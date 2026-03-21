<?php
/**
 * Outpost CMS — Theme Manifest Builder
 *
 * Scans all template files in a theme and builds a manifest of:
 * - Page fields used (per template file / page path)
 * - Global fields used (@prefixed)
 * - Collections referenced
 * - Channels referenced
 * - Menus referenced
 * - Partials included
 */

require_once __DIR__ . '/config.php';

/**
 * Build a theme manifest by scanning all template files.
 *
 * @param string $themeSlug  The theme directory name
 * @return array  The manifest structure
 */
function outpost_build_theme_manifest(string $themeSlug): array {
    $themeDir = OUTPOST_THEMES_DIR . $themeSlug . '/';
    if (!is_dir($themeDir)) return [];

    $manifest = [
        'theme' => $themeSlug,
        'pages' => [],
        'globals' => [],
        'collections' => [],
        'channels' => [],
        'menus' => [],
        'partials' => [],
        'built_at' => date('c'),
    ];

    // Collect all .html files
    $files = outpost_manifest_glob_html($themeDir);

    // First pass: scan all files including partials
    $partialFields = [];   // Fields found in partials (global scope)
    $partialGlobals = [];  // Globals found in partials
    $partialCollections = [];
    $partialChannels = [];
    $partialMenus = [];
    $partialBlocks = [];   // Blocks found in partials (global scope — nav, footer, etc.)

    foreach ($files as $relPath) {
        $fullPath = $themeDir . $relPath;
        if (!file_exists($fullPath)) continue;

        $source = file_get_contents($fullPath);
        $scan = outpost_manifest_scan_template($source);

        $isPartial = str_starts_with($relPath, 'partials/');

        if ($isPartial) {
            $partialName = pathinfo($relPath, PATHINFO_FILENAME);
            $manifest['partials'][] = $partialName;
            // Partial fields are global scope — appear on every page
            $partialFields = array_merge($partialFields, $scan['fields']);
            $partialGlobals = array_merge($partialGlobals, $scan['globals']);
            $partialCollections = array_merge($partialCollections, $scan['collections']);
            $partialChannels = array_merge($partialChannels, $scan['channels']);
            $partialMenus = array_merge($partialMenus, $scan['menus']);
            if (!empty($scan['blocks'])) {
                $partialBlocks = array_merge($partialBlocks, $scan['blocks']);
            }
        } else {
            // Map template filename to page path
            $pagePath = outpost_manifest_template_to_path($relPath);

            $manifest['pages'][$pagePath] = [
                'template' => $relPath,
                'fields' => $scan['fields'],
                'globals' => $scan['globals'],
                'collections' => $scan['collections'],
                'channels' => $scan['channels'],
                'menus' => $scan['menus'],
                'repeaters' => $scan['repeaters'],
                'flexible' => $scan['flexible'],
                'blocks' => $scan['blocks'],
            ];
        }
    }

    // Second pass: merge partial fields/globals into each page
    foreach ($manifest['pages'] as $path => &$page) {
        $page['fields'] = array_values(array_unique(array_merge($page['fields'], $partialFields)));
        $page['globals'] = array_values(array_unique(array_merge($page['globals'], $partialGlobals)));
        $page['collections'] = array_values(array_unique(array_merge($page['collections'], $partialCollections)));
        $page['channels'] = array_values(array_unique(array_merge($page['channels'], $partialChannels)));
        $page['menus'] = array_values(array_unique(array_merge($page['menus'], $partialMenus)));
        // Merge partial blocks (nav, footer, etc.) into each page
        if (!empty($partialBlocks)) {
            $page['blocks'] = array_merge($page['blocks'] ?? [], $partialBlocks);
        }
    }
    unset($page);

    // Build top-level unions
    $allGlobals = $partialGlobals;
    $allCollections = $partialCollections;
    $allChannels = $partialChannels;
    $allMenus = $partialMenus;

    foreach ($manifest['pages'] as $page) {
        $allGlobals = array_merge($allGlobals, $page['globals']);
        $allCollections = array_merge($allCollections, $page['collections']);
        $allChannels = array_merge($allChannels, $page['channels']);
        $allMenus = array_merge($allMenus, $page['menus']);
    }

    $manifest['globals'] = array_values(array_unique($allGlobals));
    $manifest['collections'] = array_values(array_unique($allCollections));
    $manifest['channels'] = array_values(array_unique($allChannels));
    $manifest['menus'] = array_values(array_unique($allMenus));
    $manifest['partials'] = array_values(array_unique($manifest['partials']));

    // Save to theme directory
    $manifestPath = $themeDir . 'theme_manifest.json';
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    return $manifest;
}


/**
 * Get the current active theme's manifest (load from file or build on the fly).
 */
function outpost_get_theme_manifest(): array {
    require_once __DIR__ . '/themes.php';
    $activeTheme = get_active_theme();
    $manifestPath = OUTPOST_THEMES_DIR . $activeTheme . '/theme_manifest.json';

    if (file_exists($manifestPath)) {
        $data = json_decode(file_get_contents($manifestPath), true);
        if (is_array($data)) return $data;
    }

    // Build on the fly if missing
    return outpost_build_theme_manifest($activeTheme);
}


/**
 * Scan a single template source for field references.
 */
function outpost_manifest_scan_template(string $source): array {
    $fields = [];
    $globals = [];
    $collections = [];
    $channels = [];
    $menus = [];
    $repeaters = [];
    $flexible = [];

    // Strip comments
    $source = preg_replace('/\{#.*?#\}/s', '', $source);

    // ── Global fields: {{ @global_name }} and {{ @global_name | filter }} ──
    // Also wrapping: {{ @name | filter }}...{{ /@name }}
    preg_match_all('/\{\{\s*@(\w+)(?:\s*\|\s*\w+)?\s*\}\}/', $source, $m);
    $globals = array_merge($globals, $m[1]);

    // Wrapping globals: {{ @name }}...{{ /@name }}
    preg_match_all('/\{\{\s*@(\w+)(?:\s*\|\s*\w+)?\s*\}\}.*?\{\{\s*\/@\1\s*\}\}/s', $source, $m);
    $globals = array_merge($globals, $m[1]);

    // {% if @global_name %} conditionals
    preg_match_all('/\{%\s*if\s+@(\w+)\s*%\}/', $source, $m);
    $globals = array_merge($globals, $m[1]);

    // ── Page fields: {{ field_name }} and {{ field_name | filter }} ──
    // Exclude @globals, meta.*, item.* (collection context)
    preg_match_all('/\{\{\s*(?!@|meta\.|item\.|var\.)(\w+)(?:\s*\|\s*\w+)?\s*\}\}/', $source, $m);
    $fields = array_merge($fields, $m[1]);

    // Wrapping page fields: {{ field }}...{{ /field }}
    preg_match_all('/\{\{\s*(?!@|meta\.|item\.|var\.)(\w+)(?:\s*\|\s*\w+)?\s*\}\}.*?\{\{\s*\/\1\s*\}\}/s', $source, $m);
    $fields = array_merge($fields, $m[1]);

    // {% if field_name %} conditionals (page-level, not @global, not item.)
    preg_match_all('/\{%\s*if\s+(?!@|item\.)(\w+)\s*(?:==|!=|%\})/', $source, $m);
    $fields = array_merge($fields, $m[1]);

    // ── Collections: {% for item in collection.slug %} ──
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+collection\.(\w+)\s*%\}/', $source, $m);
    $collections = array_merge($collections, $m[1]);

    // {% single var from collection.slug %}
    preg_match_all('/\{%\s*single\s+\w+\s+from\s+collection\.(\w+)\s*%\}/', $source, $m);
    $collections = array_merge($collections, $m[1]);

    // ── Channels: {% for item in channel.slug %} ──
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+channel\.(\w+)\s*%\}/', $source, $m);
    $channels = array_merge($channels, $m[1]);

    // ── Menus: {% for item in menu.slug %} ──
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+menu\.(\w+)\s*%\}/', $source, $m);
    $menus = array_merge($menus, $m[1]);

    // ── Repeaters: {% for item in repeater.field %} ──
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+repeater\.(\w+)\s*%\}/', $source, $m);
    $repeaters = array_merge($repeaters, $m[1]);

    // ── Flexible content: {% for block in flexible.field %} ──
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+flexible\.(\w+)\s*%\}/', $source, $m);
    $flexible = array_merge($flexible, $m[1]);

    // ── Folders: {% for label in folder.slug %} (also legacy taxonomy.*) ──
    // (not tracked separately in manifest for now)

    // Filter out template keywords that aren't real fields
    $skipWords = ['if', 'else', 'endif', 'for', 'endfor', 'include', 'single', 'endsingle', 'raw', 'end'];
    $fields = array_filter($fields, fn($f) => !in_array($f, $skipWords) && strlen($f) > 1);

    // ── v2 Data Attribute Syntax ─────────────────────────────
    // Scan for data-outpost="field" attributes and <outpost-*> elements

    // v2 globals: data-outpost="name" with data-scope="global"
    preg_match_all('/data-outpost="(\w+)"[^>]*?data-scope="global"/is', $source, $m);
    $globals = array_merge($globals, $m[1]);
    preg_match_all('/data-scope="global"[^>]*?data-outpost="(\w+)"/is', $source, $m);
    $globals = array_merge($globals, $m[1]);

    // v2 global conditionals: <outpost-if field="x" scope="global">
    preg_match_all('/<outpost-if\s+[^>]*?field="(\w+)"[^>]*?scope="global"/is', $source, $m);
    $globals = array_merge($globals, $m[1]);
    preg_match_all('/<outpost-if\s+[^>]*?scope="global"[^>]*?field="(\w+)"/is', $source, $m);
    $globals = array_merge($globals, $m[1]);

    // v2 page fields: data-outpost="name" WITHOUT data-scope="global"
    // Must exclude fields inside <outpost-each>, <outpost-single>, <outpost-menu> (item-scoped)
    // Use iterative stripping to handle nested loops
    $v2stripped = $source;
    for ($i = 0; $i < 10; $i++) {
        $before = $v2stripped;
        $v2stripped = preg_replace('/<outpost-each\s[^>]*>.*?<\/outpost-each>/is', '', $v2stripped);
        if ($v2stripped === $before) break;
    }
    for ($i = 0; $i < 10; $i++) {
        $before = $v2stripped;
        $v2stripped = preg_replace('/<outpost-single\s[^>]*>.*?<\/outpost-single>/is', '', $v2stripped);
        if ($v2stripped === $before) break;
    }
    for ($i = 0; $i < 10; $i++) {
        $before = $v2stripped;
        $v2stripped = preg_replace('/<outpost-menu\s[^>]*>.*?<\/outpost-menu>/is', '', $v2stripped);
        if ($v2stripped === $before) break;
    }
    preg_match_all('/data-outpost="(\w+)"/is', $v2stripped, $m);
    foreach ($m[1] as $fname) {
        // Check this specific match is NOT global-scoped
        // Simple heuristic: if it's already in globals, skip it
        if (!in_array($fname, $globals)) {
            $fields[] = $fname;
        }
    }

    // v2 collections: <outpost-each collection="slug"> and <outpost-single collection="slug">
    preg_match_all('/<outpost-each\s+[^>]*?collection="(\w+)"/is', $source, $m);
    $collections = array_merge($collections, $m[1]);
    preg_match_all('/<outpost-single\s+[^>]*?collection="(\w+)"/is', $source, $m);
    $collections = array_merge($collections, $m[1]);

    // v2 channels: <outpost-each channel="slug">
    preg_match_all('/<outpost-each\s+[^>]*?channel="(\w+)"/is', $source, $m);
    $channels = array_merge($channels, $m[1]);

    // v2 menus: <outpost-menu name="slug">
    preg_match_all('/<outpost-menu\s+[^>]*?name="(\w+)"/is', $source, $m);
    $menus = array_merge($menus, $m[1]);

    // v2 repeaters: <outpost-each repeat="name">
    preg_match_all('/<outpost-each\s+[^>]*?repeat="(\w+)"/is', $source, $m);
    $repeaters = array_merge($repeaters, $m[1]);

    // v2 flexible: <outpost-each flexible="name">
    preg_match_all('/<outpost-each\s+[^>]*?flexible="(\w+)"/is', $source, $m);
    $flexible = array_merge($flexible, $m[1]);

    // v2 gallery: <outpost-each gallery="name"> → register as gallery field
    preg_match_all('/<outpost-each\s+[^>]*?gallery="(\w+)"/is', $source, $m);
    $fields = array_merge($fields, $m[1]);

    // v2 page-level conditionals: <outpost-if field="x"> (not global)
    preg_match_all('/<outpost-if\s+field="(\w+)"(?![^>]*scope="global")[^>]*>/is', $v2stripped, $m);
    $fields = array_merge($fields, $m[1]);

    // ── Block grouping: parse <!-- outpost:name --> ... <!-- /outpost:name --> ──
    $blocks = outpost_manifest_parse_blocks($source);

    return [
        'fields' => array_values(array_unique($fields)),
        'globals' => array_values(array_unique($globals)),
        'collections' => array_values(array_unique($collections)),
        'channels' => array_values(array_unique($channels)),
        'menus' => array_values(array_unique($menus)),
        'repeaters' => array_values(array_unique($repeaters)),
        'flexible' => array_values(array_unique($flexible)),
        'blocks' => $blocks,
    ];
}


/**
 * Parse <!-- outpost:blockname --> ... <!-- /outpost:blockname --> comment pairs
 * and determine which page fields (data-outpost="..." or {{ field }}) belong to each block.
 *
 * Returns an array of block objects: [{ name, fields, global }]
 */
function outpost_manifest_parse_blocks(string $source): array {
    $blocks = [];

    // Find all <!-- outpost:name --> or <!-- outpost:name global --> opening comments
    // and their matching <!-- /outpost:name --> closing comments
    preg_match_all('/<!--\s*outpost:([a-zA-Z0-9_-]+)(\s+global)?\s*-->/', $source, $openings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    foreach ($openings as $opening) {
        $blockName = $opening[1][0];
        $isGlobal = !empty($opening[2][0]);
        $startPos = $opening[0][1] + strlen($opening[0][0]);

        // Find the closing comment <!-- /outpost:blockname -->
        $closePattern = '/<!--\s*\/outpost:' . preg_quote($blockName, '/') . '\s*-->/';
        if (preg_match($closePattern, $source, $closeMatch, PREG_OFFSET_CAPTURE, $startPos)) {
            $endPos = $closeMatch[0][1];
            $blockContent = substr($source, $startPos, $endPos - $startPos);

            // Extract fields from this block content
            $blockFields = outpost_manifest_extract_block_fields($blockContent, $isGlobal);

            $block = [
                'name' => $blockName,
                'fields' => array_values(array_unique($blockFields)),
            ];
            if ($isGlobal) {
                $block['global'] = true;
            }
            $blocks[] = $block;
        }
    }

    return $blocks;
}


/**
 * Extract page-level field names from a block's HTML content.
 * Looks for data-outpost="field" attributes (not inside <outpost-each>/<outpost-single>/<outpost-menu>)
 * and {{ field_name }} / {{ field_name | filter }} Liquid tags.
 */
function outpost_manifest_extract_block_fields(string $content, bool $isGlobal = false): array {
    $fields = [];

    // Strip collection/repeater/menu loop contents — fields inside those are item-scoped, not page fields
    // Use iterative stripping to handle nested loops
    $stripped = $content;
    $maxPasses = 10;
    for ($i = 0; $i < $maxPasses; $i++) {
        $before = $stripped;
        $stripped = preg_replace('/<outpost-each\s[^>]*>.*?<\/outpost-each>/is', '', $stripped);
        if ($stripped === $before) break;
    }
    for ($i = 0; $i < $maxPasses; $i++) {
        $before = $stripped;
        $stripped = preg_replace('/<outpost-single\s[^>]*>.*?<\/outpost-single>/is', '', $stripped);
        if ($stripped === $before) break;
    }
    for ($i = 0; $i < $maxPasses; $i++) {
        $before = $stripped;
        $stripped = preg_replace('/<outpost-menu\s[^>]*>.*?<\/outpost-menu>/is', '', $stripped);
        if ($stripped === $before) break;
    }

    // Also strip v1 collection/single loops
    $stripped = preg_replace('/\{%\s*for\s+\w+\s+in\s+collection\.\w+\s*%\}.*?\{%\s*endfor\s*%\}/is', '', $stripped);
    $stripped = preg_replace('/\{%\s*single\s+\w+\s+from\s+collection\.\w+\s*%\}.*?\{%\s*endsingle\s*%\}/is', '', $stripped);

    // v2: data-outpost="field" (not global-scoped unless the block itself is global)
    preg_match_all('/data-outpost="(\w+)"/is', $stripped, $m);
    $fields = array_merge($fields, $m[1]);

    // v2: <outpost-if field="x"> conditionals (page-level)
    preg_match_all('/<outpost-if\s+[^>]*?field="(\w+)"/is', $stripped, $m);
    $fields = array_merge($fields, $m[1]);

    if (!$isGlobal) {
        // v1 Liquid: {{ field_name }} and {{ field_name | filter }} — exclude @globals, meta.*, item.*, var.*
        preg_match_all('/\{\{\s*(?!@|meta\.|item\.|var\.)(\w+)(?:\s*\|\s*\w+)?\s*\}\}/', $stripped, $m);
        $skipWords = ['if', 'else', 'endif', 'for', 'endfor', 'include', 'single', 'endsingle', 'raw', 'end'];
        foreach ($m[1] as $f) {
            if (!in_array($f, $skipWords) && strlen($f) > 1) {
                $fields[] = $f;
            }
        }

        // v1 conditionals: {% if field_name %}
        preg_match_all('/\{%\s*if\s+(?!@|item\.)(\w+)\s*(?:==|!=|%\})/', $stripped, $m);
        foreach ($m[1] as $f) {
            if (!in_array($f, $skipWords) && strlen($f) > 1) {
                $fields[] = $f;
            }
        }
    } else {
        // Global block: extract @global fields
        preg_match_all('/\{\{\s*@(\w+)(?:\s*\|\s*\w+)?\s*\}\}/', $stripped, $m);
        $fields = array_merge($fields, $m[1]);
        preg_match_all('/\{%\s*if\s+@(\w+)\s*%\}/', $stripped, $m);
        $fields = array_merge($fields, $m[1]);

        // v2 global: data-outpost with data-scope="global"
        preg_match_all('/data-outpost="(\w+)"[^>]*?data-scope="global"/is', $content, $m);
        $fields = array_merge($fields, $m[1]);
        preg_match_all('/data-scope="global"[^>]*?data-outpost="(\w+)"/is', $content, $m);
        $fields = array_merge($fields, $m[1]);
    }

    return $fields;
}


/**
 * Map a template filename to its page path.
 */
function outpost_manifest_template_to_path(string $relPath): string {
    $filename = pathinfo($relPath, PATHINFO_FILENAME);

    if ($filename === 'index') return '/';

    // Handle nested directories (e.g. blog/index.html → /blog)
    $dir = dirname($relPath);
    if ($dir !== '.' && $dir !== '') {
        return '/' . $dir . ($filename !== 'index' ? '/' . $filename : '');
    }

    return '/' . $filename;
}


/**
 * Recursively find all .html files in a theme directory.
 */
function outpost_manifest_glob_html(string $dir, string $prefix = ''): array {
    $files = [];
    $entries = scandir($dir);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($entry === '.forge-snapshot') continue; // Skip snapshot dirs

        $fullPath = $dir . $entry;
        $relPath = $prefix ? $prefix . '/' . $entry : $entry;

        if (is_dir($fullPath)) {
            // Skip assets, node_modules, etc.
            if (in_array($entry, ['assets', 'node_modules', '.git'])) continue;
            $files = array_merge($files, outpost_manifest_glob_html($fullPath . '/', $relPath));
        } elseif (pathinfo($entry, PATHINFO_EXTENSION) === 'html') {
            $files[] = $relPath;
        }
    }

    return $files;
}
