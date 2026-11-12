<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    json_response(['results' => []]);
}
$like = like_query($q);
$sql = 'SELECT id, full_name, member_code, phone FROM members WHERE gym_id = ? AND status <> \'archived\' AND (full_name LIKE ? OR phone LIKE ? OR member_code LIKE ?)';
$params = [gym_id(), $like, $like, $like];
if ($scope = trainer_scope_id()) {
    $sql .= ' AND trainer_id = ?';
    $params[] = $scope;
}
$sql .= ' ORDER BY full_name LIMIT 8';
$results = [];
foreach (db_all($sql, $params) as $row) {
    $results[] = [
        'name' => $row['full_name'],
        'code' => $row['member_code'],
        'phone' => $row['phone'],
        'url' => url('members/' . $row['id']),
    ];
}
json_response(['results' => $results]);
