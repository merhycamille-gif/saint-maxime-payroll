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

    $cells190 = [
        'D8'  => $school['name_ar'] ?? '',
        'G14' => $school['nssf_employer_number'] ?? '',
        'AC1' => monthName($month, 'ar'), 'AI1' => $year,
        'C21' => $n1, 'D21' => formatLBP($w1, false), 'P21' => formatLBP($c1, false),
        'C29' => $n2, 'D29' => formatLBP($w2, false), 'P29' => formatLBP($c2, false),
        'C37' => $n3, 'D37' => formatLBP($w3, false), 'P37' => formatLBP($c3, false),
        // خانتا المجموع والباقي صيغتان بالإكسل — بالنسخة المصوّرة نحسبهما هنا
        'P43' => formatLBP($c1 + $c2 + $c3, false),
        'P45' => formatLBP($fpaid, false),
        'P47' => formatLBP(($c1 + $c2 + $c3) - $fpaid, false),
    ];
    $ok = false;
    if (($_GET['mode'] ?? '') !== 'image') {
        $ok = officialTemplateExport(__DIR__ . '/../assets/templates/cnss_monthly.xlsx', [
            'D8'  => $school['name_ar'] ?? '',
            'G14' => $school['nssf_employer_number'] ?? '',
            'AC1' => monthName($month, 'ar'), 'AI1' => $year,
            'C21' => $n1, 'D21' => $w1, 'P21' => $c1,
            'C29' => $n2, 'D29' => $w2, 'P29' => $c2,
            'C37' => $n3, 'D37' => $w3, 'P37' => $c3,
            'P45' => $fpaid,
        ], $format, 'CNSS_190A_' . $month . '_' . $year, !empty($_GET['inline']) ? 'inline' : 'attachment');
    }
    // officialTemplateExport يبثّ ويخرج؛ لو رجع false (تحويل PDF غير متاح — أونلاين بلا
    // LibreOffice) → «هيدي PDF مش مظبوطة» (p1 ‏2026-08-21): نفس آلية النماذج الثلاثة —
    // صورة القالب الرسمي الفاضي + القيم مركّبة فوقها بإحداثيات معايَرة (cnss_monthly.pos.json)
    // فيطلع زرّ الطباعة/PDF طبق الأصل الرسمي بلا أي أداة على الخادم. النموذج أفقي (A4 landscape).
    if (!$ok) {
        $posFile190 = __DIR__ . '/../assets/templates/cnss_monthly.pos.json';
        $bgFile190  = __DIR__ . '/../assets/templates/cnss_monthly.png';
        if ($format === 'pdf' && is_file($posFile190) && is_file($bgFile190)) {
            $pos = json_decode((string)file_get_contents($posFile190), true) ?: [];
            $fsBase = (float)($pos['fs'] ?? 10.7);
            $E = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
            $F = '';
            foreach ($cells190 as $ref => $val) {
                $val = trim((string)$val);
                if ($val === '' || $val === '0' || !isset($pos['cells'][$ref])) continue;
                $p = $pos['cells'][$ref];
                $len = function_exists('mb_strlen') ? mb_strlen($val, 'UTF-8') : strlen($val);
                $fs = $fsBase * ($len > 30 ? 0.8 : 1);
                $style = 'top:' . $p['yt'] . '%;font-size:' . round($fs, 1) . 'pt';
                if (($p['align'] ?? '') === 'center') {
                    $style .= ';left:' . $p['xc'] . '%;transform:translateX(-50%)';
                } else {
                    $style .= ';right:' . round(100 - $p['xr'], 2) . '%';
                }
                $F .= '<div class="f" style="' . $style . '">' . $E($val) . '</div>';
            }
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta http-equiv="Cache-Control" content="no-store"><title>CNSS 190A</title>'
               . '<style>@page{size:A4 landscape;margin:0}*{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
               . 'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif;background:#e9edf2}'
               . '.sheet{width:297mm;margin:0 auto;direction:ltr}'
               . '.page{position:relative;width:297mm;height:210mm;background:#fff;overflow:hidden;transform-origin:top left}'
               . '.pbg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:0}'
               . '.f{position:absolute;z-index:1;color:#000;font-weight:bold;line-height:1.15;white-space:nowrap}'
               . '.bar{text-align:center;margin:10px 0}'
               /* «بدي ياها تكون قد A4» (2026-08-21): هامش صفحة 0 وبلا أي تصغير — النموذج بحجمه الكامل */
               . '@media print{.bar{display:none}body{background:#fff;margin:0}.sheet{width:297mm;height:210mm;overflow:hidden;margin:0}.page{transform:none}}'
               . '</style></head><body>'
               . '<div class="bar"><button onclick="window.print()" style="padding:11px 26px;font-size:16px;font-weight:bold;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ اطبع / احفظ PDF</button>'
               . '<div style="color:#475569;font-size:13px;margin-top:6px">اكبس الزرّ ثمّ اختر طابعتك أو «حفظ كـ PDF» — النموذج طبق الأصل الرسمي (اتجاه الورقة: أفقي/Paysage)</div></div>'
               . '<div class="sheet"><div class="page"><img class="pbg" src="' . BASE_URL . 'assets/templates/cnss_monthly.png" alt=""> ' . $F . '</div></div>'
               . '</body></html>';
            exit;
        }
        $_SESSION['flash_info'] = ($format === 'pdf')
            ? 'تحويل PDF الرسمي متاح على كمبيوتر المدرسة فقط. هون فيك: تنزّل «Excel رسمي» (معبّى بنفس الأرقام) أو تكبس Ctrl+P وتختار «حفظ كـ PDF» لطباعة النموذج المعروض. / Le PDF officiel n\'est disponible que sur l\'ordinateur de l\'école — téléchargez l\'Excel officiel ou imprimez avec Ctrl+P.'
            : 'تعذّر توليد الملف على هذا الخادم — جرّب من كمبيوتر المدرسة. / Génération impossible sur ce serveur.';
        header('Location: ' . BASE_URL . 'pages/official_forms.php?form=' . urlencode($form) . '&month=' . $month . '&year=' . $year);
        exit;
    }
}

if ($form === 'mof_r3') {
    // 🏛️ نموذج وزارة المالية ر3 — «طلب تسجيل مستخدم/أجير جديد» طبق الأصل («شوف ر3 على
    // الدسك توب وبدي متلها طبق الأصل» — 2026-08-21): صورة النموذج الرسمي (mof_r3.png —
    // منذ 2026-08-22 الصورة عالية الدقة 300dpi من ملفه r3_exel.xlsx «ر3 اكسل بدي ياها طبق
    // الاصل»، نفس هندسة القديمة بالمليم فالإحداثيات لم تتغيّر) + معلومات الموظف مركّبة
    // فوقها بإحداثيات مقيسة من الـPDF
    // نفسه (تسميات fitz + مسح مربعات PIL). A4 عمودي بالحجم الكامل (قاعدة «قد A4»).
    $empId = (int)($_GET['emp'] ?? 0);
    $st = $db->prepare("SELECT * FROM employees WHERE id=? AND is_deleted=0 AND " . schoolScopeWhere('school_id'));
    $st->execute([$empId]);
    $emp = $st->fetch();
    if (!$emp) { http_response_code(404); die('الموظف غير موجود أو خارج صلاحيتك'); }
    // للمالية: مدرسة الموظف نفسها برقمها المالي (لا تخضع لقاعدة «صاحب العمل بالضمان»)
    $ss = $db->prepare("SELECT * FROM schools WHERE id=?");
    $ss->execute([(int)$emp['school_id']]);
    $esch = $ss->fetch() ?: [];

    // الجنس: من الرابط إن حُدِّد، وإلا تلقائياً من خانة gender بملف الموظف (2026-08-22)
    $sex  = (($_GET['sex'] ?? (($emp['gender'] ?? '') ?: 'm')) === 'f') ? 'f' : 'm';
    $wage = in_array(($_GET['wage'] ?? 'm'), ['m', 'd', 'h'], true) ? ($_GET['wage'] ?? 'm') : 'm';
    $social = (string)($emp['social_status'] ?? 'celibataire');
    $isMar = (strpos($social, 'marie') === 0);
    $marKey = $isMar ? 'married' : ((strpos($social, 'veu') === 0) ? 'widow' : ((strpos($social, 'divor') === 0) ? 'divorced' : 'single'));
    $mofNum = preg_replace('/\D/', '', (string)($emp['finance_ministry_number'] ?? ''));
    $dsplit = function ($date) { $t = $date ? strtotime($date) : 0; return $t ? [date('j', $t), date('n', $t), date('Y', $t)] : ['', '', '']; };
    [$bD, $bM, $bY] = $dsplit($emp['birth_date'] ?? '');
    [$hD, $hM, $hY] = $dsplit($emp['hire_date'] ?? '');
    $famBenef = (int)($emp['number_of_children'] ?? 0) + (($isMar && !(int)($emp['spouse_works'] ?? 0)) ? 1 : 0);
    $motherAr = trim(($emp['mother_first_name'] ?? '') . ' ' . ($emp['mother_last_name'] ?? ''));
    $regNo3 = trim((string)($emp['civil_registry_number'] ?? ''));
    $regPl3 = trim((string)($emp['civil_registry_place'] ?? ''));

    // الحقول: [النص، x%، y%، محاذاة r=مرساة يمين / c=توسيط، حجم الخط pt]
    $R3 = [];
    $put = function ($t, $x, $y, $a = 'r', $fs = 9.5) use (&$R3) { $t = trim((string)$t); if ($t !== '' && $t !== '0/0/0') $R3[] = [$t, $x, $y, $a, $fs]; };
    $digits = function ($num, $centers, $y) use (&$R3) { // رقم بخانات: يتعبّأ من يمين الصف (آخر الخانات)
        $num = preg_replace('/\D/', '', (string)$num);
        $off = max(0, count($centers) - strlen($num));
        for ($i = 0; $i < strlen($num) && ($off + $i) < count($centers); $i++) $R3[] = [$num[$i], $centers[$off + $i], $y, 'c', 10];
    };
    $X = function ($x, $y) use (&$R3) { $R3[] = ['×', $x, $y, 'c', 11]; };

    // 🗺️ الإحداثيات مقيسة على mof_r3.png نفسها: مواقع السطور والخانات مكشوفة آلياً من
    // الصورة (كشف صفوف/أعمدة السواد) ثم تثبيت بصري مكبّراً حقلاً حقلاً — «مش جايين
    // المعلومات بمحلهون على السطر» (2026-08-22): النص يقعد على سطره تماماً (top = سطر −
    // صعود الخط) والأرقام بوسط خاناتها الحقيقية (٩-١٠ خانات بخطوة ~2.08٪ لا 8×2.5).
    // المؤسسة
    $put($esch['name_ar'] ?? '', 76.5, 9.05, 'r', 8.5);
    $digits($esch['finance_number'] ?? '', [56.06, 58.16, 60.24, 62.32, 64.39, 66.46, 68.54, 70.61, 72.71, 74.91], 12.6);
    // هل لديه رقم مالي شخصي؟ + الرقم
    if ($mofNum !== '') { $X(47.85, 16.1); $digits($mofNum, [2.24, 4.33, 6.42, 8.48, 10.56, 12.64, 14.72, 16.81, 18.88], 16.25); }
    else { $X(37.25, 16.1); }
    // تعريف المستخدم
    $put($emp['first_name_ar'] ?? '', 84.5, 19.3);
    $put($emp['last_name_ar'] ?? '', 38.5, 19.5);
    $put($emp['father_name_ar'] ?? '', 84.5, 21.5);
    $put($motherAr, 25.2, 21.6);
    $X($sex === 'f' ? 74.5 : 83.2, 23.8);
    $natR3 = in_array(mb_strtolower(trim((string)($emp['nationality'] ?? ''))), ['lebanese', 'lebanaise', 'libanaise', 'لبنانية', 'لبناني'], true) ? 'لبنانية' : (string)($emp['nationality'] ?? '');
    $put($natR3, 40.5, 24.45);
    $put($emp['birth_place'] ?? '', 79.5, 26.6);
    $put($bD, 34.3, 26.25, 'c'); $put($bM, 27.3, 26.25, 'c'); $put($bY, 18.8, 26.25, 'c');
    $put($regNo3, 84.0, 29.25);
    $put($regPl3, 56.5, 29.35);
    $X(['single' => 78.4, 'married' => 70.35, 'widow' => 65.0, 'divorced' => 54.75][$marKey], 31.7);
    $put((string)(int)($emp['number_of_children'] ?? 0), 21.5, 31.2);
    $put($hD, 76.0, 34.2, 'c'); $put($hM, 66.0, 34.2, 'c'); $put($hY, 57.5, 34.2, 'c');
    $put($emp['nssf_number'] ?? '', 18.2, 33.3);
    $X(['m' => 22.7, 'd' => 15.2, 'h' => 8.1][$wage], 35.95);
    // الزوج/الزوجة: كل معلوماته من ملف الموظف (تُعبَّأ بشاشة نموذج ر3 — «وين معلومات
    // الزوج/الزوجة» + «ناقصة كتير معلومات» 2026-08-21) + المستفيدون من التنزيل + هل يعمل
    $spNat = in_array(mb_strtolower(trim((string)($emp['spouse_nationality'] ?? ''))), ['lebanese', 'lebanaise', 'libanaise', 'لبنانية', 'لبناني'], true) ? 'لبنانية' : (string)($emp['spouse_nationality'] ?? '');
    [$sD, $sM, $sY] = $dsplit($emp['spouse_birth_date'] ?? '');
    $put($emp['spouse_full_name'] ?? '', 72.5, 40.6);
    $put($emp['spouse_maiden_name'] ?? '', 38.5, 41.0);
    $put($emp['spouse_father_name'] ?? '', 84.0, 43.45);
    $put($emp['spouse_mother_name'] ?? '', 31.8, 43.75);
    $put($spNat, 86.0, 46.3);
    $put($emp['spouse_birth_place'] ?? '', 40.0, 46.65);
    $put($sD, 77.5, 48.95, 'c'); $put($sM, 70.5, 48.95, 'c'); $put($sY, 64.0, 48.95, 'c');
    $put($emp['spouse_id_card'] ?? '', 40.0, 49.25);
    if ($famBenef > 0) $put((string)$famBenef, 57.0, 52.3, 'c');
    if ($isMar) $X((int)($emp['spouse_works'] ?? 0) ? 67.75 : 59.9, 54.4);
    $digits($emp['spouse_mof_number'] ?? '', [61.06, 63.16, 65.24, 67.31, 69.39, 71.46, 73.54, 75.61, 77.69], 57.62);
    if (!empty($emp['spouse_employer_public'])) {
        $put($emp['spouse_employer_name'] ?? '', 66.0, 66.0, 'r', 8.5); // ب — الإدارات العامة: إسم الإدارة
    } else {
        $put($emp['spouse_employer_name'] ?? '', 59.0, 62.5, 'r', 8.5); // أ — القطاع الخاص
        $digits($emp['spouse_employer_mof'] ?? '', [3.03, 5.12, 7.2, 9.27, 11.34, 13.42, 15.5, 17.58, 19.67], 61.5);
    }
    // عنوان السكن
    $put($emp['gouvernorat'] ?? '', 87.5, 69.15);
    $put($emp['district'] ?? '', 69.5, 69.15);
    $put($emp['ville'] ?? '', 46.0, 69.15);
    $put($emp['quartier'] ?? '', 25.5, 69.15);
    $put($emp['rue'] ?? '', 87.5, 70.5);
    $put($emp['immeuble'] ?? '', 88.0, 71.9);
    $put($emp['etage'] ?? '', 59.0, 71.9);
    $put($emp['phone1'] ?? '', 41.5, 71.9);
    $put($emp['phone2'] ?? '', 14.5, 72.36);
    $put($emp['email'] ?? '', 66.0, 75.1, 'r', 8.5);
    // الإفادة (يوقّعها صاحب العمل)
    $put($esch['name_ar'] ?? '', 76.0, 84.05, 'r', 7);
    $put(date('j'), 64.5, 91.0, 'c'); $put(date('n'), 59.0, 91.0, 'c'); $put(date('Y'), 52.0, 91.0, 'c');
    // خاص بالإدارة («وخاص بالإدارة» — بطلبه 2026-08-21): الرقم المالي بالخانات + تاريخ التسجيل
    $digits($mofNum, [11.32, 13.41, 15.49, 17.56, 19.63, 21.72, 23.79, 25.88, 27.95], 82.45);
    $put(date('j'), 31.5, 86.1, 'c'); $put(date('n'), 25.8, 86.1, 'c'); $put(date('Y'), 19.8, 86.1, 'c');

    // 📊 «بدي ياها اكسل كمان» (2026-08-22): نفس النموذج طبق الأصل ملف إكسل — القالب
    // assets/templates/mof_r3_excel.xlsx هو ملف المستخدم r3_exel.xlsx نفسه (صورة النموذج
    // الرسمي قد A4 بورقة إكسل، هوامش صفر + fitToPage) والقيم تُركَّب فوق الصورة كنصوص
    // Text Box بنفس إحداثيات النسخة المطبوعة تماماً (٪ من عرض/طول A4 → EMU).
    if (($_GET['format'] ?? '') === 'xlsx') {
        $tplR3 = __DIR__ . '/../assets/templates/mof_r3_excel.xlsx';
        $tmpR3 = tempnam(sys_get_temp_dir(), 'r3x');
        $okR3 = false;
        if (is_file($tplR3) && @copy($tplR3, $tmpR3) && class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($tmpR3) === true) {
                $dr = $z->getFromName('xl/drawings/drawing1.xml');
                if ($dr !== false) {
                    $Wx = 7562850; $Hx = 10696575; // أبعاد صورة النموذج بالقالب = A4 كاملة (EMU)
                    $shapes = ''; $sid = 100;
                    foreach ($R3 as $f) {
                        [$t, $x, $y, $a, $fs] = $f;
                        $cy = (int)round($fs * 1.6 * 12700);
                        // 🔴 الاتجاه صريح دائماً («R3 ايلي بولس» 2026-08-22): rtl="1" للعربي فقط،
                        // وrtl="0" + lang لاتينية لغيره — بلا تصريح، الإكسل يخمّن من lang=ar
                        // فيقلب الهاتف والإيميل والأرقام حرفاً حرفاً.
                        $isAr = (bool)preg_match('/\p{Arabic}/u', (string)$t);
                        if ($a === 'c') { $cx = 720000; $algn = 'ctr'; $rtl = ' rtl="0"'; }
                        else { $cx = 3000000; $algn = 'r'; $rtl = $isAr ? ' rtl="1"' : ' rtl="0"'; }
                        $rlang = $isAr ? 'ar-LB' : 'en-US';
                        $px = (int)round($Wx * $x / 100 - ($a === 'c' ? $cx / 2 : $cx));
                        if ($px < 0) { $cx += $px; $px = 0; }
                        $py = (int)round($Hx * $y / 100);
                        $txt = htmlspecialchars((string)$t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        $sid++;
                        $shapes .= '<xdr:absoluteAnchor><xdr:pos x="' . $px . '" y="' . $py . '"/><xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                            . '<xdr:sp macro="" textlink=""><xdr:nvSpPr><xdr:cNvPr id="' . $sid . '" name="fld' . $sid . '"/><xdr:cNvSpPr txBox="1"/></xdr:nvSpPr>'
                            . '<xdr:spPr><a:xfrm><a:off x="' . $px . '" y="' . $py . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></xdr:spPr>'
                            . '<xdr:txBody><a:bodyPr wrap="none" lIns="0" tIns="0" rIns="0" bIns="0" anchor="t"/><a:lstStyle/>'
                            . '<a:p><a:pPr algn="' . $algn . '"' . $rtl . '/><a:r><a:rPr lang="' . $rlang . '" sz="' . (int)round($fs * 100) . '" b="1">'
                            . '<a:latin typeface="Arial"/><a:cs typeface="Arial"/></a:rPr><a:t>' . $txt . '</a:t></a:r></a:p>'
                            . '</xdr:txBody></xdr:sp><xdr:clientData/></xdr:absoluteAnchor>';
                    }
                    $z->addFromString('xl/drawings/drawing1.xml', str_replace('</xdr:wsDr>', $shapes . '</xdr:wsDr>', $dr));
                    $okR3 = true;
                }
                $z->close();
            }
        }
        if (!$okR3) { @unlink($tmpR3); http_response_code(500); die('تعذّر توليد ملف الإكسل — القالب mof_r3_excel.xlsx غير متوفر'); }
        $fnR3 = 'R3_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', trim(($emp['first_name_ar'] ?: $emp['first_name_fr']) . '_' . ($emp['last_name_ar'] ?: $emp['last_name_fr']))) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($fnR3) . '"; filename*=UTF-8\'\'' . rawurlencode($fnR3));
        header('Content-Length: ' . filesize($tmpR3));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($tmpR3);
        @unlink($tmpR3);
        exit;
    }

    $E = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $F = '';
    foreach ($R3 as $f) {
        $style = 'top:' . $f[2] . '%;font-size:' . $f[4] . 'pt';
        if ($f[3] === 'c') $style .= ';left:' . $f[1] . '%;transform:translateX(-50%)';
        else $style .= ';right:' . round(100 - $f[1], 2) . '%';
        $F .= '<div class="f" style="' . $style . '">' . $E($f[0]) . '</div>';
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta http-equiv="Cache-Control" content="no-store"><title>نموذج ر3 — طلب تسجيل مستخدم/أجير جديد</title>'
       . '<style>@page{size:A4;margin:0}*{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
       . 'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif;background:#e9edf2}'
       . '.sheet{width:210mm;margin:0 auto;direction:ltr}'
       . '.page{position:relative;width:210mm;height:297mm;background:#fff;overflow:hidden}'
       . '.pbg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:0}'
       . '.f{position:absolute;z-index:1;color:#000;font-weight:bold;line-height:1.15;white-space:nowrap}'
       . '.bar{text-align:center;margin:10px 0}'
       . '@media print{.bar{display:none}body{background:#fff;margin:0}.sheet{width:210mm;height:297mm;overflow:hidden;margin:0}}'
       . '</style></head><body>'
       . '<div class="bar"><button onclick="window.print()" style="padding:11px 26px;font-size:16px;font-weight:bold;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ اطبع / احفظ PDF</button>'
       . '<div style="color:#475569;font-size:13px;margin-top:6px">اكبس الزرّ ثمّ اختر طابعتك أو «حفظ كـ PDF» — نموذج ر3 طبق الأصل الرسمي (Margins = None)</div></div>'
       . '<div class="sheet"><div class="page"><img class="pbg" src="' . BASE_URL . 'assets/templates/mof_r3.png" alt=""> ' . $F . '</div></div>'
       . '</body></html>';
    exit;
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
    // «رقم ضمان الموظف مرتب بنص المربعات» (p1 بطلبه 2026-08-20): الإحداثيات مقيسة من صورة
    // القالب نفسها (المربع الصغير 6.3–12.8% والعريض 12.8–32.9%، الوسط العمودي 33.2%)
    $F .= $fld('c', 6.3, 32.4, 6.5, $cells['R6']) . $fld('c', 12.8, 32.4, 20, $emp['nssf_number'] ?? ''); // سنة الولادة + رقم الضمان
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
           . '.sheet{width:210mm;margin:0 auto;direction:ltr}' /* direction:ltr حتى لا تفيض الصفحة يساراً بالـRTL وتنقصّ حافتها بالطباعة */
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
    // جلب الموظف ضمن نطاق المدرسة المسموح بها (أمان)
    $st = $db->prepare("SELECT * FROM employees WHERE id=? AND is_deleted=0 AND " . schoolScopeWhere('school_id'));
    $st->execute([$empId]);
    $emp = $st->fetch();
    if (!$emp) { http_response_code(404); die('الموظف غير موجود أو خارج صلاحيتك'); }
    // الجنس: من الرابط إن حُدِّد، وإلا تلقائياً من خانة gender بملف الموظف (2026-08-22)
    $sex = (($_GET['sex'] ?? (($emp['gender'] ?? '') ?: 'm')) === 'f') ? 'f' : 'm';

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
    // «عمل الأجير»: الأستاذ = «أستاذ» فقط، والموظف الإداري حسب وظيفته (بطلبه 2026-08-19)
    $fnAr = cnssOccupationAr($emp);
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
            'G9' => dedupeAddress($esch['address'] ?? ''), // «صحح العنوان» 2026-08-20: بلا مقاطع مكررة
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
            'A9' => 'وعنوانها الكامل:  ' . dedupeAddress($esch['address'] ?? ''), // «صحح العنوان» 2026-08-20
            'K11' => $emp['nssf_number'] ?? '', 'N11' => $bYY,
            'C12' => $sex === 'm' ? 'X' : '1', 'E12' => $sex === 'f' ? 'X' : '2',
            'B13' => $first, 'E13' => $last, 'K13' => $emp['nssf_number'] ?? '', 'N13' => $bYY,
            'B14' => $father, 'H14' => $mother,
            'C15' => $bD, 'D15' => $bM, 'E15' => $bY, 'H15' => $registry, 'L15' => $nat,
            'C16' => !$married ? 'X' : '1', 'E16' => $married ? 'X' : '2',
            'B17' => $hD, 'C17' => $hM, 'D17' => $hY, 'J17' => $hrs,
            'B18' => 'X',
            // «بدو يكون رقم المبلغ قبل التفقيط» (p1 بطلبه 2026-08-20): علامة RTL بأول الخانة
            // حتى ما يقلب العرضُ الرقمَ لآخر السطر — الرقم يظهر أولاً بعد «ان الراتب الحالي» ثم التفقيط
            'C19' => $fnAr, 'G19' => trim("\u{200F}" . $wageNum . '  ' . $wageWords),
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
            'C11' => dedupeAddress($esch['address'] ?? ''), // «صحح العنوان» 2026-08-20
            'N13' => $emp['nssf_number'] ?? '', 'P13' => $bYY,
            'C14' => $sex === 'm' ? 'X' : '1', 'F14' => $sex === 'f' ? 'X' : '2',
            'B15' => $first, 'I15' => $last,
            'B16' => $father, 'K16' => $mother,
            'D17' => $emp['birth_place'] ?? '', 'F17' => $bTs ? formatDate($emp['birth_date']) : '',
            'K17' => $registry, 'O17' => $nat,
            'D18' => !$married ? 'X' : '1', 'F18' => $married ? 'X' : '2',
            'C19' => $ldTs ? (int)date('j', $ldTs) : '', 'E19' => $ldTs ? (int)date('n', $ldTs) : '', 'F19' => $ldTs ? date('Y', $ldTs) : '',
            // السبب المختار X، والباقي بأرقامه المطبوعة (تُعاد كتابتها لأن خانات التعبئة
            // مفرّغة من صورة الخلفية — وكتابتها بالإكسل فوق نفس الرقم بلا أثر)
            $reasonCells[1] => '1', $reasonCells[2] => '2', $reasonCells[3] => '3',
            $reasonCells[4] => '4', $reasonCells[5] => '5', $reasonCells[6] => '6', $reasonCells[7] => '7',
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

    // mode=image يفرض النسخة المصوّرة (للفحص والمقارنة)؛ غير ذلك نجرّب قالب الإكسل أولاً (محلياً).
    if (($_GET['mode'] ?? '') !== 'image'
        && officialTemplateExport(__DIR__ . '/../assets/templates/' . $tplFile, $cells, $format, $tplName . '_' . $d . '-' . $mo . '-' . $yr)) {
        exit; // بُثَّ الملف (pdf أو xlsx) وخرج
    }
    // 🖼️ تعذّر توليد الـPDF (أونلاين بلا LibreOffice) → «الإكسل صح بس PDF غلط» (2026-08-19):
    // نفس شكل القالب الرسمي بالضبط: صورة القالب الفاضي خلفيةً + القيم نفسها ($cells) مركّبة
    // فوقها بإحداثيات معايَرة تلقائياً (assets/templates/<form>.pos.json من tools/calibrate)
    // — فيطلع زرّ الطباعة/PDF طبق الأصل متل الإكسل بلا أي أداة على الخادم.
    $posFile = __DIR__ . '/../assets/templates/' . $form . '.pos.json';
    $bgFile  = __DIR__ . '/../assets/templates/' . $form . '.png';
    if (is_file($posFile) && is_file($bgFile)) {
        $pos = json_decode((string)file_get_contents($posFile), true) ?: [];
        $fsBase = (float)($pos['fs'] ?? 11);
        $E = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $F = '';
        foreach ($cells as $ref => $val) {
            $val = trim((string)$val);
            if ($val === '' || !isset($pos['cells'][$ref])) continue;
            $p = $pos['cells'][$ref];
            $len = function_exists('mb_strlen') ? mb_strlen($val, 'UTF-8') : strlen($val);
            // القيم الطويلة (الراتب حروفاً/سطر التوقيع) تصغر تدريجياً حتى لا تفيض عن الورقة
            $fs = $fsBase * ($len > 70 ? 0.62 : ($len > 50 ? 0.75 : 1));
            $style = 'top:' . $p['yt'] . '%;font-size:' . round($fs, 1) . 'pt';
            if (($p['align'] ?? '') === 'center') {
                $style .= ';left:' . $p['xc'] . '%;transform:translateX(-50%)';
            } else { // محاذاة يمين (النص العربي/العام) — المرساة عند يمين الخانة كما في القالب
                $style .= ';right:' . round(100 - $p['xr'], 2) . '%';
            }
            $F .= '<div class="f" style="' . $style . '">' . $E($val) . '</div>';
        }
        $titleAr = ['cnss_hire_new' => 'تصريح باستخدام أجير', 'cnss_hire_reg' => 'إعلام عن استخدام أجير', 'cnss_leave' => 'إعلام عن ترك أجير'][$form];
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta http-equiv="Cache-Control" content="no-store"><title>' . $E($titleAr) . '</title>'
           . '<style>@page{size:A4;margin:8mm}*{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
           . 'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif;background:#e9edf2}'
           . '.sheet{width:210mm;margin:0 auto;direction:ltr}' /* direction:ltr حتى لا تفيض الصفحة يساراً بالـRTL وتنقصّ حافتها بالطباعة */
           . '.page{position:relative;width:210mm;height:297mm;background:#fff;overflow:hidden;transform-origin:top left}'
           . '.pbg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:0}'
           . '.f{position:absolute;z-index:1;color:#000;font-weight:bold;line-height:1.15;white-space:nowrap}'
           . '.bar{text-align:center;margin:10px 0}'
           . '@media print{.bar{display:none}body{background:#fff;margin:0}.sheet{width:194mm;height:276mm;overflow:hidden;margin:0}.page{transform:scale(0.923)}}'
           . '</style></head><body>'
           . '<div class="bar"><button onclick="window.print()" style="padding:11px 26px;font-size:16px;font-weight:bold;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ اطبع / احفظ PDF</button>'
           . '<div style="color:#475569;font-size:13px;margin-top:6px">اكبس الزرّ ثمّ اختر طابعتك أو «حفظ كـ PDF» — النموذج طبق الأصل الرسمي</div></div>'
           . '<div class="sheet"><div class="page"><img class="pbg" src="' . BASE_URL . 'assets/templates/' . $form . '.png" alt=""> ' . $F . '</div></div>'
           . '</body></html>';
        exit;
    }
    // احتياط أخير (ملفات الصورة/الإحداثيات غير موجودة) → النسخة المبنية بالبرنامج بنفس المعلومات
    $_SESSION['flash_info'] = 'النموذج الرسمي طبق الأصل (PDF) يتولّد على كمبيوتر المدرسة. هون فتحنالك النسخة المبنية بالبرنامج بنفس المعلومات — اطبعها من زرّ الطباعة، أو نزّل Excel الرسمي. / Le PDF officiel n\'est disponible que sur l\'ordinateur de l\'école — version imprimable affichée.';
    header('Location: ' . BASE_URL . 'pages/official_forms.php?form=' . $fallbackForm . '&employee_id=' . $empId);
    exit;
}

http_response_code(400);
die('نموذج غير مدعوم للتصدير الرسمي بعد: ' . htmlspecialchars($form));
