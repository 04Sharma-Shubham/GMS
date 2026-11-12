<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
[$user, $gym] = boot_page('nav.dashboard');
$range = resolve_range($gym);
$trainer = trainer_scope_id();
$data = ReportService::dashboard((int) $gym['id'], $range, $trainer);
$k = $data['kpis'];
$showMoney = can('finance.revenue');
layout_start('Dashboard', 'dashboard');
?>
<header class="page-head">
    <div>
        <p class="eyebrow"><?= e($gym['name']) ?></p>
        <h1>Today on the floor</h1>
        <p class="lede"><?= e(range_label($range)) ?>. Figures come from this gym's records.</p>
    </div>
    <form class="filters" method="get">
        <select name="preset" onchange="if(this.value!=='custom') this.form.submit()">
            <?php foreach (['month' => 'This month', 'last_month' => 'Last month', '30d' => 'Last 30 days', 'year' => 'This year', 'custom' => 'Custom'] as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= selected($range['preset'], $value) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($range['from']) ?>">
        <input type="date" name="to" value="<?= e($range['to']) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Update</button>
    </form>
</header>
<section class="stat-grid">
    <?php
    $cards = [
        ['Total members', (string) $k['total_members'], 'Excluding archived', true],
        ['Active', (string) $k['active_members'], 'Current status', true],
        ['Expired', (string) $k['expired_members'], 'Needs a renewal', true],
        ['New in range', (string) $k['new_members'], 'By registration date', true],
        ['Today\'s attendance', (string) $k['today_attendance'], 'Distinct check-ins', true],
    ];
    if ($showMoney) {
        $cards[] = ['Revenue', money($k['revenue'], $gym), 'Collected in range', false];
        $cards[] = ['Pending now', money($k['pending'], $gym), 'Open invoice balances', false];
    }
    $cards[] = ['Expiring in 7 days', (string) $k['expiring'], 'Active plans', true];
    foreach ($cards as [$label, $value, $note, $count]):
    ?>
        <article class="stat-card"><span><?= e($label) ?></span><strong<?= $count ? ' data-count="' . e($value) . '"' : '' ?>><?= e($value) ?></strong><em><?= e($note) ?></em></article>
    <?php endforeach; ?>
</section>
<section class="split">
    <article class="card"><h2>Revenue</h2><div class="chart-box"><canvas id="revenueChart"></canvas></div></article>
    <article class="card"><h2>Plan mix</h2><div class="chart-box"><canvas id="planChart"></canvas></div></article>
</section>
<section class="split" style="margin-top:16px">
    <article class="card"><h2>Member growth</h2><div class="chart-box"><canvas id="growthChart"></canvas></div></article>
    <article class="card"><h2>Attendance</h2><div class="chart-box"><canvas id="attendanceChart"></canvas></div></article>
</section>
<section class="card" style="margin-top:16px"><h2>Payment status in range</h2><div class="chart-box"><canvas id="statusChart"></canvas></div></section>
<section class="split-3" style="margin-top:16px">
    <article class="panel"><h2>Recent members</h2>
        <?php if (!$data['recent_members']): empty_state('No members yet', 'Add the first member to start a file.'); else: ?>
        <div class="table-wrap"><table><tbody>
        <?php foreach ($data['recent_members'] as $member): ?>
            <tr><td><a class="list-person" href="<?= e(url('members/' . $member['id'])) ?>"><?= avatar_html($member['photo_path'], $member['full_name'], 'sm') ?><span><?= e($member['full_name']) ?><small><?= e($member['member_code']) ?></small></span></a></td><td><?= badge($member['status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
    <article class="panel"><h2>Recent payments</h2>
        <?php if (!$showMoney): ?><p class="muted">Payment details are hidden for this role.</p><?php elseif (!$data['recent_payments']): empty_state('No payments', 'Record a payment when cash or UPI is collected.'); else: ?>
        <div class="table-wrap"><table><tbody>
        <?php foreach ($data['recent_payments'] as $payment): ?>
            <tr><td><?= e($payment['full_name']) ?><small class="muted"><?= e($payment['invoice_number']) ?></small></td><td><?= e(money($payment['amount'], $gym)) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
    <article class="panel"><h2>Upcoming renewals</h2>
        <?php if (!$data['renewals']): ?><p class="muted">No memberships end in the next 14 days.</p><?php else: ?>
        <div class="table-wrap"><table><tbody>
        <?php foreach ($data['renewals'] as $row): ?>
            <tr><td><a href="<?= e(url('members/' . $row['member_id'])) ?>"><?= e($row['full_name']) ?></a><small class="muted"><?= e($row['plan_name']) ?></small></td><td><?= e(fmt_date($row['end_date'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<section class="split" style="margin-top:16px">
    <article class="panel"><h2>Expired memberships</h2>
        <?php if (!$data['expired']): ?><p class="muted">Nothing expired is waiting here.</p><?php else: ?>
        <div class="table-wrap"><table><tbody><?php foreach ($data['expired'] as $row): ?><tr><td><a href="<?= e(url('members/' . $row['member_id'])) ?>"><?= e($row['full_name']) ?></a></td><td><?= e($row['plan_name']) ?></td><td><?= e(fmt_date($row['end_date'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
    <article class="panel"><h2>Pending dues</h2>
        <?php if (!$showMoney): ?><p class="muted">Hidden for this role.</p><?php elseif (!$data['dues']): ?><p class="muted">No open balances.</p><?php else: ?>
        <div class="table-wrap"><table><tbody><?php foreach ($data['dues'] as $row): ?><tr><td><a href="<?= e(url('invoices/' . $row['id'])) ?>"><?= e($row['full_name']) ?></a></td><td><?= badge($row['status']) ?></td><td><?= e(money($row['balance_due'], $gym)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
</section>
<section class="split" style="margin-top:16px">
    <article class="panel"><h2>Recent activity</h2>
        <?php foreach ($data['activity'] as $row): $payload = json_decode((string) $row['new_values'], true) ?: []; ?>
            <p><strong><?= e($row['user_name'] ?: 'System') ?></strong> <?= e($payload['summary'] ?? status_label($row['action'])) ?><br><small class="muted"><?= e(fmt_datetime($row['created_at'], $gym)) ?></small></p>
        <?php endforeach; if (!$data['activity']) echo '<p class="muted">Activity will appear as the team works.</p>'; ?>
    </article>
    <article class="panel"><h2>Quick actions</h2>
        <div class="cluster">
            <?php if (can('members.manage')): ?><a class="btn btn-primary" href="<?= e(url('members/new')) ?>">Add member</a><?php endif; ?>
            <?php if (can('payments.record')): ?><a class="btn btn-ghost" href="<?= e(url('payments')) ?>">Record payment</a><?php endif; ?>
            <?php if (can('attendance.manage')): ?><a class="btn btn-ghost" href="<?= e(url('attendance/kiosk')) ?>">QR check-in</a><?php endif; ?>
            <?php if (can('expenses.manage')): ?><a class="btn btn-ghost" href="<?= e(url('expenses')) ?>">Add expense</a><?php endif; ?>
            <?php if (can('announcements.send')): ?><a class="btn btn-ghost" href="<?= e(url('automation')) ?>">Announcement</a><?php endif; ?>
        </div>
    </article>
</section>
<?php
push_json('chart-data', $data['charts']);
push_script('https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js');
push_script('assets/js/dashboard.js');
layout_end();
