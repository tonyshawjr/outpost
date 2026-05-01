<?php
/**
 * Kenii Sites — Block Registry
 * Scans /content/themes/{active-theme}/blocks/ for block definitions.
 */

/**
 * Returns the active theme slug from the settings table.
 * Falls back to 'starter' if no theme is configured.
 */
function kenii_get_active_theme(): string {
    try {
        $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
        if ($row && !empty($row['value'])) {
            return $row['value'];
        }
    } catch (\Throwable $e) {
        // Table may not exist yet
    }
    return 'starter';
}

/**
 * Returns the path to the active theme's blocks directory.
 */
function kenii_get_blocks_dir(): string {
    return KENII_CONTENT_DIR . 'themes/' . kenii_get_active_theme() . '/blocks/';
}

/**
 * Scans the blocks directory and returns an array of block definitions.
 * Each subfolder is treated as a block. Folders without an HTML file are skipped.
 */
function kenii_scan_blocks(): array {
    $dir = kenii_get_blocks_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $blocks = [];
    $entries = scandir($dir);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $blockPath = $dir . $entry . '/';
        if (!is_dir($blockPath)) continue;

        $block = _kenii_parse_block($entry, $blockPath);
        if ($block !== null) {
            $blocks[] = $block;
        }
    }

    // Sort alphabetically by name
    usort($blocks, fn($a, $b) => strcmp($a['name'], $b['name']));

    return $blocks;
}

/**
 * Parses a single block folder into a block definition array.
 * Returns null if the block has no HTML file.
 */
function _kenii_parse_block(string $slug, string $blockPath): ?array {
    $htmlFile = $blockPath . $slug . '.html';
    if (!file_exists($htmlFile)) {
        return null;
    }

    $cssFile = $blockPath . $slug . '.css';
    $hasCss = file_exists($cssFile);
    $jsonFile = $blockPath . 'block.json';

    // Defaults
    $name = ucwords(str_replace('-', ' ', $slug));
    $description = '';
    $icon = 'box';
    $category = 'content';
    $fields = [];

    if (file_exists($jsonFile)) {
        // Parse block.json
        $raw = file_get_contents($jsonFile);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $name        = $json['name']        ?? $name;
            $description = $json['description'] ?? $description;
            $icon        = $json['icon']        ?? $icon;
            $category    = $json['category']    ?? $category;
            $fields      = $json['fields']      ?? $fields;
        }
    } else {
        // Auto-detect fields from data-outpost attributes in the HTML
        $html = file_get_contents($htmlFile);
        if ($html !== false) {
            $fields = _kenii_auto_detect_fields($html);
        }
    }

    return [
        'slug'        => $slug,
        'name'        => $name,
        'description' => $description,
        'icon'        => $icon,
        'category'    => $category,
        'fields'      => $fields,
        'has_css'     => $hasCss,
        'html_file'   => $htmlFile,
        'css_file'    => $hasCss ? $cssFile : null,
    ];
}

/**
 * Auto-detect fields from data-outpost attributes in HTML.
 */
function _kenii_auto_detect_fields(string $html): array {
    $fields = [];
    if (preg_match_all('/data-outpost="([^"]+)"/', $html, $matches)) {
        foreach ($matches[1] as $key) {
            $fields[] = [
                'key'    => $key,
                'label'  => ucwords(str_replace('_', ' ', $key)),
                'type'   => 'text',
                'target' => 'content',
            ];
        }
    }
    return $fields;
}

/**
 * Returns a single block definition by slug, or null if not found.
 */
function kenii_get_block(string $slug): ?array {
    $dir = kenii_get_blocks_dir();
    $blockPath = $dir . $slug . '/';
    if (!is_dir($blockPath)) {
        return null;
    }
    return _kenii_parse_block($slug, $blockPath);
}

/**
 * Returns the raw HTML content of a block template.
 */
function kenii_get_block_html(string $slug): string {
    $dir = kenii_get_blocks_dir();
    $file = $dir . $slug . '/' . $slug . '.html';
    if (!file_exists($file)) {
        return '';
    }
    return file_get_contents($file) ?: '';
}

/**
 * Returns the raw CSS content of a block, or empty string if no CSS file.
 */
function kenii_get_block_css(string $slug): string {
    $dir = kenii_get_blocks_dir();
    $file = $dir . $slug . '/' . $slug . '.css';
    if (!file_exists($file)) {
        return '';
    }
    return file_get_contents($file) ?: '';
}
