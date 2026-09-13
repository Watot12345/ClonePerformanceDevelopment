<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get_ledger';
$_GET['employee_id'] = 'emp-102';
$_GET['role'] = 'supervisor';
ob_start();
require 'api/social.php';
$out = ob_get_clean();
$json = json_decode($out, true);
echo "=== Ledger for Supervisor ===\n";
echo "Success: " . (($json['success'] ?? false) ? 'true' : 'false') . "\n";
echo "Total ledger transactions returned: " . count($json['data'] ?? []) . "\n";
foreach ($json['data'] ?? [] as $t) {
    echo "- " . ($t['id'] ?? 'TXN') . " | " . ($t['recipient_name'] ?? $t['recipient'] ?? 'Associate') . " (" . ($t['employee_id'] ?? '') . ") | " . ($t['xpChange'] ?? ($t['points'] . ' XP')) . "\n";
}
