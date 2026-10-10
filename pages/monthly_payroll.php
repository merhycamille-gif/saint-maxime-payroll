<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$GLOBALS['msa_recalc_paid_ok'] = true; // 🔒 فعل صريح من المستخدم: يجوز إعادة حساب الأشهر المدفوعة لسنة سابقة (2026-09-23)
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/hours_reduction.php'; // 🕐 التناقص + حضور التناقص بجانب الساعات الأسبوعية بالقسيمة
requireLogin();

$currentPage = 'monthly';
$pageTitle = 'Paie mensuelle / الرواتب الشهرية';
$db = getDB();

$action = $_GET['action'] ?? 'list';
$employeeId = (int)($_GET['employee_id'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
// فلتر النوع: '' = الكل، أو نوع محدّد (ملاك/متعاقد/موظف)
// ☑️ (2026-09-25) خانات تشييك: المشيّكة فقط تبيّن، ولا واحدة ⇒ لا أحد (المصدر الواحد empTypeSelection — الرابط type[]=…&type_set=1)
$typeState = empTypeSelection($_GET, 'type');
$typeFilter = (!$typeState['all'] && count($typeState['sel']) === 1) ? $typeState['sel'][0] : ''; // فئة واحدة مشيّكة (للتسميات المفردة)
$typeQ = empTypeQueryFrom($typeState, 'type'); // لاحقة الروابط (تبدأ بـ&)
// السنة الدراسية التي يقع فيها الشهر المختار (تشرين الأول→أيلول) — لفلترة موظفي تلك السنة فقط
$msSchoolYear = ($month >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);

/**
 * يرسم قسيمة راتب أستاذ/موظف كاملة (نفس أرقام العرض الفردي) — تُستعمل
 * في العرض الفردي والطباعة الجماعية معاً لضمان تطابق الأرقام.
 */
function payslipCardHtml($emp, $salary, $month, $year) {
    $schoolYearLbl = $salary['school_year'] ?? ($month >= 10 ? $year.'-'.($year+1) : ($year-1).'-'.$year);
    $classesLbl = classLevelNames($emp['classes_taught'] ?? '');
    $isAdminEmp = ($emp['employee_type'] === 'employe'); // موظف إداري: لا درجة/صفوف/مواد/صندوق تعويضات
    // ترويسة المدرسة الرسمية على القسيمة — مدرسة الموظف نفسه (تعمل أيضاً بوضع «كل المدارس»)
    static $slipSchools = [];
    $sid = (int)($emp['school_id'] ?? 0);
    if ($sid && !array_key_exists($sid, $slipSchools)) {
        $q = getDB()->prepare("SELECT * FROM schools WHERE id = ? AND is_deleted = 0");
        $q->execute([$sid]);
        $slipSchools[$sid] = $q->fetch() ?: null;
    }
    $slipSchool = $sid ? ($slipSchools[$sid] ?? null) : currentSchool();
    ob_start();
    ?>
    <div class="card payslip-card" style="page-break-inside:avoid">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-user"></i> <?= e(empFullNameFr($emp)) ?> — <?= monthName($month) ?> <?= $year ?></span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(empFullNameAr($emp) ?: 'قسيمة الراتب') ?></div>
            </h3>
            <div style="font-size:13px;color:var(--gray-600)"><?= e(currentSchoolName()) ?></div>
        </div>
        <div class="card-body">
            <?= $slipSchool ? schoolLetterhead($slipSchool) : '' ?>
            <div class="doc-title" style="margin:2px 0 12px">Bulletin de paie mensuel / قسيمة الراتب الشهرية — <?= monthName($month, 'ar') ?> <?= $year ?></div><?= rateSubtitle($month, $year) ?>
            <table class="table" style="margin-bottom:16px">
                <tr><th colspan="4" style="background:#eef3fb;color:#000"><i class="fas fa-id-badge"></i> Informations / المعلومات</th></tr>
                <tr>
                    <td style="width:25%">Nom / الاسم</td><td style="width:25%"><strong><?= e(empFullNameFr($emp)) ?></strong><br><small class="text-rtl"><?= e(empFullNameAr($emp)) ?></small></td>
                    <td style="width:25%">Type / النوع</td><td style="width:25%"><strong><?= employeeTypeLabel($emp['employee_type']) ?></strong></td>
                </tr>
                <tr>
                    <td>Année scolaire / السنة الدراسية</td><td><strong><?= e($schoolYearLbl) ?></strong></td>
                    <td>Date d'embauche / تاريخ المباشرة</td><td><strong><?= formatDate(shownHireDate($emp)) ?></strong></td>
                </tr>
                <tr>
                    <td>N° dossier / رقم الملف</td><td><strong><?= (int)$emp['id'] ?></strong></td>
                    <td>N° CNSS / رقم الضمان</td><td><strong><?= e(cnssWithBirthYear($emp['nssf_number'] ?? '', $emp['birth_date'] ?? '')) ?></strong></td>
                </tr>
                <tr>
                    <?php if ($isAdminEmp): ?>
                    <td>Fonction / الوظيفة</td><td colspan="3"><strong><?= e(trim((string)($emp['job_title'] ?? '')) !== '' ? jobTitleLabel($emp['job_title'], 'ar') : '—') ?></strong></td>
                    <?php else: ?>
                    <td>Classes / الصفوف</td><td><strong><?= e($classesLbl) ?></strong></td>
                    <td>Matières / المواد</td><td><strong><?= e($emp['subjects_taught'] ?: '—') ?></strong></td>
                    <?php endif; ?>
                </tr>
            </table>
            <?php if (!$salary): ?>
                <div class="alert alert-warning" style="margin:0"><i class="fas fa-exclamation-triangle"></i> غير محتسب لهذا الشهر / Pas encore calculé</div>
            <?php else: ?>
                <div class="form-row cols-2">
                    <table class="table">
                        <tr><th colspan="2" style="background:#e3f0ff;color:#000">SALAIRE / الراتب</th></tr>
                        <tr><td>Salaire de base / أساس الراتب</td><td class="text-end"><?= moneyLaw($salary['base_salary_lbp']) ?></td></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Échelons / الدرجات (G<?= $salary['grade_at_month'] ?>)</td><td class="text-end"><?= moneyLaw($salary['echelon_value_lbp']) ?></td></tr><?php endif; ?>
                        <?php if (!$isAdminEmp): ?><tr style="background:var(--gray-50)"><td><strong>Base + Échelon</strong></td><td class="text-end"><strong><?= moneyLaw($salary['base_plus_echelon_lbp'], [], $salary, 'bpe') ?></strong></td></tr><?php endif; ?>
                        <tr><td>الأجر الإضافي / Supplément</td><td class="text-end"><?= extraWageMoney($salary) ?></td></tr>
                        <tr><td>مكافأة ومساعدة / Prime &amp; aide</td><td class="text-end"><?= money((int)$salary['aide_complementaire_lbp'], rowRate($salary)) ?></td></tr>
                        <tr style="background:#eef2ff"><td><strong>الراتب المركّب / Salaire composé</strong><br><small style="color:#64748b"><?= e(salaryCompLabel()) ?></small></td><td class="text-end"><strong><?= dualFromUsd(composedSalaryLbp($salary), composedSalaryUsd($salary)) ?></strong></td></tr>
                    </table>
                    <table class="table">
                        <tr><th colspan="2" style="background:#ffe3e3;color:#000">RETENUES / المحسومات</th></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Caisse EOC (6%)</td><td class="text-end text-danger">-<?= money($salary['caisse_amount_lbp'], rowRate($salary)) ?></td></tr><?php endif; ?>
                        <?php if (!empty($salary['eoc_grade_lbp'])): ?><tr><td>درجة / نصف راتب (صندوق)</td><td class="text-end text-danger">-<?= money($salary['eoc_grade_lbp'], rowRate($salary)) ?></td></tr><?php endif; ?>
                        <tr><td>CNSS (3%)</td><td class="text-end text-danger">-<?= money($salary['cnss_amount_lbp'], rowRate($salary)) ?></td></tr>
                        <tr><td>Base imposable</td><td class="text-end"><?= money($salary['taxable_base_lbp'], rowRate($salary)) ?></td></tr>
                        <tr><td>Impôt sur le revenu</td><td class="text-end text-danger">-<?= money($salary['income_tax_lbp'], rowRate($salary)) ?></td></tr>
                        <tr style="background:#fef2f2"><td><strong>Total retenues</strong></td><td class="text-end"><strong class="text-danger">-<?= money($salary['total_retenues_lbp'], rowRate($salary)) ?></strong></td></tr>
                    </table>
                </div>
                <table class="table" style="margin-top:16px">
                    <tr style="background:var(--gold-light)">
                        <td><strong>Salaire net</strong></td>
                        <td class="text-end"><strong><?= money($salary['net_salary_lbp'], rowRate($salary)) ?></strong></td>
                        <td class="text-end text-muted"><?= (displayCurrency()==='lbp' ? formatUSD(rowUsd($salary, 'net_salary_usd', 'net_salary_lbp')) : '') ?></td>
                    </tr>
                    <tr><td>Allocations familiales (exonérées)</td><td class="text-end text-success">+<?= money($salary['family_allowance_lbp'], rowRate($salary)) ?></td><td></td></tr>
                    <tr><td>Transport</td><td class="text-end text-success">+<?= money($salary['transport_lbp'], rowRate($salary)) ?></td><td></td></tr>
                    <tr style="background:#fff3cd;color:#000">
                        <td style="font-size:18px"><strong>💰 TOTAL DÛ / صافي الراتب المستحق للدفع</strong></td>
                        <td class="text-end" style="font-size:18px"><strong><?= money($salary['total_due_lbp'], rowRate($salary)) ?></strong></td>
                        <td class="text-end" style="font-size:16px"><strong><?= (displayCurrency()==='lbp' ? formatUSD(rowUsd($salary, 'total_due_usd', 'total_due_lbp')) : '') ?></strong></td>
                    </tr>
                </table>
                <div class="sign-row" style="margin-top:26px">
                    <?= signatureBox('Le comptable / توقيع المحاسب') ?>
                    <?= signatureBox("Signature de l'employé / توقيع الموظف بالاستلام") ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

$message = ''; $messageType = 'success';

// احتساب الرواتب يتطلّب اختيار مدرسة محددة
if (in_array($action, ['calc', 'calc_all'])) {
    requireSchoolSelected();
}

// Calculate single employee
if ($action === 'calc' && $employeeId > 0) {
    try {
        // حماية المنقول يدوياً: لا تُعِد حساب متعاقد/موظف بلا إعداد فعلي (يُصفَّر راتبه المخزّن)
        $cfg = $db->prepare("SELECT id, employee_type, base_salary_usd, contract_salary_lbp FROM employees WHERE id = ?");
        $cfg->execute([$employeeId]); $cfg = $cfg->fetch();
        $hasConfig = $cfg && salaryEngineAllowed($cfg, $db); // المصدر الواحد (الجديد بلا أساس منقول يُحسب من ملفه)
        $lkSid = (int)$db->query("SELECT school_id FROM employees WHERE id = " . (int)$employeeId)->fetchColumn();
        if (isSchoolYearLocked($lkSid, schoolYearOfMonth((int)$year, (int)$month))) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => yearLockedMsg($lkSid, schoolYearOfMonth((int)$year, (int)$month))];
        } elseif (!$hasConfig) {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'راتب هذا الموظف مُدخَل يدوياً (منقول) — لا يُعاد حسابه تلقائياً لئلا يُصفَّر. عدّله من بطاقته إن لزم.'];
        } else {
            (new PayrollCalculator($employeeId, $month, $year))->calculateAndSave();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Salaire calculé / تم احتساب الراتب'];
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
    header('Location: ' . BASE_URL . 'pages/monthly_payroll.php?employee_id=' . $employeeId . '&month=' . $month . '&year=' . $year);
    exit;
}

// Calculate all (يحترم فلتر النوع إن وُجد)
if ($action === 'calc_all') {
    // الاحتساب على الفاعلين (status=actif) ضمن السنة الدراسية للشهر المحسوب.
    // 🔴 قاعدة التارك (لا تُغيَّر): مَن ترك خلال السنة يبقى راتبه يُحتسب لكل أشهر سنة تركه
    // (بما فيها أشهر الصيف حتى 30-9)، ويُستبعد فقط من السنين التي تبدأ بعد تاريخ تركه —
    // نفس مبدأ yearEmploymentFilter و pruneSalariesAfterDeparture.
    // 🔴 حماية المنقولين يدوياً: يُحتسب فقط مَن له إعداد فعلي (ملاك أو أساس بالدولار/بالعقد > 0).
    // المتعاقد/الموظف ذو الراتب المنقول (بلا إعداد) لا يُعاد حسابه هنا لئلا يُصفَّر راتبه المخزّن
    // (نفس حماية recalcEmployeeYear / recalcSalariesInRange).
    $syStartC = ($month >= 10 ? $year : $year - 1) . '-10-01'; // بداية السنة الدراسية للشهر المحسوب
    $sqlC = "SELECT id FROM employees WHERE is_deleted = 0 AND status = 'actif'"
          . " AND " . leftDateSql() . " >= ?"
          . " AND " . salaryConfigSql('') . schoolScopeSql();
    $paramsC = [$syStartC];
    $sqlC .= empTypeSqlFrom($db, $typeState, ''); // ☑️ الفئات المشيّكة
    $idsSel = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))); // ☑️ v2026 اختيار جماعي من اللائحة
    if ($idsSel) $sqlC .= " AND id IN (" . implode(',', $idsSel) . ")";
    $stmtC = $db->prepare($sqlC);
    $stmtC->execute($paramsC);
    $employees = $stmtC->fetchAll();
    $count = 0; $lockedN = 0; $syCalc = schoolYearOfMonth((int)$year, (int)$month);
    foreach ($employees as $e) {
        try {
            $calc = new PayrollCalculator($e['id'], $month, $year);
            // 🔒 قفل السنة: مدرسة مقفولة = المحرّك لا يحفظ؛ نعدّها لنقولها بالرسالة
            $sidC = (int)$db->query("SELECT school_id FROM employees WHERE id = " . (int)$e['id'])->fetchColumn();
            if (isSchoolYearLocked($sidC, $syCalc)) { $lockedN++; continue; }
            $calc->calculateAndSave();
            $count++;
        } catch (Exception $ex) {}
    }
    $_SESSION['flash'] = ['type' => $lockedN && !$count ? 'danger' : 'success', 'msg' => "$count salaires calculés pour " . monthName($month) . " $year" . ($lockedN ? " — 🔒 $lockedN تُركوا كما هم لأن سنتهم مقفولة لمدرستهم" : '')];
    header('Location: ' . BASE_URL . 'pages/monthly_payroll.php?month=' . $month . '&year=' . $year . $typeQ);
    exit;
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// 🌍 (2026-10-10 «بدي أحدث تنظيم وديزاين» — دِمو Rippling/Gusto/Deel وافق عليه): لائحة الشهر وحدها تأخذ الترتيب الجديد:
// شريط الخيارات الخمسة وشريط التصدير يصيران كبستَين قابلتَين للفتح («Colonnes & options» / «Impression & export») فوق الجدول،
// والقسيمة الفردية والطباعة الجماعية تبقيان بشريطَيهما الكاملَين كما كانتا.
$mpModern = ($action === 'list' && $employeeId <= 0);
if ($mpModern) { $hideExportToolbar = true; $compactSalaryComp = true; }
include __DIR__ . '/../includes/header.php';
echo officialFormStyles(); // ستايلات الترويسة/التوقيع/العناوين الرسمية على القسيمة
?>
<style>
/* طباعة القسيمة: ضغط الحشوات وإخفاء رأس البطاقة المكرّر حتى تسع القسيمة كاملة بصفحة A4 واحدة */
@media print{
  .payslip-card .card-header,#ppExportArea .card-header{display:none !important}
  .payslip-card .table th,.payslip-card .table td,
  #ppExportArea .table th,#ppExportArea .table td{padding:4px 8px !important}
  .payslip-card .table,#ppExportArea .table{margin-bottom:8px !important}
  .payslip-card .letterhead,#ppExportArea .letterhead{margin-bottom:8px;padding-bottom:6px}
  .payslip-card .sign-row,#ppExportArea .sign-row{margin-top:14px !important;page-break-inside:avoid}
  .payslip-card .sign-box .sign-label,#ppExportArea .sign-box .sign-label{margin-bottom:26px}
}
</style>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
<?php endif; ?>

<!-- Period selector -->
<div class="card no-print<?= $mpModern ? ' mp-period' : '' ?>">
    <div class="card-header">
        <h3>
            <span dir="ltr"><i class="fas fa-calendar-alt"></i> Période</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">الفترة</div>
        </h3>
    </div>
    <div class="card-body">
        <form method="GET" class="form-row cols-5">
            <div class="form-group mb-0">
                <label class="form-label">Mois / الشهر</label>
                <select name="month" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= monthName($m) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <label class="form-label">Année / السنة</label>
                <input type="number" name="year" class="form-control" value="<?= $year ?>" min="2020" max="2030">
            </div>
            <?= empTypeCheckboxes($typeState, true, 'type') /* ☑️ (2026-09-25) خانات تشييك: المشيّكة فقط تبيّن */ ?>
            <div class="form-group mb-0">
                <label class="form-label">Taux de change / سعر الصرف</label>
                <input type="text" class="form-control" value="<?= formatLBP(getExchangeRate($month, $year)) ?> / $1" disabled>
            </div>
            <div class="form-group mb-0">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button>
            </div>
        </form>
    </div>
</div>

<?php if ($action === 'print_all'):
    // طباعة جماعية: قسيمة كاملة لكل أستاذ/موظف (حسب الفلتر)، كل واحدة بصفحة
    [$pyf, $pyp] = yearEmploymentFilter($msSchoolYear, 'e.');
    $sqlP = "SELECT e.* FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif'" . schoolScopeSql('e.school_id') . $pyf;
    $paramsP = $pyp;
    $sqlP .= empTypeSqlFrom($db, $typeState, 'e.'); // ☑️ الفئات المشيّكة
    $idsSelP = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))); // ☑️ v2026 اختيار جماعي من اللائحة
    if ($idsSelP) $sqlP .= " AND e.id IN (" . implode(',', $idsSelP) . ")";
    $sqlP .= " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr), e.id";
    $stmtP = $db->prepare($sqlP);
    $stmtP->execute($paramsP);
    $empsP = $stmtP->fetchAll();
    $salStmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND month = ? AND year = ?");
    $typeLbl = $typeFilter ? employeeTypeLabel($typeFilter) : ($typeState['all'] ? 'الكل / Tous' : empTypeTitleFrom($typeState));
?>
    <style>
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        .no-print { display: none !important; }
        body * { color: #000 !important; box-shadow: none !important; }
        .payslip-card { page-break-after: always; page-break-inside: avoid; border: none !important; }
        .payslip-card:last-child { page-break-after: auto; }
        .payslip-card .table th, .payslip-card .table td { border: 1px solid #d0d7de !important; }
    }
    </style>
    <div class="d-flex justify-between align-center mb-3 no-print">
        <a href="<?= BASE_URL ?>pages/monthly_payroll.php?month=<?= $month ?>&year=<?= $year ?><?= $typeQ ?>" class="btn btn-light">
            <i class="fas fa-arrow-left"></i> Retour à la liste / رجوع
        </a>
        <div>
            <?php /* 🧹 زرّ «طباعة الكل» أُزيل — مكرّر مع «طباعة» بشريط التصدير فوق (قاعدة المستخدم: لا أزرار مكرّرة) */ ?>
            <span class="badge badge-info"><?= count($empsP) ?> — <?= e($typeLbl) ?></span>
        </div>
    </div>
    <div class="alert alert-info no-print" style="margin-bottom:16px">
        <i class="fas fa-info-circle"></i> راجِع رواتب كل الأساتذة/الموظفين تحت، وعند التأكد اضغط «طباعة الكل». كل قسيمة تُطبع بصفحة مستقلة.
    </div>
    <?php if (!$empsP): ?>
        <div class="alert alert-warning">Aucun employé correspondant au filtre / لا يوجد موظفون مطابقون للفلتر.</div>
    <?php else: foreach ($empsP as $empP):
        $salStmt->execute([$empP['id'], $month, $year]);
        $salP = $salStmt->fetch();
        echo payslipCardHtml($empP, $salP, $month, $year);
    endforeach; endif; ?>
    <?php if (($_GET['autoprint'] ?? '') === '1' && $empsP): ?>
    <script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script>
    <?php endif; ?>
<?php elseif ($employeeId > 0):
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) { echo "<div class='alert alert-danger'>Employé introuvable dans cette école</div>"; include __DIR__ . '/../includes/footer.php'; exit; }

    $stmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND month = ? AND year = ?");
    $stmt->execute([$employeeId, $month, $year]);
    $salary = $stmt->fetch();
?>
    <div class="d-flex justify-between align-center mb-3 no-print">
        <a href="<?= BASE_URL ?>pages/monthly_payroll.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-light">
            <i class="fas fa-arrow-left"></i> Retour à la liste / رجوع
        </a>
        <a href="?action=calc&employee_id=<?= $employeeId ?>&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-gold">
            <i class="fas fa-calculator"></i> <?= $salary ? 'Recalculer / إعادة الاحتساب' : 'Calculer / احتساب' ?>
        </a>
    </div>
    
    <style>
    /* الطباعة: الخط أسود دائماً + خلفيات فاتحة لتوفير الحبر */
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        #ppExportArea, #ppExportArea * { color: #000 !important; box-shadow: none !important; }
        #ppExportArea { page-break-inside: avoid; }
        #ppExportArea .table th, #ppExportArea .table td { border: 1px solid #d0d7de !important; }
    }
    </style>

    <div class="card payslip-card" id="ppExportArea">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-user"></i> <?= e(empFullNameFr($emp)) ?> — <?= monthName($month) ?> <?= $year ?></span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(empFullNameAr($emp) ?: 'قسيمة الراتب') ?></div>
            </h3>
            <div style="font-size:13px;color:var(--gray-600)"><?= e(currentSchoolName()) ?></div>
        </div>
        <div class="card-body">
            <?php
            // السنة الدراسية للشهر المختار (تشرين الأول → أيلول)
            $schoolYearLbl = $salary['school_year'] ?? ($month >= 10 ? $year.'-'.($year+1) : ($year-1).'-'.$year);
            $classesLbl = classLevelNames($emp['classes_taught'] ?? '');
            $isAdminEmp = ($emp['employee_type'] === 'employe'); // موظف إداري: لا درجة/صفوف/مواد/صندوق تعويضات
            // ترويسة المدرسة الرسمية للقسيمة — مدرسة الموظف نفسه
            $slipSchool1 = null;
            if (!empty($emp['school_id'])) {
                $qS = $db->prepare("SELECT * FROM schools WHERE id = ? AND is_deleted = 0");
                $qS->execute([(int)$emp['school_id']]);
                $slipSchool1 = $qS->fetch() ?: null;
            }
            ?>
            <?= $slipSchool1 ? schoolLetterhead($slipSchool1) : '' ?>
            <div class="doc-title" style="margin:2px 0 12px">Bulletin de paie mensuel / قسيمة الراتب الشهرية — <?= monthName($month, 'ar') ?> <?= $year ?></div><?= rateSubtitle($month, $year) ?>
            <table class="table" style="margin-bottom:16px">
                <tr><th colspan="4" style="background:#eef3fb;color:#000"><i class="fas fa-id-badge"></i> Informations de l'enseignant / معلومات الأستاذ</th></tr>
                <tr>
                    <td style="width:25%">Nom / اسم الأستاذ</td><td style="width:25%"><strong><?= e(empFullNameFr($emp)) ?></strong><br><small class="text-rtl"><?= e(empFullNameAr($emp)) ?></small></td>
                    <td style="width:25%">Année scolaire / السنة الدراسية</td><td style="width:25%"><strong><?= e($schoolYearLbl) ?></strong></td>
                </tr>
                <tr>
                    <td>Date d'embauche / تاريخ الدخول</td><td><strong><?= formatDate(shownHireDate($emp)) ?></strong></td>
                    <td>Date titularisation / تاريخ الملاك</td><td><strong><?= $isAdminEmp ? '—' : formatDate(shownTitularizationDate($emp)) ?></strong></td>
                </tr>
                <tr>
                    <?php if ($isAdminEmp): ?>
                    <td>Fonction / الوظيفة</td><td colspan="3"><strong><?= e(trim((string)($emp['job_title'] ?? '')) !== '' ? jobTitleLabel($emp['job_title'], 'ar') : '—') ?></strong></td>
                    <?php else: ?>
                    <td>Classes / الصفوف</td><td><strong><?= e($classesLbl) ?></strong></td>
                    <td>Matières / المواد</td><td><strong><?= e($emp['subjects_taught'] ?: '—') ?></strong></td>
                    <?php endif; ?>
                </tr>
                <tr>
                    <td>Heures/semaine / عدد الساعات الأسبوعية</td><td><strong><?= rtrim(rtrim(number_format((float)$emp['hours_per_week'],1),'0'),'.') ?></strong><?php $hrSlip = hoursReductionSlipText($emp, 'ar'); if ($hrSlip !== ''): ?> <small style="color:#b45309">— <?= e($hrSlip) ?></small><?php endif; ?></td>
                    <td>Jours/semaine / أيام الحضور الأسبوعية</td><td><strong><?= (int)$emp['days_per_week'] ?></strong></td>
                </tr>
                <tr>
                    <td>N° dossier / رقم الملف</td><td><strong><?= (int)$emp['id'] ?></strong></td>
                    <td>N° CNSS / رقم الضمان</td><td><strong><?= e(cnssWithBirthYear($emp['nssf_number'] ?? '', $emp['birth_date'] ?? '')) ?></strong></td>
                </tr>
            </table>
            <?php if (!$salary): ?>
                <div class="empty-state">
                    <i class="fas fa-calculator"></i>
                    <h4>
                        <span dir="ltr">Pas encore calculé</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">لم يُحتسب بعد</div>
                    </h4>
                    <a href="?action=calc&employee_id=<?= $employeeId ?>&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-primary mt-3">
                        <i class="fas fa-bolt"></i> Calculer maintenant / احسب الآن
                    </a>
                </div>
            <?php else: ?>
                <div class="form-row cols-2">
                    <table class="table">
                        <tr><th colspan="2" style="background:#e3f0ff;color:#000">SALAIRE / الراتب</th></tr>
                        <tr><td>Salaire de base / أساس الراتب</td><td class="text-end"><?= moneyLaw($salary['base_salary_lbp']) ?></td></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Échelons / الدرجات (G<?= $salary['grade_at_month'] ?>)</td><td class="text-end"><?= moneyLaw($salary['echelon_value_lbp']) ?></td></tr><?php endif; ?>
                        <?php if (!$isAdminEmp): ?><tr style="background:var(--gray-50)"><td><strong>Base + Échelon</strong></td><td class="text-end"><strong><?= moneyLaw($salary['base_plus_echelon_lbp'], [], $salary, 'bpe') ?></strong></td></tr><?php endif; ?>
                        <tr><td>الأجر الإضافي / Supplément</td><td class="text-end"><?= extraWageMoney($salary) ?></td></tr>
                        <tr><td>مكافأة ومساعدة / Prime &amp; aide</td><td class="text-end"><?= money((int)$salary['aide_complementaire_lbp'], rowRate($salary)) ?></td></tr>
                        <tr style="background:#eef2ff"><td><strong>الراتب المركّب / Salaire composé</strong><br><small style="color:#64748b"><?= e(salaryCompLabel()) ?></small></td><td class="text-end"><strong><?= dualFromUsd(composedSalaryLbp($salary), composedSalaryUsd($salary)) ?></strong></td></tr>
                    </table>

                    <table class="table">
                        <tr><th colspan="2" style="background:#ffe3e3;color:#000">RETENUES / المحسومات</th></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Caisse EOC (6%)</td><td class="text-end text-danger">-<?= money($salary['caisse_amount_lbp'], rowRate($salary)) ?></td></tr><?php endif; ?>
                        <?php if (!empty($salary['eoc_grade_lbp'])): ?><tr><td>درجة / نصف راتب (صندوق)</td><td class="text-end text-danger">-<?= money($salary['eoc_grade_lbp'], rowRate($salary)) ?></td></tr><?php endif; ?>
                        <tr><td>CNSS (3%)</td><td class="text-end text-danger">-<?= money($salary['cnss_amount_lbp'], rowRate($salary)) ?></td></tr>
                        <tr><td>Base imposable</td><td class="text-end"><?= money($salary['taxable_base_lbp'], rowRate($salary)) ?></td></tr>
                        <tr><td>Impôt sur le revenu</td><td class="text-end text-danger">-<?= money($salary['income_tax_lbp'], rowRate($salary)) ?></td></tr>
                        <tr style="background:#fef2f2"><td><strong>Total retenues</strong></td><td class="text-end"><strong class="text-danger">-<?= money($salary['total_retenues_lbp'], rowRate($salary)) ?></strong></td></tr>
                    </table>
                </div>
                
                <table class="table" style="margin-top:20px">
                    <tr style="background:var(--gold-light)">
                        <td><strong>Salaire net</strong></td>
                        <td class="text-end"><strong><?= money($salary['net_salary_lbp'], rowRate($salary)) ?></strong></td>
                        <td class="text-end text-muted"><?= (displayCurrency()==='lbp' ? formatUSD(rowUsd($salary, 'net_salary_usd', 'net_salary_lbp')) : '') ?></td>
                    </tr>
                    <tr><td>Allocations familiales (exonérées)</td><td class="text-end text-success">+<?= money($salary['family_allowance_lbp'], rowRate($salary)) ?></td><td></td></tr>
                    <tr><td>Transport</td><td class="text-end text-success">+<?= money($salary['transport_lbp'], rowRate($salary)) ?></td><td></td></tr>
                    <tr style="background:#fff3cd;color:#000">
                        <td style="font-size:18px"><strong>💰 TOTAL DÛ / صافي الراتب المستحق للدفع</strong></td>
                        <td class="text-end" style="font-size:18px"><strong><?= money($salary['total_due_lbp'], rowRate($salary)) ?></strong></td>
                        <td class="text-end" style="font-size:16px"><strong><?= (displayCurrency()==='lbp' ? formatUSD(rowUsd($salary, 'total_due_usd', 'total_due_lbp')) : '') ?></strong></td>
                    </tr>
                </table>

                <div class="sign-row" style="margin-top:26px">
                    <?= signatureBox('Le comptable / توقيع المحاسب') ?>
                    <?= signatureBox("Signature de l'employé / توقيع الموظف بالاستلام") ?>
                </div>

                <details style="margin-top:20px" class="no-print">
                    <summary style="cursor:pointer;color:var(--gray-600);font-weight:600">
                        <i class="fas fa-eye"></i> Charges patronales (cachées du bulletin) / أعباء رب العمل (مخفية عن القسيمة)
                    </summary>
                    <table class="table" style="margin-top:10px">
                        <tr><td>CNSS École (8%)</td><td class="text-end"><?= money($salary['school_cnss_8_lbp'], rowRate($salary)) ?></td></tr>
                        <?php if ($salary['school_eoc_6_lbp'] > 0): ?>
                        <tr><td>Caisse EOC École (6%)</td><td class="text-end"><?= money($salary['school_eoc_6_lbp'], rowRate($salary)) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($salary['school_family_comp_6_lbp'] > 0): ?>
                        <tr><td>Allocations familiales (6%)</td><td class="text-end"><?= money($salary['school_family_comp_6_lbp'], rowRate($salary)) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($salary['school_end_of_service_8_5_lbp'] > 0): ?>
                        <tr><td>Indemnité fin service (8.5%)</td><td class="text-end"><?= money($salary['school_end_of_service_8_5_lbp'], rowRate($salary)) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </details>
            <?php endif; ?>
        </div>
    </div>
<?php else:
    // ═══════════════════════════════════════════════════════════════════════════════════════
    // 🌍 List view — الترتيب العصري (2026-10-10، دِمو Rippling/Gusto/Deel وافق عليه «بدي أحدث تنظيم وديزاين»):
    //   ② شريط خطوات الشهر · ⑤ بطاقات أرقام مع رسم 4 أشهر وفرق عن الشهر الماضي · ① بحث فوري + الخيارات مطوية
    //   ⑥ سطر الموظف: الاسم كبير وتحته الرمز·المدرسة · ③ مقارنة بالشهر الماضي (أخضر/أحمر) · ⑦ قائمة ⋮ بآخر السطر
    //   ④ لوحة يمين ثابتة: ملخّص الشهر برقم كبير + كبسات الشهر + «لازم تشوفهم» · ⑧ الكبس على السطر يفتح القسيمة بدرج
    //   🔴 لا يتغيّر أي رقم: نفس الاستعلام والدوال (money/rowUsd/dualFromUsd) — أُضيفت أعمدة القسيمة المخزّنة للعرض فقط.
    // ═══════════════════════════════════════════════════════════════════════════════════════
    $pmM = $month === 1 ? 12 : $month - 1; $pmY = $month === 1 ? $year - 1 : $year; // الشهر الماضي (للمقارنة)
    $sql = "SELECT e.id, e.school_id, e.employee_code, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.employee_type, e.current_grade,
                   ms.total_due_lbp, ms.total_due_usd, ms.net_salary_lbp, ms.net_salary_usd, ms.is_calculated, ms.exchange_rate, ms.month, ms.year,
                   ms.base_plus_echelon_lbp, ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp, ms.prime_fixe_usd_law, ms.total_retenues_lbp,
                   ms.family_allowance_lbp, ms.transport_lbp,
                   ms.school_cnss_8_lbp, ms.school_eoc_6_lbp, ms.school_family_comp_6_lbp, ms.school_end_of_service_8_5_lbp,
                   e.photo_path, pm.total_due_lbp AS prev_due_lbp, pm.net_salary_lbp AS prev_net_lbp, pm.is_calculated AS prev_calc
            FROM employees e
            LEFT JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.month = ? AND ms.year = ?
            LEFT JOIN monthly_salaries pm ON pm.employee_id = e.id AND pm.month = ? AND pm.year = ?
            WHERE e.is_deleted = 0 AND e.status = 'actif'" . schoolScopeSql('e.school_id');
    [$lyf, $lyp] = yearEmploymentFilter($msSchoolYear, 'e.');
    $sql .= $lyf;
    $listParams = array_merge([$month, $year, $pmM, $pmY], $lyp);
    $sql .= empTypeSqlFrom($db, $typeState, 'e.'); // ☑️ الفئات المشيّكة
    $sql .= " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr), e.id";
    $stmt = $db->prepare($sql);
    $stmt->execute($listParams);
    $list = $stmt->fetchAll();

    $totalDue = 0; $calculatedCount = 0;
    foreach ($list as $r) {
        $totalDue += (float)($r['total_due_lbp'] ?? 0);
        if ($r['is_calculated']) $calculatedCount++;
    }
    $pendingN = count($list) - $calculatedCount;
    $curRate  = getExchangeRate($month, $year);

    // ④ ملخّص الشهر (المحتسَبون فقط — نفس الأعمدة المخزّنة بالقسيمة) + ⑤ فرق عن الشهر الماضي + «لازم تشوفهم»
    $S = ['gross'=>0,'ret'=>0,'net'=>0,'fam'=>0,'tr'=>0,'due'=>0,'due_usd'=>0.0,'net_usd'=>0.0,'school'=>0,'prev_due'=>0,'prev_n'=>0,
          'base'=>0,'extra'=>0,'aide'=>0];
    $pendList = []; $anomList = []; $gapList = []; $newN = 0; $gpAll = [];
    foreach ($list as $r) {
        if ($r['is_calculated']) {
            $S['net']  += (int)$r['net_salary_lbp'];   $S['ret'] += (int)$r['total_retenues_lbp'];
            $S['fam']  += (int)$r['family_allowance_lbp']; $S['tr'] += (int)$r['transport_lbp'];
            $S['due']  += (int)$r['total_due_lbp'];    $S['due_usd'] += rowUsd($r, 'total_due_usd', 'total_due_lbp'); $S['net_usd'] += rowUsd($r, 'net_salary_usd', 'net_salary_lbp');
            $S['base'] += (int)$r['base_plus_echelon_lbp']; $S['extra'] += (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp']; $S['aide'] += (int)$r['aide_complementaire_lbp'];
            $S['school'] += (int)$r['school_cnss_8_lbp'] + (int)$r['school_eoc_6_lbp'] + (int)$r['school_family_comp_6_lbp'] + (int)$r['school_end_of_service_8_5_lbp'];
            if ((int)$r['net_salary_lbp'] <= 0) $anomList[] = $r;
            if ($r['prev_calc'] && (int)$r['prev_due_lbp'] > 0) {
                $S['prev_due'] += (int)$r['prev_due_lbp']; $S['prev_n']++;
                $gpAll[] = [$r, ((int)$r['total_due_lbp'] - (int)$r['prev_due_lbp']) / (int)$r['prev_due_lbp']];
            }
        } else {
            $pendList[] = $r;
        }
    }
    // ③ الفرق «غير الاعتيادي»: زيادة عامّة للكل (سنة جديدة/قانون/سعر صرف) ليست خطأ — وسيط **فئته** (ملاك/متعاقد/موظف) هو المرجع،
    //    ومَن ابتعد عن زملاء فئته أكثر من 15 نقطة يُنبَّه. $gpMed = وسيط الكل (للعنوان).
    $medOf = function (array $vals): float { sort($vals); $c = count($vals); if (!$c) return 0.0; return $c % 2 ? $vals[intdiv($c, 2)] : ($vals[$c / 2 - 1] + $vals[$c / 2]) / 2; };
    $gpMed = $medOf(array_map(fn($x) => $x[1], $gpAll));
    $byType = []; foreach ($gpAll as [$r, $gp]) $byType[$r['employee_type']][] = $gp;
    $medType = array_map($medOf, $byType);
    // الشهر الماضي من سنة دراسية أخرى (مثل أيلول قبل تشرين الأول) ⇒ الفروقات طبيعية (سنة جديدة: درجات/قانون/سعر) — لا تنبيهات، العمود يبقى
    $pmSameSy = (schoolYearOfMonth((int)$pmY, (int)$pmM) === $msSchoolYear);
    if ($pmSameSy) foreach ($gpAll as [$r, $gp]) { if (abs($gp - ($medType[$r['employee_type']] ?? $gpMed)) > 0.15) $gapList[] = [$r, $gp]; }
    $S['gross'] = $S['net'] + $S['ret']; // الراتب المركّب (قبل المحسومات) = الصافي + المحسومات (أعمدة القسيمة نفسها)
    $deltaPct = ($S['prev_n'] > 0 && $S['prev_due'] > 0) ? (($S['due'] - $S['prev_due']) / $S['prev_due'] * 100) : null;

    // ⑤ رسم 4 أشهر (مجموع المتوجب لموظفي اللائحة نفسها)
    $spark = [];
    if ($list) {
        $ids = implode(',', array_map('intval', array_column($list, 'id')));
        $ym = []; for ($i = 3; $i >= 0; $i--) { $mm = $month - $i; $yy = $year; while ($mm <= 0) { $mm += 12; $yy--; } $ym[] = [$yy, $mm]; }
        $ymSql = implode(' OR ', array_map(fn($p) => "(year = {$p[0]} AND month = {$p[1]})", $ym));
        $sp = $db->query("SELECT year, month, COALESCE(SUM(total_due_lbp),0) t FROM monthly_salaries WHERE is_calculated = 1 AND employee_id IN ($ids) AND ($ymSql) GROUP BY year, month")->fetchAll();
        $spMap = []; foreach ($sp as $row) $spMap[$row['year'] . '-' . $row['month']] = (float)$row['t'];
        foreach ($ym as $p) $spark[] = ['lbl' => monthName($p[1], 'fr', true) . ' ' . $p[0], 'v' => $spMap[$p[0] . '-' . $p[1]] ?? 0.0];
    }
    $spMax = max(1.0, max(array_column($spark ?: [['v'=>1]], 'v')));

    // ② الخطوات: البيانات ✓ ← المراجعة (قيد الانتظار/تنبيهات) ← الإقفال (قفل السنة للمدرسة) ← القسائم
    $alertsN = count($anomList) + count($gapList);
    $lockedSy = (!isAllSchools() && currentSchoolId()) ? isSchoolYearLocked((int)currentSchoolId(), $msSchoolYear) : false;
    $s2done = ($pendingN === 0 && $alertsN === 0 && count($list) > 0);
    $stepCur = !$s2done ? 2 : (!$lockedSy ? 3 : 4);
    $prevLbl = monthName($pmM, 'fr', true);
    $avC = ['ic2','ic4','ic5','ic6','ic1','ic3'];
    $mpBase = BASE_URL . 'pages/monthly_payroll.php?month=' . $month . '&year=' . $year;
    // 📅 v2026 شريط الأشهر (BambooHR/Deel): 12 شهراً ت1←أيلول + شهر 13 — حالة كل شهر من الداتا: مقفول (سنة مقفولة ومحسوب) / مكتمل / الحالي / متأخّر (مضى وفيه بانتظار) / قادم
    $syY = (int)substr($msSchoolYear, 0, 4); $moStrip = [];
    $moIds = array_map('intval', array_column($list, 'id')); $moN = count($moIds);
    $moCalc = [];
    if ($moN) { foreach ($db->query("SELECT year, month, COUNT(*) c FROM monthly_salaries WHERE is_calculated = 1 AND school_year = " . $db->quote($msSchoolYear) . " AND employee_id IN (" . implode(',', $moIds) . ") GROUP BY year, month") as $r) $moCalc[$r['year'] . '-' . (int)$r['month']] = (int)$r['c']; }
    $nowKey = (int)date('Y') * 100 + (int)date('n');
    foreach (array_merge(range(10, 12), range(1, 9), [13]) as $mm) {
        $yy = $mm >= 10 && $mm <= 12 ? $syY : $syY + 1; if ($mm === 13) { $yy = $syY + 1; }
        $c = $moCalc[$yy . '-' . $mm] ?? 0; $isCur = ($mm === $month && $yy === $year);
        $past = ($mm === 13) ? false : ($yy * 100 + $mm) < $nowKey;
        $st = $isCur ? 'cur' : ($c >= $moN && $moN > 0 ? ($lockedSy ? 'lock' : 'done') : ($past && $c < $moN ? 'late' : ($c > 0 ? 'ready' : 'next')));
        $moStrip[] = ['m' => $mm, 'y' => $yy, 'c' => $c, 'st' => $st];
    }
    $moLbl = ['lock' => 'Verrouillé / مقفول', 'done' => 'Complet / مكتمل', 'cur' => 'En cours / الحالي', 'late' => 'En retard / متأخّر', 'ready' => 'Calculé d’avance / محسوب مسبقاً', 'next' => 'À venir / قادم'];
?>
    <script>window.MSA_ACTIONS = [
        <?php if (!isAllSchools()): ?>{ t: 'Calculer tout — <?= monthName($month) ?> <?= $year ?> / احتساب الكل', h: '?action=calc_all&month=<?= $month ?>&year=<?= $year ?><?= e($typeQ) ?>', k: '⚡', confirm: 'احتساب رواتب كل الموظفين المعروضين لهذا الشهر؟' },<?php endif; ?>
        { t: 'Imprimer les bulletins / طباعة القسائم', h: '?action=print_all&month=<?= $month ?>&year=<?= $year ?><?= e($typeQ) ?>', k: '🖨' },
        { t: 'Mois précédent / الشهر الماضي — <?= monthName($pmM) ?> <?= $pmY ?>', h: '?month=<?= $pmM ?>&year=<?= $pmY ?><?= e($typeQ) ?>', k: '←' },
        { t: 'Relevé annuel / الكشف السنوي', h: '<?= BASE_URL ?>pages/annual_slip.php', k: '' }
    ];</script>
    <div class="mp-months no-print" aria-label="Mois de l’année / أشهر السنة">
        <?php foreach ($moStrip as $ms): ?>
        <a class="mo <?= $ms['st'] ?>" href="?month=<?= $ms['m'] === 13 ? 13 : $ms['m'] ?>&year=<?= $ms['y'] ?><?= e($typeQ) ?>" title="<?= e($moLbl[$ms['st']]) ?> · <?= $ms['c'] ?>/<?= $moN ?>">
            <b><?= $ms['m'] === 13 ? '13e' : monthName($ms['m'], 'fr', true) ?></b><small><?= $ms['m'] === 13 ? 'شهر 13' : monthName($ms['m'], 'ar') ?></small>
            <span class="st"><?= $ms['st'] === 'cur' ? 'En cours' : ($ms['st'] === 'lock' ? '🔒' : ($ms['st'] === 'done' ? '✓' : ($ms['st'] === 'late' ? '⚠ ' . ($moN - $ms['c']) : ($ms['st'] === 'ready' ? '✓ ' . $ms['c'] : '·')))) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php $mpLegal = null; foreach (($msaBell ?? []) as $mb) if (($mb[6] ?? '') === 'legal' || ($mb[6] ?? '') === 'legal_changed') { $mpLegal = $mb; break; } if ($mpLegal): /* ⚖️ v2026 أقرب موعد للدولة تحت شريط الأشهر */ ?>
    <a class="mp-legal-line no-print" href="<?= e($mpLegal[5]) ?>" style="--c:<?= e($mpLegal[1]) ?>"><i class="fas fa-landmark"></i> <b><?= e($mpLegal[3]) ?></b> <span><?= e($mpLegal[4]) ?></span> <i class="fas fa-chevron-left"></i></a>
    <?php endif; ?>
    <div class="mp-steps no-print" data-sec-title="Paie de <?= monthName($month) ?> <?= $year ?>">
        <?php $steps = [
            [1, 'Données', 'البيانات', count($list) . ' employés / موظفاً', true, '#mpTable'],
            [2, 'Vérification', 'المراجعة', ($pendingN ? $pendingN . ' en attente' : 'tous calculés') . ($alertsN ? ' · ' . $alertsN . ' alertes' : ''), $s2done, '#mpCheck'],
            [3, 'Clôture', 'الإقفال', $lockedSy ? 'Année verrouillée / السنة مقفولة' : 'Verrouiller l’année / قفل السنة', $lockedSy, (canEdit() ? BASE_URL . 'pages/open_year.php' : '#')],
            [4, 'Bulletins', 'القسائم', 'PDF · WhatsApp · Email', $stepCur === 4, $mpBase . '&action=print_all' . $typeQ],
        ];
        foreach ($steps as [$n, $fr, $ar, $sub, $done, $href]): $cls = $done ? 'done' : ($stepCur === $n ? 'cur' : ''); ?>
        <a class="mp-step <?= $cls ?>" href="<?= e($href) ?>"><span class="mp-n"><?= $done ? '✓' : $n ?></span><span><b><span dir="ltr"><?= $n ?> · <?= $fr ?></span> / <?= $ar ?></b><small><?= e($sub) ?></small></span></a>
        <?php endforeach; ?>
    </div>

    <div class="stats-grid mp-kpis">
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-users"></i></div>
            <div><div class="stat-label">Actifs / الموظفون الفاعلون</div><div class="stat-value"><?= count($list) ?></div></div>
            <?php if ($newN = count(array_filter($list, fn($r) => isNewHireInYear(employeeRowCached($db, (int)$r['id']) ?? [], $msSchoolYear)))): ?><span class="mp-d nt"><?= $newN ?> 🆕</span><?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="fas fa-check"></i></div>
            <div><div class="stat-label">Calculés / المحتسَبون</div><div class="stat-value"><?= $calculatedCount ?></div></div>
            <?php if (count($list)): ?><span class="mp-d <?= $pendingN ? 'nt' : 'up' ?>"><?= round($calculatedCount * 100 / count($list)) ?>%</span><?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
            <div><div class="stat-label">En attente / قيد الانتظار</div><div class="stat-value"><?= $pendingN ?></div></div>
            <?php if ($alertsN): ?><a class="mp-d dn" href="#mpCheck"><?= $alertsN ?> alertes</a><?php endif; ?>
        </div>
        <div class="stat-card mp-kpi-money">
            <div class="stat-icon gold"><i class="fas fa-money-bill"></i></div>
            <div><div class="stat-label">Total dû / الإجمالي المتوجب</div><div class="stat-value" style="font-size:18px"><?= money($totalDue, $curRate) ?></div></div>
            <div class="mp-spark-box">
                <div class="mp-spark" title="<?= e(implode(' · ', array_map(fn($s) => $s['lbl'] . ': ' . moneyText($s['v'], $curRate, ['withCur' => false]), $spark))) ?>">
                    <?php foreach ($spark as $s): ?><s style="height:<?= max(3, round($s['v'] / $spMax * 30)) ?>px" title="<?= e($s['lbl']) ?>: <?= e(moneyText($s['v'], $curRate)) ?>"></s><?php endforeach; ?>
                </div>
                <?php if ($deltaPct !== null): ?><span class="mp-d <?= $deltaPct > 0.05 ? 'up' : ($deltaPct < -0.05 ? 'dn' : 'nt') ?>" title="vs <?= e($prevLbl) ?> — الفرق عن الشهر الماضي (الإجمالي المتوجب)"><?= ($deltaPct > 0 ? '+' : '') . number_format($deltaPct, 1) ?>%</span><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="mp-layout">
    <div class="mp-main">
        <div class="mp-tool no-print">
            <label class="mp-ck-all no-print" title="Tout sélectionner / اختيار الكل"><input type="checkbox" id="mpCkAll"></label>
            <input type="search" id="mpSearch" class="form-control" placeholder="🔍 Nom, code, école… / اسم، رمز، مدرسة — النتيجة فورية" autocomplete="off">
            <span class="mp-count" id="mpCount"></span>
            <button type="button" class="btn btn-sm btn-light mp-tog" id="mpTogDet" data-k="det" title="Afficher base / supplément / primes / retenues"><i class="fas fa-table-columns"></i> Détails / التفاصيل</button>
            <button type="button" class="btn btn-sm btn-light mp-tog" id="mpTogCmp" data-k="cmp" title="Comparer au mois précédent"><i class="fas fa-right-left"></i> Comparer à <?= e($prevLbl) ?> / قارن</button>
        </div>

        <div class="card" id="mpTable">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-money-check-alt"></i> Paie de <?= monthName($month) ?> <?= $year ?> — <?= e(currentSchoolName()) ?></span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">رواتب الشهر</div>
                </h3>
            </div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table class="table mp-table">
                        <thead>
                            <tr>
                                <th>Employé / الموظف<small class="mp-th-sub">Code · École / الرمز · المدرسة</small></th>
                                <th>Catégorie / الفئة<small class="mp-th-sub">Échelon / الدرجة</small></th>
                                <th class="mp-det text-end">Base + éch. / الأساس</th>
                                <th class="mp-det text-end">Supplément / الإضافي</th>
                                <th class="mp-det text-end">Prime &amp; aide / المكافأة</th>
                                <th class="mp-det text-end">Retenues / المحسومات</th>
                                <th class="text-end">Net salaire / الصافي</th>
                                <th class="text-end">Total dû / الإجمالي المتوجب<small class="mp-th-sub">L.L · $</small></th>
                                <th class="mp-cmp">vs <?= e($prevLbl) ?> / الفرق</th>
                                <th>Statut / الحالة</th>
                                <th class="no-print"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $mpT = ['net'=>0,'due'=>0,'due_usd'=>0.0,'net_usd'=>0.0,'n'=>0];
                            foreach ($list as $i => $r):
                                if ($r['is_calculated']) { $mpT['net'] += (int)$r['net_salary_lbp']; $mpT['due'] += (int)$r['total_due_lbp']; $mpT['due_usd'] += rowUsd($r, 'total_due_usd', 'total_due_lbp'); $mpT['net_usd'] += rowUsd($r, 'net_salary_usd', 'net_salary_lbp'); $mpT['n']++; }
                                $nameFr = empFullNameFr($r); $nameAr = empFullNameAr($r);
                                $ini = mb_strtoupper(mb_substr(trim((string)$r['first_name_fr']), 0, 1) . mb_substr(trim((string)$r['last_name_fr']), 0, 1));
                                $schN = schoolNameById($r['school_id']);
                                $q = mb_strtolower($nameFr . ' ' . $nameAr . ' ' . $r['employee_code'] . ' ' . $schN . ' ' . employeeTypeLabel($r['employee_type']) . ' ' . ($r['is_calculated'] ? 'calculé محتسب' : 'attente انتظار'));
                                $gp = null; $gpCls = 'eq'; $gpTxt = '—';
                                if ($r['is_calculated'] && $r['prev_calc'] && (int)$r['prev_due_lbp'] > 0) {
                                    $gp = ((int)$r['total_due_lbp'] - (int)$r['prev_due_lbp']) / (int)$r['prev_due_lbp'] * 100;
                                    $gpCls = $gp > 0.5 ? 'up' : ($gp < -0.5 ? 'dn' : 'eq'); $gpTxt = $gpCls === 'eq' ? '=' : (($gp > 0 ? '+' : '') . number_format($gp, 1) . '%');
                                } elseif ($r['is_calculated'] && !$r['prev_calc']) { $gpCls = 'nw'; $gpTxt = 'Nouveau'; }
                                $viewUrl = '?employee_id=' . (int)$r['id'] . '&month=' . $month . '&year=' . $year;
                            ?>
                                <tr class="mp-row" data-q="<?= e($q) ?>" data-url="<?= e($viewUrl) ?>" data-name="<?= e($nameFr) ?>" data-sub="<?= e($r['employee_code'] . ' · ' . $schN) ?>">
                                    <td>
                                        <div class="mp-emp"><label class="mp-ck-w no-print" title="Sélectionner / اختيار"><input type="checkbox" class="mp-ck" value="<?= (int)$r['id'] ?>"></label><?= empAvatar($r, $avC[$i % 6]) ?>
                                            <span><b><?= e($nameFr) ?></b><?= empBadges($r, $db, $msSchoolYear) ?><small><?= e($r['employee_code']) ?> · <?= e($schN) ?></small></span></div>
                                    </td>
                                    <td><small><?= employeeTypeLabel($r['employee_type']) ?></small><small class="mp-sub">Échelon <?= e(gradeDisplay($r)) ?></small></td>
                                    <td class="mp-det text-end"><?= $r['is_calculated'] ? moneyLaw($r['base_plus_echelon_lbp'], [], $r, 'bpe') : '—' ?></td>
                                    <td class="mp-det text-end"><?= $r['is_calculated'] ? extraWageMoney($r) : '—' ?></td>
                                    <td class="mp-det text-end"><?= $r['is_calculated'] ? money((int)$r['aide_complementaire_lbp'], rowRate($r)) : '—' ?></td>
                                    <td class="mp-det text-end text-danger"><?= $r['is_calculated'] ? '−' . money($r['total_retenues_lbp'], rowRate($r)) : '—' ?></td>
                                    <td class="text-end mp-net"><?= $r['is_calculated'] ? money($r['net_salary_lbp'], rowRate($r)) : '—' ?>
                                        <?php if ($r['prev_calc']): ?><small class="mp-prev mp-cmp"><?= e($prevLbl) ?> <?= money($r['prev_net_lbp'], $curRate, ['withCur' => false, 'stacked' => false]) ?></small><?php endif; ?></td>
                                    <td class="text-end"><strong><?= $r['is_calculated'] ? money($r['total_due_lbp'], rowRate($r)) : '—' ?></strong></td>
                                    <td class="mp-cmp"><?php if ($r['is_calculated']): ?><span class="mp-dl <?= $gpCls ?>"><?= $gpTxt ?></span><?php endif; ?></td>
                                    <td>
                                        <?php if ($r['is_calculated']): ?>
                                            <span class="badge badge-success">✓ Calculé / محتسَب</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">En attente / قيد الانتظار</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print mp-act">
                                        <div class="mp-menu">
                                            <button type="button" class="btn btn-sm btn-light mp-dots" title="Actions / إجراءات" aria-label="Actions">⋮</button>
                                            <div class="mp-menu-list">
                                                <a href="<?= e($viewUrl) ?>"><i class="fas fa-file-invoice"></i> Voir le bulletin / عرض القسيمة</a>
                                                <?php if (!isAllSchools()): ?><a href="?action=calc&employee_id=<?= (int)$r['id'] ?>&month=<?= $month ?>&year=<?= $year ?>"><i class="fas fa-calculator"></i> <?= $r['is_calculated'] ? 'Recalculer / إعادة الاحتساب' : 'Calculer / احتساب' ?></a><?php endif; ?>
                                                <?php if (canEdit()): ?><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['id'] ?>"><i class="fas fa-pen"></i> Modifier le dossier / تعديل الملف</a><?php endif; ?>
                                                <?php if (viewerCanSeePage('attestations.php')): ?><a href="<?= BASE_URL ?>pages/attestations.php?dossier=1&employee_id=<?= (int)$r['id'] ?>"><i class="fas fa-folder-open"></i> Dossier complet / الملف الكامل</a><?php endif; ?>
                                                <a href="<?= BASE_URL ?>pages/employee_full_history.php?employee_id=<?= (int)$r['id'] ?>"><i class="fas fa-clock-rotate-left"></i> Historique / التاريخ الكامل</a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($mpT['n'] > 0): ?><tfoot><tr class="total-row" style="font-weight:700;background:var(--gold-light,#fdf6e3)">
                            <td colspan="2" style="text-align:right">المجموع (المحتسَبون: <?= $mpT['n'] ?>) / Total</td>
                            <td class="mp-det text-end"><?= money($S['base'], $curRate) ?></td>
                            <td class="mp-det text-end"><?= money($S['extra'], $curRate) ?></td>
                            <td class="mp-det text-end"><?= money($S['aide'], $curRate) ?></td>
                            <td class="mp-det text-end text-danger">−<?= money($S['ret'], $curRate) ?></td>
                            <td class="text-end"><?= dualFromUsd($mpT['net'], $mpT['net_usd']) ?></td>
                            <td class="text-end"><strong><?= dualFromUsd($mpT['due'], $mpT['due_usd']) ?></strong></td>
                            <td class="mp-cmp"><?php if ($deltaPct !== null): ?><span class="mp-dl <?= $deltaPct > 0.5 ? 'up' : ($deltaPct < -0.5 ? 'dn' : 'eq') ?>"><?= ($deltaPct > 0 ? '+' : '') . number_format($deltaPct, 1) ?>%</span><?php endif; ?></td>
                            <td></td><td class="no-print"></td>
                        </tr></tfoot><?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <aside class="mp-side no-print no-export">
        <div class="card mp-sum" data-sec-title="Résumé du mois / ملخّص الشهر">
            <div class="card-header"><h3><span dir="ltr"><i class="fas fa-calculator"></i> Résumé du mois</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">ملخّص الشهر — <?= monthName($month, 'ar') ?> <?= $year ?></div></h3></div>
            <div class="card-body">
                <div class="mp-ln"><span>Base + échelon / الأساس والدرجة</span><b><?= money($S['base'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-ln"><span>Supplément / الأجر الإضافي</span><b><?= money($S['extra'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-ln"><span>Prime &amp; aide / المكافأة والمساعدة</span><b><?= money($S['aide'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-ln mp-ln-ret"><span>Retenues / المحسومات</span><b>− <?= money($S['ret'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-ln mp-ln-net"><span>Net / الصافي</span><b><?= money($S['net'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-ln"><span>Alloc. familiales / التعويض العائلي</span><b>+ <?= money($S['fam'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-ln"><span>Transport / النقل</span><b>+ <?= money($S['tr'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-big"><small>Total dû / الإجمالي المتوجب</small><?= dualFromUsd($S['due'], $S['due_usd']) ?><span class="money-usd"><?= number_format($curRate) ?> L.L/$</span></div>
                <div class="mp-prog"><i style="width:<?= count($list) ? round($calculatedCount * 100 / count($list), 1) : 0 ?>%"></i></div>
                <div class="mp-prog-lbl"><?= $calculatedCount ?> / <?= count($list) ?> calculés / محتسَبون</div>
                <div class="mp-ln mp-ln-school" title="CNSS 8% + Caisse 6% + Alloc. 6% + Fin de service 8.5% — ما تدفعه المدرسة فوق الرواتب"><span>Charges école / أعباء المدرسة</span><b><?= money($S['school'], $curRate, ['withCur' => false, 'stacked' => false]) ?></b></div>
                <div class="mp-actions">
                    <?php if (!isAllSchools()): ?>
                    <a href="?action=calc_all&month=<?= $month ?>&year=<?= $year ?><?= $typeQ ?>" class="btn btn-gold" data-confirm="احتساب رواتب كل الموظفين المعروضين لهذا الشهر؟"><i class="fas fa-bolt"></i> Calculer tout / احتساب الكل</a>
                    <?php endif; ?>
                    <a href="?action=print_all&month=<?= $month ?>&year=<?= $year ?><?= $typeQ ?>" class="btn btn-primary" title="Afficher/imprimer le bulletin de chaque employé / عرض/طباعة قسيمة كل موظف"><i class="fas fa-file-invoice"></i> Imprimer les bulletins / طباعة القسائم</a>
                </div>
            </div>
        </div>

        <div class="card mp-check" id="mpCheck" data-sec-title="À vérifier / لازم تشوفهم">
            <div class="card-header"><h3><span dir="ltr"><i class="fas fa-list-check"></i> À vérifier</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">لازم تشوفهم قبل الطباعة</div></h3></div>
            <div class="card-body">
                <?php if (!$pendList && !$anomList && !$gapList): ?>
                    <div class="mp-ok"><i class="fas fa-circle-check"></i> Rien à signaler / ما في شي معلّق</div>
                <?php endif; ?>
                <?php if (!$pmSameSy && $S['prev_n'] > 0): ?>
                <div class="mp-alert" style="background:#eef4fd;border-color:#c7d8f0"><i style="background:var(--primary)"></i><div><b>Nouvelle année scolaire</b> — <?= e($prevLbl) ?> من السنة الماضية، فالفرق عنه (الأغلبية <?= ($gpMed > 0 ? '+' : '') . number_format($gpMed * 100, 0) ?>%) طبيعي: درجات وقانون وسعر جديد. التنبيه على الفروقات غير الاعتيادية بيبلّش من الشهر الجاي.</div></div>
                <?php endif; ?>
                <?php if ($pendList): ?>
                <div class="mp-alert wa"><i></i><div><b><?= count($pendList) ?> en attente / قيد الانتظار</b> — ما انحسب راتبهم بعد: <?= e(implode('، ', array_map(fn($r) => empFullNameFr($r), array_slice($pendList, 0, 5)))) ?><?= count($pendList) > 5 ? '…' : '' ?>
                    <a href="#" class="mp-filter" data-q="attente">Voir / شوفهم →</a></div></div>
                <?php endif; ?>
                <?php foreach (array_slice($anomList, 0, 5) as $r): ?>
                <div class="mp-alert dn"><i></i><div><b><?= e(empFullNameFr($r)) ?></b> — صافي صفر أو أقل، يرجّح خطأ بالملف. <a href="?employee_id=<?= (int)$r['id'] ?>&month=<?= $month ?>&year=<?= $year ?>">Ouvrir / افتح →</a></div></div>
                <?php endforeach; ?>
                <?php if ($gapList): ?>
                <div class="mp-alert dn"><i></i><div><b><?= count($gapList) ?> écart<?= count($gapList) > 1 ? 's' : '' ?> inhabituel<?= count($gapList) > 1 ? 's' : '' ?> vs <?= e($prevLbl) ?></b> — فرق غير اعتيادي عن الشهر الماضي مقارنةً بزملاء فئته (الأغلبية: <?= ($gpMed > 0 ? '+' : '') . number_format($gpMed * 100, 0) ?>%):
                    <?php foreach (array_slice($gapList, 0, 5) as [$r, $gp]): ?><a href="?employee_id=<?= (int)$r['id'] ?>&month=<?= $month ?>&year=<?= $year ?>"><?= e(empFullNameFr($r)) ?> (<?= ($gp > 0 ? '+' : '') . number_format($gp * 100, 0) ?>%)</a><?= $r === end($gapList)[0] ? '' : '، ' ?><?php endforeach; ?><?= count($gapList) > 5 ? '…' : '' ?>
                    <a href="#" class="mp-cmp-on">Comparer / قارن →</a></div></div>
                <?php endif; ?>
            </div>
        </div>
    </aside>
    </div>

    <?php /* ⑧ درج القسيمة: الكبس على سطر الموظف يجلب قسيمته (نفس صفحة القسيمة الفردية — نفس الأرقام) ويعرضها بالجنب بلا مغادرة اللائحة */ ?>
    <div class="mp-ov" id="mpOv"></div>
    <div class="mp-drawer no-print no-export" id="mpDrawer" aria-hidden="true">
        <div class="mp-dr-head">
            <div><b id="mpDrName">—</b><small id="mpDrSub">—</small></div>
            <button type="button" class="btn btn-sm btn-light" id="mpDrClose" title="Fermer / إغلاق">✕</button>
        </div>
        <div class="mp-dr-btns">
            <a class="btn btn-sm btn-primary" id="mpDrOpen" href="#"><i class="fas fa-up-right-from-square"></i> Page complète / الصفحة الكاملة (طباعة · PDF · واتساب)</a>
            <?php if (!isAllSchools()): ?><a class="btn btn-sm btn-gold" id="mpDrCalc" href="#"><i class="fas fa-calculator"></i> Calculer / احتساب</a><?php endif; ?>
        </div>
        <div class="mp-dr-body" id="mpDrBody"><div class="mp-dr-loading"><i class="fas fa-spinner fa-spin"></i> Chargement… / عم يحمّل…</div></div>
    </div>
    <script>
    (function () {
        var body = document.body;
        // ① الأعمدة التفصيلية والمقارنة — يتذكّر خياره على هالجهاز
        function pref(k, d) { try { var v = localStorage.getItem('mp_' + k); return v === null ? d : v === '1'; } catch (e) { return d; } }
        function setPref(k, v) { try { localStorage.setItem('mp_' + k, v ? '1' : '0'); } catch (e) {} }
        function apply() {
            var det = pref('det', false), cmp = pref('cmp', true);
            body.classList.toggle('mp-show-det', det); body.classList.toggle('mp-show-cmp', cmp);
            var a = document.getElementById('mpTogDet'), b = document.getElementById('mpTogCmp');
            if (a) a.classList.toggle('on', det); if (b) b.classList.toggle('on', cmp);
            if (window.msaRefreshStickyHeads) try { window.msaRefreshStickyHeads(); } catch (e) {}
        }
        document.querySelectorAll('.mp-tog').forEach(function (btn) {
            btn.addEventListener('click', function () { var k = btn.getAttribute('data-k'); setPref(k, !pref(k, k === 'cmp')); apply(); });
        });
        document.querySelectorAll('.mp-cmp-on').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); setPref('cmp', true); apply(); document.getElementById('mpTable').scrollIntoView({ behavior: 'smooth' }); }); });
        apply();

        // 🔍 بحث فوري بالاسم/الرمز/المدرسة/الحالة
        var rows = Array.prototype.slice.call(document.querySelectorAll('tr.mp-row')), inp = document.getElementById('mpSearch'), cnt = document.getElementById('mpCount');
        function filter() {
            var q = (inp.value || '').trim().toLowerCase(), n = 0;
            rows.forEach(function (tr) { var ok = !q || tr.getAttribute('data-q').indexOf(q) !== -1; tr.style.display = ok ? '' : 'none'; if (ok) n++; });
            if (cnt) cnt.textContent = q ? (n + ' / ' + rows.length) : rows.length + ' employés / موظفاً';
        }
        function filterM() { filter(); var n = rows.filter(function (tr) { return tr.style.display !== 'none'; }).length; if (window.msaFilterEmpty) msaFilterEmpty(inp, n); }
        if (inp) { inp.addEventListener('input', filterM); filterM(); }
        document.addEventListener('DOMContentLoaded', function () { if (window.msaBulk) msaBulk({ actions: [
            { label: 'Imprimer les bulletins / طباعة قسائم المختارين', icon: 'fa-print', cls: 'btn-primary', href: function (ids) { return '?action=print_all&month=<?= $month ?>&year=<?= $year ?><?= e($typeQ) ?>&ids=' + ids; } }<?php if (!isAllSchools() && canEdit()): ?>,
            { label: 'Calculer / احتساب المختارين', icon: 'fa-calculator', cls: 'btn-gold', confirm: 'احتساب رواتب {n} موظف لهذا الشهر؟', href: function (ids) { return '?action=calc_all&month=<?= $month ?>&year=<?= $year ?><?= e($typeQ) ?>&ids=' + ids; } }<?php endif; ?>
        ] }); });
        document.querySelectorAll('.mp-filter').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); inp.value = a.getAttribute('data-q'); filter(); document.getElementById('mpTable').scrollIntoView({ behavior: 'smooth' }); }); });

        // ⋮ قائمة السطر
        document.addEventListener('click', function (e) {
            var d = e.target.closest('.mp-dots');
            document.querySelectorAll('.mp-menu.open').forEach(function (m) { if (!d || m !== d.parentElement) m.classList.remove('open'); });
            if (d) { e.preventDefault(); e.stopPropagation(); d.parentElement.classList.toggle('open'); }
        });

        // ⑧ الدرج: الكبس على السطر (لا على رابط/زر) يجلب القسيمة
        var dr = document.getElementById('mpDrawer'), ov = document.getElementById('mpOv'), dbody = document.getElementById('mpDrBody');
        document.body.appendChild(ov); document.body.appendChild(dr); // fixed حقيقي: خارج أي حاوية لها transform/zoom (وإلا تُقصّ النافذة)
        function closeDr() { dr.classList.remove('open'); ov.classList.remove('open'); dr.setAttribute('aria-hidden', 'true'); }
        function openDr(tr) {
            var url = tr.getAttribute('data-url');
            document.getElementById('mpDrName').textContent = tr.getAttribute('data-name');
            document.getElementById('mpDrSub').textContent = tr.getAttribute('data-sub');
            document.getElementById('mpDrOpen').href = url;
            var c = document.getElementById('mpDrCalc'); if (c) c.href = url.replace('?', '?action=calc&');
            dbody.innerHTML = '<div class="mp-dr-loading"><i class="fas fa-spinner fa-spin"></i> Chargement… / عم يحمّل…</div>';
            dr.classList.add('open'); ov.classList.add('open'); dr.setAttribute('aria-hidden', 'false');
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) {
                var doc = new DOMParser().parseFromString(h, 'text/html'), slip = doc.getElementById('ppExportArea');
                if (!slip) { dbody.innerHTML = '<div class="alert alert-warning">—</div>'; return; }
                slip.querySelectorAll('.card-header, .no-print').forEach(function (x) { x.remove(); });
                dbody.innerHTML = ''; dbody.appendChild(slip);
            }).catch(function () { dbody.innerHTML = '<div class="alert alert-danger">Erreur de chargement / تعذّر التحميل</div>'; });
        }
        rows.forEach(function (tr) {
            tr.addEventListener('click', function (e) { if (e.target.closest('a, button, input, label, .mp-menu')) return; openDr(tr); });
        });
        document.getElementById('mpDrClose').addEventListener('click', closeDr);
        ov.addEventListener('click', closeDr);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDr(); });
    })();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
