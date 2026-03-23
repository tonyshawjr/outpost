<?php
/**
 * Outpost CMS — Configuration
 */

define('OUTPOST_VERSION', '4.10.2');

// Paths (resolved relative to this file's location)
define('OUTPOST_DIR', __DIR__ . '/');

// Content directory (user data)
define('OUTPOST_CONTENT_DIR', OUTPOST_DIR . 'content/');
define('OUTPOST_DATA_DIR', OUTPOST_CONTENT_DIR . 'data/');
define('OUTPOST_DB_PATH', OUTPOST_DATA_DIR . 'cms.db');
define('OUTPOST_UPLOADS_DIR', OUTPOST_CONTENT_DIR . 'uploads/');
define('OUTPOST_THEMES_DIR', OUTPOST_CONTENT_DIR . 'themes/');
define('OUTPOST_BACKUPS_DIR', OUTPOST_CONTENT_DIR . 'backups/');

// Core directories (not under content/)
define('OUTPOST_CACHE_DIR', OUTPOST_DIR . 'cache/');
define('OUTPOST_ADMIN_DIR', OUTPOST_DIR . 'admin/');

// Limits
define('OUTPOST_MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 MB
define('OUTPOST_THUMB_WIDTH', 400);
define('OUTPOST_THUMB_HEIGHT', 300);
define('OUTPOST_MAX_IMAGE_WIDTH', 2400);
define('OUTPOST_WEBP_AUTO_CONVERT', true);
define('OUTPOST_WEBP_QUALITY', 85);
define('OUTPOST_ITEMS_PER_PAGE', 20);
define('OUTPOST_RATE_LIMIT_ATTEMPTS', 5);
define('OUTPOST_RATE_LIMIT_WINDOW', 60); // seconds
define('OUTPOST_API_RATE_LIMIT', 60);        // max mutations per window (authenticated)
define('OUTPOST_API_RATE_LIMIT_WINDOW', 60); // seconds

// Upload whitelist
define('OUTPOST_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'pdf', 'mp4', 'webm']);
define('OUTPOST_ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml',
    'application/pdf', 'video/mp4', 'video/webm'
]);

// Session
define('OUTPOST_SESSION_LIFETIME', 86400); // 24 hours
define('OUTPOST_SESSION_NAME', 'outpost_session');

// Cache
define('OUTPOST_CACHE_ENABLED', true);

// Code editor
define('OUTPOST_CODE_EXTENSIONS', ['html', 'htm', 'css', 'js', 'json', 'xml', 'svg', 'txt', 'md', 'yml', 'yaml']);

// ── Auto-migrate old directory layout to content/ ──
(function() {
    // Already migrated or fresh install with content/ in place
    if (is_dir(OUTPOST_CONTENT_DIR) && is_dir(OUTPOST_DATA_DIR)) return;

    // Old layout detected: data/ exists at outpost root
    $oldDataDir = OUTPOST_DIR . 'data/';
    if (!is_dir($oldDataDir)) return; // Fresh install, nothing to migrate

    // Create content/
    if (!is_dir(OUTPOST_CONTENT_DIR)) mkdir(OUTPOST_CONTENT_DIR, 0755, true);

    // Move directories into content/
    $moves = [
        'data'    => [OUTPOST_DIR . 'data/',    OUTPOST_CONTENT_DIR . 'data/'],
        'uploads' => [OUTPOST_DIR . 'uploads/', OUTPOST_CONTENT_DIR . 'uploads/'],
        'themes'  => [OUTPOST_DIR . 'themes/',  OUTPOST_CONTENT_DIR . 'themes/'],
        'backups' => [OUTPOST_DIR . 'backups/', OUTPOST_CONTENT_DIR . 'backups/'],
    ];

    foreach ($moves as $name => [$old, $new]) {
        if (is_dir($old) && !is_link($old)) {
            rename($old, $new);
            // Create symlink for URL compatibility (uploads + themes)
            if ($name === 'uploads' || $name === 'themes') {
                symlink($new, rtrim($old, '/'));
            }
        }
    }
})();
