<?php
/**
 * Outpost — Theme schema.json autoboot (v6 Section 1.3)
 *
 * A theme can ship a `schema.json` at its root that declares the collections
 * (and seed channel definitions) the theme expects. On activation — or when
 * the user clicks "Bootstrap from theme" in the admin — we read it and create
 * any missing collections so the theme works out of the box on a fresh install.
 *
 * The theme schema.json shape:
 * {
 *   "collections": [
 *     {
 *       "slug": "blog",
 *       "name": "Blog",
 *       "singular_name": "Post",
 *       "url_pattern": "/blog/{slug}",
 *       "schema": [
 *         { "key": "title",  "type": "text",     "label": "Title" },
 *         { "key": "body",   "type": "richtext", "label": "Body" },
 *         { "key": "cover",  "type": "image",    "label": "Cover" }
 *       ]
 *     }
 *   ]
 * }
 *
 * Only missing collections are created. We never touch existing collections
 * (the user owns their schema after first creation — Sanity-style schemaless
 * iteration handles ongoing field changes).
 */

function _theme_slug_is_safe(string $themeSlug): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $themeSlug);
}

function _theme_schema_json_path(string $themeSlug): ?string {
    if (!_theme_slug_is_safe($themeSlug)) return null;
    return rtrim(OUTPOST_THEMES_DIR, '/') . '/' . $themeSlug . '/schema.json';
}

function _read_theme_schema(string $themeSlug): ?array {
    $path = _theme_schema_json_path($themeSlug);
    if ($path === null || !is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Returns a preview of what would be created if we bootstrapped the named
 * theme. Per-collection rows include `would_create: bool` so the UI can show
 * a confirm dialog with the diff.
 */
function theme_bootstrap_preview(string $themeSlug): array {
    $schema = _read_theme_schema($themeSlug);
    if ($schema === null) {
        return ['has_schema' => false, 'collections' => []];
    }
    $defs = is_array($schema['collections'] ?? null) ? $schema['collections'] : [];
    $report = [];
    foreach ($defs as $def) {
        $slug = trim((string) ($def['slug'] ?? ''));
        if ($slug === '') continue;
        $existing = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug]);
        $report[] = [
            'slug'         => $slug,
            'name'         => (string) ($def['name'] ?? ucwords(str_replace('-', ' ', $slug))),
            'would_create' => $existing === null,
        ];
    }
    return ['has_schema' => true, 'collections' => $report];
}

/**
 * Creates any missing collections from the theme's schema.json. Returns the
 * list of collection slugs that were actually created (so the UI can show a
 * "Created N collections" message).
 *
 * Idempotent — calling this twice does nothing the second time.
 */
function theme_bootstrap_apply(string $themeSlug): array {
    $schema = _read_theme_schema($themeSlug);
    if ($schema === null) return [];
    $defs = is_array($schema['collections'] ?? null) ? $schema['collections'] : [];
    $created = [];
    foreach ($defs as $def) {
        $slug = trim((string) ($def['slug'] ?? ''));
        if ($slug === '') continue;
        if (OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug])) {
            continue;
        }
        $name          = trim((string) ($def['name'] ?? ucwords(str_replace('-', ' ', $slug))));
        $singularName  = trim((string) ($def['singular_name'] ?? rtrim($name, 's')));
        $urlPattern    = trim((string) ($def['url_pattern'] ?? ''));
        $templatePath  = trim((string) ($def['template_path'] ?? ''));
        $sortField     = trim((string) ($def['sort_field'] ?? 'created_at'));
        $sortDirection = strtoupper(trim((string) ($def['sort_direction'] ?? 'DESC')));
        $itemsPerPage  = (int) ($def['items_per_page'] ?? 10);
        $fields        = is_array($def['schema'] ?? null) ? $def['schema'] : [];

        OutpostDB::insert('collections', [
            'slug'           => $slug,
            'name'           => $name,
            'singular_name'  => $singularName,
            'schema'         => json_encode(['fields' => $fields]),
            'url_pattern'    => $urlPattern,
            'template_path'  => $templatePath,
            'sort_field'     => in_array($sortField, ['created_at', 'updated_at', 'published_at', 'title', 'slug', 'sort_order'], true) ? $sortField : 'created_at',
            'sort_direction' => $sortDirection === 'ASC' ? 'ASC' : 'DESC',
            'items_per_page' => $itemsPerPage > 0 && $itemsPerPage <= 200 ? $itemsPerPage : 10,
        ]);
        $created[] = $slug;
    }
    return $created;
}

// ── API handlers ─────────────────────────────────────────────────────────

function handle_theme_bootstrap_preview(): void {
    $theme = trim((string) ($_GET['theme'] ?? ''));
    if ($theme === '') {
        // Default to active theme
        $theme = outpost_get_active_theme();
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
        json_response(['error' => 'Invalid theme slug'], 400);
        return;
    }
    json_response(['theme' => $theme] + theme_bootstrap_preview($theme));
}

function handle_theme_bootstrap(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $theme = trim((string) ($body['theme'] ?? outpost_get_active_theme()));
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
        json_response(['error' => 'Invalid theme slug'], 400);
        return;
    }
    $created = theme_bootstrap_apply($theme);
    json_response([
        'theme'   => $theme,
        'created' => $created,
        'count'   => count($created),
    ]);
}
