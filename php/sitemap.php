<?php
/**
 * Outpost CMS — XML Sitemap Generator + Robots.txt
 * Generates a sitemap.xml from pages + published collection items.
 * Generates a robots.txt with sitemap reference.
 *
 * Usage: require this file when /sitemap.xml or /robots.txt is requested.
 * Expects db.php and config.php already loaded.
 */

function outpost_generate_sitemap(): void {
    // Base URL from request
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host;

    // Collect URLs
    $urls = [];

    // 1. Pages (exclude globals, system paths, drafts, and member-only pages)
    $pages = OutpostDB::fetchAll(
        "SELECT path, updated_at FROM pages
         WHERE path NOT LIKE '%\\_\\_global\\_\\_%' ESCAPE '\\'
           AND path NOT LIKE '/outpost%'
           AND COALESCE(status, 'published') != 'draft'
           AND COALESCE(visibility, 'public') = 'public'
         ORDER BY path"
    );
    foreach ($pages as $page) {
        $isHome = ($page['path'] === '/');
        $urls[] = [
            'loc'        => $baseUrl . $page['path'],
            'lastmod'    => outpost_sitemap_date($page['updated_at']),
            'changefreq' => $isHome ? 'daily' : 'weekly',
            'priority'   => $isHome ? '1.0' : '0.8',
        ];
    }

    // 2. Published collection items (only from sitemap-enabled collections)
    $items = OutpostDB::fetchAll(
        "SELECT ci.slug, ci.updated_at, ci.published_at,
                c.slug AS collection_slug, c.url_pattern
         FROM collection_items ci
         JOIN collections c ON ci.collection_id = c.id
         WHERE ci.status = 'published'
           AND COALESCE(c.sitemap_enabled, 1) = 1
         ORDER BY c.slug, ci.slug"
    );
    foreach ($items as $item) {
        $pattern = $item['url_pattern'] ?: '/' . $item['collection_slug'] . '/{slug}';
        $itemUrl = str_replace('{slug}', $item['slug'], $pattern);
        $urls[] = [
            'loc'        => $baseUrl . $itemUrl,
            'lastmod'    => outpost_sitemap_date($item['updated_at'] ?? $item['published_at']),
            'changefreq' => 'monthly',
            'priority'   => '0.6',
        ];
    }

    // Output XML
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $entry) {
        echo "  <url>\n";
        echo '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
        if ($entry['lastmod']) {
            echo '    <lastmod>' . $entry['lastmod'] . "</lastmod>\n";
        }
        echo '    <changefreq>' . $entry['changefreq'] . "</changefreq>\n";
        echo '    <priority>' . $entry['priority'] . "</priority>\n";
        echo "  </url>\n";
    }
    echo "</urlset>\n";
}

/**
 * Generate a robots.txt that references the sitemap and blocks admin paths.
 */
function outpost_generate_robots(): void {
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host;

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /outpost/\n";
    echo "\n";
    echo "Sitemap: {$baseUrl}/sitemap.xml\n";
}

/**
 * Format a DB datetime string to YYYY-MM-DD for sitemap.
 */
function outpost_sitemap_date(?string $datetime): ?string {
    if (!$datetime) return null;
    $ts = strtotime($datetime);
    return $ts ? date('Y-m-d', $ts) : null;
}
