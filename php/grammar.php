<?php
/**
 * Outpost CMS — Grammar & readability check (LanguageTool proxy)
 *
 * Proxies text to a LanguageTool server (public API or a self-hosted instance)
 * so the API key stays server-side. The server URL is admin-configured.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ranger.php';

const GRAMMAR_DEFAULT_URL = 'https://api.languagetool.org/v2/check';

function grammar_setting(string $key): string {
    $row = OutpostDB::fetchOne('SELECT value FROM settings WHERE key = ?', [$key]);
    return $row['value'] ?? '';
}

function grammar_config(): array {
    $url = trim(grammar_setting('languagetool_server_url'));
    $user = trim(grammar_setting('languagetool_username'));
    $keyEnc = grammar_setting('languagetool_apikey');
    $key = '';
    if ($keyEnc !== '') {
        try { $key = ranger_decrypt($keyEnc); } catch (\Throwable) { $key = $keyEnc; }
    }
    return ['url' => $url !== '' ? $url : GRAMMAR_DEFAULT_URL, 'username' => $user, 'apikey' => $key];
}

function grammar_check(string $text, string $language): array {
    $cfg = grammar_config();
    if (!preg_match('~^https?://~i', $cfg['url'])) return ['error' => 'bad_config'];
    $text = mb_substr($text, 0, 20000);
    if (trim($text) === '') return ['matches' => []];

    $post = ['text' => $text, 'language' => $language !== '' ? $language : 'auto'];
    if ($cfg['username'] !== '' && $cfg['apikey'] !== '') {
        $post['username'] = $cfg['username'];
        $post['apiKey'] = $cfg['apikey'];
    }

    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXFILESIZE => 2_000_000,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code === 429) return ['error' => 'rate_limited'];
    if ($code !== 200 || !is_string($body)) return ['error' => 'provider_error', 'status' => $code];

    $data = json_decode($body, true);
    if (!is_array($data)) return ['error' => 'provider_error'];

    $matches = [];
    foreach ($data['matches'] ?? [] as $m) {
        if (!is_array($m)) continue;
        $reps = [];
        foreach (array_slice($m['replacements'] ?? [], 0, 8) as $r) {
            $v = is_array($r) ? ($r['value'] ?? '') : '';
            if (is_string($v) && $v !== '') $reps[] = mb_substr($v, 0, 200);
        }
        $matches[] = [
            'offset' => (int) ($m['offset'] ?? 0),
            'length' => (int) ($m['length'] ?? 0),
            'message' => mb_substr((string) ($m['message'] ?? ''), 0, 500),
            'shortMessage' => mb_substr((string) ($m['shortMessage'] ?? ''), 0, 200),
            'replacements' => $reps,
            'ruleId' => (string) ($m['rule']['id'] ?? ''),
            'category' => mb_substr((string) ($m['rule']['category']['name'] ?? ''), 0, 100),
            'issueType' => (string) ($m['rule']['issueType'] ?? ''),
        ];
    }
    return ['matches' => $matches];
}

// ── Handlers ─────────────────────────────────────────────

function handle_grammar_check(): void {
    outpost_require_cap('content.*');
    $body = get_json_body();
    $text = is_string($body['text'] ?? null) ? $body['text'] : '';
    $language = is_string($body['language'] ?? null) ? preg_replace('/[^A-Za-z-]/', '', $body['language']) : '';
    if (trim($text) === '') { json_response(['matches' => []]); }

    $result = grammar_check($text, $language);
    if (isset($result['error'])) {
        if ($result['error'] === 'bad_config') json_error('LanguageTool server URL is not configured.', 400);
        if ($result['error'] === 'rate_limited') json_error('Grammar check rate limit reached — try again shortly or connect a self-hosted server.', 429);
        json_error('Grammar check failed', 502);
    }
    json_response($result);
}

function handle_grammar_settings_get(): void {
    outpost_require_cap('settings.*');
    $cfg = grammar_config();
    json_response(['settings' => [
        'languagetool_server_url' => grammar_setting('languagetool_server_url'),
        'languagetool_username' => $cfg['username'],
        'languagetool_apikey' => $cfg['apikey'],
    ]]);
}

function handle_grammar_settings_update(): void {
    outpost_require_cap('settings.*');
    $data = get_json_body();
    if (array_key_exists('languagetool_server_url', $data)) {
        $url = trim((string) $data['languagetool_server_url']);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) json_error('Server URL must start with http:// or https://', 400);
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['languagetool_server_url', $url]);
    }
    if (array_key_exists('languagetool_username', $data)) {
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['languagetool_username', trim((string) $data['languagetool_username'])]);
    }
    if (array_key_exists('languagetool_apikey', $data)) {
        $val = trim((string) $data['languagetool_apikey']);
        $store = $val === '' ? '' : ranger_encrypt($val);
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['languagetool_apikey', $store]);
    }
    log_activity('system', 'Grammar settings updated');
    json_response(['success' => true]);
}
