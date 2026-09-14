<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get_dept_execution_matrix';
ob_start();
require __DIR__ . '/../api/reports.php';
$out = ob_get_clean();
echo $out;
