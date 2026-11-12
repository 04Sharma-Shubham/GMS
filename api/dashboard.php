<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('nav.dashboard');
$range = resolve_range(current_gym());
$data = ReportService::dashboard(gym_id(), $range, trainer_scope_id());
json_response(['range' => $range, 'kpis' => $data['kpis'], 'charts' => $data['charts']]);
