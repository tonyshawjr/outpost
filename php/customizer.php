<?php
/**
 * Outpost CMS — Theme Customizer
 * Visual customization of colors, fonts, logo, and favicon per theme.
 * Settings stored in content/data/customizer.json (safe from updates, included in backups).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/themes.php';
require_once __DIR__ . '/brand.php';

// ── File I/O ─────────────────────────────────────────────

/**
 * Read the customizer JSON file from disk.
 */
function customizer_read_file(): array {
    $path = OUTPOST_DATA_DIR . 'customizer.json';
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Write the customizer JSON file atomically.
 */
function customizer_write_file(array $data): void {
    $path = OUTPOST_DATA_DIR . 'customizer.json';
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ── Brand Key Resolution ─────────────────────────────────

/**
 * Resolve a dotted brand key (e.g. "colors.accent") to its value.
 * Returns empty string if the path doesn't exist.
 */
function customizer_resolve_brand_key(array $brand, string $brandKey): string {
    $parts = explode('.', $brandKey, 2);
    if (count($parts) !== 2) return '';
    return $brand[$parts[0]][$parts[1]] ?? '';
}

// ── Merged Values (used by engine for CSS injection) ─────

/**
 * Get merged customizer values for a theme.
 * Priority: saved value → brand value → field default.
 * Returns flat map: { "color-accent": "#14B8A6", "font-heading": "Inter", ... }
 */
function customizer_get_merged_values(string $themeSlug): array {
    $manifest = read_theme_manifest($themeSlug);
    $schema = $manifest['customizer'] ?? null;
    if (!$schema || empty($schema['sections'])) return [];

    $saved = customizer_read_file();
    $themeValues = $saved[$themeSlug] ?? [];
    $brand = brand_get_merged();

    $merged = [];
    foreach ($schema['sections'] as $section) {
        $sectionId = $section['id'] ?? '';
        $sectionSaved = $themeValues[$sectionId] ?? [];
        foreach ($section['fields'] ?? [] as $field) {
            $key = $field['key'] ?? '';
            if (!$key) continue;

            // Priority: saved → brand → default
            if (isset($sectionSaved[$key]) && $sectionSaved[$key] !== '') {
                $merged[$key] = $sectionSaved[$key];
            } elseif (!empty($field['brand_key'])) {
                $brandVal = customizer_resolve_brand_key($brand, $field['brand_key']);
                $merged[$key] = $brandVal !== '' ? $brandVal : ($field['default'] ?? '');
            } else {
                $merged[$key] = $field['default'] ?? '';
            }
        }
    }

    return $merged;
}

/**
 * Get the customizer schema for a theme.
 */
function customizer_get_schema(string $themeSlug): ?array {
    $manifest = read_theme_manifest($themeSlug);
    return $manifest['customizer'] ?? null;
}

// ── API Handlers ─────────────────────────────────────────

/**
 * GET customizer — Returns schema + saved values for active theme.
 * Enriches fields with brand_default when a brand_key mapping exists.
 */
function handle_customizer_get(): void {
    outpost_require_cap('settings.*');

    $theme = get_active_theme();
    $schema = customizer_get_schema($theme);
    $saved = customizer_read_file();
    $values = $saved[$theme] ?? [];

    // Enrich schema fields with resolved brand defaults
    if ($schema && !empty($schema['sections'])) {
        $brand = brand_get_merged();
        foreach ($schema['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                if (!empty($field['brand_key'])) {
                    $brandVal = customizer_resolve_brand_key($brand, $field['brand_key']);
                    if ($brandVal !== '') {
                        $field['brand_default'] = $brandVal;
                    }
                }
            }
            unset($field);
        }
        unset($section);
    }

    json_response([
        'schema' => $schema,
        'values' => $values,
        'theme'  => $theme,
    ]);
}

/**
 * PUT customizer — Save customizer values for active theme.
 * Body: { colors: { ... }, fonts: { ... }, identity: { ... }, layout: { ... } }
 */
function handle_customizer_save(): void {
    outpost_require_cap('settings.*');

    $theme = get_active_theme();
    $schema = customizer_get_schema($theme);
    if (!$schema) {
        json_error('This theme does not support customization');
    }

    $body = get_json_body();

    // Validate: only allow known section IDs
    $allowedSections = array_column($schema['sections'], 'id');
    $values = [];
    foreach ($body as $sectionId => $sectionValues) {
        if (!in_array($sectionId, $allowedSections, true)) continue;
        if (!is_array($sectionValues)) continue;

        // Validate: only allow known field keys within each section
        $section = null;
        foreach ($schema['sections'] as $s) {
            if ($s['id'] === $sectionId) { $section = $s; break; }
        }
        if (!$section) continue;

        $allowedKeys = array_column($section['fields'], 'key');
        $cleanValues = [];
        foreach ($sectionValues as $key => $val) {
            if (!in_array($key, $allowedKeys, true)) continue;
            $cleanValues[$key] = is_string($val) ? $val : '';
        }
        if ($cleanValues) {
            $values[$sectionId] = $cleanValues;
        }
    }

    // Read existing file, update this theme's entry
    $all = customizer_read_file();
    $all[$theme] = $values;
    customizer_write_file($all);

    // Sync logo to global field if set
    if (!empty($values['identity']['logo'])) {
        customizer_sync_global('site_logo', $values['identity']['logo']);
    }

    // Clear page cache so new CSS takes effect
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response(['success' => true]);
}

/**
 * POST customizer/reset — Delete all customizer values for active theme.
 */
function handle_customizer_reset(): void {
    outpost_require_cap('settings.*');

    $theme = get_active_theme();
    $all = customizer_read_file();

    if (isset($all[$theme])) {
        unset($all[$theme]);
        customizer_write_file($all);
    }

    // Clear page cache
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response(['success' => true]);
}

/**
 * GET customizer/export — Download preset JSON for active theme.
 */
function handle_customizer_export(): void {
    outpost_require_cap('settings.*');

    $theme = get_active_theme();
    $all = customizer_read_file();
    $values = $all[$theme] ?? [];

    $safeTheme = preg_replace('/[^a-zA-Z0-9_-]/', '', $theme);
    $filename = 'outpost-customizer-' . $safeTheme . '-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode([
        'theme'  => $theme,
        'values' => $values,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * POST customizer/import — Upload and apply preset JSON.
 * Body: { theme: "personal", values: { colors: {...}, ... } }
 */
function handle_customizer_import(): void {
    outpost_require_cap('settings.*');

    $body = get_json_body();
    $importValues = $body['values'] ?? null;
    if (!is_array($importValues)) {
        json_error('Invalid preset format: missing values');
    }

    $theme = get_active_theme();
    $schema = customizer_get_schema($theme);
    if (!$schema) {
        json_error('This theme does not support customization');
    }

    // Validate: only allow known section IDs and field keys
    $allowedSections = array_column($schema['sections'], 'id');
    $values = [];
    foreach ($importValues as $sectionId => $sectionValues) {
        if (!in_array($sectionId, $allowedSections, true)) continue;
        if (!is_array($sectionValues)) continue;

        $section = null;
        foreach ($schema['sections'] as $s) {
            if ($s['id'] === $sectionId) { $section = $s; break; }
        }
        if (!$section) continue;

        $allowedKeys = array_column($section['fields'], 'key');
        $cleanValues = [];
        foreach ($sectionValues as $key => $val) {
            if (!in_array($key, $allowedKeys, true)) continue;
            $cleanValues[$key] = is_string($val) ? $val : '';
        }
        if ($cleanValues) {
            $values[$sectionId] = $cleanValues;
        }
    }

    $all = customizer_read_file();
    $all[$theme] = $values;
    customizer_write_file($all);

    // Clear page cache
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response(['success' => true, 'values' => $values]);
}

// ── Helpers ──────────────────────────────────────────────

/**
 * Sync a customizer value to a global field in the database.
 * Used for logo so {{ @site_logo | image }} works in templates.
 */
function customizer_sync_global(string $fieldName, string $value): void {
    // Find the global page
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalPage) return;

    $pageId = (int)$globalPage['id'];

    // Check if field exists
    $existing = OutpostDB::fetchOne(
        "SELECT id FROM fields WHERE page_id = ? AND field_name = ? AND theme = ''",
        [$pageId, $fieldName]
    );

    if ($existing) {
        OutpostDB::query(
            "UPDATE fields SET field_value = ? WHERE id = ?",
            [$value, $existing['id']]
        );
    } else {
        OutpostDB::insert('fields', [
            'page_id'    => $pageId,
            'field_name' => $fieldName,
            'field_value' => $value,
            'theme'      => '',
        ]);
    }
}
