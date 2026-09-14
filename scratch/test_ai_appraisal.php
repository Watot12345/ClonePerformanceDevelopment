<?php
require_once __DIR__ . '/../controllers/PerformanceController.php';

$controller = new PerformanceController();
$res = $controller->generateAppraisalRecommendations(['include_evaluated' => true]);

echo "=== AI APPRAISAL RECOMMENDATIONS TEST ===\n";
echo "Count: " . ($res['data']['count'] ?? 0) . "\n";
foreach ($res['data']['recommendations'] ?? [] as $rec) {
    echo "\n- Employee: " . $rec['employee_name'] . " (" . $rec['employee_id'] . ")\n";
    echo "  Goal: " . $rec['goal_title'] . "\n";
    echo "  Recommended Rating: " . $rec['recommended_score'] . " / 5.0 (" . $rec['tier_label'] . ")\n";
    echo "  Supervisor Notes Synthesized: " . $rec['supervisor_notes'] . "\n";
    echo "  Criteria Count: " . count($rec['criteria_scores']) . "\n";
    echo "  Evidence Analyzed:\n";
    echo "    * Supervisor Notes: " . $rec['evidence_summary']['supervisor_notes_count'] . "\n";
    echo "    * Employee Learnings: " . $rec['evidence_summary']['employee_learnings_count'] . "\n";
    echo "    * Employee Feedback: " . $rec['evidence_summary']['employee_feedback_count'] . "\n";
    echo "    * Shift Milestones: " . $rec['evidence_summary']['milestones_count'] . "\n";
    echo "    * Verified Tasks: " . $rec['evidence_summary']['completed_tasks_count'] . "/" . $rec['evidence_summary']['total_tasks_count'] . "\n";
}

$refl = new ReflectionClass($controller);
$method = $refl->getMethod('analyzeTextSubstanceAndSentiment');
$method->setAccessible(true);

echo "\n=== SUBSTANCE ANALYSIS COMPARISON ===\n";
echo "1. Placeholder Input ('a', 'asd', 'D'):\n";
$res1 = $method->invoke($controller, ['a', 'asd', 'D']);
print_r($res1);

echo "\n3. Negative Incident Note:\n";
$res3 = $method->invoke($controller, ['Repeatedly missed shift turnover briefing with severe guest complaints regarding hygiene violation and delayed table service.']);
print_r($res3);

echo "\n=== TEST COMPLETE ===\n";
