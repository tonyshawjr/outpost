<?php
/**
 * Outpost CMS — Code Editor (scoped to site root, excludes outpost/)
 */

require_once __DIR__ . '/config.php';

/**
 * Validate that a path is safely inside OUTPOST_SITE_ROOT but NOT inside the outpost/ directory.
 * Returns the resolved real path or exits with 403.
 */
function code_validate_path(string $relative): string {
    // Reject null byte injection
    if (str_contains($relative, "\x00")) {
        json_error('Invalid path', 403);
    }

    // Reject path traversal attempts
    if (str_contains($relative, '..')) {
        json_error('Invalid path', 403);
    }

    // Reject any path that starts with outpost/ — engine files are off-limits
    $normalized = ltrim($relative, '/');
    // Normalize ./segments to prevent bypass (e.g. ./outpost/config.php)
    $normalized = preg_replace('#(^|/)\./#', '$1', $normalized);
    if (str_starts_with($normalized, 'outpost/') || $normalized === 'outpost') {
        json_error('Cannot access outpost engine files', 403);
    }

    $full = OUTPOST_SITE_ROOT . $normalized;
    $real = realpath($full);
    $realSiteRoot = rtrim(realpath(OUTPOST_SITE_ROOT), '/');
    $realOutpost = realpath(OUTPOST_DIR);

    // For write operations the file may not exist yet — validate parent
    if ($real === false) {
        $parent = realpath(dirname($full));
        if ($parent === false || !str_starts_with($parent, $realSiteRoot)) {
            json_error('Path outside site root', 403);
        }
        // Double-check parent is not inside outpost/
        if ($realOutpost && (str_starts_with($parent, rtrim($realOutpost, '/') . '/') || $parent === rtrim($realOutpost, '/'))) {
            json_error('Cannot access outpost engine files', 403);
        }
        return $full;
    }

    if (!str_starts_with($real, $realSiteRoot)) {
        json_error('Path outside site root', 403);
    }

    // Block any resolved path inside the outpost/ directory
    if ($realOutpost && (str_starts_with($real, rtrim($realOutpost, '/') . '/') || $real === rtrim($realOutpost, '/'))) {
        json_error('Cannot access outpost engine files', 403);
    }

    return $real;
}

/**
 * Check if a filename has an allowed extension.
 */
function code_allowed_extension(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, OUTPOST_CODE_EXTENSIONS, true);
}

/**
 * Directories to skip when building the file tree.
 */
function code_skip_dir(string $name): bool {
    return in_array($name, ['outpost', 'node_modules', '.git', '.svn', 'vendor', '__pycache__', '.forge-snapshot'], true);
}

/**
 * GET code/files — Recursive file tree inside site root (excludes outpost/)
 */
function handle_code_files(): void {
    outpost_require_cap('code.*');

    if (!is_dir(OUTPOST_SITE_ROOT)) {
        json_response(['tree' => []]);
        return;
    }

    $tree = code_build_tree(OUTPOST_SITE_ROOT, '');
    json_response(['tree' => $tree]);
}

function code_build_tree(string $base, string $prefix): array {
    $items = [];
    $entries = scandir($base);
    if ($entries === false) return [];

    // Sort: directories first, then files, both alphabetical
    $dirs = [];
    $files = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        // Skip dotfiles and dotdirs (.htaccess, .well-known, .git, etc.)
        if (str_starts_with($entry, '.')) continue;

        $full = $base . '/' . $entry;
        $rel = $prefix ? $prefix . '/' . $entry : $entry;

        if (is_link($full)) continue;

        if (is_dir($full)) {
            if (code_skip_dir($entry)) continue;
            $dirs[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'directory',
                'children' => code_build_tree($full, $rel),
            ];
        } else {
            if (code_allowed_extension($entry)) {
                $files[] = [
                    'name' => $entry,
                    'path' => $rel,
                    'type' => 'file',
                ];
            } elseif (defined('OUTPOST_ASSET_EXTENSIONS') && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), OUTPOST_ASSET_EXTENSIONS, true)) {
                $files[] = [
                    'name' => $entry,
                    'path' => $rel,
                    'type' => 'asset',
                ];
            }
        }
    }

    sort($dirs);
    sort($files);

    return array_merge($dirs, $files);
}

/**
 * GET code/read?path=theme/file.ext — Read a single file
 */
function handle_code_read(): void {
    outpost_require_cap('code.*');

    $path = $_GET['path'] ?? '';
    if (!$path) json_error('Path required');

    if (!code_allowed_extension($path)) {
        json_error('File type not allowed', 403);
    }

    $real = code_validate_path($path);

    if (!is_file($real)) {
        json_error('File not found', 404);
    }

    // Size cap: 1 MB
    if (filesize($real) > 1048576) {
        json_error('File too large (max 1 MB)');
    }

    $content = file_get_contents($real);
    if ($content === false) {
        json_error('Could not read file');
    }

    json_response([
        'path' => $path,
        'content' => $content,
        'size' => filesize($real),
        'modified' => date('Y-m-d H:i:s', filemtime($real)),
    ]);
}

/**
 * POST code/create — Create a file or folder
 * Body: { path, type: 'file'|'folder' }
 */
function handle_code_create(): void {
    outpost_require_cap('code.*');

    $data = get_json_body();
    $path = $data['path'] ?? '';
    $type = $data['type'] ?? 'file';

    if (!$path) json_error('Path required');

    if ($type === 'file' && !code_allowed_extension($path)) {
        json_error('File type not allowed', 403);
    }

    $real = code_validate_path($path);

    if (file_exists($real)) {
        json_error('Already exists', 409);
    }

    if ($type === 'folder') {
        if (!mkdir($real, 0755, true)) {
            json_error('Could not create folder');
        }
    } else {
        $dir = dirname($real);
        if (!is_dir($dir)) {
            json_error('Parent directory does not exist', 404);
        }
        $content = $data['content'] ?? '';
        if (strlen($content) > 1048576) json_error('Content too large (max 1 MB)');
        if (file_put_contents($real, $content, LOCK_EX) === false) {
            json_error('Could not create file');
        }
    }

    json_response(['success' => true, 'path' => $path, 'type' => $type]);
}

/**
 * POST code/rename — Rename or move a file or folder
 * Body: { oldPath, newPath }
 */
function handle_code_rename(): void {
    outpost_require_cap('code.*');

    $data = get_json_body();
    $oldPath = $data['oldPath'] ?? '';
    $newPath = $data['newPath'] ?? '';

    if (!$oldPath || !$newPath) json_error('oldPath and newPath required');

    $oldReal = code_validate_path($oldPath);
    $newReal = code_validate_path($newPath);

    if (!file_exists($oldReal)) {
        json_error('Source not found', 404);
    }

    if (file_exists($newReal)) {
        json_error('Destination already exists', 409);
    }

    if (is_file($oldReal) && !code_allowed_extension(basename($newPath))) {
        json_error('File type not allowed', 403);
    }

    if (!rename($oldReal, $newReal)) {
        json_error('Could not rename');
    }

    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response(['success' => true, 'oldPath' => $oldPath, 'newPath' => $newPath]);
}

/**
 * DELETE code/delete — Delete a file or folder
 * Body: { path }
 */
function handle_code_delete(): void {
    outpost_require_cap('code.*');

    $data = get_json_body();
    $path = $data['path'] ?? '';

    if (!$path) json_error('Path required');

    $real = code_validate_path($path);

    if (!file_exists($real)) {
        json_error('Not found', 404);
    }

    if (is_dir($real)) {
        code_delete_recursive($real);
    } else {
        if (!unlink($real)) {
            json_error('Could not delete file');
        }
    }

    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response(['success' => true, 'path' => $path]);
}

function code_delete_recursive(string $dir): void {
    $entries = scandir($dir);
    if ($entries === false) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . '/' . $entry;
        if (is_dir($full)) {
            code_delete_recursive($full);
        } else {
            unlink($full);
        }
    }
    rmdir($dir);
}

/**
 * GET code/search?q=term — Full-text search across site root files (excludes outpost/)
 */
function handle_code_search(): void {
    outpost_require_cap('code.*');

    $q = trim($_GET['q'] ?? '');
    if (!$q || strlen($q) < 2) json_error('Query too short');

    $results = [];
    code_search_dir(OUTPOST_SITE_ROOT, '', $q, $results);

    json_response(['results' => $results, 'query' => $q]);
}

function code_search_dir(string $base, string $prefix, string $q, array &$results): void {
    $entries = scandir($base);
    if ($entries === false) return;
    if (count($results) >= 200) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $base . '/' . $entry;
        $rel  = $prefix ? $prefix . '/' . $entry : $entry;

        if (is_link($full)) continue;

        if (is_dir($full)) {
            if (code_skip_dir($entry)) continue;
            code_search_dir($full, $rel, $q, $results);
        } elseif (code_allowed_extension($entry)) {
            if (filesize($full) > 1048576) continue;
            $content = file_get_contents($full);
            if ($content === false) continue;
            $lines = explode("\n", $content);
            foreach ($lines as $i => $line) {
                if (stripos($line, $q) !== false) {
                    $results[] = [
                        'path'    => $rel,
                        'line'    => $i + 1,
                        'preview' => mb_substr(trim($line), 0, 150),
                    ];
                    if (count($results) >= 200) return;
                }
            }
        }
    }
}

/**
 * GET code/context — Returns globals, collections for autocomplete
 */
function handle_code_context(): void {
    outpost_require_cap('code.*');

    require_once __DIR__ . '/db.php';

    $globals = [];
    try {
        $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if ($globalPage) {
            $rows = OutpostDB::fetchAll(
                "SELECT field_name AS name, field_type AS type FROM fields WHERE page_id = ? AND theme = '' ORDER BY sort_order ASC",
                [(int) $globalPage['id']]
            );
            $globals = $rows ?: [];
        }
    } catch (\Exception $e) {}

    $collections = [];
    try {
        $rows = OutpostDB::fetchAll('SELECT slug, name, schema FROM collections ORDER BY slug');
        foreach (($rows ?: []) as $row) {
            $schema = json_decode($row['schema'] ?? '[]', true) ?: [];
            $collections[] = [
                'slug'   => $row['slug'],
                'name'   => $row['name'],
                'fields' => $schema,
            ];
        }
    } catch (\Exception $e) {}

    // Forms (for Forge popover)
    $forms = [];
    try {
        $rows = OutpostDB::fetchAll('SELECT id, slug, name FROM forms ORDER BY name');
        $forms = $rows ?: [];
    } catch (\Exception $e) {}

    // Menus (for Forge popover)
    $menus = [];
    try {
        $rows = OutpostDB::fetchAll('SELECT id, slug, name FROM menus ORDER BY name');
        $menus = $rows ?: [];
    } catch (\Exception $e) {}

    // Folders (for Forge popover)
    $folders = [];
    try {
        $rows = OutpostDB::fetchAll('SELECT id, slug, name FROM folders ORDER BY name');
        $folders = $rows ?: [];
    } catch (\Exception $e) {}

    json_response([
        'globals'     => $globals,
        'collections' => $collections,
        'forms'       => $forms,
        'menus'       => $menus,
        'folders'     => $folders,
    ]);
}

/**
 * PUT code/write — Write content to a file
 * Body: { path, content }
 */
function handle_code_write(): void {
    outpost_require_cap('code.*');

    $data = get_json_body();
    $path = $data['path'] ?? '';
    $content = $data['content'] ?? '';

    // Base64 transport: bypasses WAF/ModSecurity that blocks HTML in JSON bodies
    if (($data['encoding'] ?? '') === 'base64') {
        $decoded = base64_decode($content, true);
        if ($decoded === false) json_error('Invalid base64 content', 400);
        $content = $decoded;
    }

    if (!$path) json_error('Path required');
    if (strlen($content) > 1048576) json_error('Content too large (max 1 MB)');

    if (!code_allowed_extension($path)) {
        json_error('File type not allowed', 403);
    }

    $real = code_validate_path($path);

    // Ensure parent directory exists
    $dir = dirname($real);
    if (!is_dir($dir)) {
        json_error('Directory does not exist', 404);
    }

    $bytes = file_put_contents($real, $content, LOCK_EX);
    if ($bytes === false) {
        json_error('Could not write file');
    }

    // Clear page cache after theme file changes
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response([
        'success' => true,
        'path' => $path,
        'size' => $bytes,
    ]);
}

/**
 * POST code/reset — Reset a theme folder from its .forge-snapshot backup.
 * Restores original HTML/CSS files, removes theme.json, and clears partials created by Forge.
 */
function handle_code_reset(): void {
    outpost_require_cap('code.*');

    $data = get_json_body();
    $folder = $data['folder'] ?? '';

    if (!$folder || str_contains($folder, '..') || str_contains($folder, '/')) {
        json_error('Invalid folder name');
    }

    $themeDir = rtrim(OUTPOST_SITE_ROOT, '/') . '/' . $folder;
    $snapshotDir = $themeDir . '/.forge-snapshot';

    if (!is_dir($themeDir) || !is_dir($snapshotDir)) {
        json_error('No snapshot found for this folder');
    }

    // Recursively copy snapshot files back to theme directory
    code_restore_snapshot($snapshotDir, $themeDir);

    // Delete theme.json if it exists
    $themeJson = $themeDir . '/theme.json';
    if (file_exists($themeJson)) {
        unlink($themeJson);
    }

    // Delete partials created by Forge (remove entire directory)
    $partialsDir = $themeDir . '/partials';
    if (is_dir($partialsDir)) {
        $files = scandir($partialsDir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $partialsDir . '/' . $f;
            if (is_file($path)) unlink($path);
        }
        rmdir($partialsDir);
    }

    // Clear template cache
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();

    json_response(['success' => true]);
}

/**
 * Recursively copy files from snapshot back to the target directory.
 */
/**
 * GET code/assets — List all asset files in the site root assets/ directory (including images).
 */
function handle_code_assets(): void {
    outpost_require_cap('code.*');

    $assetsDir = rtrim(OUTPOST_SITE_ROOT, '/') . '/assets';
    if (!is_dir($assetsDir)) {
        json_response(['files' => []]);
        return;
    }

    $allowedExts = ['css','js','json','svg','png','jpg','jpeg','gif','webp','avif','ico','woff','woff2','ttf','eot'];
    $files = [];
    code_scan_assets($assetsDir, '', $allowedExts, $files);
    json_response(['files' => $files]);
}

function code_scan_assets(string $dir, string $prefix, array $allowedExts, array &$result, int $maxFiles = 500, int $maxDepth = 5, int $depth = 0): void {
    if (count($result) >= $maxFiles || $depth >= $maxDepth) return;

    $entries = @scandir($dir);
    if (!$entries) return;

    foreach ($entries as $entry) {
        if (count($result) >= $maxFiles) return;
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
        $full = $dir . '/' . $entry;
        $rel  = $prefix ? $prefix . '/' . $entry : $entry;

        if (is_link($full)) continue;

        if (is_dir($full)) {
            code_scan_assets($full, $rel, $allowedExts, $result, $maxFiles, $maxDepth, $depth + 1);
        } else {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true)) {
                $result[] = ['name' => $entry, 'path' => $rel, 'size' => filesize($full)];
            }
        }
    }
}

function code_restore_snapshot(string $src, string $dst): void {
    $entries = scandir($src);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        $srcPath = $src . '/' . $entry;
        $dstPath = $dst . '/' . $entry;

        // Skip symlinks to prevent symlink-following attacks
        if (is_link($srcPath)) continue;

        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
            code_restore_snapshot($srcPath, $dstPath);
        } else {
            // Only restore files with allowed extensions
            if (!code_allowed_extension($entry)) continue;
            copy($srcPath, $dstPath);
        }
    }
}
