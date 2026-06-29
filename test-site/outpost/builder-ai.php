<?php

function builder_ai_system_prompt(array $context): string {
    if (!function_exists('outpost_builder_conventions')) require_once __DIR__ . '/node-engine.php';
    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $conventions = outpost_builder_conventions();

    return <<<PROMPT
You are the in-app build agent for Outpost, a visual page builder. The user describes what they want and you build it directly on their page by calling the apply_ops tool. You are editing a live canvas — every operation you emit is applied instantly and is undoable by the user.

$conventions

After building, reply with one short sentence describing what you did — do not list every node.

# Current page
This JSON describes the page you are editing right now (node ids, classes, and tokens). Use it to target existing nodes and reuse classes:

$contextJson
PROMPT;
}

function outpost_builder_fetch_models(string $provider, string $apiKey): array {
    $out = [];
    if ($provider === 'claude') {
        $ch = curl_init('https://api.anthropic.com/v1/models?limit=100');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $body) {
            foreach (json_decode($body, true)['data'] ?? [] as $m) {
                if (!empty($m['id'])) $out[] = ['id' => $m['id'], 'created' => $m['created_at'] ?? ''];
            }
        }
    } elseif ($provider === 'openai') {
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $body) {
            foreach (json_decode($body, true)['data'] ?? [] as $m) {
                if (!empty($m['id'])) $out[] = ['id' => $m['id'], 'created' => (string)($m['created'] ?? '')];
            }
        }
    } elseif ($provider === 'gemini') {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $body) {
            foreach (json_decode($body, true)['models'] ?? [] as $m) {
                $id = str_replace('models/', '', $m['name'] ?? '');
                if ($id) $out[] = ['id' => $id, 'created' => ''];
            }
        }
    }
    return $out;
}

function outpost_builder_available_models(string $provider, string $apiKey): array {
    $cacheKey = "builder_model_cache_$provider";
    $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", [$cacheKey]);
    if ($row) {
        $cached = json_decode($row['value'] ?? '', true);
        if (is_array($cached) && (int)($cached['fetched_at'] ?? 0) > time() - 86400 && !empty($cached['models'])) {
            return $cached['models'];
        }
    }
    $models = outpost_builder_fetch_models($provider, $apiKey);
    if (!empty($models)) {
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$cacheKey, json_encode(['fetched_at' => time(), 'models' => $models])]);
    }
    return $models;
}

function outpost_builder_resolve_model(string $provider, string $apiKey, string $hint): string {
    $hint = strtolower(trim($hint));
    $tier = match (true) {
        str_contains($hint, 'opus') => 'opus',
        str_contains($hint, 'haiku') => 'haiku',
        str_contains($hint, 'sonnet') => 'sonnet',
        default => '',
    };

    $models = outpost_builder_available_models($provider, $apiKey);

    foreach ($models as $m) {
        if (strtolower($m['id']) === $hint) return $m['id'];
    }

    if ($tier !== '' && !empty($models)) {
        $matches = array_values(array_filter($models, fn ($m) => str_contains(strtolower($m['id']), $tier)));
        if (!empty($matches)) {
            usort($matches, function ($a, $b) {
                $c = strcmp((string)$b['created'], (string)$a['created']);
                return $c !== 0 ? $c : strcmp($b['id'], $a['id']);
            });
            return $matches[0]['id'];
        }
    }

    if ($hint !== '' && $tier === '') return $hint;

    return match ($provider) {
        'openai' => 'gpt-4o',
        'gemini' => 'gemini-2.0-flash',
        default => 'claude-sonnet-4-5-20250929',
    };
}

function builder_ai_tools(): array {
    return [[
        'name' => 'apply_ops',
        'description' => 'Apply a batch of build operations to the live page. Operations run in order and are applied to the canvas immediately.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'ops' => [
                    'type' => 'array',
                    'description' => 'Ordered list of operation objects. Each has an "op" field naming the operation.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['ops'],
        ],
    ]];
}

function handle_builder_ai_chat(): void {
    outpost_require_cap('content.*');
    set_time_limit(600);

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2_000_000) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'Request too large']);
        ranger_sse_send(['type' => 'done']);
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'Invalid request body']);
        ranger_sse_send(['type' => 'done']);
        return;
    }

    $history = is_array($data['messages'] ?? null) ? $data['messages'] : [];
    $context = is_array($data['context'] ?? null) ? $data['context'] : [];

    $messages = [];
    foreach (array_slice($history, -24) as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = is_string($m['content'] ?? null) ? mb_substr($m['content'], 0, 8000) : '';
        if ($content === '') continue;
        $messages[] = ['role' => $role, 'content' => $content];
    }
    if (empty($messages) || $messages[count($messages) - 1]['role'] !== 'user') {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'A message is required']);
        ranger_sse_send(['type' => 'done']);
        return;
    }

    $requestedProvider = $data['provider'] ?? null;
    $requestedModel = $data['model'] ?? null;

    $defaultProvider = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'ranger_default_provider'");
    $provider = $requestedProvider ?: ($defaultProvider['value'] ?? 'claude');

    $modelRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", ["ranger_model_$provider"]);
    $modelHint = $requestedModel ?: ($modelRow['value'] ?? '');

    $apiKeyRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", ["ranger_api_key_$provider"]);
    if (!$apiKeyRow || empty($apiKeyRow['value'])) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => "No AI key configured for $provider. Add one in Settings → Integrations."]);
        ranger_sse_send(['type' => 'done']);
        return;
    }

    try {
        $apiKey = ranger_decrypt($apiKeyRow['value']);
    } catch (\Throwable) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'Could not read the AI key. Re-enter it in Settings → Integrations.']);
        ranger_sse_send(['type' => 'done']);
        return;
    }

    $model = outpost_builder_resolve_model($provider, $apiKey, $modelHint);

    $systemPrompt = builder_ai_system_prompt($context);
    $tools = builder_ai_tools();

    $providerInstance = match ($provider) {
        'openai' => new RangerOpenAI($apiKey),
        'gemini' => new RangerGemini($apiKey),
        default => new RangerClaude($apiKey),
    };

    ranger_sse_init();

    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            echo "data: " . json_encode(['type' => 'error', 'message' => 'An internal error occurred.']) . "\n\n";
            echo "data: " . json_encode(['type' => 'done']) . "\n\n";
        }
    });

    $totalInput = 0;
    $totalOutput = 0;
    $totalCached = 0;
    $maxRounds = 12;
    $round = 0;

    try {
        while ($round < $maxRounds) {
            $round++;
            $textAccumulator = '';
            $toolUseBlocks = [];
            $hasError = false;

            foreach ($providerInstance->stream($systemPrompt, $messages, $tools, $model) as $event) {
                if (!is_array($event)) continue;
                switch ($event['type']) {
                    case 'text':
                        $textAccumulator .= $event['content'];
                        ranger_sse_send(['type' => 'text', 'content' => $event['content']]);
                        break;
                    case 'tool_use':
                        $toolUseBlocks[] = $event;
                        break;
                    case 'usage':
                        $totalInput += $event['input_tokens'] ?? 0;
                        $totalOutput += $event['output_tokens'] ?? 0;
                        $totalCached += ($event['cache_read_tokens'] ?? 0) + ($event['cache_creation_tokens'] ?? 0);
                        break;
                    case 'error':
                        ranger_sse_send(['type' => 'error', 'message' => $event['message']]);
                        $hasError = true;
                        break;
                }
            }

            $assistantContent = [];
            if ($textAccumulator !== '') $assistantContent[] = ['type' => 'text', 'text' => $textAccumulator];
            foreach ($toolUseBlocks as $tb) {
                $assistantContent[] = ['type' => 'tool_use', 'id' => $tb['id'], 'name' => $tb['name'], 'input' => $tb['input']];
            }

            if ($hasError && empty($assistantContent)) break;

            if (!empty($assistantContent)) {
                $messages[] = (count($assistantContent) === 1 && $assistantContent[0]['type'] === 'text')
                    ? ['role' => 'assistant', 'content' => $assistantContent[0]['text']]
                    : ['role' => 'assistant', 'content' => $assistantContent];
            }

            if (empty($toolUseBlocks)) break;

            foreach ($toolUseBlocks as $tb) {
                $input = is_array($tb['input']) ? $tb['input'] : [];
                $ops = is_array($input['ops'] ?? null) ? $input['ops'] : [];
                $count = count($ops);

                ranger_sse_send(['type' => 'ops', 'ops' => $ops]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_use_id' => $tb['id'],
                    'tool_name' => $tb['name'],
                    'content' => json_encode(['applied' => true, 'operation_count' => $count]),
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log('Builder AI error: ' . $e->getMessage());
        ranger_sse_send(['type' => 'error', 'message' => 'An internal error occurred.']);
    }

    $costCents = ranger_calculate_cost($model, $totalInput, $totalOutput, $totalCached);
    ranger_sse_send([
        'type' => 'done',
        'usage' => [
            'input_tokens' => $totalInput,
            'output_tokens' => $totalOutput,
            'cached_tokens' => $totalCached,
            'cost_cents' => $costCents,
        ],
    ]);
}
