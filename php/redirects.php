<?php
/**
 * Outpost CMS — URL Redirects
 * Stores redirect rules and processes them on every front-end request.
 */

/**
 * Ensure the redirects table exists.
 */
function redirects_ensure_table(): void {
    OutpostDB::query("
        CREATE TABLE IF NOT EXISTS redirects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_url TEXT NOT NULL,
            target_url TEXT NOT NULL,
            type INTEGER DEFAULT 301,
            hits INTEGER DEFAULT 0,
            last_hit_at TEXT,
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            notes TEXT DEFAULT ''
        )
    ");
    // Unique index on source_url (ignore if exists)
    try {
        OutpostDB::query("CREATE UNIQUE INDEX IF NOT EXISTS idx_redirect_source ON redirects(source_url)");
    } catch (\Throwable $e) {
        // Index may already exist
    }
}

/**
 * Check if current path has a redirect rule and execute it if found.
 * Called early in front-router.php before theme routing.
 *
 * @param string $path The current request path (e.g. /old-page)
 */
function redirects_check(string $path): void {
    // Normalize: strip trailing slash (but keep "/" as-is)
    $normalizedPath = ($path !== '/') ? rtrim($path, '/') : '/';

    // Ensure table exists (lightweight, CREATE IF NOT EXISTS)
    try {
        redirects_ensure_table();
    } catch (\Throwable $e) {
        return; // DB not ready yet, skip silently
    }

    // 1. Exact match
    $redirect = OutpostDB::fetchOne(
        "SELECT * FROM redirects WHERE source_url = ? AND active = 1",
        [$normalizedPath]
    );

    if ($redirect) {
        redirects_execute($redirect, $normalizedPath);
        return;
    }

    // 2. Wildcard match: /old-blog/* → /blog/*
    $wildcardRedirects = OutpostDB::fetchAll(
        "SELECT * FROM redirects WHERE source_url LIKE '%*' AND active = 1 AND source_url NOT LIKE '~%'"
    );

    foreach ($wildcardRedirects as $r) {
        $sourcePrefix = rtrim(str_replace('*', '', $r['source_url']), '/');
        if (str_starts_with($normalizedPath, $sourcePrefix . '/') || $normalizedPath === $sourcePrefix) {
            $remainder = substr($normalizedPath, strlen($sourcePrefix));
            $targetBase = rtrim(str_replace('*', '', $r['target_url']), '/');
            $finalTarget = $targetBase . $remainder;
            $r['_resolved_target'] = $finalTarget;
            redirects_execute($r, $normalizedPath);
            return;
        }
    }

    // 3. Regex match: source starts with ~
    $regexRedirects = OutpostDB::fetchAll(
        "SELECT * FROM redirects WHERE source_url LIKE '~%' AND active = 1"
    );

    foreach ($regexRedirects as $r) {
        $pattern = substr($r['source_url'], 1); // strip leading ~
        if (@preg_match($pattern, $normalizedPath, $matches)) {
            // Replace $1, $2, etc. in target URL
            $target = $r['target_url'];
            foreach ($matches as $i => $match) {
                if ($i === 0) continue;
                $target = str_replace('$' . $i, $match, $target);
            }
            $r['_resolved_target'] = $target;
            redirects_execute($r, $normalizedPath);
            return;
        }
    }
}

/**
 * Validate that a redirect target is safe (relative path or same-domain).
 * Blocks open redirects to external domains.
 */
function redirects_validate_target(string $target): string {
    $target = trim($target);

    // Allow relative paths (starting with /)
    if (str_starts_with($target, '/')) {
        // Block protocol-relative URLs (//evil.com)
        if (str_starts_with($target, '//')) {
            return '/';
        }
        return $target;
    }

    // Allow same-domain absolute URLs
    $parsed = parse_url($target);
    if ($parsed && isset($parsed['host'])) {
        $currentHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (strcasecmp($parsed['host'], $currentHost) === 0) {
            return $target;
        }
        // External domain — block it, redirect to homepage
        return '/';
    }

    // No scheme, no leading slash — treat as relative path
    return '/' . ltrim($target, '/');
}

/**
 * Execute a redirect: send the Location header, increment hits, and exit.
 */
function redirects_execute(array $redirect, string $matchedPath): void {
    $target = $redirect['_resolved_target'] ?? $redirect['target_url'];
    $target = redirects_validate_target($target);
    $type = (int)($redirect['type'] ?? 301);

    // Increment hit counter
    try {
        OutpostDB::query(
            "UPDATE redirects SET hits = hits + 1, last_hit_at = datetime('now') WHERE id = ?",
            [$redirect['id']]
        );
    } catch (\Throwable $e) {
        // Non-critical
    }

    http_response_code($type);
    header('Location: ' . $target);
    exit;
}

/**
 * Test a URL against redirect rules without executing.
 * Returns the matched redirect info or null.
 */
function redirects_test(string $path): ?array {
    $normalizedPath = ($path !== '/') ? rtrim($path, '/') : '/';

    // 1. Exact match
    $redirect = OutpostDB::fetchOne(
        "SELECT * FROM redirects WHERE source_url = ? AND active = 1",
        [$normalizedPath]
    );

    if ($redirect) {
        return [
            'matched' => true,
            'type' => 'exact',
            'redirect' => $redirect,
            'resolved_target' => $redirect['target_url'],
        ];
    }

    // 2. Wildcard
    $wildcardRedirects = OutpostDB::fetchAll(
        "SELECT * FROM redirects WHERE source_url LIKE '%*' AND active = 1 AND source_url NOT LIKE '~%'"
    );

    foreach ($wildcardRedirects as $r) {
        $sourcePrefix = rtrim(str_replace('*', '', $r['source_url']), '/');
        if (str_starts_with($normalizedPath, $sourcePrefix . '/') || $normalizedPath === $sourcePrefix) {
            $remainder = substr($normalizedPath, strlen($sourcePrefix));
            $targetBase = rtrim(str_replace('*', '', $r['target_url']), '/');
            return [
                'matched' => true,
                'type' => 'wildcard',
                'redirect' => $r,
                'resolved_target' => $targetBase . $remainder,
            ];
        }
    }

    // 3. Regex
    $regexRedirects = OutpostDB::fetchAll(
        "SELECT * FROM redirects WHERE source_url LIKE '~%' AND active = 1"
    );

    foreach ($regexRedirects as $r) {
        $pattern = substr($r['source_url'], 1);
        if (@preg_match($pattern, $normalizedPath, $matches)) {
            $target = $r['target_url'];
            foreach ($matches as $i => $match) {
                if ($i === 0) continue;
                $target = str_replace('$' . $i, $match, $target);
            }
            return [
                'matched' => true,
                'type' => 'regex',
                'redirect' => $r,
                'resolved_target' => $target,
            ];
        }
    }

    return ['matched' => false];
}

// ── API Handlers ────────────────────────────────────────

function handle_redirects_list(): void {
    redirects_ensure_table();
    $redirects = OutpostDB::fetchAll("SELECT * FROM redirects ORDER BY created_at DESC");
    json_response(['redirects' => $redirects]);
}

function handle_redirect_create(): void {
    redirects_ensure_table();
    $data = get_json_body();

    $source = trim($data['source_url'] ?? '');
    $target = trim($data['target_url'] ?? '');
    $type = (int)($data['type'] ?? 301);
    $notes = trim($data['notes'] ?? '');
    $active = isset($data['active']) ? (int)$data['active'] : 1;

    if (!$source || !$target) {
        json_error('Source URL and target URL are required');
    }

    if (!in_array($type, [301, 302, 307])) {
        json_error('Invalid redirect type. Must be 301, 302, or 307');
    }

    // Check for duplicate source
    $existing = OutpostDB::fetchOne("SELECT id FROM redirects WHERE source_url = ?", [$source]);
    if ($existing) {
        json_error('A redirect for this source URL already exists');
    }

    OutpostDB::query(
        "INSERT INTO redirects (source_url, target_url, type, notes, active) VALUES (?, ?, ?, ?, ?)",
        [$source, $target, $type, $notes, $active]
    );

    $id = OutpostDB::lastInsertId();
    $redirect = OutpostDB::fetchOne("SELECT * FROM redirects WHERE id = ?", [$id]);
    json_response(['redirect' => $redirect]);
}

function handle_redirect_update(): void {
    redirects_ensure_table();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing redirect ID');

    $existing = OutpostDB::fetchOne("SELECT * FROM redirects WHERE id = ?", [$id]);
    if (!$existing) json_error('Redirect not found', 404);

    $data = get_json_body();

    $source = trim($data['source_url'] ?? $existing['source_url']);
    $target = trim($data['target_url'] ?? $existing['target_url']);
    $type = isset($data['type']) ? (int)$data['type'] : (int)$existing['type'];
    $notes = trim($data['notes'] ?? $existing['notes']);
    $active = isset($data['active']) ? (int)$data['active'] : (int)$existing['active'];

    if (!in_array($type, [301, 302, 307])) {
        json_error('Invalid redirect type. Must be 301, 302, or 307');
    }

    // Check for duplicate source (excluding current)
    $dup = OutpostDB::fetchOne("SELECT id FROM redirects WHERE source_url = ? AND id != ?", [$source, $id]);
    if ($dup) {
        json_error('A redirect for this source URL already exists');
    }

    OutpostDB::query(
        "UPDATE redirects SET source_url = ?, target_url = ?, type = ?, notes = ?, active = ? WHERE id = ?",
        [$source, $target, $type, $notes, $active, $id]
    );

    $redirect = OutpostDB::fetchOne("SELECT * FROM redirects WHERE id = ?", [$id]);
    json_response(['redirect' => $redirect]);
}

function handle_redirect_delete(): void {
    redirects_ensure_table();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing redirect ID');

    $existing = OutpostDB::fetchOne("SELECT * FROM redirects WHERE id = ?", [$id]);
    if (!$existing) json_error('Redirect not found', 404);

    OutpostDB::query("DELETE FROM redirects WHERE id = ?", [$id]);
    json_response(['success' => true]);
}

function handle_redirect_test(): void {
    redirects_ensure_table();
    $data = get_json_body();
    $url = trim($data['url'] ?? '');

    if (!$url) json_error('URL is required');

    $result = redirects_test($url);
    json_response($result);
}

function handle_redirect_import(): void {
    redirects_ensure_table();
    $data = get_json_body();
    $csv = trim($data['csv'] ?? '');

    if (!$csv) json_error('CSV data is required');

    $lines = array_filter(array_map('trim', explode("\n", $csv)));
    $imported = 0;
    $skipped = 0;
    $errors = [];

    foreach ($lines as $i => $line) {
        // Skip header row
        if ($i === 0 && (stripos($line, 'source') !== false || stripos($line, 'from') !== false)) {
            continue;
        }

        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            $errors[] = "Line " . ($i + 1) . ": not enough columns";
            $skipped++;
            continue;
        }

        $source = trim($parts[0]);
        $target = trim($parts[1]);
        $type = isset($parts[2]) ? (int)trim($parts[2]) : 301;

        if (!$source || !$target) {
            $errors[] = "Line " . ($i + 1) . ": empty source or target";
            $skipped++;
            continue;
        }

        if (!in_array($type, [301, 302, 307])) $type = 301;

        // Skip duplicates
        $existing = OutpostDB::fetchOne("SELECT id FROM redirects WHERE source_url = ?", [$source]);
        if ($existing) {
            $skipped++;
            continue;
        }

        OutpostDB::query(
            "INSERT INTO redirects (source_url, target_url, type) VALUES (?, ?, ?)",
            [$source, $target, $type]
        );
        $imported++;
    }

    json_response([
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
}
