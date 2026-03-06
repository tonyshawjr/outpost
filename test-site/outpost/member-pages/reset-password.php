<?php
/**
 * Outpost CMS — Member Reset Password Page
 */
require_once dirname(__DIR__) . '/members.php';
require_once dirname(__DIR__) . '/auth.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;

// Validate token on load
$validToken = false;
if ($token) {
    $tokenUser = OutpostDB::fetchOne(
        "SELECT id FROM users
         WHERE reset_token = ? AND reset_token_expires > datetime('now')
           AND role IN ('free_member','paid_member')",
        [$token]
    );
    $validToken = (bool) $tokenUser;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postToken = trim($_POST['token'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $user = OutpostDB::fetchOne(
            "SELECT id FROM users
             WHERE reset_token = ? AND reset_token_expires > datetime('now')
               AND role IN ('free_member','paid_member')",
            [$postToken]
        );

        if (!$user) {
            $error = 'This reset link is invalid or has expired. Please request a new one.';
        } else {
            OutpostDB::query(
                "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?",
                [OutpostAuth::hashPassword($password), $user['id']]
            );
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fafafa; color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .auth-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 2.5rem; max-width: 400px; width: 100%; }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        .subtitle { font-size: 0.9rem; color: #737373; margin-bottom: 2rem; }
        label { display: block; font-size: 0.85rem; color: #525252; margin-bottom: 0.3rem; font-weight: 500; }
        input[type="password"] { width: 100%; padding: 0.6rem 0.8rem; background: #fff; border: 1px solid #d4d4d4; border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; outline: none; transition: border-color 0.15s; }
        input:focus { border-color: #14b8a6; }
        button { width: 100%; padding: 0.7rem; background: #1a1a1a; color: #fff; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #333; }
        .error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 0.7rem; margin-bottom: 1rem; font-size: 0.85rem; color: #dc2626; }
        .info { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 0.8rem 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #15803d; }
        .links { margin-top: 1.5rem; text-align: center; font-size: 0.85rem; color: #737373; }
        .links a { color: #14b8a6; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="auth-card">
        <h1>Set new password</h1>

        <?php if ($success): ?>
            <div class="info">Your password has been updated. You can now sign in.</div>
            <div class="links"><a href="/outpost/member-pages/login.php">Sign in →</a></div>

        <?php elseif (!$validToken): ?>
            <div class="error">This reset link is invalid or has expired.</div>
            <div class="links">
                <a href="/outpost/member-pages/forgot-password.php">Request a new one</a>
                &nbsp;·&nbsp;
                <a href="/outpost/member-pages/login.php">Sign in</a>
            </div>

        <?php else: ?>
            <p class="subtitle">Choose a new password for your account.</p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <label for="password">New password</label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

                <label for="confirm">Confirm password</label>
                <input type="password" id="confirm" name="confirm" required minlength="8" autocomplete="new-password">

                <button type="submit">Set new password</button>
            </form>

            <div class="links">
                <a href="/outpost/member-pages/login.php">← Back to sign in</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
