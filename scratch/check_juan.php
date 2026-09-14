<?php
require_once __DIR__ . '/../config/config.php';
$pdo = getSupabaseDb();

$uuid = '505ff947-5c21-4f4a-a117-27d4d7dac341';
echo "Checking $uuid in employees:\n";
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id OR employee_code = :id2");
$stmt->execute([':id' => $uuid, ':id2' => $uuid]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nChecking $uuid in users:\n";
$stmt2 = $pdo->prepare("SELECT * FROM users WHERE id = :id OR employee_code = :id2");
$stmt2->execute([':id' => $uuid, ':id2' => $uuid]);
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "\nAll users:\n";
$allU = $pdo->query("SELECT id, employee_code, full_name, role, title FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($allU);

echo "\nAll employees:\n";
$allE = $pdo->query("SELECT id, employee_code, full_name, title FROM employees")->fetchAll(PDO::FETCH_ASSOC);
print_r($allE);
