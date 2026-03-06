<?php
/**
 * Outpost CMS — REST API
 * All endpoints: api.php?action=<endpoint>
 */

// Prevent PHP warnings/notices/deprecations from corrupting JSON output
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('log_errors', '1');
ob_start(); // Buffer any stray output so it doesn't corrupt JSON

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sanitizer.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/http-security.php';
require_once __DIR__ . '/code-editor.php';
require_once __DIR__ . '/themes.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/webhooks.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/channels.php';

header('Content-Type: application/json; charset=utf-8');

// GitHub repository for auto-updater
define('OUTPOST_GITHUB_REPO', 'tonyshawjr/outpost');

// ── CORS for dev ─────────────────────────────────────────
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Route ────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Public routes
if ($action === 'auth/login' && $method === 'POST') {
    handle_login();
    exit;
}

// Public: forgot / reset password (no auth required)
if ($action === 'auth/forgot' && $method === 'POST') {
    handle_forgot_password();
    exit;
}
if ($action === 'auth/reset' && $method === 'POST') {
    handle_reset_password();
    exit;
}

// Public content API (no auth required)
if (str_starts_with($action, 'content/')) {
    require_once __DIR__ . '/content-api.php';
    handle_content_request($action, $method);
    exit;
}

// Public cron endpoint — key-authenticated, no session required
if ($action === 'cron' && in_array($method, ['GET', 'POST'])) {
    require_once __DIR__ . '/engine.php';
    $provided = $_GET['key'] ?? (get_json_body()['key'] ?? '');
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'cron_key'");
    if (!$row || empty($row['value']) || !hash_equals($row['value'], $provided)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid cron key']);
        exit;
    }
    outpost_auto_publish_scheduled();
    ensure_webhooks_tables();
    webhook_process_retries();
    webhook_cleanup_deliveries();
    json_response(['success' => true]);
    exit;
}

// Public: TOTP verification (user passed password, needs 2FA code)
if ($action === 'auth/totp/verify' && $method === 'POST') {
    handle_totp_verify();
    exit;
}

// Auth check for all other routes
OutpostAuth::requireAuth();

// CSRF check on mutations (skip for API key auth — CSRF only protects browser sessions)
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!OutpostAuth::isApiKeyAuth()) {
        if ($action !== 'media/upload' && $action !== 'import/wordpress' && $action !== 'backup/restore') {
            OutpostAuth::validateCsrf();
        }
    }
    // Rate-limit all authenticated mutations (60/min default)
    OutpostAuth::checkApiRateLimit();
}

// Run migrations
ensure_users_columns();
ensure_activity_log_table();
require_once __DIR__ . '/engine.php';
ensure_fields_theme_column();
ensure_menus_table();
ensure_form_submissions_table_api();
ensure_form_configs_table();
ensure_forms_builder_table();
ensure_form_submissions_extra_columns();
ensure_pages_status_column();
ensure_revisions_table();
ensure_api_keys_table();
ensure_webhooks_tables();
ensure_channels_tables();
ensure_totp_columns();
ensure_user_collection_grants_table();
ensure_pages_locked_column();
cleanup_ghost_collection_pages();
require_once __DIR__ . '/mailer.php';

// ── Permission pre-flight ────────────────────────────────
$action_prefix = explode('/', $action)[0] ?? '';
$cap_map = [
    'settings' => 'settings.*',
    'users' => 'users.*',
    'code' => 'code.*',
    'cache' => 'cache.*',
    'members' => 'members.*',
    'themes' => 'settings.*',
    'sync'    => 'settings.*',
    'cron'    => 'settings.*',
    'apikeys' => 'settings.*',
    'webhooks' => 'settings.*',
    'channels' => 'settings.*',
    'updates'  => 'settings.*',
    'backup'   => 'settings.*',
];
if (isset($cap_map[$action_prefix])) {
    outpost_require_cap($cap_map[$action_prefix]);
}

// ── Route map ────────────────────────────────────────────
match (true) {
    // Auth
    $action === 'auth/logout' && $method === 'POST' => handle_logout(),
    $action === 'auth/me' && $method === 'GET' => handle_me(),

    // 2FA (authenticated)
    $action === 'auth/totp/setup'        && $method === 'POST' => handle_totp_setup(),
    $action === 'auth/totp/enable'       && $method === 'POST' => handle_totp_enable(),
    $action === 'auth/totp/disable'      && $method === 'POST' => handle_totp_disable(),
    $action === 'auth/totp/backup-codes' && $method === 'POST' => handle_totp_backup_codes(),
    $action === 'auth/totp/status'       && $method === 'GET'  => handle_totp_status(),

    // Pages
    $action === 'pages' && $method === 'GET' && !isset($_GET['id']) => handle_pages_list(),
    $action === 'pages' && $method === 'GET' && isset($_GET['id']) => handle_page_get(),
    $action === 'pages' && $method === 'PUT' && isset($_GET['id']) => handle_page_update(),
    $action === 'pages' && $method === 'DELETE' && isset($_GET['id']) => handle_page_delete(),

    // Fields
    $action === 'fields/bulk' && $method === 'PUT' => handle_fields_bulk_update(),

    // Global fields
    $action === 'globals' && $method === 'GET' => handle_globals_get(),

    // Collections
    $action === 'collections' && $method === 'GET' && !isset($_GET['id']) => handle_collections_list(),
    $action === 'collections' && $method === 'POST' => handle_collection_create(),
    $action === 'collections' && $method === 'PUT' && isset($_GET['id']) => handle_collection_update(),
    $action === 'collections' && $method === 'DELETE' && isset($_GET['id']) => handle_collection_delete(),

    // Collection Items
    $action === 'items' && $method === 'GET' && isset($_GET['collection']) => handle_items_list(),
    $action === 'items' && $method === 'POST' => handle_item_create(),
    $action === 'items' && $method === 'PUT' && isset($_GET['id']) => handle_item_update(),
    $action === 'items' && $method === 'DELETE' && isset($_GET['id']) => handle_item_delete(),
    $action === 'items/inline' && $method === 'PUT' => handle_item_inline_update(),
    $action === 'items/bulk-status' && $method === 'PUT' => handle_items_bulk_status(),
    $action === 'items/bulk-delete' && $method === 'DELETE' => handle_items_bulk_delete(),
    $action === 'items/preview-token' && $method === 'POST' => handle_item_preview_token(),

    // Media
    $action === 'media' && $method === 'GET' => handle_media_list(),
    $action === 'media/upload' && $method === 'POST' => handle_media_upload(),
    $action === 'media' && $method === 'PUT' && isset($_GET['id']) => handle_media_update(),
    $action === 'media/transform' && $method === 'POST' => handle_media_transform(),
    $action === 'media' && $method === 'DELETE' && isset($_GET['id']) => handle_media_delete(),

    // Settings
    $action === 'settings' && $method === 'GET' => handle_settings_get(),
    $action === 'settings' && $method === 'PUT' => handle_settings_update(),

    // Cache
    $action === 'cache/clear' && $method === 'POST' => handle_cache_clear(),

    // Users
    $action === 'users' && $method === 'GET' && isset($_GET['id']) => handle_user_get(),
    $action === 'users' && $method === 'GET' && !isset($_GET['id']) => handle_users_list(),
    $action === 'users' && $method === 'POST' => handle_user_create(),
    $action === 'users' && $method === 'PUT' && isset($_GET['id']) => handle_user_update(),
    $action === 'users' && $method === 'DELETE' && isset($_GET['id']) => handle_user_delete(),
    $action === 'users/grants' && $method === 'GET' => handle_user_grants_get(),
    $action === 'users/grants' && $method === 'PUT' => handle_user_grants_set(),

    // Code Editor
    $action === 'code/files'   && $method === 'GET'    => handle_code_files(),
    $action === 'code/read'    && $method === 'GET'    => handle_code_read(),
    $action === 'code/write'   && $method === 'PUT'    => handle_code_write(),
    $action === 'code/create'  && $method === 'POST'   => handle_code_create(),
    $action === 'code/rename'  && $method === 'POST'   => handle_code_rename(),
    $action === 'code/delete'  && $method === 'DELETE' => handle_code_delete(),
    $action === 'code/search'  && $method === 'GET'    => handle_code_search(),
    $action === 'code/context' && $method === 'GET'    => handle_code_context(),

    // Member Admin
    $action === 'members' && $method === 'GET' => handle_members_list(),
    $action === 'members' && $method === 'PUT' && isset($_GET['id']) => handle_member_update(),
    $action === 'members' && $method === 'DELETE' && isset($_GET['id']) => handle_member_delete(),

    // Themes
    $action === 'themes' && $method === 'GET' && !isset($_GET['slug']) => handle_themes_list(),
    $action === 'themes/activate' && $method === 'PUT' => handle_theme_activate(),
    $action === 'themes/duplicate' && $method === 'POST' => handle_theme_duplicate(),
    $action === 'themes' && $method === 'DELETE' && isset($_GET['slug']) => handle_theme_delete(),

    // Dashboard
    $action === 'dashboard/stats'    && $method === 'GET' => handle_dashboard_stats(),
    $action === 'dashboard/activity' && $method === 'GET' => handle_dashboard_activity(),

    // Analytics
    $action === 'dashboard/analytics' && $method === 'GET' => handle_analytics(),
    $action === 'dashboard/seo'       && $method === 'GET' => handle_analytics_seo(),
    $action === 'dashboard/content'   && $method === 'GET' => handle_analytics_content(),
    $action === 'dashboard/members'   && $method === 'GET' => handle_analytics_members(),

    // Events analytics
    $action === 'dashboard/events'        && $method === 'GET' => handle_analytics_events(),
    $action === 'dashboard/events/detail' && $method === 'GET' => handle_analytics_events_detail(),

    // Goals
    $action === 'goals' && $method === 'GET'    && !isset($_GET['id']) => handle_goals_list(),
    $action === 'goals' && $method === 'POST'                          => handle_goals_create(),
    $action === 'goals' && $method === 'PUT'    && isset($_GET['id'])  => handle_goals_update(),
    $action === 'goals' && $method === 'DELETE' && isset($_GET['id'])  => handle_goals_delete(),
    $action === 'dashboard/goals' && $method === 'GET'                 => handle_analytics_goals(),

    // Sync settings (admin+ only, gated by cap_map above)
    $action === 'sync/key' && $method === 'GET'          => handle_sync_key_get(),
    $action === 'sync/key/regenerate' && $method === 'POST' => handle_sync_key_regenerate(),

    // Cron key management (admin+)
    $action === 'cron/key' && $method === 'GET'             => handle_cron_key_get(),
    $action === 'cron/key/regenerate' && $method === 'POST' => handle_cron_key_regenerate(),

    // Folders (formerly Taxonomies)
    $action === 'folders' && $method === 'GET' && isset($_GET['id']) => handle_folder_get(),
    $action === 'folders' && $method === 'GET' && !isset($_GET['collection_id']) => handle_folders_list_all(),
    $action === 'folders' && $method === 'GET' && isset($_GET['collection_id']) => handle_folders_list(),
    $action === 'folders' && $method === 'POST' => handle_folder_create(),
    $action === 'folders' && $method === 'PUT' && isset($_GET['id']) => handle_folder_update(),
    $action === 'folders' && $method === 'DELETE' && isset($_GET['id']) => handle_folder_delete(),
    // Legacy taxonomy routes
    $action === 'taxonomies' && $method === 'GET' && isset($_GET['id']) => handle_folder_get(),
    $action === 'taxonomies' && $method === 'GET' && !isset($_GET['collection_id']) => handle_folders_list_all(),
    $action === 'taxonomies' && $method === 'GET' && isset($_GET['collection_id']) => handle_folders_list(),
    $action === 'taxonomies' && $method === 'POST' => handle_folder_create(),
    $action === 'taxonomies' && $method === 'PUT' && isset($_GET['id']) => handle_folder_update(),
    $action === 'taxonomies' && $method === 'DELETE' && isset($_GET['id']) => handle_folder_delete(),

    // Labels (formerly Terms)
    $action === 'labels' && $method === 'GET' && isset($_GET['id']) => handle_label_get(),
    $action === 'labels' && $method === 'GET' && (isset($_GET['folder_id']) || isset($_GET['taxonomy_id'])) => handle_labels_list(),
    $action === 'labels' && $method === 'POST' => handle_label_create(),
    $action === 'labels' && $method === 'PUT' && isset($_GET['id']) => handle_label_update(),
    $action === 'labels' && $method === 'DELETE' && isset($_GET['id']) => handle_label_delete(),
    // Legacy term routes
    $action === 'terms' && $method === 'GET' && isset($_GET['id']) => handle_label_get(),
    $action === 'terms' && $method === 'GET' && (isset($_GET['folder_id']) || isset($_GET['taxonomy_id'])) => handle_labels_list(),
    $action === 'terms' && $method === 'POST' => handle_label_create(),
    $action === 'terms' && $method === 'PUT' && isset($_GET['id']) => handle_label_update(),
    $action === 'terms' && $method === 'DELETE' && isset($_GET['id']) => handle_label_delete(),

    // Item Labels (formerly Item Terms)
    $action === 'item-labels' && $method === 'GET' && isset($_GET['item_id']) => handle_item_labels_get(),
    $action === 'item-labels' && $method === 'PUT' && isset($_GET['item_id']) => handle_item_labels_set(),
    // Legacy item-terms routes
    $action === 'item-terms' && $method === 'GET' && isset($_GET['item_id']) => handle_item_labels_get(),
    $action === 'item-terms' && $method === 'PUT' && isset($_GET['item_id']) => handle_item_labels_set(),

    // Import
    $action === 'import/wordpress' && $method === 'POST' => handle_wordpress_import(),

    // Webhooks
    $action === 'webhooks' && $method === 'GET' && !isset($_GET['id'])       => handle_webhooks_list(),
    $action === 'webhooks' && $method === 'GET' && isset($_GET['id'])        => handle_webhook_get(),
    $action === 'webhooks' && $method === 'POST'                             => handle_webhook_create(),
    $action === 'webhooks' && $method === 'PUT' && isset($_GET['id'])        => handle_webhook_update(),
    $action === 'webhooks' && $method === 'DELETE' && isset($_GET['id'])     => handle_webhook_delete(),
    $action === 'webhooks/regenerate-secret' && $method === 'POST'           => handle_webhook_regenerate_secret(),
    $action === 'webhooks/deliveries' && $method === 'GET'                   => handle_webhook_deliveries(),
    $action === 'webhooks/test' && $method === 'POST'                        => handle_webhook_test(),

    // Menus (Navigation)
    $action === 'menus' && $method === 'GET' && !isset($_GET['id']) => handle_menus_list(),
    $action === 'menus' && $method === 'GET' && isset($_GET['id']) => handle_menu_get(),
    $action === 'menus' && $method === 'POST' => handle_menu_create(),
    $action === 'menus' && $method === 'PUT' && isset($_GET['id']) => handle_menu_update(),
    $action === 'menus' && $method === 'DELETE' && isset($_GET['id']) => handle_menu_delete(),

    // Forms / Submissions
    $action === 'forms' && $method === 'GET' => handle_forms_list(),
    $action === 'forms/submissions' && $method === 'GET' => handle_form_submissions(),
    $action === 'forms/submissions' && $method === 'PUT' && isset($_GET['id']) => handle_form_submission_read(),
    $action === 'forms/submissions' && $method === 'DELETE' && isset($_GET['id']) => handle_form_submission_delete(),
    $action === 'forms/submissions/star' && $method === 'PUT' && isset($_GET['id']) => handle_form_submission_star(),
    $action === 'forms/submissions/status' && $method === 'PUT' && isset($_GET['id']) => handle_form_submission_status(),
    $action === 'forms/submissions/notes' && $method === 'PUT' && isset($_GET['id']) => handle_form_submission_notes(),
    $action === 'forms/submissions/bulk' && $method === 'POST' => handle_form_submissions_bulk(),
    $action === 'forms/test-smtp' && $method === 'POST' => handle_test_smtp(),
    $action === 'forms/config' && $method === 'GET' => handle_form_config_get(),
    $action === 'forms/config' && $method === 'PUT' => handle_form_config_set(),
    $action === 'forms/export' && $method === 'GET' => handle_forms_export(),

    // Form Builder
    $action === 'forms/builder' && $method === 'GET' && !isset($_GET['id']) => handle_forms_builder_list(),
    $action === 'forms/builder' && $method === 'GET' && isset($_GET['id']) => handle_forms_builder_get(),
    $action === 'forms/builder' && $method === 'POST' => handle_forms_builder_create(),
    $action === 'forms/builder' && $method === 'PUT' && isset($_GET['id']) => handle_forms_builder_update(),
    $action === 'forms/builder' && $method === 'DELETE' && isset($_GET['id']) => handle_forms_builder_delete(),
    $action === 'forms/builder/duplicate' && $method === 'POST' && isset($_GET['id']) => handle_forms_builder_duplicate(),

    // Channels
    $action === 'channels' && $method === 'GET' && !isset($_GET['id'])    => handle_channels_list(),
    $action === 'channels' && $method === 'GET' && isset($_GET['id'])     => handle_channel_get(),
    $action === 'channels' && $method === 'POST'                          => handle_channel_create(),
    $action === 'channels' && $method === 'PUT' && isset($_GET['id'])     => handle_channel_update(),
    $action === 'channels' && $method === 'DELETE' && isset($_GET['id'])  => handle_channel_delete(),
    $action === 'channels/sync' && $method === 'POST'                     => handle_channel_sync(),
    $action === 'channels/sync-log' && $method === 'GET'                  => handle_channel_sync_log(),
    $action === 'channels/discover' && $method === 'POST'                 => handle_channel_discover(),
    $action === 'channels/items' && $method === 'GET'                     => handle_channel_items(),

    // Search
    $action === 'search/content' && $method === 'GET' => handle_search_content(),

    // Revisions
    $action === 'revisions' && $method === 'GET' => handle_revisions_list(),
    $action === 'revisions/diff' && $method === 'GET' => handle_revision_diff(),
    $action === 'revisions/restore' && $method === 'POST' => handle_revision_restore(),

    // API Keys (admin+, gated by cap_map)
    $action === 'apikeys' && $method === 'GET' => handle_apikeys_list(),
    $action === 'apikeys' && $method === 'POST' => handle_apikey_create(),
    $action === 'apikeys' && $method === 'DELETE' && isset($_GET['id']) => handle_apikey_delete(),

    // Backup & Restore
    $action === 'backup/create'   && $method === 'POST'   => handle_backup_create(),
    $action === 'backup/list'     && $method === 'GET'    => handle_backup_list(),
    $action === 'backup/download' && $method === 'GET'    => handle_backup_download(),
    $action === 'backup/restore'  && $method === 'POST'   => handle_backup_restore(),
    $action === 'backup/delete'   && $method === 'DELETE'  => handle_backup_delete(),
    $action === 'backup/settings' && in_array($method, ['GET', 'PUT']) => handle_backup_settings(),

    // Updates (admin only)
    $action === 'updates/check' && $method === 'GET' => handle_updates_check(),
    $action === 'updates/apply' && $method === 'POST' => handle_updates_apply(),

    default => json_error('Not found', 404),
};

// ── Helpers ──────────────────────────────────────────────
function json_response(mixed $data, int $code = 200): void {
    // Discard any stray output (PHP warnings, deprecation notices, etc.)
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void {
    json_response(['error' => $message], $code);
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Invalid JSON body');
    return $data;
}

// ── Ghost-page helpers ───────────────────────────────────

/**
 * Compute the public URL path for a collection item given its slug and
 * the collection row (which has url_pattern and slug columns).
 */
function collection_item_url_path(string $item_slug, array $collection): string {
    $pattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
    return str_replace('{slug}', $item_slug, $pattern);
}

/**
 * Delete a single ghost page row by path (fields cascade via FK).
 */
function purge_ghost_page(string $path): void {
    OutpostDB::query(
        "DELETE FROM pages WHERE path = ? AND path != '__global__'",
        [$path]
    );
}

/**
 * Delete all ghost page rows whose path starts with the given prefix.
 * Collection items always live in collection_items.data — their
 * "pages" rows are only auto-discovered rendering ghosts, so we
 * delete unconditionally (fields cascade via FK).
 */
function purge_ghost_pages_with_prefix(string $prefix): void {
    if (!$prefix || $prefix === '/') return;
    OutpostDB::query(
        "DELETE FROM pages WHERE path LIKE ? AND path != '__global__'",
        [$prefix . '%']
    );
}

/**
 * Startup cleanup: remove all auto-discovered ghost pages.
 *
 * Real pages always have rows in the `fields` table (registered when the
 * template engine renders cms_text(), cms_image(), etc.).
 * Collection item pages are rendered from collection_items.data JSON and
 * never touch the fields table — so they are unambiguously ghosts.
 *
 * Only paths with 2+ segments (e.g. /post/slug) are targeted; top-level
 * pages (/about, /contact) are left alone even if they have no field data
 * yet (e.g. a freshly created page that hasn't been saved in the editor).
 */
function cleanup_ghost_collection_pages(): void {
    OutpostDB::query(
        "DELETE FROM pages
         WHERE path != '__global__'
           AND path LIKE '/%/%'
           AND id NOT IN (SELECT DISTINCT page_id FROM fields WHERE page_id IS NOT NULL)"
    );
}

// ── Auth Handlers ────────────────────────────────────────
function handle_forgot_password(): void {
    outpost_ip_rate_limit('forgot', 5, 300); // 5 per 5 minutes

    require_once __DIR__ . '/mailer.php';
    ensure_users_columns();

    $data = get_json_body();
    $email = trim($data['email'] ?? '');

    // Always return 200 — never reveal whether an address exists
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => true]);
        return;
    }

    $user = OutpostDB::fetchOne(
        "SELECT id, username, email FROM users WHERE email = ? AND role IN ('super_admin','admin','developer','editor')",
        [$email]
    );

    if ($user && $user['email']) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        OutpostDB::update('users', [
            'reset_token'         => $token,
            'reset_token_expires' => $expires,
        ], 'id = ?', [$user['id']]);

        $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = "{$scheme}://{$host}/outpost/?reset_token={$token}";

        $subject  = 'Reset your Outpost password';
        $name     = htmlspecialchars($user['username']);
        $text     = "Hi {$name},\n\nYou requested a password reset. Use the link below to set a new password. The link expires in 1 hour.\n\n{$resetUrl}\n\nIf you didn't request this, you can safely ignore this email.\n";
        $html     = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#1a1a1a;max-width:520px;margin:40px auto;padding:0 24px'>"
                  . "<h2 style='font-size:1.25rem;margin-bottom:8px'>Reset your password</h2>"
                  . "<p style='color:#525252;margin-bottom:24px'>Hi {$name}, you requested a password reset. Click the button below — the link expires in 1 hour.</p>"
                  . "<a href='" . htmlspecialchars($resetUrl) . "' style='display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600'>Reset password</a>"
                  . "<p style='color:#737373;font-size:0.8rem;margin-top:24px'>Or copy this link: " . htmlspecialchars($resetUrl) . "</p>"
                  . "<p style='color:#737373;font-size:0.8rem'>If you didn't request this, you can safely ignore this email.</p>"
                  . "</body></html>";

        try {
            $mailer = OutpostMailer::fromSettings();
            $mailer->send($user['email'], $subject, $text, $html);
        } catch (Exception $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
        }
    }

    json_response(['success' => true]);
}

function handle_reset_password(): void {
    outpost_ip_rate_limit('reset', 10, 300); // 10 per 5 minutes

    ensure_users_columns();

    $data     = get_json_body();
    $token    = trim($data['token'] ?? '');
    $password = $data['password'] ?? '';

    if (!$token) {
        json_error('Reset token required');
    }
    if (strlen($password) < 8) {
        json_error('Password must be at least 8 characters');
    }

    $user = OutpostDB::fetchOne(
        "SELECT id FROM users
         WHERE reset_token = ? AND reset_token_expires > datetime('now')
           AND role IN ('super_admin','admin','developer','editor')",
        [$token]
    );

    if (!$user) {
        json_error('Reset link is invalid or has expired', 400);
    }

    require_once __DIR__ . '/auth.php';
    OutpostDB::query(
        "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?",
        [OutpostAuth::hashPassword($password), $user['id']]
    );

    json_response(['success' => true]);
}

function handle_login(): void {
    $data = get_json_body();
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (!$username || !$password) {
        json_error('Username and password required');
    }

    OutpostAuth::init();

    // Rate limiting
    if (OutpostAuth::isRateLimited()) {
        json_error('Too many login attempts. Please wait.', 429);
    }

    $user = OutpostDB::fetchOne('SELECT * FROM users WHERE username = ?', [$username]);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        OutpostAuth::recordFailedAttempt();
        json_error('Invalid username or password.', 401);
    }

    // Block member roles from admin login
    if (!outpost_is_internal_role($user['role'])) {
        json_error('This login is for site administrators only.', 401);
    }

    // If 2FA is enabled, return a signed token instead of creating a session
    if (!empty($user['totp_enabled'])) {
        $totpToken = OutpostTOTP::createTotpToken((int)$user['id']);
        json_response([
            'success' => true,
            'requires_2fa' => true,
            'totp_token' => $totpToken,
        ]);
        return;
    }

    // No 2FA — create session directly
    $result = OutpostAuth::createSession((int)$user['id']);
    if ($result['success']) {
        json_response($result);
    } else {
        json_error($result['error'], 500);
    }
}

function handle_logout(): void {
    OutpostAuth::logout();
    json_response(['success' => true]);
}

function handle_me(): void {
    $sessionUser = OutpostAuth::currentUser();
    if (!$sessionUser) {
        json_response(['user' => null, 'csrf_token' => OutpostAuth::csrfToken()]);
        return;
    }
    // Fetch fresh data from DB so display_name/avatar are always current
    $user = OutpostDB::fetchOne(
        'SELECT id, username, email, display_name, avatar, bio, role, totp_enabled FROM users WHERE id = ?',
        [$sessionUser['id']]
    );
    if ($user) {
        $user['totp_enabled'] = (bool)($user['totp_enabled'] ?? 0);
    }

    // Include collection grants for editors
    $collection_grants = null;
    if ($user && $user['role'] === 'editor') {
        $grants = OutpostDB::fetchAll(
            'SELECT collection_id FROM user_collection_grants WHERE user_id = ?',
            [(int) $user['id']]
        );
        $collection_grants = empty($grants) ? null : array_map(fn($g) => (int) $g['collection_id'], $grants);
    }

    $response = [
        'user' => $user,
        'csrf_token' => OutpostAuth::csrfToken(),
        'version' => OUTPOST_VERSION,
        'collection_grants' => $collection_grants,
    ];

    // Include update status for admin/super_admin users
    if ($user && in_array($user['role'], ['admin', 'super_admin'])) {
        $updateStatus = outpost_check_update_status();
        $response['update_available'] = $updateStatus['update_available'];
        $response['latest_version'] = $updateStatus['latest_version'];
    }

    json_response($response);
}

// ── Role Refinement Migrations ────────────────────────────

function ensure_user_collection_grants_table(): void {
    OutpostDB::connect()->exec("CREATE TABLE IF NOT EXISTS user_collection_grants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        collection_id INTEGER NOT NULL,
        UNIQUE(user_id, collection_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
    )");
}

function ensure_pages_locked_column(): void {
    $cols = OutpostDB::fetchAll("PRAGMA table_info(pages)");
    $existing = array_column($cols, 'name');
    if (!in_array('locked', $existing)) {
        OutpostDB::connect()->exec("ALTER TABLE pages ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
    }
}

/**
 * Require that a page is unlocked for the current user, or return 403.
 * Admins and super_admins always pass.
 */
function require_page_unlocked(int $page_id): void {
    $role = $_SESSION['outpost_role'] ?? '';
    if (in_array($role, ['super_admin', 'admin'], true)) return;

    $page = OutpostDB::fetchOne('SELECT locked FROM pages WHERE id = ?', [$page_id]);
    if ($page && (int) $page['locked'] === 1) {
        http_response_code(403);
        echo json_encode(['error' => 'This page is locked']);
        exit;
    }
}

// ── User Collection Grants ───────────────────────────────

function handle_user_grants_get(): void {
    $userId = (int) ($_GET['user_id'] ?? 0);
    if (!$userId) json_error('user_id required');

    $grants = OutpostDB::fetchAll(
        'SELECT collection_id FROM user_collection_grants WHERE user_id = ?',
        [$userId]
    );

    json_response(['grants' => array_map(fn($g) => (int) $g['collection_id'], $grants)]);
}

function handle_user_grants_set(): void {
    $userId = (int) ($_GET['user_id'] ?? 0);
    if (!$userId) json_error('user_id required');

    $targetUser = OutpostDB::fetchOne('SELECT role FROM users WHERE id = ?', [$userId]);
    if (!$targetUser) json_error('User not found', 404);
    if ($targetUser['role'] !== 'editor') json_error('Grants only apply to editors');

    $data = get_json_body();
    $collectionIds = $data['collection_ids'] ?? [];
    if (!is_array($collectionIds)) json_error('collection_ids must be an array');

    // Validate each collection exists
    foreach ($collectionIds as $cid) {
        $coll = OutpostDB::fetchOne('SELECT id FROM collections WHERE id = ?', [(int) $cid]);
        if (!$coll) json_error("Collection ID {$cid} not found", 404);
    }

    // Replace all grants
    OutpostDB::exec('DELETE FROM user_collection_grants WHERE user_id = ?', [$userId]);
    foreach ($collectionIds as $cid) {
        OutpostDB::insert('user_collection_grants', [
            'user_id' => $userId,
            'collection_id' => (int) $cid,
        ]);
    }

    json_response(['success' => true]);
}

// ── TOTP Migration & Handlers ────────────────────────────

function ensure_totp_columns(): void {
    $cols = OutpostDB::fetchAll("PRAGMA table_info(users)");
    $existing = array_column($cols, 'name');

    if (!in_array('totp_secret', $existing)) {
        OutpostDB::connect()->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT");
    }
    if (!in_array('totp_enabled', $existing)) {
        OutpostDB::connect()->exec("ALTER TABLE users ADD COLUMN totp_enabled INTEGER DEFAULT 0");
    }
    if (!in_array('backup_codes', $existing)) {
        OutpostDB::connect()->exec("ALTER TABLE users ADD COLUMN backup_codes TEXT");
    }
}

function handle_totp_verify(): void {
    // IP-based rate limit — 5 attempts per 5 minutes (public endpoint)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $bucket = $ip . ':totp_verify';
    $now = time();
    $window = $now - 300;
    OutpostDB::connect()->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        ip TEXT PRIMARY KEY, attempts TEXT DEFAULT '[]', updated_at INTEGER
    )");
    $rlRow = OutpostDB::fetchOne('SELECT attempts FROM login_rate_limits WHERE ip = ?', [$bucket]);
    $rlAttempts = $rlRow ? json_decode($rlRow['attempts'], true) : [];
    $rlAttempts = array_values(array_filter($rlAttempts, fn($t) => $t > $window));
    if (count($rlAttempts) >= 5) {
        http_response_code(429);
        header('Retry-After: 300');
        echo json_encode(['error' => 'Too many attempts. Please wait.']);
        exit;
    }

    $data = get_json_body();
    $code = trim($data['code'] ?? '');
    $totpToken = $data['totp_token'] ?? '';
    $isBackup = !empty($data['is_backup']);

    if (!$totpToken) {
        json_error('TOTP token required', 400);
    }

    $userId = OutpostTOTP::verifyTotpToken($totpToken);
    if (!$userId) {
        json_error('Session expired. Please sign in again.', 401);
    }

    $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        json_error('User not found', 401);
    }

    // Verify nonce matches (single-use token)
    $tokenNonce = OutpostTOTP::extractNonce($totpToken);
    if ($tokenNonce !== null && ($user['totp_token_nonce'] ?? '') !== $tokenNonce) {
        json_error('Session expired. Please sign in again.', 401);
    }

    if ($isBackup) {
        // Verify backup code
        $index = OutpostTOTP::verifyBackupCode($code, $user['backup_codes'] ?? '[]');
        if ($index === -1) {
            // Record failed attempt for rate limiting
            $rlAttempts[] = $now;
            OutpostDB::query(
                "INSERT INTO login_rate_limits (ip, attempts, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT(ip) DO UPDATE SET attempts = excluded.attempts, updated_at = excluded.updated_at",
                [$bucket, json_encode($rlAttempts), $now]
            );
            json_error('Invalid backup code', 401);
        }
        // Consume the used backup code
        $updatedCodes = OutpostTOTP::consumeBackupCode($user['backup_codes'], $index);
        OutpostDB::update('users', ['backup_codes' => $updatedCodes], 'id = ?', [$userId]);
    } else {
        // Verify TOTP code
        if (!$code) {
            json_error('Authentication code required', 400);
        }
        if (!OutpostTOTP::verifyCode($user['totp_secret'], $code)) {
            // Record failed attempt for rate limiting
            $rlAttempts[] = $now;
            OutpostDB::query(
                "INSERT INTO login_rate_limits (ip, attempts, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT(ip) DO UPDATE SET attempts = excluded.attempts, updated_at = excluded.updated_at",
                [$bucket, json_encode($rlAttempts), $now]
            );
            json_error('Invalid authentication code', 401);
        }

        // Replay prevention — reject reused TOTP codes
        if (($user['totp_last_code'] ?? '') === $code) {
            json_error('Code already used. Wait for the next code.', 401);
        }
        OutpostDB::update('users', ['totp_last_code' => $code], 'id = ?', [$userId]);
    }

    // Invalidate the single-use token nonce
    OutpostDB::update('users', ['totp_token_nonce' => null], 'id = ?', [$userId]);

    // Code verified — create session
    $result = OutpostAuth::createSession($userId);
    if ($result['success']) {
        json_response($result);
    } else {
        json_error($result['error'], 500);
    }
}

function handle_totp_setup(): void {
    $sessionUser = OutpostAuth::currentUser();
    if (!$sessionUser) json_error('Not authenticated', 401);

    $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$sessionUser['id']]);
    if (!$user) json_error('User not found', 404);

    // Generate a new secret (not yet enabled)
    $secret = OutpostTOTP::generateSecret();
    OutpostDB::update('users', ['totp_secret' => $secret], 'id = ?', [$user['id']]);

    $uri = OutpostTOTP::buildUri($secret, $user['username']);

    json_response([
        'secret' => $secret,
        'uri' => $uri,
    ]);
}

function handle_totp_enable(): void {
    $sessionUser = OutpostAuth::currentUser();
    if (!$sessionUser) json_error('Not authenticated', 401);

    $data = get_json_body();
    $code = trim($data['code'] ?? '');

    $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$sessionUser['id']]);
    if (!$user || empty($user['totp_secret'])) {
        json_error('Run setup first', 400);
    }

    if (!OutpostTOTP::verifyCode($user['totp_secret'], $code)) {
        json_error('Invalid code. Make sure your authenticator app is synced.', 400);
    }

    // Generate backup codes
    $backupCodes = OutpostTOTP::generateBackupCodes();
    $hashedCodes = OutpostTOTP::hashBackupCodes($backupCodes);

    OutpostDB::update('users', [
        'totp_enabled' => 1,
        'backup_codes' => $hashedCodes,
    ], 'id = ?', [$user['id']]);

    json_response([
        'success' => true,
        'backup_codes' => $backupCodes,
    ]);
}

function handle_totp_disable(): void {
    $sessionUser = OutpostAuth::currentUser();
    if (!$sessionUser) json_error('Not authenticated', 401);

    $data = get_json_body();
    $password = $data['password'] ?? '';

    if (!$password) {
        json_error('Password required', 400);
    }

    $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$sessionUser['id']]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Incorrect password', 401);
    }

    OutpostDB::update('users', [
        'totp_secret' => null,
        'totp_enabled' => 0,
        'backup_codes' => null,
    ], 'id = ?', [$user['id']]);

    json_response(['success' => true]);
}

function handle_totp_backup_codes(): void {
    $sessionUser = OutpostAuth::currentUser();
    if (!$sessionUser) json_error('Not authenticated', 401);

    $data = get_json_body();
    $password = $data['password'] ?? '';

    if (!$password) {
        json_error('Password required', 400);
    }

    $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$sessionUser['id']]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Incorrect password', 401);
    }

    if (!$user['totp_enabled']) {
        json_error('2FA is not enabled', 400);
    }

    $backupCodes = OutpostTOTP::generateBackupCodes();
    $hashedCodes = OutpostTOTP::hashBackupCodes($backupCodes);

    OutpostDB::update('users', ['backup_codes' => $hashedCodes], 'id = ?', [$user['id']]);

    json_response([
        'success' => true,
        'backup_codes' => $backupCodes,
    ]);
}

function handle_totp_status(): void {
    $sessionUser = OutpostAuth::currentUser();
    if (!$sessionUser) json_error('Not authenticated', 401);

    $user = OutpostDB::fetchOne('SELECT totp_enabled, backup_codes FROM users WHERE id = ?', [$sessionUser['id']]);
    if (!$user) json_error('User not found', 404);

    $remaining = 0;
    if ($user['backup_codes']) {
        $codes = json_decode($user['backup_codes'], true);
        $remaining = is_array($codes) ? count($codes) : 0;
    }

    json_response([
        'enabled' => (bool)$user['totp_enabled'],
        'backup_codes_remaining' => $remaining,
    ]);
}

// ── Page Handlers ────────────────────────────────────────
function handle_pages_list(): void {
    $search = $_GET['search'] ?? '';
    $activeTheme = get_active_theme();

    // Build list of collection URL prefixes to exclude
    $collections = OutpostDB::fetchAll('SELECT slug, url_pattern FROM collections');
    $prefixes = [];
    foreach ($collections as $c) {
        // Always exclude the default slug-based prefix
        $prefixes[] = '/' . $c['slug'] . '/';
        // Also exclude the custom url_pattern prefix (if different)
        if ($c['url_pattern']) {
            $prefix = explode('{slug}', $c['url_pattern'])[0];
            if ($prefix && $prefix !== '/' && !in_array($prefix, $prefixes)) {
                $prefixes[] = $prefix;
            }
        }
    }

    // Show pages that have fields for the active theme (scoped content).
    // Falls back to showing all pages if no theme-scoped fields exist yet.
    $hasThemeFields = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM fields WHERE theme = ?",
        [$activeTheme]
    );
    $useThemeFilter = ($hasThemeFields['c'] ?? 0) > 0;

    if ($useThemeFilter) {
        if ($search) {
            $pages = OutpostDB::fetchAll(
                "SELECT DISTINCT p.* FROM pages p JOIN fields f ON f.page_id = p.id
                 WHERE f.theme = ? AND p.path != '__global__' AND (p.path LIKE ? OR p.title LIKE ?)
                 ORDER BY p.updated_at DESC",
                [$activeTheme, "%{$search}%", "%{$search}%"]
            );
        } else {
            $pages = OutpostDB::fetchAll(
                "SELECT DISTINCT p.* FROM pages p JOIN fields f ON f.page_id = p.id
                 WHERE f.theme = ? AND p.path != '__global__'
                 ORDER BY p.updated_at DESC",
                [$activeTheme]
            );
        }
    } else {
        if ($search) {
            $pages = OutpostDB::fetchAll(
                "SELECT * FROM pages WHERE path != '__global__' AND (path LIKE ? OR title LIKE ?) ORDER BY updated_at DESC",
                ["%{$search}%", "%{$search}%"]
            );
        } else {
            $pages = OutpostDB::fetchAll(
                "SELECT * FROM pages WHERE path != '__global__' ORDER BY updated_at DESC"
            );
        }
    }

    // Filter out pages whose paths match a collection URL prefix or bare slug
    $collSlugs = array_map(fn($c) => '/' . $c['slug'], $collections);
    if ($prefixes || $collSlugs) {
        $pages = array_values(array_filter($pages, function($page) use ($prefixes, $collSlugs) {
            // Exclude bare collection slug pages (e.g. /post)
            if (in_array($page['path'], $collSlugs)) return false;
            // Exclude collection item ghost pages (e.g. /post/my-item)
            foreach ($prefixes as $prefix) {
                if (str_starts_with($page['path'], $prefix)) return false;
            }
            return true;
        }));
    }

    json_response(['pages' => $pages]);
}

function handle_page_get(): void {
    $id = (int) $_GET['id'];
    $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE id = ?', [$id]);
    if (!$page) json_error('Page not found', 404);

    $activeTheme = get_active_theme();

    // Load fields scoped to the active theme
    $fields = OutpostDB::fetchAll(
        'SELECT * FROM fields WHERE page_id = ? AND theme = ? ORDER BY sort_order ASC',
        [$id, $activeTheme]
    );

    // If no theme-scoped fields, fall back to legacy unscoped fields (pre-migration content)
    if (empty($fields)) {
        $fields = OutpostDB::fetchAll(
            "SELECT * FROM fields WHERE page_id = ? AND theme = '' ORDER BY sort_order ASC",
            [$id]
        );
    }

    // Merge in registry fields that have stubs but aren't in the theme-scoped fields yet
    // (covers edge case where registry scan ran but field stub wasn't created)
    $existingNames = array_column($fields, 'field_name');
    $registryFields = OutpostDB::fetchAll(
        'SELECT * FROM page_field_registry WHERE theme = ? AND path = ? ORDER BY sort_order ASC',
        [$activeTheme, $page['path']]
    );
    foreach ($registryFields as $rf) {
        if (!in_array($rf['field_name'], $existingNames)) {
            // Show as a scaffold entry — content is empty, default shown as placeholder
            $fields[] = [
                'id' => null,
                'page_id' => $id,
                'theme' => $activeTheme,
                'field_name' => $rf['field_name'],
                'field_type' => $rf['field_type'],
                'content' => '',
                'default_value' => $rf['default_value'],
                'options' => '',
                'sort_order' => $rf['sort_order'],
                'from_registry' => true,
            ];
        }
    }

    usort($fields, fn($a, $b) => ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0));

    // Filter out stale fields not in the registry (defense-in-depth)
    $registryNames = array_column($registryFields, 'field_name');
    if (!empty($registryNames)) {
        $fields = array_values(array_filter($fields, fn($f) =>
            !empty($f['from_registry']) || in_array($f['field_name'], $registryNames)
        ));
    }

    $page['fields'] = $fields;
    $page['active_theme'] = $activeTheme;
    json_response(['page' => $page]);
}

function handle_page_update(): void {
    $id = (int) $_GET['id'];
    $data = get_json_body();

    require_page_unlocked($id);

    $allowed = ['title', 'meta_title', 'meta_description', 'status', 'visibility', 'locked'];
    $update = [];
    foreach ($allowed as $key) {
        if (isset($data[$key])) {
            $update[$key] = $data[$key];
        }
    }

    // Validate visibility value
    if (isset($update['visibility']) && !in_array($update['visibility'], ['public', 'members', 'paid'])) {
        unset($update['visibility']);
    }

    // Only admin+ can set the locked field
    if (isset($update['locked'])) {
        $role = $_SESSION['outpost_role'] ?? '';
        if (in_array($role, ['super_admin', 'admin'], true)) {
            $update['locked'] = (int) $update['locked'] ? 1 : 0;
        } else {
            unset($update['locked']);
        }
    }

    $newTimestamp = date('Y-m-d H:i:s');
    $update['updated_at'] = $newTimestamp;

    // Set published_at once when first transitioning to published
    if (isset($data['status']) && $data['status'] === 'published') {
        $existing = OutpostDB::fetchOne('SELECT published_at FROM pages WHERE id = ?', [$id]);
        if (!$existing['published_at']) {
            $update['published_at'] = $newTimestamp;
        }
    }

    // Optimistic lock: reject if page was modified since client loaded it
    $clientVersion = $data['_version'] ?? null;
    if ($clientVersion) {
        $rows = OutpostDB::update('pages', $update, 'id = ? AND updated_at = ?', [$id, $clientVersion]);
        if ($rows === 0) {
            $current = OutpostDB::fetchOne('SELECT updated_at FROM pages WHERE id = ?', [$id]);
            if (!$current) json_error('Page not found', 404);
            json_response([
                'error' => 'This page was modified by another user. Reload to see the latest version.',
                'conflict' => true,
                'server_updated_at' => $current['updated_at'],
            ], 409);
        }
    } else {
        OutpostDB::update('pages', $update, 'id = ?', [$id]);
    }

    $page = OutpostDB::fetchOne('SELECT title, path FROM pages WHERE id = ?', [$id]);
    $label = $data['title'] ?? ($page['title'] ?: ($page['path'] ?? 'a page'));

    if (isset($data['status'])) {
        $action = $data['status'] === 'published' ? 'published' : 'set to draft';
        log_activity('content', '"' . $label . '" ' . $action);
    } else {
        log_activity('content', '"' . $label . '" updated');
    }

    // Clear page HTML cache
    if ($page && $page['path']) {
        outpost_clear_cache($page['path']);
    }

    // Dispatch webhook
    if (isset($data['status']) && $data['status'] === 'published') {
        dispatch_webhook('page.published', ['id' => $id, 'title' => $label, 'path' => $page['path'] ?? '']);
    } elseif (isset($data['status']) && $data['status'] === 'draft') {
        dispatch_webhook('page.unpublished', ['id' => $id, 'title' => $label, 'path' => $page['path'] ?? '']);
    } else {
        dispatch_webhook('page.updated', ['id' => $id, 'title' => $label, 'path' => $page['path'] ?? '']);
    }

    json_response(['success' => true, 'updated_at' => $newTimestamp]);
}

function handle_page_delete(): void {
    $id = (int) $_GET['id'];

    require_page_unlocked($id);

    $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE id = ?', [$id]);
    if (!$page) json_error('Page not found', 404);

    // Protect homepage and globals pseudo-page
    if ($page['path'] === '/') json_error('Cannot delete the homepage');
    if ($page['path'] === '__global__') json_error('Cannot delete globals');

    $label = $page['title'] ?: $page['path'];

    // Delete template file from active theme (with path containment check)
    $activeTheme = get_active_theme();
    $filename = ($page['path'] === '/') ? 'index.html' : ltrim($page['path'], '/') . '.html';
    $templatePath = OUTPOST_THEMES_DIR . $activeTheme . '/' . $filename;
    $themeBase = realpath(OUTPOST_THEMES_DIR . $activeTheme);
    $resolved = realpath($templatePath);
    if ($themeBase && $resolved && str_starts_with($resolved, $themeBase . '/') && file_exists($resolved)) {
        unlink($resolved);
    }

    // Delete DB row (fields cascade via FK)
    OutpostDB::exec('DELETE FROM pages WHERE id = ?', [$id]);

    // Clean up field registry
    OutpostDB::exec('DELETE FROM page_field_registry WHERE path = ?', [$page['path']]);

    outpost_clear_cache();
    log_activity('content', '"' . $label . '" deleted');
    dispatch_webhook('page.deleted', ['id' => $id, 'title' => $label, 'path' => $page['path']]);

    json_response(['success' => true]);
}

// ── Field Handlers ───────────────────────────────────────
function handle_fields_bulk_update(): void {
    $data = get_json_body();
    $fields = $data['fields'] ?? [];
    $pageVersions = $data['_page_versions'] ?? [];

    if (empty($fields)) json_error('No fields to update');

    // Collect affected page IDs
    $snapshot_page_ids = [];
    foreach ($fields as $f) {
        $fid = (int) ($f['id'] ?? 0);
        $fld = OutpostDB::fetchOne('SELECT page_id FROM fields WHERE id = ?', [$fid]);
        if ($fld) $snapshot_page_ids[$fld['page_id']] = true;
    }

    // Check page locks on all affected pages
    foreach (array_keys($snapshot_page_ids) as $pid) {
        require_page_unlocked((int) $pid);
    }

    // Optimistic lock: reject if any affected page was modified since client loaded it
    if (!empty($pageVersions)) {
        foreach (array_keys($snapshot_page_ids) as $pid) {
            $pidStr = (string) $pid;
            if (isset($pageVersions[$pidStr])) {
                $current = OutpostDB::fetchOne('SELECT updated_at FROM pages WHERE id = ?', [$pid]);
                if ($current && $current['updated_at'] !== $pageVersions[$pidStr]) {
                    json_response([
                        'error' => 'This content was modified by another user. Reload to see the latest version.',
                        'conflict' => true,
                        'server_updated_at' => $current['updated_at'],
                        'page_id' => $pid,
                    ], 409);
                }
            }
        }
    }

    $newTimestamp = date('Y-m-d H:i:s');
    $page_ids = [];
    foreach ($fields as $f) {
        $id = (int) ($f['id'] ?? 0);
        $content = $f['content'] ?? '';

        $field = OutpostDB::fetchOne('SELECT * FROM fields WHERE id = ?', [$id]);
        if (!$field) continue;

        if ($field['field_type'] === 'richtext') {
            $content = OutpostSanitizer::clean($content);
        }

        OutpostDB::update('fields', [
            'content' => $content,
            'updated_at' => $newTimestamp,
        ], 'id = ?', [$id]);

        $page_ids[$field['page_id']] = true;
    }

    // Create revisions AFTER update with the new state
    foreach (array_keys($snapshot_page_ids) as $pid) {
        $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE id = ?', [$pid]);
        if (!$page || $page['path'] === '__global__') continue;
        $activeTheme = get_active_theme();
        $allFields = OutpostDB::fetchAll(
            "SELECT field_name, content FROM fields WHERE page_id = ? AND (theme = ? OR theme = '') ORDER BY theme DESC",
            [$pid, $activeTheme]
        );
        // Dedupe: prefer theme-scoped over unscoped
        $fieldData = [];
        foreach ($allFields as $af) $fieldData[$af['field_name']] ??= $af['content'];
        create_revision('page', $pid, $fieldData, [
            'title' => $page['title'] ?? '',
            'meta_title' => $page['meta_title'] ?? '',
            'meta_description' => $page['meta_description'] ?? '',
            'visibility' => $page['visibility'] ?? 'public',
            'status' => $page['status'] ?? 'published',
        ]);
    }

    // Bump updated_at on affected pages so future saves detect changes
    foreach (array_keys($page_ids) as $pid) {
        OutpostDB::update('pages', ['updated_at' => $newTimestamp], 'id = ?', [$pid]);
    }

    // Clear cache for affected pages; globals affect every page so clear all
    $clearAll = false;
    foreach (array_keys($page_ids) as $pid) {
        $page = OutpostDB::fetchOne('SELECT path FROM pages WHERE id = ?', [$pid]);
        if ($page && $page['path'] === '__global__') {
            $clearAll = true;
            break;
        }
    }
    if ($clearAll) {
        outpost_clear_cache();
    } else {
        foreach (array_keys($page_ids) as $pid) {
            $page = OutpostDB::fetchOne('SELECT path FROM pages WHERE id = ?', [$pid]);
            if ($page && $page['path']) {
                outpost_clear_cache($page['path']);
            }
        }
    }

    json_response(['success' => true, 'updated' => count($fields), 'updated_at' => $newTimestamp]);
}

// ── Global Field Handlers ─────────────────────────────────
function handle_globals_get(): void {
    $globalPage = OutpostDB::fetchOne("SELECT id, updated_at FROM pages WHERE path = '__global__'");
    if (!$globalPage) {
        json_response(['fields' => [], 'page_id' => null, 'updated_at' => null]);
        return;
    }
    $fields = OutpostDB::fetchAll(
        "SELECT id, field_name, field_type, content, default_value, options FROM fields WHERE page_id = ? AND theme = '' ORDER BY sort_order ASC",
        [(int) $globalPage['id']]
    );

    // Filter out stale globals not in the registry
    $activeTheme = get_active_theme();
    $registryNames = array_column(
        OutpostDB::fetchAll(
            "SELECT field_name FROM page_field_registry WHERE theme = ? AND path = '__global__'",
            [$activeTheme]
        ),
        'field_name'
    );
    if (!empty($registryNames)) {
        $fields = array_values(array_filter($fields, fn($f) =>
            in_array($f['field_name'], $registryNames)
        ));
    }

    json_response(['fields' => $fields, 'page_id' => (int) $globalPage['id'], 'updated_at' => $globalPage['updated_at']]);
}

// ── Collection Handlers ──────────────────────────────────
function handle_collections_list(): void {
    $collections = OutpostDB::fetchAll('SELECT * FROM collections ORDER BY name ASC');
    // Add item counts
    foreach ($collections as &$c) {
        $count = OutpostDB::fetchOne(
            'SELECT COUNT(*) as count FROM collection_items WHERE collection_id = ?',
            [$c['id']]
        );
        $c['item_count'] = $count['count'] ?? 0;
    }

    // Filter by grants for scoped editors
    $granted = outpost_get_granted_collection_ids();
    if ($granted !== null) {
        $collections = array_values(array_filter($collections, fn($c) => in_array((int) $c['id'], $granted, true)));
    }

    json_response(['collections' => $collections]);
}

function handle_collection_create(): void {
    $data = get_json_body();

    $slug = trim($data['slug'] ?? '');
    $name = trim($data['name'] ?? '');
    if (!$slug || !$name) json_error('Slug and name are required');

    // Validate slug
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        json_error('Slug must be lowercase letters, numbers, and hyphens only');
    }

    $existing = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug]);
    if ($existing) json_error('A collection with this slug already exists');

    $id = OutpostDB::insert('collections', [
        'slug' => $slug,
        'name' => $name,
        'singular_name' => $data['singular_name'] ?? $name,
        'schema' => json_encode($data['schema'] ?? new \stdClass()),
        'url_pattern' => $data['url_pattern'] ?? "/{$slug}/{slug}",
        'template_path' => $data['template_path'] ?? '',
        'sort_field' => $data['sort_field'] ?? 'created_at',
        'sort_direction' => $data['sort_direction'] ?? 'DESC',
        'items_per_page' => (int) ($data['items_per_page'] ?? 10),
    ]);

    json_response(['success' => true, 'id' => $id], 201);
}

function handle_collection_update(): void {
    $id = (int) $_GET['id'];
    $data = get_json_body();

    $current = OutpostDB::fetchOne('SELECT * FROM collections WHERE id = ?', [$id]);
    if (!$current) json_error('Collection not found', 404);

    $allowed = ['name', 'singular_name', 'schema', 'url_pattern', 'template_path',
                'sort_field', 'sort_direction', 'items_per_page'];
    $update = [];
    foreach ($allowed as $key) {
        if (isset($data[$key])) {
            $update[$key] = $key === 'schema' ? json_encode($data[$key]) : $data[$key];
        }
    }

    if (empty($update)) json_error('Nothing to update');

    // If url_pattern is changing, purge ghost pages that matched the old prefix
    if (isset($update['url_pattern']) && $update['url_pattern'] !== $current['url_pattern']) {
        $old_pattern = $current['url_pattern'] ?: ('/' . $current['slug'] . '/{slug}');
        $old_prefix = explode('{slug}', $old_pattern)[0];
        purge_ghost_pages_with_prefix($old_prefix);
        // Also purge the default slug-based prefix if different
        $slug_prefix = '/' . $current['slug'] . '/';
        if ($slug_prefix !== $old_prefix) {
            purge_ghost_pages_with_prefix($slug_prefix);
        }
        // Purge the bare slug page (e.g. /post)
        purge_ghost_page('/' . $current['slug']);
    }

    OutpostDB::update('collections', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_collection_delete(): void {
    $id = (int) $_GET['id'];

    // Purge ghost pages for all items in this collection before deleting
    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE id = ?', [$id]);
    if ($collection) {
        $pattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
        $prefix = explode('{slug}', $pattern)[0];
        purge_ghost_pages_with_prefix($prefix);
    }

    OutpostDB::delete('collections', 'id = ?', [$id]);
    json_response(['success' => true]);
}

// ── Collection Item Handlers ─────────────────────────────
function handle_items_list(): void {
    outpost_auto_publish_scheduled();

    $slug = $_GET['collection'] ?? '';
    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$collection) json_error('Collection not found', 404);

    if (!outpost_can_access_collection((int) $collection['id'])) {
        json_error('Permission denied', 403);
    }

    $status = $_GET['status'] ?? '';
    $where = 'collection_id = ?';
    $params = [$collection['id']];

    if ($status) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }

    $order = $collection['sort_field'] . ' ' . $collection['sort_direction'];
    if (!preg_match('/^[a-z_]+ (?:ASC|DESC)$/i', $order)) {
        $order = 'created_at DESC';
    }

    $items = OutpostDB::fetchAll(
        "SELECT * FROM collection_items WHERE {$where} ORDER BY {$order}",
        $params
    );

    // Decode JSON data
    foreach ($items as &$item) {
        $item['data'] = json_decode($item['data'], true) ?: [];
    }

    json_response(['items' => $items, 'collection' => $collection]);
}

function handle_item_create(): void {
    ensure_items_columns();
    $data = get_json_body();

    $collection_id = (int) ($data['collection_id'] ?? 0);
    $slug = trim($data['slug'] ?? '');
    if (!$collection_id || !$slug) json_error('collection_id and slug are required');

    if (!outpost_can_access_collection($collection_id)) {
        json_error('Permission denied', 403);
    }

    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        json_error('Slug must be lowercase letters, numbers, and hyphens only');
    }

    $existing = OutpostDB::fetchOne(
        'SELECT id FROM collection_items WHERE collection_id = ? AND slug = ?',
        [$collection_id, $slug]
    );
    if ($existing) json_error('An item with this slug already exists in this collection');

    $item_data = $data['data'] ?? [];
    $status = $data['status'] ?? 'draft';

    $id = OutpostDB::insert('collection_items', [
        'collection_id' => $collection_id,
        'slug' => $slug,
        'status' => $status,
        'data' => json_encode($item_data),
        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        'scheduled_at' => ($status === 'scheduled' && !empty($data['scheduled_at']) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $data['scheduled_at'])) ? $data['scheduled_at'] : null,
    ]);

    dispatch_webhook('entry.created', ['id' => $id, 'collection_id' => $collection_id, 'slug' => $slug, 'status' => $status, 'data' => $item_data]);

    json_response(['success' => true, 'id' => $id], 201);
}

function handle_item_update(): void {
    ensure_items_columns();
    $id = (int) $_GET['id'];
    $data = get_json_body();

    // Check collection access for scoped editors
    $itemCheck = OutpostDB::fetchOne('SELECT collection_id FROM collection_items WHERE id = ?', [$id]);
    if ($itemCheck && !outpost_can_access_collection((int) $itemCheck['collection_id'])) {
        json_error('Permission denied', 403);
    }

    $newTimestamp = date('Y-m-d H:i:s');
    $update = ['updated_at' => $newTimestamp];

    if (isset($data['slug'])) {
        if (!preg_match('/^[a-z0-9-]+$/', $data['slug'])) {
            json_error('Slug must be lowercase letters, numbers, and hyphens only');
        }
        // If the slug is changing, purge the ghost page for the old URL
        $current = OutpostDB::fetchOne(
            'SELECT ci.slug, c.url_pattern, c.slug AS coll_slug
             FROM collection_items ci JOIN collections c ON ci.collection_id = c.id
             WHERE ci.id = ?', [$id]
        );
        if ($current && $current['slug'] !== $data['slug']) {
            $old_path = collection_item_url_path($current['slug'], [
                'url_pattern' => $current['url_pattern'],
                'slug'        => $current['coll_slug'],
            ]);
            purge_ghost_page($old_path);
        }
        $update['slug'] = $data['slug'];
    }
    if (isset($data['data'])) {
        $update['data'] = json_encode($data['data']);
    }
    if (isset($data['status'])) {
        $update['status'] = $data['status'];
        if ($data['status'] === 'published') {
            $item = OutpostDB::fetchOne('SELECT published_at FROM collection_items WHERE id = ?', [$id]);
            if (!$item['published_at']) {
                $update['published_at'] = date('Y-m-d H:i:s');
            }
        }
    }
    if (isset($data['sort_order'])) {
        $update['sort_order'] = (int) $data['sort_order'];
    }
    if (isset($data['scheduled_at'])) {
        $sa = $data['scheduled_at'];
        if ($sa && !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $sa)) {
            json_error('Invalid scheduled_at format');
        }
        $update['scheduled_at'] = $sa;
    }
    if (array_key_exists('published_at', $data)) {
        $update['published_at'] = $data['published_at'] ?: null;
    }

    // Snapshot current state before overwriting
    $current = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$id]);
    if ($current) {
        create_revision('item', $id,
            json_decode($current['data'] ?? '{}', true) ?: [],
            ['slug' => $current['slug'], 'status' => $current['status'], 'published_at' => $current['published_at'] ?? null, 'scheduled_at' => $current['scheduled_at'] ?? null]
        );
    }

    // Optimistic lock: reject if item was modified since client loaded it
    $clientVersion = $data['_version'] ?? null;
    if ($clientVersion) {
        $rows = OutpostDB::update('collection_items', $update, 'id = ? AND updated_at = ?', [$id, $clientVersion]);
        if ($rows === 0) {
            $current = OutpostDB::fetchOne('SELECT updated_at FROM collection_items WHERE id = ?', [$id]);
            if (!$current) json_error('Item not found', 404);
            json_response([
                'error' => 'This item was modified by another user. Reload to see the latest version.',
                'conflict' => true,
                'server_updated_at' => $current['updated_at'],
            ], 409);
        }
    } else {
        OutpostDB::update('collection_items', $update, 'id = ?', [$id]);
    }

    if (isset($data['status']) && $data['status'] === 'published') {
        $item = OutpostDB::fetchOne('SELECT data, slug FROM collection_items WHERE id = ?', [$id]);
        $item_data = json_decode($item['data'] ?? '{}', true);
        $title = $item_data['title'] ?? ($item['slug'] ?? 'an item');
        log_activity('content', '"' . $title . '" published');
    }

    // Clear full HTML cache — any listing page (e.g. /blog) needs to reflect changes too
    outpost_clear_cache();

    // Dispatch webhook
    $updated_item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$id]);
    $wh_data = ['id' => $id, 'slug' => $updated_item['slug'] ?? '', 'collection_id' => $updated_item['collection_id'] ?? 0];
    if (isset($data['status']) && $data['status'] === 'published') {
        dispatch_webhook('entry.published', $wh_data);
    } elseif (isset($data['status']) && $data['status'] === 'draft') {
        dispatch_webhook('entry.unpublished', $wh_data);
    } else {
        dispatch_webhook('entry.updated', $wh_data);
    }

    json_response(['success' => true, 'updated_at' => $newTimestamp]);
}

function handle_item_inline_update(): void {
    ensure_items_columns();
    $data = get_json_body();

    $id = (int) ($data['id'] ?? 0);
    $fields = $data['fields'] ?? null;
    $clientVersion = $data['_version'] ?? null;

    if (!$id) json_error('Item ID is required');
    if (!is_array($fields) || empty($fields) || array_is_list($fields)) {
        json_error('Fields must be a non-empty associative array');
    }

    // Fetch current item
    $current = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$id]);
    if (!$current) json_error('Item not found', 404);

    // Snapshot current state before overwriting
    create_revision('item', $id,
        json_decode($current['data'] ?? '{}', true) ?: [],
        ['slug' => $current['slug'], 'status' => $current['status'], 'published_at' => $current['published_at'] ?? null, 'scheduled_at' => $current['scheduled_at'] ?? null]
    );

    // Decode existing data and merge new fields
    $existingData = json_decode($current['data'] ?? '{}', true) ?: [];
    $mergedData = array_merge($existingData, $fields);

    // Sanitize richtext fields based on collection schema
    $collection = OutpostDB::fetchOne('SELECT schema FROM collections WHERE id = ?', [$current['collection_id']]);
    if ($collection && $collection['schema']) {
        $schema = json_decode($collection['schema'], true) ?: [];
        $richtextFields = [];
        foreach ($schema as $schemaDef) {
            if (($schemaDef['type'] ?? '') === 'richtext') {
                $richtextFields[] = $schemaDef['name'] ?? '';
            }
        }
        foreach ($fields as $fieldName => $fieldValue) {
            if (in_array($fieldName, $richtextFields, true) && is_string($fieldValue)) {
                if (class_exists('OutpostSanitizer')) {
                    $mergedData[$fieldName] = OutpostSanitizer::clean($fieldValue);
                }
            }
        }
    }

    $newTimestamp = date('Y-m-d H:i:s');
    $update = [
        'data'       => json_encode($mergedData),
        'updated_at' => $newTimestamp,
    ];

    // Optimistic lock: reject if item was modified since client loaded it
    if ($clientVersion) {
        $rows = OutpostDB::update('collection_items', $update, 'id = ? AND updated_at = ?', [$id, $clientVersion]);
        if ($rows === 0) {
            $current = OutpostDB::fetchOne('SELECT updated_at FROM collection_items WHERE id = ?', [$id]);
            if (!$current) json_error('Item not found', 404);
            json_response([
                'error' => 'This item was modified by another user. Reload to see the latest version.',
                'conflict' => true,
                'server_updated_at' => $current['updated_at'],
            ], 409);
        }
    } else {
        OutpostDB::update('collection_items', $update, 'id = ?', [$id]);
    }

    // Clear full HTML cache
    outpost_clear_cache();

    // Dispatch webhook
    $updated_item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$id]);
    $wh_data = ['id' => $id, 'slug' => $updated_item['slug'] ?? '', 'collection_id' => $updated_item['collection_id'] ?? 0];
    dispatch_webhook('entry.updated', $wh_data);

    json_response(['success' => true, 'updated_at' => $newTimestamp]);
}

function handle_item_delete(): void {
    $id = (int) $_GET['id'];

    // Check collection access for scoped editors
    $itemCheck = OutpostDB::fetchOne('SELECT collection_id FROM collection_items WHERE id = ?', [$id]);
    if ($itemCheck && !outpost_can_access_collection((int) $itemCheck['collection_id'])) {
        json_error('Permission denied', 403);
    }

    // Purge the ghost page for this item before deleting
    $item = OutpostDB::fetchOne(
        'SELECT ci.slug, c.url_pattern, c.slug AS coll_slug
         FROM collection_items ci JOIN collections c ON ci.collection_id = c.id
         WHERE ci.id = ?', [$id]
    );
    if ($item) {
        purge_ghost_page(collection_item_url_path($item['slug'], [
            'url_pattern' => $item['url_pattern'],
            'slug'        => $item['coll_slug'],
        ]));
    }

    $wh_data = ['id' => $id, 'slug' => $item['slug'] ?? '', 'collection_slug' => $item['coll_slug'] ?? ''];
    OutpostDB::delete('collection_items', 'id = ?', [$id]);
    dispatch_webhook('entry.deleted', $wh_data);
    json_response(['success' => true]);
}

function handle_items_bulk_status(): void {
    $data = get_json_body();
    $ids = $data['ids'] ?? [];
    $status = $data['status'] ?? '';
    if (!is_array($ids) || count($ids) === 0 || !in_array($status, ['draft', 'published'])) {
        json_error('ids (array) and status (draft|published) required');
    }
    $ids = array_map('intval', $ids);

    // Check collection access for scoped editors
    $granted = outpost_get_granted_collection_ids();
    if ($granted !== null) {
        foreach ($ids as $itemId) {
            $ic = OutpostDB::fetchOne('SELECT collection_id FROM collection_items WHERE id = ?', [$itemId]);
            if ($ic && !in_array((int) $ic['collection_id'], $granted, true)) {
                json_error('Permission denied', 403);
            }
        }
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $update = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if ($status === 'published') {
        $update['published_at'] = date('Y-m-d H:i:s');
        // Only set published_at on items that don't have one yet
        OutpostDB::query(
            "UPDATE collection_items SET status = 'published', updated_at = ?, published_at = COALESCE(published_at, ?) WHERE id IN ($placeholders)",
            array_merge([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')], $ids)
        );
    } else {
        OutpostDB::query(
            "UPDATE collection_items SET status = ?, updated_at = ? WHERE id IN ($placeholders)",
            array_merge([$status, date('Y-m-d H:i:s')], $ids)
        );
    }
    outpost_clear_cache();
    $count = count($ids);
    log_activity('content', $count . ' item' . ($count !== 1 ? 's' : '') . ' set to ' . $status);
    $wh_event = $status === 'published' ? 'entry.published' : 'entry.unpublished';
    foreach ($ids as $wh_id) {
        dispatch_webhook($wh_event, ['id' => $wh_id]);
    }
    json_response(['success' => true, 'count' => $count]);
}

function handle_items_bulk_delete(): void {
    $data = get_json_body();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
        json_error('ids (array) required');
    }
    $ids = array_map('intval', $ids);

    // Check collection access for scoped editors
    $granted = outpost_get_granted_collection_ids();
    if ($granted !== null) {
        foreach ($ids as $itemId) {
            $ic = OutpostDB::fetchOne('SELECT collection_id FROM collection_items WHERE id = ?', [$itemId]);
            if ($ic && !in_array((int) $ic['collection_id'], $granted, true)) {
                json_error('Permission denied', 403);
            }
        }
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Purge ghost pages for all items being deleted
    $items = OutpostDB::fetchAll(
        "SELECT ci.slug, c.url_pattern, c.slug AS coll_slug
         FROM collection_items ci JOIN collections c ON ci.collection_id = c.id
         WHERE ci.id IN ($placeholders)", $ids
    );
    foreach ($items as $item) {
        purge_ghost_page(collection_item_url_path($item['slug'], [
            'url_pattern' => $item['url_pattern'],
            'slug'        => $item['coll_slug'],
        ]));
    }

    // Dispatch webhooks before deleting
    foreach ($items as $wh_item) {
        dispatch_webhook('entry.deleted', ['slug' => $wh_item['slug'], 'collection_slug' => $wh_item['coll_slug']]);
    }
    OutpostDB::query("DELETE FROM collection_items WHERE id IN ($placeholders)", $ids);
    outpost_clear_cache();
    $count = count($ids);
    log_activity('content', $count . ' item' . ($count !== 1 ? 's' : '') . ' deleted');
    json_response(['success' => true, 'count' => $count]);
}

function handle_item_preview_token(): void {
    $data = get_json_body();
    $id = (int) ($data['id'] ?? 0);
    if (!$id) json_error('id required');

    // Ensure column exists
    $db = OutpostDB::connect();
    $cols = array_column($db->query("PRAGMA table_info(collection_items)")->fetchAll(), 'name');
    if (!in_array('preview_token', $cols)) {
        $db->exec("ALTER TABLE collection_items ADD COLUMN preview_token TEXT");
    }

    $token = bin2hex(random_bytes(32));
    OutpostDB::update('collection_items', ['preview_token' => $token], 'id = ?', [$id]);
    json_response(['success' => true, 'token' => $token]);
}

// ── Media Handlers ───────────────────────────────────────
function handle_media_list(): void {
    $media = OutpostDB::fetchAll('SELECT * FROM media ORDER BY uploaded_at DESC');
    json_response(['media' => $media]);
}

function handle_media_upload(): void {
    // CSRF check for uploads (via form field since it's multipart)
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(OutpostAuth::csrfToken(), $csrf)) {
        json_error('Invalid CSRF token', 403);
    }

    if (empty($_FILES['file'])) {
        json_error('No file uploaded');
    }

    require_once __DIR__ . '/media.php';

    $file = $_FILES['file'];
    $result = OutpostMedia::upload($file);

    if (isset($result['error'])) {
        json_error($result['error']);
    }

    log_activity('media', '"' . basename($file['name']) . '" uploaded');
    dispatch_webhook('media.created', ['id' => $result['id'] ?? null, 'filename' => basename($file['name']), 'path' => $result['path'] ?? '']);

    json_response(['success' => true, 'media' => $result], 201);
}

function handle_media_delete(): void {
    $id = (int) $_GET['id'];

    $media = OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
    if (!$media) json_error('Media not found', 404);

    require_once __DIR__ . '/media.php';
    OutpostMedia::delete($media);
    dispatch_webhook('media.deleted', ['id' => $id, 'filename' => $media['filename'] ?? '', 'path' => $media['path'] ?? '']);

    json_response(['success' => true]);
}

function handle_media_update(): void {
    $id = (int) $_GET['id'];
    $data = get_json_body();

    $media = OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
    if (!$media) json_error('Media not found', 404);

    $update = [];
    if (array_key_exists('alt_text', $data)) {
        $update['alt_text'] = trim($data['alt_text'] ?? '');
    }

    if (empty($update)) json_error('Nothing to update');

    OutpostDB::update('media', $update, 'id = ?', [$id]);
    $updated = OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
    json_response(['success' => true, 'media' => $updated]);
}

function handle_media_transform(): void {
    $data = get_json_body();
    $id = (int) ($data['id'] ?? 0);
    $action = $data['action'] ?? '';
    if (!$id || !in_array($action, ['resize', 'crop'])) {
        json_error('id and action (resize|crop) required');
    }

    $media = OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
    if (!$media) json_error('Media not found', 404);
    if (!str_starts_with($media['mime_type'], 'image/') || $media['mime_type'] === 'image/svg+xml') {
        json_error('Only raster images can be transformed');
    }

    require_once __DIR__ . '/media.php';
    $absPath = OutpostMedia::getAbsolutePath($media['path']);
    if (!file_exists($absPath)) json_error('Image file not found on disk');
    if (!extension_loaded('gd')) json_error('GD extension not available');

    $info = getimagesize($absPath);
    if (!$info) json_error('Cannot read image');
    [$origW, $origH] = $info;
    $mime = $info['mime'];

    $source = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($absPath),
        'image/png' => imagecreatefrompng($absPath),
        'image/gif' => imagecreatefromgif($absPath),
        'image/webp' => imagecreatefromwebp($absPath),
        default => null,
    };
    if (!$source) json_error('Unsupported image format for editing');

    if ($action === 'resize') {
        $newW = (int) ($data['width'] ?? 0);
        $newH = (int) ($data['height'] ?? 0);
        if ($newW < 1 && $newH < 1) json_error('width or height required');
        if ($newW < 1) $newW = (int) round($origW * ($newH / $origH));
        if ($newH < 1) $newH = (int) round($origH * ($newW / $origW));
        if ($newW > 4800 || $newH > 4800) json_error('Maximum dimension is 4800px');

        $resized = imagecreatetruecolor($newW, $newH);
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($source);
        $source = $resized;
        $origW = $newW;
        $origH = $newH;

    } elseif ($action === 'crop') {
        $x = max(0, (int) ($data['x'] ?? 0));
        $y = max(0, (int) ($data['y'] ?? 0));
        $cw = (int) ($data['width'] ?? 0);
        $ch = (int) ($data['height'] ?? 0);
        if ($cw < 1 || $ch < 1) json_error('width and height required for crop');
        // Clamp to image bounds
        if ($x + $cw > $origW) $cw = $origW - $x;
        if ($y + $ch > $origH) $ch = $origH - $y;
        if ($cw < 1 || $ch < 1) json_error('Crop region out of bounds');

        $cropped = imagecreatetruecolor($cw, $ch);
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
            imagefilledrectangle($cropped, 0, 0, $cw, $ch, $transparent);
        }
        imagecopy($cropped, $source, 0, 0, $x, $y, $cw, $ch);
        imagedestroy($source);
        $source = $cropped;
        $origW = $cw;
        $origH = $ch;
    }

    // Save back
    match ($mime) {
        'image/jpeg' => imagejpeg($source, $absPath, 90),
        'image/png' => imagepng($source, $absPath),
        'image/gif' => imagegif($source, $absPath),
        'image/webp' => imagewebp($source, $absPath, 90),
        default => null,
    };
    imagedestroy($source);

    // Regenerate thumbnail
    $thumbPath = OutpostMedia::regenerateThumbnail($absPath, $media['filename']);
    $relativeThumb = $thumbPath ? OutpostMedia::getRelativePath($thumbPath) : $media['thumb_path'];

    // Update DB
    OutpostDB::update('media', [
        'width' => $origW,
        'height' => $origH,
        'file_size' => filesize($absPath),
        'thumb_path' => $relativeThumb,
    ], 'id = ?', [$id]);

    $updated = OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
    json_response(['success' => true, 'media' => $updated]);
}

// ── Settings Handlers ────────────────────────────────────
function handle_settings_get(): void {
    $rows = OutpostDB::fetchAll('SELECT * FROM settings');
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
    json_response(['settings' => $settings]);
}

function handle_settings_update(): void {
    $data = get_json_body();

    foreach ($data as $key => $value) {
        if (!is_string($key) || $key === '') continue;
        OutpostDB::query(
            'INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)',
            [$key, (string) $value]
        );
    }

    log_activity('system', 'Site settings updated');

    json_response(['success' => true]);
}

// ── Cache Handler ────────────────────────────────────────
function handle_cache_clear(): void {
    require_once __DIR__ . '/engine.php';
    outpost_clear_cache();
    dispatch_webhook('cache.cleared', []);
    json_response(['success' => true, 'message' => 'Cache cleared']);
}

// ── Sync Key Handlers ────────────────────────────────────
function handle_sync_key_get(): void {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'sync_api_key'");

    if (!$row || empty($row['value'])) {
        // Auto-generate on first access
        $key = bin2hex(random_bytes(32));
        OutpostDB::query(
            "INSERT INTO settings (key, value) VALUES ('sync_api_key', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$key]
        );
    } else {
        $key = $row['value'];
    }

    $last_pull = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'sync_last_pull'");
    $last_push = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'sync_last_push'");

    json_response([
        'key_set'     => true,
        'key_masked'  => substr($key, 0, 8) . '••••••••••••••••••••••••••••••••••••••••••••••••' . substr($key, -4),
        'last_pull'   => $last_pull['value'] ?? null,
        'last_push'   => $last_push['value'] ?? null,
    ]);
}

function handle_sync_key_regenerate(): void {
    $key = bin2hex(random_bytes(32));
    OutpostDB::query(
        "INSERT INTO settings (key, value) VALUES ('sync_api_key', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        [$key]
    );
    json_response([
        'success'    => true,
        'key'        => $key,
        'key_masked' => substr($key, 0, 8) . '••••••••••••••••••••••••••••••••••••••••••••••••' . substr($key, -4),
    ]);
}

// ── Cron Key Handlers ────────────────────────────────────
function handle_cron_key_get(): void {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'cron_key'");

    if (!$row || empty($row['value'])) {
        $key = bin2hex(random_bytes(32));
        OutpostDB::query(
            "INSERT INTO settings (key, value) VALUES ('cron_key', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$key]
        );
    } else {
        $key = $row['value'];
    }

    json_response([
        'key_masked' => substr($key, 0, 8) . str_repeat('•', 48) . substr($key, -4),
        'key'        => $key,
    ]);
}

function handle_cron_key_regenerate(): void {
    $key = bin2hex(random_bytes(32));
    OutpostDB::query(
        "INSERT INTO settings (key, value) VALUES ('cron_key', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        [$key]
    );
    json_response([
        'success'    => true,
        'key'        => $key,
        'key_masked' => substr($key, 0, 8) . str_repeat('•', 48) . substr($key, -4),
    ]);
}

// ── Taxonomy Handlers ────────────────────────────
function handle_folder_get(): void {
    ensure_folder_tables();
    $id = (int) $_GET['id'];
    $folder = OutpostDB::fetchOne(
        'SELECT f.*, c.name as collection_name, c.slug as collection_slug,
                (SELECT COUNT(*) FROM labels WHERE folder_id = f.id) as label_count
         FROM folders f
         LEFT JOIN collections c ON c.id = f.collection_id
         WHERE f.id = ?',
        [$id]
    );
    if (!$folder) json_error('Folder not found', 404);
    $folder['schema'] = json_decode($folder['schema'] ?: '[]', true);
    json_response(['folder' => $folder]);
}
// Legacy alias
function handle_taxonomy_get(): void { handle_folder_get(); }

function handle_folders_list(): void {
    ensure_folder_tables();
    $collection_id = (int) $_GET['collection_id'];
    $folders = OutpostDB::fetchAll(
        'SELECT * FROM folders WHERE collection_id = ? ORDER BY name ASC',
        [$collection_id]
    );
    // Add label counts
    foreach ($folders as &$folder) {
        $count = OutpostDB::fetchOne(
            'SELECT COUNT(*) as count FROM labels WHERE folder_id = ?',
            [$folder['id']]
        );
        $folder['label_count'] = $count['count'] ?? 0;
    }
    json_response(['folders' => $folders]);
}
// Legacy alias
function handle_taxonomies_list(): void { handle_folders_list(); }

function handle_folder_create(): void {
    ensure_folder_tables();
    $data = get_json_body();
    $collection_id = (int) ($data['collection_id'] ?? 0);
    $slug = trim($data['slug'] ?? '');
    $name = trim($data['name'] ?? '');
    $type = $data['type'] ?? 'flat';

    if (!$collection_id || !$slug || !$name) json_error('collection_id, slug, and name are required');
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) json_error('Slug must be lowercase letters, numbers, and hyphens only');
    if (!in_array($type, ['flat', 'hierarchical'])) json_error('Type must be flat or hierarchical');

    $existing = OutpostDB::fetchOne(
        'SELECT id FROM folders WHERE collection_id = ? AND slug = ?',
        [$collection_id, $slug]
    );
    if ($existing) json_error('A folder with this slug already exists for this collection');

    $id = OutpostDB::insert('folders', [
        'collection_id' => $collection_id,
        'slug' => $slug,
        'name' => $name,
        'singular_name' => trim($data['singular_name'] ?? ''),
        'type' => $type,
        'schema' => json_encode($data['schema'] ?? []),
        'description' => trim($data['description'] ?? ''),
    ]);
    json_response(['success' => true, 'id' => $id], 201);
}
// Legacy alias
function handle_taxonomy_create(): void { handle_folder_create(); }

function handle_folder_update(): void {
    ensure_folder_tables();
    $id = (int) $_GET['id'];
    $data = get_json_body();
    $update = [];
    if (isset($data['name'])) $update['name'] = trim($data['name']);
    if (isset($data['singular_name'])) $update['singular_name'] = trim($data['singular_name']);
    if (isset($data['type']) && in_array($data['type'], ['flat', 'hierarchical'])) {
        $update['type'] = $data['type'];
    }
    if (array_key_exists('schema', $data)) $update['schema'] = json_encode($data['schema']);
    if (isset($data['description'])) $update['description'] = trim($data['description']);
    if (empty($update)) json_error('Nothing to update');
    OutpostDB::update('folders', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}
// Legacy alias
function handle_taxonomy_update(): void { handle_folder_update(); }

function handle_folder_delete(): void {
    ensure_folder_tables();
    $id = (int) $_GET['id'];
    OutpostDB::delete('folders', 'id = ?', [$id]);
    json_response(['success' => true]);
}
// Legacy alias
function handle_taxonomy_delete(): void { handle_folder_delete(); }

function handle_folders_list_all(): void {
    ensure_folder_tables();
    $folders = OutpostDB::fetchAll(
        'SELECT f.*, c.name as collection_name, c.slug as collection_slug FROM folders f LEFT JOIN collections c ON c.id = f.collection_id ORDER BY c.name ASC, f.name ASC'
    );
    foreach ($folders as &$folder) {
        $count = OutpostDB::fetchOne(
            'SELECT COUNT(*) as count FROM labels WHERE folder_id = ?',
            [$folder['id']]
        );
        $folder['label_count'] = $count['count'] ?? 0;
    }
    json_response(['folders' => $folders]);
}
// Legacy alias
function handle_taxonomies_list_all(): void { handle_folders_list_all(); }

function handle_label_get(): void {
    ensure_folder_tables();
    $id = (int) $_GET['id'];
    $label = OutpostDB::fetchOne(
        'SELECT l.*, f.name as folder_name, f.slug as folder_slug, f.type as folder_type, f.collection_id,
                (SELECT COUNT(*) FROM item_labels WHERE label_id = l.id) as item_count
         FROM labels l
         INNER JOIN folders f ON f.id = l.folder_id
         WHERE l.id = ?',
        [$id]
    );
    if (!$label) json_error('Label not found', 404);
    $label['data'] = json_decode($label['data'] ?: '{}', true);
    json_response(['label' => $label]);
}
// Legacy alias
function handle_term_get(): void { handle_label_get(); }

function handle_labels_list(): void {
    ensure_folder_tables();
    // Support both new folder_id and legacy taxonomy_id param
    $folder_id = (int) ($_GET['folder_id'] ?? $_GET['taxonomy_id'] ?? 0);
    $labels = OutpostDB::fetchAll(
        'SELECT l.*, (SELECT COUNT(*) FROM item_labels WHERE label_id = l.id) as item_count FROM labels l WHERE l.folder_id = ? ORDER BY l.sort_order ASC, l.name ASC',
        [$folder_id]
    );
    foreach ($labels as &$l) {
        $l['data'] = json_decode($l['data'] ?: '{}', true);
    }
    json_response(['labels' => $labels]);
}
// Legacy alias
function handle_terms_list(): void { handle_labels_list(); }

function handle_label_create(): void {
    ensure_folder_tables();
    $data = get_json_body();
    // Support both new folder_id and legacy taxonomy_id in body
    $folder_id = (int) ($data['folder_id'] ?? $data['taxonomy_id'] ?? 0);
    $slug = trim($data['slug'] ?? '');
    $name = trim($data['name'] ?? '');
    $parent_id = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

    if (!$folder_id || !$slug || !$name) json_error('folder_id, slug, and name are required');
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) json_error('Slug must be lowercase letters, numbers, and hyphens only');

    $existing = OutpostDB::fetchOne(
        'SELECT id FROM labels WHERE folder_id = ? AND slug = ?',
        [$folder_id, $slug]
    );
    if ($existing) json_error('A label with this slug already exists in this folder');

    $insertData = [
        'folder_id' => $folder_id,
        'slug' => $slug,
        'name' => $name,
        'data' => json_encode($data['data'] ?? new \stdClass()),
        'description' => trim($data['description'] ?? ''),
    ];
    if ($parent_id) $insertData['parent_id'] = $parent_id;

    $id = OutpostDB::insert('labels', $insertData);
    json_response(['success' => true, 'id' => $id], 201);
}
// Legacy alias
function handle_term_create(): void { handle_label_create(); }

function handle_label_update(): void {
    ensure_folder_tables();
    $id = (int) $_GET['id'];
    $data = get_json_body();
    $update = [];
    if (isset($data['name'])) $update['name'] = trim($data['name']);
    if (isset($data['slug'])) {
        if (!preg_match('/^[a-z0-9-]+$/', $data['slug'])) json_error('Slug must be lowercase letters, numbers, and hyphens only');
        $update['slug'] = $data['slug'];
    }
    if (array_key_exists('parent_id', $data)) $update['parent_id'] = $data['parent_id'] ? (int) $data['parent_id'] : null;
    if (isset($data['sort_order'])) $update['sort_order'] = (int) $data['sort_order'];
    if (array_key_exists('data', $data)) $update['data'] = json_encode($data['data']);
    if (isset($data['description'])) $update['description'] = trim($data['description']);
    if (empty($update)) json_error('Nothing to update');
    OutpostDB::update('labels', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}
// Legacy alias
function handle_term_update(): void { handle_label_update(); }

function handle_label_delete(): void {
    ensure_folder_tables();
    $id = (int) $_GET['id'];
    OutpostDB::delete('labels', 'id = ?', [$id]);
    json_response(['success' => true]);
}
// Legacy alias
function handle_term_delete(): void { handle_label_delete(); }

function handle_item_labels_get(): void {
    ensure_folder_tables();
    $item_id = (int) $_GET['item_id'];
    $labels = OutpostDB::fetchAll(
        'SELECT l.* FROM labels l INNER JOIN item_labels il ON il.label_id = l.id WHERE il.item_id = ?',
        [$item_id]
    );
    $label_ids = array_map(function($l) { return (int)$l['id']; }, $labels);
    json_response(['labels' => $labels, 'label_ids' => $label_ids]);
}
// Legacy alias
function handle_item_terms_get(): void { handle_item_labels_get(); }

function handle_item_labels_set(): void {
    ensure_folder_tables();
    $item_id = (int) $_GET['item_id'];
    $data = get_json_body();
    // Support both new label_ids and legacy term_ids in body
    $label_ids = $data['label_ids'] ?? $data['term_ids'] ?? [];

    // Replace-all strategy: delete existing, insert new
    OutpostDB::delete('item_labels', 'item_id = ?', [$item_id]);
    foreach ($label_ids as $lid) {
        OutpostDB::query(
            'INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)',
            [$item_id, (int) $lid]
        );
    }
    json_response(['success' => true]);
}
// Legacy alias
function handle_item_terms_set(): void { handle_item_labels_set(); }

// ── User Handlers ───────────────────────────────────

// Migrate users table to add new columns if missing
function ensure_users_columns(): void {
    $db = OutpostDB::connect();
    $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('display_name', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN display_name TEXT DEFAULT ''");
    }
    if (!in_array('avatar', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN avatar TEXT DEFAULT ''");
    }
    if (!in_array('bio', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN bio TEXT DEFAULT ''");
    }
    if (!in_array('member_since', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN member_since TEXT");
    }
    if (!in_array('member_expires', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN member_expires TEXT");
    }
    if (!in_array('member_status', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN member_status TEXT DEFAULT 'active'");
    }
    if (!in_array('reset_token', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN reset_token TEXT");
    }
    if (!in_array('reset_token_expires', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires TEXT");
    }
    if (!in_array('email_verified', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN email_verified TEXT");
    }
    if (!in_array('verify_token', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN verify_token TEXT");
    }
    if (!in_array('verify_token_expires', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN verify_token_expires TEXT");
    }
    if (!in_array('totp_last_code', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN totp_last_code TEXT");
    }
    if (!in_array('totp_token_nonce', $colNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN totp_token_nonce TEXT");
    }
}

// ── Folder/Label Table Migration (formerly Taxonomy/Term) ─────────────────────
function ensure_folder_tables(): void {
    $db = OutpostDB::connect();

    // Check if old 'taxonomies' table exists (upgrade path)
    $oldExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='taxonomies'")->fetch();

    if ($oldExists) {
        // Rename tables for upgrade
        $db->exec("ALTER TABLE taxonomies RENAME TO folders");
        $db->exec("ALTER TABLE terms RENAME TO labels");
        $db->exec("ALTER TABLE item_terms RENAME TO item_labels");
        // Rename columns
        $db->exec("ALTER TABLE labels RENAME COLUMN taxonomy_id TO folder_id");
        $db->exec("ALTER TABLE item_labels RENAME COLUMN term_id TO label_id");
    }

    // Ensure tables exist (fresh install path) - use new names
    $db->exec("
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            type TEXT DEFAULT 'flat',
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(collection_id, slug),
            FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS labels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(folder_id, slug),
            FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS item_labels (
            item_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            PRIMARY KEY (item_id, label_id),
            FOREIGN KEY (item_id) REFERENCES collection_items(id) ON DELETE CASCADE,
            FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
        );
    ");

    // Migration: add schema column to folders
    $cols = $db->query("PRAGMA table_info(folders)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('schema', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN schema TEXT DEFAULT '[]'");
    }
    if (!in_array('description', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN description TEXT DEFAULT ''");
    }
    if (!in_array('singular_name', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN singular_name TEXT DEFAULT ''");
    }

    // Migration: add data column to labels
    $labelCols = $db->query("PRAGMA table_info(labels)")->fetchAll();
    $labelColNames = array_column($labelCols, 'name');
    if (!in_array('data', $labelColNames)) {
        $db->exec("ALTER TABLE labels ADD COLUMN data TEXT DEFAULT '{}'");
    }
    if (!in_array('description', $labelColNames)) {
        $db->exec("ALTER TABLE labels ADD COLUMN description TEXT DEFAULT ''");
    }
}
// Legacy alias
function ensure_taxonomy_tables(): void { ensure_folder_tables(); }

function ensure_items_columns(): void {
    $db = OutpostDB::connect();
    $cols = $db->query("PRAGMA table_info(collection_items)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('scheduled_at', $colNames)) {
        $db->exec("ALTER TABLE collection_items ADD COLUMN scheduled_at TEXT");
    }
}

function handle_users_list(): void {
    ensure_users_columns();
    $users = OutpostDB::fetchAll('SELECT id, username, email, display_name, avatar, bio, role, created_at, last_login FROM users ORDER BY created_at DESC');
    json_response(['users' => $users]);
}

function handle_user_get(): void {
    ensure_users_columns();
    $id = (int) $_GET['id'];
    $user = OutpostDB::fetchOne('SELECT id, username, email, display_name, avatar, bio, role, created_at, last_login FROM users WHERE id = ?', [$id]);
    if (!$user) json_error('User not found', 404);
    json_response(['user' => $user]);
}

function handle_user_create(): void {
    ensure_users_columns();
    $data = get_json_body();
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $role = $data['role'] ?? 'admin';

    if (!$username || !$password) json_error('Username and password are required');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Invalid email address');
    if (strlen($password) < 8) json_error('Password must be at least 8 characters');

    // Validate role
    if (!in_array($role, OUTPOST_ALL_ROLES)) json_error('Invalid role');

    // Only admin can assign admin/developer roles
    $currentUser = OutpostAuth::currentUser();
    $currentRole = $currentUser['role'] ?? '';
    if (in_array($role, ['admin', 'developer']) && !in_array($currentRole, ['super_admin', 'admin'])) {
        json_error('Only admins can assign admin or developer roles', 403);
    }

    $existing = OutpostDB::fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
    if ($existing) json_error('Username already exists');

    $id = OutpostDB::insert('users', [
        'username' => $username,
        'email' => $email,
        'display_name' => trim($data['display_name'] ?? ''),
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'role' => $role,
    ]);

    json_response(['success' => true, 'id' => $id], 201);
}

function handle_user_update(): void {
    ensure_users_columns();
    $id = (int) $_GET['id'];
    $data = get_json_body();

    $user = OutpostDB::fetchOne('SELECT id FROM users WHERE id = ?', [$id]);
    if (!$user) json_error('User not found', 404);

    $update = [];

    if (isset($data['username'])) {
        $newUsername = trim($data['username']);
        if ($newUsername === '') json_error('Username cannot be empty');
        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $newUsername)) {
            json_error('Username can only contain letters, numbers, dots, hyphens, and underscores');
        }
        $existing = OutpostDB::fetchOne('SELECT id FROM users WHERE username = ? AND id != ?', [$newUsername, $id]);
        if ($existing) json_error('Username is already taken');
        $update['username'] = $newUsername;
    }
    if (isset($data['email'])) {
        $email = trim($data['email']);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Invalid email address');
        $update['email'] = $email;
    }
    if (isset($data['display_name'])) $update['display_name'] = trim($data['display_name']);
    if (isset($data['bio'])) $update['bio'] = trim($data['bio']);
    if (isset($data['avatar'])) $update['avatar'] = trim($data['avatar']);

    // Role changes: admin only
    if (isset($data['role'])) {
        $currentUser = OutpostAuth::currentUser();
        $currentRole = $currentUser['role'] ?? '';
        if (!in_array($currentRole, ['super_admin', 'admin'])) {
            json_error('Only admins can change roles', 403);
        }
        if (!in_array($data['role'], OUTPOST_ALL_ROLES)) json_error('Invalid role');
        $update['role'] = $data['role'];
    }

    // Password change (optional — only if provided)
    if (!empty($data['password'])) {
        if (strlen($data['password']) < 8) json_error('Password must be at least 8 characters');
        $update['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    }

    if (empty($update)) json_error('Nothing to update');

    OutpostDB::update('users', $update, 'id = ?', [$id]);

    // Refresh session if editing own profile
    OutpostAuth::init();
    if (($_SESSION['outpost_user_id'] ?? 0) == $id) {
        if (isset($update['username'])) $_SESSION['outpost_username'] = $update['username'];
        if (isset($update['role'])) $_SESSION['outpost_role'] = $update['role'];
    }

    json_response(['success' => true]);
}

function handle_user_delete(): void {
    // Admin only
    OutpostAuth::init();
    $currentRole = $_SESSION['outpost_role'] ?? '';
    if (!in_array($currentRole, ['super_admin', 'admin'])) {
        json_error('Only admins can delete users', 403);
    }

    $id = (int) $_GET['id'];

    // Prevent self-deletion
    if ($_SESSION['outpost_user_id'] == $id) {
        json_error('Cannot delete your own account');
    }

    OutpostDB::delete('users', 'id = ?', [$id]);
    json_response(['success' => true]);
}

// ── Member Admin Handlers ────────────────────────────────
function handle_members_list(): void {
    ensure_users_columns();
    $members = OutpostDB::fetchAll(
        "SELECT id, username, email, display_name, avatar, role, member_since, member_expires, member_status, email_verified, created_at, last_login
         FROM users WHERE role IN ('free_member', 'paid_member') ORDER BY created_at DESC"
    );
    json_response(['members' => $members]);
}

function handle_member_update(): void {
    ensure_users_columns();
    $id = (int) $_GET['id'];
    $data = get_json_body();

    $member = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ? AND role IN (?, ?)', [$id, 'free_member', 'paid_member']);
    if (!$member) json_error('Member not found', 404);

    $update = [];
    if (isset($data['role']) && in_array($data['role'], ['free_member', 'paid_member'])) {
        $update['role'] = $data['role'];
    }
    if (isset($data['member_status']) && in_array($data['member_status'], ['active', 'suspended'])) {
        $update['member_status'] = $data['member_status'];
    }
    if (isset($data['member_expires'])) $update['member_expires'] = $data['member_expires'];
    if (isset($data['email_verified']) && $data['email_verified'] === true) {
        $update['email_verified'] = date('Y-m-d H:i:s');
        $update['verify_token'] = null;
        $update['verify_token_expires'] = null;
    }

    if (empty($update)) json_error('Nothing to update');
    OutpostDB::update('users', $update, 'id = ?', [$id]);
    dispatch_webhook('member.updated', ['id' => $id, 'email' => $member['email'] ?? '', 'role' => $update['role'] ?? $member['role']]);
    json_response(['success' => true]);
}

function handle_member_delete(): void {
    $id = (int) $_GET['id'];
    $member = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ? AND role IN (?, ?)', [$id, 'free_member', 'paid_member']);
    if (!$member) json_error('Member not found', 404);
    OutpostDB::delete('users', 'id = ?', [$id]);
    dispatch_webhook('member.deleted', ['id' => $id, 'email' => $member['email'] ?? '']);
    json_response(['success' => true]);
}

// ── Activity Log Helpers ─────────────────────────────────
function ensure_activity_log_table(): void {
    OutpostDB::query('CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        description TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
}

function log_activity(string $type, string $description): void {
    OutpostDB::insert('activity_log', [
        'type'        => $type,
        'description' => $description,
    ]);
    // Prune to last 100 records
    OutpostDB::query(
        'DELETE FROM activity_log WHERE id NOT IN (SELECT id FROM activity_log ORDER BY created_at DESC LIMIT 100)'
    );
}

// ── Dashboard Handlers ───────────────────────────────────
function handle_dashboard_stats(): void {
    outpost_auto_publish_scheduled();
    $period = $_GET['period'] ?? '30days';
    $days = match($period) {
        '90days' => 90,
        '1year'  => 365,
        default  => 30,
    };

    // Page count (excluding collection URL patterns)
    $collections = OutpostDB::fetchAll('SELECT url_pattern, slug FROM collections');
    $prefixes = [];
    foreach ($collections as $c) {
        $pat = $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}');
        $pfx = explode('{slug}', $pat)[0];
        if ($pfx && $pfx !== '/') $prefixes[] = $pfx;
    }
    $all_pages = OutpostDB::fetchAll("SELECT path FROM pages WHERE path != '__global__'");
    if ($prefixes) {
        $all_pages = array_values(array_filter($all_pages, function($p) use ($prefixes) {
            foreach ($prefixes as $pfx) {
                if (str_starts_with($p['path'], $pfx)) return false;
            }
            return true;
        }));
    }

    // Collection items (published)
    $items_total = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM collection_items WHERE status = 'published'"
    );
    $items_month = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM collection_items WHERE status = 'published' AND created_at >= DATE('now', '-30 days')"
    );

    // Members
    $mem_total = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM users WHERE role IN ('free_member','paid_member')"
    );
    $mem_paid = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM users WHERE role = 'paid_member'"
    );
    $mem_free = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM users WHERE role = 'free_member'"
    );
    $mem_month = OutpostDB::fetchOne(
        "SELECT COUNT(*) as count FROM users WHERE role IN ('free_member','paid_member') AND DATE(created_at) >= DATE('now', '-30 days')"
    );

    // Media
    $media_count = OutpostDB::fetchOne("SELECT COUNT(*) as count FROM media");
    $media_size  = OutpostDB::fetchOne("SELECT COALESCE(SUM(file_size), 0) as total FROM media");
    $media_mb    = round((float)($media_size['total'] ?? 0) / 1048576, 1);

    // Member growth data
    $growth = get_member_growth_data($days);

    // Content activity — pageviews per day from analytics (excludes bots)
    require_once __DIR__ . '/track.php';
    ensure_analytics_tables();
    $content_days = OutpostDB::fetchAll(
        "SELECT DATE(created_at) as date, COUNT(*) as count
         FROM analytics_hits
         WHERE is_bot = 0 AND DATE(created_at) >= DATE('now', ?)
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        ["-{$days} days"]
    );

    // Recent collection items
    $recent_rows = OutpostDB::fetchAll(
        "SELECT ci.id, ci.slug, json_extract(ci.data, '$.title') as title,
                ci.status, ci.published_at, ci.updated_at, c.name as collection_name
         FROM collection_items ci
         JOIN collections c ON c.id = ci.collection_id
         ORDER BY ci.updated_at DESC
         LIMIT 5"
    );
    $recent_items = array_map(function($row) {
        return [
            'id'              => (int)$row['id'],
            'title'           => $row['title'] ?: $row['slug'],
            'status'          => $row['status'],
            'published_at'    => $row['published_at'],
            'updated_at'      => $row['updated_at'],
            'collection_name' => $row['collection_name'],
        ];
    }, $recent_rows);

    // Trigger auto-backup check (non-blocking, silently skips if not due)
    maybe_run_auto_backup();

    json_response(['data' => [
        'totals' => [
            'pages'           => count($all_pages),
            'collection_items'=> (int)($items_total['count'] ?? 0),
            'members_total'   => (int)($mem_total['count'] ?? 0),
            'members_paid'    => (int)($mem_paid['count'] ?? 0),
            'members_free'    => (int)($mem_free['count'] ?? 0),
            'media_count'     => (int)($media_count['count'] ?? 0),
            'media_size_mb'   => $media_mb,
        ],
        'trends' => [
            'items_this_month'   => (int)($items_month['count'] ?? 0),
            'members_this_month' => (int)($mem_month['count'] ?? 0),
        ],
        'growth'           => $growth,
        'content_activity' => array_values($content_days),
        'recent_items'     => $recent_items,
        'period'           => $period,
    ]]);
}

function get_member_growth_data(int $days): array {
    // Fetch ALL member records sorted ascending
    $all_members = OutpostDB::fetchAll(
        "SELECT DATE(created_at) as date, role
         FROM users
         WHERE role IN ('free_member','paid_member')
         ORDER BY created_at ASC"
    );

    // Build daily cumulative totals keyed by date
    $total = 0; $paid = 0;
    $daily = [];
    foreach ($all_members as $m) {
        $total++;
        if ($m['role'] === 'paid_member') $paid++;
        $daily[$m['date']] = ['total' => $total, 'paid' => $paid];
    }

    $step = $days <= 30 ? 1 : ($days <= 90 ? 7 : 14);
    $growth = [];

    for ($i = $days; $i >= 0; $i -= $step) {
        $date = date('Y-m-d', strtotime("-{$i} days"));

        // Find the latest known count at or before this date
        $snap_total = 0; $snap_paid = 0;
        foreach ($daily as $d => $counts) {
            if ($d <= $date) {
                $snap_total = $counts['total'];
                $snap_paid  = $counts['paid'];
            }
        }

        $growth[] = [
            'date'  => $date,
            'total' => $snap_total,
            'paid'  => $snap_paid,
            'free'  => $snap_total - $snap_paid,
        ];
    }

    return $growth;
}

function handle_dashboard_activity(): void {
    ensure_activity_log_table();

    // System events from activity_log
    $log_events = OutpostDB::fetchAll(
        "SELECT type, description, created_at FROM activity_log ORDER BY created_at DESC LIMIT 20"
    );

    // Recent member joins (catches both admin invites and self-registrations)
    $member_joins = OutpostDB::fetchAll(
        "SELECT email, COALESCE(display_name, username, email) as display, created_at
         FROM users
         WHERE role IN ('free_member','paid_member')
         ORDER BY created_at DESC
         LIMIT 10"
    );

    $events = [];
    foreach ($log_events as $e) {
        $events[] = ['type' => $e['type'], 'description' => $e['description'], 'created_at' => $e['created_at']];
    }
    foreach ($member_joins as $m) {
        $events[] = [
            'type'        => 'member',
            'description' => 'New member joined · ' . ($m['email'] ?: $m['display']),
            'created_at'  => $m['created_at'],
        ];
    }

    // Sort by date descending, take top 8
    usort($events, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $events = array_slice($events, 0, 8);

    json_response(['data' => array_values($events)]);
}

// ── Analytics Handlers ────────────────────────────────────

function analytics_date_range(string $period): array {
    $days = match($period) {
        '7days'   => 7,
        '90days'  => 90,
        '12months'=> 365,
        default   => 30,
    };
    $start = date('Y-m-d', strtotime("-{$days} days"));
    $prev  = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
    return ['start' => $start, 'prev' => $prev, 'days' => $days];
}

function handle_analytics(): void {
    require_once __DIR__ . '/track.php';

    $period = $_GET['period'] ?? '30days';
    ['start' => $start, 'prev' => $prev, 'days' => $days] = analytics_date_range($period);

    ensure_analytics_tables();

    // Totals for current period (human traffic only)
    $pv = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM analytics_hits WHERE is_bot = 0 AND DATE(created_at) >= ?",
        [$start]
    );
    $uv = OutpostDB::fetchOne(
        "SELECT COUNT(DISTINCT session_id) as c FROM analytics_hits WHERE is_bot = 0 AND DATE(created_at) >= ?",
        [$start]
    );

    // Previous period totals (for trend %)
    $pv_prev = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM analytics_hits WHERE is_bot = 0 AND DATE(created_at) >= ? AND DATE(created_at) < ?",
        [$prev, $start]
    );
    $uv_prev = OutpostDB::fetchOne(
        "SELECT COUNT(DISTINCT session_id) as c FROM analytics_hits WHERE is_bot = 0 AND DATE(created_at) >= ? AND DATE(created_at) < ?",
        [$prev, $start]
    );

    function pct_change(int $now, int $prev): ?float {
        if ($prev === 0) return null;
        return round((($now - $prev) / $prev) * 100, 1);
    }

    // Avg session duration (seconds): sessions with >=2 hits
    $duration_rows = OutpostDB::fetchAll(
        "SELECT session_id,
                (strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at))) as dur
         FROM analytics_hits
         WHERE is_bot = 0 AND DATE(created_at) >= ?
         GROUP BY session_id
         HAVING COUNT(*) >= 2",
        [$start]
    );
    $avg_dur = 0;
    if (count($duration_rows) > 0) {
        $avg_dur = (int) round(array_sum(array_column($duration_rows, 'dur')) / count($duration_rows));
    }

    // Returning visitors: sessions in current period whose session_id also appears in previous period
    $returning = OutpostDB::fetchOne(
        "SELECT COUNT(DISTINCT a.session_id) as c
         FROM analytics_hits a
         WHERE a.is_bot = 0 AND DATE(a.created_at) >= ?
           AND EXISTS (
               SELECT 1 FROM analytics_hits b
               WHERE b.session_id = a.session_id
                 AND b.is_bot = 0
                 AND DATE(b.created_at) >= ? AND DATE(b.created_at) < ?
           )",
        [$start, $prev, $start]
    );

    $uv_count = (int)($uv['c'] ?? 0);
    $returning_count = (int)($returning['c'] ?? 0);
    $returning_rate = $uv_count > 0 ? round($returning_count / $uv_count, 3) : 0;

    // Chart: daily pageviews + unique visitors
    $chart_rows = OutpostDB::fetchAll(
        "SELECT DATE(created_at) as date,
                COUNT(*) as pageviews,
                COUNT(DISTINCT session_id) as unique_visitors
         FROM analytics_hits
         WHERE is_bot = 0 AND DATE(created_at) >= ?
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        [$start]
    );

    // Fill gaps with zeros
    $chart_by_date = [];
    foreach ($chart_rows as $r) {
        $chart_by_date[$r['date']] = ['pageviews' => (int)$r['pageviews'], 'unique_visitors' => (int)$r['unique_visitors']];
    }
    $chart = [];
    for ($i = $days; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $chart[] = [
            'date'            => $d,
            'pageviews'       => $chart_by_date[$d]['pageviews'] ?? 0,
            'unique_visitors' => $chart_by_date[$d]['unique_visitors'] ?? 0,
        ];
    }

    // Top pages
    $top_pages = OutpostDB::fetchAll(
        "SELECT path,
                COUNT(*) as pageviews,
                COUNT(DISTINCT session_id) as unique_visitors
         FROM analytics_hits
         WHERE is_bot = 0 AND DATE(created_at) >= ?
         GROUP BY path
         ORDER BY pageviews DESC
         LIMIT 20",
        [$start]
    );

    // Session durations per page (avg) — nested aggregates require a subquery
    $page_durations = OutpostDB::fetchAll(
        "SELECT path, AVG(dur) as avg_dur
         FROM (
             SELECT path, session_id,
                    (strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at))) as dur
             FROM analytics_hits
             WHERE is_bot = 0 AND DATE(created_at) >= ?
             GROUP BY path, session_id
             HAVING COUNT(*) >= 2
         ) sub
         GROUP BY path",
        [$start]
    );
    $dur_map = [];
    foreach ($page_durations as $r) {
        if (!isset($dur_map[$r['path']])) $dur_map[$r['path']] = [];
        $dur_map[$r['path']][] = (float)$r['avg_dur'];
    }

    $top_pages = array_map(function($r) use ($dur_map) {
        $path = $r['path'];
        $avg = isset($dur_map[$path]) ? round(array_sum($dur_map[$path]) / count($dur_map[$path])) : 0;
        return [
            'path'           => $path,
            'pageviews'      => (int)$r['pageviews'],
            'unique_visitors'=> (int)$r['unique_visitors'],
            'avg_duration'   => (int)$avg,
        ];
    }, $top_pages);

    // Referrers
    $referrers = OutpostDB::fetchAll(
        "SELECT COALESCE(referrer_domain, 'direct') as domain,
                COUNT(*) as visits
         FROM analytics_hits
         WHERE is_bot = 0 AND DATE(created_at) >= ?
         GROUP BY COALESCE(referrer_domain, 'direct')
         ORDER BY visits DESC
         LIMIT 10",
        [$start]
    );
    $ref_total = array_sum(array_column($referrers, 'visits'));
    $referrers = array_map(function($r) use ($ref_total) {
        return [
            'domain' => $r['domain'],
            'visits' => (int)$r['visits'],
            'share'  => $ref_total > 0 ? round($r['visits'] / $ref_total, 3) : 0,
        ];
    }, $referrers);

    // Devices
    $device_rows = OutpostDB::fetchAll(
        "SELECT device_type, COUNT(*) as c
         FROM analytics_hits
         WHERE is_bot = 0 AND DATE(created_at) >= ?
         GROUP BY device_type",
        [$start]
    );
    $device_total = array_sum(array_column($device_rows, 'c'));
    $devices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
    foreach ($device_rows as $r) {
        if (isset($devices[$r['device_type']])) {
            $devices[$r['device_type']] = $device_total > 0 ? round($r['c'] / $device_total, 3) : 0;
        }
    }

    json_response(['data' => [
        'totals' => [
            'pageviews'        => (int)($pv['c'] ?? 0),
            'unique_visitors'  => $uv_count,
            'avg_session_secs' => $avg_dur,
            'returning_rate'   => $returning_rate,
            'pageviews_prev'   => (int)($pv_prev['c'] ?? 0),
            'unique_prev'      => (int)($uv_prev['c'] ?? 0),
            'pv_change'        => pct_change((int)($pv['c'] ?? 0), (int)($pv_prev['c'] ?? 0)),
            'uv_change'        => pct_change($uv_count, (int)($uv_prev['c'] ?? 0)),
        ],
        'chart'    => $chart,
        'top_pages'=> $top_pages,
        'referrers'=> $referrers,
        'devices'  => $devices,
        'period'   => $period,
    ]]);
}

function handle_analytics_seo(): void {
    ensure_activity_log_table();

    // Build collection URL prefixes to exclude (same logic as handle_pages_list)
    $collections = OutpostDB::fetchAll('SELECT slug, url_pattern FROM collections');
    $prefixes = [];
    foreach ($collections as $c) {
        $pattern = $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}');
        $prefix = explode('{slug}', $pattern)[0];
        if ($prefix && $prefix !== '/') {
            $prefixes[] = $prefix;
        }
    }

    // Fetch all non-collection pages
    $all_pages = OutpostDB::fetchAll(
        "SELECT p.id, p.path, p.title,
                p.meta_title,
                p.meta_description,
                MAX(CASE WHEN f.field_name = 'og_image' THEN f.content END) as og_image,
                GROUP_CONCAT(CASE WHEN f.field_type IN ('text','textarea','richtext') THEN f.content END, ' ') as body_text
         FROM pages p
         LEFT JOIN fields f ON f.page_id = p.id
         WHERE p.path != '__global__'
         GROUP BY p.id"
    );

    // Filter out collection item paths and system/internal pages
    $pages = array_values(array_filter($all_pages, function($page) use ($prefixes) {
        // Exclude system endpoints
        if (str_starts_with($page['path'], '/sync') || str_starts_with($page['path'], '/outpost')) return false;
        foreach ($prefixes as $prefix) {
            // Exclude both /post/slug AND the bare /post path itself
            if (str_starts_with($page['path'], $prefix)) return false;
            if ($page['path'] === rtrim($prefix, '/')) return false;
        }
        return true;
    }));

    // Fetch all published collection items
    $items = OutpostDB::fetchAll(
        "SELECT ci.id, ci.slug, ci.status,
                json_extract(ci.data, '$.title')       as title,
                json_extract(ci.data, '$.excerpt')     as excerpt,
                json_extract(ci.data, '$.meta_description') as meta_description,
                c.name as collection_name, c.slug as collection_slug
         FROM collection_items ci
         JOIN collections c ON c.id = ci.collection_id
         WHERE ci.status = 'published'"
    );

    $critical = [];
    $warnings  = [];
    $passing   = [];
    $score     = 100;

    // Collect all meta descriptions for duplicate check
    $meta_descs = [];
    foreach ($pages as $p) {
        if ($p['meta_description']) $meta_descs[] = $p['meta_description'];
    }
    $dup_descs = array_filter(array_count_values($meta_descs), fn($c) => $c > 1);

    $pages_missing_title = 0;
    $pages_missing_meta  = 0;
    $pages_missing_og    = 0;
    $pages_long_title    = 0;
    $pages_long_meta     = 0;
    $pages_short_meta    = 0;
    $pages_empty         = 0;

    foreach ($pages as $p) {
        $label = $p['title'] ?: $p['path'];
        if (!$p['meta_title'] && !$p['title']) {
            $pages_missing_title++;
        }
        if (!$p['meta_description']) {
            $pages_missing_meta++;
        } else {
            $len = mb_strlen($p['meta_description']);
            if ($len > 160) $pages_long_meta++;
            elseif ($len < 50) $pages_short_meta++;
        }
        if (!$p['og_image']) {
            $pages_missing_og++;
        }
        if ($p['meta_title'] && mb_strlen($p['meta_title']) > 60) {
            $pages_long_title++;
        }
        // Empty body
        $body = trim(strip_tags((string)($p['body_text'] ?? '')));
        if (strlen($body) < 50) $pages_empty++;
    }

    if ($pages_missing_meta > 0) {
        $critical[] = [
            'msg'  => "{$pages_missing_meta} " . ($pages_missing_meta === 1 ? 'page is' : 'pages are') . " missing meta descriptions",
            'link' => 'pages',
        ];
        $score -= min(20, $pages_missing_meta * 5);
    }
    if ($pages_missing_og > 0) {
        $critical[] = ['msg' => "{$pages_missing_og} " . ($pages_missing_og === 1 ? 'page is' : 'pages are') . " missing an OG image", 'link' => 'pages'];
        $score -= min(15, $pages_missing_og * 5);
    }
    if (!empty($dup_descs)) {
        $critical[] = ['msg' => count($dup_descs) . ' duplicate meta ' . (count($dup_descs) === 1 ? 'description' : 'descriptions') . ' found across pages', 'link' => 'pages'];
        $score -= 10;
    }
    if ($pages_missing_title > 0) {
        $warnings[] = ['msg' => "{$pages_missing_title} " . ($pages_missing_title === 1 ? 'page is' : 'pages are') . " missing a title", 'link' => 'pages'];
        $score -= min(10, $pages_missing_title * 3);
    }
    if ($pages_long_title > 0) {
        $warnings[] = ['msg' => "{$pages_long_title} page " . ($pages_long_title === 1 ? 'title exceeds' : 'titles exceed') . " 60 characters", 'link' => 'pages'];
        $score -= min(8, $pages_long_title * 2);
    }
    if ($pages_long_meta > 0) {
        $warnings[] = ['msg' => "{$pages_long_meta} meta " . ($pages_long_meta === 1 ? 'description exceeds' : 'descriptions exceed') . " 160 characters", 'link' => 'pages'];
        $score -= min(8, $pages_long_meta * 2);
    }
    if ($pages_empty > 0) {
        $warnings[] = ['msg' => "{$pages_empty} " . ($pages_empty === 1 ? 'page has' : 'pages have') . " little or no content", 'link' => 'pages'];
        $score -= min(8, $pages_empty * 2);
    }

    // Collection items checks
    $items_no_excerpt = 0;
    foreach ($items as $item) {
        if (!$item['excerpt']) $items_no_excerpt++;
    }
    if ($items_no_excerpt > 0) {
        $warnings[] = ['msg' => "{$items_no_excerpt} published " . ($items_no_excerpt === 1 ? 'item has' : 'items have') . " no excerpt", 'link' => 'collections'];
        $score -= min(8, $items_no_excerpt * 2);
    }

    // Passing checks
    if ($pages_missing_meta === 0) $passing[] = 'All pages have meta descriptions';
    if ($pages_missing_og === 0) $passing[] = 'All pages have OG images';
    if (empty($dup_descs)) $passing[] = 'No duplicate meta descriptions';
    if ($pages_long_title === 0) $passing[] = 'All page titles are under 60 characters';
    if ($pages_long_meta === 0) $passing[] = 'All meta descriptions are within character limits';
    if ($pages_empty === 0) $passing[] = 'All pages have content';
    if ($items_no_excerpt === 0) $passing[] = 'All published items have excerpts';

    $score = max(0, min(100, $score));

    json_response(['data' => [
        'score'    => $score,
        'critical' => $critical,
        'warnings' => $warnings,
        'passing'  => $passing,
    ]]);
}

function handle_analytics_content(): void {
    require_once __DIR__ . '/track.php';
    ensure_analytics_tables();

    $period = $_GET['period'] ?? '30days';
    ['start' => $start, 'days' => $days] = analytics_date_range($period);

    // Collections list
    $collections = OutpostDB::fetchAll('SELECT id, name, slug, url_pattern FROM collections');
    if (empty($collections)) {
        json_response(['data' => ['has_collections' => false]]);
        return;
    }

    // Publishing cadence: items published per week
    $weeks = max(4, (int) ceil($days / 7));
    $cadence = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $week_start = date('Y-m-d', strtotime("-{$i} weeks"));
        $week_end   = date('Y-m-d', strtotime('-' . ($i - 1) . ' weeks'));
        $row = OutpostDB::fetchOne(
            "SELECT COUNT(*) as c FROM collection_items
             WHERE status = 'published' AND DATE(published_at) >= ? AND DATE(published_at) < ?",
            [$week_start, $week_end]
        );
        $cadence[] = ['week_start' => $week_start, 'count' => (int)($row['c'] ?? 0)];
    }

    // Top content: join analytics_hits against item slugs
    $top_content = [];
    foreach ($collections as $coll) {
        $pattern = $coll['url_pattern'] ?: ('/' . $coll['slug'] . '/{slug}');
        $prefix  = rtrim(explode('{slug}', $pattern)[0], '/');

        $items = OutpostDB::fetchAll(
            "SELECT ci.id, ci.slug, ci.published_at, json_extract(ci.data, '$.title') as title
             FROM collection_items ci
             WHERE ci.collection_id = ? AND ci.status = 'published'
             ORDER BY ci.published_at DESC
             LIMIT 20",
            [$coll['id']]
        );

        foreach ($items as $item) {
            $path = $prefix . '/' . $item['slug'];
            $views = OutpostDB::fetchOne(
                "SELECT COUNT(*) as c FROM analytics_hits WHERE is_bot = 0 AND path = ? AND DATE(created_at) >= ?",
                [$path, $start]
            );

            $dur_rows = OutpostDB::fetchAll(
                "SELECT strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at)) as dur
                 FROM analytics_hits WHERE is_bot = 0 AND path = ? AND DATE(created_at) >= ?
                 GROUP BY session_id HAVING COUNT(*) >= 2",
                [$path, $start]
            );
            $avg_dur = 0;
            if (count($dur_rows) > 0) {
                $avg_dur = (int) round(array_sum(array_column($dur_rows, 'dur')) / count($dur_rows));
            }

            $top_content[] = [
                'id'          => (int)$item['id'],
                'title'       => $item['title'] ?: $item['slug'],
                'collection'  => $coll['name'],
                'path'        => $path,
                'views'       => (int)($views['c'] ?? 0),
                'avg_duration'=> $avg_dur,
                'published_at'=> $item['published_at'],
            ];
        }
    }
    usort($top_content, fn($a, $b) => $b['views'] - $a['views']);
    $top_content = array_slice($top_content, 0, 10);

    // Content gap: last published date + monthly counts
    $last_item = OutpostDB::fetchOne(
        "SELECT published_at FROM collection_items WHERE status = 'published' ORDER BY published_at DESC LIMIT 1"
    );
    $this_month = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM collection_items WHERE status = 'published' AND strftime('%Y-%m', published_at) = strftime('%Y-%m', 'now')"
    );
    $last_month = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM collection_items WHERE status = 'published' AND strftime('%Y-%m', published_at) = strftime('%Y-%m', date('now', '-1 month'))"
    );

    $gap_message = '';
    if ($last_item) {
        $days_since = (int) floor((time() - strtotime($last_item['published_at'])) / 86400);
        $this_count = (int)($this_month['c'] ?? 0);
        $last_count = (int)($last_month['c'] ?? 0);
        if ($days_since > 14) {
            $gap_message = "Your last post was {$days_since} days ago. Consistent publishing improves SEO.";
        } elseif ($this_count > $last_count) {
            $diff = $this_count - $last_count;
            $gap_message = "You published {$this_count} " . ($this_count === 1 ? 'post' : 'posts') . " this month — up {$diff} from last month.";
        } else {
            $gap_message = "You published {$this_count} " . ($this_count === 1 ? 'post' : 'posts') . " this month.";
        }
    }

    json_response(['data' => [
        'has_collections' => true,
        'cadence'         => $cadence,
        'top_content'     => $top_content,
        'gap_message'     => $gap_message,
        'period'          => $period,
    ]]);
}

function handle_analytics_members(): void {
    $period = $_GET['period'] ?? '30days';
    ['start' => $start, 'prev' => $prev, 'days' => $days] = analytics_date_range($period);

    $total = OutpostDB::fetchOne("SELECT COUNT(*) as c FROM users WHERE role IN ('free_member','paid_member')");
    $paid  = OutpostDB::fetchOne("SELECT COUNT(*) as c FROM users WHERE role = 'paid_member'");

    if ((int)($total['c'] ?? 0) === 0) {
        json_response(['data' => ['has_members' => false]]);
        return;
    }

    $total_count = (int)($total['c'] ?? 0);
    $paid_count  = (int)($paid['c'] ?? 0);
    $free_count  = $total_count - $paid_count;
    $conversion  = $total_count > 0 ? round($paid_count / $total_count, 3) : 0;

    // MRR estimate
    $price_row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'membership_price'");
    $price = $price_row ? (float)$price_row['value'] : 0;
    $mrr   = $price > 0 ? round($paid_count * $price, 2) : null;

    // Growth chart (same as dashboard)
    $growth = get_member_growth_data($days);

    // New members this period
    $new_this = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM users WHERE role IN ('free_member','paid_member') AND DATE(created_at) >= ?",
        [$start]
    );
    $new_prev = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM users WHERE role IN ('free_member','paid_member') AND DATE(created_at) >= ? AND DATE(created_at) < ?",
        [$prev, $start]
    );
    $new_paid_this = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM users WHERE role = 'paid_member' AND DATE(created_at) >= ?",
        [$start]
    );
    $new_paid_prev = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM users WHERE role = 'paid_member' AND DATE(created_at) >= ? AND DATE(created_at) < ?",
        [$prev, $start]
    );

    // Most popular signup referrer (requires signup_referrer column migration)
    ensure_signup_referrer_column();
    $top_referrer = OutpostDB::fetchOne(
        "SELECT signup_referrer, COUNT(*) as c FROM users
         WHERE role IN ('free_member','paid_member') AND signup_referrer IS NOT NULL AND signup_referrer != ''
         GROUP BY signup_referrer ORDER BY c DESC LIMIT 1"
    );

    // Traffic totals for funnel (use analytics if table exists)
    $visitors = 0;
    try {
        $v = OutpostDB::fetchOne(
            "SELECT COUNT(DISTINCT session_id) as c FROM analytics_hits WHERE is_bot = 0 AND DATE(created_at) >= ?",
            [$start]
        );
        $visitors = (int)($v['c'] ?? 0);
    } catch (\Exception $e) {}

    json_response(['data' => [
        'has_members' => true,
        'totals' => [
            'total'      => $total_count,
            'paid'       => $paid_count,
            'free'       => $free_count,
            'conversion' => $conversion,
            'mrr'        => $mrr,
            'price'      => $price > 0 ? $price : null,
        ],
        'trends' => [
            'new_this_period'      => (int)($new_this['c'] ?? 0),
            'new_paid_this_period' => (int)($new_paid_this['c'] ?? 0),
            'new_prev_period'      => (int)($new_prev['c'] ?? 0),
            'new_paid_prev_period' => (int)($new_paid_prev['c'] ?? 0),
        ],
        'growth'       => $growth,
        'top_referrer' => $top_referrer ? $top_referrer['signup_referrer'] : null,
        'visitors'     => $visitors,
        'period'       => $period,
    ]]);
}

function ensure_signup_referrer_column(): void {
    $cols = OutpostDB::fetchAll("PRAGMA table_info(users)");
    $names = array_column($cols, 'name');
    if (!in_array('signup_referrer', $names)) {
        OutpostDB::query("ALTER TABLE users ADD COLUMN signup_referrer TEXT");
    }
}

// ── Menu / Navigation Handlers ───────────────────────────

function ensure_menus_table(): void {
    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            items TEXT NOT NULL DEFAULT '[]',
            updated_at TEXT DEFAULT (datetime('now'))
        )
    ");
}

function handle_menus_list(): void {
    $rows = OutpostDB::fetchAll(
        "SELECT id, name, slug,
                (SELECT COUNT(*) FROM json_each(items)) as item_count,
                updated_at
         FROM menus
         ORDER BY id ASC"
    );
    // Fallback item_count for SQLite versions without json_each support
    $menus = array_map(function ($row) {
        if (!isset($row['item_count'])) {
            $items = json_decode($row['items'] ?? '[]', true);
            $row['item_count'] = is_array($items) ? count($items) : 0;
        }
        return $row;
    }, $rows);
    json_response(['menus' => $menus]);
}

function handle_menu_get(): void {
    $id = (int)($_GET['id'] ?? 0);
    $row = OutpostDB::fetchOne('SELECT * FROM menus WHERE id = ?', [$id]);
    if (!$row) json_error('Menu not found', 404);
    $row['items'] = json_decode($row['items'], true) ?? [];
    json_response(['menu' => $row]);
}

function handle_menu_create(): void {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    $slug = trim($data['slug'] ?? '');

    if (!$name) json_error('Name is required');
    if (!$slug) json_error('Slug is required');
    if (!preg_match('/^[a-z0-9\-_]+$/', $slug)) json_error('Slug must be lowercase letters, numbers, hyphens, or underscores');

    $existing = OutpostDB::fetchOne('SELECT id FROM menus WHERE slug = ?', [$slug]);
    if ($existing) json_error('A menu with that slug already exists');

    $id = OutpostDB::insert('menus', [
        'name' => $name,
        'slug' => $slug,
        'items' => '[]',
    ]);
    $menu = OutpostDB::fetchOne('SELECT * FROM menus WHERE id = ?', [$id]);
    $menu['items'] = [];
    json_response(['menu' => $menu], 201);
}

function handle_menu_update(): void {
    $id = (int)($_GET['id'] ?? 0);
    $row = OutpostDB::fetchOne('SELECT id FROM menus WHERE id = ?', [$id]);
    if (!$row) json_error('Menu not found', 404);

    $data = get_json_body();
    $updates = ['updated_at' => date('Y-m-d H:i:s')];

    if (isset($data['name'])) {
        $name = trim($data['name']);
        if (!$name) json_error('Name is required');
        $updates['name'] = $name;
    }
    if (isset($data['slug'])) {
        $slug = trim($data['slug']);
        if (!preg_match('/^[a-z0-9\-_]+$/', $slug)) json_error('Slug must be lowercase letters, numbers, hyphens, or underscores');
        $existing = OutpostDB::fetchOne('SELECT id FROM menus WHERE slug = ? AND id != ?', [$slug, $id]);
        if ($existing) json_error('A menu with that slug already exists');
        $updates['slug'] = $slug;
    }
    if (isset($data['items'])) {
        $updates['items'] = json_encode($data['items'], JSON_UNESCAPED_UNICODE);
    }

    OutpostDB::update('menus', $updates, 'id = ?', [$id]);

    // Clear template cache so compiled nav loops pick up new data
    if (function_exists('OutpostTemplate') || class_exists('OutpostTemplate')) {
        // Cache is cleared on next request automatically (filemtime comparison)
    }

    $updated = OutpostDB::fetchOne('SELECT * FROM menus WHERE id = ?', [$id]);
    $updated['items'] = json_decode($updated['items'], true) ?? [];
    json_response(['menu' => $updated]);
}

function handle_menu_delete(): void {
    $id = (int)($_GET['id'] ?? 0);
    $row = OutpostDB::fetchOne('SELECT id FROM menus WHERE id = ?', [$id]);
    if (!$row) json_error('Menu not found', 404);

    OutpostDB::delete('menus', 'id = ?', [$id]);
    json_response(['success' => true]);
}

// ── Forms / Submissions Handlers ─────────────────────────

function ensure_form_submissions_table_api(): void {
    OutpostDB::connect()->exec("
        CREATE TABLE IF NOT EXISTS form_submissions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            form_name  TEXT    NOT NULL,
            data       TEXT    NOT NULL DEFAULT '{}',
            ip         TEXT    NOT NULL DEFAULT '',
            read_at    TEXT,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

/**
 * GET /api.php?action=forms
 * Returns unique form names with total and unread counts.
 */
function handle_forms_list(): void {
    $rows = OutpostDB::fetchAll(
        "SELECT
            form_name,
            COUNT(*) AS total,
            SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread,
            MAX(created_at) AS last_at
         FROM form_submissions
         GROUP BY form_name
         ORDER BY last_at DESC"
    );
    json_response(['forms' => $rows]);
}

/**
 * GET /api.php?action=forms/submissions[&form=contact][&page=1]
 */
function handle_form_submissions(): void {
    $formName = $_GET['form'] ?? '';
    $formId   = $_GET['form_id'] ?? '';
    $status   = $_GET['status'] ?? 'active';
    $starred  = $_GET['starred'] ?? '';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = 25;
    $offset   = ($page - 1) * $perPage;

    $conditions = [];
    $params = [];

    if ($formName) {
        $conditions[] = 'form_name = ?';
        $params[] = $formName;
    }
    if ($formId) {
        $conditions[] = 'form_id = ?';
        $params[] = (int)$formId;
    }
    if ($status && $status !== 'all') {
        $conditions[] = 'status = ?';
        $params[] = $status;
    }
    if ($starred !== '') {
        $conditions[] = 'starred = ?';
        $params[] = (int)$starred;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $total = OutpostDB::fetchOne(
        "SELECT COUNT(*) as cnt FROM form_submissions {$where}",
        $params
    );

    $rows = OutpostDB::fetchAll(
        "SELECT id, form_name, form_id, data, ip, read_at, status, starred, notes, user_agent, created_at
         FROM form_submissions
         {$where}
         ORDER BY created_at DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    // Decode data JSON for each row
    $submissions = array_map(function ($row) {
        $row['data'] = json_decode($row['data'], true) ?? [];
        return $row;
    }, $rows);

    json_response([
        'submissions' => $submissions,
        'total'       => (int)($total['cnt'] ?? 0),
        'page'        => $page,
        'per_page'    => $perPage,
    ]);
}

/**
 * PUT /api.php?action=forms/submissions&id=X
 * Mark a submission as read.
 */
function handle_form_submission_read(): void {
    $id = (int)($_GET['id'] ?? 0);
    $row = OutpostDB::fetchOne('SELECT id FROM form_submissions WHERE id = ?', [$id]);
    if (!$row) json_error('Submission not found', 404);

    OutpostDB::update('form_submissions', ['read_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    json_response(['success' => true]);
}

/**
 * DELETE /api.php?action=forms/submissions&id=X
 */
function handle_form_submission_delete(): void {
    $id = (int)($_GET['id'] ?? 0);
    $row = OutpostDB::fetchOne('SELECT id FROM form_submissions WHERE id = ?', [$id]);
    if (!$row) json_error('Submission not found', 404);

    OutpostDB::delete('form_submissions', 'id = ?', [$id]);
    json_response(['success' => true]);
}

/**
 * GET /api.php?action=forms/config[&form=contact]
 * Returns notify_email for one form, or all form configs.
 */
function handle_form_config_get(): void {
    $formName = $_GET['form'] ?? '';
    if ($formName) {
        $row = OutpostDB::fetchOne(
            'SELECT notify_email FROM form_configs WHERE form_name = ?',
            [$formName]
        );
        json_response(['notify_email' => $row['notify_email'] ?? '']);
    } else {
        $rows = OutpostDB::fetchAll('SELECT form_name, notify_email FROM form_configs');
        $configs = [];
        foreach ($rows as $r) {
            $configs[$r['form_name']] = $r['notify_email'];
        }
        json_response(['configs' => $configs]);
    }
}

/**
 * PUT /api.php?action=forms/config
 * Body: { form_name, notify_email }
 */
function handle_form_config_set(): void {
    $body        = get_json_body();
    $formName    = trim($body['form_name']    ?? '');
    $notifyEmail = trim($body['notify_email'] ?? '');

    if (!$formName) json_error('form_name is required');

    $existing = OutpostDB::fetchOne(
        'SELECT id FROM form_configs WHERE form_name = ?',
        [$formName]
    );
    if ($existing) {
        OutpostDB::update('form_configs', ['notify_email' => $notifyEmail], 'form_name = ?', [$formName]);
    } else {
        OutpostDB::insert('form_configs', ['form_name' => $formName, 'notify_email' => $notifyEmail]);
    }
    json_response(['success' => true]);
}

/**
 * GET /api.php?action=forms/export[&form=contact]
 * Streams all submissions (or filtered by form) as a CSV download.
 */
function handle_forms_export(): void {
    $formName = $_GET['form'] ?? '';

    if ($formName) {
        $where    = 'WHERE form_name = ?';
        $params   = [$formName];
        $filename = 'submissions-' . preg_replace('/[^a-z0-9_-]/i', '-', $formName) . '.csv';
    } else {
        $where    = '';
        $params   = [];
        $filename = 'submissions-all.csv';
    }

    $rows = OutpostDB::fetchAll(
        "SELECT id, form_name, data, ip, read_at, created_at FROM form_submissions {$where} ORDER BY created_at DESC",
        $params
    );

    // Collect all field names across all submissions (preserving order of first occurrence)
    $allFields = [];
    $decoded   = [];
    foreach ($rows as $row) {
        $data      = json_decode($row['data'], true) ?? [];
        $decoded[] = $data;
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allFields, true)) {
                $allFields[] = $key;
            }
        }
    }

    // Override Content-Type set at the top of api.php
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'form', 'date', 'ip', 'read', ...$allFields]);
    foreach ($rows as $i => $row) {
        $line = [
            $row['id'],
            $row['form_name'],
            $row['created_at'],
            $row['ip'],
            $row['read_at'] ? 'yes' : 'no',
        ];
        $data = $decoded[$i];
        foreach ($allFields as $field) {
            $line[] = $data[$field] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function handle_search_content(): void {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { json_response(['results' => []]); return; }
    $like = '%' . $q . '%';
    $results = [];

    // Pages — mirror handle_pages_list exactly: JOIN with fields so only pages that
    // have real content for the active theme are returned. Ghost rows (old collection
    // item paths, renamed slugs, etc.) have no field entries and are naturally excluded
    // regardless of what the current collection slug is.
    $activeTheme = get_active_theme();
    $hasThemeFields = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM fields WHERE theme = ?", [$activeTheme]
    );
    if (($hasThemeFields['c'] ?? 0) > 0) {
        $pages = OutpostDB::fetchAll(
            "SELECT DISTINCT p.id, p.path, p.title FROM pages p
             JOIN fields f ON f.page_id = p.id
             WHERE f.theme = ? AND p.path != '__global__' AND (p.title LIKE ? OR p.path LIKE ?)
             ORDER BY p.updated_at DESC LIMIT 8",
            [$activeTheme, $like, $like]
        );
    } else {
        // Fallback for fresh installs with no theme fields yet: use prefix exclusion
        $collections = OutpostDB::fetchAll('SELECT slug, url_pattern FROM collections');
        $prefixes = [];
        foreach ($collections as $c) {
            $pattern = $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}');
            $prefix = explode('{slug}', $pattern)[0];
            if ($prefix && $prefix !== '/') $prefixes[] = $prefix;
        }
        $pages = OutpostDB::fetchAll(
            "SELECT id, path, title FROM pages
             WHERE path != '__global__' AND (title LIKE ? OR path LIKE ?)
             ORDER BY updated_at DESC LIMIT 20",
            [$like, $like]
        );
        if ($prefixes) {
            $pages = array_values(array_filter($pages, function($p) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($p['path'], $prefix)) return false;
                }
                return true;
            }));
        }
        $pages = array_slice($pages, 0, 8);
    }
    foreach ($pages as $p) {
        $results[] = ['type' => 'page', 'id' => $p['id'],
            'title' => ($p['title'] ?: $p['path']), 'subtitle' => $p['path'], 'meta' => []];
    }

    // Collection items — LIKE on JSON text column
    $rows = OutpostDB::fetchAll(
        "SELECT ci.id, ci.slug, ci.data, ci.status,
                c.slug AS collection_slug, c.name AS collection_name
         FROM collection_items ci JOIN collections c ON ci.collection_id = c.id
         WHERE ci.data LIKE ? OR ci.slug LIKE ?
         ORDER BY ci.updated_at DESC LIMIT 12",
        [$like, $like]
    );
    foreach ($rows as $row) {
        $data  = json_decode($row['data'], true) ?: [];
        $title = $data['title'] ?? $data['name'] ?? $data['heading'] ?? $row['slug'];
        $results[] = ['type' => 'item', 'id' => $row['id'], 'title' => $title,
            'subtitle' => $row['collection_name'], 'status' => $row['status'],
            'meta' => ['collection_slug' => $row['collection_slug']]];
    }

    // Media
    $media = OutpostDB::fetchAll(
        "SELECT id, original_name, mime_type FROM media
         WHERE original_name LIKE ? OR alt_text LIKE ?
         ORDER BY uploaded_at DESC LIMIT 6",
        [$like, $like]
    );
    foreach ($media as $m) {
        $results[] = ['type' => 'media', 'id' => $m['id'], 'title' => $m['original_name'],
            'subtitle' => $m['mime_type'], 'meta' => []];
    }

    json_response(['results' => $results]);
}

/**
 * Migration: add status + published_at columns to pages table.
 * Default is 'published' so all existing pages remain live.
 */
function ensure_pages_status_column(): void {
    $db   = OutpostDB::connect();
    $cols = $db->query("PRAGMA table_info(pages)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('status', $names)) {
        $db->exec("ALTER TABLE pages ADD COLUMN status TEXT NOT NULL DEFAULT 'published'");
    }
    if (!in_array('published_at', $names)) {
        $db->exec("ALTER TABLE pages ADD COLUMN published_at TEXT");
    }
    if (!in_array('visibility', $names)) {
        $db->exec("ALTER TABLE pages ADD COLUMN visibility TEXT DEFAULT 'public'");
    }
}

/**
 * Migration: form_configs table
 */
function ensure_form_configs_table(): void {
    OutpostDB::connect()->exec("
        CREATE TABLE IF NOT EXISTS form_configs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            form_name    TEXT NOT NULL UNIQUE,
            notify_email TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

// ── Forms Builder Migrations ────────────────────────────

function ensure_forms_builder_table(): void {
    OutpostDB::connect()->exec("
        CREATE TABLE IF NOT EXISTS forms (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            slug          TEXT NOT NULL UNIQUE,
            name          TEXT NOT NULL,
            fields        TEXT NOT NULL DEFAULT '[]',
            settings      TEXT NOT NULL DEFAULT '{}',
            notifications TEXT NOT NULL DEFAULT '[]',
            feeds         TEXT NOT NULL DEFAULT '[]',
            status        TEXT NOT NULL DEFAULT 'active',
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

function ensure_form_submissions_extra_columns(): void {
    $db   = OutpostDB::connect();
    $cols = $db->query("PRAGMA table_info(form_submissions)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (!in_array('form_id', $names)) {
        $db->exec("ALTER TABLE form_submissions ADD COLUMN form_id INTEGER");
    }
    if (!in_array('status', $names)) {
        $db->exec("ALTER TABLE form_submissions ADD COLUMN status TEXT NOT NULL DEFAULT 'active'");
    }
    if (!in_array('starred', $names)) {
        $db->exec("ALTER TABLE form_submissions ADD COLUMN starred INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('notes', $names)) {
        $db->exec("ALTER TABLE form_submissions ADD COLUMN notes TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('user_agent', $names)) {
        $db->exec("ALTER TABLE form_submissions ADD COLUMN user_agent TEXT NOT NULL DEFAULT ''");
    }
}

// ── Forms Builder CRUD ──────────────────────────────────

function handle_forms_builder_list(): void {
    $forms = OutpostDB::fetchAll('SELECT * FROM forms ORDER BY updated_at DESC');
    foreach ($forms as &$f) {
        $f['fields'] = json_decode($f['fields'], true) ?: [];
        $f['settings'] = json_decode($f['settings'], true) ?: [];
        $count = OutpostDB::fetchOne(
            'SELECT COUNT(*) as cnt FROM form_submissions WHERE form_id = ?',
            [$f['id']]
        );
        $f['submission_count'] = (int)($count['cnt'] ?? 0);
    }
    json_response(['forms' => $forms]);
}

function handle_forms_builder_get(): void {
    $id = (int)$_GET['id'];
    $form = OutpostDB::fetchOne('SELECT * FROM forms WHERE id = ?', [$id]);
    if (!$form) json_error('Form not found', 404);
    $form['fields'] = json_decode($form['fields'], true) ?: [];
    $form['settings'] = json_decode($form['settings'], true) ?: [];
    $form['notifications'] = json_decode($form['notifications'], true) ?: [];
    $form['feeds'] = json_decode($form['feeds'], true) ?: [];
    json_response(['form' => $form]);
}

function handle_forms_builder_create(): void {
    $data = get_json_body();
    $slug = trim($data['slug'] ?? '');
    $name = trim($data['name'] ?? '');

    if (!$slug || !$name) json_error('Slug and name are required');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) json_error('Slug must be lowercase alphanumeric with hyphens/underscores');

    $existing = OutpostDB::fetchOne('SELECT id FROM forms WHERE slug = ?', [$slug]);
    if ($existing) json_error('A form with this slug already exists');

    $fields   = isset($data['fields']) ? json_encode($data['fields']) : '[]';
    $settings = isset($data['settings']) ? json_encode($data['settings']) : '{}';

    $id = OutpostDB::insert('forms', [
        'slug'     => $slug,
        'name'     => $name,
        'fields'   => $fields,
        'settings' => $settings,
        'status'   => $data['status'] ?? 'active',
    ]);
    json_response(['success' => true, 'id' => $id], 201);
}

function handle_forms_builder_update(): void {
    $id   = (int)$_GET['id'];
    $data = get_json_body();

    $current = OutpostDB::fetchOne('SELECT * FROM forms WHERE id = ?', [$id]);
    if (!$current) json_error('Form not found', 404);

    $update = ['updated_at' => date('Y-m-d H:i:s')];
    if (isset($data['name']))          $update['name']          = trim($data['name']);
    if (isset($data['slug']))          $update['slug']          = trim($data['slug']);
    if (isset($data['fields']))        $update['fields']        = json_encode($data['fields']);
    if (isset($data['settings']))      $update['settings']      = json_encode($data['settings']);
    if (isset($data['notifications'])) $update['notifications'] = json_encode($data['notifications']);
    if (isset($data['feeds']))         $update['feeds']         = json_encode($data['feeds']);
    if (isset($data['status']))        $update['status']        = $data['status'];

    // Slug uniqueness check
    if (isset($update['slug']) && $update['slug'] !== $current['slug']) {
        $existing = OutpostDB::fetchOne('SELECT id FROM forms WHERE slug = ? AND id != ?', [$update['slug'], $id]);
        if ($existing) json_error('A form with this slug already exists');
    }

    OutpostDB::update('forms', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_forms_builder_delete(): void {
    $id = (int)$_GET['id'];
    $form = OutpostDB::fetchOne('SELECT id FROM forms WHERE id = ?', [$id]);
    if (!$form) json_error('Form not found', 404);
    OutpostDB::delete('forms', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_forms_builder_duplicate(): void {
    $id = (int)$_GET['id'];
    $form = OutpostDB::fetchOne('SELECT * FROM forms WHERE id = ?', [$id]);
    if (!$form) json_error('Form not found', 404);

    // Generate unique slug
    $baseSlug = $form['slug'] . '-copy';
    $slug = $baseSlug;
    $n = 1;
    while (OutpostDB::fetchOne('SELECT id FROM forms WHERE slug = ?', [$slug])) {
        $n++;
        $slug = $baseSlug . '-' . $n;
    }

    $newId = OutpostDB::insert('forms', [
        'slug'          => $slug,
        'name'          => $form['name'] . ' (Copy)',
        'fields'        => $form['fields'],
        'settings'      => $form['settings'],
        'notifications' => $form['notifications'],
        'feeds'         => $form['feeds'],
        'status'        => 'draft',
    ]);
    json_response(['success' => true, 'id' => $newId], 201);
}

// ── Enhanced Submission Endpoints ───────────────────────

function handle_form_submission_star(): void {
    $id = (int)$_GET['id'];
    $row = OutpostDB::fetchOne('SELECT id, starred FROM form_submissions WHERE id = ?', [$id]);
    if (!$row) json_error('Submission not found', 404);
    $newVal = $row['starred'] ? 0 : 1;
    OutpostDB::update('form_submissions', ['starred' => $newVal], 'id = ?', [$id]);
    json_response(['success' => true, 'starred' => $newVal]);
}

function handle_form_submission_status(): void {
    $id   = (int)$_GET['id'];
    $body = get_json_body();
    $status = $body['status'] ?? '';
    if (!in_array($status, ['active', 'archived', 'trashed'])) json_error('Invalid status');
    $row = OutpostDB::fetchOne('SELECT id FROM form_submissions WHERE id = ?', [$id]);
    if (!$row) json_error('Submission not found', 404);
    OutpostDB::update('form_submissions', ['status' => $status], 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_form_submission_notes(): void {
    $id   = (int)$_GET['id'];
    $body = get_json_body();
    $notes = $body['notes'] ?? '';
    $row = OutpostDB::fetchOne('SELECT id FROM form_submissions WHERE id = ?', [$id]);
    if (!$row) json_error('Submission not found', 404);
    OutpostDB::update('form_submissions', ['notes' => $notes], 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_form_submissions_bulk(): void {
    $body = get_json_body();
    $ids    = $body['ids'] ?? [];
    $action = $body['action'] ?? '';
    if (empty($ids) || !is_array($ids)) json_error('ids array required');
    if (!in_array($action, ['read', 'star', 'unstar', 'archive', 'delete', 'restore'])) json_error('Invalid action');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $intIds = array_map('intval', $ids);

    switch ($action) {
        case 'read':
            OutpostDB::query(
                "UPDATE form_submissions SET read_at = datetime('now') WHERE id IN ({$placeholders})",
                $intIds
            );
            break;
        case 'star':
            OutpostDB::query("UPDATE form_submissions SET starred = 1 WHERE id IN ({$placeholders})", $intIds);
            break;
        case 'unstar':
            OutpostDB::query("UPDATE form_submissions SET starred = 0 WHERE id IN ({$placeholders})", $intIds);
            break;
        case 'archive':
            OutpostDB::query("UPDATE form_submissions SET status = 'archived' WHERE id IN ({$placeholders})", $intIds);
            break;
        case 'delete':
            OutpostDB::query("DELETE FROM form_submissions WHERE id IN ({$placeholders})", $intIds);
            break;
        case 'restore':
            OutpostDB::query("UPDATE form_submissions SET status = 'active' WHERE id IN ({$placeholders})", $intIds);
            break;
    }
    json_response(['success' => true]);
}

/**
 * POST /api.php?action=forms/test-smtp
 * Send a test email using the provided config (or current DB settings).
 * Body: { smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password,
 *         from_name, from_email, notify_email }
 */
function handle_test_smtp(): void {
    outpost_require_cap('settings.*');

    $body = get_json_body();

    // If no host in body, load from DB
    if (empty($body['smtp_host'])) {
        $rows = OutpostDB::fetchAll('SELECT key, value FROM settings');
        foreach ($rows as $r) {
            if (!isset($body[$r['key']])) {
                $body[$r['key']] = $r['value'];
            }
        }
    }

    $toEmail = $body['notify_email'] ?? $body['from_email'] ?? '';
    if (!$toEmail) {
        json_error('Set a Notification Email first so we know where to send the test.');
    }

    try {
        $mailer = new OutpostMailer($body);
        $mailer->send(
            $toEmail,
            'Outpost SMTP Test',
            "This is a test email from Outpost CMS.\n\nIf you received this, your SMTP settings are working correctly."
        );
        json_response(['success' => true, 'message' => "Test email sent to {$toEmail}"]);
    } catch (Throwable $e) {
        json_error('SMTP test failed: ' . $e->getMessage());
    }
}

// ── Revisions ────────────────────────────────────────────

function ensure_revisions_table(): void {
    $exists = OutpostDB::fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='revisions'");
    if (!$exists) {
        OutpostDB::connect()->exec("
            CREATE TABLE IF NOT EXISTS revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                meta TEXT NOT NULL DEFAULT '{}',
                created_by INTEGER,
                created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_revisions_entity
                ON revisions(entity_type, entity_id, created_at DESC);
        ");
    }
}

function create_revision(string $entityType, int $entityId, array $data, array $meta): void {
    $userId = null;
    $user = OutpostAuth::currentUser();
    if ($user) $userId = $user['id'];

    OutpostDB::insert('revisions', [
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'data'        => json_encode($data, JSON_UNESCAPED_UNICODE),
        'meta'        => json_encode($meta, JSON_UNESCAPED_UNICODE),
        'created_by'  => $userId,
    ]);

    // Prune: keep only last 25 revisions per entity
    OutpostDB::query(
        "DELETE FROM revisions WHERE entity_type = ? AND entity_id = ? AND id NOT IN (
            SELECT id FROM revisions WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC LIMIT 25
        )",
        [$entityType, $entityId, $entityType, $entityId]
    );
}

function handle_revisions_list(): void {
    $entityType = $_GET['entity_type'] ?? '';
    $entityId = (int) ($_GET['entity_id'] ?? 0);

    if (!in_array($entityType, ['page', 'item'])) json_error('Invalid entity_type');
    if (!$entityId) json_error('Missing entity_id');

    $revisions = OutpostDB::fetchAll(
        "SELECT r.id, r.entity_type, r.entity_id, r.data, r.created_at, r.created_by, u.display_name, u.username
         FROM revisions r
         LEFT JOIN users u ON r.created_by = u.id
         WHERE r.entity_type = ? AND r.entity_id = ?
         ORDER BY r.created_at DESC
         LIMIT 25",
        [$entityType, $entityId]
    );

    // Decode data for diff counting
    $decoded = [];
    foreach ($revisions as $i => $rev) {
        $decoded[$i] = json_decode($rev['data'] ?? '{}', true) ?: [];
    }

    foreach ($revisions as $i => &$rev) {
        $current = $decoded[$i];
        $older = $decoded[$i + 1] ?? null;

        if ($older === null) {
            // Oldest revision — count non-empty fields
            $rev['field_count'] = count(array_filter($current, fn($v) => $v !== '' && $v !== '[]'));
        } else {
            // Only count fields present in BOTH snapshots with different values
            $changed = 0;
            $commonKeys = array_intersect_key($current, $older);
            foreach ($commonKeys as $k => $v) {
                if ($older[$k] !== $v) $changed++;
            }
            $rev['field_count'] = $changed;
        }

        $rev['user'] = $rev['display_name'] ?: ($rev['username'] ?? null);
        unset($rev['display_name'], $rev['username'], $rev['data']);
    }
    unset($rev);

    json_response(['revisions' => $revisions]);
}

function handle_revision_diff(): void {
    $entityType = $_GET['entity_type'] ?? '';
    $entityId = (int) ($_GET['entity_id'] ?? 0);
    $revisionId = (int) ($_GET['revision_id'] ?? 0);

    if (!in_array($entityType, ['page', 'item'])) json_error('Invalid entity_type');
    if (!$entityId || !$revisionId) json_error('Missing entity_id or revision_id');

    $revision = OutpostDB::fetchOne(
        'SELECT data, created_at FROM revisions WHERE id = ? AND entity_type = ? AND entity_id = ?',
        [$revisionId, $entityType, $entityId]
    );
    if (!$revision) json_error('Revision not found', 404);

    $current = json_decode($revision['data'], true) ?: [];

    // Find the immediately preceding revision
    $prev = OutpostDB::fetchOne(
        'SELECT data FROM revisions WHERE entity_type = ? AND entity_id = ? AND created_at < ? ORDER BY created_at DESC LIMIT 1',
        [$entityType, $entityId, $revision['created_at']]
    );
    $older = $prev ? (json_decode($prev['data'], true) ?: []) : [];

    // Build field type lookup for pages
    $fieldTypes = [];
    if ($entityType === 'page') {
        $rows = OutpostDB::fetchAll('SELECT field_name, field_type FROM fields WHERE page_id = ?', [$entityId]);
        foreach ($rows as $r) $fieldTypes[$r['field_name']] = $r['field_type'];
    }

    $changes = [];

    // Only diff fields present in both snapshots (avoids phantom diffs from format changes)
    // For the first revision (no predecessor), show all non-empty fields
    $keysToCompare = empty($older)
        ? array_keys($current)
        : array_keys(array_intersect_key($current, $older));
    sort($keysToCompare);

    foreach ($keysToCompare as $key) {
        $newVal = $current[$key] ?? '';
        $oldVal = $older[$key] ?? '';
        if ($newVal === $oldVal) continue;
        // For first revision, skip empty fields
        if (empty($older) && ($newVal === '' || $newVal === '[]')) continue;

        $type = $fieldTypes[$key] ?? 'text';
        $isRich = $type === 'richtext' || (str_contains($newVal, '<') && str_contains($newVal, '>'));

        if ($isRich) {
            $changes[] = ['field' => $key, 'type' => 'richtext', 'old' => '', 'new' => ''];
        } else if ($type === 'image' || $type === 'gallery') {
            $changes[] = ['field' => $key, 'type' => $type, 'old' => '', 'new' => ''];
        } else {
            $changes[] = [
                'field' => $key,
                'type' => $type,
                'old' => mb_strimwidth($oldVal, 0, 200, '…'),
                'new' => mb_strimwidth($newVal, 0, 200, '…'),
            ];
        }
    }

    json_response(['changes' => $changes]);
}

function handle_revision_restore(): void {
    $body = get_json_body();
    $entityType = $body['entity_type'] ?? '';
    $entityId = (int) ($body['entity_id'] ?? 0);
    $revisionId = (int) ($body['revision_id'] ?? 0);

    if (!in_array($entityType, ['page', 'item'])) json_error('Invalid entity_type');
    if (!$entityId || !$revisionId) json_error('Missing entity_id or revision_id');

    $revision = OutpostDB::fetchOne('SELECT * FROM revisions WHERE id = ? AND entity_type = ? AND entity_id = ?', [$revisionId, $entityType, $entityId]);
    if (!$revision) json_error('Revision not found', 404);

    $revData = json_decode($revision['data'], true) ?: [];
    $revMeta = json_decode($revision['meta'], true) ?: [];

    if ($entityType === 'item') {
        // Snapshot current state first (so restore is undoable)
        $current = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$entityId]);
        if (!$current) json_error('Item not found', 404);
        create_revision('item', $entityId,
            json_decode($current['data'] ?? '{}', true) ?: [],
            ['slug' => $current['slug'], 'status' => $current['status'], 'published_at' => $current['published_at'] ?? null, 'scheduled_at' => $current['scheduled_at'] ?? null]
        );

        $update = ['data' => json_encode($revData, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
        if (isset($revMeta['slug'])) $update['slug'] = $revMeta['slug'];
        if (isset($revMeta['status'])) $update['status'] = $revMeta['status'];
        OutpostDB::update('collection_items', $update, 'id = ?', [$entityId]);

    } elseif ($entityType === 'page') {
        // Snapshot current state first
        $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE id = ?', [$entityId]);
        if (!$page) json_error('Page not found', 404);
        $activeTheme = get_active_theme();
        $allFields = OutpostDB::fetchAll(
            "SELECT field_name, content, theme FROM fields WHERE page_id = ? AND (theme = ? OR theme = '') ORDER BY theme DESC",
            [$entityId, $activeTheme]
        );
        $fieldData = [];
        foreach ($allFields as $af) $fieldData[$af['field_name']] ??= $af['content'];
        create_revision('page', $entityId, $fieldData, [
            'title' => $page['title'] ?? '',
            'meta_title' => $page['meta_title'] ?? '',
            'meta_description' => $page['meta_description'] ?? '',
            'visibility' => $page['visibility'] ?? 'public',
            'status' => $page['status'] ?? 'published',
        ]);

        // Restore field content — try theme-scoped first, fall back to unscoped
        $now = date('Y-m-d H:i:s');
        foreach ($revData as $fieldName => $content) {
            $stmt = OutpostDB::query(
                "UPDATE fields SET content = ?, updated_at = ? WHERE page_id = ? AND field_name = ? AND theme = ?",
                [$content, $now, $entityId, $fieldName, $activeTheme]
            );
            if ($stmt->rowCount() === 0) {
                OutpostDB::query(
                    "UPDATE fields SET content = ?, updated_at = ? WHERE page_id = ? AND field_name = ? AND theme = ''",
                    [$content, $now, $entityId, $fieldName]
                );
            }
        }

        // Restore page meta
        $metaUpdate = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (['title', 'meta_title', 'meta_description', 'visibility', 'status'] as $key) {
            if (isset($revMeta[$key])) $metaUpdate[$key] = $revMeta[$key];
        }
        OutpostDB::update('pages', $metaUpdate, 'id = ?', [$entityId]);

        // Clear template cache
        outpost_clear_cache();
    }

    json_response(['success' => true]);
}

// ── API Keys ─────────────────────────────────────────────

function ensure_api_keys_table(): void {
    $exists = OutpostDB::fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='api_keys'");
    if (!$exists) {
        OutpostDB::connect()->exec("
            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT '',
                key_hash TEXT NOT NULL,
                key_prefix TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                last_used_at TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }
}

function handle_apikeys_list(): void {
    $keys = OutpostDB::fetchAll("
        SELECT ak.id, ak.name, ak.key_prefix, ak.last_used_at, ak.created_at,
               u.username, u.display_name
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        ORDER BY ak.created_at DESC
    ");
    json_response(['keys' => $keys]);
}

function handle_apikey_create(): void {
    $body = get_json_body();
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        json_error('Name is required');
    }

    $user = OutpostAuth::currentUser();
    if (!$user) {
        json_error('No authenticated user', 401);
    }

    // Generate a 64-char hex key with "op_" prefix
    $rawKey = bin2hex(random_bytes(32));
    $fullKey = 'op_' . $rawKey;
    $prefix = substr($fullKey, 0, 11); // "op_" + 8 hex chars

    $id = OutpostDB::insert('api_keys', [
        'name'     => $name,
        'key_hash' => password_hash($fullKey, PASSWORD_BCRYPT, ['cost' => 12]),
        'key_prefix' => $prefix,
        'user_id'  => $user['id'],
    ]);

    json_response([
        'success' => true,
        'key'     => $fullKey,
        'id'      => $id,
        'prefix'  => $prefix,
    ]);
}

function handle_apikey_delete(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Invalid key ID');
    }
    $deleted = OutpostDB::delete('api_keys', 'id = ?', [$id]);
    if ($deleted === 0) {
        json_error('Key not found', 404);
    }
    json_response(['success' => true]);
}

// ── Goals table migration ─────────────────────────────────────

function ensure_analytics_goals_table(): void {
    OutpostDB::query('CREATE TABLE IF NOT EXISTS analytics_goals (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        type       TEXT NOT NULL,
        target     TEXT NOT NULL,
        active     INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
}

// ── Events analytics ──────────────────────────────────────────

function handle_analytics_events(): void {
    require_once __DIR__ . '/track.php';
    ensure_analytics_events_table();

    $period = $_GET['period'] ?? '30days';
    ['start' => $start, 'prev' => $prev, 'days' => $days] = analytics_date_range($period);

    $total = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM analytics_events WHERE is_bot = 0 AND DATE(created_at) >= ?",
        [$start]
    );
    $unique = OutpostDB::fetchOne(
        "SELECT COUNT(DISTINCT session_id) as c FROM analytics_events WHERE is_bot = 0 AND DATE(created_at) >= ?",
        [$start]
    );
    $total_prev = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM analytics_events WHERE is_bot = 0 AND DATE(created_at) >= ? AND DATE(created_at) < ?",
        [$prev, $start]
    );

    $total_count = (int)($total['c'] ?? 0);
    $total_prev_count = (int)($total_prev['c'] ?? 0);
    $change = $total_prev_count > 0
        ? round(($total_count - $total_prev_count) / $total_prev_count * 100, 1)
        : ($total_count > 0 ? 100 : 0);

    $auto_names = ['outbound_click', 'file_download', 'form_submit'];
    $ph = implode(',', array_fill(0, count($auto_names), '?'));
    $auto = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c FROM analytics_events WHERE is_bot = 0 AND DATE(created_at) >= ? AND name IN ($ph)",
        array_merge([$start], $auto_names)
    );
    $auto_count = (int)($auto['c'] ?? 0);
    $custom_count = $total_count - $auto_count;

    $top_events = OutpostDB::fetchAll(
        "SELECT name, COUNT(*) as count, COUNT(DISTINCT session_id) as unique_sessions
         FROM analytics_events WHERE is_bot = 0 AND DATE(created_at) >= ?
         GROUP BY name ORDER BY count DESC LIMIT 20",
        [$start]
    );
    foreach ($top_events as &$ev) {
        $prev_row = OutpostDB::fetchOne(
            "SELECT COUNT(*) as c FROM analytics_events WHERE is_bot = 0 AND name = ? AND DATE(created_at) >= ? AND DATE(created_at) < ?",
            [$ev['name'], $prev, $start]
        );
        $prev_c = (int)($prev_row['c'] ?? 0);
        $ev['prev_count'] = $prev_c;
        $ev['change'] = $prev_c > 0
            ? round(((int)$ev['count'] - $prev_c) / $prev_c * 100, 1)
            : ((int)$ev['count'] > 0 ? 100 : 0);
    }
    unset($ev);

    $chart = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $row = OutpostDB::fetchOne(
            "SELECT COUNT(*) as c FROM analytics_events WHERE is_bot = 0 AND DATE(created_at) = ?",
            [$date]
        );
        $chart[] = ['date' => $date, 'count' => (int)($row['c'] ?? 0)];
    }

    $recent = OutpostDB::fetchAll(
        "SELECT name, path, properties, created_at FROM analytics_events
         WHERE is_bot = 0 ORDER BY created_at DESC LIMIT 20"
    );
    foreach ($recent as &$r) {
        $r['properties'] = $r['properties'] ? json_decode($r['properties'], true) : null;
    }
    unset($r);

    json_response(['data' => [
        'total_events'    => $total_count,
        'unique_sessions' => (int)($unique['c'] ?? 0),
        'total_prev'      => $total_prev_count,
        'events_change'   => $change,
        'auto_count'      => $auto_count,
        'custom_count'    => $custom_count,
        'top_events'      => $top_events,
        'chart'           => $chart,
        'recent'          => $recent,
        'period'          => $period,
    ]]);
}

function handle_analytics_events_detail(): void {
    require_once __DIR__ . '/track.php';
    ensure_analytics_events_table();

    $name = trim($_GET['name'] ?? '');
    if (!$name) json_error('Event name required');

    $period = $_GET['period'] ?? '30days';
    ['start' => $start, 'days' => $days] = analytics_date_range($period);

    $total = OutpostDB::fetchOne(
        "SELECT COUNT(*) as c, COUNT(DISTINCT session_id) as u FROM analytics_events
         WHERE is_bot = 0 AND name = ? AND DATE(created_at) >= ?",
        [$name, $start]
    );

    $chart = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $row = OutpostDB::fetchOne(
            "SELECT COUNT(*) as c FROM analytics_events WHERE is_bot = 0 AND name = ? AND DATE(created_at) = ?",
            [$name, $date]
        );
        $chart[] = ['date' => $date, 'count' => (int)($row['c'] ?? 0)];
    }

    $top_pages = OutpostDB::fetchAll(
        "SELECT path, COUNT(*) as count FROM analytics_events
         WHERE is_bot = 0 AND name = ? AND DATE(created_at) >= ?
         GROUP BY path ORDER BY count DESC LIMIT 10",
        [$name, $start]
    );

    $all_props = OutpostDB::fetchAll(
        "SELECT properties FROM analytics_events
         WHERE is_bot = 0 AND name = ? AND DATE(created_at) >= ? AND properties IS NOT NULL
         LIMIT 500",
        [$name, $start]
    );
    $prop_counts = [];
    foreach ($all_props as $row) {
        $decoded = json_decode($row['properties'], true);
        if (!is_array($decoded)) continue;
        foreach ($decoded as $key => $value) {
            $val_str = is_string($value) ? $value : json_encode($value);
            $combo = $key . '=' . $val_str;
            if (!isset($prop_counts[$combo])) {
                $prop_counts[$combo] = ['key' => $key, 'value' => $val_str, 'count' => 0];
            }
            $prop_counts[$combo]['count']++;
        }
    }
    usort($prop_counts, fn($a, $b) => $b['count'] - $a['count']);
    $top_properties = array_slice(array_values($prop_counts), 0, 20);

    json_response(['data' => [
        'name'            => $name,
        'total'           => (int)($total['c'] ?? 0),
        'unique_sessions' => (int)($total['u'] ?? 0),
        'chart'           => $chart,
        'top_pages'       => $top_pages,
        'top_properties'  => $top_properties,
    ]]);
}

// ── Goals CRUD ────────────────────────────────────────────────

function handle_goals_list(): void {
    ensure_analytics_goals_table();
    $goals = OutpostDB::fetchAll("SELECT * FROM analytics_goals ORDER BY created_at DESC");
    json_response(['goals' => $goals]);
}

function handle_goals_create(): void {
    ensure_analytics_goals_table();
    $input = json_decode(file_get_contents('php://input'), true);

    $name   = trim($input['name'] ?? '');
    $type   = trim($input['type'] ?? '');
    $target = trim($input['target'] ?? '');

    if (!$name) json_error('Goal name is required');
    if (!in_array($type, ['pagevisit', 'event'], true)) json_error('Type must be pagevisit or event');
    if (!$target) json_error('Target is required');

    $id = OutpostDB::insert('analytics_goals', [
        'name'   => $name,
        'type'   => $type,
        'target' => $target,
    ]);

    json_response(['success' => true, 'id' => $id]);
}

function handle_goals_update(): void {
    ensure_analytics_goals_table();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Invalid goal ID');

    $goal = OutpostDB::fetchOne("SELECT * FROM analytics_goals WHERE id = ?", [$id]);
    if (!$goal) json_error('Goal not found', 404);

    $input = json_decode(file_get_contents('php://input'), true);
    $fields = [];
    $params = [];

    if (isset($input['name'])) {
        $fields[] = 'name = ?';
        $params[] = trim($input['name']);
    }
    if (isset($input['type']) && in_array($input['type'], ['pagevisit', 'event'], true)) {
        $fields[] = 'type = ?';
        $params[] = $input['type'];
    }
    if (isset($input['target'])) {
        $fields[] = 'target = ?';
        $params[] = trim($input['target']);
    }
    if (isset($input['active'])) {
        $fields[] = 'active = ?';
        $params[] = $input['active'] ? 1 : 0;
    }

    if ($fields) {
        $fields[] = "updated_at = datetime('now')";
        $params[] = $id;
        OutpostDB::query(
            "UPDATE analytics_goals SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );
    }

    json_response(['success' => true]);
}

function handle_goals_delete(): void {
    ensure_analytics_goals_table();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Invalid goal ID');

    $deleted = OutpostDB::delete('analytics_goals', 'id = ?', [$id]);
    if ($deleted === 0) json_error('Goal not found', 404);

    json_response(['success' => true]);
}

// ── Goals analytics ───────────────────────────────────────────

function handle_analytics_goals(): void {
    require_once __DIR__ . '/track.php';
    ensure_analytics_goals_table();
    ensure_analytics_tables();
    ensure_analytics_events_table();

    $period = $_GET['period'] ?? '30days';
    ['start' => $start, 'prev' => $prev, 'days' => $days] = analytics_date_range($period);

    $total_visitors = OutpostDB::fetchOne(
        "SELECT COUNT(DISTINCT session_id) as c FROM analytics_hits WHERE is_bot = 0 AND DATE(created_at) >= ?",
        [$start]
    );
    $visitor_count = (int)($total_visitors['c'] ?? 0);

    $goals = OutpostDB::fetchAll("SELECT * FROM analytics_goals WHERE active = 1 ORDER BY created_at DESC");

    $result = [];
    foreach ($goals as $goal) {
        if ($goal['type'] === 'pagevisit') {
            $conv = OutpostDB::fetchOne(
                "SELECT COUNT(*) as c, COUNT(DISTINCT session_id) as u FROM analytics_hits
                 WHERE is_bot = 0 AND path = ? AND DATE(created_at) >= ?",
                [$goal['target'], $start]
            );
            $conv_prev = OutpostDB::fetchOne(
                "SELECT COUNT(DISTINCT session_id) as u FROM analytics_hits
                 WHERE is_bot = 0 AND path = ? AND DATE(created_at) >= ? AND DATE(created_at) < ?",
                [$goal['target'], $prev, $start]
            );
        } else {
            $conv = OutpostDB::fetchOne(
                "SELECT COUNT(*) as c, COUNT(DISTINCT session_id) as u FROM analytics_events
                 WHERE is_bot = 0 AND name = ? AND DATE(created_at) >= ?",
                [$goal['target'], $start]
            );
            $conv_prev = OutpostDB::fetchOne(
                "SELECT COUNT(DISTINCT session_id) as u FROM analytics_events
                 WHERE is_bot = 0 AND name = ? AND DATE(created_at) >= ? AND DATE(created_at) < ?",
                [$goal['target'], $prev, $start]
            );
        }

        $conversions = (int)($conv['c'] ?? 0);
        $unique_conv = (int)($conv['u'] ?? 0);
        $prev_unique = (int)($conv_prev['u'] ?? 0);
        $change = $prev_unique > 0
            ? round(($unique_conv - $prev_unique) / $prev_unique * 100, 1)
            : ($unique_conv > 0 ? 100 : 0);

        $chart = [];
        $tbl = $goal['type'] === 'pagevisit' ? 'analytics_hits' : 'analytics_events';
        $col = $goal['type'] === 'pagevisit' ? 'path' : 'name';
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $row = OutpostDB::fetchOne(
                "SELECT COUNT(DISTINCT session_id) as c FROM {$tbl}
                 WHERE is_bot = 0 AND {$col} = ? AND DATE(created_at) = ?",
                [$goal['target'], $date]
            );
            $chart[] = ['date' => $date, 'conversions' => (int)($row['c'] ?? 0)];
        }

        $result[] = [
            'id'                 => (int)$goal['id'],
            'name'               => $goal['name'],
            'type'               => $goal['type'],
            'target'             => $goal['target'],
            'conversions'        => $conversions,
            'unique_conversions' => $unique_conv,
            'conversion_rate'    => $visitor_count > 0 ? round($unique_conv / $visitor_count, 4) : 0,
            'prev_conversions'   => $prev_unique,
            'change'             => $change,
            'chart'              => $chart,
        ];
    }

    json_response(['data' => [
        'goals'          => $result,
        'total_visitors' => $visitor_count,
        'period'         => $period,
    ]]);
}

// ── Channel Migrations ──────────────────────────────────
function ensure_channels_tables(): void {
    $db = OutpostDB::connect();

    $db->exec("CREATE TABLE IF NOT EXISTS channels (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        slug            TEXT    UNIQUE NOT NULL,
        name            TEXT    NOT NULL,
        type            TEXT    NOT NULL DEFAULT 'api',
        config          TEXT    NOT NULL DEFAULT '{}',
        field_map       TEXT    NOT NULL DEFAULT '[]',
        cache_ttl       INTEGER DEFAULT 3600,
        url_pattern     TEXT,
        sort_field      TEXT,
        sort_direction  TEXT    DEFAULT 'desc',
        max_items       INTEGER DEFAULT 100,
        status          TEXT    DEFAULT 'active',
        last_sync_at    TEXT,
        last_error      TEXT,
        created_at      TEXT    DEFAULT CURRENT_TIMESTAMP,
        updated_at      TEXT    DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS channel_items (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id   INTEGER NOT NULL,
        external_id  TEXT,
        slug         TEXT,
        data         TEXT    NOT NULL DEFAULT '{}',
        sort_value   TEXT,
        created_at   TEXT    DEFAULT CURRENT_TIMESTAMP,
        updated_at   TEXT    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_channel_items_channel ON channel_items(channel_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_channel_items_slug ON channel_items(channel_id, slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_channel_items_sort ON channel_items(channel_id, sort_value)");

    $db->exec("CREATE TABLE IF NOT EXISTS channel_sync_log (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id    INTEGER NOT NULL,
        status        TEXT    NOT NULL DEFAULT 'pending',
        items_synced  INTEGER DEFAULT 0,
        items_added   INTEGER DEFAULT 0,
        items_updated INTEGER DEFAULT 0,
        items_removed INTEGER DEFAULT 0,
        duration_ms   INTEGER,
        error_message TEXT,
        synced_at     TEXT    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
    )");
}

// ── Channel Handlers ────────────────────────────────────
function handle_channels_list(): void {
    $channels = OutpostDB::fetchAll('SELECT * FROM channels ORDER BY name ASC');
    foreach ($channels as &$ch) {
        $count = OutpostDB::fetchOne(
            'SELECT COUNT(*) as count FROM channel_items WHERE channel_id = ?',
            [$ch['id']]
        );
        $ch['item_count'] = (int)($count['count'] ?? 0);
        // Strip auth credentials from list view
        $ch['config'] = outpost_mask_channel_config($ch['config'] ?? '{}');
    }
    json_response(['channels' => $channels]);
}

function handle_channel_get(): void {
    $id = (int) $_GET['id'];
    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE id = ?', [$id]);
    if (!$channel) json_error('Channel not found', 404);

    $count = OutpostDB::fetchOne(
        'SELECT COUNT(*) as count FROM channel_items WHERE channel_id = ?',
        [$id]
    );
    $channel['item_count'] = (int)($count['count'] ?? 0);

    // Mask auth credentials in detail view (show structure but hide values)
    $channel['config'] = outpost_mask_channel_config($channel['config'] ?? '{}');

    json_response($channel);
}

/**
 * Mask sensitive auth credentials in channel config JSON.
 * Returns the config as a decoded array with auth values masked.
 */
function outpost_mask_channel_config(string $configJson): array {
    $config = json_decode($configJson, true) ?: [];
    if (isset($config['auth_config']) && is_array($config['auth_config'])) {
        foreach ($config['auth_config'] as $key => $val) {
            if (is_string($val) && $val !== '') {
                $config['auth_config'][$key] = '••••••••';
            }
        }
    }
    return $config;
}

function handle_channel_create(): void {
    $data = get_json_body();

    $slug = trim($data['slug'] ?? '');
    $name = trim($data['name'] ?? '');
    if (!$slug || !$name) json_error('Slug and name are required');

    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        json_error('Slug must be lowercase letters, numbers, and hyphens only');
    }

    $existing = OutpostDB::fetchOne('SELECT id FROM channels WHERE slug = ?', [$slug]);
    if ($existing) json_error('A channel with this slug already exists');

    $type = $data['type'] ?? 'api';
    if (!in_array($type, ['api', 'rss', 'csv'], true)) {
        json_error('Invalid channel type. Must be api, rss, or csv.');
    }

    $id = OutpostDB::insert('channels', [
        'slug'           => $slug,
        'name'           => $name,
        'type'           => $type,
        'config'         => json_encode($data['config'] ?? new \stdClass()),
        'field_map'      => json_encode($data['field_map'] ?? []),
        'cache_ttl'      => (int)($data['cache_ttl'] ?? 3600),
        'url_pattern'    => $data['url_pattern'] ?? null,
        'sort_field'     => $data['sort_field'] ?? null,
        'sort_direction' => $data['sort_direction'] ?? 'desc',
        'max_items'      => (int)($data['max_items'] ?? 100),
        'status'         => $data['status'] ?? 'active',
    ]);

    json_response(['success' => true, 'id' => $id], 201);
}

function handle_channel_update(): void {
    $id = (int) $_GET['id'];
    $data = get_json_body();

    $current = OutpostDB::fetchOne('SELECT * FROM channels WHERE id = ?', [$id]);
    if (!$current) json_error('Channel not found', 404);

    $allowed = ['name', 'config', 'field_map', 'cache_ttl', 'url_pattern',
                'sort_field', 'sort_direction', 'max_items', 'status'];
    $update = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            if ($key === 'config') {
                // Preserve existing auth credentials when masked values are sent back
                $newConfig = $data[$key];
                $oldConfig = json_decode($current['config'] ?? '{}', true) ?: [];
                if (isset($newConfig['auth_config']) && isset($oldConfig['auth_config'])) {
                    foreach ($newConfig['auth_config'] as $ak => $av) {
                        if ($av === '••••••••' && isset($oldConfig['auth_config'][$ak])) {
                            $newConfig['auth_config'][$ak] = $oldConfig['auth_config'][$ak];
                        }
                    }
                }
                $update[$key] = json_encode($newConfig);
            } elseif ($key === 'field_map') {
                $update[$key] = json_encode($data[$key]);
            } elseif (in_array($key, ['cache_ttl', 'max_items'])) {
                $update[$key] = (int)$data[$key];
            } else {
                $update[$key] = $data[$key];
            }
        }
    }

    if (empty($update)) json_error('Nothing to update');

    $update['updated_at'] = date('Y-m-d H:i:s');
    OutpostDB::update('channels', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_channel_delete(): void {
    $id = (int) $_GET['id'];
    OutpostDB::delete('channels', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_channel_sync(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Channel ID required');

    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE id = ?', [$id]);
    if (!$channel) json_error('Channel not found', 404);

    $result = channel_sync($id);

    if (isset($result['error'])) {
        json_error('Sync failed: ' . $result['error']);
    }

    json_response($result);
}

function handle_channel_sync_log(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Channel ID required');

    $logs = OutpostDB::fetchAll(
        'SELECT * FROM channel_sync_log WHERE channel_id = ? ORDER BY synced_at DESC LIMIT 50',
        [$id]
    );
    json_response(['logs' => $logs]);
}

function handle_channel_discover(): void {
    $data = get_json_body();
    $type = $data['type'] ?? 'api';

    $config = [
        'url'            => $data['url'] ?? '',
        'method'         => $data['method'] ?? 'GET',
        'auth_type'      => $data['auth_type'] ?? 'none',
        'auth_config'    => $data['auth_config'] ?? [],
        'headers'        => $data['headers'] ?? [],
        'params'         => $data['params'] ?? [],
        'csv_delimiter'  => $data['csv_delimiter'] ?? ',',
        'csv_has_headers' => $data['csv_has_headers'] ?? true,
        'csv_encoding'   => $data['csv_encoding'] ?? 'UTF-8',
    ];

    if (!$config['url']) json_error('URL is required');

    // RSS discovery
    if ($type === 'rss') {
        $result = channel_discover_rss($config);
        if (isset($result['error']) && $result['error']) json_error('RSS fetch failed: ' . $result['error']);
        json_response($result);
        return;
    }

    // CSV discovery
    if ($type === 'csv') {
        $result = channel_discover_csv($config);
        if (isset($result['error']) && $result['error']) json_error('CSV fetch failed: ' . $result['error']);
        json_response($result);
        return;
    }

    // API discovery (existing logic)
    $result = channel_fetch_api($config);

    if ($result['error']) {
        json_error('API request failed: ' . $result['error']);
    }

    $response = $result['decoded'];
    $dataPath = $data['data_path'] ?? '';
    $extracted = $dataPath ? channel_extract_data($response, $dataPath) : $response;

    // Auto-detect data path if not provided and response is an object
    $suggestedPath = '';
    if (!$dataPath && is_array($response) && !isset($response[0])) {
        foreach ($response as $key => $val) {
            if (is_array($val) && isset($val[0])) {
                $suggestedPath = $key;
                $extracted = $val;
                break;
            }
        }
    }

    $schema = channel_discover_schema(is_array($extracted) ? $extracted : []);
    $sample = is_array($extracted) ? array_slice($extracted, 0, 3) : [];

    json_response([
        'schema'         => $schema,
        'sample'         => $sample,
        'total_items'    => is_array($extracted) ? count($extracted) : 0,
        'suggested_path' => $suggestedPath,
        'raw_keys'       => is_array($response) ? array_keys($response) : [],
    ]);
}

function handle_channel_items(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Channel ID required');

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE id = ?', [$id]);
    if (!$channel) json_error('Channel not found', 404);

    $direction = strtoupper($channel['sort_direction'] ?? 'DESC');
    if (!in_array($direction, ['ASC', 'DESC'])) $direction = 'DESC';

    $items = OutpostDB::fetchAll(
        "SELECT * FROM channel_items WHERE channel_id = ? ORDER BY sort_value {$direction} LIMIT ? OFFSET ?",
        [$id, $perPage, $offset]
    );

    $total = OutpostDB::fetchOne(
        'SELECT COUNT(*) as c FROM channel_items WHERE channel_id = ?',
        [$id]
    );

    // Decode JSON data for each item
    foreach ($items as &$item) {
        $item['data'] = json_decode($item['data'], true) ?: [];
    }

    json_response([
        'items' => $items,
        'total' => (int)($total['c'] ?? 0),
        'page'  => $page,
        'pages' => ceil(($total['c'] ?? 0) / $perPage),
    ]);
}

// ── Backup & Restore ────────────────────────────────────

/**
 * Ensure the backups directory exists with a protective .htaccess.
 */
function ensure_backups_dir(): void {
    if (!is_dir(OUTPOST_BACKUPS_DIR)) {
        mkdir(OUTPOST_BACKUPS_DIR, 0755, true);
    }
    $htaccess = OUTPOST_BACKUPS_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
}

/**
 * Create a backup zip containing the database, uploads, and themes.
 * Shared by handle_backup_create() and maybe_run_auto_backup().
 */
function create_backup_zip(string $path): bool {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    // Add the SQLite database
    if (file_exists(OUTPOST_DB_PATH)) {
        $zip->addFile(OUTPOST_DB_PATH, 'cms.db');
    }

    // Recursively add a directory to the zip under the given prefix
    $addDir = function(string $dirPath, string $prefix) use ($zip) {
        if (!is_dir($dirPath)) return;
        $base = rtrim($dirPath, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            $relativePath = $prefix . substr($realPath, strlen($base) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($realPath, $relativePath);
            }
        }
    };

    $addDir(OUTPOST_UPLOADS_DIR, 'uploads/');
    $addDir(OUTPOST_THEMES_DIR, 'themes/');

    return $zip->close();
}

function handle_backup_create(): void {
    ensure_backups_dir();

    $filename = 'backup-' . date('Y-m-d-His') . '.zip';
    $path = OUTPOST_BACKUPS_DIR . $filename;

    if (!create_backup_zip($path)) {
        json_error('Failed to create backup zip', 500);
    }

    // Update last backup timestamp
    OutpostDB::query(
        'INSERT OR REPLACE INTO settings ("key", "value") VALUES (?, ?)',
        ['backup_last_backup_at', date('c')]
    );

    log_activity('system', 'Backup created: ' . $filename);

    json_response([
        'success' => true,
        'backup'  => [
            'filename'   => $filename,
            'size'       => filesize($path),
            'created_at' => date('c', filemtime($path)),
        ],
    ]);
}

function handle_backup_list(): void {
    if (!is_dir(OUTPOST_BACKUPS_DIR)) {
        json_response(['backups' => []]);
        return;
    }

    $files = glob(OUTPOST_BACKUPS_DIR . 'backup-*.zip') ?: [];

    // Sort newest first
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

    $backups = array_map(fn($f) => [
        'filename'   => basename($f),
        'size'       => filesize($f),
        'created_at' => date('c', filemtime($f)),
    ], $files);

    json_response(['backups' => $backups]);
}

function handle_backup_download(): void {
    $filename = $_GET['filename'] ?? '';

    if (!preg_match('/^backup-[\d-]+\.zip$/', $filename)) {
        json_error('Invalid filename', 400);
    }

    $path = OUTPOST_BACKUPS_DIR . $filename;

    if (!file_exists($path)) {
        json_error('Backup not found', 404);
    }

    // Discard any buffered output
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function handle_backup_restore(): void {
    // Only super_admin can restore — this is destructive
    outpost_require_cap('super.*');

    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        json_error('No valid backup file uploaded', 400);
    }

    $upload = $_FILES['backup'];
    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        json_error('Backup must be a .zip file', 400);
    }

    $tmpZip = $upload['tmp_name'];

    // Validate zip contains cms.db
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        json_error('Could not open zip file', 400);
    }
    if ($zip->locateName('cms.db') === false) {
        $zip->close();
        json_error('Invalid backup: missing cms.db', 400);
    }

    // Safety net: copy current DB to temp location before overwriting
    $safetyBackup = sys_get_temp_dir() . '/outpost_restore_safety_' . time() . '.db';
    if (file_exists(OUTPOST_DB_PATH)) {
        copy(OUTPOST_DB_PATH, $safetyBackup);
    }

    try {
        // Close the current database connection before overwriting
        OutpostDB::reconnect();

        // Extract cms.db to the database path
        $dbStream = $zip->getStream('cms.db');
        if (!$dbStream) {
            throw new RuntimeException('Could not read cms.db from zip');
        }
        $dbContent = stream_get_contents($dbStream);
        fclose($dbStream);
        file_put_contents(OUTPOST_DB_PATH, $dbContent);

        // Extract uploads and themes if present
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, 'uploads/') && !str_starts_with($name, 'themes/')) continue;

            $destPath = OUTPOST_DIR . $name;

            // Directory entry
            if (str_ends_with($name, '/')) {
                if (!is_dir($destPath)) mkdir($destPath, 0755, true);
                continue;
            }

            // File entry
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);

            $stream = $zip->getStream($name);
            if ($stream) {
                file_put_contents($destPath, stream_get_contents($stream));
                fclose($stream);
            }
        }

        $zip->close();

        // Clear all caches
        $cacheTemplatesDir = OUTPOST_CACHE_DIR . 'templates/';
        if (is_dir($cacheTemplatesDir)) {
            $cacheFiles = glob($cacheTemplatesDir . '*.php') ?: [];
            foreach ($cacheFiles as $f) unlink($f);
        }
        if (is_dir(OUTPOST_CACHE_DIR)) {
            $cacheFiles = glob(OUTPOST_CACHE_DIR . '*.php') ?: [];
            foreach ($cacheFiles as $f) unlink($f);
        }

        // Remove safety backup on success
        if (file_exists($safetyBackup)) {
            unlink($safetyBackup);
        }

        log_activity('system', 'Backup restored: ' . basename($upload['name']));

        json_response([
            'success' => true,
            'message' => 'Backup restored successfully',
        ]);
    } catch (\Throwable $e) {
        // Restore the safety backup on failure
        if (file_exists($safetyBackup)) {
            copy($safetyBackup, OUTPOST_DB_PATH);
            unlink($safetyBackup);
        }
        if ($zip) {
            @$zip->close();
        }

        json_error('Restore failed: ' . $e->getMessage(), 500);
    }
}

function handle_backup_delete(): void {
    $filename = $_GET['filename'] ?? '';

    if (!preg_match('/^backup-[\d-]+\.zip$/', $filename)) {
        json_error('Invalid filename', 400);
    }

    $path = OUTPOST_BACKUPS_DIR . $filename;

    if (!file_exists($path)) {
        json_error('Backup not found', 404);
    }

    unlink($path);

    log_activity('system', 'Backup deleted: ' . $filename);

    json_response(['success' => true]);
}

function handle_backup_settings(): void {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $auto_enabled = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_auto_enabled'");
        $frequency    = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_frequency'");
        $max_backups  = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_max_backups'");
        $last_backup  = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_last_backup_at'");

        json_response([
            'auto_enabled'   => ($auto_enabled['value'] ?? '0') === '1',
            'frequency'      => $frequency['value'] ?? 'daily',
            'max_backups'    => (int) ($max_backups['value'] ?? '10'),
            'last_backup_at' => $last_backup['value'] ?? null,
        ]);
        return;
    }

    // PUT — update settings
    $data = get_json_body();

    if (isset($data['auto_enabled'])) {
        $val = $data['auto_enabled'] ? '1' : '0';
        OutpostDB::query(
            'INSERT OR REPLACE INTO settings ("key", "value") VALUES (?, ?)',
            ['backup_auto_enabled', $val]
        );
    }

    if (isset($data['frequency'])) {
        $freq = in_array($data['frequency'], ['daily', 'weekly'], true) ? $data['frequency'] : 'daily';
        OutpostDB::query(
            'INSERT OR REPLACE INTO settings ("key", "value") VALUES (?, ?)',
            ['backup_frequency', $freq]
        );
    }

    if (isset($data['max_backups'])) {
        $max = max(1, min(100, (int) $data['max_backups']));
        OutpostDB::query(
            'INSERT OR REPLACE INTO settings ("key", "value") VALUES (?, ?)',
            ['backup_max_backups', (string) $max]
        );
    }

    log_activity('system', 'Backup settings updated');

    json_response(['success' => true]);
}

/**
 * Auto-backup trigger — called from handle_dashboard_stats().
 * Checks if an automatic backup is due and creates one if needed.
 */
function maybe_run_auto_backup(): void {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_auto_enabled'");
    if (($row['value'] ?? '0') !== '1') return;

    $freqRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_frequency'");
    $freq = $freqRow['value'] ?? 'daily';

    $lastRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_last_backup_at'");
    $last = $lastRow['value'] ?? '';

    $maxRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE \"key\" = 'backup_max_backups'");
    $maxBackups = (int) ($maxRow['value'] ?? '10');
    if ($maxBackups < 1) $maxBackups = 10;

    $interval = $freq === 'weekly' ? 7 * 86400 : 86400;
    if ($last && (time() - strtotime($last)) < $interval) return;

    // Time for a backup
    ensure_backups_dir();

    $filename = 'backup-' . date('Y-m-d-His') . '.zip';
    $path = OUTPOST_BACKUPS_DIR . $filename;

    if (!create_backup_zip($path)) return;

    // Update last backup time
    OutpostDB::query(
        'INSERT OR REPLACE INTO settings ("key", "value") VALUES (?, ?)',
        ['backup_last_backup_at', date('c')]
    );

    // Prune old backups beyond the max
    $files = glob(OUTPOST_BACKUPS_DIR . 'backup-*.zip') ?: [];
    if (count($files) > $maxBackups) {
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, $maxBackups) as $old) {
            @unlink($old);
        }
    }
}

// ── Updates ─────────────────────────────────────────────

/**
 * Lightweight update status check — reuses the same 5-minute cache as handle_updates_check().
 * Returns ['update_available' => bool, 'latest_version' => string|null].
 * Silently returns false on any error (network, DB, etc.) so it never blocks auth/me.
 */
function outpost_check_update_status(): array {
    try {
        $current = OUTPOST_VERSION;
        $cached = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'update_check_cache'");
        if ($cached) {
            $cache = json_decode($cached['value'], true);
            if ($cache && (time() - ($cache['checked_at'] ?? 0)) < 300) {
                return [
                    'update_available' => version_compare($cache['latest_version'], $current, '>'),
                    'latest_version'   => $cache['latest_version'],
                ];
            }
        }

        // Cache is stale or missing — fetch from GitHub with a short timeout
        $url = 'https://api.github.com/repos/' . OUTPOST_GITHUB_REPO . '/releases/latest';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: OutpostCMS/' . $current,
            ],
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$body) {
            return ['update_available' => false, 'latest_version' => null];
        }

        $release = json_decode($body, true);
        $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
        $downloadUrl = '';
        foreach (($release['assets'] ?? []) as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }

        // Update the shared cache so handle_updates_check() benefits too
        $cacheData = json_encode([
            'latest_version' => $latestVersion,
            'download_url'   => $downloadUrl,
            'release_notes'  => $release['body'] ?? '',
            'release_url'    => $release['html_url'] ?? '',
            'checked_at'     => time(),
        ]);
        OutpostDB::query(
            "INSERT INTO settings (key, value) VALUES ('update_check_cache', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$cacheData]
        );

        return [
            'update_available' => version_compare($latestVersion, $current, '>'),
            'latest_version'   => $latestVersion,
        ];
    } catch (\Throwable $e) {
        return ['update_available' => false, 'latest_version' => null];
    }
}

function handle_updates_check(): void {
    $current = OUTPOST_VERSION;

    // Check cache first (avoid hammering GitHub API — 60 req/hr unauthenticated limit)
    $cached = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'update_check_cache'");
    if ($cached) {
        $cache = json_decode($cached['value'], true);
        if ($cache && (time() - ($cache['checked_at'] ?? 0)) < 300) {
            json_response([
                'current_version' => $current,
                'latest_version'  => $cache['latest_version'],
                'download_url'    => $cache['download_url'] ?? '',
                'release_notes'   => $cache['release_notes'] ?? '',
                'release_url'     => $cache['release_url'] ?? '',
                'update_available' => version_compare($cache['latest_version'], $current, '>'),
                'cached' => true,
            ]);
            return;
        }
    }

    // Fetch latest release from GitHub
    $url = 'https://api.github.com/repos/' . OUTPOST_GITHUB_REPO . '/releases/latest';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: OutpostCMS/' . $current,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) {
        json_response([
            'current_version' => $current,
            'latest_version'  => $current,
            'update_available' => false,
            'error' => 'Could not reach GitHub (HTTP ' . $httpCode . ')',
        ]);
        return;
    }

    $release = json_decode($body, true);
    $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
    $downloadUrl = '';

    // Find the .zip asset in the release
    foreach (($release['assets'] ?? []) as $asset) {
        if (str_ends_with($asset['name'], '.zip')) {
            $downloadUrl = $asset['browser_download_url'];
            break;
        }
    }

    // Cache the result
    $cacheData = json_encode([
        'latest_version' => $latestVersion,
        'download_url'   => $downloadUrl,
        'release_notes'  => $release['body'] ?? '',
        'release_url'    => $release['html_url'] ?? '',
        'checked_at'     => time(),
    ]);
    OutpostDB::query(
        "INSERT INTO settings (key, value) VALUES ('update_check_cache', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        [$cacheData]
    );

    json_response([
        'current_version'  => $current,
        'latest_version'   => $latestVersion,
        'download_url'     => $downloadUrl,
        'release_notes'    => $release['body'] ?? '',
        'release_url'      => $release['html_url'] ?? '',
        'update_available' => version_compare($latestVersion, $current, '>'),
    ]);
}

function handle_updates_apply(): void {
    // Require super_admin for updates
    outpost_require_cap('settings.*');

    $body = get_json_body();
    $downloadUrl = $body['download_url'] ?? '';

    if (empty($downloadUrl)) {
        json_error('No download URL provided');
    }

    // Validate the URL strictly points to our GitHub repo
    $parsedUrl = parse_url($downloadUrl);
    $urlHost = strtolower($parsedUrl['host'] ?? '');
    $urlPath = $parsedUrl['path'] ?? '';
    $urlScheme = strtolower($parsedUrl['scheme'] ?? '');
    if ($urlScheme !== 'https' ||
        !in_array($urlHost, ['github.com', 'objects.githubusercontent.com'], true) ||
        ($urlHost === 'github.com' && !str_starts_with($urlPath, '/' . OUTPOST_GITHUB_REPO . '/releases/'))) {
        json_error('Invalid download URL — must be from the official Outpost repository');
    }

    $outpostDir = OUTPOST_DIR;
    $tmpDir = sys_get_temp_dir() . '/outpost_update_' . time();
    $zipPath = $tmpDir . '/update.zip';

    // Create temp directory
    if (!mkdir($tmpDir, 0755, true)) {
        json_error('Could not create temp directory');
    }

    try {
        // Download the zip
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_HTTPHEADER      => ['User-Agent: OutpostCMS/' . OUTPOST_VERSION],
            CURLOPT_TIMEOUT         => 120,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $zipData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$zipData) {
            throw new \RuntimeException('Download failed (HTTP ' . $httpCode . ')');
        }

        file_put_contents($zipPath, $zipData);

        // Extract zip
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open zip file');
        }

        // Zip slip prevention — validate all entry paths before extraction
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            if (str_contains($name, '../') || str_contains($name, '/..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new \RuntimeException('Zip contains unsafe path: ' . $name);
            }
        }

        $extractDir = $tmpDir . '/extracted';
        $zip->extractTo($extractDir);
        $zip->close();

        // Find the outpost/ directory inside the zip (it may be nested)
        $sourceDir = outpost_find_update_root($extractDir);
        if (!$sourceDir) {
            throw new \RuntimeException('Could not find outpost/ directory in update package');
        }

        // Define what to copy and what to skip
        $skipDirs = ['data', 'uploads', 'cache', 'themes'];
        $updatedFiles = [];

        // Copy PHP files from root of source
        $phpFiles = glob($sourceDir . '/*.php') ?: [];
        foreach ($phpFiles as $file) {
            $basename = basename($file);
            copy($file, $outpostDir . $basename);
            $updatedFiles[] = $basename;
        }

        // Copy safe directories
        $safeDirs = ['admin', 'docs', 'member-pages', 'tools'];
        foreach ($safeDirs as $dir) {
            $src = $sourceDir . '/' . $dir;
            if (is_dir($src)) {
                // Remove old directory contents first (stale hashed assets)
                $dest = $outpostDir . $dir;
                if (is_dir($dest)) {
                    outpost_rmdir_recursive($dest);
                }
                outpost_copy_recursive($src, $dest);
                $updatedFiles[] = $dir . '/';
            }
        }

        // Clear template cache
        $cacheDir = OUTPOST_CACHE_DIR . 'templates/';
        if (is_dir($cacheDir)) {
            $cacheFiles = glob($cacheDir . '*.php') ?: [];
            foreach ($cacheFiles as $f) unlink($f);
        }

        // Clear the update check cache so it re-fetches
        OutpostDB::query("DELETE FROM settings WHERE key = 'update_check_cache'");

        // Clean up temp files
        outpost_rmdir_recursive($tmpDir);

        json_response([
            'success' => true,
            'updated_files' => $updatedFiles,
            'message' => 'Update applied successfully. Refresh the page to load the new version.',
        ]);
    } catch (\Throwable $e) {
        // Clean up on failure
        if (is_dir($tmpDir)) {
            outpost_rmdir_recursive($tmpDir);
        }
        json_error('Update failed: ' . $e->getMessage());
    }
}

/**
 * Finds the outpost/ root inside an extracted zip.
 * Handles both flat (outpost/api.php) and nested (outpost-v1.0.0/outpost/api.php) layouts.
 */
function outpost_find_update_root(string $dir): ?string {
    // Check if api.php is directly here
    if (file_exists($dir . '/api.php')) {
        return $dir;
    }
    // Check one level deeper
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $sub = $dir . '/' . $entry;
        if (is_dir($sub)) {
            if (file_exists($sub . '/api.php')) {
                return $sub;
            }
            // One more level (e.g., outpost-v1.0.0/outpost/)
            $subEntries = scandir($sub);
            foreach ($subEntries as $se) {
                if ($se === '.' || $se === '..') continue;
                $subsub = $sub . '/' . $se;
                if (is_dir($subsub) && file_exists($subsub . '/api.php')) {
                    return $subsub;
                }
            }
        }
    }
    return null;
}

/** Recursively copy a directory */
function outpost_copy_recursive(string $src, string $dst): void {
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            outpost_copy_recursive($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

/** Recursively remove a directory */
function outpost_rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            outpost_rmdir_recursive($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
