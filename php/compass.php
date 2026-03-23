<?php
/**
 * Outpost CMS — Compass Engine
 * Faceted search and filtering system for collections.
 * Public API endpoints at api.php?action=compass/...
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── CORS & security headers (wide open for public API) ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Response helpers ────────────────────────────────────
function compass_response(mixed $data, ?array $extra = null): void {
    http_response_code(200);
    $out = $data;
    if ($extra !== null) $out = array_merge($out, $extra);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

function compass_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

function compass_param(string $key, int $maxLen = 255): ?string {
    $val = $_GET[$key] ?? null;
    if ($val === null) return null;
    $val = trim((string) $val);
    if ($val === '' || mb_strlen($val) > $maxLen) return null;
    return $val;
}

// ── Schema bootstrap ────────────────────────────────────
function compass_ensure_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS compass_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            facet_name TEXT NOT NULL,
            facet_value TEXT NOT NULL,
            facet_display TEXT,
            facet_parent TEXT,
            numeric_value REAL,
            sort_order INTEGER DEFAULT 0,
            FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES collection_items(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_compass_lookup ON compass_index(collection_id, facet_name, facet_value);
        CREATE INDEX IF NOT EXISTS idx_compass_item ON compass_index(item_id, facet_name);
        CREATE INDEX IF NOT EXISTS idx_compass_numeric ON compass_index(collection_id, facet_name, numeric_value);
    ");
}

// ── Indexing functions ──────────────────────────────────

/**
 * Rebuild the entire compass index for a collection.
 */
function compass_reindex(int $collectionId): int {
    compass_ensure_tables();
    $db = OutpostDB::connect();

    // Verify collection exists
    $coll = OutpostDB::fetchOne('SELECT id, schema FROM collections WHERE id = ?', [$collectionId]);
    if (!$coll) return 0;

    $schema = json_decode($coll['schema'] ?? '{}', true) ?: [];

    $db->exec('BEGIN IMMEDIATE');
    try {
        // Clear existing entries
        OutpostDB::query('DELETE FROM compass_index WHERE collection_id = ?', [$collectionId]);

        // Fetch all published items
        $items = OutpostDB::fetchAll(
            'SELECT id, data FROM collection_items WHERE collection_id = ? AND status = ?',
            [$collectionId, 'published']
        );

        $count = 0;
        foreach ($items as $item) {
            $count += compass_index_item_internal($item['id'], $collectionId, $item['data'], $schema);
        }

        $db->exec('COMMIT');
        return $count;
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Index a single item (called on create/update).
 */
function compass_index_item(int $itemId): void {
    compass_ensure_tables();

    $item = OutpostDB::fetchOne(
        'SELECT ci.id, ci.collection_id, ci.data, ci.status, c.schema
         FROM collection_items ci
         JOIN collections c ON ci.collection_id = c.id
         WHERE ci.id = ?',
        [$itemId]
    );
    if (!$item) return;

    // Only index published items
    if ($item['status'] !== 'published') {
        compass_remove_item($itemId);
        return;
    }

    $schema = json_decode($item['schema'] ?? '{}', true) ?: [];

    // Remove old entries and re-index
    OutpostDB::query('DELETE FROM compass_index WHERE item_id = ?', [$itemId]);
    compass_index_item_internal($itemId, (int) $item['collection_id'], $item['data'], $schema);
}

/**
 * Remove all index entries for an item (called on delete).
 */
function compass_remove_item(int $itemId): void {
    compass_ensure_tables();
    OutpostDB::query('DELETE FROM compass_index WHERE item_id = ?', [$itemId]);
}

/**
 * Internal: index a single item's data + folder labels.
 * Returns the number of index entries created.
 */
function compass_index_item_internal(int $itemId, int $collectionId, string $dataJson, array $schema): int {
    $data = json_decode($dataJson, true) ?: [];
    $count = 0;

    // 1. Index folder label assignments
    $labels = OutpostDB::fetchAll(
        'SELECT l.slug AS label_slug, l.name AS label_name, l.parent_id,
                f.slug AS folder_slug, f.name AS folder_name
         FROM item_labels il
         JOIN labels l ON il.label_id = l.id
         JOIN folders f ON l.folder_id = f.id
         WHERE il.item_id = ?',
        [$itemId]
    );

    // Build parent lookup for hierarchical labels
    $parentMap = [];
    if (!empty($labels)) {
        $labelIds = array_unique(array_filter(array_column($labels, 'parent_id')));
        if (!empty($labelIds)) {
            $placeholders = implode(',', array_fill(0, count($labelIds), '?'));
            $parents = OutpostDB::fetchAll(
                "SELECT id, slug FROM labels WHERE id IN ($placeholders)",
                $labelIds
            );
            foreach ($parents as $p) {
                $parentMap[(int) $p['id']] = $p['slug'];
            }
        }
    }

    foreach ($labels as $label) {
        $parentSlug = null;
        if ($label['parent_id']) {
            $parentSlug = $parentMap[(int) $label['parent_id']] ?? null;
        }

        OutpostDB::insert('compass_index', [
            'collection_id' => $collectionId,
            'item_id'       => $itemId,
            'facet_name'    => $label['folder_slug'],
            'facet_value'   => $label['label_slug'],
            'facet_display'  => $label['label_name'],
            'facet_parent'  => $parentSlug,
            'numeric_value' => null,
            'sort_order'    => 0,
        ]);
        $count++;
    }

    // 2. Index schema fields (text, select, number, date)
    $indexableTypes = ['text', 'select', 'number', 'date', 'textarea'];
    // Support both flat format {fieldName: {type}} and array format [{name, type}]
    $fields = [];
    if (isset($schema['fields']) && is_array($schema['fields']) && isset($schema['fields'][0]['name'])) {
        // Array format: [{name: "city", type: "select"}]
        foreach ($schema['fields'] as $f) {
            $fields[$f['name']] = $f;
        }
    } else {
        // Flat format: {city: {type: "select", label: "City"}}
        $fields = $schema;
    }
    if (is_array($fields)) {
        foreach ($fields as $fieldName => $field) {
            // Skip if fieldName is numeric (shouldn't happen in flat format)
            if (is_int($fieldName)) {
                $fieldName = $field['name'] ?? null;
                if (!$fieldName) continue;
            }
            $fieldType = $field['type'] ?? 'text';
            if (!in_array($fieldType, $indexableTypes)) continue;

            $value = $data[$fieldName] ?? null;
            if ($value === null || $value === '') continue;

            // For select fields with multiple values (comma-separated)
            $values = ($fieldType === 'select' && str_contains((string) $value, ','))
                ? array_map('trim', explode(',', (string) $value))
                : [(string) $value];

            foreach ($values as $v) {
                if ($v === '') continue;

                $numericVal = null;
                if ($fieldType === 'number' && is_numeric($v)) {
                    $numericVal = (float) $v;
                }

                $display = $v;
                // For select fields, try to find a display label from options
                if ($fieldType === 'select' && isset($field['options'])) {
                    $opts = is_string($field['options']) ? json_decode($field['options'], true) : $field['options'];
                    if (is_array($opts)) {
                        foreach ($opts as $opt) {
                            if (is_array($opt) && ($opt['value'] ?? '') === $v) {
                                $display = $opt['label'] ?? $v;
                                break;
                            }
                        }
                    }
                }

                OutpostDB::insert('compass_index', [
                    'collection_id' => $collectionId,
                    'item_id'       => $itemId,
                    'facet_name'    => $fieldName,
                    'facet_value'   => $v,
                    'facet_display'  => $display,
                    'facet_parent'  => null,
                    'numeric_value' => $numericVal,
                    'sort_order'    => 0,
                ]);
                $count++;
            }
        }
    }

    return $count;
}

// ── Router ──────────────────────────────────────────────
function handle_compass_request(string $action, string $method): void {
    // Rate limit: 120 requests per 60 seconds per IP
    outpost_ip_rate_limit('compass_api', 120, 60);

    compass_ensure_tables();

    match ($action) {
        'compass/values'    => $method === 'GET' ? handle_compass_values() : compass_error('Method not allowed', 405),
        'compass/filter'    => $method === 'GET' ? handle_compass_filter() : compass_error('Method not allowed', 405),
        'compass/proximity' => $method === 'GET' ? handle_compass_proximity() : compass_error('Method not allowed', 405),
        'compass/hierarchy' => $method === 'GET' ? handle_compass_hierarchy() : compass_error('Method not allowed', 405),
        'compass/reindex'   => $method === 'POST' ? handle_compass_reindex() : compass_error('Method not allowed', 405),
        default             => compass_error('Not found', 404),
    };
}

// ── Resolve collection by slug ──────────────────────────
function compass_resolve_collection(string $slug): array {
    $coll = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$coll) compass_error('Collection not found', 404);
    return $coll;
}

// ── GET compass/values ──────────────────────────────────
function handle_compass_values(): void {
    $collSlug = compass_param('collection');
    $facetName = compass_param('facet');
    if (!$collSlug || !$facetName) compass_error('collection and facet parameters are required');

    $coll = compass_resolve_collection($collSlug);

    // Auto-reindex if table is empty for this collection
    compass_auto_reindex_if_empty((int) $coll['id']);

    $rows = OutpostDB::fetchAll(
        'SELECT facet_value AS value, facet_display AS display, COUNT(*) AS count
         FROM compass_index
         WHERE collection_id = ? AND facet_name = ?
         GROUP BY facet_value
         ORDER BY count DESC, facet_display ASC',
        [(int) $coll['id'], $facetName]
    );

    $total = 0;
    foreach ($rows as &$row) {
        $row['count'] = (int) $row['count'];
        $total += $row['count'];
    }
    unset($row);

    compass_response([
        'data'  => $rows,
        'facet' => $facetName,
        'total' => $total,
    ]);
}

// ── GET compass/filter ──────────────────────────────────
function handle_compass_filter(): void {
    $collSlug = compass_param('collection');
    if (!$collSlug) compass_error('collection parameter is required');

    $coll = compass_resolve_collection($collSlug);
    $collId = (int) $coll['id'];

    // Auto-reindex if table is empty for this collection
    compass_auto_reindex_if_empty($collId);

    // Parse known params
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 12)));
    $q = compass_param('q', 500);
    $sortParam = compass_param('sort');

    // Everything else is a facet filter
    $reservedParams = ['collection', 'page', 'per_page', 'q', 'sort', 'action'];
    $facetFilters = [];
    foreach ($_GET as $key => $val) {
        if (in_array($key, $reservedParams)) continue;
        $val = trim((string) $val);
        if ($val === '') continue;
        // Multiple values = comma-separated → OR within this facet
        $facetFilters[$key] = array_map('trim', explode(',', $val));
    }

    // Get all facet names for this collection (for building counts later)
    $allFacetNames = array_column(OutpostDB::fetchAll(
        'SELECT DISTINCT facet_name FROM compass_index WHERE collection_id = ?',
        [$collId]
    ), 'facet_name');

    // Build the base query: find item IDs matching all facet filters (AND between facets)
    $baseWhere = ['ci.collection_id = ?', "ci.status = 'published'"];
    $baseParams = [$collId];

    // Text search on data JSON
    if ($q) {
        $baseWhere[] = 'ci.data LIKE ?';
        $baseParams[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    }

    // Facet joins: each facet adds an EXISTS subquery (AND between facets, OR within)
    foreach ($facetFilters as $facetName => $values) {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $baseWhere[] = "EXISTS (
            SELECT 1 FROM compass_index cx
            WHERE cx.item_id = ci.id AND cx.facet_name = ? AND cx.facet_value IN ($placeholders)
        )";
        $baseParams[] = $facetName;
        array_push($baseParams, ...$values);
    }

    $whereClause = implode(' AND ', $baseWhere);

    // Count total matches
    $totalRow = OutpostDB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM collection_items ci WHERE $whereClause",
        $baseParams
    );
    $total = (int) ($totalRow['cnt'] ?? 0);
    $pages = max(1, (int) ceil($total / $perPage));

    // Sort
    $orderBy = 'ci.created_at DESC';
    if ($sortParam) {
        $parts = explode(':', $sortParam);
        $sortField = preg_replace('/[^a-zA-Z0-9_]/', '', $parts[0]);
        $sortDir = (isset($parts[1]) && strtolower($parts[1]) === 'asc') ? 'ASC' : 'DESC';

        // Check if sorting by a data field (JSON) or a column
        $columnNames = ['id', 'slug', 'status', 'sort_order', 'created_at', 'updated_at', 'published_at'];
        if (in_array($sortField, $columnNames)) {
            $orderBy = "ci.$sortField $sortDir";
        } else {
            // Sort by JSON field
            $orderBy = "json_extract(ci.data, '\$.$sortField') $sortDir";
        }
    }

    // Fetch paginated items
    $offset = ($page - 1) * $perPage;
    $items = OutpostDB::fetchAll(
        "SELECT ci.* FROM collection_items ci WHERE $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?",
        [...$baseParams, $perPage, $offset]
    );

    // Decode data JSON for each item
    $resultItems = [];
    foreach ($items as $item) {
        $itemData = json_decode($item['data'] ?? '{}', true) ?: [];
        $resultItems[] = [
            'id'           => (int) $item['id'],
            'slug'         => $item['slug'],
            'status'       => $item['status'],
            'data'         => $itemData,
            'sort_order'   => (int) ($item['sort_order'] ?? 0),
            'created_at'   => $item['created_at'],
            'updated_at'   => $item['updated_at'],
            'published_at' => $item['published_at'],
        ];
    }

    // Compute facet counts — for each facet, exclude its own filter (cross-count)
    $facetCounts = [];
    foreach ($allFacetNames as $facetName) {
        // Build WHERE excluding the current facet's filter
        $countWhere = ['ci.collection_id = ?', "ci.status = 'published'"];
        $countParams = [$collId];

        if ($q) {
            $countWhere[] = 'ci.data LIKE ?';
            $countParams[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        }

        foreach ($facetFilters as $fn => $values) {
            if ($fn === $facetName) continue; // Exclude this facet's own filter
            $ph = implode(',', array_fill(0, count($values), '?'));
            $countWhere[] = "EXISTS (
                SELECT 1 FROM compass_index cx
                WHERE cx.item_id = ci.id AND cx.facet_name = ? AND cx.facet_value IN ($ph)
            )";
            $countParams[] = $fn;
            array_push($countParams, ...$values);
        }

        $countWhereClause = implode(' AND ', $countWhere);

        $vals = OutpostDB::fetchAll(
            "SELECT cxi.facet_value AS value, cxi.facet_display AS display, COUNT(DISTINCT cxi.item_id) AS count
             FROM compass_index cxi
             JOIN collection_items ci ON cxi.item_id = ci.id
             WHERE cxi.collection_id = ? AND cxi.facet_name = ?
               AND ci.id IN (SELECT ci.id FROM collection_items ci WHERE $countWhereClause)
             GROUP BY cxi.facet_value
             ORDER BY count DESC, cxi.facet_display ASC",
            [$collId, $facetName, ...$countParams]
        );

        foreach ($vals as &$v) {
            $v['count'] = (int) $v['count'];
        }
        unset($v);

        $facetCounts[$facetName] = $vals;
    }

    compass_response([
        'data'     => $resultItems,
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'facets'   => $facetCounts,
    ]);
}

// ── GET compass/proximity ───────────────────────────────
function handle_compass_proximity(): void {
    $collSlug = compass_param('collection');
    $lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
    $radius = isset($_GET['radius']) ? (float) $_GET['radius'] : 25;
    $unit = compass_param('unit') ?? 'miles';

    if (!$collSlug || $lat === null || $lng === null) {
        compass_error('collection, lat, and lng parameters are required');
    }

    // Validate coordinates
    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        compass_error('Invalid coordinates');
    }

    // Cap radius to a reasonable maximum (500 miles / 800 km)
    $maxRadius = ($unit === 'km') ? 800 : 500;
    $radius = max(0.1, min($radius, $maxRadius));
    if (!is_finite($radius)) $radius = 25;

    $coll = compass_resolve_collection($collSlug);
    $collId = (int) $coll['id'];

    // Earth radius: 3959 miles, 6371 km
    $earthRadius = ($unit === 'km') ? 6371 : 3959;

    // Fetch all published items that have latitude and longitude in their data
    $items = OutpostDB::fetchAll(
        "SELECT ci.*
         FROM collection_items ci
         WHERE ci.collection_id = ? AND ci.status = 'published'
           AND json_extract(ci.data, '\$.latitude') IS NOT NULL
           AND json_extract(ci.data, '\$.longitude') IS NOT NULL",
        [$collId]
    );

    // Compute Haversine distance in PHP (SQLite lacks trig functions)
    $results = [];
    $latRad = deg2rad($lat);

    foreach ($items as $item) {
        $itemData = json_decode($item['data'] ?? '{}', true) ?: [];
        $itemLat = (float) ($itemData['latitude'] ?? 0);
        $itemLng = (float) ($itemData['longitude'] ?? 0);

        if ($itemLat == 0 && $itemLng == 0) continue;

        $itemLatRad = deg2rad($itemLat);
        $dLat = deg2rad($itemLat - $lat);
        $dLng = deg2rad($itemLng - $lng);

        $a = sin($dLat / 2) ** 2 + cos($latRad) * cos($itemLatRad) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        if ($distance <= $radius) {
            $results[] = [
                'id'           => (int) $item['id'],
                'slug'         => $item['slug'],
                'status'       => $item['status'],
                'data'         => $itemData,
                'distance'     => round($distance, 2),
                'sort_order'   => (int) ($item['sort_order'] ?? 0),
                'created_at'   => $item['created_at'],
                'updated_at'   => $item['updated_at'],
                'published_at' => $item['published_at'],
            ];
        }
    }

    // Sort by distance
    usort($results, fn($a, $b) => $a['distance'] <=> $b['distance']);

    // Paginate
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 12)));
    $total = count($results);
    $pages = max(1, (int) ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;
    $paged = array_slice($results, $offset, $perPage);

    compass_response([
        'data'     => $paged,
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'unit'     => $unit,
        'center'   => ['lat' => $lat, 'lng' => $lng],
        'radius'   => $radius,
    ]);
}

// ── GET compass/hierarchy ────────────────────────────────
function handle_compass_hierarchy(): void {
    $collSlug = compass_param('collection');
    $facetName = compass_param('facet');
    $parent = compass_param('parent');
    if (!$collSlug || !$facetName) compass_error('collection and facet parameters are required');

    $coll = compass_resolve_collection($collSlug);
    $collId = (int) $coll['id'];

    // Auto-reindex if empty
    compass_auto_reindex_if_empty($collId);

    if ($parent) {
        // Fetch child values where facet_parent matches the given parent
        $rows = OutpostDB::fetchAll(
            'SELECT DISTINCT facet_value AS value, facet_display AS label
             FROM compass_index
             WHERE collection_id = ? AND facet_name = ? AND facet_parent = ?
             ORDER BY facet_display ASC',
            [$collId, $facetName, $parent]
        );
    } else {
        // Fetch top-level values (no parent)
        $rows = OutpostDB::fetchAll(
            'SELECT DISTINCT facet_value AS value, facet_display AS label
             FROM compass_index
             WHERE collection_id = ? AND facet_name = ? AND (facet_parent IS NULL OR facet_parent = ?)
             ORDER BY facet_display ASC',
            [$collId, $facetName, '']
        );
    }

    compass_response(['data' => $rows]);
}

// ── POST compass/reindex (admin only) ───────────────────
function handle_compass_reindex(): void {
    // Require admin auth
    require_once __DIR__ . '/auth.php';
    OutpostAuth::requireAuth();

    $collSlug = compass_param('collection');
    if (!$collSlug) {
        // Try JSON body
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $collSlug = $body['collection'] ?? null;
    }
    if (!$collSlug) compass_error('collection parameter is required');

    $coll = compass_resolve_collection($collSlug);
    $count = compass_reindex((int) $coll['id']);

    compass_response([
        'success'    => true,
        'collection' => $collSlug,
        'indexed'    => $count,
    ]);
}

// ── Auto-reindex if the collection has no compass entries ──
function compass_auto_reindex_if_empty(int $collectionId): void {
    $check = OutpostDB::fetchOne(
        'SELECT COUNT(*) AS cnt FROM compass_index WHERE collection_id = ?',
        [$collectionId]
    );
    if (((int) ($check['cnt'] ?? 0)) === 0) {
        // Check if the collection has published items (avoid indexing empty collections)
        $hasItems = OutpostDB::fetchOne(
            "SELECT COUNT(*) AS cnt FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$collectionId]
        );
        if (((int) ($hasItems['cnt'] ?? 0)) > 0) {
            compass_reindex($collectionId);
        }
    }
}
