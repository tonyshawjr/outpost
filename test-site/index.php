<?php
/**
 * Front-controller for Apache/Nginx deployments.
 * Detects v1 (Liquid) vs v2 (data-attribute) template engine automatically.
 */

$outpostDir = __DIR__ . '/outpost';

if (!file_exists($outpostDir . '/config.php')) {
    header('Location: /outpost/');
    exit;
}

try {

require_once $outpostDir . '/config.php';
require_once $outpostDir . '/db.php';

if (!file_exists(OUTPOST_DB_PATH)) {
    header('Location: /outpost/');
    exit;
}

// URL Redirects (checked early)
$reqPath_ = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (file_exists(OUTPOST_DB_PATH) && file_exists($outpostDir . '/redirects.php')) {
    require_once $outpostDir . '/redirects.php';
    redirects_check($reqPath_);
}

// Theme asset fallback (v6) — themes that reference assets relatively
// (e.g. assets/images/foo.svg) resolve against the page URL and miss.
// Serve those from the active theme dir before delegating to the engine.
//
// Only relevant on Apache/Nginx setups that fall through to index.php for
// missing files. If the asset already exists at the webroot, the webserver
// serves it directly and this fallback is skipped.
if ($reqPath_ !== '/' && !str_starts_with($reqPath_, '/outpost/')
    && defined('OUTPOST_THEMES_DIR')
    && pathinfo($reqPath_, PATHINFO_EXTENSION) !== ''
    && pathinfo($reqPath_, PATHINFO_EXTENSION) !== 'php'
    && !file_exists($_SERVER['DOCUMENT_ROOT'] . $reqPath_)) {
    require_once $outpostDir . '/blocks.php';
    $_themeRoot = rtrim(OUTPOST_THEMES_DIR . outpost_get_active_theme(), '/');
    $tryPaths = [$_themeRoot . $reqPath_];
    $segments = array_values(array_filter(explode('/', $reqPath_)));
    for ($i = 1; $i < count($segments); $i++) {
        $tryPaths[] = $_themeRoot . '/' . implode('/', array_slice($segments, $i));
    }
    foreach ($tryPaths as $_assetPath) {
        if (is_file($_assetPath)) {
            $ext = strtolower(pathinfo($_assetPath, PATHINFO_EXTENSION));
            if ($ext === 'php') break;
            $mime = match ($ext) {
                'css'  => 'text/css',
                'js'   => 'application/javascript',
                'svg'  => 'image/svg+xml',
                'png'  => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'  => 'font/ttf',
                'otf'  => 'font/otf',
                'eot'  => 'application/vnd.ms-fontobject',
                'json' => 'application/json',
                'txt'  => 'text/plain',
                'html', 'htm' => 'text/html',
                default => 'application/octet-stream',
            };
            header('Content-Type: ' . $mime);
            if (file_exists($outpostDir . '/boost.php')) {
                require_once $outpostDir . '/boost.php';
                if (function_exists('boost_set_static_headers')) {
                    boost_set_static_headers($_assetPath);
                }
            }
            readfile($_assetPath);
            exit;
        }
    }
}

// Boost Performance Suite
if (file_exists($outpostDir . '/boost.php')) {
    require_once $outpostDir . '/boost.php';
    $_boostConfig = boost_get_config();
    if (!boost_is_bypassed() && $_boostConfig['page_cache']) {
        if (boost_serve_cached_page()) exit;
        boost_start_output_buffer();
    }
}

// Robots.txt
if ($reqPath_ === '/robots.txt') {
    require_once $outpostDir . '/sitemap.php';
    outpost_generate_robots();
    exit;
}

// Sitemap
if ($reqPath_ === '/sitemap.xml') {
    require_once $outpostDir . '/sitemap.php';
    outpost_generate_sitemap();
    exit;
}

// RSS Feeds
if (in_array($reqPath_, ['/feed', '/feed.xml', '/rss', '/rss.xml'])) {
    require_once $outpostDir . '/feed.php';
    outpost_generate_feed();
    exit;
}
if (preg_match('#^/([a-z0-9_-]+)/feed(?:\.xml)?$#', $reqPath_, $feedMatch)) {
    require_once $outpostDir . '/feed.php';
    outpost_generate_feed($feedMatch[1]);
    exit;
}

// Resolve theme directory.
// v5 was themeless — themeDir = site root.
// v6 uses outpost/content/themes/{active}/ as the active theme dir.
// Prefer the active theme dir if it has an index.html; otherwise fall back
// to site root for v5-style themeless installs.
$themeDir = OUTPOST_SITE_ROOT;
if (defined('OUTPOST_THEMES_DIR')) {
    if (!function_exists('outpost_get_active_theme')) {
        require_once $outpostDir . '/blocks.php';
    }
    $_v6Theme = OUTPOST_THEMES_DIR . outpost_get_active_theme();
    if (is_dir($_v6Theme) && file_exists($_v6Theme . '/index.html')) {
        $themeDir = $_v6Theme;
    }
}

// Normalize: ensure $themeDir does NOT have trailing slash for path concatenation
$themeDir = rtrim($themeDir, '/');

if (!file_exists($themeDir . '/index.html')) {
    http_response_code(503);
    echo '<h1>index.html not found</h1>';
    echo '<p>Expected <code>index.html</code> at <code>' . htmlspecialchars($themeDir) . '</code>.</p>';
    echo '<p><a href="/outpost/">Go to admin</a></p>';
    exit;
}

require_once $outpostDir . '/engine.php';

// Detect template engine version: v2 (data attributes) or v1 (Liquid syntax)
$_outpost_use_v2 = outpost_detect_engine_version($themeDir);
if ($_outpost_use_v2) {
    require_once $outpostDir . '/template-engine-v2.php';
} else {
    require_once $outpostDir . '/template-engine.php';
}

$reqUri   = $_SERVER['REQUEST_URI'];
$reqPath  = '/' . trim(parse_url($reqUri, PHP_URL_PATH), '/');
$reqParts = array_values(array_filter(explode('/', trim($reqPath, '/'))));
$reqPage  = $reqParts[0] ?? '';
$reqSlug  = $reqParts[1] ?? '';

$templateFile = null;

// Lodge (member portal) routes
$_lodgeSlug = null;
try {
    $_lodgeRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'lodge_slug'");
    $_lodgeSlug = ($_lodgeRow && $_lodgeRow['value']) ? $_lodgeRow['value'] : 'lodge';
} catch (\Throwable $e) {
    $_lodgeSlug = 'lodge';
}
$_lodgeSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($_lodgeSlug));
if (!$_lodgeSlug) $_lodgeSlug = 'lodge';

if ($_lodgeSlug && str_starts_with($reqPath, '/' . $_lodgeSlug)) {
    $ffRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'feature_flags'");
    $ff = $ffRow ? json_decode($ffRow['value'], true) : [];
    if (empty($ff) || ($ff['lodge'] ?? false)) {
        require_once $outpostDir . '/members.php';
        if (!OutpostMember::check()) {
            $loginPageRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'lodge_login_page'");
            $loginPage = ($loginPageRow && $loginPageRow['value']) ? $loginPageRow['value'] : '/outpost/member-pages/login.php';
            $returnUrl = urlencode($reqUri);
            header("Location: {$loginPage}?return={$returnUrl}");
            exit;
        }
        $lodgePath = substr($reqPath, strlen('/' . $_lodgeSlug));
        $lodgePath = '/' . trim($lodgePath, '/');
        $lodgeTemplate = null;
        if ($lodgePath === '/' || $lodgePath === '') {
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/dashboard.html';
            if (!file_exists($lodgeTemplate)) $lodgeTemplate = $themeDir . '/lodge/dashboard.html';
        } elseif (preg_match('#^/edit/(\d+)$#', $lodgePath, $m)) {
            $_GET['item_id'] = $m[1];
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/edit.html';
            if (!file_exists($lodgeTemplate)) $lodgeTemplate = $themeDir . '/lodge/edit.html';
        } elseif (preg_match('#^/create/([a-z0-9_-]+)$#', $lodgePath, $m)) {
            $_GET['collection'] = $m[1];
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/create.html';
            if (!file_exists($lodgeTemplate)) $lodgeTemplate = $themeDir . '/lodge/create.html';
        } elseif ($lodgePath === '/profile') {
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/profile.html';
            if (!file_exists($lodgeTemplate)) $lodgeTemplate = $themeDir . '/lodge/profile.html';
        }
        if ($lodgeTemplate && file_exists($lodgeTemplate)) {
            outpost_init();
            outpost_render_template($lodgeTemplate, $themeDir);
            exit;
        }
    }
}

// Direct page match
if ($reqPage === '' || $reqPath === '/') {
    $templateFile = $themeDir . '/index.html';
} else {
    $candidate = $themeDir . '/' . $reqPage . '.html';
    if (file_exists($candidate)) {
        $templateFile = $candidate;
    }
}

// Draft check
if ($templateFile) {
    $lookupPath = ($reqPath === '/' || $reqPath === '') ? '/' : '/' . trim($reqPath, '/');
    $pageRow = OutpostDB::fetchOne("SELECT status FROM pages WHERE path = ?", [$lookupPath]);
    if ($pageRow && ($pageRow['status'] ?? 'published') === 'draft') {
        $templateFile = null;
    }
}

// Collection URL pattern matching
if (!$templateFile) {
    $collections = OutpostDB::fetchAll('SELECT * FROM collections');
    foreach ($collections as $col) {
        $pattern      = $col['url_pattern'] ?: '/' . $col['slug'] . '/{slug}';
        $regexPattern = '#^' . str_replace('\{slug\}', '([^/]+)', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regexPattern, $reqPath, $matches)) {
            $_GET['slug'] = $matches[1];
            $previewToken = $_GET['preview'] ?? '';
            if ($previewToken) {
                $preItem = OutpostDB::fetchOne(
                    'SELECT * FROM collection_items WHERE collection_id = ? AND slug = ? AND preview_token = ?',
                    [$col['id'], $matches[1], $previewToken]
                );
            } else {
                $preItem = OutpostDB::fetchOne(
                    'SELECT * FROM collection_items WHERE collection_id = ? AND slug = ? AND status = ?',
                    [$col['id'], $matches[1], 'published']
                );
            }
            if ($preItem) {
                if ($previewToken) define('OUTPOST_PREVIEW_MODE', true);
                require_once $outpostDir . '/engine.php';
                global $_outpost_current_item, $_outpost_current_collection;
                $data = json_decode($preItem['data'], true) ?: [];
                $data['id']           = $preItem['id'];
                $data['slug']         = $preItem['slug'];
                $data['status']       = $preItem['status'];
                $data['created_at']   = $preItem['created_at'];
                $data['updated_at']   = $preItem['updated_at'];
                $data['published_at'] = $preItem['published_at'] ? date('F j, Y', strtotime($preItem['published_at'])) : '';
                $_outpost_current_item       = $data;
                $_outpost_current_collection = $col['slug'];
            }
            // v6 template hierarchy: single-{slug}.html → {slug}.html → single.html → post.html
            $templateFile = null;
            foreach (["/single-{$col['slug']}.html", "/{$col['slug']}.html", '/single.html', '/post.html'] as $rel) {
                if (file_exists($themeDir . $rel)) { $templateFile = $themeDir . $rel; break; }
            }
            break;
        }
    }
}

// Channel URL pattern matching
if (!$templateFile) {
    try {
        $channels = OutpostDB::fetchAll("SELECT * FROM channels WHERE status = 'active' AND url_pattern IS NOT NULL AND url_pattern != ''");
    } catch (\Throwable $e) { $channels = []; }
    foreach ($channels as $chan) {
        $pattern = $chan['url_pattern'];
        $regexPattern = '#^' . str_replace('\{slug\}', '([^/]+)', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regexPattern, $reqPath, $matches)) {
            $_GET['slug'] = $matches[1];
            // v6 hierarchy + channel.html fallback
            foreach (["/single-{$chan['slug']}.html", "/{$chan['slug']}.html", '/single.html', '/post.html', '/channel.html'] as $rel) {
                if (file_exists($themeDir . $rel)) { $templateFile = $themeDir . $rel; break; }
            }
            break;
        }
    }
}

// 404
if (!$templateFile || !file_exists($templateFile)) {
    http_response_code(404);
    try {
        require_once $outpostDir . '/redirects.php';
        redirects_log_404($reqPath);
    } catch (\Throwable $e) {}
    $notFound = $themeDir . '/404.html';
    if (file_exists($notFound)) {
        outpost_init();
        outpost_render_template($notFound, $themeDir);
    } else {
        echo '<h1>404 Not Found</h1>';
    }
    exit;
}

outpost_init();
outpost_maybe_auto_publish();

// Editor mode
$_outpost_editor_mode = outpost_is_admin();

// Page visibility
$currentPath = '/' . trim(parse_url($reqUri, PHP_URL_PATH), '/');
$pageRow = OutpostDB::fetchOne('SELECT visibility FROM pages WHERE path = ?', [$currentPath]);
if ($pageRow) {
    $vis = $pageRow['visibility'] ?? 'public';
    if ($vis === 'members') cms_require_member();
    elseif ($vis === 'paid') cms_require_paid_member();
}

outpost_render_template($templateFile, $themeDir, $_outpost_editor_mode ?? false);

} catch (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    http_response_code(503);
    error_log('Outpost error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $isAdmin = function_exists('outpost_is_admin') && outpost_is_admin();
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>503</title></head><body style="font-family:sans-serif;max-width:560px;margin:80px auto"><h1>Something went wrong</h1>';
    if ($isAdmin) echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    echo '</body></html>';
}
