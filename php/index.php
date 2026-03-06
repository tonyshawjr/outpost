<?php
/**
 * Outpost CMS — Admin Entry Point
 * Serves the Svelte SPA shell or redirects to install.
 */

require_once __DIR__ . '/config.php';

// Redirect to install if not set up
if (!file_exists(OUTPOST_DATA_DIR . '.installed')) {
    header('Location: install.php');
    exit;
}

// Find the built admin index.html
$adminIndex = OUTPOST_ADMIN_DIR . 'index.html';

if (file_exists($adminIndex)) {
    // Serve the compiled Svelte SPA
    $html = file_get_contents($adminIndex);

    // Inject CSRF token and base path for the SPA
    require_once __DIR__ . '/auth.php';
    OutpostAuth::init();

    $config = json_encode([
        'basePath' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'),
        'apiUrl' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/api.php',
        'csrfToken' => OutpostAuth::csrfToken(),
        'version' => OUTPOST_VERSION,
    ]);

    $html = str_replace(
        '</head>',
        "<script>window.__OUTPOST_CONFIG__ = {$config};</script>\n</head>",
        $html
    );

    // Rewrite asset paths: ./assets/ → ./admin/assets/ since index.php serves from parent dir
    $html = str_replace('./assets/', './admin/assets/', $html);

    echo $html;
} else {
    // Fallback: admin not built yet
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Outpost CMS</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: #0a0a0a; color: #e5e5e5;
                display: flex; align-items: center; justify-content: center;
                min-height: 100vh; margin: 0;
            }
            .msg {
                text-align: center;
                max-width: 400px;
            }
            h1 { font-size: 1.3rem; margin-bottom: 0.5rem; }
            p { color: #737373; font-size: 0.9rem; line-height: 1.6; }
            code {
                background: #1a1a1a;
                padding: 0.15rem 0.4rem;
                border-radius: 4px;
                font-size: 0.85rem;
            }
        </style>
    </head>
    <body>
        <div class="msg">
            <h1>Admin panel not built yet</h1>
            <p>Run <code>npm run build</code> in the <code>cms/</code> directory to compile the admin interface.</p>
        </div>
    </body>
    </html>
    <?php
}
