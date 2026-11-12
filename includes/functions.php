<?php

declare(strict_types=1);

class AppException extends RuntimeException
{
}

function load_env(string $file): void
{
    if (is_file($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $eq));
                $value = trim(substr($line, $eq + 1));
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                $_ENV[$key] = $value;
            }
        }
    }

    $local = dirname(__DIR__) . '/config/config.local.php';
    if (is_file($local)) {
        $data = require $local;
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (!array_key_exists((string) $key, $_ENV)) {
                    $_ENV[(string) $key] = (string) $value;
                }
            }
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return (string) $value;
}

function app_debug(): bool
{
    return env('APP_DEBUG', '0') === '1';
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_log(string $level, string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = gmdate('c') . ' [' . strtoupper($level) . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents($dir . '/app.log', $line . PHP_EOL, FILE_APPEND);
}

function url(string $path = ''): string
{
    $base = rtrim((string) env('APP_BASE', ''), '/');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function app_key_material(): string
{
    $key = (string) env('APP_KEY', '');
    if ($key === '') {
        throw new AppException('APP_KEY is missing. Copy .env.example to .env and set a long random key.');
    }
    return $key;
}

function secret_encrypt(string $plain): string
{
    $key = hash('sha256', app_key_material(), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false || strlen($tag) !== 16) {
        throw new AppException('Could not protect that secret.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(?string $encoded): string
{
    if ($encoded === null || $encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = hash('sha256', app_key_material(), true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function sign_token(array $payload, int $ttl): string
{
    $payload['exp'] = time() + $ttl;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $body = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $body, app_key_material());
    return $body . '.' . $sig;
}

function verify_token(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$body, $sig] = $parts;
    $expected = hash_hmac('sha256', $body, app_key_material());
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $pad = strlen($body) % 4;
    $b64 = strtr($body, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : '');
    $json = base64_decode($b64, true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known = $_SESSION['_csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        throw new AppException('Your session expired. Refresh the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function remember_old(): void
{
    $old = $_POST;
    unset($old['password'], $old['password_confirm'], $old['_csrf'], $old['access_token'], $old['app_secret']);
    $_SESSION['_old'] = $old;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function old(string $key, string $default = ''): string
{
    if (!isset($_SESSION['_old']) || !is_array($_SESSION['_old'])) {
        return $default;
    }
    if (!array_key_exists($key, $_SESSION['_old'])) {
        return '';
    }
    $value = $_SESSION['_old'][$key];
    if (is_array($value)) {
        return $default;
    }
    return (string) $value;
}

function old_checked(string $key, bool $default): bool
{
    if (!isset($_SESSION['_old']) || !is_array($_SESSION['_old'])) {
        return $default;
    }
    return isset($_SESSION['_old'][$key]) && (string) $_SESSION['_old'][$key] !== '' && (string) $_SESSION['_old'][$key] !== '0';
}

function clip(?string $value, int $max): string
{
    $value = trim((string) $value);
    if (mb_strlen($value) > $max) {
        return mb_substr($value, 0, $max);
    }
    return $value;
}

function valid_date(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $value;
}

function valid_email(string $value): bool
{
    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

function valid_password(string $password): bool
{
    return strlen($password) >= 8 && preg_match('/[A-Za-z]/', $password) && preg_match('/\d/', $password);
}

function normalize_phone(string $phone, string $countryCode = '91'): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $digits = ltrim($digits, '0');
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) <= 10) {
        $digits = $countryCode . $digits;
    }
    return $digits;
}

function phone_is_valid(string $phone): bool
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return strlen($digits) >= 8 && strlen($digits) <= 15;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'gym';
}

function like_query(string $value): string
{
    return '%' . addcslashes($value, '%_\\') . '%';
}

function money_norm(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        $amount = '0';
    }
    $amount = str_replace([',', ' '], '', (string) $amount);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
        throw new AppException('Enter a valid amount.');
    }
    if (function_exists('bcadd')) {
        return bcadd($amount, '0', 2);
    }
    return number_format((float) $amount, 2, '.', '');
}

function money_round(string $amount): string
{
    $negative = str_starts_with($amount, '-');
    $amount = ltrim($amount, '+-');
    if (!function_exists('bcadd')) {
        $rounded = number_format(round((float) ($negative ? '-' : '') . $amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
        return $rounded;
    }
    $parts = explode('.', $amount, 2);
    $dec = substr(($parts[1] ?? '') . '0000', 0, 4);
    $base = $parts[0] . '.' . substr($dec, 0, 2);
    if ((int) $dec[2] >= 5) {
        $base = bcadd($base, '0.01', 2);
    } else {
        $base = bcadd($base, '0', 2);
    }
    if ($base === '-0.00') {
        $base = '0.00';
    }
    return $negative && $base !== '0.00' ? '-' . $base : $base;
}

function money_add(string $a, string $b): string
{
    $a = money_norm($a);
    $b = money_norm($b);
    if (function_exists('bcadd')) {
        return bcadd($a, $b, 2);
    }
    return number_format((float) $a + (float) $b, 2, '.', '');
}

function money_sub(string $a, string $b): string
{
    $a = money_norm($a);
    $b = money_norm($b);
    if (function_exists('bcsub')) {
        return bcsub($a, $b, 2);
    }
    return number_format((float) $a - (float) $b, 2, '.', '');
}

function money_comp(string $a, string $b): int
{
    $a = money_norm($a);
    $b = money_norm($b);
    if (function_exists('bccomp')) {
        return bccomp($a, $b, 2);
    }
    return ((float) $a <=> (float) $b);
}

function money_percent(string $amount, string $percent): string
{
    $amount = money_norm($amount);
    $percent = money_norm($percent);
    if (function_exists('bcmul')) {
        $raw = bcdiv(bcmul($amount, $percent, 6), '100', 6);
        return money_round($raw);
    }
    return number_format(((float) $amount * (float) $percent) / 100, 2, '.', '');
}

function money_ratio(string $amount, string $numerator, string $denominator): string
{
    $amount = money_norm($amount);
    if ((int) $denominator === 0) {
        return '0.00';
    }
    if (function_exists('bcmul')) {
        $raw = bcdiv(bcmul($amount, money_norm($numerator), 6), (string) (int) $denominator, 6);
        return money_round($raw);
    }
    return number_format(((float) $amount * (float) $numerator) / (float) $denominator, 2, '.', '');
}

function money_min(string $a, string $b): string
{
    return money_comp($a, $b) <= 0 ? money_norm($a) : money_norm($b);
}

function invoice_status_from_amounts(string $total, string $paid): array
{
    $total = money_norm($total);
    $paid = money_norm($paid);
    if (money_comp($paid, '0') < 0 || money_comp($total, '0') < 0) {
        throw new AppException('Amounts cannot be negative.');
    }
    if (money_comp($paid, $total) > 0) {
        throw new AppException('Paid amount cannot exceed the invoice total.');
    }
    if (money_comp($paid, $total) >= 0) {
        $status = 'paid';
    } elseif (money_comp($paid, '0') > 0) {
        $status = 'partial';
    } else {
        $status = 'unpaid';
    }
    return [
        'status' => $status,
        'paid' => $paid,
        'balance' => money_sub($total, $paid),
    ];
}

function payment_reminder_due(int $ageDays, array $offsets, int $repeat): bool
{
    if ($ageDays <= 0) {
        return false;
    }
    $offsets = array_values(array_unique(array_map('intval', $offsets)));
    if (in_array($ageDays, $offsets, true)) {
        return true;
    }
    if ($repeat <= 0 || $offsets === []) {
        return false;
    }
    $max = max($offsets);
    return $ageDays > $max && (($ageDays - $max) % $repeat) === 0;
}

function parse_int_list(string $value): array
{
    $out = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part === '' || !preg_match('/^\d{1,3}$/', $part)) {
            continue;
        }
        $out[] = (int) $part;
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function days_inclusive(string $start, string $end): int
{
    $a = new DateTimeImmutable($start);
    $b = new DateTimeImmutable($end);
    if ($b < $a) {
        return 0;
    }
    return (int) $a->diff($b)->days + 1;
}

function add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function gym_timezone(?array $gym = null): DateTimeZone
{
    $name = $gym['timezone'] ?? null;
    if (!$name && function_exists('current_gym')) {
        $current = current_gym();
        $name = is_array($current) ? ($current['timezone'] ?? null) : null;
    }
    $name = $name ?: (env('APP_TIMEZONE', 'Asia/Kolkata') ?: 'UTC');
    try {
        return new DateTimeZone($name);
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

function gym_now(?array $gym = null): DateTimeImmutable
{
    return new DateTimeImmutable('now', gym_timezone($gym));
}

function gym_today(?array $gym = null): string
{
    return gym_now($gym)->format('Y-m-d');
}

function gym_day_utc_bounds(string $date, ?array $gym = null): array
{
    $tz = gym_timezone($gym);
    $start = new DateTimeImmutable($date . ' 00:00:00', $tz);
    $end = $start->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [
        $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        $end->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($date, 0, 10));
    return $dt ? $dt->format('d M Y') : e($date);
}

function fmt_datetime(?string $utc, ?array $gym = null): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Exception) {
        return '—';
    }
    return $dt->setTimezone(gym_timezone($gym))->format('d M Y, h:i A');
}

function money(mixed $amount, ?array $gym = null): string
{
    if (!$gym && function_exists('current_gym')) {
        $gym = current_gym();
    }
    $symbol = is_array($gym) ? ($gym['currency_symbol'] ?? '₹') : '₹';
    $norm = '0.00';
    try {
        $norm = money_norm($amount);
    } catch (AppException) {
        $norm = '0.00';
    }
    $negative = str_starts_with($norm, '-');
    $abs = ltrim($norm, '-');
    return ($negative ? '-' : '') . $symbol . number_format((float) $abs, 2);
}

function status_label(string $status): string
{
    return match ($status) {
        'partial' => 'Partially Paid',
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'expired' => 'Expired',
        'frozen' => 'Frozen',
        'suspended' => 'Suspended',
        'archived' => 'Archived',
        'cancelled' => 'Cancelled',
        'upgraded' => 'Upgraded',
        'downgraded' => 'Downgraded',
        'bank_transfer' => 'Bank Transfer',
        'upi' => 'UPI',
        'cash' => 'Cash',
        'card' => 'Card',
        'other' => 'Other',
        'owner' => 'Gym Owner',
        'manager' => 'Manager',
        'receptionist' => 'Receptionist',
        'trainer' => 'Trainer',
        'demo' => 'Demo',
        'sent' => 'Accepted',
        'failed' => 'Failed',
        'queued' => 'Queued',
        'skipped' => 'Skipped',
        'recorded' => 'Recorded',
        'voided' => 'Voided',
        'female' => 'Female',
        'male' => 'Male',
        'undisclosed' => 'Undisclosed',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function status_tone(string $status): string
{
    return match ($status) {
        'active', 'paid', 'sent', 'recorded', 'success' => 'ok',
        'partial', 'frozen', 'suspended', 'demo', 'queued', 'warning' => 'warn',
        'expired', 'unpaid', 'failed', 'voided', 'archived', 'danger' => 'danger',
        'upgraded', 'downgraded', 'skipped', 'info' => 'info',
        default => 'muted',
    };
}

function badge(string $status): string
{
    return '<span class="badge badge-' . e(status_tone($status)) . '">' . e(status_label($status)) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        if (mb_strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : 'G';
}

function avatar_html(?string $path, string $name, string $class = ''): string
{
    $class = trim('avatar ' . $class);
    if ($path) {
        return '<img class="' . e($class) . '" src="' . e(url($path)) . '" alt="">';
    }
    return '<span class="' . e($class) . ' avatar-fallback">' . e(initials($name)) . '</span>';
}

function csv_safe(string $value): string
{
    if (preg_match('/^[=\+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function form_open(string $action = '', bool $files = false): string
{
    $enctype = $files ? ' enctype="multipart/form-data"' : '';
    $actionAttr = $action !== '' ? ' action="' . e($action) . '"' : '';
    return '<form method="post"' . $actionAttr . $enctype . '>' . csrf_field();
}

function selected($current, $value): string
{
    return (string) $current === (string) $value ? ' selected' : '';
}

function checked_attr(bool $on): string
{
    return $on ? ' checked' : '';
}

function store_upload(string $field, string $subdir, array $allowed, int $maxBytes): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $file = $_FILES[$field];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new AppException('The upload did not finish. Try a smaller file.');
    }
    if ((int) $file['size'] > $maxBytes) {
        throw new AppException('That file is larger than ' . (int) ($maxBytes / 1048576) . ' MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file((string) $file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new AppException('This file type is not allowed.');
    }
    if (str_starts_with($mime, 'image/') && @getimagesize((string) $file['tmp_name']) === false) {
        throw new AppException('The image could not be read.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dir = dirname(__DIR__) . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new AppException('The upload folder is not writable.');
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new AppException('The file could not be stored.');
    }
    @chmod($dest, 0644);
    return trim($subdir, '/') . '/' . $name;
}

function safe_unlink(?string $relative, string $root): void
{
    if (!$relative) {
        return;
    }
    $base = realpath(dirname(__DIR__) . '/' . trim($root, '/'));
    $full = realpath(dirname(__DIR__) . '/' . ltrim($relative, '/'));
    if (!$base || !$full) {
        return;
    }
    $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($full, $prefix) && is_file($full)) {
        @unlink($full);
    }
}

function amount_in_words(string $amount, string $currency = 'INR'): string
{
    $amount = money_norm($amount);
    $negative = str_starts_with($amount, '-');
    $amount = ltrim($amount, '-');
    [$rupees, $paise] = array_pad(explode('.', $amount), 2, '00');
    $words = trim(spell_integer((int) $rupees));
    $unit = $currency === 'INR' ? 'Rupees' : $currency;
    $text = ($words === '' ? 'Zero' : $words) . ' ' . $unit;
    if ((int) $paise > 0) {
        $text .= ' and ' . spell_integer((int) $paise) . ' Paise';
    }
    return ($negative ? 'Minus ' : '') . $text . ' Only';
}

function spell_integer(int $number): string
{
    if ($number === 0) {
        return 'Zero';
    }
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $chunks = [
        10000000 => 'Crore',
        100000 => 'Lakh',
        1000 => 'Thousand',
        100 => 'Hundred',
    ];
    $parts = [];
    foreach ($chunks as $value => $label) {
        if ($number >= $value) {
            $count = intdiv($number, $value);
            $number %= $value;
            $parts[] = trim(spell_integer($count) . ' ' . $label);
        }
    }
    if ($number >= 20) {
        $parts[] = $tens[intdiv($number, 10)] . ($number % 10 ? ' ' . $ones[$number % 10] : '');
    } elseif ($number > 0) {
        $parts[] = $ones[$number];
    }
    return trim(implode(' ', $parts));
}

function wa_param(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        $text = '-';
    }
    return mb_substr($text, 0, 900);
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handle_post(callable $action): void
{
    if (!is_post()) {
        return;
    }
    $back = $_POST['_back'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $back = is_string($back) ? $back : '/';
    if (!str_starts_with($back, '/')) {
        $back = '/' . $back;
    }
    if (!is_string($back) || !str_starts_with($back, '/') || str_starts_with($back, '//') || str_contains($back, '://')) {
        $back = '/';
    }
    try {
        csrf_verify();
        clear_old();
        $action();
    } catch (AppException $e) {
        remember_old();
        flash('error', $e->getMessage());
        header('Location: ' . $back);
        exit;
    } catch (Throwable $e) {
        app_log('error', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        remember_old();
        flash('error', app_debug() ? $e->getMessage() : 'Something went wrong. The details were written to the error log.');
        header('Location: ' . $back);
        exit;
    }
}

function image_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function receipt_mimes(): array
{
    return image_mimes() + ['application/pdf' => 'pdf'];
}

function default_setting_map(): array
{
    return [
        'expiry_reminder_days' => '7,3,0',
        'payment_reminder_enabled' => '1',
        'payment_overdue_days' => '1,3,7',
        'payment_repeat_days' => '7',
        'allow_expired_checkin_override' => '1',
        'freeze_max_days' => '60',
    ];
}

function default_template_map(): array
{
    return [
        'welcome' => ['Welcome message', 'Body: member name, gym name'],
        'expiry_reminder' => ['Membership expiry reminder', 'Body: member name, plan, expiry date, days remaining'],
        'payment_reminder' => ['Payment reminder', 'Body: member name, invoice number, balance, status'],
        'payment_confirmation' => ['Payment confirmation', 'Body: member name, amount, invoice number, balance'],
        'invoice_document' => ['Invoice PDF', 'Header document plus body: member name, invoice number, total'],
        'renewal_confirmation' => ['Renewal confirmation', 'Body: member name, plan, new expiry date'],
        'announcement' => ['Announcement', 'Body: member name, message'],
    ];
}

function setting(int $gymId, string $key, ?string $default = null): ?string
{
    $row = db_one('SELECT setting_value FROM gym_settings WHERE gym_id = ? AND setting_key = ?', [$gymId, $key]);
    if (!$row) {
        return $default;
    }
    return $row['setting_value'];
}

function setting_put(PDO $pdo, int $gymId, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO gym_settings (gym_id, setting_key, setting_value, updated_at) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)')
        ->execute([$gymId, $key, $value, utc_now()]);
}

function resolve_range(?array $gym = null): array
{
    $today = gym_today($gym);
    $preset = (string) ($_GET['preset'] ?? 'month');
    $start = new DateTimeImmutable($today);
    if ($preset === 'last_month') {
        $from = $start->modify('first day of last month')->format('Y-m-d');
        $to = $start->modify('last day of last month')->format('Y-m-d');
    } elseif ($preset === '30d') {
        $from = $start->modify('-29 days')->format('Y-m-d');
        $to = $today;
    } elseif ($preset === 'year') {
        $from = $start->format('Y-01-01');
        $to = $today;
    } elseif ($preset === 'custom') {
        $from = valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : $start->format('Y-m-01');
        $to = valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
    } else {
        $preset = 'month';
        $from = $start->format('Y-m-01');
        $to = $today;
    }
    return ['preset' => $preset, 'from' => $from, 'to' => $to, 'today' => $today];
}

function range_label(array $range): string
{
    return fmt_date($range['from']) . ' – ' . fmt_date($range['to']);
}

function fill_dates(string $from, string $to, array $values): array
{
    $labels = [];
    $data = [];
    $cursor = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    $span = (int) $cursor->diff($end)->days;
    if ($span > 62) {
        $bucket = [];
        foreach ($values as $day => $value) {
            $month = substr((string) $day, 0, 7);
            $bucket[$month] = money_add($bucket[$month] ?? '0', (string) $value);
        }
        $cursor = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $endMonth = new DateTimeImmutable(substr($to, 0, 7) . '-01');
        while ($cursor <= $endMonth) {
            $key = $cursor->format('Y-m');
            $labels[] = $cursor->format('M Y');
            $data[] = (float) ($bucket[$key] ?? 0);
            $cursor = $cursor->modify('+1 month');
        }
        return [$labels, $data];
    }
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $labels[] = $cursor->format('d M');
        $data[] = (float) ($values[$key] ?? 0);
        $cursor = $cursor->modify('+1 day');
    }
    return [$labels, $data];
}
