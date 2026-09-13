<?php
require_once __DIR__ . '/../config/config.php';
$db = getSupabaseDb();
$rows = $db->query("SELECT * FROM public.xp_ledger ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($rows) . "\n";
print_r($rows);
