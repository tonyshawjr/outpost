<?php
/**
 * Outpost — Release approval gates + diff (v6 Section 6)
 *
 * Adds editor-submits / admin-approves / publishes flow on top of the
 * existing releases.php. Status state machine:
 *
 *     draft → review → approved → published
 *                   ↘ rejected → draft
 *
 * Only an editor can submit a draft for review. Only an admin can approve
 * (and the existing handle_release_publish() will publish on top of approval).
 *
 * Adds a release/diff endpoint that returns the per-change before/after
 * payloads so reviewers see exactly what's changing.
 */

function ensure_release_approvals_columns(): void {
    $db = OutpostDB::connect();
    $cols = OutpostDB::fetchAll('PRAGMA table_info(releases)');
    $names = array_column($cols, 'name');
    if (!in_array('submitted_at', $names, true)) {
        $db->exec("ALTER TABLE releases ADD COLUMN submitted_at TEXT");
    }
    if (!in_array('submitted_by', $names, true)) {
        $db->exec("ALTER TABLE releases ADD COLUMN submitted_by INTEGER");
    }
    if (!in_array('approved_at', $names, true)) {
        $db->exec("ALTER TABLE releases ADD COLUMN approved_at TEXT");
    }
    if (!in_array('approved_by', $names, true)) {
        $db->exec("ALTER TABLE releases ADD COLUMN approved_by INTEGER");
    }
    if (!in_array('rejected_reason', $names, true)) {
        $db->exec("ALTER TABLE releases ADD COLUMN rejected_reason TEXT DEFAULT ''");
    }
}

function _release_approval_user_role(): string {
    if (!class_exists('OutpostAuth') || !method_exists('OutpostAuth', 'currentUser')) return '';
    try {
        $user = OutpostAuth::currentUser();
        return is_array($user) ? (string) ($user['role'] ?? '') : '';
    } catch (\Throwable $e) {
        return '';
    }
}

function handle_release_submit_for_review(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }

    $release = OutpostDB::fetchOne('SELECT * FROM releases WHERE id = ?', [$id]);
    if (!$release) { json_response(['error' => 'Release not found'], 404); return; }
    if ($release['status'] !== 'draft') {
        json_response(['error' => 'Only draft releases can be submitted for review'], 400);
        return;
    }
    OutpostDB::update('releases', [
        'status'       => 'review',
        'submitted_at' => date('Y-m-d H:i:s'),
        'submitted_by' => (OutpostAuth::currentUser()['id'] ?? null),
        'updated_at'   => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_release_approve(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }

    // Approval requires admin role
    $role = _release_approval_user_role();
    if (!in_array($role, ['admin', 'super_admin'], true)) {
        json_response(['error' => 'Admin role required to approve releases'], 403);
        return;
    }

    $release = OutpostDB::fetchOne('SELECT * FROM releases WHERE id = ?', [$id]);
    if (!$release) { json_response(['error' => 'Release not found'], 404); return; }
    if (!in_array($release['status'], ['draft', 'review'], true)) {
        json_response(['error' => 'Release is not in a reviewable state'], 400);
        return;
    }
    OutpostDB::update('releases', [
        'status'      => 'approved',
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by' => (OutpostAuth::currentUser()['id'] ?? null),
        'updated_at'  => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_release_reject(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($body['id'] ?? 0);
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }

    $role = _release_approval_user_role();
    if (!in_array($role, ['admin', 'super_admin'], true)) {
        json_response(['error' => 'Admin role required to reject releases'], 403);
        return;
    }

    $release = OutpostDB::fetchOne('SELECT * FROM releases WHERE id = ?', [$id]);
    if (!$release) { json_response(['error' => 'Release not found'], 404); return; }
    if (!in_array($release['status'], ['review', 'approved'], true)) {
        json_response(['error' => 'Release is not in a reviewable state'], 400);
        return;
    }
    OutpostDB::update('releases', [
        'status'          => 'draft', // back to draft so editor can iterate
        'rejected_reason' => mb_substr($reason, 0, 500),
        'updated_at'      => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);
    json_response(['success' => true]);
}

/**
 * Returns the per-change before/after payloads as parsed JSON for diff
 * rendering in the UI.
 */
function handle_release_diff(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }
    $release = OutpostDB::fetchOne('SELECT id, name, status FROM releases WHERE id = ?', [$id]);
    if (!$release) { json_response(['error' => 'Release not found'], 404); return; }

    $changes = OutpostDB::fetchAll(
        'SELECT id, entity_type, entity_id, entity_name, action,
                snapshot_before, snapshot_after, created_at
           FROM release_changes
          WHERE release_id = ?
          ORDER BY created_at ASC',
        [$id]
    );
    foreach ($changes as &$c) {
        $c['snapshot_before_parsed'] = $c['snapshot_before'] ? json_decode($c['snapshot_before'], true) : null;
        $c['snapshot_after_parsed']  = $c['snapshot_after']  ? json_decode($c['snapshot_after'],  true) : null;
        $c['changed_keys'] = _release_diff_changed_keys(
            $c['snapshot_before_parsed'] ?? [],
            $c['snapshot_after_parsed']  ?? []
        );
    }
    json_response([
        'release' => $release,
        'changes' => $changes,
    ]);
}

function _release_diff_changed_keys(mixed $before, mixed $after): array {
    if (!is_array($before)) $before = [];
    if (!is_array($after)) $after = [];
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    $changed = [];
    foreach ($keys as $k) {
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if (is_array($b) || is_array($a)) {
            if (json_encode($b) !== json_encode($a)) $changed[] = $k;
        } elseif ((string) $b !== (string) $a) {
            $changed[] = $k;
        }
    }
    return $changed;
}
