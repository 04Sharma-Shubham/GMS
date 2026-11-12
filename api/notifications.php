<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
if (is_post()) {
    csrf_verify();
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    NotificationService::markRead(gym_id(), user_id(), isset($body['id']) ? (int) $body['id'] : null);
}
json_response([
    'unread' => NotificationService::unreadCount(gym_id(), user_id()),
    'items' => NotificationService::latest(gym_id(), user_id(), 8),
]);
