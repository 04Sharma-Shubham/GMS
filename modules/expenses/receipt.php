<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('expenses.manage');
$row = db_one('SELECT * FROM expenses WHERE id = ? AND gym_id = ?', [(int) ($_GET['id'] ?? 0), $gym['id']]);
if (!$row || !$row['receipt_path']) {
    http_response_code(404);
    exit('Receipt not found');
}
$root = realpath(dirname(__DIR__, 2) . '/storage/receipts');
$full = realpath(dirname(__DIR__, 2) . '/' . $row['receipt_path']);
if (!$root || !$full || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Receipt not found');
}
$mime = mime_content_type($full) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="receipt-' . (int) $row['id'] . '"');
readfile($full);
exit;
