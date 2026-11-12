<?php

declare(strict_types=1);

class MembershipService
{
    public static function quote(array $gym, string $price, string $discount): array
    {
        $price = money_norm($price);
        $discount = money_norm($discount === '' ? '0' : $discount);
        if (money_comp($discount, '0') < 0) {
            throw new AppException('Discount cannot be negative.');
        }
        if (money_comp($discount, $price) > 0) {
            throw new AppException('Discount cannot be higher than the plan price.');
        }
        $taxable = money_sub($price, $discount);
        $taxPercent = !empty($gym['tax_enabled']) ? money_norm($gym['tax_percent']) : '0.00';
        $tax = money_percent($taxable, $taxPercent);
        return [
            'price' => $price,
            'discount' => $discount,
            'taxable' => $taxable,
            'tax_percent' => $taxPercent,
            'tax_amount' => $tax,
            'total' => money_add($taxable, $tax),
        ];
    }

    public static function assign(int $gymId, int $userId, array $input): array
    {
        $send = !empty($input['send_whatsapp']);
        $created = db_tx(function (PDO $pdo) use ($gymId, $userId, $input) {
            return self::createWithin($pdo, $gymId, $userId, $input);
        });
        return self::finish($gymId, $userId, $created, $send, (string) ($input['message_key'] ?? 'welcome'));
    }

    public static function freeze(int $gymId, int $userId, int $subscriptionId, string $today): void
    {
        db_tx(function (PDO $pdo) use ($gymId, $userId, $subscriptionId, $today) {
            $sub = self::lockSub($pdo, $gymId, $subscriptionId);
            if ($sub['status'] !== 'active') {
                throw new AppException('Only an active membership can be frozen.');
            }
            if ($sub['end_date'] < $today) {
                throw new AppException('This membership has already ended.');
            }
            $pdo->prepare('UPDATE member_subscriptions SET status = \'frozen\', freeze_start = ?, updated_at = ? WHERE id = ?')
                ->execute([$today, utc_now(), $subscriptionId]);
            self::syncMember($pdo, $gymId, (int) $sub['member_id'], $today);
            AuditService::log($gymId, $userId, 'membership.frozen', 'subscription', $subscriptionId, ['status' => 'active'], [
                'summary' => 'Froze membership from ' . $today,
                'freeze_start' => $today,
            ], $pdo);
        });
    }

    public static function unfreeze(int $gymId, int $userId, int $subscriptionId, string $today): string
    {
        return db_tx(function (PDO $pdo) use ($gymId, $userId, $subscriptionId, $today) {
            $sub = self::lockSub($pdo, $gymId, $subscriptionId);
            if ($sub['status'] !== 'frozen' || empty($sub['freeze_start'])) {
                throw new AppException('This membership is not frozen.');
            }
            $result = self::applyUnfreeze($pdo, $gymId, $sub, $today);
            AuditService::log($gymId, $userId, 'membership.unfrozen', 'subscription', $subscriptionId, ['status' => 'frozen'], [
                'summary' => $result,
                'end_date' => $result,
            ], $pdo);
            return $result;
        });
    }

    public static function processExpiry(int $gymId, string $today): array
    {
        return db_tx(function (PDO $pdo) use ($gymId, $today) {
            $max = (int) (setting($gymId, 'freeze_max_days', '60') ?? '60');
            $frozen = $pdo->prepare("SELECT * FROM member_subscriptions WHERE gym_id = ? AND status = 'frozen'");
            $frozen->execute([$gymId]);
            $unfrozen = 0;
            foreach ($frozen->fetchAll() as $sub) {
                if (empty($sub['freeze_start'])) {
                    continue;
                }
                $elapsed = days_inclusive($sub['freeze_start'], $today) - 1;
                $allowed = max(0, $max - (int) $sub['frozen_days']);
                if ($elapsed > $allowed) {
                    self::applyUnfreeze($pdo, $gymId, $sub, $today);
                    $unfrozen++;
                }
            }
            $due = $pdo->prepare("SELECT id, member_id, end_date FROM member_subscriptions WHERE gym_id = ? AND status = 'active' AND end_date < ?");
            $due->execute([$gymId, $today]);
            $rows = $due->fetchAll();
            $expired = 0;
            if ($rows) {
                $pdo->prepare("UPDATE member_subscriptions SET status = 'expired', updated_at = ? WHERE gym_id = ? AND status = 'active' AND end_date < ?")
                    ->execute([utc_now(), $gymId, $today]);
                foreach ($rows as $row) {
                    self::syncMember($pdo, $gymId, (int) $row['member_id'], $today);
                    $exists = $pdo->prepare("SELECT id FROM reminder_logs WHERE gym_id = ? AND member_id = ? AND reminder_type = 'status_expired' AND reference_key = ? AND remind_on = ?");
                    $exists->execute([$gymId, $row['member_id'], (string) $row['id'], $row['end_date']]);
                    if (!$exists->fetch()) {
                        $member = $pdo->prepare('SELECT full_name FROM members WHERE id = ?');
                        $member->execute([$row['member_id']]);
                        $name = (string) ($member->fetch()['full_name'] ?? 'Member');
                        NotificationService::fanout($pdo, $gymId, ['owner', 'manager', 'receptionist'], 'membership.expired', 'Membership expired', $name . ' expired on ' . $row['end_date'], 'members/' . $row['member_id']);
                        $pdo->prepare('INSERT INTO reminder_logs (gym_id, member_id, reminder_type, reference_key, remind_on, status, detail, created_at) VALUES (?,?,?,?,?,?,?,?)')
                            ->execute([$gymId, $row['member_id'], 'status_expired', (string) $row['id'], $row['end_date'], 'sent', 'In-app expiry notice', utc_now()]);
                    }
                    $expired++;
                }
            }
            return ['expired' => $expired, 'unfrozen' => $unfrozen];
        });
    }

    public static function syncMember(PDO $pdo, int $gymId, int $memberId, string $today): void
    {
        $stmt = $pdo->prepare('SELECT status FROM members WHERE id = ? AND gym_id = ? FOR UPDATE');
        $stmt->execute([$memberId, $gymId]);
        $member = $stmt->fetch();
        if (!$member || in_array($member['status'], ['suspended', 'archived'], true)) {
            return;
        }
        $active = $pdo->prepare("SELECT id FROM member_subscriptions WHERE gym_id = ? AND member_id = ? AND (status = 'frozen' OR (status = 'active' AND end_date >= ?)) LIMIT 1");
        $active->execute([$gymId, $memberId, $today]);
        $any = $pdo->prepare('SELECT id FROM member_subscriptions WHERE gym_id = ? AND member_id = ? LIMIT 1');
        $any->execute([$gymId, $memberId]);
        $status = $active->fetch() ? 'active' : ($any->fetch() ? 'expired' : 'active');
        if ($status !== $member['status']) {
            $pdo->prepare('UPDATE members SET status = ?, updated_at = ? WHERE id = ? AND gym_id = ?')->execute([$status, utc_now(), $memberId, $gymId]);
        }
    }

    public static function accessState(int $gymId, int $memberId, string $today): array
    {
        $active = db_one("SELECT s.*, p.name AS plan_name FROM member_subscriptions s JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND s.member_id = ? AND s.status = 'active' AND s.start_date <= ? AND s.end_date >= ? ORDER BY s.end_date DESC LIMIT 1", [$gymId, $memberId, $today, $today]);
        if ($active) {
            return ['state' => 'active', 'subscription' => $active];
        }
        $frozen = db_one("SELECT s.*, p.name AS plan_name FROM member_subscriptions s JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND s.member_id = ? AND s.status = 'frozen' ORDER BY s.id DESC LIMIT 1", [$gymId, $memberId]);
        if ($frozen) {
            return ['state' => 'frozen', 'subscription' => $frozen];
        }
        return ['state' => 'expired', 'subscription' => null];
    }

    private static function createWithin(PDO $pdo, int $gymId, int $userId, array $input): array
    {
        $memberId = (int) ($input['member_id'] ?? 0);
        $planId = (int) ($input['plan_id'] ?? 0);
        $start = (string) ($input['start_date'] ?? '');
        if (!valid_date($start)) {
            throw new AppException('Choose a valid start date.');
        }
        $gym = self::lockGym($pdo, $gymId);
        $member = self::fetch($pdo, 'SELECT * FROM members WHERE id = ? AND gym_id = ? FOR UPDATE', [$memberId, $gymId]);
        if (in_array($member['status'], ['archived', 'suspended'], true)) {
            throw new AppException('Reactivate this member before assigning a plan.');
        }
        $plan = self::fetch($pdo, 'SELECT * FROM membership_plans WHERE id = ? AND gym_id = ? AND status = \'active\'', [$planId, $gymId]);
        $previous = null;
        $previousId = (int) ($input['previous_subscription_id'] ?? 0);
        if ($previousId > 0) {
            $previous = self::lockSub($pdo, $gymId, $previousId);
            if ((int) $previous['member_id'] !== $memberId) {
                throw new AppException('That membership belongs to a different member.');
            }
        }
        $change = (string) ($input['change_type'] ?? 'new');
        if (!in_array($change, ['new', 'renewal', 'upgrade', 'downgrade'], true)) {
            $change = 'new';
        }
        $discount = (string) ($input['discount'] ?? '0');
        $creditNote = null;
        if ($previous && !empty($input['prorate']) && in_array($change, ['upgrade', 'downgrade'], true) && $previous['status'] === 'active') {
            $totalDays = max(1, days_inclusive($previous['start_date'], $previous['end_date']));
            $remaining = $previous['end_date'] >= $start ? days_inclusive($start, $previous['end_date']) : 0;
            $credit = money_min(money_ratio((string) $previous['price'], (string) $remaining, (string) $totalDays), (string) $plan['price']);
            $discount = money_min((string) $plan['price'], money_add(money_norm($discount === '' ? '0' : $discount), $credit));
            $creditNote = 'Unused value credit ' . $credit;
        }
        $quote = self::quote($gym, (string) $plan['price'], $discount);
        $end = add_days($start, (int) $plan['duration_days'] - 1);
        if ($previous && in_array($change, ['upgrade', 'downgrade'], true)) {
            $close = add_days($start, -1);
            if ($close < $previous['start_date']) {
                $close = $previous['start_date'];
            }
            $pdo->prepare('UPDATE member_subscriptions SET status = ?, end_date = ?, updated_at = ? WHERE id = ?')
                ->execute([$change === 'upgrade' ? 'upgraded' : 'downgraded', $close, utc_now(), $previousId]);
        } elseif ($previous && $change === 'renewal' && $previous['status'] === 'active' && $start <= $previous['end_date']) {
            $close = add_days($start, -1);
            if ($close < $previous['start_date']) {
                $close = $start;
            }
            $newStatus = $close < $gym_today($gym) ? 'expired' : 'active';
            if ($close < $previous['start_date']) {
                $newStatus = 'expired';
            }
            $pdo->prepare('UPDATE member_subscriptions SET end_date = ?, status = ?, updated_at = ? WHERE id = ?')
                ->execute([$close, $newStatus, utc_now(), $previousId]);
        }
        $now = utc_now();
        $notes = clip((string) ($input['notes'] ?? ''), 500);
        if ($creditNote) {
            $notes = trim($creditNote . '. ' . $notes);
        }
        $pdo->prepare('INSERT INTO member_subscriptions (gym_id, member_id, plan_id, start_date, end_date, status, price, discount, tax_percent, tax_amount, total_amount, frozen_days, change_type, previous_subscription_id, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?)')
            ->execute([
                $gymId, $memberId, $planId, $start, $end, 'active', $quote['price'], $quote['discount'], $quote['tax_percent'], $quote['tax_amount'], $quote['total'],
                $change, $previousId ?: null, $notes ?: null, $userId, $now, $now,
            ]);
        $subscriptionId = (int) $pdo->lastInsertId();
        $seq = (int) $gym['invoice_next_number'];
        $number = sprintf('%s-%s-%04d', $gym['invoice_prefix'] ?: 'INV', substr($start, 0, 4), $seq);
        $pdo->prepare('UPDATE gyms SET invoice_next_number = invoice_next_number + 1, updated_at = ? WHERE id = ?')->execute([$now, $gymId]);
        $pdo->prepare('INSERT INTO invoices (gym_id, invoice_number, member_id, subscription_id, invoice_date, subtotal, discount, tax_percent, tax_amount, total_amount, amount_paid, balance_due, status, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,\'unpaid\',?,?,?,?)')
            ->execute([
                $gymId, $number, $memberId, $subscriptionId, $start, $quote['price'], $quote['discount'], $quote['tax_percent'], $quote['tax_amount'], $quote['total'], $quote['total'],
                $notes ?: null, $userId, $now, $now,
            ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $description = $plan['name'] . ' (' . $start . ' to ' . $end . ')';
        $pdo->prepare('INSERT INTO invoice_items (gym_id, invoice_id, description, quantity, unit_price, amount, created_at) VALUES (?,?,?,1,?,?,?)')
            ->execute([$gymId, $invoiceId, $description, $quote['price'], $quote['price'], $now]);
        if (money_comp($quote['total'], '0') === 0) {
            PaymentService::recalculate($pdo, $gymId, $invoiceId);
        }
        $paidAmount = trim((string) ($input['payment_amount'] ?? ''));
        $payment = null;
        if ($paidAmount !== '' && money_comp(money_norm($paidAmount), '0') > 0) {
            $payment = PaymentService::addWithin(
                $pdo,
                $gymId,
                $userId,
                $invoiceId,
                $paidAmount,
                (string) ($input['payment_method'] ?? 'cash'),
                (string) ($input['payment_date'] ?? $start),
                $input['payment_reference'] ?? null,
                $input['payment_notes'] ?? null
            );
        }
        self::syncMember($pdo, $gymId, $memberId, gym_today($gym));
        AuditService::log($gymId, $userId, 'membership.' . $change, 'subscription', $subscriptionId, null, [
            'summary' => ucfirst($change) . ' ' . $plan['name'] . ' for ' . $member['full_name'],
            'invoice_number' => $number,
            'total' => $quote['total'],
        ], $pdo);
        NotificationService::fanout($pdo, $gymId, ['owner', 'manager'], 'membership.' . $change, 'Membership ' . $change, $member['full_name'] . ' · ' . $plan['name'], 'members/' . $memberId);
        $invoice = self::fetch($pdo, 'SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        return [
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'member_id' => $memberId,
            'invoice_number' => $number,
            'amount' => $payment['amount'] ?? '0.00',
            'status' => $invoice['status'],
            'balance' => $invoice['balance_due'],
            'total' => $invoice['total_amount'],
            'member_name' => $member['full_name'],
            'plan_name' => $plan['name'],
            'end_date' => $end,
            'phone' => $member['phone'],
            'opt_in' => (int) $member['whatsapp_opt_in'],
            'change' => $change,
        ];
    }

    private static function finish(int $gymId, int $userId, array $created, bool $send, string $messageKey): array
    {
        $follow = PaymentService::afterCommit($gymId, $userId, $created, false);
        $created = array_merge($created, $follow);
        if ($send && (int) $created['opt_in'] === 1 && $created['phone']) {
            $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
            if ($created['change'] === 'renewal') {
                $created['whatsapp'] = WhatsAppService::send($gymId, (int) $created['member_id'], (string) $created['phone'], 'renewal_confirmation', [
                    $created['member_name'], $created['plan_name'], fmt_date($created['end_date']),
                ]);
            } elseif (money_comp((string) $created['amount'], '0') > 0) {
                $created['whatsapp'] = WhatsAppService::send($gymId, (int) $created['member_id'], (string) $created['phone'], 'payment_confirmation', [
                    $created['member_name'], money($created['amount'], $gym), $created['invoice_number'], money($created['balance'], $gym),
                ]);
            } else {
                $created['whatsapp'] = WhatsAppService::send($gymId, (int) $created['member_id'], (string) $created['phone'], $messageKey === 'renewal' ? 'renewal_confirmation' : 'welcome', [
                    $created['member_name'], $gym['name'],
                ]);
            }
        }
        return $created;
    }

    private static function applyUnfreeze(PDO $pdo, int $gymId, array $sub, string $today): string
    {
        $elapsed = max(0, days_inclusive((string) $sub['freeze_start'], $today) - 1);
        $max = (int) (setting($gymId, 'freeze_max_days', '60') ?? '60');
        $allowed = max(0, $max - (int) $sub['frozen_days']);
        $applied = min($elapsed, $allowed);
        $end = add_days($sub['end_date'], $applied);
        $status = $end < $today ? 'expired' : 'active';
        $pdo->prepare('UPDATE member_subscriptions SET status = ?, end_date = ?, freeze_start = NULL, frozen_days = frozen_days + ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $end, $applied, utc_now(), $sub['id']]);
        self::syncMember($pdo, $gymId, (int) $sub['member_id'], $today);
        $note = 'Membership extended by ' . $applied . ' frozen day' . ($applied === 1 ? '' : 's') . '.';
        if ($applied < $elapsed) {
            $note .= ' The freeze cap shortened the extension.';
        }
        return $note;
    }

    private static function lockGym(PDO $pdo, int $gymId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM gyms WHERE id = ? FOR UPDATE');
        $stmt->execute([$gymId]);
        $gym = $stmt->fetch();
        if (!$gym) {
            throw new AppException('Gym not found.');
        }
        return $gym;
    }

    private static function lockSub(PDO $pdo, int $gymId, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM member_subscriptions WHERE id = ? AND gym_id = ? FOR UPDATE');
        $stmt->execute([$id, $gymId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('Membership not found.');
        }
        return $row;
    }

    private static function fetch(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('The selected record was not found in this gym.');
        }
        return $row;
    }
}
