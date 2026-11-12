<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
http_response_code(404);
if (current_user() && current_gym() && (int) current_gym()['onboarding_completed']) {
    boot_page();
    layout_start('Not found', 'dashboard');
    empty_state('That page is not on the floor', 'The link may be old. Head back to the dashboard.', '<a class="btn btn-primary" href="' . e(url('dashboard')) . '">Dashboard</a>');
    layout_end();
    return;
}
guest_start('Not found');
echo '<section class="auth-card"><h1>Page not found</h1><p><a class="btn btn-primary" href="' . e(url('login')) . '">Sign in</a></p></section>';
guest_end();
