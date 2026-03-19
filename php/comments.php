<?php
/**
 * Outpost CMS — Collaboration & Comments
 *
 * Team comments on pages, items, and fields + external client review via
 * shareable links. Review endpoints use token-based auth (no login).
 */

// ── Migration ────────────────────────────────────────────

function ensure_comment_tables(): void {
    $db = OutpostDB::connect();

    $db->exec("
        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            element_selector TEXT DEFAULT '',
            page_path TEXT DEFAULT '',
            parent_id INTEGER DEFAULT NULL,
            user_id INTEGER DEFAULT NULL,
            author_name TEXT DEFAULT '',
            author_email TEXT DEFAULT '',
            body TEXT NOT NULL,
            status TEXT DEFAULT 'open',
            review_token_id INTEGER DEFAULT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comments_entity ON comments(entity_type, entity_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comments_page ON comments(page_path)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS review_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            name TEXT DEFAULT '',
            page_path TEXT DEFAULT '',
            expires_at TEXT,
            active INTEGER DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS comment_mentions (
            comment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            notified INTEGER DEFAULT 0,
            FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
}

// ── Helpers ──────────────────────────────────────────────

function comment_parse_mentions(string $body): array {
    preg_match_all('/@(\w+)/', $body, $matches);
    if (empty($matches[1])) return [];

    $userIds = [];
    foreach (array_unique($matches[1]) as $username) {
        $user = OutpostDB::fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($user) $userIds[] = (int) $user['id'];
    }
    return $userIds;
}

function comment_create_mentions(int $commentId, array $userIds): void {
    foreach ($userIds as $userId) {
        OutpostDB::insert('comment_mentions', [
            'comment_id' => $commentId,
            'user_id'    => $userId,
        ]);
    }
}

function comment_enrich(array $comment): array {
    // Attach user info if user_id is set
    if ($comment['user_id']) {
        $user = OutpostDB::fetchOne('SELECT id, username, display_name, role FROM users WHERE id = ?', [$comment['user_id']]);
        $comment['user'] = $user ?: null;
    } else {
        $comment['user'] = null;
    }

    // Determine if it's an external review comment
    $comment['is_external'] = !empty($comment['review_token_id']);

    return $comment;
}

// ── Admin API Handlers (authenticated) ───────────────────

function handle_comments_list(): void {
    $entityType = $_GET['entity_type'] ?? '';
    $entityId   = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;
    $pagePath   = $_GET['page_path'] ?? '';
    $status     = $_GET['status'] ?? '';

    $where  = [];
    $params = [];

    if ($entityType) {
        $where[]  = 'c.entity_type = ?';
        $params[] = $entityType;
    }
    if ($entityId !== null) {
        $where[]  = 'c.entity_id = ?';
        $params[] = $entityId;
    }
    if ($pagePath) {
        $where[]  = 'c.page_path = ?';
        $params[] = $pagePath;
    }
    if ($status && in_array($status, ['open', 'resolved'])) {
        $where[]  = 'c.status = ?';
        $params[] = $status;
    }

    $where[] = 'c.parent_id IS NULL';
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Fetch top-level comments
    $comments = OutpostDB::fetchAll(
        "SELECT c.* FROM comments c {$whereClause} ORDER BY c.created_at DESC LIMIT 200",
        $params
    );

    // Enrich and attach replies
    $result = [];
    foreach ($comments as $c) {
        $c = comment_enrich($c);
        $replies = OutpostDB::fetchAll(
            'SELECT * FROM comments WHERE parent_id = ? ORDER BY created_at ASC',
            [$c['id']]
        );
        $c['replies'] = array_map('comment_enrich', $replies);
        $result[] = $c;
    }

    json_response(['comments' => $result]);
}

function handle_comment_create(): void {
    $data = get_json_body();
    $body = trim($data['body'] ?? '');
    if (!$body) json_error('Comment body is required');

    $entityType = $data['entity_type'] ?? '';
    $entityId   = isset($data['entity_id']) ? (int) $data['entity_id'] : null;
    $pagePath   = $data['page_path'] ?? '';
    $parentId   = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

    if (!$entityType && !$pagePath) {
        json_error('Either entity_type or page_path is required');
    }

    $userId = $_SESSION['outpost_user_id'] ?? null;

    $id = OutpostDB::insert('comments', [
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'page_path'   => $pagePath,
        'parent_id'   => $parentId,
        'user_id'     => $userId,
        'body'        => $body,
        'status'      => 'open',
    ]);

    // Parse and store @mentions
    $mentions = $data['mentions'] ?? comment_parse_mentions($body);
    if (!empty($mentions)) {
        comment_create_mentions($id, $mentions);
    }

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
    $comment = comment_enrich($comment);

    json_response(['comment' => $comment], 201);
}

function handle_comment_update(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing comment id');

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
    if (!$comment) json_error('Comment not found', 404);

    $data    = get_json_body();
    $updates = ['updated_at' => date('Y-m-d H:i:s')];

    if (isset($data['body'])) {
        $updates['body'] = trim($data['body']);
    }
    if (isset($data['status']) && in_array($data['status'], ['open', 'resolved'])) {
        $updates['status'] = $data['status'];
    }

    OutpostDB::update('comments', $updates, 'id = ?', [$id]);

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
    json_response(['comment' => comment_enrich($comment)]);
}

function handle_comment_delete(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing comment id');

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
    if (!$comment) json_error('Comment not found', 404);

    // Only the author or admins can delete
    $userId = $_SESSION['outpost_user_id'] ?? null;
    $role   = $_SESSION['outpost_role'] ?? '';
    if ($comment['user_id'] != $userId && !in_array($role, ['super_admin', 'admin'])) {
        json_error('Forbidden', 403);
    }

    OutpostDB::delete('comments', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_comments_count(): void {
    // Count open comments grouped by entity type + id
    $counts = OutpostDB::fetchAll(
        "SELECT entity_type, entity_id, page_path, COUNT(*) as count
         FROM comments WHERE status = 'open' AND parent_id IS NULL
         GROUP BY entity_type, entity_id, page_path"
    );

    // Also provide total
    $total = OutpostDB::fetchOne("SELECT COUNT(*) as total FROM comments WHERE status = 'open'");

    json_response([
        'counts' => $counts,
        'total'  => (int) ($total['total'] ?? 0),
    ]);
}

function handle_activity_feed(): void {
    $limit = min((int) ($_GET['limit'] ?? 50), 200);

    $comments = OutpostDB::fetchAll(
        'SELECT c.*, u.username, u.display_name
         FROM comments c
         LEFT JOIN users u ON c.user_id = u.id
         ORDER BY c.created_at DESC
         LIMIT ?',
        [$limit]
    );

    // Enrich each comment
    $result = [];
    foreach ($comments as $c) {
        $c['user'] = $c['username'] ? [
            'id'           => $c['user_id'],
            'username'     => $c['username'],
            'display_name' => $c['display_name'],
        ] : null;
        unset($c['username'], $c['display_name']);
        $c['is_external'] = !empty($c['review_token_id']);
        $result[] = $c;
    }

    json_response(['activity' => $result]);
}

// ── Review Token Handlers (admin only) ───────────────────

function handle_review_tokens_list(): void {
    $tokens = OutpostDB::fetchAll(
        'SELECT rt.*, u.username as created_by_username
         FROM review_tokens rt
         LEFT JOIN users u ON rt.created_by = u.id
         ORDER BY rt.created_at DESC'
    );

    json_response(['tokens' => $tokens]);
}

function handle_review_token_create(): void {
    $data = get_json_body();
    $name = trim($data['name'] ?? '');
    if (!$name) json_error('Name is required');

    $token    = bin2hex(random_bytes(24));
    $pagePath = trim($data['page_path'] ?? '');
    $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;

    $id = OutpostDB::insert('review_tokens', [
        'token'      => $token,
        'name'       => $name,
        'page_path'  => $pagePath,
        'expires_at' => $expiresAt,
        'created_by' => $_SESSION['outpost_user_id'],
    ]);

    $row = OutpostDB::fetchOne('SELECT * FROM review_tokens WHERE id = ?', [$id]);

    json_response(['token' => $row], 201);
}

function handle_review_token_delete(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing token id');

    $token = OutpostDB::fetchOne('SELECT * FROM review_tokens WHERE id = ?', [$id]);
    if (!$token) json_error('Token not found', 404);

    OutpostDB::delete('review_tokens', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_review_token_toggle(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing token id');

    $token = OutpostDB::fetchOne('SELECT * FROM review_tokens WHERE id = ?', [$id]);
    if (!$token) json_error('Token not found', 404);

    $newActive = $token['active'] ? 0 : 1;
    OutpostDB::update('review_tokens', ['active' => $newActive], 'id = ?', [$id]);

    json_response(['success' => true, 'active' => $newActive]);
}

// ── Public Review Handlers (token-authenticated) ─────────

function validate_review_token(string $tokenStr): ?array {
    if (!$tokenStr) return null;
    $token = OutpostDB::fetchOne(
        'SELECT * FROM review_tokens WHERE token = ? AND active = 1',
        [$tokenStr]
    );
    if (!$token) return null;

    // Check expiry
    if ($token['expires_at'] && $token['expires_at'] < date('Y-m-d H:i:s')) {
        return null;
    }

    return $token;
}

function handle_review_comment_create(): void {
    $data = get_json_body();

    $tokenStr = trim($data['token'] ?? '');
    $token = validate_review_token($tokenStr);
    if (!$token) json_error('Invalid or expired review token', 401);

    $body       = trim($data['body'] ?? '');
    $authorName = trim($data['author_name'] ?? '');
    $authorEmail = trim($data['author_email'] ?? '');
    $pagePath   = trim($data['page_path'] ?? '');
    $selector   = trim($data['element_selector'] ?? '');
    $parentId   = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

    if (!$body) json_error('Comment body is required');
    if (!$authorName) json_error('Name is required');

    // Check page restriction
    if ($token['page_path'] && $token['page_path'] !== $pagePath) {
        json_error('This review link is restricted to a different page', 403);
    }

    $id = OutpostDB::insert('comments', [
        'entity_type'      => 'element',
        'entity_id'        => null,
        'element_selector' => $selector,
        'page_path'        => $pagePath,
        'parent_id'        => $parentId,
        'user_id'          => null,
        'author_name'      => $authorName,
        'author_email'     => $authorEmail,
        'body'             => $body,
        'status'           => 'open',
        'review_token_id'  => $token['id'],
    ]);

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);

    json_response(['comment' => $comment], 201);
}

function handle_review_comments_list(): void {
    $tokenStr = $_GET['token'] ?? '';
    $token = validate_review_token($tokenStr);
    if (!$token) json_error('Invalid or expired review token', 401);

    $pagePath = $_GET['page_path'] ?? '';

    $where  = ['c.review_token_id = ?'];
    $params = [$token['id']];

    if ($pagePath) {
        $where[]  = 'c.page_path = ?';
        $params[] = $pagePath;
    }

    $whereClause = implode(' AND ', $where);

    $comments = OutpostDB::fetchAll(
        "SELECT c.* FROM comments c WHERE {$whereClause} ORDER BY c.created_at ASC LIMIT 500",
        $params
    );

    // Group top-level with replies
    $topLevel = [];
    $replies  = [];
    foreach ($comments as $c) {
        if ($c['parent_id']) {
            $replies[$c['parent_id']][] = $c;
        } else {
            $c['replies'] = [];
            $topLevel[$c['id']] = $c;
        }
    }
    foreach ($replies as $parentId => $reps) {
        if (isset($topLevel[$parentId])) {
            $topLevel[$parentId]['replies'] = $reps;
        }
    }

    json_response(['comments' => array_values($topLevel)]);
}

// ── Ranger Tool Handler ──────────────────────────────────

function ranger_handle_manage_comments(array $params): array {
    $action = $params['action'] ?? '';

    switch ($action) {
        case 'list':
            $where  = [];
            $qParams = [];
            if (!empty($params['entity_type'])) {
                $where[]  = 'entity_type = ?';
                $qParams[] = $params['entity_type'];
            }
            if (isset($params['entity_id'])) {
                $where[]  = 'entity_id = ?';
                $qParams[] = (int) $params['entity_id'];
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $comments = OutpostDB::fetchAll(
                "SELECT c.*, u.username FROM comments c LEFT JOIN users u ON c.user_id = u.id {$whereClause} ORDER BY c.created_at DESC LIMIT 50",
                $qParams
            );
            return ['success' => true, 'comments' => $comments];

        case 'create':
            $body = trim($params['body'] ?? '');
            if (!$body) return ['error' => 'Comment body is required'];

            $userId = $_SESSION['outpost_user_id'] ?? null;
            $id = OutpostDB::insert('comments', [
                'entity_type' => $params['entity_type'] ?? 'page',
                'entity_id'   => $params['entity_id'] ?? null,
                'page_path'   => $params['page_path'] ?? '',
                'user_id'     => $userId,
                'body'        => $body,
                'status'      => 'open',
            ]);
            $mentions = comment_parse_mentions($body);
            if ($mentions) comment_create_mentions($id, $mentions);
            return ['success' => true, 'comment_id' => $id];

        case 'resolve':
            $commentId = (int) ($params['comment_id'] ?? 0);
            if (!$commentId) return ['error' => 'comment_id required'];
            OutpostDB::update('comments', ['status' => 'resolved', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$commentId]);
            return ['success' => true];

        case 'delete':
            $commentId = (int) ($params['comment_id'] ?? 0);
            if (!$commentId) return ['error' => 'comment_id required'];
            OutpostDB::delete('comments', 'id = ?', [$commentId]);
            return ['success' => true];

        case 'activity':
            $comments = OutpostDB::fetchAll(
                'SELECT c.*, u.username FROM comments c LEFT JOIN users u ON c.user_id = u.id ORDER BY c.created_at DESC LIMIT 30'
            );
            return ['success' => true, 'activity' => $comments];

        case 'create_review_link':
            $name = trim($params['name'] ?? '');
            if (!$name) return ['error' => 'Name required'];
            $token = bin2hex(random_bytes(24));
            $id = OutpostDB::insert('review_tokens', [
                'token'      => $token,
                'name'       => $name,
                'page_path'  => $params['page_path'] ?? '',
                'expires_at' => $params['expires_at'] ?? null,
                'created_by' => $_SESSION['outpost_user_id'],
            ]);
            return ['success' => true, 'token_id' => $id, 'token' => $token, 'url' => '/?review=' . $token];

        case 'list_review_links':
            $tokens = OutpostDB::fetchAll('SELECT * FROM review_tokens ORDER BY created_at DESC');
            return ['success' => true, 'tokens' => $tokens];

        default:
            return ['error' => "Unknown action: {$action}"];
    }
}
