<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('attendance.manage');
$gymId = (int) $gym['id'];
$today = gym_today($gym);
[$start, $end] = gym_day_utc_bounds($today, $gym);

handle_post(function () use ($gymId, $user) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'checkout') {
        $result = AttendanceService::checkOut($gymId, (int) $user['id'], (int) $_POST['member_id']);
        flash('success', $result['member_name'] . ' is checked out.');
        redirect_to('attendance');
    }
    $memberId = (int) ($_POST['member_id'] ?? 0);
    if (!$memberId && !empty($_POST['query'])) {
        $q = trim((string) $_POST['query']);
        $found = db_one('SELECT id FROM members WHERE gym_id = ? AND (member_code = ? OR phone = ?) LIMIT 1', [$gymId, $q, $q]);
        if (!$found) throw new AppException('No member matches that ID or phone.');
        $memberId = (int) $found['id'];
    }
    $result = AttendanceService::checkIn($gymId, (int) $user['id'], $memberId, 'manual', !empty($_POST['override']), can('attendance.override'), (string) ($_POST['notes'] ?? ''));
    flash('success', $result['member_name'] . ' is in.');
    redirect_to('attendance?ok=1');
});

$month = (string) ($_GET['month'] ?? substr($today, 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = substr($today, 0, 7);
$offset = gym_now($gym)->format('P');
$counts = db_all("SELECT DATE(CONVERT_TZ(check_in_at, '+00:00', ?)) AS d, COUNT(*) AS c FROM attendance WHERE gym_id = ? AND DATE_FORMAT(CONVERT_TZ(check_in_at, '+00:00', ?), '%Y-%m') = ? GROUP BY d", [$offset, $gymId, $offset, $month]);
$map = [];
foreach ($counts as $row) if ($row['d']) $map[$row['d']] = (int) $row['c'];
$open = db_all("SELECT a.*, m.full_name, m.member_code, m.photo_path FROM attendance a JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? AND a.check_in_at >= ? AND a.check_in_at < ? ORDER BY a.check_in_at DESC", [$gymId, $start, $end]);
layout_start('Attendance', 'attendance');
if (!empty($_GET['ok'])) echo '<div class="success-panel"><div class="checkorb">✓</div><div><strong>Checked in</strong><p class="muted">The visit is on today\'s list.</p></div></div>';
page_head('Front desk', 'Attendance', fmt_date($today), '<a class="btn btn-primary" href="' . e(url('attendance/kiosk')) . '">QR kiosk</a>');
echo '<section class="split"><article class="card"><h2>Manual check-in</h2>';
echo form_open();
echo '<input type="hidden" name="_back" value="' . e(url('attendance')) . '"><div class="form-grid">';
$members = db_all("SELECT id, full_name, member_code FROM members WHERE gym_id = ? AND status NOT IN ('archived','suspended') ORDER BY full_name", [$gymId]);
echo '<label class="field full"><span>Member</span><select name="member_id"><option value="">Select</option>';
foreach ($members as $member) echo '<option value="' . (int) $member['id'] . '">' . e($member['full_name'] . ' · ' . $member['member_code']) . '</option>';
echo '</select></label><label class="field"><span>Or ID / phone</span><input name="query"></label><label class="field"><span>Note</span><input name="notes"></label>';
if (can('attendance.override')) echo '<label class="check"><input type="checkbox" name="override" value="1"> Override expired or frozen</label>';
echo '</div><button class="btn btn-primary">Check in</button></form></article>';
echo '<article class="panel"><h2>Today</h2><div class="table-wrap"><table><tbody>';
foreach ($open as $row) {
    echo '<tr><td>' . e($row['full_name']) . '<small class="muted">' . e(fmt_datetime($row['check_in_at'], $gym)) . '</small></td><td>' . ($row['check_out_at'] ? e(fmt_datetime($row['check_out_at'], $gym)) : form_open() . '<input type="hidden" name="action" value="checkout"><input type="hidden" name="member_id" value="' . (int) $row['member_id'] . '"><input type="hidden" name="_back" value="' . e(url('attendance')) . '"><button class="btn btn-ghost btn-sm">Check out</button></form>') . '</td></tr>';
}
if (!$open) echo '<tr><td>Nobody has checked in today.</td></tr>';
echo '</tbody></table></div></article></section>';
$first = new DateTimeImmutable($month . '-01');
$days = (int) $first->format('t');
$pad = (int) $first->format('w');
echo '<article class="card" style="margin-top:16px"><h2>' . e($first->format('F Y')) . '</h2><form method="get"><input type="month" name="month" value="' . e($month) . '"><button class="btn btn-ghost btn-sm">Show</button></form><div class="calendar" style="margin-top:12px">';
foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $name) echo '<b>' . $name . '</b>';
for ($i = 0; $i < $pad; $i++) echo '<div></div>';
for ($day = 1; $day <= $days; $day++) {
    $date = $first->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    echo '<div class="day"><strong>' . $day . '</strong><em>' . (int) ($map[$date] ?? 0) . '</em></div>';
}
echo '</div></article>';
layout_end();
