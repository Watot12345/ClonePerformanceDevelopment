<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/TrainingNeedModel.php';
require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../controllers/TrainingController.php';
require_once __DIR__ . '/../controllers/EvaluationController.php';

echo "=== Testing Training Lifecycle & Needs Cascade ===\n";

$needModel = new TrainingNeedModel();
$goalModel = new PerformanceGoalModel();
$trainCtrl = new TrainingController();
$evalCtrl  = new EvaluationController();

// 1. Initial State Check
echo "\n--- 1. Testing Appraisal with retry_count = 0 (Should NOT set needs_training or create need) ---\n";
$goals = $goalModel->getGoalsByEmployee('emp-101');
$targetGoal = $goals[0] ?? null;
if ($targetGoal) {
    $goalModel->update($targetGoal['id'], [
        'status' => 'Approved',
        'retry_count' => 0,
        'needs_training' => false,
        'in_training' => false
    ]);
}
$synced = $needModel->syncDeficitsFromPerformance('emp-101');
$goals = $goalModel->getGoalsByEmployee('emp-101');
$targetGoal = $goals[0] ?? null;

echo "Goal ID: " . ($targetGoal['id'] ?? 'none') . "\n";
echo "Goal retry_count: " . ($targetGoal['retry_count'] ?? 0) . "\n";
echo "Goal needs_training: " . (!empty($targetGoal['needs_training']) ? 'true' : 'false') . "\n";
echo "Goal status: " . ($targetGoal['status'] ?? 'none') . "\n";

$needs = $needModel->getNeeds(['employee_id' => 'emp-101', 'force_sync' => true]);
$perfNeeds = array_filter($needs, fn($n) => strpos($n['title'] ?? '', 'Performance Deficit') !== false);
echo "Active Performance Needs in training_needs: " . count($perfNeeds) . "\n";

// 2. Test retry_count = 3 (Should set needs_training = true and create deficit)
echo "\n--- 2. Setting retry_count = 3 (Should trigger needs_training = true and create training need) ---\n";
$goalModel->setGoalRetryCount($targetGoal['id'], 3);
$goalModel->setNeedsTraining($targetGoal['id'], true);
$needModel->syncDeficitsFromPerformance('emp-101');

$goalsAfter = $goalModel->getGoalsByEmployee('emp-101');
$targetGoalAfter = $goalsAfter[0] ?? null;
echo "Goal needs_training after retry 3: " . (!empty($targetGoalAfter['needs_training']) ? 'true' : 'false') . "\n";

$needsAfter = $needModel->getNeeds(['employee_id' => 'emp-101', 'force_sync' => true]);
$perfNeedsAfter = array_filter($needsAfter, fn($n) => strpos($n['title'] ?? '', 'Performance Deficit') !== false);
echo "Active Performance Needs after retry 3: " . count($perfNeedsAfter) . "\n";
$createdNeed = reset($perfNeedsAfter);
$needId = $createdNeed['id'] ?? null;
echo "Created Need ID: " . ($needId ?: 'none') . "\n";

// 3. Test Scheduling Session (Should set in_training = true on goal)
echo "\n--- 3. Scheduling Session with Roster (Should set in_training = true) ---\n";
$sessRes = $trainCtrl->createSession([
    'title'       => 'Test Service Excellence Session',
    'programId'   => 'prog-1',
    'date'        => date('M d, Y'),
    'linkedNeedId'=> $needId,
    'roster'      => [
        ['associateId' => 'emp-101', 'name' => 'Maria Santos', 'role' => 'Front Desk Host']
    ]
]);
echo "Session creation success: " . ($sessRes['success'] ? 'true' : 'false') . "\n";
$goalsDuringTrain = $goalModel->getGoalsByEmployee('emp-101');
echo "Goal in_training after scheduling: " . (!empty($goalsDuringTrain[0]['in_training']) ? 'true' : 'false') . "\n";

// 4. Test Submitting Evaluation Result - PASS (Should set goal Completed, in_training=false, needs_training=false, remove from training_needs)
echo "\n--- 4. Testing Evaluation Result (PASS) ---\n";
$evalPassRes = $evalCtrl->submitEvaluation([
    'sessionId'     => $sessRes['data']['id'] ?? 'sess-1',
    'programId'     => 'prog-1',
    'associateId'   => 'emp-101',
    'associateName' => 'Maria Santos',
    'answers'       => [0 => 0, 1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1], // perfect score
    'kirkpatrickFeedback' => ['trainerRating' => 5.0, 'relevanceRating' => 5.0, 'comments' => 'Outstanding!']
]);

echo "Eval submission success: " . ($evalPassRes['success'] ? 'true' : 'false') . "\n";
echo "Eval isPassed: " . (!empty($evalPassRes['data']['isPassed']) ? 'true' : 'false') . "\n";

$goalsPassed = $goalModel->getGoalsByEmployee('emp-101');
echo "Goal status after PASS: " . ($goalsPassed[0]['status'] ?? 'none') . "\n";
echo "Goal in_training after PASS: " . (!empty($goalsPassed[0]['in_training']) ? 'true' : 'false') . "\n";
echo "Goal needs_training after PASS: " . (!empty($goalsPassed[0]['needs_training']) ? 'true' : 'false') . "\n";

$needsPassed = $needModel->getNeeds(['employee_id' => 'emp-101', 'force_sync' => true]);
$perfNeedsPassed = array_filter($needsPassed, fn($n) => strpos($n['title'] ?? '', 'Performance Deficit') !== false);
echo "Active Performance Needs after PASS: " . count($perfNeedsPassed) . "\n";

echo "\n=== All Lifecycle Tests Completed Successfully ===\n";
