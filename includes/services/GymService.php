<?php

declare(strict_types=1);

class GymService
{
    public static function register(string $ownerName, string $email, string $password, string $gymName, string $phone = ''): void
    {
        $ownerName = clip($ownerName, 160);
        $gymName = clip($gymName, 160);
        $email = strtolower(trim($email));
        if ($ownerName === '' || $gymName === '' || !valid_email($email) || !valid_password($password)) {
            throw new AppException('Use your name, a valid email, a gym name, and a password with at least 8 characters including a letter and a number.');
        }
        if ($phone !== '' && !phone_is_valid($phone)) {
            throw new AppException('Enter a valid phone number.');
        }
        rate_limit('register:' . client_ip(), 5, 3600);
        if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new AppException('An account with that email already exists. Sign in instead.');
        }
        $now = utc_now();
        $ids = db_tx(function (PDO $pdo) use ($ownerName, $email, $password, $gymName, $phone, $now) {
            $slug = unique_slug($pdo, $gymName);
            $pdo->prepare('INSERT INTO gyms (name, slug, phone, currency_code, currency_symbol, timezone, invoice_prefix, invoice_next_number, member_prefix, member_next_number, invoice_footer, invoice_terms, onboarding_step, onboarding_completed, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1,?,1001,?,?,1,0,?,?)')
                ->execute([
                    $gymName,
                    $slug,
                    $phone !== '' ? clip($phone, 30) : null,
                    'INR',
                    '₹',
                    env('APP_TIMEZONE', 'Asia/Kolkata') ?: 'Asia/Kolkata',
                    'INV',
                    'MBR',
                    'Thank you for training with us.',
                    'Payments are collected manually at the gym. This document is a tax invoice when GST is enabled.',
                    $now,
                    $now,
                ]);
            $gymId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO users (gym_id, name, email, phone, password_hash, role, status, created_at, updated_at) VALUES (?,?,?,?,?,?,\'active\',?,?)')
                ->execute([$gymId, $ownerName, $email, $phone !== '' ? clip($phone, 30) : null, password_hash($password, PASSWORD_DEFAULT), 'owner', $now, $now]);
            $userId = (int) $pdo->lastInsertId();
            self::installDefaults($pdo, $gymId, $now);
            $pdo->prepare('INSERT INTO staff_profiles (gym_id, user_id, designation, salary, commission_percent, joining_date, created_at, updated_at) VALUES (?,?,?,?,0,?,?,?)')
                ->execute([$gymId, $userId, 'Founder', '0.00', substr($now, 0, 10), $now, $now]);
            AuditService::log($gymId, $userId, 'gym.registered', 'gym', $gymId, null, ['summary' => 'Opened ' . $gymName], $pdo);
            return ['user_id' => $userId];
        });
        $user = db_one('SELECT * FROM users WHERE id = ?', [$ids['user_id']]);
        login_user($user);
    }

    public static function installDefaults(PDO $pdo, int $gymId, ?string $now = null): void
    {
        $now = $now ?: utc_now();
        foreach (default_setting_map() as $key => $value) {
            setting_put($pdo, $gymId, $key, $value);
        }
        foreach (['Rent', 'Utilities', 'Equipment', 'Salaries', 'Marketing', 'Maintenance', 'Other'] as $category) {
            $pdo->prepare('INSERT INTO expense_categories (gym_id, name, created_at) VALUES (?,?,?)')->execute([$gymId, $category, $now]);
        }
        $pdo->prepare('INSERT INTO whatsapp_settings (gym_id, api_version, demo_mode, default_country_code, webhook_verify_token, created_at, updated_at) VALUES (?,\'v21.0\',1,\'91\',?,?,?)')
            ->execute([$gymId, bin2hex(random_bytes(12)), $now, $now]);
        foreach (default_template_map() as $key => [$label, $preview]) {
            $pdo->prepare('INSERT INTO whatsapp_templates (gym_id, template_key, language_code, body_preview, is_active, created_at, updated_at) VALUES (?,?,\'en\',?,1,?,?)')
                ->execute([$gymId, $key, $label . ' — ' . $preview, $now, $now]);
        }
    }

    public static function saveProfile(int $gymId, array $input, ?string $logo): void
    {
        $name = clip($input['name'] ?? '', 160);
        if ($name === '') {
            throw new AppException('Gym name is required.');
        }
        $phone = clip($input['phone'] ?? '', 30);
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && !valid_email($email)) {
            throw new AppException('Enter a valid gym email.');
        }
        if ($phone !== '' && !phone_is_valid($phone)) {
            throw new AppException('Enter a valid phone number.');
        }
        $tz = (string) ($input['timezone'] ?? 'Asia/Kolkata');
        try {
            new DateTimeZone($tz);
        } catch (Exception) {
            throw new AppException('Choose a valid timezone.');
        }
        db_tx(function (PDO $pdo) use ($gymId, $name, $phone, $email, $tz, $input, $logo) {
            $sql = 'UPDATE gyms SET name=?, phone=?, email=?, address_line=?, city=?, state=?, postal_code=?, country=?, timezone=?, updated_at=?';
            $params = [
                $name,
                $phone ?: null,
                $email ?: null,
                clip($input['address_line'] ?? '', 255) ?: null,
                clip($input['city'] ?? '', 100) ?: null,
                clip($input['state'] ?? '', 100) ?: null,
                clip($input['postal_code'] ?? '', 20) ?: null,
                clip($input['country'] ?? 'India', 80) ?: 'India',
                $tz,
                utc_now(),
            ];
            if ($logo) {
                $sql .= ', logo_path=?';
                $params[] = $logo;
            }
            $sql .= ' WHERE id=?';
            $params[] = $gymId;
            $pdo->prepare($sql)->execute($params);
        });
    }

    public static function saveTax(int $gymId, array $input): void
    {
        $enabled = !empty($input['tax_enabled']) ? 1 : 0;
        $percent = money_norm($input['tax_percent'] ?? '0');
        if (money_comp($percent, '0') < 0 || money_comp($percent, '100') > 0) {
            throw new AppException('Tax percent must be between 0 and 100.');
        }
        $gstin = strtoupper(clip($input['gstin'] ?? '', 20));
        db_exec('UPDATE gyms SET tax_enabled=?, tax_label=?, tax_percent=?, gstin=?, updated_at=? WHERE id=?', [
            $enabled,
            clip($input['tax_label'] ?? 'GST', 40) ?: 'GST',
            $percent,
            $gstin ?: null,
            utc_now(),
            $gymId,
        ]);
    }

    public static function saveInvoice(int $gymId, array $input): void
    {
        $prefix = strtoupper(clip($input['invoice_prefix'] ?? 'INV', 20));
        if (!preg_match('/^[A-Z0-9-]{2,12}$/', $prefix)) {
            throw new AppException('Invoice prefix should be 2 to 12 letters or numbers.');
        }
        $memberPrefix = strtoupper(clip($input['member_prefix'] ?? 'MBR', 12));
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $memberPrefix)) {
            throw new AppException('Member prefix should be 2 to 8 letters or numbers.');
        }
        db_exec('UPDATE gyms SET invoice_prefix=?, member_prefix=?, invoice_footer=?, invoice_terms=?, currency_code=?, currency_symbol=?, updated_at=? WHERE id=?', [
            $prefix,
            $memberPrefix,
            clip($input['invoice_footer'] ?? '', 2000),
            clip($input['invoice_terms'] ?? '', 2000),
            strtoupper(clip($input['currency_code'] ?? 'INR', 3)) ?: 'INR',
            clip($input['currency_symbol'] ?? '₹', 8) ?: '₹',
            utc_now(),
            $gymId,
        ]);
    }

    public static function saveWhatsapp(int $gymId, array $input): void
    {
        $version = trim((string) ($input['api_version'] ?? 'v21.0'));
        if (!preg_match('/^v\d+\.\d+$/', $version)) {
            throw new AppException('API version should look like v21.0.');
        }
        $current = WhatsAppService::settings($gymId);
        $token = trim((string) ($input['access_token'] ?? ''));
        $secret = trim((string) ($input['app_secret'] ?? ''));
        $tokenStored = $current['access_token_encrypted'] ?? null;
        $secretStored = $current['app_secret_encrypted'] ?? null;
        if ($token !== '') {
            $tokenStored = secret_encrypt($token);
        }
        if ($secret !== '') {
            $secretStored = secret_encrypt($secret);
        }
        db_exec('UPDATE whatsapp_settings SET phone_number_id=?, business_account_id=?, access_token_encrypted=?, app_secret_encrypted=?, api_version=?, demo_mode=?, default_country_code=?, webhook_verify_token=?, updated_at=? WHERE gym_id=?', [
            clip($input['phone_number_id'] ?? '', 40) ?: null,
            clip($input['business_account_id'] ?? '', 40) ?: null,
            $tokenStored,
            $secretStored,
            $version,
            !empty($input['demo_mode']) ? 1 : 0,
            preg_replace('/\D+/', '', (string) ($input['default_country_code'] ?? '91')) ?: '91',
            clip($input['webhook_verify_token'] ?? '', 80) ?: bin2hex(random_bytes(8)),
            utc_now(),
            $gymId,
        ]);
        if (!empty($input['templates']) && is_array($input['templates'])) {
            foreach ($input['templates'] as $key => $row) {
                if (!is_array($row) || !isset(default_template_map()[$key])) {
                    continue;
                }
                db_exec('UPDATE whatsapp_templates SET template_name=?, language_code=?, is_active=?, updated_at=? WHERE gym_id=? AND template_key=?', [
                    clip($row['template_name'] ?? '', 120) ?: null,
                    clip($row['language_code'] ?? 'en', 10) ?: 'en',
                    !empty($row['is_active']) ? 1 : 0,
                    utc_now(),
                    $gymId,
                    $key,
                ]);
            }
        }
    }

    public static function saveReminders(int $gymId, array $input): void
    {
        $expiry = parse_int_list((string) ($input['expiry_reminder_days'] ?? ''));
        $overdue = parse_int_list((string) ($input['payment_overdue_days'] ?? ''));
        if (!$expiry) {
            throw new AppException('Add at least one expiry reminder day, such as 7,3,0.');
        }
        $repeat = (int) ($input['payment_repeat_days'] ?? 7);
        if ($repeat < 0 || $repeat > 90) {
            throw new AppException('Repeat interval must be between 0 and 90 days.');
        }
        $freeze = (int) ($input['freeze_max_days'] ?? 60);
        if ($freeze < 0 || $freeze > 365) {
            throw new AppException('Freeze cap must be between 0 and 365 days.');
        }
        db_tx(function (PDO $pdo) use ($gymId, $expiry, $overdue, $repeat, $freeze, $input) {
            setting_put($pdo, $gymId, 'expiry_reminder_days', implode(',', $expiry));
            setting_put($pdo, $gymId, 'payment_overdue_days', implode(',', $overdue));
            setting_put($pdo, $gymId, 'payment_repeat_days', (string) $repeat);
            setting_put($pdo, $gymId, 'payment_reminder_enabled', !empty($input['payment_reminder_enabled']) ? '1' : '0');
            setting_put($pdo, $gymId, 'allow_expired_checkin_override', !empty($input['allow_expired_checkin_override']) ? '1' : '0');
            setting_put($pdo, $gymId, 'freeze_max_days', (string) $freeze);
        });
    }
}
