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

function comment_get_setting(string $key): string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", [$key]);
    return $row ? (string) $row['value'] : '';
}

function comment_get_site_name(): string {
    return comment_get_setting('site_name') ?: 'Outpost CMS';
}

function comment_get_admin_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}/outpost/";
}

function comment_build_email_html(string $heading, string $body, string $ctaUrl, string $ctaLabel, string $footer): string {
    return '<div style="font-family: -apple-system, system-ui, sans-serif; max-width: 560px; margin: 0 auto; padding: 20px;">'
        . '<div style="border-bottom: 2px solid #2D5A47; padding-bottom: 12px; margin-bottom: 20px;">'
        . '<strong style="color: #2D5A47;">' . htmlspecialchars(comment_get_site_name()) . '</strong>'
        . '</div>'
        . '<p style="color: #333; font-size: 15px; line-height: 1.6;">' . $heading . '</p>'
        . '<blockquote style="border-left: 3px solid #E5E1DA; padding: 8px 16px; margin: 16px 0; color: #555; font-size: 14px;">'
        . htmlspecialchars($body)
        . '</blockquote>'
        . '<a href="' . htmlspecialchars($ctaUrl) . '" style="display: inline-block; background: #2D5A47; color: white; padding: 8px 20px; border-radius: 6px; text-decoration: none; font-size: 14px;">'
        . htmlspecialchars($ctaLabel)
        . '</a>'
        . '<p style="color: #999; font-size: 12px; margin-top: 24px;">' . $footer . '</p>'
        . '</div>';
}

function comment_send_mention_notifications(int $commentId, array $mentionedUserIds, int $commenterUserId, string $commentBody): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $mailer = OutpostMailer::fromSettings();
        $siteName = comment_get_site_name();
        $adminUrl = comment_get_admin_url();

        // Get commenter display name
        $commenter = OutpostDB::fetchOne('SELECT display_name, username FROM users WHERE id = ?', [$commenterUserId]);
        $commenterName = $commenter['display_name'] ?: $commenter['username'] ?: 'Someone';

        foreach ($mentionedUserIds as $userId) {
            // Don't notify yourself
            if ($userId === $commenterUserId) continue;

            $user = OutpostDB::fetchOne('SELECT email, display_name FROM users WHERE id = ?', [$userId]);
            if (!$user || !$user['email']) continue;

            $subject = "You were mentioned in a comment on {$siteName}";
            $heading = '<strong>' . htmlspecialchars($commenterName) . '</strong> mentioned you in a comment:';
            $html = comment_build_email_html($heading, $commentBody, $adminUrl, 'View in Outpost', 'You received this because you were mentioned in a comment.');
            $text = "{$commenterName} mentioned you in a comment:\n\n\"{$commentBody}\"\n\nView in Outpost: {$adminUrl}";

            try {
                $mailer->send($user['email'], $subject, $text, $html);
                // Mark as notified
                OutpostDB::connect()->exec(
                    "UPDATE comment_mentions SET notified = 1 WHERE comment_id = " . (int) $commentId . " AND user_id = " . (int) $userId
                );
            } catch (\Throwable $e) {
                error_log("Comment mention email failed for user {$userId}: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log('Comment mention notification error: ' . $e->getMessage());
    }
}

function comment_send_reply_notification(int $parentId, int $replierUserId, string $commentBody): void {
    try {
        $parent = OutpostDB::fetchOne('SELECT user_id FROM comments WHERE id = ?', [$parentId]);
        if (!$parent || !$parent['user_id']) return;

        $parentAuthorId = (int) $parent['user_id'];
        // Don't notify yourself
        if ($parentAuthorId === $replierUserId) return;

        $parentUser = OutpostDB::fetchOne('SELECT email, display_name FROM users WHERE id = ?', [$parentAuthorId]);
        if (!$parentUser || !$parentUser['email']) return;

        require_once __DIR__ . '/mailer.php';
        $mailer = OutpostMailer::fromSettings();
        $siteName = comment_get_site_name();
        $adminUrl = comment_get_admin_url();

        // Get replier display name
        $replier = OutpostDB::fetchOne('SELECT display_name, username FROM users WHERE id = ?', [$replierUserId]);
        $replierName = $replier['display_name'] ?: $replier['username'] ?: 'Someone';

        $subject = "Someone replied to your comment on {$siteName}";
        $heading = '<strong>' . htmlspecialchars($replierName) . '</strong> replied to your comment:';
        $html = comment_build_email_html($heading, $commentBody, $adminUrl, 'View in Outpost', 'You received this because someone replied to your comment.');
        $text = "{$replierName} replied to your comment:\n\n\"{$commentBody}\"\n\nView in Outpost: {$adminUrl}";

        $mailer->send($parentUser['email'], $subject, $text, $html);
    } catch (\Throwable $e) {
        error_log('Comment reply notification error: ' . $e->getMessage());
    }
}

function comment_send_review_admin_notification(string $authorName, string $commentBody, string $pagePath): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $mailer = OutpostMailer::fromSettings();
        $siteName = comment_get_site_name();
        $adminUrl = comment_get_admin_url();

        // Get all admins
        $admins = OutpostDB::fetchAll("SELECT email FROM users WHERE role IN ('admin', 'super_admin') AND email IS NOT NULL AND email != ''");
        if (empty($admins)) return;

        $displayPath = $pagePath ?: '/';
        $subject = "New client feedback on {$displayPath}";
        $heading = '<strong>' . htmlspecialchars($authorName ?: 'A reviewer') . '</strong> left feedback on <code>' . htmlspecialchars($displayPath) . '</code>:';
        $html = comment_build_email_html($heading, $commentBody, $adminUrl . '#review-tokens', 'View Feedback', 'You received this because a client left feedback via a review link.');
        $text = ($authorName ?: 'A reviewer') . " left feedback on {$displayPath}:\n\n\"{$commentBody}\"\n\nView feedback: {$adminUrl}";

        foreach ($admins as $admin) {
            try {
                $mailer->send($admin['email'], $subject, $text, $html);
            } catch (\Throwable $e) {
                error_log("Review notification email failed for {$admin['email']}: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log('Review admin notification error: ' . $e->getMessage());
    }
}

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

    // Filter by review token (admin viewing feedback for a specific link)
    $reviewTokenId = isset($_GET['review_token_id']) ? (int) $_GET['review_token_id'] : null;
    if ($reviewTokenId) {
        $where[]  = 'c.review_token_id = ?';
        $params[] = $reviewTokenId;
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

    // Send email notifications (don't let failures break comment creation)
    if ($userId) {
        // Notify @mentioned users
        if (!empty($mentions)) {
            comment_send_mention_notifications($id, $mentions, $userId, $body);
        }

        // Notify parent comment author on reply
        if ($parentId) {
            comment_send_reply_notification($parentId, $userId, $body);
        }
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
    $collectionId = isset($_GET['collection_id']) ? (int) $_GET['collection_id'] : null;

    if ($collectionId) {
        // Return counts for all items in a specific collection
        $counts = OutpostDB::fetchAll(
            "SELECT c.entity_id, COUNT(*) as count
             FROM comments c
             WHERE c.entity_type = 'item'
               AND c.status = 'open'
               AND c.entity_id IN (SELECT id FROM collection_items WHERE collection_id = ?)
             GROUP BY c.entity_id",
            [$collectionId]
        );

        // Convert to a simple map: { entity_id: count }
        $countMap = [];
        foreach ($counts as $row) {
            $countMap[(int) $row['entity_id']] = (int) $row['count'];
        }

        json_response(['counts' => $countMap]);
        return;
    }

    // Default: count open comments grouped by entity type + id
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

    // Notify admins about new client feedback
    comment_send_review_admin_notification($authorName, $body, $pagePath);

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);

    json_response(['comment' => $comment], 201);
}

function handle_review_comments_list(): void {
    $tokenStr = $_GET['token'] ?? '';
    $token = validate_review_token($tokenStr);
    if (!$token) json_error('Invalid or expired review token', 401);

    $pagePath = $_GET['page_path'] ?? '';

    // Fetch top-level comments for this review token
    $where  = ['c.review_token_id = ?', 'c.parent_id IS NULL'];
    $params = [$token['id']];

    if ($pagePath) {
        $where[]  = 'c.page_path = ?';
        $params[] = $pagePath;
    }

    $whereClause = implode(' AND ', $where);

    $topLevelComments = OutpostDB::fetchAll(
        "SELECT c.* FROM comments c WHERE {$whereClause} ORDER BY c.created_at ASC LIMIT 500",
        $params
    );

    // Collect top-level IDs for reply lookup
    $topLevelIds = array_map(function($c) { return (int) $c['id']; }, $topLevelComments);

    // Build result with replies (including admin replies that have user_id but no review_token_id)
    $result = [];
    foreach ($topLevelComments as $c) {
        // Enrich with user info
        $c = comment_enrich($c);
        // Fetch ALL replies to this comment (both review and admin replies)
        $replies = OutpostDB::fetchAll(
            'SELECT * FROM comments WHERE parent_id = ? ORDER BY created_at ASC',
            [$c['id']]
        );
        $c['replies'] = array_map('comment_enrich', $replies);
        $result[] = $c;
    }

    json_response(['comments' => $result]);
}

// ── Admin Review Reply (authenticated admin replying from overlay) ────

function handle_review_admin_reply(): void {
    $data = get_json_body();
    $body = trim($data['body'] ?? '');
    if (!$body) json_error('Comment body is required');

    $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
    $pagePath = trim($data['page_path'] ?? '');
    $selector = trim($data['element_selector'] ?? '');
    $reviewTokenId = isset($data['review_token_id']) ? (int) $data['review_token_id'] : null;

    $userId = $_SESSION['outpost_user_id'] ?? null;
    if (!$userId) json_error('Authentication required', 401);

    // Get admin display name
    $user = OutpostDB::fetchOne('SELECT display_name, username FROM users WHERE id = ?', [$userId]);
    $authorName = $user['display_name'] ?: $user['username'] ?: 'Admin';

    $id = OutpostDB::insert('comments', [
        'entity_type'      => 'element',
        'entity_id'        => null,
        'element_selector' => $selector,
        'page_path'        => $pagePath,
        'parent_id'        => $parentId,
        'user_id'          => $userId,
        'author_name'      => $authorName,
        'author_email'     => '',
        'body'             => $body,
        'status'           => 'open',
        'review_token_id'  => $reviewTokenId,
    ]);

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
    $comment = comment_enrich($comment);

    json_response(['comment' => $comment], 201);
}

// ── Admin Resolve from Overlay ───────────────────────────

function handle_review_admin_resolve(): void {
    $data = get_json_body();
    $commentId = (int) ($data['comment_id'] ?? 0);
    if (!$commentId) json_error('Missing comment_id');

    $userId = $_SESSION['outpost_user_id'] ?? null;
    if (!$userId) json_error('Authentication required', 401);

    $comment = OutpostDB::fetchOne('SELECT * FROM comments WHERE id = ?', [$commentId]);
    if (!$comment) json_error('Comment not found', 404);

    $newStatus = $comment['status'] === 'resolved' ? 'open' : 'resolved';
    OutpostDB::update('comments', [
        'status' => $newStatus,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$commentId]);

    json_response(['success' => true, 'status' => $newStatus]);
}

// ── Admin Bulk Resolve ───────────────────────────────────

function handle_review_admin_resolve_all(): void {
    $data = get_json_body();
    $reviewTokenId = (int) ($data['review_token_id'] ?? 0);
    $pagePath = trim($data['page_path'] ?? '');

    $userId = $_SESSION['outpost_user_id'] ?? null;
    if (!$userId) json_error('Authentication required', 401);

    $where = ["status = 'open'"];
    $params = [];

    if ($reviewTokenId) {
        $where[] = 'review_token_id = ?';
        $params[] = $reviewTokenId;
    }
    if ($pagePath) {
        $where[] = 'page_path = ?';
        $params[] = $pagePath;
    }

    $whereClause = implode(' AND ', $where);
    OutpostDB::connect()->prepare(
        "UPDATE comments SET status = 'resolved', updated_at = datetime('now') WHERE {$whereClause}"
    )->execute($params);

    json_response(['success' => true]);
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
