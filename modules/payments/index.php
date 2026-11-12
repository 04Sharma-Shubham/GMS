<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('payments.record');
$gymId = (int) $gym['id'];
$today = gym_today($gym);

handle_post(function () use ($gymId, $user, $today) {
    $action = (string) ($_POST['action'] ?? 'record');
    if ($action === 'void') {
        require_permission('payments.void');
        $result = PaymentService::void($gymId, (int) $user['id'], (int) $_POST['payment_id'], (string) ($_POST['reason'] ?? ''));
        flash('success', 'Payment voided. Invoice is now ' . status_label($result['status']) . '.');
        redirect_to('invoices/' . $result['invoice_id']);
    }
    if ($action === 'meta') {
        require_permission('payments.void');
        PaymentService::updateMeta($gymId, (int) $user['id'], (int) $_POST['payment_id'], (string) $_POST['method'], (string) $_POST['payment_date'], $_POST['reference'] ?? '', $_POST['notes'] ?? '');
        flash('success', 'Payment details corrected. The amount was not changed.');
        redirect_to('payments');
    }
    $result = PaymentService::record(
        $gymId,
        (int) $user['id'],
        (int) ($_POST['invoice_id'] ?? 0),
        (string) ($_POST['amount'] ?? ''),
        (string) ($_POST['method'] ?? 'cash'),
        (string) ($_POST['payment_date'] ?? $today),
        $_POST['reference'] ?? '',
        $_POST['notes'] ?? '',
        !empty($_POST['send_whatsapp'])
    );
    $_SESSION['wa_result'] = $result['whatsapp'] ?? null;
    $message = 'Recorded ' . money($result['amount'], current_gym()) . '. Invoice is ' . status_label($result['status']) . '.';
    if (!empty($result['pdf_error'])) $message .= ' ' . $result['pdf_error'];
    flash('success', $message);
    redirect_to('invoices/' . $result['invoice_id'] . '?recorded=1');
});

$status = (string) ($_GET['status'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$where = 'p.gym_id = ?';
$params = [$gymId];
if (in_array($status, ['recorded', 'voided'], true)) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if (in_array($method, PaymentService::METHODS, true)) {
    $where .= ' AND p.method = ?';
    $params[] = $method;
}
if (valid_date((string) ($_GET['from'] ?? ''))) {
    $where .= ' AND p.payment_date >= ?';
    $params[] = $_GET['from'];
}
if (valid_date((string) ($_GET['to'] ?? ''))) {
    $where .= ' AND p.payment_date <= ?';
    $params[] = $_GET['to'];
}
$total = (int) (db_one("SELECT COUNT(*) AS c FROM payments p WHERE $where", $params)['c'] ?? 0);
[$page, $pages, $offset, $per] = query_page($total, 15);
$rows = db_all("SELECT p.*, m.full_name, i.invoice_number, i.balance_due, i.status AS invoice_status FROM payments p JOIN members m ON m.id = p.member_id JOIN invoices i ON i.id = p.invoice_id WHERE $where ORDER BY p.id DESC LIMIT $per OFFSET $offset", $params);
$open = db_all("SELECT i.id, i.invoice_number, i.balance_due, i.total_amount, m.full_name FROM invoices i JOIN members m ON m.id = i.member_id WHERE i.gym_id = ? AND i.balance_due > 0 ORDER BY m.full_name", [$gymId]);
$selected = (int) ($_GET['invoice'] ?? 0);
layout_start('Payments', 'payments');
page_head('Manual desk', 'Payments', 'No payment gateway. The balance is recalculated from recorded payments.');
echo '<section class="split"><article class="card" id="record"><h2>Record a payment</h2>';
if (!$open) {
    echo '<p>Every invoice is settled.</p>';
} else {
    echo form_open();
    echo '<input type="hidden" name="action" value="record"><input type="hidden" name="_back" value="' . e(url('payments')) . '"><div class="form-grid">';
    echo '<label class="field full"><span>Invoice</span><select name="invoice_id" required>';
    foreach ($open as $invoice) {
        echo '<option value="' . (int) $invoice['id'] . '" data-balance="' . e($invoice['balance_due']) . '"' . selected($selected, (string) $invoice['id']) . '>' . e($invoice['full_name'] . ' · ' . $invoice['invoice_number'] . ' · due ' . money($invoice['balance_due'], $gym)) . '</option>';
    }
    echo '</select></label><label class="field"><span>Amount</span><input name="amount" required inputmode="decimal"></label>';
    echo '<label class="field"><span>Date</span><input type="date" name="payment_date" value="' . e($today) . '" required></label>';
    echo '<label class="field"><span>Method</span><select name="method">';
    foreach (PaymentService::METHODS as $item) echo '<option value="' . $item . '">' . e(status_label($item)) . '</option>';
    echo '</select></label><label class="field"><span>Reference</span><input name="reference"></label>';
    echo '<label class="field full"><span>Notes</span><input name="notes"></label>';
    echo '<label class="check"><input type="checkbox" name="send_whatsapp" value="1"> Share confirmation on WhatsApp when the member opted in</label>';
    echo '</div><button class="btn btn-primary" type="submit">Save payment</button></form>';
}
echo '</article><article class="panel"><h2>How status is decided</h2><p>Paid when the amount received equals the invoice total.</p><p>Partially paid when some money is recorded and a balance remains.</p><p>Unpaid when nothing has been recorded. Generating an invoice does not mark it paid.</p><p>To correct an amount, void the payment and record a new one. Both steps are audited.</p></article></section>';
echo '<form class="filters" style="margin-top:16px" method="get"><select name="status"><option value="">Any state</option><option value="recorded"' . selected($status, 'recorded') . '>Recorded</option><option value="voided"' . selected($status, 'voided') . '>Voided</option></select>';
echo '<select name="method"><option value="">Any method</option>';
foreach (PaymentService::METHODS as $item) echo '<option value="' . $item . '"' . selected($method, $item) . '>' . e(status_label($item)) . '</option>';
echo '</select><input type="date" name="from" value="' . e((string) ($_GET['from'] ?? '')) . '"><input type="date" name="to" value="' . e((string) ($_GET['to'] ?? '')) . '"><button class="btn btn-ghost btn-sm">Filter</button></form>';
echo '<article class="card table-wrap"><table><thead><tr><th>Member</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $row) {
    echo '<tr><td>' . e($row['full_name']) . '<small class="muted">' . e(fmt_date($row['payment_date'])) . '</small></td><td><a href="' . e(url('invoices/' . $row['invoice_id'])) . '">' . e($row['invoice_number']) . '</a></td><td>' . e(money($row['amount'], $gym)) . '</td><td>' . e(status_label($row['method'])) . '</td><td>' . badge($row['status']) . '</td><td>';
    if (can('payments.void') && $row['status'] === 'recorded') {
        echo form_open();
        echo '<input type="hidden" name="action" value="void"><input type="hidden" name="payment_id" value="' . (int) $row['id'] . '"><input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '"><input name="reason" placeholder="Void reason" required> <button class="btn btn-danger btn-sm">Void</button></form>';
    }
    echo '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="6">No payments in this filter.</td></tr>';
echo '</tbody></table></article>';
pagination($page, $pages);
layout_end();
