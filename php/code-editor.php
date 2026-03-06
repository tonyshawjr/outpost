<?php
/**
 * Outpost CMS — Code Editor (scoped to themes/)
 */

require_once __DIR__ . '/config.php';

/**
 * Validate that a path is safely inside OUTPOST_THEMES_DIR.
 * Returns the resolved real path or exits with 403.
 */
function code_validate_path(string $relative): string {
    // Reject path traversal attempts
    if (str_contains($relative, '..')) {
        json_error('Invalid path', 403);
    }

    $full = OUTPOST_THEMES_DIR . ltrim($relative, '/');
    $real = realpath($full);

    // For write operations the file may not exist yet — validate parent
    if ($real === false) {
        $parent = realpath(dirname($full));
        if ($parent === false || !str_starts_with($parent, rtrim(realpath(OUTPOST_THEMES_DIR), '/'))) {
            json_error('Path outside themes directory', 403);
        }
        return $full;
    }

    if (!str_starts_with($real, rtrim(realpath(OUTPOST_THEMES_DIR), '/'))) {
        json_error('Path outside themes directory', 403);
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
    return in_array($name, ['node_modules', '.git', '.svn', 'vendor', '__pycache__'], true);
}

/**
 * GET code/files — Recursive file tree inside themes/
 */
function handle_code_files(): void {
    outpost_require_cap('code.*');

    if (!is_dir(OUTPOST_THEMES_DIR)) {
        json_response(['tree' => []]);
        return;
    }

    $tree = code_build_tree(OUTPOST_THEMES_DIR, '');
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

        $full = $base . '/' . $entry;
        $rel = $prefix ? $prefix . '/' . $entry : $entry;

        if (is_dir($full)) {
            if (code_skip_dir($entry)) continue;
            $dirs[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'directory',
                'children' => code_build_tree($full, $rel),
            ];
        } else {
            if (!code_allowed_extension($entry)) continue;
            $files[] = [
                'name' => $entry,
                'path' => $rel,
                'type' => 'file',
            ];
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
        if (file_put_contents($real, '', LOCK_EX) === false) {
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
 * GET code/search?q=term — Full-text search across theme files
 */
function handle_code_search(): void {
    outpost_require_cap('code.*');

    $q = trim($_GET['q'] ?? '');
    if (!$q || strlen($q) < 2) json_error('Query too short');

    $results = [];
    code_search_dir(OUTPOST_THEMES_DIR, '', $q, $results);

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

    json_response(['globals' => $globals, 'collections' => $collections]);
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

    if (!$path) json_error('Path required');

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
