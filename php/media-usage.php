<?php
/**
 * Outpost — Media usage tracking (v6 Section 7)
 *
 * Before deleting a media asset, scan content for references to its URL or
 * filename. Returns a list of pages and collection items that use this asset
 * so the admin UI can warn before destroying linked content.
 *
 * Searches:
 *   - collection_items.data (JSON) — recursive string match
 *   - page_blocks.fields    (JSON) — recursive string match
 *   - fields.content                — basic LIKE
 *
 * The match is filename-based (NOT just exact URL) so resized/derived URLs
 * still register a hit. False-positive risk is acceptable — we'd rather
 * warn-and-let-the-user-confirm than silently break a page.
 */

function media_usage_scan(int $mediaId): array {
    $media = OutpostDB::fetchOne('SELECT id, filename, path, original_name FROM media WHERE id = ?', [$mediaId]);
    if (!$media) return ['references' => [], 'count' => 0];

    $needles = array_filter([
        $media['filename'] ?? '',
        $media['path'] ?? '',
        basename((string) ($media['path'] ?? '')),
        $media['original_name'] ?? '',
    ]);
    $needles = array_values(array_unique($needles));
    if (!$needles) return ['references' => [], 'count' => 0];

    $hits = [];

    // Collection items
    $items = OutpostDB::fetchAll('SELECT ci.id, ci.slug, ci.data, c.slug AS coll_slug, c.name AS coll_name
                                    FROM collection_items ci
                                    JOIN collections c ON c.id = ci.collection_id');
    foreach ($items as $row) {
        $blob = (string) ($row['data'] ?? '');
        foreach ($needles as $n) {
            if ($n !== '' && strpos($blob, $n) !== false) {
                $hits[] = [
                    'kind'           => 'collection_item',
                    'collection'     => $row['coll_slug'],
                    'collection_name'=> $row['coll_name'],
                    'item_id'        => (int) $row['id'],
                    'item_slug'      => $row['slug'],
                    'edit_route'     => '#/collections/' . $row['coll_slug'] . '/' . (int) $row['id'],
                ];
                break;
            }
        }
    }

    // Page blocks
    $blocks = OutpostDB::fetchAll(
        'SELECT pb.id, pb.page_id, pb.block_slug, pb.fields, p.path
           FROM page_blocks pb LEFT JOIN pages p ON p.id = pb.page_id'
    );
    foreach ($blocks as $row) {
        $blob = (string) ($row['fields'] ?? '');
        foreach ($needles as $n) {
            if ($n !== '' && strpos($blob, $n) !== false) {
                $hits[] = [
                    'kind'        => 'page_block',
                    'page_id'     => (int) $row['page_id'],
                    'page_path'   => $row['path'],
                    'block_id'    => (int) $row['id'],
                    'block_slug'  => $row['block_slug'],
                    'edit_route'  => '#/page-builder/' . (int) $row['page_id'] . '?block=' . (int) $row['id'],
                ];
                break;
            }
        }
    }

    // Page-level fields
    $fields = OutpostDB::fetchAll(
        'SELECT f.page_id, f.field_name, f.content, p.path
           FROM fields f LEFT JOIN pages p ON p.id = f.page_id'
    );
    foreach ($fields as $row) {
        $blob = (string) ($row['content'] ?? '');
        foreach ($needles as $n) {
            if ($n !== '' && strpos($blob, $n) !== false) {
                $hits[] = [
                    'kind'       => 'page_field',
                    'page_id'    => (int) $row['page_id'],
                    'page_path'  => $row['path'],
                    'field_name' => $row['field_name'],
                    'edit_route' => '#/pages/' . (int) $row['page_id'] . '?focus=' . urlencode($row['field_name']),
                ];
                break;
            }
        }
    }

    return [
        'media_id'   => (int) $mediaId,
        'count'      => count($hits),
        'references' => $hits,
    ];
}

function handle_media_usage(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'id required'], 400);
        return;
    }
    json_response(media_usage_scan($id));
}
