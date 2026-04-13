<?php
/**
 * Outpost CMS — Member Login Page
 */
require_once dirname(__DIR__) . '/members.php';

$error = '';
$needsVerification = false;
$maskedEmail = '';
$resendSuccess = false;

// Handle resend verification POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verify'])) {
    $email = trim($_POST['resend_email'] ?? '');
    if ($email) {
        // Rate-limit check
        OutpostMember::init();
        $lastResend = $_SESSION['last_resend_verify'] ?? 0;
        if (time() - $lastResend >= 60) {
            $_SESSION['last_resend_verify'] = time();
            $user = OutpostDB::fetchOne(
                "SELECT id, username, email FROM users WHERE email = ? AND role IN ('free_member','paid_member') AND email_verified IS NULL AND verify_token IS NOT NULL",
                [$email]
            );
            if ($user) {
                OutpostMember::sendVerificationEmail($user['id'], $user['username'], $user['email']);
            }
        }
    }
    $resendSuccess = true;
    $needsVerification = true;
    $maskedEmail = OutpostMember::maskEmail($email);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $result = OutpostMember::login($identifier, $password);

    if ($result['success']) {
        $redirect = $_GET['redirect'] ?? '/';
        // Prevent open redirect: only allow relative paths, block protocol-relative URLs
        if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = '/';
        }
        header('Location: ' . $redirect);
        exit;
    }
    $error = $result['error'];
    if (!empty($result['needs_verification'])) {
        $needsVerification = true;
        $maskedEmail = $result['email'] ?? '';
    }
}

// Already logged in?
if (OutpostMember::check()) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fafafa;
            color: #1a1a1a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .auth-card {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 400px;
            width: 100%;
        }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        .subtitle { font-size: 0.9rem; color: #737373; margin-bottom: 2rem; }
        label { display: block; font-size: 0.85rem; color: #525252; margin-bottom: 0.3rem; font-weight: 500; }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%; padding: 0.6rem 0.8rem; background: #fff; border: 1px solid #d4d4d4;
            border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; outline: none; transition: border-color 0.15s;
        }
        input:focus { border-color: #14b8a6; }
        button {
            width: 100%; padding: 0.7rem; background: #1a1a1a; color: #fff; border: none;
            border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer;
        }
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
        <h1>Sign In</h1>
        <p class="subtitle">Welcome back. Enter your credentials to continue.</p>

        <?php if ($needsVerification): ?>
            <?php if ($resendSuccess): ?>
                <div class="info">Verification email sent. Please check your inbox.</div>
            <?php else: ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p style="font-size: 0.85rem; color: #525252; margin-bottom: 1rem;">
                A verification email was sent to <strong><?= htmlspecialchars($maskedEmail) ?></strong>. Please verify your email to sign in.
            </p>
            <form method="POST">
                <input type="hidden" name="resend_verify" value="1">
                <input type="hidden" name="resend_email" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                <button type="submit">Resend verification email</button>
            </form>
            <div class="links" style="margin-top: 1rem;">
                <a href="/outpost/member-pages/login.php">Back to sign in</a>
            </div>
        <?php else: ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="identifier">Username or Email</label>
            <input type="text" id="identifier" name="identifier" required
                   value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" autocomplete="username">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">

            <button type="submit">Sign In</button>
        </form>

        <div class="links">
            <a href="/outpost/member-pages/forgot-password.php">Forgot password?</a>
            &nbsp;·&nbsp;
            Don't have an account? <a href="/outpost/member-pages/register.php">Create one</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
