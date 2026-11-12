<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$actor, $gym] = boot_page('staff.manage');
$gymId = (int) $gym['id'];
$id = (int) ($_GET['id'] ?? 0);
$person = db_one('SELECT u.*, s.designation, s.salary, s.commission_percent, s.joining_date, s.bio FROM users u LEFT JOIN staff_profiles s ON s.user_id = u.id WHERE u.id = ? AND u.gym_id = ?', [$id, $gymId]);
if (!$person) {
    flash('error', 'Staff member not found.');
    redirect_to('trainers');
}
$range = resolve_range($gym);
handle_post(function () use ($gymId, $actor, $person, $range) {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'schedule') {
        StaffService::saveSchedule($gymId, (int) $person['id'], (int) $_POST['day_of_week'], (string) $_POST['start_time'], (string) $_POST['end_time'], (string) ($_POST['notes'] ?? ''));
        flash('success', 'Shift added.');
        redirect_to('trainers/' . $person['id']);
    }
    if ($action === 'delete_shift') {
        db_exec('DELETE FROM trainer_schedules WHERE id = ? AND gym_id = ? AND user_id = ?', [(int) $_POST['shift_id'], $gymId, $person['id']]);
        flash('success', 'Shift removed.');
        redirect_to('trainers/' . $person['id']);
    }
    StaffService::update($gymId, $actor, (int) $person['id'], $_POST);
    flash('success', 'Staff profile saved.');
    redirect_to('trainers/' . $person['id']);
});
$shifts = db_all('SELECT * FROM trainer_schedules WHERE gym_id = ? AND user_id = ? ORDER BY day_of_week, start_time', [$gymId, $id]);
$members = db_all('SELECT id, full_name, member_code, status FROM members WHERE gym_id = ? AND trainer_id = ? ORDER BY full_name', [$gymId, $id]);
$activity = db_all('SELECT * FROM audit_logs WHERE gym_id = ? AND user_id = ? ORDER BY id DESC LIMIT 12', [$gymId, $id]);
$commission = StaffService::commission($gymId, $id, $range['from'], $range['to']);
$days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
layout_start($person['name'], 'trainers');
page_head(status_label($person['role']), $person['name'], $person['email']);
echo '<section class="split"><article class="panel"><h2>Profile</h2>';
echo form_open();
echo '<input type="hidden" name="_back" value="' . e(url('trainers/' . $id)) . '"><div class="form-grid">';
echo '<label class="field"><span>Name</span><input name="name" value="' . e($person['name']) . '"></label><label class="field"><span>Email</span><input name="email" value="' . e($person['email']) . '"></label>';
echo '<label class="field"><span>Phone</span><input name="phone" value="' . e((string) $person['phone']) . '"></label><label class="field"><span>Role</span><select name="role">';
foreach (['owner' => 'Gym Owner', 'manager' => 'Manager', 'receptionist' => 'Receptionist', 'trainer' => 'Trainer'] as $key => $label) {
    if ($key === 'owner' && $person['role'] !== 'owner') continue;
    echo '<option value="' . $key . '"' . selected($person['role'], $key) . '>' . e($label) . '</option>';
}
echo '</select></label><label class="field"><span>Status</span><select name="status"><option value="active"' . selected($person['status'], 'active') . '>Active</option><option value="disabled"' . selected($person['status'], 'disabled') . '>Disabled</option></select></label>';
echo '<label class="field"><span>New password</span><input type="password" name="password" placeholder="Leave blank to keep"></label><label class="field"><span>Designation</span><input name="designation" value="' . e((string) $person['designation']) . '"></label>';
if (can('staff.salary')) {
    echo '<label class="field"><span>Salary</span><input name="salary" value="' . e((string) ($person['salary'] ?? '0')) . '"></label><label class="field"><span>Commission %</span><input name="commission_percent" value="' . e((string) ($person['commission_percent'] ?? '0')) . '"></label>';
}
echo '<label class="field"><span>Joined</span><input type="date" name="joining_date" value="' . e((string) $person['joining_date']) . '"></label><label class="field full"><span>Bio</span><textarea name="bio">' . e((string) $person['bio']) . '</textarea></label></div><button class="btn btn-primary">Save</button></form></article>';
echo '<article class="panel"><h2>PT commission · ' . e(range_label($range)) . '</h2><p>Collected on personal-training plans for assigned members: <strong>' . e(money($commission['base'], $gym)) . '</strong></p><p>Commission at ' . e($commission['percent']) . '%: <strong>' . e(money($commission['commission'], $gym)) . '</strong></p><p class="muted">Salary on the profile is informational. Record salary payments as expenses if they should affect net income.</p><h2>Assigned members</h2><ul>';
foreach ($members as $member) echo '<li><a href="' . e(url('members/' . $member['id'])) . '">' . e($member['full_name']) . '</a> ' . badge($member['status']) . '</li>';
if (!$members) echo '<li>No members assigned.</li>';
echo '</ul></article></section>';
echo '<section class="split" style="margin-top:16px"><article class="panel"><h2>Schedule</h2><ul>';
foreach ($shifts as $shift) {
    echo '<li>' . e($days[(int) $shift['day_of_week']]) . ' ' . e(substr($shift['start_time'], 0, 5)) . '–' . e(substr($shift['end_time'], 0, 5));
    echo form_open();
    echo '<input type="hidden" name="action" value="delete_shift"><input type="hidden" name="shift_id" value="' . (int) $shift['id'] . '"><input type="hidden" name="_back" value="' . e(url('trainers/' . $id)) . '"><button class="btn btn-ghost btn-sm">Remove</button></form></li>';
}
echo '</ul>';
echo form_open();
echo '<input type="hidden" name="action" value="schedule"><input type="hidden" name="_back" value="' . e(url('trainers/' . $id)) . '"><div class="form-grid"><label class="field"><span>Day</span><select name="day_of_week">';
foreach ($days as $n => $label) echo '<option value="' . $n . '">' . e($label) . '</option>';
echo '</select></label><label class="field"><span>Start</span><input type="time" name="start_time" value="06:00" required></label><label class="field"><span>End</span><input type="time" name="end_time" value="11:00" required></label><label class="field"><span>Note</span><input name="notes"></label></div><button class="btn btn-ghost">Add shift</button></form></article>';
echo '<article class="panel"><h2>Activity</h2>';
foreach ($activity as $row) {
    $payload = json_decode((string) $row['new_values'], true) ?: [];
    echo '<p>' . e($payload['summary'] ?? $row['action']) . '<br><small class="muted">' . e(fmt_datetime($row['created_at'], $gym)) . '</small></p>';
}
if (!$activity) echo '<p class="muted">No recorded actions yet.</p>';
echo '</article></section>';
layout_end();
