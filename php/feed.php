<?php
/**
 * Outpost CMS — RSS Feed Generator
 * Generates RSS 2.0 feeds for collections.
 *
 * Usage: require this file when /feed.xml or /{collection}/feed is requested.
 * Expects db.php and config.php already loaded.
 */

/**
 * Strip HTML, decode entities, trim, and truncate at a word boundary.
 */
function outpost_feed_excerpt(string $html, int $maxChars = 500): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return $text;
    }

    $truncated = mb_substr($text, 0, $maxChars, 'UTF-8');
    // Cut at last word boundary
    $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
    if ($lastSpace !== false && $lastSpace > $maxChars * 0.5) {
        $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
    }

    return $truncated . "\xe2\x80\xa6"; // UTF-8 ellipsis
}

/**
 * Generate an RSS 2.0 feed.
 *
 * @param string|null $collectionSlug  null = site-wide feed; string = single collection
 */
function outpost_generate_feed(?string $collectionSlug = null): void {
    // ── Base URL ─────────────────────────────────────────
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host;

    $e = fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');

    // ── Site metadata ────────────────────────────────────
    $siteNameRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'site_name'");
    $siteName    = ($siteNameRow && $siteNameRow['value']) ? $siteNameRow['value'] : 'Untitled Site';

    // Site tagline from globals
    $siteTagline = '';
    try {
        $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if ($globalPage) {
            $taglineRow = OutpostDB::fetchOne(
                "SELECT content FROM fields WHERE page_id = ? AND field_name = 'site_tagline'",
                [$globalPage['id']]
            );
            if ($taglineRow && $taglineRow['content']) {
                $siteTagline = $taglineRow['content'];
            }
        }
    } catch (\Throwable $t) {
        // fields table structure may vary
    }

    // ── Determine if feed_enabled column exists ──────────
    $hasFeedEnabled = true;
    try {
        OutpostDB::fetchOne("SELECT feed_enabled FROM collections LIMIT 1");
    } catch (\Throwable $t) {
        $hasFeedEnabled = false;
    }

    // ── Per-collection feed ──────────────────────────────
    if ($collectionSlug !== null) {
        $collectionSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($collectionSlug));

        if ($hasFeedEnabled) {
            $col = OutpostDB::fetchOne(
                "SELECT id, slug, name, url_pattern FROM collections WHERE slug = ? AND feed_enabled = 1",
                [$collectionSlug]
            );
        } else {
            $col = OutpostDB::fetchOne(
                "SELECT id, slug, name, url_pattern FROM collections WHERE slug = ?",
                [$collectionSlug]
            );
        }

        if (!$col) {
            http_response_code(404);
            echo '404 — Feed not found';
            return;
        }

        $channelTitle = $siteName . ' — ' . $col['name'];
        $channelDesc  = $siteTagline ?: $col['name'];
        $selfUrl      = $baseUrl . '/' . $col['slug'] . '/feed.xml';

        $items = OutpostDB::fetchAll(
            "SELECT ci.*, c.slug AS collection_slug, c.url_pattern, c.name AS collection_name
             FROM collection_items ci
             JOIN collections c ON ci.collection_id = c.id
             WHERE ci.status = 'published' AND ci.collection_id = ?
             ORDER BY ci.published_at DESC
             LIMIT 50",
            [$col['id']]
        );
    } else {
        // ── Site-wide feed ───────────────────────────────
        $channelTitle = $siteName;
        $channelDesc  = $siteTagline ?: $siteName;
        $selfUrl      = $baseUrl . '/feed.xml';

        if ($hasFeedEnabled) {
            $items = OutpostDB::fetchAll(
                "SELECT ci.*, c.slug AS collection_slug, c.url_pattern, c.name AS collection_name
                 FROM collection_items ci
                 JOIN collections c ON ci.collection_id = c.id
                 WHERE ci.status = 'published' AND c.feed_enabled = 1
                 ORDER BY ci.published_at DESC
                 LIMIT 50"
            );
        } else {
            $items = OutpostDB::fetchAll(
                "SELECT ci.*, c.slug AS collection_slug, c.url_pattern, c.name AS collection_name
                 FROM collection_items ci
                 JOIN collections c ON ci.collection_id = c.id
                 WHERE ci.status = 'published'
                 ORDER BY ci.published_at DESC
                 LIMIT 50"
            );
        }
    }

    // ── Last build date ──────────────────────────────────
    $lastBuildDate = '';
    if (!empty($items)) {
        $firstDate = $items[0]['published_at'] ?? $items[0]['created_at'] ?? '';
        if ($firstDate) {
            $lastBuildDate = date(DATE_RFC2822, strtotime($firstDate));
        }
    }
    if (!$lastBuildDate) {
        $lastBuildDate = date(DATE_RFC2822);
    }

    // ── Output RSS XML ───────────────────────────────────
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
    echo "  <channel>\n";
    echo '    <title>' . $e($channelTitle) . "</title>\n";
    echo '    <link>' . $e($baseUrl) . "</link>\n";
    echo '    <description>' . $e($channelDesc) . "</description>\n";
    echo "    <language>en</language>\n";
    echo '    <lastBuildDate>' . $e($lastBuildDate) . "</lastBuildDate>\n";
    echo '    <atom:link href="' . $e($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
    echo "    <generator>Outpost CMS</generator>\n";

    foreach ($items as $item) {
        $data = json_decode($item['data'] ?? '{}', true) ?: [];

        // Title — fall back to slug
        $itemTitle = $data['title'] ?? '';
        if ($itemTitle === '') {
            $itemTitle = $item['slug'] ?? 'Untitled';
        }

        // URL
        $urlPattern = $item['url_pattern'] ?: '/' . $item['collection_slug'] . '/{slug}';
        $itemUrl    = $baseUrl . str_replace('{slug}', $item['slug'], $urlPattern);

        // Pub date
        $pubDate = '';
        $dateStr = $item['published_at'] ?? $item['created_at'] ?? '';
        if ($dateStr) {
            $pubDate = date(DATE_RFC2822, strtotime($dateStr));
        }

        // Description — excerpt or truncated body
        $excerpt = $data['excerpt'] ?? '';
        if ($excerpt === '') {
            $excerpt = $data['body'] ?? '';
        }
        $description = ($excerpt !== '') ? outpost_feed_excerpt($excerpt, 500) : '';

        // Author
        $author = $data['author'] ?? '';

        echo "\n    <item>\n";
        echo '      <title>' . $e($itemTitle) . "</title>\n";
        echo '      <link>' . $e($itemUrl) . "</link>\n";
        echo '      <guid isPermaLink="true">' . $e($itemUrl) . "</guid>\n";
        if ($pubDate) {
            echo '      <pubDate>' . $e($pubDate) . "</pubDate>\n";
        }
        if ($description !== '') {
            echo '      <description>' . $e($description) . "</description>\n";
        }
        if ($author !== '') {
            echo '      <dc:creator>' . $e($author) . "</dc:creator>\n";
        }
        echo "    </item>\n";
    }

    echo "  </channel>\n";
    echo "</rss>\n";
}
