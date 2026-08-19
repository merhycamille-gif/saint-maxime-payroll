<?php
/**
 * تصدير النماذج الحكومية الرسمية بتعبئة قالب المستخدم الأصلي (Excel) تلقائياً → PDF/Excel طبق الأصل.
 * نقطة مستقلّة بلا header (حتى لا تُرسَل الترويسة قبل بثّ الملف).
 *   ?form=cnss_contrib_monthly&month=&year=&format=pdf|xlsx
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/report_export.php';
requireLogin();

$db = getDB();
$form = $_GET['form'] ?? '';
$format = ($_GET['format'] ?? 'pdf') === 'xlsx' ? 'xlsx' : 'pdf';
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$school = currentSchool();
// 🔒 نموذج الضمان 190A يُصدَر لمؤسسة واحدة برقم صاحب عمل واحد — في وضع «كل المدارس»
// كان يُبَثّ ملفٌ بترويسة فارغة يجمع أرقام كل المدارس. اطلب اختيار مدرسة.
if (!$school) {
    $_SESSION['flash_error'] = 'هذا النموذج يُصدَر لمدرسة واحدة — اختر المدرسة من الأعلى أولاً. / Choisissez une seule école.';
    header('Location: ' . BASE_URL . 'pages/tax_declarations.php');
    exit;
}
// 🏛️ كل نماذج هذا الملف تابعة للضمان: اسم صاحب العمل حسب رقمه لدى الصندوق
// (25-82-043 ⇒ «الراهبات المخلصيات لسيدة البشارة» — cnssEmployerSchool)
$school = cnssEmployerSchool($school);

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

    $c1 = (int)$a['c']; $n1 = (int)$a['n']; $w1 = $c1 ? (int)round($c1 / cnssTotalFrac($month, $year)) : 0;
    $c2 = (int)$t['c']; $n2 = (int)$t['n']; $w2 = $c2 ? (int)round($c2 / rateFrac('end_of_service_rate', $month, $year, 8.5)) : 0;
    $c3 = (int)$fam['c']; $n3 = (int)$fam['n']; $w3 = $c3 ? (int)round($c3 / rateFrac('family_compensation_rate', $month, $year, 6)) : 0;

    $ok = officialTemplateExport(__DIR__ . '/../assets/templates/cnss_monthly.xlsx', [
        'D8'  => $school['name_ar'] ?? '',
        'G14' => $school['nssf_employer_number'] ?? '',
        'AC1' => monthName($month, 'ar'), 'AI1' => $year,
        'C21' => $n1, 'D21' => $w1, 'P21' => $c1,
        'C29' => $n2, 'D29' => $w2, 'P29' => $c2,
        'C37' => $n3, 'D37' => $w3, 'P37' => $c3,
        'P45' => $fpaid,
    ], $format, 'CNSS_190A_' . $month . '_' . $year, !empty($_GET['inline']) ? 'inline' : 'attachment');
    // officialTemplateExport يبثّ ويخرج؛ لو رجع false (تحويل PDF غير متاح على هذا الخادم —
    // أونلاين بلا LibreOffice) نوجّه للعرض الرسمي بالمتصفح مع شرح واضح بدل «ما صار شي».
    if (!$ok) {
        $_SESSION['flash_info'] = ($format === 'pdf')
            ? 'تحويل PDF الرسمي متاح على كمبيوتر المدرسة فقط. هون فيك: تنزّل «Excel رسمي» (معبّى بنفس الأرقام) أو تكبس Ctrl+P وتختار «حفظ كـ PDF» لطباعة النموذج المعروض. / Le PDF officiel n\'est disponible que sur l\'ordinateur de l\'école — téléchargez l\'Excel officiel ou imprimez avec Ctrl+P.'
            : 'تعذّر توليد الملف على هذا الخادم — جرّب من كمبيوتر المدرسة. / Génération impossible sur ce serveur.';
        header('Location: ' . BASE_URL . 'pages/official_forms.php?form=' . urlencode($form) . '&month=' . $month . '&year=' . $year);
        exit;
    }
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
    $esch = cnssEmployerSchool($ss->fetch() ?: []);

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

    // ✅ الأساس: نعبّئ قالب الإكسل الرسمي نفسه ونبثّه (PDF افتراضياً | Excel عند الطلب). هذه أدقّ محاذاة
    //    على الإطلاق لأنّها القالب الأصلي بالضبط، والـPDF ينزّل محفوظاً على الكمبيوتر جاهزاً للطباعة.
    //    يعمل حيث تتوفّر أدوات القالب (كمبيوتر المستخدم: Python+openpyxl + LibreOffice)؛ وإن تعذّرت (أونلاين
    //    بلا هذه الأدوات) يرجع false فنسقط تلقائياً للعرض المصوّر أدناه الذي يعمل في كل مكان.
    $tplName = 'Ifadat_Damane_' . $empId . '_' . $d . '-' . $mo . '-' . $yr;
    // mode=image يفرض النسخة المصوّرة (للفحص/المقارنة)؛ غير ذلك نجرّب قالب الإكسل أولاً.
    if (($_GET['mode'] ?? '') !== 'image'
        && officialTemplateExport(__DIR__ . '/../assets/templates/cnss_work_attestation.xlsx', $cells, $format, $tplName)) {
        exit; // بُثَّ الملف (pdf أو xlsx) وخرج
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
    $sfs  = $nlen <= 26 ? 12.5 : ($nlen <= 40 ? 11 : ($nlen <= 54 ? 9.5 : 8.5));
    $stop = 22.9;
    $F  = '<div style="position:absolute;right:22%;top:' . $stop . '%;white-space:nowrap;text-align:right;font-weight:bold;color:#000;font-size:' . $sfs . 'pt;line-height:1">' . $E($schName) . '</div>';
    $F .= $fld('c', 5, 24.4, 10, $p1) . $fld('c', 17, 24.4, 10, $p2) . $fld('c', 32, 24.4, 10, $p3); // رقم المؤسسة
    $F .= $fld('r', 34, 33.9, 26, $name);                              // اسم الأجير
    $F .= $fld('c', 5, 34, 10, $cells['R6']) . $fld('c', 17, 34, 22, $emp['nssf_number'] ?? ''); // سنة الولادة + رقم الضمان
    for ($i = 0; $i < 7; $i++) {                                        // جدول الأشهر
        $F .= $fld('c', 77, $rowY[$i], 13, $cells['B' . (10 + $i)]);
        $F .= $fld('c', 62, $rowY[$i], 15, $cells['E' . (10 + $i)]);
    }
    $F .= $fld('c', 27, 92.8, 6, $d) . $fld('c', 19, 92.8, 6, $mo) . $fld('c', 9, 92.8, 9, $yr); // التاريخ
    // كشف Chrome/Edge (منصّب على كمبيوتر المستخدم؛ غالباً غير متوفّر على الاستضافة أونلاين) — لتوليد PDF بكبسة.
    $chromeBin = null;
    foreach ([
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    ] as $c) { if (@is_file($c)) { $chromeBin = $c; break; } }
    $saveUrl = '?form=cnss_work_attestation&emp=' . $empId . '&d=' . $d . '&mo=' . $mo . '&yr=' . $yr . '&save=pdf';

    // بنّاء صفحة الإفادة الكاملة (HTML). $imgSrc: رابط الصورة (http للعرض | data:base64 عند التحويل لـPDF).
    // 🔴 الطباعة على طابعة حقيقية ضمن هامش 8mm (لا «حافة لحافة»، لأنّ الطابعات ترفض الطباعة بلا هامش فترجع
    //     الورقة فارغة). نُبقي أبعاد النموذج الداخلية 210×297mm (فتبقى الحقول بمكانها) ونُصغّره بـscale ليدخل A4.
    //     والصورة عنصر <img> (لا خلفية CSS) لتُطبَع دائماً حتى لو كان «Background graphics» مقفلاً.
    $buildHtml = function ($imgSrc, $bar) use ($F, $saveUrl, $E) {
        $barHtml = '';
        if ($bar === 'download') {
            $barHtml = '<div class="bar">'
                . '<a href="' . $E($saveUrl) . '" style="display:inline-block;padding:11px 26px;font-size:16px;font-weight:bold;background:#16a34a;color:#fff;border-radius:6px;text-decoration:none;margin-left:10px">💾 احفظ PDF على الكمبيوتر</a>'
                . '<button onclick="window.print()" style="padding:11px 26px;font-size:16px;font-weight:bold;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ اطبع</button>'
                . '<div style="color:#475569;font-size:13px;margin-top:6px">«احفظ PDF» بينزّل نسخة عالكمبيوتر مباشرةً — و«اطبع» بيفتح الطابعة</div></div>';
        } elseif ($bar === 'print') {
            $barHtml = '<div class="bar">'
                . '<button onclick="window.print()" style="padding:11px 26px;font-size:16px;font-weight:bold;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ اطبع / احفظ PDF</button>'
                . '<div style="color:#475569;font-size:13px;margin-top:6px">اكبس الزرّ ثمّ اختر طابعتك أو «حفظ كـ PDF»</div></div>';
        }
        return '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta http-equiv="Cache-Control" content="no-store"><title>إفادة عمل للضمان</title>'
           . '<style>@page{size:A4;margin:8mm}*{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
           . 'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif;background:#e9edf2}'
           . '.sheet{width:210mm;margin:0 auto}'
           . '.page{position:relative;width:210mm;height:297mm;background:#fff;overflow:hidden;transform-origin:top left}'
           . '.pbg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:0}'
           . '.f{position:absolute;z-index:1;font-size:12pt;color:#000;font-weight:bold;line-height:1}.c{text-align:center}.r{text-align:right}'
           . '.bar{text-align:center;margin:10px 0}'
           . '@media print{.bar{display:none}body{background:#fff;margin:0}.sheet{width:194mm;height:276mm;overflow:hidden;margin:0}.page{transform:scale(0.923)}}'
           . '</style></head><body>'
           . $barHtml
           . '<div class="sheet"><div class="page"><img class="pbg" src="' . $imgSrc . '" alt=""> ' . $F . '</div></div>'
           . '</body></html>';
    };

    // 💾 تنزيل PDF بكبسة (محلياً): نحوّل نفس الإفادة إلى ملف PDF عبر Chrome/Edge ونبثّه للتنزيل مباشرةً.
    if (($_GET['save'] ?? '') === 'pdf' && $chromeBin && function_exists('shell_exec')) {
        $imgData = @file_get_contents(__DIR__ . '/../assets/templates/cnss_work_attestation.png');
        if ($imgData !== false) {
            $html = $buildHtml('data:image/png;base64,' . base64_encode($imgData), 'none'); // صورة مضمّنة، بلا أزرار
            $tmp  = sys_get_temp_dir();
            $uid  = 'att_' . $empId . '_' . bin2hex(random_bytes(4));
            $htmlFile = $tmp . DIRECTORY_SEPARATOR . $uid . '.html';
            $pdfFile  = $tmp . DIRECTORY_SEPARATOR . $uid . '.pdf';
            $profile  = $tmp . DIRECTORY_SEPARATOR . 'cnss_pdf_chrome_profile'; // بروفايل ثابت (لا يتراكم)
            file_put_contents($htmlFile, $html);
            @shell_exec(escapeshellarg($chromeBin) . ' --headless=new --disable-gpu --no-pdf-header-footer'
                . ' --user-data-dir=' . escapeshellarg(str_replace('\\', '/', $profile))
                . ' --print-to-pdf=' . escapeshellarg(str_replace('\\', '/', $pdfFile))
                . ' ' . escapeshellarg('file:///' . str_replace('\\', '/', $htmlFile)) . ' 2>&1');
            @unlink($htmlFile);
            if (is_file($pdfFile) && filesize($pdfFile) > 800) {
                $data = file_get_contents($pdfFile); @unlink($pdfFile);
                while (ob_get_level()) ob_end_clean();
                $fname = 'Ifadat_Damane_' . $empId . '_' . $d . '-' . $mo . '-' . $yr . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $fname . '"');
                header('Content-Length: ' . strlen($data));
                echo $data; exit;
            }
            @unlink($pdfFile);
        }
        // فشل التوليد (لا Chrome/تعذّر) → نكمّل للعرض العادي مع زرّ الطباعة.
    }

    // العرض في المتصفّح: أزرار «احفظ PDF» (متوفّر عند وجود Chrome) + «اطبع»، وإلّا زرّ طباعة فقط (أونلاين).
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); // منع الكاش نهائياً — دائماً نسخة طازجة
    header('Pragma: no-cache');
    echo $buildHtml($bg, $chromeBin ? 'download' : 'print');
    exit;
}

/* ============================================================================
 * نماذج الضمان الرسمية الثلاثة (قوالب المستخدم الأصلية من الدسك توب — 2026-08-18):
 *   cnss_hire_new — تصريح باستخدام أجير غير مضمون سابقاً (CNSS-2AA)
 *   cnss_hire_reg — إعلام عن استخدام أجير مضمون سابقاً (عنده رقم ضمان)
 *   cnss_leave    — إعلام عن ترك أجير عمله في المؤسسة
 * تُعبَّأ خانةً خانة في قالب الإكسل الرسمي نفسه → PDF/Excel طبق الأصل (محلياً)،
 * وإن تعذّر تحويل PDF (أونلاين بلا LibreOffiche) نقع على النسخة المبنية بالبرنامج.
 * ========================================================================== */
if (in_array($form, ['cnss_hire_new', 'cnss_hire_reg', 'cnss_leave'], true)) {
    $empId = (int)($_GET['emp'] ?? 0);
    $d  = (int)($_GET['d']  ?? date('j'));
    $mo = (int)($_GET['mo'] ?? date('n'));
    $yr = (int)($_GET['yr'] ?? date('Y'));
    $sex = (($_GET['sex'] ?? 'm') === 'f') ? 'f' : 'm';

    // جلب الموظف ضمن نطاق المدرسة المسموح بها (أمان)
    $st = $db->prepare("SELECT * FROM employees WHERE id=? AND is_deleted=0 AND " . schoolScopeWhere('school_id'));
    $st->execute([$empId]);
    $emp = $st->fetch();
    if (!$emp) { http_response_code(404); die('الموظف غير موجود أو خارج صلاحيتك'); }

    // مدرسة الموظف هي صاحب العمل (اسمها ورقمها في الضمان وهاتفها وعنوانها)
    // — والاسم باسم صاحب الرقم لدى الصندوق (cnssEmployerSchool)
    $ss = $db->prepare("SELECT * FROM schools WHERE id=?");
    $ss->execute([(int)$emp['school_id']]);
    $esch = cnssEmployerSchool($ss->fetch() ?: []);

    // رقم المؤسسة في الضمان «25 - 82 - 043» → 3 خانات يمين→يسار كما في النموذج الورقي
    $parts = preg_split('/[\s\-]+/', trim((string)($esch['nssf_employer_number'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $p1 = $parts[0] ?? ''; $p2 = $parts[1] ?? ''; $p3 = $parts[2] ?? '';
    // الهاتف «07 975440» → مقدّمة + رقم بخانتين منفصلتين كما في القالب
    $phSplit = function ($phone) {
        $ph = preg_replace('/[^0-9]/', '', (string)$phone);
        if (strlen($ph) >= 7 && $ph[0] === '0') return [substr($ph, 0, 2), substr($ph, 2)];
        return ['', $ph];
    };
    [$sPhPre, $sPhNum] = $phSplit($esch['phone'] ?? '');

    // الاسم والأهل والولادة والسجل
    $name = preg_replace('/\s+/', ' ', trim(($emp['first_name_ar'] ?? '') . ' ' . ($emp['father_name_ar'] ?? '') . ' ' . ($emp['last_name_ar'] ?? '')));
    if ($name === '') $name = trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['last_name_fr'] ?? ''));
    $first  = trim((string)($emp['first_name_ar'] ?? '')) ?: trim((string)($emp['first_name_fr'] ?? ''));
    $last   = trim((string)($emp['last_name_ar'] ?? ''))  ?: trim((string)($emp['last_name_fr'] ?? ''));
    $father = trim((string)($emp['father_name_ar'] ?? ''));
    $mother = trim(((string)($emp['mother_first_name'] ?? '')) . ' ' . ((string)($emp['mother_last_name'] ?? '')));
    $bTs = $emp['birth_date'] ? strtotime($emp['birth_date']) : 0;
    $bD = $bTs ? (int)date('j', $bTs) : ''; $bM = $bTs ? (int)date('n', $bTs) : '';
    $bY = $bTs ? date('Y', $bTs) : ''; $bYY = $bY !== '' ? substr($bY, 2) : '';
    $hTs = $emp['hire_date'] ? strtotime($emp['hire_date']) : 0;
    $hD = $hTs ? (int)date('j', $hTs) : ''; $hM = $hTs ? (int)date('n', $hTs) : ''; $hY = $hTs ? date('Y', $hTs) : '';
    $natRaw = trim((string)($emp['nationality'] ?? ''));
    $nat = ($natRaw === '' || strcasecmp($natRaw, 'lebanese') === 0 || $natRaw === 'لبناني' || $natRaw === 'لبنانية') ? 'لبنانية' : $natRaw;
    $married = strpos((string)($emp['social_status'] ?? ''), 'marie') === 0;
    $registry = trim((string)($emp['civil_registry_number'] ?? ''));
    $regPlace = trim((string)($emp['civil_registry_place'] ?? ''));
    // الصفة: الموظف الإداري بنوع وظيفته الفعلي، وإلا فئته (نفس منطق النماذج المبنية)
    $jobT = trim((string)($emp['job_title'] ?? ''));
    $fnAr = ($emp['employee_type'] === 'employe' && $jobT !== '') ? jobTitleLabel($jobT, 'ar') : employeeTypeLabel($emp['employee_type'], 'ar');
    // ساعات العمل في الشهر (قابلة للتعديل من الشاشة؛ الافتراضي من ساعات الأسبوع)
    $hrs = trim((string)($_GET['hrs'] ?? ''));
    if ($hrs === '' && (float)($emp['hours_per_week'] ?? 0) > 0) $hrs = (string)round((float)$emp['hours_per_week'] * 52 / 12);

    // 🔴 مصدر واحد: الراتب الخاضع للضمان من رواتب **السنة الدراسية المعروضة** (نفس اختيار الإفادات)
    $salPickSql = "ORDER BY (prime_fixe_lbp > 0 OR extra_lbp > 0) DESC,
                 (net_salary_lbp > 0 OR base_plus_echelon_lbp > 0) DESC,
                 year DESC, month DESC LIMIT 1";
    $sal = null;
    $attSy = activeSchoolYear();
    if ($attSy !== 'all') {
        $q = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND school_year = ? " . $salPickSql);
        $q->execute([$empId, $attSy]);
        $sal = $q->fetch();
    }
    if (!$sal) {
        $q = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? " . $salPickSql);
        $q->execute([$empId]);
        $sal = $q->fetch();
    }
    $wage = $sal ? cnssSubjectWageLbp($sal, $emp) : 0;
    $wageNum = $wage > 0 ? number_format($wage) : '';
    $wageWords = $wage > 0 ? (numToArabicWords($wage) . ' ليرة لبنانية') : '';

    // اسم المسؤول الموقّع (المدير/الرئيسة — أول مسؤولي المدرسة)
    $sigs = schoolSignatories($esch);
    $sigName = trim((string)($sigs[0]['name'] ?? ''));

    if ($form === 'cnss_hire_new') {
        // تصريح باستخدام أجير — غير مضمون سابقاً (CNSS-2AA)
        $cells = [
            'K5' => $p1, 'J5' => $p2, 'I5' => $p3,
            'B7' => $esch['name_ar'] ?? '',
            'K8' => $sPhNum, 'L8' => $sPhPre,
            'G9' => $esch['address'] ?? '',
            'C13' => $sex === 'm' ? 'X' : '1', 'E13' => $sex === 'f' ? 'X' : '2',
            'C14' => $first, 'K14' => $last,
            'C15' => $father, 'J15' => $mother,
            'D16' => trim(($emp['birth_place'] ?? '') . ' ' . ($bTs ? formatDate($emp['birth_date']) : '')),
            'I16' => $regPlace, 'L16' => $registry,
            'D17' => !$married ? 'X' : '1', 'F17' => $married ? 'X' : '2',
            'E18' => $regPlace !== '' ? $regPlace : trim((string)($emp['ville'] ?? '')),
            'F19' => $emp['gouvernorat'] ?? '', 'J19' => $emp['district'] ?? '',
            'D20' => $emp['ville'] ?? '', 'I20' => $emp['quartier'] ?? '', 'K20' => $emp['rue'] ?? '',
            'C21' => trim(($emp['immeuble'] ?? '') . (trim((string)($emp['etage'] ?? '')) !== '' ? ' طابق ' . $emp['etage'] : '')),
            'D22' => $hD, 'E22' => $hM, 'F22' => $hY, 'L22' => $hrs,
            'D23' => 'X',
            'D24' => $fnAr, 'J24' => $wageNum,
            'E25' => $wageWords,
            'D26' => 'X',
            // سطر التوقيع: الاسم ملحق بخانة النص الطويلة (تفيض بحرية — H33 الضيقة تقصّه)،
            // والتاريخ كاملاً بالخانة العريضة K33 (M33/N33 أضيق من 4 أرقام فتظهر #)
            'A33' => 'اوافق على صحة المعلومات المدرجة أعلاه: اسم الأجير:  ' . trim($first . ' ' . $last),
            'K33' => $d . ' / ' . $mo . ' / ' . $yr,
            'L36' => $sigName,
        ];
        [$empPhPre, $empPhNum] = $phSplit($emp['phone1'] ?? '');
        $cells['I21'] = $empPhNum; $cells['K21'] = $empPhPre;
        $tplFile = 'cnss_hire_new.xlsx'; $tplName = 'Tasrih_Istikhdam_Ajir_' . $empId;
        $fallbackForm = 'cnss_employ2';
    } elseif ($form === 'cnss_hire_reg') {
        // إعلام عن استخدام أجير — مضمون سابقاً (عنده رقم ضمان)
        $cells = [
            'C6' => $esch['name_ar'] ?? '',
            'K7' => $p3, 'M7' => $p2, 'N7' => $p1,
            'K8' => $sPhNum, 'M8' => $sPhPre,
            'A9' => 'وعنوانها الكامل:  ' . trim((string)($esch['address'] ?? '')),
            'K11' => $emp['nssf_number'] ?? '', 'N11' => $bYY,
            'C12' => $sex === 'm' ? 'X' : '1', 'E12' => $sex === 'f' ? 'X' : '2',
            'B13' => $first, 'E13' => $last, 'K13' => $emp['nssf_number'] ?? '', 'N13' => $bYY,
            'B14' => $father, 'H14' => $mother,
            'C15' => $bD, 'D15' => $bM, 'E15' => $bY, 'H15' => $registry, 'L15' => $nat,
            'C16' => !$married ? 'X' : '1', 'E16' => $married ? 'X' : '2',
            'B17' => $hD, 'C17' => $hM, 'D17' => $hY, 'J17' => $hrs,
            'B18' => 'X',
            'C19' => $fnAr, 'G19' => trim($wageNum . '  ' . $wageWords),
            'C20' => 'X',
            'B27' => $first, 'C27' => $last,
            'L27' => $sigName,
        ];
        $tplFile = 'cnss_hire_reg.xlsx'; $tplName = 'Ilam_Istikhdam_Ajir_' . $empId;
        $fallbackForm = 'cnss_employ';
    } else {
        // إعلام عن ترك أجير عمله في المؤسسة
        // 🔴 تاريخ الترك يُقرأ من ملف الموظف (تاريخ ترك الضمان) حصراً — لا من الشاشة
        // (بطلب المستخدم 2026-08-18)؛ إن لم يكن محطوطاً بالملف تبقى الخانات فارغة (لا تاريخ اليوم).
        $ldTs = $emp['left_date_cnss'] ? strtotime($emp['left_date_cnss']) : 0;
        $reason = (int)($_GET['reason'] ?? 1); if ($reason < 1 || $reason > 7) $reason = 1;
        $reasonCells = [1 => 'C20', 2 => 'E20', 3 => 'G20', 4 => 'I20', 5 => 'K20', 6 => 'M20', 7 => 'O20'];
        $cells = [
            'E8' => $esch['name_ar'] ?? '',
            'P9' => $p1, 'O9' => $p2, 'N9' => $p3,
            'L10' => $sPhNum, 'N10' => $sPhPre,
            'C11' => $esch['address'] ?? '',
            'N13' => $emp['nssf_number'] ?? '', 'P13' => $bYY,
            'C14' => $sex === 'm' ? 'X' : '1', 'F14' => $sex === 'f' ? 'X' : '2',
            'B15' => $first, 'I15' => $last,
            'B16' => $father, 'K16' => $mother,
            'D17' => $emp['birth_place'] ?? '', 'F17' => $bTs ? formatDate($emp['birth_date']) : '',
            'K17' => $registry, 'O17' => $nat,
            'D18' => !$married ? 'X' : '1', 'F18' => $married ? 'X' : '2',
            'C19' => $ldTs ? (int)date('j', $ldTs) : '', 'E19' => $ldTs ? (int)date('n', $ldTs) : '', 'F19' => $ldTs ? date('Y', $ldTs) : '',
            $reasonCells[$reason] => 'X',
            'D21' => $fnAr,
            'F22' => $wageNum, 'I22' => $wageWords,
            'A23' => trim((string)($esch['ville'] ?? '') ?: 'صيدا') . ' في ' . $d . '/' . $mo . '/' . $yr
                   . str_repeat(' ', 20) . 'خاتم المؤسسة       اسم المسؤول عن المؤسسة وتوقيعه',
            'K25' => $first, 'L25' => $last,
        ];
        $tplFile = 'cnss_leave.xlsx'; $tplName = 'Ilam_Tark_Ajir_' . $empId;
        $fallbackForm = 'cnss_terminate';
    }

    if (officialTemplateExport(__DIR__ . '/../assets/templates/' . $tplFile, $cells, $format, $tplName . '_' . $d . '-' . $mo . '-' . $yr)) {
        exit; // بُثَّ الملف (pdf أو xlsx) وخرج
    }
    // تعذّر توليد الملف (أونلاين بلا LibreOffice) → النسخة المبنية بالبرنامج بنفس المعلومات
    $_SESSION['flash_info'] = 'النموذج الرسمي طبق الأصل (PDF) يتولّد على كمبيوتر المدرسة. هون فتحنالك النسخة المبنية بالبرنامج بنفس المعلومات — اطبعها من زرّ الطباعة، أو نزّل Excel الرسمي. / Le PDF officiel n\'est disponible que sur l\'ordinateur de l\'école — version imprimable affichée.';
    header('Location: ' . BASE_URL . 'pages/official_forms.php?form=' . $fallbackForm . '&employee_id=' . $empId);
    exit;
}

http_response_code(400);
die('نموذج غير مدعوم للتصدير الرسمي بعد: ' . htmlspecialchars($form));
