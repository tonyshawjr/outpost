<?php
/**
 * Outpost CMS — Smart Forge Scan Engine
 *
 * One-click conversion: raw HTML → fully editable Outpost page.
 * Scans an HTML file, auto-detects every content element, converts them
 * to v2 data-attribute annotations, and generates a field-to-selector CSS map.
 *
 * v2 output uses data-outpost="field" + data-type="type" attributes and
 * <!-- outpost:sectionname --> / <!-- /outpost:sectionname --> comment wrappers.
 */

// ── Main Entry Point ────────────────────────────────────

/**
 * Scan an HTML string and auto-detect all content elements.
 *
 * @param string $htmlContent  Raw HTML to scan
 * @param string $filename     Original filename (for context)
 * @return array  Scan result with template, fields, sections, field_map, repeaters, suggested_partials
 */
function smart_forge_scan(string $htmlContent, string $filename = ''): array {
    $state = new SmartForgeState();
    $state->originalHtml = $htmlContent;

    // ── Parse front matter / section comments BEFORE stripping ──
    // v1: Templates with {#--- ... ---#} blocks already define their fields
    // v2: Templates with <!-- outpost:section --> comments define sections
    $frontMatterFields = smart_forge_parse_front_matter($htmlContent);
    if (!empty($frontMatterFields)) {
        $state->frontMatterFields = $frontMatterFields;
        // Mark front matter field names as already used
        foreach ($frontMatterFields as $fm) {
            $state->usedNames[$fm['name']] = 1;
        }
    }

    // ── Detect which line ranges already have Liquid tags or v2 data-outpost attributes ──
    // Before stripping, record which elements in the original HTML are already forged
    $state->liquidRanges = smart_forge_detect_forged_ranges($htmlContent);

    // Strip existing Liquid/Outpost tags before parsing — Smart Forge works on the raw content
    // Replace {% ... %} blocks (includes, loops, conditionals) with placeholder comments
    $cleanHtml = preg_replace('/\{%.*?%\}/s', '', $htmlContent);
    // Replace {{ ... }} output tags with their default/fallback content or empty
    $cleanHtml = preg_replace('/\{\{.*?\}\}/s', '', $cleanHtml);
    // Replace {# ... #} comments
    $cleanHtml = preg_replace('/\{#.*?#\}/s', '', $cleanHtml);
    // Strip v2 data-outpost and data-type attributes (so DOM parser sees clean HTML)
    $cleanHtml = preg_replace('/\s+data-outpost="[^"]*"/', '', $cleanHtml);
    $cleanHtml = preg_replace('/\s+data-type="[^"]*"/', '', $cleanHtml);
    // Strip v2 section comment wrappers
    $cleanHtml = preg_replace('/<!--\s*\/?outpost:[a-z0-9_-]+\s*-->/i', '', $cleanHtml);

    // Parse with DOMDocument
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    // Wrap in proper HTML structure — the original may have head/body in partials that got stripped
    $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $cleanHtml . '</body></html>';
    $doc->loadHTML($wrappedHtml, LIBXML_NOERROR);
    libxml_clear_errors();

    // Remove the XML processing instruction we added
    foreach ($doc->childNodes as $child) {
        if ($child->nodeType === XML_PI_NODE) {
            $doc->removeChild($child);
            break;
        }
    }

    // First pass: detect sections
    smart_forge_detect_sections($doc, $state);

    // Repeater detection disabled — it was too aggressive, eating individual fields.
    // Individual field detection is more valuable for the frontend editor.
    // Repeaters can be manually created via the Forge right-click menu.
    // smart_forge_detect_repeaters($doc, $state);

    // Third pass: walk DOM and detect fields
    smart_forge_walk($doc->documentElement ?? $doc, $doc, $state);

    // Detect meta tags
    smart_forge_detect_meta($doc, $state);

    // Detect suggested partials
    smart_forge_detect_partials($doc, $state);

    // Generate the Liquid template
    $template = smart_forge_generate_template($htmlContent, $doc, $state);

    // Build field map
    $fieldMap = [];
    foreach ($state->fields as $f) {
        if (!empty($f['selector'])) {
            $fieldMap[$f['name']] = $f['selector'];
        }
    }

    return [
        'template' => $template,
        'fields' => $state->fields,
        'sections' => array_values(array_unique($state->sections)),
        'field_map' => $fieldMap,
        'repeaters' => $state->repeaters,
        'suggested_partials' => $state->suggestedPartials,
    ];
}


// ── State Container ─────────────────────────────────────

class SmartForgeState {
    /** @var array<array{name:string,type:string,section:string,selector:string,default_value:string,label:string}> */
    public array $fields = [];

    /** @var string[] */
    public array $sections = [];

    /** @var array<array{name:string,section:string,selector:string,sub_fields:array}> */
    public array $repeaters = [];

    /** @var array<array{name:string,selector:string,start_line:int,end_line:int}> */
    public array $suggestedPartials = [];

    /** @var array<string,int> Track used field names for uniqueness */
    public array $usedNames = [];

    /** @var array<string,string> Map DOMNode paths → section names */
    public array $sectionMap = [];

    /** @var \SplObjectStorage Nodes already handled by repeater detection */
    public \SplObjectStorage $repeaterNodes;

    /** @var array Meta fields detected */
    public array $metaFields = [];

    /** @var array<string,array> Map node path → replacement info for template generation */
    public array $replacements = [];

    /** @var array Front matter field definitions parsed from {#--- ... ---#} or data-outpost attrs */
    public array $frontMatterFields = [];

    /** @var array<int,int> Line numbers in original HTML that already contain forged syntax (v1 Liquid or v2 data-outpost) */
    public array $liquidRanges = [];

    /** @var string Original HTML content (before stripping) for content-based forged detection */
    public string $originalHtml = '';

    public function __construct() {
        $this->repeaterNodes = new \SplObjectStorage();
    }

    /**
     * Generate a unique field name.
     */
    public function uniqueName(string $base): string {
        $base = smart_forge_sanitize_name($base);
        if ($base === '') $base = 'field';

        if (!isset($this->usedNames[$base])) {
            $this->usedNames[$base] = 1;
            return $base;
        }

        $this->usedNames[$base]++;
        $name = $base . '_' . $this->usedNames[$base];
        // Ensure even the numbered version is unique
        while (isset($this->usedNames[$name])) {
            $this->usedNames[$base]++;
            $name = $base . '_' . $this->usedNames[$base];
        }
        $this->usedNames[$name] = 1;
        return $name;
    }

    /**
     * Add a field to the detected list, with a human-readable label.
     */
    public function addField(string $name, string $type, string $section, string $selector, string $defaultValue, ?DOMNode $node = null): void {
        $this->fields[] = [
            'name' => $name,
            'type' => $type,
            'section' => $section,
            'selector' => $selector,
            'default_value' => $defaultValue,
            'label' => smart_forge_human_label($name),
        ];
        if ($node !== null) {
            $path = smart_forge_node_path($node);
            $this->replacements[$path] = [
                'name' => $name,
                'type' => $type,
                'node' => $node,
            ];
        }
    }
}


// ── Name Sanitization ───────────────────────────────────

function smart_forge_sanitize_name(string $raw): string {
    // Lowercase
    $name = strtolower(trim($raw));
    // Replace hyphens, spaces, dots with underscores
    $name = preg_replace('/[\-\s\.]+/', '_', $name);
    // Remove special chars (keep letters, numbers, underscores)
    $name = preg_replace('/[^a-z0-9_]/', '', $name);
    // Remove leading numbers/underscores
    $name = ltrim($name, '0123456789_');
    // Collapse multiple underscores
    $name = preg_replace('/_+/', '_', $name);
    // Trim trailing underscores
    $name = rtrim($name, '_');
    // Truncate to reasonable length
    if (strlen($name) > 40) $name = substr($name, 0, 40);
    return $name;
}


// ── Human-Readable Labels ───────────────────────────────

/**
 * Convert a snake_case field name to a human-readable label.
 * hero_heading → Hero Heading, cta_url → CTA URL
 */
function smart_forge_human_label(string $fieldName): string {
    $label = str_replace('_', ' ', $fieldName);
    $label = ucwords(trim($label));
    // Fix common abbreviations
    $label = str_replace(
        ['Cta', 'Url', 'Bg', 'Img', ' Id', ' Seo'],
        ['CTA', 'URL', 'Background', 'Image', ' ID', ' SEO'],
        $label
    );
    return $label;
}


// ── Front Matter / Section Comment Parsing ──────────────

/**
 * Parse field definitions from either:
 *  - v1: {#--- ... ---#} front matter block
 *  - v2: data-outpost="name" attributes already in the HTML
 * Returns an array of field definitions with name, type, and global flag.
 */
function smart_forge_parse_front_matter(string $html): array {
    // v1: Try {#--- ... ---#} front matter block
    if (preg_match('/\{#---(.+?)---#\}/s', $html, $m)) {
        return smart_forge_parse_v1_front_matter($m[1]);
    }

    // v2: Parse data-outpost attributes from existing HTML
    $fields = [];
    if (preg_match_all('/data-outpost="([^"]+)"(?:\s+data-type="([^"]+)")?/', $html, $matches, PREG_SET_ORDER)) {
        $seen = [];
        foreach ($matches as $match) {
            $name = $match[1];
            $type = $match[2] ?? 'text';
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $fields[] = [
                'name' => $name,
                'type' => $type,
                'global' => str_starts_with($name, '@'),
            ];
        }
    }

    return $fields;
}

/**
 * Parse the v1 {#--- ... ---#} front matter content.
 */
function smart_forge_parse_v1_front_matter(string $content): array {
    $fields = [];
    $lines = explode("\n", $content);
    $inPageFields = false;
    $inGlobalFields = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Section headers
        if (str_starts_with($trimmed, 'Page fields:'))   { $inPageFields = true;  $inGlobalFields = false; continue; }
        if (str_starts_with($trimmed, 'Global fields:')) { $inGlobalFields = true; $inPageFields = false;  continue; }
        if (str_starts_with($trimmed, 'Loops:') ||
            str_starts_with($trimmed, 'Features') ||
            str_starts_with($trimmed, 'Template:') ||
            str_starts_with($trimmed, 'Route:') ||
            str_starts_with($trimmed, 'Title:')) {
            $inPageFields = false;
            $inGlobalFields = false;
            continue;
        }

        // Skip lines that are template examples (contain {{ }})
        if (str_contains($trimmed, '{{')) continue;

        // Parse field definitions:  @?field_name  type  — description
        if (($inPageFields || $inGlobalFields) && preg_match('/^\s*(@?\w+)\s+(\w+)/', $trimmed, $fm)) {
            $name = ltrim($fm[1], '@');
            $fields[] = [
                'name' => $name,
                'type' => $fm[2],
                'global' => $inGlobalFields || str_starts_with($fm[1], '@'),
            ];
        }
    }

    return $fields;
}


// ── Forged Range Detection ──────────────────────────────

/**
 * Detect which lines in the original HTML already contain forged syntax.
 * Checks for v1 Liquid tags ({{ }}, {% %}) AND v2 data-outpost attributes.
 * Returns an array of line numbers (1-based) that are already forged.
 */
function smart_forge_detect_forged_ranges(string $html): array {
    $lines = explode("\n", $html);
    $forgedLines = [];

    foreach ($lines as $i => $line) {
        $lineNum = $i + 1;
        // v1: Liquid tags
        if (preg_match('/\{\{.*?\}\}|\{%.*?%\}/', $line)) {
            $forgedLines[$lineNum] = true;
        }
        // v2: data-outpost attributes
        if (preg_match('/data-outpost="[^"]*"/', $line)) {
            $forgedLines[$lineNum] = true;
        }
    }

    return $forgedLines;
}

/**
 * Check if a DOM element's line number falls within an already-forged range.
 */
function smart_forge_is_already_forged(DOMNode $node, SmartForgeState $state): bool {
    if (empty($state->liquidRanges)) return false;

    $lineNo = $node->getLineNo();
    if ($lineNo <= 0) return false;

    // Check this line and a few surrounding lines (DOMDocument line numbers can be approximate)
    return isset($state->liquidRanges[$lineNo]);
}


// ── Content Quality Filter ──────────────────────────────

/**
 * Determine if a text node contains content worth making editable.
 * Filters out UI chrome, garbage text, navigation labels, and boilerplate.
 */
function smart_forge_is_worth_editing(DOMElement $node, string $text): bool {
    $text = trim($text);

    // Too short (1 char) — likely a symbol
    if (mb_strlen($text) < 2) return false;

    // Pure URLs
    if (preg_match('#^https?://#', $text)) return false;
    if (preg_match('#^/[a-z0-9\-/]*$#i', $text)) return false;

    // Emoji-only
    if (preg_match('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\s]+$/u', $text)) return false;

    // Check if inside <nav> <ul> — skip nav menu links only (not CTAs, logos, taglines)
    $tag = strtolower($node->nodeName);
    $isInsideNavList = false;
    $parent = $node->parentNode;
    while ($parent && $parent instanceof DOMElement) {
        $pTag = strtolower($parent->nodeName);
        if ($pTag === 'ul' || $pTag === 'ol') {
            // Check if this list is inside a nav
            $grandparent = $parent->parentNode;
            while ($grandparent && $grandparent instanceof DOMElement) {
                if (strtolower($grandparent->nodeName) === 'nav') { $isInsideNavList = true; break; }
                $grandparent = $grandparent->parentNode;
            }
            break;
        }
        $parent = $parent->parentNode;
    }
    // Only skip if inside a nav's <ul>/<ol> list items — CTAs and brand outside lists are kept
    if ($isInsideNavList && in_array($tag, ['a', 'li', 'span'])) return false;

    return true;
}

/**
 * Check if a DOM element is inside a {% for %} loop region.
 * Since we strip Liquid before parsing, we check the original line ranges.
 */
function smart_forge_is_inside_loop(DOMNode $node, SmartForgeState $state): bool {
    // If there are no liquid ranges, there are no loops
    if (empty($state->liquidRanges)) return false;

    // Check if any ancestor was marked as a repeater node
    if ($state->repeaterNodes->offsetExists($node)) return true;

    $parent = $node->parentNode;
    while ($parent) {
        if ($parent instanceof DOMElement && $state->repeaterNodes->offsetExists($parent)) {
            return true;
        }
        $parent = $parent->parentNode ?? null;
    }

    return false;
}


// ── Node Path Utility ───────────────────────────────────

function smart_forge_node_path(DOMNode $node): string {
    $parts = [];
    $current = $node;
    while ($current && $current->nodeType === XML_ELEMENT_NODE) {
        $tag = $current->nodeName;
        // Count same-name siblings before this node
        $index = 1;
        $prev = $current->previousSibling;
        while ($prev) {
            if ($prev->nodeType === XML_ELEMENT_NODE && $prev->nodeName === $tag) {
                $index++;
            }
            $prev = $prev->previousSibling;
        }
        $parts[] = $tag . '[' . $index . ']';
        $current = $current->parentNode;
    }
    return '/' . implode('/', array_reverse($parts));
}


// ── CSS Selector Generation ─────────────────────────────

function smart_forge_css_selector(DOMElement $el): string {
    // 1. Unique ID
    $id = $el->getAttribute('id');
    if ($id !== '' && preg_match('/^[a-zA-Z][\w\-]*$/', $id)) {
        return '#' . $id;
    }

    // 2. Meaningful class
    $classes = array_filter(explode(' ', $el->getAttribute('class')), function ($c) {
        $c = trim($c);
        // Skip utility classes, empty strings
        if ($c === '' || strlen($c) < 3) return false;
        // Skip common utility-only patterns (numbers, single chars)
        if (preg_match('/^(col|row|d|p|m|w|h|mt|mb|ml|mr|pt|pb|pl|pr|px|py|mx|my|g|gap)\-/', $c)) return false;
        return true;
    });

    if (!empty($classes)) {
        // Pick the most descriptive class (longest that's still reasonable)
        usort($classes, fn($a, $b) => strlen($b) - strlen($a));
        $bestClass = reset($classes);
        // Check if parent has a distinguishing selector
        $parent = $el->parentNode;
        if ($parent && $parent instanceof DOMElement) {
            $parentSelector = smart_forge_parent_selector($parent);
            if ($parentSelector) {
                return $parentSelector . ' .' . trim($bestClass);
            }
        }
        return '.' . trim($bestClass);
    }

    // 3. Build path from nearest ID'd ancestor using nth-of-type
    return smart_forge_path_selector($el);
}

function smart_forge_parent_selector(DOMElement $el): ?string {
    $id = $el->getAttribute('id');
    if ($id !== '' && preg_match('/^[a-zA-Z][\w\-]*$/', $id)) {
        return '#' . $id;
    }

    $tag = strtolower($el->nodeName);
    // Landmark elements are good anchors
    if (in_array($tag, ['section', 'header', 'footer', 'main', 'aside', 'article', 'nav'])) {
        $classes = array_filter(explode(' ', $el->getAttribute('class')), fn($c) => trim($c) !== '');
        if (!empty($classes)) {
            return $tag . '.' . trim(reset($classes));
        }
        return $tag;
    }

    $classes = array_filter(explode(' ', $el->getAttribute('class')), fn($c) => trim($c) !== '' && strlen(trim($c)) >= 3);
    if (!empty($classes)) {
        return '.' . trim(reset($classes));
    }

    return null;
}

function smart_forge_path_selector(DOMElement $el): string {
    $parts = [];
    $current = $el;
    $depth = 0;

    while ($current && $current instanceof DOMElement && $depth < 4) {
        $tag = strtolower($current->nodeName);
        if ($tag === 'html' || $tag === 'body') break;

        $id = $current->getAttribute('id');
        if ($id !== '' && preg_match('/^[a-zA-Z][\w\-]*$/', $id)) {
            array_unshift($parts, '#' . $id);
            break;
        }

        // Count nth-of-type
        $index = 1;
        $prev = $current->previousSibling;
        while ($prev) {
            if ($prev instanceof DOMElement && strtolower($prev->nodeName) === $tag) {
                $index++;
            }
            $prev = $prev->previousSibling;
        }

        $selector = $tag;
        $classes = array_filter(explode(' ', $current->getAttribute('class')), fn($c) => trim($c) !== '' && strlen(trim($c)) >= 3);
        if (!empty($classes)) {
            $selector .= '.' . trim(reset($classes));
        } elseif ($index > 1) {
            $selector .= ':nth-of-type(' . $index . ')';
        }

        array_unshift($parts, $selector);
        $current = $current->parentNode;
        $depth++;
    }

    return implode(' > ', $parts);
}


// ── Section Detection ───────────────────────────────────

function smart_forge_detect_sections(DOMDocument $doc, SmartForgeState $state, bool $strictMode = false): void {
    $xpath = new DOMXPath($doc);

    // 1. Explicit data-outpost-section attributes
    $explicit = $xpath->query('//*[@data-outpost-section]');
    foreach ($explicit as $el) {
        $sectionName = $el->getAttribute('data-outpost-section');
        $state->sections[] = $sectionName;
        $state->sectionMap[smart_forge_node_path($el)] = $sectionName;
    }

    // 1b. HTML comments like <!-- Hero Section --> name the next sibling section
    $commentSections = [];
    $xpath2 = new DOMXPath($doc);
    $comments = $xpath2->query('//comment()');
    foreach ($comments as $comment) {
        $text = trim($comment->textContent);
        // Match patterns like "Hero Section", "Chef Section", "Team Grid"
        if (preg_match('/^[\s-]*(.+?)[\s-]*$/', $text, $cm)) {
            $sectionLabel = trim($cm[1]);
            if (strlen($sectionLabel) > 2 && strlen($sectionLabel) < 50) {
                // Find the next sibling element
                $next = $comment->nextSibling;
                while ($next && !($next instanceof DOMElement)) {
                    $next = $next->nextSibling;
                }
                if ($next && $next instanceof DOMElement) {
                    $path = smart_forge_node_path($next);
                    if (!isset($state->sectionMap[$path])) {
                        $name = smart_forge_humanize($sectionLabel);
                        $state->sections[] = $name;
                        $state->sectionMap[$path] = $name;
                    }
                }
            }
        }
    }

    // In strict mode (Forge Analyze), only use explicit markers above.
    // In normal mode (single-file Forge), fall back to heuristics.
    if (!$strictMode) {
        // 2. <section> elements — use id or class as section name (unless already named by comment)
        $sections = $xpath->query('//section');
        foreach ($sections as $el) {
            $path = smart_forge_node_path($el);
            if (isset($state->sectionMap[$path])) continue;

            $name = '';
            $id = $el->getAttribute('id');
            if ($id) {
                $name = smart_forge_humanize($id);
            } else {
                $classes = array_filter(explode(' ', $el->getAttribute('class')), fn($c) => trim($c) !== '');
                if (!empty($classes)) {
                    $name = smart_forge_humanize(reset($classes));
                }
            }
            if (!$name) $name = 'Section';

            $state->sections[] = $name;
            $state->sectionMap[$path] = $name;
        }

        // 3. Landmark elements
        $landmarks = ['header' => 'Header', 'footer' => 'Footer', 'main' => 'Main', 'aside' => 'Sidebar'];
        foreach ($landmarks as $tag => $defaultName) {
            $els = $xpath->query('//' . $tag);
            foreach ($els as $el) {
                $path = smart_forge_node_path($el);
                if (isset($state->sectionMap[$path])) continue;

                $id = $el->getAttribute('id');
                $name = $id ? smart_forge_humanize($id) : $defaultName;
                $state->sections[] = $name;
                $state->sectionMap[$path] = $name;
            }
        }

        // 4. If no sections found yet, look for h2 headings as dividers
        if (empty($state->sections)) {
            $h2s = $xpath->query('//h2');
            foreach ($h2s as $el) {
                $text = trim($el->textContent);
                if ($text) {
                    $name = smart_forge_humanize($text);
                    if (strlen($name) > 30) $name = substr($name, 0, 30);
                    $state->sections[] = $name;
                    // Map the h2's parent as the section
                    $parent = $el->parentNode;
                    if ($parent && $parent instanceof DOMElement) {
                        $state->sectionMap[smart_forge_node_path($parent)] = $name;
                    }
                }
            }
        }
    }

    // 5. Fallback
    if (empty($state->sections)) {
        $state->sections[] = 'Content';
    }
}

function smart_forge_humanize(string $raw): string {
    // Convert kebab-case / snake_case to Title Case
    $name = str_replace(['-', '_'], ' ', $raw);
    $name = ucwords(trim($name));
    return $name;
}


// ── Section Lookup ──────────────────────────────────────

function smart_forge_get_section(DOMNode $node, SmartForgeState $state): string {
    $current = $node;
    while ($current) {
        if ($current instanceof DOMElement) {
            $path = smart_forge_node_path($current);
            if (isset($state->sectionMap[$path])) {
                return $state->sectionMap[$path];
            }
        }
        $current = $current->parentNode;
    }
    // Return the first section or Content
    return $state->sections[0] ?? 'Content';
}


// ── Field Name Generation ───────────────────────────────

function smart_forge_field_name(DOMElement $el, string $section, string $typeHint, SmartForgeState $state): string {
    $sectionSlug = smart_forge_sanitize_name($section);

    // 1. Check id attribute — prefix with section slug
    $id = $el->getAttribute('id');
    if ($id && preg_match('/^[a-zA-Z][\w\-]*$/', $id)) {
        $idName = smart_forge_sanitize_name($id);
        // If the id already starts with the section slug, use it as-is
        if ($sectionSlug && str_starts_with($idName, $sectionSlug . '_')) {
            return $state->uniqueName($idName);
        }
        $name = $sectionSlug ? $sectionSlug . '_' . $idName : $idName;
        return $state->uniqueName($name);
    }

    // 2. Check classes for meaningful name — prefix with section slug
    $classes = array_filter(explode(' ', $el->getAttribute('class')), function ($c) {
        $c = trim($c);
        if ($c === '' || strlen($c) < 3) return false;
        // Skip very generic utility classes
        if (preg_match('/^(col|row|d|p|m|w|h|mt|mb|ml|mr|pt|pb|pl|pr|px|py|mx|my|g|gap|text|bg|flex|grid|block|inline|hidden|visible|container|wrapper|inner|outer)\-?/', $c)) return false;
        return true;
    });
    if (!empty($classes)) {
        $best = smart_forge_sanitize_name(reset($classes));
        // If the class already starts with the section slug, use it as-is
        if ($sectionSlug && str_starts_with($best, $sectionSlug . '_')) {
            return $state->uniqueName($best);
        }
        $name = $sectionSlug ? $sectionSlug . '_' . $best : $best;
        return $state->uniqueName($name);
    }

    // 3. Section + element type (always prefixed with block name)
    $name = $sectionSlug ? $sectionSlug . '_' . $typeHint : $typeHint;
    return $state->uniqueName($name);
}


// ── Skip List Check ─────────────────────────────────────

function smart_forge_should_skip(DOMNode $node): bool {
    if (!($node instanceof DOMElement)) return false;

    $tag = strtolower($node->nodeName);

    // Skip list
    $skipTags = ['script', 'style', 'form', 'svg', 'code', 'pre', 'noscript', 'iframe', 'template'];
    if (in_array($tag, $skipTags)) return true;

    // data-outpost-skip
    if ($node->hasAttribute('data-outpost-skip')) return true;

    // Check ancestors for skip tags
    $parent = $node->parentNode;
    while ($parent && $parent instanceof DOMElement) {
        $parentTag = strtolower($parent->nodeName);
        if (in_array($parentTag, $skipTags)) return true;
        if ($parent->hasAttribute('data-outpost-skip')) return true;
        $parent = $parent->parentNode;
    }

    return false;
}


// ── Repeater Detection ──────────────────────────────────

function smart_forge_detect_repeaters(DOMDocument $doc, SmartForgeState $state): void {
    $xpath = new DOMXPath($doc);

    // Find containers that have multiple children with identical structure
    $containers = $xpath->query('//*');
    foreach ($containers as $container) {
        if (!($container instanceof DOMElement)) continue;
        if (smart_forge_should_skip($container)) continue;

        $tag = strtolower($container->nodeName);
        // Only look at likely list containers
        if (!in_array($tag, ['div', 'ul', 'ol', 'section', 'main', 'article'])) continue;

        // Get element children (skip text nodes, comments)
        $children = [];
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && !smart_forge_should_skip($child)) {
                $children[] = $child;
            }
        }

        if (count($children) < 2) continue;

        // Group children by structural signature
        $groups = [];
        foreach ($children as $child) {
            $sig = smart_forge_element_signature($child);
            $groups[$sig][] = $child;
        }

        // Find groups with 3+ matching elements (2 is too aggressive — catches grid layouts)
        foreach ($groups as $sig => $elements) {
            if (count($elements) < 3) continue;

            // Verify they have actual content (not empty containers)
            $hasContent = false;
            foreach ($elements as $el) {
                if (trim($el->textContent) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if (!$hasContent) continue;

            // Detect sub-fields from the first element (template)
            $section = smart_forge_get_section($container, $state);
            $repeaterName = smart_forge_field_name($container, $section, 'items', $state);

            $subFields = smart_forge_detect_sub_fields($elements[0], $state);
            if (empty($subFields)) continue;

            $selector = smart_forge_css_selector($elements[0]);

            $state->repeaters[] = [
                'name' => $repeaterName,
                'section' => $section,
                'selector' => $selector,
                'sub_fields' => $subFields,
                'element_count' => count($elements),
            ];

            // Mark these nodes as handled
            foreach ($elements as $el) {
                $state->repeaterNodes->attach($el);
            }
        }
    }
}

function smart_forge_element_signature(DOMElement $el): string {
    $parts = [strtolower($el->nodeName)];

    // Include classes (sorted for consistency)
    $classes = array_filter(explode(' ', $el->getAttribute('class')), fn($c) => trim($c) !== '');
    sort($classes);
    $parts[] = implode('.', $classes);

    // Include child structure recursively (up to 3 levels)
    $parts[] = smart_forge_child_signature($el, 0);

    return implode('|', $parts);
}

function smart_forge_child_signature(DOMElement $el, int $depth): string {
    if ($depth >= 3) return '';

    $childSigs = [];
    foreach ($el->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $tag = strtolower($child->nodeName);
            $classes = array_filter(explode(' ', $child->getAttribute('class')), fn($c) => trim($c) !== '');
            sort($classes);
            $sig = $tag . (empty($classes) ? '' : '.' . implode('.', $classes));
            $sig .= '{' . smart_forge_child_signature($child, $depth + 1) . '}';
            $childSigs[] = $sig;
        }
    }
    return implode(',', $childSigs);
}

function smart_forge_detect_sub_fields(DOMElement $el, SmartForgeState $state): array {
    $subFields = [];
    $usedSubNames = [];

    $stack = [$el];
    while (!empty($stack)) {
        $current = array_shift($stack);
        if (!($current instanceof DOMElement)) continue;
        if (smart_forge_should_skip($current)) continue;

        $tag = strtolower($current->nodeName);

        // Detect images
        if ($tag === 'img') {
            $name = smart_forge_sub_field_name($current, 'image', $usedSubNames);
            $subFields[] = ['name' => $name, 'type' => 'image'];
            continue; // Don't recurse into img
        }

        // Detect headings
        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
            $name = smart_forge_sub_field_name($current, 'title', $usedSubNames);
            $subFields[] = ['name' => $name, 'type' => 'text'];
            continue;
        }

        // Detect paragraphs
        if ($tag === 'p') {
            $text = trim($current->textContent);
            $name = smart_forge_sub_field_name($current, 'description', $usedSubNames);
            $subFields[] = ['name' => $name, 'type' => strlen($text) >= 100 ? 'richtext' : 'text'];
            continue;
        }

        // Detect links
        if ($tag === 'a') {
            $name = smart_forge_sub_field_name($current, 'link', $usedSubNames);
            $subFields[] = ['name' => $name . '_label', 'type' => 'text'];
            $subFields[] = ['name' => $name . '_url', 'type' => 'text'];
            $usedSubNames[$name . '_label'] = true;
            $usedSubNames[$name . '_url'] = true;
            continue;
        }

        // Detect spans with text
        if ($tag === 'span' && trim($current->textContent) !== '') {
            $name = smart_forge_sub_field_name($current, 'label', $usedSubNames);
            $subFields[] = ['name' => $name, 'type' => 'text'];
            continue;
        }

        // Recurse into children
        foreach ($current->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $stack[] = $child;
            }
        }
    }

    return $subFields;
}

function smart_forge_sub_field_name(DOMElement $el, string $fallback, array &$usedNames): string {
    // Try id
    $id = $el->getAttribute('id');
    if ($id) {
        $name = smart_forge_sanitize_name($id);
        if ($name && !isset($usedNames[$name])) {
            $usedNames[$name] = true;
            return $name;
        }
    }

    // Try class
    $classes = array_filter(explode(' ', $el->getAttribute('class')), fn($c) => trim($c) !== '' && strlen(trim($c)) >= 3);
    if (!empty($classes)) {
        $name = smart_forge_sanitize_name(reset($classes));
        if ($name && !isset($usedNames[$name])) {
            $usedNames[$name] = true;
            return $name;
        }
    }

    // Fallback with numbering
    $name = $fallback;
    if (isset($usedNames[$name])) {
        $i = 2;
        while (isset($usedNames[$name . '_' . $i])) $i++;
        $name = $name . '_' . $i;
    }
    $usedNames[$name] = true;
    return $name;
}


// ── DOM Walk — Field Detection ──────────────────────────

function smart_forge_walk(DOMNode $node, DOMDocument $doc, SmartForgeState $state): void {
    if (!($node instanceof DOMElement)) {
        // Recurse into document fragments / document node
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                smart_forge_walk($child, $doc, $state);
            }
        }
        return;
    }

    // Skip list check
    if (smart_forge_should_skip($node)) return;

    // NOTE: We no longer skip repeater nodes — individual fields inside repeaters are still valuable

    $tag = strtolower($node->nodeName);

    // Simple forged check: scan original HTML for this exact element already having
    // v1 Liquid tags ({{ }}) or v2 data-outpost attributes
    if (!empty($state->originalHtml) && in_array($tag, ['h1','h2','h3','h4','h5','h6','p','span','a','button','img'])) {
        $nodeText = trim($node->textContent);
        if ($nodeText) {
            $first20 = substr($nodeText, 0, 20);
            foreach (explode("\n", $state->originalHtml) as $line) {
                $trimLine = trim($line);
                // v1: Line has opening tag + {{ }} + this text
                if (str_contains($trimLine, '<' . $tag) && str_contains($trimLine, '{{') && str_contains($trimLine, $first20)) {
                    return; // Already Liquid-tagged
                }
                // v2: Line has opening tag + data-outpost attribute
                if (str_contains($trimLine, '<' . $tag) && str_contains($trimLine, 'data-outpost="') && str_contains($trimLine, $first20)) {
                    return; // Already v2-tagged
                }
            }
        }
    }
    $section = smart_forge_get_section($node, $state);

    // ── Images ──
    if ($tag === 'img') {
        $src = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');
        // Skip data: URIs, SVG refs, and empty srcs
        if ($src && !str_starts_with($src, 'data:') && !str_starts_with($src, '#')) {
            $name = smart_forge_field_name($node, $section, 'image', $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'image', $section, $selector, $src, $node);
            // Also create alt text field if alt has real content
            if ($alt !== '' && mb_strlen($alt) >= 3) {
                $altName = $state->uniqueName($name . '_alt');
                $state->addField($altName, 'text', $section, $selector . '[alt]', $alt, null);
            }
        }
        return;
    }

    // ── Background images in style attribute ──
    if ($node->hasAttribute('style')) {
        $style = $node->getAttribute('style');
        if (preg_match('/background(?:-image)?\s*:\s*url\([\'"]?([^\'")\s]+)[\'"]?\)/i', $style, $m)) {
            $bgUrl = $m[1];
            // Skip data: URIs
            if (!str_starts_with($bgUrl, 'data:')) {
                $name = smart_forge_field_name($node, $section, 'bg_image', $state);
                $selector = smart_forge_css_selector($node);
                $state->addField($name, 'image', $section, $selector . '[style]', $bgUrl, $node);
            }
        }
    }

    // ── Headings (h1-h6) ──
    if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
        $text = trim($node->textContent);
        // Headings are always worth editing if they have 2+ chars and are in content areas
        if ($text !== '' && mb_strlen($text) >= 2) {
            // Skip headings inside <nav> — those are navigation labels
            $isNavLabel = false;
            $parent = $node->parentNode;
            while ($parent && $parent instanceof DOMElement) {
                if (strtolower($parent->nodeName) === 'nav') { $isNavLabel = true; break; }
                $parent = $parent->parentNode;
            }
            if (!$isNavLabel) {
                $name = smart_forge_field_name($node, $section, 'heading', $state);
                $selector = smart_forge_css_selector($node);
                // Check if heading has inline formatting children
                $hasFormatting = false;
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['em', 'strong', 'span', 'mark', 'br', 'a', 'sup', 'sub'])) {
                        $hasFormatting = true;
                        break;
                    }
                }
                $fieldType = $hasFormatting ? 'richtext' : 'text';
                $state->addField($name, $fieldType, $section, $selector, $text, $node);
            }
        }
        return; // Don't recurse into heading children
    }

    // ── Links — CTA buttons AND text links with meaningful content ──
    if ($tag === 'a') {
        $href = $node->getAttribute('href');
        $text = trim($node->textContent);

        // Skip truly empty links
        if ($text === '' || $href === '') { return; }

        // Skip if inside a nav's <ul>/<ol> (menu system handles those)
        $isInsideNavList = false;
        $p = $node->parentNode;
        while ($p && $p instanceof DOMElement) {
            $pTag = strtolower($p->nodeName);
            if ($pTag === 'ul' || $pTag === 'ol') {
                $gp = $p->parentNode;
                while ($gp && $gp instanceof DOMElement) {
                    if (strtolower($gp->nodeName) === 'nav') { $isInsideNavList = true; break; }
                    $gp = $gp->parentNode;
                }
                break;
            }
            $p = $p->parentNode;
        }
        if ($isInsideNavList) { return; }

        if (smart_forge_is_worth_editing($node, $text)) {
            $classes = $node->getAttribute('class');
            $isCta = (bool)preg_match('/\b(btn|button|cta|action)\b/i', $classes);
            $typeHint = $isCta ? 'cta' : 'link';

            $name = smart_forge_field_name($node, $section, $typeHint, $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'link', $section, $selector, $href, $node);
            // Store link replacement info
            $path = smart_forge_node_path($node);
            $state->replacements[$path] = [
                'name' => $name,
                'type' => 'link',
                'node' => $node,
            ];
        }
        return; // Don't recurse into link children
    }

    // ── Buttons ──
    if ($tag === 'button') {
        $text = trim($node->textContent);
        if ($text !== '' && smart_forge_is_worth_editing($node, $text)) {
            $name = smart_forge_field_name($node, $section, 'cta', $state);
            $selector = smart_forge_css_selector($node);
            $labelName = $state->uniqueName($name . '_label');
            $urlName = $state->uniqueName($name . '_url');
            $state->addField($urlName, 'text', $section, $selector, '#', null);
            $state->addField($labelName, 'text', $section, $selector, $text, $node);
            $path = smart_forge_node_path($node);
            $state->replacements[$path] = [
                'name_url' => $urlName,
                'name_label' => $labelName,
                'type' => 'button',
                'node' => $node,
            ];
        }
        return;
    }

    // ── Paragraphs ──
    if ($tag === 'p') {
        // Skip if already consumed by a multi-paragraph richtext container
        if ($state->repeaterNodes->offsetExists($node)) return;

        $text = trim($node->textContent);
        if ($text !== '' && smart_forge_is_worth_editing($node, $text)) {
            $len = mb_strlen($text);
            // Richtext: 100+ chars or contains inner HTML elements
            // Text: any meaningful paragraph (3+ chars passes is_worth_editing)
            if ($len >= 100) {
                $name = smart_forge_field_name($node, $section, 'body', $state);
                $selector = smart_forge_css_selector($node);
                $state->addField($name, 'richtext', $section, $selector, $text, $node);
            } else {
                $name = smart_forge_field_name($node, $section, 'text', $state);
                $selector = smart_forge_css_selector($node);
                $state->addField($name, 'text', $section, $selector, $text, $node);
            }
        }
        return;
    }

    // ── Spans with direct text — badges, labels, author names, roles ──
    if ($tag === 'span') {
        $text = trim($node->textContent);
        if ($text !== '' && smart_forge_is_worth_editing($node, $text) && smart_forge_has_direct_text($node)) {
            $name = smart_forge_field_name($node, $section, 'label', $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'text', $section, $selector, $text, $node);
        }
        return;
    }

    // ── Divs with direct text — badges, labels, names, roles, dates ──
    if ($tag === 'div') {
        $text = trim($node->textContent);
        // Only tag divs that have DIRECT text content (not just child elements)
        if ($text !== '' && smart_forge_has_direct_text($node) && smart_forge_is_worth_editing($node, $text)) {
            // Check if this div only contains short text (not a container div)
            $childElementCount = 0;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) $childElementCount++;
            }
            // If it has 0-1 child elements and text is < 100 chars, it's a label/badge/name
            if ($childElementCount <= 1 && mb_strlen($text) < 100) {
                $classes = strtolower($node->getAttribute('class'));
                $typeHint = 'label';
                if (str_contains($classes, 'name') || str_contains($classes, 'author')) $typeHint = 'name';
                if (str_contains($classes, 'role') || str_contains($classes, 'title') || str_contains($classes, 'position')) $typeHint = 'role';
                if (str_contains($classes, 'badge') || str_contains($classes, 'tag') || str_contains($classes, 'label')) $typeHint = 'badge';
                if (str_contains($classes, 'date') || str_contains($classes, 'time')) $typeHint = 'date';

                $name = smart_forge_field_name($node, $section, $typeHint, $state);
                $selector = smart_forge_css_selector($node);
                $state->addField($name, 'text', $section, $selector, $text, $node);
                return;
            }
        }
        // Don't return — let it recurse into child elements
    }

    // ── Blockquotes ──
    if ($tag === 'blockquote') {
        $text = trim($node->textContent);
        if ($text !== '' && mb_strlen($text) >= 3) {
            $name = smart_forge_field_name($node, $section, 'quote', $state);
            $selector = smart_forge_css_selector($node);
            $type = mb_strlen($text) >= 80 ? 'richtext' : 'text';
            $state->addField($name, $type, $section, $selector, $text, $node);
        }
        return;
    }

    // ── Figcaptions ──
    if ($tag === 'figcaption') {
        $text = trim($node->textContent);
        if ($text !== '' && mb_strlen($text) >= 3) {
            $name = smart_forge_field_name($node, $section, 'caption', $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'text', $section, $selector, $text, $node);
        }
        return;
    }

    // ── Time / date elements ──
    if ($tag === 'time') {
        $text = trim($node->textContent);
        if ($text !== '') {
            $name = smart_forge_field_name($node, $section, 'date', $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'text', $section, $selector, $text, $node);
        }
        return;
    }

    // ── Small text (often used for fine print, credits, author info) ──
    if ($tag === 'small') {
        $text = trim($node->textContent);
        if ($text !== '' && mb_strlen($text) >= 3 && smart_forge_is_worth_editing($node, $text)) {
            $name = smart_forge_field_name($node, $section, 'note', $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'text', $section, $selector, $text, $node);
        }
        return;
    }

    // ── Strong/em with direct text (bold callouts, emphasis) ──
    if (in_array($tag, ['strong', 'em'])) {
        // Only detect if parent is NOT a heading/paragraph (those are caught above)
        $parentTag = $node->parentNode ? strtolower($node->parentNode->nodeName) : '';
        if (!in_array($parentTag, ['h1','h2','h3','h4','h5','h6','p','a','button','blockquote'])) {
            $text = trim($node->textContent);
            if ($text !== '' && mb_strlen($text) >= 3 && smart_forge_is_worth_editing($node, $text)) {
                $name = smart_forge_field_name($node, $section, 'label', $state);
                $selector = smart_forge_css_selector($node);
                $state->addField($name, 'text', $section, $selector, $text, $node);
            }
        }
        return;
    }

    // ── Address elements ──
    if ($tag === 'address') {
        $text = trim($node->textContent);
        if ($text !== '' && mb_strlen($text) >= 3) {
            $name = smart_forge_field_name($node, $section, 'address', $state);
            $selector = smart_forge_css_selector($node);
            $state->addField($name, 'richtext', $section, $selector, $text, $node);
        }
        return;
    }

    // ── Lists (ul, ol) — treat as editable richtext blocks ──
    if (in_array($tag, ['ul', 'ol'])) {
        $liCount = 0;
        $listHtml = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $liCount++;
                $listHtml .= '<li>' . trim($child->textContent) . '</li>';
            }
        }
        if ($liCount >= 2) {
            $name = smart_forge_field_name($node, $section, 'list', $state);
            $selector = smart_forge_css_selector($node);
            $fullHtml = '<' . $tag . '>' . $listHtml . '</' . $tag . '>';
            $state->addField($name, 'richtext', $section, $selector, $fullHtml, $node);
        }
        return; // Don't recurse into list items
    }

    // ── Check for richtext: container with multiple consecutive <p> children ──
    // This catches groups like: <div><p>...</p><p>...</p><p>...</p></div>
    // Combines them into one richtext field instead of separate text fields
    if (in_array($tag, ['div', 'article', 'section', 'main'])) {
        $pCount = 0;
        $totalHtml = '';
        $pNodes = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'p') {
                $pCount++;
                $totalHtml .= '<p>' . trim($child->textContent) . '</p>';
                $pNodes[] = $child;
            }
        }
        if ($pCount >= 2 && mb_strlen(strip_tags($totalHtml)) >= 30) {
            // Check that these paragraphs aren't already forged (v1 Liquid or v2 data-outpost)
            $alreadyForged = false;
            foreach ($pNodes as $pn) {
                $pText = substr(trim($pn->textContent), 0, 20);
                if ($pText) {
                    foreach (explode("\n", $state->originalHtml) as $line) {
                        $trimLine = trim($line);
                        // v1 check
                        if (str_contains($trimLine, '<p') && str_contains($line, '{{') && str_contains($line, $pText)) {
                            $alreadyForged = true;
                            break 2;
                        }
                        // v2 check
                        if (str_contains($trimLine, '<p') && str_contains($line, 'data-outpost="') && str_contains($line, $pText)) {
                            $alreadyForged = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$alreadyForged) {
                $name = smart_forge_field_name($node, $section, 'body', $state);
                $selector = smart_forge_css_selector($node);
                $state->addField($name, 'richtext', $section, $selector, $totalHtml, $node);
                // Mark the <p> nodes so they don't get processed individually
                foreach ($pNodes as $pn) {
                    $state->repeaterNodes->attach($pn);
                }
                // Don't return — still recurse for non-<p> children (headings, images, etc.)
            }
        }
    }

    // Recurse into children
    foreach ($node->childNodes as $child) {
        smart_forge_walk($child, $doc, $state);
    }
}

function smart_forge_has_direct_text(DOMElement $el): bool {
    foreach ($el->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) !== '') {
            return true;
        }
    }
    return false;
}


// ── Meta Detection ──────────────────────────────────────

function smart_forge_detect_meta(DOMDocument $doc, SmartForgeState $state): void {
    $xpath = new DOMXPath($doc);

    // <title>
    $titles = $xpath->query('//title');
    foreach ($titles as $title) {
        $text = trim($title->textContent);
        $state->metaFields[] = [
            'type' => 'title',
            'default_value' => $text,
            'node' => $title,
        ];
    }

    // <meta name="description">
    $descs = $xpath->query('//meta[@name="description"]');
    foreach ($descs as $desc) {
        $content = $desc->getAttribute('content');
        $state->metaFields[] = [
            'type' => 'description',
            'default_value' => $content,
            'node' => $desc,
        ];
    }
}


// ── Partials Detection ──────────────────────────────────

function smart_forge_detect_partials(DOMDocument $doc, SmartForgeState $state): void {
    $xpath = new DOMXPath($doc);

    // <head> → partials/head.html
    $heads = $xpath->query('//head');
    foreach ($heads as $head) {
        $state->suggestedPartials[] = [
            'name' => 'head',
            'selector' => 'head',
            'start_line' => $head->getLineNo(),
            'end_line' => $head->getLineNo(), // Approximation — DOMDocument doesn't track end lines
        ];
    }

    // First <header> or first <nav> → partials/nav.html
    $headers = $xpath->query('//header');
    if ($headers->length > 0) {
        $el = $headers->item(0);
        $state->suggestedPartials[] = [
            'name' => 'nav',
            'selector' => 'header',
            'start_line' => $el->getLineNo(),
            'end_line' => $el->getLineNo(),
        ];
    } else {
        $navs = $xpath->query('//nav');
        if ($navs->length > 0) {
            $el = $navs->item(0);
            $state->suggestedPartials[] = [
                'name' => 'nav',
                'selector' => 'nav',
                'start_line' => $el->getLineNo(),
                'end_line' => $el->getLineNo(),
            ];
        }
    }

    // Last <footer> → partials/footer.html
    $footers = $xpath->query('//footer');
    if ($footers->length > 0) {
        $el = $footers->item($footers->length - 1);
        $state->suggestedPartials[] = [
            'name' => 'footer',
            'selector' => 'footer',
            'start_line' => $el->getLineNo(),
            'end_line' => $el->getLineNo(),
        ];
    }
}


// ── Template Generation (v2 — data-attribute output) ─────

function smart_forge_generate_template(string $originalHtml, DOMDocument $doc, SmartForgeState $state): string {
    $template = $originalHtml;

    // Apply meta replacements — replace <title> and <meta description> with <outpost-seo />
    // If both title and description are found, replace them with a single <outpost-seo /> tag
    $hasTitle = false;
    $hasDesc = false;
    $titleDefault = '';
    $descDefault = '';
    foreach ($state->metaFields as $meta) {
        if ($meta['type'] === 'title') { $hasTitle = true; $titleDefault = $meta['default_value']; }
        if ($meta['type'] === 'description') { $hasDesc = true; $descDefault = $meta['default_value']; }
    }

    if ($hasTitle || $hasDesc) {
        // Replace <title>...</title> with <outpost-meta> or add <outpost-seo />
        if ($hasTitle) {
            $template = preg_replace(
                '/<title[^>]*>.*?<\/title>/is',
                '<outpost-meta title="' . htmlspecialchars($titleDefault) . '"' .
                ($hasDesc ? ' description="' . htmlspecialchars($descDefault) . '"' : '') . ' />',
                $template,
                1
            );
        }
        // Remove the <meta name="description"> if we included it in outpost-meta
        if ($hasDesc && $hasTitle) {
            $template = preg_replace(
                '/\s*<meta\s+name=["\']description["\'][^>]*\/?>\s*/i',
                "\n",
                $template,
                1
            );
        } elseif ($hasDesc && !$hasTitle) {
            // Only description found — replace it with outpost-meta
            $template = preg_replace(
                '/<meta\s+name=["\']description["\'][^>]*\/?>/i',
                '<outpost-meta description="' . htmlspecialchars($descDefault) . '" />',
                $template,
                1
            );
        }
    }

    // Apply field replacements — inject data-outpost="name" (+ data-type for non-text)
    // into the original HTML element's opening tag
    foreach ($state->fields as $field) {
        $name = $field['name'];
        $type = $field['type'];
        $defaultVal = $field['default_value'];

        if ($type === 'image') {
            // Add data-outpost + data-type="image" to the <img> tag that has this src
            if ($defaultVal && strpos($template, $defaultVal) !== false) {
                $template = smart_forge_add_attr_to_img($template, $defaultVal, $name);
            }
        } elseif ($type === 'link') {
            // Links are handled via the replacements map below
            continue;
        } elseif ($type === 'richtext') {
            // Add data-outpost + data-type="richtext" to the element containing this text
            if ($defaultVal) {
                $template = smart_forge_add_attr_to_element($template, $defaultVal, $name, 'richtext');
            }
        } elseif ($type === 'text') {
            // Skip alt text fields (handled with image) and URL fields
            if (str_ends_with($name, '_alt') || str_ends_with($name, '_url')) continue;

            if ($defaultVal) {
                $template = smart_forge_add_attr_to_element($template, $defaultVal, $name, 'text');
            }
        }
    }

    // Apply link replacements — add data-outpost + data-type="link" to <a> tags
    foreach ($state->replacements as $path => $info) {
        if (($info['type'] ?? '') === 'link') {
            $node = $info['node'];
            $href = $node->getAttribute('href');
            $text = trim($node->textContent);
            $fieldName = $info['name'];
            // Add data-outpost="name" data-type="link" to the <a> tag
            if ($href) {
                $template = smart_forge_add_attr_to_link($template, $href, $text, $fieldName);
            }
        } elseif (($info['type'] ?? '') === 'button') {
            $node = $info['node'];
            $text = trim($node->textContent);
            $labelName = $info['name_label'] ?? '';
            if ($text && $labelName) {
                $template = smart_forge_add_attr_to_element($template, $text, $labelName, 'text');
            }
        }
    }

    // Apply repeater replacements (data-outpost-repeat attribute)
    foreach ($state->repeaters as $repeater) {
        $template = smart_forge_apply_repeater_template($template, $doc, $repeater, $state);
    }

    // Insert section comment wrappers (<!-- outpost:sectionname --> / <!-- /outpost:sectionname -->)
    $template = smart_forge_insert_section_comments($template, $doc, $state);

    // Insert <!-- outpost-settings: --> comments for sections with visual properties
    $template = smart_forge_insert_settings_comments($template, $doc, $state);

    return $template;
}

/**
 * Insert <!-- outpost-settings: --> comments for sections that have visual properties
 * (background colors, layouts, toggle visibility).
 * Placed right after the <!-- outpost:sectionname --> opening comment.
 */
function smart_forge_insert_settings_comments(string $template, DOMDocument $doc, SmartForgeState $state): string {
    // Don't add if settings comments already exist
    if (preg_match('/<!--\s*outpost-settings:/', $template)) return $template;

    // For each section, detect if the element has background colors or layout-related styles/classes
    foreach ($state->sectionMap as $path => $sectionName) {
        $slug = smart_forge_sanitize_name($sectionName);
        if (!$slug) continue;

        // Find the element in the DOM
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query($path);
        if ($nodes === false || $nodes->length === 0) continue;
        $el = $nodes->item(0);
        if (!($el instanceof DOMElement)) continue;

        $settings = [];

        // Detect background color from style attribute or class hints
        $style = $el->getAttribute('style');
        $classes = $el->getAttribute('class');

        if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\))/i', $style, $bgm)) {
            $settings[] = 'bg_color: color(' . $bgm[1] . ')';
        } elseif (preg_match('/\b(bg-|background-)\w+/', $classes)) {
            // Has a background class — suggest a color setting with white default
            $settings[] = 'bg_color: color(#ffffff)';
        }

        // All sections get a show toggle
        $settings[] = 'show_section: toggle(true)';

        // Only insert if we have settings and the section comment exists
        if (!empty($settings)) {
            $commentTag = '<!-- outpost:' . $slug;
            $pos = strpos($template, $commentTag);
            if ($pos !== false) {
                // Find end of the opening comment line
                $lineEnd = strpos($template, "\n", $pos);
                if ($lineEnd !== false) {
                    // Detect indent from the comment line
                    $lineStart = strrpos(substr($template, 0, $pos), "\n");
                    $indent = ($lineStart !== false) ? substr($template, $lineStart + 1, $pos - $lineStart - 1) : '';

                    $settingsBlock = "\n" . $indent . "<!-- outpost-settings:\n";
                    foreach ($settings as $s) {
                        $settingsBlock .= $indent . "    " . $s . "\n";
                    }
                    $settingsBlock .= $indent . "-->";

                    $template = substr($template, 0, $lineEnd) . $settingsBlock . substr($template, $lineEnd);
                }
            }
        }
    }

    return $template;
}

/**
 * Insert <!-- outpost:sectionname --> comment wrappers around detected sections.
 * Replaces the v1 {#--- front matter ---#} block.
 */
function smart_forge_insert_section_comments(string $template, DOMDocument $doc, SmartForgeState $state): string {
    // Don't add section comments if v1 front matter or v2 comments already exist
    if (preg_match('/\{#---/', $template)) return $template;
    if (preg_match('/<!--\s*outpost:/', $template)) return $template;

    // Build a map of section names → HTML elements to wrap with comments.
    // We inject comments around <section>, <header>, <footer>, <main>, <aside> tags.
    // Process in reverse order of position so inserted text doesn't shift offsets.
    $insertions = [];

    foreach ($state->sectionMap as $path => $sectionName) {
        $slug = smart_forge_sanitize_name($sectionName);
        if (!$slug) continue;

        // Determine which tag/class/id combo to search for
        // Parse the XPath-like path to get the element's tag
        if (preg_match('/([a-z]+)\[\d+\]$/', $path, $pm)) {
            $tag = $pm[1];
        } else {
            continue;
        }

        // Only wrap landmark/section elements (not arbitrary divs)
        if (!in_array($tag, ['section', 'header', 'footer', 'main', 'aside', 'article', 'nav'])) continue;

        // Determine global keyword for nav/footer
        $globalKeyword = '';
        if (in_array($tag, ['nav', 'footer'])) {
            $globalKeyword = ' global';
        }

        // Find the node in the DOMDocument to get its attributes for matching
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query($path);
        if ($nodes === false || $nodes->length === 0) continue;

        $el = $nodes->item(0);
        if (!($el instanceof DOMElement)) continue;

        // Build a regex to find this element's opening tag in the template
        $id = $el->getAttribute('id');
        $class = $el->getAttribute('class');

        $pattern = null;
        if ($id) {
            // Match by id
            $escapedId = preg_quote($id, '/');
            $pattern = '/^([ \t]*)<' . $tag . '([^>]*\bid=["\']' . $escapedId . '["\'][^>]*)>/m';
        } elseif ($class) {
            // Match by first class
            $firstClass = preg_quote(explode(' ', trim($class))[0], '/');
            $pattern = '/^([ \t]*)<' . $tag . '([^>]*\bclass=["\'][^"\']*\b' . $firstClass . '\b[^"\']*["\'][^>]*)>/m';
        } else {
            // Match by bare tag (only if there's likely one)
            $pattern = '/^([ \t]*)<' . $tag . '(\b[^>]*)>/m';
        }

        if ($pattern && preg_match($pattern, $template, $m, PREG_OFFSET_CAPTURE)) {
            $matchOffset = $m[0][1];
            $indent = $m[1][0];

            // Find the closing tag
            $closingPattern = '/<\/' . $tag . '\s*>/i';
            // Search from the opening tag position forward
            $afterOpen = substr($template, $matchOffset + strlen($m[0][0]));
            if (preg_match($closingPattern, $afterOpen, $cm, PREG_OFFSET_CAPTURE)) {
                $closingOffset = $matchOffset + strlen($m[0][0]) + $cm[0][1] + strlen($cm[0][0]);
                $insertions[] = [
                    'open_offset' => $matchOffset,
                    'close_offset' => $closingOffset,
                    'slug' => $slug,
                    'indent' => $indent,
                    'global' => $globalKeyword,
                ];
            }
        }
    }

    // Sort insertions by offset descending so we insert from back to front
    usort($insertions, fn($a, $b) => $b['close_offset'] - $a['close_offset']);

    // Track slugs we've already inserted to avoid duplicates
    $insertedSlugs = [];

    foreach ($insertions as $ins) {
        if (isset($insertedSlugs[$ins['slug']])) continue;
        $insertedSlugs[$ins['slug']] = true;

        $openComment = $ins['indent'] . '<!-- outpost:' . $ins['slug'] . $ins['global'] . " -->\n";
        $closeComment = "\n" . $ins['indent'] . '<!-- /outpost:' . $ins['slug'] . ' -->';

        // Insert closing comment after the closing tag
        $template = substr($template, 0, $ins['close_offset']) . $closeComment . substr($template, $ins['close_offset']);
        // Insert opening comment before the opening tag
        $template = substr($template, 0, $ins['open_offset']) . $openComment . substr($template, $ins['open_offset']);
    }

    return $template;
}

/**
 * Add data-outpost="name" data-type="image" to an <img> tag matching the given src.
 */
function smart_forge_add_attr_to_img(string $html, string $src, string $fieldName): string {
    $escaped = preg_quote($src, '/');
    // Find <img ... src="value" ...> and inject data-outpost + data-type before >
    $pattern = '/(<img\b[^>]*?\bsrc=["\'])(' . $escaped . ')(["\'][^>]*?)(\/?>)/i';
    return preg_replace(
        $pattern,
        '$1$2$3 data-outpost="' . $fieldName . '" data-type="image"$4',
        $html,
        1
    );
}

/**
 * Add data-outpost="name" (+ data-type if not plain text) to the element
 * that contains the given text content.
 */
function smart_forge_add_attr_to_element(string $html, string $text, string $fieldName, string $type): string {
    $escaped = preg_quote($text, '/');

    // Find an element whose opening tag is immediately followed by this text
    // Pattern: <tag ...>text — inject data-outpost before the >
    $typeAttr = ($type !== 'text') ? ' data-type="' . $type . '"' : '';
    $pattern = '/(<(?:h[1-6]|p|span|div|li|td|th|dt|dd|figcaption|blockquote|label|small|strong|em|time|address|article|section|cite|mark)\b[^>]*?)(>)\s*' . $escaped . '/i';
    $result = preg_replace(
        $pattern,
        '$1 data-outpost="' . $fieldName . '"' . $typeAttr . '$2' . $text,
        $html,
        1
    );
    if ($result !== null && $result !== $html) {
        return $result;
    }

    // Fallback: try matching any element with this exact text
    $pattern2 = '/(<[a-z][a-z0-9]*\b[^>]*?)(>)\s*' . $escaped . '\s*</i';
    $result2 = preg_replace(
        $pattern2,
        '$1 data-outpost="' . $fieldName . '"' . $typeAttr . '$2' . $text . '<',
        $html,
        1
    );
    if ($result2 !== null && $result2 !== $html) {
        return $result2;
    }

    return $html;
}

/**
 * Add data-outpost="name" data-type="link" to an <a> tag matching the given href/text.
 */
function smart_forge_add_attr_to_link(string $html, string $href, string $text, string $fieldName): string {
    $escapedHref = preg_quote($href, '/');
    // Find <a ... href="value" ...> and inject data-outpost + data-type="link"
    $pattern = '/(<a\b[^>]*?\bhref=["\'])(' . $escapedHref . ')(["\'][^>]*?)(>)/i';
    return preg_replace(
        $pattern,
        '$1$2$3 data-outpost="' . $fieldName . '" data-type="link"$4',
        $html,
        1
    );
}

function smart_forge_apply_repeater_template(string $html, DOMDocument $doc, array $repeater, SmartForgeState $state): string {
    // For repeaters, we add a data-outpost-repeat attribute to the container.
    // The actual replacement is complex — this is a best-effort marker.
    // The forge/apply endpoint can handle the full replacement.
    return $html;
}


// ── Background Image Detection in Style ─────────────────

function smart_forge_replace_bg_image(string $html, string $url, string $fieldName): string {
    $escaped = preg_quote($url, '/');
    // Add data-outpost + data-type="image" to the element with this background-image style
    $pattern = '/(<[a-z][a-z0-9]*\b[^>]*?style=["\'][^"\']*background(?:-image)?\s*:\s*url\([\'"]?)' . $escaped . '([\'"]?\)[^"\']*["\'][^>]*?)(>)/i';
    return preg_replace(
        $pattern,
        '$1' . $url . '$2 data-outpost="' . $fieldName . '" data-type="image"$3',
        $html,
        1
    );
}


// ── Smart Forge AI ──────────────────────────────────────

/**
 * Check if any AI API key is configured for Smart Forge AI.
 * Returns ['available' => bool, 'provider' => string|null]
 */
function forge_ai_status(): array {
    $db = OutpostDB::connect();
    $providers = ['claude', 'openai', 'gemini'];

    // Check for default provider first
    $defaultRow = $db->query("SELECT value FROM settings WHERE key = 'ranger_default_provider'")->fetch(\PDO::FETCH_ASSOC);
    $defaultProvider = $defaultRow ? $defaultRow['value'] : '';

    // If default provider has a key, use it
    if ($defaultProvider && in_array($defaultProvider, $providers)) {
        $row = $db->query("SELECT value FROM settings WHERE key = 'ranger_api_key_$defaultProvider'")->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['value'])) {
            return ['available' => true, 'provider' => $defaultProvider];
        }
    }

    // Otherwise find the first provider with a key
    foreach ($providers as $p) {
        $row = $db->query("SELECT value FROM settings WHERE key = 'ranger_api_key_$p'")->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['value'])) {
            return ['available' => true, 'provider' => $p];
        }
    }

    return ['available' => false, 'provider' => null];
}

/**
 * The system prompt for Smart Forge AI annotation.
 */
function forge_ai_system_prompt(): string {
    return <<<'PROMPT'
You are Smart Forge AI for Outpost CMS. Your job is to annotate raw HTML with Outpost v2 data attributes to make every content element editable.

RULES:
1. Wrap every distinct section in <!-- outpost:sectionname --> / <!-- /outpost:sectionname --> comments. Name based on content: hero, features, about, testimonials, cta, steps, showcase, pricing, faq, team, contact, newsletter, footer, nav.

2. Add data-outpost="blockname_fieldname" to EVERY editable element:
   - ALL h1-h6 headings
   - ALL paragraphs with content
   - ALL images (add data-type="image")
   - ALL links/buttons with text (add data-type="link")
   - ALL divs/spans with short direct text (badges, labels, names, roles, dates)
   - ALL blockquotes
   - Field names MUST be prefixed with block name: hero_heading, features_title, cta_button — NEVER generic names like heading_2

3. Add data-type="richtext" for multi-sentence paragraphs, data-type="image" for images, data-type="link" for links/buttons.

3b. If a heading (h1-h6) contains inline HTML formatting like <em>, <strong>, <span>, <mark>, or <br>, it MUST be tagged as data-type="richtext" not plain text. Example: `<h1 data-outpost="hero_heading" data-type="richtext">Build templates <em>visually</em></h1>`

3c. ONLY use these data-type values: richtext, image, link, textarea, select, toggle, color, number, date. Do NOT invent types like "region", "filter", "button", "card", etc. If an element is plain text, omit data-type entirely (text is the default). Do NOT add data-type="region" or data-type to wrapper/container elements.

4. Add <!-- outpost-settings: --> inside blocks for visual properties:
   - Sections with dark/colored backgrounds: bg_color: color(#detected_hex)
   - Sections with layout options: layout: select(centered, left-aligned, split)
   - Optional sections: show_section: toggle(true)

5. Nav should be wrapped in <!-- outpost:nav global --> — don't tag individual nav menu links (menu system handles those), but DO tag the brand/logo and CTA buttons.

6. Footer should be wrapped in <!-- outpost:footer global -->

7. Replace <title> and <meta name="description"> with <outpost-meta title="..." description="..." />

8. Return ONLY the annotated HTML. No explanations, no markdown, no code fences.

9. When two or more consecutive `<p>` tags appear together in a section with no other elements between them, combine them into a SINGLE richtext field on a wrapper `<div>` element, not separate fields. Example:
WRONG: `<p data-outpost="desc_a">First paragraph</p><p data-outpost="desc_b">Second paragraph</p>`
RIGHT: `<div data-outpost="showcase_description" data-type="richtext"><p>First paragraph</p><p>Second paragraph</p></div>`

10. CRITICAL: Every <!-- outpost:name --> block name MUST be unique on the page. NEVER use the same block name twice. Name them based on content: <!-- outpost:showcase_detection --> and <!-- outpost:showcase_partials -->, NOT <!-- outpost:showcase --> twice. If there are two showcase sections, name them `<!-- outpost:showcase_smart_detection -->` and `<!-- outpost:showcase_partials -->` based on content.

11. Unordered and ordered lists (`<ul>`, `<ol>`) should NOT have data-outpost on individual `<li>` elements. Instead, leave the list as-is — lists will be handled as repeatable fields in the editor. Do NOT tag `<li>` items.

12. CRITICAL: When multiple `<p>` tags appear consecutively, they MUST be wrapped in a single `<div data-outpost="name" data-type="richtext">` container. NEVER give separate data-outpost attributes to consecutive paragraphs. Example:
WRONG: <p data-outpost='desc_a'>First</p><p data-outpost='desc_b'>Second</p>
RIGHT: <div data-outpost='showcase_description' data-type='richtext'><p>First</p><p>Second</p></div>

13. Add a human-readable data-label attribute to every data-outpost element. This label is what appears in the editor sidebar. It should be clean and descriptive without numbers. Examples:
- data-outpost='hero_heading' data-label='Heading'
- data-outpost='features_card_1_heading' data-label='Card Heading'
- data-outpost='testimonials_quote_2' data-label='Quote'
- data-outpost='showcase_detection_heading' data-label='Heading'
- data-outpost='cta_button' data-label='Button' data-type='link'
The data-label is stripped from public output just like data-outpost.
PROMPT;
}

/**
 * Call the AI provider with the forge prompt + HTML, return annotated HTML.
 */
function forge_ai_call(string $html, string $provider, string $apiKey, bool $refinement = false): string {
    $systemPrompt = $refinement ? forge_ai_refinement_prompt() : forge_ai_system_prompt();
    $userMessage = $refinement
        ? "Refine this pre-annotated HTML:\n\n" . $html
        : "Annotate this HTML:\n\n" . $html;

    switch ($provider) {
        case 'claude':
            return forge_ai_call_claude($userMessage, $apiKey, $systemPrompt);
        case 'openai':
            return forge_ai_call_openai($userMessage, $apiKey, $systemPrompt);
        case 'gemini':
            return forge_ai_call_gemini($userMessage, $apiKey, $systemPrompt);
        default:
            throw new RuntimeException("Unsupported AI provider: $provider");
    }
}

/**
 * Shorter refinement prompt for the hybrid approach.
 * The PHP scanner has already done the heavy lifting — AI just polishes.
 */
function forge_ai_refinement_prompt(): string {
    return <<<'PROMPT'
You are refining HTML that has already been annotated with Outpost CMS data-outpost attributes by an automated scanner. Your job is to IMPROVE the annotations, not start from scratch.

IMPROVE these things:
1. Rename generic fields (section_heading_2, section_body_3) to descriptive names based on context (features_conditional_heading, steps_select_description)
2. Add data-label="Human Label" to every data-outpost element — clean label without numbers
3. Make every <!-- outpost:name --> block comment UNIQUE — name based on content
4. Combine consecutive <p> tags with separate data-outpost attributes into ONE <div data-outpost="name" data-type="richtext"> wrapper
5. Remove data-outpost from individual <li> elements — lists should not be tagged
6. If headings contain <em>, <strong>, <span>, add data-type="richtext"

CRITICAL — CONVERT ALL LEGACY LIQUID SYNTAX to v2:
7. {% include 'name' %} → <outpost-include partial="name" />
8. {% for item in collection.slug %} → <outpost-each collection="slug"> and </outpost-each>
9. {% for item in menu.slug %} → <outpost-menu name="slug"> and </outpost-menu>
10. {% for item in repeater.slug %} → <outpost-each repeat="slug"> and </outpost-each>
11. {% for label in folder.slug %} → <outpost-each folder="slug"> and </outpost-each>
12. {% for img in gallery.slug %} → <outpost-each gallery="slug"> and </outpost-each>
13. {% single var from collection.slug %} → <outpost-single collection="slug"> and </outpost-single>
14. {% if field %} → <outpost-if field="field" exists> and </outpost-if>
15. {% if @global %} → <outpost-if field="global" scope="global" exists> and </outpost-if>
16. {% seo %} → <outpost-seo />
17. {% pagination %} → <outpost-pagination />
18. {% form 'slug' %} → <outpost-form slug="slug" />
19. {{ field }} or {{ field | filter }} → add data-outpost="field" to the containing element. Map the filter to data-type: raw→richtext, image→image, link→link, textarea→textarea, select→(omit, text default), toggle→toggle, color→color, number→number, date→date. If no filter or unknown filter, omit data-type (defaults to text).
20. {{ @global }} or {{ @global | filter }} → add data-outpost="global" data-scope="global" to the containing element
21. {{ meta.title }}Default{{ /meta.title }} → <outpost-meta title="Default" />
22. {# comment #} → <!-- comment --> or remove entirely
23. {{ item.field }} inside loops → add data-outpost="field" to the element
24. {% else %} after {% for %} → separate <outpost-each ... empty> block
25. {% else %} after {% single %} → <outpost-single ... else> block

DO NOT leave ANY {{ }}, {% %}, or {# #} syntax in the output. Convert EVERYTHING to v2.
DO NOT remove any existing data-outpost attributes. Only improve names, add labels, fix blocks, convert Liquid, and combine paragraphs.

Return ONLY the improved HTML. No explanations.
PROMPT;
}

function forge_ai_call_claude(string $userMessage, string $apiKey, string $systemPrompt): string {
    $body = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 16000,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $userMessage],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close not needed in PHP 8+

    if ($curlError) {
        throw new RuntimeException("Connection error: $curlError");
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
        throw new RuntimeException("Claude API error: $errMsg");
    }

    // Extract text from content blocks
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    if (empty($text)) {
        throw new RuntimeException('Claude returned empty response');
    }

    return $text;
}

function forge_ai_call_openai(string $userMessage, string $apiKey, string $systemPrompt): string {
    $body = [
        'model' => 'gpt-4o',
        'max_tokens' => 16384,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close not needed in PHP 8+

    if ($curlError) {
        throw new RuntimeException("Connection error: $curlError");
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
        throw new RuntimeException("OpenAI API error: $errMsg");
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty($text)) {
        throw new RuntimeException('OpenAI returned empty response');
    }

    return $text;
}

function forge_ai_call_gemini(string $userMessage, string $apiKey, string $systemPrompt): string {
    $body = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $userMessage]]],
        ],
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'generationConfig' => [
            'maxOutputTokens' => 65536,
        ],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close not needed in PHP 8+

    if ($curlError) {
        throw new RuntimeException("Connection error: $curlError");
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $errMsg = $data['error']['message'] ?? "HTTP $httpCode";
        throw new RuntimeException("Gemini API error: $errMsg");
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($text)) {
        throw new RuntimeException('Gemini returned empty response');
    }

    return $text;
}

/**
 * Handle GET forge/ai-status — returns AI availability.
 */
function handle_forge_ai_status(): void {
    json_response(forge_ai_status());
}

/**
 * Handle POST forge/ai-scan — AI-powered forge scan.
 */
function handle_forge_ai_scan(): void {
    // This call can take 30+ seconds — prevent timeouts
    set_time_limit(120);
    ignore_user_abort(true);
    if (ob_get_level()) ob_end_clean();

    $status = forge_ai_status();
    if (!$status['available']) {
        json_error('No AI API key configured. Add one in Settings > Ranger.', 400);
    }

    $body = get_json_body();
    $html = $body['html'] ?? '';
    if (empty($html)) {
        json_error('HTML content is required');
    }

    // ── HYBRID APPROACH: PHP scanner first, then AI refinement ──

    // Step 1: Run the PHP scanner to get a pre-annotated template
    $phpResult = smart_forge_scan($html, 'index.html');
    $preAnnotatedHtml = $phpResult['template'] ?? $html;

    // Step 2: Send the pre-annotated HTML to AI for refinement
    $provider = $status['provider'];
    $db = OutpostDB::connect();
    $keyRow = $db->query("SELECT value FROM settings WHERE key = 'ranger_api_key_$provider'")->fetch(\PDO::FETCH_ASSOC);
    if (!$keyRow || empty($keyRow['value'])) {
        json_error('API key not found for provider: ' . $provider);
    }

    try {
        require_once __DIR__ . '/ranger.php';
        $apiKey = ranger_decrypt($keyRow['value']);
    } catch (\Throwable $e) {
        json_error('Failed to decrypt API key: ' . $e->getMessage());
    }

    try {
        $annotatedHtml = forge_ai_call($preAnnotatedHtml, $provider, $apiKey, true);
    } catch (\Throwable $e) {
        json_error('AI scan failed: ' . $e->getMessage());
    }

    // Strip markdown code fences if AI wrapped its response
    $annotatedHtml = preg_replace('/^```html?\s*\n?/i', '', $annotatedHtml);
    $annotatedHtml = preg_replace('/\n?```\s*$/', '', $annotatedHtml);

    // Strip invalid data-type values (only allow known types)
    $annotatedHtml = preg_replace_callback(
        '/\s+data-type="([^"]*)"/',
        function ($m) {
            $valid = ['richtext', 'image', 'link', 'textarea', 'select', 'toggle', 'color', 'number', 'date'];
            return in_array($m[1], $valid) ? $m[0] : '';
        },
        $annotatedHtml
    );

    // Validate AI output is proper annotated HTML
    if (empty($annotatedHtml) || strlen($annotatedHtml) < 50) {
        json_error('AI returned empty or too-short response');
    }
    if (!str_contains($annotatedHtml, '<') || !str_contains($annotatedHtml, '>')) {
        json_error('AI returned non-HTML response');
    }
    if (!str_contains($annotatedHtml, 'data-outpost')) {
        json_error('AI response contains no Outpost annotations — try again');
    }

    json_response([
        'template' => $annotatedHtml,
        'provider' => $provider,
    ]);
}


// ── API Handlers ────────────────────────────────────────

function handle_forge_scan(): void {
    $body = get_json_body();

    $html = '';

    if (!empty($body['html'])) {
        // Direct HTML input
        $html = $body['html'];
    } elseif (!empty($body['path'])) {
        // Read from site root file
        $path = $body['path'];
        // Validate path — must be within site root, not inside outpost/
        $fullPath = OUTPOST_SITE_ROOT . ltrim($path, '/');
        $realPath = realpath($fullPath);
        $realSiteRoot = realpath(OUTPOST_SITE_ROOT);

        if (!$realPath || !$realSiteRoot || !str_starts_with($realPath, rtrim($realSiteRoot, '/'))) {
            json_error('Invalid file path');
        }
        // Block access to outpost engine files
        $realOutpost = realpath(OUTPOST_DIR);
        if ($realOutpost && (str_starts_with($realPath, rtrim($realOutpost, '/') . '/') || $realPath === rtrim($realOutpost, '/'))) {
            json_error('Cannot access outpost engine files');
        }
        if (!file_exists($realPath)) {
            json_error('File not found');
        }
        if (!str_ends_with(strtolower($realPath), '.html') && !str_ends_with(strtolower($realPath), '.htm')) {
            json_error('Only HTML files can be scanned');
        }

        $html = file_get_contents($realPath);
        if ($html === false) {
            json_error('Could not read file');
        }
    } else {
        json_error('Provide either "html" or "path"');
    }

    $filename = $body['path'] ?? '';
    $result = smart_forge_scan($html, $filename);

    json_response($result);
}

function handle_forge_apply(): void {
    $body = get_json_body();

    $template = $body['template'] ?? '';
    $fields = $body['fields'] ?? [];
    $themePath = $body['path'] ?? '';
    $pageId = (int)($body['page_id'] ?? 0);

    if (!$template) json_error('Template content is required');
    if (!$themePath) json_error('File path is required');

    // Validate path — must be within site root, not inside outpost/
    $fullPath = OUTPOST_SITE_ROOT . ltrim($themePath, '/');
    $realSiteRoot = realpath(OUTPOST_SITE_ROOT);
    // Allow new files — use dirname for validation
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        json_error('Directory not found');
    }
    $realDir = realpath($dir);
    if (!$realDir || !str_starts_with($realDir, rtrim($realSiteRoot, '/'))) {
        json_error('Invalid file path');
    }
    // Block access to outpost engine files
    $realOutpost = realpath(OUTPOST_DIR);
    if ($realOutpost && (str_starts_with($realDir, rtrim($realOutpost, '/') . '/') || $realDir === rtrim($realOutpost, '/'))) {
        json_error('Cannot access outpost engine files');
    }

    // If no page_id provided, derive from the template filename → page path
    if ($pageId <= 0) {
        $templateFile = basename($themePath, '.html');
        // Skip partials — they don't map to pages
        $isPartial = str_contains($themePath, '/partials/');
        if (!$isPartial) {
            $pagePath = $templateFile === 'index' ? '/' : '/' . $templateFile;
            $page = OutpostDB::fetchOne('SELECT id FROM pages WHERE path = ?', [$pagePath]);
            if ($page) {
                $pageId = (int) $page['id'];
            } else {
                $title = ucwords(str_replace(['/', '-', '_'], ' ', trim($pagePath, '/'))) ?: 'Home';
                $pageId = OutpostDB::insert('pages', ['path' => $pagePath, 'title' => $title]);
            }
        }
    }

    // 1. Write the template file
    $written = file_put_contents($fullPath, $template);
    if ($written === false) {
        json_error('Failed to write template file');
    }

    // 2. Register fields in the database (theme = '' — no theme layer)
    $fieldCount = 0;
    if ($pageId > 0 && !empty($fields)) {
        $db = OutpostDB::connect();

        $fieldTheme = '';

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            $type = $field['type'] ?? 'text';
            $selector = $field['selector'] ?? null;
            $sectionName = $field['section'] ?? null;
            $defaultValue = $field['default_value'] ?? '';

            if (!$name) continue;

            // Check if field already exists for this page
            $existing = OutpostDB::fetchOne(
                "SELECT id FROM fields WHERE page_id = ? AND theme = ? AND field_name = ?",
                [$pageId, $fieldTheme, $name]
            );

            if ($existing) {
                // Update selector and section
                OutpostDB::query(
                    "UPDATE fields SET css_selector = ?, section_name = ?, field_type = ? WHERE id = ?",
                    [$selector, $sectionName, $type, $existing['id']]
                );
            } else {
                // Insert new field with default value
                OutpostDB::query(
                    "INSERT INTO fields (page_id, field_name, content, field_type, theme, default_value, css_selector, section_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$pageId, $name, $defaultValue, $type, $fieldTheme, $defaultValue, $selector, $sectionName]
                );
            }
            $fieldCount++;
        }
    }

    // 3. Clear template cache
    $cacheDir = OUTPOST_CACHE_DIR . 'templates/';
    if (is_dir($cacheDir)) {
        $cacheFiles = glob($cacheDir . '*.php');
        if ($cacheFiles) {
            foreach ($cacheFiles as $file) {
                @unlink($file);
            }
        }
    }

    // Rebuild site manifest since template has changed
    require_once __DIR__ . '/theme-manifest.php';
    if (function_exists('outpost_build_site_manifest')) {
        outpost_build_site_manifest();
    } elseif (function_exists('outpost_build_theme_manifest')) {
        // Fallback: use legacy theme manifest builder with active theme
        require_once __DIR__ . '/themes.php';
        $activeTheme = get_active_theme();
        if ($activeTheme) {
            outpost_build_theme_manifest($activeTheme);
        }
    }

    json_response([
        'success' => true,
        'field_count' => $fieldCount,
        'template_path' => $themePath,
    ]);
}


// ── Editor Field Map API ────────────────────────────────

function handle_editor_field_map(): void {
    $pageId = (int)($_GET['page_id'] ?? 0);
    if (!$pageId) json_error('page_id required');

    // Load the site manifest
    require_once __DIR__ . '/theme-manifest.php';
    $manifest = outpost_get_theme_manifest();
    $manifestGlobals = $manifest['globals'] ?? [];

    // Look up this page's path to determine which fields the manifest expects
    $page = OutpostDB::fetchOne("SELECT path FROM pages WHERE id = ?", [$pageId]);
    $pagePath = $page ? $page['path'] : '';
    $manifestFields = [];
    $manifestBlocks = [];
    if ($pagePath && isset($manifest['pages'][$pagePath])) {
        $manifestFields = $manifest['pages'][$pagePath]['fields'] ?? [];
        $manifestBlocks = $manifest['pages'][$pagePath]['blocks'] ?? [];
    }

    // Return fields for this page. Try Smart-Forge fields first (have css_selector),
    // then fall back to template-engine fields (no css_selector).
    // All fields use theme = '' (no theme layer).
    // Exclude meta fields (those go in SEO panel).
    $fields = OutpostDB::fetchAll(
        "SELECT field_name, css_selector, section_name, field_type, content, default_value
         FROM fields
         WHERE page_id = ?
           AND css_selector IS NOT NULL
           AND css_selector != ''
           AND theme = ''
           AND field_type NOT IN ('meta_title', 'meta_description')
         ORDER BY sort_order ASC, id ASC",
        [$pageId]
    );

    // If no Smart Forge fields found, fall back to template-engine fields
    if (empty($fields)) {
        $fields = OutpostDB::fetchAll(
            "SELECT field_name, css_selector, section_name, field_type, content, default_value
             FROM fields
             WHERE page_id = ?
               AND theme = ''
               AND field_type NOT IN ('meta_title', 'meta_description')
             ORDER BY sort_order ASC, id ASC",
            [$pageId]
        );
    }
    if (!empty($fields)) {

        // Enrich with section info from page_field_registry if available
        if (!empty($fields)) {
            $regEntries = OutpostDB::fetchAll(
                "SELECT field_name, field_type FROM page_field_registry WHERE theme = '' AND path = ?",
                [$pagePath]
            );
            $regMap = [];
            foreach ($regEntries as $r) {
                $regMap[$r['field_name']] = $r;
            }
            foreach ($fields as &$rf) {
                // Use registry field_type if the fields row just says 'text' and registry knows better
                if (isset($regMap[$rf['field_name']]) && ($rf['field_type'] === 'text' || empty($rf['field_type']))) {
                    $rf['field_type'] = $regMap[$rf['field_name']]['field_type'];
                }
                // Default section for template-engine fields
                if (empty($rf['section_name'])) {
                    $rf['section_name'] = 'Content';
                }
            }
            unset($rf);
        }
    }

    // If manifest has fields for this page, filter to only those in the manifest
    if (!empty($manifestFields)) {
        $fields = array_values(array_filter($fields, fn($f) =>
            in_array($f['field_name'], $manifestFields)
        ));
    }

    // Extract data-label attributes from the template HTML for human-readable labels
    $templateLabels = [];
    if ($pagePath) {
        $siteRoot = OUTPOST_SITE_ROOT;
        $templateFile = '';
        if ($pagePath === '/') {
            $templateFile = $siteRoot . 'index.html';
        } else {
            $templateFile = $siteRoot . ltrim($pagePath, '/') . '.html';
        }
        if ($templateFile && file_exists($templateFile)) {
            $templateHtml = file_get_contents($templateFile);
            // Also check partials
            $partialsDir = $siteRoot . 'partials';
            if (is_dir($partialsDir)) {
                foreach (glob($partialsDir . '/*.html') as $partial) {
                    $templateHtml .= "\n" . file_get_contents($partial);
                }
            }
            if (preg_match_all('/data-outpost="([^"]+)"[^>]*?data-label="([^"]+)"/', $templateHtml, $lm, PREG_SET_ORDER)) {
                foreach ($lm as $match) {
                    $templateLabels[$match[1]] = $match[2];
                }
            }
            // Also match data-label before data-outpost (attribute order may vary)
            if (preg_match_all('/data-label="([^"]+)"[^>]*?data-outpost="([^"]+)"/', $templateHtml, $lm2, PREG_SET_ORDER)) {
                foreach ($lm2 as $match) {
                    $templateLabels[$match[2]] = $match[1];
                }
            }
        }
    }

    $map = [];
    $sections = [];
    foreach ($fields as &$f) {
        if ($f['css_selector']) {
            $map[$f['field_name']] = $f['css_selector'];
        }
        if ($f['section_name'] && !in_array($f['section_name'], $sections)) {
            $sections[] = $f['section_name'];
        }
        // Use data-label from template if available, otherwise fall back to humanized name
        $f['label'] = $templateLabels[$f['field_name']] ?? smart_forge_human_label($f['field_name']);
        // Mark global fields
        $f['is_global'] = in_array($f['field_name'], $manifestGlobals);
    }
    unset($f);

    // Also include global fields from the globals page that are in the manifest
    $globalFields = [];
    if (!empty($manifestGlobals)) {
        $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if ($globalPage) {
            $gFields = OutpostDB::fetchAll(
                "SELECT field_name, '' as css_selector, 'Globals' as section_name, field_type, content, default_value
                 FROM fields
                 WHERE page_id = ? AND theme = ''
                 ORDER BY sort_order ASC, id ASC",
                [(int)$globalPage['id']]
            );
            foreach ($gFields as &$gf) {
                if (in_array($gf['field_name'], $manifestGlobals)) {
                    $gf['label'] = $templateLabels[$gf['field_name']] ?? smart_forge_human_label($gf['field_name']);
                    $gf['is_global'] = true;
                    $globalFields[] = $gf;
                }
            }
            unset($gf);
        }
    }

    json_response([
        'field_map' => $map,
        'sections' => $sections,
        'fields' => $fields,
        'global_fields' => $globalFields,
        'global_page_id' => isset($globalPage) ? (int)$globalPage['id'] : null,
        'manifest_globals' => $manifestGlobals,
        'blocks' => $manifestBlocks,
    ]);
}


// ── Editor Active Users ─────────────────────────────────

function handle_editor_save_field(): void {
    $body = get_json_body();
    $pageId = (int)($body['page_id'] ?? 0);
    $fieldName = $body['field_name'] ?? '';
    $content = $body['content'] ?? '';

    if (!$pageId || !$fieldName) {
        json_error('page_id and field_name are required');
    }

    $db = OutpostDB::connect();

    // Find the field and update it
    $field = OutpostDB::fetchOne(
        "SELECT id FROM fields WHERE page_id = ? AND field_name = ?",
        [$pageId, $fieldName]
    );

    if ($field) {
        $db->prepare("UPDATE fields SET content = ?, updated_at = datetime('now') WHERE id = ?")->execute([$content, $field['id']]);
    } else {
        // Field doesn't exist yet — create it
        $db->prepare("INSERT INTO fields (page_id, field_name, content, field_type, theme, updated_at) VALUES (?, ?, ?, 'text', '', datetime('now'))")->execute([$pageId, $fieldName, $content]);
    }

    // Clear page cache
    $page = OutpostDB::fetchOne("SELECT path FROM pages WHERE id = ?", [$pageId]);
    if ($page && $page['path']) {
        $cachePath = OUTPOST_CACHE_DIR . md5($page['path']) . '.html';
        if (file_exists($cachePath)) @unlink($cachePath);
    }

    json_response(['success' => true]);
}

function handle_editor_active_users(): void {
    $pageId = (int)($_GET['page_id'] ?? 0);
    if (!$pageId) json_error('page_id required');

    ensure_editor_sessions_table();

    // Clean up stale sessions (older than 60 seconds)
    OutpostDB::query(
        "DELETE FROM editor_sessions WHERE last_seen < datetime('now', '-60 seconds')"
    );

    // Get active users on this page
    try {
        $sessions = OutpostDB::fetchAll(
            "SELECT es.user_id, COALESCE(u.display_name, u.username) as name, u.email
             FROM editor_sessions es
             JOIN users u ON u.id = es.user_id
             WHERE es.page_id = ?",
            [$pageId]
        );
    } catch (\Throwable $e) {
        // Log but don't crash — active users is non-critical
        error_log('editor/active-users error: ' . $e->getMessage());
        json_response(['users' => []]);
        return;
    }

    json_response(['users' => $sessions]);
}

function handle_editor_heartbeat(): void {
    $body = get_json_body();
    $pageId = (int)($body['page_id'] ?? 0);
    if (!$pageId) json_error('page_id required');

    ensure_editor_sessions_table();

    $userId = OutpostAuth::userId();

    // Upsert session
    $existing = OutpostDB::fetchOne(
        "SELECT id FROM editor_sessions WHERE user_id = ? AND page_id = ?",
        [$userId, $pageId]
    );

    if ($existing) {
        OutpostDB::query(
            "UPDATE editor_sessions SET last_seen = datetime('now') WHERE id = ?",
            [$existing['id']]
        );
    } else {
        // Remove old sessions for this user (they navigated away)
        OutpostDB::query("DELETE FROM editor_sessions WHERE user_id = ?", [$userId]);
        OutpostDB::insert('editor_sessions', [
            'user_id' => $userId,
            'page_id' => $pageId,
        ]);
    }

    json_response(['success' => true]);
}

// ── Editor Media Lookup (by path) ────────────────────────

function handle_editor_media_lookup(): void {
    $path = trim($_GET['path'] ?? '');
    if (!$path) json_error('path required');

    $media = OutpostDB::fetchOne(
        "SELECT id, path, alt_text, original_name, mime_type, thumb_path FROM media WHERE path = ?",
        [$path]
    );

    json_response(['media' => $media ?: null]);
}

function handle_editor_media_alt_update(): void {
    $body = get_json_body();
    $path = trim($body['path'] ?? '');
    $altText = trim($body['alt_text'] ?? '');

    if (!$path) json_error('path required');

    $media = OutpostDB::fetchOne("SELECT id FROM media WHERE path = ?", [$path]);
    if (!$media) json_error('Media not found', 404);

    OutpostDB::update('media', ['alt_text' => $altText], 'id = ?', [$media['id']]);
    json_response(['success' => true]);
}


// ── Block Settings API ──────────────────────────────────

/**
 * GET editor/block-settings?page_id=X
 * Parse <!-- outpost-settings: ... --> comments from the template file for this page.
 * Also detect <outpost-each collection="..."> loop attributes.
 * Returns settings definitions with current DB values.
 */
function handle_editor_block_settings_get(): void {
    $pageId = (int)($_GET['page_id'] ?? 0);
    if (!$pageId) json_error('page_id required');

    // Get page path to find template file
    $page = OutpostDB::fetchOne("SELECT path FROM pages WHERE id = ?", [$pageId]);
    if (!$page) json_error('Page not found');
    $pagePath = $page['path'];

    // Map page path to template file (site root)
    $templateFile = ($pagePath === '/') ? 'index.html' : ltrim($pagePath, '/') . '.html';
    $templateFullPath = OUTPOST_SITE_ROOT . $templateFile;

    $allSettings = [];
    $loopSettings = [];

    if (file_exists($templateFullPath)) {
        $html = file_get_contents($templateFullPath);

        // Parse settings from this file
        $allSettings = _parse_template_block_settings($html);

        // Parse outpost-each loop attributes
        $loopSettings = _parse_template_loop_settings($html);

        // Also parse included partials for settings
        if (preg_match_all('/\{%\s*include\s+[\'"]([^"\']+)[\'"]\s*%\}/', $html, $includes)) {
            foreach ($includes[1] as $partial) {
                $partialPath = OUTPOST_SITE_ROOT . 'partials/' . $partial . '.html';
                if (file_exists($partialPath)) {
                    $partialHtml = file_get_contents($partialPath);
                    $partialSettings = _parse_template_block_settings($partialHtml);
                    $allSettings = array_merge($allSettings, $partialSettings);
                    $partialLoops = _parse_template_loop_settings($partialHtml);
                    $loopSettings = array_merge($loopSettings, $partialLoops);
                }
            }
        }

        // Also check for v2 template includes: <outpost-include partial="...">
        if (preg_match_all('/<\s*include\s+src=[\'"]([^"\']+)[\'"]\s*\/?>/i', $html, $v2includes)) {
            foreach ($v2includes[1] as $partial) {
                $partialPath = OUTPOST_SITE_ROOT . 'partials/' . $partial;
                if (!str_ends_with($partialPath, '.html')) $partialPath .= '.html';
                if (file_exists($partialPath)) {
                    $partialHtml = file_get_contents($partialPath);
                    $partialSettings = _parse_template_block_settings($partialHtml);
                    $allSettings = array_merge($allSettings, $partialSettings);
                    $partialLoops = _parse_template_loop_settings($partialHtml);
                    $loopSettings = array_merge($loopSettings, $partialLoops);
                }
            }
        }
    }

    // Load current DB values for each setting
    foreach ($allSettings as &$setting) {
        $fieldName = 'setting_' . $setting['block'] . '_' . $setting['name'];
        $row = OutpostDB::fetchOne(
            "SELECT content FROM fields WHERE page_id = ? AND field_name = ?",
            [$pageId, $fieldName]
        );
        $setting['value'] = $row ? $row['content'] : $setting['default'];
    }
    unset($setting);

    // Load current DB values for loop settings
    foreach ($loopSettings as &$ls) {
        $fieldName = 'setting_' . $ls['block'] . '_' . $ls['name'];
        $row = OutpostDB::fetchOne(
            "SELECT content FROM fields WHERE page_id = ? AND field_name = ?",
            [$pageId, $fieldName]
        );
        $ls['value'] = $row ? $row['content'] : $ls['default'];
    }
    unset($ls);

    // Merge loop settings into allSettings
    $allSettings = array_merge($allSettings, $loopSettings);

    // Get collections list (for collection dropdowns)
    $collections = OutpostDB::fetchAll("SELECT slug, name FROM collections ORDER BY name ASC");

    json_response([
        'settings' => array_values($allSettings),
        'collections' => $collections,
    ]);
}

/**
 * PUT editor/block-settings
 * Save a block setting value to the fields table.
 * Body: { page_id, block, name, value }
 */
function handle_editor_block_settings_save(): void {
    $body = get_json_body();
    $pageId = (int)($body['page_id'] ?? 0);
    $block = $body['block'] ?? '';
    $name = $body['name'] ?? '';
    $value = $body['value'] ?? '';

    if (!$pageId || !$block || !$name) {
        json_error('page_id, block, and name are required');
    }

    $fieldName = 'setting_' . $block . '_' . $name;
    $db = OutpostDB::connect();

    $existing = OutpostDB::fetchOne(
        "SELECT id FROM fields WHERE page_id = ? AND field_name = ?",
        [$pageId, $fieldName]
    );

    if ($existing) {
        $db->prepare("UPDATE fields SET content = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$value, $existing['id']]);
    } else {
        // Determine field_type from the setting type
        $fieldType = 'text';
        if (str_contains($name, 'color') || str_contains($name, 'bg_')) {
            $fieldType = 'color';
        } elseif (str_contains($name, 'image')) {
            $fieldType = 'image';
        } elseif ($name === 'loop_collection') {
            $fieldType = 'select';
        }

        $db->prepare(
            "INSERT INTO fields (page_id, field_name, content, field_type, section_name, theme, updated_at)
             VALUES (?, ?, ?, ?, ?, '', datetime('now'))"
        )->execute([$pageId, $fieldName, $value, $fieldType, 'settings:' . $block]);
    }

    // Clear page cache
    $page = OutpostDB::fetchOne("SELECT path FROM pages WHERE id = ?", [$pageId]);
    if ($page && $page['path']) {
        $cachePath = OUTPOST_CACHE_DIR . md5($page['path']) . '.html';
        if (file_exists($cachePath)) @unlink($cachePath);
    }

    json_response(['success' => true]);
}

/**
 * Parse <!-- outpost-settings: ... --> comments from HTML content.
 * Returns flat array of setting definitions with block context.
 */
function _parse_template_block_settings(string $html): array {
    $settings = [];

    // Build a map of block comment positions
    $blockPositions = [];
    if (preg_match_all('/<!--\s*outpost:(\w+)(?:\s+global)?\s*-->/', $html, $bm, PREG_OFFSET_CAPTURE)) {
        foreach ($bm[1] as $i => $match) {
            $blockPositions[$bm[0][$i][1]] = $match[0];
        }
    }

    // Find all outpost-settings comments
    if (preg_match_all('/<!--\s*outpost-settings:\s*(.*?)\s*-->/is', $html, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $i => $match) {
            $settingsRaw = trim($match[0]);
            $settingsOffset = $matches[0][$i][1];

            // Find the nearest preceding block comment
            $blockName = 'page';
            foreach ($blockPositions as $pos => $name) {
                if ($pos < $settingsOffset) {
                    $blockName = $name;
                }
            }

            // Parse each setting line
            $lines = preg_split('/\r?\n/', $settingsRaw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;

                if (preg_match('/^(\w+)\s*:\s*(\w+)(?:\(([^)]*)\))?$/', $line, $m)) {
                    $sName = $m[1];
                    $type = $m[2];
                    $args = isset($m[3]) ? $m[3] : '';

                    $def = [
                        'block' => $blockName,
                        'name' => $sName,
                        'type' => $type,
                        'default' => '',
                        'options' => [],
                    ];

                    switch ($type) {
                        case 'color':
                            $def['default'] = $args ?: '#000000';
                            break;
                        case 'range':
                            $parts = array_map('trim', explode(',', $args));
                            $def['min'] = (int)($parts[0] ?? 0);
                            $def['max'] = (int)($parts[1] ?? 100);
                            $def['default'] = $parts[2] ?? '50';
                            break;
                        case 'select':
                            $def['options'] = array_map('trim', explode(',', $args));
                            $def['default'] = $def['options'][0] ?? '';
                            break;
                        case 'toggle':
                            $def['default'] = ($args === 'true') ? 'true' : 'false';
                            break;
                        case 'text':
                            $def['default'] = $args;
                            break;
                        case 'number':
                            $def['default'] = $args ?: '0';
                            break;
                        case 'image':
                            $def['default'] = '';
                            break;
                        case 'collection':
                            $def['default'] = $args ?: '';
                            break;
                        default:
                            $def['default'] = $args;
                    }

                    $settings[] = $def;
                }
            }
        }
    }

    return $settings;
}

/**
 * Parse <outpost-each collection="..." limit="..." sort="..." order="..."> tags
 * and expose their attributes as editable settings.
 */
function _parse_template_loop_settings(string $html): array {
    $settings = [];

    $blockPositions = [];
    if (preg_match_all('/<!--\s*outpost:(\w+)(?:\s+global)?\s*-->/', $html, $bm, PREG_OFFSET_CAPTURE)) {
        foreach ($bm[1] as $i => $match) {
            $blockPositions[$bm[0][$i][1]] = $match[0];
        }
    }

    if (preg_match_all('/<outpost-each\s+([^>]*collection="[^"]*"[^>]*)>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $i => $fullMatch) {
            $attrs = $fullMatch[0];
            $offset = $fullMatch[1];

            // Determine block context
            $blockName = 'page';
            foreach ($blockPositions as $pos => $name) {
                if ($pos < $offset) {
                    $blockName = $name;
                }
            }

            // Parse attributes
            $collection = '';
            $limit = '';
            $sort = '';
            $order = '';

            if (preg_match('/collection="([^"]*)"/', $attrs, $am)) $collection = $am[1];
            if (preg_match('/limit="([^"]*)"/', $attrs, $am)) $limit = $am[1];
            if (preg_match('/sort="([^"]*)"/', $attrs, $am)) $sort = $am[1];
            if (preg_match('/order="([^"]*)"/', $attrs, $am)) $order = $am[1];

            $settings[] = [
                'block' => $blockName,
                'name' => 'loop_collection',
                'type' => 'collection',
                'default' => $collection,
                'options' => [],
            ];

            $settings[] = [
                'block' => $blockName,
                'name' => 'loop_limit',
                'type' => 'number',
                'default' => $limit ?: '10',
                'options' => [],
            ];

            $settings[] = [
                'block' => $blockName,
                'name' => 'loop_sort',
                'type' => 'select',
                'default' => $sort ?: 'created_at',
                'options' => ['created_at', 'updated_at', 'title', 'sort_order'],
            ];

            $settings[] = [
                'block' => $blockName,
                'name' => 'loop_order',
                'type' => 'select',
                'default' => $order ?: 'desc',
                'options' => ['desc', 'asc'],
            ];
        }
    }

    return $settings;
}


// ── Database Migrations ─────────────────────────────────

function ensure_smart_forge_columns(): void {
    $db = OutpostDB::connect();
    $cols = $db->query("PRAGMA table_info(fields)")->fetchAll(\PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('css_selector', $colNames)) {
        $db->exec("ALTER TABLE fields ADD COLUMN css_selector TEXT DEFAULT NULL");
    }
    if (!in_array('section_name', $colNames)) {
        $db->exec("ALTER TABLE fields ADD COLUMN section_name TEXT DEFAULT NULL");
    }
}

function ensure_editor_sessions_table(): void {
    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS editor_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            page_id INTEGER NOT NULL,
            last_seen TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
}


// ── Ranger Tool ─────────────────────────────────────────

function ranger_tool_forge_scan(array $input): array {
    $path = $input['path'] ?? '';
    if (!$path) return ['error' => 'path is required'];

    // Validate path — must be within site root, not inside outpost/
    $fullPath = OUTPOST_SITE_ROOT . ltrim($path, '/');
    $realPath = realpath($fullPath);
    $realSiteRoot = realpath(OUTPOST_SITE_ROOT);

    if (!$realPath || !$realSiteRoot || !str_starts_with($realPath, rtrim($realSiteRoot, '/'))) {
        return ['error' => 'Invalid file path'];
    }
    $realOutpost = realpath(OUTPOST_DIR);
    if ($realOutpost && (str_starts_with($realPath, rtrim($realOutpost, '/') . '/') || $realPath === rtrim($realOutpost, '/'))) {
        return ['error' => 'Cannot access outpost engine files'];
    }
    if (!file_exists($realPath)) {
        return ['error' => 'File not found: ' . $path];
    }

    $html = file_get_contents($realPath);
    if ($html === false) {
        return ['error' => 'Could not read file'];
    }

    $result = smart_forge_scan($html, $path);

    // Summarize for AI — full result is too large
    return [
        'success' => true,
        'path' => $path,
        'field_count' => count($result['fields']),
        'sections' => $result['sections'],
        'repeater_count' => count($result['repeaters']),
        'suggested_partials' => array_map(fn($p) => $p['name'], $result['suggested_partials']),
        'fields' => array_map(fn($f) => [
            'name' => $f['name'],
            'type' => $f['type'],
            'section' => $f['section'],
            'label' => $f['label'] ?? smart_forge_human_label($f['name']),
        ], $result['fields']),
    ];
}
