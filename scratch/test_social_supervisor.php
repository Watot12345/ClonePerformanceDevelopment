<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get_overview';
$_GET['employee_id'] = 'emp-102';
$_GET['role'] = 'supervisor';
ob_start();
require 'api/social.php';
$out = ob_get_clean();
$json = json_decode($out, true);
echo "=== Social Overview for Supervisor (emp-102) ===\n";
echo "Success: " . (($json['success'] ?? false) ? 'true' : 'false') . "\n";
echo "Top 5 Champions count: " . count($json['data']['champions'] ?? []) . "\n";
foreach ($json['data']['champions'] ?? [] as $champ) {
    echo "- Rank " . ($champ['rank'] ?? '?') . ": " . ($champ['name'] ?? 'Open') . " (" . ($champ['employee_id'] ?? '') . ") - " . ($champ['total_xp'] ?? 0) . " XP\n";
}
echo "All employees XP count: " . count($json['data']['all_employees_xp'] ?? []) . "\n";
foreach ($json['data']['all_employees_xp'] ?? [] as $asc) {
    echo "  * " . $asc['name'] . " (" . $asc['employee_id'] . ") - " . $asc['total_xp'] . " XP - Rank: " . ($asc['rank_display'] ?? 'None') . "\n";
}
echo "KPIs: \n";
print_r($json['data']['kpis'] ?? []);
