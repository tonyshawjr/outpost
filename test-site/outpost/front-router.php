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
    if ($ext !== 'php') {
        // Set Boost browser caching headers on static assets
        $boostFile = $docRoot . '/outpost/boost.php';
        if (file_exists($boostFile)) {
            require_once $docRoot . '/outpost/config.php';
            require_once $docRoot . '/outpost/db.php';
            if (file_exists(OUTPOST_DB_PATH)) {
                require_once $boostFile;
                boost_set_static_headers($docRoot . $path);
            }
        }
        return false;
    }
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

    // Directory request → try index.html then index.php
    if (is_dir($file)) {
        $indexHtml = $file . '/index.html';
        if (file_exists($indexHtml)) {
            // Serve static HTML (e.g., /outpost/docs/)
            header('Content-Type: text/html; charset=utf-8');
            readfile($indexHtml);
            return true;
        }
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

// ── URL Redirects (checked early, before theme routing) ──
if (file_exists(OUTPOST_DB_PATH) && file_exists($outpostDir . '/redirects.php')) {
    require_once $outpostDir . '/redirects.php';
    redirects_check($path);
}

// Not installed → redirect to admin
if (!file_exists(OUTPOST_DB_PATH)) {
    header('Location: /outpost/');
    exit;
}

// ── Boost Performance Suite ──────────────────────────────
require_once $outpostDir . '/boost.php';
$_boostConfig = boost_get_config();
if (!boost_is_bypassed() && $_boostConfig['page_cache']) {
    // Try to serve from page cache (exits on HIT)
    if (boost_serve_cached_page()) exit;
    // Start output buffering for minification/compression/caching
    boost_start_output_buffer();
}

// ── Robots.txt ──────────────────────────────────────────
if ($path === '/robots.txt') {
    require_once $outpostDir . '/sitemap.php';
    outpost_generate_robots();
    return true;
}

// ── Sitemap ──────────────────────────────────────────────
if ($path === '/sitemap.xml') {
    require_once $outpostDir . '/sitemap.php';
    outpost_generate_sitemap();
    return true;
}

// ── RSS Feeds ──────────────────────────────────────────
if ($path === '/feed' || $path === '/feed.xml' || $path === '/rss' || $path === '/rss.xml') {
    require_once $outpostDir . '/feed.php';
    outpost_generate_feed();
    return true;
}
// Per-collection feed: /{collection-slug}/feed
if (preg_match('#^/([a-z0-9_-]+)/feed(?:\.xml)?$#', $path, $feedMatch)) {
    require_once $outpostDir . '/feed.php';
    outpost_generate_feed($feedMatch[1]);
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

// ── HTML theme (.html files via template engine) ─────────
if (!file_exists($themeDir . '/index.html')) {
    http_response_code(503);
    echo '<h1>Theme not found</h1>';
    echo '<p>Active theme <code>' . htmlspecialchars($activeTheme) . '</code> has no <code>index.html</code> or <code>index.php</code>.</p>';
    return true;
}

require_once $outpostDir . '/engine.php';

// Detect template engine version: v2 (data attributes) or v1 (Liquid syntax)
$_outpost_use_v2 = outpost_detect_engine_version($themeDir);
if ($_outpost_use_v2) {
    require_once $outpostDir . '/template-engine-v2.php';
} else {
    require_once $outpostDir . '/template-engine.php';
}

// Parse request path
$reqUri   = $_SERVER['REQUEST_URI'];
$reqPath  = '/' . trim(parse_url($reqUri, PHP_URL_PATH), '/');
$reqParts = array_values(array_filter(explode('/', trim($reqPath, '/'))));
$reqPage  = $reqParts[0] ?? '';
$reqSlug  = $reqParts[1] ?? '';

$templateFile = null;

// ── Lodge (member portal) routes ────────────────────────
// Custom slug is stored in settings; default is 'lodge'
$_lodgeSlug = null;
try {
    $_lodgeRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'lodge_slug'");
    $_lodgeSlug = ($_lodgeRow && $_lodgeRow['value']) ? $_lodgeRow['value'] : 'lodge';
} catch (\Throwable $e) {
    $_lodgeSlug = 'lodge';
}
// Sanitize slug: alphanumeric and hyphens only, block reserved paths
$_lodgeSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($_lodgeSlug));
if (!$_lodgeSlug || in_array($_lodgeSlug, ['outpost', 'admin', 'api', 'wp-admin', 'wp-login'])) {
    $_lodgeSlug = 'lodge';
}

if ($_lodgeSlug && str_starts_with($reqPath, '/' . $_lodgeSlug)) {
    // Check if Lodge feature is enabled
    $ffRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'feature_flags'");
    $ff = $ffRow ? json_decode($ffRow['value'], true) : [];
    if (empty($ff) || ($ff['lodge'] ?? false)) {
        require_once $outpostDir . '/members.php';

        // All lodge routes require member auth
        if (!OutpostMember::check()) {
            // Redirect to custom login page if configured, otherwise Outpost default
            $loginPageRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'lodge_login_page'");
            $loginPage = ($loginPageRow && $loginPageRow['value']) ? $loginPageRow['value'] : '/outpost/member-pages/login.php';
            $returnUrl = urlencode($reqUri);
            header("Location: {$loginPage}?return={$returnUrl}");
            exit;
        }

        $lodgePath = substr($reqPath, strlen('/' . $_lodgeSlug));
        $lodgePath = '/' . trim($lodgePath, '/');

        // Map lodge routes to theme templates
        $lodgeTemplate = null;
        if ($lodgePath === '/' || $lodgePath === '') {
            // Dashboard
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/dashboard.html';
            if (!file_exists($lodgeTemplate)) {
                $lodgeTemplate = $themeDir . '/lodge/dashboard.html';
            }
        } elseif (preg_match('#^/edit/(\d+)$#', $lodgePath, $m)) {
            $_GET['item_id'] = $m[1];
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/edit.html';
            if (!file_exists($lodgeTemplate)) {
                $lodgeTemplate = $themeDir . '/lodge/edit.html';
            }
        } elseif (preg_match('#^/create/([a-z0-9_-]+)$#', $lodgePath, $m)) {
            $_GET['collection'] = $m[1];
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/create.html';
            if (!file_exists($lodgeTemplate)) {
                $lodgeTemplate = $themeDir . '/lodge/create.html';
            }
        } elseif ($lodgePath === '/profile') {
            $lodgeTemplate = $themeDir . '/' . $_lodgeSlug . '/profile.html';
            if (!file_exists($lodgeTemplate)) {
                $lodgeTemplate = $themeDir . '/lodge/profile.html';
            }
        }

        if ($lodgeTemplate && file_exists($lodgeTemplate)) {
            outpost_init();
            outpost_render_template($lodgeTemplate, $themeDir);
            return true;
        }

        // Fallback to 404
        http_response_code(404);
        $notFound = $themeDir . '/404.html';
        if (file_exists($notFound)) {
            outpost_init();
            outpost_render_template($notFound, $themeDir);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
        return true;
    }
}

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
            outpost_init();
            outpost_render_template($notFound, $themeDir);
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

// 4. Not found — log for redirect suggestions
if (!$templateFile || !file_exists($templateFile)) {
    http_response_code(404);
    // Log 404 for redirect suggestions
    try {
        require_once $outpostDir . '/redirects.php';
        redirects_log_404($reqPath);
    } catch (\Throwable $e) {
        // Don't let logging break the 404 page
    }
    $notFound = $themeDir . '/404.html';
    if (file_exists($notFound)) {
        outpost_init();
        outpost_render_template($notFound, $themeDir);
    } else {
        echo '<h1>404 Not Found</h1>';
    }
    return true;
}

outpost_init();
outpost_maybe_auto_publish();

// Editor mode — logged-in admins always get data-outpost attributes preserved for click-to-edit bridge
$_outpost_editor_mode = outpost_is_admin();

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

// ── Review Mode (client feedback overlay) ────────────
$reviewToken = $_GET['review'] ?? '';
$_outpost_review_inject = '';
if ($reviewToken) {
    $token = OutpostDB::fetchOne(
        'SELECT * FROM review_tokens WHERE token = ? AND active = 1',
        [$reviewToken]
    );
    if ($token) {
        $validExpiry = !$token['expires_at'] || $token['expires_at'] > date('Y-m-d H:i:s');
        $validPage   = !$token['page_path'] || $token['page_path'] === $currentPath;
        if ($validExpiry && $validPage) {
            $apiUrl = '/outpost/api.php';
            $jsUrl  = '/outpost/review-overlay.js';
            // Detect admin mode: logged-in admin visiting with ?admin=1
            $isAdminReview = isset($_GET['admin']) && function_exists('outpost_is_admin') && outpost_is_admin();
            $adminFlag = $isAdminReview ? 'true' : 'false';
            $csrfInject = '';
            $adminNameInject = '';
            if ($isAdminReview) {
                require_once $outpostDir . '/auth.php';
                $csrf = OutpostAuth::csrfToken();
                $csrfInject = "window.__OUTPOST_CSRF__=\"{$csrf}\";";
                // Get admin display name for comment attribution
                $adminUser = OutpostDB::fetchOne('SELECT display_name, username FROM users WHERE id = ?', [$_SESSION['outpost_user_id']]);
                $adminDisplayName = addslashes($adminUser['display_name'] ?: $adminUser['username'] ?: 'Admin');
                $adminNameInject = "window.__OUTPOST_ADMIN_NAME__=\"{$adminDisplayName}\";";
            }
            $_outpost_review_inject = <<<HTML
<script>window.__OUTPOST_REVIEW_TOKEN__="{$reviewToken}";window.__OUTPOST_API_URL__="{$apiUrl}";window.__OUTPOST_REVIEW_ADMIN__={$adminFlag};{$csrfInject}{$adminNameInject}</script>
<script src="{$jsUrl}"></script>
HTML;
        }
    }
}

// Inject review overlay after render if active
if ($_outpost_review_inject) {
    ob_start();
    outpost_render_template($templateFile, $themeDir, $_outpost_editor_mode);
    $html = ob_get_clean();
    // Inject before </body>
    $html = str_replace('</body>', $_outpost_review_inject . "\n</body>", $html);
    echo $html;
} else {
    outpost_render_template($templateFile, $themeDir, $_outpost_editor_mode);
}

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
