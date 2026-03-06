<?php
// Temporary debug page — visit http://localhost:8000/ope-debug.php
require_once __DIR__ . '/outpost/config.php';
require_once __DIR__ . '/outpost/engine.php';

echo "<pre>\n";
echo "Session status: " . session_status() . "\n";
echo "Session name: " . session_name() . "\n";
echo "outpost_user_id: " . ($_SESSION['outpost_user_id'] ?? 'NOT SET') . "\n";
echo "outpost_login_time: " . ($_SESSION['outpost_login_time'] ?? 'NOT SET') . "\n";
echo "outpost_is_admin(): " . (outpost_is_admin() ? 'YES' : 'NO') . "\n";

global $_outpost_edit_mode;
echo "_outpost_edit_mode (before init): " . ($_outpost_edit_mode ? 'YES' : 'NO') . "\n";

// Simulate what outpost_init does
$_outpost_edit_mode = outpost_is_admin();
echo "_outpost_edit_mode (after set): " . ($_outpost_edit_mode ? 'YES' : 'NO') . "\n";

// Check globals cache
global $_outpost_globals_cache;
$_outpost_globals_cache = null; // reset
cms_global_get('bio_short'); // trigger cache load
echo "\nGlobals cache loaded. bio_short id: " . ($_outpost_globals_cache['bio_short']['id'] ?? 'NOT FOUND') . "\n";
echo "profile_photo id: " . ($_outpost_globals_cache['profile_photo']['id'] ?? 'NOT FOUND') . "\n";
echo "Global page id: " . ($_outpost_globals_cache['_page_id'] ?? 'NOT FOUND') . "\n";

echo "\n--- bio_short output test ---\n";
echo "With edit_mode=true:\n";
$_outpost_edit_mode = true;
ob_start();
cms_global('bio_short', 'text', 'default');
$out = ob_get_clean();
echo htmlspecialchars($out) . "\n";
echo "Contains data-ope-field: " . (strpos($out, 'data-ope-field') !== false ? 'YES' : 'NO') . "\n";

echo "\n--- image fields ---\n";
global $_outpost_image_fields;
$_outpost_image_fields = [];
$_outpost_edit_mode = true;
ob_start();
cms_global('profile_photo', 'image');
ob_end_clean();
echo "Image fields count: " . count($_outpost_image_fields) . "\n";
if ($_outpost_image_fields) {
    echo "First: " . json_encode($_outpost_image_fields[0]) . "\n";
}

echo "</pre>\n";
