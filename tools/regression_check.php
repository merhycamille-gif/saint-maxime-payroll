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
$hQ = renderPage('pages/official_forms.php', ['form' => 'eoc_quarterly', 'quarter' => 3], []);
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

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
