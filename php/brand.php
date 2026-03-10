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
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
 */
function handle_brand_get(): void {
    outpost_require_cap('settings.*');

    $brand = brand_get_merged();
    $ratio = (float) ($brand['typography']['type_scale'] ?? 1.25);
    $scale = brand_compute_type_scale($ratio);

    outpost_json([
        'brand'       => $brand,
        'defaults'    => brand_defaults(),
        'scale_options' => brand_type_scale_options(),
        'computed_scale' => $scale,
    ]);
}

/**
 * PUT brand — Save brand settings.
 * Body: { colors: {...}, typography: {...}, identity: {...} }
 */
function handle_brand_save(): void {
    outpost_require_cap('settings.*');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        outpost_error('Invalid request body', 400);
    }

    $defaults = brand_defaults();
    $clean = [];

    // Validate colors
    if (isset($input['colors']) && is_array($input['colors'])) {
        $clean['colors'] = [];
        foreach ($defaults['colors'] as $key => $default) {
            $val = $input['colors'][$key] ?? $default;
            // Validate hex color
            if (is_string($val) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) {
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

    // Validate identity
    if (isset($input['identity']) && is_array($input['identity'])) {
        $clean['identity'] = [];
        foreach (['logo', 'favicon'] as $key) {
            $val = $input['identity'][$key] ?? '';
            $clean['identity'][$key] = is_string($val) ? $val : '';
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
    outpost_json([
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

    // Upsert logo field
    if ($logo !== '') {
        $existing = OutpostDB::fetchOne(
            "SELECT id FROM fields WHERE page_id = ? AND name = 'site_logo' AND theme = ''",
            [$pageId]
        );
        if ($existing) {
            OutpostDB::query("UPDATE fields SET value = ? WHERE id = ?", [$logo, $existing['id']]);
        } else {
            OutpostDB::query(
                "INSERT INTO fields (page_id, name, value, type, theme) VALUES (?, 'site_logo', ?, 'image', '')",
                [$pageId, $logo]
            );
        }
    }

    // Upsert favicon field
    if ($favicon !== '') {
        $existing = OutpostDB::fetchOne(
            "SELECT id FROM fields WHERE page_id = ? AND name = 'site_favicon' AND theme = ''",
            [$pageId]
        );
        if ($existing) {
            OutpostDB::query("UPDATE fields SET value = ? WHERE id = ?", [$favicon, $existing['id']]);
        } else {
            OutpostDB::query(
                "INSERT INTO fields (page_id, name, value, type, theme) VALUES (?, 'site_favicon', ?, 'image', '')",
                [$pageId, $favicon]
            );
        }
    }
}
