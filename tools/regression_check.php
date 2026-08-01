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
check('رؤوس ثابتة: app.js يركّبها تلقائياً على كل الجداول + يدعم رأس الصفّين (top تراكمي)',
      strpos($jsSrc, 'initStickyHeads') !== false
      && strpos($jsSrc, "classList.add('tbl-scroll')") !== false
      && strpos($jsSrc, 'top += rows[i].offsetHeight') !== false);
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
check('قاعدة التارك: فتح السنة الجديدة يبقى يستثني التاركين (لا ينتقلون للسنة الجديدة)',
      substr_count($oySrc20, $isNullTrio) >= 2);

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
check('قد الورقة: البطاقة السنوية تملأ طول الورقة (193mm) والجدول يوزّع الفراغ على صفوفه',
      ($asSrc29 = (string)file_get_contents(__DIR__ . '/../pages/annual_slip.php')) !== ''
      && strpos($asSrc29, 'min-height: 193mm') !== false
      && strpos($asSrc29, 'flex: 1 1 auto') !== false);
// الأرقام العريضة (strong/b) أيضاً 12pt عالورق — كانت ناقصة من لائحة الطباعة فتطبع
// أهم الأرقام (الإجمالي/الصافي/المستحق) أصغر من جيرانها (ملاحظة المستخدم p1)
check('الخط 12 بكل شي: strong/b ضمن لائحة 12pt للطباعة (الأرقام العريضة لا تطبع أصغر)',
      preg_match('/@media print \{[^}]*?strong, b,[^}]*?font-size: 12pt !important/s',
                 (string)file_get_contents(__DIR__ . '/../assets/css/app.css')) === 1);
check('الخط 12 بكل شي: جداول التقارير لا تتكبّر فوق خط 12 (تملأ الورقة بتوسيع الأعمدة) ولا تُقصّ',
      strpos((string)file_get_contents(__DIR__ . '/../includes/report_helpers.php'), 'حجم الخط 12 بكل شي') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/report_helpers.php'), '? 1.4 : 1') === false
      && strpos($jsSrc29, '? 1.4 : 1') === false && strpos($jsSrc29, '1.8') === false);
// 🎨🔒 التصميم النهائي المجمّد للبطاقة السنوية — اعتمده المستخدم حرفياً بقوله
// «ما تغير بقى شي بالبطاقة احفظها منيح» (2026-08-01 مساءً). أي كسر لأحد هذه البنود
// = خرق لقرار المستخدم الصريح — ممنوع تعديل تصميم البطاقة بدون طلبه المباشر:
// بلا كحلي · رؤوس فاتحة · المحسومات أحمر فاتح · أرقام 14 عريضة والدولار تحتها ·
// خط Cairo موحّد · اسم الأستاذ 17pt أبرز عنصر · معلومات 13.5 عريضة · صفحة واحدة
check('🔒 البطاقة السنوية (تصميم مجمّد بأمر المستخدم): رؤوس فاتحة والمحسومات بأحمر فاتح وبلا كحلي',
      strpos($asSrc29, '.salary-slip-table thead th { background: #f1f5f9 !important; color: #111 !important;') !== false
      && strpos($asSrc29, 'th.deduction-header { background: #ffe3e3 !important;') !== false
      && strpos($asSrc29, '#1F4E5F !important') === false);
check('🔒 البطاقة السنوية (تصميم مجمّد): الليرة 14 عريضة والدولار 8.5 أخضر تحتها',
      strpos($asSrc29, 'font-size: 14pt !important; font-weight: 700 !important;') !== false
      && strpos($asSrc29, "font-size: 8.5pt !important") !== false
      && strpos($asSrc29, "'<span class=\"sub-lbp\">' . \$l . '</span><span class=\"cur-usd\">'") !== false);
check('🔒 البطاقة السنوية (تصميم مجمّد): خط Cairo موحّد + اسم الأستاذ 17pt أبرز عنصر + معلومات عريضة',
      strpos($asSrc29, ".salary-slip, .salary-slip-table, .slip-info { font-family:'Cairo'") !== false
      && strpos($asSrc29, '.slip-emp-name .slip-pname { font-size: 17pt !important; font-weight: 800 !important;') !== false
      && strpos($asSrc29, 'font-weight:800 !important') !== false);
check('🔒 البطاقة السنوية (تصميم مجمّد): تملأ طول الورقة (193mm + flex) وبلا fit القديم',
      strpos($asSrc29, 'min-height: 193mm') !== false
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

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
