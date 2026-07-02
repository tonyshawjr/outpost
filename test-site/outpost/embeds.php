<?php
/**
 * Outpost CMS — oEmbed resolver (curated, iframe/photo providers only)
 *
 * We call a fixed set of provider oEmbed endpoints, then re-emit our own
 * sanitised iframe/img from the parsed src — never trusting provider markup.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function embed_providers(): array {
    return [
        'youtube' => [
            'match' => '~^https?://(www\.)?(youtube\.com/(watch|shorts|embed|live|playlist)|youtu\.be/|m\.youtube\.com/watch)~i',
            'endpoint' => 'https://www.youtube.com/oembed',
            'kind' => 'iframe',
            'hosts' => ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com'],
        ],
        'vimeo' => [
            'match' => '~^https?://(www\.)?(vimeo\.com/\d+|player\.vimeo\.com/video/)~i',
            'endpoint' => 'https://vimeo.com/api/oembed.json',
            'kind' => 'iframe',
            'hosts' => ['player.vimeo.com'],
        ],
        'spotify' => [
            'match' => '~^https?://open\.spotify\.com/~i',
            'endpoint' => 'https://open.spotify.com/oembed',
            'kind' => 'iframe',
            'hosts' => ['open.spotify.com'],
        ],
        'soundcloud' => [
            'match' => '~^https?://(www\.)?soundcloud\.com/~i',
            'endpoint' => 'https://soundcloud.com/oembed',
            'kind' => 'iframe',
            'hosts' => ['w.soundcloud.com'],
        ],
        'flickr' => [
            'match' => '~^https?://(www\.)?flickr\.com/photos/~i',
            'endpoint' => 'https://www.flickr.com/services/oembed/',
            'kind' => 'photo',
            'hosts' => ['live.staticflickr.com', 'farm1.staticflickr.com', 'farm2.staticflickr.com', 'farm3.staticflickr.com', 'farm4.staticflickr.com', 'farm5.staticflickr.com'],
        ],
    ];
}

function embed_detect_provider(string $url): ?array {
    foreach (embed_providers() as $name => $cfg) {
        if (preg_match($cfg['match'], $url)) return ['name' => $name] + $cfg;
    }
    return null;
}

function embed_host_allowed(string $url, array $hosts): bool {
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    if ($scheme !== 'https' || !is_string($host)) return false;
    $host = strtolower($host);
    foreach ($hosts as $h) {
        if ($host === $h) return true;
    }
    return false;
}

function embed_http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => 262144,
        CURLOPT_USERAGENT => 'Outpost-CMS-oEmbed',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, is_string($body) ? $body : ''];
}

function embed_resolve(string $url): array {
    $url = trim($url);
    if (!preg_match('~^https://~i', $url) || strlen($url) > 2000) {
        return ['error' => 'invalid_url'];
    }
    $provider = embed_detect_provider($url);
    if (!$provider) return ['error' => 'unsupported'];

    $query = http_build_query(['url' => $url, 'format' => 'json', 'maxwidth' => 960]);
    [$code, $body] = embed_http_get($provider['endpoint'] . '?' . $query);
    if ($code !== 200 || $body === '') return ['error' => 'provider_error', 'status' => $code];

    $data = json_decode($body, true);
    if (!is_array($data)) return ['error' => 'provider_error'];

    $title = mb_substr((string) ($data['title'] ?? ''), 0, 300);
    $width = (int) ($data['width'] ?? 0);
    $height = (int) ($data['height'] ?? 0);

    if ($provider['kind'] === 'photo') {
        $src = (string) ($data['url'] ?? '');
        if (!embed_host_allowed($src, $provider['hosts'])) return ['error' => 'bad_source'];
        return [
            'kind' => 'photo',
            'provider' => $provider['name'],
            'embedUrl' => $src,
            'width' => $width,
            'height' => $height,
            'title' => $title,
        ];
    }

    $html = (string) ($data['html'] ?? '');
    if (!preg_match('~<iframe[^>]+src="([^"]+)"~i', $html, $m)) return ['error' => 'no_embed'];
    $src = html_entity_decode($m[1], ENT_QUOTES);
    if (!embed_host_allowed($src, $provider['hosts'])) return ['error' => 'bad_source'];

    return [
        'kind' => 'iframe',
        'provider' => $provider['name'],
        'embedUrl' => $src,
        'width' => $width,
        'height' => $height,
        'title' => $title,
    ];
}

function handle_embed_resolve(): void {
    outpost_require_cap('content.*');
    $body = get_json_body();
    $url = (string) ($body['url'] ?? '');
    if ($url === '') json_error('URL required', 400);

    $result = embed_resolve($url);
    if (isset($result['error'])) {
        if ($result['error'] === 'unsupported') {
            json_error('That link is not supported yet. Try YouTube, Vimeo, Spotify, SoundCloud, or Flickr.', 400);
        }
        if ($result['error'] === 'invalid_url') json_error('Enter a valid https URL', 400);
        json_error('Could not load an embed for that link', 502);
    }
    json_response($result);
}

/** Validate a stored embed src against the full provider host allow-list (bake + canvas). */
function embed_src_safe(string $url): bool {
    $all = [];
    foreach (embed_providers() as $cfg) {
        foreach ($cfg['hosts'] as $h) $all[$h] = true;
    }
    return embed_host_allowed($url, array_keys($all));
}
