<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/CompetencyController.php';

$cc = new CompetencyController();
$juanId = '3a52667f-53cf-412a-b048-ef96eb407707';
$mariaId = 'emp-101';

echo "=== TEST COMPETENCY ISOLATION ===\n\n";

$juanComps = $cc->getEmployeeCompetencies($juanId);
echo "1. Juan Dela Cruz ($juanId) Competencies:\n";
echo "   - Success: " . ($juanComps['success'] ? 'true' : 'false') . "\n";
echo "   - Employee Found: " . ($juanComps['employee']['full_name'] ?? 'None') . "\n";
echo "   - Role: " . ($juanComps['employee']['title'] ?? 'None') . "\n";
echo "   - Item Count: " . count($juanComps['items'] ?? []) . "\n";

$mariaComps = $cc->getEmployeeCompetencies($mariaId);
echo "\n2. Maria Santos ($mariaId) Competencies:\n";
echo "   - Success: " . ($mariaComps['success'] ? 'true' : 'false') . "\n";
echo "   - Employee Found: " . ($mariaComps['employee']['full_name'] ?? 'None') . "\n";
echo "   - Role: " . ($mariaComps['employee']['title'] ?? 'None') . "\n";
echo "   - Item Count: " . count($mariaComps['items'] ?? []) . "\n";

echo "\n=== COMPLETE ===\n";
