<?php

declare(strict_types=1);

return [
    'name' => 'GMS',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', '0') === '1',
    'url' => rtrim((string) env('APP_URL', ''), '/'),
    'base' => rtrim((string) env('APP_BASE', ''), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Kolkata') ?: 'Asia/Kolkata',
    'key' => (string) env('APP_KEY', ''),
];
