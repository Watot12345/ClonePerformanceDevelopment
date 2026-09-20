<?php
require_once __DIR__ . '/../config/config.php';
$conn = getSupabaseDb();
$stmt = $conn->query("SELECT id, employee_id, title, status, retry_count, needs_training, final_rating FROM performance_goals");
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Goals count: " . count($goals) . "\n";
foreach ($goals as $g) {
    echo "Goal {$g['id']}: emp={$g['employee_id']}, status={$g['status']}, retry={$g['retry_count']}, needs_training={$g['needs_training']}, final_rating={$g['final_rating']}\n";
}

$stmt2 = $conn->query("SELECT id, employee_id, status, supervisor_rating, calibrated_score, new_calibrated_score FROM performance_evaluations");
$evals = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\nEvals count: " . count($evals) . "\n";
foreach ($evals as $e) {
    echo "Eval {$e['id']}: emp={$e['employee_id']}, status={$e['status']}, sup={$e['supervisor_rating']}, calibrated={$e['calibrated_score']}, new_calibrated={$e['new_calibrated_score']}\n";
}

$stmt3 = $conn->query("SELECT id, employee_id, title, category, status, current_score, required_score, target_goal_id FROM training_needs");
$needs = $stmt3->fetchAll(PDO::FETCH_ASSOC);
echo "\nNeeds count: " . count($needs) . "\n";
foreach ($needs as $n) {
    echo "Need {$n['id']}: emp={$n['employee_id']}, title={$n['title']}, status={$n['status']}, score={$n['current_score']}, target_goal={$n['target_goal_id']}\n";
}

