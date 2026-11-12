<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('staff.manage');
$gymId = (int) $gym['id'];
handle_post(function () use ($gymId, $user) {
    $id = StaffService::create($gymId, $user, $_POST);
    flash('success', 'Staff account created. They can sign in with the email and password you set.');
    redirect_to('trainers/' . $id);
});
$staff = db_all('SELECT u.*, s.designation, s.salary, s.commission_percent, (SELECT COUNT(*) FROM members m WHERE m.trainer_id = u.id AND m.status = \'active\') AS member_count FROM users u LEFT JOIN staff_profiles s ON s.user_id = u.id WHERE u.gym_id = ? ORDER BY u.role, u.name', [$gymId]);
layout_start('Staff', 'trainers');
page_head('Team', 'Staff and trainers', 'Roles control what each person can change.');
echo '<section class="split"><article class="panel"><h2>Add staff</h2>';
echo form_open();
echo '<input type="hidden" name="_back" value="' . e(url('trainers')) . '"><div class="form-grid">';
echo '<label class="field"><span>Name</span><input name="name" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label>';
echo '<label class="field"><span>Phone</span><input name="phone"></label><label class="field"><span>Role</span><select name="role">';
$roles = $user['role'] === 'owner' ? ['manager' => 'Manager', 'receptionist' => 'Receptionist', 'trainer' => 'Trainer'] : ['receptionist' => 'Receptionist', 'trainer' => 'Trainer'];
foreach ($roles as $key => $label) echo '<option value="' . $key . '">' . e($label) . '</option>';
echo '</select></label><label class="field"><span>Password</span><input type="password" name="password" required></label><label class="field"><span>Designation</span><input name="designation"></label>';
if (can('staff.salary')) {
    echo '<label class="field"><span>Monthly salary</span><input name="salary" value="0"></label><label class="field"><span>PT commission %</span><input name="commission_percent" value="0"></label>';
}
echo '<label class="field"><span>Joining date</span><input type="date" name="joining_date" value="' . e(gym_today($gym)) . '"></label></div><button class="btn btn-primary">Create login</button></form></article>';
echo '<article class="card table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Members</th><th>Status</th></tr></thead><tbody>';
foreach ($staff as $person) {
    echo '<tr><td><a href="' . e(url('trainers/' . $person['id'])) . '">' . e($person['name']) . '</a><small class="muted">' . e($person['email']) . '</small></td><td>' . e(status_label($person['role'])) . '</td><td>' . (int) $person['member_count'] . '</td><td>' . badge($person['status'] === 'active' ? 'active' : 'archived') . '</td></tr>';
}
echo '</tbody></table></article></section>';
layout_end();
