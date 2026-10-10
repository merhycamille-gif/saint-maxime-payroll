<?php
/**
 * 👥 المكرّرون (2026-10-10 «اعمول حسب معرفتك»): موظفون فاعلون بنفس الاسم بنفس المدرسة (غالباً دخلوا مرّتين عبر رابط الأستاذ الجديد).
 * الصفحة تعرض فقط — الحذف يمرّ بصفحة تأكيد الموظف نفسها (employees.php?action=delete) بقاعدة «أي محي يسألني قبل».
 * النسخة التي يُقترح حذفها = التي بلا أي راتب محسوب وبأقلّ معلومات؛ إن كانت النسختان متساويتين يُترك القرار للمستخدم.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$currentPage = 'duplicates';
$pageTitle = 'Doublons / المكرّرون';
$hideExportToolbar = true;
$db = getDB();

$sy = activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear();
$rows = $db->query("SELECT e.*, (SELECT COUNT(*) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) nsal,
        (SELECT MAX(CONCAT(m.year, '-', LPAD(m.month, 2, '0'))) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) lastm
        FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif'" . schoolScopeSql('e.school_id') . " ORDER BY e.school_id, e.id")->fetchAll();
$norm = fn($s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim(preg_replace('/[^\p{L}\s]/u', '', (string)$s))));
$groups = [];
foreach ($rows as $r) {
    $kFr = $norm($r['first_name_fr'] . ' ' . $r['last_name_fr']);
    $kAr = $norm($r['first_name_ar'] . ' ' . $r['last_name_ar']);
    if ($kFr === '' && $kAr === '') continue;
    $key = $r['school_id'] . '|' . ($kFr !== '' ? $kFr : $kAr);
    $groups[$key][] = $r;
}
// فقط المجموعات التي فيها نسخة **بلا أي راتب محسوب** (الاسم دخل مرّتين وإحداهما فاضية). الأزواج التي لكل نسخة رواتبها (ملاك ثم متعاقد…) قرارها عنده من قبل ولا تُعرض.
$groups = array_filter($groups, fn($g) => count($g) > 1 && count(array_filter($g, fn($r) => (int)$r['nsal'] === 0)) > 0);
$filled = function ($r) { $n = 0; foreach (['birth_date', 'nssf_number', 'finance_ministry_number', 'phone1', 'diploma', 'social_status', 'hire_date'] as $f) if (trim((string)($r[$f] ?? '')) !== '' && $r[$f] !== '0000-00-00') $n++; if ((float)$r['base_salary_usd'] > 0 || (int)$r['contract_salary_lbp'] > 0) $n += 3; return $n; };
$curSid = (int)currentSchoolId();
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-user-group"></i> Doublons — même nom, même école (<?= count($groups) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">المكرّرون: نفس الاسم بنفس المدرسة — الحذف بيمرّ بصفحة تأكيد</div></h3></div>
    <div class="card-body">
        <?php if (!$groups): ?>
            <div class="mp-ok"><i class="fas fa-circle-check"></i> Aucun doublon / ما في مكرّرين</div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:12px"><i class="fas fa-info-circle"></i> لكل اسم نسختان أو أكتر. النسخة المقترَح شيلها (⭐ تبقى) هي اللي بلا رواتب وبأقلّ معلومات. اكبس «احذف» على النسخة الزائدة وبتفتح صفحة تأكيد؛ الحذف ناعم وبيرجع إذا لزم.</div>
        <?php foreach ($groups as $g):
            usort($g, fn($a, $b) => [(int)$b['nsal'], $filled($b), (int)$a['id']] <=> [(int)$a['nsal'], $filled($a), (int)$b['id']]);
            $keep = $g[0]; $sid = (int)$keep['school_id'];
        ?>
        <div class="card" style="margin-bottom:10px">
            <div class="card-header"><h3><span dir="ltr"><?= e(trim($keep['first_name_fr'] . ' ' . $keep['last_name_fr'])) ?></span><div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(trim($keep['first_name_ar'] . ' ' . $keep['last_name_ar'])) ?> — <?= e(schoolNameById($sid)) ?></div></h3></div>
            <div class="card-body" style="padding:8px 12px">
                <table class="table" style="margin:0">
                    <thead><tr><th>#</th><th>Code / الرمز</th><th>Type / الفئة</th><th>Embauche / الدخول</th><th>Salaires calculés / رواتب محسوبة</th><th>Infos remplies / معلومات معبّأة</th><th>Manque / الناقص</th><th class="no-print"></th></tr></thead>
                    <tbody>
                    <?php foreach ($g as $j => $r): $gaps = employeeFileGaps($r, $db, $sy); ?>
                        <tr style="<?= $j === 0 ? 'background:#ecfdf5' : '' ?>">
                            <td><?= $j === 0 ? '⭐ تبقى' : 'زائدة' ?></td>
                            <td><strong><?= e($r['employee_code']) ?></strong> <small style="color:var(--gray-500)">id <?= (int)$r['id'] ?></small></td>
                            <td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td>
                            <td><?= formatDate($r['hire_date']) ?></td>
                            <td><?= (int)$r['nsal'] ?><?= $r['lastm'] ? ' <small>(آخر ' . e($r['lastm']) . ')</small>' : '' ?></td>
                            <td><?= $filled($r) ?> / 10</td>
                            <td style="color:#92400e"><small><?= e(implode(' · ', $gaps)) ?></small></td>
                            <td class="no-print" style="white-space:nowrap">
                                <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> فتح</a>
                                <?php if ($j > 0 && (int)$r['nsal'] === 0): ?>
                                    <?php if ($curSid === $sid): ?>
                                        <a class="btn btn-sm btn-danger" href="<?= BASE_URL ?>pages/employees.php?action=delete&id=<?= (int)$r['id'] ?>"><i class="fas fa-trash"></i> احذف (بتأكيد)</a>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-warning" href="<?= BASE_URL ?>switch_school.php?school=<?= $sid ?>&back=<?= rawurlencode(BASE_URL . 'pages/duplicates.php') ?>" title="الحذف يتطلّب اختيار مدرسة الموظف أوّلاً"><i class="fas fa-school"></i> اختار مدرستها ثم احذف</a>
                                    <?php endif; ?>
                                <?php elseif ($j > 0): ?>
                                    <small style="color:var(--gray-500)">عندها رواتب — راجعها قبل أي حذف</small>
                                <?php endif; ?>
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
