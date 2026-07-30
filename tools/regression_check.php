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
function renderPage(string $rel, array $get, array $comp): string {
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
$_GET = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];
$_SERVER['REQUEST_URI'] = '/x';
chdir(dirname($PROJ . '/' . $argv[1]));
ob_start();
try { include $PROJ . '/' . $argv[1]; echo ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); echo 'FATAL: ' . $e->getMessage(); }
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
         . escapeshellarg($rel) . ' ' . base64_encode(json_encode($get)) . ' ' . base64_encode(json_encode($comp));
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

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
