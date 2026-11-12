<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
function expect(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

$gym = db_one('SELECT * FROM gyms WHERE slug = ?', ['pulseforge-demo']);
expect($gym !== null, 'demo gym imported');
if (!$gym) {
    exit(1);
}
$gymId = (int) $gym['id'];
$owner = db_one('SELECT * FROM users WHERE email = ?', ['owner@pulseforge.demo']);
expect($owner && password_verify('Demo@12345', $owner['password_hash']), 'demo password verifies');
expect((int) $owner['gym_id'] === $gymId, 'owner belongs to the demo gym');

$range = ['today' => '2026-11-11', 'from' => '2026-11-01', 'to' => '2026-11-30'];
$dash = ReportService::dashboard($gymId, $range);
expect($dash['kpis']['total_members'] === 11, 'total members exclude archived');
expect($dash['kpis']['active_members'] === 9, 'nine active members');
expect($dash['kpis']['expired_members'] === 1, 'one expired member');
expect($dash['kpis']['new_members'] === 3, 'three members joined in November');
expect($dash['kpis']['today_attendance'] === 4, 'four check-ins on 11 Nov');
expect($dash['kpis']['revenue'] === '6770.00', 'November collections');
expect($dash['kpis']['pending'] === '15920.00', 'open balances');
expect($dash['kpis']['expiring'] === 2, 'two memberships inside seven days');

$secondEmail = 'owner+' . bin2hex(random_bytes(4)) . '@second.demo';
GymService::register('Tenant Two', $secondEmail, 'Second@12345', 'Northside Gym ' . bin2hex(random_bytes(2)), '9800000099');
$secondUser = db_one('SELECT * FROM users WHERE email = ?', [$secondEmail]);
$second = db_one('SELECT * FROM gyms WHERE id = ?', [(int) $secondUser['gym_id']]);
expect($second && (int) $second['id'] !== $gymId, 'second gym is isolated');
$leak = db_one('SELECT id FROM members WHERE gym_id = ?', [(int) $second['id']]);
expect($leak === null, 'second gym cannot see demo members');
$plans = db_all('SELECT id FROM membership_plans WHERE gym_id = ?', [(int) $second['id']]);
expect($plans === [], 'plans are not copied between gyms');

$memberId = db_insert('INSERT INTO members (gym_id, member_code, full_name, phone, email, gender, registration_date, status, whatsapp_opt_in, qr_token, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
    $second['id'], 'MBR-1001', 'Workflow Member', '9800001111', 'workflow@example.com', 'undisclosed', '2026-11-11', 'active', 1, bin2hex(random_bytes(24)), $secondUser['id'], utc_now(), utc_now(),
]);
db_exec('UPDATE gyms SET onboarding_completed = 1, tax_enabled = 1, tax_percent = 18, invoice_prefix = \'INV\', invoice_next_number = 1, updated_at = ? WHERE id = ?', [utc_now(), $second['id']]);
db_insert('INSERT INTO membership_plans (gym_id, name, duration_days, price, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)', [
    $second['id'], 'Monthly Plan', 30, '1500.00', 'active', utc_now(), utc_now(),
]);
$plan = db_one('SELECT id FROM membership_plans WHERE gym_id = ?', [(int) $second['id']]);
$assigned = MembershipService::assign((int) $second['id'], (int) $secondUser['id'], [
    'member_id' => $memberId,
    'plan_id' => $plan['id'],
    'start_date' => '2026-11-11',
    'change_type' => 'new',
    'discount' => '0',
    'payment_amount' => '500.00',
    'payment_method' => 'upi',
    'payment_date' => '2026-11-11',
    'payment_reference' => 'TEST-1',
    'send_whatsapp' => 1,
]);
expect($assigned['status'] === 'partial', 'partial payment sets invoice status');
expect($assigned['balance'] === '1270.00', 'balance is total minus payment');
expect(($assigned['whatsapp']['status'] ?? '') === 'demo', 'missing credentials stay in demo mode');
expect(empty($assigned['whatsapp']['wa_message_id']), 'demo mode does not invent a WhatsApp id');

$settled = PaymentService::record((int) $second['id'], (int) $secondUser['id'], (int) $assigned['invoice_id'], '1270.00', 'cash', '2026-11-11', null, 'Balance', false);
expect($settled['status'] === 'paid' && $settled['balance'] === '0.00', 'second payment settles the invoice');
$blocked = false;
try {
    PaymentService::record((int) $second['id'], (int) $secondUser['id'], (int) $assigned['invoice_id'], '10.00', 'cash', '2026-11-11', null, null, false);
} catch (AppException) {
    $blocked = true;
}
expect($blocked, 'settled invoice rejects another payment');

$cross = false;
try {
    PaymentService::record($gymId, (int) $owner['id'], (int) $assigned['invoice_id'], '10.00', 'cash', '2026-11-11', null, null, false);
} catch (AppException) {
    $cross = true;
}
expect($cross, 'another gym cannot pay this invoice');

$html = InvoiceService::html((int) $second['id'], (int) $assigned['invoice_id']);
expect(str_contains($html, 'INV-2026-0001') && str_contains($html, 'Workflow Member'), 'invoice HTML is generated');
if (class_exists(\Dompdf\Dompdf::class)) {
    $pdf = InvoiceService::generatePdf((int) $second['id'], (int) $assigned['invoice_id']);
    expect(is_file($pdf) && str_starts_with((string) file_get_contents($pdf), '%PDF'), 'PDF invoice is a real PDF');
} else {
    echo "SKIP PDF library is not installed\n";
}

$check = AttendanceService::checkIn((int) $second['id'], (int) $secondUser['id'], $memberId, 'manual', false, true);
expect(!empty($check['attendance_id']), 'manual check-in');
$again = false;
try {
    AttendanceService::checkIn((int) $second['id'], (int) $secondUser['id'], $memberId, 'manual', false, true);
} catch (AppException) {
    $again = true;
}
expect($again, 'open visit blocks a second check-in');

$today = '2026-11-11';
$first = ReminderService::paymentReminders((int) $second['id'], $today);
$secondRun = ReminderService::paymentReminders((int) $second['id'], $today);
expect($first['sent'] === 0 && $secondRun['sent'] === 0, 'same-day invoice is not reminded');
$later = ReminderService::paymentReminders((int) $second['id'], '2026-11-12');
$laterAgain = ReminderService::paymentReminders((int) $second['id'], '2026-11-12');
expect($later['sent'] === 0, 'paid invoice is not reminded');
expect($laterAgain['sent'] === 0, 'repeat does not send a paid reminder');

$ishaan = ReminderService::paymentReminders($gymId, '2026-11-11');
$ishaanAgain = ReminderService::paymentReminders($gymId, '2026-11-11');
expect($ishaan['sent'] >= 1, 'unpaid day-old invoice is reminded');
expect($ishaanAgain['sent'] === 0, 'the same reminder is not sent twice');

$expiry = ReminderService::membershipReminders($gymId, '2026-11-11');
$expiryAgain = ReminderService::membershipReminders($gymId, '2026-11-11');
expect($expiry['sent'] >= 1, 'seven-day expiry reminder is sent');
expect($expiryAgain['sent'] === 0, 'expiry reminder is not duplicated');

$demoLog = db_one("SELECT status, wa_message_id FROM whatsapp_message_logs WHERE gym_id = ? AND status = 'demo' ORDER BY id DESC LIMIT 1", [(int) $second['id']]);
expect($demoLog && $demoLog['wa_message_id'] === null, 'demo log is not marked sent');

echo $fail === 0 ? "WORKFLOW PASSED\n" : "WORKFLOW FAILED {$fail}\n";
exit($fail === 0 ? 0 : 1);
