<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('members.view');
$gymId = (int) $gym['id'];
$trainerScope = trainer_scope_id();

handle_post(function () use ($gymId, $user, $trainerScope) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        require_permission('members.manage');
        $id = (int) ($_POST['id'] ?? 0);
        $name = clip($_POST['full_name'] ?? '', 160);
        $phone = clip($_POST['phone'] ?? '', 30);
        if ($name === '' || !phone_is_valid($phone)) {
            throw new AppException('Name and a valid phone number are required.');
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !valid_email($email)) {
            throw new AppException('Enter a valid email or leave it blank.');
        }
        $gender = (string) ($_POST['gender'] ?? 'undisclosed');
        if (!in_array($gender, ['female', 'male', 'other', 'undisclosed'], true)) {
            $gender = 'undisclosed';
        }
        $dob = (string) ($_POST['date_of_birth'] ?? '');
        if ($dob !== '' && !valid_date($dob)) {
            throw new AppException('Date of birth is not valid.');
        }
        $trainer = (int) ($_POST['trainer_id'] ?? 0);
        if ($trainer) {
            $ok = db_one("SELECT id FROM users WHERE id = ? AND gym_id = ? AND role = 'trainer' AND status = 'active'", [$trainer, $gymId]);
            if (!$ok) {
                throw new AppException('Choose a trainer from this gym.');
            }
        }
        $photo = store_upload('photo', 'assets/uploads/members', image_mimes(), 2_000_000);
        $now = utc_now();
        $fields = [
            $name, $phone, $email ?: null, $gender, $dob ?: null,
            clip($_POST['address'] ?? '', 255) ?: null,
            clip($_POST['emergency_name'] ?? '', 160) ?: null,
            clip($_POST['emergency_phone'] ?? '', 30) ?: null,
            $trainer ?: null,
            clip($_POST['notes'] ?? '', 5000) ?: null,
            !empty($_POST['whatsapp_opt_in']) ? 1 : 0,
            $now,
        ];
        if ($id) {
            $existing = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [$id, $gymId]);
            if (!$existing || ($trainerScope && (int) $existing['trainer_id'] !== $trainerScope)) {
                throw new AppException('Member not found.');
            }
            if ($photo) {
                safe_unlink($existing['photo_path'], 'assets/uploads/members');
            }
            db_exec('UPDATE members SET full_name=?, phone=?, email=?, gender=?, date_of_birth=?, address=?, emergency_name=?, emergency_phone=?, trainer_id=?, notes=?, whatsapp_opt_in=?, updated_at=?' . ($photo ? ', photo_path=?' : '') . ' WHERE id=? AND gym_id=?', $photo ? array_merge($fields, [$photo, $id, $gymId]) : array_merge($fields, [$id, $gymId]));
            AuditService::log($gymId, (int) $user['id'], 'member.updated', 'member', $id, null, ['summary' => 'Updated ' . $name]);
            flash('success', 'Member updated.');
            redirect_to('members/' . $id);
        }
        $code = db_tx(function (PDO $pdo) use ($gymId) {
            $stmt = $pdo->prepare('SELECT member_prefix, member_next_number FROM gyms WHERE id = ? FOR UPDATE');
            $stmt->execute([$gymId]);
            $row = $stmt->fetch();
            $code = ($row['member_prefix'] ?: 'MBR') . '-' . $row['member_next_number'];
            $pdo->prepare('UPDATE gyms SET member_next_number = member_next_number + 1, updated_at = ? WHERE id = ?')->execute([utc_now(), $gymId]);
            return $code;
        });
        $newId = db_insert('INSERT INTO members (gym_id, member_code, full_name, photo_path, phone, email, gender, date_of_birth, address, emergency_name, emergency_phone, registration_date, trainer_id, notes, status, whatsapp_opt_in, qr_token, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'active\',?,?,?,?,?)', [
            $gymId, $code, $name, $photo, $phone, $email ?: null, $gender, $dob ?: null,
            clip($_POST['address'] ?? '', 255) ?: null, clip($_POST['emergency_name'] ?? '', 160) ?: null, clip($_POST['emergency_phone'] ?? '', 30) ?: null,
            gym_today($gym = current_gym()), $trainer ?: null, clip($_POST['notes'] ?? '', 5000) ?: null,
            !empty($_POST['whatsapp_opt_in']) ? 1 : 0, bin2hex(random_bytes(24)), (int) $user['id'], $now, $now,
        ]);
        AuditService::log($gymId, (int) $user['id'], 'member.created', 'member', $newId, null, ['summary' => 'Registered ' . $name]);
        NotificationService::fanout(null, $gymId, ['owner', 'manager'], 'member.created', 'New member', $name . ' · ' . $code, 'members/' . $newId);
        if (!empty($_POST['whatsapp_opt_in'])) {
            $result = WhatsAppService::send($gymId, $newId, $phone, 'welcome', [$name, current_gym()['name']]);
            $_SESSION['wa_result'] = $result;
        }
        flash('success', $code . ' is on the member list.');
        redirect_to('members/' . $newId);
    }
    if (in_array($action, ['archive', 'suspend', 'restore'], true)) {
        require_permission('members.archive');
        $id = (int) ($_POST['id'] ?? 0);
        $member = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [$id, $gymId]);
        if (!$member) {
            throw new AppException('Member not found.');
        }
        $status = match ($action) {
            'archive' => 'archived',
            'suspend' => 'suspended',
            default => 'active',
        };
        db_exec('UPDATE members SET status = ?, updated_at = ? WHERE id = ? AND gym_id = ?', [$status, utc_now(), $id, $gymId]);
        if ($action === 'restore') {
            db_tx(fn (PDO $pdo) => MembershipService::syncMember($pdo, $gymId, $id, gym_today(current_gym())));
        }
        AuditService::log($gymId, (int) $user['id'], 'member.' . $action, 'member', $id, ['status' => $member['status']], ['summary' => ucfirst($action) . ' ' . $member['full_name'], 'status' => $status]);
        flash('success', $member['full_name'] . ' is now ' . status_label($status) . '.');
        redirect_to('members/' . $id);
    }
    throw new AppException('Unknown action.');
});

$action = (string) ($_GET['action'] ?? 'list');
$editing = null;
if ($action === 'edit') {
    require_permission('members.manage');
    $editing = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [(int) ($_GET['id'] ?? 0), $gymId]);
    if (!$editing) {
        flash('error', 'Member not found.');
        redirect_to('members');
    }
}
$trainers = db_all("SELECT id, name FROM users WHERE gym_id = ? AND role = 'trainer' AND status = 'active' ORDER BY name", [$gymId]);
layout_start($action === 'list' ? 'Members' : ($editing ? 'Edit member' : 'New member'), 'members');

if ($action === 'new' || $action === 'edit') {
    require_permission('members.manage');
    $value = function (string $key, string $default = '') use ($editing) {
        if (isset($_SESSION['_old'])) {
            return old($key);
        }
        return (string) ($editing[$key] ?? $default);
    };
    page_head('Members', $editing ? 'Edit ' . $editing['full_name'] : 'New member', 'The member ID is generated when you save.');
    echo form_open(url('members'), true);
    echo '<input type="hidden" name="action" value="save"><input type="hidden" name="_back" value="' . e($_SERVER['REQUEST_URI'] ?? '/members') . '">';
    if ($editing) {
        echo '<input type="hidden" name="id" value="' . (int) $editing['id'] . '">';
    }
    echo '<div class="card"><div class="form-grid">';
    echo '<label class="field"><span>Full name</span><input name="full_name" required value="' . e($value('full_name')) . '"></label>';
    echo '<label class="field"><span>Phone</span><input name="phone" required value="' . e($value('phone')) . '"></label>';
    echo '<label class="field"><span>Email</span><input type="email" name="email" value="' . e($value('email')) . '"></label>';
    echo '<label class="field"><span>Gender</span><select name="gender">';
    foreach (['undisclosed', 'female', 'male', 'other'] as $gender) {
        echo '<option value="' . $gender . '"' . selected($value('gender', 'undisclosed'), $gender) . '>' . e(status_label($gender)) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="field"><span>Date of birth</span><input type="date" name="date_of_birth" value="' . e($value('date_of_birth')) . '"></label>';
    echo '<label class="field"><span>Photo</span><input type="file" name="photo" accept="image/png,image/jpeg,image/webp"></label>';
    echo '<label class="field full"><span>Address</span><input name="address" value="' . e($value('address')) . '"></label>';
    echo '<label class="field"><span>Emergency contact</span><input name="emergency_name" value="' . e($value('emergency_name')) . '"></label>';
    echo '<label class="field"><span>Emergency phone</span><input name="emergency_phone" value="' . e($value('emergency_phone')) . '"></label>';
    echo '<label class="field"><span>Trainer</span><select name="trainer_id"><option value="">Unassigned</option>';
    foreach ($trainers as $trainer) {
        echo '<option value="' . (int) $trainer['id'] . '"' . selected($value('trainer_id'), (string) $trainer['id']) . '>' . e($trainer['name']) . '</option>';
    }
    echo '</select></label>';
    $opt = isset($_SESSION['_old']) ? old_checked('whatsapp_opt_in', false) : (bool) ($editing['whatsapp_opt_in'] ?? false);
    echo '<label class="check"><input type="checkbox" name="whatsapp_opt_in" value="1"' . checked_attr($opt) . '> Member agrees to WhatsApp messages</label>';
    echo '<label class="field full"><span>Notes</span><textarea name="notes">' . e($value('notes')) . '</textarea></label>';
    echo '</div><p><button class="btn btn-primary" type="submit">Save member</button> <a class="btn btn-ghost" href="' . e(url('members')) . '">Cancel</a></p></div></form>';
    layout_end();
    return;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$where = 'gym_id = ?';
$params = [$gymId];
if ($trainerScope) {
    $where .= ' AND trainer_id = ?';
    $params[] = $trainerScope;
}
if ($q !== '') {
    $where .= ' AND (full_name LIKE ? OR phone LIKE ? OR member_code LIKE ? OR email LIKE ?)';
    $like = like_query($q);
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['active', 'expired', 'suspended', 'archived'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$total = (int) (db_one("SELECT COUNT(*) AS c FROM members WHERE $where", $params)['c'] ?? 0);
[$page, $pages, $offset, $per] = query_page($total);
$rows = db_all("SELECT * FROM members WHERE $where ORDER BY id DESC LIMIT $per OFFSET $offset", $params);
page_head('Directory', 'Members', $total . ' profiles in this view.', can('members.manage') ? '<a class="btn btn-primary" href="' . e(url('members/new')) . '">Add member</a>' : '');
echo '<form class="filters" method="get"><input name="q" value="' . e($q) . '" placeholder="Name, phone, ID"><select name="status"><option value="">All statuses</option>';
foreach (['active', 'expired', 'suspended', 'archived'] as $item) {
    echo '<option value="' . $item . '"' . selected($status, $item) . '>' . e(status_label($item)) . '</option>';
}
echo '</select><button class="btn btn-ghost btn-sm" type="submit">Filter</button></form>';
if (!$rows) {
    empty_state('No members match', 'Add a member or clear the filters.', can('members.manage') ? '<a class="btn btn-primary" href="' . e(url('members/new')) . '">Add member</a>' : '');
} else {
    echo '<div class="card table-wrap"><table><thead><tr><th>Member</th><th>Phone</th><th>Status</th><th>Joined</th></tr></thead><tbody>';
    foreach ($rows as $member) {
        echo '<tr><td><a class="list-person" href="' . e(url('members/' . $member['id'])) . '">' . avatar_html($member['photo_path'], $member['full_name'], 'sm') . '<span>' . e($member['full_name']) . '<small>' . e($member['member_code']) . '</small></span></a></td><td>' . e($member['phone']) . '</td><td>' . badge($member['status']) . '</td><td>' . e(fmt_date($member['registration_date'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    pagination($page, $pages);
}
layout_end();
