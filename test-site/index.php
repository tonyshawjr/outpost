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

$themeRow    = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
$activeTheme = ($themeRow && $themeRow['value']) ? $themeRow['value'] : 'starter';
$themeDir    = OUTPOST_THEMES_DIR . $activeTheme;

// PHP theme (legacy)
if (file_exists($themeDir . '/index.php')) {
    require $themeDir . '/index.php';
    exit;
}

if (!file_exists($themeDir . '/index.html')) {
    http_response_code(503);
    echo '<h1>Theme not configured</h1>';
    echo '<p>Active theme <code>' . htmlspecialchars($activeTheme) . '</code> has no <code>index.html</code>.</p>';
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
            $colTemplate = $themeDir . '/' . $col['slug'] . '.html';
            if (file_exists($colTemplate)) {
                $templateFile = $colTemplate;
            } elseif (file_exists($themeDir . '/post.html')) {
                $templateFile = $themeDir . '/post.html';
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
            $chanTemplate = $themeDir . '/' . $chan['slug'] . '.html';
            if (file_exists($chanTemplate)) {
                $templateFile = $chanTemplate;
            } elseif (file_exists($themeDir . '/channel.html')) {
                $templateFile = $themeDir . '/channel.html';
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
