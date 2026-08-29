<?php
// أداة سطر أوامر: php tools/data_audit.php [dbname] [school_year] — تطبع نتائج الفحص الرسمي على أي قاعدة
$dbn = $argv[1] ?? 'saint_maxime_payroll'; $sy = $argv[2] ?? '2025-2026';
require_once __DIR__ . '/../includes/data_audit.php';
$pdo = new PDO("mysql:host=localhost;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tot = 0;
foreach (dataAuditRules($pdo, $sy) as $r) {
    $tot += $r['n'];
    echo ($r['n'] ? '❌ ' : '✅ ') . str_pad((string)$r['n'], 4) . $r['key'] . ' — ' . $r['label'] . ($r['n'] ? "\n      ↳ " . implode(' · ', $r['samples']) : '') . "\n";
}
echo "═══ $dbn / $sy : $tot مخالفة ═══\n";
