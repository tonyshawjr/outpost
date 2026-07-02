<?php
/**
 * Outpost CMS — Stock Photo Search (Pexels + Unsplash)
 *
 * Pexels images are downloaded and self-hosted in the media library.
 * Unsplash requires hotlinking its CDN plus a download trigger on use, so
 * Unsplash images are embedded by CDN URL and are not copied to uploads/.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ranger.php';
require_once __DIR__ . '/media.php';

const STOCK_PROVIDERS = ['pexels', 'unsplash'];
const STOCK_CDN_HOSTS = ['pexels' => 'images.pexels.com', 'unsplash' => 'images.unsplash.com'];

function stock_key(string $provider): string {
    $row = OutpostDB::fetchOne('SELECT value FROM settings WHERE key = ?', ["stock_api_key_$provider"]);
    if (!$row || $row['value'] === '') return '';
    try {
        return ranger_decrypt($row['value']);
    } catch (\Throwable) {
        return $row['value'];
    }
}

function stock_http_get(string $url, array $headers, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : ''];
}

function stock_provider_headers(string $provider, string $key): array {
    return $provider === 'unsplash'
        ? ['Authorization: Client-ID ' . $key, 'Accept-Version: v1']
        : ['Authorization: ' . $key];
}

function stock_normalise_pexels(array $p): array {
    $src = $p['src'] ?? [];
    return [
        'provider' => 'pexels',
        'id' => (string) ($p['id'] ?? ''),
        'thumb' => $src['tiny'] ?? ($src['small'] ?? ''),
        'preview' => $src['medium'] ?? ($src['large'] ?? ''),
        'full' => $src['large2x'] ?? ($src['original'] ?? ''),
        'width' => (int) ($p['width'] ?? 0),
        'height' => (int) ($p['height'] ?? 0),
        'alt' => (string) ($p['alt'] ?? ''),
        'author' => (string) ($p['photographer'] ?? ''),
        'author_url' => (string) ($p['photographer_url'] ?? ''),
        'link' => (string) ($p['url'] ?? ''),
    ];
}

function stock_normalise_unsplash(array $r): array {
    $urls = $r['urls'] ?? [];
    return [
        'provider' => 'unsplash',
        'id' => (string) ($r['id'] ?? ''),
        'thumb' => $urls['thumb'] ?? ($urls['small'] ?? ''),
        'preview' => $urls['small'] ?? ($urls['regular'] ?? ''),
        'full' => $urls['regular'] ?? ($urls['full'] ?? ''),
        'width' => (int) ($r['width'] ?? 0),
        'height' => (int) ($r['height'] ?? 0),
        'alt' => (string) ($r['alt_description'] ?? ($r['description'] ?? '')),
        'author' => (string) ($r['user']['name'] ?? ''),
        'author_url' => (string) ($r['user']['links']['html'] ?? ''),
        'link' => (string) ($r['links']['html'] ?? ''),
    ];
}

function stock_search(string $provider, string $query, int $page): array {
    $key = stock_key($provider);
    if ($key === '') return ['error' => 'not_configured'];
    $query = trim($query);
    if ($query === '') return ['results' => [], 'page' => $page, 'has_more' => false];
    $page = max(1, min($page, 100));

    if ($provider === 'pexels') {
        $perPage = 24;
        $url = 'https://api.pexels.com/v1/search?' . http_build_query(['query' => $query, 'page' => $page, 'per_page' => $perPage]);
        [$code, $body] = stock_http_get($url, stock_provider_headers('pexels', $key));
        if ($code !== 200) return ['error' => 'provider_error', 'status' => $code];
        $data = json_decode($body, true);
        $items = array_map('stock_normalise_pexels', $data['photos'] ?? []);
        return ['results' => $items, 'page' => $page, 'has_more' => !empty($data['next_page'])];
    }

    $perPage = 24;
    $url = 'https://api.unsplash.com/search/photos?' . http_build_query(['query' => $query, 'page' => $page, 'per_page' => $perPage, 'content_filter' => 'high']);
    [$code, $body] = stock_http_get($url, stock_provider_headers('unsplash', $key));
    if ($code !== 200) return ['error' => 'provider_error', 'status' => $code];
    $data = json_decode($body, true);
    $items = array_map('stock_normalise_unsplash', $data['results'] ?? []);
    $totalPages = (int) ($data['total_pages'] ?? 0);
    return ['results' => $items, 'page' => $page, 'has_more' => $page < $totalPages];
}

function stock_host_allowed(string $provider, string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    return $scheme === 'https' && is_string($host) && strtolower($host) === STOCK_CDN_HOSTS[$provider];
}

function stock_download_to_temp(string $url): array {
    $tmp = tempnam(sys_get_temp_dir(), 'stock_');
    if ($tmp === false) return ['error' => 'temp_failed'];
    $fh = fopen($tmp, 'wb');
    if ($fh === false) { @unlink($tmp); return ['error' => 'temp_failed']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => OUTPOST_MAX_UPLOAD_SIZE,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code !== 200 || !str_starts_with($type, 'image/')) {
        @unlink($tmp);
        return ['error' => 'download_failed'];
    }
    return ['path' => $tmp];
}

function stock_import_pexels(string $key, string $id, string $alt): array {
    if (!ctype_digit($id)) return ['error' => 'invalid_id'];
    [$code, $body] = stock_http_get('https://api.pexels.com/v1/photos/' . $id, stock_provider_headers('pexels', $key));
    if ($code !== 200) return ['error' => 'provider_error', 'status' => $code];
    $photo = json_decode($body, true);
    if (!is_array($photo)) return ['error' => 'provider_error'];
    $src = $photo['src'] ?? [];
    $url = $src['large2x'] ?? ($src['original'] ?? '');
    if (!is_string($url) || !stock_host_allowed('pexels', $url)) return ['error' => 'bad_source'];

    $dl = stock_download_to_temp($url);
    if (isset($dl['error'])) return $dl;
    $altText = $alt !== '' ? $alt : (string) ($photo['alt'] ?? '');
    $record = OutpostMedia::importFromFile($dl['path'], 'pexels-' . $id . '.jpg', $altText);
    if (isset($record['error'])) return $record;

    return [
        'type' => 'media',
        'url' => '/' . ltrim((string) $record['path'], '/'),
        'media_id' => (int) $record['id'],
        'alt' => $altText,
        'credit' => [
            'author' => (string) ($photo['photographer'] ?? ''),
            'author_url' => (string) ($photo['photographer_url'] ?? ''),
            'provider' => 'Pexels',
            'provider_url' => (string) ($photo['url'] ?? 'https://www.pexels.com'),
        ],
    ];
}

function stock_import_unsplash(string $key, string $id, string $alt): array {
    if (!preg_match('/^[A-Za-z0-9_-]{5,32}$/', $id)) return ['error' => 'invalid_id'];
    $headers = stock_provider_headers('unsplash', $key);
    [$code, $body] = stock_http_get('https://api.unsplash.com/photos/' . $id, $headers);
    if ($code !== 200) return ['error' => 'provider_error', 'status' => $code];
    $photo = json_decode($body, true);
    if (!is_array($photo)) return ['error' => 'provider_error'];

    $dlLocation = (string) ($photo['links']['download_location'] ?? '');
    if ($dlLocation !== '' && str_starts_with($dlLocation, 'https://api.unsplash.com/')) {
        stock_http_get($dlLocation, $headers);
    }

    $raw = (string) ($photo['urls']['raw'] ?? '');
    $embed = $raw !== '' && stock_host_allowed('unsplash', $raw)
        ? $raw . (str_contains($raw, '?') ? '&' : '?') . 'w=1600&q=80&fm=jpg&fit=max'
        : (string) ($photo['urls']['regular'] ?? '');
    if (!is_string($embed) || !stock_host_allowed('unsplash', $embed)) return ['error' => 'bad_source'];

    return [
        'type' => 'hotlink',
        'url' => $embed,
        'alt' => $alt !== '' ? $alt : (string) ($photo['alt_description'] ?? ''),
        'credit' => [
            'author' => (string) ($photo['user']['name'] ?? ''),
            'author_url' => (string) ($photo['user']['links']['html'] ?? ''),
            'provider' => 'Unsplash',
            'provider_url' => (string) ($photo['links']['html'] ?? 'https://unsplash.com'),
        ],
    ];
}

function stock_import(string $provider, string $id, string $alt): array {
    $key = stock_key($provider);
    if ($key === '') return ['error' => 'not_configured'];
    return $provider === 'unsplash'
        ? stock_import_unsplash($key, $id, $alt)
        : stock_import_pexels($key, $id, $alt);
}

// ── Handlers ─────────────────────────────────────────────

function handle_stock_providers(): void {
    outpost_require_cap('content.*');
    $out = [];
    foreach (STOCK_PROVIDERS as $p) {
        $out[] = ['id' => $p, 'configured' => stock_key($p) !== ''];
    }
    json_response(['providers' => $out]);
}

function handle_stock_search(): void {
    outpost_require_cap('content.*');
    $provider = strtolower((string) ($_GET['provider'] ?? 'pexels'));
    if (!in_array($provider, STOCK_PROVIDERS, true)) json_error('Unknown provider', 400);
    $query = mb_substr((string) ($_GET['q'] ?? ''), 0, 120);
    $page = (int) ($_GET['page'] ?? 1);
    $result = stock_search($provider, $query, $page);
    if (isset($result['error'])) {
        if ($result['error'] === 'not_configured') {
            json_error('No API key configured for ' . ucfirst($provider) . '. Add one in Settings → Integrations.', 400);
        }
        json_error('Stock provider request failed', 502);
    }
    json_response($result);
}

function handle_stock_import(): void {
    outpost_require_cap('content.*');
    $body = get_json_body();
    $provider = strtolower((string) ($body['provider'] ?? ''));
    if (!in_array($provider, STOCK_PROVIDERS, true)) json_error('Unknown provider', 400);
    $id = (string) ($body['id'] ?? '');
    if ($id === '') json_error('Photo id required', 400);
    $alt = mb_substr((string) ($body['alt'] ?? ''), 0, 500);

    $result = stock_import($provider, $id, $alt);
    if (isset($result['error'])) {
        if ($result['error'] === 'not_configured') {
            json_error('No API key configured for ' . ucfirst($provider) . '.', 400);
        }
        if ($result['error'] === 'invalid_id' || $result['error'] === 'bad_source') {
            json_error('Invalid photo reference', 400);
        }
        json_error('Could not import the selected photo', 502);
    }
    json_response($result);
}

function handle_stock_settings_get(): void {
    outpost_require_cap('settings.*');
    $out = [];
    foreach (STOCK_PROVIDERS as $p) {
        $out["stock_api_key_$p"] = stock_key($p);
    }
    json_response(['settings' => $out]);
}

function handle_stock_settings_update(): void {
    outpost_require_cap('settings.*');
    $data = get_json_body();
    foreach (STOCK_PROVIDERS as $p) {
        $field = "stock_api_key_$p";
        if (!array_key_exists($field, $data)) continue;
        $value = trim((string) $data[$field]);
        $store = $value === '' ? '' : ranger_encrypt($value);
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$field, $store]);
    }
    log_activity('system', 'Stock photo settings updated');
    json_response(['success' => true]);
}
