<?php
// ─── SQLite connection ────────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/trader.db';
        $pdo  = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // ── Schema ──────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS instruments (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol       TEXT NOT NULL UNIQUE,
                price_points INTEGER NOT NULL DEFAULT 1
            );
        ");
        // Migrate: rename pip -> price_points on existing DBs (SQLite 3.25+)
        try {
            $pdo->exec("ALTER TABLE instruments RENAME COLUMN pip TO price_points");
        } catch (PDOException $e) { /* already renamed or doesn't exist */ }
        // Migrate: add default_lot if this is an existing DB without it
        try {
            $pdo->exec("ALTER TABLE instruments ADD COLUMN default_lot REAL NOT NULL DEFAULT 0.10");
        } catch (PDOException $e) { /* column already exists */ }
        // Seed default rows (columns now guaranteed to exist)
        // Migrate: drop label column if it exists (SQLite 3.35+)
        try {
            $pdo->exec("ALTER TABLE instruments DROP COLUMN label");
        } catch (PDOException $e) { /* already dropped or doesn't exist */ }
        $pdo->exec("
            INSERT OR IGNORE INTO instruments (symbol, price_points, default_lot) VALUES
                ('USOIL',  1, 0.10),
                ('XAUUSD', 1, 0.10);
        ");

        // ── Users table ─────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                api_key       TEXT NOT NULL UNIQUE
            );
        ");
        // Seed default admin user (only on first run)
        $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $hash    = password_hash('admin', PASSWORD_DEFAULT);
            $api_key = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO users (username, password_hash, api_key) VALUES (?, ?, ?)")
                ->execute(['admin', $hash, $api_key]);
        }
        // ── Trades table ─────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS trades (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL,
                symbol       TEXT NOT NULL,
                direction    TEXT NOT NULL,
                lot          REAL NOT NULL,
                status       TEXT NOT NULL DEFAULT 'pending',
                error_msg    TEXT,
                created_at   INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                executed_at  INTEGER
            );
        ");
        try {
            $pdo->exec("ALTER TABLE trades ADD COLUMN num_trades INTEGER NOT NULL DEFAULT 1");
        } catch (PDOException $e) { /* already exists */ }

        // ── App settings table (universal key-value pairs) ────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        ");
        $pdo->exec("
            INSERT OR IGNORE INTO app_settings (key, value) VALUES
                ('good_price_expansion', '20'),
                ('max_trades', '100'),
                ('default_num_trades', '1');
        ");

        // ── Manage commands table ─────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS manage_commands (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                command    TEXT NOT NULL,
                status     TEXT NOT NULL DEFAULT 'pending',
                result_msg TEXT,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );
        ");
    }
    return $pdo;
}
