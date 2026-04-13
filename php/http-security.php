<?php
/**
 * Outpost CMS — HTTP Security Helpers
 *
 * Shared guards for outbound HTTP requests (SSRF prevention).
 */

/**
 * Validate a URL is safe for server-side requests.
 * Blocks private/internal IPs, dangerous protocols, and cloud metadata endpoints.
 * Returns the resolved IP address so callers can pin it with CURLOPT_RESOLVE
 * to prevent DNS rebinding (TOCTOU between validation and connection).
 *
 * @return string Resolved IP address — callers MUST use this with CURLOPT_RESOLVE
 * @throws RuntimeException on unsafe URLs
 */
function outpost_ssrf_guard(string $url): string {
    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? '');

    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new \RuntimeException("Disallowed URL scheme: {$scheme}");
    }

    $host = strtolower($parsed['host'] ?? '');
    if ($host === '') {
        throw new \RuntimeException('No host in URL');
    }

    // Resolve hostname to IP
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new \RuntimeException("Cannot resolve host: {$host}");
    }

    // Block private, reserved, and loopback ranges
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw new \RuntimeException('Requests to private/internal addresses are not allowed');
    }

    // Block cloud metadata endpoints (defense in depth)
    $blockedHosts = ['169.254.169.254', 'metadata.google.internal'];
    if (in_array($host, $blockedHosts, true) || in_array($ip, $blockedHosts, true)) {
        throw new \RuntimeException('Requests to metadata endpoints are not allowed');
    }

    return $ip;
}

/**
 * IP-based rate limiter for public endpoints.
 * Exits with HTTP 429 if the limit is exceeded.
 */
function outpost_ip_rate_limit(string $bucket, int $maxAttempts, int $windowSeconds): void {
    $ip = ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . ':' . $bucket;
    $now = time();
    $window = $now - $windowSeconds;

    $db = OutpostDB::connect();
    $db->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        ip TEXT PRIMARY KEY, attempts TEXT DEFAULT '[]', updated_at INTEGER
    )");

    // Wrap check-and-update in a transaction to prevent TOCTOU race
    $db->exec('BEGIN IMMEDIATE');
    try {
        OutpostDB::query('DELETE FROM login_rate_limits WHERE updated_at < ?', [$window]);

        $row = OutpostDB::fetchOne('SELECT attempts FROM login_rate_limits WHERE ip = ?', [$ip]);
        $timestamps = $row ? json_decode($row['attempts'], true) : [];
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $window));

        if (count($timestamps) >= $maxAttempts) {
            $db->exec('COMMIT');
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
            echo json_encode(['error' => 'Too many requests. Please wait.']);
            exit;
        }

        $timestamps[] = $now;
        OutpostDB::query(
            "INSERT INTO login_rate_limits (ip, attempts, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(ip) DO UPDATE SET attempts = excluded.attempts, updated_at = excluded.updated_at",
            [$ip, json_encode($timestamps), $now]
        );
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        // On DB lock or failure, allow the request through rather than blocking users
    }
}
