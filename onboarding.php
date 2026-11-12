<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_login();
$gym = current_gym();
$user = current_user();
if ($gym && (int) $gym['onboarding_completed']) {
    redirect_to('dashboard');
}
$step = max(1, min(6, (int) ($gym['onboarding_step'] ?? 1)));

if (is_post()) {
    try {
        csrf_verify();
        $posted = (int) ($_POST['step'] ?? $step);
        if ($posted === 1) {
            $name = clip($_POST['name'] ?? '', 160);
            if ($name === '') {
                throw new AppException('Gym name is required.');
            }
            $logo = store_upload('logo', 'assets/uploads/logos', image_mimes(), 2_000_000);
            if ($logo) {
                db_exec('UPDATE gyms SET name=?, logo_path=?, updated_at=? WHERE id=?', [$name, $logo, utc_now(), $gym['id']]);
            } else {
                db_exec('UPDATE gyms SET name=?, updated_at=? WHERE id=?', [$name, utc_now(), $gym['id']]);
            }
        } elseif ($posted === 2) {
            GymService::saveProfile((int) $gym['id'], [
                'name' => $gym['name'],
                'phone' => $_POST['phone'] ?? '',
                'email' => $_POST['email'] ?? '',
                'address_line' => $_POST['address_line'] ?? '',
                'city' => $_POST['city'] ?? '',
                'state' => $_POST['state'] ?? '',
                'postal_code' => $_POST['postal_code'] ?? '',
                'country' => $_POST['country'] ?? 'India',
                'timezone' => $_POST['timezone'] ?? $gym['timezone'],
            ], null);
        } elseif ($posted === 3) {
            GymService::saveTax((int) $gym['id'], $_POST);
        } elseif ($posted === 4) {
            GymService::saveInvoice((int) $gym['id'], $_POST);
        } elseif ($posted === 5) {
            GymService::saveWhatsapp((int) $gym['id'], $_POST);
        } elseif ($posted === 6) {
            $name = clip($_POST['plan_name'] ?? '', 120);
            $days = (int) ($_POST['duration_days'] ?? 0);
            $price = money_norm($_POST['price'] ?? '0');
            if ($name === '' || $days < 1 || money_comp($price, '0') < 0) {
                throw new AppException('Plan name, duration, and price are required.');
            }
            $now = utc_now();
            db_insert('INSERT INTO membership_plans (gym_id, name, duration_days, price, description, benefits, status, created_at, updated_at) VALUES (?,?,?,?,?,?,\'active\',?,?)', [
                $gym['id'], $name, $days, $price, clip($_POST['description'] ?? '', 1000), clip($_POST['benefits'] ?? '', 2000), $now, $now,
            ]);
            db_exec('UPDATE gyms SET onboarding_completed = 1, onboarding_step = 6, updated_at = ? WHERE id = ?', [$now, $gym['id']]);
            flash('success', 'Your gym is ready.');
            redirect_to('dashboard');
        }
        db_exec('UPDATE gyms SET onboarding_step = ?, updated_at = ? WHERE id = ?', [min(6, $posted + 1), utc_now(), $gym['id']]);
        flash('success', 'Saved.');
        redirect_to('onboarding');
    } catch (AppException $e) {
        flash('error', $e->getMessage());
        redirect_to('onboarding');
    }
}

$gym = current_gym();
$step = max(1, min(6, (int) $gym['onboarding_step']));
$labels = ['Gym identity', 'Address', 'Tax', 'Invoices', 'WhatsApp', 'First plan'];
guest_start('Set up your gym');
?>
<section class="auth-card" style="grid-column:1 / -1">
    <p class="eyebrow">Setup <?= (int) $step ?> of 6</p>
    <h1><?= e($labels[$step - 1]) ?></h1>
    <div class="steps" style="flex-direction:row;flex-wrap:wrap;margin:18px 0">
        <?php foreach ($labels as $i => $label): ?>
            <span class="<?= $i + 1 === $step ? 'is-on' : '' ?>"><?= $i + 1 ?>. <?= e($label) ?></span>
        <?php endforeach; ?>
    </div>
    <?= form_open('', $step === 1) ?>
        <input type="hidden" name="step" value="<?= (int) $step ?>">
        <?php if ($step === 1): ?>
            <div class="form-grid">
                <label class="field"><span>Gym name</span><input name="name" required value="<?= e($gym['name']) ?>"></label>
                <label class="field"><span>Logo</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
            </div>
        <?php elseif ($step === 2): ?>
            <div class="form-grid">
                <label class="field"><span>Phone</span><input name="phone" value="<?= e((string) $gym['phone']) ?>"></label>
                <label class="field"><span>Email</span><input type="email" name="email" value="<?= e((string) $gym['email']) ?>"></label>
                <label class="field full"><span>Address</span><input name="address_line" value="<?= e((string) $gym['address_line']) ?>"></label>
                <label class="field"><span>City</span><input name="city" value="<?= e((string) $gym['city']) ?>"></label>
                <label class="field"><span>State</span><input name="state" value="<?= e((string) $gym['state']) ?>"></label>
                <label class="field"><span>Postal code</span><input name="postal_code" value="<?= e((string) $gym['postal_code']) ?>"></label>
                <label class="field"><span>Country</span><input name="country" value="<?= e($gym['country'] ?: 'India') ?>"></label>
                <label class="field full"><span>Timezone</span><input name="timezone" value="<?= e($gym['timezone']) ?>"></label>
            </div>
        <?php elseif ($step === 3): ?>
            <label class="check"><input type="checkbox" name="tax_enabled" value="1"> Charge tax on memberships</label>
            <div class="form-grid" style="margin-top:12px">
                <label class="field"><span>Tax label</span><input name="tax_label" value="GST"></label>
                <label class="field"><span>Tax percent</span><input name="tax_percent" value="18.00"></label>
                <label class="field full"><span>GSTIN</span><input name="gstin" placeholder="Optional"></label>
            </div>
        <?php elseif ($step === 4): ?>
            <div class="form-grid">
                <label class="field"><span>Invoice prefix</span><input name="invoice_prefix" value="INV" required></label>
                <label class="field"><span>Member prefix</span><input name="member_prefix" value="MBR" required></label>
                <label class="field"><span>Currency code</span><input name="currency_code" value="INR"></label>
                <label class="field"><span>Symbol</span><input name="currency_symbol" value="₹"></label>
                <label class="field full"><span>Footer</span><textarea name="invoice_footer">Thank you for training with us.</textarea></label>
                <label class="field full"><span>Terms</span><textarea name="invoice_terms">Payments are collected manually at the gym.</textarea></label>
            </div>
        <?php elseif ($step === 5): ?>
            <p class="lede">Leave demo mode on until the Meta WhatsApp Cloud API credentials are ready. Demo mode never pretends a message was delivered.</p>
            <input type="hidden" name="demo_mode" value="1">
            <label class="check"><input type="checkbox" name="demo_mode" value="1" checked> Use demo mode</label>
            <div class="form-grid" style="margin-top:12px">
                <label class="field"><span>Phone number ID</span><input name="phone_number_id"></label>
                <label class="field"><span>Business account ID</span><input name="business_account_id"></label>
                <label class="field"><span>API version</span><input name="api_version" value="v21.0"></label>
                <label class="field"><span>Country code</span><input name="default_country_code" value="91"></label>
                <label class="field full"><span>Access token</span><input type="password" name="access_token" autocomplete="off"></label>
            </div>
        <?php else: ?>
            <div class="form-grid">
                <label class="field"><span>Plan name</span><input name="plan_name" required placeholder="Monthly Plan"></label>
                <label class="field"><span>Duration in days</span><input type="number" min="1" name="duration_days" value="30" required></label>
                <label class="field"><span>Price</span><input name="price" value="1500" required></label>
                <label class="field full"><span>Benefits</span><textarea name="benefits" placeholder="Floor access, locker"></textarea></label>
            </div>
        <?php endif; ?>
        <p><button class="btn btn-primary" type="submit"><?= $step === 6 ? 'Finish setup' : 'Save and continue' ?></button></p>
    </form>
</section>
<?php guest_end(); ?>
