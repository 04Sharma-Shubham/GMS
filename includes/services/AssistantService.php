<?php

declare(strict_types=1);

class AssistantService
{
    public static function ask(int $gymId, array $user, string $question): array
    {
        $question = trim($question);
        $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
        if (!$gym) {
            return self::pack('Gym not found', 'Sign in again and retry.', []);
        }
        $today = gym_today($gym);
        $scope = ($user['role'] ?? '') === 'trainer' ? (int) $user['id'] : null;
        $showMoney = in_array($user['role'] ?? '', ['owner', 'manager', 'receptionist'], true);
        $q = mb_strtolower($question);

        if ($question === '' || preg_match('/\b(help|what can you|examples)\b/u', $q)) {
            return self::help($showMoney);
        }
        if (preg_match('/\b(attend|checked in|check-?in|who is here|on the floor)\b/u', $q)) {
            return self::attendance($gym, $today, $scope);
        }
        if ($showMoney && preg_match('/\b(revenue|collected|collection|income|earned)\b/u', $q)) {
            return self::revenue($gym, $today, $scope);
        }
        if (preg_match('/\b(frozen|freeze)\b/u', $q)) {
            return self::frozen($gym, $today, $scope, $showMoney);
        }
        if (preg_match('/\b(new member|joined this month|joined recently)\b/u', $q)) {
            return self::joined($gym, $today, $scope);
        }
        if (self::isGeneral($q) && preg_match('/\b(expir|renew|remaining|running|ending|subscription|membership|days left)\b/u', $q)) {
            return self::expiring($gym, $today, $scope, $showMoney);
        }
        if (self::isGeneral($q) && preg_match('/\b(unpaid|partial|owes|owing|owe|dues|outstanding|balance|invoice|billing)\b/u', $q)) {
            if (!$showMoney) {
                return self::pack('Billing is hidden', 'Invoice amounts are available to the owner, manager, and front desk.', []);
            }
            return self::dues($gym, $scope);
        }

        $member = self::findMember($gymId, $question, $scope);
        $aboutMoney = (bool) preg_match('/\b(invoice|bill|billing|payment|balance|due|owe|unpaid|partial)\b/u', $q);
        $aboutPlan = (bool) preg_match('/\b(plan|subscription|membership|expir|renew)\b/u', $q);
        if ($member && ($aboutMoney || $aboutPlan || !self::isGeneral($q))) {
            return self::memberFile($gym, $member, $today, $showMoney);
        }

        if (preg_match('/\b(expir|renew|remaining|days left|ending|subscription left|who.*(left|remain))\b/u', $q)) {
            return self::expiring($gym, $today, $scope, $showMoney);
        }
        if (preg_match('/\b(unpaid|partial|pending|dues|owe|outstanding|balance|invoice|billing)\b/u', $q)) {
            if (!$showMoney) {
                return self::pack('Billing is hidden', 'Invoice amounts are available to the owner, manager, and front desk.', []);
            }
            return self::dues($gym, $scope);
        }
        if (preg_match('/\b(expired member|already expired|lapsed)\b/u', $q)) {
            return self::expiredMembers($gym, $scope);
        }

        return self::pack(
            'I can answer from this gym only',
            'Try “whose membership is ending”, “who still owes money”, “billing for Meera”, or “who is here today”. I read the database. I do not guess.',
            []
        );
    }

    private static function help(bool $showMoney): array
    {
        $lines = [
            'Whose membership is ending, or how many days are left.',
            'Who is frozen, expired, or new this month.',
            'Who is checked in today.',
            'A member’s file: “billing for Meera Iyer”.',
        ];
        if ($showMoney) {
            $lines[] = 'Who still owes money, and what was collected this month.';
        }
        return self::pack('Ask about this gym', 'Answers come from your records for today. Nothing is invented.', array_map(
            static fn (string $line): array => ['label' => $line, 'meta' => '', 'href' => 'assistant?q=' . rawurlencode($line)],
            $lines
        ));
    }

    private static function expiring(array $gym, string $today, ?int $scope, bool $showMoney): array
    {
        $sql = "SELECT s.end_date, s.status, m.id, m.full_name, m.member_code, m.phone, p.name AS plan_name
            FROM member_subscriptions s
            JOIN members m ON m.id = s.member_id
            JOIN membership_plans p ON p.id = s.plan_id
            WHERE s.gym_id = ? AND s.status = 'active' AND s.end_date >= ?";
        $params = [(int) $gym['id'], $today];
        if ($scope) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $scope;
        }
        $sql .= ' ORDER BY s.end_date ASC LIMIT 12';
        $rows = db_all($sql, $params);
        $items = [];
        foreach ($rows as $row) {
            $left = max(0, days_inclusive($today, $row['end_date']) - 1);
            $meta = $row['plan_name'] . ' · ' . ($left === 0 ? 'ends today' : $left . ' day' . ($left === 1 ? '' : 's') . ' left') . ' · ' . fmt_date($row['end_date']);
            $items[] = ['label' => $row['full_name'] . ' · ' . $row['member_code'], 'meta' => $meta, 'href' => 'members/' . $row['id'] . '?tab=membership'];
        }
        $text = $items
            ? count($items) . ' active membership' . (count($items) === 1 ? '' : 's') . ' still running, soonest first.'
            : 'No active membership has time left' . ($scope ? ' on your member list' : '') . '.';
        if (!$showMoney) {
            $text .= ' Plan prices stay on the member page for staff who handle billing.';
        }
        return self::pack('Memberships still running', $text, $items);
    }

    private static function dues(array $gym, ?int $scope): array
    {
        $sql = "SELECT i.id, i.invoice_number, i.balance_due, i.status, i.invoice_date, m.full_name, m.member_code
            FROM invoices i JOIN members m ON m.id = i.member_id
            WHERE i.gym_id = ? AND i.balance_due > 0";
        $params = [(int) $gym['id']];
        if ($scope) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $scope;
        }
        $sql .= ' ORDER BY i.balance_due DESC LIMIT 12';
        $rows = db_all($sql, $params);
        $total = '0.00';
        $items = [];
        foreach ($rows as $row) {
            $total = money_add($total, (string) $row['balance_due']);
            $items[] = [
                'label' => $row['full_name'] . ' · ' . $row['invoice_number'],
                'meta' => status_label($row['status']) . ' · due ' . money($row['balance_due'], $gym) . ' · ' . fmt_date($row['invoice_date']),
                'href' => 'invoices/' . $row['id'],
            ];
        }
        $text = $items
            ? 'Open balance across these invoices: ' . money($total, $gym) . '. An invoice is unpaid until a payment is recorded.'
            : 'No open invoice balances.';
        return self::pack('Who still owes', $text, $items);
    }

    private static function memberFile(array $gym, array $member, string $today, bool $showMoney): array
    {
        $subs = db_all('SELECT s.*, p.name AS plan_name FROM member_subscriptions s JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND s.member_id = ? ORDER BY s.id DESC LIMIT 4', [(int) $gym['id'], $member['id']]);
        $invoices = $showMoney
            ? db_all('SELECT * FROM invoices WHERE gym_id = ? AND member_id = ? ORDER BY id DESC LIMIT 6', [(int) $gym['id'], $member['id']])
            : [];
        $items = [];
        foreach ($subs as $sub) {
            $left = $sub['status'] === 'active' ? max(0, days_inclusive($today, $sub['end_date']) - 1) : null;
            $meta = status_label($sub['status']) . ' · ' . fmt_date($sub['start_date']) . ' to ' . fmt_date($sub['end_date']);
            if ($left !== null) {
                $meta .= ' · ' . ($left === 0 ? 'ends today' : $left . ' days left');
            }
            if ($showMoney) {
                $meta .= ' · ' . money($sub['total_amount'], $gym);
            }
            $items[] = ['label' => $sub['plan_name'], 'meta' => $meta, 'href' => 'members/' . $member['id'] . '?tab=membership'];
        }
        foreach ($invoices as $invoice) {
            $items[] = [
                'label' => $invoice['invoice_number'],
                'meta' => status_label($invoice['status']) . ' · paid ' . money($invoice['amount_paid'], $gym) . ' · due ' . money($invoice['balance_due'], $gym),
                'href' => 'invoices/' . $invoice['id'],
            ];
        }
        $text = $member['full_name'] . ' (' . $member['member_code'] . ') is ' . status_label($member['status']) . '. Phone ' . $member['phone'] . '.';
        if (!$subs) {
            $text .= ' No membership has been assigned yet.';
        }
        return self::pack($member['full_name'], $text, $items);
    }

    private static function attendance(array $gym, string $today, ?int $scope): array
    {
        [$start, $end] = gym_day_utc_bounds($today, $gym);
        $sql = 'SELECT a.check_in_at, a.check_out_at, m.id, m.full_name, m.member_code FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? AND a.check_in_at >= ? AND a.check_in_at < ?';
        $params = [(int) $gym['id'], $start, $end];
        if ($scope) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $scope;
        }
        $sql .= ' ORDER BY a.check_in_at DESC LIMIT 20';
        $rows = db_all($sql, $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'label' => $row['full_name'] . ' · ' . $row['member_code'],
                'meta' => fmt_datetime($row['check_in_at'], $gym) . ($row['check_out_at'] ? ' · out ' . fmt_datetime($row['check_out_at'], $gym) : ' · still inside'),
                'href' => 'members/' . $row['id'] . '?tab=attendance',
            ];
        }
        return self::pack('Today’s attendance', $items ? count($items) . ' visit' . (count($items) === 1 ? '' : 's') . ' on ' . fmt_date($today) . '.' : 'Nobody has checked in yet today.', $items);
    }

    private static function revenue(array $gym, string $today, ?int $scope): array
    {
        $from = substr($today, 0, 8) . '01';
        $sql = "SELECT COALESCE(SUM(p.amount),0) AS total, COUNT(*) AS c FROM payments p JOIN members m ON m.id = p.member_id WHERE p.gym_id = ? AND p.status = 'recorded' AND p.payment_date BETWEEN ? AND ?";
        $params = [(int) $gym['id'], $from, $today];
        if ($scope) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $scope;
        }
        $row = db_one($sql, $params);
        return self::pack(
            'Collected this month',
            money($row['total'] ?? '0', $gym) . ' from ' . (int) ($row['c'] ?? 0) . ' recorded payment' . ((int) ($row['c'] ?? 0) === 1 ? '' : 's') . ' between ' . fmt_date($from) . ' and ' . fmt_date($today) . '.',
            [['label' => 'Open the payments list', 'meta' => 'Cash, UPI, bank, and card', 'href' => 'payments']]
        );
    }

    private static function frozen(array $gym, string $today, ?int $scope, bool $showMoney): array
    {
        $sql = "SELECT s.end_date, s.freeze_start, m.id, m.full_name, m.member_code, p.name AS plan_name
            FROM member_subscriptions s JOIN members m ON m.id = s.member_id JOIN membership_plans p ON p.id = s.plan_id
            WHERE s.gym_id = ? AND s.status = 'frozen'";
        $params = [(int) $gym['id']];
        if ($scope) {
            $sql .= ' AND m.trainer_id = ?';
            $params[] = $scope;
        }
        $rows = db_all($sql . ' ORDER BY s.freeze_start LIMIT 12', $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'label' => $row['full_name'] . ' · ' . $row['plan_name'],
                'meta' => 'Frozen since ' . fmt_date((string) $row['freeze_start']) . ' · end date ' . fmt_date($row['end_date']),
                'href' => 'members/' . $row['id'] . '?tab=membership',
            ];
        }
        return self::pack('Frozen memberships', $items ? 'These members cannot check in until the plan is unfrozen.' : 'No membership is frozen.', $items);
    }

    private static function joined(array $gym, string $today, ?int $scope): array
    {
        $from = substr($today, 0, 8) . '01';
        $sql = 'SELECT id, full_name, member_code, phone, registration_date, status FROM members WHERE gym_id = ? AND registration_date BETWEEN ? AND ?';
        $params = [(int) $gym['id'], $from, $today];
        if ($scope) {
            $sql .= ' AND trainer_id = ?';
            $params[] = $scope;
        }
        $rows = db_all($sql . ' ORDER BY registration_date DESC LIMIT 12', $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'label' => $row['full_name'] . ' · ' . $row['member_code'],
                'meta' => 'Joined ' . fmt_date($row['registration_date']) . ' · ' . status_label($row['status']),
                'href' => 'members/' . $row['id'],
            ];
        }
        return self::pack('New this month', $items ? count($items) . ' member' . (count($items) === 1 ? '' : 's') . ' joined since ' . fmt_date($from) . '.' : 'No new members this month.', $items);
    }

    private static function expiredMembers(array $gym, ?int $scope): array
    {
        $sql = "SELECT id, full_name, member_code, phone FROM members WHERE gym_id = ? AND status = 'expired'";
        $params = [(int) $gym['id']];
        if ($scope) {
            $sql .= ' AND trainer_id = ?';
            $params[] = $scope;
        }
        $rows = db_all($sql . ' ORDER BY full_name LIMIT 12', $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = ['label' => $row['full_name'] . ' · ' . $row['member_code'], 'meta' => $row['phone'], 'href' => 'members/' . $row['id']];
        }
        return self::pack('Expired members', $items ? 'These profiles need a renewal before a normal check-in.' : 'No member is marked expired.', $items);
    }

    private static function findMember(int $gymId, string $question, ?int $scope): ?array
    {
        $clean = preg_replace('/\b(whose|who|what|whats|show|me|the|a|an|for|of|about|and|their|is|are|still|left|remaining|subscription|membership|plan|invoice|invoices|billing|bill|payment|payments|balance|due|unpaid|status|data|whole|please|tell)\b/iu', ' ', $question) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if (mb_strlen($clean) < 3) {
            return null;
        }
        $sql = 'SELECT * FROM members WHERE gym_id = ? AND (full_name LIKE ? OR member_code LIKE ? OR phone LIKE ?)';
        $like = '%' . $clean . '%';
        $params = [$gymId, $like, $like, $like];
        if ($scope) {
            $sql .= ' AND trainer_id = ?';
            $params[] = $scope;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        return db_one($sql, $params);
    }

    private static function isGeneral(string $q): bool
    {
        return (bool) preg_match('/\b(who|whose|list|all|anyone|everybody|everyone)\b/u', $q);
    }

    private static function pack(string $title, string $text, array $rows): array
    {
        return ['title' => $title, 'text' => $text, 'rows' => $rows];
    }
}
