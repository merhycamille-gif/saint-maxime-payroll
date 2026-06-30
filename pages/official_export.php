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

    $ok = officialTemplateExport(__DIR__ . '/../assets/templates/cnss_work_attestation.xlsx', $cells, $format,
        'Afadat_Amal_' . $empId . '_' . $d . '-' . $mo . '-' . $yr);
    // بديل أونلاين (بلا أدوات Python/LibreOffice): عرض الإفادة كصفحة HTML رسمية تُطبَع من المتصفّح → PDF.
    if (!$ok) {
        $rows = '';
        for ($i = 0; $i < 7; $i++) {
            $rows .= '<tr><td>' . htmlspecialchars($cells['B' . (10 + $i)]) . '</td><td>'
                  . htmlspecialchars($cells['E' . (10 + $i)]) . '</td></tr>';
        }
        $empNo = trim($p1 . ' - ' . $p2 . ' - ' . $p3, ' -');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>إفادة عمل للضمان</title>'
           . '<style>@page{size:A4;margin:18mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
           . 'body{font-family:"Cairo",Arial,sans-serif;direction:rtl;color:#000;font-size:14px;line-height:1.9}'
           . '.wrap{max-width:780px;margin:0 auto}h1{text-align:center;font-size:18px;margin:6px 0}'
           . '.sub{text-align:center;margin-bottom:18px;font-size:13px}.row{margin:6px 0}.lbl{font-weight:bold}'
           . 'table{width:100%;border-collapse:collapse;margin:14px 0}td{border:1px solid #444;padding:7px 10px;text-align:center}'
           . 'thead td{background:#1e3a8a;color:#fff;font-weight:bold}.sig{margin-top:40px;text-align:left}'
           . '.noprint{margin:14px 0;text-align:center}@media print{.noprint{display:none}}</style></head><body><div class="wrap">'
           . '<div class="noprint"><button onclick="window.print()" style="padding:8px 18px;font-size:15px;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ احفظ كـ PDF / اطبع</button></div>'
           . '<h1>إفادة عمل</h1><div class="sub">مديرية ضمان المرض والأمومة — الصندوق الوطني للضمان الاجتماعي</div>'
           . '<div class="row"><span class="lbl">المؤسسة:</span> ' . htmlspecialchars($esch['name_ar'] ?? '') . '</div>'
           . '<div class="row"><span class="lbl">رقم المؤسسة في الضمان:</span> ' . htmlspecialchars($empNo) . '</div>'
           . '<div class="row"><span class="lbl">الاسم الثلاثي:</span> ' . htmlspecialchars($name) . '</div>'
           . '<div class="row"><span class="lbl">رقم الضمان:</span> ' . htmlspecialchars($emp['nssf_number'] ?? '') . ''
           . ' &nbsp;&nbsp; <span class="lbl">سنة الولادة:</span> ' . htmlspecialchars($cells['R6']) . '</div>'
           . '<p>تشهد المؤسسة المذكورة أعلاه أنّ المضمون المذكور يعمل لديها دواماً كاملاً خلال الأشهر التالية:</p>'
           . '<table><thead><tr><td>الشهر</td><td>الدوام</td></tr></thead><tbody>' . $rows . '</tbody></table>'
           . '<div class="sig">حُرّر في: ' . $d . ' / ' . $mo . ' / ' . $yr . '<br><br>الخاتم والتوقيع</div>'
           . '<script>setTimeout(function(){window.print();},600);</script>'
           . '</div></body></html>';
        exit;
    }
}

http_response_code(400);
die('نموذج غير مدعوم للتصدير الرسمي بعد: ' . htmlspecialchars($form));
