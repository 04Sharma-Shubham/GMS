<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    $sent = 0;
    $skipped = 0;
    foreach (db_all("SELECT * FROM gyms WHERE status = 'active'") as $gym) {
        $result = ReminderService::membershipReminders((int) $gym['id'], gym_today($gym));
        $sent += $result['sent'];
        $skipped += $result['skipped'];
    }
    cron_finish('membership_reminders', "Processed reminders. Attempts: {$sent}. Skipped: {$skipped}.");
} catch (Throwable $e) {
    app_log('error', $e->getMessage());
    cron_finish('membership_reminders', $e->getMessage(), false);
    exit(1);
}
