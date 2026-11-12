<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $secret = (string) env('CRON_SECRET', '');
    $given = (string) ($_GET['key'] ?? '');
    if ($secret === '' || !hash_equals($secret, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function cron_finish(string $job, string $message, bool $ok = true): void
{
    $now = utc_now();
    db_insert('INSERT INTO cron_runs (job_name, started_at, finished_at, status, message) VALUES (?,?,?,?,?)', [
        $job, $now, $now, $ok ? 'success' : 'failed', clip($message, 500),
    ]);
    echo $job . ': ' . $message . PHP_EOL;
}
