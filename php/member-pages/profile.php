<?php
/**
 * Outpost CMS — Member Profile Page
 */
require_once dirname(__DIR__) . '/members.php';
require_once dirname(__DIR__) . '/db.php';

// Require login
if (!OutpostMember::check()) {
    header('Location: /outpost/member-pages/login.php');
    exit;
}

$member = OutpostMember::currentMember();
$user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$member['id']]);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    OutpostMember::validateCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $display_name = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            if ($email && $email !== $user['email']) {
                $existing = OutpostDB::fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $member['id']]);
                if ($existing) {
                    $error = 'Email already in use.';
                }
            }

            if (!$error) {
                OutpostDB::update('users', [
                    'display_name' => $display_name,
                    'email' => $email,
                ], 'id = ?', [$member['id']]);
                $success = 'Profile updated.';
                $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$member['id']]);
            }
        }
    }

    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';

        if (!$current || !$new) {
            $error = 'Both passwords are required.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            require_once dirname(__DIR__) . '/auth.php';
            OutpostDB::update('users', [
                'password_hash' => OutpostAuth::hashPassword($new),
            ], 'id = ?', [$member['id']]);
            $success = 'Password updated.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Profile</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fafafa; color: #1a1a1a; min-height: 100vh; padding: 2rem;
            display: flex; justify-content: center;
        }
        .container { max-width: 480px; width: 100%; }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 2rem; }
        h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #525252; }
        .card { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 2rem; margin-bottom: 1.5rem; }
        label { display: block; font-size: 0.85rem; color: #525252; margin-bottom: 0.3rem; font-weight: 500; }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%; padding: 0.6rem 0.8rem; background: #fff; border: 1px solid #d4d4d4;
            border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; outline: none;
        }
        input:focus { border-color: #14b8a6; }
        button { padding: 0.6rem 1.2rem; background: #1a1a1a; color: #fff; border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #333; }
        .badge { display: inline-block; font-size: 0.75rem; padding: 0.2rem 0.6rem; border-radius: 999px; background: #f0fdf4; color: #15803d; font-weight: 500; margin-bottom: 1rem; }
        .badge.paid { background: #fef3c7; color: #92400e; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 0.7rem; margin-bottom: 1rem; font-size: 0.85rem; color: #15803d; }
        .error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 0.7rem; margin-bottom: 1rem; font-size: 0.85rem; color: #dc2626; }
        .links { text-align: center; font-size: 0.85rem; color: #737373; }
        .links a { color: #14b8a6; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Your Profile</h1>

        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <span class="badge <?= $user['role'] === 'paid_member' ? 'paid' : '' ?>">
                <?= $user['role'] === 'paid_member' ? 'Paid Member' : 'Free Member' ?>
            </span>

            <h2>Profile Details</h2>
            <form method="POST">
                <input type="hidden" name="action" value="profile">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(OutpostMember::csrfToken()) ?>">

                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name"
                       value="<?= htmlspecialchars($user['display_name'] ?? '') ?>">

                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($user['email'] ?? '') ?>">

                <button type="submit">Save Changes</button>
            </form>
        </div>

        <div class="card">
            <h2>Change Password</h2>
            <form method="POST">
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(OutpostMember::csrfToken()) ?>">

                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required>

                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required minlength="8">

                <button type="submit">Update Password</button>
            </form>
        </div>

        <div class="links">
            <a href="/outpost/member-pages/logout.php">Sign out</a>
        </div>
    </div>
</body>
</html>
