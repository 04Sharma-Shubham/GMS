<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $match = db_one('SELECT id FROM whatsapp_settings WHERE webhook_verify_token = ?', [$token]);
    if ($mode === 'subscribe' && $match && $challenge !== '') {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Forbidden');
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Bad request');
}
$signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$secrets = db_all('SELECT app_secret_encrypted FROM whatsapp_settings WHERE app_secret_encrypted IS NOT NULL AND app_secret_encrypted <> \'\'');
if ($secrets) {
    $valid = false;
    foreach ($secrets as $row) {
        $secret = secret_decrypt($row['app_secret_encrypted']);
        if ($secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature)) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        http_response_code(403);
        exit('Invalid signature');
    }
}
foreach ($payload['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        foreach ($change['value']['statuses'] ?? [] as $status) {
            $id = (string) ($status['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $error = $status['errors'][0]['title'] ?? null;
            db_exec('UPDATE whatsapp_message_logs SET delivery_status = ?, error_message = COALESCE(?, error_message), updated_at = ? WHERE wa_message_id = ?', [
                clip((string) ($status['status'] ?? ''), 32),
                $error ? clip((string) $error, 500) : null,
                utc_now(),
                $id,
            ]);
        }
    }
}
json_response(['ok' => true]);
