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

header('Content-Type: application/json; charset=utf-8');

// ── CORS for dev ─────────────────────────────────────────
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (str_contains($origin, 'localhost')) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

// ── Public routes (no auth required) ─────────────────────

if ($action === 'forgot' && $method === 'POST') {
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

if ($action === 'register' && $method === 'POST') {
    $data = member_json_body();
    $result = OutpostMember::register(
        $data['username'] ?? '',
        $data['email'] ?? '',
        $data['password'] ?? ''
    );
    if ($result['success']) {
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
    $data = member_json_body();
    $identifier = trim($data['username'] ?? $data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$identifier || !$password) {
        member_error('Username/email and password required');
    }

    $result = OutpostMember::login($identifier, $password);
    if ($result['success']) {
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
if (!OutpostMember::check()) {
    member_error('Authentication required', 401);
}

// CSRF on mutations
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    OutpostMember::validateCsrf();
}

match (true) {
    $action === 'logout' && $method === 'POST' => (function () {
        OutpostMember::logout();
        member_json(['success' => true]);
    })(),

    $action === 'me' && $method === 'GET' => (function () {
        $member = OutpostMember::currentMember();
        if (!$member) member_error('Not authenticated', 401);

        $full = OutpostDB::fetchOne(
            'SELECT id, username, email, role, display_name, avatar, member_since FROM users WHERE id = ?',
            [$member['id']]
        );
        member_json([
            'member' => $full,
            'csrf_token' => OutpostMember::csrfToken(),
        ]);
    })(),

    $action === 'profile' && $method === 'PUT' => (function () {
        $member = OutpostMember::currentMember();
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
        $member = OutpostMember::currentMember();
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

    default => member_error('Not found', 404),
};
