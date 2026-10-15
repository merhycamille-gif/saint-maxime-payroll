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
        (SELECT MAX(CONCAT(m.year, '-', LPAD(m.month, 2, '0'))) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) lastm,
        (SELECT MIN(CONCAT(m.year, '-', LPAD(m.month, 2, '0'))) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) firstm,
        " . leftDateSql('e.') . " ld
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
        // 🚪↩️ (2026-10-15) «عاد بعد ترك»: الأصلي المضمون تارك (تاريخ ترك) والنسخة الجديدة رواتبها بعد تركه ⇒ ليس مكرّراً بل ملف جديد
        //    لفترة عمل جديدة — لا حذف ولا دمج (الدمج في ملف تارك يمحو رواتبه فوراً: heal_leaver_phantoms)
        $keepLeft = ($keep['ld'] ?? '9999-12-31') !== '9999-12-31' ? substr($keep['ld'], 0, 7) : null;
        if ($keepLeft && !$noSal && $r['firstm'] && $r['firstm'] > $keepLeft) { $flags[$r['id']] = 'returned'; continue; }
        if (($hasInsured && $zeroCn) || $noSal) { $flags[$r['id']] = 'del'; $eligible[(int)$r['id']] = trim($r['first_name_fr'] . ' ' . $r['last_name_fr']) . ' (' . trim($r['first_name_ar'] . ' ' . $r['last_name_ar']) . ') — ' . schoolNameById($r['school_id']) . ' — ' . $r['employee_code'] . ' — ' . employeeTypeLabel($r['employee_type'], 'ar') . ' — رواتب بلا ضمان: ' . (int)$r['nsal'] . ($r['lastm'] ? ' (آخر ' . $r['lastm'] . ')' : '') . ' — تبقى: ' . $keep['employee_code'] . ' ' . employeeTypeLabel($keep['employee_type'], 'ar');
            if ($r['lastm'] && $r['lastm'] >= $cyM) $eligCur[(int)$r['id']] = 1; else $eligSafe[(int)$r['id']] = 1;
            $keepOf[(int)$r['id']] = (int)$keep['id']; }
        else $flags[$r['id']] = 'review';
    }
    $view[] = [$g, $flags];
}
$back = BASE_URL . 'pages/duplicates.php';
// 🔗 (2026-10-15 «الاسم المكرّر بنفس المدرسة منشيلو… عمول الشغل انت»): دمج الزائدة بالملف الأصلي بدل حذفها وضياع رواتبها —
//    رواتب الزائدة للأشهر التي لا يملكها الأصلي تنتقل إليه، والأشهر المكرّرة تُحذف من الزائدة، والمستندات تنتقل، ثم تُحذف الزائدة
//    نهائياً (نفس bulkDeleteHard). لا دمج لأي زائدة فيها شهر مدفوع (تُتخطّى وتُذكر). صفحة تأكيد بالأسماء قبل التنفيذ.
if (($_GET['action'] ?? '') === 'merge') {
    requireWriteAction();
    $mIds = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? $_GET['ids'] ?? [])), fn($i) => isset($eligible[$i]) && isset($keepOf[$i])));
    if (!$mIds) { $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'لا أحد مؤهَّل للدمج / Rien à fusionner']; header('Location: ' . $back); exit; }
    $nameOf = function (int $id) use ($db): string { $r = $db->prepare("SELECT first_name_fr, last_name_fr, employee_code FROM employees WHERE id = ?"); $r->execute([$id]); $r = $r->fetch(); return $r ? trim($r['first_name_fr'] . ' ' . $r['last_name_fr']) . ' #' . $r['employee_code'] . ' (id ' . $id . ')' : ('id ' . $id); };
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirmed'])) {
        $done = 0; $moved = 0; $dropped = 0; $skipped = [];
        $tables = $db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'employee_id' AND TABLE_NAME NOT IN ('employees','monthly_salaries','employee_bonuses','info_submissions') AND TABLE_NAME NOT LIKE 'smp\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($mIds as $x) {
            $k = $keepOf[$x];
            $paid = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $x AND is_paid = 1")->fetchColumn();
            if ($paid > 0) { $skipped[] = $nameOf($x) . " — فيه $paid شهراً مدفوعاً"; continue; }
            $kl = (string)$db->query("SELECT " . leftDateSql() . " FROM employees WHERE id = $k")->fetchColumn();   // 🚪 الأصلي تارك ⇒ لا دمج (رواتبه تُمحى بالشفاء)
            if ($kl !== '' && $kl !== '9999-12-31') { $skipped[] = $nameOf($x) . ' — الأصلي تارك بتاريخ ' . substr($kl, 0, 10) . ' (عودة بعد ترك: ليس مكرّراً)'; continue; }
            $db->beginTransaction();
            try {
                // أشهر الزائدة غير الموجودة عند الأصلي ⇒ تنتقل؛ المكرّرة ⇒ تُحذف من الزائدة
                $st = $db->prepare("UPDATE monthly_salaries x LEFT JOIN monthly_salaries k ON k.employee_id = ? AND k.year = x.year AND k.month = x.month SET x.employee_id = ? WHERE x.employee_id = ? AND k.id IS NULL");
                $st->execute([$k, $k, $x]); $moved += $st->rowCount();
                $st = $db->prepare("DELETE FROM monthly_salaries WHERE employee_id = ?"); $st->execute([$x]); $dropped += $st->rowCount();
                // العلاوات: تنتقل فقط إن لم يكن للأصلي أي علاوة بنفس السنة الدراسية (وإلّا تُحذف مع الزائدة لئلا تتضاعف)
                $st = $db->prepare("UPDATE employee_bonuses x SET x.employee_id = ? WHERE x.employee_id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT school_year FROM employee_bonuses WHERE employee_id = ?) kk WHERE kk.school_year = x.school_year)");
                $st->execute([$k, $x, $k]);
                $db->prepare("UPDATE info_submissions SET employee_id = ? WHERE employee_id = ?")->execute([$k, $x]);
                foreach ($tables as $t) { try { $db->prepare("UPDATE `$t` SET employee_id = ? WHERE employee_id = ?")->execute([$k, $x]); } catch (Throwable $e) {} }   // مستندات/سجلّات ⇒ للأصلي
                logAudit('merge', 'employees', $x, $nameOf($x) . ' ⇒ ' . $nameOf($k), 'doublon fusionné (salaires non chevauchants déplacés)');
                bulkDeleteHard($db, $x);
                $db->commit(); $done++;
            } catch (Throwable $e) { $db->rollBack(); $skipped[] = $nameOf($x) . ' — خطأ: ' . $e->getMessage(); }
        }
        try { foreach (array_unique(array_map(fn($x) => $keepOf[$x], $mIds)) as $k) { if (function_exists('invalidateEmployeeCaches')) invalidateEmployeeCaches($k); } } catch (Throwable $e) {}
        $_SESSION['flash'] = ['type' => $skipped ? 'warning' : 'success', 'msg' => "تم دمج $done ملفاً مكرّراً بالأصلي: انتقل $moved شهراً من الرواتب وحُذف $dropped شهراً مكرّراً" . ($skipped ? ' — تُخطّي: ' . implode(' · ', $skipped) : '')];
        header('Location: ' . $back); exit;
    }
    $pageTitle = 'Confirmer la fusion / تأكيد الدمج'; $hideExportToolbar = true;
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:900px;margin:30px auto;border:2px solid #d97706">
        <div class="card-body" style="padding:24px">
            <div style="text-align:center"><div style="font-size:44px;margin-bottom:6px">🔗</div>
                <h3 style="margin:0 0 4px"><span dir="ltr">Fusionner les doublons</span> / دمج المكرّرين بالملف الأصلي</h3>
                <p style="color:#92400e;margin:4px 0 14px;font-weight:700">رواتب النسخة الزائدة للأشهر الناقصة عند الأصلي تنتقل إليه، والأشهر المكرّرة تُحذف، ثم تُحذف الزائدة نهائياً. أي زائدة فيها شهر مدفوع تُتخطّى.</p></div>
            <form method="post" action="?action=merge">
                <input type="hidden" name="confirmed" value="1">
                <div class="table-wrapper"><table class="table" style="margin-bottom:14px"><thead><tr><th>#</th><th>الزائدة (تُدمج وتُحذف)</th><th>⇒ الملف الأصلي (يبقى)</th><th>أشهر تنتقل</th><th>أشهر مكرّرة تُحذف</th></tr></thead><tbody>
                <?php foreach ($mIds as $i => $x): $k = $keepOf[$x];
                    $mv = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries x LEFT JOIN monthly_salaries k ON k.employee_id = $k AND k.year = x.year AND k.month = x.month WHERE x.employee_id = $x AND k.id IS NULL")->fetchColumn();
                    $ov = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries x JOIN monthly_salaries k ON k.employee_id = $k AND k.year = x.year AND k.month = x.month WHERE x.employee_id = $x")->fetchColumn(); ?>
                    <tr><td><?= $i + 1 ?></td><td><strong><?= e($nameOf($x)) ?></strong><input type="hidden" name="ids[]" value="<?= (int)$x ?>"></td><td><?= e($nameOf($k)) ?></td><td><?= $mv ?></td><td><?= $ov ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <div class="d-flex gap-2" style="justify-content:center">
                    <a href="<?= e($back) ?>" class="btn btn-light btn-lg"><i class="fas fa-arrow-left"></i> لا، رجوع / Non</a>
                    <button type="submit" class="btn btn-warning btn-lg"><i class="fas fa-link"></i> نعم، ادمج الـ<?= count($mIds) ?> / Oui, fusionner</button>
                </div>
            </form>
        </div>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; exit;
}
if (bulkDeleteFlow($db, $eligible, 'doublon_non_assure', $back, 'Supprimer les doublons non assurés', 'حذف النسخ المكرّرة غير المضمونة')) exit;
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-user-group"></i> Doublons — même nom, même école (<?= count($view) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">المكرّرون: نفس الاسم بنفس المدرسة — المضمونة تبقى وغير المضمونة تُحذف (بتأكيد)</div></h3>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($eligSafe): ?><form method="get" action="" style="margin:0"><input type="hidden" name="action" value="bulk_delete"><?php foreach (array_keys($eligSafe) as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button type="submit" class="btn btn-danger" title="نسخ فاضية أو رواتبها قديمة فقط — آمنة"><i class="fas fa-trash"></i> احذف الزائدة الفاضية/القديمة (<?= count($eligSafe) ?>) — بتأكيد</button></form><?php endif; ?>
        <?php if ($eligCur): ?><form method="get" action="" style="margin:0"><input type="hidden" name="action" value="bulk_delete"><?php foreach (array_keys($eligCur) as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button type="submit" class="btn btn-warning" title="⚠️ هذه النسخ عندها رواتب بالسنة الحالية بلا ضمان — حذفها يُخفي رواتبها من كشوف هالسنة"><i class="fas fa-triangle-exclamation"></i> الزائدة اللي عندها رواتب هالسنة (<?= count($eligCur) ?>) — راجعها قبل</button></form><?php endif; ?>
        <?php if ($eligCur): ?><form method="get" action="" style="margin:0"><input type="hidden" name="action" value="merge"><?php foreach (array_keys($eligCur) as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button class="btn btn-sm btn-warning" title="رواتب الزائدة للأشهر الناقصة تنتقل للأصلي ثم تُحذف الزائدة"><i class="fas fa-link"></i> 🔗 ادمج اللي عندها رواتب بالملف الأصلي (<?= count($eligCur) ?>) — بتأكيد</button></form><?php endif; ?>
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
                            <td><?= $f === 'keep' ? '⭐ تبقى' : ($f === 'del' ? '<b style="color:#b91c1c">زائدة</b>' : ($f === 'returned' ? '<b style="color:#0a6b5e">↩️ عاد بعد ترك — ملف جديد (ليس مكرّراً)</b>' : '<b style="color:#92400e">راجعها</b>')) ?></td>
                            <td><strong><?= e($r['employee_code']) ?></strong> <small style="color:var(--gray-500)">id <?= (int)$r['id'] ?></small></td>
                            <td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td>
                            <td><?= formatDate($r['hire_date']) ?></td>
                            <td><?= (int)$r['nsal'] ?><?= $r['lastm'] ? ' <small>(آخر ' . e($r['lastm']) . ')</small>' : '' ?><?php if ($f === 'del' && $r['lastm'] && $r['lastm'] >= substr(currentSchoolYear(), 0, 4) . '-10'): ?><br><span class="badge badge-danger">⚠️ عندها رواتب هالسنة — بتختفي من الكشوف</span><?php endif; ?></td>
                            <td><?= (float)$r['cn'] > 0 ? '<span class="badge badge-success">مضمون · ' . formatLBP($r['cn'], false) . '</span>' : '<span class="badge badge-warning">بلا ضمان</span>' ?></td>
                            <td style="color:#92400e"><small><?= e(implode(' · ', $gaps)) ?></small></td>
                            <td class="no-print" style="white-space:nowrap">
                                <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> فتح</a>
                                <?php if ($f === 'del'): ?><a class="btn btn-sm btn-warning" href="?action=merge&ids[]=<?= (int)$r['id'] ?>" title="ادمج بالملف الأصلي (الرواتب الناقصة تنتقل)"><i class="fas fa-link"></i> ادمج</a> <a class="btn btn-sm btn-danger" href="?action=bulk_delete&ids[]=<?= (int)$r['id'] ?>"><i class="fas fa-trash"></i> احذف (بتأكيد)</a><?php endif; ?>
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
