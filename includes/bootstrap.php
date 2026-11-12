<?php

declare(strict_types=1);

define('GMS_ROOT', dirname(__DIR__));

require_once GMS_ROOT . '/includes/functions.php';
load_env(GMS_ROOT . '/.env');

$autoload = GMS_ROOT . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once GMS_ROOT . '/includes/db.php';
require_once GMS_ROOT . '/includes/auth.php';
require_once GMS_ROOT . '/includes/permissions.php';
require_once GMS_ROOT . '/includes/mailer.php';
require_once GMS_ROOT . '/includes/services/AuditService.php';
require_once GMS_ROOT . '/includes/services/NotificationService.php';
require_once GMS_ROOT . '/includes/services/InvoiceService.php';
require_once GMS_ROOT . '/includes/services/WhatsAppService.php';
require_once GMS_ROOT . '/includes/services/PaymentService.php';
require_once GMS_ROOT . '/includes/services/MembershipService.php';
require_once GMS_ROOT . '/includes/services/AttendanceService.php';
require_once GMS_ROOT . '/includes/services/ReminderService.php';
require_once GMS_ROOT . '/includes/services/ReportService.php';
require_once GMS_ROOT . '/includes/services/GymService.php';
require_once GMS_ROOT . '/includes/services/StaffService.php';
require_once GMS_ROOT . '/includes/services/AssistantService.php';
require_once GMS_ROOT . '/includes/layout.php';

if (PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    session_name('GMSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (env('APP_BASE', '') ?: '/'),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self' https://cdn.jsdelivr.net https://unpkg.com; connect-src 'self'; frame-ancestors 'self'");
    if (!defined('GMS_TESTING')) {
        set_exception_handler(static function (Throwable $e): void {
            app_log('error', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                exit(1);
            }
            $message = $e instanceof AppException || app_debug()
                ? $e->getMessage()
                : 'Something went wrong. Check storage/logs/app.log.';
            http_response_code(500);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GMS</title><link rel="stylesheet" href="' . e(url('assets/css/app.css')) . '"></head><body class="auth-body"><main class="auth-card"><p class="eyebrow">GMS</p><h1>We hit a problem</h1><p>' . e($message) . '</p><p><a class="btn btn-primary" href="' . e(url('login')) . '">Back to sign in</a></p></main></body></html>';
            exit;
        });
    }
}

function boot_page(string $permission = ''): array
{
    require_onboarded();
    if ($permission !== '') {
        require_permission($permission);
    }
    return [current_user(), current_gym()];
}
