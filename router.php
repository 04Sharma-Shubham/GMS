<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

$routes = [
    '#^/login/?$#' => 'login.php',
    '#^/register/?$#' => 'register.php',
    '#^/logout/?$#' => 'logout.php',
    '#^/forgot/?$#' => 'forgot.php',
    '#^/reset/?$#' => 'reset.php',
    '#^/onboarding/?$#' => 'onboarding.php',
    '#^/dashboard/?$#' => 'index.php',
    '#^/assistant/?$#' => 'modules/assistant/index.php',
    '#^/$#' => 'index.php',
    '#^/members/?$#' => 'modules/members/index.php',
    '#^/memberships/?$#' => 'modules/memberships/index.php',
    '#^/payments/?$#' => 'modules/payments/index.php',
    '#^/invoices/?$#' => 'modules/invoices/index.php',
    '#^/attendance/?$#' => 'modules/attendance/index.php',
    '#^/attendance/kiosk/?$#' => 'modules/attendance/kiosk.php',
    '#^/trainers/?$#' => 'modules/trainers/index.php',
    '#^/expenses/?$#' => 'modules/expenses/index.php',
    '#^/reports/?$#' => 'modules/reports/index.php',
    '#^/reports/export/?$#' => 'modules/reports/export.php',
    '#^/notifications/?$#' => 'modules/notifications/index.php',
    '#^/settings/?$#' => 'modules/settings/index.php',
    '#^/automation/?$#' => 'modules/automation/index.php',
    '#^/workouts/?$#' => 'modules/workouts/index.php',
    '#^/media/invoice/?$#' => 'media/invoice.php',
];
foreach ($routes as $pattern => $script) {
    if (preg_match($pattern, $uri)) {
        require __DIR__ . '/' . $script;
        return true;
    }
}
if (preg_match('#^/members/new/?$#', $uri)) {
    $_GET['action'] = 'new';
    require __DIR__ . '/modules/members/index.php';
    return true;
}
if (preg_match('#^/members/(\d+)/edit/?$#', $uri, $m)) {
    $_GET['action'] = 'edit';
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/members/index.php';
    return true;
}
if (preg_match('#^/members/(\d+)/qr\.svg$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/members/qr.php';
    return true;
}
if (preg_match('#^/members/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/members/view.php';
    return true;
}
if (preg_match('#^/invoices/(\d+)/pdf/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/invoices/download.php';
    return true;
}
if (preg_match('#^/invoices/(\d+)/print/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/invoices/print.php';
    return true;
}
if (preg_match('#^/invoices/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/invoices/view.php';
    return true;
}
if (preg_match('#^/trainers/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/trainers/view.php';
    return true;
}
if (preg_match('#^/expenses/receipt/(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/modules/expenses/receipt.php';
    return true;
}
if (preg_match('#^/api/([A-Za-z0-9_]+)/?$#', $uri, $m) && is_file(__DIR__ . '/api/' . $m[1] . '.php')) {
    require __DIR__ . '/api/' . $m[1] . '.php';
    return true;
}
if (preg_match('#^/cron/([A-Za-z0-9_]+)\.php$#', $uri, $m) && is_file(__DIR__ . '/cron/' . $m[1] . '.php')) {
    require __DIR__ . '/cron/' . $m[1] . '.php';
    return true;
}
http_response_code(404);
require __DIR__ . '/404.php';
return true;
