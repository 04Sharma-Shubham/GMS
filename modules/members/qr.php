<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('members.view');
$id = (int) ($_GET['id'] ?? 0);
$member = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [$id, $gym['id']]);
$scope = trainer_scope_id();
if (!$member || ($scope && (int) $member['trainer_id'] !== $scope)) {
    http_response_code(404);
    exit('Not found');
}
if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
    header('Content-Type: text/plain');
    echo $member['qr_token'];
    exit;
}
$options = new chillerlan\QRCode\QROptions();
$options->outputType = chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG;
$options->scale = 6;
$options->drawLightModules = false;
header('Content-Type: image/svg+xml');
echo (new chillerlan\QRCode\QRCode($options))->render($member['qr_token']);
