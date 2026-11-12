<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    $sent = 0;
    foreach (db_all("SELECT * FROM gyms WHERE status = 'active'") as $gym) {
        $result = ReminderService::paymentReminders((int) $gym['id'], gym_today($gym));
        $sent += $result['sent'];
    }
    cron_finish('payment_reminders', "Payment reminders attempted: {$sent}.");
} catch (Throwable $e) {
    app_log('error', $e->getMessage());
    cron_finish('payment_reminders', $e->getMessage(), false);
    exit(1);
}
