<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('plans.manage');
$gymId = (int) $gym['id'];

handle_post(function () use ($gymId, $user) {
    $action = (string) ($_POST['action'] ?? 'save');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'toggle') {
        $plan = db_one('SELECT * FROM membership_plans WHERE id = ? AND gym_id = ?', [$id, $gymId]);
        if (!$plan) throw new AppException('Plan not found.');
        $status = $plan['status'] === 'active' ? 'inactive' : 'active';
        db_exec('UPDATE membership_plans SET status=?, updated_at=? WHERE id=? AND gym_id=?', [$status, utc_now(), $id, $gymId]);
        flash('success', $plan['name'] . ' is ' . $status . '.');
        redirect_to('memberships');
    }
    $name = clip($_POST['name'] ?? '', 120);
    $days = (int) ($_POST['duration_days'] ?? 0);
    $price = money_norm($_POST['price'] ?? '0');
    if ($name === '' || $days < 1 || money_comp($price, '0') < 0) {
        throw new AppException('Name, a duration of at least 1 day, and a price are required.');
    }
    $now = utc_now();
    $params = [$name, $days, $price, clip($_POST['description'] ?? '', 2000), clip($_POST['benefits'] ?? '', 4000), !empty($_POST['is_personal_training']) ? 1 : 0, $now];
    if ($id) {
        $params[] = $id;
        $params[] = $gymId;
        db_exec('UPDATE membership_plans SET name=?, duration_days=?, price=?, description=?, benefits=?, is_personal_training=?, updated_at=? WHERE id=? AND gym_id=?', $params);
        AuditService::log($gymId, (int) $user['id'], 'plan.updated', 'plan', $id, null, ['summary' => 'Updated plan ' . $name]);
        flash('success', 'Plan updated.');
    } else {
        array_splice($params, 6, 0, ['active']);
        $params = [$gymId, $name, $days, $price, clip($_POST['description'] ?? '', 2000), clip($_POST['benefits'] ?? '', 4000), !empty($_POST['is_personal_training']) ? 1 : 0, 'active', $now, $now];
        $newId = db_insert('INSERT INTO membership_plans (gym_id, name, duration_days, price, description, benefits, is_personal_training, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', $params);
        AuditService::log($gymId, (int) $user['id'], 'plan.created', 'plan', $newId, null, ['summary' => 'Created plan ' . $name]);
        flash('success', 'Plan created.');
    }
    redirect_to('memberships');
});

$plans = db_all('SELECT p.*, (SELECT COUNT(*) FROM member_subscriptions s WHERE s.plan_id = p.id AND s.status IN (\'active\',\'frozen\')) AS active_count FROM membership_plans p WHERE p.gym_id = ? ORDER BY p.status, p.price', [$gymId]);
$edit = null;
if ((int) ($_GET['edit'] ?? 0)) {
    $edit = db_one('SELECT * FROM membership_plans WHERE id = ? AND gym_id = ?', [(int) $_GET['edit'], $gymId]);
}
layout_start('Plans', 'memberships');
page_head('Memberships', 'Plans', 'Duration is stored in days so custom plans stay exact.');
echo '<section class="split"><article class="panel"><h2>' . ($edit ? 'Edit plan' : 'New plan') . '</h2>';
echo form_open();
echo '<input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI']) . '">';
if ($edit) echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
echo '<div class="form-grid"><label class="field"><span>Name</span><input name="name" required value="' . e($edit['name'] ?? '') . '"></label>';
echo '<label class="field"><span>Duration (days)</span><input type="number" min="1" name="duration_days" required value="' . e((string) ($edit['duration_days'] ?? 30)) . '"></label>';
echo '<label class="field"><span>Price</span><input name="price" required value="' . e((string) ($edit['price'] ?? '1500.00')) . '"></label>';
echo '<label class="check"><input type="checkbox" name="is_personal_training" value="1"' . checked_attr((bool) ($edit['is_personal_training'] ?? false)) . '> Personal training plan</label>';
echo '<label class="field full"><span>Description</span><textarea name="description">' . e($edit['description'] ?? '') . '</textarea></label>';
echo '<label class="field full"><span>Benefits</span><textarea name="benefits">' . e($edit['benefits'] ?? '') . '</textarea></label></div>';
echo '<button class="btn btn-primary" type="submit">Save plan</button></form></article>';
echo '<article class="panel table-wrap"><h2>Library</h2><table><thead><tr><th>Plan</th><th>Price</th><th>Active</th><th></th></tr></thead><tbody>';
foreach ($plans as $plan) {
    echo '<tr><td><strong>' . e($plan['name']) . '</strong><small class="muted">' . (int) $plan['duration_days'] . ' days</small><br>' . badge($plan['status']) . '</td><td>' . e(money($plan['price'], $gym)) . '</td><td>' . (int) $plan['active_count'] . '</td><td>';
    echo '<a class="btn btn-ghost btn-sm" href="' . e(url('memberships?edit=' . $plan['id'])) . '">Edit</a> ';
    echo form_open('', false);
    echo '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int) $plan['id'] . '"><input type="hidden" name="_back" value="' . e(url('memberships')) . '"><button class="btn btn-ghost btn-sm">' . ($plan['status'] === 'active' ? 'Pause' : 'Activate') . '</button></form></td></tr>';
}
if (!$plans) echo '<tr><td colspan="4">Create the first plan.</td></tr>';
echo '</tbody></table></article></section>';
layout_end();
