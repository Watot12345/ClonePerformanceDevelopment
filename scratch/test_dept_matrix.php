<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get_dept_execution_matrix';
ob_start();
require 'api/reports.php';
$out = ob_get_clean();
$json = json_decode($out, true);
echo "Reports matrix response success: " . ($json['success'] ? 'true' : 'false') . "\n";
echo "Matrix count: " . count($json['matrix'] ?? []) . "\n";
foreach ($json['matrix'] ?? [] as $m) {
    echo "- " . $m['department'] . " | Goals: " . $m['goals_approved_pct'] . "% | LMS: " . $m['lms_rate_pct'] . "% | Succ: " . $m['succession_ready_pct'] . "%\n";
}
