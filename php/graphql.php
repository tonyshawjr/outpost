<?php
/**
 * Outpost CMS — GraphQL API
 * Public read API + authenticated mutations. No external dependencies.
 *
 * Queries (Query type)    — no auth required, like the REST Content API
 * Mutations (Mutation type) — require Authorization: Bearer op_xxx API key
 *
 * Endpoint: POST /outpost/graphql.php
 * Body: {"query": "...", "variables": {}}
 */

// ── Suppress PHP warnings/notices from leaking into JSON output ──
ob_start();
ini_set('display_errors', '0');

// ── Bootstrap (loaded early for CORS settings lookup) ───
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http-security.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/auth.php';

// ── CORS & headers ──────────────────────────────────────
$_gql_cors_origins = null;
try {
    $_gql_cors_row = OutpostDB::fetchOne("SELECT value FROM settings WHERE key = 'api_cors_origins'");
    if ($_gql_cors_row && $_gql_cors_row['value']) $_gql_cors_origins = $_gql_cors_row['value'];
} catch (\Throwable $e) {}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    $isLocalDev = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin);

    if ($isLocalDev) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    } elseif ($_gql_cors_origins === '*') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
    } elseif ($_gql_cors_origins) {
        $allowed = array_map('trim', explode(',', $_gql_cors_origins));
        if (in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }
    }
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
} elseif ($_gql_cors_origins === '*') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Rate limit: 120 requests per 60 seconds per IP
outpost_ip_rate_limit('graphql_api', 120, 60);

// Ensure folder/label tables exist (same migration as content-api.php)
graphql_ensure_tables();

// ── Read input ──────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput ?: '', true);

// Support GET requests for simple queries (e.g. GraphQL Playground introspection)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = [
        'query' => $_GET['query'] ?? '',
        'variables' => isset($_GET['variables']) ? json_decode($_GET['variables'], true) : [],
        'operationName' => $_GET['operationName'] ?? null,
    ];
}

$queryString = trim($input['query'] ?? '');
$variables = $input['variables'] ?? [];
$operationName = $input['operationName'] ?? null;

if ($queryString === '') {
    graphql_respond(['errors' => [['message' => 'No query provided']]]);
}

// ── Security: cap query size to prevent parser DoS ─────
if (strlen($queryString) > 32768) {
    graphql_respond(['errors' => [['message' => 'Query too large (max 32 KB)']]]);
}

// ── Execute ─────────────────────────────────────────────
try {
    $parser = new OutpostGraphQLParser();
    $document = $parser->parse($queryString);

    // ── Security: enforce query depth + selection count limits ──
    $maxDepth = 10;
    $maxSelections = 500;
    $selCount = 0;
    foreach ($document['operations'] as $op) {
        graphql_check_depth($op['selections'] ?? [], $document['fragments'] ?? [], 1, $maxDepth, $selCount);
        if ($selCount > $maxSelections) {
            graphql_respond(['errors' => [['message' => "Query too complex: exceeds {$maxSelections} field selections"]]]);
        }
    }

    // Resolve operation
    $operation = null;
    if ($operationName) {
        foreach ($document['operations'] as $op) {
            if (($op['name'] ?? '') === $operationName) {
                $operation = $op;
                break;
            }
        }
        if (!$operation) {
            graphql_respond(['errors' => [['message' => "Operation '{$operationName}' not found"]]]);
        }
    } else {
        $operation = $document['operations'][0] ?? null;
    }

    if (!$operation) {
        graphql_respond(['errors' => [['message' => 'No operation found in query']]]);
    }

    // Check for introspection
    $selections = $operation['selections'] ?? [];
    $isIntrospection = false;
    foreach ($selections as $sel) {
        if (($sel['name'] ?? '') === '__schema' || ($sel['name'] ?? '') === '__type') {
            $isIntrospection = true;
            break;
        }
    }

    if ($isIntrospection) {
        $data = resolve_introspection($selections, $document['fragments'] ?? []);
        graphql_respond(['data' => $data]);
    }

    // Resolve variables from operation variable definitions
    $resolvedVars = [];
    foreach ($operation['variables'] ?? [] as $varDef) {
        $varName = $varDef['name'];
        if (array_key_exists($varName, $variables)) {
            $resolvedVars[$varName] = $variables[$varName];
        } elseif (isset($varDef['default'])) {
            $resolvedVars[$varName] = $varDef['default'];
        }
    }

    // Mutations require authentication
    $isMutation = ($operation['type'] ?? 'query') === 'mutation';
    if ($isMutation) {
        if (!graphql_authenticate()) {
            graphql_respond(['errors' => [['message' => 'Authentication required. Provide Authorization: Bearer <api_key> header.']]]);
        }
    }

    $executor = new OutpostGraphQLExecutor($resolvedVars, $document['fragments'] ?? [], $isMutation);
    $data = $executor->resolve($selections);

    graphql_respond(['data' => $data]);

} catch (OutpostGraphQLError $e) {
    graphql_respond(['errors' => [['message' => $e->getMessage()]]]);
} catch (\Throwable $e) {
    error_log('Outpost GraphQL error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    graphql_respond(['errors' => [['message' => 'Internal server error']]]);
}

// ── Helpers ─────────────────────────────────────────────
/**
 * Recursively check query depth and count total selections to prevent DoS.
 */
function graphql_check_depth(array $selections, array $fragments, int $depth, int $maxDepth, int &$count = 0, array &$visitedFragments = []): void {
    if ($depth > $maxDepth) {
        graphql_respond(['errors' => [['message' => "Query depth exceeds maximum of {$maxDepth}"]]]);
    }
    foreach ($selections as $sel) {
        $count++;
        if (($sel['type'] ?? '') === 'fragment_spread') {
            $fragName = $sel['name'];
            // Prevent circular fragment references
            if (in_array($fragName, $visitedFragments, true)) {
                graphql_respond(['errors' => [['message' => "Circular fragment reference: '{$fragName}'"]]]);
            }
            if (isset($fragments[$fragName])) {
                $visited = $visitedFragments;
                $visited[] = $fragName;
                graphql_check_depth($fragments[$fragName]['selections'] ?? [], $fragments, $depth, $maxDepth, $count, $visited);
            }
            continue;
        }
        if (!empty($sel['selections'])) {
            graphql_check_depth($sel['selections'], $fragments, $depth + 1, $maxDepth, $count, $visitedFragments);
        }
    }
}

function graphql_respond(array $payload): void {
    // Discard any buffered PHP warnings/notices before sending clean JSON
    if (ob_get_level()) ob_end_clean();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Authenticate via Bearer API key. Returns true if authenticated.
 */
function graphql_authenticate(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return false;
    }
    $providedKey = $m[1];
    $prefix = substr($providedKey, 0, 11);

    $keys = OutpostDB::fetchAll('SELECT * FROM api_keys WHERE key_prefix = ?', [$prefix]);
    foreach ($keys as $row) {
        if (password_verify($providedKey, $row['key_hash'])) {
            $user = OutpostDB::fetchOne('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
            if (!$user || !outpost_is_internal_role($user['role'])) {
                return false;
            }
            // Populate session so role/grant capability checks work for GraphQL auth
            $_SESSION['outpost_user_id'] = $user['id'];
            $_SESSION['outpost_role'] = $user['role'];
            OutpostDB::update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
            return true;
        }
    }
    return false;
}

function graphql_ensure_tables(): void {
    $db = OutpostDB::connect();
    $db->exec("
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            type TEXT DEFAULT 'flat',
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(collection_id, slug),
            FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS labels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(folder_id, slug),
            FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS item_labels (
            item_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            PRIMARY KEY (item_id, label_id),
            FOREIGN KEY (item_id) REFERENCES collection_items(id) ON DELETE CASCADE,
            FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            items TEXT NOT NULL DEFAULT '[]',
            updated_at TEXT DEFAULT (datetime('now'))
        );
    ");

    // Migration columns
    $cols = $db->query("PRAGMA table_info(folders)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('schema', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN schema TEXT DEFAULT '[]'");
    }
    if (!in_array('description', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN description TEXT DEFAULT ''");
    }
    if (!in_array('singular_name', $colNames)) {
        $db->exec("ALTER TABLE folders ADD COLUMN singular_name TEXT DEFAULT ''");
    }

    $labelCols = $db->query("PRAGMA table_info(labels)")->fetchAll();
    $labelColNames = array_column($labelCols, 'name');
    if (!in_array('data', $labelColNames)) {
        $db->exec("ALTER TABLE labels ADD COLUMN data TEXT DEFAULT '{}'");
    }
    if (!in_array('description', $labelColNames)) {
        $db->exec("ALTER TABLE labels ADD COLUMN description TEXT DEFAULT ''");
    }
}

// ══════════════════════════════════════════════════════════
// GraphQL Parser — recursive descent, zero dependencies
// ══════════════════════════════════════════════════════════

class OutpostGraphQLError extends \RuntimeException {}

class OutpostGraphQLParser {
    private string $source;
    private int $pos;
    private int $len;

    public function parse(string $source): array {
        $this->source = $source;
        $this->pos = 0;
        $this->len = strlen($source);

        $operations = [];
        $fragments = [];

        $this->skipWhitespaceAndComments();

        while ($this->pos < $this->len) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= $this->len) break;

            // Check if it starts with a keyword or is an anonymous query
            if ($this->peek() === '{') {
                // Anonymous query
                $selections = $this->parseSelectionSet();
                $operations[] = [
                    'type' => 'query',
                    'name' => null,
                    'variables' => [],
                    'selections' => $selections,
                ];
            } else {
                $word = $this->readName();
                if ($word === 'query' || $word === 'mutation' || $word === 'subscription') {
                    $operations[] = $this->parseOperation($word);
                } elseif ($word === 'fragment') {
                    $frag = $this->parseFragment();
                    $fragments[$frag['name']] = $frag;
                } else {
                    throw new OutpostGraphQLError("Unexpected token: '{$word}'");
                }
            }

            $this->skipWhitespaceAndComments();
        }

        return ['operations' => $operations, 'fragments' => $fragments];
    }

    private function parseOperation(string $type): array {
        $this->skipWhitespaceAndComments();

        $name = null;
        $variables = [];

        // Optional operation name
        if ($this->pos < $this->len && $this->peek() !== '(' && $this->peek() !== '{') {
            $name = $this->readName();
        }

        $this->skipWhitespaceAndComments();

        // Optional variable definitions
        if ($this->pos < $this->len && $this->peek() === '(') {
            $variables = $this->parseVariableDefinitions();
        }

        $this->skipWhitespaceAndComments();
        $selections = $this->parseSelectionSet();

        return [
            'type' => $type,
            'name' => $name,
            'variables' => $variables,
            'selections' => $selections,
        ];
    }

    private function parseVariableDefinitions(): array {
        $this->expect('(');
        $vars = [];

        while ($this->peek() !== ')') {
            $this->skipWhitespaceAndComments();
            $this->expect('$');
            $varName = $this->readName();
            $this->skipWhitespaceAndComments();
            $this->expect(':');
            $this->skipWhitespaceAndComments();
            $typeName = $this->readType();

            $default = null;
            $this->skipWhitespaceAndComments();
            if ($this->pos < $this->len && $this->peek() === '=') {
                $this->pos++; // skip =
                $this->skipWhitespaceAndComments();
                $default = $this->readValue();
            }

            $vars[] = ['name' => $varName, 'type' => $typeName, 'default' => $default];

            $this->skipWhitespaceAndComments();
            if ($this->peek() === ',') $this->pos++;
        }

        $this->expect(')');
        return $vars;
    }

    private function parseFragment(): array {
        $this->skipWhitespaceAndComments();
        $name = $this->readName();
        $this->skipWhitespaceAndComments();

        // Expect 'on'
        $on = $this->readName();
        if ($on !== 'on') {
            throw new OutpostGraphQLError("Expected 'on' in fragment definition, got '{$on}'");
        }

        $this->skipWhitespaceAndComments();
        $typeName = $this->readName();
        $this->skipWhitespaceAndComments();
        $selections = $this->parseSelectionSet();

        return ['name' => $name, 'type' => $typeName, 'selections' => $selections];
    }

    private function parseSelectionSet(): array {
        $this->skipWhitespaceAndComments();
        $this->expect('{');
        $selections = [];

        while (true) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= $this->len) break;
            if ($this->peek() === '}') break;

            // Fragment spread
            if ($this->peek() === '.' && $this->pos + 2 < $this->len
                && $this->source[$this->pos + 1] === '.' && $this->source[$this->pos + 2] === '.') {
                $this->pos += 3;
                $this->skipWhitespaceAndComments();
                $fragName = $this->readName();
                $selections[] = ['type' => 'fragment_spread', 'name' => $fragName];
                continue;
            }

            $selections[] = $this->parseField();
        }

        $this->expect('}');
        return $selections;
    }

    private function parseField(): array {
        $this->skipWhitespaceAndComments();
        $nameOrAlias = $this->readName();
        $this->skipWhitespaceAndComments();

        $alias = null;
        $name = $nameOrAlias;

        // Check for alias
        if ($this->pos < $this->len && $this->peek() === ':') {
            $this->pos++; // skip :
            $this->skipWhitespaceAndComments();
            $alias = $nameOrAlias;
            $name = $this->readName();
            $this->skipWhitespaceAndComments();
        }

        // Arguments
        $args = [];
        if ($this->pos < $this->len && $this->peek() === '(') {
            $args = $this->parseArguments();
        }

        // Sub-selection
        $this->skipWhitespaceAndComments();
        $selections = [];
        if ($this->pos < $this->len && $this->peek() === '{') {
            $selections = $this->parseSelectionSet();
        }

        return [
            'type' => 'field',
            'name' => $name,
            'alias' => $alias,
            'arguments' => $args,
            'selections' => $selections,
        ];
    }

    private function parseArguments(): array {
        $this->expect('(');
        $args = [];

        while ($this->peek() !== ')') {
            $this->skipWhitespaceAndComments();
            $argName = $this->readName();
            $this->skipWhitespaceAndComments();
            $this->expect(':');
            $this->skipWhitespaceAndComments();
            $argValue = $this->readValue();
            $args[$argName] = $argValue;

            $this->skipWhitespaceAndComments();
            if ($this->peek() === ',') $this->pos++;
        }

        $this->expect(')');
        return $args;
    }

    private function readValue(): mixed {
        $this->skipWhitespaceAndComments();
        $ch = $this->peek();

        // Variable reference
        if ($ch === '$') {
            $this->pos++;
            return ['__var' => $this->readName()];
        }

        // String
        if ($ch === '"') {
            return $this->readString();
        }

        // Array
        if ($ch === '[') {
            return $this->readArray();
        }

        // Object
        if ($ch === '{') {
            return $this->readObject();
        }

        // Number, boolean, null, enum
        $word = $this->readName();
        if ($word === 'true') return true;
        if ($word === 'false') return false;
        if ($word === 'null') return null;
        if (is_numeric($word)) return strpos($word, '.') !== false ? (float)$word : (int)$word;

        // Enum value or bare name
        return $word;
    }

    private function readString(): string {
        $this->expect('"');
        $result = '';
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\') {
                $this->pos++;
                $next = $this->source[$this->pos] ?? '';
                switch ($next) {
                    case '"': $result .= '"'; break;
                    case '\\': $result .= '\\'; break;
                    case '/': $result .= '/'; break;
                    case 'n': $result .= "\n"; break;
                    case 'r': $result .= "\r"; break;
                    case 't': $result .= "\t"; break;
                    default: $result .= $next;
                }
                $this->pos++;
            } elseif ($ch === '"') {
                $this->pos++;
                return $result;
            } else {
                $result .= $ch;
                $this->pos++;
            }
        }
        throw new OutpostGraphQLError('Unterminated string');
    }

    private function readArray(): array {
        $this->expect('[');
        $items = [];
        while ($this->peek() !== ']') {
            $this->skipWhitespaceAndComments();
            $items[] = $this->readValue();
            $this->skipWhitespaceAndComments();
            if ($this->peek() === ',') $this->pos++;
        }
        $this->expect(']');
        return $items;
    }

    private function readObject(): array {
        $this->expect('{');
        $obj = [];
        while ($this->peek() !== '}') {
            $this->skipWhitespaceAndComments();
            $key = $this->readName();
            $this->skipWhitespaceAndComments();
            $this->expect(':');
            $this->skipWhitespaceAndComments();
            $obj[$key] = $this->readValue();
            $this->skipWhitespaceAndComments();
            if ($this->peek() === ',') $this->pos++;
        }
        $this->expect('}');
        return $obj;
    }

    private function readName(): string {
        $this->skipWhitespaceAndComments();
        $start = $this->pos;
        while ($this->pos < $this->len && preg_match('/[a-zA-Z0-9_]/', $this->source[$this->pos])) {
            $this->pos++;
        }
        if ($this->pos === $start) {
            $ch = $this->pos < $this->len ? $this->source[$this->pos] : 'EOF';
            throw new OutpostGraphQLError("Expected a name, got '{$ch}' at position {$this->pos}");
        }
        // Handle negative numbers
        $name = substr($this->source, $start, $this->pos - $start);
        // Also consume a dot for floats if preceded by digits
        if ($this->pos < $this->len && $this->source[$this->pos] === '.' && is_numeric($name)) {
            $this->pos++;
            while ($this->pos < $this->len && ctype_digit($this->source[$this->pos])) {
                $this->pos++;
            }
            $name = substr($this->source, $start, $this->pos - $start);
        }
        return $name;
    }

    private function readType(): string {
        $this->skipWhitespaceAndComments();
        $type = '';
        if ($this->peek() === '[') {
            $type .= '[';
            $this->pos++;
            $type .= $this->readType();
            $this->skipWhitespaceAndComments();
            if ($this->peek() === '!') { $type .= '!'; $this->pos++; }
            $this->expect(']');
            $type .= ']';
        } else {
            $type = $this->readName();
        }
        $this->skipWhitespaceAndComments();
        if ($this->pos < $this->len && $this->peek() === '!') {
            $type .= '!';
            $this->pos++;
        }
        return $type;
    }

    private function peek(): string {
        return $this->pos < $this->len ? $this->source[$this->pos] : '';
    }

    private function expect(string $ch): void {
        $this->skipWhitespaceAndComments();
        if ($this->pos >= $this->len || $this->source[$this->pos] !== $ch) {
            $got = $this->pos < $this->len ? $this->source[$this->pos] : 'EOF';
            throw new OutpostGraphQLError("Expected '{$ch}', got '{$got}' at position {$this->pos}");
        }
        $this->pos++;
    }

    private function skipWhitespaceAndComments(): void {
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === ',') {
                $this->pos++;
            } elseif ($ch === '#') {
                // Line comment
                while ($this->pos < $this->len && $this->source[$this->pos] !== "\n") {
                    $this->pos++;
                }
            } else {
                break;
            }
        }
    }
}

// ══════════════════════════════════════════════════════════
// GraphQL Executor — resolves fields against CMS data
// ══════════════════════════════════════════════════════════

class OutpostGraphQLExecutor {
    private array $variables;
    private array $fragments;
    private bool $isMutation;

    public function __construct(array $variables, array $fragments, bool $isMutation = false) {
        $this->variables = $variables;
        $this->fragments = $fragments;
        $this->isMutation = $isMutation;
    }

    public function resolve(array $selections): array {
        $result = [];

        foreach ($selections as $sel) {
            if ($sel['type'] === 'fragment_spread') {
                $fragName = $sel['name'];
                if (!isset($this->fragments[$fragName])) {
                    throw new OutpostGraphQLError("Fragment '{$fragName}' not found");
                }
                $fragResult = $this->resolve($this->fragments[$fragName]['selections']);
                $result = array_merge($result, $fragResult);
                continue;
            }

            $fieldName = $sel['name'];
            $outputKey = $sel['alias'] ?? $fieldName;
            $args = $this->resolveArguments($sel['arguments'] ?? []);
            $subSelections = $sel['selections'] ?? [];

            $result[$outputKey] = $this->resolveRootField($fieldName, $args, $subSelections);
        }

        return $result;
    }

    private function resolveArguments(array $args): array {
        $resolved = [];
        foreach ($args as $key => $value) {
            $resolved[$key] = $this->resolveValue($value);
        }
        return $resolved;
    }

    private function resolveValue(mixed $value): mixed {
        if (is_array($value) && isset($value['__var'])) {
            $varName = $value['__var'];
            if (!array_key_exists($varName, $this->variables)) {
                throw new OutpostGraphQLError("Variable '\${$varName}' is not defined");
            }
            return $this->variables[$varName];
        }
        if (is_array($value)) {
            return array_map(fn($v) => $this->resolveValue($v), $value);
        }
        return $value;
    }

    private function resolveRootField(string $name, array $args, array $sels): mixed {
        if ($this->isMutation) {
            return $this->resolveMutation($name, $args, $sels);
        }

        return match ($name) {
            'collections' => $this->resolveCollections($args, $sels),
            'collection'  => $this->resolveCollection($args, $sels),
            'items'       => $this->resolveItems($args, $sels),
            'item'        => $this->resolveItem($args, $sels),
            'pages'       => $this->resolvePages($args, $sels),
            'page'        => $this->resolvePage($args, $sels),
            'globals'     => $this->resolveGlobals($args, $sels),
            'menus'       => $this->resolveMenus($args, $sels),
            'menu'        => $this->resolveMenu($args, $sels),
            'media'       => $this->resolveMedia($args, $sels),
            'folders'     => $this->resolveFolders($args, $sels),
            'labels'      => $this->resolveLabels($args, $sels),
            'schema'      => $this->resolveSchema($args, $sels),
            '__typename'  => 'Query',
            default       => throw new OutpostGraphQLError("Unknown field: '{$name}'"),
        };
    }

    // ── Mutation dispatcher ─────────────────────────────
    private function resolveMutation(string $name, array $args, array $sels): mixed {
        return match ($name) {
            'createItem'        => $this->mutCreateItem($args, $sels),
            'updateItem'        => $this->mutUpdateItem($args, $sels),
            'deleteItem'        => $this->mutDeleteItem($args, $sels),
            'updatePage'        => $this->mutUpdatePage($args, $sels),
            'updateGlobals'     => $this->mutUpdateGlobals($args, $sels),
            'deleteMedia'       => $this->mutDeleteMedia($args, $sels),
            'assignLabels'      => $this->mutAssignLabels($args, $sels),
            'removeLabels'      => $this->mutRemoveLabels($args, $sels),
            'createCollection'  => $this->mutCreateCollection($args, $sels),
            'updateCollection'  => $this->mutUpdateCollection($args, $sels),
            'createFolder'      => $this->mutCreateFolder($args, $sels),
            'createLabel'       => $this->mutCreateLabel($args, $sels),
            '__typename'        => 'Mutation',
            default             => throw new OutpostGraphQLError("Unknown mutation: '{$name}'"),
        };
    }

    // ── Collections ─────────────────────────────────────
    private function resolveCollections(array $args, array $sels): array {
        $rows = OutpostDB::fetchAll(
            'SELECT id, slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page FROM collections ORDER BY name ASC'
        );

        return array_map(fn($row) => $this->formatCollection($row, $sels), $rows);
    }

    private function resolveCollection(array $args, array $sels): ?array {
        $slug = $args['slug'] ?? null;
        if (!$slug) throw new OutpostGraphQLError("collection requires 'slug' argument");

        $row = OutpostDB::fetchOne(
            'SELECT id, slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page FROM collections WHERE slug = ?',
            [$slug]
        );

        return $row ? $this->formatCollection($row, $sels) : null;
    }

    private function formatCollection(array $row, array $sels): array {
        $result = [];
        $requestedFields = $this->getRequestedFieldNames($sels);

        if (in_array('id', $requestedFields))             $result['id'] = (int) $row['id'];
        if (in_array('slug', $requestedFields))            $result['slug'] = $row['slug'];
        if (in_array('name', $requestedFields))            $result['name'] = $row['name'];
        if (in_array('singularName', $requestedFields))    $result['singularName'] = $row['singular_name'] ?: $row['name'];
        if (in_array('urlPattern', $requestedFields))      $result['urlPattern'] = $row['url_pattern'] ?: ('/' . $row['slug'] . '/{slug}');
        if (in_array('sortField', $requestedFields))       $result['sortField'] = $row['sort_field'] ?: 'created_at';
        if (in_array('sortDirection', $requestedFields))   $result['sortDirection'] = $row['sort_direction'] ?: 'DESC';
        if (in_array('itemsPerPage', $requestedFields))    $result['itemsPerPage'] = (int) ($row['items_per_page'] ?: 10);

        if (in_array('fields', $requestedFields)) {
            $schema = json_decode($row['schema'] ?: '{}', true);
            $fieldList = $schema['fields'] ?? $schema;
            $fields = [];
            if (is_array($fieldList)) {
                $fieldSels = $this->getSubSelections('fields', $sels);
                foreach ($fieldList as $f) {
                    $fields[] = $this->formatFieldDef($f, $fieldSels);
                }
            }
            $result['fields'] = $fields;
        }

        if (in_array('itemCount', $requestedFields)) {
            $count = OutpostDB::fetchOne(
                "SELECT COUNT(*) as count FROM collection_items WHERE collection_id = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))",
                [$row['id']]
            );
            $result['itemCount'] = (int) ($count['count'] ?? 0);
        }

        return $result;
    }

    private function formatFieldDef(array $f, array $sels): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['name', 'type', 'label', 'required'];

        if (in_array('name', $requested))     $result['name'] = $f['name'] ?? '';
        if (in_array('type', $requested))     $result['type'] = $f['type'] ?? 'text';
        if (in_array('label', $requested))    $result['label'] = $f['label'] ?? ($f['name'] ?? '');
        if (in_array('required', $requested)) $result['required'] = !empty($f['required']);

        return $result;
    }

    // ── Items ───────────────────────────────────────────
    private function resolveItems(array $args, array $sels): array {
        $collectionSlug = $args['collection'] ?? null;
        if (!$collectionSlug) throw new OutpostGraphQLError("items requires 'collection' argument");

        $collection = OutpostDB::fetchOne(
            'SELECT id, slug, url_pattern, sort_field, sort_direction FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$collection) throw new OutpostGraphQLError("Collection '{$collectionSlug}' not found");

        $limit = max(1, min(100, (int) ($args['limit'] ?? 10)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $orderBy = $args['orderBy'] ?? ($collection['sort_field'] ?: 'created_at');
        $order = strtoupper($args['order'] ?? ($collection['sort_direction'] ?: 'DESC'));
        if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';
        $allowedSorts = ['created_at', 'updated_at', 'published_at', 'slug', 'sort_order'];
        if (!in_array($orderBy, $allowedSorts)) $orderBy = 'created_at';

        $where = "collection_id = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))";
        $params = [$collection['id']];

        // Folder filtering
        $folderSlug = $args['folder'] ?? null;
        $labelSlug = $args['label'] ?? null;
        if ($folderSlug && $labelSlug) {
            $folder = OutpostDB::fetchOne(
                'SELECT id FROM folders WHERE collection_id = ? AND slug = ?',
                [$collection['id'], $folderSlug]
            );
            if ($folder) {
                $label = OutpostDB::fetchOne(
                    'SELECT id FROM labels WHERE folder_id = ? AND slug = ?',
                    [$folder['id'], $labelSlug]
                );
                if ($label) {
                    $where .= ' AND id IN (SELECT item_id FROM item_labels WHERE label_id = ?)';
                    $params[] = $label['id'];
                } else {
                    // Label doesn't exist — return empty
                    return $this->pickFields(['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset], $sels);
                }
            }
        }

        $total = OutpostDB::fetchOne(
            "SELECT COUNT(*) as count FROM collection_items WHERE {$where}",
            $params
        );
        $totalCount = (int) ($total['count'] ?? 0);

        $items = OutpostDB::fetchAll(
            "SELECT id, slug, status, data, sort_order, created_at, updated_at, published_at FROM collection_items WHERE {$where} ORDER BY {$orderBy} {$order} LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );

        $urlPattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
        $dataSels = $this->getSubSelections('data', $sels);

        $result = [];
        $requested = $this->getRequestedFieldNames($sels);

        if (in_array('data', $requested)) {
            $result['data'] = array_map(fn($item) => $this->formatItem($item, $urlPattern, $dataSels), $items);
        }
        if (in_array('total', $requested))  $result['total'] = $totalCount;
        if (in_array('limit', $requested))  $result['limit'] = $limit;
        if (in_array('offset', $requested)) $result['offset'] = $offset;

        return $result;
    }

    private function resolveItem(array $args, array $sels): ?array {
        $collectionSlug = $args['collection'] ?? null;
        $slug = $args['slug'] ?? null;
        if (!$collectionSlug) throw new OutpostGraphQLError("item requires 'collection' argument");
        if (!$slug) throw new OutpostGraphQLError("item requires 'slug' argument");

        $collection = OutpostDB::fetchOne(
            'SELECT id, slug, url_pattern FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$collection) return null;

        $item = OutpostDB::fetchOne(
            "SELECT id, slug, status, data, sort_order, created_at, updated_at, published_at FROM collection_items WHERE collection_id = ? AND slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= datetime('now'))",
            [$collection['id'], $slug]
        );
        if (!$item) return null;

        $urlPattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
        return $this->formatItem($item, $urlPattern, $sels);
    }

    private function formatItem(array $item, string $urlPattern, array $sels): array {
        $data = json_decode($item['data'], true) ?: [];
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['id', 'slug', 'title', 'url', 'status', 'createdAt', 'updatedAt', 'publishedAt', 'fields', 'labels'];

        if (in_array('id', $requested))          $result['id'] = (int) $item['id'];
        if (in_array('slug', $requested))        $result['slug'] = $item['slug'];
        if (in_array('title', $requested))       $result['title'] = $data['title'] ?? $item['slug'];
        if (in_array('url', $requested))         $result['url'] = $urlPattern ? str_replace('{slug}', $item['slug'], $urlPattern) : '';
        if (in_array('status', $requested))      $result['status'] = $item['status'];
        if (in_array('createdAt', $requested))   $result['createdAt'] = $item['created_at'];
        if (in_array('updatedAt', $requested))   $result['updatedAt'] = $item['updated_at'];
        if (in_array('publishedAt', $requested)) $result['publishedAt'] = $item['published_at'];

        if (in_array('fields', $requested)) {
            $fields = [];
            foreach ($data as $key => $value) {
                if ($key === 'blocks') continue;
                $fields[$key] = $value;
            }
            // Render blocks to body
            if (!empty($data['blocks']) && is_array($data['blocks'])) {
                $bodyParts = [];
                foreach ($data['blocks'] as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'text' || $type === 'markdown' || $type === 'html') {
                        $bodyParts[] = $block['content'] ?? '';
                    } elseif ($type === 'image') {
                        $src = htmlspecialchars($block['src'] ?? '', ENT_QUOTES, 'UTF-8');
                        $alt = htmlspecialchars($block['alt'] ?? '', ENT_QUOTES, 'UTF-8');
                        $bodyParts[] = "<img src=\"{$src}\" alt=\"{$alt}\">";
                    } elseif ($type === 'divider') {
                        $bodyParts[] = '<hr>';
                    }
                }
                $fields['body'] = implode("\n", $bodyParts);
                $fields['blocks'] = $data['blocks'];
            }
            $result['fields'] = $fields;
        }

        if (in_array('labels', $requested)) {
            $labels = OutpostDB::fetchAll(
                'SELECT l.id, l.name, l.slug as label_slug, l.parent_id, f.slug as folder_slug
                 FROM item_labels il
                 INNER JOIN labels l ON l.id = il.label_id
                 INNER JOIN folders f ON f.id = l.folder_id
                 WHERE il.item_id = ?
                 ORDER BY l.sort_order ASC, l.name ASC',
                [$item['id']]
            );
            $labelsByFolder = [];
            foreach ($labels as $l) {
                $labelsByFolder[$l['folder_slug']][] = [
                    'id' => (int) $l['id'],
                    'name' => $l['name'],
                    'slug' => $l['label_slug'],
                    'parent_id' => $l['parent_id'] ? (int) $l['parent_id'] : null,
                ];
            }
            $result['labels'] = $labelsByFolder;
        }

        return $result;
    }

    // ── Pages ───────────────────────────────────────────
    private function resolvePages(array $args, array $sels): array {
        $pages = OutpostDB::fetchAll(
            "SELECT id, path, title, meta_title, meta_description FROM pages WHERE path != '__global__' AND (visibility IS NULL OR visibility = 'public') ORDER BY path ASC"
        );

        return array_map(fn($p) => $this->formatPage($p, $sels), $pages);
    }

    private function resolvePage(array $args, array $sels): ?array {
        $path = $args['path'] ?? null;
        if (!$path) throw new OutpostGraphQLError("page requires 'path' argument");

        $page = OutpostDB::fetchOne(
            "SELECT id, path, title, meta_title, meta_description FROM pages WHERE path = ? AND path != '__global__' AND (visibility IS NULL OR visibility = 'public')",
            [$path]
        );

        return $page ? $this->formatPage($page, $sels) : null;
    }

    private function formatPage(array $page, array $sels): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['id', 'path', 'title', 'metaTitle', 'metaDescription', 'fields'];

        if (in_array('id', $requested))              $result['id'] = (int) $page['id'];
        if (in_array('path', $requested))            $result['path'] = $page['path'];
        if (in_array('title', $requested))           $result['title'] = $page['title'];
        if (in_array('metaTitle', $requested))       $result['metaTitle'] = $page['meta_title'];
        if (in_array('metaDescription', $requested)) $result['metaDescription'] = $page['meta_description'];

        if (in_array('fields', $requested)) {
            $fields = OutpostDB::fetchAll(
                'SELECT field_name, content FROM fields WHERE page_id = ? ORDER BY sort_order ASC',
                [$page['id']]
            );
            $fieldMap = [];
            foreach ($fields as $f) {
                $fieldMap[$f['field_name']] = $f['content'];
            }
            $result['fields'] = $fieldMap;
        }

        return $result;
    }

    // ── Globals ─────────────────────────────────────────
    private function resolveGlobals(array $args, array $sels): mixed {
        $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if (!$globalPage) return (object)[];

        $fields = OutpostDB::fetchAll(
            "SELECT field_name, content FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
            [$globalPage['id']]
        );

        $result = [];
        foreach ($fields as $f) {
            $result[$f['field_name']] = $f['content'];
        }
        return $result ?: (object)[];
    }

    // ── Menus ───────────────────────────────────────────
    private function resolveMenus(array $args, array $sels): array {
        $menus = OutpostDB::fetchAll('SELECT slug, name, items FROM menus ORDER BY id ASC');
        $result = [];
        foreach ($menus as $m) {
            $items = json_decode($m['items'], true) ?? [];
            $entry = [];
            $requested = $this->getRequestedFieldNames($sels);
            if (in_array('slug', $requested))      $entry['slug'] = $m['slug'];
            if (in_array('name', $requested))      $entry['name'] = $m['name'];
            if (in_array('itemCount', $requested)) $entry['itemCount'] = count($items);
            $result[] = $entry;
        }
        return $result;
    }

    private function resolveMenu(array $args, array $sels): ?array {
        $slug = $args['slug'] ?? null;
        if (!$slug) throw new OutpostGraphQLError("menu requires 'slug' argument");

        $menu = OutpostDB::fetchOne('SELECT slug, name, items FROM menus WHERE slug = ?', [$slug]);
        if (!$menu) return null;

        $items = json_decode($menu['items'], true) ?? [];
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);

        if (in_array('slug', $requested)) $result['slug'] = $menu['slug'];
        if (in_array('name', $requested)) $result['name'] = $menu['name'];

        if (in_array('items', $requested)) {
            $itemSels = $this->getSubSelections('items', $sels);
            $result['items'] = array_map(fn($item) => $this->formatMenuItem($item, $itemSels), $items);
        }

        return $result;
    }

    private function formatMenuItem(array $item, array $sels, int $depth = 0): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['label', 'url', 'target', 'children'];

        if (in_array('label', $requested))  $result['label'] = $item['label'] ?? '';
        if (in_array('url', $requested))    $result['url'] = $item['url'] ?? '';
        if (in_array('target', $requested)) $result['target'] = $item['target'] ?? '';

        if (in_array('children', $requested)) {
            $children = $item['children'] ?? [];
            $childSels = $this->getSubSelections('children', $sels);
            // Cap menu nesting depth to prevent runaway recursion
            if ($depth >= 5 || !is_array($children)) {
                $result['children'] = [];
            } else {
                $result['children'] = array_map(fn($c) => $this->formatMenuItem($c, $childSels, $depth + 1), $children);
            }
        }

        return $result;
    }

    // ── Media ───────────────────────────────────────────
    private function resolveMedia(array $args, array $sels): array {
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $total = OutpostDB::fetchOne('SELECT COUNT(*) as count FROM media');
        $totalCount = (int) ($total['count'] ?? 0);

        $media = OutpostDB::fetchAll(
            'SELECT id, filename, original_name, path, thumb_path, mime_type, file_size, width, height, alt_text, uploaded_at
             FROM media ORDER BY uploaded_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        $result = [];
        $requested = $this->getRequestedFieldNames($sels);

        if (in_array('data', $requested)) {
            $dataSels = $this->getSubSelections('data', $sels);
            $result['data'] = array_map(fn($m) => $this->formatMediaItem($m, $dataSels), $media);
        }
        if (in_array('total', $requested))  $result['total'] = $totalCount;
        if (in_array('limit', $requested))  $result['limit'] = $limit;
        if (in_array('offset', $requested)) $result['offset'] = $offset;

        return $result;
    }

    private function formatMediaItem(array $m, array $sels): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['id', 'filename', 'originalName', 'path', 'thumbPath', 'mimeType', 'fileSize', 'width', 'height', 'altText', 'uploadedAt'];

        if (in_array('id', $requested))           $result['id'] = (int) $m['id'];
        if (in_array('filename', $requested))     $result['filename'] = $m['filename'];
        if (in_array('originalName', $requested)) $result['originalName'] = $m['original_name'];
        if (in_array('path', $requested))         $result['path'] = $m['path'];
        if (in_array('thumbPath', $requested))    $result['thumbPath'] = $m['thumb_path'];
        if (in_array('mimeType', $requested))     $result['mimeType'] = $m['mime_type'];
        if (in_array('fileSize', $requested))     $result['fileSize'] = (int) $m['file_size'];
        if (in_array('width', $requested))        $result['width'] = (int) $m['width'];
        if (in_array('height', $requested))       $result['height'] = (int) $m['height'];
        if (in_array('altText', $requested))      $result['altText'] = $m['alt_text'];
        if (in_array('uploadedAt', $requested))   $result['uploadedAt'] = $m['uploaded_at'];

        return $result;
    }

    // ── Folders ─────────────────────────────────────────
    private function resolveFolders(array $args, array $sels): array {
        $sql = 'SELECT f.id, f.slug, f.name, f.singular_name, f.type, c.slug as collection_slug
                FROM folders f
                LEFT JOIN collections c ON c.id = f.collection_id';
        $params = [];

        $collectionFilter = $args['collection'] ?? null;
        if ($collectionFilter) {
            $sql .= ' WHERE c.slug = ?';
            $params[] = $collectionFilter;
        }

        $sql .= ' ORDER BY f.name ASC';
        $folders = OutpostDB::fetchAll($sql, $params);

        return array_map(fn($fld) => $this->formatFolder($fld, $sels), $folders);
    }

    private function formatFolder(array $fld, array $sels): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['slug', 'name', 'singularName', 'type', 'collectionSlug', 'labelCount'];

        if (in_array('slug', $requested))           $result['slug'] = $fld['slug'];
        if (in_array('name', $requested))           $result['name'] = $fld['name'];
        if (in_array('singularName', $requested))   $result['singularName'] = $fld['singular_name'] ?: $fld['name'];
        if (in_array('type', $requested))           $result['type'] = $fld['type'] ?: 'flat';
        if (in_array('collectionSlug', $requested)) $result['collectionSlug'] = $fld['collection_slug'];

        if (in_array('labelCount', $requested)) {
            $count = OutpostDB::fetchOne(
                'SELECT COUNT(*) as count FROM labels WHERE folder_id = ?',
                [$fld['id']]
            );
            $result['labelCount'] = (int) ($count['count'] ?? 0);
        }

        return $result;
    }

    // ── Labels ──────────────────────────────────────────
    private function resolveLabels(array $args, array $sels): array {
        $folderSlug = $args['folder'] ?? null;
        if (!$folderSlug) throw new OutpostGraphQLError("labels requires 'folder' argument");

        $folder = OutpostDB::fetchOne(
            'SELECT id FROM folders WHERE slug = ?',
            [$folderSlug]
        );
        if (!$folder) return [];

        $labels = OutpostDB::fetchAll(
            'SELECT id, slug, name, description, parent_id, data, sort_order FROM labels WHERE folder_id = ? ORDER BY sort_order ASC, name ASC',
            [$folder['id']]
        );

        return array_map(function ($l) use ($sels) {
            $result = [];
            $requested = $this->getRequestedFieldNames($sels);
            if (empty($requested)) $requested = ['id', 'slug', 'name', 'description', 'parentId', 'itemCount', 'data'];

            if (in_array('id', $requested))          $result['id'] = (int) $l['id'];
            if (in_array('slug', $requested))        $result['slug'] = $l['slug'];
            if (in_array('name', $requested))        $result['name'] = $l['name'];
            if (in_array('description', $requested)) $result['description'] = $l['description'] ?? '';
            if (in_array('parentId', $requested))    $result['parentId'] = $l['parent_id'] ? (int) $l['parent_id'] : null;
            if (in_array('data', $requested))        $result['data'] = json_decode($l['data'] ?: '{}', true);

            if (in_array('itemCount', $requested)) {
                $itemCount = OutpostDB::fetchOne(
                    "SELECT COUNT(*) as count FROM item_labels il
                     JOIN collection_items ci ON ci.id = il.item_id
                     WHERE il.label_id = ? AND ci.status = 'published'
                       AND (ci.published_at IS NULL OR ci.published_at <= datetime('now'))",
                    [$l['id']]
                );
                $result['itemCount'] = (int) ($itemCount['count'] ?? 0);
            }

            return $result;
        }, $labels);
    }

    // ── Schema ──────────────────────────────────────────
    private function resolveSchema(array $args, array $sels): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);

        if (in_array('collections', $requested)) {
            $collSels = $this->getSubSelections('collections', $sels);
            $result['collections'] = $this->resolveCollections([], $collSels);
        }

        if (in_array('pages', $requested)) {
            $pages = OutpostDB::fetchAll(
                "SELECT id, path, title FROM pages WHERE path != '__global__' AND (visibility IS NULL OR visibility = 'public') ORDER BY path ASC"
            );
            $pageSels = $this->getSubSelections('pages', $sels);
            $result['pages'] = array_map(function ($p) use ($pageSels) {
                $entry = [];
                $req = $this->getRequestedFieldNames($pageSels);
                if (in_array('path', $req))  $entry['path'] = $p['path'];
                if (in_array('title', $req)) $entry['title'] = $p['title'];
                if (in_array('fields', $req)) {
                    // Page fields from the fields table
                    $fields = OutpostDB::fetchAll(
                        'SELECT field_name, field_type FROM fields WHERE page_id = ? ORDER BY sort_order ASC',
                        [$p['id']]
                    );
                    $fieldSels = $this->getSubSelections('fields', $pageSels);
                    $entry['fields'] = array_map(fn($f) => $this->formatFieldDef([
                        'name' => $f['field_name'],
                        'type' => $f['field_type'],
                        'label' => $f['field_name'],
                    ], $fieldSels), $fields);
                }
                return $entry;
            }, $pages);
        }

        if (in_array('globals', $requested)) {
            $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
            if ($globalPage) {
                $fields = OutpostDB::fetchAll(
                    "SELECT field_name, field_type FROM fields WHERE page_id = ? AND (theme = '' OR theme IS NULL) ORDER BY sort_order ASC",
                    [$globalPage['id']]
                );
                $globSels = $this->getSubSelections('globals', $sels);
                $result['globals'] = array_map(fn($f) => $this->formatFieldDef([
                    'name' => $f['field_name'],
                    'type' => $f['field_type'],
                    'label' => $f['field_name'],
                ], $globSels), $fields);
            } else {
                $result['globals'] = [];
            }
        }

        if (in_array('folders', $requested)) {
            $folderSels = $this->getSubSelections('folders', $sels);
            $result['folders'] = $this->resolveFolders([], $folderSels);
        }

        return $result;
    }

    // ══════════════════════════════════════════════════════
    // MUTATIONS (require Bearer API key authentication)
    // ══════════════════════════════════════════════════════

    // ── createItem ──────────────────────────────────────
    private function mutCreateItem(array $args, array $sels): array {
        $collectionSlug = $args['collection'] ?? null;
        $slug = $args['slug'] ?? null;
        $status = $args['status'] ?? 'draft';
        $data = $args['data'] ?? [];

        if (!$collectionSlug) throw new OutpostGraphQLError("createItem requires 'collection'");
        if (!$slug) throw new OutpostGraphQLError("createItem requires 'slug'");

        // Validate slug: alphanumeric, hyphens, underscores only
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (!$slug) throw new OutpostGraphQLError("Invalid slug");

        if (!in_array($status, ['draft', 'published', 'scheduled'])) $status = 'draft';

        $collection = OutpostDB::fetchOne('SELECT id, slug, url_pattern FROM collections WHERE slug = ?', [$collectionSlug]);
        if (!$collection) throw new OutpostGraphQLError("Collection '{$collectionSlug}' not found");

        // Check slug uniqueness within collection
        $existing = OutpostDB::fetchOne(
            'SELECT id FROM collection_items WHERE collection_id = ? AND slug = ?',
            [$collection['id'], $slug]
        );
        if ($existing) throw new OutpostGraphQLError("Item with slug '{$slug}' already exists in this collection");

        // Ensure data is a JSON-encodable array/object
        if (is_string($data)) $data = json_decode($data, true) ?: [];

        $now = date('Y-m-d H:i:s');
        $publishedAt = ($status === 'published') ? $now : null;

        $itemId = OutpostDB::insert('collection_items', [
            'collection_id' => $collection['id'],
            'slug' => $slug,
            'status' => $status,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $publishedAt,
        ]);

        $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$itemId]);
        $urlPattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
        return $this->formatItem($item, $urlPattern, $sels);
    }

    // ── updateItem ──────────────────────────────────────
    private function mutUpdateItem(array $args, array $sels): array {
        $id = (int) ($args['id'] ?? 0);
        if (!$id) throw new OutpostGraphQLError("updateItem requires 'id'");

        $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$id]);
        if (!$item) throw new OutpostGraphQLError("Item {$id} not found");

        $collection = OutpostDB::fetchOne('SELECT * FROM collections WHERE id = ?', [$item['collection_id']]);

        $updates = [];

        // Merge data (partial update)
        if (isset($args['data'])) {
            $existingData = json_decode($item['data'], true) ?: [];
            $newData = is_string($args['data']) ? (json_decode($args['data'], true) ?: []) : $args['data'];
            $mergedData = array_merge($existingData, $newData);
            $updates['data'] = json_encode($mergedData, JSON_UNESCAPED_UNICODE);
        }

        if (isset($args['status'])) {
            $status = $args['status'];
            if (!in_array($status, ['draft', 'published', 'scheduled'])) $status = 'draft';
            $updates['status'] = $status;
            // Set published_at if transitioning to published
            if ($status === 'published' && !$item['published_at']) {
                $updates['published_at'] = date('Y-m-d H:i:s');
            }
        }

        if (isset($args['slug'])) {
            $newSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($args['slug']));
            if (!$newSlug) throw new OutpostGraphQLError("Invalid slug");
            // Check uniqueness within collection
            $dupeCheck = OutpostDB::fetchOne(
                'SELECT id FROM collection_items WHERE collection_id = ? AND slug = ? AND id != ?',
                [$item['collection_id'], $newSlug, $id]
            );
            if ($dupeCheck) throw new OutpostGraphQLError("Item with slug '{$newSlug}' already exists in this collection");
            $updates['slug'] = $newSlug;
        }

        if (!empty($updates)) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            OutpostDB::update('collection_items', $updates, 'id = ?', [$id]);
        }

        $item = OutpostDB::fetchOne('SELECT * FROM collection_items WHERE id = ?', [$id]);
        $urlPattern = $collection['url_pattern'] ?: ('/' . $collection['slug'] . '/{slug}');
        return $this->formatItem($item, $urlPattern, $sels);
    }

    // ── deleteItem ──────────────────────────────────────
    private function mutDeleteItem(array $args, array $sels): bool {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) throw new OutpostGraphQLError("deleteItem requires a valid 'id'");

        $item = OutpostDB::fetchOne('SELECT id FROM collection_items WHERE id = ?', [$id]);
        if (!$item) throw new OutpostGraphQLError("Item {$id} not found");

        $deleted = OutpostDB::delete('collection_items', 'id = ?', [$id]);
        return $deleted > 0;
    }

    // ── updatePage ──────────────────────────────────────
    private function mutUpdatePage(array $args, array $sels): array {
        $id = (int) ($args['id'] ?? 0);
        if (!$id) throw new OutpostGraphQLError("updatePage requires 'id'");

        $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE id = ?', [$id]);
        if (!$page) throw new OutpostGraphQLError("Page {$id} not found");

        // Guard: do not allow direct mutation of the __global__ page via updatePage
        if (($page['path'] ?? '') === '__global__') {
            throw new OutpostGraphQLError("Cannot modify globals page via updatePage. Use updateGlobals instead.");
        }

        $pageUpdates = [];
        if (isset($args['metaTitle']))       $pageUpdates['meta_title'] = $args['metaTitle'];
        if (isset($args['metaDescription'])) $pageUpdates['meta_description'] = $args['metaDescription'];
        if (isset($args['status'])) {
            $status = $args['status'];
            if (!in_array($status, ['draft', 'published'], true)) $status = 'draft';
            $pageUpdates['status'] = $status;
        }

        if (!empty($pageUpdates)) {
            $pageUpdates['updated_at'] = date('Y-m-d H:i:s');
            OutpostDB::update('pages', $pageUpdates, 'id = ?', [$id]);
        }

        // Update page fields
        if (isset($args['fields']) && is_array($args['fields'])) {
            foreach ($args['fields'] as $fieldName => $content) {
                // Validate field name: alphanumeric + underscores only
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
                    throw new OutpostGraphQLError("Invalid field name: '{$fieldName}'");
                }
                // Content must be string
                if (!is_string($content)) $content = is_scalar($content) ? (string)$content : json_encode($content);

                $existing = OutpostDB::fetchOne(
                    "SELECT id FROM fields WHERE page_id = ? AND field_name = ? AND (theme = '' OR theme IS NULL)",
                    [$id, $fieldName]
                );
                if ($existing) {
                    OutpostDB::update('fields', [
                        'content' => $content,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$existing['id']]);
                } else {
                    OutpostDB::insert('fields', [
                        'page_id' => $id,
                        'theme' => '',
                        'field_name' => $fieldName,
                        'field_type' => 'text',
                        'content' => $content,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        $page = OutpostDB::fetchOne('SELECT * FROM pages WHERE id = ?', [$id]);
        return $this->formatPage($page, $sels);
    }

    // ── updateGlobals ───────────────────────────────────
    private function mutUpdateGlobals(array $args, array $sels): bool {
        $fields = $args['fields'] ?? null;
        if (!is_array($fields)) throw new OutpostGraphQLError("updateGlobals requires 'fields' object");

        // Ensure global page exists
        $globalPage = OutpostDB::fetchOne("SELECT id FROM pages WHERE path = '__global__'");
        if (!$globalPage) {
            $pageId = OutpostDB::insert('pages', [
                'path' => '__global__',
                'title' => 'Globals',
            ]);
        } else {
            $pageId = $globalPage['id'];
        }

        foreach ($fields as $fieldName => $content) {
            // Validate field name: alphanumeric + underscores only
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
                throw new OutpostGraphQLError("Invalid field name: '{$fieldName}'");
            }
            // Content must be string
            if (!is_string($content)) $content = is_scalar($content) ? (string)$content : json_encode($content);

            $existing = OutpostDB::fetchOne(
                "SELECT id FROM fields WHERE page_id = ? AND field_name = ? AND (theme = '' OR theme IS NULL)",
                [$pageId, $fieldName]
            );
            if ($existing) {
                OutpostDB::update('fields', [
                    'content' => $content,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
            } else {
                OutpostDB::insert('fields', [
                    'page_id' => $pageId,
                    'theme' => '',
                    'field_name' => $fieldName,
                    'field_type' => 'text',
                    'content' => $content,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return true;
    }

    // ── deleteMedia ─────────────────────────────────────
    private function mutDeleteMedia(array $args, array $sels): bool {
        $id = (int) ($args['id'] ?? 0);
        if (!$id) throw new OutpostGraphQLError("deleteMedia requires 'id'");

        $media = OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$media) return false;

        // Delete physical files
        $basePath = OUTPOST_UPLOADS_DIR;
        $filePath = $basePath . basename($media['path']);
        $thumbPath = $media['thumb_path'] ? $basePath . 'thumbs/' . basename($media['thumb_path']) : null;

        if (file_exists($filePath)) @unlink($filePath);
        if ($thumbPath && file_exists($thumbPath)) @unlink($thumbPath);

        OutpostDB::delete('media', 'id = ?', [$id]);
        return true;
    }

    // ── assignLabels ────────────────────────────────────
    private function mutAssignLabels(array $args, array $sels): bool {
        $itemId = (int) ($args['itemId'] ?? 0);
        $labelIds = $args['labelIds'] ?? [];
        if (!$itemId) throw new OutpostGraphQLError("assignLabels requires 'itemId'");
        if (!is_array($labelIds) || empty($labelIds)) throw new OutpostGraphQLError("assignLabels requires non-empty 'labelIds' array");

        // Verify item exists
        $item = OutpostDB::fetchOne('SELECT id FROM collection_items WHERE id = ?', [$itemId]);
        if (!$item) throw new OutpostGraphQLError("Item {$itemId} not found");

        $db = OutpostDB::connect();
        foreach ($labelIds as $labelId) {
            $labelId = (int) $labelId;
            if ($labelId <= 0) continue;
            $db->prepare(
                'INSERT OR IGNORE INTO item_labels (item_id, label_id) VALUES (?, ?)'
            )->execute([$itemId, $labelId]);
        }
        return true;
    }

    // ── removeLabels ────────────────────────────────────
    private function mutRemoveLabels(array $args, array $sels): bool {
        $itemId = (int) ($args['itemId'] ?? 0);
        $labelIds = $args['labelIds'] ?? [];
        if (!$itemId) throw new OutpostGraphQLError("removeLabels requires 'itemId'");
        if (!is_array($labelIds) || empty($labelIds)) throw new OutpostGraphQLError("removeLabels requires non-empty 'labelIds' array");

        foreach ($labelIds as $labelId) {
            $lid = (int) $labelId;
            if ($lid <= 0) continue;
            OutpostDB::delete('item_labels', 'item_id = ? AND label_id = ?', [$itemId, $lid]);
        }
        return true;
    }

    // ── createCollection ────────────────────────────────
    private function mutCreateCollection(array $args, array $sels): array {
        $name = $args['name'] ?? null;
        $slug = $args['slug'] ?? null;
        if (!$name) throw new OutpostGraphQLError("createCollection requires 'name'");
        if (!$slug) throw new OutpostGraphQLError("createCollection requires 'slug'");

        // Validate slug
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (!$slug) throw new OutpostGraphQLError("Invalid slug");

        $existing = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$slug]);
        if ($existing) throw new OutpostGraphQLError("Collection '{$slug}' already exists");

        $schema = $args['schema'] ?? [];
        if (is_string($schema)) $schema = json_decode($schema, true) ?: [];

        $collId = OutpostDB::insert('collections', [
            'slug' => $slug,
            'name' => $name,
            'singular_name' => $args['singularName'] ?? $name,
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'url_pattern' => $args['urlPattern'] ?? ('/' . $slug . '/{slug}'),
            'sort_field' => 'created_at',
            'sort_direction' => 'DESC',
            'items_per_page' => 10,
        ]);

        $row = OutpostDB::fetchOne(
            'SELECT id, slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page FROM collections WHERE id = ?',
            [$collId]
        );
        return $this->formatCollection($row, $sels);
    }

    // ── updateCollection ────────────────────────────────
    private function mutUpdateCollection(array $args, array $sels): array {
        $id = (int) ($args['id'] ?? 0);
        if (!$id) throw new OutpostGraphQLError("updateCollection requires 'id'");

        $coll = OutpostDB::fetchOne('SELECT * FROM collections WHERE id = ?', [$id]);
        if (!$coll) throw new OutpostGraphQLError("Collection {$id} not found");

        $updates = [];
        if (isset($args['name']))       $updates['name'] = $args['name'];
        if (isset($args['urlPattern'])) $updates['url_pattern'] = $args['urlPattern'];
        if (isset($args['schema'])) {
            $schema = is_string($args['schema']) ? (json_decode($args['schema'], true) ?: []) : $args['schema'];
            $updates['schema'] = json_encode($schema, JSON_UNESCAPED_UNICODE);
        }

        if (!empty($updates)) {
            OutpostDB::update('collections', $updates, 'id = ?', [$id]);
        }

        $row = OutpostDB::fetchOne(
            'SELECT id, slug, name, singular_name, schema, url_pattern, sort_field, sort_direction, items_per_page FROM collections WHERE id = ?',
            [$id]
        );
        return $this->formatCollection($row, $sels);
    }

    // ── createFolder ────────────────────────────────────
    private function mutCreateFolder(array $args, array $sels): array {
        $collectionId = (int) ($args['collectionId'] ?? 0);
        $name = $args['name'] ?? null;
        $slug = $args['slug'] ?? null;
        $type = $args['type'] ?? 'flat';

        if (!$collectionId) throw new OutpostGraphQLError("createFolder requires 'collectionId'");
        if (!$name) throw new OutpostGraphQLError("createFolder requires 'name'");
        if (!$slug) throw new OutpostGraphQLError("createFolder requires 'slug'");
        if (!in_array($type, ['flat', 'hierarchical'], true)) $type = 'flat';

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (!$slug) throw new OutpostGraphQLError("Invalid slug");

        // Verify collection exists
        $coll = OutpostDB::fetchOne('SELECT id FROM collections WHERE id = ?', [$collectionId]);
        if (!$coll) throw new OutpostGraphQLError("Collection {$collectionId} not found");

        // Check slug uniqueness within collection
        $existingFolder = OutpostDB::fetchOne(
            'SELECT id FROM folders WHERE collection_id = ? AND slug = ?',
            [$collectionId, $slug]
        );
        if ($existingFolder) throw new OutpostGraphQLError("Folder '{$slug}' already exists in this collection");

        $folderId = OutpostDB::insert('folders', [
            'collection_id' => $collectionId,
            'slug' => $slug,
            'name' => $name,
            'type' => $type,
        ]);

        $fld = OutpostDB::fetchOne(
            'SELECT f.id, f.slug, f.name, f.singular_name, f.type, c.slug as collection_slug
             FROM folders f LEFT JOIN collections c ON c.id = f.collection_id
             WHERE f.id = ?',
            [$folderId]
        );
        return $this->formatFolder($fld, $sels);
    }

    // ── createLabel ─────────────────────────────────────
    private function mutCreateLabel(array $args, array $sels): array {
        $folderId = (int) ($args['folderId'] ?? 0);
        $name = $args['name'] ?? null;
        $slug = $args['slug'] ?? null;

        if (!$folderId) throw new OutpostGraphQLError("createLabel requires 'folderId'");
        if (!$name) throw new OutpostGraphQLError("createLabel requires 'name'");
        if (!$slug) throw new OutpostGraphQLError("createLabel requires 'slug'");

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        if (!$slug) throw new OutpostGraphQLError("Invalid slug");

        // Verify folder exists
        $folder = OutpostDB::fetchOne('SELECT id FROM folders WHERE id = ?', [$folderId]);
        if (!$folder) throw new OutpostGraphQLError("Folder {$folderId} not found");

        // Check slug uniqueness within folder
        $existingLabel = OutpostDB::fetchOne(
            'SELECT id FROM labels WHERE folder_id = ? AND slug = ?',
            [$folderId, $slug]
        );
        if ($existingLabel) throw new OutpostGraphQLError("Label '{$slug}' already exists in this folder");

        $labelId = OutpostDB::insert('labels', [
            'folder_id' => $folderId,
            'slug' => $slug,
            'name' => $name,
        ]);

        $l = OutpostDB::fetchOne('SELECT * FROM labels WHERE id = ?', [$labelId]);
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        if (empty($requested)) $requested = ['id', 'slug', 'name', 'description', 'parentId', 'itemCount', 'data'];

        if (in_array('id', $requested))          $result['id'] = (int) $l['id'];
        if (in_array('slug', $requested))        $result['slug'] = $l['slug'];
        if (in_array('name', $requested))        $result['name'] = $l['name'];
        if (in_array('description', $requested)) $result['description'] = $l['description'] ?? '';
        if (in_array('parentId', $requested))    $result['parentId'] = null;
        if (in_array('itemCount', $requested))   $result['itemCount'] = 0;
        if (in_array('data', $requested))        $result['data'] = json_decode($l['data'] ?? '{}', true);

        return $result;
    }

    // ── Helpers ─────────────────────────────────────────
    private function getRequestedFieldNames(array $sels): array {
        $names = [];
        foreach ($sels as $sel) {
            if (($sel['type'] ?? '') === 'fragment_spread') {
                $fragName = $sel['name'];
                if (isset($this->fragments[$fragName])) {
                    $names = array_merge($names, $this->getRequestedFieldNames($this->fragments[$fragName]['selections']));
                }
            } else {
                $names[] = $sel['alias'] ?? $sel['name'];
            }
        }
        return $names;
    }

    private function getSubSelections(string $fieldName, array $sels): array {
        foreach ($sels as $sel) {
            if (($sel['type'] ?? '') === 'fragment_spread') {
                $fragName = $sel['name'];
                if (isset($this->fragments[$fragName])) {
                    $sub = $this->getSubSelections($fieldName, $this->fragments[$fragName]['selections']);
                    if (!empty($sub)) return $sub;
                }
                continue;
            }
            $name = $sel['alias'] ?? $sel['name'];
            if ($name === $fieldName) {
                return $sel['selections'] ?? [];
            }
        }
        return [];
    }

    private function pickFields(array $data, array $sels): array {
        $result = [];
        $requested = $this->getRequestedFieldNames($sels);
        foreach ($requested as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }
        return $result;
    }
}

// ══════════════════════════════════════════════════════════
// Introspection — enough for GraphQL Playground/Explorer
// ══════════════════════════════════════════════════════════

function resolve_introspection(array $selections, array $fragments): array {
    $schema = build_introspection_schema();
    $result = [];

    foreach ($selections as $sel) {
        $name = $sel['name'] ?? '';
        $outputKey = $sel['alias'] ?? $name;

        if ($name === '__schema') {
            $result[$outputKey] = resolve_introspection_schema($schema, $sel['selections'] ?? [], $fragments);
        } elseif ($name === '__type') {
            $typeName = $sel['arguments']['name'] ?? null;
            if ($typeName && isset($schema['types'][$typeName])) {
                $result[$outputKey] = resolve_introspection_type($schema['types'][$typeName], $sel['selections'] ?? [], $schema, $fragments);
            } else {
                $result[$outputKey] = null;
            }
        } else {
            // Non-introspection field mixed in — skip for now
        }
    }

    return $result;
}

function resolve_introspection_schema(array $schema, array $sels, array $fragments): array {
    $result = [];
    foreach ($sels as $sel) {
        if (($sel['type'] ?? '') === 'fragment_spread') continue;
        $name = $sel['name'] ?? '';
        $key = $sel['alias'] ?? $name;

        switch ($name) {
            case 'types':
                $result[$key] = array_values(array_map(
                    fn($t) => resolve_introspection_type($t, $sel['selections'] ?? [], $schema, $fragments),
                    $schema['types']
                ));
                break;
            case 'queryType':
                $result[$key] = resolve_introspection_type($schema['types']['Query'], $sel['selections'] ?? [], $schema, $fragments);
                break;
            case 'mutationType':
                $result[$key] = resolve_introspection_type($schema['types']['Mutation'], $sel['selections'] ?? [], $schema, $fragments);
                break;
            case 'subscriptionType':
                $result[$key] = null;
                break;
            case 'directives':
                $result[$key] = [];
                break;
        }
    }
    return $result;
}

function resolve_introspection_type(array $typeDef, array $sels, array $schema, array $fragments): ?array {
    if (empty($sels)) {
        return ['name' => $typeDef['name'] ?? null, 'kind' => $typeDef['kind'] ?? 'OBJECT'];
    }

    $result = [];
    foreach ($sels as $sel) {
        if (($sel['type'] ?? '') === 'fragment_spread') {
            $fragName = $sel['name'];
            if (isset($fragments[$fragName])) {
                $fragResult = resolve_introspection_type($typeDef, $fragments[$fragName]['selections'], $schema, $fragments);
                $result = array_merge($result, $fragResult);
            }
            continue;
        }
        $name = $sel['name'] ?? '';
        $key = $sel['alias'] ?? $name;

        switch ($name) {
            case 'name':
                $result[$key] = $typeDef['name'] ?? null;
                break;
            case 'kind':
                $result[$key] = $typeDef['kind'] ?? 'OBJECT';
                break;
            case 'description':
                $result[$key] = $typeDef['description'] ?? null;
                break;
            case 'fields':
                $fields = $typeDef['fields'] ?? [];
                $includeDeprecated = $sel['arguments']['includeDeprecated'] ?? false;
                $result[$key] = array_values(array_map(
                    fn($f) => resolve_introspection_field($f, $sel['selections'] ?? [], $schema, $fragments),
                    $fields
                ));
                break;
            case 'inputFields':
                $result[$key] = $typeDef['inputFields'] ?? null;
                break;
            case 'interfaces':
                $result[$key] = [];
                break;
            case 'enumValues':
                $includeDeprecated = $sel['arguments']['includeDeprecated'] ?? false;
                $result[$key] = $typeDef['enumValues'] ?? null;
                break;
            case 'possibleTypes':
                $result[$key] = $typeDef['possibleTypes'] ?? null;
                break;
            case 'ofType':
                if (isset($typeDef['ofType'])) {
                    $innerType = $typeDef['ofType'];
                    if (is_string($innerType) && isset($schema['types'][$innerType])) {
                        $result[$key] = resolve_introspection_type($schema['types'][$innerType], $sel['selections'] ?? [], $schema, $fragments);
                    } elseif (is_array($innerType)) {
                        $result[$key] = resolve_introspection_type($innerType, $sel['selections'] ?? [], $schema, $fragments);
                    } else {
                        $result[$key] = null;
                    }
                } else {
                    $result[$key] = null;
                }
                break;
            case 'specifiedByURL':
            case 'specifiedByUrl':
                $result[$key] = null;
                break;
        }
    }
    return $result;
}

function resolve_introspection_field(array $fieldDef, array $sels, array $schema, array $fragments): array {
    $result = [];
    foreach ($sels as $sel) {
        if (($sel['type'] ?? '') === 'fragment_spread') {
            $fragName = $sel['name'];
            if (isset($fragments[$fragName])) {
                $fragResult = resolve_introspection_field($fieldDef, $fragments[$fragName]['selections'], $schema, $fragments);
                $result = array_merge($result, $fragResult);
            }
            continue;
        }
        $name = $sel['name'] ?? '';
        $key = $sel['alias'] ?? $name;

        switch ($name) {
            case 'name':
                $result[$key] = $fieldDef['name'] ?? '';
                break;
            case 'description':
                $result[$key] = $fieldDef['description'] ?? null;
                break;
            case 'args':
                $args = $fieldDef['args'] ?? [];
                $result[$key] = array_values(array_map(
                    fn($a) => resolve_introspection_input_value($a, $sel['selections'] ?? [], $schema, $fragments),
                    $args
                ));
                break;
            case 'type':
                $typeRef = $fieldDef['type'] ?? [];
                $result[$key] = resolve_introspection_type_ref($typeRef, $sel['selections'] ?? [], $schema, $fragments);
                break;
            case 'isDeprecated':
                $result[$key] = false;
                break;
            case 'deprecationReason':
                $result[$key] = null;
                break;
        }
    }
    return $result;
}

function resolve_introspection_input_value(array $argDef, array $sels, array $schema, array $fragments): array {
    $result = [];
    foreach ($sels as $sel) {
        if (($sel['type'] ?? '') === 'fragment_spread') continue;
        $name = $sel['name'] ?? '';
        $key = $sel['alias'] ?? $name;

        switch ($name) {
            case 'name':
                $result[$key] = $argDef['name'] ?? '';
                break;
            case 'description':
                $result[$key] = $argDef['description'] ?? null;
                break;
            case 'type':
                $result[$key] = resolve_introspection_type_ref($argDef['type'] ?? [], $sel['selections'] ?? [], $schema, $fragments);
                break;
            case 'defaultValue':
                $result[$key] = isset($argDef['defaultValue']) ? json_encode($argDef['defaultValue']) : null;
                break;
        }
    }
    return $result;
}

function resolve_introspection_type_ref(array $typeRef, array $sels, array $schema, array $fragments): array {
    if (empty($sels)) {
        return ['name' => $typeRef['name'] ?? null, 'kind' => $typeRef['kind'] ?? 'SCALAR'];
    }

    $result = [];
    foreach ($sels as $sel) {
        if (($sel['type'] ?? '') === 'fragment_spread') {
            $fragName = $sel['name'];
            if (isset($fragments[$fragName])) {
                $fragResult = resolve_introspection_type_ref($typeRef, $fragments[$fragName]['selections'], $schema, $fragments);
                $result = array_merge($result, $fragResult);
            }
            continue;
        }
        $name = $sel['name'] ?? '';
        $key = $sel['alias'] ?? $name;

        switch ($name) {
            case 'name':
                $result[$key] = $typeRef['name'] ?? null;
                break;
            case 'kind':
                $result[$key] = $typeRef['kind'] ?? 'SCALAR';
                break;
            case 'ofType':
                if (isset($typeRef['ofType'])) {
                    $result[$key] = resolve_introspection_type_ref($typeRef['ofType'], $sel['selections'] ?? [], $schema, $fragments);
                } else {
                    $result[$key] = null;
                }
                break;
            case 'description':
                $result[$key] = $typeRef['description'] ?? null;
                break;
            case 'fields':
                $result[$key] = null;
                break;
            case 'inputFields':
                $result[$key] = null;
                break;
            case 'interfaces':
                $result[$key] = [];
                break;
            case 'enumValues':
                $result[$key] = null;
                break;
            case 'possibleTypes':
                $result[$key] = null;
                break;
            case 'specifiedByURL':
            case 'specifiedByUrl':
                $result[$key] = null;
                break;
        }
    }
    return $result;
}

function build_introspection_schema(): array {
    // Helper to build type references
    $nonNull = fn(array $inner) => ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $inner];
    $listOf = fn(array $inner) => ['kind' => 'LIST', 'name' => null, 'ofType' => $inner];
    $named = fn(string $name, string $kind = 'OBJECT') => ['kind' => $kind, 'name' => $name, 'ofType' => null];
    $scalar = fn(string $name) => $named($name, 'SCALAR');

    $types = [];

    // Scalars
    foreach (['String', 'Int', 'Boolean', 'Float', 'ID'] as $s) {
        $types[$s] = ['name' => $s, 'kind' => 'SCALAR', 'description' => "Built-in {$s} scalar"];
    }
    $types['JSON'] = ['name' => 'JSON', 'kind' => 'SCALAR', 'description' => 'Arbitrary JSON value'];

    // Query type
    $types['Query'] = [
        'name' => 'Query',
        'kind' => 'OBJECT',
        'description' => 'Root query type',
        'fields' => [
            [
                'name' => 'collections',
                'description' => 'List all collections',
                'args' => [],
                'type' => $nonNull($listOf($nonNull($named('Collection')))),
            ],
            [
                'name' => 'collection',
                'description' => 'Get a single collection by slug',
                'args' => [
                    ['name' => 'slug', 'description' => 'Collection slug', 'type' => $nonNull($scalar('String'))],
                ],
                'type' => $named('Collection'),
            ],
            [
                'name' => 'items',
                'description' => 'List items in a collection with pagination',
                'args' => [
                    ['name' => 'collection', 'description' => 'Collection slug', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'limit', 'description' => 'Max items to return', 'type' => $scalar('Int'), 'defaultValue' => 10],
                    ['name' => 'offset', 'description' => 'Items to skip', 'type' => $scalar('Int'), 'defaultValue' => 0],
                    ['name' => 'orderBy', 'description' => 'Sort field', 'type' => $scalar('String')],
                    ['name' => 'order', 'description' => 'Sort direction (ASC/DESC)', 'type' => $scalar('String')],
                    ['name' => 'folder', 'description' => 'Folder slug for filtering', 'type' => $scalar('String')],
                    ['name' => 'label', 'description' => 'Label slug for filtering', 'type' => $scalar('String')],
                ],
                'type' => $nonNull($named('ItemConnection')),
            ],
            [
                'name' => 'item',
                'description' => 'Get a single item by collection + slug',
                'args' => [
                    ['name' => 'collection', 'description' => 'Collection slug', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'slug', 'description' => 'Item slug', 'type' => $nonNull($scalar('String'))],
                ],
                'type' => $named('Item'),
            ],
            [
                'name' => 'pages',
                'description' => 'List all pages',
                'args' => [],
                'type' => $nonNull($listOf($nonNull($named('Page')))),
            ],
            [
                'name' => 'page',
                'description' => 'Get a single page by path',
                'args' => [
                    ['name' => 'path', 'description' => 'Page path (e.g. "/" or "/about")', 'type' => $nonNull($scalar('String'))],
                ],
                'type' => $named('Page'),
            ],
            [
                'name' => 'globals',
                'description' => 'Get all global field values',
                'args' => [],
                'type' => $scalar('JSON'),
            ],
            [
                'name' => 'menus',
                'description' => 'List all menus',
                'args' => [],
                'type' => $nonNull($listOf($nonNull($named('MenuSummary')))),
            ],
            [
                'name' => 'menu',
                'description' => 'Get a single menu by slug with its items',
                'args' => [
                    ['name' => 'slug', 'description' => 'Menu slug', 'type' => $nonNull($scalar('String'))],
                ],
                'type' => $named('Menu'),
            ],
            [
                'name' => 'media',
                'description' => 'List media files with pagination',
                'args' => [
                    ['name' => 'limit', 'description' => 'Max items', 'type' => $scalar('Int'), 'defaultValue' => 50],
                    ['name' => 'offset', 'description' => 'Items to skip', 'type' => $scalar('Int'), 'defaultValue' => 0],
                ],
                'type' => $nonNull($named('MediaConnection')),
            ],
            [
                'name' => 'folders',
                'description' => 'List folders, optionally filtered by collection',
                'args' => [
                    ['name' => 'collection', 'description' => 'Filter by collection slug', 'type' => $scalar('String')],
                ],
                'type' => $nonNull($listOf($nonNull($named('Folder')))),
            ],
            [
                'name' => 'labels',
                'description' => 'List labels in a folder',
                'args' => [
                    ['name' => 'folder', 'description' => 'Folder slug', 'type' => $nonNull($scalar('String'))],
                ],
                'type' => $nonNull($listOf($nonNull($named('Label')))),
            ],
            [
                'name' => 'schema',
                'description' => 'Full site schema: collections, pages, globals, folders',
                'args' => [],
                'type' => $named('SiteSchema'),
            ],
        ],
    ];

    // Collection type
    $types['Collection'] = [
        'name' => 'Collection',
        'kind' => 'OBJECT',
        'description' => 'A content collection',
        'fields' => [
            ['name' => 'id', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'slug', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'name', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'singularName', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'urlPattern', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'sortField', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'sortDirection', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'itemsPerPage', 'args' => [], 'type' => $scalar('Int')],
            ['name' => 'fields', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('FieldDef'))))],
            ['name' => 'itemCount', 'args' => [], 'type' => $nonNull($scalar('Int'))],
        ],
    ];

    $types['FieldDef'] = [
        'name' => 'FieldDef',
        'kind' => 'OBJECT',
        'description' => 'Field definition',
        'fields' => [
            ['name' => 'name', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'type', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'label', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'required', 'args' => [], 'type' => $scalar('Boolean')],
        ],
    ];

    $types['Item'] = [
        'name' => 'Item',
        'kind' => 'OBJECT',
        'description' => 'A collection item (entry)',
        'fields' => [
            ['name' => 'id', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'slug', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'title', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'url', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'status', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'createdAt', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'updatedAt', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'publishedAt', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'fields', 'args' => [], 'type' => $scalar('JSON')],
            ['name' => 'labels', 'args' => [], 'type' => $scalar('JSON')],
        ],
    ];

    $types['ItemConnection'] = [
        'name' => 'ItemConnection',
        'kind' => 'OBJECT',
        'description' => 'Paginated list of items',
        'fields' => [
            ['name' => 'data', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('Item'))))],
            ['name' => 'total', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'limit', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'offset', 'args' => [], 'type' => $nonNull($scalar('Int'))],
        ],
    ];

    $types['Page'] = [
        'name' => 'Page',
        'kind' => 'OBJECT',
        'description' => 'A CMS page',
        'fields' => [
            ['name' => 'id', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'path', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'title', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'metaTitle', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'metaDescription', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'fields', 'args' => [], 'type' => $scalar('JSON')],
        ],
    ];

    $types['Menu'] = [
        'name' => 'Menu',
        'kind' => 'OBJECT',
        'description' => 'A navigation menu with items',
        'fields' => [
            ['name' => 'slug', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'name', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'items', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('MenuItem'))))],
        ],
    ];

    $types['MenuItem'] = [
        'name' => 'MenuItem',
        'kind' => 'OBJECT',
        'description' => 'A menu item',
        'fields' => [
            ['name' => 'label', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'url', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'target', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'children', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('MenuItem'))))],
        ],
    ];

    $types['MenuSummary'] = [
        'name' => 'MenuSummary',
        'kind' => 'OBJECT',
        'description' => 'A menu summary (without full item tree)',
        'fields' => [
            ['name' => 'slug', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'name', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'itemCount', 'args' => [], 'type' => $nonNull($scalar('Int'))],
        ],
    ];

    $types['MediaItem'] = [
        'name' => 'MediaItem',
        'kind' => 'OBJECT',
        'description' => 'An uploaded media file',
        'fields' => [
            ['name' => 'id', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'filename', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'originalName', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'path', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'thumbPath', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'mimeType', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'fileSize', 'args' => [], 'type' => $scalar('Int')],
            ['name' => 'width', 'args' => [], 'type' => $scalar('Int')],
            ['name' => 'height', 'args' => [], 'type' => $scalar('Int')],
            ['name' => 'altText', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'uploadedAt', 'args' => [], 'type' => $scalar('String')],
        ],
    ];

    $types['MediaConnection'] = [
        'name' => 'MediaConnection',
        'kind' => 'OBJECT',
        'description' => 'Paginated list of media files',
        'fields' => [
            ['name' => 'data', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('MediaItem'))))],
            ['name' => 'total', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'limit', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'offset', 'args' => [], 'type' => $nonNull($scalar('Int'))],
        ],
    ];

    $types['Folder'] = [
        'name' => 'Folder',
        'kind' => 'OBJECT',
        'description' => 'A content folder (taxonomy)',
        'fields' => [
            ['name' => 'slug', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'name', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'singularName', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'type', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'collectionSlug', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'labelCount', 'args' => [], 'type' => $nonNull($scalar('Int'))],
        ],
    ];

    $types['Label'] = [
        'name' => 'Label',
        'kind' => 'OBJECT',
        'description' => 'A label within a folder',
        'fields' => [
            ['name' => 'id', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'slug', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'name', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'description', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'parentId', 'args' => [], 'type' => $scalar('Int')],
            ['name' => 'itemCount', 'args' => [], 'type' => $nonNull($scalar('Int'))],
            ['name' => 'data', 'args' => [], 'type' => $scalar('JSON')],
        ],
    ];

    $types['SiteSchema'] = [
        'name' => 'SiteSchema',
        'kind' => 'OBJECT',
        'description' => 'Full site schema',
        'fields' => [
            ['name' => 'collections', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('Collection'))))],
            ['name' => 'pages', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('PageSchema'))))],
            ['name' => 'globals', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('FieldDef'))))],
            ['name' => 'folders', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('Folder'))))],
        ],
    ];

    $types['PageSchema'] = [
        'name' => 'PageSchema',
        'kind' => 'OBJECT',
        'description' => 'Page with field definitions',
        'fields' => [
            ['name' => 'path', 'args' => [], 'type' => $nonNull($scalar('String'))],
            ['name' => 'title', 'args' => [], 'type' => $scalar('String')],
            ['name' => 'fields', 'args' => [], 'type' => $nonNull($listOf($nonNull($named('FieldDef'))))],
        ],
    ];

    // Mutation type
    $types['Mutation'] = [
        'name' => 'Mutation',
        'kind' => 'OBJECT',
        'description' => 'Root mutation type (requires Bearer API key auth)',
        'fields' => [
            [
                'name' => 'createItem',
                'description' => 'Create a new collection item',
                'args' => [
                    ['name' => 'collection', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'slug', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'status', 'type' => $scalar('String'), 'defaultValue' => 'draft'],
                    ['name' => 'data', 'type' => $nonNull($scalar('JSON'))],
                ],
                'type' => $nonNull($named('Item')),
            ],
            [
                'name' => 'updateItem',
                'description' => 'Update an existing item (partial update)',
                'args' => [
                    ['name' => 'id', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'data', 'type' => $scalar('JSON')],
                    ['name' => 'status', 'type' => $scalar('String')],
                    ['name' => 'slug', 'type' => $scalar('String')],
                ],
                'type' => $nonNull($named('Item')),
            ],
            [
                'name' => 'deleteItem',
                'description' => 'Delete an item by ID',
                'args' => [
                    ['name' => 'id', 'type' => $nonNull($scalar('Int'))],
                ],
                'type' => $nonNull($scalar('Boolean')),
            ],
            [
                'name' => 'updatePage',
                'description' => 'Update a page and its fields',
                'args' => [
                    ['name' => 'id', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'fields', 'type' => $scalar('JSON')],
                    ['name' => 'metaTitle', 'type' => $scalar('String')],
                    ['name' => 'metaDescription', 'type' => $scalar('String')],
                    ['name' => 'status', 'type' => $scalar('String')],
                ],
                'type' => $nonNull($named('Page')),
            ],
            [
                'name' => 'updateGlobals',
                'description' => 'Update global field values',
                'args' => [
                    ['name' => 'fields', 'type' => $nonNull($scalar('JSON'))],
                ],
                'type' => $nonNull($scalar('Boolean')),
            ],
            [
                'name' => 'deleteMedia',
                'description' => 'Delete a media file',
                'args' => [
                    ['name' => 'id', 'type' => $nonNull($scalar('Int'))],
                ],
                'type' => $nonNull($scalar('Boolean')),
            ],
            [
                'name' => 'assignLabels',
                'description' => 'Assign labels to an item',
                'args' => [
                    ['name' => 'itemId', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'labelIds', 'type' => $nonNull($listOf($nonNull($scalar('Int'))))],
                ],
                'type' => $nonNull($scalar('Boolean')),
            ],
            [
                'name' => 'removeLabels',
                'description' => 'Remove labels from an item',
                'args' => [
                    ['name' => 'itemId', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'labelIds', 'type' => $nonNull($listOf($nonNull($scalar('Int'))))],
                ],
                'type' => $nonNull($scalar('Boolean')),
            ],
            [
                'name' => 'createCollection',
                'description' => 'Create a new collection',
                'args' => [
                    ['name' => 'name', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'slug', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'singularName', 'type' => $scalar('String')],
                    ['name' => 'urlPattern', 'type' => $scalar('String')],
                    ['name' => 'schema', 'type' => $scalar('JSON')],
                ],
                'type' => $nonNull($named('Collection')),
            ],
            [
                'name' => 'updateCollection',
                'description' => 'Update an existing collection',
                'args' => [
                    ['name' => 'id', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'name', 'type' => $scalar('String')],
                    ['name' => 'schema', 'type' => $scalar('JSON')],
                    ['name' => 'urlPattern', 'type' => $scalar('String')],
                ],
                'type' => $nonNull($named('Collection')),
            ],
            [
                'name' => 'createFolder',
                'description' => 'Create a new folder in a collection',
                'args' => [
                    ['name' => 'collectionId', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'name', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'slug', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'type', 'type' => $scalar('String'), 'defaultValue' => 'flat'],
                ],
                'type' => $nonNull($named('Folder')),
            ],
            [
                'name' => 'createLabel',
                'description' => 'Create a new label in a folder',
                'args' => [
                    ['name' => 'folderId', 'type' => $nonNull($scalar('Int'))],
                    ['name' => 'name', 'type' => $nonNull($scalar('String'))],
                    ['name' => 'slug', 'type' => $nonNull($scalar('String'))],
                ],
                'type' => $nonNull($named('Label')),
            ],
        ],
    ];

    return ['types' => $types];
}
