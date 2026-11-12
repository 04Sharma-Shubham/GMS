<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('members.view');
$gymId = (int) $gym['id'];
$id = (int) ($_GET['id'] ?? 0);
$member = db_one('SELECT m.*, u.name AS trainer_name FROM members m LEFT JOIN users u ON u.id = m.trainer_id WHERE m.id = ? AND m.gym_id = ?', [$id, $gymId]);
$scope = trainer_scope_id();
if (!$member || ($scope && (int) $member['trainer_id'] !== $scope)) {
    flash('error', 'Member not found.');
    redirect_to('members');
}
$tab = (string) ($_GET['tab'] ?? 'overview');
$today = gym_today($gym);
$subs = db_all('SELECT s.*, p.name AS plan_name, p.duration_days, p.is_personal_training FROM member_subscriptions s JOIN membership_plans p ON p.id = s.plan_id WHERE s.gym_id = ? AND s.member_id = ? ORDER BY s.id DESC', [$gymId, $id]);
$current = null;
foreach ($subs as $sub) {
    if (in_array($sub['status'], ['active', 'frozen'], true)) {
        $current = $sub;
        break;
    }
}
$plans = db_all("SELECT * FROM membership_plans WHERE gym_id = ? AND status = 'active' ORDER BY price", [$gymId]);
$invoices = db_all('SELECT * FROM invoices WHERE gym_id = ? AND member_id = ? ORDER BY id DESC', [$gymId, $id]);
$payments = db_all('SELECT p.*, i.invoice_number FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.gym_id = ? AND p.member_id = ? ORDER BY p.id DESC', [$gymId, $id]);
$attendance = db_all('SELECT * FROM attendance WHERE gym_id = ? AND member_id = ? ORDER BY check_in_at DESC LIMIT 40', [$gymId, $id]);
$activity = db_all('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.gym_id = ? AND ((entity_type = \'member\' AND entity_id = ?) OR JSON_UNQUOTE(JSON_EXTRACT(new_values, \'$.member_id\')) = ?) ORDER BY a.id DESC LIMIT 20', [$gymId, $id, (string) $id]);
$workouts = db_all('SELECT a.*, w.name FROM workout_assignments a JOIN workout_plans w ON w.id = a.workout_plan_id WHERE a.gym_id = ? AND a.member_id = ? ORDER BY a.id DESC', [$gymId, $id]);
$wa = $_SESSION['wa_result'] ?? null;
unset($_SESSION['wa_result']);

handle_post(function () use ($gym, $gymId, $user, $member, $today) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'assign') {
        require_permission('plans.manage');
        $result = MembershipService::assign($gymId, (int) $user['id'], [
            'member_id' => (int) $member['id'],
            'plan_id' => (int) ($_POST['plan_id'] ?? 0),
            'start_date' => $_POST['start_date'] ?? $today,
            'discount' => $_POST['discount'] ?? '0',
            'notes' => $_POST['notes'] ?? '',
            'change_type' => $_POST['change_type'] ?? 'new',
            'previous_subscription_id' => (int) ($_POST['previous_subscription_id'] ?? 0),
            'prorate' => !empty($_POST['prorate']),
            'payment_amount' => $_POST['payment_amount'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'payment_date' => $_POST['payment_date'] ?? $today,
            'payment_reference' => $_POST['payment_reference'] ?? '',
            'send_whatsapp' => !empty($_POST['send_whatsapp']),
        ]);
        $_SESSION['wa_result'] = $result['whatsapp'] ?? null;
        $message = 'Membership saved. Invoice ' . $result['invoice_number'] . ' is ' . status_label($result['status']) . '.';
        if (!empty($result['pdf_error'])) {
            $message .= ' PDF: ' . $result['pdf_error'];
        }
        flash('success', $message);
        redirect_to('invoices/' . $result['invoice_id'] . '?recorded=1');
    }
    if ($action === 'freeze' || $action === 'unfreeze') {
        require_permission('plans.manage');
        $subId = (int) ($_POST['subscription_id'] ?? 0);
        if ($action === 'freeze') {
            MembershipService::freeze($gymId, (int) $user['id'], $subId, $today);
            flash('success', 'Membership frozen.');
        } else {
            flash('success', MembershipService::unfreeze($gymId, (int) $user['id'], $subId, $today));
        }
        redirect_to('members/' . $member['id'] . '?tab=membership');
    }
    if ($action === 'checkin') {
        require_permission('attendance.manage');
        $result = AttendanceService::checkIn($gymId, (int) $user['id'], (int) $member['id'], 'manual', !empty($_POST['override']), can('attendance.override'));
        flash('success', $result['member_name'] . ' is checked in.');
        redirect_to('attendance');
    }
    throw new AppException('Unknown action.');
});

layout_start($member['full_name'], 'members');
if (!empty($_GET['ok'])) {
    echo '<div class="success-panel"><div class="checkorb">✓</div><div><strong>Saved</strong><p class="muted">The member file is up to date.</p></div></div>';
}
if (is_array($wa)) {
    $label = $wa['status'] === 'sent' ? 'WhatsApp accepted the message.' : ($wa['status'] === 'demo' ? 'Demo mode did not deliver this automatically.' : 'WhatsApp did not accept the message.');
    echo '<div class="flash flash-info">' . e($label) . ' <a href="' . e($wa['click_to_chat']) . '" target="_blank" rel="noopener">Open WhatsApp chat (manual send)</a></div>';
}
echo '<section class="card profile-hero"><div>' . avatar_html($member['photo_path'], $member['full_name'], 'lg') . '</div><div><p class="eyebrow">' . e($member['member_code']) . '</p><h1 style="font-size:40px;margin:0">' . e($member['full_name']) . '</h1><p>' . badge($member['status']);
if ($current && $current['status'] === 'frozen') {
    echo ' ' . badge('frozen');
}
echo '</p><p class="muted">' . e($member['phone']) . ($member['email'] ? ' · ' . e($member['email']) : '') . '</p></div><div class="page-actions">';
if (can('members.manage')) echo '<a class="btn btn-ghost" href="' . e(url('members/' . $id . '/edit')) . '">Edit</a>';
if (can('attendance.manage')) echo '<a class="btn btn-primary" href="#checkin">Check in</a>';
echo '</div></section>';
echo '<nav class="tabs" style="margin-top:16px">';
foreach (['overview' => 'Overview', 'membership' => 'Membership', 'payments' => 'Payments', 'invoices' => 'Invoices', 'attendance' => 'Attendance', 'activity' => 'Activity'] as $key => $label) {
    if (in_array($key, ['payments', 'invoices'], true) && !can('finance.revenue')) continue;
    echo '<a class="' . ($tab === $key ? 'is-active' : '') . '" href="' . e(url('members/' . $id . '?tab=' . $key)) . '">' . e($label) . '</a>';
}
echo '</nav>';

if ($tab === 'overview') {
    $left = $current ? days_inclusive($today, $current['end_date']) : 0;
    echo '<section class="split"><article class="panel"><h2>Contact</h2><p>' . e($member['address'] ?: 'No address') . '</p><p>Emergency: ' . e($member['emergency_name'] ?: '—') . ' ' . e($member['emergency_phone'] ?: '') . '</p><p>Trainer: ' . e($member['trainer_name'] ?: 'Unassigned') . '</p><p>WhatsApp consent: ' . ($member['whatsapp_opt_in'] ? 'Yes' : 'No') . '</p><p>Joined ' . e(fmt_date($member['registration_date'])) . '</p>';
    if ($member['notes']) echo '<p>' . nl2br(e($member['notes'])) . '</p>';
    echo '</article><article class="panel"><h2>Current plan</h2>';
    if (!$current) {
        echo '<p>No active plan.</p>';
    } else {
        $label = $current['status'] === 'frozen' ? 'Frozen' : ($current['end_date'] === $today ? 'Expires today' : $left . ' days left');
        echo '<p><strong>' . e($current['plan_name']) . '</strong><br>' . e(fmt_date($current['start_date'])) . ' – ' . e(fmt_date($current['end_date'])) . '<br>' . e($label) . '</p>';
    }
    echo '<h2>QR pass</h2><img alt="Member QR" width="180" height="180" src="' . e(url('members/' . $id . '/qr.svg')) . '"><p class="muted">Scan this at the attendance desk.</p>';
    if (can('members.archive') && $member['status'] !== 'archived') {
        echo form_open(url('members'));
        echo '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '">';
        echo '<button class="btn btn-danger btn-sm" name="action" value="suspend">Suspend</button> <button class="btn btn-ghost btn-sm" name="action" value="archive">Archive</button></form>';
    }
    if (can('members.archive') && $member['status'] !== 'active') {
        echo form_open(url('members'));
        echo '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '"><button class="btn btn-ghost btn-sm" name="action" value="restore">Restore</button></form>';
    }
    echo '</article></section>';
    if ($workouts) {
        echo '<article class="panel" style="margin-top:16px"><h2>Workouts</h2><ul>';
        foreach ($workouts as $workout) echo '<li>' . e($workout['name']) . ' · ' . e(fmt_date($workout['start_date'])) . '</li>';
        echo '</ul></article>';
    }
}

if ($tab === 'membership') {
    echo '<article class="panel table-wrap"><h2>History</h2><table><thead><tr><th>Plan</th><th>Period</th><th>Status</th><th>Total</th><th></th></tr></thead><tbody>';
    foreach ($subs as $sub) {
        echo '<tr><td>' . e($sub['plan_name']) . '<small class="muted">' . e($sub['change_type']) . '</small></td><td>' . e(fmt_date($sub['start_date'])) . ' – ' . e(fmt_date($sub['end_date'])) . '</td><td>' . badge($sub['status']) . '</td><td>' . e(can('finance.revenue') ? money($sub['total_amount'], $gym) : '—') . '</td><td>';
        if (can('plans.manage') && $sub['status'] === 'active') {
            echo form_open();
            echo '<input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '"><input type="hidden" name="subscription_id" value="' . (int) $sub['id'] . '"><button class="btn btn-ghost btn-sm" name="action" value="freeze">Freeze</button></form>';
        }
        if (can('plans.manage') && $sub['status'] === 'frozen') {
            echo form_open();
            echo '<input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '"><input type="hidden" name="subscription_id" value="' . (int) $sub['id'] . '"><button class="btn btn-ghost btn-sm" name="action" value="unfreeze">Unfreeze</button></form>';
        }
        echo '</td></tr>';
    }
    if (!$subs) echo '<tr><td colspan="5">No plans assigned yet.</td></tr>';
    echo '</tbody></table></article>';
    if (can('plans.manage')) {
        echo '<article class="panel" style="margin-top:16px"><h2>Assign, renew, or change</h2><p class="muted">Renewal and plan changes create a new subscription and a new invoice. Earlier invoices stay in the history.</p>';
        echo form_open();
        echo '<input type="hidden" name="action" value="assign"><input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '"><div class="form-grid">';
        echo '<label class="field"><span>Plan</span><select name="plan_id" required>';
        foreach ($plans as $plan) echo '<option value="' . (int) $plan['id'] . '">' . e($plan['name']) . ' · ' . e(money($plan['price'], $gym)) . '</option>';
        echo '</select></label><label class="field"><span>Type</span><select name="change_type"><option value="new">New</option><option value="renewal">Renewal</option><option value="upgrade">Upgrade</option><option value="downgrade">Downgrade</option></select></label>';
        echo '<label class="field"><span>Previous membership</span><select name="previous_subscription_id"><option value="">None</option>';
        foreach ($subs as $sub) if (in_array($sub['status'], ['active', 'frozen'], true)) echo '<option value="' . (int) $sub['id'] . '">' . e($sub['plan_name']) . ' until ' . e(fmt_date($sub['end_date'])) . '</option>';
        echo '</select></label><label class="field"><span>Start</span><input type="date" name="start_date" value="' . e($today) . '" required></label>';
        echo '<label class="field"><span>Discount</span><input name="discount" value="0"></label><label class="check"><input type="checkbox" name="prorate" value="1" checked> Apply unused value as a discount</label>';
        echo '<label class="field"><span>Amount collected now</span><input name="payment_amount" placeholder="Leave blank if unpaid"></label><label class="field"><span>Method</span><select name="payment_method">';
        foreach (PaymentService::METHODS as $method) echo '<option value="' . $method . '">' . e(status_label($method)) . '</option>';
        echo '</select></label><label class="field"><span>Payment date</span><input type="date" name="payment_date" value="' . e($today) . '"></label><label class="field"><span>Reference</span><input name="payment_reference"></label>';
        echo '<label class="field full"><span>Notes</span><input name="notes"></label><label class="check"><input type="checkbox" name="send_whatsapp" value="1"' . checked_attr((bool) $member['whatsapp_opt_in']) . '> Send WhatsApp if the member opted in</label>';
        echo '</div><button class="btn btn-primary" type="submit">Save membership</button></form></article>';
    }
}

if ($tab === 'payments' && can('finance.revenue')) {
    echo '<article class="panel table-wrap"><table><thead><tr><th>Date</th><th>Invoice</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead><tbody>';
    foreach ($payments as $payment) echo '<tr><td>' . e(fmt_date($payment['payment_date'])) . '</td><td><a href="' . e(url('invoices/' . $payment['invoice_id'])) . '">' . e($payment['invoice_number']) . '</a></td><td>' . e(money($payment['amount'], $gym)) . '</td><td>' . e(status_label($payment['method'])) . '</td><td>' . badge($payment['status']) . '</td></tr>';
    if (!$payments) echo '<tr><td colspan="5">No payments yet.</td></tr>';
    echo '</tbody></table></article>';
}
if ($tab === 'invoices' && can('finance.revenue')) {
    echo '<article class="panel table-wrap"><table><thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
    foreach ($invoices as $invoice) echo '<tr><td><a href="' . e(url('invoices/' . $invoice['id'])) . '">' . e($invoice['invoice_number']) . '</a></td><td>' . e(fmt_date($invoice['invoice_date'])) . '</td><td>' . e(money($invoice['total_amount'], $gym)) . '</td><td>' . e(money($invoice['balance_due'], $gym)) . '</td><td>' . badge($invoice['status']) . '</td></tr>';
    if (!$invoices) echo '<tr><td colspan="5">No invoices yet.</td></tr>';
    echo '</tbody></table></article>';
}
if ($tab === 'attendance') {
    echo '<article class="panel table-wrap"><table><thead><tr><th>In</th><th>Out</th><th>Method</th></tr></thead><tbody>';
    foreach ($attendance as $row) echo '<tr><td>' . e(fmt_datetime($row['check_in_at'], $gym)) . '</td><td>' . e(fmt_datetime($row['check_out_at'], $gym)) . '</td><td>' . e(strtoupper($row['method'])) . ($row['override_expired'] ? ' · override' : '') . '</td></tr>';
    if (!$attendance) echo '<tr><td colspan="3">No visits yet.</td></tr>';
    echo '</tbody></table></article>';
}
if ($tab === 'activity') {
    echo '<article class="panel">';
    foreach ($activity as $row) {
        $payload = json_decode((string) $row['new_values'], true) ?: [];
        echo '<p><strong>' . e($row['user_name'] ?: 'System') . '</strong> ' . e($payload['summary'] ?? $row['action']) . '<br><small class="muted">' . e(fmt_datetime($row['created_at'], $gym)) . '</small></p>';
    }
    if (!$activity) echo '<p class="muted">No activity yet.</p>';
    echo '</article>';
}
echo '<div class="modal-layer" id="checkin"><div class="card modal"><h2>Check in ' . e($member['full_name']) . '</h2>';
echo form_open();
echo '<input type="hidden" name="action" value="checkin"><input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '">';
if (can('attendance.override')) echo '<label class="check"><input type="checkbox" name="override" value="1"> Override expired or frozen membership</label>';
echo '<p><button class="btn btn-primary" type="submit">Check in</button> <a class="btn btn-ghost" href="#">Close</a></p></form></div></div>';
layout_end();
