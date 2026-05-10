<?php
/**
 * Outpost — Portable Text foundations (v6 Section 4)
 *
 * STATUS: Foundations only. Per the v6 plan, full Portable Text adoption is a
 * multi-quarter effort (multi-channel renderers, migration tooling, editor
 * UX). This file ships:
 *   1. The `richtext-blocks` field type — schema + validation.
 *   2. JSON storage shape (Sanity-compatible Portable Text-lite).
 *   3. Baseline HTML renderer (text + headings + lists + links + emphasis +
 *      images + code blocks). Sufficient for 80% of editorial content.
 *   4. Per-collection opt-in via schema field type.
 *   5. Rollback safety: callers can reuse outpost_richtext_blocks_to_html()
 *      to flatten back to HTML if a renderer bug surfaces.
 *
 * DEFERRED (tracked in v6 Section 4 of the plan):
 *   - Email renderer (inline styles, table layouts).
 *   - HTML → JSON migration tooling for legacy richtext fields.
 *   - Inline annotations (mentions, embeds, custom marks).
 *   - Embedded blocks (callouts, custom components).
 *
 * Storage shape — array of typed blocks:
 *   [
 *     {"type":"block","style":"h2","children":[{"type":"span","text":"Hello"}]},
 *     {"type":"block","style":"normal","children":[
 *        {"type":"span","text":"Read "},
 *        {"type":"span","text":"more","marks":["strong","link"],"linkHref":"/about"}
 *     ]},
 *     {"type":"image","src":"/uploads/cover.jpg","alt":"Cover"},
 *     {"type":"code","language":"php","code":"<?php echo 'hi';"}
 *   ]
 */

/**
 * Returns true if the given value looks like a valid portable-text payload.
 */
function outpost_richtext_blocks_validate(mixed $value): bool {
    if (!is_array($value)) return false;
    foreach ($value as $block) {
        if (!is_array($block)) return false;
        $t = $block['type'] ?? null;
        if ($t === null) return false;
        if (!in_array($t, ['block', 'image', 'code', 'list'], true)) return false;
    }
    return true;
}

/**
 * Render Portable Text JSON to HTML. Safe-by-default: every text node and
 * attribute is HTML-escaped. Marks supported: strong, em, code, link.
 */
function outpost_richtext_blocks_to_html(mixed $value): string {
    if (!is_array($value)) return '';
    $out = '';
    $listBuffer = null;
    $listType = null;

    $flushList = function () use (&$out, &$listBuffer, &$listType) {
        if ($listBuffer !== null) {
            $tag = $listType === 'number' ? 'ol' : 'ul';
            $out .= "<{$tag}>" . implode('', $listBuffer) . "</{$tag}>";
            $listBuffer = null;
            $listType = null;
        }
    };

    foreach ($value as $block) {
        if (!is_array($block)) continue;
        $type = $block['type'] ?? '';

        if ($type === 'block' && !empty($block['listItem'])) {
            $li = $block['listItem'];
            if ($listBuffer === null || $listType !== $li) {
                $flushList();
                $listBuffer = [];
                $listType = $li;
            }
            $listBuffer[] = '<li>' . _portable_text_render_children($block['children'] ?? []) . '</li>';
            continue;
        } else {
            $flushList();
        }

        switch ($type) {
            case 'block':
                $style = $block['style'] ?? 'normal';
                $children = _portable_text_render_children($block['children'] ?? []);
                $tag = match ($style) {
                    'h1' => 'h1',
                    'h2' => 'h2',
                    'h3' => 'h3',
                    'h4' => 'h4',
                    'blockquote' => 'blockquote',
                    default => 'p',
                };
                $out .= "<{$tag}>{$children}</{$tag}>";
                break;
            case 'image':
                $src = htmlspecialchars((string) ($block['src'] ?? ''), ENT_QUOTES, 'UTF-8');
                $alt = htmlspecialchars((string) ($block['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($src === '') break;
                $out .= "<figure><img src=\"{$src}\" alt=\"{$alt}\">";
                if (!empty($block['caption'])) {
                    $out .= '<figcaption>' . htmlspecialchars((string) $block['caption'], ENT_QUOTES, 'UTF-8') . '</figcaption>';
                }
                $out .= '</figure>';
                break;
            case 'code':
                $code = htmlspecialchars((string) ($block['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                $lang = htmlspecialchars((string) ($block['language'] ?? ''), ENT_QUOTES, 'UTF-8');
                $out .= '<pre><code' . ($lang !== '' ? " class=\"language-{$lang}\"" : '') . ">{$code}</code></pre>";
                break;
        }
    }
    $flushList();
    return $out;
}

function _portable_text_render_children(array $children): string {
    $out = '';
    foreach ($children as $child) {
        if (!is_array($child)) continue;
        if (($child['type'] ?? '') !== 'span') continue;
        $text = htmlspecialchars((string) ($child['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $marks = is_array($child['marks'] ?? null) ? $child['marks'] : [];
        $href = $child['linkHref'] ?? null;

        $open = '';
        $close = '';
        if (in_array('strong', $marks, true)) { $open .= '<strong>'; $close = '</strong>' . $close; }
        if (in_array('em', $marks, true))     { $open .= '<em>';     $close = '</em>' . $close; }
        if (in_array('code', $marks, true))   { $open .= '<code>';   $close = '</code>' . $close; }
        if (in_array('link', $marks, true) && $href) {
            // Strip whitespace + control chars (incl. NBSP, ZWSP) before
            // scheme detection so e.g. " javascript:..." doesn't slip
            // past the prefix check.
            $rawHref = preg_replace('/^[\s\x00-\x1F\x7F\xA0\p{Cf}\p{Zs}]+/u', '', (string) $href) ?? (string) $href;
            $isDangerousScheme = (bool) preg_match('/^(?:javascript|data|vbscript|file):/i', $rawHref);
            $safeHref = $isDangerousScheme
                ? '#'
                : htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8');
            $open .= "<a href=\"{$safeHref}\">";
            $close = '</a>' . $close;
        }
        $out .= $open . $text . $close;
    }
    return $out;
}

/**
 * Convert a HTML string to a basic Portable Text approximation. Used as the
 * one-way migration helper for richtext → richtext-blocks. Strips tags it
 * doesn't understand (rather than corrupting the JSON shape).
 */
function outpost_html_to_richtext_blocks(string $html): array {
    if ($html === '') return [];
    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    // XXE defense: LIBXML_NONET disables network access during parse;
    // LIBXML_NOENT is intentionally NOT set so external entities are not
    // expanded. PHP 8+ disables external entity loading by default.
    // Wrap so DOMDocument doesn't add html/body wrappers we have to chase.
    $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__pt_root__">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('__pt_root__');
    if (!$root) return [];
    $blocks = [];
    foreach ($root->childNodes as $node) {
        $b = _portable_text_dom_to_block($node);
        if ($b !== null) $blocks[] = $b;
    }
    return $blocks;
}

function _portable_text_dom_to_block(DOMNode $node): ?array {
    if ($node instanceof DOMText) {
        $text = trim($node->wholeText);
        if ($text === '') return null;
        return ['type' => 'block', 'style' => 'normal', 'children' => [
            ['type' => 'span', 'text' => $text]
        ]];
    }
    if (!($node instanceof DOMElement)) return null;
    $tag = strtolower($node->tagName);
    $style = match ($tag) {
        'h1' => 'h1',
        'h2' => 'h2',
        'h3' => 'h3',
        'h4' => 'h4',
        'blockquote' => 'blockquote',
        default => 'normal',
    };
    if ($tag === 'img') {
        return ['type' => 'image',
                'src' => $node->getAttribute('src'),
                'alt' => $node->getAttribute('alt')];
    }
    if ($tag === 'pre') {
        $code = $node->textContent;
        return ['type' => 'code', 'code' => $code, 'language' => ''];
    }
    if ($tag === 'ul' || $tag === 'ol') {
        $listType = $tag === 'ol' ? 'number' : 'bullet';
        $blocks = [];
        foreach ($node->childNodes as $li) {
            if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                $blocks[] = [
                    'type' => 'block',
                    'style' => 'normal',
                    'listItem' => $listType,
                    'children' => _portable_text_inline_children($li),
                ];
            }
        }
        return $blocks ? ['type' => '__inline_list__', 'blocks' => $blocks] : null;
    }
    return ['type' => 'block', 'style' => $style,
            'children' => _portable_text_inline_children($node)];
}

function _portable_text_inline_children(DOMNode $node): array {
    $out = [];
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text = $child->wholeText;
            if ($text === '') continue;
            $out[] = ['type' => 'span', 'text' => $text];
        } elseif ($child instanceof DOMElement) {
            $marks = [];
            $href = null;
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['strong', 'b'], true)) $marks[] = 'strong';
            if (in_array($tag, ['em', 'i'], true))     $marks[] = 'em';
            if ($tag === 'code')                       $marks[] = 'code';
            if ($tag === 'a') {
                $marks[] = 'link';
                $href = $child->getAttribute('href');
            }
            $text = $child->textContent;
            if ($text === '') continue;
            $span = ['type' => 'span', 'text' => $text];
            if ($marks) $span['marks'] = $marks;
            if ($href !== null) $span['linkHref'] = $href;
            $out[] = $span;
        }
    }
    return $out;
}
