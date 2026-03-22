<?php
/**
 * Outpost CMS — Template Engine v2 (Data Attribute Architecture)
 *
 * Compiles HTML templates with data-outpost attributes and <outpost-*> custom
 * elements into cached PHP files. Two output modes:
 *   - public:  strips ALL Outpost syntax → clean semantic HTML
 *   - editor:  keeps data attributes + block comments for click-to-edit bridge
 *
 * Syntax overview:
 *   <h1 data-outpost="headline">Default</h1>         → page-scoped text field
 *   <div data-outpost="body" data-type="richtext">    → richtext field
 *   <img data-outpost="photo" data-type="image" />    → image field (sets src)
 *   <a data-outpost="cta" data-type="link" href="#">   → link field (sets href)
 *   <span data-outpost="name" data-scope="global">    → global field
 *
 *   <!-- outpost:hero -->...<!-- /outpost:hero -->     → block grouping (page-scoped)
 *   <!-- outpost:footer global -->...<!-- /outpost:footer --> → global block
 *   <!-- outpost-settings: bg: color(#fff) -->         → block settings → CSS vars
 *
 *   <outpost-each collection="post" limit="6">        → collection loop
 *   <outpost-each collection="post" empty>             → empty fallback
 *   <outpost-each repeat="skills">                     → repeater loop
 *   <outpost-each folder="categories" collection="post"> → folder/taxonomy loop
 *   <outpost-single collection="post">                 → single item by slug
 *   <outpost-single collection="post" else>            → not-found fallback
 *   <outpost-if field="x" is="true">                   → conditional
 *   <outpost-include partial="nav" />                  → include partial
 *   <outpost-menu name="main">                         → menu loop
 *   <outpost-meta title="Default" />                   → SEO meta block
 *   <outpost-seo />                                    → full SEO block (title + OG + JSON-LD)
 *   <outpost-pagination />                              → pagination controls
 *   <outpost-each gallery="name">                       → gallery loop
 *
 *   data-bind="attr:field" on loop items                → set HTML attribute from item data
 *     e.g. <time data-outpost="published_at" data-bind="datetime:published_at">
 */

class OutpostTemplateV2 {

    /** @var string Active theme directory (absolute, with trailing slash) */
    private static string $themeDir = '';

    /** @var bool If true, keep data attributes in output (editor preview) */
    private static bool $editorMode = false;

    /**
     * Render a template file. Compiles to PHP cache on first run, then includes cached version.
     */
    public static function render(string $templateFile, string $themeDir, bool $editorMode = false): void {
        self::$themeDir = rtrim($themeDir, '/') . '/';
        self::$editorMode = $editorMode;

        $cacheDir = OUTPOST_CACHE_DIR . 'templates/';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

        // Cache key includes theme dir + file path + editor mode
        $relPath  = str_replace(self::$themeDir, '', $templateFile);
        $cacheKey = md5($themeDir . '/' . $relPath . ($editorMode ? ':editor' : ':public'));
        $cacheFile = $cacheDir . $cacheKey . '.php';

        // Recompile if source or any partial is newer than cache
        $needsRecompile = !file_exists($cacheFile) || filemtime($templateFile) > filemtime($cacheFile);
        if (!$needsRecompile && file_exists($cacheFile)) {
            // Check if any partial is newer than the cache
            $partialsDir = self::$themeDir . 'partials';
            if (is_dir($partialsDir)) {
                $cacheMtime = filemtime($cacheFile);
                foreach (glob($partialsDir . '/*.html') as $partial) {
                    if (filemtime($partial) > $cacheMtime) {
                        $needsRecompile = true;
                        break;
                    }
                }
            }
        }
        if ($needsRecompile) {
            $source = file_get_contents($templateFile);
            $compiled = self::compile($source, $editorMode);
            // Prepend execution guard to prevent direct access
            $guarded = "<?php if (!defined('OUTPOST_VERSION')) exit; ?>" . $compiled;
            file_put_contents($cacheFile, $guarded, LOCK_EX);
        }

        // Execute the compiled template
        include $cacheFile;
    }

    /**
     * Compile an HTML template string into PHP code.
     * If $themeDir is provided, it overrides the class property (for standalone compile calls).
     */
    public static function compile(string $html, bool $editorMode = false, string $themeDir = ''): string {
        if ($themeDir) {
            self::$themeDir = rtrim($themeDir, '/') . '/';
        }
        // Step 1: Process includes first (recursive, depth-limited)
        $html = self::processIncludes($html, 0);
        // Clean up any orphaned closing tags from custom elements
        $html = preg_replace('/<\/outpost-(?:include|meta|seo|pagination|form)>/i', '', $html);

        // Step 2: Process <outpost-seo /> and <outpost-meta> tags
        $html = self::compileSeo($html);
        $html = self::compileMeta($html, $editorMode);

        // Step 2b: Process <outpost-pagination />
        $html = self::compilePagination($html);

        // Step 2c: Process <outpost-form slug="..." />
        $html = self::compileForms($html);

        // Step 3: Process block comments and settings
        $html = self::compileBlocks($html, $editorMode);

        // Step 4: Process <outpost-menu> elements
        $html = self::compileMenus($html, $editorMode);

        // Step 5: Process <outpost-each> elements (collections, repeaters, folders)
        $html = self::compileEachLoops($html, $editorMode);

        // Step 6: Process <outpost-single> elements
        $html = self::compileSingles($html, $editorMode);

        // Step 6b: Process <outpost-lodge-*> elements (member portal)
        $html = self::compileLodgeTags($html, $editorMode);

        // Step 6c: Process <outpost-compass*> elements (smart filtering)
        $html = self::compileCompass($html, $editorMode);

        // Step 7: Process <outpost-if> elements
        $html = self::compileConditionals($html, $editorMode);

        // Step 8: Process data-outpost attributes on elements
        $html = self::compileFields($html, $editorMode);

        return $html;
    }

    // ─── Includes ────────────────────────────────────────────

    /**
     * Replace <outpost-include partial="name" /> with the partial file contents.
     */
    private static function processIncludes(string $html, int $depth): string {
        if ($depth > 10) return $html; // prevent infinite recursion

        return preg_replace_callback(
            '/<outpost-include\s+partial="([^"]+)"\s*\/?\s*>(?:<\/outpost-include>)?/i',
            function ($m) use ($depth) {
                $name = $m[1];
                // Security: reject path traversal attempts
                if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
                    return '<!-- invalid partial name -->';
                }
                $name = basename($name); // extra safety
                $file = self::$themeDir . 'partials/' . $name . '.html';
                if (!file_exists($file)) {
                    return '<!-- partial not found: ' . htmlspecialchars($name) . ' -->';
                }
                // Verify resolved path is inside the theme directory
                $realFile = realpath($file);
                $realTheme = realpath(self::$themeDir);
                if (!$realFile || !$realTheme || !str_starts_with($realFile, $realTheme)) {
                    return '<!-- invalid partial path -->';
                }
                $content = file_get_contents($file);
                // Recursively process includes in the partial
                return self::processIncludes($content, $depth + 1);
            },
            $html
        );
    }

    // ─── Meta / SEO ──────────────────────────────────────────

    /**
     * Replace <outpost-meta title="..." description="..." image="..." />
     * with a PHP call to cms_seo() or manual meta output.
     */
    private static function compileMeta(string $html, bool $editorMode): string {
        return preg_replace_callback(
            '/<outpost-meta\s+([^>]*?)\/?\s*>(?:<\/outpost-meta>)?/is',
            function ($m) use ($editorMode) {
                $attrs = self::parseAttributes($m[1]);
                $title = self::phpString($attrs['title'] ?? '');
                $desc  = self::phpString($attrs['description'] ?? '');
                $image = self::phpString($attrs['image'] ?? '');

                // Use the built-in SEO system
                return '<?php cms_seo(); ?>';
            },
            $html
        );
    }

    /**
     * Replace <outpost-seo /> with full SEO block output.
     */
    private static function compileSeo(string $html): string {
        return preg_replace(
            '/<outpost-seo\s*\/?\s*>(?:<\/outpost-seo>)?/i',
            '<?php cms_seo(); ?>',
            $html
        );
    }

    /**
     * Replace <outpost-pagination /> with pagination controls.
     */
    private static function compilePagination(string $html): string {
        return preg_replace(
            '/<outpost-pagination\s*\/?\s*>(?:<\/outpost-pagination>)?/i',
            '<?php cms_pagination(); ?>',
            $html
        );
    }

    /**
     * Replace <outpost-form slug="name" /> with rendered form output.
     */
    private static function compileForms(string $html): string {
        return preg_replace_callback(
            '/<outpost-form\s+slug="([^"]+)"\s*\/?\s*(?:><\/outpost-form>)?/i',
            function ($m) {
                $slug = $m[1];
                return "<?php require_once __DIR__ . '/forms-engine.php'; cms_form('" . addslashes($slug) . "'); ?>";
            },
            $html
        );
    }

    // ─── Block Comments ──────────────────────────────────────

    /**
     * Process <!-- outpost:blockname --> and <!-- outpost:blockname global --> comments.
     * Also process <!-- outpost-settings: ... --> within blocks.
     *
     * In public mode: strip the comments entirely.
     * In editor mode: keep them for the bridge JS to read.
     */
    private static function compileBlocks(string $html, bool $editorMode): string {
        // Process settings comments first — they inject CSS vars onto the next element
        $html = self::compileBlockSettings($html, $editorMode);

        if (!$editorMode) {
            // Public mode: strip block open/close comments
            $html = preg_replace('/<!--\s*outpost:[\w-]+(?:\s+global)?\s*-->\n?/i', '', $html);
            $html = preg_replace('/<!--\s*\/outpost:[\w-]+\s*-->\n?/i', '', $html);
        } else {
            // Editor mode: inject data-outpost-block attribute on the first HTML element
            // after each <!-- outpost:blockname --> comment for click-to-edit bridge.
            // Match the comment followed by whitespace, then the first opening tag up to
            // its first space or >.
            $html = preg_replace_callback(
                '/(<!--\s*outpost:([\w-]+)(?:\s+global)?\s*-->)(.*?)(<(\w+)[\s>])/is',
                function ($m) {
                    $comment = $m[1];
                    $blockName = $m[2];
                    $between = $m[3]; // settings PHP, whitespace, etc.
                    $tagStart = $m[4]; // e.g. "<section" or "<div"
                    // Insert data-outpost-block after the tag name
                    return $comment . $between . $tagStart . ' data-outpost-block="' . htmlspecialchars($blockName, ENT_QUOTES, 'UTF-8') . '"';
                },
                $html
            );
        }

        return $html;
    }

    /**
     * Process <!-- outpost-settings: ... --> comments.
     * Parses settings definitions and generates PHP code that outputs
     * CSS custom properties as a style attribute on the parent element.
     */
    private static function compileBlockSettings(string $html, bool $editorMode): string {
        // First, build a map of settings comment positions to their parent block names
        // by finding the nearest preceding <!-- outpost:blockname --> comment on the ORIGINAL html
        $blockNames = [];
        preg_match_all('/<!--\s*outpost:([\w-]+)(?:\s+global)?\s*-->/', $html, $blockMatches, PREG_OFFSET_CAPTURE);
        foreach ($blockMatches[0] as $i => $match) {
            $blockNames[] = ['name' => $blockMatches[1][$i][0], 'pos' => $match[1]];
        }

        // Pre-build a map of settings comment → block name using positions from the ORIGINAL string
        // (Issue 11 fix: avoid strpos on mutated string)
        $settingsBlockMap = [];
        preg_match_all('/<!--\s*outpost-settings:\s*(.*?)\s*-->/is', $html, $settingsMatches, PREG_OFFSET_CAPTURE);
        foreach ($settingsMatches[0] as $i => $match) {
            $settingsPos = $match[1];
            $blockName = 'page'; // default if not inside a block
            foreach ($blockNames as $bn) {
                if ($bn['pos'] < $settingsPos) {
                    $blockName = $bn['name'];
                }
            }
            $settingsBlockMap[$match[0]] = $blockName;
        }

        // Match outpost-settings comment blocks (can be multiline)
        $html = preg_replace_callback(
            '/<!--\s*outpost-settings:\s*(.*?)\s*-->/is',
            function ($m) use ($editorMode, $settingsBlockMap) {
                $settingsRaw = trim($m[1]);
                $settings = self::parseSettingsDefinitions($settingsRaw);

                if (empty($settings)) return '';

                // Use pre-built map (Issue 11 fix: no strpos on mutated string)
                $blockName = $settingsBlockMap[$m[0]] ?? 'page';
                $blockPrefix = str_replace('-', '_', $blockName);

                // Generate PHP code that reads setting values and outputs style/data attrs
                $php = '<?php ' . "\n";
                $php .= '$_outpost_block_settings = [];' . "\n";
                $php .= '$_outpost_block_data_attrs = [];' . "\n";

                foreach ($settings as $name => $def) {
                    // Use prefixed field name matching the API: setting_blockname_fieldname
                    $dbFieldName = self::phpString('setting_' . $blockPrefix . '_' . $name);
                    $default = self::phpString($def['default']);
                    $type = $def['type'];

                    $php .= '$_sv_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . ' = ';

                    if ($type === 'select') {
                        $php .= 'outpost_resolve_field(' . $dbFieldName . ', \'select\', ' . $default . ');' . "\n";
                        // Select values go as data attributes, not CSS vars
                        $php .= '$_outpost_block_data_attrs[\'data-' . htmlspecialchars($name) . '\'] = $_sv_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . ';' . "\n";
                    } elseif ($type === 'toggle') {
                        $php .= 'cms_toggle(' . $dbFieldName . ', ' . ($def['default'] === 'true' ? 'true' : 'false') . ') ? \'1\' : \'0\';' . "\n";
                        $php .= '$_outpost_block_data_attrs[\'data-' . htmlspecialchars($name) . '\'] = $_sv_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . ';' . "\n";
                    } elseif ($type === 'image') {
                        $php .= 'outpost_resolve_field(' . $dbFieldName . ', \'image\', \'\');' . "\n";
                        $php .= 'if ($_sv_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . ' !== \'\') {' . "\n";
                        $php .= '  $_outpost_block_settings[\'--' . htmlspecialchars($name) . '\'] = \'url(\\\'\' . htmlspecialchars($_sv_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . ', ENT_QUOTES, \'UTF-8\') . \'\\\')\';' . "\n";
                        $php .= '}' . "\n";
                    } else {
                        // color, range, number, text — all become CSS vars
                        $php .= 'outpost_resolve_field(' . $dbFieldName . ', \'text\', ' . $default . ');' . "\n";
                        $php .= '$_outpost_block_settings[\'--' . htmlspecialchars($name) . '\'] = htmlspecialchars($_sv_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . ', ENT_QUOTES, \'UTF-8\');' . "\n";
                    }
                }

                // Build a combined style string and data attrs to inject
                $php .= '$_outpost_settings_style = \'\';' . "\n";
                $php .= 'foreach ($_outpost_block_settings as $_k => $_v) {' . "\n";
                $php .= '  $_outpost_settings_style .= $_k . \':\' . $_v . \'; \';' . "\n";
                $php .= '}' . "\n";
                $php .= '$_outpost_settings_data = \'\';' . "\n";
                $php .= 'foreach ($_outpost_block_data_attrs as $_k => $_v) {' . "\n";
                $php .= '  $_outpost_settings_data .= \' \' . $_k . \'="\' . htmlspecialchars($_v, ENT_QUOTES, \'UTF-8\') . \'"\';' . "\n";
                $php .= '}' . "\n";
                $php .= '?>';

                if (!$editorMode) {
                    return $php;
                }

                // Editor mode: keep the comment AND output the PHP
                return $m[0] . "\n" . $php;
            },
            $html
        );

        // Issue 1 fix: Inject style/data attrs into the next HTML element's opening tag
        // After each settings PHP block, find the next opening HTML tag and inject the attributes
        $html = preg_replace_callback(
            '/(\$_outpost_settings_data;\s*\?>\s*(?:<!--[^>]*-->\s*)*)(<(\w+)([\s>]))/s',
            function ($m) {
                $before = $m[1];
                $tagStart = '<' . $m[3];
                $afterTagName = $m[4];
                $inject = '<?php echo $_outpost_settings_style ? \' style="\' . htmlspecialchars(trim($_outpost_settings_style), ENT_QUOTES, \'UTF-8\') . \'"\' : \'\'; ?><?php echo $_outpost_settings_data; ?>';
                return $before . $tagStart . $inject . $afterTagName;
            },
            $html
        );

        return $html;
    }

    /**
     * Parse settings definitions from a comment block.
     * Format: name: type(default) — one per line
     */
    private static function parseSettingsDefinitions(string $raw): array {
        $settings = [];
        $lines = preg_split('/\r?\n/', $raw);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Match: field_name: type(args)
            if (preg_match('/^([\w-]+)\s*:\s*(\w+)(?:\(([^)]*)\))?$/', $line, $m)) {
                $name = $m[1];
                $type = $m[2];
                $args = isset($m[3]) ? $m[3] : '';

                $def = ['type' => $type, 'default' => '', 'options' => []];

                switch ($type) {
                    case 'color':
                        $def['default'] = $args ?: '#000000';
                        break;
                    case 'range':
                        $parts = array_map('trim', explode(',', $args));
                        $def['min'] = $parts[0] ?? '0';
                        $def['max'] = $parts[1] ?? '100';
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
                    default:
                        $def['default'] = $args;
                }

                $settings[$name] = $def;
            }
        }

        return $settings;
    }

    // ─── Menus ───────────────────────────────────────────────

    /**
     * Compile <outpost-menu name="main">..template..</outpost-menu>
     * Inner HTML is the template for each menu item (repeated).
     */
    private static function compileMenus(string $html, bool $editorMode): string {
        return preg_replace_callback(
            '/<outpost-menu\s+([^>]*?)>(.*?)<\/outpost-menu>/is',
            function ($m) use ($editorMode) {
                $attrs = self::parseAttributes($m[1]);
                $inner = $m[2];
                $name = self::phpString($attrs['name'] ?? 'main');

                // Replace data-outpost fields inside menu template with item properties
                // Menu items have: label, url, target, children
                $template = self::compileMenuItemFields($inner);

                $php = '<?php foreach (cms_menu_items(' . $name . ') as $_mi) { ?>';
                $php .= $template;
                $php .= '<?php } ?>';

                return $php;
            },
            $html
        );
    }

    /**
     * Replace data-outpost fields in menu item template with PHP echoes.
     */
    private static function compileMenuItemFields(string $html): string {
        // Handle <a data-outpost="url" data-type="link" href="#">
        $html = preg_replace_callback(
            '/<a(\s[^>]*?)data-outpost="url"([^>]*?)href="[^"]*"([^>]*?)>/is',
            function ($m) {
                $before = $m[1] . $m[2] . $m[3];
                // Remove data-outpost, data-type, and data-label from output
                $before = preg_replace('/\s*data-outpost="[^"]*"/', '', $before);
                $before = preg_replace('/\s*data-type="[^"]*"/', '', $before);
                $before = preg_replace('/\s*data-label="[^"]*"/', '', $before);
                return '<a' . $before . 'href="<?php echo htmlspecialchars($_mi[\'url\'], ENT_QUOTES, \'UTF-8\'); ?>">';
            },
            $html
        );

        // Handle <span data-outpost="label">text</span> and similar text fields
        $html = preg_replace_callback(
            '/(<(\w+)\s[^>]*?)data-outpost="label"([^>]*?)>([^<]*)<\/\2>/is',
            function ($m) {
                $tag = $m[2];
                $attrs = $m[1] . $m[3];
                $attrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $attrs);
                $attrs = preg_replace('/\s*data-label="[^"]*"/', '', $attrs);
                return $attrs . '><?php echo htmlspecialchars($_mi[\'label\'], ENT_QUOTES, \'UTF-8\'); ?></' . $tag . '>';
            },
            $html
        );

        // Handle data-outpost="count" (for folder items with counts)
        $html = preg_replace_callback(
            '/(<(\w+)\s[^>]*?)data-outpost="count"([^>]*?)>([^<]*)<\/\2>/is',
            function ($m) {
                $tag = $m[2];
                $attrs = $m[1] . $m[3];
                $attrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $attrs);
                $attrs = preg_replace('/\s*data-label="[^"]*"/', '', $attrs);
                return $attrs . '><?php echo htmlspecialchars($_mi[\'count\'] ?? \'\', ENT_QUOTES, \'UTF-8\'); ?></' . $tag . '>';
            },
            $html
        );

        return $html;
    }

    // ─── Collection/Repeater/Folder Loops ────────────────────

    /**
     * Compile <outpost-each> elements.
     *
     * Variants:
     *   <outpost-each collection="post" limit="6">     → collection loop
     *   <outpost-each collection="post" empty>          → empty fallback
     *   <outpost-each repeat="skills">                  → repeater loop
     *   <outpost-each folder="categories" collection="post"> → folder loop
     */
    private static function compileEachLoops(string $html, bool $editorMode): string {
        // First pass: collect empty fallback blocks for each collection
        // Process innermost first to handle nested loops (Issue 5 fix)
        $emptyBlocks = [];
        $maxPasses = 10;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $before = $html;
            $html = preg_replace_callback(
                '/<outpost-each\s+([^>]*?)\bempty\b([^>]*?)>(.*?)<\/outpost-each>/is',
                function ($m) use (&$emptyBlocks) {
                    $attrs = self::parseAttributes($m[1] . $m[2]);
                    $collection = $attrs['collection'] ?? '';
                    $repeat = $attrs['repeat'] ?? '';
                    $key = $collection ?: ('repeat:' . $repeat);
                    $emptyBlocks[$key] = $m[3];
                    return '<!--OUTPOST_EMPTY_PLACEHOLDER:' . $key . '-->';
                },
                $html
            );
            if ($html === $before) break;
        }

        // Second pass: compile main loops
        // Process innermost first, working outward (Issue 5 fix for nested loops)
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $before = $html;
            $html = preg_replace_callback(
                '/<outpost-each\s+([^>]*?)>(.*?)<\/outpost-each>/is',
                function ($m) use ($editorMode, &$emptyBlocks) {
                    $attrs = self::parseAttributes($m[1]);
                    $inner = $m[2];

                    // Folder loop
                    if (isset($attrs['folder'])) {
                        return self::compileFolderLoop($attrs, $inner, $editorMode);
                    }

                    // Gallery loop
                    if (isset($attrs['gallery'])) {
                        return self::compileGalleryLoop($attrs, $inner, $editorMode);
                    }

                    // Media folder loop
                    if (isset($attrs['media-folder'])) {
                        return self::compileMediaFolderLoop($attrs, $inner, $editorMode);
                    }

                    // Repeater loop
                    if (isset($attrs['repeat'])) {
                        return self::compileRepeaterLoop($attrs, $inner, $editorMode, $emptyBlocks);
                    }

                    // Collection loop
                    if (isset($attrs['collection'])) {
                        return self::compileCollectionLoop($attrs, $inner, $editorMode, $emptyBlocks);
                    }

                    return $m[0]; // unknown — leave as-is
                },
                $html
            );
            if ($html === $before) break; // no more matches
        }

        // Clean up empty placeholders that weren't replaced
        $html = preg_replace('/<!--OUTPOST_EMPTY_PLACEHOLDER:[^>]+-->/', '', $html);

        return $html;
    }

    /**
     * Compile a collection loop.
     */
    private static function compileCollectionLoop(array $attrs, string $inner, bool $editorMode, array &$emptyBlocks): string {
        $slug = self::phpString($attrs['collection']);
        $limit = (int) ($attrs['limit'] ?? 0);
        $sort = $attrs['sort'] ?? '';
        $order = strtoupper($attrs['order'] ?? 'DESC');
        $offset = (int) ($attrs['offset'] ?? 0);
        $paginate = (int) ($attrs['paginate'] ?? 0);
        $related = isset($attrs['related']);
        $filter = $attrs['filter'] ?? '';

        if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';

        // Build options array
        $opts = [];
        if ($limit) $opts[] = "'limit' => {$limit}";
        if ($sort) $opts[] = "'order' => " . self::phpString($sort . ' ' . $order);
        if ($offset) $opts[] = "'offset' => {$offset}";
        if ($paginate) $opts[] = "'paginate' => {$paginate}";
        if ($related) $opts[] = "'related_id' => (\$_outpost_current_item['id'] ?? 0)";
        if ($filter) $opts[] = "'filter_param' => " . self::phpString($filter);

        $optsPhp = '[' . implode(', ', $opts) . ']';

        // Compile inner fields (item-scoped)
        $compiledInner = self::compileItemFields($inner, $editorMode);

        // Empty fallback
        $emptyKey = $attrs['collection'];
        $emptyHtml = '';
        if (isset($emptyBlocks[$emptyKey])) {
            $emptyHtml = $emptyBlocks[$emptyKey];
            unset($emptyBlocks[$emptyKey]);
        }

        $php = '<?php $_outpost_each_count = 0; cms_collection_list(' . $slug . ', function($item) use (&$_outpost_each_count) { $_outpost_each_count++; ?>';
        $php .= $compiledInner;
        $php .= '<?php }, ' . $optsPhp . '); ?>';

        if ($emptyHtml) {
            $php .= '<?php if ($_outpost_each_count === 0) { ?>';
            $php .= $emptyHtml;
            $php .= '<?php } ?>';
            // Remove the placeholder
            $php = str_replace('<!--OUTPOST_EMPTY_PLACEHOLDER:' . $emptyKey . '-->', '', $php);
        }

        if ($paginate) {
            $php .= '<?php cms_pagination(); ?>';
        }

        return $php;
    }

    /**
     * Compile a repeater loop.
     */
    private static function compileRepeaterLoop(array $attrs, string $inner, bool $editorMode, array &$emptyBlocks): string {
        $name = self::phpString($attrs['repeat']);

        // Compile conditionals + inner fields (item-scoped)
        $compiledInner = self::compileConditionals($inner, $editorMode);
        $compiledInner = self::compileItemFields($compiledInner, $editorMode);

        $emptyKey = 'repeat:' . $attrs['repeat'];
        $emptyHtml = isset($emptyBlocks[$emptyKey]) ? $emptyBlocks[$emptyKey] : '';

        $php = '<?php if (isset($item) && isset($item[' . $name . '])) { $_rpt_raw = $item[' . $name . ']; $_rpt_items = is_array($_rpt_raw) ? $_rpt_raw : (json_decode($_rpt_raw, true) ?: []); } else { $_rpt_items = cms_repeater_items(' . $name . '); } ?>';
        if ($emptyHtml) {
            $php .= '<?php if (empty($_rpt_items)) { ?>';
            $php .= $emptyHtml;
            $php .= '<?php } else { ?>';
        }
        $php .= '<?php foreach ($_rpt_items as $item) { ?>';
        $php .= $compiledInner;
        $php .= '<?php } ?>';
        if ($emptyHtml) {
            $php .= '<?php } ?>';
        }

        return $php;
    }

    /**
     * Compile a folder/taxonomy loop.
     */
    private static function compileFolderLoop(array $attrs, string $inner, bool $editorMode): string {
        $folderSlug = self::phpString($attrs['folder']);

        // Compile inner fields — folder items have: name, slug, count
        // Reuse menu item compilation pattern (similar structure)
        $compiledInner = self::compileFolderItemFields($inner);

        $php = '<?php foreach (cms_folder_labels(' . $folderSlug . ') as $_mi) { ?>';
        $php .= $compiledInner;
        $php .= '<?php } ?>';

        return $php;
    }

    /**
     * Replace data-outpost attributes inside a folder item template.
     */
    private static function compileFolderItemFields(string $html): string {
        // Map data-outpost names to folder item keys
        // url → build URL from slug, label/name → name, count → count, slug → slug
        $html = preg_replace_callback(
            '/<a(\s[^>]*?)data-outpost="url"([^>]*?)href="[^"]*"([^>]*?)>/is',
            function ($m) {
                $rest = $m[1] . $m[2] . $m[3];
                $rest = preg_replace('/\s*data-outpost="[^"]*"/', '', $rest);
                $rest = preg_replace('/\s*data-type="[^"]*"/', '', $rest);
                $rest = preg_replace('/\s*data-label="[^"]*"/', '', $rest);
                return '<a' . $rest . 'href="?<?php echo urlencode($_mi[\'slug\']); ?>">';
            },
            $html
        );

        $html = preg_replace_callback(
            '/(<(\w+)\s[^>]*?)data-outpost="(label|name)"([^>]*?)>([^<]*)<\/\2>/is',
            function ($m) {
                $tag = $m[2];
                $attrs = $m[1] . $m[4];
                $attrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $attrs);
                $attrs = preg_replace('/\s*data-label="[^"]*"/', '', $attrs);
                return $attrs . '><?php echo htmlspecialchars($_mi[\'name\'], ENT_QUOTES, \'UTF-8\'); ?></' . $tag . '>';
            },
            $html
        );

        $html = preg_replace_callback(
            '/(<(\w+)\s[^>]*?)data-outpost="count"([^>]*?)>([^<]*)<\/\2>/is',
            function ($m) {
                $tag = $m[2];
                $attrs = $m[1] . $m[3];
                $attrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $attrs);
                $attrs = preg_replace('/\s*data-label="[^"]*"/', '', $attrs);
                return $attrs . '><?php echo htmlspecialchars($_mi[\'count\'] ?? \'\', ENT_QUOTES, \'UTF-8\'); ?></' . $tag . '>';
            },
            $html
        );

        $html = preg_replace_callback(
            '/(<(\w+)\s[^>]*?)data-outpost="slug"([^>]*?)>([^<]*)<\/\2>/is',
            function ($m) {
                $tag = $m[2];
                $attrs = $m[1] . $m[3];
                $attrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $attrs);
                $attrs = preg_replace('/\s*data-label="[^"]*"/', '', $attrs);
                return $attrs . '><?php echo htmlspecialchars($_mi[\'slug\'], ENT_QUOTES, \'UTF-8\'); ?></' . $tag . '>';
            },
            $html
        );

        return $html;
    }

    /**
     * Compile a gallery loop.
     */
    private static function compileGalleryLoop(array $attrs, string $inner, bool $editorMode): string {
        $name = self::phpString($attrs['gallery']);

        // Gallery items have: url
        // Replace img src with gallery URL
        $compiledInner = preg_replace_callback(
            '/<(img)(\s[^>]*?)data-outpost="url"([^>]*?)\/?>/is',
            function ($m) use ($editorMode) {
                $allAttrs = $m[2] . $m[3];
                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }
                $allAttrs = preg_replace('/\ssrc="[^"]*"/', '', $allAttrs);
                return '<img' . $allAttrs . ' src="<?php echo htmlspecialchars($item[\'url\'], ENT_QUOTES, \'UTF-8\'); ?>">';
            },
            $inner
        );

        $php = '<?php foreach (cms_gallery_items(' . $name . ') as $item) { ?>';
        $php .= $compiledInner;
        $php .= '<?php } ?>';

        return $php;
    }

    /**
     * Compile a media folder loop.
     */
    private static function compileMediaFolderLoop(array $attrs, string $inner, bool $editorMode): string {
        $slug = self::phpString($attrs['media-folder']);

        // Media folder items have: url, alt, alt_text, width, height, focal_x, focal_y, mime_type, filename
        $compiledInner = self::compileItemFields($inner, $editorMode);

        $php = '<?php foreach (cms_media_folder_items(' . $slug . ') as $item) { ?>';
        $php .= $compiledInner;
        $php .= '<?php } ?>';

        return $php;
    }

    /**
     * Compile data-outpost fields inside a collection/repeater loop item.
     * These resolve from $item['field'] instead of the page field store.
     */
    private static function compileItemFields(string $inner, bool $editorMode): string {
        // Handle self-closing elements (img) with data-outpost
        $inner = preg_replace_callback(
            '/<(img)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)\/?>/is',
            function ($m) use ($editorMode) {
                $tag = $m[1];
                $prefix = $m[2];
                $field = $m[3];
                $suffix = $m[4];
                $allAttrs = $prefix . $suffix;

                $type = 'text';
                if (preg_match('/data-type="([^"]+)"/', $allAttrs, $tm)) {
                    $type = $tm[1];
                }

                // Clean data attributes in public mode
                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-scope="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }

                if ($type === 'image' || $tag === 'img') {
                    // Replace src attribute with item value
                    $allAttrs = preg_replace('/\ssrc="[^"]*"/', '', $allAttrs);
                    return '<' . $tag . $allAttrs . ' src="<?php echo htmlspecialchars($item[\'' . addslashes($field) . '\'] ?? \'\', ENT_QUOTES, \'UTF-8\'); ?>">';
                }

                return $m[0]; // fallback
            },
            $inner
        );

        // Handle <a> with data-outpost + data-type="link" (sets href)
        $inner = preg_replace_callback(
            '/<(a)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)>/is',
            function ($m) use ($editorMode) {
                $tag = $m[1];
                $prefix = $m[2];
                $field = $m[3];
                $suffix = $m[4];
                $allAttrs = $prefix . $suffix;

                $type = 'text';
                if (preg_match('/data-type="([^"]+)"/', $allAttrs, $tm)) {
                    $type = $tm[1];
                }

                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }

                if ($type === 'link' || $field === 'url') {
                    // Replace or set href
                    $allAttrs = preg_replace('/\shref="[^"]*"/', '', $allAttrs);
                    return '<a' . $allAttrs . ' href="<?php echo htmlspecialchars($item[\'' . addslashes($field) . '\'] ?? \'#\', ENT_QUOTES, \'UTF-8\'); ?>">';
                }

                return $m[0];
            },
            $inner
        );

        // Handle regular elements with data-outpost (content goes inside)
        $inner = preg_replace_callback(
            '/(<(\w+)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)>)(.*?)(<\/\2>)/is',
            function ($m) use ($editorMode) {
                $openTag = $m[1];
                $tag = $m[2];
                $prefix = $m[3];
                $field = $m[4];
                $suffix = $m[5];
                $defaultContent = $m[6];
                $closeTag = $m[7];
                $allAttrs = $prefix . $suffix;

                // Skip if already handled (img, a)
                if ($tag === 'img' || $tag === 'a') return $m[0];

                $type = 'text';
                if (preg_match('/data-type="([^"]+)"/', $allAttrs, $tm)) {
                    $type = $tm[1];
                }

                // Parse data-bind for attribute bindings: data-bind="attr:field,attr2:field2"
                $bindings = [];
                if (preg_match('/data-bind="([^"]+)"/', $allAttrs, $bm)) {
                    foreach (explode(',', $bm[1]) as $bind) {
                        $bind = trim($bind);
                        if (str_contains($bind, ':')) {
                            [$attr, $bindField] = explode(':', $bind, 2);
                            $bindings[trim($attr)] = trim($bindField);
                        }
                    }
                }

                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-bind="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }

                // Inject bound attributes
                $bindPhp = '';
                foreach ($bindings as $attr => $bindField) {
                    $bindPhp .= ' ' . htmlspecialchars($attr) . '="<?php echo htmlspecialchars($item[\'' . addslashes($bindField) . '\'] ?? \'\', ENT_QUOTES, \'UTF-8\'); ?>"';
                }

                $newOpen = '<' . $tag . $allAttrs . $bindPhp . '>';

                if ($type === 'richtext') {
                    $value = '<?php echo $item[\'' . addslashes($field) . '\'] ?? \'\'; ?>';
                } else {
                    $value = '<?php echo outpost_esc($item[\'' . addslashes($field) . '\'] ?? \'\'); ?>';
                }

                // Richtext: always render the container (consistent with page-scoped behavior)
                if ($type === 'richtext') {
                    return $newOpen . $value . $closeTag;
                }

                // Auto-hide: if value is empty, hide the element
                $wrapped = '<?php $_iv = $item[\'' . addslashes($field) . '\'] ?? \'\'; if ($_iv !== \'\') { ?>';
                $wrapped .= $newOpen . $value . $closeTag;
                $wrapped .= '<?php } ?>';

                return $wrapped;
            },
            $inner
        );

        return $inner;
    }

    // ─── Singles ──────────────────────────────────────────────

    /**
     * Compile <outpost-single collection="post">...<outpost-single collection="post" else>
     */
    private static function compileSingles(string $html, bool $editorMode): string {
        // First: collect else blocks
        $elseBlocks = [];
        $html = preg_replace_callback(
            '/<outpost-single\s+([^>]*?)\belse\b([^>]*?)>(.*?)<\/outpost-single>/is',
            function ($m) use (&$elseBlocks) {
                $attrs = self::parseAttributes($m[1] . $m[2]);
                $collection = $attrs['collection'] ?? '';
                $elseBlocks[$collection] = $m[3];
                return '<!--OUTPOST_SINGLE_ELSE:' . $collection . '-->';
            },
            $html
        );

        // Main single blocks
        $html = preg_replace_callback(
            '/<outpost-single\s+([^>]*?)>(.*?)<\/outpost-single>/is',
            function ($m) use ($editorMode, &$elseBlocks) {
                $attrs = self::parseAttributes($m[1]);
                $inner = $m[2];
                $slug = $attrs['collection'] ?? '';
                $slugPhp = self::phpString($slug);

                // Compile inner fields as item-scoped
                $compiledInner = self::compileItemFields($inner, $editorMode);

                $elseHtml = '';
                if (isset($elseBlocks[$slug])) {
                    $elseHtml = $elseBlocks[$slug];
                    unset($elseBlocks[$slug]);
                }

                $php = '<?php $_single_item = cms_collection_single(' . $slugPhp . '); ?>';
                $php .= '<?php if ($_single_item) { $_item_backup = $item ?? null; $item = $_single_item; ?>';
                $php .= $compiledInner;
                $php .= '<?php $item = $_item_backup; } ?>';

                if ($elseHtml) {
                    // Replace the placeholder
                    $placeholder = '<!--OUTPOST_SINGLE_ELSE:' . $slug . '-->';
                    $php .= '<?php if (!$_single_item) { ?>';
                    $php .= $elseHtml;
                    $php .= '<?php } ?>';
                }

                return $php;
            },
            $html
        );

        // Clean up remaining else placeholders
        $html = preg_replace('/<!--OUTPOST_SINGLE_ELSE:[^>]+-->/', '', $html);

        return $html;
    }

    // ─── Conditionals ────────────────────────────────────────

    /**
     * Compile <outpost-if field="x" is="true">...</outpost-if>
     *
     * Operators: is, equals, not, exists, empty
     */
    private static function compileConditionals(string $html, bool $editorMode): string {
        return preg_replace_callback(
            '/<outpost-if\s+([^>]*?)>(.*?)<\/outpost-if>/is',
            function ($m) {
                $attrs = self::parseAttributes($m[1]);
                $inner = $m[2];
                $field = $attrs['field'] ?? '';
                if (!$field) return $inner; // no field = show unconditionally

                $fieldPhp = self::phpString($field);
                $isGlobal = isset($attrs['scope']) && $attrs['scope'] === 'global';

                // Helper: resolve field value — checks $item first (for loop/single context),
                // then falls back to page field store. Global fields always use cms_global_get.
                $resolveExpr = $isGlobal
                    ? 'cms_global_get(' . $fieldPhp . ')'
                    : '(isset($item) && isset($item[' . $fieldPhp . ']) ? (string)$item[' . $fieldPhp . '] : outpost_resolve_field(' . $fieldPhp . ', \'text\', \'\'))';
                $truthyExpr = $isGlobal
                    ? 'cms_global_get(' . $fieldPhp . ') !== \'\''
                    : '(isset($item) && !empty($item[' . $fieldPhp . ']) ? true : cms_field_truthy(' . $fieldPhp . '))';

                // Determine condition
                if (isset($attrs['is']) || isset($attrs['equals'])) {
                    $val = self::phpString($attrs['is'] ?? $attrs['equals']);
                    $cond = $resolveExpr . ' === ' . $val;
                } elseif (isset($attrs['not'])) {
                    $val = self::phpString($attrs['not']);
                    $cond = $resolveExpr . ' !== ' . $val;
                } elseif (array_key_exists('exists', $attrs)) {
                    $cond = $truthyExpr;
                } elseif (array_key_exists('empty', $attrs) || array_key_exists('negate', $attrs)) {
                    $cond = '!(' . $truthyExpr . ')';
                } else {
                    // Default: truthy check
                    $cond = $truthyExpr;
                }

                return '<?php if (' . $cond . ') { ?>' . $inner . '<?php } ?>';
            },
            $html
        );
    }

    // ─── Page-Scoped Fields (data-outpost on static elements) ─

    /**
     * Compile data-outpost attributes on page-level elements.
     * These read from the page field store (or globals with data-scope="global").
     */
    private static function compileFields(string $html, bool $editorMode): string {
        // Handle void elements (input, hr, br, source, embed, meta, link) with data-outpost
        $html = preg_replace_callback(
            '/<(input|hr|br|source|embed|meta|link)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)\/?>/is',
            function ($m) use ($editorMode) {
                $tag = $m[1]; $prefix = $m[2]; $field = $m[3]; $suffix = $m[4];
                $allAttrs = $prefix . $suffix;
                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }
                if ($tag === 'input') {
                    $allAttrs = preg_replace('/\svalue="[^"]*"/', '', $allAttrs);
                    return '<' . $tag . $allAttrs . ' value="<?php echo outpost_esc(outpost_resolve_field(\'' . addslashes($field) . '\', \'text\', \'\')); ?>">';
                }
                return '<' . $tag . $allAttrs . '>';
            },
            $html
        );

        // Handle self-closing img elements
        $html = preg_replace_callback(
            '/<(img)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)\/?>/is',
            function ($m) use ($editorMode) {
                $tag = $m[1];
                $prefix = $m[2];
                $field = $m[3];
                $suffix = $m[4];
                $allAttrs = $prefix . $suffix;

                $isGlobal = (bool) preg_match('/data-scope="global"/', $allAttrs);

                // Extract data-label before stripping
                $dataLabel = '';
                if (preg_match('/data-label="([^"]*)"/', $allAttrs, $dlm)) {
                    $dataLabel = $dlm[1];
                }

                // Get default src
                $defaultSrc = '';
                if (preg_match('/src="([^"]*)"/', $allAttrs, $sm)) {
                    $defaultSrc = $sm[1];
                }

                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-scope="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }

                // Replace src with resolved value
                $allAttrs = preg_replace('/\ssrc="[^"]*"/', '', $allAttrs);
                $defaultPhp = self::phpString($defaultSrc);

                if ($isGlobal) {
                    $srcPhp = '<?php $__v = cms_global_get(' . self::phpString($field) . ', ' . $defaultPhp . '); echo htmlspecialchars($__v, ENT_QUOTES, \'UTF-8\'); ?>';
                } else {
                    $srcPhp = '<?php $__v = outpost_resolve_field(' . self::phpString($field) . ', \'image\', ' . $defaultPhp . '); echo htmlspecialchars($__v, ENT_QUOTES, \'UTF-8\'); ?>';
                }

                // Auto-hide: don't render img if no value
                $result = '<?php $__img_v = ' . ($isGlobal
                    ? 'cms_global_get(' . self::phpString($field) . ', ' . $defaultPhp . ')'
                    : 'outpost_resolve_field(' . self::phpString($field) . ', \'image\', ' . $defaultPhp . ')')
                    . '; if ($__img_v !== \'\') { ?>';
                $imgEditorAttrs = $editorMode ? ' data-outpost="' . htmlspecialchars($field) . '" data-type="image"' . ($isGlobal ? ' data-scope="global"' : '') . ($dataLabel ? ' data-label="' . htmlspecialchars($dataLabel) . '"' : '') : '';
                $result .= '<' . $tag . $allAttrs . $imgEditorAttrs . ' src="<?php echo htmlspecialchars($__img_v, ENT_QUOTES, \'UTF-8\'); ?>">';
                $result .= '<?php } ?>';

                return $result;
            },
            $html
        );

        // Handle <a> with data-outpost + data-type="link"
        $html = preg_replace_callback(
            '/<(a)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)>(.*?)<\/a>/is',
            function ($m) use ($editorMode) {
                $tag = $m[1];
                $prefix = $m[2];
                $field = $m[3];
                $suffix = $m[4];
                $content = $m[5];
                $allAttrs = $prefix . $suffix;

                $type = 'text';
                if (preg_match('/data-type="([^"]+)"/', $allAttrs, $tm)) {
                    $type = $tm[1];
                }
                $isGlobal = (bool) preg_match('/data-scope="global"/', $allAttrs);

                // Extract data-label before stripping
                $dataLabel = '';
                if (preg_match('/data-label="([^"]*)"/', $allAttrs, $dlm)) {
                    $dataLabel = $dlm[1];
                }

                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-scope="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }

                if ($type === 'link') {
                    // Replace href with resolved value
                    $defaultHref = '#';
                    if (preg_match('/href="([^"]*)"/', $allAttrs, $hm)) {
                        $defaultHref = $hm[1];
                    }
                    $allAttrs = preg_replace('/\shref="[^"]*"/', '', $allAttrs);
                    $defaultPhp = self::phpString($defaultHref);

                    if ($isGlobal) {
                        $hrefPhp = 'cms_global_get(' . self::phpString($field) . ', ' . $defaultPhp . ')';
                    } else {
                        $hrefPhp = 'outpost_resolve_field(' . self::phpString($field) . ', \'link\', ' . $defaultPhp . ')';
                    }

                    // Process child content — it may have its own data-outpost fields
                    $linkEditorAttrs = $editorMode ? ' data-outpost="' . htmlspecialchars($field) . '" data-type="link"' . ($isGlobal ? ' data-scope="global"' : '') . ($dataLabel ? ' data-label="' . htmlspecialchars($dataLabel) . '"' : '') : '';
                    return '<a' . $allAttrs . $linkEditorAttrs . ' href="<?php echo htmlspecialchars(' . $hrefPhp . ', ENT_QUOTES, \'UTF-8\'); ?>">' . $content . '</a>';
                }

                return $m[0]; // non-link <a>
            },
            $html
        );

        // Handle regular elements with content
        $html = preg_replace_callback(
            '/(<(\w+)(\s[^>]*?)data-outpost="([^"]+)"([^>]*?)>)(.*?)(<\/\2>)/is',
            function ($m) use ($editorMode) {
                $tag = $m[2];
                $prefix = $m[3];
                $field = $m[4];
                $suffix = $m[5];
                $defaultContent = $m[6];
                $closeTag = $m[7];
                $allAttrs = $prefix . $suffix;

                // Skip already-handled tags
                if ($tag === 'img' || $tag === 'a') return $m[0];

                $type = 'text';
                if (preg_match('/data-type="([^"]+)"/', $allAttrs, $tm)) {
                    $type = $tm[1];
                }
                $isGlobal = (bool) preg_match('/data-scope="global"/', $allAttrs);

                // Extract data-label before stripping
                $dataLabel = '';
                if (preg_match('/data-label="([^"]*)"/', $allAttrs, $dlm)) {
                    $dataLabel = $dlm[1];
                }

                if (!$editorMode) {
                    $allAttrs = preg_replace('/\s*data-outpost="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-type="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-scope="[^"]*"/', '', $allAttrs);
                    $allAttrs = preg_replace('/\s*data-label="[^"]*"/', '', $allAttrs);
                }

                $fieldPhp = self::phpString($field);
                $defaultPhp = self::phpString(trim($defaultContent));

                // In editor mode, re-add data-outpost (it was consumed by the regex capture)
                $editorAttrs = '';
                if ($editorMode) {
                    $editorAttrs = ' data-outpost="' . htmlspecialchars($field) . '"';
                    if ($type !== 'text') $editorAttrs .= ' data-type="' . htmlspecialchars($type) . '"';
                    if ($isGlobal) $editorAttrs .= ' data-scope="global"';
                    if ($dataLabel) $editorAttrs .= ' data-label="' . htmlspecialchars($dataLabel) . '"';
                }

                $newOpen = '<' . $tag . $allAttrs . $editorAttrs . '>';

                // Map data-type to engine field type
                $engineType = match ($type) {
                    'richtext' => 'richtext',
                    'textarea' => 'textarea',
                    'image' => 'image',
                    'link' => 'link',
                    'toggle' => 'toggle',
                    'select' => 'select',
                    'number' => 'number',
                    'date' => 'date',
                    'color' => 'color',
                    default => 'text',
                };

                if ($isGlobal) {
                    if ($type === 'richtext') {
                        $valuePart = '<?php cms_global(' . $fieldPhp . ', \'richtext\', ' . $defaultPhp . '); ?>';
                    } else {
                        $valuePart = '<?php cms_global(' . $fieldPhp . ', ' . self::phpString($engineType) . ', ' . $defaultPhp . '); ?>';
                    }
                } else {
                    if ($type === 'richtext') {
                        $valuePart = '<?php cms_richtext(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    } elseif ($type === 'textarea') {
                        $valuePart = '<?php cms_textarea(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    } elseif ($type === 'toggle') {
                        $valuePart = '<?php echo cms_toggle(' . $fieldPhp . ') ? \'true\' : \'false\'; ?>';
                    } elseif ($type === 'select') {
                        $valuePart = '<?php cms_select(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    } elseif ($type === 'number') {
                        $valuePart = '<?php cms_number(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    } elseif ($type === 'date') {
                        $valuePart = '<?php cms_date(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    } elseif ($type === 'color') {
                        $valuePart = '<?php cms_color(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    } else {
                        $valuePart = '<?php cms_text(' . $fieldPhp . ', ' . $defaultPhp . '); ?>';
                    }
                }

                // Auto-hide: if field is empty and not richtext, hide element
                if ($type !== 'richtext' && $type !== 'toggle') {
                    $checkFn = $isGlobal
                        ? 'cms_global_get(' . $fieldPhp . ', ' . $defaultPhp . ')'
                        : 'outpost_resolve_field(' . $fieldPhp . ', ' . self::phpString($engineType) . ', ' . $defaultPhp . ')';
                    $result = '<?php $_fv = ' . $checkFn . '; if ($_fv !== \'\') { ?>';
                    $result .= $newOpen . $valuePart . $closeTag;
                    $result .= '<?php } ?>';
                    return $result;
                }

                return $newOpen . $valuePart . $closeTag;
            },
            $html
        );

        return $html;
    }

    // ─── Lodge (Member Portal) Tags ────────────────────────

    /**
     * Compile <outpost-lodge-items>, <outpost-lodge-form>, and <outpost-lodge-dashboard>.
     */
    private static function compileLodgeTags(string $html, bool $editorMode): string {
        // <outpost-lodge-dashboard>...</outpost-lodge-dashboard>
        // Wraps content only for authenticated members, injects member data
        $html = preg_replace_callback(
            '/<outpost-lodge-dashboard>(.*?)<\/outpost-lodge-dashboard>/is',
            function ($m) use ($editorMode) {
                $inner = $m[1];
                $php = '<?php ';
                $php .= 'require_once __DIR__ . "/../members.php"; ';
                $php .= 'if (OutpostMember::check()) { ';
                $php .= '$_lodge_member = OutpostMember::currentMember(); ';
                $php .= '$_lodge_full = OutpostDB::fetchOne("SELECT id, username, email, display_name, avatar, bio, tier FROM users WHERE id = ?", [$_lodge_member["id"]]); ';
                $php .= '$item = $_lodge_full ?: []; ';
                $php .= 'if (isset($item["bio"])) $item["bio"] = htmlspecialchars($item["bio"] ?? "", ENT_QUOTES, "UTF-8"); ';
                $php .= '?>';
                $php .= $inner;
                $php .= '<?php } ?>';
                return $php;
            },
            $html
        );

        // <outpost-lodge-items collection="slug">...</outpost-lodge-items>
        // Loops through the authenticated member's own items in a collection
        $html = preg_replace_callback(
            '/<outpost-lodge-items\s+([^>]*?)>(.*?)<\/outpost-lodge-items>/is',
            function ($m) use ($editorMode) {
                $attrs = self::parseAttributes($m[1]);
                $inner = $m[2];
                $slug = $attrs['collection'] ?? '';
                if (!$slug) return '<!-- outpost-lodge-items: collection attribute required -->';

                $slugPhp = self::phpString($slug);
                $compiledInner = self::compileItemFields($inner, $editorMode);

                $php = '<?php ';
                $php .= 'require_once __DIR__ . "/../members.php"; ';
                $php .= 'if (OutpostMember::check()) { ';
                $php .= '$_lodge_member = OutpostMember::currentMember(); ';
                $php .= '$_lodge_col = OutpostDB::fetchOne("SELECT * FROM collections WHERE slug = ? AND lodge_enabled = 1", [' . $slugPhp . ']); ';
                $php .= 'if ($_lodge_col) { ';
                $php .= '$_lodge_items = OutpostDB::fetchAll(';
                $php .= '"SELECT id, slug, status, data, created_at, updated_at, published_at FROM collection_items WHERE collection_id = ? AND owner_member_id = ? ORDER BY created_at DESC", ';
                $php .= '[$_lodge_col["id"], $_lodge_member["id"]]); ';
                $php .= 'foreach ($_lodge_items as $_lodge_raw) { ';
                $php .= '$item = json_decode($_lodge_raw["data"], true) ?: []; ';
                $php .= '$item["id"] = $_lodge_raw["id"]; ';
                $php .= '$item["slug"] = $_lodge_raw["slug"]; ';
                $php .= '$item["status"] = $_lodge_raw["status"]; ';
                $php .= '$item["created_at"] = $_lodge_raw["created_at"]; ';
                $php .= '$item["updated_at"] = $_lodge_raw["updated_at"]; ';
                $php .= '$item["published_at"] = $_lodge_raw["published_at"]; ';
                $php .= '?>';
                $php .= $compiledInner;
                $php .= '<?php } } } ?>';

                return $php;
            },
            $html
        );

        // <outpost-lodge-form collection="slug" item="current" />
        // Generates an HTML form from the collection schema + lodge_config editable_fields
        $html = preg_replace_callback(
            '/<outpost-lodge-form\s+([^>]*?)\/?>(.*?)(?:<\/outpost-lodge-form>)?/is',
            function ($m) use ($editorMode) {
                $attrs = self::parseAttributes($m[1]);
                $slug = $attrs['collection'] ?? '';
                if (!$slug) return '<!-- outpost-lodge-form: collection attribute required -->';

                $slugPhp = self::phpString($slug);
                $itemAttr = $attrs['item'] ?? '';
                $action = $attrs['action'] ?? '/outpost/member-api.php';

                $php = '<?php ';
                $php .= 'require_once __DIR__ . "/../members.php"; ';
                $php .= 'require_once __DIR__ . "/../lodge.php"; ';
                $php .= 'if (OutpostMember::check()) { ';
                $php .= '$_lf_col = OutpostDB::fetchOne("SELECT * FROM collections WHERE slug = ? AND lodge_enabled = 1", [' . $slugPhp . ']); ';
                $php .= 'if ($_lf_col) { ';
                $php .= '$_lf_config = lodge_get_config($_lf_col["id"]); ';
                $php .= '$_lf_schema = json_decode($_lf_col["schema"] ?: "[]", true) ?: []; ';
                // Load existing item data if editing
                $php .= '$_lf_item_data = []; ';
                $php .= '$_lf_item_id = $_GET["item_id"] ?? ""; ';
                $php .= 'if ($_lf_item_id) { ';
                $php .= '$_lf_member = OutpostMember::currentMember(); ';
                $php .= '$_lf_existing = OutpostDB::fetchOne("SELECT * FROM collection_items WHERE id = ? AND owner_member_id = ?", [$_lf_item_id, $_lf_member["id"]]); ';
                $php .= 'if ($_lf_existing) $_lf_item_data = json_decode($_lf_existing["data"], true) ?: []; ';
                $php .= '} ';
                // Determine which fields to render
                $php .= '$_lf_editable = $_lf_config["editable_fields"] ?? []; ';
                $php .= '$_lf_readonly = $_lf_config["readonly_fields"] ?? []; ';
                // Build action URL
                $php .= '$_lf_action_url = ' . self::phpString($action) . '; ';
                $php .= 'if ($_lf_item_id) { $_lf_action_url .= "?action=lodge/items&id=" . urlencode($_lf_item_id); $_lf_method = "PUT"; } ';
                $php .= 'else { $_lf_action_url .= "?action=lodge/items&collection=" . urlencode(' . $slugPhp . '); $_lf_method = "POST"; } ';
                $php .= '?>';
                $php .= '<form class="outpost-lodge-form" data-action="<?php echo htmlspecialchars($_lf_action_url); ?>" data-method="<?php echo $_lf_method; ?>">';
                $php .= '<?php foreach ($_lf_schema as $_lf_field) { ';
                $php .= '$_lf_name = $_lf_field["name"] ?? ""; ';
                $php .= '$_lf_type = $_lf_field["type"] ?? "text"; ';
                $php .= '$_lf_label = $_lf_field["label"] ?? ucfirst(str_replace("_", " ", $_lf_name)); ';
                $php .= 'if (!$_lf_name) continue; ';
                // Skip if not in editable_fields (when set) or if in readonly_fields
                $php .= 'if (!empty($_lf_editable) && !in_array($_lf_name, $_lf_editable)) continue; ';
                $php .= 'if (in_array($_lf_name, $_lf_readonly)) continue; ';
                $php .= '$_lf_val = htmlspecialchars($_lf_item_data[$_lf_name] ?? "", ENT_QUOTES, "UTF-8"); ';
                $php .= '$_lf_required = !empty($_lf_field["required"]) ? " required" : ""; ';
                $php .= '?>';
                $php .= '<div class="lodge-field lodge-field--<?php echo htmlspecialchars($_lf_type); ?>">';
                $php .= '<label for="lodge-<?php echo htmlspecialchars($_lf_name); ?>"><?php echo htmlspecialchars($_lf_label); ?></label>';
                $php .= '<?php if ($_lf_type === "richtext" || $_lf_type === "textarea") { ?>';
                $php .= '<textarea id="lodge-<?php echo htmlspecialchars($_lf_name); ?>" name="<?php echo htmlspecialchars($_lf_name); ?>"<?php echo $_lf_required; ?>><?php echo $_lf_val; ?></textarea>';
                $php .= '<?php } elseif ($_lf_type === "image") { ?>';
                $php .= '<input type="file" id="lodge-<?php echo htmlspecialchars($_lf_name); ?>" name="<?php echo htmlspecialchars($_lf_name); ?>" accept="image/*"<?php echo $_lf_required; ?>>';
                $php .= '<?php if ($_lf_val) { ?><img src="<?php echo $_lf_val; ?>" class="lodge-field__preview" alt=""><?php } ?>';
                $php .= '<?php } elseif ($_lf_type === "select" && !empty($_lf_field["options"])) { ?>';
                $php .= '<select id="lodge-<?php echo htmlspecialchars($_lf_name); ?>" name="<?php echo htmlspecialchars($_lf_name); ?>"<?php echo $_lf_required; ?>>';
                $php .= '<option value="">Select...</option>';
                $php .= '<?php foreach ($_lf_field["options"] as $_lf_opt) { ?>';
                $php .= '<option value="<?php echo htmlspecialchars($_lf_opt); ?>"<?php echo $_lf_val === htmlspecialchars($_lf_opt) ? " selected" : ""; ?>><?php echo htmlspecialchars($_lf_opt); ?></option>';
                $php .= '<?php } ?>';
                $php .= '</select>';
                $php .= '<?php } elseif ($_lf_type === "toggle" || $_lf_type === "boolean") { ?>';
                $php .= '<input type="checkbox" id="lodge-<?php echo htmlspecialchars($_lf_name); ?>" name="<?php echo htmlspecialchars($_lf_name); ?>" value="1"<?php echo $_lf_val ? " checked" : ""; ?>>';
                $php .= '<?php } else { ?>';
                $php .= '<input type="text" id="lodge-<?php echo htmlspecialchars($_lf_name); ?>" name="<?php echo htmlspecialchars($_lf_name); ?>" value="<?php echo $_lf_val; ?>"<?php echo $_lf_required; ?>>';
                $php .= '<?php } ?>';
                $php .= '</div>';
                $php .= '<?php } ?>';
                $php .= '<button type="submit" class="lodge-form__submit"><?php echo $_lf_item_id ? "Update" : "Create"; ?></button>';
                $php .= '</form>';
                $php .= '<?php } } ?>';

                return $php;
            },
            $html
        );

        return $html;
    }

    // ─── Compass (Smart Filtering) ─────────────────────────

    /**
     * Compile <outpost-compass*> tags into PHP + HTML for client-side filtering.
     *
     * Supported tags:
     *   <outpost-compass type="dropdown|checkbox|radio|search|range|az|toggle|proximity|hierarchy|time-since|pager" ...>
     *   <outpost-compass-results collection="..." layout="grid" columns="3">...</outpost-compass-results>
     *   <outpost-compass-count collection="...">
     *   <outpost-compass-reset collection="...">
     *   <outpost-compass-selections collection="...">
     *   <outpost-compass-sort collection="..." options="..." labels="...">
     */
    private static function compileCompass(string $html, bool $editorMode): string {

        // ── Facet tags: <outpost-compass type="..." ...> ──
        $html = preg_replace_callback(
            '/<outpost-compass\s+([^>]*?)\/?>(.*?)(?:<\/outpost-compass>)?/is',
            function ($m) {
                $attrs = self::parseAttributes($m[1]);
                $type  = $attrs['type'] ?? '';
                $col   = $attrs['collection'] ?? '';
                $name  = $attrs['source'] ?? ($attrs['name'] ?? '');

                if (!$type || !$col) return '<!-- outpost-compass: type and collection required -->';

                $colSafe  = htmlspecialchars($col, ENT_QUOTES);
                $nameSafe = htmlspecialchars($name, ENT_QUOTES);
                $colPhp   = self::phpString($col);
                $namePhp  = self::phpString($name);

                switch ($type) {
                    case 'dropdown':
                        $label = htmlspecialchars($attrs['label'] ?? ('All ' . ucfirst($name) . 's'), ENT_QUOTES);
                        return '<?php require_once OUTPOST_DIR . "compass-helpers.php";'
                            . '$_cv = compass_tpl_facet_values(' . $colPhp . ', ' . $namePhp . '); ?>'
                            . '<div data-compass-facet="' . $nameSafe . '" data-compass-type="dropdown" data-compass-collection="' . $colSafe . '">'
                            . '<select class="compass-dropdown input">'
                            . '<option value="">' . $label . '</option>'
                            . '<?php foreach ($_cv as $_ck => $_cc) { ?>'
                            . '<option value="<?php echo htmlspecialchars($_ck); ?>"><?php echo htmlspecialchars(ucfirst($_ck)); ?> (<?php echo (int)$_cc; ?>)</option>'
                            . '<?php } ?>'
                            . '</select></div>';

                    case 'checkbox':
                        return '<?php require_once OUTPOST_DIR . "compass-helpers.php";'
                            . '$_cv = compass_tpl_facet_values(' . $colPhp . ', ' . $namePhp . '); ?>'
                            . '<div data-compass-facet="' . $nameSafe . '" data-compass-type="checkbox" data-compass-collection="' . $colSafe . '" class="compass-checkbox-group">'
                            . '<?php foreach ($_cv as $_ck => $_cc) { ?>'
                            . '<label class="compass-checkbox"><input type="checkbox" value="<?php echo htmlspecialchars($_ck); ?>"> <?php echo htmlspecialchars(ucfirst($_ck)); ?> <span class="compass-count"><?php echo (int)$_cc; ?></span></label>'
                            . '<?php } ?>'
                            . '</div>';

                    case 'radio':
                        return '<?php require_once OUTPOST_DIR . "compass-helpers.php";'
                            . '$_cv = compass_tpl_facet_values(' . $colPhp . ', ' . $namePhp . '); ?>'
                            . '<div data-compass-facet="' . $nameSafe . '" data-compass-type="radio" data-compass-collection="' . $colSafe . '" class="compass-radio-group">'
                            . '<?php foreach ($_cv as $_ck => $_cc) { ?>'
                            . '<label class="compass-radio"><input type="radio" name="compass_' . $nameSafe . '" value="<?php echo htmlspecialchars($_ck); ?>"> <?php echo htmlspecialchars(ucfirst($_ck)); ?> <span class="compass-count"><?php echo (int)$_cc; ?></span></label>'
                            . '<?php } ?>'
                            . '</div>';

                    case 'search':
                        $fields = htmlspecialchars($attrs['fields'] ?? 'title', ENT_QUOTES);
                        $placeholder = htmlspecialchars($attrs['placeholder'] ?? 'Search...', ENT_QUOTES);
                        return '<div data-compass-facet="q" data-compass-type="search" data-compass-collection="' . $colSafe . '">'
                            . '<input type="text" class="compass-search input" placeholder="' . $placeholder . '" data-compass-fields="' . $fields . '">'
                            . '</div>';

                    case 'range':
                        $min  = (int) ($attrs['min'] ?? 0);
                        $max  = (int) ($attrs['max'] ?? 100);
                        $step = (int) ($attrs['step'] ?? 1);
                        $prefix = htmlspecialchars($attrs['prefix'] ?? '', ENT_QUOTES);
                        return '<div data-compass-facet="' . $nameSafe . '" data-compass-type="range" data-compass-collection="' . $colSafe . '" data-compass-min="' . $min . '" data-compass-max="' . $max . '" data-compass-step="' . $step . '">'
                            . '<div class="compass-range">'
                            . '<input type="range" class="compass-range-min" data-compass-range="min" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $min . '">'
                            . '<input type="range" class="compass-range-max" data-compass-range="max" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $max . '">'
                            . '<div class="compass-range-display"><span class="compass-range-min-val" data-compass-range-min-display>' . $prefix . $min . '</span> – <span class="compass-range-max-val" data-compass-range-max-display>' . $prefix . $max . '</span></div>'
                            . '</div></div>';

                    case 'az':
                        $letters = '<button class="compass-az-btn compass-az-active" data-compass-az="">All</button>';
                        foreach (range('A', 'Z') as $l) {
                            $letters .= '<button class="compass-az-btn" data-compass-az="' . $l . '">' . $l . '</button>';
                        }
                        return '<div data-compass-facet="' . $nameSafe . '" data-compass-type="az" data-compass-collection="' . $colSafe . '" class="compass-az">'
                            . $letters . '</div>';

                    case 'toggle':
                        $label = htmlspecialchars($attrs['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $name)), ENT_QUOTES);
                        return '<?php require_once OUTPOST_DIR . "compass-helpers.php";'
                            . '$_ct = compass_tpl_facet_count(' . $colPhp . ', ' . $namePhp . '); ?>'
                            . '<div data-compass-facet="' . $nameSafe . '" data-compass-type="toggle" data-compass-collection="' . $colSafe . '" class="compass-toggle-wrap">'
                            . '<label class="compass-toggle-label">'
                            . '<input type="checkbox" class="compass-toggle-input">'
                            . '<span class="compass-toggle-switch"></span>'
                            . ' ' . $label . ' <span class="compass-count"><?php echo (int)$_ct; ?></span>'
                            . '</label></div>';

                    case 'proximity':
                        $radius = (int) ($attrs['radius'] ?? 25);
                        $unit = htmlspecialchars($attrs['unit'] ?? 'miles', ENT_QUOTES);
                        return '<div data-compass-facet="proximity" data-compass-type="proximity" data-compass-collection="' . $colSafe . '" data-compass-radius="' . $radius . '" data-compass-unit="' . $unit . '" class="compass-proximity">'
                            . '<button class="compass-proximity-btn btn btn-outline btn-sm" type="button" data-compass-proximity>'
                            . '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
                            . ' Near Me</button>'
                            . '<select class="compass-proximity-radius input" data-compass-radius style="display:none;">'
                            . '<option value="5">5 ' . $unit . '</option>'
                            . '<option value="10">10 ' . $unit . '</option>'
                            . '<option value="25"' . ($radius === 25 ? ' selected' : '') . '>25 ' . $unit . '</option>'
                            . '<option value="50">50 ' . $unit . '</option>'
                            . '</select></div>';

                    case 'hierarchy':
                        return '<?php require_once OUTPOST_DIR . "compass-helpers.php";'
                            . '$_ch = compass_tpl_hierarchy_top(' . $colPhp . ', ' . $namePhp . '); ?>'
                            . '<div data-compass-facet="' . $nameSafe . '" data-compass-type="hierarchy" data-compass-collection="' . $colSafe . '" class="compass-hierarchy">'
                            . '<select class="compass-hierarchy-level input" data-compass-level="0">'
                            . '<option value="">All ' . htmlspecialchars(ucfirst($name), ENT_QUOTES) . '</option>'
                            . '<?php foreach ($_ch as $_hv) { ?>'
                            . '<option value="<?php echo htmlspecialchars($_hv); ?>"><?php echo htmlspecialchars($_hv); ?></option>'
                            . '<?php } ?>'
                            . '</select></div>';

                    case 'time-since':
                        return '<div data-compass-facet="' . $nameSafe . '" data-compass-type="time-since" data-compass-collection="' . $colSafe . '" class="compass-time-since">'
                            . '<select class="input">'
                            . '<option value="">Any Time</option>'
                            . '<option value="7">Past 7 days</option>'
                            . '<option value="30">Past 30 days</option>'
                            . '<option value="90">Past 90 days</option>'
                            . '<option value="365">Past year</option>'
                            . '</select></div>';

                    case 'pager':
                        $perPage = (int) ($attrs['per-page'] ?? $attrs['perpage'] ?? 12);
                        return '<div data-compass-facet="pager" data-compass-pager data-compass-type="pager" data-compass-collection="' . $colSafe . '" data-compass-per-page="' . $perPage . '" class="compass-pager"></div>';

                    default:
                        return '<!-- outpost-compass: unknown type "' . htmlspecialchars($type) . '" -->';
                }
            },
            $html
        );

        // ── Results container: <outpost-compass-results collection="..." layout="..." columns="...">...</outpost-compass-results> ──
        $html = preg_replace_callback(
            '/<outpost-compass-results\s+([^>]*?)>(.*?)<\/outpost-compass-results>/is',
            function ($m) use ($editorMode) {
                $attrs   = self::parseAttributes($m[1]);
                $inner   = $m[2];
                $col     = $attrs['collection'] ?? '';
                $layout  = $attrs['layout'] ?? 'grid';
                $columns = (int) ($attrs['columns'] ?? 3);
                $partial = $attrs['partial'] ?? '';

                if (!$col) return '<!-- outpost-compass-results: collection required -->';

                $colSafe     = htmlspecialchars($col, ENT_QUOTES);
                $layoutSafe  = htmlspecialchars($layout, ENT_QUOTES);
                $partialSafe = htmlspecialchars($partial, ENT_QUOTES);

                // Build grid class
                $gridClass = 'compass-results';
                if ($layout === 'grid') {
                    $gridClass .= ' grid';
                    if ($columns === 2) $gridClass .= ' md:grid-cols-2';
                    elseif ($columns === 3) $gridClass .= ' md:grid-cols-2 lg:grid-cols-3';
                    elseif ($columns === 4) $gridClass .= ' md:grid-cols-2 lg:grid-cols-4';
                    $gridClass .= ' gap-6';
                } elseif ($layout === 'list') {
                    $gridClass .= ' compass-results--list';
                }

                // Compile inner template as item-scoped fields (same as outpost-each)
                $compiledInner = self::compileItemFields($inner, $editorMode);
                $colPhp = self::phpString($col);

                $php = '<div data-compass-results data-compass-collection="' . $colSafe . '" data-compass-layout="' . $layoutSafe . '" data-compass-columns="' . $columns . '"'
                    . ($partial ? ' data-compass-partial="' . $partialSafe . '"' : '')
                    . ' class="' . $gridClass . '">';
                $php .= '<?php cms_collection_list(' . $colPhp . ', function($item) { ?>';
                $php .= $compiledInner;
                $php .= '<?php }); ?>';
                $php .= '</div>';

                return $php;
            },
            $html
        );

        // ── Count: <outpost-compass-count collection="..." /> ──
        $html = preg_replace_callback(
            '/<outpost-compass-count\s+([^>]*?)\/?>(.*?)(?:<\/outpost-compass-count>)?/is',
            function ($m) {
                $attrs = self::parseAttributes($m[1]);
                $col = $attrs['collection'] ?? '';
                if (!$col) return '';
                $colSafe = htmlspecialchars($col, ENT_QUOTES);
                $colPhp  = self::phpString($col);
                return '<?php require_once OUTPOST_DIR . "compass-helpers.php";?>'
                    . '<span data-compass-count data-compass-collection="' . $colSafe . '" class="compass-result-count">'
                    . '<?php echo compass_tpl_total_count(' . $colPhp . '); ?></span>';
            },
            $html
        );

        // ── Reset: <outpost-compass-reset collection="..." /> ──
        $html = preg_replace_callback(
            '/<outpost-compass-reset\s+([^>]*?)\/?>(.*?)(?:<\/outpost-compass-reset>)?/is',
            function ($m) {
                $attrs = self::parseAttributes($m[1]);
                $col = $attrs['collection'] ?? '';
                if (!$col) return '';
                $colSafe = htmlspecialchars($col, ENT_QUOTES);
                $label = htmlspecialchars($attrs['label'] ?? 'Clear All Filters', ENT_QUOTES);
                return '<button data-compass-reset data-compass-collection="' . $colSafe . '" class="compass-reset btn btn-outline btn-sm">' . $label . '</button>';
            },
            $html
        );

        // ── Selections: <outpost-compass-selections collection="..." /> ──
        $html = preg_replace_callback(
            '/<outpost-compass-selections\s+([^>]*?)\/?>(.*?)(?:<\/outpost-compass-selections>)?/is',
            function ($m) {
                $attrs = self::parseAttributes($m[1]);
                $col = $attrs['collection'] ?? '';
                if (!$col) return '';
                $colSafe = htmlspecialchars($col, ENT_QUOTES);
                return '<div data-compass-selections data-compass-collection="' . $colSafe . '" class="compass-selections"></div>';
            },
            $html
        );

        // ── Sort: <outpost-compass-sort collection="..." options="field1,field2" labels="Label 1,Label 2" /> ──
        $html = preg_replace_callback(
            '/<outpost-compass-sort\s+([^>]*?)\/?>(.*?)(?:<\/outpost-compass-sort>)?/is',
            function ($m) {
                $attrs = self::parseAttributes($m[1]);
                $col = $attrs['collection'] ?? '';
                if (!$col) return '';
                $colSafe = htmlspecialchars($col, ENT_QUOTES);
                $options = array_map('trim', explode(',', $attrs['options'] ?? 'title'));
                $labels  = array_map('trim', explode(',', $attrs['labels'] ?? ''));

                $out = '<div data-compass-facet="sort" data-compass-type="sort" data-compass-collection="' . $colSafe . '" class="compass-sort">'
                    . '<select class="compass-sort-select input">'
                    . '<option value="">Sort by...</option>';
                foreach ($options as $i => $opt) {
                    $label = $labels[$i] ?? ucfirst(str_replace('_', ' ', $opt));
                    $out .= '<option value="' . htmlspecialchars($opt, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
                }
                $out .= '</select></div>';
                return $out;
            },
            $html
        );

        // Clean up orphaned closing tags
        $html = preg_replace('/<\/outpost-compass(?:-results|-count|-reset|-selections|-sort)?>/i', '', $html);

        return $html;
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Parse HTML attributes from a string into an associative array.
     * Handles boolean attributes (e.g. `empty`, `else`, `related`).
     */
    private static function parseAttributes(string $str): array {
        $attrs = [];

        // Match key="value" pairs
        preg_match_all('/(\w[\w-]*)\s*=\s*"([^"]*)"/', $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $attrs[$m[1]] = $m[2];
        }

        // Match boolean attributes (word not followed by =)
        preg_match_all('/\b(\w[\w-]*)\b(?!\s*=)/', $str, $bools);
        foreach ($bools[1] as $b) {
            if (!isset($attrs[$b])) {
                $attrs[$b] = true;
            }
        }

        return $attrs;
    }

    /**
     * Generate a PHP string literal from a value (properly escaped).
     */
    private static function phpString(string $value): string {
        // Strip null bytes and escape for single-quoted PHP string
        $value = str_replace("\0", '', $value);
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
