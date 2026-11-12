<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
guest_only();

if (is_post()) {
    try {
        csrf_verify();
        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) {
            throw new AppException('Passwords do not match.');
        }
        GymService::register((string) $_POST['name'], (string) $_POST['email'], (string) $_POST['password'], (string) $_POST['gym_name'], (string) ($_POST['phone'] ?? ''));
        redirect_to('onboarding');
    } catch (AppException $e) {
        remember_old();
        flash('error', $e->getMessage());
        redirect_to('register');
    }
}

guest_start('Create gym');
?>
<section class="auth-copy">
    <div class="auth-brand">
        <span class="brand-mark auth-pulse">G</span>
        <div><strong>GMS</strong><small>Open your gym</small></div>
    </div>
    <p class="eyebrow">Create a workspace</p>
    <h1>One database.<br><span class="lime-word">Your gym stays separate.</span></h1>
    <p class="lede">After this form, six short steps collect the address, tax, invoice prefix, WhatsApp, and the first plan.</p>
    <ol class="auth-steps">
        <li><b>1</b><span>Gym name and logo</span></li>
        <li><b>2</b><span>Address and contact</span></li>
        <li><b>3</b><span>GST and tax</span></li>
        <li><b>4</b><span>Invoice branding</span></li>
        <li><b>5</b><span>WhatsApp, demo mode is fine</span></li>
        <li><b>6</b><span>Your first membership plan</span></li>
    </ol>
</section>
<section class="auth-card">
    <p class="eyebrow">Owner account</p>
    <h1>Create account</h1>
    <?= form_open() ?>
        <div class="form-grid" style="margin-top:18px">
            <label class="field"><span>Your name</span><input name="name" required value="<?= e(old('name')) ?>" placeholder="Aanya Kapoor"></label>
            <label class="field"><span>Gym name</span><input name="gym_name" required value="<?= e(old('gym_name')) ?>" placeholder="PulseForge Athletics"></label>
            <label class="field"><span>Email</span><input type="email" name="email" required autocomplete="username" value="<?= e(old('email')) ?>" placeholder="you@gym.com"></label>
            <label class="field"><span>Phone</span><input name="phone" value="<?= e(old('phone')) ?>" placeholder="9800000000"></label>
            <label class="field"><span>Password</span><span class="pass-wrap"><input type="password" name="password" required data-password autocomplete="new-password" placeholder="8+ characters, letter and number"><button class="pass-toggle" type="button" data-toggle-password>Show</button></span><span class="strength" data-strength><i></i></span></label>
            <label class="field"><span>Confirm password</span><span class="pass-wrap"><input type="password" name="password_confirm" required autocomplete="new-password" placeholder="Repeat the password"><button class="pass-toggle" type="button" data-toggle-password>Show</button></span></label>
        </div>
        <p style="margin-top:18px"><button class="btn btn-primary btn-shine" type="submit">Create gym</button></p>
    </form>
    <p class="auth-switch">Already registered? <a href="<?= e(url('login')) ?>">Sign in</a></p>
</section>
<?php guest_end(); ?>
