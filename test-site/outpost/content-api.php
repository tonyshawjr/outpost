<?php
/**
 * Outpost CMS — Public Content API
 * Read-only endpoints for headless usage. No auth required.
 * Accessed via api.php?action=content/...
 */

// ── CORS & security headers (wide open for public API) ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Response helpers ────────────────────────────────────
function content_response(mixed $data, ?array $meta = null): void {
    http_response_code(200);
    $out = ['data' => $data];
    if ($meta !== null) $out['meta'] = $meta;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

function content_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

/**
 * Validate and sanitize a query string parameter.
 * Returns the trimmed value if valid, or null if missing/over limit.
 */
function content_param(string $key, int $maxLen = 255): ?string {
    $val = $_GET[$key] ?? null;
    if ($val === null) return null;
    $val = trim((string) $val);
    if ($val === '' || mb_strlen($val) > $maxLen) return null;
    return $val;
}

// ── Router ──────────────────────────────────────────────
function handle_content_request(string $action, string $method): void {
    if ($method !== 'GET') {
        content_error('Method not allowed', 405);
    }

    // Rate limit: 120 requests per 60 seconds per IP
    outpost_ip_rate_limit('content_api', 120, 60);

    // Ensure folder/label tables exist
    ensure_content_folder_tables();

    match ($action) {
        'content/schema'       => handle_content_schema(),
        'content/pages'        => handle_content_pages(),
        'content/collections'  => handle_content_collections(),
        'content/items'        => handle_content_items(),
        'content/folders'      => handle_content_folders(),
        'content/labels'       => handle_content_labels(),
        'content/taxonomies'   => handle_content_folders(),   // alias (legacy)
        'content/terms'        => handle_content_labels(),    // alias (legacy)
        'content/globals'      => handle_content_globals(),
        'content/media'        => handle_content_media(),
        'content/menus'        => handle_content_menus(),
        'content/syntax'       => handle_content_syntax(),
        default                => content_error('Not found', 404),
    };
}

// ── Folder/label table migration (same as api.php) ──────
function ensure_content_folder_tables(): void {
    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            type TEXT DEFAULT 'flat',
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(collection_id, slug),
            FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS labels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(folder_id, slug),
            FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS item_labels (
            item_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            PRIMARY KEY (item_id, label_id),
            FOREIGN KEY (item_id) REFERENCES collection_items(id) ON DELETE CASCADE,
            FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            items TEXT NOT NULL DEFAULT '[]',
            updated_at TEXT DEFAULT (datetime('now'))
        );
    ");

    // Migration columns
    $cols = $db->query("PRAGMA table_info(folders)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('schema', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN schema TEXT DEFAULT '[]'");
    }
    if (!in_array('description', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN description TEXT DEFAULT ''");
    }
    if (!in_array('singular_name', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN singular_name TEXT DEFAULT ''");
    }

    $labelCols = $db->query("PRAGMA table_info(labels)")->fetchAll();
    $labelColNames = array_column($labelCols, 'name');
    if (!in_array('data', $labelColNames)) {
        $db->exec("ALTER TABLE labels ADD COLUMN data TEXT DEFAULT '{}'");
    }
    if (!in_array('description', $labelColNames)) {
        $db->exec("ALTER TABLE labels ADD COLUMN description TEXT DEFAULT ''");
    }
}

// Legacy alias
function ensure_content_taxonomy_tables(): void { ensure_content_folder_tables(); }

// ── Template field extraction ────────────────────────────
/**
 * Map a page path to its theme template file.
 */
function resolveTemplateFile(string $pagePath, string $themeDir): ?string {
    $slug = trim($pagePath, '/');
    $file = $themeDir . '/' . ($slug === '' ? 'index' : $slug) . '.html';
    return file_exists($file) ? $file : null;
}

/**
 * Extract page-level field references from a Liquid template.
 * Resolves {% include %} partials one level deep.
 */
function extractTemplateFields(string $templatePath, string $themeDir): array {
    $source = file_get_contents($templatePath);
    if ($source === false) return [];

    // Collect include partials (one level deep)
    if (preg_match_all('/\{%\s*include\s+[\'"]([a-zA-Z0-9_\-\/]+)[\'"]\s*%\}/', $source, $incl)) {
        foreach ($incl[1] as $partial) {
            $pFile = $themeDir . '/partials/' . $partial . '.html';
            if (file_exists($pFile)) {
                $source .= "\n" . file_get_contents($pFile);
            }
        }
    }

    $fields = []; // name => type

    // System/built-in fields to exclude (already shown separately in the UI)
    $builtins = ['id', 'path', 'title', 'meta_title', 'meta_description'];

    // {{ field_name | filter }} or {{ field_name | filter "default" }}
    if (preg_match_all('/\{\{\s*(\w+)\s*\|\s*(\w+)(?:\s+"[^"]*")?\s*\}\}/', $source, $m)) {
        $filterMap = [
            'raw' => 'richtext', 'image' => 'image', 'link' => 'link',
            'textarea' => 'textarea', 'select' => 'text', 'toggle' => 'toggle',
            'color' => 'color', 'number' => 'number', 'date' => 'date',
            'or_body' => 'text',
        ];
        foreach ($m[1] as $i => $name) {
            if (in_array($name, $builtins)) continue;
            $filter = $m[2][$i];
            $type = $filterMap[$filter] ?? 'text';
            // Don't overwrite a more specific type with text
            if (!isset($fields[$name]) || $fields[$name] === 'text') {
                $fields[$name] = $type;
            }
        }
    }

    // {{ field_name }} or {{ field_name "default" }} — plain text (no filter, no dot, no @)
    if (preg_match_all('/\{\{\s*(\w+)(?:\s+"[^"]*")?\s*\}\}/', $source, $m)) {
        foreach ($m[1] as $name) {
            if (in_array($name, $builtins)) continue;
            if (!isset($fields[$name])) {
                $fields[$name] = 'text';
            }
        }
    }

    // {% if field_name %} — standalone word (no dot, no @)
    if (preg_match_all('/\{%\s*if\s+(\w+)\s*%\}/', $source, $m)) {
        foreach ($m[1] as $name) {
            if ($name === 'admin' || in_array($name, $builtins)) continue;
            if (!isset($fields[$name])) {
                $fields[$name] = 'toggle';
            }
        }
    }

    // Deduplicate into array of {name, type}
    $result = [];
    foreach ($fields as $name => $type) {
        $result[] = ['name' => $name, 'type' => $type];
    }
    return $result;
}

/**
 * Extract ALL template references from a Liquid template: fields, globals,
 * collections, singles, galleries, repeaters, menus, folders.
 * Resolves {% include %} partials one level deep.
 */
function extractAllTemplateReferences(string $templatePath, string $themeDir): array {
    $source = file_get_contents($templatePath);
    if ($source === false) return [
        'fields' => [], 'globals' => [], 'collections' => [],
        'singles' => [], 'galleries' => [], 'repeaters' => [],
        'menus' => [], 'folders' => [], 'flexibles' => [],
        'relationships' => [],
    ];

    // Collect include partials (one level deep)
    if (preg_match_all('/\{%\s*include\s+[\'"]([a-zA-Z0-9_\-\/]+)[\'"]\s*%\}/', $source, $incl)) {
        foreach ($incl[1] as $partial) {
            $pFile = $themeDir . '/partials/' . $partial . '.html';
            if (file_exists($pFile)) {
                $source .= "\n" . file_get_contents($pFile);
            }
        }
    }

    // ── Page-level fields (reuse same logic as extractTemplateFields) ──
    $fields = [];
    $builtins = ['id', 'path', 'title', 'meta_title', 'meta_description'];
    $filterMap = [
        'raw' => 'richtext', 'image' => 'image', 'link' => 'link',
        'textarea' => 'textarea', 'select' => 'text', 'toggle' => 'toggle',
        'color' => 'color', 'number' => 'number', 'date' => 'date',
        'or_body' => 'text',
    ];

    if (preg_match_all('/\{\{\s*(\w+)\s*\|\s*(\w+)(?:\s+"[^"]*")?\s*\}\}/', $source, $m)) {
        foreach ($m[1] as $i => $name) {
            if (in_array($name, $builtins)) continue;
            $filter = $m[2][$i];
            $type = $filterMap[$filter] ?? 'text';
            if (!isset($fields[$name]) || $fields[$name] === 'text') {
                $fields[$name] = $type;
            }
        }
    }
    if (preg_match_all('/\{\{\s*(\w+)(?:\s+"[^"]*")?\s*\}\}/', $source, $m)) {
        foreach ($m[1] as $name) {
            if (in_array($name, $builtins)) continue;
            if (!isset($fields[$name])) $fields[$name] = 'text';
        }
    }
    if (preg_match_all('/\{%\s*if\s+(\w+)\s*%\}/', $source, $m)) {
        foreach ($m[1] as $name) {
            if ($name === 'admin' || in_array($name, $builtins)) continue;
            if (!isset($fields[$name])) $fields[$name] = 'toggle';
        }
    }
    $fieldResult = [];
    foreach ($fields as $name => $type) {
        $fieldResult[] = ['name' => $name, 'type' => $type];
    }

    // ── Globals ──
    $globalsMap = extractGlobalsFromSource($source);
    $globalsResult = [];
    foreach ($globalsMap as $name => $type) {
        $globalsResult[] = ['name' => $name, 'type' => $type];
    }

    // ── Collections: {% for VAR in collection.SLUG [opts] %} ──
    $collections = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+collection\.(\w+)(?:\s+([^%]*?))?\s*%\}/', $source, $m)) {
        foreach ($m[1] as $i => $slug) {
            $opts = trim($m[2][$i] ?? '');
            // Deduplicate by slug — keep first (or merge opts)
            $existing = array_filter($collections, fn($c) => $c['slug'] === $slug);
            if (empty($existing)) {
                $collections[] = ['slug' => $slug, 'options' => $opts];
            }
        }
    }

    // ── Singles: {% single VAR from collection.SLUG %} ──
    $singles = [];
    if (preg_match_all('/\{%\s*single\s+\w+\s+from\s+collection\.(\w+)\s*%\}/', $source, $m)) {
        foreach (array_unique($m[1]) as $slug) {
            $singles[] = ['slug' => $slug];
        }
    }

    // ── Galleries: {% for VAR in gallery.NAME %} ──
    $galleries = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+gallery\.(\w+)\s*%\}/', $source, $m)) {
        $galleries = array_values(array_unique($m[1]));
    }

    // ── Repeaters: {% for VAR in repeater.NAME %} ──
    $repeaters = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+repeater\.(\w+)\s*%\}/', $source, $m)) {
        $repeaters = array_values(array_unique($m[1]));
    }

    // ── Menus: {% for VAR in menu.SLUG %} ──
    $menus = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+menu\.(\w+)\s*%\}/', $source, $m)) {
        $menus = array_values(array_unique($m[1]));
    }

    // ── Folders: {% for VAR in folder.SLUG %} (legacy: taxonomy.SLUG) ──
    $folders = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+(?:folder|taxonomy)\.(\w+)\s*%\}/', $source, $m)) {
        $folders = array_values(array_unique($m[1]));
    }

    // ── Flexible Content: {% for VAR in flexible.NAME %} ──
    $flexibles = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+flexible\.(\w+)\s*%\}/', $source, $m)) {
        $flexibles = array_values(array_unique($m[1]));
    }

    // ── Relationships: {% for VAR in relationship.NAME %} ──
    $relationships = [];
    if (preg_match_all('/\{%\s*for\s+\w+\s+in\s+relationship\.(\w+)\s*%\}/', $source, $m)) {
        $relationships = array_values(array_unique($m[1]));
    }

    return [
        'fields'        => $fieldResult,
        'globals'       => $globalsResult,
        'collections'   => $collections,
        'singles'       => $singles,
        'galleries'     => $galleries,
        'repeaters'     => $repeaters,
        'menus'         => $menus,
        'folders'       => $folders,
        'flexibles'     => $flexibles,
        'relationships' => $relationships,
    ];
}

/**
 * Extract global field references ({{ @name }}, {% if @name %}) from a template source string.
 */
function extractGlobalsFromSource(string $source): array {
    $globals = []; // name => type

    $filterMap = [
        'raw' => 'richtext', 'image' => 'image', 'link' => 'link',
        'textarea' => 'textarea', 'select' => 'text', 'toggle' => 'toggle',
        'color' => 'color', 'number' => 'number', 'date' => 'date',
    ];

    // {{ @name | filter }} or {{ @name }}
    if (preg_match_all('/\{\{\s*@(\w+)\s*(?:\|\s*(\w+))?\s*\}\}/', $source, $m)) {
        foreach ($m[1] as $i => $name) {
            $filter = $m[2][$i] ?: 'text';
            $type = $filterMap[$filter] ?? 'text';
            if (!isset($globals[$name]) || $globals[$name] === 'text') {
                $globals[$name] = $type;
            }
        }
    }

    // {% if @name %}
    if (preg_match_all('/\{%\s*if\s+@(\w+)\s*%\}/', $source, $m)) {
        foreach ($m[1] as $name) {
            if (!isset($globals[$name])) {
                $globals[$name] = 'text'; // conditional only — keep as text unless typed elsewhere
            }
        }
    }

    return $globals;
}

/**
 * Scan all theme templates + partials for global field references.
 */
function extractAllGlobalFields(string $themeDir): array {
    $globals = []; // name => type

    // Gather all .html files in theme root and partials/
    $files = glob($themeDir . '/*.html') ?: [];
    $partialFiles = glob($themeDir . '/partials/*.html') ?: [];
    $allFiles = array_merge($files, $partialFiles);

    foreach ($allFiles as $file) {
        $source = file_get_contents($file);
        if ($source === false) continue;
        foreach (extractGlobalsFromSource($source) as $name => $type) {
            if (!isset($globals[$name]) || $globals[$name] === 'text') {
                $globals[$name] = $type;
            }
        }
    }

    $result = [];
    foreach ($globals as $name => $type) {
        $result[] = ['name' => $name, 'type' => $type];
    }
    return $result;
}

// ── Schema endpoint ─────────────────────────────────────
function handle_content_schema(): void {
    // Collections with fields + folders
    $collections = OutpostDB::fetchAll('SELECT id, slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page FROM collections ORDER BY name ASC');
    $collectionData = [];
    foreach ($collections as $c) {
        $schema = json_decode($c['schema'] ?: '{}', true);
        $fields = [];
        if (is_array($schema)) {
            // Schema can be object with 'fields' key or direct array
            $fieldList = $schema['fields'] ?? $schema;
            if (is_array($fieldList)) {
                foreach ($fieldList as $f) {
                    $fields[] = [
                        'name' => $f['name'] ?? '',
                        'type' => $f['type'] ?? 'text',
                        'label' => $f['label'] ?? ($f['name'] ?? ''),
                        'required' => !empty($f['required']),
                    ];
                }
            }
        }

        // Folders for this collection
        $collFolders = OutpostDB::fetchAll(
            'SELECT slug, name, type FROM folders WHERE collection_id = ? ORDER BY name ASC',
            [$c['id']]
        );

        $collectionData[] = [
            'slug' => $c['slug'],
            'name' => $c['name'],
            'singular_name' => $c['singular_name'] ?: $c['name'],
            'url_pattern' => $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}'),
            'sort_field' => $c['sort_field'] ?: 'created_at',
            'sort_direction' => $c['sort_direction'] ?: 'DESC',
            'items_per_page' => (int) ($c['items_per_page'] ?: 10),
            'fields' => $fields,
            'folders' => $collFolders,
        ];
    }

    // Pages with their fields — derived from theme template files
    $activeTheme = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
    $activeThemeSlug = $activeTheme ? $activeTheme['value'] : '';
    $themeDir = OUTPOST_THEMES_DIR . $activeThemeSlug;

    $pages = OutpostDB::fetchAll(
        "SELECT id, path, title FROM pages WHERE path != '__global__' AND (visibility IS NULL OR visibility = 'public') ORDER BY path ASC"
    );

    $pageData = [];
    foreach ($pages as $p) {
        $templateFile = $activeThemeSlug ? resolveTemplateFile($p['path'], $themeDir) : null;
        $refs = $templateFile ? extractAllTemplateReferences($templateFile, $themeDir) : [
            'fields' => [], 'globals' => [], 'collections' => [],
            'singles' => [], 'galleries' => [], 'repeaters' => [],
            'menus' => [], 'folders' => [],
        ];

        $pageData[] = [
            'path' => $p['path'],
            'title' => $p['title'],
            ...$refs,
        ];
    }

    // Folders with label fields
    $allFolders = OutpostDB::fetchAll(
        'SELECT f.*, c.slug as collection_slug
         FROM folders f
         LEFT JOIN collections c ON c.id = f.collection_id
         ORDER BY f.name ASC'
    );
    $folderData = [];
    foreach ($allFolders as $fld) {
        $schema = json_decode($fld['schema'] ?: '[]', true);
        $labelFields = [];
        if (is_array($schema)) {
            foreach ($schema as $f) {
                $labelFields[] = [
                    'name' => $f['name'] ?? '',
                    'type' => $f['type'] ?? 'text',
                    'label' => $f['label'] ?? ($f['name'] ?? ''),
                ];
            }
        }
        $folderData[] = [
            'slug' => $fld['slug'],
            'name' => $fld['name'],
            'type' => $fld['type'] ?: 'flat',
            'collection_slug' => $fld['collection_slug'],
            'label_fields' => $labelFields,
        ];
    }

    // Globals — derived from all theme template files
    $globalFields = $activeThemeSlug ? extractAllGlobalFields($themeDir) : [];

    content_response([
        'collections' => $collectionData,
        'pages' => $pageData,
        'globals' => $globalFields,
        'folders' => $folderData,
    ]);
}

// ── Pages endpoint ──────────────────────────────────────
function handle_content_pages(): void {
    $path = content_param('path');

    if ($path !== null) {
        // Single page by path (exclude members-only pages from public API)
        $page = OutpostDB::fetchOne(
            "SELECT id, path, title, meta_title, meta_description FROM pages WHERE path = ? AND path != '__global__' AND (visibility IS NULL OR visibility = 'public')",
            [$path]
        );
        if (!$page) content_error('Page not found', 404);

        $fields = OutpostDB::fetchAll(
            'SELECT field_name, field_type, content FROM fields WHERE page_id = ? ORDER BY sort_order ASC',
            [$page['id']]
        );
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[$f['field_name']] = $f['content'];
        }

        content_response([
            'id' => (int) $page['id'],
            'path' => $page['path'],
            'title' => $page['title'],
            'meta_title' => $page['meta_title'],
            'meta_description' => $page['meta_description'],
            'fields' => $fieldMap,
        ]);
        return;
    }

    // All pages (exclude members-only pages from public API)
    $pages = OutpostDB::fetchAll(
        "SELECT id, path, title, meta_title, meta_description FROM pages WHERE path != '__global__' AND (visibility IS NULL OR visibility = 'public') ORDER BY path ASC"
    );

    $result = [];
    foreach ($pages as $p) {
        $fields = OutpostDB::fetchAll(
            'SELECT field_name, content FROM fields WHERE page_id = ? ORDER BY sort_order ASC',
            [$p['id']]
        );
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[$f['field_name']] = $f['content'];
        }
        $result[] = [
            'id' => (int) $p['id'],
            'path' => $p['path'],
            'title' => $p['title'],
            'meta_title' => $p['meta_title'],
            'meta_description' => $p['meta_description'],
            'fields' => $fieldMap,
        ];
    }

    content_response($result, ['total' => count($result)]);
}

// ── Collections endpoint ────────────────────────────────
function handle_content_collections(): void {
    $slug = content_param('slug');

    if ($slug !== null) {
        $collection = OutpostDB::fetchOne(
            'SELECT id, slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page FROM collections WHERE slug = ?',
            [$slug]
        );
        if (!$collection) content_error('Collection not found', 404);

        $schema = json_decode($collection['schema'] ?: '{}', true);
        $fields = [];
        $fieldList = $schema['fields'] ?? $schema;
        if (is_array($fieldList)) {
            foreach ($fieldList as $f) {
                $fields[] = [
                    'name' => $f['name'] ?? '',
                    'type' => $f['type'] ?? 'text',
                    'label' => $f['label'] ?? ($f['name'] ?? ''),
                    'required' => !empty($f['required']),
                ];
            }
        }

        $count = OutpostDB::fetchOne(
            "SELECT COUNT(*) as count FROM collection_items WHERE collection_id = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))",
            [$collection['id']]
        );

        content_response([
            'slug' => $collection['slug'],
            'name' => $collection['name'],
            'singular_name' => $collection['singular_name'] ?: $collection['name'],
            'url_pattern' => $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}'),
            'sort_field' => $collection['sort_field'] ?: 'created_at',
            'sort_direction' => $collection['sort_direction'] ?: 'DESC',
            'items_per_page' => (int) ($collection['items_per_page'] ?: 10),
            'fields' => $fields,
            'published_count' => (int) ($count['count'] ?? 0),
        ]);
        return;
    }

    // List all
    $collections = OutpostDB::fetchAll(
        'SELECT id, slug, name, singular_name, schema, items_per_page FROM collections ORDER BY name ASC'
    );
    $result = [];
    foreach ($collections as $c) {
        $schema = json_decode($c['schema'] ?: '{}', true);
        $fieldCount = 0;
        $fieldList = $schema['fields'] ?? $schema;
        if (is_array($fieldList)) $fieldCount = count($fieldList);

        $count = OutpostDB::fetchOne(
            "SELECT COUNT(*) as count FROM collection_items WHERE collection_id = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))",
            [$c['id']]
        );

        $result[] = [
            'slug' => $c['slug'],
            'name' => $c['name'],
            'singular_name' => $c['singular_name'] ?: $c['name'],
            'field_count' => $fieldCount,
            'published_count' => (int) ($count['count'] ?? 0),
        ];
    }

    content_response($result, ['total' => count($result)]);
}

// ── Items endpoint ──────────────────────────────────────
function handle_content_items(): void {
    $collectionSlug = content_param('collection');
    if (!$collectionSlug) content_error('collection parameter required');

    $collection = OutpostDB::fetchOne(
        'SELECT id, slug, url_pattern, sort_field, sort_direction FROM collections WHERE slug = ?',
        [$collectionSlug]
    );
    if (!$collection) content_error('Collection not found', 404);

    $slug = content_param('slug');

    // Single item by slug
    if ($slug !== null) {
        $item = OutpostDB::fetchOne(
            "SELECT id, slug, status, data, sort_order, created_at, updated_at, published_at FROM collection_items WHERE collection_id = ? AND slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))",
            [$collection['id'], $slug]
        );
        if (!$item) content_error('Item not found', 404);

        $urlPattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
        content_response(format_item($item, $urlPattern));
        return;
    }

    // Pagination
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    // Sorting
    $orderBy = $_GET['orderby'] ?? $_GET['orderBy'] ?? ($collection['sort_field'] ?: 'created_at');
    $order = strtoupper($_GET['order'] ?? ($collection['sort_direction'] ?: 'DESC'));
    if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';
    // Whitelist sort fields
    $allowedSorts = ['created_at', 'updated_at', 'published_at', 'slug', 'sort_order'];
    if (!in_array($orderBy, $allowedSorts)) $orderBy = 'created_at';

    // Base conditions
    $where = "collection_id = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))";
    $params = [$collection['id']];

    // Folder filtering: any query param matching a folder slug filters by label
    $itemFolders = OutpostDB::fetchAll(
        'SELECT id, slug FROM folders WHERE collection_id = ?',
        [$collection['id']]
    );
    $folderSlugs = array_column($itemFolders, 'slug', 'id');
    $folderIdBySlug = array_flip($folderSlugs);

    foreach ($folderIdBySlug as $folderSlug => $folderId) {
        if (isset($_GET[$folderSlug]) && $_GET[$folderSlug] !== '') {
            $labelSlug = $_GET[$folderSlug];
            // Find label ID
            $label = OutpostDB::fetchOne(
                'SELECT id FROM labels WHERE folder_id = ? AND slug = ?',
                [$folderId, $labelSlug]
            );
            if ($label) {
                $where .= ' AND id IN (SELECT item_id FROM item_labels WHERE label_id = ?)';
                $params[] = $label['id'];
            } else {
                // Label doesn't exist — no results
                content_response([], ['total' => 0, 'limit' => $limit, 'offset' => $offset]);
                return;
            }
        }
    }

    // Count
    $total = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM collection_items WHERE {$where}",
        $params
    );
    $totalCount = (int) ($total['count'] ?? 0);

    // Fetch
    $items = OutpostDB::fetchAll(
        "SELECT id, slug, status, data, sort_order, created_at, updated_at, published_at FROM collection_items WHERE {$where} ORDER BY {$orderBy} {$order} LIMIT ? OFFSET ?",
        [...$params, $limit, $offset]
    );

    $urlPattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
    $result = array_map(fn($item) => format_item($item, $urlPattern), $items);

    content_response($result, [
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

/**
 * Format a collection item for public output.
 * Flattens data JSON to fields, renders blocks to body, attaches labels.
 * Optional $urlPattern builds the item URL (e.g. '/post/{slug}' → '/post/my-post').
 */
function format_item(array $item, string $urlPattern = ''): array {
    $data = json_decode($item['data'], true) ?: [];

    // Flatten fields from data
    $fields = [];
    foreach ($data as $key => $value) {
        if ($key === 'blocks') continue; // handled below
        $fields[$key] = $value;
    }

    // Render blocks to body
    if (!empty($data['blocks']) && is_array($data['blocks'])) {
        $bodyParts = [];
        foreach ($data['blocks'] as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text' || $type === 'markdown' || $type === 'html') {
                $bodyParts[] = $block['content'] ?? '';
            } elseif ($type === 'image') {
                $src = htmlspecialchars($block['src'] ?? '', ENT_QUOTES, 'UTF-8');
                $alt = htmlspecialchars($block['alt'] ?? '', ENT_QUOTES, 'UTF-8');
                $bodyParts[] = "<img src=\"{$src}\" alt=\"{$alt}\">";
            } elseif ($type === 'divider') {
                $bodyParts[] = '<hr>';
            }
        }
        $fields['body'] = implode("\n", $bodyParts);
        $fields['blocks'] = $data['blocks'];
    }

    // Attach labels grouped by folder slug — full objects so templates can use label.name, label.slug etc.
    $labels = OutpostDB::fetchAll(
        'SELECT l.id, l.name, l.slug as label_slug, l.parent_id, f.slug as folder_slug
         FROM item_labels il
         INNER JOIN labels l ON l.id = il.label_id
         INNER JOIN folders f ON f.id = l.folder_id
         WHERE il.item_id = ?
         ORDER BY l.sort_order ASC, l.name ASC',
        [$item['id']]
    );
    $labelsByFolder = [];
    foreach ($labels as $l) {
        $labelsByFolder[$l['folder_slug']][] = [
            'id'        => (int) $l['id'],
            'name'      => $l['name'],
            'slug'      => $l['label_slug'],
            'parent_id' => $l['parent_id'] ? (int) $l['parent_id'] : null,
        ];
    }

    // Build title and URL
    $title = $data['title'] ?? $item['slug'];
    $url = $urlPattern ? str_replace('{slug}', $item['slug'], $urlPattern) : '';

    return [
        'id' => (int) $item['id'],
        'slug' => $item['slug'],
        'title' => $title,
        'url' => $url,
        'status' => $item['status'],
        'created_at' => $item['created_at'],
        'updated_at' => $item['updated_at'],
        'published_at' => $item['published_at'],
        'fields' => $fields,
        'labels' => $labelsByFolder,
    ];
}

// ── Folders endpoint ─────────────────────────────────────
function handle_content_folders(): void {
    $collectionFilter = content_param('collection') ?? '';
    $sql = 'SELECT f.id, f.slug, f.name, f.singular_name, f.type, c.slug as collection_slug
            FROM folders f
            LEFT JOIN collections c ON c.id = f.collection_id';
    $params = [];

    if ($collectionFilter !== '') {
        $sql .= ' WHERE c.slug = ?';
        $params[] = $collectionFilter;
    }

    $sql .= ' ORDER BY f.name ASC';
    $folders = OutpostDB::fetchAll($sql, $params);

    $result = [];
    foreach ($folders as $fld) {
        $count = OutpostDB::fetchOne(
            'SELECT COUNT(*) as count FROM labels WHERE folder_id = ?',
            [$fld['id']]
        );
        $result[] = [
            'slug' => $fld['slug'],
            'name' => $fld['name'],
            'singular_name' => $fld['singular_name'] ?: $fld['name'],
            'type' => $fld['type'] ?: 'flat',
            'collection_slug' => $fld['collection_slug'],
            'label_count' => (int) ($count['count'] ?? 0),
        ];
    }

    content_response($result, ['total' => count($result)]);
}

// Legacy alias
function handle_content_taxonomies(): void { handle_content_folders(); }

// ── Labels endpoint ──────────────────────────────────────
function handle_content_labels(): void {
    // Accept both ?folder= (new) and ?taxonomy= (legacy)
    $folderSlug = content_param('folder') ?? content_param('taxonomy') ?? '';
    if (!$folderSlug) content_error('folder parameter required');

    $folder = OutpostDB::fetchOne(
        'SELECT f.id, f.slug, f.name, f.type, c.slug as collection_slug
         FROM folders f
         LEFT JOIN collections c ON c.id = f.collection_id
         WHERE f.slug = ?',
        [$folderSlug]
    );
    if (!$folder) content_error('Folder not found', 404);

    $labels = OutpostDB::fetchAll(
        'SELECT id, slug, name, description, parent_id, data, sort_order FROM labels WHERE folder_id = ? ORDER BY sort_order ASC, name ASC',
        [$folder['id']]
    );

    $result = [];
    foreach ($labels as $l) {
        $itemCount = OutpostDB::fetchOne(
            "SELECT COUNT(*) as count FROM item_labels il
             JOIN collection_items ci ON ci.id = il.item_id
             WHERE il.label_id = ? AND ci.status = 'published'
               AND (ci.published_at IS NULL OR ci.published_at <= datetime('now'))",
            [$l['id']]
        );
        $data = json_decode($l['data'] ?: '{}', true);
        $result[] = [
            'id' => (int) $l['id'],
            'slug' => $l['slug'],
            'name' => $l['name'],
            'description' => $l['description'] ?? '',
            'parent_id' => $l['parent_id'] ? (int) $l['parent_id'] : null,
            'data' => $data,
            'item_count' => (int) ($itemCount['count'] ?? 0),
        ];
    }

    content_response($result, [
        'total' => count($result),
        'folder' => [
            'slug' => $folder['slug'],
            'name' => $folder['name'],
            'type' => $folder['type'] ?: 'flat',
            'collection_slug' => $folder['collection_slug'],
        ],
    ]);
}

// Legacy alias
function handle_content_terms(): void { handle_content_labels(); }

// ── Globals endpoint ─────────────────────────────────────
function handle_content_globals(): void {
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalPage) { content_response([]); return; }

    $fields = OutpostDB::fetchAll(
        "SELECT field_name, field_type, content FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
        [$globalPage['id']]
    );

    $result = [];
    foreach ($fields as $f) {
        $result[$f['field_name']] = $f['content'];
    }
    content_response($result);
}

// ── Media endpoint ──────────────────────────────────────
function handle_content_media(): void {
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $total = OutpostDB::fetchOne('SELECT COUNT(*) as count FROM media');
    $totalCount = (int) ($total['count'] ?? 0);

    $media = OutpostDB::fetchAll(
        'SELECT id, filename, original_name, path, thumb_path, mime_type, file_size, width, height, alt_text, uploaded_at
         FROM media ORDER BY uploaded_at DESC LIMIT ? OFFSET ?',
        [$limit, $offset]
    );

    $result = [];
    foreach ($media as $m) {
        $result[] = [
            'id' => (int) $m['id'],
            'filename' => $m['filename'],
            'original_name' => $m['original_name'],
            'path' => $m['path'],
            'thumb_path' => $m['thumb_path'],
            'mime_type' => $m['mime_type'],
            'file_size' => (int) $m['file_size'],
            'width' => (int) $m['width'],
            'height' => (int) $m['height'],
            'alt_text' => $m['alt_text'],
            'uploaded_at' => $m['uploaded_at'],
        ];
    }

    content_response($result, [
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

// ── Menus endpoint ──────────────────────────────────────
function handle_content_menus(): void {
    $slug = content_param('slug');

    if ($slug !== null) {
        // Single menu by slug
        $menu = OutpostDB::fetchOne('SELECT slug, name, items FROM menus WHERE slug = ?', [$slug]);
        if (!$menu) content_error('Menu not found', 404);

        $items = json_decode($menu['items'], true) ?? [];

        // Build nested items with children
        $formatted = [];
        foreach ($items as $item) {
            $entry = [
                'label'  => $item['label'] ?? '',
                'url'    => $item['url'] ?? '',
                'target' => $item['target'] ?? '',
            ];
            if (!empty($item['children']) && is_array($item['children'])) {
                $entry['children'] = array_map(fn($child) => [
                    'label'  => $child['label'] ?? '',
                    'url'    => $child['url'] ?? '',
                    'target' => $child['target'] ?? '',
                ], $item['children']);
            } else {
                $entry['children'] = [];
            }
            $formatted[] = $entry;
        }

        content_response([
            'slug' => $menu['slug'],
            'name' => $menu['name'],
            'items' => $formatted,
        ]);
        return;
    }

    // List all menus
    $menus = OutpostDB::fetchAll('SELECT slug, name, items FROM menus ORDER BY id ASC');
    $result = [];
    foreach ($menus as $menu) {
        $items = json_decode($menu['items'], true) ?? [];
        $result[] = [
            'slug' => $menu['slug'],
            'name' => $menu['name'],
            'item_count' => count($items),
        ];
    }

    content_response($result, ['total' => count($result)]);
}

// ── Syntax reference ─────────────────────────────────────
// Add new rows here whenever new tags/filters are added to engine.php / template-engine.php.
function handle_content_syntax(): void {
    $groups = [
        [
            'label' => 'CMS Fields',
            'rows' => [
                ['syntax' => '{{ field_name }}',               'description' => 'Editable text field — HTML-escaped output'],
                ['syntax' => '{{ field_name "Default" }}',     'description' => 'Text field with default shown until client saves a value'],
                ['syntax' => '{{ field_name | raw }}',         'description' => 'Rich text (HTML) field — output as-is, no escaping'],
                ['syntax' => '{{ field_name | raw "Default" }}','description' => 'Rich text field with default HTML'],
                ['syntax' => '{{ field_name | image }}',       'description' => 'Image URL field — outputs the upload path'],
                ['syntax' => '{{ field_name | textarea }}',    'description' => 'Multi-line text — preserves line breaks'],
                ['syntax' => '{{ field_name | link }}',        'description' => 'URL / link field'],
                ['syntax' => '{{ field_name | color }}',       'description' => 'Hex color value (e.g. #14B8A6)'],
                ['syntax' => '{{ field_name | number }}',      'description' => 'Numeric field'],
                ['syntax' => '{{ field_name | date }}',        'description' => 'Date field'],
                ['syntax' => '{{ field_name | select }}',      'description' => 'Select field — outputs the chosen value'],
                ['syntax' => '{{ field_name | toggle }}',      'description' => 'Boolean toggle — registers field type; use {% if field_name %} for logic'],
                ['syntax' => '{{ field_name | or_body }}',     'description' => 'Text with auto-fallback: outputs field value, or a 40-word excerpt from body if empty'],
            ],
        ],
        [
            'label' => 'Collection Loops',
            'rows' => [
                ['syntax' => '{% for item in collection.slug %}',                    'description' => 'Loop over all published items in a collection'],
                ['syntax' => '{% for item in collection.slug limit:5 %}',            'description' => 'Loop with a result limit'],
                ['syntax' => '{% for item in collection.slug orderby:field %}',      'description' => 'Sort by a field (descending)'],
                ['syntax' => '{% for item in collection.slug paginate:10 %}',        'description' => 'Paginate — show N per page; add {% pagination %} after loop'],
                ['syntax' => '{% for item in collection.slug filteredby:param %}',   'description' => 'Filter by URL query param matching folder label slugs'],
                ['syntax' => '{% for item in collection.slug related:var %}',        'description' => 'Show items sharing folder labels with the named variable'],
                ['syntax' => '{% else %}',                                            'description' => 'Rendered when the collection is empty (inside for loop)'],
                ['syntax' => '{% endfor %}',                                         'description' => 'Close the loop'],
                ['syntax' => '{% pagination %}',                                     'description' => 'Render page navigation after a paginate: loop'],
                ['syntax' => '{{ item.field_name }}',                               'description' => 'Output a field from the current loop item (HTML-escaped)'],
                ['syntax' => '{{ item.field_name | raw }}',                         'description' => 'Output a richtext field from loop item (unescaped)'],
                ['syntax' => '{{ item.url }}',                                      'description' => 'Full URL path to this item (e.g. /blog/my-post)'],
                ['syntax' => '{{ item.slug }}',                                     'description' => 'URL-safe identifier for the item'],
                ['syntax' => '{{ item.published_at }}',                             'description' => 'Publish date/time string'],
            ],
        ],
        [
            'label' => 'Single Item',
            'rows' => [
                ['syntax' => '{% single item from collection.slug %}', 'description' => 'Fetch a single item — slug read from ?slug= query param'],
                ['syntax' => '{% else %}',                             'description' => 'Rendered when no matching item is found'],
                ['syntax' => '{% endsingle %}',                       'description' => 'Close the single block'],
            ],
        ],
        [
            'label' => 'Folder Loops',
            'rows' => [
                ['syntax' => '{% for label in folder.slug %}',   'description' => 'Iterate over all labels in a folder'],
                ['syntax' => '{{ label.name }}',                 'description' => 'Display name of the label'],
                ['syntax' => '{{ label.slug }}',                 'description' => 'URL-safe slug of the label'],
                ['syntax' => '{{ label.count }}',                'description' => 'Number of items tagged with this label'],
                ['syntax' => '{% endfor %}',                     'description' => 'Close the folder loop'],
            ],
        ],
        [
            'label' => 'Menu Loops',
            'rows' => [
                ['syntax' => '{% for item in menu.slug %}',         'description' => 'Iterate over items in a navigation menu'],
                ['syntax' => '{{ item.label }}',                    'description' => 'Menu item display text'],
                ['syntax' => '{{ item.url }}',                      'description' => 'Menu item URL'],
                ['syntax' => '{{ item.target }}',                   'description' => 'Link target (_blank for new tab, empty otherwise)'],
                ['syntax' => '{% for child in item.children %}',    'description' => 'Nested loop for dropdown sub-items'],
                ['syntax' => '{% endfor %}',                        'description' => 'Close the menu loop'],
            ],
        ],
        [
            'label' => 'Gallery & Repeater',
            'rows' => [
                ['syntax' => '{% for img in gallery.field_name %}', 'description' => 'Iterate over images in a gallery field'],
                ['syntax' => '{{ img.url }}',                       'description' => 'Image URL within the gallery'],
                ['syntax' => '{% for row in repeater.field_name %}','description' => 'Iterate over rows in a repeater field'],
                ['syntax' => '{{ row.sub_field }}',                 'description' => 'Access a sub-field within the repeater row'],
                ['syntax' => '{% endfor %}',                        'description' => 'Close the gallery or repeater loop'],
            ],
        ],
        [
            'label' => 'Conditionals',
            'rows' => [
                ['syntax' => '{% if field_name %}',              'description' => 'True when any field has a non-empty value (text, toggle, repeater, etc.)'],
                ['syntax' => '{% if item.field %}',              'description' => 'True when a collection/single variable field is non-empty'],
                ['syntax' => '{% if @global_name %}',            'description' => 'True when a global field has a value'],
                ['syntax' => '{% if item.field == "value" %}',   'description' => 'String equality check on a loop/single variable'],
                ['syntax' => '{% if item.field != "value" %}',   'description' => 'String inequality check'],
                ['syntax' => '{% if admin %}',                   'description' => 'True only when a logged-in admin is previewing the page'],
                ['syntax' => '{% else %}',                       'description' => 'Fallback branch'],
                ['syntax' => '{% endif %}',                      'description' => 'Close the conditional'],
            ],
        ],
        [
            'label' => 'Globals & Meta',
            'rows' => [
                ['syntax' => '{{ @global_name }}',            'description' => 'Site-wide global field (shared across all pages)'],
                ['syntax' => '{{ @global_name | raw }}',      'description' => 'Global rich text field — output as-is'],
                ['syntax' => '{{ @global_name | image }}',    'description' => 'Global image field'],
                ['syntax' => '{{ @global_name | link }}',     'description' => 'Global URL/link field'],
                ['syntax' => '{{ meta.title "Default" }}',    'description' => 'Page meta title — falls back to default if not set in admin'],
                ['syntax' => '{{ meta.description "Def" }}',  'description' => 'Page meta description with fallback'],
            ],
        ],
        [
            'label' => 'Includes & Comments',
            'rows' => [
                ['syntax' => "{% include 'partial-name' %}",  'description' => "Include a partial from the theme's partials/ directory (no extension)"],
                ['syntax' => '{# comment text #}',            'description' => 'Template comment — stripped at compile time, never sent to browser'],
            ],
        ],
    ];
    content_response($groups);
}
