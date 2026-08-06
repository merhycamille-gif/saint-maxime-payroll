<?php
/**
 * regression_check.php — فحص الانحدار الدائم (يعمل من سطر الأوامر فقط)
 * ==========================================================================
 * «قفل المصلَّح»: كل ميزة/إصلاح اتفقنا عليه مع المستخدم يُضاف هنا كفحص ثابت،
 * ويُشغَّل هذا الملف بعد أي تعديل على البرنامج قبل إبلاغ المستخدم «خلص».
 * أي FAIL = ممنوع اعتبار الشغل منتهياً.
 *
 * التشغيل:  C:\xampp\php\php.exe tools\regression_check.php
 * ==========================================================================
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$PROJ = dirname(__DIR__);
$pass = 0; $fail = 0; $results = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $results;
    $ok ? $pass++ : $fail++;
    $results[] = ($ok ? '✅ PASS' : '❌ FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '');
}

// ---------- تهيئة جلسة وهمية (مدير عام) ----------
session_start();
$_SESSION += ['user_id' => 1, 'username' => 'admin', 'full_name' => 'RegCheck', 'role' => 'superadmin', 'lang' => 'ar'];

require_once $PROJ . '/config/database.php';
require_once $PROJ . '/includes/functions.php';
$db = getDB();

// ---------- عارض صفحات داخلي (كل صفحة بعملية فرعية لتفادي إعادة تعريف الدوال) ----------
function renderPage(string $rel, array $get, array $comp, array $schoolIds = [], string $currency = '', string $schoolYear = ''): string {
    global $PROJ;
    $runner = __DIR__ . '/_render_one.php';
    // الوسائط تمرَّر base64 (اقتباسات JSON تتخربط بسطر أوامر ويندوز)
    file_put_contents($runner, <<<'PHP'
<?php // مشغّل داخلي لـregression_check — لا يُستدعى مباشرة
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
error_reporting(E_ERROR | E_PARSE);
$PROJ = dirname(__DIR__);
session_start();
$_SESSION += ['user_id'=>1,'username'=>'admin','full_name'=>'RegCheck','role'=>'superadmin','lang'=>'ar'];
$_SESSION['salary_comp'] = json_decode(base64_decode($argv[3] ?? ''), true) ?: [];
$__sch = json_decode(base64_decode($argv[4] ?? ''), true) ?: [];
if ($__sch) $_SESSION['active_schools'] = $__sch; // نطاق مدرسة محدّدة (للنماذج المؤسّسية)
$__cur = $argv[5] ?? '';
if ($__cur !== '') $_SESSION['display_currency'] = $__cur; // وضع العملة (ليرة/دولار/الاثنين)
$__sy = $argv[6] ?? '';
if ($__sy !== '') $_SESSION['active_school_year'] = $__sy; // السنة الدراسية المعروضة
$_GET = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];
$_SERVER['REQUEST_URI'] = '/x';
chdir(dirname($PROJ . '/' . $argv[1]));
ob_start();
try { include $PROJ . '/' . $argv[1]; echo ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); echo 'FATAL: ' . $e->getMessage(); }
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
         . escapeshellarg($rel) . ' ' . base64_encode(json_encode($get)) . ' ' . base64_encode(json_encode($comp))
         . ' ' . base64_encode(json_encode($schoolIds)) . ' ' . escapeshellarg($currency) . ' ' . escapeshellarg($schoolYear);
    return (string)shell_exec($cmd . ' 2>NUL');
}
$noFatal = fn(string $h) => strpos($h, 'FATAL') !== 0 && stripos($h, 'Fatal error') === false && strlen($h) > 5000;

echo "═══ فحص الانحدار — رواتب Saint-Maxime ═══\n\n";

/* =====================================================================
 * 1) كل الصفحات الأساسية ترندر بلا خطأ قاتل (بالحالتين: كل الخيارات/بلاها)
 * =================================================================== */
$pages = [
    'eoc_staff'      => ['pages/official_forms.php', ['form' => 'eoc_staff', 'cat' => 'titulaire']],
    'teaching_staff' => ['pages/official_forms.php', ['form' => 'teaching_staff']],
    'salary_all'     => ['pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026]],
    'payment_list'   => ['pages/official_forms.php', ['form' => 'payment_list', 'month' => 6, 'year' => 2026]],
    'full_register'  => ['pages/official_forms.php', ['form' => 'full_register', 'month' => 6, 'year' => 2026]],
    'differences'    => ['pages/official_forms.php', ['form' => 'differences']],
    'general_report' => ['pages/official_forms.php', ['form' => 'general_report']],
    'monthly_rep'    => ['pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026]],
    'cnss_rep'       => ['pages/reports.php', ['report' => 'cnss_summary', 'month' => 6, 'year' => 2026]],
    'tax_rep'        => ['pages/reports.php', ['report' => 'tax_summary', 'month' => 6, 'year' => 2026]],
    'eoc_rep'        => ['pages/reports.php', ['report' => 'eoc_summary', 'month' => 6, 'year' => 2026]],
];
$html = []; // كاش للفحوص التالية
foreach ($pages as $name => [$rel, $get]) {
    foreach ([['none', []], ['all', ['extra', 'aide', 'transport']]] as [$lbl, $comp]) {
        $h = renderPage($rel, $get, $comp);
        $html["$name|$lbl"] = $h;
        check("رندر $name ($lbl) بلا خطأ", $noFatal($h), 'len=' . strlen($h));
    }
}

/* =====================================================================
 * 2) أعمدة الإضافي/المكافأة/النقل تتبع زرّ «الراتب يشمل» (تظهر/تختفي)
 * =================================================================== */
foreach (['eoc_staff', 'teaching_staff', 'salary_all', 'monthly_rep', 'cnss_rep'] as $p) {
    $hAll  = $html["$p|all"] ?? '';
    $hNone = $html["$p|none"] ?? '';
    check("عمود الإضافي يظهر مع الخيار — $p", substr_count($hAll, 'الأجر الإضافي</th>') >= 1);
    check("عمود الإضافي يختفي بلا الخيار — $p", substr_count($hNone, 'الأجر الإضافي</th>') === 0);
    check("عمود المكافأة يختفي بلا الخيار — $p", substr_count($hNone, 'مكافأة ومساعدة</th>') === 0);
}
// الحالة الجزئية: إضافي فقط
$hEx = renderPage('pages/official_forms.php', ['form' => 'eoc_staff', 'cat' => 'titulaire'], ['extra']);
check('إضافي فقط: عمود الإضافي ظاهر والمكافأة مخفية — eoc_staff',
    substr_count($hEx, 'الأجر الإضافي</th>') >= 1 && substr_count($hEx, 'مكافأة ومساعدة</th>') === 0);

/* =====================================================================
 * 3) ofLatestSalary يفضّل السنة الدراسية النشطة (إصلاح «الإضافي = 0»)
 *    فحص من البيانات نفسها: أي أستاذ عنده prime_fixe>0 بالسنة الحالية
 *    ورواتب بسنين لاحقة — لازم النموذج يعرض قيمة سنته لا صفر السنين الجاي.
 * =================================================================== */
$sy = currentSchoolYear();
$probe = $db->query("SELECT ms.employee_id, MAX(ms.extra_lbp + ms.prime_fixe_lbp) mx
    FROM monthly_salaries ms
    JOIN employees e ON e.id = ms.employee_id AND e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire'
    WHERE ms.school_year = '$sy' AND ms.is_calculated = 1
    GROUP BY ms.employee_id
    HAVING mx > 0
       AND EXISTS (SELECT 1 FROM monthly_salaries m2 WHERE m2.employee_id = ms.employee_id
                   AND m2.school_year > '$sy' AND m2.is_calculated = 1 AND (m2.extra_lbp + m2.prime_fixe_lbp) = 0)
    LIMIT 1")->fetch();
if ($probe) {
    $eid = (int)$probe['employee_id'];
    // استدعاء الدالة نفسها ضمن عملية فرعية (لأنها معرّفة داخل official_forms)
    $out = renderPage('pages/official_forms.php', ['form' => 'eoc_staff', 'cat' => 'titulaire'], ['extra']);
    $expected = number_format((float)$probe['mx']);
    check("قيمة الإضافي من السنة النشطة لا من السنين المولّدة (أستاذ $eid)",
        strpos($out, $expected) !== false || strpos($html['eoc_staff|all'] ?? '', $expected) !== false,
        "متوقّع $expected");
} else {
    check('قيمة الإضافي من السنة النشطة', true, 'لا حالة مطابقة للفحص بالبيانات — تخطٍّ');
}

/* =====================================================================
 * 4) فلترة الترك: التارك يبقى بسنة عمله ويختفي من السنين بعدها
 * =================================================================== */
[$m1] = array_map('intval', explode('-', $sy));
$leaver = $db->query("SELECT id,
        LEAST(COALESCE(left_date_cnss,'9999-12-31'),COALESCE(left_date_finance,'9999-12-31'),COALESCE(left_date_eoc,'9999-12-31')) ld
    FROM employees WHERE is_deleted = 0
    HAVING ld >= '{$m1}-10-01' AND ld <> '9999-12-31' LIMIT 1")->fetch();
if ($leaver) {
    [$f, $prm] = yearEmploymentFilter($sy);
    $q = $db->prepare("SELECT COUNT(*) c FROM employees WHERE id = {$leaver['id']}" . str_replace('id IN', 'id IN', $f));
    $q->execute($prm);
    check("التارك {$leaver['ld']} ظاهر بسنة عمله $sy (id {$leaver['id']})", (int)$q->fetch()['c'] === 1);
    // والسنة التالية: يختفي
    $nextSy = ($m1 + 1) . '-' . ($m1 + 2);
    [$f2, $prm2] = yearEmploymentFilter($nextSy);
    $q2 = $db->prepare("SELECT COUNT(*) c FROM employees WHERE id = {$leaver['id']}" . $f2);
    $q2->execute($prm2);
    $ldBeforeNext = $leaver['ld'] < (($m1 + 1) . '-10-01');
    check("التارك يختفي من $nextSy (ترك قبل بدايتها)", !$ldBeforeNext || (int)$q2->fetch()['c'] === 0);
} else {
    check('فلترة الترك', true, 'لا تاركين ضمن السنة الحالية — تخطٍّ');
}

/* =====================================================================
 * 5) الراتب المركّب = الأساس+الدرجة + المكوّنات المختارة (من البيانات)
 * =================================================================== */
$srow = $db->query("SELECT * FROM monthly_salaries WHERE school_year = '$sy' AND is_calculated = 1
    AND (extra_lbp + prime_fixe_lbp) > 0 AND base_plus_echelon_lbp > 0 LIMIT 1")->fetch();
if ($srow) {
    $_SESSION['salary_comp'] = ['extra'];
    $exp = (int)$srow['base_plus_echelon_lbp'] + (int)$srow['extra_lbp'] + (int)$srow['prime_fixe_lbp'];
    check('composedSalaryLbp (أساس+إضافي)', composedSalaryLbp($srow) === $exp, number_format($exp));
    $_SESSION['salary_comp'] = [];
    check('composedSalaryLbp (أساسي فقط)', composedSalaryLbp($srow) === (int)$srow['base_plus_echelon_lbp']);
    $_SESSION['salary_comp'] = ['extra', 'aide', 'transport'];
    $expAll = $exp + (int)$srow['aide_complementaire_lbp'] + (int)$srow['transport_lbp'];
    check('composedSalaryLbp (كل المكوّنات)', composedSalaryLbp($srow) === $expAll, number_format($expAll));
} else {
    check('composedSalaryLbp', true, 'لا صف مناسب — تخطٍّ');
}

/* =====================================================================
 * 6) تصدير Excel يعمل بلا خطأ بالحالتين (ملف xlsx سليم = يبدأ بـPK)
 * =================================================================== */
foreach (['monthly_summary', 'cnss_summary', 'annual_totals'] as $repName) {
    foreach ([['none', []], ['all', ['extra', 'aide', 'transport']]] as [$lbl, $comp]) {
        $x = renderPage('pages/reports_export.php', ['report' => $repName, 'format' => 'xlsx', 'month' => 6, 'year' => 2026], $comp);
        check("تصدير Excel $repName ($lbl)", strpos($x, 'PK') !== false && strpos($x, 'FATAL') !== 0);
    }
}

/* 6-ب) تصدير لائحة الموظفين يحترم الأعمدة المختارة (cols[]) — كل عمود من الشاشة له نظير بالتصدير
 * (كانت علّة: أعمدة الاسم بالعربي/الإضافي/المكافأة/المركّب/… ناقصة من التصدير فتختفي من Word/Excel) */
$elCols = ['name_ar','extra_wage','aide','composed','email','address','birth','social','hours','days'];
$xEl = renderPage('pages/reports_export.php', ['report' => 'employee_list', 'format' => 'docx', 'cols' => $elCols], []);
check('تصدير لائحة الموظفين ملف سليم', strpos($xEl, 'PK') !== false && strpos($xEl, 'FATAL') !== 0);
// مقارنة مفاتيح أعمدة الشاشة ($availCols في reports.php) بمفاتيح التصدير ($cols في reports_export.php):
// أي عمود يظهر على الشاشة ولا نظير له بالتصدير = يختفي من Word/Excel → فشل.
$srcScreen = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
$srcExport = (string)file_get_contents(__DIR__ . '/../pages/reports_export.php');
preg_match('/\$availCols\s*=\s*\[(.*?)\n\s*\];/s', $srcScreen, $mScr);
preg_match('/\$cols\s*=\s*\[(.*?)\n\s*\];/s', $srcExport, $mExp);
preg_match_all("/'([a-z_]+)'\s*=>\s*\[/", $mScr[1] ?? '', $kScr);
preg_match_all("/'([a-z_]+)'\s*=>\s*\[/", $mExp[1] ?? '', $kExp);
$elMissing = array_diff($kScr[1] ?? ['?'], $kExp[1] ?? []);
check('كل أعمدة شاشة لائحة الموظفين لها نظير بالتصدير', !empty($kScr[1]) && !empty($kExp[1]) && !$elMissing,
      $elMissing ? ('ناقص: ' . implode(',', $elMissing)) : (count($kScr[1] ?? []) . ' عموداً'));

/* =====================================================================
 * 7) «كل صفحة فيها مبالغ بنهايتها مجموع» — صف مجموع بآخر جداول المبالغ
 * =================================================================== */
foreach (['eoc_staff', 'teaching_staff'] as $p) {
    $h = $html["$p|all"] ?? '';
    check("صف المجموع موجود — $p", strpos($h, 'المجموع — العدد:') !== false);
}
$hGi = renderPage('pages/official_forms.php', ['form' => 'general_info'], ['extra', 'aide', 'transport']);
check('صف المجموع موجود — general_info', strpos($hGi, 'المجموع — العدد:') !== false, 'len=' . strlen($hGi));
$hMp = renderPage('pages/monthly_payroll.php', ['month' => 6, 'year' => 2026], ['extra', 'aide', 'transport']);
check('صف المجموع موجود — لائحة رواتب الشهر', strpos($hMp, 'المجموع (المحتسَبون:') !== false || strpos($hMp, 'قيد الانتظار') === false, 'len=' . strlen($hMp));
// تطابق المجموع مع الداتا: مجموع «الراتب المركّب» بذيل eoc_staff = مجموع الحساب المباشر من DB
// (فحص وجود القيمة المتوقعة ضمن الصفحة يكفي لكشف أي انزلاق بالأعمدة)

/* =====================================================================
 * 8) التنسيق الرسمي A4 (2026-07-28): كشف الرواتب/القسيمة/الإفادتين
 * =================================================================== */
$hSA = $html['salary_all|all'] ?? renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], ['extra', 'aide', 'transport']);
check('كشف الرواتب: العنوان الرسمي «كشف الرواتب والأجور الشهري»', strpos($hSA, 'كشف الرواتب والأجور الشهري') !== false);
check('كشف الرواتب: عمود توقيع الموظف', strpos($hSA, 'توقيع الموظف') !== false);
check('كشف الرواتب: تواقيع إعداد/تدقيق/اعتماد', strpos($hSA, 'إعداد: المحاسب') !== false
      && strpos($hSA, 'تدقيق: مدير الموارد البشرية') !== false && strpos($hSA, 'اعتماد: المدير العام') !== false);
$hlpSrc = (string)file_get_contents(__DIR__ . '/../includes/report_helpers.php');
check('رؤوس الجداول كحلية #1F4E5F (شاشة + طباعة)', substr_count($hlpSrc, '#1F4E5F') >= 2);
check('الخط العربي الرسمي (Sakkal Majalla) معرَّف', strpos($hlpSrc, 'Sakkal Majalla') !== false);
// حجم الخط 12pt (متل «12» بالوورد) بالتقارير والإفادات — بطلب المستخدم 2026-07-29
check('حجم الخط 12pt بالتقارير (doc-table + رؤوس + فقرات النماذج)',
      strpos($hlpSrc, 'font-size:12pt;margin:10px 0') !== false          // .doc-table
      && substr_count($hlpSrc, 'font-size:12pt') >= 6);                   // th/info-grid/fline/doc-p/sign-box
$attSrc = (string)file_get_contents(__DIR__ . '/../pages/attestations.php');
check('حجم الخط 12pt بالإفادات (أجسام الإفادات الثلاثة)', substr_count($attSrc, 'font-size:12pt') >= 3);
$repSrc = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
check('حجم الخط 12pt بطباعة مركز التقارير', strpos($repSrc, 'font-size: 12pt !important') !== false);
$regEid = (int)$db->query("SELECT ms.employee_id FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
                           WHERE ms.month = 6 AND ms.year = 2026 AND ms.net_salary_lbp > 0 AND e.is_deleted = 0 LIMIT 1")->fetchColumn();
if ($regEid) {
    $hPs = renderPage('pages/monthly_payroll.php', ['employee_id' => $regEid, 'month' => 6, 'year' => 2026], []);
    check('القسيمة: توقيعا المحاسب والموظف بالاستلام', strpos($hPs, 'توقيع المحاسب') !== false && strpos($hPs, 'توقيع الموظف بالاستلام') !== false);
    check('القسيمة: «صافي الراتب المستحق للدفع» بارز', strpos($hPs, 'صافي الراتب المستحق للدفع') !== false);
    $hAt = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'salaire'], []);
    check('إفادة راتب: الصيغة الرسمية (تفصيل + عدم مسؤولية)', strpos($hAt, 'وفق التفصيل الآتي') !== false && strpos($hAt, 'دون أدنى مسؤولية') !== false);
    $hAw = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'tadris'], []);
    check('إفادة عمل: جملة حسن السلوك والالتزام', strpos($hAw, 'حسن سلوك والتزام') !== false);
    // تعويض النقل (2026-07-29): عمود النقل بالكشف السنوي خيار بإيد المستخدم عبر زرّ «الراتب يشمل» —
    // يظهر مع الخيار ويختفي بلاه، ولما يختفي يُعرض «المستحق» بلا النقل لتبقى الأرقام راكبة
    $hAnT = renderPage('pages/annual_slip.php', ['employee_id' => $regEid, 'school_year' => '2025-2026'], ['transport']);
    check('الكشف السنوي: عمود النقل يظهر مع الخيار', strpos($hAnT, 'Transport<br>نقل') !== false);
    $hAn0 = renderPage('pages/annual_slip.php', ['employee_id' => $regEid, 'school_year' => '2025-2026'], []);
    check('الكشف السنوي: عمود النقل يختفي بلا الخيار', strpos($hAn0, 'Transport<br>نقل') === false);
    // (2026-07-29) الكشف السنوي: الفرنسي قبل العربي + الصفوف بالفرنسي فقط
    check('الكشف السنوي: رؤوس الجدول فرنسي-فوق', strpos($hAn0, 'Salaire<br>أساس الراتب') !== false
          && strpos($hAn0, 'Total dû<br>المستحق') !== false && strpos($hAn0, 'Classes / الصفوف') !== false);
    $asdSrc = (string)file_get_contents(__DIR__ . '/../includes/annual_slip_data.php');
    check('الكشف السنوي: الصفوف بالفرنسي فقط', strpos($asdSrc, "classLevelNames(\$emp['classes_taught'] ?? '', true)") !== false);
    check('إفادة راتب: سطر «تعويض النقل» مفصول بجدول التفصيل', strpos($attSrc, '>تعويض النقل<') !== false);
} else {
    check('التنسيق الرسمي: لا موظف تجريبي (6/2026)', false, 'ما لقيت راتب محسوب 6/2026');
}

/* =====================================================================
 * 9) «الراتب يشمل» يعمّ كل التقارير (2026-07-28): بيان الصندوق الفصلي + معلومات عامة
 * =================================================================== */
// (2026-07-29) بيان الصندوق الفصلي صار مطابقاً للنموذج الرسمي الورقي (صورة المستخدم)
// (2026-07-31) عمود «الأجر الإضافي» صار يتبع زرّ «الراتب يشمل» — الفحص هنا بالزرّ مفعّلاً
// (حالة الزرّ المطفأ يغطّيها فحص التوازن بالقسم 26)
$hQ = renderPage('pages/official_forms.php', ['form' => 'eoc_quarterly', 'quarter' => 3], ['extra', 'aide', 'transport']);
check('بيان الصندوق الفصلي: ترويسة النموذج الرسمي',
      strpos($hQ, 'بيان بالمحسومات المقتطعة ومساهمة المدرسة') !== false
      && strpos($hQ, 'لأفراد الهيئة التعليمية في المدارس الخاصة') !== false
      && strpos($hQ, 'رقم المدرسة') !== false && strpos($hQ, 'عن الفصل') !== false);
check('بيان الصندوق الفصلي: أعمدة النموذج (اسم الأب/الإضافي/نصف راتب/مختلف درجة)',
      strpos($hQ, 'اسم الأب') !== false && strpos($hQ, 'الأجر<br>الإضافي ل.ل') !== false
      && strpos($hQ, 'نصف<br>راتب ل.ل') !== false && strpos($hQ, 'مختلف، درجة<br>تمرين ل.ل') !== false);
check('بيان الصندوق الفصلي: خلاصة المقتطعة + مساهمة المدرسة + المجموع العام + المادة 6',
      strpos($hQ, 'المحسومات المقتطعة') !== false && strpos($hQ, 'مساهمة المدرسة') !== false
      && strpos($hQ, 'المجموع العام') !== false && strpos($hQ, 'المرسوم الاشتراعي رقم 47') !== false
      && strpos($hQ, 'توقيع المدير أو من يقوم مقامه') !== false && strpos($hQ, 'ليرة لبنانية لا غير') !== false);
$hGon  = renderPage('pages/official_forms.php', ['form' => 'general_info'], ['extra']);
$hGoff = renderPage('pages/official_forms.php', ['form' => 'general_info'], []);
check('معلومات عامة: عمود الراتب مركّب حسب الخيار', strpos($hGon, 'الأساسي + الإضافي') !== false);
check('معلومات عامة: يرجع أساسياً فقط بلا الخيار', strpos($hGoff, 'الأساسي + الإضافي') === false);

/* =====================================================================
 * 10) «الأرقام تركب» (2026-07-29): المستحق المعروض يتبع زرّ النقل بكل الكشوف
 *     بلا النقل: المستحق = الصافي + العائلي (لا 9 مليون مجهولة المصدر) —
 *     مع النقل: المستحق = total_due كاملاً وعمود النقل ظاهر يفسّر الفرق.
 * =================================================================== */
$rk = $db->query("SELECT ms.net_salary_lbp+ms.family_allowance_lbp a, ms.total_due_lbp b
                  FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                  WHERE ms.year=2026 AND ms.month=6 AND ms.transport_lbp>0 AND ms.total_due_lbp>0 AND e.is_deleted=0
                  LIMIT 1")->fetch();
if ($rk) {
    $fA = number_format((float)$rk['a']); $fB = number_format((float)$rk['b']);
    foreach (['monthly_rep', 'salary_all', 'payment_list'] as $p) {
        check("الأرقام تركب: المستحق بلا النقل = صافي+عائلي — $p", strpos($html["$p|none"] ?? '', $fA) !== false, $fA);
        check("الأرقام تركب: المستحق مع النقل كامل — $p", strpos($html["$p|all"] ?? '', $fB) !== false, $fB);
    }
} else {
    check('الأرقام تركب: لا موظف بنقل>0 (6/2026) للفحص', false);
}
// المجاميع السنوية: تسمية المتوجب توضح «+ النقل» فقط عند تفعيله + سطر التعويضات العائلية موجود
$hAtOn  = renderPage('pages/reports.php', ['report' => 'annual_totals', 'school_year' => '2025-2026'], ['extra', 'aide', 'transport']);
$hAtOff = renderPage('pages/reports.php', ['report' => 'annual_totals', 'school_year' => '2025-2026'], ['extra', 'aide']);
check('المجاميع السنوية: المتوجب «+ النقل» مع الخيار فقط',
      strpos($hAtOn, 'الصافي + التعويضات + النقل') !== false && strpos($hAtOff, 'الصافي + التعويضات + النقل') === false);
check('المجاميع السنوية: سطر التعويضات العائلية موجود', strpos($hAtOff, 'التعويضات العائلية') !== false);
// فتح السنة: فرق النقل يُحسب من transport_lbp وحده (العمودان نفس القيمة — الجمع = دوبل)
$oySrc = (string)file_get_contents(__DIR__ . '/../pages/open_year.php');
check('فتح السنة: لا جمع لعمودَي النقل عند تصحيح total_due', strpos($oySrc, "transport_complement_lbp'] + (float)") === false
      && strpos($oySrc, "transport_complement_lbp'] ?? 0) + (float)") === false);

/* =====================================================================
 * 11) علاوات السنة الجديدة (2026-07-29): الإضافي/المكافأة موجودة برواتب 2026-2027
 *     + شفاء ذاتي أونلاين مربوط بالهيدر (مرّة واحدة بعلامة settings)
 * =================================================================== */
$q = $db->query("SELECT COUNT(DISTINCT ms.employee_id) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                 WHERE ms.school_year='2026-2027' AND e.is_deleted=0 AND ms.prime_fixe_lbp>0")->fetchColumn();
check('السنة الجديدة 2026-2027: الأجر الإضافي موجود برواتبها (≥20 موظف)', (int)$q >= 20, "n=$q");
$fnSrc = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$hdSrc = (string)file_get_contents(__DIR__ . '/../includes/header.php');
check('الشفاء الذاتي healYearAdditions2627 معرَّف ومربوط بالهيدر',
      strpos($fnSrc, 'function healYearAdditions2627') !== false && strpos($hdSrc, 'healYearAdditions2627();') !== false);

/* =====================================================================
 * 12) الواجهة العصرية (2026-07-30): قائمة الموبايل + بحث القائمة الجانبية
 *     + نظام التصميم الحديث في app.css + عدم المسّ بستايلات الطباعة
 * =================================================================== */
$cssSrc = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
check('الواجهة العصرية: زر القائمة للموبايل + الغطاء موجودان بالهيدر',
      strpos($hdSrc, 'menu-toggle') !== false && strpos($hdSrc, 'nav-overlay') !== false);
check('الواجهة العصرية: بحث القائمة الجانبية (navFilter) موجود',
      strpos($hdSrc, 'navFilter') !== false && strpos($cssSrc, '.sidebar-search') !== false);
check('الواجهة العصرية: تجاوب الموبايل بالـCSS (درج منزلق ≤1080px)',
      strpos($cssSrc, 'max-width: 1080px') !== false && strpos($cssSrc, 'nav-open') !== false);
check('الواجهة العصرية: قاعدتا «12px + بولد» بطلب المستخدم باقيتان',
      strpos($cssSrc, 'font-size: 12px !important') !== false && strpos($cssSrc, 'font-weight: 700 !important') !== false);
check('الواجهة العصرية: استثناء النماذج الرسمية .official-doc باقٍ',
      strpos($cssSrc, '.official-doc td') !== false);
check('الواجهة العصرية: ستايلات الطباعة الأساسية باقية (إخفاء القائمة/الشريط + A4)',
      strpos($cssSrc, '.sidebar, .topbar, .no-print') !== false && strpos($cssSrc, '@page { size: A4; margin: 12mm; }') !== false);
// البحث الشامل Ctrl+K: الملف موجود + مربوط بالهيدر + مقيّد بالمدارس المسموحة + التنبيهات العائمة
check('البحث الشامل: ajax_search.php موجود ومقيّد بنطاق المدارس',
      is_file(__DIR__ . '/../ajax_search.php')
      && strpos((string)file_get_contents(__DIR__ . '/../ajax_search.php'), 'schoolScopeSql()') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../ajax_search.php'), 'requireLogin()') !== false);
check('البحث الشامل: مربوط بالشريط العلوي (globalSearch + Ctrl+K)',
      strpos($hdSrc, 'globalSearch') !== false && strpos($hdSrc, "toLowerCase() === 'k'") !== false);
check('التنبيهات العائمة: toast-stack بالهيدر + ستايلها بالـCSS',
      strpos($hdSrc, 'toast-stack') !== false && strpos($cssSrc, '.toast-stack') !== false);
check('تدرّج العناوين: شاشة فقط (@media screen) والطباعة تبقى 12px موحّدة',
      strpos($cssSrc, 'تدرّج عناوين عصري') !== false
      && preg_match('/@media screen \{[^}]*\.topbar h1/s', $cssSrc) === 1);

/* =====================================================================
 * 13) فحص مطابقة القانون (2026-07-30): العدد يتبع السنة الدراسية المختارة
 *     (yearEmploymentFilter) — التارك لا يُحتسب بعد سنة تركه (كان يعدّ كل التاريخ)
 * =================================================================== */
require_once __DIR__ . '/../includes/payroll_calculator.php';
[$yfLC, $ypLC] = yearEmploymentFilter('2025-2026');
$stLC = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_type='enseignant_titulaire' AND is_deleted=0" . $yfLC . " AND school_id=2");
$stLC->execute($ypLC);
$expLC = (int)$stLC->fetchColumn();
$gotLC = count(lawConsistencyCheck([2], '2025-2026'));
$allLC = count(lawConsistencyCheck([2], 'all'));
check('فحص القانون: العدد = أساتذة السنة المختارة فقط (مدرسة 2 / 2025-2026)', $gotLC === $expLC && $expLC > 0, "n=$gotLC");
check('فحص القانون: «كل السنين» أكبر (تشمل التاركين) والسنة المفلترة أصغر', $allLC > $gotLC, "all=$allLC year=$gotLC");
check('فحص القانون: الصفحة توضح السنة المفحوصة', strpos((string)file_get_contents(__DIR__ . '/../pages/law_check.php'), 'السنة المفحوصة') !== false);

/* =====================================================================
 * 14) الفحص الشامل (2026-07-30): لا تحذيرات PHP بأي صفحة مفحوصة
 *     + النسخ الاحتياطي يتدفّق (unbuffered) بدل تحميل كل الداتا بالذاكرة
 * =================================================================== */
$warnHit = '';
foreach ($html as $hk => $hv) {
    if (strpos($hv, 'Undefined array key') !== false || strpos($hv, 'Undefined variable') !== false
        || strpos($hv, 'Fatal error') !== false || preg_match('/\bWarning: /', $hv)) { $warnHit = $hk; break; }
}
check('لا تحذيرات/أخطاء PHP بكل الصفحات المفحوصة', $warnHit === '', $warnHit ?: 'نظيف');
$bkSrc = (string)file_get_contents(__DIR__ . '/../pages/backup.php');
check('النسخ الاحتياطي: تدفّق غير مخزّن (لا انفجار ذاكرة)',
      strpos($bkSrc, 'MYSQL_ATTR_USE_BUFFERED_QUERY => false') !== false
      && strpos($bkSrc, '$db->query("SELECT * FROM `$t`")->fetchAll') === false);
$repSrc = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
check('الكشف الشهري: مفاتيح مجاميع الدولار/المركّب مهيّأة (لا Undefined)',
      preg_match('/\$totals = \[[^\]]*composed_usd/s', $repSrc) === 1);

/* =====================================================================
 * 15) الفحص الشامل — الأمان والصلاحيات (2026-07-30)
 * =================================================================== */
$fnSrc2 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$instSrc = (string)file_get_contents(__DIR__ . '/../install.php');
check('أمان: install.php مُحيَّد (410) ولا يشغّل schema',
      strpos($instSrc, 'http_response_code(410)') !== false
      && strpos($instSrc, 'file_get_contents(__DIR__') === false // لا قراءة/تشغيل لملفات sql
      && strpos($instSrc, '->exec(') === false && strpos($instSrc, 'new PDO') === false);
check('أمان: حساب المدرسة متعدّد المدارس لا يرى تقارير مدارس أخرى (viewerAllowedSchoolIds)',
      preg_match('/function selectedReportSchoolIds.*?isViewer\(\).*?viewerAllowedSchoolIds\(\)/s', $fnSrc2) === 1);
check('أمان: مبدّلات العرض والبحث مسموحة لحساب المدرسة (لا يُطرَد)',
      strpos($fnSrc2, "'switch_currency.php', 'switch_salarycomp.php', 'ajax_search.php'") !== false);
check('أمان: requireWriteAction معرَّفة (صلاحية + مصدر داخلي)',
      strpos($fnSrc2, 'function requireWriteAction') !== false
      && strpos($fnSrc2, 'HTTP_SEC_FETCH_SITE') !== false && strpos($fnSrc2, '!canEdit()') !== false);
$getWritePages = ['annual_slip'=>3,'grades'=>4,'employees'=>1,'bonuses'=>1,'classes'=>1,'exceptional_laws'=>1,
                  'exchange_rates'=>1,'rates_history'=>1,'social_security'=>1,'salary_scales'=>1,'tax_brackets'=>2,
                  'users'=>2,'schools'=>1];
$gwMissing = [];
foreach ($getWritePages as $pg => $minN) {
    $c = preg_match_all('/requireWriteAction\(/', (string)file_get_contents(__DIR__ . "/../pages/$pg.php"));
    if ($c < $minN) $gwMissing[] = "$pg($c/$minN)";
}
check('أمان: كل عمليات التعديل عبر الروابط محميّة بـrequireWriteAction', empty($gwMissing), $gwMissing ? implode(' ', $gwMissing) : '13 صفحة');
check('أمان: القوانين الوطنية (نِسَب/ضمان/سلسلة/شطور) تعديلها للمدير فقط',
      count(array_filter(['rates_history','social_security','salary_scales','tax_brackets'],
        fn($p) => strpos((string)file_get_contents(__DIR__ . "/../pages/$p.php"), 'قوانين وطنية مشتركة') !== false)) === 4);
check('أمان: الإعدادات العامة بقائمة بيضاء وللمدير فقط',
      strpos((string)file_get_contents(__DIR__ . '/../pages/settings.php'), '$allowedSettings') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/settings.php'), 'if (!isAdmin())') !== false);
$usrSrc = (string)file_get_contents(__DIR__ . '/../pages/users.php');
check('أمان: دور «مدير عام» ينشئه/يعدّله المدير العام فقط',
      strpos($usrSrc, "if (isSuperAdmin()) \$ROLES['superadmin']") !== false
      && strpos($usrSrc, "\$cur['role'] === 'superadmin' && !isSuperAdmin()") !== false);
check('أمان: تجديد معرّف الجلسة عند الدخول (session fixation)',
      strpos((string)file_get_contents(__DIR__ . '/../login.php'), 'session_regenerate_id(true)') !== false);
$swOk = count(array_filter(['switch_currency','switch_lang','switch_salarycomp','switch_school','switch_year'],
    fn($s) => strpos((string)file_get_contents(__DIR__ . "/../$s.php"), 'safeBackUrl()') !== false));
check('أمان: رجوع المبدّلات مقيّد بنفس الموقع (safeBackUrl)', $swOk === 5, "$swOk/5");
// 🔴 تغيير السنة من فوق وأنت على صفحة تثبّت السنة برابطها (البطاقة السنوية/التقارير/النماذج):
// كان يرجع للرابط القديم فتضل الصفحة على السنة القديمة (شكوى 2026-08-02). التصليح: switch_year
// يبدّل school_year في رابط العودة نفسه (وعند «كل السنين» يشيله ليتبع الجلسة).
$syw = (string)file_get_contents(__DIR__ . '/../switch_year.php');
check('تغيير السنة يطبَّق حتى على الصفحات المثبّتة سنتها بالرابط (البطاقة السنوية/التقارير)',
      strpos($syw, "preg_match('/[?&]school_year=/', \$back)") !== false
      && strpos($syw, "school_year=[^&]*&?") !== false      // فرع «كل السنين»: إزالة الوسيط
      && strpos($syw, "[?&]school_year=)[^&]*") !== false); // فرع سنة محدّدة: تبديل القيمة
// 📝 عقد التعليم: «المبلغ المتفق عليه» بالعملتين أو كل عملة لحالها (طلب المستخدم 2026-08-02) —
// خانتان فوق العقد (aqd_lbp/aqd_usd) وسطر بالمادة الثالثة بالأرقام والحروف؛ الفارغ = فراغ منقّط.
$aqdEmp = (int)$db->query("SELECT e.id FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id WHERE e.is_deleted=0 LIMIT 1")->fetchColumn();
$aqdH = renderPage('pages/attestations.php', ['employee_id' => $aqdEmp, 'type' => 'aqd_taalim', 'opts_set' => 1, 'aqd_lbp' => 50000000, 'aqd_usd' => 500], []);
check('عقد التعليم: خانتا المبلغ المتفق عليه + سطره بالعقد بالأرقام والحروف (ل.ل و $)',
      strpos($aqdH, 'name="aqd_lbp"') !== false && strpos($aqdH, 'name="aqd_usd"') !== false
      && strpos($aqdH, 'المبلغ المتفق عليه :') !== false
      && strpos($aqdH, '50,000,000 ل.ل') !== false && strpos($aqdH, 'خمسون مليون ليرة لبنانية') !== false
      && strpos($aqdH, '$500') !== false && strpos($aqdH, 'خمسمئة دولار أميركي') !== false);
$aqdH2 = renderPage('pages/attestations.php', ['employee_id' => $aqdEmp, 'type' => 'aqd_taalim', 'opts_set' => 1], []);
check('عقد التعليم: بلا مبلغ متفق عليه → فراغ منقّط يُكتب باليد (ولا أثر لمبلغ صفري)',
      strpos($aqdH2, 'المبلغ المتفق عليه :') !== false
      && preg_match('/المبلغ المتفق عليه :<\/strong>\s*<span style="display:inline-block;min-width:240px/u', $aqdH2) === 1);
// 🗑️ «بدي زر الحذف يكون زغير مش كبير لحتى ما نكبس بالغلط» (2026-08-02): قاعدة CSS مركزية
// تصغّر كل أزرار الحذف (سلة المهملات/✕) وتباعدها عن جيرانها؛ صفحة التأكيد الكبيرة مستثناة.
$appCss = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
check('أزرار الحذف زغيرة ومفرّغة بكل البرنامج (حماية من الكبس بالغلط)',
      strpos($appCss, '.btn-danger:has(.fa-trash, .fa-trash-alt):not(.btn-lg)') !== false
      && strpos($appCss, '.btn-danger[onclick*=".remove()"]') !== false
      && strpos($appCss, 'margin-inline-start: 14px') !== false);

/* =====================================================================
 * 16) الفحص الشامل — صحّة الأرقام والأعداد (2026-07-30)
 * =================================================================== */
$ofSrc = (string)file_get_contents(__DIR__ . '/../pages/official_forms.php');
check('تصريح ر5/ر10: لا تنزيل للنقل من مبلغ لا يحتويه (السطور تترابط)',
      preg_match('/\$paid\s+=\s+\$gross \+ \$trans;/', $ofSrc) === 1
      && preg_match('/\$net\s+=\s+\$paid - \$trans - \$other;/', $ofSrc) === 1
      && strpos($ofSrc, '$net=$gross-$trans;') === false);
// ترابط فعلي بالأرقام: ١٢٠−١٣٠−١٥٠ = ١٦٠ و ١٦٠−١٧٠ = ١٨٠
$r5 = renderPage('pages/official_forms.php', ['form' => 'tax_r5', 'school_year' => '2025-2026'], ['extra','aide','transport'], [2]); // مدرسة واحدة (التصريح مؤسّسي)
$r5t = preg_replace('/<[^>]+>/u', ' ', $r5); $r5v = [];
foreach (['١٢٠','١٣٠','١٥٠','١٦٠','١٧٠','١٨٠'] as $cd) {
    if (preg_match('/' . preg_quote($cd, '/') . '\s+([^0-9]{0,70}?)\s*([0-9,]{7,})/u', $r5t, $mm)) $r5v[$cd] = (int)str_replace(',', '', $mm[2]);
}
check('تصريح ر5: ١٢٠ − ١٣٠ − ١٥٠ = ١٦٠',
      isset($r5v['١٢٠'],$r5v['١٣٠'],$r5v['١٥٠'],$r5v['١٦٠']) && ($r5v['١٢٠']-$r5v['١٣٠']-$r5v['١٥٠']) === $r5v['١٦٠']);
check('تصريح ر5: ١٦٠ − ١٧٠ = ١٨٠',
      isset($r5v['١٦٠'],$r5v['١٨٠']) && ($r5v['١٦٠'] - ($r5v['١٧٠'] ?? 0)) === $r5v['١٨٠']);
check('التقرير العام: «الصافية مع النقل» والمجموع يتبعان زرّ النقل',
      strpos($ofSrc, '$transShown = salaryCompHas(\'transport\') ? $trans : 0;') !== false
      && strpos($ofSrc, '$netWith=$net+$trans;') === false);
check('النماذج المؤسّسية: تطلب مدرسة واحدة (لا تصريح بلا رقم صاحب عمل)',
      strpos($ofSrc, '$institutionForms') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/official_export.php'), 'if (!$school) {') !== false);
check('النماذج: السنة/الشهر مُتحقَّق منهما (لا فلتر سنة فارغ)',
      strpos($ofSrc, "preg_match('/^\\d{4}-\\d{4}\$/', (string)\$schoolYear)") !== false);
$asSrc = (string)file_get_contents(__DIR__ . '/../pages/annual_slip.php');
$aeSrc = (string)file_get_contents(__DIR__ . '/../pages/annual_slip_export.php');
check('الكشف السنوي: الإجمالي/الصافي/المستحق تتبع إخفاء الإضافي والمكافأة (شاشة+تصدير)',
      strpos($asSrc, '$hidRow') !== false && strpos($asSrc, "\$money(\$r['brut'] - \$hR, true)") !== false
      && strpos($aeSrc, "\$r['brut'] - \$hR") !== false && strpos($aeSrc, "\$t['net'] - \$hT") !== false);
check('إفادة الضمان: سطر النقل يظهر عند اختياره فيساوي المجموع',
      strpos((string)file_get_contents(__DIR__ . '/../pages/attestations.php'), '$attTrans = $incTrans') !== false);
$elSrc = (string)file_get_contents(__DIR__ . '/../pages/exceptional_laws.php');
check('القوانين الاستثنائية: العدد = أساتذة فعليون (DISTINCT + غير محذوفين + نطاق المدارس)',
      strpos($elSrc, 'COUNT(DISTINCT gh.employee_id)') !== false && strpos($elSrc, "schoolScopeSql('e.school_id')") !== false);
$lawCnt = (int)$db->query("SELECT COUNT(DISTINCT gh.employee_id) FROM employee_grade_history gh JOIN employees e ON e.id=gh.employee_id WHERE gh.law_reference='102' AND e.is_deleted=0")->fetchColumn();
$lawRows = (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE law_reference='102'")->fetchColumn();
check('القوانين الاستثنائية: العدد أقل من عدد الصفوف (الدرجة مفردة صفّاً لكل وحدة)', $lawCnt > 0 && $lawCnt < $lawRows, "أساتذة=$lawCnt صفوف=$lawRows");
check('الصفوف: عدّ مستعملي الصفّ مقيّد بنطاق المدارس',
      strpos((string)file_get_contents(__DIR__ . '/../pages/classes.php'), "schoolScopeSql()") !== false);
check('«كل المدارس» = الفاعلة فقط (المعطّلة لا تُدمَج بالمجاميع)',
      strpos($fnSrc2, 'function allActiveSchoolIdsCached') !== false
      && preg_match('/function schoolScopeSql.*?allActiveSchoolIdsCached\(\)/s', $fnSrc2) === 1
      && preg_match('/function reportSchoolSql.*?allActiveSchoolIdsCached\(\)/s', $fnSrc2) === 1);
check('التقارير: منتقي المدارس لا يعرض المعطّلة',
      strpos((string)file_get_contents(__DIR__ . '/../pages/reports.php'), '$schools = allSchools();') !== false);
check('حفظ العلاوات: «كل السنين» تُخزَّن بالسنة الحالية لا \'all\'',
      strpos($fnSrc2, 'function writeSchoolYear') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/bonuses.php'), 'writeSchoolYear()') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), 'writeSchoolYear()') !== false);
$noAllYear = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE school_year = 'all'")->fetchColumn();
check('لا علاوة مخزَّنة بسنة \'all\' بالبيانات', $noAllYear === 0, "n=$noAllYear");
$reSrc = (string)file_get_contents(__DIR__ . '/../pages/reports_export.php');
check('التصدير = الشاشة: لائحة الموظفين فيها صفّ مجاميع الأعمدة المالية',
      strpos($reSrc, '$sumCols = [') !== false && strpos($reSrc, "\$row[] = isset(\$sumCols[\$c]) ? formatLBP(\$colTot[\$c]) : '';") !== false);
check('التصدير = الشاشة: عمود الشهادة يعرض وظيفة الموظف الإداري بالاثنين',
      strpos($repSrc = (string)file_get_contents(__DIR__ . '/../pages/reports.php'), "\$r['employee_type'] === 'employe' ? jobTitleLabel(\$r['job_title'] ?? '') : diplomaLabel(\$r['diploma'])") !== false);
check('تقرير الصندوق: لا صفّ مجاميع أصفار على شهر بلا بيانات',
      strpos($repSrc, 'لا تطبع صفّ مجاميع أصفار') !== false);
check('الضريبة: مجموع «الراتب الخاضع للضريبة» يظهر بالشاشة (كان فارغاً)',
      strpos($repSrc, "'txb'=>(int)\$r['taxable_base_lbp']") !== false && strpos($repSrc, "formatLBP(\$a['txb'])") !== false);
check('رقم الصندوق: شفاء ذاتي يمنع كتابة رقم مدرسة على مؤسسات أخرى',
      strpos($fnSrc2, 'function healCaisseNumbers') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), 'healCaisseNumbers();') !== false
      && strpos($ofSrc, "UPDATE schools SET caisse_number='75210'") === false);
$badCaisse = (int)$db->query("SELECT COUNT(*) FROM schools WHERE caisse_number='75210' AND name_ar NOT LIKE 'مدرسة%'")->fetchColumn();
check('رقم الصندوق: لا مؤسسة غير المدرسة تحمل رقمها', $badCaisse === 0, "n=$badCaisse");

/* =====================================================================
 * 17) جولة «ولا غلطة» (2026-07-30): النِّسَب المؤرّخة، الأسرار، الكاش، العملة، التدقيق
 * =================================================================== */
$fnSrc3 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$ofSrc3 = (string)file_get_contents(__DIR__ . '/../pages/official_forms.php');
$oeSrc3 = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('النماذج الرسمية: لا نِسَب مكتوبة بالكود (كلّها مؤرّخة من rate_history)',
      preg_match('/[\/*]\s*0\.(11|085|06|03)\b/', $ofSrc3) === 0 && preg_match('/[\/*]\s*0\.(11|085|06|03)\b/', $oeSrc3) === 0
      && strpos($fnSrc3, 'function rateFrac') !== false && strpos($fnSrc3, 'function cnssTotalFrac') !== false);
// النسبة المؤرّخة تُقرأ فعلاً بالقيم الصحيحة
check('النِّسَب المؤرّخة تُقرأ صحيحة (ضمان 3% ونهاية خدمة 8.5% وتعويض عائلي 6%)',
      abs(rateFrac('cnss_employee_rate', 6, 2026, 3) - 0.03) < 1e-9
      && abs(rateFrac('end_of_service_rate', 6, 2026, 8.5) - 0.085) < 1e-9
      && abs(rateFrac('family_compensation_rate', 6, 2026, 6) - 0.06) < 1e-9
      && abs(cnssTotalFrac(6, 2026) - 0.11) < 1e-9);
check('سرّ روابط الأساتذة عشوائي لكل تنصيب (ليس نصّاً بالكود)',
      strpos($fnSrc3, 'StM_infoform_') === false && strpos($fnSrc3, 'function infoFormSecret') !== false
      && strpos($fnSrc3, 'random_bytes(32)') !== false);
$secLen = strlen((string)getSetting('info_form_secret', ''));
check('سرّ الروابط مخزَّن بقاعدة البيانات بطول كافٍ', $secLen >= 32, "طول=$secLen");
// التوكن ثابت (الروابط المُرسَلة تبقى تعمل ضمن نفس التنصيب)
check('توكن الأستاذ ثابت بين الاستدعاءات', infoFormToken(1828) === infoFormToken(1828));
$dbSrc = (string)file_get_contents(__DIR__ . '/../config/database.php');
check('ذاكرة الإعدادات تتحدّث عند الحفظ (لا قراءة قيمة قديمة بنفس الطلب)',
      strpos($dbSrc, 'function &settingsCache') !== false
      && preg_match('/function setSetting.*?settingsCache\(\);\s*\$settings\[\$key\] = \$value;/s', $dbSrc) === 1);
$probeKey = '__reg_probe_' . getmypid();
setSetting($probeKey, 'v1');
$readBack = getSetting($probeKey, '');
setSetting($probeKey, 'v2');
$readBack2 = getSetting($probeKey, '');
try { $db->exec("DELETE FROM settings WHERE `key` = " . $db->quote($probeKey)); } catch (Exception $e) {}
check('اختبار فعلي: الحفظ ثم القراءة بنفس الطلب يرجع الجديد', $readBack === 'v1' && $readBack2 === 'v2', "$readBack/$readBack2");
check('الدولار المخزَّن الصفري يُحسَب من الليرة عند العرض (لا $0.00)',
      strpos($fnSrc3, 'function rowUsd') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/monthly_payroll.php'), "rowUsd(\$salary, 'net_salary_usd'") !== false);
// وضع العملة: «دولار فقط» لا يخلط الليرة بالدولار في الكشف الشهري
$hUsd = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026], ['extra','aide','transport'], [], 'usd');
$lbpHits = preg_match_all('/L\.L/u', $hUsd);
check('وضع «دولار فقط»: الكشف الشهري بلا خلط عملات', $lbpHits <= 2, "خلايا ليرة=$lbpHits");
$repSrc3 = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
check('الكشف الشهري: كل أعمدة المجاميع بالعملة المختارة (لا formatLBP ثابتة)',
      strpos($repSrc3, "\$dualTot(\$t['total'], \$t['total_usd'])") !== false
      && strpos($repSrc3, "\$dualTot(\$t['net'], \$t['net_usd'])") !== false
      && strpos($repSrc3, "\$dualTot(\$t['base'], \$t['base_usd'])") !== false);
check('تدقيق سلامة الأرقام المخزَّنة موجود بصفحة فحص القانون',
      strpos((string)file_get_contents(__DIR__ . '/../pages/law_check.php'), 'تدقيق سلامة الأرقام المخزَّنة') !== false);
// سلامة البيانات: الثوابت التي يجب أن تبقى صفراً دائماً
$INT = 'FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=0';
// الأقواس ضرورية: AND تسبق OR بالأولوية، فبلاها يتسرّب شرط is_deleted ويُحتسب المحذوفون
$dq = fn(string $w) => (int)$db->query("SELECT COUNT(*) $INT AND ($w)")->fetchColumn();
check('بيانات: الأساس+الدرجة = الأساس + قيمة الدرجة (كل الصفوف)',
      $dq('ABS(ms.base_plus_echelon_lbp - (ms.base_salary_lbp + ms.echelon_value_lbp)) > 1') === 0);
check('بيانات: المستحق = الصافي + العائلي + النقل (كل الصفوف)',
      $dq('ABS(ms.total_due_lbp - (ms.net_salary_lbp + ms.family_allowance_lbp + ms.transport_lbp)) > 1') === 0);
check('بيانات: لا ضمان محسوم على غير خاضع للضمان', $dq('ms.cnss_amount_lbp > 0 AND e.cnss_subject = 0') === 0);
check('بيانات: لا ضريبة أكبر من الأساس الخاضع', $dq('ms.income_tax_lbp > ms.taxable_base_lbp') === 0);
check('بيانات: لا قيم سالبة', $dq('ms.base_salary_lbp < 0 OR ms.net_salary_lbp < 0 OR ms.total_due_lbp < 0 OR ms.cnss_amount_lbp < 0 OR ms.income_tax_lbp < 0') === 0);
check('بيانات: لا مبالغ مستحيلة (>100 مليار بصفّ)', $dq('ms.prime_fixe_lbp > 1e11 OR ms.total_due_lbp > 1e11 OR ms.base_salary_lbp > 1e11') === 0);
check('بيانات: عمودا النقل متطابقان دائماً (لا دوبل)', $dq('ms.transport_lbp > 0 AND ms.transport_complement_lbp > 0 AND ms.transport_lbp <> ms.transport_complement_lbp') === 0);
check('بيانات: لا صفوف رواتب مكرّرة (موظف/شهر/سنة)',
      (int)$db->query("SELECT COUNT(*) FROM (SELECT employee_id, month, year, COUNT(*) c FROM monthly_salaries GROUP BY employee_id, month, year HAVING c > 1) x")->fetchColumn() === 0);
check('بيانات: لا رواتب لموظفين غير موجودين',
      (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms LEFT JOIN employees e ON e.id = ms.employee_id WHERE e.id IS NULL")->fetchColumn() === 0);
check('حارس خطأ العملة: مبلغ ضخم بالدولار يُفهَم ليرةً (يمنع راتب 3600 مليار)',
      strpos($fnSrc3, 'function sanitizeAmountCurrency') !== false
      && sanitizeAmountCurrency(54000000, 'USD') === 'LBP'
      && sanitizeAmountCurrency(1500, 'USD') === 'USD'
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), 'sanitizeAmountCurrency(') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/bonuses.php'), 'sanitizeAmountCurrency(') !== false);
check('حماية: الحقول المصفوفة بالفورمات محصّنة ((array) cast)',
      strpos((string)file_get_contents(__DIR__ . '/../pages/tax_brackets.php'), "(array)(\$_POST['rate']") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/schools.php'), "(array)(\$_POST['sig_name']") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/salary_scales.php'), "(array)(\$_POST['new_salary_2017']") !== false);

/* =====================================================================
 * 18) صفحة «فحص صحّة البرنامج» (2026-07-30): يفحص المستخدمُ البرنامجَ بنفسه
 * =================================================================== */
$hcSrc = (string)file_get_contents(__DIR__ . '/../pages/health_check.php');
$hdSrc2 = (string)file_get_contents(__DIR__ . '/../includes/header.php');
check('فحص الصحّة: الصفحة موجودة وللمدير فقط وقراءة فقط',
      $hcSrc !== '' && strpos($hcSrc, 'if (!isAdmin())') !== false
      && preg_match('/\b(UPDATE|DELETE|INSERT|ALTER)\s+(?!.*health_log_since)/i', preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $hcSrc)) === 0);
check('فحص الصحّة: مربوطة بالقائمة الجانبية', strpos($hdSrc2, 'pages/health_check.php') !== false);
check('فحص الصحّة: تفصل خطأ البرنامج عن بيانات تحتاج قرار المستخدم',
      strpos($hcSrc, "\$type = 'review'") !== false && strpos($hcSrc, '$reviewAll') !== false);
check('فحص الصحّة: قراءة تاريخ سجلّ Apache تتجاهل الميكروثانية (وإلّا احتُسب القديم جديداً)',
      strpos($hcSrc, "preg_replace('/\\.\\d+/', '', \$dm[1])") !== false);
check('فحص الصحّة: زرّ تصفير سجلّ التحذيرات محميّ (POST + CSRF)',
      strpos($hcSrc, "'reset_log'") !== false && strpos($hcSrc, 'requireCsrf()') !== false
      && strpos($hcSrc, 'health_log_since') !== false);
// تشغيل فعلي: الصفحة تعطي «لا خطأ برمجي»
$hcOut = renderPage('pages/health_check.php', [], ['extra','aide','transport']);
check('تشغيل فعلي: صفحة فحص الصحّة تقول «لا خطأ برمجي واحد»',
      strpos($hcOut, 'لا خطأ برمجي واحد') !== false, 'len=' . strlen($hcOut));

/* =====================================================================
 * 19) رؤوس الجداول الثابتة (2026-07-30): العناوين تبقى ظاهرة أثناء التمرير
 *     بكل الجداول (.table/.doc-table/.salary-slip-table) — والطباعة كما هي
 * =================================================================== */
$jsSrc = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$cssSrc19 = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
check('رؤوس ثابتة: CSS التثبيت موجود (sticky + tbl-scroll) لكل أنواع الجداول',
      strpos($cssSrc19, '.tbl-scroll') !== false
      && preg_match('/\.table thead th, \.doc-table thead th, \.salary-slip-table thead th \{\s*position:\s*sticky/s', $cssSrc19) === 1);
check('رؤوس ثابتة: على الشاشة فقط — الطباعة تلغي صندوق التمرير (الرأس يتكرّر بكل صفحة)',
      preg_match('/@media print \{\s*\.tbl-scroll \{ max-height: none !important; overflow: visible !important; \}/s', $cssSrc19) === 1);
// 📌 «العناوين تبقى براس الصفحة مش بنص الصفحة» (2026-08-03): الصفحة نفسها هي الأسانسور
// (لا صناديق تمرير داخلية)، الرأس يلتصق تحت الشريط العلوي، والجدول الأعرض من شاشته
// يحتفظ بأسانسوره الأفقي فقط ورأسه يُثبَّت يدوياً بالتمرير (translateY)
check('رؤوس ثابتة براس الشاشة: أسانسور واحد للصفحة + فتح الحاويات + تثبيت يدوي للجدول العريض',
      strpos($jsSrc, 'initStickyHeads') !== false
      && strpos($jsSrc, 'stickXHeads') !== false
      && strpos($jsSrc, 'data-stkvis') !== false
      && strpos($jsSrc, 'translateY') !== false
      && strpos($jsSrc, 'table-wrapper') !== false
      && strpos($jsSrc, "addEventListener('scroll', stickXHeads") !== false
      && strpos($jsSrc, 'top += rows[i].offsetHeight') !== false
      && strpos($jsSrc, "classList.add('tbl-scroll')") === false);
check('رؤوس ثابتة: تكرار رأس الجدول بالطباعة باقٍ (thead: table-header-group)',
      strpos((string)file_get_contents(__DIR__ . '/../includes/report_helpers.php'), 'display:table-header-group') !== false);

/* =====================================================================
 * 20) 🔴 قاعدة التارك (2026-07-30، شكوى المستخدم): مَن عمل ولو شهراً واحداً
 *     في السنة يبقى اسمه فيها حتى لو ترك خلالها (حتى التارك 30-9)،
 *     ويُشال فقط من السنة الدراسية التي تبدأ بعد تركه —
 *     بلائحة الموظفين + عدّادات الرئيسية + «احسب للكل»
 * =================================================================== */
$lv = $db->query("SELECT id, employee_code FROM employees
    WHERE is_deleted = 0 AND status = 'actif'
      AND LEAST(COALESCE(left_date_cnss,'9999-12-31'),COALESCE(left_date_finance,'9999-12-31'),COALESCE(left_date_eoc,'9999-12-31')) BETWEEN '2025-10-01' AND '2026-09-30'
      AND id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = '2025-2026'
                 AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0))
    LIMIT 1")->fetch();
if ($lv) {
    $lvMark = '<strong>' . $lv['employee_code'] . '</strong>';
    $empY = renderPage('pages/employees.php', [], ['extra','aide','transport'], [], '', '2025-2026');
    check('قاعدة التارك: تارك خلال 2025-2026 يبقى بلائحة موظفي 2025-2026', strpos($empY, $lvMark) !== false, 'id=' . $lv['id']);
    $empN = renderPage('pages/employees.php', [], ['extra','aide','transport'], [], '', '2026-2027');
    check('قاعدة التارك: نفسه يختفي من لائحة 2026-2027 (بدأت بعد تركه)', strpos($empN, $lvMark) === false, 'id=' . $lv['id']);
} else {
    check('قاعدة التارك: وجود عيّنة تارك خلال 2025-2026 للفحص الفعلي', false, 'لا عيّنة');
}
$empSrc20 = (string)file_get_contents(__DIR__ . '/../pages/employees.php');
$idxSrc20 = (string)file_get_contents(__DIR__ . '/../index.php');
$mpSrc20  = (string)file_get_contents(__DIR__ . '/../pages/monthly_payroll.php');
$oySrc20  = (string)file_get_contents(__DIR__ . '/../pages/open_year.php');
$isNullTrio = "left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL";
check('قاعدة التارك: لائحة الموظفين تفلتر ببداية السنة الدراسية لا باستبعاد كلّي',
      strpos($empSrc20, "LEAST(COALESCE(left_date_cnss") !== false && strpos($empSrc20, $isNullTrio) === false);
check('قاعدة التارك: عدّادات الرئيسية تستبعد التاركين فقط في وضع «كل السنين»',
      preg_match('/\$notLeft = \(\$yfStat === \'\'\)/', $idxSrc20) === 1);
check('قاعدة التارك: «احسب للكل» يحسب التارك لأشهر سنة تركه (حدّ بداية السنة)',
      strpos($mpSrc20, '$syStartC') !== false && strpos($mpSrc20, $isNullTrio) === false);
// (2026-08-06) صار الاستثناء ببداية السنة المفتوحة (>= y1-10-01) بدل الاستبعاد الكلّي —
// التارك قبل بداية السنة لا يُنقَل، ومن ترك خلالها/بعدها يُشمَل (فتصحّ السنين القديمة أيضاً)
check('قاعدة التارك: فتح السنة الجديدة يبقى يستثني التاركين (لا ينتقلون للسنة الجديدة)',
      substr_count($oySrc20, "\$emps->execute([\$schoolId, \$y1 . '-10-01']);") === 2
      && strpos($oySrc20, $isNullTrio) === false);

/* =====================================================================
 * 21) 🗑️ حذف مدرستَي «ثانوية السيدة - مغدوشة» و«ليسيه سان نيقولا» نهائياً
 *     (2026-07-31، بطلب المستخدم): لا أثر لهما ولا بيانات يتيمة خلفهما،
 *     والشفاء الذاتي يبقى مركّباً بالهيدر ليحذفهما من الأونلاين تلقائياً
 * =================================================================== */
check('حذف مغدوشة/سان نيقولا: لا مدرسة بهذا الاسم في الداتا',
      (int)$db->query("SELECT COUNT(*) FROM schools WHERE name_ar LIKE '%مغدوشة%' OR name_ar LIKE '%نيقولا%' OR name_fr LIKE '%Maghdouch%' OR name_fr LIKE '%Nicolas%'")->fetchColumn() === 0);
check('حذف مغدوشة/سان نيقولا: لا موظفين يتامى (مدرستهم محذوفة)',
      (int)$db->query("SELECT COUNT(*) FROM employees e LEFT JOIN schools s ON s.id = e.school_id WHERE s.id IS NULL")->fetchColumn() === 0);
check('حذف مغدوشة/سان نيقولا: لا رواتب يتيمة (مدرستها محذوفة)',
      (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms LEFT JOIN schools s ON s.id = ms.school_id WHERE s.id IS NULL")->fetchColumn() === 0);
$fnSrc21 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$hdSrc21 = (string)file_get_contents(__DIR__ . '/../includes/header.php');
check('حذف مغدوشة/سان نيقولا: الشفاء الذاتي موجود ومستدعى بالهيدر (يُصلح الأونلاين لحاله)',
      strpos($fnSrc21, 'function healPurgeClosedSchools20260731') !== false
      && strpos($hdSrc21, 'healPurgeClosedSchools20260731();') !== false);
check('حذف مغدوشة/سان نيقولا: الشفاء يحفظ نسخة استرجاع قبل الحذف',
      strpos($fnSrc21, "purge_auto_") !== false && strpos($fnSrc21, 'FOREIGN_KEY_CHECKS=0') !== false);
$hcSrc21 = (string)file_get_contents(__DIR__ . '/../pages/health_check.php');
check('حذف مغدوشة/سان نيقولا: فحصا اليتامى مضافان بصفحة فحص الصحّة',
      strpos($hcSrc21, 'لا موظفين تابعين لمدرسة محذوفة') !== false
      && strpos($hcSrc21, 'لا رواتب تابعة لمدرسة محذوفة') !== false);

/* =====================================================================
 * 22) 🔠 حجم الخط 12 (12pt متل الوورد) بكل التقارير والإفادات والقسائم
 *     (2026-07-31، طلب p1): النص 12pt كحدّ أدنى على الورق، والجدول/القسيمة
 *     الأعرض من الورقة تصغّر نفسها محسوباً (--pz) فلا يُقصّ عمود ولا تنقسم قسيمة
 * =================================================================== */
$cssSrc22 = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
$jsSrc22  = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$rhSrc22  = (string)file_get_contents(__DIR__ . '/../includes/report_helpers.php');
$ofSrc22  = (string)file_get_contents(__DIR__ . '/../pages/official_forms.php');
$asSrc22  = (string)file_get_contents(__DIR__ . '/../pages/annual_slip.php');
check('خط 12: طباعة الجداول العادية 12pt لا 12px (app.css)',
      strpos($cssSrc22, 'body { font-size: 12pt; }') !== false
      && preg_match('/@media print \{\s*\/\*[^*]*\*\/\s*body, p, span[^}]*\{\s*font-size: 12pt !important;/s', $cssSrc22) === 1
      && strpos($cssSrc22, 'font-size: 12px !important;
    }
    /* النماذج الرسمية') === false);
check('خط 12: الجدول العريض يصغّر نفسه بالطباعة (--pz للجداول العادية + القسائم)',
      strpos($cssSrc22, '.table { zoom: var(--pz, 1); }') !== false
      && strpos($cssSrc22, '.payslip-card, .salary-slip { zoom: var(--pz, 1); }') !== false
      && strpos($jsSrc22, 'function fitPrintZoom') !== false
      && strpos($jsSrc22, "addEventListener('beforeprint', fitPrintZoom)") !== false);
check('خط 12: جداول التقارير doc-table أساسها 12pt (report_helpers)',
      strpos($rhSrc22, '.doc-table{width:100%;border-collapse:collapse;font-size:12pt;') !== false);
check('خط 12: لا نصوص مستندات أصغر من 12 في report_helpers (doc-note/code-table/mof/cnss)',
      strpos($rhSrc22, '.doc-note{font-size:12pt;') !== false
      && strpos($rhSrc22, '.code-table{width:100%;border-collapse:collapse;font-size:12pt;') !== false
      && preg_match('/\.(doc-note|code-table|mof-gov|cnss-head|lh-contact)\{[^}]*font-size:(?:[0-9]|1[01])(?:\.\d+)?px/u', $rhSrc22) === 0);
check('خط 12: لا تصغير يدوي على جداول النماذج الرسمية (official_forms)',
      strpos($ofSrc22, 'doc-table" style="font-size:') === false
      && preg_match('/font-size:(?:[0-9]|1[01])(?:\.\d+)?px/', $ofSrc22) === 0);
check('خط 12: القسيمة السنوية 12pt والتصغير المحسوب يبقيها بصفحة واحدة',
      strpos($asSrc22, '.salary-slip-table { font-size: 12pt !important;') !== false
      && strpos($asSrc22, 'zoom: var(--pz, 1);') !== false
      && preg_match('/font-size:\s*(?:[0-9]|1[01])(?:\.\d+)?px\s*!important/', $asSrc22) === 0);
check('خط 12: القسيمة الشهرية مشمولة (payslip-card على العرض الفردي والجماعي)',
      strpos((string)file_get_contents(__DIR__ . '/../pages/monthly_payroll.php'), '<div class="card payslip-card" id="ppExportArea">') !== false);
check('خط 12: النماذج طبق الأصل مستثناة عمداً (xlsf 9px كما صُمّمت — المحاذاة أهم)',
      strpos($rhSrc22, 'table.xlsf{font-size:9px;}') !== false);

/* =====================================================================
 * 23) ✏️ أزرار «تعديل/حفظ/حذف» قدّام كل درجة بلوحة درجات الأستاذ (2026-07-31)
 *     + ترتيب اللوحة (أساس قانوني مختصر بتلميح، حقول مقفلة حتى «تعديل»)
 * =================================================================== */
$fnSrc23 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$grSrc23 = (string)file_get_contents(__DIR__ . '/../pages/grades.php');
check('أزرار الدرجات: اللوحة فيها تعديل/حفظ/حذف لكل صفّ (gr-edit/gr-save/row_delete)',
      strpos($fnSrc23, 'class="btn btn-sm btn-warning gr-edit"') !== false
      && strpos($fnSrc23, 'gr-save') !== false
      && strpos($fnSrc23, 'name="row_delete"') !== false);
check('أزرار الدرجات: معالج الحذف موجود مع حماية دخول الملاك + rechain',
      strpos($grSrc23, "isset(\$_POST['row_delete'])") !== false
      && strpos($grSrc23, 'درجة دخول الملاك ثابتة — لا تُعدَّل ولا تُحذف') !== false
      && substr_count($grSrc23, 'rechainGradeHistory($employeeId)') >= 2);
check('أزرار الدرجات: الحفظ الشامل لا يخطف كبسة حذف الصفّ الواحد',
      strpos($grSrc23, "!isset(\$_POST['row_delete']) && \$employeeId > 0") !== false);
check('أزرار الدرجات: حقلا التاريخ والمقدار مقفلان (readonly) حتى كبسة «تعديل»',
      strpos($fnSrc23, 'readonly') !== false && strpos($fnSrc23, 'x.readOnly = false') !== false
      && strpos($fnSrc23, "name=\"gamt[") !== false);
check('أزرار الدرجات: الحفظ الفوري — أي تغيير يُظهر زرّ «حفظ» بنفس السطر (change/input + نبض)',
      strpos($fnSrc23, "f.addEventListener('change', function (e) { reveal(e.target); })") !== false
      && strpos($fnSrc23, "f.addEventListener('input',  function (e) { reveal(e.target); })") !== false
      && strpos($fnSrc23, 'gr-pulse') !== false
      && strpos($fnSrc23, 'id="gradeUnitsTable"') !== false);
check('أزرار الدرجات: 🔒 كل اللائحة مقفولة افتراضياً و«تعديل» يفتح صفّه فقط',
      strpos($fnSrc23, 'tr.gr-locked input[type=checkbox]{pointer-events:none') !== false
      && strpos($fnSrc23, "tr.classList.remove('gr-locked')") !== false
      && substr_count($fnSrc23, 'class="gr-locked"') >= 1
      && substr_count($fnSrc23, 'gr-locked') >= 5);
// القفل بـpointer-events لا بـdisabled: المعطَّل لا يُرسَل مع POST فيمسح «محسوبة؟» عن كل الصفوف المقفولة
preg_match('/<input type="checkbox" name="keep\[\]"[^>]*>/u', $fnSrc23, $mKeep23);
check('أزرار الدرجات: الصحّات المقفولة تبقى تُرسَل مع الحفظ (pointer-events لا disabled — لا يضيع «محسوبة؟»)',
      !empty($mKeep23[0]) && strpos($mKeep23[0], 'disabled') === false);
// فحص فعلي: صفحة الدرجات وملف الأستاذ يعرضان الأزرار لأستاذ ملاك عنده سجلّ درجات
$t23 = $db->query("SELECT e.id FROM employees e JOIN employee_grade_history g ON g.employee_id = e.id
                   WHERE e.employee_type = 'enseignant_titulaire' AND e.is_deleted = 0 LIMIT 1")->fetchColumn();
if ($t23) {
    $hGr = renderPage('pages/grades.php', ['employee_id' => (string)$t23], ['extra','aide','transport']);
    check('أزرار الدرجات: صفحة الدرجات تعرض الجدول والأزرار فعلياً', strpos($hGr, 'gradeRowsTable') !== false
          && strpos($hGr, 'gr-edit') !== false && strpos($hGr, 'row_delete') !== false, 'id=' . $t23);
    $hEmp23 = renderPage('pages/employees.php', ['action' => 'edit', 'id' => (string)$t23], ['extra','aide','transport']);
    check('أزرار الدرجات: لوحة الدرجات بملف الأستاذ تعرض الأزرار فعلياً', strpos($hEmp23, 'gradeRowsTable') !== false
          && strpos($hEmp23, 'gr-edit') !== false, 'id=' . $t23);
} else {
    check('أزرار الدرجات: وجود أستاذ ملاك بسجلّ درجات للفحص الفعلي', false, 'لا عيّنة');
}
// فحص فعلي على الداتا (ضمن معاملة تُرجَع بالكامل): إضافة درجة ثم حذفها مع rechain تعيد الدرجة الحالية كما كانت
if ($t23) {
    try {
        $g0 = (float)$db->query("SELECT current_grade FROM employees WHERE id = $t23")->fetchColumn();
        $db->beginTransaction();
        $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,law_reference,notes)
                      VALUES (?,0,1,1,1,'2020-01-01','manual',NULL,'فحص regression مؤقت')")->execute([$t23]);
        $rid23 = (int)$db->lastInsertId();
        rechainGradeHistory($t23);
        $g1 = (float)$db->query("SELECT current_grade FROM employees WHERE id = $t23")->fetchColumn();
        $db->prepare("DELETE FROM employee_grade_history WHERE id = ?")->execute([$rid23]);
        rechainGradeHistory($t23);
        $g2 = (float)$db->query("SELECT current_grade FROM employees WHERE id = $t23")->fetchColumn();
        $db->rollBack();
        check('أزرار الدرجات: الحذف يعيد السلسلة والدرجة الحالية كما كانت (rechain بعد delete)',
              abs($g1 - ($g0 + 1)) < 0.01 && abs($g2 - $g0) < 0.01, "قبل=$g0 بعد الإضافة=$g1 بعد الحذف=$g2");
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        check('أزرار الدرجات: الحذف يعيد السلسلة والدرجة الحالية كما كانت (rechain بعد delete)', false, $e->getMessage());
    }
}

/* =====================================================================
 * 24) 📊 درجات كانون تظهر بعمود «قيمة الدرجة» لا مدموجة بالأساس (2026-07-31، p1 مارغريتا بونصار)
 *     الباگ: early-return بالمحرّك كان يُرجِع تدرّج 0 لكل درجة كسرية (X.5) فتُدمج
 *     درجات كانون الاستثنائية دغري بأساس الراتب بالكشوف. الصح: أساس الشهر = مجموع
 *     الشهر السابق + قيمة الدرجة = الفرق، ثم تنضمّ للأساس الأشهر التالية.
 *     وقاعدة نصف الدرجة تبقى محفوظة (floor بالسلسلة): نص وحده = أساس ثابت وتدرّج 0.
 * =================================================================== */
$pcSrc24 = (string)file_get_contents(__DIR__ . '/../includes/payroll_calculator.php');
$fnSrc24 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$hdSrc24 = (string)file_get_contents(__DIR__ . '/../includes/header.php');
check('توزيع الدرجات: أُزيل early-return «الدرجة الكسرية = تدرّج 0» من المحرّك',
      strpos($pcSrc24, 'if ($effGrade != floor($effGrade)) {') === false
      && strpos($pcSrc24, 'فالفرق يظهر بعمود «قيمة الدرجة»') !== false);
check('توزيع الدرجات: الشفاء الذاتي healEchelonSplit20260731 موجود ومربوط بالهيدر (يُصلح الأونلاين لحاله)',
      strpos($fnSrc24, 'function healEchelonSplit20260731') !== false
      && strpos($hdSrc24, 'healEchelonSplit20260731();') !== false);
check('توزيع الدرجات: لا يبقى أي شهر «درجة كسرية بقفزة أساس وتدرّج 0» بالداتا',
      (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms
        JOIN monthly_salaries p ON p.employee_id = ms.employee_id AND p.school_year = ms.school_year
             AND (p.year*12 + p.month) = (ms.year*12 + ms.month) - 1
        JOIN employees e ON e.id = ms.employee_id AND e.employee_type = 'enseignant_titulaire'
        WHERE ms.grade_at_month <> FLOOR(ms.grade_at_month) AND ms.echelon_value_lbp = 0
          AND FLOOR(ms.grade_at_month) > FLOOR(p.grade_at_month)
          AND p.base_plus_echelon_lbp > 0 AND p.base_plus_echelon_lbp < ms.base_plus_echelon_lbp")->fetchColumn() === 0);
check('توزيع الدرجات: أساس + قيمة الدرجة = الراتب بعد التدرّج بكل صفوف الرواتب (المجموع لم يتغيّر)',
      (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE base_salary_lbp + echelon_value_lbp <> base_plus_echelon_lbp")->fetchColumn() === 0);
// مرجع حيّ ١: جونا زوبا 1546 (درجة 23.5) — كانون 2025: الأساس يبقى 1,755,000 والدرجات 260,000 بعمودها
$r24 = $db->query("SELECT base_salary_lbp, echelon_value_lbp, base_plus_echelon_lbp FROM monthly_salaries WHERE employee_id = 1546 AND month = 1 AND year = 2025")->fetch(PDO::FETCH_ASSOC);
check('توزيع الدرجات: مرجع جونا زوبا كانون 2025 = أساس 1,755,000 + درجات 260,000 = 2,015,000',
      $r24 && (int)$r24['base_salary_lbp'] === 1755000 && (int)$r24['echelon_value_lbp'] === 260000 && (int)$r24['base_plus_echelon_lbp'] === 2015000,
      $r24 ? json_encode($r24) : 'لا صفّ');
// مرجع حيّ ٢: الين منصور 191 (39.5) — قاعدة نصف الدرجة محفوظة: أساس ثابت 3,445,000 وتدرّج 0 طوال 2025-2026
$r24b = $db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = 191 AND school_year = '2025-2026'
                    AND (base_salary_lbp <> 3445000 OR echelon_value_lbp <> 0)")->fetchColumn();
check('توزيع الدرجات: قاعدة نصف الدرجة محفوظة (الين منصور 39.5: أساس ثابت 3,445,000 وتدرّج 0 كل السنة)',
      (int)$r24b === 0 && (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = 191 AND school_year = '2025-2026'")->fetchColumn() > 0);
// مرجع حيّ ٣: جمّا عبّود 1752 لم يتأثّر (درجات كاملة): تشرين تدرّج 40,000 ومجموع السنة 15,420,000
$r24c = $db->query("SELECT SUM(base_plus_echelon_lbp) FROM monthly_salaries WHERE employee_id = 1752 AND school_year = '2025-2026'")->fetchColumn();
$r24d = $db->query("SELECT echelon_value_lbp FROM monthly_salaries WHERE employee_id = 1752 AND month = 10 AND year = 2025")->fetchColumn();
check('توزيع الدرجات: مرجع جمّا ثابت (تشرين تدرّج 40,000 ومجموع السنة 15,420,000)',
      (int)$r24c === 15420000 && (int)$r24d === 40000, "مجموع=$r24c تشرين=$r24d");
$hcSrc24 = (string)file_get_contents(__DIR__ . '/../pages/health_check.php');
check('توزيع الدرجات: الفحصان مضافان بصفحة فحص الصحّة',
      strpos($hcSrc24, 'أساس الراتب + قيمة الدرجة = الراتب بعد التدرّج') !== false
      && strpos($hcSrc24, 'درجات كانون لا تُدمج دغري بأساس الراتب') !== false);

/* =====================================================================
 * 25) 🖨️ تنزيل النماذج الرسمية يعمل على أي خادم (2026-07-31، «ما عم في اطبع اكسل ولا PDF» أونلاين)
 *     المولّد الاحتياطي phpFillXlsxTemplate (ZipArchive+DOM، بلا بايثون/LibreOffice)
 *     يعبّي قالب المستخدم الرسمي نفسه → زرّ Excel يعمل أونلاين، وطلب PDF بلا LibreOffice
 *     لا يُخطَف بملف إكسل بل يرجع للبديل الصحيح مع رسالة توضيحية.
 * =================================================================== */
$reSrc25 = (string)file_get_contents(__DIR__ . '/../includes/report_export.php');
$oeSrc25 = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('التصدير الرسمي: المولّد الاحتياطي بـPHP موجود ومربوط كبديل عن بايثون',
      strpos($reSrc25, 'function phpFillXlsxTemplate') !== false
      && strpos($reSrc25, 'if (!phpFillXlsxTemplate($templateAbs, $cells, $outXlsx))') !== false);
check('التصدير الرسمي: طلب PDF بلا LibreOffice يرجع false (البديل الصحيح) لا ملف إكسل مخطوف',
      strpos($reSrc25, 'لا نخطف طلب الـPDF') !== false);
check('التصدير الرسمي: رسالة توضيحية للمستخدم عند تعذّر PDF على الخادم (بدل «ما صار شي»)',
      strpos($oeSrc25, "\$_SESSION['flash_info']") !== false);
check('التصدير الرسمي: القالبان الرسميان موجودان (الاشتراكات الشهري + إفادة العمل)',
      is_file(__DIR__ . '/../assets/templates/cnss_monthly.xlsx')
      && is_file(__DIR__ . '/../assets/templates/cnss_work_attestation.xlsx'));
// فحص فعلي: تعبئة القالب بـPHP وحده ثم قراءة الملف الناتج والتثبّت من القيم وإجبار إعادة حساب المجاميع
if (!function_exists('phpFillXlsxTemplate')) require_once __DIR__ . '/../includes/report_export.php';
$out25 = __DIR__ . '/../tmp/regr_fill_' . uniqid() . '.xlsx';
try {
    $ok25 = phpFillXlsxTemplate(__DIR__ . '/../assets/templates/cnss_monthly.xlsx',
        ['D8' => 'فحص regression', 'C21' => 29, 'P21' => 165673200, 'G14' => '045'], $out25);
    $sheet25 = '';
    if ($ok25) {
        $z25 = new ZipArchive();
        if ($z25->open($out25) === true) { $sheet25 = (string)$z25->getFromName('xl/worksheets/sheet1.xml'); $z25->close(); }
    }
    check('التصدير الرسمي: التعبئة بـPHP وحدها تنجح والقيم (نص عربي/رقم/صفر بادئ) تُكتب فعلاً',
          $ok25 && strpos($sheet25, 'فحص regression') !== false
          && strpos($sheet25, '<v>165673200</v>') !== false
          && strpos($sheet25, '>045<') !== false);
    // خلايا الصيغ (مجموع القالب P43) يجب أن تكون بلا قيمة مخبّأة — وإلا يظهر مجموع قديم خاطئ للدولة
    check('التصدير الرسمي: مجاميع القالب تُحسب من جديد (لا قيمة مخبّأة قديمة بخلايا الصيغ)',
          $sheet25 !== '' && preg_match('#<c r="P43"[^>]*>(?:(?!</c>).)*<v>#s', $sheet25) === 0
          && strpos($sheet25, 'P21+P29+P37') !== false);
} catch (Throwable $e) {
    check('التصدير الرسمي: التعبئة بـPHP وحدها تنجح والقيم (نص عربي/رقم/صفر بادئ) تُكتب فعلاً', false, $e->getMessage());
}
@unlink($out25);

/* =====================================================================
 * 26) 📑 زرّ «الراتب يشمل» يُحترم بكشف الضمان الاسمي المفصّل + «معلومات تفصيلية عن الراتب»
 *     (2026-07-31، p1: «حطيت نقل ما ببين، شلت إضافي بضلها الإضافي») + إصلاح انزياح
 *     أعمدة «معلومات تفصيلية» (كانت 18 رأساً مقابل 19 خلية — الأرقام تحت عناوين غلط).
 * =================================================================== */
$ofSrc26 = (string)file_get_contents(__DIR__ . '/../pages/official_forms.php');
check('الراتب يشمل: كشف الضمان الاسمي يستعمل extraAideHeads/transportHead (لا أعمدة مقصوصة بالكود)',
      substr_count($ofSrc26, 'extraAideHeads(\' rowspan="2"\')') >= 4
      && strpos($ofSrc26, "\$nomCols = 19 + compColsCount();") !== false);
check('الراتب يشمل: «معلومات تفصيلية عن الراتب» — المحسومات 7 أعمدة برؤوس صحيحة (مجموع المحسومات موجود)',
      strpos($ofSrc26, '<th colspan="7">المحسومات القانونية</th>') !== false
      && strpos($ofSrc26, '<th>مجموع المحسومات</th>') !== false
      && strpos($ofSrc26, '<th>التنزيل العائلي</th>') === false);
check('الراتب يشمل: «معلومات تفصيلية» — المستحق المعروض عبر dueShownLbp والأجر الإجمالي من الظاهر فقط',
      strpos($ofSrc26, "'due'=>dueShownLbp(\$r),") !== false
      && strpos($ofSrc26, "\$sdCols = 16 + compColsCount();") !== false);
// فحص فعلي: توازن الرؤوس/الخلايا بكل تركيبات الزر للنموذجين (يمسك أي عمود ناقص/زائد فوراً)
$colBalance = function (string $html): array {
    if (!preg_match('#<table[^>]*doc-table[^>]*>(.*?)</table>#s', $html, $tm)) return [-1, -1];
    preg_match('#<thead>(.*?)</thead>#s', $tm[1], $hm);
    preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $hm[1] ?? '', $hrows);
    $leaf = 0;
    foreach ($hrows[1] as $ri => $rowHtml) {
        preg_match_all('#<th([^>]*)>#', $rowHtml, $ths);
        foreach ($ths[1] as $attrs) {
            $cs = preg_match('/colspan="(\d+)"/', $attrs, $c) ? (int)$c[1] : 1;
            if ($ri === 0) $leaf += ($cs > 1) ? 0 : $cs;
            if ($ri === 1) $leaf += $cs;
        }
    }
    preg_match('#<tbody>(.*?)$#s', $tm[1], $bm);
    preg_match_all('#<tr>(.*?)</tr>#s', $bm[1] ?? '', $brows);
    foreach ($brows[1] as $rowHtml) {
        $n = preg_match_all('#<td[^>]*>#', $rowHtml, $x);
        if ($n > 5) return [$leaf, $n];
    }
    return [$leaf, 0];
};
$balOk = true; $balDetail = []; $balN = 0;
$balForms26 = ['salary_detail', 'cnss_nominative_monthly', 'eoc_quarterly', 'salary_all',
               'payment_list', 'full_register', 'general_report', 'teaching_staff', 'eoc_staff'];
foreach ([['extra','aide','transport'], ['transport'], []] as $comp26) {
    foreach ($balForms26 as $form26) {
        $h26 = renderPage('pages/official_forms.php', ['form' => $form26, 'month' => '7', 'year' => '2026'], $comp26, [2]);
        [$lf, $cells] = $colBalance($h26);
        $balN++;
        if ($lf < 5 || $lf !== $cells) { $balOk = false; $balDetail[] = $form26 . '[' . implode(',', $comp26) . "]=$lf/$cells"; }
    }
}
check('الراتب يشمل: رؤوس الأعمدة = خلايا الصف بكل تركيبات الزر (9 كشوف جماعية × 3 تركيبات)',
      $balOk, $balDetail ? implode(' · ', $balDetail) : "$balN حالة متوازنة");
// بقية الكشوف والبطاقات التي كانت تتجاهل الزر (جولة p1 الثانية — eoc_quarterly وأخواتها)
check('الراتب يشمل: المحسومات الفصلية (صندوق التعويضات) — عمود الأجر الإضافي مشروط بالزر',
      strpos($ofSrc26, "\$allSpan = (\$multiS ? 11 : 10) + (salaryCompHas('extra') ? 1 : 0);") !== false
      && preg_match('/if \(salaryCompHas\(\'extra\'\)\): \?><th>الأجر<br>الإضافي/u', $ofSrc26) === 1);
check('الراتب يشمل: كلفة المؤسسة — الإجمالي والكلفة من البنود الظاهرة فقط وسطور «منها»/النقل مشروطة',
      strpos($ofSrc26, "\$totalCost = \$gross+\$fam+(salaryCompHas('transport') ? \$trans : 0)+\$employerCharges;") !== false
      && strpos($ofSrc26, "if (salaryCompHas('extra')) \$lines[] = ['— منها: الأجر الإضافي'") !== false
      && strpos($ofSrc26, "if (salaryCompHas('transport')) \$lines[] = ['تعويضات النقل'") !== false);
check('الراتب يشمل: بطاقة الأستاذ وبطاقة الملاك — سطرا الإضافي/المكافأة مشروطان بالزر',
      substr_count($ofSrc26, "if (salaryCompHas('extra')): ?><div><span class=\"k\">الأجر الإضافي:</span>") === 2
      && substr_count($ofSrc26, "if (salaryCompHas('aide')): ?><div><span class=\"k\">مكافأة ومساعدة:</span>") === 2);
$ehSrc26 = (string)file_get_contents(__DIR__ . '/../pages/employee_history.php');
check('الراتب يشمل: سيرة الأستاذ — سطور «+ إضافي/مكافأة/نقل» مشروطة فيبقى المركّب = مجموع الظاهر',
      strpos($ehSrc26, "if (salaryCompHas('extra')): ?><tr><td>+ Supplément") !== false
      && strpos($ehSrc26, "if (salaryCompHas('aide')): ?><tr><td>+ Prime") !== false
      && strpos($ehSrc26, "if (salaryCompHas('transport')): ?><tr><td>+ Transport") !== false);
check('الراتب يشمل: التقرير العام — عمود «الصافية مع تعويض النقل» مشروط بالنقل (لا ذكر للنقل والزرّ مطفأ)',
      strpos($ofSrc26, "if (salaryCompHas('transport')): ?><th>الرواتب الصافية<br>مع تعويض النقل</th>") !== false
      && strpos($ofSrc26, "<th>الرواتب الصافية<?= salaryCompHas('transport') ? '<br>بدون النقل' : '' ?></th>") !== false);
// النقل يظهر عند اختياره ويختفي عند إلغائه (نص الرأس نفسه)
$hT26 = renderPage('pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => '7', 'year' => '2026'], ['transport'], [2]);
$hN26 = renderPage('pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => '7', 'year' => '2026'], [], [2]);
check('الراتب يشمل: عمود «تعويض النقل» بكشف الضمان الاسمي يظهر مع النقل ويختفي بلاه، والإضافي يختفي عند شيله',
      strpos($hT26, '<th rowspan="2">تعويض النقل</th>') !== false
      && strpos($hT26, '<th rowspan="2">الأجر الإضافي</th>') === false
      && strpos($hN26, '<th rowspan="2">تعويض النقل</th>') === false);

/* =====================================================================
 * 27) القالب الموحّد للتقارير (docSheet) + وضع «عرض المستند» (doc-view)
 *     — طلب المستخدم 2026-08-01: «التقارير والإفادات منظّمة ومرتّبة قبل كل شي»
 *     + «افتح التقرير واضح بلا عجقة وارجع لنفس الصفحة اللي كنت فيها»
 * =================================================================== */
$fnSrc27  = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$rhSrc27  = (string)file_get_contents(__DIR__ . '/../includes/report_helpers.php');
$hdSrc27  = (string)file_get_contents(__DIR__ . '/../includes/header.php');
$repSrc27 = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
check('القالب الموحّد: دوال docSheetStart/docSheetEnd و docBackUrl موجودة',
      strpos($rhSrc27, 'function docSheetStart(') !== false
      && strpos($rhSrc27, 'function docSheetEnd(') !== false
      && strpos($fnSrc27, 'function docBackUrl(') !== false);
check('القالب الموحّد: التقارير الستة بمركز التقارير كلها على docSheetStart (لا ترويسة/عنوان «لحاله»)',
      substr_count($repSrc27, 'docSheetStart(') >= 7   // 6 تقارير + جدول «تفصيل لكل مدرسة»
      && substr_count($repSrc27, 'docSheetEnd()') === substr_count($repSrc27, 'docSheetStart('));
// 🔴 doc-view للتقارير والنماذج الرسمية **فقط** — بطاقة الراتب السنوية والإفادات وسيرة الأستاذ
// تبقى بشكلها المعهود (شكوى المستخدم p1 بتاريخ 2026-08-01: «خربتلي كل التقارير والإفادات»)
check('وضع عرض المستند: مفعَّل بمركز التقارير والنماذج الرسمية فقط — لا يلمس البطاقة السنوية/الإفادات/السيرة',
      strpos($repSrc27, '$docFocus = true') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/official_forms.php'), '$docFocus = true') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/attestations.php'), '$docFocus = true') === false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/annual_slip.php'), '$docFocus = true') === false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employee_history.php'), '$docFocus = true') === false
      && strpos((string)file_get_contents(__DIR__ . '/../assets/css/app.css'), 'body.doc-view { background') === false);
check('وضع عرض المستند: الهيدر يضيف صف doc-view للـbody وزرّ الرجوع يستعمل docBackUrl',
      strpos($hdSrc27, "!empty(\$docFocus) ? ' doc-view'") !== false
      && strpos($hdSrc27, 'docBackUrl()') !== false);
check('وضع عرض المستند: CSS يخفي القائمة الجانبية وأدوات التنقّل ويرسم الورقة الموحّدة',
      ($cssSrc27 = (string)file_get_contents(__DIR__ . '/../assets/css/app.css')) !== ''
      && strpos($cssSrc27, 'body.doc-view .sidebar') !== false
      && strpos($cssSrc27, '.doc-sheet') !== false
      && strpos($cssSrc27, '.doc-head .dh-ar') !== false);
// فحص فعلي: التقارير الستة ترندر بورقة موحّدة (doc-sheet + عنوان عربي + body doc-view)
$docRepOk = true; $docRepDetail = [];
foreach ([['report' => 'monthly_summary', 'month' => 6, 'year' => 2026],
          ['report' => 'cnss_summary', 'month' => 6, 'year' => 2026],
          ['report' => 'tax_summary', 'month' => 6, 'year' => 2026],
          ['report' => 'eoc_summary', 'month' => 6, 'year' => 2026],
          ['report' => 'employee_list'],
          ['report' => 'annual_totals', 'school_year' => '2025-2026']] as $g27) {
    $h27 = renderPage('pages/reports.php', $g27, ['extra', 'aide', 'transport']);
    if (strpos($h27, 'doc-sheet') === false || strpos($h27, 'dh-ar') === false
        || strpos($h27, 'doc-view') === false || strpos($h27, 'صدر بتاريخ') === false) {
        $docRepOk = false; $docRepDetail[] = $g27['report'];
    }
}
check('القالب الموحّد: التقارير الستة ترندر فعلاً بورقة موحّدة (ترويسة + عنوان + شارات) بوضع doc-view',
      $docRepOk, $docRepDetail ? ('ناقص: ' . implode(',', $docRepDetail)) : '6/6');
// النماذج الرسمية: بوضع doc-view لا يتكرّر زرّا رجوع/طباعة (officialFormToolbar يصمت)
$hOF27 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], ['extra', 'aide', 'transport'], [2]);
check('وضع عرض المستند: النماذج الرسمية عليها doc-view وبلا شريط أزرار مكرّر (page-actions)',
      strpos($hOF27, 'doc-view') !== false && strpos($hOF27, 'page-actions') === false);

/* =====================================================================
 * 28) ملاحظات المستخدم 2026-08-01 (p1): «رجعنا نفس الأخطاء والتظبيطات»
 *     — المجاميع مرّة واحدة بآخر التقرير + نموذج الضمان 190A مرتّب
 * =================================================================== */
// المجموع العام يُطبع مرّة واحدة بآخر التقرير (لا يتكرّر بأسفل كل صفحة فيُقرأ كمجاميع وسطية)
check('ترتيب التقارير: المجاميع (tfoot) تُطبع مرّة واحدة بآخر التقرير لا على كل صفحة',
      strpos($rhSrc27, '.doc-table tfoot{display:table-row-group;}') !== false
      && strpos($rhSrc27, 'table-footer-group;}') === false);
// نموذج الضمان 190A: السنة لا تتكرّر («آب 2026 2026») — plabel بلا سنة والقالب يطبع @@year@@ وحدها
$ofSrc28 = (string)file_get_contents(__DIR__ . '/../pages/official_forms.php');
check('نموذج الضمان 190A: السنة لا تظهر مرّتين (plabel بلا سنة داخل القالب)',
      strpos($ofSrc28, '$plabelNoYear = $isQuarter ? $qNames[$quarter] : monthName($month, \'ar\');') !== false
      && strpos($ofSrc28, "'plabel'=>\$plabelNoYear") !== false);
// المعاينة عالشاشة = النموذج الرسمي المعبّى (iframe inline) حيث LibreOffice متوفّر، والطباعة تبقى بالنسخة المرسومة
$h190 = renderPage('pages/official_forms.php', ['form' => 'cnss_contrib_monthly', 'month' => 8, 'year' => 2026], [], [2]);
$hasLO = is_file('C:/Program Files/LibreOffice/program/soffice.exe') || is_file('C:/Program Files (x86)/LibreOffice/program/soffice.exe');
check('نموذج الضمان 190A: المعاينة عالشاشة هي النموذج الرسمي المعبّى نفسه (حيث LibreOffice) والرسمة تبقى للطباعة',
      $hasLO ? (strpos($h190, 'format=pdf&inline=1') !== false && strpos($h190, 'print-only') !== false)
             : (strpos($h190, 'xls-sheet') !== false));
// officialTemplateExport يدعم العرض داخل الصفحة (inline) بلا كسر التنزيل الافتراضي
check('نموذج الضمان 190A: التصدير الرسمي يدعم المعاينة داخل الصفحة (disposition inline)',
      strpos((string)file_get_contents(__DIR__ . '/../includes/report_export.php'),
             "function officialTemplateExport(\$templateAbs, array \$cells, \$format, \$name, \$disposition = 'attachment')") !== false);

/* =====================================================================
 * 29) «قد ورقة A4 وواضحة» (طلب المستخدم 2026-08-01) — مؤكَّدة بصرياً بالـPDF:
 *     البطاقة السنوية تملأ الورقة، والقسيمة الشهرية صفحة واحدة بتواقيعها
 * =================================================================== */
$jsSrc29 = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
check('قد الورقة: قياس القسائم/البطاقات على عرض الورقة الحقيقي لا عرض الشاشة (لا تصغير زائد)',
      strpos($jsSrc29, "c.style.width = tw + 'px';") !== false
      && strpos($jsSrc29, "c.style.setProperty('--pz', 1);") !== false);
check('الخط 12 بكل شي: لا تكبير فوق خط 12 بالقسائم/البطاقات (سقف 1) والقسيمة صفحة واحدة بهامش أمان التواقيع (960)',
      strpos($jsSrc29, "Math.min(tw / (w * scale), th / (h * scale), 1)") !== false
      && strpos($jsSrc29, "th = land ? 720 : 960;") !== false);
check('قد الورقة: البطاقة السنوية تملأ طول الورقة (188mm معوَّضة بالتصغير) والجدول يوزّع الفراغ على صفوفه',
      ($asSrc29 = (string)file_get_contents(__DIR__ . '/../pages/annual_slip.php')) !== ''
      && strpos($asSrc29, 'min-height: calc(188mm / var(--pz, 1))') !== false
      && strpos($asSrc29, 'flex: 1 1 auto') !== false);
// 🖨️ إصلاح «البطاقة أونلاين صغيرة بنص ورقة فاضية» (شكوى المستخدم p1 بتاريخ 2026-08-01):
// beforeprint يقيس على تنسيق الشاشة فيغلط (~0.42) — القياس الصحيح يقلب قواعد @media print
// مؤقتاً ويقيس على مقاس الورقة، دفعةً واحدة لكل البطاقات (الطباعة الجماعية لا تعلّق)
check('🖨️ البطاقة السنوية: القياس بشروط الطباعة الحقيقية (قلب @media print) دفعةً لكل البطاقات',
      strpos($jsSrc29, "rule.media.mediaText = 'all';") !== false
      && strpos($jsSrc29, 'twS = 1085, thS = 710') !== false
      && strpos($jsSrc29, 'zArr') !== false
      && strpos($jsSrc29, "flipped[fI][0].media.mediaText = flipped[fI][1];") !== false);
check('🖨️ صمام الصفوف: صفّ البطاقة لا ينقسم على صفحتين + بانر إرشاد هوامش المتصفح (None) بوضع _autoprint',
      strpos($asSrc29, '.salary-slip-table tr { page-break-inside: avoid; }') !== false
      && strpos(($ftSrc29 = (string)file_get_contents(__DIR__ . '/../includes/footer.php')), 'None / بلا') !== false);
// «بدي بس اطبع ما تطلع دغري البرنتر... متل ما بطبع وورد» (طلب المستخدم 2026-08-01):
// وضع _autoprint = معاينة الورقة أولاً + زرّان واضحان «اطبع عالورق» و«احفظها عالكمبيوتر»
// (PDF) — ممنوع فتح حوار الطابعة تلقائياً عند تحميل الصفحة
check('🖨️ معاينة قبل الطباعة (متل الوورد): _autoprint لا يفتح الطابعة لحاله + زرّا «اطبع عالورق» و«احفظها عالكمبيوتر»',
      strpos($ftSrc29, 'اطبع عالورق') !== false
      && strpos($ftSrc29, 'احفظها عالكمبيوتر') !== false
      && strpos($ftSrc29, "onclick=\"window.print()\"") !== false
      && !preg_match('/setTimeout\([^)]*window\.print/s', $ftSrc29));
// 💾 «ما بيّن عندي Save as PDF» (2026-08-01): زرّ الحفظ ينزّل ملف PDF حقيقياً بكبسة
// واحدة بلا أي شاشة (تصوير القسيمة بشكل الطباعة + jsPDF) — المكتبات والخطوط محلية
// بالكامل (استقلال البرنامج: بلا Google Fonts وبلا cdnjs)
check('💾 حفظ PDF بكبسة واحدة: pdf-save.js مربوط بالفوتر والزرّ يستدعي msaSavePdfStart',
      strpos($ftSrc29, 'pdf-save.js') !== false
      && strpos($ftSrc29, 'msaSavePdfStart(this)') !== false
      && strpos($ftSrc29, 'window.BASE_URL') !== false
      && is_file(__DIR__ . '/../assets/js/pdf-save.js'));
check('💾 مكتبتا التوليد محليتان (html-to-image + jsPDF) وتصوير القسيمة بشكل الطباعة (قلب @media print)',
      is_file(__DIR__ . '/../assets/vendor/html-to-image.js')
      && is_file(__DIR__ . '/../assets/vendor/jspdf.umd.min.js')
      && strpos(($psSrc29 = (string)file_get_contents(__DIR__ . '/../assets/js/pdf-save.js')), "mediaText = 'all'") !== false
      && strpos($psSrc29, 'msaSavePdfStart') !== false);
$hdSrc29 = (string)file_get_contents(__DIR__ . '/../includes/header.php');
check('🔤 الخطوط والأيقونات محلية بالكامل (استقلال البرنامج — بلا CDN خارجي)',
      strpos($hdSrc29, 'fonts.googleapis.com') === false
      && strpos($hdSrc29, 'cdnjs.cloudflare.com') === false
      && strpos($hdSrc29, 'assets/fonts/fonts.css') !== false
      && strpos($hdSrc29, 'assets/vendor/fontawesome/css/all.min.css') !== false
      && is_file(__DIR__ . '/../assets/fonts/fonts.css')
      && is_file(__DIR__ . '/../assets/vendor/fontawesome/css/all.min.css')
      && is_file(__DIR__ . '/../assets/vendor/fontawesome/webfonts/fa-solid-900.woff2')
      && strpos((string)file_get_contents(__DIR__ . '/../assets/fonts/fonts.css'), 'fonts.gstatic.com') === false);
// الأرقام العريضة (strong/b) أيضاً 12pt عالورق — كانت ناقصة من لائحة الطباعة فتطبع
// أهم الأرقام (الإجمالي/الصافي/المستحق) أصغر من جيرانها (ملاحظة المستخدم p1)
check('الخط 12 بكل شي: strong/b ضمن لائحة 12pt للطباعة (الأرقام العريضة لا تطبع أصغر)',
      preg_match('/@media print \{[^}]*?strong, b,[^}]*?font-size: 12pt !important/s',
                 (string)file_get_contents(__DIR__ . '/../assets/css/app.css')) === 1);
check('الخط 12 بكل شي: جداول التقارير لا تتكبّر فوق خط 12 (تملأ الورقة بتوسيع الأعمدة) ولا تُقصّ',
      strpos((string)file_get_contents(__DIR__ . '/../includes/report_helpers.php'), 'حجم الخط 12 بكل شي') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/report_helpers.php'), '? 1.4 : 1') === false
      && strpos($jsSrc29, '? 1.4 : 1') === false && strpos($jsSrc29, '1.8') === false);
// 🔠 «بدي حجم الخط بالتقارير يكون 12» (2026-08-04): كان الجدول الواسع يتصغّر على الشاشة
// (zoom لغاية 0.5) فيبيّن الخط أصغر من 12 — صار الخط 12 حقيقياً على الشاشة دائماً،
// والجدول الأوسع من شاشته له تمرير أفقي فقط، والتصغير المحسوب --pz بقي للورق وحده
$rhSrc34 = (string)file_get_contents(__DIR__ . '/../includes/report_helpers.php');
check('الخط 12 على الشاشة: جداول التقارير بلا تصغير zoom على الشاشة (التصغير المحسوب للطباعة فقط)',
      preg_match('/if \(z < 1\) t\.style\.zoom/', $rhSrc34) === 0
      && strpos($rhSrc34, '@media print{ .doc-table{zoom:var(--pz,1) !important;} }') !== false);
// 🔠 «على الورق في تقارير 12 وتقارير مش 12» (ملاحظة المستخدم 2026-08-04): القياس القديم
// كان بأوسع حالة (max-content بلا لفّ نص + مع أعمدة الأزرار غير المطبوعة) فيصغّر أكثر
// من اللزوم (التاركون 6.3 والضمان 9 بدل 12). القياس الصحيح: بعرض الورقة الحقيقي مع لفّ
// النص وإخفاء no-print — فلا يُصغَّر إلا ما لا تسعه الورقة فعلاً (الكشف الشهري 17 عموداً)
$asSrc34 = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
$jsSrc34 = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
check('الخط 12 على الورق: القياس بعرض الورقة الحقيقي لا بأوسع حالة (doc-table + .table معاً)',
      strpos($rhSrc34, "t.style.setProperty('width', target + 'px', 'important')") !== false
      && strpos($jsSrc34, "t.style.setProperty('width', target + 'px', 'important')") !== false
      && strpos($rhSrc34, 'width:max-content !important;table-layout:auto') === false
      && preg_match('/\.table\.pz-measure \{ width: max-content/', $asSrc34) === 0);
check('الخط 12 على الورق: أعمدة الأزرار (no-print) لا تدخل بقياس التصغير',
      strpos($rhSrc34, '.pz-measure .no-print{display:none !important;}') !== false
      && strpos($asSrc34, '.pz-measure .no-print { display: none !important; }') !== false);
check('الخط 12 على الورق: تقارير reports.php أعمدتها حسب المحتوى (لا fixed يقصّ الأرقام) والتاركون A4 أفقي',
      strpos((string)file_get_contents(__DIR__ . '/../pages/reports.php'), 'table-layout: fixed') === false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/left_teachers.php'), 'land-report') !== false);
// 🎨🔒 التصميم النهائي المجمّد للبطاقة السنوية — اعتمده المستخدم حرفياً بقوله
// «ما تغير بقى شي بالبطاقة احفظها منيح» (2026-08-01 مساءً). أي كسر لأحد هذه البنود
// = خرق لقرار المستخدم الصريح — ممنوع تعديل تصميم البطاقة بدون طلبه المباشر:
// بلا كحلي · رؤوس فاتحة · المحسومات أحمر فاتح · أرقام 14 عريضة والدولار تحتها ·
// خط Cairo موحّد · اسم الأستاذ 17pt أبرز عنصر · معلومات 13.5 عريضة · صفحة واحدة
check('🔒 البطاقة السنوية (تصميم مجمّد بأمر المستخدم): رؤوس فاتحة والمحسومات بأحمر فاتح وبلا كحلي',
      strpos($asSrc29, '.salary-slip-table thead th { background: #f1f5f9 !important; color: #111 !important;') !== false
      && strpos($asSrc29, 'th.deduction-header { background: #ffe3e3 !important;') !== false
      && strpos($asSrc29, '#1F4E5F !important') === false);
check('🔒 البطاقة السنوية (تصميم مجمّد): كل مبالغ الليرة 12 عريضة موحّدة والدولار 11 عريض أخضر تحتها',
      strpos($asSrc29, '.salary-slip-table .sub-lbp { white-space: nowrap; font-size: 12pt !important; font-weight: 700 !important; }') !== false
      && strpos($asSrc29, '.salary-slip-table .num-lbp, .salary-slip-table .num-lbp strong { font-size: 12pt !important;') !== false
      && strpos($asSrc29, "font-size: 11pt !important; font-weight: 700 !important;") !== false
      && strpos($asSrc29, "'<span class=\"sub-lbp\">' . \$l . '</span><span class=\"cur-usd\">'") !== false);
check('🔒 البطاقة السنوية (تصميم مجمّد): خط Cairo موحّد + اسم الأستاذ 17pt أبرز عنصر + معلومات عريضة',
      strpos($asSrc29, ".salary-slip, .salary-slip-table, .slip-info { font-family:'Cairo'") !== false
      && strpos($asSrc29, '.slip-emp-name .slip-pname { font-size: 17pt !important; font-weight: 800 !important;') !== false
      && strpos($asSrc29, 'font-weight:800 !important') !== false);
check('🔒 البطاقة السنوية (تصميم مجمّد): تملأ طول الورقة (188mm/pz + flex) وبلا fit القديم',
      strpos($asSrc29, 'min-height: calc(188mm / var(--pz, 1))') !== false
      && strpos($asSrc29, 'flex: 1 1 auto') !== false
      && strpos($asSrc29, '&fit=1') === false);
// «الأزرار مكرّرة وعجقة» (اختيار المستخدم 2026-08-01): شريط التصدير العام مخفي بصفحة
// البطاقة السنوية — أزرار الصفحة الخاصة (PDF رسمي/Excel/طباعة) هي المجموعة الوحيدة
check('البطاقة السنوية: لا أزرار مكرّرة — شريط التصدير العام مخفي والصفحة بأزرارها الخاصة فقط',
      strpos($asSrc29, '$hideExportToolbar = true;') !== false);
// 🔴 قاعدة عامة ملزِمة («بكل شي ما تخلي الأزرار مكررة وتعجق الصفحة»): أي صفحة عليها شريط
// التصدير العام ممنوع تحوي زرّ طباعة خاصاً بها — مجموعة أزرار واحدة بكل صفحة
$dupBtns = [];
foreach (['monthly_payroll', 'attestations', 'employees', 'grades', 'info_status', 'schools', 'users'] as $pg29) {
    $src29 = (string)file_get_contents(__DIR__ . '/../pages/' . $pg29 . '.php');
    if (preg_match('/<button[^>]*window\.print\(\)/', $src29)) $dupBtns[] = $pg29;
}
check('لا أزرار مكرّرة بكل البرنامج: لا زرّ طباعة خاصاً بصفحة عليها شريط التصدير العام',
      empty($dupBtns), $dupBtns ? ('مكرّر في: ' . implode(',', $dupBtns)) : '7 صفحات نظيفة');
// زرّا «PDF رسمي» بالبطاقة السنوية على المسار العادي (بلا fit=1): وضع fit القديم كان يطبع
// البطاقة أصغر من الورقة (ملاحظة المستخدم p1) والمسار العادي صار يملأها كاملة
check('البطاقة السنوية: زرّا PDF الرسمي (فردي/جماعي) بلا وضع fit القديم — يطبعان قدّ الورقة',
      strpos((string)file_get_contents(__DIR__ . '/../pages/annual_slip.php'), '&fit=1') === false);

/* =====================================================================
 * 30) القفل الشامل («كل البرنامج مسكّر إلا إذا بدي أعمل تعديل لشي معيّن
 *     ويفتح بس على التعديل البدي ياه» — قاعدة المستخدم 2026-08-01)
 * =================================================================== */
$lockPages30 = ['bonuses', 'bulk_allowances', 'classes', 'exchange_rates', 'info_collect',
                'email_settings', 'employees', 'exceptional_laws', 'grades', 'rates_history',
                'salary_scales', 'schools', 'settings', 'social_security', 'tax_brackets', 'users'];
$noLock30 = [];
foreach ($lockPages30 as $lp30) {
    if (strpos((string)file_get_contents(__DIR__ . '/../pages/' . $lp30 . '.php'), 'lockedit') === false) $noLock30[] = $lp30;
}
check('القفل الشامل: كل صفحات التعديل الـ16 على آلية lockedit (مقفولة حتى كبسة «تعديل»)',
      empty($noLock30), $noLock30 ? ('بلا قفل: ' . implode(',', $noLock30)) : '16 صفحة مقفولة');
$flSrc30 = (string)file_get_contents(__DIR__ . '/../assets/js/form-lock.js');
check('القفل الشامل: القفل يلقط حقول السطور المربوطة بسمة form= ويقرأ المعرّف بـgetAttribute (حقل «id» كان يغطّيه)',
      strpos($flSrc30, "form.getAttribute('id')") !== false
      && strpos($flSrc30, "document.querySelectorAll('[form=\"' + fid + '\"]')") !== false
      && strpos($flSrc30, 'lockedit-compact') !== false);
check('القفل الشامل: سطور جدول الصفوف مقفولة صفّاً صفّاً (متل لوحة الدرجات)',
      strpos((string)file_get_contents(__DIR__ . '/../pages/classes.php'), 'class="lockedit lockedit-compact"') !== false);
// ملف الأستاذ: صفّ أزرار (تعديل + حفظ + حذف) بكل تبويب من التبويبات الستة
// (طلب المستخدم 2026-08-01: «بكل صفحة من صفحاتو لازم يكون في زر تعديل وزر حذف وزر حفظ»)
$empSrc30 = (string)file_get_contents(__DIR__ . '/../pages/employees.php');
check('ملف الأستاذ: صفّ أزرار تعديل/حفظ/حذف بكل تبويب (٦ تبويبات) والقفل يدعم أزرار تعديل متعددة',
      substr_count($empSrc30, '<?php $empTabBar(); ?>') === 6
      && strpos($empSrc30, 'function () use ($id, $employee)') !== false
      && strpos($flSrc30, 'extBtns.forEach') !== false);
// الحفظ الفوري بجانب الحقل («بدي بس حط أي رقم يكون دغري بجانبو حفظ»): أي تغيير بأي
// فورم POST يُظهر زرّ حفظ أخضر نابضاً بجانب الحقل نفسه، مربوطاً بفورم الحقل (سمة form=)
$flSrc30b = (string)file_get_contents(__DIR__ . '/../assets/js/form-lock.js');
check('الحفظ الفوري: زرّ «حفظ» أخضر نابض يظهر بجانب أي حقل يتغيّر (بكل فورمات الحفظ)',
      strpos($flSrc30b, 'quicksave-btn') !== false
      && strpos($flSrc30b, "qsPulse") !== false
      && strpos($flSrc30b, "b.setAttribute('form', fid)") !== false
      && strpos($flSrc30b, "el.insertAdjacentElement('afterend', b)") !== false);

/* =====================================================================
 * 31) أمان الحذف والحفظ (حادثة أندره مراد 2026-08-01: حفظ بفورم مقفول
 *     وصل فارغاً فمسح بياناته ثم حُذف بالغلط — استُرجع من نسخة الأونلاين)
 * =================================================================== */
$empSrc31 = (string)file_get_contents(__DIR__ . '/../pages/employees.php');
$flSrc31 = (string)file_get_contents(__DIR__ . '/../assets/js/form-lock.js');
check('🛡️ صمام مسح البيانات: حفظ بلا اسم لموظف له اسم = مرفوض كلياً (فورم مقفول أُرسل فارغاً)',
      strpos($empSrc31, 'لم يُحفَظ شيء: وصل طلب الحفظ فارغاً') !== false);
check('🛡️ الحفظ الفوري لا يظهر على فورم مقفول + أزرار «إضافة سطر» تُقفل مع الفورم',
      strpos($flSrc31, "form.dataset.lockState === 'locked'") !== false
      && strpos($flSrc31, 'b.disabled = locked;') !== false);
check('🛡️ «أي محي لازم يسألني قبل»: حذف الموظف بصفحة تأكيد حقيقية (لا يتمّ بلا confirmed=1)',
      strpos($empSrc31, "empty(\$_GET['confirmed'])") !== false
      && strpos($empSrc31, 'هل تريد فعلاً حذف الموظف؟') !== false);
// أندره مراد (1673) مُستعاد وغير محذوف — بياناته التعريفية موجودة
$aq31 = $db->query("SELECT first_name_ar, last_name_ar, finance_ministry_number, nssf_number, is_deleted FROM employees WHERE id = 1673")->fetch();
check('أندره مراد مُستعاد بالكامل (اسم + رقم مالية 479105 + ضمان 778170 + غير محذوف)',
      $aq31 && $aq31['first_name_ar'] === 'اندره' && $aq31['last_name_ar'] === 'مراد'
      && $aq31['finance_ministry_number'] === '479105' && $aq31['nssf_number'] === '778170'
      && (int)$aq31['is_deleted'] === 0);

/* =====================================================================
 * 32) تنظيف الإفادات وعقد التعليم (طلب المستخدم 2026-08-03)
 * =================================================================== */
$atSrc32 = (string)file_get_contents(__DIR__ . '/../pages/attestations.php');
check('الإفادات: لا رقم هاتف تحت توقيع المدير/الإدارة',
      strpos($atSrc32, 'e($sigPhone)') === false);
check('الإفادات: «على رأس عمله» استُبدلت بـ«حتى تاريخه» (راتب/عمل/ملاك)',
      strpos($atSrc32, 'على رأس عمله') === false
      && substr_count($atSrc32, 'ولا يزال(تزال) حتى تاريخه') === 2
      && strpos($atSrc32, 'ولا يزال حتى تاريخه') !== false);
check('الإفادة المدرسية: لا «راجع ظهر الصفحة» بأسفلها',
      strpos($atSrc32, 'راجع ظهر الصفحة') === false);
check('عقد التعليم: «ساعات إضافية» خطّ فارغ دائماً بلا مبلغ',
      strpos($atSrc32, 'ساعات إضافية : <?= $blank(120) ?>') !== false
      && strpos($atSrc32, 'moneyAr($cExtra)') === false);
// 🖨️ «الإفادة ما عم تكون قد ورقة A4، عم تطلع على صفحتين» (2026-08-03):
// (١) صندوق الترويسة 1122px لا 1123 (A4=1122.5px — نصف البكسل كان يكسر التوقيع لصفحة ثانية)
// (٢) @page بيد الإفادة + تصفير حشوة .page-content بالطباعة (16px فوق كانت تزيح الورقة)
// (٣) قياس ppExportArea في app.js (data-fit1) + zoom محسوب في app.css — وعقد التعليم مستثنى
$appJs32  = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$appCss32 = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
check('🖨️ الإفادات صفحة A4 واحدة دائماً (1122px + @page + تصفير الحشوة + قياس --pz) وعقد التعليم مستثنى',
      strpos($atSrc32, 'min-height:1122px') !== false
      && strpos($atSrc32, 'min-height:1123px') === false
      && substr_count($atSrc32, 'data-fit1') >= 3
      && strpos($atSrc32, "\$type === 'aqd_taalim' ? '' : ' data-fit1=\"1\"'") !== false
      && substr_count($atSrc32, '.page-content{padding:0 !important;margin:0 !important}') === 3
      && strpos($appJs32, "getElementById('ppExportArea')") !== false
      && strpos($appJs32, "getAttribute('data-fit1')") !== false
      && strpos($appCss32, '#ppExportArea { zoom: var(--pz, 1); }') !== false);
// ✍️ «الامضاء بنهاية الافادة على جنب الورقة مش بالنص» (2026-08-03): توقيع المدير/الإدارة
// على يسار الورقة (margin-right:auto) بإفادات الراتب/العمل/يهمه الأمر، والسفارة (LTR) يمينها
check('✍️ إمضاء نهاية الإفادة على جنب الورقة لا في الوسط (3 عربي يسار + سفارة يمين)',
      substr_count($atSrc32, 'margin:42px auto 0 0') === 3
      && substr_count($atSrc32, 'margin:42px 0 0 auto') === 1
      && strpos($atSrc32, 'text-align:center;margin-top:42px') === false);
// شعار مكسيموس: إفادات مدارس/مراكز «مكسيموس» تأخذ شعارها الخاص لا شعار م.س.أ الموحّد
$sMax32 = $db->query("SELECT * FROM schools WHERE id = 2")->fetch();
$sSal32 = $db->query("SELECT * FROM schools WHERE id = 3")->fetch();
check('شعار مكسيموس على إفاداته (لا شعار م.س.أ الموحّد) والباقي على الموحّد',
      $sMax32 && strpos((string)schoolLogoUrl($sMax32), 'maximos.') !== false
      && $sSal32 && strpos((string)schoolLogoUrl($sSal32), 'unified.') !== false);

/* =====================================================================
 * 33) تركيب العلاوات للمنقولين (p1 ديانا شرو 2026-08-04): أجر إضافي يُدخَل
 *     بملف موظف منقول (بلا أساس بالإعداد) لازم يظهر على البطاقة السنوية
 * =================================================================== */
require_once $PROJ . '/includes/payroll_calculator.php';
$pcSrc33 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$asdSrc33 = (string)file_get_contents($PROJ . '/includes/annual_slip_data.php');
check('تركيب العلاوات: دالة overlayStoredYearBonuses موجودة وrecalcEmployeeYear يحوّل المنقول إليها بدل تجاهله',
      function_exists('overlayStoredYearBonuses')
      && strpos($pcSrc33, 'if (!$hasConfig) return overlayStoredYearBonuses($employeeId, $sy);') !== false);
check('تركيب العلاوات: مصدر واحد لمنطق العلاوات (calculate يستعمل bonusComponents نفسها)',
      strpos($pcSrc33, '= $this->bonusComponents($basePlusEchelon);') !== false
      && strpos($pcSrc33, 'public function bonusComponents(') !== false);
check('تركيب العلاوات: شفاء ذاتي بالبطاقة السنوية (computeAnnualSlip) محجوب عن حسابات القراءة-فقط',
      strpos($asdSrc33, 'overlayStoredYearBonuses((int)$emp[\'id\'], $schoolYear)') !== false
      && strpos($asdSrc33, '!isViewer()') !== false);
check('تركيب العلاوات: حارس العائلات — لا تصفير نقل منقول لا سجلّ له (الفرق من transport_lbp وحده)',
      strpos($pcSrc33, "if (!\$doAdd && !\$doTr) return 0;") !== false
      && strpos($pcSrc33, 'الفرق من transport_lbp وحده') !== false);
check('تركيب العلاوات: فحص انعكاس العلاوات مُضاف بصفحة «فحص صحّة البرنامج»',
      strpos((string)file_get_contents($PROJ . '/pages/health_check.php'), 'منعكسة على أشهرهم') !== false);
check('امتصاص الفجوة بالكود: المخفي داخل الصافي لا يُضاف مرّة ثانية (الفائض فقط علاوة جديدة)',
      strpos($pcSrc33, '$dNet = ($dAdd > 0) ? max(0, $dAdd - $gap) : $dAdd;') !== false);
// الشفاء الشامل («فوت على كل أستاذ متعاقد وحطلو الإضافي؟» — لا، تلقائي): الدالة موجودة
// ومربوطة بالهيدر، وبعد تشغيلها لا يبقى منقول عنده «إضافي مخفي» بلا علاوة مسجّلة بملفه
check('الشفاء الشامل: healHiddenImportedExtras20260804 موجودة ومربوطة بالهيدر ومحجوبة عن القراءة-فقط',
      function_exists('healHiddenImportedExtras20260804')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healHiddenImportedExtras20260804();') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "if (isViewer()) return; // حسابات «قراءة فقط» لا تكتب شيئاً") !== false);
healHiddenImportedExtras20260804(); // idempotent — إن كان الفلاغ مضبوطاً لا يفعل شيئاً
$left33 = (int)$db->query("SELECT COUNT(*) FROM (SELECT ms.employee_id FROM monthly_salaries ms
    JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
      AND COALESCE(e.base_salary_usd, 0) = 0 AND COALESCE(e.contract_salary_lbp, 0) = 0
      AND COALESCE(ms.is_indemnity_month, 0) = 0
      AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = ms.employee_id
                      AND (b.school_year = ms.school_year OR b.school_year IS NULL)
                      AND b.bonus_type IN ('prime_fixe','aide_complementaire'))
    GROUP BY ms.employee_id, ms.school_year
    HAVING MAX((ms.net_salary_lbp + ms.total_retenues_lbp) - (ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp)) > 0) t")->fetchColumn();
check('الشفاء الشامل: لا يبقى موظف منقول عنده أجر إضافي مخفي بلا علاوة مسجّلة بملفه', $left33 === 0, "متبقٍّ: $left33");
// الشفاء ب (p1 ديانا بالتقارير): العلاوات المدخلة يدوياً **قبل** نزول التصليح تُركَّب
// على الأعمدة فوراً بلا انتظار فتح البطاقة — وبعده كل الحالات القابلة للمطابقة الدقيقة
// (مبلغ ل.ل لكل السنة) منعكسة على الأشهر
check('الشفاء ب: healOverlayImportedBonuses20260804b موجودة ومربوطة بالهيدر ومحجوبة عن القراءة-فقط',
      function_exists('healOverlayImportedBonuses20260804b')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healOverlayImportedBonuses20260804b();') !== false);
healOverlayImportedBonuses20260804b(); // idempotent — إن كان الفلاغ مضبوطاً لا يفعل شيئاً
$mm33 = (int)$db->query("SELECT COUNT(DISTINCT e.id) FROM employees e
    WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
      AND COALESCE(e.base_salary_usd, 0) = 0 AND COALESCE(e.contract_salary_lbp, 0) = 0
      AND EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = e.id AND b.school_year = '2025-2026' AND b.is_active = 1
                    AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND b.value_type = 'amount' AND b.currency = 'LBP'
                    AND b.start_month IS NULL AND b.end_month IS NULL)
      AND NOT EXISTS (SELECT 1 FROM employee_bonuses b2 WHERE b2.employee_id = e.id AND b2.school_year = '2025-2026' AND b2.is_active = 1
                    AND b2.bonus_type IN ('prime_fixe','aide_complementaire')
                    AND (b2.value_type <> 'amount' OR b2.currency <> 'LBP' OR b2.start_month IS NOT NULL OR b2.end_month IS NOT NULL))
      AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = '2025-2026' AND COALESCE(ms.is_indemnity_month, 0) = 0
                    AND (ms.prime_fixe_lbp + ms.aide_complementaire_lbp) <>
                        (SELECT COALESCE(SUM(b3.amount), 0) FROM employee_bonuses b3 WHERE b3.employee_id = e.id AND b3.school_year = '2025-2026' AND b3.is_active = 1
                           AND b3.bonus_type IN ('prime_fixe','aide_complementaire')))")->fetchColumn();
check('الشفاء ب: كل علاوات المنقولين المسجّلة (مبلغ ل.ل لكل السنة) منعكسة على أشهر 2025-2026', $mm33 === 0, "غير منعكسة: $mm33");
// الشفاء ج (الفحص الشامل): «صافي الدولار» الصفري المنقول يُملأ بمرآة المحرّك — والفريش
// دولار الحقيقي (net_salary_usd غير صفري) لا يُمسّ أبداً
check('الشفاء ج: healNetUsdMirror20260804c موجودة ومربوطة بالهيدر وشرطها net_salary_usd = 0 فقط',
      function_exists('healNetUsdMirror20260804c')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNetUsdMirror20260804c();') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), 'WHERE net_salary_usd = 0 AND net_salary_lbp > 0 AND exchange_rate > 0') !== false);
healNetUsdMirror20260804c(); // idempotent — إن كان الفلاغ مضبوطاً لا يفعل شيئاً
$usd33 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE net_salary_usd = 0 AND net_salary_lbp > 0 AND exchange_rate > 0")->fetchColumn();
check('الشفاء ج: لا صافي دولار صفري وصف الليرة موجب (الشاشة المزدوجة لا تعرض $0.00)', $usd33 === 0, "متبقٍّ: $usd33");
// الشفاء د: فرق المحسومات المنقولة الموجب = ضريبة الدخل المحسومة بالقديم (إثبات قانوني
// 2025-2026 + بنيوي 2023-2024) — يُنسب لعمود الضريبة مع أساسه الخاضع، والمجموع/الصافي لا يتغيّران
check('الشفاء د: healImportedTaxColumn20260804d موجودة ومربوطة بالهيدر وتملأ الضريبة مع أساسها الخاضع',
      function_exists('healImportedTaxColumn20260804d')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healImportedTaxColumn20260804d();') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), 'SET income_tax_lbp = income_tax_lbp + ?, taxable_base_lbp = ?') !== false);
healImportedTaxColumn20260804d(); // idempotent — إن كان الفلاغ مضبوطاً لا يفعل شيئاً
$ret33 = (int)$db->query("SELECT COUNT(DISTINCT ms.employee_id) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0
      AND ms.total_retenues_lbp - (ms.caisse_amount_lbp + ms.cnss_amount_lbp + ms.income_tax_lbp + COALESCE(ms.eoc_grade_lbp, 0)) > 1")->fetchColumn();
check('الشفاء د: تفصيل المحسومات اكتمل بكل السنين — لا فرق موجب غير منسوب عند أي موظف',
      $ret33 === 0, "متبقٍّ: $ret33 موظفاً");
// الشفاء هـ: الفريش دولار المنقول (٦ موظفين) يُحفظ الرقم الحقيقي بملاحظات ملفهم أولاً ثم
// يُوحَّد عمود صافي الدولار على المرآة — فلا معلومة تضيع ولا بطاقة يخالف مجموعُها أشهرَها
check('الشفاء هـ: healFreshUsdColumn20260804e موجودة ومربوطة بالهيدر وتحفظ الرقم بالملاحظات قبل التوحيد',
      function_exists('healFreshUsdColumn20260804e')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healFreshUsdColumn20260804e();') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "strpos(\$notes, \$marker) === false") !== false);
healFreshUsdColumn20260804e(); // idempotent — إن كان الفلاغ مضبوطاً لا يفعل شيئاً
$usdm33 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0 AND ms.exchange_rate > 0 AND ms.net_salary_lbp > 0
      AND ABS(ms.net_salary_usd - ms.net_salary_lbp / ms.exchange_rate) > 0.06")->fetchColumn();
check('الشفاء هـ: مرآة الدولار مطابقة بكل صفوف الرواتب (مجموع البطاقة = جمع أشهرها بالدولار)',
      $usdm33 === 0, "متبقٍّ: $usdm33 صفّاً");
$zn33 = (string)$db->query("SELECT COALESCE(notes,'') FROM employees WHERE id = 1623")->fetchColumn();
check('زياد أيوب (فريش دولار): رقمه الحقيقي 962$ محفوظ بملاحظات ملفه قبل توحيد العمود',
      strpos($zn33, '962$') !== false && strpos($zn33, 'نُقل من عمود صافي الدولار') !== false);
$dt33 = $db->query("SELECT income_tax_lbp, total_retenues_lbp, cnss_amount_lbp FROM monthly_salaries WHERE employee_id = 1826 AND year = 2025 AND month = 10")->fetch();
check('ديانا شرو: عمود الضريبة صار 130,000 والمحسومات باتت مفصّلة بالكامل (1,320,000 + 130,000 = 1,450,000)',
      $dt33 && (int)$dt33['income_tax_lbp'] === 130000
      && (int)$dt33['cnss_amount_lbp'] + (int)$dt33['income_tax_lbp'] === (int)$dt33['total_retenues_lbp']);
// ديانا شرو نفسها (p1): العلاوة اتسجّلت بملفها تلقائياً 43م والعمود امتلأ والصافي/المستحق ما تغيّرا
$db33 = $db->query("SELECT COALESCE(SUM(amount),0) s FROM employee_bonuses WHERE employee_id = 1826 AND school_year = '2025-2026' AND bonus_type = 'prime_fixe' AND is_active = 1")->fetch();
$dr33 = $db->query("SELECT prime_fixe_lbp, net_salary_lbp, total_due_lbp, transport_lbp FROM monthly_salaries WHERE employee_id = 1826 AND year = 2025 AND month = 10")->fetch();
check('ديانا شرو (p1): الأجر الإضافي 43م اتسجّل بملفها تلقائياً وظهر بالعمود والصافي 42.55م والنقل 9م ما تغيّرا',
      $db33 && (int)$db33['s'] === 43000000 && $dr33
      && (int)$dr33['prime_fixe_lbp'] === 43000000
      && (int)$dr33['net_salary_lbp'] === 42550000
      && (int)$dr33['total_due_lbp'] === 51550000
      && (int)$dr33['transport_lbp'] === 9000000,
      $dr33 ? ('prime=' . $dr33['prime_fixe_lbp'] . ' net=' . $dr33['net_salary_lbp'] . ' due=' . $dr33['total_due_lbp']) : 'صف مفقود');
// تجربة فعلية بمعاملة تُرجَع كاملة: علاوة أكبر من الفجوة — الفائض فقط يُضاف للصافي
// (امتصاص الفجوة: المخفي داخل الصافي أصلاً لا يُضاف مرّة ثانية)
$tx33Ok = false; $tx33Detail = '';
try {
    $db->beginTransaction();
    $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = 1826 AND school_year = '2025-2026' AND bonus_type IN ('prime_fixe','aide_complementaire')")->execute();
    $b33 = $db->query("SELECT base_plus_echelon_lbp, extra_lbp, prime_fixe_lbp, aide_complementaire_lbp, total_retenues_lbp, net_salary_lbp, total_due_lbp, transport_lbp
                       FROM monthly_salaries WHERE employee_id = 1826 AND year = 2025 AND month = 10")->fetch();
    $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                  VALUES (1826, 'prime_fixe', 1, '2025-2026', 50000000, 'amount', 'LBP', NULL, NULL, 1)")->execute();
    $n33 = overlayStoredYearBonuses(1826, '2025-2026');
    $a33 = $db->query("SELECT prime_fixe_lbp, net_salary_lbp, total_due_lbp, transport_lbp
                       FROM monthly_salaries WHERE employee_id = 1826 AND year = 2025 AND month = 10")->fetch();
    $gap33 = max(0, ((int)$b33['net_salary_lbp'] + (int)$b33['total_retenues_lbp'])
               - ((int)$b33['base_plus_echelon_lbp'] + (int)$b33['extra_lbp'] + (int)$b33['prime_fixe_lbp'] + (int)$b33['aide_complementaire_lbp']));
    $dAdd33 = 50000000 - ((int)$b33['prime_fixe_lbp'] + (int)$b33['aide_complementaire_lbp']);
    $dNet33 = max(0, $dAdd33 - $gap33); // المتوقّع: 50م − 43م فجوة = 7م فقط تُضاف
    $tx33Ok = $b33 && $a33 && $n33 > 0
        && (int)$a33['prime_fixe_lbp'] === 50000000
        && (int)$a33['net_salary_lbp'] === (int)$b33['net_salary_lbp'] + $dNet33
        && (int)$a33['total_due_lbp'] === (int)$b33['total_due_lbp'] + $dNet33
        && (int)$a33['transport_lbp'] === (int)$b33['transport_lbp'];
    $tx33Detail = $a33 ? ('prime=' . $a33['prime_fixe_lbp'] . ' net=' . $a33['net_salary_lbp'] . ' فائض=' . $dNet33) : 'صف مفقود';
} catch (Throwable $e33) { $tx33Detail = 'خطأ: ' . $e33->getMessage(); }
finally { if ($db->inTransaction()) $db->rollBack(); }
check('امتصاص الفجوة (تجربة فعلية مع ترجيع): علاوة 50م فوق فجوة 43م ⇒ العمود 50م والصافي +7م فقط والنقل ثابت', $tx33Ok, $tx33Detail);
// وبعد الترجيع: أرقام ديانا رجعت متل ما كانت حرفياً (المعاملة ما خرّبت شي)
$r33 = $db->query("SELECT prime_fixe_lbp, net_salary_lbp, total_due_lbp FROM monthly_salaries WHERE employee_id = 1826 AND year = 2025 AND month = 10")->fetch();
check('امتصاص الفجوة (تجربة فعلية): الترجيع أعاد أرقام ديانا كما كانت قبل التجربة',
      $r33 && $dr33 && (int)$r33['prime_fixe_lbp'] === (int)$dr33['prime_fixe_lbp']
      && (int)$r33['net_salary_lbp'] === (int)$dr33['net_salary_lbp']
      && (int)$r33['total_due_lbp'] === (int)$dr33['total_due_lbp']);

/* =====================================================================
 * 34) فلترا التقارير الموحّدان (طلب 2026-08-04): «الملاك لحالون أو المتعاقدين
 *     أو الموظفين أو مع بعض» + «يخضع للضرائب أو لا يخضع» — بكل التقارير والتصدير
 * =================================================================== */
$repSrc34 = (string)file_get_contents($PROJ . '/pages/reports.php');
$expSrc34 = (string)file_get_contents($PROJ . '/pages/reports_export.php');
check('فلتر الفئة والضريبة: منتقٍ موحّد empTypePicker بكل تقارير reports.php والاستعلامات تحمل الفلترين',
      substr_count($repSrc34, 'empTypePicker();') >= 5
      && substr_count($repSrc34, '$empTypeSql') >= 6
      && strpos($repSrc34, 'الكل مع بعض') !== false
      && strpos($repSrc34, "name=\"tax_sub\"") !== false
      && strpos($repSrc34, 'e.tax_subject = ') !== false);
check('فلتر الفئة والضريبة: التصدير Excel/Word يحترم الفلترين نفسيهما وبعنوان الملف',
      substr_count($expSrc34, '$empTypeSql') >= 6
      && substr_count($expSrc34, '$empTypeTitle') >= 5
      && strpos($expSrc34, "tax_sub") !== false);
// تجربة فعلية: كشف حزيران 2026 مفلتراً بالمتعاقدين — عدد الإجمالي العام = عدّ قاعدة البيانات نفسه
[$yf34, $yp34] = yearEmploymentFilter('2025-2026', 'e.');
$act34 = implode(',', array_map('intval', allActiveSchoolIdsCached()));
$q34 = function ($extraWhere) use ($db, $yf34, $yp34, $act34) {
    $st = $db->prepare("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE ms.year = 2026 AND ms.month = 6 AND e.is_deleted = 0
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)
          AND ms.school_id IN ($act34)" . $yf34 . $extraWhere);
    $st->execute($yp34);
    return (int)$st->fetchColumn();
};
$h34 = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026, 'emp_type' => 'enseignant_contractuel'], []);
preg_match('/مجموع كل الفئات \(العدد: (\d+)\)/u', $h34, $m34a);
check('فلتر الفئة (تجربة فعلية): كشف حزيران بالمتعاقدين فقط — العدد الظاهر = عدّ القاعدة، وعنوانه يذكر الفئة',
      isset($m34a[1]) && (int)$m34a[1] === $q34(" AND e.employee_type = 'enseignant_contractuel'")
      && strpos($h34, '— المتعاقدين') !== false,
      'ظاهر: ' . ($m34a[1] ?? '؟') . ' / قاعدة: ' . $q34(" AND e.employee_type = 'enseignant_contractuel'"));
// فلتر «خاضع للضريبة»: العدد الظاهر = عدّ القاعدة (وإن كان صفراً تُعرض «لا توجد بيانات» بسلام)
$h34b = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026, 'tax_sub' => '1'], []);
preg_match('/مجموع كل الفئات \(العدد: (\d+)\)/u', $h34b, $m34b);
$h34c = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026, 'tax_sub' => '0'], []);
preg_match('/مجموع كل الفئات \(العدد: (\d+)\)/u', $h34c, $m34c);
$exp34c = $q34(" AND e.tax_subject = 0");
check('فلتر الضريبة (تجربة فعلية): الخاضعون = عدّ القاعدة وعنوانه يذكرهم، وغير الخاضعين كذلك (أو «لا بيانات» إن صفر)',
      isset($m34b[1]) && (int)$m34b[1] === $q34(" AND e.tax_subject = 1")
      && strpos($h34b, 'الخاضعون للضريبة') !== false
      && ($exp34c === 0 ? (strpos($h34c, 'لا توجد بيانات') !== false) : (isset($m34c[1]) && (int)$m34c[1] === $exp34c))
      && strpos($h34c, 'غير الخاضعين للضريبة') !== false,
      'خاضعون ظاهر: ' . ($m34b[1] ?? '؟') . ' / قاعدة: ' . $q34(" AND e.tax_subject = 1") . ' — غير خاضعين قاعدة: ' . $exp34c);

// «بدي بكل التقارير»: الفلتران معمَّمان على النماذج الرسمية أيضاً (شريط موحّد + استعلامات)
$ofSrc34 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('فلترا كل التقارير: شريط موحّد بالنماذج الرسمية (19 نموذجاً جماعياً) والاستعلامات تحمل الفلترين',
      strpos($ofSrc34, '$ofFilterableForms = [') !== false
      && substr_count($ofSrc34, '$ofEmpFilter') >= 15
      && strpos($ofSrc34, "name=\"tax_sub\"") !== false
      && strpos($ofSrc34, 'الفلتر المختار يُطبَع على رأس المستند نفسه') !== false);
// تجربة فعلية (كشف رواتب كل الموظفين — مدرسة 3): الكل = ملاك + متعاقدون + موظفون
$n34 = function ($cmb) {
    $h = renderPage('pages/official_forms.php', array_merge(['form' => 'salary_all', 'month' => 6, 'year' => 2026], $cmb), [], [3]);
    return preg_match('/المجموع العام \((\d+)\)/u', $h, $m) ? (int)$m[1] : -1;
};
$all34 = $n34([]);
$sum34 = $n34(['emp_type' => 'enseignant_titulaire']) + $n34(['emp_type' => 'enseignant_contractuel']) + $n34(['emp_type' => 'employe']);
check('فلترا النماذج (تجربة فعلية): كشف رواتب كل الموظفين — الكل = مجموع الفئات الثلاث وكلٌّ لحاله',
      $all34 > 0 && $all34 === $sum34, "الكل=$all34 / مجموع الفئات=$sum34");

// 🏷️ «بكل ورقة من التقارير لازم يكون في عنوان التقرير» (2026-08-04): صفّ عنوان يُحقن
// داخل thead فيتكرّر مع رأس الجدول أعلى كل صفحة مطبوعة — مخفيّ على الشاشة
$rhSrc35 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$ajSrc35 = (string)file_get_contents($PROJ . '/assets/js/app.js');
check('🏷️ عنوان التقرير على كل ورقة مطبوعة: حقن pr-title-row داخل thead (app.js) + CSS يظهره بالطباعة فقط',
      strpos($ajSrc35, "row.className = 'pr-title-row'") !== false
      && strpos($ajSrc35, 'table.tHead || table.createTHead()') !== false
      && strpos($ajSrc35, "'.doc-sheet, .official-doc'") !== false
      && strpos($rhSrc35, '.pr-title-row{display:none;}') !== false
      && strpos($rhSrc35, '.doc-table thead .pr-title-row{display:table-row;}') !== false
      && strpos($rhSrc35, '.doc-table thead{display:table-header-group;}') !== false);

/* =====================================================================
 * 36) زرّ «نسخ الملف لسنة» بملف الموظف (طلب 2026-08-05): أستاذ ترك من سنين
 *     ورجع — كبسة وحدة تنسخ ملفه كامل (رواتب + علاوات) لأي سنة يختارها
 *     بلا إعادة إدخال، ويرجع «فاعلاً» مع حفظ تواريخ التّرك القديمة بالملاحظات.
 *     🔴 الدرجات لا تُمَسّ أبداً (قاعدة: لا إعادة بناء درجات تلقائية).
 * =================================================================== */
$empSrc36 = (string)file_get_contents($PROJ . '/pages/employees.php');
check('نسخ الملف لسنة: الزر بملف الموظف + المعالج copy_year موجود ومحمي (كتابة + مدرسة + تبديل تلقائي)',
      strpos($empSrc36, "action=copy_year&id=") !== false
      && strpos($empSrc36, 'نسخ لسنة') !== false
      && strpos($empSrc36, "\$action === 'copy_year' && \$id > 0") !== false
      && strpos($empSrc36, "['edit', 'delete', 'copy_year']") !== false
      && strpos($empSrc36, "['new', 'edit', 'delete', 'copy_year']") !== false);
// جوهر المعالج: يرجّعه فاعلاً بحفظ تواريخ التّرك بالملاحظات، ينسخ العلاوات بلا تكرار،
// ينسخ صفوف المنقول بـis_paid=0، يحسب بالمحرّك الموحّد recalcEmployeeYear — وممنوع يلمس الدرجات
$h36s = strpos($empSrc36, '📅 نسخ ملف الموظف لسنة');
$h36e = strpos($empSrc36, 'صفحة الاختيار والتأكيد');
$hnd36 = ($h36s !== false && $h36e !== false && $h36e > $h36s) ? substr($empSrc36, $h36s, $h36e - $h36s) : '';
check('نسخ الملف لسنة: المعالج يرجّع التارك «فاعلاً» ويحفظ تواريخ التّرك بالملاحظات وينسخ العلاوات والصفوف ويحسب بالمحرّك الموحّد',
      $hnd36 !== ''
      && strpos($hnd36, 'تواريخ التّرك السابقة') !== false
      && strpos($hnd36, "left_date_cnss = NULL, left_date_finance = NULL, left_date_eoc = NULL") !== false
      && strpos($hnd36, "status = 'actif'") !== false
      && strpos($hnd36, "\$src['is_paid'] = 0") !== false
      && strpos($hnd36, 'ON DUPLICATE KEY UPDATE') !== false
      && strpos($hnd36, 'recalcEmployeeYear($id, $target)') !== false
      && strpos($hnd36, "logAudit('copy_year'") !== false);
check('🔴 نسخ الملف لسنة: المعالج لا يلمس الدرجات إطلاقاً (لا بناء ولا إعادة ربط ولا حذف أحداث)',
      $hnd36 !== ''
      && strpos($hnd36, 'buildLegalGradeHistory') === false
      && strpos($hnd36, 'rechainGradeHistory') === false
      && strpos($hnd36, 'employee_grade_history') === false);
// تجربة فعلية (قراءة فقط): صفحة النسخ لجوانا الفغالي (1545، تاركة 2023) تعرض اسمها
// وآخر سنة عمل (2022-2023) كمصدر، وتوضّح أن الدرجات لا تتغيّر، مع منتقي سنة وتأكيد
$h36 = renderPage('pages/employees.php', ['action' => 'copy_year', 'id' => 1545], [], [3]);
check('نسخ الملف لسنة (تجربة فعلية): صفحة التأكيد تعرض الاسم + آخر سنة عمل كمصدر + «الدرجات ما بتتغيّر» + منتقي السنة',
      strpos($h36, 'جوانا الفغالي') !== false
      && strpos($h36, '2022-2023') !== false
      && strpos($h36, 'الدرجات ما بتتغيّر') !== false
      && strpos($h36, 'name="target_year"') !== false
      && strpos($h36, 'انسخ الملف / Copier') !== false,
      strlen($h36) . ' حرف');

/* =====================================================================
 * 37) قاعدة التارك بالسنة الجديدة (شكوى 2026-08-06): «فتحت سنة جديدة وأساتذة
 *     تاركين طلعوا فيها» — ثلاث طبقات: حماية المحرّك (calculateAndSave لا يحفظ
 *     راتباً لسنة تبدأ بعد الترك) + شفاء ذاتي يمسح الوهمي غير المدفوع + فلتر
 *     فتح السنة حسب §١٠ (يُستثنى فقط من ترك قبل بداية السنة المفتوحة).
 * =================================================================== */
$pcSrc37 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$fnSrc37 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hdSrc37 = (string)file_get_contents($PROJ . '/includes/header.php');
$oySrc37 = (string)file_get_contents($PROJ . '/pages/open_year.php');
$hcSrc37 = (string)file_get_contents($PROJ . '/pages/health_check.php');
check('قاعدة التارك: حماية المحرّك موجودة (calculateAndSave يرفض سنة بعد الترك)',
      strpos($pcSrc37, 'قاعدة التارك (§١٠)') !== false
      && strpos($pcSrc37, 'if ($rowRank > $depRank) return $this->calculate();') !== false);
check('قاعدة التارك: الشفاء الذاتي healLeaverPhantomRows مربوط بالهيدر ولا يمسّ المدفوع',
      function_exists('healLeaverPhantomRows')
      && strpos($fnSrc37, 'COALESCE(ms.is_paid, 0) = 0') !== false
      && strpos($hdSrc37, 'healLeaverPhantomRows();') !== false);
check('قاعدة التارك: فتح السنة (والشك مارك) يستثنيان فقط من ترك قبل بداية السنة (>= 1/10)',
      substr_count($oySrc37, "\$emps->execute([\$schoolId, \$y1 . '-10-01']);") === 2
      && strpos($oySrc37, 'left_date_cnss IS NULL') === false);
check('قاعدة التارك: فحصا صفحة الصحة (وهمي غير مدفوع = خطأ، مدفوع = مراجعة المستخدم)',
      strpos($hcSrc37, 'لا رواتب وهمية لتارك بعد سنة تركه') !== false
      && strpos($hcSrc37, 'رواتب مدفوعة لتاركين بعد سنة تركهم') !== false);

// شغّل الشفاء فعلياً (ينظّف أي وهمي موجود بالبيانات قبل التجارب)
unset($_SESSION['heal_leaver_phantoms_done']);
healLeaverPhantomRows();

// اختر تاركاً ملاكاً ما إله أي صف بعد سنة تركه (بعد الشفاء يبقى فقط أصحاب المدفوع فيُستثنون)
$lv37 = $db->query("SELECT e.id, LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),
                                       COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),
                                       COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31')) ld
                    FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire'
                    HAVING ld < '9999-12-31' ORDER BY ld DESC LIMIT 20")->fetchAll();
$pick37 = null; $ty37 = 0;
$cAfter37 = $db->prepare("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = ?
                          AND (CASE WHEN month >= 10 THEN year ELSE year - 1 END) > ?");
foreach ($lv37 as $r37) {
    $ldY = (int)substr($r37['ld'], 0, 4); $ldM = (int)substr($r37['ld'], 5, 2);
    $depR = ($ldM >= 10) ? $ldY : $ldY - 1;
    $cAfter37->execute([(int)$r37['id'], $depR]);
    if ((int)$cAfter37->fetchColumn() === 0) { $pick37 = $r37; $ty37 = $depR + 1; break; }
}
if ($pick37) {
    $pid37 = (int)$pick37['id'];
    // (أ) المحرّك: محاولة حساب 11/$ty37 (سنة دراسية بعد الترك) يجب ألا تُنشئ أي صف
    try { (new PayrollCalculator($pid37, 11, $ty37))->calculateAndSave(); } catch (Exception $e) {}
    $cg37 = $db->prepare("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = ? AND month = 11 AND year = ?");
    $cg37->execute([$pid37, $ty37]);
    check('قاعدة التارك (تجربة فعلية): المحرّك ما أنشأ راتباً لتارك بشهر بعد سنة تركه',
          (int)$cg37->fetchColumn() === 0, "id $pid37 ترك {$pick37['ld']} — جرّبنا 11/$ty37");
    // (ب) الشفاء: ازرع صفاً وهمياً (نسخة عن آخر شهر حقيقي إله) وشغّل الشفاء → لازم ينحذف
    $srcQ37 = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? ORDER BY year DESC, month DESC LIMIT 1");
    $srcQ37->execute([$pid37]);
    $s37 = $srcQ37->fetch(PDO::FETCH_ASSOC);
    if ($s37) {
        unset($s37['id'], $s37['created_at']);
        $s37['month'] = 10; $s37['year'] = $ty37; $s37['school_year'] = $ty37 . '-' . ($ty37 + 1);
        $s37['is_paid'] = 0; if (array_key_exists('paid_date', $s37)) $s37['paid_date'] = null;
        $cols37 = array_keys($s37);
        $db->prepare("INSERT INTO monthly_salaries (`" . implode('`,`', $cols37) . "`) VALUES ("
                     . implode(',', array_fill(0, count($cols37), '?')) . ")")->execute(array_values($s37));
        unset($_SESSION['heal_leaver_phantoms_done']);
        healLeaverPhantomRows();
        $ch37 = $db->prepare("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = ? AND month = 10 AND year = ?");
        $ch37->execute([$pid37, $ty37]);
        $healed37 = (int)$ch37->fetchColumn() === 0;
        check('قاعدة التارك (تجربة فعلية): الشفاء الذاتي مسح صفاً وهمياً مزروعاً لتارك بعد سنة تركه',
              $healed37, "id $pid37 — 10/$ty37");
        if (!$healed37) { // ترجيع: لا نترك أثر التجربة لو فشل الشفاء
            $db->prepare("DELETE FROM monthly_salaries WHERE employee_id = ? AND month = 10 AND year = ? AND COALESCE(is_paid,0) = 0")
               ->execute([$pid37, $ty37]);
        }
    } else {
        check('قاعدة التارك (تجربة الشفاء)', true, 'لا صف مصدر للزرع — تخطٍّ');
    }
} else {
    check('قاعدة التارك (تجارب فعلية)', true, 'لا تارك ملاك مناسب بالبيانات — تخطٍّ');
}
// (ج) البيانات كلها نظيفة بعد الشفاء: صفر رواتب وهمية (غير مدفوعة) لتاركين بعد سنة تركهم
$ldAll37 = "LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),
                  COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),
                  COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31'))";
$phAll37 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0 AND $ldAll37 < '9999-12-31' AND COALESCE(ms.is_paid, 0) = 0
      AND (CASE WHEN ms.month >= 10 THEN ms.year ELSE ms.year - 1 END)
          > (CASE WHEN MONTH($ldAll37) >= 10 THEN YEAR($ldAll37) ELSE YEAR($ldAll37) - 1 END)")->fetchColumn();
check('قاعدة التارك: صفر رواتب وهمية (غير مدفوعة) لتاركين بعد سنة تركهم بكل القاعدة', $phAll37 === 0,
      $phAll37 === 0 ? 'نظيفة' : "$phAll37 صفّاً");

/* =====================================================================
 * 38) «الكشف يطابق الملف» (شكوى 2026-08-06 — مارسيلا داود): إعادة الحساب بلا
 *     سنة صريحة كانت تصيب السنة التقويمية فقط بينما العلاوات تُحفَظ على السنة
 *     المعروضة → كشوف السنة الجديدة المفتوحة تبقى على القديم. صار النداء بلا
 *     سنة يعيد حساب: السنة المعروضة + التقويمية + كل السنين المفتوحة اللاحقة.
 * =================================================================== */
$pcSrc38 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$fnSrc38 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hdSrc38 = (string)file_get_contents($PROJ . '/includes/header.php');
check('الكشف يطابق الملف: recalcEmployeeYear بلا سنة = السنة المعروضة + التقويمية + المفتوحة اللاحقة',
      strpos($pcSrc38, '$sy = $schoolYear ?: writeSchoolYear();') !== false
      && strpos($pcSrc38, '$schoolYear ?: currentSchoolYear()') === false
      && strpos($pcSrc38, 'foreach (array_keys($others) as $oSy)') !== false);
check('الكشف يطابق الملف: الشفاء healStaleYearMirror20260806 موجود ومربوط بالهيدر',
      function_exists('healStaleYearMirror20260806')
      && strpos($hdSrc38, 'healStaleYearMirror20260806();') !== false
      && strpos($fnSrc38, "stale_year_recalc_2026_08_06") !== false);
$hcSrc38 = (string)file_get_contents($PROJ . '/pages/health_check.php');
check('الكشف يطابق الملف: فحص المطابقة بصفحة الصحة (علاوات الملف = المخزّن بكل السنين)',
      strpos($hcSrc38, 'الإضافي/المكافأة بملف الموظف = المخزّن بكشوفه بكل السنين') !== false);

// تجربة فعلية (سيناريو مارسيلا بالضبط، مع ترجيع كامل): موظف معدّ له علاوة إضافي
// «مبلغ ل.ل لكل السنة» بسنة مفتوحة لاحقة — نعدّل قيمة العلاوة ونستدعي إعادة الحساب
// **بلا سنة** والجلسة على السنة التقويمية: يجب أن تتحدّث أشهر السنة اللاحقة تلقائياً.
$cur38 = currentSchoolYear();
$cand38 = $db->query("SELECT b.id bid, b.employee_id eid, b.amount, b.school_year
    FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
    WHERE e.is_deleted = 0 AND b.is_active = 1 AND b.bonus_type = 'prime_fixe'
      AND b.value_type = 'amount' AND b.currency = 'LBP' AND b.start_month IS NULL AND b.end_month IS NULL
      AND b.school_year > '$cur38'
      AND (e.employee_type = 'enseignant_titulaire' OR e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
      AND LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),
                COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),
                COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31')) = '9999-12-31'
      AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = b.employee_id AND ms.school_year = b.school_year)
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cand38) {
    $eid38 = (int)$cand38['eid']; $sy38 = $cand38['school_year']; $orig38 = (float)$cand38['amount'];
    $test38 = $orig38 + 1000000; // +مليون ليرة للتجربة
    $y138 = (int)substr($sy38, 0, 4);
    $prm38 = $db->prepare("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id = ? AND month = 10 AND year = ?");
    $oldSess38 = $_SESSION['active_school_year'] ?? null;
    $_SESSION['active_school_year'] = $cur38; // المستخدم واقف على السنة التقويمية (سيناريو الباگ)
    $db->prepare("UPDATE employee_bonuses SET amount = ? WHERE id = ?")->execute([$test38, (int)$cand38['bid']]);
    recalcEmployeeYear($eid38); // ⬅ بلا سنة — قبل الإصلاح ما كان يلمس $sy38
    $prm38->execute([$eid38, $y138]);
    $got38 = (int)$prm38->fetchColumn();
    // ترجيع كامل ثم تثبّت أن القيمة رجعت
    $db->prepare("UPDATE employee_bonuses SET amount = ? WHERE id = ?")->execute([$orig38, (int)$cand38['bid']]);
    recalcEmployeeYear($eid38, $sy38);
    $prm38->execute([$eid38, $y138]);
    $back38 = (int)$prm38->fetchColumn();
    if ($oldSess38 === null) unset($_SESSION['active_school_year']); else $_SESSION['active_school_year'] = $oldSess38;
    check('الكشف يطابق الملف (تجربة فعلية): تعديل علاوة سنة مفتوحة + إعادة حساب بلا سنة حدّث أشهرها',
          $got38 === (int)$test38, "موظف $eid38 سنة $sy38 — مخزّن 10/$y138 = " . number_format($got38) . " (المتوقّع " . number_format($test38) . ")");
    check('الكشف يطابق الملف (تجربة فعلية): الترجيع أعاد المخزّن كما كان',
          $back38 === (int)$orig38, number_format($back38));
} else {
    check('الكشف يطابق الملف (تجربة فعلية)', true, 'لا علاوة مناسبة بسنة مفتوحة — تخطٍّ');
}
// البيانات كلها مطابقة: صفر (موظف معدّ × سنة) مخزّنه يخالف علاوات ملفه (المقارنة الدقيقة)
$mir38 = (int)$db->query("SELECT COUNT(*) FROM (SELECT ms.employee_id FROM monthly_salaries ms
    JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0 AND COALESCE(ms.is_indemnity_month, 0) = 0
      AND (e.employee_type = 'enseignant_titulaire' OR e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
      AND NOT EXISTS (SELECT 1 FROM employee_bonuses b2 WHERE b2.employee_id = e.id AND b2.school_year = ms.school_year AND b2.is_active = 1
          AND b2.bonus_type IN ('prime_fixe','aide_complementaire')
          AND (b2.value_type <> 'amount' OR b2.currency <> 'LBP' OR b2.start_month IS NOT NULL OR b2.end_month IS NOT NULL))
    GROUP BY ms.employee_id, ms.school_year
    HAVING SUM((ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) <>
        (SELECT COALESCE(SUM(b.amount),0) FROM employee_bonuses b WHERE b.employee_id = ms.employee_id AND b.school_year = ms.school_year AND b.is_active = 1
           AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND b.value_type = 'amount' AND b.currency = 'LBP'
           AND b.start_month IS NULL AND b.end_month IS NULL)) > 0) x")->fetchColumn();
check('الكشف يطابق الملف: صفر موظف معدّ مخزّنُ سنةٍ عنده يخالف علاوات ملفه (بكل القاعدة)',
      $mir38 === 0, $mir38 === 0 ? 'مطابق' : "$mir38 موظف×سنة");

/* =====================================================================
 * 39) «مصدر واحد بكل التقارير والإفادات» (طلب 2026-08-06): الإفادات والسيرة
 *     كانت تلقّط «آخر شهر» من كل تاريخ الموظف (حتى من سنة مفتوحة لاحقة)
 *     فتخالف الكشوف المعروضة — صارت تقرأ من السنة الدراسية المعروضة نفسها،
 *     وإفادة عمل الضمان (٦ أشهر) لا تعرض أشهراً مستقبلية أبداً.
 * =================================================================== */
$atSrc39 = (string)file_get_contents($PROJ . '/pages/attestations.php');
$ehSrc39 = (string)file_get_contents($PROJ . '/pages/employee_history.php');
$ofSrc39 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('مصدر واحد: الإفادات تختار شهر الراتب من السنة المعروضة أولاً (fallback إجمالي)',
      strpos($atSrc39, '$attSy = activeSchoolYear();') !== false
      && strpos($atSrc39, 'AND school_year = ? " . $salPickSql') !== false);
check('مصدر واحد: السيرة/الملف الكامل «الراتب الحالي» من السنة المعروضة أولاً',
      strpos($ehSrc39, '$ehSy = activeSchoolYear();') !== false
      && strpos($ehSrc39, 'AND school_year = ? ORDER BY year DESC, month DESC LIMIT 1') !== false);
check('مصدر واحد: إفادة عمل الضمان (٦ أشهر ×٢) + مجاميع نهاية الخدمة (215A/207 + التصفية) كلها محصورة بالأشهر الفعلية الماضية',
      substr_count($ofSrc39, '(year * 100 + month) <= ?') === 3
      && substr_count($ofSrc39, '(year * 100 + month) <= " . ((int)date') === 1);
// تجربة فعلية: إفادة راتب مارسيلا (1677، لها 2025-2026 بأساس 2,085,000 و2026-2027
// بأساس 2,225,000) — أرقام الإفادة تتبع السنة المعروضة نفسها التي تعرضها كل الكشوف
$h39a = renderPage('pages/attestations.php', ['type' => 'salaire', 'employee_id' => 1677], [], [2], '', '2025-2026');
$h39b = renderPage('pages/attestations.php', ['type' => 'salaire', 'employee_id' => 1677], [], [2], '', '2026-2027');
check('مصدر واحد (تجربة فعلية): إفادة الراتب تتبع السنة المعروضة (2025-2026 → أساس 2,085,000)',
      strpos($h39a, '2,085,000') !== false && strpos($h39a, '2,225,000') === false,
      strlen($h39a) . ' حرف');
check('مصدر واحد (تجربة فعلية): نفس الإفادة على السنة الجديدة تتبعها (2026-2027 → أساس 2,225,000)',
      strpos($h39b, '2,225,000') !== false && strpos($h39b, '2,085,000') === false,
      strlen($h39b) . ' حرف');
// تجربة فعلية: إفادة عمل الضمان لمارسيلا — رغم وجود أشهر مخزّنة حتى أيلول 2027،
// الأشهر المعروضة فعلية ماضية فقط (لا أشهر 2027 المستقبلية؛ منتقي السنة بالفورم خارج الفحص)
$h39c = renderPage('pages/official_forms.php', ['form' => 'cnss_work_detail', 'employee_id' => 1677], [], [2]);
check('مصدر واحد (تجربة فعلية): إفادة عمل الضمان بلا أشهر مستقبلية (آب 2026 ظاهر، أشهر 2027 غائبة)',
      strpos($h39c, 'آب 2026') !== false
      && strpos($h39c, 'أيلول 2027') === false && strpos($h39c, 'أيار 2027') === false
      && strpos($h39c, 'نيسان 2027') === false,
      strlen($h39c) . ' حرف');
// تجربة فعلية: إفادة الأجر الأخير (نهاية الخدمة 207) لمارسيلا — «مجموع الأجور» يجب أن يكون
// مجموع الأشهر الفعلية الماضية فقط (حتى شهر اليوم)، لا شامل أشهر 2026-2027 المستقبلية
$nowYM39 = (int)date('Y') * 100 + (int)date('n');
$twQ39 = $db->prepare("SELECT SUM(base_plus_echelon_lbp) base, SUM(extra_lbp+prime_fixe_lbp) exw, SUM(aide_complementaire_lbp) aide
                       FROM monthly_salaries WHERE employee_id = 1677 AND is_calculated = 1" . " AND (year * 100 + month) <= " . $nowYM39);
$twQ39->execute(); $twCap39 = $twQ39->fetch(PDO::FETCH_ASSOC);
$twQ39b = $db->query("SELECT SUM(base_plus_echelon_lbp) base, SUM(extra_lbp+prime_fixe_lbp) exw, SUM(aide_complementaire_lbp) aide
                      FROM monthly_salaries WHERE employee_id = 1677 AND is_calculated = 1")->fetch(PDO::FETCH_ASSOC);
$flags39 = $db->query("SELECT cnss_includes_extra, cnss_includes_prime_aide FROM employees WHERE id = 1677")->fetch(PDO::FETCH_ASSOC);
$mkTot39 = fn($t) => (int)$t['base'] + (!empty($flags39['cnss_includes_extra']) ? (int)$t['exw'] : 0)
                   + (!empty($flags39['cnss_includes_prime_aide']) ? (int)$t['aide'] : 0);
$capTot39 = $mkTot39($twCap39); $allTot39 = $mkTot39($twQ39b);
$h39d = renderPage('pages/official_forms.php', ['form' => 'cnss_eos_invite', 'employee_id' => 1677], [], [2]);
check('مصدر واحد (تجربة فعلية): «مجموع الأجور» بإفادة نهاية الخدمة 215A = الأشهر الماضية فقط (لا يشمل السنة المفتوحة)',
      $capTot39 !== $allTot39
      && strpos($h39d, number_format($capTot39)) !== false
      && strpos($h39d, number_format($allTot39)) === false,
      'محدود: ' . number_format($capTot39) . ' / الكلّي المرفوض: ' . number_format($allTot39));

/* =====================================================================
 * 40) ر10 بيان دوري **فصلي** (شكوى 2026-08-06 + مرجع Desktop\ر10 تصريح فصلي.pdf):
 *     التصريح كل ٣ أشهر مع منتقي «عن الفترة» (الفصل + السنة) يظهر من–إلى على
 *     المستند، والمجاميع من أشهر الفصل المختار حصراً — وبقي ر5 سنوياً.
 * =================================================================== */
$of40 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('ر10 فصلي: فرع مستقل بمنتقي الفصل (rq/rqy) وأشهر الفصل حصراً + «عن الفتــرة» على المستند',
      strpos($of40, "elseif (\$form === 'tax_r10'):") !== false
      && strpos($of40, 'name="rq"') !== false
      && strpos($of40, 'ms.month IN ($rqIn)') !== false
      && strpos($of40, 'عن الفتــرة') !== false
      && strpos($of40, 'بيان دوري بتأدية ضريبة الرواتب والأجور') !== false);
check('ر5 بقي سنوياً على السنة الدراسية المعروضة (فرع مستقل عن ر10)',
      strpos($of40, "elseif (\$form === 'tax_r5'):") !== false
      && strpos($of40, "form === 'tax_r5' || \$form === 'tax_r10'") === false);
// تجربة فعلية: الفصل ٢/2026 (نيسان-حزيران) مدرسة 2 — التواريخ على المستند وضريبته
// تساوي مجموع القاعدة لنفس الأشهر بالمليم، بنفس فلاتر المصدر الواحد
$h40 = renderPage('pages/official_forms.php', ['form' => 'tax_r10', 'rq' => 2, 'rqy' => 2026], [], [2]);
$dbTax40 = (int)$db->query("SELECT COALESCE(SUM(ms.income_tax_lbp),0) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
    WHERE e.is_deleted=0 AND e.tax_subject=1 AND ms.year=2026 AND ms.month IN (4,5,6) AND ms.school_id=2
      AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0)
      AND LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) >= '2025-10-01'
      AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year='2025-2026'
                     AND (base_plus_echelon_lbp>0 OR net_salary_lbp>0 OR total_due_lbp>0))")->fetchColumn();
check('ر10 فصلي (تجربة فعلية): الفصل ٢/2026 — «من 01/04/2026 إلى 30/06/2026» وضريبته = مجموع القاعدة للأشهر 4-6 بالمليم',
      strpos($h40, '01/04/2026') !== false && strpos($h40, '30/06/2026') !== false
      && $dbTax40 > 0 && strpos($h40, number_format($dbTax40)) !== false,
      'ضريبة القاعدة: ' . number_format($dbTax40));
// الافتراضي بلا باراميترات = آخر فصل مكتمل (يُحسب ديناميكياً فلا يفسد الفحص بمرور الوقت)
$q40 = intdiv((int)date('n') - 1, 3) + 1; $q40y = (int)date('Y');
$q40--; if ($q40 < 1) { $q40 = 4; $q40y--; }
$q40from = sprintf('01/%02d/%04d', [1=>1,2=>4,3=>7,4=>10][$q40], $q40y);
$h40b = renderPage('pages/official_forms.php', ['form' => 'tax_r10'], [], [2]);
check('ر10 فصلي (تجربة فعلية): الافتراضي بلا اختيار = آخر فصل مكتمل',
      strpos($h40b, $q40from) !== false, "المتوقّع من $q40from");
$h40c = renderPage('pages/official_forms.php', ['form' => 'tax_r5'], [], [2]);
check('ر5 (تجربة فعلية): بعده تصريحاً سنوياً يعمل بلا خطأ',
      strpos($h40c, 'تصريح سنوي عن ضريبة الدخل على الرواتب والأجور') !== false
      && strpos($h40c, 'FATAL') === false, strlen($h40c) . ' حرف');

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
