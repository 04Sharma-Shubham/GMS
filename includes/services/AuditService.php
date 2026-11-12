<?php

declare(strict_types=1);

class AuditService
{
    public static function log(int $gymId, ?int $userId, string $action, string $entityType, ?int $entityId, ?array $old, ?array $new, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?: db();
        $pdo->prepare('INSERT INTO audit_logs (gym_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                $gymId,
                $userId,
                $action,
                $entityType,
                $entityId,
                $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
                PHP_SAPI === 'cli' ? null : client_ip(),
                utc_now(),
            ]);
    }
}
