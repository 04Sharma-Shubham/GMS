<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('reports.view');
$range = resolve_range($gym);
$report = ReportService::financial((int) $gym['id'], $range);
$expiring = db_all("SELECT s.end_date, m.full_name, m.phone, p.name AS plan_name FROM member_subscriptions s JOIN members m ON m.id = s.member_id JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND s.status = 'active' AND s.end_date BETWEEN ? AND ? ORDER BY s.end_date", [$gym['id'], $range['today'], add_days($range['today'], 30)]);
$attendance = (int) (db_one('SELECT COUNT(*) AS c FROM attendance WHERE gym_id = ? AND check_in_at >= ? AND check_in_at < ?', array_merge([$gym['id']], (function () use ($gym, $range) {
    $start = gym_day_utc_bounds($range['from'], $gym)[0];
    $end = gym_day_utc_bounds(add_days($range['to'], 1), $gym)[0];
    return [$start, $end];
})()))['c'] ?? 0);
layout_start('Reports', 'reports');
page_head('Finance', 'Reports', range_label($range), '<a class="btn btn-ghost" href="' . e(url('reports/export?type=payments&preset=' . $range['preset'] . '&from=' . $range['from'] . '&to=' . $range['to'])) . '">Payments CSV</a> <a class="btn btn-ghost" href="' . e(url('reports/export?type=expenses&from=' . $range['from'] . '&to=' . $range['to'])) . '">Expenses CSV</a> <button class="btn btn-primary" onclick="print()">Print</button>');
echo '<form class="filters" method="get"><select name="preset"><option value="month"' . selected($range['preset'], 'month') . '>This month</option><option value="last_month"' . selected($range['preset'], 'last_month') . '>Last month</option><option value="year"' . selected($range['preset'], 'year') . '>This year</option><option value="custom"' . selected($range['preset'], 'custom') . '>Custom</option></select><input type="date" name="from" value="' . e($range['from']) . '"><input type="date" name="to" value="' . e($range['to']) . '"><button class="btn btn-ghost btn-sm">Apply</button></form>';
echo '<section class="stat-grid"><article class="stat-card"><span>Collected</span><strong>' . e(money($report['revenue'], $gym)) . '</strong></article><article class="stat-card"><span>Expenses</span><strong>' . e(money($report['expenses'], $gym)) . '</strong></article><article class="stat-card"><span>Net cash</span><strong>' . e(money($report['net'], $gym)) . '</strong><em>Collected minus expenses</em></article><article class="stat-card"><span>Outstanding</span><strong>' . e(money($report['outstanding'], $gym)) . '</strong></article></section>';
echo '<p class="muted">Monthly payroll on staff profiles is ' . e(money($report['payroll'], $gym)) . '. It is not subtracted unless you also record it as an expense. Check-ins in range: ' . $attendance . '.</p>';
echo '<section class="split"><article class="panel"><h2>By payment method</h2><table><tbody>';
foreach ($report['methods'] as $row) echo '<tr><td>' . e(status_label($row['method'])) . '</td><td>' . (int) $row['c'] . '</td><td>' . e(money($row['total'], $gym)) . '</td></tr>';
if (!$report['methods']) echo '<tr><td>No collections in this range.</td></tr>';
echo '</tbody></table></article><article class="panel"><h2>Expenses by category</h2><table><tbody>';
foreach ($report['categories'] as $row) echo '<tr><td>' . e($row['name']) . '</td><td>' . e(money($row['total'], $gym)) . '</td></tr>';
if (!$report['categories']) echo '<tr><td>No expenses in this range.</td></tr>';
echo '</tbody></table></article></section>';
echo '<article class="panel" style="margin-top:16px"><h2>Outstanding dues</h2><div class="table-wrap"><table><thead><tr><th>Member</th><th>Invoice</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
foreach ($report['dues'] as $due) echo '<tr><td>' . e($due['full_name']) . '</td><td><a href="' . e(url('invoices/' . $due['id'])) . '">' . e($due['invoice_number']) . '</a></td><td>' . e(money($due['balance_due'], $gym)) . '</td><td>' . badge($due['status']) . '</td></tr>';
if (!$report['dues']) echo '<tr><td colspan="4">No open balances.</td></tr>';
echo '</tbody></table></div></article>';
echo '<article class="panel" style="margin-top:16px"><h2>Expiring in 30 days</h2><table><tbody>';
foreach ($expiring as $row) echo '<tr><td>' . e($row['full_name']) . '</td><td>' . e($row['plan_name']) . '</td><td>' . e(fmt_date($row['end_date'])) . '</td><td>' . e($row['phone']) . '</td></tr>';
if (!$expiring) echo '<tr><td>None in the next 30 days.</td></tr>';
echo '</tbody></table></article>';
layout_end();
