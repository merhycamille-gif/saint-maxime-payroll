<?php
/**
 * تصدير تقارير reports.php إلى Excel/Word حقيقي (.xlsx/.docx) — رسمي ومنظّم على A4.
 * نفس استعلامات وأعمدة reports.php، عبر مولّد ReportTable (بلا مكتبات خارجية).
 *   ?report=<type>&format=xlsx|docx&month=&year=&school_year=&emp_type=&cols[]=
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/report_export.php';
requireLogin();

$db = getDB();
$report = $_GET['report'] ?? '';
$format = in_array($_GET['format'] ?? 'xlsx', ['docx', 'pdf'], true) ? $_GET['format'] : 'xlsx';
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$schoolYear = $_GET['school_year'] ?? activeSchoolYear();
if ($schoolYear === 'all') $schoolYear = currentSchoolYear();

$schoolSql = reportSchoolSql('ms.school_id');
$schoolSqlEmp = reportSchoolSql('e.school_id');
$multi = reportIsMultiSchool();
$school = currentSchool(); // null في وضع عدة مدارس
$periodSchoolYear = ($month >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
[$empYearFilter, $empYearParams] = yearEmploymentFilter($periodSchoolYear, 'e.');
[$annualEmpFilter, $annualEmpParams] = yearEmploymentFilter($schoolYear, 'e.');

$nm = function ($r) { return trim(($r['first_name_ar'] ?? '') . ' ' . ($r['last_name_ar'] ?? '')) ?: trim(($r['first_name_fr'] ?? '') . ' ' . ($r['last_name_fr'] ?? '')); };
$catTitle = fn($t) => empCategoryTitle($t);

// عمود المدرسة (في وضع عدة مدارس)
$schCol = $multi;

$rep = null;

if ($report === 'monthly_summary') {
    $st = $db->prepare("SELECT e.first_name_fr,e.last_name_fr,e.first_name_ar,e.last_name_ar,e.employee_type,e.school_id,ms.*
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE ms.year=? AND ms.month=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0)" . $schoolSql . $empYearFilter . "
        ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)");
    $st->execute(array_merge([$year, $month], $empYearParams));
    $data = $st->fetchAll();

    $rep = new ReportTable('كشف الرواتب الشهري — ' . monthName($month, 'ar') . ' ' . $year, true);
    $rep->schoolHeader($school);
    $head = ['#']; if ($schCol) $head[] = 'المدرسة';
    $head = array_merge($head, ['الاسم', 'الفئة', 'الدرجة', 'أساس الراتب', 'قيمة الدرجة', 'الراتب بعد التدرّج', 'الأجر الإضافي', 'مكافأة ومساعدة', 'الضمان ٣٪', 'الصندوق ٦٪', 'الضريبة', 'الصافي', 'تعويض عائلي', 'تعويض النقل', 'الإجمالي المتوجب']);
    $rep->head($head);
    $rep->widths($schCol ? [5, 18, 26, 14, 8, 16, 14, 18, 14, 14, 14, 14, 12, 16, 14, 14, 18] : [5, 28, 14, 8, 16, 14, 18, 14, 14, 14, 14, 12, 16, 14, 14, 18]);

    $z = ['base' => 0, 'ech' => 0, 'bpe' => 0, 'extra' => 0, 'aide' => 0, 'cnss' => 0, 'caisse' => 0, 'tax' => 0, 'net' => 0, 'fam' => 0, 'tr' => 0, 'tot' => 0];
    $G = $z; $cur = null; $sub = $z; $subN = 0; $rn = 0;
    $emit = function ($label, $a, $n) use ($rep, $schCol) {
        $cells = ['', 'مجموع ' . $label . ' — العدد: ' . $n];
        if ($schCol) array_splice($cells, 1, 0, '');
        // محاذاة الأعمدة: بعد (#,[school],name,type,grade) تبدأ الأرقام
        $pad = $schCol ? 5 : 4; $row = array_fill(0, $pad, ''); $row[$pad - 1] = 'مجموع ' . $label . ' — العدد: ' . $n;
        $row = array_merge($row, [$a['base'], $a['ech'], $a['bpe'], $a['extra'], $a['aide'], $a['cnss'], $a['caisse'], $a['tax'], $a['net'], $a['fam'], $a['tr'], $a['tot']]);
        $rep->totalRow($row);
    };
    foreach ($data as $r) {
        if ($cur !== null && $r['employee_type'] !== $cur) { $emit($catTitle($cur), $sub, $subN); $sub = $z; $subN = 0; }
        if ($r['employee_type'] !== $cur) { $cur = $r['employee_type']; $rep->sectionRow($catTitle($cur)); }
        $tr = (int)$r['transport_lbp'];
        $v = ['base' => (int)$r['base_salary_lbp'], 'ech' => (int)$r['echelon_value_lbp'], 'bpe' => (int)$r['base_plus_echelon_lbp'], 'extra' => extraWageLbp($r), 'aide' => aideCompLbp($r), 'cnss' => (int)$r['cnss_amount_lbp'], 'caisse' => (int)$r['caisse_amount_lbp'], 'tax' => (int)$r['income_tax_lbp'], 'net' => (int)$r['net_salary_lbp'], 'fam' => (int)$r['family_allowance_lbp'], 'tr' => $tr, 'tot' => (int)$r['total_due_lbp']];
        foreach ($v as $k => $val) { $sub[$k] += $val; $G[$k] += $val; }
        $subN++; $rn++;
        $row = [$rn]; if ($schCol) $row[] = schoolNameById($r['school_id']);
        $row = array_merge($row, [$nm($r), employeeTypeLabel($r['employee_type']), gradeDisplay($r['employee_type'], $r['grade_at_month']), $v['base'], $v['ech'], $v['bpe'], $v['extra'], $v['aide'], $v['cnss'], $v['caisse'], $v['tax'], $v['net'], $v['fam'], $v['tr'], $v['tot']]);
        $rep->row($row);
    }
    if ($data) { $emit($catTitle($cur), $sub, $subN); $emit('الإجمالي العام', $G, $rn); }

} elseif ($report === 'cnss_summary') {
    $st = $db->prepare("SELECT e.employee_type,e.first_name_fr,e.last_name_fr,e.first_name_ar,e.last_name_ar,e.nssf_number,e.birth_date,e.school_id,ms.base_salary_lbp,ms.cnss_amount_lbp,ms.school_cnss_8_lbp,ms.extra_lbp,ms.prime_fixe_lbp,ms.aide_complementaire_lbp
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE ms.year=? AND ms.month=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0) AND e.cnss_subject=1" . $schoolSql . $empYearFilter . "
        ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)");
    $st->execute(array_merge([$year, $month], $empYearParams));
    $data = $st->fetchAll();
    $rep = new ReportTable('كشف اشتراكات الضمان — ' . monthName($month, 'ar') . ' ' . $year, true);
    $rep->schoolHeader($school);
    $head = ['#']; if ($schCol) $head[] = 'المدرسة';
    $head = array_merge($head, ['رقم الضمان', 'الاسم', 'أساس الراتب', 'الأجر الإضافي', 'مكافأة ومساعدة', 'وعاء الضمان', 'الأجير ٣٪', 'المدرسة ٨٪']);
    $rep->head($head);
    $z = ['base' => 0, 'extra' => 0, 'aide' => 0, 'cnss' => 0, 'school' => 0]; $G = $z; $cur = null; $sub = $z; $subN = 0; $rn = 0;
    $pad = $schCol ? 4 : 3;
    $emit = function ($label, $a, $n) use ($rep, $pad) {
        $row = array_fill(0, $pad, ''); $row[$pad - 1] = 'مجموع ' . $label . ' — العدد: ' . $n;
        $row = array_merge($row, [$a['base'], $a['extra'], $a['aide'], '', $a['cnss'], $a['school']]);
        $rep->totalRow($row);
    };
    foreach ($data as $r) {
        if ($cur !== null && $r['employee_type'] !== $cur) { $emit($catTitle($cur), $sub, $subN); $sub = $z; $subN = 0; }
        if ($r['employee_type'] !== $cur) { $cur = $r['employee_type']; $rep->sectionRow($catTitle($cur)); }
        $base = (int)$r['base_salary_lbp']; $ex = extraWageLbp($r); $ai = aideCompLbp($r); $cnss = (int)$r['cnss_amount_lbp']; $sch = (int)$r['school_cnss_8_lbp'];
        $sub['base'] += $base; $sub['extra'] += $ex; $sub['aide'] += $ai; $sub['cnss'] += $cnss; $sub['school'] += $sch;
        foreach (['base' => $base, 'extra' => $ex, 'aide' => $ai, 'cnss' => $cnss, 'school' => $sch] as $k => $val) $G[$k] += $val;
        $subN++; $rn++;
        $row = [$rn]; if ($schCol) $row[] = schoolNameById($r['school_id']);
        $row = array_merge($row, [cnssWithBirthYear($r['nssf_number'], $r['birth_date']), $nm($r), $base, $ex, $ai, ($cnss ? (int)round($cnss / 0.03) : 0), $cnss, $sch]);
        $rep->row($row);
    }
    if ($data) { $emit($catTitle($cur), $sub, $subN); $emit('المجموع العام', $G, $rn); }

} elseif ($report === 'tax_summary') {
    $st = $db->prepare("SELECT e.employee_type,e.first_name_fr,e.last_name_fr,e.first_name_ar,e.last_name_ar,e.finance_ministry_number,e.school_id,ms.base_salary_lbp,ms.income_tax_lbp,ms.taxable_base_lbp,ms.extra_lbp,ms.prime_fixe_lbp,ms.aide_complementaire_lbp
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE ms.year=? AND ms.month=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0) AND e.tax_subject=1" . $schoolSql . $empYearFilter . "
        ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)");
    $st->execute(array_merge([$year, $month], $empYearParams));
    $data = $st->fetchAll();
    $rep = new ReportTable('كشف ضريبة الدخل — ' . monthName($month, 'ar') . ' ' . $year, true);
    $rep->schoolHeader($school);
    $head = ['#']; if ($schCol) $head[] = 'المدرسة';
    $head = array_merge($head, ['رقم المالية', 'الاسم', 'أساس الراتب', 'الأجر الإضافي', 'مكافأة ومساعدة', 'الراتب الخاضع', 'ضريبة الدخل']);
    $rep->head($head);
    $z = ['base' => 0, 'extra' => 0, 'aide' => 0, 'txb' => 0, 'tax' => 0]; $G = $z; $cur = null; $sub = $z; $subN = 0; $rn = 0;
    $pad = $schCol ? 4 : 3;
    $emit = function ($label, $a, $n) use ($rep, $pad) {
        $row = array_fill(0, $pad, ''); $row[$pad - 1] = 'مجموع ' . $label . ' — العدد: ' . $n;
        $row = array_merge($row, [$a['base'], $a['extra'], $a['aide'], $a['txb'], $a['tax']]);
        $rep->totalRow($row);
    };
    foreach ($data as $r) {
        if ($cur !== null && $r['employee_type'] !== $cur) { $emit($catTitle($cur), $sub, $subN); $sub = $z; $subN = 0; }
        if ($r['employee_type'] !== $cur) { $cur = $r['employee_type']; $rep->sectionRow($catTitle($cur)); }
        $base = (int)$r['base_salary_lbp']; $ex = extraWageLbp($r); $ai = aideCompLbp($r); $txb = (int)$r['taxable_base_lbp']; $tax = (int)$r['income_tax_lbp'];
        $sub['base'] += $base; $sub['extra'] += $ex; $sub['aide'] += $ai; $sub['txb'] += $txb; $sub['tax'] += $tax;
        foreach (['base' => $base, 'extra' => $ex, 'aide' => $ai, 'txb' => $txb, 'tax' => $tax] as $k => $val) $G[$k] += $val;
        $subN++; $rn++;
        $row = [$rn]; if ($schCol) $row[] = schoolNameById($r['school_id']);
        $row = array_merge($row, [$r['finance_ministry_number'], $nm($r), $base, $ex, $ai, $txb, $tax]);
        $rep->row($row);
    }
    if ($data) { $emit($catTitle($cur), $sub, $subN); $emit('المجموع العام', $G, $rn); }

} elseif ($report === 'eoc_summary') {
    $st = $db->prepare("SELECT e.first_name_fr,e.last_name_fr,e.first_name_ar,e.last_name_ar,e.caisse_number,e.school_id,ms.base_salary_lbp,ms.caisse_amount_lbp,ms.eoc_grade_lbp,ms.school_eoc_6_lbp,ms.extra_lbp,ms.prime_fixe_lbp,ms.aide_complementaire_lbp
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE ms.year=? AND ms.month=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0) AND e.employee_type='enseignant_titulaire'" . $schoolSql . $empYearFilter . "
        ORDER BY e.school_id, COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)");
    $st->execute(array_merge([$year, $month], $empYearParams));
    $data = $st->fetchAll();
    $rep = new ReportTable('كشف صندوق التعويضات — ' . monthName($month, 'ar') . ' ' . $year, true);
    $rep->schoolHeader($school);
    $head = ['#']; if ($schCol) $head[] = 'المدرسة';
    $head = array_merge($head, ['رقم الصندوق', 'الاسم', 'أساس الراتب', 'الأجر الإضافي', 'مكافأة ومساعدة', 'الأجير ٦٪', 'درجة/نصف راتب', 'المدرسة ٦٪']);
    $rep->head($head);
    $T = ['base' => 0, 'extra' => 0, 'aide' => 0, 'caisse' => 0, 'grade' => 0, 'school' => 0]; $rn = 0;
    foreach ($data as $r) {
        $base = (int)$r['base_salary_lbp']; $ex = extraWageLbp($r); $ai = aideCompLbp($r); $ca = (int)$r['caisse_amount_lbp']; $gr = (int)$r['eoc_grade_lbp']; $sc = (int)$r['school_eoc_6_lbp'];
        $T['base'] += $base; $T['extra'] += $ex; $T['aide'] += $ai; $T['caisse'] += $ca; $T['grade'] += $gr; $T['school'] += $sc; $rn++;
        $row = [$rn]; if ($schCol) $row[] = schoolNameById($r['school_id']);
        $row = array_merge($row, [$r['caisse_number'], $nm($r), $base, $ex, $ai, $ca, ($gr > 0 ? $gr : '—'), $sc]);
        $rep->row($row);
    }
    if ($data) {
        $pad = $schCol ? 4 : 3; $row = array_fill(0, $pad, ''); $row[$pad - 1] = 'المجاميع — العدد: ' . $rn;
        $rep->totalRow(array_merge($row, [$T['base'], $T['extra'], $T['aide'], $T['caisse'], $T['grade'], $T['school']]));
    }

} elseif ($report === 'employee_list') {
    $empType = $_GET['emp_type'] ?? '';
    $allowed = ['enseignant_titulaire', 'enseignant_contractuel', 'employe'];
    $typeSql = in_array($empType, $allowed, true) ? " AND e.employee_type=" . $db->quote($empType) : "";
    [$yf, $yp] = yearEmploymentFilter(activeSchoolYear(), 'e.');
    $st = $db->prepare("SELECT e.* FROM employees e WHERE e.is_deleted=0" . $schoolSqlEmp . $typeSql . $yf . " ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)");
    $st->execute($yp);
    $data = $st->fetchAll();
    $scaleMap = [];
    foreach ($db->query("SELECT grade,new_salary_2017 FROM salary_scale_2017 WHERE version_id=1") as $sc) $scaleMap[(int)$sc['grade']] = (float)$sc['new_salary_2017'];
    // أساس الموظف الإداري الفعلي من آخر راتب محسوب (لا سلسلة رتب — قانون العمل)
    $baseMap = [];
    foreach ($db->query("SELECT ms.employee_id, ms.base_plus_echelon_lbp FROM monthly_salaries ms
                         JOIN (SELECT employee_id, MAX(year*12+month) ym FROM monthly_salaries WHERE is_calculated=1 GROUP BY employee_id) lt
                           ON lt.employee_id=ms.employee_id AND (ms.year*12+ms.month)=lt.ym") as $b) {
        $baseMap[(int)$b['employee_id']] = (float)$b['base_plus_echelon_lbp'];
    }
    $cols = [
        'code' => ['Code', fn($r) => $r['employee_code']],
        'name' => ['الاسم', fn($r) => trim($r['first_name_fr'] . ' ' . $r['last_name_fr']) ?: trim($r['first_name_ar'] . ' ' . $r['last_name_ar'])],
        'type' => ['الفئة', fn($r) => employeeTypeLabel($r['employee_type'])],
        'diploma' => ['الشهادة', fn($r) => $r['employee_type'] === 'employe' ? jobTitleLabel($r['job_title'] ?? '') : diplomaLabel($r['diploma'])],
        'grade' => ['الدرجة', fn($r) => gradeDisplay($r)],
        'salary' => ['الراتب', fn($r) => $r['employee_type'] === 'employe'
                        ? (int)($baseMap[(int)$r['id']] ?? 0)
                        : (int)($scaleMap[(int)round($r['current_grade'])] ?? 0)],
        'nssf' => ['رقم الضمان', fn($r) => $r['nssf_number']],
        'mof' => ['رقم المالية', fn($r) => $r['finance_ministry_number']],
        'caisse' => ['رقم الصندوق', fn($r) => $r['caisse_number']],
        'phone' => ['هاتف', fn($r) => implode(' / ', array_filter([trim($r['phone1']), trim($r['phone2'])]))],
        'hire' => ['الدخول', fn($r) => formatDate($r['hire_date'])],
        'titul' => ['دخول الملاك', fn($r) => formatDate($r['titularization_date'])],
        'status' => ['الحالة', fn($r) => employeeStatusLabel($r['status'])['label']],
    ];
    $defaultCols = ['code', 'name', 'type', 'grade', 'status'];
    $sel = $_GET['cols'] ?? $defaultCols;
    if (!is_array($sel)) $sel = $defaultCols;
    $sel = array_values(array_filter($sel, fn($c) => isset($cols[$c])));
    if (!$sel) $sel = $defaultCols;
    $rep = new ReportTable('لائحة الموظفين', false);
    $rep->schoolHeader($school);
    $head = ['#']; if ($schCol) $head[] = 'المدرسة';
    foreach ($sel as $c) $head[] = $cols[$c][0];
    $rep->head($head);
    $cur = null; $rn = 0;
    foreach ($data as $r) {
        if ($r['employee_type'] !== $cur) { $cur = $r['employee_type']; $rep->sectionRow($catTitle($cur)); }
        $rn++; $row = [$rn]; if ($schCol) $row[] = schoolNameById($r['school_id']);
        foreach ($sel as $c) $row[] = ($cols[$c][1])($r);
        $rep->row($row);
    }
    if ($data) { $pad = ($schCol ? 2 : 1); $row = array_fill(0, $pad, ''); $row[$pad - 1] = 'العدد الإجمالي: ' . count($data); $rep->totalRow($row); }

} elseif ($report === 'annual_totals') {
    $st = $db->prepare("SELECT COUNT(*) cnt, SUM(ms.net_salary_lbp) net, SUM(ms.total_due_lbp) total, SUM(ms.cnss_amount_lbp) cnss, SUM(ms.income_tax_lbp) tax, SUM(ms.caisse_amount_lbp) caisse, SUM(ms.school_cnss_8_lbp) scnss, SUM(ms.school_eoc_6_lbp) seoc, SUM(ms.base_salary_lbp) base_sal, SUM(ms.base_plus_echelon_lbp) bpe, SUM(ms.extra_lbp+ms.prime_fixe_lbp) extra_wage, SUM(ms.aide_complementaire_lbp) aide, SUM(ms.transport_lbp) transport
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=0" . $annualEmpFilter . " AND ms.school_year=? AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0)" . $schoolSql);
    $st->execute(array_merge($annualEmpParams, [$schoolYear]));
    $t = $st->fetch();
    $rep = new ReportTable('المجاميع السنوية — ' . $schoolYear, false);
    $rep->schoolHeader($school);
    $rep->head(['البيان', 'القيمة (ل.ل)']);
    $rep->widths([40, 28]);
    $rep->row(['عدد الكشوف المحسوبة', (int)$t['cnt']]);
    $rep->totalRow(['إجمالي المدفوع (الصافي)', (int)$t['net']]);
    $rep->totalRow(['الإجمالي المتوجب (صافي + تعويضات)', (int)$t['total']]);
    $rep->row(['أساس الراتب', (int)$t['base_sal']]);
    $rep->row(['الراتب بعد التدرّج', (int)$t['bpe']]);
    $rep->row(['الأجر الإضافي', (int)$t['extra_wage']]);
    $rep->row(['مكافأة ومساعدة', (int)$t['aide']]);
    $rep->row(['تعويض النقل', (int)$t['transport']]);
    $rep->row(['الضمان (حصة الأجير ٣٪)', (int)$t['cnss']]);
    $rep->row(['الضمان (حصة المدرسة ٨٪)', (int)$t['scnss']]);
    $rep->row(['صندوق التعويضات (الأجير)', (int)$t['caisse']]);
    $rep->row(['صندوق التعويضات (المدرسة ٦٪)', (int)$t['seoc']]);
    $rep->row(['ضريبة الدخل', (int)$t['tax']]);

} else {
    http_response_code(400);
    die('نوع تقرير غير معروف للتصدير: ' . htmlspecialchars($report));
}

$rep->export($format); // Python احترافي (openpyxl/python-docx) مع fallback لليدوي
