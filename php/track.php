<?php
/**
 * Outpost CMS — Privacy-First Analytics Tracker
 * Called by the small JS snippet injected into every public page.
 * Logs one row per pageview to analytics_hits.
 *
 * Privacy guarantees:
 *   - Never stores raw IP addresses
 *   - Daily session hash: sha256(ip + ua + date + salt) — unlinkable across days
 *   - Respects Do Not Track header
 *   - Bot traffic flagged and excluded from counts
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Helpers ───────────────────────────────────────────────────

function track_respond_empty(): void {
    http_response_code(204);
    header('Content-Length: 0');
    exit;
}

function track_respond_pixel(): void {
    // 1×1 transparent GIF
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    header('Content-Type: image/gif');
    header('Content-Length: ' . strlen($gif));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo $gif;
    exit;
}

function get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function detect_device(string $ua): string {
    $ua = strtolower($ua);
    if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) return 'tablet';
    if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) return 'mobile';
    return 'desktop';
}

function is_bot(string $ua): bool {
    $patterns = [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners',
        'facebookexternalhit', 'twitterbot', 'linkedinbot',
        'whatsapp', 'skype', 'slack', 'discord', 'telegram',
        'googlebot', 'bingbot', 'yandex', 'baidu', 'duckduck',
        'semrush', 'ahrefs', 'moz.com', 'pingdom', 'uptimerobot',
        'curl/', 'wget/', 'python-requests', 'go-http-client',
        'okhttp', 'apache-httpclient', 'java/', 'ruby',
    ];
    $ua = strtolower($ua);
    foreach ($patterns as $p) {
        if (str_contains($ua, $p)) return true;
    }
    return false;
}

function extract_referrer_domain(string $ref): string {
    if (!$ref) return '';
    $host = parse_url($ref, PHP_URL_HOST) ?: '';
    // Strip www.
    $host = preg_replace('/^www\./', '', $host);
    return strtolower($host);
}

function get_analytics_salt(): string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'analytics_salt'");
    if ($row) return $row['value'];
    // Generate salt on first use
    $salt = bin2hex(random_bytes(16));
    OutpostDB::insert('settings', ['key' => 'analytics_salt', 'value' => $salt]);
    return $salt;
}

function ensure_analytics_tables(): void {
    OutpostDB::query('CREATE TABLE IF NOT EXISTS analytics_hits (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        path           TEXT NOT NULL,
        referrer       TEXT,
        referrer_domain TEXT,
        user_agent     TEXT,
        device_type    TEXT,
        session_id     TEXT,
        is_bot         INTEGER DEFAULT 0,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_hits_path    ON analytics_hits(path)');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_hits_created ON analytics_hits(created_at)');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_hits_session ON analytics_hits(session_id)');

    // Geo enrichment column (v2.3)
    $db = OutpostDB::connect();
    $cols = array_column($db->query("PRAGMA table_info(analytics_hits)")->fetchAll(), 'name');
    if (!in_array('country_code', $cols)) {
        $db->exec("ALTER TABLE analytics_hits ADD COLUMN country_code TEXT DEFAULT NULL");
    }

    // Rate-limit table for tracker (separate from sync rate limits)
    OutpostDB::query('CREATE TABLE IF NOT EXISTS tracker_rate_limits (
        ip_hash    TEXT NOT NULL,
        hit_count  INTEGER DEFAULT 1,
        window_start DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ip_hash)
    )');
}

function ensure_analytics_searches_table(): void {
    OutpostDB::query('CREATE TABLE IF NOT EXISTS analytics_searches (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        query          TEXT NOT NULL,
        results_count  INTEGER DEFAULT 0,
        clicked_path   TEXT DEFAULT NULL,
        session_id     TEXT,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_searches_query   ON analytics_searches(query)');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_searches_created ON analytics_searches(created_at)');
}

function ensure_analytics_events_table(): void {
    OutpostDB::query('CREATE TABLE IF NOT EXISTS analytics_events (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        properties  TEXT DEFAULT NULL,
        path        TEXT NOT NULL,
        session_id  TEXT NOT NULL,
        is_bot      INTEGER DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_events_name    ON analytics_events(name)');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_events_created ON analytics_events(created_at)');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_events_session ON analytics_events(session_id)');
}

function check_rate_limit(string $ip): bool {
    $ip_hash = hash('sha256', $ip);
    $window = 60; // seconds
    $max    = 15; // hits per window (pageviews + events)

    $row = OutpostDB::fetchOne(
        "SELECT hit_count, window_start FROM tracker_rate_limits WHERE ip_hash = ?",
        [$ip_hash]
    );

    $now = time();

    if (!$row) {
        OutpostDB::query(
            "INSERT INTO tracker_rate_limits (ip_hash, hit_count, window_start) VALUES (?, 1, datetime('now'))",
            [$ip_hash]
        );
        return true; // allow
    }

    $window_start = strtotime($row['window_start']);
    $elapsed = $now - $window_start;

    if ($elapsed > $window) {
        // Reset window
        OutpostDB::query(
            "UPDATE tracker_rate_limits SET hit_count = 1, window_start = datetime('now') WHERE ip_hash = ?",
            [$ip_hash]
        );
        return true;
    }

    if ((int)$row['hit_count'] >= $max) {
        return false; // rate-limited
    }

    OutpostDB::query(
        "UPDATE tracker_rate_limits SET hit_count = hit_count + 1 WHERE ip_hash = ?",
        [$ip_hash]
    );
    return true;
}

// ── Main (only runs when called directly, not when included) ──

// Guard: skip main logic when included by api.php for table helpers only.
// Use REQUEST_URI (not SCRIPT_FILENAME — unreliable with PHP built-in server router).
$_track_req_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if (!str_ends_with($_track_req_path, 'track.php')) {
    return;
}

// Accept GET (fetch fallback) and POST (sendBeacon sends POST)
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    track_respond_empty();
}

// Setup tables
ensure_analytics_tables();

$ip = get_ip();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ── Event tracking ────────────────────────────────────────────
$type = trim($_GET['type'] ?? 'pageview');

if ($type === 'event') {
    ensure_analytics_events_table();

    $event_name = trim($_GET['name'] ?? '');
    $event_path = trim($_GET['path'] ?? '/');
    $props_raw  = trim($_GET['props'] ?? '');

    // Validate
    if (!$event_name || strlen($event_name) > 100) track_respond_empty();
    if (!str_starts_with($event_path, '/')) $event_path = '/' . $event_path;
    if (strlen($event_path) > 500) track_respond_empty();

    // Rate limit
    if (!check_rate_limit($ip)) track_respond_pixel();

    // Validate and cap properties JSON
    $properties = null;
    if ($props_raw && strlen($props_raw) <= 2048) {
        $decoded = json_decode($props_raw, true);
        if (is_array($decoded)) {
            $properties = $props_raw;
        }
    }

    $bot        = is_bot($ua) ? 1 : 0;
    $salt       = get_analytics_salt();
    $session_id = hash('sha256', $ip . $ua . date('Y-m-d') . $salt);

    OutpostDB::insert('analytics_events', [
        'name'       => substr($event_name, 0, 100),
        'properties' => $properties,
        'path'       => $event_path,
        'session_id' => $session_id,
        'is_bot'     => $bot,
    ]);

    track_respond_pixel();
}

// ── Search tracking ──────────────────────────────────────────
if ($type === 'search') {
    ensure_analytics_searches_table();

    $query       = trim($_GET['query'] ?? '');
    $results     = (int)($_GET['results'] ?? 0);
    $clicked     = trim($_GET['clicked'] ?? '');

    // Validate
    if (!$query || strlen($query) > 200) track_respond_empty();

    // Rate limit
    if (!check_rate_limit($ip)) track_respond_pixel();

    $bot        = is_bot($ua) ? 1 : 0;
    if ($bot) track_respond_pixel(); // Don't track bot searches

    $salt       = get_analytics_salt();
    $session_id = hash('sha256', $ip . $ua . date('Y-m-d') . $salt);

    OutpostDB::insert('analytics_searches', [
        'query'        => substr($query, 0, 200),
        'results_count'=> max(0, $results),
        'clicked_path' => $clicked ? substr($clicked, 0, 500) : null,
        'session_id'   => $session_id,
    ]);

    track_respond_pixel();
}

// ── Pageview tracking (existing) ──────────────────────────────
$path   = trim($_GET['path'] ?? '/');
$ref    = trim($_GET['ref'] ?? '');
$width  = (int)($_GET['w'] ?? 0);

// Validate path
if (!$path || strlen($path) > 500) {
    track_respond_empty();
}
if (!str_starts_with($path, '/')) $path = '/' . $path;

// Rate limit
if (!check_rate_limit($ip)) {
    track_respond_pixel();
}

// Detect bot
$bot      = is_bot($ua) ? 1 : 0;
$device   = detect_device($ua);
$ref_domain = extract_referrer_domain($ref);

// Anonymous session hash — daily, never stores raw IP
$salt       = get_analytics_salt();
$session_id = hash('sha256', $ip . $ua . date('Y-m-d') . $salt);

// Clean referrer — truncate, strip if same domain
$site_host = parse_url('http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: '';
if ($ref_domain && $ref_domain === preg_replace('/^www\./', '', strtolower($site_host))) {
    $ref = '';
    $ref_domain = '';
}
if (strlen($ref) > 500) $ref = substr($ref, 0, 500);

// Geo lookup (optional — requires mmdb file + setting)
$country_code = null;
try {
    $mmdb_path = OUTPOST_DATA_DIR . 'GeoLite2-Country.mmdb';
    if (file_exists($mmdb_path)) {
        $geo_setting = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'geo_enabled'");
        if ($geo_setting && $geo_setting['value'] === '1') {
            require_once __DIR__ . '/mmdb-reader.php';
            $mmdb = mmdb_open($mmdb_path);
            if ($mmdb) {
                $geo = mmdb_lookup($mmdb, $ip);
                if ($geo && !empty($geo['country_code'])) {
                    $country_code = substr($geo['country_code'], 0, 2);
                }
                mmdb_close($mmdb);
            }
        }
    }
} catch (\Throwable $e) {} // Non-critical, silently skip

// Insert hit
OutpostDB::insert('analytics_hits', [
    'path'           => $path,
    'referrer'       => $ref ?: null,
    'referrer_domain'=> $ref_domain ?: null,
    'user_agent'     => strlen($ua) > 300 ? substr($ua, 0, 300) : ($ua ?: null),
    'device_type'    => $device,
    'session_id'     => $session_id,
    'is_bot'         => $bot,
    'country_code'   => $country_code,
]);

// Weekly pruning: delete hits older than 13 months
// Only run occasionally (1% chance per request) to stay lightweight
if (mt_rand(1, 100) === 1) {
    OutpostDB::query(
        "DELETE FROM analytics_hits WHERE created_at < datetime('now', '-13 months')"
    );
    OutpostDB::query(
        "DELETE FROM analytics_events WHERE created_at < datetime('now', '-13 months')"
    );
    // Prune search analytics (table may not exist yet on first run)
    try {
        OutpostDB::query(
            "DELETE FROM analytics_searches WHERE created_at < datetime('now', '-13 months')"
        );
    } catch (\Exception $e) {} // Table not created yet, skip
    OutpostDB::query(
        "DELETE FROM tracker_rate_limits WHERE window_start < datetime('now', '-1 hour')"
    );
}

track_respond_pixel();
