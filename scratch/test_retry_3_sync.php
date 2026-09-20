<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../models/TrainingNeedModel.php';
require_once __DIR__ . '/../controllers/PerformanceController.php';

$pdo = getSupabaseDb();

// Clear test needs
$pdo->exec("DELETE FROM training_needs WHERE employee_id = 'emp-101'");

$goalModel = new PerformanceGoalModel();
$needModel = new TrainingNeedModel();
$perfController = new PerformanceController();

echo "--- 1. Initial State ---\n";
$needsBefore = $pdo->query("SELECT * FROM training_needs WHERE employee_id = 'emp-101'")->fetchAll(PDO::FETCH_ASSOC);
echo "Needs count before: " . count($needsBefore) . "\n";

echo "--- 2. Setting Goal retry_count = 2 ---\n";
$goalModel->setEmployeeGoalsRetryCount('emp-101', 2);
$needsAfter2 = $pdo->query("SELECT * FROM training_needs WHERE employee_id = 'emp-101'")->fetchAll(PDO::FETCH_ASSOC);
echo "Needs count after retry=2: " . count($needsAfter2) . "\n";

echo "--- 3. Testing retryPlan() -> retry_count becomes 3 ---\n";
$res = $perfController->retryPlan(['employee_id' => 'emp-101']);
echo "retryPlan response: success=" . ($res['success'] ? 'true' : 'false') . ", retry_count=" . ($res['retry_count'] ?? 0) . ", needs_training=" . ($res['needs_training'] ? 'true' : 'false') . "\n";

$needsAfter3 = $pdo->query("SELECT id, employee_id, title, status, current_score, required_score, target_goal_id FROM training_needs WHERE employee_id = 'emp-101'")->fetchAll(PDO::FETCH_ASSOC);
echo "Needs count after retry=3: " . count($needsAfter3) . "\n";
foreach ($needsAfter3 as $n) {
    echo "  -> Need ID: {$n['id']}, Title: {$n['title']}, Status: {$n['status']}, Score: {$n['current_score']}/{$n['required_score']}, Goal: {$n['target_goal_id']}\n";
}
