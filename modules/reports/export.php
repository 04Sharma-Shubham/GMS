<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('reports.export');
$gymId = (int) $gym['id'];
$type = (string) ($_GET['type'] ?? 'members');
$range = resolve_range($gym);
$filename = 'gms-' . $type . '-' . $range['from'] . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
if ($out === false) {
    exit;
}
if ($type === 'payments') {
    fputcsv($out, ['Date', 'Member', 'Invoice', 'Amount', 'Method', 'Reference', 'Status']);
    $rows = db_all("SELECT p.*, m.full_name, i.invoice_number FROM payments p JOIN members m ON m.id = p.member_id JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date", [$gymId, $range['from'], $range['to']]);
    foreach ($rows as $row) fputcsv($out, [csv_safe($row['payment_date']), csv_safe($row['full_name']), csv_safe($row['invoice_number']), $row['amount'], $row['method'], csv_safe((string) $row['reference_no']), $row['status']]);
} elseif ($type === 'expenses') {
    fputcsv($out, ['Date', 'Category', 'Description', 'Amount', 'Method']);
    $rows = db_all('SELECT e.*, c.name FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.gym_id = ? AND e.expense_date BETWEEN ? AND ?', [$gymId, $range['from'], $range['to']]);
    foreach ($rows as $row) fputcsv($out, [$row['expense_date'], csv_safe($row['name']), csv_safe($row['description']), $row['amount'], $row['payment_method']]);
} elseif ($type === 'invoices') {
    fputcsv($out, ['Number', 'Date', 'Member', 'Total', 'Paid', 'Balance', 'Status']);
    $rows = db_all('SELECT i.*, m.full_name FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? ORDER BY i.id', [$gymId]);
    foreach ($rows as $row) fputcsv($out, [csv_safe($row['invoice_number']), $row['invoice_date'], csv_safe($row['full_name']), $row['total_amount'], $row['amount_paid'], $row['balance_due'], $row['status']]);
} elseif ($type === 'attendance') {
    fputcsv($out, ['Member', 'Check in', 'Check out', 'Method']);
    $rows = db_all('SELECT a.*, m.full_name FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? ORDER BY a.check_in_at DESC', [$gymId]);
    foreach ($rows as $row) fputcsv($out, [csv_safe($row['full_name']), $row['check_in_at'], (string) $row['check_out_at'], $row['method']]);
} else {
    fputcsv($out, ['Code', 'Name', 'Phone', 'Email', 'Status', 'Joined']);
    $rows = db_all('SELECT * FROM members WHERE gym_id = ? ORDER BY full_name', [$gymId]);
    foreach ($rows as $row) fputcsv($out, [csv_safe($row['member_code']), csv_safe($row['full_name']), csv_safe($row['phone']), csv_safe((string) $row['email']), $row['status'], $row['registration_date']]);
}
fclose($out);
exit;
