<?php
require_once 'models/PerformanceTaskModel.php';
$taskModel = new PerformanceTaskModel();
$gen = $taskModel->getGeneralTasks();
echo "General Tasks in DB count: " . count($gen) . "\n";
foreach ($gen as $g) {
    echo "ID: {$g['id']} | Title: {$g['title']} | Status: {$g['status']}\n";
}
