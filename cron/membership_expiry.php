<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    $expired = 0;
    foreach (db_all("SELECT * FROM gyms WHERE status = 'active'") as $gym) {
        $result = MembershipService::processExpiry((int) $gym['id'], gym_today($gym));
        $expired += $result['expired'];
    }
    cron_finish('membership_expiry', "Expired subscriptions: {$expired}.");
} catch (Throwable $e) {
    app_log('error', $e->getMessage());
    cron_finish('membership_expiry', $e->getMessage(), false);
    exit(1);
}
