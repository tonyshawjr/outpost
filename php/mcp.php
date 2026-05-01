<?php
/**
 * Outpost CMS — MCP (Model Context Protocol) Server
 * JSON-RPC 2.0 over Streamable HTTP transport.
 * Single endpoint: POST /outpost/mcp.php
 *
 * Exposes Outpost content operations as MCP tools so AI clients
 * (Claude Desktop, ChatGPT, etc.) can manage site content directly.
 *
 * Auth: Bearer token via existing API keys (Settings → Integrations).
 * No new dependencies. No Composer. Pure PHP.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('log_errors', '1');
ini_set('log_errors_max_len', '1024');
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sanitizer.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/blocks.php';

// ── Constants ────────────────────────────────────────────
define('MCP_PROTOCOL_VERSION', '2025-03-26');
define('MCP_SERVER_NAME', 'Outpost CMS');

// ── JSON-RPC Error Codes ─────────────────────────────────
define('MCP_ERR_PARSE',        -32700);
define('MCP_ERR_INVALID_REQ',  -32600);
define('MCP_ERR_METHOD',       -32601);
define('MCP_ERR_PARAMS',       -32602);
define('MCP_ERR_INTERNAL',     -32603);
define('MCP_ERR_AUTH',         -32001);

// ── Payload size limit (1 MB) — prevent DoS via huge payloads ─
define('MCP_MAX_PAYLOAD_BYTES', 1 * 1024 * 1024);
if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > MCP_MAX_PAYLOAD_BYTES) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => MCP_ERR_PARSE, 'message' => 'Payload too large (max 1 MB)']]);
    exit;
}

// ── CORS ─────────────────────────────────────────────────
if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Strip any newlines/carriage returns to prevent header injection
    $origin = str_replace(["\r", "\n", "\0"], '', $_SERVER['HTTP_ORIGIN']);
    $isLocal = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin);
    if ($isLocal) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
    } else {
        // Check configured CORS origins
        try {
            $corsRow = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'api_cors_origins'");
            $corsOrigins = $corsRow['value'] ?? '';
        } catch (\Throwable $e) { $corsOrigins = ''; }

        if ($corsOrigins === '*') {
            // Wildcard CORS — do NOT send credentials header (browser security)
            header('Access-Control-Allow-Origin: *');
        } elseif ($corsOrigins) {
            $allowed = array_map('trim', explode(',', $corsOrigins));
            if (in_array($origin, $allowed, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Credentials: true');
            }
        }
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Accept');
    header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: Mcp-Session-Id');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Response helpers ─────────────────────────────────────
function mcp_response(mixed $id, array $result): never {
    ob_end_clean();
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_error(mixed $id, int $code, string $message, mixed $data = null): never {
    ob_end_clean();
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) $err['data'] = $data;
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => $err,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_tool_result(mixed $id, mixed $data, bool $isError = false): never {
    $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    mcp_response($id, [
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => $isError,
    ]);
}

// ── Helpers ──────────────────────────────────────────────
/** Escape special LIKE characters so user input is matched literally */
function mcp_like_escape(string $value): string {
    return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
}

/** Sanitize richtext fields in collection item data using the collection schema */
function mcp_sanitize_collection_data(array $collection, array $data): array {
    $schema = json_decode($collection['schema'] ?: '{}', true);
    $fieldList = $schema['fields'] ?? $schema;
    if (!is_array($fieldList)) return $data;

    $richtextFields = [];
    foreach ($fieldList as $f) {
        if (isset($f['name']) && ($f['type'] ?? '') === 'richtext') {
            $richtextFields[] = $f['name'];
        }
    }

    foreach ($richtextFields as $fieldName) {
        if (isset($data[$fieldName]) && is_string($data[$fieldName])) {
            $data[$fieldName] = OutpostSanitizer::clean($data[$fieldName]);
        }
    }

    return $data;
}

// ── Authentication ───────────────────────────────────────
function mcp_authenticate(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        mcp_error(null, MCP_ERR_AUTH, 'Authentication required. Provide an API key via Authorization: Bearer <key>');
    }
    $providedKey = $m[1];
    $prefix = substr($providedKey, 0, 11);

    $keys = OutpostDB::fetchAll('SELECT * FROM api_keys WHERE key_prefix = ?', [$prefix]);
    foreach ($keys as $row) {
        if (password_verify($providedKey, $row['key_hash'])) {
            $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
            if (!$user || !outpost_is_internal_role($user['role'])) {
                mcp_error(null, MCP_ERR_AUTH, 'API key is not associated with an admin user');
            }
            // Update last_used_at
            OutpostDB::update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
            // Populate session vars for role checks
            $_SESSION['outpost_user_id'] = $user['id'];
            $_SESSION['outpost_role'] = $user['role'];
            return [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
            ];
        }
    }
    mcp_error(null, MCP_ERR_AUTH, 'Invalid API key');
}

// ── Handle DELETE (session termination) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Require auth to prevent unauthenticated session termination
    mcp_authenticate();
    http_response_code(202);
    exit;
}

// ── Reject non-POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, DELETE, OPTIONS');
    exit;
}

// ── Parse JSON-RPC request ───────────────────────────────
$rawBody = file_get_contents('php://input', false, null, 0, MCP_MAX_PAYLOAD_BYTES + 1);
if ($rawBody === false || $rawBody === '') {
    mcp_error(null, MCP_ERR_PARSE, 'Empty request body');
}
if (strlen($rawBody) > MCP_MAX_PAYLOAD_BYTES) {
    mcp_error(null, MCP_ERR_PARSE, 'Payload too large (max 1 MB)');
}

$request = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    mcp_error(null, MCP_ERR_PARSE, 'Invalid JSON');
}

// Reject batch requests (JSON-RPC arrays) — not supported
if (!is_array($request) || array_is_list($request)) {
    mcp_error(null, MCP_ERR_INVALID_REQ, 'Invalid JSON-RPC 2.0 request. Batch requests are not supported.');
}

if (($request['jsonrpc'] ?? '') !== '2.0') {
    mcp_error(null, MCP_ERR_INVALID_REQ, 'Invalid JSON-RPC 2.0 request');
}

if (!isset($request['method']) || !is_string($request['method'])) {
    mcp_error(null, MCP_ERR_INVALID_REQ, 'Missing or invalid "method" field');
}

$id = $request['id'] ?? null;
$method = $request['method'];
$params = $request['params'] ?? [];

// ── Notifications (no id) — acknowledge and exit ─────────
// notifications/initialized is the only notification allowed without auth
// (sent immediately after initialize, before the client has authed)
if ($id === null && $method === 'notifications/initialized') {
    http_response_code(202);
    exit;
}
if ($id === null) {
    // All other notifications require auth to prevent unauthenticated flooding
    mcp_authenticate();
    http_response_code(202);
    exit;
}

// ── Initialize does not require auth ─────────────────────
if ($method === 'initialize') {
    $sessionId = bin2hex(random_bytes(16));
    header("Mcp-Session-Id: {$sessionId}");
    mcp_response($id, [
        'protocolVersion' => MCP_PROTOCOL_VERSION,
        'capabilities' => [
            'tools' => new \stdClass(),
            'resources' => new \stdClass(),
        ],
        'serverInfo' => [
            'name' => MCP_SERVER_NAME,
            'version' => OUTPOST_VERSION,
        ],
        'instructions' => 'Outpost CMS MCP server. Use tools to manage content (collections, items, pages, globals, media). Use resources to read the site schema and structure. All write operations create revision snapshots automatically.',
    ]);
}

// ── All other methods require auth ───────────────────────
$user = mcp_authenticate();

// ── Ping ─────────────────────────────────────────────────
if ($method === 'ping') {
    mcp_response($id, new \stdClass());
}

// ── Route ────────────────────────────────────────────────
match ($method) {
    'tools/list'             => handle_mcp_tools_list($id),
    'tools/call'             => handle_mcp_tools_call($id, $params),
    'resources/list'         => handle_mcp_resources_list($id),
    'resources/templates/list' => handle_mcp_resource_templates_list($id),
    'resources/read'         => handle_mcp_resources_read($id, $params),
    default                  => mcp_error($id, MCP_ERR_METHOD, 'Unknown method: ' . substr($method, 0, 100)),
};

// ═══════════════════════════════════════════════════════════
// TOOLS
// ═══════════════════════════════════════════════════════════

function handle_mcp_tools_list(mixed $id): never {
    mcp_response($id, ['tools' => mcp_get_tool_definitions()]);
}

function handle_mcp_tools_call(mixed $id, array $params): never {
    $toolName = $params['name'] ?? '';
    $args = $params['arguments'] ?? [];

    $tools = [
        'list_collections' => 'mcp_tool_list_collections',
        'get_collection'   => 'mcp_tool_get_collection',
        'list_items'       => 'mcp_tool_list_items',
        'get_item'         => 'mcp_tool_get_item',
        'create_item'      => 'mcp_tool_create_item',
        'update_item'      => 'mcp_tool_update_item',
        'delete_item'      => 'mcp_tool_delete_item',
        'list_pages'       => 'mcp_tool_list_pages',
        'get_page_fields'  => 'mcp_tool_get_page_fields',
        'update_page_fields' => 'mcp_tool_update_page_fields',
        'get_globals'      => 'mcp_tool_get_globals',
        'update_globals'   => 'mcp_tool_update_globals',
        'list_media'       => 'mcp_tool_list_media',
        'search_content'   => 'mcp_tool_search_content',
        'get_schema'       => 'mcp_tool_get_schema',
        'list_blocks'      => 'mcp_tool_list_blocks',
        'get_block_schema' => 'mcp_tool_get_block_schema',
        'list_channels'    => 'mcp_tool_list_channels',
        'get_channel_schema' => 'mcp_tool_get_channel_schema',
        'list_templates'   => 'mcp_tool_list_templates',
        'compose_page'     => 'mcp_tool_compose_page',
        'add_block_to_page' => 'mcp_tool_add_block_to_page',
        'set_block_field'  => 'mcp_tool_set_block_field',
    ];

    if (!isset($tools[$toolName])) {
        mcp_error($id, MCP_ERR_PARAMS, 'Unknown tool: ' . substr($toolName, 0, 100));
    }

    // Rate-limit mutation tools (create, update, delete)
    $mutationTools = ['create_item', 'update_item', 'delete_item', 'update_page_fields', 'update_globals', 'compose_page', 'add_block_to_page', 'set_block_field'];
    if (in_array($toolName, $mutationTools, true)) {
        OutpostAuth::checkApiRateLimit();
    }

    try {
        $tools[$toolName]($id, $args);
    } catch (\Throwable $e) {
        // Log the full error server-side but only show a safe message to the client
        error_log('MCP tool error [' . $toolName . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        // Only expose the message, not file paths or stack traces
        $safeMessage = preg_replace('/\b(\/[^\s:]+\.(php|db|sqlite))\b/i', '[redacted]', $e->getMessage());
        mcp_tool_result($id, "Error: {$safeMessage}", true);
    }
}

// ── Tool Definitions ─────────────────────────────────────
function mcp_get_tool_definitions(): array {
    return [
        [
            'name' => 'list_collections',
            'description' => 'List all content collections with their schemas, field definitions, and item counts.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
        [
            'name' => 'get_collection',
            'description' => 'Get a single collection by slug, including its full schema and field definitions.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Collection slug (e.g. "post", "work")'],
                ],
                'required' => ['slug'],
            ],
        ],
        [
            'name' => 'list_items',
            'description' => 'List items in a collection with optional filtering by status.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection' => ['type' => 'string', 'description' => 'Collection slug'],
                    'status' => ['type' => 'string', 'description' => 'Filter by status', 'enum' => ['draft', 'published', 'scheduled', 'pending_review']],
                    'limit' => ['type' => 'integer', 'description' => 'Max items (default 50, max 100)', 'default' => 50],
                    'offset' => ['type' => 'integer', 'description' => 'Pagination offset', 'default' => 0],
                ],
                'required' => ['collection'],
            ],
        ],
        [
            'name' => 'get_item',
            'description' => 'Get a single collection item by ID with all field data.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Item ID'],
                ],
                'required' => ['id'],
            ],
        ],
        [
            'name' => 'create_item',
            'description' => 'Create a new item in a collection.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'collection' => ['type' => 'string', 'description' => 'Collection slug'],
                    'slug' => ['type' => 'string', 'description' => 'URL slug (lowercase, hyphens, no spaces)'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'default' => 'draft'],
                    'data' => ['type' => 'object', 'description' => 'Field values as key-value pairs matching the collection schema'],
                ],
                'required' => ['collection', 'slug', 'data'],
            ],
        ],
        [
            'name' => 'update_item',
            'description' => 'Update an existing collection item. Only specified fields change (partial update).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Item ID'],
                    'data' => ['type' => 'object', 'description' => 'Field values to update'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'scheduled']],
                    'slug' => ['type' => 'string', 'description' => 'New slug'],
                ],
                'required' => ['id'],
            ],
        ],
        [
            'name' => 'delete_item',
            'description' => 'Delete a collection item by ID.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Item ID'],
                ],
                'required' => ['id'],
            ],
        ],
        [
            'name' => 'list_pages',
            'description' => 'List all site pages with paths, titles, and visibility.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
        [
            'name' => 'get_page_fields',
            'description' => 'Get all editable fields for a page with current content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Page path (e.g. "/" for homepage, "/about")'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'update_page_fields',
            'description' => 'Update field values on a page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Page path'],
                    'fields' => ['type' => 'object', 'description' => 'Object of field_name: new_value pairs'],
                ],
                'required' => ['path', 'fields'],
            ],
        ],
        [
            'name' => 'get_globals',
            'description' => 'Get all global field values (site name, logo, social links, etc.).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
        [
            'name' => 'update_globals',
            'description' => 'Update global field values.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'fields' => ['type' => 'object', 'description' => 'Object of field_name: new_value pairs'],
                ],
                'required' => ['fields'],
            ],
        ],
        [
            'name' => 'list_media',
            'description' => 'List uploaded media files with optional search.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Search by filename or alt text'],
                    'limit' => ['type' => 'integer', 'default' => 50],
                    'offset' => ['type' => 'integer', 'default' => 0],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'search_content',
            'description' => 'Search across all collection items by keyword.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query'],
                    'collection' => ['type' => 'string', 'description' => 'Limit search to a specific collection slug'],
                    'limit' => ['type' => 'integer', 'default' => 20],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name' => 'list_blocks',
            'description' => 'List all blocks available in the active theme. Each block includes slug, name, description, category, icon, and field schema. Use this before composing pages so you know what blocks exist and what fields each one accepts.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
        [
            'name' => 'get_block_schema',
            'description' => 'Get the full schema for a single block by slug, including all field definitions and the raw HTML/CSS file paths.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Block slug (e.g. "hero", "faq-accordion")'],
                ],
                'required' => ['slug'],
            ],
        ],
        [
            'name' => 'list_channels',
            'description' => 'List all channels (external data sources — REST API, RSS, CSV — that flow content into the site). Returns slug, name, type, status, url_pattern, and the field map describing each channel\'s data shape.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
        [
            'name' => 'get_channel_schema',
            'description' => 'Get the full schema for a single channel by slug, including its field map (data shape), config metadata, sort settings, and url_pattern.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Channel slug (e.g. "github-issues", "rss-feed")'],
                ],
                'required' => ['slug'],
            ],
        ],
        [
            'name' => 'list_templates',
            'description' => 'List the page templates available in the active theme. Prefers a "templates" array declared in theme.json (or blueprint.json), and falls back to scanning *.html files in the theme\'s templates/ directory or the theme root. Used by the page composer to pick a template for a new page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
        [
            'name' => 'compose_page',
            'description' => 'Create a new page with a sequence of blocks pre-populated. Inputs: title, optional slug, optional template, blocks array. NOTE: Outpost\'s current page model stores per-page editable values in the fields table keyed by data-outpost field names — there is no DB-backed list of block instances on a page. Page composition therefore requires a page-builder data model that has not yet been built. This tool returns an explicit error until that model lands.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Page title'],
                    'slug' => ['type' => 'string', 'description' => 'Optional URL slug; derived from title if omitted'],
                    'template' => ['type' => 'string', 'description' => 'Optional template slug (e.g. "page", "landing")'],
                    'blocks' => [
                        'type' => 'array',
                        'description' => 'Ordered list of blocks to insert',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => ['type' => 'string'],
                                'fields' => ['type' => 'object'],
                            ],
                            'required' => ['slug'],
                        ],
                    ],
                ],
                'required' => ['title', 'blocks'],
            ],
        ],
        [
            'name' => 'add_block_to_page',
            'description' => 'Insert a block instance into an existing page at an optional position. NOTE: Outpost does not yet store per-page block instances in the database — pages are filesystem HTML templates and the fields table only holds individual data-outpost values. This tool returns an explicit error until the page-builder data model is added.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'description' => 'Target page ID'],
                    'block_slug' => ['type' => 'string', 'description' => 'Block slug to insert'],
                    'position' => ['type' => 'integer', 'description' => 'Optional 0-based insertion index; appends if omitted'],
                    'fields' => ['type' => 'object', 'description' => 'Initial field values for the block'],
                ],
                'required' => ['page_id', 'block_slug'],
            ],
        ],
        [
            'name' => 'set_block_field',
            'description' => 'Update a single field value on a single block instance attached to a page. NOTE: Blocked on the same missing data model as compose_page and add_block_to_page — pages do not store block instances in the database yet. Block-level *theme settings* (CSS variables) can already be set via update_page_fields using the `setting_{block}_{name}` field-name convention.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'block_id' => ['type' => 'integer'],
                    'field_key' => ['type' => 'string'],
                    'value' => ['description' => 'New field value (string, number, boolean, or object)'],
                ],
                'required' => ['page_id', 'block_id', 'field_key', 'value'],
            ],
        ],
        [
            'name' => 'get_schema',
            'description' => 'Get the complete site schema: all collections with fields, all pages, global fields, and folder structure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ],
    ];
}

// ── Tool Implementations ─────────────────────────────────

function mcp_tool_list_collections(mixed $id, array $args): never {
    $collections = OutpostDB::fetchAll('SELECT * FROM collections ORDER BY name ASC');
    $result = [];
    foreach ($collections as $c) {
        $counts = OutpostDB::fetchOne(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft
             FROM collection_items WHERE collection_id = ?",
            [$c['id']]
        );
        $schema = json_decode($c['schema'] ?: '{}', true);
        $fields = [];
        $fieldList = $schema['fields'] ?? $schema;
        if (is_array($fieldList)) {
            foreach ($fieldList as $f) {
                if (isset($f['name'])) {
                    $fields[] = ['name' => $f['name'], 'type' => $f['type'] ?? 'text', 'label' => $f['label'] ?? $f['name']];
                }
            }
        }
        $result[] = [
            'slug' => $c['slug'],
            'name' => $c['name'],
            'url_pattern' => $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}'),
            'items_total' => (int) ($counts['total'] ?? 0),
            'items_published' => (int) ($counts['published'] ?? 0),
            'items_draft' => (int) ($counts['draft'] ?? 0),
            'fields' => $fields,
        ];
    }
    mcp_tool_result($id, $result);
}

function mcp_tool_get_collection(mixed $id, array $args): never {
    $slug = $args['slug'] ?? '';
    if (!$slug) mcp_tool_result($id, 'Error: slug is required', true);
    if (!preg_match('/^[a-z0-9_-]+$/', $slug)) mcp_tool_result($id, 'Error: invalid slug format', true);

    $c = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$c) mcp_tool_result($id, "Error: Collection '{$slug}' not found", true);

    $schema = json_decode($c['schema'] ?: '{}', true);
    $fields = [];
    $fieldList = $schema['fields'] ?? $schema;
    if (is_array($fieldList)) {
        foreach ($fieldList as $f) {
            if (isset($f['name'])) {
                $fields[] = $f;
            }
        }
    }

    // Get folders for this collection
    $folders = OutpostDB::fetchAll(
        'SELECT slug, name, type FROM folders WHERE collection_id = ? ORDER BY name ASC',
        [$c['id']]
    );

    mcp_tool_result($id, [
        'slug' => $c['slug'],
        'name' => $c['name'],
        'singular_name' => $c['singular_name'] ?: $c['name'],
        'url_pattern' => $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}'),
        'sort_field' => $c['sort_field'] ?: 'created_at',
        'sort_direction' => $c['sort_direction'] ?: 'DESC',
        'items_per_page' => (int) ($c['items_per_page'] ?: 10),
        'fields' => $fields,
        'folders' => $folders,
    ]);
}

function mcp_tool_list_items(mixed $id, array $args): never {
    $slug = $args['collection'] ?? '';
    if (!$slug) mcp_tool_result($id, 'Error: collection is required', true);
    if (!preg_match('/^[a-z0-9_-]+$/', $slug)) mcp_tool_result($id, 'Error: invalid collection slug format', true);

    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$collection) mcp_tool_result($id, "Error: Collection '{$slug}' not found", true);

    $limit = max(1, min((int) ($args['limit'] ?? 50), 100));
    $offset = max((int) ($args['offset'] ?? 0), 0);
    $status = $args['status'] ?? '';

    $where = 'collection_id = ?';
    $params = [$collection['id']];

    if ($status && in_array($status, ['draft', 'published', 'scheduled', 'pending_review'])) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }

    $sortCol = in_array($collection['sort_field'], ['created_at', 'updated_at', 'published_at', 'slug', 'sort_order'])
        ? $collection['sort_field'] : 'created_at';
    $sortDir = strtoupper($collection['sort_direction']) === 'ASC' ? 'ASC' : 'DESC';

    $items = OutpostDB::fetchAll(
        "SELECT * FROM collection_items WHERE {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ? OFFSET ?",
        [...$params, $limit, $offset]
    );

    $total = OutpostDB::fetchOne("SELECT COUNT(*) as c FROM collection_items WHERE {$where}", $params);

    $result = [];
    foreach ($items as $item) {
        $item['data'] = json_decode($item['data'], true) ?: [];
        $result[] = $item;
    }

    mcp_tool_result($id, ['items' => $result, 'total' => (int) $total['c'], 'limit' => $limit, 'offset' => $offset]);
}

function mcp_tool_get_item(mixed $id, array $args): never {
    $itemId = (int) ($args['id'] ?? 0);
    if (!$itemId) mcp_tool_result($id, 'Error: id is required', true);

    $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
    if (!$item) mcp_tool_result($id, "Error: Item {$itemId} not found", true);

    $item['data'] = json_decode($item['data'], true) ?: [];

    // Get collection info
    $coll = OutpostDB::fetchOne('SELECT slug, name FROM collections WHERE id = ?', [$item['collection_id']]);
    $item['collection_slug'] = $coll['slug'] ?? '';
    $item['collection_name'] = $coll['name'] ?? '';

    // Get labels
    $labels = OutpostDB::fetchAll(
        'SELECT l.slug, l.name FROM labels l JOIN item_labels il ON l.id = il.label_id WHERE il.item_id = ?',
        [$itemId]
    );
    $item['labels'] = $labels;

    mcp_tool_result($id, $item);
}

function mcp_tool_create_item(mixed $id, array $args): never {
    $collSlug = $args['collection'] ?? '';
    $slug = trim($args['slug'] ?? '');
    $data = $args['data'] ?? [];
    $status = $args['status'] ?? 'draft';

    if (!$collSlug) mcp_tool_result($id, 'Error: collection is required', true);
    if (!preg_match('/^[a-z0-9_-]+$/', $collSlug)) mcp_tool_result($id, 'Error: invalid collection slug format', true);
    if (!$slug) mcp_tool_result($id, 'Error: slug is required', true);

    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        mcp_tool_result($id, 'Error: slug must be lowercase letters, numbers, and hyphens only', true);
    }
    if (!in_array($status, ['draft', 'published'])) {
        $status = 'draft';
    }

    $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$collSlug]);
    if (!$collection) mcp_tool_result($id, "Error: Collection '{$collSlug}' not found", true);

    $existing = OutpostDB::fetchOne(
        'SELECT id FROM collection_items WHERE collection_id = ? AND slug = ?',
        [$collection['id'], $slug]
    );
    if ($existing) mcp_tool_result($id, "Error: An item with slug '{$slug}' already exists in this collection", true);

    // Sanitize richtext fields based on collection schema
    $data = mcp_sanitize_collection_data($collection, $data);

    $itemId = OutpostDB::insert('collection_items', [
        'collection_id' => $collection['id'],
        'slug' => $slug,
        'status' => $status,
        'data' => json_encode($data),
        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
    ]);

    // Index for Compass search
    if (file_exists(__DIR__ . '/compass.php')) {
        require_once __DIR__ . '/compass.php';
        if (function_exists('compass_index_item')) {
            compass_index_item($itemId);
        }
    }

    // Webhook
    if (function_exists('dispatch_webhook')) {
        dispatch_webhook('entry.created', ['id' => $itemId, 'collection_id' => $collection['id'], 'slug' => $slug, 'status' => $status]);
    }

    mcp_tool_result($id, ['success' => true, 'id' => $itemId, 'slug' => $slug, 'status' => $status]);
}

function mcp_tool_update_item(mixed $id, array $args): never {
    $itemId = (int) ($args['id'] ?? 0);
    if (!$itemId) mcp_tool_result($id, 'Error: id is required', true);

    $current = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
    if (!$current) mcp_tool_result($id, "Error: Item {$itemId} not found", true);

    $update = ['updated_at' => date('Y-m-d H:i:s')];

    if (isset($args['slug'])) {
        $newSlug = trim($args['slug']);
        if (!preg_match('/^[a-z0-9-]+$/', $newSlug)) {
            mcp_tool_result($id, 'Error: slug must be lowercase letters, numbers, and hyphens only', true);
        }
        $update['slug'] = $newSlug;
    }

    if (isset($args['data'])) {
        // Merge with existing data (partial update)
        $existingData = json_decode($current['data'], true) ?: [];
        $mergedData = array_merge($existingData, $args['data']);

        // Sanitize richtext fields based on collection schema
        $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE id = ?', [$current['collection_id']]);
        if ($collection) {
            $mergedData = mcp_sanitize_collection_data($collection, $mergedData);
        }

        $update['data'] = json_encode($mergedData);
    }

    if (isset($args['status']) && in_array($args['status'], ['draft', 'published', 'scheduled'])) {
        $update['status'] = $args['status'];
        if ($args['status'] === 'published' && !$current['published_at']) {
            $update['published_at'] = date('Y-m-d H:i:s');
        }
    }

    // Create revision snapshot
    if (function_exists('create_revision')) {
        create_revision('item', $itemId,
            json_decode($current['data'] ?? '{}', true) ?: [],
            ['slug' => $current['slug'], 'status' => $current['status']]
        );
    }

    OutpostDB::update('collection_items', $update, 'id = ?', [$itemId]);

    // Re-index for Compass
    if (file_exists(__DIR__ . '/compass.php')) {
        require_once __DIR__ . '/compass.php';
        if (function_exists('compass_index_item')) {
            compass_index_item($itemId);
        }
    }

    // Webhook
    if (function_exists('dispatch_webhook')) {
        dispatch_webhook('entry.updated', ['id' => $itemId, 'collection_id' => $current['collection_id']]);
    }

    mcp_tool_result($id, ['success' => true, 'id' => $itemId]);
}

function mcp_tool_delete_item(mixed $id, array $args): never {
    $itemId = (int) ($args['id'] ?? 0);
    if (!$itemId) mcp_tool_result($id, 'Error: id is required', true);

    $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
    if (!$item) mcp_tool_result($id, "Error: Item {$itemId} not found", true);

    OutpostDB::delete('collection_items', 'id = ?', [$itemId]);

    // Webhook
    if (function_exists('dispatch_webhook')) {
        dispatch_webhook('entry.deleted', ['id' => $itemId, 'collection_id' => $item['collection_id'], 'slug' => $item['slug']]);
    }

    mcp_tool_result($id, ['success' => true, 'deleted_id' => $itemId, 'slug' => $item['slug']]);
}

function mcp_tool_list_pages(mixed $id, array $args): never {
    $pages = OutpostDB::fetchAll(
        "SELECT id, path, title, meta_title, meta_description, visibility, updated_at
         FROM pages WHERE path != '__global__' ORDER BY path ASC"
    );
    mcp_tool_result($id, $pages);
}

function mcp_tool_get_page_fields(mixed $id, array $args): never {
    $path = $args['path'] ?? '';
    if ($path === '') mcp_tool_result($id, 'Error: path is required', true);

    $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE path = ?', [$path]);
    if (!$page) mcp_tool_result($id, "Error: Page '{$path}' not found", true);

    $fields = OutpostDB::fetchAll(
        "SELECT id, field_name, field_type, content, default_value, sort_order
         FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL)
         ORDER BY sort_order ASC",
        [(int) $page['id']]
    );

    mcp_tool_result($id, [
        'page' => ['id' => $page['id'], 'path' => $page['path'], 'title' => $page['title']],
        'fields' => $fields,
    ]);
}

function mcp_tool_update_page_fields(mixed $id, array $args): never {
    $path = $args['path'] ?? '';
    $fieldUpdates = $args['fields'] ?? [];

    if ($path === '') mcp_tool_result($id, 'Error: path is required', true);
    if (empty($fieldUpdates)) mcp_tool_result($id, 'Error: fields object is required', true);

    $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE path = ?', [$path]);
    if (!$page) mcp_tool_result($id, "Error: Page '{$path}' not found", true);

    $pageId = (int) $page['id'];
    $now = date('Y-m-d H:i:s');
    $updated = 0;

    foreach ($fieldUpdates as $fieldName => $value) {
        // Sanitize field name
        $fieldName = preg_replace('/[^a-zA-Z0-9_-]/', '', $fieldName);
        if (!$fieldName) continue;

        // Find existing field
        $field = OutpostDB::fetchOne(
            "SELECT * FROM fields WHERE page_id = ? AND field_name = ? AND (theme = '' OR theme IS NULL)",
            [$pageId, $fieldName]
        );

        // Ensure value is a string for DB storage
        $content = is_string($value) ? $value : json_encode($value);

        if ($field) {
            // Sanitize richtext
            if ($field['field_type'] === 'richtext') {
                $content = OutpostSanitizer::clean($content);
            }
            OutpostDB::update('fields', ['content' => $content, 'updated_at' => $now], 'id = ?', [$field['id']]);
            $updated++;
        } else {
            // Create field if it doesn't exist
            OutpostDB::insert('fields', [
                'page_id' => $pageId,
                'theme' => '',
                'field_name' => $fieldName,
                'field_type' => 'text',
                'content' => $content,
            ]);
            $updated++;
        }
    }

    // Bump page updated_at
    OutpostDB::update('pages', ['updated_at' => $now], 'id = ?', [$pageId]);

    // Clear cache
    if (function_exists('outpost_clear_cache')) {
        outpost_clear_cache($path);
    }

    mcp_tool_result($id, ['success' => true, 'updated' => $updated]);
}

function mcp_tool_get_globals(mixed $id, array $args): never {
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalPage) {
        mcp_tool_result($id, ['fields' => []]);
    }

    $fields = OutpostDB::fetchAll(
        "SELECT field_name, field_type, content FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
        [(int) $globalPage['id']]
    );

    $result = [];
    foreach ($fields as $f) {
        $result[$f['field_name']] = [
            'value' => $f['content'],
            'type' => $f['field_type'],
        ];
    }

    mcp_tool_result($id, $result);
}

function mcp_tool_update_globals(mixed $id, array $args): never {
    $fieldUpdates = $args['fields'] ?? [];
    if (empty($fieldUpdates)) mcp_tool_result($id, 'Error: fields object is required', true);

    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if (!$globalPage) mcp_tool_result($id, 'Error: Global page not found', true);

    $pageId = (int) $globalPage['id'];
    $now = date('Y-m-d H:i:s');
    $updated = 0;

    foreach ($fieldUpdates as $fieldName => $value) {
        $fieldName = preg_replace('/[^a-zA-Z0-9_-]/', '', $fieldName);
        if (!$fieldName) continue;

        $field = OutpostDB::fetchOne(
            "SELECT * FROM fields WHERE page_id = ? AND field_name = ? AND (theme = '' OR theme IS NULL)",
            [$pageId, $fieldName]
        );

        // Ensure value is a string for DB storage
        $content = is_string($value) ? $value : json_encode($value);

        if ($field) {
            if ($field['field_type'] === 'richtext') {
                $content = OutpostSanitizer::clean($content);
            }
            OutpostDB::update('fields', ['content' => $content, 'updated_at' => $now], 'id = ?', [$field['id']]);
            $updated++;
        }
    }

    // Clear all caches (globals affect every page)
    if (function_exists('outpost_clear_cache')) {
        outpost_clear_cache();
    }

    mcp_tool_result($id, ['success' => true, 'updated' => $updated]);
}

function mcp_tool_list_media(mixed $id, array $args): never {
    $search = trim($args['search'] ?? '');
    $limit = max(1, min((int) ($args['limit'] ?? 50), 100));
    $offset = max((int) ($args['offset'] ?? 0), 0);

    if ($search) {
        // Truncate search to reasonable length
        $search = mb_substr($search, 0, 200);
        $escapedSearch = '%' . mcp_like_escape($search) . '%';
        $media = OutpostDB::fetchAll(
            "SELECT id, filename, original_name, path, mime_type, file_size, width, height, alt_text, uploaded_at
             FROM media WHERE original_name LIKE ? ESCAPE '\\' OR alt_text LIKE ? ESCAPE '\\'
             ORDER BY uploaded_at DESC LIMIT ? OFFSET ?",
            [$escapedSearch, $escapedSearch, $limit, $offset]
        );
    } else {
        $media = OutpostDB::fetchAll(
            "SELECT id, filename, original_name, path, mime_type, file_size, width, height, alt_text, uploaded_at
             FROM media ORDER BY uploaded_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    $total = OutpostDB::fetchOne(
        $search
            ? "SELECT COUNT(*) as c FROM media WHERE original_name LIKE ? ESCAPE '\\' OR alt_text LIKE ? ESCAPE '\\'"
            : "SELECT COUNT(*) as c FROM media",
        $search ? [$escapedSearch, $escapedSearch] : []
    );

    mcp_tool_result($id, ['media' => $media, 'total' => (int) $total['c']]);
}

function mcp_tool_search_content(mixed $id, array $args): never {
    $query = trim($args['query'] ?? '');
    if (!$query) mcp_tool_result($id, 'Error: query is required', true);

    // Truncate search query to reasonable length
    $query = mb_substr($query, 0, 200);

    $collSlug = $args['collection'] ?? '';
    $limit = max(1, min((int) ($args['limit'] ?? 20), 50));
    $searchTerm = '%' . mcp_like_escape($query) . '%';

    $results = [];

    // Search collection items
    if ($collSlug) {
        // Validate collection slug format
        if (!preg_match('/^[a-z0-9_-]+$/', $collSlug)) {
            mcp_tool_result($id, 'Error: invalid collection slug format', true);
        }
        $coll = OutpostDB::fetchOne('SELECT id, slug, name FROM collections WHERE slug = ?', [$collSlug]);
        if ($coll) {
            $items = OutpostDB::fetchAll(
                "SELECT ci.*, c.slug as collection_slug, c.name as collection_name
                 FROM collection_items ci JOIN collections c ON ci.collection_id = c.id
                 WHERE ci.collection_id = ? AND (ci.slug LIKE ? ESCAPE '\\' OR ci.data LIKE ? ESCAPE '\\')
                 ORDER BY ci.updated_at DESC LIMIT ?",
                [$coll['id'], $searchTerm, $searchTerm, $limit]
            );
        } else {
            $items = [];
        }
    } else {
        $items = OutpostDB::fetchAll(
            "SELECT ci.*, c.slug as collection_slug, c.name as collection_name
             FROM collection_items ci JOIN collections c ON ci.collection_id = c.id
             WHERE ci.slug LIKE ? ESCAPE '\\' OR ci.data LIKE ? ESCAPE '\\'
             ORDER BY ci.updated_at DESC LIMIT ?",
            [$searchTerm, $searchTerm, $limit]
        );
    }

    foreach ($items as $item) {
        $data = json_decode($item['data'], true) ?: [];
        $results[] = [
            'type' => 'item',
            'id' => $item['id'],
            'slug' => $item['slug'],
            'status' => $item['status'],
            'collection' => $item['collection_slug'],
            'collection_name' => $item['collection_name'],
            'title' => $data['title'] ?? $item['slug'],
            'updated_at' => $item['updated_at'],
        ];
    }

    // Also search pages (non-collection)
    if (!$collSlug) {
        $pages = OutpostDB::fetchAll(
            "SELECT p.id, p.path, p.title FROM pages p
             WHERE p.path != '__global__' AND (p.path LIKE ? ESCAPE '\\' OR p.title LIKE ? ESCAPE '\\')
             ORDER BY p.updated_at DESC LIMIT ?",
            [$searchTerm, $searchTerm, $limit]
        );
        foreach ($pages as $page) {
            $results[] = [
                'type' => 'page',
                'id' => $page['id'],
                'path' => $page['path'],
                'title' => $page['title'],
            ];
        }
    }

    mcp_tool_result($id, ['results' => $results, 'query' => $query]);
}

function mcp_tool_get_schema(mixed $id, array $args): never {
    // Collections with fields
    $collections = OutpostDB::fetchAll('SELECT * FROM collections ORDER BY name ASC');
    $collectionData = [];
    foreach ($collections as $c) {
        $schema = json_decode($c['schema'] ?: '{}', true);
        $fields = [];
        $fieldList = $schema['fields'] ?? $schema;
        if (is_array($fieldList)) {
            foreach ($fieldList as $f) {
                if (isset($f['name'])) {
                    $fields[] = ['name' => $f['name'], 'type' => $f['type'] ?? 'text', 'label' => $f['label'] ?? $f['name']];
                }
            }
        }
        $folders = OutpostDB::fetchAll('SELECT slug, name, type FROM folders WHERE collection_id = ? ORDER BY name ASC', [$c['id']]);
        $count = OutpostDB::fetchOne('SELECT COUNT(*) as c FROM collection_items WHERE collection_id = ?', [$c['id']]);
        $collectionData[] = [
            'slug' => $c['slug'],
            'name' => $c['name'],
            'url_pattern' => $c['url_pattern'] ?: ('/' . $c['slug'] . '/{slug}'),
            'fields' => $fields,
            'folders' => $folders,
            'item_count' => (int) $count['c'],
        ];
    }

    // Pages
    $pages = OutpostDB::fetchAll("SELECT id, path, title FROM pages WHERE path != '__global__' ORDER BY path ASC");
    $pageData = [];
    foreach ($pages as $p) {
        $fields = OutpostDB::fetchAll(
            "SELECT field_name, field_type FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
            [(int) $p['id']]
        );
        $pageData[] = [
            'path' => $p['path'],
            'title' => $p['title'],
            'fields' => $fields,
        ];
    }

    // Globals
    $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    $globals = [];
    if ($globalPage) {
        $globalFields = OutpostDB::fetchAll(
            "SELECT field_name, field_type, content FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
            [(int) $globalPage['id']]
        );
        foreach ($globalFields as $f) {
            $globals[] = ['name' => $f['field_name'], 'type' => $f['field_type'], 'value' => $f['content']];
        }
    }

    mcp_tool_result($id, [
        'collections' => $collectionData,
        'pages' => $pageData,
        'globals' => $globals,
    ]);
}

// ═══════════════════════════════════════════════════════════
// RESOURCES
// ═══════════════════════════════════════════════════════════

function handle_mcp_resources_list(mixed $id): never {
    mcp_response($id, [
        'resources' => [
            [
                'uri' => 'outpost://schema',
                'name' => 'Site Schema',
                'description' => 'Complete site content schema: collections, fields, pages, globals, folders',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => 'outpost://pages',
                'name' => 'Pages List',
                'description' => 'All site pages with paths, titles, and field definitions',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => 'outpost://globals',
                'name' => 'Global Fields',
                'description' => 'All global site settings and field values',
                'mimeType' => 'application/json',
            ],
        ],
    ]);
}

function handle_mcp_resource_templates_list(mixed $id): never {
    mcp_response($id, [
        'resourceTemplates' => [
            [
                'uriTemplate' => 'outpost://collections/{slug}',
                'name' => 'Collection Detail',
                'description' => 'Schema and recent items for a specific collection',
                'mimeType' => 'application/json',
            ],
        ],
    ]);
}

function handle_mcp_resources_read(mixed $id, array $params): never {
    $uri = $params['uri'] ?? '';

    if ($uri === 'outpost://schema') {
        // Reuse the schema tool
        mcp_resource_schema($id, $uri);
    } elseif ($uri === 'outpost://pages') {
        mcp_resource_pages($id, $uri);
    } elseif ($uri === 'outpost://globals') {
        mcp_resource_globals($id, $uri);
    } elseif (preg_match('#^outpost://collections/([a-z0-9-]+)$#', $uri, $m)) {
        mcp_resource_collection($id, $uri, $m[1]);
    } else {
        mcp_error($id, MCP_ERR_PARAMS, "Unknown resource URI: {$uri}");
    }
}

function mcp_resource_schema(mixed $id, string $uri): never {
    // Reuse get_schema logic
    $collections = OutpostDB::fetchAll('SELECT * FROM collections ORDER BY name ASC');
    $collectionData = [];
    foreach ($collections as $c) {
        $schema = json_decode($c['schema'] ?: '{}', true);
        $fields = [];
        $fieldList = $schema['fields'] ?? $schema;
        if (is_array($fieldList)) {
            foreach ($fieldList as $f) {
                if (isset($f['name'])) {
                    $fields[] = ['name' => $f['name'], 'type' => $f['type'] ?? 'text'];
                }
            }
        }
        $collectionData[] = ['slug' => $c['slug'], 'name' => $c['name'], 'fields' => $fields];
    }

    $pages = OutpostDB::fetchAll("SELECT path, title FROM pages WHERE path != '__global__' ORDER BY path ASC");

    $globals = [];
    $gp = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    if ($gp) {
        $gf = OutpostDB::fetchAll("SELECT field_name, field_type FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL)", [(int) $gp['id']]);
        foreach ($gf as $f) $globals[] = ['name' => $f['field_name'], 'type' => $f['field_type']];
    }

    mcp_response($id, [
        'contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode(['collections' => $collectionData, 'pages' => $pages, 'globals' => $globals], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]],
    ]);
}

function mcp_resource_pages(mixed $id, string $uri): never {
    $pages = OutpostDB::fetchAll(
        "SELECT id, path, title, meta_title, meta_description, visibility, updated_at
         FROM pages WHERE path != '__global__' ORDER BY path ASC"
    );
    mcp_response($id, [
        'contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]],
    ]);
}

function mcp_resource_globals(mixed $id, string $uri): never {
    $gp = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
    $globals = [];
    if ($gp) {
        $fields = OutpostDB::fetchAll(
            "SELECT field_name, field_type, content FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
            [(int) $gp['id']]
        );
        foreach ($fields as $f) {
            $globals[$f['field_name']] = ['value' => $f['content'], 'type' => $f['field_type']];
        }
    }
    mcp_response($id, [
        'contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode($globals, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]],
    ]);
}

function mcp_resource_collection(mixed $id, string $uri, string $slug): never {
    $c = OutpostDB::fetchOne('SELECT * FROM collections WHERE slug = ?', [$slug]);
    if (!$c) mcp_error($id, MCP_ERR_PARAMS, "Collection '{$slug}' not found");

    $schema = json_decode($c['schema'] ?: '{}', true);
    $fields = [];
    $fieldList = $schema['fields'] ?? $schema;
    if (is_array($fieldList)) {
        foreach ($fieldList as $f) {
            if (isset($f['name'])) $fields[] = $f;
        }
    }

    $items = OutpostDB::fetchAll(
        "SELECT * FROM collection_items WHERE collection_id = ? ORDER BY updated_at DESC LIMIT 20",
        [$c['id']]
    );
    foreach ($items as &$item) {
        $item['data'] = json_decode($item['data'], true) ?: [];
    }

    mcp_response($id, [
        'contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode([
                'slug' => $c['slug'],
                'name' => $c['name'],
                'fields' => $fields,
                'recent_items' => $items,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]],
    ]);
}

// ── Block introspection (v6 Track 3) ─────────────────────

function mcp_tool_list_blocks(mixed $id, array $args): never {
    $blocks = outpost_scan_blocks();
    $out = [];
    foreach ($blocks as $b) {
        $out[] = [
            'slug'        => $b['slug'],
            'name'        => $b['name'],
            'description' => $b['description'],
            'category'    => $b['category'],
            'icon'        => $b['icon'],
            'fields'      => $b['fields'],
        ];
    }
    mcp_tool_result($id, [
        'theme'  => outpost_get_active_theme(),
        'count'  => count($out),
        'blocks' => $out,
    ]);
}

function mcp_tool_get_block_schema(mixed $id, array $args): never {
    $slug = $args['slug'] ?? '';
    if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
        mcp_tool_result($id, 'Error: invalid slug. Use lowercase letters, digits, hyphens, underscores only.', true);
    }
    $block = outpost_get_block($slug);
    if ($block === null) {
        mcp_tool_result($id, "Error: block '{$slug}' not found in active theme.", true);
    }
    mcp_tool_result($id, [
        'slug'        => $block['slug'],
        'name'        => $block['name'],
        'description' => $block['description'],
        'category'    => $block['category'],
        'icon'        => $block['icon'],
        'fields'      => $block['fields'],
        'has_css'     => $block['has_css'],
    ]);
}

// ── Channels (v6 Track 3) ────────────────────────────────

/**
 * Shape one channels-table row into the public MCP envelope.
 * Pulls the field_map JSON column out as the channel's "fields" schema.
 * Pulls a description out of config JSON if present.
 * Strips encrypted auth credentials so they never leak through MCP.
 */
function _mcp_shape_channel(array $row): array {
    $config = json_decode($row['config'] ?? '{}', true);
    if (!is_array($config)) $config = [];
    // Never expose auth credentials (even encrypted) over MCP
    if (isset($config['auth_config'])) unset($config['auth_config']);

    $fieldMap = json_decode($row['field_map'] ?? '[]', true);
    if (!is_array($fieldMap)) $fieldMap = [];

    return [
        'slug'           => $row['slug'],
        'name'           => $row['name'],
        'description'    => is_string($config['description'] ?? null) ? $config['description'] : '',
        'type'           => $row['type'] ?? 'api',
        'status'         => $row['status'] ?? 'active',
        'url_pattern'    => $row['url_pattern'] ?? null,
        'sort_field'     => $row['sort_field'] ?? null,
        'sort_direction' => $row['sort_direction'] ?? 'desc',
        'cache_ttl'      => isset($row['cache_ttl']) ? (int) $row['cache_ttl'] : null,
        'max_items'      => isset($row['max_items']) ? (int) $row['max_items'] : null,
        'last_sync_at'   => $row['last_sync_at'] ?? null,
        'fields'         => $fieldMap,
        'config'         => $config,
    ];
}

function mcp_tool_list_channels(mixed $id, array $args): never {
    try {
        $rows = OutpostDB::fetchAll(
            'SELECT * FROM channels WHERE status = ? ORDER BY name ASC',
            ['active']
        );
    } catch (\Throwable $e) {
        // Channels table may not exist yet (channels is a feature flag-able subsystem)
        mcp_tool_result($id, ['count' => 0, 'channels' => []]);
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = _mcp_shape_channel($row);
    }
    mcp_tool_result($id, ['count' => count($out), 'channels' => $out]);
}

function mcp_tool_get_channel_schema(mixed $id, array $args): never {
    $slug = $args['slug'] ?? '';
    if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
        mcp_tool_result($id, 'Error: invalid slug. Use lowercase letters, digits, hyphens, underscores only.', true);
    }

    try {
        $row = OutpostDB::fetchOne('SELECT * FROM channels WHERE slug = ?', [$slug]);
    } catch (\Throwable $e) {
        mcp_tool_result($id, "Error: channels subsystem is not initialized.", true);
    }

    if (!$row) {
        mcp_tool_result($id, "Error: channel '{$slug}' not found.", true);
    }

    mcp_tool_result($id, _mcp_shape_channel($row));
}

// ── Templates (v6 Track 3) ───────────────────────────────

/**
 * Return the templates available in the active theme.
 * Order of preference for the source of truth:
 *   1. theme.json (or blueprint.json) "templates" array.
 *   2. files in {themeDir}/templates/*.html
 *   3. files in {themeDir}/*.html (excluding partials/blocks/styles dirs)
 */
function mcp_tool_list_templates(mixed $id, array $args): never {
    $theme = outpost_get_active_theme();
    $themeDir = OUTPOST_THEMES_DIR . $theme . '/';

    if (!is_dir($themeDir)) {
        mcp_tool_result($id, [
            'theme'     => $theme,
            'source'    => 'none',
            'count'     => 0,
            'templates' => [],
        ]);
    }

    // 1. Try theme.json or blueprint.json's templates[] array first
    $manifestFiles = ['theme.json', 'blueprint.json'];
    foreach ($manifestFiles as $manifestFile) {
        $manifestPath = $themeDir . $manifestFile;
        if (!file_exists($manifestPath)) continue;
        $raw = file_get_contents($manifestPath);
        if ($raw === false) continue;
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) continue;
        if (!isset($manifest['templates']) || !is_array($manifest['templates'])) continue;

        $out = [];
        foreach ($manifest['templates'] as $t) {
            if (!is_array($t)) continue;
            $slug = is_string($t['slug'] ?? null) ? $t['slug'] : '';
            if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) continue;
            $entry = [
                'slug' => $slug,
                'name' => is_string($t['name'] ?? null) ? $t['name'] : ucwords(str_replace(['-', '_'], ' ', $slug)),
            ];
            if (isset($t['description']) && is_string($t['description'])) {
                $entry['description'] = $t['description'];
            }
            $out[] = $entry;
        }
        if (!empty($out)) {
            mcp_tool_result($id, [
                'theme'     => $theme,
                'source'    => $manifestFile,
                'count'     => count($out),
                'templates' => $out,
            ]);
        }
    }

    // 2. Scan {theme}/templates/*.html
    $templatesDir = $themeDir . 'templates/';
    if (is_dir($templatesDir)) {
        $files = glob($templatesDir . '*.html') ?: [];
        $out = [];
        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) continue;
            $out[] = [
                'slug' => $slug,
                'name' => ucwords(str_replace(['-', '_'], ' ', $slug)),
            ];
        }
        if (!empty($out)) {
            usort($out, fn($a, $b) => strcmp($a['slug'], $b['slug']));
            mcp_tool_result($id, [
                'theme'     => $theme,
                'source'    => 'templates-dir',
                'count'     => count($out),
                'templates' => $out,
            ]);
        }
    }

    // 3. Fallback: scan theme root for .html files (legacy flat layout)
    $files = glob($themeDir . '*.html') ?: [];
    $out = [];
    foreach ($files as $file) {
        $slug = pathinfo($file, PATHINFO_FILENAME);
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) continue;
        $out[] = [
            'slug' => $slug,
            'name' => ucwords(str_replace(['-', '_'], ' ', $slug)),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['slug'], $b['slug']));

    mcp_tool_result($id, [
        'theme'     => $theme,
        'source'    => 'theme-root',
        'count'     => count($out),
        'templates' => $out,
    ]);
}

// ── Page composition (v6 Track 3 — blocked on data model) ─

/**
 * Shared error returned by compose_page, add_block_to_page, set_block_field.
 *
 * Outpost's current page model:
 *   - `pages` table: id, path, title, meta_*, timestamps. No blocks column.
 *   - `fields` table: per-page key/value field content keyed by `page_id` +
 *     `field_name` (the data-outpost attribute on the rendered template).
 *   - Pages render from filesystem .html template files in the active theme,
 *     not from a DB-backed list of block instances.
 *
 * The block-as-page-composition model (Sites/Shopify-style ordered block
 * instances per page) has not been added yet. Until a `page_blocks` (or
 * equivalent) table lands and the engine is taught to render from it, these
 * three tools return this explicit error rather than guess at a schema.
 *
 * Workaround for now: callers can already (a) edit a template file directly,
 * or (b) use update_page_fields to set per-page field values, or (c) write
 * block-level theme settings via the `setting_{block}_{name}` field_name
 * convention used by handle_editor_block_settings_save in smart-forge.php.
 */
function _mcp_page_blocks_unimplemented_error(): string {
    return 'Error: page-as-blocks composition is not yet supported. Outpost stores per-page values in the fields table keyed by data-outpost field names, not as an ordered list of block instances. A page_blocks data model is required before compose_page / add_block_to_page / set_block_field can be implemented. Use update_page_fields for per-field edits in the meantime.';
}

function mcp_tool_compose_page(mixed $id, array $args): never {
    // Validate inputs first so the client gets useful feedback even though
    // the operation can't complete yet.
    $title = is_string($args['title'] ?? null) ? trim($args['title']) : '';
    $blocks = $args['blocks'] ?? null;

    if ($title === '') {
        mcp_tool_result($id, 'Error: title is required', true);
    }
    if (!is_array($blocks)) {
        mcp_tool_result($id, 'Error: blocks must be an array', true);
    }
    if (isset($args['slug']) && is_string($args['slug']) && $args['slug'] !== ''
        && !preg_match('/^[a-z0-9-]+$/', $args['slug'])) {
        mcp_tool_result($id, 'Error: slug must be lowercase letters, numbers, and hyphens only', true);
    }
    if (isset($args['template']) && is_string($args['template']) && $args['template'] !== ''
        && !preg_match('/^[a-z0-9_-]+$/', $args['template'])) {
        mcp_tool_result($id, 'Error: invalid template slug', true);
    }

    // TODO(v6): implement once the page_blocks data model + engine renderer lands.
    mcp_tool_result($id, _mcp_page_blocks_unimplemented_error(), true);
}

function mcp_tool_add_block_to_page(mixed $id, array $args): never {
    $pageId = (int) ($args['page_id'] ?? 0);
    $blockSlug = $args['block_slug'] ?? '';

    if (!$pageId) {
        mcp_tool_result($id, 'Error: page_id is required', true);
    }
    if (!is_string($blockSlug) || !preg_match('/^[a-z0-9_-]+$/', $blockSlug)) {
        mcp_tool_result($id, 'Error: block_slug is required and must be lowercase letters, digits, hyphens, underscores only', true);
    }

    // TODO(v6): implement once the page_blocks data model lands.
    mcp_tool_result($id, _mcp_page_blocks_unimplemented_error(), true);
}

function mcp_tool_set_block_field(mixed $id, array $args): never {
    $pageId = (int) ($args['page_id'] ?? 0);
    $blockId = (int) ($args['block_id'] ?? 0);
    $fieldKey = $args['field_key'] ?? '';

    if (!$pageId) {
        mcp_tool_result($id, 'Error: page_id is required', true);
    }
    if (!$blockId) {
        mcp_tool_result($id, 'Error: block_id is required', true);
    }
    if (!is_string($fieldKey) || !preg_match('/^[a-zA-Z0-9_-]+$/', $fieldKey)) {
        mcp_tool_result($id, 'Error: field_key must be alphanumeric with hyphens or underscores only', true);
    }
    if (!array_key_exists('value', $args)) {
        mcp_tool_result($id, 'Error: value is required', true);
    }

    // TODO(v6): implement once the page_blocks data model lands.
    mcp_tool_result($id, _mcp_page_blocks_unimplemented_error(), true);
}
