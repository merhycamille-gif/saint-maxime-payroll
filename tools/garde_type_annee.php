<?php
/**
 * 🛡️ حارس «صفة الموظف حسب السنة» (2026-11-27 — المدير: «صلّح كل البرنامج، ما بقبل بقى أخطاء»). قراءة فقط، CLI.
 * مَن كان ملاكاً وصار متعاقداً من سنة (contract_from_sy): بسنواته قبلها يبقى «ملاك» بكل استعلام وكل صفّ مجلوب، وبعدها متعاقد.
 * يُستدعى من regression_check (بعملية مستقلّة لأنّه يعلن سنة الصفحة).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$P = dirname(__DIR__); chdir($P); require_once $P . '/config/database.php'; require_once $P . '/includes/functions.php'; require_once $P . '/includes/cadre_due.php';
$db = getDB(); cadreDueEnsureColumns($db); $bad = []; $n = 0;
$L = $db->query("SELECT id, employee_type, contract_from_sy FROM employees WHERE contract_from_sy IS NOT NULL AND contract_from_sy <> ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($L as $e) { $n++; $id = (int)$e['id']; $c = $e['contract_from_sy']; $av = sprintf('%04d-%04d', (int)substr($c, 0, 4) - 1, (int)substr($c, 0, 4));
    if ($e['employee_type'] === 'enseignant_titulaire') { $bad[] = "#$id ملاك وعنده contract_from_sy"; continue; }
    if (empTypeInYear($e, $av) !== 'enseignant_titulaire' || empTypeInYear($e, $c) !== $e['employee_type']) $bad[] = "#$id empTypeInYear";
    if (empFundKind($db->query("SELECT * FROM employees WHERE id = $id")->fetch(PDO::FETCH_ASSOC), $c) !== 'contract') $bad[] = "#$id صندوق بعد التحوّل ≠ متعاقد";
    // بسنة الصفّ: كل شهر حُسم فيه للصندوق قبل التحوّل = ملاك
    $x = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id WHERE e.id = $id AND ms.caisse_amount_lbp > 0 AND " . empTypeAtSql('e.', 'ms.school_year') . " <> 'enseignant_titulaire'")->fetchColumn();
    if ($x) $bad[] = "#$id: $x شهراً بصندوق محسوم ويُعَدّ غير ملاك";
}
// بسنة الصفحة المعلَنة: الاستعلام + الصفّ المجلوب
foreach ($L as $e) { if ($e['employee_type'] === 'enseignant_titulaire') continue; $id = (int)$e['id']; $c = $e['contract_from_sy']; $av = sprintf('%04d-%04d', (int)substr($c, 0, 4) - 1, (int)substr($c, 0, 4));
    foreach ([[$av, 'enseignant_titulaire'], [$c, $e['employee_type']]] as [$sy, $want]) { msaTypeYear($sy);
        $a = $db->query("SELECT " . empTypeAtSql('e.') . " t FROM employees e WHERE e.id = $id")->fetchColumn();
        $r = $db->query("SELECT * FROM employees WHERE id = $id")->fetch(); $all = $db->query("SELECT e.* FROM employees e WHERE e.id = $id")->fetchAll(); $it = null; foreach ($db->query("SELECT * FROM employees WHERE id = $id") as $row) $it = $row;
        $f = (int)$db->query("SELECT COUNT(*) FROM employees e WHERE e.id = $id" . empTypeSqlFrom($db, ['sel' => [$want], 'all' => false, 'none' => false]))->fetchColumn();
        if ($a !== $want || $r['employee_type'] !== $want || $all[0]['employee_type'] !== $want || $it['employee_type'] !== $want || $f !== 1) $bad[] = "#$id سنة $sy: استعلام=$a صفّ={$r['employee_type']} فلتر=$f (المطلوب $want)"; } }
echo ($bad ? "❌ حارس صفة السنة: " . count($bad) . " — " . implode(' | ', array_slice($bad, 0, 6)) : "✅ حارس صفة السنة: $n موظفاً تحوّلوا من ملاك، كل سنة بصفتها") . "\n"; exit($bad ? 1 : 0);
