<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
guest_only();

if (is_post()) {
    try {
        csrf_verify();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if (!attempt_login($email, $password)) {
            throw new AppException('Those credentials do not match an active account.');
        }
        $gym = current_gym();
        redirect_to($gym && (int) $gym['onboarding_completed'] ? 'dashboard' : 'onboarding');
    } catch (AppException $e) {
        flash('error', $e->getMessage());
        redirect_to('login');
    }
}

guest_start('Sign in');
?>
<section class="auth-copy">
    <div class="auth-brand">
        <span class="brand-mark auth-pulse">G</span>
        <div><strong>GMS</strong><small>Gym Management System</small></div>
    </div>
    <p class="eyebrow">Welcome back</p>
    <h1>Run the floor.<br><span class="lime-word">Keep the books honest.</span></h1>
    <p class="lede">Members, plans, manual payments, invoices, attendance, and WhatsApp reminders in one dark workspace.</p>
    <ul class="feature-list">
        <li>Manual payments only. Paid, partially paid, or unpaid.</li>
        <li>Invoices and PDFs generated from the payment record.</li>
        <li>WhatsApp Cloud API, with a clearly labeled demo mode.</li>
    </ul>
    <div class="auth-pills"><span>Paid</span><span>Partially paid</span><span>Unpaid</span></div>
</section>
<section class="auth-card">
    <p class="eyebrow">Sign in</p>
    <h1>Enter the gym</h1>
    <?= form_open() ?>
        <label class="field" style="margin-top:22px"><span>Email</span><input type="email" name="email" required autocomplete="username" value="<?= e(old('email')) ?>" placeholder="you@gym.com"></label>
        <label class="field" style="margin:14px 0 18px"><span>Password</span><span class="pass-wrap"><input type="password" name="password" required autocomplete="current-password" placeholder="Your password"><button class="pass-toggle" type="button" data-toggle-password>Show</button></span></label>
        <button class="btn btn-primary btn-shine" type="submit">Enter gym</button>
    </form>
    <p class="auth-switch">New gym? <a href="<?= e(url('register')) ?>">Create an owner account</a><br><a href="<?= e(url('forgot')) ?>">Forgot password</a></p>
</section>
<?php guest_end(); ?>
