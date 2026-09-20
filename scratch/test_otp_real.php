<?php
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController();
$res = $auth->requestOtp(['email' => 'hresources771@gmail.com', 'full_name' => 'HR Admin']);
echo json_encode($res, JSON_PRETTY_PRINT) . PHP_EOL;
