<?php
/**
 * Outpost CMS — HTML to PHP Converter
 * Scans for .html files and converts them for CMS use.
 *
 * Usage: php convert.php [directory]
 *
 * What it does:
 * 1. Finds all .html files in the given directory
 * 2. Renames them to .php
 * 3. Injects the engine require at the top
 * 4. Reports what was converted
 */

$dir = $argv[1] ?? dirname(__DIR__, 2); // Default: site root (parent of outpost/)
$outpostPath = 'outpost/engine.php';

if (!is_dir($dir)) {
    echo "Error: Directory not found: {$dir}\n";
    exit(1);
}

echo "Outpost CMS — HTML to PHP Converter\n";
echo "Scanning: {$dir}\n\n";

$converted = 0;
$skipped = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    $path = $file->getPathname();

    // Skip outpost/ directory itself
    if (str_contains($path, '/outpost/') || str_contains($path, '/node_modules/')) {
        continue;
    }

    if ($file->getExtension() !== 'html') {
        continue;
    }

    $phpPath = preg_replace('/\.html$/', '.php', $path);

    // Skip if .php version already exists
    if (file_exists($phpPath)) {
        echo "  SKIP (PHP exists): {$path}\n";
        $skipped++;
        continue;
    }

    $content = file_get_contents($path);

    // Calculate relative path to outpost/engine.php
    $relativePath = str_repeat('../', substr_count(
        str_replace($dir, '', dirname($path)),
        DIRECTORY_SEPARATOR
    ));
    $requirePath = $relativePath . $outpostPath;

    // Inject engine require at the top
    $phpContent = "<?php require_once '{$requirePath}'; ?>\n" . $content;

    // Write .php file
    file_put_contents($phpPath, $phpContent);

    // Rename original .html to .html.bak
    rename($path, $path . '.bak');

    echo "  CONVERTED: {$path} → {$phpPath}\n";
    $converted++;
}

echo "\nDone! Converted: {$converted}, Skipped: {$skipped}\n";

if ($converted > 0) {
    echo "\nNext steps:\n";
    echo "  1. Add CMS tags to your .php files (e.g., <?php cms_text('title', 'Default'); ?>)\n";
    echo "  2. Visit each page in a browser to auto-discover fields\n";
    echo "  3. Log into /outpost/ to edit content\n";
}
