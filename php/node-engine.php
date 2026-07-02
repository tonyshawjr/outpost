<?php
/**
 * Outpost — Node-Tree Engine (v6 visual page-builder spine).
 *
 * A page's layout is a flat-map node tree:
 *   { "root": "n_root", "nodes": { "<id>": <node>, ... } }
 * where each node is:
 *   { id, type, tag, props:{}, classes:[], styles:{}, children:[<id>,...] }
 *
 * This file is the canonical PHP side: the type registry, structural
 * validation/sanitisation, and a recursive node -> semantic-HTML renderer.
 * The JS editor store mirrors this shape; the renderer here is the seed of
 * the eventual publisher (clean HTML out, no builder cruft).
 */

/** Node type registry: allowed tags (first = default) + whether it nests. */
function outpost_node_types(): array {
    return [
        'container' => [
            'tags' => ['div', 'section', 'main', 'header', 'footer', 'article', 'aside', 'nav', 'ul', 'ol', 'li', 'figure'],
            'children' => true,
            'void' => false,
        ],
        'text' => [
            'tags' => ['p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'blockquote', 'label'],
            'children' => false,
            'void' => false,
        ],
        'image' => [
            'tags' => ['img'],
            'children' => false,
            'void' => true,
        ],
        'button' => [
            'tags' => ['button', 'a'],
            'children' => false,
            'void' => false,
        ],
        'link' => [
            'tags' => ['a'],
            'children' => false,
            'void' => false,
        ],
        'component-ref' => [
            'tags' => ['div'],
            'children' => false,
            'void' => false,
        ],
        'embed' => [
            'tags' => ['div'],
            'children' => false,
            'void' => false,
        ],
    ];
}

/**
 * The shared builder rulebook: node schema, styling, dynamic islands, and the
 * apply_ops operation vocabulary. Single source of truth served to BOTH the
 * in-app AI sidebar (system prompt) and the builder MCP (resource + tool docs)
 * so the terminal and the panel build by the same rules.
 */
function outpost_builder_conventions(): string {
    return <<<GUIDE
# Outpost page model
A page is a tree of nodes. Each node has: id, type, tag, props, classes, children.

Node types and their allowed tags:
- container — div, section, main, header, footer, article, aside, nav, ul, ol, li, figure (holds children)
- text — p, span, h1, h2, h3, h4, h5, h6, strong, em, small, blockquote, label (props.text holds the text; no children)
- image — img (props.src, props.alt; no children)
- button — button, a (props.text, props.href; no children)
- link — a (props.text, props.href; no children)

Only container nodes can hold children. Put text inside containers as separate text nodes; never nest text in text.

# Styling
Style with CSS classes, not inline styles. Define a class once with define_class, then attach it to nodes. Reuse existing classes when they fit. Prefer design tokens (CSS variables) for colors and spacing so the page stays on-brand.

# Dynamic content (islands)
A node can be bound to a dynamic field so its content is editable as managed content and rendered server-side. Bind a field with bind_field when the content should be editable or data-driven (e.g. a hero headline, a price, a description). Give the field a short snake_case name. This is the "static page with dynamic holes" model — the page bakes to static HTML except the bound fields.

# Operations (the apply_ops / apply_page_ops vocabulary)
Operations run in order. Supported operations:
- {"op":"insert_tree","parent":<id|"root"|"selected">,"index":<int?>,"node":<spec>} — insert a subtree. A spec is {"type","tag"?,"text"?,"src"?,"alt"?,"href"?,"classes"?:[...],"field"?,"ref"?,"children"?:[spec...]}. Use "ref" to name a created node and reference it later in the same batch (as a parent or target). This is the main tool for building.
- {"op":"update","id":<id|ref>,"text"?,"href"?,"src"?,"alt"?,"tag"?} — change a node's content or tag.
- {"op":"set_classes","id":<id|ref>,"classes":[...]} — replace a node's class list.
- {"op":"add_class","id":<id|ref>,"class":"name"} / {"op":"remove_class","id":<id|ref>,"class":"name"}
- {"op":"move","id":<id|ref>,"parent":<id|ref>,"index":<int?>}
- {"op":"duplicate","id":<id|ref>} / {"op":"remove","id":<id|ref>}
- {"op":"define_class","name":"hero","declarations":{"padding":"var(--space-l)","background":"var(--surface)"}} — create or update a CSS class. Property names are kebab-case CSS properties; values are plain CSS (no semicolons, no braces). Declarations may nest: a key starting with "&" is a nested selector and a key like "@media (max-width: 600px)" is an at-rule, each mapping to its own declaration object — e.g. {"opacity":"1","&:hover":{"opacity":"0.8"},"@media (max-width: 600px)":{"padding":"1rem"}}.
- {"op":"bind_field","id":<id|ref>,"field":"hero_title","fieldType":"text"} — make a node a dynamic island.

# Rules
- Reference existing nodes by their real id (from the page context). Reference nodes you create in the same batch by "ref".
- Class names must be valid CSS identifiers (letters, numbers, hyphens, underscores).
- Build complete, semantic, accessible markup: real headings in order, alt text on images, descriptive link text.
- Batch a coherent change into a single operation list when you can.
- If a request is ambiguous, make a sensible choice and build it; it can be refined afterward.
GUIDE;
}

function outpost_import_conventions(): string {
    return <<<GUIDE
# Outpost import-ready HTML

Produce a self-contained section as three separate parts — HTML, CSS, and JavaScript. Outpost's Import "explodes" the HTML into editable nodes, merges the CSS into the site stylesheet, and appends the JS to the page. Emit the three parts as separate fenced blocks (```html / ```css / ```js) or the three fields of the emit_section tool. Keep each part focused on ONE section.

# HTML
- One top-level element wraps the section (it becomes the section root). Build real, semantic, accessible markup: headings in order, alt text on every image, descriptive link text.
- Only these tags survive the import; use nothing else:
  - Containers (hold children): div, section, main, header, footer, article, aside, nav, ul, ol, li, figure
  - Text (content only, no children): p, span, h1–h6, strong, em, small, blockquote, label
  - Media: img (src, alt) · Interactive: a (href), button
- A text element's text is taken whole — do NOT nest elements inside a text element. `<p>Save <strong>50%</strong></p>` flattens to the plain text "Save 50%". To style part of a line, make the pieces siblings inside a container (e.g. a `<div>` holding a `<span>` and a `<span class="accent">`), not children of one `<p>`.
- Any other tag (table, svg, input, select, form, video, iframe, web components) collapses to a plain div and loses its attributes; script/style/link/meta/br/hr are dropped entirely. Do not rely on them — put behavior in the JS part, decoration in CSS.
- Style ONLY with the class attribute. Inline style="" is discarded on import.

# Dynamic holes (the "static except the holes" model)
- Add data-outpost="snake_case_name" to any text/image/button/link element to make its content an editable, server-rendered field. The page bakes to static HTML except these holes, which fill from managed content at request time — editing a hole updates the live page with no rebuild.
- Optional: data-type="text|richtext|image|url" and data-scope="global" (site-wide field shared across pages).
- Use holes for anything editable or data-driven: hero headline, subhead, price, CTA label, hero image. Leave purely decorative/structural text as plain nodes.

# CSS
- Target elements by a SINGLE class only: `.hero-title { ... }`. Comma groups are fine: `.a, .b { ... }`.
- Supported: base rules, pseudo-classes/elements on one class (`.btn:hover`, `.card::before`), and `@media` / `@container` / `@supports` blocks whose inner rules are single-class rules. These map to the builder's nested-style model, so hover states and responsive rules survive.
- NOT supported — ignored on import, so never depend on them: descendant/child combinators (`.a .b`, `.a > .b`), compound/multi-class selectors (`.a.b`), element/id/attribute-only selectors, `@keyframes`, `@import`. Give every element its own class and style it by that class.
- Prefer CSS variables (design tokens) for color and spacing so the section stays on-brand. No `expression()`, no `url(javascript:)`, no `behavior`.

# JavaScript
- Vanilla JS, no build step, no framework. It runs on the PUBLISHED page only — not in the builder canvas — and is appended to the page's script file.
- Scope every query to the section's own classes so it can't touch the rest of the page. Guard for elements possibly not present.

# Class names
Letters, numbers, hyphens, underscores. BEM is encouraged (`.hero`, `.hero__title`, `.hero__cta--ghost`).

# Example
```html
<section class="cta">
  <p class="cta__eyebrow" data-outpost="cta_eyebrow">Limited beta</p>
  <h2 class="cta__title" data-outpost="cta_title">Ship pages before lunch</h2>
  <a class="cta__btn" href="/signup" data-outpost="cta_href">Start free</a>
</section>
```
```css
.cta { padding: var(--space-xl, 4rem) 1.5rem; text-align: center; background: var(--surface, #0b0b12); color: #fff; }
.cta__title { font-size: clamp(1.75rem, 4vw, 2.75rem); margin: 0 0 1rem; }
.cta__btn { display: inline-block; padding: 0.8rem 1.6rem; border-radius: 10px; background: var(--primary, #6d5efc); color: #fff; text-decoration: none; }
.cta__btn:hover { transform: translateY(-2px); }
@media (max-width: 600px) { .cta { padding: 2.5rem 1rem; } }
```
```js
document.querySelectorAll('.cta__btn').forEach((b) => b.addEventListener('click', () => {}));
```
GUIDE;
}

/** Generate a node id matching the JS store format (n_ + 10 hex). */
function outpost_node_id(): string {
    return 'n_' . bin2hex(random_bytes(5));
}

/** A fresh empty tree: a single body container as root. */
function outpost_node_default_tree(): array {
    $root = outpost_node_id();
    return [
        'root' => $root,
        'nodes' => [
            $root => [
                'id' => $root,
                'type' => 'container',
                'tag' => 'main',
                'props' => new \stdClass(),
                'classes' => [],
                'styles' => new \stdClass(),
                'children' => [],
            ],
        ],
    ];
}

/** Sanitise a class token to a safe CSS class name. */
function outpost_node_sanitise_class(string $c): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $c);
}

/** Sanitise a URL prop: allow http(s)/mailto/tel/relative; block scripts. */
function outpost_node_sanitise_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $probe = preg_replace('/[\x00-\x20]+/', '', $url);
    if ($probe !== null && preg_match('/^(javascript|data|vbscript):/i', $probe)) return '';
    return $url;
}

/**
 * Validate + sanitise a tree. Returns a clean tree (unknown fields dropped,
 * tags clamped to the type whitelist, dangling child refs removed, cycles
 * broken). Throws on a structurally unusable tree.
 */
function outpost_node_validate(array $tree): array {
    if (!isset($tree['root']) || !is_string($tree['root'])) {
        throw new \InvalidArgumentException('tree.root missing');
    }
    if (!isset($tree['nodes']) || !is_array($tree['nodes'])) {
        throw new \InvalidArgumentException('tree.nodes missing');
    }
    $types = outpost_node_types();
    $clean = [];

    foreach ($tree['nodes'] as $id => $node) {
        if (!is_string($id) || !is_array($node)) continue;
        $type = $node['type'] ?? '';
        if (!isset($types[$type])) continue;

        // Tag must be in the type's whitelist, else fall back to the default.
        $allowedTags = $types[$type]['tags'];
        $tag = $node['tag'] ?? '';
        if (!in_array($tag, $allowedTags, true)) $tag = $allowedTags[0];

        // Classes -> sanitised, de-duped, non-empty.
        $classes = [];
        foreach ((array) ($node['classes'] ?? []) as $c) {
            if (!is_string($c)) continue;
            $c = outpost_node_sanitise_class($c);
            if ($c !== '' && !in_array($c, $classes, true)) $classes[] = $c;
        }

        $clean[$id] = [
            'id' => $id,
            'type' => $type,
            'tag' => $tag,
            'props' => is_array($node['props'] ?? null) ? $node['props'] : [],
            'classes' => $classes,
            'styles' => is_array($node['styles'] ?? null) ? $node['styles'] : [],
            'children' => $types[$type]['children'] && is_array($node['children'] ?? null)
                ? array_values(array_filter($node['children'], 'is_string'))
                : [],
        ];
    }

    if (!isset($clean[$tree['root']])) {
        throw new \InvalidArgumentException('tree.root not found in nodes');
    }

    // Drop child refs that point at missing nodes.
    foreach ($clean as $id => &$node) {
        $node['children'] = array_values(array_filter(
            $node['children'],
            fn($cid) => isset($clean[$cid])
        ));
    }
    unset($node);

    // Break cycles + drop orphans by walking from root once (each node visited
    // at most once; a child already seen is removed from the parent).
    $seen = [];
    $walk = function (string $id) use (&$walk, &$clean, &$seen) {
        if (isset($seen[$id])) return false; // already attached elsewhere -> cycle/dup
        $seen[$id] = true;
        $kept = [];
        foreach ($clean[$id]['children'] as $cid) {
            if (isset($clean[$cid]) && $walk($cid)) $kept[] = $cid;
        }
        $clean[$id]['children'] = $kept;
        return true;
    };
    $walk($tree['root']);

    // Keep only reachable nodes.
    $nodes = [];
    foreach ($seen as $id => $_) $nodes[$id] = $clean[$id];

    return ['root' => $tree['root'], 'nodes' => $nodes];
}

/**
 * Load a node tree for an owner (page/component/template). Returns the saved
 * tree + version, or — for a page with a template file but no saved tree yet —
 * the template parsed into a tree, so every page is editable. Shared by the
 * in-app builder API and the builder MCP so both read the identical tree.
 */
function outpost_load_node_tree(string $type, int $ownerId): array {
    $row = OutpostDB::fetchOne(
        'SELECT tree, version FROM node_trees WHERE owner_type = ? AND owner_id = ?',
        [$type, $ownerId]
    );
    if ($row) {
        $tree = json_decode($row['tree'] ?: '{}', true);
        if (!is_array($tree) || !isset($tree['root'])) $tree = outpost_node_default_tree();
        return ['tree' => $tree, 'version' => (int) $row['version'], 'exists' => true, 'fromTemplate' => false];
    }

    $tree = null;
    $fromTemplate = false;
    if ($type === 'page') {
        if (!function_exists('outpost_active_render_root')) require_once __DIR__ . '/blocks.php';
        $page = OutpostDB::fetchOne('SELECT path FROM pages WHERE id = ?', [$ownerId]);
        if ($page) {
            $base = ($page['path'] === '/') ? 'index' : trim($page['path'], '/');
            $file = rtrim(outpost_active_render_root(), '/') . '/' . $base . '.html';
            if ($base !== '' && !str_contains($base, '/') && file_exists($file)) {
                $html = file_get_contents($file);
                if ($html !== false && trim($html) !== '') {
                    try {
                        $tree = outpost_html_to_node_tree($html);
                        $fromTemplate = true;
                    } catch (\Throwable) {
                        $tree = null;
                    }
                }
            }
        }
    }
    return ['tree' => $tree ?: outpost_node_default_tree(), 'version' => 0, 'exists' => false, 'fromTemplate' => $fromTemplate];
}

/**
 * Persist a validated node tree for an owner with optimistic locking, then bake
 * the page to static HTML. Shared by the in-app builder API and the MCP so both
 * write and bake identically. Returns ['conflict','version','baked'].
 */
function outpost_save_node_tree(string $type, int $ownerId, array $cleanTree, ?int $expectVersion = null): array {
    $existing = OutpostDB::fetchOne(
        'SELECT version FROM node_trees WHERE owner_type = ? AND owner_id = ?',
        [$type, $ownerId]
    );
    if ($existing && $expectVersion !== null && $expectVersion !== (int) $existing['version']) {
        return ['conflict' => true, 'version' => (int) $existing['version'], 'baked' => false];
    }

    $treeJson = json_encode($cleanTree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = date('Y-m-d H:i:s');

    if ($existing) {
        $newVersion = (int) $existing['version'] + 1;
        OutpostDB::update('node_trees',
            ['tree' => $treeJson, 'version' => $newVersion, 'updated_at' => $now],
            'owner_type = ? AND owner_id = ?', [$type, $ownerId]
        );
    } else {
        $newVersion = 1;
        OutpostDB::insert('node_trees', [
            'owner_type' => $type,
            'owner_id'   => $ownerId,
            'tree'       => $treeJson,
            'version'    => $newVersion,
        ]);
    }

    $baked = false;
    if ($type === 'page') {
        if (!function_exists('outpost_active_render_root')) require_once __DIR__ . '/blocks.php';
        $pg = OutpostDB::fetchOne('SELECT path FROM pages WHERE id = ?', [$ownerId]);
        $path = $pg['path'] ?? '';
        $base = ($path === '/') ? 'index' : trim($path, '/');
        $templateFile = rtrim(outpost_active_render_root(), '/') . '/' . $base . '.html';
        $treeExisted = (bool) $existing;
        $hasContent = count($cleanTree['nodes']) > 1;
        if ($base !== '' && !str_contains($base, '/') && ($treeExisted || !file_exists($templateFile) || $hasContent)) {
            $baked = outpost_bake_node_page($ownerId);
            if ($pg && function_exists('outpost_clear_cache')) outpost_clear_cache($path);
        }
    }
    return ['conflict' => false, 'version' => $newVersion, 'baked' => $baked];
}

function outpost_node_parent_of(array $tree, string $id): ?string {
    foreach ($tree['nodes'] as $nid => $node) {
        if (in_array($id, (array) ($node['children'] ?? []), true)) return $nid;
    }
    return null;
}

function outpost_node_is_ancestor(array $tree, string $maybeAncestor, string $id): bool {
    $cur = $id;
    $guard = 0;
    while ($cur !== null && $guard++ < 1000) {
        if ($cur === $maybeAncestor) return true;
        $cur = outpost_node_parent_of($tree, $cur);
    }
    return false;
}

function outpost_node_delete(array $tree, string $id): array {
    if ($id === $tree['root']) return $tree;
    $parent = outpost_node_parent_of($tree, $id);
    if ($parent !== null) {
        $tree['nodes'][$parent]['children'] = array_values(array_filter(
            $tree['nodes'][$parent]['children'],
            fn($c) => $c !== $id
        ));
    }
    $stack = [$id];
    while ($stack) {
        $nid = array_pop($stack);
        if (!isset($tree['nodes'][$nid])) continue;
        foreach ((array) ($tree['nodes'][$nid]['children'] ?? []) as $c) $stack[] = $c;
        unset($tree['nodes'][$nid]);
    }
    return $tree;
}

function outpost_node_duplicate(array $tree, string $id): array {
    $parent = outpost_node_parent_of($tree, $id);
    if ($parent === null) return ['tree' => $tree, 'id' => null];

    $cloneSubtree = function (string $srcId) use (&$cloneSubtree, &$tree): string {
        $src = $tree['nodes'][$srcId];
        $copy = $src;
        $copy['id'] = outpost_node_id();
        $copy['children'] = [];
        foreach ((array) ($src['children'] ?? []) as $cid) {
            $copy['children'][] = $cloneSubtree($cid);
        }
        $tree['nodes'][$copy['id']] = $copy;
        return $copy['id'];
    };

    $newId = $cloneSubtree($id);
    $children = $tree['nodes'][$parent]['children'];
    $idx = array_search($id, $children, true);
    array_splice($children, $idx === false ? count($children) : $idx + 1, 0, [$newId]);
    $tree['nodes'][$parent]['children'] = $children;
    return ['tree' => $tree, 'id' => $newId];
}

function outpost_node_ai_props(array $spec): array {
    $p = [];
    foreach (['text', 'alt', 'fieldType', 'fieldScope'] as $k) {
        if (isset($spec[$k]) && is_scalar($spec[$k])) $p[$k] = (string) $spec[$k];
    }
    foreach (['href', 'src'] as $k) {
        if (isset($spec[$k]) && is_scalar($spec[$k])) $p[$k] = outpost_node_sanitise_url((string) $spec[$k]);
    }
    if (isset($spec['field']) && is_scalar($spec['field'])) {
        $f = preg_replace('/[^A-Za-z0-9_]/', '', (string) $spec['field']);
        if ($f !== '') $p['field'] = $f;
    }
    return $p;
}

function outpost_node_ai_classes(mixed $list): array {
    $out = [];
    foreach ((array) $list as $c) {
        if (!is_string($c)) continue;
        $c = outpost_node_sanitise_class($c);
        if ($c !== '' && outpost_class_name_valid($c) && !in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
}

/**
 * Apply a batch of build operations (the shared apply_ops vocabulary) to a tree.
 * The PHP twin of the JS store's applyAiOps — same op set, same guards — so the
 * builder MCP and the in-app sidebar drive identical edits. Mutates $classes
 * (name => declarations) for define_class and returns the validated tree.
 */
function outpost_apply_node_ops(array $tree, array $ops, array &$classes, array &$summary): array {
    $types = outpost_node_types();
    $refMap = [];
    $builtCount = 0;
    $maxDepth = 32;
    $maxNodes = 2000;

    $resolve = function ($ref) use (&$tree, &$refMap): ?string {
        if (!is_string($ref) || $ref === '') return null;
        if ($ref === 'root') return $tree['root'];
        if (isset($refMap[$ref]) && isset($tree['nodes'][$refMap[$ref]])) return $refMap[$ref];
        return isset($tree['nodes'][$ref]) ? $ref : null;
    };

    $build = function (array $spec, int $depth) use (&$build, &$tree, &$refMap, &$classes, &$builtCount, &$summary, $types, $maxDepth, $maxNodes): string {
        if ($depth > $maxDepth) throw new \RuntimeException('node spec too deeply nested');
        if (++$builtCount > $maxNodes) throw new \RuntimeException('node spec too large');
        $type = (isset($spec['type']) && isset($types[$spec['type']])) ? $spec['type'] : 'container';
        $tags = $types[$type]['tags'];
        $tag = (isset($spec['tag']) && in_array($spec['tag'], $tags, true)) ? $spec['tag'] : $tags[0];
        $id = outpost_node_id();
        $node = [
            'id' => $id,
            'type' => $type,
            'tag' => $tag,
            'props' => outpost_node_ai_props($spec),
            'classes' => outpost_node_ai_classes($spec['classes'] ?? []),
            'styles' => [],
            'children' => [],
        ];
        if (isset($spec['ref']) && is_string($spec['ref']) && $spec['ref'] !== '') $refMap[$spec['ref']] = $id;
        if ($types[$type]['children'] && isset($spec['children']) && is_array($spec['children'])) {
            foreach ($spec['children'] as $child) {
                if (is_array($child)) $node['children'][] = $build($child, $depth + 1);
            }
        }
        foreach ($node['classes'] as $c) if (!isset($classes[$c])) $classes[$c] = [];
        $tree['nodes'][$id] = $node;
        $summary['inserted']++;
        return $id;
    };

    foreach ($ops as $op) {
        if (!is_array($op)) continue;
        $kind = $op['op'] ?? $op['action'] ?? '';
        try {
            if ($kind === 'insert_tree' || $kind === 'insert') {
                $parentId = $resolve($op['parent'] ?? $op['parentId'] ?? 'root') ?? $tree['root'];
                $parent = $tree['nodes'][$parentId] ?? null;
                if (!$parent || !$types[$parent['type']]['children']) { $summary['errors'][] = 'insert: invalid parent'; continue; }
                $spec = isset($op['node']) && is_array($op['node']) ? $op['node'] : $op;
                $rootId = $build($spec, 0);
                $children = $tree['nodes'][$parentId]['children'];
                $max = count($children);
                $at = (isset($op['index']) && is_int($op['index'])) ? max(0, min($op['index'], $max)) : $max;
                array_splice($children, $at, 0, [$rootId]);
                $tree['nodes'][$parentId]['children'] = $children;
            } elseif ($kind === 'update') {
                $id = $resolve($op['id'] ?? null);
                if (!$id) { $summary['errors'][] = 'update: unknown node'; continue; }
                if (isset($op['tag']) && in_array($op['tag'], $types[$tree['nodes'][$id]['type']]['tags'], true)) {
                    $tree['nodes'][$id]['tag'] = $op['tag'];
                }
                $patch = (isset($op['props']) && is_array($op['props'])) ? outpost_node_ai_props($op['props']) : outpost_node_ai_props($op);
                $tree['nodes'][$id]['props'] = array_merge((array) $tree['nodes'][$id]['props'], $patch);
                $summary['updated']++;
            } elseif ($kind === 'set_classes') {
                $id = $resolve($op['id'] ?? null);
                if (!$id) { $summary['errors'][] = 'set_classes: unknown node'; continue; }
                $list = outpost_node_ai_classes($op['classes'] ?? []);
                $tree['nodes'][$id]['classes'] = $list;
                foreach ($list as $c) if (!isset($classes[$c])) $classes[$c] = [];
                $summary['updated']++;
            } elseif ($kind === 'add_class') {
                $id = $resolve($op['id'] ?? null);
                $name = outpost_node_sanitise_class((string) ($op['class'] ?? $op['name'] ?? ''));
                if (!$id || $name === '' || !outpost_class_name_valid($name)) { $summary['errors'][] = 'add_class: invalid'; continue; }
                if (!in_array($name, $tree['nodes'][$id]['classes'], true)) $tree['nodes'][$id]['classes'][] = $name;
                if (!isset($classes[$name])) $classes[$name] = [];
                $summary['updated']++;
            } elseif ($kind === 'remove_class') {
                $id = $resolve($op['id'] ?? null);
                $name = outpost_node_sanitise_class((string) ($op['class'] ?? $op['name'] ?? ''));
                if (!$id || $name === '') continue;
                $tree['nodes'][$id]['classes'] = array_values(array_filter($tree['nodes'][$id]['classes'], fn($c) => $c !== $name));
                $summary['updated']++;
            } elseif ($kind === 'move') {
                $id = $resolve($op['id'] ?? null);
                $parentId = $resolve($op['parent'] ?? $op['parentId'] ?? null);
                if (!$id || !$parentId || $id === $tree['root']) { $summary['errors'][] = 'move: invalid'; continue; }
                if (outpost_node_is_ancestor($tree, $id, $parentId)) { $summary['errors'][] = 'move: would create cycle'; continue; }
                if (!$types[$tree['nodes'][$parentId]['type']]['children']) { $summary['errors'][] = 'move: parent cannot hold children'; continue; }
                $old = outpost_node_parent_of($tree, $id);
                if ($old !== null) {
                    $tree['nodes'][$old]['children'] = array_values(array_filter($tree['nodes'][$old]['children'], fn($c) => $c !== $id));
                }
                $children = $tree['nodes'][$parentId]['children'];
                $max = count($children);
                $at = (isset($op['index']) && is_int($op['index'])) ? max(0, min($op['index'], $max)) : $max;
                array_splice($children, $at, 0, [$id]);
                $tree['nodes'][$parentId]['children'] = $children;
                $summary['moved']++;
            } elseif ($kind === 'duplicate') {
                $id = $resolve($op['id'] ?? null);
                if (!$id || $id === $tree['root']) { $summary['errors'][] = 'duplicate: invalid'; continue; }
                if (count($tree['nodes']) >= $maxNodes) { $summary['errors'][] = 'duplicate: node limit reached'; continue; }
                $r = outpost_node_duplicate($tree, $id);
                $tree = $r['tree'];
                if ($r['id'] && isset($op['ref']) && is_string($op['ref'])) $refMap[$op['ref']] = $r['id'];
                $summary['inserted']++;
            } elseif ($kind === 'remove' || $kind === 'delete') {
                $id = $resolve($op['id'] ?? null);
                if (!$id || $id === $tree['root']) { $summary['errors'][] = 'remove: invalid'; continue; }
                $tree = outpost_node_delete($tree, $id);
                $summary['removed']++;
            } elseif ($kind === 'define_class') {
                $name = outpost_node_sanitise_class((string) ($op['name'] ?? ''));
                if ($name === '' || !outpost_class_name_valid($name)) { $summary['errors'][] = 'define_class: invalid name'; continue; }
                $decls = (isset($op['declarations']) && is_array($op['declarations'])) ? outpost_sanitise_class_decls($op['declarations']) : [];
                $classes[$name] = array_merge($classes[$name] ?? [], $decls);
                $summary['classes']++;
            } elseif ($kind === 'bind_field') {
                $id = $resolve($op['id'] ?? null);
                if (!$id) { $summary['errors'][] = 'bind_field: unknown node'; continue; }
                $props = (array) $tree['nodes'][$id]['props'];
                $field = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($op['field'] ?? ''));
                $props['field'] = $field;
                if (isset($op['fieldType']) && is_scalar($op['fieldType'])) $props['fieldType'] = (string) $op['fieldType'];
                if (isset($op['fieldScope']) && is_scalar($op['fieldScope'])) $props['fieldScope'] = (string) $op['fieldScope'];
                $tree['nodes'][$id]['props'] = $props;
                $summary['fields']++;
            } elseif ($kind === 'select') {
                continue;
            } else {
                $summary['errors'][] = "unknown operation: $kind";
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = "$kind: " . $e->getMessage();
        }
    }

    return outpost_node_validate($tree);
}

function outpost_html_map_tag(string $tag): array {
    static $containers = ['div', 'section', 'main', 'header', 'footer', 'article', 'aside', 'nav', 'ul', 'ol', 'li', 'figure'];
    static $texts = ['p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'blockquote', 'label'];
    if (in_array($tag, $containers, true)) return ['container', $tag];
    if (in_array($tag, $texts, true)) return ['text', $tag];
    if ($tag === 'img') return ['image', 'img'];
    if ($tag === 'a') return ['link', 'a'];
    if ($tag === 'button') return ['button', 'button'];
    return [null, null];
}

function outpost_dom_to_node(\DOMNode $el, array &$nodes, int $depth = 0): ?string {
    if ($el->nodeType !== XML_ELEMENT_NODE) return null;
    if ($depth > 100 || count($nodes) > 5000) return null;
    $tag = strtolower($el->nodeName);
    if (in_array($tag, ['script', 'style', 'link', 'meta', 'head', 'title', 'br', 'hr'], true)) return null;

    [$type, $nodeTag] = outpost_html_map_tag($tag);
    if ($type === null) { $type = 'container'; $nodeTag = 'div'; }

    $classes = [];
    if ($el instanceof \DOMElement && $el->hasAttribute('class')) {
        foreach (preg_split('/\s+/', trim($el->getAttribute('class'))) as $c) {
            $c = preg_replace('/[^A-Za-z0-9_-]/', '', $c);
            if ($c !== '' && !in_array($c, $classes, true)) $classes[] = $c;
        }
    }

    $props = [];
    $children = [];
    if ($el instanceof \DOMElement && $el->hasAttribute('data-outpost')) {
        $fieldName = preg_replace('/[^A-Za-z0-9_]/', '', $el->getAttribute('data-outpost'));
        if ($fieldName !== '') {
            $props['field'] = $fieldName;
            if ($el->hasAttribute('data-type')) {
                $props['fieldType'] = preg_replace('/[^a-z]/', '', strtolower($el->getAttribute('data-type')));
            }
            if ($el->getAttribute('data-scope') === 'global') {
                $props['fieldScope'] = 'global';
            }
        }
    }
    if ($type === 'text') {
        $props['text'] = trim($el->textContent);
    } elseif ($type === 'image') {
        $props['src'] = $el instanceof \DOMElement ? $el->getAttribute('src') : '';
        $props['alt'] = $el instanceof \DOMElement ? $el->getAttribute('alt') : '';
    } elseif ($type === 'link' || $type === 'button') {
        $props['text'] = trim($el->textContent);
        if ($el instanceof \DOMElement && $el->hasAttribute('href')) $props['href'] = $el->getAttribute('href');
    } else {
        foreach ($el->childNodes as $child) {
            $cid = outpost_dom_to_node($child, $nodes, $depth + 1);
            if ($cid) $children[] = $cid;
        }
    }

    $id = outpost_node_id();
    $nodes[$id] = [
        'id' => $id,
        'type' => $type,
        'tag' => $nodeTag,
        'props' => $props ?: new \stdClass(),
        'classes' => $classes,
        'styles' => new \stdClass(),
        'children' => $children,
    ];
    return $id;
}

function outpost_html_to_node_tree(string $html): array {
    $html = trim($html);
    if ($html === '') return outpost_node_default_tree();

    $prev = libxml_use_internal_errors(true);
    $doc = new \DOMDocument();
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="__oc_root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $rootEl = $doc->getElementById('__oc_root');
    $rootId = outpost_node_id();
    $nodes = [
        $rootId => [
            'id' => $rootId, 'type' => 'container', 'tag' => 'main',
            'props' => new \stdClass(), 'classes' => [], 'styles' => new \stdClass(), 'children' => [],
        ],
    ];
    if ($rootEl) {
        foreach ($rootEl->childNodes as $child) {
            $cid = outpost_dom_to_node($child, $nodes);
            if ($cid) $nodes[$rootId]['children'][] = $cid;
        }
    }

    // Avoid a redundant wrapper: if the HTML has a single root container, use it
    // as the tree root instead of nesting it inside the synthetic <main>.
    $topChildren = $nodes[$rootId]['children'];
    if (count($topChildren) === 1) {
        $onlyId = $topChildren[0];
        if (($nodes[$onlyId]['type'] ?? '') === 'container') {
            unset($nodes[$rootId]);
            return outpost_node_validate(['root' => $onlyId, 'nodes' => $nodes]);
        }
    }

    return outpost_node_validate(['root' => $rootId, 'nodes' => $nodes]);
}

function outpost_class_name_valid(string $name): bool {
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name);
}

function outpost_css_property_valid(string $prop): bool {
    if (in_array($prop, ['behavior', '-moz-binding'], true)) return false;
    return (bool) preg_match('/^[a-z][a-z-]*$/', $prop);
}

function outpost_css_value_safe(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/javascript:|expression\s*\(|<\/|[{}<>]|@import/i', $value)) return '';
    return $value;
}

function outpost_sanitise_declarations(array $declarations): array {
    $clean = [];
    foreach ($declarations as $prop => $value) {
        if (!is_string($prop) || !outpost_css_property_valid($prop)) continue;
        if (!is_scalar($value)) continue;
        $safe = outpost_css_value_safe((string) $value);
        if ($safe !== '') $clean[$prop] = $safe;
    }
    return $clean;
}

/** A nested-rule key is a valid &-selector or a safe @media/@container/@supports at-rule. */
function outpost_css_nested_key_valid(string $key): bool {
    if ($key === '' || strlen($key) > 200) return false;
    if (str_starts_with($key, '@')) {
        return (bool) preg_match('/^@(media|container|supports)\b[^{}<>;]*$/i', $key);
    }
    if (preg_match('/[{}<;@]/', $key)) return false;
    if (!preg_match('/^[A-Za-z0-9 &.:>+~\[\]="\'(),_-]+$/', $key)) return false;
    $inQuote = '';
    $paren = 0;
    $bracket = 0;
    for ($i = 0, $len = strlen($key); $i < $len; $i++) {
        $ch = $key[$i];
        if ($inQuote !== '') {
            if ($ch === $inQuote) $inQuote = '';
            continue;
        }
        switch ($ch) {
            case '"':
            case "'": $inQuote = $ch; break;
            case '(': $paren++; break;
            case ')': if (--$paren < 0) return false; break;
            case '[': $bracket++; break;
            case ']': if (--$bracket < 0) return false; break;
            case ',': if ($paren === 0 && $bracket === 0) return false; break;
        }
    }
    return $inQuote === '' && $paren === 0 && $bracket === 0;
}

/**
 * Recursively sanitise a class declaration map that may contain nested rules:
 * scalar values keyed by CSS property, or nested objects keyed by an &-selector
 * or @media/@container/@supports at-rule. Mirrors the JS css-nest model.
 */
function outpost_sanitise_class_decls(array $decls, int $depth = 0): array {
    if ($depth > 20) return [];
    $clean = [];
    foreach ($decls as $key => $value) {
        if (!is_string($key)) continue;
        if (is_array($value)) {
            if (!outpost_css_nested_key_valid($key)) continue;
            $sub = outpost_sanitise_class_decls($value, $depth + 1);
            if ($sub) $clean[$key] = $sub;
        } elseif (is_scalar($value)) {
            if (!outpost_css_property_valid($key)) continue;
            $safe = outpost_css_value_safe((string) $value);
            if ($safe !== '') $clean[$key] = $safe;
        }
    }
    return $clean;
}

/** Emit a CSS rule (with nesting expanded) for a selector and a decl map. */
function outpost_emit_rule(string $selector, array $decls, int $depth = 0): string {
    if ($depth > 20) return '';
    $body = '';
    foreach ($decls as $key => $value) {
        if (!is_string($value) || !outpost_css_property_valid($key)) continue;
        $safe = outpost_css_value_safe($value);
        if ($safe !== '') $body .= "{$key}:{$safe};";
    }
    $out = $body !== '' ? "{$selector}{{$body}}\n" : '';
    foreach ($decls as $key => $value) {
        if (!is_array($value) || !outpost_css_nested_key_valid($key)) continue;
        if (str_starts_with($key, '@')) {
            $inner = outpost_emit_rule($selector, $value, $depth + 1);
            if ($inner !== '') $out .= "{$key}{{$inner}}\n";
        } else {
            $child = str_contains($key, '&') ? str_replace('&', $selector, $key) : "{$selector} {$key}";
            $out .= outpost_emit_rule($child, $value, $depth + 1);
        }
    }
    return $out;
}

function outpost_classes_to_css(array $classes, string $scope = ''): string {
    $prefix = $scope !== '' ? rtrim($scope) . ' ' : '';
    $out = '';
    foreach ($classes as $name => $declarations) {
        if (!is_string($name) || !outpost_class_name_valid($name) || !is_array($declarations)) continue;
        $out .= outpost_emit_rule($prefix . '.' . $name, $declarations);
    }
    return $out;
}

/** Render a single attribute string from a class list. */
function outpost_node_class_attr(array $classes): string {
    if (!$classes) return '';
    return ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES) . '"';
}

/**
 * Render a validated tree to semantic HTML. Pass the tree and optionally a
 * starting node id (defaults to root). Pure string output, every value escaped.
 */
function outpost_render_node_tree(array $tree, ?string $startId = null, array $components = []): string {
    $tree = outpost_node_validate($tree);
    $start = $startId ?? $tree['root'];
    if (!isset($tree['nodes'][$start])) return '';
    return outpost_render_node($tree, $start, $components, []);
}

function outpost_node_field_attr(array $props): string {
    if (empty($props['field'])) return '';
    $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $props['field']);
    if ($name === '') return '';
    $attr = ' data-outpost="' . htmlspecialchars($name, ENT_QUOTES) . '"';
    if (!empty($props['fieldType'])) {
        $t = preg_replace('/[^a-z]/', '', strtolower((string) $props['fieldType']));
        if ($t !== '') $attr .= ' data-type="' . $t . '"';
    }
    if (($props['fieldScope'] ?? '') === 'global') {
        $attr .= ' data-scope="global"';
    }
    return $attr;
}

function outpost_render_node(array $tree, string $id, array $components, array $stack): string {
    $node = $tree['nodes'][$id];
    $tag = $node['tag'];
    $cls = outpost_node_class_attr($node['classes']);
    $props = (array) $node['props'];
    $sid = (preg_match('/^n_[a-z0-9]+$/', $id) && is_array($node['styles'] ?? null) && $node['styles'])
        ? ' data-node-id="' . $id . '"' : '';
    $fld = outpost_node_field_attr($props) . $sid;

    switch ($node['type']) {
        case 'component-ref':
            $cid = (string) ($props['componentId'] ?? '');
            if ($cid === '' || in_array($cid, $stack, true) || !isset($components[$cid]['tree'])) {
                return "<div{$cls}{$sid}></div>";
            }
            $ctree = outpost_node_validate($components[$cid]['tree']);
            $inner = isset($ctree['nodes'][$ctree['root']])
                ? outpost_render_node($ctree, $ctree['root'], $components, array_merge($stack, [$cid]))
                : '';
            return "<div{$cls}{$sid}>{$inner}</div>";

        case 'image':
            $src = htmlspecialchars(outpost_node_sanitise_url((string) ($props['src'] ?? '')), ENT_QUOTES);
            $alt = htmlspecialchars((string) ($props['alt'] ?? ''), ENT_QUOTES);
            return "<img{$cls}{$fld} src=\"{$src}\" alt=\"{$alt}\">";

        case 'text':
            $text = htmlspecialchars((string) ($props['text'] ?? ''), ENT_QUOTES);
            return "<{$tag}{$cls}{$fld}>{$text}</{$tag}>";

        case 'link':
        case 'button':
            $text = htmlspecialchars((string) ($props['text'] ?? ''), ENT_QUOTES);
            $href = outpost_node_sanitise_url((string) ($props['href'] ?? ''));
            if ($tag === 'a') {
                $h = htmlspecialchars($href !== '' ? $href : '#', ENT_QUOTES);
                return "<a{$cls}{$fld} href=\"{$h}\">{$text}</a>";
            }
            return "<button{$cls}{$fld} type=\"button\">{$text}</button>";

        case 'embed':
            if (!function_exists('embed_src_safe')) require_once __DIR__ . '/embeds.php';
            $embedUrl = (string) ($props['embedUrl'] ?? '');
            if ($embedUrl === '' || !embed_src_safe($embedUrl)) {
                return "<div{$cls}{$fld}></div>";
            }
            $u = htmlspecialchars($embedUrl, ENT_QUOTES);
            $title = htmlspecialchars((string) ($props['title'] ?? ''), ENT_QUOTES);
            $w = (int) ($props['width'] ?? 0) ?: 16;
            $h = (int) ($props['height'] ?? 0) ?: 9;
            if (($props['kind'] ?? '') === 'photo') {
                return "<div{$cls}{$fld}><span class=\"oc-embed oc-embed--photo\"><img src=\"{$u}\" alt=\"{$title}\" width=\"{$w}\" height=\"{$h}\" loading=\"lazy\"></span></div>";
            }
            return "<div{$cls}{$fld}><span class=\"oc-embed\">"
                . "<iframe src=\"{$u}\" title=\"{$title}\" width=\"{$w}\" height=\"{$h}\" loading=\"lazy\" "
                . "allow=\"autoplay; encrypted-media; picture-in-picture; fullscreen\" allowfullscreen "
                . "referrerpolicy=\"strict-origin-when-cross-origin\"></iframe></span></div>";

        case 'container':
        default:
            $inner = '';
            foreach ($node['children'] as $cid2) {
                $inner .= outpost_render_node($tree, $cid2, $components, $stack);
            }
            return "<{$tag}{$cls}{$fld}>{$inner}</{$tag}>";
    }
}

function outpost_tree_class_names(array $tree): array {
    $used = [];
    foreach (($tree['nodes'] ?? []) as $node) {
        foreach (($node['classes'] ?? []) as $c) $used[$c] = true;
    }
    return $used;
}

function outpost_public_class_css(?array $only = null): string {
    $rows = OutpostDB::fetchAll('SELECT name, declarations FROM style_classes ORDER BY name ASC');
    $css = '';
    foreach ($rows as $r) {
        if ($only !== null && !isset($only[$r['name']])) continue;
        if (!outpost_class_name_valid($r['name'])) continue;
        $decls = json_decode($r['declarations'] ?: '{}', true) ?: [];
        if (!is_array($decls)) continue;
        $css .= outpost_emit_rule('.' . $r['name'], $decls);
    }
    return $css;
}

function outpost_embed_base_css(): string {
    return '.oc-embed{display:block;max-width:100%}.oc-embed iframe,.oc-embed img{display:block;width:100%;height:auto;border:0}';
}

function outpost_tree_has_embed(array $tree): bool {
    foreach (($tree['nodes'] ?? []) as $node) {
        if (($node['type'] ?? '') === 'embed') return true;
    }
    return false;
}

function outpost_node_styles_css(array $tree): string {
    $css = '';
    foreach (($tree['nodes'] ?? []) as $id => $node) {
        if (!is_string($id) || !preg_match('/^n_[a-z0-9]+$/', $id)) continue;
        $styles = $node['styles'] ?? null;
        if (!is_array($styles) || !$styles) continue;
        $css .= outpost_emit_rule('[data-node-id="' . $id . '"]', $styles);
    }
    return $css;
}

/**
 * Sanitise a raw CSS blob (global stylesheet / :root variables) for safe
 * injection into a <style> element. Strips anything that could break out of
 * the style context or load external resources, while allowing arbitrary
 * selectors, at-rules, and custom-property declarations.
 */
function outpost_sanitise_raw_css(string $css): string {
    if ($css === '') return '';
    $css = mb_substr($css, 0, 100000);
    $css = str_replace('<', '', $css);
    $css = preg_replace('#@import\b[^;]*;?#i', '', $css);
    $css = preg_replace('#@charset\b[^;]*;?#i', '', $css);
    $css = preg_replace('#expression\s*\(#i', '', $css);
    $css = preg_replace('#(javascript|vbscript)\s*:#i', '', $css);
    return $css;
}

/** Parse the stored @custom-media definitions into a name => condition map. */
function outpost_custom_media_map(): array {
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'builder_custom_media'");
    $text = $row['value'] ?? '';
    $map = [];
    if ($text && preg_match_all('/@custom-media\s+--([A-Za-z0-9_-]+)\s+([^;{}]+);/i', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $cond = trim($m[2]);
            if (str_starts_with($cond, '(') && str_ends_with($cond, ')')) {
                $cond = trim(substr($cond, 1, -1));
            }
            if ($cond !== '' && strlen($cond) <= 200 && preg_match('/^[A-Za-z0-9 ():>=,.\/-]+$/', $cond)) {
                $map[$m[1]] = $cond;
            }
        }
    }
    return $map;
}

/** Expand @media (--name) / @container (--name) references using a custom-media map. */
function outpost_expand_custom_media(string $css, array $map): string {
    if (!$map) return $css;
    return preg_replace_callback('/@(media|container)([^{]*)\{/i', function ($m) use ($map) {
        $prelude = preg_replace_callback('/\(\s*--([A-Za-z0-9_-]+)\s*\)/', function ($mm) use ($map) {
            return isset($map[$mm[1]]) ? '(' . $map[$mm[1]] . ')' : $mm[0];
        }, $m[2]);
        return '@' . $m[1] . $prelude . '{';
    }, $css);
}

/** Concatenated global CSS: variable collections + extra stylesheets (sanitised). */
function outpost_global_style_css(): string {
    $css = '';
    $vc = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'builder_variable_collections'");
    foreach (json_decode($vc['value'] ?? '[]', true) ?: [] as $col) {
        if (is_array($col) && !empty($col['css'])) $css .= outpost_sanitise_raw_css((string) $col['css']) . "\n";
    }
    $ss = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'builder_stylesheets'");
    foreach (json_decode($ss['value'] ?? '[]', true) ?: [] as $sheet) {
        if (is_array($sheet) && !empty($sheet['css'])) $css .= outpost_sanitise_raw_css((string) $sheet['css']) . "\n";
    }
    return $css;
}

function outpost_bake_node_page(int $pageId): bool {
    $page = OutpostDB::fetchOne('SELECT path, title FROM pages WHERE id = ?', [$pageId]);
    if (!$page) return false;

    $row = OutpostDB::fetchOne(
        "SELECT tree FROM node_trees WHERE owner_type = 'page' AND owner_id = ?",
        [$pageId]
    );
    if (!$row) return false;
    $tree = json_decode($row['tree'] ?: '{}', true);
    if (!is_array($tree) || empty($tree['root'])) return false;

    $components = [];
    foreach (OutpostDB::fetchAll('SELECT id, tree FROM components') as $c) {
        $components[$c['id']] = ['tree' => json_decode($c['tree'] ?: '{}', true)];
    }

    try {
        $body = outpost_render_node_tree($tree, null, $components);
    } catch (\Throwable $e) {
        return false;
    }

    if (!function_exists('outpost_active_render_root')) require_once __DIR__ . '/blocks.php';
    $renderRoot = rtrim(outpost_active_render_root(), '/');

    $base = ($page['path'] === '/') ? 'index' : trim($page['path'], '/');
    if ($base === '' || str_contains($base, '/')) return false;

    $filename = ($page['path'] === '/') ? 'index.html' : $base . '.html';
    $existingFile = $renderRoot . '/' . $filename;

    $preservedLinks = '';
    if (file_exists($existingFile)) {
        $existingHtml = @file_get_contents($existingFile);
        if ($existingHtml !== false) {
            $sheets = outpost_page_stylesheets($existingHtml);
            foreach ($sheets['links'] as $tag) $preservedLinks .= $tag . "\n";
            if (preg_match_all('#<link\b[^>]*rel=["\']?(?:preconnect|preload)["\']?[^>]*>#i', $existingHtml, $pm)) {
                foreach ($pm[0] as $tag) $preservedLinks .= $tag . "\n";
            }
        }
    }

    $pageCssLink = (file_exists($renderRoot . '/' . $base . '.css') && !str_contains($preservedLinks, $base . '.css'))
        ? '<link rel="stylesheet" href="/' . $base . '.css">' . "\n" : '';
    $jsLink = file_exists($renderRoot . '/' . $base . '.js')
        ? '<script src="/' . $base . '.js" defer></script>' . "\n" : '';
    $classCss = outpost_public_class_css(outpost_tree_class_names($tree));
    $nodeCss = outpost_node_styles_css($tree);
    $embedCss = outpost_tree_has_embed($tree) ? outpost_embed_base_css() : '';
    $allCss = outpost_expand_custom_media(outpost_global_style_css() . $classCss . $nodeCss . $embedCss, outpost_custom_media_map());
    $titleEsc = htmlspecialchars($page['title'] ?: 'Page', ENT_QUOTES);

    $doc = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>{$titleEsc}</title>\n"
        . "<outpost-seo></outpost-seo>\n"
        . $preservedLinks
        . $pageCssLink
        . "<style>\n{$allCss}</style>\n"
        . "</head>\n<body>\n{$body}\n{$jsLink}</body>\n</html>\n";

    return file_put_contents($existingFile, $doc) !== false;
}

function outpost_resolve_local_css(string $href): ?string {
    if ($href === '' || preg_match('#^[a-z]+:#i', $href)) return null;
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, "\0")) return null;

    if (str_starts_with($path, '/outpost/')) {
        $candidate = rtrim(OUTPOST_DIR, '/') . substr($path, strlen('/outpost'));
    } else {
        if (!function_exists('outpost_active_render_root')) require_once __DIR__ . '/blocks.php';
        $candidate = rtrim(outpost_active_render_root(), '/') . '/' . ltrim($path, '/');
    }
    $real = realpath($candidate);
    if (!$real || !is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'css') return null;

    $bases = [realpath(rtrim(OUTPOST_DIR, '/'))];
    if (function_exists('outpost_active_render_root')) $bases[] = realpath(rtrim(outpost_active_render_root(), '/'));
    foreach ($bases as $base) {
        if ($base && str_starts_with($real, rtrim($base, '/') . '/')) return $real;
    }
    return null;
}

function outpost_page_stylesheets(string $html): array {
    $links = [];
    $css = '';
    if (preg_match_all('/<link\b[^>]*>/i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('/rel=["\']?stylesheet["\']?/i', $tag)) continue;
            $links[] = $tag;
            if (preg_match('/href=["\']([^"\']+)["\']/i', $tag, $h)) {
                $file = outpost_resolve_local_css($h[1]);
                if ($file) {
                    $content = @file_get_contents($file);
                    if ($content !== false) $css .= "\n" . $content;
                }
            }
        }
    }
    return ['css' => $css, 'links' => $links];
}
