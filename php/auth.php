<?php
/**
 * Outpost CMS — Authentication
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';

class OutpostAuth {
    private static bool $apiKeyAuth = false;
    private static ?array $apiKeyUser = null;

    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(OUTPOST_SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => OUTPOST_SESSION_LIFETIME,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
            session_start();
        }
    }

    public static function login(string $username, string $password): array {
        self::init();

        // Rate limiting
        if (self::isRateLimited()) {
            return ['success' => false, 'error' => 'Too many login attempts. Please wait.'];
        }

        $user = OutpostDB::fetchOne('SELECT * FROM users WHERE username = ?', [$username]);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt();
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // Block member roles from admin login
        if (!outpost_is_internal_role($user['role'])) {
            return ['success' => false, 'error' => 'This login is for site administrators only.'];
        }

        // Regenerate session
        session_regenerate_id(true);

        $_SESSION['outpost_user_id'] = $user['id'];
        $_SESSION['outpost_username'] = $user['username'];
        $_SESSION['outpost_role'] = $user['role'];
        $_SESSION['outpost_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['outpost_login_time'] = time();

        // Update last login
        OutpostDB::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // Clear rate limit
        unset($_SESSION['outpost_login_attempts']);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
            ],
            'csrf_token' => $_SESSION['outpost_csrf'],
        ];
    }

    /**
     * Create a session for a user by ID (without re-verifying password).
     * Used after TOTP verification and by the normal login path.
     */
    public static function createSession(int $userId): array {
        self::init();

        $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        session_regenerate_id(true);

        $_SESSION['outpost_user_id'] = $user['id'];
        $_SESSION['outpost_username'] = $user['username'];
        $_SESSION['outpost_role'] = $user['role'];
        $_SESSION['outpost_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['outpost_login_time'] = time();

        OutpostDB::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        unset($_SESSION['outpost_login_attempts']);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
            ],
            'csrf_token' => $_SESSION['outpost_csrf'],
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
        return isset($_SESSION['outpost_user_id']) &&
               (time() - ($_SESSION['outpost_login_time'] ?? 0)) < OUTPOST_SESSION_LIFETIME;
    }

    public static function requireAuth(): void {
        // Try session auth first
        if (self::check()) {
            $role = $_SESSION['outpost_role'] ?? '';
            if (!outpost_is_internal_role($role)) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin access denied']);
                exit;
            }
            return;
        }

        // Try API key auth as fallback
        if (self::checkApiKey()) {
            return;
        }

        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    public static function isApiKeyAuth(): bool {
        return self::$apiKeyAuth;
    }

    private static function checkApiKey(): bool {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return false;
        }
        $providedKey = $m[1];

        // Use prefix for O(1) lookup instead of scanning all keys with bcrypt
        $prefix = substr($providedKey, 0, 11);
        $keys = OutpostDB::fetchAll('SELECT * FROM api_keys WHERE key_prefix = ?', [$prefix]);
        foreach ($keys as $row) {
            if (password_verify($providedKey, $row['key_hash'])) {
                $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
                if (!$user || !outpost_is_internal_role($user['role'])) {
                    return false;
                }
                self::$apiKeyAuth = true;
                self::$apiKeyUser = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                ];
                // Regenerate session ID to prevent session fixation attacks
                session_regenerate_id(true);
                // Populate session vars so role/grant checks work for API key auth
                $_SESSION['outpost_user_id'] = $user['id'];
                $_SESSION['outpost_role'] = $user['role'];
                // Update last_used_at
                OutpostDB::update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
                return true;
            }
        }
        return false;
    }

    public static function currentUser(): ?array {
        if (self::$apiKeyAuth && self::$apiKeyUser) {
            return self::$apiKeyUser;
        }
        self::init();
        if (!self::check()) return null;
        return [
            'id' => $_SESSION['outpost_user_id'],
            'username' => $_SESSION['outpost_username'],
            'role' => $_SESSION['outpost_role'],
        ];
    }

    public static function csrfToken(): string {
        self::init();
        if (empty($_SESSION['outpost_csrf'])) {
            $_SESSION['outpost_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['outpost_csrf'];
    }

    public static function validateCsrf(): void {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(self::csrfToken(), $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }

    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Rate-limit authenticated API mutations (POST/PUT/DELETE).
     * Uses session for browser auth, IP-based for API key auth.
     */
    public static function checkApiRateLimit(): void {
        if (self::$apiKeyAuth) {
            self::checkApiKeyRateLimit();
            return;
        }
        self::init();
        $key = 'outpost_api_mutations';
        $window = time() - OUTPOST_API_RATE_LIMIT_WINDOW;
        $timestamps = $_SESSION[$key] ?? [];
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $window));

        if (count($timestamps) >= OUTPOST_API_RATE_LIMIT) {
            http_response_code(429);
            $retry = max(1, ($timestamps[0] ?? time()) + OUTPOST_API_RATE_LIMIT_WINDOW - time());
            header("Retry-After: $retry");
            echo json_encode(['error' => 'Too many requests. Please slow down.']);
            exit;
        }

        $timestamps[] = time();
        $_SESSION[$key] = $timestamps;
    }

    /**
     * IP-based rate limiting for API key auth (no session available).
     */
    private static function checkApiKeyRateLimit(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $now = time();
        $window = $now - OUTPOST_API_RATE_LIMIT_WINDOW;

        // Ensure table exists
        OutpostDB::connect()->exec("
            CREATE TABLE IF NOT EXISTS api_key_rate_limits (
                ip TEXT PRIMARY KEY,
                timestamps TEXT DEFAULT '[]',
                updated_at INTEGER
            )
        ");

        // Clean old entries
        OutpostDB::query('DELETE FROM api_key_rate_limits WHERE updated_at < ?', [$window]);

        $row = OutpostDB::fetchOne('SELECT timestamps FROM api_key_rate_limits WHERE ip = ?', [$ip]);
        $timestamps = $row ? json_decode($row['timestamps'], true) : [];
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $window));

        if (count($timestamps) >= OUTPOST_API_RATE_LIMIT) {
            http_response_code(429);
            $retry = max(1, ($timestamps[0] ?? $now) + OUTPOST_API_RATE_LIMIT_WINDOW - $now);
            header("Retry-After: $retry");
            echo json_encode(['error' => 'Too many requests. Please slow down.']);
            exit;
        }

        $timestamps[] = $now;
        OutpostDB::query(
            "INSERT INTO api_key_rate_limits (ip, timestamps, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(ip) DO UPDATE SET timestamps = excluded.timestamps, updated_at = excluded.updated_at",
            [$ip, json_encode($timestamps), $now]
        );
    }

    public static function isRateLimited(): bool {
        // Session-based check
        $attempts = $_SESSION['outpost_login_attempts'] ?? [];
        $window = time() - OUTPOST_RATE_LIMIT_WINDOW;
        $attempts = array_filter($attempts, fn($t) => $t > $window);
        $_SESSION['outpost_login_attempts'] = $attempts;
        if (count($attempts) >= OUTPOST_RATE_LIMIT_ATTEMPTS) return true;

        // IP-based check (prevents bypass by discarding session cookies)
        return self::isIpRateLimited('admin_login');
    }

    public static function recordFailedAttempt(): void {
        if (!isset($_SESSION['outpost_login_attempts'])) {
            $_SESSION['outpost_login_attempts'] = [];
        }
        $_SESSION['outpost_login_attempts'][] = time();
        self::recordIpAttempt('admin_login');
    }

    private static function isIpRateLimited(string $bucket): bool {
        $ip = ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . ':' . $bucket;
        $now = time();
        $window = $now - OUTPOST_RATE_LIMIT_WINDOW;

        OutpostDB::connect()->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip TEXT PRIMARY KEY, attempts TEXT DEFAULT '[]', updated_at INTEGER
        )");
        OutpostDB::query('DELETE FROM login_rate_limits WHERE updated_at < ?', [$window]);

        $row = OutpostDB::fetchOne('SELECT attempts FROM login_rate_limits WHERE ip = ?', [$ip]);
        $timestamps = $row ? json_decode($row['attempts'], true) : [];
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $window));
        return count($timestamps) >= OUTPOST_RATE_LIMIT_ATTEMPTS;
    }

    private static function recordIpAttempt(string $bucket): void {
        $ip = ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . ':' . $bucket;
        $now = time();
        $window = $now - OUTPOST_RATE_LIMIT_WINDOW;

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
}
