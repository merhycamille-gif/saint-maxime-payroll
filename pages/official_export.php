<?php
/**
 * تصدير النماذج الحكومية الرسمية بتعبئة قالب المستخدم الأصلي (Excel) تلقائياً → PDF/Excel طبق الأصل.
 * نقطة مستقلّة بلا header (حتى لا تُرسَل الترويسة قبل بثّ الملف).
 *   ?form=cnss_contrib_monthly&month=&year=&format=pdf|xlsx
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_export.php';
requireLogin();

$db = getDB();
$form = $_GET['form'] ?? '';
$format = ($_GET['format'] ?? 'pdf') === 'xlsx' ? 'xlsx' : 'pdf';
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$school = currentSchool();

if ($form === 'cnss_contrib_monthly') {
    $periodSY = ($month >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
    [$yf, $yp] = yearEmploymentFilter($periodSY, 'e.');
    $params = array_merge($yp, [$year, $month]);
    $q1 = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN (ms.cnss_amount_lbp+ms.school_cnss_8_lbp)>0 THEN ms.employee_id END) n,
            COALESCE(SUM(ms.cnss_amount_lbp + ms.school_cnss_8_lbp),0) c
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.cnss_subject=1 AND e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month=? AND " . schoolScopeWhere('ms.school_id'));
    $q1->execute($params); $a = $q1->fetch();
    $q2 = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN ms.school_end_of_service_8_5_lbp>0 THEN ms.employee_id END) n,
            COALESCE(SUM(ms.school_end_of_service_8_5_lbp),0) c
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.employee_type='employe' AND e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month=? AND " . schoolScopeWhere('ms.school_id'));
    $q2->execute($params); $t = $q2->fetch();
    $q2b = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN ms.school_family_comp_6_lbp>0 THEN ms.employee_id END) n,
            COALESCE(SUM(ms.school_family_comp_6_lbp),0) c
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.employee_type='employe' AND e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month=? AND " . schoolScopeWhere('ms.school_id'));
    $q2b->execute($params); $fam = $q2b->fetch();
    $q3 = $db->prepare("SELECT COALESCE(SUM(ms.family_allowance_lbp),0) f FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month=? AND " . schoolScopeWhere('ms.school_id'));
    $q3->execute($params); $fpaid = (int)$q3->fetchColumn();

    $c1 = (int)$a['c']; $n1 = (int)$a['n']; $w1 = $c1 ? (int)round($c1 / 0.11) : 0;
    $c2 = (int)$t['c']; $n2 = (int)$t['n']; $w2 = $c2 ? (int)round($c2 / 0.085) : 0;
    $c3 = (int)$fam['c']; $n3 = (int)$fam['n']; $w3 = $c3 ? (int)round($c3 / 0.06) : 0;

    $ok = officialTemplateExport(__DIR__ . '/../assets/templates/cnss_monthly.xlsx', [
        'D8'  => $school['name_ar'] ?? '',
        'G14' => $school['nssf_employer_number'] ?? '',
        'AC1' => monthName($month, 'ar'), 'AI1' => $year,
        'C21' => $n1, 'D21' => $w1, 'P21' => $c1,
        'C29' => $n2, 'D29' => $w2, 'P29' => $c2,
        'C37' => $n3, 'D37' => $w3, 'P37' => $c3,
        'P45' => $fpaid,
    ], $format, 'CNSS_190A_' . $month . '_' . $year);
    // officialTemplateExport يبثّ ويخرج؛ لو رجع false (أداة ناقصة) نوجّه للعرض العادي
    if (!$ok) { header('Location: ' . BASE_URL . 'pages/official_forms.php?form=' . urlencode($form) . '&month=' . $month . '&year=' . $year); exit; }
}

if ($form === 'cnss_work_attestation') {
    // افادة عمل للضمان (مديرية ضمان المرض والأمومة) — تعبئة قالب المستخدم الرسمي تلقائياً.
    // ?form=cnss_work_attestation&emp=ID&d=&mo=&yr=&format=pdf|xlsx
    $empId = (int)($_GET['emp'] ?? 0);
    $d  = (int)($_GET['d']  ?? date('j'));
    $mo = (int)($_GET['mo'] ?? date('n'));
    $yr = (int)($_GET['yr'] ?? date('Y'));

    // جلب الموظف ضمن نطاق المدرسة المسموح بها (أمان: لا يُفتح موظف خارج صلاحية المستخدم)
    $st = $db->prepare("SELECT * FROM employees WHERE id=? AND is_deleted=0 AND " . schoolScopeWhere('school_id'));
    $st->execute([$empId]);
    $emp = $st->fetch();
    if (!$emp) { http_response_code(404); die('الموظف غير موجود أو خارج صلاحيتك'); }

    // مدرسة الموظف هي صاحب العمل (نأخذ رقم المؤسسة منها لا من المدرسة المختارة)
    $ss = $db->prepare("SELECT * FROM schools WHERE id=?");
    $ss->execute([(int)$emp['school_id']]);
    $esch = $ss->fetch() ?: [];

    // رقم المؤسسة في الضمان «25 - 82 - 043» → 3 خانات R4/P4/N4 (يمين → يسار)
    $parts = preg_split('/[\s\-]+/', trim((string)($esch['nssf_employer_number'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $p1 = $parts[0] ?? ''; $p2 = $parts[1] ?? ''; $p3 = $parts[2] ?? '';

    // الاسم الثلاثي بالعربي (الاسم + اسم الأب + الشهرة)، fallback للفرنسي
    $name = trim(($emp['first_name_ar'] ?? '') . ' ' . ($emp['father_name_ar'] ?? '') . ' ' . ($emp['last_name_ar'] ?? ''));
    $name = preg_replace('/\s+/', ' ', $name);
    if ($name === '') $name = trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['last_name_fr'] ?? ''));

    $cells = [
        'B4' => $esch['name_ar'] ?? '',
        'R4' => $p1, 'P4' => $p2, 'N4' => $p3,
        'D6' => $name,
        'O6' => $emp['nssf_number'] ?? '',
        'R6' => $emp['birth_date'] ? substr($emp['birth_date'], 0, 4) : '',
        'N31' => $d, 'O31' => $mo, 'P31' => $yr,
    ];
    // الأشهر السبعة: تنتهي بآخر شهر كامل قبل التاريخ (mo-1) وترجع لورا؛ الصفّ 1 = الأقدم.
    $end = $mo - 1;
    for ($i = 0; $i < 7; $i++) {
        $idx  = $end - (6 - $i);
        $norm = (($idx - 1) % 12 + 12) % 12 + 1;
        $cells['B' . (10 + $i)] = monthName($norm, 'ar');
        $cells['E' . (10 + $i)] = 'دوام كامل';
    }

    // تحميل Excel (اختياري): يعمل حيث تتوفّر أدوات القالب (كمبيوتر المستخدم)؛ أونلاين يسقط للعرض الموحّد أدناه.
    if ($format === 'xlsx') {
        $ok = officialTemplateExport(__DIR__ . '/../assets/templates/cnss_work_attestation.xlsx', $cells, 'xlsx',
            'Afadat_Amal_' . $empId . '_' . $d . '-' . $mo . '-' . $yr);
        if ($ok) exit; // بُثَّ الملف وخرج
    }
    // ✅ العرض الرسمي الموحّد (أونلاين = محلي تماماً): صورة النموذج الرسمي خلفية + الحقول مركّبة فوقها بمكانها،
    // تُطبَع من المتصفّح → PDF. لا يعتمد على أي أداة خارجية، فيطلع نفس الشكل بالضبط في كل مكان.
    $bg = BASE_URL . 'assets/templates/cnss_work_attestation.png';
    $E  = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $fld = function ($align, $left, $top, $width, $text, $fs = 12) use ($E) {
        return '<div class="f ' . $align . '" style="left:' . $left . '%;top:' . $top . '%;width:' . $width . '%;font-size:' . $fs . 'pt">' . $E($text) . '</div>';
    };
    $rowY = [43.9, 46.2, 48.6, 50.9, 53.2, 55.5, 57.8];
    // اسم المؤسسة: سطر واحد بلا التفاف، محاذاة يمين تنتهي عند x62% ويمتدّ يساراً، والخطّ يصغر تلقائياً
    // للأسماء الطويلة (shrink-to-fit) تماماً كالنسخة الرسمية.
    $schName = (string)($esch['name_ar'] ?? '');
    $nlen = function_exists('mb_strlen') ? mb_strlen($schName, 'UTF-8') : strlen($schName);
    $sfs  = $nlen <= 22 ? 12 : ($nlen <= 34 ? 10 : ($nlen <= 46 ? 8.5 : 7.5));
    $stop = $nlen <= 22 ? 25.4 : 25.9;
    $F  = '<div style="position:absolute;right:38%;top:' . $stop . '%;white-space:nowrap;text-align:right;font-weight:bold;color:#000;font-size:' . $sfs . 'pt;line-height:1">' . $E($schName) . '</div>';
    $F .= $fld('c', 5, 24.4, 10, $p1) . $fld('c', 17, 24.4, 10, $p2) . $fld('c', 32, 24.4, 10, $p3); // رقم المؤسسة
    $F .= $fld('r', 34, 33.9, 26, $name);                              // اسم الأجير
    $F .= $fld('c', 5, 34, 10, $cells['R6']) . $fld('c', 17, 34, 22, $emp['nssf_number'] ?? ''); // سنة الولادة + رقم الضمان
    for ($i = 0; $i < 7; $i++) {                                        // جدول الأشهر
        $F .= $fld('c', 77, $rowY[$i], 13, $cells['B' . (10 + $i)]);
        $F .= $fld('c', 62, $rowY[$i], 15, $cells['E' . (10 + $i)]);
    }
    $F .= $fld('c', 27, 92.8, 6, $d) . $fld('c', 19, 92.8, 6, $mo) . $fld('c', 9, 92.8, 9, $yr); // التاريخ
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>إفادة عمل للضمان</title>'
       . '<style>@page{size:A4;margin:0}*{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
       . 'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif}'
       . '.page{position:relative;width:210mm;height:297mm;margin:0 auto;background:url(\'' . $E($bg) . '\') no-repeat;background-size:100% 100%}'
       . '.f{position:absolute;font-size:12pt;color:#000;font-weight:bold;line-height:1}.c{text-align:center}.r{text-align:right}'
       . '.bar{text-align:center;margin:10px 0}@media print{.bar{display:none}.page{margin:0}}'
       . '</style></head><body>'
       . '<div class="bar"><button onclick="window.print()" style="padding:9px 22px;font-size:15px;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ احفظ كـ PDF / اطبع</button></div>'
       . '<div class="page">' . $F . '</div>'
       . '<img src="' . $E($bg) . '" style="position:absolute;width:0;height:0;opacity:0" onload="setTimeout(function(){window.print();},250)">'
       . '</body></html>';
    exit;
}

http_response_code(400);
die('نموذج غير مدعوم للتصدير الرسمي بعد: ' . htmlspecialchars($form));
