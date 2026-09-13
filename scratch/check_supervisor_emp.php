<?php
require_once __DIR__ . '/../config/config.php';
$pdo = getSupabaseDb();
$emps = $pdo->query("SELECT id, employee_code, full_name, email, role, title FROM employees WHERE id IN ('emp-101', 'emp-102', 'emp-103') OR role ILIKE '%super%'")->fetchAll(PDO::FETCH_ASSOC);
echo "Employees:\n";
print_r($emps);

$rba = $pdo->query("SELECT * FROM role_based_accounts")->fetchAll(PDO::FETCH_ASSOC);
echo "\nRole Based Accounts:\n";
print_r($rba);
