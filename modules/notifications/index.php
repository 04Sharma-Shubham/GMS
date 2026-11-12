<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('nav.notifications');
if (is_post()) {
    csrf_verify();
    NotificationService::markRead((int) $gym['id'], (int) $user['id'], ($_POST['id'] ?? '') === 'all' ? null : (int) $_POST['id']);
    flash('success', 'Updated.');
    redirect_to('notifications');
}
$rows = db_all('SELECT * FROM notifications WHERE gym_id = ? AND user_id = ? ORDER BY id DESC LIMIT 100', [$gym['id'], $user['id']]);
layout_start('Notifications', 'notifications');
page_head('Inbox', 'Notifications', 'Membership, payment, and WhatsApp alerts for your login.', form_open() . '<input type="hidden" name="id" value="all"><input type="hidden" name="_back" value="' . e(url('notifications')) . '"><button class="btn btn-ghost">Mark all read</button></form>');
if (!$rows) {
    empty_state('You are caught up', 'New alerts will land here.');
} else {
    echo '<article class="card">';
    foreach ($rows as $row) {
        echo '<div class="list-person" style="justify-content:space-between;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.08)"><div><strong>' . e($row['title']) . '</strong><span>' . e($row['body']) . '</span><small class="muted">' . e(fmt_datetime($row['created_at'], $gym)) . '</small></div><div class="cluster">';
        if ($row['link']) echo '<a class="btn btn-ghost btn-sm" href="' . e(url($row['link'] . '?read=' . $row['id'])) . '">Open</a>';
        if (!(int) $row['is_read']) {
            echo form_open();
            echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '"><input type="hidden" name="_back" value="' . e(url('notifications')) . '"><button class="btn btn-ghost btn-sm">Read</button></form>';
        }
        echo '</div></div>';
    }
    echo '</article>';
}
layout_end();
