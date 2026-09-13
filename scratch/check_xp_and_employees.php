<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/SocialModel.php';

try {
    $db = getSupabaseDb();
    echo "=== EMPLOYEES ===\n";
    $emps = $db->query("SELECT * FROM employees ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    print_r($emps);

    echo "\n=== USERS ===\n";
    $users = $db->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    print_r($users);

    echo "\n=== XP LEDGER ===\n";
    $xp = $db->query("SELECT * FROM xp_ledger ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    print_r($xp);

    echo "\n=== SOCIAL MODEL LEADERBOARD ===\n";
    $sm = new SocialModel();
    $lb = $sm->getLeaderboardWithStanding();
    print_r($lb);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
