<?php
/**
 * Outpost CMS — SQLite Database Wrapper
 */

require_once __DIR__ . '/config.php';

class OutpostDB {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance === null) {
            if (!file_exists(OUTPOST_DB_PATH)) {
                throw new RuntimeException('Database not found. Please run install.php first.');
            }
            self::$instance = new PDO('sqlite:' . OUTPOST_DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
        }
        return self::$instance;
    }

    public static function reconnect(): void {
        self::$instance = null;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchOne(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        self::query(
            "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );
        return (int) self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $stmt = self::query(
            "UPDATE {$table} SET {$set} WHERE {$where}",
            [...array_values($data), ...$whereParams]
        );
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        $stmt = self::query("DELETE FROM {$table} WHERE {$where}", $params);
        return $stmt->rowCount();
    }

    public static function createSchema(): void {
        $db = self::connect();
        $db->exec("
            CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT UNIQUE NOT NULL,
                title TEXT DEFAULT '',
                meta_title TEXT DEFAULT '',
                meta_description TEXT DEFAULT '',
                discovered_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS fields (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL,
                theme TEXT NOT NULL DEFAULT '',
                field_name TEXT NOT NULL,
                field_type TEXT NOT NULL,
                content TEXT DEFAULT '',
                default_value TEXT DEFAULT '',
                options TEXT DEFAULT '',
                sort_order INTEGER DEFAULT 0,
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE(page_id, theme, field_name),
                FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS collections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                singular_name TEXT DEFAULT '',
                schema TEXT NOT NULL DEFAULT '{}',
                url_pattern TEXT DEFAULT '',
                template_path TEXT DEFAULT '',
                sort_field TEXT DEFAULT 'created_at',
                sort_direction TEXT DEFAULT 'DESC',
                items_per_page INTEGER DEFAULT 10,
                created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS collection_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                collection_id INTEGER NOT NULL,
                slug TEXT NOT NULL,
                status TEXT DEFAULT 'draft',
                data TEXT NOT NULL DEFAULT '{}',
                sort_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                published_at TEXT,
                UNIQUE(collection_id, slug),
                FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                original_name TEXT NOT NULL,
                path TEXT NOT NULL,
                thumb_path TEXT DEFAULT '',
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                width INTEGER DEFAULT 0,
                height INTEGER DEFAULT 0,
                alt_text TEXT DEFAULT '',
                focal_x INTEGER DEFAULT 50,
                focal_y INTEGER DEFAULT 50,
                folder_id INTEGER DEFAULT NULL,
                uploaded_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS media_folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT DEFAULT '',
                parent_id INTEGER DEFAULT NULL REFERENCES media_folders(id) ON DELETE CASCADE,
                sort_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS media_folder_items (
                media_id INTEGER NOT NULL,
                folder_id INTEGER NOT NULL,
                PRIMARY KEY (media_id, folder_id),
                FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
                FOREIGN KEY (folder_id) REFERENCES media_folders(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT DEFAULT '',
                display_name TEXT DEFAULT '',
                avatar TEXT DEFAULT '',
                bio TEXT DEFAULT '',
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'admin',
                created_at TEXT DEFAULT (datetime('now')),
                last_login TEXT
            );

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT DEFAULT ''
            );

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

            CREATE TABLE IF NOT EXISTS revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                meta TEXT NOT NULL DEFAULT '{}',
                created_by INTEGER,
                created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT '',
                key_hash TEXT NOT NULL,
                key_prefix TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                last_used_at TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_fields_page_theme ON fields(page_id, theme);
            CREATE INDEX IF NOT EXISTS idx_collection_items_coll_status ON collection_items(collection_id, status);
            CREATE INDEX IF NOT EXISTS idx_revisions_entity ON revisions(entity_type, entity_id, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_media_folder_items_folder ON media_folder_items(folder_id);
            CREATE INDEX IF NOT EXISTS idx_media_folder_items_media ON media_folder_items(media_id);
        ");
    }
}
