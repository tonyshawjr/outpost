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
 * POST themes/upload — Install a theme from a ZIP file
 */
function handle_theme_upload(): void {
    outpost_require_cap('settings.*');

    if (empty($_FILES['theme'])) {
        json_error('No file uploaded');
    }

    $file = $_FILES['theme'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Upload failed (error ' . $file['error'] . ')');
    }

    // Validate size (50MB max)
    if ($file['size'] > 50 * 1024 * 1024) {
        json_error('File too large (max 50MB)');
    }

    // Validate extension and MIME
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        json_error('Only .zip files are accepted');
    }

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])) {
        json_error('Invalid file type');
    }

    $tmpDir = sys_get_temp_dir() . '/outpost-theme-' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0755, true);

    try {
        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            throw new \RuntimeException('Could not open zip file');
        }

        // Zip-slip prevention
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            if (str_contains($name, '../') || str_contains($name, '/..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \RuntimeException('Zip contains unsafe path');
            }
        }

        $zip->extractTo($tmpDir);
        $zip->close();

        // Find theme root (directory containing theme.json)
        $themeRoot = theme_find_root($tmpDir);
        if (!$themeRoot) {
            throw new \RuntimeException('No theme.json found in zip');
        }

        $manifest = json_decode(file_get_contents($themeRoot . '/theme.json'), true);
        if (!is_array($manifest) || empty($manifest['name'])) {
            throw new \RuntimeException('theme.json must contain a "name" field');
        }

        // Generate slug
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($manifest['name']));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        if (!$slug) throw new \RuntimeException('Invalid theme name');

        $destPath = OUTPOST_THEMES_DIR . $slug;
        if (is_dir($destPath)) {
            throw new \RuntimeException('A theme named "' . $manifest['name'] . '" already exists');
        }

        // Copy theme to themes dir
        theme_copy_dir($themeRoot, $destPath);

        // Remove managed flag from uploaded themes
        $destManifest = $destPath . '/theme.json';
        if (file_exists($destManifest)) {
            $m = json_decode(file_get_contents($destManifest), true) ?: [];
            unset($m['managed']);
            file_put_contents($destManifest, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        json_response(['success' => true, 'slug' => $slug, 'name' => $manifest['name']], 201);
    } catch (\Throwable $e) {
        json_error($e->getMessage());
    } finally {
        // Clean up temp dir
        if (is_dir($tmpDir)) theme_delete_dir($tmpDir);
    }
}

/**
 * POST themes/create — Create a new blank theme
 * Body: { name }
 */
function handle_theme_create(): void {
    outpost_require_cap('settings.*');

    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('name required');

    // Generate slug
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    $slug = preg_replace('/-+/', '-', trim($slug, '-'));
    if (!$slug) json_error('Invalid theme name');

    $destPath = OUTPOST_THEMES_DIR . $slug;
    if (is_dir($destPath)) json_error('A theme with this slug already exists');

    mkdir($destPath, 0755, true);

    // Write minimal theme.json
    $manifest = [
        'name' => $name,
        'version' => '0.0.1',
    ];
    file_put_contents(
        $destPath . '/theme.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    // Write minimal index.html
    $indexHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ meta.title }}</title>
</head>
<body>
<h1>{{ meta.title }}</h1>
<p>Edit this theme in the Code Editor.</p>
</body>
</html>
HTML;
    file_put_contents($destPath . '/index.html', $indexHtml, LOCK_EX);

    json_response(['success' => true, 'slug' => $slug], 201);
}

/**
 * GET themes/export?slug=my-theme — Download a theme as a ZIP file
 */
function handle_theme_export(): void {
    outpost_require_cap('settings.*');

    $slug = $_GET['slug'] ?? '';
    if (!$slug) json_error('slug required');

    $path = OUTPOST_THEMES_DIR . $slug;
    if (!is_dir($path)) json_error('Theme not found', 404);

    // Validate path is inside themes dir
    $real = realpath($path);
    if (!$real || !str_starts_with($real, rtrim(realpath(OUTPOST_THEMES_DIR), '/'))) {
        json_error('Invalid theme path', 403);
    }

    $tmpFile = sys_get_temp_dir() . '/outpost-export-' . $slug . '-' . bin2hex(random_bytes(4)) . '.zip';

    try {
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Could not create zip file');
        }

        // Recursively add theme files
        theme_add_to_zip($zip, $real, $slug);
        $zip->close();

        // Serve the zip
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '.zip"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
    } finally {
        if (file_exists($tmpFile)) unlink($tmpFile);
    }
    exit;
}

/**
 * Find the theme root directory (the one containing theme.json) inside an extracted zip.
 */
function theme_find_root(string $dir): ?string {
    if (file_exists($dir . '/theme.json')) return $dir;

    // Check one level deeper (zip may have a wrapper directory)
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $sub = $dir . '/' . $entry;
        if (is_dir($sub) && file_exists($sub . '/theme.json')) {
            return $sub;
        }
    }
    return null;
}

/**
 * Recursively add a directory to a ZipArchive.
 */
function theme_add_to_zip(\ZipArchive $zip, string $dir, string $prefix): void {
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $fullPath = $dir . '/' . $entry;
        $zipPath = $prefix . '/' . $entry;
        if (is_dir($fullPath)) {
            $zip->addEmptyDir($zipPath);
            theme_add_to_zip($zip, $fullPath, $zipPath);
        } else {
            $zip->addFile($fullPath, $zipPath);
        }
    }
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
