<?php
/**
 * Outpost — Click-to-edit chrome (v6 Section 2.5)
 *
 * Server side has two responsibilities:
 *   1. Inject the overlay script + stylesheet into rendered pages when the
 *      visitor is an authenticated admin. This is wired up in engine.php.
 *   2. Resolve a click → editor intent. The browser sends the data-outpost-*
 *      attributes from the clicked element; we figure out which admin route
 *      should open. The browser then navigates the admin SPA to that route.
 *
 * Resolution rules (in order):
 *   - data-outpost-block ID present  → /page-builder/{page_id}?block={block_id}
 *                                      (collection items use the same when
 *                                      the collection schema has a blocks field)
 *   - data-outpost-collection + item → /collections/{slug}/{item_id}?focus={field}
 *   - data-outpost-page              → /pages/{page_id}?focus={field}
 *   - data-outpost field on a regular page → /pages?path={page_path}&focus={field}
 *
 * The intent itself is a string the SPA understands; we don't redirect on
 * the server because that would log the admin out of the public site.
 */

/**
 * Returns true if the request is from an authenticated admin viewing the
 * public site (so we should inject the overlay).
 */
function outpost_is_admin_viewing(): bool {
    if (!class_exists('OutpostAuth')) return false;
    try {
        return (bool) OutpostAuth::check();
    } catch (\Throwable $e) {
        return false;
    }
}

function outpost_click_to_edit_overlay_script_url(): string {
    return '/outpost/click-to-edit.js?v=' . OUTPOST_VERSION;
}

function outpost_click_to_edit_overlay_style_url(): string {
    return '/outpost/click-to-edit.css?v=' . OUTPOST_VERSION;
}

/**
 * HTML to inject into <head> on every rendered admin-viewer page. Keep tiny
 * — just the script + style URLs and a small global so the script knows the
 * admin base URL and CSRF token (so XHR doesn't fail).
 */
function outpost_click_to_edit_inject_head(): string {
    if (!outpost_is_admin_viewing()) return '';
    $adminBase = '/outpost/admin/';
    $apiBase = '/outpost/api.php';
    $csrf = '';
    try {
        if (class_exists('OutpostAuth') && method_exists('OutpostAuth', 'csrfToken')) {
            $csrf = (string) OutpostAuth::csrfToken();
        }
    } catch (\Throwable $e) {
        $csrf = '';
    }
    $cfg = json_encode([
        'adminBase' => $adminBase,
        'apiBase'   => $apiBase,
        'csrf'      => $csrf,
        'version'   => OUTPOST_VERSION,
    ], JSON_UNESCAPED_SLASHES);
    $scriptUrl = htmlspecialchars(outpost_click_to_edit_overlay_script_url(), ENT_QUOTES, 'UTF-8');
    $styleUrl  = htmlspecialchars(outpost_click_to_edit_overlay_style_url(), ENT_QUOTES, 'UTF-8');
    return "<link rel=\"stylesheet\" href=\"{$styleUrl}\" data-outpost-edit-style>"
         . "<script>window.__OUTPOST_EDIT__ = {$cfg};</script>"
         . "<script src=\"{$scriptUrl}\" defer data-outpost-edit-script></script>";
}

// ── Edit-intent resolution ───────────────────────────────────────────────

function _intent_resolve(array $hints): array {
    $blockId = isset($hints['block']) ? (int) $hints['block'] : 0;
    $pageId  = isset($hints['page'])  ? (int) $hints['page']  : 0;
    $field   = isset($hints['field']) ? trim((string) $hints['field']) : '';
    $coll    = isset($hints['collection']) ? trim((string) $hints['collection']) : '';
    $item    = isset($hints['item']) ? trim((string) $hints['item']) : '';
    $path    = isset($hints['path']) ? trim((string) $hints['path']) : '';

    // 1. Block edit → page builder
    if ($blockId > 0) {
        $row = OutpostDB::fetchOne(
            'SELECT pb.id, pb.page_id, p.path FROM page_blocks pb
             LEFT JOIN pages p ON p.id = pb.page_id
             WHERE pb.id = ?',
            [$blockId]
        );
        if ($row) {
            $url = '#/page-builder/' . (int) $row['page_id'] . '?block=' . (int) $row['id'];
            if ($field !== '') $url .= '&field=' . urlencode($field);
            return ['route' => $url, 'kind' => 'page-builder'];
        }
    }

    // 2. Collection item → collection editor
    if ($coll !== '' && $item !== '') {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $coll)) {
            return ['route' => '#/collections', 'kind' => 'fallback'];
        }
        // Item can be either a numeric id or a slug
        $itemId = ctype_digit($item) ? (int) $item : 0;
        if ($itemId === 0) {
            $found = OutpostDB::fetchOne(
                'SELECT ci.id FROM collection_items ci
                 JOIN collections c ON c.id = ci.collection_id
                 WHERE c.slug = ? AND ci.slug = ?',
                [$coll, $item]
            );
            if ($found) $itemId = (int) $found['id'];
        }
        if ($itemId > 0) {
            // Decide between post-editor (Ghost-style) and form editor based
            // on the collection's schema shape — collections with a "blocks"
            // field land in the page builder; otherwise the post/form editor.
            $col = OutpostDB::fetchOne('SELECT schema FROM collections WHERE slug = ?', [$coll]);
            $schema = $col ? json_decode((string) ($col['schema'] ?? '{}'), true) : null;
            $hasBlocks = false;
            if (is_array($schema) && is_array($schema['fields'] ?? null)) {
                foreach ($schema['fields'] as $f) {
                    if (is_array($f) && (($f['type'] ?? '') === 'blocks')) { $hasBlocks = true; break; }
                }
            }
            $route = $hasBlocks
                ? '#/collections/' . urlencode($coll) . '/' . $itemId . '/blocks'
                : '#/collections/' . urlencode($coll) . '/' . $itemId;
            if ($field !== '') $route .= ($hasBlocks ? '&' : '?') . 'focus=' . urlencode($field);
            return ['route' => $route, 'kind' => $hasBlocks ? 'page-builder' : 'collection-item'];
        }
    }

    // 3. Page-level field → page edit
    if ($pageId > 0) {
        $route = '#/pages/' . $pageId;
        if ($field !== '') $route .= '?focus=' . urlencode($field);
        return ['route' => $route, 'kind' => 'page'];
    }

    // 4. Path fallback → resolve path to page id
    if ($path !== '') {
        $row = OutpostDB::fetchOne('SELECT id FROM pages WHERE path = ?', [$path]);
        if ($row) {
            $route = '#/pages/' . (int) $row['id'];
            if ($field !== '') $route .= '?focus=' . urlencode($field);
            return ['route' => $route, 'kind' => 'page'];
        }
    }

    return ['route' => '#/dashboard', 'kind' => 'fallback'];
}

function handle_edit_intent_resolve(): void {
    $hints = [
        'block'      => $_GET['block'] ?? null,
        'page'       => $_GET['page'] ?? null,
        'field'      => $_GET['field'] ?? null,
        'collection' => $_GET['collection'] ?? null,
        'item'       => $_GET['item'] ?? null,
        'path'       => $_GET['path'] ?? null,
    ];
    json_response(_intent_resolve($hints));
}
