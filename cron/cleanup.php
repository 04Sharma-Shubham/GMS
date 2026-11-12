<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    $retries = WhatsAppService::retryDue(25);
    db_exec('DELETE FROM password_resets WHERE expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)', [utc_now(), gmdate('Y-m-d H:i:s', time() - 86400 * 7)]);
    db_exec('DELETE FROM rate_limits WHERE window_start < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 2)]);
    db_exec('DELETE FROM cron_runs WHERE started_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 30)]);
    cron_finish('cleanup', "Retried {$retries} WhatsApp messages and removed expired tokens.");
} catch (Throwable $e) {
    app_log('error', $e->getMessage());
    cron_finish('cleanup', $e->getMessage(), false);
    exit(1);
}
