<?php
/**
 * Outpost CMS — TOTP Two-Factor Authentication
 * Pure PHP implementation of RFC 6238 (TOTP) with backup codes and signed tokens.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class OutpostTOTP {

    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const CODE_DIGITS = 6;
    private const TIME_STEP = 30;
    private const TOKEN_TTL = 300; // 5 minutes
    private const BACKUP_CODE_CHARS = 'abcdefghjkmnpqrstuvwxyz23456789'; // no ambiguous chars

    // ── Base32 ──────────────────────────────────────────────

    public static function base32Encode(string $data): string {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::BASE32_CHARS[bindec($chunk)];
        }

        return $result;
    }

    public static function base32Decode(string $encoded): string {
        $encoded = strtoupper(rtrim($encoded, '='));
        $binary = '';
        foreach (str_split($encoded) as $char) {
            $pos = strpos(self::BASE32_CHARS, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $bytes = str_split($binary, 8);
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }

        return $result;
    }

    // ── TOTP Core ───────────────────────────────────────────

    public static function generateSecret(): string {
        return self::base32Encode(random_bytes(20));
    }

    public static function generateCode(string $secret, ?int $timestamp = null): string {
        $time = $timestamp ?? time();
        $counter = intdiv($time, self::TIME_STEP);

        $counterBytes = pack('J', $counter); // 64-bit big-endian
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::CODE_DIGITS);

        return str_pad((string)$code, self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    public static function verifyCode(string $secret, string $code): bool {
        $code = trim($code);
        if (strlen($code) !== self::CODE_DIGITS || !ctype_digit($code)) {
            return false;
        }

        $now = time();
        // Check ±1 window (current, previous, next 30s period)
        for ($offset = -1; $offset <= 1; $offset++) {
            $expected = self::generateCode($secret, $now + ($offset * self::TIME_STEP));
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    public static function buildUri(string $secret, string $username, string $issuer = 'Outpost CMS'): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($username);
        return 'otpauth://totp/' . $label
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1'
            . '&digits=' . self::CODE_DIGITS
            . '&period=' . self::TIME_STEP;
    }

    // ── Backup Codes ────────────────────────────────────────

    public static function generateBackupCodes(): array {
        $codes = [];
        $chars = self::BACKUP_CODE_CHARS;
        $len = strlen($chars);
        for ($i = 0; $i < 8; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $chars[random_int(0, $len - 1)];
            }
            $codes[] = $code;
        }
        return $codes;
    }

    public static function hashBackupCodes(array $codes): string {
        $hashed = [];
        foreach ($codes as $code) {
            $hashed[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
        }
        return json_encode($hashed);
    }

    /**
     * Verify a backup code against hashed codes.
     * Returns the index of the matching code, or -1 if none match.
     */
    public static function verifyBackupCode(string $input, string $hashedJson): int {
        $input = strtolower(trim($input));
        $hashes = json_decode($hashedJson, true);
        if (!is_array($hashes)) return -1;

        foreach ($hashes as $i => $hash) {
            if (password_verify($input, $hash)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Remove a used backup code by index.
     * Returns the updated JSON string.
     */
    public static function consumeBackupCode(string $hashedJson, int $index): string {
        $hashes = json_decode($hashedJson, true);
        if (!is_array($hashes)) return '[]';

        array_splice($hashes, $index, 1);
        return json_encode(array_values($hashes));
    }

    // ── Signed TOTP Tokens ──────────────────────────────────
    // Temporary token proving the user passed password auth but still needs TOTP.

    private static function getSigningKey(): string {
        $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'totp_signing_key'");
        if ($row && !empty($row['value'])) {
            return $row['value'];
        }

        $key = bin2hex(random_bytes(32));
        OutpostDB::query(
            "INSERT INTO settings (key, value) VALUES ('totp_signing_key', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$key]
        );
        return $key;
    }

    /**
     * Create a signed token encoding userId + expiry.
     */
    public static function createTotpToken(int $userId): string {
        $expires = time() + self::TOKEN_TTL;
        $nonce = bin2hex(random_bytes(8));

        // Store nonce in DB for single-use verification
        OutpostDB::update('users', ['totp_token_nonce' => $nonce], 'id = ?', [$userId]);

        $payload = $userId . '.' . $expires . '.' . $nonce;
        $sig = hash_hmac('sha256', $payload, self::getSigningKey());
        return base64_encode($payload . '.' . $sig);
    }

    /**
     * Verify a signed TOTP token. Returns userId or null.
     */
    public static function verifyTotpToken(string $token): ?int {
        $decoded = base64_decode($token, true);
        if (!$decoded) return null;

        $parts = explode('.', $decoded);
        if (count($parts) !== 4) return null;

        [$userId, $expires, $nonce, $sig] = $parts;
        $payload = $userId . '.' . $expires . '.' . $nonce;
        $expectedSig = hash_hmac('sha256', $payload, self::getSigningKey());

        if (!hash_equals($expectedSig, $sig)) return null;
        if ((int)$expires < time()) return null;

        return (int)$userId;
    }

    /**
     * Extract the nonce from a TOTP token (for single-use verification).
     */
    public static function extractNonce(string $token): ?string {
        $decoded = base64_decode($token, true);
        if (!$decoded) return null;

        $parts = explode('.', $decoded);
        if (count($parts) !== 4) return null;

        return $parts[2]; // nonce is the third element
    }
}
