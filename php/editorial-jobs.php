<?php
/**
 * Outpost — Editorial AI scheduler (v6 Section 5)
 *
 * Cron-driven AI content production. A user defines a job: cadence, prompt,
 * target collection, status (draft/published). The cron worker picks up due
 * jobs, runs them through Ranger (the existing AI assistant), and inserts
 * the result as a new collection_item.
 *
 * Cost-control rule: AI is summoned or scheduled, never ambient. Per-job
 * AND daily-spend caps are enforced before each run; if the daily cap has
 * been hit, all further runs that day are skipped (and logged).
 *
 * SHIPPING SCOPE: data layer + API + cron entry point + budget caps.
 * Actual generation is handed to Ranger (`ranger_generate_for_job`) which
 * already exists for chat — this is the cron-side handoff. If Ranger isn't
 * configured (no API key) the job stages a placeholder draft so editors see
 * the schedule is alive even before AI is wired.
 */

function ensure_editorial_jobs_table(): void {
    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS editorial_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            cadence TEXT NOT NULL DEFAULT 'manual',
            cron_expr TEXT DEFAULT '',
            collection_slug TEXT NOT NULL,
            prompt TEXT NOT NULL DEFAULT '',
            target_status TEXT NOT NULL DEFAULT 'draft',
            cost_cap_cents INTEGER NOT NULL DEFAULT 50,
            enabled INTEGER NOT NULL DEFAULT 1,
            last_run_at TEXT,
            next_run_at TEXT,
            created_by INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS editorial_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            started_at TEXT DEFAULT (datetime('now')),
            finished_at TEXT,
            status TEXT NOT NULL DEFAULT 'running',
            cost_cents INTEGER NOT NULL DEFAULT 0,
            created_item_id INTEGER,
            error TEXT DEFAULT '',
            log TEXT DEFAULT '',
            FOREIGN KEY (job_id) REFERENCES editorial_jobs(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_editorial_runs_job ON editorial_runs(job_id, started_at DESC)");

    // Default daily budget setting (in cents) if not already set
    $exists = OutpostDB::fetchOne("SELECT key FROM settings WHERE key = 'editorial_daily_cap_cents'");
    if (!$exists) {
        OutpostDB::insert('settings', [
            'key' => 'editorial_daily_cap_cents',
            'value' => '500', // $5/day default
        ]);
    }
}

function editorial_daily_cap_cents(): int {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'editorial_daily_cap_cents'");
    return (int) ($row['value'] ?? 500);
}

function editorial_today_spend_cents(): int {
    $row = OutpostDB::fetchOne(
        "SELECT COALESCE(SUM(cost_cents), 0) AS total FROM editorial_runs
         WHERE date(started_at) = date('now')"
    );
    return (int) ($row['total'] ?? 0);
}

/**
 * Run a single job. Returns the created run id or null on skip.
 */
function editorial_run_job(int $jobId, bool $force = false): ?int {
    $job = OutpostDB::fetchOne('SELECT * FROM editorial_jobs WHERE id = ?', [$jobId]);
    if (!$job) return null;
    if (!$force && (int) $job['enabled'] !== 1) return null;

    // Daily cap check
    $cap = editorial_daily_cap_cents();
    $spent = editorial_today_spend_cents();
    if ($spent >= $cap) {
        OutpostDB::insert('editorial_runs', [
            'job_id' => $jobId,
            'status' => 'skipped',
            'finished_at' => date('Y-m-d H:i:s'),
            'cost_cents' => 0,
            'log' => "Skipped: daily cap reached ({$spent}/{$cap} cents)",
        ]);
        return null;
    }

    $runId = OutpostDB::insert('editorial_runs', [
        'job_id' => $jobId,
        'status' => 'running',
        'cost_cents' => 0,
    ]);

    try {
        $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$job['collection_slug']]);
        if (!$collection) {
            throw new RuntimeException('Target collection not found: ' . $job['collection_slug']);
        }

        // Hand off to Ranger if available; otherwise stage a placeholder so the
        // editor sees the job ran. The Ranger integration is intentionally a
        // soft dependency — Outpost shouldn't fail jobs because no API key is set.
        $generated = null;
        $costCents = 0;
        if (function_exists('ranger_generate_for_job')) {
            $result = ranger_generate_for_job([
                'prompt'         => $job['prompt'],
                'collection'     => $collection,
                'cost_cap_cents' => (int) $job['cost_cap_cents'],
            ]);
            $generated = is_array($result) ? ($result['data'] ?? null) : null;
            $costCents = is_array($result) ? (int) ($result['cost_cents'] ?? 0) : 0;
        }

        if (!is_array($generated)) {
            $generated = [
                'title' => '[Draft staged] ' . $job['name'] . ' — ' . date('M j, Y g:ia'),
                'body'  => "Editorial job ran on " . date('Y-m-d H:i:s') . ".\n\nPrompt:\n" . $job['prompt']
                          . "\n\nNo AI provider is configured — this is a placeholder so you can see the schedule is alive.",
            ];
        }

        $slug = outpost_slugify(($generated['title'] ?? 'editorial-draft') . '-' . substr(bin2hex(random_bytes(4)), 0, 6));

        $itemId = OutpostDB::insert('collection_items', [
            'collection_id' => $collection['id'],
            'slug'          => $slug,
            'status'        => $job['target_status'] === 'published' ? 'published' : 'draft',
            'data'          => json_encode($generated),
        ]);

        OutpostDB::update('editorial_runs', [
            'status'          => 'completed',
            'finished_at'     => date('Y-m-d H:i:s'),
            'cost_cents'      => $costCents,
            'created_item_id' => $itemId,
        ], 'id = ?', [$runId]);

        OutpostDB::update('editorial_jobs', [
            'last_run_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobId]);

        return $runId;
    } catch (\Throwable $e) {
        OutpostDB::update('editorial_runs', [
            'status'      => 'failed',
            'finished_at' => date('Y-m-d H:i:s'),
            'error'       => substr($e->getMessage(), 0, 500),
        ], 'id = ?', [$runId]);
        return $runId;
    }
}

/**
 * Cron entry point — call via the existing cron-key-protected endpoint.
 * Runs every job whose `next_run_at` is past or null on a daily cadence.
 */
function editorial_cron_tick(): array {
    $jobs = OutpostDB::fetchAll(
        "SELECT id FROM editorial_jobs
         WHERE enabled = 1
           AND cadence != 'manual'
           AND (next_run_at IS NULL OR next_run_at <= datetime('now'))"
    );
    $ran = [];
    foreach ($jobs as $j) {
        $runId = editorial_run_job((int) $j['id']);
        if ($runId) $ran[] = $runId;
        // Schedule next run based on cadence
        $cadence = OutpostDB::fetchOne('SELECT cadence FROM editorial_jobs WHERE id = ?', [$j['id']])['cadence'] ?? 'daily';
        $next = match ($cadence) {
            'hourly' => '+1 hour',
            'daily'  => '+1 day',
            'weekly' => '+1 week',
            default  => '+1 day',
        };
        OutpostDB::update('editorial_jobs', [
            'next_run_at' => date('Y-m-d H:i:s', strtotime($next)),
        ], 'id = ?', [$j['id']]);
    }
    return $ran;
}

/**
 * Find-and-update across collection_items.data — the "find every mention of
 * 'apply now' and change to 'enroll today'" feature. Walks every item's data
 * JSON and replaces literal-string matches in any string-typed field.
 *
 * Returns: ['matched' => N, 'updated_items' => [item_ids]]
 *
 * Always creates a revision row before mutating an item so the change is
 * recoverable.
 */
function editorial_find_and_update(string $find, string $replace, ?string $collectionSlug = null): array {
    if ($find === '') return ['matched' => 0, 'updated_items' => []];
    $where = '1=1';
    $params = [];
    if ($collectionSlug !== null && preg_match('/^[a-zA-Z0-9_-]+$/', $collectionSlug)) {
        $where = 'collection_id = (SELECT id FROM collections WHERE slug = ?)';
        $params[] = $collectionSlug;
    }
    $items = OutpostDB::fetchAll(
        "SELECT id, data FROM collection_items WHERE {$where}",
        $params
    );
    $updatedItems = [];
    $matched = 0;
    $userId = (int) (OutpostAuth::currentUser()['id'] ?? 0);
    foreach ($items as $item) {
        $data = json_decode((string) $item['data'], true);
        if (!is_array($data)) continue;
        $changed = false;
        $count = 0;
        $walk = function (&$node) use ($find, $replace, &$changed, &$count, &$walk) {
            if (is_string($node)) {
                if (strpos($node, $find) !== false) {
                    $hits = substr_count($node, $find);
                    $node = str_replace($find, $replace, $node);
                    $changed = true;
                    $count += $hits;
                }
            } elseif (is_array($node)) {
                foreach ($node as &$child) $walk($child);
            }
        };
        $walk($data);
        if ($changed) {
            // snapshot
            OutpostDB::insert('revisions', [
                'entity_type' => 'collection_item.find_and_update',
                'entity_id'   => (int) $item['id'],
                'data'        => (string) $item['data'],
                'meta'        => json_encode(['find' => $find, 'replace' => $replace, 'count' => $count]),
                'created_by'  => $userId ?: null,
            ]);
            OutpostDB::update('collection_items', [
                'data'       => json_encode($data),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$item['id']]);
            $updatedItems[] = (int) $item['id'];
            $matched += $count;
        }
    }
    return ['matched' => $matched, 'updated_items' => $updatedItems];
}

// ── API handlers ─────────────────────────────────────────────────────────

function handle_editorial_jobs_list(): void {
    $jobs = OutpostDB::fetchAll(
        'SELECT id, name, description, cadence, collection_slug, target_status,
                cost_cap_cents, enabled, last_run_at, next_run_at, created_at
           FROM editorial_jobs ORDER BY created_at DESC'
    );
    foreach ($jobs as &$j) $j['enabled'] = (int) $j['enabled'] === 1;
    json_response(['jobs' => $jobs]);
}

function handle_editorial_job_create(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string) ($body['name'] ?? ''));
    $collection = trim((string) ($body['collection_slug'] ?? ''));
    $prompt = trim((string) ($body['prompt'] ?? ''));
    if ($name === '' || $collection === '' || $prompt === '') {
        json_response(['error' => 'name, collection_slug and prompt are required'], 400);
        return;
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $collection)) {
        json_response(['error' => 'invalid collection slug'], 400);
        return;
    }
    $cadence = (string) ($body['cadence'] ?? 'manual');
    if (!in_array($cadence, ['manual', 'hourly', 'daily', 'weekly'], true)) {
        json_response(['error' => 'invalid cadence'], 400);
        return;
    }
    $costCap = (int) ($body['cost_cap_cents'] ?? 50);
    if ($costCap < 1) $costCap = 1;
    if ($costCap > 10000) $costCap = 10000;
    $targetStatus = ($body['target_status'] ?? 'draft') === 'published' ? 'published' : 'draft';

    $id = OutpostDB::insert('editorial_jobs', [
        'name'            => $name,
        'description'     => trim((string) ($body['description'] ?? '')),
        'cadence'         => $cadence,
        'collection_slug' => $collection,
        'prompt'          => $prompt,
        'target_status'   => $targetStatus,
        'cost_cap_cents'  => $costCap,
        'enabled'         => !empty($body['enabled']) ? 1 : 0,
        'created_by'      => (OutpostAuth::currentUser()['id'] ?? null),
    ]);
    json_response(['id' => $id]);
}

function handle_editorial_job_update(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $patch = [];
    foreach (['name', 'description', 'prompt', 'target_status'] as $f) {
        if (array_key_exists($f, $body)) $patch[$f] = trim((string) $body[$f]);
    }
    if (array_key_exists('cadence', $body)) {
        $cadence = (string) $body['cadence'];
        if (!in_array($cadence, ['manual', 'hourly', 'daily', 'weekly'], true)) {
            json_response(['error' => 'invalid cadence'], 400);
            return;
        }
        $patch['cadence'] = $cadence;
    }
    if (array_key_exists('collection_slug', $body)) {
        $slug = trim((string) $body['collection_slug']);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            json_response(['error' => 'invalid collection slug'], 400);
            return;
        }
        $patch['collection_slug'] = $slug;
    }
    if (array_key_exists('cost_cap_cents', $body)) {
        $cap = (int) $body['cost_cap_cents'];
        $patch['cost_cap_cents'] = max(1, min(10000, $cap));
    }
    if (array_key_exists('enabled', $body)) {
        $patch['enabled'] = !empty($body['enabled']) ? 1 : 0;
    }
    $patch['updated_at'] = date('Y-m-d H:i:s');
    OutpostDB::update('editorial_jobs', $patch, 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_editorial_job_delete(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }
    OutpostDB::delete('editorial_jobs', 'id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_editorial_job_run_now(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) { json_response(['error' => 'id required'], 400); return; }
    $runId = editorial_run_job($id, true);
    json_response(['run_id' => $runId]);
}

function handle_editorial_runs_list(): void {
    $jobId = (int) ($_GET['job_id'] ?? 0);
    if ($jobId > 0) {
        $rows = OutpostDB::fetchAll(
            'SELECT * FROM editorial_runs WHERE job_id = ? ORDER BY started_at DESC LIMIT 50',
            [$jobId]
        );
    } else {
        $rows = OutpostDB::fetchAll(
            'SELECT r.*, j.name AS job_name FROM editorial_runs r
             LEFT JOIN editorial_jobs j ON j.id = r.job_id
             ORDER BY r.started_at DESC LIMIT 100'
        );
    }
    json_response(['runs' => $rows]);
}

function handle_editorial_budget_get(): void {
    $cap = editorial_daily_cap_cents();
    $spent = editorial_today_spend_cents();
    json_response([
        'daily_cap_cents'  => $cap,
        'spent_today_cents' => $spent,
        'remaining_cents'  => max(0, $cap - $spent),
    ]);
}

function handle_editorial_budget_update(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $cap = (int) ($body['daily_cap_cents'] ?? 0);
    if ($cap < 0) $cap = 0;
    if ($cap > 100000) $cap = 100000;
    $exists = OutpostDB::fetchOne("SELECT key FROM settings WHERE key = 'editorial_daily_cap_cents'");
    if ($exists) {
        OutpostDB::update('settings', ['value' => (string) $cap], "key = 'editorial_daily_cap_cents'");
    } else {
        OutpostDB::insert('settings', ['key' => 'editorial_daily_cap_cents', 'value' => (string) $cap]);
    }
    json_response(['daily_cap_cents' => $cap]);
}

function handle_editorial_find_and_update(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $find = (string) ($body['find'] ?? '');
    $replace = (string) ($body['replace'] ?? '');
    $collection = isset($body['collection']) ? (string) $body['collection'] : null;
    if ($find === '') {
        json_response(['error' => 'find string required'], 400);
        return;
    }
    if (mb_strlen($find) < 2) {
        json_response(['error' => 'find string too short (min 2 chars)'], 400);
        return;
    }
    $result = editorial_find_and_update($find, $replace, $collection);
    json_response($result);
}
