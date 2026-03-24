<?php
/**
 * Outpost CMS — Member API
 * Separate entry point for front-end member operations.
 * URL: /outpost/member-api.php?action=<endpoint>
 * Uses member session (NOT admin session).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/members.php';
require_once __DIR__ . '/http-security.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/lodge.php';

header('Content-Type: application/json; charset=utf-8');

// ── CORS ─────────────────────────────────────────────────
// Allow configurable origins for mobile/headless clients, fall back to '*'
$_corsOrigins = null;
try {
    $_corsRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'api_cors_origins'");
    if ($_corsRow && $_corsRow['value']) $_corsOrigins = $_corsRow['value'];
} catch (\Throwable $e) {}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    $isLocalDev = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin);

    if ($isLocalDev) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
    } elseif ($_corsOrigins === '*') {
        header('Access-Control-Allow-Origin: *');
    } elseif ($_corsOrigins) {
        $allowed = array_map('trim', explode(',', $_corsOrigins));
        if (in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
        }
    }
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
} elseif ($_corsOrigins === '*') {
    // Non-browser client (no Origin header) — still set CORS for preflight caching
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── JWT bearer token detection ───────────────────────────
$_jwt_member = null;
$_jwt_auth = false;

$_authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $_authHeader, $_m)) {
    $_tokenStr = $_m[1];
    // Only process if it looks like a JWT (3 dot-separated parts), not an API key
    if (substr_count($_tokenStr, '.') === 2) {
        $payload = outpost_jwt_decode($_tokenStr, outpost_jwt_secret());
        if ($payload && ($payload['type'] ?? '') === 'member') {
            $member = OutpostDB::fetchOne(
                "SELECT id, username, email, role, display_name, avatar, member_since, member_status
                 FROM users WHERE id = ? AND role IN ('free_member', 'paid_member')",
                [(int) $payload['sub']]
            );
            if ($member && ($member['member_status'] ?? 'active') !== 'suspended') {
                $_jwt_member = $member;
                $_jwt_auth = true;
            }
        }
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Helpers ──────────────────────────────────────────────
function member_json(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function member_error(string $message, int $code = 400): void {
    member_json(['error' => $message], $code);
}

function member_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) member_error('Invalid JSON body');
    return $data;
}

// ── Ensure reset token columns exist ─────────────────────
function member_api_ensure_reset_columns(): void {
    $db   = OutpostDB::connect();
    $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
    if (!in_array('reset_token', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN reset_token TEXT");
    }
    if (!in_array('reset_token_expires', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires TEXT");
    }
    if (!in_array('email_verified', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN email_verified TEXT");
    }
    if (!in_array('verify_token', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN verify_token TEXT");
    }
    if (!in_array('verify_token_expires', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN verify_token_expires TEXT");
    }
}

// ── Member event recording (for funnels) ─────────────────
function _member_api_record_event(int $userId, string $eventType, ?string $details = null): void {
    try {
        OutpostDB::query('CREATE TABLE IF NOT EXISTS member_events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER,
            event_type  TEXT NOT NULL,
            details     TEXT DEFAULT NULL,
            session_id  TEXT DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )');
        OutpostDB::insert('member_events', [
            'user_id'    => $userId,
            'event_type' => $eventType,
            'details'    => $details,
        ]);
    } catch (\Throwable $e) {
        // Non-critical — don't break registration/login flow
    }
}

// ── Public routes (no auth required) ─────────────────────

if ($action === 'forgot' && $method === 'POST') {
    outpost_ip_rate_limit('member_forgot', 5, 300); // 5 per 5 minutes
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/auth.php';
    member_api_ensure_reset_columns();

    $data  = member_json_body();
    $email = trim($data['email'] ?? '');

    // Always return 200 — never reveal whether the address exists
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        member_json(['success' => true]);
    }

    $user = OutpostDB::fetchOne(
        "SELECT id, username, email FROM users WHERE email = ? AND role IN ('free_member','paid_member')",
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
        $resetUrl = "{$scheme}://{$host}/outpost/member-pages/reset-password.php?token={$token}";

        $subject  = 'Reset your password';
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
            error_log('Member password reset email failed: ' . $e->getMessage());
        }
    }

    member_json(['success' => true]);
}

if ($action === 'reset' && $method === 'POST') {
    outpost_ip_rate_limit('member_reset', 10, 300); // 10 per 5 minutes
    require_once __DIR__ . '/auth.php';
    member_api_ensure_reset_columns();

    $data     = member_json_body();
    $token    = trim($data['token'] ?? '');
    $password = $data['password'] ?? '';

    if (!$token) {
        member_error('Reset token required');
    }
    if (strlen($password) < 8) {
        member_error('Password must be at least 8 characters');
    }

    $user = OutpostDB::fetchOne(
        "SELECT id FROM users
         WHERE reset_token = ? AND reset_token_expires > datetime('now')
           AND role IN ('free_member','paid_member')",
        [$token]
    );

    if (!$user) {
        member_error('Reset link is invalid or has expired', 400);
    }

    OutpostDB::query(
        "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?",
        [OutpostAuth::hashPassword($password), $user['id']]
    );

    member_json(['success' => true]);
}

if ($action === 'resend-verify' && $method === 'POST') {
    member_api_ensure_reset_columns();

    $data  = member_json_body();
    $email = trim($data['email'] ?? '');

    // Rate-limit: 60s per session
    OutpostMember::init();
    $lastResend = $_SESSION['last_resend_verify'] ?? 0;
    if (time() - $lastResend < 60) {
        member_json(['success' => true]); // silent rate limit
    }
    $_SESSION['last_resend_verify'] = time();

    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = OutpostDB::fetchOne(
            "SELECT id, username, email FROM users WHERE email = ? AND role IN ('free_member','paid_member') AND email_verified IS NULL AND verify_token IS NOT NULL",
            [$email]
        );
        if ($user) {
            OutpostMember::sendVerificationEmail($user['id'], $user['username'], $user['email']);
        }
    }

    // Always return success — don't reveal if email exists
    member_json(['success' => true]);
}

// ── JWT Token Endpoints ──────────────────────────────────

if ($action === 'token' && $method === 'POST') {
    outpost_ip_rate_limit('member_token', 10, 60); // 10 per minute
    $data = member_json_body();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$email || !$password) {
        member_error('Email and password required');
    }

    // Find member by email or username
    $user = OutpostDB::fetchOne(
        "SELECT * FROM users WHERE (email = ? OR username = ?) AND role IN ('free_member', 'paid_member')",
        [$email, $email]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        member_error('Invalid credentials', 401);
    }

    if (($user['member_status'] ?? 'active') === 'suspended') {
        member_error('Your account has been suspended', 403);
    }

    // Check email verification
    $verifyRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'require_email_verification'");
    $requireVerify = $verifyRow && $verifyRow['value'] === '1';
    if ($requireVerify && empty($user['email_verified']) && !empty($user['verify_token'])) {
        member_error('Please verify your email address first', 403);
    }

    $secret = outpost_jwt_secret();
    $exp = time() + (86400 * 30); // 30 days
    $token = outpost_jwt_encode([
        'sub'  => $user['id'],
        'type' => 'member',
        'exp'  => $exp,
    ], $secret);

    // Update last login
    OutpostDB::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
    _member_api_record_event($user['id'], 'login');

    member_json([
        'token' => $token,
        'member' => [
            'id'    => $user['id'],
            'name'  => $user['display_name'] ?: $user['username'],
            'email' => $user['email'],
            'tier'  => $user['role'] === 'paid_member' ? 'paid' : 'free',
        ],
        'expires_at' => date('c', $exp),
    ]);
}

if ($action === 'token/register' && $method === 'POST') {
    outpost_ip_rate_limit('member_register', 5, 300); // 5 per 5 minutes
    $data = member_json_body();

    $result = OutpostMember::register(
        $data['name'] ?? $data['username'] ?? '',
        $data['email'] ?? '',
        $data['password'] ?? ''
    );

    if (!$result['success']) {
        member_error($result['error']);
    }

    // If verification required, no token yet
    if (!empty($result['requires_verification'])) {
        member_json([
            'requires_verification' => true,
            'message' => 'Please check your email to verify your account.',
        ]);
    }

    $member = $result['member'];
    $secret = outpost_jwt_secret();
    $exp = time() + (86400 * 30);
    $token = outpost_jwt_encode([
        'sub'  => $member['id'],
        'type' => 'member',
        'exp'  => $exp,
    ], $secret);

    _member_api_record_event($member['id'], 'signup');
    try {
        require_once __DIR__ . '/webhooks.php';
        ensure_webhooks_tables();
        dispatch_webhook('member.created', ['email' => $data['email'] ?? '', 'username' => $data['name'] ?? $data['username'] ?? '']);
    } catch (\Throwable $e) {}

    member_json([
        'token' => $token,
        'member' => [
            'id'    => $member['id'],
            'name'  => $member['username'],
            'email' => $member['email'],
            'tier'  => 'free',
        ],
        'expires_at' => date('c', $exp),
    ], 201);
}

if ($action === 'token/refresh' && $method === 'POST') {
    // Must have a valid bearer token
    if (!$_jwt_auth || !$_jwt_member) {
        member_error('Valid bearer token required', 401);
    }

    $secret = outpost_jwt_secret();
    $exp = time() + (86400 * 30);
    $token = outpost_jwt_encode([
        'sub'  => $_jwt_member['id'],
        'type' => 'member',
        'exp'  => $exp,
    ], $secret);

    member_json([
        'token' => $token,
        'member' => [
            'id'    => (int) $_jwt_member['id'],
            'name'  => $_jwt_member['display_name'] ?: $_jwt_member['username'],
            'email' => $_jwt_member['email'],
            'tier'  => $_jwt_member['role'] === 'paid_member' ? 'paid' : 'free',
        ],
        'expires_at' => date('c', $exp),
    ]);
}

if ($action === 'token/me' && $method === 'GET') {
    // Must have a valid bearer token
    if (!$_jwt_auth || !$_jwt_member) {
        member_error('Valid bearer token required', 401);
    }

    $full = OutpostDB::fetchOne(
        'SELECT id, username, email, role, display_name, avatar, member_since FROM users WHERE id = ?',
        [$_jwt_member['id']]
    );
    if (!$full) member_error('Member not found', 404);

    member_json([
        'member' => [
            'id'           => (int) $full['id'],
            'name'         => $full['display_name'] ?: $full['username'],
            'username'     => $full['username'],
            'email'        => $full['email'],
            'tier'         => $full['role'] === 'paid_member' ? 'paid' : 'free',
            'avatar'       => $full['avatar'] ?? null,
            'member_since' => $full['member_since'] ?? null,
        ],
    ]);
}

if ($action === 'register' && $method === 'POST') {
    outpost_ip_rate_limit('member_register_session', 5, 300);
    $data = member_json_body();
    $result = OutpostMember::register(
        $data['username'] ?? '',
        $data['email'] ?? '',
        $data['password'] ?? ''
    );
    if ($result['success']) {
        $memberId = $result['member']['id'] ?? null;

        // Store display_name if first/last name provided
        if ($memberId && !empty($data['display_name'])) {
            OutpostDB::update('users', ['display_name' => trim($data['display_name'])], 'id = ?', [$memberId]);
        }

        // Store custom meta fields (e.g. is_military, is_first_responder)
        if ($memberId && !empty($data['meta']) && is_array($data['meta'])) {
            $existing = OutpostDB::fetchOne('SELECT meta FROM users WHERE id = ?', [$memberId]);
            $meta = ($existing && $existing['meta']) ? json_decode($existing['meta'], true) : [];
            if (!is_array($meta)) $meta = [];
            $meta = array_merge($meta, $data['meta']);
            OutpostDB::update('users', ['meta' => json_encode($meta)], 'id = ?', [$memberId]);
        }

        // Record signup event for funnels
        if ($memberId) {
            _member_api_record_event($memberId, 'signup');
        }
        try {
            require_once __DIR__ . '/webhooks.php';
            ensure_webhooks_tables();
            dispatch_webhook('member.created', ['email' => $data['email'] ?? '', 'username' => $data['username'] ?? '']);
        } catch (\Throwable $e) {
            error_log('Outpost webhook error (member): ' . $e->getMessage());
        }
        $code = !empty($result['requires_verification']) ? 200 : 201;
        member_json($result, $code);
    } else {
        member_error($result['error']);
    }
}

if ($action === 'login' && $method === 'POST') {
    outpost_ip_rate_limit('member_login_session', 10, 60);
    $data = member_json_body();
    $identifier = trim($data['username'] ?? $data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$identifier || !$password) {
        member_error('Username/email and password required');
    }

    $result = OutpostMember::login($identifier, $password);
    if ($result['success']) {
        // Record login event for funnels
        $memberId = $result['member']['id'] ?? null;
        if ($memberId) {
            _member_api_record_event($memberId, 'login');
        }
        $result['csrf_token'] = OutpostMember::csrfToken();
        member_json($result);
    } else {
        $response = ['error' => $result['error']];
        if (!empty($result['needs_verification'])) {
            $response['needs_verification'] = true;
            $response['email'] = $result['email'] ?? '';
        }
        member_json($response, 401);
    }
}

// ── Auth required from here ──────────────────────────────
// Accept either session auth OR JWT bearer token
if (!$_jwt_auth && !OutpostMember::check()) {
    member_error('Authentication required', 401);
}

// CSRF on mutations — skip for JWT-authenticated requests (tokens are not CSRF-vulnerable)
if (in_array($method, ['POST', 'PUT', 'DELETE']) && !$_jwt_auth) {
    OutpostMember::validateCsrf();
}

match (true) {
    $action === 'logout' && $method === 'POST' => (function () {
        OutpostMember::logout();
        member_json(['success' => true]);
    })(),

    $action === 'me' && $method === 'GET' => (function () {
        global $_jwt_auth, $_jwt_member;

        if ($_jwt_auth && $_jwt_member) {
            $member = ['id' => $_jwt_member['id']];
        } else {
            $member = OutpostMember::currentMember();
        }
        if (!$member) member_error('Not authenticated', 401);

        $full = OutpostDB::fetchOne(
            'SELECT id, username, email, role, display_name, avatar, member_since FROM users WHERE id = ?',
            [$member['id']]
        );

        $response = ['member' => $full];
        if (!$_jwt_auth) {
            $response['csrf_token'] = OutpostMember::csrfToken();
        }
        member_json($response);
    })(),

    $action === 'profile' && $method === 'PUT' => (function () {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        $data = member_json_body();

        $update = [];
        if (isset($data['display_name'])) $update['display_name'] = trim($data['display_name']);
        if (isset($data['email'])) {
            $email = trim($data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                member_error('Invalid email');
            }
            // Check uniqueness
            $existing = OutpostDB::fetchOne(
                'SELECT id FROM users WHERE email = ? AND id != ?',
                [$email, $member['id']]
            );
            if ($existing) member_error('Email already in use');
            $update['email'] = $email;
        }

        if (empty($update)) member_error('Nothing to update');

        OutpostDB::update('users', $update, 'id = ?', [$member['id']]);
        member_json(['success' => true]);
    })(),

    $action === 'password' && $method === 'PUT' => (function () {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        $data = member_json_body();

        $current = $data['current_password'] ?? '';
        $new = $data['new_password'] ?? '';

        if (!$current || !$new) {
            member_error('Current and new password required');
        }
        if (strlen($new) < 8) {
            member_error('New password must be at least 8 characters');
        }

        // Verify current password
        $user = OutpostDB::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$member['id']]);
        if (!password_verify($current, $user['password_hash'])) {
            member_error('Current password is incorrect', 403);
        }

        require_once __DIR__ . '/auth.php';
        OutpostDB::update('users', [
            'password_hash' => OutpostAuth::hashPassword($new),
        ], 'id = ?', [$member['id']]);

        member_json(['success' => true]);
    })(),

    // Lodge (member portal)
    $action === 'lodge/dashboard' && $method === 'GET' => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? $_jwt_member : OutpostMember::currentMember();
        $full = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$member['id']]);
        handle_lodge_dashboard($full);
    })(),

    $action === 'lodge/items' && $method === 'GET' => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_items_list($member);
    })(),

    $action === 'lodge/items' && $method === 'POST' => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_item_create($member);
    })(),

    $action === 'lodge/items' && $method === 'PUT' && isset($_GET['id']) => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_item_update($member);
    })(),

    $action === 'lodge/items' && $method === 'DELETE' && isset($_GET['id']) => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_item_delete($member);
    })(),

    $action === 'lodge/profile' && $method === 'GET' => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_profile_get($member);
    })(),

    $action === 'lodge/profile' && $method === 'PUT' => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_profile_update($member);
    })(),

    $action === 'lodge/upload' && $method === 'POST' => (function() {
        global $_jwt_auth, $_jwt_member;
        $member = $_jwt_auth ? ['id' => $_jwt_member['id']] : OutpostMember::currentMember();
        handle_lodge_upload($member);
    })(),

    default => member_error('Not found', 404),
};
