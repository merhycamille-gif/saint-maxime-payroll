<?php
/**
 * 🛡️ متابعة الضمان الاجتماعي (2026-10-10 بكلماته: «هيدا لازم يكون ضمن البرنامج لأنو لازم أعرف دايماً شو في وصحّح»):
 *   ١) من 2025-2026 وطالع بلا أي ضمان محسوم ⇒ قراره لكل واحد: «يبقى (غير خاضع)» (cnss_subject=0 فيختفي من اللائحة) أو «يُشال» (حذف نهائي بتأكيد)
 *   ٢) عليهم ضمان محسوم وبلا رقم ضمان ⇒ خانة لكتابة الرقم وحفظه فوراً
 *   ٣) عليهم ضمان محسوم وبلا رقم ضمان ولا رقم مالية ⇒ خانتان
 *   كل حفظ = UPDATE على ملف الموظف فقط (لا يمسّ أي راتب) + logAudit. المصدر: الرواتب المحسوبة (cnss_amount_lbp).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bulk_delete.php';
requireLogin();
$currentPage = 'cnss_followup';
$pageTitle = 'Suivi CNSS / متابعة الضمان';
$hideExportToolbar = true;
$db = getDB();
$self = BASE_URL . 'pages/cnss_followup.php';
$hasNum = fn($v) => trim((string)$v) !== '' && trim((string)$v) !== '0';

// ── حفظ (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do'])) {
    requireWriteAction($self);
    if (!verifyCsrf($_POST['csrf'] ?? '')) { $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'جلسة منتهية — أعد المحاولة']; header('Location: ' . $self); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $cur = $db->prepare("SELECT id, nssf_number, finance_ministry_number, cnss_subject, first_name_fr, last_name_fr FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $cur->execute([$id]); $emp = $cur->fetch();
    if ($emp) {
        if ($_POST['do'] === 'numbers') {
            $n = trim((string)($_POST['nssf'] ?? '')); $f = trim((string)($_POST['finance'] ?? ''));
            $set = []; $par = [];
            if ($n !== '') { $set[] = 'nssf_number = ?'; $par[] = $n; }
            if ($f !== '') { $set[] = 'finance_ministry_number = ?'; $par[] = $f; }
            if ($set) { $par[] = $id; $db->prepare("UPDATE employees SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
                logAudit('update', 'employees', $id, json_encode(['nssf' => $emp['nssf_number'], 'fin' => $emp['finance_ministry_number']]), json_encode(['nssf' => $n ?: $emp['nssf_number'], 'fin' => $f ?: $emp['finance_ministry_number']], JSON_UNESCAPED_UNICODE));
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تم الحفظ / Enregistré: ' . trim($emp['first_name_fr'] . ' ' . $emp['last_name_fr'])]; }
        } elseif ($_POST['do'] === 'keep') {
            // «يبقى»: غير خاضع للضمان بقراره ⇒ يختفي من لائحة ١ (الملف والرواتب لا تُمسّ)
            $db->prepare("UPDATE employees SET cnss_subject = 0 WHERE id = ?")->execute([$id]);
            logAudit('update', 'employees', $id, 'cnss_subject=' . (int)$emp['cnss_subject'], 'cnss_subject=0 (يبقى — غير خاضع بقراره)');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'بيبقى كغير خاضع للضمان: ' . trim($emp['first_name_fr'] . ' ' . $emp['last_name_fr'])];
        } elseif ($_POST['do'] === 'unkeep') {
            $db->prepare("UPDATE employees SET cnss_subject = 1 WHERE id = ?")->execute([$id]);
            logAudit('update', 'employees', $id, 'cnss_subject=0', 'cnss_subject=1 (رجع للائحة)');
            $_SESSION['flash'] = ['type' => 'info', 'msg' => 'رجع للائحة: ' . trim($emp['first_name_fr'] . ' ' . $emp['last_name_fr'])];
        }
    }
    header('Location: ' . $self . (!empty($_POST['tab']) ? '#' . preg_replace('/[^a-z0-9]/', '', $_POST['tab']) : '')); exit;
}

// ── الاستعلامات ──
// ١) رواتب بـ2025-2026 أو بعدها وبلا أي ضمان محسوم بتلك السنين — ويُستثنى مَن قرّر أنه «يبقى» (cnss_subject=0) ويُعرض بطيّة لحاله
$r1all = $db->query("SELECT e.id, e.employee_code, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.school_id, e.employee_type, e.status, e.hire_date, " . leftDateSql() . " ld, e.nssf_number, e.finance_ministry_number, e.cnss_subject,
    SUM(m.cnss_amount_lbp) cn, SUM(m.income_tax_lbp) tx, COUNT(*) k, GROUP_CONCAT(DISTINCT m.school_year ORDER BY m.school_year) sys
    FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.is_calculated = 1 AND m.school_year >= '2025-2026'
    WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . " GROUP BY e.id HAVING cn = 0
    ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), e.first_name_fr, e.last_name_fr")->fetchAll();
$r1 = array_values(array_filter($r1all, fn($r) => (int)$r['cnss_subject'] !== 0));
$r1kept = array_values(array_filter($r1all, fn($r) => (int)$r['cnss_subject'] === 0));
// ٢ و ٣) عليهم ضمان محسوم (أي سنة)
$r2 = $db->query("SELECT e.id, e.employee_code, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.school_id, e.employee_type, e.hire_date, e.birth_date, e.nssf_number, e.finance_ministry_number,
    SUM(m.cnss_amount_lbp) cn, COUNT(*) k, MAX(CONCAT(m.year,'-',LPAD(m.month,2,'0'))) lastm
    FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.is_calculated = 1
    WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . " GROUP BY e.id HAVING cn > 0 ORDER BY e.school_id, e.first_name_fr, e.last_name_fr")->fetchAll();
$noCnss = array_values(array_filter($r2, fn($r) => !$hasNum($r['nssf_number'])));
$noBoth = array_values(array_filter($noCnss, fn($r) => !$hasNum($r['finance_ministry_number'])));
$noCnssOnly = array_values(array_filter($noCnss, fn($r) => $hasNum($r['finance_ministry_number'])));

// حذف جماعي للائحة ١ (قراره «يُشال») — بصفحة تأكيد
$eligible = [];
foreach (array_merge($r1, $noCnss) as $r) $eligible[(int)$r['id']] = trim($r['first_name_fr'] . ' ' . $r['last_name_fr']) . ' (' . trim($r['first_name_ar'] . ' ' . $r['last_name_ar']) . ') — ' . schoolNameById((int)$r['school_id']) . ' — ' . $r['employee_code'];
if (bulkDeleteFlow($db, $eligible, 'sans_cnss_decision', $self, 'Retirer — sans CNSS', 'حذف نهائي — بلا ضمان بقرارك')) exit;

$canW = canEdit();
include __DIR__ . '/../includes/header.php';
$name = fn($r) => '<strong>' . e(trim($r['first_name_fr'] . ' ' . $r['last_name_fr'])) . '</strong><br><small style="color:var(--gray-500)">' . e(trim($r['first_name_ar'] . ' ' . $r['last_name_ar'])) . ' · ' . e($r['employee_code']) . '</small>';
$schoolRow = function ($sid, $cols) { return '<tr><td colspan="' . $cols . '" style="background:#dbe7f6;font-weight:800;color:#173f6f">' . e(schoolNameById($sid)) . '</td></tr>'; };
?>
<div class="mp-steps no-print" style="grid-template-columns:repeat(3,1fr)">
    <a class="mp-step cur" href="#t1"><span class="mp-n"><?= count($r1) ?></span><span><b><span dir="ltr">1 · Sans CNSS (2025-2026 →)</span> / بلا ضمان</b><small>قرارك: يبقى أو يُشال<?= $r1kept ? ' · ' . count($r1kept) . ' قرّرت يبقوا' : '' ?></small></span></a>
    <a class="mp-step" href="#t2"><span class="mp-n"><?= count($noCnssOnly) ?></span><span><b><span dir="ltr">2 · N° CNSS manquant</span> / ناقص رقم الضمان</b><small>عليهم ضمان محسوم — اكتب الرقم</small></span></a>
    <a class="mp-step" href="#t3"><span class="mp-n"><?= count($noBoth) ?></span><span><b><span dir="ltr">3 · CNSS + Finances manquants</span> / ناقص الرقمان</b><small>اكتب رقم الضمان ورقم المالية</small></span></a>
</div>

<div class="card" id="t1">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-user-shield"></i> 1 · Depuis 2025-2026 sans aucune CNSS retenue (<?= count($r1) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">من 2025-2026 وطالع وما انحسم عليهم ضمان ولا شهر — قرّر: «يبقى» (غير خاضع) أو «يُشال»</div></h3>
        <?php if ($canW && $r1): ?><form method="get" action="" class="no-print" style="margin:0"><input type="hidden" name="action" value="bulk_delete"><?php foreach ($r1 as $rr): $id = (int)$rr['id']; ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?><button type="submit" class="btn btn-danger btn-sm" title="حذف نهائي لكل اللائحة — بصفحة تأكيد"><i class="fas fa-trash"></i> شيل الكل (<?= count($r1) ?>) — بتأكيد</button></form><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$r1): ?><div class="mp-ok"><i class="fas fa-circle-check"></i> ما في حدا بلا ضمان بانتظار قرارك</div><?php else: ?>
        <div class="mp-tool no-print"><input type="search" class="form-control" placeholder="⚡ صفّي: اسم، مدرسة، فئة…" data-filter="#t1 tbody tr"></div>
        <div class="table-wrapper"><table class="table mp-table">
            <thead><tr><th>Employé / الموظف</th><th>Type / الفئة</th><th>Embauche / الدخول</th><th>Statut / الحالة</th><th>Salaires sans CNSS / رواتب بلا ضمان</th><th class="no-print" colspan="2">N° CNSS · N° Finances / اكتب الرقمين واحفظ</th><th>Impôt retenu / ضريبة</th><th class="no-print">Décision / قرارك</th></tr></thead>
            <tbody>
            <?php $ps = null; foreach ($r1 as $r): if ($ps !== $r['school_id']) { $ps = $r['school_id']; echo $schoolRow($ps, 9); } $isCur = ($r['status'] === 'actif' && $r['ld'] >= '2026-10-01'); ?>
                <tr data-q="<?= e(mb_strtolower($r['first_name_fr'] . ' ' . $r['last_name_fr'] . ' ' . $r['first_name_ar'] . ' ' . $r['last_name_ar'] . ' ' . schoolNameById($r['school_id']) . ' ' . employeeTypeLabel($r['employee_type'], 'ar') . ' ' . $r['employee_code'])) ?>">
                    <td><?= $name($r) ?></td><td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td><td><?= formatDate($r['hire_date']) ?></td>
                    <td><?= $isCur ? '<span class="badge badge-success">فاعل 2026-2027</span>' : '<span class="badge badge-warning">ترك ' . formatDate($r['ld']) . '</span>' ?></td>
                    <td><?= (int)$r['k'] ?> شهر <small style="color:var(--gray-500)">[<?= e($r['sys']) ?>]</small></td>
                    <td class="no-print" colspan="2">
                        <?php if ($canW): ?>
                        <form method="post" action="<?= $self ?>" style="display:flex;gap:5px;align-items:center;margin:0"><?= csrfField() ?><input type="hidden" name="do" value="numbers"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="t1">
                            <input type="text" name="nssf" class="form-control" style="font-size:13px;padding:5px 8px;width:130px" placeholder="رقم الضمان" value="<?= $hasNum($r['nssf_number']) ? e($r['nssf_number']) : '' ?>" dir="ltr">
                            <input type="text" name="finance" class="form-control" style="font-size:13px;padding:5px 8px;width:110px" placeholder="رقم المالية" value="<?= $hasNum($r['finance_ministry_number']) ? e($r['finance_ministry_number']) : '' ?>" dir="ltr">
                            <button type="submit" class="btn btn-sm btn-primary" title="يكتب الرقمين بملفه فوراً"><i class="fas fa-save"></i> حفظ</button>
                        </form>
                        <?php else: ?><?= $hasNum($r['nssf_number']) ? e($r['nssf_number']) : '—' ?> · <?= $hasNum($r['finance_ministry_number']) ? e($r['finance_ministry_number']) : '—' ?><?php endif; ?>
                    </td>
                    <td><?= (int)$r['tx'] > 0 ? formatLBP($r['tx'], false) : '0' ?></td>
                    <td class="no-print" style="white-space:nowrap">
                        <?php if ($canW): ?>
                        <form method="post" action="<?= $self ?>" style="display:inline"><?= csrfField() ?><input type="hidden" name="do" value="keep"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="t1"><button type="submit" class="btn btn-sm btn-success" title="يبقى بالبرنامج كغير خاضع للضمان — يختفي من هذه اللائحة"><i class="fas fa-check"></i> يبقى</button></form>
                        <a class="btn btn-sm btn-danger" href="?action=bulk_delete&ids[]=<?= (int)$r['id'] ?>" title="حذف الملف نهائياً بصفحة تأكيد"><i class="fas fa-trash"></i> احذف الملف</a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>" title="فتح الملف"><i class="fas fa-pen"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <?php if ($r1kept): ?>
        <details style="margin-top:12px"><summary class="btn btn-sm btn-light"><i class="fas fa-eye"></i> قرّرت إنّهم يبقوا كغير خاضعين (<?= count($r1kept) ?>)</summary>
            <div class="table-wrapper" style="margin-top:8px"><table class="table"><thead><tr><th>Employé / الموظف</th><th>École / المدرسة</th><th>Type / الفئة</th><th class="no-print"></th></tr></thead><tbody>
            <?php foreach ($r1kept as $r): ?><tr><td><?= $name($r) ?></td><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td>
                <td class="no-print"><?php if ($canW): ?><form method="post" action="<?= $self ?>" style="display:inline"><?= csrfField() ?><input type="hidden" name="do" value="unkeep"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="t1"><button type="submit" class="btn btn-sm btn-light"><i class="fas fa-rotate-left"></i> رجّعه للائحة</button></form><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div></details>
        <?php endif; ?>
    </div>
</div>

<?php foreach ([['t2', $noCnssOnly, '2 · CNSS retenue mais n° CNSS manquant', 'عليهم ضمان محسوم بس ما عندهم رقم ضمان — اكتب الرقم واحفظ', false], ['t3', $noBoth, '3 · CNSS retenue — n° CNSS et n° Finances manquants', 'عليهم ضمان محسوم وما عندهم لا رقم ضمان ولا رقم مالية — اكتب الرقمين واحفظ', true]] as [$tid, $lst, $tf, $ta, $both]): ?>
<div class="card" id="<?= $tid ?>">
    <div class="card-header"><h3><span dir="ltr"><i class="fas fa-id-card"></i> <?= e($tf) ?> (<?= count($lst) ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e($ta) ?></div></h3></div>
    <div class="card-body">
        <?php if (!$lst): ?><div class="mp-ok"><i class="fas fa-circle-check"></i> ما في حدا — كل الأرقام موجودة</div><?php else: ?>
        <div class="mp-tool no-print"><input type="search" class="form-control" placeholder="⚡ صفّي: اسم، مدرسة…" data-filter="#<?= $tid ?> tbody tr"></div>
        <div class="table-wrapper"><table class="table mp-table">
            <thead><tr><th>Employé / الموظف</th><th>Type / الفئة</th><th>Embauche / الدخول</th><th>Naissance / الولادة</th><th>CNSS retenue / ضمان محسوم</th><th>Dernier mois / آخر شهر</th><th class="no-print" style="min-width:420px">N° CNSS · N° Finances / اكتب واحفظ — أو احذف الملف</th></tr></thead>
            <tbody>
            <?php $ps = null; foreach ($lst as $r): if ($ps !== $r['school_id']) { $ps = $r['school_id']; echo $schoolRow($ps, 7); } ?>
                <tr data-q="<?= e(mb_strtolower($r['first_name_fr'] . ' ' . $r['last_name_fr'] . ' ' . $r['first_name_ar'] . ' ' . $r['last_name_ar'] . ' ' . schoolNameById($r['school_id']) . ' ' . $r['employee_code'])) ?>">
                    <td><?= $name($r) ?></td><td><small><?= e(employeeTypeLabel($r['employee_type'])) ?></small></td><td><?= formatDate($r['hire_date']) ?></td><td><?= formatDate($r['birth_date']) ?></td>
                    <td><?= formatLBP($r['cn'], false) ?> <small style="color:var(--gray-500)">(<?= (int)$r['k'] ?> شهر)</small></td><td><?= e($r['lastm']) ?></td>
                    <td class="no-print">
                        <?php if ($canW): ?>
                        <form method="post" action="<?= $self ?>" style="display:flex;gap:5px;align-items:center;margin:0"><?= csrfField() ?><input type="hidden" name="do" value="numbers"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="<?= $tid ?>">
                            <input type="text" name="nssf" class="form-control" style="font-size:13px;padding:5px 8px;width:130px" placeholder="رقم الضمان" dir="ltr">
                            <input type="text" name="finance" class="form-control" style="font-size:13px;padding:5px 8px;width:110px" placeholder="رقم المالية" value="<?= $both ? '' : e($r['finance_ministry_number']) ?>" dir="ltr">
                            <button type="submit" class="btn btn-sm btn-primary" title="يكتب الرقمين بملفه فوراً"><i class="fas fa-save"></i> حفظ</button>
                            <a class="btn btn-sm btn-danger" href="?action=bulk_delete&ids[]=<?= (int)$r['id'] ?>" title="حذف الملف نهائياً بصفحة تأكيد"><i class="fas fa-trash"></i> احذف الملف</a>
                            <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>" title="فتح الملف"><i class="fas fa-pen"></i></a>
                        </form>
                        <?php else: ?><a class="btn btn-sm btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i></a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<script>
document.querySelectorAll('input[data-filter]').forEach(function (inp) {
    var rows = document.querySelectorAll(inp.getAttribute('data-filter'));
    inp.addEventListener('input', function () { var q = inp.value.trim().toLowerCase(); rows.forEach(function (tr) { if (!tr.hasAttribute('data-q')) return; tr.style.display = (!q || tr.getAttribute('data-q').indexOf(q) !== -1) ? '' : 'none'; }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
