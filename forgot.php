<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
guest_only();

if (is_post()) {
    try {
        csrf_verify();
        rate_limit('reset:' . client_ip(), 5, 3600);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $user = db_one('SELECT * FROM users WHERE email = ? AND status = ?', [$email, 'active']);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db_exec('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);
            db_exec('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)', [
                $user['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), utc_now(),
            ]);
            $link = url('reset?token=' . $token);
            $sent = send_app_mail($user['email'], 'Reset your GMS password', '<p>Reset your password within one hour:</p><p><a href="' . e($link) . '">' . e($link) . '</a></p>');
            if (!$sent && env('APP_ENV') === 'local') {
                flash('info', 'Email is not configured. Local reset link: ' . $link);
            }
        }
        flash('success', 'If that account exists, a reset link is ready.');
    } catch (AppException $e) {
        flash('error', $e->getMessage());
    }
    redirect_to('forgot');
}

guest_start('Forgot password');
?>
<section class="auth-card" style="grid-column:1 / -1; max-width:560px">
    <p class="eyebrow">Account recovery</p>
    <h1>Reset password</h1>
    <p class="lede">We will email a one-hour link when mail is configured. In local mode the link is shown once and written to the mail log.</p>
    <?= form_open() ?>
        <label class="field" style="margin:16px 0"><span>Email</span><input type="email" name="email" required></label>
        <button class="btn btn-primary" type="submit">Prepare reset link</button>
        <a href="<?= e(url('login')) ?>">Back to sign in</a>
    </form>
</section>
<?php guest_end(); ?>
