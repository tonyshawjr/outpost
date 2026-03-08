<?php
/**
 * Outpost CMS — Theme Management
 * List, activate, duplicate, and delete themes.
 * Themes are directories inside OUTPOST_THEMES_DIR, each with a theme.json manifest.
 */

require_once __DIR__ . '/config.php';

/**
 * Get the active theme slug from settings.
 */
function get_active_theme(): string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
    return $row ? $row['value'] : 'starter';
}

/**
 * Read a theme's manifest (theme.json).
 */
function read_theme_manifest(string $slug): ?array {
    $file = OUTPOST_THEMES_DIR . $slug . '/theme.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;
    return $data;
}

/**
 * GET themes — List all themes
 */
function handle_themes_list(): void {
    outpost_require_cap('settings.*');

    if (!is_dir(OUTPOST_THEMES_DIR)) {
        json_response(['themes' => [], 'active' => '']);
        return;
    }

    $active = get_active_theme();
    $themes = [];

    $dirs = scandir(OUTPOST_THEMES_DIR);
    foreach ($dirs as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = OUTPOST_THEMES_DIR . $entry;
        if (!is_dir($path)) continue;

        $manifest = read_theme_manifest($entry);
        $themes[] = [
            'slug' => $entry,
            'name' => $manifest['name'] ?? ucfirst($entry),
            'version' => $manifest['version'] ?? '0.0.0',
            'author' => $manifest['author'] ?? '',
            'description' => $manifest['description'] ?? '',
            'screenshot' => $manifest['screenshot'] ?? '',
            'managed' => !empty($manifest['managed']),
            'active' => ($entry === $active),
        ];
    }

    // Sort: active theme first, then alphabetical
    usort($themes, function ($a, $b) {
        if ($a['active'] && !$b['active']) return -1;
        if (!$a['active'] && $b['active']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });

    json_response(['themes' => $themes, 'active' => $active]);
}

/**
 * PUT themes/activate — Set active theme
 * Body: { slug }
 */
function handle_theme_activate(): void {
    outpost_require_cap('settings.*');

    $data = get_json_body();
    $slug = trim($data['slug'] ?? '');
    if (!$slug) json_error('slug required');

    $path = OUTPOST_THEMES_DIR . $slug;
    if (!is_dir($path)) json_error('Theme not found', 404);

    OutpostDB::query(
        "INSERT OR REPLACE INTO settings (key, value) VALUES ('active_theme', ?)",
        [$slug]
    );

    // Clear all caches — templates and pages
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    // Clear template cache too
    $templateCache = OUTPOST_CACHE_DIR . 'templates/';
    if (is_dir($templateCache)) {
        $files = glob($templateCache . '*.php');
        if ($files) {
            foreach ($files as $f) unlink($f);
        }
    }

    // Run DB migration (idempotent) then scan this theme's templates to
    // create page/field stubs and populate the page_field_registry.
    ensure_fields_theme_column();
    outpost_scan_theme_templates($slug);

    // Ensure a 'main' menu slot exists for the personal theme (empty — user fills it in Outpost)
    if ($slug === 'personal') {
        ensure_menus_table();
        $existing = OutpostDB::fetchOne('SELECT id FROM menus WHERE slug = ?', ['main']);
        if (!$existing) {
            OutpostDB::insert('menus', [
                'name'  => 'Main Menu',
                'slug'  => 'main',
                'items' => '[]',
            ]);
        }
    }

    json_response(['success' => true, 'active' => $slug]);
}

/**
 * POST themes/duplicate — Duplicate a theme
 * Body: { source, name }
 */
function handle_theme_duplicate(): void {
    outpost_require_cap('settings.*');

    $data = get_json_body();
    $source = trim($data['source'] ?? '');
    $name = trim($data['name'] ?? '');

    if (!$source || !$name) json_error('source and name required');

    $sourcePath = OUTPOST_THEMES_DIR . $source;
    if (!is_dir($sourcePath)) json_error('Source theme not found', 404);

    // Generate slug from name
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    $slug = preg_replace('/-+/', '-', trim($slug, '-'));
    if (!$slug) json_error('Invalid theme name');

    $destPath = OUTPOST_THEMES_DIR . $slug;
    if (is_dir($destPath)) json_error('A theme with this slug already exists');

    // Recursive copy
    theme_copy_dir($sourcePath, $destPath);

    // Update manifest in the copy
    $manifestFile = $destPath . '/theme.json';
    if (file_exists($manifestFile)) {
        $manifest = json_decode(file_get_contents($manifestFile), true) ?: [];
        $manifest['name'] = $name;
        $manifest['version'] = '0.0.1';
        unset($manifest['managed']);
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    json_response(['success' => true, 'slug' => $slug], 201);
}

/**
 * DELETE themes&slug=my-theme — Delete a theme
 */
function handle_theme_delete(): void {
    outpost_require_cap('settings.*');

    $slug = $_GET['slug'] ?? '';
    if (!$slug) json_error('slug required');

    // Prevent deleting active theme
    if ($slug === get_active_theme()) {
        json_error('Cannot delete the active theme');
    }

    $path = OUTPOST_THEMES_DIR . $slug;
    if (!is_dir($path)) json_error('Theme not found', 404);

    // Validate path is inside themes dir
    $real = realpath($path);
    if (!$real || !str_starts_with($real, rtrim(realpath(OUTPOST_THEMES_DIR), '/'))) {
        json_error('Invalid theme path', 403);
    }

    theme_delete_dir($real);

    json_response(['success' => true]);
}

/**
 * Recursively copy a directory.
 */
function theme_copy_dir(string $src, string $dst): void {
    mkdir($dst, 0755, true);
    $entries = scandir($src);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $srcPath = $src . '/' . $entry;
        $dstPath = $dst . '/' . $entry;
        if (is_dir($srcPath)) {
            theme_copy_dir($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

/**
 * Recursively delete a directory.
 */
function theme_delete_dir(string $dir): void {
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            theme_delete_dir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
