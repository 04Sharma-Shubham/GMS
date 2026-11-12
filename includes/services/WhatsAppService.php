<?php

declare(strict_types=1);

class WhatsAppService
{
    public static function settings(int $gymId): array
    {
        $row = db_one('SELECT * FROM whatsapp_settings WHERE gym_id = ?', [$gymId]);
        if ($row) {
            return $row;
        }
        $now = utc_now();
        db_insert('INSERT INTO whatsapp_settings (gym_id, api_version, demo_mode, default_country_code, created_at, updated_at) VALUES (?,?,1,\'91\',?,?)', [$gymId, 'v21.0', $now, $now]);
        return db_one('SELECT * FROM whatsapp_settings WHERE gym_id = ?', [$gymId]) ?? [];
    }

    public static function isConfigured(array $settings): bool
    {
        if (!$settings || (int) ($settings['demo_mode'] ?? 1) === 1) {
            return false;
        }
        return trim((string) ($settings['phone_number_id'] ?? '')) !== '' && secret_decrypt($settings['access_token_encrypted'] ?? '') !== '';
    }

    public static function send(int $gymId, ?int $memberId, string $phone, string $templateKey, array $bodyParams, ?array $document = null): array
    {
        if (db()->inTransaction()) {
            throw new RuntimeException('WhatsApp messages are sent after the database transaction commits.');
        }
        $settings = self::settings($gymId);
        $gym = db_one('SELECT name FROM gyms WHERE id = ?', [$gymId]);
        $country = (string) ($settings['default_country_code'] ?: '91');
        $to = normalize_phone($phone, $country);
        $preview = self::preview($templateKey, $bodyParams, (string) ($gym['name'] ?? 'Gym'), $document);
        $template = db_one('SELECT * FROM whatsapp_templates WHERE gym_id = ? AND template_key = ?', [$gymId, $templateKey]);
        $configured = self::isConfigured($settings);
        $templateName = trim((string) ($template['template_name'] ?? ''));
        $now = utc_now();

        if (!$configured) {
            $logId = self::insertLog($gymId, $memberId, $templateKey, $to, [
                'mode' => 'demo',
                'preview' => $preview,
                'params' => $bodyParams,
            ], ['demo' => true], 'demo', null, null, null, 0, null);
            return self::result('demo', $logId, $to, $preview, null);
        }
        if ($templateName === '' || !(int) ($template['is_active'] ?? 0)) {
            $error = 'Map an approved WhatsApp template name for "' . status_label($templateKey) . '" before automatic sending.';
            $logId = self::insertLog($gymId, $memberId, $templateKey, $to, ['params' => $bodyParams], ['error' => $error], 'failed', null, $error, 400, 1, null);
            self::notifyFailure($gymId, $templateKey, $error);
            return self::result('failed', $logId, $to, $preview, $error);
        }

        $components = [];
        if ($document) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'document',
                    'document' => [
                        'link' => $document['link'],
                        'filename' => $document['filename'],
                    ],
                ]],
            ];
        }
        if ($bodyParams) {
            $parameters = [];
            foreach ($bodyParams as $param) {
                $parameters[] = ['type' => 'text', 'text' => wa_param((string) $param)];
            }
            $components[] = ['type' => 'body', 'parameters' => $parameters];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $template['language_code'] ?: 'en'],
                'components' => $components,
            ],
        ];
        $version = trim((string) ($settings['api_version'] ?: 'v21.0'));
        if (!preg_match('/^v\d+\.\d+$/', $version)) {
            $version = 'v21.0';
        }
        $config = require dirname(__DIR__, 2) . '/config/whatsapp.php';
        $url = rtrim($config['graph'], '/') . '/' . $version . '/' . rawurlencode((string) $settings['phone_number_id']) . '/messages';
        $token = secret_decrypt($settings['access_token_encrypted'] ?? '');
        $response = self::postJson($url, $token, $payload);
        $messageId = $response['json']['messages'][0]['id'] ?? null;
        $accepted = $response['http'] >= 200 && $response['http'] < 300 && is_string($messageId) && $messageId !== '';
        $error = $accepted ? null : self::apiError($response);
        $retry = !$accepted && self::retryable($response['http']);
        $logId = self::insertLog(
            $gymId,
            $memberId,
            $templateKey,
            $to,
            $payload,
            $response['json'] ?: ['raw' => $response['raw'], 'error' => $response['error']],
            $accepted ? 'sent' : 'failed',
            $accepted ? $messageId : null,
            $error,
            $response['http'],
            1,
            $retry ? gmdate('Y-m-d H:i:s', time() + 900) : null
        );
        if (!$accepted) {
            self::notifyFailure($gymId, $templateKey, (string) $error);
        }
        return self::result($accepted ? 'sent' : 'failed', $logId, $to, $preview, $error, $accepted ? $messageId : null);
    }

    public static function retryDue(int $limit = 20): int
    {
        $limit = max(1, min(50, $limit));
        $rows = db_all("SELECT * FROM whatsapp_message_logs WHERE status = 'failed' AND attempts < 3 AND next_retry_at IS NOT NULL AND next_retry_at <= ? ORDER BY id LIMIT {$limit}", [utc_now()]);
        $count = 0;
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload) || ($payload['type'] ?? '') !== 'template') {
                db_exec('UPDATE whatsapp_message_logs SET next_retry_at = NULL, updated_at = ? WHERE id = ?', [utc_now(), $row['id']]);
                continue;
            }
            $settings = self::settings((int) $row['gym_id']);
            if (!self::isConfigured($settings)) {
                continue;
            }
            $version = trim((string) ($settings['api_version'] ?: 'v21.0'));
            $config = require dirname(__DIR__, 2) . '/config/whatsapp.php';
            $url = rtrim($config['graph'], '/') . '/' . $version . '/' . rawurlencode((string) $settings['phone_number_id']) . '/messages';
            $response = self::postJson($url, secret_decrypt($settings['access_token_encrypted'] ?? ''), $payload);
            $messageId = $response['json']['messages'][0]['id'] ?? null;
            $accepted = $response['http'] >= 200 && $response['http'] < 300 && is_string($messageId) && $messageId !== '';
            $attempts = (int) $row['attempts'] + 1;
            $retry = !$accepted && self::retryable($response['http']) && $attempts < 3;
            db_exec('UPDATE whatsapp_message_logs SET status = ?, wa_message_id = ?, response_json = ?, error_message = ?, http_status = ?, attempts = ?, next_retry_at = ?, updated_at = ? WHERE id = ?', [
                $accepted ? 'sent' : 'failed',
                $accepted ? $messageId : $row['wa_message_id'],
                json_encode($response['json'] ?: ['raw' => $response['raw']], JSON_UNESCAPED_UNICODE),
                $accepted ? null : self::apiError($response),
                $response['http'],
                $attempts,
                $retry ? gmdate('Y-m-d H:i:s', time() + (900 * $attempts)) : null,
                utc_now(),
                $row['id'],
            ]);
            if ($accepted) {
                $count++;
            }
        }
        return $count;
    }

    public static function preview(string $templateKey, array $params, string $gymName, ?array $document = null): string
    {
        $p = array_map(static fn($v) => wa_param((string) $v), $params);
        $text = match ($templateKey) {
            'welcome' => 'Welcome to ' . $gymName . ', ' . ($p[0] ?? 'there') . '. Your membership file is ready.',
            'expiry_reminder' => ($p[0] ?? 'Hi') . ', your ' . ($p[1] ?? 'membership') . ' at ' . $gymName . ' expires on ' . ($p[2] ?? '') . ' (' . ($p[3] ?? '') . '). Reply to renew.',
            'payment_reminder' => ($p[0] ?? 'Hi') . ', invoice ' . ($p[1] ?? '') . ' has a balance of ' . ($p[2] ?? '') . ' and is ' . ($p[3] ?? 'unpaid') . '.',
            'payment_confirmation' => 'Payment received at ' . $gymName . '. ' . ($p[0] ?? '') . ' paid ' . ($p[1] ?? '') . ' against ' . ($p[2] ?? '') . '. Balance: ' . ($p[3] ?? '0') . '.',
            'invoice_document' => 'Invoice ' . ($p[1] ?? '') . ' for ' . ($p[0] ?? '') . '. Total ' . ($p[2] ?? '') . '.',
            'renewal_confirmation' => ($p[0] ?? 'Hi') . ', your ' . ($p[1] ?? 'plan') . ' is renewed through ' . ($p[2] ?? '') . '.',
            'announcement' => ($p[0] ?? 'Hi') . ', ' . ($p[1] ?? ''),
            default => $gymName . ' sent you a message.',
        };
        if ($document) {
            $text .= ' Invoice file: ' . ($document['filename'] ?? 'invoice.pdf');
        }
        return $text;
    }

    public static function clickToChat(string $phone, string $text, string $countryCode = '91'): string
    {
        return 'https://wa.me/' . normalize_phone($phone, $countryCode) . '?text=' . rawurlencode($text);
    }

    private static function result(string $status, int $logId, string $phone, string $preview, ?string $error, ?string $messageId = null): array
    {
        return [
            'status' => $status,
            'log_id' => $logId,
            'phone' => $phone,
            'preview' => $preview,
            'error' => $error,
            'wa_message_id' => $messageId,
            'click_to_chat' => self::clickToChat($phone, $preview),
            'automatic' => $status === 'sent',
        ];
    }

    private static function insertLog(int $gymId, ?int $memberId, string $key, string $phone, array $payload, array $response, string $status, ?string $waId, ?string $error, ?int $http, int $attempts, ?string $retryAt): int
    {
        return db_insert('INSERT INTO whatsapp_message_logs (gym_id, member_id, template_key, phone, payload_json, response_json, status, wa_message_id, error_message, http_status, attempts, next_retry_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $gymId,
            $memberId,
            $key,
            $phone,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $status,
            $waId,
            $error ? clip($error, 500) : null,
            $http,
            $attempts,
            $retryAt,
            utc_now(),
            utc_now(),
        ]);
    }

    private static function postJson(string $url, string $token, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['http' => 0, 'json' => [], 'raw' => '', 'error' => 'Could not start the HTTP client.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'http' => $http,
            'json' => is_array($json) ? $json : [],
            'raw' => is_string($raw) ? mb_substr($raw, 0, 4000) : '',
            'error' => $error,
        ];
    }

    private static function apiError(array $response): string
    {
        $message = $response['json']['error']['message'] ?? $response['error'] ?? '';
        if ($message === '') {
            $message = 'WhatsApp did not accept the message.';
        }
        return clip((string) $message, 500);
    }

    private static function retryable(int $http): bool
    {
        return in_array($http, [0, 408, 429, 500, 502, 503, 504], true);
    }

    private static function notifyFailure(int $gymId, string $key, string $error): void
    {
        NotificationService::fanout(null, $gymId, ['owner', 'manager'], 'whatsapp.failed', 'WhatsApp message failed', $key . ': ' . $error, 'automation');
    }
}
