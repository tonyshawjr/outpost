<?php
/**
 * Outpost CMS — Member Forgot Password Page
 */
require_once dirname(__DIR__) . '/members.php';
require_once dirname(__DIR__) . '/mailer.php';
require_once dirname(__DIR__) . '/auth.php';

// Migrate reset columns if needed
(function () {
    $db   = OutpostDB::connect();
    $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
    if (!in_array('reset_token', $cols))         $db->exec("ALTER TABLE users ADD COLUMN reset_token TEXT");
    if (!in_array('reset_token_expires', $cols)) $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires TEXT");
})();

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    // Always show the "sent" message — don't reveal whether address exists
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = OutpostDB::fetchOne(
            "SELECT id, username, email FROM users WHERE email = ? AND role IN ('free_member','paid_member')",
            [$email]
        );

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            OutpostDB::update('users', [
                'reset_token'         => $token,
                'reset_token_expires' => $expires,
            ], 'id = ?', [$user['id']]);

            $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetUrl = "{$scheme}://{$host}/outpost/member-pages/reset-password.php?token={$token}";

            $name    = $user['username'];
            $subject = 'Reset your password';
            $text    = "Hi {$name},\n\nYou requested a password reset. Use the link below to set a new password (expires in 1 hour):\n\n{$resetUrl}\n\nIf you didn't request this, ignore this email.\n";
            $html    = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#1a1a1a;max-width:520px;margin:40px auto;padding:0 24px'>"
                     . "<h2 style='font-size:1.25rem;margin-bottom:8px'>Reset your password</h2>"
                     . "<p style='color:#525252;margin-bottom:24px'>Hi " . htmlspecialchars($name) . ", click the button below — the link expires in 1 hour.</p>"
                     . "<a href='" . htmlspecialchars($resetUrl) . "' style='display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600'>Reset password</a>"
                     . "<p style='color:#737373;font-size:0.8rem;margin-top:24px'>Or copy: " . htmlspecialchars($resetUrl) . "</p>"
                     . "</body></html>";

            try {
                $mailer = OutpostMailer::fromSettings();
                $mailer->send($user['email'], $subject, $text, $html);
            } catch (Exception $e) {
                error_log('Member forgot-password email error: ' . $e->getMessage());
            }
        }
    }

    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fafafa; color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .auth-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 2.5rem; max-width: 400px; width: 100%; }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        .subtitle { font-size: 0.9rem; color: #737373; margin-bottom: 2rem; line-height: 1.5; }
        label { display: block; font-size: 0.85rem; color: #525252; margin-bottom: 0.3rem; font-weight: 500; }
        input[type="email"] { width: 100%; padding: 0.6rem 0.8rem; background: #fff; border: 1px solid #d4d4d4; border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; outline: none; transition: border-color 0.15s; }
        input:focus { border-color: #14b8a6; }
        button { width: 100%; padding: 0.7rem; background: #1a1a1a; color: #fff; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #333; }
        .info { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 0.8rem 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #15803d; line-height: 1.5; }
        .links { margin-top: 1.5rem; text-align: center; font-size: 0.85rem; color: #737373; }
        .links a { color: #14b8a6; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="auth-card">
        <h1>Forgot password?</h1>

        <?php if ($sent): ?>
            <div class="info">
                If that email address is on an account, you'll receive a reset link shortly. Check your inbox.
            </div>
            <div class="links">
                <a href="/outpost/member-pages/login.php">← Back to sign in</a>
            </div>
        <?php else: ?>
            <p class="subtitle">Enter your email and we'll send you a reset link.</p>
            <form method="POST">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" required autocomplete="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <button type="submit">Send reset link</button>
            </form>
            <div class="links">
                <a href="/outpost/member-pages/login.php">← Back to sign in</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
