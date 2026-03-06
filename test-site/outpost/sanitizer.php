<?php
/**
 * Outpost CMS — HTML Sanitizer
 * Whitelist-based sanitizer for richtext content.
 */

class OutpostSanitizer {
    private static array $allowedTags = [
        'p', 'br', 'strong', 'em', 'u', 's', 'b', 'i',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code',
        'a', 'img',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'hr', 'div', 'span', 'figure', 'figcaption',
    ];

    private static array $allowedAttrs = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
        '*' => ['class', 'id'],
    ];

    public static function clean(string $html): string {
        if (trim($html) === '') return '';

        $dom = new DOMDocument('1.0', 'UTF-8');
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('div')->item(0);
        if (!$body) return '';

        self::walkNode($body, $dom);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function walkNode(DOMNode $node, DOMDocument $dom): void {
        $toRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);

                if (!in_array($tagName, self::$allowedTags)) {
                    // Replace disallowed tag with its text content
                    $text = $dom->createTextNode($child->textContent);
                    $toRemove[] = ['old' => $child, 'new' => $text];
                    continue;
                }

                // Filter attributes
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    $tagAllowed = self::$allowedAttrs[$tagName] ?? [];
                    $globalAllowed = self::$allowedAttrs['*'] ?? [];

                    if (!in_array($attrName, $tagAllowed) && !in_array($attrName, $globalAllowed)) {
                        $attrsToRemove[] = $attr->name;
                        continue;
                    }

                    // Sanitize href/src — only allow safe URI schemes
                    if (in_array($attrName, ['href', 'src'])) {
                        $val = preg_replace('/[\s\x00-\x1f]/u', '', trim($attr->value));
                        if (preg_match('/^(javascript|data|vbscript)\s*:/i', $val)) {
                            $attrsToRemove[] = $attr->name;
                        }
                    }

                    // Event handlers (on*)
                    if (str_starts_with($attrName, 'on')) {
                        $attrsToRemove[] = $attr->name;
                    }
                }

                foreach ($attrsToRemove as $attrName) {
                    $child->removeAttribute($attrName);
                }

                // Force rel=noopener on external links
                if ($tagName === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }

                // Recurse
                self::walkNode($child, $dom);
            }
        }

        foreach ($toRemove as $replacement) {
            $node->replaceChild($replacement['new'], $replacement['old']);
        }
    }
}
