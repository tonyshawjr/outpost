<?php
/**
 * Outpost CMS — Ranger AI Assistant
 *
 * Multi-provider AI assistant with tool execution, conversation persistence,
 * and streaming responses. Supports Claude, OpenAI, and Gemini.
 */

// ── Encryption ──────────────────────────────────────────

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Get or generate a random 256-bit encryption key.
 * Stored as 64-char hex in the settings table under 'ranger_key'.
 * Falls back to deterministic derivation if DB is unavailable.
 */
function outpost_encryption_key(): string {
    try {
        $row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'ranger_key'");
        if ($row && is_string($row['value']) && strlen($row['value']) === 64 && ctype_xdigit($row['value'])) {
            return hex2bin($row['value']);
        }
        // Generate and store a new random key
        $key = random_bytes(32);
        OutpostDB::query(
            "INSERT OR REPLACE INTO settings (key, value) VALUES ('ranger_key', ?)",
            [bin2hex($key)]
        );
        return $key;
    } catch (\Throwable $e) {
        // Fallback to deterministic key if DB is unavailable
        return hash('sha256', OUTPOST_DB_PATH . 'outpost-ranger-salt', true);
    }
}

function ranger_encrypt(string $plaintext): string {
    $key = outpost_encryption_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed');
    }
    return base64_encode($iv . $tag . $cipher);
}

function ranger_decrypt(string $ciphertext): string {
    $key = outpost_encryption_key();
    $raw = base64_decode($ciphertext, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Decryption failed: invalid ciphertext');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plaintext = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('Decryption failed');
    }
    return $plaintext;
}

/**
 * Decrypt a value with fallback to plaintext for backward compatibility.
 * If decryption fails (e.g. value was stored before encryption was added),
 * returns the raw value unchanged.
 */
function safe_decrypt(string $value): string {
    if ($value === '') return $value;
    try {
        return ranger_decrypt($value);
    } catch (\Throwable $e) {
        // Value is likely still plaintext (pre-encryption migration)
        return $value;
    }
}

// ── Database Migration ──────────────────────────────────

function ensure_ranger_tables(): void {
    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS ranger_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT DEFAULT '',
            provider TEXT NOT NULL DEFAULT 'claude',
            model TEXT NOT NULL DEFAULT '',
            messages TEXT NOT NULL DEFAULT '[]',
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ranger_convos_user ON ranger_conversations(user_id, updated_at DESC)");

    // Add usage tracking columns if missing
    $cols = $db->query("PRAGMA table_info(ranger_conversations)")->fetchAll(\PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('total_input_tokens', $colNames)) {
        $db->exec("ALTER TABLE ranger_conversations ADD COLUMN total_input_tokens INTEGER DEFAULT 0");
        $db->exec("ALTER TABLE ranger_conversations ADD COLUMN total_output_tokens INTEGER DEFAULT 0");
        $db->exec("ALTER TABLE ranger_conversations ADD COLUMN total_cost_cents REAL DEFAULT 0");
    }
}

// Cost per million tokens (in cents) — updated March 2026
function ranger_get_token_costs(string $model): array {
    return match (true) {
        str_contains($model, 'opus') => ['input' => 1500, 'output' => 7500, 'cached_input' => 150],
        str_contains($model, 'sonnet') => ['input' => 300, 'output' => 1500, 'cached_input' => 30],
        str_contains($model, 'haiku') => ['input' => 80, 'output' => 400, 'cached_input' => 8],
        str_contains($model, 'gpt-4o-mini') => ['input' => 15, 'output' => 60, 'cached_input' => 15],
        str_contains($model, 'gpt-4o') => ['input' => 250, 'output' => 1000, 'cached_input' => 125],
        str_contains($model, 'gpt-4-turbo') => ['input' => 1000, 'output' => 3000, 'cached_input' => 1000],
        str_contains($model, 'gemini-2.0-flash') => ['input' => 8, 'output' => 30, 'cached_input' => 2],
        str_contains($model, 'gemini-1.5-pro') => ['input' => 125, 'output' => 500, 'cached_input' => 31],
        default => ['input' => 300, 'output' => 1500, 'cached_input' => 30],
    };
}

function ranger_calculate_cost(string $model, int $inputTokens, int $outputTokens, int $cachedTokens = 0): float {
    $costs = ranger_get_token_costs($model);
    $nonCachedInput = max(0, $inputTokens - $cachedTokens);
    $inputCost = ($nonCachedInput / 1_000_000) * $costs['input'];
    $cachedCost = ($cachedTokens / 1_000_000) * $costs['cached_input'];
    $outputCost = ($outputTokens / 1_000_000) * $costs['output'];
    return max(0, round($inputCost + $cachedCost + $outputCost, 4));
}

// ── Provider Abstraction ────────────────────────────────

abstract class RangerProvider {
    protected string $apiKey;

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Stream a chat completion. Yields arrays:
     *   ['type' => 'text', 'content' => '...']
     *   ['type' => 'tool_use', 'id' => '...', 'name' => '...', 'input' => [...]]
     *   ['type' => 'done']
     *   ['type' => 'error', 'message' => '...']
     */
    abstract public function stream(string $systemPrompt, array $messages, array $tools, string $model): \Generator;

    abstract protected function formatTools(array $tools): array;

    protected function curlStream(string $url, array $headers, array $body): \Generator {
        // Use a temp file as a pipe — cURL writes SSE lines, we read them
        $pipePath = tempnam(sys_get_temp_dir(), 'ranger_sse_');
        $pipeHandle = fopen($pipePath, 'w+');
        $writePos = 0;
        $buffer = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($pipeHandle, &$writePos) {
                fseek($pipeHandle, $writePos);
                $written = fwrite($pipeHandle, $data);
                fflush($pipeHandle);
                $writePos += $written;
                return strlen($data);
            },
        ]);

        // Run cURL in a non-blocking way using multi handle
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $active = true;
        $readPos = 0;

        while ($active) {
            curl_multi_exec($mh, $running);
            if ($running === 0) $active = false;

            // Read any new data from the pipe
            fseek($pipeHandle, $readPos);
            $newData = fread($pipeHandle, 65536);
            if ($newData !== false && $newData !== '') {
                $readPos += strlen($newData);
                $buffer .= $newData;

                // Extract complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') {
                        yield $line;
                    }
                }
            }

            if ($active) {
                // Wait briefly for more data (10ms)
                curl_multi_select($mh, 0.01);
            }
        }

        // Flush remaining buffer
        fseek($pipeHandle, $readPos);
        $remaining = fread($pipeHandle, 65536);
        if ($remaining) $buffer .= $remaining;
        $buffer = trim($buffer);
        if ($buffer !== '') yield $buffer;

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);
        fclose($pipeHandle);
        @unlink($pipePath);

        if ($curlError) {
            yield ['type' => 'error', 'message' => 'Connection error: ' . $curlError];
            return;
        }
        if ($httpCode >= 400) {
            // Try to extract error message from the response body that was already yielded as lines
            // Re-read the pipe for any remaining error data
            $errorDetail = "HTTP $httpCode";
            // Check if any yielded lines contain error JSON
            if (!empty($buffer)) {
                $decoded = json_decode($buffer, true);
                if ($decoded && isset($decoded['error']['message'])) {
                    $errorDetail = $decoded['error']['message'];
                }
            }
            yield ['type' => 'error', 'message' => "API error ($errorDetail)"];
            return;
        }
    }
}

// ── Claude Provider ─────────────────────────────────────

class RangerClaude extends RangerProvider {
    public function stream(string $systemPrompt, array $messages, array $tools, string $model): \Generator {
        // Convert normalized messages to Claude format
        $claudeMessages = array_map([$this, 'convertMessage'], $messages);

        $resolvedModel = $model ?: 'claude-sonnet-4-20250514';

        // Max tokens per model — use the model's full capacity
        $maxTokens = match (true) {
            str_contains($resolvedModel, 'opus') => 32000,
            str_contains($resolvedModel, 'sonnet') => 64000,
            str_contains($resolvedModel, 'haiku') => 8192,
            default => 16384,
        };

        $body = [
            'model' => $resolvedModel,
            'max_tokens' => $maxTokens,
            'system' => [
                ['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => $claudeMessages,
            'stream' => true,
        ];
        if (!empty($tools)) {
            $formattedTools = $this->formatTools($tools);
            // Cache tool definitions — stable across rounds, big token savings
            $lastIdx = count($formattedTools) - 1;
            if ($lastIdx >= 0) {
                $formattedTools[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
            }
            $body['tools'] = $formattedTools;
        }

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ];

        $textContent = '';
        $toolUseBlocks = [];
        $currentToolUse = null;
        $currentToolJson = '';

        $rawEvents = $this->curlStream('https://api.anthropic.com/v1/messages', $headers, $body);

        foreach ($rawEvents as $line) {
            if (is_array($line)) {
                yield $line;
                return;
            }

            // Non-SSE error response (Claude returns plain JSON on 400s)
            if (!str_starts_with($line, 'data:') && !str_starts_with($line, 'event:') && str_contains($line, '"error"')) {
                $errJson = json_decode($line, true);
                if ($errJson) {
                    $errMsg = $errJson['error']['message'] ?? $errJson['message'] ?? 'Unknown API error';
                    yield ['type' => 'error', 'message' => $errMsg];
                    return;
                }
            }

            if (!str_starts_with($line, 'data: ')) continue;
            $json = substr($line, 6);
            if ($json === '[DONE]') continue;

            $event = json_decode($json, true);
            if (!$event) continue;

            $type = $event['type'] ?? '';

            if ($type === 'content_block_start') {
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $currentToolUse = [
                        'id' => $block['id'] ?? '',
                        'name' => $block['name'] ?? '',
                    ];
                    $currentToolJson = '';
                }
            } elseif ($type === 'content_block_delta') {
                $delta = $event['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta') {
                    $text = $delta['text'] ?? '';
                    $textContent .= $text;
                    yield ['type' => 'text', 'content' => $text];
                } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                    $currentToolJson .= $delta['partial_json'] ?? '';
                }
            } elseif ($type === 'content_block_stop') {
                if ($currentToolUse !== null) {
                    $input = json_decode($currentToolJson, true);
                    if (!is_array($input)) $input = [];
                    $toolBlock = [
                        'type' => 'tool_use',
                        'id' => $currentToolUse['id'],
                        'name' => $currentToolUse['name'],
                        'input' => empty($input) ? new \stdClass() : $input,
                    ];
                    $toolUseBlocks[] = $toolBlock;
                    yield $toolBlock;
                    $currentToolUse = null;
                    $currentToolJson = '';
                }
            } elseif ($type === 'message_start') {
                // Capture usage from message start
                $usage = $event['message']['usage'] ?? [];
                if (!empty($usage)) {
                    yield ['type' => 'usage', 'input_tokens' => $usage['input_tokens'] ?? 0, 'output_tokens' => 0, 'cache_read_tokens' => $usage['cache_read_input_tokens'] ?? 0, 'cache_creation_tokens' => $usage['cache_creation_input_tokens'] ?? 0];
                }
            } elseif ($type === 'message_delta') {
                // Capture final usage
                $usage = $event['usage'] ?? [];
                if (!empty($usage)) {
                    yield ['type' => 'usage', 'input_tokens' => 0, 'output_tokens' => $usage['output_tokens'] ?? 0, 'cache_read_tokens' => 0, 'cache_creation_tokens' => 0];
                }
            } elseif ($type === 'message_stop') {
                yield ['type' => 'done'];
            } elseif ($type === 'error') {
                yield ['type' => 'error', 'message' => $event['error']['message'] ?? 'Unknown Claude error'];
            }
        }
    }

    protected function formatTools(array $tools): array {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ];
        }
        return $formatted;
    }

    private function convertMessage(array $msg): array {
        $role = $msg['role'];

        // Tool result → Claude uses 'user' role with tool_result content blocks
        if ($role === 'tool') {
            return [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $msg['tool_use_id'] ?? '',
                    'content' => is_string($msg['content']) ? $msg['content'] : json_encode($msg['content']),
                ]],
            ];
        }

        // Assistant messages with content blocks — ensure tool_use inputs are objects
        if ($role === 'assistant' && is_array($msg['content'])) {
            $content = array_map(function ($block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    // Ensure input is always an object, never null or string
                    $input = $block['input'] ?? [];
                    if (is_string($input)) {
                        $input = json_decode($input, true) ?? [];
                    }
                    if (!is_array($input)) {
                        $input = [];
                    }
                    // Force object encoding even for empty arrays
                    $block['input'] = empty($input) ? new \stdClass() : $input;
                }
                return $block;
            }, $msg['content']);
            return ['role' => 'assistant', 'content' => $content];
        }

        return $msg;
    }
}

// ── OpenAI Provider ─────────────────────────────────────

class RangerOpenAI extends RangerProvider {
    public function stream(string $systemPrompt, array $messages, array $tools, string $model): \Generator {
        $apiMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($messages as $msg) {
            $apiMessages[] = $this->convertMessage($msg);
        }

        $resolvedModel = $model ?: 'gpt-4o';
        $maxTokens = match (true) {
            str_contains($resolvedModel, '4o') => 16384,
            str_contains($resolvedModel, 'turbo') => 4096,
            default => 16384,
        };

        $body = [
            'model' => $resolvedModel,
            'messages' => $apiMessages,
            'max_tokens' => $maxTokens,
            'stream' => true,
        ];
        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $textContent = '';
        $toolCalls = [];

        $rawEvents = $this->curlStream('https://api.openai.com/v1/chat/completions', $headers, $body);

        foreach ($rawEvents as $line) {
            if (is_array($line)) {
                yield $line;
                return;
            }

            // Non-SSE error response (OpenAI returns plain JSON on auth/rate errors)
            if (!str_starts_with($line, 'data:') && str_contains($line, '"error"')) {
                $errJson = json_decode($line, true);
                if ($errJson && isset($errJson['error']['message'])) {
                    yield ['type' => 'error', 'message' => $errJson['error']['message']];
                    return;
                }
            }

            if (!str_starts_with($line, 'data: ')) continue;
            $json = substr($line, 6);
            if ($json === '[DONE]') {
                // Emit any accumulated tool calls
                foreach ($toolCalls as $tc) {
                    $input = json_decode($tc['arguments'], true);
                    if (!is_array($input)) $input = [];
                    yield [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['name'],
                        'input' => empty($input) ? new \stdClass() : $input,
                    ];
                }
                yield ['type' => 'done'];
                return;
            }

            $event = json_decode($json, true);
            if (!$event) continue;

            // Check for error events in the stream
            if (isset($event['error'])) {
                yield ['type' => 'error', 'message' => $event['error']['message'] ?? 'OpenAI API error'];
                return;
            }

            $delta = $event['choices'][0]['delta'] ?? [];

            // Only yield text when content is a non-null string
            if (isset($delta['content']) && is_string($delta['content'])) {
                $text = $delta['content'];
                $textContent .= $text;
                yield ['type' => 'text', 'content' => $text];
            }

            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = $tc['index'] ?? 0;
                    if (!isset($toolCalls[$idx])) {
                        $toolCalls[$idx] = [
                            'id' => $tc['id'] ?? '',
                            'name' => $tc['function']['name'] ?? '',
                            'arguments' => '',
                        ];
                    }
                    if (isset($tc['id']) && $tc['id']) {
                        $toolCalls[$idx]['id'] = $tc['id'];
                    }
                    if (isset($tc['function']['name'])) {
                        $toolCalls[$idx]['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $toolCalls[$idx]['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }
        }
    }

    protected function formatTools(array $tools): array {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ];
        }
        return $formatted;
    }

    private function convertMessage(array $msg): array {
        $role = $msg['role'];
        if ($role === 'assistant') {
            $content = $msg['content'] ?? '';
            // If message has tool_use content blocks
            if (is_array($content)) {
                $textParts = '';
                $toolCalls = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $textParts .= $block['text'];
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $input = $block['input'] ?? [];
                        if (is_string($input)) {
                            $input = json_decode($input, true) ?? [];
                        }
                        if (!is_array($input)) {
                            $input = [];
                        }
                        $toolCalls[] = [
                            'id' => $block['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => $block['name'],
                                'arguments' => json_encode(empty($input) ? new \stdClass() : $input),
                            ],
                        ];
                    }
                }
                $result = ['role' => 'assistant', 'content' => $textParts ?: null];
                if (!empty($toolCalls)) {
                    $result['tool_calls'] = $toolCalls;
                }
                return $result;
            }
            return ['role' => 'assistant', 'content' => $content];
        }
        if ($role === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => $msg['tool_use_id'] ?? '',
                'content' => is_string($msg['content']) ? $msg['content'] : json_encode($msg['content']),
            ];
        }
        // user messages — handle multimodal (images)
        if (is_array($msg['content'])) {
            $parts = [];
            foreach ($msg['content'] as $block) {
                if (($block['type'] ?? '') === 'image' && isset($block['source'])) {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:' . $block['source']['media_type'] . ';base64,' . $block['source']['data']],
                    ];
                } elseif (($block['type'] ?? '') === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $block['text']];
                }
            }
            return ['role' => 'user', 'content' => $parts ?: ($msg['content'] ?? '')];
        }
        return ['role' => 'user', 'content' => $msg['content'] ?? ''];
    }
}

// ── Gemini Provider ─────────────────────────────────────

class RangerGemini extends RangerProvider {
    public function stream(string $systemPrompt, array $messages, array $tools, string $model): \Generator {
        $model = $model ?: 'gemini-2.0-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':streamGenerateContent?alt=sse&key=' . $this->apiKey;

        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = $this->convertMessage($msg);
        }

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => 16384,
            ],
        ];
        if (!empty($tools)) {
            $body['tools'] = [['functionDeclarations' => $this->formatTools($tools)]];
        }

        $headers = ['Content-Type: application/json'];

        $rawEvents = $this->curlStream($url, $headers, $body);
        $yieldedDone = false;

        foreach ($rawEvents as $line) {
            if (is_array($line)) {
                yield $line;
                return;
            }

            // Non-SSE error response (Gemini returns plain JSON on 400s)
            if (!str_starts_with($line, 'data:') && str_contains($line, '"error"')) {
                $errJson = json_decode($line, true);
                if ($errJson) {
                    $errMsg = $errJson['error']['message'] ?? $errJson['message'] ?? 'Gemini API error';
                    yield ['type' => 'error', 'message' => $errMsg];
                    return;
                }
            }

            if (!str_starts_with($line, 'data: ')) continue;
            $json = substr($line, 6);
            $event = json_decode($json, true);
            if (!$event) continue;

            // Check for API-level errors (e.g. invalid key, quota exceeded)
            if (isset($event['error'])) {
                yield ['type' => 'error', 'message' => $event['error']['message'] ?? 'Gemini API error'];
                return;
            }

            $parts = $event['candidates'][0]['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    yield ['type' => 'text', 'content' => $part['text']];
                }
                if (isset($part['functionCall'])) {
                    $args = $part['functionCall']['args'] ?? [];
                    if (!is_array($args)) $args = [];
                    yield [
                        'type' => 'tool_use',
                        'id' => 'gemini_' . bin2hex(random_bytes(8)),
                        'name' => $part['functionCall']['name'],
                        'input' => empty($args) ? new \stdClass() : $args,
                    ];
                }
            }

            $finishReason = $event['candidates'][0]['finishReason'] ?? '';
            if ($finishReason === 'STOP' || $finishReason === 'MAX_TOKENS') {
                $yieldedDone = true;
                yield ['type' => 'done'];
            }
        }

        // Fallback: ensure we always yield done
        if (!$yieldedDone) {
            yield ['type' => 'done'];
        }
    }

    protected function formatTools(array $tools): array {
        $formatted = [];
        foreach ($tools as $tool) {
            $schema = $tool['parameters'];
            $schema = $this->stripAdditionalProperties($schema);
            $formatted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $schema,
            ];
        }
        return $formatted;
    }

    /**
     * Recursively strip additionalProperties from schemas — Gemini rejects them.
     */
    private function stripAdditionalProperties(array $schema): array {
        unset($schema['additionalProperties']);
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = $this->stripAdditionalProperties($prop);
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->stripAdditionalProperties($schema['items']);
        }
        return $schema;
    }

    private function convertMessage(array $msg): array {
        $role = $msg['role'] === 'assistant' ? 'model' : 'user';
        $content = $msg['content'] ?? '';

        // Tool result messages become user messages with functionResponse
        if ($msg['role'] === 'tool') {
            $resultContent = is_string($content) ? json_decode($content, true) ?? ['result' => $content] : $content;
            return [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $msg['tool_name'] ?? 'unknown',
                        'response' => $resultContent,
                    ],
                ]],
            ];
        }

        if ($role === 'model' && is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $parts[] = ['text' => $block['text']];
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $input = $block['input'] ?? [];
                    if (is_string($input)) {
                        $input = json_decode($input, true) ?? [];
                    }
                    if (!is_array($input)) {
                        $input = [];
                    }
                    $parts[] = [
                        'functionCall' => [
                            'name' => $block['name'],
                            'args' => empty($input) ? new \stdClass() : $input,
                        ],
                    ];
                }
            }
            return ['role' => 'model', 'parts' => $parts ?: [['text' => '']]];
        }

        // user messages — handle multimodal (images)
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'image' && isset($block['source'])) {
                    $parts[] = ['inlineData' => ['mimeType' => $block['source']['media_type'], 'data' => $block['source']['data']]];
                } elseif (($block['type'] ?? '') === 'text') {
                    $parts[] = ['text' => $block['text']];
                }
            }
            return ['role' => $role, 'parts' => $parts];
        }
        return ['role' => $role, 'parts' => [['text' => is_string($content) ? $content : json_encode($content)]]];
    }
}

// ── Tool Definitions ────────────────────────────────────

function ranger_classify_intent(string $message): string {
    $msg = strtolower($message);
    if (preg_match('/\b(dark mode|light mode|navigate|go to|open|toggle)\b/', $msg)) return 'ui';
    if (preg_match('/\b(theme|template|html|css|javascript|build|design|create.*page|layout|style)\b/', $msg)) return 'build';
    if (preg_match('/\b(collection|item|post|blog|content|draft|publish|article)\b/', $msg)) return 'content';
    return 'full';
}

function ranger_get_tools(array $userCaps, string $intent = 'full'): array {
    $allTools = [
        // ── All roles ──
        [
            'name' => 'get_site_info',
            'description' => 'Get site settings, collections list, and other site information.',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'required_capability' => null,
        ],
        [
            'name' => 'list_content',
            'description' => 'List content items. Use type to specify what to list: pages, collections, items (requires collection_slug), or globals.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['pages', 'collections', 'items', 'globals'], 'description' => 'What type of content to list'],
                    'collection_slug' => ['type' => 'string', 'description' => 'Required when type is "items" — the collection slug'],
                ],
                'required' => ['type'],
            ],
            'required_capability' => null,
        ],
        [
            'name' => 'create_item',
            'description' => 'Create a new item in a collection. Provide the collection slug, field data, status, and slug.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'collection_slug' => ['type' => 'string', 'description' => 'The collection slug to add the item to'],
                    'data' => ['type' => 'object', 'description' => 'Field data as key-value pairs'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'description' => 'Item status'],
                    'slug' => ['type' => 'string', 'description' => 'URL slug for the item'],
                ],
                'required' => ['collection_slug', 'data', 'slug'],
            ],
            'required_capability' => null,
        ],
        [
            'name' => 'update_item',
            'description' => 'Update an existing collection item by ID. Provide the fields to update.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => ['type' => 'integer', 'description' => 'The item ID to update'],
                    'data' => ['type' => 'object', 'description' => 'Field data to update as key-value pairs'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published']],
                    'slug' => ['type' => 'string', 'description' => 'New URL slug'],
                ],
                'required' => ['item_id'],
            ],
            'required_capability' => null,
        ],
        [
            'name' => 'search_docs',
            'description' => 'Search the Outpost CMS documentation for information about template syntax, features, and configuration.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query'],
                ],
                'required' => ['query'],
            ],
            'required_capability' => null,
        ],

        [
            'name' => 'frontend_action',
            'description' => 'Execute a frontend-only action in the admin panel. Actions: "set_dark_mode" (params: enabled true/false), "toggle_dark_mode" (no params), "navigate" (params: page string e.g. "dashboard", "pages", "collections", "media", "settings", "code-editor", "themes", "analytics", "navigation", "globals"), "refresh_page" (no params). Use this for UI actions that don\'t involve data changes.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'description' => 'The frontend action to execute', 'enum' => ['set_dark_mode', 'toggle_dark_mode', 'navigate', 'refresh_page']],
                    'params' => ['type' => 'object', 'description' => 'Parameters for the action'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => null,
        ],

        // ── Developer+ (code.*) ──
        [
            'name' => 'read_file',
            'description' => 'Read a file from the site root. Path is relative to site root (e.g. "index.html", "css/style.css"). Cannot read files inside outpost/.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File path relative to site root (e.g. "index.html", "partials/nav.html")'],
                ],
                'required' => ['path'],
            ],
            'required_capability' => 'code.*',
        ],
        [
            'name' => 'write_file',
            'description' => 'Write or update a file in the site root. Creates parent directories if needed. Cannot write to outpost/.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File path relative to site root'],
                    'content' => ['type' => 'string', 'description' => 'File content to write'],
                ],
                'required' => ['path', 'content'],
            ],
            'required_capability' => 'code.*',
        ],
        [
            'name' => 'delete_file',
            'description' => 'Delete a file from the site root. Cannot delete files inside outpost/.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File path relative to site root'],
                ],
                'required' => ['path'],
            ],
            'required_capability' => 'code.*',
        ],
        [
            'name' => 'clear_cache',
            'description' => 'Clear the template cache. Useful after making theme file changes.',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'required_capability' => 'code.*',
        ],

        // ── Admin+ (settings.*) ──
        [
            'name' => 'create_collection',
            'description' => 'Create a new content collection with a schema of fields.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Collection display name (e.g. "Blog Posts")'],
                    'slug' => ['type' => 'string', 'description' => 'URL-safe slug (e.g. "post")'],
                    'singular_name' => ['type' => 'string', 'description' => 'Singular name (e.g. "Blog Post")'],
                    'url_pattern' => ['type' => 'string', 'description' => 'URL pattern (e.g. "/post/{slug}")'],
                    'fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'required' => ['type' => 'boolean'],
                            ],
                        ],
                        'description' => 'Array of field definitions',
                    ],
                ],
                'required' => ['name', 'slug'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_collection',
            'description' => 'Update or delete an existing collection.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['update', 'delete']],
                    'id' => ['type' => 'integer', 'description' => 'Collection ID'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'url_pattern' => ['type' => 'string'],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
                'required' => ['action', 'id'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_items_bulk',
            'description' => 'Perform bulk operations on collection items.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['publish', 'unpublish', 'delete']],
                    'item_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of item IDs'],
                ],
                'required' => ['action', 'item_ids'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_workflows',
            'description' => 'Create, update, or list content workflows. Workflows define custom approval stages for collections (e.g., Draft → Review → Approved → Published).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'get', 'update', 'delete', 'transition', 'history'], 'description' => 'Action to perform'],
                    'id' => ['type' => 'integer', 'description' => 'Workflow ID (for get/update/delete)'],
                    'name' => ['type' => 'string', 'description' => 'Workflow name (for create/update)'],
                    'stages' => ['type' => 'array', 'description' => 'Array of stage objects: {name, slug, color, roles[], can_move_to[]}'],
                    'item_id' => ['type' => 'integer', 'description' => 'For transition: the collection item ID'],
                    'to_stage' => ['type' => 'string', 'description' => 'For transition: target stage slug'],
                    'note' => ['type' => 'string', 'description' => 'For transition: optional note'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_comments',
            'description' => 'Manage comments, create review links for client feedback, and view activity feed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'resolve', 'delete', 'activity', 'create_review_link', 'list_review_links']],
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type: page, item, field, element'],
                    'entity_id' => ['type' => 'integer', 'description' => 'Entity ID'],
                    'body' => ['type' => 'string', 'description' => 'Comment body text'],
                    'comment_id' => ['type' => 'integer', 'description' => 'Comment ID (for resolve/delete)'],
                    'name' => ['type' => 'string', 'description' => 'Review link name'],
                    'page_path' => ['type' => 'string', 'description' => 'URL path'],
                    'expires_at' => ['type' => 'string', 'description' => 'Expiry datetime'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => null,
        ],
        [
            'name' => 'update_fields',
            'description' => 'Update page or global fields by page ID.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'description' => 'Page ID to update fields for'],
                    'fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'field_name' => ['type' => 'string'],
                                'content' => ['type' => 'string'],
                            ],
                        ],
                        'description' => 'Array of field name + content pairs',
                    ],
                ],
                'required' => ['page_id', 'fields'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'list_menus',
            'description' => 'List all navigation menus.',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_menu',
            'description' => 'Create, update, or delete a navigation menu.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'items' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Menu items array with label, url, children'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'list_folders',
            'description' => 'List folders and labels for organizing collection items.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'collection_id' => ['type' => 'integer', 'description' => 'Optional: filter by collection ID'],
                ],
                'required' => [],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_folder',
            'description' => 'Create, update, or delete a folder (taxonomy group).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'id' => ['type' => 'integer'],
                    'collection_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_label',
            'description' => 'Create, update, or delete a label (taxonomy term) within a folder.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'id' => ['type' => 'integer'],
                    'folder_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'list_forms',
            'description' => 'List all form builder forms.',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_form',
            'description' => 'Create, update, or delete a form.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'list_media',
            'description' => 'List uploaded media files.',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'upload_media',
            'description' => 'Download an image from a URL and upload it to Outpost\'s media library. Returns the local path (e.g., /outpost/uploads/filename.jpg) that can be used in templates and collection items. Use this when you need real images instead of external URLs.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL of the image to download (Unsplash, etc.)'],
                    'filename' => ['type' => 'string', 'description' => 'Desired filename (e.g., "hero-restaurant.jpg"). Extension will be auto-detected if omitted.'],
                ],
                'required' => ['url'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_media_folder',
            'description' => 'Create, update, or delete a media folder.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'update_settings',
            'description' => 'Update site settings. Provide key-value pairs to update.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'settings' => ['type' => 'object', 'description' => 'Object of setting key-value pairs to update'],
                ],
                'required' => ['settings'],
            ],
            'required_capability' => 'settings.*',
        ],

        // ── Admin+ additional tools ──
        [
            'name' => 'manage_users',
            'description' => 'Create, update, or delete admin/editor users. Can set roles and collection access grants.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'delete']],
                    'id' => ['type' => 'integer', 'description' => 'User ID (for update/delete)'],
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'password' => ['type' => 'string'],
                    'role' => ['type' => 'string', 'enum' => ['editor', 'developer', 'admin', 'super_admin']],
                    'display_name' => ['type' => 'string'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'users.*',
        ],
        [
            'name' => 'manage_webhooks',
            'description' => 'Create, update, delete, or list webhooks. Webhooks fire HTTP requests when content changes.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'delete', 'test']],
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'url' => ['type' => 'string', 'description' => 'Webhook endpoint URL'],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Events to trigger on: entry.created, entry.updated, entry.deleted, media.created, form.submitted, *'],
                    'active' => ['type' => 'boolean'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_backup',
            'description' => 'Create, list, or restore site backups. Backups include the database and all content.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'restore', 'delete']],
                    'file' => ['type' => 'string', 'description' => 'Backup filename (for restore/delete)'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_channel',
            'description' => 'Create, update, delete, or sync a Channel (external data source). Channels pull data from REST APIs, RSS feeds, or CSV files into templates.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'delete', 'sync']],
                    'id' => ['type' => 'integer'],
                    'slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => ['api', 'rss', 'csv']],
                    'config' => ['type' => 'object', 'description' => 'Connection config: {url, method, params, headers, auth_type, auth_config}'],
                    'field_map' => ['type' => 'object', 'description' => 'Map external fields to internal: {title: "data.name", image: "data.image_url"}'],
                    'cache_ttl' => ['type' => 'integer', 'description' => 'Cache duration in seconds (default 3600)'],
                    'url_pattern' => ['type' => 'string', 'description' => 'URL pattern for single items (e.g., /repos/{slug})'],
                    'max_items' => ['type' => 'integer'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'query_database',
            'description' => 'Run a read-only SQL query against the Outpost SQLite database. For inspecting data, debugging, and understanding content relationships. Only SELECT queries allowed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'SQL SELECT query (read-only, no INSERT/UPDATE/DELETE)'],
                ],
                'required' => ['query'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_members',
            'description' => 'List, update, or delete front-end members (site visitors with accounts).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'update', 'delete']],
                    'id' => ['type' => 'integer'],
                    'plan' => ['type' => 'string', 'description' => 'Member plan (free, paid)'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'suspended']],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'members.*',
        ],
        [
            'name' => 'debug_template',
            'description' => 'Debug template compilation issues. Can check a specific template file for syntax errors, read recent error logs, or validate template syntax.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['check_file', 'recent_errors', 'validate_syntax']],
                    'path' => ['type' => 'string', 'description' => 'Theme file path for check_file (e.g., "restaurant-pro/index.html")'],
                    'template' => ['type' => 'string', 'description' => 'Raw template content to validate for validate_syntax'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'code.*',
        ],
        [
            'name' => 'manage_email',
            'description' => 'Configure email settings including SMTP, from address, and notification preferences. Can also test the email configuration.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['get', 'update', 'test']],
                    'smtp_host' => ['type' => 'string'],
                    'smtp_port' => ['type' => 'integer'],
                    'smtp_user' => ['type' => 'string'],
                    'smtp_pass' => ['type' => 'string'],
                    'smtp_secure' => ['type' => 'string', 'enum' => ['tls', 'ssl', 'none']],
                    'from_email' => ['type' => 'string'],
                    'from_name' => ['type' => 'string'],
                    'test_email' => ['type' => 'string', 'description' => 'Email address to send test email to'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'manage_releases',
            'description' => 'Create, publish, or rollback content releases. Releases bundle multiple changes and publish them all at once.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'create', 'get', 'publish', 'rollback', 'delete', 'add_change']],
                    'id' => ['type' => 'integer', 'description' => 'Release ID (for get/publish/rollback/delete/add_change)'],
                    'name' => ['type' => 'string', 'description' => 'Release name (for create)'],
                    'description' => ['type' => 'string', 'description' => 'Release description (for create)'],
                    'entity_type' => ['type' => 'string', 'enum' => ['field', 'item', 'menu', 'page', 'collection'], 'description' => 'Entity type (for add_change)'],
                    'entity_id' => ['type' => 'integer', 'description' => 'Entity ID (for add_change)'],
                    'entity_name' => ['type' => 'string', 'description' => 'Human-readable label (for add_change)'],
                    'change_action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete'], 'description' => 'Change action (for add_change)'],
                ],
                'required' => ['action'],
            ],
            'required_capability' => 'settings.*',
        ],
        [
            'name' => 'forge_scan',
            'description' => 'Run Smart Forge on an HTML file to auto-detect all content elements and convert to an editable Outpost template.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Theme file path (e.g., "my-theme/index.html")'],
                ],
                'required' => ['path'],
            ],
            'required_capability' => 'code.*',
        ],
    ];

    // Filter by user capabilities
    $tools = array_values(array_filter($allTools, function ($tool) use ($userCaps) {
        if ($tool['required_capability'] === null) return true;
        return in_array($tool['required_capability'], $userCaps, true);
    }));

    // Filter by intent to reduce token cost — fewer tool definitions = fewer input tokens
    if ($intent !== 'full') {
        $intentTools = [
            'ui' => ['frontend_action', 'get_site_info', 'update_settings'],
            'build' => ['get_site_info', 'list_content', 'search_docs', 'read_file', 'write_file', 'delete_file', 'clear_cache', 'upload_media', 'create_collection', 'create_item', 'manage_menu', 'frontend_action', 'debug_template', 'manage_channel', 'forge_scan'],
            'content' => ['get_site_info', 'list_content', 'create_item', 'update_item', 'manage_items_bulk', 'update_fields', 'create_collection', 'manage_collection', 'list_folders', 'manage_folder', 'manage_label', 'search_docs', 'frontend_action', 'manage_email', 'manage_releases', 'manage_workflows', 'manage_comments'],
        ];
        $allowed = $intentTools[$intent] ?? null;
        if ($allowed !== null) {
            $tools = array_values(array_filter($tools, fn($t) => in_array($t['name'], $allowed, true)));
        }
    }

    return $tools;
}

// ── Tool Result Truncation ──────────────────────────────

function ranger_truncate_tool_result(string $toolName, array $result): array {
    // Truncate read_file content to save tokens — AI usually needs structure, not every line
    if ($toolName === 'read_file' && isset($result['content']) && is_string($result['content'])) {
        if (strlen($result['content']) > 2000) {
            $result['content'] = substr($result['content'], 0, 2000) . "\n\n...(truncated, " . $result['size'] . " bytes total)";
        }
    }

    // Truncate list_content items to first 10
    if ($toolName === 'list_content' && isset($result['items']) && is_array($result['items'])) {
        $total = count($result['items']);
        if ($total > 10) {
            $result['items'] = array_slice($result['items'], 0, 10);
            $result['_note'] = "Showing 10 of $total items. Use create_item/update_item with specific IDs.";
        }
    }

    // Truncate search_docs results
    if ($toolName === 'search_docs' && isset($result['results']) && is_array($result['results'])) {
        foreach ($result['results'] as &$r) {
            if (is_string($r) && strlen($r) > 500) {
                $r = substr($r, 0, 500) . '...(truncated)';
            }
        }
    }

    return $result;
}

// ── Tool Capability Map ─────────────────────────────────

function ranger_tool_requires_capability(string $name): ?string {
    static $map = null;
    if ($map === null) {
        // Build map from the canonical tools list (uses empty caps to get all tools)
        $allTools = ranger_get_tools(['settings.*', 'code.*', 'users.*', 'members.*', 'cache.*']);
        $map = [];
        foreach ($allTools as $tool) {
            $map[$tool['name']] = $tool['required_capability'] ?? null;
        }
    }
    return $map[$name] ?? null;
}

// ── Tool Execution ──────────────────────────────────────

function ranger_execute_tool(string $name, mixed $input): array {
    // Normalize stdClass to array (from empty JSON objects)
    if ($input instanceof \stdClass || !is_array($input)) {
        $input = (array)$input;
    }
    try {
        switch ($name) {
            case 'frontend_action':
                // This tool is executed client-side. Return a marker.
                return ['_frontend_action' => true, 'action' => $input['action'] ?? '', 'params' => $input['params'] ?? []];
            case 'get_site_info':
                return ranger_tool_get_site_info();
            case 'list_content':
                return ranger_tool_list_content($input);
            case 'create_item':
                return ranger_tool_create_item($input);
            case 'update_item':
                return ranger_tool_update_item($input);
            case 'search_docs':
                return ranger_tool_search_docs($input);
            case 'read_file':
                return ranger_tool_read_file($input);
            case 'write_file':
                return ranger_tool_write_file($input);
            case 'delete_file':
                return ranger_tool_delete_file($input);
            case 'clear_cache':
                return ranger_tool_clear_cache();
            case 'create_collection':
                return ranger_tool_create_collection($input);
            case 'manage_collection':
                return ranger_tool_manage_collection($input);
            case 'manage_items_bulk':
                return ranger_tool_manage_items_bulk($input);
            case 'update_fields':
                return ranger_tool_update_fields($input);
            case 'list_menus':
                return ranger_tool_list_menus();
            case 'manage_menu':
                return ranger_tool_manage_menu($input);
            case 'list_folders':
                return ranger_tool_list_folders($input);
            case 'manage_folder':
                return ranger_tool_manage_folder($input);
            case 'manage_label':
                return ranger_tool_manage_label($input);
            case 'list_forms':
                return ranger_tool_list_forms();
            case 'manage_form':
                return ranger_tool_manage_form($input);
            case 'list_media':
                return ranger_tool_list_media();
            case 'upload_media':
                return ranger_tool_upload_media($input);
            case 'manage_media_folder':
                return ranger_tool_manage_media_folder($input);
            case 'update_settings':
                return ranger_tool_update_settings($input);
            case 'manage_users':
                return ranger_tool_manage_users($input);
            case 'manage_webhooks':
                return ranger_tool_manage_webhooks($input);
            case 'manage_backup':
                return ranger_tool_manage_backup($input);
            case 'manage_channel':
                return ranger_tool_manage_channel($input);
            case 'query_database':
                return ranger_tool_query_database($input);
            case 'manage_members':
                return ranger_tool_manage_members($input);
            case 'debug_template':
                return ranger_tool_debug_template($input);
            case 'manage_email':
                return ranger_tool_manage_email($input);
            case 'manage_releases':
                return ranger_tool_manage_releases($input);
            case 'manage_workflows':
                return ranger_tool_manage_workflows($input);
            case 'manage_comments':
                return ranger_handle_manage_comments($input);
            case 'forge_scan':
                return ranger_tool_forge_scan($input);
            default:
                return ['error' => "Unknown tool: $name"];
        }
    } catch (\Throwable $e) {
        error_log('Ranger tool error (' . $name . '): ' . $e->getMessage());
        return ['error' => 'Tool execution failed. Check the error log for details.'];
    }
}

// ── Tool Implementations ────────────────────────────────

function ranger_tool_get_site_info(): array {
    $settings = [];
    $rows = OutpostDB::fetchAll('SELECT * FROM settings');
    foreach ($rows as $row) {
        // Exclude sensitive keys
        if (str_starts_with($row['key'], 'ranger_api_key')) continue;
        $settings[$row['key']] = $row['value'];
    }

    $collections = OutpostDB::fetchAll('SELECT id, slug, name, singular_name, url_pattern, schema FROM collections ORDER BY name');
    $collectionsSummary = [];
    foreach ($collections as $c) {
        $schema = json_decode($c['schema'] ?? '{}', true);
        $fieldCount = count($schema['fields'] ?? []);
        $collectionsSummary[] = [
            'id' => $c['id'],
            'slug' => $c['slug'],
            'name' => $c['name'],
            'singular_name' => $c['singular_name'],
            'url_pattern' => $c['url_pattern'],
            'field_count' => $fieldCount,
        ];
    }

    return [
        'version' => OUTPOST_VERSION,
        'settings' => $settings,
        'collections' => $collectionsSummary,
    ];
}

function ranger_tool_list_content(array $input): array {
    $type = $input['type'] ?? '';

    switch ($type) {
        case 'pages':
            $pages = OutpostDB::fetchAll("SELECT id, path, title, meta_title, visibility FROM pages WHERE path != '__global__' ORDER BY path");
            return ['pages' => $pages];

        case 'collections':
            $collections = OutpostDB::fetchAll('SELECT id, slug, name, singular_name, url_pattern FROM collections ORDER BY name');
            return ['collections' => $collections];

        case 'items':
            $slug = $input['collection_slug'] ?? '';
            if (!$slug) return ['error' => 'collection_slug is required when type is "items"'];
            $collection = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug]);
            if (!$collection) return ['error' => "Collection '$slug' not found"];
            $items = OutpostDB::fetchAll(
                'SELECT id, slug, status, data, created_at, updated_at FROM collection_items WHERE collection_id = ? ORDER BY created_at DESC LIMIT 100',
                [$collection['id']]
            );
            foreach ($items as &$item) {
                $item['data'] = json_decode($item['data'] ?? '{}', true);
            }
            return ['items' => $items, 'collection_slug' => $slug];

        case 'globals':
            $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
            if (!$globalPage) return ['globals' => []];
            $fields = OutpostDB::fetchAll(
                "SELECT field_name, content FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL)",
                [$globalPage['id']]
            );
            $globals = [];
            foreach ($fields as $f) {
                $globals[$f['field_name']] = $f['content'];
            }
            return ['globals' => $globals];

        default:
            return ['error' => 'Invalid type. Use: pages, collections, items, or globals'];
    }
}

function ranger_tool_create_item(array $input): array {
    $slug = $input['collection_slug'] ?? '';
    $collection = OutpostDB::fetchOne('SELECT id, url_pattern FROM collections WHERE slug = ?', [$slug]);
    if (!$collection) return ['error' => "Collection '$slug' not found"];

    $itemSlug = $input['slug'] ?? '';
    if (!$itemSlug) return ['error' => 'slug is required'];

    $data = $input['data'] ?? [];
    $status = $input['status'] ?? 'draft';

    $db = OutpostDB::connect();
    $stmt = $db->prepare('INSERT INTO collection_items (collection_id, slug, status, data, created_at, updated_at) VALUES (?, ?, ?, ?, datetime("now"), datetime("now"))');
    $stmt->execute([$collection['id'], $itemSlug, $status, json_encode($data)]);
    $itemId = (int)$db->lastInsertId();

    return ['success' => true, 'id' => $itemId, 'slug' => $itemSlug, 'status' => $status];
}

function ranger_tool_update_item(array $input): array {
    $itemId = $input['item_id'] ?? 0;
    if (!$itemId) return ['error' => 'item_id is required'];

    $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
    if (!$item) return ['error' => "Item $itemId not found"];

    $updates = [];
    $params = [];

    if (isset($input['data'])) {
        $existingData = json_decode($item['data'] ?? '{}', true);
        $merged = array_merge($existingData, $input['data']);
        $updates[] = 'data = ?';
        $params[] = json_encode($merged);
    }
    if (isset($input['status'])) {
        $updates[] = 'status = ?';
        $params[] = $input['status'];
    }
    if (isset($input['slug'])) {
        $updates[] = 'slug = ?';
        $params[] = $input['slug'];
    }

    if (empty($updates)) return ['error' => 'No updates provided'];

    $updates[] = 'updated_at = datetime("now")';
    $params[] = $itemId;

    $db = OutpostDB::connect();
    $db->prepare('UPDATE collection_items SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    return ['success' => true, 'id' => $itemId];
}

function ranger_tool_search_docs(array $input): array {
    $query = strtolower(trim($input['query'] ?? ''));
    if (!$query) return ['error' => 'query is required'];

    $docsFile = __DIR__ . '/docs/llms.txt';
    if (!file_exists($docsFile)) return ['error' => 'Documentation file not found'];

    $lines = file($docsFile, FILE_IGNORE_NEW_LINES);
    $results = [];
    $queryTerms = explode(' ', $query);

    foreach ($lines as $i => $line) {
        $lower = strtolower($line);
        $matched = true;
        foreach ($queryTerms as $term) {
            if ($term !== '' && strpos($lower, $term) === false) {
                $matched = false;
                break;
            }
        }
        if ($matched) {
            // Grab context: 5 lines before and 10 lines after
            $start = max(0, $i - 5);
            $end = min(count($lines) - 1, $i + 10);
            $context = implode("\n", array_slice($lines, $start, $end - $start + 1));
            $results[] = $context;
            if (count($results) >= 5) break; // Max 5 matches
        }
    }

    if (empty($results)) {
        return ['message' => 'No documentation found matching: ' . $query];
    }

    return ['results' => $results];
}

// Theme listing removed — v5.0 uses site root directly, no theme layer.

function ranger_validate_site_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, "\x00")) {
        throw new RuntimeException('Invalid path');
    }
    if (str_contains($path, '..') || str_starts_with($path, '/')) {
        throw new RuntimeException('Invalid path: directory traversal not allowed');
    }
    // Normalize ./segments to prevent bypass
    $path = preg_replace('#(^|/)\./#', '$1', $path);
    // Reject paths targeting outpost/ directory
    if (str_starts_with($path, 'outpost/') || $path === 'outpost') {
        throw new RuntimeException('Cannot access the outpost/ directory');
    }
    $fullPath = OUTPOST_SITE_ROOT . $path;
    $realBase = realpath(OUTPOST_SITE_ROOT);
    // For new files, check parent
    $checkPath = file_exists($fullPath) ? $fullPath : dirname($fullPath);
    $realCheck = realpath($checkPath);
    $realBase = $realBase ? rtrim($realBase, '/') : false;
    if ($realBase === false || $realCheck === false || (!str_starts_with($realCheck, $realBase . '/') && $realCheck !== $realBase)) {
        throw new RuntimeException('Path is outside the site root');
    }
    // Double-check resolved path is not inside outpost/
    $outpostDir = $realBase . '/outpost';
    if (str_starts_with($realCheck, $outpostDir . '/') || $realCheck === $outpostDir) {
        throw new RuntimeException('Cannot access the outpost/ directory');
    }
    return $fullPath;
}

function ranger_tool_read_file(array $input): array {
    $path = $input['path'] ?? '';
    if (!$path) return ['error' => 'path is required'];

    $fullPath = ranger_validate_site_path($path);
    if (!file_exists($fullPath)) return ['error' => "File not found: $path"];
    if (!is_file($fullPath)) return ['error' => "Not a file: $path"];

    $content = file_get_contents($fullPath);
    $size = strlen($content);

    // Truncate very large files
    if ($size > 50000) {
        $content = substr($content, 0, 50000) . "\n\n... [truncated at 50KB]";
    }

    return ['path' => $path, 'content' => $content, 'size' => $size];
}

function ranger_tool_write_file(array $input): array {
    $path = $input['path'] ?? '';
    $content = $input['content'] ?? '';
    if (!$path) return ['error' => 'path is required'];
    if (strlen($content) > 1048576) {
        return ['error' => 'File content exceeds 1MB limit'];
    }

    $fullPath = ranger_validate_site_path($path);
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $existed = file_exists($fullPath);
    file_put_contents($fullPath, $content);

    return ['success' => true, 'path' => $path, 'action' => $existed ? 'updated' : 'created', 'size' => strlen($content)];
}

function ranger_tool_delete_file(array $input): array {
    $path = $input['path'] ?? '';
    if (!$path) return ['error' => 'path is required'];

    $fullPath = ranger_validate_site_path($path);
    if (!file_exists($fullPath)) return ['error' => "File not found: $path"];
    if (!is_file($fullPath)) return ['error' => "Not a file: $path"];

    unlink($fullPath);
    return ['success' => true, 'path' => $path];
}

function ranger_tool_clear_cache(): array {
    $count = 0;
    $templateCache = OUTPOST_CACHE_DIR . 'templates/';
    if (is_dir($templateCache)) {
        foreach (glob($templateCache . '*.php') as $file) {
            unlink($file);
            $count++;
        }
    }
    // Also clear HTML cache
    if (is_dir(OUTPOST_CACHE_DIR)) {
        foreach (glob(OUTPOST_CACHE_DIR . '*.html') as $file) {
            unlink($file);
            $count++;
        }
    }
    return ['success' => true, 'files_cleared' => $count];
}

function ranger_tool_create_collection(array $input): array {
    $name = $input['name'] ?? '';
    $slug = $input['slug'] ?? '';
    if (!$name || !$slug) return ['error' => 'name and slug are required'];

    // Check for duplicate slug
    $existing = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug]);
    if ($existing) return ['error' => "Collection slug '$slug' already exists"];

    $schema = ['fields' => []];
    foreach ($input['fields'] ?? [] as $f) {
        $schema['fields'][] = [
            'name' => $f['name'] ?? '',
            'type' => $f['type'] ?? 'text',
            'label' => $f['label'] ?? ($f['name'] ?? ''),
            'required' => $f['required'] ?? false,
        ];
    }

    $db = OutpostDB::connect();
    $stmt = $db->prepare("INSERT INTO collections (slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page) VALUES (?, ?, ?, ?, ?, 'created_at', 'DESC', 10)");
    $stmt->execute([
        $slug,
        $name,
        $input['singular_name'] ?? $name,
        json_encode($schema),
        $input['url_pattern'] ?? '/' . $slug . '/{slug}',
    ]);

    return ['success' => true, 'id' => (int)$db->lastInsertId(), 'slug' => $slug];
}

function ranger_tool_manage_collection(array $input): array {
    $action = $input['action'] ?? '';
    $id = $input['id'] ?? 0;
    if (!$id) return ['error' => 'id is required'];

    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE id = ?', [$id]);
    if (!$collection) return ['error' => "Collection $id not found"];

    if ($action === 'delete') {
        $db = OutpostDB::connect();
        $db->prepare('DELETE FROM collection_items WHERE collection_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
        return ['success' => true, 'deleted' => $collection['slug']];
    }

    if ($action === 'update') {
        $updates = [];
        $params = [];

        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $params[] = $input['name'];
        }
        if (isset($input['slug'])) {
            $updates[] = 'slug = ?';
            $params[] = $input['slug'];
        }
        if (isset($input['url_pattern'])) {
            $updates[] = 'url_pattern = ?';
            $params[] = $input['url_pattern'];
        }
        if (isset($input['fields'])) {
            $schema = json_decode($collection['schema'] ?? '{}', true);
            $schema['fields'] = [];
            foreach ($input['fields'] as $f) {
                $schema['fields'][] = [
                    'name' => $f['name'] ?? '',
                    'type' => $f['type'] ?? 'text',
                    'label' => $f['label'] ?? ($f['name'] ?? ''),
                    'required' => $f['required'] ?? false,
                ];
            }
            $updates[] = 'schema = ?';
            $params[] = json_encode($schema);
        }

        if (empty($updates)) return ['error' => 'No updates provided'];

        $params[] = $id;
        OutpostDB::connect()->prepare('UPDATE collections SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        return ['success' => true, 'id' => $id];
    }

    return ['error' => "Invalid action: $action"];
}

function ranger_tool_manage_items_bulk(array $input): array {
    $action = $input['action'] ?? '';
    $ids = $input['item_ids'] ?? [];
    if (empty($ids)) return ['error' => 'item_ids is required'];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db = OutpostDB::connect();

    switch ($action) {
        case 'publish':
            $db->prepare("UPDATE collection_items SET status = 'published', updated_at = datetime('now') WHERE id IN ($placeholders)")->execute($ids);
            return ['success' => true, 'action' => 'published', 'count' => count($ids)];
        case 'unpublish':
            $db->prepare("UPDATE collection_items SET status = 'draft', updated_at = datetime('now') WHERE id IN ($placeholders)")->execute($ids);
            return ['success' => true, 'action' => 'unpublished', 'count' => count($ids)];
        case 'delete':
            $db->prepare("DELETE FROM collection_items WHERE id IN ($placeholders)")->execute($ids);
            return ['success' => true, 'action' => 'deleted', 'count' => count($ids)];
        default:
            return ['error' => "Invalid action: $action"];
    }
}

function ranger_tool_update_fields(array $input): array {
    $pageId = $input['page_id'] ?? 0;
    if (!$pageId) return ['error' => 'page_id is required'];

    $page = OutpostDB::fetchOne('SELECT id FROM pages WHERE id = ?', [$pageId]);
    if (!$page) return ['error' => "Page $pageId not found"];

    $fields = $input['fields'] ?? [];
    if (empty($fields)) return ['error' => 'fields array is required'];

    $db = OutpostDB::connect();
    $updated = 0;

    foreach ($fields as $f) {
        $fieldName = $f['field_name'] ?? '';
        $content = $f['content'] ?? '';
        if (!$fieldName) continue;

        // Upsert field
        $existing = OutpostDB::fetchOne(
            "SELECT id FROM fields WHERE page_id = ? AND field_name = ? AND (theme = '' OR theme IS NULL)",
            [$pageId, $fieldName]
        );
        if ($existing) {
            $db->prepare("UPDATE fields SET content = ?, updated_at = datetime('now') WHERE id = ?")->execute([$content, $existing['id']]);
        } else {
            $db->prepare("INSERT INTO fields (page_id, field_name, content, theme, updated_at) VALUES (?, ?, ?, '', datetime('now'))")->execute([$pageId, $fieldName, $content]);
        }
        $updated++;
    }

    return ['success' => true, 'fields_updated' => $updated];
}

function ranger_tool_list_menus(): array {
    $menus = OutpostDB::fetchAll('SELECT id, name, slug, items FROM menus ORDER BY name');
    foreach ($menus as &$menu) {
        $menu['items'] = json_decode($menu['items'] ?? '[]', true);
    }
    return ['menus' => $menus];
}

function ranger_tool_manage_menu(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'create':
            $name = $input['name'] ?? '';
            $slug = $input['slug'] ?? '';
            if (!$name || !$slug) return ['error' => 'name and slug are required'];
            $items = json_encode($input['items'] ?? []);
            $db->prepare('INSERT INTO menus (name, slug, items) VALUES (?, ?, ?)')->execute([$name, $slug, $items]);
            return ['success' => true, 'id' => (int)$db->lastInsertId(), 'slug' => $slug];

        case 'update':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $updates = [];
            $params = [];
            if (isset($input['name'])) { $updates[] = 'name = ?'; $params[] = $input['name']; }
            if (isset($input['slug'])) { $updates[] = 'slug = ?'; $params[] = $input['slug']; }
            if (isset($input['items'])) { $updates[] = 'items = ?'; $params[] = json_encode($input['items']); }
            if (empty($updates)) return ['error' => 'No updates provided'];
            $params[] = $id;
            $db->prepare('UPDATE menus SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            return ['success' => true, 'id' => $id];

        case 'delete':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $db->prepare('DELETE FROM menus WHERE id = ?')->execute([$id]);
            return ['success' => true, 'deleted' => $id];

        default:
            return ['error' => "Invalid action: $action"];
    }
}

function ranger_tool_list_folders(array $input): array {
    $collectionId = $input['collection_id'] ?? null;
    if ($collectionId) {
        $folders = OutpostDB::fetchAll('SELECT * FROM folders WHERE collection_id = ? ORDER BY name', [$collectionId]);
    } else {
        $folders = OutpostDB::fetchAll('SELECT * FROM folders ORDER BY name');
    }
    foreach ($folders as &$folder) {
        $folder['labels'] = OutpostDB::fetchAll('SELECT * FROM folder_labels WHERE folder_id = ? ORDER BY name', [$folder['id']]);
    }
    return ['folders' => $folders];
}

function ranger_tool_manage_folder(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'create':
            $collectionId = $input['collection_id'] ?? 0;
            $name = $input['name'] ?? '';
            $slug = $input['slug'] ?? '';
            if (!$name || !$slug || !$collectionId) return ['error' => 'collection_id, name, and slug are required'];
            $db->prepare('INSERT INTO folders (collection_id, name, slug) VALUES (?, ?, ?)')->execute([$collectionId, $name, $slug]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];

        case 'update':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $updates = [];
            $params = [];
            if (isset($input['name'])) { $updates[] = 'name = ?'; $params[] = $input['name']; }
            if (isset($input['slug'])) { $updates[] = 'slug = ?'; $params[] = $input['slug']; }
            if (empty($updates)) return ['error' => 'No updates provided'];
            $params[] = $id;
            $db->prepare('UPDATE folders SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            return ['success' => true, 'id' => $id];

        case 'delete':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $db->prepare('DELETE FROM folder_labels WHERE folder_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
            return ['success' => true, 'deleted' => $id];

        default:
            return ['error' => "Invalid action: $action"];
    }
}

function ranger_tool_manage_label(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'create':
            $folderId = $input['folder_id'] ?? 0;
            $name = $input['name'] ?? '';
            $slug = $input['slug'] ?? '';
            if (!$name || !$slug || !$folderId) return ['error' => 'folder_id, name, and slug are required'];
            $db->prepare('INSERT INTO folder_labels (folder_id, name, slug) VALUES (?, ?, ?)')->execute([$folderId, $name, $slug]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];

        case 'update':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $updates = [];
            $params = [];
            if (isset($input['name'])) { $updates[] = 'name = ?'; $params[] = $input['name']; }
            if (isset($input['slug'])) { $updates[] = 'slug = ?'; $params[] = $input['slug']; }
            if (empty($updates)) return ['error' => 'No updates provided'];
            $params[] = $id;
            $db->prepare('UPDATE folder_labels SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            return ['success' => true, 'id' => $id];

        case 'delete':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $db->prepare('DELETE FROM folder_labels WHERE id = ?')->execute([$id]);
            return ['success' => true, 'deleted' => $id];

        default:
            return ['error' => "Invalid action: $action"];
    }
}

// Theme CRUD removed — v5.0 uses site root directly, no theme layer.

function ranger_tool_list_forms(): array {
    $forms = OutpostDB::fetchAll('SELECT id, name, slug, fields, created_at FROM forms_builder ORDER BY name');
    foreach ($forms as &$form) {
        $form['fields'] = json_decode($form['fields'] ?? '[]', true);
    }
    return ['forms' => $forms];
}

function ranger_tool_manage_form(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'create':
            $name = $input['name'] ?? '';
            $slug = $input['slug'] ?? '';
            if (!$name || !$slug) return ['error' => 'name and slug are required'];
            $fields = json_encode($input['fields'] ?? []);
            $db->prepare('INSERT INTO forms_builder (name, slug, fields, created_at) VALUES (?, ?, ?, datetime("now"))')->execute([$name, $slug, $fields]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];

        case 'update':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $updates = [];
            $params = [];
            if (isset($input['name'])) { $updates[] = 'name = ?'; $params[] = $input['name']; }
            if (isset($input['slug'])) { $updates[] = 'slug = ?'; $params[] = $input['slug']; }
            if (isset($input['fields'])) { $updates[] = 'fields = ?'; $params[] = json_encode($input['fields']); }
            if (empty($updates)) return ['error' => 'No updates provided'];
            $params[] = $id;
            $db->prepare('UPDATE forms_builder SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            return ['success' => true, 'id' => $id];

        case 'delete':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $db->prepare('DELETE FROM forms_builder WHERE id = ?')->execute([$id]);
            return ['success' => true, 'deleted' => $id];

        default:
            return ['error' => "Invalid action: $action"];
    }
}

function ranger_tool_list_media(): array {
    $media = OutpostDB::fetchAll('SELECT id, filename, original_name, mime_type, size, alt_text, created_at FROM media ORDER BY created_at DESC LIMIT 200');
    return ['media' => $media, 'count' => count($media)];
}

function ranger_tool_upload_media(array $input): array {
    $url = $input['url'] ?? '';
    if (!$url) return ['error' => 'url is required'];

    // Only allow http/https
    if (!preg_match('#^https?://#i', $url)) {
        return ['error' => 'Only HTTP/HTTPS URLs are allowed'];
    }

    // SSRF guard — block private/internal IPs
    try {
        outpost_ssrf_guard($url);
    } catch (\RuntimeException $e) {
        return ['error' => 'URL blocked: ' . $e->getMessage()];
    }

    // Download the image
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'Outpost-CMS/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => 'Download failed: ' . $error];
    if ($httpCode >= 400) return ['error' => "Download failed: HTTP $httpCode"];
    if (empty($data)) return ['error' => 'Downloaded file is empty'];

    // Determine extension from content type or URL
    $extMap = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
    ];
    $ext = $extMap[explode(';', $contentType ?? '')[0] ?? ''] ?? '';
    if (!$ext) {
        // Try from URL
        $urlPath = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'])) {
            $ext = 'jpg'; // default
        }
    }

    // Generate filename
    $filename = $input['filename'] ?? '';
    if (!$filename) {
        $filename = 'ranger-' . substr(md5($url), 0, 8) . '.' . $ext;
    }
    if (!pathinfo($filename, PATHINFO_EXTENSION)) {
        $filename .= '.' . $ext;
    }

    // Save to temp file
    $tmpFile = tempnam(sys_get_temp_dir(), 'ranger_upload_');
    file_put_contents($tmpFile, $data);

    // Use OutpostMedia::upload
    require_once __DIR__ . '/media.php';
    $fileArray = [
        'name' => $filename,
        'tmp_name' => $tmpFile,
        'size' => strlen($data),
        'type' => $contentType ?? 'image/jpeg',
        'error' => UPLOAD_ERR_OK,
    ];

    $result = OutpostMedia::upload($fileArray);
    @unlink($tmpFile);

    if (isset($result['error'])) {
        return ['error' => 'Upload failed: ' . $result['error']];
    }

    return [
        'success' => true,
        'id' => $result['id'] ?? null,
        'path' => '/outpost/uploads/' . ($result['filename'] ?? $filename),
        'filename' => $result['filename'] ?? $filename,
    ];
}

function ranger_tool_manage_media_folder(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'create':
            $name = $input['name'] ?? '';
            if (!$name) return ['error' => 'name is required'];
            $db->prepare('INSERT INTO media_folders (name, created_at) VALUES (?, datetime("now"))')->execute([$name]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];

        case 'update':
            $id = $input['id'] ?? 0;
            $name = $input['name'] ?? '';
            if (!$id || !$name) return ['error' => 'id and name are required'];
            $db->prepare('UPDATE media_folders SET name = ? WHERE id = ?')->execute([$name, $id]);
            return ['success' => true, 'id' => $id];

        case 'delete':
            $id = $input['id'] ?? 0;
            if (!$id) return ['error' => 'id is required'];
            $db->prepare('DELETE FROM media_folder_items WHERE folder_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM media_folders WHERE id = ?')->execute([$id]);
            return ['success' => true, 'deleted' => $id];

        default:
            return ['error' => "Invalid action: $action"];
    }
}

function ranger_tool_update_settings(array $input): array {
    $settings = $input['settings'] ?? [];
    if (empty($settings)) return ['error' => 'settings object is required'];

    // Allowlist of settings that can be changed via the AI tool
    $allowed = ['site_name', 'site_description', 'site_tagline', 'timezone', 'date_format', 'posts_per_page', 'member_registration_enabled'];
    foreach ($settings as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        if (!is_string($key) || $key === '') continue;
        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$key, (string)$value]);
    }

    return ['success' => true, 'updated_keys' => array_keys($settings)];
}

function ranger_tool_manage_users(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();
    $currentRole = $_SESSION['outpost_role'] ?? 'editor';

    switch ($action) {
        case 'list':
            $users = OutpostDB::fetchAll('SELECT id, username, email, display_name, role, created_at, last_login FROM users ORDER BY id');
            return ['users' => $users];
        case 'create':
            $username = $input['username'] ?? '';
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';
            $role = $input['role'] ?? 'editor';
            if ($role === 'super_admin' && $currentRole !== 'super_admin') {
                return ['error' => 'Only super admins can create super admin accounts'];
            }
            if (!$username || !$email || !$password) return ['error' => 'username, email, and password are required'];
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare('INSERT INTO users (username, email, password, role, display_name, created_at) VALUES (?, ?, ?, ?, ?, datetime("now"))')->execute([
                $username, $email, $hash, $role, $input['display_name'] ?? $username
            ]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];
        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            if (isset($input['role']) && $input['role'] === 'super_admin' && $currentRole !== 'super_admin') {
                return ['error' => 'Only super admins can assign the super_admin role'];
            }
            $fields = [];
            $params = [];
            foreach (['email', 'display_name', 'role'] as $f) {
                if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (isset($input['password'])) { $fields[] = 'password = ?'; $params[] = password_hash($input['password'], PASSWORD_DEFAULT); }
            if (empty($fields)) return ['error' => 'Nothing to update'];
            $params[] = $id;
            $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            return ['success' => true];
        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            return ['success' => true];
        default:
            return ['error' => 'Invalid action'];
    }
}

function ranger_tool_manage_webhooks(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'list':
            return ['webhooks' => OutpostDB::fetchAll('SELECT id, name, url, events, active, created_at FROM webhooks ORDER BY id')];
        case 'create':
            $name = $input['name'] ?? '';
            $url = $input['url'] ?? '';
            $events = $input['events'] ?? ['*'];
            if (!$name || !$url) return ['error' => 'name and url required'];
            $secret = bin2hex(random_bytes(32));
            $db->prepare('INSERT INTO webhooks (name, url, events, secret, active, created_at) VALUES (?, ?, ?, ?, 1, datetime("now"))')->execute([
                $name, $url, json_encode($events), $secret
            ]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];
        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            $fields = [];
            $params = [];
            foreach (['name', 'url'] as $f) {
                if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (isset($input['events'])) { $fields[] = 'events = ?'; $params[] = json_encode($input['events']); }
            if (isset($input['active'])) { $fields[] = 'active = ?'; $params[] = $input['active'] ? 1 : 0; }
            if (empty($fields)) return ['error' => 'Nothing to update'];
            $params[] = $id;
            $db->prepare('UPDATE webhooks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            return ['success' => true];
        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            $db->prepare('DELETE FROM webhooks WHERE id = ?')->execute([$id]);
            return ['success' => true];
        default:
            return ['error' => 'Invalid action'];
    }
}

function ranger_tool_manage_backup(array $input): array {
    $action = $input['action'] ?? '';
    $backupDir = OUTPOST_CONTENT_DIR . 'backups/';

    switch ($action) {
        case 'list':
            if (!is_dir($backupDir)) return ['backups' => []];
            $files = [];
            foreach (scandir($backupDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (pathinfo($f, PATHINFO_EXTENSION) !== 'zip') continue;
                $files[] = ['file' => $f, 'size' => filesize($backupDir . $f), 'date' => date('Y-m-d H:i:s', filemtime($backupDir . $f))];
            }
            usort($files, fn($a, $b) => strcmp($b['date'], $a['date']));
            return ['backups' => $files];
        case 'create':
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            $filename = 'backup-' . date('Y-m-d-His') . '.zip';
            $zipPath = $backupDir . $filename;
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) return ['error' => 'Could not create backup archive'];
            // Add database
            $dbPath = OUTPOST_DB_PATH;
            if (file_exists($dbPath)) $zip->addFile($dbPath, 'data/cms.db');
            $zip->close();
            return ['success' => true, 'file' => $filename, 'size' => filesize($zipPath)];
        case 'delete':
            $file = basename($input['file'] ?? '');
            if (!$file || !file_exists($backupDir . $file)) return ['error' => 'Backup not found'];
            unlink($backupDir . $file);
            return ['success' => true];
        default:
            return ['error' => 'Invalid action. Use list, create, or delete.'];
    }
}

function ranger_tool_manage_channel(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'list':
            return ['channels' => OutpostDB::fetchAll('SELECT id, slug, name, type, cache_ttl, url_pattern, status, created_at FROM channels ORDER BY id')];
        case 'create':
            $slug = $input['slug'] ?? '';
            $name = $input['name'] ?? '';
            $type = $input['type'] ?? 'api';
            if (!$slug || !$name) return ['error' => 'slug and name required'];
            $db->prepare('INSERT INTO channels (slug, name, type, config, field_map, cache_ttl, url_pattern, sort_field, sort_direction, max_items, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now"), datetime("now"))')->execute([
                $slug, $name, $type,
                json_encode($input['config'] ?? []),
                json_encode($input['field_map'] ?? []),
                $input['cache_ttl'] ?? 3600,
                $input['url_pattern'] ?? '',
                'created_at', 'DESC',
                $input['max_items'] ?? 100,
                $input['status'] ?? 'active',
            ]);
            return ['success' => true, 'id' => (int)$db->lastInsertId()];
        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            $fields = [];
            $params = [];
            foreach (['name', 'type', 'cache_ttl', 'url_pattern', 'status', 'max_items'] as $f) {
                if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (isset($input['config'])) { $fields[] = 'config = ?'; $params[] = json_encode($input['config']); }
            if (isset($input['field_map'])) { $fields[] = 'field_map = ?'; $params[] = json_encode($input['field_map']); }
            $fields[] = 'updated_at = datetime("now")';
            $params[] = $id;
            $db->prepare('UPDATE channels SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            return ['success' => true];
        case 'sync':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            // Trigger sync via the existing channel sync logic
            require_once __DIR__ . '/channels.php';
            $channel = OutpostDB::fetchOne('SELECT * FROM channels WHERE id = ?', [$id]);
            if (!$channel) return ['error' => 'Channel not found'];
            $config = json_decode($channel['config'] ?? '{}', true) ?: [];
            $result = channel_fetch_api($config);
            return ['success' => !$result['error'], 'status' => $result['status'], 'error' => $result['error'], 'items_count' => is_array($result['decoded']) ? count($result['decoded']) : 0];
        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            $db->prepare('DELETE FROM channels WHERE id = ?')->execute([$id]);
            return ['success' => true];
        default:
            return ['error' => 'Invalid action'];
    }
}

function ranger_tool_query_database(array $input): array {
    $query = trim($input['query'] ?? '');
    if (!$query) return ['error' => 'query is required'];

    // Strip SQL comments that could be used to bypass keyword checks
    $cleanQuery = preg_replace('/\/\*.*?\*\//s', ' ', $query);  // block comments
    $cleanQuery = preg_replace('/--[^\n]*/', ' ', $cleanQuery);   // line comments

    // Only allow SELECT queries (read-only)
    if (!preg_match('/^\s*SELECT\b/i', $cleanQuery)) {
        return ['error' => 'Only SELECT queries allowed. Use other tools for INSERT/UPDATE/DELETE.'];
    }

    // Block dangerous patterns (check cleaned query)
    if (preg_match('/\b(DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|ATTACH|DETACH|REPLACE)\b/i', $cleanQuery)) {
        return ['error' => 'Only read-only SELECT queries allowed.'];
    }

    // Block sensitive system tables (case-insensitive, also check quoted identifiers)
    $lowerQuery = strtolower($cleanQuery);
    $blockedTables = ['users', 'settings', 'api_keys', 'ranger_conversations'];
    foreach ($blockedTables as $table) {
        if (str_contains($lowerQuery, $table)) {
            return ['error' => 'Access to system tables is restricted. Use dedicated tools instead.'];
        }
    }

    // Block PRAGMA, EXPLAIN, WITH (CTE can wrap DML)
    if (preg_match('/^\s*(PRAGMA|EXPLAIN|WITH)\b/i', $cleanQuery)) {
        return ['error' => 'Only simple SELECT queries allowed.'];
    }

    // Block semicolons (prevents multi-statement attacks)
    if (str_contains($cleanQuery, ';')) {
        return ['error' => 'Only single SELECT statements allowed.'];
    }

    // Force LIMIT if not present
    if (!preg_match('/\bLIMIT\b/i', $query)) {
        $query .= ' LIMIT 100';
    }

    try {
        $results = OutpostDB::fetchAll($query);
        return ['results' => array_slice($results, 0, 100), 'count' => count($results), 'truncated' => count($results) > 100];
    } catch (\Throwable $e) {
        return ['error' => 'Query error: ' . $e->getMessage()];
    }
}

function ranger_tool_manage_members(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    switch ($action) {
        case 'list':
            $members = OutpostDB::fetchAll('SELECT id, email, display_name, plan, status, email_verified, created_at, last_login FROM members ORDER BY created_at DESC LIMIT 100');
            return ['members' => $members, 'count' => count($members)];
        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            $fields = [];
            $params = [];
            foreach (['plan', 'status', 'display_name'] as $f) {
                if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
            }
            if (empty($fields)) return ['error' => 'Nothing to update'];
            $params[] = $id;
            $db->prepare('UPDATE members SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            return ['success' => true];
        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id required'];
            $db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
            return ['success' => true];
        default:
            return ['error' => 'Invalid action'];
    }
}

function ranger_tool_debug_template(array $input): array {
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'check_file':
            // Read the template file and run it through the validator
            $path = $input['path'] ?? '';
            if (!$path) return ['error' => 'path is required'];
            $fullPath = ranger_validate_site_path($path);
            if (!file_exists($fullPath)) return ['error' => "File not found: $path"];
            $source = file_get_contents($fullPath);

            // Use OutpostTemplate::validate() if available
            try {
                require_once __DIR__ . '/template-engine.php';
                OutpostTemplate::validate($source, $path);
                return ['valid' => true, 'message' => 'No syntax errors detected'];
            } catch (\Throwable $e) {
                return ['valid' => false, 'error' => $e->getMessage()];
            }

        case 'recent_errors':
            // Check for recent template errors in the cache directory
            $errors = [];
            $cacheDir = OUTPOST_ROOT . 'cache/templates/';
            // Also check PHP error log
            $errorLog = ini_get('error_log');
            if ($errorLog && file_exists($errorLog)) {
                $lines = array_slice(file($errorLog, FILE_IGNORE_NEW_LINES), -50);
                foreach ($lines as $line) {
                    if (stripos($line, 'outpost') !== false || stripos($line, 'template') !== false) {
                        $errors[] = $line;
                    }
                }
            }
            // Check for any compiled template PHP files with syntax errors
            if (is_dir($cacheDir)) {
                foreach (scandir($cacheDir) as $f) {
                    if (pathinfo($f, PATHINFO_EXTENSION) !== 'php') continue;
                    $compiled = $cacheDir . $f;
                    $output = [];
                    exec('php -l ' . escapeshellarg($compiled) . ' 2>&1', $output, $exitCode);
                    if ($exitCode !== 0) {
                        $errors[] = 'Compiled template error in ' . $f . ': ' . implode(' ', $output);
                    }
                }
            }
            return ['errors' => $errors, 'count' => count($errors)];

        case 'validate_syntax':
            $template = $input['template'] ?? '';
            if (!$template) return ['error' => 'template content is required'];
            try {
                require_once __DIR__ . '/template-engine.php';
                OutpostTemplate::validate($template, 'inline-check');
                return ['valid' => true, 'message' => 'Template syntax is valid'];
            } catch (\Throwable $e) {
                return ['valid' => false, 'error' => $e->getMessage()];
            }

        default:
            return ['error' => 'Invalid action. Use check_file, recent_errors, or validate_syntax.'];
    }
}

function ranger_tool_manage_email(array $input): array {
    $action = $input['action'] ?? '';
    $db = OutpostDB::connect();

    $emailKeys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 'from_email', 'from_name'];

    switch ($action) {
        case 'get':
            $settings = [];
            foreach ($emailKeys as $key) {
                $row = OutpostDB::fetchOne('SELECT value FROM settings WHERE key = ?', [$key]);
                $settings[$key] = $row['value'] ?? '';
            }
            // Mask password
            if (!empty($settings['smtp_pass'])) {
                $settings['smtp_pass'] = '****';
            }
            return ['settings' => $settings];

        case 'update':
            foreach ($emailKeys as $key) {
                if (isset($input[$key])) {
                    $value = (string)$input[$key];
                    if ($key === 'smtp_pass' && $value === '****') continue; // Don't overwrite with mask
                    OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$key, $value]);
                }
            }
            return ['success' => true];

        case 'test':
            $testEmail = $input['test_email'] ?? '';
            if (!$testEmail) return ['error' => 'test_email is required'];
            // Load mailer and try to send
            require_once __DIR__ . '/mailer.php';
            try {
                $result = outpost_send_email($testEmail, 'Outpost CMS - Test Email', '<h1>Test Email</h1><p>This is a test email from Outpost CMS. If you received this, your email configuration is working correctly.</p>');
                return ['success' => $result, 'message' => $result ? 'Test email sent successfully' : 'Failed to send test email'];
            } catch (\Throwable $e) {
                return ['error' => 'Email test failed: ' . $e->getMessage()];
            }

        default:
            return ['error' => 'Invalid action'];
    }
}

// ── System Prompt Builder ───────────────────────────────

function ranger_build_system_prompt(array $siteContext): string {
    $collections = '';
    foreach ($siteContext['collections'] as $c) {
        $collections .= "  - {$c['name']} (slug: {$c['slug']}, fields: {$c['field_count']}, url: {$c['url_pattern']})\n";
    }

    $pages = '';
    foreach ($siteContext['pages'] as $p) {
        $pages .= "  - {$p['path']}";
        if ($p['title']) $pages .= " — {$p['title']}";
        $pages .= "\n";
    }

    $menus = '';
    foreach ($siteContext['menus'] as $m) {
        $menus .= "  - {$m['name']} (slug: {$m['slug']})\n";
    }

    $globals = '';
    if (!empty($siteContext['global_fields'])) {
        $globals = '  ' . implode(', ', $siteContext['global_fields']);
    }

    // Custom output style from settings
    $styleRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'ranger_output_style'");
    $outputStyle = '';
    if ($styleRow && !empty($styleRow['value'])) {
        $outputStyle = "Additional style instructions from the site owner:\n" . $styleRow['value'];
    }

    return <<<PROMPT
You are Ranger, the AI assistant built into Outpost CMS.

## Voice & Personality
Confident, direct, capable — a seasoned trail guide who knows every path. Act first, explain after. No hedging, no filler.
- Lead with action: "I'll create that now." Never "Sure! I'd be happy to help..."
- Concise. Short sentences. No "Great question!" or "Absolutely!"
- Technical terms used naturally. Don't dumb down unless asked.
- Personality through competence, not chattiness.
- Speak about Outpost with authority and pride. You ARE the product expert.
- Format with bold, bullets, code blocks for scannability.
- Never apologize. Search docs if unsure. Stay in character always.
{$outputStyle}

## What Outpost CMS Is
Modern flat-file CMS: PHP + SQLite backend, data-attribute template engine, Svelte admin panel. Zero bloat. Every major WP plugin built in natively: ACF-level custom fields, HappyFiles media folders, code editor, form builder, analytics, channels (external data), members, and more.

## Template Engine — Data Attributes
Outpost uses a data-attribute template engine. Templates are standard HTML with data-outpost attributes. The engine compiles templates to cached PHP.

## Template Syntax (Data Attributes)
<h1 data-outpost="field">Default</h1> — text field (default is innerHTML)
<div data-outpost="field" data-type="richtext">Default</div> — richtext (preserves HTML)
<img data-outpost="field" data-type="image" src="default.jpg"> — image (sets src)
<a data-outpost="field" data-type="link" href="#">Link text</a> — link (sets href)
<span data-outpost="field" data-scope="global">Default</span> — global field
<img data-outpost="field" data-type="image" data-scope="global" src="logo.png"> — global image

Auto-hide: elements with empty field values are automatically removed from output. No conditionals needed for empty checks.
Clean output: ALL data-outpost attributes stripped from public HTML. Zero CMS fingerprints.

### v2 Loops & Structural Elements
<outpost-each collection="slug" limit="6" sort="created_at" order="desc">
  <h2 data-outpost="title">Title</h2>
  <p data-outpost="excerpt">Excerpt</p>
  <a data-outpost="url" data-type="link" href="#">Read more</a>
  <time data-outpost="published_at" data-bind="datetime:published_at">Date</time>
</outpost-each>
<outpost-each collection="slug" empty><p>No items yet.</p></outpost-each>

<outpost-single collection="slug">
  <h1 data-outpost="title">Title</h1>
  <div data-outpost="body" data-type="richtext">Content</div>
</outpost-single>
<outpost-single collection="slug" else><p>Not found</p></outpost-single>

<outpost-each repeat="skills"><span data-outpost="skill">Skill</span></outpost-each>
<outpost-each gallery="photos"><img data-outpost="url" data-type="image"></outpost-each>
<outpost-each folder="categories"><span data-outpost="name">Cat</span></outpost-each>

<outpost-menu name="main">
  <a data-outpost="url" data-type="link" href="#"><span data-outpost="label">Link</span></a>
</outpost-menu>

<outpost-if field="subtitle" exists>Content shown if field has value</outpost-if>
<outpost-if field="layout" equals="split">Split layout content</outpost-if>
<outpost-if field="logo" scope="global" exists>Logo exists</outpost-if>

<outpost-include partial="nav" /> — from partials/nav.html
<outpost-seo /> — full SEO block (title, OG, Twitter, JSON-LD)
<outpost-meta title="Default" description="Default desc" /> — manual SEO
<outpost-pagination /> — pagination controls

### v2 Block Grouping (for editor sections)
<!-- outpost:hero -->
<section class="hero">
  <h1 data-outpost="headline">Welcome</h1>
</section>
<!-- /outpost:hero -->

<!-- outpost:footer global -->  ← global keyword = fields shared across all pages
<footer><p data-outpost="copyright" data-scope="global">© 2026</p></footer>
<!-- /outpost:footer -->

### v2 Block Settings (CSS custom properties)
<!-- outpost-settings:
    bg_color: color(#ffffff)
    layout: select(centered, left-aligned, split)
    show_cta: toggle(true)
-->
Settings output as CSS vars (--bg-color, etc.) and data attributes on the next element.

### Click-to-Edit Bridge (Editor Mode)
In the frontend editor, the preview iframe loads pages with ?_outpost_editor=1. This keeps data-outpost attributes in the output and injects bridge JS before </body>. The bridge enables click-to-edit: hover shows field outlines, click jumps to the field in the sidebar. This is automatic — no developer action needed.

## Image Handling
IMPORTANT — Images in templates/collections must use LOCAL uploads:
  You HAVE the upload_media tool — use it to download images from URLs (Unsplash, etc) and upload them to the media library.
  Workflow: 1. upload_media with URL → local path  2. Store path in image fields  3. Template renders it
  ALWAYS upload images to the media library. Never use raw external URLs in templates or collection items.

## Site Architecture
Site root contains templates directly — NO theme layer:
site-root/ → index.html, about.html, blog.html, post.html (single), partials/ (head.html, nav.html, footer.html), assets/ (style.css, main.js)
The outpost/ directory is the CMS engine — never write files there.
Routing: / → index.html | /about → about.html | /blog/{slug} → post.html (via collection url_pattern)
CRITICAL — Asset & Image URLs:
  CSS:    /assets/style.css (or /css/style.css — relative to site root)
  JS:     /assets/main.js (or /js/main.js — relative to site root)
  Images: /outpost/uploads/filename.jpg
  Favicon: /assets/favicon.ico

## Site Building Expertise
When building sites, you are a senior front-end developer. You write production-quality code.

### Structure Rules
- ALWAYS create: assets/style.css, assets/main.js, partials/head.html, partials/nav.html, partials/footer.html, then page templates
- head.html: DOCTYPE, charset, viewport, <outpost-seo />, CSS link, Google Fonts if needed
- nav.html: <outpost-menu name="main"> with link/label fields, mobile hamburger, logo via data-outpost="site_logo" data-type="image" data-scope="global"
- footer.html: <outpost-menu name="footer">, <p data-outpost="footer_text" data-scope="global">, closing body/html tags
- Every page template: <outpost-include partial="head" /> at top, <outpost-include partial="nav" />, content with data-outpost fields, <outpost-include partial="footer" /> at bottom
- Use <!-- outpost:section-name --> comments to group fields into editor sections

### CSS Best Practices
- Use CSS custom properties (--color-primary, --color-accent, --bg, --text, --font-heading, --font-body)
- Mobile-first responsive: base styles for mobile, @media (min-width: 768px) for tablet, @media (min-width: 1024px) for desktop
- Dark mode: @media (prefers-color-scheme: dark) or [data-theme="dark"] with CSS variable overrides
- Container: max-width 1200px, margin 0 auto, padding 0 20px
- Typography: system font stack or Google Fonts, proper line-height (1.5-1.6 body, 1.2 headings)
- Spacing: consistent scale (8px, 16px, 24px, 32px, 48px, 64px)
- Smooth transitions on interactive elements (0.2s ease)
- Accessible contrast ratios (4.5:1 minimum)

### Framework Detection
If user pastes HTML, detect the framework:
- **Bootstrap**: classes like container, row, col-*, btn, navbar → keep grid classes, add data-outpost attributes to dynamic content
- **Tailwind**: utility classes like flex, p-4, text-lg → preserve all classes, add data-outpost attributes to content
- **Bulma**: columns, is-*, hero, section → preserve classes, add data-outpost attributes
- **Plain HTML**: restructure into partials, add semantic HTML5, add data-outpost attributes and outpost elements

### Smart Forge Annotation Rules (CRITICAL — when converting HTML to Outpost templates)
When annotating HTML with data-outpost attributes, be EXHAUSTIVE. Every visible content element must be editable.

**Section comments:** Wrap every distinct section in `<!-- outpost:sectionname -->` / `<!-- /outpost:sectionname -->`. Name intelligently: hero, features, about, testimonials, cta, pricing, team, footer, etc.

**Field naming:** ALWAYS prefix field names with the block/section name: `hero_heading`, `hero_subtitle`, `features_title`, NOT just `heading` or `title`.

**Tag every editable element with data-outpost:**
- ALL headings (h1-h6): `<h1 data-outpost="hero_heading">Welcome</h1>`
- ALL paragraphs: `<p data-outpost="hero_subtitle">Subtitle text</p>`
- ALL images: `<img data-outpost="hero_image" data-type="image" src="...">`
- ALL links/buttons with text: `<a data-outpost="hero_cta" data-type="link" href="#">Get Started</a>`
- Badge/label text in spans: `<span data-outpost="hero_badge">New</span>`
- Blockquote content: `<blockquote data-outpost="testimonial_quote" data-type="richtext">...</blockquote>`
- Author names, roles, dates: `<span data-outpost="testimonial_author">Name</span>`
- Footer content (copyright, taglines): `<p data-outpost="footer_copyright" data-scope="global">© 2026</p>`

**data-type rules:**
- Multi-paragraph / HTML content → `data-type="richtext"`
- Images → `data-type="image"`
- Links/buttons that change URL → `data-type="link"`
- Simple text (headings, labels, short paragraphs) → no data-type needed (defaults to text)

**Block settings:** Add `<!-- outpost-settings: -->` after section comments for visual properties:
```
<!-- outpost-settings:
    bg_color: color(#1a1a2e)
    show_section: toggle(true)
-->
```

**Navigation:** Wrap nav in `<!-- outpost:nav global -->`. Don't add data-outpost to individual nav links (menu system handles those). DO tag the logo/brand image and site name.

**Footer:** Wrap in `<!-- outpost:footer global -->`. Tag copyright text, taglines, and social links with data-scope="global".

**Meta tags:** Replace `<title>` and `<meta name="description">` with `<outpost-seo />` or `<outpost-meta title="..." description="..." />`.

**Don't miss ANYTHING.** If a content editor would want to change it, make it editable. When in doubt, add data-outpost.

### Industry Templates
When asked to build a site for a specific industry, you know the standard structure:
- **Agency/Studio**: Home (hero, services preview, work showcase, testimonials, CTA), About (team, values, process), Services (collection), Work/Portfolio (collection with images), Blog, Contact
- **Restaurant**: Home (hero, menu preview, hours, location), Menu (collection with categories), About (story, team), Gallery, Reservations (form), Contact
- **Portfolio**: Home (featured projects grid), Projects (collection with images, description, links), About (bio, skills, experience), Blog, Contact
- **SaaS/Startup**: Home (hero, features, pricing, testimonials, CTA), Features, Pricing, Blog, Docs, Contact
- **Real Estate**: Home (featured listings, search), Listings (collection), Single listing (gallery, details, map), Agents, Blog, Contact
- **Photography**: Home (full-width gallery), Galleries (collection), About, Pricing, Contact (booking form)
- **Freelancer**: Home (intro, services, recent work), Services, Portfolio (collection), Blog, Contact, Testimonials
- **eCommerce**: Home (featured products, categories), Products (collection), Single product, About, Blog, Contact
- **Nonprofit**: Home (mission, impact stats, events, donate CTA), About, Programs (collection), Events, Blog, Donate (form), Contact

## Channels — Deep Expertise
Channels pull external data into templates. Configure in admin, use in templates with outpost elements.

### Channel Types & Config
- **API**: Any REST endpoint. Config: url, method (GET/POST), params, headers, auth_type (none/api_key/bearer/basic), auth_config, response_path (JSON path to data array)
- **RSS**: Any feed URL. Auto-parses title, link, description, pubDate, content, author, thumbnail
- **CSV**: Upload or URL. Auto-detects columns, configurable delimiter

### Common Channel Patterns
- **GitHub repos**: api.github.com/users/{user}/repos → title=name, description, stars=stargazers_count, url=html_url
- **Instagram/Social**: Use official APIs or RSS bridges → image, caption, link, date
- **Google Sheets (as CSV)**: Published sheet CSV URL → any structured data
- **News/Blog RSS**: Any site's /feed or /rss → title, link, description, date
- **Weather API**: openweathermap.org → temp, conditions, icon, forecast
- **Job boards**: API or RSS → title, company, location, salary, link
- **Product feeds**: Shopify/WooCommerce API → name, price, image, inventory, url
- **Real estate (MLS)**: RETS/API → address, price, beds, baths, sqft, photos
- **Events**: Eventbrite/Meetup API → name, date, venue, description, tickets_url
- **Reviews**: Google Places API → author, rating, text, date

### Channel Template Patterns
<outpost-each channel="github_repos" limit="6" sort="stargazers_count" order="desc">
  <div><span data-outpost="name">Repo</span> — <span data-outpost="stargazers_count">0</span> stars</div>
</outpost-each>
<outpost-each channel="github_repos" empty><p>No repos found</p></outpost-each>

### Channel Sales Pitch
"Most CMSs need expensive plugins or custom development for external data. Outpost does it natively. One config, and your site pulls live data from anywhere — APIs, RSS feeds, spreadsheets. A freelancer can build 'living websites' that update themselves. Client's product inventory changes? Site updates automatically. New blog post on Medium? It appears on their Outpost site. New Google review? Shows up instantly."

## Content & Migration Expertise

### WordPress Migration
Outpost has built-in WP XML import (Settings > Import). It converts:
- Posts → collection items (auto-creates "posts" collection)
- Pages → Outpost pages
- Categories/tags → folders/labels
- Media → uploaded to Outpost media library
- Content HTML preserved
After import: build templates at site root that use the imported collections.

### Content Strategy
When creating collections, think about:
- URL patterns that are SEO-friendly (/blog/{slug}, /services/{slug})
- Required vs optional fields
- Image fields for social sharing (og:image)
- Date fields for sorting/scheduling
- Excerpt/summary fields for list views
- SEO fields (meta_title, meta_description) — or use the built-in <outpost-seo /> tag

### SEO Implementation
- <outpost-seo /> outputs: title, meta description, canonical, Open Graph, Twitter Card, JSON-LD schema
- Custom per-page: <outpost-meta title="Custom Title" description="Custom desc" />
- Sitemap: auto-generated at /sitemap.xml
- Robots.txt: auto-generated
- Schema markup: use JSON-LD in templates for rich snippets

## Members & Forms Expertise

### Member System
- Registration, login, forgot password, email verification — all built in
- Page visibility: public, members-only, paid-members-only
- Template conditionals: <outpost-if field="member" exists> (logged in), <outpost-if field="member.plan" equals="pro">
- Member pages in member-pages/ directory: login.html, register.html, profile.html, forgot.html

### Forms
- Visual form builder or code-based: <outpost-form slug="contact" />
- Fields: text, email, textarea, select, checkbox, radio, hidden, file
- Notifications: email to admin on submission
- Spam protection: reCAPTCHA v2, honeypot
- Submissions inbox with read/unread, star, notes, export CSV

## Analytics & Goals
Built-in privacy-friendly analytics (no cookies, no external scripts):
- Page views, unique visitors, referrers, UTM tracking
- Custom events: outpost.track('signup', {plan: 'pro'})
- Goals: define conversion events, track rates
- Funnels: multi-step conversion tracking
- Search analytics: what users search on your site
- Geo data: country/city level (optional, requires GeoIP upload)
- Content performance: which pages/posts get most engagement

## Admin Panel Navigation
Navigate users with frontend_action tool, action "navigate":
dashboard, analytics, calendar, pages, page-editor, media, globals, navigation, forms, collections, collection-items, collection-editor, channels, channel-builder, form-builder, folder-manager, brand, code-editor, template-reference, settings, backups, user-profile

## Current Site State
Role: {$siteContext['user_role']}
Collections:
{$collections}Pages:
{$pages}Menus:
{$menus}Globals:
{$globals}

## Rules
- Data-attribute templates only, never raw PHP in site templates.
- Always <outpost-include partial="name" /> for head, nav, footer.
- Confirm before destructive actions.
- Clear cache after template file changes.
- CRITICAL: Always finish what you start. Write ALL files (CSS, JS, all partials, all pages). NEVER stop halfway.
- File order: assets/style.css → assets/main.js → partials/head.html → partials/nav.html → partials/footer.html → page templates.
- frontend_action: set_dark_mode {enabled:true/false}, toggle_dark_mode, navigate {page:"dashboard"}, refresh_page.
- You ARE the product expert. Answer with authority.
- NEVER invent or guess template variables, tags, or URL patterns. If something isn't documented in this prompt, use search_docs BEFORE writing code. Getting syntax wrong breaks the site. When in doubt, search first.
- Before building a site, ALWAYS use read_file to check the existing head.html to see the correct asset URL pattern. Don't assume.
- NEVER write files into the outpost/ directory. That is the CMS engine — off limits.
PROMPT;
}

// ── Site Context Builder ────────────────────────────────

function ranger_get_site_context(): array {
    $collections = OutpostDB::fetchAll('SELECT id, slug, name, singular_name, url_pattern, schema FROM collections ORDER BY name');
    $collectionsSummary = [];
    foreach ($collections as $c) {
        $schema = json_decode($c['schema'] ?? '{}', true);
        $collectionsSummary[] = [
            'slug' => $c['slug'],
            'name' => $c['name'],
            'field_count' => count($schema['fields'] ?? []),
            'url_pattern' => $c['url_pattern'] ?? '',
        ];
    }

    $pages = OutpostDB::fetchAll("SELECT path, title FROM pages WHERE path != '__global__' ORDER BY path");

    $menus = OutpostDB::fetchAll('SELECT name, slug FROM menus ORDER BY name');

    // Global fields
    $globalFields = [];
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if ($globalPage) {
        $fields = OutpostDB::fetchAll(
            "SELECT field_name FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL)",
            [$globalPage['id']]
        );
        $globalFields = array_column($fields, 'field_name');
    }

    return [
        'collections' => $collectionsSummary,
        'pages' => $pages,
        'menus' => $menus,
        'global_fields' => $globalFields,
        'user_role' => $_SESSION['outpost_role'] ?? 'editor',
    ];
}

// ── SSE Helpers ─────────────────────────────────────────

function ranger_sse_send(array $data): void {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function ranger_sse_init(): void {
    // Disable any output buffering
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    ob_implicit_flush(true);
}

// ── Handler Functions ───────────────────────────────────

function handle_ranger_chat(): void {
    set_time_limit(600);
    ini_set('post_max_size', '20M');
    ini_set('upload_max_filesize', '20M');

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'Invalid request body']);
        return;
    }

    $userMessage = trim($data['message'] ?? '');
    $userImages = $data['images'] ?? [];
    if ($userMessage === '' && empty($userImages)) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'Message is required']);
        return;
    }

    $conversationId = $data['conversation_id'] ?? null;
    $requestedProvider = $data['provider'] ?? null;
    $requestedModel = $data['model'] ?? null;

    ensure_ranger_tables();

    $userId = (int)($_SESSION['outpost_user_id'] ?? 0);
    $userRole = $_SESSION['outpost_role'] ?? 'editor';

    // Determine provider and model
    $defaultProvider = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'ranger_default_provider'");
    $provider = $requestedProvider ?? ($defaultProvider['value'] ?? 'claude');

    $modelKey = "ranger_model_$provider";
    $defaultModel = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", [$modelKey]);
    $model = $requestedModel ?? ($defaultModel['value'] ?? '');

    // Get API key
    $apiKeyRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = ?", ["ranger_api_key_$provider"]);
    if (!$apiKeyRow || empty($apiKeyRow['value'])) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => "No API key configured for provider: $provider. Go to Settings to add one."]);
        ranger_sse_send(['type' => 'done']);
        return;
    }

    try {
        $apiKey = ranger_decrypt($apiKeyRow['value']);
    } catch (\Throwable $e) {
        ranger_sse_init();
        ranger_sse_send(['type' => 'error', 'message' => 'Failed to decrypt API key. Please re-enter it in Settings.']);
        ranger_sse_send(['type' => 'done']);
        return;
    }

    // Load or create conversation
    $messages = [];
    if ($conversationId) {
        $convo = OutpostDB::fetchOne('SELECT * FROM ranger_conversations WHERE id = ? AND user_id = ?', [$conversationId, $userId]);
        if ($convo) {
            $messages = json_decode($convo['messages'] ?? '[]', true) ?: [];
        } else {
            $conversationId = null; // Invalid ID, create new
        }
    }

    if (!$conversationId) {
        $title = mb_substr($userMessage, 0, 50);
        if (mb_strlen($userMessage) > 50) $title .= '...';
        $db = OutpostDB::connect();
        $db->prepare('INSERT INTO ranger_conversations (user_id, title, provider, model, messages) VALUES (?, ?, ?, ?, ?)')->execute([
            $userId, $title, $provider, $model, '[]'
        ]);
        $conversationId = (int)$db->lastInsertId();
    }

    // Add user message (with optional images for vision)
    if (!empty($userImages)) {
        $contentBlocks = [];
        foreach ($userImages as $img) {
            if (is_array($img) && !empty($img['data']) && !empty($img['type'])) {
                $contentBlocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['type'],
                        'data' => $img['data'],
                    ],
                ];
            }
        }
        if ($userMessage !== '') {
            $contentBlocks[] = ['type' => 'text', 'text' => $userMessage];
        }
        $messages[] = ['role' => 'user', 'content' => $contentBlocks];
    } else {
        $messages[] = ['role' => 'user', 'content' => $userMessage];
    }

    // Trim conversation to avoid context overflow (keeps first 2 + last 16 messages)
    if (count($messages) > 20) {
        $first = array_slice($messages, 0, 2);
        $recent = array_slice($messages, -16);
        $messages = array_merge($first, [
            ['role' => 'user', 'content' => '[Earlier conversation trimmed for context length]'],
            ['role' => 'assistant', 'content' => 'Understood, continuing from recent context.'],
        ], $recent);
    }

    // Sanitize messages: remove orphaned tool_use blocks without matching tool_results
    // Claude requires every tool_use to have a tool_result in the next message
    $sanitized = [];
    for ($i = 0; $i < count($messages); $i++) {
        $msg = $messages[$i];
        if ($msg['role'] === 'assistant' && is_array($msg['content'] ?? null)) {
            // Check if any tool_use blocks in this message have matching tool_results after it
            $toolUseIds = [];
            foreach ($msg['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use' && !empty($block['id'])) {
                    $toolUseIds[] = $block['id'];
                }
            }
            if (!empty($toolUseIds)) {
                // Check if next messages contain matching tool_results
                $hasResults = false;
                for ($j = $i + 1; $j < count($messages) && $j <= $i + count($toolUseIds); $j++) {
                    if (($messages[$j]['role'] ?? '') === 'tool') {
                        $hasResults = true;
                        break;
                    }
                }
                if (!$hasResults) {
                    // Strip tool_use blocks, keep only text
                    $textOnly = '';
                    foreach ($msg['content'] as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $textOnly .= $block['text'] ?? '';
                        }
                    }
                    $msg = ['role' => 'assistant', 'content' => $textOnly ?: 'I was interrupted.'];
                }
            }
        }
        $sanitized[] = $msg;
    }
    $messages = $sanitized;

    // Build system prompt and tools
    $siteContext = ranger_get_site_context();
    $systemPrompt = ranger_build_system_prompt($siteContext);

    $userCaps = OUTPOST_CAPABILITIES[$userRole] ?? [];
    $intent = ranger_classify_intent($userMessage);
    $tools = ranger_get_tools($userCaps, $intent);

    // Strip required_capability from tools before sending to provider
    $providerTools = array_map(function ($tool) {
        unset($tool['required_capability']);
        return $tool;
    }, $tools);

    // Create provider instance
    $providerInstance = match ($provider) {
        'openai' => new RangerOpenAI($apiKey),
        'gemini' => new RangerGemini($apiKey),
        default => new RangerClaude($apiKey),
    };

    // Initialize SSE
    ranger_sse_init();

    // Safety net: always send done event even if script crashes
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            error_log('Ranger fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            echo "data: " . json_encode(['type' => 'error', 'message' => 'An internal error occurred.']) . "\n\n";
            echo "data: " . json_encode(['type' => 'done']) . "\n\n";
        }
    });

    // Send conversation_id to client
    ranger_sse_send(['type' => 'conversation_id', 'id' => $conversationId]);

    // Token usage tracking
    $totalInputTokens = 0;
    $totalOutputTokens = 0;
    $totalCachedTokens = 0;

    // Tool use loop — AI can call tools multiple times
    $maxToolRounds = 25;
    $toolRound = 0;

    try {
    while ($toolRound < $maxToolRounds) {
        $toolRound++;
        $assistantContent = [];
        $textAccumulator = '';
        $toolUseBlocks = [];
        $hasError = false;

        $stream = $providerInstance->stream($systemPrompt, $messages, $providerTools, $model);

        foreach ($stream as $event) {
            if (!is_array($event)) continue;

            switch ($event['type']) {
                case 'text':
                    $textAccumulator .= $event['content'];
                    ranger_sse_send(['type' => 'text', 'content' => $event['content']]);
                    break;

                case 'tool_use':
                    $toolUseBlocks[] = $event;
                    ranger_sse_send([
                        'type' => 'tool_use',
                        'name' => $event['name'],
                        'input' => $event['input'],
                    ]);
                    break;

                case 'usage':
                    $totalInputTokens += $event['input_tokens'] ?? 0;
                    $totalOutputTokens += $event['output_tokens'] ?? 0;
                    $totalCachedTokens += ($event['cache_read_tokens'] ?? 0) + ($event['cache_creation_tokens'] ?? 0);
                    break;

                case 'error':
                    ranger_sse_send(['type' => 'error', 'message' => $event['message']]);
                    $hasError = true;
                    break;

                case 'done':
                    break;
            }
        }

        // Build assistant message content
        if ($textAccumulator !== '') {
            $assistantContent[] = ['type' => 'text', 'text' => $textAccumulator];
        }
        foreach ($toolUseBlocks as $tb) {
            $assistantContent[] = [
                'type' => 'tool_use',
                'id' => $tb['id'],
                'name' => $tb['name'],
                'input' => $tb['input'],
            ];
        }

        if ($hasError && empty($assistantContent)) {
            break;
        }

        // Store assistant message
        if (!empty($assistantContent)) {
            // If only text, store as simple string for simpler conversation history
            if (count($assistantContent) === 1 && $assistantContent[0]['type'] === 'text') {
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent[0]['text']];
            } else {
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];
            }
        }

        // If no tool calls, we're done
        if (empty($toolUseBlocks)) {
            break;
        }

        // Execute tools and add results
        foreach ($toolUseBlocks as $tb) {
            // Verify the user has the required capability for this tool
            $requiredCap = ranger_tool_requires_capability($tb['name']);
            if ($requiredCap !== null && !in_array($requiredCap, $userCaps, true)) {
                $result = ['error' => 'Permission denied: you do not have access to this tool.'];
                ranger_sse_send(['type' => 'tool_result', 'name' => $tb['name'], 'result' => $result]);
                $messages[] = [
                    'role' => 'tool_result',
                    'tool_use_id' => $tb['id'],
                    'content' => json_encode($result),
                ];
                continue;
            }

            ranger_sse_send(['type' => 'tool_status', 'name' => $tb['name'], 'status' => 'running']);

            $result = ranger_execute_tool($tb['name'], $tb['input']);
            $result = ranger_truncate_tool_result($tb['name'], $result);

            // Frontend actions get a special event type so the client can execute them
            if (isset($result['_frontend_action'])) {
                ranger_sse_send([
                    'type' => 'frontend_action',
                    'action' => $result['action'],
                    'params' => $result['params'],
                ]);
                // Tell the AI it worked (the frontend will execute it)
                $result = ['success' => true, 'executed' => $result['action']];
            }

            ranger_sse_send([
                'type' => 'tool_result',
                'name' => $tb['name'],
                'result' => $result,
            ]);

            // Add tool result to messages for next round.
            // We use a normalized format that each provider's convertMessage handles.
            $messages[] = [
                'role' => 'tool',
                'tool_use_id' => $tb['id'],
                'tool_name' => $tb['name'],
                'content' => json_encode($result),
            ];
        }
    }
    } catch (\Throwable $e) {
        error_log('Ranger chat error: ' . $e->getMessage());
        ranger_sse_send(['type' => 'error', 'message' => 'An internal error occurred. Check error log for details.']);
    }

    // Calculate cost
    $costCents = ranger_calculate_cost($model, $totalInputTokens, $totalOutputTokens, $totalCachedTokens);

    // Send done event with usage stats
    ranger_sse_send([
        'type' => 'done',
        'usage' => [
            'input_tokens' => $totalInputTokens,
            'output_tokens' => $totalOutputTokens,
            'cached_tokens' => $totalCachedTokens,
            'cost_cents' => $costCents,
        ],
    ]);

    // Save conversation with usage
    try {
        $db = OutpostDB::connect();
        // Accumulate usage (add to existing totals for multi-message conversations)
        $db->prepare('UPDATE ranger_conversations SET messages = ?, model = ?, total_input_tokens = total_input_tokens + ?, total_output_tokens = total_output_tokens + ?, total_cost_cents = total_cost_cents + ?, updated_at = datetime("now") WHERE id = ?')->execute([
            json_encode($messages),
            $model,
            $totalInputTokens,
            $totalOutputTokens,
            $costCents,
            $conversationId,
        ]);
    } catch (\Throwable $e) {
        // Don't let save failure block the response
    }
}

function handle_ranger_conversations_list(): void {
    ensure_ranger_tables();
    $userId = (int)($_SESSION['outpost_user_id'] ?? 0);
    $conversations = OutpostDB::fetchAll(
        'SELECT id, title, provider, model, total_input_tokens, total_output_tokens, total_cost_cents, created_at, updated_at FROM ranger_conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50',
        [$userId]
    );
    // Calculate totals
    $totalCost = 0;
    $totalTokens = 0;
    foreach ($conversations as &$c) {
        $totalCost += (float)($c['total_cost_cents'] ?? 0);
        $totalTokens += (int)($c['total_input_tokens'] ?? 0) + (int)($c['total_output_tokens'] ?? 0);
    }
    json_response([
        'conversations' => $conversations,
        'usage_summary' => [
            'total_cost_cents' => round($totalCost, 2),
            'total_tokens' => $totalTokens,
            'conversation_count' => count($conversations),
        ],
    ]);
}

function handle_ranger_conversation_get(): void {
    ensure_ranger_tables();
    $id = (int)($_GET['id'] ?? 0);
    $userId = (int)($_SESSION['outpost_user_id'] ?? 0);

    if (!$id) json_error('Conversation ID is required');

    $convo = OutpostDB::fetchOne('SELECT * FROM ranger_conversations WHERE id = ? AND user_id = ?', [$id, $userId]);
    if (!$convo) json_error('Conversation not found', 404);

    $convo['messages'] = json_decode($convo['messages'] ?? '[]', true);
    json_response(['conversation' => $convo]);
}

function handle_ranger_conversation_delete(): void {
    ensure_ranger_tables();
    $id = (int)($_GET['id'] ?? 0);
    $userId = (int)($_SESSION['outpost_user_id'] ?? 0);

    if (!$id) json_error('Conversation ID is required');

    $convo = OutpostDB::fetchOne('SELECT id FROM ranger_conversations WHERE id = ? AND user_id = ?', [$id, $userId]);
    if (!$convo) json_error('Conversation not found', 404);

    OutpostDB::query('DELETE FROM ranger_conversations WHERE id = ?', [$id]);
    json_response(['success' => true]);
}

function handle_ranger_settings_get(): void {
    outpost_require_cap('settings.*');
    $keys = [
        'ranger_default_provider',
        'ranger_model_claude', 'ranger_model_openai', 'ranger_model_gemini',
        'ranger_api_key_claude', 'ranger_api_key_openai', 'ranger_api_key_gemini',
        'ranger_output_style',
    ];

    $settings = [];
    foreach ($keys as $key) {
        $row = OutpostDB::fetchOne('SELECT value FROM settings WHERE key = ?', [$key]);
        $value = $row['value'] ?? '';

        if (str_starts_with($key, 'ranger_api_key_') && $value !== '') {
            // Mask the key — show first 5 + last 4 chars
            try {
                $decrypted = ranger_decrypt($value);
                $len = strlen($decrypted);
                if ($len > 9) {
                    $value = substr($decrypted, 0, 5) . '****' . substr($decrypted, -4);
                } else {
                    $value = '****';
                }
            } catch (\Throwable) {
                $value = '****';
            }
        }

        $settings[$key] = $value;
    }

    json_response(['settings' => $settings]);
}

function handle_ranger_settings_update(): void {
    outpost_require_cap('settings.*');
    $data = get_json_body();

    $allowedKeys = [
        'ranger_default_provider',
        'ranger_model_claude', 'ranger_model_openai', 'ranger_model_gemini',
        'ranger_api_key_claude', 'ranger_api_key_openai', 'ranger_api_key_gemini',
        'ranger_output_style',
    ];

    foreach ($data as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) continue;
        if (!is_string($value)) continue;

        // If it's an API key field
        if (str_starts_with($key, 'ranger_api_key_')) {
            // Skip masked values (user didn't change it)
            if (str_contains($value, '****')) continue;
            // Empty = remove key
            if ($value === '') {
                OutpostDB::query('DELETE FROM settings WHERE key = ?', [$key]);
                continue;
            }
            // Encrypt the key
            $value = ranger_encrypt($value);
        }

        OutpostDB::query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$key, $value]);
    }

    json_response(['success' => true]);
}

// ── Releases Tool ───────────────────────────────────────

function ranger_tool_manage_releases(array $input): array {
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $rows = OutpostDB::fetchAll("
                SELECT r.*, COUNT(rc.id) as change_count, u.display_name as created_by_name
                FROM releases r
                LEFT JOIN release_changes rc ON rc.release_id = r.id
                LEFT JOIN users u ON u.id = r.created_by
                GROUP BY r.id
                ORDER BY r.created_at DESC
            ");
            return ['releases' => $rows];

        case 'get':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
            if (!$release) return ['error' => 'Release not found'];
            $changes = OutpostDB::fetchAll("SELECT * FROM release_changes WHERE release_id = ? ORDER BY created_at ASC", [$id]);
            $release['changes'] = $changes;
            return $release;

        case 'create':
            $name = trim($input['name'] ?? '');
            if (!$name) return ['error' => 'name is required'];
            $userId = OutpostAuth::getUserId();
            $id = OutpostDB::insert('releases', [
                'name' => $name,
                'description' => trim($input['description'] ?? ''),
                'created_by' => $userId,
            ]);
            return ['success' => true, 'id' => $id, 'name' => $name];

        case 'publish':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
            if (!$release) return ['error' => 'Release not found'];
            if ($release['status'] !== 'draft') return ['error' => 'Only draft releases can be published'];
            $changes = OutpostDB::fetchAll("SELECT * FROM release_changes WHERE release_id = ?", [$id]);
            if (empty($changes)) return ['error' => 'Cannot publish release with no changes'];

            $db = OutpostDB::connect();
            $db->beginTransaction();
            try {
                foreach ($changes as $change) {
                    $currentState = release_get_entity_state($change['entity_type'], $change['entity_id']);
                    if ($currentState !== null) {
                        OutpostDB::update('release_changes', ['snapshot_before' => json_encode($currentState)], 'id = ?', [$change['id']]);
                    }
                    $after = $change['snapshot_after'] ? json_decode($change['snapshot_after'], true) : null;
                    release_apply_change($change['entity_type'], $change['entity_id'], $change['action'], $after);
                }
                $userId = OutpostAuth::getUserId();
                OutpostDB::update('releases', [
                    'status' => 'published',
                    'published_at' => date('Y-m-d H:i:s'),
                    'published_by' => $userId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                $db->commit();
            } catch (\Exception $e) {
                $db->rollBack();
                return ['error' => 'Failed to publish: ' . $e->getMessage()];
            }
            return ['success' => true, 'status' => 'published'];

        case 'rollback':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
            if (!$release) return ['error' => 'Release not found'];
            if ($release['status'] !== 'published') return ['error' => 'Only published releases can be rolled back'];

            $changes = OutpostDB::fetchAll("SELECT * FROM release_changes WHERE release_id = ? ORDER BY id DESC", [$id]);
            $db = OutpostDB::connect();
            $db->beginTransaction();
            try {
                foreach ($changes as $change) {
                    $before = $change['snapshot_before'] ? json_decode($change['snapshot_before'], true) : null;
                    switch ($change['action']) {
                        case 'update':
                            if ($before) release_apply_change($change['entity_type'], $change['entity_id'], 'update', $before);
                            break;
                        case 'create':
                            release_apply_change($change['entity_type'], $change['entity_id'], 'delete', null);
                            break;
                        case 'delete':
                            if ($before) release_restore_entity($change['entity_type'], $before);
                            break;
                    }
                }
                OutpostDB::update('releases', ['status' => 'rolled_back', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                $db->commit();
            } catch (\Exception $e) {
                $db->rollBack();
                return ['error' => 'Failed to rollback: ' . $e->getMessage()];
            }
            return ['success' => true, 'status' => 'rolled_back'];

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
            if (!$release) return ['error' => 'Release not found'];
            if ($release['status'] !== 'draft') return ['error' => 'Only draft releases can be deleted'];
            OutpostDB::delete('releases', 'id = ?', [$id]);
            return ['success' => true];

        case 'add_change':
            $id = (int)($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $release = OutpostDB::fetchOne("SELECT * FROM releases WHERE id = ?", [$id]);
            if (!$release || $release['status'] !== 'draft') return ['error' => 'Release not found or not a draft'];
            $entityType = $input['entity_type'] ?? '';
            $entityId = (int)($input['entity_id'] ?? 0);
            $changeAction = $input['change_action'] ?? 'update';
            if (!$entityType || !$entityId) return ['error' => 'entity_type and entity_id are required'];
            release_add_change($id, $entityType, $entityId, $changeAction, null, null, $input['entity_name'] ?? '');
            return ['success' => true];

        default:
            return ['error' => "Unknown action: $action. Use list, create, get, publish, rollback, delete, or add_change."];
    }
}

function ranger_tool_manage_workflows(array $input): array {
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'list':
            $workflows = OutpostDB::fetchAll('SELECT * FROM workflows ORDER BY is_default DESC, name ASC');
            foreach ($workflows as &$wf) {
                $wf['stages'] = json_decode($wf['stages'], true) ?: [];
                $usage = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM collections WHERE workflow_id = ?', [$wf['id']]);
                $wf['collection_count'] = (int) ($usage['c'] ?? 0);
            }
            return ['workflows' => $workflows];

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE id = ?', [$id]);
            if (!$wf) return ['error' => 'Workflow not found'];
            $wf['stages'] = json_decode($wf['stages'], true) ?: [];
            return $wf;

        case 'create':
            $name = trim($input['name'] ?? '');
            if (!$name) return ['error' => 'name is required'];
            $stages = $input['stages'] ?? [];
            if (!is_array($stages) || count($stages) < 2) {
                return ['error' => 'At least two stages are required (draft and published)'];
            }
            $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
            $slug = preg_replace('/-+/', '-', trim($slug, '-'));
            $existing = OutpostDB::fetchOne('SELECT id FROM workflows WHERE slug = ?', [$slug]);
            if ($existing) return ['error' => 'A workflow with this slug already exists'];
            $id = OutpostDB::insert('workflows', [
                'name' => $name,
                'slug' => $slug,
                'stages' => json_encode($stages),
            ]);
            return ['success' => true, 'id' => $id, 'name' => $name];

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if (isset($input['name'])) $update['name'] = trim($input['name']);
            if (isset($input['stages'])) $update['stages'] = json_encode($input['stages']);
            OutpostDB::update('workflows', $update, 'id = ?', [$id]);
            return ['success' => true];

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if (!$id) return ['error' => 'id is required'];
            $wf = OutpostDB::fetchOne('SELECT * FROM workflows WHERE id = ?', [$id]);
            if (!$wf) return ['error' => 'Workflow not found'];
            if ((int) ($wf['is_default'] ?? 0) === 1) return ['error' => 'Cannot delete the default workflow'];
            $usage = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM collections WHERE workflow_id = ?', [$id]);
            if ((int) ($usage['c'] ?? 0) > 0) return ['error' => 'Workflow is assigned to collections'];
            OutpostDB::delete('workflows', 'id = ?', [$id]);
            return ['success' => true];

        case 'transition':
            $itemId = (int) ($input['item_id'] ?? 0);
            $toStage = trim($input['to_stage'] ?? '');
            if (!$itemId || !$toStage) return ['error' => 'item_id and to_stage are required'];
            $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
            if (!$item) return ['error' => 'Item not found'];
            $workflow = get_collection_workflow((int) $item['collection_id']);
            $currentSlug = $item['status'] ?? 'draft';
            $currentStage = find_stage($workflow['stages'], $currentSlug);
            if ($currentStage) {
                $canMoveTo = $currentStage['can_move_to'] ?? [];
                if (!in_array($toStage, $canMoveTo)) {
                    return ['error' => "Cannot transition from '{$currentSlug}' to '{$toStage}'"];
                }
            }
            // Validate target stage exists and user role is allowed
            $targetStage = find_stage($workflow['stages'], $toStage);
            if (!$targetStage) {
                return ['error' => "Target stage '{$toStage}' does not exist in this workflow"];
            }
            $role = $_SESSION['outpost_role'] ?? '';
            $allowedRoles = $targetStage['roles'] ?? [];
            if (!empty($allowedRoles) && !in_array($role, $allowedRoles)) {
                return ['error' => 'You do not have permission to move content to this stage'];
            }
            $update = ['status' => $toStage, 'updated_at' => date('Y-m-d H:i:s')];
            if ($toStage === 'published' && empty($item['published_at'])) {
                $update['published_at'] = date('Y-m-d H:i:s');
            }
            OutpostDB::update('collection_items', $update, 'id = ?', [$itemId]);
            $userId = (int) ($_SESSION['outpost_user_id'] ?? 0);
            OutpostDB::insert('workflow_transitions', [
                'item_id' => $itemId,
                'collection_id' => (int) $item['collection_id'],
                'from_stage' => $currentSlug,
                'to_stage' => $toStage,
                'user_id' => $userId,
                'note' => trim($input['note'] ?? ''),
            ]);
            return ['success' => true, 'from' => $currentSlug, 'to' => $toStage];

        case 'history':
            $itemId = (int) ($input['item_id'] ?? 0);
            if (!$itemId) return ['error' => 'item_id is required'];
            $transitions = OutpostDB::fetchAll(
                "SELECT wt.*, u.display_name FROM workflow_transitions wt LEFT JOIN users u ON wt.user_id = u.id WHERE wt.item_id = ? ORDER BY wt.created_at DESC LIMIT 50",
                [$itemId]
            );
            return ['transitions' => $transitions];

        default:
            return ['error' => "Unknown action: $action. Use list, create, get, update, delete, transition, or history."];
    }
}
