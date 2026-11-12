<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('attendance.manage');
$gymId = (int) $gym['id'];
handle_post(function () use ($gymId, $user) {
    $result = AttendanceService::checkInToken($gymId, (int) $user['id'], (string) ($_POST['token'] ?? ''), !empty($_POST['override']), can('attendance.override'));
    flash('success', $result['member_name'] . ' is checked in.');
    redirect_to('attendance/kiosk?ok=' . urlencode($result['member_name']));
});
layout_start('QR check-in', 'attendance');
if (!empty($_GET['ok'])) echo '<div class="success-panel"><div class="checkorb">✓</div><div><strong>' . e((string) $_GET['ok']) . ' is in</strong><p class="muted">Ready for the next member.</p></div></div>';
echo '<section class="kiosk"><article class="card kiosk-scan"><h2>Scan a pass</h2><div id="qr-reader" style="min-height:240px"></div><button class="btn btn-ghost" id="start-scan" type="button">Use camera</button>';
echo form_open();
echo '<input type="hidden" name="_back" value="' . e(url('attendance/kiosk')) . '"><label class="field" style="margin-top:12px"><span>Token or scanned text</span><input name="token" autofocus required></label>';
if (can('attendance.override')) echo '<label class="check"><input type="checkbox" name="override" value="1"> Override expired or frozen</label>';
echo '<p><button class="btn btn-primary">Check in</button></p></form><p class="muted">If the camera is blocked, paste the code. This is still a real check-in.</p></article>';
echo '<article class="panel"><h2>Rules</h2><p>Archived and suspended members are refused.</p><p>Expired and frozen memberships are refused unless an owner or manager overrides.</p><p>A member who is already inside must be checked out before a new visit.</p></article></section>';
push_script('https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js');
push_script('assets/js/attendance.js');
layout_end();
