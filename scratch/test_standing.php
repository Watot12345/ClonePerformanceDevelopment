<?php
require_once 'models/SocialModel.php';
$model = new SocialModel();
$res = $model->getLeaderboardWithStanding(null);
echo "Champions count: " . count($res['champions']) . "\n";
print_r($res['champions']);
echo "\nAll rankings count: " . count($res['all_rankings']) . "\n";
print_r($res['all_rankings']);
