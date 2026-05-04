<?php
/**
 * Outpost CMS — Brand Settings
 *
 * Site-wide brand identity: colors, typography (with type scale ratio),
 * logo, and favicon. Stored in content/data/brand.json.
 *
 * Brand settings are site-wide defaults. The per-theme customizer can
 * override any value. For framework-enabled themes, the framework CSS
 * uses brand tokens directly.
 */

// ── Default Brand Schema ────────────────────────────────

function brand_defaults(): array {
    return [
        'colors' => [
            'primary'    => '#2D5A47',
            'secondary'  => '#C4785C',
            'accent'     => '#14B8A6',
            'neutral'    => '#27272A',
            'background' => '#FFFFFF',
            'surface'    => '#FAFAFA',
        ],
        'typography' => [
            'heading_font' => 'Inter',
            'body_font'    => 'Inter',
            'type_scale'   => '1.25',
        ],
        'identity' => [
            'logo'    => '',
            'favicon' => '',
        ],
    ];
}

// ── Type Scale Ratios ───────────────────────────────────

function brand_type_scale_options(): array {
    return [
        ['value' => '1.067', 'label' => 'Minor Second (1.067)'],
        ['value' => '1.125', 'label' => 'Major Second (1.125)'],
        ['value' => '1.2',   'label' => 'Minor Third (1.2)'],
        ['value' => '1.25',  'label' => 'Major Third (1.25)'],
        ['value' => '1.333', 'label' => 'Perfect Fourth (1.333)'],
        ['value' => '1.414', 'label' => 'Augmented Fourth (1.414)'],
        ['value' => '1.5',   'label' => 'Perfect Fifth (1.5)'],
        ['value' => '1.618', 'label' => 'Golden Ratio (1.618)'],
    ];
}

// ── File I/O ────────────────────────────────────────────

function brand_read(): array {
    $path = OUTPOST_DATA_DIR . 'brand.json';
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function brand_save(array $data): void {
    $path = OUTPOST_DATA_DIR . 'brand.json';
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $bytes = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($bytes === false) {
        json_error('Failed to save brand settings', 500);
    }
}

/**
 * Merge saved brand values over defaults.
 * Returns the full brand object with all keys guaranteed.
 */
function brand_get_merged(): array {
    $defaults = brand_defaults();
    $saved = brand_read();

    $result = [];
    foreach ($defaults as $section => $fields) {
        $result[$section] = [];
        foreach ($fields as $key => $default) {
            $result[$section][$key] = $saved[$section][$key] ?? $default;
        }
    }
    return $result;
}

/**
 * Compute the type scale sizes from base (1rem) and ratio.
 * Returns an associative array of token name => rem value.
 */
function brand_compute_type_scale(float $ratio): array {
    $base = 1.0; // 1rem
    return [
        'text-xs'  => round($base / ($ratio * $ratio), 3),
        'text-sm'  => round($base / $ratio, 3),
        'text-base' => $base,
        'text-lg'  => round($base * $ratio, 3),
        'text-xl'  => round($base * $ratio * $ratio, 3),
        'text-2xl' => round($base * pow($ratio, 3), 3),
        'text-3xl' => round($base * pow($ratio, 4), 3),
        'text-4xl' => round($base * pow($ratio, 5), 3),
        'text-5xl' => round($base * pow($ratio, 6), 3),
    ];
}

// ── API Handlers ────────────────────────────────────────

/**
 * GET brand — Returns brand settings + type scale options.
 *
 * Response shape (additive — old + new consumers both work):
 *   {
 *     // Legacy structured shape (used by Brand.svelte)
 *     brand:          { colors: {...}, typography: {...}, identity: {...} },
 *     defaults:       same shape as brand,
 *     scale_options:  [...],
 *     computed_scale: { text-xs: ..., text-sm: ..., ... },
 *
 *     // v6 blueprint shape (used by Design.svelte + PageBuilder live preview)
 *     blueprint: <active theme's blueprint.json contents, or {}>,
 *     saved:     <flat key/value of the saved Design overrides — accent_color,
 *                 heading_font, nav_layout, header_style, etc.>
 *   }
 */
function handle_brand_get(): void {
    outpost_require_cap('settings.*');

    $brand = brand_get_merged();
    $ratio = (float) ($brand['typography']['type_scale'] ?? 1.25);
    $scale = brand_compute_type_scale($ratio);

    // v6 blueprint shape — Design.svelte expects blueprint.brand.*, blueprint.settings.*.
    // Read from the active theme's blueprint.json if present.
    $blueprint = [];
    if (function_exists('outpost_active_theme_root')) {
        $bpPath = outpost_active_theme_root() . 'blueprint.json';
    } else {
        // Fallback (engine.php not loaded yet)
        $bpPath = OUTPOST_SITE_ROOT . 'blueprint.json';
        if (defined('OUTPOST_THEMES_DIR') && function_exists('outpost_get_active_theme')) {
            $themeDir = OUTPOST_THEMES_DIR . outpost_get_active_theme() . '/';
            if (is_dir($themeDir) && file_exists($themeDir . 'blueprint.json')) {
                $bpPath = $themeDir . 'blueprint.json';
            }
        }
    }
    if (file_exists($bpPath)) {
        $decoded = json_decode(file_get_contents($bpPath), true);
        if (is_array($decoded)) $blueprint = $decoded;
    }

    // Saved Design overrides — flat key/value map. Stored alongside brand.json
    // as content/data/design.json. Falls back to mapping legacy structured
    // brand fields onto the flat shape so existing installs see their colors.
    $saved = brand_read_design();
    if (empty($saved)) {
        $saved = [
            'accent_color'  => $brand['colors']['accent']  ?? null,
            'heading_font'  => $brand['typography']['heading_font'] ?? null,
            'body_font'     => $brand['typography']['body_font']    ?? null,
        ];
        // Strip nulls so consumers can use blueprint defaults
        $saved = array_filter($saved, fn($v) => $v !== null && $v !== '');
    }

    json_response([
        // Legacy keys (do not remove — Brand.svelte depends on them)
        'brand'          => $brand,
        'defaults'       => brand_defaults(),
        'scale_options'  => brand_type_scale_options(),
        'computed_scale' => $scale,
        // v6 keys
        'blueprint'      => $blueprint,
        'saved'          => $saved,
    ]);
}

/**
 * Read flat Design overrides (saved by Design.svelte / PageBuilder live preview).
 */
function brand_read_design(): array {
    $path = OUTPOST_DATA_DIR . 'design.json';
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Save flat Design overrides.
 */
function brand_save_design(array $data): void {
    $path = OUTPOST_DATA_DIR . 'design.json';
    $dir  = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    @file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * PUT brand — Save brand settings.
 * Body: { colors: {...}, typography: {...}, identity: {...} }
 */
function handle_brand_save(): void {
    outpost_require_cap('settings.*');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_error('Invalid request body', 400);
    }

    // v6: detect flat Design payload (accent_color, heading_font, nav_layout, etc.)
    // vs legacy structured payload ({ colors: {...}, typography: {...}, identity: {...} }).
    // Flat payloads come from Design.svelte; structured payloads from Brand.svelte.
    $isFlat = !isset($input['colors']) && !isset($input['typography']) && !isset($input['identity'])
        && (isset($input['accent_color']) || isset($input['heading_font']) || isset($input['body_font'])
            || isset($input['nav_layout']) || isset($input['header_style']) || isset($input['site_bg_color']));

    if ($isFlat) {
        // Sanitize + persist flat Design overrides to design.json.
        // Allow simple scalar values (string/bool/number) for keys we recognize.
        $allowedKeys = [
            'accent_color', 'heading_font', 'body_font',
            'nav_layout', 'header_style', 'header_text',
            'site_bg_color', 'header_footer_color',
            'bg_image', 'post_feed_style', 'show_feed_images',
            'show_author', 'show_publish_date', 'show_post_meta',
            'enable_drop_caps', 'show_related',
        ];
        $clean = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $input)) continue;
            $val = $input[$key];
            // Hex colors
            if (in_array($key, ['accent_color', 'site_bg_color', 'header_footer_color'], true)) {
                if (is_string($val) && preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $val)) {
                    $clean[$key] = $val;
                }
                continue;
            }
            // Font names
            if (in_array($key, ['heading_font', 'body_font'], true)) {
                if (is_string($val) && preg_match('/^[a-zA-Z0-9 \'\-]+$/', $val)) {
                    $clean[$key] = $val;
                }
                continue;
            }
            // Selects (limited charset)
            if (in_array($key, ['nav_layout', 'header_style', 'post_feed_style'], true)) {
                if (is_string($val) && preg_match('/^[a-z0-9_-]+$/', $val)) {
                    $clean[$key] = $val;
                }
                continue;
            }
            // Booleans
            if (in_array($key, ['bg_image', 'show_feed_images', 'show_author', 'show_publish_date', 'show_post_meta', 'enable_drop_caps', 'show_related'], true)) {
                $clean[$key] = (bool) $val;
                continue;
            }
            // Free text (header_text)
            if ($key === 'header_text') {
                if (is_string($val)) $clean[$key] = mb_substr($val, 0, 200);
                continue;
            }
        }

        $existing = brand_read_design();
        $merged = array_replace($existing, $clean);
        brand_save_design($merged);

        // Clear template cache so new tokens take effect on next render
        outpost_clear_cache();

        json_response([
            'saved'     => $merged,
            'blueprint' => (function() {
                $bpPath = (function_exists('outpost_active_theme_root') ? outpost_active_theme_root() : OUTPOST_SITE_ROOT) . 'blueprint.json';
                if (!file_exists($bpPath)) return new \stdClass();
                $d = json_decode(file_get_contents($bpPath), true);
                return is_array($d) ? $d : new \stdClass();
            })(),
        ]);
        return;
    }

    $defaults = brand_defaults();
    $clean = [];

    // Validate colors
    if (isset($input['colors']) && is_array($input['colors'])) {
        $clean['colors'] = [];
        foreach ($defaults['colors'] as $key => $default) {
            $val = $input['colors'][$key] ?? $default;
            // Validate hex color
            if (is_string($val) && preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $val)) {
                $clean['colors'][$key] = $val;
            } else {
                $clean['colors'][$key] = $default;
            }
        }
    }

    // Validate typography
    if (isset($input['typography']) && is_array($input['typography'])) {
        $clean['typography'] = [];
        foreach (['heading_font', 'body_font'] as $key) {
            $val = $input['typography'][$key] ?? $defaults['typography'][$key];
            if (is_string($val) && preg_match('/^[a-zA-Z0-9 \'\-]+$/', $val)) {
                $clean['typography'][$key] = $val;
            } else {
                $clean['typography'][$key] = $defaults['typography'][$key];
            }
        }
        // Validate type scale
        $scaleVal = $input['typography']['type_scale'] ?? $defaults['typography']['type_scale'];
        $validScales = array_column(brand_type_scale_options(), 'value');
        $clean['typography']['type_scale'] = in_array($scaleVal, $validScales, true) ? $scaleVal : $defaults['typography']['type_scale'];
    }

    // Validate identity (must be empty or a safe relative path)
    if (isset($input['identity']) && is_array($input['identity'])) {
        $clean['identity'] = [];
        foreach (['logo', 'favicon'] as $key) {
            $val = $input['identity'][$key] ?? '';
            if (is_string($val) && ($val === '' || preg_match('#^uploads/[a-zA-Z0-9._/ -]+$#', $val))) {
                $clean['identity'][$key] = $val;
            } else {
                $clean['identity'][$key] = '';
            }
        }
    }

    // Merge with existing (so partial updates work)
    $existing = brand_read();
    $merged = array_replace_recursive($existing, $clean);
    brand_save($merged);

    // Sync logo/favicon to globals for template tag compatibility
    brand_sync_globals($merged);

    // Clear template cache so new tokens take effect
    outpost_clear_cache();

    $ratio = (float) ($merged['typography']['type_scale'] ?? 1.25);
    json_response([
        'brand' => brand_get_merged(),
        'computed_scale' => brand_compute_type_scale($ratio),
    ]);
}

/**
 * Sync brand logo/favicon to global fields so {{ @site_logo | image }}
 * and {{ @site_favicon | image }} work without extra configuration.
 */
function brand_sync_globals(array $brand): void {
    $logo = $brand['identity']['logo'] ?? '';
    $favicon = $brand['identity']['favicon'] ?? '';

    // Find the globals page
    $globalsPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalsPage) return;
    $pageId = $globalsPage['id'];

    // Upsert or clear logo field
    $existingLogo = OutpostDB::fetchOne(
        "SELECT id FROM fields WHERE page_id = ? AND name = 'site_logo' AND theme = ''",
        [$pageId]
    );
    if ($logo !== '') {
        if ($existingLogo) {
            OutpostDB::query("UPDATE fields SET value = ? WHERE id = ?", [$logo, $existingLogo['id']]);
        } else {
            OutpostDB::query(
                "INSERT INTO fields (page_id, name, value, type, theme) VALUES (?, 'site_logo', ?, 'image', '')",
                [$pageId, $logo]
            );
        }
    } elseif ($existingLogo) {
        OutpostDB::query("DELETE FROM fields WHERE id = ?", [$existingLogo['id']]);
    }

    // Upsert or clear favicon field
    $existingFavicon = OutpostDB::fetchOne(
        "SELECT id FROM fields WHERE page_id = ? AND name = 'site_favicon' AND theme = ''",
        [$pageId]
    );
    if ($favicon !== '') {
        if ($existingFavicon) {
            OutpostDB::query("UPDATE fields SET value = ? WHERE id = ?", [$favicon, $existingFavicon['id']]);
        } else {
            OutpostDB::query(
                "INSERT INTO fields (page_id, name, value, type, theme) VALUES (?, 'site_favicon', ?, 'image', '')",
                [$pageId, $favicon]
            );
        }
    } elseif ($existingFavicon) {
        OutpostDB::query("DELETE FROM fields WHERE id = ?", [$existingFavicon['id']]);
    }
}
