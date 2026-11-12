<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('invoices.view');
$id = (int) ($_GET['id'] ?? 0);
$invoice = db_one('SELECT invoice_number FROM invoices WHERE id = ? AND gym_id = ?', [$id, $gym['id']]);
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found');
}
require dirname(__DIR__, 2) . '/includes/views/invoice_document.php';
$html = invoice_document(InvoiceService::bundle((int) $gym['id'], $id), false);
?><!DOCTYPE html><html><head><meta charset="utf-8"><title><?= e($invoice['invoice_number']) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>"></head>
<body style="background:#e9ece4">
<div class="no-print" style="padding:16px;display:flex;gap:8px"><button class="btn btn-primary" onclick="print()">Print or save PDF</button><a class="btn btn-ghost" href="<?= e(url('invoices/' . $id)) ?>">Back</a></div>
<div class="invoice-sheet" style="width:min(900px,100%);margin:0 auto"><?= $html ?></div>
</body></html>
