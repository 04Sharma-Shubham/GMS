<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('workouts.manage');
$gymId = (int) $gym['id'];
$scope = trainer_scope_id();
handle_post(function () use ($gymId, $user, $scope) {
    $action = (string) ($_POST['action'] ?? 'plan');
    if ($action === 'assign') {
        $plan = db_one('SELECT * FROM workout_plans WHERE id = ? AND gym_id = ?', [(int) $_POST['workout_plan_id'], $gymId]);
        $member = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [(int) $_POST['member_id'], $gymId]);
        if (!$plan || !$member) throw new AppException('Choose a plan and a member from this gym.');
        if ($scope && (int) $member['trainer_id'] !== $scope && (int) $plan['trainer_id'] !== $scope) {
            throw new AppException('You can assign plans to your members.');
        }
        $start = (string) ($_POST['start_date'] ?? gym_today(current_gym()));
        if (!valid_date($start)) throw new AppException('Start date is invalid.');
        db_insert('INSERT INTO workout_assignments (gym_id, workout_plan_id, member_id, assigned_by, start_date, notes, created_at) VALUES (?,?,?,?,?,?,?)', [
            $gymId, $plan['id'], $member['id'], $user['id'], $start, clip($_POST['notes'] ?? '', 500) ?: null, utc_now(),
        ]);
        flash('success', 'Workout assigned to ' . $member['full_name'] . '.');
        redirect_to('workouts');
    }
    $name = clip($_POST['name'] ?? '', 160);
    if ($name === '') throw new AppException('Plan name is required.');
    $id = db_insert('INSERT INTO workout_plans (gym_id, name, description, trainer_id, created_at, updated_at) VALUES (?,?,?,?,?,?)', [
        $gymId, $name, clip($_POST['description'] ?? '', 4000) ?: null, $scope ?: ((int) ($_POST['trainer_id'] ?? 0) ?: null), utc_now(), utc_now(),
    ]);
    AuditService::log($gymId, (int) $user['id'], 'workout.created', 'workout', $id, null, ['summary' => 'Created workout ' . $name]);
    flash('success', 'Workout plan saved.');
    redirect_to('workouts');
});
$plans = db_all('SELECT w.*, u.name AS trainer_name FROM workout_plans w LEFT JOIN users u ON u.id = w.trainer_id WHERE w.gym_id = ?' . ($scope ? ' AND (w.trainer_id = ? OR w.trainer_id IS NULL)' : '') . ' ORDER BY w.id DESC', $scope ? [$gymId, $scope] : [$gymId]);
$members = db_all('SELECT id, full_name FROM members WHERE gym_id = ? AND status <> \'archived\'' . ($scope ? ' AND trainer_id = ?' : '') . ' ORDER BY full_name', $scope ? [$gymId, $scope] : [$gymId]);
$assignments = db_all('SELECT a.*, w.name, m.full_name FROM workout_assignments a JOIN workout_plans w ON w.id = a.workout_plan_id JOIN members m ON m.id = a.member_id WHERE a.gym_id = ? ORDER BY a.id DESC LIMIT 30', [$gymId]);
layout_start('Workouts', 'workouts');
page_head('Coaching', 'Workout plans', 'Assign a plan and it shows on the member profile.');
echo '<section class="split"><article class="panel"><h2>New plan</h2>';
echo form_open();
echo '<input type="hidden" name="action" value="plan"><input type="hidden" name="_back" value="' . e(url('workouts')) . '"><label class="field"><span>Name</span><input name="name" required></label><label class="field"><span>Description</span><textarea name="description"></textarea></label><button class="btn btn-primary">Save plan</button></form>';
echo '<h2 style="margin-top:18px">Assign</h2>';
echo form_open();
echo '<input type="hidden" name="action" value="assign"><input type="hidden" name="_back" value="' . e(url('workouts')) . '"><label class="field"><span>Plan</span><select name="workout_plan_id">';
foreach ($plans as $plan) echo '<option value="' . (int) $plan['id'] . '">' . e($plan['name']) . '</option>';
echo '</select></label><label class="field"><span>Member</span><select name="member_id">';
foreach ($members as $member) echo '<option value="' . (int) $member['id'] . '">' . e($member['full_name']) . '</option>';
echo '</select></label><label class="field"><span>Start</span><input type="date" name="start_date" value="' . e(gym_today($gym)) . '"></label><button class="btn btn-ghost">Assign</button></form></article>';
echo '<article class="panel"><h2>Recent assignments</h2><ul>';
foreach ($assignments as $row) echo '<li><a href="' . e(url('members/' . $row['member_id'])) . '">' . e($row['full_name']) . '</a> · ' . e($row['name']) . ' · ' . e(fmt_date($row['start_date'])) . '</li>';
if (!$assignments) echo '<li>No assignments yet.</li>';
echo '</ul></article></section>';
layout_end();
