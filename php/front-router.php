<?php
/**
 * Outpost Builder — Front Router
 * PHP built-in server router script for local development.
 *
 * Usage: php -S localhost:PORT -t /path/to/files/ /path/to/files/outpost/front-router.php
 *
 * - Static assets (CSS/JS/images/fonts): served directly via return false
 * - /outpost/ paths: served directly (admin panel, API, etc.)
 * - All other paths: routed through the active theme's index.php
 */

$uri     = $_SERVER['REQUEST_URI'];
$path    = parse_url($uri, PHP_URL_PATH);
$docRoot = $_SERVER['DOCUMENT_ROOT'];

// ── 1. Static assets — serve directly (non-PHP only) ─────
if ($path !== '/' && file_exists($docRoot . $path) && is_file($docRoot . $path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== 'php') return false;
}

// ── 2. /outpost/ admin paths ──────────────────────────────
if (str_starts_with($path, '/outpost') || $path === '/outpost') {
    // Redirect bare /outpost → /outpost/ so relative asset paths resolve correctly
    if ($path === '/outpost') {
        header('Location: /outpost/');
        exit;
    }

    $cleanPath = rtrim($path, '/');
    $file      = $docRoot . $cleanPath;

    // PHP files must be require'd — return false does NOT execute PHP
    // files in the built-in server when a router is active.
    if (file_exists($file) && is_file($file) && str_ends_with($file, '.php')) {
        require $file;
        return true;
    }

    // Non-PHP static file (CSS, JS, images) — serve directly
    if (file_exists($file) && is_file($file)) {
        return false;
    }

    // Directory request → try index.php
    if (is_dir($file)) {
        $index = $file . '/index.php';
        if (file_exists($index)) {
            require $index;
            return true;
        }
    }

    // Fallback: outpost/index.php handles the rest
    $adminIndex = $docRoot . '/outpost/index.php';
    if (file_exists($adminIndex)) {
        require $adminIndex;
        return true;
    }

    http_response_code(404);
    echo '<h1>404 Not Found</h1>';
    return true;
}

// ── 3. Front-end page routing ─────────────────────────────
$outpostDir = $docRoot . '/outpost';
$configFile = $outpostDir . '/config.php';

if (!file_exists($configFile)) {
    http_response_code(503);
    echo '<h1>Outpost not installed</h1><p>Expected <code>outpost/</code> directory at <code>' . htmlspecialchars($docRoot) . '</code>.</p>';
    return true;
}

try {

require_once $configFile;
require_once $outpostDir . '/db.php';

// Not installed → redirect to admin
if (!file_exists(OUTPOST_DB_PATH)) {
    header('Location: /outpost/');
    exit;
}

// ── Sitemap ──────────────────────────────────────────────
if ($path === '/sitemap.xml') {
    require_once $outpostDir . '/sitemap.php';
    outpost_generate_sitemap();
    return true;
}

// Resolve active theme
$themeRow    = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
$activeTheme = ($themeRow && $themeRow['value']) ? $themeRow['value'] : 'starter';
$themeDir    = OUTPOST_THEMES_DIR . $activeTheme;

// ── PHP theme (legacy) ────────────────────────────────────
if (file_exists($themeDir . '/index.php')) {
    require $themeDir . '/index.php';
    return true;
}

// ── Liquid theme (.html files via template engine) ────────
if (!file_exists($themeDir . '/index.html')) {
    http_response_code(503);
    echo '<h1>Theme not found</h1>';
    echo '<p>Active theme <code>' . htmlspecialchars($activeTheme) . '</code> has no <code>index.html</code> or <code>index.php</code>.</p>';
    return true;
}

require_once $outpostDir . '/engine.php';
require_once $outpostDir . '/template-engine.php';

// Parse request path
$reqUri   = $_SERVER['REQUEST_URI'];
$reqPath  = '/' . trim(parse_url($reqUri, PHP_URL_PATH), '/');
$reqParts = array_values(array_filter(explode('/', trim($reqPath, '/'))));
$reqPage  = $reqParts[0] ?? '';
$reqSlug  = $reqParts[1] ?? '';

$templateFile = null;

// 1. Direct page match: / → index.html, /blog → blog.html, etc.
if ($reqPage === '' || $reqPath === '/') {
    $templateFile = $themeDir . '/index.html';
} else {
    $candidate = $themeDir . '/' . $reqPage . '.html';
    if (file_exists($candidate)) {
        $templateFile = $candidate;
    }
}

// Draft check — block draft pages from public visitors
if ($templateFile) {
    $lookupPath = ($reqPath === '/' || $reqPath === '') ? '/' : '/' . trim($reqPath, '/');
    $pageRow    = OutpostDB::fetchOne("SELECT status FROM pages WHERE path = ?", [$lookupPath]);
    if ($pageRow && ($pageRow['status'] ?? 'published') === 'draft') {
        http_response_code(404);
        $notFound = $themeDir . '/404.html';
        if (file_exists($notFound)) {
            require_once $outpostDir . '/engine.php';
            require_once $outpostDir . '/template-engine.php';
            outpost_init();
            OutpostTemplate::render($notFound, $themeDir);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
        return true;
    }
}

// 2. Collection URL pattern matching (e.g. /post/my-slug → post.html)
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
                    // Signal to engine to skip cache and allow draft rendering
                    define('OUTPOST_PREVIEW_MODE', true);
                }
                require_once $outpostDir . '/engine.php';
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
            // Try collection-slug.html first, then generic post.html
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

// 3. Channel URL pattern matching (e.g. /listing/my-item → listing.html)
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
            // Try channel-slug.html, then generic channel.html
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

// 4. Not found
if (!$templateFile || !file_exists($templateFile)) {
    http_response_code(404);
    $notFound = $themeDir . '/404.html';
    if (file_exists($notFound)) {
        outpost_init();
        OutpostTemplate::render($notFound, $themeDir);
    } else {
        echo '<h1>404 Not Found</h1>';
    }
    return true;
}

outpost_init();
outpost_maybe_auto_publish();

// Check page visibility (members-only / paid-only gating)
$currentPath = '/' . trim(parse_url($reqUri, PHP_URL_PATH), '/');
$pageRow = OutpostDB::fetchOne('SELECT visibility FROM pages WHERE path = ?', [$currentPath]);
if ($pageRow) {
    $vis = $pageRow['visibility'] ?? 'public';
    if ($vis === 'members') {
        cms_require_member();
    } elseif ($vis === 'paid') {
        cms_require_paid_member();
    }
}

OutpostTemplate::render($templateFile, $themeDir);

} catch (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    http_response_code(503);
    error_log('Outpost front-router error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

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

return true;
