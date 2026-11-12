<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$token = verify_token((string) ($_GET['t'] ?? ''));
if (!$token) {
    http_response_code(403);
    exit('This invoice link has expired.');
}
$gymId = (int) ($token['g'] ?? 0);
$invoiceId = (int) ($token['i'] ?? 0);
try {
    $path = InvoiceService::ensurePdf($gymId, $invoiceId);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Invoice unavailable.');
}
$root = realpath(dirname(__DIR__) . '/storage/invoices');
$full = realpath($path);
if (!$root || !$full || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Invoice unavailable.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="invoice.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($full);
