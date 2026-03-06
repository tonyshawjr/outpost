<?php
/**
 * Outpost CMS — Channel Engine
 * Sync external REST APIs into local cache for template rendering.
 */

// ── Fetch helpers ───────────────────────────────────────

/**
 * Make a single HTTP request to an external API.
 * Returns ['status' => int, 'body' => string, 'decoded' => array|null, 'error' => string|null]
 */
function channel_fetch_api(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 0, 'body' => '', 'decoded' => null, 'error' => 'No URL configured'];

    // SSRF guard — block private IPs and dangerous protocols
    try {
        outpost_ssrf_guard($url);
    } catch (\RuntimeException $e) {
        return ['status' => 0, 'body' => '', 'decoded' => null, 'error' => $e->getMessage()];
    }

    $method = strtoupper($config['method'] ?? 'GET');

    // Build query params
    $params = $config['params'] ?? [];
    if ($params && $method === 'GET') {
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . http_build_query($params);
    }

    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'User-Agent: Outpost-CMS/1.0'];

    // Custom headers
    foreach (($config['headers'] ?? []) as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }

    // Auth
    $authType = $config['auth_type'] ?? 'none';
    $authConfig = $config['auth_config'] ?? [];
    switch ($authType) {
        case 'api_key':
            $headerName = $authConfig['api_key_header'] ?? 'X-API-Key';
            $headers[] = $headerName . ': ' . ($authConfig['api_key'] ?? '');
            break;
        case 'bearer':
            $headers[] = 'Authorization: Bearer ' . ($authConfig['bearer_token'] ?? '');
            break;
        case 'basic':
            curl_setopt($ch, CURLOPT_USERPWD, ($authConfig['basic_username'] ?? '') . ':' . ($authConfig['basic_password'] ?? ''));
            break;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (isset($config['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($config['body']));
        }
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 0, 'body' => '', 'decoded' => null, 'error' => 'cURL error: ' . $error];
    }

    $decoded = json_decode($body, true);
    if ($status >= 400) {
        return ['status' => $status, 'body' => $body, 'decoded' => $decoded, 'error' => "HTTP {$status}"];
    }

    return ['status' => $status, 'body' => $body, 'decoded' => $decoded, 'error' => null];
}

/**
 * Fetch all pages from a paginated API.
 * Returns the combined array of all items.
 */
function channel_fetch_all_pages(array $config): array {
    $pagination = $config['pagination'] ?? ['type' => 'none'];
    $paginationType = $pagination['type'] ?? 'none';

    if ($paginationType === 'none') {
        $result = channel_fetch_api($config);
        if ($result['error']) return ['items' => [], 'error' => $result['error']];
        $dataPath = $config['data_path'] ?? '';
        $items = $dataPath ? channel_extract_data($result['decoded'], $dataPath) : $result['decoded'];
        return ['items' => is_array($items) ? $items : [], 'error' => null];
    }

    $allItems = [];
    $page = 1;
    $maxPages = 50; // Safety limit
    $cursor = null;

    while ($page <= $maxPages) {
        $pageConfig = $config;

        if ($paginationType === 'offset') {
            $pageConfig['params'] = array_merge($pageConfig['params'] ?? [], [
                ($pagination['page_param'] ?? 'page') => $page,
                ($pagination['limit_param'] ?? 'limit') => ($pagination['per_page'] ?? 50),
            ]);
        } elseif ($paginationType === 'cursor' && $cursor) {
            $pageConfig['params'] = array_merge($pageConfig['params'] ?? [], [
                ($pagination['cursor_param'] ?? 'after') => $cursor,
            ]);
        }

        $result = channel_fetch_api($pageConfig);
        if ($result['error']) break;

        $dataPath = $config['data_path'] ?? '';
        $items = $dataPath ? channel_extract_data($result['decoded'], $dataPath) : $result['decoded'];
        if (!is_array($items) || empty($items)) break;

        $allItems = array_merge($allItems, $items);

        // Check for next cursor
        if ($paginationType === 'cursor') {
            $cursorPath = $pagination['cursor_path'] ?? '';
            $cursor = $cursorPath ? channel_extract_data($result['decoded'], $cursorPath) : null;
            if (!$cursor) break;
        }

        // If fewer items than per_page, we're done
        $perPage = (int)($pagination['per_page'] ?? 50);
        if (count($items) < $perPage) break;

        $page++;
    }

    return ['items' => $allItems, 'error' => null];
}

/**
 * Extract data from a nested JSON response using dot-notation path.
 * e.g. "data.listings" => $response['data']['listings']
 */
function channel_extract_data($response, string $dataPath) {
    if (!$dataPath || !is_array($response)) return $response;

    $keys = explode('.', $dataPath);
    $current = $response;

    foreach ($keys as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return null;
        }
        $current = $current[$key];
    }

    return $current;
}

/**
 * Discover schema from the first item in a response array.
 * Returns an array of field definitions: [{name, type, sample, children?}]
 */
function channel_discover_schema($data, int $maxDepth = 5, int $depth = 0): array {
    if (!is_array($data) || $depth >= $maxDepth) return [];

    // If it's a numeric array, use the first item
    if (isset($data[0])) {
        $data = $data[0];
    }

    $fields = [];
    foreach ($data as $key => $value) {
        $field = ['name' => (string)$key, 'type' => gettype($value)];

        if (is_null($value)) {
            $field['type'] = 'string';
            $field['sample'] = null;
        } elseif (is_bool($value)) {
            $field['type'] = 'boolean';
            $field['sample'] = $value;
        } elseif (is_int($value) || is_float($value)) {
            $field['type'] = 'number';
            $field['sample'] = $value;
        } elseif (is_string($value)) {
            $field['type'] = 'string';
            $field['sample'] = mb_substr($value, 0, 200);
        } elseif (is_array($value)) {
            if (isset($value[0])) {
                $field['type'] = 'array';
                $field['sample'] = count($value) . ' items';
                $field['children'] = channel_discover_schema($value[0], $maxDepth, $depth + 1);
            } else {
                $field['type'] = 'object';
                $field['sample'] = null;
                $field['children'] = channel_discover_schema($value, $maxDepth, $depth + 1);
            }
        }

        $fields[] = $field;
    }

    return $fields;
}


// ── Sync ────────────────────────────────────────────────

/**
 * Sync a channel: fetch from external API, upsert items into channel_items.
 */
function channel_sync(int $channelId): array {
    $startTime = microtime(true);
    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE id = ?', [$channelId]);
    if (!$channel) return ['error' => 'Channel not found'];

    $config = json_decode($channel['config'], true) ?: [];
    $fieldMap = json_decode($channel['field_map'], true) ?: [];
    $idField = $config['id_field'] ?? 'id';
    $slugField = $config['slug_field'] ?? 'slug';
    $maxItems = (int)($channel['max_items'] ?: 100);
    $sortField = $channel['sort_field'] ?? '';

    // Log entry
    $logId = OutpostDB::insert('channel_sync_log', [
        'channel_id' => $channelId,
        'status' => 'running',
    ]);

    // Fetch all data
    $result = channel_fetch_all_pages($config);

    if ($result['error']) {
        $duration = (int)((microtime(true) - $startTime) * 1000);
        OutpostDB::update('channel_sync_log', [
            'status' => 'error',
            'error_message' => $result['error'],
            'duration_ms' => $duration,
        ], 'id = ?', [$logId]);
        OutpostDB::update('channels', [
            'last_error' => $result['error'],
            'last_sync_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$channelId]);
        return ['error' => $result['error']];
    }

    $items = array_slice($result['items'], 0, $maxItems);
    $added = 0;
    $updated = 0;
    $removed = 0;

    // Get existing external IDs for upsert
    $existingItems = OutpostDB::fetchAll(
        'SELECT id, external_id FROM channel_items WHERE channel_id = ?',
        [$channelId]
    );
    $existingMap = [];
    foreach ($existingItems as $ei) {
        if ($ei['external_id']) $existingMap[$ei['external_id']] = (int)$ei['id'];
    }

    $seenIds = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;

        // Apply field map: filter to only mapped fields, or use all if no map
        $data = $item;
        if (!empty($fieldMap)) {
            $mapped = [];
            foreach ($fieldMap as $fm) {
                $source = $fm['source'] ?? $fm;
                $target = $fm['target'] ?? $source;
                $mapped[$target] = channel_extract_data($item, $source);
            }
            $data = $mapped;
        }

        $externalId = (string)($item[$idField] ?? '');
        $slug = (string)($item[$slugField] ?? $externalId);
        // Sanitize slug
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        if (!$slug) $slug = 'item-' . ($externalId ?: uniqid());

        $sortValue = $sortField ? (string)($item[$sortField] ?? '') : '';

        if ($externalId) $seenIds[] = $externalId;

        // Upsert
        if ($externalId && isset($existingMap[$externalId])) {
            OutpostDB::update('channel_items', [
                'slug' => $slug,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'sort_value' => $sortValue,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$existingMap[$externalId]]);
            $updated++;
        } else {
            OutpostDB::insert('channel_items', [
                'channel_id' => $channelId,
                'external_id' => $externalId,
                'slug' => $slug,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'sort_value' => $sortValue,
            ]);
            $added++;
        }
    }

    // Remove items no longer in the feed
    if (!empty($seenIds)) {
        $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
        $removeParams = array_merge([$channelId], $seenIds);
        $toRemove = OutpostDB::fetchAll(
            "SELECT id FROM channel_items WHERE channel_id = ? AND external_id NOT IN ({$placeholders}) AND external_id IS NOT NULL AND external_id != ''",
            $removeParams
        );
        foreach ($toRemove as $r) {
            OutpostDB::delete('channel_items', 'id = ?', [$r['id']]);
            $removed++;
        }
    }

    $duration = (int)((microtime(true) - $startTime) * 1000);

    // Update sync log
    OutpostDB::update('channel_sync_log', [
        'status' => 'success',
        'items_synced' => count($items),
        'items_added' => $added,
        'items_updated' => $updated,
        'items_removed' => $removed,
        'duration_ms' => $duration,
    ], 'id = ?', [$logId]);

    // Update channel
    OutpostDB::update('channels', [
        'last_sync_at' => date('Y-m-d H:i:s'),
        'last_error' => null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$channelId]);

    return [
        'success' => true,
        'items_synced' => count($items),
        'items_added' => $added,
        'items_updated' => $updated,
        'items_removed' => $removed,
        'duration_ms' => $duration,
    ];
}

/**
 * Check if a channel needs syncing based on cache_ttl.
 */
function channel_needs_sync(array $channel): bool {
    if (!$channel['last_sync_at']) return true;
    $ttl = (int)($channel['cache_ttl'] ?? 3600);
    if ($ttl <= 0) return false; // manual only
    $lastSync = strtotime($channel['last_sync_at']);
    return (time() - $lastSync) >= $ttl;
}


// ── Template functions ──────────────────────────────────

/**
 * Iterate channel items — used in templates via {% for item in channel.slug %}
 * Pattern matches cms_collection_list() in engine.php.
 */
function cms_channel_list(string $slug, callable $callback, array $opts = []): void {
    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE slug = ? AND status = ?', [$slug, 'active']);
    if (!$channel) return;

    // Auto-sync if stale
    if (channel_needs_sync($channel)) {
        try { channel_sync((int)$channel['id']); } catch (\Throwable $e) {
            error_log('Outpost channel auto-sync error (' . $slug . '): ' . $e->getMessage());
        }
    }

    $limit = (int)($opts['limit'] ?? 0);
    $orderBy = 'sort_value';
    $direction = strtoupper($channel['sort_direction'] ?? 'DESC');
    if (!in_array($direction, ['ASC', 'DESC'])) $direction = 'DESC';

    if (isset($opts['order'])) {
        // opts['order'] is like 'field DESC'
        $orderBy = 'sort_value'; // always sort by sort_value since data is JSON
        $parts = explode(' ', $opts['order']);
        if (count($parts) >= 2 && in_array(strtoupper($parts[1]), ['ASC', 'DESC'])) {
            $direction = strtoupper($parts[1]);
        }
    }

    $sql = "SELECT * FROM channel_items WHERE channel_id = ? ORDER BY {$orderBy} {$direction}";
    $params = [(int)$channel['id']];

    if ($limit > 0) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
    }

    $items = OutpostDB::fetchAll($sql, $params);

    foreach ($items as $row) {
        $data = json_decode($row['data'], true) ?: [];
        $data['_id'] = $row['id'];
        $data['_external_id'] = $row['external_id'];
        $data['_slug'] = $row['slug'];
        $data['url'] = '';
        if ($channel['url_pattern']) {
            $data['url'] = str_replace('{slug}', $row['slug'], $channel['url_pattern']);
        }
        $callback($data);
    }
}

/**
 * Get a single channel item by slug — used in templates via {% single item from channel.slug %}
 */
function cms_channel_single(string $channelSlug, string $itemSlug = ''): ?array {
    if (!$itemSlug) {
        $itemSlug = $_GET['slug'] ?? '';
    }
    if (!$itemSlug) return null;

    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE slug = ? AND status = ?', [$channelSlug, 'active']);
    if (!$channel) return null;

    // Auto-sync if stale
    if (channel_needs_sync($channel)) {
        try { channel_sync((int)$channel['id']); } catch (\Throwable $e) {
            error_log('Outpost channel auto-sync error (' . $channelSlug . '): ' . $e->getMessage());
        }
    }

    $item = OutpostDB::fetchOne(
        'SELECT * FROM channel_items WHERE channel_id = ? AND (slug = ? OR external_id = ?)',
        [(int)$channel['id'], $itemSlug, $itemSlug]
    );

    if (!$item) return null;

    $data = json_decode($item['data'], true) ?: [];
    $data['_id'] = $item['id'];
    $data['_external_id'] = $item['external_id'];
    $data['_slug'] = $item['slug'];
    $data['url'] = '';
    if ($channel['url_pattern']) {
        $data['url'] = str_replace('{slug}', $item['slug'], $channel['url_pattern']);
    }

    return $data;
}

/**
 * Get count of cached items for a channel.
 */
function cms_channel_count(string $slug): int {
    $channel = OutpostDB::fetchOne('SELECT id FROM channels WHERE slug = ? AND status = ?', [$slug, 'active']);
    if (!$channel) return 0;
    $row = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM channel_items WHERE channel_id = ?', [(int)$channel['id']]);
    return (int)($row['c'] ?? 0);
}

/**
 * Get channel metadata.
 */
function cms_channel_meta(string $slug, string $key): string {
    $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE slug = ? AND status = ?', [$slug, 'active']);
    if (!$channel) return '';
    return match($key) {
        'last_sync' => $channel['last_sync_at'] ?? '',
        'name' => $channel['name'] ?? '',
        'count' => (string)cms_channel_count($slug),
        default => '',
    };
}
