<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();

$currentPage = 'monthly';
$pageTitle = 'Paie mensuelle / الرواتب الشهرية';
$db = getDB();

$action = $_GET['action'] ?? 'list';
$employeeId = (int)($_GET['employee_id'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
// فلتر النوع: '' = الكل، أو نوع محدّد (ملاك/متعاقد/موظف)
$allowedTypes = ['enseignant_titulaire', 'enseignant_contractuel', 'employe'];
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = '';
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
    ob_start();
    ?>
    <div class="card payslip-card" style="page-break-inside:avoid">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-user"></i> <?= e(trim($emp['first_name_fr'].' '.$emp['last_name_fr']) ?: trim($emp['first_name_ar'].' '.$emp['last_name_ar'])) ?> — <?= monthName($month) ?> <?= $year ?></span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: 'قسيمة الراتب') ?></div>
            </h3>
            <div style="font-size:13px;color:var(--gray-600)"><?= e(currentSchoolName()) ?></div>
        </div>
        <div class="card-body">
            <table class="table" style="margin-bottom:16px">
                <tr><th colspan="4" style="background:#eef3fb;color:#000"><i class="fas fa-id-badge"></i> Informations / المعلومات</th></tr>
                <tr>
                    <td style="width:25%">Nom / الاسم</td><td style="width:25%"><strong><?= e($emp['first_name_fr'].' '.$emp['last_name_fr']) ?></strong><br><small class="text-rtl"><?= e(trim($emp['first_name_ar'].' '.$emp['last_name_ar'])) ?></small></td>
                    <td style="width:25%">Type / النوع</td><td style="width:25%"><strong><?= employeeTypeLabel($emp['employee_type']) ?></strong></td>
                </tr>
                <tr>
                    <td>Année scolaire / السنة الدراسية</td><td><strong><?= e($schoolYearLbl) ?></strong></td>
                    <td>Date d'embauche / تاريخ الدخول</td><td><strong><?= formatDate($emp['hire_date']) ?></strong></td>
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
                        <tr><td>Salaire de base / أساس الراتب</td><td class="text-end"><?= formatLBP($salary['base_salary_lbp']) ?></td></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Échelons / الدرجات (G<?= $salary['grade_at_month'] ?>)</td><td class="text-end"><?= formatLBP($salary['echelon_value_lbp']) ?></td></tr><?php endif; ?>
                        <?php if (!$isAdminEmp): ?><tr style="background:var(--gray-50)"><td><strong>Base + Échelon</strong></td><td class="text-end"><strong><?= formatLBP($salary['base_plus_echelon_lbp']) ?></strong></td></tr><?php endif; ?>
                        <tr><td>الأجر الإضافي / Supplément</td><td class="text-end"><?= money((int)$salary['extra_lbp'] + (int)$salary['prime_fixe_lbp'], rowRate($salary)) ?></td></tr>
                        <tr><td>مكافأة ومساعدة / Prime &amp; aide</td><td class="text-end"><?= money((int)$salary['aide_complementaire_lbp'], rowRate($salary)) ?></td></tr>
                        <tr style="background:#eef2ff"><td><strong>الراتب المركّب / Salaire composé</strong><br><small style="color:#64748b"><?= e(salaryCompLabel()) ?></small></td><td class="text-end"><strong><?= money(composedSalaryLbp($salary), rowRate($salary)) ?></strong></td></tr>
                    </table>
                    <table class="table">
                        <tr><th colspan="2" style="background:#ffe3e3;color:#000">RETENUES / المحسومات</th></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Caisse EOC (6%)</td><td class="text-end text-danger">-<?= formatLBP($salary['caisse_amount_lbp']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($salary['eoc_grade_lbp'])): ?><tr><td>درجة / نصف راتب (صندوق)</td><td class="text-end text-danger">-<?= formatLBP($salary['eoc_grade_lbp']) ?></td></tr><?php endif; ?>
                        <tr><td>CNSS (3%)</td><td class="text-end text-danger">-<?= formatLBP($salary['cnss_amount_lbp']) ?></td></tr>
                        <tr><td>Base imposable</td><td class="text-end"><?= formatLBP($salary['taxable_base_lbp']) ?></td></tr>
                        <tr><td>Impôt sur le revenu</td><td class="text-end text-danger">-<?= formatLBP($salary['income_tax_lbp']) ?></td></tr>
                        <tr style="background:#fef2f2"><td><strong>Total retenues</strong></td><td class="text-end"><strong class="text-danger">-<?= formatLBP($salary['total_retenues_lbp']) ?></strong></td></tr>
                    </table>
                </div>
                <table class="table" style="margin-top:16px">
                    <tr style="background:var(--gold-light)">
                        <td><strong>Salaire net</strong></td>
                        <td class="text-end"><strong><?= formatLBP($salary['net_salary_lbp']) ?></strong></td>
                        <td class="text-end text-muted"><?= formatUSD($salary['net_salary_usd']) ?></td>
                    </tr>
                    <tr><td>Allocations familiales (exonérées)</td><td class="text-end text-success">+<?= formatLBP($salary['family_allowance_lbp']) ?></td><td></td></tr>
                    <tr><td>Transport</td><td class="text-end text-success">+<?= money($salary['transport_lbp'], rowRate($salary)) ?></td><td></td></tr>
                    <tr style="background:#fff3cd;color:#000">
                        <td style="font-size:18px"><strong>💰 TOTAL DÛ</strong></td>
                        <td class="text-end" style="font-size:18px"><strong><?= formatLBP($salary['total_due_lbp']) ?></strong></td>
                        <td class="text-end" style="font-size:16px"><strong><?= formatUSD($salary['total_due_usd']) ?></strong></td>
                    </tr>
                </table>
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
        $cfg = $db->prepare("SELECT employee_type, base_salary_usd, contract_salary_lbp FROM employees WHERE id = ?");
        $cfg->execute([$employeeId]); $cfg = $cfg->fetch();
        $hasConfig = $cfg && ($cfg['employee_type'] === 'enseignant_titulaire' || (float)$cfg['base_salary_usd'] > 0 || (float)$cfg['contract_salary_lbp'] > 0);
        if (!$hasConfig) {
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
    // الاحتساب على الفاعلين فقط (status=actif وبلا تاريخ ترك) — بمجرّد أن يترك الأستاذ
    // (حالة ≠ نشط أو تاريخ ترك) لا يُحتسب له راتب فلا يظهر في السنين اللاحقة.
    // 🔴 حماية المنقولين يدوياً: يُحتسب فقط مَن له إعداد فعلي (ملاك أو أساس بالدولار/بالعقد > 0).
    // المتعاقد/الموظف ذو الراتب المنقول (بلا إعداد) لا يُعاد حسابه هنا لئلا يُصفَّر راتبه المخزّن
    // (نفس حماية recalcEmployeeYear / recalcSalariesInRange).
    $sqlC = "SELECT id FROM employees WHERE is_deleted = 0 AND status = 'actif'"
          . " AND left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL"
          . " AND (employee_type = 'enseignant_titulaire' OR base_salary_usd > 0 OR contract_salary_lbp > 0)" . schoolScopeSql();
    $paramsC = [];
    if ($typeFilter) { $sqlC .= " AND employee_type = ?"; $paramsC[] = $typeFilter; }
    $stmtC = $db->prepare($sqlC);
    $stmtC->execute($paramsC);
    $employees = $stmtC->fetchAll();
    $count = 0;
    foreach ($employees as $e) {
        try {
            $calc = new PayrollCalculator($e['id'], $month, $year);
            $calc->calculateAndSave();
            $count++;
        } catch (Exception $ex) {}
    }
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "$count salaires calculés pour " . monthName($month) . " $year"];
    header('Location: ' . BASE_URL . 'pages/monthly_payroll.php?month=' . $month . '&year=' . $year . '&type=' . urlencode($typeFilter));
    exit;
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
<?php endif; ?>

<!-- Period selector -->
<div class="card">
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
            <div class="form-group mb-0">
                <label class="form-label">الفئة / Type</label>
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <option value="" <?= $typeFilter === '' ? 'selected' : '' ?>>الكل / Tous</option>
                    <option value="enseignant_titulaire" <?= $typeFilter === 'enseignant_titulaire' ? 'selected' : '' ?>>أساتذة ملاك / Titulaires</option>
                    <option value="enseignant_contractuel" <?= $typeFilter === 'enseignant_contractuel' ? 'selected' : '' ?>>متعاقدون / Contractuels</option>
                    <option value="employe" <?= $typeFilter === 'employe' ? 'selected' : '' ?>>موظفون / Employés</option>
                </select>
            </div>
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
    if ($typeFilter) { $sqlP .= " AND e.employee_type = ?"; $paramsP[] = $typeFilter; }
    $sqlP .= " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
    $stmtP = $db->prepare($sqlP);
    $stmtP->execute($paramsP);
    $empsP = $stmtP->fetchAll();
    $salStmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND month = ? AND year = ?");
    $typeLbl = $typeFilter ? employeeTypeLabel($typeFilter) : 'الكل / Tous';
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
        <a href="<?= BASE_URL ?>pages/monthly_payroll.php?month=<?= $month ?>&year=<?= $year ?>&type=<?= urlencode($typeFilter) ?>" class="btn btn-light">
            <i class="fas fa-arrow-left"></i> Retour à la liste / رجوع
        </a>
        <div>
            <span class="badge badge-info"><?= count($empsP) ?> — <?= e($typeLbl) ?></span>
            <button type="button" onclick="window.print()" class="btn btn-gold"><i class="fas fa-print"></i> طباعة الكل / Imprimer</button>
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
    <div class="d-flex justify-between align-center mb-3">
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

    <div class="card" id="ppExportArea">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-user"></i> <?= e($emp['first_name_fr'].' '.$emp['last_name_fr']) ?> — <?= monthName($month) ?> <?= $year ?></span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: 'قسيمة الراتب') ?></div>
            </h3>
            <div style="font-size:13px;color:var(--gray-600)"><?= e(currentSchoolName()) ?></div>
        </div>
        <div class="card-body">
            <?php
            // السنة الدراسية للشهر المختار (تشرين الأول → أيلول)
            $schoolYearLbl = $salary['school_year'] ?? ($month >= 10 ? $year.'-'.($year+1) : ($year-1).'-'.$year);
            $classesLbl = classLevelNames($emp['classes_taught'] ?? '');
            $isAdminEmp = ($emp['employee_type'] === 'employe'); // موظف إداري: لا درجة/صفوف/مواد/صندوق تعويضات
            ?>
            <table class="table" style="margin-bottom:16px">
                <tr><th colspan="4" style="background:#eef3fb;color:#000"><i class="fas fa-id-badge"></i> Informations de l'enseignant / معلومات الأستاذ</th></tr>
                <tr>
                    <td style="width:25%">Nom / اسم الأستاذ</td><td style="width:25%"><strong><?= e($emp['first_name_fr'].' '.$emp['last_name_fr']) ?></strong><br><small class="text-rtl"><?= e(trim($emp['first_name_ar'].' '.$emp['last_name_ar'])) ?></small></td>
                    <td style="width:25%">Année scolaire / السنة الدراسية</td><td style="width:25%"><strong><?= e($schoolYearLbl) ?></strong></td>
                </tr>
                <tr>
                    <td>Date d'embauche / تاريخ الدخول</td><td><strong><?= formatDate($emp['hire_date']) ?></strong></td>
                    <td>Date titularisation / تاريخ الملاك</td><td><strong><?= $isAdminEmp ? '—' : formatDate($emp['titularization_date']) ?></strong></td>
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
                    <td>Heures/semaine / عدد الساعات الأسبوعية</td><td><strong><?= rtrim(rtrim(number_format((float)$emp['hours_per_week'],1),'0'),'.') ?></strong></td>
                    <td>Jours/semaine / أيام الحضور الأسبوعية</td><td><strong><?= (int)$emp['days_per_week'] ?></strong></td>
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
                        <tr><td>Salaire de base / أساس الراتب</td><td class="text-end"><?= formatLBP($salary['base_salary_lbp']) ?></td></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Échelons / الدرجات (G<?= $salary['grade_at_month'] ?>)</td><td class="text-end"><?= formatLBP($salary['echelon_value_lbp']) ?></td></tr><?php endif; ?>
                        <?php if (!$isAdminEmp): ?><tr style="background:var(--gray-50)"><td><strong>Base + Échelon</strong></td><td class="text-end"><strong><?= formatLBP($salary['base_plus_echelon_lbp']) ?></strong></td></tr><?php endif; ?>
                        <tr><td>الأجر الإضافي / Supplément</td><td class="text-end"><?= money((int)$salary['extra_lbp'] + (int)$salary['prime_fixe_lbp'], rowRate($salary)) ?></td></tr>
                        <tr><td>مكافأة ومساعدة / Prime &amp; aide</td><td class="text-end"><?= money((int)$salary['aide_complementaire_lbp'], rowRate($salary)) ?></td></tr>
                        <tr style="background:#eef2ff"><td><strong>الراتب المركّب / Salaire composé</strong><br><small style="color:#64748b"><?= e(salaryCompLabel()) ?></small></td><td class="text-end"><strong><?= money(composedSalaryLbp($salary), rowRate($salary)) ?></strong></td></tr>
                    </table>

                    <table class="table">
                        <tr><th colspan="2" style="background:#ffe3e3;color:#000">RETENUES / المحسومات</th></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Caisse EOC (6%)</td><td class="text-end text-danger">-<?= formatLBP($salary['caisse_amount_lbp']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($salary['eoc_grade_lbp'])): ?><tr><td>درجة / نصف راتب (صندوق)</td><td class="text-end text-danger">-<?= formatLBP($salary['eoc_grade_lbp']) ?></td></tr><?php endif; ?>
                        <tr><td>CNSS (3%)</td><td class="text-end text-danger">-<?= formatLBP($salary['cnss_amount_lbp']) ?></td></tr>
                        <tr><td>Base imposable</td><td class="text-end"><?= formatLBP($salary['taxable_base_lbp']) ?></td></tr>
                        <tr><td>Impôt sur le revenu</td><td class="text-end text-danger">-<?= formatLBP($salary['income_tax_lbp']) ?></td></tr>
                        <tr style="background:#fef2f2"><td><strong>Total retenues</strong></td><td class="text-end"><strong class="text-danger">-<?= formatLBP($salary['total_retenues_lbp']) ?></strong></td></tr>
                    </table>
                </div>
                
                <table class="table" style="margin-top:20px">
                    <tr style="background:var(--gold-light)">
                        <td><strong>Salaire net</strong></td>
                        <td class="text-end"><strong><?= formatLBP($salary['net_salary_lbp']) ?></strong></td>
                        <td class="text-end text-muted"><?= formatUSD($salary['net_salary_usd']) ?></td>
                    </tr>
                    <tr><td>Allocations familiales (exonérées)</td><td class="text-end text-success">+<?= formatLBP($salary['family_allowance_lbp']) ?></td><td></td></tr>
                    <tr><td>Transport</td><td class="text-end text-success">+<?= money($salary['transport_lbp'], rowRate($salary)) ?></td><td></td></tr>
                    <tr style="background:#fff3cd;color:#000">
                        <td style="font-size:18px"><strong>💰 TOTAL DÛ</strong></td>
                        <td class="text-end" style="font-size:18px"><strong><?= formatLBP($salary['total_due_lbp']) ?></strong></td>
                        <td class="text-end" style="font-size:16px"><strong><?= formatUSD($salary['total_due_usd']) ?></strong></td>
                    </tr>
                </table>
                
                <details style="margin-top:20px">
                    <summary style="cursor:pointer;color:var(--gray-600);font-weight:600">
                        <i class="fas fa-eye"></i> Charges patronales (cachées du bulletin) / أعباء رب العمل (مخفية عن القسيمة)
                    </summary>
                    <table class="table" style="margin-top:10px">
                        <tr><td>CNSS École (8%)</td><td class="text-end"><?= formatLBP($salary['school_cnss_8_lbp']) ?></td></tr>
                        <?php if ($salary['school_eoc_6_lbp'] > 0): ?>
                        <tr><td>Caisse EOC École (6%)</td><td class="text-end"><?= formatLBP($salary['school_eoc_6_lbp']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($salary['school_family_comp_6_lbp'] > 0): ?>
                        <tr><td>Allocations familiales (6%)</td><td class="text-end"><?= formatLBP($salary['school_family_comp_6_lbp']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($salary['school_end_of_service_8_5_lbp'] > 0): ?>
                        <tr><td>Indemnité fin service (8.5%)</td><td class="text-end"><?= formatLBP($salary['school_end_of_service_8_5_lbp']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </details>
            <?php endif; ?>
        </div>
    </div>
<?php else:
    // List view
    $sql = "SELECT e.id, e.school_id, e.employee_code, e.first_name_fr, e.last_name_fr, e.employee_type, e.current_grade,
                   ms.total_due_lbp, ms.total_due_usd, ms.net_salary_lbp, ms.is_calculated
            FROM employees e
            LEFT JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.month = ? AND ms.year = ?
            WHERE e.is_deleted = 0 AND e.status = 'actif'" . schoolScopeSql('e.school_id');
    [$lyf, $lyp] = yearEmploymentFilter($msSchoolYear, 'e.');
    $sql .= $lyf;
    $listParams = array_merge([$month, $year], $lyp);
    if ($typeFilter) { $sql .= " AND e.employee_type = ?"; $listParams[] = $typeFilter; }
    $sql .= " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
    $stmt = $db->prepare($sql);
    $stmt->execute($listParams);
    $list = $stmt->fetchAll();
    
    $totalDue = 0; $calculatedCount = 0;
    foreach ($list as $r) {
        $totalDue += (float)($r['total_due_lbp'] ?? 0);
        if ($r['is_calculated']) $calculatedCount++;
    }
?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-users"></i></div>
            <div><div class="stat-label">Total employés actifs / إجمالي الموظفين الفاعلين</div><div class="stat-value"><?= count($list) ?></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="fas fa-check"></i></div>
            <div><div class="stat-label">Calculés / المحتسَبون</div><div class="stat-value"><?= $calculatedCount ?></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
            <div><div class="stat-label">En attente / قيد الانتظار</div><div class="stat-value"><?= count($list) - $calculatedCount ?></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold"><i class="fas fa-money-bill"></i></div>
            <div><div class="stat-label">Total dû / الإجمالي المتوجب</div><div class="stat-value" style="font-size:18px"><?= formatLBP($totalDue) ?></div></div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-money-check-alt"></i> Paie de <?= monthName($month) ?> <?= $year ?> — <?= e(currentSchoolName()) ?></span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">رواتب الشهر</div>
            </h3>
            <div class="d-flex gap-2 no-print">
                <button type="button" onclick="window.print()" class="btn btn-light"><i class="fas fa-print"></i> Imprimer le tableau / طباعة الجدول</button>
                <a href="?action=print_all&month=<?= $month ?>&year=<?= $year ?>&type=<?= urlencode($typeFilter) ?>" class="btn btn-primary" title="Afficher/imprimer le bulletin de chaque employé / عرض/طباعة قسيمة كل موظف">
                    <i class="fas fa-file-invoice"></i> Imprimer les bulletins / طباعة القسائم
                </a>
                <?php if (!isAllSchools()): ?>
                <a href="?action=calc_all&month=<?= $month ?>&year=<?= $year ?>&type=<?= urlencode($typeFilter) ?>" class="btn btn-gold" data-confirm="احتساب رواتب كل الموظفين المعروضين لهذا الشهر؟">
                    <i class="fas fa-bolt"></i> Calculer tout / احتساب الكل
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code / الرمز</th>
                            <?php if (isAllSchools()): ?><th>École / المدرسة</th><?php endif; ?>
                            <th>Nom / الاسم</th>
                            <th>Type / الفئة</th>
                            <th>Échelon / الدرجة</th>
                            <th>Net salaire / الصافي</th>
                            <th>Total dû L.L / الإجمالي ل.ل</th>
                            <th>Total dû $ / الإجمالي بالدولار</th>
                            <th>Statut / الحالة</th>
                            <th class="no-print">Action / إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $mpT = ['net'=>0,'due'=>0,'due_usd'=>0.0,'n'=>0];
                        foreach ($list as $r):
                            if ($r['is_calculated']) { $mpT['net'] += (int)$r['net_salary_lbp']; $mpT['due'] += (int)$r['total_due_lbp']; $mpT['due_usd'] += (float)$r['total_due_usd']; $mpT['n']++; }
                        ?>
                            <tr>
                                <td><strong><?= e($r['employee_code']) ?></strong></td>
                                <?php if (isAllSchools()): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                                <td><?= e($r['first_name_fr'].' '.$r['last_name_fr']) ?></td>
                                <td><small><?= employeeTypeLabel($r['employee_type']) ?></small></td>
                                <td><?= e(gradeDisplay($r)) ?></td>
                                <td><?= $r['is_calculated'] ? formatLBP($r['net_salary_lbp']) : '—' ?></td>
                                <td><strong><?= $r['is_calculated'] ? formatLBP($r['total_due_lbp']) : '—' ?></strong></td>
                                <td><?= $r['is_calculated'] ? formatUSD($r['total_due_usd']) : '—' ?></td>
                                <td>
                                    <?php if ($r['is_calculated']): ?>
                                        <span class="badge badge-success">✓ Calculé / محتسَب</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">En attente / قيد الانتظار</span>
                                    <?php endif; ?>
                                </td>
                                <td class="no-print">
                                    <a href="?employee_id=<?= $r['id'] ?>&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (!isAllSchools()): ?>
                                    <a href="?action=calc&employee_id=<?= $r['id'] ?>&month=<?= $month ?>&year=<?= $year ?>" class="btn btn-sm btn-gold" title="Calculer / احتساب">
                                        <i class="fas fa-calculator"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($mpT['n'] > 0): ?><tfoot><tr class="total-row" style="font-weight:700;background:var(--gold-light,#fdf6e3)">
                        <td colspan="<?= isAllSchools() ? 5 : 4 ?>" style="text-align:right">المجموع (المحتسَبون: <?= $mpT['n'] ?>) / Total</td>
                        <td><?= formatLBP($mpT['net']) ?></td>
                        <td><strong><?= formatLBP($mpT['due']) ?></strong></td>
                        <td><?= formatUSD($mpT['due_usd']) ?></td>
                        <td></td><td class="no-print"></td>
                    </tr></tfoot><?php endif; ?>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
