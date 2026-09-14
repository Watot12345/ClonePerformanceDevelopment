<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../models/PerformanceEvaluationModel.php';
require_once __DIR__ . '/../models/SocialModel.php';

echo "=== EMPLOYEE DATA ISOLATION VERIFICATION ===\n\n";

$goalModel = new PerformanceGoalModel();
$evalModel = new PerformanceEvaluationModel();
$socialModel = new SocialModel();

// 1. Check goal retrieval isolation
$testUuid = '3a52667f-53cf-412a-b048-ef96eb407707'; // Juan Dela Cruz
$mariaId = 'emp-101';

$juanGoals = $goalModel->getGoalsByEmployee($testUuid);
$mariaGoals = $goalModel->getGoalsByEmployee($mariaId);

echo "1. Goals Isolation:\n";
echo "   - Goals for Juan Dela Cruz ($testUuid): " . count($juanGoals) . "\n";
foreach ($juanGoals as $g) {
    echo "     * Goal ID {$g['id']}: {$g['title']} (Emp ID: {$g['employee_id']})\n";
    if ($g['employee_id'] === 'emp-101' || $g['employee_id'] === 'OXF-EMP-1001') {
        echo "     [FATAL LEAK] Maria's goal returned for Juan!\n";
    }
}
echo "   - Goals for Maria Santos ($mariaId): " . count($mariaGoals) . "\n";

// 2. Check evaluation isolation
echo "\n2. Evaluation Isolation:\n";
$juanEval = $evalModel->getEvaluationByEmployee($testUuid);
$mariaEval = $evalModel->getEvaluationByEmployee($mariaId);

echo "   - Evaluation for Juan: " . ($juanEval ? ("Found (ID: " . ($juanEval['id'] ?? 'N/A') . ", Emp: " . ($juanEval['employee_id'] ?? '') . ")") : "None (Correct/Empty)") . "\n";
echo "   - Evaluation for Maria: " . ($mariaEval ? ("Found (ID: " . ($mariaEval['id'] ?? 'N/A') . ", Emp: " . ($mariaEval['employee_id'] ?? '') . ")") : "None") . "\n";

if ($juanEval && ($juanEval['employee_id'] === 'emp-101' || $juanEval['employee_id'] === 'OXF-EMP-1001')) {
    echo "   [FATAL LEAK] Maria's evaluation returned for Juan!\n";
} else {
    echo "   [PASS] Juan evaluation is strictly isolated from Maria.\n";
}

// 3. Check resolveValidUserId
echo "\n3. Identity Resolution:\n";
$resolvedJuan = $goalModel->resolveValidUserId($testUuid);
$resolvedMaria = $goalModel->resolveValidUserId($mariaId);
echo "   - Resolved Juan ($testUuid): $resolvedJuan\n";
echo "   - Resolved Maria ($mariaId): $resolvedMaria\n";

if ($resolvedJuan === $resolvedMaria) {
    echo "   [FATAL LEAK] Distinct employees resolved to same user ID!\n";
} else {
    echo "   [PASS] Distinct employees resolve to distinct IDs.\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
