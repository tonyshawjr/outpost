<?php
/**
 * Outpost CMS — Core Engine
 * Include this at the top of any .php page to enable CMS functionality.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/forms-engine.php';
require_once __DIR__ . '/channels.php';

// ── Globals ──────────────────────────────────────────────
$_outpost_page_id = null;
$_outpost_page_path = null;
$_outpost_field_counter = 0;
$_outpost_fields_cache = [];
$_outpost_globals_cache = null;
$_outpost_cache_active = false;
$_outpost_active_theme = '';
$_outpost_current_item = null;       // set when cms_collection_single() finds an item
$_outpost_current_collection = '';   // slug of collection for current single item
$_outpost_edit_mode = false;         // true when admin is viewing frontend (on-page editing)
$_outpost_in_single = false;         // true when inside a {% single %} block (for OPE wrapping)
$_outpost_image_fields = [];         // collected image fields for JS matching

// ── Bootstrap ────────────────────────────────────────────
function outpost_init(): void {
    global $_outpost_page_id, $_outpost_page_path, $_outpost_cache_active, $_outpost_active_theme;
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    if (!file_exists(OUTPOST_DB_PATH)) {
        return; // Not installed yet
    }

    // Run migrations (idempotent)
    ensure_fields_theme_column();
    ensure_indexes();

    // Read active theme from settings
    $themeRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
    $_outpost_active_theme = $themeRow ? $themeRow['value'] : 'starter';

    // Determine current page path
    $_outpost_page_path = outpost_current_path();

    // Skip non-page requests (favicon, robots, static assets, etc.)
    if (outpost_should_skip($_outpost_page_path)) {
        return;
    }

    // Auto-scan templates if no fields exist OR if template files changed since last scan
    $needsScan = false;
    $fieldCount = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM fields WHERE theme = ?",
        [$_outpost_active_theme]
    );
    if (($fieldCount['c'] ?? 0) == 0) {
        $needsScan = true;
    } else {
        // Check if any theme template files are newer than the last scan
        $themeDir = OUTPOST_THEMES_DIR . $_outpost_active_theme . '/';
        if (is_dir($themeDir)) {
            $maxMtime = 0;
            $htmlFiles = glob($themeDir . '*.html') ?: [];
            $partialFiles = is_dir($themeDir . 'partials/') ? (glob($themeDir . 'partials/*.html') ?: []) : [];
            foreach (array_merge($htmlFiles, $partialFiles) as $f) {
                $mt = filemtime($f);
                if ($mt > $maxMtime) $maxMtime = $mt;
            }
            if ($maxMtime > 0) {
                $lastScan = OutpostDB::fetchOne(
                    "SELECT value FROM settings WHERE key = ?",
                    ['last_template_scan_' . $_outpost_active_theme]
                );
                if (!$lastScan || (int) $lastScan['value'] < $maxMtime) {
                    $needsScan = true;
                }
            }
        }
    }
    if ($needsScan) {
        outpost_scan_theme_templates($_outpost_active_theme);
    }

    // Check cache first — skip for logged-in admins, preview mode
    $isPreview = defined('OUTPOST_PREVIEW_MODE') && OUTPOST_PREVIEW_MODE;
    if (OUTPOST_CACHE_ENABLED && !isset($_GET['nocache']) && !outpost_is_admin() && !$isPreview) {
        $cache_file = outpost_cache_path($_outpost_page_path);
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
            readfile($cache_file);
            exit;
        }
    }

    // Detect admin for on-page editing annotations
    global $_outpost_edit_mode;
    $_outpost_edit_mode = outpost_is_admin();

    // Auto-discover page
    $_outpost_page_id = outpost_discover_page($_outpost_page_path);

    // Preload all fields for this page + active theme
    outpost_preload_fields($_outpost_page_id);

    // Always buffer output for GA4 + admin bar injection (and optionally caching)
    $_outpost_cache_active = true;
    ob_start('outpost_cache_output');
}

function outpost_current_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = '/' . trim($path, '/');
    // Remove .php extension for cleaner paths
    $path = preg_replace('/\.php$/', '', $path);
    if ($path === '') $path = '/';
    return $path;
}

function outpost_should_skip(string $path): bool {
    // Skip common non-page paths
    $skip_exact = ['/favicon.ico', '/favicon.png', '/robots.txt', '/sitemap.xml', '/humans.txt'];
    if (in_array($path, $skip_exact)) return true;

    // Skip paths with file extensions that aren't pages
    if (preg_match('/\.(ico|png|jpg|jpeg|gif|svg|webp|css|js|map|woff2?|ttf|eot|xml|json|txt|pdf|mp4|webm)$/i', $path)) {
        return true;
    }

    // Skip outpost admin paths
    if (str_starts_with($path, '/outpost')) return true;

    return false;
}

function outpost_discover_page(string $path): int {
    $page = OutpostDB::fetchOne('SELECT id FROM pages WHERE path = ?', [$path]);
    if ($page) {
        return (int) $page['id'];
    }
    // Auto-discover: register new page
    $title = ucwords(str_replace(['/', '-', '_'], ' ', trim($path, '/'))) ?: 'Home';
    return OutpostDB::insert('pages', [
        'path' => $path,
        'title' => $title,
    ]);
}

function outpost_preload_fields(int $page_id): void {
    global $_outpost_fields_cache, $_outpost_active_theme;
    $fields = OutpostDB::fetchAll(
        'SELECT id, field_name, field_type, content, default_value, options FROM fields WHERE page_id = ? AND theme = ? ORDER BY sort_order ASC',
        [$page_id, $_outpost_active_theme]
    );
    $_outpost_fields_cache = [];
    foreach ($fields as $f) {
        $_outpost_fields_cache[$f['field_name']] = $f;
    }
}

// ── Field Resolution ─────────────────────────────────────
function outpost_resolve_field(string $name, string $type, string $default, string $options = ''): string {
    global $_outpost_page_id, $_outpost_fields_cache, $_outpost_field_counter, $_outpost_active_theme;

    if ($_outpost_page_id === null) return $default;

    $_outpost_field_counter++;

    // Check if field exists in cache
    if (isset($_outpost_fields_cache[$name])) {
        $field = $_outpost_fields_cache[$name];
        $value = $field['content'];
        return ($value !== '' && $value !== null) ? $value : $default;
    }

    // Auto-register new field (INSERT OR IGNORE to handle race conditions / duplicate stubs)
    OutpostDB::query(
        "INSERT OR IGNORE INTO fields (page_id, theme, field_name, field_type, content, default_value, options, sort_order) VALUES (?, ?, ?, ?, '', ?, ?, ?)",
        [$_outpost_page_id, $_outpost_active_theme, $name, $type, $default, $options, $_outpost_field_counter]
    );

    $_outpost_fields_cache[$name] = [
        'field_name' => $name,
        'field_type' => $type,
        'content' => '',
        'default_value' => $default,
        'options' => $options,
    ];

    return $default;
}

// ── Tag Functions ────────────────────────────────────────

// Normalize stored values that may contain pre-encoded HTML entities (e.g. from imports)
function outpost_esc(string $v): string {
    return htmlspecialchars(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

function cms_text(string $name, string $default = ''): void {
    global $_outpost_edit_mode, $_outpost_fields_cache, $_outpost_page_id;
    $val = outpost_esc(outpost_resolve_field($name, 'text', $default));
    if ($_outpost_edit_mode && isset($_outpost_fields_cache[$name])) {
        $fid = (int) $_outpost_fields_cache[$name]['id'];
        echo '<span data-ope-field="' . htmlspecialchars($name, ENT_QUOTES) . '" data-ope-id="' . $fid . '" data-ope-page="' . $_outpost_page_id . '" data-ope-type="text">' . $val . '</span>';
    } else {
        echo $val;
    }
}

function cms_textarea(string $name, string $default = ''): void {
    global $_outpost_edit_mode, $_outpost_fields_cache, $_outpost_page_id;
    $val = nl2br(htmlspecialchars(outpost_resolve_field($name, 'textarea', $default), ENT_QUOTES, 'UTF-8'));
    if ($_outpost_edit_mode && isset($_outpost_fields_cache[$name])) {
        $fid = (int) $_outpost_fields_cache[$name]['id'];
        echo '<span data-ope-field="' . htmlspecialchars($name, ENT_QUOTES) . '" data-ope-id="' . $fid . '" data-ope-page="' . $_outpost_page_id . '" data-ope-type="textarea">' . $val . '</span>';
    } else {
        echo $val;
    }
}

function cms_richtext(string $name, string $default = ''): void {
    global $_outpost_edit_mode, $_outpost_fields_cache, $_outpost_page_id;
    // Richtext content is stored sanitized, output as-is
    $val = outpost_resolve_field($name, 'richtext', $default);
    if ($_outpost_edit_mode && isset($_outpost_fields_cache[$name])) {
        $fid = (int) $_outpost_fields_cache[$name]['id'];
        echo '<div data-ope-field="' . htmlspecialchars($name, ENT_QUOTES) . '" data-ope-id="' . $fid . '" data-ope-page="' . $_outpost_page_id . '" data-ope-type="richtext">' . $val . '</div>';
    } else {
        echo $val;
    }
}

function cms_image(string $name, string $default = ''): void {
    global $_outpost_edit_mode, $_outpost_fields_cache, $_outpost_page_id, $_outpost_image_fields;
    $val = htmlspecialchars(outpost_resolve_field($name, 'image', $default), ENT_QUOTES, 'UTF-8');
    echo $val;
    if ($_outpost_edit_mode && isset($_outpost_fields_cache[$name])) {
        $_outpost_image_fields[] = [
            'name' => $name,
            'id' => (int) $_outpost_fields_cache[$name]['id'],
            'page' => $_outpost_page_id,
            'value' => $val,
        ];
    }
}

function cms_focal(string $name): void {
    // Look up the image path from the field, then find its focal point in the media table
    $path = outpost_resolve_field($name, 'image', '');
    if ($path) {
        $media = OutpostDB::fetchOne('SELECT focal_x, focal_y FROM media WHERE path = ?', [$path]);
        if ($media) {
            echo (int) $media['focal_x'] . '% ' . (int) $media['focal_y'] . '%';
            return;
        }
    }
    echo '50% 50%';
}

function cms_link(string $name, string $default = ''): void {
    echo htmlspecialchars(outpost_resolve_field($name, 'link', $default), ENT_QUOTES, 'UTF-8');
}

function cms_select(string $name, string $default = '', array $options = []): void {
    $opts_json = json_encode($options);
    echo htmlspecialchars(outpost_resolve_field($name, 'select', $default, $opts_json), ENT_QUOTES, 'UTF-8');
}

function cms_toggle(string $name, bool $default = false): bool {
    $val = outpost_resolve_field($name, 'toggle', $default ? '1' : '0');
    return $val === '1' || $val === 'true';
}

// General truthy check for {% if field %} — works for toggles, text, and repeater fields.
function cms_field_truthy(string $name): bool {
    $val = outpost_resolve_field($name, 'text', '');
    if ($val === '' || $val === null || $val === '0') return false;
    // Repeater/gallery: check that the JSON array is non-empty
    if (str_starts_with(ltrim($val), '[')) {
        $arr = json_decode($val, true);
        return is_array($arr) && count($arr) > 0;
    }
    return true;
}

// Returns gallery items as [{url: ...}] array for template loops.
function cms_gallery_items(string $name): array {
    $json = outpost_resolve_field($name, 'gallery', '[]', '[]');
    $items = json_decode($json, true);
    if (!is_array($items)) return [];
    // Return raw URLs; the template engine's {{ img.url }} auto-escapes on output.
    return array_map(function ($url) {
        return ['url' => (string) $url];
    }, $items);
}

// Returns all rows of a repeater field as an array of escaped key=>value maps.
function cms_repeater_items(string $name): array {
    $json = outpost_resolve_field($name, 'repeater', '[]', '[]');
    $items = json_decode($json, true);
    if (!is_array($items)) return [];
    return array_map(function ($item) {
        $safe = [];
        foreach ($item as $k => $v) {
            $safe[$k] = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        }
        return $safe;
    }, $items);
}

// Returns all rows of a flexible content field as an array of associative arrays.
// Each row includes a 'layout' key identifying its layout type, plus all sub-field values.
// Sub-field values are escaped except richtext (identified by layout schema).
function cms_flexible_items(string $name): array {
    $json = outpost_resolve_field($name, 'flexible', '[]', '[]');
    $items = json_decode($json, true);
    if (!is_array($items)) return [];
    return array_map(function ($item) {
        $safe = [];
        foreach ($item as $k => $v) {
            if ($k === '_layout') {
                $safe['layout'] = (string) $v;
            } else {
                $safe[$k] = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            }
        }
        return $safe;
    }, $items);
}

// Returns related collection items for a relationship field.
// Fetches full item data (same shape as collection loop items) by stored IDs.
// Preserves the order of IDs as stored in the field value.
function cms_relationship_items(string $name): array {
    $json = outpost_resolve_field($name, 'relationship', '[]', '[]');
    $ids = json_decode($json, true);
    if (!is_array($ids) || empty($ids)) return [];
    // Sanitize IDs to integers
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = OutpostDB::fetchAll(
        "SELECT ci.*, c.slug AS collection_slug, c.url_pattern, c.schema
         FROM collection_items ci
         JOIN collections c ON ci.collection_id = c.id
         WHERE ci.id IN ({$placeholders}) AND ci.status = 'published'",
        $ids
    );
    if (!$rows) return [];

    // Index rows by ID for order preservation
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = $row;
    }

    $result = [];
    foreach ($ids as $id) {
        if (!isset($byId[$id])) continue;
        $row = $byId[$id];
        $data = json_decode($row['data'], true) ?: [];
        $schema = json_decode($row['schema'], true) ?: [];
        // Escape fields based on schema type (richtext stays raw)
        foreach ($data as $k => $v) {
            $fieldType = $schema[$k]['type'] ?? ($schema[$k] ?? 'text');
            if (is_string($fieldType) && $fieldType === 'richtext') {
                // richtext: leave as-is
            } elseif (is_string($v)) {
                $data[$k] = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            }
        }
        $data['id'] = (int) $row['id'];
        $data['slug'] = htmlspecialchars($row['slug'], ENT_QUOTES, 'UTF-8');
        $data['status'] = $row['status'];
        $data['created_at'] = $row['created_at'] ?? '';
        $data['updated_at'] = $row['updated_at'] ?? '';
        $data['published_at'] = $row['published_at']
            ? date('F j, Y', strtotime($row['published_at']))
            : '';
        // Build URL from url_pattern
        $data['url'] = $row['url_pattern']
            ? str_replace('{slug}', $row['slug'], $row['url_pattern'])
            : '';
        $result[] = $data;
    }
    return $result;
}

// Returns navigation items for a named menu by slug.
// Each item: ['label' => ..., 'url' => ..., 'target' => ..., 'children' => [...]]
// Children arrays have the same shape (escaped). Use in templates as:
//   {% for item in menu.slug %} ... {% endfor %}
function cms_menu_items(string $slug): array {
    $row = OutpostDB::fetchOne('SELECT items FROM menus WHERE slug = ?', [$slug]);
    if (!$row) return [];
    $items = json_decode($row['items'], true);
    if (!is_array($items)) return [];

    $sanitize = function (array $item) use (&$sanitize): array {
        $safe = [
            'label'    => htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'url'      => htmlspecialchars((string)($item['url'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'target'   => ($item['target'] ?? '') === '_blank' ? '_blank' : '_self',
            'children' => [],
        ];
        if (!empty($item['children']) && is_array($item['children'])) {
            $safe['children'] = array_map($sanitize, $item['children']);
        }
        return $safe;
    };

    return array_map($sanitize, $items);
}

/**
 * Returns all labels for a folder (by slug), with published item counts.
 * Used by {% for label in folder.slug %} in templates.
 * Each label has: name, slug, count.
 */
function cms_folder_labels(string $folder_slug): array {
    if (!file_exists(OUTPOST_DB_PATH)) return [];
    $labels = OutpostDB::fetchAll(
        "SELECT lb.slug, lb.name,
                COUNT(DISTINCT ci.id) AS count
         FROM labels lb
         JOIN folders f ON lb.folder_id = f.id
         LEFT JOIN item_labels il ON il.label_id = lb.id
         LEFT JOIN collection_items ci ON ci.id = il.item_id AND ci.status = 'published'
         WHERE f.slug = ?
         GROUP BY lb.id
         ORDER BY lb.sort_order ASC, lb.name ASC",
        [$folder_slug]
    );
    return array_map(function ($t) {
        return [
            'name'  => htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'slug'  => htmlspecialchars((string) ($t['slug'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'count' => (int) ($t['count'] ?? 0),
        ];
    }, $labels);
}

/**
 * Backward-compat alias for cms_folder_labels().
 */
function cms_taxonomy_terms(string $slug): array {
    return cms_folder_labels($slug);
}

function cms_color(string $name, string $default = '#000000'): void {
    echo htmlspecialchars(outpost_resolve_field($name, 'color', $default), ENT_QUOTES, 'UTF-8');
}

function cms_number(string $name, string $default = '0'): void {
    echo htmlspecialchars(outpost_resolve_field($name, 'number', $default), ENT_QUOTES, 'UTF-8');
}

function cms_date(string $name, string $default = ''): void {
    echo htmlspecialchars(outpost_resolve_field($name, 'date', $default), ENT_QUOTES, 'UTF-8');
}

// ── On-Page Editing: Collection Item Wrappers ───────────
// Used by compiled templates inside {% single %} blocks to annotate item fields.

function outpost_ope_item_text(array $item, string $field): string {
    global $_outpost_edit_mode, $_outpost_in_single, $_outpost_current_collection;
    $val = outpost_esc($item[$field] ?? '');
    if ($_outpost_edit_mode && $_outpost_in_single && isset($item['id'])) {
        $itemId = (int) $item['id'];
        $coll = htmlspecialchars($_outpost_current_collection, ENT_QUOTES);
        $fn = htmlspecialchars($field, ENT_QUOTES);
        return '<span data-ope-field="' . $fn . '" data-ope-item="' . $itemId . '" data-ope-collection="' . $coll . '" data-ope-type="text">' . $val . '</span>';
    }
    return $val;
}

function outpost_ope_item_raw(array $item, string $field): string {
    global $_outpost_edit_mode, $_outpost_in_single, $_outpost_current_collection;
    $val = $item[$field] ?? '';
    if ($_outpost_edit_mode && $_outpost_in_single && isset($item['id'])) {
        $itemId = (int) $item['id'];
        $coll = htmlspecialchars($_outpost_current_collection, ENT_QUOTES);
        $fn = htmlspecialchars($field, ENT_QUOTES);
        return '<div data-ope-field="' . $fn . '" data-ope-item="' . $itemId . '" data-ope-collection="' . $coll . '" data-ope-type="richtext">' . $val . '</div>';
    }
    return $val;
}

function outpost_ope_item_image(array $item, string $field): string {
    global $_outpost_edit_mode, $_outpost_in_single, $_outpost_current_collection, $_outpost_image_fields;
    $val = htmlspecialchars($item[$field] ?? '', ENT_QUOTES, 'UTF-8');
    if ($_outpost_edit_mode && $_outpost_in_single && isset($item['id'])) {
        $_outpost_image_fields[] = [
            'name' => $field,
            'item' => (int) $item['id'],
            'collection' => $_outpost_current_collection,
            'value' => $val,
        ];
    }
    return $val;
}

/**
 * Output focal point for an item's image field (e.g. {{ item.featured_image | focal }}).
 */
function outpost_item_focal(array $item, string $field): void {
    $path = $item[$field] ?? '';
    if ($path) {
        $media = OutpostDB::fetchOne('SELECT focal_x, focal_y FROM media WHERE path = ?', [$path]);
        if ($media) {
            echo (int) $media['focal_x'] . '% ' . (int) $media['focal_y'] . '%';
            return;
        }
    }
    echo '50% 50%';
}

/**
 * Auto-excerpt: strips HTML and returns the first $words words.
 */
function outpost_auto_excerpt(string $text, int $words = 40): string {
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    $arr  = explode(' ', $text);
    if (count($arr) <= $words) return $text;
    return implode(' ', array_slice($arr, 0, $words)) . '…';
}

function cms_meta_title(string $name, string $default = ''): void {
    global $_outpost_page_id, $_outpost_current_item;
    // Collection single item: prefer item's meta_title, fallback to item title
    if ($_outpost_current_item !== null) {
        $value = $_outpost_current_item['meta_title'] ?? '';
        if ($value === '') $value = $_outpost_current_item['title'] ?? $default;
        echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return;
    }
    if ($_outpost_page_id === null) {
        echo htmlspecialchars($default, ENT_QUOTES, 'UTF-8');
        return;
    }
    $page = OutpostDB::fetchOne('SELECT meta_title FROM pages WHERE id = ?', [$_outpost_page_id]);
    $value = ($page && $page['meta_title'] !== '') ? $page['meta_title'] : '';
    if ($value === '') {
        $value = outpost_resolve_field($name, 'meta_title', $default);
    }
    echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cms_meta_description(string $name, string $default = ''): void {
    global $_outpost_page_id, $_outpost_current_item;
    // Collection single item: prefer item's meta_description, fallback to excerpt
    if ($_outpost_current_item !== null) {
        $value = $_outpost_current_item['meta_description'] ?? '';
        if ($value === '') {
            $body = $_outpost_current_item['excerpt'] ?? $_outpost_current_item['body'] ?? '';
            $value = $body ? outpost_auto_excerpt($body, 30) : $default;
        }
        echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return;
    }
    if ($_outpost_page_id === null) {
        echo htmlspecialchars($default, ENT_QUOTES, 'UTF-8');
        return;
    }
    $page = OutpostDB::fetchOne('SELECT meta_description FROM pages WHERE id = ?', [$_outpost_page_id]);
    $value = ($page && $page['meta_description'] !== '') ? $page['meta_description'] : '';
    if ($value === '') {
        $value = outpost_resolve_field($name, 'meta_description', $default);
    }
    echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ── Global Fields ────────────────────────────────────────
function cms_global(string $name, string $type = 'text', string $default = ''): void {
    global $_outpost_globals_cache, $_outpost_edit_mode, $_outpost_image_fields;

    if (!file_exists(OUTPOST_DB_PATH)) {
        echo htmlspecialchars($default, ENT_QUOTES, 'UTF-8');
        return;
    }

    // Lazy-load globals
    if ($_outpost_globals_cache === null) {
        // Ensure global page exists
        $global_page = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if (!$global_page) {
            OutpostDB::insert('pages', ['path' => '__global__', 'title' => 'Global Fields']);
            $global_page = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        }
        $fields = OutpostDB::fetchAll(
            "SELECT id, field_name, field_type, content, default_value FROM fields WHERE page_id = ? AND theme = ''",
            [$global_page['id']]
        );
        $_outpost_globals_cache = ['_page_id' => $global_page['id']];
        foreach ($fields as $f) {
            $_outpost_globals_cache[$f['field_name']] = $f;
        }
    }

    if (isset($_outpost_globals_cache[$name])) {
        $val = $_outpost_globals_cache[$name]['content'];
        $output = ($val !== '' && $val !== null) ? $val : $default;
    } else {
        // Auto-register (globals always unscoped, theme = '')
        OutpostDB::query(
            "INSERT OR IGNORE INTO fields (page_id, theme, field_name, field_type, content, default_value) VALUES (?, '', ?, ?, '', ?)",
            [$_outpost_globals_cache['_page_id'], $name, $type, $default]
        );
        $output = $default;
    }

    $globalPageId = $_outpost_globals_cache['_page_id'];
    $fid = (int) ($_outpost_globals_cache[$name]['id'] ?? 0);

    if ($type === 'image') {
        $escaped = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        echo $escaped;
        if ($_outpost_edit_mode && $fid) {
            $_outpost_image_fields[] = [
                'name' => $name,
                'id' => $fid,
                'page' => $globalPageId,
                'value' => $escaped,
                'global' => true,
            ];
        }
    } elseif ($type === 'richtext') {
        if ($_outpost_edit_mode && $fid) {
            echo '<div data-ope-field="' . htmlspecialchars($name, ENT_QUOTES) . '" data-ope-id="' . $fid . '" data-ope-page="' . $globalPageId . '" data-ope-type="richtext" data-ope-global="1">' . $output . '</div>';
        } else {
            echo $output;
        }
    } else {
        $escaped = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        if ($_outpost_edit_mode && $fid) {
            echo '<span data-ope-field="' . htmlspecialchars($name, ENT_QUOTES) . '" data-ope-id="' . $fid . '" data-ope-page="' . $globalPageId . '" data-ope-type="text" data-ope-global="1">' . $escaped . '</span>';
        } else {
            echo $escaped;
        }
    }
}

/**
 * Returns a global field value as a string (without echoing).
 * Used internally by template-engine.php for {% if @global %} conditionals.
 */
function cms_global_get(string $name, string $default = ''): string {
    global $_outpost_globals_cache;

    if (!file_exists(OUTPOST_DB_PATH)) return $default;

    // Lazy-load globals
    if ($_outpost_globals_cache === null) {
        $global_page = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if (!$global_page) {
            OutpostDB::insert('pages', ['path' => '__global__', 'title' => 'Global Fields']);
            $global_page = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        }
        $fields = OutpostDB::fetchAll(
            "SELECT id, field_name, field_type, content, default_value FROM fields WHERE page_id = ? AND theme = ''",
            [$global_page['id']]
        );
        $_outpost_globals_cache = ['_page_id' => $global_page['id']];
        foreach ($fields as $f) {
            $_outpost_globals_cache[$f['field_name']] = $f;
        }
    }

    if (isset($_outpost_globals_cache[$name])) {
        $val = $_outpost_globals_cache[$name]['content'];
        return ($val !== '' && $val !== null) ? $val : $default;
    }

    return $default;
}

// ── SEO ──────────────────────────────────────────────────
/**
 * {% seo %} — outputs a complete SEO block for the <head>.
 *
 * Resolves title, description, image and canonical from:
 *   1. Collection item fields (if this is a single-item page)
 *   2. Page-level meta fields (set per-page in the admin)
 *   3. Global fields: @site_name, @site_tagline, @og_image, @twitter_handle
 *
 * Also outputs:
 *   - <link rel="canonical">
 *   - robots noindex for members-only / paid pages
 *   - JSON-LD Article schema on collection singles
 *   - Twitter Card and Open Graph tags
 */
function cms_seo(): void {
    global $_outpost_current_item, $_outpost_current_collection, $_outpost_page_id, $_outpost_page_path;

    if (!file_exists(OUTPOST_DB_PATH)) return;

    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // Site URL — derive from server if OUTPOST_SITE_URL is not defined
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $siteUrl  = defined('OUTPOST_SITE_URL') ? rtrim(OUTPOST_SITE_URL, '/') : ($protocol . '://' . $host);

    // Global fallbacks (auto-register new SEO globals so they appear in admin)
    $siteName      = outpost_seo_global('site_name',      'text');
    $siteTagline   = outpost_seo_global('site_tagline',   'text');
    $ogImageGlobal = outpost_seo_global('og_image',       'image');
    $twitterHandle = outpost_seo_global('twitter_handle', 'text');

    // Page-level meta
    $pageMeta       = [];
    $pageVisibility = 'public';
    if ($_outpost_page_id) {
        try {
            $row = OutpostDB::fetchOne(
                'SELECT meta_title, meta_description, visibility FROM pages WHERE id = ?',
                [$_outpost_page_id]
            );
            if ($row) {
                $pageMeta       = $row;
                $pageVisibility = $row['visibility'] ?? 'public';
            }
        } catch (\Throwable $t) {
            // visibility column may not exist yet (added by api.php migration)
            $row = OutpostDB::fetchOne(
                'SELECT meta_title, meta_description FROM pages WHERE id = ?',
                [$_outpost_page_id]
            );
            if ($row) $pageMeta = $row;
        }
    }

    // Resolve title / description / image from item → page → globals
    $item      = $_outpost_current_item;
    $isArticle = ($item !== null && $_outpost_current_collection !== '');

    if ($isArticle) {
        $title = $item['meta_title'] ?? '';
        if ($title === '') $title = $item['title'] ?? '';

        $desc = $item['meta_description'] ?? '';
        if ($desc === '') {
            $raw  = $item['excerpt'] ?? '';
            if ($raw === '') $raw = $item['body'] ?? '';
            $desc = $raw !== '' ? outpost_auto_excerpt($raw, 40) : $siteTagline;
        }

        $image  = $item['featured_image'] ?? '';
        if ($image === '') $image = $ogImageGlobal;

        $author = $item['author'] ?? '';

        // Fetch raw ISO date for JSON-LD datePublished
        $publishedIso = '';
        if (!empty($item['id'])) {
            $dateRow      = OutpostDB::fetchOne('SELECT published_at FROM collection_items WHERE id = ?', [(int)$item['id']]);
            $publishedIso = $dateRow['published_at'] ?? '';
        }
    } else {
        $title        = $pageMeta['meta_title'] ?? '';
        $desc         = ($pageMeta['meta_description'] ?? '') ?: $siteTagline;
        $image        = $ogImageGlobal;
        $author       = '';
        $publishedIso = '';
    }

    // Full <title> — "Page Title — Site Name"
    $fullTitle = $title !== ''
        ? ($siteName !== '' ? $title . ' — ' . $siteName : $title)
        : $siteName;

    // Canonical URL
    $currentPath = $_outpost_page_path ?: '/';
    $canonical   = $siteUrl . $currentPath;

    // Make image URL absolute
    $imageUrl = '';
    if ($image !== '') {
        $imageUrl = str_starts_with($image, 'http') ? $image : $siteUrl . '/' . ltrim($image, '/');
    }

    // ── Output ────────────────────────────────────────────
    echo "\n";

    echo '  <title>' . $e($fullTitle) . "</title>\n";

    // noindex for gated pages
    if (in_array($pageVisibility, ['members', 'paid'], true)) {
        echo "  <meta name=\"robots\" content=\"noindex,follow\">\n";
    }

    if ($desc !== '') {
        echo '  <meta name="description" content="' . $e($desc) . "\">\n";
    }

    echo '  <link rel="canonical" href="' . $e($canonical) . "\">\n";

    // Open Graph
    echo '  <meta property="og:type" content="' . ($isArticle ? 'article' : 'website') . "\">\n";
    echo '  <meta property="og:title" content="' . $e($fullTitle) . "\">\n";
    if ($desc !== '') {
        echo '  <meta property="og:description" content="' . $e($desc) . "\">\n";
    }
    echo '  <meta property="og:url" content="' . $e($canonical) . "\">\n";
    if ($siteName !== '') {
        echo '  <meta property="og:site_name" content="' . $e($siteName) . "\">\n";
    }
    if ($imageUrl !== '') {
        echo '  <meta property="og:image" content="' . $e($imageUrl) . "\">\n";
    }

    // Twitter Card
    echo "  <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    echo '  <meta name="twitter:title" content="' . $e($fullTitle) . "\">\n";
    if ($desc !== '') {
        echo '  <meta name="twitter:description" content="' . $e($desc) . "\">\n";
    }
    if ($imageUrl !== '') {
        echo '  <meta name="twitter:image" content="' . $e($imageUrl) . "\">\n";
    }
    if ($twitterHandle !== '') {
        echo '  <meta name="twitter:site" content="' . $e($twitterHandle) . "\">\n";
    }

    // JSON-LD Article schema (collection singles only)
    if ($isArticle) {
        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'headline' => $title,
            'url'      => $canonical,
        ];
        if ($desc !== '')         $ld['description']   = $desc;
        if ($imageUrl !== '')     $ld['image']         = $imageUrl;
        if ($publishedIso !== '') $ld['datePublished'] = $publishedIso;
        if ($author !== '')       $ld['author']        = ['@type' => 'Person', 'name' => $author];
        if ($siteName !== '')     $ld['publisher']     = ['@type' => 'Organization', 'name' => $siteName];
        echo "  <script type=\"application/ld+json\">\n";
        echo json_encode($ld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "\n  </script>\n";
    }

    echo "\n";
}

/**
 * Load a global field value and auto-register it if it doesn't exist yet.
 * Returns the string value (never echoes). Used internally by cms_seo().
 */
function outpost_seo_global(string $name, string $type): string {
    global $_outpost_globals_cache;

    if (!file_exists(OUTPOST_DB_PATH)) return '';

    // Lazy-load globals cache (same pattern as cms_global)
    if ($_outpost_globals_cache === null) {
        $global_page = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if (!$global_page) {
            OutpostDB::insert('pages', ['path' => '__global__', 'title' => 'Global Fields']);
            $global_page = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        }
        $fields = OutpostDB::fetchAll(
            "SELECT field_name, content, default_value FROM fields WHERE page_id = ? AND theme = ''",
            [$global_page['id']]
        );
        $_outpost_globals_cache = ['_page_id' => $global_page['id']];
        foreach ($fields as $f) {
            $_outpost_globals_cache[$f['field_name']] = $f;
        }
    }

    if (isset($_outpost_globals_cache[$name])) {
        $val = $_outpost_globals_cache[$name]['content'];
        return ($val !== '' && $val !== null) ? (string)$val : '';
    }

    // Auto-register so the field appears in the admin globals panel
    OutpostDB::query(
        "INSERT OR IGNORE INTO fields (page_id, theme, field_name, field_type, content, default_value) VALUES (?, '', ?, ?, '', '')",
        [$_outpost_globals_cache['_page_id'], $name, $type]
    );

    return '';
}

// ── Collections ──────────────────────────────────────────
/**
 * Holds pagination state set by cms_collection_list() for use by cms_pagination().
 */
class OutpostPagination {
    public static int $total   = 0;
    public static int $perPage = 0;
    public static int $current = 1;

    public static function set(int $total, int $perPage, int $current): void {
        self::$total   = $total;
        self::$perPage = $perPage;
        self::$current = $current;
    }
}

function cms_collection_list(string $slug, callable $callback, array $opts = []): void {
    if (!file_exists(OUTPOST_DB_PATH)) return;
    outpost_auto_publish_scheduled();

    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$collection) return;

    $limit       = $opts['limit'] ?? $collection['items_per_page'];
    $order       = $opts['order'] ?? ($collection['sort_field'] . ' ' . $collection['sort_direction']);
    $relatedId   = (int) ($opts['related_id'] ?? 0);
    $filterParam = $opts['filter_param'] ?? '';
    $filterSlug  = $filterParam ? trim($_GET[$filterParam] ?? '') : '';
    $paginate    = (int) ($opts['paginate'] ?? 0);

    // Sanitize order clause — only allow known patterns
    if (!preg_match('/^[a-z_]+ (?:ASC|DESC)$/i', $order)) {
        $order = 'created_at DESC';
    }

    // Pagination: override limit, compute offset, count total
    $offset = 0;
    if ($paginate > 0) {
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $paginate;
        $limit  = $paginate;

        if ($filterSlug) {
            $countRow = OutpostDB::fetchOne(
                "SELECT COUNT(DISTINCT ci.id) as c FROM collection_items ci
                 INNER JOIN item_terms it ON it.item_id = ci.id
                 INNER JOIN terms t ON t.id = it.term_id
                 WHERE ci.collection_id = ? AND ci.status = 'published' AND t.slug = ?",
                [$collection['id'], $filterSlug]
            );
        } else {
            $countRow = OutpostDB::fetchOne(
                "SELECT COUNT(*) as c FROM collection_items WHERE collection_id = ? AND status = 'published'",
                [$collection['id']]
            );
        }
        OutpostPagination::set((int) ($countRow['c'] ?? 0), $paginate, $page);
    }

    if ($filterSlug) {
        // Filter by taxonomy term slug from query param (e.g. ?category=bricks)
        $items = OutpostDB::fetchAll(
            "SELECT ci.* FROM collection_items ci
             INNER JOIN item_terms it ON it.item_id = ci.id
             INNER JOIN terms t ON t.id = it.term_id
             WHERE ci.collection_id = ? AND ci.status = 'published' AND t.slug = ?
             GROUP BY ci.id
             ORDER BY {$order} LIMIT ? OFFSET ?",
            [$collection['id'], $filterSlug, (int) $limit, $offset]
        );
    } elseif ($relatedId) {
        // Fetch taxonomy terms for the current item
        $termRows = OutpostDB::fetchAll(
            "SELECT term_id FROM item_terms WHERE item_id = ?",
            [$relatedId]
        );
        $termIds = array_column($termRows, 'term_id');

        if (!empty($termIds)) {
            $ph = implode(',', array_fill(0, count($termIds), '?'));
            $params = array_merge([$collection['id']], $termIds, [$relatedId, (int) $limit]);
            $items = OutpostDB::fetchAll(
                "SELECT ci.* FROM collection_items ci
                 INNER JOIN item_terms it ON it.item_id = ci.id
                 WHERE ci.collection_id = ? AND ci.status = 'published'
                   AND it.term_id IN ({$ph}) AND ci.id != ?
                 GROUP BY ci.id
                 ORDER BY {$order} LIMIT ?",
                $params
            );
        } else {
            // Post has no taxonomy terms — fall back to recent posts excluding current
            $items = OutpostDB::fetchAll(
                "SELECT * FROM collection_items WHERE collection_id = ? AND status = 'published' AND id != ? ORDER BY {$order} LIMIT ?",
                [$collection['id'], $relatedId, (int) $limit]
            );
        }
    } else {
        $items = OutpostDB::fetchAll(
            "SELECT * FROM collection_items WHERE collection_id = ? AND status = 'published' ORDER BY {$order} LIMIT ? OFFSET ?",
            [$collection['id'], (int) $limit, $offset]
        );
    }

    foreach ($items as $item) {
        $data = json_decode($item['data'], true) ?: [];
        $data['id'] = $item['id'];
        $data['slug'] = $item['slug'];
        $data['status'] = $item['status'];
        $data['created_at'] = $item['created_at'];
        $data['updated_at'] = $item['updated_at'];
        $data['published_at'] = $item['published_at']
            ? date('F j, Y', strtotime($item['published_at']))
            : '';

        // Build URL
        $pattern = $collection['url_pattern'] ?: "/{$slug}/{slug}";
        $data['url'] = str_replace('{slug}', $item['slug'], $pattern);

        // Escape non-richtext values
        $safe = [];
        $schema = json_decode($collection['schema'], true) ?: [];
        foreach ($data as $key => $val) {
            $field_type = $schema[$key]['type'] ?? 'text';
            if ($field_type === 'richtext') {
                $safe[$key] = $val;
            } else {
                $safe[$key] = is_string($val) ? htmlspecialchars($val, ENT_QUOTES, 'UTF-8') : $val;
            }
        }

        $callback($safe);
    }
}

/**
 * Renders pagination links. Call after a paginate: collection loop.
 * Preserves existing query params (e.g. ?category=coding).
 */
function cms_pagination(): void {
    $total   = OutpostPagination::$total;
    $perPage = OutpostPagination::$perPage;
    $current = OutpostPagination::$current;

    if ($perPage <= 0 || $total <= $perPage) return;

    $totalPages = (int) ceil($total / $perPage);
    $query      = $_GET;

    echo '<nav class="pagination" aria-label="Page navigation">';

    // Previous
    if ($current > 1) {
        $query['page'] = $current - 1;
        echo '<a href="?' . htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') . '" class="page-link page-prev">← Prev</a>';
    }

    // Page numbers (show up to 7, with ellipsis if needed)
    $window = 2;
    for ($i = 1; $i <= $totalPages; $i++) {
        $near  = ($i === 1 || $i === $totalPages || abs($i - $current) <= $window);
        if (!$near) {
            if ($i === 2 || $i === $totalPages - 1) {
                echo '<span class="page-ellipsis">…</span>';
            }
            continue;
        }
        $query['page'] = $i;
        $active = ($i === $current) ? ' page-current' : '';
        echo '<a href="?' . htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') . '" class="page-link' . $active . '">' . $i . '</a>';
    }

    // Next
    if ($current < $totalPages) {
        $query['page'] = $current + 1;
        echo '<a href="?' . htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') . '" class="page-link page-next">Next →</a>';
    }

    echo '</nav>';
}

function cms_collection_single(string $slug): ?array {
    global $_outpost_current_item, $_outpost_current_collection;
    if (!file_exists(OUTPOST_DB_PATH)) return null;
    outpost_auto_publish_scheduled();

    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$collection) return null;

    // Support both ?slug=... query param and path-based URLs like /post/new-post
    $item_slug = $_GET['slug'] ?? null;
    if (!$item_slug) {
        $path = outpost_current_path(); // e.g. /post/new-post
        $parts = explode('/', trim($path, '/'));
        $item_slug = count($parts) > 1 ? end($parts) : null;
    }
    if (!$item_slug) return null;

    $item = OutpostDB::fetchOne(
        'SELECT * FROM collection_items WHERE collection_id = ? AND slug = ?',
        [$collection['id'], $item_slug]
    );
    if (!$item) return null;

    $data = json_decode($item['data'], true) ?: [];
    $data['id'] = $item['id'];
    $data['slug'] = $item['slug'];
    $data['status'] = $item['status'];
    $data['created_at'] = $item['created_at'];
    $data['updated_at'] = $item['updated_at'];
    $data['published_at'] = $item['published_at']
        ? date('F j, Y', strtotime($item['published_at']))
        : '';

    // Track for admin bar edit link
    $_outpost_current_item = $data;
    $_outpost_current_collection = $slug;

    return $data;
}

// ── Cache ────────────────────────────────────────────────
function outpost_cache_path(string $page_path): string {
    // Include sorted query string so /blog?page=2 and /blog?category=bricks
    // each get their own cache entry.
    $query = $_GET;
    if (!empty($query)) {
        ksort($query);
        $page_path .= '?' . http_build_query($query);
    }
    $safe = md5($page_path);
    return OUTPOST_CACHE_DIR . $safe . '.html';
}

function outpost_cache_output(string $buffer): string {
    global $_outpost_page_path;

    // 1. Inject GA4 before </head> (included in cache — same for everyone)
    $ga4Id = cms_global_get('ga4_id');
    if ($ga4Id && stripos($buffer, '</head>') !== false) {
        $ga4 = outpost_ga4_snippet($ga4Id);
        $buffer = preg_replace('/<\/head>/i', $ga4 . "\n</head>", $buffer, 1);
    }

    // 2. Save to cache (only for non-admins, only when caching is enabled, skip preview)
    $isPreview = defined('OUTPOST_PREVIEW_MODE') && OUTPOST_PREVIEW_MODE;
    if (OUTPOST_CACHE_ENABLED && $_outpost_page_path && !outpost_is_admin() && !$isPreview) {
        $cache_file = outpost_cache_path($_outpost_page_path);
        $dir = dirname($cache_file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($cache_file, $buffer, LOCK_EX);
    }

    // 3. Inject on-page editor + admin bar before </body> (never cached — admin only)
    if (outpost_is_admin()) {
        // Inject editor CSS before </head>
        if (stripos($buffer, '</head>') !== false) {
            $editorCss = '<link rel="stylesheet" href="/outpost/admin/on-page-editor.css">';
            $buffer = preg_replace('/<\/head>/i', $editorCss . "\n</head>", $buffer, 1);
        }

        if (stripos($buffer, '</body>') !== false) {
            // Build context JSON for the on-page editor
            $opeContext = outpost_ope_context_json();
            $bar = outpost_admin_bar_html();
            $editorJs = '<script src="/outpost/admin/on-page-editor.js"></script>';
            $buffer = preg_replace('/<\/body>/i', $opeContext . "\n" . $bar . "\n" . $editorJs . "\n</body>", $buffer, 1);
        }
    }

    // 4. Inject preview banner when viewing via preview token
    if ($isPreview && stripos($buffer, '</body>') !== false) {
        $banner = '<div style="position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#1a1a2e;color:#fff;text-align:center;padding:10px 16px;font:13px/1.4 -apple-system,sans-serif;letter-spacing:0.02em;">Preview Mode — This page is not yet published. <a href="javascript:window.close()" style="color:#8b8bf5;margin-left:8px;text-decoration:underline;">Close</a></div>';
        $buffer = preg_replace('/<\/body>/i', $banner . "\n</body>", $buffer, 1);
    }

    return $buffer;
}

function outpost_ga4_snippet(string $id): string {
    $eid = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$eid}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$eid}');</script>
HTML;
}

function outpost_admin_bar_html(): string {
    global $_outpost_current_item, $_outpost_current_collection, $_outpost_page_id;

    // Build the edit URL
    if ($_outpost_current_item && $_outpost_current_collection) {
        $itemId = (int) ($_outpost_current_item['id'] ?? 0);
        $collSlug = htmlspecialchars($_outpost_current_collection, ENT_QUOTES, 'UTF-8');
        $editUrl  = "/outpost/#/collection-editor/collection={$collSlug}/itemId={$itemId}";
        $editLabel = 'Edit in Outpost';
    } elseif ($_outpost_page_id) {
        $editUrl  = "/outpost/#/page-editor/pageId={$_outpost_page_id}";
        $editLabel = 'Edit in Outpost';
    } else {
        $editUrl  = '/outpost/';
        $editLabel = 'Outpost';
    }

    $csrf = htmlspecialchars($_SESSION['outpost_csrf'] ?? '', ENT_QUOTES, 'UTF-8');

    return <<<HTML
<style id="_outpost_bar_css">
#_outpost_bar{position:fixed;bottom:20px;right:20px;display:flex;align-items:center;gap:0;background:rgba(15,15,15,.92);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.09);border-radius:100px;padding:7px 14px 7px 12px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;box-shadow:0 4px 24px rgba(0,0,0,.35);}
#_outpost_bar a{color:rgba(255,255,255,.85);text-decoration:none;font-size:12px;font-weight:500;display:flex;align-items:center;gap:5px;letter-spacing:-.01em;}
#_outpost_bar a:hover{color:#fff;}
#_outpost_bar button{color:rgba(255,255,255,.5);background:none;border:none;cursor:pointer;display:flex;align-items:center;padding:0 0 0 10px;margin-left:10px;border-left:1px solid rgba(255,255,255,.1);}
#_outpost_bar button:hover{color:#fff;}
#_ope_status{position:fixed;bottom:20px;right:20px;transform:translateX(calc(-100% - 16px));font:500 11px/1 -apple-system,sans-serif;color:rgba(255,255,255,.7);background:rgba(15,15,15,.88);backdrop-filter:blur(12px);border-radius:100px;padding:6px 12px;z-index:2147483646;opacity:0;transition:opacity .3s;pointer-events:none;}
#_ope_status.ope-visible{opacity:1;}
@keyframes _outpost_spin{to{transform:rotate(360deg)}}
#_outpost_cache_spin{display:none;animation:_outpost_spin .7s linear infinite;}
</style>
<div id="_outpost_bar">
  <a href="{$editUrl}">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
    {$editLabel}
  </a>
  <button onclick="(function(){var i=document.getElementById('_outpost_cache_ico'),s=document.getElementById('_outpost_cache_spin');if(i)i.style.display='none';if(s)s.style.display='block';fetch('/outpost/api.php?action=cache/clear',{method:'POST',headers:{'X-CSRF-Token':'{$csrf}'},credentials:'include'}).then(function(){location.reload();}).catch(function(){if(i)i.style.display='block';if(s)s.style.display='none';});})();" title="Clear cache" aria-label="Clear cache">
    <svg id="_outpost_cache_ico" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-5.1L1 10"/></svg>
    <svg id="_outpost_cache_spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.2-8.56"/></svg>
  </button>
</div>
<div id="_ope_status"></div>
HTML;
}

function outpost_ope_context_json(): string {
    global $_outpost_page_id, $_outpost_current_item, $_outpost_current_collection,
           $_outpost_image_fields, $_outpost_globals_cache;

    $csrf = htmlspecialchars($_SESSION['outpost_csrf'] ?? '', ENT_QUOTES, 'UTF-8');

    // Get page updated_at for optimistic locking
    $pageVersion = '';
    if ($_outpost_page_id) {
        $page = OutpostDB::fetchOne('SELECT updated_at FROM pages WHERE id = ?', [$_outpost_page_id]);
        $pageVersion = $page['updated_at'] ?? '';
    }

    // Get global page info
    $globalPageId = $_outpost_globals_cache['_page_id'] ?? 0;
    $globalVersion = '';
    if ($globalPageId) {
        $gp = OutpostDB::fetchOne('SELECT updated_at FROM pages WHERE id = ?', [$globalPageId]);
        $globalVersion = $gp['updated_at'] ?? '';
    }

    $ctx = [
        'csrf' => $_SESSION['outpost_csrf'] ?? '',
        'apiUrl' => '/outpost/api.php',
        'pageId' => $_outpost_page_id,
        'pageVersion' => $pageVersion,
        'globalPageId' => (int) $globalPageId,
        'globalVersion' => $globalVersion,
        'imageFields' => $_outpost_image_fields ?: [],
    ];

    // Collection item context for single pages
    if ($_outpost_current_item && $_outpost_current_collection) {
        $ctx['itemContext'] = [
            'id' => (int) ($_outpost_current_item['id'] ?? 0),
            'collection' => $_outpost_current_collection,
            'updated_at' => $_outpost_current_item['updated_at'] ?? '',
        ];
    }

    $json = json_encode($ctx, JSON_HEX_TAG | JSON_HEX_AMP);
    return '<script>window.__OPE=' . $json . ';</script>';
}

function outpost_clear_cache(?string $page_path = null): void {
    if ($page_path) {
        $file = outpost_cache_path($page_path);
        if (file_exists($file)) unlink($file);
    } else {
        $files = glob(OUTPOST_CACHE_DIR . '*.html');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}

// ── Auto-publish (throttled — runs at most once per 60s) ─
function outpost_maybe_auto_publish(): void {
    if (!defined('OUTPOST_CACHE_DIR')) return;
    $lockFile = OUTPOST_CACHE_DIR . '/cron_last_run.txt';
    $now = time();
    if (file_exists($lockFile) && ($now - (int)@file_get_contents($lockFile)) < 60) {
        return;
    }
    @file_put_contents($lockFile, (string)$now);
    outpost_auto_publish_scheduled();
}

// ── Auto-publish scheduled items ─────────────────────
function outpost_auto_publish_scheduled(): void {
    if (!file_exists(OUTPOST_DB_PATH)) return;

    // Check for scheduled_at column existence
    $db = OutpostDB::connect();
    $cols = $db->query("PRAGMA table_info(collection_items)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('scheduled_at', $colNames)) return;

    $now = date('Y-m-d H:i:s');
    $items = OutpostDB::fetchAll(
        "SELECT id FROM collection_items WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?",
        [$now]
    );

    if (!empty($items)) {
        foreach ($items as $item) {
            OutpostDB::update('collection_items', [
                'status' => 'published',
                'published_at' => $now,
                'updated_at' => $now,
            ], 'id = ?', [$item['id']]);
        }
        // Clear cache since published items changed
        outpost_clear_cache();
    }
}

// ── Member Helpers ───────────────────────────────────────
function cms_is_member(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('outpost_member');
        session_start();
    }
    return isset($_SESSION['outpost_member_id']);
}

function cms_is_paid_member(): bool {
    return cms_is_member() && ($_SESSION['outpost_member_role'] ?? '') === 'paid_member';
}

function cms_require_member(): void {
    if (!cms_is_member()) {
        header('Location: /outpost/member-pages/login.php');
        exit;
    }
}

function cms_require_paid_member(): void {
    cms_require_member();
    if (!cms_is_paid_member()) {
        echo '<p>This content is for paid members only.</p>';
        exit;
    }
}

function cms_current_member(): ?array {
    if (!cms_is_member()) return null;
    return [
        'id' => $_SESSION['outpost_member_id'],
        'username' => $_SESSION['outpost_member_username'] ?? '',
        'role' => $_SESSION['outpost_member_role'] ?? '',
    ];
}

// ── DB Migration — Theme-scoped Fields ───────────────────
/**
 * Migrates the fields table to support theme-scoped content.
 * Adds a `theme` column and changes the UNIQUE constraint to (page_id, theme, field_name).
 * Also creates the page_field_registry table for template scanning.
 * Safe to call on every request — checks if migration is needed first.
 */
function ensure_fields_theme_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db = OutpostDB::connect();

    // Check if theme column already exists
    $cols = $db->query("PRAGMA table_info(fields)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    if (!in_array('theme', $colNames)) {
        // Recreate fields table with theme column + updated UNIQUE constraint
        $db->exec("PRAGMA foreign_keys = OFF");
        $db->exec("BEGIN");
        $db->exec("
            CREATE TABLE fields_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL,
                theme TEXT NOT NULL DEFAULT '',
                field_name TEXT NOT NULL,
                field_type TEXT NOT NULL,
                content TEXT DEFAULT '',
                default_value TEXT DEFAULT '',
                options TEXT DEFAULT '',
                sort_order INTEGER DEFAULT 0,
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE(page_id, theme, field_name),
                FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
            )
        ");
        $db->exec("
            INSERT INTO fields_new (id, page_id, theme, field_name, field_type, content, default_value, options, sort_order, updated_at)
            SELECT id, page_id, '', field_name, field_type, content, default_value, options, sort_order, updated_at FROM fields
        ");
        $db->exec("DROP TABLE fields");
        $db->exec("ALTER TABLE fields_new RENAME TO fields");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_fields_page_theme ON fields(page_id, theme)");
        $db->exec("COMMIT");
        $db->exec("PRAGMA foreign_keys = ON");
    }

    // Create page_field_registry (template schema store)
    $db->exec("CREATE TABLE IF NOT EXISTS page_field_registry (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        theme TEXT NOT NULL,
        path TEXT NOT NULL,
        field_name TEXT NOT NULL,
        field_type TEXT NOT NULL DEFAULT 'text',
        default_value TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        UNIQUE(theme, path, field_name)
    )");
}

function ensure_indexes(): void {
    $db = OutpostDB::connect();
    $db->exec("CREATE INDEX IF NOT EXISTS idx_fields_page_theme ON fields(page_id, theme)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_collection_items_coll_status ON collection_items(collection_id, status)");
}

// ── Template Scanner ─────────────────────────────────────
/**
 * Parses field tags from a template source string.
 * Returns array of [field_name, field_type, default_value, sort_order].
 */
function outpost_scan_theme_fields(string $source): array {
    $fields = [];
    $counter = 0;

    // Strip comments
    $source = preg_replace('/\{#.*?#\}/s', '', $source);

    $type_map = [
        'raw' => 'richtext', 'image' => 'image', 'link' => 'link',
        'color' => 'color', 'number' => 'number', 'date' => 'date',
        'textarea' => 'textarea', 'select' => 'select', 'toggle' => 'toggle',
        'gallery' => 'gallery',
    ];

    // {% for item in gallery.field_name %} — registers a gallery field
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+gallery\.(\w+)\s*%\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => 'gallery', 'default_value' => '[]', 'sort_order' => ++$counter, 'options' => '[]'];
        }
    }

    // {{ field | filter }}Default{{ /field }} — wrapping-tag defaults with filter (must run before inline)
    preg_match_all('/\{\{\s*(\w+)\s*\|\s*(\w+)\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/s', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if ($name === 'meta' || $name === 'collection') continue;
        $type = $type_map[$m[2]] ?? 'text';
        $default = trim($m[3]);
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => $type, 'default_value' => $default, 'sort_order' => ++$counter];
        }
    }

    // {{ field }}Default{{ /field }} — wrapping-tag defaults plain text (must run before inline)
    preg_match_all('/\{\{\s*(\w+)\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/s', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if ($name === 'meta' || $name === 'collection') continue;
        $default = trim($m[2]);
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => 'text', 'default_value' => $default, 'sort_order' => ++$counter];
        }
    }

    // {{ field | filter "default" }} or {{ field | filter }}
    preg_match_all('/\{\{\s*(\w+)\s*\|\s*(\w+)(?:\s+"([^"]*)")?\s*\}\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if ($name === 'meta' || $name === 'collection') continue;
        $type = $type_map[$m[2]] ?? 'text';
        $default = $m[3] ?? '';
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => $type, 'default_value' => $default, 'sort_order' => ++$counter];
        }
    }

    // {{ field "default" }} or {{ field }} (plain text fields)
    preg_match_all('/\{\{\s*(\w+)(?:\s+"([^"]*)")?\s*\}\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if ($name === 'meta' || $name === 'collection') continue;
        $default = $m[2] ?? '';
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => 'text', 'default_value' => $default, 'sort_order' => ++$counter];
        }
    }

    // {% for item in repeater.field_name [key:type,...] %} — registers a repeater field with schema
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+repeater\.(\w+)(?:\s+([\w:,\s]+))?\s*%\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        $schemaStr = isset($m[2]) ? trim($m[2]) : '';
        $schema = [];
        foreach (explode(',', $schemaStr) as $pair) {
            $parts = array_map('trim', explode(':', trim($pair), 2));
            if (count($parts) === 2 && $parts[0] !== '') {
                $schema[$parts[0]] = $parts[1];
            }
        }
        if (!isset($fields[$name])) {
            $fields[$name] = [
                'field_name'    => $name,
                'field_type'    => 'repeater',
                'default_value' => '[]',
                'sort_order'    => ++$counter,
                'options'       => $schema ? json_encode($schema) : '{}',
            ];
        }
    }

    // {% for block in flexible.field_name %} — registers a flexible content field
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+flexible\.(\w+)\s*%\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if (!isset($fields[$name])) {
            $fields[$name] = [
                'field_name'    => $name,
                'field_type'    => 'flexible',
                'default_value' => '[]',
                'sort_order'    => ++$counter,
                'options'       => '{}',
            ];
        }
    }

    // {% for item in relationship.field_name %} — registers a relationship field
    preg_match_all('/\{%\s*for\s+\w+\s+in\s+relationship\.(\w+)\s*%\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if (!isset($fields[$name])) {
            $fields[$name] = [
                'field_name'    => $name,
                'field_type'    => 'relationship',
                'default_value' => '[]',
                'sort_order'    => ++$counter,
                'options'       => '{}',
            ];
        }
    }

    return array_values($fields);
}

/**
 * Scans template source for @global field tags ({{ @field | type }} and {{ @field }}).
 * Returns field definitions for the shared global settings page.
 */
function outpost_scan_global_fields(string $source): array {
    $fields = [];

    // Strip comments
    $source = preg_replace('/\{#.*?#\}/s', '', $source);

    $type_map = [
        'raw' => 'richtext', 'image' => 'image', 'link' => 'link',
        'color' => 'color', 'number' => 'number', 'date' => 'date',
        'textarea' => 'textarea', 'select' => 'select', 'toggle' => 'toggle',
    ];

    // {{ @field | filter }}Default{{ /@field }} — wrapping global with filter (must run before inline)
    preg_match_all('/\{\{\s*@(\w+)\s*\|\s*(\w+)\s*\}\}(.*?)\{\{\s*\/@\1\s*\}\}/s', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        $type = $type_map[$m[2]] ?? 'text';
        $default = trim($m[3]);
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => $type, 'default_value' => $default];
        }
    }

    // {{ @field }}Default{{ /@field }} — wrapping global plain text (must run before inline)
    preg_match_all('/\{\{\s*@(\w+)\s*\}\}(.*?)\{\{\s*\/@\1\s*\}\}/s', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        $default = trim($m[2]);
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => 'text', 'default_value' => $default];
        }
    }

    // {{ @field | filter }} — global with type
    preg_match_all('/\{\{\s*@(\w+)\s*\|\s*(\w+)\s*\}\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        $type = $type_map[$m[2]] ?? 'text';
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => $type, 'default_value' => ''];
        }
    }

    // {{ @field }} — plain global text
    preg_match_all('/\{\{\s*@(\w+)\s*\}\}/', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $name = $m[1];
        if (!isset($fields[$name])) {
            $fields[$name] = ['field_name' => $name, 'field_type' => 'text', 'default_value' => ''];
        }
    }

    return array_values($fields);
}

/**
 * Scans all top-level .html files in a theme and:
 * 1. Creates page stubs in the pages table (if not already present)
 * 2. Creates field stubs — page fields for page-specific content, globals for shared
 * 3. Upserts into page_field_registry for the admin field schema
 *
 * Smart @field routing:
 *   - @field in a partial (nav/footer/head)  → global (shown in every page)
 *   - @field in 2+ page templates             → global (shared)
 *   - @field in exactly 1 page template       → page field for that page (not global)
 *
 * Called on theme activation and on the first frontend request for a theme with no fields yet.
 */
function outpost_scan_theme_templates(string $slug): void {
    if (!file_exists(OUTPOST_DB_PATH)) return;
    ensure_fields_theme_column();

    $themeDir = OUTPOST_THEMES_DIR . $slug . '/';
    if (!is_dir($themeDir)) return;

    $pageFiles = glob($themeDir . '*.html') ?: [];
    if (empty($pageFiles)) return;

    $knownPartials = ['head', 'nav', 'footer', 'header', 'sidebar'];

    // Collect all @fields from every file (partials + page templates) — @prefix = always global
    $allAtFields = []; // name => field def

    $partialDir = $themeDir . 'partials/';
    $partialFiles = is_dir($partialDir) ? (glob($partialDir . '*.html') ?: []) : [];
    foreach ($partialFiles as $file) {
        foreach (outpost_scan_global_fields(file_get_contents($file)) as $g) {
            $allAtFields[$g['field_name']] ??= $g;
        }
    }

    $pageInfos = []; // path => ['id'=>int, 'fields'=>[]]

    foreach ($pageFiles as $file) {
        $filename = basename($file, '.html');
        $content = file_get_contents($file);

        // Root-level partials
        if (in_array($filename, $knownPartials)) {
            foreach (outpost_scan_global_fields($content) as $g) {
                $allAtFields[$g['field_name']] ??= $g;
            }
            continue;
        }

        $path = $filename === 'index' ? '/' : '/' . $filename;

        $page = OutpostDB::fetchOne('SELECT id FROM pages WHERE path = ?', [$path]);
        if ($page) {
            $pageId = (int) $page['id'];
        } else {
            $title = ucwords(str_replace(['/', '-', '_'], ' ', trim($path, '/'))) ?: 'Home';
            $pageId = OutpostDB::insert('pages', ['path' => $path, 'title' => $title]);
        }

        foreach (outpost_scan_global_fields($content) as $g) {
            $allAtFields[$g['field_name']] ??= $g;
        }

        $pageInfos[$path] = ['id' => $pageId, 'fields' => outpost_scan_theme_fields($content)];
    }

    // Insert page fields (regular {{ field }} only)
    foreach ($pageInfos as $path => $info) {
        $pageId = $info['id'];
        foreach ($info['fields'] as $f) {
            $opts = $f['options'] ?? '';
            OutpostDB::query(
                "INSERT OR IGNORE INTO fields (page_id, theme, field_name, field_type, content, default_value, options, sort_order) VALUES (?, ?, ?, ?, '', ?, ?, ?)",
                [$pageId, $slug, $f['field_name'], $f['field_type'], $f['default_value'], $opts, $f['sort_order']]
            );
            OutpostDB::query(
                "INSERT INTO page_field_registry (theme, path, field_name, field_type, default_value, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(theme, path, field_name) DO UPDATE SET
                 field_type = excluded.field_type, default_value = excluded.default_value, sort_order = excluded.sort_order",
                [$slug, $path, $f['field_name'], $f['field_type'], $f['default_value'], $f['sort_order']]
            );
        }
    }

    // Insert all @fields into globals; remove any that were mistakenly stored as page fields
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalPage) {
        $globalPageId = OutpostDB::insert('pages', ['path' => '__global__', 'title' => 'Global Fields']);
    } else {
        $globalPageId = (int) $globalPage['id'];
    }

    $gOrder = 0;
    foreach ($allAtFields as $name => $g) {
        // If this field has content at a page-level, migrate it to globals before deleting.
        $migrateRow = OutpostDB::fetchOne(
            "SELECT content FROM fields WHERE field_name = ? AND page_id != ? AND content != '' ORDER BY updated_at DESC LIMIT 1",
            [$name, $globalPageId]
        );
        $migrateContent = $migrateRow ? $migrateRow['content'] : '';

        OutpostDB::query(
            "INSERT INTO fields (page_id, theme, field_name, field_type, content, default_value, sort_order)
             VALUES (?, '', ?, ?, ?, ?, ?)
             ON CONFLICT(page_id, theme, field_name) DO UPDATE SET
               field_type    = excluded.field_type,
               content       = CASE WHEN content = '' AND excluded.content != '' THEN excluded.content ELSE content END,
               default_value = excluded.default_value,
               sort_order    = excluded.sort_order",
            [$globalPageId, $name, $g['field_type'], $migrateContent, $g['default_value'] ?? '', ++$gOrder]
        );
        // Register global field in page_field_registry
        OutpostDB::query(
            "INSERT INTO page_field_registry (theme, path, field_name, field_type, default_value, sort_order)
             VALUES (?, '__global__', ?, ?, ?, ?)
             ON CONFLICT(theme, path, field_name) DO UPDATE SET
             field_type = excluded.field_type, default_value = excluded.default_value, sort_order = excluded.sort_order",
            [$slug, $name, $g['field_type'], $g['default_value'] ?? '', $gOrder]
        );
        // Ensure @fields are never stored as page-scoped fields
        OutpostDB::query(
            "DELETE FROM fields WHERE field_name = ? AND page_id != ?",
            [$name, $globalPageId]
        );
    }

    // ── Cleanup stale fields ─────────────────────────────────

    // Phase 1: Prune stale page_field_registry entries (page fields)
    foreach ($pageInfos as $path => $info) {
        $validNames = array_column($info['fields'], 'field_name');
        if (empty($validNames)) {
            // No fields in template — remove all registry entries for this path
            OutpostDB::query(
                "DELETE FROM page_field_registry WHERE theme = ? AND path = ?",
                [$slug, $path]
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($validNames), '?'));
            OutpostDB::query(
                "DELETE FROM page_field_registry WHERE theme = ? AND path = ? AND field_name NOT IN ($placeholders)",
                array_merge([$slug, $path], $validNames)
            );
        }
    }

    // Phase 1b: Prune stale page_field_registry entries (globals)
    $globalNames = array_keys($allAtFields);
    if (empty($globalNames)) {
        OutpostDB::query(
            "DELETE FROM page_field_registry WHERE theme = ? AND path = '__global__'",
            [$slug]
        );
    } else {
        $placeholders = implode(',', array_fill(0, count($globalNames), '?'));
        OutpostDB::query(
            "DELETE FROM page_field_registry WHERE theme = ? AND path = '__global__' AND field_name NOT IN ($placeholders)",
            array_merge([$slug], $globalNames)
        );
    }

    // Phase 2: Prune stale empty field stubs (page fields)
    foreach ($pageInfos as $path => $info) {
        $validNames = array_column($info['fields'], 'field_name');
        if (empty($validNames)) {
            OutpostDB::query(
                "DELETE FROM fields WHERE page_id = ? AND theme = ? AND (content = '' OR content = '[]')",
                [$info['id'], $slug]
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($validNames), '?'));
            OutpostDB::query(
                "DELETE FROM fields WHERE page_id = ? AND theme = ? AND field_name NOT IN ($placeholders) AND (content = '' OR content = '[]')",
                array_merge([$info['id'], $slug], $validNames)
            );
        }
    }

    // Phase 3: Prune stale empty field stubs (globals)
    if (empty($globalNames)) {
        OutpostDB::query(
            "DELETE FROM fields WHERE page_id = ? AND theme = '' AND (content = '' OR content = '[]')",
            [$globalPageId]
        );
    } else {
        $placeholders = implode(',', array_fill(0, count($globalNames), '?'));
        OutpostDB::query(
            "DELETE FROM fields WHERE page_id = ? AND theme = '' AND field_name NOT IN ($placeholders) AND (content = '' OR content = '[]')",
            array_merge([$globalPageId], $globalNames)
        );
    }

    // Record scan timestamp so we can detect future template changes
    OutpostDB::query(
        "INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        ['last_template_scan_' . $slug, (string) time()]
    );
}

// ── Admin detection ──────────────────────────────────────
/**
 * Returns true if the current visitor is a logged-in Outpost admin.
 * Used by the template engine for {% if admin %} blocks.
 */
function outpost_is_admin(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(OUTPOST_SESSION_NAME);
        session_set_cookie_params(['lifetime' => OUTPOST_SESSION_LIFETIME, 'path' => '/']);
        session_start();
    }
    return isset($_SESSION['outpost_user_id']) &&
           (time() - ($_SESSION['outpost_login_time'] ?? 0)) < OUTPOST_SESSION_LIFETIME;
}

// ── Auto-init ────────────────────────────────────────────
outpost_init();
