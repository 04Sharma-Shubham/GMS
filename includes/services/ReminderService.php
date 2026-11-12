<?php

declare(strict_types=1);

class ReminderService
{
    public static function membershipReminders(int $gymId, string $today): array
    {
        $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
        $days = parse_int_list((string) setting($gymId, 'expiry_reminder_days', '7,3,0'));
        $sent = 0;
        $skipped = 0;
        foreach ($days as $day) {
            $target = add_days($today, $day);
            $statuses = $day === 0 ? "s.status IN ('active','expired')" : "s.status = 'active'";
            $rows = db_all("SELECT s.*, m.full_name, m.phone, m.whatsapp_opt_in, m.status AS member_status, p.name AS plan_name
                FROM member_subscriptions s
                JOIN members m ON m.id = s.member_id
                JOIN membership_plans p ON p.id = s.plan_id
                WHERE s.gym_id = ? AND s.end_date = ? AND {$statuses} AND m.status NOT IN ('archived','suspended')", [$gymId, $target]);
            foreach ($rows as $row) {
                $type = 'expiry_' . $day;
                $claim = self::claim($gymId, (int) $row['member_id'], $type, (string) $row['id'], $today, (int) $row['whatsapp_opt_in'] !== 1 || $row['phone'] === '');
                if ($claim === 'skip') {
                    $skipped++;
                    continue;
                }
                if ($claim === 'skipped') {
                    $skipped++;
                    continue;
                }
                $daysLabel = $day === 0 ? 'today' : 'in ' . $day . ' days';
                $result = WhatsAppService::send($gymId, (int) $row['member_id'], (string) $row['phone'], 'expiry_reminder', [
                    $row['full_name'], $row['plan_name'], fmt_date($row['end_date']), $daysLabel,
                ]);
                self::completeClaim($gymId, (int) $row['member_id'], $type, (string) $row['id'], $today, $result);
                if ($day <= 7) {
                    NotificationService::fanout(null, $gymId, ['owner', 'manager', 'receptionist'], 'membership.expiring', 'Membership ending ' . $daysLabel, $row['full_name'] . ' · ' . $row['plan_name'], 'members/' . $row['member_id']);
                }
                $sent++;
            }
        }
        return ['sent' => $sent, 'skipped' => $skipped, 'gym' => $gym['name'] ?? ''];
    }

    public static function paymentReminders(int $gymId, string $today): array
    {
        if ((string) setting($gymId, 'payment_reminder_enabled', '1') !== '1') {
            return ['sent' => 0, 'skipped' => 0];
        }
        $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
        $offsets = parse_int_list((string) setting($gymId, 'payment_overdue_days', '1,3,7'));
        $repeat = (int) setting($gymId, 'payment_repeat_days', '7');
        $rows = db_all("SELECT i.*, m.full_name, m.phone, m.whatsapp_opt_in, m.status AS member_status
            FROM invoices i JOIN members m ON m.id = i.member_id
            WHERE i.gym_id = ? AND i.status IN ('unpaid','partial') AND m.status NOT IN ('archived','suspended')", [$gymId]);
        $sent = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $age = days_inclusive($row['invoice_date'], $today) - 1;
            if (!payment_reminder_due($age, $offsets, $repeat)) {
                continue;
            }
            $type = 'payment_' . $age;
            $noConsent = (int) $row['whatsapp_opt_in'] !== 1 || $row['phone'] === '';
            $claim = self::claim($gymId, (int) $row['member_id'], $type, (string) $row['id'], $today, $noConsent);
            if ($claim !== 'send') {
                $skipped++;
                continue;
            }
            $result = WhatsAppService::send($gymId, (int) $row['member_id'], (string) $row['phone'], 'payment_reminder', [
                $row['full_name'],
                $row['invoice_number'],
                money($row['balance_due'], $gym),
                status_label($row['status']),
            ]);
            self::completeClaim($gymId, (int) $row['member_id'], $type, (string) $row['id'], $today, $result);
            NotificationService::fanout(null, $gymId, ['owner', 'manager', 'receptionist'], 'payment.overdue', 'Payment reminder', $row['full_name'] . ' · ' . $row['invoice_number'], 'invoices/' . $row['id']);
            $sent++;
        }
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private static function claim(int $gymId, int $memberId, string $type, string $reference, string $today, bool $skipConsent): string
    {
        return db_tx(function (PDO $pdo) use ($gymId, $memberId, $type, $reference, $today, $skipConsent) {
            $stmt = $pdo->prepare('SELECT * FROM reminder_logs WHERE gym_id = ? AND member_id = ? AND reminder_type = ? AND reference_key = ? AND remind_on = ? FOR UPDATE');
            $stmt->execute([$gymId, $memberId, $type, $reference, $today]);
            $row = $stmt->fetch();
            $status = $skipConsent ? 'skipped' : 'queued';
            if (!$row) {
                try {
                    $pdo->prepare('INSERT INTO reminder_logs (gym_id, member_id, reminder_type, reference_key, remind_on, status, detail, created_at) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$gymId, $memberId, $type, $reference, $today, $status, $skipConsent ? 'No WhatsApp consent' : 'Claimed', utc_now()]);
                } catch (PDOException $e) {
                    if ((string) $e->getCode() === '23000') {
                        return 'skip';
                    }
                    throw $e;
                }
                return $skipConsent ? 'skipped' : 'send';
            }
            if (in_array($row['status'], ['sent', 'demo', 'skipped'], true)) {
                return 'skip';
            }
            if ($row['status'] === 'queued') {
                $age = time() - (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->getTimestamp();
                if ($age < 600) {
                    return 'skip';
                }
            }
            $pdo->prepare('UPDATE reminder_logs SET status = ?, detail = ?, created_at = ? WHERE id = ?')
                ->execute([$status, $skipConsent ? 'No WhatsApp consent' : 'Retry', utc_now(), $row['id']]);
            return $skipConsent ? 'skipped' : 'send';
        });
    }

    private static function completeClaim(int $gymId, int $memberId, string $type, string $reference, string $today, array $result): void
    {
        $status = match ($result['status']) {
            'sent' => 'sent',
            'demo' => 'demo',
            default => 'failed',
        };
        db_exec('UPDATE reminder_logs SET status = ?, message_log_id = ?, detail = ? WHERE gym_id = ? AND member_id = ? AND reminder_type = ? AND reference_key = ? AND remind_on = ?', [
            $status,
            $result['log_id'] ?? null,
            $result['error'] ?? $result['status'],
            $gymId,
            $memberId,
            $type,
            $reference,
            $today,
        ]);
    }
}
