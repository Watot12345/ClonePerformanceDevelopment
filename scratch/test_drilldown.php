<?php
$_SESSION['role'] = 'Associate';
$_SESSION['employee_id'] = '3a52667f-53cf-412a-b048-ef96eb407707';
$_GET['action'] = 'get_drilldown';
$_GET['metric'] = 'active_objectives';
$_GET['role'] = 'Associate';

require_once __DIR__ . '/../config/config.php';

ob_start();
include __DIR__ . '/../api/overview.php';
$output = ob_get_clean();

$res = json_decode($output, true);
echo "=== ACTIVE OBJECTIVES DRILLDOWN RESPONSE (EMPLOYEE VIEW) ===\n";
echo "Success: " . ($res['success'] ? 'true' : 'false') . "\n";
echo "Title: " . ($res['data']['title'] ?? 'N/A') . "\n";
echo "Action Label: " . var_export($res['data']['action_label'] ?? null, true) . "\n";
echo "Goals Count: " . count($res['data']['chart']['data'] ?? []) . "\n";
