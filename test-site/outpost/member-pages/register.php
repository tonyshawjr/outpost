<?php
/**
 * Outpost CMS — Member Registration Page
 */
require_once dirname(__DIR__) . '/members.php';

$error = '';
$requiresVerification = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = OutpostMember::register(
        $_POST['username'] ?? '',
        $_POST['email'] ?? '',
        $_POST['password'] ?? ''
    );

    if ($result['success']) {
        if (!empty($result['requires_verification'])) {
            $requiresVerification = true;
        } else {
            header('Location: /');
            exit;
        }
    } else {
        $error = $result['error'];
    }
}

// Already logged in?
if (!$requiresVerification && OutpostMember::check()) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fafafa; color: #1a1a1a; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 2rem;
        }
        .auth-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 2.5rem; max-width: 400px; width: 100%; }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        .subtitle { font-size: 0.9rem; color: #737373; margin-bottom: 2rem; }
        label { display: block; font-size: 0.85rem; color: #525252; margin-bottom: 0.3rem; font-weight: 500; }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%; padding: 0.6rem 0.8rem; background: #fff; border: 1px solid #d4d4d4;
            border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; outline: none;
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
        <?php if ($requiresVerification): ?>
            <h1>Check your email</h1>
            <div class="info">We've sent a verification link to your email address. Please check your inbox and click the link to verify your account.</div>
            <div class="links">
                <a href="/outpost/member-pages/login.php">Sign in</a>
            </div>
        <?php else: ?>
        <h1>Create Account</h1>
        <p class="subtitle">Sign up for free to access member content.</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required minlength="3"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

            <button type="submit">Create Account</button>
        </form>

        <div class="links">
            Already have an account? <a href="/outpost/member-pages/login.php">Sign in</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
