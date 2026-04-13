<?php
/**
 * Outpost CMS — Sync API
 *
 * Authenticated endpoint for Outpost Builder (local dev tool).
 * Handles pull snapshots, site file pushes, and backup management.
 *
 * Auth:    X-Outpost-Key header (64-char hex)
 * Endpoints:
 *   GET  ?action=pull            — full site snapshot (site files + DB)
 *   POST ?action=push            — deploy site files to live server
 *   GET  ?action=backup/list     — list server-side backups
 *   POST ?action=backup/restore  — restore a backup by ID
 */

// Buffer all output so stray warnings/notices don't corrupt the JSON response
ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Sync constants ────────────────────────────────────────
define('SYNC_BACKUPS_DIR',          OUTPOST_BACKUPS_DIR);
define('SYNC_MAX_FILE_SIZE',        2 * 1024 * 1024); // 2 MB
define('SYNC_RATE_LIMIT_ATTEMPTS',  5);
define('SYNC_RATE_LIMIT_WINDOW',    3600);             // 1 hour
define('SYNC_BACKUP_RETENTION_DAYS', 30);
define('SYNC_EXTENSIONS', [
    'html', 'htm', 'css', 'js', 'json',
    'svg', 'txt', 'md', 'yml', 'yaml', 'xml',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico',
    'woff', 'woff2', 'ttf', 'eot', 'otf',
]);

// ── Headers ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// HTTPS enforcement — skip for localhost
$_sync_host  = $_SERVER['HTTP_HOST'] ?? '';
$_sync_local = str_contains($_sync_host, 'localhost')
            || str_contains($_sync_host, '127.0.0.1')
            || str_contains($_sync_host, '::1');

if (!$_sync_local && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    sync_error(403, 'HTTPS required');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Bootstrap tables ─────────────────────────────────────
sync_ensure_tables();

// ── Authentication ────────────────────────────────────────
$_sync_ip  = sync_get_ip();
sync_check_rate_limit($_sync_ip);

$_sync_key_provided = trim($_SERVER['HTTP_X_OUTPOST_KEY'] ?? '');
if ($_sync_key_provided === '') {
    // Don't count as a failed attempt — just missing header
    sync_error(401, 'X-Outpost-Key header required');
}

$_sync_key_stored = sync_get_setting('sync_api_key');
if (empty($_sync_key_stored)) {
    sync_error(401, 'Sync API not configured. Generate an API key in Outpost Settings.');
}

if (!password_verify($_sync_key_provided, $_sync_key_stored)) {
    sync_record_failed_attempt($_sync_ip);
    sync_error(401, 'Invalid API key');
}

// Successful auth — clear any accumulated failed attempts
sync_clear_rate_limit($_sync_ip);

// ── IP Allowlisting (optional) ───────────────────────────
$_sync_allowed_ips = sync_get_setting('sync_allowed_ips');
if (!empty($_sync_allowed_ips)) {
    $allowedList = array_map('trim', explode(',', $_sync_allowed_ips));
    if (!in_array($_sync_ip, $allowedList, true)) {
        sync_error(403, 'IP not in sync allowlist');
    }
}

// ── Route ─────────────────────────────────────────────────
$_sync_action = $_GET['action'] ?? '';
$_sync_method = $_SERVER['REQUEST_METHOD'];

switch ($_sync_action) {
    case 'pull':
        if ($_sync_method !== 'GET') sync_error(405, 'GET required');
        handle_sync_pull();
        break;

    case 'push':
        if ($_sync_method !== 'POST') sync_error(405, 'POST required');
        handle_sync_push();
        break;

    case 'backup/list':
        if ($_sync_method !== 'GET') sync_error(405, 'GET required');
        handle_backup_list();
        break;

    case 'backup/restore':
        if ($_sync_method !== 'POST') sync_error(405, 'POST required');
        handle_backup_restore();
        break;

    default:
        sync_error(404, 'Unknown action');
}


// ═══════════════════════════════════════════════════════════
// HANDLERS
// ═══════════════════════════════════════════════════════════

/**
 * GET ?action=pull
 * Returns full snapshot: site files (base64), upload list, DB snapshot.
 * Walks OUTPOST_SITE_ROOT excluding the outpost/ directory.
 */
function handle_sync_pull(): void {
    $outpost_base = sync_outpost_base_url();

    // Walk site root, excluding outpost/ directory
    $site_files = [];
    $site_root  = OUTPOST_SITE_ROOT;
    if (is_dir($site_root)) {
        $site_files = sync_walk_site_root($site_root);
    }

    // Uploads — paths + URLs (no binary; Electron downloads separately)
    $uploads = [];
    if (is_dir(OUTPOST_UPLOADS_DIR)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                OUTPOST_UPLOADS_DIR,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $rel_path = 'uploads/' . ltrim(
                str_replace(rtrim(OUTPOST_UPLOADS_DIR, '/'), '', $file->getPathname()),
                '/\\'
            );
            $rel_path = str_replace('\\', '/', $rel_path);
            $uploads[] = [
                'path' => $rel_path,
                'url'  => $outpost_base . '/' . $rel_path,
                'size' => $file->getSize(),
            ];
        }
    }

    sync_json([
        'outpost_version' => OUTPOST_VERSION,
        'site_url'        => $outpost_base,
        'pulled_at'       => gmdate('c'),
        'site_files'      => $site_files,
        'uploads'         => $uploads,
        'db_snapshot'     => sync_build_db_snapshot(),
        'db_file'         => file_exists(OUTPOST_DB_PATH) ? base64_encode(file_get_contents(OUTPOST_DB_PATH)) : null,
    ]);
}

/**
 * POST ?action=push
 * Receives site files, auto-backs up, applies, clears cache.
 * Body: { "files": { "index.html": "<base64>", "css/style.css": "<base64>" }, "push_content": false }
 * Files are relative to site root. Targeting outpost/ is rejected.
 */
function handle_sync_push(): void {
    $body = sync_parse_body();

    if (empty($body['files']) || !is_array($body['files'])) {
        sync_error(400, 'files must be a non-empty object');
    }

    $push_content = !empty($body['push_content']);
    $site_root    = OUTPOST_SITE_ROOT;

    // Auto-backup — push is blocked if backup fails
    $backup_id = sync_make_backup('site', $site_root);
    if ($backup_id === null) {
        sync_error(500, 'Backup failed — push aborted. Check server write permissions.');
    }

    // Apply files
    $diff = ['added' => [], 'modified' => [], 'skipped' => []];

    foreach ($body['files'] as $rel_path => $encoded) {
        // Validate path stays within site root and outside outpost/
        $validated_rel = sync_validate_file_path($rel_path);
        if ($validated_rel === null) {
            $diff['skipped'][] = ['path' => $rel_path, 'reason' => 'invalid path'];
            continue;
        }

        // Extension whitelist
        $ext = strtolower(pathinfo($rel_path, PATHINFO_EXTENSION));
        if (!in_array($ext, SYNC_EXTENSIONS, true)) {
            $diff['skipped'][] = ['path' => $rel_path, 'reason' => 'extension not allowed'];
            continue;
        }

        // Decode
        $content = base64_decode($encoded, true);
        if ($content === false) {
            $diff['skipped'][] = ['path' => $rel_path, 'reason' => 'invalid base64'];
            continue;
        }

        // Size cap
        if (strlen($content) > SYNC_MAX_FILE_SIZE) {
            $diff['skipped'][] = ['path' => $rel_path, 'reason' => 'file exceeds 2 MB'];
            continue;
        }

        // Full disk path
        $full_path = $site_root . $validated_rel;

        // Track diff type
        if (file_exists($full_path)) {
            if (file_get_contents($full_path) !== $content) {
                $diff['modified'][] = $rel_path;
            }
            // unchanged — still write (idempotent)
        } else {
            $diff['added'][] = $rel_path;
        }

        // Ensure parent directory exists
        $dir = dirname($full_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($full_path, $content, LOCK_EX);
    }

    // Optional content push — db_file (binary) takes priority over legacy db_snapshot
    if ($push_content) {
        if (!empty($body['db_file'])) {
            // Full database replace — local is truth
            $db_data = base64_decode($body['db_file'], true);
            if ($db_data !== false && strlen($db_data) > 0) {
                file_put_contents(OUTPOST_DB_PATH, $db_data, LOCK_EX);
                // Re-open the DB connection with the new file
                OutpostDB::reconnect();
            }
        } elseif (!empty($body['db_snapshot'])) {
            sync_apply_content($body['db_snapshot']);
        }
    }

    // Clear page + template cache
    sync_clear_cache();

    sync_json([
        'success'              => true,
        'backup_id'            => $backup_id,
        'diff'                 => $diff,
        'push_content_applied' => $push_content,
    ]);
}

/**
 * GET ?action=backup/list
 * Returns all server-side backups, newest first.
 */
function handle_backup_list(): void {
    sync_prune_old_backups();

    $backups = [];

    if (!is_dir(SYNC_BACKUPS_DIR)) {
        sync_json(['backups' => []]);
        return;
    }

    foreach (scandir(SYNC_BACKUPS_DIR, SCANDIR_SORT_DESCENDING) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir = SYNC_BACKUPS_DIR . $entry . '/';
        if (!is_dir($dir)) continue;

        $manifest_path = $dir . 'manifest.json';
        if (!file_exists($manifest_path)) continue;

        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (!is_array($manifest)) continue;

        // Compute total size
        $size = 0;
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if ($f->isFile()) $size += $f->getSize();
            }
        } catch (Exception $e) {
            // Skip unreadable backup
        }

        $backups[] = [
            'id'         => $entry,
            'source'     => $manifest['source'] ?? $manifest['theme'] ?? 'site',
            'created_at' => $manifest['created_at'] ?? '',
            'file_count' => $manifest['file_count'] ?? 0,
            'size_bytes' => $size,
        ];
    }

    sync_json(['backups' => $backups]);
}

/**
 * POST ?action=backup/restore
 * Restores a backup by ID. Takes a new backup of current state first.
 * Body: { "backup_id": "2026-03-02T14-00-00-a1b2c3d4" }
 */
function handle_backup_restore(): void {
    $body      = sync_parse_body();
    $backup_id = trim($body['backup_id'] ?? '');

    if ($backup_id === '') {
        sync_error(400, 'backup_id required');
    }

    // Prevent traversal
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $backup_id)) {
        sync_error(400, 'Invalid backup_id');
    }

    $backup_dir = SYNC_BACKUPS_DIR . $backup_id . '/';
    if (!is_dir($backup_dir)) {
        sync_error(404, 'Backup not found');
    }

    $manifest_path = $backup_dir . 'manifest.json';
    if (!file_exists($manifest_path)) {
        sync_error(500, 'Backup manifest missing');
    }

    $manifest   = json_decode(file_get_contents($manifest_path), true);
    $source     = $manifest['source'] ?? $manifest['theme'] ?? 'site';
    $site_root  = OUTPOST_SITE_ROOT;

    // Snapshot current state before restoring
    sync_make_backup('site', $site_root);

    // Restore backup files
    $files_dir = $backup_dir . 'files/';
    if (!is_dir($files_dir)) {
        sync_error(500, 'Backup files directory missing');
    }

    $files_real = realpath($files_dir);
    $restored   = 0;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($files_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;

        $file_real = realpath($file->getPathname());
        if ($file_real === false || !str_starts_with($file_real, $files_real)) continue;

        $rel  = ltrim(substr($file_real, strlen($files_real)), '/\\');
        $rel  = str_replace('\\', '/', $rel);

        // Never restore into outpost/ directory
        if (str_starts_with($rel, 'outpost/') || $rel === 'outpost') continue;

        $dest = $site_root . $rel;
        $dir  = dirname($dest);

        if (!is_dir($dir)) mkdir($dir, 0755, true);
        copy($file->getPathname(), $dest);
        $restored++;
    }

    sync_clear_cache();

    sync_json([
        'success'  => true,
        'restored' => $restored,
        'source'   => $source,
    ]);
}


// ═══════════════════════════════════════════════════════════
// RESPONSE HELPERS
// ═══════════════════════════════════════════════════════════

function sync_error(int $code, string $message): never {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}

function sync_json(array $data): never {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
        exit;
    }
    echo $json;
    exit;
}

function sync_parse_body(): array {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) sync_error(400, 'Invalid JSON body');
    return $data;
}


// ═══════════════════════════════════════════════════════════
// AUTH / RATE LIMITING
// ═══════════════════════════════════════════════════════════

function sync_ensure_tables(): void {
    OutpostDB::query("
        CREATE TABLE IF NOT EXISTS sync_rate_limits (
            ip           TEXT PRIMARY KEY,
            attempts     INTEGER DEFAULT 0,
            window_start INTEGER DEFAULT (strftime('%s','now'))
        )
    ");
}

function sync_get_ip(): string {
    // Only use REMOTE_ADDR for rate limiting. X-Forwarded-For and X-Real-IP
    // are trivially spoofable by clients and allow rate limit bypass.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return 'unknown';
}

function sync_check_rate_limit(string $ip): void {
    $row = OutpostDB::fetchOne(
        "SELECT attempts, window_start FROM sync_rate_limits WHERE ip = ?",
        [$ip]
    );
    if (!$row) return;

    $now          = time();
    $window_start = (int) $row['window_start'];
    $attempts     = (int) $row['attempts'];

    // Window expired — clear and allow
    if ($now - $window_start >= SYNC_RATE_LIMIT_WINDOW) {
        OutpostDB::query("DELETE FROM sync_rate_limits WHERE ip = ?", [$ip]);
        return;
    }

    if ($attempts >= SYNC_RATE_LIMIT_ATTEMPTS) {
        $retry_after = SYNC_RATE_LIMIT_WINDOW - ($now - $window_start);
        header("Retry-After: {$retry_after}");
        sync_error(429, "Too many failed attempts. Retry in {$retry_after}s.");
    }
}

function sync_record_failed_attempt(string $ip): void {
    OutpostDB::query("
        INSERT INTO sync_rate_limits (ip, attempts, window_start)
        VALUES (?, 1, strftime('%s','now'))
        ON CONFLICT(ip) DO UPDATE SET attempts = attempts + 1
    ", [$ip]);
}

function sync_clear_rate_limit(string $ip): void {
    OutpostDB::query("DELETE FROM sync_rate_limits WHERE ip = ?", [$ip]);
}

function sync_get_setting(string $key): ?string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", [$key]);
    return $row['value'] ?? null;
}

function sync_set_setting(string $key, string $value): void {
    OutpostDB::query(
        "INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        [$key, $value]
    );
}


// ═══════════════════════════════════════════════════════════
// FILE UTILITIES
// ═══════════════════════════════════════════════════════════

/**
 * Derive the outpost base URL from the current request.
 * sync-api.php lives in /outpost/, so dirname(SCRIPT_NAME) = /outpost.
 */
function sync_outpost_base_url(): string {
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/outpost/sync-api.php'), '/');
    return $scheme . '://' . $host . $script_dir;
}

/**
 * Walk the site root, returning [ 'rel/path' => 'base64content' ]
 * Excludes the outpost/ directory entirely.
 */
function sync_walk_site_root(string $site_root): array {
    $files     = [];
    $root_real = realpath($site_root);
    if ($root_real === false || !is_dir($root_real)) return $files;

    $root_real = rtrim($root_real, '/');
    $outpost_prefix = $root_real . '/outpost';

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root_real, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;

        $file_real = realpath($file->getPathname());
        if ($file_real === false) continue;

        // Skip anything inside outpost/
        if (str_starts_with($file_real, $outpost_prefix . '/') || $file_real === $outpost_prefix) continue;

        if ($file->getSize() > SYNC_MAX_FILE_SIZE) continue;

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, SYNC_EXTENSIONS, true)) continue;

        $rel_path = ltrim(substr($file_real, strlen($root_real)), '/\\');
        $rel_path = str_replace('\\', '/', $rel_path);

        $files[$rel_path] = base64_encode(file_get_contents($file_real));
    }

    return $files;
}

/**
 * Recursively walk a directory, returning [ 'rel/path' => 'base64content' ]
 * Only includes files with allowed extensions and under the size cap.
 */
function sync_walk_directory(string $abs_dir, string $rel_prefix): array {
    $files    = [];
    $abs_real = realpath($abs_dir);
    if ($abs_real === false || !is_dir($abs_real)) return $files;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs_real, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        if ($file->getSize() > SYNC_MAX_FILE_SIZE) continue;

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, SYNC_EXTENSIONS, true)) continue;

        $file_real = realpath($file->getPathname());
        if ($file_real === false) continue;

        $rel_path = $rel_prefix . ltrim(substr($file_real, strlen($abs_real)), '/\\');
        $rel_path = str_replace('\\', '/', $rel_path);

        $files[$rel_path] = base64_encode(file_get_contents($file_real));
    }

    return $files;
}

/**
 * Validate an incoming push file path.
 * Returns the sanitized path relative to OUTPOST_SITE_ROOT,
 * or null if the path is invalid / attempts traversal / targets outpost/.
 */
function sync_validate_file_path(string $rel_path): ?string {
    // Null byte injection
    if (str_contains($rel_path, "\x00")) return null;

    // Reject any traversal attempt
    if (str_contains($rel_path, '..')) return null;

    // Normalize slashes
    $rel_path = str_replace('\\', '/', $rel_path);
    $rel_path = preg_replace('/\/+/', '/', $rel_path);
    $rel_path = trim($rel_path, '/');

    if ($rel_path === '') return null;

    // SECURITY: reject any path targeting the outpost/ directory
    if (str_starts_with($rel_path, 'outpost/') || $rel_path === 'outpost') return null;

    // Manual path normalization (realpath won't work for non-existent files)
    $site_base = rtrim(
        (realpath(OUTPOST_SITE_ROOT) ?: rtrim(OUTPOST_SITE_ROOT, '/')),
        '/'
    );
    $target_raw = $site_base . '/' . $rel_path;

    // Resolve . and .. segments
    $parts    = explode('/', $target_raw);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '..') {
            array_pop($resolved);
        } elseif ($part !== '' && $part !== '.') {
            $resolved[] = $part;
        }
    }
    $normalized = '/' . implode('/', $resolved);

    // Confirmed still inside the site root
    if (!str_starts_with($normalized, $site_base . '/')) return null;

    // Double-check: must not resolve into outpost/
    $result_rel = ltrim(substr($normalized, strlen($site_base)), '/');
    if (str_starts_with($result_rel, 'outpost/') || $result_rel === 'outpost') return null;

    return $rel_path;
}

/**
 * Copy current site files into a timestamped backup directory.
 * Excludes outpost/ directory from backups.
 * Returns the backup ID on success, null on failure.
 */
function sync_make_backup(string $source_name, string $source_dir): ?string {
    if (!is_dir(SYNC_BACKUPS_DIR) && !mkdir(SYNC_BACKUPS_DIR, 0755, true)) {
        return null;
    }

    $backup_id  = gmdate('Y-m-d\TH-i-s') . '-' . bin2hex(random_bytes(4));
    $backup_dir = SYNC_BACKUPS_DIR . $backup_id . '/';
    $files_dir  = $backup_dir . 'files/';

    if (!mkdir($files_dir, 0755, true)) return null;

    $file_count = 0;

    if (is_dir($source_dir)) {
        $source_real = realpath($source_dir);
        if ($source_real) {
            $source_real = rtrim($source_real, '/');
            $outpost_prefix = $source_real . '/outpost';

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source_real, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) continue;
                $file_real = realpath($file->getPathname());
                if (!$file_real) continue;

                // Skip outpost/ directory
                if (str_starts_with($file_real, $outpost_prefix . '/') || $file_real === $outpost_prefix) continue;

                $rel  = ltrim(substr($file_real, strlen($source_real)), '/\\');
                $rel  = str_replace('\\', '/', $rel);
                $dest = $files_dir . $rel;
                $dir  = dirname($dest);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                copy($file_real, $dest);
                $file_count++;
            }
        }
    }

    file_put_contents($backup_dir . 'manifest.json', json_encode([
        'id'         => $backup_id,
        'source'     => $source_name,
        'created_at' => gmdate('c'),
        'file_count' => $file_count,
    ], JSON_UNESCAPED_SLASHES));

    return $backup_id;
}

/**
 * Delete backups older than SYNC_BACKUP_RETENTION_DAYS.
 */
function sync_prune_old_backups(): void {
    if (!is_dir(SYNC_BACKUPS_DIR)) return;
    $cutoff = time() - (SYNC_BACKUP_RETENTION_DAYS * 86400);

    foreach (scandir(SYNC_BACKUPS_DIR) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $dir           = SYNC_BACKUPS_DIR . $entry . '/';
        $manifest_path = $dir . 'manifest.json';
        if (!is_dir($dir) || !file_exists($manifest_path)) continue;

        $manifest   = json_decode(file_get_contents($manifest_path), true);
        $created_at = isset($manifest['created_at']) ? strtotime($manifest['created_at']) : 0;
        if ($created_at && $created_at < $cutoff) {
            sync_rmdir_recursive($dir);
        }
    }
}

function sync_rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Clear page output cache and compiled template cache.
 */
function sync_clear_cache(): void {
    if (!is_dir(OUTPOST_CACHE_DIR)) return;

    foreach (glob(OUTPOST_CACHE_DIR . '*.html') ?: [] as $f) {
        @unlink($f);
    }

    $tpl_cache = OUTPOST_CACHE_DIR . 'templates/';
    if (is_dir($tpl_cache)) {
        foreach (glob($tpl_cache . '*.php') ?: [] as $f) {
            @unlink($f);
        }
    }
}


// ═══════════════════════════════════════════════════════════
// DATABASE SNAPSHOT
// ═══════════════════════════════════════════════════════════

/**
 * Build the DB snapshot included in the pull response.
 * Excludes: sync_api_key and any admin credentials.
 */
function sync_build_db_snapshot(): array {
    // Settings — never expose the sync key
    $excluded = ['sync_api_key'];
    $settings = [];
    foreach (OutpostDB::fetchAll("SELECT key, value FROM settings") as $row) {
        if (!in_array($row['key'], $excluded, true)) {
            $settings[$row['key']] = $row['value'];
        }
    }

    // Pages with nested fields
    $pages = OutpostDB::fetchAll("SELECT * FROM pages ORDER BY path");
    foreach ($pages as &$page) {
        $page['fields'] = OutpostDB::fetchAll(
            "SELECT field_name, field_type, content, default_value, options, sort_order
             FROM fields
             WHERE page_id = ?
             ORDER BY sort_order",
            [$page['id']]
        );
    }
    unset($page);

    // Collections
    $collections = OutpostDB::fetchAll("SELECT * FROM collections ORDER BY name");

    // Published items only
    $items = OutpostDB::fetchAll("
        SELECT ci.*, c.slug AS collection_slug
        FROM collection_items ci
        JOIN collections c ON c.id = ci.collection_id
        WHERE ci.status = 'published'
          AND ci.published_at IS NOT NULL
          AND ci.published_at <= datetime('now')
        ORDER BY ci.collection_id, ci.sort_order
    ");

    // Folders and labels (legacy: taxonomies/terms)
    try {
        $taxonomies = OutpostDB::fetchAll("SELECT * FROM taxonomies ORDER BY collection_id, slug");
    } catch (\Throwable $e) {
        // Fresh installs use 'folders' table instead of 'taxonomies'
        try { $taxonomies = OutpostDB::fetchAll("SELECT * FROM folders ORDER BY collection_id, slug"); } catch (\Throwable $e2) { $taxonomies = []; }
    }
    try {
        $terms = OutpostDB::fetchAll("SELECT * FROM terms ORDER BY taxonomy_id, sort_order");
    } catch (\Throwable $e) {
        // Fresh installs use 'labels' table instead of 'terms'
        try { $terms = OutpostDB::fetchAll("SELECT * FROM labels ORDER BY folder_id, sort_order"); } catch (\Throwable $e2) { $terms = []; }
    }

    return [
        'settings'         => $settings,
        'pages'            => $pages,
        'collections'      => $collections,
        'collection_items' => $items,
        'taxonomies'       => $taxonomies,
        'terms'            => $terms,
    ];
}

/**
 * Apply a content snapshot (push_content: true).
 * Only safe, developer-owned data is touched.
 * Client content (items, users, members) is never overwritten by default.
 */
function sync_apply_content(array $snapshot): void {
    // Settings — safe keys only, never credentials or sync key
    $safe_settings = ['site_name', 'site_url', 'cache_enabled'];
    foreach ($safe_settings as $key) {
        if (isset($snapshot['settings'][$key])) {
            sync_set_setting($key, (string) $snapshot['settings'][$key]);
        }
    }

    // Pages — upsert by path
    if (!empty($snapshot['pages']) && is_array($snapshot['pages'])) {
        foreach ($snapshot['pages'] as $page) {
            OutpostDB::query("
                INSERT INTO pages (path, title, meta_title, meta_description, updated_at)
                VALUES (?, ?, ?, ?, datetime('now'))
                ON CONFLICT(path) DO UPDATE SET
                    title            = excluded.title,
                    meta_title       = excluded.meta_title,
                    meta_description = excluded.meta_description,
                    updated_at       = excluded.updated_at
            ", [
                $page['path']             ?? '',
                $page['title']            ?? '',
                $page['meta_title']       ?? '',
                $page['meta_description'] ?? '',
            ]);

            if (!empty($page['fields'])) {
                $page_row = OutpostDB::fetchOne(
                    "SELECT id FROM pages WHERE path = ?", [$page['path']]
                );
                if ($page_row) {
                    foreach ($page['fields'] as $field) {
                        OutpostDB::query("
                            INSERT INTO fields
                                (page_id, field_name, field_type, content, default_value, options, sort_order, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
                            ON CONFLICT(page_id, field_name) DO UPDATE SET
                                content    = excluded.content,
                                updated_at = excluded.updated_at
                        ", [
                            $page_row['id'],
                            $field['field_name']   ?? '',
                            $field['field_type']   ?? 'text',
                            $field['content']      ?? '',
                            $field['default_value'] ?? '',
                            $field['options']      ?? '',
                            $field['sort_order']   ?? 0,
                        ]);
                    }
                }
            }
        }
    }

    // Collections — upsert by slug (schema and config only, not items)
    if (!empty($snapshot['collections']) && is_array($snapshot['collections'])) {
        foreach ($snapshot['collections'] as $coll) {
            OutpostDB::query("
                INSERT INTO collections
                    (slug, name, singular_name, schema, url_pattern, template_path,
                     sort_field, sort_direction, items_per_page)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(slug) DO UPDATE SET
                    name          = excluded.name,
                    singular_name = excluded.singular_name,
                    schema        = excluded.schema,
                    url_pattern   = excluded.url_pattern,
                    template_path = excluded.template_path
            ", [
                $coll['slug']           ?? '',
                $coll['name']           ?? '',
                $coll['singular_name']  ?? '',
                $coll['schema']         ?? '{}',
                $coll['url_pattern']    ?? '',
                $coll['template_path']  ?? '',
                $coll['sort_field']     ?? 'created_at',
                $coll['sort_direction'] ?? 'DESC',
                $coll['items_per_page'] ?? 10,
            ]);
        }
    }
}
