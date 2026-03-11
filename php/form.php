<?php
/**
 * Outpost CMS — Universal Form Handler
 *
 * Catches POST submissions from any HTML form, rate-limits by IP,
 * stores in DB, and emails the configured notification address.
 *
 * Required hidden field:
 *   <input type="hidden" name="_form" value="contact">
 *
 * Optional hidden fields:
 *   <input type="hidden" name="_redirect" value="/thanks">
 *   <input type="hidden" name="_notify"   value="custom@email.com">
 *
 * reCAPTCHA (if configured in Settings):
 *   Add reCAPTCHA v2 widget to form; this handler verifies server-side.
 *
 * Spam protection:
 *   IP rate limiting — max 5 submissions per IP per 60 seconds.
 *
 * Example form:
 *   <form action="/outpost/form.php" method="post">
 *     <input type="hidden" name="_form" value="contact">
 *     <input type="hidden" name="_redirect" value="/thanks">
 *     <input name="name" type="text" placeholder="Your name" required>
 *     <input name="email" type="email" placeholder="Email" required>
 *     <textarea name="message" placeholder="Message"></textarea>
 *     <button type="submit">Send</button>
 *   </form>
 */

// Only handle POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

// ── Read submission ───────────────────────────────────────

$formName = trim($_POST['_form']     ?? '');
$redirect = trim($_POST['_redirect'] ?? '');
// _notify from POST is intentionally ignored — only admin-configured addresses are used
// This prevents email injection via public form submissions

$formId   = trim($_POST['_form_id']  ?? '');

// Strip internal fields — everything else is submission data
$reserved = ['_form', '_redirect', '_notify', '_form_id', 'g-recaptcha-response'];
// Also strip honeypot fields
foreach (array_keys($_POST) as $key) {
    if (str_starts_with($key, '_hp_')) $reserved[] = $key;
}
$data = [];
foreach ($_POST as $k => $v) {
    if (!in_array($k, $reserved)) {
        $data[htmlspecialchars(trim($k), ENT_QUOTES, 'UTF-8')] = is_array($v)
            ? implode(', ', array_map('trim', $v))
            : trim((string)$v);
    }
}

// ── Helpers ───────────────────────────────────────────────

function form_finish(string $redirect, string $error = ''): never {
    // Only allow relative paths to prevent open redirect
    if ($redirect && (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//'))) {
        $redirect = '';
    }
    $base = $redirect ?: ($_SERVER['HTTP_REFERER'] ?? '/');
    // Also guard the referer fallback against absolute URLs from untrusted sources
    if (!str_starts_with($base, '/') || str_starts_with($base, '//')) {
        $base = '/';
    }
    $sep  = str_contains($base, '?') ? '&' : '?';
    if ($error) {
        header('Location: ' . $base . $sep . 'form_error=' . urlencode($error));
    } else {
        header('Location: ' . $base . $sep . 'submitted=1');
    }
    exit;
}

function get_setting(string $key): string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", [$key]);
    return $row ? (string)$row['value'] : '';
}

function get_form_notify(string $formName): string {
    try {
        $row = OutpostDB::fetchOne("SELECT notify_email FROM form_configs WHERE form_name = ?", [$formName]);
        return ($row && $row['notify_email']) ? (string)$row['notify_email'] : '';
    } catch (Throwable $e) {
        return ''; // Table may not exist yet
    }
}

// ── Validate ─────────────────────────────────────────────

if (!$formName) {
    form_finish($redirect, 'missing_form_name');
}

// Basic: form name must be safe
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $formName)) {
    form_finish($redirect, 'invalid_form_name');
}

// Ensure DB table exists
ensure_form_submissions_table();

// ── Rate limit (IP) ──────────────────────────────────────

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// Strip IPv6 zone IDs
$ip = preg_replace('/%.+$/', '', $ip);

$recentCount = OutpostDB::fetchOne(
    "SELECT COUNT(*) as cnt FROM form_submissions
     WHERE ip = ? AND created_at > datetime('now', '-60 seconds')",
    [$ip]
);
if ((int)($recentCount['cnt'] ?? 0) >= 5) {
    form_finish($redirect, 'rate_limited');
}

// ── reCAPTCHA (optional) ─────────────────────────────────

$recaptchaSecret = get_setting('recaptcha_secret');
if ($recaptchaSecret) {
    $token = $_POST['g-recaptcha-response'] ?? '';
    if (!$token || !verify_recaptcha($token, $recaptchaSecret)) {
        form_finish($redirect, 'captcha_failed');
    }
}

// ── Honeypot check ───────────────────────────────────────

$hpField = '_hp_' . $formName;
if (!empty($_POST[$hpField])) {
    // Bot detected — silently redirect as if success
    form_finish($redirect);
}

// ── Builder-form validation ─────────────────────────────

$builderForm = null;
if ($formId) {
    $builderForm = OutpostDB::fetchOne('SELECT * FROM forms WHERE id = ?', [(int)$formId]);
    if ($builderForm) {
        $bFields   = json_decode($builderForm['fields'], true) ?: [];
        $bSettings = json_decode($builderForm['settings'], true) ?: [];

        // Validate required fields from schema
        foreach ($bFields as $bf) {
            if (!empty($bf['required']) && !in_array($bf['type'], ['hidden', 'html', 'section'])) {
                $fieldName = $bf['name'] ?? '';
                if ($fieldName && empty($data[$fieldName])) {
                    form_finish($redirect, 'required_field_missing');
                }
            }
        }

        // Use builder confirmation redirect if set
        if (empty($redirect) && !empty($bSettings['confirmation_type']) && $bSettings['confirmation_type'] === 'redirect' && !empty($bSettings['redirect_url'])) {
            $redirect = $bSettings['redirect_url'];
        }
    }
}

// ── Store submission ──────────────────────────────────────

$insertData = [
    'form_name'  => $formName,
    'data'       => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'ip'         => $ip,
    'created_at' => date('Y-m-d H:i:s'),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];
if ($builderForm) {
    $insertData['form_id'] = (int)$builderForm['id'];
}
OutpostDB::insert('form_submissions', $insertData);

// ── Dispatch webhook ──────────────────────────────────────
try {
    require_once __DIR__ . '/webhooks.php';
    ensure_webhooks_tables();
    dispatch_webhook('form.submitted', ['form' => $formName, 'data' => $data, 'ip' => $ip]);
} catch (\Throwable $e) {
    error_log('Outpost webhook error (form): ' . $e->getMessage());
}

// ── Send email notification ───────────────────────────────

try {
    $notifyEmail = (!empty($bSettings['notification_email']) ? $bSettings['notification_email'] : get_form_notify($formName)) ?: get_setting('notify_email');

    if ($notifyEmail) {
        $mailer  = OutpostMailer::fromSettings();
        $subject = '[' . $formName . '] New form submission';

        // Plain text body
        $text = "You received a new submission from the \"{$formName}\" form.\n\n";
        foreach ($data as $field => $value) {
            $text .= strtoupper($field) . "\n" . $value . "\n\n";
        }
        $text .= "---\nIP: {$ip}\nTime: " . date('Y-m-d H:i:s') . " UTC";

        // HTML body
        $html  = "<p>You received a new submission from the <strong>" . htmlspecialchars($formName) . "</strong> form.</p>";
        $html .= "<table style='border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;'>";
        foreach ($data as $field => $value) {
            $html .= "<tr style='border-bottom:1px solid #eee;'>";
            $html .= "<td style='padding:8px 12px;font-weight:600;color:#666;white-space:nowrap;vertical-align:top;width:140px;'>" . htmlspecialchars($field) . "</td>";
            $html .= "<td style='padding:8px 12px;color:#111;'>" . nl2br(htmlspecialchars($value)) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
        $html .= "<p style='font-size:12px;color:#999;margin-top:24px;'>IP: {$ip} &middot; " . date('Y-m-d H:i:s') . " UTC</p>";

        $mailer->send($notifyEmail, $subject, $text, $html);
    }
} catch (Throwable $e) {
    // Email failure never blocks the submission — it's already stored
    error_log('Outpost mailer error: ' . $e->getMessage());
}

// ── Done ──────────────────────────────────────────────────

form_finish($redirect);

// ── Table migration ───────────────────────────────────────

function ensure_form_submissions_table(): void {
    OutpostDB::connect()->exec("
        CREATE TABLE IF NOT EXISTS form_submissions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            form_name  TEXT    NOT NULL,
            data       TEXT    NOT NULL DEFAULT '{}',
            ip         TEXT    NOT NULL DEFAULT '',
            read_at    TEXT,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

// ── reCAPTCHA v2 verification ─────────────────────────────

function verify_recaptcha(string $token, string $secret): bool {
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query(['secret' => $secret, 'response' => $token]),
        'timeout' => 5,
    ]]);
    $res = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if (!$res) return false;
    $data = json_decode($res, true);
    return ($data['success'] ?? false) === true;
}
