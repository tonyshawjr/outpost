<?php
/**
 * Outpost CMS — Newsletter (subscribers + double opt-in + Resend sending)
 *
 * Recipients are the union of confirmed standalone subscribers and Lodge
 * members (member-role users) who opted in. Sending goes through Resend.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ranger.php';

function ensure_newsletter_tables(): void {
    $db = OutpostDB::connect();
    $db->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'pending',
        confirm_token TEXT,
        unsub_token TEXT NOT NULL,
        source TEXT DEFAULT 'form',
        created_at TEXT DEFAULT (datetime('now')),
        confirmed_at TEXT,
        unsubscribed_at TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_subscribers_status ON subscribers(status)");
    $db->exec("CREATE TABLE IF NOT EXISTS subscribe_rate_limits (
        ip TEXT PRIMARY KEY,
        timestamps TEXT DEFAULT '[]',
        updated_at INTEGER
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS newsletter_sends (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subject TEXT NOT NULL,
        html TEXT NOT NULL,
        recipient_count INTEGER DEFAULT 0,
        sent_count INTEGER DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TEXT DEFAULT (datetime('now')),
        sent_at TEXT
    )");

    $cols = $db->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_ASSOC);
    if (!in_array('newsletter_optin', array_column($cols, 'name'), true)) {
        $db->exec("ALTER TABLE users ADD COLUMN newsletter_optin INTEGER DEFAULT 0");
    }
}

function newsletter_token(): string {
    return bin2hex(random_bytes(20));
}

function newsletter_valid_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254;
}

function newsletter_config(): array {
    $get = function ($k) {
        $r = OutpostDB::fetchOne('SELECT value FROM settings WHERE key = ?', [$k]);
        return $r['value'] ?? '';
    };
    $keyEnc = $get('resend_api_key');
    $key = '';
    if ($keyEnc !== '') {
        try { $key = ranger_decrypt($keyEnc); } catch (\Throwable) { $key = $keyEnc; }
    }
    return [
        'apikey' => $key,
        'from' => $get('newsletter_from'),
        'reply_to' => $get('newsletter_reply_to'),
    ];
}

function newsletter_site_url(): string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'site_url'");
    $url = trim($row['value'] ?? '');
    return $url !== '' ? rtrim($url, '/') : '';
}

// ── Subscriber lifecycle (double opt-in) ─────────────────

function newsletter_subscribe(string $email, string $source = 'form'): array {
    ensure_newsletter_tables();
    $email = strtolower(trim($email));
    if (!newsletter_valid_email($email)) return ['error' => 'invalid_email'];

    $existing = OutpostDB::fetchOne('SELECT id, status, created_at FROM subscribers WHERE email = ?', [$email]);
    if ($existing && $existing['status'] === 'confirmed') {
        return ['status' => 'already_confirmed'];
    }

    if ($existing && $existing['status'] === 'pending' && !empty($existing['created_at'])
        && strtotime($existing['created_at'] . ' UTC') > time() - 600) {
        return ['status' => 'pending'];
    }

    $confirm = newsletter_token();
    if ($existing) {
        OutpostDB::update('subscribers',
            ['status' => 'pending', 'confirm_token' => $confirm, 'unsubscribed_at' => null, 'created_at' => gmdate('Y-m-d H:i:s')],
            'id = ?', [$existing['id']]);
    } else {
        OutpostDB::insert('subscribers', [
            'email' => $email,
            'status' => 'pending',
            'confirm_token' => $confirm,
            'unsub_token' => newsletter_token(),
            'source' => preg_replace('/[^a-z0-9_-]/', '', $source) ?: 'form',
        ]);
    }

    $sub = OutpostDB::fetchOne('SELECT * FROM subscribers WHERE email = ?', [$email]);
    return ['status' => 'pending', 'subscriber' => $sub];
}

function newsletter_confirm(string $token): bool {
    ensure_newsletter_tables();
    if ($token === '' || !ctype_xdigit($token)) return false;
    $sub = OutpostDB::fetchOne('SELECT id FROM subscribers WHERE confirm_token = ? AND status = ?', [$token, 'pending']);
    if (!$sub) return false;
    OutpostDB::update('subscribers',
        ['status' => 'confirmed', 'confirm_token' => null, 'confirmed_at' => date('Y-m-d H:i:s')],
        'id = ?', [$sub['id']]);
    return true;
}

function newsletter_unsubscribe(string $token): bool {
    ensure_newsletter_tables();
    if ($token === '' || !ctype_xdigit($token)) return false;
    $sub = OutpostDB::fetchOne('SELECT id FROM subscribers WHERE unsub_token = ?', [$token]);
    if ($sub) {
        OutpostDB::update('subscribers',
            ['status' => 'unsubscribed', 'unsubscribed_at' => date('Y-m-d H:i:s')],
            'id = ?', [$sub['id']]);
        return true;
    }
    return false;
}

/** Confirmed standalone subscribers + opted-in member-role users. */
function newsletter_recipients(): array {
    ensure_newsletter_tables();
    $rows = OutpostDB::fetchAll("SELECT email, unsub_token FROM subscribers WHERE status = 'confirmed'");
    $out = [];
    foreach ($rows as $r) $out[strtolower($r['email'])] = ['email' => $r['email'], 'unsub_token' => $r['unsub_token']];

    if (!function_exists('outpost_is_internal_role')) require_once __DIR__ . '/roles.php';
    $members = OutpostDB::fetchAll("SELECT email, role FROM users WHERE newsletter_optin = 1 AND email != ''");
    foreach ($members as $m) {
        if (outpost_is_internal_role($m['role'])) continue;
        $key = strtolower($m['email']);
        if (isset($out[$key])) continue;
        $out[$key] = ['email' => $m['email'], 'unsub_token' => ''];
    }
    return array_values($out);
}

// ── Resend sending ───────────────────────────────────────

function newsletter_from(array $cfg): string {
    return $cfg['from'] !== '' ? $cfg['from'] : 'Outpost <onboarding@resend.dev>';
}

function newsletter_resend_post(string $path, $body, string $apikey, string $idempotency = ''): array {
    $headers = [
        'Authorization: Bearer ' . $apikey,
        'Content-Type: application/json',
        'User-Agent: Outpost-CMS',
    ];
    if ($idempotency !== '') $headers[] = 'Idempotency-Key: ' . $idempotency;
    $ch = curl_init('https://api.resend.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, is_string($resp) ? $resp : ''];
}

function newsletter_send_one(string $to, string $subject, string $html, array $extraHeaders = []): array {
    $cfg = newsletter_config();
    if ($cfg['apikey'] === '') return ['error' => 'no_key'];
    $body = ['from' => newsletter_from($cfg), 'to' => $to, 'subject' => $subject, 'html' => $html];
    if ($cfg['reply_to'] !== '') $body['reply_to'] = $cfg['reply_to'];
    if ($extraHeaders) $body['headers'] = $extraHeaders;
    [$code, $resp] = newsletter_resend_post('/emails', $body, $cfg['apikey']);
    if ($code === 429) return ['error' => 'rate_limited'];
    if ($code < 200 || $code >= 300) return ['error' => 'send_failed', 'status' => $code];
    return ['id' => json_decode($resp, true)['id'] ?? ''];
}

function newsletter_email_layout(string $title, string $bodyHtml, string $unsubUrl): string {
    $t = htmlspecialchars($title, ENT_QUOTES);
    $u = htmlspecialchars($unsubUrl, ENT_QUOTES);
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\"></head>"
        . "<body style=\"margin:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b\">"
        . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr><td align=\"center\" style=\"padding:24px 12px\">"
        . "<table role=\"presentation\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:600px;background:#fff;border-radius:12px;overflow:hidden\">"
        . "<tr><td style=\"padding:32px 32px 8px\"><h1 style=\"margin:0;font-size:22px\">{$t}</h1></td></tr>"
        . "<tr><td style=\"padding:8px 32px 24px;font-size:15px;line-height:1.6\">{$bodyHtml}</td></tr>"
        . "<tr><td style=\"padding:16px 32px 28px;border-top:1px solid #efeff1;font-size:12px;color:#71717a\">"
        . "You're receiving this because you subscribed. <a href=\"{$u}\" style=\"color:#71717a\">Unsubscribe</a>."
        . "</td></tr></table></td></tr></table></body></html>";
}

function newsletter_action_url(string $action, string $token): string {
    $base = newsletter_site_url();
    return $base . '/outpost/api.php?action=' . $action . '&token=' . urlencode($token);
}

function newsletter_send_confirmation(array $subscriber): array {
    $confirmUrl = newsletter_action_url('newsletter/confirm', (string) $subscriber['confirm_token']);
    $u = htmlspecialchars($confirmUrl, ENT_QUOTES);
    $body = "<p>Thanks for subscribing! Please confirm your email to start receiving updates.</p>"
        . "<p style=\"margin:24px 0\"><a href=\"{$u}\" style=\"display:inline-block;background:#6d5efc;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600\">Confirm subscription</a></p>"
        . "<p style=\"font-size:13px;color:#71717a\">If you didn't request this, you can ignore this email.</p>";
    $html = newsletter_email_layout('Confirm your subscription', $body, newsletter_action_url('newsletter/unsubscribe', (string) $subscriber['unsub_token']));
    return newsletter_send_one((string) $subscriber['email'], 'Confirm your subscription', $html);
}

function newsletter_broadcast(string $subject, string $contentHtml): array {
    $cfg = newsletter_config();
    if ($cfg['apikey'] === '') return ['error' => 'no_key'];
    $recipients = newsletter_recipients();
    if (empty($recipients)) return ['error' => 'no_recipients'];

    ensure_newsletter_tables();
    $sendId = OutpostDB::insert('newsletter_sends', [
        'subject' => mb_substr($subject, 0, 300),
        'html' => $contentHtml,
        'recipient_count' => count($recipients),
        'status' => 'sending',
    ]);

    $from = newsletter_from($cfg);
    $sent = 0;
    $chunks = array_chunk($recipients, 100);
    foreach ($chunks as $ci => $chunk) {
        $batch = [];
        foreach ($chunk as $r) {
            $unsubUrl = $r['unsub_token'] !== '' ? newsletter_action_url('newsletter/unsubscribe', $r['unsub_token']) : (newsletter_site_url() . '/outpost/api.php?action=newsletter/unsubscribe');
            $email = [
                'from' => $from,
                'to' => $r['email'],
                'subject' => $subject,
                'html' => newsletter_email_layout($subject, $contentHtml, $unsubUrl),
                'headers' => [
                    'List-Unsubscribe' => '<' . $unsubUrl . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
            ];
            if ($cfg['reply_to'] !== '') $email['reply_to'] = $cfg['reply_to'];
            $batch[] = $email;
        }
        [$code, $resp] = newsletter_resend_post('/emails/batch', $batch, $cfg['apikey'], 'nl-' . $sendId . '-' . $ci);
        if ($code === 429) {
            OutpostDB::update('newsletter_sends', ['status' => 'rate_limited', 'sent_count' => $sent], 'id = ?', [$sendId]);
            return ['error' => 'rate_limited', 'sent' => $sent];
        }
        if ($code >= 200 && $code < 300) {
            $data = json_decode($resp, true);
            $sent += is_array($data['data'] ?? null) ? count($data['data']) : count($batch);
        }
    }

    OutpostDB::update('newsletter_sends', ['status' => 'sent', 'sent_count' => $sent, 'sent_at' => date('Y-m-d H:i:s')], 'id = ?', [$sendId]);
    return ['sent' => $sent, 'recipients' => count($recipients), 'send_id' => (int) $sendId];
}

function newsletter_ip_rate_limited(int $max = 10, int $window = 3600): bool {
    ensure_newsletter_tables();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();
    $cutoff = $now - $window;
    OutpostDB::query('DELETE FROM subscribe_rate_limits WHERE updated_at < ?', [$cutoff]);
    $row = OutpostDB::fetchOne('SELECT timestamps FROM subscribe_rate_limits WHERE ip = ?', [$ip]);
    $ts = $row ? json_decode($row['timestamps'], true) : [];
    $ts = array_values(array_filter(is_array($ts) ? $ts : [], fn($t) => $t > $cutoff));
    if (count($ts) >= $max) return true;
    $ts[] = $now;
    OutpostDB::query(
        "INSERT INTO subscribe_rate_limits (ip, timestamps, updated_at) VALUES (?, ?, ?)
         ON CONFLICT(ip) DO UPDATE SET timestamps = excluded.timestamps, updated_at = excluded.updated_at",
        [$ip, json_encode($ts), $now]
    );
    return false;
}

// ── Handlers ─────────────────────────────────────────────

function newsletter_html_page(string $title, string $message): void {
    $t = htmlspecialchars($title, ENT_QUOTES);
    $m = htmlspecialchars($message, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$t}</title>"
        . "<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#18181b}"
        . ".card{max-width:440px;padding:40px;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.08);text-align:center}"
        . "h1{font-size:20px;margin:0 0 10px}p{color:#52525b;line-height:1.5;margin:0}</style></head>"
        . "<body><div class=\"card\"><h1>{$t}</h1><p>{$m}</p></div></body></html>";
}

function handle_newsletter_subscribe_public(): void {
    $body = get_json_body();
    $email = is_string($body['email'] ?? null) ? $body['email'] : (string) ($_POST['email'] ?? '');
    $source = is_string($body['source'] ?? null) ? $body['source'] : 'form';
    if (newsletter_ip_rate_limited()) {
        json_response(['status' => 'pending', 'message' => 'Almost there — check your email to confirm.']);
    }
    $result = newsletter_subscribe($email, $source);
    if (isset($result['error'])) {
        json_error('Please enter a valid email address.', 400);
    }
    if ($result['status'] === 'already_confirmed') {
        json_response(['status' => 'already_subscribed', 'message' => "You're already subscribed."]);
    }
    if (!empty($result['subscriber'])) {
        newsletter_send_confirmation($result['subscriber']);
    }
    json_response(['status' => 'pending', 'message' => 'Almost there — check your email to confirm.']);
}

function handle_newsletter_confirm_public(): void {
    $token = (string) ($_GET['token'] ?? '');
    if (newsletter_confirm($token)) {
        newsletter_html_page('Subscription confirmed', "You're all set — thanks for subscribing.");
    } else {
        newsletter_html_page('Link expired', 'This confirmation link is invalid or has already been used.');
    }
}

function handle_newsletter_unsubscribe_public(): void {
    $token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
    $ok = newsletter_unsubscribe($token);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(200);
        echo '';
        return;
    }
    if ($ok) {
        newsletter_html_page('Unsubscribed', "You've been removed from the list and won't receive further emails.");
    } else {
        newsletter_html_page('Link not found', 'This unsubscribe link is invalid.');
    }
}

function handle_newsletter_settings_get(): void {
    outpost_require_cap('settings.*');
    $cfg = newsletter_config();
    json_response(['settings' => [
        'resend_api_key' => $cfg['apikey'],
        'newsletter_from' => $cfg['from'],
        'newsletter_reply_to' => $cfg['reply_to'],
    ]]);
}

function handle_newsletter_settings_update(): void {
    outpost_require_cap('settings.*');
    $data = get_json_body();
    if (array_key_exists('resend_api_key', $data)) {
        $val = trim((string) $data['resend_api_key']);
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['resend_api_key', $val === '' ? '' : ranger_encrypt($val)]);
    }
    if (array_key_exists('newsletter_from', $data)) {
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['newsletter_from', trim((string) $data['newsletter_from'])]);
    }
    if (array_key_exists('newsletter_reply_to', $data)) {
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['newsletter_reply_to', trim((string) $data['newsletter_reply_to'])]);
    }
    log_activity('system', 'Newsletter settings updated');
    json_response(['success' => true]);
}

function handle_newsletter_subscribers(): void {
    outpost_require_cap('settings.*');
    ensure_newsletter_tables();
    $rows = OutpostDB::fetchAll("SELECT id, email, status, source, created_at, confirmed_at FROM subscribers ORDER BY created_at DESC LIMIT 500");
    $counts = ['confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0];
    foreach (OutpostDB::fetchAll("SELECT status, COUNT(*) c FROM subscribers GROUP BY status") as $r) {
        $counts[$r['status']] = (int) $r['c'];
    }
    $members = OutpostDB::fetchOne("SELECT COUNT(*) c FROM users WHERE newsletter_optin = 1");
    json_response([
        'subscribers' => $rows,
        'counts' => $counts,
        'member_optins' => (int) ($members['c'] ?? 0),
        'total_recipients' => count(newsletter_recipients()),
    ]);
}

function handle_newsletter_send(): void {
    outpost_require_cap('content.*');
    $data = get_json_body();
    $subject = trim((string) ($data['subject'] ?? ''));
    $html = (string) ($data['html'] ?? '');
    $test = !empty($data['test']);
    if ($subject === '' || trim($html) === '') json_error('Subject and content are required.', 400);

    $cfg = newsletter_config();
    if ($cfg['apikey'] === '') json_error('Connect Resend in Settings → Integrations first.', 400);

    if ($test) {
        $to = is_string($data['test_email'] ?? null) && newsletter_valid_email($data['test_email']) ? $data['test_email'] : '';
        if ($to === '') json_error('Enter a valid test email address.', 400);
        $unsub = newsletter_site_url() . '/outpost/api.php?action=newsletter/unsubscribe';
        $res = newsletter_send_one($to, '[Test] ' . $subject, newsletter_email_layout($subject, $html, $unsub));
        if (isset($res['error'])) json_error($res['error'] === 'no_key' ? 'No Resend key configured.' : 'Test send failed.', 502);
        json_response(['status' => 'test_sent', 'to' => $to]);
    }

    $res = newsletter_broadcast($subject, $html);
    if (isset($res['error'])) {
        if ($res['error'] === 'no_recipients') json_error('No confirmed subscribers to send to yet.', 400);
        if ($res['error'] === 'rate_limited') json_error('Resend rate limit hit — some emails may not have sent.', 429);
        json_error('Send failed.', 502);
    }
    json_response(['status' => 'sent', 'sent' => $res['sent'], 'recipients' => $res['recipients']]);
}
