<?php

declare(strict_types=1);

define('GMS_TESTING', true);
require dirname(__DIR__) . '/includes/functions.php';
$_ENV['APP_KEY'] = 'test-key-please-change';
$_ENV['APP_TIMEZONE'] = 'Asia/Kolkata';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    if ($ok) {
        echo "OK  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}\n";
}

check(money_add('10.10', '0.20') === '10.30', 'money add');
check(money_sub('10.00', '0.30') === '9.70', 'money sub');
check(money_percent('1500.00', '18.00') === '270.00', 'tax 18 percent of 1500');
check(money_percent('7200', '18') === '1296.00', 'tax half year');
check(money_percent('8000', '18') === '1440.00', 'tax PT');
$paid = invoice_status_from_amounts('1770.00', '1770.00');
check($paid['status'] === 'paid' && $paid['balance'] === '0.00', 'fully paid');
$partial = invoice_status_from_amounts('4720.00', '2000.00');
check($partial['status'] === 'partial' && $partial['balance'] === '2720.00', 'partial balance');
$unpaid = invoice_status_from_amounts('1770.00', '0');
check($unpaid['status'] === 'unpaid' && $unpaid['balance'] === '1770.00', 'unpaid');
$free = invoice_status_from_amounts('0', '0');
check($free['status'] === 'paid', 'zero total is settled');
$threw = false;
try { invoice_status_from_amounts('100', '150'); } catch (AppException) { $threw = true; }
check($threw, 'overpayment rejected');
check(payment_reminder_due(1, [1, 3, 7], 7) === true, 'day 1 reminder');
check(payment_reminder_due(2, [1, 3, 7], 7) === false, 'day 2 skipped');
check(payment_reminder_due(14, [1, 3, 7], 7) === true, 'repeat after max');
check(payment_reminder_due(0, [1, 3, 7], 7) === false, 'same-day invoice is quiet');
check(days_inclusive('2026-10-20', '2026-11-18') === 30, 'inclusive 30 days');
check(add_days('2026-01-15', 364) === '2027-01-14', 'annual end date');
check(normalize_phone('09800001001', '91') === '919800001001', 'phone normalized');
check(csv_safe('=cmd') === "'=cmd", 'csv formula guard');
$token = sign_token(['g' => 1, 'i' => 4], 60);
$decoded = verify_token($token);
check(is_array($decoded) && (int) $decoded['g'] === 1, 'signed token');
check(verify_token($token . 'x') === null, 'bad signature rejected');
$secret = secret_encrypt('meta-token');
check(secret_decrypt($secret) === 'meta-token', 'secret roundtrip');
check(secret_decrypt('nope') === '', 'bad secret is empty');

exit($fail === 0 ? 0 : 1);
