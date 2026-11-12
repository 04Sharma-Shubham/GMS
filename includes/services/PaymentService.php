<?php

declare(strict_types=1);

class PaymentService
{
    public const METHODS = ['cash', 'upi', 'bank_transfer', 'card', 'other'];

    public static function record(int $gymId, int $userId, int $invoiceId, string $amount, string $method, string $paymentDate, ?string $reference, ?string $notes, bool $sendWhatsapp): array
    {
        $created = db_tx(function (PDO $pdo) use ($gymId, $userId, $invoiceId, $amount, $method, $paymentDate, $reference, $notes) {
            return self::addWithin($pdo, $gymId, $userId, $invoiceId, $amount, $method, $paymentDate, $reference, $notes);
        });
        return self::afterCommit($gymId, $userId, $created, $sendWhatsapp);
    }

    public static function addWithin(PDO $pdo, int $gymId, int $userId, int $invoiceId, string $amount, string $method, string $paymentDate, ?string $reference, ?string $notes): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new AppException('Choose a payment method.');
        }
        if (!valid_date($paymentDate)) {
            throw new AppException('Enter a valid payment date.');
        }
        $amount = money_norm($amount);
        if (money_comp($amount, '0') <= 0) {
            throw new AppException('Enter an amount greater than zero.');
        }
        $invoice = self::lockInvoice($pdo, $gymId, $invoiceId);
        if (money_comp($invoice['balance_due'], '0') <= 0) {
            throw new AppException('This invoice is already settled.');
        }
        if (money_comp($amount, (string) $invoice['balance_due']) > 0) {
            throw new AppException('That amount is higher than the balance due of ' . $invoice['balance_due'] . '.');
        }
        $now = utc_now();
        $pdo->prepare('INSERT INTO payments (gym_id, invoice_id, member_id, subscription_id, amount, payment_date, method, reference_no, notes, recorded_by, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,\'recorded\',?,?)')
            ->execute([
                $gymId,
                $invoiceId,
                (int) $invoice['member_id'],
                (int) $invoice['subscription_id'],
                $amount,
                $paymentDate,
                $method,
                clip($reference, 120) ?: null,
                clip($notes, 500) ?: null,
                $userId,
                $now,
                $now,
            ]);
        $paymentId = (int) $pdo->lastInsertId();
        $updated = self::recalculate($pdo, $gymId, $invoiceId);
        AuditService::log($gymId, $userId, 'payment.recorded', 'payment', $paymentId, null, [
            'summary' => 'Recorded ' . $amount . ' against ' . $invoice['invoice_number'],
            'amount' => $amount,
            'method' => $method,
            'invoice_id' => $invoiceId,
            'status' => $updated['status'],
        ], $pdo);
        $member = self::fetch($pdo, 'SELECT full_name FROM members WHERE id = ? AND gym_id = ?', [(int) $invoice['member_id'], $gymId]);
        NotificationService::fanout($pdo, $gymId, ['owner', 'manager', 'receptionist'], 'payment.received', 'Payment recorded', $member['full_name'] . ' · ' . $invoice['invoice_number'] . ' · ' . $amount, 'invoices/' . $invoiceId);
        return [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'member_id' => (int) $invoice['member_id'],
            'invoice_number' => $invoice['invoice_number'],
            'amount' => $amount,
            'status' => $updated['status'],
            'balance' => $updated['balance_due'],
            'total' => $updated['total_amount'],
            'member_name' => $member['full_name'],
        ];
    }

    public static function void(int $gymId, int $userId, int $paymentId, string $reason): array
    {
        $reason = clip($reason, 255);
        if ($reason === '') {
            throw new AppException('A reason is required before a payment can be voided.');
        }
        $result = db_tx(function (PDO $pdo) use ($gymId, $userId, $paymentId, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND gym_id = ? FOR UPDATE');
            $stmt->execute([$paymentId, $gymId]);
            $payment = $stmt->fetch();
            if (!$payment) {
                throw new AppException('Payment not found.');
            }
            if ($payment['status'] !== 'recorded') {
                throw new AppException('This payment is already voided.');
            }
            $now = utc_now();
            $pdo->prepare('UPDATE payments SET status = \'voided\', void_reason = ?, voided_by = ?, voided_at = ?, updated_at = ? WHERE id = ? AND gym_id = ?')
                ->execute([$reason, $userId, $now, $now, $paymentId, $gymId]);
            $invoice = self::recalculate($pdo, $gymId, (int) $payment['invoice_id']);
            AuditService::log($gymId, $userId, 'payment.voided', 'payment', $paymentId, [
                'amount' => $payment['amount'],
                'status' => 'recorded',
            ], [
                'summary' => 'Voided payment ' . $payment['amount'] . ' — ' . $reason,
                'status' => 'voided',
                'invoice_status' => $invoice['status'],
            ], $pdo);
            return [
                'invoice_id' => (int) $payment['invoice_id'],
                'member_id' => (int) $payment['member_id'],
                'invoice_number' => $invoice['invoice_number'],
                'amount' => $payment['amount'],
                'status' => $invoice['status'],
                'balance' => $invoice['balance_due'],
                'total' => $invoice['total_amount'],
                'member_name' => '',
            ];
        });
        return self::afterCommit($gymId, $userId, $result, false);
    }

    public static function updateMeta(int $gymId, int $userId, int $paymentId, string $method, string $paymentDate, ?string $reference, ?string $notes): void
    {
        if (!in_array($method, self::METHODS, true) || !valid_date($paymentDate)) {
            throw new AppException('Check the payment method and date.');
        }
        db_tx(function (PDO $pdo) use ($gymId, $userId, $paymentId, $method, $paymentDate, $reference, $notes) {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND gym_id = ? FOR UPDATE');
            $stmt->execute([$paymentId, $gymId]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['status'] !== 'recorded') {
                throw new AppException('Only a recorded payment can be corrected.');
            }
            $pdo->prepare('UPDATE payments SET method = ?, payment_date = ?, reference_no = ?, notes = ?, updated_at = ? WHERE id = ?')
                ->execute([$method, $paymentDate, clip($reference, 120) ?: null, clip($notes, 500) ?: null, utc_now(), $paymentId]);
            AuditService::log($gymId, $userId, 'payment.corrected', 'payment', $paymentId, [
                'method' => $payment['method'],
                'payment_date' => $payment['payment_date'],
                'reference_no' => $payment['reference_no'],
                'notes' => $payment['notes'],
            ], [
                'summary' => 'Corrected payment details',
                'method' => $method,
                'payment_date' => $paymentDate,
                'reference_no' => $reference,
                'notes' => $notes,
            ], $pdo);
        });
    }

    public static function recalculate(PDO $pdo, int $gymId, int $invoiceId): array
    {
        $invoice = self::lockInvoice($pdo, $gymId, $invoiceId);
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = ? AND gym_id = ? AND status = 'recorded'");
        $stmt->execute([$invoiceId, $gymId]);
        $paid = (string) ($stmt->fetch()['paid'] ?? '0');
        $calc = invoice_status_from_amounts((string) $invoice['total_amount'], $paid);
        $pdo->prepare('UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ?, updated_at = ? WHERE id = ? AND gym_id = ?')
            ->execute([$calc['paid'], $calc['balance'], $calc['status'], utc_now(), $invoiceId, $gymId]);
        $invoice['amount_paid'] = $calc['paid'];
        $invoice['balance_due'] = $calc['balance'];
        $invoice['status'] = $calc['status'];
        return $invoice;
    }

    public static function afterCommit(int $gymId, int $userId, array $created, bool $sendWhatsapp): array
    {
        $created['pdf'] = null;
        $created['pdf_error'] = null;
        $created['whatsapp'] = null;
        try {
            $created['pdf'] = InvoiceService::generatePdf($gymId, (int) $created['invoice_id']);
        } catch (Throwable $e) {
            $created['pdf_error'] = $e->getMessage();
            app_log('error', 'Invoice PDF failed', ['invoice' => $created['invoice_id'], 'error' => $e->getMessage()]);
        }
        if ($sendWhatsapp) {
            $member = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [(int) $created['member_id'], $gymId]);
            if ($member && (int) $member['whatsapp_opt_in'] === 1 && $member['phone']) {
                $gym = db_one('SELECT * FROM gyms WHERE id = ?', [$gymId]);
                $document = null;
                $base = (string) env('APP_URL', '');
                if ($created['pdf'] && str_starts_with($base, 'https://')) {
                    $document = [
                        'link' => InvoiceService::publicUrl($gymId, (int) $created['invoice_id']),
                        'filename' => $created['invoice_number'] . '.pdf',
                    ];
                }
                $created['whatsapp'] = WhatsAppService::send(
                    $gymId,
                    (int) $member['id'],
                    (string) $member['phone'],
                    $document ? 'invoice_document' : 'payment_confirmation',
                    $document
                        ? [$member['full_name'], $created['invoice_number'], money($created['total'], $gym)]
                        : [$member['full_name'], money($created['amount'], $gym), $created['invoice_number'], money($created['balance'], $gym)],
                    $document
                );
            }
        }
        return $created;
    }

    private static function lockInvoice(PDO $pdo, int $gymId, int $invoiceId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND gym_id = ? FOR UPDATE');
        $stmt->execute([$invoiceId, $gymId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new AppException('Invoice not found.');
        }
        return $invoice;
    }

    private static function fetch(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('Related record not found.');
        }
        return $row;
    }
}
