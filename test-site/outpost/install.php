<?php
/**
 * Outpost CMS — Installation Wizard
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Block if already installed
if (file_exists(OUTPOST_DATA_DIR . '.installed')) {
    if (php_sapi_name() !== 'cli') {
        header('Location: ./');
        exit;
    }
    echo "Already installed.\n";
    exit;
}

$errors = [];
$step = $_POST['step'] ?? 'check';

// ── Step 1: Pre-flight checks ────────────────────────────
function check_requirements(): array {
    $checks = [];

    $checks['php_version'] = [
        'label' => 'PHP 8.0+',
        'pass' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'value' => PHP_VERSION,
    ];

    $checks['pdo_sqlite'] = [
        'label' => 'PDO SQLite Extension',
        'pass' => extension_loaded('pdo_sqlite'),
        'value' => extension_loaded('pdo_sqlite') ? 'Loaded' : 'Missing',
    ];

    $checks['gd'] = [
        'label' => 'GD Image Library',
        'pass' => extension_loaded('gd'),
        'value' => extension_loaded('gd') ? 'Loaded' : 'Missing (optional, needed for thumbnails)',
    ];

    $checks['content_writable'] = [
        'label' => 'Content directory writable',
        'pass' => is_writable(OUTPOST_CONTENT_DIR) || (!is_dir(OUTPOST_CONTENT_DIR) && is_writable(OUTPOST_DIR)),
        'value' => OUTPOST_CONTENT_DIR,
    ];

    $checks['cache_writable'] = [
        'label' => 'Cache directory writable',
        'pass' => is_writable(OUTPOST_CACHE_DIR) || (is_dir(OUTPOST_CACHE_DIR) === false && is_writable(OUTPOST_DIR)),
        'value' => OUTPOST_CACHE_DIR,
    ];

    return $checks;
}

// ── Step 2: Create DB + Admin User ───────────────────────
function do_install(string $username, string $password, string $email): array {
    $errors = [];

    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($errors) return $errors;

    // Create directories
    foreach ([OUTPOST_CONTENT_DIR, OUTPOST_DATA_DIR, OUTPOST_UPLOADS_DIR, OUTPOST_BACKUPS_DIR, OUTPOST_CACHE_DIR] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    // Create URL-compatible symlinks (uploads + themes served via /outpost/uploads/ and /outpost/themes/)
    $uploadsLink = OUTPOST_DIR . 'uploads';
    $themesLink = OUTPOST_DIR . 'themes';
    if (!file_exists($uploadsLink) && !is_link($uploadsLink)) symlink(OUTPOST_UPLOADS_DIR, $uploadsLink);
    if (!file_exists($themesLink) && !is_link($themesLink)) symlink(OUTPOST_THEMES_DIR, $themesLink);

    // Write .htaccess files for security
    file_put_contents(OUTPOST_CONTENT_DIR . '.htaccess', "Options -Indexes\n");
    file_put_contents(OUTPOST_DATA_DIR . '.htaccess', "Deny from all\n");
    file_put_contents(OUTPOST_BACKUPS_DIR . '.htaccess', "Deny from all\n");
    file_put_contents(OUTPOST_CACHE_DIR . '.htaccess', "Deny from all\n");
    file_put_contents(OUTPOST_UPLOADS_DIR . '.htaccess', "<FilesMatch \"\\.php$\">\n    Deny from all\n</FilesMatch>\n");

    // Create database
    try {
        // Touch DB file so PDO can connect
        touch(OUTPOST_DB_PATH);
        OutpostDB::createSchema();

        // Create admin user
        require_once __DIR__ . '/auth.php';
        OutpostDB::insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => OutpostAuth::hashPassword($password),
            'role' => 'admin',
        ]);

        // Default settings
        OutpostDB::query("INSERT OR IGNORE INTO settings (key, value) VALUES ('site_name', 'My Website')");
        OutpostDB::query("INSERT OR IGNORE INTO settings (key, value) VALUES ('cache_enabled', '1')");

        // Write lock file
        file_put_contents(OUTPOST_DATA_DIR . '.installed', date('Y-m-d H:i:s'));

        // Write .htaccess rewrite rules at site root
        outpost_install_htaccess();

    } catch (Exception $e) {
        $errors[] = 'Database error: ' . $e->getMessage();
    }

    return $errors;
}

// ── Write .htaccess rewrite rules at site root ───────────
function outpost_install_htaccess(): void {
    $htaccessPath = OUTPOST_SITE_ROOT . '.htaccess';
    $block = <<<'HTACCESS'
# BEGIN Outpost CMS
RewriteEngine On
RewriteBase /

# Let outpost admin and API through directly
RewriteRule ^outpost/ - [L]

# Serve static assets directly (not HTML — those go through Outpost)
RewriteCond %{REQUEST_FILENAME} -f
RewriteCond %{REQUEST_URI} !\.(html?)$ [NC]
RewriteRule ^ - [L]

# Serve directories directly
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Route everything else (including .html files) through Outpost
RewriteRule ^(.*)$ outpost/front-router.php [QSA,L]
# END Outpost CMS
HTACCESS;

    if (file_exists($htaccessPath)) {
        $existing = file_get_contents($htaccessPath);
        if (str_contains($existing, '# BEGIN Outpost CMS')) {
            // Replace existing block
            $existing = preg_replace(
                '/# BEGIN Outpost CMS.*?# END Outpost CMS/s',
                $block,
                $existing
            );
            file_put_contents($htaccessPath, $existing, LOCK_EX);
        } else {
            // Prepend block — Outpost rules must run first to catch .html files
            file_put_contents($htaccessPath, $block . "\n\n" . $existing, LOCK_EX);
        }
    } else {
        file_put_contents($htaccessPath, $block . "\n", LOCK_EX);
    }
}

// ── Handle POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'install') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $errors = do_install($username, $password, $email);

    if (empty($errors)) {
        header('Location: ./');
        exit;
    }
}

$checks = check_requirements();
$all_pass = !in_array(false, array_column($checks, 'pass'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Outpost CMS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0a;
            color: #e5e5e5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .installer {
            background: #141414;
            border: 1px solid #262626;
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 480px;
            width: 100%;
        }
        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: #fff;
        }
        .version {
            font-size: 0.8rem;
            color: #737373;
            margin-bottom: 2rem;
        }
        h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #d4d4d4;
        }
        .check-list { list-style: none; margin-bottom: 1.5rem; }
        .check-list li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border-bottom: 1px solid #1f1f1f;
        }
        .check-pass { color: #22c55e; }
        .check-fail { color: #ef4444; }
        .check-icon { width: 18px; text-align: center; }
        label {
            display: block;
            font-size: 0.85rem;
            color: #a3a3a3;
            margin-bottom: 0.3rem;
        }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%;
            padding: 0.6rem 0.8rem;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 6px;
            color: #e5e5e5;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            outline: none;
            transition: border-color 0.15s;
        }
        input:focus { border-color: #14b8a6; }
        button {
            width: 100%;
            padding: 0.7rem;
            background: #14b8a6;
            color: #0a0a0a;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.15s;
        }
        button:hover { opacity: 0.9; }
        button:disabled { opacity: 0.4; cursor: not-allowed; }
        .errors {
            background: #1c0a0a;
            border: 1px solid #7f1d1d;
            border-radius: 6px;
            padding: 0.8rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: #fca5a5;
        }
    </style>
</head>
<body>
    <div class="installer">
        <div class="logo">Outpost CMS</div>
        <div class="version">v<?= OUTPOST_VERSION ?></div>

        <h2>System Requirements</h2>
        <ul class="check-list">
            <?php foreach ($checks as $check): ?>
            <li class="<?= $check['pass'] ? 'check-pass' : 'check-fail' ?>">
                <span class="check-icon"><?= $check['pass'] ? '&#10003;' : '&#10007;' ?></span>
                <span><?= htmlspecialchars($check['label']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($all_pass): ?>
            <h2>Create Admin Account</h2>

            <?php if ($errors): ?>
                <div class="errors">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="step" value="install">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required minlength="3"
                       value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>">

                <label for="email">Email (optional)</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">

                <button type="submit">Install Outpost</button>
            </form>
        <?php else: ?>
            <p style="color: #ef4444; font-size: 0.9rem;">Please fix the issues above before installing.</p>
        <?php endif; ?>
    </div>
</body>
</html>
