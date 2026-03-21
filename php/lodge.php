<?php
/**
 * Outpost CMS — Lodge (Member Portal) API
 * Handles member-owned content CRUD, scoped to the authenticated member.
 * All endpoints require JWT member auth via member-api.php.
 */

/**
 * Get Lodge configuration for a collection.
 */
function lodge_get_config(int $collectionId): array {
    $col = OutpostDB::fetchOne('SELECT lodge_enabled, lodge_config FROM collections WHERE id = ?', [$collectionId]);
    if (!$col || !$col['lodge_enabled']) return ['enabled' => false];
    $config = json_decode($col['lodge_config'] ?: '{}', true) ?: [];
    return array_merge([
        'enabled' => true,
        'allow_create' => true,
        'allow_edit' => true,
        'allow_delete' => false,
        'require_approval' => false,
        'max_items_per_member' => 0, // 0 = unlimited
        'editable_fields' => [],     // empty = all schema fields
        'readonly_fields' => [],
    ], $config);
}

/**
 * Lodge Dashboard — returns member profile + owned items summary per collection.
 */
function handle_lodge_dashboard(array $member): void {
    $collections = OutpostDB::fetchAll("SELECT id, name, slug, schema, lodge_enabled, lodge_config FROM collections WHERE lodge_enabled = 1");

    $summary = [];
    foreach ($collections as $col) {
        $config = lodge_get_config($col['id']);
        $counts = OutpostDB::fetchOne(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
                    SUM(CASE WHEN status = 'draft' OR status = 'pending_review' THEN 1 ELSE 0 END) as pending
             FROM collection_items WHERE collection_id = ? AND owner_member_id = ?",
            [$col['id'], $member['id']]
        );
        $summary[] = [
            'collection_id' => (int) $col['id'],
            'collection_name' => $col['name'],
            'collection_slug' => $col['slug'],
            'total' => (int) ($counts['total'] ?? 0),
            'published' => (int) ($counts['published'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'allow_create' => $config['allow_create'],
            'max_items' => $config['max_items_per_member'],
        ];
    }

    member_json([
        'member' => [
            'id' => (int) $member['id'],
            'name' => $member['display_name'] ?: $member['username'],
            'email' => $member['email'],
            'avatar' => $member['avatar'] ?? null,
            'tier' => $member['tier'] ?? 'free',
        ],
        'collections' => $summary,
    ]);
}

/**
 * Lodge Items — list member's own items in a collection.
 */
function handle_lodge_items_list(array $member): void {
    $collSlug = $_GET['collection'] ?? '';
    if (!$collSlug) member_error('collection parameter required');

    $col = OutpostDB::fetchOne("SELECT * FROM collections WHERE slug = ? AND lodge_enabled = 1", [$collSlug]);
    if (!$col) member_error('Collection not found or Lodge not enabled', 404);

    $items = OutpostDB::fetchAll(
        "SELECT id, slug, status, data, created_at, updated_at, published_at
         FROM collection_items
         WHERE collection_id = ? AND owner_member_id = ?
         ORDER BY created_at DESC",
        [$col['id'], $member['id']]
    );

    $result = [];
    foreach ($items as $item) {
        $data = json_decode($item['data'], true) ?: [];
        $result[] = [
            'id' => (int) $item['id'],
            'slug' => $item['slug'],
            'status' => $item['status'],
            'data' => $data,
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
            'published_at' => $item['published_at'],
        ];
    }

    member_json(['items' => $result, 'collection' => $col['name']]);
}

/**
 * Lodge Item Create — member creates a new item.
 */
function handle_lodge_item_create(array $member): void {
    $collSlug = $_GET['collection'] ?? '';
    if (!$collSlug) member_error('collection parameter required');

    $col = OutpostDB::fetchOne("SELECT * FROM collections WHERE slug = ? AND lodge_enabled = 1", [$collSlug]);
    if (!$col) member_error('Collection not found or Lodge not enabled', 404);

    $config = lodge_get_config($col['id']);
    if (!$config['allow_create']) member_error('Creating items is not allowed', 403);

    // Check max items per member
    if ($config['max_items_per_member'] > 0) {
        $count = OutpostDB::fetchOne(
            "SELECT COUNT(*) as cnt FROM collection_items WHERE collection_id = ? AND owner_member_id = ?",
            [$col['id'], $member['id']]
        );
        if ((int)($count['cnt'] ?? 0) >= $config['max_items_per_member']) {
            member_error('Maximum items limit reached (' . $config['max_items_per_member'] . ')');
        }
    }

    $body = member_json_body();
    $data = $body['data'] ?? [];

    // Filter to editable fields only
    if (!empty($config['editable_fields'])) {
        $filtered = [];
        foreach ($config['editable_fields'] as $field) {
            if (isset($data[$field])) $filtered[$field] = $data[$field];
        }
        $data = $filtered;
    }
    // Remove readonly fields
    foreach ($config['readonly_fields'] as $field) {
        unset($data[$field]);
    }

    // Generate slug from title or random
    $title = $data['title'] ?? $data['name'] ?? '';
    $slug = $body['slug'] ?? '';
    if (!$slug && $title) {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($title)));
        $slug = trim($slug, '-');
    }
    if (!$slug) {
        $slug = 'item-' . bin2hex(random_bytes(4));
    }

    // Ensure slug uniqueness
    $baseSlug = $slug;
    $counter = 1;
    while (OutpostDB::fetchOne("SELECT id FROM collection_items WHERE collection_id = ? AND slug = ?", [$col['id'], $slug])) {
        $slug = $baseSlug . '-' . $counter++;
    }

    $status = $config['require_approval'] ? 'pending_review' : 'draft';

    OutpostDB::insert('collection_items', [
        'collection_id' => $col['id'],
        'slug' => $slug,
        'status' => $status,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'owner_member_id' => $member['id'],
        'sort_order' => 0,
    ]);

    $itemId = OutpostDB::connect()->lastInsertId();

    // Dispatch webhook for new lodge submission
    try {
        require_once __DIR__ . '/webhooks.php';
        ensure_webhooks_tables();
        dispatch_webhook('lodge.item_created', [
            'item_id' => (int) $itemId,
            'collection' => $collSlug,
            'slug' => $slug,
            'status' => $status,
            'member_id' => $member['id'],
        ]);
    } catch (\Throwable $e) {}

    member_json([
        'success' => true,
        'item' => [
            'id' => (int) $itemId,
            'slug' => $slug,
            'status' => $status,
        ],
    ], 201);
}

/**
 * Lodge Item Update — member edits their own item.
 */
function handle_lodge_item_update(array $member): void {
    $itemId = (int) ($_GET['id'] ?? 0);
    if (!$itemId) member_error('Item ID required');

    $item = OutpostDB::fetchOne(
        "SELECT ci.*, c.lodge_enabled, c.lodge_config, c.slug as collection_slug
         FROM collection_items ci
         JOIN collections c ON c.id = ci.collection_id
         WHERE ci.id = ? AND ci.owner_member_id = ?",
        [$itemId, $member['id']]
    );

    if (!$item) member_error('Item not found or access denied', 404);
    if (!$item['lodge_enabled']) member_error('Lodge not enabled for this collection', 403);

    $config = lodge_get_config($item['collection_id']);
    if (!$config['allow_edit']) member_error('Editing items is not allowed', 403);

    $body = member_json_body();
    $newData = $body['data'] ?? [];
    $existingData = json_decode($item['data'], true) ?: [];

    // Filter to editable fields only
    if (!empty($config['editable_fields'])) {
        foreach ($newData as $key => $val) {
            if (!in_array($key, $config['editable_fields'])) {
                unset($newData[$key]);
            }
        }
    }
    // Remove readonly fields
    foreach ($config['readonly_fields'] as $field) {
        unset($newData[$field]);
    }

    $mergedData = array_merge($existingData, $newData);

    $updateFields = ['data' => json_encode($mergedData, JSON_UNESCAPED_UNICODE)];

    // If approval required, edits go back to pending
    if ($config['require_approval'] && $item['status'] === 'published') {
        $updateFields['status'] = 'pending_review';
    }

    // Allow slug update
    if (isset($body['slug']) && $body['slug'] !== $item['slug']) {
        $newSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($body['slug'])));
        if ($newSlug) {
            $exists = OutpostDB::fetchOne(
                "SELECT id FROM collection_items WHERE collection_id = ? AND slug = ? AND id != ?",
                [$item['collection_id'], $newSlug, $itemId]
            );
            if (!$exists) $updateFields['slug'] = $newSlug;
        }
    }

    OutpostDB::update('collection_items', $updateFields, 'id = ?', [$itemId]);

    // Dispatch webhook for lodge item update
    try {
        require_once __DIR__ . '/webhooks.php';
        ensure_webhooks_tables();
        dispatch_webhook('lodge.item_updated', [
            'item_id' => $itemId,
            'collection' => $item['collection_slug'],
            'status' => $updateFields['status'] ?? $item['status'],
            'member_id' => $member['id'],
        ]);
    } catch (\Throwable $e) {}

    member_json(['success' => true]);
}

/**
 * Lodge Item Delete — member deletes their own item (if allowed).
 */
function handle_lodge_item_delete(array $member): void {
    $itemId = (int) ($_GET['id'] ?? 0);
    if (!$itemId) member_error('Item ID required');

    $item = OutpostDB::fetchOne(
        "SELECT ci.*, c.lodge_enabled
         FROM collection_items ci
         JOIN collections c ON c.id = ci.collection_id
         WHERE ci.id = ? AND ci.owner_member_id = ?",
        [$itemId, $member['id']]
    );

    if (!$item) member_error('Item not found or access denied', 404);
    if (!$item['lodge_enabled']) member_error('Lodge not enabled', 403);

    $config = lodge_get_config($item['collection_id']);
    if (!$config['allow_delete']) member_error('Deleting items is not allowed', 403);

    OutpostDB::query('DELETE FROM collection_items WHERE id = ? AND owner_member_id = ?', [$itemId, $member['id']]);

    member_json(['success' => true]);
}

/**
 * Lodge Profile — get member's own profile.
 */
function handle_lodge_profile_get(array $member): void {
    $full = OutpostDB::fetchOne(
        'SELECT id, username, email, display_name, avatar, bio, member_since, tier, meta FROM users WHERE id = ?',
        [$member['id']]
    );
    if (!$full) member_error('Member not found', 404);

    member_json([
        'profile' => [
            'id' => (int) $full['id'],
            'username' => $full['username'],
            'email' => $full['email'],
            'display_name' => $full['display_name'],
            'avatar' => $full['avatar'],
            'bio' => $full['bio'],
            'member_since' => $full['member_since'],
            'tier' => $full['tier'] ?? 'free',
            'meta' => json_decode($full['meta'] ?? '{}', true),
        ],
    ]);
}

/**
 * Lodge Profile Update — member updates their own profile.
 */
function handle_lodge_profile_update(array $member): void {
    $data = member_json_body();
    $update = [];

    $allowed = ['display_name', 'bio', 'avatar'];
    foreach ($allowed as $key) {
        if (isset($data[$key])) $update[$key] = trim($data[$key]);
    }

    if (isset($data['email'])) {
        $email = trim($data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) member_error('Invalid email');
        $existing = OutpostDB::fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $member['id']]);
        if ($existing) member_error('Email already in use');
        $update['email'] = $email;
    }

    if (empty($update)) member_error('Nothing to update');

    OutpostDB::update('users', $update, 'id = ?', [$member['id']]);
    member_json(['success' => true]);
}

/**
 * Lodge Upload — member uploads a file attached to their own item.
 */
function handle_lodge_upload(array $member): void {
    $itemId = (int) ($_GET['item_id'] ?? 0);

    // Verify ownership if item_id provided
    if ($itemId) {
        $item = OutpostDB::fetchOne(
            "SELECT id FROM collection_items WHERE id = ? AND owner_member_id = ?",
            [$itemId, $member['id']]
        );
        if (!$item) member_error('Item not found or access denied', 404);
    }

    if (empty($_FILES['file'])) member_error('No file uploaded');
    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) member_error('Upload error');

    // 10MB limit for member uploads
    if ($file['size'] > 10 * 1024 * 1024) member_error('File too large (max 10MB)');

    // Allowed types
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedTypes)) member_error('File type not allowed');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'lodge-' . $member['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $ext;

    $uploadsDir = OUTPOST_UPLOADS_DIR;
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

    $dest = $uploadsDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        member_error('Failed to save file');
    }

    // Insert into media table
    OutpostDB::insert('media', [
        'filename' => $filename,
        'original_name' => $file['name'],
        'mime_type' => $mimeType,
        'size' => $file['size'],
    ]);

    $url = '/outpost/content/uploads/' . $filename;

    member_json([
        'success' => true,
        'url' => $url,
        'filename' => $filename,
    ]);
}
