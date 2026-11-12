<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('invoices.view');
$gymId = (int) $gym['id'];
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$where = 'i.gym_id = ?';
$params = [$gymId];
if (in_array($status, ['paid', 'partial', 'unpaid'], true)) {
    $where .= ' AND i.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where .= ' AND (i.invoice_number LIKE ? OR m.full_name LIKE ? OR m.phone LIKE ?)';
    $like = like_query($q);
    array_push($params, $like, $like, $like);
}
$total = (int) (db_one("SELECT COUNT(*) AS c FROM invoices i JOIN members m ON m.id = i.member_id WHERE $where", $params)['c'] ?? 0);
[$page, $pages, $offset, $per] = query_page($total, 15);
$rows = db_all("SELECT i.*, m.full_name FROM invoices i JOIN members m ON m.id = i.member_id WHERE $where ORDER BY i.id DESC LIMIT $per OFFSET $offset", $params);
layout_start('Invoices', 'invoices');
page_head('Billing', 'Invoices', 'Each membership sale creates an invoice. Payment status follows the money recorded.');
echo '<form class="filters" method="get"><input name="q" value="' . e($q) . '" placeholder="Invoice or member"><select name="status"><option value="">All</option>';
foreach (['paid' => 'Paid', 'partial' => 'Partially Paid', 'unpaid' => 'Unpaid'] as $key => $label) echo '<option value="' . $key . '"' . selected($status, $key) . '>' . e($label) . '</option>';
echo '</select><button class="btn btn-ghost btn-sm">Filter</button></form>';
if (!$rows) {
    empty_state('No invoices', 'Assign a membership to generate the first invoice.');
} else {
    echo '<article class="card table-wrap"><table><thead><tr><th>Invoice</th><th>Member</th><th>Total</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td><a href="' . e(url('invoices/' . $row['id'])) . '">' . e($row['invoice_number']) . '</a><small class="muted">' . e(fmt_date($row['invoice_date'])) . '</small></td><td>' . e($row['full_name']) . '</td><td>' . e(money($row['total_amount'], $gym)) . '</td><td>' . e(money($row['balance_due'], $gym)) . '</td><td>' . badge($row['status']) . '</td></tr>';
    }
    echo '</tbody></table></article>';
    pagination($page, $pages);
}
layout_end();
