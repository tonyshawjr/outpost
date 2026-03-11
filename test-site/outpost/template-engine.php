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
                self::validate($source, basename($templateFile));
                $php = self::compile($source, basename($templateFile));
            } catch (\Throwable $e) {
                self::renderError('Template compile error', $e, $templateFile, $source);
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
            // Read compiled source BEFORE deleting cache (needed for line mapping)
            $compiledSource = file_exists($compiled) ? @file_get_contents($compiled) : '';
            ob_end_clean();
            // Remove bad cache so next request recompiles
            @unlink($compiled);
            $runtimeSource = file_exists($templateFile) ? file_get_contents($templateFile) : '';
            self::renderError('Template runtime error', $e, $templateFile, $runtimeSource, '', $compiledSource ?: '');
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
            '},' .
            // outpost.trackSearch() API
            'trackSearch:function(q,r){' .
                'if(!q)return;' .
                'var d={type:"search",query:q,results:r||0};' .
                'var su=T+"?"+new URLSearchParams(d).toString();' .
                'fetch(su,{method:"GET",keepalive:true,mode:"no-cors"}).catch(function(){new Image().src=su;});' .
            '},' .
            // outpost.trackSearchClick() API
            'trackSearchClick:function(q,p){' .
                'if(!q||!p)return;' .
                'var d={type:"search",query:q,results:1,clicked:p};' .
                'var su=T+"?"+new URLSearchParams(d).toString();' .
                'fetch(su,{method:"GET",keepalive:true,mode:"no-cors"}).catch(function(){new Image().src=su;});' .
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
            '});' .
            // Auto-track: search queries from URL params (?q=, ?search=, ?s=)
            '(function(){' .
                'var sp=new URLSearchParams(location.search);' .
                'var sq=sp.get("q")||sp.get("search")||sp.get("s");' .
                'if(sq)outpost.trackSearch(sq)' .
            '})()' .
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
     * Validate template source for balanced block tags.
     * Throws descriptive errors with line numbers for unclosed/mismatched tags.
     */
    public static function validate(string $source, string $filename = 'template'): void {
        // Strip comments first so they don't confuse the scanner
        $clean = preg_replace('/\{#.*?#\}/s', '', $source);

        // Find all block-level opening and closing tags
        $pattern = '/\{%\s*(if|for|single|else|endif|endfor|endsingle)\b[^%]*%\}/';
        preg_match_all($pattern, $clean, $matches, PREG_OFFSET_CAPTURE);

        $stack = []; // [{tag, line}]

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $tag = $matches[1][$i][0]; // the captured keyword
            $line = substr_count($clean, "\n", 0, $offset) + 1;

            if ($tag === 'if' || $tag === 'for' || $tag === 'single') {
                $stack[] = ['tag' => $tag, 'line' => $line, 'has_else' => false];
            } elseif ($tag === 'else') {
                // else must be inside an if, for, or single
                if (empty($stack)) {
                    throw new \RuntimeException(
                        "Unexpected {% else %} on line {$line} of {$filename} — no matching opening tag"
                    );
                }
                $top =& $stack[count($stack) - 1];
                if ($top['has_else']) {
                    throw new \RuntimeException(
                        "Duplicate {% else %} on line {$line} of {$filename} — {% {$top['tag']} %} on line {$top['line']} already has an else clause"
                    );
                }
                $top['has_else'] = true;
                unset($top);
            } elseif ($tag === 'endif' || $tag === 'endfor' || $tag === 'endsingle') {
                $expected = str_replace('end', '', $tag); // 'if', 'for', 'single'
                if (empty($stack)) {
                    throw new \RuntimeException(
                        "Unexpected {% {$tag} %} on line {$line} of {$filename} — no matching opening tag"
                    );
                }
                $top = array_pop($stack);
                if ($top['tag'] !== $expected) {
                    throw new \RuntimeException(
                        "Mismatched tags in {$filename}: expected {% end{$top['tag']} %} (opened on line {$top['line']}) but found {% {$tag} %} on line {$line}"
                    );
                }
            }
        }

        // Check for unclosed tags
        if (!empty($stack)) {
            $top = array_pop($stack);
            throw new \RuntimeException(
                "Unclosed {% {$top['tag']} %} tag opened on line {$top['line']} of {$filename} — add {% end{$top['tag']} %} to close it"
            );
        }
    }

    /**
     * Compile template source to PHP.
     */
    public static function compile(string $source, string $filename = ''): string {
        // Inject line markers before compilation so they survive regex transforms
        $lines = explode("\n", $source);
        $marked = [];
        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            $marked[] = "<?php /* @line:{$lineNum} */ ?>" . $line;
        }
        $php = implode("\n", $marked);

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
            '/\{%\s*for\s+(\w+)\s+in\s+(?!collection\b|gallery\b|repeater\b|menu\b|taxonomy\b|folder\b|channel\b|media_folder\b)(\w+)\.(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s',
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
        // {% for item in menu.slug %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+menu\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_mn_{$var} = cms_menu_items('{$slug}'); if (!empty(\$_outpost_mn_{$var})): foreach (\$_outpost_mn_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_menu_items('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in gallery.field_name %} ... {% endfor %}
        // {% for item in gallery.field_name %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+gallery\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_gl_{$var} = cms_gallery_items('{$name}'); if (!empty(\$_outpost_gl_{$var})): foreach (\$_outpost_gl_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_gallery_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in repeater.field_name [key:type,...] %} ... {% endfor %}
        // {% for item in repeater.field_name %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+repeater\.(\w+)(?:\s+[\w:,\s]*)?\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_rp_{$var} = cms_repeater_items('{$name}'); if (!empty(\$_outpost_rp_{$var})): foreach (\$_outpost_rp_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_repeater_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for block in flexible.field_name %} ... {% endfor %}
        // {% for block in flexible.field_name %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+flexible\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_fx_{$var} = cms_flexible_items('{$name}'); if (!empty(\$_outpost_fx_{$var})): foreach (\$_outpost_fx_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_flexible_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for item in relationship.field_name %} ... {% endfor %}
        // {% for item in relationship.field_name %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+relationship\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $name = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_rl_{$var} = cms_relationship_items('{$name}'); if (!empty(\$_outpost_rl_{$var})): foreach (\$_outpost_rl_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_relationship_items('{$name}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for label in folder.slug %} ... {% endfor %}
        // {% for label in folder.slug %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+folder\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_fl_{$var} = cms_folder_labels('{$slug}'); if (!empty(\$_outpost_fl_{$var})): foreach (\$_outpost_fl_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_folder_labels('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for term in taxonomy.slug %} ... {% endfor %} (backward compat)
        // {% for term in taxonomy.slug %} ... {% else %} ... {% endfor %} (backward compat with else)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+taxonomy\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_fl_{$var} = cms_folder_labels('{$slug}'); if (!empty(\$_outpost_fl_{$var})): foreach (\$_outpost_fl_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_folder_labels('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
            },
            $php
        );

        // {% for img in media_folder.slug %} ... {% endfor %}
        // {% for img in media_folder.slug %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+media_folder\.(\w+)\s*%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) {
                $var  = $m[1];
                $slug = $m[2];
                $body = $m[3];
                if (isset($m[4])) {
                    $empty = $m[4];
                    return "<?php \$_outpost_mf_{$var} = cms_media_folder_items('{$slug}'); if (!empty(\$_outpost_mf_{$var})): foreach (\$_outpost_mf_{$var} as \${$var}): ?>" . $body . "<?php endforeach; else: ?>" . $empty . "<?php endif; ?>";
                }
                return "<?php foreach (cms_media_folder_items('{$slug}') as \${$var}): ?>" . $body . "<?php endforeach; ?>";
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

        // {% for item in channel.slug [limit:N] %} ... {% endfor %}
        // {% for item in channel.slug ... %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+channel\.(\w+)([^%]*)%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) use ($parseChannelOpts) {
                $var  = $m[1];
                $slug = $m[2];
                $opts = $parseChannelOpts($m[3]);
                if (isset($m[5])) {
                    $itemBody  = $m[4];
                    $emptyBody = $m[5];
                    return "<?php \$_outpost_found_{$var} = false; cms_channel_list('{$slug}', function(\${$var}) use (&\$_outpost_found_{$var}) { \$_outpost_found_{$var} = true; ?>"
                         . $itemBody
                         . "<?php }, {$opts}); if (!\$_outpost_found_{$var}): ?>"
                         . $emptyBody
                         . "<?php endif; ?>";
                }
                $body = $m[4];
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

        // {% for item in collection.slug [limit:N] [orderby:field] [related:var] %} ... {% endfor %}
        // {% for item in collection.slug ... %} ... {% else %} ... {% endfor %} (with empty fallback)
        $php = preg_replace_callback(
            '/\{%\s*for\s+(\w+)\s+in\s+collection\.(\w+)([^%]*)%\}(.*?)(?:\{%\s*else\s*%\}(.*?))?\{%\s*endfor\s*%\}/s',
            function ($m) use ($parseCollOpts) {
                $var  = $m[1];
                $slug = $m[2];
                $opts = $parseCollOpts($m[3]);
                if (isset($m[5])) {
                    $itemBody  = $m[4];
                    $emptyBody = $m[5];
                    return "<?php \$_outpost_found_{$var} = false; cms_collection_list('{$slug}', function(\${$var}) use (&\$_outpost_found_{$var}) { \$_outpost_found_{$var} = true; ?>"
                         . $itemBody
                         . "<?php }, {$opts}); if (!\$_outpost_found_{$var}): ?>"
                         . $emptyBody
                         . "<?php endif; ?>";
                }
                $body = $m[4];
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

        // {{ @global_name | filter | edit }}Default{{ /@global_name }} — wrapping global with filter
        $php = preg_replace_callback(
            '/\{\{\s*@(\w+)\s*\|\s*(\w+)(\s*\|\s*edit)?\s*\}\}(.*?)\{\{\s*\/@\1\s*\}\}/s',
            function ($m) {
                $name = $m[1];
                $filter = $m[2];
                $editable = false;
                if ($filter === 'edit') { $filter = 'text'; $editable = true; }
                elseif (!empty($m[3])) { $editable = true; }
                $default = addslashes($m[4]);
                $eb = $editable ? 'true' : 'false';
                return "<?php cms_global('{$name}', '{$filter}', '{$default}', {$eb}); ?>";
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

        // {{ @global_name | type | edit }} — global fields (no wrapping default)
        $php = preg_replace_callback(
            '/\{\{\s*@(\w+)\s*(?:\|\s*(\w+))?(\s*\|\s*edit)?\s*\}\}/',
            function ($m) {
                $name = $m[1];
                $filter = $m[2] ?? 'text';
                $editable = false;
                if ($filter === 'edit') { $filter = 'text'; $editable = true; }
                elseif (!empty($m[3])) { $editable = true; }
                $eb = $editable ? 'true' : 'false';
                return "<?php cms_global('{$name}', '{$filter}', '', {$eb}); ?>";
            },
            $php
        );

        // {{ item.field }} and {{ item.field | raw | edit }} — loop variable access
        // Uses OPE wrapper functions for on-page editing support in {% single %} blocks.
        // When preceded by =" or =' (inside an HTML attribute), skip OPE wrappers that
        // emit <span>/<div> tags — those break attribute values.
        $php = preg_replace_callback(
            '/(=["\'])?\{\{\s*(\w+)\.(\w+)\s*(?:\|\s*(\w+))?(\s*\|\s*edit)?\s*\}\}/',
            function ($m) {
                $inAttr = !empty($m[1]);
                $prefix = $m[1] ?? '';
                $var = $m[2];
                $field = $m[3];
                $filter = $m[4] ?? null;
                $editable = false;
                if ($filter === 'edit') { $filter = null; $editable = true; }
                elseif (!empty($m[5])) { $editable = true; }
                $eb = $editable ? 'true' : 'false';

                // Skip meta.* and collection.* (already handled above)
                if ($var === 'meta' || $var === 'collection') return $m[0];

                if ($filter === 'raw') {
                    if ($inAttr) return "{$prefix}<?= outpost_esc(\${$var}['{$field}'] ?? '') ?>";
                    return "<?= outpost_ope_item_raw(\${$var}, '{$field}', {$eb}) ?>";
                }
                if ($filter === 'image') {
                    return "{$prefix}<?= outpost_ope_item_image(\${$var}, '{$field}', {$eb}) ?>";
                }
                if ($filter === 'focal') {
                    return "{$prefix}<?php outpost_item_focal(\${$var}, '{$field}'); ?>";
                }
                // or_body: use excerpt if set, otherwise auto-generate from body
                if ($filter === 'or_body') {
                    return "{$prefix}<?= outpost_esc(\${$var}['{$field}'] ?: outpost_auto_excerpt(\${$var}['body'] ?? '')) ?>";
                }
                // Inside HTML attributes: plain escaped text (no <span> OPE wrapper)
                if ($inAttr) {
                    return "{$prefix}<?= outpost_esc(\${$var}['{$field}'] ?? '') ?>";
                }
                return "<?= outpost_ope_item_text(\${$var}, '{$field}', {$eb}) ?>";
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

        // {{ field | filter | edit }}Default{{ /field }} — wrapping filtered fields
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\|\s*(\w+)(\s*\|\s*edit)?\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/s',
            function ($m) {
                $name = $m[1];
                $filter = $m[2];
                $editable = false;
                if ($filter === 'edit') { $filter = 'text'; $editable = true; }
                elseif (!empty($m[3])) { $editable = true; }
                $default = addslashes($m[4]);
                $eb = $editable ? 'true' : 'false';

                $map = [
                    'raw'      => "cms_richtext('{$name}', '{$default}', {$eb})",
                    'image'    => "cms_image('{$name}', '{$default}', {$eb})",
                    'link'     => "cms_link('{$name}', '{$default}')",
                    'color'    => "cms_color('{$name}', '{$default}')",
                    'number'   => "cms_number('{$name}', '{$default}')",
                    'date'     => "cms_date('{$name}', '{$default}')",
                    'textarea' => "cms_textarea('{$name}', '{$default}', {$eb})",
                    'select'   => "cms_text('{$name}', '{$default}', {$eb})",
                    'toggle'   => "cms_toggle('{$name}')",
                    'focal'    => "cms_focal('{$name}')",
                ];

                $call = $map[$filter] ?? "cms_text('{$name}', '{$default}', {$eb})";
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

        // {{ field_name | filter | edit }} or {{ field_name | filter "default" | edit }} — CMS fields with explicit type
        $php = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\|\s*(\w+)(?:\s+"([^"]*)")?(\s*\|\s*edit)?\s*\}\}/',
            function ($m) {
                $name = $m[1];
                $filter = $m[2];
                $default = addslashes($m[3] ?? '');
                $editable = false;
                if ($filter === 'edit') { $filter = 'text'; $editable = true; }
                elseif (!empty($m[4])) { $editable = true; }
                $eb = $editable ? 'true' : 'false';

                $map = [
                    'raw'      => "cms_richtext('{$name}', '{$default}', {$eb})",
                    'image'    => "cms_image('{$name}', '{$default}', {$eb})",
                    'link'     => "cms_link('{$name}', '{$default}')",
                    'color'    => "cms_color('{$name}', '{$default}')",
                    'number'   => "cms_number('{$name}', '{$default}')",
                    'date'     => "cms_date('{$name}', '{$default}')",
                    'textarea' => "cms_textarea('{$name}', '{$default}', {$eb})",
                    'select'   => "cms_text('{$name}', '{$default}', {$eb})",
                    'toggle'   => "cms_toggle('{$name}')",
                    'focal'    => "cms_focal('{$name}')",
                ];

                $call = $map[$filter] ?? "cms_text('{$name}', '{$default}', {$eb})";
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
                self::validate($source, 'partials/' . $name . '.html');
                $partialPhp = self::compilePartial($source, $name);
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
    private static function compilePartial(string $source, string $name = ''): string {
        $full = self::compile($source, $name);
        // Remove the engine require line we prepend (partials inherit from parent)
        $full = preg_replace('/^<\?php require_once .*?engine\.php\';\s*\?>\n/', '', $full);
        return $full;
    }

    /**
     * Render a friendly error page (503) for template failures.
     * Admins get source context + friendly messages; visitors see a generic page.
     */
    private static function renderError(string $title, \Throwable $e, string $templateFile, string $source = '', string $compiledFile = '', string $compiledSource = ''): void {
        http_response_code(503);
        error_log("Outpost {$title} in " . basename($templateFile) . ': ' . $e->getMessage());

        $isAdmin = function_exists('outpost_is_admin') && outpost_is_admin();
        $tpl = htmlspecialchars(basename($templateFile));

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>503 — Site Error</title>'
           . '<style>body{font-family:-apple-system,system-ui,sans-serif;max-width:680px;margin:60px auto;color:#333;line-height:1.6;padding:0 20px}'
           . 'h1{font-size:22px;margin-bottom:4px}p{color:#666}'
           . '.error-msg{background:#fef2f2;border:1px solid #fecaca;padding:14px 18px;border-radius:8px;font-size:14px;color:#991b1b;margin:16px 0}'
           . '.source-context{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0;margin:16px 0;overflow:hidden}'
           . '.source-context pre{margin:0;padding:14px 18px;font-size:13px;line-height:1.7;overflow-x:auto;color:#475569}'
           . '.source-line{display:block}.source-line.error-line{background:#fef2f2;color:#991b1b;font-weight:600}'
           . '.line-num{display:inline-block;width:32px;text-align:right;margin-right:12px;color:#94a3b8;user-select:none}'
           . '.error-line .line-num{color:#991b1b}'
           . '.source-header{background:#f1f5f9;padding:8px 18px;font-size:12px;color:#64748b;border-bottom:1px solid #e2e8f0;font-weight:600}'
           . '</style></head>'
           . '<body><h1>Something went wrong</h1>'
           . '<p>This page couldn&#39;t be rendered. The site owner has been notified.</p>';

        if ($isAdmin) {
            // Translate PHP errors into friendly messages
            $friendlyMsg = self::translateError($e->getMessage());
            $templateLine = null;

            // Try to extract template line from the error or from compiled source
            if (preg_match('/line (\d+) of /', $e->getMessage(), $lm)) {
                // Already a template-engine error with line info
                $templateLine = (int) $lm[1];
            } else {
                // Runtime error — map PHP line to template source line
                // Try in-memory compiled source first, fall back to file
                if (!$compiledSource && $compiledFile && file_exists($compiledFile)) {
                    $compiledSource = @file_get_contents($compiledFile) ?: '';
                }
                if ($compiledSource) {
                    $phpLine = $e->getLine();
                    $templateLine = self::mapCompiledLineToSource($compiledSource, $phpLine);
                }
            }

            echo '<div class="error-msg"><strong>' . htmlspecialchars($title) . '</strong> in <code>' . $tpl . '</code>';
            if ($templateLine) {
                echo ' on <strong>line ' . $templateLine . '</strong>';
            }
            echo '<br>' . htmlspecialchars($friendlyMsg) . '</div>';

            // Show source context if we have source and a line number
            if ($source && $templateLine) {
                $lines = explode("\n", $source);
                $start = max(0, $templateLine - 3);
                $end = min(count($lines) - 1, $templateLine + 2);

                echo '<div class="source-context">';
                echo '<div class="source-header">' . $tpl . '</div>';
                echo '<pre>';
                for ($i = $start; $i <= $end; $i++) {
                    $num = $i + 1;
                    $isError = ($num === $templateLine);
                    $class = $isError ? 'source-line error-line' : 'source-line';
                    $marker = $isError ? '>>' : '  ';
                    echo '<span class="' . $class . '">'
                       . '<span class="line-num">' . $num . '</span>'
                       . $marker . ' ' . htmlspecialchars($lines[$i]) . '</span>' . "\n";
                }
                echo '</pre></div>';
            } elseif (!$templateLine) {
                // No line mapping available — show raw error
                echo '<pre style="background:#f5f5f5;padding:16px;border-radius:6px;overflow-x:auto;font-size:13px;color:#c00">'
                   . htmlspecialchars($e->getMessage()) . '</pre>';
            }
        }

        echo '</body></html>';
    }

    /**
     * Map a compiled PHP line number to the original template source line.
     * Scans backwards from the PHP error line looking for the nearest @line:N marker.
     */
    private static function mapCompiledLineToSource(string $compiledSource, int $phpLine): ?int {
        $lines = explode("\n", $compiledSource);
        // Scan from the error line backwards to find the nearest @line marker
        for ($i = min($phpLine - 1, count($lines) - 1); $i >= 0; $i--) {
            if (preg_match('/\/\* @line:(\d+) \*\//', $lines[$i], $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Translate common PHP error messages into friendly, actionable descriptions.
     */
    private static function translateError(string $message): string {
        // Undefined variable
        if (preg_match('/Undefined variable \$?(\w+)/', $message, $m)) {
            return "Unknown variable \${$m[1]}. Check that it's defined in a {% for %} or {% single %} block.";
        }
        // Syntax error
        if (str_contains($message, 'syntax error')) {
            return 'Template syntax error. Check for unclosed tags or invalid template expressions.';
        }
        // Undefined array key
        if (preg_match('/Undefined array key "([^"]+)"/', $message, $m)) {
            return "Field \"{$m[1]}\" is not defined. Check the field name in your template.";
        }
        // Call to undefined function
        if (preg_match('/Call to undefined function (\w+)/', $message, $m)) {
            return "Unknown function {$m[1]}(). This may indicate a missing Outpost feature or a typo.";
        }
        return $message;
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
