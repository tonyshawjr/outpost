<?php
/**
 * Outpost — Schema iteration safety: "Unknown fields found" panel
 * (v6 Section 1.7 — Sanity-style)
 *
 * When a developer changes a collection's schema (renames a field, drops one,
 * etc.) any item rows that still hold the old field show that field as
 * orphaned data — present in `data` JSON but absent from the schema.
 *
 * This module surfaces those orphans, along with two one-click resolutions:
 *   1. Promote — add the orphan key back into the schema as a `text` field.
 *   2. Strip   — remove the orphan key from every item's data.
 *
 * No data is ever destroyed silently. Strip is logged in the revisions table
 * before deletion so it is recoverable if needed.
 */

function _collection_schema_keys(array $collection): array {
    $schemaRaw = $collection['schema'] ?? '{}';
    $schema = is_array($schemaRaw) ? $schemaRaw : json_decode((string) $schemaRaw, true);
    if (!is_array($schema)) return [];
    $fields = $schema['fields'] ?? $schema;
    if (!is_array($fields)) return [];
    $keys = [];
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $key = $f['key'] ?? null;
        if ($key) $keys[] = (string) $key;
        // Object field: include children with prefixed keys so partial
        // matches don't mis-flag nested fields.
        if (($f['type'] ?? '') === 'object' && is_array($f['fields'] ?? null)) {
            foreach ($f['fields'] as $cf) {
                if (is_array($cf) && !empty($cf['key'])) {
                    $keys[] = $key . '.' . $cf['key'];
                }
            }
        }
    }
    return $keys;
}

/**
 * Scan every item in a collection for keys not in the schema.
 * Returns: [
 *   'collection' => slug,
 *   'orphans' => [
 *      'extra_key' => ['count' => 3, 'sample' => [item_id, ...up-to-5]]
 *   ],
 *   'item_count' => total items scanned
 * ]
 */
function schema_orphans_for_collection(string $slug): array {
    $col = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$col) return ['collection' => $slug, 'orphans' => [], 'item_count' => 0];

    $known = array_flip(_collection_schema_keys($col));
    // Always allow these system keys
    foreach (['title', 'slug', 'status', 'published_at'] as $sys) $known[$sys] = true;

    $items = OutpostDB::fetchAll(
        'SELECT id, data FROM collection_items WHERE collection_id = ?',
        [$col['id']]
    );

    $orphans = [];
    foreach ($items as $item) {
        $data = json_decode((string) $item['data'], true);
        if (!is_array($data)) continue;
        foreach (array_keys($data) as $key) {
            if (isset($known[$key])) continue;
            if (!isset($orphans[$key])) {
                $orphans[$key] = ['count' => 0, 'sample' => []];
            }
            $orphans[$key]['count']++;
            if (count($orphans[$key]['sample']) < 5) {
                $orphans[$key]['sample'][] = (int) $item['id'];
            }
        }
    }

    return [
        'collection'  => $slug,
        'orphans'     => $orphans,
        'item_count'  => count($items),
    ];
}

/**
 * Add an orphan key to the collection schema as a basic text field.
 */
function schema_orphans_promote(string $slug, string $key): bool {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $key)) return false;
    $col = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$col) return false;
    $schemaRaw = $col['schema'] ?: '{}';
    $schema = json_decode($schemaRaw, true);
    if (!is_array($schema)) $schema = [];
    if (!isset($schema['fields']) || !is_array($schema['fields'])) {
        $schema['fields'] = [];
    }
    foreach ($schema['fields'] as $f) {
        if (is_array($f) && ($f['key'] ?? null) === $key) return true;
    }
    $schema['fields'][] = [
        'key'   => $key,
        'label' => ucwords(str_replace(['_', '-', '.'], ' ', $key)),
        'type'  => 'text',
    ];
    OutpostDB::update('collections', [
        'schema' => json_encode($schema),
    ], 'id = ?', [$col['id']]);
    return true;
}

/**
 * Remove an orphan key from every item's data in this collection.
 * Stores a snapshot in revisions so it's recoverable.
 */
function schema_orphans_strip(string $slug, string $key): int {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $key)) return 0;
    $col = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug]);
    if (!$col) return 0;

    $items = OutpostDB::fetchAll(
        'SELECT id, data FROM collection_items WHERE collection_id = ?',
        [$col['id']]
    );
    $removed = 0;
    $userId = (int) (OutpostAuth::currentUser()['id'] ?? 0);

    foreach ($items as $item) {
        $data = json_decode((string) $item['data'], true);
        if (!is_array($data) || !array_key_exists($key, $data)) continue;

        // Snapshot pre-strip
        OutpostDB::insert('revisions', [
            'entity_type' => 'collection_item.strip_field',
            'entity_id'   => (int) $item['id'],
            'data'        => json_encode($data),
            'meta'        => json_encode(['stripped_key' => $key, 'collection' => $slug]),
            'created_by'  => $userId ?: null,
        ]);

        unset($data[$key]);
        OutpostDB::update('collection_items', [
            'data' => json_encode($data),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$item['id']]);
        $removed++;
    }
    return $removed;
}

// ── API handlers ─────────────────────────────────────────────────────────

function handle_schema_orphans_list(): void {
    $slug = trim((string) ($_GET['collection'] ?? ''));
    if ($slug === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        json_response(['error' => 'collection slug required'], 400);
        return;
    }
    json_response(schema_orphans_for_collection($slug));
}

function handle_schema_orphans_promote(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $slug = trim((string) ($body['collection'] ?? ''));
    $key  = trim((string) ($body['key'] ?? ''));
    if ($slug === '' || $key === '') {
        json_response(['error' => 'collection and key required'], 400);
        return;
    }
    if (!schema_orphans_promote($slug, $key)) {
        json_response(['error' => 'Could not promote field'], 400);
        return;
    }
    json_response(['success' => true]);
}

function handle_schema_orphans_strip(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $slug = trim((string) ($body['collection'] ?? ''));
    $key  = trim((string) ($body['key'] ?? ''));
    if ($slug === '' || $key === '') {
        json_response(['error' => 'collection and key required'], 400);
        return;
    }
    $removed = schema_orphans_strip($slug, $key);
    json_response(['success' => true, 'removed_from' => $removed]);
}
