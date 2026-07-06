 <?php
define('DB_PATH', __DIR__ . '/data/app.db');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        initDB($pdo);
    }
    return $pdo;
}

function initDB(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'viewer',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            last_login TEXT
        )
    ");
}

function getUsers(): array
{
    $stmt = getDB()->query('SELECT id, username, role, created_at, last_login FROM users ORDER BY id ASC');
    return $stmt->fetchAll();
}

function getUser(string $username): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getUserById(int $id): ?array
{
    $stmt = getDB()->prepare('SELECT id, username, role, created_at, last_login FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function createUser(string $username, string $passwordHash, string $role = 'viewer'): bool
{
    $stmt = getDB()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    return $stmt->execute([$username, $passwordHash, $role]);
}

function deleteUser(int $id): bool
{
    $stmt = getDB()->prepare('DELETE FROM users WHERE id = ?');
    return $stmt->execute([$id]);
}

function updateUserPassword(int $id, string $newHash): bool
{
    $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    return $stmt->execute([$newHash, $id]);
}

function updateUserLogin(string $username): void
{
    $stmt = getDB()->prepare('UPDATE users SET last_login = datetime("now","localtime") WHERE username = ?');
    $stmt->execute([$username]);
}

function hasUsers(): bool
{
    $stmt = getDB()->query('SELECT COUNT(*) FROM users');
    return $stmt->fetchColumn() > 0;
}
