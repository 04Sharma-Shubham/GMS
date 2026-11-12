<?php

declare(strict_types=1);

class InvoiceService
{
    public static function bundle(int $gymId, int $invoiceId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: db();
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND gym_id = ?');
        $stmt->execute([$invoiceId, $gymId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new AppException('Invoice not found.');
        }
        $member = self::fetch($pdo, 'SELECT * FROM members WHERE id = ? AND gym_id = ?', [(int) $invoice['member_id'], $gymId]);
        $subscription = self::fetch($pdo, 'SELECT * FROM member_subscriptions WHERE id = ? AND gym_id = ?', [(int) $invoice['subscription_id'], $gymId]);
        $plan = self::fetch($pdo, 'SELECT * FROM membership_plans WHERE id = ? AND gym_id = ?', [(int) $subscription['plan_id'], $gymId]);
        $gym = self::fetch($pdo, 'SELECT * FROM gyms WHERE id = ?', [$gymId]);
        $items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? AND gym_id = ? ORDER BY id');
        $items->execute([$invoiceId, $gymId]);
        $payments = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? AND gym_id = ? AND status = 'recorded' ORDER BY payment_date, id");
        $payments->execute([$invoiceId, $gymId]);
        return [
            'gym' => $gym,
            'invoice' => $invoice,
            'member' => $member,
            'subscription' => $subscription,
            'plan' => $plan,
            'items' => $items->fetchAll(),
            'payments' => $payments->fetchAll(),
            'logo' => self::logoData($gym['logo_path'] ?? null),
        ];
    }

    public static function html(int $gymId, int $invoiceId, bool $standalone = true): string
    {
        require_once dirname(__DIR__) . '/views/invoice_document.php';
        return invoice_document(self::bundle($gymId, $invoiceId), $standalone);
    }

    public static function generatePdf(int $gymId, int $invoiceId): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new AppException('PDF library is not installed. Run composer install, or use Print and choose Save as PDF.');
        }
        $html = self::html($gymId, $invoiceId, true);
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot(dirname(__DIR__, 2));
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();
        $invoice = db_one('SELECT invoice_number FROM invoices WHERE id = ? AND gym_id = ?', [$invoiceId, $gymId]);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice['invoice_number']) ?: 'invoice';
        $dir = dirname(__DIR__, 2) . '/storage/invoices/' . $gymId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AppException('The invoice folder is not writable.');
        }
        $relative = 'storage/invoices/' . $gymId . '/' . $safe . '.pdf';
        $full = dirname(__DIR__, 2) . '/' . $relative;
        if (file_put_contents($full, $dompdf->output()) === false) {
            throw new AppException('The invoice PDF could not be saved.');
        }
        @chmod($full, 0640);
        db_exec('UPDATE invoices SET pdf_path = ?, updated_at = ? WHERE id = ? AND gym_id = ?', [$relative, utc_now(), $invoiceId, $gymId]);
        return $full;
    }

    public static function ensurePdf(int $gymId, int $invoiceId): string
    {
        $invoice = db_one('SELECT pdf_path FROM invoices WHERE id = ? AND gym_id = ?', [$invoiceId, $gymId]);
        if (!$invoice) {
            throw new AppException('Invoice not found.');
        }
        $full = $invoice['pdf_path'] ? dirname(__DIR__, 2) . '/' . $invoice['pdf_path'] : '';
        if ($full && is_file($full)) {
            return $full;
        }
        return self::generatePdf($gymId, $invoiceId);
    }

    public static function publicUrl(int $gymId, int $invoiceId): string
    {
        $config = require dirname(__DIR__, 2) . '/config/whatsapp.php';
        $token = sign_token(['g' => $gymId, 'i' => $invoiceId], (int) $config['document_ttl']);
        $base = rtrim((string) env('APP_URL', ''), '/');
        $path = url('media/invoice?t=' . rawurlencode($token));
        if ($base === '') {
            return $path;
        }
        return $base . $path;
    }

    private static function fetch(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('Invoice data is incomplete.');
        }
        return $row;
    }

    private static function logoData(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
        if (!is_file($full)) {
            return null;
        }
        $mime = mime_content_type($full) ?: 'image/png';
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($full));
    }
}
