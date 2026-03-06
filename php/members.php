<?php
/**
 * Outpost CMS — Front-end Member Authentication
 * Separate session from admin auth. Used by theme templates and member-api.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class OutpostMember {
    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) return;
        self::$initialized = true;

        // Use a separate session name from admin
        if (session_status() === PHP_SESSION_NONE) {
            session_name('outpost_member');
            session_set_cookie_params([
                'lifetime' => 86400 * 30, // 30 days
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
            session_start();
        }
    }

    public static function login(string $identifier, string $password): array {
        self::init();

        if (self::isRateLimited()) {
            return ['success' => false, 'error' => 'Too many login attempts. Please wait.'];
        }

        // Allow login by username or email
        $user = OutpostDB::fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND role IN ('free_member', 'paid_member')",
            [$identifier, $identifier]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt();
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Check suspended
        if (($user['member_status'] ?? 'active') === 'suspended') {
            return ['success' => false, 'error' => 'Your account has been suspended.'];
        }

        // Check email verification (if enabled)
        $verifyRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'require_email_verification'");
        $requireVerify = $verifyRow && $verifyRow['value'] === '1';
        if ($requireVerify && empty($user['email_verified']) && !empty($user['verify_token'])) {
            return [
                'success' => false,
                'error' => 'Please verify your email address before signing in.',
                'needs_verification' => true,
                'email' => self::maskEmail($user['email']),
            ];
        }

        session_regenerate_id(true);

        $_SESSION['outpost_member_id'] = $user['id'];
        $_SESSION['outpost_member_username'] = $user['username'];
        $_SESSION['outpost_member_role'] = $user['role'];
        $_SESSION['member_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['outpost_member_login_time'] = time();

        // Update last login
        OutpostDB::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        unset($_SESSION['member_login_attempts']);

        return [
            'success' => true,
            'member' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ];
    }

    public static function register(string $username, string $email, string $password): array {
        self::init();

        if (self::isRateLimited()) {
            return ['success' => false, 'error' => 'Too many attempts. Please wait.'];
        }

        $username = trim($username);
        $email = trim($email);

        if (strlen($username) < 3) {
            return ['success' => false, 'error' => 'Username must be at least 3 characters.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Valid email is required.'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        // Check uniqueness
        $existing = OutpostDB::fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing) {
            return ['success' => false, 'error' => 'Username is already taken.'];
        }

        $existing = OutpostDB::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            return ['success' => false, 'error' => 'Email is already registered.'];
        }

        require_once __DIR__ . '/auth.php';

        // Check if email verification is required
        $verifyRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'require_email_verification'");
        $requireVerify = $verifyRow && $verifyRow['value'] === '1';

        $insertData = [
            'username' => $username,
            'email' => $email,
            'password_hash' => OutpostAuth::hashPassword($password),
            'role' => 'free_member',
            'member_since' => date('Y-m-d H:i:s'),
            'member_status' => 'active',
        ];

        if (!$requireVerify) {
            $insertData['email_verified'] = date('Y-m-d H:i:s');
        }

        $id = OutpostDB::insert('users', $insertData);

        if ($requireVerify) {
            // Send verification email — don't auto-login
            self::sendVerificationEmail($id, $username, $email);
            return [
                'success' => true,
                'requires_verification' => true,
            ];
        }

        // Auto-login after registration
        session_regenerate_id(true);
        $_SESSION['outpost_member_id'] = $id;
        $_SESSION['outpost_member_username'] = $username;
        $_SESSION['outpost_member_role'] = 'free_member';
        $_SESSION['member_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['outpost_member_login_time'] = time();

        return [
            'success' => true,
            'member' => [
                'id' => $id,
                'username' => $username,
                'email' => $email,
                'role' => 'free_member',
            ],
        ];
    }

    public static function logout(): void {
        self::init();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool {
        self::init();
        if (!isset($_SESSION['outpost_member_id'])) return false;
        if ((time() - ($_SESSION['outpost_member_login_time'] ?? 0)) >= (86400 * 30)) return false;

        // Revalidate against DB every 5 minutes (catches suspended users)
        $lastCheck = $_SESSION['outpost_member_db_check'] ?? 0;
        if (time() - $lastCheck > 300) {
            $user = OutpostDB::fetchOne(
                "SELECT member_status FROM users WHERE id = ? AND role IN ('free_member', 'paid_member')",
                [$_SESSION['outpost_member_id']]
            );
            if (!$user || ($user['member_status'] ?? 'active') === 'suspended') {
                self::logout();
                return false;
            }
            $_SESSION['outpost_member_db_check'] = time();
        }
        return true;
    }

    public static function currentMember(): ?array {
        self::init();
        if (!self::check()) return null;

        return [
            'id' => $_SESSION['outpost_member_id'],
            'username' => $_SESSION['outpost_member_username'],
            'role' => $_SESSION['outpost_member_role'],
        ];
    }

    public static function csrfToken(): string {
        self::init();
        if (empty($_SESSION['member_csrf'])) {
            $_SESSION['member_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['member_csrf'];
    }

    public static function validateCsrf(): void {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!hash_equals(self::csrfToken(), $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }

    public static function isPaid(): bool {
        $role = $_SESSION['outpost_member_role'] ?? '';
        return $role === 'paid_member';
    }

    private static function isRateLimited(): bool {
        // Session-based check
        $attempts = $_SESSION['member_login_attempts'] ?? [];
        $window = time() - 60;
        $attempts = array_filter($attempts, fn($t) => $t > $window);
        $_SESSION['member_login_attempts'] = $attempts;
        if (count($attempts) >= 5) return true;

        // IP-based check (prevents bypass by discarding session cookies)
        return self::isIpRateLimited();
    }

    private static function recordFailedAttempt(): void {
        if (!isset($_SESSION['member_login_attempts'])) {
            $_SESSION['member_login_attempts'] = [];
        }
        $_SESSION['member_login_attempts'][] = time();
        self::recordIpAttempt();
    }

    private static function isIpRateLimited(): bool {
        $ip = ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . ':member_login';
        $now = time();
        $window = $now - 60;

        OutpostDB::connect()->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip TEXT PRIMARY KEY, attempts TEXT DEFAULT '[]', updated_at INTEGER
        )");
        OutpostDB::query('DELETE FROM login_rate_limits WHERE updated_at < ?', [$window]);

        $row = OutpostDB::fetchOne('SELECT attempts FROM login_rate_limits WHERE ip = ?', [$ip]);
        $timestamps = $row ? json_decode($row['attempts'], true) : [];
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $window));
        return count($timestamps) >= 5;
    }

    private static function recordIpAttempt(): void {
        $ip = ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . ':member_login';
        $now = time();
        $window = $now - 60;

        $row = OutpostDB::fetchOne('SELECT attempts FROM login_rate_limits WHERE ip = ?', [$ip]);
        $timestamps = $row ? json_decode($row['attempts'], true) : [];
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $window));
        $timestamps[] = $now;

        OutpostDB::query(
            "INSERT INTO login_rate_limits (ip, attempts, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(ip) DO UPDATE SET attempts = excluded.attempts, updated_at = excluded.updated_at",
            [$ip, json_encode($timestamps), $now]
        );
    }

    public static function sendVerificationEmail(int $userId, string $username, string $email): void {
        require_once __DIR__ . '/mailer.php';

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        OutpostDB::update('users', [
            'verify_token'         => $token,
            'verify_token_expires' => $expires,
        ], 'id = ?', [$userId]);

        $scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $verifyUrl = "{$scheme}://{$host}/outpost/member-pages/verify-email.php?token={$token}";

        $name    = htmlspecialchars($username);
        $subject = 'Verify your email address';
        $text    = "Hi {$name},\n\nPlease verify your email address by clicking the link below. The link expires in 24 hours.\n\n{$verifyUrl}\n\nIf you didn't create an account, you can safely ignore this email.\n";
        $html    = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#1a1a1a;max-width:520px;margin:40px auto;padding:0 24px'>"
                 . "<h2 style='font-size:1.25rem;margin-bottom:8px'>Verify your email</h2>"
                 . "<p style='color:#525252;margin-bottom:24px'>Hi {$name}, please verify your email address by clicking the button below — the link expires in 24 hours.</p>"
                 . "<a href='" . htmlspecialchars($verifyUrl) . "' style='display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600'>Verify email</a>"
                 . "<p style='color:#737373;font-size:0.8rem;margin-top:24px'>Or copy this link: " . htmlspecialchars($verifyUrl) . "</p>"
                 . "<p style='color:#737373;font-size:0.8rem'>If you didn't create an account, you can safely ignore this email.</p>"
                 . "</body></html>";

        try {
            $mailer = OutpostMailer::fromSettings();
            $mailer->send($email, $subject, $text, $html);
        } catch (\Exception $e) {
            error_log('Member verification email failed: ' . $e->getMessage());
        }
    }

    public static function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***';
        $local = $parts[0];
        $domain = $parts[1];
        $len = strlen($local);
        if ($len <= 2) {
            $masked = $local[0] . str_repeat('*', max($len - 1, 1));
        } else {
            $masked = $local[0] . str_repeat('*', $len - 2) . $local[$len - 1];
        }
        return $masked . '@' . $domain;
    }
}
