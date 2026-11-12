<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = require dirname(__DIR__) . '/config/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['name'],
        $config['charset']
    );
    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        app_log('error', 'Database connection failed', ['error' => $e->getMessage()]);
        throw new AppException('Database connection failed. Check DB_HOST, DB_NAME, DB_USER and DB_PASS, then import database/database.sql.');
    }
    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) db()->lastInsertId();
}

function db_tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rate_limit(string $key, int $max, int $windowSeconds): void
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $row = db_one('SELECT attempts, window_start FROM rate_limits WHERE action_key = ?', [$key]);
    if (!$row) {
        db_exec('INSERT INTO rate_limits (action_key, attempts, window_start) VALUES (?,?,?)', [$key, 1, $now->format('Y-m-d H:i:s')]);
        return;
    }
    $start = new DateTimeImmutable($row['window_start'], new DateTimeZone('UTC'));
    if (($now->getTimestamp() - $start->getTimestamp()) > $windowSeconds) {
        db_exec('UPDATE rate_limits SET attempts = 1, window_start = ? WHERE action_key = ?', [$now->format('Y-m-d H:i:s'), $key]);
        return;
    }
    if ((int) $row['attempts'] >= $max) {
        throw new AppException('Too many attempts. Wait a few minutes and try again.');
    }
    db_exec('UPDATE rate_limits SET attempts = attempts + 1 WHERE action_key = ?', [$key]);
}
