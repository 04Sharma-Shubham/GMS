<?php

declare(strict_types=1);

class ReportService
{
    public static function dashboard(int $gymId, array $range, ?int $trainerId = null): array
    {
        $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
        $today = $range['today'];
        $memberWhere = 'gym_id = ?';
        $params = [$gymId];
        if ($trainerId) {
            $memberWhere .= ' AND trainer_id = ?';
            $params[] = $trainerId;
        }
        $count = function (string $extra = '', array $extraParams = []) use ($memberWhere, $params): int {
            $row = db_one('SELECT COUNT(*) AS c FROM members WHERE ' . $memberWhere . $extra, array_merge($params, $extraParams));
            return (int) ($row['c'] ?? 0);
        };
        [$startUtc, $endUtc] = gym_day_utc_bounds($today, $gym);
        $attendanceSql = 'SELECT COUNT(DISTINCT a.member_id) AS c FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? AND a.check_in_at >= ? AND a.check_in_at < ?';
        $attendanceParams = [$gymId, $startUtc, $endUtc];
        if ($trainerId) {
            $attendanceSql .= ' AND m.trainer_id = ?';
            $attendanceParams[] = $trainerId;
        }
        $todayAttendance = (int) (db_one($attendanceSql, $attendanceParams)['c'] ?? 0);
        $revenueSql = "SELECT COALESCE(SUM(p.amount),0) AS total FROM payments p JOIN members m ON m.id = p.member_id WHERE p.gym_id = ? AND p.status = 'recorded' AND p.payment_date BETWEEN ? AND ?";
        $revenueParams = [$gymId, $range['from'], $range['to']];
        if ($trainerId) {
            $revenueSql .= ' AND m.trainer_id = ?';
            $revenueParams[] = $trainerId;
        }
        $revenue = (string) (db_one($revenueSql, $revenueParams)['total'] ?? '0');
        $pendingSql = "SELECT COALESCE(SUM(i.balance_due),0) AS total FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.status IN ('unpaid','partial')";
        $pendingParams = [$gymId];
        if ($trainerId) {
            $pendingSql .= ' AND m.trainer_id = ?';
            $pendingParams[] = $trainerId;
        }
        $pending = (string) (db_one($pendingSql, $pendingParams)['total'] ?? '0');
        $expiringSql = "SELECT COUNT(*) AS c FROM member_subscriptions s JOIN members m ON m.id = s.member_id WHERE s.gym_id = ? AND s.status = 'active' AND s.end_date BETWEEN ? AND ?";
        $expiringParams = [$gymId, $today, add_days($today, 7)];
        if ($trainerId) {
            $expiringSql .= ' AND m.trainer_id = ?';
            $expiringParams[] = $trainerId;
        }
        $scope = $trainerId ? ' AND m.trainer_id = ' . (int) $trainerId : '';
        $payments = db_all("SELECT p.*, m.full_name, i.invoice_number FROM payments p JOIN members m ON m.id = p.member_id JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND p.status = 'recorded' {$scope} ORDER BY p.id DESC LIMIT 6", [$gymId]);
        $recentMembers = db_all("SELECT * FROM members m WHERE m.gym_id = ? {$scope} ORDER BY m.id DESC LIMIT 6", [$gymId]);
        $renewals = db_all("SELECT s.*, m.full_name, m.id AS member_id, p.name AS plan_name FROM member_subscriptions s JOIN members m ON m.id = s.member_id JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND s.status = 'active' AND s.end_date BETWEEN ? AND ? {$scope} ORDER BY s.end_date LIMIT 6", [$gymId, $today, add_days($today, 14)]);
        $expired = db_all("SELECT s.*, m.full_name, m.id AS member_id, p.name AS plan_name FROM member_subscriptions s JOIN members m ON m.id = s.member_id JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND (s.status = 'expired' OR (s.status = 'active' AND s.end_date < ?)) {$scope} ORDER BY s.end_date DESC LIMIT 6", [$gymId, $today]);
        $dues = db_all("SELECT i.*, m.full_name FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.balance_due > 0 {$scope} ORDER BY i.balance_due DESC LIMIT 6", [$gymId]);
        $activity = db_all('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.gym_id = ? ORDER BY a.id DESC LIMIT 8', [$gymId]);
        return [
            'kpis' => [
                'total_members' => $count(" AND status <> 'archived'"),
                'active_members' => $count(" AND status = 'active'"),
                'expired_members' => $count(" AND status = 'expired'"),
                'new_members' => $count(' AND registration_date BETWEEN ? AND ?', [$range['from'], $range['to']]),
                'today_attendance' => $todayAttendance,
                'revenue' => money_norm($revenue),
                'pending' => money_norm($pending),
                'expiring' => (int) (db_one($expiringSql, $expiringParams)['c'] ?? 0),
            ],
            'charts' => [
                'revenue' => self::series($gym, 'revenue', $range, $trainerId),
                'growth' => self::series($gym, 'growth', $range, $trainerId),
                'attendance' => self::series($gym, 'attendance', $range, $trainerId),
                'plans' => self::planMix($gymId, $trainerId),
                'payment_status' => self::statusMix($gymId, $range, $trainerId),
            ],
            'recent_members' => $recentMembers,
            'recent_payments' => $payments,
            'renewals' => $renewals,
            'expired' => $expired,
            'dues' => $dues,
            'activity' => $activity,
        ];
    }

    public static function financial(int $gymId, array $range): array
    {
        $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
        $revenue = (string) (db_one("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE gym_id = ? AND status = 'recorded' AND payment_date BETWEEN ? AND ?", [$gymId, $range['from'], $range['to']])['total'] ?? '0');
        $expenses = (string) (db_one('SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE gym_id = ? AND expense_date BETWEEN ? AND ?', [$gymId, $range['from'], $range['to']])['total'] ?? '0');
        $methods = db_all("SELECT method, COALESCE(SUM(amount),0) AS total, COUNT(*) AS c FROM payments WHERE gym_id = ? AND status = 'recorded' AND payment_date BETWEEN ? AND ? GROUP BY method ORDER BY total DESC", [$gymId, $range['from'], $range['to']]);
        $categories = db_all('SELECT c.name, COALESCE(SUM(e.amount),0) AS total FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.gym_id = ? AND e.expense_date BETWEEN ? AND ? GROUP BY c.id, c.name ORDER BY total DESC', [$gymId, $range['from'], $range['to']]);
        $dues = db_all("SELECT i.*, m.full_name, m.phone FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.balance_due > 0 ORDER BY i.balance_due DESC", [$gymId]);
        $outstanding = '0.00';
        foreach ($dues as $due) {
            $outstanding = money_add($outstanding, (string) $due['balance_due']);
        }
        return [
            'revenue' => money_norm($revenue),
            'expenses' => money_norm($expenses),
            'net' => money_sub(money_norm($revenue), money_norm($expenses)),
            'outstanding' => $outstanding,
            'methods' => $methods,
            'categories' => $categories,
            'dues' => $dues,
            'payroll' => (string) (db_one('SELECT COALESCE(SUM(salary),0) AS total FROM staff_profiles WHERE gym_id = ?', [$gymId])['total'] ?? '0'),
            'charts' => self::series($gym, 'revenue', $range, null),
        ];
    }

    public static function series(?array $gym, string $kind, array $range, ?int $trainerId): array
    {
        $gymId = (int) ($gym['id'] ?? 0);
        $map = [];
        if ($kind === 'revenue') {
            $sql = "SELECT payment_date AS d, SUM(amount) AS total FROM payments p JOIN members m ON m.id = p.member_id WHERE p.gym_id = ? AND p.status = 'recorded' AND p.payment_date BETWEEN ? AND ?";
            $params = [$gymId, $range['from'], $range['to']];
            if ($trainerId) {
                $sql .= ' AND m.trainer_id = ?';
                $params[] = $trainerId;
            }
            $sql .= ' GROUP BY payment_date';
            foreach (db_all($sql, $params) as $row) {
                $map[$row['d']] = $row['total'];
            }
        } elseif ($kind === 'growth') {
            $sql = 'SELECT registration_date AS d, COUNT(*) AS total FROM members WHERE gym_id = ? AND registration_date BETWEEN ? AND ?';
            $params = [$gymId, $range['from'], $range['to']];
            if ($trainerId) {
                $sql .= ' AND trainer_id = ?';
                $params[] = $trainerId;
            }
            $sql .= ' GROUP BY registration_date';
            foreach (db_all($sql, $params) as $row) {
                $map[$row['d']] = $row['total'];
            }
        } else {
            $offset = gym_now($gym)->format('P');
            $sql = "SELECT DATE(CONVERT_TZ(a.check_in_at, '+00:00', ?)) AS d, COUNT(*) AS total FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? AND DATE(CONVERT_TZ(a.check_in_at, '+00:00', ?)) BETWEEN ? AND ?";
            $params = [$offset, $gymId, $offset, $range['from'], $range['to']];
            if ($trainerId) {
                $sql .= ' AND m.trainer_id = ?';
                $params[] = $trainerId;
            }
            $sql .= ' GROUP BY d';
            foreach (db_all($sql, $params) as $row) {
                if ($row['d']) {
                    $map[$row['d']] = $row['total'];
                }
            }
        }
        [$labels, $values] = fill_dates($range['from'], $range['to'], $map);
        return ['labels' => $labels, 'values' => $values];
    }

    private static function planMix(int $gymId, ?int $trainerId): array
    {
        $sql = "SELECT p.name, COUNT(*) AS c FROM member_subscriptions s JOIN membership_plans p ON p.id = s.plan_id JOIN members m ON m.id = s.member_id WHERE s.gym_id = ? AND s.status IN ('active','frozen')";
        $params = [$gymId];
        if ($trainerId) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $trainerId;
        }
        $sql .= ' GROUP BY p.id, p.name ORDER BY c DESC';
        $rows = db_all($sql, $params);
        return [
            'labels' => array_column($rows, 'name'),
            'values' => array_map('intval', array_column($rows, 'c')),
        ];
    }

    private static function statusMix(int $gymId, array $range, ?int $trainerId): array
    {
        $sql = "SELECT i.status, COUNT(*) AS c FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.invoice_date BETWEEN ? AND ?";
        $params = [$gymId, $range['from'], $range['to']];
        if ($trainerId) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $trainerId;
        }
        $sql .= ' GROUP BY i.status';
        $out = ['paid' => 0, 'partial' => 0, 'unpaid' => 0];
        foreach (db_all($sql, $params) as $row) {
            $out[$row['status']] = (int) $row['c'];
        }
        return $out;
    }
}
