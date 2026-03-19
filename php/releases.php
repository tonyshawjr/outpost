<?php
/**
 * Outpost CMS — Releases
 * Bundle multiple content changes and publish them all at once.
 */

// ── Database Migration ──────────────────────────────────

function ensure_releases_tables(): void {
    $db = OutpostDB::connect();

    $db->exec("
        CREATE TABLE IF NOT EXISTS releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft',
            created_by INTEGER NOT NULL,
            published_at TEXT,
            published_by INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS release_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            entity_name TEXT DEFAULT '',
            action TEXT NOT NULL,
            snapshot_before TEXT,
            snapshot_after TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (release_id) REFERENCES releases(id) ON DELETE CASCADE
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_release_changes_release ON release_changes(release_id)");
}

// ── Handlers ────────────────────────────────────────────

function handle_releases_list(): void {
    $rows = OutpostDB::fetchAll("
        SELECT r.*, COUNT(rc.id) as change_count, u.display_name as created_by_name,
               pu.display_name as published_by_name
        FROM releases r
        LEFT JOIN release_changes rc ON rc.release_id = r.id
        LEFT JOIN users u ON u.id = r.created_by
        LEFT JOIN users pu ON pu.id = r.published_by
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    json_response(['releases' => $rows]);
}

function handle_release_get(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $release = OutpostDB::fetchOne("
        SELECT r.*, u.display_name as created_by_name,
               pu.display_name as published_by_name
        FROM releases r
        LEFT JOIN users u ON u.id = r.created_by
        LEFT JOIN users pu ON pu.id = r.published_by
        WHERE r.id = ?
    ", [$id]);
    if (!$release) json_error('Release not found', 404);

    $changes = OutpostDB::fetchAll("
        SELECT * FROM release_changes
        WHERE release_id = ?
        ORDER BY created_at ASC
    ", [$id]);

    $release['changes'] = $changes;
    $release['change_count'] = count($changes);
    json_response($release);
}

function handle_release_create(): void {
    $body = get_json_body();
    $name = trim($body['name'] ?? '');
    if (!$name) json_error('Name is required');
    if (mb_strlen($name) > 200) json_error('Name must be 200 characters or fewer');

    $description = trim($body['description'] ?? '');
    if (mb_strlen($description) > 2000) json_error('Description must be 2000 characters or fewer');
    $userId = OutpostAuth::getUserId();

    $id = OutpostDB::insert('releases', [
        'name' => $name,
        'description' => $description,
        'created_by' => $userId,
    ]);

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    json_response($release, 201);
}

function handle_release_update(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    if (!$release) json_error('Release not found', 404);
    if ($release['status'] !== 'draft') json_error('Only draft releases can be edited');

    $body = get_json_body();
    $updates = [];
    if (isset($body['name'])) $updates['name'] = trim($body['name']);
    if (isset($body['description'])) $updates['description'] = trim($body['description']);
    if (empty($updates)) json_error('Nothing to update');

    $updates['updated_at'] = date('Y-m-d H:i:s');
    OutpostDB::update('releases', $updates, 'id = ?', [$id]);

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    json_response($release);
}

function handle_release_delete(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    if (!$release) json_error('Release not found', 404);
    if ($release['status'] !== 'draft') json_error('Only draft releases can be deleted');

    OutpostDB::delete('releases', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_release_add_change(): void {
    $body = get_json_body();
    $releaseId = (int)($body['release_id'] ?? 0);
    if (!$releaseId) json_error('release_id is required');

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$releaseId]);
    if (!$release) json_error('Release not found', 404);
    if ($release['status'] !== 'draft') json_error('Cannot add changes to a non-draft release');

    $entityType = $body['entity_type'] ?? '';
    $entityId = (int)($body['entity_id'] ?? 0);
    $action = $body['action'] ?? '';
    $entityName = $body['entity_name'] ?? '';

    if (!$entityType || !$entityId || !$action) {
        json_error('entity_type, entity_id, and action are required');
    }
    if (!in_array($entityType, ['field', 'item', 'menu', 'page', 'collection', 'setting'])) {
        json_error('Invalid entity_type');
    }
    if (!in_array($action, ['create', 'update', 'delete'])) {
        json_error('Invalid action');
    }

    $snapshotBefore = isset($body['snapshot_before']) ? json_encode($body['snapshot_before']) : null;
    $snapshotAfter = isset($body['snapshot_after']) ? json_encode($body['snapshot_after']) : null;

    // Guard against oversized snapshots (1MB limit each)
    if ($snapshotBefore !== null && strlen($snapshotBefore) > 1048576) json_error('snapshot_before exceeds 1MB limit');
    if ($snapshotAfter !== null && strlen($snapshotAfter) > 1048576) json_error('snapshot_after exceeds 1MB limit');

    $id = OutpostDB::insert('release_changes', [
        'release_id' => $releaseId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_name' => $entityName,
        'action' => $action,
        'snapshot_before' => $snapshotBefore,
        'snapshot_after' => $snapshotAfter,
    ]);

    OutpostDB::update('releases', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$releaseId]);

    $change = OutpostDB::fetchOne("SELECT * FROM release_changes WHERE id = ?", [$id]);
    json_response($change, 201);
}

function handle_release_remove_change(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing change id');

    $change = OutpostDB::fetchOne("SELECT rc.*, r.status FROM release_changes rc JOIN releases r ON r.id = rc.release_id WHERE rc.id = ?", [$id]);
    if (!$change) json_error('Change not found', 404);
    if ($change['status'] !== 'draft') json_error('Cannot remove changes from a non-draft release');

    OutpostDB::delete('release_changes', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_release_publish(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_error('Missing release id');

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    if (!$release) json_error('Release not found', 404);
    if ($release['status'] !== 'draft') json_error('Only draft releases can be published');

    $changes = OutpostDB::fetchAll("SELECT * FROM release_changes WHERE release_id = ? ORDER BY id ASC", [$id]);
    if (empty($changes)) json_error('Cannot publish a release with no changes');

    $db = OutpostDB::connect();
    $db->beginTransaction();

    try {
        foreach ($changes as $change) {
            $entityType = $change['entity_type'];
            $entityId = $change['entity_id'];
            $action = $change['action'];
            $after = $change['snapshot_after'] ? json_decode($change['snapshot_after'], true) : null;

            // Capture current state as snapshot_before
            $currentState = release_get_entity_state($entityType, $entityId);
            if ($currentState !== null) {
                OutpostDB::update('release_changes',
                    ['snapshot_before' => json_encode($currentState)],
                    'id = ?', [$change['id']]
                );
            }

            // Apply the change
            release_apply_change($entityType, $entityId, $action, $after);
        }

        $userId = OutpostAuth::getUserId();
        OutpostDB::update('releases', [
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s'),
            'published_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        error_log('Release publish error (id=' . $id . '): ' . $e->getMessage());
        json_error('Failed to publish release. Check error log for details.');
    }

    // Clear template cache
    $cacheDir = OUTPOST_CACHE_DIR . 'templates/';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '*.php');
        foreach ($files as $f) @unlink($f);
    }

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    json_response(['success' => true, 'release' => $release]);
}

function handle_release_rollback(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_error('Missing release id');

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    if (!$release) json_error('Release not found', 404);
    if ($release['status'] !== 'published') json_error('Only published releases can be rolled back');

    $changes = OutpostDB::fetchAll("SELECT * FROM release_changes WHERE release_id = ? ORDER BY id DESC", [$id]);

    $db = OutpostDB::connect();
    $db->beginTransaction();

    try {
        foreach ($changes as $change) {
            $entityType = $change['entity_type'];
            $entityId = $change['entity_id'];
            $action = $change['action'];
            $before = $change['snapshot_before'] ? json_decode($change['snapshot_before'], true) : null;

            // Reverse the change
            switch ($action) {
                case 'update':
                    if ($before) {
                        release_apply_change($entityType, $entityId, 'update', $before);
                    }
                    break;
                case 'create':
                    // Delete what was created
                    release_apply_change($entityType, $entityId, 'delete', null);
                    break;
                case 'delete':
                    // Re-create what was deleted
                    if ($before) {
                        release_restore_entity($entityType, $before);
                    }
                    break;
            }
        }

        OutpostDB::update('releases', [
            'status' => 'rolled_back',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        error_log('Release rollback error (id=' . $id . '): ' . $e->getMessage());
        json_error('Failed to rollback release. Check error log for details.');
    }

    // Clear template cache
    $cacheDir = OUTPOST_CACHE_DIR . 'templates/';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '*.php');
        foreach ($files as $f) @unlink($f);
    }

    $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
    json_response(['success' => true, 'release' => $release]);
}

// ── Entity State Helpers ────────────────────────────────

function release_get_entity_state(string $entityType, int $entityId): ?array {
    switch ($entityType) {
        case 'field':
            return OutpostDB::fetchOne("SELECT * FROM fields WHERE id = ?", [$entityId]);
        case 'item':
            return OutpostDB::fetchOne("SELECT * FROM collection_items WHERE id = ?", [$entityId]);
        case 'menu':
            return OutpostDB::fetchOne("SELECT * FROM menus WHERE id = ?", [$entityId]);
        case 'page':
            return OutpostDB::fetchOne("SELECT * FROM pages WHERE id = ?", [$entityId]);
        case 'collection':
            return OutpostDB::fetchOne("SELECT * FROM collections WHERE id = ?", [$entityId]);
        case 'setting':
            // Settings use key-based lookup; entity_id maps to a synthetic id
            return null;
        default:
            return null;
    }
}

function release_apply_change(string $entityType, int $entityId, string $action, ?array $data): void {
    switch ($entityType) {
        case 'field':
            if ($action === 'update' && $data) {
                $updateData = array_intersect_key($data, array_flip(['content', 'field_type', 'default_value', 'options', 'sort_order']));
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                OutpostDB::update('fields', $updateData, 'id = ?', [$entityId]);
            } elseif ($action === 'delete') {
                OutpostDB::delete('fields', 'id = ?', [$entityId]);
            }
            break;

        case 'item':
            if ($action === 'update' && $data) {
                $updateData = array_intersect_key($data, array_flip(['slug', 'status', 'data', 'sort_order', 'published_at']));
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                OutpostDB::update('collection_items', $updateData, 'id = ?', [$entityId]);
            } elseif ($action === 'create' && $data) {
                // For create, the entity_id was pre-assigned but doesn't exist yet
                $insertData = array_intersect_key($data, array_flip(['collection_id', 'slug', 'status', 'data', 'sort_order']));
                OutpostDB::insert('collection_items', $insertData);
            } elseif ($action === 'delete') {
                OutpostDB::delete('collection_items', 'id = ?', [$entityId]);
            }
            break;

        case 'menu':
            if ($action === 'update' && $data) {
                $updateData = array_intersect_key($data, array_flip(['name', 'slug', 'items']));
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                OutpostDB::update('menus', $updateData, 'id = ?', [$entityId]);
            } elseif ($action === 'delete') {
                OutpostDB::delete('menus', 'id = ?', [$entityId]);
            }
            break;

        case 'page':
            if ($action === 'update' && $data) {
                $updateData = array_intersect_key($data, array_flip(['title', 'meta_title', 'meta_description', 'visibility']));
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                OutpostDB::update('pages', $updateData, 'id = ?', [$entityId]);
            } elseif ($action === 'delete') {
                OutpostDB::delete('pages', 'id = ?', [$entityId]);
            }
            break;

        case 'collection':
            if ($action === 'update' && $data) {
                $updateData = array_intersect_key($data, array_flip(['name', 'singular_name', 'schema', 'url_pattern', 'template_path', 'sort_field', 'sort_direction', 'items_per_page']));
                OutpostDB::update('collections', $updateData, 'id = ?', [$entityId]);
            } elseif ($action === 'delete') {
                OutpostDB::delete('collections', 'id = ?', [$entityId]);
            }
            break;
    }
}

function release_restore_entity(string $entityType, array $data): void {
    switch ($entityType) {
        case 'field':
            $insertData = array_intersect_key($data, array_flip(['page_id', 'theme', 'field_name', 'field_type', 'content', 'default_value', 'options', 'sort_order']));
            OutpostDB::insert('fields', $insertData);
            break;
        case 'item':
            $insertData = array_intersect_key($data, array_flip(['collection_id', 'slug', 'status', 'data', 'sort_order', 'published_at']));
            OutpostDB::insert('collection_items', $insertData);
            break;
        case 'menu':
            $insertData = array_intersect_key($data, array_flip(['name', 'slug', 'items']));
            OutpostDB::insert('menus', $insertData);
            break;
        case 'page':
            $insertData = array_intersect_key($data, array_flip(['path', 'title', 'meta_title', 'meta_description']));
            OutpostDB::insert('pages', $insertData);
            break;
        case 'collection':
            $insertData = array_intersect_key($data, array_flip(['slug', 'name', 'singular_name', 'schema', 'url_pattern', 'template_path', 'sort_field', 'sort_direction', 'items_per_page']));
            OutpostDB::insert('collections', $insertData);
            break;
    }
}

// ── Public helper for other modules ─────────────────────

function release_add_change(int $releaseId, string $entityType, int $entityId, string $action, ?array $before, ?array $after, string $entityName = ''): void {
    OutpostDB::insert('release_changes', [
        'release_id' => $releaseId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_name' => $entityName,
        'action' => $action,
        'snapshot_before' => $before ? json_encode($before) : null,
        'snapshot_after' => $after ? json_encode($after) : null,
    ]);
    OutpostDB::update('releases', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$releaseId]);
}
