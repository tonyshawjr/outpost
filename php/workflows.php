<?php
/**
 * Outpost CMS — Custom Workflows
 *
 * Define custom approval stages per collection.
 * Default: Simple (Draft → Published), Editorial (Draft → Review → Approved → Published).
 */

// ── Database Migration ──────────────────────────────────

function ensure_workflow_tables(): void {
    $db = OutpostDB::connect();

    $db->exec("CREATE TABLE IF NOT EXISTS workflows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        stages TEXT NOT NULL DEFAULT '[]',
        is_default INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS workflow_transitions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id INTEGER NOT NULL,
        collection_id INTEGER NOT NULL,
        from_stage TEXT,
        to_stage TEXT NOT NULL,
        user_id INTEGER NOT NULL,
        note TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (item_id) REFERENCES collection_items(id) ON DELETE CASCADE
    )");

    // Seed default workflows if none exist
    $count = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM workflows');
    if ((int) ($count['c'] ?? 0) === 0) {
        OutpostDB::insert('workflows', [
            'name' => 'Simple',
            'slug' => 'simple',
            'is_default' => 1,
            'stages' => json_encode([
                [
                    'name' => 'Draft',
                    'slug' => 'draft',
                    'color' => '#8A857D',
                    'can_move_to' => ['published'],
                    'roles' => ['editor', 'developer', 'admin', 'super_admin'],
                ],
                [
                    'name' => 'Published',
                    'slug' => 'published',
                    'color' => '#2D5A47',
                    'can_move_to' => ['draft'],
                    'roles' => ['editor', 'developer', 'admin', 'super_admin'],
                ],
            ]),
        ]);
        OutpostDB::insert('workflows', [
            'name' => 'Editorial',
            'slug' => 'editorial',
            'is_default' => 0,
            'stages' => json_encode([
                [
                    'name' => 'Draft',
                    'slug' => 'draft',
                    'color' => '#8A857D',
                    'can_move_to' => ['review'],
                    'roles' => ['editor', 'developer', 'admin', 'super_admin'],
                ],
                [
                    'name' => 'Copy Review',
                    'slug' => 'review',
                    'color' => '#E8B87A',
                    'can_move_to' => ['approved', 'draft'],
                    'roles' => ['admin', 'super_admin'],
                ],
                [
                    'name' => 'Approved',
                    'slug' => 'approved',
                    'color' => '#6FCF97',
                    'can_move_to' => ['published', 'review'],
                    'roles' => ['admin', 'super_admin'],
                ],
                [
                    'name' => 'Published',
                    'slug' => 'published',
                    'color' => '#2D5A47',
                    'can_move_to' => ['draft'],
                    'roles' => ['admin', 'super_admin'],
                ],
            ]),
        ]);
    }

    // Add workflow_id column to collections if missing
    $cols = $db->query("PRAGMA table_info(collections)")->fetchAll(\PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('workflow_id', $colNames)) {
        $db->exec("ALTER TABLE collections ADD COLUMN workflow_id INTEGER DEFAULT NULL");
    }
}

// ── Helpers ─────────────────────────────────────────────

/**
 * Get the workflow for a collection. Falls back to the Simple (default) workflow.
 */
function get_collection_workflow(int $collectionId): array {
    $coll = OutpostDB::fetchOne('SELECT workflow_id FROM collections WHERE id = ?', [$collectionId]);
    $workflowId = $coll['workflow_id'] ?? null;

    if ($workflowId) {
        $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE id = ?', [$workflowId]);
        if ($wf) {
            $wf['stages'] = json_decode($wf['stages'], true) ?: [];
            return $wf;
        }
    }

    // Fallback to default workflow
    $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE is_default = 1');
    if (!$wf) {
        // Ultra-fallback: simple draft → published
        return [
            'id' => 0,
            'name' => 'Simple',
            'slug' => 'simple',
            'is_default' => 1,
            'stages' => [
                ['name' => 'Draft', 'slug' => 'draft', 'color' => '#8A857D', 'can_move_to' => ['published'], 'roles' => ['editor', 'developer', 'admin', 'super_admin']],
                ['name' => 'Published', 'slug' => 'published', 'color' => '#2D5A47', 'can_move_to' => ['draft'], 'roles' => ['editor', 'developer', 'admin', 'super_admin']],
            ],
        ];
    }
    $wf['stages'] = json_decode($wf['stages'], true) ?: [];
    return $wf;
}

/**
 * Find a stage definition within a workflow's stages array.
 */
function find_stage(array $stages, string $slug): ?array {
    foreach ($stages as $stage) {
        if ($stage['slug'] === $slug) return $stage;
    }
    return null;
}

// ── Handlers ────────────────────────────────────────────

function handle_workflows_list(): void {
    $workflows = OutpostDB::fetchAll('SELECT * FROM workflows ORDER BY is_default DESC, name ASC');
    foreach ($workflows as &$wf) {
        $wf['stages'] = json_decode($wf['stages'], true) ?: [];
        // Count how many collections use this workflow
        $usage = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM collections WHERE workflow_id = ?', [$wf['id']]);
        $wf['collection_count'] = (int) ($usage['c'] ?? 0);
    }
    json_response(['workflows' => $workflows]);
}

function handle_workflow_get(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing workflow id');
    $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE id = ?', [$id]);
    if (!$wf) json_error('Workflow not found', 404);
    $wf['stages'] = json_decode($wf['stages'], true) ?: [];
    $usage = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM collections WHERE workflow_id = ?', [$wf['id']]);
    $wf['collection_count'] = (int) ($usage['c'] ?? 0);
    json_response(['workflow' => $wf]);
}

function handle_workflow_create(): void {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('Name is required');
    if (mb_strlen($name) > 100) json_error('Name must be 100 characters or fewer');

    $slug = trim($data['slug'] ?? '');
    if (!$slug) {
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
    }
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        json_error('Slug must be lowercase letters, numbers, and hyphens only');
    }

    $existing = OutpostDB::fetchOne('SELECT id FROM workflows WHERE slug = ?', [$slug]);
    if ($existing) json_error('A workflow with this slug already exists');

    $stages = $data['stages'] ?? [];
    if (!is_array($stages) || count($stages) < 2) {
        json_error('At least two stages are required (draft and published)');
    }
    if (count($stages) > 20) {
        json_error('Maximum 20 stages allowed');
    }

    // Validate stages have draft and published
    $slugs = array_column($stages, 'slug');
    if (!in_array('draft', $slugs) || !in_array('published', $slugs)) {
        json_error('Workflows must include "draft" and "published" stages');
    }

    $id = OutpostDB::insert('workflows', [
        'name' => $name,
        'slug' => $slug,
        'stages' => json_encode($stages),
    ]);

    json_response(['success' => true, 'id' => $id], 201);
}

function handle_workflow_update(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing workflow id');
    $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE id = ?', [$id]);
    if (!$wf) json_error('Workflow not found', 404);

    $data = get_json_body();
    $update = ['updated_at' => date('Y-m-d H:i:s')];

    if (isset($data['name'])) {
        $update['name'] = trim($data['name']);
    }

    if (isset($data['stages'])) {
        $stages = $data['stages'];
        if (!is_array($stages) || count($stages) < 2) {
            json_error('At least two stages are required');
        }
        if (count($stages) > 20) {
            json_error('Maximum 20 stages allowed');
        }
        $slugs = array_column($stages, 'slug');
        if (!in_array('draft', $slugs) || !in_array('published', $slugs)) {
            json_error('Workflows must include "draft" and "published" stages');
        }
        $update['stages'] = json_encode($stages);
    }

    OutpostDB::update('workflows', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_workflow_delete(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing workflow id');
    $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE id = ?', [$id]);
    if (!$wf) json_error('Workflow not found', 404);

    if ((int) ($wf['is_default'] ?? 0) === 1) {
        json_error('Cannot delete the default workflow');
    }

    // Check if any collection is using this workflow
    $usage = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM collections WHERE workflow_id = ?', [$id]);
    if ((int) ($usage['c'] ?? 0) > 0) {
        json_error('Cannot delete a workflow that is assigned to collections. Remove the workflow from all collections first.');
    }

    OutpostDB::delete('workflows', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_workflow_transition(): void {
    $data = get_json_body();
    $itemId = (int) ($data['item_id'] ?? 0);
    $toStage = trim($data['to_stage'] ?? '');
    $note = trim($data['note'] ?? '');
    if (mb_strlen($note) > 2000) json_error('Note must be 2000 characters or fewer');
    if (mb_strlen($toStage) > 50) json_error('Invalid stage slug');

    if (!$itemId || !$toStage) json_error('item_id and to_stage are required');

    // Get item + collection
    $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
    if (!$item) json_error('Item not found', 404);

    $collectionId = (int) $item['collection_id'];
    if (!outpost_can_access_collection($collectionId)) {
        json_error('Permission denied', 403);
    }

    // Get workflow
    $workflow = get_collection_workflow($collectionId);
    $stages = $workflow['stages'];
    $currentSlug = $item['status'] ?? 'draft';

    // Find current stage
    $currentStage = find_stage($stages, $currentSlug);
    if (!$currentStage) {
        // Unknown status — allow transition from any stage if user is admin
        $role = $_SESSION['outpost_role'] ?? '';
        if (!in_array($role, ['admin', 'super_admin'])) {
            json_error("Current stage '{$currentSlug}' is not in this workflow");
        }
    } else {
        // Validate transition is allowed
        $canMoveTo = $currentStage['can_move_to'] ?? [];
        if (!in_array($toStage, $canMoveTo)) {
            json_error("Cannot transition from '{$currentSlug}' to '{$toStage}'");
        }
    }

    // Find target stage and validate role
    $targetStage = find_stage($stages, $toStage);
    if (!$targetStage) {
        json_error("Target stage '{$toStage}' does not exist in this workflow");
    }

    $role = $_SESSION['outpost_role'] ?? '';
    $allowedRoles = $targetStage['roles'] ?? [];
    if (!empty($allowedRoles) && !in_array($role, $allowedRoles)) {
        json_error('You do not have permission to move content to this stage');
    }

    // Perform transition
    $update = [
        'status' => $toStage,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    // If moving to published, set published_at if not already set
    if ($toStage === 'published' && empty($item['published_at'])) {
        $update['published_at'] = date('Y-m-d H:i:s');
    }

    OutpostDB::update('collection_items', $update, 'id = ?', [$itemId]);

    // Record transition
    $userId = (int) ($_SESSION['outpost_user_id'] ?? 0);
    OutpostDB::insert('workflow_transitions', [
        'item_id' => $itemId,
        'collection_id' => $collectionId,
        'from_stage' => $currentSlug,
        'to_stage' => $toStage,
        'user_id' => $userId,
        'note' => $note,
    ]);

    // Dispatch webhook
    dispatch_webhook('workflow.transition', [
        'item_id' => $itemId,
        'collection_id' => $collectionId,
        'from_stage' => $currentSlug,
        'to_stage' => $toStage,
        'user_id' => $userId,
        'note' => $note,
        'workflow' => $workflow['name'],
    ]);

    // Clear cache
    outpost_clear_cache();

    // Log activity
    if ($toStage === 'published') {
        $itemData = json_decode($item['data'] ?? '{}', true);
        $title = $itemData['title'] ?? ($item['slug'] ?? 'an item');
        log_activity('content', '"' . $title . '" published');
    }

    json_response([
        'success' => true,
        'status' => $toStage,
        'from_stage' => $currentSlug,
    ]);
}

function handle_workflow_bulk_transition(): void {
    $data = get_json_body();
    $itemIds = $data['item_ids'] ?? [];
    $toStage = trim($data['to_stage'] ?? '');
    $note = trim($data['note'] ?? '');

    if (empty($itemIds) || !$toStage) json_error('item_ids and to_stage are required');
    if (!is_array($itemIds)) json_error('item_ids must be an array');
    if (count($itemIds) > 200) json_error('Maximum 200 items per bulk transition');

    $userId = (int) ($_SESSION['outpost_user_id'] ?? 0);
    $role = $_SESSION['outpost_role'] ?? '';
    $transitioned = 0;
    $errors = [];

    $db = OutpostDB::connect();
    $db->beginTransaction();

    try {
    foreach ($itemIds as $itemId) {
        $itemId = (int) $itemId;
        $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
        if (!$item) continue;

        $collectionId = (int) $item['collection_id'];
        if (!outpost_can_access_collection($collectionId)) continue;

        $workflow = get_collection_workflow($collectionId);
        $stages = $workflow['stages'];
        $currentSlug = $item['status'] ?? 'draft';

        // Find current stage and validate
        $currentStage = find_stage($stages, $currentSlug);
        if ($currentStage) {
            $canMoveTo = $currentStage['can_move_to'] ?? [];
            if (!in_array($toStage, $canMoveTo)) continue;
        }

        $targetStage = find_stage($stages, $toStage);
        if (!$targetStage) continue;

        $allowedRoles = $targetStage['roles'] ?? [];
        if (!empty($allowedRoles) && !in_array($role, $allowedRoles)) continue;

        $update = [
            'status' => $toStage,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($toStage === 'published' && empty($item['published_at'])) {
            $update['published_at'] = date('Y-m-d H:i:s');
        }

        OutpostDB::update('collection_items', $update, 'id = ?', [$itemId]);

        OutpostDB::insert('workflow_transitions', [
            'item_id' => $itemId,
            'collection_id' => $collectionId,
            'from_stage' => $currentSlug,
            'to_stage' => $toStage,
            'user_id' => $userId,
            'note' => $note,
        ]);

        $transitioned++;
    }
    $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        error_log('Bulk transition error: ' . $e->getMessage());
        json_error('Bulk transition failed. Check error log for details.');
    }

    outpost_clear_cache();

    json_response(['success' => true, 'transitioned' => $transitioned]);
}

function handle_workflow_history(): void {
    $itemId = (int) ($_GET['item_id'] ?? 0);
    if (!$itemId) json_error('item_id is required');

    $transitions = OutpostDB::fetchAll(
        "SELECT wt.*, u.display_name, u.username
         FROM workflow_transitions wt
         LEFT JOIN users u ON wt.user_id = u.id
         WHERE wt.item_id = ?
         ORDER BY wt.created_at DESC
         LIMIT 50",
        [$itemId]
    );

    json_response(['transitions' => $transitions]);
}

/**
 * Get the workflow for a collection (API endpoint for frontend).
 */
function handle_workflow_for_collection(): void {
    $collectionId = (int) ($_GET['collection_id'] ?? 0);
    if (!$collectionId) json_error('collection_id is required');

    $workflow = get_collection_workflow($collectionId);
    json_response(['workflow' => $workflow]);
}
