<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/TrainingNeedModel.php';
require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../controllers/TrainingController.php';
require_once __DIR__ . '/../controllers/EvaluationController.php';

echo "=== Testing Training FAIL Lifecycle ===\n";

$needModel = new TrainingNeedModel();
$goalModel = new PerformanceGoalModel();
$trainCtrl = new TrainingController();
$evalCtrl  = new EvaluationController();

// 1. Setup a fresh goal with retry 3 (ready for training)
$pdo = getSupabaseDb();
$pdo->prepare("UPDATE performance_goals SET status = 'Approved', retry_count = 3, needs_training = true, in_training = false WHERE id = 103")->execute();

// Sync need
$needModel->syncDeficitsFromPerformance('emp-101');
$needs = $needModel->getNeeds(['employee_id' => 'emp-101', 'force_sync' => true]);
$perfNeeds = array_filter($needs, fn($n) => strpos($n['title'] ?? '', 'Performance Deficit') !== false);
$createdNeed = reset($perfNeeds);
$needId = $createdNeed['id'] ?? null;
echo "Need ID before training: " . ($needId ?: 'none') . "\n";

// 2. Schedule Session
$sessRes = $trainCtrl->createSession([
    'title'       => 'Test Fail Session',
    'programId'   => 'prog-1',
    'date'        => date('M d, Y'),
    'linkedNeedId'=> $needId,
    'roster'      => [
        ['associateId' => 'emp-101', 'name' => 'Maria Santos', 'role' => 'Front Desk Host']
    ]
]);
$goalsDuringTrain = $goalModel->getGoalsByEmployee('emp-101');
echo "Goal in_training after scheduling: " . (!empty($goalsDuringTrain[0]['in_training']) ? 'true' : 'false') . "\n";

// 3. Submit Failing Evaluation (score 20% < 80%)
$evalFailRes = $evalCtrl->submitEvaluation([
    'sessionId'     => $sessRes['data']['id'] ?? 'sess-1',
    'programId'     => 'prog-1',
    'associateId'   => 'emp-101',
    'associateName' => 'Maria Santos',
    'answers'       => [0 => 2, 1 => 2, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0], // failing score
    'kirkpatrickFeedback' => ['trainerRating' => 4.0, 'relevanceRating' => 4.0, 'comments' => 'Needs more practice.']
]);

echo "Eval isPassed: " . (!empty($evalFailRes['data']['isPassed']) ? 'true' : 'false') . "\n";

$goalsFailed = $goalModel->getGoalsByEmployee('emp-101');
echo "Goal status after FAIL: " . ($goalsFailed[0]['status'] ?? 'none') . "\n";
echo "Goal in_training after FAIL: " . (!empty($goalsFailed[0]['in_training']) ? 'true' : 'false') . "\n";
echo "Goal needs_training after FAIL: " . (!empty($goalsFailed[0]['needs_training']) ? 'true' : 'false') . "\n";

$needsAfterFail = $needModel->getNeeds(['employee_id' => 'emp-101', 'force_sync' => true]);
$perfNeedsAfterFail = array_filter($needsAfterFail, fn($n) => strpos($n['title'] ?? '', 'Performance Deficit') !== false);
echo "Active Performance Needs after FAIL: " . count($perfNeedsAfterFail) . "\n";

echo "\n=== FAIL Lifecycle Test Completed ===\n";
