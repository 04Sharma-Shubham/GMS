<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if ($token !== '') {
    $row = db_one('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?', [hash('sha256', $token), utc_now()]);
}

if (is_post()) {
    try {
        csrf_verify();
        if (!$row) {
            throw new AppException('This reset link is invalid or expired.');
        }
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== ($_POST['password_confirm'] ?? '') || !valid_password($password)) {
            throw new AppException('Use matching passwords with at least 8 characters, a letter, and a number.');
        }
        db_tx(function (PDO $pdo) use ($row, $password) {
            $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), utc_now(), $row['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?')->execute([utc_now(), $row['id']]);
        });
        flash('success', 'Password updated. Sign in with the new password.');
        redirect_to('login');
    } catch (AppException $e) {
        flash('error', $e->getMessage());
        redirect_to('reset?token=' . urlencode($token));
    }
}

guest_start('Choose a password');
?>
<section class="auth-card" style="grid-column:1 / -1; max-width:560px">
    <h1>Choose a new password</h1>
    <?php if (!$row): ?>
        <p>This reset link is invalid or expired.</p>
        <a class="btn btn-primary" href="<?= e(url('forgot')) ?>">Request another</a>
    <?php else: ?>
        <?= form_open() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label class="field" style="margin:16px 0"><span>New password</span><input type="password" name="password" required></label>
            <label class="field" style="margin-bottom:16px"><span>Confirm</span><input type="password" name="password_confirm" required></label>
            <button class="btn btn-primary" type="submit">Update password</button>
        </form>
    <?php endif; ?>
</section>
<?php guest_end(); ?>
