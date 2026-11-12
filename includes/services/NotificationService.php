<?php

declare(strict_types=1);

class NotificationService
{
    public static function push(?PDO $pdo, int $gymId, ?int $userId, string $type, string $title, string $body, ?string $link = null): void
    {
        $pdo = $pdo ?: db();
        $pdo->prepare('INSERT INTO notifications (gym_id, user_id, type, title, body, link, is_read, created_at) VALUES (?,?,?,?,?,?,0,?)')
            ->execute([$gymId, $userId, $type, clip($title, 180), clip($body, 500), $link, utc_now()]);
    }

    public static function fanout(?PDO $pdo, int $gymId, array $roles, string $type, string $title, string $body, ?string $link = null): void
    {
        if (!$roles) {
            return;
        }
        $pdo = $pdo ?: db();
        $marks = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT id FROM users WHERE gym_id = ? AND status = 'active' AND role IN ($marks)");
        $stmt->execute(array_merge([$gymId], array_values($roles)));
        foreach ($stmt->fetchAll() as $row) {
            self::push($pdo, $gymId, (int) $row['id'], $type, $title, $body, $link);
        }
    }

    public static function unreadCount(int $gymId, int $userId): int
    {
        $row = db_one('SELECT COUNT(*) AS c FROM notifications WHERE gym_id = ? AND user_id = ? AND is_read = 0', [$gymId, $userId]);
        return (int) ($row['c'] ?? 0);
    }

    public static function latest(int $gymId, int $userId, int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));
        return db_all("SELECT * FROM notifications WHERE gym_id = ? AND user_id = ? ORDER BY id DESC LIMIT {$limit}", [$gymId, $userId]);
    }

    public static function markRead(int $gymId, int $userId, ?int $id = null): void
    {
        if ($id) {
            db_exec('UPDATE notifications SET is_read = 1 WHERE id = ? AND gym_id = ? AND user_id = ?', [$id, $gymId, $userId]);
            return;
        }
        db_exec('UPDATE notifications SET is_read = 1 WHERE gym_id = ? AND user_id = ?', [$gymId, $userId]);
    }
}
