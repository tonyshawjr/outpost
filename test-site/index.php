<?php
/**
 * Front-controller for Apache/Nginx deployments.
 * For PHP built-in server (local dev), use outpost/front-router.php as the router script.
 * The Outpost Builder handles this automatically.
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

// Sitemap
$reqPath_ = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($reqPath_ === '/sitemap.xml') {
    require_once $outpostDir . '/sitemap.php';
    outpost_generate_sitemap();
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

// Liquid theme
if (!file_exists($themeDir . '/index.html')) {
    http_response_code(503);
    echo '<h1>Theme not configured</h1>';
    echo '<p>Active theme <code>' . htmlspecialchars($activeTheme) . '</code> has no <code>index.html</code> or <code>index.php</code>.</p>';
    echo '<p><a href="/outpost/">Go to admin</a></p>';
    exit;
}

require_once $outpostDir . '/engine.php';
require_once $outpostDir . '/template-engine.php';

$reqUri   = $_SERVER['REQUEST_URI'];
$reqPath  = '/' . trim(parse_url($reqUri, PHP_URL_PATH), '/');
$reqParts = array_values(array_filter(explode('/', trim($reqPath, '/'))));
$reqPage  = $reqParts[0] ?? '';

$templateFile = null;

if ($reqPage === '' || $reqPath === '/') {
    $templateFile = $themeDir . '/index.html';
} else {
    $candidate = $themeDir . '/' . $reqPage . '.html';
    if (file_exists($candidate)) {
        $templateFile = $candidate;
    }
}

if (!$templateFile) {
    $collections = OutpostDB::fetchAll('SELECT * FROM collections');
    foreach ($collections as $col) {
        $pattern      = $col['url_pattern'] ?: '/' . $col['slug'] . '/{slug}';
        $regexPattern = '#^' . str_replace('\{slug\}', '([^/]+)', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regexPattern, $reqPath, $matches)) {
            $_GET['slug'] = $matches[1];
            // Pre-load item so {{ meta.title }} in head partial can access SEO fields
            // Allow preview of unpublished items via ?preview=TOKEN
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
                if ($previewToken) {
                    define('OUTPOST_PREVIEW_MODE', true);
                }
                global $_outpost_current_item, $_outpost_current_collection;
                $data = json_decode($preItem['data'], true) ?: [];
                $data['id']           = $preItem['id'];
                $data['slug']         = $preItem['slug'];
                $data['status']       = $preItem['status'];
                $data['created_at']   = $preItem['created_at'];
                $data['updated_at']   = $preItem['updated_at'];
                $data['published_at'] = $preItem['published_at']
                    ? date('F j, Y', strtotime($preItem['published_at']))
                    : '';
                $_outpost_current_item       = $data;
                $_outpost_current_collection = $col['slug'];
            }
            $colTemplate  = $themeDir . '/' . $col['slug'] . '.html';
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
    } catch (\Throwable $e) {
        $channels = [];
    }
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

if (!$templateFile || !file_exists($templateFile)) {
    http_response_code(404);
    $notFound = $themeDir . '/404.html';
    if (file_exists($notFound)) {
        outpost_init();
        OutpostTemplate::render($notFound, $themeDir);
    } else {
        echo '<h1>404 Not Found</h1>';
    }
    exit;
}

outpost_init();
outpost_maybe_auto_publish();

// Check page visibility (members-only / paid-only gating)
$pageRow = OutpostDB::fetchOne('SELECT visibility FROM pages WHERE path = ?', [$reqPath]);
if ($pageRow) {
    $vis = $pageRow['visibility'] ?? 'public';
    if ($vis === 'members') {
        cms_require_member();
    } elseif ($vis === 'paid') {
        cms_require_paid_member();
    }
}

// ── Review Mode (client feedback overlay) ────────────
$reviewToken = $_GET['review'] ?? '';
$_outpost_review_inject = '';
if ($reviewToken) {
    require_once $outpostDir . '/comments.php';
    ensure_comment_tables();
    $tokenRow = OutpostDB::fetchOne(
        'SELECT * FROM review_tokens WHERE token = ? AND active = 1',
        [$reviewToken]
    );
    if ($tokenRow) {
        $validExpiry = !$tokenRow['expires_at'] || $tokenRow['expires_at'] > date('Y-m-d H:i:s');
        $validPage   = !$tokenRow['page_path'] || $tokenRow['page_path'] === $reqPath;
        if ($validExpiry && $validPage) {
            $apiUrl = '/outpost/api.php';
            $jsUrl  = '/outpost/review-overlay.js';
            $isAdmin = function_exists('outpost_is_admin') && outpost_is_admin();
            $csrfPart = '';
            if ($isAdmin && isset($_GET['admin'])) {
                $csrfPart = 'window.__OUTPOST_CSRF_TOKEN__="' . OutpostAuth::csrfToken() . '";';
            }
            $_outpost_review_inject = '<script>window.__OUTPOST_REVIEW_TOKEN__="' . $reviewToken . '";window.__OUTPOST_API_URL__="' . $apiUrl . '";window.__OUTPOST_REVIEW_ADMIN__=' . ($isAdmin && isset($_GET['admin']) ? 'true' : 'false') . ';' . $csrfPart . '</script><script src="' . $jsUrl . '"></script>';
        }
    }
}

if ($_outpost_review_inject) {
    ob_start();
    OutpostTemplate::render($templateFile, $themeDir);
    $html = ob_get_clean();
    echo str_replace('</body>', $_outpost_review_inject . "\n</body>", $html);
} else {
    OutpostTemplate::render($templateFile, $themeDir);
}

} catch (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    http_response_code(503);
    error_log('Outpost front-controller error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $isAdmin = function_exists('outpost_is_admin') && outpost_is_admin();

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>503 — Site Error</title>'
       . '<style>body{font-family:-apple-system,system-ui,sans-serif;max-width:560px;margin:80px auto;color:#333;line-height:1.5}'
       . 'h1{font-size:22px;margin-bottom:4px}p{color:#666}pre{background:#f5f5f5;padding:16px;border-radius:6px;overflow-x:auto;font-size:13px;color:#c00}</style></head>'
       . '<body><h1>Something went wrong</h1>'
       . '<p>This page couldn&#39;t be rendered. The site owner has been notified.</p>';

    if ($isAdmin) {
        echo '<hr style="margin:24px 0;border:0;border-top:1px solid #eee">'
           . '<p style="color:#999;font-size:13px"><strong>Error</strong> in <code>' . htmlspecialchars(basename($e->getFile())) . ':' . $e->getLine() . '</code></p>'
           . '<pre>' . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }

    echo '</body></html>';
}
