<?php

declare(strict_types=1);

class AttendanceService
{
    public static function checkIn(int $gymId, int $userId, int $memberId, string $method, bool $override, bool $actorCanOverride, string $notes = ''): array
    {
        if (!in_array($method, ['manual', 'qr'], true)) {
            $method = 'manual';
        }
        $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
        $today = gym_today($gym);
        return db_tx(function (PDO $pdo) use ($gymId, $userId, $memberId, $method, $override, $actorCanOverride, $notes, $today) {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND gym_id = ? FOR UPDATE');
            $stmt->execute([$memberId, $gymId]);
            $member = $stmt->fetch();
            if (!$member) {
                throw new AppException('Member not found.');
            }
            if ($member['status'] === 'archived') {
                throw new AppException('Archived members cannot check in.');
            }
            if ($member['status'] === 'suspended') {
                throw new AppException('This membership is suspended.');
            }
            $open = $pdo->prepare('SELECT id FROM attendance WHERE gym_id = ? AND member_id = ? AND check_out_at IS NULL LIMIT 1');
            $open->execute([$gymId, $memberId]);
            if ($open->fetch()) {
                throw new AppException($member['full_name'] . ' is already checked in.');
            }
            $access = MembershipService::accessState($gymId, $memberId, $today);
            $usedOverride = false;
            if ($access['state'] !== 'active') {
                if (!$override) {
                    $message = $access['state'] === 'frozen'
                        ? 'This membership is frozen.'
                        : 'This membership is expired or has not started. An owner or manager can override the check-in.';
                    throw new AppException($message);
                }
                if (!$actorCanOverride) {
                    throw new AppException('You cannot override an expired or frozen membership.');
                }
                $usedOverride = true;
            }
            $now = utc_now();
            $pdo->prepare('INSERT INTO attendance (gym_id, member_id, check_in_at, method, recorded_by, notes, override_expired, created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$gymId, $memberId, $now, $method, $userId ?: null, clip($notes, 255) ?: null, $usedOverride ? 1 : 0, $now]);
            $id = (int) $pdo->lastInsertId();
            AuditService::log($gymId, $userId ?: null, 'attendance.checkin', 'attendance', $id, null, [
                'summary' => 'Checked in ' . $member['full_name'] . ($usedOverride ? ' with override' : ''),
                'member_id' => $memberId,
                'method' => $method,
            ], $pdo);
            return [
                'attendance_id' => $id,
                'member_id' => $memberId,
                'member_name' => $member['full_name'],
                'override' => $usedOverride,
                'checked_in_at' => $now,
            ];
        });
    }

    public static function checkInToken(int $gymId, int $userId, string $token, bool $override, bool $actorCanOverride): array
    {
        if (preg_match('/[a-f0-9]{48}/i', $token, $match)) {
            $token = strtolower($match[0]);
        } else {
            $token = strtolower(trim($token));
        }
        $member = db_one('SELECT id FROM members WHERE gym_id = ? AND qr_token = ?', [$gymId, $token]);
        if (!$member) {
            throw new AppException('That QR code does not match a member of this gym.');
        }
        return self::checkIn($gymId, $userId, (int) $member['id'], 'qr', $override, $actorCanOverride);
    }

    public static function checkOut(int $gymId, int $userId, int $memberId): array
    {
        return db_tx(function (PDO $pdo) use ($gymId, $userId, $memberId) {
            $stmt = $pdo->prepare('SELECT a.id, m.full_name FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? AND a.member_id = ? AND a.check_out_at IS NULL ORDER BY a.id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$gymId, $memberId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new AppException('This member is not checked in.');
            }
            $now = utc_now();
            $pdo->prepare('UPDATE attendance SET check_out_at = ? WHERE id = ?')->execute([$now, $row['id']]);
            AuditService::log($gymId, $userId ?: null, 'attendance.checkout', 'attendance', (int) $row['id'], null, [
                'summary' => 'Checked out ' . $row['full_name'],
                'member_id' => $memberId,
            ], $pdo);
            return ['member_name' => $row['full_name'], 'checked_out_at' => $now];
        });
    }
}
