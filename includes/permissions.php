<?php

declare(strict_types=1);

function permission_map(): array
{
    $all = ['owner', 'manager', 'receptionist', 'trainer'];
    $ops = ['owner', 'manager', 'receptionist'];
    $lead = ['owner', 'manager'];
    return [
        'nav.dashboard' => $all,
        'nav.assistant' => $all,
        'nav.members' => $all,
        'nav.memberships' => $lead,
        'nav.payments' => $ops,
        'nav.invoices' => $ops,
        'nav.attendance' => $all,
        'nav.trainers' => $lead,
        'nav.workouts' => ['owner', 'manager', 'trainer'],
        'nav.expenses' => $lead,
        'nav.reports' => $lead,
        'nav.automation' => $lead,
        'nav.notifications' => $all,
        'nav.settings' => ['owner', 'manager', 'receptionist', 'trainer'],
        'members.view' => $all,
        'members.manage' => $ops,
        'members.archive' => $lead,
        'plans.manage' => $lead,
        'payments.record' => $ops,
        'payments.void' => $lead,
        'invoices.view' => $ops,
        'invoices.send' => $ops,
        'attendance.manage' => $all,
        'attendance.override' => $lead,
        'staff.manage' => $lead,
        'staff.salary' => $lead,
        'expenses.manage' => $lead,
        'reports.view' => $lead,
        'reports.export' => $lead,
        'finance.revenue' => $ops,
        'settings.gym' => ['owner'],
        'whatsapp.configure' => ['owner'],
        'announcements.send' => $lead,
        'automation.view' => $lead,
        'workouts.manage' => ['owner', 'manager', 'trainer'],
        'assistant.ask' => $all,
    ];
}

function can(string $permission): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    $map = permission_map();
    return in_array($user['role'], $map[$permission] ?? [], true);
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        flash('error', 'You do not have permission to do that.');
        redirect_to('dashboard');
    }
}

function trainer_scope_id(): ?int
{
    $user = current_user();
    if ($user && $user['role'] === 'trainer') {
        return (int) $user['id'];
    }
    return null;
}
