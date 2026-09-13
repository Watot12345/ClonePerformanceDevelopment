<?php
require_once 'models/PerformanceGoalModel.php';
require_once 'models/PerformanceTaskModel.php';

$goalModel = new PerformanceGoalModel();
$taskModel = new PerformanceTaskModel();

$goals = $goalModel->getGoals();
echo "GOALS:\n";
foreach ($goals as $g) {
    echo "Goal ID: {$g['id']} | Emp: {$g['employee_id']} | Title: {$g['title']} | Status: {$g['status']}\n";
    $tasks = $taskModel->all(['goal_id' => $g['id']]);
    echo "  Tasks count: " . count($tasks) . "\n";
    foreach ($tasks as $t) {
        echo "    Task ID: {$t['id']} | Type: {$t['task_type']} | Title: {$t['title']} | Status: {$t['status']}\n";
    }
}

echo "\nALL TASKS in DB:\n";
$allTasks = $taskModel->all();
echo "Total tasks in DB: " . count($allTasks) . "\n";
foreach ($allTasks as $t) {
    echo "Task ID: {$t['id']} | Goal ID: {$t['goal_id']} | Emp ID: {$t['employee_id']} | Type: {$t['task_type']} | Title: {$t['title']}\n";
}
