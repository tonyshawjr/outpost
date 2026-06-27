<?php
/**
 * Outpost — Presence indicators (v6 Section 2 — phase 1 only)
 *
 * Lightweight "Tony is also editing this" warning. No real-time CRDT — just
 * heartbeat polling. Phase 2 (real-time collaborative editing) is tracked in
 * the v6 plan as deferred.
 *
 * Each open editor sends a ping every 15s. The server records (user, entity,
 * last_seen). Other editors poll the same endpoint to see who's looking at
 * the same entity. Sessions older than 60s are considered stale.
 */

function ensure_presence_table(): void {
    OutpostDB::connect()->exec("
        CREATE TABLE IF NOT EXISTS presence_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            last_seen TEXT DEFAULT (datetime('now')),
            UNIQUE(user_id, entity_type, entity_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    OutpostDB::connect()->exec(
        'CREATE INDEX IF NOT EXISTS idx_presence_entity ON presence_sessions(entity_type, entity_id, last_seen)'
    );
}

function _presence_validate_entity(string $type): bool {
    return in_array($type, ['page', 'collection_item', 'page_block'], true);
}

function presence_ping(int $userId, string $entityType, int $entityId): void {
    if (!_presence_validate_entity($entityType) || $entityId <= 0 || $userId <= 0) return;
    $existing = OutpostDB::fetchOne(
        'SELECT id FROM presence_sessions WHERE user_id = ? AND entity_type = ? AND entity_id = ?',
        [$userId, $entityType, $entityId]
    );
    if ($existing) {
        OutpostDB::update(
            'presence_sessions',
            ['last_seen' => date('Y-m-d H:i:s')],
            'id = ?',
            [$existing['id']]
        );
    } else {
        OutpostDB::insert('presence_sessions', [
            'user_id'     => $userId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ]);
    }
}

function presence_others(int $userId, string $entityType, int $entityId): array {
    if (!_presence_validate_entity($entityType) || $entityId <= 0) return [];
    return OutpostDB::fetchAll(
        "SELECT u.id, u.display_name, u.username, u.avatar, ps.last_seen
           FROM presence_sessions ps
           JOIN users u ON u.id = ps.user_id
          WHERE ps.entity_type = ?
            AND ps.entity_id = ?
            AND ps.user_id != ?
            AND ps.last_seen >= datetime('now', '-60 seconds')",
        [$entityType, $entityId, $userId]
    );
}

function handle_presence_ping(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = (string) ($body['entity_type'] ?? '');
    $id = (int) ($body['entity_id'] ?? 0);
    if (!_presence_validate_entity($type)) {
        json_response(['error' => 'invalid entity_type'], 400);
        return;
    }
    if ($id <= 0) {
        json_response(['error' => 'invalid entity_id'], 400);
        return;
    }
    $userId = (int) (OutpostAuth::currentUser()['id'] ?? 0);
    if ($userId <= 0) {
        json_response(['error' => 'not authenticated'], 401);
        return;
    }
    presence_ping($userId, $type, $id);
    $others = presence_others($userId, $type, $id);
    json_response(['others' => $others]);
}
