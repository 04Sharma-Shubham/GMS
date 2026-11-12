<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('automation.view');
$gymId = (int) $gym['id'];
handle_post(function () use ($gymId, $gym) {
    require_permission('announcements.send');
    $message = clip($_POST['message'] ?? '', 800);
    if ($message === '') throw new AppException('Write the announcement first.');
    $ids = array_map('intval', (array) ($_POST['member_ids'] ?? []));
    if (!$ids && (($_POST['segment'] ?? '') === 'active')) {
        $ids = array_column(db_all("SELECT id FROM members WHERE gym_id = ? AND status = 'active' AND whatsapp_opt_in = 1", [$gymId]), 'id');
    }
    $ids = array_slice(array_unique(array_filter($ids)), 0, 200);
    if (!$ids) throw new AppException('Choose at least one opted-in member.');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $members = db_all("SELECT * FROM members WHERE gym_id = ? AND whatsapp_opt_in = 1 AND id IN ($marks)", array_merge([$gymId], $ids));
    $links = [];
    $sent = 0;
    $failed = 0;
    $demo = 0;
    foreach ($members as $member) {
        $result = WhatsAppService::send($gymId, (int) $member['id'], (string) $member['phone'], 'announcement', [$member['full_name'], $message]);
        if ($result['status'] === 'sent') $sent++;
        elseif ($result['status'] === 'demo') { $demo++; $links[] = ['name' => $member['full_name'], 'url' => $result['click_to_chat']]; }
        else $failed++;
    }
    $_SESSION['share_links'] = $links;
    flash('success', $sent . ' accepted by WhatsApp, ' . $demo . ' left as manual demo links, ' . $failed . ' failed.');
    redirect_to('automation');
});
$links = $_SESSION['share_links'] ?? [];
unset($_SESSION['share_links']);
$logs = db_all('SELECT l.*, m.full_name FROM whatsapp_message_logs l LEFT JOIN members m ON m.id = l.member_id WHERE l.gym_id = ? ORDER BY l.id DESC LIMIT 40', [$gymId]);
$runs = db_all('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 12');
$members = db_all("SELECT id, full_name, phone FROM members WHERE gym_id = ? AND status = 'active' AND whatsapp_opt_in = 1 ORDER BY full_name", [$gymId]);
$wa = WhatsAppService::settings($gymId);
layout_start('Automation', 'automation');
page_head('Messaging', 'WhatsApp automation', WhatsAppService::isConfigured($wa) ? 'Cloud API is configured.' : 'Demo mode is on. Links below are manual sends.');
if ($links) {
    echo '<article class="panel"><h2>Manual links</h2><p>These were not delivered automatically.</p><ul>';
    foreach ($links as $link) echo '<li><a target="_blank" rel="noopener" href="' . e($link['url']) . '">' . e($link['name']) . '</a></li>';
    echo '</ul></article>';
}
if (can('announcements.send')) {
    echo '<article class="card"><h2>Announcement</h2><p class="muted">Business-initiated messages use your approved announcement template. In demo mode each person gets a click-to-chat link instead.</p>';
    echo form_open();
    echo '<input type="hidden" name="_back" value="' . e(url('automation')) . '"><label class="field"><span>Message</span><textarea name="message" required></textarea></label>';
    echo '<label class="check"><input type="checkbox" name="segment" value="active"> All active opted-in members</label><div class="field"><span>Or choose members</span><select name="member_ids[]" multiple size="6">';
    foreach ($members as $member) echo '<option value="' . (int) $member['id'] . '">' . e($member['full_name'] . ' · ' . $member['phone']) . '</option>';
    echo '</select></div><button class="btn btn-primary">Queue messages</button></form></article>';
}
echo '<section class="split" style="margin-top:16px"><article class="panel"><h2>Message log</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>Type</th><th>Status</th></tr></thead><tbody>';
foreach ($logs as $log) {
    echo '<tr><td>' . e(fmt_datetime($log['created_at'], $gym)) . '</td><td>' . e($log['full_name'] ?: $log['phone']) . '</td><td>' . e($log['template_key']) . '</td><td>' . badge($log['status']) . ($log['delivery_status'] ? '<small class="muted">' . e($log['delivery_status']) . '</small>' : '') . ($log['error_message'] ? '<small class="muted">' . e($log['error_message']) . '</small>' : '') . '</td></tr>';
}
if (!$logs) echo '<tr><td colspan="4">No messages yet.</td></tr>';
echo '</tbody></table></div></article><article class="panel"><h2>Cron runs</h2>';
foreach ($runs as $run) echo '<p><strong>' . e($run['job_name']) . '</strong> ' . badge($run['status'] === 'success' ? 'sent' : ($run['status'] === 'failed' ? 'failed' : 'queued')) . '<br><small class="muted">' . e((string) $run['message']) . ' · ' . e(fmt_datetime($run['finished_at'] ?: $run['started_at'], $gym)) . '</small></p>';
if (!$runs) echo '<p class="muted">No cron job has reported in yet.</p>';
$root = 'php ' . dirname(__DIR__, 2);
echo '<h2>Hostinger commands</h2><pre style="white-space:pre-wrap">' . e($root . '/cron/membership_reminders.php') . "\n" . e($root . '/cron/payment_reminders.php') . "\n" . e($root . '/cron/membership_expiry.php') . "\n" . e($root . '/cron/cleanup.php') . '</pre><p class="muted">Or call each URL with ?key= your CRON_SECRET. Suggested times: expiry 00:10, membership reminders 08:00, payment reminders 09:00, cleanup 02:00.</p></article></section>';
layout_end();
