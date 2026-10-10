<?php
/**
 * 👥 المكرّرون (2026-10-10 «اعمول حسب معرفتك» + بكلماته: «كل أستاذ مكرّر اسمه بنفس المدرسة بتشيل محلّ الراتب اللي مش خاضع للضمان»):
 *    نفس الاسم (فرنسي أو عربي) بنفس المدرسة، فاعلون. لكل مجموعة:
 *      ⭐ تبقى = النسخة المضمونة (حُسم عليها ضمان) — وإن تعدّدت فالأكثر رواتب؛
 *      زائدة مؤهَّلة للحذف = نسخة **بلا أي ضمان محسوم** (أو بلا رواتب أصلاً) بينما بالمجموعة نسخة مضمونة أو أكثر رواتب.
 *    الحذف لا يتمّ من هنا مباشرة: زرّ واحد يفتح صفحة تأكيد تعدّد الأسماء (includes/bulk_delete.php) — حذف ناعم مع نسخة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bulk_delete.php';
requireLogin();
$currentPage = 'duplicates';
$pageTitle = 'Doublons / المكرّرون';
$hideExportToolbar = true;
$db = getDB();

$sy = activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear();
$rows = $db->query("SELECT e.*, (SELECT COUNT(*) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) nsal,
        (SELECT COALESCE(SUM(m.cnss_amount_lbp),0) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) cn,
        (SELECT MAX(CONCAT(m.year, '-', LPAD(m.month, 2, '0'))) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) lastm
        FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif'" . schoolScopeSql('e.school_id') . " ORDER BY e.school_id, e.id")->fetchAll();
$norm = fn($s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim(preg_replace('/[^\p{L}\s]/u', '', (string)$s))));
$groups = [];
foreach ($rows as $r) {
    $kFr = $norm($r['first_name_fr'] . ' ' . $r['last_name_fr']);
    $kAr = $norm($r['first_name_ar'] . ' ' . $r['last_name_ar']);
    if ($kFr === '' && $kAr === '') continue;
    $groups[$r['school_id'] . '|' . ($kFr !== '' ? $kFr : $kAr)][] = $r;
}
$groups = array_filter($groups, fn($g) => count($g) > 1);
$eligible = []; $view = []; $eligSafe = []; $eligCur = []; $cyM = substr(currentSchoolYear(), 0, 4) . '-10';
foreach ($groups as $g) {
    // الترتيب: المضمونة أوّلاً (ضمان محسوم أكبر)، ثم الأكثر رواتب، ثم الأقدم
    usort($g, fn($a, $b) => [(float)$b['cn'] > 0, (float)$b['cn'], (int)$b['nsal'], (int)$a['id']] <=> [(float)$a['cn'] > 0, (float)$a['cn'], (int)$a['nsal'], (int)$b['id']]);
    $keep = $g[0]; $hasInsured = (float)$keep['cn'] > 0;
    $flags = [];
    foreach ($g as $j => $r) {
        if ($j === 0) { $flags[$r['id']] = 'keep'; continue; }
        $zeroCn = (float)$r['cn'] <= 0; $noSal = (int)$r['nsal'] === 0;
        if (($hasInsured && $zeroCn) || $noSal) { $flags[$r['id']] = 'del'; $eligible[(int)$r['id']] = trim($r['first_name_fr'] . ' ' . $r['last_name_fr']) . ' (' . trim($r['first_name_ar'] . ' ' . $r['last_name_ar']) . ') — ' . schoolNameById($r['school_id']) . ' — ' . $r['employee_code'] . ' — ' . employeeTypeLabel($r['employee_type'], 'ar') . ' — رواتب بلا ضمان: ' . (int)$r['nsal'] . ($r['lastm'] ? ' (آخر ' . $r['lastm'] . ')' : '') . ' — تبقى: ' . $keep['employee_code'] . ' ' . employeeTypeLabel($keep['employee_type'], 'ar');
            if ($r['lastm'] && $r['lastm'] >= $cyM) $eligCur[(int)$r['id']] = 1; else $eligSafe[(int)$r['id']] = 1; }
        else $flags[$r['id']] = 'review';
    }
    $view[] = [$g, $flags];
}
$back = BASE_URL . 'pages/duplicates.php';
if (bulkDeleteFlow($db, $eligible, 'doublon_non_assure', $back, 'Supprimer les doublons non assurés', 'حذف النسخ المكرّرة غير المضمونة')) exit;
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-user-group"></i> Doublons — même nom, même école (<?= count($view) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">المكرّرون: نفس الاسم بنفس المدرسة — المضمونة تبقى وغير المضمونة تُحذف (بتأكيد)</div></h3>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($eligSafe): ?><form method="get" action="" style="margin:0"><input type="hidden" name="action" value="bulk_delete"><?php foreach (array_keys($eligSafe) as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button type="submit" class="btn btn-danger" title="نسخ فاضية أو رواتبها قديمة فقط — آمنة"><i class="fas fa-trash"></i> احذف الزائدة الفاضية/القديمة (<?= count($eligSafe) ?>) — بتأكيد</button></form><?php endif; ?>
        <?php if ($eligCur): ?><form method="get" action="" style="margin:0"><input type="hidden" name="action" value="bulk_delete"><?php foreach (array_keys($eligCur) as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button type="submit" class="btn btn-warning" title="⚠️ هذه النسخ عندها رواتب بالسنة الحالية بلا ضمان — حذفها يُخفي رواتبها من كشوف هالسنة"><i class="fas fa-triangle-exclamation"></i> الزائدة اللي عندها رواتب هالسنة (<?= count($eligCur) ?>) — راجعها قبل</button></form><?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$view): ?>
            <div class="mp-ok"><i class="fas fa-circle-check"></i> Aucun doublon / ما في مكرّرين</div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:12px"><i class="fas fa-info-circle"></i> ⭐ تبقى = النسخة المضمونة (انحسم عليها ضمان). <b style="color:#b91c1c">زائدة</b> = نسخة بلا أي ضمان محسوم (أو بلا رواتب) ⇒ مؤهَّلة للحذف. <b style="color:#92400e">راجعها</b> = النسختان بنفس الحال (مضمونتان معاً أو بلا ضمان معاً) — قرارك.</div>
        <?php foreach ($view as [$g, $flags]): $keep = $g[0]; $sid = (int)$keep['school_id']; ?>
        <div class="card" style="margin-bottom:10px">
            <div class="card-header"><h3><span dir="ltr"><?= e(trim($keep['first_name_fr'] . ' ' . $keep['last_name_fr'])) ?></span><div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(trim($keep['first_name_ar'] . ' ' . $keep['last_name_ar'])) ?> — <?= e(schoolNameById($sid)) ?></div></h3></div>
            <div class="card-body" style="padding:8px 12px">
                <table class="table" style="margin:0">
                    <thead><tr><th>#</th><th>Code / الرمز</th><th>Type / الفئة</th><th>Embauche / الدخول</th><th>Salaires / رواتب</th><th>CNSS retenue / ضمان محسوم</th><th>Manque / الناقص</th><th class="no-print"></th></tr></thead>
                    <tbody>
                    <?php foreach ($g as $r): $f = $flags[$r['id']]; $gaps = employeeFileGaps($r, $db, $sy); ?>
                        <tr style="<?= $f === 'keep' ? 'background:#ecfdf5' : ($f === 'del' ? 'background:#fff1f2' : '') ?>">
                            <td><?= $f === 'keep' ? '⭐ تبقى' : ($f === 'del' ? '<b style="color:#b91c1c">زائدة</b>' : '<b style="color:#92400e">راجعها</b>') ?></td>
                            <td><strong><?= e($r['employee_code']) ?></strong> <small style="color:var(--gray-500)">id <?= (int)$r['id'] ?></small></td>
                            <td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td>
                            <td><?= formatDate($r['hire_date']) ?></td>
                            <td><?= (int)$r['nsal'] ?><?= $r['lastm'] ? ' <small>(آخر ' . e($r['lastm']) . ')</small>' : '' ?><?php if ($f === 'del' && $r['lastm'] && $r['lastm'] >= substr(currentSchoolYear(), 0, 4) . '-10'): ?><br><span class="badge badge-danger">⚠️ عندها رواتب هالسنة — بتختفي من الكشوف</span><?php endif; ?></td>
                            <td><?= (float)$r['cn'] > 0 ? '<span class="badge badge-success">مضمون · ' . formatLBP($r['cn'], false) . '</span>' : '<span class="badge badge-warning">بلا ضمان</span>' ?></td>
                            <td style="color:#92400e"><small><?= e(implode(' · ', $gaps)) ?></small></td>
                            <td class="no-print" style="white-space:nowrap">
                                <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> فتح</a>
                                <?php if ($f === 'del'): ?><a class="btn btn-sm btn-danger" href="?action=bulk_delete&ids[]=<?= (int)$r['id'] ?>"><i class="fas fa-trash"></i> احذف (بتأكيد)</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
