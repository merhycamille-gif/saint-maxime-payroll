<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();
$cols = $db->query("SHOW COLUMNS FROM monthly_salaries")->fetchAll(PDO::FETCH_COLUMN);
echo "ms nssf-ish: " . implode(',', array_filter($cols, fn($c)=>stripos($c,'nssf')!==false || stripos($c,'cnss')!==false || stripos($c,'sick')!==false || stripos($c,'family')!==false || stripos($c,'eos')!==false)) . "\n";
echo "ms all: " . implode(',', $cols) . "\n";
$ecols = $db->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
echo "\nemp nssf-ish: " . implode(',', array_filter($ecols, fn($c)=>stripos($c,'nssf')!==false || stripos($c,'birth')!==false || stripos($c,'depart')!==false || stripos($c,'left')!==false)) . "\n";
echo "\nsettings nssf-ish:\n";
foreach ($db->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE '%nssf%' OR `key` LIKE '%cnss%' OR `key` LIKE '%ceiling%' OR `key` LIKE '%plafond%'")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  {$r['key']} = " . mb_substr($r['value'],0,120) . "\n";
