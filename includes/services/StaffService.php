<?php

declare(strict_types=1);

class StaffService
{
    public static function create(int $gymId, array $actor, array $input): int
    {
        $role = (string) ($input['role'] ?? '');
        self::assertRole($actor['role'], $role);
        $name = clip($input['name'] ?? '', 160);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($name === '' || !valid_email($email) || !valid_password($password)) {
            throw new AppException('Name, a valid email, and a password with a letter and a number are required.');
        }
        if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new AppException('That email is already used.');
        }
        $phone = clip($input['phone'] ?? '', 30);
        if ($phone !== '' && !phone_is_valid($phone)) {
            throw new AppException('Enter a valid phone number.');
        }
        $now = utc_now();
        return db_tx(function (PDO $pdo) use ($gymId, $actor, $input, $role, $name, $email, $password, $phone, $now) {
            $pdo->prepare('INSERT INTO users (gym_id, name, email, phone, password_hash, role, status, created_at, updated_at) VALUES (?,?,?,?,?,?,\'active\',?,?)')
                ->execute([$gymId, $name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), $role, $now, $now]);
            $id = (int) $pdo->lastInsertId();
            self::saveProfile($pdo, $gymId, $id, $input, $now, true);
            AuditService::log($gymId, (int) $actor['id'], 'staff.created', 'user', $id, null, ['summary' => 'Added ' . $name . ' as ' . $role], $pdo);
            return $id;
        });
    }

    public static function update(int $gymId, array $actor, int $userId, array $input): void
    {
        $user = db_one('SELECT * FROM users WHERE id = ? AND gym_id = ?', [$userId, $gymId]);
        if (!$user) {
            throw new AppException('Staff member not found.');
        }
        $role = (string) ($input['role'] ?? $user['role']);
        if ($role !== $user['role']) {
            self::assertRole($actor['role'], $role);
            if ($user['role'] === 'owner') {
                $owners = db_one("SELECT COUNT(*) AS c FROM users WHERE gym_id = ? AND role = 'owner' AND status = 'active'", [$gymId]);
                if ((int) $owners['c'] <= 1) {
                    throw new AppException('The last gym owner cannot be demoted.');
                }
            }
        } elseif ($actor['role'] !== 'owner' && $user['role'] === 'owner') {
            throw new AppException('Only an owner can edit another owner.');
        }
        $name = clip($input['name'] ?? '', 160);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($name === '' || !valid_email($email)) {
            throw new AppException('Name and a valid email are required.');
        }
        $other = db_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId]);
        if ($other) {
            throw new AppException('That email is already used.');
        }
        $phone = clip($input['phone'] ?? '', 30);
        $status = ($input['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
        if ((int) $actor['id'] === $userId && $status === 'disabled') {
            throw new AppException('You cannot disable your own login.');
        }
        $now = utc_now();
        db_tx(function (PDO $pdo) use ($gymId, $actor, $user, $userId, $input, $role, $name, $email, $phone, $status, $now) {
            $params = [$name, $email, $phone ?: null, $role, $status, $now, $userId, $gymId];
            $sql = 'UPDATE users SET name=?, email=?, phone=?, role=?, status=?, updated_at=? WHERE id=? AND gym_id=?';
            if (!empty($input['password'])) {
                if (!valid_password((string) $input['password'])) {
                    throw new AppException('The new password needs 8 characters, a letter, and a number.');
                }
                $sql = 'UPDATE users SET name=?, email=?, phone=?, role=?, status=?, password_hash=?, updated_at=? WHERE id=? AND gym_id=?';
                $params = [$name, $email, $phone ?: null, $role, $status, password_hash((string) $input['password'], PASSWORD_DEFAULT), $now, $userId, $gymId];
            }
            $pdo->prepare($sql)->execute($params);
            self::saveProfile($pdo, $gymId, $userId, $input, $now, false);
            AuditService::log($gymId, (int) $actor['id'], 'staff.updated', 'user', $userId, ['role' => $user['role'], 'status' => $user['status']], [
                'summary' => 'Updated ' . $name,
                'role' => $role,
                'status' => $status,
            ], $pdo);
        });
    }

    public static function saveSchedule(int $gymId, int $userId, int $day, string $start, string $end, string $notes = ''): void
    {
        if ($day < 0 || $day > 6 || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
            throw new AppException('Choose a weekday and a valid time range.');
        }
        $now = utc_now();
        db_insert('INSERT INTO trainer_schedules (gym_id, user_id, day_of_week, start_time, end_time, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)', [
            $gymId, $userId, $day, $start . ':00', $end . ':00', clip($notes, 255) ?: null, $now, $now,
        ]);
    }

    public static function commission(int $gymId, int $trainerId, string $from, string $to): array
    {
        $profile = db_one('SELECT * FROM staff_profiles WHERE gym_id = ? AND user_id = ?', [$gymId, $trainerId]);
        $percent = money_norm($profile['commission_percent'] ?? '0');
        $row = db_one("SELECT COALESCE(SUM(p.amount),0) AS total FROM payments p
            JOIN invoices i ON i.id = p.invoice_id
            JOIN member_subscriptions s ON s.id = i.subscription_id
            JOIN membership_plans pl ON pl.id = s.plan_id
            JOIN members m ON m.id = p.member_id
            WHERE p.gym_id = ? AND p.status = 'recorded' AND pl.is_personal_training = 1 AND m.trainer_id = ? AND p.payment_date BETWEEN ? AND ?", [$gymId, $trainerId, $from, $to]);
        $base = money_norm($row['total'] ?? '0');
        return ['base' => $base, 'percent' => $percent, 'commission' => money_percent($base, $percent)];
    }

    private static function assertRole(string $actorRole, string $role): void
    {
        $allowed = $actorRole === 'owner' ? ['manager', 'receptionist', 'trainer'] : ['receptionist', 'trainer'];
        if (!in_array($role, $allowed, true)) {
            throw new AppException('You cannot assign that role.');
        }
    }

    private static function saveProfile(PDO $pdo, int $gymId, int $userId, array $input, string $now, bool $insert): void
    {
        $salary = money_norm($input['salary'] ?? '0');
        $commission = money_norm($input['commission_percent'] ?? '0');
        if (money_comp($salary, '0') < 0 || money_comp($commission, '0') < 0 || money_comp($commission, '100') > 0) {
            throw new AppException('Salary and commission must be valid percentages or amounts.');
        }
        $joining = (string) ($input['joining_date'] ?? '');
        if ($joining !== '' && !valid_date($joining)) {
            throw new AppException('Joining date is not valid.');
        }
        if ($insert) {
            $pdo->prepare('INSERT INTO staff_profiles (gym_id, user_id, designation, salary, commission_percent, joining_date, bio, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$gymId, $userId, clip($input['designation'] ?? '', 120) ?: null, $salary, $commission, $joining ?: null, clip($input['bio'] ?? '', 2000) ?: null, $now, $now]);
            return;
        }
        $exists = $pdo->prepare('SELECT id FROM staff_profiles WHERE user_id = ?');
        $exists->execute([$userId]);
        if (!$exists->fetch()) {
            self::saveProfile($pdo, $gymId, $userId, $input, $now, true);
            return;
        }
        $pdo->prepare('UPDATE staff_profiles SET designation=?, salary=?, commission_percent=?, joining_date=?, bio=?, updated_at=? WHERE user_id=? AND gym_id=?')
            ->execute([clip($input['designation'] ?? '', 120) ?: null, $salary, $commission, $joining ?: null, clip($input['bio'] ?? '', 2000) ?: null, $now, $userId, $gymId]);
    }
}
