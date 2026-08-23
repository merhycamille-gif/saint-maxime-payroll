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

    // الجنس: من الرابط إن حُدِّد وإلا من خانة gender بملفه — وإن كان مجهولاً فلا علامة ×
    // إطلاقاً (تعليم «ذكر» افتراضياً كان يغلّط بنماذج الإناث — «إلسي/تيا/اسمهان» 2026-08-22)
    $sexQ = (string)($_GET['sex'] ?? ($emp['gender'] ?? ''));
    $sex  = in_array($sexQ, ['m', 'f'], true) ? $sexQ : '';
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
    // 🔠 «قلنا بدنا حجم الخط 12» (2026-08-22): التعبئة 12pt (والحقول الطويلة 10pt كي لا
    // تفيض عن سطورها) — الإحداثيات معايَرة على الأحجام القديمة فيُعوَّض فرق صعود الخط
    // (0.907×Δpt×0.1188٪) حتى يبقى كل نص قاعداً على سطره بعد التكبير.
    $fsMap = ['9.5' => 12.0, '10' => 12.0, '11' => 12.0, '8.5' => 10.0, '7' => 8.0];
    $put = function ($t, $x, $y, $a = 'r', $fs = 9.5) use (&$R3, $fsMap) {
        $t = trim((string)$t);
        if ($t === '' || $t === '0/0/0') return;
        $fs2 = $fsMap[rtrim(rtrim(number_format($fs, 1, '.', ''), '0'), '.')] ?? $fs;
        $R3[] = [$t, $x, round($y - 0.907 * ($fs2 - $fs) * 0.1188, 2), $a, $fs2];
    };
    $digits = function ($num, $centers, $y) use (&$R3) { // رقم بخانات: يتعبّأ من يمين الصف (آخر الخانات)
        $num = preg_replace('/\D/', '', (string)$num);
        $off = max(0, count($centers) - strlen($num));
        $y2 = round($y - 0.907 * 2.0 * 0.1188, 2); // 10 → 12pt
        for ($i = 0; $i < strlen($num) && ($off + $i) < count($centers); $i++) $R3[] = [$num[$i], $centers[$off + $i], $y2, 'c', 12];
    };
    $X = function ($x, $y) use (&$R3) { $R3[] = ['×', $x, round($y - 0.907 * 1.0 * 0.1188, 2), 'c', 12]; };

    // 🗺️ الإحداثيات مقيسة على mof_r3.png نفسها: مواقع السطور والخانات مكشوفة آلياً من
    // الصورة (كشف صفوف/أعمدة السواد) ثم تثبيت بصري مكبّراً حقلاً حقلاً — «مش جايين
    // المعلومات بمحلهون على السطر» (2026-08-22): النص يقعد على سطره تماماً (top = سطر −
    // صعود الخط) والأرقام بوسط خاناتها الحقيقية (٩-١٠ خانات بخطوة ~2.08٪ لا 8×2.5).
    // المؤسسة
    $put($esch['name_ar'] ?? '', 76.5, 9.05, 'r', 8.5);
    $digits($esch['finance_number'] ?? '', [56.06, 58.16, 60.24, 62.32, 64.39, 66.46, 68.54, 70.61, 72.71], 12.48); // 9 خانات فعلية — «74.91» كانت حافة اللابل لا خانة (p1 13:15)
    // هل لديه رقم مالي شخصي؟ + الرقم
    if ($mofNum !== '') { $X(47.85, 15.98); $digits($mofNum, [2.24, 4.33, 6.42, 8.48, 10.56, 12.64, 14.72, 16.81, 18.88], 16.13); }
    else { $X(37.25, 15.98); }
    // تعريف المستخدم
    $put($emp['first_name_ar'] ?? '', 84.5, 18.97);
    $put($emp['last_name_ar'] ?? '', 38.5, 19.15);
    $put($emp['father_name_ar'] ?? '', 84.5, 21.23);
    $put($motherAr, 25.2, 21.6);
    if ($sex !== '') $X($sex === 'f' ? 74.5 : 83.2, 23.68); // مجهول → المربعان فارغان
    $natR3 = in_array(mb_strtolower(trim((string)($emp['nationality'] ?? ''))), ['lebanese', 'lebanaise', 'libanaise', 'لبنانية', 'لبناني'], true) ? 'لبنانية' : (string)($emp['nationality'] ?? '');
    $put($natR3, 40.5, 23.8);
    $put($emp['birth_place'] ?? '', 79.5, 26.6);
    $put($bD, 34.3, 26.25, 'c'); $put($bM, 27.3, 26.25, 'c'); $put($bY, 18.8, 26.25, 'c');
    $put($regNo3, 84.0, 28.63);
    $put($regPl3, 56.5, 28.82);
    $X(['single' => 78.4, 'married' => 70.2, 'widow' => 65.0, 'divorced' => 54.75][$marKey], 31.58);
    $put((string)(int)($emp['number_of_children'] ?? 0), 21.5, 31.2);
    $put($hD, 76.0, 34.2, 'c'); $put($hM, 66.0, 34.2, 'c'); $put($hY, 57.5, 34.2, 'c');
    $put($emp['nssf_number'] ?? '', 17.7, 33.3); // مزاح قليلاً عن كلمة «الإجتماعي»
    $X(['m' => 22.5, 'd' => 15.2, 'h' => 8.1][$wage], 35.83);
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
    if ($isMar) $X((int)($emp['spouse_works'] ?? 0) ? 67.75 : 59.9, 54.28);
    $digits($emp['spouse_mof_number'] ?? '', [61.06, 63.16, 65.24, 67.31, 69.39, 71.46, 73.54, 75.61, 77.69], 57.62);
    if (!empty($emp['spouse_employer_public'])) {
        $put($emp['spouse_employer_name'] ?? '', 66.0, 66.0, 'r', 8.5); // ب — الإدارات العامة: إسم الإدارة
    } else {
        $put($emp['spouse_employer_name'] ?? '', 59.0, 62.5, 'r', 8.5); // أ — القطاع الخاص
        $digits($emp['spouse_employer_mof'] ?? '', [3.03, 5.12, 7.2, 9.27, 11.34, 13.42, 15.5, 17.58, 19.67], 61.5);
    }
    // عنوان السكن
    $put($emp['gouvernorat'] ?? '', 87.5, 68.97);
    $put($emp['district'] ?? '', 69.5, 68.97);
    $put($emp['ville'] ?? '', 46.0, 68.97);
    $put($emp['quartier'] ?? '', 24.2, 68.97); // مزاح عن كلمة «الحي»
    $put($emp['rue'] ?? '', 87.5, 70.31);
    $put($emp['immeuble'] ?? '', 88.0, 71.9);
    $put($emp['etage'] ?? '', 59.0, 71.9);
    $put($emp['phone1'] ?? '', 39.0, 72.45); // سطره أوطى من صف المبنى + مزاح عن كلمة «هاتف»
    $put($emp['phone2'] ?? '', 14.5, 72.36);
    $put($emp['email'] ?? '', 66.0, 75.1, 'r', 8.5);
    // الإفادة (يوقّعها صاحب العمل)
    $put($esch['name_ar'] ?? '', 76.0, 84.05, 'r', 7);
    $put(date('j'), 64.5, 91.0, 'c'); $put(date('n'), 59.0, 91.0, 'c'); $put(date('Y'), 52.0, 91.0, 'c');
    // 🔴 قسم «خاص بالإدارة» يبقى فاضياً («لازم يبقى فاضي ما يتعبى هيدا الدولة بتعبي» —
    // p1 ‏2026-08-23، يلغي طلب 2026-08-21): لا رقم مالي بخاناته ولا تاريخ تسجيل — الوزارة تعبّيه.

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
                        // 🔴 «الارقام والكلمات مش بمحلها» على شاشة الإكسل (2026-08-22): المراسي
                        // المطلقة تكبَّر بنسبة مختلفة عن الصورة مع تكبير شاشة ويندوز (DPI) فتفلّ
                        // النصوص عن السطور. الحل: الصورة والنصوص كلاهما مربوطان بشبكة الخلايا
                        // (twoCellAnchor على شبكة 47 عموداً ×17px و70 صفاً ×16px = A4) — أي تكبير
                        // يطبَّق على الشبكة يطالهما سوية فلا ينفكّان أبداً (شاشة/طباعة/PDF).
                        $pxx = $px / 9525.0; $pyy = $py / 9525.0;          // EMU → بكسل 96dpi
                        $cxx = $cx / 9525.0; $cyy = $cy / 9525.0;
                        $anch = function ($xp, $yp) {                       // بكسل → (عمود/صف + إزاحة)
                            $c = min(46, (int)floor($xp / 17)); $r = min(69, (int)floor($yp / 16));
                            return [$c, (int)round(($xp - $c * 17) * 9525), $r, (int)round(($yp - $r * 16) * 9525)];
                        };
                        [$c1, $o1, $r1, $q1] = $anch($pxx, $pyy);
                        [$c2, $o2, $r2, $q2] = $anch($pxx + $cxx, $pyy + $cyy);
                        $shapes .= '<xdr:twoCellAnchor editAs="twoCell"><xdr:from><xdr:col>' . $c1 . '</xdr:col><xdr:colOff>' . $o1 . '</xdr:colOff><xdr:row>' . $r1 . '</xdr:row><xdr:rowOff>' . $q1 . '</xdr:rowOff></xdr:from>'
                            . '<xdr:to><xdr:col>' . $c2 . '</xdr:col><xdr:colOff>' . $o2 . '</xdr:colOff><xdr:row>' . $r2 . '</xdr:row><xdr:rowOff>' . $q2 . '</xdr:rowOff></xdr:to>'
                            . '<xdr:sp macro="" textlink=""><xdr:nvSpPr><xdr:cNvPr id="' . $sid . '" name="fld' . $sid . '"/><xdr:cNvSpPr txBox="1"/></xdr:nvSpPr>'
                            . '<xdr:spPr><a:xfrm><a:off x="' . $px . '" y="' . $py . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></xdr:spPr>'
                            . '<xdr:txBody><a:bodyPr wrap="none" lIns="0" tIns="0" rIns="0" bIns="0" anchor="t"/><a:lstStyle/>'
                            . '<a:p><a:pPr algn="' . $algn . '"' . $rtl . '/><a:r><a:rPr lang="' . $rlang . '" sz="' . (int)round($fs * 100) . '" b="1">'
                            . '<a:latin typeface="Arial"/><a:cs typeface="Arial"/></a:rPr><a:t>' . $txt . '</a:t></a:r></a:p>'
                            . '</xdr:txBody></xdr:sp><xdr:clientData/></xdr:twoCellAnchor>';
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

/* ============================================================================
 * 🏛️ نماذج وزارة المالية ر5/ر6/ر10 طبق الأصل («بعتلك اكسل بدي ياهون طبق الاصل
 * r3,r6,r5,r10» — 2026-08-23): القوالب هي ملفات المستخدم نفسها (مفرَّغة من بيانات
 * العيّنة) assets/templates/mof_r5|r6|r10.xlsx + صورة 300dpi للطباعة + إحداثيات كل
 * خانة معايَرة آلياً بماركرات من PDF إكسل نفسه (mof_rX.pos.json — 187 خانة).
 *   - زر الطباعة: صورة القالب + القيم فوقها بمواقعها الحقيقية (يعمل محلياً وأونلاين).
 *   - زر «Excel رسمي»: تعبئة خانات القالب نفسه (تنسيقه ونمط أرقامه كما هو).
 * الأرقام من monthly_salaries حصراً (قاعدة «مصدر واحد») بمنطق ر10 الفصلي المعتمد:
 * السنة الميلادية = ٤ فصول تُجمع، فالسنوي «يركب» على مجموع الفصول بالمليم.
 * ========================================================================== */

/** فلترا الفئة/الضريبة من الرابط (نفس فلتري official_forms) */
function mofEmpFilterSql($db) {
    $t = in_array($_GET['emp_type'] ?? '', ['enseignant_titulaire', 'enseignant_contractuel', 'employe'], true) ? $_GET['emp_type'] : '';
    $x = in_array($_GET['tax_sub'] ?? '', ['1', '0'], true) ? $_GET['tax_sub'] : '';
    return ($t ? " AND e.employee_type = " . $db->quote($t) : '')
         . ($x !== '' ? " AND e.tax_subject = " . (int)$x : '');
}

/** مجاميع فصل ميلادي واحد — المنطق المعتمد نفسه من نموذج ر10 (2026-08-06) */
function mofQuarterAgg($db, $rq, $rqy, $empFilter) {
    $rqMonthsMap = [1 => [1, 2, 3], 2 => [4, 5, 6], 3 => [7, 8, 9], 4 => [10, 11, 12]];
    $rqM = $rqMonthsMap[$rq];
    $rqIn = implode(',', $rqM);
    $rqSy = ($rq === 4) ? ($rqy . '-' . ($rqy + 1)) : (($rqy - 1) . '-' . $rqy);
    [$yf, $yp] = yearEmploymentFilter($rqSy, 'e.');
    $q = $db->prepare("SELECT
            SUM(ms.base_plus_echelon_lbp+ms.extra_lbp+ms.prime_fixe_lbp+ms.aide_complementaire_lbp) gross,
            SUM(ms.transport_lbp) transport,
            SUM(ms.caisse_amount_lbp) caisse, SUM(ms.eoc_grade_lbp) eoc,
            SUM(ms.taxable_base_lbp) taxable, SUM(ms.income_tax_lbp) tax
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND e.tax_subject=1" . $yf . $empFilter . " AND ms.year=? AND ms.month IN ($rqIn)
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id'));
    $q->execute(array_merge($yp, [$rqy]));
    $g = $q->fetch() ?: [];
    // ١٧٠ التنزيل العائلي للفترة (المصدر الوحيد familyDeductionAnnual — تجزئة بمدة العمل)
    $qDed = $db->prepare("SELECT e.id, e.social_status, e.spouse_works, COALESCE(e.apply_family_deduction,1) afd, COALESCE(e.grant_spouse_addition,0) gsa, COALESCE(e.grant_children_addition,0) gca, COUNT(DISTINCT ms.month) mcnt, SUM(ms.taxable_base_lbp) tb
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND e.tax_subject=1" . $yf . $empFilter . " AND ms.year=? AND ms.month IN ($rqIn)
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id') . "
        GROUP BY e.id, e.social_status, e.spouse_works, afd");
    $qDed->execute(array_merge($yp, [$rqy]));
    $dedAsOf = sprintf('%04d-%02d-01', $rqy, $rqM[0]);
    $exempt = 0; $ids = [];
    foreach ($qDed->fetchAll() as $de) {
        $ids[] = (int)$de['id'];
        $fda = familyDeductionAnnual($de['social_status'], $de['spouse_works'], $de['afd'], $dedAsOf, $de['gsa'] ?? 0, $de['gca'] ?? 0, (int)$de['id']);
        $exempt += (int)min($fda / 12 * (int)$de['mcnt'], (float)$de['tb']);
    }
    $gross = (int)($g['gross'] ?? 0); $trans = (int)($g['transport'] ?? 0);
    $other = (int)($g['caisse'] ?? 0) + (int)($g['eoc'] ?? 0);
    $net   = $gross - $other; // = (gross+trans) − trans − other = مجموع الأساس الخاضع المخزَّن
    return [
        'ids' => $ids, 'gross' => $gross, 'trans' => $trans, 'other' => $other,
        'net' => $net, 'exempt' => $exempt, 'taxable' => max(0, $net - $exempt), 'tax' => (int)($g['tax'] ?? 0),
    ];
}

/** صفحة العرض/الطباعة طبق الأصل: صورة القالب + القيم بإحداثياتها المعايَرة */
function mofOverlayServe($tplKey, $titleAr, array $vals, array $widths = []) {
    $posFile = __DIR__ . '/../assets/templates/' . $tplKey . '.pos.json';
    $pos = is_file($posFile) ? (json_decode((string)file_get_contents($posFile), true) ?: []) : [];
    $cells = $pos['cells'] ?? [];
    $E = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $F = '';
    foreach ($vals as $ref => $v) {
        $v = trim((string)$v);
        if ($v === '' || !isset($cells[$ref])) continue;
        $p = $cells[$ref];
        $fs = (float)$p['fs'];
        // تصغير تلقائي للنص الطويل (متل shrinkToFit): الحدّ = المساحة المقيسة حتى أقرب
        // تسمية مطبوعة (w بpos.json — «القيم فوق التسميات» p1 ‏2026-08-23) أو حدّ صريح
        $maxw = $widths[$ref] ?? ($p['w'] ?? null);
        if ($maxw !== null) {
            $len = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
            $estPct = $len * $fs * 0.52 / 5.9532; // عرض تقريبي ٪ من عرض A4
            if ($estPct > $maxw) $fs = max(6.0, round($fs * $maxw / $estPct, 1));
        }
        $style = 'top:' . $p['y'] . '%;font-size:' . $fs . 'pt';
        if ($p['a'] === 'c') $style .= ';left:' . $p['x'] . '%;transform:translateX(-50%)';
        elseif ($p['a'] === 'l') $style .= ';left:' . $p['x'] . '%';
        else $style .= ';right:' . round(100 - (float)$p['x'], 3) . '%';
        $F .= '<div class="f" style="' . $style . '">' . $E($v) . '</div>';
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta http-equiv="Cache-Control" content="no-store"><title>' . $E($titleAr) . '</title>'
       . '<style>@page{size:A4;margin:0}*{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'
       . 'body{font-family:Calibri,"Segoe UI",Tahoma,Arial,sans-serif;background:#e9edf2}'
       . '.sheet{width:210mm;margin:0 auto;direction:ltr}'
       . '.page{position:relative;width:210mm;height:297mm;background:#fff;overflow:hidden}'
       . '.pbg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:0}'
       . '.f{position:absolute;z-index:1;color:#000;line-height:1.18;white-space:nowrap}'
       . '.bar{text-align:center;margin:10px 0}'
       . '@media print{.bar{display:none}body{background:#fff;margin:0}.sheet{width:210mm;height:297mm;overflow:hidden;margin:0}}'
       . '</style></head><body>'
       . '<div class="bar"><button onclick="window.print()" style="padding:11px 26px;font-size:16px;font-weight:bold;background:#dc2626;color:#fff;border:0;border-radius:6px;cursor:pointer">🖨️ اطبع / احفظ PDF</button>'
       . '<div style="color:#475569;font-size:13px;margin-top:6px">اكبس الزرّ ثمّ اختر طابعتك أو «حفظ كـ PDF» — ' . $E($titleAr) . ' طبق الأصل الرسمي (Margins = None)</div></div>'
       . '<div class="sheet"><div class="page"><img class="pbg" src="' . BASE_URL . 'assets/templates/' . $tplKey . '.png" alt=""> ' . $F . '</div></div>'
       . '</body></html>';
    exit;
}

/** بثّ قالب إكسل معبّأً (طبق الأصل) — تعبئة PHP خالصة (تعديل الخانات فقط): القالب يبقى
 *  بايت-بايت كملف المستخدم (مسار python/openpyxl يعيد كتابة الملف فيرمي أجزاءً منه).
 *  $checkbox: رقم مربع «الوضع العائلي» (1=أعزب 2=متزوج 3=أرمل 4=مطلق) يُعلَّم بالقالب
 *  (ctrlPropN + شكل VML المقابل s102(4+N)) — القالب محفوظ كله بلا علامات. */
function mofXlsxServe($tplKey, array $cells, $fname, $checkbox = 0) {
    $tpl = __DIR__ . '/../assets/templates/' . $tplKey . '.xlsx';
    $tmp = tempnam(sys_get_temp_dir(), 'mof');
    if (!is_file($tpl) || !phpFillXlsxTemplate($tpl, $cells, $tmp)) {
        @unlink($tmp);
        http_response_code(500);
        die('تعذّر توليد ملف الإكسل — القالب ' . htmlspecialchars($tplKey, ENT_QUOTES, 'UTF-8') . '.xlsx غير متوفر');
    }
    if ($checkbox >= 1 && $checkbox <= 4 && class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($tmp) === true) {
            $cpName = 'xl/ctrlProps/ctrlProp' . (int)$checkbox . '.xml';
            $cp = $z->getFromName($cpName);
            if ($cp !== false && strpos($cp, 'checked=') === false) {
                $z->addFromString($cpName, str_replace('objectType="CheckBox"', 'objectType="CheckBox" checked="Checked"', $cp));
            }
            $vml = $z->getFromName('xl/drawings/vmlDrawing1.vml');
            $shapeId = '_x0000_s' . (1024 + (int)$checkbox);
            if ($vml !== false && preg_match('/<v:shape id="' . $shapeId . '".*?<\/v:shape>/s', $vml, $mSh)
                && strpos($mSh[0], '<x:Checked>') === false) {
                $sh2 = preg_replace('/(<x:ClientData[^>]*>)/', '$1<x:Checked>1</x:Checked>', $mSh[0], 1);
                $z->addFromString('xl/drawings/vmlDrawing1.vml', str_replace($mSh[0], $sh2, $vml));
            }
            $z->close();
        }
    }
    $fn = $fname . '.xlsx';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fn) . '"; filename*=UTF-8\'\'' . rawurlencode($fn));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if (in_array($form, ['mof_r5', 'mof_r10', 'mof_r6'], true)) {
    ensureMofProfile20260823();
    $fmt2 = function ($v) { if ($v === '' || $v === null) return ''; return (float)$v == 0.0 ? '0' : number_format((float)$v, 2, '.', ','); };
    $fmt0 = function ($v) { if ($v === '' || $v === null) return ''; return (float)$v == 0.0 ? '0' : number_format((float)$v, 0, '.', ','); };
    $serial = function ($dateStr) { return (int)round((strtotime($dateStr) - strtotime('1899-12-30')) / 86400); };
    $empFilter = mofEmpFilterSql($db);
    $fy = (int)($_GET['fy'] ?? 0);
    if ($fy < 2000 || $fy > 2100) $fy = (int)date('Y') - 1;
    $isXlsx = (($_GET['format'] ?? '') === 'xlsx');

    if ($form === 'mof_r6') {
        // ===== ر6: كشف سنوي إفرادي لموظف واحد عن سنة ميلادية =====
        $empId = (int)($_GET['emp'] ?? 0);
        $st = $db->prepare("SELECT * FROM employees WHERE id=? AND is_deleted=0 AND " . schoolScopeWhere('school_id'));
        $st->execute([$empId]);
        $emp = $st->fetch();
        if (!$emp) { http_response_code(404); die('الموظف غير موجود أو خارج صلاحيتك'); }
        $ss = $db->prepare("SELECT * FROM schools WHERE id=?");
        $ss->execute([(int)$emp['school_id']]);
        $esch = $ss->fetch() ?: [];
        $prof = mofProfile($esch);
        // مجاميع الموظف للسنة الميلادية (نفس أعمدة المحرّك — مصدر واحد)
        $ag = $db->prepare("SELECT SUM(base_plus_echelon_lbp) base,
                SUM(extra_lbp+prime_fixe_lbp) extraw, SUM(aide_complementaire_lbp) aide,
                SUM(family_allowance_lbp) family, SUM(transport_lbp) transport,
                SUM(caisse_amount_lbp+eoc_grade_lbp) other,
                SUM(taxable_base_lbp) taxable, SUM(income_tax_lbp) tax,
                COUNT(DISTINCT month) mcnt, MIN(month) m1, MAX(month) m2
            FROM monthly_salaries WHERE employee_id=? AND year=?
              AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0)");
        $ag->execute([$empId, $fy]);
        $a = $ag->fetch() ?: [];
        $base = (int)($a['base'] ?? 0); $extraW = (int)($a['extraw'] ?? 0); $aide = (int)($a['aide'] ?? 0);
        $family = (int)($a['family'] ?? 0); $trans = (int)($a['transport'] ?? 0); $other = (int)($a['other'] ?? 0);
        $tbSum = (int)($a['taxable'] ?? 0); $tax = (int)($a['tax'] ?? 0); $mcnt = (int)($a['mcnt'] ?? 0);
        $isMar = strpos((string)($emp['social_status'] ?? ''), 'marie') === 0;
        // ٣٣٠ التنزيل العائلي: المصدر الوحيد + تجزئة بأشهر العمل، محدود بأساسه الخاضع
        $fda = familyDeductionAnnual($emp['social_status'] ?? '', $emp['spouse_works'] ?? 0,
            $emp['apply_family_deduction'] ?? 1, $fy . '-01-01', $emp['grant_spouse_addition'] ?? 0, $emp['grant_children_addition'] ?? 0, (int)$emp['id']);
        $fd = $mcnt ? (int)min($fda / 12 * min(12, $mcnt), (float)$tbSum) : 0;
        $tot1 = $base + $extraW + $aide + $family + $trans;  // إجمالي (١)
        $tot2 = $family + $trans;                            // غير خاضع (٢)
        $tot3 = $base + $extraW + $aide;                     // خاضع (٣)
        $net350 = max(0, $tbSum - $fd);
        // عدّاد «المدخل x من y»: موظفو المؤسسة بنفس السنة (اتحاد الفصول الأربعة)
        $allIds = [];
        for ($q = 1; $q <= 4; $q++) { $qa = mofQuarterAgg($db, $q, $fy, ''); $allIds = array_merge($allIds, $qa['ids']); }
        $allIds = array_values(array_unique($allIds));
        $cnt = count($allIds);
        $seq = '';
        if ($allIds) {
            $in = implode(',', array_map('intval', $allIds));
            $ord = $db->query("SELECT id FROM employees WHERE id IN ($in)
                ORDER BY COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)")->fetchAll(PDO::FETCH_COLUMN);
            $ix = array_search($empId, array_map('intval', $ord), true);
            if ($ix !== false) $seq = $ix + 1;
        }
        // الوضع العائلي: مربعات القالب نفسها (أعزب/متزوج/أرمل/مطلق) لا نصاً
        $marMap = ['celibataire' => 1, 'marie' => 2, 'veuf' => 3, 'divorce' => 4];
        $marBox = 1;
        foreach ($marMap as $k => $vv) { if (strpos((string)($emp['social_status'] ?? ''), $k) === 0) { $marBox = $vv; break; } }
        $mofNum = trim((string)($emp['finance_ministry_number'] ?? ''));
        $from = $mcnt ? sprintf('%04d-%02d-01', $fy, (int)$a['m1']) : '';
        $to   = $mcnt ? date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $fy, (int)$a['m2']))) : '';
        $benef = (int)($emp['number_of_children'] ?? 0) + (($isMar && !(int)($emp['spouse_works'] ?? 0)) ? 1 : 0);
        $common = [
            'D5' => $esch['name_ar'] ?? '', 'C6' => $prof['trade_name'], 'D7' => preg_replace('/\D/', '', (string)($esch['finance_number'] ?? '')),
            'K5' => $fy, 'K6' => $cnt ?: '', 'K8' => $seq, 'L8' => $cnt ? ($cnt . '/') : '',
            'D9' => trim((string)($emp['first_name_ar'] ?: $emp['first_name_fr'])),
            'G9' => trim((string)($emp['father_name_ar'] ?? '')),
            'J9' => trim((string)($emp['last_name_ar'] ?: $emp['last_name_fr'])),
            'D10' => $mofNum, 'G10' => cnssOccupationAr($emp),
            'I10' => ' X شهري', 'J10' => 'يومي', 'K10' => 'بالساعة',
            'H11' => (string)(int)($emp['number_of_children'] ?? 0), 'K12' => (string)$benef,
            'D17' => $emp['gouvernorat'] ?? '', 'G17' => $emp['district'] ?? '', 'J17' => $emp['ville'] ?? '',
            'D18' => $emp['quartier'] ?? '', 'G18' => $emp['rue'] ?? '',
            'D19' => $emp['immeuble'] ?? '', 'G19' => $emp['etage'] ?? '',
            'I19' => $emp['phone1'] ?? '', 'K19' => $emp['phone2'] ?? '', 'D21' => $emp['email'] ?? '',
        ];
        $money = [
            'F24' => $base, 'J24' => $base,
            'F26' => $extraW ?: '', 'J26' => $extraW ?: '',
            'F27' => $isMar ? $family : 0, 'H27' => $isMar ? $family : 0,
            'F28' => $isMar ? 0 : $family, 'H28' => $isMar ? 0 : $family,
            'F29' => $trans, 'H29' => $trans,
            'F41' => $aide ?: '', 'J41' => $aide ?: '',
            'F42' => $tot1, 'H42' => $tot2, 'J42' => $tot3,
            'F44' => $fd ?: '', 'F45' => $other ?: '',
            'I48' => $net350, 'I49' => $tax,
        ];
        $nm = trim(($emp['first_name_ar'] ?: $emp['first_name_fr']) . '_' . ($emp['last_name_ar'] ?: $emp['last_name_fr']));
        if ($isXlsx) {
            $cells = $common;
            $cells['C13'] = $from ? $serial($from) : '';
            $cells['E13'] = $to ? $serial($to) : '';
            foreach ($money as $k => $v) $cells[$k] = ($v === '' ? '' : (int)$v);
            $cells = array_filter($cells, function ($v) { return $v !== '' && $v !== null; });
            mofXlsxServe('mof_r6', $cells, 'R6_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $nm) . '_' . $fy, $marBox);
        }
        $vals = $common;
        $vals[['CB_single', 'CB_married', 'CB_widow', 'CB_divorced'][$marBox - 1]] = '×';
        $vals['C13'] = $from ? date('d/m/Y', strtotime($from)) : '';
        $vals['E13'] = $to ? date('d/m/Y', strtotime($to)) : '';
        foreach ($money as $k => $v) $vals[$k] = ($v === '' ? '' : $fmt2($v));
        mofOverlayServe('mof_r6', 'نموذج ر6 — كشف سنوي إفرادي ' . $fy, $vals, ['D5' => 24, 'G10' => 7]);
    }

    // ===== ر5 (سنوي) / ر10 (فصلي) — مستوى المؤسسة =====
    // مدرسة المالية = المدرسة المختارة نفسها برقمها المالي (لا قاعدة «صاحب العمل بالضمان»)
    $s0 = currentSchool();
    $ss = $db->prepare("SELECT * FROM schools WHERE id=?");
    $ss->execute([(int)($s0['id'] ?? 0)]);
    $sch = $ss->fetch() ?: $s0;
    $prof = mofProfile($sch);
    $finNum = preg_replace('/\D/', '', (string)($sch['finance_number'] ?? ''));

    if ($form === 'mof_r5') {
        // السنة الميلادية = مجموع فصولها الأربعة (نفس محرّك ر10 — «الأرقام تركب»)
        $sum = ['gross' => 0, 'trans' => 0, 'other' => 0, 'net' => 0, 'exempt' => 0, 'taxable' => 0, 'tax' => 0];
        $ids = [];
        for ($q = 1; $q <= 4; $q++) {
            $qa = mofQuarterAgg($db, $q, $fy, $empFilter);
            foreach ($sum as $k => $v) $sum[$k] += $qa[$k];
            $ids = array_merge($ids, $qa['ids']);
        }
        $cnt = count(array_unique($ids));
        $paid = $sum['gross'] + $sum['trans'];
        $common = [
            'D6' => $sch['name_ar'] ?? '', 'C7' => $finNum, 'C10' => $prof['trade_name'],
            'J6' => '31/12/' . $fy, 'J8' => '31/12/' . $fy,
            'C12' => $prof['gov'], 'H12' => $prof['gov'], 'C13' => $prof['caza'], 'H13' => $prof['caza'],
            'C14' => $prof['town'], 'H14' => $prof['town'], 'F14' => $prof['quarter'], 'J14' => $prof['quarter'],
            'C15' => $prof['street'], 'H15' => $prof['street'], 'F15' => $prof['cadastral'], 'J15' => $prof['cadastral'],
            'C16' => $prof['lot'], 'H16' => $prof['lot'], 'F16' => $prof['building'], 'J16' => $prof['building'],
            'C17' => $prof['floor'], 'H17' => $prof['floor'], 'F17' => $sch['phone'] ?? '', 'J17' => $sch['phone'] ?? '',
            'C18' => $prof['fax'], 'H18' => $prof['fax'],
            'D19' => $prof['pob'], 'I19' => $prof['pob'], 'F19' => $prof['region'], 'K19' => $prof['region'],
            'E20' => $prof['email'], 'K20' => $prof['email'],
            'C22' => $prof['contact_name'], 'H22' => $prof['preparer_name'],
            'C23' => $prof['contact_reg'], 'H23' => $prof['preparer_reg'],
            'C24' => $prof['contact_phone'], 'E24' => $prof['contact_fax'],
            'H24' => $prof['preparer_phone'], 'J24' => $prof['preparer_fax'],
            'F27' => $cnt ?: '',
            'C53' => $prof['signer_name'], 'C54' => date('j'), 'D54' => date('n'), 'E54' => date('Y'),
            'I54' => $prof['signer_title'],
        ];
        $money = [
            'I29' => $sum['gross'], 'I30' => $sum['trans'] ?: '', 'I31' => $paid,
            'I32' => $sum['trans'] ?: '', 'I34' => $sum['other'] ?: '', 'I35' => $sum['net'],
            'I36' => $sum['exempt'] ?: '', 'I37' => $sum['taxable'], 'I38' => $sum['tax'],
            'J43' => $sum['taxable'], 'J44' => $sum['tax'], 'J45' => 0, 'J46' => $sum['tax'],
            'J49' => $sum['tax'], 'C49' => 0,
        ];
        if ($isXlsx) {
            $cells = array_filter($common, function ($v) { return $v !== '' && $v !== null; });
            $cells['H6'] = $serial($fy . '-01-01'); $cells['H8'] = $serial($fy . '-01-01');
            foreach ($money as $k => $v) { if ($v !== '') $cells[$k] = (int)$v; }
            mofXlsxServe('mof_r5', $cells, 'R5_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string)($sch['name_ar'] ?? 'school')) . '_' . $fy);
        }
        $vals = $common;
        $vals['H6'] = '01/01/' . $fy; $vals['H8'] = '01/01/' . $fy;
        foreach ($money as $k => $v) $vals[$k] = ($v === '' ? '' : $fmt2($v));
        mofOverlayServe('mof_r5', 'نموذج ر5 — تصريح سنوي عن ضريبة الرواتب ' . $fy, $vals,
            ['D6' => 19, 'C22' => 12, 'H22' => 12, 'C53' => 12, 'E20' => 13, 'K20' => 10, 'C24' => 9, 'E24' => 9, 'H24' => 9, 'J24' => 8]);
    }

    if ($form === 'mof_r10') {
        $rqNow = intdiv((int)date('n') - 1, 3) + 1;
        $rq = (int)($_GET['rq'] ?? 0);
        $rqy = (int)($_GET['rqy'] ?? 0);
        if ($rq < 1 || $rq > 4) {
            $rq = $rqNow - 1; $rqyDef = (int)date('Y');
            if ($rq < 1) { $rq = 4; $rqyDef--; }
        } else { $rqyDef = (int)date('Y'); }
        if ($rqy < 2000 || $rqy > 2100) $rqy = $rqyDef;
        $rqMonthsMap = [1 => [1, 2, 3], 2 => [4, 5, 6], 3 => [7, 8, 9], 4 => [10, 11, 12]];
        $rqEndDay = [1 => 31, 2 => 30, 3 => 30, 4 => 31];
        $rqM = $rqMonthsMap[$rq];
        $qa = mofQuarterAgg($db, $rq, $rqy, $empFilter);
        $paid = $qa['gross'] + $qa['trans'];
        $cnt = count(array_unique($qa['ids']));
        $common = [
            'D5' => $sch['name_ar'] ?? '', 'C6' => $finNum, 'D9' => $prof['trade_name'],
            'E10' => $prof['rep_name'], 'H10' => $prof['rep_title'], 'N10' => $prof['rep_phone'] ?? '',
            'H5' => 1, 'I5' => 1, 'J5' => $rqy, 'L5' => 31, 'M5' => 12, 'N5' => $rqy,
            'H7' => 1, 'I7' => $rqM[0], 'J7' => $rqy, 'L7' => $rqEndDay[$rq], 'M7' => $rqM[2], 'N7' => $rqy,
            'D13' => $prof['gov'], 'F13' => $prof['caza'], 'J13' => $prof['town'], 'N13' => $prof['quarter'],
            'D14' => $prof['street'], 'F14' => $prof['cadastral'], 'J14' => $prof['lot'], 'N14' => $prof['building'],
            'D15' => $prof['floor'], 'F15' => $sch['phone'] ?? '', 'I15' => '', 'N15' => $prof['fax'],
            'D16' => $prof['pob'], 'F16' => $prof['region'], 'K16' => $prof['email'],
            'E18' => $prof['contact_name'], 'J18' => $prof['preparer_name'],
            'E19' => $prof['contact_reg'], 'M19' => $prof['preparer_reg'],
            'C20' => $prof['contact_phone'], 'F20' => $prof['contact_fax'],
            'I20' => $prof['preparer_phone'], 'M20' => $prof['preparer_fax'],
            'G23' => 0, 'G24' => $cnt ?: '', 'O23' => 0,
            'C52' => $prof['signer_name'], 'I52' => $prof['signer_title'],
            'C55' => date('j'), 'D55' => date('n'), 'E55' => date('Y'),
        ];
        $money = [
            'J27' => $qa['gross'], 'J28' => $qa['trans'] ?: '', 'J29' => $paid,
            'J30' => $qa['trans'] ?: '', 'J32' => $qa['other'] ?: '', 'J33' => $qa['net'],
            'J34' => $qa['exempt'] ?: '', 'J35' => $qa['taxable'], 'J36' => $qa['tax'],
            'K43' => $qa['taxable'], 'K44' => $qa['tax'], 'K47' => $qa['tax'],
        ];
        if ($isXlsx) {
            $cells = array_filter($common, function ($v) { return $v !== '' && $v !== null; });
            foreach ($money as $k => $v) { if ($v !== '') $cells[$k] = (int)$v; }
            mofXlsxServe('mof_r10', $cells, 'R10_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string)($sch['name_ar'] ?? 'school')) . '_T' . $rq . '_' . $rqy);
        }
        $vals = $common;
        foreach ($money as $k => $v) $vals[$k] = ($v === '' ? '' : $fmt0($v));
        mofOverlayServe('mof_r10', 'نموذج ر10 — بيان دوري بتأدية الضريبة — الفصل ' . $rq . ' / ' . $rqy, $vals,
            ['D5' => 14, 'E10' => 12, 'E18' => 10, 'J18' => 10, 'C52' => 10, 'K16' => 13, 'N15' => 8]);
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
    // الجنس: من الرابط أو من ملفه — مجهول = '' فلا يُعلَّم X على أي جنس (خانتا 1/2 تبقيان)
    $sexQ = (string)($_GET['sex'] ?? ($emp['gender'] ?? ''));
    $sex = in_array($sexQ, ['m', 'f'], true) ? $sexQ : '';

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
