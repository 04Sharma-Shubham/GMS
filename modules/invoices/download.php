<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('invoices.view');
$id = (int) ($_GET['id'] ?? 0);
$invoice = db_one('SELECT * FROM invoices WHERE id = ? AND gym_id = ?', [$id, $gym['id']]);
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found');
}
try {
    $path = InvoiceService::ensurePdf((int) $gym['id'], $id);
} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect_to('invoices/' . $id . '/print');
}
$safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice['invoice_number']) ?: 'invoice';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $safe . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
