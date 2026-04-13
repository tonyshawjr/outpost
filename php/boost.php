<?php
/**
 * Outpost CMS — Boost Performance Suite
 *
 * Comprehensive performance optimization: page caching, browser cache headers,
 * GZIP compression, HTML minification, lazy loading, and database optimization.
 *
 * Load early in the request lifecycle (front-router.php).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Default Configuration ────────────────────────────────

define('BOOST_DEFAULT_CONFIG', [
    'enabled'               => true,
    'developer_mode'        => false,
    'page_cache'            => true,
    'page_cache_ttl'        => 3600,
    'page_cache_excludes'   => ['/outpost/*', '/api/*'],
    'browser_cache'         => true,
    'browser_cache_css_ttl' => 31536000,
    'browser_cache_js_ttl'  => 31536000,
    'browser_cache_img_ttl' => 2592000,
    'browser_cache_font_ttl'=> 31536000,
    'compression'           => true,
    'html_minify'           => true,
    'css_minify'            => true,
    'js_minify'             => false,
    'lazy_loading'          => true,
    'lazy_skip_count'       => 2,
    'db_auto_optimize'      => false,
]);

// ── Configuration ────────────────────────────────────────

/**
 * Get the current Boost configuration, merged with defaults.
 */
function boost_get_config(bool $forceReload = false): array {
    static $config = null;
    if ($config !== null && !$forceReload) return $config;

    try {
        $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'boost_config'");
        $stored = $row ? json_decode($row['value'], true) : [];
        if (!is_array($stored)) $stored = [];
    } catch (\Throwable $e) {
        $stored = [];
    }

    $config = array_merge(BOOST_DEFAULT_CONFIG, $stored);
    return $config;
}

/**
 * Save Boost configuration.
 */
function boost_save_config(array $config): void {
    // Validate and sanitize
    $clean = [];
    foreach (BOOST_DEFAULT_CONFIG as $key => $default) {
        if (array_key_exists($key, $config)) {
            if (is_bool($default)) {
                $clean[$key] = (bool) $config[$key];
            } elseif (is_int($default)) {
                $clean[$key] = max(0, (int) $config[$key]);
            } elseif (is_array($default)) {
                $clean[$key] = is_array($config[$key]) ? $config[$key] : $default;
            } else {
                $clean[$key] = $config[$key];
            }
        } else {
            $clean[$key] = $default;
        }
    }

    OutpostDB::query(
        "INSERT INTO settings (key, value) VALUES ('boost_config', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        [json_encode($clean)]
    );

    // Clear the static cache so subsequent calls in this request see new config
    boost_get_config(true);

    // Any config change clears the page cache
    boost_clear_page_cache();
}

// ── Developer Mode Check ─────────────────────────────────

/**
 * Returns true if Boost is disabled or developer mode is on.
 */
function boost_is_bypassed(): bool {
    $config = boost_get_config();
    return !$config['enabled'] || $config['developer_mode'];
}

// ── Page Cache ───────────────────────────────────────────

/**
 * Directory for Boost page cache files.
 */
function boost_cache_dir(): string {
    return OUTPOST_CACHE_DIR . 'boost/';
}

/**
 * Generate a cache file path for the current request.
 */
function boost_cache_path(string $url_path): string {
    $query = $_GET;
    // Remove nocache param if present
    unset($query['nocache']);
    if (!empty($query)) {
        ksort($query);
        $url_path .= '?' . http_build_query($query);
    }
    $hash = md5($url_path);
    return boost_cache_dir() . $hash . '.html';
}

/**
 * Check if the current request should be excluded from caching.
 */
function boost_is_excluded(string $path, array $excludes): bool {
    foreach ($excludes as $pattern) {
        $pattern = trim($pattern);
        if ($pattern === '') continue;

        // Convert glob-style pattern to regex
        $regex = '#^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '#')) . '$#';
        if (preg_match($regex, $path)) {
            return true;
        }
    }
    return false;
}

/**
 * Try to serve a cached page. Returns true if cache hit, false if miss.
 */
function boost_serve_cached_page(): bool {
    $config = boost_get_config();

    // Developer mode or page cache disabled
    if (boost_is_bypassed() || !$config['page_cache']) return false;

    // Never cache POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') return false;

    // Don't cache for logged-in users (session cookie present)
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['outpost_user_id'])) {
        return false;
    }
    // Check for session cookie even if session isn't started
    if (isset($_COOKIE[OUTPOST_SESSION_NAME])) return false;

    // Don't cache nocache requests
    if (isset($_GET['nocache'])) return false;

    // Don't cache preview mode
    if (isset($_GET['preview'])) return false;

    // Check path exclusions
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (boost_is_excluded($path, $config['page_cache_excludes'])) return false;

    $cacheFile = boost_cache_path($path);
    if (!file_exists($cacheFile)) return false;

    // Check TTL
    $age = time() - filemtime($cacheFile);
    if ($age > $config['page_cache_ttl']) {
        @unlink($cacheFile);
        return false;
    }

    // Cache hit — log it
    boost_log_hit(true);

    // Set cache headers
    $lastModified = filemtime($cacheFile);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('X-Boost-Cache: HIT');
    header('X-Boost-Age: ' . $age);

    // Check If-Modified-Since
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $ifModified = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($ifModified >= $lastModified) {
            http_response_code(304);
            return true;
        }
    }

    // Serve compressed if possible
    $content = file_get_contents($cacheFile);
    if ($config['compression'] && boost_can_gzip()) {
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        echo gzencode($content, 6);
    } else {
        echo $content;
    }

    return true;
}

/**
 * Save the current page output to cache.
 */
function boost_cache_page(string $html): void {
    $config = boost_get_config();

    if (boost_is_bypassed() || !$config['page_cache']) return;

    // Don't cache error pages
    $code = http_response_code();
    if ($code >= 400) return;

    // Don't cache POST responses
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;

    // Don't cache for logged-in users
    if (isset($_COOKIE[OUTPOST_SESSION_NAME])) return;

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (boost_is_excluded($path, $config['page_cache_excludes'])) return;

    $dir = boost_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $cacheFile = boost_cache_path($path);
    @file_put_contents($cacheFile, $html, LOCK_EX);
}

/**
 * Clear the Boost page cache.
 */
function boost_clear_page_cache(): void {
    $dir = boost_cache_dir();
    if (!is_dir($dir)) return;
    $files = glob($dir . '*.html');
    if ($files) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * Get page cache statistics.
 */
function boost_cache_stats(): array {
    $dir = boost_cache_dir();
    $entries = 0;
    $totalSize = 0;

    if (is_dir($dir)) {
        $files = glob($dir . '*.html') ?: [];
        $entries = count($files);
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
    }

    // Hit rate from cache log
    $hitRate = boost_get_hit_rate();

    return [
        'entries'    => $entries,
        'size_bytes' => $totalSize,
        'size_human' => boost_format_bytes($totalSize),
        'hit_rate'   => $hitRate,
    ];
}

// ── Cache Hit/Miss Logging ───────────────────────────────

function boost_log_hit(bool $isHit): void {
    $logFile = OUTPOST_CACHE_DIR . 'boost_hits.log';
    $entry = date('Y-m-d H:i:s') . "\t" . ($isHit ? 'HIT' : 'MISS') . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    // Trim log if too large (keep last 10000 lines)
    if (rand(1, 100) === 1) { // 1% chance to trim (avoid doing it every request)
        boost_trim_hit_log();
    }
}

function boost_trim_hit_log(): void {
    $logFile = OUTPOST_CACHE_DIR . 'boost_hits.log';
    if (!file_exists($logFile)) return;
    if (filesize($logFile) < 500000) return; // Only trim if > 500KB

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -5000); // Keep last 5000
    file_put_contents($logFile, implode("\n", $lines) . "\n", LOCK_EX);
}

function boost_get_hit_rate(): array {
    $logFile = OUTPOST_CACHE_DIR . 'boost_hits.log';
    if (!file_exists($logFile)) return ['hits' => 0, 'misses' => 0, 'rate' => 0];

    $cutoff = date('Y-m-d H:i:s', time() - 86400); // Last 24 hours
    $hits = 0;
    $misses = 0;

    $fp = @fopen($logFile, 'r');
    if (!$fp) return ['hits' => 0, 'misses' => 0, 'rate' => 0];

    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode("\t", $line, 2);
        if (count($parts) < 2) continue;
        if ($parts[0] < $cutoff) continue;

        if ($parts[1] === 'HIT') $hits++;
        else $misses++;
    }
    fclose($fp);

    $total = $hits + $misses;
    return [
        'hits'   => $hits,
        'misses' => $misses,
        'rate'   => $total > 0 ? round(($hits / $total) * 100, 1) : 0,
    ];
}

// ── Browser Cache Headers ────────────────────────────────

/**
 * Set appropriate browser caching headers for a static file.
 * Call this in front-router.php before serving static assets.
 */
function boost_set_static_headers(string $filePath): void {
    $config = boost_get_config();

    header('X-Content-Type-Options: nosniff');

    // Developer mode — send no-cache headers
    if (boost_is_bypassed() || !$config['browser_cache']) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        return;
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // Determine TTL based on file type
    $ttl = 0;
    $immutable = false;

    switch ($ext) {
        case 'css':
            $ttl = $config['browser_cache_css_ttl'];
            // Hashed filenames are immutable
            if (preg_match('/[\.-][a-f0-9]{6,}\.css$/i', $filePath)) $immutable = true;
            break;
        case 'js':
            $ttl = $config['browser_cache_js_ttl'];
            if (preg_match('/[\.-][a-f0-9]{6,}\.js$/i', $filePath)) $immutable = true;
            break;
        case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': case 'avif': case 'svg': case 'ico':
            $ttl = $config['browser_cache_img_ttl'];
            break;
        case 'woff': case 'woff2': case 'ttf': case 'otf': case 'eot':
            $ttl = $config['browser_cache_font_ttl'] ?? 31536000;
            break;
        default:
            $ttl = 3600; // 1 hour for everything else
            break;
    }

    // Set headers
    $cacheControl = "public, max-age={$ttl}";
    if ($immutable) $cacheControl .= ', immutable';
    header('Cache-Control: ' . $cacheControl);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');

    // Last-Modified and ETag
    if (file_exists($filePath)) {
        $mtime = filemtime($filePath);
        $etag = '"' . md5($filePath . $mtime) . '"';
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);

        // 304 Not Modified check
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            exit;
        }
        if ($ifModifiedSince && strtotime($ifModifiedSince) >= $mtime) {
            http_response_code(304);
            exit;
        }
    }
}

// ── GZIP Compression ─────────────────────────────────────

/**
 * Check if the client supports gzip encoding.
 */
function boost_can_gzip(): bool {
    if (!function_exists('gzencode')) return false;
    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    return stripos($accept, 'gzip') !== false;
}

/**
 * Compress content with gzip.
 */
function boost_gzip(string $content): string {
    return gzencode($content, 6);
}

// ── HTML Minification ────────────────────────────────────

/**
 * Minify HTML output by stripping unnecessary whitespace.
 * Preserves content inside <pre>, <code>, <script>, <style>, <textarea>.
 */
function boost_minify_html(string $html): string {
    // Preserve content inside these tags
    $preserved = [];
    $html = preg_replace_callback(
        '/<(pre|code|script|style|textarea)\b[^>]*>.*?<\/\1>/is',
        function ($m) use (&$preserved) {
            $key = '<!--BOOST_PRESERVE_' . count($preserved) . '-->';
            $preserved[$key] = $m[0];
            return $key;
        },
        $html
    );

    // Remove HTML comments (except IE conditionals and Outpost block markers)
    $html = preg_replace('/<!--(?!\[if|\s*outpost:|\/outpost:|\s*BOOST_PRESERVE).*?-->/s', '', $html);

    // Collapse whitespace
    $html = preg_replace('/\s+/', ' ', $html);

    // Remove spaces around tags
    $html = preg_replace('/>\s+</', '><', $html);

    // Restore preserved content
    foreach ($preserved as $key => $content) {
        $html = str_replace($key, $content, $html);
    }

    return trim($html);
}

// ── Lazy Loading ─────────────────────────────────────────

/**
 * Auto-add loading="lazy" to img and iframe tags.
 * Skips the first $skipCount images (above the fold).
 */
function boost_add_lazy_loading(string $html, int $skipCount = 2): string {
    $imgCount = 0;
    $html = preg_replace_callback(
        '/<img\b([^>]*?)>/i',
        function ($m) use (&$imgCount, $skipCount) {
            $imgCount++;
            $attrs = $m[1];
            // Skip if already has loading attribute
            if (stripos($attrs, 'loading=') !== false) return $m[0];
            // Skip first N images (above the fold)
            if ($imgCount <= $skipCount) return $m[0];
            return '<img' . $attrs . ' loading="lazy">';
        },
        $html
    );

    // Lazy load iframes too
    $html = preg_replace_callback(
        '/<iframe\b([^>]*?)>/i',
        function ($m) {
            $attrs = $m[1];
            if (stripos($attrs, 'loading=') !== false) return $m[0];
            return '<iframe' . $attrs . ' loading="lazy">';
        },
        $html
    );

    return $html;
}

// ── CSS Minification ─────────────────────────────────────

/**
 * Basic CSS minification: strip comments and extra whitespace.
 */
function boost_minify_css(string $css): string {
    // Remove comments
    $css = preg_replace('/\/\*.*?\*\//s', '', $css);
    // Remove whitespace around special characters
    $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css);
    // Collapse remaining whitespace
    $css = preg_replace('/\s+/', ' ', $css);
    // Remove trailing semicolons before closing brace
    $css = str_replace(';}', '}', $css);
    return trim($css);
}

// ── JS Minification (basic) ──────────────────────────────

/**
 * Basic JS minification: strip comments (cautiously) and extra whitespace.
 * This is intentionally conservative — not a full minifier.
 */
function boost_minify_js(string $js): string {
    // Remove single-line comments (but not URLs)
    $js = preg_replace('#(?<!:)//(?!/).*$#m', '', $js);
    // Remove multi-line comments
    $js = preg_replace('/\/\*.*?\*\//s', '', $js);
    // Collapse whitespace (but keep newlines to avoid breaking ASI)
    $js = preg_replace('/[ \t]+/', ' ', $js);
    // Remove blank lines
    $js = preg_replace('/\n\s*\n/', "\n", $js);
    return trim($js);
}

// ── Output Buffer Handler ────────────────────────────────

/**
 * Start Boost output buffering. Processes HTML at the end of the request.
 */
function boost_start_output_buffer(): void {
    ob_start('boost_output_callback');
}

/**
 * Output buffer callback — applies minification, lazy loading, caching.
 */
function boost_output_callback(string $buffer): string {
    $config = boost_get_config();

    // Developer mode — pass through untouched
    if (boost_is_bypassed()) return $buffer;

    // Only process HTML responses
    $contentType = '';
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0) {
            $contentType = strtolower(trim(substr($header, 13)));
            break;
        }
    }
    $isHtml = $contentType === '' || stripos($contentType, 'text/html') !== false;

    if (!$isHtml) return $buffer;

    // Skip empty responses
    if (trim($buffer) === '') return $buffer;

    // Apply lazy loading
    if ($config['lazy_loading']) {
        $buffer = boost_add_lazy_loading($buffer, $config['lazy_skip_count']);
    }

    // Apply HTML minification
    if ($config['html_minify']) {
        $buffer = boost_minify_html($buffer);
    }

    // Cache the processed page
    boost_cache_page($buffer);

    // Log cache miss (this was a generated page, not a cache hit)
    boost_log_hit(false);

    // Add header
    header('X-Boost-Cache: MISS');

    // GZIP compression
    if ($config['compression'] && boost_can_gzip()) {
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        return gzencode($buffer, 6);
    }

    return $buffer;
}

// ── Database Optimization ────────────────────────────────

/**
 * Optimize the SQLite database: VACUUM + cleanup stale data.
 */
function boost_optimize_db(): array {
    $cleaned = [];

    // 1. Clean expired rate limit entries
    try {
        $stmt = OutpostDB::query("DELETE FROM rate_limits WHERE expires_at < datetime('now')");
        $cleaned['rate_limits'] = $stmt->rowCount();
    } catch (\Throwable $e) {
        $cleaned['rate_limits'] = 0;
    }

    // 2. Clean old activity log entries (older than 90 days)
    try {
        $stmt = OutpostDB::query("DELETE FROM activity_log WHERE created_at < datetime('now', '-90 days')");
        $cleaned['activity_log'] = $stmt->rowCount();
    } catch (\Throwable $e) {
        $cleaned['activity_log'] = 0;
    }

    // 3. Clean old analytics data (older than 1 year)
    try {
        $stmt = OutpostDB::query("DELETE FROM traffic WHERE created_at < datetime('now', '-365 days')");
        $cleaned['analytics'] = $stmt->rowCount();
    } catch (\Throwable $e) {
        $cleaned['analytics'] = 0;
    }

    // 4. Clear expired preview tokens
    try {
        $stmt = OutpostDB::query("UPDATE collection_items SET preview_token = NULL WHERE preview_token IS NOT NULL AND preview_expires < datetime('now')");
        $cleaned['preview_tokens'] = $stmt->rowCount();
    } catch (\Throwable $e) {
        $cleaned['preview_tokens'] = 0;
    }

    // 5. VACUUM to reclaim space
    try {
        OutpostDB::connect()->exec('VACUUM');
        $cleaned['vacuumed'] = true;
    } catch (\Throwable $e) {
        $cleaned['vacuumed'] = false;
    }

    return $cleaned;
}

/**
 * Get the SQLite database file size.
 */
function boost_db_size(): array {
    $path = OUTPOST_DB_PATH;
    $size = file_exists($path) ? filesize($path) : 0;

    // Also include WAL and SHM files
    $walSize = file_exists($path . '-wal') ? filesize($path . '-wal') : 0;
    $shmSize = file_exists($path . '-shm') ? filesize($path . '-shm') : 0;

    $total = $size + $walSize + $shmSize;
    return [
        'size_bytes' => $total,
        'size_human' => boost_format_bytes($total),
        'db_bytes'   => $size,
        'wal_bytes'  => $walSize,
    ];
}

// ── Cache Preloading ─────────────────────────────────────

/**
 * Preload (warm) the page cache by requesting all known page URLs.
 * Returns the count of URLs warmed.
 */
function boost_preload_cache(): array {
    // Gather all page URLs
    $urls = [];

    // 1. Pages
    try {
        $pages = OutpostDB::fetchAll("SELECT path FROM pages WHERE status = 'published' AND path != '__global__'");
        foreach ($pages as $p) {
            $urls[] = $p['path'];
        }
    } catch (\Throwable $e) {}

    // 2. Collection items
    try {
        $collections = OutpostDB::fetchAll('SELECT slug, url_pattern FROM collections');
        foreach ($collections as $col) {
            $pattern = $col['url_pattern'] ?: '/' . $col['slug'] . '/{slug}';
            $items = OutpostDB::fetchAll(
                "SELECT slug FROM collection_items WHERE collection_id = (SELECT id FROM collections WHERE slug = ?) AND status = 'published'",
                [$col['slug']]
            );
            foreach ($items as $item) {
                $urls[] = str_replace('{slug}', $item['slug'], $pattern);
            }
        }
    } catch (\Throwable $e) {}

    // 3. Ensure homepage is included
    if (!in_array('/', $urls)) {
        array_unshift($urls, '/');
    }

    // Make HTTP requests to warm cache (use file_get_contents with short timeout)
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $host;

    $warmed = 0;
    $failed = 0;
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header'  => "User-Agent: Outpost-Boost-Preloader\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    foreach ($urls as $url) {
        $fullUrl = $baseUrl . $url;
        try {
            $result = @file_get_contents($fullUrl, false, $context);
            if ($result !== false) {
                $warmed++;
            } else {
                $failed++;
            }
        } catch (\Throwable $e) {
            $failed++;
        }
    }

    return [
        'total_urls' => count($urls),
        'warmed'     => $warmed,
        'failed'     => $failed,
    ];
}

// ── Status / Dashboard ───────────────────────────────────

/**
 * Get full Boost status for the admin dashboard.
 */
function boost_status(): array {
    $config = boost_get_config();
    $cacheStats = boost_cache_stats();
    $dbSize = boost_db_size();

    // Template cache stats
    $templateCacheDir = OUTPOST_CACHE_DIR . 'templates/';
    $templateEntries = 0;
    $templateSize = 0;
    if (is_dir($templateCacheDir)) {
        $files = glob($templateCacheDir . '*.php') ?: [];
        $templateEntries = count($files);
        foreach ($files as $f) {
            $templateSize += filesize($f);
        }
    }

    return [
        'config'         => $config,
        'page_cache'     => $cacheStats,
        'template_cache' => [
            'entries'    => $templateEntries,
            'size_bytes' => $templateSize,
            'size_human' => boost_format_bytes($templateSize),
        ],
        'database'       => $dbSize,
        'compression'    => [
            'gzip_available'   => function_exists('gzencode'),
            'brotli_available' => function_exists('brotli_compress'),
        ],
    ];
}

// ── Utility ──────────────────────────────────────────────

function boost_format_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
