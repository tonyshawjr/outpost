<?php
/**
 * Outpost CMS — Email Verification Landing Page
 */
require_once dirname(__DIR__) . '/members.php';

$token   = trim($_GET['token'] ?? '');
$success = false;
$error   = '';

if ($token) {
    $user = OutpostDB::fetchOne(
        "SELECT id FROM users
         WHERE verify_token = ? AND verify_token_expires > datetime('now')
           AND role IN ('free_member','paid_member')",
        [$token]
    );

    if ($user) {
        OutpostDB::query(
            "UPDATE users SET email_verified = datetime('now'), verify_token = NULL, verify_token_expires = NULL WHERE id = ?",
            [$user['id']]
        );
        $success = true;
    } else {
        $error = 'This verification link is invalid or has expired.';
    }
} else {
    $error = 'No verification token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fafafa; color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .auth-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 2.5rem; max-width: 400px; width: 100%; }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        .error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 0.7rem; margin-bottom: 1rem; font-size: 0.85rem; color: #dc2626; }
        .info { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 0.8rem 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #15803d; }
        .links { margin-top: 1.5rem; text-align: center; font-size: 0.85rem; color: #737373; }
        .links a { color: #14b8a6; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="auth-card">
        <?php if ($success): ?>
            <h1>Email verified</h1>
            <div class="info">Your email has been verified. You can now sign in.</div>
            <div class="links"><a href="/outpost/member-pages/login.php">Sign in &rarr;</a></div>
        <?php else: ?>
            <h1>Verification failed</h1>
            <div class="error"><?= htmlspecialchars($error) ?></div>
            <div class="links">
                <a href="/outpost/member-pages/login.php">Sign in</a>
                &nbsp;&middot;&nbsp;
                <a href="/outpost/member-pages/register.php">Create account</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
