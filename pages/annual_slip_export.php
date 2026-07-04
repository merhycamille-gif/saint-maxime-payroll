<?php
/**
 * تصدير «كشف الراتب السنوي» إلى PDF/Excel/Word رسمي عبر ReportTable (openpyxl + LibreOffice).
 * يطلع طبق الأصل على A4 أفقي يتأقلم تلقائياً بلا قصّ ولا CSS — بديل طباعة المتصفّح.
 *   ?employee_id=&school_year=&format=pdf|xlsx|docx   (موظف واحد)
 *   ?all=1&type=&school_year=&format=...               (كل المدرسة، كتلة لكل أستاذ)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/annual_slip_data.php';
require_once __DIR__ . '/../includes/report_export.php';
requireLogin();

$db = getDB();
$format = in_array($_GET['format'] ?? 'pdf', ['pdf', 'xlsx', 'docx'], true) ? $_GET['format'] : 'pdf';
$schoolYear = $_GET['school_year'] ?? activeSchoolYear();
if ($schoolYear === 'all') $schoolYear = currentSchoolYear();
$employeeId = (int)($_GET['employee_id'] ?? 0);
$all = !empty($_GET['all']);

$allowedTypes = ['enseignant_titulaire', 'enseignant_contractuel', 'employe'];
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = '';

// رؤوس الأعمدة (LBP الرسمي) — 17 عموداً تتأقلم على A4 أفقي
$head = [
    'الشهر', 'أساس الراتب', 'قيمة الدرجة', 'الراتب بعد التدرّج', 'الأجر الإضافي', 'مكافأة ومساعدة',
    'الإجمالي', 'الصندوق', 'درجة/نصف راتب', 'الضمان', 'الضريبة', 'مجموع الحسومات', 'الصافي',
    'عائلي', 'النقل', 'المستحق', 'التوقيع',
];
// عرض الأعمدة: الشهر أوسع شوي، التوقيع للإمضاء، أعمدة المبالغ واسعة (fitToWidth يصغّر تلقائياً على A4)
$widths = [12, 14, 12, 15, 14, 13, 15, 12, 12, 13, 12, 14, 15, 12, 14, 15, 12];

// أعمدة الأستاذ التي تُحذف للموظف الإداري (قيمة الدرجة، الراتب بعد التدرّج، الصندوق، درجة/نصف راتب) — مؤشّرات 0-based.
const ANNUAL_TEACHER_COLS = [2, 3, 7, 8];
function annualDropTeacherCols(array $row) {
    foreach (ANNUAL_TEACHER_COLS as $i) unset($row[$i]);
    return array_values($row);
}

/**
 * يضيف صفوف أستاذ (هوية اختيارية + أشهر + مجموع) إلى التقرير.
 * $withIdentity: سطر هوية مدموج قبل الأشهر — للطباعة الجماعية فقط (للموظف الواحد الهوية في period).
 * $dropCols: يحذف أعمدة الدرجة/الصندوق (للموظف الإداري في تصدير الموظف الواحد فقط).
 */
function addEmployeeBlock(ReportTable $rep, array $slip, $withIdentity = true, $dropCols = false) {
    $m = $slip['meta'];
    $emit = function (array $row, $isTotal = false) use ($rep, $dropCols) {
        if ($dropCols) $row = annualDropTeacherCols($row);
        $isTotal ? $rep->totalRow($row) : $rep->row($row);
    };
    if ($withIdentity) {
        $rep->sectionRow($m['name'] . '  —  الشهادة: ' . $m['diploma'] . ' · الفئة: ' . $m['type']
            . ' · الدرجة: ' . $m['grade'] . ' · ر.الضمان: ' . $m['cnss']
            . ' · ر.الصندوق: ' . $m['caisse_no'] . ' · ر.المالية: ' . $m['finance_no']);
    }

    foreach ($slip['rows'] as $r) {
        if (empty($r['s'])) { // شهر بلا احتساب
            $emit([$r['label'], '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
            continue;
        }
        $emit([
            $r['label'], $r['base_shown'], ($r['grade_inc'] > 0 ? $r['grade_inc'] : ''), $r['cur_sal'],
            ($r['extra_wage'] > 0 ? $r['extra_wage'] : ''), ($r['aide'] > 0 ? $r['aide'] : ''),
            $r['brut'], ($r['caisse'] > 0 ? $r['caisse'] : ''), ($r['eoc_grade'] > 0 ? $r['eoc_grade'] : ''),
            ($r['cnss'] > 0 ? $r['cnss'] : ''), ($r['income_tax'] > 0 ? $r['income_tax'] : ''),
            $r['total_retenues'], $r['net'], ($r['family'] > 0 ? $r['family'] : ''),
            ($r['transport'] > 0 ? $r['transport'] : ''), $r['total_due'], '',
        ]);
    }
    $t = $slip['tot'];
    $emit([
        'المجموع', $t['base_shown'], $t['grade_inc'], $t['base_plus_echelon'], $t['extra_wage'], $t['aide'],
        $t['brut'], $t['caisse'], $t['eoc_grade'], $t['cnss'], $t['income_tax'], $t['total_retenues'], $t['net'],
        $t['family'], $t['transport'], $t['total_due'], '',
    ], true);
}

if ($all) {
    // كل المدرسة (حسب الفلتر) — كتلة لكل أستاذ
    [$yf, $yp] = yearEmploymentFilter($schoolYear, 'e.');
    $sql = "SELECT e.* FROM employees e WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . $yf;
    $params = $yp;
    if ($typeFilter) { $sql .= " AND e.employee_type = ?"; $params[] = $typeFilter; }
    $sql .= " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $emps = $stmt->fetchAll();
    if (!$emps) { http_response_code(404); die('لا يوجد موظفون مطابقون.'); }

    $typeLbl = $typeFilter ? employeeTypeLabel($typeFilter) : 'الكل';
    $rep = new ReportTable('كشوف الرواتب السنوية ' . $schoolYear . ' — ' . $typeLbl, true);
    $rep->schoolHeader(currentSchool());
    $rep->period('السنة الدراسية ' . $schoolYear . ' — ' . $typeLbl . ' (' . count($emps) . ' موظف)');
    $rep->head($head);
    $rep->widths($widths);
    foreach ($emps as $emp) {
        addEmployeeBlock($rep, computeAnnualSlip($db, $emp, $schoolYear));
    }
    $rep->export($format);
    exit;
}

// موظف واحد
if ($employeeId <= 0) { http_response_code(400); die('حدّد موظفاً.'); }
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
$stmt->execute([$employeeId]);
$emp = $stmt->fetch();
if (!$emp) { http_response_code(404); die('الموظف غير موجود في هذه المدرسة.'); }

$slip = computeAnnualSlip($db, $emp, $schoolYear);
$m = $slip['meta'];
$rep = new ReportTable('كشف الراتب السنوي ' . $schoolYear, true);
$rep->schoolHeader($slip['school']);
$isAdminEmp = ($emp['employee_type'] === 'employe');
$rep->period($m['name'] . '  —  ' . ($isAdminEmp ? 'الوظيفة' : 'الشهادة') . ': ' . $m['diploma'] . ' · الفئة: ' . $m['type']
    . ($isAdminEmp ? '' : ' · الدرجة: ' . $m['grade'])
    . ' · ر.الضمان: ' . $m['cnss'] . ' · ر.الصندوق: ' . $m['caisse_no'] . ' · ر.المالية: ' . $m['finance_no']);
// الموظف الإداري: احذف أعمدة الدرجة/التدرّج/الصندوق من الرأس والعرض والصفوف (لا سلسلة رتب له).
$rep->head($isAdminEmp ? annualDropTeacherCols($head) : $head);
$rep->widths($isAdminEmp ? annualDropTeacherCols($widths) : $widths);
addEmployeeBlock($rep, $slip, false, $isAdminEmp); // الهوية معروضة في period — لا تكرّرها
$rep->export($format);
