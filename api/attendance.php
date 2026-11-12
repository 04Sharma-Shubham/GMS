<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('attendance.manage');
if (!is_post()) {
    json_response(['error' => 'POST required'], 405);
}
try {
    csrf_verify();
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    if (($body['action'] ?? '') === 'checkout') {
        $result = AttendanceService::checkOut(gym_id(), user_id(), (int) ($body['member_id'] ?? 0));
        json_response(['ok' => true, 'result' => $result]);
    }
    if (!empty($body['token'])) {
        $result = AttendanceService::checkInToken(gym_id(), user_id(), (string) $body['token'], !empty($body['override']), can('attendance.override'));
    } else {
        $result = AttendanceService::checkIn(gym_id(), user_id(), (int) ($body['member_id'] ?? 0), 'manual', !empty($body['override']), can('attendance.override'));
    }
    json_response(['ok' => true, 'result' => $result]);
} catch (AppException $e) {
    json_response(['error' => $e->getMessage()], 422);
}
