<?php
/**
 * 🔤 فحص الأسماء (2026-11-02 — لاحظ بلائحة الموظفين «YSaidi Michel Nassar» و«Abou zgheib»):
 *    لائحة للمراجعة فقط — لا تعديل تلقائي على الداتا («أرزاق الناس»): أسماء الموظفين الفاعلين بالسنة المعروضة
 *    التي فيها شكل غلط: حرف كبير بنصّ الكلمة، كلمة تبدأ بحرف صغير، فراغ مزدوج/زائد، رقم أو رمز غريب، حروف عربية بالاسم
 *    الفرنسي أو لاتينية بالعربي، اسم عربي ناقص. كل سطر فيه كبسة تفتح ملف الموظف للتصحيح باليد.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$currentPage = 'reports';
$pageTitle = 'Vérification des noms / فحص الأسماء';
$hideExportToolbar = true;
$db = getDB();

$sy = activeSchoolYear();
[$yf, $yp] = yearEmploymentFilter($sy, 'e.');
$st = $db->prepare("SELECT e.* FROM employees e WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . $yf
    . " ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), e.last_name_fr, e.first_name_fr");
$st->execute($yp);
$rows = $st->fetchAll();

/** يفحص اسماً لاتينياً ويرجع لائحة المشاكل (فارغة = سليم) */
function nameIssuesFr(string $s): array {
    $out = [];
    $t = $s;
    if ($t === '') return $out;
    if ($t !== trim($t)) $out[] = 'فراغ بالأوّل/الآخر';
    if (preg_match('/\s{2,}/u', $t)) $out[] = 'فراغ مزدوج';
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $t)) $out[] = 'حروف عربية بالاسم الفرنسي';
    if (preg_match('/\d/u', $t)) $out[] = 'فيه رقم';
    if (preg_match('/[^\p{L}\p{M}\s\'’\-\.]/u', $t)) $out[] = 'رمز غريب';
    foreach (preg_split('/[\s\-]+/u', trim($t)) as $w) {
        if ($w === '' || mb_strlen($w) < 2) continue;
        $w2 = preg_replace('/[\'’\.]/u', '', $w);
        if ($w2 === '') continue;
        // حرف كبير بنصّ الكلمة (YSaidi, McDo مستثنى عملياً نادر) — نتجاهل الكلمة المكتوبة كلّها بالكبير (SAID)
        $isAllCaps = mb_strtoupper($w2) === $w2;
        if (!$isAllCaps && preg_match('/^\p{L}\p{Ll}*\p{Lu}/u', $w2)) $out[] = 'حرف كبير بنصّ الكلمة: «' . $w . '»';
        if (preg_match('/^\p{Ll}/u', $w2) && !in_array(mb_strtolower($w2), ['de', 'el', 'al', 'du', 'des', 'le', 'la', 'von', 'van', 'bou', 'abou', 'abi', 'ibn', 'bin', 'ben', 'di', 'da'], true)) $out[] = 'يبدأ بحرف صغير: «' . $w . '»';
    }
    return array_values(array_unique($out));
}
function nameIssuesAr(string $s): array {
    $out = [];
    if ($s === '') return ['الاسم العربي ناقص'];
    if ($s !== trim($s)) $out[] = 'فراغ بالأوّل/الآخر';
    if (preg_match('/\s{2,}/u', $s)) $out[] = 'فراغ مزدوج';
    if (preg_match('/[A-Za-z]/u', $s)) $out[] = 'حروف لاتينية بالاسم العربي';
    if (preg_match('/\d/u', $s)) $out[] = 'فيه رقم';
    if (preg_match('/[^\p{L}\p{M}\s\-]/u', $s)) $out[] = 'رمز غريب';
    return array_values(array_unique($out));
}

$found = [];
foreach ($rows as $r) {
    $fr = trim(implode(' ', array_filter([$r['first_name_fr'], $r['father_name_fr'], $r['last_name_fr']])));
    $ar = trim(implode(' ', array_filter([$r['first_name_ar'], $r['father_name_ar'], $r['last_name_ar']])));
    $iFr = []; foreach (['first_name_fr', 'father_name_fr', 'last_name_fr'] as $f) foreach (nameIssuesFr((string)($r[$f] ?? '')) as $x) $iFr[] = $x;
    $iAr = []; foreach (['first_name_ar', 'father_name_ar', 'last_name_ar'] as $f) { $v = (string)($r[$f] ?? ''); if ($f === 'father_name_ar' && $v === '') continue; foreach (nameIssuesAr($v) as $x) $iAr[] = $x; }
    if ($ar === '') { $iAr = ['الاسم العربي ناقص']; }
    $iFr = array_values(array_unique($iFr)); $iAr = array_values(array_unique($iAr));
    if (!$iFr && !$iAr) continue;
    $found[] = ['r' => $r, 'fr' => $fr, 'ar' => $ar, 'iFr' => $iFr, 'iAr' => $iAr];
}
$multi = currentSchool() === null;
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-spell-check"></i> Vérification des noms — <?= count($found) ?> / <?= count($rows) ?></span><div style="font-size:0.85em;font-weight:600;opacity:0.9">فحص الأسماء: <?= count($found) ?> من أصل <?= count($rows) ?> موظفاً بسنة <span dir="ltr"><?= e($sy === 'all' ? 'كل السنين' : $sy) ?></span> — للمراجعة فقط، التصحيح من ملف الموظف</div></h3>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>pages/reports.php?report=employee_list"><i class="fas fa-arrow-left"></i> Retour à la liste / رجوع للائحة</a></div>
    <div class="card-body">
        <?php if (!$found): ?>
            <div class="alert alert-success" style="font-size:1.05em"><i class="fas fa-circle-check"></i> Tous les noms sont bien écrits / كل الأسماء سليمة الشكل ✔</div>
        <?php else: ?>
        <div class="table-wrapper"><table class="table doc-table" dir="rtl">
            <thead><tr><th>#</th><?php if ($multi): ?><th>المدرسة / École</th><?php endif; ?><th>الرمز / Code</th><th>الاسم الفرنسي / Nom (FR)</th><th>الاسم العربي / Nom (AR)</th><th>الملاحظة / Remarque</th><th></th></tr></thead>
            <tbody>
            <?php $n = 0; foreach ($found as $f): $r = $f['r']; ?>
                <tr>
                    <td><?= ++$n ?></td>
                    <?php if ($multi): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                    <td><strong><?= e($r['employee_code']) ?></strong></td>
                    <td dir="ltr" style="text-align:left<?= $f['iFr'] ? ';color:#b91c1c;font-weight:700' : '' ?>"><?= e($f['fr']) ?: '—' ?></td>
                    <td style="<?= $f['iAr'] ? 'color:#b91c1c;font-weight:700' : '' ?>"><?= e($f['ar']) ?: '—' ?></td>
                    <td><small><?= e(implode(' · ', array_merge($f['iFr'], $f['iAr']))) ?></small></td>
                    <td><a class="btn btn-sm btn-warning" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> Corriger / صحّح</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
