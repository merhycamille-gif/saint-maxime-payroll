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
$GLOBALS['msa_recalc_paid_ok'] = true; // 🔒 أداة فحص = فعل صريح: التجارب الحيّة تعيد حساب أشهر مدفوعة بسنين سابقة (القسم 164 يختبر الحماية بإطفاء العلم مؤقتاً)

// ---------- عارض صفحات داخلي (كل صفحة بعملية فرعية لتفادي إعادة تعريف الدوال) ----------
// $outFile: للمخرجات الثنائية (xlsx...) — أنبوب shell_exec بويندوز وضع نصي يقصّ عند أول
// محرف 0x1A، فالثنائي يُكتب لملف عبر إعادة توجيه cmd ويُقرأ من القرص (2026-08-22)
function renderPage(string $rel, array $get, array $comp, array $schoolIds = [], string $currency = '', string $schoolYear = '', string $outFile = '', array $files = [], string $dueMode = '', string $netFamMode = ''): string {
    global $PROJ;
    if ($netFamMode !== '' && $dueMode === '') $dueMode = 'amount'; // 👨‍👩‍👧➕ يحفظ ترتيب الوسائط (argv[8] المستحق، argv[9] الصافي+العائلي)
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
// رفع ملف (POST multipart) للصفحات التي تستقبل ملفات — مدقّق ملف الوزارة
$__dm = $argv[8] ?? '';
if ($__dm !== '') $_SESSION['due_col_mode'] = $__dm; // 💰 حالة عمود المستحق (2026-09-19)
$__nfm = $argv[9] ?? '';
if ($__nfm !== '') $_SESSION['netfam_col_mode'] = $__nfm; // 👨‍👩‍👧➕ حالة عمود الصافي + التعويض العائلي (2026-09-20)
$__f = json_decode(base64_decode($argv[7] ?? ''), true) ?: [];
if ($__f) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf'] = $_SESSION['csrf_token'] = 'regcheck-token';
    foreach ($__f as $k => $path) {
        $_FILES[$k] = ['name' => basename($path), 'type' => 'text/xml', 'tmp_name' => $path,
                       'error' => UPLOAD_ERR_OK, 'size' => (int)@filesize($path)];
    }
}
$_SERVER['REQUEST_URI'] = '/x';
$GLOBALS['msa_att_prefs_off'] = true; // 🧠 خيارات الإفادة المحفوظة مطفأة بالفحوص إلا بـprefs_test=1 (2026-09-24)
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET'; // CLI بلا REQUEST_METHOD — تحذيره كان يتصدّر مخرجات القياس
chdir(dirname($PROJ . '/' . $argv[1]));
ob_start();
try { include $PROJ . '/' . $argv[1]; echo ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); echo 'FATAL: ' . $e->getMessage(); }
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
         . escapeshellarg($rel) . ' ' . base64_encode(json_encode($get)) . ' ' . base64_encode(json_encode($comp))
         . ' ' . base64_encode(json_encode($schoolIds)) . ' ' . escapeshellarg($currency) . ' ' . escapeshellarg($schoolYear)
         . ' ' . base64_encode(json_encode($files)) . ($dueMode !== '' ? ' ' . escapeshellarg($dueMode) : '') . ($netFamMode !== '' ? ' ' . escapeshellarg($netFamMode) : '');
    if ($outFile !== '') {
        @unlink($outFile);
        shell_exec($cmd . ' 2>NUL > ' . escapeshellarg($outFile));
        $res = (string)@file_get_contents($outFile);
        @unlink($outFile);
        return $res;
    }
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
    // (2026-09-15) الرأس قد يحمل تحته سطر النسبة (extraPctHead) — نقبل الصيغتين
    $exHead = '/الأجر الإضافي(<br><small class="rate-head" dir="ltr">[^<]*<\/small>)?<\/th>/u';
    check("عمود الإضافي يظهر مع الخيار — $p", preg_match($exHead, $hAll) === 1);
    check("عمود الإضافي يختفي بلا الخيار — $p", preg_match($exHead, $hNone) === 0);
    check("عمود المكافأة يختفي بلا الخيار — $p", substr_count($hNone, 'مكافأة ومساعدة</th>') === 0);
}
// الحالة الجزئية: إضافي فقط
$hEx = renderPage('pages/official_forms.php', ['form' => 'eoc_staff', 'cat' => 'titulaire'], ['extra']);
check('إضافي فقط: عمود الإضافي ظاهر والمكافأة مخفية — eoc_staff',
    preg_match('/الأجر الإضافي(<br><small class="rate-head" dir="ltr">[^<]*<\/small>)?<\/th>/u', $hEx) === 1 && substr_count($hEx, 'مكافأة ومساعدة</th>') === 0);

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
$leaver = $db->query("SELECT id, " . leftDateSql() . " ld
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
    // (تصحيح المستخدم 2026-08-06): النقل لا يدخل بالمركّب أبداً — عموده مستقل قبل «الإجمالي المتوجب»
    $_SESSION['salary_comp'] = ['extra', 'aide', 'transport'];
    $expAll = $exp + (int)$srow['aide_complementaire_lbp'];
    check('composedSalaryLbp (كل المكوّنات — بلا النقل)', composedSalaryLbp($srow) === $expAll, number_format($expAll));
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
// (2026-09-04) فحص Sakkal القديم أُلغي — الخط صار Arial (الفحص الجديد تحت)
// حجم الخط 12pt (متل «12» بالوورد) بالتقارير والإفادات — بطلب المستخدم 2026-07-29
check('حجم الخط 12pt بالتقارير (doc-table + رؤوس + فقرات النماذج)',
      strpos($hlpSrc, 'font-size:12pt;margin:10px 0') !== false          // .doc-table
      && substr_count($hlpSrc, 'font-size:12pt') >= 6);                   // th/info-grid/fline/doc-p/sign-box
$attSrc = (string)file_get_contents(__DIR__ . '/../pages/attestations.php');
check('حجم الخط 12pt بالإفادات (أجسام الإفادات الثلاثة)', substr_count($attSrc, 'font-size:12pt') >= 3);
// (2026-08-20) بطلب المستخدم: خط الإفادات Arial (متل «أبجد هوز» بالوورد) بكل اللغات —
// القاعدة معرَّفة بمواضع العرض الثلاثة (إفادة الضمان + نمط مكسيموس + القسم العام) وبلا Sakkal بالصفحة
check('خط الإفادات Arial بكل اللغات (٣ مواضع، بلا Sakkal بصفحة الإفادات)',
      substr_count($attSrc, "#ppExportArea{font-family:Arial,'Segoe UI',Tahoma,sans-serif}") >= 3
      && strpos($attSrc, 'Sakkal') === false);
// (2026-09-04) «بس يطلع الراتب الصافي فيه كسور كمان عملو داون»: المحرّك بلا round نصفي —
// كل محسوم floor أولاً، المجموع = جمع المنزَّل، الصافي = الإجمالي − المجموع، المستحق = الصافي + العائلي + النقل
$pcSrcFl = (string)file_get_contents(__DIR__ . '/../includes/payroll_calculator.php');
// 🛡️ حادثة أنطوني جبور (2026-09-04): نسبة 55 % مكرّرة فاعلة بـ2026-2027 = 110 % بعد نسخ فتح السنة (فحص «نفس الفترة» فقط)
$fnSrcDup = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
check('🛡️ لا بند نسبة مكرّر أبداً: صمام bonusDuplicateExists بفتح السنة و«نسخ الملف لسنة» + شفاء healDuplicatePercent20260904 عند كل فتح (موصول بالهيدر) + قاعدتا الفحص الرسمي dup_percent/multi_percent + محلياً صفر مكرّر',
      strpos($fnSrcDup, 'function bonusDuplicateExists(PDO $db, int $empId, string $targetSY, array $b): bool') !== false
      && strpos($fnSrcDup, "OR (value_type = 'percent' AND ? = 'percent')") !== false
      && strpos($fnSrcDup, 'function findDuplicatePercentBonusGroups(PDO $db): array') !== false
      && strpos($fnSrcDup, 'function healDuplicatePercent20260904()') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), 'healDuplicatePercent20260904();') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/open_year.php'), 'if (bonusDuplicateExists($db, (int)$empId, $newSY, $b)) continue;') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), 'if (bonusDuplicateExists($db, (int)$id, $target, $b)) continue;') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/data_audit.php'), "\$add('dup_percent'") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/data_audit.php'), "\$add('multi_percent'") !== false
      && count(findDuplicatePercentBonusGroups($db)) === 0
      && strpos((string)getSetting('heal_dup_percent_20260904', ''), 'done') === 0,
      (string)getSetting('heal_dup_percent_20260904', ''));
// 🩹 «ما بدي أخطاء أبداً» (2026-09-04): بنود بصفر تُطفأ ولا تُنسخ + أشهر السنوات المشتقّة تطابق بنودها الثابتة (مارسيلا داود)
check('🩹 بنود بصفر: healFutureYearConsistency20260904 موصول ونُفِّذ + لا بند فاعل بصفر + لا نسخ لبند بصفر (فتح السنة/نسخ الملف)',
      strpos($fnSrcDup, 'function healFutureYearConsistency20260904()') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), 'healFutureYearConsistency20260904();') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/open_year.php'), "if ((float)\$b['amount'] <= 0) continue;") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), "if ((float)\$b['amount'] <= 0) continue; // بند بصفر") !== false
      && strpos((string)getSetting('heal_future_consistency_20260904', ''), 'done') === 0
      && (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE is_active = 1 AND value_type = 'amount' AND amount <= 0")->fetchColumn() === 0,
      (string)getSetting('heal_future_consistency_20260904', ''));
// ⚖️ تقرير المخالفات والتصحيحات (طلبه 2026-09-04): «مساج بس افتح البرنامج: هيدا الأستاذ مخالف القانون، نوع المخالفة، التصحيح، موافق أو لا — ودايماً في تقرير»
require_once __DIR__ . '/../includes/compliance.php';
complianceEnsureTable($db);
$cpItems = complianceItems($db, currentSchoolYear());
$cpRules = complianceRules();
$cpBadRule = array_filter($cpItems, fn($i) => !isset($cpRules[$i['rule']]) || !isset($i['key'], $i['violation'], $i['fix'], $i['auto']));
$cpSrc = (string)file_get_contents(__DIR__ . '/../includes/compliance.php');
check('⚖️ تقرير المخالفات: الوحدة + الجدول الذاتي + 27 قاعدة (درجة/سلسلة/قانون النسبة/مكرّر/إضافي/تارك/صافي/تنزيل عائلي مطفأ/ضريبة ≠ قانون/صندوق على الأساس/نقل بنسبة/تعويض عائلي ≠ ملفه…) + الرئيسية تبنيه وتعرضه عند كل فتح وتعالج «موافق/لا» + الصفحة الدائمة + شارة بالقائمة + المكرّر التلقائي يُسجَّل فيه',
      count($cpRules) === 27 /* 👨‍👩‍👧 2026-09-20 + family_allow_stale · 🔎 2026-09-21 + month_stale */
      && (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'compliance_decisions'")->fetchColumn() === 1
      && is_array($cpItems) && count($cpBadRule) === 0
      && strpos($cpSrc, "case 'left_rows':") !== false && strpos($cpSrc, "case 'grade_law':") !== false && strpos($cpSrc, "case 'net_math':") !== false
      && strpos($cpSrc, "in_array(\$_POST['action'] ?? '', ['comp_approve', 'comp_reject', 'comp_reopen', 'comp_approve_rule', 'comp_law_from'], true)") !== false
      && strpos($cpSrc, 'requireCsrf();') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../index.php'), 'handleCompliancePost($db, BASE_URL . \'index.php\');') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../index.php'), 'renderCompliancePending($homeComp, true);') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/compliance.php'), 'complianceBuild($db)') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), 'pages/compliance.php') !== false
      && strpos($fnSrcDup, "complianceLogAuto(\$db, 'dup_percent'") !== false,
      'items=' . count($cpItems) . ' rules=' . implode(',', array_unique(array_column($cpItems, 'rule'))));
check('المحرّك: الصافي داون للألف (قراره 2026-09-04) والمستحق = الصافي + العائلي + النقل، المحسومات القانونية كما كانت + الشفاء الذاتي للمخزّن بكل السنوات نُفِّذ',
      strpos($pcSrcFl, '$cnssAmount        = round($cnssAmount);') !== false   // المحسومات القانونية كما كانت
      && strpos($pcSrcFl, '$monthlyTax        = round($monthlyTax);') !== false
      && strpos($pcSrcFl, '$totalRetenues = $cnssAmount + $caisseAmount + $monthlyTax + $eocGradeDeduction;') !== false
      && strpos($pcSrcFl, '$netSalary = floor(($grossEarnings - $totalRetenues) / 1000) * 1000;') !== false // قراره «للألف»
      && strpos($pcSrcFl, '$totalDue = floor($netSalary + $familyAllowance + $transportComp);') !== false
      && strpos($pcSrcFl, "(int)(floor(((int)\$r['net_salary_lbp'] + \$dNet) / 1000) * 1000)") !== false // مسار الترميم للمنقولين كذلك
      // الشفاء الذاتي للمخزّن من 2026-2027 فصاعداً + موصول بالهيدر + بعد تنفيذه محلياً لا صافي بفراطات ألف بهذه السنوات
      && strpos((string)file_get_contents(__DIR__ . '/../includes/functions.php'), "function healNetFloor1000_20260904()") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), "healNetFloor1000_20260904();") !== false
      && strpos((string)getSetting('heal_net_floor1000_all_20260904', ''), 'done') === 0
      && (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE is_calculated=1 AND (net_salary_lbp % 1000) <> 0")->fetchColumn() === 0
      && (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE is_calculated=1 AND total_due_lbp <> net_salary_lbp + family_allowance_lbp + transport_lbp")->fetchColumn() === 0
      && strpos((string)file_get_contents(__DIR__ . '/../includes/data_audit.php'), "NOT BETWEEN -1 AND 999") !== false // الفحص الرسمي يقبل التنزيل للألف
      && strpos($pcSrcFl, "'net_salary_lbp' => (int)\$netSalary,") !== false
      && !preg_match("/'[a-z_0-9]+_lbp' => round\(/", $pcSrcFl));
// (2026-09-04) بطلب المستخدم (p1: كشف برنامجه القديم بخط Arial): خط التقارير والقسائم والورقة
// الموحّدة Arial بكل اللغات متل الإفادات — بلا Sakkal بملف التقارير؛ البطاقة السنوية مجمّدة ولها خطّها
check('خط التقارير Arial (official-doc + doc-table/ppExportArea/payslip-card/doc-sheet، بلا Sakkal بملف التقارير)',
      strpos($hlpSrc, ".doc-table,#ppExportArea,.payslip-card,.doc-sheet{font-family:Arial,'Segoe UI',Tahoma,sans-serif;}") !== false
      && preg_match("/\.official-doc\{[^}]*font-family:Arial,'Segoe UI',Tahoma,sans-serif/s", $hlpSrc) === 1
      && strpos($hlpSrc, 'Sakkal') === false);
// (2026-08-20) «وقت عم اطبع وورد ما عم يبين لوغو المدرسة»: وورد لا يعرض خلفيات CSS ولا روابط نسبية —
// ترويسة .word-head مخفية تُكشف بتصدير الوورد فقط + خط Arial إنلاين على أجسام الإفادات لينتقل للوورد
check('تصدير وورد: ترويسة .word-head البديلة (موضعا الضمان ونمط مكسيموس) + Arial إنلاين بأجسام الإفادات',
      substr_count($attSrc, 'class="word-head"') >= 2
      && substr_count($attSrc, "font-size:12pt;font-family:Arial,'Segoe UI',Tahoma,sans-serif") >= 3);
// (2026-08-20) «إفادة الراتب بدون تابلو» + «بدون ألوان»: لا جدول بإفادة الراتب ولا كحلي بالإفادات،
// وترويسة الوورد بلا خط تحت الشعار + المدينة فقط + الهاتف LTR (الكود على شمال الرقم)
// (2026-08-20) «لوغو الراهبات»: كتابة الترويسة متوسّطة تحت الشعار ($headBodyAr — جدول بلا حدود
// لأن وورد لا يفهم inline-block) + سطر «للراهبات المخلصيات – المدينة» إن كان بالاسم + الهاتف LTR
$whW = strpos($attSrc, '$headBodyAr =') !== false ? substr($attSrc, strpos($attSrc, '$headBodyAr ='), 900) : 'border-bottom';
check('إفادة راتب بلا تابلو + ترويسة «لوغو الراهبات» (متوسّطة، بلا خط، هاتف LTR)',
      strpos($attSrc, 'background:#1F4E5F') === false
      && strpos($whW, 'border-bottom') === false
      && strpos($whW, 'text-align:center') !== false
      && strpos($attSrc, 'للراهبات المخلصيات') !== false
      && substr_count($attSrc, 'هاتف : <span dir="ltr">') >= 3);
// (2026-08-20) «في تكرار للمنطقة بس حط المنطقة قلنا» (p1 — ايلح-ايلح): ترويسات الشاشة كمان
// بالمدينة فقط ($cityAr/$cityFr) + scr-head ينشال بتصدير الوورد مكان word-head (لكل المدارس)
// + الشعار بword-head بقياس width/height صريح (وورد لا يفهم max-height فكان يطلع بحجمه الكامل)
check('ترويسات الإفادات بالمدينة فقط + scr-head/word-head لكل المدارس + شعار الوورد بقياس صريح',
      substr_count($attSrc, 'class="scr-head"') >= 3
      && strpos($attSrc, '$logoImgWord') !== false
      && strpos($attSrc, "e(\$cityAr)") !== false && strpos($attSrc, "e(\$cityFr)") !== false);
// (2026-08-20) «لاستعمالها لدى من يلزم ، دون أدنى مسؤولية... شيلها من كل الإفادات»:
// صيغة الختام هذه شِيلت من كل الإفادات (بقيت «بناءً على طلبه») — والنصوص القانونية الجوهرية
// (إبراء الذمة/العقد/الإقرار) بصياغاتها المختلفة لم تُمسّ
check('صيغة «لمن يلزم» وجملة عدم المسؤولية غائبتان من كل الإفادات',
      strpos($attSrc, 'لاستعمالها لدى من يلزم') === false
      && strpos($attSrc, 'دون أدنى مسؤولية') === false
      && strpos($attSrc, 'لاستعمالها عند الحاجة') === false
      && strpos($attSrc, 'valoir ce que de droit') === false);
// (2026-08-20) «p1: بدو يكونو على اليمين»: كتلة «الصندوق الوطني للضمان الاجتماعي / مكتب /
// رقم الوارد / تاريخ» أعلى إفادة الضمان عاليمين لا عاليسار
check('إفادة الضمان: كتلة «الصندوق الوطني» عاليمين',
      strpos($attSrc, 'text-align:right;font-weight:700;line-height:1.7') !== false
      && strpos($attSrc, 'text-align:left;font-weight:700;line-height:1.7') === false);
// (2026-08-20) «بدو يكون رقم المبلغ قبل التفقيط» (p1): خانة الراتب المدموجة G19 بنموذج
// الاستخدام-المضمون تبدأ بعلامة RTL ‏(‏) حتى يظهر الرقم أولاً بعد «ان الراتب الحالي» ثم التفقيط
$oeSrc2 = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('نموذج الاستخدام-المضمون: الرقم قبل التفقيط بخانة G19 (علامة RTL بأول الخانة)',
      strpos($oeSrc2, '\'G19\' => trim("\u{200F}" . $wageNum') !== false);
// (2026-08-20) «مكان جملة ملحقات مدفوعة بدو يكون الأجر الإضافي» بإفادة الضمان
check('إفادة الضمان: سطر «الأجر الإضافي» بدل «ملحقات مدفوعة من أشخاص ثالثين»',
      strpos($attSrc, 'ملحقات مدفوعة من أشخاص ثالثين') === false
      && strpos($attSrc, "\$cnssParts[] = ['ar' => 'الأجر الإضافي'") !== false // (2026-09-15) سطر لكل مكوّن مختار فقط — لا سطر بصفر
      && strpos($attSrc, '- الأجر الإضافي : <strong><?= $moneyAr($attSupp)') === false);
// (2026-08-21) p1: خانة «الرقم/N°» انشالت من رأس إفادتي الراتب والعمل + التاريخ وحده سطراً
// (شمال الصفحة بالعربي بdir=rtl حتى تسبق كلمة «التاريخ» الرقم، ويمينها باللاتيني) — بلا flex
// لأن الوورد لا يفهمه فتتكوّم السطور بجهة وحدة + كلمة «هاتف» قبل الرقم (سطر الهاتف dir=rtl)
// (2026-08-21) «اسم المكان بدك تكتبو مظبوط بالفرنسي — الحدث Hadath مش Hds»: قاموس المناطق
// اللبنانية arPlaceToFr يترجم عناوين المدارس وأماكن الموظفين بالإفادات اللاتينية (فرنسي/إنكليزي)
require_once __DIR__ . '/../includes/translit_ar_fr.php';
check('أسماء المناطق باللاتينية: قاموس المناطق + العناوين المركّبة + مربوط بالإفادات وصفحة المدارس',
      arPlaceToFr('الحدث') === 'Hadath'
      && arPlaceToFr('الحدث - تلال الحدث - الراهبات المخلصيات') === 'Hadath - Tilal El Hadath - Sœurs Salvatoriennes'
      && arPlaceToFr('عبرا - الراهبات المخلصيات') === 'Abra - Sœurs Salvatoriennes'
      && arPlaceToFr('المحتقرة - جون - الراهبات المخلصيات') === 'Mohtakra - Joun - Sœurs Salvatoriennes'
      && arPlaceToFr('المنصورية') === 'Mansourieh'
      && arPlaceToFr('ابلح') === 'Ablah' && arPlaceToFr('يارون') === 'Yaroun'
      && arPlaceToFr('كسارة - تلال كسارة - الراهبات المخلصيات') === 'Ksara - Tilal Ksara - Sœurs Salvatoriennes'
      && strpos($attSrc, 'arPlaceToFr($schoolAddr)') !== false
      && strpos($attSrc, '$addrLat') !== false && strpos($attSrc, '$bplaceLat') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/schools.php'), 'arPlaceToFr($addrAr)') !== false
      && function_exists('healSchoolNameFrDiacritics20260821'));
// (2026-08-21) ترتيب مثال p1.png + «بدي المسافة 2 سنتم»: اللوغو بزاوية الورقة (هامش صفحة 8mm
// وترويسة scr-head ترجع للحافة بهامش سالب -12mm) والكتابة 2 سم من الجهتين (8mm صفحة +
// 12mm جسم الإفادة) والنص اللاتيني مضبوط الطرفين + المدينة قبل التاريخ — مقيسة فعلياً بالـPDF
check('ترتيب p1: اللوغو بزاوية الورقة والكتابة 2 سم من الحافتين + justify لاتيني + المدينة قبل التاريخ',
      strpos($attSrc, 'padding:20px 8mm') !== false
      && substr_count($attSrc, '#ppExportArea .card-body{padding-left:12mm;padding-right:12mm} #ppExportArea .scr-head{margin-left:-12mm;margin-right:-12mm}') >= 2
      && substr_count($attSrc, '#ppExportArea{padding:0 !important} #ppExportArea .card-body{padding:8mm 20mm 10mm !important}') >= 2
      && substr_count($attSrc, "\$type === 'aqd_taalim' ? '10mm 0' : '0'") >= 1
      && strpos((string)file_get_contents(__DIR__ . '/../assets/js/export.js'), "att ? '1.2cm 2cm'") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../tools/page_to_pdf.js'), "isAtt ? '0' : '5mm'") !== false /* PDF الإيميل بلا هوامش إضافية للإفادات */);
// (2026-08-21) «بس اضغط زر طبع PDF ما بيطبع — ظبطها تطبع»: زر «PDF رسمي» صار view=1 —
// الـPDF يفتح بتبويب جديد (target=_blank) وشاشة الطباعة تطلع لحالها (كان ينزّل بصمت
// عالـDownloads فيبدو أن ما صار شي) + وضع fetch يقدّم الملف inline/تنزيلاً
$ppdf21 = (string)file_get_contents(__DIR__ . '/../pages/print_pdf.php');
check('زر PDF رسمي يعرض ويطبع (view=1 + fetch + طباعة تلقائية بالتبويب)',
      strpos((string)file_get_contents(__DIR__ . '/../includes/functions.php'), "'&view=1'") !== false
      && strpos($fnSrcToolbar21 = (string)file_get_contents(__DIR__ . '/../includes/functions.php'), 'target="_blank" title="PDF رسمي طبق الأصل') !== false
      && strpos($ppdf21, "\$_GET['fetch']") !== false
      && strpos($ppdf21, "f.contentWindow.print()") !== false
      && strpos($ppdf21, "addEventListener(\"load\",function(){setTimeout(pr,800);})") !== false
      && strpos($attSrc, '<div dir="ltr" style="text-align:left">') === false
      && substr_count($attSrc, "e(\$cityFr) . ', le '") >= 3);
// (2026-08-21) p1: «بدنا نكتب لمادة اللغة الإنكليزية» — المادة تُكتب بلغة الوثيقة نفسها
// مهما كانت لغة تخزينها بملف الأستاذ (subjectToLang: Anglais→اللغة الإنكليزية بالعربي والعكس)
check('المادة بلغة الإفادة: قاموس المواد بالاتجاهات الثلاثة + مربوط بمواضع المادة كلها',
      subjectToLang('Anglais', 'ar') === 'اللغة الإنكليزية'
      && subjectToLang('Anglais', 'en') === 'English'
      && subjectToLang('اللغة الفرنسية', 'fr') === 'Français'
      && subjectToLang('رياضيات', 'en') === 'Mathematics'
      && subjectToLang('اللّغة العربيّة', 'fr') === 'Arabe'
      && subjectToLang('Chimie', 'ar') === 'الكيمياء'
      && subjectToLang('مادة غير معروفة', 'fr') === 'مادة غير معروفة'
      /* p1 (2026-08-21): موادّ متعددة بمسافات تُترجم كلمةً كلمة إن عُرفت كلها، وإلا تبقى كما هي */
      && subjectToLang('تاريخ تربية جغرافيا', 'fr') === 'Histoire, Éducation civique, Géographie'
      && subjectToLang('تاريخ تربية جغرافيا', 'en') === 'History, Civics, Geography'
      && subjectToLang('مديرة قسم الروضات', 'fr') === 'مديرة قسم الروضات'
      /* p1: واو العطف الملزوقة + «اجتماعيات» */
      && subjectToLang('لغة عربية واجتماعيات', 'fr') === 'Arabe, Sciences sociales'
      && subjectToLang('لغة عربية واجتماعيات', 'ar') === 'اللغة العربية والاجتماعيات'
      && strpos($attSrc, "subjectToLang(\$subj, 'ar')") !== false
      && strpos($attSrc, 'e($subjAr)') !== false && strpos($attSrc, 'e($subjL)') !== false
      && strpos($attSrc, 'e($subjFr)') !== false && strpos($attSrc, 'e($subjEn)') !== false
      && strpos($attSrc, '$vb($subjAr, 110)') !== false && strpos($attSrc, '$vb($subjL, 110)') !== false
      && strpos($attSrc, "\$blank(140) ?></strong> <?= \$levelsAr ?>") !== false);
// (2026-08-21) p1: «هيدي PDF مش مظبوطة» — نموذج 190A أونلاين (بلا LibreOffice) كان يقع على
// النسخة المرسومة المخربطة → صار له نسخة مصوّرة طبق الأصل (صورة القالب الرسمي المفرَّغ +
// القيم المركّبة بإحداثيات معايَرة + خانتا المجموع/الباقي محسوبتان) — A4 أفقي
$oe190 = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('نموذج 190A: نسخة مصوّرة طبق الأصل (خلفية + إحداثيات + مجموع/باقٍ محسوبان + A4 أفقي)',
      is_file(__DIR__ . '/../assets/templates/cnss_monthly.png')
      && is_file(__DIR__ . '/../assets/templates/cnss_monthly.pos.json')
      && count(json_decode((string)file_get_contents(__DIR__ . '/../assets/templates/cnss_monthly.pos.json'), true)['cells'] ?? []) === 16
      && strpos($oe190, "'P43' => formatLBP(\$c1 + \$c2 + \$c3, false)") !== false
      && strpos($oe190, "'P47' => formatLBP((\$c1 + \$c2 + \$c3) - \$fpaid, false)") !== false
      && strpos($oe190, 'cnss_monthly.pos.json') !== false
      && strpos($oe190, 'size:A4 landscape') !== false);
// (2026-08-21) «شوف ر3 على الدسك توب وبدي متلها طبق الأصل»: نموذج المالية ر3 (طلب تسجيل
// مستخدم/أجير جديد) — صورة النموذج الرسمي + تعبئة تلقائية من ملف الموظف بإحداثيات مقيسة
$hR3 = renderPage('pages/official_export.php', ['form' => 'mof_r3', 'emp' => 2, 'sex' => 'f', 'wage' => 'm'], [], [1]);
check('نموذج المالية ر3 طبق الأصل: الصورة + التعبئة (لبنانية/الضمان/خانات الأرقام) + A4 كامل',
      is_file(__DIR__ . '/../assets/templates/mof_r3.png')
      && is_file(__DIR__ . '/../assets/templates/mof_r3.pdf')
      && strpos($hR3, 'assets/templates/mof_r3.png') !== false
      && strpos($hR3, 'لبنانية') !== false
      && strpos($hR3, '911426') !== false
      && strpos($hR3, 'size:A4;margin:0') !== false
      && strpos($attSrc, "'mof_r3'") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/official_export.php'), "count(\$centers) - strlen(\$num)") !== false);
// (2026-08-21) «وين معلومات الزوج/الزوجة»: أعمدة الزوج بملف الموظف (تركيب ذاتي)
// + شاشة حفظها بنموذج ر3 + تعبئة قسم الزوج/الزوجة بالنموذج.
// 🔴 (2026-08-23) «المكان الخاص بالادارة لازم يبقى فاضي ما يتعبى هيدا الدولة بتعبي»:
// قسم «خاص بالإدارة» لا يُعبَّأ إطلاقاً (لا رقم بخاناته 82.33 ولا تاريخ تسجيل 86.1).
$oeR3s = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('نموذج ر3: معلومات الزوج/الزوجة (أعمدة ذاتية + شاشة حفظ + تعبئة) + قسم خاص بالإدارة فاضٍ',
      function_exists('ensureSpouseColumns20260821')
      && (ensureSpouseColumns20260821() ?? true)
      && $db->query("SHOW COLUMNS FROM employees LIKE 'spouse_full_name'")->fetch() !== false
      && $db->query("SHOW COLUMNS FROM employees LIKE 'spouse_employer_public'")->fetch() !== false
      && strpos($attSrc, 'save_spouse') !== false
      && strpos($attSrc, 'name="spouse_mof_number"') !== false
      && strpos($oeR3s, "\$emp['spouse_full_name']") !== false
      && strpos($oeR3s, "\$emp['spouse_mof_number']") !== false
      && strpos($oeR3s, 'يبقى فاضياً') !== false
      && strpos($oeR3s, '82.33') === false
      && strpos($oeR3s, '86.1, ') === false);
// (2026-08-22) «مش جايين المعلومات بمحلهون على السطر»: معايرة القياس الآلي — كل نص يقعد
// على سطره (top = سطر − صعود الخط) والأرقام بوسط خاناتها الحقيقية (٩-١٠ خانات ~2.08٪)
check('نموذج ر3: إحداثيات مقاسة من الصورة (خانات 2.08٪ + أم الموظف 21.6 + ضمان 33.3 + هاتف شمالي 72.36)',
      strpos($oeR3s, '25.2, 21.6') !== false
      && strpos($oeR3s, '17.7, 33.3') !== false
      && strpos($oeR3s, '21.5, 31.2') !== false
      && strpos($oeR3s, '14.5, 72.36') !== false
      // (خانات «خاص بالإدارة» 11.32... انشالت 2026-08-23 — القسم يبقى فاضياً للدولة)
      && strpos($oeR3s, '61.06, 63.16') !== false
      && strpos($oeR3s, 'rtl="0"') !== false); // إكسل: اتجاه صريح — لا انعكاس هاتف/إيميل
// (2026-08-22) «شوف على الدسك توب ر3 اكسل بدي ياها طبق الاصل» + «بدي ياها اكسل كمان»:
// صورة عالية الدقة من r3_exel.xlsx + تصدير إكسل معبّى (قالب الصورة + نصوص فوقها) — تجربة فعلية
$xR3 = renderPage('pages/official_export.php', ['form' => 'mof_r3', 'emp' => 2, 'sex' => 'f', 'wage' => 'm', 'format' => 'xlsx'], [], [1], '', '', sys_get_temp_dir() . '/reg_r3_xlsx.bin');
check('نموذج ر3 إكسل رسمي: القالب (صورة المستخدم قد A4) + توليد xlsx معبّى فعلياً + زرّ Excel بشاشة ر3',
      is_file(__DIR__ . '/../assets/templates/mof_r3_excel.xlsx')
      && filesize(__DIR__ . '/../assets/templates/mof_r3_excel.xlsx') > 1000000
      && substr((string)$xR3, 0, 2) === 'PK'
      && strlen((string)$xR3) > 1000000
      && strpos($oeR3s, 'twoCellAnchor') !== false /* مرساة الشبكة: النص ملزوق بالصورة بأي شاشة/تكبير */
      && strpos($oeR3s, 'mof_r3_excel.xlsx') !== false
      && strpos($attSrc, '&format=xlsx') !== false);
// (2026-08-22) «عمول شغلك صح دغري»: ر3 من النماذج الرسمية يحوّل لنموذج mof_r3 طبق الأصل —
// النسخة المبنية HTML القديمة انشالت نهائياً من official_forms
$ofSrc22 = (string)file_get_contents(__DIR__ . '/../pages/official_forms.php');
check('ر3 بالنماذج الرسمية = طبق الأصل حصراً (تحويل لmof_r3 + لا نسخة مبنية قديمة)',
      strpos($ofSrc22, "\$form === 'tax_register' && \$emp") !== false
      && strpos($ofSrc22, "type=mof_r3") !== false
      && strpos($ofSrc22, "elseif (\$form === 'tax_register'): // ر3 طلب تسجيل") === false);
// (2026-08-22) «المعلومات بدها تتعبى من ملف الموظف تلقائياً»: خانة الجنس بملف الموظف —
// عمود gender ذاتي التركيب + تعبئة تلقائية من الاسم (لوائح أسماء + الاخت/الاب) + شاشات
// ر3 ونماذج الضمان تقرأها تلقائياً وأي تغيير منها يُحفَظ بالملف + خانة بفورم الموظف
check('الجنس تلقائياً من ملف الموظف: عمود gender + تعبئة من الاسم + الشاشات تقرأه وتحفظ تغييره',
      function_exists('ensureGenderColumn20260822')
      && (ensureGenderColumn20260822() ?? true)
      && $db->query("SHOW COLUMNS FROM employees LIKE 'gender'")->fetch() !== false
      && (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted=0 AND gender IS NOT NULL")->fetchColumn() > 500
      && substr_count($attSrc, 'ensureGenderColumn20260822') >= 2
      && substr_count($attSrc, "UPDATE employees SET gender=?") >= 2
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), 'name="gender"') !== false
      && substr_count((string)file_get_contents(__DIR__ . '/../pages/official_export.php'), "\$emp['gender']") >= 2);
// (2026-08-22) «إلسي/تيا/اسمهان طلعوا ذكر»: جنس مجهول = لا علامة × إطلاقاً (لا افتراض ذكر)
// + الشاشة تعرض «حدّد الجنس» بإطار أحمر بدل تعليم غلط على نموذج رسمي
$oeR3g = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('الجنس المجهول بر3: لا × على ذكر/أنثى + شاشة «حدّد الجنس» + أسماء النساء المفقودة بالتعبئة',
      strpos($oeR3g, "if (\$sex !== '') \$X(") !== false
      && substr_count($oeR3g, "= genderSexOf(\$sexQ);") >= 2 // (2026-09-15) المصدر الواحد: الآنسة (d) ⇒ أنثى، مجهول ⇒ ''
      && substr_count($attSrc, 'حدّد الجنس') >= 2
      && strpos((string)file_get_contents(__DIR__ . '/../includes/functions.php'), "'إلسي'") !== false
      && $db->query("SELECT COUNT(*) FROM employees WHERE first_name_ar IN ('تيا','اسمهان','السي') AND is_deleted=0 AND gender='f'")->fetchColumn() >= 3);
// (2026-08-22) p1 «ورقة الطباعة بيضاء»: صمام _autoprint — صفحة كل محتواها no-print (شاشة
// اختيار موظف) ما تعرض زرّي الطباعة بل رسالة توجيه، فلا تنطبع ورقة فاضية
$ftSrc22 = (string)file_get_contents(__DIR__ . '/../includes/footer.php');
check('صمام الورقة الفاضية بالطباعة: _autoprint يتحقق من وجود مستند قابل للطباعة قبل عرض الأزرار',
      strpos($ftSrc22, 'hasPrintable') !== false
      && strpos($ftSrc22, 'ما في مستند معروض للطباعة') !== false);
// (2026-08-21) p1: «ما عم يبين أسانسور التفتيش» — البطاقات .card عليها overflow:hidden فكانت
// لائحة نتائج التفتيش تنقصّ كلياً حين تكون البطاقة قصيرة (صفحة النماذج الرسمية) — الويدجت
// صارت تفتح قصّ البطاقات الأسلاف وقت اللوحة مفتوحة (setCardClip) وترجّعه عند إغلاقها
$ssSrc21 = (string)file_get_contents(__DIR__ . '/../assets/js/select-search.js');
check('تفتيش الأستاذ: لائحة النتائج لا تنقصّ ببطاقات overflow:hidden (setCardClip/hidePanel)',
      strpos($ssSrc21, 'function setCardClip') !== false
      && strpos($ssSrc21, "el.style.overflow = open ? 'visible' : ''") !== false
      && strpos($ssSrc21, 'function hidePanel') !== false
      && substr_count($ssSrc21, 'setCardClip(true)') >= 2
      && substr_count($ssSrc21, 'hidePanel') >= 4);
check('p1: بلا خانة رقم برأس الإفادات + التاريخ شمال عربي/يمين لاتيني + كلمة هاتف قبل الرقم',
      strpos($attSrc, "'N°' : 'No.'") === false
      && strpos($attSrc, 'الرقم : <span style="display:inline-block;min-width:90px') === false
      && substr_count($attSrc, '<div dir="rtl" style="text-align:left;margin-bottom:10px">التاريخ : ') === 2
      && substr_count($attSrc, ": 'Date : ' ?><?= \$today ?>") === 2 /* سطر التاريخ اللاتيني (مدينة، le/: تاريخ — وDate احتياط) بإفادتي الراتب والعمل */
      && strpos($attSrc, '<div dir="rtl" style="font-size:14px">هاتف : <span dir="ltr">') !== false
      && substr_count($attSrc, '<div dir="rtl"><small>هاتف : <span dir="ltr">') === 2);
// (2026-08-20) «p1 وp2: صحح العنوان»: عناوين المدارس المخزّنة فيها مقاطع مكررة («عبرا - عبرا») —
// dedupeAddress تشيل المكرر بالعرض، ومطبَّقة على خانات العنوان بنماذج الضمان الثلاثة وبالإفادات
$oeSrc = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
check('تنقية العنوان: dedupeAddress تشيل المقاطع المكررة ومطبَّقة بنماذج الضمان والإفادات',
      dedupeAddress('عبرا - عبرا - الراهبات المخلصيات') === 'عبرا - الراهبات المخلصيات'
      && dedupeAddress('االمحتقرة - جون - االمحتقرة - جون - الراهبات المخلصيات') === 'االمحتقرة - جون - الراهبات المخلصيات'
      && dedupeAddress('الحدث - تلال الحدث - الراهبات المخلصيات') === 'الحدث - تلال الحدث - الراهبات المخلصيات'
      && substr_count($oeSrc, 'dedupeAddress(') >= 3
      && strpos($attSrc, 'dedupeAddress(') !== false);
// (2026-08-20) «صححها كلها وين ما كان — برنامج ما لازم يكون فيه أخطاء»: الداتا نفسها منقّاة
// بالشفاء healSchoolAddressDedupe20260820 (بالهيدر) — صفر عناوين مدارس بمقاطع مكررة أو ألف مزدوجة
$badAddr = [];
foreach ($db->query("SELECT id, address FROM schools")->fetchAll(PDO::FETCH_KEY_PAIR) as $sid => $a) {
    $a = (string)$a;
    if (trim($a) === '') continue;
    if ($a !== dedupeAddress($a) || preg_match('/(^|\s)اا/u', $a)) $badAddr[] = $sid;
}
$hdrSrc = (string)file_get_contents(__DIR__ . '/../includes/header.php');
check('عناوين المدارس منقّاة بالداتا نفسها (شفاء بالهيدر + صفر عناوين مخربطة)',
      !$badAddr && strpos($hdrSrc, 'healSchoolAddressDedupe20260820') !== false,
      $badAddr ? ('مدارس: ' . implode(',', $badAddr)) : '');
// (2026-08-20) «الراتب بدك تجمعو مع الإضافي أو المكافأة إذا محطوطين»: علاوات ناقصة أونلاين
// (السي موسى) — لائحة الترحيل من الكمبيوتر + شفاء دفعات بالهيدر يكمّل الناقص أونلاين فقط
$bfFile = __DIR__ . '/../assets/data/bonuses_backfill_20260820.json';
$bfList = is_file($bfFile) ? json_decode((string)file_get_contents($bfFile), true) : null;
check('ترحيل العلاوات الناقصة أونلاين: اللائحة موجودة وسليمة + الشفاء بالهيدر',
      is_array($bfList) && count($bfList) > 700
      && isset($bfList[0]['e'], $bfList[0]['t'], $bfList[0]['sy'], $bfList[0]['a'])
      && strpos($hdrSrc, 'healBonusBackfill20260820') !== false);
// (2026-08-20) جردة «صحح كل البرنامج» — الورقة الأخيرة البيضاء بالتقارير الطويلة:
// (١) صف العنوان المحقون colSpan بعدد الأعمدة الحقيقي (99 كان يخرّب تقطيع الجدول)
// (٢) العنوان بdiv .pr-title-text سطراً واحداً (width:0/min-width:100% — الالتفاف كان يطوّل
//     الرأس المكرر ويولّد ورقة بيضاء) (٣) هامش سالب صغير بآخر الجدول (٤) فكّ تمطيط الهيكل بالطباعة
$appJs = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$rhSrc = (string)file_get_contents(__DIR__ . '/../includes/report_helpers.php');
$cssSrc = (string)file_get_contents(__DIR__ . '/../assets/css/app.css');
check('لا ورقة أخيرة بيضاء بالتقارير الطويلة (colSpan حقيقي + عنوان سطر واحد + هوامش سالبة + فكّ التمطيط)',
      strpos($appJs, 'colSpan = 99') === false
      && strpos($appJs, 'pr-title-text') !== false
      && strpos($rhSrc, '.pr-title-text{width:0;min-width:100%;white-space:nowrap') !== false
      && strpos($rhSrc, '.doc-table{margin-bottom:-12px;}') !== false
      && strpos($rhSrc, '.official-doc,.doc-sheet{margin-bottom:-120px;}') !== false
      && strpos($rhSrc, 'body:has(.land-report){page:landscapePage;}') !== false
      && strpos($cssSrc, '.app-layout, .main-content { display: block !important') !== false);
// (2026-08-20) جردة ب: (١) هدف التصغير = عرض الورقة الفعلي داخل هوامش @page (كان 745/1075
// أعرض من الورقة فيُقصّ طرف الجدول المصغَّر صمتاً — عمود «الباقي للصندوق» بالاسمي الشهري)
// + هامش أمان 0.98 بمعادلة --pz (٢) sign-row جدولاً بالطباعة (flex لا يحترم منع الانقسام)
check('لا قصّ صامت بالجداول المصغَّرة (أهداف 718/1062 + أمان 0.98 لـdoc-table و0.96 للجداول العادية داخل البطاقات) + صفوف التواقيع جدول بالطباعة',
      strpos($rhSrc, '--pz-target:718') !== false
      && strpos($rhSrc, '--pz-target:1062') !== false
      && strpos($rhSrc, '(target * 0.98) / natW') !== false && strpos($appJs, '(target * 0.96) / natW') !== false
      && strpos($appJs, "? 1062 : 718") !== false
      && strpos($rhSrc, '.sign-row{display:table;width:100%;table-layout:fixed') !== false);
// (2026-08-20) «قلنالك بدون هيدا الخط الأسود تحت اللوغو»: بلا أي خط صلب تحت ترويسات الإفادات
// بالشاشة والطباعة والوورد كلها — المسموح فقط الخطوط المنقّطة/المتقطعة لخانات التعبئة
check('لا خط صلب تحت ترويسات الإفادات (شاشة/طباعة/وورد)',
      strpos($attSrc, 'border-bottom:2px') === false
      && strpos($attSrc, 'border-bottom:1px solid') === false);
$expSrc = (string)file_get_contents(__DIR__ . '/../assets/js/export.js');
check('تصدير وورد: ملف MHT بصور مضمَّنة base64 (multipart/related + كشف .word-head وإزالة .scr-head + rawDownload بلا BOM)',
      strpos($expSrc, 'multipart/related') !== false
      && strpos($expSrc, 'word-head') !== false
      && strpos($expSrc, 'scr-head') !== false
      && strpos($expSrc, 'rawDownload') !== false);
$repSrc = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
check('حجم الخط 12pt بطباعة مركز التقارير', strpos($repSrc, 'font-size: 12pt !important') !== false);
$regEid = (int)$db->query("SELECT ms.employee_id FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
                           WHERE ms.month = 6 AND ms.year = 2026 AND ms.net_salary_lbp > 0 AND e.is_deleted = 0 LIMIT 1")->fetchColumn();
if ($regEid) {
    $hPs = renderPage('pages/monthly_payroll.php', ['employee_id' => $regEid, 'month' => 6, 'year' => 2026], []);
    check('القسيمة: توقيعا المحاسب والموظف بالاستلام', strpos($hPs, 'توقيع المحاسب') !== false && strpos($hPs, 'توقيع الموظف بالاستلام') !== false);
    check('القسيمة: «صافي الراتب المستحق للدفع» بارز', strpos($hPs, 'صافي الراتب المستحق للدفع') !== false);
    // (2026-08-20) «الأجر الإضافي بكل الإفادات بدها تكون»: الإضافي محطوط افتراضياً — فالأساس-وحده
    // يُجرَّب بشيل المربّع صراحةً (opts_set=1 بلا inc_extra)
    $hAt = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'salaire', 'opts_set' => 1], []);
    $hAtDef = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'salaire'], []);
    check('إفادة راتب: الأجر الإضافي محطوط افتراضياً بكل الإفادات (المربّع مؤشَّر بلا أي خيار)',
          strpos($hAtDef, 'name="inc_extra" value="1" checked') !== false);
    // (2026-08-16) بطلب المستخدم: جملة «دون أدنى مسؤولية...» انشالت من إفادة الراتب فقط
    // (2026-08-20) «بدون تابلو»: الأساس وحده = جملة «قدره» بلا تفصيل ولا جدول
    check('إفادة راتب: الصيغة الرسمية (الأساس وحده = جملة قدره، بلا جملة عدم المسؤولية، بلا جدول، بلا «لاستعمالها لدى من يلزم»)',
          preg_match('/و[يت]تقاضى راتباً شهرياً قدره/u', $hAt) === 1 // (2026-09-15) ويتقاضى/وتتقاضى حسب جنس الموظف
          && strpos($hAt, 'دون أدنى مسؤولية') === false
          && strpos($hAt, '<table dir="rtl"') === false
          && strpos($hAt, 'لاستعمالها لدى من يلزم') === false
          && preg_match('/بناءً على طلبه(ا|\(ا\))? \./u', $hAt) === 1); // (2026-09-15) طلبه/طلبها/طلبه(ا) حسب جنس الموظف بملفه
    // (2026-08-20) «بدون تابلو»: مع المكوّنات المختارة = تفصيل سطوراً (أساس + إضافي + الإجمالي) لا جدولاً
    $hAtC = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'salaire'], ['extra']);
    check('إفادة راتب بلا تابلو: التفصيل سطوراً مع المكوّنات (أساس + الأجر الإضافي + الإجمالي)',
          strpos($hAtC, 'وفق التفصيل الآتي') !== false
          && strpos($hAtC, '- الأجر الإضافي :') !== false
          && strpos($hAtC, '- الإجمالي :') !== false
          && strpos($hAtC, '<table dir="rtl"') === false);
    // (2026-08-16) خيار صفة الموقّع بإفادة الراتب: الرئيسة/الإدارة/المدير (المدير افتراضياً)
    check('إفادة راتب: خيار الإمضاء (الرئيسة/الإدارة/المدير) والافتراضي المدير',
          strpos($hAt, 'المدير — التوقيع والختم') !== false
          && strpos($hAt, 'name="sig_t"') !== false && strpos($hAt, 'الرئيسة') !== false);
    $hAtR = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'salaire', 'sig_t' => 'raisa'], []);
    check('إفادة راتب: اختيار «الرئيسة» يبدّل سطر التوقيع', strpos($hAtR, 'الرئيسة — التوقيع والختم') !== false);
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
    // ✍️ (2026-08-25) «في شي مكرر بمركز التقارير»: بطاقة «Résumé mensuel» أُزيلت (مكرّرة —
    // أعمدتها ضمن État des salaires) ولا ترجع؛ التقرير نفسه يبقى شغّالاً بالرابط المباشر
    $repSrc25 = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
    check('مركز التقارير بلا مكرّر: لا بطاقة «Résumé mensuel» (ضمن كشف رواتب كل الموظفين) والرابط المباشر شغّال',
          strpos($repSrc25, "'fr'=>'Résumé mensuel'") === false
          && strpos($repSrc25, "report === 'monthly_summary'") !== false);
    // ✍️ (2026-08-25) «بدي المجموع» بلائحة الموظفين: سطر المجموع بالعملتين متل الخانات
    // (دولار المجموع = جمع أرقام الصفوف المدوّرة — dualFromUsd يتبع زرّ العملة)
    $hEL25 = renderPage('pages/reports.php', ['report' => 'employee_list', 'cols' => ['name', 'extra_wage', 'transport']], ['extra','transport']);
    check('لائحة الموظفين: سطر المجموع موجود وبالعملتين للأعمدة المالية',
          strpos($hEL25, 'المجموع (') !== false
          && strpos($repSrc25, 'dualFromUsd($colTot[$k], $colTotUsd[$k])') !== false
          && strpos($repSrc25, "\$colTotUsd = array_fill_keys(['salary','extra_wage','aide','transport','composed'], 0.0);") !== false);
    // «وهون شو المكرر» (2026-08-25): بطاقة «Infos générales» أُزيلت (أعمدتها ضمن لائحة الموظفين)
    // — نموذج p13 نفسه يبقى شغّالاً بالرابط المباشر بofficial_forms
    check('مركز التقارير بلا مكرّر: لا بطاقة «Infos générales» (ضمن لائحة الموظفين) ونموذج p13 المباشر شغّال',
          strpos($repSrc25, "'fr'=>'Infos générales'") === false
          && strpos((string)file_get_contents(__DIR__ . '/../pages/official_forms.php'), "\$form === 'general_info'") !== false);
    // 🎓 (2026-08-27) تقرير «الداخلون في الملاك بتاريخ» (طلب المستخدم — الافتراضي 1/10/2023):
    // بطاقته بمركز التقارير + الصفحة تُعرض بالقالب الموحّد مع فلتر التاريخ ومنتقي المدارس
    $hTit27 = renderPage('pages/reports.php', ['report' => 'titularized', 'tdate' => '2023-10-01'], []);
    check('تقرير الداخلين في الملاك: البطاقة بالمركز + الصفحة تعمل بفلتر التاريخ',
          strpos($repSrc25, "'fr'=>'Entrés au cadre (par date)'") !== false
          && strpos($hTit27, 'الداخلون في الملاك بتاريخ') !== false
          && strpos($hTit27, 'name="tdate"') !== false);
    // 🏫 (2026-09-04) «مين داخل على المدرسة بتاريخ/بسنة لكل مدرسة»: وضع الدخول إلى المدرسة (hire_date، كل الفئات) + مدى «كل السنة الدراسية»
    $hHire04 = renderPage('pages/reports.php', ['report' => 'titularized', 'tmode' => 'hire', 'tspan' => 'year', 'tdate' => '2023-10-01'], []);
    $nHire04 = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND hire_date BETWEEN '2023-10-01' AND '2024-09-30'")->fetchColumn();
    check('تقرير الداخلين إلى المدرسة: بطاقة بالمركز + وضع hire (كل الفئات) + مدى السنة الدراسية 1/10→30/9 + العدد = استعلام مباشر',
          strpos($repSrc25, "'fr'=>\"Entrés à l'école (par date)\"") !== false
          && strpos($hHire04, 'الداخلون إلى المدرسة خلال السنة الدراسية 2023-2024') !== false
          && strpos($hHire04, 'name="tmode"') !== false && strpos($hHire04, 'name="tspan"') !== false
          && strpos($hHire04, 'العدد: ' . $nHire04) !== false,
          "n=$nHire04");
    // 🗓️ (2026-08-27) حالة تحديث الأساتذة بسنة التحديث («يبينو اللي بدون تحديث بنفس السنة
    // أو اختار السنة»): منتقي السنة + «ما بعتوا» = ما بعتوا بالسنة المختارة (نافذة 1/7→30/6،
    // إرسال الصيف يُحسب للسنة الجاية) + خيار «كل السنين» = السلوك القديم
    $isSrc27 = (string)file_get_contents(__DIR__ . '/../pages/info_status.php');
    $hIS27 = renderPage('pages/info_status.php', ['sy' => '2026-2027'], []);
    check('حالة تحديث الأساتذة: فلتر سنة التحديث (منتقي السنة + نافذة تموز→حزيران + كل السنين)',
          strpos($isSrc27, 'function updateYearOfToday') !== false
          && strpos($isSrc27, "'-07-01 00:00:00'") !== false
          && strpos($isSrc27, "s.submitted_at >= ? AND s.submitted_at < ?") !== false
          && strpos($hIS27, 'name="sy"') !== false
          && strpos($hIS27, 'سنة التحديث') !== false
          && strpos($hIS27, 'كل السنين') !== false);
    // ✍️ (2026-08-25) «بدون الفراطات — داون للرقم بالراتب»: كل دولار معروض صحيح (تدوير لتحت)
    // — الأساس بالليرة حسب السلسلة لا يُمسّ، والتدوير على ناتج الجمع (أساس+إضافي قبل الضرائب)
    // والمجاميع جمع الأرقام الشهرية المدوّرة نفسها. فحص حي: لا سنتات بالكشف السنوي المعروض
    check('بدون فراطات بالدولار: الكشف السنوي المعروض بلا أي مبلغ دولار بكسور (X.XX $)',
          preg_match('/\d\.\d{2}\s*\$/u', $hAn0) === 0 && preg_match('/\$\s*[\d,]+\.\d{2}/u', $hAn0) === 0);
    // ✍️ (2026-08-25) قانون أشهر تعويض النقل («من تشرين الأول لحزيران ضمناً يعني 9 أشهر
    // للداخلين بالملاك... وكمان للمتعاقدين»): نافذة 10→6 للأساتذة، قابلة للتعديل بالإعدادات،
    // سارية من 2026-2027 — الموظف الإداري يداوم الصيف فنقله كل السنة، و2025-2026 لا تُمسّ
    check('أشهر النقل: المنطق الحي — تموز/آب/أيلول بلا نقل للأساتذة من 2026-2027، وحزيران وتشرين الأول ضمن النافذة',
          transportMonthActive(7, 'enseignant_titulaire', '2026-2027') === false
          && transportMonthActive(8, 'enseignant_contractuel', '2026-2027') === false
          && transportMonthActive(9, 'enseignant_titulaire', '2026-2027') === false
          && transportMonthActive(6, 'enseignant_titulaire', '2026-2027') === true
          && transportMonthActive(10, 'enseignant_contractuel', '2026-2027') === true
          && transportMonthActive(1, 'enseignant_titulaire', '2027-2028') === true);
    check('أشهر النقل: الموظف الإداري نقله كل السنة + سنة 2025-2026 المدفوعة لا تُمسّ حتى عند إعادة الحساب',
          transportMonthActive(7, 'employe', '2026-2027') === true
          && transportMonthActive(8, 'employe', '2027-2028') === true
          && transportMonthActive(7, 'enseignant_titulaire', '2025-2026') === true
          && transportMonthActive(9, 'enseignant_contractuel', '2025-2026') === true);
    $pcSrc25 = (string)file_get_contents(__DIR__ . '/../includes/payroll_calculator.php');
    $stSrc25 = (string)file_get_contents(__DIR__ . '/../pages/settings.php');
    check('أشهر النقل: بوابة المحرّك (bonusComponents) + شفاء الصفوف المولّدة + نافذة قابلة للتعديل بالإعدادات مع إعادة حساب تلقائية',
          strpos($pcSrc25, 'transportMonthActive((int)$this->month') !== false
          && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), 'healTransportWindow20260825();') !== false
          && strpos($stSrc25, "'transport_start_month', 'transport_end_month'") !== false
          && strpos($stSrc25, 'name="transport_start_month"') !== false
          && strpos($stSrc25, "recalcSalariesInRange(\$db, '2026-10-01', null)") !== false);
    // فحص حي بالقاعدة: لا صفّ أستاذ من 2026-2027 وطالع فيه نقل بشهر خارج النافذة
    $trBad = 0;
    try {
        foreach (getDB()->query("SELECT ms.month, ms.school_year, e.employee_type FROM monthly_salaries ms
                                  JOIN employees e ON e.id=ms.employee_id
                                  WHERE ms.school_year >= '2026-2027' AND ms.transport_lbp > 0
                                    AND e.employee_type IN ('enseignant_titulaire','enseignant_contractuel')") as $trR) {
            if (!transportMonthActive((int)$trR['month'], (string)$trR['employee_type'], (string)$trR['school_year'])) $trBad++;
        }
    } catch (Exception $e) {}
    check('أشهر النقل: لا نقل مخزّناً خارج النافذة بصفوف الأساتذة من 2026-2027 وطالع (الشفاء اشتغل)',
          $trBad === 0, $trBad ? "صفوف مخالفة: $trBad" : 'نظيف');
    // ✍️ (2026-08-25) «أسماء الصفوف بدها تكون بالفرنسي EB7,EB8,EB9»: شفاء ذاتي يملأ name_fr
    // الفاضي بالأسماء المعروفة (زرع 015 كان يبذر بلا فرنسي فيسقط الكشف للعربي) — فحص حي بالقاعدة
    $fnSrc25 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
    classLevelNames('1'); // يشغّل الشفاء
    $clsMissing = 0;
    try {
        $clsMissing = (int)getDB()->query("SELECT COUNT(*) FROM class_levels WHERE (name_fr IS NULL OR name_fr='')
            AND name IN ('روضة أولى','روضة ثانية','روضة ثالثة','الأول أساسي','الثاني أساسي','الثالث أساسي','الرابع أساسي',
                         'الخامس أساسي','السادس أساسي','السابع أساسي','الثامن أساسي','التاسع أساسي','الأول ثانوي','الثاني ثانوي','الثالث ثانوي')")->fetchColumn();
    } catch (Exception $e) {}
    check('الصفوف بالفرنسي EB1-EB9: شفاء ذاتي بالكود (محجوب عن القراءة-فقط) + لا صفّ معروفاً بلا name_fr بالقاعدة',
          strpos($fnSrc25, "'السابع أساسي'=>'EB7'") !== false
          && strpos($fnSrc25, '!isViewer()') !== false
          && $clsMissing === 0, $clsMissing ? "صفوف بلا فرنسي: $clsMissing" : 'كل الصفوف المعروفة إلها فرنسي');
    check('إفادة راتب: سطر «تعويض النقل» مفصول بالتفصيل', strpos($attSrc, "['تعويض النقل', \$transW]") !== false);
    // (2026-08-20) «بدو يكون عنا خيار لسبب ترك العمل» بإفادة صندوق التعويضات: خيار جاهز
    // (استقالة/صرف/بلوغ السن) أو نص حرّ يكتبه — والفاضي يبقى خطاً منقّطاً للتعبئة باليد
    $hAf1 = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'afade_madrasiya', 'opts_set' => 1, 'lv_sel' => 'بلوغ السن القانوني'], []);
    $hAf2 = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'afade_madrasiya', 'opts_set' => 1, 'lv_txt' => 'سبب حرّ للتجربة'], []);
    check('إفادة صندوق التعويضات: خيار سبب الترك (جاهز/حرّ/فاضي منقّط)',
          strpos($hAf1, 'للأسباب الآتية : <strong>بلوغ السن القانوني</strong>') !== false
          && strpos($hAf2, 'للأسباب الآتية : <strong>سبب حرّ للتجربة</strong>') !== false
          && strpos($attSrc, "name=\"lv_sel\"") !== false && strpos($attSrc, "name=\"lv_txt\"") !== false);
    // (2026-08-20) إفادة السفارة: «خيار أنا حط قيمة الراتب بالدولار + شهري أو سنوي»
    $hEm1 = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'embassy', 'opts_set' => 1, 'emb_amt' => 1500, 'emb_cur' => 'usd', 'emb_per' => 'month'], []);
    $hEm2 = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'embassy', 'opts_set' => 1, 'emb_amt' => 18000, 'emb_per' => 'year'], []);
    check('إفادة السفارة: المبلغ اليدوي بالدولار + الفترة شهري/سنوي + التفقيط بالإنكليزي',
          strpos($hEm1, '$1,500 per month') !== false
          && strpos($hEm1, '(One Thousand Five Hundred US Dollars only)') !== false
          && strpos($hEm2, '$18,000 per year') !== false
          && strpos($hEm2, '(Eighteen Thousand US Dollars only)') !== false
          && strpos($attSrc, 'name="emb_cur"') !== false && strpos($attSrc, 'name="emb_per"') !== false
          && numToEnglishWords(90000000) === 'Ninety Million'
          && numToEnglishWords(136445000) === 'One Hundred Thirty-Six Million Four Hundred Forty-Five Thousand');
    // (2026-08-20) «أي إفادة موجودة أنا اختار دغري بأي لغة وتترجم صح دغري — وبكل المؤسسات»:
    // كل الإفادات الـ14 لها نسخة فرنسية وإنكليزية كاملة تُختار من أزرار اللغة
    $latTitles = [
        'salaire' => ['Attestation de salaire', 'Salary Certificate'],
        'tadris' => ['Attestation de travail', 'Work'],
        'cnss' => ['À qui de droit', 'To whom it may concern'],
        'riaaya' => ['À qui de droit', 'To whom it may concern'],
        'anhaa_khedme' => ['Lettre de fin de service', 'End-of-Service Letter'],
        'anhaa_mail' => ['Lettre de fin de service', 'End-of-Service Letter'],
        'talab_istiqala' => ['Demande de démission', 'Resignation Request'],
        'afade_madrasiya' => ['Attestation scolaire', 'School Attestation'],
        'isqat_haq' => ['Renonciation de droits', 'Waiver of Rights'],
        'baraa_zimma' => ['Quittance', 'Release'],
        'iqrar' => ['Déclaration', 'Declaration'],
        'aqd_taalim' => ["Contrat d'enseignement", 'Teaching Contract'],
        'notice_school' => ['Avertissement', 'Warning'],
        'notice_mail' => ['Avertissement', 'Warning'],
    ];
    $latBad = [];
    foreach ($latTitles as $lt => $pair) {
        $hF = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => $lt, 'lang_doc' => 'fr'], []);
        if (strpos($hF, $pair[0]) === false || strpos($hF, 'FATAL') !== false) $latBad[] = $lt . '/fr';
        $hE = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => $lt, 'lang_doc' => 'en'], []);
        if (strpos($hE, $pair[1]) === false || strpos($hE, 'FATAL') !== false) $latBad[] = $lt . '/en';
    }
    check('كل الإفادات الـ14 تصدر بالفرنسية والإنكليزية بعناوينها الصحيحة (28 نسخة)',
          !$latBad, $latBad ? implode(',', $latBad) : '');
    // (2026-08-20) «بدي نفس الإفادة باللغة الفرنسية»: إفادة سفارة فرنسية كاملة بزرّ Français
    // + تفقيط فرنسي صحيح (soixante et onze / quatre-vingts / cents)
    $hEmF = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'embassy', 'lang_doc' => 'fr', 'opts_set' => 1, 'emb_amt' => 1500], []);
    check('إفادة السفارة الفرنسية: النص والتفقيط بالفرنسي (والإنكليزية بحالها)',
          strpos($hEmF, 'À qui de droit') !== false
          && strpos($hEmF, 'par mois</strong> (mille cinq cents dollars américains uniquement)') !== false
          && strpos($hEmF, 'Cette attestation lui est délivrée à sa demande.') !== false
          && numToFrenchWords(71) === 'soixante et onze'
          && numToFrenchWords(80) === 'quatre-vingts'
          && numToFrenchWords(200) === 'deux cents'
          && strpos($hEm1, 'To whom it may concern') !== false);
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
// 📊 «بدي مجاميع لكل بند + أقدر حط اللي بدي ياه بالتقرير + أختار مدرسة لحالها أو مع بعض + أختار السنة» (2026-09-06):
//     صفّ لكل مدرسة + صفّ «المجموع» + اختيار البنود items[] + قائمة السنة + التصدير بنفس البنود
$hAtSel = renderPage('pages/reports.php', ['report' => 'annual_totals', 'school_year' => '2025-2026', 'items' => ['cnss', 'scnss']], ['extra', 'aide', 'transport']);
check('المجاميع السنوية: جدول مدارس × بنود مع صفّ المجموع + قائمة السنة + منتقي البنود بالمجموعات',
      strpos($hAtOn, 'المجاميع السنوية — لكل مدرسة ولكل بند') !== false
      && strpos($hAtOn, 'المجموع / Total') !== false
      && strpos($hAtOn, '<select name="school_year"') !== false
      && strpos($hAtOn, 'name="items[]" value="cnss"') !== false
      && strpos($hAtOn, 'name="schools[]"') !== false
      && strpos($hAtOn, '<th>أساس الراتب') !== false && strpos($hAtOn, '<th>ضريبة الدخل') !== false
      && strpos($hAtOn, 'FATAL') === false);
// «عامود الأجر الإضافي لازم يكون قبل الراتب المركّب» (p1 — 2026-09-06): المركّب بعد مكوّناته (إضافي/مكافأة/نقل)
check('المجاميع السنوية: ترتيب الأعمدة — الأجر الإضافي والمكافأة والنقل قبل الراتب المركّب',
      strpos($hAtOn, '<th>الأجر الإضافي') !== false && strpos($hAtOn, '<th>الراتب المركّب') !== false
      && strpos($hAtOn, '<th>الأجر الإضافي') < strpos($hAtOn, '<th>الراتب المركّب')
      && strpos($hAtOn, '<th>تعويض النقل') < strpos($hAtOn, '<th>الراتب المركّب')
      && strpos($hAtOn, '<th>الراتب بعد التدرّج') < strpos($hAtOn, '<th>الأجر الإضافي'));
check('المجاميع السنوية: اختيار بنود الضمان فقط يخفي باقي الأعمدة (أساس الراتب/الضريبة) ويُبقي الضمان',
      strpos($hAtSel, '<th>الضمان — الأجير ٣٪') !== false && strpos($hAtSel, '<th>الضمان — المدرسة ٨٪') !== false
      && strpos($hAtSel, '<th>أساس الراتب') === false && strpos($hAtSel, '<th>ضريبة الدخل') === false
      && strpos($hAtSel, 'FATAL') === false);
$xAtSel = renderPage('pages/reports_export.php', ['report' => 'annual_totals', 'format' => 'xlsx', 'school_year' => '2025-2026', 'items' => ['cnss']], ['extra', 'aide', 'transport']);
check('المجاميع السنوية: التصدير يحمل البنود المختارة (items[]) وملف Excel سليم',
      strpos($xAtSel, 'PK') !== false && strpos($xAtSel, 'FATAL') !== 0
      && strpos((string)file_get_contents(__DIR__ . '/../pages/reports.php'), "'&items[]='") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/reports_export.php'), 'annualTotalSelected()') !== false);
// صحّة المجاميع: صفّ المجموع = مجموع صفوف المدارس (الضمان 3٪ بالليرة) — من المصدر نفسه
require_once __DIR__ . '/../includes/report_helpers.php';
[$rowsChk, $totChk] = annualTotalRows($db, '2025-2026', '', [], '', ' ');
check('المجاميع السنوية: صفّ المجموع = مجموع صفوف المدارس (الضمان ٣٪ + عدد الكشوف)',
      (int)$totChk['cnss'] === array_sum(array_map(fn($r) => (int)$r['cnss'], $rowsChk))
      && (int)$totChk['cnt'] === array_sum(array_map(fn($r) => (int)$r['cnt'], $rowsChk)) && (int)$totChk['cnt'] > 0,
      'cnt=' . $totChk['cnt'] . ' cnss=' . number_format((int)$totChk['cnss']));
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
// 🔍 (2026-09-13 «بس أحطّ أوّل حرف من اسمه لازم دغري يعطيني اللائحة»): من أوّل حرف + الأسماء التي تبدأ بالحرف أوّلاً (تجربة حيّة على الخادم نفسه)
$gs1 = ''; $gsOk = false;
try {
    $gsSrc = (string)file_get_contents(__DIR__ . '/../ajax_search.php'); $hdSrc = (string)file_get_contents(__DIR__ . '/../includes/header.php');
    $_GET['q'] = 'ج'; ob_start(); include __DIR__ . '/../ajax_search.php'; $gsOut = ob_get_clean(); unset($_GET['q']);
    $gsRows = json_decode($gsOut, true) ?: [];
    $gsFirst = $gsRows ? mb_substr((string)$gsRows[0]['ar'], 0, 1) : '';
    $gsOk = strpos($gsSrc, 'mb_strlen($q) < 1') !== false && strpos($gsSrc, 'ORDER BY rk,') !== false && strpos($hdSrc, 'q.length < 1') !== false
         && count($gsRows) >= 5 && $gsFirst === 'ج';
    $gs1 = 'n=' . count($gsRows) . ' first=' . ($gsRows[0]['ar'] ?? '-');
} catch (Throwable $e) { $gs1 = $e->getMessage(); }
check('البحث الشامل: من أوّل حرف (حرف واحد يعطي اللائحة) والأسماء التي تبدأ بالحرف أوّلاً — الهيدر والخادم', $gsOk, $gs1);
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
$getWritePages = ['annual_slip'=>3,'grades'=>3,'employees'=>1,'classes'=>1,'exceptional_laws'=>1,
                  'exchange_rates'=>1,'rates_history'=>1,'social_security'=>1,'salary_scales'=>1,'tax_brackets'=>2,
                  'users'=>2,'schools'=>1];
$gwMissing = [];
foreach ($getWritePages as $pg => $minN) {
    $c = preg_match_all('/requireWriteAction\(/', (string)file_get_contents(__DIR__ . "/../pages/$pg.php"));
    if ($c < $minN) $gwMissing[] = "$pg($c/$minN)";
}
check('أمان: كل عمليات التعديل عبر الروابط محميّة بـrequireWriteAction', empty($gwMissing), $gwMissing ? implode(' ', $gwMissing) : '12 صفحة');
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
// (منذ 2026-08-23 ر5/ر10 طبق الأصل بofficial_export: 100 رواتب بلا نقل + 110 نقل +
//  120 المجموع − 130 النقل − 150 تنزيلات أخرى = 160 = مجموع الأساس الخاضع المخزَّن)
$oxSrc16 = (string)file_get_contents(__DIR__ . '/../pages/official_export.php');
// ملاحظة 2026-08-24: دوال mofQuarterAgg/mofYearEmpData/mofQuarterEmpData انتقلت
// إلى includes/functions.php (ليستعملها مدقّق ملف الوزارة) — فحوص المحرّك تفتّش بالملفين معاً
$oxAll16 = $oxSrc16 . (string)file_get_contents(__DIR__ . '/../includes/functions.php');
// (2026-08-24 «شوف في فرق بين ر5 لحالها وR567؟» + «انتبه ر5 كمان بدها تكون مجموع ر10 على
//  أربع فصول» ⇒ التوحيد الكامل: ر5 السنوي من mofYearEmpData، ر10 الفصلي من
//  mofQuarterEmpData التراكمي، والتقرير الإفرادي من mofCumTax — كله مصدر واحد)
check('تصريح ر5/ر10: المصدر الإفرادي الموحّد (سنوي mofYearEmpData / فصلي تراكمي mofQuarterEmpData / التقرير mofCumTax)',
      strpos($oxSrc16, '$yd5 = mofYearEmpData($db, $fy, $empFilter);') !== false
      && strpos($oxSrc16, "'I31' => \$S5a['paid']") !== false
      && strpos($oxSrc16, "\$yd567 = mofYearEmpData(\$db, \$fy, \$empFilter);") !== false
      && strpos($oxSrc16, '$qd = mofQuarterEmpData($db, $rq, $rqy, $empFilter);') !== false
      && strpos($oxAll16, "\$S['fd'] += \$C9['fd'] - \$P9['fd'];") !== false
      && substr_count($ofSrc, 'mofCumTax($db, $r, $y,') === 2);
// ترابط فعلي بالأرقام من ملف الإكسل المعبّى نفسه (خانات القالب: 100=I29 .. 190=I38)
$r5x = renderPage('pages/official_export.php', ['form' => 'mof_r5', 'fy' => 2025, 'format' => 'xlsx'], [], [2], '', '', $PROJ . '/tmp/reg16.xlsx');
$r5v = [];
if (strpos($r5x, 'PK') === 0) {
    file_put_contents($PROJ . '/tmp/reg16b.xlsx', $r5x);
    $z16 = new ZipArchive();
    if ($z16->open($PROJ . '/tmp/reg16b.xlsx') === true) {
        $sh16 = (string)$z16->getFromName('xl/worksheets/sheet1.xml');
        foreach (['100'=>'I29','110'=>'I30','120'=>'I31','130'=>'I32','150'=>'I34','160'=>'I35','170'=>'I36','180'=>'I37','190'=>'I38'] as $cd => $ref) {
            if (preg_match('/<c r="' . $ref . '"[^>]*><v>(-?\d+)/', $sh16, $mm)) $r5v[$cd] = (int)$mm[1];
        }
        $z16->close();
    }
    @unlink($PROJ . '/tmp/reg16b.xlsx');
}
check('تصريح ر5: ١٢٠ − ١٣٠ − ١٥٠ = ١٦٠ (من خانات الإكسل المعبّى)',
      isset($r5v['120'],$r5v['130'],$r5v['160']) && ($r5v['120'] - $r5v['130'] - ($r5v['150'] ?? 0)) === $r5v['160'],
      json_encode($r5v));
check('تصريح ر5: ١٦٠ − ١٧٠ = ١٨٠ + ١٠٠+١١٠=١٢٠',
      isset($r5v['160'],$r5v['180']) && ($r5v['160'] - ($r5v['170'] ?? 0)) === $r5v['180']
      && isset($r5v['100'],$r5v['120']) && ($r5v['100'] + ($r5v['110'] ?? 0)) === $r5v['120']);
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
// (تحديث 2026-08-06: الخاضع المعروض صار **بعد حسم حصّة التنزيل العائلي** بطلب المستخدم)
check('الضريبة: مجموع «الراتب الخاضع للضريبة» يظهر بالشاشة (كان فارغاً)',
      strpos($repSrc, "'txb'=>taxableAfterFamilyDed(\$r,\$fded43)") !== false && strpos($repSrc, "money(\$a['txb'], \$repRate)") !== false);
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
// ✍️ (2026-08-28) «دولار←ليرة بلا فراطات — داون»: مصدر واحد usdToLbp (floor) بكل مواقع التحويل
$pcSrcU = (string)file_get_contents(__DIR__ . '/../includes/payroll_calculator.php');
check('تحويل دولار←ليرة بلا فراطات (usdToLbp تدوير لتحت + لا ضرب خام بسعر الصرف بالمحرّك)',
      strpos($fnSrc3, 'function usdToLbp') !== false
      && usdToLbp(2.5, 89501) === 223752.0
      && usdToLbp(100, 89500) === 8950000.0
      && strpos($pcSrcU, '*= $this->exchangeRate') === false
      && preg_match('/base_salary_usd.{0,30}\*\s*\$this->exchangeRate/', $pcSrcU) === 0
      && substr_count($pcSrcU, 'usdToLbp(') >= 5
      && strpos((string)file_get_contents(__DIR__ . '/../pages/attestations.php'), "usdToLbp(\$emp['base_salary_usd']") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/bulk_allowances.php'), 'usdToLbp(') !== false);
// والدولار المعروض من الجافاسكريبت أيضاً بلا فراطات (Math.floor لا سنتات)
$appJsU = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
check('formatUSD بالجافاسكريبت بلا فراطات (Math.floor + لا minimumFractionDigits)',
      strpos($appJsU, 'Math.floor(n)') !== false
      && strpos($appJsU, 'minimumFractionDigits') === false);
// وضع العملة: «دولار فقط» لا يخلط الليرة بالدولار في الكشف الشهري
$hUsd = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026], ['extra','aide','transport'], [], 'usd');
$lbpHits = preg_match_all('/L\.L/u', $hUsd);
check('وضع «دولار فقط»: الكشف الشهري بلا خلط عملات', $lbpHits <= 2, "خلايا ليرة=$lbpHits");
// ✍️ (2026-08-28، p1 تيا نخلة) توحيد العملتين بالنماذج الرسمية: كل أعمدة المبالغ تتبع وضع
// العملة (لا أعمدة «مبقّعة» بعضها بدولار وبعضها ليرة فقط). بوضع «دولار فقط» ممنوع يظهر أي
// رقم بحجم الملايين (خلية ليرة متروكة formatLBP كانت تظهر هكذا).
// ✍️ (2026-08-28 «كل شي موحّد مش بس بتقرير معيّن»): المسح يشمل كل التقارير والكشوف والشاشات
// المالية — أي رقم مليوني بوضع «دولار فقط» = خلية ليرة متروكة formatLBP (يُستثنى أكواد ألوان rgba
// والقيم القانونية كالحد الأدنى، لذا يُفحص نطاق الجداول doc فقط حيث وُجد وإلا الصفحة كاملة منظّفة).
// نطاق مدرسة واحدة وسنة معروفة: بمدرسة واحدة تبقى مجاميع الدولار تحت المليون، فأي رقم
// مليوني = خلية ليرة متروكة فعلاً (على كل المدارس مجاميع الدولار السنوية نفسها تتجاوز المليون).
$usSchool = (int)$db->query("SELECT ms.school_id FROM monthly_salaries ms
    WHERE ms.school_year='2025-2026' AND ms.month=10 GROUP BY ms.school_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$usdSweep = [
    'salary_all'      => ['pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025]],
    'payment_list'    => ['pages/official_forms.php', ['form' => 'payment_list', 'month' => 10, 'year' => 2025]],
    'full_register'   => ['pages/official_forms.php', ['form' => 'full_register', 'month' => 10, 'year' => 2025]],
    'differences'     => ['pages/official_forms.php', ['form' => 'differences']],
    'general_report'  => ['pages/official_forms.php', ['form' => 'general_report']],
    'employer_cost'   => ['pages/official_forms.php', ['form' => 'employer_cost']],
    'general_info'    => ['pages/official_forms.php', ['form' => 'general_info']],
    'teaching_staff'  => ['pages/official_forms.php', ['form' => 'teaching_staff']],
    'rep_monthly'     => ['pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025]],
    'rep_cnss'        => ['pages/reports.php', ['report' => 'cnss_summary', 'month' => 10, 'year' => 2025]],
    'rep_tax'         => ['pages/reports.php', ['report' => 'tax_summary', 'month' => 10, 'year' => 2025]],
    'rep_eoc'         => ['pages/reports.php', ['report' => 'eoc_summary', 'month' => 10, 'year' => 2025]],
    'rep_annual'      => ['pages/reports.php', ['report' => 'annual_totals']],
    'rep_emp_list'    => ['pages/reports.php', ['report' => 'employee_list', 'cols' => ['name_ar','salary','extra_wage','aide','transport','composed']]],
    'monthly_payroll' => ['pages/monthly_payroll.php', ['month' => 10, 'year' => 2025]],
];
// التقارير السنوية مجاميع دولارها الحقيقية قد تتجاوز المليون — عتبتها المليار (الليرة السنوية المتروكة مليارات)
$usAnnual = ['differences', 'general_report', 'employer_cost', 'rep_annual'];
foreach ($usdSweep as $usLbl => [$usRel, $usGet]) {
    $hOfU = renderPage($usRel, $usGet, ['extra','aide','transport'], $usSchool ? [$usSchool] : [], 'usd', '2025-2026');
    $usArea = $hOfU;
    if (preg_match_all('/<table[^>]*class="[^"]*doc-table[^"]*".*?<\/table>/su', $hOfU, $mT) && $mT[0]) $usArea = implode('', $mT[0]);
    $usArea = preg_replace('/rgba?\([^)]*\)/', '', $usArea);
    $usArea = preg_replace('/<span class="law-lbp">.*?<\/span>/su', '', $usArea); // 📄 الأساس/الدرجة بالليرة دائماً كالبطاقة (2026-09-21)
    $usPat = in_array($usLbl, $usAnnual, true) ? '/\d{1,3}(?:,\d{3}){3}/' : '/\d{1,3},\d{3},\d{3}/';
    $mil = preg_match_all($usPat, $usArea);
    check("توحيد العملتين: $usLbl بوضع «دولار فقط» بلا أي خلية ليرة متروكة", strlen($hOfU) > 5000 && $mil === 0, "خلايا ليرة=$mil");
}
// 🧮 (2026-08-28) قاعدة النسبة المئوية للأجر الإضافي (شرحها المستخدم بمثال تيا نخلة):
// (الأساس بعد التدرّج ÷ 1500 الرسمي) × النسبة٪ ← داون دولار ← × سعر السوق ← داون للمليون
check('قاعدة نسبة الإضافي: ÷1500 ← نسبة ← داون دولار ← سعر السوق ← داون للمليون (أمثلة البشارة 45٪)',
      bonusPercentLbp(45, 1755000, 89500) === 47000000.0   // تيا تشرين (526.5⇒526⇒47,077,000⇒47م)
      && bonusPercentLbp(45, 2015000, 89500) === 54000000.0 // تيا من كانون (درجة 23)
      && bonusPercentLbp(45, 2545000, 89500) === 68000000.0 // روز/ناتالي
      && bonusPercentLbp(45, 1525000, 89500) === 40000000.0 // كارمن
      && bonusPercentLbp(45, 4195000, 89500) === 112000000.0 // نهاية
      && strpos((string)file_get_contents(__DIR__ . '/../includes/payroll_calculator.php'), 'bonusPercentLbp($pctSum, $baseForPercent, $this->exchangeRate)') !== false);
check('تيا نخلة (1554): علاوتها نسبة 45٪ وأشهرها عالقاعدة (تشرين 47م / كانون 54م تتحرّك مع درجتها)',
      (function () use ($db) {
          $b = $db->query("SELECT value_type, amount FROM employee_bonuses WHERE employee_id=1554 AND bonus_type='prime_fixe' AND school_year='2025-2026' AND is_active=1")->fetch();
          if (!$b || $b['value_type'] !== 'percent' || (float)$b['amount'] !== 45.0) return false;
          $m = $db->query("SELECT month, prime_fixe_lbp FROM monthly_salaries WHERE employee_id=1554 AND school_year='2025-2026' AND month IN (10,1)")->fetchAll(PDO::FETCH_KEY_PAIR);
          return (int)($m[10] ?? 0) === 47000000 && (int)($m[1] ?? 0) === 54000000;
      })());
// ⚖️ (2026-08-28) «طبق القانون على الجميع»: ملاك المدارس ذات النسبة الموحّدة صاروا نسبةً
// (النجاة 55٪/عبرا 65٪/البشارة 45٪/الانتقال 60٪/53٪) — والمبالغ الثابتة باقية حيث لا نسبة
check('قانون النسبة على الجميع: الشفاء موصول + محميّا عبرا (ريتا مارون/ماريا الياس) + ≥150 بند نسبة',
      strpos($fnSrc3, 'function healPercentLawAll20260828') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/header.php'), 'healPercentLawAll20260828();') !== false
      && strpos($fnSrc3, "e.father_name_ar LIKE 'مارون%'") !== false
      && (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE value_type='percent' AND is_active=1")->fetchColumn() >= 150);
// 🧮 (2026-08-28) «بدي 1500 يكون عندي خيار عدلها»: السعر الرسمي إعداد قابل للتعديل،
// وتغييره يعيد حساب أصحاب النسبة تلقائياً، والقاعدة والليبلات تقرأه ديناميكياً
$offOld28 = $db->query("SELECT value FROM settings WHERE `key`='official_usd_rate_lbp'")->fetchColumn();
setSetting('official_usd_rate_lbp', '3000');
$offProbe = bonusPercentLbp(45, 1755000, 89500); // ÷3000: 585×45٪=263.25⇒263$×89500=23,538,500⇒23م
// الإرجاع بsetSetting (لا DELETE — الحذف المباشر يترك الكاش على 3000 لبقية الطلب)
setSetting('official_usd_rate_lbp', $offOld28 !== false ? (string)$offOld28 : '1500');
check('السعر الرسمي (÷1500) خيار بالإعدادات: القاعدة تقرأه حيّاً + تغييره يعيد حساب أصحاب النسبة + الليبلات ديناميكية',
      $offProbe === 23000000.0
      && bonusPercentLbp(45, 1755000, 89500) === 47000000.0
      && strpos($fnSrc3, 'function officialUsdRate') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/settings.php'), "أُعيد حساب أصحاب النسبة المئوية") !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/bulk_allowances.php'), 'officialUsdRateLbl()') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), 'officialUsdRateLbl()') !== false);
// التفقيط بالإفادات يتبع نفس الرقم المعروض (floor لا round)
$attSrcU = (string)file_get_contents(__DIR__ . '/../pages/attestations.php');
check('الإفادات: تفقيط الدولار بالحروف = الرقم المعروض نفسه (floor)',
      strpos($attSrcU, '(int)floor($usdOf($lbp))') !== false
      && strpos($attSrcU, '(int)round($usdOf($lbp))') === false);
$repSrc3 = (string)file_get_contents(__DIR__ . '/../pages/reports.php');
check('الكشف الشهري: كل أعمدة المجاميع بالعملة المختارة (لا formatLBP ثابتة)',
      strpos($repSrc3, "\$dualTot(\$t['total'], \$t['total_usd'])") !== false
      && strpos($repSrc3, "\$dualTot(\$t['net'], \$t['net_usd'])") !== false
      && strpos($repSrc3, "dualLaw(\$t['base'], \$t['base_usd'], true)") !== false);
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
      && strpos((string)file_get_contents(__DIR__ . '/../pages/employees.php'), 'sanitizeAmountCurrency(') !== false);
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
      AND " . leftDateSql() . " BETWEEN '2025-10-01' AND '2026-09-30'
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
      strpos($empSrc20, 'AND " . leftDateSql() . " >= ?"') !== false && strpos($empSrc20, $isNullTrio) === false);
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
      strpos($cssSrc22, '.table { zoom: var(--pz, 1) !important; }') !== false
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
      strpos($fnSrc23, "f.addEventListener('change', function (e) {") !== false && strpos($fnSrc23, "reveal(e.target);") !== false
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
      substr_count($ofSrc26, 'extraAideHeads(\' rowspan="2"\',') >= 4
      && strpos($ofSrc26, "\$nomCols = 19 + compColsCount();") !== false);
// (تحديث 2026-09-15 «p1 بهيدا التقرير مافي عامود للتنزيل العائلي»: المحسومات صارت 8 أعمدة —
//  التنزيل العائلي (حصّة الشهر) قبل الخاضع بعد الحسم — رأسان بمقابلهما خليّتان، لا رأس بلا خلية)
check('الراتب يشمل: «معلومات تفصيلية عن الراتب» — المحسومات 8 أعمدة برؤوس صحيحة (مجموع المحسومات + التنزيل العائلي موجودان)',
      strpos($ofSrc26, '<th colspan="8">المحسومات القانونية</th>') !== false
      && strpos($ofSrc26, '<th>مجموع المحسومات</th>') !== false
      && strpos($ofSrc26, '<th>التنزيل العائلي</th>') === false
      && preg_match('/<th>الأجر الإجمالي<\?= rateHead\(\'mkt\', \$month, \$year\) \?><\/th>\s*<\?= familyDedHeads\(\) \?>\s*<th>ضريبة الدخل<\/th>/u', $ofSrc26) === 1);
check('الراتب يشمل: «معلومات تفصيلية» — المستحق المعروض عبر dueShownLbp والأجر الإجمالي من الظاهر فقط',
      strpos($ofSrc26, "'due'=>dueShownLbp(\$r),") !== false
      && strpos($ofSrc26, "\$sdCols = 16 + compColsCount() + dueColsCount() + netFamColsCount();") !== false); // (2026-09-19 + عمود المستحق الثلاثي، 2026-09-20 + الصافي+العائلي)
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
check('الراتب يشمل: التقرير العام — «الرواتب الصافية» تذكر النقل فقط حين يكون بالمبلغ (لا ذكر للنقل والزرّ مطفأ)',
      strpos($ofSrc26, "<th><?= \$grBi('Salaires nets', 'الرواتب الصافية') ?><?= \$grTransAmt ? \$grSub('avec transport', 'مع تعويض النقل') : '' ?></th>") !== false
      && strpos($ofSrc26, "\$grTransAmt = salaryCompHas('transport');") !== false);
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
      // 🗏 (2026-09-04) grid بصفّ 1fr للجدول بدل flex (الجدول كان يفيض عن الصندوق فيطلع المجموع بصفحة ثانية)
      // 📏 (2026-09-20) 4 صفوف (سطر السعر) والجدول مثبَّت بصفّ 1fr عبر grid-row:4
      && strpos($asSrc29, 'grid-template-rows: auto auto auto 1fr') !== false
      && !preg_match('/\.salary-slip-table \{ flex: 1 1 auto; \}/', $asSrc29));
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
// بلا كحلي · رؤوس فاتحة · المحسومات أحمر فاتح · أرقام عريضة سوداء والدولار تحتها
// بلا ألوان (بطلبه 2026-08-25) · اسم الأستاذ 17pt أبرز عنصر · معلومات 13.5 عريضة · صفحة واحدة
// ✍️ تعديل وحيد بطلبه المباشر (2026-08-25): العربي صار بخط نسخي Noto Naskh Arabic
// («الخط بالعربي بشع») والأرقام/اللاتيني بقيا Cairo — باقي التصميم مجمّد كما هو
check('🔒 البطاقة السنوية (تصميم مجمّد بأمر المستخدم): رؤوس فاتحة والمحسومات بأحمر فاتح وبلا كحلي',
      strpos($asSrc29, '.salary-slip-table thead th { background: #f1f5f9 !important; color: #111 !important;') !== false
      && strpos($asSrc29, 'th.deduction-header { background: #ffe3e3 !important;') !== false
      && strpos($asSrc29, '#1F4E5F !important') === false);
// ✍️ (2026-08-25) «بدون ألوان بخطوط المبالغ»: كل الأرقام سوداء — لا أخضر بالدولار
check('🔒 البطاقة السنوية (تصميم مجمّد): كل مبالغ الليرة 12 عريضة موحّدة والدولار 11 عريض أسود تحتها — بلا ألوان بالأرقام',
      strpos($asSrc29, '.salary-slip-table .sub-lbp { white-space: nowrap; font-size: 12pt !important; font-weight: 700 !important; }') !== false
      && strpos($asSrc29, '.salary-slip-table .num-lbp, .salary-slip-table .num-lbp strong { font-size: 12pt !important;') !== false
      && strpos($asSrc29, "font-size: 11pt !important; font-weight: 700 !important;") !== false
      && strpos($asSrc29, '#047857') === false
      // «P1 بدون ألوان هون» (2026-08-25): لا تخطيط متناوباً بصفوف المبالغ — كل السطور بيض
      && strpos($asSrc29, 'tbody tr:nth-child(even)') === false
      // «P1 بدون لون» (2026-08-25): صفّ المجموع TOTAL بلا خلفية صفراء
      && strpos($asSrc29, '#fff3cd') === false
      && strpos($asSrc29, "'<span class=\"sub-lbp\">' . \$l . '</span><span class=\"cur-usd\">'") !== false);
// ✍️ (2026-09-04) بطلبه المباشر: خط البطاقة Arial بكل اللغات متل التقارير والإفادات — الخط فقط، الباقي مجمّد
check('🔒 البطاقة السنوية (تصميم مجمّد): الخط Arial بكل اللغات (الجدول + المعلومات) + اسم الأستاذ 17pt أبرز عنصر + معلومات عريضة',
      strpos($asSrc29, ".salary-slip, .salary-slip-table, .slip-info { font-family:Arial,'Segoe UI',Tahoma,sans-serif; }") !== false
      && strpos($asSrc29, "font-family:Arial,'Segoe UI',Tahoma,sans-serif !important; color:#000 !important;") !== false
      && strpos($asSrc29, 'Noto Naskh') === false
      && strpos($asSrc29, '.slip-emp-name .slip-pname { font-size: 17pt !important; font-weight: 700 !important;') !== false
      && strpos($asSrc29, '.slip-info .val { font-size: 13.5pt !important; font-weight:700 !important;') !== false);
// 🔤 «بدي الحرف بالعربي ببطاقة الأستاذ متل p1» (2026-09-04): الوزن 800 يقفز على Arial Black (بلا عربي) فينزل
// العربي على Tahoma — ممنوع أي 800 بالبطاقة؛ 700 = Arial Bold بحروفها العربية متل كشف برنامجه القديم
check('🔒 البطاقة السنوية: الحرف العربي Arial Bold متل p1 — لا وزن 800 بالبطاقة (يقفز على Arial Black بلا عربي → Tahoma)',
      !preg_match('/font-weight:\s*800/', $asSrc29));
// 🔤 «المخلصيات لازم تكون على نفس السطر» (2026-09-04 عن p1): اسم المدرسة/الاسم/عنوان الكشف لا ينكسرون —
// مفحوص: 358 كشفاً 2025-2026 كلها ترويسة بسطر واحد وصفحة واحدة (أطول اسم مدرسة 92 حرفاً)
check('🔒 البطاقة السنوية: ترويسة الاسم/المدرسة/العنوان بسطر واحد (nowrap) والصفّ يلفّ ككل عالشاشة الضيّقة',
      strpos($asSrc29, '.slip-emp-name .slip-school, .slip-emp-name .slip-rep, .slip-emp-name .slip-pname { white-space:nowrap; }') !== false
      && strpos($asSrc29, '.slip-emp-name { flex-wrap:wrap; }') !== false);
check('🔒 البطاقة السنوية (تصميم مجمّد): تملأ طول الورقة (188mm/pz + grid 1fr للجدول) وبلا fit القديم',
      strpos($asSrc29, 'min-height: calc(188mm / var(--pz, 1))') !== false
      && strpos($asSrc29, '.salary-slip { display: grid; grid-template-columns: 100%; grid-template-rows: auto auto auto 1fr;') !== false
      && strpos($asSrc29, '&fit=1') === false);
// ✍️ الخط النسخي (بطلبه 2026-08-25): ملف Noto Naskh Arabic محلي + معرَّف بfonts.css
// للعربي فقط (unicode-range) حتى تبقى الأرقام واللاتيني على Cairo ولا يتلخبط الترتيب
$fcSrc29 = (string)file_get_contents(__DIR__ . '/../assets/fonts/fonts.css');
// ✍️ (2026-08-25) «P1 بدون تضييق» (تراجُعه عن التضييق بنفس اليوم): خانات معلومات الأستاذ
// بقياسها الأصلي (3px 8px + اسم 6px 10px) — وسطور المبالغ الأوسع (5px) بقيت بطلبه
// 📏 (2026-09-20) طلبه الصريح من جديد «ضيّق شوي أسطر المعلومات وهيك منقدر نوسّع أسطر المبالغ بدون ما
// تتخطّى A4 — العواميد اتركها»: عمودياً فقط (line-height 1.2 + حشوة 1px 8px + اسم 3px 10px + سعر 1/2px)
// وسطور المبالغ 7px 3px — البطاقة محكومة بالطول فيكبر --pz وتكبر سطور المبالغ
check('🔒 البطاقة السنوية: معلومات الأستاذ مضيَّقة عمودياً (2026-09-20: line-height 1.2 + 1px 8px + اسم 3px 10px) وسطور المبالغ 7px 3px والعواميد كما هي',
      strpos($asSrc29, '.slip-info td { border:1px solid #888 !important; padding: 1px 8px !important; line-height:1.2; }') !== false
      && strpos($asSrc29, 'padding:3px 10px !important; margin-bottom:3px !important; line-height:1.2;') !== false
      && strpos($asSrc29, '.salary-slip .slip-rate { margin:1px 0 2px !important; line-height:1.2; }') !== false
      && strpos($asSrc29, '.slip-info .lbl { font-size: 10.5pt !important; margin-bottom: 0 !important; color:#555 !important; line-height:1.2; }') !== false
      && strpos($asSrc29, '.salary-slip-table td { padding: 7px 3px !important; }') !== false
      && strpos($asSrc29, '.slip-info td { border:1px solid var(--gray-300); padding:6px 10px; vertical-align:top; width:25%; }') !== false /* الشاشة بلا مسّ */);
// 📏 (2026-09-20) جدول المبالغ هو من يأخذ باقي الورقة (1fr) لا جدول المعلومات: سطر السعر (2026-09-19) صار العنصر
// الثالث فانتفخت خانات المعلومات بالفراغ الفائض — grid-row:4 يثبّت الجدول بالصفّ الأخير بوجود سطر السعر أو غيابه
check('🔒 البطاقة السنوية: جدول المبالغ مثبَّت بصفّ 1fr الأخير (grid-row:4 + 4 صفوف) — الفراغ الفائض لسطور المبالغ لا لخانات المعلومات',
      strpos($asSrc29, 'grid-template-rows: auto auto auto 1fr; min-height: calc(188mm / var(--pz, 1));') !== false
      && strpos($asSrc29, '.salary-slip-table { align-self: stretch; grid-row: 4; }') !== false);
check('🔒 البطاقة السنوية: الخط النسخي محلي (naskh-ar.woff2 موجود + @font-face للعربي فقط 400-700)',
      is_file(__DIR__ . '/../assets/fonts/naskh-ar.woff2')
      && filesize(__DIR__ . '/../assets/fonts/naskh-ar.woff2') > 50000
      && strpos($fcSrc29, "font-family: 'Noto Naskh Arabic';") !== false
      && strpos($fcSrc29, 'naskh-ar.woff2') !== false
      && preg_match("/font-family: 'Noto Naskh Arabic';[^}]*unicode-range: U\\+0600-06FF/s", $fcSrc29) === 1);
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
$lockPages30 = ['bulk_allowances', 'classes', 'exchange_rates', 'info_collect',
                'email_settings', 'employees', 'exceptional_laws', 'grades', 'rates_history',
                'salary_scales', 'schools', 'settings', 'social_security', 'tax_brackets', 'users'];
$noLock30 = [];
foreach ($lockPages30 as $lp30) {
    $lpSrc30 = (string)file_get_contents(__DIR__ . '/../pages/' . $lp30 . '.php');
    // ✍️ (2026-08-28) فورمات التعديل داخل نافذة منبثقة (ba-overlay/ba-modal) = قفل مكافئ وأقوى:
    // لا تُفتح إلا بكبسة زر صريحة + تأكيد عند الحفظ (إعادة تصميم صفحة المكافآت «متل البرامج العالمية»)
    if (strpos($lpSrc30, 'lockedit') === false && strpos($lpSrc30, 'ba-overlay') === false) $noLock30[] = $lp30;
}
check('القفل الشامل: كل صفحات التعديل الـ16 على آلية lockedit (أو فورمات بنوافذ منبثقة — قفل مكافئ)',
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
      && substr_count($atSrc32, "\$g('ولا يزال', 'ولا تزال', 'ولا يزال(تزال)') . ' حتى تاريخه ، '") === 2 // (2026-09-15) الصيغة حسب الجنس — (2026-09-24) صارت الفرع «غير التارك» بجانب «ولغاية تاريخ …» للتارك
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
check('✍️ إمضاء نهاية الإفادة على جنب الورقة لا في الوسط (عربي يسار + سفارة fr/en يمين)',
      substr_count($atSrc32, 'margin:42px auto 0 0') >= 3
      && substr_count($atSrc32, 'margin:42px 0 0 auto') >= 2   // نسختا السفارة (فرنسي + إنكليزي) 2026-08-20
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
      strpos($pcSrc33, '= $this->bonusComponents($baseSalary + $echelonValue);') !== false
      && strpos($pcSrc33, 'return $this->computeFrom($baseSalary, $echelonValue, $effectiveGrade, $primeFixe, $aideComp, $transportComp);') !== false
      && strpos($pcSrc33, 'public function bonusComponents(') !== false);
check('تركيب العلاوات: شفاء ذاتي بالبطاقة السنوية (computeAnnualSlip) محجوب عن حسابات القراءة-فقط',
      strpos($asdSrc33, 'overlayStoredYearBonuses((int)$emp[\'id\'], $schoolYear)') !== false
      && strpos($asdSrc33, '!isViewer()') !== false);
check('تركيب العلاوات: حارس العائلات — لا تصفير نقل منقول لا سجلّ له (الفرق من transport_lbp وحده)',
      strpos($pcSrc33, "if (!\$doAdd && !\$doTr && !\$doFam && !\$famZeroAll) return 0;") !== false /* 👨‍👩‍👧 2026-09-20 عائلة التعويض العائلي */
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
    $a33r = $db->query("SELECT total_retenues_lbp, cnss_amount_lbp, income_tax_lbp, caisse_amount_lbp, eoc_grade_lbp, family_allowance_lbp FROM monthly_salaries WHERE employee_id = 1826 AND year = 2025 AND month = 10")->fetch();
    if ($gap33 > 0) {
        // فجوة منقولة: فرق الصافي فقط (المحسومات المخزّنة لا تُمَسّ)
        $okNet33 = (int)$a33['net_salary_lbp'] === (int)$b33['net_salary_lbp'] + $dNet33 && (int)$a33['total_due_lbp'] === (int)$b33['total_due_lbp'] + $dNet33;
    } else {
        // 🧮 (2026-09-17) بلا فجوة (العمود مسجَّل بالملف أصلاً): تغيّر الإضافي يعيد حساب الشهر بقلب المحرّك — المحسومات تتبع الإجمالي والأرقام تركب
        $okNet33 = $a33r && (int)$a33r['total_retenues_lbp'] === (int)$a33r['cnss_amount_lbp'] + (int)$a33r['income_tax_lbp'] + (int)$a33r['caisse_amount_lbp'] + (int)$a33r['eoc_grade_lbp']
            && (int)$a33['net_salary_lbp'] === (int)floor(((int)$b33['base_plus_echelon_lbp'] + 50000000 - (int)$a33r['total_retenues_lbp']) / 1000) * 1000
            && (int)$a33['total_due_lbp'] === (int)$a33['net_salary_lbp'] + (int)$a33r['family_allowance_lbp'] + (int)$a33['transport_lbp']
            && (int)$a33r['total_retenues_lbp'] !== (int)$b33['total_retenues_lbp']; // المحسومات تحرّكت مع الإجمالي فعلاً
    }
    $tx33Ok = $b33 && $a33 && $n33 > 0
        && (int)$a33['prime_fixe_lbp'] === 50000000
        && $okNet33
        && (int)$a33['transport_lbp'] === (int)$b33['transport_lbp'];
    $tx33Detail = $a33 ? ('prime=' . $a33['prime_fixe_lbp'] . ' net=' . $a33['net_salary_lbp'] . ' فجوة=' . $gap33 . ' حسومات ' . ($b33['total_retenues_lbp'] ?? '?') . '→' . ($a33r['total_retenues_lbp'] ?? '?')) : 'صف مفقود';
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
      && strpos($repSrc34, 'empTypeCheckboxes($empTypeState)') !== false // ☑️ (2026-09-25) خانات تشييك بدل «الكل مع بعض»
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
      && strpos($hnd36, "left_date_all = NULL, left_date_cnss = NULL, left_date_finance = NULL, left_date_eoc = NULL") !== false
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
$lv37 = $db->query("SELECT e.id, " . leftDateSql('e.') . " ld
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
$ldAll37 = leftDateSql('e.');
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
check('الكشف يطابق الملف: recalcEmployeeYear بلا سنة = السنة المعروضة فقط (📅 قراره 2026-09-23: التغيير يمسّ السنة التي يُغيَّر فيها وحدها)',
      strpos($pcSrc38, '$sy = $schoolYear ?: writeSchoolYear();') !== false
      && strpos($pcSrc38, '$schoolYear ?: currentSchoolYear()') === false
      && strpos($pcSrc38, 'foreach (array_keys($others) as $oSy)') === false);
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
      AND " . leftDateSql('e.') . " = '9999-12-31'
      AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = b.employee_id AND ms.school_year = b.school_year)
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cand38) {
    $eid38 = (int)$cand38['eid']; $sy38 = $cand38['school_year']; $orig38 = (float)$cand38['amount'];
    $test38 = $orig38 + 1000000; // +مليون ليرة للتجربة
    $y138 = (int)substr($sy38, 0, 4);
    $prm38 = $db->prepare("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id = ? AND month = 10 AND year = ?");
    $oldSess38 = $_SESSION['active_school_year'] ?? null;
    $prm38->execute([$eid38, $y138]); $before38 = (int)$prm38->fetchColumn();
    $_SESSION['active_school_year'] = $cur38; // واقف على سنة أخرى ⇒ (📅 2026-09-23) لا تُمسّ سنة البند
    $db->prepare("UPDATE employee_bonuses SET amount = ? WHERE id = ?")->execute([$test38, (int)$cand38['bid']]);
    recalcEmployeeYear($eid38);
    $prm38->execute([$eid38, $y138]); $untouched38 = (int)$prm38->fetchColumn();
    $_SESSION['active_school_year'] = $sy38; // واقف على سنة البند نفسها ⇒ تُحدَّث
    recalcEmployeeYear($eid38);
    $prm38->execute([$eid38, $y138]);
    $got38 = (int)$prm38->fetchColumn();
    // ترجيع كامل ثم تثبّت أن القيمة رجعت
    $db->prepare("UPDATE employee_bonuses SET amount = ? WHERE id = ?")->execute([$orig38, (int)$cand38['bid']]);
    recalcEmployeeYear($eid38, $sy38);
    $prm38->execute([$eid38, $y138]);
    $back38 = (int)$prm38->fetchColumn();
    if ($oldSess38 === null) unset($_SESSION['active_school_year']); else $_SESSION['active_school_year'] = $oldSess38;
    check('الكشف يطابق الملف (تجربة فعلية): تعديل علاوة سنة + إعادة حساب بلا سنة وأنا على سنتها حدّث أشهرها، وعلى سنة أخرى لم يمسّها (📅 2026-09-23)',
          $got38 === (int)$test38 && $untouched38 === $before38, "موظف $eid38 سنة $sy38 — مخزّن 10/$y138 = " . number_format($got38) . " (المتوقّع " . number_format($test38) . ") · على سنة أخرى: " . number_format($untouched38) . " (كان " . number_format($before38) . ")");
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
// (2026-09-15) الشهر المختار = آخر شهر لا يتجاوز تاريخ الإفادة (قسم 130) — فتاريخ الإفادة يُمرَّر صراحةً بآخر السنة
$h39a = renderPage('pages/attestations.php', ['type' => 'salaire', 'employee_id' => 1677, 'date' => '2026-09-15'], [], [2], '', '2025-2026');
$h39b = renderPage('pages/attestations.php', ['type' => 'salaire', 'employee_id' => 1677, 'date' => '2027-09-15'], [], [2], '', '2026-2027');
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
// (منذ 2026-08-23 ر10 طبق الأصل بofficial_export — منتقي الفصل بشاشة official_forms
//  والأرقام من أشهر الفصل حصراً بmofQuarterAgg)
$of40 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
$ox40 = (string)file_get_contents($PROJ . '/pages/official_export.php') . (string)file_get_contents($PROJ . '/includes/functions.php'); // + functions.php (انتقلت دوال mof* 2026-08-24)
check('ر10 فصلي: فرع مستقل بمنتقي الفصل (rq/rqy) وأشهر الفصل حصراً + طبق الأصل',
      strpos($of40, "elseif (\$form === 'tax_r10'):") !== false
      && strpos($of40, 'name="rq"') !== false
      && strpos($ox40, 'ms.month IN ($rqIn)') !== false
      && strpos($ox40, 'function mofQuarterAgg') !== false);
check('ر5 بقي سنوياً (مجموع الفصول الأربعة — فرع مستقل عن ر10)',
      strpos($of40, "elseif (\$form === 'tax_r5'):") !== false
      && strpos($ox40, 'for ($q = 1; $q <= 4; $q++)') !== false
      && strpos($of40, "form === 'tax_r5' || \$form === 'tax_r10'") === false);
// تجربة فعلية: الفصل ٢/2026 (نيسان-حزيران) مدرسة 2 — تواريخ الفترة بخانات القالب
// (H7..N7) وضريبته (خانة 190=J36) تساوي مجموع القاعدة لنفس الأشهر بالمليم
$dbTax40 = (int)$db->query("SELECT COALESCE(SUM(ms.income_tax_lbp),0) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
    WHERE e.is_deleted=0 AND e.tax_subject=1 AND ms.year=2026 AND ms.month IN (4,5,6) AND ms.school_id=2
      AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0)
      AND " . leftDateSql('e.') . " >= '2025-10-01'
      AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year='2025-2026'
                     AND (base_plus_echelon_lbp>0 OR net_salary_lbp>0 OR total_due_lbp>0))")->fetchColumn();
$x40 = renderPage('pages/official_export.php', ['form' => 'mof_r10', 'rq' => 2, 'rqy' => 2026, 'format' => 'xlsx'], [], [2], '', '', $PROJ . '/tmp/reg40.xlsx');
$v40 = [];
if (strpos($x40, 'PK') === 0) {
    file_put_contents($PROJ . '/tmp/reg40b.xlsx', $x40);
    $z40 = new ZipArchive();
    if ($z40->open($PROJ . '/tmp/reg40b.xlsx') === true) {
        $sh40 = (string)$z40->getFromName('xl/worksheets/sheet1.xml');
        foreach (['H7','I7','J7','L7','M7','N7','J36'] as $ref) {
            if (preg_match('/<c r="' . $ref . '"[^>]*><v>(-?\d+)/', $sh40, $mm)) $v40[$ref] = (int)$mm[1];
        }
        $z40->close();
    }
    @unlink($PROJ . '/tmp/reg40b.xlsx');
}
check('ر10 فصلي (تجربة فعلية): الفصل ٢/2026 — من 1/4/2026 إلى 30/6/2026 وضريبته = مجموع القاعدة بالمليم',
      ($v40['H7'] ?? 0) === 1 && ($v40['I7'] ?? 0) === 4 && ($v40['J7'] ?? 0) === 2026
      && ($v40['L7'] ?? 0) === 30 && ($v40['M7'] ?? 0) === 6 && ($v40['N7'] ?? 0) === 2026
      && $dbTax40 > 0 && ($v40['J36'] ?? -1) === $dbTax40,
      'ضريبة القاعدة: ' . number_format($dbTax40) . ' / بالنموذج: ' . number_format($v40['J36'] ?? -1));
// الافتراضي بلا باراميترات = آخر فصل مكتمل (يُحسب ديناميكياً فلا يفسد الفحص بمرور الوقت)
$q40 = intdiv((int)date('n') - 1, 3) + 1; $q40y = (int)date('Y');
$q40--; if ($q40 < 1) { $q40 = 4; $q40y--; }
$h40b = renderPage('pages/official_forms.php', ['form' => 'tax_r10'], [], [2]);
check('ر10 فصلي (تجربة فعلية): الافتراضي بلا اختيار = آخر فصل مكتمل',
      strpos($h40b, 'form=mof_r10&amp;rq=' . $q40 . '&amp;rqy=' . $q40y) !== false, "المتوقّع rq=$q40 rqy=$q40y");
$h40c = renderPage('pages/official_forms.php', ['form' => 'tax_r5'], [], [2]);
check('ر5 (تجربة فعلية): شاشته تعمل بلا خطأ وتقود للنموذج الرسمي',
      strpos($h40c, 'form=mof_r5') !== false && strpos($h40c, 'FATAL') === false, strlen($h40c) . ' حرف');

/* =====================================================================
 * 41) خيار «تطبيق التنزيل العائلي» بملف الموظف (طلب 2026-08-06): زرّ لكل موظف
 *     يقرّر تطبيق التنزيل العائلي على ضريبته أو لا — العمود يتركّب ذاتياً،
 *     والمحرّك + ر5 + ر10 يحترمونه، والافتراضي مفعّل (كما كان دائماً).
 * =================================================================== */
$fn41 = (string)file_get_contents($PROJ . '/includes/functions.php');
$pc41 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$emp41 = (string)file_get_contents($PROJ . '/pages/employees.php');
$of41 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
$hd41 = (string)file_get_contents($PROJ . '/includes/header.php');
check('التنزيل العائلي اختياري: العمود يتركّب ذاتياً (ensureEmployeeFlagColumns بالهيدر وبحفظ الملف)',
      function_exists('ensureEmployeeFlagColumns')
      && strpos($fn41, "ADD COLUMN apply_family_deduction TINYINT(1) NOT NULL DEFAULT 1") !== false
      && strpos($hd41, 'ensureEmployeeFlagColumns();') !== false
      && strpos($emp41, 'ensureEmployeeFlagColumns();') !== false);
check('التنزيل العائلي اختياري: المحرّك يقرأه من المصدر الوحيد familyDeductionAnnual (يحترم الزرّ والزوج العامل)',
      strpos($pc41, 'familyDeductionAnnual(') !== false
      && strpos($pc41, "\$this->employee['apply_family_deduction'] ?? 1") !== false);
check('التنزيل العائلي اختياري: زرّ بملف الموظف (بطاقة الضريبة) + يُحفَظ مع الملف',
      strpos($emp41, 'name="apply_family_deduction"') !== false
      && strpos($emp41, "'apply_family_deduction' => isset(\$_POST['apply_family_deduction'])") !== false
      && strpos($emp41, "'apply_family_deduction' => 1,") !== false);
// (منذ 2026-08-23 ر5/ر10 بofficial_export — المصدر الوحيد نفسه بmofQuarterAgg)
$ox41 = (string)file_get_contents($PROJ . '/pages/official_export.php') . (string)file_get_contents($PROJ . '/includes/functions.php');
check('التنزيل العائلي اختياري: ر5 ور10 وعمود كشف الرواتب كلهم على المصدر الوحيد familyDeductionAnnual',
      substr_count($ox41, "COALESCE(e.apply_family_deduction,1) afd") === 1
      && substr_count($ox41, "familyDeductionAnnual(\$de['social_status'], \$de['spouse_works'], \$de['afd']") === 1
      && strpos($of41, "\$sfdOf = fn(\$r) => familyDedMonthShare(\$r, (int)\$month, (int)\$year);") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/report_helpers.php'), "familyDeductionAnnual(\$r['social_status'] ?? '', \$r['spouse_works'] ?? 0, \$r['afd'] ?? 1, \$asOf, \$r['gsa'] ?? 0, \$r['gca'] ?? 0, \$eid)") !== false);
// تجربة فعلية (مع ترجيع كامل): موظف خاضع بضريبة موجبة وتنزيل ساري > 0 — طفي الخيار
// يرفع ضريبته الشهرية، وإرجاعه يعيدها كما كانت بالمليم
ensureEmployeeFlagColumns();
$cand41 = $db->query("SELECT e.id, ms.income_tax_lbp t0 FROM employees e
    JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.year = 2026 AND ms.month = 6
    WHERE e.is_deleted = 0 AND e.tax_subject = 1 AND e.employee_type = 'enseignant_titulaire'
      AND ms.income_tax_lbp > 0 AND COALESCE(e.apply_family_deduction, 1) = 1
      AND " . leftDateSql('e.') . " = '9999-12-31'
      AND EXISTS (SELECT 1 FROM family_tax_deductions f WHERE f.social_status = e.social_status
                    AND f.effective_from <= '2026-06-01' AND f.annual_deduction > 0)
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cand41) {
    $cid41 = (int)$cand41['id']; $t0 = (int)$cand41['t0'];
    $tOf41 = $db->prepare("SELECT income_tax_lbp FROM monthly_salaries WHERE employee_id = ? AND month = 6 AND year = 2026");
    $db->exec("UPDATE employees SET apply_family_deduction = 0 WHERE id = $cid41");
    try { (new PayrollCalculator($cid41, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf41->execute([$cid41]); $tNo = (int)$tOf41->fetchColumn();
    $db->exec("UPDATE employees SET apply_family_deduction = 1 WHERE id = $cid41");
    try { (new PayrollCalculator($cid41, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf41->execute([$cid41]); $tBack = (int)$tOf41->fetchColumn();
    check('التنزيل العائلي اختياري (تجربة فعلية): إطفاء الخيار يرفع الضريبة الشهرية',
          $tNo > $t0, "id $cid41 — مع التنزيل: " . number_format($t0) . " / بلا: " . number_format($tNo));
    check('التنزيل العائلي اختياري (تجربة فعلية): إرجاع الخيار يعيد الضريبة كما كانت بالمليم',
          $tBack === $t0, number_format($tBack));
} else {
    check('التنزيل العائلي اختياري (تجربة فعلية)', true, 'لا مرشّح مناسب — تخطٍّ');
}

/* =====================================================================
 * 42) عمود «التنزيل العائلي» مقابل كل أستاذ بكشف ضريبة الدخل (طلب 2026-08-06):
 *     بالشاشة والتصدير معاً — السنوي الساري حسب وضعه الاجتماعي، ويتبع زرّ
 *     «تطبيق التنزيل العائلي» بملفه (مطفأ = 0). نفس مصدر المحرّك.
 * =================================================================== */
$rp42 = (string)file_get_contents($PROJ . '/pages/reports.php');
$rx42 = (string)file_get_contents($PROJ . '/pages/reports_export.php');
// (تصحيح المستخدم 2026-08-06): الكشف شهري ⇒ التنزيل «حصّة الشهر» (السنوي ÷ أشهر دفعه)،
// وعموده **قبل** «الخاضع للضريبة» لأنه يُحسم منه، والخاضع المعروض = بعد الحسم
check('عمود التنزيل العائلي: حصّة الشهر + قبل «الخاضع» + الخاضع بعد الحسم (شاشة، بالمصدر الوحيد)',
      strpos($rp42, 'التنزيل العائلي<br><small style="font-weight:400">حصّة الشهر — مطفأ بملفه = 0</small></th><th>الراتب الخاضع للضريبة<br><small style="font-weight:400">بعد حسم التنزيل</small>') !== false
      && strpos($rp42, "'txb'=>taxableAfterFamilyDed(\$r,\$fded43)") !== false
      && strpos($rp42, "\$fdOf = fn(\$r) => familyDedMonthShare(\$r, (int)\$month, (int)\$year);") !== false);
check('عمود التنزيل العائلي: بتصدير Excel/Word بنفس الترتيب والمنطق',
      strpos($rx42, "'التنزيل العائلي (حصّة الشهر)', 'الراتب الخاضع (بعد حسم التنزيل)'") !== false
      && strpos($rx42, "COALESCE(e.apply_family_deduction,1) afd") !== false
      && strpos($rx42, "\$txb = taxableAfterFamilyDed(\$r, \$fded)") !== false
      && strpos($rx42, '[$comp, $fded, $txb, $tax]') !== false);
// تجربة فعلية: كشف حزيران 2026 مدرسة 2 — مارسيلا (12 شهر دفع): حصّة الشهر = السنوي ÷ 12
// والخاضع الظاهر = المخزّن − الحصّة، والعمود قبل الخاضع بترتيب الخلايا
$fd42 = (int)$db->query("SELECT f.annual_deduction FROM family_tax_deductions f
    JOIN employees e ON e.social_status = f.social_status
    WHERE e.id = 1677 AND f.effective_from <= '2026-06-01'
    ORDER BY f.effective_from DESC LIMIT 1")->fetchColumn();
$mpy42 = max(1, (int)$db->query("SELECT payment_months_per_year FROM employees WHERE id = 1677")->fetchColumn());
$txb42 = (int)$db->query("SELECT taxable_base_lbp FROM monthly_salaries WHERE employee_id = 1677 AND month = 6 AND year = 2026")->fetchColumn();
$share42 = (int)round($fd42 / $mpy42);
$after42 = max(0, $txb42 - $share42);
$h42 = renderPage('pages/reports.php', ['report' => 'tax_summary', 'month' => 6, 'year' => 2026], [], [2]);
$pM42 = mb_strpos($h42, 'مارسيلا');
$row42 = $pM42 !== false ? mb_substr($h42, $pM42, 1200) : '';
check('عمود التنزيل العائلي (تجربة فعلية): بصف مارسيلا الحصّة الشهرية ثم الخاضع بعد حسمها بهذا الترتيب',
      $fd42 > 0 && $row42 !== ''
      && strpos($row42, number_format($share42)) !== false
      && strpos($row42, number_format($after42)) !== false
      && mb_strpos($row42, number_format($share42)) < mb_strpos($row42, number_format($after42)),
      "حصّة: " . number_format($share42) . " / خاضع بعدها: " . number_format($after42));

/* =====================================================================
 * 43) خيارا التعويض العائلي بملف الموظف (طلب 2026-08-06): زرّ «احتساب تعويض
 *     الزوج/الزوجة» + زرّ «احتساب تعويض الأولاد» + قاعدة: الزوج/الزوجة يعمل ⇒
 *     لا تعويض زوجة. 👨‍👩‍👧 (2026-09-20 القانون بلسانه) تعويض الأولاد **كاملاً — لا يُقسَّم**
 *     (الذي يُقسَّم هو تنزيل الأولاد بالضريبة) — يبطل تنصيف 2026-08-06؛ المصدر الواحد familyAllowanceForMonth.
 * =================================================================== */
$fn43 = (string)file_get_contents($PROJ . '/includes/functions.php');
$pc43 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$emp43 = (string)file_get_contents($PROJ . '/pages/employees.php');
check('تعويض عائلي اختياري: العمودان يتركّبان ذاتياً (count_spouse/children_allowance)',
      strpos($fn43, "ADD COLUMN count_spouse_allowance TINYINT(1) NOT NULL DEFAULT 1") !== false
      && strpos($fn43, "ADD COLUMN count_children_allowance TINYINT(1) NOT NULL DEFAULT 1") !== false);
check('تعويض عائلي اختياري: المصدر الواحد يحترم الزرّين + الزوج العامل = لا تعويض زوجة والأولاد كاملاً (لا تقسيم — 2026-09-20)',
      strpos($fn43, "if ((int)(\$emp['count_spouse_allowance'] ?? 1) !== 1) \$sp = 0;") !== false
      && strpos($fn43, "if ((int)(\$emp['count_children_allowance'] ?? 1) !== 1) \$ch = 0;") !== false
      && strpos($fn43, "if (!empty(\$emp['spouse_works'])) \$sp = 0;") !== false
      && strpos($pc43, "\$familyAllowance = familyAllowanceForMonth(\$emp, (int)\$this->month, (int)\$this->year);") !== false
      && strpos($pc43, "\$famChildren = round(\$famChildren / 2);") === false);
check('تعويض عائلي اختياري: زرّان بملف الموظف عند حقلي التعويض ويُحفَظان مع الملف',
      strpos($emp43, 'name="count_spouse_allowance"') !== false
      && strpos($emp43, 'name="count_children_allowance"') !== false
      && strpos($emp43, "'count_spouse_allowance' => isset(\$_POST['count_spouse_allowance'])") !== false
      && strpos($emp43, '<strong>لا يُقسَّم</strong> بين الزوجين') !== false);
// تجربة فعلية (مع ترجيع كامل): موظف فاعل معدّ — نركّب عليه مبلغَي زوجة 600,000 وأولاد 900,000
// ونجرّب الحالات الأربع على شهر 6/2026 عبر عمود family_allowance_lbp المخزّن
ensureEmployeeFlagColumns();
$c43 = $db->query("SELECT e.id, e.spouse_works, e.family_allowance_spouse_lbp sp, e.family_allowance_children_lbp ch,
                          COALESCE(e.count_spouse_allowance,1) cs, COALESCE(e.count_children_allowance,1) cc
    FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.year = 2026 AND ms.month = 6
    WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND ms.net_salary_lbp > 0
      AND " . leftDateSql('e.') . " = '9999-12-31'
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($c43) {
    $cid43 = (int)$c43['id'];
    $fam43 = $db->prepare("SELECT family_allowance_lbp FROM monthly_salaries WHERE employee_id = ? AND month = 6 AND year = 2026");
    $famOf = function () use ($fam43, $cid43, $db) {
        try { (new PayrollCalculator($cid43, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
        $fam43->execute([$cid43]);
        return (int)$fam43->fetchColumn();
    };
    $set43 = function ($sw, $cs, $cc) use ($db, $cid43) {
        $db->exec("UPDATE employees SET spouse_works = $sw, count_spouse_allowance = $cs, count_children_allowance = $cc,
                   family_allowance_spouse_lbp = 600000, family_allowance_children_lbp = 900000 WHERE id = $cid43");
    };
    $set43(0, 1, 1); $fA = $famOf();   // زوجة لا تعمل + الزرّان مفعّلان = 1,500,000
    $set43(0, 0, 1); $fB = $famOf();   // زرّ الزوجة مطفأ = 900,000
    $set43(0, 1, 0); $fC = $famOf();   // زرّ الأولاد مطفأ = 600,000
    $set43(1, 1, 1); $fD = $famOf();   // الزوجة تعمل = 0 زوجة + الأولاد كاملاً = 900,000 (لا تقسيم — 2026-09-20)
    // ترجيع كامل
    $db->exec("UPDATE employees SET spouse_works = " . (int)$c43['spouse_works'] . ", count_spouse_allowance = " . (int)$c43['cs'] . ",
               count_children_allowance = " . (int)$c43['cc'] . ", family_allowance_spouse_lbp = " . (int)$c43['sp'] . ",
               family_allowance_children_lbp = " . (int)$c43['ch'] . " WHERE id = $cid43");
    $fR = $famOf();
    $expR = ((int)$c43['spouse_works'] ? 0 : ((int)$c43['cs'] ? (int)$c43['sp'] : 0))
          + ((int)$c43['cc'] ? (int)$c43['ch'] : 0);
    check('تعويض عائلي (تجربة فعلية): الزرّان يتحكّمان بالمبلغ (كامل/بلا زوجة/بلا أولاد)',
          $fA === 1500000 && $fB === 900000 && $fC === 600000,
          "id $cid43 — كامل: " . number_format($fA) . " / بلا زوجة: " . number_format($fB) . " / بلا أولاد: " . number_format($fC));
    check('تعويض عائلي (تجربة فعلية): الزوج/الزوجة يعمل ⇒ صفر زوجة + الأولاد كاملاً بلا تقسيم (900,000 — القانون بلسانه 2026-09-20)',
          $fD === 900000, number_format($fD));
    check('تعويض عائلي (تجربة فعلية): الترجيع أعاد كل شيء كما كان', $fR === $expR, number_format($fR));
} else {
    check('تعويض عائلي (تجربة فعلية)', true, 'لا مرشّح — تخطٍّ');
}

/* =====================================================================
 * 44) عمود «التنزيل العائلي» بكشف رواتب كل الموظفين (طلب 2026-08-06):
 *     نفس قاعدة كشف الضريبة — حصّة الشهر، قبل «الخاضع للضريبة»، والخاضع بعد الحسم.
 * =================================================================== */
$of44 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('كشف رواتب كل الموظفين: عمود التنزيل العائلي (حصّة الشهر) قبل الخاضع والخاضع بعد الحسم',
      strpos($of44, '<th>التنزيل العائلي<br><small style="font-weight:400">حصّة الشهر</small></th><th>الراتب الخاضع للضريبة<br><small style="font-weight:400">بعد حسم التنزيل</small></th>') !== false
      && strpos($of44, "'txb'=>taxableAfterFamilyDed(\$r,\$sfd)") !== false
      && strpos($of44, "\$sfdOf = fn(\$r) => familyDedMonthShare(\$r, (int)\$month, (int)\$year);") !== false);
// تجربة فعلية: صف مارسيلا بكشف 6/2026 — الحصّة ثم الخاضع بعدها بهذا الترتيب (نفس أرقام كشف الضريبة)
$h44 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], ['extra','aide','transport'], [2]);
$p44 = mb_strpos($h44, 'مارسيلا');
$row44 = $p44 !== false ? mb_substr($h44, $p44, 1600) : '';
check('كشف رواتب كل الموظفين (تجربة فعلية): بصف مارسيلا حصّة التنزيل ثم الخاضع بعد حسمها بالترتيب',
      $row44 !== '' && isset($share42, $after42) && $share42 > 0
      && strpos($row44, number_format($share42)) !== false
      && strpos($row44, number_format($after42)) !== false
      && mb_strpos($row44, number_format($share42)) < mb_strpos($row44, number_format($after42)),
      isset($share42, $after42) ? ('حصّة: ' . number_format($share42) . ' / خاضع بعدها: ' . number_format($after42)) : '؟');

/* =====================================================================
 * 45) «الراتب المركّب» بلا تعويض النقل (قاعدة المستخدم 2026-08-06): العمود الذي
 *     يلي الإضافي والمكافأة لا يحوي النقل — النقل عمود مستقل قبل «الإجمالي
 *     المتوجب» فيُجمع فيه. مركزي بـcomposedSalaryLbp فيعمّ كل الكشوف والتصدير.
 * =================================================================== */
$fn45 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('المركّب بلا نقل: composedSalaryLbp المركزية لا تجمع transport_lbp والتسمية بلا «النقل»',
      strpos($fn45, "لا يدخل بالمركّب أبداً") !== false
      && preg_match('/function composedSalaryLbp.*?^}/ms', $fn45, $m45) === 1
      && strpos($m45[0], 'transport_lbp') === false
      && strpos($fn45, "array_intersect(salaryComp(), ['extra', 'aide'])") !== false);
check('المركّب بلا نقل: خيار النقل بالشريط مستقل عن المركّب («عمود تعويض النقل:» بثلاث حالات، و«موجود مع المبلغ (يُجمع بالمستحق)»)',
      strpos($fn45, 'عمود تعويض النقل:') !== false && strpos($fn45, 'موجود مع المبلغ (يُجمع بالمستحق)') !== false);
// تجربة فعلية: كشف رواتب كل الموظفين 6/2026 بكل الخيارات — مركّب مارسيلا 56,145,000
// (بلا النقل 9,000,000) لا 65,145,000، وعمود النقل مستقل والمستحق 59,786,424 يجمعه
$h45 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], ['extra','aide','transport'], [2]);
$p45 = mb_strpos($h45, 'مارسيلا');
$row45 = $p45 !== false ? mb_substr($h45, $p45, 1800) : '';
check('المركّب بلا نقل (تجربة فعلية): مركّب مارسيلا 56,145,000 والنقل 9,000,000 مستقل والمستحق 59,786,000 (الصافي داون للألف 2026-09-04)',
      $row45 !== ''
      && strpos($row45, '56,145,000') !== false
      && strpos($row45, '65,145,000') === false
      && strpos($row45, '9,000,000') !== false
      && strpos($row45, '59,786,000') !== false);
// والكشف الشهري العام أيضاً: النقل قبل «الإجمالي المتوجب» مباشرة (بنية الرأس)
$rp45 = (string)file_get_contents($PROJ . '/pages/reports.php');
check('الكشف الشهري: عمود النقل يسبق «الإجمالي المتوجب» مباشرة',
      strpos($rp45, 'transportHead() ?><?= dueHead() ?>') !== false); // (2026-09-19) رأس المستحق صار dueHead

/* =====================================================================
 * 46) «الزوج/الزوجة يعمل» يُسقط زيادة الزوج من التنزيل العائلي (حالة زاهية الحاج
 *     2026-08-06): المصدر الوحيد familyDeductionAnnual — متأهل وزوجه يعمل ⇒
 *     التنزيل = الشخصي (عازب) + حصص الأولاد فقط، بلا زيادة الزوج (225 مليون).
 * =================================================================== */
$fn46 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('زيادة الزوج: الدالة الموحّدة familyDeductionAnnual موجودة (زوج عامل ⇒ حذف الزيادة، بحدّ العازب)',
      function_exists('familyDeductionAnnual')
      && strpos($fn46, "\$ded = max(\$single, \$ded - max(0, \$married0 - \$single));") !== false);
// تجربة فعلية على زاهية الحاج (18، متأهلة بلا أولاد، ضريبتها 0 لأن 656.4م < 675م) مع ترجيع:
// تعليم «الزوج يعمل» ⇒ تنزيلها يصير 450م (بلا زيادة الزوج) ⇒ تظهر ضريبة فعلية
$z46 = $db->query("SELECT spouse_works, social_status FROM employees WHERE id = 18")->fetch(PDO::FETCH_ASSOC);
if ($z46 && $z46['social_status'] === 'marie_sans_enfants') {
    // (منذ «طفي زيادة الزوج» 2026-08-23 الافتراضي مطفأ — الصيغ هنا بتضوية صريحة gsa=1)
    $fdBase46 = familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-06-01', 1);
    $fdSW46   = familyDeductionAnnual('marie_sans_enfants', 1, 1, '2026-06-01', 1);
    $fdSingle46 = familyDeductionAnnual('celibataire', 0, 1, '2026-06-01', 1);
    check('زيادة الزوج: متأهل بلا أولاد = 675م، وزوجه يعمل = تنزيل العازب 450م',
          $fdBase46 === 675000000 && $fdSW46 === $fdSingle46 && $fdSW46 === 450000000,
          number_format($fdBase46) . ' → ' . number_format($fdSW46));
    // زاهية اليوم: gsa=0 ⇒ عندها ضريبة. تضوية الزيادة تصفّرها (675م)، وتعليم «الزوج يعمل»
    // يسقطها حكماً فترجع الضريبة — ثم ترجيع كامل. (معكوس القديم بعد «طفي زيادة الزوج»)
    $g46 = (int)$db->query("SELECT COALESCE(grant_spouse_addition,0) FROM employees WHERE id = 18")->fetchColumn();
    $tOf46 = $db->prepare("SELECT income_tax_lbp FROM monthly_salaries WHERE employee_id = 18 AND month = 6 AND year = 2026");
    $tOf46->execute([]); $t0_46 = (int)$tOf46->fetchColumn();
    $db->exec("UPDATE employees SET grant_spouse_addition = 1 WHERE id = 18");
    try { (new PayrollCalculator(18, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf46->execute([]); $tGr46 = (int)$tOf46->fetchColumn();
    $db->exec("UPDATE employees SET spouse_works = 1 WHERE id = 18");
    try { (new PayrollCalculator(18, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf46->execute([]); $tSW46 = (int)$tOf46->fetchColumn();
    $db->exec("UPDATE employees SET spouse_works = " . (int)$z46['spouse_works'] . ", grant_spouse_addition = " . $g46 . " WHERE id = 18");
    try { (new PayrollCalculator(18, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf46->execute([]); $tBack46 = (int)$tOf46->fetchColumn();
    check('زيادة الزوج (تجربة فعلية): تضويتها لزاهية تصفّر ضريبتها، و«الزوج يعمل» يسقطها حكماً فتعود',
          $t0_46 > 0 && $tGr46 === 0 && $tSW46 === $t0_46,
          'بلا زيادة: ' . number_format($t0_46) . ' / معها: ' . number_format($tGr46) . ' / زوج يعمل: ' . number_format($tSW46));
    check('زيادة الزوج (تجربة فعلية): الترجيع أعاد ضريبتها كما كانت', $tBack46 === $t0_46, number_format($tBack46));
} else {
    check('زيادة الزوج (تجربة فعلية)', true, 'زاهية غير مطابقة للسيناريو — تخطٍّ');
}

/* =====================================================================
 * 47) زرّ «زيادة الزوج/الزوجة بالتنزيل: تُعطى / لا تُعطى» بملف الموظف (طلب
 *     المستخدم 2026-08-06 بعد التفتيش القانوني): خيار صريح يُسقط زيادة الزوج
 *     (225م) وحدها مع بقاء الشخصي + الأولاد — والعمود ذاتي التركيب.
 * =================================================================== */
$fn47 = (string)file_get_contents($PROJ . '/includes/functions.php');
$emp47 = (string)file_get_contents($PROJ . '/pages/employees.php');
$pc47 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
check('زيادة الزوج اختيارية: العمود grant_spouse_addition ذاتي التركيب والدالة الموحّدة تحترمه',
      strpos($fn47, "ADD COLUMN grant_spouse_addition TINYINT(1) NOT NULL DEFAULT 0") !== false
      && strpos($fn47, '$grantSpouseAdd = 0') !== false
      && strpos($fn47, "(!empty(\$spouseWorks) || (int)(\$grantSpouseAdd ?? 1) !== 1)") !== false);
check('زيادة الزوج اختيارية: زرّ بملف الموظف + يُحفَظ + المحرّك يمرّره',
      strpos($emp47, 'name="grant_spouse_addition"') !== false
      && strpos($emp47, "'grant_spouse_addition' => ((string)(\$_POST['grant_spouse_addition'] ?? '0') === '1') ? 1 : 0") !== false // قائمة نعم/كلا منذ 2026-09-10
      && strpos($pc47, "\$this->employee['grant_spouse_addition'] ?? 0") !== false);
// تجربة فعلية على زاهية (متأهلة بلا أولاد، زوجها لا يعمل، ضريبتها 0) مع ترجيع كامل:
// إطفاء «زيادة الزوج» وحده ⇒ تنزيلها 450م ⇒ تظهر ضريبة — والدالة مباشرة: 675م → 450م
$fdG47a = familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-06-01', 1);
$fdG47b = familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-06-01', 0);
check('زيادة الزوج اختيارية: الدالة — تُعطى = 675م، لا تُعطى = 450م (الشخصي فقط)',
      $fdG47a === 675000000 && $fdG47b === 450000000,
      number_format($fdG47a) . ' → ' . number_format($fdG47b));
$z47 = $db->query("SELECT COALESCE(grant_spouse_addition,1) g, spouse_works FROM employees WHERE id = 18")->fetch(PDO::FETCH_ASSOC);
if ($z47 && (int)$z47['spouse_works'] === 0) {
    // (معكوس بعد «طفي زيادة الزوج»): الزر مطفأ ⇒ ضريبة فعلية؛ تضويته تصفّرها؛ الترجيع يعيدها
    $tOf47 = $db->prepare("SELECT income_tax_lbp FROM monthly_salaries WHERE employee_id = 18 AND month = 6 AND year = 2026");
    $tOf47->execute([]); $t0_47 = (int)$tOf47->fetchColumn();
    $db->exec("UPDATE employees SET grant_spouse_addition = 1 WHERE id = 18");
    try { (new PayrollCalculator(18, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf47->execute([]); $tOn47 = (int)$tOf47->fetchColumn();
    $db->exec("UPDATE employees SET grant_spouse_addition = " . (int)$z47['g'] . " WHERE id = 18");
    try { (new PayrollCalculator(18, 6, 2026))->calculateAndSave(); } catch (Exception $e) {}
    $tOf47->execute([]); $tBack47 = (int)$tOf47->fetchColumn();
    check('زيادة الزوج اختيارية (تجربة فعلية): الزر المطفأ = ضريبة فعلية، وتضويته تصفّرها (تنزيل 675م)',
          $t0_47 > 0 && $tOn47 === 0, 'مطفأ: ' . number_format($t0_47) . ' / مضوّى: ' . number_format($tOn47));
    check('زيادة الزوج اختيارية (تجربة فعلية): الترجيع أعاد ضريبتها كما كانت', $tBack47 === $t0_47, number_format($tBack47));
} else {
    check('زيادة الزوج اختيارية (تجربة فعلية)', true, 'زاهية غير مطابقة — تخطٍّ');
}

/* =====================================================================
 * 48) تشييك كل المدارس (طلب 2026-08-06): مجاميع كشف الرواتب لكل مدرسة = القاعدة
 *     بالمليم + فحص «ملفات محتملة التكرار» بصفحة الصحة (مراجعة المستخدم).
 * =================================================================== */
$hc48 = (string)file_get_contents($PROJ . '/pages/health_check.php');
check('كل المدارس: فحص «ملفات بنفس الاسم والفئة بنفس المؤسسة وكلاهما يقبض» بصفحة الصحة (مراجعة)',
      strpos($hc48, 'ملفات بنفس الاسم والفئة بنفس المؤسسة وكلاهما يقبض') !== false
      && strpos($hc48, "GROUP BY e.school_id, nm, e.employee_type HAVING COUNT(*) > 1") !== false);
// تجربة فعلية: 3 مدارس مختلفة الأحجام — عدد «المجموع العام» بكشف الرواتب = عدّ القاعدة
foreach ([3, 4, 6] as $sid48) {
    $db48 = $db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE ms.school_id = $sid48 AND ms.month = 6 AND ms.year = 2026 AND e.is_deleted = 0
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)")->fetchColumn();
    $h48 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], [], [$sid48]);
    preg_match('/المجموع العام \((\d+)\)/u', $h48, $m48);
    check("كل المدارس (تجربة فعلية): كشف رواتب مدرسة $sid48 — العدد الظاهر = عدّ القاعدة",
          isset($m48[1]) && (int)$m48[1] === (int)$db48, 'قاعدة: ' . $db48 . ' / كشف: ' . ($m48[1] ?? '؟'));
}

/* =====================================================================
 * 49) قاعدة المستخدم (2026-08-06): ذو الملفين بنفس المؤسسة ⇒ ملف واحد فقط
 *     خاضع للضريبة والثاني «بلاه» — فحص مراجعة دائم بصفحة الصحة.
 * =================================================================== */
$hc49 = (string)file_get_contents($PROJ . '/pages/health_check.php');
check('ذو الملفين: فحص «موظف بملفين بنفس المؤسسة وكلاهما خاضع للضريبة» بصفحة الصحة (مراجعة)',
      strpos($hc49, 'موظف بملفين بنفس المؤسسة وكلاهما خاضع للضريبة') !== false
      && strpos($hc49, "HAVING COUNT(*) > 1 AND SUM(e.tax_subject = 1) > 1") !== false
      && strpos($hc49, 'أطفئ «خاضع للضريبة»') !== false);

/* =====================================================================
 * 50) «الملف اللي ما في اسم الأب شيلو» (أمر المستخدم 2026-08-06): الشفاء الذاتي
 *     شال الملف المكرّر ذا الأب الوهمي (نقاط/حرف) حذفاً ناعماً وأبقى الكامل —
 *     وما لمس مَن كلا ملفيه بلا أب (جان عاد، قرار يدوي) ولا الشخصين الحقيقيين.
 * =================================================================== */
$fn50 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hd50 = (string)file_get_contents($PROJ . '/includes/header.php');
check('شيل ملف بلا أب: الشفاء موجود ومربوط بالهيدر (حذف ناعم + مطابقة بالاسم + أمان الحالتين)',
      function_exists('healRemoveNoFatherDuplicates20260806')
      && strpos($hd50, 'healRemoveNoFatherDuplicates20260806();') !== false
      && strpos($fn50, "UPDATE employees SET is_deleted = 1 WHERE id = ?") !== false
      && strpos($fn50, 'if (!$with || !$without) continue;') !== false);
// البيانات بعد الشفاء: الملفات الخمسة الوهمية مشالة والملفات الكاملة باقية وجان عاد بملفيه
$gone50 = (int)$db->query("SELECT SUM(is_deleted = 1) FROM employees WHERE id IN (976, 641, 1593, 1795, 1746)")->fetchColumn();
$kept50 = (int)$db->query("SELECT SUM(is_deleted = 0) FROM employees WHERE id IN (69, 248, 419, 1514, 1540)")->fetchColumn();
$jean50 = (int)$db->query("SELECT SUM(is_deleted = 0) FROM employees WHERE id IN (1657, 1794)")->fetchColumn();
check('شيل ملف بلا أب (البيانات): الخمسة الوهمية مشالة، الكاملة باقية، جان عاد بملفيه لقرار المستخدم',
      $gone50 === 5 && $kept50 === 5 && $jean50 === 2, "مشال $gone50/5 · باقٍ $kept50/5 · جان عاد $jean50/2");
// وبالكشوف: المشال ما عاد يظهر (مريم ريشا صارت مرة واحدة بكشف النجاة)
$h50 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], [], [3]);
check('شيل ملف بلا أب (تجربة فعلية): مريم ريشا صارت مرّة واحدة فقط بكشف رواتب النجاة',
      substr_count($h50, 'مريم ريشا') === 1, substr_count($h50, 'مريم ريشا') . ' مرّة');

/* =====================================================================
 * 51) 🔴 القاعدة الرسمية: تجزئة التنزيل العائلي وشطور الضريبة بمدة العمل
 *     (دليل وزارة المالية ص55 — «بدي كل شي حسب القوانين اللبنانية» 2026-08-06):
 *     كل شهر معمول = 1/12 من التنزيل و1/12 من الشطور — المحرّك يُسنوِن ×12
 *     ويقسم ÷12 (لا ×أشهر الدفع/÷أشهر الدفع)، وحصّة الشهر بالكشوف = السنوي÷12.
 * =================================================================== */
$pc51 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$fn51 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hd51 = (string)file_get_contents($PROJ . '/includes/header.php');
$rp51 = (string)file_get_contents($PROJ . '/pages/reports.php');
$of51 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('تجزئة القانون: المحرّك يُسنوِن ×12 ويقسم ÷12 (لا على أشهر الدفع)',
      strpos($pc51, '$annualTaxable = $taxBase * 12;') !== false
      && strpos($pc51, '$monthlyTax = $annualTax / 12;') !== false
      && strpos($pc51, '$taxBase * $monthsPerYear') === false);
check('تجزئة القانون: حصّة الشهر بالكشوف = السنوي ÷ 12 دائماً + ر5/ر10 بحصص الأشهر المعمولة + الشفاء مربوط',
      substr_count((string)file_get_contents($PROJ . '/includes/report_helpers.php'), '$eid) / 12);') === 1
      && substr_count($rp51 . $of51 . (string)file_get_contents($PROJ . '/pages/reports_export.php'), 'familyDedMonthShare($r, (int)$month, (int)$year)') >= 6
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), '$exempt += (int)min($fda / 12 * (int)$de[\'mcnt\'], (float)$de[\'tb\']);') !== false
      && function_exists('healLawfulTaxProration20260806')
      && strpos($hd51, 'healLawfulTaxProration20260806();') !== false);
// (تكملة بقاعدة المستخدم «ما بيصير نيغاتيف»): التنزيل المعروض بحدّ الراتب الخاضع —
// طانوس القزي (عازب 10 أشهر، خاضعه 32م < حصة 37.5م): تنزيله المعروض = 32,000,000
// والخاضع بعده = 0 — لا 45,000,000 القديمة ولا 37,500,000 غير المسقّفة
// (منذ 2026-09-15 السقف داخل المصدر الواحد familyDedMonthShare بreport_helpers — فيعمّ كل الكشوف)
check('التنزيل بحدّ الخاضع: السقف min() مطبَّق بالمصدر الواحد familyDedMonthShare (كل الكشوف)',
      strpos((string)file_get_contents($PROJ . '/includes/report_helpers.php'), 'return min($share, $txb);') !== false
      && strpos($rp51, "min(\$fdOf(\$r), (int)\$r['taxable_base_lbp'])") === false
      && strpos($of51, "min(\$sfdOf(\$r), (int)\$r['taxable_base_lbp'])") === false);
$h51 = renderPage('pages/reports.php', ['report' => 'tax_summary', 'month' => 6, 'year' => 2026], [], [2]);
$p51 = mb_strpos($h51, 'طانوس القزي');
$row51 = $p51 !== false ? mb_substr($h51, $p51, 1200) : '';
$e51 = mb_strpos($row51, '</tr>');
if ($e51 !== false) $row51 = mb_substr($row51, 0, $e51); // صفّه فقط (لا الصف التالي)
check('تجزئة القانون + السقف (تجربة فعلية): تنزيل طانوس المعروض = راتبه الخاضع 32,000,000 (لا 45م ولا 37.5م) وضريبته 0',
      $row51 !== '' && strpos($row51, '32,000,000') !== false
      && strpos($row51, '45,000,000') === false && strpos($row51, '37,500,000') === false);
// تجربة فعلية: كل معدٍّ غير ذي 12 شهراً خاضع وضريبته > 0 بحزيران — ضريبته المخزّنة
// = القانون بالضبط (شطور ×12 − التنزيل الكامل ثم ÷12) ضمن ±1 ل.ل. للتقريب
// (2026-09-10: صار بالمصدر الواحد expectedMonthlyTax — كل الأوضاع العائلية وإعدادات الملف، لا العازب فقط)
$law51 = $db->query("SELECT e.*, ms.taxable_base_lbp txb, ms.income_tax_lbp tax
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0 AND e.payment_months_per_year <> 12 AND e.tax_subject = 1
      AND (e.employee_type = 'enseignant_titulaire' OR e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
      AND ms.month = 6 AND ms.year = 2026 AND ms.income_tax_lbp > 0")->fetchAll(PDO::FETCH_ASSOC);
$bad51 = [];
foreach ($law51 as $r51x) {
    $exp51 = expectedMonthlyTax($r51x, (float)$r51x['txb'], 6, 2026, $db);
    if (abs((int)$r51x['tax'] - $exp51) > 1) $bad51[] = $r51x['id'] . ':' . number_format((int)$r51x['tax']) . '≠' . number_format($exp51);
}
check('تجزئة القانون (تجربة فعلية): ضريبة كل المعدّين غير ذوي الـ12 شهراً المخزّنة بحزيران 2026 = القانون الحيّ بإعدادات ملفهم (expectedMonthlyTax) بالمليم',
      !$bad51 /* 📆 2026-09-20: صار الجميع 12 شهراً فقد تكون العيّنة فارغة — القانون نفسه يُفحص بتجزئة الـ12 بفحوص الضريبة الأخرى */, count($law51) . ' موظفاً' . ($bad51 ? ' — خلل: ' . implode(' · ', $bad51) : ' كلهم مطابقون'));

/* =====================================================================
 * 52) 📜 نماذج الضمان الرسمية الثلاثة بملف إفادات الأستاذ (2026-08-18):
 *     تصريح باستخدام أجير غير مضمون (CNSS-2AA) + إعلام استخدام أجير مضمون
 *     (عنده رقم) + إعلام ترك أجير — قوالب المستخدم الأصلية تُعبَّأ طبق الأصل.
 * =================================================================== */
check('نماذج الضمان الثلاثة: القوالب الرسمية موجودة (استخدام جديد/مضمون سابقاً/ترك)',
      is_file($PROJ . '/assets/templates/cnss_hire_new.xlsx')
      && is_file($PROJ . '/assets/templates/cnss_hire_reg.xlsx')
      && is_file($PROJ . '/assets/templates/cnss_leave.xlsx'));
$at52 = (string)file_get_contents($PROJ . '/pages/attestations.php');
$oe52 = (string)file_get_contents($PROJ . '/pages/official_export.php');
check('نماذج الضمان الثلاثة: الأنواع مضافة بصفحة الإفادات وبمجموعة «راتب وعمل وضمان» بملف الأستاذ',
      strpos($at52, "'cnss_hire_new'") !== false && strpos($at52, "'cnss_hire_reg'") !== false
      && strpos($at52, "'cnss_leave'") !== false
      && preg_match("/'Salaire, travail et CNSS[^]]*'cnss_hire_new','cnss_hire_reg','cnss_leave'/u", $at52) === 1);
check('نماذج الضمان الثلاثة: التصدير الرسمي يعالجها (تعبئة القالب + بديل النسخة المبنية عند تعذّر PDF)',
      strpos($oe52, "['cnss_hire_new', 'cnss_hire_reg', 'cnss_leave']") !== false
      && strpos($oe52, "\$fallbackForm = 'cnss_employ2'") !== false
      && strpos($oe52, "\$fallbackForm = 'cnss_employ'") !== false
      && strpos($oe52, "\$fallbackForm = 'cnss_terminate'") !== false);
// تجربة فعلية: شاشة الخيارات تفتح لموظف حقيقي وفيها زرّا PDF/Excel
$emp52 = $db->query("SELECT id FROM employees WHERE is_deleted = 0 ORDER BY id LIMIT 1")->fetchColumn();
$h52 = renderPage('pages/attestations.php', ['employee_id' => $emp52, 'type' => 'cnss_leave'], [], []);
check('نماذج الضمان الثلاثة (تجربة فعلية): شاشة إعلام الترك تفتح وفيها PDF وExcel وسبب الترك',
      strpos($h52, 'official_export.php?form=cnss_leave') !== false
      && strpos($h52, 'format=xlsx') !== false && strpos($h52, 'سبب ترك العمل') !== false);
// تاريخ الترك يُقرأ من ملف الموظف حصراً (بطلبه 2026-08-18): لا خانة يدوية بالشاشة،
// والتصدير يأخذ left_date_cnss من الملف — وإن كان فارغاً تبقى خانات النموذج فارغة (لا تاريخ اليوم)
check('نماذج الضمان الثلاثة: تاريخ الترك من ملف الموظف حصراً (عرض للعلم فقط، بلا خانة يدوية)',
      strpos($h52, 'name="ld"') === false
      && strpos($oe52, "\$ldCnss = leftDateOfFor(\$emp, 'cnss');") !== false && strpos($oe52, "\$ldTs = \$ldCnss ? strtotime(\$ldCnss) : 0;") !== false
      && strpos($oe52, "\$_GET['ld']") === false);
// «بعدك ما عم بتحط تاريخ الترك» (2026-08-18): إن كان ملف الموظف بلا تاريخ ترك، شاشة
// إعلام الترك تعرض خانة تحفظه **بملف الموظف نفسه** (left_date_cnss عبر POST بحماية CSRF)
$at52b = (string)file_get_contents($PROJ . '/pages/attestations.php');
check('نماذج الضمان الثلاثة: ملف بلا تاريخ ترك → خانة حفظ التاريخ بملف الموظف (POST + CSRF) موجودة',
      strpos($at52b, "isset(\$_POST['save_leave_date'])") !== false
      && strpos($at52b, 'requireCsrf();') !== false
      && strpos($at52b, 'UPDATE employees SET left_date_cnss = ? WHERE id = ?') !== false
      && strpos($at52b, 'name="ld_new"') !== false);
// الخط 12 بكل خانات القوالب الثلاثة («بدي الخط يكون حجم 12» 2026-08-18) — نقرأ ملف الأنماط
// من كل قالب ونتثبّت أن لا حجم خط غير 12 (قاعدة الخط 12 بكل شي)
$fontsOk52 = true; $fontsBad52 = '';
foreach (['cnss_hire_new.xlsx', 'cnss_hire_reg.xlsx', 'cnss_leave.xlsx'] as $t52) {
    $z52b = new ZipArchive();
    if ($z52b->open($PROJ . '/assets/templates/' . $t52) !== true) { $fontsOk52 = false; $fontsBad52 = $t52 . ': لا يفتح'; break; }
    $styles52 = (string)$z52b->getFromName('xl/styles.xml'); $z52b->close();
    preg_match_all('/<sz val="([0-9.]+)"/', $styles52, $m52);
    $others52 = array_diff(array_unique($m52[1]), ['12', '12.0']);
    if ($others52) { $fontsOk52 = false; $fontsBad52 = $t52 . ': ' . implode('،', $others52); break; }
}
check('نماذج الضمان الثلاثة: الخط 12 بكل خانات القوالب (لا حجم آخر بملف الأنماط)', $fontsOk52, $fontsBad52);
// التعبئة بـPHP وحدها (الأونلاين) تكتب القيم فعلاً في قالب الترك
$out52 = $PROJ . '/tmp/regr_cnss3_' . uniqid() . '.xlsx';
try {
    $ok52 = phpFillXlsxTemplate($PROJ . '/assets/templates/cnss_leave.xlsx',
        ['E8' => 'فحص المدرسة', 'B15' => 'فحص الاسم', 'N13' => '911426'], $out52);
    $sheet52 = '';
    if ($ok52) {
        $z52 = new ZipArchive();
        if ($z52->open($out52) === true) { $sheet52 = (string)$z52->getFromName('xl/worksheets/sheet1.xml'); $z52->close(); }
    }
    // قالب openpyxl بلا encoding مصرَّح → DOM يكتب العربي كـ&#x..; (Excel يقرأها) — نفكّها قبل المقارنة
    $dec52 = html_entity_decode($sheet52, ENT_QUOTES | ENT_XML1, 'UTF-8');
    check('نماذج الضمان الثلاثة: التعبئة بـPHP وحدها (أونلاين) تكتب فعلاً في قالب الترك',
          $ok52 && strpos($dec52, 'فحص المدرسة') !== false && strpos($dec52, 'فحص الاسم') !== false
          && strpos($dec52, '911426') !== false);
} catch (Throwable $e) {
    check('نماذج الضمان الثلاثة: التعبئة بـPHP وحدها (أونلاين) تكتب فعلاً في قالب الترك', false, $e->getMessage());
}
@unlink($out52);

/* =====================================================================
 * 53) 🔎 تفتيش سريع بقوائم اختيار الأستاذ («نكتب أول حرف من اسمو أو اسمو
 *     أو رقم الهاتف» 2026-08-18): ويدجت select-search.js تحوّل كل
 *     select[name=employee_id] لخانة تفتيش حيّة (اسم عربي/فرنسي بأول حرف
 *     أو جزء + رقم الهاتف من data-phone) — بالإفادات والبطاقة السنوية
 *     وسيرة الأستاذ والنماذج الرسمية.
 * =================================================================== */
$ssJs53 = (string)file_get_contents($PROJ . '/assets/js/select-search.js');
check('تفتيش الأستاذ: الويدجت موجودة ومربوطة بالفوتر (كل الصفحات) وفيها تطبيع عربي وهاتف',
      $ssJs53 !== '' && strpos($ssJs53, "select[name=\"employee_id\"]") !== false
      && strpos($ssJs53, 'data-phone') !== false && strpos($ssJs53, '[أإآٱ]') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/footer.php'), 'select-search.js') !== false);
check('تفتيش الأستاذ: رقم الهاتف مزروع data-phone بقوائم الصفحات الأربع',
      substr_count((string)file_get_contents($PROJ . '/pages/attestations.php'), 'data-phone=') >= 1
      && substr_count((string)file_get_contents($PROJ . '/pages/annual_slip.php'), 'data-phone=') >= 1
      && substr_count((string)file_get_contents($PROJ . '/pages/employee_history.php'), 'data-phone=') >= 1
      && substr_count((string)file_get_contents($PROJ . '/pages/official_forms.php'), 'data-phone=') >= 1);
// تجربة فعلية: صفحة الإفادات (مدرسة فيها موظفون) تبثّ خيارات فيها هواتف حقيقية + السكربت
$h53 = renderPage('pages/attestations.php', [], [], [2], '', '2025-2026');
check('تفتيش الأستاذ (تجربة فعلية): قائمة الإفادات فيها data-phone بأرقام حقيقية والسكربت محمّل',
      preg_match('/data-phone="[^"]*\d{6}/', $h53) === 1
      && strpos($h53, 'select-search.js') !== false);

/* =====================================================================
 * 54) 🏛️ اسم صاحب العمل تجاه الضمان («كل شي تابع للضمان باسم الراهبات
 *     المخلصيات لسيدة البشارة» 2026-08-19): المؤسسات ذات رقم الضمان
 *     25-82-043 تصدر كل أوراق الضمان (نماذج/إفادات/تقارير) باسم الجمعية،
 *     وما عداها (رقم مختلف) باسم مؤسسته — cnssEmployerSchool.
 * =================================================================== */
$CONG54 = 'الراهبات المخلصيات لسيدة البشارة';
check('صاحب العمل بالضمان: الدالة موجودة والتطبيع يوحّد «25 - 82 - 043» و«25 - 82 - 43»',
      function_exists('cnssEmployerSchool')
      && cnssEmployerNumberKey('25 - 82 - 043') === '25-82-43'
      && cnssEmployerNumberKey('25 - 82 - 43') === '25-82-43'
      && cnssEmployerSchool(['nssf_employer_number' => '25 - 82 - 043', 'name_ar' => 'مدرسة'])['name_ar'] === $CONG54
      && cnssEmployerSchool(['nssf_employer_number' => '22 - 82 - 745', 'name_ar' => 'مكسيموس'])['name_ar'] === 'مكسيموس');
// تجربة فعلية: نموذج ضمان مبني لموظف من مؤسسة 043 → اسم الجمعية؛ ولمؤسسة برقم آخر → اسمها هي
$emp54a = $db->query("SELECT e.id FROM employees e JOIN schools s ON s.id = e.school_id
    WHERE e.is_deleted = 0 AND REPLACE(REPLACE(s.nssf_employer_number,' ',''),'-','') IN ('2582043','258243') LIMIT 1")->fetchColumn();
$emp54b = $db->query("SELECT e.id FROM employees e JOIN schools s ON s.id = e.school_id
    WHERE e.is_deleted = 0 AND e.school_id = 2 LIMIT 1")->fetchColumn();
$sid54a = (int)$db->query("SELECT school_id FROM employees WHERE id = " . (int)$emp54a)->fetchColumn();
$h54a = renderPage('pages/official_forms.php', ['form' => 'cnss_employ', 'employee_id' => (int)$emp54a], [], [$sid54a]);
$h54b = renderPage('pages/official_forms.php', ['form' => 'cnss_employ', 'employee_id' => (int)$emp54b], [], [2]);
check('صاحب العمل بالضمان (تجربة فعلية): نموذج 41A لمؤسسة 043 باسم الجمعية، ولمكسيموس باسمه',
      strpos($h54a, $CONG54) !== false && strpos($h54b, $CONG54) === false && strpos($h54b, 'مكسيموس') !== false);
// إفادة الضمان (لمن يهمه الأمر) باسم الجمعية — وإفادة الراتب (غير الضمان) تبقى باسم المدرسة
$h54c = renderPage('pages/attestations.php', ['employee_id' => (int)$emp54a, 'type' => 'cnss'], [], [$sid54a]);
$h54d = renderPage('pages/attestations.php', ['employee_id' => (int)$emp54a, 'type' => 'salaire'], [], [$sid54a]);
check('صاحب العمل بالضمان (تجربة فعلية): إفادة الضمان باسم الجمعية وإفادة الراتب باسم المدرسة',
      strpos($h54c, $CONG54) !== false && strpos($h54d, $CONG54) === false);
// كشف الضمان الشهري: ترويسته باسم الجمعية لمؤسسة 043
$h54e = renderPage('pages/reports.php', ['report' => 'cnss_summary', 'month' => 6, 'year' => 2026], [], [$sid54a]);
check('صاحب العمل بالضمان (تجربة فعلية): ترويسة كشف الضمان الشهري باسم الجمعية',
      strpos($h54e, $CONG54) !== false);
// التصدير الرسمي (القوالب الثلاثة + إفادة العمل + 190A) يمرّ بـcnssEmployerSchool
$oe54 = (string)file_get_contents($PROJ . '/pages/official_export.php');
check('صاحب العمل بالضمان: التصدير الرسمي (القوالب) يمرّ كله بـcnssEmployerSchool',
      substr_count($oe54, 'cnssEmployerSchool(') >= 3);

/* =====================================================================
 * 55) 📐 «بدي الإفادة مليانة على A4» + «عمل الأجير = أستاذ» (2026-08-19):
 *     قالبا الترك والاستخدام-المضمون كانا يطبعان مصغّرين (~56%) لأن منطقة
 *     الطباعة فيها أعمدة فاضية عريضة والملاءمة تحشر كل شي — قُصّت المنطقة
 *     على الأعمدة المعبّأة + توسيط عمودي + خانة الراتب حروفاً تلتف بسطرين.
 *     و«عمل الأجير» بنماذج الضمان: أستاذ فقط (لا ملاك/متعاقد)، والموظف بوظيفته.
 * =================================================================== */
check('عمل الأجير بالضمان: أستاذ فقط للأساتذة والموظف حسب وظيفته (cnssOccupationAr)',
      function_exists('cnssOccupationAr')
      && cnssOccupationAr(['employee_type' => 'enseignant_titulaire']) === 'أستاذ'
      && cnssOccupationAr(['employee_type' => 'enseignant_contractuel']) === 'أستاذ'
      && cnssOccupationAr(['employee_type' => 'employe', 'job_title' => '']) === 'موظف'
      && strpos((string)file_get_contents($PROJ . '/pages/official_export.php'), '$fnAr = cnssOccupationAr($emp);') !== false
      && substr_count((string)file_get_contents($PROJ . '/pages/official_forms.php'), 'cnssOccupationAr($emp)') >= 2);
// القالبان مطبوعان ملء الصفحة: منطقة طباعة مقصوصة + توسيط أفقي/عمودي + ملاءمة صفحة واحدة
$fit55ok = true; $fit55msg = '';
foreach (['cnss_leave.xlsx' => 'A1:R38', 'cnss_hire_reg.xlsx' => 'A1:O38'] as $t55 => $area55) {
    $z55 = new ZipArchive();
    if ($z55->open($PROJ . '/assets/templates/' . $t55) !== true) { $fit55ok = false; $fit55msg = $t55 . ': لا يفتح'; break; }
    $sh55 = (string)$z55->getFromName('xl/worksheets/sheet1.xml');
    $wb55 = (string)$z55->getFromName('xl/workbook.xml');
    $z55->close();
    $areaRef55 = str_replace(':', ':$', '$' . str_replace(':', ':', $area55)); // A1:R38 → $A$1... (مرجع مطلق)
    $areaOk55 = (strpos($wb55, str_replace(['A1', 'R38', 'O38'], ['$A$1', '$R$38', '$O$38'], $area55)) !== false)
              || (strpos($wb55, $area55) !== false);
    if (!$areaOk55 || strpos($sh55, 'verticalCentered="1"') === false
        || strpos($sh55, 'fitToPage="1"') === false) {
        $fit55ok = false; $fit55msg = $t55; break;
    }
}
check('نماذج الضمان مليانة على A4: منطقة الطباعة مقصوصة + توسيط عمودي + ملاءمة الصفحة', $fit55ok, $fit55msg);
// خانة الراتب حروفاً بنموذج الاستخدام-المضمون مدموجة وتلتف (لا قصّ للنص الطويل)
$z55b = new ZipArchive(); $mrg55 = ''; $wrap55 = false;
if ($z55b->open($PROJ . '/assets/templates/cnss_hire_reg.xlsx') === true) {
    $sh55b = (string)$z55b->getFromName('xl/worksheets/sheet1.xml');
    $st55b = (string)$z55b->getFromName('xl/styles.xml');
    $z55b->close();
    $mrg55 = (strpos($sh55b, '<mergeCell ref="G19:O19"/>') !== false) ? 'ok' : '';
    $wrap55 = strpos($st55b, 'wrapText="1"') !== false;
}
check('نموذج الاستخدام-المضمون: خانة الراتب حروفاً G19:O19 مدموجة وملتفّة', $mrg55 === 'ok' && $wrap55);

/* =====================================================================
 * 56) 🖼️ «الإكسل صح بس PDF غلط» (2026-08-19): أونلاين بلا LibreOffice كان
 *     زرّ PDF يقع على النسخة المبنية بالبرنامج — صار يعرض صورة القالب
 *     الرسمي الفاضي (خانات التعبئة مفرَّغة منه) والقيم نفسها مركّبة فوقها
 *     بإحداثيات معايَرة (<form>.pos.json) → طبق الأصل متل الإكسل بكل مكان.
 * =================================================================== */
$ov56ok = true; $ov56msg = '';
foreach (['cnss_hire_new', 'cnss_hire_reg', 'cnss_leave'] as $f56) {
    $pj56 = $PROJ . '/assets/templates/' . $f56 . '.pos.json';
    if (!is_file($pj56) || !is_file($PROJ . '/assets/templates/' . $f56 . '.png')) { $ov56ok = false; $ov56msg = $f56 . ': ملفات ناقصة'; break; }
    $pd56 = json_decode((string)file_get_contents($pj56), true);
    if (!is_array($pd56) || empty($pd56['cells']) || count($pd56['cells']) < 30 || empty($pd56['fs'])) { $ov56ok = false; $ov56msg = $f56 . ': إحداثيات ناقصة'; break; }
}
check('نماذج الضمان الثلاثة: صورة القالب + إحداثيات المعايرة موجودة للنسخة المصوّرة (أونلاين)', $ov56ok, $ov56msg);
$oe56 = (string)file_get_contents($PROJ . '/pages/official_export.php');
check('نماذج الضمان الثلاثة: زرّ PDF أونلاين = صورة القالب بالقيم المركّبة (لا النسخة المبنية إلا احتياطاً أخيراً)',
      strpos($oe56, "\$form . '.pos.json'") !== false
      && strpos($oe56, "\$form . '.png'") !== false
      && strpos($oe56, "translateX(-50%)") !== false
      && strpos($oe56, "direction:ltr") !== false);
// أرقام مربعات سبب الترك (1-7) تُعاد كتابتها لأن خاناتها مفرَّغة من صورة الخلفية
check('إعلام الترك: أرقام مربعات سبب الترك 1-7 تُكتب مع X المختار (لا مربعات فاضية بالنسخة المصوّرة)',
      strpos($oe56, "\$reasonCells[7] => '7'") !== false);

/* =====================================================================
 * 57) 🖨️ جردة الطباعة الشاملة 2026-08-19 («رتب كل التقارير والإفادات»):
 *     57 مطبوعة فُحصت PDF فعلياً — إصلاحان: طلب تصفية تعويض نهاية الخدمة
 *     أُعيد بناؤه نظيفاً (كانت فورمة الإكسل تُطبع مخربطة)، وبطاقة الأستاذ
 *     صارت صفحة واحدة بتوقيعها (كان التوقيع يقفز لورقة ثانية فاضية).
 * =================================================================== */
$of57 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('طلب تصفية نهاية الخدمة: نموذج مبني نظيف (لا فورمة الإكسل المخربطة eos_settle.html)',
      strpos($of57, "renderFormTemplate('eos_settle'") === false
      && strpos($of57, 'طــلــب تصــفـيــة تـعـويــض نـهـايــة الـخـدمــة') !== false
      && strpos($of57, "cbox('ترك العمل المأجور نهائياً', true, 'X')") !== false);
// موظف إداري من مدرسة فعّالة (المدارس المعطّلة خارج نطاق النماذج فيظهر «اختر الموظف» بدل النموذج)
$emp57 = $db->query("SELECT e.id FROM employees e JOIN schools s ON s.id = e.school_id AND s.is_active = 1
    WHERE e.is_deleted = 0 AND e.employee_type = 'employe' LIMIT 1")->fetchColumn();
$h57 = renderPage('pages/official_forms.php', ['form' => 'cnss_eos_settle', 'employee_id' => (int)$emp57], [], []);
check('طلب تصفية نهاية الخدمة (تجربة فعلية): يُرندر بعناصره (المدير العام + المستندات + حقل الصندوق)',
      strpos($h57, 'حضرة المدير العام للصندوق') !== false
      && strpos($h57, 'إفادة بالأجر والكسب الأخير') !== false
      && strpos($h57, 'حقل مخصص للصندوق') !== false);
check('بطاقة الأستاذ: تكثيف الطباعة صفحة واحدة (tcard) موجود حتى لا يقفز التوقيع لورقة فاضية',
      strpos($of57, '"official-doc rtl tcard"') !== false
      && strpos($of57, '.tcard .sign-row{margin-top:10px') !== false);

/* =====================================================================
 * 58) 🎨 «الواجهات أنعم والخطوط الملونة أنحف والمكرر بلاه» (2026-08-19):
 *     رؤوس البطاقات وأقسام لوحة القيادة وعناوين الأقسام الداخلية صارت
 *     شرائط رفيعة فاتحة بلون القسم (لا أشرطة غامقة عريضة بكتابة بيضاء) +
 *     شريط التصدير مخفيّ بصفحات القوائم (لا شيء يُصدَّر منها) + شيل زرّ
 *     «رجوع لملف الأستاذ» المكرَّر (الرجوع بالهيدر + Dossier موجودان).
 * =================================================================== */
$css58 = (string)file_get_contents($PROJ . '/assets/css/app.css');
check('واجهة أنعم: رؤوس البطاقات شرائط رفيعة فاتحة (لا شريط غامق بكتابة بيضاء)',
      strpos($css58, '.card-header:not([style*="background"]) h3 { color: #fff; }') === false
      && strpos($css58, 'border-inline-start: 3px solid var(--accent, var(--primary));') !== false
      && strpos($css58, '.dash-sec-head .ds-fr { font-weight:700; font-size:12.5px; color:var(--sec-c);') !== false);
check('شريط التصدير مخفيّ بصفحات القوائم (تقارير/إفادات/نماذج/تصاريح — لا شيء يُصدَّر منها)',
      strpos((string)file_get_contents($PROJ . '/pages/reports.php'), "if (\$report === '') \$hideExportToolbar = true;") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/attestations.php'), "if (!\$emp) \$hideExportToolbar = true;") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/official_forms.php'), "if (\$form === '') \$hideExportToolbar = true;") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/tax_declarations.php'), '$hideExportToolbar = true;') !== false);
check('لا أزرار «رجوع لملف الأستاذ» مكرَّرة بصفحات الإفادات (الرجوع بالهيدر + زرّ Dossier)',
      strpos((string)file_get_contents($PROJ . '/pages/attestations.php'), 'رجوع لملف الأستاذ') === false);
// 🧾 الإفادة المدرسية لصندوق التعويضات: تفصيل الراتب (أساس/إضافي/مكافأة ثم المجموع) حسب
// خيارات «الراتب يشمل» — تجربة فعلية بموظفة عندها أجر إضافي
$h58 = renderPage('pages/attestations.php', ['employee_id' => 968, 'type' => 'afade_madrasiya', 'lang_doc' => 'ar',
                  'opts_set' => 1, 'inc_extra' => 1, 'inc_aide' => 1], [], [3]);
check('الإفادة المدرسية (تجربة فعلية): تفصيل الراتب أساس + إضافي + المجموع حسب الخيارات',
      strpos($h58, 'مؤلّفاً ممّا يلي') !== false
      && strpos($h58, 'أساس الراتب :') !== false
      && strpos($h58, 'الأجر الإضافي :') !== false
      && strpos($h58, 'المجموع :') !== false);
$h58b = renderPage('pages/attestations.php', ['employee_id' => 968, 'type' => 'afade_madrasiya', 'lang_doc' => 'ar',
                  'opts_set' => 1], [], [3]);
check('الإفادة المدرسية: الأساس وحده مختاراً → سطر واحد كما كان (بلا تفصيل)',
      strpos($h58b, 'مؤلّفاً ممّا يلي') === false
      && strpos($h58b, 'وكان راتبه الشهري ( دون التعويض العائلي )') !== false);

/* =====================================================================
 * 59) 🏛️ نماذج المالية ر5/ر6/ر10 طبق الأصل («بعتلك اكسل بدي ياهون طبق
 *     الاصل r3,r6,r5,r10» — 2026-08-23): القوالب ملفات المستخدم نفسها
 *     (مفرَّغة) + صورة 300dpi + إحداثيات معايَرة، والتعبئة PHP خالصة تحفظ
 *     القالب بايت-بايت. السنة الميلادية بر5 = مجموع فصول ر10 الأربعة.
 * =================================================================== */
foreach (['mof_r5' => 69, 'mof_r6' => 55, 'mof_r10' => 66] as $tpl59 => $minCells59) {
    $okT = is_file($PROJ . "/assets/templates/$tpl59.xlsx") && is_file($PROJ . "/assets/templates/$tpl59.png");
    $pos59 = json_decode((string)@file_get_contents($PROJ . "/assets/templates/$tpl59.pos.json"), true);
    check("قالب $tpl59: xlsx + png + إحداثيات كاملة", $okT && count($pos59['cells'] ?? []) >= $minCells59,
          'cells=' . count($pos59['cells'] ?? []));
}
check('ر6: مربعات الوضع العائلي الأربعة بالإحداثيات (CB_single..CB_divorced)',
      count(array_intersect(['CB_single', 'CB_married', 'CB_widow', 'CB_divorced'],
            array_keys(json_decode((string)@file_get_contents($PROJ . '/assets/templates/mof_r6.pos.json'), true)['cells'] ?? []))) === 4);
// القالب المفرَّغ: بلا علامة أعزب محفورة (تُعلَّم حسب الموظف عند التوليد)
$z59 = new ZipArchive();
$okCb59 = $z59->open($PROJ . '/assets/templates/mof_r6.xlsx') === true
       && strpos((string)$z59->getFromName('xl/ctrlProps/ctrlProp1.xml'), 'checked=') === false;
$z59->close();
check('ر6: القالب المفرَّغ بلا علامة وضع عائلي محفورة', $okCb59);
// ملف تعريف المؤسسة: العمود يتركّب ذاتياً ومزروع لسان مكسيم من ملفات المستخدم
ensureMofProfile20260823();
$prof59 = json_decode((string)$db->query("SELECT mof_profile FROM schools WHERE REPLACE(COALESCE(finance_number,''),' ','')='2459823' LIMIT 1")->fetchColumn(), true) ?: [];
check('mof_profile: مزروع لسان مكسيم (المكلف بالبريد 271629 + المنطقة 1825/1)',
      ($prof59['contact_reg'] ?? '') === '271629' && ($prof59['region'] ?? '') === '1825/1');
// شاشات الأزرار الجديدة (فلتر الفئة يبقى + زرا الطباعة والإكسل + صندوق معلومات المؤسسة)
$scr59 = renderPage('pages/official_forms.php', ['form' => 'tax_r5'], [], [2]);
check('شاشة ر5: زرا «طباعة/PDF» و«Excel رسمي» + صندوق معلومات المؤسسة',
      strpos($scr59, 'form=mof_r5') !== false && strpos($scr59, 'Excel رسمي') !== false
      && strpos($scr59, 'save_mof_profile') !== false);
$scr59b = renderPage('pages/official_forms.php', ['form' => 'tax_r10'], [], [2]);
check('شاشة ر10: منتقي الفصل + زرا النموذج الرسمي', strpos($scr59b, 'form=mof_r10') !== false && strpos($scr59b, 'الفصل') !== false);
$scr59c = renderPage('pages/official_forms.php', ['form' => 'tax_r6', 'employee_id' => 15], [], [2]);
check('شاشة ر6: سنة ميلادية + زرا النموذج الرسمي', strpos($scr59c, 'form=mof_r6') !== false);
// «الأرقام تركب»: صفحة ر5 المعبّاة تحمل مجموع ضريبة السنة الميلادية من monthly_salaries نفسها
$tax59 = 0; $tb59 = 0;
$q59 = $db->query("SELECT COALESCE(SUM(ms.income_tax_lbp),0) t FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                   WHERE e.is_deleted=0 AND e.tax_subject=1 AND ms.year=2025 AND ms.school_id=2
                     AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0)");
$tax59 = (int)$q59->fetchColumn();
$h59 = renderPage('pages/official_export.php', ['form' => 'mof_r5', 'fy' => 2025], [], [2]);
check('ر5 المعبّى: صورة القالب + مجموع ضريبة 2025 من monthly_salaries (رمز 190)',
      strpos($h59, 'mof_r5.png') !== false
      && ($tax59 === 0 || strpos($h59, number_format($tax59, 2, '.', ',')) !== false),
      'tax=' . $tax59);
$h59b = renderPage('pages/official_export.php', ['form' => 'mof_r10', 'rq' => 1, 'rqy' => 2025], [], [2]);
check('ر10 المعبّى: صورة القالب + زر الطباعة', strpos($h59b, 'mof_r10.png') !== false && strpos($h59b, 'اطبع') !== false);
$h59c = renderPage('pages/official_export.php', ['form' => 'mof_r6', 'emp' => 15, 'fy' => 2025], [], [2]);
check('ر6 المعبّى: صورة القالب + علامة الوضع العائلي ×', strpos($h59c, 'mof_r6.png') !== false && strpos($h59c, '>×<') !== false);
// إكسل ر6 المعبّى: القالب محفوظ بكل أجزائه (تعبئة PHP خالصة لا openpyxl) + المربع معلَّم
$x59 = renderPage('pages/official_export.php', ['form' => 'mof_r6', 'emp' => 15, 'fy' => 2025, 'format' => 'xlsx'], [], [2], '', '', $PROJ . '/tmp/reg59.xlsx');
$okX59 = strpos($x59, 'PK') === 0;
if ($okX59) {
    file_put_contents($PROJ . '/tmp/reg59b.xlsx', $x59);
    $zt59 = new ZipArchive(); $zo59 = new ZipArchive();
    $okX59 = $zt59->open($PROJ . '/tmp/reg59b.xlsx') === true && $zo59->open($PROJ . '/assets/templates/mof_r6.xlsx') === true
          && $zt59->numFiles === $zo59->numFiles
          && strpos((string)$zt59->getFromName('xl/drawings/vmlDrawing1.vml'), '<x:Checked>1</x:Checked>') !== false;
    @$zt59->close(); @$zo59->close();
    @unlink($PROJ . '/tmp/reg59b.xlsx');
}
check('إكسل ر6 المعبّى: كل أجزاء القالب محفوظة + مربع الوضع العائلي معلَّم', $okX59);

/* =====================================================================
 * 60) 🧾 تقرير ضريبة الأستاذ - الموظف («بدي تقرير مرتب بكامل التفاصيل» —
 *     رسمته Desktop\تقرير ضريبة الاستاذ-الموظف.xlsx ‏2026-08-23): كشف اسمي
 *     بأعمدة سطور ر5/ر10 عن فترة من-إلى، محسوب فصلاً ففصلاً بمنطق ر10 —
 *     مجموعه يطابق نموذج ر10 للفصل نفسه بالمليم. ورقة موحّدة + عرضاني + فلاتر.
 * =================================================================== */
$h60 = renderPage('pages/official_forms.php', ['form' => 'tax_emp_report', 'fm' => 4, 'fyr' => 2026, 'tm' => 6, 'tyr' => 2026], [], [2]);
check('تقرير ضريبة الأستاذ-الموظف: ورقة موحّدة + عرضاني + كل الأعمدة',
      strpos($h60, 'تقرير ضريبة الدخل - الأستاذ/الموظف') !== false
      && strpos($h60, 'land-report') !== false
      && strpos($h60, 'doc-sheet') !== false
      && strpos($h60, 'الأجر الإضافي') !== false && strpos($h60, 'التنزيل العائلي') !== false
      && strpos($h60, 'الرواتب الخاضعة') !== false && strpos($h60, 'الضريبة المتوجبة') !== false
      && strpos($h60, 'FATAL') === false);
// «الأرقام تركب»: مجموع ضريبة التقرير للفصل ٢/2026 = قاعدة ر10 نفسها بالمليم ($dbTax40 من فحص 40)
check('تقرير ضريبة الأستاذ-الموظف (تجربة فعلية): مجموع الفصل ٢/2026 يطابق ر10 بالمليم',
      $dbTax40 > 0 && strpos($h60, formatLBP($dbTax40, false)) !== false,
      'ضريبة الفصل: ' . number_format($dbTax40));
$of60 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('تقرير ضريبة الأستاذ-الموظف: ضمن الفلاتر الموحّدة + النماذج المؤسّسية + قائمة التقارير',
      strpos($of60, "'tax_r5', 'tax_r10', 'tax_r7', 'tax_emp_report', 'staff_stats'") !== false
      && strpos($of60, "'tax_emp_report',") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/reports.php'), "tax_emp_report") !== false);

/* =====================================================================
 * 61) 👶 مفتاح «تنزيل الأولاد بالضريبة: يُعطى/لا» («لو عندها اولاد او الزوج
 *     لا يعمل اذا انا مطفي التنزيل عليهن ما لازم يحسب — بس تنزيل الاستاذ
 *     لوحدو» — 2026-08-23): عمود grant_children_addition ذاتي التركيب،
 *     المعادلة المركزية familyDeductionAnnual تحترمه، وكل القارئين يمرّرونه
 *     (المحرّك + كشوف reports/export + salary_all + tax_emp_report + ر5/ر6/ر10).
 * =================================================================== */
ensureEmployeeFlagColumns();
$col61 = $db->query("SHOW COLUMNS FROM employees LIKE 'grant_children_addition'")->fetch(PDO::FETCH_ASSOC);
check('تنزيل الأولاد اختياري: العمود يتركّب ذاتياً والافتراضي مطفأ («هيدا الزر يكون مطفي تلقائيا»)',
      $col61 !== false && (string)$col61['Default'] === '0');
$fd61full = familyDeductionAnnual('marie_3_enfants', 0, 1, '2026-04-01', 1, 1);
$fd61noKids = familyDeductionAnnual('marie_3_enfants', 0, 1, '2026-04-01', 1, 0);
$fd61solo = familyDeductionAnnual('marie_3_enfants', 0, 1, '2026-04-01', 0, 0);
$fd61m0 = familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-04-01', 1, 1);
$fd61single = familyDeductionAnnual('celibataire', 0, 1, '2026-04-01', 1, 1);
check('تنزيل الأولاد اختياري: مطفأ = كأنه متزوج بلا أولاد، ومع طفي الزوج = الشخصي لوحده',
      $fd61full > $fd61noKids && $fd61noKids === $fd61m0 && $fd61solo === $fd61single,
      number_format($fd61full) . ' / ' . number_format($fd61noKids) . ' / ' . number_format($fd61solo));
$pc61 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$emp61s = (string)file_get_contents($PROJ . '/pages/employees.php');
check('تنزيل الأولاد اختياري: المحرّك يمرّره + مفتاح بملف الموظف (يُحفَظ مع الملف)',
      strpos($pc61, "\$this->employee['grant_children_addition'] ?? 0") !== false
      && strpos($emp61s, 'name="grant_children_addition"') !== false
      && strpos($emp61s, "'grant_children_addition' => ((string)(\$_POST['grant_children_addition'] ?? '0') === '1') ? 1 : 0") !== false // قائمة نعم/كلا منذ 2026-09-10
      && strpos($emp61s, "'grant_children_addition' => 0,") !== false);
$gcaCount = 0;
// (2026-08-24: تقرير الضريبة صار يمرّره عبر mofCumTax التراكمية بfunctions.php — تُعدّ كمان)
// (2026-09-15: كشوف الرواتب/الضريبة الخمسة صارت على المصدر الواحد familyDedMonthShare بreport_helpers — يُعدّ هو، ومواضع نداءاته ≥ 7)
$fdShareCalls61 = 0;
foreach (['pages/reports.php', 'pages/reports_export.php', 'pages/official_forms.php'] as $f61) $fdShareCalls61 += substr_count((string)file_get_contents($PROJ . '/' . $f61), 'familyDedMonthShare($r, (int)$month, (int)$year)');
foreach (['pages/reports.php', 'pages/reports_export.php', 'pages/official_forms.php', 'pages/official_export.php', 'includes/functions.php', 'includes/report_helpers.php'] as $f61) {
    $gcaCount += substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$r['gca'] ?? 0")
               + substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$de['gca'] ?? 0")
               + substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$emp['grant_children_addition'] ?? 0")
               + substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$e['grant_children_addition'] ?? (\$e['gca'] ?? 0)");
}
check('تنزيل الأولاد اختياري: كل القارئين يمرّرونه (كشوف + تقرير الضريبة + ر5/ر6/ر10)', $gcaCount >= 4 && $fdShareCalls61 >= 7, 'ممرَّر بـ' . $gcaCount . ' مواضع + ' . $fdShareCalls61 . ' نداءات للمصدر الواحد');

/* =====================================================================
 * 62) 🩹 مايا أبي حبيب («الضريبة 0 وهيدا غلط» — 2026-08-23): تنزيلها شخصي
 *     فقط (زيادة الزوج + الأولاد مطفيان) وشفاء ذاتي يعيد احتساب أشهرها من
 *     2025-2026 محلياً وأونلاين — ضريبتها الشهرية المخزّنة 132,500 لا 0.
 * =================================================================== */
check('مايا أبي حبيب: الشفاء موصول بالهيدر (يعمل أونلاين بعد النشر)',
      function_exists('healMayaTaxFlags20260823')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healMayaTaxFlags20260823();') !== false);
$maya62 = $db->query("SELECT grant_spouse_addition gsa, grant_children_addition gca FROM employees WHERE id=1754")->fetch(PDO::FETCH_ASSOC);
$tax62 = $db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=1754 AND school_year >= '2025-2026' AND school_year <= '2027-2028' AND taxable_base_lbp > 10000000 AND income_tax_lbp <= 0")->fetchColumn();
check('مايا أبي حبيب: مفتاحا الزوج والأولاد مطفيان + لا شهر حقيقي بضريبة صفر من 2025-2026',
      $maya62 && (int)$maya62['gsa'] === 0 && (int)$maya62['gca'] === 0 && (int)$tax62 === 0,
      'أشهر بضريبة صفر: ' . $tax62);

/* =====================================================================
 * 63) 🖤 «صحح الحالات وضوي المفاتيح» (2026-08-23): حالات سيدة النجاة مصحّحة
 *     من إخراجات القيد (14 موظفاً) + فئة «أرمل» مدعومة بالمعادلة (تُحسب على
 *     فئة المتزوج المقابلة بلا زيادة زوج دائماً) + خياراتها بملف الموظف.
 * =================================================================== */
check('فئة الأرمل: المعادلة تحسبها (شخصي + أولاد كاملين بمفتاحهم، بلا زيادة زوج — ولا تقاسم لأن لا زوج، بخلاف المتزوج الذي زوجه يعمل منذ 2026-09-10)',
      familyDeductionAnnual('veuf_2_enfants', 0, 1, '2026-01-01', 1, 1) === familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-01-01', 0, 1)
      && familyDeductionAnnual('veuf_2_enfants', 0, 1, '2026-01-01', 1, 1) === 540000000
      && familyDeductionAnnual('veuf_sans_enfants', 0, 1, '2026-01-01', 1, 1) === familyDeductionAnnual('celibataire', 0, 1, '2026-01-01', 1, 1)
      && familyDeductionAnnual('veuf_2_enfants', 0, 1, '2026-01-01', 1, 0) === familyDeductionAnnual('celibataire', 0, 1, '2026-01-01', 1, 1));
check('فئة الأرمل: خيارها بملف الموظف (أرمل(ة) بقائمة «متزوج؟» منذ 2026-09-10، والعدد من «عدد الأولاد») + تسمياتها',
      strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'value="veuf"') !== false
      && composeSocialStatus('veuf', 2) === 'veuf_2_enfants'
      && socialStatusLabel('veuf_2_enfants', 'ar') === 'أرمل وله ولدان');
check('حالات سيدة النجاة: الشفاء موصول بالهيدر (يعمل أونلاين بعد النشر)',
      function_exists('healNajatCivilStatus20260823')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNajatCivilStatus20260823();') !== false);
$naj63 = $db->query("SELECT
    SUM(CASE WHEN id=1546 AND social_status='marie_3_enfants' AND grant_children_addition=1 AND grant_spouse_addition=0 THEN 1 ELSE 0 END)
  + SUM(CASE WHEN id=968 AND social_status='veuf_2_enfants' AND grant_children_addition=1 THEN 1 ELSE 0 END)
  + SUM(CASE WHEN id=53 AND social_status='veuf_sans_enfants' AND grant_children_addition=0 THEN 1 ELSE 0 END)
  + SUM(CASE WHEN id=65 AND social_status='marie_sans_enfants' AND grant_children_addition=0 THEN 1 ELSE 0 END)
  FROM employees WHERE id IN (1546,968,53,65)")->fetchColumn();
check('حالات سيدة النجاة: عيّنات مثبّتة (جونا متزوجة+3 مضوّى · برباري أرملة+2 مضوّى · قرعه أرملة مطفى · غنيمه متزوجة بلا قاصرين)',
      (int)$naj63 === 4, 'مطابق: ' . $naj63 . '/4');

/* =====================================================================
 * 64) 💡 «لازم يضوي بالبرنامج وانا بساعتها بطبق او لاء» (2026-08-23):
 *     اقتراحات إخراجات القيد بجدول ذاتي التركيب + صفحة قرار (طبّق/تجاهل
 *     بإعادة احتساب تلقائية) + إشارة حمراء تضوي بالقائمة عند وجود معلَّق.
 * =================================================================== */
ensureTaxSuggestions20260823();
check('اقتراحات إخراج القيد: الجدول ذاتي التركيب + زرع قراءات سيدة النجاة (14 مطبَّقاً موثَّقاً)',
      (int)$db->query("SELECT COUNT(*) FROM tax_suggestions WHERE source_key LIKE 'najat_%' AND status='applied'")->fetchColumn() === 13
      && (int)$db->query("SELECT COUNT(*) FROM tax_suggestions WHERE source_key IN ('najat_62','najat_1387','maxim_38')")->fetchColumn() === 3);
$ts64 = renderPage('pages/tax_suggestions.php', [], []);
// «شو يعني طبّق؟ الأفضل نعم أو كلا» + «لازم يبين موظفين المدرسة اللي مختارها» (2026-08-25):
// أزرار القرار نعم/كلا (نعم = بدي التنزيل) والاقتراحات بنطاق المدرسة المختارة فقط
$tsSrc64 = (string)file_get_contents($PROJ . '/pages/tax_suggestions.php');
check('اقتراحات إخراج القيد: الصفحة تعرض المعلَّق والمطبَّق بأزرار قرار «نعم/كلا» + مفلترة بالمدرسة المختارة',
      strpos($ts64, 'اقتراحات من قراءة إخراجات القيد') !== false
      && strpos($ts64, 'name="act" value="apply"') !== false
      && strpos($ts64, 'name="act" value="dismiss"') !== false
      && strpos($ts64, '</i> نعم</button>') !== false
      && strpos($ts64, '</i> كلا</button>') !== false
      && strpos($ts64, '> طبّق</button>') === false
      && strpos($tsSrc64, "schoolScopeWhere('ts.school_id')") !== false
      && strpos($ts64, 'FATAL') === false);
// بنطاق مدرسة مكسيموس (2): ما بيبين ولا اقتراح لمدرسة النجاة (3) — مادونا عازار اقتراحها بالنجاة
$ts64b = renderPage('pages/tax_suggestions.php', [], [], [2]);
check('اقتراحات إخراج القيد: بنطاق سان مكسيم ما بتبين اقتراحات سيدة النجاة (مادونا عازار غايبة)',
      strpos($ts64b, 'مادونا') === false
      && (int)$db->query("SELECT COUNT(*) FROM tax_suggestions ts JOIN employees e ON e.id=ts.employee_id
            WHERE e.first_name_ar LIKE '%مادونا%' AND ts.school_id <> 2")->fetchColumn() >= 1
      && strpos($ts64b, 'FATAL') === false);
check('اقتراحات إخراج القيد: الإشارة تضوي بالقائمة (عدّاد أحمر نابض) + التطبيق يعيد الاحتساب',
      strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'taxSuggestionsPendingCount()') !== false
      && strpos((string)file_get_contents($PROJ . '/assets/css/app.css'), '@keyframes pulse') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/tax_suggestions.php'), '$recalcFrom((int)$sg[\'employee_id\']);') !== false);

/* =====================================================================
 * 65) 💑 «طفي زيادة الزوج» + «الا قراري انا وانت اكيد بتكون باعتلي رسالة»
 *     (2026-08-23): زيادة الزوج مطفأة تلقائياً للجميع (كمفتاح الأولاد)،
 *     والمتأثرون وصلتهم رسالة قرار بصفحة الاقتراحات (طبّق = تعود له).
 * =================================================================== */
check('زيادة الزوج مطفأة تلقائياً: الافتراضي بالعمود والمحرّك والقارئين = 0',
      (string)($db->query("SHOW COLUMNS FROM employees LIKE 'grant_spouse_addition'")->fetch(PDO::FETCH_ASSOC)['Default'] ?? '') === '0'
      && strpos((string)file_get_contents($PROJ . '/includes/payroll_calculator.php'), "\$this->employee['grant_spouse_addition'] ?? 0") !== false
      && substr_count((string)file_get_contents($PROJ . '/pages/official_export.php') . (string)file_get_contents($PROJ . '/includes/functions.php'), "gsa'] ?? 0") >= 1
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "'grant_spouse_addition' => 0,") !== false);
check('زيادة الزوج: الشفاء موصول بالهيدر + رسائل القرار مزروعة للمتأثرين',
      function_exists('healSpouseAdditionOff20260823')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healSpouseAdditionOff20260823();') !== false
      && (int)$db->query("SELECT COUNT(*) FROM tax_suggestions WHERE source_key LIKE 'gsa_off_%'")->fetchColumn() >= 20);
check('زيادة الزوج: لا موظف يأخذها إلا بقرار صريح (كلهم مطفأون الآن)',
      (int)$db->query("SELECT COUNT(*) FROM employees WHERE grant_spouse_addition=1")->fetchColumn() === 0);

/* =====================================================================
 * 66) 📅 التنزيل المؤرَّخ تلقائياً («اذا الاولاد تحت 18 واذا اكتر خلص —
 *     يشيل التنزيل من تاريخ بلوغ 18، والزوج اذا اصبح يعمل من تاريخ بدء
 *     العمل، مع ابتداءً من تاريخ الى تاريخ» — 2026-08-23): أولاد مؤرَّخون
 *     (employee_children) + تاريخ بدء عمل الزوج + صفحة قرارات نعم/كلا.
 * =================================================================== */
ensureEmployeeChildren20260823();
check('الأولاد المؤرَّخون: الجدول ذاتي التركيب + زرع أولاد سيدة النجاة من إخراجات القيد',
      $db->query("SHOW TABLES LIKE 'employee_children'")->fetch() !== false
      && (int)$db->query("SELECT COUNT(*) FROM employee_children WHERE source='family_doc'")->fetchColumn() >= 24
      && $db->query("SHOW COLUMNS FROM employees LIKE 'spouse_work_start_date'")->fetch() !== false);
// أتمتة الـ18 (مرسال 68: ريبيكا 13/5/2009 وريا 18/8/2011): 2 ← 1 ← 0 ولد عبر السنين
check('أتمتة الـ18: تنزيل كل ولد يسقط من شهر بلوغه (مرسال: 540م ← 495م ← 450م)',
      familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-06-01', 0, 1, 68) === 540000000
      && familyDeductionAnnual('marie_2_enfants', 0, 1, '2027-06-01', 0, 1, 68) === 495000000
      && familyDeductionAnnual('marie_2_enfants', 0, 1, '2029-09-01', 0, 1, 68) === 450000000);
// عتبة الشهر بالضبط (لبيب العشي مواليد 1/8/2008): تموز 2026 محتسب، آب لا
check('أتمتة الـ18: العتبة بأول الشهر (لبيب: تموز 2026 محتسب، آب 2026 ساقط)',
      familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-07-01', 0, 1, 65) === 495000000
      && familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-08-01', 0, 1, 65) === 450000000);
// تاريخ بدء عمل الزوج: زاهية (18) مؤقتاً — الزيادة تسقط تلقائياً من التاريخ (مع ترجيع)
$sw66 = $db->query("SELECT spouse_work_start_date FROM employees WHERE id = 18")->fetchColumn();
$db->exec("UPDATE employees SET spouse_work_start_date = '2026-03-01' WHERE id = 18");
$fd66a = familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-02-01', 1, 0, 18);
$fd66b = familyDeductionAnnual('marie_sans_enfants', 0, 1, '2026-04-01', 1, 0, 18);
$db->prepare("UPDATE employees SET spouse_work_start_date = ? WHERE id = 18")->execute([$sw66 ?: null]);
check('تاريخ بدء عمل الزوج: الزيادة تسري قبله وتسقط تلقائياً منه (675م ← 450م)',
      $fd66a === 675000000 && $fd66b === 450000000,
      number_format($fd66a) . ' ← ' . number_format($fd66b));
// (ملاحظة: الكاش الساكن داخل الدالة لكل طلب — بفحص CLI هذا كل نداء طلب مستقل فالقيم طازجة)
$dec66 = renderPage('pages/tax_suggestions.php', [], [], [3]);
// 🧾 «بدي حط قدام كل أستاذ: متزوج نعم/كلا → عدد الأولاد المستحقين → الزوج يعمل نعم/كلا →
//     تنزيل الأولاد نعم/كلا → تنزيل الزوج نعم/كلا — هيك بيكون التقرير مفهوم أكتر» (2026-09-06):
//     الأعمدة بهذا الترتيب + مفتاحا «متزوج؟» و«الزوج يعمل؟» قرارات مباشرة + عمود التنزيل السنوي الناتج
$ord66 = [strpos($dec66, '<th>متزوج؟</th>'), strpos($dec66, '<th>الأولاد المستحقون تنزيلاً'), strpos($dec66, '<th>الزوج/الزوجة<br>يعمل؟</th>'),
          strpos($dec66, '<th>تنزيل الأولاد</th>'), strpos($dec66, '<th>تنزيل<br>الزوج/الزوجة</th>'), strpos($dec66, '<th>التنزيل السنوي<br>الناتج</th>')];
check('صفحة القرارات: الجدول المفهوم — متزوج؟ → الأولاد المستحقون → الزوج يعمل؟ → تنزيل الأولاد → تنزيل الزوج → الناتج (بهذا الترتيب) + تشاك مارك نعم/كلا يطبَّق فوراً + إدارة الأولاد وتاريخ عمل الزوج',
      strpos($dec66, 'قرارات التنزيل العائلي') !== false
      && !in_array(false, $ord66, true) && $ord66 === array_values(array_unique($ord66)) && $ord66 == array_values((function ($a) { sort($a); return $a; })($ord66))
      && strpos($dec66, '→ 18: <strong>') !== false
      && strpos($dec66, 'name="act" value="set_married"') !== false
      && strpos($dec66, 'name="act" value="set_spouse_works"') !== false
      && strpos($dec66, 'name="act" value="set_gca"') !== false
      && strpos($dec66, 'name="act" value="set_gsa"') !== false
      && strpos($dec66, 'type="radio" name="val"') !== false
      && strpos($dec66, 'onchange="this.form.submit()"') !== false
      && strpos($dec66, 'name="act" value="add_child"') !== false
      && strpos($dec66, 'name="act" value="spouse_start"') !== false
      && strpos($dec66, 'FATAL') === false);
// 📅 «بدي اساتذة نفس السنة» (p1 — 2026-08-25): الاقتراحات مفلترة بأساتذة السنة المعروضة
// (زينه نجم تركت 2015 — ما بتظهر بسنة 2025-2026، ودنيا القزي موظفة السنة بتظهر) + بكل
// السنين «all» بيظهر الكل + العدّاد بالقائمة بنفس النطاق (مدرسة + سنة)
$sg66y = renderPage('pages/tax_suggestions.php', [], [], [2], '', '2025-2026');
$sg66a = renderPage('pages/tax_suggestions.php', [], [], [2], '', 'all');
check('الاقتراحات: أساتذة السنة المعروضة فقط (لا تاركين قدامى) وبكل السنين يظهر الكل',
      strpos($sg66y, 'دنيا القزي') !== false && strpos($sg66y, 'زينه نجم') === false
      && strpos($sg66a, 'زينه نجم') !== false && strpos($sg66y, 'FATAL') === false,
      'year=' . (strpos($sg66y, 'زينه نجم') === false ? 'ok' : 'leak') . ' all=' . (strpos($sg66a, 'زينه نجم') !== false ? 'ok' : 'miss'));
check('عدّاد الاقتراحات بالقائمة بنفس نطاق الصفحة (مدرسة + سنة)',
      strpos((string)file_get_contents($PROJ . '/includes/functions.php'),
             "JOIN employees e ON e.id = ts.employee_id AND e.is_deleted = 0") !== false
      && preg_match('/function taxSuggestionsPendingCount[^}]*yearEmploymentFilter/s',
             (string)file_get_contents($PROJ . '/includes/functions.php')) === 1);
check('المحرّك والقارئون يمرّرون رقم الموظف للأتمتة المؤرَّخة',
      strpos((string)file_get_contents($PROJ . '/includes/payroll_calculator.php'), "(int)(\$this->employee['id'] ?? 0)") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "(int)\$de['id']") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/report_helpers.php'), "\$eid = (int)(\$r['employee_id'] ?? (\$r['eid'] ?? 0));") !== false);

/* ---------- ٦٧) ملف الوزارة السنوي R567 (ر5+ر6+ر7 بقالب الماكرو الرسمي — 2026-08-23) ---------- */
check('قالب الوزارة mof_r567.xlsm موجود ومعه قوائم الأكواد الجغرافية',
      is_file($PROJ . '/assets/templates/mof_r567.xlsm') && filesize($PROJ . '/assets/templates/mof_r567.xlsm') > 1000000
      && is_file($PROJ . '/assets/templates/mof_r567_geo.json'));
$geo67 = json_decode((string)file_get_contents($PROJ . '/assets/templates/mof_r567_geo.json'), true) ?: [];
check('قوائم الأكواد: 8 محافظات + 25 قضاء + 1942 بلدة',
      count($geo67['govs'] ?? []) === 8 && count($geo67['cazas'] ?? []) === 25 && count($geo67['towns'] ?? []) === 1942);
$oe67 = (string)file_get_contents($PROJ . '/pages/official_export.php');
check('مولّد R567: الأوراق الثلاث منفصلة + حماية «الماكرو يقف عند رقم مالية فارغ» + شاشة التدقيق الهرمي',
      strpos($oe67, "form === 'mof_r567'") !== false
      && strpos($oe67, "phpFillXlsxTemplateSheets(\$tpl567, ['R5' => \$r5c, 'R6' => \$r6c, 'R7' => \$r7c]") !== false
      && substr_count($oe67 . (string)file_get_contents($PROJ . '/includes/functions.php'), "REGEXP '[0-9]') DESC") === 2
      && strpos($oe67, 'بقوائم الوزارة هالبلدة بقضاء') !== false);
$of67 = renderPage('pages/official_forms.php', ['form' => 'tax_r5'], [], [2]);
check('زرّا R567 (توليد + تدقيق أسماء) بشاشة ر5',
      strpos($of67, 'form=mof_r567') !== false && strpos($of67, 'تدقيق أسماء المناطق') !== false
      && strpos($of67, 'FATAL') === false);
/* 🔍 مدقّق ملف الوزارة («بعد الجنريت ما بقدر اعرف الملف صح او غلط» — 2026-08-24):
 *    يفكّ تشفير R567.xml (DES-CBC بمفتاح الماكرو) ويقارنه بأرقام البرنامج قبل الإرسال */
$chkSrc68 = (string)@file_get_contents($PROJ . '/pages/r567_check.php');
check('صفحة فحص ملف الوزارة موجودة (فكّ تشفير الماكرو + قراءة فقط) وزرّها بشاشة ر5',
      $chkSrc68 !== ''
      && strpos($chkSrc68, "openssl_decrypt(\$bin, 'des-cbc', R567_KEY, OPENSSL_RAW_DATA") !== false
      && strpos($chkSrc68, "R567_IV_HEX = '1314531830a13d1f'") !== false
      && strpos($chkSrc68, 'requireCsrf();') !== false
      && preg_match('/\b(UPDATE|INSERT|DELETE)\b/i', $chkSrc68) === 0 // قراءة فقط حصراً
      && strpos($of67, 'pages/r567_check.php') !== false);
// تجربة حيّة: نولّد XML بنفس منطق الماكرو من أرقامنا ونمرّره بالمدقّق ⇒ لازم «سليم»
// (بنفس نطاق الصفحة المرندَرة: مدرسة سان مكسيم وحدها — وإلا قارنّا 440 موظفاً بـ38)
$savedScope68 = $_SESSION['active_schools'] ?? null;
$_SESSION['active_schools'] = [2];
$yd68 = mofYearEmpData($db, 2025, '');
if ($yd68['rows']) {
    $S68 = $yd68['sum'];
    $r6x68 = '';
    foreach ($yd68['rows'] as $r68) {
        $d68 = $r68['d'];
        $fin68 = preg_replace('/\D/', '', (string)($r68['e']['finance_ministry_number'] ?? '')) ?: '1';
        $r6x68 .= '<Attached_Form FormNo="R6" Ver="2">'
            . '<FCG Int_Line_No="1015"><Cell_Value>2</Cell_Value></FCG>'
            . '<FCG Int_Line_No="1020"><Cell_Value>3</Cell_Value></FCG>'
            . '<FCG Int_Line_No="1025"><Cell_Value>202127</Cell_Value></FCG>'
            . '<FCG Int_Line_No="1384"><Cell_Value>تجربة</Cell_Value></FCG>'
            . '<FCG Int_Line_No="1387"><Cell_Value>' . $fin68 . '</Cell_Value></FCG>'
            . '<FCG Int_Line_No="1389"><Cell_Value>1</Cell_Value></FCG>'
            . '<FCG Int_Line_No="1391"><Cell_Value>1</Cell_Value></FCG>'
            . '<FC Int_Line_No="15"><Submitted_AMT>' . $d68['trans'] . '</Submitted_AMT></FC>'
            . '<FC Int_Line_No="66"><Submitted_AMT>' . $d68['tot1'] . '</Submitted_AMT></FC>'
            . '<FC Int_Line_No="80"><Submitted_AMT>' . $r68['fd'] . '</Submitted_AMT></FC>'
            . '<FC Int_Line_No="81"><Submitted_AMT>' . ($d68['other'] + $d68['fam']) . '</Submitted_AMT></FC>'
            . '<FC Int_Line_No="84"><Submitted_AMT>' . $d68['net350'] . '</Submitted_AMT></FC>'
            . '<FC Int_Line_No="89"><Submitted_AMT>' . $d68['tax'] . '</Submitted_AMT></FC></Attached_Form>';
    }
    $fin68s = preg_replace('/\D/', '', (string)($db->query("SELECT finance_number FROM schools WHERE id=2")->fetchColumn() ?: ''));
    $xml68 = '<?xml version="1.0" encoding="UTF-8"?><DSAssesment><Assessment>'
        . '<Form_No>R5</Form_No><Version_No>6</Version_No><Tax_Payer_No>' . $fin68s . '</Tax_Payer_No>'
        . '<TP_Start_Date>2025-01-01</TP_Start_Date><TP_End_Date>2025-12-31</TP_End_Date>'
        . '<UserID>RegCheck</UserID><Declaration_Date>2026-01-01</Declaration_Date>'
        . '<FC Int_Line_No="10"><Submitted_AMT>' . $S68['paid'] . '</Submitted_AMT></FC>'
        . '<FC Int_Line_No="12"><Submitted_AMT>' . $S68['trans'] . '</Submitted_AMT></FC>'
        . '<FC Int_Line_No="16"><Submitted_AMT>' . ($S68['other'] + $S68['fam']) . '</Submitted_AMT></FC>'
        . '<FC Int_Line_No="22"><Submitted_AMT>' . $S68['tb'] . '</Submitted_AMT></FC>'
        . '<FC Int_Line_No="24"><Submitted_AMT>' . $S68['fd'] . '</Submitted_AMT></FC>'
        . '<FC Int_Line_No="26"><Submitted_AMT>' . $S68['net'] . '</Submitted_AMT></FC>'
        . '<FC Int_Line_No="28"><Submitted_AMT>' . $S68['tax'] . '</Submitted_AMT></FC>'
        . $r6x68 . '<Attached_Form FormNo="R7" Ver="1"><FCG Int_Line_No="1001"><Cell_Value>2025</Cell_Value></FCG></Attached_Form>'
        . '</Assessment></DSAssesment>';
    // شفّره بمفتاح الماكرو نفسه (تجربة الطريق الكامل: تشفير ⇒ رفع ⇒ فحص)
    $enc68 = base64_encode(openssl_encrypt($xml68, 'des-cbc', '6E79A445', OPENSSL_RAW_DATA, hex2bin('1314531830a13d1f')));
    $up68 = $PROJ . '/tools/_r567_test.xml';
    file_put_contents($up68, $enc68);
    $out68 = renderPage('pages/r567_check.php', [], [], [2], '', '', '', ['xml' => $up68]);
    @unlink($up68);
    check('مدقّق ملف الوزارة: ملف مطابق لأرقام البرنامج ⇒ «سليم» (فكّ التشفير + كل الفحوص خضراء)',
          strpos($out68, 'الملف سليم') !== false && strpos($out68, '❌') === false
          && strpos($out68, 'FATAL') === false, 'موظفون: ' . count($yd68['rows']));
    // 🖥️ العرض التفصيلي متل موقع المالية («لازم يبين بالتفصيل ر6 لكل موظف ور5 ور7» — 2026-08-25):
    // ر5 بسطوره + كبسة الموظف = نموذج ر6 الرسمي طبق الأصل مضمَّناً مع طباعة («بدو يكون هيك ر6
    // وكل موظف واقدر اطبعو كمان») + ر7 التاركون + الوضع العائلي من الخانة 1391 لا 1389
    check('مدقّق الوزارة يعرض مضمون الملف: ر5 سطوراً + كبسة الموظف = نموذج ر6 الرسمي (iframe + طباعة) + ر7 + الخانة 1391',
          strpos($out68, 'التصريح السنوي ر5') !== false
          && strpos($out68, 'ر6 مستقل لكل موظف') !== false
          && strpos($out68, 'تجربة') !== false                     // اسم الموظف ظاهر بسطره
          && strpos($out68, 'form=mof_r6') !== false               // النموذج الرسمي طبق الأصل مربوط
          && strpos($out68, 'iframe data-src') !== false           // مضمَّن بالشاشة (تحميل كسول)
          && strpos($out68, 'للطباعة') !== false                   // زرّ الطباعة بصفحة كاملة
          && strpos($out68, 'كشف التاركين ر7') !== false
          && strpos($chkSrc68, "\$g['1391']") !== false            // الوضع العائلي من خانته الصحيحة
          && strpos($chkSrc68, '«الوضع العائلي*» (1391)') !== false);
    // ونفس الملف بضريبة مبدَّلة ⇒ لازم يوقعه
    $bad68 = str_replace('<FC Int_Line_No="28"><Submitted_AMT>' . $S68['tax'],
                         '<FC Int_Line_No="28"><Submitted_AMT>' . ($S68['tax'] + 1000), $xml68);
    file_put_contents($up68, base64_encode(openssl_encrypt($bad68, 'des-cbc', '6E79A445', OPENSSL_RAW_DATA, hex2bin('1314531830a13d1f'))));
    $out68b = renderPage('pages/r567_check.php', [], [], [2], '', '', '', ['xml' => $up68]);
    @unlink($up68);
    check('مدقّق ملف الوزارة: رقم مبدَّل بالملف ⇒ «لا تبعتو» (ما بيمرق غلط)',
          strpos($out68b, 'لا تبعتو') !== false && strpos($out68b, 'الضريبة المتوجبة') !== false);
}
if ($savedScope68 === null) unset($_SESSION['active_schools']); else $_SESSION['active_schools'] = $savedScope68;
// تعبئة متعددة الأوراق: نصيّة (لا DOM — ورقة R6 ‏15MB) + ممنوع دهس خلايا الصيغ
require_once $PROJ . '/includes/report_export.php';
$x67 = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
     . '<row r="16" spans="1:5"><c r="A16" s="3"/><c r="B16" s="4" t="s"><v>9</v></c>'
     . '<c r="C16" s="5"><f>A16*2</f><v>44</v></c></row></sheetData></worksheet>';
$y67 = phpFillSheetXmlCells($x67, ['A16' => 123, 'B16' => 'نصّ&قيمة', 'C16' => 999, 'D16' => '']);
check('التعبئة النصيّة: رقم + نصّ مهرَّب + صيغة محمية من الدهس + شطب القيمة المخبّأة',
      is_string($y67)
      && strpos($y67, '<c r="A16" s="3"><v>123</v></c>') !== false
      && strpos($y67, 'نصّ&amp;قيمة') !== false
      && strpos($y67, '<f>A16*2</f>') !== false && strpos($y67, '<v>44</v>') === false
      && strpos($y67, '999') === false);
// توليد حي: ملف xlsm سليم بماكرو الوزارة والقيم بأوراقه
$b67 = renderPage('pages/official_export.php', ['form' => 'mof_r567', 'fy' => '2025'], [], [2], '', '', $PROJ . '/tools/_r567_test.xlsm');
$ok67 = strncmp($b67, 'PK', 2) === 0 && strlen($b67) > 1000000;
$vba67 = false; $r6emp67 = false; $r5xml67 = ''; $r6xml67 = '';
if ($ok67) {
    $tmp67 = $PROJ . '/tools/_r567_probe.xlsm';
    file_put_contents($tmp67, $b67);
    $z67 = new ZipArchive();
    if ($z67->open($tmp67) === true) {
        $vba67 = $z67->getFromName('xl/vbaProject.bin') !== false;
        $r5xml67 = (string)$z67->getFromName('xl/worksheets/sheet1.xml');
        $r6xml67 = (string)$z67->getFromName('xl/worksheets/sheet2.xml');
        for ($i67 = 1; $i67 <= 6; $i67++) {
            $sx67 = (string)$z67->getFromName("xl/worksheets/sheet$i67.xml");
            if (strpos($sx67, 'أعزب') !== false || strpos($sx67, 'متزوج') !== false) { $r6emp67 = true; break; }
        }
        $z67->close();
    }
    @unlink($tmp67);
}
check('توليد R567 حي: ملف xlsm سليم + ماكرو الوزارة محفوظ + صفوف الموظفين بمفردات الوزارة (أعزب/متزوج)',
      $ok67 && $vba67 && $r6emp67, 'حجم ' . number_format(strlen($b67)));
// 🔴 «الأرقام بر5 لازم يكونو مطابقين لر6» (2026-08-24): كل سطر مالي بورقة ر5 = مجموع
// عموده بصفوف ر6 (16..54) بالمليم + السلسلة الحسابية تامة (120−130−150=160، 160−170=180)
// ⚠️ regex بلا تراجع (لا .*? — ورقة R6 ‏15MB بتفجّر backtrack limit): خلية قيمة مباشرة
// <c r=".." ...><v>..</v> فقط — خلايا الصيغ (<f> قبل <v>) لا تُلتقط أصلاً وهذا مقصود
$cell67 = function ($xml, $ref) {
    return preg_match('/<c r="' . $ref . '"[^>]*><v>([^<]+)<\/v>/', $xml, $m) ? (float)$m[1] : 0.0;
};
$sums67 = [];
if (preg_match_all('/<c r="([A-Z]{1,2})(\d+)"[^>]*><v>([^<]+)<\/v>/', $r6xml67, $ms67, PREG_SET_ORDER)) {
    foreach ($ms67 as $m67) {
        $rw67 = (int)$m67[2];
        if ($rw67 < 16 || $rw67 > 54) continue; // 55 = صف مجاميع القالب (صيَغ)
        $sums67[$m67[1]] = ($sums67[$m67[1]] ?? 0.0) + (float)$m67[3];
    }
}
$g67 = fn($c) => $sums67[$c] ?? 0.0;
$eq67 = fn($a, $b) => abs($a - $b) < 0.5;
$fam67 = $g67('CF') - $g67('AV');
check('R567: ورقة ر5 = مجموع صفوف ر6 بالمليم (المدفوع/النقل/تنزيلات أخرى/الأساس/العائلي/الخاضع/الضريبة) + السلسلة الحسابية تامة',
      $eq67($cell67($r5xml67, 'L31'), $g67('CE')) && $eq67($cell67($r5xml67, 'L33'), $g67('CE'))
      && $eq67($cell67($r5xml67, 'L34'), $g67('AV'))
      && $eq67($cell67($r5xml67, 'L36'), $g67('CI') + $fam67)
      && $eq67($cell67($r5xml67, 'L37'), $g67('CH') + $g67('CJ'))
      && $eq67($cell67($r5xml67, 'L38'), $g67('CH'))
      && $eq67($cell67($r5xml67, 'L39'), $g67('CJ'))
      && $eq67($cell67($r5xml67, 'L40'), $g67('CK'))
      && $eq67($cell67($r5xml67, 'K48'), $cell67($r5xml67, 'L39'))
      && $eq67($cell67($r5xml67, 'K51'), $cell67($r5xml67, 'L40'))
      && $eq67($cell67($r5xml67, 'L33') - $cell67($r5xml67, 'L34') - $cell67($r5xml67, 'L36'), $cell67($r5xml67, 'L37'))
      && $eq67($cell67($r5xml67, 'L37') - $cell67($r5xml67, 'L38'), $cell67($r5xml67, 'L39'))
      && $g67('CE') > 0,
      'ΣCE=' . number_format($g67('CE')) . ' ΣCJ=' . number_format($g67('CJ')) . ' ΣCK=' . number_format($g67('CK')));
// 🟰 «شوف في فرق بين ر5 لحالها وR567؟» (2026-08-24): ممنوع يرجع الفرق — نموذج ر5 المستقل
// (خانات قسم 16: 120=I31..190=I38) يطابق ورقة R5 داخل R567 خلية بخلية بالمليم
check('ر5 المستقل = ورقة R5 بملف R567 خلية بخلية (المجموع/النقل/أخرى/الأساس/العائلي/الخاضع/الضريبة)',
      isset($r5v['120'], $r5v['160'], $r5v['180'], $r5v['190'])
      && $eq67($r5v['120'], $cell67($r5xml67, 'L33'))
      && $eq67($r5v['130'] ?? 0, $cell67($r5xml67, 'L34'))
      && $eq67($r5v['150'] ?? 0, $cell67($r5xml67, 'L36'))
      && $eq67($r5v['160'], $cell67($r5xml67, 'L37'))
      && $eq67($r5v['170'] ?? 0, $cell67($r5xml67, 'L38'))
      && $eq67($r5v['180'], $cell67($r5xml67, 'L39'))
      && $eq67($r5v['190'], $cell67($r5xml67, 'L40')),
      'ر5=' . json_encode($r5v));
// 🟰 «انتبه ر5 كمان بدها تكون مجموع ر10 على أربع فصول» (2026-08-24): الفصول الأربعة
// المولَّدة حياً تُجمَع خانة خانة وتُقارَن بر5 — التنزيل العائلي التراكمي يضمنها بالمليم
$r10sum67 = ['J27' => 0.0, 'J28' => 0.0, 'J29' => 0.0, 'J30' => 0.0, 'J32' => 0.0, 'J33' => 0.0, 'J34' => 0.0, 'J35' => 0.0, 'J36' => 0.0];
for ($q67 = 1; $q67 <= 4; $q67++) {
    $bq67 = renderPage('pages/official_export.php', ['form' => 'mof_r10', 'rq' => $q67, 'rqy' => 2025, 'format' => 'xlsx'], [], [2], '', '', $PROJ . '/tools/_r10q.xlsx');
    if (strncmp($bq67, 'PK', 2) !== 0) continue;
    file_put_contents($PROJ . '/tools/_r10qb.xlsx', $bq67);
    $zq67 = new ZipArchive();
    if ($zq67->open($PROJ . '/tools/_r10qb.xlsx') === true) {
        $shq67 = (string)$zq67->getFromName('xl/worksheets/sheet1.xml');
        foreach ($r10sum67 as $ref67 => $v67) $r10sum67[$ref67] += $cell67($shq67, $ref67);
        $zq67->close();
    }
    @unlink($PROJ . '/tools/_r10qb.xlsx');
}
// 🎯 p1 ‏2026-08-24 («####» بالتسجيل + رقم عمودي بالفاكس): القيم بصناديق الإدخال الحقيقية
// حصراً — بلوك التبليغ L..Q (I..K تسميات مدموجة) والمحضّر L23/J24/O24 وفاكس المكلف G24
check('R567-ر5: المراسي بصناديق الإدخال الصح (L23/J24/O24/G24 + بلوك التبليغ L11-L19 + البلدة C13) لا بالخلايا الضيقة',
      strpos($oe67, "'L23' => \$prof['preparer_reg']") !== false
      && strpos($oe67, "'J24' => \$prof['preparer_phone']") !== false
      && strpos($oe67, "'O24' => \$prof['preparer_fax']") !== false
      && strpos($oe67, "'G24' => \$prof['contact_fax']") !== false
      && strpos($oe67, "'L11' => \$prof['gov']") !== false
      && strpos($oe67, "'L13' => \$prof['town']") !== false
      && strpos($oe67, "'C13' => \$prof['town']") !== false
      && strpos($oe67, "'K23' => \$prof['preparer_reg']") === false
      && strpos($oe67, "'H24' => \$prof['contact_fax']") === false
      && strpos($oe67, "'K11' => \$prof['gov']") === false);
check('ر5 = مجموع ر10 على أربعة فصول بالمليم (كل السطور: الرواتب/المنافع/المجموع/النقل/الأخرى/الأساس/العائلي/الخاضع/الضريبة)',
      $eq67($r10sum67['J27'], $r5v['100'] ?? -1)
      && $eq67($r10sum67['J28'], $r5v['110'] ?? 0)
      && $eq67($r10sum67['J29'], $r5v['120'] ?? -1)
      && $eq67($r10sum67['J30'], $r5v['130'] ?? 0)
      && $eq67($r10sum67['J32'], $r5v['150'] ?? 0)
      && $eq67($r10sum67['J33'], $r5v['160'] ?? -1)
      && $eq67($r10sum67['J34'], $r5v['170'] ?? 0)
      && $eq67($r10sum67['J35'], $r5v['180'] ?? -1)
      && $eq67($r10sum67['J36'], $r5v['190'] ?? -1),
      'Σر10=' . json_encode(array_map('intval', $r10sum67)));
$chk67 = renderPage('pages/official_export.php', ['form' => 'mof_r567', 'fy' => '2025', 'check' => '1'], [], [2]);
check('شاشة تدقيق أسماء المناطق تشتغل (عنوانها + جدول/رسالة نتيجتها)',
      strpos($chk67, 'تدقيق الأسماء الجغرافية على قوائم أكواد الوزارة') !== false
      && (strpos($chk67, 'مطابقة لقوائم الوزارة') !== false || strpos($chk67, 'غير مطابقة') !== false));
// محرّك تصحيح أسماء المناطق («صححهن متل ما كتبتهن الدولة» — عام لكل المؤسسات)
$r67 = fn($gv, $cz, $tw) => r567GeoResolve($gv, $cz, $tw, $geo67);
check('تصحيح المناطق: تهجئة الوزارة (ة→ه) + «ال» والمسافات + الهمزة',
      $r67('جبل لبنان', 'المتن', 'الدكوانة') === ['جبل لبنان', 'المتن', 'الدكوانه']
      && $r67('جبل لبنان', 'المتن', 'الروضة') === ['جبل لبنان', 'المتن', 'روضة']
      && $r67('جبل لبنان', 'المتن', 'مارموسى') === ['جبل لبنان', 'المتن', 'مار موسى']
      && $r67('بيروت', 'بيروت', 'الاشرفية') === ['بيروت', 'بيروت', 'الأشرفية']);
check('تصحيح المناطق: البلدة مرجع القضاء (صليما ⇒ بعبدا، جل الديب ⇒ المتن) والملتبس يبقى لقرار المستخدم',
      $r67('جبل لبنان', 'المتن', 'صليما') === ['جبل لبنان', 'بعبدا', 'صليما']
      && $r67('جبل لبنان', 'كسروان', 'جل الديب') === ['جبل لبنان', 'المتن', 'جل الديب']
      && $r67('جبل لبنان', 'المتن', 'المتن') === null
      && $r67('جبل لبنان', 'المتن', 'Beyrouth') === null
      && $r67('جبل لبنان', 'المتن', 'المنصورية') === null);
check('شفاء تصحيح المناطق معلَّق بالترويسة + التصحيح التلقائي بشاشة التدقيق',
      strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healR567GeoFix20260823()') !== false
      && strpos($oe67, 'r567GeoAutoFix($db, schoolScopeWhere(') !== false);
// ✍️ التصحيح المباشر من شاشة التدقيق («بدون ما ارجع فوت على ملف الأستاذ» 2026-08-24):
// معالج حفظ POST بحماية CSRF + مدير فقط + تدقيق هرمي، وفورم القوائم المتسلسلة بالشاشة
check('شاشة التدقيق: تصحيح مباشر (geo_save بCSRF + مدير فقط + تدقيق هرمي + PRG) وقوائم متسلسلة من لوائح الوزارة',
      strpos($oe67, "\$_POST['geo_save']") !== false
      && strpos($oe67, 'requireCsrf();') !== false
      && strpos($oe67, 'isAdmin()') !== false
      && strpos($oe67, "\$t4['caza'] === \$cazaId4") !== false
      && strpos($oe67, "header('Location: ' . basename(strtok(") !== false
      && strpos($oe67, 'class="gfix"') !== false
      && strpos($oe67, 'g-town') !== false);
// عنوان الين منصور 191 استقرّ على تهجئة الوزارة (ديك المحدي/المتن) — كان «Beyrouth» (تصحيح 2026-08-24)
$al67 = $db->query("SELECT gouvernorat, district, ville FROM employees WHERE id=191")->fetch();
check('الين منصور 191: عنوان السكن ديك المحدي/المتن/جبل لبنان (مش Beyrouth)',
      $al67 && $al67['gouvernorat'] === 'جبل لبنان' && $al67['district'] === 'المتن' && $al67['ville'] === 'ديك المحدي');

/* =====================================================================
 * 68) 🏦 استيراد أرقام صندوق التعويضات (2026-08-26): بيان مدرسة القديس
 *     مكسيموس الرسمي (18 ملاكاً، منظَّم 2025-02-10) يُستورَد بشفاء ذاتي
 *     موصول بالهيدر (يعمل أونلاين بعد النشر) — المطابقة بالاسم الثلاثي
 *     ولا يُكتب فوق رقم أدخله المستخدم يدوياً.
 * =================================================================== */
check('صندوق التعويضات: الشفاء موجود وموصول بالهيدر',
      function_exists('healCaisseImport20260826')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healCaisseImport20260826();') !== false);
$cn68 = $db->query("SELECT e.first_name_ar fn, e.caisse_number cn FROM employees e
    JOIN schools s ON s.id=e.school_id
    WHERE s.name_ar LIKE 'مدرسة%مكسيموس%' AND e.is_deleted=0
      AND TRIM(e.first_name_ar)='اندره' AND TRIM(e.father_name_ar)='يوسف' AND TRIM(e.last_name_ar)='مراد'")->fetch(PDO::FETCH_ASSOC);
$cnt68 = (int)$db->query("SELECT COUNT(*) FROM employees e JOIN schools s ON s.id=e.school_id
    WHERE s.name_ar LIKE 'مدرسة%مكسيموس%' AND e.is_deleted=0 AND TRIM(COALESCE(e.caisse_number,'')) <> ''")->fetchColumn();
check('صندوق التعويضات: أرقام البيان معبّأة (أندره مراد 3938 + ≥18 ملفاً برقم)',
      $cn68 && trim((string)$cn68['cn']) === '3938' && $cnt68 >= 18,
      'أندره=' . ($cn68['cn'] ?? '؟') . ' · معبّأ=' . $cnt68);
// البيان العام (eoc_staff) هو نموذج الصندوق الرسمي نفسه: عمود «الرقم المالي» للملاك
// = رقمه لدى صندوق التعويضات لا رقم وزارة المالية (المتعاقد يبقى برقم المالية)
$of68 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('البيان العام للصندوق: عمود الرقم المالي للملاك = caisse_number (والمتعاقد رقم المالية)',
      strpos($of68, "\$isMlk ? (\$r['caisse_number'] ?? '') : \$r['finance_ministry_number']") !== false);

/* =====================================================================
 * 69) 🏦 أرقام الصندوق — ثانوية السيدة عبرا (2026-08-26): بيانها الرسمي PDF
 *     (129 ملاكاً) يُستورَد بمحرّك caisseImportForSchool (تطبيع الأسماء +
 *     ملفات الأب المجهول عند وحدانية الاسم + الملتبس لا يُكتب) — «بس خود
 *     منه ارقام الصندوق للاساتذة».
 * =================================================================== */
check('عبرا: الشفاء والمحرّك موجودان وموصولان بالهيدر',
      function_exists('healCaisseImportAbra20260826') && function_exists('caisseImportForSchool')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healCaisseImportAbra20260826();') !== false);
$ab69 = $db->query("SELECT e.first_name_ar fn, e.father_name_ar fa, e.caisse_number cn FROM employees e
    JOIN schools s ON s.id=e.school_id
    WHERE s.name_ar LIKE 'مدرسة%ثانوية السيدة%' AND e.is_deleted=0
      AND TRIM(e.first_name_ar)='ريتا' AND TRIM(e.last_name_ar)='حليحل'")->fetchAll(PDO::FETCH_ASSOC);
$abRita = ['يوسف' => [], 'مارون' => [], '.' => []];
foreach ($ab69 as $r) $abRita[trim((string)$r['fa'])][] = trim((string)$r['cn']);
$abCnt69 = (int)$db->query("SELECT COUNT(*) FROM employees e JOIN schools s ON s.id=e.school_id
    WHERE s.name_ar LIKE 'مدرسة%ثانوية السيدة%' AND e.is_deleted=0 AND TRIM(COALESCE(e.caisse_number,'')) <> ''")->fetchColumn();
// بعد تنظيف المكرّرين (2026-09-02) صار العدّ أشخاصاً حقيقيين لا ملفات (الرقم نفسه كان بملفين) — العتبة 160،
// مع صمام: أي رقم صندوق كان على ملف مُزال لازم يبقى موجوداً على ملف فاعل بنفس المدرسة (lost=0)
$lostCn69 = 0;
try {
    $lostCn69 = (int)$db->query("SELECT COUNT(*) FROM _emp_bk_dedup20260902 bk
        WHERE TRIM(COALESCE(bk.caisse_number,'')) NOT IN ('','.','0')
          AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.is_deleted=0 AND e.school_id=bk.school_id AND e.caisse_number=bk.caisse_number)")->fetchColumn();
} catch (Throwable $e69) {}
check('عبرا: ≥160 شخصاً برقم (بعد لمّ المكرّرين) + لا رقم صندوق ضاع بالدمج + ريتا حليحل تفرّقتا بالأب (يوسف=101141، مارون=130587) والملتبسة الأب فاضية',
      $abCnt69 >= 160 && $lostCn69 === 0
      && !array_diff($abRita['يوسف'] ?? ['x'], ['101141']) && in_array('101141', $abRita['يوسف'] ?? [], true)
      && !array_diff($abRita['مارون'] ?? ['x'], ['130587'])
      && !array_filter($abRita['.'] ?? []),
      'معبّأ=' . $abCnt69 . ' · ريتا=' . json_encode($abRita, JSON_UNESCAPED_UNICODE));
check('عبرا: تطبيع الأسماء (عطاالله=عطالله، جوزيف=جوزف، ميريللا=ميريلا)',
      caisseNameNorm('عطاالله') === caisseNameNorm('عطالله')
      && caisseNameNorm('جوزف') === caisseNameNorm('جوزيف')
      && caisseNameNorm('ميريللا') === caisseNameNorm('ميريلا')
      && caisseNameNorm('سعد الدين') === caisseNameNorm('سعدالدين'));

/* =====================================================================
 * 70) 🏦 أرقام الصندوق — أربع مدارس («شوف ابلح فرزل حدث جون» 2026-08-26):
 *     النياح/ابلح 17 + الانتقال/الفرزل 14 + النجاة/الحدث 24 + البشارة/جون 19
 *     بشفاء واحد + احتياط الأب↔الشهرة المعكوسين بالمحرّك (تيا ديب/نخلة).
 * =================================================================== */
check('الأربع مدارس: الشفاء موجود وموصول بالهيدر',
      function_exists('healCaisseImport4Schools20260826')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healCaisseImport4Schools20260826();') !== false);
$c70 = function($like, $fn, $fa, $ln) use ($db) {
    $q = $db->prepare("SELECT TRIM(COALESCE(e.caisse_number,'')) FROM employees e JOIN schools s ON s.id=e.school_id
        WHERE s.name_ar LIKE ? AND e.is_deleted=0
          AND TRIM(e.first_name_ar)=? AND TRIM(e.father_name_ar)=? AND TRIM(e.last_name_ar)=? LIMIT 1");
    $q->execute([$like, $fn, $fa, $ln]);
    return (string)$q->fetchColumn();
};
$n70 = function($like) use ($db) {
    $q = $db->prepare("SELECT COUNT(DISTINCT e.caisse_number) FROM employees e JOIN schools s ON s.id=e.school_id
        WHERE s.name_ar LIKE ? AND e.is_deleted=0 AND TRIM(COALESCE(e.caisse_number,'')) NOT IN ('','0')");
    $q->execute([$like]);
    return (int)$q->fetchColumn();
};
check('الأربع مدارس: عيّنة رقم صح بكل مدرسة + عدد الأرقام المميزة = عدد بيانها',
      $c70('%سيدة النياح%', 'جوسلين', 'يوسف', 'عازار') === '36967' && $n70('%سيدة النياح%') === 17
      && $c70('%سيدة الانتقال%', 'وفاء', 'توفيق', 'مهنا') === '14887' && $n70('%سيدة الانتقال%') === 14
      && $c70('%سيدة النجاة%', 'جونا', 'فادي', 'زوبا') === '130695' && $n70('%سيدة النجاة%') === 24
      && $n70('مدرسة%سيدة البشارة%') === 19,
      'مميزة: نياح=' . $n70('%سيدة النياح%') . ' انتقال=' . $n70('%سيدة الانتقال%')
      . ' نجاة=' . $n70('%سيدة النجاة%') . ' بشارة=' . $n70('مدرسة%سيدة البشارة%'));
check('احتياط الأب↔الشهرة المعكوسين: تيا (البشارة) أخذت 133694 رغم انعكاس ديب/نخلة بملفها',
      $c70('مدرسة%سيدة البشارة%', 'تيا', 'ديب', 'نخلة') === '133694');

/* =====================================================================
 * 71) 🔢 «كل الارقام اكتبو بالفرنسي» (p1 ميلي طنوس 2026-08-26): خانات
 *     الأرقام الرسمية والهواتف بأرقام فرنسية — شفاء للداتا المخزّنة (أونلاين
 *     حيث «الرقم المالي: ١٢٥٩٠٤») + تطبيع تلقائي عند كل حفظ جديد.
 * =================================================================== */
check('الأرقام بالفرنسي: الشفاء موصول بالهيدر + الدالتان موجودتان',
      function_exists('officialNumberFr') && function_exists('arabicDigitsFr')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healFrenchDigits20260826();') !== false);
check('الأرقام بالفرنسي: التحويل صحيح (حالة ميلي «الرقم المالي: ١٢٥٩٠٤» ⇒ 125904 + هاتف ٠٣/٨٨٨٨٤٩ يبقى بصيغته + النظيف لا يُمسّ)',
      officialNumberFr('الرقم المالي: ١٢٥٩٠٤') === '125904'
      && officialNumberFr('١٢٥٩٠٤') === '125904'
      && officialNumberFr('125904') === '125904'
      && officialNumberFr('') === ''
      && trim(arabicDigitsFr('٠٣/٨٨٨٨٤٩')) === '03/888849'
      && trim(arabicDigitsFr('+961 71 234567')) === '+961 71 234567');
$emp71 = (string)file_get_contents($PROJ . '/pages/employees.php');
$sch71 = (string)file_get_contents($PROJ . '/pages/schools.php');
check('الأرقام بالفرنسي: التطبيع مربوط بحفظ ملف الموظف (صندوق/ضمان/مالية/هاتفين) وصفحة المدارس',
      strpos($emp71, "officialNumberFr(\$_POST['caisse_number']") !== false
      && strpos($emp71, "officialNumberFr(\$_POST['nssf_number']") !== false
      && strpos($emp71, "officialNumberFr(\$_POST['finance_ministry_number']") !== false
      && strpos($emp71, "arabicDigitsFr(\$_POST['phone1']") !== false
      && strpos($sch71, "officialNumberFr(\$_POST['caisse_number']") !== false
      && strpos($sch71, "officialNumberFr(\$_POST['finance_number']") !== false
      && strpos($sch71, "arabicDigitsFr(\$_POST['phone']") !== false);
$mili71 = $db->query("SELECT COUNT(*) FROM employees WHERE is_deleted=0 AND
    (caisse_number REGEXP '[^0-9/ -]' OR nssf_number REGEXP '[^0-9/ -]' OR finance_ministry_number REGEXP '[^0-9/ -]')")->fetchColumn();
check('الأرقام بالفرنسي: لا خانة رقم رسمي فيها كلام أو أرقام غير فرنسية بالقاعدة',
      (int)$mili71 === 0, 'ملفات ملوّثة: ' . $mili71);

/* =====================================================================
 * 72) 🏛️ تسوية الضمان السنوية طبق الأصل (2026-08-26): قالبه الرسمي بأوراقه
 *     الأربع + اختيار حر للمدارس (كل وحدة لحالها أو ذوات رقم الضمان المشترك
 *     مع بعضها) + كتل 19 سطراً + توسّع تلقائي فوق 38 أجيراً بمجموع عام محسوب.
 * =================================================================== */
check('تسوية الضمان: القالب والمحرّك والشاشة والبلاطة موجودون',
      is_file($PROJ . '/assets/templates/cnss_taswiya.xlsx')
      && function_exists('cnssTaswiyaData') && function_exists('cnssSchoolGroups')
      && strpos((string)file_get_contents($PROJ . '/pages/official_export.php'), "form === 'cnss_taswiya'") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/official_forms.php'), "form === 'cnss_taswiya'") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/reports.php'), 'cnss_taswiya') !== false);
$ts72 = cnssTaswiyaData($db, 2025, [2]);
$tt72 = $ts72['totals'];
$aMal72 = 0; foreach ($ts72['monthly'] as $mm72) $aMal72 += $mm72['mal'];
check('تسوية الضمان: الأرقام تركب — P=O×نسبة نهاية الخدمة المؤرّخة وR=N والمرض الشهري (أ) = مجموع الملحق بالمليم',
      $tt72['count'] > 0
      && (int)$tt72['P'] === (int)array_sum(array_map(fn($p) => (int)round($p['O'] * rateFrac('end_of_service_rate', 12, 2025, 8.5)), $ts72['persons']))
      && (int)$tt72['R'] === (int)$tt72['N']
      && (int)$aMal72 === (int)$tt72['R'],
      'أجراء=' . $tt72['count'] . ' N=' . $tt72['N'] . ' aMal=' . $aMal72);
$tsx72 = renderPage('pages/official_export.php', ['form' => 'cnss_taswiya', 'fy' => '2025', 'schools' => '2', 'format' => 'xlsx'], [], [2], '', '', $PROJ . '/tools/_ts72.xlsx');
$ok72 = strncmp($tsx72, 'PK', 2) === 0;
$sh372 = '';
if ($ok72) {
    file_put_contents($PROJ . '/tools/_ts72b.xlsx', $tsx72);
    $z72 = new ZipArchive();
    if ($z72->open($PROJ . '/tools/_ts72b.xlsx') === true) {
        $sh372 = (string)$z72->getFromName('xl/worksheets/sheet3.xml');
        $z72->close();
    }
    @unlink($PROJ . '/tools/_ts72b.xlsx');
}
check('تسوية الضمان: ملف Excel يصدر (4 أوراق) وعدد الأسطر طبق الأصل — كتلتا 19 + الصفرية وصيَغ القالب حيّة (SUM/8.5%/العدّاد)',
      $ok72
      && strpos($sh372, '<v>2025</v>') !== false
      && strpos($sh372, 'SUM(A8:A26)') !== false && strpos($sh372, 'SUM(A28:A46)') !== false
      && strpos($sh372, 'SUM(A48:A66)') !== false && strpos($sh372, 'A67+A47+A27') !== false
      && strpos($sh372, '$O48*8.5%') !== false
      && preg_match('/<dimension ref="A1:T70"/', $sh372) === 1,
      'PK=' . ($ok72 ? '1' : '0'));
// التوسّع: مجموعة رقم الضمان المشترك (25-82-043) — مئات الأجراء بكتل مستنسخة ومجموع عام محسوب
$grp72 = [];
foreach (cnssSchoolGroups($db) as $gk72 => $gs72) if (count($gs72) >= 5) { $grp72 = array_map(fn($s) => (int)$s['id'], $gs72); break; }
if ($grp72) {
    $ts272 = cnssTaswiyaData($db, 2025, $grp72);
    $tsx272 = renderPage('pages/official_export.php', ['form' => 'cnss_taswiya', 'fy' => '2025', 'schools' => implode(',', $grp72), 'format' => 'xlsx'], [], [], '', '', $PROJ . '/tools/_ts72c.xlsx');
    $sh3g = '';
    if (strncmp($tsx272, 'PK', 2) === 0) {
        file_put_contents($PROJ . '/tools/_ts72d.xlsx', $tsx272);
        $zg72 = new ZipArchive();
        if ($zg72->open($PROJ . '/tools/_ts72d.xlsx') === true) { $sh3g = (string)$zg72->getFromName('xl/worksheets/sheet3.xml'); $zg72->close(); }
        @unlink($PROJ . '/tools/_ts72d.xlsx');
    }
    $needG = count($ts272['persons']);
    $extraG = max(0, (int)ceil(max(0, $needG - 38) / 19));
    $grandG = 68 + $extraG * 20;
    check('تسوية الضمان: مدارس الرقم المشترك مع بعضها — توسّع تلقائي والمجموع العام = عدد الأجراء المدموجين',
          $needG > 38 && $sh3g !== ''
          && preg_match('/<dimension ref="A1:T' . (70 + $extraG * 20) . '"/', $sh3g) === 1
          && strpos($sh3g, '<c r="A' . $grandG . '"') !== false
          && preg_match('#<c r="A' . $grandG . '"[^>]*><v>' . $needG . '</v></c>#', $sh3g) === 1
          && preg_match('#<c r="R' . $grandG . '"[^>]*><v>' . (int)$ts272['totals']['R'] . '</v></c>#', $sh3g) === 1,
          'أجراء المجموعة=' . $needG . ' كتل إضافية=' . $extraG);
    // 🎨 «ليش عم يطلع هيك التابلو» (p1): صفوف الكتل المستنسخة بنمط صف واحد موحّد (لا رقع بولد/حدود)
    $styG = [];
    for ($rG = 48; $rG <= 66; $rG++) {
        if (preg_match('#<row r="' . $rG . '"([^>]*)>#', $sh3g, $rmG) && preg_match('/\bs="(\d+)"/', $rmG[1], $smG)) $styG[$smG[1]] = 1;
    }
    check('تسوية الضمان: صفوف الكتل المستنسخة بتنسيق موحّد (نمط صف واحد — لا رقع مورّثة)',
          count($styG) === 1, 'أنماط=' . implode('،', array_keys($styG)));
}
// 🔴 «مجموع الاجور السنوية لكل فرع ضمن الحد الاقصى — وبيتغير خلال السنة» (2026-08-26):
// كل شهر يُسقَف بسقفه المؤرّخ، والسقفان التاريخيان للعائلي مزروعان من تسويته الرسمية
check('تسوية الضمان: سقف العائلي مؤرّخ (12م حتى 6/2025 ثم 18م ثم 28م من 5/2026) + الشفاء موصول',
      (float)(getCnssBracket('allocations_familiales', 3, 2025)['max_salary_lbp'] ?? 0) === 12000000.0
      && (float)(getCnssBracket('allocations_familiales', 10, 2025)['max_salary_lbp'] ?? 0) === 18000000.0
      && (float)(getCnssBracket('allocations_familiales', 3, 2026)['max_salary_lbp'] ?? 0) === 18000000.0
      && (float)(getCnssBracket('allocations_familiales', 6, 2026)['max_salary_lbp'] ?? 0) === 28000000.0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healCnssFamilyCeilings20260826();') !== false);
$tsC72 = cnssTaswiyaData($db, 2025, [2]);
check('تسوية الضمان: العائلي الشهري مسقوف شهراً بشهر طبق ملفه الرسمي (1-6/2025 = 24م و7-12 = 18م)',
      (int)$tsC72['monthly'][3]['fam'] === 24000000 && (int)$tsC72['monthly'][10]['fam'] === 18000000
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "clampCnssBase(\$bfam, 'allocations_familiales', \$m, \$fy)") !== false,
      'شهر3=' . $tsC72['monthly'][3]['fam'] . ' شهر10=' . $tsC72['monthly'][10]['fam']);
$tsHtml72 = renderPage('pages/official_forms.php', ['form' => 'cnss_taswiya', 'fy' => '2025', 'ts_schools' => ['2']], [], [2]);
check('تسوية الضمان: الشاشة تعرض المعاينة والاختيار الحر وزر الملف الرسمي',
      strpos($tsHtml72, 'المدارس المشمولة بالتسوية') !== false
      && strpos($tsHtml72, 'form=cnss_taswiya&amp;fy=2025&amp;schools=2') !== false
      && strpos($tsHtml72, 'الجدول الملحق') !== false
      && strpos($tsHtml72, 'اشتراكات التسوية') !== false);

/* =====================================================================
 * 73) 🏛️ قوانين الدولة (2026-08-26): «الضمان وضريبة الدخل وصندوق التعويضات
 *     كل واحد مستقل لحالو... والنسب المئوية والحدود القصوى والحدود الادنى»
 *     — صفحة أركان ثلاثة + عمود الحد الأدنى المؤرّخ بحدود الفروع ويطبّقه المحرّك.
 * =================================================================== */
$sl73 = renderPage('pages/state_laws.php', [], []);
check('قوانين الدولة: صفحة الأركان الثلاثة المستقلة (نسب + حدود قصوى ودنيا + شطور) مربوطة بالقائمة',
      strpos($sl73, 'الضمان الاجتماعي — مستقل لحاله') !== false
      && strpos($sl73, 'صندوق التعويضات (الهيئة التعليمية) — مستقل لحاله') !== false
      && strpos($sl73, 'ضريبة الدخل (الباب الثاني') !== false
      && strpos($sl73, 'الحد الأدنى') !== false && strpos($sl73, 'الشطور المؤرّخة') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'pages/state_laws.php') !== false);
renderPage('pages/social_security.php', [], []); // يركّب عمود الحد الأدنى إن لم يوجد
$min73 = $db->query("SHOW COLUMNS FROM cnss_brackets LIKE 'min_salary_lbp'")->fetch();
check('الحد الأدنى المؤرّخ: العمود مركّب ذاتياً + صفحة الحدود تحفظه وتعرضه',
      $min73 !== false
      && strpos((string)file_get_contents($PROJ . '/pages/social_security.php'), 'min_salary_lbp') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/social_security.php'), 'الحد الأدنى أكبر من الأقصى') !== false);
// المحرّك يطبّق الحد الأدنى المؤرّخ: نجرّب بسطر مؤقت على فرع الصندوق بفترة ماضية بعيدة خالية
$db->exec("DELETE FROM cnss_brackets WHERE branch='eoc' AND effective_from='1990-01-01'");
$db->exec("INSERT INTO cnss_brackets (branch, max_salary_lbp, min_salary_lbp, effective_from, effective_to, notes)
           VALUES ('eoc', 900, 100, '1990-01-01', '1990-12-31', 'فحص regression مؤقت')");
$clampLo = clampCnssBase(50, 'eoc', 6, 1990);
$clampHi = clampCnssBase(5000, 'eoc', 6, 1990);
$clampZero = clampCnssBase(0, 'eoc', 6, 1990);
$clampOut = clampCnssBase(50, 'eoc', 6, 1991);
$db->exec("DELETE FROM cnss_brackets WHERE branch='eoc' AND effective_from='1990-01-01'");
check('المحرّك يطبّق الحدود المؤرّخة الاثنين: الأدنى يرفع (50→100) والأقصى يسقف (5000→900) وغير الخاضع (0) لا يُرفع وخارج الفترة لا يسري',
      (float)$clampLo === 100.0 && (float)$clampHi === 900.0 && (float)$clampZero === 0.0 && (float)$clampOut === 50.0,
      "lo=$clampLo hi=$clampHi z=$clampZero out=$clampOut");

/* =====================================================================
 * 74) 🏫 سيدة النجاة 2025-2026 (2026-08-27): «رواتب وتعويض نقل المتعاقدين...
 *     بدك تحطن بسنة 2025-2026» + «طابق نفس الاسماء... اسماء زيادة بدك تشيلهن»
 *     — كشفا البرنامج القديم (13 متعاقداً + 6 موظفين خاضعين) هما المرجع بالمليم،
 *     وغير الخاضعين الزائدين شيلوا بقراره، وجيسيكا كنعان (دخلت 1/11/2025) باقية.
 * =================================================================== */
healNajatSheet20260827();
$naj74 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النجاة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
$sum74 = function (string $type) use ($db, $naj74) {
    return $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.total_due_lbp),0) due, COALESCE(SUM(ms.net_salary_lbp),0) net,
            COALESCE(SUM(ms.income_tax_lbp),0) tax, COALESCE(SUM(ms.cnss_amount_lbp),0) cnss, COALESCE(SUM(ms.transport_lbp),0) tr
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.school_id = $naj74 AND e.employee_type = '$type' AND ms.year = 2025 AND ms.month = 10")->fetch();
};
$c74 = $sum74('enseignant_contractuel'); $e74 = $sum74('employe');
check('النجاة: كشف تشرين الأول 2025 للمتعاقدين الخاضعين = كشفه القديم بالمليم (13 أستاذاً، صافي 791,810,000 + نقل 108,000,000 = مجموع 899,810,000، ضريبة 9,410,000، ضمان 24,780,000)',
      (int)$c74['n'] === 13 && (float)$c74['due'] === 899810000.0 && (float)$c74['net'] === 791810000.0
      && (float)$c74['tr'] === 108000000.0 && (float)$c74['tax'] === 9410000.0 && (float)$c74['cnss'] === 24780000.0,
      "n={$c74['n']} due={$c74['due']}");
check('النجاة: كشف تشرين الأول 2025 للموظفين الخاضعين = كشفه القديم بالمليم (6 موظفين، صافي 166,995,000 + نقل 54,000,000 = مجموع 220,995,000، ضريبة 0 — الصافي داون للألف 2026-09-04)',
      (int)$e74['n'] === 6 && (float)$e74['due'] === 220995000.0 && (float)$e74['net'] === 166995000.0
      && (float)$e74['tr'] === 54000000.0 && (float)$e74['tax'] === 0.0,
      "n={$e74['n']} due={$e74['due']}");
$who74 = function (string $first, string $last) use ($db, $naj74) {
    $st = $db->prepare("SELECT e.id, e.employee_type, e.apply_family_deduction,
            (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id=e.id AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) yr
        FROM employees e WHERE e.school_id=$naj74 AND e.is_deleted=0 AND e.first_name_ar LIKE ? AND e.last_name_ar LIKE ? ORDER BY yr DESC LIMIT 1");
    $st->execute([$first . '%', '%' . $last . '%']);
    return $st->fetch() ?: ['id'=>0,'employee_type'=>'','apply_family_deduction'=>-1,'yr'=>0];
};
$alaa74 = $who74('علاء', 'شمعون'); $claude74 = $who74('كلود', 'كامل'); $camil74 = $who74('كميل', 'مرعي');
$camTax74 = (int)$db->query("SELECT income_tax_lbp FROM monthly_salaries WHERE employee_id=" . (int)$camil74['id'] . " AND year=2025 AND month=10")->fetchColumn();
check('النجاة: علاء شمعون وكلود كامل وكميل مرعي متعاقدون حسب كشفه (كانوا «ملاك» خطأً) وكميل بلا تنزيل عائلي وضريبته 2,240,000',
      $alaa74['employee_type'] === 'enseignant_contractuel' && $claude74['employee_type'] === 'enseignant_contractuel'
      && $camil74['employee_type'] === 'enseignant_contractuel' && (int)$camil74['apply_family_deduction'] === 0
      && $camTax74 === 2240000,
      "كميل tax=$camTax74");
$hanan74 = $who74('حنان', 'تحومي');
$hanNet74 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(net_salary_lbp),0) s FROM monthly_salaries WHERE employee_id=" . (int)$hanan74['id'] . " AND (year*100+month) BETWEEN 202510 AND 202609")->fetch();
check('النجاة: حنان تحومي مكمَّلة كل السنة بقراره (12 شهراً × 27,160,000 = 325,920,000 — كانت أيار-أيلول نقلاً بلا راتب)',
      (int)$hanNet74['n'] === 12 && (float)$hanNet74['s'] === 325920000.0, "n={$hanNet74['n']} s={$hanNet74['s']}");
$aline74 = $who74('الين', 'قاصوف'); $jes74 = $who74('جيسيكا', 'كنعان');
$jesOct74 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=" . (int)$jes74['id'] . " AND year=2025 AND month=10")->fetchColumn();
check('النجاة «طابق نفس الاسماء»: غير الخاضعين الزائدين بلا أشهر 2025-2026 (الين قاصوف نموذجاً) + جيسيكا كنعان باقية 11 شهراً من دخولها 1/11/2025 بلا صف تشرين وهمي + نسخة الاسترجاع _ms_bk_najat20260827 موجودة',
      (int)$aline74['yr'] === 0 && (int)$jes74['yr'] === 11 && $jesOct74 === 0
      && (int)$db->query("SELECT COUNT(*) FROM _ms_bk_najat20260827")->fetchColumn() > 0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNajatSheet20260827') !== false,
      "قاصوف={$aline74['yr']} كنعان={$jes74['yr']}");
healNajatGcaOff20260827();
$diana74 = $who74('ديانا', 'شرو'); $karin74 = $who74('كارين', 'السكاف');
$gca74 = $db->query("SELECT SUM(grant_children_addition) FROM employees WHERE id IN (" . (int)$diana74['id'] . "," . (int)$karin74['id'] . ")")->fetchColumn();
check('النجاة «طفي» (2026-08-27): مفتاح تنزيل الأولاد مطفأ عند ديانا شرو وكارين السكاف (كشف 2025-2026 المدفوع بتنزيل العازب فقط) والشفاء موصول بالهيدر',
      (int)$gca74 === 0 && (int)$diana74['id'] > 0 && (int)$karin74['id'] > 0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNajatGcaOff20260827') !== false,
      "gca_sum=$gca74");
// الشفاء التكميلي (باميلا نضّور أونلاين بلا أي شهر): تجربة فعلية — نحذف شهر تشرين ٢ عندها
// محلياً ونشغّل الشفاء فيعيد خلقه بقيم الكشف نفسها مدفوعاً (ثم لا حاجة لاسترجاع: القيم مطلقة)
$pam74 = $who74('باميلا', 'نضّور');
$pamRow74 = $db->query("SELECT * FROM monthly_salaries WHERE employee_id=" . (int)$pam74['id'] . " AND year=2025 AND month=11")->fetch();
$fillOk74 = false;
if ($pamRow74) {
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id=" . (int)$pam74['id'] . " AND year=2025 AND month=11");
    $db->prepare("DELETE FROM settings WHERE `key`='heal_najat_sheet_fill_20260827'")->execute();
    $sc74 = &settingsCache(); unset($sc74['heal_najat_sheet_fill_20260827']);
    healNajatSheetFill20260827();
    $re74 = $db->query("SELECT net_salary_lbp, income_tax_lbp, cnss_amount_lbp, transport_lbp, total_due_lbp, prime_fixe_lbp, is_paid FROM monthly_salaries WHERE employee_id=" . (int)$pam74['id'] . " AND year=2025 AND month=11")->fetch();
    $fillOk74 = $re74 && (float)$re74['net_salary_lbp'] === 42550000.0 && (float)$re74['income_tax_lbp'] === 130000.0
        && (float)$re74['cnss_amount_lbp'] === 1320000.0 && (float)$re74['transport_lbp'] === 9000000.0
        && (float)$re74['total_due_lbp'] === 51550000.0 && (float)$re74['prime_fixe_lbp'] === 42000000.0 && (int)$re74['is_paid'] === 1;
    if (!$re74) { // فشل الخلق ⇒ استرجاع الصف الأصلي كي لا تنقص الداتا
        $cols74 = array_keys($pamRow74);
        $db->prepare("INSERT INTO monthly_salaries (`" . implode('`,`', $cols74) . "`) VALUES (" . implode(',', array_fill(0, count($cols74), '?')) . ")")
           ->execute(array_values($pamRow74));
    }
}
check('النجاة — الشفاء التكميلي يخلق الشهر الناقص كلياً بقيم الكشف مدفوعاً (حالة باميلا نضّور أونلاين بلا أي شهر) وهو موصول بالهيدر',
      $fillOk74 && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNajatSheetFill20260827') !== false,
      $pamRow74 ? 'أُعيد خلق تشرين ٢' : 'صف باميلا الأصلي غائب');
// تواريخ ترك «مستحيلة» (أقدم من الدخول — حالة باميلا أونلاين 2024-01-12 قبل دخولها 2024-10-01):
// الشفاء يمسحها + تجربة فعلية: نزرعها مؤقتاً محلياً ثم نتأكد أنها انمسحت وظهرت بالكشف
healNajatPamelaLeft20260827();
$db->exec("UPDATE employees SET left_date_cnss='2024-01-12', left_date_finance='2024-01-12', left_date_eoc='2024-01-12' WHERE id=" . (int)$pam74['id']);
$db->prepare("DELETE FROM settings WHERE `key`='heal_najat_pamela_left_20260827'")->execute();
$scPam74 = &settingsCache(); unset($scPam74['heal_najat_pamela_left_20260827']);
healNajatPamelaLeft20260827();
$pamLeft74 = $db->query("SELECT COALESCE(left_date_cnss, left_date_finance, left_date_eoc) FROM employees WHERE id=" . (int)$pam74['id'])->fetchColumn();
check('النجاة — تواريخ الترك المستحيلة (أقدم من الدخول، حالة باميلا أونلاين) تُمسح بالشفاء فيرجع الظهور بالكشوف + موصول بالهيدر',
      $pamLeft74 === null
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNajatPamelaLeft20260827') !== false,
      'left=' . var_export($pamLeft74, true));

/* =====================================================================
 * 75) 🏫 تدقيق ملاك عبرا على كشفه (2026-08-27 مساءً): «شيك على رواتب الملاك
 *     مدرسة السيدة عبرا» — 102/131 مطابقين؛ بقراره الصريح: تصليح ريتا مارون
 *     حليحل (أخذت سلفة ريتا يوسف 138م ⇒ 80م) وماريا الياس حليحل (سلفة صفر ⇒
 *     53م) على الكشف بالمليم + فيوليت الحمصي وتريز حبقوق «اساتذة تعاقد».
 * =================================================================== */
healAbraFixes20260827();
$abra75 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة ثانوية السيدة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
$who75 = function (string $first, string $father, string $last) use ($db, $abra75) {
    $st = $db->prepare("SELECT e.id, e.employee_type FROM employees e
        WHERE e.school_id=$abra75 AND e.is_deleted=0 AND e.first_name_ar LIKE ? AND e.father_name_ar LIKE ? AND e.last_name_ar LIKE ? LIMIT 1");
    $st->execute([$first . '%', $father . '%', '%' . $last . '%']);
    return $st->fetch() ?: ['id'=>0,'employee_type'=>''];
};
$ritaM75 = $who75('ريتا', 'مارون', 'حليحل'); $mariaE75 = $who75('ماريا', 'الياس', 'حليحل');
$rr75 = $db->query("SELECT COUNT(*) n, SUM(net_salary_lbp) s, MAX(prime_fixe_lbp) p FROM monthly_salaries WHERE employee_id=" . (int)$ritaM75['id'] . " AND (year*100+month) BETWEEN 202510 AND 202609")->fetch();
$mm75 = $db->query("SELECT COUNT(*) n, SUM(net_salary_lbp) s, MAX(prime_fixe_lbp) p FROM monthly_salaries WHERE employee_id=" . (int)$mariaE75['id'] . " AND (year*100+month) BETWEEN 202510 AND 202609")->fetch();
// 🔄 (2026-09-10 «دايما طبّق القانون بكل البرنامج» + «أكيد كلهم 65»): القفل صار على القانون لا على الكشف القديم —
//    ريتا: نسبة 65٪ (80م تشرين→كانون ثم 86م من كانون 2026 بدرجة 26) مجموع الصافي 932,532,000؛ ماريا: 65٪ (53م ثم 61م من كانون بدرجة 16) مجموع 656,026,000
//    (2026-09-11: صندوقها صار يشمل الأجر الإضافي بقراره بتقرير المخالفات eoc_base_only — 3,262,500 بتشرين = كشفه القديم).
check('عبرا: ريتا مارون حليحل وماريا الياس حليحل بالقانون (65٪ + درجات كانون 2026 + صندوق ماريا يشمل الإضافي): ريتا صافي السنة 932,599,000 (بعد ضبطه توقيت درجتها أونلاين 2026-09-11) وإضافي حتى 86م · ماريا 656,026,000 وإضافي حتى 61م',
      (int)$rr75['n'] === 12 && (float)$rr75['s'] === 932599000.0 && (float)$rr75['p'] === 86000000.0
      && (int)$mm75['n'] === 12 && (float)$mm75['s'] === 656026000.0 && (float)$mm75['p'] === 61000000.0,
      "ريتا s={$rr75['s']} p={$rr75['p']} ماريا s={$mm75['s']} p={$mm75['p']}");
$vio75 = $who75('فيوليت', 'جميل', 'الحمصي'); $ter75 = $who75('تريز', 'جوزيف', 'حبقوق');
check('عبرا: فيوليت الحمصي وتريز حبقوق «اساتذة تعاقد» بقراره — الفئة متعاقد والأرقام المخزّنة بلا مسّ + الشفاء موصول بالهيدر',
      $vio75['employee_type'] === 'enseignant_contractuel' && $ter75['employee_type'] === 'enseignant_contractuel'
      && (float)$db->query("SELECT net_salary_lbp FROM monthly_salaries WHERE employee_id=" . (int)$vio75['id'] . " AND year=2025 AND month=10")->fetchColumn() === 76709000.0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healAbraFixes20260827') !== false);
// كشف متعاقدي وموظفي عبرا (a1..a4): 52/52 مطابقون — بعد حذف غير الخاضعين بقراره،
// مجموع تشرين لغير الملاك = سطر مجموع كشفه بالمليم + تقسيم فيوليت/تريز على الكشف
healAbraCw20260827();
$cw75 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.net_salary_lbp),0) net, COALESCE(SUM(ms.transport_lbp),0) tr, COALESCE(SUM(ms.total_due_lbp),0) due
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.school_id=$abra75 AND (e.employee_type <> 'enseignant_titulaire' OR COALESCE(e.titularization_date,'1900-01-01') >= '2026-10-01') AND ms.year=2025 AND ms.month=10")->fetch();
check('عبرا: كشف تشرين للمتعاقدين والموظفين الخاضعين = كشفه القديم بالمليم (52 شخصاً، صافي 2,063,537,000 + نقل 376,200,000 = مجموع 2,439,737,000 — الصافي داون للألف) بعد حذف غير الخاضعين الـ11 بقراره',
      (int)$cw75['n'] === 52 && (float)$cw75['net'] === 2063537000.0
      && (float)$cw75['tr'] === 376200000.0 && (float)$cw75['due'] === 2439737000.0,
      "n={$cw75['n']} net={$cw75['net']}");
$terSplit75 = $db->query("SELECT base_plus_echelon_lbp be, prime_fixe_lbp p, net_salary_lbp nt FROM monthly_salaries WHERE employee_id=" . (int)$ter75['id'] . " AND year=2025 AND month=10")->fetch();
$vioSplit75 = $db->query("SELECT base_plus_echelon_lbp be, prime_fixe_lbp p, net_salary_lbp nt FROM monthly_salaries WHERE employee_id=" . (int)$vio75['id'] . " AND year=2025 AND month=10")->fetch();
$bilal75 = $who75('بلال', 'علي', 'اسعد');
$bilalRows75 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=" . (int)$bilal75['id'] . " AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
check('عبرا: تقسيم تريز 2,600,000+100م وفيوليت 2,225,000+78م متل كشفه والصافي ما تغيّر + غير الخاضعين انشالوا (بلال اسعد بلا أشهر) + الشفاء موصول',
      (float)$terSplit75['be'] === 2600000.0 && (float)$terSplit75['p'] === 100000000.0 && (float)$terSplit75['nt'] === 97518000.0
      && (float)$vioSplit75['be'] === 2225000.0 && (float)$vioSplit75['p'] === 78000000.0 && (float)$vioSplit75['nt'] === 76709000.0
      && $bilalRows75 === 0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healAbraCw20260827') !== false,
      "تريز={$terSplit75['be']}/{$terSplit75['p']} بلال=$bilalRows75");
// النسخة الثانية (بلال/جوزيف بولس بداتا أونلاين منحرفة): تجربة فعلية — نزرع صف تشرين
// مؤقتاً لبلال ثم نشغّل الشفاء فيمحوه رغم كونه «خاضعاً» (الصمّام لا يحميه بأمر المستخدم)
healAbraCw2_20260827();
$cw2Ok75 = false;
if ((int)$bilal75['id'] > 0) {
    $db->exec("INSERT INTO monthly_salaries (employee_id, school_id, month, year, school_year, grade_at_month,
        base_salary_lbp, base_plus_echelon_lbp, prime_fixe_lbp, cnss_amount_lbp, taxable_base_lbp, income_tax_lbp,
        total_retenues_lbp, net_salary_lbp, transport_lbp, total_due_lbp, exchange_rate, is_calculated, is_paid)
        VALUES (" . (int)$bilal75['id'] . ", $abra75, 10, 2025, '2025-2026', 1,
        0, 0, 49000000, 1470000, 49000000, 230000, 1700000, 47300000, 0, 47300000, 89500, 1, 1)");
    $db->prepare("DELETE FROM settings WHERE `key`='heal_abra_cw2_20260827'")->execute();
    $scCw75 = &settingsCache(); unset($scCw75['heal_abra_cw2_20260827']);
    healAbraCw2_20260827();
    $cw2Ok75 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=" . (int)$bilal75['id'] . " AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn() === 0;
    if (!$cw2Ok75) $db->exec("DELETE FROM monthly_salaries WHERE employee_id=" . (int)$bilal75['id'] . " AND year=2025 AND month=10"); // تنظيف لو فشل
}
check('عبرا — النسخة الثانية تشيل صفوف بلال اسعد وجوزيف بولس حتى لو كانت «خاضعة» (داتا أونلاين منحرفة، بأمره الصريح) + موصولة بالهيدر',
      $cw2Ok75 && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healAbraCw2_20260827') !== false,
      'زرعنا صف تشرين خاضعاً لبلال والشفاء محاه');

/* =====================================================================
 * 76) 🏫 البشارة (2026-08-27 مساءً): «p1 شوف وصحح» — كشف المتعاقدين والموظفين
 *     الخاضعين (7+4): الـ11 مطابقون بالمليم، وغير الخاضعين الأربعة الزائدون
 *     شيلوا بنمط النجاة/عبرا الذي قرّره (تغريد غدار/ادي فرنسيس/جوسلين مرعي/عماد ديب).
 * =================================================================== */
healBecharaCw20260827();
$bech76 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
$bcw76 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.net_salary_lbp),0) net, COALESCE(SUM(ms.transport_lbp),0) tr, COALESCE(SUM(ms.total_due_lbp),0) due
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.school_id=$bech76 AND e.employee_type <> 'enseignant_titulaire' AND ms.year=2025 AND ms.month=10")->fetch();
check('البشارة: كشف تشرين للمتعاقدين والموظفين الخاضعين = كشفه القديم بالمليم (11 شخصاً، صافي 358,842,000 + نقل 82,800,000 = مجموع 441,642,000 — الصافي داون للألف) بعد حذف غير الخاضعين الأربعة بقراره',
      (int)$bcw76['n'] === 11 && (float)$bcw76['net'] === 358842000.0
      && (float)$bcw76['tr'] === 82800000.0 && (float)$bcw76['due'] === 441642000.0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healBecharaCw20260827') !== false,
      "n={$bcw76['n']} net={$bcw76['net']}");

/* =====================================================================
 * 77) 🏫 سيدة الانتقال-زحلة (2026-08-27 مساءً): «المتعاقد والموظف صحح» —
 *     كشفه (13 متعاقداً + 4 موظفين، مجموع 762,850,410): 5 متعاقدين كانوا «ملاك»
 *     خطأً حُوِّلوا وضُبطوا بقيم الكشف، وكلاريتا وبول غير الخاضعين شيلوا بالنمط.
 * =================================================================== */
healEntikalCw20260827();
$ent77 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة الانتقال%' AND is_deleted=0 LIMIT 1")->fetchColumn();
$ecw77 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.net_salary_lbp),0) net, COALESCE(SUM(ms.transport_lbp),0) tr, COALESCE(SUM(ms.total_due_lbp),0) due
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.school_id=$ent77 AND e.employee_type <> 'enseignant_titulaire' AND ms.year=2025 AND ms.month=10")->fetch();
check('الانتقال: كشف تشرين للمتعاقدين والموظفين الخاضعين = كشفه القديم بالمليم (17 شخصاً، صافي 649,445,000 + نقل 113,400,000 = مجموع 762,845,000 — الصافي داون للألف)',
      (int)$ecw77['n'] === 17 && (float)$ecw77['net'] === 649445000.0
      && (float)$ecw77['tr'] === 113400000.0 && (float)$ecw77['due'] === 762845000.0,
      "n={$ecw77['n']} net={$ecw77['net']}");
$who77 = function (string $first, string $last) use ($db, $ent77) {
    $st = $db->prepare("SELECT e.id, e.employee_type FROM employees e WHERE e.school_id=$ent77 AND e.is_deleted=0
        AND e.first_name_ar LIKE ? AND e.last_name_ar LIKE ? ORDER BY (SELECT COUNT(*) FROM monthly_salaries ms
        WHERE ms.employee_id=e.id AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) DESC LIMIT 1");
    $st->execute([$first . '%', '%' . $last . '%']);
    return $st->fetch() ?: ['id'=>0,'employee_type'=>''];
};
$samir77 = $who77('سمير', 'اعزان'); $almas77 = $who77('الماس', 'فرح'); $clarita77 = $who77('كلاريتا', 'مساعد');
$samOct77 = $db->query("SELECT net_salary_lbp nt, income_tax_lbp tx FROM monthly_salaries WHERE employee_id=" . (int)$samir77['id'] . " AND year=2025 AND month=10")->fetch();
$clRows77 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=" . (int)$clarita77['id'] . " AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
check('الانتقال: الخمسة صاروا متعاقدين حسب كشفه (الماس فرح وسمير اعزان نموذجاً) وسمير على ضريبة تنزيله العائلي (155,000 وصافي 47,375,000) + كلاريتا بلا أشهر + الشفاء موصول',
      $almas77['employee_type'] === 'enseignant_contractuel' && $samir77['employee_type'] === 'enseignant_contractuel'
      && (float)$samOct77['nt'] === 47375000.0 && (float)$samOct77['tx'] === 155000.0
      && $clRows77 === 0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healEntikalCw20260827') !== false,
      "سمير net={$samOct77['nt']} tax={$samOct77['tx']} كلاريتا=$clRows77");

/* =====================================================================
 * 78) 🏫 سيدة النياح-زحلة (2026-08-27 مساءً): «تصحيح المتعاقد والموظف» —
 *     كشفه (4 متعاقدين + موظفة، مجموع 293,326,600): بياره بوزيدان كانت «ملاك»
 *     خطأً حُوِّلت بقيم الكشف، والزائدون الخمسة غير الخاضعين شيلوا بالنمط.
 * =================================================================== */
healNiyahCw20260827();
$niy78 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النياح%' AND is_deleted=0 LIMIT 1")->fetchColumn();
$ncw78 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.net_salary_lbp),0) net, COALESCE(SUM(ms.transport_lbp),0) tr, COALESCE(SUM(ms.total_due_lbp),0) due
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.school_id=$niy78 AND e.employee_type <> 'enseignant_titulaire' AND ms.year=2025 AND ms.month=10")->fetch();
$nm78 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.cnss_amount_lbp),0) cn, COALESCE(SUM(ms.transport_lbp),0) tr
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.school_id=$niy78 AND e.employee_type = 'enseignant_titulaire' AND ms.year=2025 AND ms.month=10")->fetch();
check('النياح: كشف تشرين للمتعاقدين والموظفين = كشفه بالمليم (5 أشخاص، صافي 257,326,000 + نقل 36,000,000 = مجموع 293,326,000 — الصافي داون للألف) وبياره متعاقدة والملاك صاروا 17 بضمان كشفهم 46,155,450',
      (int)$ncw78['n'] === 5 && (float)$ncw78['net'] === 257326000.0
      && (float)$ncw78['tr'] === 36000000.0 && (float)$ncw78['due'] === 293326000.0
      // 🧮 (2026-09-03) بعد «طبّق النسبة اللي بتطلع صح»: 5 من ملاك النياح تحوّلوا لنسبتهم (فرق فراطات ≤490 ألفاً بالإضافي) فصار ضمانهم 46,165,350 (كان 46,155,450 بكشفه)
      && (int)$nm78['n'] === 17 && in_array((float)$nm78['cn'], [46155450.0, 46165350.0], true) && (float)$nm78['tr'] === 153000000.0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNiyahCw20260827') !== false,
      "cw n={$ncw78['n']} net={$ncw78['net']} ملاك n={$nm78['n']}");

/* =====================================================================
 * 79) 🏫 دار السعادة-كسارة (2026-08-27 مساءً): «شوف وصحح» — كشف الموظفين
 *     الخاضعين (8، مجموع 301,319,000): الثمانية مطابقون بالمليم، والزائدون
 *     غير الخاضعين الـ12 شيلوا بالنمط (صمّام الخضوع حمى الياس منير سمعان).
 * =================================================================== */
healKsaraCw20260827();
$ksa79 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE 'دار السعادة للراهبات%' AND is_deleted=0 LIMIT 1")->fetchColumn();
$kcw79 = $db->query("SELECT COUNT(*) n, COALESCE(SUM(ms.net_salary_lbp),0) net, COALESCE(SUM(ms.transport_lbp),0) tr, COALESCE(SUM(ms.total_due_lbp),0) due
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.school_id=$ksa79 AND ms.year=2025 AND ms.month=10")->fetch();
$elias79 = $db->prepare("SELECT e.id FROM employees e WHERE e.school_id=$ksa79 AND e.is_deleted=0 AND e.first_name_ar LIKE 'الياس%' AND e.father_name_ar LIKE 'منير%' LIMIT 1");
$elias79->execute(); $eliasId79 = (int)$elias79->fetchColumn();
$eliasNet79 = (float)$db->query("SELECT net_salary_lbp FROM monthly_salaries WHERE employee_id=$eliasId79 AND year=2025 AND month=10")->fetchColumn();
check('كسارة: كشف تشرين للموظفين الخاضعين = كشفه بالمليم (8 موظفين، صافي 225,719,000 + نقل 75,600,000 = مجموع 301,319,000) والياس منير سمعان الخاضع محميّ (31,525,000)',
      (int)$kcw79['n'] === 8 && (float)$kcw79['net'] === 225719000.0
      && (float)$kcw79['tr'] === 75600000.0 && (float)$kcw79['due'] === 301319000.0
      && $eliasNet79 === 31525000.0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healKsaraCw20260827') !== false,
      "n={$kcw79['n']} net={$kcw79['net']} الياس=$eliasNet79");

// كلاريتا (الانتقال) — النسخة الثانية تشيل صفوفها حتى الخاضعة (داتا أونلاين منحرفة):
// تجربة فعلية بزرع صف خاضع ثم محوه
healEntikalCw2_20260827();
$cla79 = $db->prepare("SELECT id FROM employees WHERE school_id=$ent77 AND first_name_ar LIKE 'كلاريتا%' AND last_name_ar LIKE 'مساعد%' LIMIT 1");
$cla79->execute(); $claId79 = (int)$cla79->fetchColumn();
$claOk79 = false;
if ($claId79 > 0) {
    $db->exec("INSERT INTO monthly_salaries (employee_id, school_id, month, year, school_year, grade_at_month,
        base_salary_lbp, base_plus_echelon_lbp, prime_fixe_lbp, cnss_amount_lbp, taxable_base_lbp, income_tax_lbp,
        total_retenues_lbp, net_salary_lbp, transport_lbp, total_due_lbp, exchange_rate, is_calculated, is_paid)
        VALUES ($claId79, $ent77, 10, 2025, '2025-2026', 1, 0, 0, 17000000, 510000, 17000000, 0, 510000, 16490000, 0, 16490000, 89500, 1, 1)");
    $db->prepare("DELETE FROM settings WHERE `key`='heal_entikal_cw2_20260827'")->execute();
    $scCla79 = &settingsCache(); unset($scCla79['heal_entikal_cw2_20260827']);
    healEntikalCw2_20260827();
    $claOk79 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=$claId79 AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn() === 0;
    if (!$claOk79) $db->exec("DELETE FROM monthly_salaries WHERE employee_id=$claId79 AND year=2025 AND month=10");
}
check('الانتقال — النسخة الثانية تشيل صفوف كلاريتا مساعد حتى «الخاضعة» (داتا أونلاين منحرفة، بالنمط المقرَّر) + موصولة بالهيدر',
      $claOk79 && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healEntikalCw2_20260827') !== false,
      'زرعنا صف تشرين خاضعاً لكلاريتا والشفاء محاه');

/* =====================================================================
 * 80) 📅 «ما عم يحفظ تغيير تاريخ المهلة» (2026-08-28): كتابة التاريخ بأرقام
 *     عربية أو بمسافات كانت تُتجاهَل بصمت مع رسالة نجاح كاذبة — parseFlexibleDate
 *     صار يطبّع الأرقام والمسافات (يعمّ كل البرنامج)، والمعالج يصارح بالخطأ.
 * =================================================================== */
check('التاريخ المرن: الأرقام العربية والمسافات وسنة-شهر-يوم كلها تُفهم، وغير المفهوم يبقى مرفوضاً',
      parseFlexibleDate('٣٠/٠٨/٢٠٢٦') === '2026-08-30'
      && parseFlexibleDate(' 15 / 9 / 2026 ') === '2026-09-15'
      && parseFlexibleDate('2026-9-5') === '2026-09-05'
      && parseFlexibleDate('15/9/26') === null && parseFlexibleDate('كلام') === null,
      'ar=' . var_export(parseFlexibleDate('٣٠/٠٨/٢٠٢٦'), true));
check('حفظ مهلة رابط المعلومات يصارح: نجاح باطل ممنوع (flash_error عند تاريخ غير مفهوم) والتطبيع موصول',
      strpos((string)file_get_contents($PROJ . '/pages/info_collect.php'), 'ما قدرت افهم التاريخ') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), 'arabicDigitsFr($s)') !== false);

/* =====================================================================
 * 81) 🎓 شيرا انطوان العاقوري (البشارة) — «عندها إجازة تعليمية» (2026-08-29):
 *     شهادتها كانت فاضية → درجة 18 وإضافي جامد يعوّض. صارت إجازة تعليمية → درجة 31
 *     حسب القانون (2,625,000) + إضافي 45٪ بقاعدة ÷1500 + الشفاء موصول بالهيدر.
 * =================================================================== */
$chi81 = $db->query("SELECT e.id, e.diploma, e.current_grade, ms.base_plus_echelon_lbp b, ms.prime_fixe_lbp p, ms.exchange_rate r
    FROM employees e JOIN schools s ON s.id=e.school_id
    LEFT JOIN monthly_salaries ms ON ms.employee_id=e.id AND ms.year=2025 AND ms.month=11
    WHERE s.name_ar LIKE 'مدرسة سيدة البشارة%' AND e.is_deleted=0 AND e.employee_type='enseignant_titulaire'
      AND e.first_name_ar='شيرا' AND e.last_name_ar LIKE '%عاقوري%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$law81 = $chi81 ? buildLegalGradeHistory((int)$chi81['id'], null, true) : null;
$scale81 = $chi81 ? (int)$db->query("SELECT new_salary_2017 FROM salary_scale_2017 WHERE version_id=1 AND grade=" . (int)floor((float)$chi81['current_grade']))->fetchColumn() : 0;
check('شيرا العاقوري: إجازة تعليمية + درجتها = القانون (31) + أساس تشرين الثاني = السلسلة + الإضافي 45٪ بقاعدة ÷1500 + الشفاء موصول',
      $chi81 && $chi81['diploma'] === 'ijaza_taalimiya'
      && $law81 && (float)$law81['final_grade'] === (float)$chi81['current_grade'] && (float)$chi81['current_grade'] >= 31
      && (int)$chi81['b'] === $scale81 && $scale81 > 0
      && (int)$chi81['p'] === (int)bonusPercentLbp(45, (int)$chi81['b'], (float)$chi81['r'])
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healChiraTaalimiya20260829();') !== false,
      'row=' . json_encode($chi81, JSON_UNESCAPED_UNICODE) . ' law=' . json_encode($law81 ? $law81['final_grade'] : null));

check('حفظ الموظف: تعبئة شهادة كانت فاضية تُعدّ تغييراً → إعادة بناء الدرجات والراتب تلقائياً (لا شرط !== null)',
      strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "\$diplomaChanged = ((string)\$oldDiploma !== (string)\$data['diploma']);") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "\$oldDiploma !== null && \$oldDiploma !== \$data['diploma']") === false);

/* =====================================================================
 * 82) 🎓 ماريا اسعد (البشارة) — «الملاك = دخول المدرسة» + «45٪ مرّة وحدة» (2026-08-29):
 *     درجتها = القانون، أساس تشرين الثاني = السلسلة، الإضافي بند نسبة واحد (لا ازدواج
 *     نسبة+مبلغ)، وحارس الازدواج بمحرّر الموظف + الشفاء موصولان.
 * =================================================================== */
$mar82 = $db->query("SELECT e.id, e.hire_date, e.titularization_date, e.current_grade, ms.base_plus_echelon_lbp b, ms.prime_fixe_lbp p, ms.exchange_rate r
    FROM employees e JOIN schools s ON s.id=e.school_id
    LEFT JOIN monthly_salaries ms ON ms.employee_id=e.id AND ms.year=2025 AND ms.month=11
    WHERE s.name_ar LIKE 'مدرسة سيدة البشارة%' AND e.is_deleted=0 AND e.employee_type='enseignant_titulaire'
      AND e.first_name_ar='ماريا' AND e.last_name_ar LIKE 'اسعد%' AND e.father_name_ar LIKE 'اديب%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$law82 = $mar82 ? buildLegalGradeHistory((int)$mar82['id'], null, true) : null;
$scale82 = $mar82 ? (int)$db->query("SELECT new_salary_2017 FROM salary_scale_2017 WHERE version_id=1 AND grade=" . (int)floor((float)$mar82['current_grade']))->fetchColumn() : 0;
$nPrime82 = $mar82 ? (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id=" . (int)$mar82['id'] . " AND bonus_type='prime_fixe' AND is_active=1 AND (school_year IS NULL OR school_year='2025-2026')")->fetchColumn() : 0;
check('ماريا اسعد: الملاك = دخول المدرسة + الدرجة = القانون + الأساس = السلسلة + إضافي بند نسبة 45٪ واحد بقاعدة ÷1500 + الشفاء موصول',
      $mar82 && $mar82['titularization_date'] === $mar82['hire_date']
      && $law82 && (float)$law82['final_grade'] === (float)$mar82['current_grade']
      && (int)$mar82['b'] === $scale82 && $scale82 > 0 && $nPrime82 === 1
      && (int)$mar82['p'] === (int)bonusPercentLbp(45, (int)$mar82['b'], (float)$mar82['r'])
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healMariaMalak20260829();') !== false,
      'row=' . json_encode($mar82, JSON_UNESCAPED_UNICODE) . ' nPrime=' . $nPrime82);
$dup82 = (int)$db->query("SELECT COUNT(*) FROM (SELECT b.employee_id FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id
    WHERE b.bonus_type='prime_fixe' AND b.is_active=1 AND (b.school_year IS NULL OR b.school_year='2025-2026')
      AND (b.start_month IS NULL OR (b.start_month=10 AND b.end_month=9)) AND e.is_deleted=0
    GROUP BY b.employee_id HAVING SUM(b.value_type='percent')>=1 AND SUM(b.value_type='amount')>=1) t")->fetchColumn();
check('محرّر الموظف: نسبة ٪ + مبلغ ثابت معاً مسموحان (قراره 2026-08-29 — لا حارس يُسقط المبلغ)',
      strpos((string)file_get_contents($PROJ . '/pages/employees.php'), '$hasPctPrime') === false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'مسموحان بقراره') !== false,
      'dups=' . $dup82);

/* =====================================================================
 * 83) 🧮 نِسَب متعدّدة لنفس الشهر تُجمَع قبل التدوير (2026-08-29، «إذا زدت 5٪ وما حطّيت 50»):
 *     45٪ + 5٪ = 50٪ بالضبط (كان كل سطر يُدوَّر لحاله فيضيع لغاية مليون). فحص بالمصدر +
 *     تجربة حيّة على شيرا (2,625,000: سطران 45+5 = 78م لا 77م) ثم الإرجاع.
 * =================================================================== */
$src83 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$chi83 = $db->query("SELECT e.id FROM employees e JOIN schools s ON s.id=e.school_id WHERE s.name_ar LIKE 'مدرسة سيدة البشارة%' AND e.is_deleted=0
    AND e.employee_type='enseignant_titulaire' AND e.first_name_ar='شيرا' AND e.last_name_ar LIKE '%عاقوري%' LIMIT 1")->fetchColumn();
$live83 = false;
if ($chi83) {
    $chi83 = (int)$chi83;
    $db->exec("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
        VALUES ($chi83, 'prime_fixe', 9, '2025-2026', 5, 'percent', 'LBP', 11, 11, 1)");
    $newId83 = (int)$db->lastInsertId();
    try {
        recalcEmployeeYear($chi83, '2025-2026');
        $p83 = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$chi83 AND year=2025 AND month=11")->fetchColumn();
        $b83 = (int)$db->query("SELECT base_plus_echelon_lbp FROM monthly_salaries WHERE employee_id=$chi83 AND year=2025 AND month=11")->fetchColumn();
        $r83 = (float)$db->query("SELECT exchange_rate FROM monthly_salaries WHERE employee_id=$chi83 AND year=2025 AND month=11")->fetchColumn();
        $live83 = ($p83 === (int)bonusPercentLbp(50, $b83, $r83)) && ($p83 > (int)bonusPercentLbp(45, $b83, $r83));
    } finally {
        $db->exec("DELETE FROM employee_bonuses WHERE id=$newId83");
        recalcEmployeeYear($chi83, '2025-2026');
    }
}
check('نِسَب متعدّدة لنفس الشهر تُجمَع ثم تُدوَّر مرّة واحدة (45٪+5٪ = 50٪ بالضبط) — مصدر + تجربة حيّة',
      strpos($src83, '$pctSum += $amount;') !== false && strpos($src83, 'bonusPercentLbp($pctSum') !== false && $live83,
      'live=' . var_export($live83, true));

/* =====================================================================
 * 84) 📌 «بعد الحفظ بضلّ بنفس المحل» (2026-08-29): app.js يخزّن موضع التمرير عند أي إرسال فورم
 *     أو رابط إجراء على نفس الصفحة ويرجّعه بعد إعادة التحميل (عام لكل البرنامج).
 * =================================================================== */
$js84 = (string)file_get_contents($PROJ . '/assets/js/app.js');
check('البقاء بنفس المحل بعد الحفظ: ppStay موجود بapp.js (submit + روابط نفس الصفحة + استرجاع بعد التحميل)',
      strpos($js84, "'ppStay:' + location.pathname") !== false
      && strpos($js84, "document.addEventListener('submit'") !== false
      && strpos($js84, 'scrollRestoration') !== false
      && strpos($js84, 'window.scrollTo(0, st.y)') !== false);

check('حفظ الدرجات من ملف الأستاذ يرجع لنفس الصفحة والتبويب (return_url بالنموذجين + gradeReturnToEmployee بكل معالجات grades.php)',
      substr_count((string)file_get_contents($PROJ . '/includes/functions.php'), 'name="return_url"') >= 2
      && strpos((string)file_get_contents($PROJ . '/pages/grades.php'), 'function gradeReturnToEmployee') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/grades.php'), "employees.php?action=edit&id=' . \$employeeId . '#gradesPanel'") === false);

/* =====================================================================
 * 85) 🪄 عرض «درجة كل سنتين» (2026-08-29): لوحة الدرجات تلمّ نصفَي التدرّج العادي بسطر واحد
 *     **عرضاً فقط** — التخزين بالأنصاف والدرجة والراتب لا يتغيّرون؛ صح السطر الملموم يشمل
 *     النصفين (مرآة مخفية) والحذف "id1,id2". تجربة على شيرا (11 نصفاً → 5 كاملة + نصف مفرد).
 * =================================================================== */
$chi85 = $db->query("SELECT e.* FROM employees e JOIN schools s ON s.id=e.school_id WHERE s.name_ar LIKE 'مدرسة سيدة البشارة%' AND e.is_deleted=0
    AND e.employee_type='enseignant_titulaire' AND e.first_name_ar='شيرا' AND e.last_name_ar LIKE '%عاقوري%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ok85 = false; $why85 = 'no emp';
if ($chi85) {
    $gBefore = (float)$chi85['current_grade'];
    $halves = (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id=" . (int)$chi85['id'] . " AND reason='biennial_promotion' AND delta=0.5 AND notes NOT LIKE '%تقديم%'")->fetchColumn();
    ob_start(); renderGradeChecklist($chi85, 'grades'); $html85 = ob_get_clean();
    $full = substr_count($html85, 'درجة عادية كاملة (كل سنتين)');
    $single = substr_count($html85, 'نص درجة عادية (تكتمل');
    $mirrors = substr_count($html85, 'class="gr-pair-mirror"');
    $keepN = preg_match_all('/name="keep\[\]"/', $html85);
    $storedRows = (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id=" . (int)$chi85['id'] . " AND reason<>'titularization'")->fetchColumn();
    $gAfter = (float)$db->query("SELECT current_grade FROM employees WHERE id=" . (int)$chi85['id'])->fetchColumn();
    // 🔴 (2026-09-10 «ترتيب الدرجات أوضح»): يُلمّ النصفان فقط إذا كانا **متتاليَين** بلا درجة بينهما —
    // المتوقَّع يُحسب من السجلّ نفسه بنفس القاعدة (نصف عادي غير «تقديم» + نفس حالة الاحتساب + متجاوران).
    $expFull = 0; $expSingle = 0; $pend85 = null;
    foreach ($db->query("SELECT reason, delta, counted, notes FROM employee_grade_history WHERE employee_id=" . (int)$chi85['id'] . " ORDER BY change_date, id") as $r85) {
        $isHalf = $r85['reason'] === 'biennial_promotion' && abs((float)$r85['delta'] - 0.5) < 0.001 && strpos((string)$r85['notes'], 'تقديم') === false;
        if (!$isHalf) { if ($pend85 !== null) $expSingle++; $pend85 = null; continue; }
        if ($pend85 !== null && (int)$pend85 === (int)$r85['counted']) { $expFull++; $pend85 = null; }
        else { if ($pend85 !== null) $expSingle++; $pend85 = (int)$r85['counted']; }
    }
    if ($pend85 !== null) $expSingle++;
    $ok85 = $full === $expFull && $single === $expSingle && $mirrors === $full && $keepN === $storedRows && $gAfter === $gBefore
         && ($expFull * 2 + $expSingle) === $halves
         && strpos((string)file_get_contents($PROJ . '/pages/grades.php'), "explode(',', (string)\$_POST['row_delete'])") !== false;
    $why85 = "halves=$halves full=$full/$expFull single=$single/$expSingle mirrors=$mirrors keep=$keepN stored=$storedRows grade=$gBefore→$gAfter";
}
check('عرض «درجة كل سنتين»: النصفان بسطر واحد عرضاً + صح لكل نصف مخزّن (مرآة) + الحذف المزدوج + الدرجة لا تتغيّر', $ok85, $why85);

/* =====================================================================
 * 86) 🎁 صفحة المكافآت «مش واضحة» (2026-08-29): الرأس اللاصق كان يغطي خانات نافذة «بند جديد»
 *     (app.js يتخطّى جداول المودال) + النافذة بخطوات ١/٢/٣ + تعبئة مسبقة بالبنود الحالية للفئة
 *     + تنبيه «تحلّ محلّ نفس النوع» + شرح «كيف بشتغل» أعلى الصفحة + معاينة تجمع النسب.
 * =================================================================== */
$ba86 = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php');
check('صفحة المكافآت: المودال لا يُثبَّت رأسه + خطوات ١/٢/٣ + تعبئة مسبقة + تنبيه الاستبدال + شرح الصفحة + معاينة تجمع النسب',
      strpos((string)file_get_contents($PROJ . '/assets/js/app.js'), "t.closest('.ba-overlay, .modal, [role=\"dialog\"]')") !== false
      && substr_count($ba86, 'class="ba-num"') >= 3
      && strpos($ba86, 'window.baPrefill=function') !== false && strpos($ba86, 'var BA_LINES=') !== false
      && strpos($ba86, 'id="baReplaceNote"') !== false
      && strpos($ba86, 'كيف بشتغل بهالصفحة؟') !== false
      && strpos($ba86, 'byType[type].pct+=val') !== false);

/* =====================================================================
 * 87) 🧍 «مبالغ فردية (لكل واحد)» بصفحة المكافآت (2026-08-29): جدول بأسماء الفئة، خانة لكل نوع،
 *     فاضي = لا تغيير، 0 = إزالة، يُستبدل بند «مبلغ لكل السنة» فقط (النسب والفترات لا تُمسّ).
 * =================================================================== */
$ba87 = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php');
check('المكافآت: زرّ ومودال «مبالغ فردية» + معالج apply_individual يحمي النسب/الفترات ويعيد الحساب',
      strpos($ba87, "action === 'apply_individual'") !== false
      && strpos($ba87, 'id="baModalIndiv"') !== false
      && strpos($ba87, "value_type = ? AND \$perSql") !== false && strpos($ba87, "[\$k . '_pct']") !== false
      && strpos($ba87, 'recalcEmployeeYear($eid, $schoolYear)') !== false
      && strpos($ba87, 'window.baIndivFilter=function') !== false);

check('تسميات أنواع العلاوات موحّدة بكل البرنامج (prime_fixe = الأجر الإضافي، aide = مكافأة ومساعدة) — المحرّر الموحّد بملف الأستاذ (p1 2026-08-29 / 2026-09-11)',
      strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'مكافأة ثابتة') === false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "'➕ Supplément de salaire', 'الأجر الإضافي'") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "'💰 Prime & aide', 'مكافأة ومساعدة'") !== false);

/* =====================================================================
 * 🎁 (2026-09-11) «صفحة المكافآت والمساعدات بدها ترتيب لأن مش واضح كيفية استعمالها»:
 *     مكان واحد للمكافآت/المساعدات/النقل = تبويب «المكافآت» بملف الأستاذ (قسم لكل نوع + شرح + معاينة حيّة)؛
 *     التبويب المالي بلا محرّر بنود؛ bonuses.php تحويل فقط؛ لا زرّ «إدارة المكافآت» مكرّر.
 * =================================================================== */
$emp0911 = (string)file_get_contents($PROJ . '/pages/employees.php');
$bn0911  = (string)file_get_contents($PROJ . '/pages/bonuses.php');
check('المكافآت 2026-09-11: تبويب واحد بأقسام لكل نوع (prime_fixe/aide/transport_complement + النقل اليومي) + شرح «كيف بتشتغل» + معاينة الشهري',
      strpos($emp0911, "\$bonusSection('prime_fixe'") !== false
      && strpos($emp0911, "\$bonusSection('aide_complementaire'") !== false
      && strpos($emp0911, 'id="bnBody_transport_complement"') !== false
      && strpos($emp0911, 'id="tLinesBody"') !== false
      && strpos($emp0911, 'كيف بتشتغل هالصفحة') !== false
      && strpos($emp0911, 'class="bnPrev"') !== false
      && strpos($emp0911, 'data-tab="bonuses">🎁 Primes, aides & transport / المكافآت والمساعدات والنقل') !== false);
$finTab0911 = substr($emp0911, strpos($emp0911, 'data-tab-content="finance"'), strpos($emp0911, 'Bonuses Tab') - strpos($emp0911, 'data-tab-content="finance"'));
check('المكافآت 2026-09-11: لا تكرار — التبويب المالي بلا محرّر بنود/نقل يومي، لا زرّ «إدارة المكافآت»، bonuses.php تحويل لتبويب المكافآت',
      strpos($finTab0911, 'bonus_editor') === false && strpos($finTab0911, 'tLinesBody') === false
      && strpos($emp0911, 'Gérer les primes') === false && strpos($emp0911, 'pages/bonuses.php?') === false
      && strpos($bn0911, '&tab=bonuses') !== false && strpos($bn0911, 'INSERT INTO') === false);
$ba0911 = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php');
check('المكافآت الجماعية 2026-09-11/12: بطاقة «طبّق على الكل دفعة وحدة» صارت سطراً لكل فئة (ملاك/متعاقدين/موظفين كل واحدة نسبتها ٪ أو مبلغها — «نسبة تانية وتالتة») × شو × من←إلى، معالج apply_percats على نفس $applyLinesTo + الوضع الحالي + مثال حيّ بمعادلة المحرّك + تبديل الجلسة تلقائياً + رسالة تقول ماذا طُبّق',
      strpos($ba0911, 'id="baOnePct"') !== false && strpos($ba0911, 'id="baOnePctForm"') !== false
      && strpos($ba0911, 'name="pc[<?= $ck ?>][vtype]" value="percent"') !== false && strpos($ba0911, 'name="pc[<?= $ck ?>][vtype]" value="amount"') !== false
      && strpos($ba0911, 'name="pc[<?= $ck ?>][value]"') !== false && strpos($ba0911, 'name="pc[<?= $ck ?>][currency]"') !== false && strpos($ba0911, 'name="pc[<?= $ck ?>][on]"') !== false
      && strpos($ba0911, 'id="opCats"') !== false && strpos($ba0911, 'id="opTypes"') !== false && substr_count($ba0911, 'class="opRow" data-cat="<?= $ck ?>"') === 1
      && strpos($ba0911, 'name="ptype" value="prime_fixe"') !== false && strpos($ba0911, 'name="ptype" value="aide_complementaire"') !== false
      && strpos($ba0911, 'name="ptype" value="transport_complement"') !== false
      && strpos($ba0911, "SELECT employee_id, bonus_type, value_type, amount, currency FROM employee_bonuses") !== false
      && strpos($ba0911, "=== 'apply_percats'") !== false && substr_count($ba0911, '$applyLinesTo(') === 2 && strpos($ba0911, "foreach (\$validCats as \$ck) {") !== false
      && strpos($ba0911, "if (empty(\$row['on'])) continue;") !== false && strpos($ba0911, '$applyLinesTo([$ck], [$ln])') !== false
      && strpos($ba0911, "baMonthSel('pc[' . \$ck . '][from]', 10") !== false && strpos($ba0911, "baMonthSel('pc[' . \$ck . '][to]', 9") !== false
      && strpos($ba0911, "'from' => \$row['from'] ?? \$pfrom, 'to' => \$row['to'] ?? \$pto") !== false
      && strpos($ba0911, "baMonthSel('ind_from', 10") !== false && strpos($ba0911, "\$perSql = \$iFull ? \$fullYearSql : \"(start_month = \$iFrom AND end_month = \$iTo)\"") !== false
      && strpos($ba0911, 'var usd=Math.floor((base/OFFICIAL)*(pct/100))') !== false
      && strpos($ba0911, 'var usd=Math.floor((base/OFFICIAL)*(b.pct/100))') !== false
      && strpos($ba0911, "isSuperAdmin()) { \$_SESSION['active_schools'] = \$scopeIds;") !== false
      && strpos($ba0911, '"✅ طُبّق: $desc') !== false
      && strpos($emp0911, 'bulk_allowances.php?sch=') !== false && strpos($emp0911, '#baOnePct') !== false);
$tf0911 = (string)file_get_contents($PROJ . '/pages/teacher_form.php'); $ic0911 = (string)file_get_contents($PROJ . '/pages/info_collect.php');
check('المكافآت 2026-09-11 «بكل البرنامج»: فورم الأستاذ الجديد فيه الأجر الإضافي + مكافأة ومساعدة بخيار (ل.ل / $ / ٪ من الأساس) والإنشاء يترجم ٪ إلى value_type=percent',
      strpos($tf0911, "'new_aide'") !== false && strpos($tf0911, 'value="PCT"') !== false
      && strpos($tf0911, "['new_salary','new_extra','new_extra2','new_aide','new_transport']") !== false // (2026-09-13) + الجزء الثاني بالعملة الأخرى
      && strpos($ic0911, "'new_aide' => 'aide_complementaire'") !== false && strpos($ic0911, "=== 'PCT') ? 'percent' : 'amount'") !== false
      && strpos($tf0911, 'name="new_from"') !== false && strpos($tf0911, 'name="new_to"') !== false
      && strpos($ic0911, "(\$nFull ? 'NULL, NULL' : \"\$nFrom, \$nTo\")") !== false);
/* ⚖️ (2026-09-11 مقارنة كشف عبرا 113/131) قاعدة «الصندوق على الأساس فقط» بتقرير المخالفات — بلا شفاء صامت */
$cp0911 = (string)file_get_contents($PROJ . '/includes/compliance.php');
$eocItems0911 = []; $eocWhy = '';
try {
    $itemsAll0911 = complianceItems($db, '2025-2026');
    foreach ($itemsAll0911 as $it) if ($it['rule'] === 'eoc_base_only') $eocItems0911[] = $it['emp_id'];
    $maria0911 = (int)$db->query("SELECT COUNT(*) FROM employees WHERE id = 1651 AND is_deleted = 0 AND COALESCE(eoc_includes_extra,0) = 0")->fetchColumn();
    $eocWhy = 'items=' . count($eocItems0911) . ' maria_flag0=' . $maria0911;
} catch (Throwable $e) { $eocWhy = $e->getMessage(); }
check('المخالفات: قاعدة eoc_base_only (ملاك بإضافي وصندوقه على الأساس وحده) موجودة + تصحيحها تضوية المفتاح وإعادة الحساب + بلا «موافق على الكل» + تكشف ماريا حليحل 1651 محلياً ما دام مفتاحها مطفأ',
      strpos($cp0911, "'eoc_base_only'  => [") !== false && strpos($cp0911, "case 'eoc_base_only':") !== false
      && strpos($cp0911, "UPDATE employees SET eoc_includes_extra = 1 WHERE id = \$eid") !== false
      && strpos($cp0911, "\$rk !== 'eoc_base_only'") !== false && strpos($cp0911, "\$rule !== 'eoc_base_only'") !== false
      && (empty($maria0911) || in_array(1651, $eocItems0911, true)), $eocWhy);
$oy0911 = (string)file_get_contents($PROJ . '/pages/open_year.php');
check('فتح السنة 2026-09-11 «بكل المدارس ينقل نفس الرواتب مع التدرّج تلقائياً»: خيار «كل المدارس» + حلقة openOne لكل مدرسة + جدول حالة السنة بكل مدرسة + الافتراضي نقل كل شي',
      strpos($oy0911, 'id="oy_all" name="all_schools"') !== false && strpos($oy0911, "\$allSchoolsOpen = !empty(\$_POST['all_schools'])") !== false
      && strpos($oy0911, '$openOne = function (int $schoolId)') !== false && strpos($oy0911, "array_map(fn(\$sc) => (int)\$sc['id'], allSchools())") !== false
      && strpos($oy0911, 'مفتوح لهم') !== false && strpos($oy0911, 'name="add_mode" value="same" checked') !== false && strpos($oy0911, 'name="trans_mode" value="same" checked') !== false);
$fn0911b = (string)file_get_contents($PROJ . '/includes/functions.php');
$prevPY = (string)getSetting('program_school_year', '');
try {
    setSetting('program_school_year', '2030-2031'); $pyA = currentSchoolYear();
    setSetting('program_school_year', '2001-2002'); $pyB = currentSchoolYear();
    setSetting('program_school_year', '');          $pyC = currentSchoolYear();
} finally { setSetting('program_school_year', $prevPY); }
check('السنة الحالية للبرنامج 2026-09-11 «بس نفتح السنة الجديدة تصير كل التقارير والإفادات عليها»: currentSchoolYear يتبع program_school_year (لا أقدم من التقويم)، فتح السنة يثبّتها، تفريغها يرجّع الافتراضي، بطاقة تبديل يدوي',
      function_exists('calendarSchoolYear') && $pyA === '2030-2031' && $pyB === calendarSchoolYear() && $pyC === calendarSchoolYear()
      && strpos($oy0911, "setSetting('program_school_year', \$newYear)") !== false
      && strpos($oy0911, "=== 'set_program_year'") !== false && strpos($oy0911, "=== \$clrYear) setSetting('program_school_year', '')") !== false
      && strpos($oy0911, "\$clrYear <= calendarSchoolYear()") !== false && strpos($oy0911, "\$yr <= calendarSchoolYear()") !== false);
$cp0911b = (string)file_get_contents($PROJ . '/includes/compliance.php'); $ba0911b = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php'); $emp0911b = (string)file_get_contents($PROJ . '/pages/employees.php');
check('النقل مبلغ دائماً 2026-09-11 (عبرا: «نقل شهري 85٪» ضاعف المستحق): قاعدة transport_pct بالتقرير + تصحيحها إطفاء البند + البطاقة الجماعية وapply_periods وملف الأستاذ يمنعون النسبة على النقل',
      isset(complianceRules()['transport_pct']) && strpos($cp0911b, "case 'transport_pct':") !== false
      && strpos($ba0911b, "if (\$type === 'transport_complement') \$vt = 'amount';") !== false && strpos($ba0911b, 'function guardTransport()') !== false
      && strpos($emp0911b, "if (\$bt === 'transport_complement') \$vt = 'amount';") !== false && strpos($emp0911b, 'مبلغ (النقل لا يكون نسبة)') !== false);
check('المكافآت 2026-09-11: كل سطر يرسل الحقول الستّة متراصفة (type مخفي بالخلية الأولى) + المحرّر يعرض الفعّال فقط',
      substr_count($emp0911, 'name="bonus_rows[type][]"') === 1
      && strpos($emp0911, '<td><input type="hidden" name="bonus_rows[type][]"') !== false
      && preg_match('/name="bonus_rows\[(currency|vtype|from|to|value)\]\[\]"/', $emp0911) === 1);

/* =====================================================================
 * 88) 🧹 تنظيف عام (2026-08-29، «ما تخلّي شي ما إلو معنى»): الدرجات بلا ترقية يدوية/تلقائية قديمة
 *     (+1) وقواعدها محدّثة والراتب على الدرجة الكاملة؛ الإعدادات بلا بطاقة فارغة؛ النسخ الاحتياطي
 *     يطوي الجداول الداخلية؛ شريط التصدير مخفي على صفحات الإعدادات؛ cols-5 موجود.
 * =================================================================== */
$gr88 = (string)file_get_contents($PROJ . '/pages/grades.php');
check('تنظيف: صفحة الدرجات بلا «Promotion (+1)»/«ترقية تلقائية» + قواعد محدّثة (نص درجة/سنة) + السلسلة على FLOOR(الدرجة)',
      strpos($gr88, 'auto_promote') === false && strpos($gr88, 'Promotion (+1)') === false
      && strpos($gr88, 'نص درجة كل سنة') !== false
      && strpos($gr88, 'FLOOR(e.current_grade) = sc.grade') !== false
      && strpos($gr88, "(int)floor((float)\$emp['current_grade'])") !== false);
check('تنظيف: الإعدادات بلا بطاقة «معلومات المدارس» + النسخ الاحتياطي يطوي الجداول الداخلية + شريط التصدير مخفي على صفحات الإعدادات + cols-5',
      strpos((string)file_get_contents($PROJ . '/pages/settings.php'), 'Informations des écoles') === false
      && strpos((string)file_get_contents($PROJ . '/pages/backup.php'), '$internalTables') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), "\$noToolbarPages") !== false
      && strpos((string)file_get_contents($PROJ . '/assets/css/app.css'), '.form-row.cols-5') !== false);

/* =====================================================================
 * 89) 👻 «إضافي عالق بلا سطر» (كلاديس الصباغ 2026-08-29): حذف بند العلاوة = إطفاء (is_active=0)
 *     لا محو، فيُصفَّر من الأشهر المخزّنة عبر تركيب العلاوات؛ المحرّرات تعرض الفعّال فقط؛
 *     تجربة حيّة على موظفة بلا إعداد: إضافة سطر → يظهر، إطفاؤه → يصير 0 والصافي يرجع.
 * =================================================================== */
$noHardDel89 = true;
foreach (['pages/bulk_allowances.php', 'pages/employees.php'] as $f89) {
    $src89 = (string)file_get_contents($PROJ . '/' . $f89);
    $src89 = preg_replace('/DELETE FROM employee_bonuses WHERE employee_id = \? AND school_year = \? AND is_active = 0/', '', $src89); // تنظيف المطفأ المتراكم مسموح
    if (strpos($src89, 'DELETE FROM employee_bonuses') !== false) $noHardDel89 = false;
}
$live89 = false; $why89 = '';
$gl89 = $db->query("SELECT e.id FROM employees e JOIN schools s ON s.id=e.school_id WHERE s.name_ar LIKE 'مدرسة سيدة البشارة%' AND e.is_deleted=0
    AND e.employee_type='employe' AND COALESCE(e.base_salary_usd,0)=0 AND COALESCE(e.contract_salary_lbp,0)=0 AND e.first_name_ar LIKE 'كلاديس%' LIMIT 1")->fetchColumn();
if ($gl89) {
    $gl89 = (int)$gl89;
    $b0 = $db->query("SELECT prime_fixe_lbp, net_salary_lbp FROM monthly_salaries WHERE employee_id=$gl89 AND year=2025 AND month=11")->fetch(PDO::FETCH_ASSOC);
    $db->exec("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES ($gl89, 'prime_fixe', 98, '2025-2026', 1000000, 'amount', 'LBP', NULL, NULL, 1)");
    $bid89 = (int)$db->lastInsertId();
    try {
        recalcEmployeeYear($gl89, '2025-2026');
        $b1 = $db->query("SELECT prime_fixe_lbp, net_salary_lbp FROM monthly_salaries WHERE employee_id=$gl89 AND year=2025 AND month=11")->fetch(PDO::FETCH_ASSOC);
        $db->exec("UPDATE employee_bonuses SET is_active=0 WHERE id=$bid89");
        recalcEmployeeYear($gl89, '2025-2026');
        $b2 = $db->query("SELECT prime_fixe_lbp, net_salary_lbp FROM monthly_salaries WHERE employee_id=$gl89 AND year=2025 AND month=11")->fetch(PDO::FETCH_ASSOC);
        $live89 = $b0 && (int)$b1['prime_fixe_lbp'] === (int)$b0['prime_fixe_lbp'] + 1000000 && (int)$b2['prime_fixe_lbp'] === (int)$b0['prime_fixe_lbp'] && (int)$b2['net_salary_lbp'] === (int)$b0['net_salary_lbp'];
        $why89 = json_encode([$b0, $b1, $b2]);
    } finally {
        $db->exec("DELETE FROM employee_bonuses WHERE id=$bid89");
        recalcEmployeeYear($gl89, '2025-2026');
    }
}
check('حذف بند العلاوة = إطفاء لا محو (bonuses/bulk/employees) + المحرّرات تعرض الفعّال فقط + الشفاء موصول',
      $noHardDel89
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "WHERE employee_id = ? AND is_active = 1 AND (school_year = ? OR school_year IS NULL)") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healGladisGhostPrime20260829();') !== false);
check('تجربة حيّة (موظفة بلا إعداد): إضافة سطر إضافي يظهر بالشهر، وإطفاؤه يصفّره ويرجّع الصافي', $live89, $why89);

/* =====================================================================
 * 90) 🚑 الفحص الرسمي + استرجاع المصفَّر (2026-08-29): قواعد الفحص (20) صفر أخطاء حساب محلياً
 *     (المعلوماتية مستثناة)، شفاء التكميل لا يستدعي المحرّك الكامل مباشرةً، الشفاء موصول،
 *     واللقطة موجودة وبطاقة الفحص بصفحة الصحة.
 * =================================================================== */
require_once $PROJ . '/includes/data_audit.php';
$aud90 = dataAuditRules($db, '2025-2026');
$info90 = ['active_nomonths', 'rate_missing', 'no_diploma', 'dupes', 'left_rows', 'orphan_rows', 'row_rate0'];
$bad90 = []; foreach ($aud90 as $a) if ($a['n'] > 0 && !in_array($a['key'], $info90, true)) $bad90[] = $a['key'] . '=' . $a['n'];
check('الفحص الرسمي (21 قاعدة، منها tax_stale الضريبة ≠ القانون الحيّ منذ 2026-09-10) على النسخة المحلية 2025-2026: صفر أخطاء حساب (المعلوماتية للمراجعة مستثناة)', !$bad90, implode(' ', $bad90));
check('شفاء تكميل العلاوات لا يستدعي المحرّك الكامل مباشرةً (سبب تصفير الأساس أونلاين) + شفاء الاسترجاع موصول + اللقطة + بطاقة الفحص',
      strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "(new PayrollCalculator(\$eid, (int)\$mrow['month'], (int)\$mrow['year']))->calculateAndSave()") === false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healRestoreZeroedRows20260829();') !== false
      && is_file($PROJ . '/tools/data/rows_snapshot_20260829.json')
      && strpos((string)file_get_contents($PROJ . '/pages/health_check.php'), 'id="officialAudit"') !== false);

check('التارك: أشهر ما بعد الترك ضمن السنة لا تُحذف تلقائياً (قد تكون بقراره — حنان تحومي) + شفاء اليتامى موصول',
      strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "(year * 100 + month) > ?") === false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healPostDepartureOrphans20260829();') !== false);
$aud91 = dataAuditRules($db, '2025-2026'); $or91 = 0;
foreach ($aud91 as $a) { if ($a['key'] === 'orphan_rows') $or91 = $a['n']; }
check('بعد الشفاء محلياً: لا صفوف رواتب يتيمة لموظفين محذوفين (2025-2026)', $or91 === 0, "orphan=$or91");

/* =====================================================================
 * 92) 🎓 شهادات من برنامجه القديم (2026-08-29): الستّة يطابقون كشفه بالمليم بعد الشفاء
 *     (نادين 3,445,000/133م · ميرنا 3,215,000/124م · ايليز 2,625,000/101م · هبه 2,545,000/98م ·
 *     غريس 2,465,000/95م · مي خاطر 2,225,000/79م) + الشفاء موصول.
 * =================================================================== */
$exp92 = [155 => [3445000, 133000000], 400 => [3215000, 124000000], 585 => [2625000, 101000000], 697 => [2545000, 98000000], 942 => [2465000, 95000000], 1056 => [2225000, 79000000]];
$ok92 = true; $why92 = [];
foreach ($exp92 as $id92 => [$b92, $p92]) {
    $r92 = $db->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp p FROM monthly_salaries WHERE employee_id=$id92 AND year=2025 AND month=11")->fetch(PDO::FETCH_ASSOC);
    if (!$r92 || (int)$r92['b'] !== $b92 || (int)$r92['p'] !== $p92) { $ok92 = false; $why92[] = "$id92: " . json_encode($r92); }
}
check('شهادات الملاك من برنامجه القديم: الستّة يطابقون كشفه بالمليم (الأساس والإضافي بتشرين الثاني) + الشفاء موصول',
      $ok92 && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healDiplomaFromOldProgram20260829();') !== false, implode(' ', $why92));

check('النياح ٣: شفاء اسبر منصور/ندى باصيل/زويا سمعان موصول + محلياً لا رواتب لهم بالنياح 2025-2026',
      strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healNiyahCw3_20260829();') !== false
      && (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id JOIN schools s ON s.id=e.school_id
            WHERE s.name_ar LIKE 'مدرسة سيدة النياح%' AND ms.school_year='2025-2026' AND e.is_deleted=0
              AND ((e.first_name_ar='اسبر' AND e.last_name_ar LIKE '%منصور%') OR (e.first_name_ar='ندى' AND e.last_name_ar LIKE '%باصيل%') OR (e.first_name_ar='زويا' AND e.last_name_ar LIKE '%سمعان%'))")->fetchColumn() === 0);

/* =====================================================================
 * 93) 🧾 «وين عامود الدرجة اللي بدو يروح على صندوق التعويضات» (p1 2026-08-29): عمود «درجة / نصف
 *     راتب (إلى الصندوق)» بكشف الرواتب الشهري الرسمي + الكشف الشهري بالتقارير + تصدير Excel/Word.
 * =================================================================== */
$sa93 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], ['extra','aide','transport'], [5], 'lbp', '2025-2026');
$ms93 = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], ['extra','aide','transport'], [5], 'lbp', '2025-2026');
check('عمود «درجة / نصف راتب → صندوق التعويضات» موجود بكشف الرواتب الشهري الرسمي وبالكشف الشهري بالتقارير وبالتصدير',
      strpos($sa93, 'إلى صندوق التعويضات') !== false && strpos($sa93, '95,000') !== false
      && strpos($ms93, 'درجة / نصف راتب') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/reports_export.php'), "'درجة / نصف راتب (إلى الصندوق)'") !== false);

/* =====================================================================
 * 94) 🧹 تنظيف المكرّرين (أمره 2026-09-02 «المكرر بهيدي السنة بنفس المدرسة ينشال»): الشفاء موصول،
 *     بعد تشغيله محلياً لا يبقى مكرّرون فاعلون بنفس المدرسة إلا المستثنيين بقراره (جوزيف ابي عيد/جان عاد)،
 *     المستثنون لم يُمَسّوا، ولا صفوف رواتب معلّقة بملفات شالها الشفاء (كلها اندمجت تحت الملف الباقي).
 * =================================================================== */
healDedup2526_20260902();
healDedupFill20260902();
$dup94 = (int)$db->query("SELECT COUNT(*) FROM employees e1 JOIN employees e2 ON e2.id>e1.id AND e2.school_id=e1.school_id
      AND e2.first_name_ar=e1.first_name_ar AND e2.last_name_ar=e1.last_name_ar AND COALESCE(e2.father_name_ar,'')=COALESCE(e1.father_name_ar,'')
    WHERE e1.is_deleted=0 AND e2.is_deleted=0 AND e1.status='actif' AND e2.status='actif'
      AND NOT (e1.first_name_ar='جوزيف' AND e1.last_name_ar LIKE '%ابي عيد%')
      AND NOT (e1.first_name_ar='جان' AND e1.last_name_ar LIKE '%عاد%')")->fetchColumn();
$kept94 = 0; $orph94 = 0;
try {
    $kept94 = (int)$db->query("SELECT COUNT(*) FROM _emp_bk_dedup20260902 WHERE (first_name_ar='جوزيف' AND last_name_ar LIKE '%ابي عيد%') OR (first_name_ar='جان' AND last_name_ar LIKE '%عاد%')")->fetchColumn();
    $orph94 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN _emp_bk_dedup20260902 bk ON bk.id=ms.employee_id JOIN employees e ON e.id=bk.id AND e.is_deleted=1")->fetchColumn();
} catch (Throwable $e94) { /* لا نسخة = لم يشل أحداً محلياً */ }
check('المكرّرون: الشفاء موصول + أداة المعاينة موجودة + الحذف حذف ناعم لا محو',
      strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healDedup2526_20260902();') !== false
      && is_file($PROJ . '/tools/dedup_dryrun.php')
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "UPDATE employees SET is_deleted=1 WHERE id=\$lid") !== false);
check('بعد الشفاء محلياً: صفر مكرّرين فاعلين بنفس المدرسة (عدا المستثنيين بقراره) + المستثنون لم يُمَسّوا + لا رواتب معلّقة بملف مُزال (اندمجت كلها)',
      $dup94 === 0 && $kept94 === 0 && $orph94 === 0, "dup=$dup94 kept=$kept94 orph=$orph94");
check('شفاء المكرّرين نجح (done لا err) + تكملة الدمج (استكمال خانات الهوية من نسخة المُزال) موصولة وناجحة',
      strpos((string)getSetting('heal_dedup_2526_20260902', ''), 'done') === 0
      && strpos((string)getSetting('heal_dedup_fill_20260902', ''), 'done') === 0
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healDedupFill20260902();') !== false,
      (string)getSetting('heal_dedup_2526_20260902', '') . ' // ' . (string)getSetting('heal_dedup_fill_20260902', ''));

/* =====================================================================
 * 95) 🕐 تناقص ساعات التدريس (مرسوم 2601/2018 — طلبه 2026-09-03): جداول المرسوم الثلاثة صحيحة
 *     رقماً برقم، سلّم الاستدلال (مرحلة → صفوف → افتراضي المدرسة)، الصفحة والمساج موصولان،
 *     والميزة عرض فقط (لا كتابة بقاعدة البيانات إطلاقاً — «انتبه ما تخرب شي»).
 * =================================================================== */
require_once $PROJ . '/includes/hours_reduction.php';
$mk95 = fn($malak, $niv = '', $cls = '') => ['employee_type' => 'enseignant_titulaire', 'titularization_date' => $malak, 'niveau_scolaire' => $niv, 'classes_taught' => $cls];
$a95 = hoursReductionFor($mk95('2009-10-01', 'secondaire'), '2025-2026');                    // ثانوي سنة 17 → 19 (−1)
$b95 = hoursReductionFor($mk95('2010-10-01', 'secondaire'), '2025-2026');                    // سنة 16 → لا تناقص، يبدأ 2026-2027
$c95 = hoursReductionFor($mk95('1994-10-01', 'intermediaire'), '2025-2026');                 // حلقة 3 سنة 32 → 18 (−6)
$d95 = hoursReductionFor($mk95('1990-10-01', 'primaire'), '2025-2026');                      // روضة/حلقتان سنة 36 → 19 (−8)
$e95 = hoursReductionFor($mk95('1997-10-01', 'maternelle'), '2025-2026');                    // سنة 29 → 23 (−4)
$f95 = hoursReductionFor($mk95('1990-10-01', '', '9,10'), '2025-2026');                      // من الصفوف: EB7 → جدول 2
$g95 = hoursReductionFor($mk95('2000-10-01'), '2025-2026', 'مدرسة ثانوية السيدة');           // افتراضي ثانوية → جدول 1 assumed
$h95 = hoursReductionFor(['employee_type' => 'enseignant_contractuel', 'titularization_date' => '1990-10-01'], '2025-2026'); // غير ملاك → null
check('جداول المرسوم 2601/2018: ثانوي 17→19(−1) · عتبة 16 سنة لم تُبلغ → 0 ويبدأ بسنته · حلقة3 32→18(−6) · روضة/حلقتان 36→19(−8) · 29→23(−4)',
      $a95 && (int)$a95['lawHours'] === 19 && (int)$a95['reduction'] === 1 && (int)$a95['serviceYear'] === 17
      && $b95 && (int)$b95['reduction'] === 0 && $b95['startSy'] === '2026-2027'
      && $c95 && (int)$c95['lawHours'] === 18 && (int)$c95['reduction'] === 6
      && $d95 && (int)$d95['lawHours'] === 19 && (int)$d95['reduction'] === 8
      && $e95 && (int)$e95['lawHours'] === 23 && (int)$e95['reduction'] === 4,
      json_encode([$a95, $b95, $c95, $d95, $e95]));
check('سلّم الاستدلال: الصفوف 9,10 → جدول 2 · افتراضي «ثانوية» → جدول 1 موسوم افتراضياً · غير الملاك لا يشمله القانون',
      $f95 && (int)$f95['table'] === 2 && !$f95['assumed']
      && $g95 && (int)$g95['table'] === 1 && $g95['assumed']
      && $h95 === null);
$hrPage95 = renderPage('pages/hours_reduction.php', [], ['extra','aide','transport'], [], 'lbp', '2025-2026');
$hrSrc95 = (string)file_get_contents($PROJ . '/includes/hours_reduction.php') . (string)file_get_contents($PROJ . '/pages/hours_reduction.php');
check('صفحة تناقص الساعات تعرض المرسوم والمستحقّين بكل مدرسة + الرابط بالقائمة + مساج ملف الأستاذ موصول',
      strpos($hrPage95, '2601') !== false && strpos($hrPage95, 'تناقص') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'pages/hours_reduction.php') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'hoursReductionMsg') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "require_once __DIR__ . '/../includes/hours_reduction.php'") !== false);
// 🔔 (2026-09-03) الكتابة الوحيدة المسموحة = تسجيل ساعات الملاك/التناقص **بإذنه** داخل handleHoursReductionPost
//    (CSRF + canEdit + نطاق المدرسة) + التركيب الذاتي للأعمدة؛ لا INSERT/DELETE ولا لمس أي راتب.
$hrInc95 = (string)file_get_contents($PROJ . '/includes/hours_reduction.php');
$hrPg95  = (string)file_get_contents($PROJ . '/pages/hours_reduction.php');
$hrHandlerPos = strpos($hrInc95, 'function handleHoursReductionPost');
$hrBeforeHandler = substr($hrInc95, 0, (int)$hrHandlerPos);
check('الكتابة الوحيدة = تسجيل التناقص بإذنه: UPDATE فقط داخل handleHoursReductionPost (requireCsrf + canEdit + schoolScopeSql)، لا INSERT/DELETE، الصفحة نفسها بلا كتابة، ولا لمس للرواتب',
      $hrHandlerPos !== false
      && stripos($hrBeforeHandler, 'UPDATE ') === false && stripos($hrInc95, 'INSERT ') === false && stripos($hrInc95, 'DELETE ') === false
      && substr_count($hrInc95, 'UPDATE employees SET') === 2
      && strpos($hrInc95, 'requireCsrf();') !== false && strpos($hrInc95, 'if (!canEdit())') !== false
      && strpos($hrInc95, "schoolScopeSql('e.school_id'));") !== false
      && stripos($hrInc95, 'monthly_salaries') === false && stripos($hrInc95, 'recalcEmployeeYear') === false && stripos($hrInc95, 'calculateAndSave') === false
      && stripos($hrPg95, 'UPDATE ') === false && stripos($hrPg95, 'INSERT ') === false && stripos($hrPg95, '->exec(') === false);
// قاعدة «قرار مطلوب» (دالة صافية): يظهر فقط من صار عنده تناقص جديد/أكبر ولم يُسجَّل بملفه، ويختفي بعد الموافقة أو «لاحقاً» لهذه السنة فقط
$hrE95 = ['employee_type' => 'enseignant_titulaire', 'titularization_date' => '1990-10-01', 'niveau_scolaire' => 'primaire', 'hours_per_week' => 27, 'hours_reduction' => 0];
$hrR95 = hoursReductionFor($hrE95, '2025-2026');                                                   // سنة 36 → 19 (−8)
$hrApplied95 = $hrE95 + []; $hrApplied95['hours_per_week'] = 19; $hrApplied95['hours_reduction'] = 8;   // بعد الموافقة
$hrLater95 = $hrE95; $hrLater95['hours_reduction_later_sy'] = '2025-2026';                          // «لاحقاً» لهذه السنة
$hrNext95 = hoursReductionFor($hrApplied95, '2026-2027');                                           // سنة 37 → 19 (نفس الساعات) → لا مساج
$hrStep95 = hoursReductionFor(['employee_type' => 'enseignant_titulaire', 'titularization_date' => '2002-10-01', 'niveau_scolaire' => 'primaire', 'hours_per_week' => 26, 'hours_reduction' => 1], '2026-2027'); // سنة 25 → 25 (−2): تناقص أكبر → مساج
$hrNone95 = hoursReductionFor(['employee_type' => 'enseignant_titulaire', 'titularization_date' => '2010-10-01', 'niveau_scolaire' => 'primaire', 'hours_per_week' => 18, 'hours_reduction' => 0], '2025-2026'); // سنة 16 → لا تناقص → لا مساج
check('قاعدة «قرار مطلوب»: جديد غير مسجّل → مساج · بعد الموافقة (19/8) → لا · «لاحقاً» لهذه السنة → لا (ويرجع السنة الجاية) · السنة الجاية بنفس الساعات → لا · صعود درجة تناقص (−1→−2) → مساج · بلا تناقص → لا',
      hoursReductionNeedsDecision($hrE95, $hrR95, '2025-2026') === true
      && hoursReductionNeedsDecision($hrApplied95, $hrR95, '2025-2026') === false
      && hoursReductionNeedsDecision($hrLater95, $hrR95, '2025-2026') === false
      && hoursReductionNeedsDecision($hrLater95, $hrR95, '2026-2027') === true
      && hoursReductionNeedsDecision($hrApplied95, $hrNext95, '2026-2027') === false
      && hoursReductionNeedsDecision(['hours_per_week' => 26, 'hours_reduction' => 1], $hrStep95, '2026-2027') === true && (int)$hrStep95['reduction'] === 2
      && hoursReductionNeedsDecision(['hours_per_week' => 18, 'hours_reduction' => 0], $hrNone95, '2025-2026') === false);
check('مساج «قرار مطلوب» بكل مدرسة موصول: لوحة القيادة + صفحة التناقص (hr_apply/hr_later + «موافق على الكل بهذه المدرسة») + ملف الأستاذ (زرّا موافق/لاحقاً يرجعان لملفه) + الأعمدة تتركّب ذاتياً',
      strpos((string)file_get_contents($PROJ . '/index.php'), 'handleHoursReductionPost($db') !== false
      && strpos((string)file_get_contents($PROJ . '/index.php'), 'renderHoursReductionPending(') !== false
      && strpos($hrPg95, 'handleHoursReductionPost($db') !== false && strpos($hrPg95, 'renderHoursReductionPending(') !== false
      && strpos($hrInc95, 'موافق على الكل بهذه المدرسة') !== false && strpos($hrInc95, "value=\"hr_apply\"") !== false && strpos($hrInc95, "value=\"hr_later\"") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'hoursReductionDecisionButtons($employee, $hrMsg') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'hoursReductionNeedsDecision($employee') !== false
      && strpos($hrInc95, "ADD COLUMN hours_reduction DECIMAL(4,1)") !== false && strpos($hrInc95, "ADD COLUMN hours_reduction_sy") !== false
      && strpos($hrPage95, 'المسجّل بملفه') !== false);
// ⏱️ (2026-09-03) ساعات التناقص بملفه + ساعات حضور التناقص = التناقص × 1.5 («4 ساعات تناقص بيصير حضور التناقص 6») بملفه وببطاقة الرواتب والقسيمة بجانب الساعات الفعلية
$hrEmpSrc95 = (string)file_get_contents($PROJ . '/pages/employees.php');
check('ساعات التناقص خانة بملف الأستاذ (للداخلين بالملاك فقط) + حضور التناقص ×1.5 (4→6، 3→4.5، 0→بلا نص) + معامل بالإعدادات + النص بقسيمة الراتب الشهرية وبخانة الساعات نفسها بالبطاقة السنوية (بلا خانة/صف جديد)',
      abs(hoursReductionPresence(4) - 6) < 0.01 && abs(hoursReductionPresence(3) - 4.5) < 0.01 && abs(hoursReductionPresence(1) - 1.5) < 0.01
      && hoursReductionSlipText(['hours_reduction' => 4]) === 'تناقص 4 س — حضور التناقص 6 س' && hoursReductionSlipText(['hours_reduction' => 0]) === ''
      && strpos($hrEmpSrc95, 'name="hours_reduction"') !== false && strpos($hrEmpSrc95, "'hours_reduction' => (\$empType === 'enseignant_titulaire')") !== false
      && strpos($hrEmpSrc95, 'hoursReductionEnsureColumns($db); // 🕐 عمود') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/settings.php'), "'hours_reduction_presence_factor'") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/monthly_payroll.php'), 'hoursReductionSlipText($emp') !== false
      // 🔒 البطاقة السنوية: بطلبه الصريح («بدي التناقص يطلع ببطاقة الأستاذ والحضور كمان بدون ما تغير بحجم البطاقة») — بنفس خانة الساعات فقط، بلا أي خانة/صف جديد
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), '<span class="val" dir="ltr" style="white-space:nowrap;unicode-bidi:isolate">') !== false // سطر واحد LTR (2026-09-04: الخط الأعرض كان يلفّه ويلخبطه)
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "j · Réduction <?= e(\$meta['hours_red']) ?> h = Présence <?= e(\$meta['hours_pres']) ?> h</span></td>") !== false
      && substr_count((string)file_get_contents($PROJ . '/pages/annual_slip.php'), 'Heures / jours par semaine — الساعات / الأيام أسبوعياً</span>') === 2 // نفس العنوان بالحالتين (2026-09-04: العنوان الطويل كان يلفّ سطرين فيطول الصف ويدفع المجموع لصفحة ثانية)
      && substr_count((string)file_get_contents($PROJ . '/pages/annual_slip.php'), 'hours_red') === 2
      // 🧮 نسبة الإضافي المعطاة له بالبطاقة (طلبه 2026-09-03) بخانة الدرجة نفسها: «38 · 45 %» — الدالة employeeExtraPercentForYear
      && function_exists('employeeExtraPercentForYear') && employeeExtraPercentForYear($db, -1, '2025-2026') === '' && employeeExtraPercentForYear($db, 1, 'all') === ''
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "الأجر الإضافي<?= (\$meta['extra_pct'] ?? '') !== '' ? '<br><span dir=\"ltr\">' . e(\$meta['extra_pct']) . ' %</span>' . ((\$meta['new_rates'] ?? '') !== ''") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "<td><span class=\"lbl\">Échelon / الدرجة</span><span class=\"val\"><?= e(\$meta['grade']) ?></span></td>") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), "'extra_pct'   => employeeExtraPercentForYear(\$db, \$emp['id'], \$schoolYear)") !== false
      // 🧮 (طلبه بعدها) قانون النسبة ظاهر بالبطاقة: تحت «الراتب بعد التدرج» قيمته بالدولار القديم (÷1500 داون) + السعر القديم بترويسته، والسعر الجديد (سعر الشهر) تحت النسبة — لأصحاب النسبة فقط
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), "'cur_sal_old_usd' => (int)floor(\$curSal / officialUsdRate())") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), "\$tot['bpe_old_usd'] += (int)floor(\$curSal / officialUsdRate())") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "الراتب بعد التدرج<?= (\$meta['extra_pct'] ?? '') !== '' ? '<br><span dir=\"ltr\">1 $ = ' . e(\$meta['old_rate'])") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "'<br><span dir=\"ltr\">1 $ = ' . e(\$meta['new_rates'])") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "number_format((int)\$r['cur_sal_old_usd'], 0) ?> $</span>") !== false
      // 🧮 («نعم» 2026-09-03) دولار الإضافي لأصحاب النسبة = دولار القانون: 2,305,000÷1500=1,536 ×55٪ = 844 $ (لا 75,000,000÷89,500 = 837)
      && extraPercentLawUsd(55, 2305000) === 844 && extraPercentLawUsd(0, 2305000) === 0
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), "'extra_law_usd'   => \$hasPct ? ((int)(\$s['prime_fixe_usd_law'] ?? 0) > 0 ? (int)extraWageUsd(\$s) : (extraPercentLawUsd(") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), "\$r['extra_law_usd'] !== null ? (int)\$r['extra_law_usd'] : \$usd(\$r['extra_wage'])") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), "'hours_pres'  =>") !== false);

/* =====================================================================
 * 96) 🧮 «طبّق النسبة المئوية اللي بتطلع صح» (أمره 2026-09-03): الملاك الباقون بمبلغ ثابت بمدارس النسبة
 *     → نسبة المدرسة إن كان الفرق فراطات (<5 مليون) وإلا نسبتهم الخاصة الأقرب لرقمهم (خطوة 0.5٪)؛
 *     المدارس بلا نسبة والمحميّتان لا تُمسّ. الشفاء موصول بheader بدفعات + نسخ احتياطية.
 * =================================================================== */
$own96 = choosePercentLawOwn($db, '2025-2026', true);
$fnSrc96 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('«النسبة اللي بتطلع صح»: الدالة الصافية تعمل + كل نسبة مختارة تُخرج رقماً بفرق < 5 مليون عن المخزّن + لا تلمس المدارس بلا نسبة + الشفاء موصول بheader بنسخ احتياطية',
      is_array($own96['plan']) && is_array($own96['skips'])
      && array_reduce($own96['plan'], fn($ok, $p) => $ok && abs($p['law'] - $p['amount']) < 5000000 && $p['pct'] > 0, true)
      && strpos($fnSrc96, "AND NOT (e.first_name_ar LIKE 'ريتا%' AND e.father_name_ar LIKE 'مارون%'") !== false
      && strpos($fnSrc96, "_bk_bonuses_pctown0903") !== false && strpos($fnSrc96, "_ms_bk_pctown0903") !== false
      && strpos($fnSrc96, "recalcEmployeeYear(\$eid, '2025-2026');") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healPercentLawOwn20260903();') !== false,
      'plan=' . count($own96['plan']) . ' skips=' . count($own96['skips']));
check('بعد الشفاء محلياً: لا يبقى ملاك بمبلغ ثابت بمدارس النسبة (الخطّة فارغة) والفلاغ done',
      count($own96['plan']) === 0 && strpos((string)getSetting('heal_percent_law_own_20260903', ''), 'done') === 0,
      (string)getSetting('heal_percent_law_own_20260903', '') . ' | ' . (string)getSetting('heal_percent_law_own_progress', ''));

/* =====================================================================
 * 97) 🧮 «صحّح النسبة بكل التقارير والإفادات» (أمره 2026-09-03): دولار الأجر الإضافي لأصحاب النسبة = دولار القانون
 *     (floor(floor(الأساس÷1500)×النسبة) — 844 $ لا 837) بكل البرنامج عبر مصدر واحد: عمود prime_fixe_usd_law يكتبه
 *     المحرّك + extraWageUsd()/extraWageMoney()/extraWageUsdSql() — ممنوع أي قسمة مباشرة للإضافي على السعر بالتقارير.
 * =================================================================== */
ensurePrimeUsdLawColumn();
$rowLaw97 = ['prime_fixe_lbp' => 75000000, 'extra_lbp' => 0, 'exchange_rate' => 89500, 'prime_fixe_usd_law' => 844];
$rowNo97  = ['prime_fixe_lbp' => 75000000, 'extra_lbp' => 0, 'exchange_rate' => 89500, 'prime_fixe_usd_law' => 0];
$rowMix97 = ['prime_fixe_lbp' => 75000000, 'extra_lbp' => 895000, 'exchange_rate' => 89500, 'prime_fixe_usd_law' => 844];
$_SESSION['display_currency'] = 'usd';
$mUsd97 = strip_tags(extraWageMoney($rowLaw97));
$_SESSION['display_currency'] = 'both';
check('دولار الإضافي الموحّد: بعمود القانون 844 · بلا نسبة 837 (÷السعر داون) · مع ساعات إضافية قديمة 844+10 · عرض «دولار فقط» يطبع 844 · تعبير SQL بنفس القاعدة',
      (int)extraWageUsd($rowLaw97) === 844 && (int)extraWageUsd($rowNo97) === 837 && (int)extraWageUsd($rowMix97) === 854
      && strpos($mUsd97, '844') !== false && strpos($mUsd97, '837') === false
      && strpos(extraWageUsdSql('ms.'), 'ms.prime_fixe_usd_law > 0') !== false && strpos(extraWageUsdSql(''), 'FLOOR((extra_lbp+prime_fixe_lbp)') !== false,
      "usd=" . extraWageUsd($rowLaw97) . " shown=" . $mUsd97);
$allSrc97 = '';
foreach (glob($PROJ . '/pages/*.php') as $f97) $allSrc97 .= file_get_contents($f97);
foreach (glob($PROJ . '/includes/*.php') as $f97) if (basename($f97) !== 'functions.php') $allSrc97 .= file_get_contents($f97);
$calcSrc97 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
check('لا بقايا قسمة مباشرة للإضافي على السعر بأي تقرير/نموذج/إفادة (money(extraWageLbp / lbpToUsd(extraWageLbp / FLOOR((extra+prime)/rate)) + المحرّك يخزّن prime_fixe_usd_law (حفظ + overlay) + الشفاء موصول',
      strpos($allSrc97, 'money(extraWageLbp(') === false && strpos($allSrc97, 'lbpToUsd(extraWageLbp(') === false
      && preg_match('/FLOOR\(\((ms\.)?extra_lbp\s*\+\s*(ms\.)?prime_fixe_lbp\)/', $allSrc97) === 0
      && strpos($allSrc97, '(extra_lbp+prime_fixe_lbp)/NULLIF') === false
      && strpos($calcSrc97, "'prime_fixe_usd_law' => (int)\$this->primeUsdLaw") !== false
      && strpos($calcSrc97, 'prime_fixe_usd_law = VALUES(prime_fixe_usd_law)') !== false
      && strpos($calcSrc97, 'total_due_usd = ?, prime_fixe_usd_law = ?') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healPrimeUsdLaw20260903();') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/attestations.php'), '$extraWUsd = ($sal && (int)($sal[\'prime_fixe_usd_law\'] ?? 0) > 0) ? extraWageUsd($sal) : null;') !== false);
// 🔴 («كل شي مطابق ونفس الأرقام بكل التقارير» 2026-09-03) أي SELECT يجيب أعمدة الإضافي صراحةً لعرضها يجب أن يجيب prime_fixe_usd_law معها
//    (لائحة الدفع كانت 837 $ لأن استعلامها بلا العمود) + المجاميع الفرعية تجمع دولارات القانون لا تقسم الليرة (cnss/tax/eoc summaries)
$bad97 = [];
foreach (['pages/reports.php', 'pages/official_forms.php', 'pages/reports_export.php', 'pages/monthly_payroll.php', 'pages/attestations.php', 'pages/employee_history.php'] as $f97) {
    foreach (explode("\n", (string)file_get_contents($PROJ . '/' . $f97)) as $ln97 => $line97) {
        if (preg_match('/(ms\.)?extra_lbp,\s*(ms\.)?prime_fixe_lbp,\s*(ms\.)?aide_complementaire_lbp/', $line97) && strpos($line97, 'prime_fixe_usd_law') === false) $bad97[] = $f97 . ':' . ($ln97 + 1);
    }
}
$repSrc97 = (string)file_get_contents($PROJ . '/pages/reports.php');
check('كل استعلام عرض يجيب extra_lbp/prime_fixe_lbp صراحةً يجيب prime_fixe_usd_law معه + المجاميع الفرعية بالكشوف تجمع دولارات القانون (لا money($a[extra]) ولا money($teEx)) + الإفادة: الإضافي وحده بدولار القانون والمجاميع ÷ السعر كباقي التقارير',
      !$bad97 && strpos($repSrc97, "money(\$a['extra'], \$repRate)") === false && strpos($repSrc97, "money(\$teEx, \$repRate)") === false
      && strpos($repSrc97, "dualFromUsd(\$a['extra'], \$a['extra_usd'])") !== false && strpos($repSrc97, "dualFromUsd(\$teEx, \$teExU)") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/attestations.php'), "if (\$extraWUsd !== null && \$extraW > 0 && (int)\$lbp === (int)\$extraW) return \$extraWUsd;") !== false,
      $bad97 ? implode(', ', $bad97) : 'ok');
// حيّ: بعد شفاء التعبئة محلياً، كل صف بإضافي نسبةٍ له عمود قانون = floor(floor(bpe/1500)×pct) — عيّنة أصحاب النسبة
$miss97 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employee_bonuses b ON b.employee_id=ms.employee_id AND b.bonus_type='prime_fixe' AND b.is_active=1 AND b.value_type='percent' AND (b.school_year IS NULL OR b.school_year=ms.school_year) AND b.start_month IS NULL
    WHERE ms.prime_fixe_lbp > 0 AND ms.prime_fixe_usd_law = 0")->fetchColumn();
$smp97 = $db->query("SELECT ms.base_plus_echelon_lbp bpe, ms.prime_fixe_usd_law law, b.amount pct FROM monthly_salaries ms JOIN employee_bonuses b ON b.employee_id=ms.employee_id AND b.bonus_type='prime_fixe' AND b.is_active=1 AND b.value_type='percent' AND (b.school_year IS NULL OR b.school_year=ms.school_year) AND b.start_month IS NULL
    WHERE ms.school_year='2025-2026' AND ms.prime_fixe_lbp > 0 ORDER BY ms.employee_id, ms.month LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
$okSmp97 = true; foreach ($smp97 as $r97) if ((int)$r97['law'] !== (int)floor(floor((float)$r97['bpe'] / officialUsdRate()) * (float)$r97['pct'] / 100)) $okSmp97 = false;
check('بعد الشفاء محلياً: لا صف إضافي نسبةٍ بلا عمود قانون + العيّنة تطابق القاعدة floor(floor(bpe/1500)×pct) + الفلاغ done',
      $miss97 === 0 && $smp97 && $okSmp97 && strpos((string)getSetting('heal_prime_usd_law_20260903', ''), 'done') === 0,
      "missing=$miss97 sample=" . count($smp97) . ' flag=' . getSetting('heal_prime_usd_law_20260903', ''));

/* =====================================================================
 * 98) 📜 جدول السلسلة 2017 طبق الجريدة الرسمية (العدد 37 — 21/8/2017، الجدول 17): الدرجات 47/49/50 كانت ناقصة 5,000
 *     (مريم ريشا تشرين 2026 «قيمة درجة 145,000 وما في هالقد بالقانون») — الصح 4,475,000/4,775,000/4,925,000 والقيمة 150,000 للدرجات 46-50.
 * =================================================================== */
$sc98 = [];
foreach ($db->query("SELECT grade, new_salary_2017 s, new_grade_value v FROM salary_scale_2017 WHERE version_id = 1 AND grade BETWEEN 41 AND 52 ORDER BY grade") as $r98) $sc98[(int)$r98['grade']] = [(int)$r98['s'], (int)$r98['v']];
$gaz98 = [41 => 3675000, 42 => 3805000, 43 => 3935000, 44 => 4065000, 45 => 4195000, 46 => 4325000, 47 => 4475000, 48 => 4625000, 49 => 4775000, 50 => 4925000, 51 => 5075000, 52 => 5245000];
$okSc98 = true; foreach ($gaz98 as $g => $sal) if (($sc98[$g][0] ?? 0) !== $sal) $okSc98 = false;
$okStep98 = true; for ($g = 47; $g <= 51; $g++) if (($sc98[$g][1] ?? 0) !== 150000) $okStep98 = false;
check('السلسلة 2017 الدرجات 41-52 = الجريدة الرسمية رقماً برقم (47=4,475,000 · 49=4,775,000 · 50=4,925,000) + قيمة الدرجة 150,000 للدرجات 47-51 (لا 145,000) + الشفاء موصول بالهيدر وتمّ',
      $okSc98 && $okStep98 && ($sc98[52][1] ?? 0) === 170000
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healScale47_20260903();') !== false
      && strpos((string)getSetting('heal_scale47_20260903', ''), 'done') === 0,
      json_encode($sc98) . ' | ' . getSetting('heal_scale47_20260903', ''));

/* =====================================================================
 * 99) 🏛️ موازنة وزارة التربية (MEHE) — «خلّي البرنامج يطلّع هالموازنة لحاله من داتا كل مدرسة
 *     متل نماذج ر5 ور6، إكسل وPDF وبدون أي خطأ» (2026-09-06): صفحة mehe_budget لمدرسة وسنة —
 *     جداول الملاك/المتعاقدين/الإداريين من الرواتب + ملخّص أ/ب/ج/د + إكسل متعدّد الأوراق بصيغ.
 * =================================================================== */
require_once __DIR__ . '/../includes/mehe_budget.php';
ensureMeheBudget20260906();
$mb99 = renderPage('pages/mehe_budget.php', [], [], [2], '', '2025-2026');
check('موازنة الوزارة: الصفحة ترندر لمدرسة واحدة بكل أقسام النموذج (الطلب، معلومات المدرسة، الملاك، المتعاقدون، الإداريون، الهيكل، المعفيون، الصرف، التكاليف، الإيرادات، الملخّص) + نموذج بأزرار تعديل/حفظ لكل سطر + ستايلات الطباعة',
      strpos($mb99, 'أعضاء هيئة التدريس في الملاك') !== false && strpos($mb99, 'أعضاء هيئة التدريس المتعاقدين') !== false
      && strpos($mb99, 'الموظفون الإداريون') !== false && strpos($mb99, 'الهيكل الإداري والتعليمي') !== false
      && strpos($mb99, 'قائمة الطلاب المعفيين') !== false && strpos($mb99, 'تعويضات الصرف للداخلين في الملاك') !== false
      && strpos($mb99, 'تكاليف التشغيل') !== false && strpos($mb99, 'ملخص الميزانية') !== false
      && strpos($mb99, 'class="card no-print mehe-form"') !== false && strpos($mb99, 'meheWireRow') !== false && strpos($mb99, 'landscapePage') !== false
      && strpos($mb99, 'name="mehe_save"') !== false && strpos($mb99, 'FATAL') === false);
// المجاميع من الرواتب = مجاميع monthly_salaries الفعلية (وضع «معدل الأشهر» ⇒ الشهري × الأشهر = مجموع السنة بالضبط)
$mbData99 = meheLoad($db, [2], '2025-2026'); $mbData99['excluded'] = []; $mbData99['base_mode'] = 'avg'; $mbData99['manual_admins'] = []; $mbData99['overrides'] = ['emp' => [], 'months' => [], 'sum' => []];
$mbP99 = mehePayroll($db, [2], '2025-2026', $mbData99);
$q99 = $db->query("SELECT e.employee_type t, SUM(ms.base_plus_echelon_lbp) bpe, SUM(ms.extra_lbp+ms.prime_fixe_lbp) ex, SUM(ms.transport_lbp) tr, SUM(ms.school_cnss_8_lbp) c8, SUM(ms.school_eoc_6_lbp) e6
    FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.school_id=2 AND e.is_deleted=0 AND ms.school_year='2025-2026'
      AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0) GROUP BY e.employee_type")->fetchAll(PDO::FETCH_ASSOC);
$sums99 = []; foreach ($q99 as $r99) $sums99[$r99['t']] = $r99;
$eq99 = fn($a, $b) => abs((float)$a - (float)$b) < 1;
check('موازنة الوزارة: مجاميع الملاك/المتعاقدين/الإداريين (الأساس، الإضافي، النقل، ضمان 8٪، صندوق 6٪) = مجاميع الرواتب الفعلية بالمليم',
      $eq99($mbP99['tit_tot']['base'], $sums99['enseignant_titulaire']['bpe'] ?? -1) && $eq99($mbP99['tit_tot']['extra_ll'], $sums99['enseignant_titulaire']['ex'] ?? -1)
      && $eq99($mbP99['tit_tot']['transport'], $sums99['enseignant_titulaire']['tr'] ?? -1) && $eq99($mbP99['tit_tot']['cnss'], $sums99['enseignant_titulaire']['c8'] ?? -1)
      && $eq99($mbP99['tit_tot']['fund'], $sums99['enseignant_titulaire']['e6'] ?? -1)
      && $eq99($mbP99['con_tot']['base'], $sums99['enseignant_contractuel']['bpe'] ?? -1) && $eq99($mbP99['con_tot']['cnss'], $sums99['enseignant_contractuel']['c8'] ?? -1)
      && $eq99($mbP99['adm_tot']['base'], $sums99['employe']['bpe'] ?? -1) && count($mbP99['tit']) === 20 && count($mbP99['con']) === 8,
      'tit=' . count($mbP99['tit']) . ' con=' . count($mbP99['con']) . ' adm=' . count($mbP99['adm']) . ' base=' . number_format((float)$mbP99['tit_tot']['base']));
// الملخّص: أ+ب = مجموع بنود الفئتين، والإجمالي = أ+ب+ج+د، ومساهمة الصندوق مقرَّبة للألف صعوداً كالوزارة
$mbS99 = meheSummary($mbData99, $mbP99);
$sumA99 = array_sum(array_map(fn($r) => $r[1], $mbS99['A'])); $sumB99 = array_sum(array_map(fn($r) => $r[1], $mbS99['B']));
$sumC99 = array_sum(array_map(fn($r) => $r[1], $mbS99['C'])); $sumD99 = array_sum(array_map(fn($r) => $r[1], $mbS99['D']));
check('موازنة الوزارة: الملخّص — أ+ب وأ+ب+ج والإجمالي متّسقة + صندوق التعويضات بالملخّص = ROUNDUP للألف + الملاك رواتب = مجموع الأساس',
      $eq99($mbS99['abL'], $sumA99 + $sumB99) && $eq99($mbS99['abcL'], $sumA99 + $sumB99 + $sumC99) && $eq99($mbS99['allL'], $sumA99 + $sumB99 + $sumC99 + $sumD99)
      && $eq99($mbS99['A'][0][1], $mbP99['tit_tot']['base']) && $eq99($mbS99['B'][3][1], ceil($mbP99['tit_tot']['fund'] / 1000) * 1000)
      && $eq99($mbS99['B'][0][1], $mbP99['tit_tot']['transport'] + $mbP99['con_tot']['transport'] + $mbP99['adm_tot']['transport']));
// الإكسل: ملف سليم (PK) متعدّد الأوراق (11 ورقة) بصيغ حيّة + الرابط بالقائمة
// (عبر ملف: shell_exec على ويندوز يحوّل سطر الإرجاع فيخرّب الـzip — الكتابة بملف تحفظ البايتات)
$mbX99 = renderPage('pages/mehe_budget.php', ['export' => 'xlsx'], [], [2], '', '2025-2026', tempnam(sys_get_temp_dir(), 'mehe'));
$mbXl99 = tempnam(sys_get_temp_dir(), 'mehe'); file_put_contents($mbXl99, $mbX99);
$mbZ99 = new ZipArchive(); $mbSheets99 = 0; $mbFormulas99 = false;
if ($mbZ99->open($mbXl99) === true) {
    for ($zi = 0; $zi < $mbZ99->numFiles; $zi++) { $zn = $mbZ99->getNameIndex($zi); if (strpos($zn, 'xl/worksheets/sheet') === 0) $mbSheets99++; }
    $mbFormulas99 = strpos((string)$mbZ99->getFromName('xl/worksheets/sheet3.xml'), '<f>SUM(') !== false && strpos((string)$mbZ99->getFromName('xl/workbook.xml'), 'fullCalcOnLoad') !== false;
    $mbZ99->close();
}
@unlink($mbXl99);
// 🏫 مجموعة مدارس مع بعضها: موازنة مجمّعة (عمود «المدرسة» بالجداول + الموظفون من كل النطاق + الحفظ بمفتاح النطاق)
$mbG99 = renderPage('pages/mehe_budget.php', [], [], [2, 3], '', '2025-2026');
$mbPG99 = mehePayroll($db, [2, 3], '2025-2026', ['base_mode' => 'avg', 'excluded' => [], 'manual_admins' => []]);
$mbP3 = mehePayroll($db, [3], '2025-2026', ['base_mode' => 'avg', 'excluded' => [], 'manual_admins' => []]);
check('موازنة الوزارة: مجموعة مدارس (مكسيموس + النجاة) = موازنة مجمّعة — عمود المدرسة بالجداول + عدد الملاك = مجموع المدرستين + مفتاح النطاق "2,3"',
      strpos($mbG99, '<th>المدرسة</th>') !== false && strpos($mbG99, 'موازنة مجمّعة لـ2 مدارس') !== false && strpos($mbG99, 'FATAL') === false
      && count($mbPG99['tit']) === count($mbP99['tit']) + count($mbP3['tit']) && !empty($mbPG99['multi']) && empty($mbP99['multi'])
      && meheScopeKey([3, 2]) === '2,3',
      'tit=' . count($mbPG99['tit']));
// ✏️💾 «بدي قدام كل سطر من صفحات الموازنة الخيار تعديل وحفظ» (2026-09-07): كل سطر بأوراق الموازنة قدّامه تعديل/حفظ
//   (موظفون emp، أشهر الأعمدة months، ملخّص sum، خانات field، نفقات exp، قوائم list) + معالج fetch + JS الصفحة
$mbRows99 = []; foreach (['emp|e', 'months|tit', 'sum|A|0', 'field|serial', 'field|languages|fr', 'field|equipment|', 'field|rooms|', 'exp|phone', 'list|grants|new', 'list|revenues|', 'list|severance|new', 'list|manual_admins|new'] as $mk99) $mbRows99[$mk99] = strpos($mb99, 'data-mrow="' . $mk99) !== false;
check('موازنة الوزارة: زرّا «تعديل/حفظ» قدّام كل سطر بالأوراق نفسها (موظفون، أشهر الأعمدة، ملخّص، خانات المدرسة، اللغات، المعدات، الغرف، النفقات، منح/إيرادات/صرف/إداريون يدويون) + أزرار «+ سطر» + المعالج والـJS + الأزرار مخفية بالطباعة',
      !in_array(false, $mbRows99, true) && substr_count($mb99, 'class="rowctl no-print"') > 150 && strpos($mb99, "fd.append('mehe_row'") !== false
      && strpos($mb99, 'meheAddSheetRow') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/mehe_budget.php'), "isset(\$_POST['mehe_row'])") !== false
      && strpos($mb99, '.no-print{display:none !important}') !== false,
      json_encode(array_keys(array_filter($mbRows99, fn($v) => !$v))));
// المنطق: تعديل موظف يبدّل شهريّه ومجموعه ومجموع العمود؛ أشهر عمود مفروضة تسري على كل من له قيمة؛ سطر ملخّص مفروض ثم رجوع؛ خانة/نفقة/قائمة (إضافة + تعديل + حذف بإفراغ الإلزامي)؛ الرجوع التلقائي يمسح القيم اليدوية
$mbE99 = $mbData99; $mbE0 = $mbP99['tit'][0];
$mbR1 = meheApplyRowEdit($mbE99, ['mehe_row' => 'emp', 'key' => $mbE0['key'], 'f' => ['base' => '10,000,000', 'role' => 'ناظر']]);
$mbPE = mehePayroll($db, [2], '2025-2026', $mbE99); $mbE1 = $mbPE['tit'][0];
meheApplyRowEdit($mbE99, ['mehe_row' => 'months', 'key' => 'tit', 'f' => ['transport' => '10']]);
$mbPM = mehePayroll($db, [2], '2025-2026', $mbE99);
$mbSM0 = meheSummary($mbE99, $mbPM); meheApplyRowEdit($mbE99, ['mehe_row' => 'sum', 'key' => 'A|6', 'f' => ['usd' => '1234']]); $mbSM1 = meheSummary($mbE99, $mbPM);
meheApplyRowEdit($mbE99, ['mehe_row' => 'sum', 'key' => 'A|6', 'reset' => '1']); $mbSM2 = meheSummary($mbE99, $mbPM);
meheApplyRowEdit($mbE99, ['mehe_row' => 'field', 'key' => 'rooms|مسرح', 'f' => ['v' => '2']]); meheApplyRowEdit($mbE99, ['mehe_row' => 'exp', 'key' => 'phone', 'f' => ['ll' => '3,000,000']]);
$mbG0 = count($mbE99['grants']); meheApplyRowEdit($mbE99, ['mehe_row' => 'list', 'key' => 'grants|new', 'f' => ['student' => 'طالب فحص', 'll' => '5000000']]); $mbGi = count($mbE99['grants']) - 1;
meheApplyRowEdit($mbE99, ['mehe_row' => 'list', 'key' => "grants|$mbGi", 'f' => ['cat' => 'بقية الكادر']]); $mbGcat = $mbE99['grants'][$mbGi]['cat'] ?? '';
meheApplyRowEdit($mbE99, ['mehe_row' => 'list', 'key' => "grants|$mbGi", 'f' => ['student' => '']]); $mbG2 = count($mbE99['grants']);
meheApplyRowEdit($mbE99, ['mehe_row' => 'emp', 'key' => $mbE0['key'], 'reset' => '1']); $mbPR = mehePayroll($db, [2], '2025-2026', $mbE99);
$mbBad = meheApplyRowEdit($mbE99, ['mehe_row' => 'field', 'key' => 'bogus', 'f' => ['v' => '1']]);
check('موازنة الوزارة: منطق التعديل من الورقة — موظف (الشهري 10,000,000 × أشهره = المجموع، الدور «ناظر»، مجموع العمود يتحرّك بالفرق) + أشهر النقل 10 لكل العمود + سطر ملخّص مفروض 1234 $ ثم يرجع 0 + غرفة/نفقة + قائمة منح (إضافة/تعديل/حذف) + «↺ تلقائي» يرجّع الأساس + خانة مجهولة مرفوضة',
      $mbR1['ok'] && (float)$mbE1['base'] === 10000000.0 && $mbE1['role'] === 'ناظر' && !empty($mbE1['overridden'])
      && $eq99($mbE1['base_total'], 10000000 * (int)$mbE0['months']['base'])
      && $eq99($mbPE['tit_tot']['base'] - $mbP99['tit_tot']['base'], (10000000 - (float)$mbE0['base']) * (int)$mbE0['months']['base'])
      && (int)$mbPM['tit_months']['transport'] === 10 && $eq99($mbPM['tit_tot']['transport'], array_sum(array_map(fn($x) => (float)$x['transport'] * 10, $mbPM['tit'])))
      && (float)$mbSM1['A'][6][2] === 1234.0 && !empty($mbSM1['A'][6][3]['ov']) && $eq99($mbSM1['abU'], $mbSM0['abU'] + 1234) && (float)$mbSM2['A'][6][2] === 0.0 && empty($mbSM2['A'][6][3]['ov'])
      && (int)$mbE99['rooms']['مسرح'] === 2 && (float)$mbE99['expenses']['phone']['ll'] === 3000000.0
      && $mbGi === $mbG0 && $mbGcat === 'بقية الكادر' && $mbG2 === $mbG0
      && $eq99($mbPR['tit'][0]['base'], $mbE0['base']) && empty($mbPR['tit'][0]['overridden']) && !$mbBad['ok'],
      'base=' . $mbE1['base'] . ' tm=' . $mbPM['tit_months']['transport'] . ' A6usd=' . $mbSM1['A'][6][2] . ' grants=' . $mbGi . '/' . $mbG2);
check('موازنة الوزارة: تصدير إكسل سليم (11 ورقة، صيغ SUM×الأشهر، إعادة حساب عند الفتح) + الرابط بقائمة التقارير',
      strpos($mbX99, 'PK') === 0 && $mbSheets99 === 11 && $mbFormulas99
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'pages/mehe_budget.php') !== false
      && strpos((string)file_get_contents($PROJ . '/index.php'), "['pages/mehe_budget.php','fas fa-landmark'") !== false, // بطاقة بلوحة القيادة (طلبه: «اسم موازنة وزارة التربية مش موجود بالأيقونات»)
      "sheets=$mbSheets99");

/* =====================================================================
 * 100) 🔁 مزامنة بيانات الأونلاين → الكمبيوتر تلقائياً («انا بدي كل شي تلقائي» 2026-09-07):
 *      includes/local_sync.php + sync_local.php + إطلاق خلفي من الهيدر محلياً فقط (لا أونلاين ولا CLI)
 * =================================================================== */
require_once __DIR__ . '/../includes/local_sync.php';
$ls100 = (string)file_get_contents($PROJ . '/includes/local_sync.php');
$hd100 = (string)file_get_contents($PROJ . '/includes/header.php');
$sl100 = renderPage('sync_local.php', [], [], [2], '', '2025-2026');
check('مزامنة الأونلاين→الكمبيوتر: الدوال معرَّفة + معطّلة بـCLI/أونلاين (localSyncEnabled=false) + الهيدر يطلقها خلفياً للمدير محلياً + سطر الحالة بلوحة القيادة + النقطة sync_local.php تردّ JSON «disabled» بـCLI + التحقّق من اكتمال الملف والجداول والأرقام قبل التبديل الذرّي + الحالة بملف لا بجدول settings',
      function_exists('localSyncEnabled') && function_exists('localSyncRun') && function_exists('localSyncDue') && !localSyncEnabled()
      && strpos($hd100, "require_once __DIR__ . '/local_sync.php'") !== false && strpos($hd100, "sync_local.php') ?>, {credentials:'same-origin', keepalive:true}") !== false
      && strpos($hd100, 'localSyncStatusText()') !== false && strpos($hd100, "=== 'dashboard'") !== false
      && strpos($sl100, '"msg":"disabled"') !== false && strpos($sl100, 'FATAL') === false
      && strpos($ls100, 'SET FOREIGN_KEY_CHECKS=1;') !== false && strpos($ls100, '$got !== $ntab') !== false && strpos($ls100, 'RENAME TABLE') !== false
      && strpos($ls100, 'smp_pc_archive_first') !== false && strpos($ls100, 'local_sync_state.json') !== false
      && strpos($ls100, "strpos(\$host, 'localhost') === false") !== false,
      $sl100 === '' ? 'no output' : mb_substr($sl100, 0, 80));

/* =====================================================================
 * 101) 🆕 الأستاذ الجديد بلا أساس بالإعداد يُحسب من ملفه (ريتا بو عاصي/مكسيموس 2026-09-09
 *      «أستاذ جديد ما عم ببين رواتب بالبطاقة»): المصدر الواحد salaryEngineAllowed() بكل الصفحات —
 *      المنقول بأساس مخزّن يبقى محميّاً (تركيب العلاوات فقط)، والجديد بلا أي أساس مخزّن يُحسب
 *      (أساس 0 + الإضافي + النقل + محسوماتها)، وبلا ما يُدفَع لا تُولَّد أشهر أصفار + شفاء ذاتي عند كل فتح
 * =================================================================== */
require_once $PROJ . '/includes/payroll_calculator.php';
$src101 = [];
foreach (['includes/payroll_calculator.php', 'pages/annual_slip.php', 'pages/monthly_payroll.php', 'pages/open_year.php', 'includes/compliance.php', 'includes/header.php', 'includes/functions.php'] as $f101) $src101[$f101] = (string)file_get_contents($PROJ . '/' . $f101);
$raw101 = 0; // لا نسخة يدوية من الشرط القديم خارج المصدر الواحد
foreach (['pages/annual_slip.php', 'pages/monthly_payroll.php', 'pages/open_year.php', 'includes/compliance.php'] as $f101) $raw101 += preg_match_all("/=== 'enseignant_titulaire'\s*\|\|\s*\(float\)\\\$\w+\['base_salary_usd'\] > 0/", $src101[$f101]);
check('الجديد بلا أساس (ريتا بو عاصي): المصدر الواحد salaryEngineAllowed/salaryYearPayable معرَّفان ومستعملان بالبطاقة السنوية (×2) والكشف الشهري وفتح السنة (×2) وتقرير المخالفات + recalcEmployeeYear يمرّ بهما + الشفاء موصول بالهيدر + لا نسخة يدوية من الشرط القديم بالصفحات',
      function_exists('salaryEngineAllowed') && function_exists('salaryYearPayable') && function_exists('healNewHiresNoRows20260909')
      && substr_count($src101['pages/annual_slip.php'], 'salaryEngineAllowed(') === 2 && substr_count($src101['pages/open_year.php'], 'salaryEngineAllowed(') === 2
      && strpos($src101['pages/monthly_payroll.php'], 'salaryEngineAllowed(') !== false && strpos($src101['includes/compliance.php'], 'salaryEngineAllowed($r, $db)') !== false
      && strpos($src101['includes/payroll_calculator.php'], '$hasConfig = salaryEngineAllowed($e, $db);') !== false
      && strpos($src101['includes/payroll_calculator.php'], '!$hasBaseCfg && !salaryYearPayable(') !== false
      && strpos($src101['includes/header.php'], 'healNewHiresNoRows20260909();') !== false && $raw101 === 0,
      "raw=$raw101");
// المنقول بأساس مخزّن بلا إعداد يبقى محميّاً؛ والملاك/صاحب الأساس مسموح دائماً
$tr101 = $db->query("SELECT e.* FROM employees e WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
    AND COALESCE(e.base_salary_usd,0) <= 0 AND COALESCE(e.contract_salary_lbp,0) <= 0
    AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.base_plus_echelon_lbp > 0) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('الجديد بلا أساس: المنقول بأساس مخزّن (بلا إعداد) يبقى محميّاً من المحرّك الكامل + الملاك مسموح + صاحب العقد بالليرة مسموح',
      $tr101 && !salaryEngineAllowed($tr101, $db)
      && salaryEngineAllowed(['id' => 0, 'employee_type' => 'enseignant_titulaire'], $db)
      && salaryEngineAllowed(['id' => 0, 'employee_type' => 'enseignant_contractuel', 'contract_salary_lbp' => 3025000], $db),
      'transferred#' . ($tr101['id'] ?? '-'));
// تجربة حيّة: متعاقدة جديدة بمكسيموس (دخول 2026-10-01، أساس 0، إضافي 550 $ من 10 إلى 6، نقل يومي 5 $ × 4 أيام × 4 أسابيع)
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, salary_input_mode, base_salary_usd, contract_salary_lbp, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted)
    VALUES (2, '__REG101', 'enseignant_contractuel', 'فحص', 'ريتا101', 'Reg', 'Test101', '2026-10-01', 'actif', 'percent_of_lbp', 0, 0, 10, 4, 4, 1, 1, 1, 1, 0, 0)");
$rid101 = (int)$db->lastInsertId();
try {
    $n0 = recalcEmployeeYear($rid101); // بلا ما يُدفَع → لا أشهر أصفار
    $z101 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $rid101")->fetchColumn();
    $db->exec("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES
        ($rid101, 'prime_fixe', 1, '2026-2027', 550, 'amount', 'USD', 10, 6, 1), ($rid101, 'transport_daily', 1, '2026-2027', 5, 'amount', 'USD', 10, 9, 1)");
    $n1 = recalcEmployeeYear($rid101);
    $rw101 = []; foreach ($db->query("SELECT * FROM monthly_salaries WHERE employee_id = $rid101 ORDER BY year, month") as $r) $rw101[(int)$r['month']] = $r;
    $oct = $rw101[10] ?? []; $jul = $rw101[7] ?? [];
    $rateOct = getExchangeRate(10, 2026);
    $expPrime = usdToLbp(550, $rateOct); $expTr = usdToLbp(5 * 4 * 4, $rateOct);
    $expNet = floor(($expPrime - (float)$oct['total_retenues_lbp']) / 1000) * 1000;
    // ثم الأستاذ نفسه بعد إطفاء بنوده: صفوفه صارت مخزّنة بأساس 0 → ما زال مسموحاً (لا أساس منقول) والإعادة تُصفّر الإضافي بدل تركه عالقاً
    $db->exec("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = $rid101");
    $n2 = recalcEmployeeYear($rid101);
    $stuck = (float)$db->query("SELECT COALESCE(SUM(prime_fixe_lbp + transport_lbp), 0) FROM monthly_salaries WHERE employee_id = $rid101")->fetchColumn();
    check('الجديد بلا أساس (تجربة حيّة ريتا101): بلا علاوة = 0 صف · مع إضافي 550 $ ونقل 5 $ = 10 أشهر: تشرين الأول أساس 0 + إضافي ' . number_format($expPrime) . ' + نقل ' . number_format($expTr) . ' + ضمان 3٪ على الإضافي + الصافي داون للألف + المستحق = الصافي + النقل · تموز بلا إضافي ولا نقل (10→6 والنافذة) · إطفاء البنود يصفّر الإضافي والنقل',
          $n0 === 0 && $z101 === 0 && $n1 === 10 && count($rw101) === 10
          && (float)$oct['base_plus_echelon_lbp'] === 0.0 && (float)$oct['prime_fixe_lbp'] === (float)$expPrime && (float)$oct['transport_lbp'] === (float)$expTr
          && (float)$oct['cnss_amount_lbp'] === round($expPrime * 0.03) && (float)$oct['net_salary_lbp'] === (float)$expNet && fmod((float)$oct['net_salary_lbp'], 1000) === 0.0
          && (float)$oct['total_due_lbp'] === (float)$expNet + (float)$expTr
          && $jul && (float)$jul['prime_fixe_lbp'] === 0.0 && (float)$jul['transport_lbp'] === 0.0
          && $n2 === 10 && $stuck === 0.0,
          "n0=$n0 z=$z101 n1=$n1 rows=" . count($rw101) . ' oct=' . json_encode(['b' => $oct['base_plus_echelon_lbp'] ?? null, 'p' => $oct['prime_fixe_lbp'] ?? null, 't' => $oct['transport_lbp'] ?? null, 'cnss' => $oct['cnss_amount_lbp'] ?? null, 'net' => $oct['net_salary_lbp'] ?? null, 'due' => $oct['total_due_lbp'] ?? null]) . " n2=$n2 stuck=$stuck");
} finally {
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid101");
    $db->exec("DELETE FROM employee_bonuses WHERE employee_id = $rid101");
    $db->exec("DELETE FROM employees WHERE id = $rid101");
}

/* ===================================================================
 * 102) 👨‍👩‍👧 الوضع العائلي بخانات واضحة (طلبه 2026-09-10 «متزوج أم أعزب؟ نعم/كلا — الزوجة تعمل؟
 *      نعم/كلا — عدد الأولاد») + بطاقة «المحسومات والتنزيل العائلي الساري» بالتبويب المالي:
 *      المخزّن يبقى مفتاح القانون social_status ويُركَّب عند الحفظ (composeSocialStatus) —
 *      العازب بأولاد يبقى celibataire (لا يتغيّر تنزيل أحد من الـ79)، والمتزوج/الأرمل بسقف 5.
 * =================================================================== */
$src102 = (string)file_get_contents($PROJ . '/pages/employees.php');
check('الوضع العائلي الواضح: composeSocialStatus/splitSocialStatus معرَّفتان وتُركّبان مفتاح القانون بالضبط (عازب+3 = celibataire · متزوج+2 = marie_2_enfants · متزوج+7 = marie_5_enfants · أرمل+0 = veuf_sans_enfants · أرمل+1 = veuf_1_enfant · قيمة قديمة كاملة تمرّ كما هي) والتفكيك عكسها',
      function_exists('composeSocialStatus') && function_exists('splitSocialStatus')
      && composeSocialStatus('celibataire', 3) === 'celibataire' && composeSocialStatus('marie', 2) === 'marie_2_enfants'
      && composeSocialStatus('marie', 7) === 'marie_5_enfants' && composeSocialStatus('marie', 0) === 'marie_sans_enfants'
      && composeSocialStatus('veuf', 0) === 'veuf_sans_enfants' && composeSocialStatus('veuf', 1) === 'veuf_1_enfant'
      && composeSocialStatus('marie_3_enfants', 0) === 'marie_3_enfants' && composeSocialStatus('', 2) === 'celibataire'
      && splitSocialStatus('marie_2_enfants') === ['marie', 2] && splitSocialStatus('veuf_1_enfant') === ['veuf', 1]
      && splitSocialStatus('marie_sans_enfants') === ['marie', 0] && splitSocialStatus('celibataire') === ['celibataire', 0]);
// كل مفتاح مخزّن بالقاعدة يعود لنفسه بعد تفكيك ثم تركيب (لا يتغيّر تنزيل أحد عند إعادة الحفظ)
$rt102 = 0; $bad102 = [];
foreach ($db->query("SELECT DISTINCT social_status FROM employees WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN) as $ss102) {
    [$k102, $n102] = splitSocialStatus($ss102);
    if (composeSocialStatus($k102, $n102) !== (string)$ss102) $bad102[] = $ss102; else $rt102++;
}
check('الوضع العائلي الواضح: كل مفاتيح القاعدة تعود لنفسها بعد تفكيك/تركيب (إعادة حفظ الملف لا تغيّر فئة أحد)', !$bad102 && $rt102 > 0, "ok=$rt102 bad=" . implode(',', $bad102));
check('الوضع العائلي الواضح: الفورم يعرض «متزوج؟» (marital_kind) + «الزوج/الزوجة يعمل؟» كقائمة نعم/كلا + عدد الأولاد، بلا القائمة القديمة الـ12 خياراً، والحفظ يركّب social_status من النوع + العدد ويقرأ spouse_works من القيمة لا من isset',
      strpos($src102, 'name="marital_kind" id="maritalKind"') !== false && strpos($src102, '<select name="spouse_works" class="form-select">') !== false
      && strpos($src102, 'name="number_of_children"') !== false && strpos($src102, 'name="social_status"') === false
      && strpos($src102, "'social_status' => composeSocialStatus(\$_POST['marital_kind'] ?? (\$_POST['social_status'] ?? 'celibataire'), (int)(\$_POST['number_of_children'] ?? 0))") !== false
      && strpos($src102, "'spouse_works' => ((string)(\$_POST['spouse_works'] ?? '0') === '1') ? 1 : 0") !== false
      && strpos($src102, "isset(\$_POST['spouse_works'])") === false);
check('الملف المالي: بطاقة «المحسومات والتنزيل العائلي الساري» (finSummaryCard) تقرأ التنزيل من المصدر الواحد familyDeductionAnnual (÷12) والصندوق/الضمان/الضريبة من آخر شهر مخزّن بالسنة المختارة عبر money() لا formatLBP للخانة',
      strpos($src102, 'id="finSummaryCard"') !== false && substr_count($src102, "familyDeductionAnnual(\$employee['social_status'] ?? '', \$employee['spouse_works'] ?? 0,") === 1
      && strpos($src102, "'monthly' => (int)floor(\$fsAnnual / 12)") !== false
      && strpos($src102, "return \$v === null ? '—' : money(\$v, null, \$finSum['opts']);") !== false
      && strpos($src102, "SELECT month, year, school_year, caisse_amount_lbp, cnss_amount_lbp, income_tax_lbp") !== false);

/* ===================================================================
 * 103) 👨‍👩‍👧 زيادة الزوج/تنزيل الأولاد تحت الحالة العائلية (جورج العموري 2026-09-10 «حطينا الزوجة لا تعمل
 *      وعندو ولدين ما حسبلهن التنزيل»): قائمتا نعم/كلا بالتبويب الشخصي (لا بتبويب المحسومات) + الحفظ بالقيمة
 *      + قاعدة تقرير المخالفات family_ded_off (بند بالفرق السنوي + تصحيح بكبسة = تضوية + إعادة حساب السنة، بلا تضوية جماعية)
 *      + قفل القانون: متزوج + ولدان + الزرّان = 765,000,000 سنوياً (63,750,000 شهرياً = كشفه القديم).
 * =================================================================== */
$src103 = (string)file_get_contents($PROJ . '/pages/employees.php');
$cmp103 = (string)file_get_contents($PROJ . '/includes/compliance.php');
check('زيادة الزوج/تنزيل الأولاد: قائمتان نعم/كلا داخل الحالة العائلية (famDedGroup) بالتبويب الشخصي، غير مكرّرتين بتبويب المحسومات، والحفظ يقرأ القيمة لا isset',
      strpos($src103, 'id="famDedGroup"') !== false
      && substr_count($src103, '<select name="grant_spouse_addition" class="form-select">') === 1 && substr_count($src103, '<select name="grant_children_addition" class="form-select">') === 1
      && strpos($src103, 'type="checkbox" name="grant_spouse_addition"') === false && strpos($src103, 'type="checkbox" name="grant_children_addition"') === false
      && strpos($src103, "'grant_spouse_addition' => ((string)(\$_POST['grant_spouse_addition'] ?? '0') === '1') ? 1 : 0") !== false
      && strpos($src103, "'grant_children_addition' => ((string)(\$_POST['grant_children_addition'] ?? '0') === '1') ? 1 : 0") !== false
      && strpos($src103, "isset(\$_POST['grant_spouse_addition'])") === false);
check('قانون التنزيل العائلي (جورج العموري): متزوج + ولدان + الزوجة لا تعمل + الزرّان = 765,000,000 سنوياً (63,750,000 شهرياً = كشفه القديم) · زيادة الزوج وحدها 675م · الأولاد وحدهم 540م · بلا الزرّين 450م كالعازب · تاريخ عمل زوج وهمي (0001-01-01) لا يُسقط الزيادة',
      familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-09-01', 1, 1) === 765000000
      && familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-09-01', 1, 0) === 675000000
      && familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-09-01', 0, 1) === 540000000
      && familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-09-01', 0, 0) === 450000000
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "\$fdSws = (\$sw && (string)\$sw >= '1900-01-01') ? \$sw : null;") !== false);
// ⚖️ «إذا الزوجة تعمل تنزيل الأولاد بينقسم على اثنين بين الزوج والزوجة» (تنبيهه 2026-09-10): الزوجان العاملان يتقاسمان
// حصة الأولاد مناصفة — الأرمل/المطلق لا زوج فحصته كاملة، و«تنزيل الأولاد: كلا» يبقى الشخصي فقط
check('قانون التنزيل العائلي: الزوج يعمل ⇒ نصف حصة الأولاد — متزوج+ولدان+الزوجة تعمل+الأولاد نعم = 495,000,000 (= كشف جوزيف حليحل 41,250,000 شهرياً) · ولد واحد = 472,500,000 · أرمل+ولدان = 540م كاملة · الزوج يعمل والأولاد كلا = 450م · الزوج لا يعمل = 765م كاملة',
      familyDeductionAnnual('marie_2_enfants', 1, 1, '2026-09-01', 1, 1) === 495000000
      && familyDeductionAnnual('marie_1_enfant', 1, 1, '2026-09-01', 1, 1) === 472500000
      && familyDeductionAnnual('veuf_2_enfants', 0, 1, '2026-09-01', 1, 1) === 540000000
      && familyDeductionAnnual('marie_2_enfants', 1, 1, '2026-09-01', 1, 0) === 450000000
      && familyDeductionAnnual('marie_2_enfants', 0, 1, '2026-09-01', 1, 1) === 765000000
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), 'if ($spouseActuallyWorks && $ded > $single) $ded = $single + ($ded - $single) / 2;') !== false);
// 👨‍👩‍👧 «فصلهن: الزوج قديش، الزوجة قديش، الأولاد قديش، وتحتهن المجموع» (2026-09-10): التفصيل بالملف المالي يركب على المجموع دائماً
$bd103a = familyDeductionBreakdown(['id' => 0, 'social_status' => 'marie_2_enfants', 'spouse_works' => 0, 'apply_family_deduction' => 1, 'grant_spouse_addition' => 1, 'grant_children_addition' => 1], '2026-09-01');
$bd103b = familyDeductionBreakdown(['id' => 0, 'social_status' => 'marie_2_enfants', 'spouse_works' => 1, 'apply_family_deduction' => 1, 'grant_spouse_addition' => 0, 'grant_children_addition' => 1], '2026-09-01');
$bd103c = familyDeductionBreakdown(['id' => 0, 'social_status' => 'celibataire', 'spouse_works' => 0, 'apply_family_deduction' => 1, 'grant_spouse_addition' => 0, 'grant_children_addition' => 0], '2026-09-01');
check('تفصيل التنزيل العائلي بالملف المالي: جورج (الزوجة لا تعمل + الزرّان) = 450م شخصي + 225م زوج + 90م أولاد = 765م · جوزيف (الزوجة تعمل + الأولاد) = 450م + 0 + 45م = 495م · عازب = 450م فقط · والجدول famDedBreakdown بالصفحة',
      $bd103a === ['personal' => 450000000, 'spouse' => 225000000, 'children' => 90000000, 'total' => 765000000]
      && $bd103b === ['personal' => 450000000, 'spouse' => 0, 'children' => 45000000, 'total' => 495000000]
      && $bd103c === ['personal' => 450000000, 'spouse' => 0, 'children' => 0, 'total' => 450000000]
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'id="famDedBreakdown"') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "familyDeductionBreakdown(\$employee + ['id' => (int)\$id], \$fsAsOf)") !== false,
      json_encode([$bd103a, $bd103b]));
check('قانون تقاسم تنزيل الأولاد: شفاء healChildrenSplit20260910 معرَّف وموصول بالهيدر (يعيد حساب من أشهره مخزّنة بالحصة الكاملة — جوزيف حليحل أونلاين) ويستهدف المتزوج+أولاد+الزوج يعمل+الأولاد نعم فقط',
      function_exists('healChildrenSplit20260910')
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healChildrenSplit20260910();') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "AND e.social_status LIKE 'marie%' AND e.social_status NOT LIKE '%sans_enfants' AND COALESCE(e.grant_children_addition,0) = 1") !== false);
check('تقرير المخالفات: قاعدة family_ded_off معرَّفة (مراجعة) + بندها يحسب الفرق بالمصدر الواحد familyDeductionAnnual + التصحيح يضوّي الزرّين المطفأين فقط ويعيد حساب السنة + مستثناة من «تصحيح الكل» + الملف المالي يعرض حالة الزرّين',
      isset(complianceRules()['family_ded_off'])
      && strpos($cmp103, "\$add('family_ded_off', \$r,") !== false && substr_count($cmp103, 'familyDeductionAnnual($r[\'social_status\'], $r[\'spouse_works\'] ?? 0, 1, $fdAsOf,') === 2
      && strpos($cmp103, "case 'family_ded_off':") !== false && strpos($cmp103, "if (!empty(\$d['spouse'])) \$set[] = 'grant_spouse_addition = 1';") !== false
      && strpos($cmp103, "&& \$rule !== 'family_ded_off' && \$rule !== 'eoc_base_only' && \$rule !== 'carried_stale' && \$rule !== 'month_stale') \$keys[] = \$it['key'];") !== false
      && strpos($cmp103, "&& \$rk !== 'family_ded_off' && \$rk !== 'eoc_base_only' && \$rk !== 'carried_stale' && \$rk !== 'month_stale' && count(array_filter(") !== false
      && strpos($src103, '· تنزيل الأولاد: <b>') !== false);

/* ===================================================================
 * 104) 🧾 صمام «الضريبة المخزّنة ≠ القانون الحيّ» (2026-09-10 «انتبه هيدا برنامج يا أستاذ ما بدي ضل أعمل أنا تست»):
 *      المصدر الواحد lawIncomeTaxAnnual (المحرّك + annualLawTaxAsOf + expectedMonthlyTax) + قاعدة tax_stale
 *      بتقرير المخالفات (تصحيح = إعادة حساب السنة) وبالفحص الرسمي (data_audit، خطأ حساب) + تجربة حيّة.
 * =================================================================== */
$cp104 = (string)file_get_contents($PROJ . '/includes/compliance.php');
$da104 = (string)file_get_contents($PROJ . '/includes/data_audit.php');
$pc104 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$fn104 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('صمام الضريبة: lawIncomeTaxAnnual/expectedMonthlyTax معرَّفتان، المحرّك وannualLawTaxAsOf يستدعيان lawIncomeTaxAnnual (لا حلقة شطور مكرّرة)، قاعدة tax_stale بتقرير المخالفات (19) وبالفحص الرسمي، وcomplianceApply يعيد حساب السنة لها',
      function_exists('lawIncomeTaxAnnual') && function_exists('expectedMonthlyTax')
      && strpos($pc104, 'return lawIncomeTaxAnnual(getDB(), $taxableAfterDeduction, $asOfDed);') !== false && substr_count($pc104, 'FROM tax_brackets') === 0
      && substr_count($fn104, 'FROM tax_brackets WHERE effective_from = (SELECT MAX(effective_from)') === 1
      && isset(complianceRules()['tax_stale']) && strpos($cp104, "\$add('tax_stale', \$r,") !== false && strpos($cp104, "case 'active_nomonths': case 'tax_stale':") !== false
      && strpos($da104, "\$add('tax_stale',") !== false && strpos($da104, 'expectedMonthlyTax($e, (float)$mrow[\'taxable_base_lbp\']') !== false);
// قفل رقمي (جوزيف حليحل 958، تشرين 2025: وعاء 136,445,000 − صندوق 8,186,700 = 128,258,300؛ متزوج + ولدان + الزوجة تعمل + الأولاد نعم ⇒ تنزيل 495م ⇒ ضريبة كشفه القديم 3,240,581)
$jh104 = ['id' => 0, 'tax_subject' => 1, 'apply_family_deduction' => 1, 'social_status' => 'marie_2_enfants', 'spouse_works' => 1, 'grant_spouse_addition' => 0, 'grant_children_addition' => 1];
check('صمام الضريبة (قفل جوزيف حليحل): expectedMonthlyTax(وعاء 128,258,300، تشرين 2025، متزوج+ولدان+الزوجة تعمل+الأولاد نعم) = 3,240,581 = كشفه القديم · والأولاد كلا = 3,503,081 · غير خاضع = 0',
      expectedMonthlyTax($jh104, 128258300, 10, 2025, $db) === 3240581
      && expectedMonthlyTax($jh104 + ['x' => 1], 128258300, 10, 2025, $db) === 3240581
      && expectedMonthlyTax(array_merge($jh104, ['grant_children_addition' => 0]), 128258300, 10, 2025, $db) === 3503081
      && expectedMonthlyTax(array_merge($jh104, ['tax_subject' => 0]), 128258300, 10, 2025, $db) === 0,
      'got=' . expectedMonthlyTax($jh104, 128258300, 10, 2025, $db) . '/' . expectedMonthlyTax(array_merge($jh104, ['grant_children_addition' => 0]), 128258300, 10, 2025, $db));
// تجربة حيّة: ملاك مؤقت متزوج+ولدان بإضافي 133م — يُحسب ⇒ لا بند؛ تُضوّى الأزرار بلا إعادة حساب ⇒ بند tax_stale؛ التصحيح يزيله والضريبة = القانون
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, titularization_date, status, salary_input_mode, current_grade, diploma, social_status, number_of_children, spouse_works, grant_spouse_addition, grant_children_addition, payment_months_per_year, tax_subject, tax_includes_extra, tax_includes_echelon, cnss_subject, eoc_subject, eoc_includes_extra, is_deleted)
    VALUES (2, '__REG104', 'enseignant_titulaire', 'فحص', 'ضريبة104', 'Reg', 'Tax104', '2015-10-01', '2015-10-01', 'actif', 'percent_of_lbp', 20, 'licence', 'marie_2_enfants', 2, 0, 0, 0, 10, 1, 1, 1, 1, 1, 1, 0)");
$rid104 = (int)$db->lastInsertId();
try {
    $db->exec("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, is_active) VALUES ($rid104, 'prime_fixe', 1, '2025-2026', 133000000, 'amount', 'LBP', 1)");
    $n104 = recalcEmployeeYear($rid104, '2025-2026');
    $find104 = function () use ($db, $rid104) { foreach (complianceItems($db, '2025-2026') as $it) if ($it['rule'] === 'tax_stale' && (int)$it['emp_id'] === $rid104) return $it; return null; };
    $row104 = $db->query("SELECT taxable_base_lbp, income_tax_lbp FROM monthly_salaries WHERE employee_id = $rid104 AND month = 10 AND year = 2025")->fetch(PDO::FETCH_ASSOC);
    $before104 = $find104();
    $db->exec("UPDATE employees SET grant_spouse_addition = 1, grant_children_addition = 1 WHERE id = $rid104"); // تغيير بالملف بلا إعادة حساب = أشهر قديمة
    $e104 = $db->query("SELECT * FROM employees WHERE id = $rid104")->fetch(PDO::FETCH_ASSOC);
    $exp104 = expectedMonthlyTax($e104, (float)$row104['taxable_base_lbp'], 10, 2025, $db);
    $stale104 = $find104();
    $res104 = $stale104 ? complianceApply($db, $stale104) : 'no item';
    $after104 = $find104();
    $row104b = $db->query("SELECT income_tax_lbp FROM monthly_salaries WHERE employee_id = $rid104 AND month = 10 AND year = 2025")->fetch(PDO::FETCH_ASSOC);
    check('صمام الضريبة (تجربة حيّة ضريبة104): بعد الاحتساب لا بند · تضوية زيادة الزوج والأولاد بالملف بلا إعادة حساب ⇒ الضريبة المتوقّعة أقل من المخزّنة وبند tax_stale يظهر بتقرير المخالفات · «موافق — صحّح» يعيد حساب السنة فيزول البند والضريبة المخزّنة = القانون الحيّ',
          $n104 === 10 && $row104 && (int)$row104['income_tax_lbp'] > 0 && $before104 === null
          && $exp104 < (int)$row104['income_tax_lbp'] && $stale104 !== null && $stale104['auto'] === true
          && $after104 === null && (int)$row104b['income_tax_lbp'] === $exp104,
          "n=$n104 stored=" . ($row104['income_tax_lbp'] ?? '-') . " exp=$exp104 before=" . ($before104 ? 'item' : 'none') . ' stale=' . ($stale104 ? 'item' : 'none') . " apply=$res104 after=" . ($after104 ? 'item' : 'none') . ' now=' . ($row104b['income_tax_lbp'] ?? '-'));
} finally {
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid104");
    $db->exec("DELETE FROM employee_bonuses WHERE employee_id = $rid104");
    $db->exec("DELETE FROM compliance_decisions WHERE employee_id = $rid104");
    $db->exec("DELETE FROM employees WHERE id = $rid104");
}

/* =====================================================================
 * 105) 🆕 نظام الأساتذة الجدد (4+4+2) بديل القوانين 244/102/223 (جوزف السرّوع 2026-09-10
 *      «p1 بتقول عاطيهن وp2 مش عاطيهن»): من دخل الملاك بعد 2/4/2012 لا تُعرَض له هذه القوانين
 *      «بعدها ما أُعطيت» ولا تُطبَّق عليه (lawGradesForEmployee = 0 = المصدر الواحد isNewSystemTeacher)،
 *      وتابلو الدرجات لا يلغي قانوناً معطّلاً (لا ينطبق) عند الحفظ. قانون 344 اليدوي و2017 كما هما.
 * =================================================================== */
$lawsBy105 = [];
foreach ($db->query("SELECT * FROM exceptional_grades_laws WHERE is_active = 1") as $L105) $lawsBy105[(string)$L105['law_number']] = $L105;
$empNew105 = ['id' => 0, 'hire_date' => '2010-10-01', 'titularization_date' => '2012-10-01', 'diploma' => 'ijaza_taalimiya', 'employee_type' => 'enseignant_titulaire'];
$empOld105 = ['id' => 0, 'hire_date' => '2003-10-01', 'titularization_date' => '2005-10-01', 'diploma' => 'ijaza_taalimiya', 'employee_type' => 'enseignant_titulaire'];
$empEdge105 = ['id' => 0, 'hire_date' => '2010-04-02', 'titularization_date' => '2012-04-02', 'diploma' => 'ijaza_taalimiya', 'employee_type' => 'enseignant_titulaire'];
check('نظام 4+4+2: isNewSystemTeacher = دخول الملاك بعد 2/4/2012 (01/10/2012 جديد · 02/04/2012 قديم · 2005 قديم · بلا hire_date بلا تثبيت = لا)',
      isNewSystemTeacher($empNew105) === true && isNewSystemTeacher($empEdge105) === false
      && isNewSystemTeacher($empOld105) === false && isNewSystemTeacher(['id' => 0]) === false);
check('نظام 4+4+2: القوانين 244/102/223 = 0 درجة للجديد، وكاملة للقديم (3/3/4.5)، و344 اليدوي 4 للاثنين',
      isset($lawsBy105['244'], $lawsBy105['102'], $lawsBy105['223'], $lawsBy105['344'])
      && lawGradesForEmployee($lawsBy105['244'], $empNew105) == 0 && lawGradesForEmployee($lawsBy105['102'], $empNew105) == 0
      && lawGradesForEmployee($lawsBy105['223'], $empNew105) == 0
      && lawGradesForEmployee($lawsBy105['244'], $empOld105) == 3 && lawGradesForEmployee($lawsBy105['102'], $empOld105) == 3
      && lawGradesForEmployee($lawsBy105['223'], $empOld105) == 4.5
      && lawGradesForEmployee($lawsBy105['344'], $empNew105) == 4 && lawGradesForEmployee($lawsBy105['344'], $empOld105) == 4,
      'new=' . lawGradesForEmployee($lawsBy105['244'] ?? ['law_number' => 'x', 'grades_count' => 0], $empNew105)
      . ' old=' . lawGradesForEmployee($lawsBy105['244'] ?? ['law_number' => 'x', 'grades_count' => 0], $empOld105));
check('نظام 4+4+2: لائحة «بعدها ما أُعطيت» فارغة للجديد على 244/102/223 وممتلئة للقديم (3 وحدات لـ244)',
      isset($lawsBy105['244']) && exceptionalGrantUnits($empNew105, $lawsBy105['244']) === []
      && exceptionalGrantUnits($empNew105, $lawsBy105['223']) === []
      && count(exceptionalGrantUnits($empOld105, $lawsBy105['244'])) === 3);
$grSrc105 = (string)file_get_contents(__DIR__ . '/../pages/grades.php');
check('نظام 4+4+2: تابلو الدرجات لا يلغي قانوناً لا ينطبق عند الحفظ (حارس lawGradesForEmployee ≤ 0 → continue) + جدول القوانين للتذكير (gradeLawsRefTable) بلوحة الدرجات',
      strpos($grSrc105, 'lawGradesForEmployee($lawRow, $empExc) <= 0) continue;') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/functions.php'), 'id="gradeLawsRefTable"') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/functions.php'), 'لا ينطبق عليه — بديله نظام 4+4+2') !== false
      && strpos((string)file_get_contents(__DIR__ . '/../includes/payroll_calculator.php'), "\$isNew = isNewSystemTeacher(\$emp);") !== false);

/* =====================================================================
 * 106) ⚖️ «أكيد كلهم 65» (2026-09-10): كل ملاك عبرا على نسبة 65٪ — شفاء healAbraPct65_20260910 (مرّة واحدة،
 *      نسخ _bk_bonuses_abra65_0910/_ms_bk_abra65_0910، موصول بالهيدر) يحوّل النِّسَب الخاصة السبع (34.5/41.5/80/80.5/91/91/99)
 *      لـ65٪ ويعيد الحساب؛ المحميّتان ريتا مارون وماريا الياس حليحل (سلفة موثّقة = 65٪ أصلاً) لا تُمسّان.
 *      قفل: جوزف السرّوع تشرين 2025 إضافي 105,000,000 (كان 130م بنسبة 80.5٪) بأساس 2,720,000.
 * =================================================================== */
$fn106 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('عبرا 65٪: الشفاء موجود وموصول بالهيدر (v2 بلا محميّين — «دايما طبّق القانون بكل البرنامج») ويحدّد المدرسة بـ«ثانوية السيدة»',
      strpos($fn106, 'function healAbraPct65_20260910()') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healAbraPct65_20260910();') !== false
      && substr_count(substr($fn106, strpos($fn106, 'function healAbraPct65_20260910()'), 4000), "LIKE '%حليحل%'") === 0
      && strpos($fn106, "heal_abra_pct65v2_20260910") !== false
      && strpos($fn106, "name_ar LIKE '%ثانوية السيدة%'") !== false);
$abra106 = (int)$db->query("SELECT id FROM schools WHERE name_ar LIKE '%ثانوية السيدة%' ORDER BY id LIMIT 1")->fetchColumn();
$non65 = $db->query("SELECT CONCAT(e.first_name_ar,' ',e.last_name_ar) nm FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id
    WHERE e.school_id=$abra106 AND e.employee_type='enseignant_titulaire' AND e.is_deleted=0 AND b.is_active=1 AND b.bonus_type='prime_fixe'
      AND b.start_month IS NULL AND b.end_month IS NULL AND (b.school_year IS NULL OR b.school_year='2025-2026')
      AND NOT (b.value_type='percent' AND ROUND(b.amount,2)=65.00)")->fetchAll(PDO::FETCH_COLUMN);
$jos106 = $db->query("SELECT ms.base_plus_echelon_lbp bpe, ms.prime_fixe_lbp p FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
    WHERE e.school_id=$abra106 AND e.first_name_ar='جوزف' AND e.last_name_ar LIKE '%السر%وع%' AND e.is_deleted=0 AND ms.year=2025 AND ms.month=10 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
// ريتا مارون حليحل: كانون 2026 بالقانون = درجة 26 (4+4+2 بكانون) → أساس 2,225,000 وإضافي 65٪ = 86,000,000 (كان مجمّداً 2,085,000/80م على الكشف القديم)
$rita106 = $db->query("SELECT ms.base_plus_echelon_lbp bpe, ms.prime_fixe_lbp p FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
    WHERE e.school_id=$abra106 AND e.first_name_ar LIKE 'ريتا%' AND e.father_name_ar LIKE 'مارون%' AND e.last_name_ar LIKE '%حليحل%' AND e.is_deleted=0 AND ms.year=2026 AND ms.month=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('عبرا 65٪ (داتا حيّة): كل ملاك عبرا على 65٪ بلا استثناء + قفل جوزف السرّوع تشرين 2025 = 105,000,000 (أساس 2,720,000) + ريتا مارون حليحل كانون 2026 = 2,225,000/86,000,000 بالقانون',
      $abra106 > 0 && count($non65) === 0 && $jos106 && (int)$jos106['p'] === 105000000 && (int)$jos106['bpe'] === 2720000
      && $rita106 && (int)$rita106['bpe'] === 2225000 && (int)$rita106['p'] === 86000000,
      'non65=' . implode('؛', $non65) . ' jos=' . json_encode($jos106) . ' rita=' . json_encode($rita106));

/* =====================================================================
 * 107) 📅 فتح السنة 2026-09-12 «كمّل التدرّج عادي وخلّي الزيادة اللي عطيتها»: applyLegalGradesForNewYear صار
 *      بالمحرّك (المصدر الواحد) — الدرجة الجديدة = المخزّنة كما رتّبها + ما يضيفه القانون لهذه السنة (عادي بتشرين
 *      + استثنائية بكانون) بلا «أمان» يحرم مَن درجته ≠ القانون من الاستثنائية (كان يحرم 23 أستاذاً من دفعة 2023-2024).
 *      ذاتي التصحيح: صفوف «(فتح السنة)» القديمة الخاطئة تُستبدَل، والمعدَّلة يدوياً تُترَك. + اختيار المدارس بالتأشير (الكل أو بعضها).
 * =================================================================== */
require_once $PROJ . '/includes/payroll_calculator.php';
$pc107 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$oy107 = (string)file_get_contents($PROJ . '/pages/open_year.php');
check('فتح السنة: applyLegalGradesForNewYear بالمحرّك فقط، بلا قيد «≠ القانون ⇒ 0.5 بلا استثنائي»، مع استبدال الصفوف الآلية الخاطئة واحترام المعدَّلة يدوياً',
      function_exists('applyLegalGradesForNewYear') && strpos($oy107, 'function applyLegalGradesForNewYear') === false
      && strpos($pc107, "if (abs((float)\$prev['final_grade'] - \$running) >= 0.01)") === false
      && strpos($pc107, "if (mb_strpos((string)\$r['notes'], '(فتح السنة)') === false) return 0;") !== false && strpos($pc107, "\$note . ' [+' . \$dl . ']'") !== false
      && strpos($pc107, "\$ordDelta = max(0.0, round((float)\$new['ordinary']") !== false);
check('فتح السنة: اختيار المدارس بالتأشير (كل المدارس افتراضياً + school_ids[]) والرسالة تسمّي المدارس المفتوحة',
      strpos($oy107, 'name="school_ids[]"') !== false && strpos($oy107, "(array)(\$_POST['school_ids'] ?? [])") !== false
      && strpos($oy107, "if (\$allSchoolsOpen) \$chosen = \$validIds;") !== false && strpos($oy107, 'كُمِّل تدرّجهم على درجتهم كما رتّبتها') !== false
      && strpos($oy107, 'كما رتّبتها') !== false);
// تجربة حيّة (بلا أثر: transaction + rollback) على أستاذ درجته المخزّنة ≠ القانون وله استثنائية بكانون 2027 (تيا نخلة/ماريا حليحل…)
$why107 = ''; $ok107 = false;
try {
    $cand = null;
    foreach ([1651, 1554, 1595, 1677] as $cid) {
        $ce = $db->query("SELECT id, employee_type, is_deleted FROM employees WHERE id=$cid")->fetch(PDO::FETCH_ASSOC);
        if (!$ce || (int)$ce['is_deleted'] === 1 || $ce['employee_type'] !== 'enseignant_titulaire') continue;
        $p = buildLegalGradeHistory($cid, '2026-09-30', true); $n = buildLegalGradeHistory($cid, '2027-09-30', true);
        $od = round($n['ordinary'] - $p['ordinary'], 1); $xd = round($n['exceptional'] - $p['exceptional'], 1);
        if ($od > 0 && $xd > 0) { $cand = [$cid, $od, $xd]; break; }
    }
    if (!$cand) { $why107 = 'لا عيّنة محلياً'; $ok107 = true; }
    else {
        [$cid, $od, $xd] = $cand;
        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM employee_grade_history WHERE employee_id=$cid AND change_date IN ('2026-10-01','2027-01-01') AND notes LIKE '%(فتح السنة)%'");
            $run = $db->query("SELECT grade_after FROM employee_grade_history WHERE employee_id=$cid AND grade_after>=1 AND change_date<'2026-10-01' ORDER BY change_date DESC, id DESC LIMIT 1")->fetchColumn();
            $run = ($run === false) ? (float)$db->query("SELECT current_grade FROM employees WHERE id=$cid")->fetchColumn() : (float)$run;
            // صفّ قديم بالقاعدة القديمة (نصف درجة فقط) → يجب أن يُستبدَل
            $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,notes) VALUES (?,?,?,0.5,1,'2026-10-01','biennial_promotion','تدرّج عادي سنوي (فتح السنة)')")->execute([$cid, $run, $run + 0.5]);
            $r1 = applyLegalGradesForNewYear($db, $cid, 2026, 2027);
            $rows = $db->query("SELECT change_date d, grade_before b, grade_after a FROM employee_grade_history WHERE employee_id=$cid AND change_date IN ('2026-10-01','2027-01-01') AND notes LIKE '%(فتح السنة)%' ORDER BY change_date")->fetchAll(PDO::FETCH_ASSOC);
            $r2 = applyLegalGradesForNewYear($db, $cid, 2026, 2027); // idempotent
            // تعديل يدوي على صفّ آلي (المقدار من لوحة الدرجات) → لا يُلمَس ولا يُكرَّر
            $db->exec("UPDATE employee_grade_history SET delta=delta+3, grade_after=grade_after+3 WHERE employee_id=$cid AND change_date='2027-01-01' AND notes LIKE '%(فتح السنة)%'");
            $r3 = applyLegalGradesForNewYear($db, $cid, 2026, 2027);
            $kept = (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id=$cid AND change_date='2027-01-01'")->fetchColumn();
            // صفّ بغير ملاحظتنا بنفس التاريخ (بناء قانوني/يدوي) → لا نلمس شيئاً
            $db->exec("UPDATE employee_grade_history SET notes='عدّلها المستخدم' WHERE employee_id=$cid AND change_date='2027-01-01'");
            $r4 = applyLegalGradesForNewYear($db, $cid, 2026, 2027);
            $kept2 = (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id=$cid AND change_date IN ('2026-10-01','2027-01-01')")->fetchColumn();
            $expOrd = min(52, round($run + $od, 1)); $expExc = min(52, round($expOrd + $xd, 1));
            $ok107 = $r1 === 2 && count($rows) === 2 && $rows[0]['d'] === '2026-10-01' && abs((float)$rows[0]['b'] - $run) < 0.01 && abs((float)$rows[0]['a'] - $expOrd) < 0.01
                  && $rows[1]['d'] === '2027-01-01' && abs((float)$rows[1]['a'] - $expExc) < 0.01 && $r2 === 0 && $r3 === 0 && $kept === 1 && $r4 === 0 && $kept2 === 2;
            $why107 = "emp=$cid run=$run ord=+$od exc=+$xd r1=$r1 rows=" . json_encode($rows) . " r2=$r2 r3=$r3 kept=$kept r4=$r4 kept2=$kept2";
        } finally { $db->rollBack(); }
    }
} catch (Throwable $e) { $why107 = $e->getMessage(); }
check('فتح السنة (تجربة حيّة بلا أثر): الصفّ القديم «نصف درجة فقط» يُستبدَل بالمستحقّ (عادي بتشرين + استثنائية بكانون فوق المخزّنة) + idempotent + الصفّ المعدَّل يدوياً لا يُلمَس', $ok107, $why107);

/* =====================================================================
 * 108) 🔒 قفل السنة الدراسية لكل مدرسة بكلمة سرّ (2026-09-12 «حتى ما نخلص حسابات المدرسة بتضلّ متل ما هي») +
 *      📅 تجهيز 2026-2027 التلقائي بدفعات (heal_tick + نبض footer) + صفحة فتح السنة مرتّبة (خيارات مطوية + أدوات مطوية)
 * =================================================================== */
$fn108 = (string)file_get_contents($PROJ . '/includes/functions.php'); $pc108 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$oy108 = (string)file_get_contents($PROJ . '/pages/open_year.php'); $ft108 = (string)file_get_contents($PROJ . '/includes/footer.php'); $hd108 = (string)file_get_contents($PROJ . '/includes/header.php');
check('قفل السنة: المصدر الواحد isSchoolYearLocked + الجدول ذاتي التركيب + المحرّك لا يحفظ لسنة مقفولة + التركيب/ملف الأستاذ/المكافآت الجماعية/المخالفات/فتح السنة/التفريغ/الاحتساب الشهري يحترمونه',
      function_exists('isSchoolYearLocked') && function_exists('lockSchoolYear') && function_exists('yearLockPasswordOk')
      && strpos($pc108, "if (isSchoolYearLocked((int)(\$this->employee['school_id'] ?? 0), schoolYearOfMonth((int)\$this->year, (int)\$this->month))) return \$this->calculate();") !== false
      && strpos($pc108, "if (isSchoolYearLocked(\$lkSid, (string)\$schoolYear)) return 0;") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "if (isSchoolYearLocked(\$lkSid, \$sy)) { \$_SESSION['flash_error'] = yearLockedMsg(") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/bulk_allowances.php'), "\$lkHit = array_values(array_filter(\$lkSchools") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/compliance.php'), "isSchoolYearLocked((int)\$it['school_id'], \$sy)) { \$lockedSkipped++; continue; }") !== false
      && strpos($oy108, "\$lockedT = array_values(array_filter(\$chosen, fn(\$sid) => isSchoolYearLocked((int)\$sid, \$newYear)));") !== false
      && strpos($oy108, "elseif (isSchoolYearLocked(\$schoolId, \$yr))") !== false && strpos($oy108, "(لا تفريغ لسنة مقفولة)") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/monthly_payroll.php'), "if (isSchoolYearLocked(\$sidC, \$syCalc)) { \$lockedN++; continue; }") !== false
      && strpos($oy108, 'id="yearLocks"') !== false && strpos($oy108, "'lock_pw', 'lock_year', 'unlock_year'") !== false && strpos($hd108, '🔒 مقفولة / Verrouillée') !== false);
// تجربة حيّة بلا أثر: كلمة سرّ مؤقّتة + قفل + إعادة حساب لا تغيّر الشهر + فتح — كل شيء يُرجَع
$why108 = ''; $ok108 = false;
try {
    require_once $PROJ . '/includes/payroll_calculator.php';
    $prevHash108 = (string)getSetting('year_lock_password_hash', '');
    $e108 = $db->query("SELECT e.id, e.school_id FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.school_year = '2025-2026' AND m.month = 11
        WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND e.status = 'actif' ORDER BY e.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$e108) { $why108 = 'لا عيّنة'; $ok108 = true; }
    else {
        $eid = (int)$e108['id']; $sid = (int)$e108['school_id'];
        setSetting('year_lock_password_hash', password_hash('reg-1234', PASSWORD_DEFAULT));
        $wasLocked = isSchoolYearLocked($sid, '2025-2026');
        try {
            lockSchoolYear($sid, '2025-2026', 'regcheck');
            $b = $db->query("SELECT net_salary_lbp, base_plus_echelon_lbp, prime_fixe_lbp, income_tax_lbp FROM monthly_salaries WHERE employee_id = $eid AND year = 2025 AND month = 11")->fetch(PDO::FETCH_ASSOC);
            (new PayrollCalculator($eid, 11, 2025))->calculateAndSave();
            recalcEmployeeYear($eid, '2025-2026');
            $a = $db->query("SELECT net_salary_lbp, base_plus_echelon_lbp, prime_fixe_lbp, income_tax_lbp FROM monthly_salaries WHERE employee_id = $eid AND year = 2025 AND month = 11")->fetch(PDO::FETCH_ASSOC);
            $ok108 = isSchoolYearLocked($sid, '2025-2026') && $b == $a && yearLockPasswordOk('reg-1234') && !yearLockPasswordOk('wrong') && !isSchoolYearLocked($sid, '2019-2020');
            $why108 = "emp=$eid sid=$sid same=" . var_export($b == $a, true);
        } finally {
            if (!$wasLocked) unlockSchoolYear($sid, '2025-2026', 'regcheck');
            setSetting('year_lock_password_hash', $prevHash108);
        }
        $ok108 = $ok108 && !isSchoolYearLocked($sid, '2025-2026');
    }
} catch (Throwable $e) { $why108 = $e->getMessage(); }
check('قفل السنة (تجربة حيّة بلا أثر): بعد القفل لا يتغيّر الشهر باحتساب مباشر ولا بإعادة حساب السنة + كلمة السرّ تُتحقَّق + الفتح يرجّع التعديل', $ok108, $why108);
check('تجهيز 2026-2027 التلقائي: healOpenYear2627_20260912 بثلاث مراحل + pages/heal_tick.php + نبض footer يظهر فقط ما دام غير مكتمل + يحترم القفل',
      function_exists('healOpenYear2627_20260912') && is_file($PROJ . '/pages/heal_tick.php')
      && strpos($fn108, "if (\$s['stage'] === 'grades')") !== false && strpos($fn108, "if (\$s['stage'] === 'transport')") !== false && strpos($fn108, "if (\$s['stage'] === 'abra85')") !== false
      && substr_count(substr($fn108, strpos($fn108, 'function healOpenYear2627_20260912')), 'isSchoolYearLocked(') >= 3
      && strpos($ft108, "openYearHealPending20260912()): ?>") !== false && strpos($ft108, "pages/heal_tick.php") !== false);
check('صفحة فتح السنة مرتّبة (2026-09-12 «واضحة ومش معجقة»): ٣ خطوات + خيارات الإضافات مطوية (details) + الأدوات الإضافية مطوية + زرّ افتح بارز',
      substr_count($oy108, '<details') === 2 && strpos($oy108, 'خيارات إضافية (الافتراضي: الإضافات والنقل نفس السنة الماضية)') !== false
      && strpos($oy108, 'أدوات إضافية (تعديل الإضافات لسنة مفتوحة') !== false && strpos($oy108, 'font-size:17px;font-weight:800;padding:10px 26px') !== false
      && strpos($oy108, 'Comment ça marche') === false);

/* =====================================================================
 * 109) 🏆 نصف «تقديم التدرّج» لنظام 4+4+2 عند أوّل تشرين بعد دخول الملاك (mAY+1) لا mAY+3 — كشف ملاك عبرا تشرين 2026
 *      (2026-09-12): ايليو نوفل 20 بتشرين 2025، اندي يونان 20 بتشرين 2026، ماريا حليحل 16 بكانون 2026. + الجزء الثاني من التجهيز
 *      (lawshift لغير المعدَّلين فقط + grades2 + transport_restore) عبر heal_tick.
 * =================================================================== */
$pc109 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php'); $fn109 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('4+4+2: نصف تقديم التدرّج عند mAY+1 بالمصدر (لا mAY+3)',
      strpos($pc109, "\$compYear = \$mAY + 1;") !== false && strpos($pc109, "\$compYear . '-10-01', 'type' => 'ordinary', 'delta' => 0.5, 'comp' => true") !== false
      && strpos($pc109, "\$lastBatchYear") === false);
// حساب حيّ (dryRun) على ماريا الياس حليحل 1651 إن وُجدت محلياً بنفس معطياتها (إجازة جامعية، درجة دخول 6، ملاك 1/10/2024):
// تشرين 2024 +1 → 7، كانون 2025 +4 → 11، تشرين 2025 +0.5+0.5 → 12، كانون 2026 +4 → 16 (كشفه: 16 لا 15.5)؛ بنهاية 2026-2027: تشرين 2026 +0.5 → 16.5، كانون 2027 +2 → 18.5
$m109 = $db->query("SELECT id, diploma, starting_grade, titularization_date, employee_type FROM employees WHERE id = 1651 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
if ($m109 && $m109['employee_type'] === 'enseignant_titulaire' && $m109['diploma'] === 'ijaza_jamiya' && (float)$m109['starting_grade'] === 6.0 && $m109['titularization_date'] === '2024-10-01') {
    try { $d1 = buildLegalGradeHistory(1651, '2026-09-30', true); $d2 = buildLegalGradeHistory(1651, '2027-09-30', true); $why109 = 'end2526=' . $d1['final_grade'] . ' end2627=' . $d2['final_grade']; }
    catch (Throwable $e) { $d1 = $d2 = null; $why109 = $e->getMessage(); }
    check('4+4+2 (حساب حيّ): ماريا الياس حليحل بالقانون = 16 بنهاية 2025-2026 و18.5 بنهاية 2026-2027 (كشف عبرا)',
          $d1 && $d2 && abs((float)$d1['final_grade'] - 16.0) < 0.01 && abs((float)$d2['final_grade'] - 18.5) < 0.01, $why109);
} else check('4+4+2 (حساب حيّ): ماريا الياس حليحل', true, 'العيّنة غير متاحة محلياً بنفس المعطيات — تخطٍّ');
check('تجهيز 2026-2027 الجزء الثاني: healOpenYear2627b بثلاث مراحل (lawshift لغير المعدَّلين فقط: بلا manual وبلا counted=0 وفرق +0.5 بالضبط) + heal_tick يشغّل الجزأين + footer يعتمد openYearHealPending',
      function_exists('healOpenYear2627b_20260912') && strpos($fn109, "if (\$s['stage'] === 'lawshift')") !== false && strpos($fn109, "if (\$s['stage'] === 'transport_restore')") !== false
      && strpos($fn109, "if (abs(\$gap - 0.5) > 0.01) continue;") !== false && strpos($fn109, "SUM(reason = 'manual') m, SUM(counted = 0 AND reason <> 'titularization') z") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/heal_tick.php'), "if (\$s === null) \$s = healOpenYear2627b_20260912(6.0);") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/footer.php'), "openYearHealPending20260912()): ?>") !== false);

/* =====================================================================
 * 110) 🏆 «أي درجة أو نص درجة أنا بزيدها تثبت ما تتغيّر أبداً بكل البرنامج إلا إذا أنا بدي غيّر» (2026-09-12):
 *      عمود user_edited + gradesUserAdjusted المصدر الواحد + buildLegalGradeHistory لا يعيد البناء آلياً (إلا force من زرّ الصفحة)
 *      + المخالفات لا تعرض «الدرجة ≠ القانون» للمعدَّل + ملف الموظف لا يعيد البناء عند تغيير الشهادة/التواريخ + لوحة الدرجات توسم كل لمسة.
 * =================================================================== */
ensureGradeUserEditedColumn();
$gr110 = (string)file_get_contents($PROJ . '/pages/grades.php'); $pc110 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
check('الدرجات المعدَّلة بيده ثابتة (مصدر): العمود ذاتي التركيب + gradesUserAdjusted + buildLegalGradeHistory(force) يتخطّى المعدَّل + المخالفات/ملف الموظف/الشفاء يحترمونه + لوحة الدرجات توسم (شك-مارك/تاريخ/مقدار/يدوية/وحدات) + زرّ «ابنِ» force',
      (bool)$db->query("SHOW COLUMNS FROM employee_grade_history LIKE 'user_edited'")->fetch() && function_exists('gradesUserAdjusted') && function_exists('markGradeRowsUserEdited')
      && strpos($pc110, 'function buildLegalGradeHistory($empId, $todayOverride = null, $dryRun = false, $force = false)') !== false
      && strpos($pc110, "if (!\$dryRun && !\$force && function_exists('gradesUserAdjusted') && gradesUserAdjusted((int)\$empId)) {") !== false
      && strpos($pc110, "if ((int)(\$r['user_edited'] ?? 0) === 1) return 0;") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/compliance.php'), "if (gradesUserAdjusted((int)\$r['id'])) continue;") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), "&& gradesUserAdjusted((int)\$id)) {") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "|| gradesUserAdjusted(\$id)) continue;") !== false
      && substr_count($gr110, 'markGradeRowsUserEdited(') === 3 && strpos($gr110, 'buildLegalGradeHistory($employeeId, null, false, true)') !== false
      && strpos($gr110, "if ((int)\$r['counted'] !== \$on) \$touched[] = \$rid;") !== false);
// تجربة حيّة (تُرجَع): صفّ يدوي غير محسوب (counted=0 فلا تتغيّر الدرجة) → buildLegalGradeHistory بلا force = تخطٍّ بلا أي حذف؛ dryRun يبقى يحسب؛ المخالفات لا تعرضه
$why110 = ''; $ok110 = false;
try {
    $e110 = $db->query("SELECT e.id, e.current_grade FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND e.status = 'actif'
        AND NOT EXISTS (SELECT 1 FROM employee_grade_history h WHERE h.employee_id = e.id AND (h.reason = 'manual' OR h.user_edited = 1)) AND EXISTS (SELECT 1 FROM employee_grade_history h2 WHERE h2.employee_id = e.id) ORDER BY e.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$e110) { $why110 = 'لا عيّنة'; $ok110 = true; }
    else {
        $eid = (int)$e110['id'];
        $rowsBefore = $db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id = $eid")->fetchColumn();
        $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,notes,user_edited) VALUES (?,0,0,1,0,'2019-01-01','manual','regcheck',1)")->execute([$eid]);
        $rid = (int)$db->lastInsertId();
        try {
            $adj = gradesUserAdjusted($eid);
            $r1 = buildLegalGradeHistory($eid);                 // بلا force ⇒ تخطٍّ
            $rowsAfter = $db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id = $eid")->fetchColumn();
            $cgAfter = (float)$db->query("SELECT current_grade FROM employees WHERE id = $eid")->fetchColumn();
            $dry = buildLegalGradeHistory($eid, null, true);    // dryRun يبقى متاحاً
            $inComp = false; foreach (complianceItems($db, currentSchoolYear()) as $it) if ($it['rule'] === 'grade_law' && (int)$it['emp_id'] === $eid) $inComp = true;
            $ok110 = $adj && !empty($r1['skipped']) && (int)$rowsAfter === (int)$rowsBefore + 1 && abs($cgAfter - (float)$e110['current_grade']) < 0.01 && isset($dry['final_grade']) && !$inComp;
            $why110 = "emp=$eid adj=" . var_export($adj, true) . " skipped=" . var_export(!empty($r1['skipped']), true) . " rows=$rowsBefore→$rowsAfter cg=" . $e110['current_grade'] . "→$cgAfter inComp=" . var_export($inComp, true);
        } finally { $db->exec("DELETE FROM employee_grade_history WHERE id = $rid"); }
    }
} catch (Throwable $e) { $why110 = $e->getMessage(); }
check('الدرجات المعدَّلة بيده ثابتة (تجربة حيّة تُرجَع): بعد لمسة يدوية لا يعيد البرنامج بناء درجاته ولا يعرضه كمخالفة، والحساب التقديري يبقى', $ok110, $why110);

/* =====================================================================
 * 111) 🧑‍🏫 سامر ابونادر (عبرا 840) — «ما عم شوفو» (2026-09-12): شفاء بالاسم مرّة واحدة يمسح تواريخ الترك المستحيلة (= ولادته)،
 *      يضوّي «الصندوق يشمل الإضافي»، يضيف 85٪ + نقل 7,200,000 لـ2026-2027 ويحسبها — متحقَّق على النسخة طبق الأصل: تشرين 2026
 *      أساس 2,545,000 / إضافي 129,000,000 / صندوق 7,892,700 / ضريبة 3,180,661 / صافي 116,871,000 = كشفه بالمليم.
 * =================================================================== */
$fn111 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('سامر ابونادر: الشفاء موجود وموصول بالهيدر، بالاسم، يمسح تواريخ الترك = الولادة أو قبل الدخول فقط، يضوّي الصندوق على الإضافي، ولا يمسّ المكرّرين (الحذف بقراره)',
      function_exists('healSamerAbounader20260912') && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healSamerAbounader20260912();') !== false
      && strpos($fn111, "first_name_ar = 'سامر' AND father_name_ar LIKE 'مارون%' AND last_name_ar LIKE '%ابونادر%'") !== false
      && strpos($fn111, "(\$v === \$bd || (\$hd && \$v < \$hd))") !== false && strpos($fn111, "UPDATE employees SET eoc_includes_extra = 1 WHERE id = \$id") !== false
      && substr_count(substr($fn111, strpos($fn111, 'function healSamerAbounader20260912'), strpos($fn111, 'function healSamerAllYears20260912') - strpos($fn111, 'function healSamerAbounader20260912')), 'is_deleted = 1') === 0);

/* =====================================================================
 * 113) 🧑‍🏫 سامر ابونادر — الجزء الثاني (2026-09-12 «بس بعد ما وصل سامر أونلاين» + «بدو يكون موجود بكل البرنامج» + قراره «1» = محي المكرّرَين):
 *      الشفاء الأوّل أعطاه 2026-2027 فقط. الثاني: بنود 2025-2026 كرفاقه (65٪ + نقل 7,200,000) + كل سنة من ترسيمه 2016-2017 حتى 2025-2026
 *      بالمحرّك (موسومة مدفوعة كرفاقه) + تدرّج تشرين 2026 + حذف ناعم للمكرّرَين الفارغين (نسخة _emp_bk_samerdup0912) — يحترم قفل السنة.
 *      متحقَّق على النسخة طبق الأصل مقابل توأمه 934 (نفس الدخول/الترسيم/الدرجة): كل سنة بالمليم؛ تشرين 2025 = 89,738,000 = كشفه؛ ملاك عبرا تشرين 2025 = 131.
 * =================================================================== */
$fn113 = substr($fn111, strpos($fn111, 'function healSamerAllYears20260912'));
$fn113 = substr($fn113, 0, strpos($fn113, "\nfunction ", 10) ?: null);
check('سامر — الجزء الثاني: الشفاء موجود وموصول بالهيدر، بالاسم، يبدأ من سنة الترسيم، بنود 65٪ + نقل 7,200,000 لـ2025-2026، تدرّج 2026-2027 بالمصدر الواحد، حذف ناعم بنسخة احتياطية للمتعاقدَين الفارغَين فقط، ويحترم القفل',
      function_exists('healSamerAllYears20260912') && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healSamerAllYears20260912();') !== false
      && strpos($fn113, "schoolYearOfDate(\$e['titularization_date'])") !== false
      && strpos($fn113, "[\$id, 'prime_fixe', '2025-2026', 65, 'percent', 10, 9]") !== false && strpos($fn113, "[\$id, 'transport_complement', '2025-2026', 7200000, 'amount', null, null]") !== false
      && strpos($fn113, "applyLegalGradesForNewYear(\$db, \$id, 2026, 2027)") !== false
      && strpos($fn113, "employee_type = 'enseignant_contractuel'") !== false && strpos($fn113, "if ((float)\$d['mx'] > 0 || (int)\$d['nb'] > 0)") !== false
      && strpos($fn113, "INSERT IGNORE INTO _emp_bk_samerdup0912") !== false && substr_count($fn113, 'isSchoolYearLocked($sid, $sy)') === 1);
// تجربة حيّة على قاعدة هذا الجهاز (مرآة الأونلاين): بعد الشفاء سامر موجود بكل سنة من ترسيمه، تشرين 2025 = كشفه، ولا مكرّر فاعل
$why113 = ''; $ok113 = false;
try {
    healSamerAllYears20260912();
    $s113 = $db->query("SELECT id FROM employees WHERE is_deleted = 0 AND employee_type = 'enseignant_titulaire' AND first_name_ar = 'سامر' AND father_name_ar LIKE 'مارون%' AND last_name_ar LIKE '%ابونادر%' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$s113) { $ok113 = true; $why113 = 'لا سامر بهذه القاعدة'; }
    else {
        $sid113 = (int)$s113;
        $yrs = $db->query("SELECT GROUP_CONCAT(DISTINCT school_year ORDER BY school_year) FROM monthly_salaries WHERE employee_id = $sid113")->fetchColumn();
        $oct25 = $db->query("SELECT net_salary_lbp FROM monthly_salaries WHERE employee_id = $sid113 AND year = 2025 AND month = 10")->fetchColumn();
        $dupAct = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND id <> $sid113 AND employee_type = 'enseignant_contractuel' AND first_name_ar = 'سامر' AND last_name_ar LIKE '%نادر%' AND school_id = (SELECT school_id FROM employees WHERE id = $sid113)")->fetchColumn();
        $ok113 = strpos((string)$yrs, '2016-2017') === 0 && strpos((string)$yrs, '2025-2026') !== false && strpos((string)$yrs, '2026-2027') !== false && (int)$oct25 === 89738000 && $dupAct === 0;
        $why113 = "emp=$sid113 years=$yrs oct2025=$oct25 dupsActive=$dupAct | " . mb_substr((string)getSetting('heal_samer_allyears_20260912', ''), 0, 160);
    }
} catch (Throwable $e) { $why113 = $e->getMessage(); }
check('سامر — الجزء الثاني (تجربة حيّة): موجود بكل سنة من 2016-2017 حتى 2026-2027، تشرين 2025 = 89,738,000 = كشفه، والمكرّران غير فاعلَين', $ok113, $why113);

/* =====================================================================
 * 112) 🚪 «انتبه بدك تحطّو بكل البرنامج» (2026-09-12): قاعدة عامّة «تاريخ ترك مستحيل» (= الولادة أو قبل دخول المدرسة) بتقرير
 *      المخالفات (تصحيح آلي: مسح التواريخ + إعادة الحساب، يجوز «موافق على الكل») وبالفحص الرسمي — كل المدارس، لا بالاسم.
 * =================================================================== */
$cp112 = (string)file_get_contents($PROJ . '/includes/compliance.php'); $da112 = (string)file_get_contents($PROJ . '/includes/data_audit.php');
check('تاريخ ترك مستحيل: قاعدة left_impossible بالمخالفات (بانية + تصحيح آلي يمسح التواريخ ويعيد الحساب) + قاعدة بالفحص الرسمي',
      isset(complianceRules()['left_impossible']) && strpos($cp112, "\$add('left_impossible', \$r,") !== false && strpos($cp112, "case 'left_impossible':") !== false
      && strpos($cp112, "array_intersect((array)(\$d['cols'] ?? []), array_keys(leftDateColumns()))") !== false
      && strpos($da112, "\$add('left_impossible',") !== false);
// تجربة حيّة تُرجَع: موظف فاعل بتاريخ ترك = ولادته يظهر بالقاعدة، والتصحيح يمسحه
$why112 = ''; $ok112 = false;
try {
    $e112 = $db->query("SELECT id, school_id, birth_date, hire_date, left_date_cnss, left_date_finance, left_date_eoc FROM employees WHERE is_deleted = 0 AND status = 'actif' AND birth_date IS NOT NULL AND birth_date <> '0000-00-00'
        AND COALESCE(NULLIF(left_date_all,'0000-00-00'), NULLIF(left_date_cnss,'0000-00-00'), NULLIF(left_date_finance,'0000-00-00'), NULLIF(left_date_eoc,'0000-00-00')) IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$e112) { $why112 = 'لا عيّنة'; $ok112 = true; }
    else {
        $eid = (int)$e112['id'];
        $db->exec("UPDATE employees SET left_date_cnss = birth_date WHERE id = $eid");
        try {
            $hit = null; foreach (complianceItems($db, currentSchoolYear()) as $it) if ($it['rule'] === 'left_impossible' && (int)$it['emp_id'] === $eid) $hit = $it;
            $res = $hit ? complianceApply($db, $hit) : 'لم يظهر';
            $after = $db->query("SELECT left_date_cnss FROM employees WHERE id = $eid")->fetchColumn();
            $ok112 = $hit && !empty($hit['auto']) && ($after === null || $after === '') ;
            $why112 = "emp=$eid hit=" . var_export((bool)$hit, true) . " res=$res after=" . var_export($after, true);
        } finally { $db->prepare("UPDATE employees SET left_date_cnss = ?, left_date_finance = ?, left_date_eoc = ? WHERE id = ?")->execute([$e112['left_date_cnss'], $e112['left_date_finance'], $e112['left_date_eoc'], $eid]); }
    }
} catch (Throwable $e) { $why112 = $e->getMessage(); }
check('تاريخ ترك مستحيل (تجربة حيّة تُرجَع): يظهر بالتقرير بتصحيح آلي، والتصحيح يمسح التاريخ', $ok112, $why112);

/* =====================================================================
 * 114) 📗 إكسل الرواتب والأجر الإضافي للمتعاقدين والموظفين (2026-09-12 «بدي ملف إكسل فيه أسماء المتعاقد أو الموظف ومحلّ أنا حطّ الراتب
 *      والأجر الإضافي وعدد الأيام بالأسبوع… وانت بترجع بتوزّعهن على ملفاتهم — خليها أوبسيون زيادة»):
 *      includes/excel_salaries.php (بناء xlsx بـZipArchive · قراءة · مقارنة قديم←جديد · تطبيق + recalc) + pages/excel_salaries.php (نزّل ← ارفع ← معاينة ← طبّق)
 *      + رابط بالقائمة وبصفحة المكافآت الجماعية. الأعمدة ثابتة (المصدر الواحد excelSalariesColumns) ومنها «الإضافي من شهر ← إلى شهر».
 * =================================================================== */
require_once $PROJ . '/includes/excel_salaries.php';
$xsPage = (string)file_get_contents($PROJ . '/pages/excel_salaries.php'); $xsInc = (string)file_get_contents($PROJ . '/includes/excel_salaries.php');
$hdr114 = (string)file_get_contents($PROJ . '/includes/header.php'); $ba114 = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php');
check('إكسل الرواتب: الوحدة (بناء/قراءة/مقارنة/تطبيق) + الصفحة (نزّل/ارفع/معاينة/طبّق بتوكن) + رابط بالقائمة وبالمكافآت الجماعية + الأعمدة الثابتة فيها من←إلى + يحترم القفل + الفئة خيار (المصدر الواحد excelSalariesCats)',
      function_exists('excelSalariesBuild') && function_exists('excelSalariesParse') && function_exists('excelSalariesDiff') && function_exists('excelSalariesApply')
      && isset(excelSalariesColumns()['from'], excelSalariesColumns()['to'], excelSalariesColumns()['days'], excelSalariesColumns()['pct'], excelSalariesColumns()['sal_usd'], excelSalariesColumns()['sal_lbp'])
      && strpos($xsInc, "'types' => ['enseignant_contractuel']") !== false && strpos($xsInc, 'isSchoolYearLocked($schoolId, $sy)') !== false // (2026-09-13) الفئة صارت خياراً: excelSalariesCats
      && strpos($xsInc, 'recalcEmployeeYear($id, $sy)') !== false
      && strpos($xsPage, "\$action === 'download'") !== false && strpos($xsPage, "\$action === 'upload'") !== false && strpos($xsPage, "\$action === 'apply'") !== false
      && strpos($xsPage, "excel_import_' . \$token . '.json'") !== false && strpos($xsPage, 'enctype="multipart/form-data"') !== false
      && strpos($hdr114, 'pages/excel_salaries.php') !== false && strpos($hdr114, "'excel_salaries.php'") !== false && strpos($ba114, 'pages/excel_salaries.php?sch=') !== false);
// تجربة حيّة: بناء الملف لمدرسة ← قراءته ← مقارنته = صفر فروقات (idempotent)؛ ثم تعديل (أجر إضافي بفترة + أيام) ← تطبيق ← تحقّق ← إرجاع بالأداة نفسها ← مطابق للأصل
$why114 = ''; $ok114 = false;
try {
    $sch114 = (int)$db->query("SELECT e.school_id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2025-2026'
        WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type IN ('enseignant_contractuel','employe') AND " . leftDateSql('e.') . " = '9999-12-31'
        GROUP BY e.school_id ORDER BY COUNT(DISTINCT e.id) DESC LIMIT 1")->fetchColumn();
    $sy114 = '2025-2026';
    if (!$sch114) { $ok114 = true; $why114 = 'لا عيّنة'; }
    else {
        $tmp114 = sys_get_temp_dir() . '/reg114_' . uniqid() . '.xlsx';
        file_put_contents($tmp114, excelSalariesBuild($db, $sch114, $sy114));
        $parsed114 = excelSalariesParse($tmp114); @unlink($tmp114);
        $d0 = excelSalariesDiff($db, $sch114, $sy114, $parsed114);
        $before = excelSalariesRows($db, $sch114, $sy114);
        $vict = null; foreach ($before as $r) if ($r['sal_lbp'] !== null || $r['sal_usd'] !== null) { $vict = $r; break; }
        if (!$vict) { $ok114 = count($d0['changes']) === 0 && !$d0['errors']; $why114 = "school=$sch114 rows=" . count($parsed114) . ' roundtrip=' . count($d0['changes']) . ' (لا عيّنة للتعديل)'; }
        else {
            $id114 = (int)$vict['id'];
            $mod = ['id' => $id114, 'pct' => '12.5', 'amt_lbp' => '0', 'amt_usd' => '0', 'from' => 'كانون الثاني', 'to' => '3', 'days' => (string)((($vict['days'] ?? 5) % 7) + 1)];
            $d1 = excelSalariesDiff($db, $sch114, $sy114, [$mod]);
            $r1 = excelSalariesApply($db, $sch114, $sy114, $d1['changes']);
            $after = excelSalariesRows($db, $sch114, $sy114)[$id114];
            $bon = $db->query("SELECT value_type, amount, start_month, end_month FROM employee_bonuses WHERE employee_id = $id114 AND bonus_type = 'prime_fixe' AND school_year = '$sy114' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            $okApply = (float)$after['pct'] === 12.5 && $after['amt_lbp'] === null && $after['amt_usd'] === null && $after['from'] === monthName(1, 'ar') && $after['to'] === monthName(3, 'ar')
                       && (int)$after['days'] === (int)$mod['days'] && count($bon) === 1 && (int)$bon[0]['start_month'] === 1 && (int)$bon[0]['end_month'] === 3;
            // إرجاع بالأداة نفسها: صفّ الأصل كنصوص (مع 0 للإضافي إن كان بلا)
            $restore = ['id' => $id114, 'sal_usd' => $vict['sal_usd'] !== null ? (string)$vict['sal_usd'] : '', 'sal_lbp' => $vict['sal_lbp'] !== null ? (string)$vict['sal_lbp'] : '',
                        'pct' => (string)(float)($vict['pct'] ?? 0), 'amt_lbp' => (string)(float)($vict['amt_lbp'] ?? 0), 'amt_usd' => (string)(float)($vict['amt_usd'] ?? 0),
                        'from' => (string)($vict['from'] ?? ''), 'to' => (string)($vict['to'] ?? ''), 'days' => (string)($vict['days'] ?? '')];
            $d2 = excelSalariesDiff($db, $sch114, $sy114, [$restore]);
            excelSalariesApply($db, $sch114, $sy114, $d2['changes']);
            $final = excelSalariesRows($db, $sch114, $sy114)[$id114];
            $cmp = fn($r) => [$r['sal_usd'], $r['sal_lbp'], (float)($r['pct'] ?? 0), (float)($r['amt_lbp'] ?? 0), (float)($r['amt_usd'] ?? 0), $r['from'], $r['to'], $r['days']];
            $ok114 = count($d0['changes']) === 0 && !$d0['errors'] && count($parsed114) === count($before) && count($d1['changes']) === 1 && $r1['applied'] === 1 && $okApply && $cmp($final) === $cmp($vict);
            $why114 = "school=$sch114 rows=" . count($parsed114) . ' roundtrip=' . count($d0['changes']) . " emp=$id114 apply=" . json_encode($r1) . ' okApply=' . var_export($okApply, true) . ' restored=' . var_export($cmp($final) === $cmp($vict), true);
        }
    }
} catch (Throwable $e) { $why114 = $e->getMessage(); }
check('إكسل الرواتب (تجربة حيّة تُرجَع): بناء ← قراءة ← صفر فروقات، ثم تعديل (12.5٪ كانون2←آذار + أيام) ← تطبيق ← بند واحد بالفترة ← إرجاع بالأداة نفسها = الأصل', $ok114, $why114);

/* =====================================================================
 * 115) 🎓 الترسيم الحكمي بالملاك بعد سنتين تعاقد (2026-09-13 «إذا صرلو الأستاذ سنتين بالمدرسة لازم تالت سنة يصير حكماً بالملاك تلقائياً
 *      وطبّق عليه كل الدرجات حسب القوانين والنسب المئوية المعطاة للملاك بنفس المدرسة — وبس افتح السنة يطلعلي مساج بالأسماء ويكون عندي
 *      خيار وافق أو ما وافق»): includes/cadre_due.php (المرشَّحون = متعاقد تقاضى راتباً بالسنتين السابقتين بمدرسته ودخلها قبل 1/11 من Y1−2 ·
 *      titularizeContractTeacher · قرارات cadre_due بجدول compliance_decisions) + صفحة مراجعة قبل الفتح بopen_year.php + بطاقة بلوحة القيادة
 *      وبصفحة فتح السنة + صمام cadre_from_sy بالمحرّك (سنوات التعاقد السابقة لا تُلمَس) + ملف الأستاذ: صار ملاكاً بيده ⇒ بناء الدرجات.
 * =================================================================== */
require_once $PROJ . '/includes/cadre_due.php';
$cd115 = (string)file_get_contents($PROJ . '/includes/cadre_due.php'); $oy115 = (string)file_get_contents($PROJ . '/pages/open_year.php');
$ix115 = (string)file_get_contents($PROJ . '/index.php'); $pc115 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$hd115 = (string)file_get_contents($PROJ . '/includes/header.php'); $em115 = (string)file_get_contents($PROJ . '/pages/employees.php');
check('الترسيم الحكمي: الوحدة (مرشَّحون/ترسيم/قرارات/مساج/مراجعة) + مراجعة قبل الفتح بopen_year (cadre_reviewed) + تنفيذ بعد الفتح + بطاقة لوحة القيادة وفتح السنة + صمام cadre_from_sy بالمحرّك وrecalc + تركيب ذاتي بالهيدر + قاعدة cadre_due بالتقرير + ملف الأستاذ يبني الدرجات لمن صار ملاكاً بيده',
      function_exists('cadreDueCandidates') && function_exists('titularizeContractTeacher') && function_exists('handleCadreDuePost') && function_exists('renderCadreDuePending') && function_exists('renderCadreDueReview') && function_exists('schoolCadrePercent')
      && strpos($oy115, "empty(\$_POST['cadre_reviewed'])") !== false && strpos($oy115, 'renderCadreDueReview($cdCands, $newYear, $hidden)') !== false
      && strpos($oy115, 'titularizeContractTeacher($db, (int)$c[\'id\'], $newYear, $whoCd)') !== false && strpos($oy115, "cadreDueRecordDecision(\$db, \$c, \$newYear, 'rejected'") !== false
      && strpos($oy115, 'handleCadreDuePost($db, BASE_URL . \'pages/open_year.php\')') !== false && strpos($oy115, 'renderCadreDuePending($cdPend, $cdSy, false') !== false
      && strpos($ix115, 'handleCadreDuePost($db, BASE_URL . \'index.php\')') !== false && strpos($ix115, 'renderCadreDuePending($homeCd, $homeCdSy, true') !== false
      && strpos($pc115, "\$cfs = (string)(\$this->employee['cadre_from_sy'] ?? '')") !== false && strpos($pc115, 'SELECT cadre_from_sy FROM employees WHERE id = ') !== false
      && strpos($hd115, 'cadreDueEnsureColumns(); healJanaRestore20260913(); healCadreNew20260913();') !== false && function_exists('healJanaRestore20260913') && function_exists('healCadreNew20260913') && is_file($PROJ . '/tools/data/rows_1785_pre2627_20260913.json') && strpos($em115, 'UPDATE employees SET cadre_from_sy = ? WHERE id = ?') !== false && strpos($em115, 'cadreDueTemplate($db, currentSchoolId())') !== false && strpos($cd115, 'heal_cadre_new_20260923c') !== false && strpos($cd115, '$tplF = cadreDueTemplate($db, (int)$emp[\'school_id\'])') !== false && isset(complianceRules()['cadre_due']) && function_exists('schoolCadreTransportTemplate') && function_exists('cadreDueApplyTransport') && strpos($cd115, "cadreDueApplyTransport(\$db, \$empId, (int)\$emp['school_id'], \$sy)") !== false
      && strpos($em115, '$becameCadre = (') !== false && strpos($cd115, "isSchoolYearLocked((int)\$emp['school_id'], \$sy)") !== false
      && strpos($cd115, "buildLegalGradeHistory(\$empId, sprintf('%04d-09-30', \$y2), false, true)") !== false && strpos($cd115, 'recalcEmployeeYear($empId, $sy)') !== false);
// تجربة حيّة تُرجَع بالكامل (لقطة + استرجاع): مرشَّح حقيقي لسنة 2026-2027 ← ترسيم ← ملاك من 1/10 + السلسلة + الدرجات (دخول + فورية لغير التعليمية بتشرين + 4 بكانون)
// + نسبة المدرسة (إن وُجدت) + سنواته السابقة بالمليم كما كانت + إعادة حساب 2025-2026 كملاك ممنوعة (الصمام) + مطابق للقانون + لا تكرار + قرار مسجَّل
$why115 = ''; $ok115 = false;
try {
    require_once $PROJ . '/includes/payroll_calculator.php';
    $sy115 = '2026-2027';
    $cands115 = cadreDueCandidates($db, $sy115, null, false, false);
    $c115 = null; foreach ($cands115 as $c) if ($c['can'] && !isSchoolYearLocked((int)$c['school_id'], $sy115)) { $c115 = $c; break; }
    if (!$c115) { $ok115 = true; $why115 = 'لا مرشَّح (' . count($cands115) . ')'; }
    else {
        $id115 = (int)$c115['id'];
        $snapE = $db->query("SELECT * FROM employees WHERE id = $id115")->fetch(PDO::FETCH_ASSOC);
        $snapG = $db->query("SELECT * FROM employee_grade_history WHERE employee_id = $id115")->fetchAll(PDO::FETCH_ASSOC);
        $snapB = $db->query("SELECT * FROM employee_bonuses WHERE employee_id = $id115")->fetchAll(PDO::FETCH_ASSOC);
        $snapM = $db->query("SELECT * FROM monthly_salaries WHERE employee_id = $id115")->fetchAll(PDO::FETCH_ASSOC);
        $prevHash = fn() => md5(json_encode($db->query("SELECT * FROM monthly_salaries WHERE employee_id = $id115 AND school_year < '$sy115' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC)));
        $h0 = $prevHash();
        try {
            $r = titularizeContractTeacher($db, $id115, $sy115, 'regcheck');
            $e = $db->query("SELECT employee_type, titularization_date, salary_input_mode, contract_salary_lbp, base_salary_usd, current_grade, cadre_from_sy FROM employees WHERE id = $id115")->fetch(PDO::FETCH_ASSOC);
            $gh = $db->query("SELECT change_date, reason, delta FROM employee_grade_history WHERE employee_id = $id115 ORDER BY change_date, id")->fetchAll(PDO::FETCH_ASSOC);
            $nTit = count(array_filter($gh, fn($g) => $g['reason'] === 'titularization' && $g['change_date'] === '2026-10-01'));
            $nOrd = count(array_filter($gh, fn($g) => $g['reason'] === 'biennial_promotion' && $g['change_date'] === '2026-10-01'));
            $excJan = array_sum(array_map(fn($g) => (float)$g['delta'], array_filter($gh, fn($g) => $g['change_date'] === '2027-01-01' && $g['reason'] !== 'manual')));
            $mOct = $db->query("SELECT grade_at_month, base_plus_echelon_lbp, prime_fixe_lbp, caisse_amount_lbp, eoc_grade_lbp FROM monthly_salaries WHERE employee_id = $id115 AND year = 2026 AND month = 10")->fetch(PDO::FETCH_ASSOC);
            $tplE = cadreDueTemplate($db, (int)$c115['school_id']);
            // 🏦 صندوق التعويضات ٦٪ + نصف راتب الترسيم بتشرين (إن كان ملاك المدرسة خاضعين) — «ما عملتهن حسم لصندوق التعويضات» 2026-09-13
            $okEoc = !(int)$tplE['eoc_subject'] || ((float)$mOct['caisse_amount_lbp'] > 0 && abs((float)$mOct['caisse_amount_lbp'] - round(((float)$mOct['base_plus_echelon_lbp'] + ((int)$tplE['eoc_includes_extra'] ? (float)$mOct['prime_fixe_lbp'] : 0)) * 0.06)) < 2 && (float)$mOct['eoc_grade_lbp'] >= round((float)$mOct['base_plus_echelon_lbp'] / 2) - 2); // نصف الراتب (+ قيمة الدرجة الفورية إن وُجدت)
            $mJan = $db->query("SELECT grade_at_month FROM monthly_salaries WHERE employee_id = $id115 AND year = 2027 AND month = 1")->fetch(PDO::FETCH_ASSOC);
            $pctRows = $db->query("SELECT amount FROM employee_bonuses WHERE employee_id = $id115 AND school_year = '$sy115' AND bonus_type = 'prime_fixe' AND value_type = 'percent' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            $okPct = $c115['pct'] ? (count($pctRows) === 1 && abs((float)$pctRows[0] - (float)$c115['pct']['pct']) < 0.01) : true;
            $tpl115 = schoolCadreTransportTemplate($db, (int)$c115['school_id'], $sy115);
            $okTr = !$tpl115 || json_encode(cadreDueTransportLines($db, $id115, $sy115)) === json_encode($tpl115['lines']);
            $h1 = $prevHash();
            recalcEmployeeYear($id115, '2025-2026'); (new PayrollCalculator($id115, 11, 2025))->calculateAndSave();
            $h2 = $prevHash();
            $law = lawConsistencyCheckOne($id115);
            $again = applyLegalGradesForNewYear($db, $id115, 2026, 2027);
            $dec = $db->query("SELECT decision FROM compliance_decisions WHERE item_key = 'cadre_due|$id115|$sy115'")->fetchColumn();
            $stillCand = in_array($id115, array_map(fn($x) => $x['id'], cadreDueCandidates($db, $sy115, null, false, false)), true);
            $ok115 = $e['employee_type'] === 'enseignant_titulaire' && $e['titularization_date'] === '2026-10-01' && $e['salary_input_mode'] === 'percent_of_lbp'
                  && (float)$e['contract_salary_lbp'] == 0 && (float)$e['base_salary_usd'] == 0 && $e['cadre_from_sy'] === $sy115
                  && $nTit === 1 && $nOrd === ($c115['immediate'] ? 1 : 0) && abs($excJan - 4.0) < 0.01
                  && $mOct && (float)$mOct['grade_at_month'] == (float)$c115['grade_start'] + ($c115['immediate'] ? 1 : 0) && (float)$mOct['base_plus_echelon_lbp'] > 0
                  && $mJan && (float)$mJan['grade_at_month'] == (float)$c115['grade_start'] + ($c115['immediate'] ? 1 : 0) + 4
                  && $okPct && $okTr && $okEoc && $h0 === $h1 && $h0 === $h2 && $law['ok'] && $again === 0 && $dec === 'approved' && !$stillCand
                  && abs((float)$e['current_grade'] - ((float)$c115['grade_start'] + ($c115['immediate'] ? 1 : 0))) < 0.01;
            $why115 = "emp=$id115 {$c115['name']} dip={$c115['diploma']} gs={$c115['grade_start']} imm={$c115['immediate']} tit=$nTit ord=$nOrd jan=$excJan oct=" . json_encode($mOct) . " janG=" . ($mJan['grade_at_month'] ?? '?')
                    . " pct=" . json_encode($pctRows) . '/' . ($c115['pct']['pct'] ?? '-') . " tr=" . var_export($okTr, true) . " eoc=" . var_export($okEoc, true) . " prevSame=" . var_export($h0 === $h1 && $h0 === $h2, true) . " law=" . var_export($law['ok'], true) . " again=$again dec=$dec cand=" . var_export($stillCand, true);
        } finally {
            // 🔁 استرجاع كامل
            $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$id115]);
            $cols = '`' . implode('`,`', array_keys($snapE)) . '`';
            $db->prepare("INSERT INTO employees ($cols) VALUES (" . implode(',', array_fill(0, count($snapE), '?')) . ")")->execute(array_values($snapE));
            foreach ([['employee_grade_history', $snapG], ['employee_bonuses', $snapB], ['monthly_salaries', $snapM]] as [$tbl, $rows]) {
                $db->prepare("DELETE FROM $tbl WHERE employee_id = ?")->execute([$id115]);
                foreach ($rows as $row) { $cc = '`' . implode('`,`', array_keys($row)) . '`'; $db->prepare("INSERT INTO $tbl ($cc) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")->execute(array_values($row)); }
            }
            $db->prepare("DELETE FROM compliance_decisions WHERE item_key = ?")->execute(["cadre_due|$id115|$sy115"]);
            foreach (['_emp_bk_cadre_due' => 'id', '_gh_bk_cadre_due' => 'employee_id', '_bon_bk_cadre_due' => 'employee_id'] as $bt => $bc) { try { $db->exec("DELETE FROM $bt WHERE $bc = $id115"); } catch (Throwable $t) {} }
            try { $db->exec("DELETE FROM audit_log WHERE action = 'cadre_titularize' AND record_id = $id115"); } catch (Throwable $t) {}
        }
        $eR = $db->query("SELECT * FROM employees WHERE id = $id115")->fetch(PDO::FETCH_ASSOC);
        $ok115 = $ok115 && $eR == $snapE && (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id = $id115")->fetchColumn() === count($snapG)
              && (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $id115")->fetchColumn() === count($snapM);
        $why115 .= ' restored=' . var_export($eR == $snapE, true);
    }
} catch (Throwable $e) { $why115 = $e->getMessage(); }
check('الترسيم الحكمي (تجربة حيّة تُرجَع): متعاقد أكمل سنتين ← ملاك من 1/10/2026 بالسلسلة + الدرجات بالقانون (دخول + فورية + 4 بكانون) + نسبة المدرسة + 🚌 النقل كملاك المدرسة (5 أيام) + 🏦 صندوق التعويضات ٦٪ ونصف راتب الترسيم + سنواته السابقة بالمليم + الصمام يمنع إعادة حساب 2025-2026 + مطابق للقانون + لا تكرار + قرار مسجَّل', $ok115, $why115);
// صفحة المراجعة والمساج تُرسمان بلا خطأ (بمخزن مؤقّت) + صفحة فتح السنة ولوحة القيادة تفتحان
$okR115 = false; $whyR115 = '';
try {
    $cs = cadreDueCandidates($db, '2026-2027', null, false, false);
    ob_start(); renderCadreDueReview(array_slice($cs, 0, 3), '2026-2027', ['action' => 'open', 'new_year' => '2026-2027', 'school_ids' => [2, 4]]); $h1 = ob_get_clean();
    ob_start(); renderCadreDuePending(array_slice($cs, 0, 3), '2026-2027', true, ''); $h2 = ob_get_clean();
    $pg = renderPage('pages/open_year.php', [], []); $ix = renderPage('index.php', [], []);
    $okR115 = (!$cs || (strpos($h1, 'cadre_reviewed') !== false && strpos($h1, 'name="cadre_ok[]"') !== false && strpos($h1, 'name="school_ids[]" value="4"') !== false
                        && strpos($h2, 'cd_approve') !== false && strpos($h2, 'cd_reject') !== false && strpos($h2, 'name="emp_ids[]"') !== false))
             && strpos($hd115, "msa_stay:") !== false && strpos($hd115, "sessionStorage.setItem(key") !== false && strpos($ix115, "index.php#cadreDue") === false
             && strpos($pg, 'FATAL') === false && strpos($pg, 'الملاك حكماً') !== false && strpos($ix, 'FATAL') === false;
    $whyR115 = 'cands=' . count($cs) . ' review=' . strlen($h1) . ' pending=' . strlen($h2) . ' open_year=' . strlen($pg) . ' index=' . strlen($ix);
} catch (Throwable $e) { $whyR115 = $e->getMessage(); }
check('الترسيم الحكمي: صفحة المراجعة (صناديق + حقول الفتح المخفيّة) والمساج (شك مارك قدّام كل أستاذ + وافق/يبقون) يُرسمان + open_year وindex بلا Fatal + 📌 الصفحة ترجع لمكانها بعد أي POST (msa_stay بالهيدر، بلا مرساة)', $okR115, $whyR115);

/* =====================================================================
 * 116) 🖨️📤 أزرار التقارير (2026-09-13 «كبسة احفظها على الكمبيوتر عم تطلع متل طباعة على الورق، والإيميل والواتساب مش شغالين»):
 *      PDF حقيقي بالمتصفّح لأي صفحة (pdf-save.js: buildGenericPdf بتقطيع على حدود الصفوف + أفقي للجداول العريضة + msaPdfBlob)
 *      + زرّ الشريط «PDF — احفظ عالكمبيوتر» + نافذة واتساب (رابط حقيقي + تنزيل الملف) + نافذة إيميل تُرسل من الخادم مع المرفق (pages/send_report.php)
 * =================================================================== */
$ps116 = (string)file_get_contents($PROJ . '/assets/js/pdf-save.js'); $ex116 = (string)file_get_contents($PROJ . '/assets/js/export.js');
$fn116 = (string)file_get_contents($PROJ . '/includes/functions.php'); $ft116 = (string)file_get_contents($PROJ . '/includes/footer.php');
$sr116 = (string)file_get_contents($PROJ . '/pages/send_report.php');
check('أزرار التقارير: PDF حقيقي لأي صفحة (تقطيع على الصفوف + عريض = أفقي + blob للإرسال) + زرّ الشريط + نافذتا واتساب/إيميل + إرسال من الخادم بالمرفق (CSRF + canEdit + SMTP ثم mail)',
      strpos($ps116, 'function buildGenericPdf(area)') !== false && strpos($ps116, 'window.msaPdfBlob = function') !== false && strpos($ps116, "cuts[k] > y + Math.floor(want * 0.45)") !== false
      && strpos($ps116, "rows[i].children.length >= 9") !== false && strpos($ps116, "w.style.overflow = 'visible'") !== false
      && strpos($ex116, 'function shareModal(html)') !== false && strpos($ex116, "'https://wa.me/' + n + '?text='") !== false && strpos($ex116, "pages/send_report.php") !== false && strpos($ex116, 'window.msaPdfBlob()') !== false
      && strpos($fn116, 'onclick="msaSavePdfStart(this)"') !== false && strpos($ft116, 'window.CSRF_TOKEN') !== false
      && strpos($sr116, "verifyCsrf(\$_POST['csrf'] ?? '')") !== false && strpos($sr116, '!canEdit()') !== false && strpos($sr116, 'smtpSendMail($cfg, $to, $subject, $text, $att)') !== false && strpos($sr116, '@mail($to') !== false
      && strpos($sr116, "substr(\$data, 0, 4) !== '%PDF'") !== false);
// الخادم يرفض بلا ملف/بلا CSRF (تجربة حيّة بلا أثر)
$why116 = ''; $ok116 = false;
try {
    $o = renderPage('pages/send_report.php', [], []);
    $j = json_decode(trim($o), true);
    $ok116 = is_array($j) && empty($j['ok']) && !empty($j['msg']);
    $why116 = 'GET→' . substr(trim($o), 0, 80);
} catch (Throwable $e) { $why116 = $e->getMessage(); }
check('إرسال التقرير بالإيميل: الخادم يرفض الطلب غير الصالح برسالة JSON (لا يرسل شيئاً)', $ok116, $why116);

/* =====================================================================
 * 117) 💵 الأجر الإضافي بالدولار وبالليرة معاً لنفس الشخص (2026-09-13 «الأجر الإضافي للمتعاقدين ما بدي متل أنا حطّو ثابت ولا نسبة مئوية —
 *      كل أستاذ بدي أعطيه مبلغ معيّن بالدولار وبالليرة»): سطر لكل عملة بنفس الفترة والمحرّك يجمعهما. بكل نقاط الإدخال:
 *      الإكسل (العمودان G+H معاً، كان يُرفَض) + «مبالغ فردية» (خانتا ل.ل + $ بدل خانة بعملة واحدة) + ملف الأستاذ (سطران) + فورم الأستاذ الجديد (new_extra2).
 * =================================================================== */
$xs117 = (string)file_get_contents($PROJ . '/includes/excel_salaries.php'); $ba117 = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php');
$tf117 = (string)file_get_contents($PROJ . '/pages/teacher_form.php'); $ic117 = (string)file_get_contents($PROJ . '/pages/info_collect.php');
check('الإضافي بالعملتين معاً: الإكسل لا يرفض G+H + «مبالغ فردية» خانتا ل.ل/$ (سطر لكل عملة، حارس العملة يضمّ للّيرة) + فورم الأستاذ الجديد new_extra2 يُقرأ ويُخزَّن prime_fixe + تلميح ملف الأستاذ',
      strpos($xs117, 'بالليرة وبالدولار معاً — حدّد عملة واحدة') === false && strpos($xs117, "if (\$newAu > 0) \$lines[] = ['vt' => 'amount', 'val' => \$newAu, 'cur' => 'USD'") !== false
      && strpos($ba117, "array_key_exists(\$k . '_lbp', \$vals) || array_key_exists(\$k . '_usd', \$vals)") !== false && strpos($ba117, 'name="ind[<?= $eid ?>][<?= $k ?>_usd]"') !== false && strpos($ba117, "['pct' => null, 'lbp' => null, 'usd' => null]") !== false
      && strpos($ba117, "foreach (['LBP', 'USD'] as \$cc) if (\$fin[\$cc] > 0) \$insI->execute") !== false && strpos($ba117, "\$fin['LBP'] += \$fin['USD']") !== false
      && strpos($ba117, "[\$k . '_cur']") === false
      && strpos($tf117, "'new_extra2'") !== false && strpos($tf117, "['new_salary','new_extra','new_extra2','new_aide','new_transport']") !== false
      && strpos($ic117, "'new_extra2' => 'prime_fixe'") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'جزء بالدولار وجزء بالليرة؟') !== false);
// تجربة حيّة تُرجَع: متعاقد ← 100 $ + 5,000,000 ل.ل عبر الإكسل ⇒ سطران فاعلان والمخزّن بالشهر = 5,000,000 + usdToLbp(100, سعر الشهر)
$ok117 = false; $why117 = '';
try {
    $v117 = $db->query("SELECT e.id, e.school_id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2025-2026' AND ms.exchange_rate > 0
        WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_contractuel' AND " . leftDateSql('e.') . " = '9999-12-31'
          AND e.salary_input_mode IN ('direct_usd','direct_lbp') AND (e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
        GROUP BY e.id HAVING COUNT(*) >= 12 ORDER BY e.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$v117) { $ok117 = true; $why117 = 'لا عيّنة'; }
    elseif (isSchoolYearLocked((int)$v117['school_id'], '2025-2026')) { $ok117 = true; $why117 = 'السنة مقفولة — تخطّي'; }
    else {
        $id117 = (int)$v117['id']; $sch117 = (int)$v117['school_id']; $sy117 = '2025-2026';
        $origIds = array_map('intval', $db->query("SELECT id FROM employee_bonuses WHERE employee_id = $id117 AND bonus_type = 'prime_fixe' AND school_year = '$sy117' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
        $before117 = $db->query("SELECT month, extra_lbp + prime_fixe_lbp t, net_salary_lbp n FROM monthly_salaries WHERE employee_id = $id117 AND school_year = '$sy117' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
        $d117 = excelSalariesDiff($db, $sch117, $sy117, [['id' => $id117, 'pct' => '0', 'amt_lbp' => '5000000', 'amt_usd' => '100', 'from' => '', 'to' => '']]);
        $r117 = excelSalariesApply($db, $sch117, $sy117, $d117['changes']);
        $rows117 = $db->query("SELECT value_type, amount, currency, start_month, end_month FROM employee_bonuses WHERE employee_id = $id117 AND bonus_type = 'prime_fixe' AND school_year = '$sy117' AND is_active = 1 ORDER BY currency")->fetchAll(PDO::FETCH_ASSOC);
        $ms117 = $db->query("SELECT month, extra_lbp + prime_fixe_lbp t, exchange_rate r FROM monthly_salaries WHERE employee_id = $id117 AND school_year = '$sy117' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
        $byCur117 = []; foreach ($rows117 as $rw) $byCur117[$rw['currency']] = $rw; // العملة ENUM فترتيبها ليس أبجدياً
        $okRows = count($rows117) === 2 && isset($byCur117['LBP'], $byCur117['USD']) && (float)$byCur117['LBP']['amount'] == 5000000 && $byCur117['LBP']['value_type'] === 'amount'
                  && (float)$byCur117['USD']['amount'] == 100 && $byCur117['USD']['value_type'] === 'amount';
        $okMs = count($ms117) > 0; $bad = '';
        foreach ($ms117 as $m) { $exp = 5000000 + usdToLbp(100, (float)$m['r']); if ((int)$m['t'] !== (int)$exp) { $okMs = false; $bad = "m{$m['month']}: {$m['t']}≠$exp"; break; } }
        // إرجاع: إطفاء أسطر التجربة + تفعيل الأصلية بأرقامها + إعادة الحساب ⇒ الأشهر بالمليم كما كانت
        $db->exec("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = $id117 AND bonus_type = 'prime_fixe' AND school_year = '$sy117'");
        if ($origIds) $db->exec("UPDATE employee_bonuses SET is_active = 1 WHERE id IN (" . implode(',', $origIds) . ")");
        recalcEmployeeYear($id117, $sy117);
        $after117 = $db->query("SELECT month, extra_lbp + prime_fixe_lbp t, net_salary_lbp n FROM monthly_salaries WHERE employee_id = $id117 AND school_year = '$sy117' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
        $restored = ($before117 == $after117);
        $ok117 = !$d117['errors'] && count($d117['changes']) === 1 && $r117['applied'] === 1 && $okRows && $okMs && $restored;
        $why117 = "emp=$id117 school=$sch117 errors=" . count($d117['errors']) . ' rows=' . json_encode($rows117) . " okMs=" . var_export($okMs, true) . " $bad restored=" . var_export($restored, true);
    }
} catch (Throwable $e) { $why117 = $e->getMessage(); }
check('الإضافي بالعملتين (تجربة حيّة تُرجَع): متعاقد ← 100 $ + 5,000,000 ل.ل بالإكسل ⇒ سطران (LBP+USD) والمخزّن كل شهر = 5,000,000 + usdToLbp(100، سعر الشهر) ⇒ إرجاع بالمليم', $ok117, $why117);

/* =====================================================================
 * 118) 🧑‍🏫🧾 إكسل الرواتب والأجر الإضافي — خيار الفئة (2026-09-13 «بدك تعطيني خيار الملاك لوحدون أو المتعاقد أو الموظف أو كلهن»)
 *      + فلتر الضريبة («كمان بدك تحط ميزة خاضع للضريبة / لا يخضع / الكل»): excelSalariesCats/excelSalariesTaxes المصدر الواحد،
 *      الملاك مشمولون بإضافيهم وأيامهم فقط (راتبهم بالسلسلة: خانتاه رمادية، أي رقم يُكتب = تنبيه ولا يُطبَّق، والتطبيق يحذف op الراتب لهم).
 * =================================================================== */
$xs118 = (string)file_get_contents($PROJ . '/includes/excel_salaries.php'); $xp118 = (string)file_get_contents($PROJ . '/pages/excel_salaries.php');
check('إكسل الفئة/الضريبة: المصدر الواحد (cats/taxes/filterLabel) + rows/build/diff تقبل الفئة والضريبة + الصفحة منتقيان (cat/tax) يمرّان بالتنزيل والرفع والتطبيق والمعاينة + الملاك بلا راتب من الإكسل (rows/diff/apply)',
      function_exists('excelSalariesCats') && function_exists('excelSalariesTaxes') && function_exists('excelSalariesFilterLabel')
      && array_keys(excelSalariesCats()) === ['all', 'titulaire', 'contractuel', 'employe'] && array_keys(excelSalariesTaxes()) === ['', 1, 0]
      && strpos($xs118, "function excelSalariesRows(PDO \$db, int \$schoolId, string \$sy, string \$cat = 'all', string \$tax = '')") !== false
      && strpos($xs118, "function excelSalariesBuild(PDO \$db, int \$schoolId, string \$sy, string \$cat = 'all', string \$tax = '')") !== false
      && strpos($xs118, "function excelSalariesDiff(PDO \$db, int \$schoolId, string \$sy, array \$parsed, string \$cat = 'all', string \$tax = '')") !== false
      && strpos($xs118, "AND e.tax_subject = \" . (int)\$tax") !== false
      && strpos($xs118, "if (\$ety === 'enseignant_titulaire') unset(\$ops['salary']);") !== false
      && strpos($xs118, "ملاك — راتبه بالسلسلة والدرجات لا من الإكسل") !== false
      && strpos($xp118, "\$xsCat = excelSalariesCat(") !== false && strpos($xp118, "\$xsTax = excelSalariesTax(") !== false
      && strpos($xp118, "excelSalariesBuild(\$db, \$schoolId, \$schoolYear, \$xsCat, \$xsTax)") !== false && strpos($xp118, "excelSalariesDiff(\$db, \$schoolId, \$schoolYear, \$parsed, \$xsCat, \$xsTax)") !== false
      && strpos($xp118, "excelSalariesRows(\$db, \$schoolId, \$schoolYear, \$xsCat, \$xsTax)") !== false && strpos($xp118, "'cat' => \$xsCat, 'tax' => \$xsTax") !== false
      && substr_count($xp118, '<input type="hidden" name="cat" value="<?= e($xsCat) ?>"><input type="hidden" name="tax" value="<?= e($xsTax) ?>">') === 2
      && strpos($xp118, '<select name="cat"') !== false && strpos($xp118, '<select name="tax"') !== false);
// تجربة حيّة تُرجَع: مدرسة فيها ملاك ومتعاقدون — كل فئة تعطي أنواعها فقط + ذهاب/إياب صفر فروقات + الملاك بلا راتب + راتب مكتوب لملاك = تنبيه بلا op
// + فلتر الضريبة: قلب tax_subject لموظف واحد مؤقتاً ⇒ يظهر وحده بـ«غير الخاضعين» ويغيب عن «الخاضعين» ⇒ إرجاع
// + تطبيق نسبة على ملاك واحد عبر الإكسل ⇒ بند percent واحد ⇒ إرجاع بالمليم
$ok118 = false; $why118 = '';
try {
    $sch118 = (int)$db->query("SELECT e.school_id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2025-2026'
        WHERE e.is_deleted = 0 AND e.status = 'actif' AND " . leftDateSql('e.') . " = '9999-12-31'
        GROUP BY e.school_id HAVING SUM(e.employee_type = 'enseignant_titulaire') > 0 AND SUM(e.employee_type = 'enseignant_contractuel') > 0 ORDER BY COUNT(DISTINCT e.id) DESC LIMIT 1")->fetchColumn();
    $sy118 = '2025-2026';
    if (!$sch118) { $ok118 = true; $why118 = 'لا عيّنة'; }
    else {
        $parts = []; $okCats = true;
        foreach (excelSalariesCats() as $ck => $cv) {
            $rows = excelSalariesRows($db, $sch118, $sy118, $ck);
            $types = array_values(array_unique(array_map(fn($r) => $r['type'], $rows)));
            $tmp = sys_get_temp_dir() . '/reg118_' . $ck . '.xlsx'; file_put_contents($tmp, excelSalariesBuild($db, $sch118, $sy118, $ck));
            $d = excelSalariesDiff($db, $sch118, $sy118, excelSalariesParse($tmp), $ck); @unlink($tmp);
            $titSal = count(array_filter($rows, fn($r) => $r['type'] === 'enseignant_titulaire' && ($r['sal_usd'] !== null || $r['sal_lbp'] !== null)));
            $okC = count($rows) > 0 && !array_diff($types, $cv['types']) && count($d['changes']) === 0 && !$d['errors'] && $titSal === 0;
            if ($ck === 'all') $okC = $okC && count($types) === 3;
            $okCats = $okCats && $okC; $parts[] = "$ck=" . count($rows) . ($okC ? '' : '✗');
        }
        // ملاك كتب راتباً ⇒ تنبيه ولا تغيير
        $tit = null; foreach (excelSalariesRows($db, $sch118, $sy118, 'titulaire') as $r) { $tit = $r; break; }
        $dS = excelSalariesDiff($db, $sch118, $sy118, [['id' => $tit['id'], 'sal_usd' => '500']], 'titulaire');
        $okGuard = count($dS['changes']) === 0 && count($dS['errors']) === 1 && strpos($dS['errors'][0], 'راتبه بالسلسلة') !== false;
        // متعاقد بملف الملاك ⇒ «ليس من الفئة»
        $con = null; foreach (excelSalariesRows($db, $sch118, $sy118, 'contractuel') as $r) { $con = $r; break; }
        $dW = excelSalariesDiff($db, $sch118, $sy118, [['id' => $con['id'], 'pct' => '5']], 'titulaire');
        $okWrong = count($dW['changes']) === 0 && count($dW['errors']) === 1;
        // فلتر الضريبة (قلب مؤقت لموظف واحد ثم إرجاع)
        $cid = (int)$con['id']; $tsOrig = (int)$db->query("SELECT tax_subject FROM employees WHERE id = $cid")->fetchColumn();
        $db->exec("UPDATE employees SET tax_subject = 0 WHERE id = $cid");
        $r0 = excelSalariesRows($db, $sch118, $sy118, 'contractuel', '0'); $r1 = excelSalariesRows($db, $sch118, $sy118, 'contractuel', '1'); $rA = excelSalariesRows($db, $sch118, $sy118, 'contractuel', '');
        $db->exec("UPDATE employees SET tax_subject = $tsOrig WHERE id = $cid");
        $okTax = isset($r0[$cid]) && !isset($r1[$cid]) && isset($rA[$cid]) && count($r0) + count($r1) === count($rA)
                 && (int)$db->query("SELECT tax_subject FROM employees WHERE id = $cid")->fetchColumn() === $tsOrig;
        // تطبيق نسبة على ملاك عبر الإكسل ثم إرجاع بالمليم
        $tid = (int)$tit['id']; $okLive = false; $whyLive = '';
        if (isSchoolYearLocked($sch118, $sy118)) { $okLive = true; $whyLive = 'مقفولة'; }
        else {
            $origIds = array_map('intval', $db->query("SELECT id FROM employee_bonuses WHERE employee_id = $tid AND bonus_type = 'prime_fixe' AND school_year = '$sy118' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
            $msQ = "SELECT month, extra_lbp + prime_fixe_lbp t, net_salary_lbp n, total_due_lbp d FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$sy118' ORDER BY year, month";
            $before = $db->query($msQ)->fetchAll(PDO::FETCH_ASSOC);
            $newPct = ((float)($tit['pct'] ?? 0) == 12.5) ? '15' : '12.5';
            $dL = excelSalariesDiff($db, $sch118, $sy118, [['id' => $tid, 'sal_usd' => '', 'sal_lbp' => '', 'pct' => $newPct, 'amt_lbp' => '0', 'amt_usd' => '0', 'from' => '', 'to' => '', 'days' => '']], 'titulaire');
            $rL = excelSalariesApply($db, $sch118, $sy118, $dL['changes']);
            $bon = $db->query("SELECT value_type, amount FROM employee_bonuses WHERE employee_id = $tid AND bonus_type = 'prime_fixe' AND school_year = '$sy118' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            $salSame = $db->query("SELECT salary_input_mode, base_salary_usd, contract_salary_lbp FROM employees WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
            $db->exec("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = $tid AND bonus_type = 'prime_fixe' AND school_year = '$sy118'");
            if ($origIds) $db->exec("UPDATE employee_bonuses SET is_active = 1 WHERE id IN (" . implode(',', $origIds) . ")");
            recalcEmployeeYear($tid, $sy118);
            $after = $db->query($msQ)->fetchAll(PDO::FETCH_ASSOC);
            $okLive = count($dL['changes']) === 1 && !$dL['errors'] && $rL['applied'] === 1 && count($bon) === 1 && $bon[0]['value_type'] === 'percent' && (float)$bon[0]['amount'] == (float)$newPct && ($before == $after);
            $whyLive = "tit=$tid pct=$newPct bon=" . json_encode($bon) . ' restored=' . var_export($before == $after, true);
        }
        $ok118 = $okCats && $okGuard && $okWrong && $okTax && $okLive;
        $why118 = "school=$sch118 " . implode(' ', $parts) . " guard=" . var_export($okGuard, true) . " wrong=" . var_export($okWrong, true) . " tax=" . var_export($okTax, true) . " live[$whyLive]";
    }
} catch (Throwable $e) { $why118 = $e->getMessage(); }
check('إكسل الفئة/الضريبة (تجربة حيّة تُرجَع): كل فئة أنواعها فقط + ذهاب/إياب صفر + ملاك بلا راتب (رقم مكتوب = تنبيه) + متعاقد بملف الملاك = ليس من الفئة + فلتر الضريبة يعزل غير الخاضع + نسبة لملاك عبر الإكسل = بند percent واحد ⇒ إرجاع بالمليم', $ok118, $why118);

/* =====================================================================
 * 119) ↔️ كشوف الرواتب (لوائح الدولة) من الشمال لليمين (2026-09-13 «قصدي اللوائح اللي منقدّمها للدولة اللبنانية — المالية والضمان وصندوق
 *      التعويضات — بس اللي موجودين بخانة كشوف الرواتب ما عدا بطاقة الأستاذ السنوية ما تقرب صوبها — الاتجاه بس»):
 *      خانة «كشوف الرواتب» بمركز التقارير = salary_all/salary_detail/payment_list/employer_cost/full_register (official_forms، المصدر الواحد
 *      ofStateLtrForms) + annual_totals (reports، docSheetStart dir) ⇒ dir=ltr دائماً. غيرها rtl كما كان. البطاقة السنوية والقسيمة لا تُمَسّ.
 * =================================================================== */
$of119 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp119 = (string)file_get_contents($PROJ . '/pages/reports.php'); $rh119 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$tiles119 = []; if (preg_match("/'tiles'=>\[(.*?)\]\],/su", preg_replace('~^\s*//.*$~mu', '', substr($rp119, strpos($rp119, "'كشوف الرواتب / Bulletins de salaire'"))), $mt)) preg_match_all("/\\\$OF\.'([a-z_]+)'|\?report=([a-z_]+)|\\\$PG\.'([a-z_]+)\.php'/", $mt[1], $mm);
$tiles119 = array_values(array_filter(array_merge($mm[1] ?? [], $mm[2] ?? [], $mm[3] ?? [])));
check('كشوف الرواتب LTR: خانة «كشوف الرواتب» بالمركز = البطاقة السنوية (مستثناة) + 5 نماذج ofStateLtrForms + مجاميع سنوية — كلها dir=ltr بالكود (لا ربط بفلتر الضريبة)، Résumé mensuel rtl، البطاقة السنوية بلا dir=ltr',
      function_exists('ofStateLtrForms') === false /* دالة محلية بالصفحة لا بfunctions */
      && strpos($of119, "function ofStateLtrForms(): array { return ['salary_all', 'salary_detail', 'payment_list', 'employer_cost', 'full_register']; }") !== false
      && strpos($of119, "\$ofDir = in_array(\$form, ofStateLtrForms(), true) ? 'ltr' : 'rtl';") !== false
      && substr_count($of119, 'class="official-doc <?= $ofDir ?>') === 5 && substr_count($of119, 'id="ppExportArea" dir="<?= $ofDir ?>"') === 4 && substr_count($of119, 'id="ppExportArea" style="max-width:100%" dir="<?= $ofDir ?>"') === 1
      && strpos($of119, '$ofLtr') === false && strpos($rp119, '$rsDir') === false
      && strpos($rp119, "docSheetStart('Résumé mensuel', 'كشف رواتب شهري', [monthName(\$month) . ' ' . \$year . \$empTypeTitle], ['month' => \$month, 'year' => \$year]) ?>") !== false // (2026-09-19 + سعر الصرف بالعنوان)
      && preg_match("/docSheetStart\('Totaux annuels par école et par rubrique'.*?\['dir' => 'ltr', 'annual' => true, 'law' => false\]\) \?>/su", $rp119) === 1
      && strpos($rh119, "\$ltr = ((\$opts['dir'] ?? 'rtl') === 'ltr');") !== false && strpos($rh119, '.official-doc.ltr,.official-doc[dir="ltr"]{direction:ltr;text-align:left;}') !== false && strpos($rh119, '.doc-sheet.doc-ltr{direction:ltr;text-align:left;}') !== false
      && sort($tiles119) !== null && $tiles119 === ['annual_slip', 'annual_totals', 'employer_cost', 'full_register', 'payment_list', 'salary_all', 'salary_detail']
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), 'dir="ltr"') === false && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), 'doc-ltr') === false,
      'tiles=' . implode(',', $tiles119));
// تشغيل فعلي: الخمسة + المجاميع السنوية ⇒ ltr؛ Résumé mensuel وكشف الضمان ⇒ rtl؛ بلا Fatal
$ok119 = false; $why119 = '';
try {
    $m119 = (int)$db->query("SELECT month FROM monthly_salaries WHERE school_year = '2025-2026' ORDER BY year, month LIMIT 1")->fetchColumn() ?: 10;
    $y119 = $m119 >= 10 ? 2025 : 2026; $bad119 = [];
    foreach (['salary_all', 'salary_detail', 'payment_list', 'employer_cost', 'full_register'] as $f) {
        $o = renderPage('pages/official_forms.php', ['form' => $f, 'month' => $m119, 'year' => $y119], []);
        if (!preg_match('/class="official-doc ltr[^"]*" id="ppExportArea"[^>]*dir="ltr"/', $o) || stripos($o, 'Fatal error') !== false) $bad119[] = $f;
    }
    $o = renderPage('pages/reports.php', ['report' => 'annual_totals'], []);
    if (strpos($o, '<div class="doc-sheet doc-ltr" dir="ltr">') === false || strpos($o, '<table class="doc-table" dir="ltr">') === false || stripos($o, 'Fatal error') !== false) $bad119[] = 'annual_totals';
    $o = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => $m119, 'year' => $y119], []);
    if (strpos($o, 'class="doc-sheet doc-ltr"') !== false || strpos($o, '<table class="doc-table" dir="rtl">') === false) $bad119[] = 'monthly_summary(rtl)';
    $o = renderPage('pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => $m119, 'year' => $y119], []);
    if (strpos($o, 'official-doc ltr') !== false) $bad119[] = 'cnss(rtl)';
    $ok119 = !$bad119; $why119 = "month=$m119/$y119 bad=" . implode(',', $bad119);
} catch (Throwable $e) { $why119 = $e->getMessage(); }
check('كشوف الرواتب LTR (تشغيل فعلي): الخمسة + المجاميع السنوية dir=ltr، Résumé mensuel والضمان rtl كما كانا، بلا Fatal', $ok119, $why119);

/* ===================================================================
 * 120) 🔤 أسماء الأساتذة بلوائح الدولة LTR بالفرنسي كمان (2026-09-14 «بدي أسماء الأساتذة اللي بالتقارير من الشمال إلى اليمين
 *      يكون باللغة الأجنبية كمان»): خانة الاسم بـsalary_all/payment_list/full_register/salary_detail = العربي + الفرنسي تحته
 *      (المصدر الواحد ofStateNameCell). employer_cost/annual_totals بلا أسماء. غير لوائح الدولة (الضمان/الضريبة/القسيمة/البطاقة) لا تُمَسّ.
 * =================================================================== */
$of120 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rh120 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
check('أسماء لوائح الدولة بالفرنسي (كود): ofStateNameCell مصدر واحد + 3 خلايا + salary_detail عبر drawRow + CSS .nm-fr، بلا اسم فرنسي بغير لوائح الدولة',
      strpos($of120, "function ofStateNameCell(array \$r): string {") !== false
      && substr_count($of120, '<td style="text-align:right"><?= ofStateNameCell($r) ?></td>') === 3
      && strpos($of120, '$drawRow(++$n, ofStateNameCell($r), $v);') !== false
      && substr_count($of120, '<?= $name /* HTML جاهز من ofStateNameCell */ ?>') === 1
      && substr_count($of120, 'ofStateNameCell(') === 5
      && strpos($rh120, '.official-doc .nm-fr{display:block;') !== false
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), 'nm-fr') === false && strpos((string)file_get_contents($PROJ . '/pages/reports.php'), 'ofStateNameCell') === false);
$ok120 = false; $why120 = '';
try {
    $e120 = $db->query("SELECT e.id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr, ms.month, ms.year FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
                        WHERE e.is_deleted = 0 AND ms.school_year = '2025-2026' AND ms.net_salary_lbp > 0 AND TRIM(e.first_name_ar) <> '' AND TRIM(e.first_name_fr) <> '' AND TRIM(e.last_name_fr) <> '' ORDER BY ms.year, ms.month LIMIT 1")->fetch();
    if (!$e120) throw new RuntimeException('لا أستاذ باسم عربي وفرنسي معاً');
    $ar = trim($e120['first_name_ar'] . ' ' . $e120['last_name_ar']); $fr = trim($e120['first_name_fr'] . ' ' . $e120['last_name_fr']);
    $bad120 = [];
    foreach (['salary_all', 'payment_list', 'full_register', 'salary_detail'] as $f) {
        $o = renderPage('pages/official_forms.php', ['form' => $f, 'month' => (int)$e120['month'], 'year' => (int)$e120['year']], [], [], '', '', '', []);
        if (strpos($o, e($ar) . '<br><span class="nm-fr" dir="ltr">' . e($fr) . '</span>') === false || stripos($o, 'Fatal error') !== false) $bad120[] = $f;
    }
    $o = renderPage('pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => (int)$e120['month'], 'year' => (int)$e120['year']], []);
    if (strpos($o, 'class="nm-fr"') !== false) $bad120[] = 'cnss(no-fr)';
    $o = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => (int)$e120['month'], 'year' => (int)$e120['year']], []);
    if (strpos($o, 'class="nm-fr"') !== false) $bad120[] = 'monthly_summary(no-fr)';
    $ok120 = !$bad120; $why120 = "emp={$e120['id']} $ar / $fr bad=" . implode(',', $bad120);
} catch (Throwable $e) { $why120 = $e->getMessage(); }
check('أسماء لوائح الدولة بالفرنسي (تشغيل فعلي): الأربعة تعرض العربي + الفرنسي تحته لأستاذ حقيقي؛ الضمان وResumé mensuel بلا', $ok120, $why120);

/* ===================================================================
 * 121) 📅 إكسل الرواتب = أساتذة السنة لا أساتذة كل البرنامج (2026-09-14 «وقت اللي بختار متعاقد أو ملاك أو الثنين يكونوا أساتذة
 *      نفس السنة بس مش أساتذة كل البرنامج»): فتح السنة نسخ كل «فاعل» بلا تاريخ ترك حتى مَن لم يُدفَع له بالسنة السابقة، فصار
 *      الإكسل يعرضهم. المصدر الواحد excelSalariesInYearSql/Params: مستمرّ (راتب فعلي بالسنة السابقة) | جديد (دخوله منذ بداية
 *      السنة السابقة) | راتب فعلي بالسنة نفسها إن بدأت أو بلا راتب أقدم | جديد كلياً بلا صفوف. لا يلمس فتح السنة ولا التقارير.
 * =================================================================== */
$xs121 = (string)file_get_contents($PROJ . '/includes/excel_salaries.php');
check('إكسل أساتذة السنة (كود): excelSalariesInYearSql/Params مصدر واحد داخل excelSalariesRows (rows/build/diff/apply عبره) + المعايير الأربعة بالـSQL',
      function_exists('excelSalariesInYearSql') && function_exists('excelSalariesInYearParams')
      && strpos($xs121, 'AND " . excelSalariesInYearSql($sy) . "') !== false && strpos($xs121, '$st->execute(array_merge([$schoolId], excelSalariesInYearParams($sy)));') !== false
      && substr_count($xs121, 'excelSalariesInYearSql(') === 2 && strpos($xs121, "OR e.hire_date >= ?") !== false
      && strpos($xs121, "AND (? = 1 OR NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year < ? AND \$nz)))") !== false
      && excelSalariesInYearParams('2026-2027', '2026-09-14') === ['2026-2027', '2025-2026', '2025-10-01', '2026-2027', 0, '2025-2026', '2027-09-30']
      && excelSalariesInYearParams('2026-2027', '2026-10-01')[4] === 1 && excelSalariesInYearParams('2025-2026', '2026-09-14')[4] === 1);
$ok121 = false; $why121 = '';
try {
    $nz121 = '(m.base_plus_echelon_lbp > 0 OR m.net_salary_lbp > 0 OR m.total_due_lbp > 0)';
    $sy121 = currentSchoolYear(); $y121 = (int)substr($sy121, 0, 4); $prev121 = ($y121 - 1) . '-' . $y121; $bad121 = [];
    $actv = "e.is_deleted = 0 AND e.status = 'actif' AND " . leftDateSql('e.') . " = '9999-12-31' AND e.employee_type IN ('enseignant_titulaire','enseignant_contractuel','employe')";
    // أ) راكد: له صفوف بالسنة الحالية، بلا أي راتب فعلي بالسنة السابقة، دخوله قديم ⇒ ليس من السنة (إلا إذا كانت السنة بدأت وله راتب فعلي فيها)
    $stale = $db->query("SELECT e.id, e.school_id FROM employees e WHERE $actv AND e.hire_date < '" . ($y121 - 1) . "-10-01'
        AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$sy121')
        AND NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$prev121' AND $nz121)
        AND NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$sy121' AND $nz121) LIMIT 1")->fetch();
    // ب) مستمرّ: راتب فعلي بالسنة السابقة وصفوف بالحالية ⇒ من السنة
    $cont = $db->query("SELECT e.id, e.school_id FROM employees e WHERE $actv
        AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$sy121')
        AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$prev121' AND $nz121) LIMIT 1")->fetch();
    // ج) السنة السابقة (بدأت وانتهت): كل مَن له راتب فعلي فيها من السنة، حتى بلا راتب بالتي قبلها
    $paidPrev = $db->query("SELECT e.id, e.school_id FROM employees e WHERE $actv
        AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$prev121' AND $nz121)
        AND NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '" . ($y121 - 2) . '-' . ($y121 - 1) . "' AND $nz121) AND e.hire_date < '" . ($y121 - 2) . "-10-01' LIMIT 1")->fetch();
    if ($stale) { $rows = excelSalariesRows($db, (int)$stale['school_id'], $sy121); if (isset($rows[(int)$stale['id']])) $bad121[] = 'stale#' . $stale['id'] . ' مدرج'; } else $bad121[] = 'لا عيّنة راكدة';
    if ($cont) { $rows = excelSalariesRows($db, (int)$cont['school_id'], $sy121); if (!isset($rows[(int)$cont['id']])) $bad121[] = 'cont#' . $cont['id'] . ' غائب'; } else $bad121[] = 'لا عيّنة مستمرّة';
    if ($paidPrev) { $rows = excelSalariesRows($db, (int)$paidPrev['school_id'], $prev121); if (!isset($rows[(int)$paidPrev['id']])) $bad121[] = 'paidPrev#' . $paidPrev['id'] . ' غائب'; }
    // د) لا أحد بالإكسل خارج فلتر الفاعلين/التاركين، ولا أحد بلا صفوف بالسنة إلا الجديد كلياً
    foreach (excelSalariesRows($db, (int)($cont['school_id'] ?? 0), $sy121) as $id => $r) {
        $x = $db->query("SELECT (SELECT COUNT(*) FROM monthly_salaries m WHERE m.employee_id = $id) alln, (SELECT COUNT(*) FROM monthly_salaries m WHERE m.employee_id = $id AND m.school_year = '$sy121') syn FROM dual")->fetch();
        if ((int)$x['syn'] === 0 && (int)$x['alln'] > 0) { $bad121[] = "#$id بلا صفوف بالسنة"; break; }
    }
    $ok121 = !$bad121; $why121 = "sy=$sy121 stale=" . ($stale['id'] ?? '-') . " cont=" . ($cont['id'] ?? '-') . " paidPrev=" . ($paidPrev['id'] ?? '-') . ' bad=' . implode(',', $bad121);
} catch (Throwable $e) { $why121 = $e->getMessage(); }
check('إكسل أساتذة السنة (تشغيل فعلي): الراكد (صفوف بلا راتب فعلي ولا راتب بالسنة السابقة، دخوله قديم) خارج الإكسل؛ المستمرّ داخله؛ مَن دُفع له بالسنة السابقة داخل إكسلها', $ok121, $why121);

/* ===================================================================
 * 122) 🚫 المنقول للسنة الجديدة بلا راتب بالسنة السابقة (2026-09-14 «اوك» على المقترح): قاعدتا مخالفات carried_stale (براتب — واحداً واحداً،
 *      بلا «موافق على الكل») وcarried_zero (صفري — بالجملة) للسنة الحالية فقط، التصحيح = حذف صفوفه بالسنة؛ وفتح السنة لا يعيدها:
 *      openYearCarrySql (راتب فعلي بالسنة السابقة أو دخوله منذ بدايتها؛ مدرسة بلا رواتب سابقة = الشرط القديم) بالمواضع الثلاثة.
 * =================================================================== */
$cp122 = (string)file_get_contents($PROJ . '/includes/compliance.php'); $oy122 = (string)file_get_contents($PROJ . '/pages/open_year.php');
check('منقول بلا راتب سابق (كود): قاعدتان بالتقرير + حذف الصفوف بالتصحيح + الراتبي مستثنى من الجملة + openYearCarrySql بفتح السنة (3 مواضع) للسنة الحالية فقط',
      isset(complianceRules()['carried_stale']) && isset(complianceRules()['carried_zero'])
      && strpos($cp122, "case 'carried_stale': case 'carried_zero':") !== false && strpos($cp122, 'DELETE FROM monthly_salaries WHERE employee_id = ? AND school_year = ?");') !== false
      && strpos($cp122, "&& \$rule !== 'carried_stale'") !== false && strpos($cp122, "&& \$rk !== 'carried_stale'") !== false
      && strpos($cp122, "if (strcmp(\$sy, currentSchoolYear()) >= 0) {") !== false
      && function_exists('openYearCarrySql') && substr_count($oy122, 'openYearCarrySql($db, ') === 3);
$ok122 = false; $why122 = '';
try {
    $sy122 = currentSchoolYear(); $y122 = (int)substr($sy122, 0, 4); $prev122 = ($y122 - 1) . '-' . $y122; $bad122 = [];
    $nz = '(m.base_plus_echelon_lbp > 0 OR m.net_salary_lbp > 0 OR m.total_due_lbp > 0)';
    $st122 = $db->query("SELECT e.id, e.school_id, (SELECT MAX(m.net_salary_lbp) FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$sy122') nm FROM employees e
        WHERE e.is_deleted = 0 AND (e.hire_date IS NULL OR e.hire_date < '" . ($y122 - 1) . "-10-01')
          AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$sy122')
          AND NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$prev122' AND $nz)
          AND EXISTS (SELECT 1 FROM monthly_salaries m JOIN employees e2 ON e2.id = m.employee_id WHERE e2.school_id = e.school_id AND m.school_year = '$prev122' AND $nz) ORDER BY nm DESC LIMIT 1")->fetch();
    $cont122 = $db->query("SELECT e.id, e.school_id FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif'
          AND EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = '$prev122' AND $nz) LIMIT 1")->fetch();
    if ($st122) {
        $_SESSION['active_schools'] = [(int)$st122['school_id']];
        $items = complianceItems($db, $sy122); $found = null;
        foreach ($items as $i) if ((int)$i['emp_id'] === (int)$st122['id'] && in_array($i['rule'], ['carried_stale', 'carried_zero'], true)) $found = $i;
        if (!$found) $bad122[] = 'stale#' . $st122['id'] . ' غير مدرج';
        elseif ($found['rule'] !== ((float)$st122['nm'] > 0 ? 'carried_stale' : 'carried_zero')) $bad122[] = 'rule=' . $found['rule'];
        elseif (!$found['auto'] || strpos($found['fix'], 'حذف أشهره') === false) $bad122[] = 'fix';
        // بالسنة السابقة (بدأت) لا تُطبَّق القاعدة
        foreach (complianceItems($db, $prev122) as $i) if (in_array($i['rule'], ['carried_stale', 'carried_zero'], true)) { $bad122[] = 'prev-year flagged'; break; }
        // فتح السنة: الشرط يستبعده ويُبقي المستمرّ
        $c = openYearCarrySql($db, (int)$st122['school_id'], $y122);
        if ($c === '' || (int)$db->query("SELECT COUNT(*) FROM employees WHERE id = " . (int)$st122['id'] . $c)->fetchColumn() !== 0) $bad122[] = 'openYear يشمل الراكد';
        if ($cont122 && (int)$db->query("SELECT COUNT(*) FROM employees WHERE id = " . (int)$cont122['id'] . openYearCarrySql($db, (int)$cont122['school_id'], $y122))->fetchColumn() !== 1) $bad122[] = 'openYear يستبعد المستمرّ';
        unset($_SESSION['active_schools']);
    } else $bad122[] = 'لا عيّنة';
    $ok122 = !$bad122; $why122 = "sy=$sy122 stale=" . ($st122['id'] ?? '-') . " bad=" . implode(',', $bad122);
} catch (Throwable $e) { $why122 = $e->getMessage(); }
check('منقول بلا راتب سابق (تشغيل فعلي): الراكد يظهر بالقاعدة الصحيحة بالسنة الحالية لا السابقة، وفتح السنة يستبعده ويُبقي المستمرّ', $ok122, $why122);

/* ===================================================================
 * 123) 🔄 زرّ اتجاه الورقة (2026-09-14 «وقت عم اطبع بتضل لاندسكيب ما بيغير على بورتريه بالبرنت»): الاتجاه مثبّت بالـCSS فحوار المتصفّح
 *      يرفض تغييره — زرّ واحد بشريط التصدير (exportToolbar) يقلب أفقي ⇄ عمودي: body.print-portrait/.print-landscape (app.css صفحة
 *      مسمّاة تغلب الافتراضي + --pz-target) + pdf-save.js يتبع الزرّ (msaOrientForced). لا يُحفَظ بين الصفحات. البطاقة السنوية لم تُمَسّ.
 * =================================================================== */
$fn123 = (string)file_get_contents($PROJ . '/includes/functions.php'); $ex123 = (string)file_get_contents($PROJ . '/assets/js/export.js');
$ps123 = (string)file_get_contents($PROJ . '/assets/js/pdf-save.js'); $css123 = (string)file_get_contents($PROJ . '/assets/css/app.css');
check('زرّ اتجاه الورقة (كود): زرّ واحد بشريط التصدير + msaToggleOrient/msaPrintOrient بـexport.js + CSS الصفحتين المسمّاتين بـapp.css + PDF يتبع الزرّ + البطاقة السنوية بلا تغيير',
      substr_count($fn123, 'id="msaOrientBtn" onclick="msaToggleOrient()"') === 1
      && strpos($ex123, 'window.msaToggleOrient = function () {') !== false && strpos($ex123, 'window.msaPrintOrient = function () {') !== false && strpos($ex123, "if (document.querySelector('.land-report, .xls-sheet')) return 'landscape';") !== false
      && strpos($css123, '@page msaPortrait{size:A4 portrait !important;margin:10mm;}') !== false && strpos($css123, '@page msaLandscape{size:A4 landscape !important;margin:8mm;}') !== false
      && strpos($css123, 'body.print-portrait .doc-table{--pz-target:718 !important;}') !== false && strpos($css123, 'body.print-portrait .land-report') !== false && strpos($css123, 'body.print-portrait .xls-sheet') !== false
      && strpos($ps123, "var landscape = window.msaOrientForced ? (window.msaOrientForced === 'landscape') : genericWide(area);") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), '@page { size: A4 landscape; margin: 4mm; }') !== false);
$ok123 = false; $why123 = '';
try {
    $o = renderPage('pages/official_forms.php', ['form' => 'payment_list', 'month' => 10, 'year' => 2025], []);
    $o2 = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], []);
    $ok123 = substr_count($o, 'id="msaOrientBtn"') === 1 && substr_count($o2, 'id="msaOrientBtn"') === 1 && strpos($o, 'assets/js/export.js') !== false && stripos($o, 'Fatal error') === false;
    $why123 = 'btn=' . substr_count($o, 'id="msaOrientBtn"') . '/' . substr_count($o2, 'id="msaOrientBtn"');
} catch (Throwable $e) { $why123 = $e->getMessage(); }
check('زرّ اتجاه الورقة (تشغيل فعلي): يظهر مرّة واحدة بكشف الدفع وبـRésumé mensuel مع export.js', $ok123, $why123);

/* ===================================================================
 * 124) 📄📗 (2026-09-14 «وقت عم نحفظ PDF على الكمبيوتر ما عم تظهر عناوين الصفحة بكل ورقة، وما بقى في طباعة إكسل ولا وورد»):
 *      pdf-save.js: كل ورقة بعد الأولى تعيد ترويسة المستند (ما قبل أوّل جدول) + رأس الجدول (thead) الذي انقطع فيه.
 *      official_forms: الكشوف الجدولية الـ12 (ofOfficeForms) ترجع لها أزرار Excel/Word بالمتصفّح؛ النماذج الرسمية الثابتة بلا.
 * =================================================================== */
$ps124 = (string)file_get_contents($PROJ . '/assets/js/pdf-save.js'); $of124 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('PDF بعناوين بكل ورقة + إكسل/وورد للكشوف (كود): headEnd/thead بالتقطيع + ofOfficeForms مصدر واحد لـno_office',
      strpos($ps124, "var headEnd = firstTbl ? Math.max(0, firstTbl.getBoundingClientRect().top - top0) : 0;") !== false && strpos($ps124, "var fy = canvas.height / areaHm;") !== false && strpos($ps124, "cuts = cuts.map(P).filter(") !== false
      && strpos($ps124, "var repH = idx > 0 ? headEnd : 0, thTop = 0, thH = 0;") !== false
      && strpos($ps124, "if (repH > 0) { cc.drawImage(canvas, 0, 0, canvas.width, repH, 0, 0, canvas.width, repH); oy += repH; }") !== false
      && strpos($ps124, "if (thH > 0) { cc.drawImage(canvas, 0, thTop, canvas.width, thH, 0, oy, canvas.width, thH); oy += thH; }") !== false
      && strpos($ps124, "doc.addImage(c.toDataURL('image/jpeg', canvas.height > 9000 ? 0.8 : 0.92), 'JPEG', M, M, boxW, c.height * scale);") !== false
      && strpos($of124, "function ofOfficeForms(): array { return ['salary_all', 'salary_detail', 'payment_list', 'employer_cost', 'full_register', 'teaching_staff', 'eoc_staff', 'eoc_quarterly', 'differences', 'general_report', 'staff_stats', 'general_info']; }") !== false
      && strpos($of124, "\$exportOpts['no_office'] = !in_array(\$form, ofOfficeForms(), true);") !== false);
$ok124 = false; $why124 = '';
try {
    $bad124 = [];
    foreach (['salary_all', 'payment_list', 'full_register', 'staff_stats'] as $f) {
        $o = renderPage('pages/official_forms.php', ['form' => $f, 'month' => 10, 'year' => 2025], []);
        if (substr_count($o, 'onclick="ppExcel(') !== 1 || substr_count($o, 'onclick="ppWord(') !== 1 || substr_count($o, 'id="ppExportArea"') !== 1) $bad124[] = $f;
    }
    foreach (['tax_r6t', 'cnss_annual'] as $f) {
        $o = renderPage('pages/official_forms.php', ['form' => $f, 'month' => 10, 'year' => 2025], []);
        if (strpos($o, 'onclick="ppExcel(') !== false || strpos($o, 'onclick="ppWord(') !== false) $bad124[] = $f . '(office!)';
    }
    $ok124 = !$bad124; $why124 = 'bad=' . implode(',', $bad124);
} catch (Throwable $e) { $why124 = $e->getMessage(); }
check('إكسل/وورد للكشوف (تشغيل فعلي): زرّا Excel/Word مرّة واحدة بالكشوف الجدولية، وغائبان بالنماذج الرسمية الثابتة', $ok124, $why124);

/* ===================================================================
 * 125) 💵 «p1 لازم يكون أساس الراتب والراتب بعد التدرّج على أساس دولار 1500 — انتبه» (2026-09-14): دولار الأساس/الدرجة/بعد التدرّج
 *      بكل الكشوف والتقارير = ÷ السعر الرسمي (1500) داون — المصدر الواحد lawUsd/lawUsdSql/moneyLaw/composedSalaryUsd. الصافي والمحسومات
 *      والنقل تبقى بسعر الشهر. البطاقة السنوية كانت أصلاً ÷1500 (cur_sal_old_usd) ولم تُمَسّ.
 * =================================================================== */
$of125 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp125 = (string)file_get_contents($PROJ . '/pages/reports.php');
$mp125 = (string)file_get_contents($PROJ . '/pages/monthly_payroll.php'); $rh125 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
check('دولار القانون للأساس (كود): lawUsd/lawUsdSql/moneyLaw/composedSalaryUsd + لا بقايا تحويل الأساس بسعر الشهر بالكشوف/التقارير/الراتب الشهري/المجاميع السنوية',
      function_exists('lawUsd') && function_exists('lawUsdSql') && function_exists('moneyLaw') && function_exists('composedSalaryUsd')
      && lawUsd(3445000) === 2296.0 && lawUsd(1499) === 0.0 && lawUsdSql('x') === 'FLOOR((x)/1500)'
      && strpos($of125, "money(\$r['base_salary_lbp'], \$rRate") === false && strpos($of125, "money(\$r['echelon_value_lbp'], \$rRate") === false && strpos($of125, "money(\$r['base_plus_echelon_lbp'], \$rRate") === false
      && strpos($of125, "lbpToUsd(composedSalaryLbp(") === false && substr_count($of125, "base_plus_echelon_lbp/NULLIF(") === 3 && substr_count($of125, "AS bpe_usd_mkt,") === 1 && substr_count($of125, ") bpe_usd_mkt,") === 2 && strpos($of125, "base_salary_lbp/NULLIF") === false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "function composedSalaryUsd(array \$row): float { return lbpToUsd(composedSalaryLbp(\$row), rowRate(\$row)); }") !== false
      && strpos($of125, "foreach (['base','ech','bpe'] as \$uk) \$add[\$uk.'_usd'] = lawUsdRow(\$r, \$uk, \$add[\$uk]);") !== false
      && strpos($of125, "\$fmtL = fn(\$v) => (int)\$v ? moneyLaw((int)\$v, ['withCur'=>false]) : '0';") !== false
      && strpos($rp125, "money(\$r['base_salary_lbp'], \$r") === false && strpos($rp125, "lbpToUsd((int)\$r['base_salary_lbp']") === false && strpos($rp125, "lbpToUsd(composedSalaryLbp(") === false
      && substr_count($rp125, "dualFromUsd(composedSalaryLbp(\$r), composedSalaryUsd(\$r))") === 4 && strpos($rp125, "money(composedSalaryLbp(") === false && strpos($of125, "money(composedSalaryLbp(") === false
      && strpos($mp125, "money(\$salary['base_salary_lbp']") === false && substr_count($mp125, "moneyLaw(\$salary['base_plus_echelon_lbp'], [], \$salary, 'bpe')") === 2
      && strpos($rh125, "THEN ' . lawUsdSql('ms.base_plus_echelon_lbp') . ' ELSE 0 END)'") !== false && strpos($rh125, "\$u('ms.base_salary_lbp')") === false && strpos($rh125, "\$r['composed_usd'] = (float)\$r['bpe_usd_mkt'] + (\$hasE") !== false
      && strpos((string)file_get_contents($PROJ . '/includes/annual_slip_data.php'), "'cur_sal_old_usd' => (int)floor(\$curSal / officialUsdRate())") !== false);
$ok125 = false; $why125 = '';
try {
    $r125 = $db->query("SELECT ms.*, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE ms.school_year = '2025-2026' AND ms.base_salary_lbp > 0 AND ms.exchange_rate > 2000 AND e.is_deleted = 0 ORDER BY ms.year, ms.month, e.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r125) throw new RuntimeException('لا صفّ');
    $lawB = number_format(lawUsd($r125['base_salary_lbp'])); $mktB = number_format(lbpToUsd($r125['base_salary_lbp'], $r125['exchange_rate']));
    $bad125 = [];
    $_SESSION['display_currency'] = 'both';
    foreach ([['pages/official_forms.php', ['form' => 'payment_list']], ['pages/official_forms.php', ['form' => 'salary_all']], ['pages/official_forms.php', ['form' => 'salary_detail']],
              ['pages/official_forms.php', ['form' => 'full_register']], ['pages/reports.php', ['report' => 'monthly_summary']], ['pages/reports.php', ['report' => 'cnss_summary']]] as [$pg, $g]) {
        $o = renderPage($pg, $g + ['month' => (int)$r125['month'], 'year' => (int)$r125['year']], [], [], 'both');
        $seg = ''; $i = false; // الاسم بالعربي (تقارير المركز) أو بالفرنسي (لوائح الدولة)
        foreach ([trim($r125['first_name_ar'] . ' ' . $r125['last_name_ar']), trim($r125['first_name_fr'] . ' ' . $r125['last_name_fr'])] as $nm) { if ($nm !== '' && ($i = strpos($o, $nm)) !== false) break; }
        if ($i !== false) $seg = substr($o, $i, 1500);
        // 📄 (2026-09-21 كالبطاقة) الأساس بالليرة فقط؛ صاحب النسبة وحده يظهر ÷1500 تحت «بعد التدرّج»
        $isP125 = isPctLawRow($r125); $bpeL125 = number_format(lawUsd($r125['base_plus_echelon_lbp']));
        if ($seg === '' || strpos($seg, number_format((int)$r125['base_salary_lbp'])) === false || (!$isP125 && strpos($seg, '$' . $lawB . '<') !== false) || ($isP125 && strpos($seg, '$' . $bpeL125) === false)) $bad125[] = ($g['form'] ?? $g['report']);
        if (stripos($o, 'Fatal error') !== false) $bad125[] = ($g['form'] ?? $g['report']) . '(fatal)';
    }
    unset($_SESSION['display_currency']);
    $ok125 = !$bad125; $why125 = "emp={$r125['employee_id']} base={$r125['base_salary_lbp']} law=\$$lawB mkt=\$$mktB bad=" . implode(',', $bad125);
} catch (Throwable $e) { $why125 = $e->getMessage(); }
check('دولار القانون للأساس (تشغيل فعلي): أساس أوّل أستاذ يظهر ÷1500 لا بسعر الشهر بكشف الدفع/كل الموظفين/التفصيلي/الشامل/Résumé mensuel/الضمان', $ok125, $why125);

/* ===================================================================
 * 126) 🏷️ «ليش ما بتخلّي سعر صرف الدولار يبيّن كمان بالعناوين» (2026-09-14): rateHead('law') تحت رؤوس الأساس/الدرجة/بعد التدرّج/
 *      الأجر الإجمالي/المركّب (1 $ = 1,500) وrateHead('mkt',$month,$year) تحت الصافي/المستحق/مجموع المدفوعات/الكلفة (1 $ = سعر الشهر)
 *      بكل الكشوف الجدولية ومركز التقارير؛ يختفي بوضع «ليرة فقط»؛ التقرير العام السنوي بلا سعر شهر.
 * =================================================================== */
$of126 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp126 = (string)file_get_contents($PROJ . '/pages/reports.php'); $rh126 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
check('سعر الصرف بالعناوين (كود): rateHead مصدر واحد + 15 law (أساس/درجة/بعد التدرّج) و13 mkt (المركّب/الإجمالي/الصافي/المستحق) بالنماذج + 6/5 بالمركز + CSS .rate-head + الفروقات والتقرير العام السنويان بلا سعر شهر تحت المركّب',
      function_exists('rateHead') && strpos($rh126, '.doc-table th .rate-head{display:block;') !== false
      && substr_count($of126, "<?= rateHead('law') ?></th>") === 4 /* 📄 2026-09-21: «بعد التدرّج» فقط كالبطاقة */ && substr_count($of126, "<?= rateHead('mkt', \$month, \$year) ?></th>") === 10 && substr_count($of126, ". rateHead('mkt', \$month, \$year)) ?>") === 6 /* رؤوس المستحق عبر dueHead (2026-09-19) + الصافي+العائلي عبر netFamHead (2026-09-20) */
      && substr_count($of126, "</small><?= rateHead('mkt', \$month, \$year) ?></th>") === 5 && substr_count($of126, "</small><?= rateHead('law') ?></th>") === 0 && strpos($of126, "<th>الأجر الإجمالي<?= rateHead('mkt', \$month, \$year) ?></th>") !== false
      && substr_count($rp126, "<?= rateHead('law') ?></th>") === 1 && substr_count($rp126, "<?= rateHead('mkt', \$month, \$year) ?></th>") === 5 && substr_count($rp126, "</small><?= rateHead('mkt', \$month, \$year) ?></th>") === 4
      && strpos($of126, "<th><?= \$grBi('Salaire après échelon', 'الراتب بعد التدرّج') ?><?= rateHead('law') ?></th>") !== false
      && rateHead('law') === '<br><small class="rate-head" dir="ltr">1 $ = ' . number_format(officialUsdRate(), 0, '.', ',') . '</small>');
$ok126 = false; $why126 = '';
try {
    $bad126 = [];
    $o = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], [], [], 'both');
    $mk = number_format((float)getExchangeRate(10, 2025), 0, '.', ',');
    if (substr_count($o, 'rate-head" dir="ltr">1 $ = 1,500') !== 1 || substr_count($o, 'rate-head" dir="ltr">1 $ = ' . $mk) !== 4) $bad126[] = 'salary_all(both)'; // 4 = المركّب/الصافي/الصافي+العائلي (2026-09-20)/المدفوعات
    $o = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], [], [], 'lbp');
    if (strpos($o, 'class="rate-head"') !== false) $bad126[] = 'salary_all(lbp يعرض)'; // (نصّ CSS يحوي rate-head — نفحص الصنف بالخلية)
    $o = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], [], [], 'both');
    if (substr_count($o, 'rate-head" dir="ltr">1 $ = 1,500') !== 1 || substr_count($o, 'rate-head" dir="ltr">1 $ = ' . $mk) !== 3) $bad126[] = 'monthly_summary'; // 3 = المركّب/الصافي/الصافي+العائلي (2026-09-20)
    $ok126 = !$bad126; $why126 = "mkt=$mk bad=" . implode(',', $bad126);
} catch (Throwable $e) { $why126 = $e->getMessage(); }
check('سعر الصرف بالعناوين (تشغيل فعلي): كشف كل الموظفين 3×1,500 (أساس/درجة/بعد التدرّج) + 3×سعر الشهر (المركّب/الصافي/المدفوعات)، يختفي بوضع الليرة، Résumé mensuel 3 + 2', $ok126, $why126);

/* =====================================================================
 * 127) 🖥️ «في تقارير وقت بدي شوفها على شاشة الكمبيوتر ما بتطلع كلها قبل الطبع — أوعى تخرب البطاقة السنوية» (2026-09-15):
 *      الجدول الأعرض من حاويته يصغّر نفسه على الشاشة (msaFitScreenTables بapp.js: zoom = عرض الحاوية ÷ عرضه، حدّ 0.5)
 *      فتظهر كل أعمدته دفعة واحدة؛ الطباعة/PDF على --pz (!important) ووورد/إكسل يمسحان zoom؛ doc-view أوسع (1600)؛
 *      قياس الجداول العادية للطباعة بالقواعد المقلوبة (كالبطاقة) وبخصم حشوة البطاقة (عمود الحالة كان يُقصّ على الورق)
 *      + البطاقة وحاوية الجدول لا تقصّان بالطباعة. 🔒 البطاقة السنوية (.salary-slip) مستثناة من كل ذلك.
 *      🧮 «بالتقارير حطّ تحت عنوان الأجر الإضافي قديش النسبة الحاطينها»: extraPctHead تحت رأس العمود بكل الكشوف
 *      (extraAideHeads بصفوف/شهر/سنة) — الأكثر شيوعاً أوّلاً لغاية 4 نِسَب ثم «…».
 * =================================================================== */
$js127 = (string)file_get_contents($PROJ . '/assets/js/app.js'); $css127 = (string)file_get_contents($PROJ . '/assets/css/app.css');
$rh127 = (string)file_get_contents($PROJ . '/includes/report_helpers.php'); $of127 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp127 = (string)file_get_contents($PROJ . '/pages/reports.php');
check('الجدول العريض على الشاشة (كود): msaFitScreenTables بapp.js (table/doc-table/xlsf، يستثني .salary-slip والقسيمة والإفادات، حدّ 0.5) + تُستدعى من initStickyHeads وfitDocTables + doc-view 1600',
      strpos($js127, 'window.msaFitScreenTables = function ()') !== false
      && strpos($js127, "querySelectorAll('table.table, table.doc-table, table.xlsf')") !== false
      && strpos($js127, "t.closest('.salary-slip, .payslip-card, [data-fit1], .no-print, .ba-overlay, .modal, [role=\"dialog\"]')") !== false
      && strpos($js127, 'Math.max((avail - 2) / natW, 0.5).toFixed(3)') !== false
      && strpos($js127, 'window.msaFitScreenTables();   // 🖥️') !== false
      && strpos($rh127, 'if (window.msaFitScreenTables) window.msaFitScreenTables();') !== false
      && strpos($css127, 'body.doc-view .page-content { max-width: 1600px; margin: 0 auto; }') !== false);
check('الجدول العريض على الشاشة: الطباعة وPDF لا يرثان تصغير الشاشة (--pz !important للجداول العادية وdoc-table، xlsf zoom:1) + وورد/إكسل يمسحان zoom',
      strpos($css127, '.table { zoom: var(--pz, 1) !important; }') !== false
      && strpos($rh127, '@media print{ .doc-table{zoom:var(--pz,1) !important;} }') !== false
      && strpos($rh127, '@media print{ table.xlsf{zoom:1 !important;} }') !== false
      && strpos((string)file_get_contents($PROJ . '/assets/js/export.js'), "querySelectorAll('[style*=\"zoom\"]').forEach(function (n) { n.style.zoom = ''; })") !== false);
check('طباعة الجداول العادية: القياس بالقواعد المقلوبة + خصم حشوة البطاقة + أمان 0.96 + البطاقة وحاوية الجدول لا تقصّان على الورق',
      preg_match('/var flipped1 = \[\];.*?rule1\.media\.mediaText = \'all\'/s', $js127) === 1
      && strpos($js127, 'if (over1 > 0 && over1 < target / 2) target -= over1;') !== false
      && strpos($js127, 'var pz = (target * 0.96) / natW;') !== false
      && strpos($js127, "t.style.zoom = '';                                    // تصغير الشاشة") !== false
      && strpos($css127, '.card, .table-wrapper { overflow: visible !important; }') !== false);
check('🔒 البطاقة السنوية لم تُمَسّ (annual_slip.php وقياسها ٢-أ بapp.js كما هما)',
      strpos($js127, '// (٢-أ) 🔒 البطاقات السنوية تُقاس **بشروط الطباعة الحقيقية** لا بتنسيق الشاشة:') !== false
      && strpos($js127, 'var twS = 1085, thS = 710, k;') !== false
      && strpos($css127, '.payslip-card, .salary-slip { zoom: var(--pz, 1); }') !== false
      && strpos((string)file_get_contents($PROJ . '/pages/annual_slip.php'), 'class="salary-slip-table') !== false);
check('نسبة الأجر الإضافي تحت العنوان (كود): extraPctHead مصدر واحد + extraAideHeads بصفوف/شهر/سنة بكل مواقع النداء (4 بالمركز + 9 بالنماذج) + الرأسان الحرفيان (ضريبة الأستاذ/الصندوق الفصلي)',
      function_exists('extraPctHead') && function_exists('extraAideHeads')
      && substr_count($rp127, "extraAideHeads('', \$data, \$month, \$year)") === 4
      && substr_count($of127, 'extraAideHeads(') === 8 && substr_count($of127, 'extraAideHeads()') === 0 && substr_count($of127, "extraAideHeads(' rowspan=\"2\"')") === 0
      && substr_count($of127, 'extraPctHead(') === 3 /* +التقرير العام برأسه الثنائي (2026-09-17) */
      && strpos($rh127, "implode(' / ', array_slice(\$keys, 0, 4)) . ' %' . (count(\$keys) > 4 ? ' …' : '')") !== false);
$ok127 = false; $why127 = '';
try {
    $db127 = getDB();
    // مدرسة واحدة بنسبة موحّدة: المتوقّع من القاعدة نفسها (الأكثر شيوعاً أوّلاً) — تشرين الأوّل 2025 (سنة 2025-2026)
    $st127 = $db127->query("SELECT b.amount, COUNT(DISTINCT b.employee_id) n FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id
        WHERE b.bonus_type='prime_fixe' AND b.is_active=1 AND b.value_type='percent' AND (b.school_year IS NULL OR b.school_year='2025-2026') AND e.is_deleted=0 AND e.school_id=4
          AND (b.start_month IS NULL OR b.end_month IS NULL OR ((b.start_month<=b.end_month AND 10 BETWEEN b.start_month AND b.end_month) OR (b.start_month>b.end_month AND (10>=b.start_month OR 10<=b.end_month))))
        GROUP BY b.amount ORDER BY n DESC, b.amount DESC");
    $exp = []; foreach ($st127->fetchAll(PDO::FETCH_ASSOC) as $r) $exp[] = rtrim(rtrim(number_format((float)$r['amount'], 2, '.', ''), '0'), '.');
    $expTxt = $exp ? (implode(' / ', array_slice($exp, 0, 4)) . ' %' . (count($exp) > 4 ? ' …' : '')) : '';
    $bad = [];
    foreach ([['pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025]],
              ['pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025]],
              ['pages/official_forms.php', ['form' => 'payment_list', 'month' => 10, 'year' => 2025]],
              ['pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => 10, 'year' => 2025]]] as $pg) {
        $o = renderPage($pg[0], $pg[1], ['extra', 'aide', 'transport'], [4], 'both');
        $has = strpos($o, 'الأجر الإضافي<br><small class="rate-head" dir="ltr">' . $expTxt . '</small>') !== false;
        if (!$has || $expTxt === '') $bad[] = $pg[1]['report'] ?? $pg[1]['form'];
    }
    // بوضع «ليرة فقط» النسبة تبقى (ليست سعر صرف)
    $o = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [4], 'lbp');
    if (strpos($o, 'rate-head" dir="ltr">' . $expTxt . '</small>') === false) $bad[] = 'lbp-mode';
    $ok127 = !$bad; $why127 = "exp=$expTxt bad=" . implode(',', $bad);
} catch (Throwable $e) { $why127 = $e->getMessage(); }
check('نسبة الأجر الإضافي تحت العنوان (تشغيل فعلي): مدرسة 4 تشرين 2025 — Résumé mensuel + كشف كل الموظفين + كشف الدفع + الضمان الاسمي يعرضون نسبة القاعدة نفسها، وتبقى بوضع الليرة', $ok127, $why127);

/* =====================================================================
 * 128) 👨‍👩‍👧 «p1 بهيدا التقرير مافي عامود للتنزيل العائلي» (2026-09-15): الثلاثية الملزمة (التنزيل حصّة الشهر ←
 *     الخاضع بعد الحسم ← الضريبة) صارت بكل كشف شهري فيه ضريبة: «معلومات تفصيلية عن الراتب» (salary_detail)
 *     + «الكشف الشامل بالكلفة» (full_register) + Résumé mensuel (شاشة + Excel/Word) — إضافةً لكشف الضريبة
 *     وكشف كل الموظفين القديمَين — كلها على مصدر واحد familyDedMonthShare/taxableAfterFamilyDed/familyDedHeads.
 * =================================================================== */
$rh128 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$of128 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
$rp128 = (string)file_get_contents($PROJ . '/pages/reports.php');
$rx128 = (string)file_get_contents($PROJ . '/pages/reports_export.php');
check('التنزيل العائلي بكل الكشوف (كود): المصدر الواحد بreport_helpers + الرأسان بالترتيب الملزم',
      function_exists('familyDedSelectCols') && function_exists('familyDedMonthShare') && function_exists('taxableAfterFamilyDed') && function_exists('familyDedHeads')
      && strpos($rh128, "return '<th' . \$attrs . '>التنزيل العائلي<br><small style=\"font-weight:400\">حصّة الشهر</small></th>'") !== false
      && strpos($rh128, "if ((int)(\$r['income_tax_lbp'] ?? 0) + \$txb === 0) return 0;") !== false
      && substr_count($of128, "familyDedSelectCols('e')") === 2 && substr_count($of128, 'familyDedHeads()') === 2
      && substr_count($rp128, "familyDedSelectCols('e')") === 1 && substr_count($rp128, 'familyDedHeads()') === 1
      && substr_count($rx128, "familyDedSelectCols('e')") === 1
      && strpos($rx128, "'التنزيل العائلي (حصّة الشهر)', 'الراتب الخاضع (بعد حسم التنزيل)', 'الضريبة'") !== false
      && strpos($rx128, "[\$v['cnss'], \$v['caisse'], \$v['eocg'], \$v['fded'], \$v['txb'], \$v['tax'], \$v['net'], \$v['fam']]") !== false
      && strpos($of128, "(\$multiS?17:16) + compColsCount()") !== false && strpos($rp128, "(\$multi?18:17) + compColsCount()") !== false);
// تجربة فعلية: مارسيلا (1677) بحزيران 2026 مدرسة 2 — بالكشوف الثلاثة الجديدة نفس حصّة كشف الضريبة (قسم 42)
// ثم الخاضع بعد حسمها بهذا الترتيب، وعدد خلايا صفّها = عدد رؤوس الجدول (لا رأس بلا خلية)
$ok128 = isset($share42, $after42) && $share42 > 0; $why128 = [];
$cells128 = function (string $html, string $needle): array {
    $p = mb_strpos($html, $needle); if ($p === false) return [];
    $s = mb_strrpos(mb_substr($html, 0, $p), '<tr'); $e = mb_strpos($html, '</tr>', $p);
    preg_match_all('/<td[^>]*>(.*?)<\/td>/su', mb_substr($html, $s, $e - $s), $m);
    return array_map(fn($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))), $m[1]);
};
$heads128 = function (string $html): int {
    if (!preg_match('/<thead>.*?<\/thead>/su', $html, $mh)) return 0;
    preg_match_all('/<th\b([^>]*)>/su', $mh[0], $mt);
    $n = 0; foreach ($mt[1] as $attrs) { if (strpos($attrs, 'colspan') === false) $n++; } // رؤوس المجموعات لا تُعدّ
    return $n;
};
foreach ([['pages/official_forms.php', ['form' => 'salary_detail', 'month' => 6, 'year' => 2026]],
          ['pages/official_forms.php', ['form' => 'full_register', 'month' => 6, 'year' => 2026]],
          ['pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026]]] as $pg) {
    $h = renderPage($pg[0], $pg[1], ['extra', 'aide', 'transport'], [2], 'lbp');
    $cells = $cells128($h, 'مارسيلا'); $joined = implode('|', $cells);
    $iS = array_search(number_format($share42), array_map(fn($c) => preg_replace('/ L\.L$/u', '', $c), $cells), true);
    $iA = array_search(number_format($after42), array_map(fn($c) => preg_replace('/ L\.L$/u', '', $c), $cells), true);
    $nH = $heads128($h);
    $good = $cells && $iS !== false && $iA !== false && $iA === $iS + 1 && $nH === count($cells);
    if (!$good) { $ok128 = false; $why128[] = ($pg[1]['form'] ?? $pg[1]['report']) . " heads=$nH cells=" . count($cells) . " iS=" . var_export($iS, true) . " iA=" . var_export($iA, true); }
}
check('التنزيل العائلي بكل الكشوف (تشغيل فعلي): التفصيلي + الشامل + Résumé mensuel — حصّة مارسيلا ثم الخاضع بعدها متجاورَين، ورؤوس = خلايا',
      $ok128, $why128 ? implode(' ; ', $why128) : ('حصّة ' . number_format($share42 ?? 0) . ' / خاضع بعدها ' . number_format($after42 ?? 0)));

/* =====================================================================
 * 129) 🧑 «p1 شوف هيك صح شي» (2026-09-15، على إفادة راتب كميل مرعي 1387): صيغة المذكّر/المؤنّث بكل
 *     الإفادات من خانة الجنس بملف الموظف — معروف ⇒ «السيّد… يعمل… مدرّس… طلبه» أو «السيّدة… تعمل…
 *     مدرّسة… طلبها» (وM./Mme بالفرنسي)؛ مجهول ⇒ الصيغة المزدوجة (ة) كما كانت + خانة جنس حمراء تُحفَظ بالملف.
 * =================================================================== */
$at129 = (string)file_get_contents($PROJ . '/pages/attestations.php');
check('جنس الإفادات (كود): $g(مذكّر, مؤنّث, مزدوج) مصدر واحد + خانة الجنس بشريط الخيارات تُحفَظ بالملف + لا صيغة مزدوجة خام بالنصوص',
      strpos($at129, "\$g = function (string \$m, string \$f, string \$both, ?string \$miss = null) use (\$attGender): string {") !== false
      && substr_count($at129, "\$g('") >= 30
      // «بعد بدك تضيف الآنسة على الجنس»: القيمة d بكل المداخل (ملف الموظف + شاشات الإفادات) ونماذج الدولة تعاملها أنثى
      && function_exists('genderSexOf') && genderSexOf('d') === 'f' && genderSexOf('m') === 'm' && genderSexOf('x') === ''
      && substr_count($at129, '>Mlle / الآنسة</option>') === 3 && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), '>Mlle / الآنسة</option>') !== false
      && substr_count((string)file_get_contents($PROJ . '/pages/official_export.php'), '= genderSexOf($sexQ);') === 2
      && strpos($at129, "\$g('السيّد', 'السيّدة', 'السيّد(ة)', 'الآنسة')") !== false && strpos($at129, "\$g('M.', 'Mme', 'M./Mme', 'Mlle')") !== false
      && strpos($at129, "UPDATE employees SET gender=? WHERE id=? AND \" . schoolScopeWhere('school_id'))->execute([\$_GET['sex'], (int)\$employeeId]);") !== false
      && preg_match('/<select name="sex" onchange="this\.form\.submit\(\)"[^>]*\$attGender===\'\'/u', $at129) === 1
      && preg_match_all('/(السيّد\(ة\)|يعمل\(تعمل\)|مدرّس\(ة\)|طلبه\(ا\)|الأستاذ\(ة\)|المحترم\(ة\)|الموقّع\(ة\)|المولود\(ة\))/u', preg_replace('/\$g\([^)]*\)/u', '', preg_replace('#//.*$#mu', '', $at129))) === 0);
$ok129 = true; $why129 = [];
try {
    $db129 = getDB();
    $m129 = (int)$db129->query("SELECT e.id FROM employees e JOIN monthly_salaries m ON m.employee_id=e.id WHERE e.is_deleted=0 AND e.gender='m' AND e.employee_type<>'employe' AND e.hire_date IS NOT NULL AND e.left_date_all IS NULL AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL ORDER BY (e.id=1387) DESC, e.id LIMIT 1")->fetchColumn();
    $f129 = (int)$db129->query("SELECT e.id FROM employees e JOIN monthly_salaries m ON m.employee_id=e.id WHERE e.is_deleted=0 AND e.gender='f' AND e.employee_type<>'employe' AND e.hire_date IS NOT NULL AND e.left_date_all IS NULL AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL ORDER BY e.id LIMIT 1")->fetchColumn();
    // (2026-09-24) غير تارك: التارك صار بصيغة الماضي «عمل(ت) لديها» لا «يعمل(تعمل)» (إفادة عمل وتدريس مظبوطة لغوياً)
    $u129 = (int)$db129->query("SELECT e.id FROM employees e JOIN monthly_salaries m ON m.employee_id=e.id WHERE e.is_deleted=0 AND e.gender IS NULL AND e.employee_type<>'employe' AND e.hire_date IS NOT NULL AND e.left_date_all IS NULL AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL ORDER BY e.id LIMIT 1")->fetchColumn();
    $txt129 = function (int $eid, string $type, string $lang) {
        $h = renderPage('pages/attestations.php', ['employee_id' => $eid, 'type' => $type, 'lang_doc' => $lang, 'date' => '2026-09-15'], ['extra'], [], '', 'all');
        $p = mb_strpos($h, 'id="ppExportArea"');
        return [$h, preg_replace('/\s+/u', ' ', strip_tags(mb_substr($h, $p === false ? 0 : $p, 5000)))];
    };
    if ($m129) { [$h, $t] = $txt129($m129, 'salaire', 'ar');
        if (!(mb_strpos($t, 'بأنّ السيّد ') !== false && mb_strpos($t, ' يعمل لديها بوظيفة مدرّس لمادة') !== false && mb_strpos($t, 'ولا يزال حتى تاريخه ، ويتقاضى') !== false && mb_strpos($t, 'بناءً على طلبه .') !== false && mb_strpos($t, '(ة)') === false && mb_strpos($t, '(ا)') === false)) { $ok129 = false; $why129[] = "m=$m129"; }
        [$h, $t] = $txt129($m129, 'salaire', 'fr');
        if (!(mb_strpos($t, 'atteste que M. ') !== false && mb_strpos($t, 'M./Mme') === false)) { $ok129 = false; $why129[] = "m-fr=$m129"; }
    } else $why129[] = 'no-m';
    if ($f129) { [$h, $t] = $txt129($f129, 'tadris', 'ar');
        if (!(mb_strpos($t, 'بأنّ السيّدة ') !== false && mb_strpos($t, ' تعمل لديها بوظيفة مدرّسة لمادة') !== false && mb_strpos($t, 'ولا تزال حتى تاريخه ، وهي على حسن سلوك والتزام في أداء عملها') !== false && mb_strpos($t, 'بناءً على طلبها .') !== false && mb_strpos($t, '(ة)') === false)) { $ok129 = false; $why129[] = "f=$f129"; }
        // الآنسة (تجربة فعلية مع ترجيع كامل): نفس الموظفة مؤقتاً 'd' ⇒ «الآنسة … تعمل … طلبها» وMlle بالفرنسي
        $db129->prepare("UPDATE employees SET gender='d' WHERE id=?")->execute([$f129]);
        try {
            [$h, $t] = $txt129($f129, 'salaire', 'ar');
            if (!(mb_strpos($t, 'بأنّ الآنسة ') !== false && mb_strpos($t, ' تعمل لديها بوظيفة مدرّسة لمادة') !== false && mb_strpos($t, 'بناءً على طلبها .') !== false && mb_strpos($t, 'السيّدة') === false)) { $ok129 = false; $why129[] = "d=$f129"; }
            [$h, $t] = $txt129($f129, 'salaire', 'fr');
            if (!(mb_strpos($t, 'atteste que Mlle ') !== false)) { $ok129 = false; $why129[] = "d-fr=$f129"; }
        } finally { $db129->prepare("UPDATE employees SET gender='f' WHERE id=?")->execute([$f129]); }
    } else $why129[] = 'no-f';
    if ($u129) { [$h, $t] = $txt129($u129, 'salaire', 'ar');
        if (!(mb_strpos($t, 'بأنّ السيّد(ة) ') !== false && mb_strpos($t, 'يعمل(تعمل) لديها بوظيفة مدرّس(ة)') !== false && mb_strpos($t, 'بناءً على طلبه(ا) .') !== false
              && strpos($h, 'name="sex" onchange="this.form.submit()" style="padding:3px 6px;margin-right:6px;border:2px solid #dc2626') !== false)) { $ok129 = false; $why129[] = "u=$u129"; }
    }
} catch (Throwable $e) { $ok129 = false; $why129[] = $e->getMessage(); }
check('جنس الإفادات (تشغيل فعلي): ذكر ⇒ صيغة المذكّر وحدها (عربي + M. بالفرنسي) · أنثى ⇒ المؤنّث وحدها · مجهول ⇒ المزدوجة + خانة حمراء', $ok129, implode(' ; ', $why129) ?: "m=$m129 f=$f129 u=$u129");

/* =====================================================================
 * 130) 🗓️ «انتبه أنا حاطط مثلاً مع نقل ما عم يحطها» (2026-09-15): إفادة كميل مرعي (1387) بتاريخ 15/9/2026 وسنة
 *     2026-2027 كانت تأخذ آخر شهر بالسنة (أيلول 2027 صيفي بلا نقل ⇒ النقل 0 رغم تأشيره). صار الشهر = آخر شهر
 *     لا يتجاوز تاريخ الإفادة، وإن كانت السنة كلها مستقبلية فأوّل شهر فيها (تشرين الأول بنقله 18,000,000).
 * =================================================================== */
$at130 = (string)file_get_contents($PROJ . '/pages/attestations.php');
check('شهر الإفادة (كود): الاختيار مقيَّد بتاريخ الإفادة (≤ attYm أوّلاً، ثم أقرب شهر مستقبلي)',
      strpos($at130, "\$attYm = (int)date('Ym', strtotime(\$effDate) ?: time());") !== false
      && strpos($at130, "(year*100+month <= \$attYm) DESC,") !== false
      && strpos($at130, "CASE WHEN year*100+month <= \$attYm THEN -(year*100+month) ELSE (year*100+month) END ASC LIMIT 1") !== false);
$ok130 = false; $why130 = '';
try {
    $db130 = getDB();
    $oct130 = $db130->query("SELECT transport_lbp, prime_fixe_lbp FROM monthly_salaries WHERE employee_id = 1387 AND year = 2026 AND month = 10")->fetch(PDO::FETCH_ASSOC);
    $sep130 = $db130->query("SELECT transport_lbp FROM monthly_salaries WHERE employee_id = 1387 AND year = 2026 AND month = 9")->fetch(PDO::FETCH_ASSOC);
    if ($oct130 && (int)$oct130['transport_lbp'] > 0) {
        $g130 = ['employee_id' => 1387, 'type' => 'salaire', 'lang_doc' => 'ar', 'date' => '2026-09-15', 'opts_set' => 1, 'inc_extra' => 1, 'inc_trans' => 1];
        $hNew = renderPage('pages/attestations.php', $g130, ['extra', 'transport'], [3], 'lbp', '2026-2027'); // السنة لم تبدأ ⇒ تشرين الأول
        $hAll = renderPage('pages/attestations.php', $g130, ['extra', 'transport'], [3], 'lbp', 'all');       // كل السنين ⇒ آخر شهر ≤ أيلول 2026
        $okNew = strpos($hNew, '- تعويض النقل : <strong>' . number_format((int)$oct130['transport_lbp']) . ' ل.ل</strong>') !== false
              && strpos($hNew, '- الأجر الإضافي : <strong>' . number_format((int)$oct130['prime_fixe_lbp']) . ' ل.ل</strong>') !== false;
        $okAll = !$sep130 || (int)$sep130['transport_lbp'] === 0 || strpos($hAll, '- تعويض النقل : <strong>' . number_format((int)$sep130['transport_lbp']) . ' ل.ل</strong>') !== false;
        $ok130 = $okNew && $okAll; $why130 = 'تشرين 2026 نقل ' . number_format((int)$oct130['transport_lbp']) . ($okNew ? ' ✓' : ' ✗') . ' / كل السنين أيلول 2026 نقل ' . number_format((int)($sep130['transport_lbp'] ?? 0)) . ($okAll ? ' ✓' : ' ✗');
    } else { $ok130 = true; $why130 = 'لا صفّ تشرين 2026 لـ1387 بنقل — تخطٍّ'; }
} catch (Throwable $e) { $why130 = $e->getMessage(); }
check('شهر الإفادة (تشغيل فعلي): كميل مرعي 15/9/2026 — سنة 2026-2027 ⇒ نقل تشرين الأول لا صفر أيلول 2027، وكل السنين ⇒ أيلول 2026', $ok130, $why130);

/* =====================================================================
 * 131) 🧾 «دايماً بأي إفادة بدي أصدرها يكون عندي خيار حطّ الإضافي أو المكافأة أو النقل، دولار أو ليرة أو الاثنين —
 *     البرنامج موحّد وكل الخيارات بكل المحلات» (2026-09-15): شريط المكوّنات + العملة بكل الإفادات العامة (16 نوعاً)
 *     — النماذج الرسمية الثابتة (ضمان/ر3) لها شاشاتها الخاصة.
 * =================================================================== */
$at131 = (string)file_get_contents($PROJ . '/pages/attestations.php');
check('خيارات الإفادات موحّدة (كود): $hasComponents و$hasCurrency = true بلا قوائم أنواع',
      strpos($at131, "\$hasComponents = true;") !== false && strpos($at131, "\$hasCurrency   = true;") !== false
      && strpos($at131, "\$printsSalary  = in_array(\$type, ['cnss', 'afade_madrasiya', 'isqat_haq', 'salaire', 'embassy', 'aqd_taalim'], true);") !== false);
$bad131 = [];
foreach (['cnss', 'salaire', 'tadris', 'embassy', 'riaaya', 'anhaa_khedme', 'anhaa_mail', 'talab_istiqala', 'afade_madrasiya', 'isqat_haq', 'baraa_zimma', 'iqrar', 'aqd_taalim', 'notice_school', 'notice_mail'] as $ty131) {
    $h = renderPage('pages/attestations.php', ['employee_id' => 1387, 'type' => $ty131, 'date' => '2026-09-15'], ['extra'], [3], '', '2026-2027');
    if (strpos($h, 'name="inc_extra"') === false || strpos($h, 'name="inc_aide"') === false || strpos($h, 'name="inc_trans"') === false
        || substr_count($h, 'name="cur"') < 3 || strpos($h, 'الراتب المعتمد') === false) $bad131[] = $ty131;
}
check('خيارات الإفادات موحّدة (تشغيل فعلي): 15 نوعاً كلها تعرض الإضافي/المكافأة/النقل + ليرة/دولار/الاثنين + سطر الراتب المعتمد', !$bad131, $bad131 ? implode(',', $bad131) : 'كلها');

/* =====================================================================
 * 132) 🔴 «شيّكت إفادة الضمان اللي منقدّمها للضمان وشلت الإضافي — بعدو بيحطّو فيها، شيّك على كل البرنامج وصحّح»
 *     (2026-09-15): إفادة الضمان كانت تطبع سطر «الأجر الإضافي» دائماً (بصفر) والمكافأة مدموجة فيه؛ وعقد التعليم
 *     كان يجمع النقل ولو شِيل خياره. صار كل مكوّن يتبع مربّعه بكل الإفادات: مشيول = لا سطر ولا بالمجموع.
 * =================================================================== */
$at132 = (string)file_get_contents($PROJ . '/pages/attestations.php');
check('خيارات المكوّنات تُحترَم (كود): إفادة الضمان سطر لكل مكوّن مختار + عقد التعليم النقل يتبع مربّعه',
      substr_count($at132, "foreach (\$cnssParts as \$cp)") === 2
      && substr_count($at132, "\$cTrans  = \$incTrans ? (int)\$transW : 0;") === 2 // (2026-09-24) \$transW لا \$sal مباشرة — يصفَّر مع المبلغ اليدوي
      && strpos($at132, "\$attSupp = (\$incExtra ? \$extraW : 0) + (\$incAide ? \$aideW : 0);") !== false);
$ok132 = false; $why132 = '';
try {
    $r132 = getDB()->query("SELECT prime_fixe_lbp + extra_lbp ex, transport_lbp tr FROM monthly_salaries WHERE employee_id = 1387 AND year = 2026 AND month = 10")->fetch(PDO::FETCH_ASSOC);
    if ($r132 && (int)$r132['ex'] > 0 && (int)$r132['tr'] > 0) {
        $base132 = ['employee_id' => 1387, 'lang_doc' => 'ar', 'date' => '2026-09-15', 'opts_set' => 1];
        $exS = number_format((int)$r132['ex']); $trS = number_format((int)$r132['tr']);
        $hOn  = renderPage('pages/attestations.php', $base132 + ['type' => 'cnss', 'inc_extra' => 1], ['extra'], [3], 'lbp', '2026-2027');
        $hOff = renderPage('pages/attestations.php', $base132 + ['type' => 'cnss'], ['extra'], [3], 'lbp', '2026-2027');
        $cOn  = renderPage('pages/attestations.php', $base132 + ['type' => 'aqd_taalim', 'inc_trans' => 1], ['transport'], [3], 'lbp', '2026-2027');
        $cOff = renderPage('pages/attestations.php', $base132 + ['type' => 'aqd_taalim'], ['transport'], [3], 'lbp', '2026-2027');
        $doc132 = function (string $h): string { $p = strpos($h, 'id="ppExportArea"'); return $p === false ? $h : substr($h, $p); }; // نصّ الوثيقة فقط (لا شريط الخيارات الذي يعرض المبالغ للعلم)
        $okCn = strpos($doc132($hOn), '- الأجر الإضافي : <strong>' . $exS) !== false && strpos($doc132($hOff), '- الأجر الإضافي :') === false && strpos($doc132($hOff), $exS) === false;
        $okCt = strpos($doc132($cOn), $trS) !== false && strpos($doc132($cOff), '<strong>' . $trS . ' ل.ل</strong> شهرياً') === false;
        $ok132 = $okCn && $okCt; $why132 = "ضمان: مع الإضافي $exS " . ($okCn ? '✓' : '✗') . " / عقد: النقل $trS " . ($okCt ? '✓' : '✗');
    } else { $ok132 = true; $why132 = 'لا صفّ تشرين 2026 لـ1387 بإضافي ونقل — تخطٍّ'; }
} catch (Throwable $e) { $why132 = $e->getMessage(); }
check('خيارات المكوّنات تُحترَم (تشغيل فعلي): إفادة الضمان بلا الإضافي لا تذكره ولا بالمجموع، وعقد التعليم بلا النقل لا يجمعه', $ok132, $why132);

/* =====================================================================
 * 133) 🔴 «شوف تصريح باستخدام أجير ما عم بيغيّر — صحّح» (2026-09-15): نماذج الضمان الرسمية الثلاثة (استخدام أجير
 *     جديد/مضمون + ترك أجير) صار راتبها يتبع خيارات الشاشة نفسها (الإضافي/المكافأة/النقل + ليرة/دولار/الاثنين)
 *     — أوّل فتح = زرّا ملف الموظف «الضمان يشمل…» وبلا نقل؛ والشهر = آخر شهر ≤ تاريخ التصريح.
 * =================================================================== */
$oe133 = (string)file_get_contents($PROJ . '/pages/official_export.php');
$at133 = (string)file_get_contents($PROJ . '/pages/attestations.php');
check('تصريح استخدام أجير (كود): الراتب من مربّعات الشاشة + العملة + الشهر ≤ تاريخ التصريح، والشاشة تمرّرها بالرابط',
      strpos($oe133, "\$incExtra = \$decOpts ? !empty(\$_GET['inc_extra']) : !empty(\$emp['cnss_includes_extra']);") !== false
      && strpos($oe133, "(\$incTrans ? (int)(\$sal['transport_lbp'] ?? 0) : 0)) : 0;") !== false
      && strpos($oe133, "(year*100+month <= \$decYm) DESC,") !== false
      && strpos($oe133, "numToArabicWords((int)\$wageUsd) . ' دولار أميركي'") !== false
      && strpos($at133, "'&opts_set=1' . (\$incExtra ? '&inc_extra=1' : '') . (\$incAide ? '&inc_aide=1' : '') . (\$incTrans ? '&inc_trans=1' : '') . '&cur=' . \$decCur;") !== false
      && strpos($at133, 'الراتب المعتمد بالتصريح:') !== false);
$ok133 = false; $why133 = '';
try {
    $r133 = getDB()->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp + extra_lbp ex, transport_lbp tr, exchange_rate fx FROM monthly_salaries WHERE employee_id = 1387 AND year = 2026 AND month = 10")->fetch(PDO::FETCH_ASSOC);
    if ($r133 && (int)$r133['ex'] > 0 && (int)$r133['tr'] > 0) {
        $g133 = ['form' => 'cnss_hire_new', 'emp' => 1387, 'd' => 15, 'mo' => 9, 'yr' => 2026, 'opts_set' => 1, 'format' => 'xlsx'];
        // الإكسل الرسمي (محلياً PDF حقيقي بـLibreOffice فلا يُقرأ نصّه) — نصوص الخانات من sharedStrings (كيانات HTML للعربي)
        $vals = function (string $bin): string {
            $tmp = tempnam(sys_get_temp_dir(), 'r133'); file_put_contents($tmp, $bin);
            $z = new ZipArchive(); $out = '';
            if ($z->open($tmp) === true) { $out = html_entity_decode((string)$z->getFromName('xl/sharedStrings.xml') . (string)$z->getFromName('xl/worksheets/sheet1.xml'), ENT_QUOTES | ENT_HTML5, 'UTF-8'); $z->close(); }
            @unlink($tmp); return $out;
        };
        // 🔴 المخرجات ثنائية (xlsx): shell_exec على ويندوز يقطعها عند 0x1A — لذلك عبر ملف ($outFile بـrenderPage)
        $xf133 = sys_get_temp_dir() . '/r133_out.xlsx';
        $hB = $vals(renderPage('pages/official_export.php', $g133 + ['cur' => 'lbp'], [], [3], 'lbp', '2026-2027', $xf133));
        $hE = $vals(renderPage('pages/official_export.php', $g133 + ['inc_extra' => 1, 'cur' => 'lbp'], [], [3], 'lbp', '2026-2027', $xf133));
        $hT = $vals(renderPage('pages/official_export.php', $g133 + ['inc_extra' => 1, 'inc_trans' => 1, 'cur' => 'usd'], [], [3], 'lbp', '2026-2027', $xf133));
        $b = (int)$r133['b']; $be = $b + (int)$r133['ex']; $bet = $be + (int)$r133['tr']; $usd = (int)floor($bet / (float)$r133['fx']);
        $okB = strpos($hB, number_format($b)) !== false && strpos($hB, number_format($be)) === false;
        $okE = strpos($hE, number_format($be)) !== false;
        $okT = strpos($hT, '$' . number_format($usd)) !== false && strpos($hT, 'دولار أميركي') !== false && strpos($hT, 'ليرة لبنانية') === false;
        $ok133 = $okB && $okE && $okT;
        $why133 = 'أساس ' . number_format($b) . ($okB ? ' ✓' : ' ✗') . ' / +إضافي ' . number_format($be) . ($okE ? ' ✓' : ' ✗') . ' / +نقل بالدولار $' . number_format($usd) . ($okT ? ' ✓' : ' ✗');
    } else { $ok133 = true; $why133 = 'لا صفّ تشرين 2026 لـ1387 — تخطٍّ'; }
} catch (Throwable $e) { $why133 = $e->getMessage(); }
check('تصريح استخدام أجير (تشغيل فعلي): كميل مرعي 15/9/2026 — الأساس وحده، ثم +الإضافي، ثم +النقل بالدولار (رقماً وحروفاً)', $ok133, $why133);

/* =====================================================================
 * 134) 🏛️ «ما مشي الحال» (2026-09-15، على تصريح استخدام أجير): أزرار الشريط العلوي (PDF/Excel/Word/واتساب/إيميل) كانت
 *     تعمل على صفحة الخيارات لا على النموذج الرسمي. صار الشريط نفسه (مجموعة أزرار وحدة — الكبيرة انشالت) يعمل على
 *     النموذج المعبّأ بخيارات الشاشة الحالية عبر msaOfficialToolbar (export.js) — بالضمان الثلاثة ور3.
 * =================================================================== */
$at134 = (string)file_get_contents($PROJ . '/pages/attestations.php');
$js134 = (string)file_get_contents($PROJ . '/assets/js/export.js');
check('شريط النماذج الرسمية (كود): msaOfficialToolbar بـexport.js + 3 نداءات بالصفحة (ضمان الثلاثة + إفادة عمل الضمان + ر3) بعد DOMContentLoaded + لا أزرار كبيرة مكرّرة',
      strpos($js134, 'window.msaOfficialToolbar = function (o) {') !== false
      && substr_count($at134, "document.addEventListener('DOMContentLoaded', function () { msaOfficialToolbar({") === 3
      && strpos($at134, "&format=pdf&mode=image') ?>") !== false
      && strpos($at134, 'btn btn-danger btn-lg') === false && strpos($at134, 'btn btn-success btn-lg') === false);
$h134 = renderPage('pages/attestations.php', ['employee_id' => 1387, 'type' => 'cnss_hire_new', 'date' => '2026-09-15', 'opts_set' => 1, 'inc_extra' => 1, 'cur' => 'usd'], [], [3], 'lbp', '2026-2027');
check('شريط النماذج الرسمية (تشغيل فعلي): رابط PDF بالشريط يحمل خيارات الشاشة نفسها (opts_set + inc_extra + cur=usd)',
      preg_match('/msaOfficialToolbar\(\{pdf: "([^"]+)"/', $h134, $m134) === 1
      && strpos($m134[1], 'form=cnss_hire_new') !== false && strpos($m134[1], '&opts_set=1&inc_extra=1&cur=usd&format=pdf') !== false,
      $m134[1] ?? 'لا نداء');

/* =====================================================================
 * 135) 🚌 «بدي بالتقارير بعامود تعويض النقل خيار: موجود بلا مبلغ / موجود مع المبلغ / غير موجود» (2026-09-17):
 *     خيار ثلاثي بالشريط (transport_mode) بدل مربّع النقل — 'transport' = بالمبلغ (يُجمع بالمستحق)،
 *     'transport_blank' = العمود ظاهر بخانات فارغة ولا يُجمع (الأرقام تركب)، لا شيء = العمود غير موجود.
 *     المصدر الواحد: transportColMode/transportColShown/transportTd (functions) + transportHead/Cell/TotalCell (report_helpers).
 * =================================================================== */
$fn135 = (string)file_get_contents($PROJ . '/includes/functions.php');
$rh135 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$sw135 = (string)file_get_contents($PROJ . '/switch_salarycomp.php');
$of135 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
$rp135 = (string)file_get_contents($PROJ . '/pages/reports.php');
$rx135 = (string)file_get_contents($PROJ . '/pages/reports_export.php');
$ax135 = (string)file_get_contents($PROJ . '/pages/annual_slip_export.php');
check('عمود النقل الثلاثي (كود): الدوال المركزية + الشريط select + المبدّل + لا خلية نقل مباشرة بـsalaryCompHas بالتقارير + الإكسل يتبع الحالة',
      strpos($fn135, 'function transportColMode(): string {') !== false
      && strpos($fn135, 'function transportColShown(): bool { return transportColMode() !== \'none\'; }') !== false
      && strpos($fn135, 'function transportTd(string $html, string $attrs = \' class="num"\'): string {') !== false
      && strpos($fn135, '<select name="transport_mode" onchange="this.form.submit()">') !== false
      && strpos($fn135, "if (\$withTransport) \$n += transportColShown() ? 1 : 0;") !== false
      && strpos($rh135, "return transportColShown() ? '<th' . \$attrs . '>' . \$label . '</th>' : '';") !== false
      && strpos($rh135, "return transportTd(money((int)(\$r['transport_lbp'] ?? 0), rowRate(\$r), ['withCur' => false]), \$num ? ' class=\"num\"' : '');") !== false
      && strpos($rh135, "if (!salaryCompHas('transport')) \$h += (int)(\$r['transport_lbp'] ?? 0);") !== false // الجمع على «بالمبلغ» فقط
      && strpos($sw135, "if (\$tm === 'amount')     \$comp[] = 'transport';") !== false
      && strpos($sw135, "elseif (\$tm === 'blank')  \$comp[] = 'transport_blank';") !== false
      && preg_match("/if \(salaryCompHas\('transport'\)\): \?><td[^>]*><\?= (money|\\\$fmt|dualFromUsd\(\\\$trans|\\\$dualTot)/", $of135 . $rp135) === 0
      && substr_count($of135, 'transportTd(') >= 7 && substr_count($rp135, 'transportTd(') === 2
      && strpos($rx135, "if (transportColShown()) { \$head[] = 'تعويض النقل'; \$w[] = 14; }") !== false
      && substr_count($rx135, "if (transportColShown()) \$row[] = salaryCompHas('transport') ?") === 2
      && strpos($ax135, "if (!transportColShown())        \$d[] = 15;") !== false); // 👨‍👩‍👧➕ 2026-09-20: العمود 14 صار الصافي+العائلي والنقل 15
// تشغيل فعلي: الكشف الشهري بالحالات الثلاث — عدد رؤوس الجدول: بلا مبلغ = بالمبلغ = غير موجود + 1؛
// والإجمالي المتوجب بوضع «بلا مبلغ» = وضع «غير موجود» (النقل غير مجموع) وأصغر من «بالمبلغ».
$ths = function (string $h): int { return preg_match('/<thead>.*?<\/thead>/s', $h, $m) ? substr_count($m[0], '<th') : -1; };
$grand = function (string $h): string {
    if (!preg_match_all('/<tr class="total-row"[^>]*>.*?<\/tr>/s', $h, $mm)) return '';
    $row = end($mm[0]);
    return preg_match_all('/<strong>(.*?)<\/strong>/s', $row, $s) ? strip_tags(end($s[1])) : '';
};
$g135 = ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026];
$hN = renderPage('pages/reports.php', $g135, ['extra', 'aide'], [], 'lbp');
$hB = renderPage('pages/reports.php', $g135, ['extra', 'aide', 'transport_blank'], [], 'lbp');
$hA = renderPage('pages/reports.php', $g135, ['extra', 'aide', 'transport'], [], 'lbp');
$toNum = fn(string $s) => (int)preg_replace('/\D+/', '', $s);
$okShape = $noFatal($hB) && $ths($hB) === $ths($hA) && $ths($hB) === $ths($hN) + 1
        && strpos($hB, '<th>تعويض النقل</th>') !== false && strpos($hN, '<th>تعويض النقل</th>') === false;
$okSum = $toNum($grand($hB)) === $toNum($grand($hN)) && $toNum($grand($hA)) > $toNum($grand($hB));
// خانات النقل فارغة بوضع «بلا مبلغ»: عدد خلايا الجسم <td>&nbsp;</td> بعد خلية العائلي ≥ عدد الصفوف
$rowsB = preg_match_all('/<td><strong>[^<]+<\/strong><\/td>\s*<\/tr>/', $hB); // خلية المتوجب بكل صف
$blankB = preg_match_all('/<td>&nbsp;<\/td>\s*<td><strong>/', $hB);            // خلية نقل فارغة قبلها
check('عمود النقل الثلاثي (تشغيل فعلي، كشف حزيران 2026): الرؤوس بلا مبلغ = بالمبلغ = غير موجود+1، والخانات فارغة، والمتوجب بلا مبلغ = غير موجود < بالمبلغ',
      $okShape && $okSum && $rowsB > 0 && $blankB === $rowsB,
      'رؤوس ' . $ths($hN) . '/' . $ths($hB) . '/' . $ths($hA) . ' · متوجب ' . $grand($hN) . ' / ' . $grand($hB) . ' / ' . $grand($hA) . ' · فارغة ' . $blankB . '/' . $rowsB);
// النماذج الرسمية (كشف الدفع + كشف الرواتب + كلفة المؤسسات + الكلفة التفصيلية + الأساتذة) بوضع «بلا مبلغ» بلا خطأ وبرأس النقل
$okOf = true; $whyOf = [];
foreach (['payment_list' => ['month' => 6, 'year' => 2026], 'salary_all' => ['month' => 6, 'year' => 2026], 'general_report' => [], 'full_register' => ['month' => 6, 'year' => 2026], 'teaching_staff' => []] as $f => $g) {
    $h = renderPage('pages/official_forms.php', ['form' => $f] + $g, ['extra', 'aide', 'transport_blank'], [], 'lbp');
    $ok = $noFatal($h) && preg_match('/<th[^>]*>تعويض نقل<\/th>|<th[^>]*>تعويض النقل<\/th>|تعويض النقل<\/span><\/th>/u', $h) === 1;
    if (!$ok) { $okOf = false; $whyOf[] = $f; }
}
check('عمود النقل الثلاثي (النماذج الرسمية بوضع «بلا مبلغ»): 5 كشوف ترندر برأس النقل بلا خطأ', $okOf, implode(',', $whyOf));

/* =====================================================================
 * 136) 📐 «p1 بدي ترتب هيدا التقرير» (2026-09-17، التقرير العام = الموازنة السنوية المقدّرة): ترتيب الأعمدة الملزم
 *     المؤسسة ← الراتب بعد التدرّج ← الإضافي ← المكافأة ← النقل ← المجموع (= مجموع الأربعة الظاهرة) ← الصافي ← الضمان ← الصندوق
 *     ← الضريبة ← المجموع الأخير (= الصافي + الضمان + الصندوق + الضريبة) + منتقي المدارس (schools[]) + فلتر الفئة/الضريبة
 *     + الاتجاه من الشمال لليمين + كل عنوان فرنسي فوق العربي (grBi).
 * =================================================================== */
$of136 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
check('التقرير العام (كود): dir=ltr + grBi + منتقي المدارس داخل شريط الفلترة + «الكل» يصفّر الاختيار + الاستعلام على reportSchoolSql',
      strpos($of136, '<div class="official-doc ltr land-report" id="ppExportArea" dir="ltr" style="max-width:100%">') !== false
      && strpos($of136, "\$grBi = fn(string \$fr, string \$ar) =>") !== false
      && strpos($of136, "if (\$form === 'general_report' && isSuperAdmin()):") !== false
      && strpos($of136, "if (isset(\$_GET['schools_set']) && !isset(\$_GET['schools'])) \$_SESSION['report_schools'] = [];") !== false
      && strpos($of136, "AND ms.school_year=?\" . reportSchoolSql('ms.school_id') . \" GROUP BY ms.school_id") !== false
      && strpos($of136, "\$composed = \$bpe + (salaryCompHas('extra')?\$exW:0) + (salaryCompHas('aide')?\$aid:0) + (\$grTransAmt?\$trans:0);") !== false
      && strpos($of136, "\$netWith=\$net+\$transShown; \$tot=\$netWith+\$cnss+\$eoc+\$tax;") !== false);
$h136 = renderPage('pages/official_forms.php', ['form' => 'general_report'], ['extra', 'aide', 'transport'], [], 'lbp', '2025-2026');
$heads136 = preg_match('/<thead>(.*?)<\/thead>/s', $h136, $mh) ? array_map(fn($x) => trim(strip_tags($x)), preg_split('/<\/th>/', $mh[1], -1, PREG_SPLIT_NO_EMPTY)) : [];
$heads136 = array_values(array_filter($heads136, fn($x) => $x !== ''));
$expFr = ['Établissement', 'Salaire après échelon', 'Supplément', 'Prime et aide', 'Transport', 'Total', 'Salaires nets', 'CNSS', 'Caisse des indemnités', 'Impôt sur le revenu', 'Total'];
$okOrder = count($heads136) === 11;
foreach ($expFr as $i => $fr) if ($okOrder && strpos($heads136[$i], $fr) !== 0) $okOrder = false;
$okAr = $okOrder && strpos($heads136[1], 'الراتب بعد التدرّج') !== false && strpos($heads136[5], 'المجموع') !== false && strpos($heads136[6], 'الرواتب الصافية') !== false;
// الأرقام تركب: بأوّل صف مدرسة، المجموع = الأربعة قبله، والمجموع الأخير = الصافي + الضمان + الصندوق + الضريبة
$okRow = false; $why136 = 'heads=' . count($heads136);
if (preg_match('/<tbody>\s*<tr>(.*?)<\/tr>/s', $h136, $mr)) {
    $cells = array_map(fn($c) => (int)preg_replace('/\D+/', '', strip_tags($c)), array_slice(preg_split('/<\/td>/', $mr[1], -1, PREG_SPLIT_NO_EMPTY), 1));
    if (count($cells) >= 10) {
        $okRow = ($cells[0] + $cells[1] + $cells[2] + $cells[3] === $cells[4]) && ($cells[5] + $cells[6] + $cells[7] + $cells[8] === $cells[9]);
        $why136 .= ' row=' . implode('|', $cells);
    }
}
check('التقرير العام (تشغيل فعلي 2025-2026): 11 عموداً بالترتيب المطلوب فرنسي فوق عربي + dir=ltr + المجموع = الأربعة + الأخير = الصافي+الضمان+الصندوق+الضريبة + منتقي المدارس ظاهر',
      $noFatal($h136) && $okOrder && $okAr && $okRow && strpos($h136, 'name="schools[]"') !== false && strpos($h136, 'id="ppExportArea" dir="ltr"') !== false, $why136);

/* =====================================================================
 * 137) 🏆 «p1 بدي بهيدا التقرير الدرجات العادية والدرجات الاستثنائية والراتب بعد التدرّج» (2026-09-17، المجاميع السنوية):
 *     بندان جديدان بمجموعة الرواتب (grade_ord/grade_exc من annualGradeSplit) بين أساس الراتب والراتب بعد التدرّج —
 *     أساس + عادية + استثنائية = الراتب بعد التدرّج لكل مدرسة (الأرقام تركب)، والتصدير يحملهما تلقائياً (annualTotalSelected).
 * =================================================================== */
$rh137 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$keys137 = array_keys(annualTotalItems());
check('الدرجات العادية/الاستثنائية بالمجاميع السنوية (كود): بندان calc بين أساس الراتب والراتب بعد التدرّج + annualGradeSplit مصدر واحد',
      function_exists('annualGradeSplit')
      && array_search('grade_ord', $keys137, true) === array_search('base_sal', $keys137, true) + 1
      && array_search('grade_exc', $keys137, true) === array_search('grade_ord', $keys137, true) + 1
      && array_search('bpe', $keys137, true) === array_search('grade_exc', $keys137, true) + 1
      && strpos($rh137, "\$r['grade_ord'] = \$g['ord']; \$r['grade_ord_usd'] = \$g['ord_usd']; \$r['grade_exc'] = \$g['exc']; \$r['grade_exc_usd'] = \$g['exc_usd'];") !== false
      && strpos($rh137, "AND ms.echelon_value_lbp > 0") !== false);
[$rows137, $tot137] = annualTotalRows($db, '2025-2026', '', [], '', ' ');
$bad137 = [];
foreach ($rows137 as $r) if ((int)$r['base_sal'] + (int)$r['grade_ord'] + (int)$r['grade_exc'] !== (int)$r['bpe']) $bad137[] = $r['school_id'] . ':' . ((int)$r['base_sal'] + (int)$r['grade_ord'] + (int)$r['grade_exc'] - (int)$r['bpe']);
check('الدرجات العادية/الاستثنائية (داتا 2025-2026): أساس + عادية + استثنائية = الراتب بعد التدرّج بكل مدرسة + المجموع يركب + في درجات فعلاً',
      !$bad137 && count($rows137) > 0 && (int)$tot137['grade_ord'] + (int)$tot137['grade_exc'] > 0
      && (int)$tot137['grade_ord'] === array_sum(array_map(fn($r) => (int)$r['grade_ord'], $rows137)),
      ($bad137 ? 'فروق: ' . implode(' ', $bad137) : '') . ' عادية=' . number_format((int)$tot137['grade_ord']) . ' استثنائية=' . number_format((int)$tot137['grade_exc']));
$hAt137 = renderPage('pages/reports.php', ['report' => 'annual_totals', 'school_year' => '2025-2026'], ['extra', 'aide', 'transport'], [], 'lbp');
check('الدرجات العادية/الاستثنائية (شاشة): الرأسان بين أساس الراتب والراتب بعد التدرّج',
      strpos($hAt137, '<th>أساس الراتب') !== false && strpos($hAt137, '<th>الدرجات العادية') !== false && strpos($hAt137, '<th>الدرجات الاستثنائية') !== false
      && strpos($hAt137, '<th>أساس الراتب') < strpos($hAt137, '<th>الدرجات العادية') && strpos($hAt137, '<th>الدرجات العادية') < strpos($hAt137, '<th>الدرجات الاستثنائية')
      && strpos($hAt137, '<th>الدرجات الاستثنائية') < strpos($hAt137, '<th>الراتب بعد التدرّج'));

/* =====================================================================
 * 138) 🚪 «أنا غيّرت وحطّيت 55 لازم يكون 55 — لازم يغيّر لكل أساتذة الملاك» (2026-09-17): رأس «الأجر الإضافي» كان يعرض
 *     «55 / 60 %» لأن بند 60٪ لأستاذتين تركتا 30/9/2026 بقي فعّالاً بسنة 2026-2027. صار: (١) نطاق المدارس بـextraPctHead =
 *     أساتذة السنة فقط (yearEmploymentFilter) (٢) حفظ تاريخ الترك يطفئ علاوات السنين اللاحقة فوراً (pruneSalariesAfterDeparture).
 * =================================================================== */
$rh138 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$fn138 = (string)file_get_contents($PROJ . '/includes/functions.php');
check('نسبة الإضافي برأس التقرير تتجاهل التاركين (كود): yearEmploymentFilter بنطاق المدارس + إطفاء علاوات ما بعد الترك مع الحفظ',
      strpos($rh138, "[\$yf, \$yp] = yearEmploymentFilter(\$sy, 'e.');") !== false
      && strpos($rh138, "\$sql .= schoolScopeSql('e.school_id') . \$yf; \$prm = array_merge(\$prm, \$yp);") !== false
      && strpos($fn138, "UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = ? AND is_active = 1 AND school_year IS NOT NULL") !== false);
// تجربة حيّة: تارك قبل بداية السنة النشطة يُعطى بند 97.5٪ مؤقّتاً — لا يظهر بالرأس؛ ثم يُمسح
$ok138 = false; $why138 = '';
try {
    $syA = (string)activeSchoolYear(); $ys = substr($syA, 0, 4) . '-10-01';
    $lv = $db->query("SELECT e.id, e.school_id FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire'
                      AND " . leftDateSql('e.') . " < '$ys'
                      AND e.school_id IN (SELECT school_id FROM monthly_salaries WHERE school_year = '$syA' AND base_plus_echelon_lbp > 0) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lv) {
        $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'prime_fixe', 9, ?, 97.5, 'percent', 'LBP', 10, 9, 1)")->execute([(int)$lv['id'], $syA]);
        $bid = (int)$db->lastInsertId();
        $_SESSION['active_schools'] = [(int)$lv['school_id']];
        $hdr = extraPctHead(null, null, null, $syA);
        $db->exec("DELETE FROM employee_bonuses WHERE id = $bid");
        unset($_SESSION['active_schools']);
        $ok138 = strpos($hdr, '97.5') === false;
        $why138 = 'تارك #' . $lv['id'] . ' مدرسة ' . $lv['school_id'] . ' — الرأس: ' . strip_tags($hdr);
    } else { $ok138 = true; $why138 = 'لا تارك بالمحلي — تخطٍّ'; }
} catch (Throwable $e) { $why138 = $e->getMessage(); }
check('نسبة الإضافي برأس التقرير (تشغيل فعلي): بند 97.5٪ لتارك قبل السنة لا يظهر بالرأس', $ok138, $why138);

/* =====================================================================
 * 139) 🩹 «انت بدك تشوف وتشيّك، أنا تعبت من التشييك» (2026-09-17): فحص دمب الأونلاين (smp_online) وإصلاح ما طلع بنيوياً:
 *     (١) computeFrom = قلب المحرّك المشترك؛ مسار المنقولين overlayStoredYearBonuses يعيد حساب الشهر به حين يتغيّر الإضافي بلا
 *         فجوة أو حين يكون الشهر بلا معنى (صافي 0 وحسومات > 0 — كريستوف شلهوب تموز 2027)
 *     (٢) شفاءات مستمرّة ببوّابة زمنية healGateOpen: إضافي عالق بلا سطر (17 متعاقداً) / سعر صرف فارغ (1,291 صفاً) / شهر لا تركب أرقامه
 *     (٣) قاعدة الفحص base_scale تستثني أشهر ما قبل دخول الملاك (ريتا طنوس 2025-2026)
 * =================================================================== */
$pc139 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$fn139 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hd139 = (string)file_get_contents($PROJ . '/includes/header.php');
$da139 = (string)file_get_contents($PROJ . '/includes/data_audit.php');
check('الفحص الذاتي (كود): computeFrom + إعادة حساب شهر المنقول عند تغيّر الإضافي/شهر بلا معنى + 3 شفاءات ببوّابة زمنية موصولة بالهيدر + استثناء ما قبل الملاك بالفحص الرسمي',
      strpos($pc139, 'public function computeFrom($baseSalary, $echelonValue, $effectiveGrade, $primeFixe, $aideComp, $transportComp) {') !== false
      && strpos($pc139, "\$nonsense = \$doAdd && (int)\$r['net_salary_lbp'] === 0 && (int)\$r['total_retenues_lbp'] > 0 && (int)\$r['base_plus_echelon_lbp'] > 0;") !== false
      && strpos($pc139, "if ((\$dAdd !== 0 && \$gap === 0 && \$doAdd) || \$nonsense) {") !== false
      && strpos($fn139, 'function healGateOpen(string $key, int $hours = 3): bool {') !== false
      && strpos($fn139, "if (!healGateOpen('heal_ghost_add')) return 0;") !== false && strpos($fn139, "if (!healGateOpen('heal_null_rate')) return 0;") !== false && strpos($fn139, "if (!healGateOpen('heal_net_math')) return 0;") !== false
      && strpos($hd139, 'healGhostAdditionsFromPrevYear();') !== false && strpos($hd139, 'healNullExchangeRates();') !== false && strpos($hd139, 'healNetMathRows();') !== false
      && strpos($da139, "AND (e.titularization_date IS NULL OR CONCAT(ms.year,'-',LPAD(ms.month,2,'0'),'-01') >= e.titularization_date)") !== false);
// تجربة فعلية مع ترجيع: شهر منقول «بلا معنى» (صافي 0 وحسومات 4,130,000 وإضافي 0 لأن سطره ينتهي بحزيران) → التركيب يعيد حسابه بالقانون
$ok139 = false; $why139 = '';
try {
    $db->beginTransaction();
    $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = 1826 AND school_year = '2025-2026' AND bonus_type IN ('prime_fixe','aide_complementaire')")->execute();
    $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                  VALUES (1826, 'prime_fixe', 1, '2025-2026', 43000000, 'amount', 'LBP', 10, 6, 1)")->execute();
    // تموز 2026: نجعله بلا معنى كما كان كريستوف
    $db->prepare("UPDATE monthly_salaries SET prime_fixe_lbp = 0, total_retenues_lbp = 4130000, cnss_amount_lbp = 2670000, income_tax_lbp = 1460000, net_salary_lbp = 0, total_due_lbp = 0 WHERE employee_id = 1826 AND year = 2026 AND month = 7")->execute();
    $had = $db->query("SELECT base_plus_echelon_lbp FROM monthly_salaries WHERE employee_id = 1826 AND year = 2026 AND month = 7")->fetchColumn();
    if ($had === false) { $ok139 = true; $why139 = 'لا صفّ تموز 2026 لديانا — تخطٍّ'; }
    else {
        overlayStoredYearBonuses(1826, '2025-2026');
        $j = $db->query("SELECT base_plus_echelon_lbp bpe, prime_fixe_lbp pf, cnss_amount_lbp cnss, income_tax_lbp tax, total_retenues_lbp ret, net_salary_lbp net, total_due_lbp due, transport_lbp tr, family_allowance_lbp fam FROM monthly_salaries WHERE employee_id = 1826 AND year = 2026 AND month = 7")->fetch();
        $ok139 = $j && (int)$j['pf'] === 0 && (int)$j['net'] > 0 && (int)$j['ret'] < 4130000
            && (int)$j['net'] === (int)floor(((int)$j['bpe'] - (int)$j['ret']) / 1000) * 1000
            && (int)$j['due'] === (int)$j['net'] + (int)$j['fam'] + (int)$j['tr'];
        $why139 = $j ? ('تموز: أساس ' . number_format((int)$j['bpe']) . ' حسومات ' . number_format((int)$j['ret']) . ' صافي ' . number_format((int)$j['net'])) : 'صف مفقود';
    }
} catch (Throwable $e) { $why139 = 'خطأ: ' . $e->getMessage(); }
finally { if ($db->inTransaction()) $db->rollBack(); }
check('الفحص الذاتي (تشغيل فعلي مع ترجيع): شهر منقول صافيه 0 وحسوماته 4,130,000 بلا إضافي → يُعاد حسابه: حسومات على الأساس وصافٍ > 0 والأرقام تركب', $ok139, $why139);

/* =====================================================================
 * 140) 🚪 «ترك من الكل» (2026-09-18 «لازم يكون فيه تاريخ ترك للضمان وتاريخ لصندوق التعويضات وتاريخ للكل — ومن بعدها حسب
 *      موضوع الترك بيصير، وإذا الترك من الكل يعني ما في اسم ولا رواتب من بعده»):
 *      أربعة تواريخ ترك — «الكل» (left_date_all) وحده يُخرج الاسم والرواتب من السنين اللاحقة (yearEmploymentFilter/المحرّك/الشفاء/فتح السنة/
 *      اللوائح)؛ ترك الضمان/المالية/الصندوق يوقف جهته فقط (الاشتراك بالمحرّك + لوائحها ونماذجها) من الشهر التالي، والراتب والاسم يكمّلان.
 *      العمود يتركّب ذاتياً ويُعبَّأ مرّة واحدة بالأبكر من الثلاثة لكل الموجودين (لا يتغيّر أي سلوك قائم). المصدر الواحد: leftDateSql/leftDateOf.
 * =================================================================== */
$fn140 = (string)file_get_contents($PROJ . '/includes/functions.php');
$pc140 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$em140 = (string)file_get_contents($PROJ . '/pages/employees.php');
$hd140 = (string)file_get_contents($PROJ . '/includes/header.php');
check('ترك من الكل (كود): عمود left_date_all يتركّب ذاتياً + تعبئة مرّة واحدة + المصدر الواحد leftDateSql/leftDateOf/monthSubjectTo + الخانة الرابعة بملف الموظف + المحرّك يوقف الجهة فقط',
      function_exists('ensureLeftDateAllColumn') && function_exists('leftDateSql') && function_exists('leftDateSqlFor') && function_exists('leftDateOf') && function_exists('leftDateOfFor') && function_exists('monthSubjectTo')
      && strpos($fn140, "ADD COLUMN left_date_all DATE NULL") !== false && strpos($fn140, "left_date_all_migrated_20260918") !== false
      && strpos($fn140, '$leftDate = leftDateSql($prefix);') !== false
      && strpos($hd140, 'ensureLeftDateAllColumn();') !== false
      && strpos($em140, 'name="left_date_all"') !== false && strpos($em140, "'left_date_all' => (\$_POST['left_date_all'] ?? '') ?: null,") !== false
      && strpos($pc140, '$ld = leftDateOf($this->employee);') !== false
      && strpos($pc140, "if (\$emp['tax_subject'] && \$subjTax) {") !== false
      && strpos($pc140, "if (\$emp['cnss_subject'] && \$subjCnss) {") !== false
      && strpos($pc140, "if (\$emp['eoc_subject'] && \$subjEoc && \$emp['employee_type'] === 'enseignant_titulaire') {") !== false
      && (bool)$db->query("SHOW COLUMNS FROM employees LIKE 'left_date_all'")->fetch()
      && strpos((string)getSetting('left_date_all_migrated_20260918', ''), 'done') === 0);
// كنس البرنامج كله: لا يبقى أي تعبير «أبكر الثلاثة» (LEAST) ولا «الثلاثة NULL» خارج المصدر الواحد (استثناء وحيد: سطر التعبئة بـensureLeftDateAllColumn)
$sweep140 = []; $least140 = 0;
$it140 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PROJ, FilesystemIterator::SKIP_DOTS));
foreach ($it140 as $f140) {
    $p140 = str_replace('\\', '/', $f140->getPathname());
    if (substr($p140, -4) !== '.php' || strpos($p140, '/tools/') !== false || strpos($p140, '/tmp/') !== false || strpos($p140, '/vendor/') !== false
        || preg_match('~/(fix_departures_once|migrate|cleanup_ghosts)\.php$~', $p140)) continue;
    $s140 = (string)file_get_contents($p140);
    $least140 += preg_match_all('/LEAST\(COALESCE\((NULLIF\()?(e\.|\{\$prefix\})?left_date_cnss|LEAST\(COALESCE\(" \. validDateSql\(\'left_date_cnss\'\)/', $s140); // (2026-09-19) التعبئة صارت عبر validDateSql
    if (preg_match('/left_date_cnss IS NULL AND (\{\$prefix\}|e\.)?left_date_finance IS NULL/', $s140)) $sweep140[] = basename($p140);
}
check('ترك من الكل (كنس البرنامج): لا تعبير «أبكر الثلاثة» خارج سطر التعبئة الواحد ولا «الثلاثة NULL» بأي ملف', $least140 === 1 && !$sweep140,
      "LEAST=$least140" . ($sweep140 ? ' · IS NULL: ' . implode('، ', $sweep140) : ''));
// وحدات PHP: تاريخ الجهة = الأبكر بين تاريخها وتاريخ الكل؛ شهر الترك خاضع والذي يليه لا
$u140 = ['left_date_all' => '2026-06-30', 'left_date_cnss' => '2026-09-30', 'left_date_finance' => null, 'left_date_eoc' => '0000-00-00'];
check('ترك من الكل (وحدات): leftDateOfFor = الأبكر بين الجهة والكل · monthSubjectTo: شهر الترك خاضع والتالي لا · بلا أي تاريخ = خاضع دائماً',
      leftDateOf($u140) === '2026-06-30' && leftDateOfFor($u140, 'cnss') === '2026-06-30' && leftDateOfFor($u140, 'finance') === '2026-06-30'
      && leftDateOfFor(['left_date_cnss' => '2026-03-15'], 'cnss') === '2026-03-15' && leftDateOf(['left_date_cnss' => '2026-03-15']) === null
      && monthSubjectTo($u140, 'cnss', 2026, 6) === true && monthSubjectTo($u140, 'cnss', 2026, 7) === false
      && monthSubjectTo(['left_date_cnss' => '2026-03-15'], 'cnss', 2026, 3) === true && monthSubjectTo(['left_date_cnss' => '2026-03-15'], 'cnss', 2026, 4) === false
      && monthSubjectTo([], 'eoc', 2030, 1) === true);
// تجربة حيّة تُرجَع: ملاك خاضع للضمان بلا تواريخ — (أ) ترك الضمان 31/1/2026 ⇒ آذار 2026: الضمان 0 والأساس والصندوق كما هما، وكانون الثاني ما زال بضمانه؛
// وما زال ضمن أساتذة 2025-2026 (yearEmploymentFilter) — (ب) ترك من الكل 2020 ⇒ يختفي من السنة
$ok140 = false; $why140 = '';
try {
    $db->beginTransaction();
    $t140 = $db->query("SELECT e.id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.year = 2026 AND ms.month = 3
        WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire' AND e.cnss_subject = 1 AND e.eoc_subject = 1
          AND ms.cnss_amount_lbp > 0 AND ms.caisse_amount_lbp > 0 AND ms.base_plus_echelon_lbp > 0
          AND " . leftDateSql('e.') . " = '9999-12-31' AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL
          AND (e.keep_working_past_64 IS NULL OR e.keep_working_past_64 = 0) ORDER BY e.id LIMIT 1")->fetchColumn();
    if (!$t140) { $ok140 = true; $why140 = 'لا عيّنة — تخطٍّ'; }
    else {
        $tid = (int)$t140;
        $c0 = (new PayrollCalculator($tid, 3, 2026))->calculate();
        $db->exec("UPDATE employees SET left_date_cnss = '2026-01-31' WHERE id = $tid");
        $c1 = (new PayrollCalculator($tid, 3, 2026))->calculate();
        $cJan = (new PayrollCalculator($tid, 1, 2026))->calculate();
        [$yf140, $yp140] = yearEmploymentFilter('2025-2026', 'e.');
        $in1 = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.id = ?" . $yf140); $in1->execute(array_merge([$tid], $yp140)); $in1 = (int)$in1->fetchColumn();
        $db->exec("UPDATE employees SET left_date_all = '2020-01-01' WHERE id = $tid");
        $in2 = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.id = ?" . $yf140); $in2->execute(array_merge([$tid], $yp140)); $in2 = (int)$in2->fetchColumn();
        $ok140 = (int)$c0['cnss_amount_lbp'] > 0 && (int)$c1['cnss_amount_lbp'] === 0 && (int)$cJan['cnss_amount_lbp'] > 0
              && (int)$c1['base_plus_echelon_lbp'] === (int)$c0['base_plus_echelon_lbp'] && (int)$c1['caisse_amount_lbp'] === (int)$c0['caisse_amount_lbp']
              && (int)$c1['net_salary_lbp'] > (int)$c0['net_salary_lbp']
              && $in1 === 1 && $in2 === 0;
        $why140 = "#$tid آذار: ضمان " . number_format((int)$c0['cnss_amount_lbp']) . ' → ' . number_format((int)$c1['cnss_amount_lbp']) . ' · ك٢ ' . number_format((int)$cJan['cnss_amount_lbp'])
                . ' · صندوق ' . number_format((int)$c0['caisse_amount_lbp']) . '=' . number_format((int)$c1['caisse_amount_lbp']) . " · بالسنة: ضمان فقط=$in1 · الكل=$in2";
    }
} catch (Throwable $e) { $why140 = 'خطأ: ' . $e->getMessage(); }
finally { if ($db->inTransaction()) $db->rollBack(); }
check('ترك من الكل (تجربة حيّة تُرجَع): ترك الضمان وحده يصفّر الضمان من الشهر التالي فقط ويُبقي الاسم بالسنة والأساس والصندوق؛ الترك من الكل يخفيه', $ok140, $why140);

/* =====================================================================
 * 141) 🧑‍🏫⚖️ نوع الوظيفة على مستويين + راتب الموظف حسب قانون العمل (2026-09-18 «نوع الوظيف بدي يكون أو موظف أو أستاذ؛ إذا موظف لازم
 *      نحطّ نوع الوظيفة ويخضع راتبه لقانون العمل وأنا إذا بدي غيّرو بغيّرو؛ إذا أستاذ ملاك أو متعاقد: الملاك سلسلة الرتب والرواتب،
 *      والمتعاقد أنا بحدّد قدّيش»): الشاشة emp_kind (+teacher_kind) وemployee_type مخفي يشتقّه الخادم؛ الملاك مقفول على السلسلة؛
 *      الموظف: عمود salary_labor_law (يتركّب ذاتياً) = الأساس الحد الأدنى للأجور الساري بتاريخ الشهر — القدامى لا يتغيّرون (0).
 * =================================================================== */
$fn141 = (string)file_get_contents($PROJ . '/includes/functions.php');
$pc141 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$em141 = (string)file_get_contents($PROJ . '/pages/employees.php');
$hd141 = (string)file_get_contents($PROJ . '/includes/header.php');
check('نوع الوظيفة على مستويين + قانون العمل (كود): العمود يتركّب ذاتياً + isLaborLawSalary/laborLawMinWage/salaryConfigSql + المحرّك + اشتقاق الخادم + الشاشة',
      function_exists('ensureSalaryLaborLawColumn') && function_exists('isLaborLawSalary') && function_exists('laborLawMinWage') && function_exists('salaryConfigSql')
      && strpos($fn141, "ADD COLUMN salary_labor_law TINYINT(1) NOT NULL DEFAULT 0") !== false
      && strpos($hd141, 'ensureSalaryLaborLawColumn();') !== false
      && strpos($pc141, "if (isLaborLawSalary(\$emp)) {") !== false && strpos($pc141, "return [(float)laborLawMinWage((int)\$this->month, (int)\$this->year), 0.0, (float)\$emp['current_grade']];") !== false
      && strpos($pc141, "if (isLaborLawSalary(\$emp)) return true;") !== false
      && strpos($em141, "if (\$kindP === 'employe') \$empType = 'employe';") !== false
      && strpos($em141, "if (\$empType === 'enseignant_titulaire') \$modeP = 'percent_of_lbp';") !== false
      && strpos($em141, "\$laborLawP = (\$empType === 'employe' && \$modeP === 'labor_law') ? 1 : 0;") !== false
      && strpos($em141, 'name="emp_kind"') !== false && strpos($em141, 'name="teacher_kind"') !== false && strpos($em141, 'id="employeeTypeHidden"') !== false
      && strpos($em141, 'value="labor_law" data-for="employe"') !== false
      && (bool)$db->query("SHOW COLUMNS FROM employees LIKE 'salary_labor_law'")->fetch()
      && (int)$db->query("SELECT COUNT(*) FROM employees WHERE salary_labor_law = 1 AND employee_type <> 'employe'")->fetchColumn() === 0);
// تجربة حيّة تُرجَع: موظف إداري جديد على «قانون العمل» ⇒ أساس حزيران 2026 = الحد الأدنى الساري بتاريخه (> 0) والمحرّك يسمح؛ وبإطفائه بلا مبلغ ⇒ لا إعداد (أساس 0)
$ok141 = false; $why141 = '';
try {
    $db->beginTransaction();
    $db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, salary_input_mode, salary_labor_law, base_salary_usd, contract_salary_lbp, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted)
        VALUES (2, '__REG141', 'employe', 'فحص', 'موظف141', 'Reg', 'Test141', '2025-10-01', 'actif', 'direct_lbp', 1, 0, 0, 12, 5, 4, 1, 1, 1, 1, 0, 0)");
    $rid141 = (int)$db->lastInsertId();
    $mw141 = laborLawMinWage(6, 2026);
    $c141 = (new PayrollCalculator($rid141, 6, 2026))->calculate();
    $e141 = $db->query("SELECT * FROM employees WHERE id = $rid141")->fetch(PDO::FETCH_ASSOC);
    $allow1 = salaryEngineAllowed($e141, $db);
    $db->exec("UPDATE employees SET salary_labor_law = 0 WHERE id = $rid141");
    $c141b = (new PayrollCalculator($rid141, 6, 2026))->calculate();
    $e141b = $db->query("SELECT * FROM employees WHERE id = $rid141")->fetch(PDO::FETCH_ASSOC);
    $allow0 = salaryEngineAllowed($e141b, $db);
    $ok141 = $mw141 > 0 && (int)$c141['base_plus_echelon_lbp'] === $mw141 && (int)$c141['net_salary_lbp'] > 0 && $allow1
          && (int)$c141b['base_plus_echelon_lbp'] === 0 && $allow0 === true /* جديد بلا أي صفّ مخزّن: المحرّك سيّده (ريتا بو عاصي) */;
    $why141 = 'الحد الأدنى 6/2026 = ' . number_format($mw141) . ' · الأساس ' . number_format((int)$c141['base_plus_echelon_lbp']) . ' · صافي ' . number_format((int)$c141['net_salary_lbp']) . ' · مطفأ: أساس ' . number_format((int)$c141b['base_plus_echelon_lbp']);
} catch (Throwable $e) { $why141 = 'خطأ: ' . $e->getMessage(); }
finally { if ($db->inTransaction()) $db->rollBack(); }
check('قانون العمل (تجربة حيّة تُرجَع): موظف على قانون العمل ⇒ أساسه = الحد الأدنى الساري بتاريخ الشهر وصافيه > 0؛ مطفأ بلا مبلغ ⇒ أساس 0', $ok141, $why141);

/**
 * 142) 🎓 صفحة «اقتراحات الدخول بالملاك» (2026-09-19 «بدي اقتراح للدخول في الملاك للأساتذة اللي بيكون صارلون سنتين داخلين على المدرسة
 *      وأنا ساعتها بوافق دخّلهن بالملاك أو لا»): الميزة (cadre_due.php) كانت مدفونة ببطاقة مطوية بلوحة القيادة ⇒ صفحة دائمة
 *      pages/cadre_due.php بالقائمة الجانبية مع شارة العدد المعلّق (cadreDuePendingCount = الاستعلام نفسه بلا نسب/نقل، ≤ 0.5 ث)
 *      + قائمة مَن دخل الملاك بموافقته (cadreDueApprovedList) + معاينة السنة القادمة (الجدد فقط) + رابط «الصفحة الكاملة» من البطاقة.
 *      🔴 متغيّر الشارة بالهيدر اسمه $cdNavPend — لا $cdPend (الهيدر يُضمَّن بنطاق الصفحة فكان يدوس مصفوفتها).
 */
$hd142 = (string)file_get_contents($PROJ . '/includes/header.php');
$pg142 = (string)file_get_contents($PROJ . '/pages/cadre_due.php');
$cd142 = (string)file_get_contents($PROJ . '/includes/cadre_due.php');
$t142 = microtime(true); $n142 = cadreDuePendingCount($db, currentSchoolYear()); $ms142 = (microtime(true) - $t142) * 1000;
$n142b = count(cadreDueCandidates($db, currentSchoolYear(), null, false, true));
$html142 = renderPage('pages/cadre_due.php', [], [], [], '', currentSchoolYear());
$html142i = renderPage('index.php', [], [], [], '', currentSchoolYear());
check('🎓 صفحة اقتراحات الدخول بالملاك (كود + رندر): الصفحة بالقائمة مع شارة العدد ($cdNavPend) + الشارة = عدد المرشَّحين المعلّقين فعلاً + الصفحة تُرندَر بلا خطأ بالنموذج والأزرار + بطاقة لوحة القيادة تربط إليها',
      file_exists($PROJ . '/pages/cadre_due.php')
      && function_exists('cadreDuePendingCount') && function_exists('cadreDueApprovedList')
      && strpos($hd142, 'pages/cadre_due.php') !== false && strpos($hd142, '$cdNavPend = cadreDuePendingCount();') !== false
      && strpos($hd142, "'cadre_due'=>'personnel'") !== false && strpos($hd142, "'cadre_due.php'") !== false
      && strpos($pg142, "handleCadreDuePost(\$db, BASE_URL . 'pages/cadre_due.php');") !== false
      && strpos($pg142, "renderCadreDuePending(\$cdPend, \$cdSy, false, BASE_URL . 'pages/cadre_due.php', \$cdRej);") !== false
      && strpos($pg142, 'cadreDueApprovedList($db, $cdSy)') !== false
      && strpos($cd142, "pages/cadre_due.php\" class=\"btn btn-sm\"") !== false
      && strpos((string)file_get_contents($PROJ . '/index.php'), "'pages/cadre_due.php'") !== false
      && $n142 === $n142b && $ms142 < 1500
      && strpos($html142, 'FATAL') === false && strpos($html142, 'اقتراحات الدخول بالملاك') !== false
      && strpos($html142, 'بانتظار قرارك: ' . $n142b . '</span>') !== false
      && ($n142b === 0 || (substr_count($html142, '<input type="checkbox" name="emp_ids[]"') === $n142b /* الاسم يظهر أيضاً بـJS msaCdOne */ && strpos($html142, 'name="cd_act"') !== false))
      && strpos($html142, 'pages/cadre_due.php" class="active"') !== false
      && substr_count($html142, '<th>أساسه بالملاك حسب القانون (السلسلة)</th>') === substr_count($html142, '<th>راتبه الآن (متعاقد)</th>') // 🧮 عمود القانون (ماريا اسكندر 2026-09-19)
      && ($n142b === 0 || preg_match('/<strong>1,[0-9]{3},000<\/strong> <small>\(درجة [0-9.]+\)<\/small><br><small>كانون: <strong>1,[0-9]{3},000<\/strong>/u', $html142) === 1)
      && strpos($html142i, 'FATAL') === false && ($n142b === 0 || strpos($html142i, 'الصفحة الكاملة / Page complète') !== false),
      'معلّق=' . $n142 . '/' . $n142b . ' · الشارة ' . round($ms142) . ' ms');

// 🔢 «إضافي 6 %» بدل 60 % (2026-09-19، ظهر بتجربة الترسيم على اسبر منصور بالانتقال): rtrim('60','0') = '6' ⇒ pctFmt بكل المواضع + شفاء نصوص القرارات/التدقيق المخزّنة
$fn142 = (string)file_get_contents($PROJ . '/includes/functions.php'); $cp142 = (string)file_get_contents($PROJ . '/includes/compliance.php');
check('🔢 pctFmt: 60 ⇒ «60» لا «6» + لا rtrim مباشر على النِّسَب بالترسيم/المخالفات + الشفاء المخزّن مركَّب بالهيدر ولا نصّ «إضافي 6 %» باقٍ لمدرسة نسبتها 60',
      function_exists('pctFmt') && pctFmt(60) === '60' && pctFmt('60.00') === '60' && pctFmt(12.5) === '12.5' && pctFmt('85') === '85' && pctFmt(100) === '100'
      && strpos($cd142, "rtrim(rtrim((string)\$pct") === false && strpos($cd142, "rtrim(rtrim((string)\$p,") === false
      && strpos($cd142, "pctFmt(\$pct['pct'])") !== false && strpos($cp142, "pctFmt(\$s['amount'])") !== false && strpos($fn142, "pctFmt(\$g['amount'])") !== false
      && function_exists('healCadrePctText20260919') && strpos($hd142, 'healCadrePctText20260919();') !== false
      && (int)$db->query("SELECT COUNT(*) FROM compliance_decisions d WHERE d.rule_key = 'cadre_due' AND d.school_id IN (3,6) AND (d.result LIKE '%إضافي 6 \\% كملاك%' OR d.violation LIKE '%إضافي 6 \\% كملاك%')")->fetchColumn() === 0,
      'heal=' . getSetting('heal_cadre_pct_text_20260919', '—'));

/**
 * 143) 🚪🔴 تاريخ ترك وهمي = لا تاريخ + التقليم لا يمحو المدفوع (2026-09-19 — حادثة كرستيان عون 1438، النجاة): ترك صندوق «0001-01-01»
 *      نسخته تعبئة «الكل» (2026-09-18) ⇒ pruneSalariesAfterDeparture اعتبره تاركاً منذ الأزل ومحا 43 شهراً أونلاين (33 مدفوعاً) بلا أثر.
 *      الإصلاح: validDateSql بكل تعابير الترك (SQL + PHP) + التقليم يستثني is_paid=1 ويسجّل بالتدقيق + شفاء مرّة: تصفير الوهمي واسترجاع
 *      1438 من tools/data/rows_1438_20260919.json (نسخة الأونلاين 2026-09-18 14:31) — «ترك من الكل» فارغ؛ الضمان/المالية موقوفان بقراره.
 */
$fn143 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hd143 = (string)file_get_contents($PROJ . '/includes/header.php');
$r143 = $db->query("SELECT (SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = 1438) rows_, (SELECT COUNT(*) FROM employee_bonuses WHERE employee_id = 1438) bon,
                    left_date_all, left_date_eoc, last_name_ar FROM employees WHERE id = 1438")->fetch(PDO::FETCH_ASSOC) ?: [];
$bogus143 = (int)$db->query("SELECT COUNT(*) FROM employees WHERE (left_date_all IS NOT NULL AND left_date_all < '1900-01-01') OR (left_date_cnss IS NOT NULL AND left_date_cnss < '1900-01-01') OR (left_date_finance IS NOT NULL AND left_date_finance < '1900-01-01') OR (left_date_eoc IS NOT NULL AND left_date_eoc < '1900-01-01')")->fetchColumn();
// تجربة حيّة تُرجَع: موظف تاركٌ من الكل 2024-06-30 وله بسنة 2025-2026 شهر مدفوع وشهر غير مدفوع ⇒ التقليم يمحو غير المدفوع فقط؛ وبتاريخ وهمي لا يمحو شيئاً
$ok143 = false; $why143 = '';
try {
    $db->beginTransaction();
    $db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, left_date_all)
               VALUES (2, '__REG143', 'employe', 'فحص', 'ترك143', 'Reg', 'Left143', '2020-10-01', 'actif', '2024-06-30')");
    $rid = (int)$db->lastInsertId();
    $db->exec("INSERT INTO monthly_salaries (school_id, employee_id, month, year, school_year, net_salary_lbp, total_due_lbp, is_paid) VALUES (2, $rid, 10, 2025, '2025-2026', 1000, 1000, 1), (2, $rid, 11, 2025, '2025-2026', 1000, 1000, 0)");
    $d1 = pruneSalariesAfterDeparture($db, $rid);
    $left1 = $db->query("SELECT GROUP_CONCAT(month ORDER BY month) FROM monthly_salaries WHERE employee_id = $rid")->fetchColumn();
    $db->exec("UPDATE employees SET left_date_all = '0001-01-01' WHERE id = $rid");
    $db->exec("INSERT INTO monthly_salaries (school_id, employee_id, month, year, school_year, net_salary_lbp, total_due_lbp, is_paid) VALUES (2, $rid, 12, 2025, '2025-2026', 1000, 1000, 0)");
    $d2 = pruneSalariesAfterDeparture($db, $rid);
    $ld2 = $db->query("SELECT " . leftDateSql() . " FROM employees WHERE id = $rid")->fetchColumn();
    $emp2 = $db->query("SELECT * FROM employees WHERE id = $rid")->fetch(PDO::FETCH_ASSOC);
    $ok143 = $d1 === 1 && (string)$left1 === '10' && $d2 === 0 && $ld2 === '9999-12-31' && leftDateOf($emp2) === null && leftDateOfFor($emp2, 'eoc') === null;
    $why143 = "d1=$d1 left=$left1 d2=$d2 ld2=$ld2";
} catch (Throwable $e) { $why143 = 'خطأ: ' . $e->getMessage(); }
finally { if ($db->inTransaction()) $db->rollBack(); }
check('🚪🔴 تاريخ ترك وهمي = لا تاريخ + التقليم لا يمحو المدفوع (كود + تجربة حيّة تُرجَع + شفاء كرستيان عون: 43 شهراً + بندا نقل + لا تاريخ وهمي بالبرنامج)',
      function_exists('validDateSql') && function_exists('healBogusLeftDates20260919')
      && strpos($fn143, "return \"COALESCE(\" . validDateSql(\"{\$prefix}left_date_all\") . \",'9999-12-31')\";") !== false
      && strpos($fn143, "LEAST(COALESCE(\" . validDateSql(\"{\$prefix}{\$col}\")") !== false
      && strpos($fn143, "\$trio = \"LEAST(COALESCE(\" . validDateSql('left_date_cnss')") !== false
      && strpos($fn143, "DELETE FROM monthly_salaries WHERE employee_id = ? AND COALESCE(is_paid, 0) = 0 AND ((month >= 10 AND year > ?)") !== false
      && strpos($fn143, "logAudit('prune_after_departure'") !== false
      && strpos($hd143, 'healBogusLeftDates20260919();') !== false
      && is_file($PROJ . '/tools/data/rows_1438_20260919.json')
      && $ok143 && $bogus143 === 0
      && ((string)($r143['last_name_ar'] ?? '') !== 'عون' || ((int)$r143['bon'] === 2 && $r143['left_date_eoc'] === null
          && (($r143['left_date_all'] === null && (int)$r143['rows_'] === 45 /* 43 + آب/أيلول 2027 بعد قاعدة 12 شهراً للجميع (2026-09-20) */)
              || ($r143['left_date_all'] === '2026-09-30' && (int)$r143['rows_'] >= 33 /* 🚪 قراره أونلاين 2026-09-21: ترك 30/9/2026 ⇒ أشهر 2026-2027 غير المدفوعة قُلِّمت */)))),
      $why143 . ' · bogus=' . $bogus143 . ' · 1438=' . json_encode($r143, JSON_UNESCAPED_UNICODE));

/**
 * 144) 📐 «p1: عمود الصافي لازم يكون بكل التقارير وكل البرنامج دغري بعد عمود المحسومات» (2026-09-19):
 *      كشف الرواتب والأجور الشهري (salary_all) كان: محسومات ← عائلي ← نقل ← مجموع المدفوعات ← صافي ⇒ صار محسومات ← صافي ← عائلي ← نقل ← مدفوعات
 *      (رأس + صفوف + مجاميع). كنس: أي <th> «مجموع المحسومات» بأي صفحة يليه مباشرة «الصافي».
 */
$h144 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3]);
$hdr144 = preg_match('/<th>مجموع المحسومات<\/th><th>الصافي/u', $h144) === 1;
$rowOk144 = preg_match_all('/<td class="num">(?:(?!<\/td>).)*<\/td>\s*<td class="num"><strong>(?:(?!<\/td>).)*<\/td>\s*(?:<td[^>]*>(?:(?!<\/td>).)*<\/td>\s*){3,4}<td style="min-width:60px">/su', $h144); // محسومات ← صافي (bold) ← 3-4 خلايا (عائلي/الصافي+العائلي 2026-09-20/نقل/مدفوعات) ← توقيع
$sweep144 = [];
foreach (glob($PROJ . '/pages/*.php') as $f144) {
    $src144 = (string)file_get_contents($f144);
    if (preg_match_all('/<th>مجموع المحسومات<\/th>\s*<th>([^<]{0,20})/u', $src144, $m144)) foreach ($m144[1] as $nx) if (strpos($nx, 'الصافي') !== 0) $sweep144[] = basename($f144) . ':' . $nx;
}
check('📐 الصافي دغري بعد المحسومات: كشف الرواتب والأجور الشهري (رأس + صفوف + مجاميع) + كنس الصفحات (كل «مجموع المحسومات» يليه «الصافي»)',
      strpos($h144, 'FATAL') === false && $hdr144 && $rowOk144 > 0 && strpos($h144, '<th>مجموع المحسومات</th><th>تعويض عائلي</th>') === false && !$sweep144,
      'rows=' . $rowOk144 . ($sweep144 ? ' · ' . implode('، ', $sweep144) : ''));

/**
 * 145) 🏷️ «اتفقنا بكل عناوين التقارير والإفادات والبرنامج: بس نحطّ الراتب بالدولار لازم يكون بالعنوان سعر الدولار اللي حاسبها» (2026-09-19):
 *      rateTitleText/rateSubtitle (functions.php) = المصدر الواحد؛ docSheetStart يضيفه تلقائياً (opts month/year/annual/law)؛ عناوين official_forms
 *      الـ13 + القسيمة + كل الإفادات التي تطبع مبلغاً ($rateLine بعد كل <h2>) + ملف الأستاذ.
 *      ⚠️ 2026-09-23 (تريزيا مارون «البطاقة ما بيّنت قيمة الدولار بالعناوين»): السطر يظهر بكل أوضاع العملة بما فيها «ليرة فقط» (كان يُخفى فيه).
 */
$fn145 = (string)file_get_contents($PROJ . '/includes/functions.php'); $rh145 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$of145 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $at145 = (string)file_get_contents($PROJ . '/pages/attestations.php');
$sch145 = (int)$db->query("SELECT school_id FROM employees WHERE id = " . (int)$regEid)->fetchColumn() ?: 3; // مستندات الموظف بمدرسته وشهره (6/2026)
$docs145 = [
    ['pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], true, [3], '2025-2026'],
    ['pages/official_forms.php', ['form' => 'payment_list', 'month' => 10, 'year' => 2025], true, [3], '2025-2026'],
    ['pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], true, [3], '2025-2026'],
    ['pages/reports.php', ['report' => 'annual_totals'], false, [3], '2025-2026'],
    ['pages/attestations.php', ['employee_id' => $regEid, 'type' => 'salaire'], false, [$sch145], '2025-2026'],
    ['pages/monthly_payroll.php', ['employee_id' => $regEid, 'month' => 6, 'year' => 2026], true, [$sch145], '2025-2026'],
    ['pages/official_forms.php', ['form' => 'teacher_card', 'employee_id' => $regEid], false, [$sch145], '2025-2026'],
];
$ok145 = true; $why145 = [];
foreach ($docs145 as [$pg, $get, $law, $scope145, $sy145]) {
    $hb = renderPage($pg, $get, ['extra', 'aide', 'transport'], $scope145, 'both', $sy145);
    $hl = renderPage($pg, $get, ['extra', 'aide', 'transport'], $scope145, 'lbp', $sy145);
    $hasB = preg_match('/سعر الصرف المعتمد: (سعر كل شهر — آخر سعر )?1 \$ = [0-9,]+ ل\.ل\./u', $hb) === 1;
    $lawB = strpos($hb, 'الراتب بعد التدرّج لأصحاب النسبة بالسعر الرسمي 1 $ = 1,500') !== false;
    $hasL = strpos($hl, 'سعر الصرف المعتمد') !== false;
    $fat = strpos($hb, 'FATAL') !== false || strpos($hl, 'FATAL') !== false;
    if (!$hasB || $lawB !== $law || !$hasL || $fat) { $ok145 = false; $why145[] = basename($pg) . ':' . json_encode($get) . " both=" . (int)$hasB . " law=" . (int)$lawB . " lbp=" . (int)$hasL . " fatal=" . (int)$fat; }
}
$hTad = renderPage('pages/attestations.php', ['employee_id' => $regEid, 'type' => 'tadris'], [], [$sch145], 'both', '2025-2026'); // إفادة بلا مبلغ ⇒ بلا سطر سعر
// 🏷️ «مش موجود ببطاقة المتعاقد» (2026-09-19): البطاقة السنوية — سطر واحد (.slip-rate) لكل الفئات؛ 1,500 لأصحاب النسبة فقط؛ يظهر بوضع الليرة أيضاً منذ 2026-09-23 (التصميم المجمّد لم يُمسّ)
$con145 = (int)$db->query("SELECT e.id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2025-2026' AND ms.net_salary_lbp > 0
                           WHERE e.employee_type = 'enseignant_contractuel' AND e.is_deleted = 0 AND e.school_id = 3 LIMIT 1")->fetchColumn();
$slipB = $con145 ? renderPage('pages/annual_slip.php', ['employee_id' => $con145, 'school_year' => '2025-2026'], ['extra', 'aide'], [3], 'both', '2025-2026') : '';
$slipL = $con145 ? renderPage('pages/annual_slip.php', ['employee_id' => $con145, 'school_year' => '2025-2026'], ['extra', 'aide'], [3], 'lbp', '2025-2026') : '';
$slipOk = $con145 && preg_match('/<div class="slip-rate" dir="rtl">سعر الصرف المعتمد: سعر كل شهر — آخر سعر 1 \$ = [0-9,]+ ل\.ل\.<\/div>/u', $slipB) === 1
          && strpos($slipL, 'slip-rate"') !== false && strpos($slipB, 'FATAL') === false;
if (!$slipOk) $why145[] = 'annual_slip contract=' . $con145;
check('🏷️ سعر الصرف المعتمد بعنوان كل مستند فيه دولار (كشف/تقرير/إفادة/قسيمة/بطاقة): يظهر بكل أوضاع العملة (الليرة أيضاً منذ 2026-09-23)، والسعر الرسمي 1,500 فقط حيث أعمدة الأساس، وإفادة بلا مبلغ بلا سطر',
      function_exists('rateTitleText') && function_exists('rateSubtitle') && strpos($rh145, "rateTitleText(\$opts['month'] ?? null") !== false
      && substr_count($of145, 'rateSubtitle(') === 13 && substr_count($at145, '<?= $rateLine ?>') === substr_count($at145, '</h2>')
      && $ok145 && $slipOk && strpos($hTad, 'سعر الصرف المعتمد') === false,
      implode(' · ', $why145) ?: 'ok');

/**
 * 146) 🏦 «ببطاقة المتعاقد ما لازم يكون فيه عمود لصندوق التعويضات — بس انتبه أوعى تخرب البطاقة» (2026-09-19): $noCaisse = موظف أو متعاقد ⇒
 *      عمودا Caisse ودرجة/نصف راتب مخفيان (المحسومات colspan 3) — الملاك كما هو (5 + Caisse). أعمدة الدرجة/التدرّج تبقى للمتعاقد. لا تغيير آخر.
 */
$as146 = (string)file_get_contents($PROJ . '/pages/annual_slip.php');
$tit146 = (int)$db->query("SELECT e.id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2025-2026' AND ms.net_salary_lbp > 0
                           WHERE e.employee_type = 'enseignant_titulaire' AND e.is_deleted = 0 AND e.school_id = 3 LIMIT 1")->fetchColumn();
$sC = $con145 ? renderPage('pages/annual_slip.php', ['employee_id' => $con145, 'school_year' => '2025-2026'], ['extra', 'aide'], [3], 'both', '2025-2026') : '';
$sT = $tit146 ? renderPage('pages/annual_slip.php', ['employee_id' => $tit146, 'school_year' => '2025-2026'], ['extra', 'aide'], [3], 'both', '2025-2026') : '';
check('🏦 البطاقة السنوية للمتعاقد بلا عمود صندوق التعويضات (المحسومات = ضمان/ضريبة/مجموع، colspan 3، أعمدة الدرجة باقية) — الملاك كما هو (Caisse + colspan 5) — بلا Fatal',
      strpos($as146, "\$noCaisse = \$isEmp || \$emp['employee_type'] === 'enseignant_contractuel';") !== false
      && strpos($as146, '<th colspan="<?= $noCaisse ? 3 : 5 ?>" class="deduction-header">') !== false
      && substr_count($as146, '<?php if (!$noCaisse): ?>') === 3 && strpos($as146, "(\$isEmp ? 9 : (\$noCaisse ? 11 : 13)) + compColsCount() + (\$showDue ? 1 : 0)") !== false
      && $con145 && $tit146 && strpos($sC, 'FATAL') === false && strpos($sT, 'FATAL') === false
      && strpos($sC, 'deduction-header">Caisse') === false && strpos($sC, 'colspan="3" class="deduction-header"') !== false && strpos($sC, 'Valeur échelon<br>قيمة الدرجة') !== false
      && strpos($sT, 'deduction-header">Caisse') !== false && strpos($sT, 'colspan="5" class="deduction-header"') !== false,
      "contract=$con145 titulaire=$tit146");

/**
 * 147) 🏦 نماذج تعويض نهاية الخدمة p1/p2/p3 (2026-09-19 «بدي متلهون طبق الأصل ينعملو ويتعبّوا ويكون عندي خيار عبّيهون أو غيّر فيهن واحفظ واحذف
 *      وأكيد الطبع PDF أو إكسل أو وورد»): includes/eos_forms.php المصدر الواحد (eosData) + 3 نماذج بـofficial_forms (cnss_eos_doc/2y/annual،
 *      للموظف والمتعاقد فقط) + خانات ofe قابلة للتعديل تُحفظ JSON بجدول official_form_edits (يتركّب ذاتياً) وتُحذف + بلاطات بمركز التقارير.
 */
require_once $PROJ . '/includes/eos_forms.php';
ensureOfficialFormEdits();
$of147 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp147 = (string)file_get_contents($PROJ . '/pages/reports.php');
$e147 = $db->query("SELECT e.id, e.school_id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.is_calculated = 1 AND ms.net_salary_lbp > 0
                    WHERE e.employee_type = 'employe' AND e.is_deleted = 0 AND e.nssf_number <> '' GROUP BY e.id HAVING COUNT(*) >= 12 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$ok147 = (bool)$e147; $why147 = 'emp=' . json_encode($e147);
if ($e147) {
    $eid = (int)$e147['id']; $sid = (int)$e147['school_id'];
    $emp147 = $db->query("SELECT * FROM employees WHERE id = $eid")->fetch(PDO::FETCH_ASSOC); $sch147 = $db->query("SELECT * FROM schools WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
    $d147 = eosData($db, $emp147, $sch147);
    $consistent = (int)$d147['total'] === (int)array_sum($d147['years']) && $d147['contrib'] === (int)round($d147['total'] * $d147['rate_pct'] / 100);
    foreach (['cnss_eos_doc', 'cnss_eos_2y', 'cnss_eos_annual'] as $f147) {
        $h = renderPage('pages/official_forms.php', ['form' => $f147, 'employee_id' => $eid], [], [$sid], 'lbp', '2025-2026');
        if (strpos($h, 'FATAL') !== false || strpos($h, 'id="ppExportArea"') === false || strpos($h, 'data-k="inst_no"') === false || strpos($h, 'id="ofeSaveForm"') === false) { $ok147 = false; $why147 .= " $f147:render"; }
        if ($f147 === 'cnss_eos_doc' && strpos($h, 'data-k="total">' . eosNum($d147['total']) . '<') === false) { $ok147 = false; $why147 .= ' doc:total'; }
        if ($f147 === 'cnss_eos_annual' && strpos($h, 'data-k="yr_tot">' . eosNum($d147['total']) . '<') === false) { $ok147 = false; $why147 .= ' annual:total'; }
    }
    // حفظ ← يظهر المحفوظ بدل التلقائي ← حذف ← يرجع التلقائي (على موظف وهمي 999999 لا يلمس داتا حقيقية)
    ofeSave($db, 999999, 'cnss_eos_doc', ['inst_no' => 'REG-147', 'b_week' => 'X'], 'regcheck');
    $sv = ofeLoad($db, 999999, 'cnss_eos_doc');
    $GLOBALS['OFE_DATA'] = $sv['data'];
    $okSave = ($sv['data']['inst_no'] ?? '') === 'REG-147' && strpos(ofe('inst_no', 'AUTO'), '>REG-147<') !== false && strpos(ofeBox('b_week', 'x', false), 'data-v="X"') !== false && strpos(ofe('other', 'AUTO'), '>AUTO<') !== false;
    ofeDelete($db, 999999, 'cnss_eos_doc'); $GLOBALS['OFE_DATA'] = [];
    $okDel = ofeLoad($db, 999999, 'cnss_eos_doc')['updated_at'] === null;
    if (!$consistent) { $ok147 = false; $why147 .= ' consistency'; }
    if (!$okSave || !$okDel) { $ok147 = false; $why147 .= " save=" . (int)$okSave . " del=" . (int)$okDel; }
}
check('🏦 نماذج نهاية الخدمة p1/p2/p3: تُرندَر بلا خطأ لموظف حقيقي + مجموع الأجور = مجموع السنوات والاشتراك = المجموع × النسبة + حفظ/حذف الخانات المعدَّلة + قصر النموذج على الموظف والمتعاقد + بلاطات التقارير',
      $ok147 && strpos($of147, "\$laborLawOnly = ['cnss_eos_doc','cnss_eos_2y','cnss_eos_annual'];") !== false
      && strpos($of147, "in_array(\$form, \$laborLawOnly) ? \" AND employee_type IN ('employe','enseignant_contractuel')\"") !== false
      && strpos($of147, 'eosHandlePost($db);') !== false && strpos($of147, "elseif (in_array(\$form, ['cnss_eos_doc','cnss_eos_2y','cnss_eos_annual'], true)):") !== false
      && substr_count($rp147, "\$OF.'cnss_eos_doc'") === 1 && substr_count($rp147, "\$OF.'cnss_eos_2y'") === 1 && substr_count($rp147, "\$OF.'cnss_eos_annual'") === 1
      && (bool)$db->query("SHOW TABLES LIKE 'official_form_edits'")->fetch(),
      $why147);

/**
 * 148) 💰 عمود «المستحق / مجموع المدفوعات» بثلاث حالات متل النقل (2026-09-19 «بدي بعمود المستحق كمان خيار: حطّ المبلغ أو ما حطّو بس
 *      العمود يضلّ موجود أو ما حطّو كلّو — أوعى تخربطلي بطاقة الراتب»): dueColMode/dueColShown/dueColsCount/dueTd (functions) +
 *      dueHead/dueCell/dueTotalCell (report_helpers) + select due_mode بالشريط والترويسة + switch_salarycomp — عرض فقط، لا حساب يتغيّر.
 *      مطبَّق: كشف الرواتب الشهري (reports + إكسل) + salary_all + payment_list + ofState + البطاقة السنوية (+ إكسلها).
 */
$fn148 = (string)file_get_contents($PROJ . '/includes/functions.php'); $rh148 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$of148 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp148 = (string)file_get_contents($PROJ . '/pages/reports.php');
$as148 = (string)file_get_contents($PROJ . '/pages/annual_slip.php'); $ax148 = (string)file_get_contents($PROJ . '/pages/annual_slip_export.php');
$rx148 = (string)file_get_contents($PROJ . '/pages/reports_export.php'); $hd148 = (string)file_get_contents($PROJ . '/includes/header.php');
$ok148 = true; $why148 = ''; $ths148 = [];
$con148 = $con145 ?: 0;
foreach (['amount', 'blank', 'none'] as $dm) {
    $hr = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], $dm);
    $hs = $con148 ? renderPage('pages/annual_slip.php', ['employee_id' => $con148, 'school_year' => '2025-2026'], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], $dm) : '';
    $ha = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], $dm);
    $thR = substr_count($hr, '<th>الإجمالي المتوجب</th>'); $thS = substr_count($hs, 'Total dû<br>المستحق'); $thA = substr_count($ha, 'مجموع المدفوعات');
    $fat = strpos($hr . $hs . $ha, 'FATAL') !== false;
    $blankR = substr_count($hr, '<td style="min-width:110px">&nbsp;</td>'); // 💰 «طلع ضيق — وسّعه» (2026-09-19): الخلية الفارغة تحفظ عرضاً للكتابة
    if ($dm === 'blank' && $con148 && substr_count($hs, 'class="due-blank"') < 3) { $ok148 = false; $why148 .= ' slip:due-blank'; }
    if ($dm === 'amount' && $con148 && strpos($hs, 'class="due-blank"') !== false) { $ok148 = false; $why148 .= ' slip:due-blank-in-amount'; }
    $exp = $dm === 'none' ? 0 : 1;
    if ($fat || $thR !== $exp || ($con148 && $thS !== $exp) || $thA !== $exp || ($dm === 'blank' && $blankR < 10) || ($dm !== 'blank' && $blankR > 0)) { $ok148 = false; $why148 .= " $dm: thR=$thR thS=$thS thA=$thA blank=$blankR fatal=" . (int)$fat; }
    // عدد <th> بالكشف الشهري ينقص عموداً واحداً فقط بوضع «غير موجود» (الجدول لا يتخربط)
    $ths148[$dm] = substr_count($hr, '<th');
}
if (!isset($ths148['amount'], $ths148['none']) || $ths148['amount'] - $ths148['none'] !== 1 || $ths148['blank'] !== $ths148['amount']) { $ok148 = false; $why148 .= ' ths=' . json_encode($ths148 ?? []); }
check('💰 عمود المستحق بثلاث حالات (كود + تشغيل فعلي بالكشف الشهري وsalary_all والبطاقة السنوية للمتعاقد: بالمبلغ/فارغ/غير موجود، عمود واحد يزيد أو ينقص فقط، بلا Fatal)',
      $ok148 && function_exists('dueColMode') && function_exists('dueTd') && function_exists('dueHead') && function_exists('dueCell') && function_exists('dueTotalCell')
      && strpos($fn148, '<select name="due_mode" onchange="this.form.submit()">') !== false && strpos($hd148, '<select name="due_mode" class="form-control form-control-sm"') !== false
      && strpos((string)file_get_contents($PROJ . '/switch_salarycomp.php'), "\$_SESSION['due_col_mode'] = (string)\$_GET['due_mode'];") !== false
      && substr_count($of148, 'dueHead(') === 3 && substr_count($of148, 'dueCell(') === 2 && substr_count($of148, 'dueTotalCell(') === 2 && substr_count($of148, 'dueTd(') === 2
      && strpos($of148, 'colspan="<?= 16 + compColsCount() + dueColsCount() + netFamColsCount() ?>"') !== false && strpos($of148, 'colspan="<?= 8 + compColsCount() + dueColsCount() + netFamColsCount() ?>"') !== false && strpos($of148, '$sdCols = 16 + compColsCount() + dueColsCount() + netFamColsCount();') !== false
      && strpos($rp148, '<?= transportHead() ?><?= dueHead() ?>') !== false && substr_count($rp148, 'dueTd(') === 2 && strpos($rp148, '($multi?17:16) + compColsCount() + dueColsCount()') !== false
      && strpos($as148, "\$showDue = dueColShown(); \$dueAmt = (dueColMode() === 'amount');") !== false && strpos($as148, '.salary-slip-table .due-blank { min-width: 130px; }') !== false && substr_count($as148, '<?php if ($showDue): ?>') === 3 && strpos($as148, "(\$isEmp ? 9 : (\$noCaisse ? 11 : 13)) + compColsCount() + (\$showDue ? 1 : 0)") !== false
      && strpos($ax148, "if (!dueColShown())              \$d[] = 16;") !== false /* 👨‍👩‍👧➕ 2026-09-20: العمود 14 صار الصافي+العائلي */ && substr_count($ax148, "dueColMode() === 'amount' ?") === 2
      && strpos($rx148, "if (dueColShown()) { \$head[] = 'الإجمالي المتوجب'; \$w[] = 18; }") !== false && substr_count($rx148, "if (dueColShown()) \$row[] = dueColMode() === 'amount' ?") === 2,
      $why148 ?: 'ok ths=' . json_encode($ths148 ?? []));

/**
 * 149) 👨‍👩‍👧 التعويض العائلي مؤرَّخ «من شهر ← إلى شهر» (2026-09-20 طانيوس طنوس/عبرا «حطّيت تعويضاً عائلياً وما بيّن ببطاقته السنوية»
 *      ⇒ «لازم نحطّ تاريخ من ← إلى وبيضلّ ياخد» + القانون بلسانه: لا يُقسَّم بين الزوجين · المتعاقد لا يستحقّ · موظف قانون العمل من الضمان):
 *      المصدر الواحد familyAllowanceForMonth بالمحرّك + مسار المنقولين (كان يركّب الإضافي والنقل فقط) + الفورم + الهيدر (تركيب/شفاء) + المخالفات + الصحّة.
 */
$fn149 = (string)file_get_contents($PROJ . '/includes/functions.php'); $pc149 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$em149 = (string)file_get_contents($PROJ . '/pages/employees.php'); $cp149 = (string)file_get_contents($PROJ . '/includes/compliance.php');
$hd149 = (string)file_get_contents($PROJ . '/includes/header.php'); $hc149 = (string)file_get_contents($PROJ . '/pages/health_check.php');
ensureFamilyAllowanceDateColumns();
check('👨‍👩‍👧 التعويض العائلي المؤرَّخ (كود): العمودان يتركّبان ذاتياً + المصدر الواحد بالمحرّك ومسار المنقولين + الفورم يحفظ من/إلى مع بداية افتراضية + الهيدر يركّب ويشفي + قاعدة المخالفات + فحص الصحّة للمتعاقد',
      (bool)$db->query("SHOW COLUMNS FROM employees LIKE 'family_allowance_spouse_from'")->fetch() && (bool)$db->query("SHOW COLUMNS FROM employees LIKE 'family_allowance_spouse_to'")->fetch()
      && (bool)$db->query("SHOW COLUMNS FROM employees LIKE 'family_allowance_children_from'")->fetch() && (bool)$db->query("SHOW COLUMNS FROM employees LIKE 'family_allowance_children_to'")->fetch() /* 👫 مدّتان مستقلّتان */
      && function_exists('familyAllowanceForMonth') && function_exists('defaultFamilyAllowanceFrom') && function_exists('familyAllowanceEligible') && function_exists('familyAllowanceFromKeyMin')
      && strpos($pc149, '$familyAllowance = familyAllowanceForMonth($emp, (int)$this->month, (int)$this->year);') !== false
      && strpos($pc149, "elseif (\$doFam && (\$famFromKey === null || \$mKey >= \$famFromKey)) \$newFam = familyAllowanceForMonth(\$empRow, (int)\$r['month'], (int)\$r['year']);") !== false
      && strpos($pc149, 'if (!$doAdd && !$doTr && !$doFam && !$famZeroAll) return 0;') !== false
      && strpos($pc149, '$newDue = max(0, $newNet + $newFam + $newTr);') !== false
      && strpos($em149, 'name="family_allowance_spouse_from"') !== false && strpos($em149, 'name="family_allowance_spouse_to"') !== false
      && strpos($em149, 'name="family_allowance_children_from"') !== false && strpos($em149, 'name="family_allowance_children_to"') !== false && strpos($em149, 'name="family_allowance_from"') === false
      && substr_count($em149, 'applyFamilyAllowanceDates($db, $id, $data);') === 2 && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), '$dflt = $dflt ?: defaultFamilyAllowanceFrom($id, $db); $from = $dflt;') !== false /* 👨‍👩‍👧📋 الدالة صارت مشتركة بـfunctions.php (2026-09-24) */
      && strpos($pc149, '$famFromKey = familyAllowanceFromKeyMin($empRow);') !== false && strpos($cp149, '$fromKey = familyAllowanceFromKeyMin($r);') !== false
      && strpos($em149, 'id="famAllowTotal"') !== false && strpos($em149, "\$famTotNow = isset(\$employee['id']) ? familyAllowanceForMonth(\$employee, (int)date('n'), (int)date('Y')) : 0;") !== false /* 🧮 مجموع التعويضات بالملف (2026-09-20) */
      && strpos($hd149, 'ensureFamilyAllowanceDateColumns();') !== false && strpos($hd149, 'healFamilyAllowanceFrom20260920();') !== false
      && strpos($cp149, "'family_allow_stale' => ['Alloc. familiales ≠ dossier'") !== false && strpos($cp149, "case 'tax_stale': case 'family_allow_stale':") !== false
      && strpos($hc149, "ms.family_allowance_lbp > 0 AND e.employee_type = 'enseignant_contractuel'") !== false);
// الدالة الواحدة على حالات صريحة
// 👫 مدّتان مستقلّتان: الزوجة تشرين الأول ← كانون الأول 2026، الأولاد تشرين الأول 2026 ← آذار 2027
$e149 = ['employee_type' => 'employe', 'family_allowance_spouse_lbp' => 600000, 'family_allowance_children_lbp' => 900000, 'count_spouse_allowance' => 1, 'count_children_allowance' => 1, 'spouse_works' => 0,
         'family_allowance_spouse_from' => '2026-10-01', 'family_allowance_spouse_to' => '2026-12-01', 'family_allowance_children_from' => '2026-10-01', 'family_allowance_children_to' => '2027-03-01'];
$okF149 = familyAllowanceForMonth($e149, 10, 2026) === 1500000 && familyAllowanceForMonth($e149, 9, 2026) === 0 && familyAllowanceForMonth($e149, 12, 2026) === 1500000
       && familyAllowanceForMonth($e149, 1, 2027) === 900000 /* الزوجة انتهت، الأولاد مستمرّون */ && familyAllowanceForMonth($e149, 3, 2027) === 900000 && familyAllowanceForMonth($e149, 4, 2027) === 0
       && familyAllowanceForMonth(['family_allowance_children_from' => '2027-01-01'] + $e149, 11, 2026) === 600000 /* الأولاد لم يبدؤوا بعد، الزوجة نعم */
       && familyAllowanceForMonth(['spouse_works' => 1] + $e149, 11, 2026) === 900000
       && familyAllowanceForMonth(['employee_type' => 'enseignant_contractuel'] + $e149, 11, 2026) === 0
       && familyAllowanceForMonth(['employee_type' => 'enseignant_titulaire'] + $e149, 11, 2026) === 1500000
       && familyAllowanceForMonth(['family_allowance_spouse_from' => null, 'family_allowance_spouse_to' => null, 'family_allowance_children_from' => null, 'family_allowance_children_to' => null] + $e149, 1, 2020) === 1500000
       && familyAllowanceForMonth(['count_children_allowance' => 0] + $e149, 11, 2026) === 600000
       && familyAllowanceFromKeyMin($e149) === 2026 * 12 + 10 && familyAllowanceFromKeyMin(['family_allowance_spouse_from' => null] + $e149) === null && familyAllowanceFromKeyMin(['family_allowance_spouse_lbp' => 0, 'family_allowance_spouse_from' => null] + $e149) === 2026 * 12 + 10;
check('👨‍👩‍👧 familyAllowanceForMonth: مدّتان مستقلّتان (الزوجة تنتهي والأولاد يكمّلون / الأولاد يبدؤون لاحقاً) / قبل وبعد صفر / الزوج يعمل = الأولاد كاملاً / المتعاقد صفر / الملاك يأخذ / بلا تواريخ = من الأزل / زرّ الأولاد مطفأ / أبكر بداية', $okF149);
// تجربة فعلية (مع ترجيع كامل) على منقول بلا إعداد وبلا علاوات/نقل (فقط مسار التعويض يشتغل)
$t149 = null;
foreach ($db->query("SELECT e.id, e.school_id, ms.school_year sy, COUNT(*) n FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
    WHERE e.is_deleted = 0 AND e.employee_type = 'employe' AND COALESCE(e.base_salary_usd,0) = 0 AND COALESCE(e.contract_salary_lbp,0) = 0 AND COALESCE(e.salary_labor_law,0) = 0
      AND ms.base_plus_echelon_lbp > 0 AND COALESCE(ms.is_indemnity_month,0) = 0
      AND COALESCE(e.family_allowance_spouse_lbp,0) = 0 AND COALESCE(e.family_allowance_children_lbp,0) = 0 AND e.family_allowance_children_from IS NULL AND e.family_allowance_spouse_from IS NULL
      AND COALESCE(e.transport_daily_amount,0) = 0 AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = e.id)
      AND " . leftDateSql('e.') . " = '9999-12-31'
    GROUP BY e.id, ms.school_year HAVING n >= 10 ORDER BY ms.school_year DESC, e.id LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) as $cand149) {
    if (!isSchoolYearLocked((int)$cand149['school_id'], (string)$cand149['sy'])) { $t149 = $cand149; break; }
}
if ($t149) {
    $tid = (int)$t149['id']; $tsy = (string)$t149['sy']; [$ty1, $ty2] = schoolYearToYears($tsy);
    $snapE = $db->query("SELECT family_allowance_spouse_lbp sp, family_allowance_children_lbp ch, count_spouse_allowance cs, count_children_allowance cc, spouse_works sw, family_allowance_children_from ff, family_allowance_children_to ft, family_allowance_spouse_from sf, family_allowance_spouse_to st FROM employees WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
    $snapR = $db->query("SELECT id, family_allowance_lbp, total_due_lbp, total_due_usd, net_salary_lbp, net_salary_usd, transport_lbp FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy'")->fetchAll(PDO::FETCH_ASSOC);
    $setF = function ($sw, $from, $to) use ($db, $tid) { $db->prepare("UPDATE employees SET family_allowance_spouse_lbp = 0, family_allowance_children_lbp = 2310000, count_spouse_allowance = 1, count_children_allowance = 1, spouse_works = ?, family_allowance_children_from = ?, family_allowance_children_to = ?, family_allowance_spouse_from = NULL, family_allowance_spouse_to = NULL WHERE id = ?")->execute([$sw, $from, $to, $tid]); };
    $famRows = function () use ($db, $tid, $tsy) { $o = []; foreach ($db->query("SELECT month, year, family_allowance_lbp f, net_salary_lbp n, transport_lbp t, total_due_lbp d FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy' AND COALESCE(is_indemnity_month,0)=0 ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['year']*12+(int)$r['month']] = $r; return $o; };
    // أ) مبلغ من تشرين الأول بلا نهاية — قبل إعادة الحساب يظهر ببند المخالفات، وبعدها كل الأشهر 2,310,000 والمستحق يركب
    $setF(0, "$ty1-10-01", null);
    $okCp = false; try { foreach (complianceItems($db, $tsy) as $it) if ($it['rule'] === 'family_allow_stale' && (int)$it['emp_id'] === $tid) $okCp = true; } catch (Throwable $e) {}
    recalcEmployeeYear($tid, $tsy); $ra = $famRows();
    $okA = $ra && count(array_filter($ra, fn($r) => (int)$r['f'] === 2310000)) === count($ra) && count(array_filter($ra, fn($r) => (int)$r['d'] === (int)$r['n'] + (int)$r['f'] + (int)$r['t'])) === count($ra);
    $okCp2 = true; try { foreach (complianceItems($db, $tsy) as $it) if ($it['rule'] === 'family_allow_stale' && (int)$it['emp_id'] === $tid) $okCp2 = false; } catch (Throwable $e) {}
    $hs149 = renderPage('pages/annual_slip.php', ['employee_id' => $tid, 'school_year' => $tsy], ['extra', 'aide', 'transport'], [(int)$t149['school_id']], 'lbp', $tsy);
    $okSlip = strpos($hs149, '2,310,000') !== false && strpos($hs149, 'FATAL') === false;
    // ب) «إلى كانون الأول» ⇒ تشرين الأول-كانون الأول 2,310,000 وما بعدها صفر
    $setF(0, "$ty1-10-01", "$ty1-12-01"); recalcEmployeeYear($tid, $tsy); $rb = $famRows();
    $okB = (bool)$rb; foreach ($rb as $k => $r) { $exp = ($k <= $ty1 * 12 + 12) ? 2310000 : 0; if ((int)$r['f'] !== $exp || (int)$r['d'] !== (int)$r['n'] + (int)$r['f'] + (int)$r['t']) { $okB = false; break; } }
    // ج) الزوج يعمل ⇒ الأولاد كاملاً (لا تقسيم)
    $setF(1, "$ty1-10-01", null); recalcEmployeeYear($tid, $tsy); $rc = $famRows();
    $okC = $rc && count(array_filter($rc, fn($r) => (int)$r['f'] === 2310000)) === count($rc);
    // ترجيع كامل (الملف + الصفوف)
    $db->prepare("UPDATE employees SET family_allowance_spouse_lbp = ?, family_allowance_children_lbp = ?, count_spouse_allowance = ?, count_children_allowance = ?, spouse_works = ?, family_allowance_children_from = ?, family_allowance_children_to = ?, family_allowance_spouse_from = ?, family_allowance_spouse_to = ? WHERE id = ?")->execute([$snapE['sp'], $snapE['ch'], $snapE['cs'], $snapE['cc'], $snapE['sw'], $snapE['ff'], $snapE['ft'], $snapE['sf'], $snapE['st'], $tid]);
    $rs149 = $db->prepare("UPDATE monthly_salaries SET family_allowance_lbp = ?, total_due_lbp = ?, total_due_usd = ?, net_salary_lbp = ?, net_salary_usd = ?, transport_lbp = ? WHERE id = ?");
    foreach ($snapR as $r) $rs149->execute([$r['family_allowance_lbp'], $r['total_due_lbp'], $r['total_due_usd'], $r['net_salary_lbp'], $r['net_salary_usd'], $r['transport_lbp'], $r['id']]);
    $back = $db->query("SELECT SUM(family_allowance_lbp) f, SUM(total_due_lbp) d FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy'")->fetch(PDO::FETCH_ASSOC);
    $okR = (int)$back['f'] === (int)array_sum(array_column($snapR, 'family_allowance_lbp')) && (int)$back['d'] === (int)array_sum(array_column($snapR, 'total_due_lbp'));
    check("👨‍👩‍👧 تجربة فعلية على منقول بلا إعداد (#$tid $tsy): مبلغ بملفه من تشرين الأول ⇒ يظهر بالمخالفات ثم بعد إعادة الحساب كل أشهره 2,310,000 والمستحق يركب والبند يختفي + البطاقة السنوية تعرضه + «إلى كانون الأول» يصفّر ما بعده + الزوج يعمل = كاملاً + الترجيع",
          $okA && $okCp && $okCp2 && $okSlip && $okB && $okC && $okR,
          "A=" . (int)$okA . " comp=" . (int)$okCp . "/" . (int)$okCp2 . " slip=" . (int)$okSlip . " B=" . (int)$okB . " C=" . (int)$okC . " R=" . (int)$okR . " n=" . count($ra));
} else {
    check('👨‍👩‍👧 تجربة فعلية على منقول بلا إعداد: لا عيّنة مناسبة بالقاعدة', true, 'skipped');
}
// المتعاقد لا يستحقّ — المحرّك بلا حفظ على متعاقد حقيقي
$c149 = $db->query("SELECT e.id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_contractuel' AND ms.year = 2026 AND ms.month = 6 LIMIT 1")->fetchColumn();
if ($c149) {
    $cid149 = (int)$c149;
    $sn149 = $db->query("SELECT family_allowance_spouse_lbp sp, family_allowance_children_lbp ch FROM employees WHERE id = $cid149")->fetch(PDO::FETCH_ASSOC);
    $db->exec("UPDATE employees SET family_allowance_spouse_lbp = 500000, family_allowance_children_lbp = 700000 WHERE id = $cid149");
    $calcC149 = null; try { $calcC149 = (new PayrollCalculator($cid149, 6, 2026))->calculate(); } catch (Throwable $e) {}
    $db->prepare("UPDATE employees SET family_allowance_spouse_lbp = ?, family_allowance_children_lbp = ? WHERE id = ?")->execute([$sn149['sp'], $sn149['ch'], $cid149]);
    check("👨‍👩‍👧 المتعاقد لا يستحقّ تعويضاً عائلياً (قانون المعلمين — تجربة فعلية #$cid149 بالمحرّك بلا حفظ): مبلغ بملفه ⇒ 0",
          is_array($calcC149) && (int)$calcC149['family_allowance_lbp'] === 0, json_encode($calcC149['family_allowance_lbp'] ?? null));
}
check('👨‍👩‍👧 لا تعويض عائلي مخزّن لأي متعاقد (داتا حيّة)',
      (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_contractuel' AND ms.family_allowance_lbp > 0")->fetchColumn() === 0);

/**
 * 150) 👨‍👩‍👧➕ عمود «الصافي + التعويض العائلي» بجانب التعويض العائلي بثلاث حالات متل النقل والمستحق (2026-09-20 «فينا نزيد عامود بجانب
 *      التعويض العائلي عنوانو الصافي + تعويض العائلي وكمان يكون عندي خيار أقدر أتحكّم فيه متل عامود النقل وعامود المستحق»):
 *      netFamColMode/netFamColShown/netFamColsCount/netFamTd/netFamLbp (functions) + netFamHead/netFamCell/netFamTotalCell (report_helpers) +
 *      select netfam_mode بالشريط والترويسة + switch_salarycomp — عرض فقط. مطبَّق: كشف الرواتب الشهري (+إكسل) · salary_all · payment_list · ofState · البطاقة السنوية (+إكسلها).
 */
$fn150 = (string)file_get_contents($PROJ . '/includes/functions.php'); $rh150 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$of150 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp150 = (string)file_get_contents($PROJ . '/pages/reports.php');
$as150 = (string)file_get_contents($PROJ . '/pages/annual_slip.php'); $ax150 = (string)file_get_contents($PROJ . '/pages/annual_slip_export.php');
$rx150 = (string)file_get_contents($PROJ . '/pages/reports_export.php'); $hd150 = (string)file_get_contents($PROJ . '/includes/header.php');
$ok150 = true; $why150 = ''; $ths150 = [];
$con150 = $con145 ?: 0;
$lbl150 = 'الصافي + التعويض العائلي';
foreach (['amount', 'blank', 'none'] as $nm) {
    $hr = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], 'amount', $nm);
    $hs = $con150 ? renderPage('pages/annual_slip.php', ['employee_id' => $con150, 'school_year' => '2025-2026'], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], 'amount', $nm) : '';
    $ha = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], 'amount', $nm);
    $hp = renderPage('pages/official_forms.php', ['form' => 'payment_list', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], 'amount', $nm);
    $hd = renderPage('pages/official_forms.php', ['form' => 'salary_detail', 'month' => 10, 'year' => 2025], ['extra', 'aide', 'transport'], [3], 'lbp', '2025-2026', '', [], 'amount', $nm);
    $fat = strpos($hr . $hs . $ha . $hp . $hd, 'FATAL') !== false;
    $exp = $nm === 'none' ? 0 : 1;
    $cR = substr_count($hr, '<th>' . $lbl150); $cA = substr_count($ha, '<th>' . $lbl150); $cP = substr_count($hp, '<th>' . $lbl150 . ' (ل.ل)'); $cD = substr_count($hd, '<th rowspan="2">' . $lbl150); $cS = $con150 ? substr_count($hs, 'Net + alloc.<br>الصافي + عائلي') : $exp;
    if ($fat || $cR !== $exp || $cA !== $exp || $cP !== $exp || $cD !== $exp || $cS !== $exp) { $ok150 = false; $why150 .= " $nm: R=$cR A=$cA P=$cP D=$cD S=$cS fatal=" . (int)$fat; }
    // بوضع «بلا مبلغ» خلايا فارغة بعرض للكتابة؛ بوضع «بالمبلغ» لا
    $blankR = substr_count($hr, '<td style="min-width:110px">&nbsp;</td>');
    if ($nm === 'blank' && $blankR < 10) { $ok150 = false; $why150 .= " blank-cells=$blankR"; }
    if ($nm !== 'blank' && $con150 && $nm === 'amount' && strpos($hs, 'Net + alloc.') !== false && !preg_match('/<td>[^<]*<span class="sub-lbp">/', $hs)) { $ok150 = false; $why150 .= ' slip-no-amount'; }
    $ths150[$nm] = [substr_count($hr, '<th'), substr_count($ha, '<th'), substr_count($hp, '<th'), substr_count($hd, '<th'), $con150 ? substr_count($hs, '<th') : 0];
}
// عمود واحد فقط يزيد أو ينقص بكل جدول
foreach ([0, 1, 2, 3, 4] as $i) {
    if (!$con150 && $i === 4) continue;
    if ($ths150['amount'][$i] - $ths150['none'][$i] !== 1 || $ths150['blank'][$i] !== $ths150['amount'][$i]) { $ok150 = false; $why150 .= " ths[$i]=" . json_encode([$ths150['amount'][$i], $ths150['blank'][$i], $ths150['none'][$i]]); }
}
check('👨‍👩‍👧➕ عمود «الصافي + التعويض العائلي» بثلاث حالات (كود + تشغيل فعلي: الكشف الشهري وsalary_all وpayment_list وجميع الأساتذة والبطاقة السنوية — بالمبلغ/فارغ/غير موجود، عمود واحد يزيد أو ينقص فقط، بلا Fatal)',
      $ok150 && function_exists('netFamColMode') && function_exists('netFamTd') && function_exists('netFamLbp') && function_exists('netFamHead') && function_exists('netFamCell') && function_exists('netFamTotalCell')
      && strpos($fn150, '<select name="netfam_mode" onchange="this.form.submit()">') !== false && strpos($hd150, '<select name="netfam_mode" class="form-control form-control-sm"') !== false
      && strpos((string)file_get_contents($PROJ . '/switch_salarycomp.php'), "\$_SESSION['netfam_col_mode'] = (string)\$_GET['netfam_mode'];") !== false
      && substr_count($of150, 'netFamHead(') === 3 && substr_count($of150, 'netFamCell(') === 2 && substr_count($of150, 'netFamTotalCell(') === 2 && substr_count($of150, 'netFamTd(') === 2
      && strpos($rp150, "<?= netFamHead('', 'الصافي + التعويض العائلي' . rateHead('mkt', \$month, \$year)) ?><?= transportHead() ?><?= dueHead() ?>") !== false && substr_count($rp150, 'netFamTd(') === 2
      && strpos($as150, "\$showNetFam = netFamColShown(); \$netFamAmt = (netFamColMode() === 'amount');") !== false && substr_count($as150, '<?php if ($showNetFam): ?>') === 3
      && strpos($as150, "+ (\$showDue ? 1 : 0) + (\$showNetFam ? 1 : 0)") !== false
      && strpos($ax150, "if (!netFamColShown())           \$d[] = 14;") !== false && substr_count($ax150, "netFamColMode() === 'amount' ?") === 2 && strpos($ax150, "'Net + alloc. / الصافي + عائلي'") !== false
      && strpos($rx150, "if (netFamColShown()) { \$head[] = 'الصافي + التعويض العائلي'; \$w[] = 18; }") !== false && substr_count($rx150, "if (netFamColShown()) \$row[] = netFamColMode() === 'amount' ?") === 2,
      $why150 ?: 'ok ths=' . json_encode($ths150));

/**
 * 151) 📅 «p1 قلتلك ما تخرب البطاقة شوف آب وأيلول» (2026-09-20 طانيوس طنّوس): منقول بلا إعداد صار «12 شهراً» بملفه بعدما انفتحت سنته بـ10
 *      ⇒ آب/أيلول «—» بالبطاقة لأن لا صفّ لهما. fillCarriedMissingMonths (داخل overlayStoredYearBonuses): الأشهر المتوقّعة بعد آخر شهر مخزّن تُخلق
 *      نسخةً عنه (غير مدفوعة) ثم يُركَّب ما بالملف — بلا حذف أبداً + شفاء مرّة واحدة بالهيدر.
 */
$pc151 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php'); $hd151 = (string)file_get_contents($PROJ . '/includes/header.php');
$ok151 = function_exists('fillCarriedMissingMonths') && strpos($pc151, 'fillCarriedMissingMonths((int)$employeeId, (string)$schoolYear);') !== false
       && strpos($pc151, "if (\$k <= \$lastKey || isset(\$have[\$k])) continue;") !== false && (preg_match('/function fillCarriedMissingMonths\(.*?
\}/s', $pc151, $fb151) === 1 && !preg_match('/DELETE\s+FROM/i', $fb151[0])) /* لا حذف داخل دالة الاستكمال نفسها (is_deleted ليس حذفاً) */
       && strpos($hd151, 'healCarriedMissingMonths20260920();') !== false && function_exists('healCarriedMissingMonths20260920');
$why151 = 'code=' . (int)$ok151;
// تجربة فعلية (مع ترجيع كامل): منقول «10 أشهر» بـ10 صفوف بسنة غير مقفولة ⇒ 12 شهراً ⇒ آب/أيلول يُخلقان نسخةً عن تموز، غير مدفوعين، والبطاقة بلا «—»
$t151 = null;
foreach ($db->query("SELECT e.id, e.school_id, ms.school_year sy, COUNT(*) n, MAX(ms.year*12+ms.month) lk FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND COALESCE(ms.is_indemnity_month,0) = 0
    WHERE e.is_deleted = 0 AND e.employee_type = 'employe' AND COALESCE(e.payment_months_per_year,10) = 10 AND COALESCE(e.base_salary_usd,0) = 0 AND COALESCE(e.contract_salary_lbp,0) = 0 AND COALESCE(e.salary_labor_law,0) = 0
      AND ms.base_plus_echelon_lbp > 0 AND " . leftDateSql('e.') . " = '9999-12-31' AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = e.id) AND COALESCE(e.transport_daily_amount,0) = 0
    GROUP BY e.id, ms.school_year HAVING n = 10 ORDER BY ms.school_year DESC, e.id LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) as $c151) {
    [$cy1, $cy2] = schoolYearToYears($c151['sy']);
    if ((int)$c151['lk'] === $cy2 * 12 + 7 && !isSchoolYearLocked((int)$c151['school_id'], (string)$c151['sy'])) { $t151 = $c151; break; }
}
if ($t151) {
    $tid = (int)$t151['id']; $tsy = (string)$t151['sy']; [$ty1, $ty2] = schoolYearToYears($tsy);
    $ids0 = $db->query("SELECT GROUP_CONCAT(id) FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy'")->fetchColumn();
    $jul = $db->query("SELECT net_salary_lbp, total_due_lbp, base_salary_lbp FROM monthly_salaries WHERE employee_id = $tid AND year = $ty2 AND month = 7")->fetch(PDO::FETCH_ASSOC);
    $db->exec("UPDATE employees SET payment_months_per_year = 12 WHERE id = $tid");
    recalcEmployeeYear($tid, $tsy);
    $after = $db->query("SELECT month, net_salary_lbp net, total_due_lbp due, base_salary_lbp b, is_paid p FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy' AND year = $ty2 AND month IN (8,9) ORDER BY month")->fetchAll(PDO::FETCH_ASSOC);
    $n12 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy'")->fetchColumn();
    $okRows = count($after) === 2 && $n12 === 12;
    foreach ($after as $a) if ((int)$a['net'] !== (int)$jul['net_salary_lbp'] || (int)$a['b'] !== (int)$jul['base_salary_lbp'] || (int)$a['p'] !== 0) $okRows = false;
    $hs151 = renderPage('pages/annual_slip.php', ['employee_id' => $tid, 'school_year' => $tsy], ['extra', 'aide', 'transport'], [(int)$t151['school_id']], 'lbp', $tsy);
    $dash = preg_match_all('/<td colspan="\d+" class="text-muted">—<\/td>/u', $hs151);
    $okSlip = $dash === 0 && strpos($hs151, 'FATAL') === false && strpos($hs151, 'Sept. ' . $ty2) !== false;
    // إعادة التشغيل لا تخلق شيئاً (idempotent)
    $again = fillCarriedMissingMonths($tid, $tsy);
    // ترجيع: حذف الصفّين اللذين خلقتهما التجربة + الملف
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy' AND id NOT IN ($ids0)");
    $db->exec("UPDATE employees SET payment_months_per_year = 10 WHERE id = $tid");
    $nBack = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$tsy'")->fetchColumn();
    $ok151 = $ok151 && $okRows && $okSlip && $again === 0 && $nBack === 10;
    $why151 .= " #$tid $tsy rows=" . (int)$okRows . " (n=$n12) slip=" . (int)$okSlip . " (dash=$dash) again=$again back=$nBack";
} else { $why151 .= ' · لا عيّنة (skipped)'; }
check('📅 المنقول الذي صار «12 شهراً»: آب/أيلول يُخلقان نسخةً عن آخر شهر (غير مدفوعين) تلقائياً + البطاقة بلا «—» + idempotent + لا حذف بالكود + شفاء بالهيدر', $ok151, $why151);

/**
 * 152) 📆 «السنة الدراسية من ت1 لغاية أيلول لازم تطبّق تلقائياً على الجميع أساتذة مع موظفين — إذا بدّي أعطي حدا لتاريخ محدّد بفوت على ملفه
 *      وبغيّر» (2026-09-20): الافتراضي 12 (فورم + حفظ) + تسميات المدى + شفاء تدريجي (الكل 12 + استكمال السنة الجارية/اللاحقة) +
 *      بطاقة السنة الماضية بلا «—» بعد آخر شهر مخزّن.
 */
$em152 = (string)file_get_contents($PROJ . '/pages/employees.php'); $fn152 = (string)file_get_contents($PROJ . '/includes/functions.php');
$hd152 = (string)file_get_contents($PROJ . '/includes/header.php'); $ad152 = (string)file_get_contents($PROJ . '/includes/annual_slip_data.php');
$hNew152 = renderPage('pages/employees.php', ['action' => 'new'], [], [3]);
$sel152 = preg_match('/<option value="12" selected>12 mois — Oct\. → Sept\./u', $hNew152) === 1;
$pmBad152 = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND COALESCE(payment_months_per_year,10) <> 12")->fetchColumn();
$healSt152 = (string)getSetting('heal_pm12_20260920', '');
// بطاقة سنة ماضية بـ10 أشهر مخزّنة: لا صفوف «—» لآب/أيلول
$past152 = $db->query("SELECT e.id, e.school_id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2025-2026' AND COALESCE(ms.is_indemnity_month,0) = 0
    WHERE e.is_deleted = 0 GROUP BY e.id HAVING COUNT(*) = 10 AND MAX(ms.year*12+ms.month) = 2026*12+7 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$okPast152 = true; $dash152 = -1;
if ($past152 && strcmp('2025-2026', currentSchoolYear()) < 0) {
    $hp152 = renderPage('pages/annual_slip.php', ['employee_id' => (int)$past152['id'], 'school_year' => '2025-2026'], ['extra', 'aide', 'transport'], [(int)$past152['school_id']], 'lbp', '2025-2026');
    $dash152 = preg_match_all('/<td colspan="\d+" class="text-muted">—<\/td>/u', $hp152);
    $okPast152 = $dash152 === 0 && strpos($hp152, 'FATAL') === false && strpos($hp152, 'Juil. 2026') !== false && strpos($hp152, 'Août 2026') === false;
}
check('📆 السنة الدراسية ت1 ← أيلول للجميع: الافتراضي 12 بالفورم والحفظ + التسميات بالمدى + الشفاء التدريجي مربوط + لا فاعل على غير 12 بعد الخطوة ١ + بطاقة السنة الماضية بلا «—» بعد آخر شهر',
      $sel152 && strpos($em152, "'payment_months_per_year' => (int)(\$_POST['payment_months_per_year'] ?? 12)") !== false
      && strpos($em152, '12 mois — Oct. → Sept. / 12 شهراً (ت1 ← أيلول) — للجميع تلقائياً') !== false
      && function_exists('healPaymentMonths12_20260920') && strpos($hd152, 'healPaymentMonths12_20260920();') !== false
      && strpos($fn152, "UPDATE employees SET payment_months_per_year = 12 WHERE is_deleted = 0 AND COALESCE(payment_months_per_year, 10) <> 12") !== false
      && strpos($ad152, "if (strcmp((string)\$schoolYear, (string)currentSchoolYear()) < 0 && \$salaries) {") !== false
      && ($pmBad152 === 0 || $healSt152 === '') && $okPast152,
      "sel=" . (int)$sel152 . " pmBad=$pmBad152 heal=" . mb_substr($healSt152, 0, 40) . " pastDash=$dash152");

/**
 * 153) 💵 متعاقدو عبرا 2026-2027 بأساس الراتب بالدولار من إكسله (2026-09-20): 16 ملفاً direct_usd بالمبلغ + إضافيهم 2026-2027 مطفأ
 *      (لا تضاعف) + صافي تشرين الأول 2026 لا يتجاوز الأساس بالدولار + 2025-2026 لم تُمسّ (نسخة احتياطية موجودة).
 */
$hd153 = (string)file_get_contents($PROJ . '/includes/header.php');
$map153 = [1815 => 400, 1821 => 400, 1397 => 500, 1816 => 700, 1847 => 750, 1106 => 670, 1817 => 400, 1822 => 400, 175 => 815, 141 => 860, 1819 => 460, 1820 => 400, 1560 => 700, 1000016 => 600, 1000017 => 600, 1000018 => 600]; // 💵 تعديله الثاني (مدوَّرة)
$st153 = (string)getSetting('heal_abra_cw_usd2_20260920', ''); // بعد التعديل الثاني
$net153 = strpos((string)getSetting('heal_abra_cw_net_20260921', ''), 'done') === 0;
$ok153 = function_exists('healAbraContractUsd20260920') && strpos($hd153, 'healAbraContractUsd20260920();') !== false && function_exists('healAbraContractUsd2_20260920') && strpos($hd153, 'healAbraContractUsd2_20260920();') !== false; $why153 = 'heal=' . mb_substr($st153, 0, 12);
if (strpos($st153, 'done') === 0) {
    $bad153 = [];
    foreach ($map153 as $eid => $usd) {
        $e = $db->query("SELECT salary_input_mode m, base_salary_usd u, school_id s FROM employees WHERE id = $eid AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if (!$e) continue; // غير موجود بهذه القاعدة
        $act = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id = $eid AND is_active = 1 AND bonus_type IN ('prime_fixe','aide_complementaire') AND (school_year IS NULL OR school_year >= '2026-2027')")->fetchColumn();
        $oct = $db->query("SELECT base_salary_lbp b, ROUND(net_salary_lbp/NULLIF(exchange_rate,0)) n, exchange_rate r, extra_lbp + prime_fixe_lbp + aide_complementaire_lbp ex FROM monthly_salaries WHERE employee_id = $eid AND school_year = '2026-2027' AND month = 10")->fetch(PDO::FETCH_ASSOC);
        if ($net153) $usd = (float)$e['u']; // 💵 بعد شفاء الصافي (2026-09-21) الأساس صار محسوباً من الصافي — يُفحص بالـ154
        if ($e['m'] !== 'direct_usd' || (float)$e['u'] !== (float)$usd || $act !== 0 || !$oct || (int)$oct['ex'] !== 0 || (int)$oct['n'] > $usd + 1 || abs((int)$oct['b'] - (int)floor($usd * (float)$oct['r'])) > 1) $bad153[] = "#$eid m={$e['m']} u={$e['u']} act=$act oct=" . json_encode($oct);
    }
    $ok153 = $ok153 && !$bad153; $why153 .= $bad153 ? ' bad=' . implode(' · ', $bad153) : ' 16 ok';
}
check('💵 متعاقدو عبرا بأساس دولار (كود + بعد الشفاء: 16 ملفاً direct_usd بمبلغ الإكسل، الإضافي 2026-2027 مطفأ، أساس ت1 = $×سعر الشهر، صافي ≤ الأساس بالدولار)', $ok153, $why153);

/**
 * 154) 💵 «هودي الرواتب بدي ياهن رواتب صافية بعد المحسومات — شوف قديش قيمة أساس الراتب وتحطو» (2026-09-21): إكسل «متعاقد عبرا.xlsx»
 *      = الصافي بالدولار. بعد الشفاء: 16 ملفاً direct_usd، الأساس بالدولار ≥ الصافي المطلوب، وكل أشهر 2026-2027: الصافي داون للدولار
 *      = المبلغ تماماً (لا أقلّ ولا دولار زيادة) + أساس الشهر = floor($ × السعر) + الصافي بالليرة داون للألف + النسخة الاحتياطية موجودة.
 */
$hd154 = (string)file_get_contents($PROJ . '/includes/header.php');
$map154 = [1815 => 400, 1821 => 400, 1397 => 498, 1816 => 702, 1847 => 750, 1106 => 667, 1817 => 400, 1822 => 400, 175 => 812, 141 => 857, 1819 => 462, 1820 => 400, 1560 => 698, 1000016 => 600, 1000017 => 600, 1000018 => 600];
$st154 = (string)getSetting('heal_abra_cw_net_20260921', '');
$pc154 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$ok154 = function_exists('healAbraContractNet20260921') && strpos($hd154, 'healAbraContractNet20260921();') !== false
    && function_exists('usdBaseAppliesForMonth') && function_exists('ensureUsdBaseFromSyColumn') && strpos($hd154, 'ensureUsdBaseFromSyColumn();') !== false
    && substr_count($pc154, 'usdBaseAppliesForMonth($emp, (int)$this->month, (int)$this->year)') === 2
    && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'name="usd_base_from_sy"') !== false
    && strpos((string)file_get_contents($PROJ . '/includes/excel_salaries.php'), 'usd_base_from_sy = ?') !== false; $why154 = 'heal=' . mb_substr($st154, 0, 12);
if (strpos($st154, 'done') === 0) {
    $bad154 = [];
    foreach ($map154 as $eid => $net) {
        $e = $db->query("SELECT salary_input_mode m, base_salary_usd u FROM employees WHERE id = $eid AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if (!$e) continue;
        $rows = $db->query("SELECT month, base_salary_lbp b, net_salary_lbp n, exchange_rate r FROM monthly_salaries WHERE employee_id = $eid AND school_year = '2026-2027'")->fetchAll(PDO::FETCH_ASSOC);
        if ($e['m'] !== 'direct_usd' || (float)$e['u'] < $net || count($rows) < 12) { $bad154[] = "#$eid m={$e['m']} u={$e['u']} rows=" . count($rows); continue; }
        foreach ($rows as $r) {
            $nu = (int)floor((int)$r['n'] / (float)$r['r']);
            if ($nu !== $net || (int)$r['n'] % 1000 !== 0 || abs((int)$r['b'] - (int)floor((float)$e['u'] * (float)$r['r'])) > 1) { $bad154[] = "#$eid m{$r['month']} net={$r['n']}⇒{$nu}$≠{$net} b={$r['b']}"; break; }
        }
    }
    // 📅 أساس الدولار يسري من 2026-2027: كل الـ16 مؤرَّخون + حساب حيّ (بلا حفظ) لتشرين الأول 2025 لفيوليت = راتبها القديم بالليرة 2,225,000 لا الدولار
    $fs154 = (int)$db->query("SELECT COUNT(*) FROM employees WHERE id IN (" . implode(',', array_keys($map154)) . ") AND is_deleted = 0 AND usd_base_from_sy = '2026-2027'")->fetchColumn();
    $n154 = (int)$db->query("SELECT COUNT(*) FROM employees WHERE id IN (" . implode(',', array_keys($map154)) . ") AND is_deleted = 0")->fetchColumn();
    if ($fs154 !== $n154) $bad154[] = "from_sy $fs154/$n154";
    try { $v154 = (new PayrollCalculator(141, 10, 2025))->calculate(); if ((int)$v154['base_salary_lbp'] !== 2225000) $bad154[] = 'فيوليت ت1 2025 أساس=' . (int)$v154['base_salary_lbp']; } catch (Throwable $e) { $bad154[] = 'calc141: ' . $e->getMessage(); }
    $bk154 = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '_bk_abra_cw_net_20260921_emp'")->fetchColumn();
    if (!$bk154) $bad154[] = 'no backup';
    $ok154 = $ok154 && !$bad154; $why154 .= $bad154 ? ' bad=' . implode(' · ', $bad154) : ' 16 ok (12 شهراً كلّ واحد)';
}
check('💵 متعاقدو عبرا: إكسله = الصافي بعد المحسومات ⇒ الأساس بالدولار محسوب بالمحرّك + 📅 «يسري من 2026-2027» (عمود ذاتي + خانة بالملف + الإكسل يؤرّخ) فتبقى 2025-2026 بالليرة (كود + بعد الشفاء: كل أشهر 2026-2027 صافي = المبلغ تماماً + فيوليت ت1 2025 حيّاً = 2,225,000)', $ok154, $why154);

/**
 * 155) 🔎 «ما عندك طريقة تكتشف الأخطاء دفعة واحدة بها البرنامج بدل ما كل يوم نكتشف خطأ جديد… ما إلنا حق نلخبط برواتب الناس» (2026-09-21):
 *      الفحص الشامل الدوري — كل شهر مخزّن يُعاد حسابه بالمحرّك الحيّ ويُقارَن رقماً رقماً ⇒ بند month_stale بتقرير المخالفات (بلا تصحيح
 *      جماعي/تلقائي). كود: الدوال + الترويسة + القاعدة + التصحيح. تشغيل فعلي: صفّ مطابق لا يُبلَّغ، وتحريف ضمانه +7,777 يُبلَّغ ثم يُسترجَع.
 */
$fn155 = (string)file_get_contents($PROJ . '/includes/functions.php'); $cp155 = (string)file_get_contents($PROJ . '/includes/compliance.php'); $hd155 = (string)file_get_contents($PROJ . '/includes/header.php');
$ok155 = function_exists('monthStaleCompare') && function_exists('monthStaleScanStep') && function_exists('ensureMonthStaleTable')
      && strpos($hd155, 'monthStaleScanStep();') !== false && strpos($cp155, "'month_stale'    => ['Mois ≠ moteur'") !== false
      && strpos($cp155, "FROM month_stale_findings f JOIN employees e") !== false && strpos($cp155, "case 'month_stale':") !== false
      && strpos($cp155, "&& \$rule !== 'month_stale') \$keys[] = \$it['key'];") !== false && strpos($cp155, "&& \$rk !== 'month_stale'") !== false;
$why155 = 'code=' . ($ok155 ? 'ok' : 'missing');
try {
    ensureMonthStaleTable();
    // موظف يسمح له المحرّك وله أشهر 2026-2027 مطابقة (نأخذ أوّل واحد ليس ببند month_stale محلياً)
    $cand155 = $db->query("SELECT e.* FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = '2026-2027' AND ms.net_salary_lbp > 0
        LEFT JOIN month_stale_findings f ON f.employee_id = e.id AND f.school_year = '2026-2027'
        WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_contractuel' AND e.contract_salary_lbp > 0 AND f.employee_id IS NULL AND COALESCE(e.left_date_all,'9999-12-31') = '9999-12-31' AND COALESCE(e.cadre_from_sy,'') = ''
        GROUP BY e.id ORDER BY e.id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $pick = null; $r0 = null;
    foreach ($cand155 as $c) { $r = monthStaleCompare($c, '2026-2027', $db); if ($r === null) { $pick = $c; break; } }
    if (!$pick) { $ok155 = false; $why155 .= ' no clean candidate'; }
    else {
        $row = $db->query("SELECT month, year, cnss_amount_lbp FROM monthly_salaries WHERE employee_id = " . (int)$pick['id'] . " AND school_year = '2026-2027' AND net_salary_lbp > 0 ORDER BY year, month LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $db->exec("UPDATE monthly_salaries SET cnss_amount_lbp = cnss_amount_lbp + 7777 WHERE employee_id = " . (int)$pick['id'] . " AND school_year = '2026-2027' AND month = " . (int)$row['month'] . " AND year = " . (int)$row['year']);
        $r1 = monthStaleCompare($pick, '2026-2027', $db);
        $db->exec("UPDATE monthly_salaries SET cnss_amount_lbp = " . (int)$row['cnss_amount_lbp'] . " WHERE employee_id = " . (int)$pick['id'] . " AND school_year = '2026-2027' AND month = " . (int)$row['month'] . " AND year = " . (int)$row['year']);
        $r2 = monthStaleCompare($pick, '2026-2027', $db);
        $ok155 = $ok155 && $r1 !== null && (int)$r1['months'] === 1 && isset($r1['fields']['cnss_amount_lbp']) && $r2 === null;
        $why155 .= ' #' . $pick['id'] . ' tamper=' . ($r1 ? $r1['months'] . 'm ' . implode(',', array_keys($r1['fields'])) : 'null') . ' restored=' . ($r2 === null ? 'clean' : 'dirty');
    }
} catch (Throwable $e) { $ok155 = false; $why155 .= ' err=' . $e->getMessage(); }
check('🔎 الفحص الشامل الدوري «شهر مخزّن ≠ المحرّك الحيّ» (كود + ترويسة + قاعدة month_stale بلا تصحيح جماعي + تجربة فعلية: تحريف ضمان شهر يُكشَف ويُسترجَع)', $ok155, $why155);

/**
 * 156) ⚖️📅 «طبّق القانون على كل البرنامج ابتداءً من 1-10-2026» (2026-09-21): إعداد مؤرَّخ law_enforce_from_sy (افتراضياً 2026-2027) —
 *      الفحص الشامل يبدأ منه (monthStaleYears) وتقرير المخالفات لسنة أقدم = لافتة «تاريخ مدفوع» بلا بنود + خانة تغييره بالصفحة (comp_law_from).
 */
$cp156 = (string)file_get_contents($PROJ . '/includes/compliance.php'); $pg156 = (string)file_get_contents($PROJ . '/pages/compliance.php');
$ok156 = function_exists('lawEnforceFromSy') && lawEnforceFromSy() >= '2026-2027' && !in_array('2025-2026', monthStaleYears(), true)
      && strpos($cp156, "\$items = \$beforeLaw ? [] : complianceItems(\$db, \$sy);") !== false && strpos($cp156, "'comp_law_from'") !== false
      && strpos($pg156, "if (!empty(\$rep['before_law'])):") !== false && strpos($pg156, 'name="law_from"') !== false;
$why156 = 'from=' . lawEnforceFromSy() . ' years=' . implode(',', monthStaleYears());
try {
    $sv156 = $_SESSION['active_school_year'] ?? null; $_SESSION['active_school_year'] = '2025-2026';
    $rep156 = complianceBuild($db);
    $_SESSION['active_school_year'] = $sv156;
    $ok156 = $ok156 && !empty($rep156['before_law']) && $rep156['items'] === [] && $rep156['pending'] === [];
    $why156 .= ' 2025-2026: before_law=' . (int)!empty($rep156['before_law']) . ' items=' . count($rep156['items']);
} catch (Throwable $e) { $ok156 = false; $why156 .= ' err=' . $e->getMessage(); }
check('⚖️📅 تطبيق القانون ابتداءً من 2026-2027 (إعداد مؤرَّخ + الفحص الشامل من تلك السنة + تقرير 2025-2026 = تاريخ مدفوع بلا بنود + خانة التغيير)', $ok156, $why156);

/**
 * 157) 🪪 «دايماً بكل التقارير والإفادات بس بدك تحط رقم الضمان لازم يكون بجانبو دغري على الشمال سنة تاريخ الولادة مثلاً 1968-1242983» (2026-09-21 p1
 *      مستند تصفية نهاية الخدمة): cnssWithBirthYear بكل مواضع العرض (إفادات، كشف الراتب الشهري، النماذج الرسمية والتصفية ونهاية الخدمة، التقارير،
 *      التصدير) + مربّعات الرقم بالنماذج مسبوقة بالسنة (nssfBoxesWithYear). لا عرض خامّ متبقٍّ.
 */
$src157 = ['pages/attestations.php', 'pages/monthly_payroll.php', 'pages/official_forms.php', 'includes/eos_forms.php', 'pages/reports.php', 'pages/reports_export.php', 'pages/official_export.php'];
$raw157 = [];
foreach ($src157 as $f) {
    foreach (explode("\n", (string)file_get_contents($PROJ . '/' . $f)) as $ln => $line) {
        if (strpos($line, 'nssf_number') === false) continue;
        if (preg_match('/cnssWithBirthYear|nssfBoxesWithYear|e\.nssf_number|SELECT|\$_POST|name="nssf_number"|hasReg = |\'R6\'|\'O6\'|K11|K13|N13|12\.8, 32\.4|nssfDigits/', $line)) continue;
        $raw157[] = $f . ':' . ($ln + 1);
    }
}
$ok157 = function_exists('cnssWithBirthYear') && function_exists('nssfBoxesWithYear')
      && cnssWithBirthYear('1242983', '1968-05-02') === '1968-1242983' && cnssWithBirthYear('1242983', '') === '1242983' && cnssWithBirthYear('', '', '') === ''
      && strpos(nssfBoxesWithYear('1242983', '1968-05-02', 8), '1968-</b>') !== false && strpos(nssfBoxesWithYear('1242983', '1968-05-02', 8), 'dir="ltr"') !== false
      && substr_count((string)file_get_contents($PROJ . '/pages/official_forms.php'), 'nssfBoxesWithYear(') === 3
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "'nssf' => cnssWithBirthYear(\$nssfDigits, \$r['birth_date'] ?? '', '')") !== false
      && substr_count((string)file_get_contents($PROJ . '/includes/eos_forms.php'), "ofe('emp_no', cnssWithBirthYear(") === 3
      && !$raw157;
check('🪪 رقم الضمان مسبوقاً بسنة الولادة (1968-1242983) بكل الإفادات والكشوف والنماذج الرسمية والتقارير والتصدير + مربّعات النماذج + لا عرض خامّ متبقٍّ', $ok157, $raw157 ? 'raw=' . implode(',', $raw157) : 'ok');

/**
 * 158) 📄 «p1 شوف يا أستاذ كل التقارير لازم تكون مطابقة لبطاقة الراتب السنوية» (2026-09-21): قاعدة البطاقة لدولار أعمدة الراتب صارت المصدر
 *      الواحد بكل الكشوف — الأساس والدرجة بالليرة فقط، «بعد التدرّج» ÷1500 لأصحاب النسبة فقط (كانت الكشوف تقسم ÷1500 للجميع: ايف عيد 24,605$).
 *      كود: الدوال + لا lawUsd/lawUsdSql خامّ للأساس/الدرجة + رؤوس «1$=1,500» على «بعد التدرّج» فقط. تجربة: متعاقد بالدولار بلا دولار قانون، وصاحب نسبة ÷1500.
 */
$of158 = (string)file_get_contents($PROJ . '/pages/official_forms.php'); $rp158 = (string)file_get_contents($PROJ . '/pages/reports.php'); $rh158 = (string)file_get_contents($PROJ . '/includes/report_helpers.php');
$ok158 = function_exists('pctLawHolderIds') && function_exists('isPctLawRow') && function_exists('lawUsdRow') && function_exists('dualLaw')
      && preg_match_all('/lawUsd\(\(int\)\$r\[' . "'" . 'base_salary_lbp' . "'" . '\]\)|lawUsd\(\(int\)\$r\[' . "'" . 'echelon_value_lbp' . "'" . '\]\)|lawUsdSql\(' . "'" . '(ms\.)?base_salary_lbp' . "'" . '\)/', $of158 . $rp158 . $rh158) === 0
      && substr_count($of158, "rateHead('law')") === 4 && substr_count($rp158, "rateHead('law')") === 1
      && strpos($of158, "أساس الراتب<?= rateHead('law') ?>") === false && strpos($rp158, "أساس الراتب<?= rateHead('law') ?>") === false
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "الراتب بعد التدرّج لأصحاب النسبة بالسعر الرسمي") !== false;
$why158 = 'code=' . ($ok158 ? 'ok' : 'bad');
try {
    $cur158 = $_SESSION['display_currency'] ?? null; $_SESSION['display_currency'] = 'both';
    $c158 = $db->query("SELECT ms.* FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_contractuel' AND e.salary_input_mode = 'direct_usd' AND e.base_salary_usd > 0 AND ms.school_year = '2026-2027' AND ms.month = 10 AND ms.base_salary_lbp > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $p158 = $db->query("SELECT ms.* FROM monthly_salaries ms JOIN employee_bonuses b ON b.employee_id = ms.employee_id AND b.bonus_type = 'prime_fixe' AND b.is_active = 1 AND b.value_type = 'percent' AND (b.school_year IS NULL OR b.school_year = '2026-2027') WHERE ms.school_year = '2026-2027' AND ms.month = 10 AND ms.base_plus_echelon_lbp > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $t1 = $c158 ? (lawUsdRow($c158, 'base') == 0 && lawUsdRow($c158, 'bpe') == 0 && !isPctLawRow($c158) && strpos(moneyLaw($c158['base_salary_lbp'], ['withCur' => false], $c158, 'bpe'), '$') === false) : false;
    $t2 = $p158 ? (isPctLawRow($p158) && lawUsdRow($p158, 'bpe') == floor($p158['base_plus_echelon_lbp'] / officialUsdRate()) && lawUsdRow($p158, 'base') == 0 && strpos(moneyLaw($p158['base_plus_echelon_lbp'], ['withCur' => false], $p158, 'bpe'), 'money-usd') !== false) : false;
    $ok158 = $ok158 && $t1 && $t2; if ($cur158 === null) unset($_SESSION['display_currency']); else $_SESSION['display_currency'] = $cur158;
    $why158 .= ' contract#' . ($c158['employee_id'] ?? '-') . '=' . ($t1 ? 'lbp-only' : 'BAD') . ' pct#' . ($p158['employee_id'] ?? '-') . '=' . ($t2 ? '÷1500' : 'BAD');
} catch (Throwable $e) { $ok158 = false; $why158 .= ' err=' . $e->getMessage(); }
check('📄 الكشوف مطابقة للبطاقة السنوية: الأساس والدرجة بالليرة فقط، «بعد التدرّج» ÷1500 لأصحاب النسبة فقط (كود + رؤوس + تجربة على متعاقد بالدولار وصاحب نسبة)', $ok158, $why158);

/**
 * 159) 💱 «عم شوف سعر دولار 89,501 أو 89,509 ليش نحنا حاطينو 89,500» (2026-09-21): الصفوف المنقولة بسعر مشتقّ بكسور تُعاد إلى سعر الشهر
 *      الرسمي (±100) مع مرايا الدولار — healDerivedExchangeRates مستمرّ بالترويسة. تشغيل فعلي: صفّ يُحرَّف إلى 89,501.09 يرجع 89,500، وسعر صحيح 90,000 لا يُمسّ.
 */
$ok159 = function_exists('healDerivedExchangeRates') && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healDerivedExchangeRates();') !== false;
$why159 = 'code=' . ($ok159 ? 'ok' : 'bad');
try {
    $row159 = $db->query("SELECT employee_id, year, month, exchange_rate, net_salary_lbp FROM monthly_salaries WHERE school_year = '2026-2027' AND month = 10 AND net_salary_lbp > 0 AND exchange_rate = ROUND(exchange_rate) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row159) throw new RuntimeException('لا صفّ');
    $w159 = "employee_id = " . (int)$row159['employee_id'] . " AND year = " . (int)$row159['year'] . " AND month = " . (int)$row159['month'];
    $db->exec("UPDATE monthly_salaries SET exchange_rate = 89501.09 WHERE $w159");
    healDerivedExchangeRates(true);
    $after = $db->query("SELECT exchange_rate, net_salary_usd FROM monthly_salaries WHERE $w159")->fetch(PDO::FETCH_ASSOC);
    $off159 = (float)getExchangeRate((int)$row159['month'], (int)$row159['year']);
    $t1 = abs((float)$after['exchange_rate'] - $off159) < 0.01 && abs((float)$after['net_salary_usd'] - round((int)$row159['net_salary_lbp'] / $off159, 2)) < 0.011;
    $db->exec("UPDATE monthly_salaries SET exchange_rate = 90000 WHERE $w159");
    healDerivedExchangeRates(true);
    $t2 = (float)$db->query("SELECT exchange_rate FROM monthly_salaries WHERE $w159")->fetchColumn() == 90000.0;
    $db->prepare("UPDATE monthly_salaries SET exchange_rate = ?, net_salary_usd = ROUND(net_salary_lbp / ?, 2) WHERE $w159")->execute([(float)$row159['exchange_rate'], (float)$row159['exchange_rate']]);
    $left159 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE exchange_rate > 0 AND exchange_rate <> ROUND(exchange_rate) AND school_year >= '2025-2026'")->fetchColumn();
    $ok159 = $ok159 && $t1 && $t2 && $left159 === 0;
    $why159 .= " tamper=" . ($t1 ? 'healed' : 'NOT') . " whole=" . ($t2 ? 'kept' : 'TOUCHED') . " leftover=$left159";
} catch (Throwable $e) { $ok159 = false; $why159 .= ' err=' . $e->getMessage(); }
check('💱 سعر الصرف المشتقّ بكسور يرجع سعر الشهر الرسمي (89,500) مع مرايا الدولار — مستمرّ بالترويسة + لا صفّ متبقٍّ + الأسعار الصحيحة لا تُمسّ', $ok159, $why159);

/**
 * 160) 🔍📞 «بصفحة الموظفين والأساتذة بس بدي فتّش على أستاذ لازم دغري بس حطّ أوّل حرف تبيّن أسماء اللي بأوّل هيدا الحرف وأنا بختار
 *      أو بكمّل كتابة الاسم أو تفتيش برقم التلفون» (2026-09-21): خانة البحث بلوحة اقتراحات فورية (ajax_search) من أوّل حرف + الهاتف
 *      بالأرقام فقط بالاقتراحات وبفلتر اللائحة. تجربة: أستاذ بهاتف يُعثر عليه بآخر 6 أرقام بلا شرطة.
 */
$ax160 = (string)file_get_contents($PROJ . '/ajax_search.php'); $ep160 = (string)file_get_contents($PROJ . '/pages/employees.php');
$ok160 = strpos($ax160, "REPLACE(REPLACE(REPLACE(COALESCE(phone1,''),'-',''),' ',''),'/','') LIKE ?") !== false && strpos($ax160, "'phone'  =>") !== false
      && strpos($ep160, 'id="empSearchPanel"') !== false && strpos($ep160, "ajax_search.php?q=") !== false && strpos($ep160, "REPLACE(REPLACE(REPLACE(COALESCE(phone1,''),'-',''),' ',''),'/','') LIKE ?") !== false
      && strpos($ep160, "action=edit&id=' + r.id") !== false;
$why160 = 'code=' . ($ok160 ? 'ok' : 'bad');
try {
    $ph = $db->query("SELECT id, phone1 FROM employees WHERE is_deleted = 0 AND phone1 REGEXP '^[0-9]{2}-[0-9]{6}$' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ph) throw new RuntimeException('لا هاتف');
    $d = substr(preg_replace('/\D/', '', $ph['phone1']), -6);
    $st = $db->prepare("SELECT id FROM employees WHERE is_deleted = 0 AND (REPLACE(REPLACE(REPLACE(COALESCE(phone1,''),'-',''),' ',''),'/','') LIKE ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone2,''),'-',''),' ',''),'/','') LIKE ?)");
    $st->execute(['%' . $d . '%', '%' . $d . '%']); $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $ok160 = $ok160 && in_array((int)$ph['id'], $ids, true);
    $why160 .= " phone={$ph['phone1']} tail=$d found=" . (in_array((int)$ph['id'], $ids, true) ? 'yes' : 'NO');
} catch (Throwable $e) { $ok160 = false; $why160 .= ' err=' . $e->getMessage(); }
check('🔍📞 بحث الموظفين: اقتراحات فورية من أوّل حرف (اختيار أو تكملة) + البحث برقم الهاتف بالأرقام فقط (اقتراحات + فلتر اللائحة)', $ok160, $why160);

/**
 * 161) 🆕 «الأساتذة الجداد اللي بيكونوا باعتين عاللينك وكبست موافق وما كمّلت الملف: إذا بدّي أطبع تقرير متعاقد أو موظف
 *      وأطلب الكل لازم يبيّنوا أسماؤهم حتى لو ما كمّلتهن الملف، وهيك بعرف أي ملف ناقص» (2026-09-23):
 *      yearEmploymentFilter = راتب فعلي بالسنة **أو** دخول ضمن السنة (hire_date بين تشرين الأول وأيلول) + المصدر الواحد
 *      noSalaryYearEmployeeIds/employeeFileGaps/incompleteFileBadge/incompleteEmployeesRows بكل اللوائح والكشوف والتصدير واللوحة.
 *      تجربة حيّة: متعاقد جديد __REG161 دخول 2026-10-01 بلا أي راتب ⇒ ظاهر بفلتر 2026-2027، «ملف ناقص»، غير ظاهر بـ2025-2026.
 */
$ok161 = true; $why161 = '';
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, salary_input_mode, base_salary_usd, contract_salary_lbp, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted)
    VALUES (2, '__REG161', 'enseignant_contractuel', 'فحص', 'جديد161', 'Reg', 'Nouveau161', '2026-10-01', 'actif', 'percent_of_lbp', 0, 0, 12, 5, 4, 1, 1, 1, 1, 0, 0)");
$rid161 = (int)$db->lastInsertId();
try {
    [$f161, $p161] = yearEmploymentFilter('2026-2027', 'e.');
    $q = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.id = $rid161" . $f161); $q->execute($p161); $in27 = (int)$q->fetchColumn();
    [$f161b, $p161b] = yearEmploymentFilter('2025-2026', 'e.');
    $q = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.id = $rid161" . $f161b); $q->execute($p161b); $in26 = (int)$q->fetchColumn();
    // المصدر الواحد (بلا كاش قديم: المفتاح يتضمّن شرطاً فريداً)
    $ids161 = noSalaryYearEmployeeIds($db, '2026-2027', '', " AND e.id = $rid161");
    $e161 = $db->query("SELECT * FROM employees WHERE id = $rid161")->fetch(PDO::FETCH_ASSOC);
    $gaps161 = employeeFileGaps($e161, $db, '2026-2027');
    $rows161 = incompleteEmployeesRows($db, '2026-2027', '', " AND e.id = $rid161");
    $badge161 = incompleteFileBadge($e161, $db, '2026-2027', false);
    $both161 = empBadges($e161, $db, '2026-2027', false); $txt161 = empBadgesText($e161, $db, '2026-2027'); // 🆕 «جديد» + «ملف ناقص» معاً (2026-09-23)
    $msRow161 = ['employee_id' => $rid161]; $bothMs161 = empBadges($msRow161, $db, '2026-2027', false); // صفّ راتب شهري (بلا بيانات الموظف) يكمّل من الكاش
    // موظف له راتب فعلي بالسنة ⇒ لا شارة (الملفات المكتملة لا تُوسَم)
    $paid161 = $db->query("SELECT e.* FROM employees e WHERE e.is_deleted = 0 AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = '2025-2026' AND base_plus_echelon_lbp > 0) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $paidGaps = $paid161 ? employeeFileGaps($paid161, $db, '2025-2026') : [];
    // الكود: الصفوف الملحقة بالكشف الشهري (شاشة + تصدير) + عمود «ملف ناقص» بلائحة الموظفين + الشارة بكشف الرواتب ولائحة الموظفين + اللوحة + فحص الصحة
    $rp = (string)file_get_contents($PROJ . '/pages/reports.php'); $rx = (string)file_get_contents($PROJ . '/pages/reports_export.php');
    $mp = (string)file_get_contents($PROJ . '/pages/monthly_payroll.php'); $ep = (string)file_get_contents($PROJ . '/pages/employees.php');
    $ix = (string)file_get_contents($PROJ . '/index.php'); $hc = (string)file_get_contents($PROJ . '/pages/health_check.php');
    $code161 = strpos($rp, "incompleteEmployeesRows(\$db, \$periodSchoolYear, \$schoolSqlEmp, \$empTypeSql)") !== false && strpos($rp, "'gaps'    => ['ملف ناقص / Dossier incomplet'") !== false
            && strpos($rp, "empBadges(\$r, \$db, \$bonusSy)") !== false
            && strpos($rx, "incompleteEmployeesRows(\$db, \$periodSchoolYear, \$schoolSqlEmp, \$empTypeSql)") !== false && strpos($rx, "'gaps' => ['ملف ناقص'") !== false
            && strpos($mp, "empBadges(\$r, \$db, \$msSchoolYear)") !== false && strpos($ep, "empBadges(\$emp, \$db,") !== false
            && strpos($ix, "incompleteEmployeesRows(\$db, \$homeIncSy") !== false && strpos($hc, "incompleteEmployeesRows(\$db, \$hcYear") !== false;
    $ok161 = $in27 === 1 && $in26 === 0 && isset($ids161[$rid161]) && $gaps161 && $gaps161[0] === 'الراتب غير محسوب (الإعداد المالي)' && in_array('رقم الضمان', $gaps161, true)
          && count($rows161) === 1 && (int)$rows161[0]['id'] === $rid161 && strpos($badge161, 'ملف ناقص') !== false && $paidGaps === [] && $code161
          && strpos($both161, 'جديد') !== false && strpos($both161, 'ملف ناقص') !== false && $txt161 === ' (جديد) (ملف ناقص)' && strpos($bothMs161, 'جديد') !== false
          && substr_count($rp, 'empBadges($r, $db, $periodSchoolYear)') === 4 && strpos($rx, 'empBadgesText($r, $db, $periodSchoolYear)') !== false && strpos($mp, 'empBadges($r, $db, $msSchoolYear)') !== false;
    $why161 = "in2026-2027=$in27 in2025-2026=$in26 noSal=" . (isset($ids161[$rid161]) ? 'yes' : 'NO') . ' gaps=' . implode('|', $gaps161) . ' rows=' . count($rows161)
            . ' badge=' . (strpos($badge161, 'ملف ناقص') !== false ? 'ok' : 'NO') . ' paidGaps=' . count($paidGaps) . ' code=' . ($code161 ? 'ok' : 'bad');
} catch (Throwable $e) { $ok161 = false; $why161 .= ' err=' . $e->getMessage(); }
finally { $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid161"); $db->exec("DELETE FROM employees WHERE id = $rid161"); }
check('🆕 الأستاذ الجديد الموافَق عليه بلا راتب يظهر بتقارير سنة دخوله بشارة «ملف ناقص» (فلتر السنة = راتب أو دخول ضمنها) + الكشف الشهري/التصدير/لائحة الموظفين/كشف الرواتب/اللوحة/فحص الصحة + الملف المكتمل بلا شارة', $ok161, $why161);

/**
 * 162) 🆕 «بدي تقرير كمان اسمه الأساتذة الجداد وفيه ملاحظة كمان مين مكمّل ملفه المالي ومين لا — وأكيد يكون فيه كامل المعلومات عن ملفه» (2026-09-23):
 *      ?report=new_teachers = كل من دخل ضمن السنة (hire_date) + كل أعمدة الملف (newTeachersReportCols ≥ 28 عموداً) + ملاحظة ✅/❌ الملف المالي
 *      + المصدر (رابط/يدوي) + تصدير بنفس الأعمدة. تجربة حيّة: __REG162 دخول 2026-10-01 بلا راتب ⇒ بالتقرير 2026-2027 «غير مكتمل»
 *      وليس بـ2025-2026؛ موظف براتب 2025-2026 ⇒ «مكتمل» مع أساسه.
 */
$ok162 = true; $why162 = '';
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, salary_input_mode, base_salary_usd, contract_salary_lbp, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted, phone1, diploma)
    VALUES (2, '__REG162', 'enseignant_contractuel', 'فحص', 'جديد162', 'Reg', 'Nouveau162', '2026-10-01', 'actif', 'percent_of_lbp', 0, 0, 12, 5, 4, 1, 1, 1, 1, 0, 0, '03-162162', 'licence')");
$rid162 = (int)$db->lastInsertId();
try {
    $r27 = newTeachersReportRows($db, '2026-2027', '', " AND e.id = $rid162");
    $r26 = newTeachersReportRows($db, '2025-2026', '', " AND e.id = $rid162");
    $paid = $db->query("SELECT e.id FROM employees e WHERE e.is_deleted = 0 AND e.hire_date BETWEEN '2025-10-01' AND '2026-09-30' AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = '2025-2026' AND base_plus_echelon_lbp > 0) LIMIT 1")->fetchColumn();
    $rp = $paid ? newTeachersReportRows($db, '2025-2026', '', " AND e.id = " . (int)$paid) : [];
    $cols = newTeachersReportCols();
    $txt = $r27 ? array_map(fn($c) => (string)($c[1])($r27[0]), $cols) : [];
    $html = renderPage('pages/reports.php', ['report' => 'new_teachers', 'school_year' => '2026-2027'], ['extra','aide','transport']);
    $src = (string)file_get_contents($PROJ . '/pages/reports.php'); $srx = (string)file_get_contents($PROJ . '/pages/reports_export.php');
    $ok162 = count($r27) === 1 && $r27[0]['fin_ok'] === false && strpos($r27[0]['fin_note'], 'غير مكتمل') !== false && $r27[0]['via_link'] === false
          && count($r26) === 0 && (!$paid || (count($rp) === 1 && $rp[0]['fin_ok'] === true && strpos($rp[0]['fin_note'], 'مكتمل') !== false && $rp[0]['last_salary']))
          && count($cols) >= 28 && ($txt['phone'] ?? '') === '03-162162' && ($txt['nssf'] ?? '') === '⚠️ ناقص' && ($txt['setup'] ?? '') === '⚠️ لا أساس بالملف' && ($txt['hire'] ?? '') === formatDate('2026-10-01')
          && strpos($html, 'Nouveau162') !== false && strpos($html, 'الملف المالي غير مكتمل') !== false && strpos($html, 'الأساتذة الجدد') !== false && strpos($html, 'رقم الضمان / N° CNSS') !== false
          && strpos($src, "'?report=new_teachers'") !== false && strpos($src, "'new_teachers']") !== false && strpos($srx, "\$report === 'new_teachers'") !== false && strpos($srx, 'newTeachersReportCols()') !== false;
    $why162 = 'r27=' . count($r27) . ' r26=' . count($r26) . ' paid=' . (int)$paid . ' paidOk=' . (($rp[0]['fin_ok'] ?? null) ? 'yes' : 'no') . ' cols=' . count($cols) . ' phone=' . ($txt['phone'] ?? '-') . ' nssf=' . ($txt['nssf'] ?? '-') . ' html=' . (strpos($html, 'Nouveau162') !== false ? 'ok' : 'NO') . ' len=' . strlen($html);
} catch (Throwable $e) { $ok162 = false; $why162 .= ' err=' . $e->getMessage(); }
finally { $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid162"); $db->exec("DELETE FROM employees WHERE id = $rid162"); }
check('🆕 تقرير «الأساتذة الجدد»: من دخل ضمن السنة + كامل معلومات الملف (≥28 عموداً) + ملاحظة الملف المالي (✅ راتب محسوب / ❌ لا راتب + النواقص) + المصدر + الشاشة والتصدير والقائمة', $ok162, $why162);

/**
 * 163) 🎓✍️🏷️ تريزيا مارون (2026-09-23): «حطّيت موافق لدخول بالملاك ما أخذها، رجعت فتت على ملفها وحطّيتلها ملاك — هيدي بدّك تنتبهلها»
 *      + «طلّعت البطاقة السنوية ما ظهر بالعناوين قدّيش قيمة الدولار»:
 *      (أ) التحويل اليدوي من الملف = قانون الملاك كاملاً فوراً (cadreManualConversionComplete من مسار الحفظ) + قرار approved بسجلّ الترسيم.
 *      (ب) فشل «موافق» لا يصمت: اسم + سبب + سجلّ تدقيق cadre_approve_fail (حتى لو لم يعد مرشَّحاً) + الزرّ المطفأ يشرح.
 *      (ج) سطر سعر الصرف بعنوان البطاقة يظهر بكل أوضاع العملة (كان يختفي بوضع «ليرة فقط») + بتصدير البطاقة.
 *      تجربة حيّة: متعاقد __REG163 بسنتَي رواتب بمدرسة 2 يُحوَّل بيده إلى ملاك ⇒ السلسلة + نسبة/نقل الملاك + cadre_from_sy + قرار approved.
 */
$ok163 = true; $why163 = '';
$cd163 = (string)file_get_contents($PROJ . '/includes/cadre_due.php'); $ep163 = (string)file_get_contents($PROJ . '/pages/employees.php');
$fn163 = (string)file_get_contents($PROJ . '/includes/functions.php'); $ax163 = (string)file_get_contents($PROJ . '/pages/annual_slip_export.php');
$code163 = strpos($cd163, 'function cadreManualConversionComplete(') !== false && strpos($cd163, "logAudit('cadre_approve_fail'") !== false && strpos($cd163, 'راجع الملاحظة الصفراء بسطره') !== false
        && strpos($ep163, 'cadreManualConversionComplete($db, (int)$id') !== false && strpos($fn163, "if (displayCurrency() === 'lbp') return '';\n    \$r = \$rate") === false
        && strpos($ax163, '$slipRateTxt = rateTitleText(') !== false;
$prevCur163 = $_SESSION['display_currency'] ?? null; $_SESSION['display_currency'] = 'lbp';
$rateLbp163 = rateTitleText(null, null, true, 89500.0, false);
if ($prevCur163 === null) unset($_SESSION['display_currency']); else $_SESSION['display_currency'] = $prevCur163;
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, titularization_date, status, salary_input_mode, base_salary_usd, contract_salary_lbp, starting_grade, current_grade, diploma, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted)
    VALUES (2, '__REG163', 'enseignant_contractuel', 'فحص', 'تريزيا163', 'Reg', 'Manual163', '2024-10-01', '2026-10-01', 'actif', 'direct_lbp', 0, 1325000, 1, 1, 'licence', 10, 5, 4, 1, 1, 1, 1, 0, 0)");
$rid163 = (int)$db->lastInsertId();
try {
    $ins = $db->prepare("INSERT INTO monthly_salaries (employee_id, school_id, month, year, school_year, base_salary_lbp, base_plus_echelon_lbp, net_salary_lbp, total_due_lbp, exchange_rate, is_calculated) VALUES (?,2,?,?,?,1325000,1325000,1200000,1200000,89500,1)");
    foreach (['2024-2025' => 2024, '2025-2026' => 2025] as $sy0 => $y0) { $ins->execute([$rid163, 10, $y0, $sy0]); $ins->execute([$rid163, 11, $y0, $sy0]); }
    // التحويل اليدوي كما يفعله مسار الحفظ: النوع ملاك ثم الاستكمال
    $db->exec("UPDATE employees SET employee_type = 'enseignant_titulaire' WHERE id = $rid163");
    $res163 = cadreManualConversionComplete($db, $rid163, 'reg');
    $e163 = $db->query("SELECT * FROM employees WHERE id = $rid163")->fetch(PDO::FETCH_ASSOC);
    $dec163 = $db->query("SELECT decision, result FROM compliance_decisions WHERE item_key = 'cadre_due|$rid163|2026-2027'")->fetch(PDO::FETCH_ASSOC);
    $pct163 = schoolCadrePercent($db, 2, '2026-2027');
    $bon163 = $db->query("SELECT value_type, amount FROM employee_bonuses WHERE employee_id = $rid163 AND school_year = '2026-2027' AND bonus_type = 'prime_fixe' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $n163 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $rid163 AND school_year = '2026-2027' AND base_plus_echelon_lbp > 0")->fetchColumn();
    $oldKept = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $rid163 AND school_year < '2026-2027' AND base_plus_echelon_lbp = 1325000")->fetchColumn();
    $au163 = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'cadre_manual' AND record_id = $rid163")->fetchColumn();
    // 🔁 الشفاء الدوري لا يتخطّى بصمت: أستاذ ملاك جديد بسنة البرنامج بلا شهادة (الدرجات تفشل) يأخذ رغم ذلك النسبة/السلسلة/المحسومات ولا يُعلَّم منجزاً إن تعذّر شيء
    $cdSrc163 = (string)file_get_contents($PROJ . '/includes/cadre_due.php');
    $healOk163 = strpos($cdSrc163, "heal_cadre_new_20260923c") !== false && strpos($cdSrc163, "logAudit('cadre_heal_fail'") !== false && strpos($cdSrc163, '} } catch (Throwable $eg) {') !== false
              && strpos($cdSrc163, "SET salary_input_mode = 'percent_of_lbp', base_salary_lbp_percent = IF(base_salary_lbp_percent > 0, base_salary_lbp_percent, 100), contract_salary_lbp = 0, base_salary_usd = 0 WHERE id = ?\")->execute([\$id]);") !== false
              && strpos($ep163, "cadreManualConversionComplete(\$db, (int)\$id, (string)(\$_SESSION['username'] ?? ''), 'new')") !== false
              && strpos($ep163, "logAudit('cadre_pct_guard'") !== false && strpos($ep163, 'saveEmployeeBonuses($db, $id); // حفظ الأجر الإضافي') < strpos($ep163, "logAudit('cadre_pct_guard'"); // 🛡️ الحارس بعد إعادة كتابة البنود
    $ok163 = $code163 && $healOk163 && strpos($rateLbp163, '89,500') !== false && $res163 && $e163['cadre_from_sy'] === '2026-2027' && $e163['salary_input_mode'] === 'percent_of_lbp' && (float)$e163['contract_salary_lbp'] == 0
          && (int)$e163['eoc_subject'] === 1 && $dec163 && $dec163['decision'] === 'approved' && strpos($dec163['result'], 'حُوِّل بيده') !== false
          && (!$pct163 || (count($bon163) === 1 && $bon163[0]['value_type'] === 'percent' && abs((float)$bon163[0]['amount'] - (float)$pct163['pct']) < 0.01))
          && $n163 >= 10 && $oldKept === 4 && $au163 === 1;
    $why163 = 'code=' . ($code163 ? 'ok' : 'bad') . ' heal=' . ($healOk163 ? 'ok' : 'bad') . ' rateLbp=' . (strpos($rateLbp163, '89,500') !== false ? 'ok' : 'NO') . ' cfs=' . ($e163['cadre_from_sy'] ?? '-') . ' mode=' . ($e163['salary_input_mode'] ?? '-') . ' eoc=' . (int)($e163['eoc_subject'] ?? -1)
            . ' dec=' . ($dec163['decision'] ?? '-') . ' pct=' . json_encode($bon163) . ' months27=' . $n163 . ' oldKept=' . $oldKept . ' audit=' . $au163;
} catch (Throwable $e) { $ok163 = false; $why163 .= ' err=' . $e->getMessage(); }
finally {
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid163"); $db->exec("DELETE FROM employee_bonuses WHERE employee_id = $rid163");
    $db->exec("DELETE FROM employee_grade_history WHERE employee_id = $rid163"); $db->exec("DELETE FROM compliance_decisions WHERE employee_id = $rid163");
    $db->exec("DELETE FROM audit_log WHERE record_id = $rid163 AND table_name = 'employees'"); $db->exec("DELETE FROM employees WHERE id = $rid163");
}
check('🎓✍️🏷️ تريزيا مارون: التحويل اليدوي إلى ملاك = قانون الملاك كاملاً فوراً (السلسلة + المحسومات + النسبة + النقل + صمام السنين + قرار approved + تدقيق) + فشل «موافق» يُقال ويُسجَّل + سطر سعر الصرف بالبطاقة بكل أوضاع العملة وبالتصدير', $ok163, $why163);

/**
 * 164) 🔒📆 (2026-09-23) ايف عيد #1815: حفظ ملفه أعاد حساب 9 أشهر مدفوعة من 2025-2026 (69,060,000 ⇒ 970,000) ⇒ الأشهر المدفوعة لسنة سابقة
 *      لا تُعاد بإعادة حساب ضمنية (recalcEmployeeYear/overlay) إلا بعلم الفعل الصريح msa_recalc_paid_ok (صفحات احسب السنة/المخالفات/الإكسل/العلاوات/الإعدادات).
 *      + الأساتذة الجدد من الرابط: سنة الدخول = سنة البرنامج الحالية (الفورم + الموافقة + 12 شهراً) + شفاء الثلاثة المدفوعين إلى 2027-2028.
 *      تجربة حيّة: متعاقد __REG164 بعقد 2,000,000 وشهر تشرين 2025 مدفوع بصافي 1 ⇒ إعادة الحساب الضمنية تتركه 1، وبالعلم تصحّحه.
 */
$ok164 = true; $why164 = '';
$pc164 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php'); $ic164 = (string)file_get_contents($PROJ . '/pages/info_collect.php');
$tf164 = (string)file_get_contents($PROJ . '/pages/teacher_form.php'); $hd164 = (string)file_get_contents($PROJ . '/includes/header.php');
$code164 = strpos($pc164, 'function protectPaidMonths(') !== false && strpos($pc164, "logAudit('recalc_skipped_paid'") !== false && strpos($pc164, '$protectOv && (int)($r[\'is_paid\'] ?? 0) === 1) continue;') !== false
        && strpos($ic164, "'payment_months_per_year' => 12,") !== false && strpos($ic164, "strcmp(\$data['entry_school_year'], \$curSyIC) >= 0") !== false
        && strpos($tf164, 'for ($yy = $curStart; $yy <= $curStart + 2; $yy++)') !== false && strpos($hd164, 'healLinkEntryYear20260923();') !== false
        && strpos($hd164, 'healJounSchoolMove20260923();') !== false && function_exists('healJounSchoolMove20260923')
        && strpos($ic164, "name=\"target_school_id\"") !== false && strpos($ic164, "\$pickSid = (int)(\$_POST['target_school_id'] ?? 0);") !== false; // 🏫 المدرسة تُختار عند الموافقة (2026-09-23)
foreach (['annual_slip', 'monthly_payroll', 'compliance', 'excel_salaries', 'bulk_allowances', 'settings'] as $pg164) $code164 = $code164 && strpos((string)file_get_contents($PROJ . "/pages/$pg164.php"), "\$GLOBALS['msa_recalc_paid_ok'] = true;") !== false;
$prevFlag164 = $GLOBALS['msa_recalc_paid_ok'] ?? null; unset($GLOBALS['msa_recalc_paid_ok']);
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, salary_input_mode, base_salary_usd, contract_salary_lbp, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted)
    VALUES (2, '__REG164', 'enseignant_contractuel', 'فحص', 'مدفوع164', 'Reg', 'Paid164', '2024-10-01', 'actif', 'direct_lbp', 0, 2000000, 12, 5, 4, 1, 1, 1, 1, 0, 0)");
$rid164 = (int)$db->lastInsertId();
try {
    $db->exec("INSERT INTO monthly_salaries (employee_id, school_id, month, year, school_year, base_salary_lbp, base_plus_echelon_lbp, net_salary_lbp, total_due_lbp, exchange_rate, is_calculated, is_paid) VALUES ($rid164, 2, 10, 2025, '2025-2026', 1, 1, 1, 1, 89500, 1, 1)");
    $db->exec("INSERT INTO monthly_salaries (employee_id, school_id, month, year, school_year, base_salary_lbp, base_plus_echelon_lbp, net_salary_lbp, total_due_lbp, exchange_rate, is_calculated, is_paid) VALUES ($rid164, 2, 11, 2025, '2025-2026', 1, 1, 1, 1, 89500, 1, 0)");
    recalcEmployeeYear($rid164, '2025-2026');
    $octA = (int)$db->query("SELECT net_salary_lbp FROM monthly_salaries WHERE employee_id = $rid164 AND month = 10 AND year = 2025")->fetchColumn();
    $novA = (int)$db->query("SELECT net_salary_lbp FROM monthly_salaries WHERE employee_id = $rid164 AND month = 11 AND year = 2025")->fetchColumn();
    $sk = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'recalc_skipped_paid' AND record_id = $rid164")->fetchColumn();
    $GLOBALS['msa_recalc_paid_ok'] = true;
    recalcEmployeeYear($rid164, '2025-2026');
    $octB = (int)$db->query("SELECT net_salary_lbp FROM monthly_salaries WHERE employee_id = $rid164 AND month = 10 AND year = 2025")->fetchColumn();
    $ok164 = $code164 && $octA === 1 && $novA > 1000 && $sk === 1 && $octB > 1000 && protectPaidMonths('2025-2026') === false;
    unset($GLOBALS['msa_recalc_paid_ok']);
    $ok164 = $ok164 && protectPaidMonths('2025-2026') === true && protectPaidMonths(currentSchoolYear()) === false;
    $why164 = 'code=' . ($code164 ? 'ok' : 'bad') . " octProtected=$octA novRecalc=$novA skippedLog=$sk octForced=$octB";
} catch (Throwable $e) { $ok164 = false; $why164 .= ' err=' . $e->getMessage(); }
finally {
    if ($prevFlag164 !== null) $GLOBALS['msa_recalc_paid_ok'] = $prevFlag164; else unset($GLOBALS['msa_recalc_paid_ok']);
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid164"); $db->exec("DELETE FROM audit_log WHERE record_id = $rid164 AND action = 'recalc_skipped_paid'"); $db->exec("DELETE FROM employees WHERE id = $rid164");
}
check('🔒📆 الأشهر المدفوعة لسنة سابقة لا تُعاد بإعادة حساب ضمنية (حفظ ملف/درجات/شفاء) وتُعاد بالفعل الصريح فقط + تسجيل التخطّي + الجديد من الرابط على سنة البرنامج الحالية بـ12 شهراً + شفاء المدفوعين إلى 2027-2028', $ok164, $why164);

/**
 * 165) 🧹 أحمد بصبوص #1000015 (2026-09-23): «حطّيت أساس 400 $ وحفظت، رجعت صفّرته وحطّيت 400 $ بالإضافي — البطاقة بقيت على الأساس القديم»:
 *      صمام المنقولين (أساس 0 بالملف + أشهر بأساس > 0 ⇒ overlay فقط) كان يمنع تصفير الأساس. صار المحرّك سيّده لمن دخل بسنة البرنامج
 *      (لا أشهر منقولة) ولمن صفّر المستخدم أساسه بيده (base_cleared_by_user، عمود ذاتي يُرفع بمسار الحفظ) + شفاء healNewHireZeroBase.
 *      تجربة حيّة: متعاقد __REG165 دخول 2026-10-01 بأساس 400 $ ⇒ أشهر بأساس > 0؛ ثم أساس 0 + إضافي 400 $ ⇒ إعادة الحساب تصفّر الأساس والإضافي = 400 $.
 */
$ok165 = true; $why165 = '';
$pc165 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php'); $ep165 = (string)file_get_contents($PROJ . '/pages/employees.php'); $hd165 = (string)file_get_contents($PROJ . '/includes/header.php');
$code165 = strpos($pc165, "if ((int)(\$emp['base_cleared_by_user'] ?? 0) === 1) return true;") !== false && strpos($ep165, "logAudit('base_cleared_by_user'") !== false
        && strpos($hd165, 'healNewHireZeroBase20260923();') !== false && function_exists('ensureBaseClearedColumn') && function_exists('healNewHireZeroBase20260923');
ensureBaseClearedColumn();
$db->exec("INSERT INTO employees (school_id, employee_code, employee_type, first_name_ar, last_name_ar, first_name_fr, last_name_fr, hire_date, status, salary_input_mode, base_salary_usd, contract_salary_lbp, payment_months_per_year, days_per_week, transport_weeks, tax_subject, tax_includes_extra, cnss_subject, cnss_includes_extra, eoc_subject, is_deleted)
    VALUES (2, '__REG165', 'enseignant_contractuel', 'فحص', 'بصبوص165', 'Reg', 'Zero165', '2026-10-01', 'actif', 'direct_usd', 400, 0, 12, 5, 4, 1, 1, 1, 1, 0, 0)");
$rid165 = (int)$db->lastInsertId();
try {
    $n1 = (int)recalcEmployeeYear($rid165, '2026-2027');
    $b1 = (float)$db->query("SELECT base_plus_echelon_lbp FROM monthly_salaries WHERE employee_id = $rid165 AND month = 10 AND year = 2026")->fetchColumn();
    // كما يفعل الملف المالي: الأساس 0 + إضافي 400 $
    $db->exec("UPDATE employees SET base_salary_usd = 0 WHERE id = $rid165");
    $db->exec("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES ($rid165, 'prime_fixe', 1, '2026-2027', 400, 'amount', 'USD', NULL, NULL, 1)");
    $e165 = $db->query("SELECT * FROM employees WHERE id = $rid165")->fetch(PDO::FETCH_ASSOC);
    $allowed165 = salaryEngineAllowed($e165, $db); // دخل بسنة البرنامج ⇒ المحرّك سيّده رغم أساس 0 وأشهر بأساس > 0
    $n2 = (int)recalcEmployeeYear($rid165, '2026-2027');
    $r2 = $db->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp p, exchange_rate x FROM monthly_salaries WHERE employee_id = $rid165 AND month = 10 AND year = 2026")->fetch(PDO::FETCH_ASSOC);
    $expP = usdToLbp(400, (float)$r2['x']);
    // وقديم الدخول: بلا علم ⇒ ما زال «منقولاً» محميّاً؛ بالعلم ⇒ المحرّك سيّده
    $db->exec("UPDATE monthly_salaries SET base_salary_lbp = 5000000, base_plus_echelon_lbp = 5000000 WHERE employee_id = $rid165 AND month = 10 AND year = 2026"); // أساس «منقول» مخزّن
    $db->exec("UPDATE employees SET hire_date = '2020-10-01', base_cleared_by_user = 0 WHERE id = $rid165");
    $eOld = $db->query("SELECT * FROM employees WHERE id = $rid165")->fetch(PDO::FETCH_ASSOC); $oldProtected = !salaryEngineAllowed($eOld, $db);
    $db->exec("UPDATE employees SET base_cleared_by_user = 1 WHERE id = $rid165");
    $eFlag = $db->query("SELECT * FROM employees WHERE id = $rid165")->fetch(PDO::FETCH_ASSOC); $flagAllowed = salaryEngineAllowed($eFlag, $db);
    $ok165 = $code165 && $n1 === 12 && $b1 > 0 && $allowed165 && $n2 === 12 && (float)$r2['b'] === 0.0 && abs((float)$r2['p'] - $expP) < 1 && $oldProtected && $flagAllowed;
    $why165 = 'code=' . ($code165 ? 'ok' : 'bad') . " n1=$n1 b1=$b1 allowed=" . (int)$allowed165 . " n2=$n2 b2={$r2['b']} p2={$r2['p']} exp=$expP oldProtected=" . (int)$oldProtected . ' flagAllowed=' . (int)$flagAllowed;
} catch (Throwable $e) { $ok165 = false; $why165 .= ' err=' . $e->getMessage(); }
finally { $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $rid165"); $db->exec("DELETE FROM employee_bonuses WHERE employee_id = $rid165"); $db->exec("DELETE FROM employees WHERE id = $rid165"); }
check('🧹 تصفير الأساس من الملف المالي يصل إلى البطاقة وكل الكشوف: داخل سنة البرنامج ليس «منقولاً» + علم base_cleared_by_user + المنقول القديم يبقى محميّاً + شفاء أحمد بصبوص', $ok165, $why165);


/* =====================================================================
 * 166) ✍️📅💱 خيارات الإفادات الجديدة (2026-09-24): «بكل إفادة خيار حطّ أو شيل قيمة الدولار + أقدر غيّر أو أكتب اسم المدير
 *      أو الرئيسة + أغيّر التاريخ + رقم المبلغ + تاريخ الدخول من – إلى» — بشريط الخيارات بكل الإفادات الـ15 ولغاتها الثلاث:
 *      rate_show (افتراضي = الإفادات التي تطبع مبلغاً) · sig_t بكل الإفادات (الافتراضي يحفظ صيغة كل إفادة) + sig_name/sig_noname ·
 *      date = التاريخ المطبوع · amt/amt_cur يحلّ محلّ الراتب كلّه بلا تفصيل · hire_dt/end_dt للدخول والترك (سنوات الخدمة بينهما).
 */
$ok166 = true; $why166 = [];
$eid166 = (int)$db->query("SELECT ms.employee_id FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id WHERE ms.month = 6 AND ms.year = 2026 AND ms.net_salary_lbp > 0 AND e.is_deleted = 0 LIMIT 1")->fetchColumn();
$area166 = function (string $h): string { $p = strpos($h, 'id="ppExportArea"'); return $p === false ? '' : substr($h, $p); };
$c166 = function (string $k, bool $v) use (&$ok166, &$why166) { if (!$v) { $ok166 = false; $why166[] = $k; } };
if ($eid166) {
    $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'date' => '2026-01-15'], []);
    $c166('date', strpos($area166($h), 'التاريخ : 15/01/2026') !== false && strpos($h, 'type="date" name="date" value="2026-01-15"') !== false);
    $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'embassy', 'date' => '2026-01-15'], []);
    $c166('date-embassy', strpos($area166($h), '15/01/2026') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'sig_t' => 'raisa', 'sig_name' => 'الأخت فحص الموقّعة'], []));
    $c166('sig-tadris', strpos($a, 'الرئيسة — التوقيع والختم') !== false && strpos($a, 'الأخت فحص الموقّعة') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'lang_doc' => 'fr', 'sig_t' => 'raisa', 'sig_name' => 'الأخت فحص الموقّعة'], []));
    $c166('sig-fr', strpos($a, 'La Supérieure — Signature et cachet') !== false && preg_match('/<br>[A-Za-z]/', $a) === 1);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'sig_noname' => 1, 'sig_name' => 'الأخت فحص الموقّعة'], []));
    $c166('sig-noname', strpos($a, 'الأخت فحص الموقّعة') === false && preg_match('/المدير — التوقيع والختم<\/strong><\/div>/u', $a) === 1);
    $c166('sig-riaaya-default', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'riaaya'], [])), '<strong>الإدارة</strong>') !== false);
    $c166('sig-embassy-en', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'embassy', 'sig_t' => 'raisa'], [])), '<strong>The Mother Superior</strong>') !== false);
    // 🏫 «يا مدير لحالو أو رئيسة المدرسة لحالها» (p1 2026-09-24): الإفادة المدرسية تتبع خيار الإمضاء — لا «رئيسة أو مديرة» ولا «رئيس أو مدير»
    $aAf = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'afade_madrasiya', 'sig_t' => 'raisa', 'sig_name' => 'الأخت فحص الموقّعة'], []));
    $c166('sig-afade-name', strpos($aAf, 'أنا الموقّعة أدناه : <strong>الأخت فحص الموقّعة</strong>') !== false && strpos($aAf, 'رئيسة مدرسة : <strong>') !== false && strpos($aAf, '<strong>توقيع رئيسة المدرسة</strong>') !== false && strpos($aAf, 'رئيسة أو مديرة') === false);
    $aAf = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'afade_madrasiya'], []));
    $c166('sig-afade-director-default', strpos($aAf, 'أنا الموقّع أدناه : ') !== false && strpos($aAf, 'مدير مدرسة : <strong>') !== false && strpos($aAf, '<strong>توقيع مدير المدرسة</strong>') !== false && strpos($aAf, 'رئيس أو مدير') === false);
    $aAf = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'afade_madrasiya', 'lang_doc' => 'fr', 'sig_t' => 'raisa'], []));
    $c166('sig-afade-fr', strpos($aAf, 'Je soussignée : ') !== false && preg_match('/Supérieure de l(&#039;|\x27)école : <strong>/u', $aAf) === 1 && strpos($aAf, 'Signature de la Supérieure') !== false); // e() تحوّل ' إلى &#039;
    $aAf = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'afade_madrasiya', 'lang_doc' => 'en'], []));
    $c166('sig-afade-en', strpos($aAf, 'Director of : <strong>') !== false && strpos($aAf, 'Signature of the Director') !== false);
    // «كل الإفادات لازم» (2026-09-24): لا «رئيسة المدرسة» ثابتة ولا «Directrice/Principal» — الصفة المختارة بكل الإفادات (إنهاء الخدمات/الإنذاران/الاستقالة/العقد)
    $at166 = (string)file_get_contents($PROJ . '/pages/attestations.php');
    $c166('no-fixed-head-in-code', strpos($at166, '<strong>رئيسة المدرسة</strong>') === false && strpos($at166, 'La Directrice de l') === false && strpos($at166, 'School Principal') === false && strpos($at166, 'رئيسة أو مديرة') === false && strpos($at166, 'رئيس أو مدير') === false && strpos($at166, 'حضرة مديرة مدرسة') === false && strpos($at166, 'من قبل رئيس المدرسة') === false);
    foreach (['anhaa_khedme' => ['رئيسة المدرسة', 'مدير المدرسة'], 'notice_mail' => ['رئيسة المدرسة', 'مدير المدرسة'], 'talab_istiqala' => ['حضرة رئيسة مدرسة :', 'حضرة مدير مدرسة :']] as $ty166 => $w166) {
        $aR = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => $ty166], []));
        $aM = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => $ty166, 'sig_t' => 'moudir'], []));
        $c166("head-choice-$ty166", strpos($aR, $w166[0]) !== false && strpos($aR, $w166[1]) === false && strpos($aM, $w166[1]) !== false && strpos($aM, 'رئيسة') === false);
    }
    $aF = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'anhaa_khedme', 'lang_doc' => 'fr', 'sig_t' => 'moudir'], []));
    $c166('head-choice-fr', preg_match('/Le Directeur de l(&#039;|\')école/u', $aF) === 1 && strpos($aF, 'Directrice') === false);
    $c166('head-choice-contract', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'aqd_taalim', 'sig_t' => 'raisa'], [])), 'من قبل رئيسة المدرسة') !== false);
    // 🧠 «الملاحظات اللي أنا مختارها لإلو تبقى» (2026-09-24): حفظ بتفاعل واحد ثم فتح إفادة أخرى بلا خيارات ⇒ نفس الخيارات (والموقّع لكل مدرسة) + ↩️ زرّ الرجوع لملف إفاداته
    $sch166 = (int)$db->query("SELECT school_id FROM employees WHERE id = $eid166")->fetchColumn();
    attestationPrefsClear($eid166, $sch166);
    try {
        renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'prefs_test' => 1, 'opts_set' => 1, 'inc_extra' => 1, 'cur' => 'usd', 'rate_show' => 1, 'sig_t' => 'raisa', 'sig_name' => 'الأخت فحص محفوظة', 'subj_ovr' => 'رياضيات', 'id_mof' => 1, 'lang_doc' => 'fr'], []);
        $hP = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'prefs_test' => 1], []);
        $aP = $area166($hP);
        $c166('prefs-recalled', strpos($hP, 'name="sig_t" value="raisa" checked') !== false && strpos($aP, 'الأخت فحص محفوظة') === false /* fr: مترجَم */ && strpos($hP, 'value="الأخت فحص محفوظة"') !== false
              && strpos($hP, 'name="rate_show" value="1" checked') !== false && strpos($hP, 'name="cur" value="usd" checked') !== false && strpos($hP, 'name="id_mof" value="1" checked') !== false
              && strpos($hP, 'value="رياضيات"') !== false && strpos($aP, 'La Supérieure de l') !== false && strpos($aP, 'Mathématiques') !== false);
        $hO = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire'], []); // بلا prefs_test ⇒ الفحوص لا تتأثر
        $c166('prefs-off-in-tests', strpos($hO, 'name="sig_t" value="moudir" checked') !== false);
        $c166('back-to-dossier', strpos($hO, "attestations.php?employee_id=$eid166&amp;dossier=1'") !== false); // e() تحوّل & إلى &amp;
    } finally { attestationPrefsClear($eid166, $sch166); }
    // «ببداية الإفادة: تفيد رئيسة / تفيد إدارة / يفيد مدير» (2026-09-24) — افتتاحية الراتب/العمل/الرعاية بحسب الإمضاء (ar/fr/en)
    foreach (['raisa' => ['تفيد رئيسة <strong>', 'La Supérieure de l', 'The Mother Superior of <strong>'], 'idara' => ['تفيد إدارة <strong>', 'L&#039;administration de l', 'The administration of <strong>'], 'moudir' => ['يفيد مدير <strong>', 'Le Directeur de l', 'The Director of <strong>']] as $st166 => $w166) {
        $oa = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'sig_t' => $st166], []));
        $of = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'lang_doc' => 'fr', 'sig_t' => $st166], []));
        $oe = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'riaaya', 'lang_doc' => 'en', 'sig_t' => $st166], []));
        $c166("opening-$st166", strpos($oa, $w166[0]) !== false && strpos($of, $w166[1]) !== false && strpos($oe, $w166[2]) !== false && ($st166 === 'idara' || (strpos($oa, 'تفيد إدارة') === false && strpos($of, 'administration de l') === false)));
    }
    $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire'], []);
    $c166('rate-default-on', strpos($area166($h), 'سعر الصرف المعتمد') !== false && strpos($h, 'name="rate_show" value="1" checked') !== false);
    $c166('rate-off', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1], [])), 'سعر الصرف المعتمد') === false);
    $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris'], []);
    $c166('rate-default-off-tadris', strpos($area166($h), 'سعر الصرف المعتمد') === false && strpos($h, 'name="rate_show" value="1" checked') === false);
    $c166('rate-on-tadris', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'opts_set' => 1, 'inc_extra' => 1, 'rate_show' => 1], [])), 'سعر الصرف المعتمد') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1, 'amt' => 12345678, 'amt_cur' => 'lbp', 'cur' => 'lbp'], []));
    $c166('amt-lbp', strpos($a, 'قدره <strong>12,345,678 ل.ل</strong>') !== false && strpos($a, 'وفق التفصيل') === false && strpos($a, 'اثنا عشر مليون') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1, 'amt' => 500, 'amt_cur' => 'usd', 'cur' => 'usd'], []));
    $c166('amt-usd', strpos($a, 'قدره <strong>$500</strong>') !== false && strpos($a, 'خمسمئة دولار') !== false);
    $c166('amt-both', preg_match('/قدره <strong>[0-9,]+ ل\.ل \(\$500\)<\/strong>/u', $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1, 'amt' => 500, 'amt_cur' => 'usd', 'cur' => 'both'], []))) === 1);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'cnss', 'opts_set' => 1, 'inc_extra' => 1, 'amt' => 12345678, 'cur' => 'lbp'], []));
    $c166('amt-cnss', strpos($a, 'أساس راتب عملاً بالقانون : <strong>12,345,678 ل.ل</strong>') !== false && strpos($a, 'المجموع : <strong>12,345,678 ل.ل</strong>') !== false && strpos($a, 'الأجر الإضافي') === false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'lang_doc' => 'en', 'opts_set' => 1, 'inc_extra' => 1, 'amt' => 500, 'amt_cur' => 'usd', 'cur' => 'usd'], []));
    $c166('amt-en', strpos($a, 'of <strong>$500</strong>') !== false && strpos($a, 'Five Hundred US Dollars') !== false);
    $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'hire_dt' => '2011-10-03'], []);
    $c166('hire-override', strpos($area166($h), 'منذ تاريخ <strong>03/10/2011</strong>') !== false && strpos($h, 'name="hire_dt" value="2011-10-03"') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'afade_madrasiya', 'hire_dt' => '2011-10-03', 'end_dt' => '2026-06-30', 'date' => '2026-07-10'], []));
    $c166('afade-from-to', strpos($a, 'بتاريخ <strong>03/10/2011</strong>') !== false && strpos($a, 'عن العمل بتاريخ <strong>30/06/2026</strong>') !== false && strpos($a, 'تحريراً في 10/07/2026') !== false);
    $c166('isqat-end', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'isqat_haq', 'end_dt' => '2026-06-30', 'date' => '2026-07-10'], [])), 'بتاريخ <strong>30/06/2026</strong>') !== false);
    $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire'], []);
    $c166('hire-default-file', preg_match('/name="hire_dt" value="\d{4}-\d{2}-\d{2}"/', $h) === 1 && strpos($h, '&hire_dt=') === false);
    // 🪪 أرقام الموظف (الضمان/المالية/صندوق التعويضات) — مشيولة افتراضياً، وسطر بلغة الإفادة عند تأشيرها
    $c166('ids-default-off', strpos($area166($h), 'id-line') === false && strpos($h, 'name="id_nssf"') !== false && strpos($h, 'name="id_mof"') !== false && strpos($h, 'name="id_eoc"') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1, 'id_nssf' => 1, 'id_mof' => 1, 'id_eoc' => 1], []));
    $c166('ids-ar', strpos($a, 'رقم الضمان الاجتماعي : <strong>') !== false && strpos($a, 'الرقم المالي (وزارة المالية) : <strong>') !== false && strpos($a, 'رقم صندوق التعويضات : <strong>') !== false);
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'lang_doc' => 'fr', 'opts_set' => 1, 'inc_extra' => 1, 'id_nssf' => 1], []));
    $c166('ids-fr', strpos($a, 'N° CNSS : <strong>') !== false && strpos($a, 'N° fiscal') === false);
    $c166('ids-embassy-en', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'embassy', 'opts_set' => 1, 'inc_extra' => 1, 'id_mof' => 1], [])), 'Tax No. (Ministry of Finance) : <strong>') !== false);
    // «الأرقام ما بيكونو بأوّل الصفحة»: سطر الأرقام بعد العنوان وقبل آخر كتلة توقيع، مرّة واحدة (راتب/ضمان/مدرسية/عقد)
    foreach (['salaire' => 'التوقيع والختم', 'cnss' => 'الخاتم والتوقيع', 'afade_madrasiya' => 'خاتم المدرسة', 'aqd_taalim' => 'الفريق الأول'] as $ty166 => $sg166) {
        $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => $ty166, 'opts_set' => 1, 'inc_extra' => 1, 'id_nssf' => 1], []));
        $pi = strpos($a, 'class="id-line"'); $ph = strpos($a, '</h2>'); $ps = strrpos($a, $sg166);
        $c166("ids-bottom-$ty166", $pi !== false && $ph !== false && $pi > $ph && $ps !== false && $pi < $ps && substr_count($a, 'class="id-line"') === 1);
    }
    // 🚪 «إذا عندو ترك لازم نحطّو + غيّره أو شيله» + 📚 «المادة أكتب أو غيّر»
    $left166 = (int)$db->query("SELECT e.id FROM employees e WHERE e.is_deleted=0 AND e.left_date_all IS NOT NULL AND e.left_date_all > '2020-01-01' AND e.employee_type<>'employe' ORDER BY (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id=e.id AND ms.net_salary_lbp>0) DESC LIMIT 1")->fetchColumn();
    if ($left166) {
        $ld166 = (string)$db->query("SELECT left_date_all FROM employees WHERE id=$left166")->fetchColumn(); $lf166 = date('d/m/Y', strtotime($ld166));
        $h = renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'salaire'], []);
        $c166('left-default-ar', strpos($area166($h), 'ولغاية تاريخ <strong>' . $lf166 . '</strong>') !== false && strpos($area166($h), 'حتى تاريخه') === false && strpos($h, 'name="end_dt" value="' . $ld166 . '"') !== false && strpos($h, '&end_dt=' . $ld166) !== false);
        // (2026-09-24 «إفادة عمل وتدريس مظبوطة لغوياً») التارك بالماضي: a enseigné/a travaillé … du X au Y + الضمير بحسب الجنس (لا Il/Elle حين معروف)
        $aF = $area166(renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'tadris', 'lang_doc' => 'fr'], []));
        $c166('left-default-fr', preg_match('/a (enseigné|travaillé) au sein de son établissement[^.]* du <strong>[0-9\/]+<\/strong> au <strong>' . preg_quote($lf166, '/') . '<\/strong>\. (Il|Elle|Il\/Elle) a fait preuve/u', $aF) === 1 && strpos($aF, 'jusqu') === false);
        $aE = $area166(renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'tadris', 'lang_doc' => 'en'], []));
        $c166('left-default-en', preg_match('/(taught|worked there as)[^.]* from <strong>[0-9\/]+<\/strong> to <strong>' . preg_quote($lf166, '/') . '<\/strong>\. (He|She|He\/She) showed good conduct/u', $aE) === 1);
        $c166('left-default-ar-past', preg_match('/(عمل|عملت|عمل\(ت\)) لديها بوظيفة/u', $area166(renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'tadris'], []))) === 1);
        $c166('left-changed', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1, 'end_dt' => '2025-06-30'], [])), 'ولغاية تاريخ <strong>30/06/2025</strong>') !== false);
        $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'salaire', 'opts_set' => 1, 'inc_extra' => 1, 'end_none' => 1], []));
        $c166('left-removed', strpos($a, 'حتى تاريخه') !== false && strpos($a, 'ولغاية تاريخ') === false);
        $c166('left-removed-afade-dotted', preg_match('/عن العمل بتاريخ <strong><span style="display:inline-block;min-width:110px;border-bottom:1px dotted/u', $area166(renderPage('pages/attestations.php', ['employee_id' => $left166, 'type' => 'afade_madrasiya', 'opts_set' => 1, 'inc_extra' => 1, 'end_none' => 1], []))) === 1);
    } else { $why166[] = 'no-left-teacher(skipped)'; }
    $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire'], []));
    $c166('no-left-unchanged', strpos($a, 'حتى تاريخه') !== false && strpos($a, 'ولغاية') === false);
    // 🏫 «بالإفادات ما تخلّي اسم المدرسة يتكرّر مرتين حدّ بعض» (2026-09-24): مدرسة اسمها يبدأ بـ«مدرسة» وفرنسيها بـ«Ecole» ⇒ لا «مدرسة : مدرسة …» ولا «l'école Ecole …» ولا «school Ecole …»
    $dup166 = (int)$db->query("SELECT e.id FROM employees e JOIN schools s ON s.id = e.school_id WHERE e.is_deleted = 0 AND e.employee_type <> 'employe' AND e.hire_date IS NOT NULL AND s.name_ar LIKE 'مدرسة %' AND (s.name_fr LIKE 'Ecole %' OR s.name_fr LIKE 'École %') ORDER BY e.id LIMIT 1")->fetchColumn();
    if ($dup166) {
        foreach (['afade_madrasiya', 'aqd_taalim', 'isqat_haq', 'anhaa_khedme'] as $ty166) foreach (['ar', 'fr', 'en'] as $lg166) {
            $txt = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($area166(renderPage('pages/attestations.php', ['employee_id' => $dup166, 'type' => $ty166, 'lang_doc' => $lg166], [])))));
            $c166("no-dup-school-$ty166/$lg166", $txt !== '' && preg_match('/(مدرسة|مؤسسة|دير|مركز|دار|مستوصف|ثانوية) ?:? ?\1 /u', $txt) === 0 && preg_match('/(l[\x27’]?[EeÉé]cole|[EeÉé]cole|school) ?:? ?[EeÉé]cole /u', $txt) === 0);
        }
        $c166('no-dup-school-afade-sample', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $dup166, 'type' => 'afade_madrasiya'], [])), 'مديرة مدرسة : <strong>مدرسة ') === false);
    } else { $why166[] = 'no-dup-school-sample(skipped)'; }
    $m166 = (int)$db->query("SELECT id FROM employees WHERE is_deleted=0 AND gender='m' AND employee_type<>'employe' AND left_date_all IS NULL LIMIT 1")->fetchColumn();
    if ($m166) {
        $aM = $area166(renderPage('pages/attestations.php', ['employee_id' => $m166, 'type' => 'tadris', 'lang_doc' => 'fr'], []));
        $c166('pronoun-fr-male', strpos($aM, 'Il fait preuve de bonne conduite') !== false && strpos($aM, 'Il/Elle') === false);
        $aM = $area166(renderPage('pages/attestations.php', ['employee_id' => $m166, 'type' => 'tadris', 'lang_doc' => 'en'], []));
        $c166('pronoun-en-male', strpos($aM, 'He has shown good conduct and commitment in the performance of his duties.') !== false && strpos($aM, 'He/She') === false);
    }
    // 📚 «ببطاقة الراتب السنوية حطّ المواد محلّ الدرجة» (2026-09-24): خانة المواد بالسطر الأوّل مكان الدرجة، والدرجة بالسطر الثالث مكانها — تبديل فقط
    $as166 = (string)file_get_contents($PROJ . '/pages/annual_slip.php');
    $pS166 = strpos($as166, '<span class="lbl">Matières / المواد</span>'); $pG166 = strpos($as166, '<span class="lbl">Échelon / الدرجة</span>'); $pT166 = strpos($as166, '<span class="lbl">Type / الفئة</span>'); $pC166 = strpos($as166, '<span class="lbl">Code / الرمز</span>');
    $c166('slip-subjects-in-grade-cell', $pS166 !== false && $pG166 !== false && $pT166 < $pS166 && $pS166 < $pC166 && $pG166 > $pC166 && substr_count($as166, 'Matières / المواد') === 1 && substr_count($as166, 'Échelon / الدرجة') === 1);
    // 📚 «شيّك بالإفادات إذا عم تذكر المواد»: الإفادات القانونية تذكر المادة مع الصفة (الضمان/الإسقاط/الإبراء/الإقرار/المدرسية) بالعربي والفرنسي
    $sj166 = (string)$db->query("SELECT subjects_taught FROM employees WHERE id = $eid166")->fetchColumn();
    foreach (['cnss' => 'بصفة (<strong>', 'isqat_haq' => 'بصفة : <strong>', 'baraa_zimma' => 'بصفة <strong>', 'iqrar' => 'بصفة <strong>'] as $ty166 => $pre166) {
        $a = $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => $ty166, 'opts_set' => 1, 'inc_extra' => 1, 'subj_ovr' => 'رياضيات'], []));
        $c166("subject-in-$ty166", preg_match('/' . preg_quote($pre166, '/') . '[^<]*— مادة الرياضيات/u', $a) === 1);
    }
    $c166('subject-in-afade', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'afade_madrasiya', 'opts_set' => 1, 'inc_extra' => 1, 'subj_ovr' => 'رياضيات'], [])), 'التدريس في مدرستنا لمادة <strong>الرياضيات</strong> بتاريخ') !== false);
    $c166('subject-in-isqat-fr', preg_match('/en qualité de : <strong>[^<]*— matière : Mathématiques/u', $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'isqat_haq', 'lang_doc' => 'fr', 'opts_set' => 1, 'inc_extra' => 1, 'subj_ovr' => 'رياضيات'], []))) === 1);
    $c166('subject-free', strpos($area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'tadris', 'opts_set' => 1, 'inc_extra' => 1, 'subj_ovr' => 'مادة حرّة خاصة'], [])), 'لمادة <strong>مادة حرّة خاصة</strong>') !== false);
    $c166('subject-translated', preg_match('/la matière <strong>Math/u', $area166(renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => 'salaire', 'lang_doc' => 'fr', 'opts_set' => 1, 'inc_extra' => 1, 'subj_ovr' => 'رياضيات'], []))) === 1);
    foreach (['salaire','tadris','embassy','riaaya','anhaa_khedme','afade_madrasiya','isqat_haq','iqrar','aqd_taalim','notice_mail','cnss'] as $ty166) {
        foreach (['ar','fr','en'] as $lg166) {
            $h = renderPage('pages/attestations.php', ['employee_id' => $eid166, 'type' => $ty166, 'lang_doc' => $lg166, 'opts_set' => 1, 'inc_extra' => 1, 'rate_show' => 1, 'amt' => 777, 'amt_cur' => 'usd', 'cur' => 'both', 'sig_t' => 'raisa', 'sig_name' => 'Sr Test', 'date' => '2026-02-02', 'hire_dt' => '2011-10-03', 'end_dt' => '2026-06-30', 'id_nssf' => 1, 'id_mof' => 1, 'id_eoc' => 1, 'subj_ovr' => 'رياضيات'], []);
            $c166("no-error $ty166/$lg166", strpos($h, 'FATAL') === false && strpos($h, 'Warning:') === false && strpos($h, 'id="ppExportArea"') !== false && strpos($h, 'name="sig_t"') !== false && strpos($h, 'name="amt"') !== false && substr_count($h, 'class="id-line"') === 1);
        }
    }
} else { $ok166 = false; $why166[] = 'no employee'; }
check('✍️📅💱🪪 خيارات الإفادات (2026-09-24): سعر الدولار حطّ/شيل + صفة واسم الموقّع بكل الإفادات + التاريخ المطبوع + المبلغ اليدوي + تاريخ الدخول من – إلى + أرقام الضمان/المالية/صندوق التعويضات (آخر الإفادة قبل التوقيع) + تاريخ الترك من الملف (غيّره/شيله) + المادة تُكتب أو تُغيَّر — بكل الأنواع واللغات بلا أخطاء', $ok166, implode(' · ', $why166) ?: 'ok');

/* =====================================================================
 * 167) 👨‍👩‍👧📋 ملف التعويض العائلي الجماعي (2026-09-24 «بدل ما فوت على كل موظف… ملف فيه كل الموظفين وقدام كل واحد الزوجة/الأولاد من–إلى،
 *      أحدّد الفئة والمدرسة، وبس أحطّهم وأكبس طبّق يروح على ملف كل موظف»): الصفحة ترندر (كل المدارس + مدرسة + فلتر الفئة/العرض/البحث)،
 *      المتعاقد مقفول، والتطبيق الجماعي = حفظ ملف الموظف نفسه (المبلغان + المدّتان + إعادة حساب السنة) — تجربة فعلية مع ترجيع + idempotent + الإيقاف بـ«إلى شهر»
 * =================================================================== */
$ok167 = true; $why167 = [];
$c167 = function (string $n, bool $ok) use (&$ok167, &$why167) { if (!$ok) { $ok167 = false; $why167[] = $n; } };
$c167('applyFamilyAllowanceDates-shared', function_exists('applyFamilyAllowanceDates') && function_exists('familyAllowanceBulkApply')
    && strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'function applyFamilyAllowanceDates') === false);
$hdr167 = (string)file_get_contents($PROJ . '/includes/header.php');
$c167('nav+dashboard', strpos($hdr167, 'pages/family_allowances.php') !== false && strpos($hdr167, "'family_allowances'=>'personnel'") !== false
    && strpos($hdr167, "'family_allowances.php'") !== false && strpos((string)file_get_contents($PROJ . '/index.php'), 'pages/family_allowances.php') !== false);
$sy167 = currentSchoolYear();
$vis167 = fn(string $h, string $cls = '(?:na)?', string $cat = '[a-z]+') => preg_match_all('/<tr class="' . $cls . '" data-id="\d+" data-cat="' . $cat . '" data-school="\d+" data-cur="\d+">/', $h); // ⚡ الصفوف الظاهرة (غير المشيّكة تصل بـstyle=display:none)
$h0 = renderPage('pages/family_allowances.php', ['sch' => 'all', 'sy' => $sy167], []);
$c167('first-open-nobody', $noFatal($h0) && $vis167($h0) === 0 && strpos($h0, 'ما في ولا فئة مشيّكة') !== false && strpos($h0, 'value="titulaire" ') !== false && strpos($h0, 'value="titulaire" checked') === false); // ☐ «اتفقنا نغيّر تشك مارك»: أوّل فتحة بلا تشك مارك ولا أحد
$h = renderPage('pages/family_allowances.php', ['sch' => 'all', 'sy' => $sy167, 'cat' => ['titulaire', 'contractuel', 'employe']], []);
$nElig = substr_count($h, '<tr class="" data-id='); $nNa = substr_count($h, '<tr class="na" data-id=');
$c167('render-all', $noFatal($h) && strpos($h, 'id="faForm"') !== false && strpos($h, 'name="fa[') !== false && $nElig > 0 && strpos($h, 'type="month"') !== false && stripos($h, 'Warning:') === false);
$c167('edit-save-per-row', preg_match('/<td class="fa-act"[^>]*>\s*<button type="button" class="btn btn-sm btn-primary fa-edit-btn"/', $h) === 1 && strpos($h, 'fa-save-btn') !== false
    && preg_match('/name="fa\[\d+\]\[sp\]"[^>]* readonly>/', $h) === 1 && strpos($h, 'Modifier / تعديل</th>') !== false && strpos($h, 'function unlockRow(tr)') !== false); // ✏️ «لازم يكون قدام الموظف في إديت»
$c167('contractuel-locked', $nNa === 0 || (substr_count($h, 'متعاقد: لا يستحقّ') === $nNa && preg_match('/<tr class="na" data-id="\d+"[^>]*>.*?<input[^>]*name="fa\[\d+\]\[sp\]"[^>]* disabled>/s', $h) === 1)); // ⚡ الصفّ يحمل data-cat/data-school (2026-09-26)
[$yf167, $yp167] = yearEmploymentFilter($sy167, 'e.');
$h2 = renderPage('pages/family_allowances.php', ['sch' => 'all', 'sy' => $sy167, 'cat' => ['titulaire'], 'show' => 'with'], []);
$stW = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND (COALESCE(e.family_allowance_spouse_lbp,0) > 0 OR COALESCE(e.family_allowance_children_lbp,0) > 0)" . $yf167);
$stW->execute($yp167);
$c167('render-filter-titulaire-with', $noFatal($h2) && $vis167($h2, 'na') === 0 && $vis167($h2, '', 'employe') === 0 && $vis167($h2, '', 'contractuel') === 0
    && $vis167($h2, '', 'titulaire') === (int)$stW->fetchColumn());
// ☑️ «الفئة المشيّكة بس هي تبيّن، وبلا ولا تشك مارك ما يبيّن حدا» (2026-09-24 مساءً): cat_set بلا cat = صفر صفوف + رسالة؛ الموظفون وحدهم = لا ملاك ولا متعاقد
$h2b = renderPage('pages/family_allowances.php', ['sch' => 'all', 'sy' => $sy167, 'cat_set' => 1], []);
$c167('no-category-no-rows', $noFatal($h2b) && $vis167($h2b) === 0 && strpos($h2b, 'ما في ولا فئة مشيّكة') !== false && strpos($h2b, 'name="cat_set"') !== false);
$h2c = renderPage('pages/family_allowances.php', ['sch' => 'all', 'sy' => $sy167, 'cat_set' => 1, 'cat' => ['employe']], []);
$c167('employe-only', $noFatal($h2c) && $vis167($h2c, '(?:na)?', 'titulaire') === 0 && $vis167($h2c, '(?:na)?', 'contractuel') === 0 && $vis167($h2c, '(?:na)?', 'employe') > 0 && strpos($h2c, 'data-cat="titulaire" data-school="') !== false); // الملاك موجودون مخفيّين
$sch167 = (int)$db->query("SELECT school_id FROM employees WHERE is_deleted = 0 AND employee_type = 'enseignant_titulaire' LIMIT 1")->fetchColumn();
$h3 = renderPage('pages/family_allowances.php', ['sch' => $sch167, 'sy' => $sy167, 'q' => 'ا', 'cat' => ['titulaire', 'contractuel', 'employe']], []);
$c167('render-school-search', $noFatal($h3) && strpos($h3, 'École / المدرسة</th>') === false && strpos($h3, 'id="faForm"') !== false);
// تجربة فعلية: ملاك بسنة جارية بأشهر غير مدفوعة وبلا تعويض ⇒ تطبيق زوجة+أولاد من كانون الأول، الأولاد حتى آذار ⇒ الأشهر تتبع + idempotent + الإيقاف ⇒ ترجيع كامل
[$y167] = schoolYearToYears($sy167);
$t167 = $db->query("SELECT e.id, e.school_id FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND COALESCE(e.family_allowance_children_lbp,0) = 0 AND COALESCE(e.family_allowance_spouse_lbp,0) = 0
    AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = '$sy167' AND is_paid = 0 AND base_plus_echelon_lbp > 0 AND month = 12) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($t167) {
    $tid = (int)$t167['id'];
    $snap = $db->query("SELECT family_allowance_spouse_lbp sp, family_allowance_children_lbp ch, family_allowance_spouse_from sf, family_allowance_spouse_to st, family_allowance_children_from cf, family_allowance_children_to ct, family_allowance_from f0, family_allowance_to t0 FROM employees WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
    $before = $db->query("SELECT * FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$sy167' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
    $db->exec("DROP TEMPORARY TABLE IF EXISTS _ms_bak167"); $db->exec("CREATE TEMPORARY TABLE _ms_bak167 AS SELECT * FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$sy167'");
    try {
        $m1 = sprintf('%04d-12', $y167); $m2 = sprintf('%04d-03', $y167 + 1);
        $r1 = familyAllowanceBulkApply($db, [$tid => ['sp' => '500,000', 'spf' => $m1, 'spt' => '', 'ch' => '2,310,000', 'chf' => $m1, 'cht' => $m2], 999999999 => ['sp' => 1]], $sy167, ' AND e.school_id = ?', [(int)$t167['school_id']], 'regcheck');
        $fam = function () use ($db, $tid, $sy167) { $o = []; foreach ($db->query("SELECT month, family_allowance_lbp f, total_due_lbp d FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$sy167'")->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['month']] = [(int)$r['f'], (int)$r['d']]; return $o; };
        $b = []; foreach ($before as $r) $b[(int)$r['month']] = [(int)$r['family_allowance_lbp'], (int)$r['total_due_lbp']];
        $a = $fam();
        $c167('apply-changed-1', $r1['changed'] === 1 && $r1['recalc'] === 1 && $r1['skipped'] === 1);
        $emp = $db->query("SELECT * FROM employees WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
        $c167('apply-file', (int)$emp['family_allowance_spouse_lbp'] === 500000 && (int)$emp['family_allowance_children_lbp'] === 2310000 && $emp['family_allowance_spouse_from'] === $m1 . '-01' && $emp['family_allowance_spouse_to'] === null && $emp['family_allowance_children_from'] === $m1 . '-01' && $emp['family_allowance_children_to'] === $m2 . '-01');
        $c167('apply-months', isset($a[11], $a[12], $a[3], $a[4]) && $a[11][0] === 0 && $a[12][0] === 2810000 && $a[3][0] === 2810000 && $a[4][0] === 500000
            && $a[12][1] === $b[12][1] + 2810000 && $a[4][1] === $b[4][1] + 500000 && $a[11][1] === $b[11][1]); // المستحق يركب بالضبط
        $r2 = familyAllowanceBulkApply($db, [$tid => ['sp' => '500000', 'spf' => $m1, 'spt' => '', 'ch' => '2310000', 'chf' => $m1, 'cht' => $m2]], $sy167, '', [], 'regcheck');
        $c167('idempotent', $r2['changed'] === 0);
        $r2b = familyAllowanceBulkApply($db, [$tid => ['sp' => '500000', 'spf' => '', 'spt' => '', 'ch' => '2310000', 'chf' => '', 'cht' => $m2]], $sy167, '', [], 'regcheck');
        $c167('idempotent-empty-from-keeps-stored', $r2b['changed'] === 0); // «من» فارغ مع نفس المبلغ = يبقى المخزّن، لا تغيير وهمي
        $r3 = familyAllowanceBulkApply($db, [$tid => ['sp' => '500000', 'spf' => $m1, 'spt' => $m2, 'ch' => '2310000', 'chf' => $m1, 'cht' => $m2]], $sy167, '', [], 'regcheck');
        $a3 = $fam();
        $c167('stop-by-to-month', $r3['changed'] === 1 && $a3[4][0] === 0 && $a3[3][0] === 2810000 && $a3[4][1] === $b[4][1]);
        $ct = $db->query("SELECT id FROM employees WHERE is_deleted = 0 AND employee_type = 'enseignant_contractuel' LIMIT 1")->fetchColumn();
        if ($ct) { $r4 = familyAllowanceBulkApply($db, [(int)$ct => ['sp' => '900000', 'ch' => '900000']], $sy167, '', [], 'regcheck'); $c167('contractuel-skipped', $r4['changed'] === 0 && $r4['skipped'] === 1 && (int)$db->query("SELECT COALESCE(family_allowance_children_lbp,0) FROM employees WHERE id = $ct")->fetchColumn() !== 900000); }
    } catch (Throwable $e) { $c167('exception:' . $e->getMessage(), false); }
    $db->prepare("UPDATE employees SET family_allowance_spouse_lbp = ?, family_allowance_children_lbp = ?, family_allowance_spouse_from = ?, family_allowance_spouse_to = ?, family_allowance_children_from = ?, family_allowance_children_to = ?, family_allowance_from = ?, family_allowance_to = ? WHERE id = ?")
       ->execute([$snap['sp'], $snap['ch'], $snap['sf'], $snap['st'], $snap['cf'], $snap['ct'], $snap['f0'], $snap['t0'], $tid]);
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$sy167'");
    $db->exec("INSERT INTO monthly_salaries SELECT * FROM _ms_bak167"); $db->exec("DROP TEMPORARY TABLE _ms_bak167");
    try { $db->exec("DELETE FROM audit_log WHERE action = 'family_allowance_bulk' AND record_id = $tid"); } catch (Throwable $e) {}
    $after = $db->query("SELECT * FROM monthly_salaries WHERE employee_id = $tid AND school_year = '$sy167' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
    $c167('restored', $after === $before);
} else { $why167[] = 'no test employee (skipped live test)'; }
check('👨‍👩‍👧📋 ملف التعويض العائلي الجماعي (2026-09-24): صفحة family_allowances.php (مدرسة/كل المدارس + الفئة + عندهم/بلا + بحث) + المتعاقد مقفول + «طبّق» = حفظ ملف الموظف نفسه (المبلغان + المدّتان + إعادة حساب السنة) — تجربة فعلية مع ترجيع + idempotent + الإيقاف بـ«إلى شهر» + الملاحة', $ok167, implode(' · ', $why167) ?: 'ok');

/* =====================================================================
 * 168) 📅💱 التعويض العائلي الشهري (2026-09-24 «أوقات خلال السنة بتتغيّر قيمة التعويض من شهر لشهر — بختار أي شهر وبحطّ القيمة،
 *      وإذا ما غيّرتها بأي شهر بتضلّ هي ذاتها خلال السنة حتى غيّرها بأي شهر» — بملف الموظف وبالملف الجماعي معاً):
 *      جدول family_allowance_changes يتركّب ذاتياً + المصدر الواحد familyAllowanceKindAmount داخل familyAllowanceForMonth + المنقول/المخالفات
 *      يعتبران التغيير تسجيلاً (familyAllowanceHasAny) + الفورم (قسم التغييرات) + الملف الجماعي (سطر «شهري») — تجربة فعلية مع ترجيع كامل
 * =================================================================== */
$ok168 = true; $why168 = [];
$c168 = function (string $n, bool $ok) use (&$ok168, &$why168) { if (!$ok) { $ok168 = false; $why168[] = $n; } };
ensureFamilyAllowanceDateColumns();
$c168('table', (bool)$db->query("SHOW TABLES LIKE 'family_allowance_changes'")->fetch() && function_exists('familyAllowanceKindAmount') && function_exists('saveFamilyAllowanceChanges') && function_exists('familyAllowanceHasAny'));
$fn168 = (string)file_get_contents($PROJ . '/includes/functions.php');
$c168('single-source', strpos($fn168, "familyAllowanceMonthInWindow(\$emp, \$month, \$year, 'spouse')   ? familyAllowanceKindAmount(\$emp, \$month, \$year, 'spouse')") !== false
    && strpos((string)file_get_contents($PROJ . '/includes/payroll_calculator.php'), '$doFam = $empRow && !$famZeroAll && familyAllowanceHasAny($empRow);') !== false
    && strpos((string)file_get_contents($PROJ . '/includes/compliance.php'), '$hasAmt = familyAllowanceHasAny($r);') !== false);
$em168 = (string)file_get_contents($PROJ . '/pages/employees.php');
$c168('form-code', strpos($em168, 'id="faChgBox"') !== false && substr_count($em168, "if (isset(\$_POST['fa_chg_set'])) saveFamilyAllowanceChanges(") === 2 && strpos($em168, 'name="fa_chg[<?= $i ?>][from]"') !== false);
$fa168 = (string)file_get_contents($PROJ . '/pages/family_allowances.php');
$c168('totals-row', strpos($fa168, 'class="fa-total-row"') !== false && strpos($fa168, 'id="faTotSp"') !== false && strpos($fa168, 'id="faTotCh"') !== false && strpos($fa168, 'id="faTotCur"') !== false); // 🧮 «ديماً مجموع للكل»
$c168('bulk-code', strpos($fa168, 'class="fa-chg-row"') !== false && strpos($fa168, '[chg_set]') !== false && strpos($fa168, 'fa-chg-btn') !== false && strpos($fn168, "if (!empty(\$r['chg_set']) || (isset(\$r['chg']) && is_array(\$r['chg'])))") !== false);
// تجربة فعلية على ملاك بأشهر غير مدفوعة بلا تعويض: أولاد 2,000,000 من تشرين الأول ⇒ 2,500,000 من كانون الثاني ⇒ 0 من أيار ⇒ إزالة التغييرات ⇒ تغيير بلا مبلغ أساس ⇒ ترجيع
$sy168 = currentSchoolYear(); [$y168] = schoolYearToYears($sy168);
$t168 = $db->query("SELECT e.id, e.school_id FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND COALESCE(e.family_allowance_children_lbp,0) = 0 AND COALESCE(e.family_allowance_spouse_lbp,0) = 0
    AND e.id NOT IN (SELECT employee_id FROM family_allowance_changes)
    AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = '$sy168' AND is_paid = 0 AND base_plus_echelon_lbp > 0 AND month = 12) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($t168) {
    $tid168 = (int)$t168['id'];
    $snap168 = $db->query("SELECT family_allowance_spouse_lbp sp, family_allowance_children_lbp ch, family_allowance_spouse_from sf, family_allowance_spouse_to st, family_allowance_children_from cf, family_allowance_children_to ct, family_allowance_from f0, family_allowance_to t0 FROM employees WHERE id = $tid168")->fetch(PDO::FETCH_ASSOC);
    $before168 = $db->query("SELECT * FROM monthly_salaries WHERE employee_id = $tid168 AND school_year = '$sy168' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
    $db->exec("DROP TEMPORARY TABLE IF EXISTS _ms_bak168"); $db->exec("CREATE TEMPORARY TABLE _ms_bak168 AS SELECT * FROM monthly_salaries WHERE employee_id = $tid168 AND school_year = '$sy168'");
    try {
        $famF = function () use ($db, $tid168, $sy168) { $o = []; foreach ($db->query("SELECT month, family_allowance_lbp f, total_due_lbp d FROM monthly_salaries WHERE employee_id = $tid168 AND school_year = '$sy168'")->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['month']] = [(int)$r['f'], (int)$r['d']]; return $o; };
        $b168 = []; foreach ($before168 as $r) $b168[(int)$r['month']] = [(int)$r['family_allowance_lbp'], (int)$r['total_due_lbp']];
        $y2 = $y168 + 1;
        $r1 = familyAllowanceBulkApply($db, [$tid168 => ['sp' => '0', 'ch' => '2,000,000', 'chf' => "$y168-10", 'chg_set' => 1, 'chg' => [
            ['kind' => 'children', 'from' => "$y2-01", 'amt' => '2,500,000'], ['kind' => 'children', 'from' => "$y2-05", 'amt' => '0'], ['kind' => 'spouse', 'from' => '', 'amt' => '5']]]], $sy168, '', [], 'regcheck');
        $a = $famF();
        $c168('apply', $r1['changed'] === 1 && $r1['recalc'] === 1 && $a[10][0] === 2000000 && $a[12][0] === 2000000 && $a[1][0] === 2500000 && $a[4][0] === 2500000 && $a[5][0] === 0 && $a[9][0] === 0
            && $a[1][1] === $b168[1][1] + 2500000 && $a[5][1] === $b168[5][1] && count(familyAllowanceChangesRows($tid168)) === 2); // الصفّ غير الصالح أُهمل
        $emp168 = $db->query("SELECT * FROM employees WHERE id = $tid168")->fetch(PDO::FETCH_ASSOC);
        $c168('forMonth', familyAllowanceForMonth($emp168, 10, $y168) === 2000000 && familyAllowanceForMonth($emp168, 1, $y2) === 2500000 && familyAllowanceForMonth($emp168, 4, $y2) === 2500000 && familyAllowanceForMonth($emp168, 5, $y2) === 0 && familyAllowanceHasAny($emp168));
        $r2 = familyAllowanceBulkApply($db, [$tid168 => ['sp' => '0', 'ch' => '2000000', 'chf' => "$y168-10", 'chg_set' => 1, 'chg' => [['kind' => 'children', 'from' => "$y2-01", 'amt' => '2500000'], ['kind' => 'children', 'from' => "$y2-05", 'amt' => '0']]]], $sy168, '', [], 'regcheck');
        $c168('idempotent', $r2['changed'] === 0);
        $r3 = familyAllowanceBulkApply($db, [$tid168 => ['sp' => '0', 'ch' => '2000000', 'chf' => "$y168-10", 'chg_set' => 1]], $sy168, '', [], 'regcheck');
        $a3 = $famF();
        $c168('remove-changes', $r3['changed'] === 1 && $a3[1][0] === 2000000 && $a3[5][0] === 2000000 && count(familyAllowanceChangesRows($tid168)) === 0);
        $r4 = familyAllowanceBulkApply($db, [$tid168 => ['sp' => '0', 'ch' => '0', 'chf' => '', 'chg_set' => 1, 'chg' => [['kind' => 'children', 'from' => "$y2-02", 'amt' => '1,000,000']]]], $sy168, '', [], 'regcheck');
        $emp168 = $db->query("SELECT * FROM employees WHERE id = $tid168")->fetch(PDO::FETCH_ASSOC); $a4 = $famF();
        $c168('change-without-base', $r4['changed'] === 1 && familyAllowanceFromKeyMin($emp168) === $y2 * 12 + 2 && $a4[1][0] === 0 && $a4[2][0] === 1000000 && $a4[9][0] === 1000000 && familyAllowanceHasAny($emp168));
        // الفورم يعرض القسم مع سطر التغيير، والملف الجماعي يعرض سطر «شهري» بالقيمة
        $hf = renderPage('pages/employees.php', ['action' => 'edit', 'id' => $tid168, 'tab' => 'finance'], []);
        $c168('form-render', $noFatal($hf) && strpos($hf, 'id="faChgBox"') !== false && preg_match('/name="fa_chg\[0\]\[from\]"[^>]*value="' . $y2 . '-02"/', $hf) === 1 && preg_match('/name="fa_chg\[0\]\[amt\]"[^>]*value="1000000"/', $hf) === 1);
        $hb = renderPage('pages/family_allowances.php', ['sch' => (int)$t168['school_id'], 'sy' => $sy168, 'cat' => ['titulaire']], []);
        $c168('bulk-render', $noFatal($hb) && preg_match('/<tr class="fa-chg-row" data-for="' . $tid168 . '"/', $hb) === 1 && preg_match('/name="fa\[' . $tid168 . '\]\[chg\]\[0\]\[amt\]" value="1,000,000"/', $hb) === 1 && preg_match('/fa-chg-btn" data-id="' . $tid168 . '"[^>]*>.*?1<\/button>/s', $hb) === 1);
    } catch (Throwable $e) { $c168('exception:' . $e->getMessage(), false); }
    $db->prepare("DELETE FROM family_allowance_changes WHERE employee_id = ?")->execute([$tid168]);
    $db->prepare("UPDATE employees SET family_allowance_spouse_lbp = ?, family_allowance_children_lbp = ?, family_allowance_spouse_from = ?, family_allowance_spouse_to = ?, family_allowance_children_from = ?, family_allowance_children_to = ?, family_allowance_from = ?, family_allowance_to = ? WHERE id = ?")
       ->execute([$snap168['sp'], $snap168['ch'], $snap168['sf'], $snap168['st'], $snap168['cf'], $snap168['ct'], $snap168['f0'], $snap168['t0'], $tid168]);
    $db->exec("DELETE FROM monthly_salaries WHERE employee_id = $tid168 AND school_year = '$sy168'");
    $db->exec("INSERT INTO monthly_salaries SELECT * FROM _ms_bak168"); $db->exec("DROP TEMPORARY TABLE _ms_bak168");
    try { $db->exec("DELETE FROM audit_log WHERE action = 'family_allowance_bulk' AND record_id = $tid168"); } catch (Throwable $e) {}
    $after168 = $db->query("SELECT * FROM monthly_salaries WHERE employee_id = $tid168 AND school_year = '$sy168' ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC);
    $c168('restored', $after168 === $before168);
} else { $why168[] = 'no test employee (skipped live test)'; }
check('📅💱 التعويض العائلي الشهري (2026-09-24): تغييرات المبلغ خلال السنة (النوع + من شهر + المبلغ الجديد، يبقى حتى التغيير التالي، 0 = يوقف) بملف الموظف وبالملف الجماعي — جدول ذاتي + المصدر الواحد + المنقول/المخالفات — تجربة فعلية مع ترجيع', $ok168, implode(' · ', $why168) ?: 'ok');

/* =====================================================================
 * 169) ☑️ منتقي الفئة بخانات تشييك (2026-09-25 «بدي بس حط تشاك مارك لأي فئة من الموظفين تبيّن أسماء هيدي الفئة بس،
 *      وإذا مش حاطط تشاك مارك ما يبيّنوا»): المصدر الواحد empTypeSelection/empTypeSqlFrom/empTypeTitleFrom/empTypeCheckboxes
 *      بكل التقارير + النماذج الرسمية + التصدير + الرواتب الشهرية + البطاقة السنوية (اللائحة) + لائحة الموظفين.
 *      المشيّكة فقط تبيّن؛ ولا واحدة مشيّكة ⇒ لا أحد؛ أوّل فتحة بلا اختيار = الكل؛ الرابط القديم emp_type=x ما زال يعمل.
 * =================================================================== */
$ok169 = true; $why169 = [];
$c169 = function (string $what, bool $ok) use (&$ok169, &$why169) { if (!$ok) { $ok169 = false; $why169[] = $what; } };
// (أ) الدوال المركزية
$stA = empTypeSelection([]);                                   $c169('default=all', $stA['all'] && !$stA['none'] && count($stA['sel']) === 3);
$stN = empTypeSelection(['emp_type_set' => '1']);              $c169('set-none', $stN['none'] && !$stN['all'] && $stN['sel'] === []);
$stT = empTypeSelection(['emp_type' => ['employe', 'enseignant_titulaire']]);
$c169('two-canonical-order', !$stT['all'] && !$stT['none'] && $stT['sel'] === ['enseignant_titulaire', 'employe']);
$stL = empTypeSelection(['emp_type' => 'enseignant_contractuel']); $c169('legacy-single', $stL['sel'] === ['enseignant_contractuel'] && !$stL['all']);
$stP = empTypeSelection(['type' => ['employe'], 'type_set' => '1'], 'type'); $c169('param-type', $stP['sel'] === ['employe']);
$c169('sql-none', empTypeSqlFrom($db, $stN) === ' AND 1=0');
$c169('sql-all', empTypeSqlFrom($db, $stA) === '');
$c169('sql-two', empTypeSqlFrom($db, $stT, 'e.') === " AND e.employee_type IN ('enseignant_titulaire','employe')");
$c169('title', empTypeTitleFrom($stT) === 'الملاك + الموظفين' && empTypeTitleFrom($stN) === 'بلا فئة مشيّكة' && empTypeTitleFrom($stA) === '');
$c169('query', empTypeQueryFrom($stA) === '' && empTypeQueryFrom($stN) === '&emp_type_set=1'
      && empTypeQueryFrom($stT, 'type') === '&type_set=1&type[]=enseignant_titulaire&type[]=employe');
$cb169 = empTypeCheckboxes($stT, true, 'type');
$c169('checkboxes-html', substr_count($cb169, 'type="checkbox"') === 3 && substr_count($cb169, ' checked') === 2
      && strpos($cb169, 'name="type_set" value="1"') !== false && strpos($cb169, 'onchange="(window.msaSubmitSoon||function(f){f.submit()})(this.form)"') !== false); // ⏳ مؤجَّل (2026-09-26)
// (ب) المصدر: كل الصفحات على المصدر الواحد — لا قائمة منسدلة للفئة بقيت
foreach (['pages/reports.php', 'pages/reports_export.php', 'pages/official_forms.php', 'pages/monthly_payroll.php', 'pages/annual_slip.php', 'pages/annual_slip_export.php', 'pages/employees.php'] as $pf169) {
    $src169 = (string)file_get_contents($PROJ . '/' . $pf169);
    $c169("src:$pf169", strpos($src169, 'empTypeSelection(') !== false && strpos($src169, 'empTypeSqlFrom(') !== false
        && preg_match('/<select name="(emp_type|type)"/', $src169) === 0 && strpos($src169, '&type=<?=') === false && strpos($src169, "'&type=' . urlencode") === false); // روابط الفئة القديمة فقط (لا نوع الإفادة/translit)
}
$c169('src:mof', strpos((string)file_get_contents($PROJ . '/includes/functions.php'), 'return empTypeSqlFrom($db, empTypeSelection())') !== false);
// (ج) تجربة فعلية: كشف حزيران 2026 — ولا فئة مشيّكة = لا بيانات وعنوان «بلا فئة مشيّكة»؛ فئتان = مجموع الفئتين بالقاعدة
$hN = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026, 'emp_type_set' => '1'], []);
$c169('live-none', $noFatal($hN) && strpos($hN, 'لا توجد بيانات') !== false && strpos($hN, '— بلا فئة مشيّكة') !== false);
$hT = renderPage('pages/reports.php', ['report' => 'monthly_summary', 'month' => 6, 'year' => 2026, 'emp_type_set' => '1', 'emp_type' => ['enseignant_titulaire', 'employe']], []);
preg_match('/مجموع كل الفئات \(العدد: (\d+)\)/u', $hT, $mT);
$expT = $q34(" AND e.employee_type IN ('enseignant_titulaire','employe')");
$c169('live-two=' . ($mT[1] ?? '?') . '/' . $expT, $noFatal($hT) && isset($mT[1]) && (int)$mT[1] === $expT && $expT > 0 && strpos($hT, '— الملاك + الموظفين') !== false
      && preg_match('/name="emp_type\[\]" value="enseignant_titulaire" checked/', $hT) === 1 && preg_match('/name="emp_type\[\]" value="enseignant_contractuel"(?! checked)/', $hT) === 1);
// النماذج الرسمية: كشف رواتب كل الموظفين (مدرسة 3) — فئتان = مجموع كلٍّ لحاله، وولا واحدة = 0
$n169two = $n34(['emp_type_set' => '1', 'emp_type' => ['enseignant_titulaire', 'enseignant_contractuel']]);
$n169sum = $n34(['emp_type' => 'enseignant_titulaire']) + $n34(['emp_type' => 'enseignant_contractuel']);
$c169("of-two=$n169two/$n169sum", $n169two > 0 && $n169two === $n169sum);
$hON = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026, 'emp_type_set' => '1'], [], [3]);
$c169('of-none', $noFatal($hON) && strpos($hON, 'بلا فئة مشيّكة') !== false && (preg_match('/المجموع العام \((\d+)\)/u', $hON, $mON) === 0 || (int)$mON[1] === 0));
// الرواتب الشهرية + لائحة الموظفين + البطاقة السنوية: خانات التشييك ظاهرة، وولا فئة = لا صفوف
$hM = renderPage('pages/monthly_payroll.php', ['month' => 6, 'year' => 2026, 'type_set' => '1'], []);
$c169('monthly-none', $noFatal($hM) && substr_count($hM, 'name="type[]" value=') === 3 /* 🔁 2026-09-26: علامة data-msa-sync-name="type[]" تُضاف فلا تُعدّ */ && !preg_match('/name="type\[\]" value="[a-z_]+" checked/', $hM));
$hE = renderPage('pages/employees.php', ['type_set' => '1', 'type' => ['employe']], []);
$c169('employees-one', $noFatal($hE) && preg_match('/name="type\[\]" value="employe" checked/', $hE) === 1 && strpos($hE, 'أستاذ في الملاك</') === false);
$hA = renderPage('pages/annual_slip.php', ['school_year' => '2025-2026', 'type_set' => '1', 'type' => ['enseignant_contractuel']], []);
$c169('annual-list', $noFatal($hA) && preg_match('/name="type\[\]" value="enseignant_contractuel" checked/', $hA) === 1 && strpos($hA, '&type_set=1&type[]=enseignant_contractuel') !== false);
check('☑️ منتقي الفئة بخانات تشييك (2026-09-25): المشيّكة فقط تبيّن وولا واحدة = لا أحد — مصدر واحد بكل التقارير/النماذج/التصدير/الرواتب الشهرية/البطاقة/لائحة الموظفين — تجارب فعلية', $ok169, implode(' · ', $why169) ?: 'ok');

/* =====================================================================
 * 170) 🏥👨‍👩‍👧 التعويضات العائلية بتصاريح الضمان (2026-09-25 «التعويضات العائلية للموظفين الخاضعين لقانون العمل لازم تنحطّ
 *      بتصاريح الضمان الشهرية والفصلية — وتعويضات الأساتذة الخاضعين لقانون المعلمين بتبيّن بكل التقارير ما عدا تصاريح وتقارير الضمان»):
 *      «التعويضات العائلية المدفوعة» بتصريح الضمان الشهري/الفصلي (شاشة + إكسل) وبالكشف الاسمي الشهري = موظفو قانون العمل
 *      (employe) فقط — المصدر الواحد cnssFamilyPaidTypeSql / cnssFamilyPaidLbp. باقي التقارير تعرض تعويض الجميع كما هي.
 * =================================================================== */
$ok170 = true; $why170 = [];
$c170 = function (string $what, bool $ok) use (&$ok170, &$why170) { if (!$ok) { $ok170 = false; $why170[] = $what; } };
$c170('fn-sql', cnssFamilyPaidTypeSql('e.') === " AND e.employee_type = 'employe'" && strpos(cnssFamilyPaidExpr(), "WHEN e.employee_type = 'employe' THEN ms.family_allowance_lbp ELSE 0") !== false);
$c170('fn-row', cnssFamilyPaidLbp(['employee_type' => 'employe', 'family_allowance_lbp' => 2310000]) === 2310000
    && cnssFamilyPaidLbp(['employee_type' => 'enseignant_titulaire', 'family_allowance_lbp' => 2310000]) === 0
    && cnssFamilyPaidLbp(['employee_type' => 'enseignant_contractuel', 'family_allowance_lbp' => 500]) === 0);
$of170 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
$ox170 = (string)file_get_contents($PROJ . '/pages/official_export.php');
// التصريح الشهري/الفصلي (شاشة + إكسل رسمي): استعلام المدفوع يحمل حصر الموظفين — والكشف الاسمي صفّه عبر الدالة
$c170('src-forms', preg_match('/SUM\(ms\.family_allowance_lbp\),0\) f FROM monthly_salaries ms JOIN employees e ON e\.id=ms\.employee_id\s+WHERE e\.is_deleted=0" \. cnssFamilyPaidTypeSql\(\'e\.\'\)/', $of170) === 1
    && strpos($of170, '$fpaid = cnssFamilyPaidLbp($r);') !== false);
$c170('src-export', preg_match('/SUM\(ms\.family_allowance_lbp\),0\) f FROM monthly_salaries ms JOIN employees e ON e\.id=ms\.employee_id\s+WHERE e\.is_deleted=0" \. cnssFamilyPaidTypeSql\(\'e\.\'\)/', $ox170) === 1);
// باقي التقارير لم تُحصَر: كشف الرواتب الشهري بمركز التقارير ما زال يعرض التعويض للجميع
$rp170 = (string)file_get_contents($PROJ . '/pages/reports.php');
$c170('others-untouched', strpos($rp170, "money(\$r['family_allowance_lbp'], \$rRate)") !== false && strpos($rp170, 'cnssFamilyPaid') === false);
// تجربة فعلية بالقاعدة: أحدث شهر فيه تعويض مخزّن لأستاذ ملاك — استعلام التصريح (بالحصر) لا يضمّه، وبلا حصر يضمّه
$r170 = $db->query("SELECT ms.school_id, ms.year, ms.month FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
    WHERE e.is_deleted=0 AND e.employee_type='enseignant_titulaire' AND ms.family_allowance_lbp>0 ORDER BY ms.year DESC, ms.month DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r170) {
    $sid170 = (int)$r170['school_id']; $y170 = (int)$r170['year']; $m170 = (int)$r170['month'];
    $qAll170 = (int)$db->query("SELECT COALESCE(SUM(ms.family_allowance_lbp),0) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0 AND ms.school_id=$sid170 AND ms.year=$y170 AND ms.month=$m170")->fetchColumn();
    $qEmp170 = (int)$db->query("SELECT COALESCE(SUM(ms.family_allowance_lbp),0) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0" . cnssFamilyPaidTypeSql('e.') . " AND ms.school_id=$sid170 AND ms.year=$y170 AND ms.month=$m170")->fetchColumn();
    $qTit170 = (int)$db->query("SELECT COALESCE(SUM(ms.family_allowance_lbp),0) FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0 AND e.employee_type='enseignant_titulaire' AND ms.school_id=$sid170 AND ms.year=$y170 AND ms.month=$m170")->fetchColumn();
    $c170("db:$sid170/$m170-$y170 emp=$qEmp170 all=$qAll170", $qTit170 > 0 && $qEmp170 < $qAll170 && $qEmp170 + $qTit170 <= $qAll170);
    $hC170 = renderPage('pages/official_forms.php', ['form' => 'cnss_contrib_monthly', 'month' => $m170, 'year' => $y170], [], [$sid170]);
    $c170('render-contrib', $noFatal($hC170) && strpos($hC170, formatLBP($qEmp170, false)) !== false);
    $hN170 = renderPage('pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => $m170, 'year' => $y170], [], [$sid170]);
    $c170('render-nominative', $noFatal($hN170) && strpos($hN170, 'المتوجب للصندوق') !== false);
} else { $why170[] = 'no titulaire with stored family allowance (db part skipped)'; }
check('🏥👨‍👩‍👧 تصاريح الضمان (2026-09-25): «التعويضات العائلية المدفوعة» = موظفو قانون العمل فقط (الشهري/الفصلي شاشة + إكسل + الكشف الاسمي) — تعويض أستاذ الملاك يبقى بكل التقارير الأخرى — مصدر واحد + تجربة بالقاعدة', $ok170, implode(' · ', $why170) ?: 'ok');

/* =====================================================================
 * 171) 🏫 التصاريح المؤسّسية مع عدة مدارس مختارة (2026-09-25 «اخترت مدرستين وكبست تصريح الضمان الشهري عم بينطّ على صفحة
 *      التصاريح الرسمية» ثم «بدي اختار أو وحدة لحالها أو مجموعة»): لا طرد — عدة مدارس = التصريح **للمجموعة معاً**
 *      (institutionGroupSchool: الاسم = صاحب العمل الموحّد إن تشاركت رقم الضمان، وإلا الأسماء + تنبيه)، والعدد/المجاميع
 *      = مجموع المدارس؛ &school_id= يحصر الطلب بمدرسة واحدة من ضمن المختارة (msa_school_override) والاختيار العام لا يتغيّر.
 * =================================================================== */
$ok171 = true; $why171 = [];
$c171 = function (string $what, bool $ok) use (&$ok171, &$why171) { if (!$ok) { $ok171 = false; $why171[] = $what; } };
$fn171 = (string)file_get_contents($PROJ . '/includes/functions.php');
$c171('src-fn', strpos($fn171, "if (!empty(\$GLOBALS['msa_school_override'])) return") !== false && strpos($fn171, 'function institutionSchoolPick(): ?array') !== false
    && strpos($fn171, 'function institutionGroupSchool(): ?array') !== false && strpos($fn171, 'function institutionGroupBadgeHtml(') !== false);
$of171 = (string)file_get_contents($PROJ . '/pages/official_forms.php');
$c171('src-forms', strpos($of171, '$school = institutionSchoolPick();') !== false && strpos($of171, '$school = institutionGroupSchool();') !== false
    && strpos($of171, 'institutionSchoolChooserHtml') === false && strpos($of171, 'اختر المدرسة من الأعلى أولاً') === false
    && substr_count($of171, "(\$ofSchoolPicked ? '&school_id=' . (int)\$school['id'] : '')") >= 3);
$ox171 = (string)file_get_contents($PROJ . '/pages/official_export.php');
$c171('src-export', strpos($ox171, 'institutionSchoolPick() ?: institutionGroupSchool()') !== false && substr_count($ox171, "(!empty(\$s0['_group_ids'])) ? \$s0") === 2);
// تجربة فعلية: مدرستان بنفس رقم الضمان (3 و5): الكشف الاسمي للمجموعة = مجموع عدد مضموني كلٍّ لحالها + اسم صاحب العمل الموحّد
$cnt171 = function (array $ids) use (&$noFatal) {
    $h = renderPage('pages/official_forms.php', ['form' => 'cnss_nominative_monthly', 'month' => 10, 'year' => 2025], [], $ids);
    return preg_match('/عدد المضمونين: (\d+)/u', $h, $m) ? [(int)$m[1], $h] : [-1, $h];
};
[$n3, ] = $cnt171([3]); [$n5, ] = $cnt171([5]); [$n35, $h35] = $cnt171([3, 5]);
$c171("group-nominative=$n35/" . ($n3 + $n5), $n3 > 0 && $n5 > 0 && $n35 === $n3 + $n5 && strpos($h35, 'التصريح لمجموعة المدارس') !== false
    && strpos($h35, 'الراهبات المخلصيات لسيدة البشارة') !== false && strpos($h35, '⚠️ المدارس المختارة بأرقام ضمان مختلفة') === false);
$hM = renderPage('pages/official_forms.php', ['form' => 'cnss_contrib_monthly', 'month' => 10, 'year' => 2025], [], [3, 5]);
$c171('group-contrib', $noFatal($hM) && strpos($hM, 'التصريح لمجموعة المدارس') !== false && strpos($hM, 'ppExportArea') !== false);
// أرقام ضمان مختلفة (2 مكسيموس 22-82-745 + 3): التصريح يطلع مع تنبيه
$hX = renderPage('pages/official_forms.php', ['form' => 'cnss_contrib_monthly', 'month' => 10, 'year' => 2025], [], [2, 3]);
$c171('group-mixed-warning', $noFatal($hX) && strpos($hX, '⚠️ المدارس المختارة بأرقام ضمان مختلفة') !== false);
// &school_id= يحصر بمدرسة واحدة من ضمن المختارة (شارة + رابط الإكسل)، وخارج النطاق = المجموعة
$hB = renderPage('pages/official_forms.php', ['form' => 'cnss_contrib_monthly', 'month' => 10, 'year' => 2025, 'school_id' => 5], [], [3, 5]);
$c171('picked-one', $noFatal($hB) && strpos($hB, 'التصريح لمدرسة') !== false && strpos($hB, 'التصريح لمجموعة') === false && strpos($hB, 'official_export.php?form=cnss_contrib_monthly&month=10&year=2025&school_id=5') !== false);
$hC = renderPage('pages/official_forms.php', ['form' => 'cnss_contrib_monthly', 'month' => 10, 'year' => 2025, 'school_id' => 7], [], [3, 5]);
$c171('out-of-scope=group', $noFatal($hC) && strpos($hC, 'التصريح لمجموعة المدارس') !== false);
$hD = renderPage('pages/official_forms.php', ['form' => 'tax_r10'], [], [3, 5]);
$c171('r10-group', $noFatal($hD) && strpos($hD, 'التصريح لمجموعة المدارس') !== false);
check('🏫 التصاريح المؤسّسية مع عدة مدارس (2026-09-25): وحدة لحالها أو مجموعة معاً — المجموعة = مجموع المدارس + صاحب العمل الموحّد + تنبيه عند اختلاف الأرقام + school_id يحصر — تجربة فعلية', $ok171, implode(' · ', $why171) ?: 'ok');


/* =====================================================================
 * 172) 🏫 الصفحات الجماعية بعدة مدارس (2026-09-26 «بدي بصفحة التعويضات اقدر اختار كمان عدة مدارس»):
 *      التعويض العائلي + المكافآت/النقل = مدرسة واحدة أو مجموعة معاً أو الكل بخانات تشييك — مصدر واحد pageSchoolScope
 *      (sch_all / sch[] + القديم sch=all/sch=id) — بلا طرد لمدرسة واحدة (requireSchoolSelected أُزيل من الجماعي)
 * =================================================================== */
$ok172 = true; $why172 = [];
$c172 = function (string $n, bool $ok) use (&$ok172, &$why172) { if (!$ok) { $ok172 = false; $why172[] = $n; } };
$fa172 = (string)file_get_contents($PROJ . '/pages/family_allowances.php'); $ba172 = (string)file_get_contents($PROJ . '/pages/bulk_allowances.php');
$c172('single-source', function_exists('pageSchoolScope') && function_exists('pageSchoolScopeSql') && function_exists('pageSchoolPickerHtml')
    && strpos($fa172, 'pageSchoolPickerHtml(') !== false && strpos($ba172, 'pageSchoolPickerHtml(') !== false
    && strpos($fa172, '<select name="sch"') === false && strpos($ba172, '<select name="sch"') === false && strpos($ba172, 'requireSchoolSelected') === false);
$sy172 = currentSchoolYear(); [$yf172, $yp172] = yearEmploymentFilter($sy172, 'e.');
$two172 = $db->prepare("SELECT e.school_id, COUNT(*) n FROM employees e JOIN schools s ON s.id = e.school_id AND s.is_active = 1 WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire'" . $yf172 . " GROUP BY e.school_id HAVING n > 0 ORDER BY n DESC LIMIT 2");
$two172->execute($yp172); $two172 = $two172->fetchAll(PDO::FETCH_KEY_PAIR);
if (count($two172) === 2) {
    [$sa172, $sb172] = array_keys($two172);
    $rowsOf = fn(string $h) => preg_match_all('/<tr class="(?:na)?" data-id="\d+" data-cat="[a-z]+" data-school="\d+" data-cur="\d+">/', $h); // ⚡ الصفوف الظاهرة فقط (كل الفئات محمَّلة، غير المشيّكة مخفيّة)
    $hA = renderPage('pages/family_allowances.php', ['sch' => [$sa172], 'sy' => $sy172, 'cat' => ['titulaire']], []);
    $hAB = renderPage('pages/family_allowances.php', ['sch' => [$sa172, $sb172], 'sy' => $sy172, 'cat' => ['titulaire']], []);
    $hAll = renderPage('pages/family_allowances.php', ['sch_all' => 1, 'sy' => $sy172, 'cat' => ['titulaire']], []);
    $c172('fa-one-school', $noFatal($hA) && $rowsOf($hA) === (int)$two172[$sa172] && strpos($hA, 'École / المدرسة</th>') === false
        && preg_match('/name="sch\[\]" value="' . $sa172 . '" checked/', $hA) === 1 && strpos($hA, 'name="sch_all" value="1" checked') === false);
    $c172('fa-two-schools=sum', $noFatal($hAB) && $rowsOf($hAB) === (int)$two172[$sa172] + (int)$two172[$sb172] && strpos($hAB, 'École / المدرسة</th>') !== false
        && substr_count($hAB, '<input type="hidden" name="sch[]" value="') === 2 && preg_match('/النطاق: [^·]+ \+ [^·]+ ·/u', $hAB) === 1);
    $c172('fa-all', $noFatal($hAll) && strpos($hAll, 'name="sch_all" value="1" checked') !== false && strpos($hAll, 'النطاق: كل المدارس') !== false && $rowsOf($hAll) >= $rowsOf($hAB));
    $hB1 = renderPage('pages/bulk_allowances.php', ['sch' => [$sa172], 'sy' => $sy172], []);
    $hB2 = renderPage('pages/bulk_allowances.php', ['sch' => [$sa172, $sb172], 'sy' => $sy172], []);
    $c172('ba-one-school', $noFatal($hB1) && strpos($hB1, 'excel_salaries.php?sch=' . $sa172) !== false && strpos($hB1, 'id="baOnePct"') !== false
        && preg_match('/name="sch\[\]" value="' . $sa172 . '" checked/', $hB1) === 1);
    $c172('ba-two-schools', $noFatal($hB2) && strpos($hB2, 'excel_salaries.php?sch=') === false && strpos($hB2, 'id="baOnePct"') !== false
        && preg_match('/name="sch\[\]" value="' . $sb172 . '" checked/', $hB2) === 1 && strpos($hB2, '<input type="hidden" name="sch[]" value="' . $sa172 . '"><input type="hidden" name="sch[]" value="' . $sb172 . '">') !== false
        && preg_match('/لكل فئة رقمها[^<]*—[^<]* \+ [^<]* — /u', $hB2) === 1);
} else $c172('need-two-schools-data', false);
$js172 = (string)file_get_contents($PROJ . '/assets/js/app.js');
$c172('debounced-submit', strpos($js172, 'window.msaSubmitSoon = function (form, ms)') !== false && strpos($js172, 'msaBusyOverlay') !== false
    && strpos($fa172, 'onchange="window.faApplyLiveFilter&&faApplyLiveFilter()"') !== false && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), "\$sub = '(window.msaSubmitSoon||function(f){f.submit()})(this.form)';") !== false // ⚡ الفئة محلّية؛ المدارس بالإرسال المؤجَّل
    && strpos($fa172, "getElementById('faFilter').submit()") === false); // ☑️⏳ «مشيّك على الموظفين ولسا مبيّن الملاك»: الكبسات المتتالية تُجمَع بإرسال واحد
$c172('sync-forms', strpos($js172, 'window.msaSyncForms = function ()') !== false && strpos($js172, "addEventListener('pageshow', function () { setTimeout(window.msaSyncForms, 50); })") !== false
    && strpos($fa172, 'data-msa-sync-name="cat[]"') === false && strpos($fa172, 'id="faFilter" autocomplete="off"') !== false // ⚡ الفئة محلّية بلا مزامنة سيرفر
    && strpos($hAB, 'data-msa-sync-name="sch[]" data-msa-sync-values="' . $sa172 . ',' . $sb172 . '"') !== false && strpos($hAll, 'data-msa-sync-name="sch_all" data-msa-sync-values="1"') !== false
    && strpos($hA, 'onchange="window.faApplyLiveFilter&&faApplyLiveFilter()"') !== false && strpos($hA, '<input type="checkbox" autocomplete="off" name="cat[]"') !== false
    && strpos(empTypeCheckboxes(empTypeSelection(['type' => ['employe'], 'type_set' => 1], 'type'), true, 'type'), 'data-msa-sync-name="type[]" data-msa-sync-values="employe"') !== false); // 🔁 الخانات المعروضة = الصفحة المحسوبة وإلا إعادة تحميل
$c172('live-filter', strpos($fa172, 'window.faApplyLiveFilter = function ()') !== false && strpos($fa172, "addEventListener('pageshow', window.faApplyLiveFilter)") !== false
    && preg_match('/<tr class="" data-id="\d+" data-cat="titulaire" data-school="' . $sa172 . '" data-cur="\d+">/', $hA) === 1 && strpos($hA, 'id="faShown"') !== false
    && preg_match('/data-cat="employe" data-school="\d+" data-cur="\d+" style="display:none">/', $hA) === 1 && strpos($fa172, 'e.employee_type IN (') === false && strpos($fa172, 'id="faCatHidden"') !== false); // ☑️⚡ كل الفئات محمَّلة، غير المشيّكة مخفيّة، التبديل محلّي بلا إرسال
check('🏫 الصفحات الجماعية بعدة مدارس (2026-09-26): التعويض العائلي + المكافآت/النقل = مدرسة واحدة أو مجموعة معاً أو الكل بخانات تشييك (pageSchoolScope) — المجموعة = مجموع المدارس + عمود المدرسة + بلا طرد', $ok172, implode(' · ', $why172) ?: 'ok');

/* =====================================================================
 * 173) ⚖️ موظف قانون العمل بعد 64 لا يخضع لتعويض نهاية الخدمة (2026-09-26 «صار عمره 64 وبعده يشتغل ما بيخضع…
 *      وبتصريح الضمان أو مطرح ما في عدد الموظفين الخاضعين لنهاية الخدمة اللي مش خاضع ما لازم تعدّه»):
 *      المحرّك تلقائي (بلا مفتاح) للموظف فقط · الملاك يبقى بقرار الإبقاء · الشفاء يعيد حساب المخزّن · العدّ بالتسوية = من له أجور تحت الفرع
 * =================================================================== */
$ok173 = true; $why173 = [];
$c173 = function (string $n, bool $ok) use (&$ok173, &$why173) { if (!$ok) { $ok173 = false; $why173[] = $n; } };
$pc173 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$c173('engine-code', function_exists('employeExemptEos64') && strpos($pc173, "\$past64Employe = employeExemptEos64(\$emp, (int)\$this->month, (int)\$this->year);") !== false
    && strpos($pc173, "\$emp['employee_type'] === 'enseignant_titulaire' && !empty(\$emp['keep_working_past_64'])) \$past64Titulaire = true;") !== false
    && strpos($pc173, "(\$isEmploye && !employeExemptEos64(\$r, \$m, \$y))") !== false
    && employeExemptEos64(['employee_type' => 'employe', 'birth_date' => '1962-06-15'], 6, 2026) && !employeExemptEos64(['employee_type' => 'employe', 'birth_date' => '1962-06-15'], 5, 2026)
    && !employeExemptEos64(['employee_type' => 'enseignant_titulaire', 'birth_date' => '1950-01-01'], 6, 2026));
$c173('heal+count-code', function_exists('healEmploye64EndOfService') && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healEmploye64EndOfService();') !== false
    && strpos((string)file_get_contents($PROJ . '/pages/official_export.php'), "\$ag['D'] += ((\$p['worker'] && \$p['O'] > 0) ? 1 : 0);") !== false
    && strpos($fnAll173 = (string)file_get_contents($PROJ . '/includes/functions.php'), "\$tot['workers'] += (\$p['worker'] && \$p['O'] > 0) ? 1 : 0;") !== false
    && strpos((string)file_get_contents($PROJ . '/pages/official_forms.php'), 'خاضع لنهاية الخدمة — الموظف بعد 64 لا يُعدّ') !== false);
// المحرّك: موظف بلغ 64 بنهاية الشهر ⇒ ٨.٥٪ = 0 بلا مفتاح؛ وشهر قبل بلوغه (إن وُجد بالقاعدة) ⇒ > 0
// تجربة فعلية مع ترجيع: موظف بمحرّك حيّ (آخر شهر مخزّن له عائلي > 0) — نبدّل تاريخ ولادته مؤقّتاً ليبلغ 64 بذلك الشهر ⇒ ٨.٥٪ = 0
//    وبـ63 ⇒ > 0، ثم نعيد تاريخه حرفياً (المحرّك يقرأ الملف عند الإنشاء)
$e173 = null; $after = $before = null;
$cand173 = $db->query("SELECT e.id, e.birth_date, ms.month, ms.year FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
    WHERE e.is_deleted = 0 AND e.employee_type = 'employe' AND e.cnss_subject = 1 AND COALESCE(e.keep_working_past_64, 0) = 0
      AND ms.school_family_comp_6_lbp > 0 AND ms.school_end_of_service_8_5_lbp > 0 AND ms.is_paid = 0
      AND (e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
    ORDER BY ms.year DESC, ms.month DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cand173) {
    $id173 = (int)$cand173['id']; $m173 = (int)$cand173['month']; $y173 = (int)$cand173['year']; $orig173 = $cand173['birth_date'];
    $setBd = $db->prepare("UPDATE employees SET birth_date = ? WHERE id = ?");
    try {
        $setBd->execute([sprintf('%04d-%02d-01', $y173 - 64, $m173), $id173]);
        $after = (new PayrollCalculator($id173, $m173, $y173))->calculate();   // بلغ 64 هذا الشهر
        $setBd->execute([sprintf('%04d-%02d-01', $y173 - 63, $m173), $id173]);
        $before = (new PayrollCalculator($id173, $m173, $y173))->calculate();  // 63 فقط
    } finally { $setBd->execute([$orig173, $id173]); }
    $e173 = $cand173;
    $c173('engine-64=0', $after && (int)$after['school_end_of_service_8_5_lbp'] === 0 && (int)$after['school_family_comp_6_lbp'] > 0 && (int)$after['cnss_amount_lbp'] > 0);
    $c173('engine-63>0', $before && (int)$before['school_end_of_service_8_5_lbp'] > 0);
    $c173('birth-restored', $db->query("SELECT birth_date FROM employees WHERE id = $id173")->fetchColumn() === $orig173);
    // بعد الشفاء: لا شهر مخزّن (غير محميّ) لموظف بلغ 64 يحمل ٨.٥٪
    healEmploye64EndOfService(true);
    $left173 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND e.employee_type = 'employe' AND e.birth_date > '1900-01-01' AND ms.school_end_of_service_8_5_lbp > 0
          AND TIMESTAMPDIFF(YEAR, e.birth_date, LAST_DAY(CONCAT(ms.year, '-', LPAD(ms.month, 2, '0'), '-01'))) >= 64
          AND NOT (ms.is_paid = 1 AND ms.school_year < " . $db->quote(currentSchoolYear()) . ")")->fetchColumn();
    $c173('heal-cleared', $left173 === 0);
} else $c173('no-employe-64-data', false);
// الملاك بلا قرار إبقاء: صندوق التعويضات يبقى (لا يتأثّر بالقاعدة الجديدة)
$t173 = $db->query("SELECT e.id, e.birth_date FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
    WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND e.eoc_subject = 1 AND COALESCE(e.keep_working_past_64, 0) = 0
      AND e.birth_date > '1900-01-01' AND ms.base_plus_echelon_lbp > 0 AND ms.caisse_amount_lbp > 0
      AND TIMESTAMPDIFF(YEAR, e.birth_date, LAST_DAY(CONCAT(ms.year, '-', LPAD(ms.month, 2, '0'), '-01'))) >= 64
    ORDER BY ms.year DESC, ms.month DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($t173) { [$ty173, $tm173] = array_map('intval', explode('-', substr($t173['birth_date'], 0, 7))); $tr = (new PayrollCalculator((int)$t173['id'], $tm173, $ty173 + 64))->calculate(); $c173('titulaire-unchanged', (int)$tr['caisse_amount_lbp'] > 0); }
check('⚖️ موظف قانون العمل بعد 64 لا يخضع لنهاية الخدمة (2026-09-26): المحرّك تلقائي بلا مفتاح (٨.٥٪ = 0 من شهر بلوغه، والعائلي ٦٪ يبقى) + الملاك بقرار الإبقاء كما كان + الشفاء يصفّر المخزّن غير المحميّ + العدّ بالتسوية = الخاضعون فعلاً', $ok173, implode(' · ', $why173) ?: 'ok');

// 🖨️ (2026-09-26 «عم حاول أطبع البطاقات السنوية PDF عم بيقلي طلب غير صالح»): الفئة بخانات type[]= تحمل [ ] ⇒ القائمة البيضاء لهدف الطباعة/الإرسال تقبلهما
check('🖨️ PDF/إرسال: هدف فيه type[]=… مقبول (2026-09-26)', strpos((string)file_get_contents($PROJ . '/pages/print_pdf.php'), "[A-Za-z0-9_./?&=%\-+:\[\]]+") !== false
    && strpos((string)file_get_contents($PROJ . '/pages/send_attestation.php'), "[A-Za-z0-9_./?&=%\-+:\[\]]+") !== false
    && preg_match('#^[A-Za-z0-9_./?&=%\-+:\[\]]+$#', 'pages/annual_slip.php?action=print_all&type_set=1&type[]=enseignant_titulaire&school_year=2026-2027') === 1, 'ok');

/* =====================================================================
 * 174) 🏦 الداخل للملاك يرث «الصندوق يشمل الأجر الإضافي» كرفاقه المتقاضين إضافياً (2026-09-26 ريتا طنوس/النجاة: 6٪ على الأساس بس)
 *      + شفاء تلقائي للملاك الحاليين بإضافي ومفتاح مطفأ خلافاً لرفاقهم
 * =================================================================== */
$ok174 = true; $why174 = [];
$c174 = function (string $n, bool $ok) use (&$ok174, &$why174) { if (!$ok) { $ok174 = false; $why174[] = $n; } };
require_once $PROJ . '/includes/cadre_due.php';
$c174('code', function_exists('healCadreEocIncludesExtra') && strpos((string)file_get_contents($PROJ . '/includes/header.php'), 'healCadreEocIncludesExtra();') !== false
    && strpos((string)file_get_contents($PROJ . '/includes/cadre_due.php'), "preg_match('/includes_(extra|prime_aide)\$/', \$f) && \$withExtra") !== false);
// القالب: بكل مدرسة فيها ملاك يتقاضون إضافياً وأكثريتهم «يشمل» ⇒ القالب «يشمل» حتى لو أكثرية كل الملاك (بلا إضافي) مطفأة
$schools174 = $db->query("SELECT e.school_id, SUM(e.eoc_includes_extra = 1) yes, COUNT(*) n FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire'
    AND EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = e.id AND b.bonus_type = 'prime_fixe' AND b.is_active = 1 AND b.school_year >= " . $db->quote(currentSchoolYear()) . ") GROUP BY e.school_id HAVING yes * 2 > n")->fetchAll(PDO::FETCH_ASSOC);
$tplOk174 = count($schools174) > 0;
foreach ($schools174 as $sr) { $t = cadreDueTemplate($db, (int)$sr['school_id']); if ((int)$t['eoc_includes_extra'] !== 1) { $tplOk174 = false; $why174[] = 'tpl-school-' . $sr['school_id']; } }
$c174('template-includes-extra', $tplOk174);
// الشفاء: بعده لا ملاك فاعل بإضافي مخزّن بسنة حالية/مفتوحة ومفتاح مطفأ في مدرسة أكثريتها «يشمل» (ريتا طنوس 1776 محلياً: 6٪ × 55,525,000)
healCadreEocIncludesExtra(true);
$left174 = 0;
foreach ($db->query("SELECT DISTINCT e.id, e.school_id FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
    WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire' AND COALESCE(e.eoc_subject, 1) = 1 AND COALESCE(e.eoc_includes_extra, 0) = 0
      AND ms.school_year >= " . $db->quote(currentSchoolYear()) . " AND ms.prime_fixe_lbp > 0 AND ms.caisse_amount_lbp > 0 AND " . leftDateSql('e.') . " = '9999-12-31'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (in_array((int)$r['school_id'], array_map('intval', array_column($schools174, 'school_id')), true)) $left174++;
}
$c174('heal-cleared', $left174 === 0);
$rita174 = $db->query("SELECT eoc_includes_extra ex, (SELECT caisse_amount_lbp FROM monthly_salaries WHERE employee_id = 1776 AND year = 2026 AND month = 10) c FROM employees WHERE id = 1776 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
if ($rita174) $c174('rita-oct-2026', (int)$rita174['ex'] === 1 && (int)$rita174['c'] === (int)round(55525000 * 0.06));
$c174('idempotent', healCadreEocIncludesExtra(true) === 0);
check('🏦 الداخل للملاك يرث «الصندوق يشمل الأجر الإضافي» كرفاقه المتقاضين إضافياً + شفاء الملاك الحاليين (2026-09-26 ريتا طنوس: 6٪ على الأساس + الإضافي)', $ok174, implode(' · ', $why174) ?: 'ok');

/* =====================================================================
 * 175) 🎓 المرشّح للملاك بعد سنتين يظهر للقرار حتى لو رواتب سنته الأولى غير مخزّنة (بند إضافي/نقل لها يكفي) — باميلا نضّور (2026-09-26)
 * =================================================================== */
$cd175 = (string)file_get_contents($PROJ . '/includes/cadre_due.php');
$ok175 = strpos($cd175, "OR e.id IN (SELECT employee_id FROM employee_bonuses WHERE school_year = ? AND bonus_type IN ('prime_fixe','transport_complement','transport_daily'))") !== false
    && strpos($cd175, "\$p = [\$cut, \$yearStart, \$syA, \$syA, \$syB];") !== false;
// باميلا (1802): رُسِّمت بـ2026-2027 (قرار approved) وملاكها كرفاقها: صندوق يشمل الإضافي، إضافي 60٪، ت1 2026 = 6٪ × (الأساس + الإضافي)
$pam175 = $db->query("SELECT e.employee_type, e.titularization_date, e.eoc_includes_extra, (SELECT decision FROM compliance_decisions d WHERE d.rule_key = 'cadre_due' AND d.employee_id = 1802 AND d.school_year = '2026-2027' ORDER BY d.id DESC LIMIT 1) dec,
    (SELECT caisse_amount_lbp FROM monthly_salaries WHERE employee_id = 1802 AND year = 2026 AND month = 10) caisse, (SELECT base_plus_echelon_lbp + prime_fixe_lbp FROM monthly_salaries WHERE employee_id = 1802 AND year = 2026 AND month = 10) gross
    FROM employees e WHERE e.id = 1802 AND e.is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
if ($pam175) $ok175 = $ok175 && $pam175['employee_type'] === 'enseignant_titulaire' && $pam175['titularization_date'] === '2026-10-01' && (int)$pam175['eoc_includes_extra'] === 1 && $pam175['dec'] === 'approved'
    && (int)$pam175['caisse'] === (int)round((int)$pam175['gross'] * 0.06) && (int)$pam175['gross'] > 50000000;
check('🎓 المرشّح للملاك بعد سنتين يظهر للقرار ولو رواتب سنته الأولى غير مخزّنة (بند لها يكفي) + باميلا نضّور رُسِّمت 1/10/2026 كرفاقها (2026-09-26)', $ok175, $pam175 ? json_encode($pam175) : 'no-pamela');

/* =====================================================================
 * 176) 🏆 إعادة بناء سجلّ الدرجات بلا تاريخ صريح تحفظ درجات السنة الدراسية المستقبلية (4+4+2 بكانون الثاني) والدرجة الحالية لغاية اليوم
 *      (2026-09-26 ريتا طنوس: كانون الثاني 2027 صار 15 بدل 19 بعد إعادة البناء) — تجربة فعلية مع ترجيع
 * =================================================================== */
$ok176 = true; $why176 = [];
$c176 = function (string $n, bool $ok) use (&$ok176, &$why176) { if (!$ok) { $ok176 = false; $why176[] = $n; } };
$pc176 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$c176('code', strpos($pc176, "if (\$eoy > \$todayTs) { \$todayTs = \$eoy; \$horizonExtended = true; }") !== false && strpos($pc176, "if (\$horizonExtended) {") !== false);
[$y176a, $y176b] = array_map('intval', explode('-', currentSchoolYear()));
$t176 = $db->query("SELECT e.id FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND e.diploma = 'ijaza_taalimiya' AND e.titularization_date = '$y176a-10-01'
    AND EXISTS (SELECT 1 FROM employee_grade_history h WHERE h.employee_id = e.id AND h.change_date = '$y176b-01-01' AND h.notes LIKE '%4+4+2%')
    AND NOT EXISTS (SELECT 1 FROM employee_grade_history h WHERE h.employee_id = e.id AND h.user_edited = 1) ORDER BY e.id LIMIT 1")->fetchColumn();
if ($t176 && date('Y-m-d') < "$y176b-01-01") {
    $t176 = (int)$t176;
    $bk176 = $db->query("SELECT * FROM employee_grade_history WHERE employee_id = $t176 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $cg176 = (float)$db->query("SELECT current_grade FROM employees WHERE id = $t176")->fetchColumn();
    try {
        buildLegalGradeHistory($t176);  // بلا تاريخ ⇒ الأفق نهاية السنة الدراسية
        $after176 = $db->query("SELECT MAX(grade_after) mx, SUM(change_date = '$y176b-01-01') jan, (SELECT current_grade FROM employees WHERE id = $t176) cg FROM employee_grade_history WHERE employee_id = $t176")->fetch(PDO::FETCH_ASSOC);
        $c176('future-rows-kept', (int)$after176['jan'] === 4 && (float)$after176['mx'] === (float)($bk176 ? max(array_column($bk176, 'grade_after')) : 0));
        $c176('current-grade-as-of-today', abs((float)$after176['cg'] - $cg176) < 0.01);
        $eng176 = (new PayrollCalculator($t176, 1, $y176b))->calculate();
        $c176('engine-jan-uses-future-grade', (float)$eng176['grade_at_month'] === (float)$after176['mx'] && (int)$eng176['echelon_value_lbp'] > 0);
    } catch (Throwable $e) { $c176('exception:' . $e->getMessage(), false); }
    // ترجيع حرفي
    $db->exec("DELETE FROM employee_grade_history WHERE employee_id = $t176");
    if ($bk176) { $cols = array_keys($bk176[0]); $ins = $db->prepare("INSERT INTO employee_grade_history (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")"); foreach ($bk176 as $r) $ins->execute(array_values($r)); }
    $db->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([$cg176, $t176]);
    $c176('restored', $db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id = $t176")->fetchColumn() == count($bk176));
} else $c176('no-sample-or-past-january', $t176 ? true : false);
check('🏆 إعادة بناء سجلّ الدرجات تحفظ درجات السنة المستقبلية (4+4+2 كانون الثاني) والدرجة الحالية لغاية اليوم (2026-09-26 ريتا طنوس) — تجربة فعلية مع ترجيع', $ok176, implode(' · ', $why176) ?: 'ok');

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
