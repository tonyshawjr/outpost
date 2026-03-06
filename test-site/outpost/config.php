<?php
/**
 * Outpost CMS — Configuration
 */

define('OUTPOST_VERSION', '1.0.0-beta.10');

// Paths (resolved relative to this file's location)
define('OUTPOST_DIR', __DIR__ . '/');
define('OUTPOST_DATA_DIR', OUTPOST_DIR . 'data/');
define('OUTPOST_DB_PATH', OUTPOST_DATA_DIR . 'cms.db');
define('OUTPOST_UPLOADS_DIR', OUTPOST_DIR . 'uploads/');
define('OUTPOST_CACHE_DIR', OUTPOST_DIR . 'cache/');
define('OUTPOST_ADMIN_DIR', OUTPOST_DIR . 'admin/');

// Limits
define('OUTPOST_MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 MB
define('OUTPOST_THUMB_WIDTH', 400);
define('OUTPOST_THUMB_HEIGHT', 300);
define('OUTPOST_MAX_IMAGE_WIDTH', 2400);
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

// Themes (code editor)
define('OUTPOST_THEMES_DIR', OUTPOST_DIR . 'themes/');
define('OUTPOST_CODE_EXTENSIONS', ['php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'svg', 'txt', 'md', 'yml', 'yaml']);
