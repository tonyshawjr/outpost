<?php
/**
 * Outpost CMS — One-File Installer
 *
 * Upload this single file to your server's web root, visit it in a browser,
 * and click Install. It downloads the latest Outpost release from GitHub
 * and sets everything up automatically.
 *
 * Requirements: PHP 8.0+, zip extension, allow_url_fopen or cURL
 *
 * After install, this file deletes itself for security.
 */

// ── Config ──────────────────────────────────────────────
define('OUTPOST_GITHUB_REPO', 'tonyshawjr/outpost');
define('OUTPOST_DIR_NAME', 'outpost');

// ── Safety checks ───────────────────────────────────────
if (PHP_VERSION_ID < 80000) {
    die('Outpost requires PHP 8.0 or higher. You are running PHP ' . PHP_VERSION);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$installDir = __DIR__ . '/' . OUTPOST_DIR_NAME;

// ── API: Check latest version ───────────────────────────
function outpost_fetch_latest_release(): array {
    $url = 'https://api.github.com/repos/' . OUTPOST_GITHUB_REPO . '/releases/latest';
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Outpost-Installer\r\nAccept: application/vnd.github.v3+json\r\n",
            'timeout' => 15,
        ],
    ];
    $ctx = stream_context_create($opts);
    $json = @file_get_contents($url, false, $ctx);

    if (!$json) {
        // Try cURL fallback
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['User-Agent: Outpost-Installer', 'Accept: application/vnd.github.v3+json'],
            ]);
            $json = curl_exec($ch);
            curl_close($ch);
        }
    }

    if (!$json) return ['error' => 'Could not reach GitHub. Check that your server allows outbound HTTPS requests.'];

    $data = json_decode($json, true);
    if (!$data || !isset($data['tag_name'])) return ['error' => 'Invalid response from GitHub.'];

    // Find the zip asset
    $zipUrl = null;
    foreach (($data['assets'] ?? []) as $asset) {
        if (str_ends_with($asset['name'], '.zip')) {
            $zipUrl = $asset['browser_download_url'];
            break;
        }
    }

    if (!$zipUrl) return ['error' => 'No zip file found in the latest release.'];

    return [
        'version' => $data['tag_name'],
        'zip_url' => $zipUrl,
        'notes' => $data['body'] ?? '',
        'published' => $data['published_at'] ?? '',
    ];
}

// ── API: Download and extract ───────────────────────────
function outpost_download_and_install(string $zipUrl): array {
    $tmpFile = sys_get_temp_dir() . '/outpost-install-' . bin2hex(random_bytes(4)) . '.zip';

    // Download — use cURL first (handles GitHub's CDN redirects properly)
    $zipData = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($zipUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['User-Agent: Outpost-Installer'],
        ]);
        $zipData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) $zipData = false;
    }

    if (!$zipData) {
        // Fallback to file_get_contents with redirect following
        $zipData = @file_get_contents($zipUrl, false, stream_context_create([
            'http' => [
                'header' => "User-Agent: Outpost-Installer\r\n",
                'timeout' => 120,
                'follow_location' => true,
                'max_redirects' => 5,
            ],
        ]));
    }

    if (!$zipData) return ['error' => 'Failed to download the release zip.'];

    file_put_contents($tmpFile, $zipData);

    if (!class_exists('ZipArchive')) {
        @unlink($tmpFile);
        return ['error' => 'PHP zip extension is required. Install it with: sudo apt install php-zip'];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        @unlink($tmpFile);
        return ['error' => 'Failed to open the downloaded zip file.'];
    }

    // Extract to the web root
    $extractDir = __DIR__;

    // The zip contains an 'outpost/' directory at root — extract directly
    $zip->extractTo($extractDir);
    $zip->close();
    @unlink($tmpFile);

    // Verify installation
    $installDir = $extractDir . '/' . OUTPOST_DIR_NAME;
    if (!file_exists($installDir . '/config.php')) {
        return ['error' => 'Installation extracted but config.php not found. The zip structure may have changed.'];
    }

    // Set directory permissions
    $dirs = ['content', 'content/data', 'content/uploads', 'content/themes', 'content/backups', 'cache'];
    foreach ($dirs as $dir) {
        $path = $installDir . '/' . $dir;
        if (is_dir($path)) chmod($path, 0755);
    }

    return ['success' => true];
}

// ── Handle AJAX requests ────────────────────────────────
if ($action === 'check') {
    header('Content-Type: application/json');
    echo json_encode(outpost_fetch_latest_release());
    exit;
}

if ($action === 'install') {
    header('Content-Type: application/json');

    // Check if already installed
    if (is_dir($installDir) && file_exists($installDir . '/config.php')) {
        echo json_encode(['error' => 'Outpost is already installed. Delete the outpost/ directory first to reinstall.']);
        exit;
    }

    $zipUrl = $_POST['zip_url'] ?? '';
    if (!$zipUrl || !str_starts_with($zipUrl, 'https://github.com/')) {
        echo json_encode(['error' => 'Invalid download URL.']);
        exit;
    }

    $result = outpost_download_and_install($zipUrl);

    // Self-delete on success
    if (!empty($result['success'])) {
        @unlink(__FILE__);
    }

    echo json_encode($result);
    exit;
}

// ── Check prerequisites ─────────────────────────────────
$checks = [
    'php_version' => ['ok' => PHP_VERSION_ID >= 80000, 'label' => 'PHP 8.0+', 'value' => PHP_VERSION],
    'zip' => ['ok' => class_exists('ZipArchive'), 'label' => 'Zip extension', 'value' => class_exists('ZipArchive') ? 'Installed' : 'Missing'],
    'writable' => ['ok' => is_writable(__DIR__), 'label' => 'Directory writable', 'value' => is_writable(__DIR__) ? 'Yes' : 'No'],
    'https' => ['ok' => function_exists('curl_init') || ini_get('allow_url_fopen'), 'label' => 'HTTPS requests', 'value' => function_exists('curl_init') ? 'cURL' : (ini_get('allow_url_fopen') ? 'file_get_contents' : 'None')],
    'pdo_sqlite' => ['ok' => extension_loaded('pdo_sqlite'), 'label' => 'PDO SQLite', 'value' => extension_loaded('pdo_sqlite') ? 'Installed' : 'Missing'],
];
$allOk = !in_array(false, array_column($checks, 'ok'));
$alreadyInstalled = is_dir($installDir) && file_exists($installDir . '/config.php');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Outpost CMS</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #FAF8F5;
    color: #1a1a1a;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .installer {
    max-width: 480px;
    width: 100%;
  }
  .logo {
    text-align: center;
    margin-bottom: 40px;
  }
  .logo h1 {
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.5px;
    color: #2D5A47;
  }
  .logo p {
    font-size: 14px;
    color: #888;
    margin-top: 6px;
  }
  .card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e8e4df;
    padding: 32px;
  }
  .checks {
    margin-bottom: 24px;
  }
  .check-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #f0ede8;
    font-size: 14px;
  }
  .check-row:last-child { border-bottom: none; }
  .check-label { color: #555; }
  .check-value { font-weight: 500; }
  .check-ok { color: #2D5A47; }
  .check-fail { color: #c53030; }
  .version-info {
    background: #f5f3ef;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
    font-size: 14px;
  }
  .version-info .ver { font-weight: 600; color: #2D5A47; }
  .btn {
    display: block;
    width: 100%;
    padding: 14px;
    background: #2D5A47;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
  }
  .btn:hover { background: #1E3D30; }
  .btn:disabled { background: #999; cursor: not-allowed; }
  .btn-secondary {
    background: none;
    color: #2D5A47;
    border: 1px solid #2D5A47;
    margin-top: 12px;
  }
  .btn-secondary:hover { background: rgba(45,90,71,0.05); }
  .status {
    text-align: center;
    padding: 16px 0;
    font-size: 14px;
    color: #555;
  }
  .status.error { color: #c53030; }
  .status.success { color: #2D5A47; }
  .spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid #ddd;
    border-top-color: #2D5A47;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    vertical-align: middle;
    margin-right: 8px;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .already-installed {
    text-align: center;
    padding: 24px 0;
  }
  .already-installed p { margin-bottom: 16px; color: #555; font-size: 14px; }
</style>
</head>
<body>
<div class="installer">
  <div class="logo">
    <h1>Outpost</h1>
    <p>One-click CMS installation</p>
  </div>

  <div class="card">
    <?php if ($alreadyInstalled): ?>
      <div class="already-installed">
        <p>Outpost is already installed on this server.</p>
        <a href="/outpost/" class="btn" style="text-decoration:none; text-align:center;">Open Outpost Admin</a>
      </div>
    <?php else: ?>
      <div class="checks">
        <?php foreach ($checks as $check): ?>
          <div class="check-row">
            <span class="check-label"><?= $check['label'] ?></span>
            <span class="check-value <?= $check['ok'] ? 'check-ok' : 'check-fail' ?>">
              <?= $check['ok'] ? '&#10003; ' : '&#10007; ' ?><?= htmlspecialchars($check['value']) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>

      <div id="version-info" class="version-info" style="display:none;"></div>
      <div id="status" class="status" style="display:none;"></div>

      <?php if ($allOk): ?>
        <button id="install-btn" class="btn" onclick="startInstall()">
          Install Outpost
        </button>
      <?php else: ?>
        <button class="btn" disabled>Fix the issues above to continue</button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$alreadyInstalled && $allOk): ?>
<script>
  const btn = document.getElementById('install-btn');
  const status = document.getElementById('status');
  const versionInfo = document.getElementById('version-info');
  let releaseData = null;

  // Check latest version on page load
  (async () => {
    try {
      const res = await fetch('?action=check');
      const data = await res.json();
      if (data.error) {
        status.textContent = data.error;
        status.className = 'status error';
        status.style.display = 'block';
        btn.disabled = true;
        return;
      }
      releaseData = data;
      versionInfo.innerHTML = 'Latest version: <span class="ver">' + data.version + '</span>';
      versionInfo.style.display = 'block';
    } catch (e) {
      status.textContent = 'Could not check for latest version.';
      status.className = 'status error';
      status.style.display = 'block';
    }
  })();

  async function startInstall() {
    if (!releaseData) {
      status.textContent = 'Still checking for latest version...';
      status.className = 'status';
      status.style.display = 'block';
      return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Installing...';
    status.innerHTML = 'Downloading ' + releaseData.version + '...';
    status.className = 'status';
    status.style.display = 'block';

    try {
      const form = new FormData();
      form.append('action', 'install');
      form.append('zip_url', releaseData.zip_url);

      const res = await fetch('', { method: 'POST', body: form });
      const data = await res.json();

      if (data.error) {
        status.textContent = data.error;
        status.className = 'status error';
        btn.disabled = false;
        btn.textContent = 'Try Again';
        return;
      }

      status.innerHTML = '&#10003; Outpost installed successfully! Redirecting...';
      status.className = 'status success';
      btn.style.display = 'none';

      setTimeout(() => {
        window.location.href = '/outpost/';
      }, 1500);
    } catch (e) {
      status.textContent = 'Installation failed: ' + e.message;
      status.className = 'status error';
      btn.disabled = false;
      btn.textContent = 'Try Again';
    }
  }
</script>
<?php endif; ?>
</body>
</html>
