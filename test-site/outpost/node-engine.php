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
    ];
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
    if (preg_match('/^\s*javascript:/i', $url)) return '';
    if (preg_match('/^\s*data:/i', $url)) return '';
    if (preg_match('/^\s*vbscript:/i', $url)) return '';
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

function outpost_dom_to_node(\DOMNode $el, array &$nodes): ?string {
    if ($el->nodeType !== XML_ELEMENT_NODE) return null;
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
            $cid = outpost_dom_to_node($child, $nodes);
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
    return outpost_node_validate(['root' => $rootId, 'nodes' => $nodes]);
}

function outpost_class_name_valid(string $name): bool {
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name);
}

function outpost_css_property_valid(string $prop): bool {
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

function outpost_classes_to_css(array $classes, string $scope = ''): string {
    $prefix = $scope !== '' ? rtrim($scope) . ' ' : '';
    $out = '';
    foreach ($classes as $name => $declarations) {
        if (!is_string($name) || !outpost_class_name_valid($name) || !is_array($declarations)) continue;
        $decls = outpost_sanitise_declarations($declarations);
        if (!$decls) continue;
        $body = '';
        foreach ($decls as $prop => $value) $body .= "{$prop}:{$value};";
        $out .= "{$prefix}.{$name}{{$body}}\n";
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
    $fld = outpost_node_field_attr($props);

    switch ($node['type']) {
        case 'component-ref':
            $cid = (string) ($props['componentId'] ?? '');
            if ($cid === '' || in_array($cid, $stack, true) || !isset($components[$cid]['tree'])) {
                return "<div{$cls}></div>";
            }
            $ctree = outpost_node_validate($components[$cid]['tree']);
            $inner = isset($ctree['nodes'][$ctree['root']])
                ? outpost_render_node($ctree, $ctree['root'], $components, array_merge($stack, [$cid]))
                : '';
            return "<div{$cls}>{$inner}</div>";

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

        case 'container':
        default:
            $inner = '';
            foreach ($node['children'] as $cid2) {
                $inner .= outpost_render_node($tree, $cid2, $components, $stack);
            }
            return "<{$tag}{$cls}{$fld}>{$inner}</{$tag}>";
    }
}

function outpost_public_class_css(): string {
    $rows = OutpostDB::fetchAll('SELECT name, declarations FROM style_classes ORDER BY name ASC');
    $css = '';
    foreach ($rows as $r) {
        if (!outpost_class_name_valid($r['name'])) continue;
        $decls = json_decode($r['declarations'] ?: '{}', true) ?: [];
        $body = '';
        foreach ($decls as $prop => $value) {
            if (!is_string($prop) || !outpost_css_property_valid($prop) || !is_scalar($value)) continue;
            $safe = outpost_css_value_safe((string) $value);
            if ($safe !== '') $body .= "{$prop}:{$safe};";
        }
        if ($body !== '') $css .= ".{$r['name']}{{$body}}\n";
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
    $classCss = outpost_public_class_css();
    $titleEsc = htmlspecialchars($page['title'] ?: 'Page', ENT_QUOTES);

    $doc = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>{$titleEsc}</title>\n"
        . "<outpost-seo></outpost-seo>\n"
        . $preservedLinks
        . $pageCssLink
        . "<style>\n{$classCss}</style>\n"
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
