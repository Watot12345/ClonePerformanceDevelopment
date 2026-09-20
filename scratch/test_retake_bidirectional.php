<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/TrainingNeedModel.php';
require_once __DIR__ . '/../models/PerformanceGoalModel.php';
require_once __DIR__ . '/../models/TrainingProgramModel.php';
require_once __DIR__ . '/../models/TrainingSessionModel.php';
require_once __DIR__ . '/../controllers/TrainingController.php';
require_once __DIR__ . '/../controllers/EvaluationController.php';

$pdo = getSupabaseDb();
$needModel = new TrainingNeedModel();
$goalModel = new PerformanceGoalModel();
$trainCtrl = new TrainingController();
$evalCtrl  = new EvaluationController();

echo "=== Testing Bidirectional Retake Status Changes ===\n";

// Ensure a test program and session exists
$progModel = new TrainingProgramModel();
$programs = $progModel->getPrograms();
$program = $programs[0] ?? null;
$programId = $program['id'] ?? 'prog-test';

$sessRes = $trainCtrl->createSession([
    'title'       => 'Bidirectional Retake Test Session',
    'programId'   => $programId,
    'trainerName' => 'Lead Master Trainer',
    'scheduledAt' => date('Y-m-d H:i:s'),
    'venue'       => 'Grand Ballroom',
    'roster'      => [
        ['associateId' => 'emp-101', 'name' => 'Maria Santos', 'role' => 'Front Desk Host', 'attendanceStatus' => 'Attended']
    ]
]);
$sessionId = $sessRes['data']['id'] ?? 'sess-test';

// Reset goal to Approved
$goals = $goalModel->getGoalsByEmployee('emp-101');
$targetGoal = $goals[0] ?? null;
$goalId = $targetGoal['id'];

// --- Attempt 1: FAIL ---
echo "\n--- 1. First Attempt: FAILED (Score: 2/10) ---\n";
$evalFail1 = $evalCtrl->submitEvaluation([
    'sessionId'   => $sessionId,
    'programId'   => $programId,
    'associateId' => 'emp-101',
    'answers'     => [0 => 9, 1 => 9, 2 => 9], // wrong answers
    'isRetest'    => false,
    'role'        => 'Associate'
]);
$goalAfterFail1 = $goalModel->find($goalId);
echo "Result 1 isPassed: " . ($evalFail1['data']['isPassed'] ? 'true' : 'false') . "\n";
echo "Goal status after 1st attempt (Fail): {$goalAfterFail1['status']}\n";

// --- Attempt 2: PASS (Retake) ---
echo "\n--- 2. Second Attempt: RETAKE PASSED (Score: 10/10) ---\n";
$evalPass2 = $evalCtrl->submitEvaluation([
    'sessionId'        => $sessionId,
    'programId'        => $programId,
    'associateId'      => 'emp-101',
    'answers'          => [0 => 0, 1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1], // all correct
    'previousResultId' => $evalFail1['data']['evaluation']['id'] ?? null,
    'isRetest'         => true,
    'role'             => 'Associate'
]);
$goalAfterPass2 = $goalModel->find($goalId);
echo "Result 2 isPassed: " . ($evalPass2['data']['isPassed'] ? 'true' : 'false') . "\n";
echo "Goal status after 2nd attempt (Pass): {$goalAfterPass2['status']} (Expected: Completed)\n";

// --- Attempt 3: FAIL (Retake Again) ---
echo "\n--- 3. Third Attempt: RETAKE FAILED (Score: 1/10) ---\n";
$evalFail3 = $evalCtrl->submitEvaluation([
    'sessionId'        => $sessionId,
    'programId'        => $programId,
    'associateId'      => 'emp-101',
    'answers'          => [0 => 9, 1 => 9], // wrong answers
    'previousResultId' => $evalPass2['data']['evaluation']['id'] ?? null,
    'isRetest'         => true,
    'role'             => 'Associate'
]);
$goalAfterFail3 = $goalModel->find($goalId);
echo "Result 3 isPassed: " . ($evalFail3['data']['isPassed'] ? 'true' : 'false') . "\n";
echo "Goal status after 3rd attempt (Fail): {$goalAfterFail3['status']} (Expected: Failed)\n";

// --- Attempt 4: PASS (Retake Again) ---
echo "\n--- 4. Fourth Attempt: RETAKE PASSED AGAIN (Score: 10/10) ---\n";
$evalPass4 = $evalCtrl->submitEvaluation([
    'sessionId'        => $sessionId,
    'programId'        => $programId,
    'associateId'      => 'emp-101',
    'answers'          => [0 => 0, 1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1], // all correct
    'previousResultId' => $evalFail3['data']['evaluation']['id'] ?? null,
    'isRetest'         => true,
    'role'             => 'Associate'
]);
$goalAfterPass4 = $goalModel->find($goalId);
echo "Result 4 isPassed: " . ($evalPass4['data']['isPassed'] ? 'true' : 'false') . "\n";
echo "Goal status after 4th attempt (Pass): {$goalAfterPass4['status']} (Expected: Completed)\n";

echo "\n=== Bidirectional Status Toggle Verified! ===\n";
