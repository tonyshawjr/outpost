<?php
/**
 * Outpost CMS — WordPress WXR Import
 *
 * Parses a WordPress export file (WXR 1.x) and imports posts into a collection.
 * Called from api.php for action=import/wordpress.
 */

function handle_wordpress_import(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    // Accept either a file upload or a previously uploaded temp path
    $tmpPath = null;
    if (!empty($_FILES['file']['tmp_name'])) {
        $tmpPath = $_FILES['file']['tmp_name'];
    } elseif (!empty($_POST['tmp_path'])) {
        // Second-pass call with already-uploaded file path (unused for now)
        $tmpPath = $_POST['tmp_path'];
    }
    if (!$tmpPath || !file_exists($tmpPath)) {
        json_error('No file uploaded');
    }

    $options = [
        'collection_slug' => trim($_POST['collection_slug'] ?? 'post'),
        'status_filter'   => $_POST['status_filter'] ?? 'publish', // 'publish' | 'all'
        'on_duplicate'    => $_POST['on_duplicate'] ?? 'skip',     // 'skip' | 'overwrite'
        'import_images'   => ($_POST['import_images'] ?? '0') === '1',
    ];

    $result = wordpress_import_wxr($tmpPath, $options);
    json_response($result);
}

/**
 * Core WXR parser and importer.
 */
function wordpress_import_wxr(string $filePath, array $options): array {
    ensure_taxonomy_tables();
    ensure_items_columns();

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->load($filePath)) {
        return ['error' => 'Could not parse XML file — is it a valid WordPress export?'];
    }
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('wp',      'http://wordpress.org/export/1.2/');
    $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
    $xpath->registerNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');
    $xpath->registerNamespace('dc',      'http://purl.org/dc/elements/1.1/');

    // ── Find or create collection ─────────────────────────
    $collectionSlug = $options['collection_slug'];
    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$collectionSlug]);
    if (!$collection) {
        return ['error' => "Collection '{$collectionSlug}' not found. Create it in the admin first."];
    }
    $collectionId = (int) $collection['id'];

    // ── Build attachment map: wp_post_id → attachment_url ─
    $attachmentMap = [];
    $attachments = $xpath->query('//item[wp:post_type="attachment"]');
    foreach ($attachments as $att) {
        $postId  = wp_node($xpath, $att, 'wp:post_id');
        $attUrl  = wp_node($xpath, $att, 'wp:attachment_url');
        if ($postId && $attUrl) {
            $attachmentMap[(int) $postId] = $attUrl;
        }
    }

    // ── Find or create 'categories' folder ──────────────
    $categoryFolder   = wp_ensure_folder($collectionId, 'categories', 'Categories');
    $categoryFolderId = $categoryFolder['id'];

    // ── Process posts ─────────────────────────────────────
    $stats = ['imported' => 0, 'skipped' => 0, 'overwritten' => 0, 'errors' => []];

    $items = $xpath->query('//item[wp:post_type="post"]');
    foreach ($items as $item) {
        $wpStatus = wp_node($xpath, $item, 'wp:status');

        // Status filter
        if ($options['status_filter'] === 'publish' && $wpStatus !== 'publish') {
            $stats['skipped']++;
            continue;
        }

        $title   = wp_text($xpath, $item, 'title');
        $slug    = wp_node($xpath, $item, 'wp:post_name');
        $content = wp_text($xpath, $item, 'content:encoded');
        $excerpt = wp_text($xpath, $item, 'excerpt:encoded');
        $pubDate = wp_node($xpath, $item, 'wp:post_date');
        $author  = wp_text($xpath, $item, 'dc:creator');

        // Sanitise slug
        $slug = trim($slug);
        if (!$slug) {
            $slug = wp_slugify($title);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $slug = wp_slugify($slug);
        }
        if (!$slug) {
            $stats['errors'][] = "Skipped post with empty title/slug";
            $stats['skipped']++;
            continue;
        }

        // Featured image
        $featuredImageUrl = '';
        $thumbnailId = wp_meta($xpath, $item, '_thumbnail_id');
        if ($thumbnailId && isset($attachmentMap[(int) $thumbnailId])) {
            $featuredImageUrl = $attachmentMap[(int) $thumbnailId];
        }

        // Categories
        $categories = [];
        $catNodes = $xpath->query('category[@domain="category"]', $item);
        foreach ($catNodes as $cat) {
            $catName = trim($cat->textContent);
            $catSlug = $cat->getAttribute('nicename') ?: wp_slugify($catName);
            if ($catName && $catSlug) {
                $categories[] = ['name' => $catName, 'slug' => $catSlug];
            }
        }

        // Map WP status → Outpost status
        $outpostStatus = ($wpStatus === 'publish') ? 'published' : 'draft';

        $postData = [
            'title'          => $title,
            'body'           => $content,
            'excerpt'        => $excerpt,
            'author'         => $author,
            'featured_image' => $featuredImageUrl,
        ];

        // Check duplicate
        $existing = OutpostDB::fetchOne(
            'SELECT id FROM collection_items WHERE collection_id = ? AND slug = ?',
            [$collectionId, $slug]
        );

        if ($existing) {
            if ($options['on_duplicate'] === 'skip') {
                $stats['skipped']++;
                continue;
            }
            // Overwrite
            $updateData = [
                'data'       => json_encode($postData),
                'status'     => $outpostStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($outpostStatus === 'published' && $pubDate) {
                $updateData['published_at'] = date('Y-m-d H:i:s', strtotime($pubDate));
            }
            OutpostDB::update('collection_items', $updateData, 'id = ?', [$existing['id']]);
            $itemId = (int) $existing['id'];
            $stats['overwritten']++;
        } else {
            $insertData = [
                'collection_id' => $collectionId,
                'slug'          => $slug,
                'status'        => $outpostStatus,
                'data'          => json_encode($postData),
            ];
            if ($outpostStatus === 'published' && $pubDate) {
                $insertData['published_at'] = date('Y-m-d H:i:s', strtotime($pubDate));
            }
            $itemId = OutpostDB::insert('collection_items', $insertData);
            $stats['imported']++;
        }

        // Assign categories as labels
        if ($itemId && !empty($categories)) {
            wp_assign_categories($itemId, $categoryFolderId, $categories);
        }
    }

    return [
        'success'     => true,
        'imported'    => $stats['imported'],
        'skipped'     => $stats['skipped'],
        'overwritten' => $stats['overwritten'],
        'errors'      => $stats['errors'],
    ];
}

// ── Helpers ───────────────────────────────────────────────

function wp_node(DOMXPath $xpath, DOMNode $ctx, string $tag): string {
    $nodes = $xpath->query($tag, $ctx);
    return $nodes && $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
}

function wp_text(DOMXPath $xpath, DOMNode $ctx, string $tag): string {
    // Returns raw text (CDATA content)
    return wp_node($xpath, $ctx, $tag);
}

function wp_meta(DOMXPath $xpath, DOMNode $ctx, string $key): string {
    $nodes = $xpath->query('wp:postmeta[wp:meta_key="' . $key . '"]/wp:meta_value', $ctx);
    return $nodes && $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
}

function wp_slugify(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    return substr($text, 0, 200);
}

function wp_ensure_folder(int $collectionId, string $slug, string $name): array {
    $existing = OutpostDB::fetchOne(
        'SELECT * FROM folders WHERE collection_id = ? AND slug = ?',
        [$collectionId, $slug]
    );
    if ($existing) return $existing;

    $id = OutpostDB::insert('folders', [
        'collection_id' => $collectionId,
        'slug'          => $slug,
        'name'          => $name,
        'type'          => 'flat',
    ]);
    return ['id' => $id, 'slug' => $slug, 'name' => $name];
}

/**
 * Backward-compat alias for wp_ensure_folder().
 */
function wp_ensure_taxonomy(int $collectionId, string $slug, string $name): array {
    return wp_ensure_folder($collectionId, $slug, $name);
}

function wp_assign_categories(int $itemId, int $folderId, array $categories): void {
    $labelIds = [];
    foreach ($categories as $cat) {
        $existing = OutpostDB::fetchOne(
            'SELECT id FROM labels WHERE folder_id = ? AND slug = ?',
            [$folderId, $cat['slug']]
        );
        if ($existing) {
            $labelIds[] = (int) $existing['id'];
        } else {
            $labelIds[] = OutpostDB::insert('labels', [
                'folder_id' => $folderId,
                'slug'      => $cat['slug'],
                'name'      => $cat['name'],
                'data'      => '{}',
            ]);
        }
    }

    // Replace item's category labels
    OutpostDB::delete('item_labels', 'item_id = ?', [$itemId]);
    foreach ($labelIds as $lid) {
        OutpostDB::query(
            'INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)',
            [$itemId, $lid]
        );
    }
}
