<?php
/**
 * Outpost CMS — Collection URL Router
 * Handles clean URLs for collection items.
 * Include from .htaccess rewrite or from front controller.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class OutpostRouter {
    public static function resolve(): ?array {
        if (!file_exists(OUTPOST_DB_PATH)) return null;

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = '/' . trim($path, '/');

        // Check all collections for URL pattern matches
        $collections = OutpostDB::fetchAll('SELECT * FROM collections WHERE url_pattern != ""');

        foreach ($collections as $collection) {
            $pattern = $collection['url_pattern'];
            // Convert URL pattern to regex: /{slug}/{slug} → /collection-slug/([a-z0-9-]+)
            $collectionSlug = $collection['slug'];
            $regex = str_replace(
                ['{slug}'],
                ['([a-z0-9-]+)'],
                $pattern
            );
            // Replace the first part with the actual collection slug
            $regex = preg_replace('/^\/[a-z0-9-]+/', '/' . preg_quote($collectionSlug, '/'), $regex);
            $regex = '/^' . str_replace('/', '\/', $regex) . '$/';

            if (preg_match($regex, $path, $matches)) {
                $itemSlug = $matches[1] ?? null;
                if ($itemSlug) {
                    $item = OutpostDB::fetchOne(
                        'SELECT * FROM collection_items WHERE collection_id = ? AND slug = ?',
                        [$collection['id'], $itemSlug]
                    );

                    if ($item) {
                        return [
                            'collection' => $collection,
                            'item' => $item,
                            'data' => json_decode($item['data'], true) ?: [],
                        ];
                    }
                }
            }
        }

        return null;
    }

    public static function handleRequest(): void {
        $match = self::resolve();
        if (!$match) {
            http_response_code(404);
            return;
        }

        $collection = $match['collection'];
        $templatePath = $collection['template_path'];

        if ($templatePath && file_exists($templatePath)) {
            // Set globals for the template
            $GLOBALS['outpost_collection'] = $collection;
            $GLOBALS['outpost_item'] = $match['item'];
            $GLOBALS['outpost_data'] = $match['data'];

            require $templatePath;
        }
    }

    /**
     * Generate .htaccess rules for collection routing.
     */
    public static function generateHtaccess(): string {
        $rules = "# Outpost CMS Collection Routing\n";
        $rules .= "RewriteEngine On\n\n";

        $collections = OutpostDB::fetchAll('SELECT * FROM collections WHERE url_pattern != ""');

        foreach ($collections as $collection) {
            $slug = $collection['slug'];
            $rules .= "# {$collection['name']}\n";
            $rules .= "RewriteRule ^{$slug}/([a-z0-9-]+)/?$ router.php?collection={$slug}&slug=$1 [L,QSA]\n\n";
        }

        return $rules;
    }
}

// Auto-handle if accessed directly via rewrite
if (isset($_GET['collection']) && isset($_GET['slug'])) {
    OutpostRouter::handleRequest();
}
