<?php
/**
 * Outpost CMS — Lightweight JWT (HS256)
 *
 * Pure-PHP JSON Web Token implementation — no external libraries.
 * Used for stateless bearer-token auth for mobile apps and headless clients.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Get the JWT signing secret.
 * Uses a random 256-bit secret stored in the settings table.
 * Falls back to the old deterministic method if the DB is unavailable.
 */
function outpost_jwt_secret(): string {
    try {
        $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'jwt_secret'");

        if ($row && !empty($row['value']) && preg_match('/^[0-9a-f]{64}$/i', $row['value'])) {
            return hex2bin($row['value']);
        }

        // Generate and persist a new random secret
        $secret = random_bytes(32);
        $hex = bin2hex($secret);
        OutpostDB::query(
            "INSERT INTO settings (key, value) VALUES ('jwt_secret', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$hex]
        );

        return $secret;
    } catch (\Throwable $e) {
        // DB unavailable — fall back to deterministic derivation
        return hash('sha256', OUTPOST_DB_PATH . '::outpost-jwt-v1::' . (__DIR__), true);
    }
}

/**
 * Base64url-encode (RFC 7515).
 */
function outpost_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64url-decode (RFC 7515).
 */
function outpost_base64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Create a signed JWT.
 *
 * @param array  $payload  Claims — must include 'sub' and 'type'
 * @param string $secret   HMAC key (binary)
 * @return string  The compact JWT string (header.payload.signature)
 */
function outpost_jwt_encode(array $payload, string $secret): string {
    $header = outpost_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    // Set standard claims if not already present
    if (!isset($payload['iat'])) {
        $payload['iat'] = time();
    }
    if (!isset($payload['exp'])) {
        // Default: 30 days for members, 24 hours for admin
        $ttl = ($payload['type'] ?? 'member') === 'admin' ? 86400 : (86400 * 30);
        $payload['exp'] = time() + $ttl;
    }

    $body = outpost_base64url_encode(json_encode($payload));
    $signature = outpost_base64url_encode(
        hash_hmac('sha256', "{$header}.{$body}", $secret, true)
    );

    return "{$header}.{$body}.{$signature}";
}

/**
 * Validate and decode a JWT.
 *
 * @param string $token   The compact JWT string
 * @param string $secret  HMAC key (binary)
 * @return array|null  Decoded payload, or null if invalid/expired
 */
function outpost_jwt_decode(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $body, $signature] = $parts;

    // Verify signature
    $expected = outpost_base64url_encode(
        hash_hmac('sha256', "{$header}.{$body}", $secret, true)
    );

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    // Decode header and verify algorithm
    $headerData = json_decode(outpost_base64url_decode($header), true);
    if (!$headerData || ($headerData['alg'] ?? '') !== 'HS256') {
        return null;
    }

    // Decode payload
    $payload = json_decode(outpost_base64url_decode($body), true);
    if (!is_array($payload)) {
        return null;
    }

    // Check expiry
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    // Require subject
    if (empty($payload['sub'])) {
        return null;
    }

    return $payload;
}
