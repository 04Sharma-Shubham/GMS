<?php

declare(strict_types=1);

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $seen = (int) ($_SESSION['_seen'] ?? time());
    if (time() - $seen > 43200) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['_seen'] = time();
    try {
        $user = db_one('SELECT * FROM users WHERE id = ? AND status = ?', [$id, 'active']);
    } catch (Throwable $e) {
        $user = null;
        return null;
    }
    if (!$user) {
        $_SESSION = [];
        return null;
    }
    $_SESSION['gym_id'] = (int) $user['gym_id'];
    return $user;
}

function current_gym(): ?array
{
    static $gym = null;
    static $loaded = false;
    if ($loaded) {
        return $gym;
    }
    $loaded = true;
    $user = current_user();
    if (!$user) {
        return null;
    }
    $gym = db_one('SELECT * FROM gyms WHERE id = ?', [(int) $user['gym_id']]);
    return $gym;
}

function forget_auth_cache(): void
{
    // Cache is request-local. A redirect starts a fresh request.
}

function user_id(): int
{
    return (int) (current_user()['id'] ?? 0);
}

function gym_id(): int
{
    return (int) (current_user()['gym_id'] ?? 0);
}

function require_login(): void
{
    if (!current_user()) {
        flash('error', 'Sign in to continue.');
        redirect_to('login');
    }
}

function require_onboarded(): void
{
    require_login();
    $gym = current_gym();
    if (!$gym || !(int) $gym['onboarding_completed']) {
        redirect_to('onboarding');
    }
}

function guest_only(): void
{
    if (current_user()) {
        $gym = current_gym();
        redirect_to($gym && (int) $gym['onboarding_completed'] ? 'dashboard' : 'onboarding');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'gym_id' => (int) $user['gym_id'],
        '_seen' => time(),
        '_csrf' => bin2hex(random_bytes(32)),
    ];
    db_exec('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?', [utc_now(), utc_now(), $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function attempt_login(string $email, string $password): bool
{
    rate_limit('login:' . client_ip(), 8, 900);
    rate_limit('login-email:' . strtolower($email), 8, 900);
    $user = db_one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), utc_now(), $user['id']]);
    }
    login_user($user);
    return true;
}

function unique_slug(PDO $pdo, string $name): string
{
    $base = slugify($name);
    $slug = $base;
    $i = 2;
    $stmt = $pdo->prepare('SELECT id FROM gyms WHERE slug = ?');
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}
