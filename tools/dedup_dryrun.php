<?php
// أداة سطر أوامر: php tools/dedup_dryrun.php [dbname] [apply] — معاينة (أو تنفيذ مع apply) تنظيف المكرّرين بنفس المدرسة
$dbn = $argv[1] ?? 'saint_maxime_payroll';
$apply = ($argv[2] ?? '') === 'apply';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = new PDO("mysql:host=localhost;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = dedupSameSchool2526($pdo, !$apply);
echo ($apply ? "═══ تنفيذ فعلي ═══\n" : "═══ معاينة (بلا كتابة) ═══\n");
echo "مجموعات: {$r['groups']} · ملفات تُشال: {$r['removed']} · صفوف تُنقَل: {$r['moved']} · صفوف مكرّرة تُحذف: {$r['dropped']} · بنود تُطفأ: {$r['bonOff']}\n";
foreach ($r['skipped'] as $s) echo "⏭️  $s\n";
foreach ($r['log'] as $l) echo "🗑  $l\n";
