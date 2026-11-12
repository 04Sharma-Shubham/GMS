<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('nav.settings');
$gymId = (int) $gym['id'];
$tab = (string) ($_GET['tab'] ?? 'gym');
$allowed = ['gym', 'invoice', 'whatsapp', 'reminders', 'account', 'logs', 'export'];
if (!in_array($tab, $allowed, true)) $tab = 'gym';

handle_post(function () use ($gymId, $user, $gym) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'gym') {
        require_permission('settings.gym');
        $logo = store_upload('logo', 'assets/uploads/logos', image_mimes(), 2_000_000);
        GymService::saveProfile($gymId, $_POST, $logo);
        flash('success', 'Gym profile saved.');
    } elseif ($action === 'tax' || $action === 'invoice') {
        require_permission('settings.gym');
        if ($action === 'tax') GymService::saveTax($gymId, $_POST);
        else GymService::saveInvoice($gymId, $_POST);
        flash('success', 'Billing settings saved.');
    } elseif ($action === 'whatsapp') {
        require_permission('whatsapp.configure');
        GymService::saveWhatsapp($gymId, $_POST);
        flash('success', 'WhatsApp settings saved. Tokens stay on the server.');
    } elseif ($action === 'test_whatsapp') {
        require_permission('whatsapp.configure');
        $phone = (string) ($_POST['test_phone'] ?? '');
        if (!phone_is_valid($phone)) throw new AppException('Enter a phone number for the test.');
        $result = WhatsAppService::send($gymId, null, $phone, 'announcement', ['there', 'This is a GMS configuration test from ' . $gym['name'] . '.']);
        $_SESSION['wa_result'] = $result;
        flash($result['status'] === 'failed' ? 'error' : 'success', $result['status'] === 'sent' ? 'WhatsApp accepted the test.' : ($result['status'] === 'demo' ? 'Demo mode created a manual link. Nothing was delivered automatically.' : (string) $result['error']));
    } elseif ($action === 'reminders') {
        require_permission('settings.gym');
        GymService::saveReminders($gymId, $_POST);
        flash('success', 'Reminder schedule saved.');
    } elseif ($action === 'account') {
        $name = clip($_POST['name'] ?? '', 160);
        $phone = clip($_POST['phone'] ?? '', 30);
        if ($name === '') throw new AppException('Name is required.');
        db_exec('UPDATE users SET name=?, phone=?, updated_at=? WHERE id=?', [$name, $phone ?: null, utc_now(), $user['id']]);
        if (!empty($_POST['password'])) {
            $current = (string) ($_POST['current_password'] ?? '');
            if (!password_verify($current, $user['password_hash']) || !valid_password((string) $_POST['password'])) {
                throw new AppException('Current password is wrong, or the new one is too weak.');
            }
            db_exec('UPDATE users SET password_hash=?, updated_at=? WHERE id=?', [password_hash((string) $_POST['password'], PASSWORD_DEFAULT), utc_now(), $user['id']]);
        }
        flash('success', 'Account updated.');
    } else {
        throw new AppException('Unknown settings action.');
    }
    redirect_to('settings?tab=' . ($_POST['_tab'] ?? 'gym'));
});

$gym = current_gym();
$settings = [];
foreach (db_all('SELECT setting_key, setting_value FROM gym_settings WHERE gym_id = ?', [$gymId]) as $row) $settings[$row['setting_key']] = $row['setting_value'];
$wa = db_one('SELECT * FROM whatsapp_settings WHERE gym_id = ?', [$gymId]) ?: [];
$templates = db_all('SELECT * FROM whatsapp_templates WHERE gym_id = ? ORDER BY template_key', [$gymId]);
$waResult = $_SESSION['wa_result'] ?? null;
unset($_SESSION['wa_result']);
layout_start('Settings', 'settings');
page_head('Control room', 'Settings', 'Changes apply only to ' . $gym['name'] . '.');
echo '<nav class="tabs">';
foreach (['gym' => 'Gym', 'invoice' => 'Invoices & tax', 'whatsapp' => 'WhatsApp', 'reminders' => 'Reminders', 'account' => 'Account', 'logs' => 'Activity', 'export' => 'Export'] as $key => $label) {
    echo '<a class="' . ($tab === $key ? 'is-active' : '') . '" href="' . e(url('settings?tab=' . $key)) . '">' . e($label) . '</a>';
}
echo '</nav>';
if (is_array($waResult)) {
    echo '<div class="flash flash-info"><a target="_blank" rel="noopener" href="' . e($waResult['click_to_chat']) . '">Open WhatsApp chat (manual send)</a></div>';
}

if ($tab === 'gym') {
    if (!can('settings.gym')) { echo '<p>Only the gym owner can edit these details.</p>'; }
    else {
        echo form_open(url('settings?tab=gym'), true);
        echo '<input type="hidden" name="action" value="gym"><input type="hidden" name="_tab" value="gym"><input type="hidden" name="_back" value="' . e(url('settings?tab=gym')) . '"><div class="card form-grid">';
        foreach (['name' => 'Gym name', 'phone' => 'Phone', 'email' => 'Email', 'address_line' => 'Address', 'city' => 'City', 'state' => 'State', 'postal_code' => 'Postal code', 'country' => 'Country', 'timezone' => 'Timezone'] as $key => $label) {
            echo '<label class="field"><span>' . e($label) . '</span><input name="' . $key . '" value="' . e((string) ($gym[$key] ?? '')) . '"></label>';
        }
        echo '<label class="field"><span>Replace logo</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>';
        echo '<div class="full"><button class="btn btn-primary">Save gym</button> <a href="' . e(url('trainers')) . '">Manage staff</a></div></div></form>';
    }
}

if ($tab === 'invoice' && can('settings.gym')) {
    echo '<section class="split"><article class="card">';
    echo form_open();
    echo '<input type="hidden" name="action" value="tax"><input type="hidden" name="_tab" value="invoice"><input type="hidden" name="_back" value="' . e(url('settings?tab=invoice')) . '"><h2>Tax</h2><label class="check"><input type="checkbox" name="tax_enabled" value="1"' . checked_attr((bool) $gym['tax_enabled']) . '> Enable tax</label><div class="form-grid">';
    echo '<label class="field"><span>Label</span><input name="tax_label" value="' . e($gym['tax_label']) . '"></label><label class="field"><span>Percent</span><input name="tax_percent" value="' . e((string) $gym['tax_percent']) . '"></label><label class="field full"><span>GSTIN</span><input name="gstin" value="' . e((string) $gym['gstin']) . '"></label></div><button class="btn btn-primary">Save tax</button></form></article>';
    echo '<article class="card">';
    echo form_open();
    echo '<input type="hidden" name="action" value="invoice"><input type="hidden" name="_tab" value="invoice"><input type="hidden" name="_back" value="' . e(url('settings?tab=invoice')) . '"><h2>Invoice identity</h2><div class="form-grid">';
    foreach (['invoice_prefix' => 'Prefix', 'member_prefix' => 'Member prefix', 'currency_code' => 'Currency', 'currency_symbol' => 'Symbol'] as $key => $label) echo '<label class="field"><span>' . $label . '</span><input name="' . $key . '" value="' . e((string) $gym[$key]) . '"></label>';
    echo '<label class="field full"><span>Footer</span><textarea name="invoice_footer">' . e((string) $gym['invoice_footer']) . '</textarea></label><label class="field full"><span>Terms</span><textarea name="invoice_terms">' . e((string) $gym['invoice_terms']) . '</textarea></label></div><button class="btn btn-primary">Save invoice settings</button></form></article></section>';
}

if ($tab === 'whatsapp') {
    if (!can('whatsapp.configure')) echo '<p>Only the owner can store WhatsApp credentials.</p>';
    else {
        $configured = WhatsAppService::isConfigured($wa);
        echo '<p class="lede">' . ($configured ? 'Cloud API credentials are saved. Messages are marked sent only after Meta accepts them.' : 'Demo mode is active. The app will prepare click-to-chat links and will not claim automatic delivery.') . '</p>';
        echo form_open();
        echo '<input type="hidden" name="action" value="whatsapp"><input type="hidden" name="_tab" value="whatsapp"><input type="hidden" name="_back" value="' . e(url('settings?tab=whatsapp')) . '"><div class="card form-grid">';
        echo '<label class="check"><input type="checkbox" name="demo_mode" value="1"' . checked_attr((bool) ($wa['demo_mode'] ?? 1)) . '> Demo mode</label>';
        echo '<label class="field"><span>Phone number ID</span><input name="phone_number_id" value="' . e((string) ($wa['phone_number_id'] ?? '')) . '"></label>';
        echo '<label class="field"><span>Business account ID</span><input name="business_account_id" value="' . e((string) ($wa['business_account_id'] ?? '')) . '"></label>';
        echo '<label class="field"><span>API version</span><input name="api_version" value="' . e((string) ($wa['api_version'] ?? 'v21.0')) . '"></label>';
        echo '<label class="field"><span>Country code</span><input name="default_country_code" value="' . e((string) ($wa['default_country_code'] ?? '91')) . '"></label>';
        echo '<label class="field"><span>Access token</span><input type="password" name="access_token" placeholder="' . (($wa['access_token_encrypted'] ?? '') ? 'Saved. Leave blank to keep it.' : 'Paste token') . '" autocomplete="off"></label>';
        echo '<label class="field"><span>App secret</span><input type="password" name="app_secret" placeholder="' . (($wa['app_secret_encrypted'] ?? '') ? 'Saved' : 'Optional, for webhook signatures') . '" autocomplete="off"></label>';
        echo '<label class="field full"><span>Webhook verify token</span><input name="webhook_verify_token" value="' . e((string) ($wa['webhook_verify_token'] ?? '')) . '"></label>';
        echo '<p class="full muted">Webhook URL: ' . e(rtrim((string) env('APP_URL', ''), '/') . url('api/whatsapp_webhook')) . '</p>';
        foreach ($templates as $template) {
            $key = $template['template_key'];
            echo '<label class="field"><span>' . e($key) . ' template name</span><input name="templates[' . e($key) . '][template_name]" value="' . e((string) $template['template_name']) . '"></label>';
            echo '<label class="field"><span>Language</span><input name="templates[' . e($key) . '][language_code]" value="' . e($template['language_code']) . '"></label>';
            echo '<input type="hidden" name="templates[' . e($key) . '][is_active]" value="0">';
            echo '<label class="check"><input type="checkbox" name="templates[' . e($key) . '][is_active]" value="1"' . checked_attr((bool) $template['is_active']) . '> Active · ' . e((string) $template['body_preview']) . '</label>';
        }
        echo '<div class="full"><button class="btn btn-primary">Save WhatsApp</button></div></div></form>';
        echo form_open();
        echo '<input type="hidden" name="action" value="test_whatsapp"><input type="hidden" name="_tab" value="whatsapp"><input type="hidden" name="_back" value="' . e(url('settings?tab=whatsapp')) . '"><div class="cluster" style="margin-top:12px"><input name="test_phone" placeholder="Test phone"><button class="btn btn-ghost">Send test</button></div></form>';
    }
}

if ($tab === 'reminders' && can('settings.gym')) {
    echo form_open();
    echo '<input type="hidden" name="action" value="reminders"><input type="hidden" name="_tab" value="reminders"><input type="hidden" name="_back" value="' . e(url('settings?tab=reminders')) . '"><div class="card form-grid">';
    echo '<label class="field"><span>Expiry days</span><input name="expiry_reminder_days" value="' . e($settings['expiry_reminder_days'] ?? '7,3,0') . '"></label>';
    echo '<label class="field"><span>Payment ages</span><input name="payment_overdue_days" value="' . e($settings['payment_overdue_days'] ?? '1,3,7') . '"></label>';
    echo '<label class="field"><span>Repeat every N days after the last age</span><input name="payment_repeat_days" value="' . e($settings['payment_repeat_days'] ?? '7') . '"></label>';
    echo '<label class="field"><span>Max freeze days</span><input name="freeze_max_days" value="' . e($settings['freeze_max_days'] ?? '60') . '"></label>';
    echo '<label class="check"><input type="checkbox" name="payment_reminder_enabled" value="1"' . checked_attr(($settings['payment_reminder_enabled'] ?? '1') === '1') . '> Payment reminders</label>';
    echo '<label class="check"><input type="checkbox" name="allow_expired_checkin_override" value="1"' . checked_attr(($settings['allow_expired_checkin_override'] ?? '1') === '1') . '> Allow expired check-in override</label>';
    echo '<p class="full muted">A reminder is stored before it is sent, so the same person is not nudged twice on the same day. Hostinger cron commands are on the Automation page.</p>';
    echo '<div class="full"><button class="btn btn-primary">Save reminders</button></div></div></form>';
}

if ($tab === 'account') {
    echo form_open();
    echo '<input type="hidden" name="action" value="account"><input type="hidden" name="_tab" value="account"><input type="hidden" name="_back" value="' . e(url('settings?tab=account')) . '"><div class="card form-grid">';
    echo '<label class="field"><span>Name</span><input name="name" value="' . e($user['name']) . '"></label><label class="field"><span>Phone</span><input name="phone" value="' . e((string) $user['phone']) . '"></label>';
    echo '<label class="field"><span>Current password</span><input type="password" name="current_password"></label><label class="field"><span>New password</span><input type="password" name="password"></label>';
    echo '<div class="full"><button class="btn btn-primary">Save account</button></div></div></form>';
}

if ($tab === 'logs') {
    if (!can('reports.view')) echo '<p>Activity logs are available to owners and managers.</p>';
    else {
        $logs = db_all('SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.gym_id = ? ORDER BY a.id DESC LIMIT 80', [$gymId]);
        echo '<article class="card">';
        foreach ($logs as $log) {
            $payload = json_decode((string) $log['new_values'], true) ?: [];
            echo '<p><strong>' . e($log['name'] ?: 'System') . '</strong> ' . e($payload['summary'] ?? $log['action']) . '<br><small class="muted">' . e(fmt_datetime($log['created_at'], $gym)) . ' · ' . e((string) $log['ip_address']) . '</small></p>';
        }
        echo '</article>';
    }
}

if ($tab === 'export') {
    if (!can('reports.export')) echo '<p>Exports are limited to owners and managers.</p>';
    else {
        echo '<div class="cluster">';
        foreach (['members' => 'Members', 'payments' => 'Payments', 'invoices' => 'Invoices', 'attendance' => 'Attendance', 'expenses' => 'Expenses'] as $type => $label) {
            echo '<a class="btn btn-ghost" href="' . e(url('reports/export?type=' . $type)) . '">' . e($label) . ' CSV</a>';
        }
        echo '</div>';
    }
}
layout_end();
