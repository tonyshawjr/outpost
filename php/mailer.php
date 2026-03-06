<?php
/**
 * Outpost CMS — Mailer
 *
 * Minimal SMTP implementation. No external dependencies.
 * Falls back to PHP mail() if SMTP is not configured.
 *
 * Supports:
 *   - Port 465 + SSL (implicit TLS)
 *   - Port 587 + STARTTLS
 *   - Port 25 / 2525 (plain, no encryption)
 *   - AUTH LOGIN
 *   - Multipart text/HTML emails
 */

class OutpostMailer {

    private string $host;
    private int    $port;
    private string $encryption; // 'tls' | 'ssl' | 'none'
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct(array $settings) {
        $this->host       = trim($settings['smtp_host']       ?? '');
        $this->port       = (int)($settings['smtp_port']      ?? 587);
        $this->encryption = strtolower(trim($settings['smtp_encryption'] ?? 'tls'));
        $this->username   = $settings['smtp_username']        ?? '';
        $this->password   = $settings['smtp_password']        ?? '';
        $this->fromEmail  = trim($settings['from_email']      ?? '');
        $this->fromName   = trim($settings['from_name']       ?? 'Outpost CMS');
    }

    /**
     * Load config from DB settings and return a ready instance.
     */
    public static function fromSettings(): self {
        $rows = OutpostDB::fetchAll('SELECT key, value FROM settings');
        $s = [];
        foreach ($rows as $r) {
            $s[$r['key']] = $r['value'];
        }
        return new self($s);
    }

    /**
     * Send an email.
     *
     * @throws RuntimeException on SMTP failure
     */
    public function send(string $toEmail, string $subject, string $text, string $html = ''): void {
        // Validate email and strip any CRLF to prevent header injection
        $toEmail = str_replace(["\r", "\n"], '', $toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient email address');
        }

        if ($this->host && $this->fromEmail) {
            $this->sendSmtp($toEmail, $subject, $text, $html);
        } else {
            $this->sendFallback($toEmail, $subject, $text);
        }
    }

    // ── SMTP ──────────────────────────────────────────────

    private function sendSmtp(string $to, string $subject, string $text, string $html): void {
        $addr = $this->encryption === 'ssl'
            ? 'ssl://' . $this->host
            : $this->host;

        $errno = 0; $errstr = '';
        $sock = @fsockopen($addr, $this->port, $errno, $errstr, 15);
        if (!$sock) {
            throw new RuntimeException("SMTP connect to {$this->host}:{$this->port} failed: {$errstr}");
        }
        stream_set_timeout($sock, 15);

        try {
            $this->expect($sock, 220);                                       // Banner
            $this->send_cmd($sock, 'EHLO ' . $this->localHost(), 250);      // EHLO

            if ($this->encryption === 'tls') {
                $this->send_cmd($sock, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                $this->send_cmd($sock, 'EHLO ' . $this->localHost(), 250);  // Re-EHLO after TLS
            }

            if ($this->username) {
                $this->send_cmd($sock, 'AUTH LOGIN', 334);
                $this->send_cmd($sock, base64_encode($this->username), 334);
                $this->send_cmd($sock, base64_encode($this->password), 235);
            }

            $this->send_cmd($sock, "MAIL FROM:<{$this->fromEmail}>", 250);
            $this->send_cmd($sock, "RCPT TO:<{$to}>",                250);
            $this->send_cmd($sock, 'DATA',                            354);

            fwrite($sock, $this->buildMessage($to, $subject, $text, $html) . "\r\n.\r\n");
            $this->expect($sock, 250);

            $this->send_cmd($sock, 'QUIT', 221);
        } finally {
            fclose($sock);
        }
    }

    private function buildMessage(string $to, string $subject, string $text, string $html): string {
        $boundary = 'OutpostBoundary_' . bin2hex(random_bytes(8));
        $from = $this->fromName
            ? '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromEmail . '>'
            : $this->fromEmail;

        $hdr  = "From: {$from}\r\n";
        $hdr .= "To: {$to}\r\n";
        $hdr .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $hdr .= "Date: " . date('r') . "\r\n";
        $hdr .= "MIME-Version: 1.0\r\n";
        $hdr .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@outpost>\r\n";

        if ($html) {
            $hdr .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $hdr .= "--{$boundary}\r\n";
            $hdr .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $hdr .= $this->escapeDot($text) . "\r\n";
            $hdr .= "--{$boundary}\r\n";
            $hdr .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $hdr .= $this->escapeDot($html) . "\r\n";
            $hdr .= "--{$boundary}--";
        } else {
            $hdr .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $hdr .= $this->escapeDot($text);
        }

        return $hdr;
    }

    /** RFC 5321 §4.5.2 — escape leading dots in DATA body */
    private function escapeDot(string $s): string {
        return preg_replace('/^\.$/m', '..', $s);
    }

    private function send_cmd($sock, string $cmd, int $expect): string {
        fwrite($sock, $cmd . "\r\n");
        return $this->expect($sock, $expect);
    }

    private function expect($sock, int $code): string {
        $response = '';
        while (!feof($sock) && ($line = fgets($sock, 512))) {
            $response .= $line;
            // Multi-line: "250-…" continues, "250 …" ends
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $actual = (int)substr($response, 0, 3);
        if ($actual !== $code) {
            throw new RuntimeException("SMTP expected {$code}, got {$actual}: " . trim($response));
        }
        return $response;
    }

    private function localHost(): string {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    // ── Fallback: PHP mail() ──────────────────────────────

    private function sendFallback(string $to, string $subject, string $text): void {
        $from = $this->fromEmail ?: ('noreply@' . $this->localHost());
        $name = $this->fromName ?: 'Outpost CMS';
        $headers  = "From: {$name} <{$from}>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        @mail($to, $subject, $text, $headers);
    }
}
