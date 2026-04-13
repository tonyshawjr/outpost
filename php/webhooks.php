<?php
/**
 * Outpost CMS — Webhook Engine
 *
 * Dispatch webhooks to external URLs on CMS events.
 * Immediate delivery with queue-based retry via cron.
 */

require_once __DIR__ . '/ranger.php';

// ── Database Migration ───────────────────────────────────
function ensure_webhooks_tables(): void {
    $db = OutpostDB::connect();

    $db->exec("CREATE TABLE IF NOT EXISTS webhooks (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL DEFAULT '',
        url        TEXT    NOT NULL,
        secret     TEXT    NOT NULL,
        events     TEXT    NOT NULL DEFAULT '[]',
        headers    TEXT    NOT NULL DEFAULT '{}',
        active     INTEGER NOT NULL DEFAULT 1,
        created_at TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        webhook_id    INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
        event         TEXT    NOT NULL,
        payload       TEXT    NOT NULL DEFAULT '{}',
        status        TEXT    NOT NULL DEFAULT 'pending',
        attempts      INTEGER NOT NULL DEFAULT 0,
        status_code   INTEGER,
        response_body TEXT,
        next_retry_at TEXT,
        created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
        completed_at  TEXT
    )");
}

// ── Dispatch ─────────────────────────────────────────────
/**
 * Fire a webhook event. Called from API handlers.
 * Never throws — wrapped in try/catch to protect the caller.
 */
function dispatch_webhook(string $event, array $data = []): void {
    try {
        $webhooks = OutpostDB::fetchAll(
            'SELECT * FROM webhooks WHERE active = 1'
        );

        foreach ($webhooks as $wh) {
            $events = json_decode($wh['events'], true) ?: [];
            if (!in_array('*', $events) && !in_array($event, $events)) {
                continue;
            }

            $payload = json_encode([
                'event'     => $event,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'data'      => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $deliveryId = OutpostDB::insert('webhook_deliveries', [
                'webhook_id' => $wh['id'],
                'event'      => $event,
                'payload'    => $payload,
                'status'     => 'pending',
            ]);

            webhook_attempt_delivery($deliveryId, $wh, $payload);
        }
    } catch (\Throwable $e) {
        error_log('Outpost webhook dispatch error: ' . $e->getMessage());
    }
}

// ── Delivery ─────────────────────────────────────────────
function webhook_attempt_delivery(int $deliveryId, array $webhook, string $payload): void {
    try {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Outpost-CMS/1.0',
            'X-Outpost-Event: ' . (json_decode($payload, true)['event'] ?? ''),
            'X-Outpost-Delivery: ' . $deliveryId,
            'X-Outpost-Signature: sha256=' . hash_hmac('sha256', $payload, safe_decrypt($webhook['secret'])),
        ];

        // Add custom headers
        $custom = json_decode($webhook['headers'] ?? '{}', true) ?: [];
        foreach ($custom as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        // SSRF guard — block private IPs and dangerous protocols
        $resolvedIp = outpost_ssrf_guard($webhook['url']);
        $parsed = parse_url($webhook['url']);
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);

        $ch = curl_init($webhook['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE         => ["{$host}:{$port}:{$resolvedIp}"],
        ]);

        $body       = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        // Increment attempts
        $delivery = OutpostDB::fetchOne('SELECT attempts FROM webhook_deliveries WHERE id = ?', [$deliveryId]);
        $attempts = ($delivery['attempts'] ?? 0) + 1;

        if ($curlError || $statusCode < 200 || $statusCode >= 300) {
            // Failed — schedule retry
            $retryDelays = [60, 300, 1800, 7200, 43200]; // 1m, 5m, 30m, 2h, 12h
            $retryIndex  = min($attempts - 1, count($retryDelays) - 1);

            if ($attempts >= 5) {
                OutpostDB::update('webhook_deliveries', [
                    'status'        => 'failed',
                    'attempts'      => $attempts,
                    'status_code'   => $statusCode ?: null,
                    'response_body' => mb_substr($curlError ?: ($body ?: ''), 0, 2000),
                    'completed_at'  => date('Y-m-d H:i:s'),
                ], 'id = ?', [$deliveryId]);
            } else {
                $nextRetry = date('Y-m-d H:i:s', time() + $retryDelays[$retryIndex]);
                OutpostDB::update('webhook_deliveries', [
                    'status'        => 'retrying',
                    'attempts'      => $attempts,
                    'status_code'   => $statusCode ?: null,
                    'response_body' => mb_substr($curlError ?: ($body ?: ''), 0, 2000),
                    'next_retry_at' => $nextRetry,
                ], 'id = ?', [$deliveryId]);
            }
        } else {
            // Success
            OutpostDB::update('webhook_deliveries', [
                'status'        => 'success',
                'attempts'      => $attempts,
                'status_code'   => $statusCode,
                'response_body' => mb_substr($body ?: '', 0, 2000),
                'completed_at'  => date('Y-m-d H:i:s'),
            ], 'id = ?', [$deliveryId]);
        }
    } catch (\Throwable $e) {
        error_log('Outpost webhook delivery error: ' . $e->getMessage());
        OutpostDB::update('webhook_deliveries', [
            'status'        => 'failed',
            'response_body' => mb_substr($e->getMessage(), 0, 2000),
            'completed_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$deliveryId]);
    }
}

// ── Retry Processing (called from cron) ──────────────────
function webhook_process_retries(): void {
    try {
        $due = OutpostDB::fetchAll(
            "SELECT wd.*, w.url, w.secret, w.headers, w.active
             FROM webhook_deliveries wd
             JOIN webhooks w ON wd.webhook_id = w.id
             WHERE wd.status = 'retrying'
               AND wd.next_retry_at <= datetime('now')
             ORDER BY wd.next_retry_at ASC
             LIMIT 20"
        );

        foreach ($due as $d) {
            if (!$d['active']) {
                // Webhook disabled — mark failed
                OutpostDB::update('webhook_deliveries', [
                    'status'       => 'failed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$d['id']]);
                continue;
            }

            webhook_attempt_delivery($d['id'], [
                'id'      => $d['webhook_id'],
                'url'     => $d['url'],
                'secret'  => $d['secret'],
                'headers' => $d['headers'],
            ], $d['payload']);
        }
    } catch (\Throwable $e) {
        error_log('Outpost webhook retry error: ' . $e->getMessage());
    }
}

// ── Cleanup ──────────────────────────────────────────────
function webhook_cleanup_deliveries(): void {
    try {
        // Successful deliveries older than 7 days
        OutpostDB::query(
            "DELETE FROM webhook_deliveries WHERE status = 'success' AND completed_at < datetime('now', '-7 days')"
        );
        // Failed deliveries older than 30 days
        OutpostDB::query(
            "DELETE FROM webhook_deliveries WHERE status = 'failed' AND completed_at < datetime('now', '-30 days')"
        );
    } catch (\Throwable $e) {
        error_log('Outpost webhook cleanup error: ' . $e->getMessage());
    }
}

// ── CRUD Handlers ────────────────────────────────────────

function handle_webhooks_list(): void {
    $rows = OutpostDB::fetchAll('SELECT id, name, url, events, active, created_at, updated_at FROM webhooks ORDER BY created_at DESC');
    foreach ($rows as &$r) {
        $r['events'] = json_decode($r['events'], true) ?: [];
    }
    json_response(['webhooks' => $rows]);
}

function handle_webhook_get(): void {
    $id = (int) $_GET['id'];
    $wh = OutpostDB::fetchOne('SELECT id, name, url, events, headers, active, created_at, updated_at FROM webhooks WHERE id = ?', [$id]);
    if (!$wh) json_error('Webhook not found', 404);
    $wh['events']  = json_decode($wh['events'], true) ?: [];
    $wh['headers'] = json_decode($wh['headers'], true) ?: (object)[];
    json_response(['webhook' => $wh]);
}

function handle_webhook_create(): void {
    $data = get_json_body();
    $url  = trim($data['url'] ?? '');
    $name = trim($data['name'] ?? '');
    if (!$url) json_error('URL is required');
    if (!filter_var($url, FILTER_VALIDATE_URL)) json_error('Invalid URL');
    try { outpost_ssrf_guard($url); } catch (\RuntimeException $e) { json_error($e->getMessage()); } // validation only

    $events  = $data['events'] ?? ['*'];
    $headers = $data['headers'] ?? (object)[];
    $active  = $data['active'] ?? true;
    $secret  = bin2hex(random_bytes(32));

    $id = OutpostDB::insert('webhooks', [
        'name'    => $name,
        'url'     => $url,
        'secret'  => ranger_encrypt($secret),
        'events'  => json_encode(array_values($events)),
        'headers' => json_encode($headers),
        'active'  => $active ? 1 : 0,
    ]);

    json_response([
        'success' => true,
        'id'      => $id,
        'secret'  => $secret,
    ], 201);
}

function handle_webhook_update(): void {
    $id   = (int) $_GET['id'];
    $data = get_json_body();

    $wh = OutpostDB::fetchOne('SELECT id FROM webhooks WHERE id = ?', [$id]);
    if (!$wh) json_error('Webhook not found', 404);

    $update = ['updated_at' => date('Y-m-d H:i:s')];
    if (isset($data['name']))    $update['name']    = trim($data['name']);
    if (isset($data['url'])) {
        if (!filter_var($data['url'], FILTER_VALIDATE_URL)) json_error('Invalid URL');
        try { outpost_ssrf_guard($data['url']); } catch (\RuntimeException $e) { json_error($e->getMessage()); } // validation only
        $update['url'] = trim($data['url']);
    }
    if (isset($data['events']))  $update['events']  = json_encode(array_values($data['events']));
    if (isset($data['headers'])) $update['headers'] = json_encode($data['headers']);
    if (isset($data['active']))  $update['active']  = $data['active'] ? 1 : 0;

    OutpostDB::update('webhooks', $update, 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_webhook_delete(): void {
    $id = (int) $_GET['id'];
    $wh = OutpostDB::fetchOne('SELECT id FROM webhooks WHERE id = ?', [$id]);
    if (!$wh) json_error('Webhook not found', 404);
    OutpostDB::delete('webhooks', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_webhook_regenerate_secret(): void {
    $id = (int) $_GET['id'];
    $wh = OutpostDB::fetchOne('SELECT id FROM webhooks WHERE id = ?', [$id]);
    if (!$wh) json_error('Webhook not found', 404);

    $secret = bin2hex(random_bytes(32));
    OutpostDB::update('webhooks', [
        'secret'     => ranger_encrypt($secret),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    json_response(['success' => true, 'secret' => $secret]);
}

function handle_webhook_deliveries(): void {
    $id    = (int) $_GET['id'];
    $limit = min((int) ($_GET['limit'] ?? 50), 100);

    $deliveries = OutpostDB::fetchAll(
        'SELECT id, event, status, attempts, status_code, response_body, created_at, completed_at
         FROM webhook_deliveries
         WHERE webhook_id = ?
         ORDER BY created_at DESC
         LIMIT ?',
        [$id, $limit]
    );

    json_response(['deliveries' => $deliveries]);
}

function handle_webhook_test(): void {
    $id = (int) $_GET['id'];
    $wh = OutpostDB::fetchOne('SELECT * FROM webhooks WHERE id = ?', [$id]);
    if (!$wh) json_error('Webhook not found', 404);

    $payload = json_encode([
        'event'     => 'test',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'data'      => ['message' => 'This is a test webhook from Outpost CMS.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $deliveryId = OutpostDB::insert('webhook_deliveries', [
        'webhook_id' => $wh['id'],
        'event'      => 'test',
        'payload'    => $payload,
        'status'     => 'pending',
    ]);

    webhook_attempt_delivery($deliveryId, $wh, $payload);

    $result = OutpostDB::fetchOne('SELECT status, status_code, response_body FROM webhook_deliveries WHERE id = ?', [$deliveryId]);

    json_response([
        'success'     => $result['status'] === 'success',
        'status'      => $result['status'],
        'status_code' => $result['status_code'],
        'response'    => $result['response_body'],
    ]);
}
