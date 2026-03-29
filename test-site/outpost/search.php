<?php
/**
 * Outpost CMS — Site Search Engine
 * Full-text search across pages and collection items.
 * Public API at api.php?action=search/site
 */

// config.php and db.php are loaded by api.php or front-router.php before this file
if (!class_exists('OutpostDB')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/db.php';
}

// ── Table Setup ─────────────────────────────────────────

function search_ensure_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    OutpostDB::query('CREATE TABLE IF NOT EXISTS search_index (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_type TEXT NOT NULL,
        item_id INTEGER NOT NULL,
        collection_slug TEXT DEFAULT NULL,
        title TEXT NOT NULL DEFAULT \'\',
        content_text TEXT NOT NULL DEFAULT \'\',
        url TEXT NOT NULL,
        image TEXT DEFAULT \'\',
        updated_at TEXT DEFAULT (datetime(\'now\')),
        UNIQUE(item_type, item_id)
    )');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_search_type ON search_index(item_type)');
    OutpostDB::query('CREATE INDEX IF NOT EXISTS idx_search_title ON search_index(title)');
}

// ── Helpers ─────────────────────────────────────────────

/**
 * Get the active theme slug from settings.
 */
function search_get_active_theme(): string {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'active_theme'");
    return $row ? $row['value'] : 'forge-playground';
}

/**
 * Strip HTML tags and collapse whitespace from a string.
 */
function search_strip_html(string $html): string {
    // Remove script/style contents first
    $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Extract text-ish values from a JSON data object (collection item).
 */
function search_extract_text_from_data(array $data, array $schema = []): string {
    $parts = [];
    foreach ($data as $key => $value) {
        if ($key === 'id' || $key === 'slug' || $key === '_layout') continue;
        if (is_string($value) && $value !== '') {
            $parts[] = search_strip_html($value);
        } elseif (is_array($value)) {
            // Flexible content / repeater — recurse
            foreach ($value as $sub) {
                if (is_array($sub)) {
                    $parts[] = search_extract_text_from_data($sub);
                } elseif (is_string($sub) && $sub !== '') {
                    $parts[] = search_strip_html($sub);
                }
            }
        }
    }
    return implode(' ', $parts);
}

/**
 * Find the first image field in collection item data.
 */
function search_find_image(array $data, array $schema = []): string {
    // Check featured_image first
    if (!empty($data['featured_image'])) return $data['featured_image'];
    // Check hero_image
    if (!empty($data['hero_image'])) return $data['hero_image'];
    // Check schema for image type fields
    foreach ($schema as $key => $def) {
        $type = $def['type'] ?? '';
        if ($type === 'image' && !empty($data[$key])) {
            return $data[$key];
        }
    }
    return '';
}

// ── Full Reindex ────────────────────────────────────────

function search_reindex(): int {
    search_ensure_tables();

    $db = OutpostDB::connect();
    $db->exec('DELETE FROM search_index');

    $theme = search_get_active_theme();
    $count = 0;

    // Check which columns exist on pages table (once, not per-row)
    $pageCols = array_column($db->query("PRAGMA table_info(pages)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
    $hasVisibility = in_array('visibility', $pageCols);
    $hasStatus = in_array('status', $pageCols);

    // 1. Index published pages (exclude __global__, /outpost paths, drafts, members-only, paid-only)
    $pageQuery = "SELECT id, path, title FROM pages WHERE path != '__global__' AND path NOT LIKE '/outpost%'";
    if ($hasStatus) $pageQuery .= " AND COALESCE(status, 'published') != 'draft'";
    if ($hasVisibility) $pageQuery .= " AND COALESCE(visibility, 'public') = 'public'";
    $pages = OutpostDB::fetchAll($pageQuery);
    foreach ($pages as $page) {

        // Gather field text content
        $fields = OutpostDB::fetchAll(
            "SELECT field_name, field_type, content FROM fields WHERE page_id = ? AND theme = ?",
            [$page['id'], $theme]
        );
        $textParts = [];
        foreach ($fields as $f) {
            $type = $f['field_type'] ?? 'text';
            if (in_array($type, ['text', 'richtext', 'textarea', 'select'])) {
                $val = search_strip_html($f['content'] ?? '');
                if ($val !== '') $textParts[] = $val;
            }
        }

        $title = $page['title'] ?: $page['path'];
        $url = $page['path'] === '/' ? '/' : '/' . ltrim($page['path'], '/');

        OutpostDB::insert('search_index', [
            'item_type'    => 'page',
            'item_id'      => $page['id'],
            'collection_slug' => null,
            'title'        => $title,
            'content_text' => implode(' ', $textParts),
            'url'          => $url,
            'image'        => '',
        ]);
        $count++;
    }

    // 2. Index published collection items
    $collections = OutpostDB::fetchAll("SELECT id, slug, url_pattern, schema FROM collections");
    foreach ($collections as $col) {
        $schema = json_decode($col['schema'] ?: '{}', true) ?: [];
        $items = OutpostDB::fetchAll(
            "SELECT id, slug, data, status FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$col['id']]
        );
        foreach ($items as $item) {
            $data = json_decode($item['data'] ?: '{}', true) ?: [];
            $title = $data['title'] ?? $data['name'] ?? $item['slug'];
            $contentText = search_extract_text_from_data($data, $schema);
            $image = search_find_image($data, $schema);

            // Build URL from url_pattern
            $urlPattern = $col['url_pattern'] ?: '/' . $col['slug'] . '/{slug}';
            $url = str_replace('{slug}', $item['slug'], $urlPattern);

            OutpostDB::insert('search_index', [
                'item_type'       => 'collection_item',
                'item_id'         => $item['id'],
                'collection_slug' => $col['slug'],
                'title'           => $title,
                'content_text'    => $contentText,
                'url'             => $url,
                'image'           => $image,
            ]);
            $count++;
        }
    }

    return $count;
}

// ── Incremental Indexing ────────────────────────────────

function search_index_page(int $pageId): void {
    search_ensure_tables();

    $theme = search_get_active_theme();
    $page = OutpostDB::fetchOne("SELECT id, path, title FROM pages WHERE id = ?", [$pageId]);
    if (!$page) return;

    // Skip system pages
    if ($page['path'] === '__global__' || str_starts_with($page['path'], '/outpost')) return;

    // Remove old entry
    search_remove_item('page', $pageId);

    // Check visibility — skip non-public pages
    try {
        $vRow = OutpostDB::fetchOne("SELECT COALESCE(visibility, 'public') as vis FROM pages WHERE id = ?", [$pageId]);
        if ($vRow && $vRow['vis'] !== 'public') return;
    } catch (\Throwable $e) {
        // visibility column may not exist yet — proceed
    }

    // Check status — skip drafts
    try {
        $sRow = OutpostDB::fetchOne("SELECT COALESCE(status, 'published') as st FROM pages WHERE id = ?", [$pageId]);
        if ($sRow && $sRow['st'] === 'draft') return;
    } catch (\Throwable $e) {
        // status column may not exist yet — proceed
    }

    // Gather field text
    $fields = OutpostDB::fetchAll(
        "SELECT field_name, field_type, content FROM fields WHERE page_id = ? AND theme = ?",
        [$pageId, $theme]
    );
    $textParts = [];
    foreach ($fields as $f) {
        $type = $f['field_type'] ?? 'text';
        if (in_array($type, ['text', 'richtext', 'textarea', 'select'])) {
            $val = search_strip_html($f['content'] ?? '');
            if ($val !== '') $textParts[] = $val;
        }
    }

    $title = $page['title'] ?: $page['path'];
    $url = $page['path'] === '/' ? '/' : '/' . ltrim($page['path'], '/');

    OutpostDB::insert('search_index', [
        'item_type'    => 'page',
        'item_id'      => $pageId,
        'collection_slug' => null,
        'title'        => $title,
        'content_text' => implode(' ', $textParts),
        'url'          => $url,
        'image'        => '',
    ]);
}

function search_index_item(int $itemId): void {
    search_ensure_tables();

    $item = OutpostDB::fetchOne("SELECT ci.*, c.slug as collection_slug, c.url_pattern, c.schema FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE ci.id = ?", [$itemId]);
    if (!$item) return;

    // Remove old entry
    search_remove_item('collection_item', $itemId);

    // Only index published items
    if (($item['status'] ?? 'draft') !== 'published') return;

    $schema = json_decode($item['schema'] ?: '{}', true) ?: [];
    $data = json_decode($item['data'] ?: '{}', true) ?: [];
    $title = $data['title'] ?? $data['name'] ?? $item['slug'];
    $contentText = search_extract_text_from_data($data, $schema);
    $image = search_find_image($data, $schema);

    $urlPattern = $item['url_pattern'] ?: '/' . $item['collection_slug'] . '/{slug}';
    $url = str_replace('{slug}', $item['slug'], $urlPattern);

    OutpostDB::insert('search_index', [
        'item_type'       => 'collection_item',
        'item_id'         => $itemId,
        'collection_slug' => $item['collection_slug'],
        'title'           => $title,
        'content_text'    => $contentText,
        'url'             => $url,
        'image'           => $image,
    ]);
}

function search_remove_item(string $type, int $id): void {
    search_ensure_tables();
    OutpostDB::query("DELETE FROM search_index WHERE item_type = ? AND item_id = ?", [$type, $id]);
}

// ── Search Query ────────────────────────────────────────

function search_query(string $query, int $limit = 20, int $offset = 0): array {
    search_ensure_tables();

    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return ['results' => [], 'total' => 0];
    }

    $words = array_filter(array_map('trim', explode(' ', $query)));
    if (empty($words)) {
        return ['results' => [], 'total' => 0];
    }

    $conditions = [];
    $params = [];
    foreach ($words as $word) {
        // Escape LIKE wildcards in user input
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $word);
        $conditions[] = "(title LIKE ? ESCAPE '\\' OR content_text LIKE ? ESCAPE '\\')";
        $params[] = '%' . $escaped . '%';
        $params[] = '%' . $escaped . '%';
    }
    $where = implode(' AND ', $conditions);

    // Get total count
    $countRow = OutpostDB::fetchOne("SELECT COUNT(*) as c FROM search_index WHERE {$where}", $params);
    $total = (int)($countRow['c'] ?? 0);

    // Get results — title matches ranked higher via ORDER BY
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);

    // Build a relevance score: title match = higher priority
    $firstWord = str_replace(['%', '_'], ['\\%', '\\_'], $words[0]);
    $scoreParams = ['%' . $firstWord . '%'];
    $allParams = array_merge($scoreParams, $params, [$limit, $offset]);

    $rows = OutpostDB::fetchAll(
        "SELECT *, (CASE WHEN title LIKE ? ESCAPE '\\' THEN 1 ELSE 0 END) as relevance
         FROM search_index WHERE {$where}
         ORDER BY relevance DESC, updated_at DESC
         LIMIT ? OFFSET ?",
        $allParams
    );

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'type'            => $row['item_type'],
            'id'              => (int)$row['item_id'],
            'collection_slug' => $row['collection_slug'],
            'title'           => $row['title'],
            'url'             => $row['url'],
            'image'           => $row['image'] ?: null,
            'excerpt'         => search_excerpt($row['content_text'], $query),
        ];
    }

    return ['results' => $results, 'total' => $total];
}

// ── Excerpt Generator ───────────────────────────────────

function search_excerpt(string $text, string $query, int $contextChars = 200): string {
    if ($text === '') return '';

    $words = array_filter(array_map('trim', explode(' ', $query)));
    if (empty($words)) return mb_substr($text, 0, $contextChars);

    // Find first occurrence of any query word (case-insensitive)
    $bestPos = mb_strlen($text); // fallback: start of text
    foreach ($words as $word) {
        $pos = mb_stripos($text, $word);
        if ($pos !== false && $pos < $bestPos) {
            $bestPos = $pos;
        }
    }

    if ($bestPos >= mb_strlen($text)) {
        // No word found, show start of text
        $bestPos = 0;
    }

    // Extract context around the match
    $half = (int)($contextChars / 2);
    $start = max(0, $bestPos - $half);
    $excerpt = mb_substr($text, $start, $contextChars);

    // Add ellipsis
    $prefix = $start > 0 ? '…' : '';
    $suffix = ($start + $contextChars) < mb_strlen($text) ? '…' : '';

    // HTML-escape the excerpt BEFORE adding <mark> tags (XSS prevention)
    $excerpt = htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');

    // Highlight matching words with <mark> tags
    foreach ($words as $word) {
        $escaped = preg_quote(htmlspecialchars($word, ENT_QUOTES, 'UTF-8'), '/');
        $excerpt = preg_replace('/(' . $escaped . ')/iu', '<mark>$1</mark>', $excerpt);
    }

    return $prefix . $excerpt . $suffix;
}

// ── API Handlers ────────────────────────────────────────

function handle_search_site(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Rate limit: 30 requests per 60 seconds per IP
    outpost_ip_rate_limit('search_site', 30, 60);

    search_ensure_tables();

    $q = trim($_GET['q'] ?? '');
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);

    if ($limit < 1) $limit = 20;
    if ($limit > 50) $limit = 50;
    if ($offset < 0) $offset = 0;

    if (mb_strlen($q) < 2) {
        echo json_encode(['results' => [], 'total' => 0, 'query' => $q]);
        exit;
    }

    // Auto-reindex if search_index is empty
    $countRow = OutpostDB::fetchOne("SELECT COUNT(*) as c FROM search_index");
    if (($countRow['c'] ?? 0) == 0) {
        search_reindex();
    }

    $result = search_query($q, $limit, $offset);
    $result['query'] = $q;

    // Log the search to analytics_searches if track.php pattern exists
    try {
        require_once __DIR__ . '/track.php';
        ensure_analytics_searches_table();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $salt = '';
        try { $salt = get_analytics_salt(); } catch (\Throwable $e) {}
        $sessionId = hash('sha256', $ip . $ua . date('Y-m-d') . $salt);
        OutpostDB::insert('analytics_searches', [
            'query'         => mb_substr($q, 0, 200),
            'results_count' => $result['total'],
            'clicked_path'  => null,
            'session_id'    => $sessionId,
        ]);
    } catch (\Throwable $e) {
        // Non-critical — don't fail the search if logging fails
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_search_reindex(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $count = search_reindex();
    echo json_encode(['success' => true, 'indexed' => $count]);
    exit;
}

// ── Router ──────────────────────────────────────────────

function handle_search_request(string $action, string $method): void {
    // CORS headers for public API
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    search_ensure_tables();

    match ($action) {
        'search/site'    => $method === 'GET' ? handle_search_site() : search_error('Method not allowed', 405),
        'search/reindex' => $method === 'POST' ? handle_search_reindex() : search_error('Method not allowed', 405),
        default          => search_error('Not found', 404),
    };
}

function search_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}
