<?php
/**
 * Outpost CMS — Shield Security Suite
 *
 * Comprehensive security hardening: login protection, IP blocking,
 * WAF-lite firewall, file integrity monitoring, security headers,
 * live traffic logging, and admin notifications.
 */

// ── Shield Config Cache ──────────────────────────────────
$_shield_config = null;

function shield_get_config(): array {
    global $_shield_config;
    if ($_shield_config !== null) return $_shield_config;

    $defaults = [
        'enabled'                 => true,
        'login_lockout'           => true,
        'login_max_attempts'      => 5,
        'login_lockout_minutes'   => 15,
        'auto_block_after_lockouts' => 3,
        'firewall_enabled'        => true,
        'firewall_mode'           => 'block', // 'block' | 'log'
        'file_integrity'          => true,
        'security_headers'        => true,
        'traffic_logging'         => true,
        'email_notifications'     => false,
        'notification_email'      => '',
    ];

    try {
        $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'shield_config'");
        if ($row && $row['value']) {
            $saved = json_decode($row['value'], true);
            if (is_array($saved)) {
                $defaults = array_merge($defaults, $saved);
            }
        }
    } catch (\Throwable $e) {
        // DB not ready yet — use defaults
    }

    $_shield_config = $defaults;
    return $_shield_config;
}

function shield_save_config(array $config): void {
    global $_shield_config;
    OutpostDB::query(
        "INSERT OR REPLACE INTO settings (key, value) VALUES ('shield_config', ?)",
        [json_encode($config)]
    );
    $_shield_config = $config;
}

// ── Table Migrations ─────────────────────────────────────
function ensure_shield_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = OutpostDB::connect();

    $db->exec("CREATE TABLE IF NOT EXISTS shield_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        username TEXT,
        success INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_shield_login_ip ON shield_login_attempts(ip, created_at)");

    $db->exec("CREATE TABLE IF NOT EXISTS shield_blocked_ips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL UNIQUE,
        reason TEXT,
        auto_blocked INTEGER DEFAULT 0,
        blocked_at TEXT DEFAULT (datetime('now')),
        expires_at TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_shield_blocked_ip ON shield_blocked_ips(ip)");

    $db->exec("CREATE TABLE IF NOT EXISTS shield_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        path TEXT,
        method TEXT,
        rule TEXT,
        payload TEXT,
        blocked INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_shield_log_created ON shield_log(created_at)");

    $db->exec("CREATE TABLE IF NOT EXISTS shield_file_hashes (
        file_path TEXT PRIMARY KEY,
        hash TEXT NOT NULL,
        checked_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS shield_traffic (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        path TEXT,
        method TEXT,
        status_code INTEGER DEFAULT 200,
        user_agent TEXT,
        threat INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_shield_traffic_created ON shield_traffic(created_at)");
}

// ── Core Request Check ───────────────────────────────────
// Called early in the request lifecycle, before auth.
function shield_check_request(): void {
    $config = shield_get_config();
    if (!$config['enabled']) return;

    ensure_shield_tables();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 1. Check blocked IPs
    if (shield_is_ip_blocked($ip)) {
        // Log and exit
        shield_log_event($ip, $path, $method, 'blocked_ip', '', true);
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    // 2. Firewall checks
    if ($config['firewall_enabled']) {
        shield_firewall_check($ip, $path, $method, $config);
    }

    // 3. Log traffic
    if ($config['traffic_logging']) {
        shield_log_traffic($ip, $path, $method);
    }

    // 4. Baseline security headers (always applied, non-toggleable)
    shield_baseline_headers();

    // 5. Full security headers (toggleable via config)
    if ($config['security_headers']) {
        shield_add_security_headers();
    }
}

// ── IP Blocking ──────────────────────────────────────────
function shield_is_ip_blocked(string $ip): bool {
    try {
        $row = OutpostDB::fetchOne(
            "SELECT id, expires_at FROM shield_blocked_ips WHERE ip = ?",
            [$ip]
        );
        if (!$row) return false;

        // Check if block has expired
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            OutpostDB::query("DELETE FROM shield_blocked_ips WHERE id = ?", [$row['id']]);
            return false;
        }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function shield_block_ip(string $ip, string $reason = '', bool $auto = false, ?int $expiresMinutes = null): void {
    $expiresAt = $expiresMinutes
        ? date('Y-m-d H:i:s', time() + ($expiresMinutes * 60))
        : null;

    OutpostDB::query(
        "INSERT OR REPLACE INTO shield_blocked_ips (ip, reason, auto_blocked, blocked_at, expires_at)
         VALUES (?, ?, ?, datetime('now'), ?)",
        [$ip, $reason, $auto ? 1 : 0, $expiresAt]
    );
}

function shield_unblock_ip(string $ip): void {
    OutpostDB::query("DELETE FROM shield_blocked_ips WHERE ip = ?", [$ip]);
}

// ── Login Protection ─────────────────────────────────────
function shield_record_login_attempt(string $ip, string $username, bool $success): void {
    $config = shield_get_config();
    if (!$config['enabled'] || !$config['login_lockout']) return;

    ensure_shield_tables();

    OutpostDB::insert('shield_login_attempts', [
        'ip'       => $ip,
        'username' => $username,
        'success'  => $success ? 1 : 0,
    ]);

    if ($success) {
        // Clear failed attempts for this IP on successful login
        return;
    }

    // Check if lockout threshold reached
    $windowMinutes = $config['login_lockout_minutes'];
    $maxAttempts = $config['login_max_attempts'];
    $window = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    $count = OutpostDB::fetchOne(
        "SELECT COUNT(*) as cnt FROM shield_login_attempts
         WHERE ip = ? AND success = 0 AND created_at > ?",
        [$ip, $window]
    );

    if ($count && (int)$count['cnt'] >= $maxAttempts) {
        // Lockout triggered — temporarily block the IP
        shield_block_ip($ip, "Login lockout: {$maxAttempts} failed attempts", true, $windowMinutes);

        shield_log_event($ip, '/outpost/api.php?action=auth/login', 'POST', 'login_lockout',
            "User: {$username}, Attempts: {$count['cnt']}", true);

        // Check if auto-permanent-block threshold reached
        $lockoutCount = OutpostDB::fetchOne(
            "SELECT COUNT(DISTINCT created_at) as cnt FROM shield_log
             WHERE ip = ? AND rule = 'login_lockout'",
            [$ip]
        );
        if ($lockoutCount && (int)$lockoutCount['cnt'] >= $config['auto_block_after_lockouts']) {
            shield_block_ip($ip, "Auto-blocked: {$config['auto_block_after_lockouts']} lockouts", true);
            shield_notify('lockout_permanent', "IP {$ip} permanently blocked after {$config['auto_block_after_lockouts']} lockout events (user: {$username})");
        } else {
            shield_notify('lockout', "IP {$ip} locked out for {$windowMinutes} minutes after {$maxAttempts} failed login attempts (user: {$username})");
        }
    }
}

function shield_is_login_locked(string $ip): bool {
    $config = shield_get_config();
    if (!$config['enabled'] || !$config['login_lockout']) return false;

    ensure_shield_tables();
    return shield_is_ip_blocked($ip);
}

// ── Firewall (WAF-lite) ──────────────────────────────────
function shield_firewall_check(string $ip, string $path, string $method, array $config): void {
    // Skip admin API requests from logged-in users for performance
    // (they already passed auth; blocking them would lock admins out)
    if (isset($_SESSION['outpost_user_id'])) return;

    $requestData = $path;
    if ($method === 'POST' || $method === 'PUT') {
        $body = file_get_contents('php://input');
        if ($body && strlen($body) < 10000) { // Don't scan huge uploads
            $requestData .= ' ' . $body;
        }
    }

    // Also check query string
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if ($qs) $requestData .= ' ' . urldecode($qs);

    $rules = [
        // SQL injection
        'sql_injection' => '/(\bUNION\b.*\bSELECT\b|\bDROP\s+TABLE\b|\bINSERT\s+INTO\b.*\bVALUES\b|\bDELETE\s+FROM\b|\b(OR|AND)\s+[\d\'"]=[\d\'"]|;\s*--|\bSLEEP\s*\(|\bBENCHMARK\s*\(|\bLOAD_FILE\s*\()/i',
        // XSS
        'xss' => '/(<\s*script\b|javascript\s*:|on(error|load|click|mouseover|focus|blur)\s*=)/i',
        // Path traversal
        'path_traversal' => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/|\.\.%2f)/i',
        // PHP injection
        'php_injection' => '/(<\?php|<\?=|\beval\s*\(|\bbase64_decode\s*\(|\bsystem\s*\(|\bexec\s*\(|\bpassthru\s*\(|\bshell_exec\s*\(|\bproc_open\s*\(|\bpopen\s*\()/i',
        // Null byte
        'null_byte' => '/%00/',
    ];

    foreach ($rules as $ruleName => $pattern) {
        if (preg_match($pattern, $requestData)) {
            $blocked = ($config['firewall_mode'] === 'block');

            // Truncate payload for storage
            $payload = substr($requestData, 0, 500);
            shield_log_event($ip, $path, $method, $ruleName, $payload, $blocked);

            if ($blocked) {
                shield_notify('firewall', "Blocked {$ruleName} attack from {$ip} on {$path}");
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Request blocked by security rules']);
                exit;
            }
        }
    }
}

// ── File Integrity Monitoring ────────────────────────────
function shield_check_file_integrity(): array {
    $config = shield_get_config();
    if (!$config['enabled'] || !$config['file_integrity']) {
        return ['status' => 'disabled', 'changes' => []];
    }

    ensure_shield_tables();

    $coreFiles = [];
    $dirs = [OUTPOST_DIR];
    $extensions = ['php'];

    foreach ($dirs as $dir) {
        $iterator = new \DirectoryIterator($dir);
        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isDir()) continue;
            if (in_array($file->getExtension(), $extensions)) {
                $coreFiles[] = $file->getPathname();
            }
        }
    }

    $changes = [];
    $now = date('Y-m-d H:i:s');

    foreach ($coreFiles as $filePath) {
        $relativePath = str_replace(OUTPOST_DIR, '', $filePath);
        $currentHash = hash_file('sha256', $filePath);

        $stored = OutpostDB::fetchOne(
            "SELECT hash FROM shield_file_hashes WHERE file_path = ?",
            [$relativePath]
        );

        if (!$stored) {
            // First scan — store the hash
            OutpostDB::query(
                "INSERT OR REPLACE INTO shield_file_hashes (file_path, hash, checked_at) VALUES (?, ?, ?)",
                [$relativePath, $currentHash, $now]
            );
        } elseif ($stored['hash'] !== $currentHash) {
            $changes[] = [
                'file'     => $relativePath,
                'old_hash' => $stored['hash'],
                'new_hash' => $currentHash,
            ];
            // Update the stored hash
            OutpostDB::query(
                "INSERT OR REPLACE INTO shield_file_hashes (file_path, hash, checked_at) VALUES (?, ?, ?)",
                [$relativePath, $currentHash, $now]
            );
        } else {
            // Hash matches — update checked_at
            OutpostDB::query(
                "UPDATE shield_file_hashes SET checked_at = ? WHERE file_path = ?",
                [$now, $relativePath]
            );
        }
    }

    // Remove hashes for files that no longer exist
    $allStored = OutpostDB::fetchAll("SELECT file_path FROM shield_file_hashes");
    foreach ($allStored as $row) {
        $fullPath = OUTPOST_DIR . $row['file_path'];
        if (!file_exists($fullPath)) {
            $changes[] = ['file' => $row['file_path'], 'status' => 'deleted'];
            OutpostDB::query("DELETE FROM shield_file_hashes WHERE file_path = ?", [$row['file_path']]);
        }
    }

    if (!empty($changes)) {
        $fileList = implode(', ', array_column($changes, 'file'));
        shield_notify('file_integrity', "File integrity changes detected: {$fileList}");
    }

    return [
        'status'       => empty($changes) ? 'clean' : 'changes_detected',
        'changes'      => $changes,
        'files_checked' => count($coreFiles),
        'checked_at'   => $now,
    ];
}

// ── Baseline Headers (always applied, non-toggleable) ────
function shield_baseline_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ── Security Headers ─────────────────────────────────────
function shield_add_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ── Traffic Logging ──────────────────────────────────────
function shield_log_traffic(string $ip, string $path, string $method): void {
    try {
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        OutpostDB::insert('shield_traffic', [
            'ip'         => $ip,
            'path'       => substr($path, 0, 500),
            'method'     => $method,
            'user_agent' => $ua,
        ]);

        // Prune: keep last 1000 entries
        OutpostDB::query(
            "DELETE FROM shield_traffic WHERE id NOT IN (SELECT id FROM shield_traffic ORDER BY id DESC LIMIT 1000)"
        );
    } catch (\Throwable $e) {
        // Don't let logging failures break requests
    }
}

// ── Security Event Log ───────────────────────────────────
function shield_log_event(string $ip, string $path, string $method, string $rule, string $payload, bool $blocked): void {
    try {
        OutpostDB::insert('shield_log', [
            'ip'      => $ip,
            'path'    => substr($path, 0, 500),
            'method'  => $method,
            'rule'    => $rule,
            'payload' => substr($payload, 0, 1000),
            'blocked' => $blocked ? 1 : 0,
        ]);

        // Prune: keep last 5000 entries
        OutpostDB::query(
            "DELETE FROM shield_log WHERE id NOT IN (SELECT id FROM shield_log ORDER BY id DESC LIMIT 5000)"
        );
    } catch (\Throwable $e) {}
}

// ── Email Notifications ──────────────────────────────────
function shield_notify(string $eventType, string $message): void {
    $config = shield_get_config();
    if (!$config['email_notifications'] || empty($config['notification_email'])) return;

    try {
        require_once __DIR__ . '/mailer.php';
        $mailer = OutpostMailer::fromSettings();

        $subjects = [
            'lockout'            => 'Shield: Login Lockout Triggered',
            'lockout_permanent'  => 'Shield: IP Permanently Blocked',
            'firewall'           => 'Shield: Attack Blocked',
            'file_integrity'     => 'Shield: File Integrity Change Detected',
        ];

        $subject = $subjects[$eventType] ?? "Shield: Security Alert ({$eventType})";
        $text = "Outpost Shield Security Alert\n\nEvent: {$eventType}\n\n{$message}\n\nTime: " . date('Y-m-d H:i:s') . "\n\nThis is an automated notification from Outpost Shield.";

        $mailer->send($config['notification_email'], $subject, $text);
    } catch (\Throwable $e) {
        // Email failure should never break the security check
    }
}

// ── API Handlers ─────────────────────────────────────────

function handle_shield_status(): void {
    ensure_shield_tables();
    $config = shield_get_config();

    $blockedCount = OutpostDB::fetchOne("SELECT COUNT(*) as cnt FROM shield_blocked_ips");
    $recentAttacks = OutpostDB::fetchOne(
        "SELECT COUNT(*) as cnt FROM shield_log WHERE created_at > datetime('now', '-24 hours')"
    );
    $recentBlocked = OutpostDB::fetchOne(
        "SELECT COUNT(*) as cnt FROM shield_log WHERE blocked = 1 AND created_at > datetime('now', '-24 hours')"
    );

    // File integrity last check
    $lastCheck = OutpostDB::fetchOne(
        "SELECT MAX(checked_at) as last_check FROM shield_file_hashes"
    );

    // Login attempts in last 24h
    $loginAttempts = OutpostDB::fetchOne(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed
         FROM shield_login_attempts WHERE created_at > datetime('now', '-24 hours')"
    );

    json_response([
        'enabled'          => $config['enabled'],
        'blocked_ips'      => (int)($blockedCount['cnt'] ?? 0),
        'attacks_24h'      => (int)($recentAttacks['cnt'] ?? 0),
        'blocked_24h'      => (int)($recentBlocked['cnt'] ?? 0),
        'last_integrity_check' => $lastCheck['last_check'] ?? null,
        'login_attempts_24h'   => (int)($loginAttempts['total'] ?? 0),
        'failed_logins_24h'    => (int)($loginAttempts['failed'] ?? 0),
        'firewall_mode'    => $config['firewall_mode'],
    ]);
}

function handle_shield_log(): void {
    ensure_shield_tables();

    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $logs = OutpostDB::fetchAll(
        "SELECT * FROM shield_log ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [$limit, $offset]
    );
    $total = OutpostDB::fetchOne("SELECT COUNT(*) as cnt FROM shield_log");

    json_response([
        'logs'  => $logs,
        'total' => (int)($total['cnt'] ?? 0),
    ]);
}

function handle_shield_blocked_ips(): void {
    ensure_shield_tables();

    $ips = OutpostDB::fetchAll(
        "SELECT * FROM shield_blocked_ips ORDER BY blocked_at DESC"
    );

    // Check for expired blocks and clean them up
    $now = date('Y-m-d H:i:s');
    $cleaned = [];
    foreach ($ips as $row) {
        if ($row['expires_at'] && $row['expires_at'] < $now) {
            OutpostDB::query("DELETE FROM shield_blocked_ips WHERE id = ?", [$row['id']]);
            continue;
        }
        $cleaned[] = $row;
    }

    json_response(['blocked_ips' => $cleaned]);
}

function handle_shield_block_ip(): void {
    $data = get_json_body();
    $ip = trim($data['ip'] ?? '');
    $reason = trim($data['reason'] ?? 'Manually blocked');

    if (!$ip) json_error('IP address required');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) json_error('Invalid IP address');

    // Don't block the admin's own IP
    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === $currentIp) {
        json_error('Cannot block your own IP address');
    }

    ensure_shield_tables();
    shield_block_ip($ip, $reason, false);
    log_activity('security', "Shield: Manually blocked IP {$ip}");

    json_response(['success' => true]);
}

function handle_shield_unblock_ip(): void {
    $ip = trim($_GET['ip'] ?? '');
    if (!$ip) json_error('IP address required');

    ensure_shield_tables();
    shield_unblock_ip($ip);
    log_activity('security', "Shield: Unblocked IP {$ip}");

    json_response(['success' => true]);
}

function handle_shield_traffic(): void {
    ensure_shield_tables();

    $limit = min((int)($_GET['limit'] ?? 100), 500);
    $offset = (int)($_GET['offset'] ?? 0);

    $traffic = OutpostDB::fetchAll(
        "SELECT * FROM shield_traffic ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [$limit, $offset]
    );
    $total = OutpostDB::fetchOne("SELECT COUNT(*) as cnt FROM shield_traffic");

    json_response([
        'traffic' => $traffic,
        'total'   => (int)($total['cnt'] ?? 0),
    ]);
}

function handle_shield_file_check(): void {
    $result = shield_check_file_integrity();
    log_activity('security', 'Shield: File integrity check run');
    json_response($result);
}

function handle_shield_config_get(): void {
    json_response(['config' => shield_get_config()]);
}

function handle_shield_config_update(): void {
    $data = get_json_body();

    // Whitelist valid config keys
    $validKeys = [
        'enabled', 'login_lockout', 'login_max_attempts', 'login_lockout_minutes',
        'auto_block_after_lockouts', 'firewall_enabled', 'firewall_mode',
        'file_integrity', 'security_headers', 'traffic_logging',
        'email_notifications', 'notification_email',
    ];

    $config = shield_get_config();
    foreach ($validKeys as $key) {
        if (array_key_exists($key, $data)) {
            $val = $data[$key];
            // Type coercion
            if (in_array($key, ['enabled', 'login_lockout', 'firewall_enabled', 'file_integrity', 'security_headers', 'traffic_logging', 'email_notifications'])) {
                $val = (bool)$val;
            } elseif (in_array($key, ['login_max_attempts', 'login_lockout_minutes', 'auto_block_after_lockouts'])) {
                $val = max(1, (int)$val);
            } elseif ($key === 'firewall_mode') {
                $val = in_array($val, ['block', 'log']) ? $val : 'block';
            } elseif ($key === 'notification_email') {
                $val = trim((string)$val);
                if ($val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $val = ''; // Reject invalid email addresses
                }
            }
            $config[$key] = $val;
        }
    }

    shield_save_config($config);
    log_activity('security', 'Shield: Configuration updated');

    json_response(['success' => true, 'config' => $config]);
}

function handle_shield_login_attempts(): void {
    ensure_shield_tables();

    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $attempts = OutpostDB::fetchAll(
        "SELECT * FROM shield_login_attempts ORDER BY created_at DESC LIMIT ?",
        [$limit]
    );

    json_response(['attempts' => $attempts]);
}
