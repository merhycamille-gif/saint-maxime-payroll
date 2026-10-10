<?php
/**
 * 🧹 القدامى غير المضمونين (2026-10-10 بكلماته: «الأساتذة اللي قبل 2025-2026 ولورا ما في براتبهن ضريبة على الضمان شيلهن»
 *    + «في فرق بين اللي كان بالضمان وترك واللي ما كان مضمون أبداً»):
 *    مَن ليس فاعلاً بالسنة الدراسية الحالية (لا راتب فيها وترك قبلها) **ولم يُحسم عليه ضمان ولا شهراً واحداً** بكل رواتبه.
 *    مَن حُسم عليه ضمان ولو مرّة = كان مضموناً ⇒ يبقى تاركاً عادياً ولا يظهر هنا.
 *    الصفحة تعرض فقط — الحذف يمرّ بصفحة تأكيد الموظف نفسها (قاعدة «أي محي يسألني قبل»)، ويلزم اختيار مدرسته (الزرّ يفعلها).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bulk_delete.php';
requireLogin();
$currentPage = 'old_uninsured';
$pageTitle = 'Anciens non assurés / القدامى غير المضمونين';
$hideExportToolbar = true;
$db = getDB();
$cy = currentSchoolYear(); $cyStart = substr($cy, 0, 4) . '-10-01'; $prevSy = ((int)substr($cy, 0, 4) - 1) . '-' . substr($cy, 0, 4);
$rows = $db->prepare("SELECT e.id, e.employee_code, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.school_id, e.employee_type, e.status, e.hire_date, " . leftDateSql() . " ld, e.nssf_number,
        SUM(m.cnss_amount_lbp) cn, SUM(m.income_tax_lbp) tx, COUNT(*) k, GROUP_CONCAT(DISTINCT m.school_year ORDER BY m.school_year) sys,
        (SELECT COUNT(*) FROM monthly_salaries x WHERE x.employee_id = e.id AND x.school_year = ? AND x.is_calculated = 1) scur
        FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.is_calculated = 1
        WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . "
        GROUP BY e.id HAVING cn = 0 AND SUM(m.school_year <= ?) > 0 ORDER BY e.school_id, e.first_name_fr, e.last_name_fr"); // بكلماته «قبل 2025-2026 ولورا»: له رواتب بسنة سابقة
$rows->execute([$cy, $prevSy]);
// بكلماته (2026-10-10): «كل أستاذ ببطاقته ما في ضريبة ضمان قبل 2025-2026 احذفه» ⇒ المعيار = ضمان صفر بكل رواتبه، وبلا راتب بالسنة الحالية
//   (حتى لو ملفه «فاعل» بلا تاريخ ترك — آخر راتب له 2024-2025 أو أقدم). مَن يقبض هالسنة بلا ضمان يُعرض لحاله بتحذير (قراره واحداً واحداً).
$list = []; $curList = [];
foreach ($rows->fetchAll() as $r) { if ((int)$r['scur'] > 0) $curList[] = $r; else $list[] = $r; }
$eligible = [];
foreach (array_merge($list, $curList) as $r) $eligible[(int)$r['id']] = trim($r['first_name_fr'] . ' ' . $r['last_name_fr']) . ' (' . trim($r['first_name_ar'] . ' ' . $r['last_name_ar']) . ') — ' . schoolNameById((int)$r['school_id']) . ' — ' . $r['employee_code'];
$back = BASE_URL . 'pages/old_uninsured.php';
if (bulkDeleteFlow($db, $eligible, 'ancien_non_assure', $back, 'Supprimer les anciens non assurés', 'حذف القدامى غير المضمونين')) exit;
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-user-slash"></i> Anciens non assurés — jamais de CNSS retenue (<?= count($list) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">القدامى غير المضمونين: بطاقتهم بلا أي ضمان محسوم بكل سنينهم، وبلا راتب بـ<?= e($cy) ?> — الحذف بيمرّ بصفحة تأكيد</div></h3>
        <?php if ($eligible): ?><form method="get" action="" class="no-print" style="margin:0"><input type="hidden" name="action" value="bulk_delete"><?php foreach (array_keys($eligible) as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> احذف الكل (<?= count($eligible) ?>) من البرنامج والداتا — بتأكيد</button></form><?php endif; ?></div>
    <div class="card-body">
        <?php if (!$list): ?>
            <div class="mp-ok"><i class="fas fa-circle-check"></i> Aucun / ما في حدا</div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:12px"><i class="fas fa-info-circle"></i> مَن انحسم عليه ضمان ولو شهراً واحداً كان مضموناً وبيبقى (ما بيظهر هون). الملف «فاعل» بلا تاريخ ترك بس آخر راتبه 2024-2025 أو أقدم = قديم عملياً وبيظهر هون. اكبس «احذف» ثم أكّد بالصفحة التالية؛ الحذف ناعم وبيرجع إذا لزم.</div>
        <div class="table-wrapper"><table class="table">
            <thead><tr><th>Employé / الموظف</th><th>École / المدرسة</th><th>Type / الفئة</th><th>Embauche → départ / الدخول ← الترك</th><th>Salaires / الرواتب</th><th>N° CNSS</th><th class="no-print"></th></tr></thead>
            <tbody>
            <?php foreach ($list as $r): $sid = (int)$r['school_id']; ?>
                <tr>
                    <td><strong><?= e(trim($r['first_name_fr'] . ' ' . $r['last_name_fr'])) ?></strong><br><small style="color:var(--gray-500)"><?= e(trim($r['first_name_ar'] . ' ' . $r['last_name_ar'])) ?> · <?= e($r['employee_code']) ?></small></td>
                    <td><small><?= e(schoolNameById($sid)) ?></small></td>
                    <td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td>
                    <td><?= formatDate($r['hire_date']) ?> → <?= $r['ld'] < '9999-12-31' ? formatDate($r['ld']) : '<span class="badge badge-warning">' . e($r['status']) . '</span>' ?></td>
                    <td><?= (int)$r['k'] ?> شهر <small>[<?= e($r['sys']) ?>]</small><br><small style="color:var(--gray-500)">ضمان 0 · ضريبة <?= number_format((int)$r['tx']) ?></small></td>
                    <td><?= trim((string)$r['nssf_number']) !== '' && $r['nssf_number'] !== '0' ? e($r['nssf_number']) : '—' ?></td>
                    <td class="no-print" style="white-space:nowrap">
                        <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> فتح</a>
                        <a class="btn btn-sm btn-danger" href="?action=bulk_delete&ids[]=<?= (int)$r['id'] ?>"><i class="fas fa-trash"></i> احذف (بتأكيد)</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php if ($curList): ?>
<div class="card" style="border:2px solid #f59e0b">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-triangle-exclamation" style="background:#f59e0b"></i> Payés cette année sans CNSS (<?= count($curList) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">⚠️ عم يقبضوا هالسنة <?= e($cy) ?> وما انحسم عليهم ضمان أبداً — حذفهم يُخفي رواتب هالسنة من الكشوف. قرارك واحداً واحداً.</div></h3></div>
    <div class="card-body">
        <div class="table-wrapper"><table class="table">
            <thead><tr><th>Employé / الموظف</th><th>École / المدرسة</th><th>Type / الفئة</th><th>Embauche / الدخول</th><th>Salaires / الرواتب</th><th>N° CNSS</th><th class="no-print"></th></tr></thead>
            <tbody>
            <?php foreach ($curList as $r): ?>
                <tr>
                    <td><strong><?= e(trim($r['first_name_fr'] . ' ' . $r['last_name_fr'])) ?></strong><br><small style="color:var(--gray-500)"><?= e(trim($r['first_name_ar'] . ' ' . $r['last_name_ar'])) ?> · <?= e($r['employee_code']) ?></small></td>
                    <td><small><?= e(schoolNameById((int)$r['school_id'])) ?></small></td>
                    <td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td>
                    <td><?= formatDate($r['hire_date']) ?></td>
                    <td><?= (int)$r['k'] ?> شهر <small>[<?= e($r['sys']) ?>]</small> · <span class="badge badge-danger">هالسنة: <?= (int)$r['scur'] ?> شهر بلا ضمان</span></td>
                    <td><?= trim((string)$r['nssf_number']) !== '' && $r['nssf_number'] !== '0' ? e($r['nssf_number']) : '—' ?></td>
                    <td class="no-print" style="white-space:nowrap">
                        <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> فتح</a>
                        <a class="btn btn-sm btn-warning" href="?action=bulk_delete&ids[]=<?= (int)$r['id'] ?>"><i class="fas fa-trash"></i> احذف (بتأكيد)</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
