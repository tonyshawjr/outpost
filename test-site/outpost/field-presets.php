<?php
/**
 * Outpost — Reusable Field Presets (v6 Section 1)
 *
 * Lets developers define a named bundle of fields once ("image with credit",
 * "address block", "SEO meta") and drop the preset into any collection schema.
 *
 * Storage:
 *   field_presets table — slug + name + JSON fields[]
 *
 * Resolution:
 *   At schema-render time the editor expands { type: 'preset', preset: 'slug' }
 *   into the preset's field list. Presets can also be inlined into the
 *   "object" field type's children.
 */

function ensure_field_presets_table(): void {
    OutpostDB::connect()->exec("
        CREATE TABLE IF NOT EXISTS field_presets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            fields TEXT NOT NULL DEFAULT '[]',
            built_in INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )
    ");
    seed_built_in_field_presets();
}

/**
 * Seed a small set of useful built-in presets on first migration.
 * Built-in presets cannot be deleted or renamed (slug is locked) but their
 * field shape can still be customized — those edits persist.
 */
function seed_built_in_field_presets(): void {
    $existing = OutpostDB::fetchOne('SELECT COUNT(*) AS c FROM field_presets WHERE built_in = 1');
    if ($existing && (int) $existing['c'] > 0) {
        return;
    }
    $presets = [
        [
            'slug' => 'image-with-credit',
            'name' => 'Image with credit',
            'description' => 'Image, alt text, credit line, and optional caption.',
            'fields' => [
                ['key' => 'image',   'label' => 'Image',   'type' => 'image'],
                ['key' => 'alt',     'label' => 'Alt text','type' => 'text'],
                ['key' => 'credit',  'label' => 'Credit',  'type' => 'text'],
                ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
            ],
        ],
        [
            'slug' => 'seo-meta',
            'name' => 'SEO meta',
            'description' => 'Title, description, social share image.',
            'fields' => [
                ['key' => 'title',       'label' => 'SEO title',       'type' => 'text'],
                ['key' => 'description', 'label' => 'SEO description', 'type' => 'textarea'],
                ['key' => 'og_image',    'label' => 'Share image',     'type' => 'image'],
            ],
        ],
        [
            'slug' => 'cta',
            'name' => 'Call to action',
            'description' => 'Button label and destination.',
            'fields' => [
                ['key' => 'label', 'label' => 'Button label', 'type' => 'text'],
                ['key' => 'href',  'label' => 'Link',         'type' => 'text'],
                ['key' => 'style', 'label' => 'Style',        'type' => 'select',
                 'options' => ['primary', 'secondary', 'ghost']],
            ],
        ],
    ];
    foreach ($presets as $p) {
        OutpostDB::insert('field_presets', [
            'slug'        => $p['slug'],
            'name'        => $p['name'],
            'description' => $p['description'],
            'fields'      => json_encode($p['fields']),
            'built_in'    => 1,
        ]);
    }
}

function field_presets_list(): array {
    $rows = OutpostDB::fetchAll('SELECT * FROM field_presets ORDER BY built_in DESC, name ASC');
    foreach ($rows as &$r) {
        $r['fields'] = json_decode($r['fields'] ?: '[]', true) ?: [];
        $r['built_in'] = (int) $r['built_in'] === 1;
    }
    return $rows;
}

function field_preset_get_by_slug(string $slug): ?array {
    $row = OutpostDB::fetchOne('SELECT * FROM field_presets WHERE slug = ?', [$slug]);
    if (!$row) return null;
    $row['fields'] = json_decode($row['fields'] ?: '[]', true) ?: [];
    $row['built_in'] = (int) $row['built_in'] === 1;
    return $row;
}

/**
 * Expand a schema array — replacing any { type: 'preset', preset: 'slug' }
 * entries with the resolved field list from field_presets. Unknown presets
 * are dropped (loud-fails would break the editor for old schemas).
 */
function field_presets_expand_schema(array $fields): array {
    $expanded = [];
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        if (($f['type'] ?? '') === 'preset' && !empty($f['preset'])) {
            $preset = field_preset_get_by_slug((string) $f['preset']);
            if ($preset) {
                $prefix = $f['key'] ?? '';
                foreach ($preset['fields'] as $pf) {
                    if ($prefix !== '') {
                        $pf['key'] = $prefix . '.' . ($pf['key'] ?? '');
                    }
                    $expanded[] = $pf;
                }
            }
            continue;
        }
        // Recurse into object field children
        if (($f['type'] ?? '') === 'object' && !empty($f['fields']) && is_array($f['fields'])) {
            $f['fields'] = field_presets_expand_schema($f['fields']);
        }
        $expanded[] = $f;
    }
    return $expanded;
}

// ── API handlers ─────────────────────────────────────────────────────────

function handle_field_presets_list(): void {
    json_response(['presets' => field_presets_list()]);
}

function handle_field_preset_get(): void {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    if ($slug === '') {
        json_response(['error' => 'slug required'], 400);
        return;
    }
    $preset = field_preset_get_by_slug($slug);
    if (!$preset) {
        json_response(['error' => 'Preset not found'], 404);
        return;
    }
    json_response(['preset' => $preset]);
}

function handle_field_preset_create(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $slug = outpost_slugify((string) ($body['slug'] ?? $body['name'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $fields = $body['fields'] ?? [];
    if ($slug === '' || $name === '' || !is_array($fields)) {
        json_response(['error' => 'slug, name, and fields[] required'], 400);
        return;
    }
    if (field_preset_get_by_slug($slug)) {
        json_response(['error' => 'A preset with that slug already exists'], 409);
        return;
    }
    OutpostDB::insert('field_presets', [
        'slug'        => $slug,
        'name'        => $name,
        'description' => $description,
        'fields'      => json_encode(array_values($fields)),
        'built_in'    => 0,
    ]);
    json_response(['preset' => field_preset_get_by_slug($slug)]);
}

function handle_field_preset_update(): void {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    $existing = field_preset_get_by_slug($slug);
    if (!$existing) {
        json_response(['error' => 'Preset not found'], 404);
        return;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $patch = [];
    if (isset($body['name']))        $patch['name']        = trim((string) $body['name']);
    if (isset($body['description'])) $patch['description'] = trim((string) $body['description']);
    if (isset($body['fields']) && is_array($body['fields'])) {
        $patch['fields'] = json_encode(array_values($body['fields']));
    }
    if ($patch) {
        $patch['updated_at'] = date('Y-m-d H:i:s');
        OutpostDB::update('field_presets', $patch, 'id = ?', [$existing['id']]);
    }
    json_response(['preset' => field_preset_get_by_slug($slug)]);
}

function handle_field_preset_delete(): void {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    $existing = field_preset_get_by_slug($slug);
    if (!$existing) {
        json_response(['error' => 'Preset not found'], 404);
        return;
    }
    if ($existing['built_in']) {
        json_response(['error' => 'Built-in presets cannot be deleted'], 403);
        return;
    }
    OutpostDB::delete('field_presets', 'id = ?', [$existing['id']]);
    json_response(['success' => true]);
}
