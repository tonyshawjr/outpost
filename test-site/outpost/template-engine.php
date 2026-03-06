<?php
/**
 * Outpost CMS — Template Engine
 * Compiles Liquid-style {{ }} / {% %} templates to PHP.
 * Compiled output is cached — first request compiles, all subsequent requests run cached PHP.
 *
 * Syntax:
 *   {{ field_name }}              → cms_text('field_name')  (auto-escaped)
 *   {{ field_name | raw }}        → cms_richtext('field_name')  (no escaping, for HTML)
 *   {{ field_name | image }}      → cms_image('field_name')
 *   {{ field_name | link }}       → cms_link('field_name')
 *   {{ field_name | color }}      → cms_color('field_name')
 *   {{ field_name | number }}     → cms_number('field_name')
 *   {{ field_name | date }}       → cms_date('field_name')
 *   {{ field_name | textarea }}   → cms_textarea('field_name')
 *   {{ field_name | toggle }}     → cms_toggle('field_name')  (returns bool, for use in {% if %})
 *   {{ field_name | select }}     → cms_select('field_name')  (for selects, just outputs value)
 *
 *   {{ @global_name }}            → cms_global('global_name')
 *   {{ @global_name | raw }}      → cms_global('global_name', 'richtext')
 *
 *   {{ meta.title "Default" }}    → cms_meta_title('meta_title_field', 'Default')
 *   {{ meta.description "Def" }}  → cms_meta_description('meta_desc_field', 'Default')
 *
 *   Wrapping-tag defaults (recommended — supports multiline & HTML):
 *   {{ field }}Default{{ /field }}              → cms_text('field', 'Default')
 *   {{ field | raw }}<p>HTML</p>{{ /field }}    → cms_richtext('field', '<p>HTML</p>')
 *   {{ meta.title }}My Site{{ /meta.title }}    → cms_meta_title('meta_title', 'My Site')
 *
 *   {% for item in collection.blog %}
 *     {{ item.title }}            → $item['title'] (auto-escaped)
 *     {{ item.body | raw }}       → $item['body'] (raw)
 *     {{ item.url }}              → $item['url']
 *   {% endfor %}
 *
 *   {% if field_name %}           → if (cms_toggle('field_name')):
 *   {% endif %}
 *
 *   {% include 'header' %}        → include theme partials/header.html (compiled)
 *   {% include 'footer' %}        → include theme partials/footer.html (compiled)
 *
 *   {# This is a comment #}      → stripped entirely
 */

define('OUTPOST_TEMPLATE_CACHE_DIR', OUTPOST_CACHE_DIR . 'templates/');

class OutpostTemplate {
    private static string $themePath = '';

    /**
     * Render a theme template file. Compiles if needed, then includes cached PHP.
     */
    public static function render(string $templateFile, string $themePath): void {
        self::$themePath = rtrim($themePath, '/');

        if (!file_exists($templateFile)) {
            http_response_code(404);
            echo '<!-- Template not found: ' . htmlspecialchars(basename($templateFile)) . ' -->';
            return;
        }

        $compiled = self::getCompiledPath($templateFile);

        // Recompile if source is newer than cache
        if (!file_exists($compiled) || filemtime($templateFile) > filemtime($compiled)) {
            $source = file_get_contents($templateFile);
            try {
                $php = self::compile($source);
            } catch (\Throwable $e) {
                self::renderError('Template compile error', $e, $templateFile);
                return;
            }

            $dir = dirname($compiled);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($compiled, $php, LOCK_EX);
        }

        // Buffer output to inject tracker before </body>
        ob_start();
        try {
            include $compiled;
        } catch (\Throwable $e) {
            ob_end_clean();
            // Remove bad cache so next request recompiles
            @unlink($compiled);
            self::renderError('Template runtime error', $e, $templateFile);
            return;
        }
        $output = ob_get_clean();

        $tracker = self::getTrackerSnippet();
        if ($tracker && stripos($output, '</body>') !== false) {
            $output = str_ireplace('</body>', $tracker . '</body>', $output);
        }

        echo $output;
    }

    /**
     * Returns the tiny JS tracker snippet to inject into public pages.
     */
    private static function getTrackerSnippet(): string {
        $t = json_encode('/outpost/track.php');

        return '<script>(function(){' .
            'var T=' . $t . ';' .
            // Pageview (existing behavior)
            'var d={path:location.pathname,ref:document.referrer,w:screen.width};' .
            'var u=T+"?"+new URLSearchParams(d).toString();' .
            'fetch(u,{method:"GET",keepalive:true,mode:"no-cors"}).catch(function(){new Image().src=u;});' .
            // outpost.track() API
            'window.outpost={track:function(n,p){' .
                'if(!n)return;' .
                'var q={type:"event",name:n,path:location.pathname};' .
                'if(p)try{q.props=JSON.stringify(p)}catch(e){}' .
                'var eu=T+"?"+new URLSearchParams(q).toString();' .
                'fetch(eu,{method:"GET",keepalive:true,mode:"no-cors"}).catch(function(){new Image().src=eu;});' .
            '}};' .
            // Auto-track: outbound links + file downloads
            'document.addEventListener("click",function(e){' .
                'var a=e.target.closest("a");if(!a)return;var h=a.href||"";' .
                'if(h&&a.hostname&&a.hostname!==location.hostname){' .
                    'outpost.track("outbound_click",{url:h,text:(a.textContent||"").slice(0,100)})' .
                '}' .
                'var ext=(h.split("?")[0].split(".").pop()||"").toLowerCase();' .
                'if(["pdf","zip","doc","docx","xls","xlsx","csv","mp3","mp4","dmg","exe"].indexOf(ext)!==-1){' .
                    'outpost.track("file_download",{url:h,ext:ext})' .
                '}' .
            '});' .
            // Auto-track: form submissions
            'document.addEventListener("submit",function(e){' .
                'var f=e.target;if(f.tagName==="FORM"){' .
                    'outpost.track("form_submit",{action:f.action||"",id:f.id||"",name:f.getAttribute("name")||""})' .
                '}' .
            '})' .
        '})();</script>';
    }

    /**
     * Get the cached PHP path for a template file.
     */
    private static function getCompiledPath(string $templateFile): string {
        $hash = md5(realpath($templateFile) ?: $templateFile);
        return OUTPOST_TEMPLATE_CACHE_DIR . $hash . '.php';
    }

    /**
     * Compile template source to PHP.
     */
    public static function compile(string $source): string {
        $php = $source;

        // Strip comments: {# ... #}
        $php = preg_replace('/\{#.*?#\}/s', '', $php);

        // {% include 'partial' %} → include compiled partial
        $php = preg_replace_callback(
            '/\{%\s*include\s+[\'"]([a-zA-Z0-9_\-\/]+)[\'"]\s*%\}/',
            function ($m) {
                $partial = $m[1];
                return '<?php OutpostTemplate::renderPartial(\'' . addslashes($partial) . '\'); ?>';
            },
            $php
        );

        // {% for child in item.children %} — iterates a PHP variable's array field.
        // Must run FIRST so inner loops are compiled before outer namespace loops run.
        // Uses a negative lookahead to exclude named namespaces so the regex never
        // matches them — avoiding the "skip via return $m[0]" trap that caused the
        // outer match to consume inner {% endfor %} tags prematurely.
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+(?!collection\b|gallery\b|repeater\b|menu\b|taxonomy\b|folder\b|channel\b)(\w+)\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $loopVar   = $m[1];
                $parentVar = $m[2];
                $field     = $m[3];
                $body      = $m[4];
                return "<?php foreach (\${$parentVar}['{$field}'] ?? [] as \${$loopVar}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in menu.slug %} ... {% endfor %}
        // Loads a named navigation menu. After inner loops compiled, this captures the full outer block.
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+menu\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_menu_items('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in gallery.field_name %} ... {% endfor %}
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+gallery\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_gallery_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in repeater.field_name [key:type,...] %} ... {% endfor %}
        // Process before collection loops so the endfor is consumed correctly.
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+repeater\.(\w+)(?:\s+[\w:,\s]*)?\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_repeater_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for block in flexible.field_name %} ... {% endfor %}
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+flexible\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_flexible_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in relationship.field_name %} ... {% endfor %}
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+relationship\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_relationship_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for label in folder.slug %} ... {% endfor %} (new syntax)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+folder\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_folder_labels('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for term in taxonomy.slug %} ... {% endfor %} (backward compat)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+taxonomy\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                return "<?php foreach (cms_folder_labels('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // Channel loop options parser helper
        $parseChannelOpts = function (string $optsStr): string {
            $parts = [];
            if (preg_match('/\blimit:(\d+)\b/', $optsStr, $m)) $parts[] = "'limit' => " . (int)$m[1];
            if (preg_match('/\borderby:(\w+)\b/', $optsStr, $m)) $parts[] = "'order' => '{$m[1]} DESC'";
            return '[' . implode(', ', $parts) . ']';
        };

        // {% for item in channel.slug [limit:N] %} ... {% else %} ... {% endfor %}
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+channel\.(\w+)([^%]*)%\}(.*?)\{%\s*else\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) use ($parseChannelOpts) {
                $var       = $m[1];
                $slug      = $m[2];
                $itemBody  = $m[4];
                $emptyBody = $m[5];
                $opts      = $parseChannelOpts($m[3]);
                return "<?php \$_outpost_found_{$var} = false; cms_channel_list('{$slug}', function(\${$var}) use (&\$_outpost_found_{$var}) { \$_outpost_found_{$var} = true; ?>"
                     . $itemBody
                     . "<?php }, {$opts}); if (!\$_outpost_found_{$var}): ?>"
                     . $emptyBody
                     . "<?php endif; ?>";
            },
            $php
        );
        // Channel loop without else
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+channel\.(\w+)([^%]*)%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) use ($parseChannelOpts) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[4];
                $opts = $parseChannelOpts($m[3]);
                return "<?php cms_channel_list('{$slug}', function(\${$var}) { ?>"
                     . $body
                     . "<?php }, {$opts}); ?>";
            },
            $php
        );

        // Collection loop options parser helper
        // Parses any combination of: limit:N  orderby:field  related:varname (any order)
        $parseCollOpts = function (string $optsStr): string {
            $parts = [];
            if (preg_match('/\blimit:(\d+)\b/', $optsStr, $m))       $parts[] = "'limit' => " . (int)$m[1];
            if (preg_match('/\borderby:(\w+)\b/', $optsStr, $m))     $parts[] = "'order' => '{$m[1]} DESC'";
            if (preg_match('/\brelated:(\w+)\b/', $optsStr, $m))     $parts[] = "'related_id' => (\${$m[1]}['id'] ?? 0)";
            if (preg_match('/\bfilteredby:(\w+)\b/', $optsStr, $m))  $parts[] = "'filter_param' => '{$m[1]}'";
            if (preg_match('/\bpaginate:(\d+)\b/', $optsStr, $m))    $parts[] = "'paginate' => " . (int)$m[1];
            return '[' . implode(', ', $parts) . ']';
        };

        // {% for item in collection.slug [limit:N] [orderby:field] [related:var] %} ... {% else %} ... {% endfor %}
        // Pass 1: for-else-endfor (with empty-state fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+collection\.(\w+)([^%]*)%\}(.*?)\{%\s*else\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) use ($parseCollOpts) {
                $var       = $m[1];
                $slug      = $m[2];
                $itemBody  = $m[4];
                $emptyBody = $m[5];
                $opts      = $parseCollOpts($m[3]);
                return "<?php \$_outpost_found_{$var} = false; cms_collection_list('{$slug}', function(\${$var}) use (&\$_outpost_found_{$var}) { \$_outpost_found_{$var} = true; ?>"
                     . $itemBody
                     . "<?php }, {$opts}); if (!\$_outpost_found_{$var}): ?>"
                     . $emptyBody
                     . "<?php endif; ?>";
            },
            $php
        );
        // Pass 2: for-endfor (no else) — options parsed in any order
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+collection\.(\w+)([^%]*)%\}(.*?)\{%\s*endfor\s*%\}/s',
            function ($m) use ($parseCollOpts) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[4];
                $opts = $parseCollOpts($m[3]);
                return "<?php cms_collection_list('{$slug}', function(\${$var}) { ?>"
                     . $body
                     . "<?php }, {$opts}); ?>";
            },
            $php
        );

        // {% form 'slug' %} — render a builder-defined form
        $php = preg_replace_callback(
            '/\{%\s*form\s+[\'"]([a-zA-Z0-9_-]+)[\'"]\s*%\}/',
            function ($m) {
                $slug = $m[1];
                return "<?php cms_form('{$slug}'); ?>";
            },
            $php
        );

        // {% seo %} — full SEO block (title, meta, OG, Twitter Card, canonical, JSON-LD)
        $php = preg_replace('/\{%\s*seo\s*%\}/', '<?php cms_seo(); ?>', $php);

        // {% pagination %} — renders page links after a paginate: collection loop
        $php = preg_replace('/\{%\s*pagination\s*%\}/', '<?php cms_pagination(); ?>', $php);

        // {% single var from collection.slug %} ... {% else %} ... {% endsingle %}
        $php = preg_replace_callback(
            '/\{%\s*single\s+(\w+)\s+from\s+collection\.(\w+)\s*%\}/',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                return "<?php \$_outpost_{$var} = cms_collection_single('{$slug}'); if (\$_outpost_{$var}): \${$var} = \$_outpost_{$var}; \$GLOBALS['_outpost_in_single'] = true; ?>";
            },
            $php
        );

        // {% single var from channel.slug %} ... {% else %} ... {% endsingle %}
        $php = preg_replace_callback(
            '/\{%\s*single\s+(\w+)\s+from\s+channel\.(\w+)\s*%\}/',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                return "<?php \$_outpost_{$var} = cms_channel_single('{$slug}'); if (\$_outpost_{$var}): \${$var} = \$_outpost_{$var}; ?>";
            },
            $php
        );

        $php = preg_replace('/\{%\s*endsingle\s*%\}/', '<?php $GLOBALS[\'_outpost_in_single\'] = false; endif; ?>', $php);

        // {% if item.field == "value" %} or {% if item.field != "value" %}
        $php = preg_replace_callback(
            '/\{%\s*if\s+(\w+)\.(\w+)\s*(==|!=)\s*"([^"]*)"\s*%\}/',
            function ($m) {
                $var   = $m[1];
                $field = $m[2];
                $op    = $m[3] === '==' ? '===' : '!==';
                $val   = addslashes($m[4]);
                return "<?php if ((\${$var}['{$field}'] ?? '') {$op} '{$val}'): ?>";
            },
            $php
        );

        // {% if item.field %} — check non-empty collection/single variable field
        $php = preg_replace_callback(
            '/\{%\s*if\s+(\w+)\.(\w+)\s*%\}/',
            function ($m) {
                $var   = $m[1];
                $field = $m[2];
                return "<?php if (!empty(\${$var}['{$field}'])): ?>";
            },
            $php
        );

        // {% if @global_name %} — check global field is non-empty
        $php = preg_replace_callback(
            '/\{%\s*if\s+@(\w+)\s*%\}/',
            function ($m) {
                $name = $m[1];
                return "<?php if (cms_global_get('{$name}')): ?>";
            },
            $php
        );

        // {% if admin %} — true only for logged-in Outpost admins
        $php = preg_replace('/\{%\s*if\s+admin\s*%\}/', '<?php if (outpost_is_admin()): ?>', $php);

        // {% if field_name %} — truthy check (works for toggles and any non-empty field)
        $php = preg_replace_callback(
            '/\{%\s*if\s+(\w+)\s*%\}/',
            function ($m) {
                $field = $m[1];
                return "<?php if (cms_field_truthy('{$field}')): ?>";
            },
            $php
        );
        $php = preg_replace('/\{%\s*else\s*%\}/', '<?php else: ?>', $php);
        $php = preg_replace('/\{%\s*endif\s*%\}/', '<?php endif; ?>', $php);

        // {{ meta.title "Default" }} and {{ meta.description "Default" }}
        $php = preg_replace_callback(
            '/\{\{\s*meta\.(title|description)\s+"([^"]*?)"\s*\}\}/',
            function ($m) {
                $type = $m[1];
                $default = addslashes($m[2]);
                $func = $type === 'title' ? 'cms_meta_title' : 'cms_meta_description';
                return "<?php {$func}('meta_{$type}', '{$default}'); ?>";
            },
            $php
        );

        // {{ @global_name | filter }}Default{{ /@global_name }} — wrapping global with filter
        $php = preg_replace_callback(
            '/\{\{\s*@(\w+)\s*\|\s*(\w+)\s*\}\}(.*?)\{\{\s*\/@\1\s*\}\}/s',
            function ($m) {
                $name = $m[1];
                $filter = $m[2];
                $default = addslashes($m[3]);
                return "<?php cms_global('{$name}', '{$filter}', '{$default}'); ?>";
            },
            $php
        );

        // {{ @global_name }}Default{{ /@global_name }} — wrapping global plain text
        $php = preg_replace_callback(
            '/\{\{\s*@(\w+)\s*\}\}(.*?)\{\{\s*\/@\1\s*\}\}/s',
            function ($m) {
                $name = $m[1];
                $default = addslashes($m[2]);
                return "<?php cms_global('{$name}', 'text', '{$default}'); ?>";
            },
            $php
        );

        // {{ @global_name | type }} — global fields (no wrapping default)
        $php = preg_replace_callback(
            '/\{\{\s*@(\w+)\s*(?:\|\s*(\w+))?\s*\}\}/',
            function ($m) {
                $name = $m[1];
                $filter = $m[2] ?? 'text';
                return "<?php cms_global('{$name}', '{$filter}'); ?>";
            },
            $php
        );

        // {{ item.field }} and {{ item.field | raw }} — loop variable access
        // Uses OPE wrapper functions for on-page editing support in {% single %} blocks
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)\.(\w+)\s*(?:\|\s*(\w+))?\s*\}\}/',
            function ($m) {
                $var = $m[1];
                $field = $m[2];
                $filter = $m[3] ?? null;

                // Skip meta.* and collection.* (already handled above)
                if ($var === 'meta' || $var === 'collection') return $m[0];

                if ($filter === 'raw') {
                    return "<?= outpost_ope_item_raw(\${$var}, '{$field}') ?>";
                }
                if ($filter === 'image') {
                    return "<?= outpost_ope_item_image(\${$var}, '{$field}') ?>";
                }
                // or_body: use excerpt if set, otherwise auto-generate from body
                if ($filter === 'or_body') {
                    return "<?= outpost_esc(\${$var}['{$field}'] ?: outpost_auto_excerpt(\${$var}['body'] ?? '')) ?>";
                }
                return "<?= outpost_ope_item_text(\${$var}, '{$field}') ?>";
            },
            $php
        );

        // --- Wrapping-tag defaults: {{ field }}Default{{ /field }} ---
        // These must run BEFORE the inline-default regexes so the opening {{ field }}
        // isn't consumed as a no-default tag before the closing tag is seen.

        // {{ meta.title }}Default{{ /meta.title }} and {{ meta.description }}Default{{ /meta.description }}
        $php = preg_replace_callback(
            '/\{\{\s*meta\.(title|description)\s*\}\}(.*?)\{\{\s*\/meta\.\1\s*\}\}/s',
            function ($m) {
                $type = $m[1];
                $default = addslashes($m[2]);
                $func = $type === 'title' ? 'cms_meta_title' : 'cms_meta_description';
                return "<?php {$func}('meta_{$type}', '{$default}'); ?>";
            },
            $php
        );

        // {{ field | filter }}Default{{ /field }} — wrapping filtered fields
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\|\s*(\w+)\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/s',
            function ($m) {
                $name = $m[1];
                $filter = $m[2];
                $default = addslashes($m[3]);

                $map = [
                    'raw'      => "cms_richtext('{$name}', '{$default}')",
                    'image'    => "cms_image('{$name}', '{$default}')",
                    'link'     => "cms_link('{$name}', '{$default}')",
                    'color'    => "cms_color('{$name}', '{$default}')",
                    'number'   => "cms_number('{$name}', '{$default}')",
                    'date'     => "cms_date('{$name}', '{$default}')",
                    'textarea' => "cms_textarea('{$name}', '{$default}')",
                    'select'   => "cms_text('{$name}', '{$default}')",
                    'toggle'   => "cms_toggle('{$name}')",
                ];

                $call = $map[$filter] ?? "cms_text('{$name}', '{$default}')";
                return "<?php {$call}; ?>";
            },
            $php
        );

        // {{ field }}Default{{ /field }} — wrapping plain text fields
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/s',
            function ($m) {
                $name = $m[1];
                $default = addslashes($m[2]);
                return "<?php cms_text('{$name}', '{$default}'); ?>";
            },
            $php
        );

        // --- Inline defaults (backwards-compatible) ---

        // {{ field_name | filter "default" }} or {{ field_name | filter }} — CMS fields with explicit type
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\|\s*(\w+)(?:\s+"([^"]*)")?\s*\}\}/',
            function ($m) {
                $name = $m[1];
                $filter = $m[2];
                $default = addslashes($m[3] ?? '');

                $map = [
                    'raw'      => "cms_richtext('{$name}', '{$default}')",
                    'image'    => "cms_image('{$name}', '{$default}')",
                    'link'     => "cms_link('{$name}', '{$default}')",
                    'color'    => "cms_color('{$name}', '{$default}')",
                    'number'   => "cms_number('{$name}', '{$default}')",
                    'date'     => "cms_date('{$name}', '{$default}')",
                    'textarea' => "cms_textarea('{$name}', '{$default}')",
                    'select'   => "cms_text('{$name}', '{$default}')",
                    'toggle'   => "cms_toggle('{$name}')",
                ];

                $call = $map[$filter] ?? "cms_text('{$name}', '{$default}')";
                return "<?php {$call}; ?>";
            },
            $php
        );

        // {{ field_name "default" }} or {{ field_name }} — plain CMS text field (must be last)
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)(?:\s+"([^"]*)")?\s*\}\}/',
            function ($m) {
                $name = $m[1];
                $default = addslashes($m[2] ?? '');
                return "<?php cms_text('{$name}', '{$default}'); ?>";
            },
            $php
        );

        // Prepend engine include
        $php = "<?php require_once '" . addslashes(OUTPOST_DIR) . "engine.php'; ?>\n" . $php;

        return $php;
    }

    /**
     * Render a partial template from the current theme's partials/ directory.
     */
    public static function renderPartial(string $name): void {
        $file = self::$themePath . '/partials/' . $name . '.html';
        if (!file_exists($file)) {
            echo '<!-- Partial not found: ' . htmlspecialchars($name) . ' -->';
            return;
        }

        $compiled = self::getCompiledPath($file);

        if (!file_exists($compiled) || filemtime($file) > filemtime($compiled)) {
            $source = file_get_contents($file);

            try {
                $partialPhp = self::compilePartial($source);
            } catch (\Throwable $e) {
                echo '<!-- Partial compile error: ' . htmlspecialchars($name) . ' -->';
                error_log('Outpost partial compile error (' . $name . '): ' . $e->getMessage());
                return;
            }

            $dir = dirname($compiled);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($compiled, $partialPhp, LOCK_EX);
        }

        try {
            include $compiled;
        } catch (\Throwable $e) {
            @unlink($compiled);
            echo '<!-- Partial runtime error: ' . htmlspecialchars($name) . ' -->';
            error_log('Outpost partial runtime error (' . $name . '): ' . $e->getMessage());
        }
    }

    /**
     * Compile a partial (same as compile but without engine require).
     */
    private static function compilePartial(string $source): string {
        $full = self::compile($source);
        // Remove the engine require line we prepend (partials inherit from parent)
        $full = preg_replace('/^<\?php require_once .*?engine\.php\';\s*\?>\n/', '', $full);
        return $full;
    }

    /**
     * Render a friendly error page (503) for template failures.
     * Shows details only to logged-in admins; visitors see a generic message.
     */
    private static function renderError(string $title, \Throwable $e, string $templateFile): void {
        http_response_code(503);
        error_log("Outpost {$title} in " . basename($templateFile) . ': ' . $e->getMessage());

        $isAdmin = function_exists('outpost_is_admin') && outpost_is_admin();
        $tpl = htmlspecialchars(basename($templateFile));

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>503 — Site Error</title>'
           . '<style>body{font-family:-apple-system,system-ui,sans-serif;max-width:560px;margin:80px auto;color:#333;line-height:1.5}'
           . 'h1{font-size:22px;margin-bottom:4px}p{color:#666}pre{background:#f5f5f5;padding:16px;border-radius:6px;overflow-x:auto;font-size:13px;color:#c00}</style></head>'
           . '<body><h1>Something went wrong</h1>'
           . '<p>This page couldn&#39;t be rendered. The site owner has been notified.</p>';

        if ($isAdmin) {
            echo '<hr style="margin:24px 0;border:0;border-top:1px solid #eee">'
               . '<p style="color:#999;font-size:13px"><strong>' . htmlspecialchars($title) . '</strong> in <code>' . $tpl . '</code></p>'
               . '<pre>' . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }

        echo '</body></html>';
    }

    /**
     * Clear compiled template cache.
     */
    public static function clearCache(): void {
        $files = glob(OUTPOST_TEMPLATE_CACHE_DIR . '*.php');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
