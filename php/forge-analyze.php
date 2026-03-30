<?php
/**
 * Outpost CMS — Forge Site Analysis
 *
 * Cross-file analysis engine that converts a set of plain HTML files
 * into a fully Outpost-compatible site. Extracts shared partials,
 * auto-creates navigation menus, detects editable fields, and
 * registers everything in the database.
 *
 * No AI required — pure algorithmic analysis using DOM tree comparison,
 * frequency counting, and heuristic section detection.
 *
 * Phases 1–4 (this file):
 *   1. Discovery         — find and parse all HTML files
 *   2. Partial Extraction — cross-file diff to find shared nav/footer
 *   3. Head Classification — separate shared vs per-page <head> elements
 *   4. Nav Parsing        — extract menu items from nav HTML
 *
 * Loaded alongside smart-forge.php — can call its helpers.
 */

// ── Helper Utilities ───────────────────────────────────────

/**
 * Collapse all whitespace runs to a single space and trim.
 *
 * Used for comparing HTML fragments across files where formatting differs
 * but structure is identical.
 *
 * @param string $html  Raw HTML string
 * @return string  Normalized HTML with collapsed whitespace
 */
function forge_analyze_normalize_whitespace(string $html): string {
    // Collapse all whitespace (newlines, tabs, spaces) into single space
    $html = preg_replace('/\s+/', ' ', $html);
    return trim($html);
}

/**
 * Serialize a DOMNode to its outerHTML string.
 *
 * DOMDocument doesn't have a native outerHTML — this uses saveHTML()
 * on the node directly, which gives us the element and all its children.
 *
 * @param DOMNode     $node  The node to serialize
 * @param DOMDocument $doc   The owning document
 * @return string  The outerHTML of the node
 */
function forge_analyze_serialize_node(DOMNode $node, DOMDocument $doc): string {
    return $doc->saveHTML($node);
}

/**
 * Find the Nth element of a given tag name in a DOMDocument.
 *
 * Negative index counts from the end: -1 = last, -2 = second to last.
 *
 * @param DOMDocument $doc    The document to search
 * @param string      $tag    Tag name (e.g. 'nav', 'footer')
 * @param int         $index  0-based index, or negative for from-end
 * @return DOMElement|null  The matched element or null
 */
function forge_analyze_find_element(DOMDocument $doc, string $tag, int $index = 0): ?DOMElement {
    $elements = $doc->getElementsByTagName($tag);
    $count = $elements->length;
    if ($count === 0) return null;

    // Negative index: count from end
    if ($index < 0) {
        $index = $count + $index;
    }

    if ($index < 0 || $index >= $count) return null;

    $el = $elements->item($index);
    return ($el instanceof DOMElement) ? $el : null;
}

/**
 * Get the class list of a DOM element as an array.
 *
 * @param DOMElement $el  The element
 * @return array  Array of individual class names (trimmed, no empties)
 */
function forge_analyze_get_classes(DOMElement $el): array {
    $raw = $el->getAttribute('class');
    if (!$raw) return [];
    return array_values(array_filter(
        explode(' ', $raw),
        fn($c) => trim($c) !== ''
    ));
}

/**
 * Check if any of the element's classes contain one of the given substrings.
 *
 * Case-insensitive matching. Useful for detecting patterns like 'mobile',
 * 'overlay', 'dropdown', 'submenu' in class names.
 *
 * @param DOMElement $el        The element to check
 * @param array      $patterns  Substrings to look for (e.g. ['mobile', 'overlay'])
 * @return bool  True if any class contains any pattern
 */
function forge_analyze_class_contains(DOMElement $el, array $patterns): bool {
    $classes = forge_analyze_get_classes($el);
    foreach ($classes as $cls) {
        $lower = strtolower($cls);
        foreach ($patterns as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Compute a structural hash of a DOM subtree.
 *
 * Produces an md5 hash based on the tree's tag structure and text content,
 * ignoring attribute differences. Two nodes with the same tag hierarchy
 * and text will produce the same hash — useful for detecting shared
 * components (navs, footers) across files that may have different
 * active-state classes but identical structure.
 *
 * @param DOMNode $node  The root node to hash
 * @return string  md5 hash representing the structural signature
 */
function forge_analyze_structural_hash(DOMNode $node): string {
    // Text nodes: hash the trimmed content
    if ($node->nodeType === XML_TEXT_NODE) {
        $text = trim($node->textContent);
        if ($text === '') return '';
        return md5('#text|' . $text);
    }

    // Comment nodes: skip
    if ($node->nodeType === XML_COMMENT_NODE) {
        return '';
    }

    // Element nodes: hash tag name + children recursively
    if ($node->nodeType === XML_ELEMENT_NODE) {
        $childHashes = [];
        foreach ($node->childNodes as $child) {
            $h = forge_analyze_structural_hash($child);
            if ($h !== '') {
                $childHashes[] = $h;
            }
        }
        return md5(strtolower($node->nodeName) . '|' . implode(',', $childHashes));
    }

    return '';
}

/**
 * Load an HTML string into a DOMDocument with error suppression.
 *
 * Wraps the content in a proper HTML5 structure to avoid parse issues
 * with fragments, then returns the document.
 *
 * @param string $html  Raw HTML content
 * @return DOMDocument  Parsed document
 */
function forge_analyze_load_html(string $html): DOMDocument {
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);

    // If the HTML already has a doctype or <html> tag, load as-is
    if (preg_match('/<!doctype|<html[\s>]/i', $html)) {
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED);
    } else {
        // Fragment — wrap in a full document
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED);
    }

    libxml_clear_errors();
    return $doc;
}


// ── Phase 1: Discovery ─────────────────────────────────────

/**
 * Discover all HTML files at the site root eligible for Forge analysis.
 *
 * Scans OUTPOST_SITE_ROOT for *.html files (non-recursive), skipping:
 *   - Files starting with _ or . (partials, hidden files)
 *   - Files already containing data-outpost= attributes (idempotency)
 *
 * Each file is parsed into a DOMDocument for cross-file analysis.
 *
 * @return array {
 *   'files'       => array of ['path' => string, 'html' => string, 'doc' => DOMDocument],
 *   'single_file' => bool  True if only one file found (single-file fallback mode),
 *   'warnings'    => array of string
 * }
 */
function forge_analyze_discover(): array {
    $siteRoot = defined('OUTPOST_SITE_ROOT') ? OUTPOST_SITE_ROOT : dirname(__DIR__) . '/';
    $pattern = rtrim($siteRoot, '/') . '/*.html';
    $matches = glob($pattern);

    $files = [];
    $warnings = [];

    // Safety limits to prevent DoS via very large sites
    $maxFiles   = 100;  // Maximum number of HTML files to process
    $maxFileSize = 2 * 1024 * 1024;  // 2 MB per file

    if (!$matches) {
        $warnings[] = 'No .html files found in site root: ' . $siteRoot;
        return ['files' => [], 'single_file' => false, 'warnings' => $warnings];
    }

    foreach ($matches as $fullPath) {
        $basename = basename($fullPath);

        // Skip hidden files and partials (prefixed with _ or .)
        if ($basename[0] === '_' || $basename[0] === '.') {
            continue;
        }

        // Enforce file count limit
        if (count($files) >= $maxFiles) {
            $warnings[] = "Stopped at {$maxFiles} files — limit reached";
            break;
        }

        // Enforce file size limit
        $fileSize = filesize($fullPath);
        if ($fileSize > $maxFileSize) {
            $warnings[] = "Skipped {$basename} — file too large (" . round($fileSize / 1024) . " KB, max " . round($maxFileSize / 1024) . " KB)";
            continue;
        }

        // Read file contents
        $html = file_get_contents($fullPath);
        if ($html === false) {
            $warnings[] = "Could not read file: {$basename}";
            continue;
        }

        // Idempotency: skip files already containing data-outpost attributes
        if (preg_match('/\bdata-outpost\s*=/', $html)) {
            $warnings[] = "Skipped {$basename} — already contains data-outpost attributes";
            continue;
        }

        // Parse into DOM
        $doc = forge_analyze_load_html($html);

        // Compute relative path from site root
        $relativePath = $basename;

        $files[] = [
            'path' => $relativePath,
            'html'  => $html,
            'doc'   => $doc,
        ];
    }

    $singleFile = (count($files) === 1);

    if ($singleFile && count($files) === 1) {
        $warnings[] = 'Single-file mode: only one HTML file found, cross-file analysis limited';
    }

    return [
        'files'       => $files,
        'single_file' => $singleFile,
        'warnings'    => $warnings,
    ];
}


// ── Phase 2: Cross-File Partial Extraction ──────────────────

/**
 * Extract shared partials (nav, footer, mobile menu) by comparing DOM
 * structures across all discovered files.
 *
 * Algorithm (Site Style Trees approach):
 *   1. For each file, find the FIRST <nav> and LAST <footer>
 *   2. Serialize outerHTML, normalize whitespace, compute md5 hash
 *   3. If the same hash appears in 80%+ of files, it's a shared partial
 *   4. The most common version becomes the canonical partial HTML
 *   5. Also detect mobile overlay/menu divs (class containing 'mobile' or 'overlay'
 *      with multiple <a> descendants)
 *
 * @param array $files  The 'files' array from forge_analyze_discover()
 * @return array {
 *   'nav'         => ['html' => string, 'hash' => string, 'match_count' => int] | null,
 *   'footer'      => ['html' => string, 'hash' => string, 'match_count' => int] | null,
 *   'mobile_menu' => ['html' => string, 'hash' => string, 'match_count' => int] | null,
 *   'warnings'    => array of string
 * }
 */
function forge_analyze_extract_partials(array $files): array {
    $fileCount = count($files);
    $threshold = max(1, (int)floor($fileCount * 0.8));
    $warnings = [];

    $result = [
        'nav'         => null,
        'footer'      => null,
        'mobile_menu' => null,
        'warnings'    => [],
    ];

    if ($fileCount === 0) return $result;

    // ── Collect nav hashes ──
    $navHashes = [];     // hash => ['html' => string, 'count' => int]
    $navByFile = [];     // filename => hash|null

    foreach ($files as $file) {
        $doc = $file['doc'];
        $nav = forge_analyze_find_element($doc, 'nav', 0);

        if ($nav) {
            $html = forge_analyze_serialize_node($nav, $doc);
            $normalized = forge_analyze_normalize_whitespace($html);
            // Strip active-state classes before hashing — these vary per page
            // but the nav structure is identical
            $forHash = preg_replace('/\s*\b(nav-active|active|current|current-menu-item|current_page_item)\b/', '', $normalized);
            // Strip aria-current attributes (vary per page)
            $forHash = preg_replace('/\s*aria-current="[^"]*"/', '', $forHash);
            // Collapse multiple spaces inside class attribute values
            $forHash = preg_replace_callback('/class="([^"]*)"/', function($m) {
                $val = trim(preg_replace('/\s+/', ' ', $m[1]));
                return $val === '' ? '' : 'class="' . $val . '"';
            }, $forHash);
            // Clean up any residual whitespace before > from removed attributes
            $forHash = preg_replace('/\s+>/', '>', $forHash);
            // Collapse any double spaces left behind
            $forHash = preg_replace('/\s{2,}/', ' ', $forHash);
            $hash = md5($forHash);

            $navByFile[$file['path']] = $hash;

            if (!isset($navHashes[$hash])) {
                $navHashes[$hash] = ['html' => $html, 'count' => 0];
            }
            $navHashes[$hash]['count']++;
        } else {
            $navByFile[$file['path']] = null;
        }
    }

    // Find most common nav
    if (!empty($navHashes)) {
        // Sort by count descending
        uasort($navHashes, fn($a, $b) => $b['count'] <=> $a['count']);
        $topNav = array_key_first($navHashes);
        $top = $navHashes[$topNav];

        if ($top['count'] >= $threshold) {
            $result['nav'] = [
                'html'        => $top['html'],
                'hash'        => $topNav,
                'match_count' => $top['count'],
            ];

            // Warn about files missing the shared nav
            foreach ($navByFile as $path => $hash) {
                if ($hash === null) {
                    $warnings[] = "File '{$path}' has no <nav> element — shared nav partial will be added";
                } elseif ($hash !== $topNav) {
                    $warnings[] = "File '{$path}' has a different nav than the shared version — will use per-page nav";
                }
            }
        }
    }

    // ── Collect footer hashes ──
    $footerHashes = [];
    $footerByFile = [];

    foreach ($files as $file) {
        $doc = $file['doc'];
        // Get the LAST footer element
        $footer = forge_analyze_find_element($doc, 'footer', -1);

        if ($footer) {
            $html = forge_analyze_serialize_node($footer, $doc);
            $normalized = forge_analyze_normalize_whitespace($html);
            $hash = md5($normalized);

            $footerByFile[$file['path']] = $hash;

            if (!isset($footerHashes[$hash])) {
                $footerHashes[$hash] = ['html' => $html, 'count' => 0];
            }
            $footerHashes[$hash]['count']++;
        } else {
            $footerByFile[$file['path']] = null;
        }
    }

    // Find most common footer
    if (!empty($footerHashes)) {
        uasort($footerHashes, fn($a, $b) => $b['count'] <=> $a['count']);
        $topFooterHash = array_key_first($footerHashes);
        $top = $footerHashes[$topFooterHash];

        if ($top['count'] >= $threshold) {
            $result['footer'] = [
                'html'        => $top['html'],
                'hash'        => $topFooterHash,
                'match_count' => $top['count'],
            ];

            foreach ($footerByFile as $path => $hash) {
                if ($hash === null) {
                    $warnings[] = "File '{$path}' has no <footer> element — shared footer partial will be added";
                } elseif ($hash !== $topFooterHash) {
                    $warnings[] = "File '{$path}' has a different footer than the shared version — will use per-page footer";
                }
            }
        }
    }

    // ── Detect mobile overlay / mobile menu ──
    $mobileHashes = [];
    $mobilePatterns = ['mobile', 'overlay', 'offcanvas', 'off-canvas', 'sidebar-menu', 'hamburger-menu'];

    foreach ($files as $file) {
        $doc = $file['doc'];
        $xpath = new DOMXPath($doc);
        $divs = $xpath->query('//div');

        foreach ($divs as $div) {
            if (!($div instanceof DOMElement)) continue;
            if (!forge_analyze_class_contains($div, $mobilePatterns)) continue;

            // Must have multiple <a> tags to be a menu, not just a wrapper
            $links = $div->getElementsByTagName('a');
            if ($links->length < 2) continue;

            $html = forge_analyze_serialize_node($div, $doc);
            $normalized = forge_analyze_normalize_whitespace($html);
            // Strip active-state classes (same as nav normalization)
            $forHash = preg_replace('/\s*\b(nav-active|active|current|current-menu-item|current_page_item)\b/', '', $normalized);
            $forHash = preg_replace('/\s*aria-current="[^"]*"/', '', $forHash);
            $forHash = preg_replace_callback('/class="([^"]*)"/', function($m) {
                $val = trim(preg_replace('/\s+/', ' ', $m[1]));
                return $val === '' ? '' : 'class="' . $val . '"';
            }, $forHash);
            $forHash = preg_replace('/\s+>/', '>', $forHash);
            $forHash = preg_replace('/\s{2,}/', ' ', $forHash);
            $hash = md5($forHash);

            if (!isset($mobileHashes[$hash])) {
                $mobileHashes[$hash] = ['html' => $html, 'count' => 0];
            }
            $mobileHashes[$hash]['count']++;
            break; // Only take the first mobile menu per file
        }
    }

    if (!empty($mobileHashes)) {
        uasort($mobileHashes, fn($a, $b) => $b['count'] <=> $a['count']);
        $topMobileHash = array_key_first($mobileHashes);
        $top = $mobileHashes[$topMobileHash];

        if ($top['count'] >= $threshold) {
            $result['mobile_menu'] = [
                'html'        => $top['html'],
                'hash'        => $topMobileHash,
                'match_count' => $top['count'],
            ];
        }
    }

    $result['warnings'] = $warnings;
    return $result;
}


// ── Phase 3: Head Classification ────────────────────────────

/**
 * Classify <head> elements across all files as shared (identical in all)
 * or per-page (varying between files).
 *
 * Certain tags are ALWAYS per-page regardless of frequency:
 *   - <title>
 *   - <meta name="description">
 *   - <meta name="keywords">
 *   - <meta property="og:title|og:description|og:url">
 *   - <link rel="canonical">
 *
 * Everything else is classified by frequency: if it appears in every file
 * with the same content, it's shared; otherwise per-page.
 *
 * @param array $files  The 'files' array from forge_analyze_discover()
 * @return array {
 *   'shared_html'         => string  HTML of shared head elements,
 *   'per_page'            => array   [filename => [key => element_html]],
 *   'always_per_page_keys' => array  List of keys always classified as per-page
 * }
 */
function forge_analyze_classify_head(array $files): array {
    $fileCount = count($files);

    // Keys that are ALWAYS per-page, even if identical across files
    $alwaysPerPage = [
        'title',
        'meta:description',
        'meta:keywords',
        'og:title',
        'og:description',
        'og:url',
        'canonical',
    ];

    // Collect all head elements across files, keyed by normalized identifier
    // Structure: [key => [serialized_html => count]]
    $frequency = [];
    // Per-file head elements: [filename => [key => serialized_html]]
    $perFileElements = [];

    foreach ($files as $file) {
        $doc = $file['doc'];
        $heads = $doc->getElementsByTagName('head');
        if ($heads->length === 0) continue;

        $head = $heads->item(0);
        $fileElements = [];

        foreach ($head->childNodes as $child) {
            // Skip text nodes (whitespace between tags)
            if ($child->nodeType === XML_TEXT_NODE) continue;
            if ($child->nodeType === XML_COMMENT_NODE) continue;
            if (!($child instanceof DOMElement)) continue;

            $key = forge_analyze_head_element_key($child);
            if ($key === null) continue;

            $serialized = forge_analyze_serialize_node($child, $doc);
            $normalized = forge_analyze_normalize_whitespace($serialized);

            $fileElements[$key] = $normalized;

            if (!isset($frequency[$key])) {
                $frequency[$key] = [];
            }
            if (!isset($frequency[$key][$normalized])) {
                $frequency[$key][$normalized] = 0;
            }
            $frequency[$key][$normalized]++;
        }

        $perFileElements[$file['path']] = $fileElements;
    }

    // Classify each key
    $sharedElements = [];  // ordered list of HTML strings
    $perPageResult = [];   // [filename => [key => html]]

    // Initialize per-page arrays
    foreach ($files as $file) {
        $perPageResult[$file['path']] = [];
    }

    foreach ($frequency as $key => $variants) {
        $isAlwaysPerPage = in_array($key, $alwaysPerPage, true);

        // Find the most common version of this element
        arsort($variants);
        $topHtml = array_key_first($variants);
        $topCount = $variants[$topHtml];

        // Shared: appears in ALL files with identical content, and not an always-per-page key
        $isShared = (!$isAlwaysPerPage && $topCount === $fileCount && count($variants) === 1);

        if ($isShared) {
            $sharedElements[] = $topHtml;
        } else {
            // Per-page: record each file's version
            foreach ($perFileElements as $path => $elements) {
                if (isset($elements[$key])) {
                    $perPageResult[$path][$key] = $elements[$key];
                }
            }
        }
    }

    return [
        'shared_html'          => implode("\n    ", $sharedElements),
        'per_page'             => $perPageResult,
        'always_per_page_keys' => $alwaysPerPage,
    ];
}

/**
 * Generate a normalized key for a <head> child element.
 *
 * Maps each head element to a stable identifier used for frequency counting.
 * Returns null for elements that can't be meaningfully classified.
 *
 * @param DOMElement $el  A child element of <head>
 * @return string|null  Normalized key, or null to skip
 */
function forge_analyze_head_element_key(DOMElement $el): ?string {
    $tag = strtolower($el->nodeName);

    switch ($tag) {
        case 'title':
            return 'title';

        case 'meta':
            // <meta charset="...">
            if ($el->hasAttribute('charset')) {
                return 'meta:charset';
            }
            // <meta name="...">
            $name = strtolower($el->getAttribute('name'));
            if ($name) {
                // Common named metas
                if ($name === 'description') return 'meta:description';
                if ($name === 'keywords') return 'meta:keywords';
                if ($name === 'viewport') return 'meta:viewport';
                if ($name === 'robots') return 'meta:robots';
                if ($name === 'author') return 'meta:author';
                return 'meta:' . $name;
            }
            // <meta property="og:..."> / <meta property="twitter:...">
            $prop = strtolower($el->getAttribute('property'));
            if ($prop) {
                return $prop; // e.g. 'og:title', 'og:description'
            }
            // <meta http-equiv="...">
            $httpEquiv = strtolower($el->getAttribute('http-equiv'));
            if ($httpEquiv) {
                return 'meta:http-equiv:' . $httpEquiv;
            }
            return null;

        case 'link':
            $rel = strtolower($el->getAttribute('rel'));
            $href = $el->getAttribute('href');
            if ($rel === 'stylesheet') {
                return 'link:stylesheet:' . basename($href);
            }
            if ($rel === 'canonical') {
                return 'canonical';
            }
            if ($rel === 'icon' || $rel === 'shortcut icon') {
                return 'link:icon:' . basename($href);
            }
            if ($rel === 'preconnect') {
                return 'link:preconnect:' . $href;
            }
            if ($rel === 'preload') {
                return 'link:preload:' . basename($href);
            }
            if ($rel === 'apple-touch-icon') {
                return 'link:apple-touch-icon:' . basename($href);
            }
            return 'link:' . $rel . ':' . basename($href);

        case 'script':
            $src = $el->getAttribute('src');
            if ($src) {
                return 'script:' . basename($src);
            }
            // Inline script — key by content hash
            $content = $el->textContent;
            return 'script:inline:' . md5($content);

        case 'style':
            // Inline style — key by content hash
            $content = $el->textContent;
            return 'style:' . md5($content);

        default:
            return null;
    }
}


// ── Phase 4: Nav Parsing + Menu Creation ────────────────────

/**
 * Parse extracted nav HTML into a structured menu definition.
 *
 * Extracts all navigation links, detects dropdowns/submenus,
 * and also parses the footer for a secondary "footer" menu.
 *
 * Priority order for finding link containers:
 *   1. nav > .nav-links or nav > ul (direct child container)
 *   2. Any <ul> or <div> with multiple <a> descendants
 *
 * Dropdown detection: sibling/child elements with class containing
 * 'dropdown', 'submenu', 'sub-menu'.
 *
 * @param array $partials  The result from forge_analyze_extract_partials()
 * @return array  ['main' => ['name' => string, 'slug' => string, 'items' => [...]],
 *                  'footer' => [...] | null]
 */
function forge_analyze_parse_nav(array $partials): array {
    $result = [];

    // ── Parse main nav ──
    if (!empty($partials['nav']['html'])) {
        $items = forge_analyze_extract_menu_items($partials['nav']['html'], 'nav');
        if (!empty($items)) {
            $result['main'] = [
                'name'  => 'Main Navigation',
                'slug'  => 'main',
                'items' => $items,
            ];
        }
    }

    // ── Parse mobile menu (may have different/additional items) ──
    // If we got a main nav, merge any unique items from the mobile menu
    if (!empty($partials['mobile_menu']['html']) && !empty($result['main'])) {
        $mobileItems = forge_analyze_extract_menu_items($partials['mobile_menu']['html'], 'div');
        $existingUrls = array_column($result['main']['items'], 'url');

        foreach ($mobileItems as $item) {
            if (!in_array($item['url'], $existingUrls, true)) {
                $result['main']['items'][] = $item;
            }
        }
    } elseif (!empty($partials['mobile_menu']['html']) && empty($result['main'])) {
        // No desktop nav found — use mobile menu as main
        $items = forge_analyze_extract_menu_items($partials['mobile_menu']['html'], 'div');
        if (!empty($items)) {
            $result['main'] = [
                'name'  => 'Main Navigation',
                'slug'  => 'main',
                'items' => $items,
            ];
        }
    }

    // ── Parse footer for a secondary menu ──
    if (!empty($partials['footer']['html'])) {
        $footerItems = forge_analyze_extract_footer_menu($partials['footer']['html']);
        if (!empty($footerItems)) {
            $result['footer'] = [
                'name'  => 'Footer Navigation',
                'slug'  => 'footer',
                'items' => $footerItems,
            ];
        }
    }

    return $result;
}

/**
 * Extract menu items from an HTML fragment containing navigation links.
 *
 * Handles nested dropdowns by looking for child elements with classes
 * containing 'dropdown', 'submenu', or 'sub-menu'.
 *
 * @param string $html      The nav/menu HTML
 * @param string $rootTag   Expected root tag ('nav' or 'div')
 * @return array  Array of menu items: [['label' => string, 'url' => string, 'target' => string, 'children' => [...]]]
 */
function forge_analyze_extract_menu_items(string $html, string $rootTag = 'nav'): array {
    $doc = forge_analyze_load_html($html);
    $items = [];

    // Find the root navigation element
    $root = forge_analyze_find_element($doc, $rootTag, 0);
    if (!$root) {
        // Try common fallbacks
        $root = forge_analyze_find_element($doc, 'nav', 0);
        if (!$root) {
            $root = forge_analyze_find_element($doc, 'div', 0);
        }
        if (!$root) return [];
    }

    // Priority 1: Look for direct child link containers (.nav-links, ul)
    $linkContainer = forge_analyze_find_link_container($root);

    if ($linkContainer) {
        $items = forge_analyze_parse_link_container($linkContainer, $doc);
    } else {
        // Priority 2: Parse all direct <a> children of the root
        $items = forge_analyze_parse_flat_links($root, $doc);
    }

    return $items;
}

/**
 * Find the primary link container inside a navigation element.
 *
 * Looks for:
 *   1. A direct child with class containing 'nav-links', 'nav-items', 'menu'
 *   2. A direct child <ul>
 *   3. Any descendant <ul> with multiple <li> children
 *
 * @param DOMElement $root  The nav/wrapper element
 * @return DOMElement|null  The link container, or null
 */
function forge_analyze_find_link_container(DOMElement $root): ?DOMElement {
    $containerPatterns = ['nav-links', 'nav-items', 'nav-menu', 'menu-items', 'navbar-nav', 'menu'];

    // 1. Direct child with nav-like class
    foreach ($root->childNodes as $child) {
        if (!($child instanceof DOMElement)) continue;
        if (forge_analyze_class_contains($child, $containerPatterns)) {
            return $child;
        }
    }

    // 2. Direct child <ul>
    foreach ($root->childNodes as $child) {
        if (!($child instanceof DOMElement)) continue;
        if (strtolower($child->nodeName) === 'ul') {
            return $child;
        }
    }

    // 3. Any descendant <ul> with 2+ <li> children
    $uls = $root->getElementsByTagName('ul');
    foreach ($uls as $ul) {
        if (!($ul instanceof DOMElement)) continue;
        $liCount = 0;
        foreach ($ul->childNodes as $c) {
            if ($c instanceof DOMElement && strtolower($c->nodeName) === 'li') {
                $liCount++;
            }
        }
        if ($liCount >= 2) return $ul;
    }

    return null;
}

/**
 * Parse a link container (ul, div with nav-links class) into menu items.
 *
 * Handles <li> wrappers and detects dropdown submenus.
 *
 * @param DOMElement  $container  The link container element
 * @param DOMDocument $doc        The owning document
 * @return array  Array of menu item arrays
 */
function forge_analyze_parse_link_container(DOMElement $container, DOMDocument $doc): array {
    $items = [];
    $tag = strtolower($container->nodeName);
    $dropdownPatterns = ['dropdown', 'submenu', 'sub-menu', 'subnav', 'sub-nav', 'has-children'];

    if ($tag === 'ul' || $tag === 'ol') {
        // Parse <li> children
        foreach ($container->childNodes as $li) {
            if (!($li instanceof DOMElement)) continue;
            if (strtolower($li->nodeName) !== 'li') continue;

            $item = forge_analyze_parse_menu_li($li, $doc, $dropdownPatterns);
            if ($item) $items[] = $item;
        }
    } else {
        // Div-based container — look for direct <a> children or <a> in child divs
        foreach ($container->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;

            $childTag = strtolower($child->nodeName);

            if ($childTag === 'a') {
                $item = forge_analyze_link_to_item($child);
                if ($item) $items[] = $item;
            } elseif ($childTag === 'div' || $childTag === 'span') {
                // Check for dropdown wrapper
                if (forge_analyze_class_contains($child, $dropdownPatterns)) {
                    // This is a dropdown — find the trigger link and children
                    $triggerLink = forge_analyze_find_element_in($child, 'a', 0);
                    if ($triggerLink) {
                        $item = forge_analyze_link_to_item($triggerLink);
                        if ($item) {
                            $item['children'] = forge_analyze_extract_dropdown_items($child, $triggerLink, $doc);
                            $items[] = $item;
                        }
                    }
                } else {
                    // Regular wrapper — extract its <a> tag
                    $link = forge_analyze_find_element_in($child, 'a', 0);
                    if ($link) {
                        $item = forge_analyze_link_to_item($link);
                        if ($item) $items[] = $item;
                    }
                }
            }
        }
    }

    return $items;
}

/**
 * Parse a single <li> element into a menu item, detecting dropdowns.
 *
 * @param DOMElement  $li                The <li> element
 * @param DOMDocument $doc               The owning document
 * @param array       $dropdownPatterns  Class patterns indicating a dropdown
 * @return array|null  Menu item array or null if no link found
 */
function forge_analyze_parse_menu_li(DOMElement $li, DOMDocument $doc, array $dropdownPatterns): ?array {
    // Find the primary <a> link in this li (first one)
    $link = forge_analyze_find_element_in($li, 'a', 0);
    if (!$link) return null;

    $item = forge_analyze_link_to_item($link);
    if (!$item) return null;

    // Check if this <li> has a dropdown
    $hasDropdown = forge_analyze_class_contains($li, $dropdownPatterns);

    if (!$hasDropdown) {
        // Check child elements for dropdown containers
        foreach ($li->childNodes as $child) {
            if (!($child instanceof DOMElement)) continue;
            if (forge_analyze_class_contains($child, $dropdownPatterns)) {
                $hasDropdown = true;
                break;
            }
            // Also check for nested <ul> (classic dropdown pattern)
            if (strtolower($child->nodeName) === 'ul') {
                $hasDropdown = true;
                break;
            }
        }
    }

    if ($hasDropdown) {
        $item['children'] = forge_analyze_extract_dropdown_items($li, $link, $doc);
    }

    return $item;
}

/**
 * Extract child menu items from a dropdown container.
 *
 * Finds all <a> tags inside the element that are NOT the trigger link,
 * and returns them as child menu items.
 *
 * @param DOMElement  $container    The dropdown parent element
 * @param DOMElement  $triggerLink  The parent link (to exclude)
 * @param DOMDocument $doc          The owning document
 * @return array  Array of child menu item arrays
 */
function forge_analyze_extract_dropdown_items(DOMElement $container, DOMElement $triggerLink, DOMDocument $doc): array {
    $children = [];
    $links = $container->getElementsByTagName('a');

    foreach ($links as $link) {
        if (!($link instanceof DOMElement)) continue;
        // Skip the trigger link itself
        if ($link->isSameNode($triggerLink)) continue;

        $item = forge_analyze_link_to_item($link);
        if ($item) $children[] = $item;
    }

    return $children;
}

/**
 * Convert a DOMElement <a> tag into a menu item array.
 *
 * @param DOMElement $link  An <a> element
 * @return array|null  ['label' => string, 'url' => string, 'target' => string, 'children' => []] or null
 */
function forge_analyze_link_to_item(DOMElement $link): ?array {
    $label = trim($link->textContent);
    $href = $link->getAttribute('href');
    $target = $link->getAttribute('target') ?: '_self';

    // Skip empty labels (icon-only links, etc.)
    if ($label === '' && !$href) return null;
    // Use href as label fallback for icon-only links
    if ($label === '') $label = basename($href) ?: 'Link';

    return [
        'label'    => $label,
        'url'      => $href ?: '#',
        'target'   => $target,
        'children' => [],
    ];
}

/**
 * Parse flat <a> links directly inside a container (no ul/li structure).
 *
 * @param DOMElement  $root  The container element
 * @param DOMDocument $doc   The owning document
 * @return array  Array of menu item arrays
 */
function forge_analyze_parse_flat_links(DOMElement $root, DOMDocument $doc): array {
    $items = [];
    $links = $root->getElementsByTagName('a');

    foreach ($links as $link) {
        if (!($link instanceof DOMElement)) continue;

        // Only include links that are somewhat "top level" — skip deeply nested ones
        // that are likely inside logo wrappers, social icons, etc.
        $depth = 0;
        $parent = $link->parentNode;
        while ($parent && $parent instanceof DOMElement && !$parent->isSameNode($root)) {
            $depth++;
            $parent = $parent->parentNode;
        }

        // Skip links nested more than 3 levels deep (probably not main nav)
        if ($depth > 3) continue;

        $item = forge_analyze_link_to_item($link);
        if ($item) $items[] = $item;
    }

    return $items;
}

/**
 * Extract a "footer menu" from footer HTML.
 *
 * Looks for a group of links in the footer that form a navigation set
 * (not social media icons or copyright text).
 *
 * @param string $footerHtml  The footer partial HTML
 * @return array  Array of menu item arrays, or empty if no footer nav detected
 */
function forge_analyze_extract_footer_menu(string $footerHtml): array {
    $doc = forge_analyze_load_html($footerHtml);
    $footer = forge_analyze_find_element($doc, 'footer', 0);
    if (!$footer) return [];

    // ONLY extract menus from <nav> elements inside the footer.
    // If the developer wants footer links managed by Outpost's menu system,
    // they wrap them in <nav>. Otherwise they stay as static HTML.
    $footerNav = forge_analyze_find_element_in($footer, 'nav', 0);
    if ($footerNav) {
        $container = forge_analyze_find_link_container($footerNav);
        if ($container) {
            return forge_analyze_parse_link_container($container, $doc);
        }
        return forge_analyze_parse_flat_links($footerNav, $doc);
    }

    // No <nav> in footer — no footer menu. Links stay as regular content.
    return [];
}

/**
 * REMOVED: Generic div-based footer menu extraction.
 * Menus are only extracted from <nav> elements. This is the developer contract:
 * wrap your navigation links in <nav> if you want them managed by Outpost.
 */
function _forge_analyze_extract_footer_menu_legacy(): array {
    return [];
}

/**
 * Find the Nth element of a given tag within a specific parent element.
 *
 * Unlike forge_analyze_find_element() which searches the whole document,
 * this searches only within a subtree.
 *
 * @param DOMElement $parent  The parent to search within
 * @param string     $tag     Tag name to find
 * @param int        $index   0-based index
 * @return DOMElement|null
 */
function forge_analyze_find_element_in(DOMElement $parent, string $tag, int $index = 0): ?DOMElement {
    $elements = $parent->getElementsByTagName($tag);
    $count = $elements->length;
    if ($count === 0) return null;

    if ($index < 0) {
        $index = $count + $index;
    }
    if ($index < 0 || $index >= $count) return null;

    $el = $elements->item($index);
    return ($el instanceof DOMElement) ? $el : null;
}
/**
 * Outpost CMS — Forge Analyze: Phases 5–8
 *
 * This file is APPENDED to forge-analyze.php after Phase 1–4.
 * It provides per-page scanning, global field detection, template rewriting,
 * database registration, backup, and API handler functions.
 *
 * Dependencies (loaded in same process):
 *   - smart-forge.php  (smart_forge_scan, smart_forge_walk, smart_forge_generate_template, etc.)
 *   - engine.php       (outpost_scan_site_templates, outpost_build_site_manifest via theme-manifest.php)
 *   - db.php           (OutpostDB)
 *   - config.php       (OUTPOST_* constants)
 */


// ════════════════════════════════════════════════════════════
// Phase 5: Per-Page Smart Forge Scan
// ════════════════════════════════════════════════════════════

/**
 * Scan a single page's HTML after partials have been extracted.
 *
 * Strips the regions that correspond to nav, footer, and shared <head> content,
 * then runs the existing `smart_forge_scan()` engine on the remaining body HTML.
 *
 * @param string $originalHtml     The full original HTML of this page
 * @param array  $partials         Partials extracted by Phase 3/4, keyed by name:
 *                                 ['nav' => ['html'=>'...','start'=>int,'end'=>int], ...]
 *                                 Each entry has 'start' (byte offset) and 'end' (byte offset)
 * @param array  $headClassification  Head classification from Phase 2:
 *                                    ['shared'=>[elements], 'per_page'=>[elements],
 *                                     'head_start'=>int, 'head_end'=>int]
 * @param string $filename         Original filename (for context in field naming)
 * @return array  Smart Forge scan result: {template, fields, sections, field_map, repeaters, suggested_partials}
 */
function forge_analyze_scan_page(string $originalHtml, array $partials, array $headClassification, string $filename = ''): array {
    // Build a list of byte ranges to strip (nav, footer, mobile-menu, shared head)
    $stripRanges = [];

    // Strip shared head content — replace entire <head> innards with just per-page elements
    // We keep the <body> tag and everything after it, minus nav/footer
    if (!empty($headClassification['head_start']) && !empty($headClassification['head_end'])) {
        $stripRanges[] = [
            'start' => (int)$headClassification['head_start'],
            'end'   => (int)$headClassification['head_end'],
        ];
    }

    // Strip each extracted partial region from the HTML
    foreach ($partials as $name => $partial) {
        if (isset($partial['start'], $partial['end'])) {
            $stripRanges[] = [
                'start' => (int)$partial['start'],
                'end'   => (int)$partial['end'],
            ];
        }
    }

    // Sort ranges by start offset descending so we can strip from back to front
    usort($stripRanges, fn($a, $b) => $b['start'] - $a['start']);

    // Strip regions from the HTML
    $strippedHtml = $originalHtml;
    foreach ($stripRanges as $range) {
        $before = substr($strippedHtml, 0, $range['start']);
        $after  = substr($strippedHtml, $range['end']);
        $strippedHtml = $before . $after;
    }

    // Remove DOCTYPE, <html>, <head>, </head>, <body>, </body>, </html> wrapper tags
    // so smart_forge_scan gets clean body content
    $strippedHtml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $strippedHtml);
    $strippedHtml = preg_replace('/<\/?html[^>]*>/i', '', $strippedHtml);
    $strippedHtml = preg_replace('/<\/?head[^>]*>/i', '', $strippedHtml);
    $strippedHtml = preg_replace('/<\/?body[^>]*>/i', '', $strippedHtml);
    $strippedHtml = trim($strippedHtml);

    // Run the existing Smart Forge scanner on the cleaned body content
    $scanResult = smart_forge_scan($strippedHtml, $filename);

    return $scanResult;
}


// ════════════════════════════════════════════════════════════
// Phase 6: Global Field Detection
// ════════════════════════════════════════════════════════════

/**
 * Detect global fields from nav and footer partial HTML.
 *
 * Parses each partial with DOMDocument, walks the DOM using smart_forge_walk(),
 * then applies class-name heuristics to name global fields (site_logo, footer_text, etc.).
 *
 * @param array $partials  Keyed by partial name: ['nav'=>['html'=>'...'], 'footer'=>['html'=>'...'], ...]
 * @return array  Array of global field definitions:
 *                [{name, type, section, selector, default_value, label, scope:'global'}, ...]
 */
function forge_analyze_detect_globals(array $partials): array {
    $globalFields = [];

    foreach ($partials as $partialName => $partial) {
        $html = $partial['html'] ?? '';
        if (empty($html)) continue;

        // Only scan nav and footer for globals (other partials like mobile-menu are derived)
        if (!in_array($partialName, ['nav', 'footer', 'header'])) continue;

        // Wrap in valid HTML so DOMDocument can parse it
        $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML($wrappedHtml, LIBXML_NOERROR);
        libxml_clear_errors();

        // Use SmartForgeState for the DOM walk
        $state = new SmartForgeState();
        $state->originalHtml = $html;

        // Walk the DOM to detect fields
        smart_forge_walk($doc->documentElement ?? $doc, $doc, $state);

        // Convert detected fields to global scope with heuristic naming
        foreach ($state->fields as $field) {
            $globalField = forge_analyze_apply_global_heuristics($field, $partialName, $html);
            $globalField['scope'] = 'global';
            $globalFields[] = $globalField;
        }
    }

    // Deduplicate by name
    $seen = [];
    $unique = [];
    foreach ($globalFields as $gf) {
        if (!isset($seen[$gf['name']])) {
            $seen[$gf['name']] = true;
            $unique[] = $gf;
        }
    }

    return $unique;
}

/**
 * Apply class-name heuristics to rename a detected field as a global field.
 *
 * Uses CSS class names and surrounding context to assign meaningful names
 * like site_logo, site_name, footer_text, etc.
 *
 * @param array  $field        Original field from smart_forge_walk
 * @param string $partialName  Which partial this came from ('nav', 'footer')
 * @param string $html         The partial's raw HTML for context matching
 * @return array  Field with potentially renamed name and updated label
 */
function forge_analyze_apply_global_heuristics(array $field, string $partialName, string $html): array {
    $selector = strtolower($field['selector'] ?? '');
    $defaultVal = $field['default_value'] ?? '';
    $type = $field['type'] ?? 'text';
    $name = $field['name'];

    // ── Logo / Brand detection ──
    if ($type === 'image') {
        if (preg_match('/\b(brand|logo|site[-_]?logo)\b/i', $selector)) {
            $name = 'site_logo';
        } elseif ($partialName === 'nav' && preg_match('/\b(navbar[-_]?brand|header[-_]?logo)\b/i', $selector)) {
            $name = 'site_logo';
        }
    }

    // ── Site name / brand text ──
    if ($type === 'text' || $type === 'richtext') {
        if (preg_match('/\b(brand|site[-_]?name|logo[-_]?text)\b/i', $selector)) {
            $name = 'site_name';
        }

        // ── Social links ──
        if (preg_match('/\b(social)\b/i', $selector)) {
            if (preg_match('/\b(twitter|x[-_]?com)\b/i', $selector) || preg_match('/\b(twitter|x\.com)\b/i', $defaultVal)) {
                $name = 'twitter_url';
            } elseif (preg_match('/\b(facebook|fb)\b/i', $selector) || str_contains($defaultVal, 'facebook.com')) {
                $name = 'facebook_url';
            } elseif (preg_match('/\b(instagram|ig)\b/i', $selector) || str_contains($defaultVal, 'instagram.com')) {
                $name = 'instagram_url';
            } elseif (preg_match('/\b(linkedin)\b/i', $selector) || str_contains($defaultVal, 'linkedin.com')) {
                $name = 'linkedin_url';
            } elseif (preg_match('/\b(github)\b/i', $selector) || str_contains($defaultVal, 'github.com')) {
                $name = 'github_url';
            } elseif (preg_match('/\b(youtube)\b/i', $selector) || str_contains($defaultVal, 'youtube.com')) {
                $name = 'youtube_url';
            }
        }

        // ── Copyright / footer text ──
        if (preg_match('/\b(copyright|copy[-_]?right|footer[-_]?text|legal)\b/i', $selector)) {
            $name = 'footer_text';
        } elseif ($partialName === 'footer' && preg_match('/©|copyright|\d{4}/i', $defaultVal)) {
            $name = 'footer_text';
        }

        // ── Address ──
        if (preg_match('/\b(address|location)\b/i', $selector)) {
            $name = 'address';
        }

        // ── Phone ──
        if (preg_match('/\b(phone|tel|telephone)\b/i', $selector)) {
            $name = 'phone';
        } elseif (preg_match('/tel:/', $defaultVal)) {
            $name = 'phone';
        }

        // ── Email ──
        if (preg_match('/\b(email|mail|contact[-_]?email)\b/i', $selector)) {
            $name = 'contact_email';
        } elseif (preg_match('/mailto:/', $defaultVal)) {
            $name = 'contact_email';
        }
    }

    // ── Link-type social fields ──
    if ($type === 'link') {
        $href = $defaultVal;
        if (str_contains($href, 'twitter.com') || str_contains($href, 'x.com')) {
            $name = 'twitter_url';
        } elseif (str_contains($href, 'facebook.com')) {
            $name = 'facebook_url';
        } elseif (str_contains($href, 'instagram.com')) {
            $name = 'instagram_url';
        } elseif (str_contains($href, 'linkedin.com')) {
            $name = 'linkedin_url';
        } elseif (str_contains($href, 'github.com')) {
            $name = 'github_url';
        } elseif (str_contains($href, 'youtube.com')) {
            $name = 'youtube_url';
        } elseif (preg_match('/\b(social)\b/i', $selector)) {
            // Generic social link
            $name = 'social_url';
        }
    }

    $field['name'] = $name;
    $field['label'] = smart_forge_human_label($name);
    return $field;
}


// ════════════════════════════════════════════════════════════
// Phase 7: Template Rewriting
// ════════════════════════════════════════════════════════════

/**
 * Rewrite a page's original HTML into an Outpost-annotated template.
 *
 * Takes the ORIGINAL unmodified HTML and produces the final version with:
 * - <outpost-include partial="head/nav/footer"> replacing shared regions
 * - data-outpost field annotations from the scan result
 * - <!-- outpost:section --> comment wrappers
 * - <outpost-meta> and <outpost-seo> tags
 *
 * @param string $originalHtml       Full original HTML of the page
 * @param array  $partials           Extracted partials with byte offsets: {name => {html, start, end}}
 * @param array  $scanResult         Smart Forge scan result from forge_analyze_scan_page()
 * @param array  $headClassification Head classification from Phase 2
 * @param string $pagePath           Page path (e.g. '/', '/about') for meta defaults
 * @return string  Final annotated HTML template
 */
function forge_analyze_rewrite_page(string $originalHtml, array $partials, array $scanResult, array $headClassification, string $pagePath): string {
    $template = $originalHtml;

    // ── Step 1: Build replacement map (byte offset => replacement) ──
    // We process replacements from end to start to preserve offsets.
    $replacements = []; // [['start'=>int, 'end'=>int, 'replacement'=>string], ...]

    // ── Replace <head>...</head> with outpost-include + per-page styles ──
    $headStart = $headClassification['head_start'] ?? null;
    $headEnd   = $headClassification['head_end'] ?? null;

    if ($headStart !== null && $headEnd !== null) {
        // Collect per-page elements (inline styles, page-specific meta)
        $perPageHtml = '';
        foreach (($headClassification['per_page'] ?? []) as $element) {
            $perPageHtml .= '  ' . trim($element) . "\n";
        }

        // Build the replacement: outpost-include for head + per-page elements
        $headReplacement = "<outpost-include partial=\"head\">\n";
        if (!empty($perPageHtml)) {
            $headReplacement .= $perPageHtml;
        }

        // Find where <body> tag starts — everything from DOCTYPE through <body> is handled by head partial
        $bodyPos = stripos($template, '<body');
        if ($bodyPos !== false) {
            $bodyTagEnd = strpos($template, '>', $bodyPos);
            if ($bodyTagEnd !== false) {
                $replacements[] = [
                    'start'       => 0,
                    'end'         => $bodyTagEnd + 1,
                    'replacement' => $headReplacement,
                ];
            }
        }
    }

    // ── Replace nav region with outpost-include ──
    if (isset($partials['nav']) && isset($partials['nav']['start'], $partials['nav']['end'])) {
        $navStart = (int)$partials['nav']['start'];
        $navEnd   = (int)$partials['nav']['end'];

        // Detect indentation at the nav position
        $indent = forge_analyze_detect_indent($template, $navStart);

        $replacements[] = [
            'start'       => $navStart,
            'end'         => $navEnd,
            'replacement' => $indent . "<outpost-include partial=\"nav\">\n",
        ];
    }

    // ── Replace mobile menu/overlay with outpost-include (if extracted) ──
    if (isset($partials['mobile-menu']) && isset($partials['mobile-menu']['start'], $partials['mobile-menu']['end'])) {
        $mmStart = (int)$partials['mobile-menu']['start'];
        $mmEnd   = (int)$partials['mobile-menu']['end'];
        $indent  = forge_analyze_detect_indent($template, $mmStart);

        $replacements[] = [
            'start'       => $mmStart,
            'end'         => $mmEnd,
            'replacement' => $indent . "<outpost-include partial=\"mobile-menu\">\n",
        ];
    }

    // ── Replace footer region with outpost-include ──
    if (isset($partials['footer']) && isset($partials['footer']['start'], $partials['footer']['end'])) {
        $footerStart = (int)$partials['footer']['start'];
        $footerEnd   = (int)$partials['footer']['end'];
        $indent      = forge_analyze_detect_indent($template, $footerStart);

        // Footer include replaces everything from footer start through </html>
        $endOfDoc = strlen($template);
        $replacements[] = [
            'start'       => $footerStart,
            'end'         => $endOfDoc,
            'replacement' => $indent . "<outpost-include partial=\"footer\">\n",
        ];
    }

    // ── Sort replacements by start offset descending ──
    usort($replacements, fn($a, $b) => $b['start'] - $a['start']);

    // ── Apply structural replacements (back to front) ──
    foreach ($replacements as $r) {
        $template = substr($template, 0, $r['start']) . $r['replacement'] . substr($template, $r['end']);
    }

    // ── Step 2: Apply Smart Forge field annotations ──
    // Use the annotated template from the scan result as a guide,
    // but apply annotations to our structurally-rewritten template.
    $fields = $scanResult['fields'] ?? [];
    foreach ($fields as $field) {
        $name = $field['name'];
        $type = $field['type'];
        $defaultVal = $field['default_value'] ?? '';

        if ($type === 'image') {
            if ($defaultVal && str_contains($template, $defaultVal)) {
                $template = smart_forge_add_attr_to_img($template, $defaultVal, $name);
            }
        } elseif ($type === 'link') {
            if ($defaultVal) {
                $template = smart_forge_add_attr_to_link($template, $defaultVal, '', $name);
            }
        } elseif ($type === 'richtext') {
            if ($defaultVal) {
                $template = smart_forge_add_attr_to_element($template, $defaultVal, $name, 'richtext');
            }
        } elseif ($type === 'text') {
            // Skip alt text and URL sub-fields (handled with their parent)
            if (str_ends_with($name, '_alt') || str_ends_with($name, '_url')) continue;
            if ($defaultVal) {
                $template = smart_forge_add_attr_to_element($template, $defaultVal, $name, 'text');
            }
        }
    }

    // ── Step 3: Insert section comment wrappers ──
    // Parse the current template to detect sections and insert comments
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $wrappedForSections = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $template . '</body></html>';
    $doc->loadHTML($wrappedForSections, LIBXML_NOERROR);
    libxml_clear_errors();

    $sectionState = new SmartForgeState();
    smart_forge_detect_sections($doc, $sectionState);
    $template = smart_forge_insert_section_comments($template, $doc, $sectionState);

    // ── Step 4: Insert <outpost-meta> if not already present ──
    if (!str_contains($template, '<outpost-meta') && !str_contains($template, '<outpost-seo')) {
        // Derive defaults from the page path
        $titleDefault = ucwords(str_replace(['/', '-', '_'], ' ', trim($pagePath, '/'))) ?: 'Home';
        $metaTag = '  <outpost-meta title="' . htmlspecialchars($titleDefault) . '" description="">' . "\n";
        $metaTag .= '  <outpost-seo>' . "\n";

        // Insert after the head include line
        $headIncludePos = strpos($template, '<outpost-include partial="head">');
        if ($headIncludePos !== false) {
            $lineEnd = strpos($template, "\n", $headIncludePos);
            if ($lineEnd !== false) {
                $template = substr($template, 0, $lineEnd + 1) . $metaTag . substr($template, $lineEnd + 1);
            }
        }
    }

    return $template;
}


/**
 * Rewrite a partial's HTML with Outpost template tags.
 *
 * - Nav: wraps link container with <outpost-menu name="main">, adds global field attrs
 * - Footer: injects data-outpost + data-scope="global" on detected global fields
 * - Head: adds <outpost-meta> and <outpost-seo> tags
 *
 * @param string $partialHtml   Raw HTML of the partial
 * @param string $partialName   Partial name ('nav', 'footer', 'head', 'mobile-menu')
 * @param array  $globalFields  Global fields detected by forge_analyze_detect_globals()
 * @return string  Annotated partial HTML
 */
function forge_analyze_rewrite_partial(string $partialHtml, string $partialName, array $globalFields): string {
    $template = $partialHtml;

    if ($partialName === 'head') {
        return forge_analyze_rewrite_head_partial($template);
    }

    if ($partialName === 'nav') {
        $template = forge_analyze_rewrite_nav_partial($template, $globalFields);
    }

    if ($partialName === 'footer') {
        $template = forge_analyze_rewrite_footer_partial($template, $globalFields);
    }

    // Apply global field annotations for any partial
    foreach ($globalFields as $gf) {
        $name = $gf['name'];
        $type = $gf['type'];
        $defaultVal = $gf['default_value'] ?? '';

        // Skip fields already annotated
        if (str_contains($template, 'data-outpost="' . $name . '"')) continue;

        $scopeAttr = ' data-scope="global"';

        if ($type === 'image' && $defaultVal && str_contains($template, $defaultVal)) {
            // Add data-outpost + data-type="image" + data-scope="global" to matching <img>
            $escaped = preg_quote($defaultVal, '/');
            $pattern = '/(<img\b[^>]*?\bsrc=["\'])(' . $escaped . ')(["\'][^>]*?)(\/?>)/i';
            $template = preg_replace(
                $pattern,
                '$1$2$3 data-outpost="' . $name . '" data-type="image"' . $scopeAttr . '$4',
                $template,
                1
            );
        } elseif ($type === 'text' && $defaultVal) {
            // Add data-outpost + data-scope="global" to element with this text
            $escaped = preg_quote($defaultVal, '/');
            $pattern = '/(<(?:h[1-6]|p|span|div|li|td|a|small|strong|em|time|address|label)\b[^>]*?)(>)\s*' . $escaped . '/i';
            $result = preg_replace(
                $pattern,
                '$1 data-outpost="' . $name . '"' . $scopeAttr . '$2' . $defaultVal,
                $template,
                1
            );
            if ($result !== null && $result !== $template) {
                $template = $result;
            }
        }
    }

    return $template;
}


/**
 * Rewrite the head partial: DOCTYPE through <body> with Outpost meta tags.
 *
 * @param string $html  Raw head HTML (DOCTYPE through opening <body>)
 * @return string  Annotated head partial
 */
function forge_analyze_rewrite_head_partial(string $html): string {
    $template = $html;

    // Extract existing <title> content for outpost-meta default
    $titleDefault = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $template, $tm)) {
        $titleDefault = trim(strip_tags($tm[1]));
    }

    // Extract existing <meta name="description"> for outpost-meta default
    $descDefault = '';
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\'][^>]*\/?>/i', $template, $dm)) {
        $descDefault = $dm[1];
    }

    // Replace <title> with <outpost-meta>
    if ($titleDefault || $descDefault) {
        $outpostMeta = '<outpost-meta title="' . htmlspecialchars($titleDefault) . '"'
            . ' description="' . htmlspecialchars($descDefault) . '">';
        $template = preg_replace('/<title[^>]*>.*?<\/title>/is', $outpostMeta, $template, 1);

        // Remove <meta name="description"> (now handled by outpost-meta)
        $template = preg_replace('/\s*<meta\s+name=["\']description["\'][^>]*\/?>\s*/i', "\n", $template, 1);
    }

    // Add <outpost-seo> after the outpost-meta (or after charset meta if no title was found)
    if (!str_contains($template, '<outpost-seo')) {
        $seoTag = "\n    <outpost-seo>";
        if (str_contains($template, '<outpost-meta')) {
            $metaPos = strpos($template, '<outpost-meta');
            $metaEnd = strpos($template, '>', $metaPos);
            if ($metaEnd !== false) {
                $template = substr($template, 0, $metaEnd + 1) . $seoTag . substr($template, $metaEnd + 1);
            }
        } else {
            // Insert after <meta charset>
            $charsetPos = stripos($template, '<meta charset');
            if ($charsetPos !== false) {
                $charsetEnd = strpos($template, '>', $charsetPos);
                if ($charsetEnd !== false) {
                    $outpostMeta = "\n    <outpost-meta title=\"\" description=\"\">";
                    $template = substr($template, 0, $charsetEnd + 1) . $outpostMeta . $seoTag . substr($template, $charsetEnd + 1);
                }
            }
        }
    }

    return $template;
}


/**
 * Rewrite the nav partial: wrap the link list with <outpost-menu name="main">.
 *
 * @param string $html          Raw nav HTML
 * @param array  $globalFields  Global fields for logo/brand annotation
 * @return string  Annotated nav partial
 */
function forge_analyze_rewrite_nav_partial(string $html, array $globalFields): string {
    $template = $html;

    // ── Brand/logo annotation ──
    // Look for brand/logo images in the nav
    if (preg_match('/(<(?:a|div|span)\b[^>]*class=["\'][^"\']*\b(?:brand|logo|navbar-brand)[^"\']*["\'][^>]*>)/i', $template, $brandMatch, PREG_OFFSET_CAPTURE)) {
        $brandTag = $brandMatch[0][0];
        $brandPos = $brandMatch[0][1];

        // Find <img> inside the brand container
        $afterBrand = substr($template, $brandPos);
        if (preg_match('/(<img\b[^>]*)(\/?>)/i', $afterBrand, $imgMatch, PREG_OFFSET_CAPTURE)) {
            $imgTag = $imgMatch[0][0];
            $imgOffset = $brandPos + $imgMatch[0][1];

            // Only annotate if not already annotated
            if (!str_contains($imgTag, 'data-outpost=')) {
                $annotatedImg = $imgMatch[1][0] . ' data-outpost="site_logo" data-type="image" data-scope="global"' . $imgMatch[2][0];
                $template = substr($template, 0, $imgOffset) . $annotatedImg . substr($template, $imgOffset + strlen($imgTag));
            }
        }

        // Find text-based brand (not inside an img)
        $brandContainer = $brandMatch[0][0];
        if (!str_contains($afterBrand, '<img') || strpos($afterBrand, '<img') > 200) {
            // Likely a text brand — annotate the brand link/span itself
            if (!str_contains($brandTag, 'data-outpost=')) {
                $annotatedBrand = preg_replace('/(>)$/', ' data-outpost="site_name" data-scope="global">',  $brandTag, 1);
                $template = substr($template, 0, $brandPos) . $annotatedBrand . substr($template, $brandPos + strlen($brandTag));
            }
        }
    }

    // ── Wrap navigation links with <outpost-menu> ──
    // Find the primary <ul> or <ol> inside <nav>
    $navPos = stripos($template, '<nav');
    if ($navPos !== false) {
        // Find the first <ul> inside the nav
        $afterNav = substr($template, $navPos);
        if (preg_match('/<ul\b[^>]*>/i', $afterNav, $ulMatch, PREG_OFFSET_CAPTURE)) {
            $ulStart = $navPos + $ulMatch[0][1];

            // Find the matching </ul>
            $ulContent = substr($template, $ulStart);
            $depth = 0;
            $ulEndOffset = null;
            $searchPos = 0;

            while (preg_match('/<(\/?)ul\b[^>]*>/i', $ulContent, $tagMatch, PREG_OFFSET_CAPTURE, $searchPos)) {
                $isClosing = $tagMatch[1][0] === '/';
                if ($isClosing) {
                    $depth--;
                    if ($depth < 0) {
                        $ulEndOffset = $tagMatch[0][1] + strlen($tagMatch[0][0]);
                        break;
                    }
                } else {
                    $depth++;
                }
                $searchPos = $tagMatch[0][1] + strlen($tagMatch[0][0]);
            }

            if ($ulEndOffset !== null) {
                $ulEnd = $ulStart + $ulEndOffset;
                $indent = forge_analyze_detect_indent($template, $ulStart);

                // Extract the <ul>...</ul> content
                $ulHtml = substr($template, $ulStart, $ulEnd - $ulStart);

                // Extract individual <li><a> items and build the menu template
                // Wrap with <outpost-menu name="main">
                $menuOpen  = $indent . '<outpost-menu name="main">' . "\n";
                $menuClose = "\n" . $indent . '</outpost-menu>';

                // Replace <ul>...</ul> with <outpost-menu>..items..</outpost-menu>
                // Extract <a> tags from list items for the menu template
                $menuItems = '';
                if (preg_match_all('/<li[^>]*>\s*(<a\b[^>]*>.*?<\/a>)\s*<\/li>/is', $ulHtml, $liMatches)) {
                    // Use the first <a> as the template
                    $firstLink = $liMatches[1][0] ?? '';
                    if ($firstLink) {
                        // Add data-bind="href:url" and data-outpost="label" to the template link
                        $menuLink = preg_replace('/(<a\b[^>]*?)(\bhref=["\'][^"\']*["\'])([^>]*>)/i', '$1data-bind="href:url" $2$3', $firstLink, 1);
                        // Add data-outpost="label" to the link text
                        $menuLink = preg_replace('/(>)([^<]+)(<\/a>)/i', '>$2$3', $menuLink);
                        $menuLink = preg_replace('/(<a\b[^>]*)(>)/i', '$1 data-outpost="label"$2', $menuLink, 1);

                        $menuItems = $indent . '  ' . $menuLink;
                    }
                }

                if ($menuItems) {
                    $replacement = $menuOpen . $menuItems . $menuClose;
                    $template = substr($template, 0, $ulStart) . $replacement . substr($template, $ulEnd);
                }
            }
        }
    }

    return $template;
}


/**
 * Rewrite the footer partial: inject global field annotations.
 *
 * @param string $html          Raw footer HTML
 * @param array  $globalFields  Detected global fields
 * @return string  Annotated footer partial with </body></html> closing
 */
function forge_analyze_rewrite_footer_partial(string $html, array $globalFields): string {
    $template = $html;

    // Ensure footer partial ends with </body></html>
    if (!preg_match('/<\/body>/i', $template)) {
        $template = rtrim($template) . "\n</body>\n</html>\n";
    }

    // Global field annotations are handled by the caller (forge_analyze_rewrite_partial)
    // via the global fields loop. Here we just ensure structural correctness.

    return $template;
}


/**
 * Detect the indentation at a given byte offset in the template.
 *
 * @param string $html    Full HTML string
 * @param int    $offset  Byte offset to check
 * @return string  Indentation string (spaces/tabs at start of that line)
 */
function forge_analyze_detect_indent(string $html, int $offset): string {
    // Walk backwards from offset to find the start of the line
    $lineStart = $offset;
    while ($lineStart > 0 && $html[$lineStart - 1] !== "\n") {
        $lineStart--;
    }
    $lineContent = substr($html, $lineStart, $offset - $lineStart);
    if (preg_match('/^([\s\t]*)/', $lineContent, $m)) {
        return $m[1];
    }
    return '';
}


// ════════════════════════════════════════════════════════════
// Phase 8: Database Registration
// ════════════════════════════════════════════════════════════

/**
 * Register all pages, fields, globals, and menus in the Outpost database.
 *
 * Follows the exact patterns from outpost_scan_site_templates() in engine.php
 * and handle_forge_apply() in smart-forge.php.
 *
 * @param array $pages        Array of pages: [{path, title, fields:[{name,type,default_value,section,selector,sort_order}]}]
 * @param array $globalFields Array of global field defs: [{name, type, default_value, label}]
 * @param array $menus        Array of menus: [{name, slug, items:[{label,url}]}]
 * @return array  Summary: {pages_registered, fields_registered, globals_registered, menus_registered}
 */
function forge_analyze_register_all(array $pages, array $globalFields, array $menus): array {
    $summary = [
        'pages_registered'   => 0,
        'fields_registered'  => 0,
        'globals_registered' => 0,
        'menus_registered'   => 0,
    ];

    $fieldTheme = ''; // v5: no theme layer

    // ── Register pages and their fields ──
    foreach ($pages as $pageInfo) {
        $path  = $pageInfo['path'] ?? '';
        $title = $pageInfo['title'] ?? '';
        if (!$path) continue;

        // Create page entry (or find existing)
        $existing = OutpostDB::fetchOne('SELECT id FROM pages WHERE path = ?', [$path]);
        if ($existing) {
            $pageId = (int)$existing['id'];
        } else {
            $pageId = OutpostDB::insert('pages', [
                'path'  => $path,
                'title' => $title ?: ucwords(str_replace(['/', '-', '_'], ' ', trim($path, '/'))) ?: 'Home',
            ]);
        }
        $summary['pages_registered']++;

        // Register fields for this page
        $fields = $pageInfo['fields'] ?? [];
        $sortOrder = 0;
        foreach ($fields as $field) {
            $fieldName    = $field['name'] ?? '';
            $fieldType    = $field['type'] ?? 'text';
            $defaultValue = $field['default_value'] ?? '';
            $selector     = $field['selector'] ?? null;
            $sectionName  = $field['section'] ?? null;
            $sortOrder++;

            if (!$fieldName) continue;

            // Check if field already exists for this page
            $existingField = OutpostDB::fetchOne(
                "SELECT id FROM fields WHERE page_id = ? AND theme = ? AND field_name = ?",
                [$pageId, $fieldTheme, $fieldName]
            );

            if ($existingField) {
                // Update existing field (preserve user content)
                OutpostDB::query(
                    "UPDATE fields SET css_selector = ?, section_name = ?, field_type = ?, default_value = ?, sort_order = ? WHERE id = ?",
                    [$selector, $sectionName, $fieldType, $defaultValue, $sortOrder, $existingField['id']]
                );
            } else {
                // Insert new field with default value as initial content
                OutpostDB::query(
                    "INSERT INTO fields (page_id, field_name, content, field_type, theme, default_value, css_selector, section_name, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$pageId, $fieldName, $defaultValue, $fieldType, $fieldTheme, $defaultValue, $selector, $sectionName, $sortOrder]
                );
            }

            // Register in page_field_registry
            OutpostDB::query(
                "INSERT INTO page_field_registry (theme, path, field_name, field_type, default_value, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(theme, path, field_name) DO UPDATE SET
                 field_type = excluded.field_type, default_value = excluded.default_value, sort_order = excluded.sort_order",
                [$fieldTheme, $path, $fieldName, $fieldType, $defaultValue, $sortOrder]
            );

            $summary['fields_registered']++;
        }
    }

    // ── Register global fields ──
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalPage) {
        $globalPageId = OutpostDB::insert('pages', ['path' => '__global__', 'title' => 'Global Fields']);
    } else {
        $globalPageId = (int)$globalPage['id'];
    }

    $gOrder = 0;
    foreach ($globalFields as $gf) {
        $name         = $gf['name'] ?? '';
        $type         = $gf['type'] ?? 'text';
        $defaultValue = $gf['default_value'] ?? '';

        if (!$name) continue;
        $gOrder++;

        // Insert or update global field (preserve existing content)
        OutpostDB::query(
            "INSERT INTO fields (page_id, theme, field_name, field_type, content, default_value, sort_order)
             VALUES (?, '', ?, ?, ?, ?, ?)
             ON CONFLICT(page_id, theme, field_name) DO UPDATE SET
               field_type    = excluded.field_type,
               content       = CASE WHEN content = '' AND excluded.content != '' THEN excluded.content ELSE content END,
               default_value = excluded.default_value,
               sort_order    = excluded.sort_order",
            [$globalPageId, $name, $type, $defaultValue, $defaultValue, $gOrder]
        );

        // Register in page_field_registry
        OutpostDB::query(
            "INSERT INTO page_field_registry (theme, path, field_name, field_type, default_value, sort_order)
             VALUES (?, '__global__', ?, ?, ?, ?)
             ON CONFLICT(theme, path, field_name) DO UPDATE SET
             field_type = excluded.field_type, default_value = excluded.default_value, sort_order = excluded.sort_order",
            [$fieldTheme, $name, $type, $defaultValue, $gOrder]
        );

        $summary['globals_registered']++;
    }

    // ── Register menus ──
    foreach ($menus as $menu) {
        $menuName = $menu['name'] ?? '';
        $menuSlug = $menu['slug'] ?? '';
        $items    = $menu['items'] ?? [];

        if (!$menuName || !$menuSlug) continue;

        // Validate slug format
        $menuSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($menuSlug));
        if (!$menuSlug) continue;

        OutpostDB::query(
            "INSERT OR IGNORE INTO menus (name, slug, items) VALUES (?, ?, ?)",
            [$menuName, $menuSlug, json_encode($items, JSON_UNESCAPED_UNICODE)]
        );

        $summary['menus_registered']++;
    }

    // ── Clear template cache ──
    if (defined('OUTPOST_CACHE_DIR')) {
        $cacheDir = OUTPOST_CACHE_DIR . 'templates/';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*.php') as $f) {
                @unlink($f);
            }
        }
    }

    // ── Rebuild site manifest ──
    require_once __DIR__ . '/theme-manifest.php';
    if (function_exists('outpost_build_site_manifest')) {
        outpost_build_site_manifest();
    }

    return $summary;
}


// ════════════════════════════════════════════════════════════
// Backup Function
// ════════════════════════════════════════════════════════════

/**
 * Back up original files before Forge rewrites them.
 *
 * Creates a timestamped backup directory under .forge-backup/ at the site root,
 * preserving relative paths for each file.
 *
 * @param string $siteRoot   Absolute path to the site root directory
 * @param array  $filePaths  Array of absolute file paths to back up
 * @return string  Path to the backup directory
 */
function forge_analyze_backup(string $siteRoot, array $filePaths): string {
    $timestamp = date('Y-m-d_His');
    $backupDir = rtrim($siteRoot, '/') . '/.forge-backup/' . $timestamp . '/';

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $siteRoot = rtrim($siteRoot, '/') . '/';

    foreach ($filePaths as $filePath) {
        if (!file_exists($filePath)) continue;

        // Determine relative path from site root
        $relativePath = str_starts_with($filePath, $siteRoot)
            ? substr($filePath, strlen($siteRoot))
            : basename($filePath);

        $backupPath = $backupDir . $relativePath;
        $backupSubDir = dirname($backupPath);

        if (!is_dir($backupSubDir)) {
            mkdir($backupSubDir, 0755, true);
        }

        copy($filePath, $backupPath);
    }

    return $backupDir;
}


// ════════════════════════════════════════════════════════════
// Main Orchestrator
// ════════════════════════════════════════════════════════════

/**
 * Analyze a multi-page site and produce a preview of the Forge conversion.
 *
 * Runs Phases 1–6 (discovery, head classification, partial extraction, deduplication,
 * per-page scan, global detection) and returns a preview without writing any files.
 *
 * @param string $siteRoot  Absolute path to the site root directory
 * @return array  Analysis result:
 *   {
 *     pages: [{path, title, filename, fields:[], sections:[], original_size}],
 *     partials: [{name, html, source_pages:[]}],
 *     global_fields: [{name, type, default_value, label, scope:'global'}],
 *     menus: [{name, slug, items:[]}],
 *     summary: {total_pages, total_fields, total_globals, total_partials}
 *   }
 */
function forge_analyze_site(string $siteRoot): array {
    $siteRoot = rtrim($siteRoot, '/') . '/';

    // ── Phase 1: Discover HTML files ──
    // Discovery uses OUTPOST_SITE_ROOT internally (no parameter needed)
    $discovery = forge_analyze_discover();
    $htmlFiles = $discovery['files'] ?? [];
    if (empty($htmlFiles)) {
        return [
            'pages'         => [],
            'partials'      => [],
            'global_fields' => [],
            'menus'         => [],
            'summary'       => ['total_pages' => 0, 'total_fields' => 0, 'total_globals' => 0, 'total_partials' => 0],
        ];
    }

    // ── Phase 2: Classify <head> content across pages ──
    $headClassification = forge_analyze_classify_head($htmlFiles);

    // ── Phase 3: Extract partials (nav, footer, mobile-menu) ──
    $extractedPartials = forge_analyze_extract_partials($htmlFiles);

    // ── Phase 4: Use extracted partials directly (keyed by name) ──
    $partials = [];

    // Add head partial from classification
    if (!empty($headClassification['shared_html'])) {
        $partials['head'] = [
            'html'        => $headClassification['shared_html'],
            'match_count' => count($htmlFiles),
        ];
    }

    foreach (['nav', 'footer', 'mobile_menu'] as $pKey) {
        if (!empty($extractedPartials[$pKey])) {
            $name = str_replace('_', '-', $pKey); // mobile_menu -> mobile-menu
            $partials[$name] = $extractedPartials[$pKey];
        }
    }

    // ── Phase 5: Scan each page for fields ──
    $pages = [];
    foreach ($htmlFiles as $fileData) {
        $filePath = $fileData['path'];  // relative filename e.g. "about.html"
        $originalHtml = $fileData['html'];
        $filename = basename($filePath, '.html');
        $path = $filename === 'index' ? '/' : '/' . $filename;
        $fullFilePath = rtrim($siteRoot, '/') . '/' . $filePath;

        // Build per-file partial offsets from the extracted partials
        $filePartials = [];
        foreach ($partials as $pName => $pData) {
            // Find this partial's position in the current file
            $partialHtml = $pData['html'] ?? '';
            if ($partialHtml && str_contains($originalHtml, $partialHtml)) {
                $pos = strpos($originalHtml, $partialHtml);
                $filePartials[$pName] = [
                    'html'  => $partialHtml,
                    'start' => $pos,
                    'end'   => $pos + strlen($partialHtml),
                ];
            }
        }

        // Get per-file head classification
        $fileHeadClass = $headClassification;

        // Run Smart Forge scan on the stripped page body
        $scanResult = forge_analyze_scan_page($originalHtml, $filePartials, $fileHeadClass, $filename);

        // Clean page name from filename (for display)
        $title = ucwords(str_replace(['-', '_'], ' ', $filename));
        if ($filename === 'index') $title = 'Home';

        $pages[] = [
            'path'          => $path,
            'title'         => $title,
            'filename'      => $filePath,
            'file'          => $fullFilePath,
            'fields'        => $scanResult['fields'] ?? [],
            'sections'      => $scanResult['sections'] ?? [],
            'scan_result'   => $scanResult,
            'head_class'    => $fileHeadClass,
            'file_partials' => $filePartials,
            'original_size' => strlen($originalHtml),
        ];
    }

    // ── Phase 6: Detect global fields from partials ──
    $globalFields = forge_analyze_detect_globals($partials);

    // ── Detect menus from nav partial ──
    $menus = [];
    if (isset($partials['nav'])) {
        $navHtml = $partials['nav']['html'] ?? '';
        $menuItems = forge_analyze_extract_menu_items($navHtml);
        if (!empty($menuItems)) {
            $menus[] = [
                'name'  => 'Main Navigation',
                'slug'  => 'main',
                'items' => $menuItems,
            ];
        }
    }
    // Check for footer menu (uses footer-specific extractor that handles div.footer-links etc.)
    if (isset($partials['footer'])) {
        $footerHtml = $partials['footer']['html'] ?? '';
        $footerMenuItems = forge_analyze_extract_footer_menu($footerHtml);
        if (!empty($footerMenuItems)) {
            $menus[] = [
                'name'  => 'Footer Navigation',
                'slug'  => 'footer',
                'items' => $footerMenuItems,
            ];
        }
    }

    // ── Build summary ──
    $totalFields = 0;
    foreach ($pages as $p) {
        $totalFields += count($p['fields']);
    }

    // Build clean partial list for preview (without internal data)
    $partialsList = [];
    foreach ($partials as $name => $pData) {
        $partialsList[] = [
            'name'         => $name,
            'html'         => $pData['html'] ?? '',
            'source_pages' => $pData['source_pages'] ?? [],
            'size'         => strlen($pData['html'] ?? ''),
        ];
    }

    return [
        'pages'         => $pages,
        'partials'      => $partialsList,
        'global_fields' => $globalFields,
        'menus'         => $menus,
        'summary'       => [
            'total_pages'    => count($pages),
            'total_fields'   => $totalFields,
            'total_globals'  => count($globalFields),
            'total_partials' => count($partialsList),
        ],
    ];
}


/**
 * Extract navigation menu items from an HTML string.
 *
 * Parses <a> tags within <nav> or <ul> structures and returns an array of
 * menu items with label and url.
 *
 * @param string $html  HTML containing navigation links
 * @return array  Array of [{label, url}]
 */
// forge_analyze_extract_menu_items() defined above in Phase 4


// ════════════════════════════════════════════════════════════
// API Handlers
// ════════════════════════════════════════════════════════════

/**
 * API handler: Analyze a site and return a preview.
 *
 * Called via POST api.php?action=forge/analyze
 * Returns a JSON preview of what the Forge will do, without writing any files.
 *
 * @return void  Outputs JSON response
 */
function handle_forge_analyze(): void {
    // Always use the configured site root — no user-supplied path override.
    // Allowing arbitrary paths would enable reading any directory on the server.
    $siteRoot = OUTPOST_SITE_ROOT;

    try {
        $result = forge_analyze_site($siteRoot);
        json_response($result);
    } catch (\Throwable $e) {
        json_error('Forge analysis failed: ' . $e->getMessage(), 500);
    }
}


/**
 * API handler: Apply Forge analysis — rewrite templates + register in DB.
 *
 * Called via POST api.php?action=forge/analyze/apply
 * Expects JSON body with the preview data + options:
 *   {
 *     pages: [...],        // from forge_analyze_site() preview
 *     partials: [...],     // from preview
 *     global_fields: [...],// from preview
 *     menus: [...],        // from preview
 *     options: {
 *       backup: true,       // create backup before writing (default: true)
 *       write_templates: true,  // write rewritten .html files (default: true)
 *       register_db: true,      // register fields/pages in DB (default: true)
 *       create_partials_dir: true  // create partials/ directory (default: true)
 *     }
 *   }
 *
 * @return void  Outputs JSON response with summary
 */
function handle_forge_analyze_apply(): void {
    $body = get_json_body();

    $pages        = $body['pages'] ?? [];
    $partials     = $body['partials'] ?? [];
    $globalFields = $body['global_fields'] ?? [];
    $menus        = $body['menus'] ?? [];
    $options      = $body['options'] ?? [];

    // Defaults
    $doBackup     = $options['backup'] ?? true;
    $doWrite      = $options['write_templates'] ?? true;
    $doRegister   = $options['register_db'] ?? true;
    $doPartials   = $options['create_partials_dir'] ?? true;

    $siteRoot = OUTPOST_SITE_ROOT;
    $siteRoot = rtrim($siteRoot, '/') . '/';

    // Security: validate all file paths are within the site root.
    // Client-supplied 'file' fields could be manipulated to read/write arbitrary paths.
    foreach ($pages as $page) {
        $file = $page['file'] ?? '';
        if (!$file) continue;
        $realFile = realpath($file);
        $realRoot = realpath(rtrim($siteRoot, '/'));
        if (!$realFile || !$realRoot || !str_starts_with($realFile, $realRoot . '/')) {
            json_error('Invalid file path: path is outside site root', 403);
            return;
        }
        // Block paths inside the outpost directory (admin, data, config, etc.)
        $outpostDir = defined('OUTPOST_DIR') ? realpath(OUTPOST_DIR) : realpath($siteRoot . 'outpost');
        if ($outpostDir && str_starts_with($realFile, $outpostDir . '/')) {
            json_error('Invalid file path: cannot modify files inside outpost directory', 403);
            return;
        }
    }

    $result = [
        'backup_path'      => null,
        'files_written'    => [],
        'partials_written' => [],
        'db_summary'       => null,
        'errors'           => [],
    ];

    try {
        // ── Backup originals ──
        if ($doBackup) {
            $filePaths = [];
            foreach ($pages as $page) {
                $file = $page['file'] ?? '';
                if ($file && file_exists($file)) {
                    $filePaths[] = $file;
                }
            }
            if (!empty($filePaths)) {
                $result['backup_path'] = forge_analyze_backup($siteRoot, $filePaths);
            }
        }

        // ── Create partials directory ──
        $partialsDir = $siteRoot . 'partials/';
        if ($doPartials && !is_dir($partialsDir)) {
            mkdir($partialsDir, 0755, true);
        }

        // ── Build partials lookup (name => data) ──
        $partialsMap = [];
        foreach ($partials as $p) {
            $name = $p['name'] ?? '';
            if ($name) {
                $partialsMap[$name] = $p;
            }
        }

        // ── Phase 7: Rewrite and write partials ──
        if ($doWrite) {
            foreach ($partialsMap as $name => $pData) {
                $partialHtml = $pData['html'] ?? '';
                if (!$partialHtml) continue;

                // Security: sanitize partial name to prevent directory traversal
                // Only allow alphanumeric, hyphens, and underscores
                $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
                if (!$name) {
                    $result['errors'][] = "Invalid partial name — skipped";
                    continue;
                }

                // Rewrite the partial with Outpost annotations
                $rewrittenPartial = forge_analyze_rewrite_partial($partialHtml, $name, $globalFields);

                // Security: strip PHP tags from partial content
                $rewrittenPartial = preg_replace('/<\?(?:php|=)?/i', '&lt;?', $rewrittenPartial);

                // Write to partials directory
                $partialPath = $partialsDir . $name . '.html';
                $written = file_put_contents($partialPath, $rewrittenPartial);
                if ($written !== false) {
                    $result['partials_written'][] = $partialPath;
                } else {
                    $result['errors'][] = "Failed to write partial: {$name}.html";
                }
            }

            // ── Phase 7: Rewrite and write page templates ──
            foreach ($pages as $page) {
                $file = $page['file'] ?? '';
                if (!$file) continue;

                $originalHtml    = file_get_contents($file);
                $scanResult      = $page['scan_result'] ?? [];
                $headClass       = $page['head_class'] ?? [];
                $filePartials    = $page['file_partials'] ?? [];
                $pagePath        = $page['path'] ?? '/';

                // Rewrite the page template
                $rewrittenPage = forge_analyze_rewrite_page(
                    $originalHtml,
                    $filePartials,
                    $scanResult,
                    $headClass,
                    $pagePath
                );

                // Security: strip any PHP tags that could have been injected through
                // the HTML source files. The template engine compiles .html to .php,
                // so any <?php tags would execute as server-side code.
                $rewrittenPage = preg_replace('/<\?(?:php|=)?/i', '&lt;?', $rewrittenPage);

                // Write the rewritten template
                $written = file_put_contents($file, $rewrittenPage);
                if ($written !== false) {
                    $result['files_written'][] = $file;
                } else {
                    $result['errors'][] = "Failed to write page: " . basename($file);
                }
            }
        }

        // ── Phase 8: Database registration ──
        if ($doRegister) {
            // Prepare page data for registration
            $dbPages = [];
            foreach ($pages as $page) {
                $dbPages[] = [
                    'path'   => $page['path'] ?? '',
                    'title'  => $page['title'] ?? '',
                    'fields' => $page['fields'] ?? [],
                ];
            }

            $result['db_summary'] = forge_analyze_register_all($dbPages, $globalFields, $menus);
        }

        json_response($result);

    } catch (\Throwable $e) {
        $result['errors'][] = $e->getMessage();
        json_response($result, 500);
    }
}
