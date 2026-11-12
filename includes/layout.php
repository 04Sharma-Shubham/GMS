<?php

declare(strict_types=1);

function push_script(string $src): void
{
    $GLOBALS['page_scripts'][] = $src;
}

function push_json(string $id, array $data): void
{
    $GLOBALS['page_json'][$id] = $data;
}

function nav_items(): array
{
    return [
        ['dashboard', 'Dashboard', 'dashboard', 'layout-dashboard'],
        ['assistant', 'Desk', 'assistant', 'sparkles'],
        ['members', 'Members', 'members', 'users'],
        ['memberships', 'Plans', 'memberships', 'badge-check'],
        ['payments', 'Payments', 'payments', 'wallet'],
        ['invoices', 'Invoices', 'invoices', 'file-text'],
        ['attendance', 'Attendance', 'attendance', 'qr-code'],
        ['trainers', 'Staff', 'trainers', 'dumbbell'],
        ['workouts', 'Workouts', 'workouts', 'activity'],
        ['expenses', 'Expenses', 'expenses', 'receipt'],
        ['reports', 'Reports', 'reports', 'pie-chart'],
        ['automation', 'Automation', 'automation', 'message-circle'],
        ['notifications', 'Alerts', 'notifications', 'bell'],
        ['settings', 'Settings', 'settings', 'settings'],
    ];
}

function layout_start(string $title, string $active): void
{
    $user = current_user();
    $gym = current_gym();
    if ($user && isset($_GET['read'])) {
        NotificationService::markRead((int) $user['gym_id'], (int) $user['id'], (int) $_GET['read']);
    }
    $unread = $user ? NotificationService::unreadCount((int) $user['gym_id'], (int) $user['id']) : 0;
    $notes = $user ? NotificationService::latest((int) $user['gym_id'], (int) $user['id'], 5) : [];
    $flashes = consume_flashes();
    $name = $gym['name'] ?? 'GMS';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e($name) . '</title><meta name="csrf" content="' . e(csrf_token()) . '">';
    echo '<link rel="icon" href="' . e(url('assets/images/favicon.svg')) . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://unpkg.com/aos@2.3.4/dist/aos.css">';
    echo '<link rel="stylesheet" href="' . e(url('assets/css/app.css')) . '">';
    echo '</head><body data-base="' . e(url('')) . '"><div class="ambient" aria-hidden="true"></div><div class="app">';
    echo '<aside class="sidebar" id="sidebar"><div class="brand"><a href="' . e(url('dashboard')) . '"><span class="brand-mark">G</span><span class="brand-copy"><strong>GMS</strong><small>' . e($name) . '</small></span></a></div><nav class="side-nav">';
    foreach (nav_items() as [$key, $label, $perm, $icon]) {
        if (!can('nav.' . $perm)) {
            continue;
        }
        $cls = $key === $active ? ' is-active' : '';
        echo '<a class="side-link' . $cls . '" href="' . e(url($key === 'dashboard' ? 'dashboard' : $key)) . '"><i data-lucide="' . e($icon) . '"></i><span>' . e($label) . '</span></a>';
    }
    echo '</nav><div class="side-user">' . avatar_html($user['avatar_path'] ?? null, $user['name'] ?? 'User', 'sm') . '<div><strong>' . e($user['name'] ?? '') . '</strong><small>' . e(status_label($user['role'] ?? '')) . '</small></div></div></aside>';
    echo '<div class="main"><header class="topbar"><button class="icon-btn" type="button" data-sidebar aria-label="Menu"><i data-lucide="menu"></i></button>';
    echo '<form class="top-search" action="' . e(url('members')) . '" method="get"><i data-lucide="search"></i><input type="search" name="q" placeholder="Search members" autocomplete="off" data-member-search><div class="search-results" hidden></div></form>';
    echo '<div class="top-actions"><details class="menu"><summary class="icon-btn" aria-label="Notifications">' . ($unread ? '<em>' . (int) $unread . '</em>' : '') . '<i data-lucide="bell"></i></summary><div class="menu-panel notes-panel">';
    if (!$notes) {
        echo '<p class="muted">No alerts yet.</p>';
    }
    foreach ($notes as $note) {
        echo '<a href="' . e(url(($note['link'] ?: 'notifications') . (str_contains((string) $note['link'], '?') ? '&' : '?') . 'read=' . (int) $note['id'])) . '"><strong>' . e($note['title']) . '</strong><span>' . e($note['body']) . '</span></a>';
    }
    echo '<a class="menu-all" href="' . e(url('notifications')) . '">Open notification center</a></div></details>';
    echo '<details class="menu"><summary>' . avatar_html($user['avatar_path'] ?? null, $user['name'] ?? '', 'sm') . '<span class="hide-sm">' . e($user['name'] ?? '') . '</span></summary><div class="menu-panel"><a href="' . e(url('settings?tab=account')) . '">Account</a><a href="' . e(url('logout')) . '">Log out</a></div></details></div></header>';
    echo '<div class="content" id="content">';
    if ($gym && ($gym['slug'] ?? '') === 'pulseforge-demo') {
        echo '<div class="demo-banner">Sample gym data for PulseForge Athletics. Register a new gym when you are ready to start clean.</div>';
    }
    foreach ($flashes as $flash) {
        echo '<div class="flash flash-' . e($flash['type']) . '" role="status">' . e($flash['message']) . '</div>';
    }
}

function layout_end(): void
{
    echo '</div></div></div>';
    echo '<nav class="tabbar">';
    foreach ([['dashboard', 'Home', 'layout-dashboard'], ['members', 'Members', 'users'], ['attendance', 'Check-in', 'qr-code'], ['payments', 'Pay', 'wallet']] as [$key, $label, $icon]) {
        if (!can('nav.' . ($key === 'dashboard' ? 'dashboard' : $key))) {
            continue;
        }
        echo '<a href="' . e(url($key === 'dashboard' ? 'dashboard' : $key)) . '"><i data-lucide="' . e($icon) . '"></i><span>' . e($label) . '</span></a>';
    }
    echo '</nav><div class="toast-stack" id="toasts"></div>';
    if (!empty($GLOBALS['page_json'])) {
        foreach ($GLOBALS['page_json'] as $id => $data) {
            echo '<script type="application/json" id="' . e($id) . '">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
    }
    echo '<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>';
    echo '<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>';
    echo '<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>';
    echo '<script src="' . e(url('assets/js/app.js')) . '"></script>';
    foreach ($GLOBALS['page_scripts'] ?? [] as $src) {
        $href = preg_match('#^https?://#', $src) ? $src : url($src);
        echo '<script src="' . e($href) . '"></script>';
    }
    clear_old();
    echo '</body></html>';
}

function guest_start(string $title): void
{
    $flashes = consume_flashes();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · GMS</title>';
    echo '<link rel="icon" href="' . e(url('assets/images/favicon.svg')) . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="' . e(url('assets/css/app.css')) . '"></head><body class="auth-body">';
    echo '<div class="auth-stage" aria-hidden="true"><span class="auth-orb auth-orb-a"></span><span class="auth-orb auth-orb-b"></span><span class="auth-orb auth-orb-c"></span><span class="auth-mesh"></span></div>';
    echo '<main class="auth-shell">';
    foreach ($flashes as $flash) {
        echo '<div class="flash flash-' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function guest_end(): void
{
    clear_old();
    echo '</main><script src="' . e(url('assets/js/auth.js')) . '"></script></body></html>';
}

function page_head(string $kicker, string $title, string $text = '', string $actions = ''): void
{
    echo '<header class="page-head"><div><p class="eyebrow">' . e($kicker) . '</p><h1>' . e($title) . '</h1>';
    if ($text !== '') {
        echo '<p class="lede">' . e($text) . '</p>';
    }
    echo '</div><div class="page-actions">' . $actions . '</div></header>';
}

function empty_state(string $title, string $text, string $action = ''): void
{
    echo '<div class="empty"><div class="empty-mark">G</div><h2>' . e($title) . '</h2><p>' . e($text) . '</p>' . $action . '</div>';
}

function pagination(int $page, int $pages): void
{
    if ($pages <= 1) {
        return;
    }
    $query = $_GET;
    echo '<nav class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $query['page'] = $i;
        $cls = $i === $page ? ' is-active' : '';
        echo '<a class="' . trim($cls) . '" href="?' . e(http_build_query($query)) . '">' . $i . '</a>';
    }
    echo '</nav>';
}

function query_page(int $total, int $per = 12): array
{
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    return [$page, $pages, ($page - 1) * $per, $per];
}
