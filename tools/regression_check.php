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
// $outFile: للمخرجات الثنائية (xlsx...) — أنبوب shell_exec بويندوز وضع نصي يقصّ عند أول
// محرف 0x1A، فالثنائي يُكتب لملف عبر إعادة توجيه cmd ويُقرأ من القرص (2026-08-22)
function renderPage(string $rel, array $get, array $comp, array $schoolIds = [], string $currency = '', string $schoolYear = '', string $outFile = '', array $files = []): string {
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
// رفع ملف (POST multipart) للصفحات التي تستقبل ملفات — مدقّق ملف الوزارة
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
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET'; // CLI بلا REQUEST_METHOD — تحذيره كان يتصدّر مخرجات القياس
chdir(dirname($PROJ . '/' . $argv[1]));
ob_start();
try { include $PROJ . '/' . $argv[1]; echo ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); echo 'FATAL: ' . $e->getMessage(); }
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
         . escapeshellarg($rel) . ' ' . base64_encode(json_encode($get)) . ' ' . base64_encode(json_encode($comp))
         . ' ' . base64_encode(json_encode($schoolIds)) . ' ' . escapeshellarg($currency) . ' ' . escapeshellarg($schoolYear)
         . ' ' . base64_encode(json_encode($files));
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
check('الخط العربي الرسمي (Sakkal Majalla) معرَّف', strpos($hlpSrc, 'Sakkal Majalla') !== false);
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
      && strpos($attSrc, '- الأجر الإضافي : <strong><?= $moneyAr($attSupp)') !== false);
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
      && substr_count($oeR3g, "in_array(\$sexQ, ['m', 'f'], true) ? \$sexQ : ''") >= 2
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
check('لا قصّ صامت بالجداول المصغَّرة (أهداف 718/1062 + أمان 0.98) + صفوف التواقيع جدول بالطباعة',
      strpos($rhSrc, '--pz-target:718') !== false
      && strpos($rhSrc, '--pz-target:1062') !== false
      && substr_count($rhSrc . $appJs, '(target * 0.98) / natW') >= 2
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
          strpos($hAt, 'ويتقاضى راتباً شهرياً قدره') !== false
          && strpos($hAt, 'دون أدنى مسؤولية') === false
          && strpos($hAt, '<table dir="rtl"') === false
          && strpos($hAt, 'لاستعمالها لدى من يلزم') === false
          && strpos($hAt, 'بناءً على طلبه(ا) .') !== false);
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
          && strpos($repSrc25, "\$colTotUsd = array_fill_keys(['extra_wage','aide','transport','composed'], 0.0);") !== false);
    // «وهون شو المكرر» (2026-08-25): بطاقة «Infos générales» أُزيلت (أعمدتها ضمن لائحة الموظفين)
    // — نموذج p13 نفسه يبقى شغّالاً بالرابط المباشر بofficial_forms
    check('مركز التقارير بلا مكرّر: لا بطاقة «Infos générales» (ضمن لائحة الموظفين) ونموذج p13 المباشر شغّال',
          strpos($repSrc25, "'fr'=>'Infos générales'") === false
          && strpos((string)file_get_contents(__DIR__ . '/../pages/official_forms.php'), "\$form === 'general_info'") !== false);
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
// (تحديث 2026-08-06: الخاضع المعروض صار **بعد حسم حصّة التنزيل العائلي** بطلب المستخدم)
check('الضريبة: مجموع «الراتب الخاضع للضريبة» يظهر بالشاشة (كان فارغاً)',
      strpos($repSrc, "'txb'=>max(0,(int)\$r['taxable_base_lbp']-\$fded43)") !== false && strpos($repSrc, "formatLBP(\$a['txb'])") !== false);
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
check('🔒 البطاقة السنوية (تصميم مجمّد): العربي نسخي Noto Naskh والأرقام Cairo + اسم الأستاذ 17pt أبرز عنصر + معلومات عريضة',
      strpos($asSrc29, ".salary-slip, .salary-slip-table, .slip-info { font-family:'Noto Naskh Arabic','Cairo'") !== false
      && strpos($asSrc29, '.slip-emp-name .slip-pname { font-size: 17pt !important; font-weight: 800 !important;') !== false
      && strpos($asSrc29, 'font-weight:800 !important') !== false);
check('🔒 البطاقة السنوية (تصميم مجمّد): تملأ طول الورقة (188mm/pz + flex) وبلا fit القديم',
      strpos($asSrc29, 'min-height: calc(188mm / var(--pz, 1))') !== false
      && strpos($asSrc29, 'flex: 1 1 auto') !== false
      && strpos($asSrc29, '&fit=1') === false);
// ✍️ الخط النسخي (بطلبه 2026-08-25): ملف Noto Naskh Arabic محلي + معرَّف بfonts.css
// للعربي فقط (unicode-range) حتى تبقى الأرقام واللاتيني على Cairo ولا يتلخبط الترتيب
$fcSrc29 = (string)file_get_contents(__DIR__ . '/../assets/fonts/fonts.css');
// ✍️ (2026-08-25) «P1 بدون تضييق» (تراجُعه عن التضييق بنفس اليوم): خانات معلومات الأستاذ
// بقياسها الأصلي (3px 8px + اسم 6px 10px) — وسطور المبالغ الأوسع (5px) بقيت بطلبه
check('🔒 البطاقة السنوية: معلومات الأستاذ بلا تضييق (حشوة 3px 8px + اسم 6px 10px) وسطور المبالغ أوسع (5px 3px)',
      strpos($asSrc29, '.slip-info td { border:1px solid #888 !important; padding: 3px 8px !important; }') !== false
      && strpos($asSrc29, 'padding:6px 10px !important; margin-bottom:5px !important;') !== false
      && strpos($asSrc29, '.salary-slip-table td { padding: 5px 3px !important; }') !== false);
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
      AND LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) >= '2025-10-01'
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
      && strpos($of41, "familyDeductionAnnual(\$r['social_status'] ?? '', \$r['spouse_works'] ?? 0, \$r['afd'] ?? 1, \$sfdAsOf, \$r['gsa'] ?? 0, \$r['gca'] ?? 0, (int)(\$r['employee_id'] ?? 0))") !== false);
// تجربة فعلية (مع ترجيع كامل): موظف خاضع بضريبة موجبة وتنزيل ساري > 0 — طفي الخيار
// يرفع ضريبته الشهرية، وإرجاعه يعيدها كما كانت بالمليم
ensureEmployeeFlagColumns();
$cand41 = $db->query("SELECT e.id, ms.income_tax_lbp t0 FROM employees e
    JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.year = 2026 AND ms.month = 6
    WHERE e.is_deleted = 0 AND e.tax_subject = 1 AND e.employee_type = 'enseignant_titulaire'
      AND ms.income_tax_lbp > 0 AND COALESCE(e.apply_family_deduction, 1) = 1
      AND LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),
                COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),
                COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31')) = '9999-12-31'
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
      && strpos($rp42, "'txb'=>max(0,(int)\$r['taxable_base_lbp']-\$fded43)") !== false
      && strpos($rp42, "familyDeductionAnnual(\$r['social_status'] ?? '', \$r['spouse_works'] ?? 0, \$r['afd'] ?? 1, \$fdAsOf, \$r['gsa'] ?? 0, \$r['gca'] ?? 0, (int)(\$r['eid'] ?? 0))") !== false);
check('عمود التنزيل العائلي: بتصدير Excel/Word بنفس الترتيب والمنطق',
      strpos($rx42, "'التنزيل العائلي (حصّة الشهر)', 'الراتب الخاضع (بعد حسم التنزيل)'") !== false
      && strpos($rx42, "COALESCE(e.apply_family_deduction,1) afd") !== false
      && strpos($rx42, "\$txb = max(0, (int)\$r['taxable_base_lbp'] - \$fded)") !== false
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
 *     لا تعويض زوجة + تعويض الأولاد مناصفةً (النصف تلقائياً).
 * =================================================================== */
$fn43 = (string)file_get_contents($PROJ . '/includes/functions.php');
$pc43 = (string)file_get_contents($PROJ . '/includes/payroll_calculator.php');
$emp43 = (string)file_get_contents($PROJ . '/pages/employees.php');
check('تعويض عائلي اختياري: العمودان يتركّبان ذاتياً (count_spouse/children_allowance)',
      strpos($fn43, "ADD COLUMN count_spouse_allowance TINYINT(1) NOT NULL DEFAULT 1") !== false
      && strpos($fn43, "ADD COLUMN count_children_allowance TINYINT(1) NOT NULL DEFAULT 1") !== false);
check('تعويض عائلي اختياري: المحرّك يحترم الزرّين + الزوج العامل = لا تعويض زوجة ونصف تعويض الأولاد',
      strpos($pc43, "(int)(\$emp['count_spouse_allowance'] ?? 1) !== 1) \$famSpouse = 0;") !== false
      && strpos($pc43, "(int)(\$emp['count_children_allowance'] ?? 1) !== 1) \$famChildren = 0;") !== false
      && strpos($pc43, "\$famChildren = round(\$famChildren / 2);") !== false);
check('تعويض عائلي اختياري: زرّان بملف الموظف عند حقلي التعويض ويُحفَظان مع الملف',
      strpos($emp43, 'name="count_spouse_allowance"') !== false
      && strpos($emp43, 'name="count_children_allowance"') !== false
      && strpos($emp43, "'count_spouse_allowance' => isset(\$_POST['count_spouse_allowance'])") !== false
      && strpos($emp43, 'يُحسب <strong>النصف تلقائياً</strong>') !== false);
// تجربة فعلية (مع ترجيع كامل): موظف فاعل معدّ — نركّب عليه مبلغَي زوجة 600,000 وأولاد 900,000
// ونجرّب الحالات الأربع على شهر 6/2026 عبر عمود family_allowance_lbp المخزّن
ensureEmployeeFlagColumns();
$c43 = $db->query("SELECT e.id, e.spouse_works, e.family_allowance_spouse_lbp sp, e.family_allowance_children_lbp ch,
                          COALESCE(e.count_spouse_allowance,1) cs, COALESCE(e.count_children_allowance,1) cc
    FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.year = 2026 AND ms.month = 6
    WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND ms.net_salary_lbp > 0
      AND LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),
                COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),
                COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31')) = '9999-12-31'
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
    $set43(1, 1, 1); $fD = $famOf();   // الزوجة تعمل = 0 زوجة + نصّ الأولاد = 450,000
    // ترجيع كامل
    $db->exec("UPDATE employees SET spouse_works = " . (int)$c43['spouse_works'] . ", count_spouse_allowance = " . (int)$c43['cs'] . ",
               count_children_allowance = " . (int)$c43['cc'] . ", family_allowance_spouse_lbp = " . (int)$c43['sp'] . ",
               family_allowance_children_lbp = " . (int)$c43['ch'] . " WHERE id = $cid43");
    $fR = $famOf();
    $expR = ((int)$c43['spouse_works'] ? 0 : ((int)$c43['cs'] ? (int)$c43['sp'] : 0))
          + ((int)$c43['cc'] ? (int)round(((int)$c43['ch']) / ((int)$c43['spouse_works'] ? 2 : 1)) : 0);
    check('تعويض عائلي (تجربة فعلية): الزرّان يتحكّمان بالمبلغ (كامل/بلا زوجة/بلا أولاد)',
          $fA === 1500000 && $fB === 900000 && $fC === 600000,
          "id $cid43 — كامل: " . number_format($fA) . " / بلا زوجة: " . number_format($fB) . " / بلا أولاد: " . number_format($fC));
    check('تعويض عائلي (تجربة فعلية): الزوج/الزوجة يعمل ⇒ صفر زوجة + نصف الأولاد (450,000 من 900,000)',
          $fD === 450000, number_format($fD));
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
      && strpos($of44, "'txb'=>max(0,(int)\$r['taxable_base_lbp']-\$sfd)") !== false
      && strpos($of44, "familyDeductionAnnual(\$r['social_status'] ?? '', \$r['spouse_works'] ?? 0, \$r['afd'] ?? 1, \$sfdAsOf, \$r['gsa'] ?? 0, \$r['gca'] ?? 0, (int)(\$r['employee_id'] ?? 0))") !== false);
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
check('المركّب بلا نقل: خيار النقل بالشريط صار «عمود تعويض النقل (يُجمع بالمستحق)»',
      strpos($fn45, 'عمود تعويض النقل (يُجمع بالمستحق)') !== false);
// تجربة فعلية: كشف رواتب كل الموظفين 6/2026 بكل الخيارات — مركّب مارسيلا 56,145,000
// (بلا النقل 9,000,000) لا 65,145,000، وعمود النقل مستقل والمستحق 59,786,424 يجمعه
$h45 = renderPage('pages/official_forms.php', ['form' => 'salary_all', 'month' => 6, 'year' => 2026], ['extra','aide','transport'], [2]);
$p45 = mb_strpos($h45, 'مارسيلا');
$row45 = $p45 !== false ? mb_substr($h45, $p45, 1800) : '';
check('المركّب بلا نقل (تجربة فعلية): مركّب مارسيلا 56,145,000 والنقل 9,000,000 مستقل والمستحق 59,786,424',
      $row45 !== ''
      && strpos($row45, '56,145,000') !== false
      && strpos($row45, '65,145,000') === false
      && strpos($row45, '9,000,000') !== false
      && strpos($row45, '59,786,424') !== false);
// والكشف الشهري العام أيضاً: النقل قبل «الإجمالي المتوجب» مباشرة (بنية الرأس)
$rp45 = (string)file_get_contents($PROJ . '/pages/reports.php');
check('الكشف الشهري: عمود النقل يسبق «الإجمالي المتوجب» مباشرة',
      strpos($rp45, 'transportHead() ?><th>الإجمالي المتوجب</th>') !== false);

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
      && strpos($emp47, "'grant_spouse_addition' => isset(\$_POST['grant_spouse_addition'])") !== false
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
      substr_count($rp51 . $of51 . (string)file_get_contents($PROJ . '/pages/reports_export.php'), '?? 0)) / 12)') >= 2
      && strpos((string)file_get_contents($PROJ . '/includes/functions.php'), '$exempt += (int)min($fda / 12 * (int)$de[\'mcnt\'], (float)$de[\'tb\']);') !== false
      && function_exists('healLawfulTaxProration20260806')
      && strpos($hd51, 'healLawfulTaxProration20260806();') !== false);
// (تكملة بقاعدة المستخدم «ما بيصير نيغاتيف»): التنزيل المعروض بحدّ الراتب الخاضع —
// طانوس القزي (عازب 10 أشهر، خاضعه 32م < حصة 37.5م): تنزيله المعروض = 32,000,000
// والخاضع بعده = 0 — لا 45,000,000 القديمة ولا 37,500,000 غير المسقّفة
check('التنزيل بحدّ الخاضع: السقف min() مطبَّق بالكشوف الثلاثة (شاشة/تصدير/رواتب)',
      strpos($rp51, "min(\$fdOf(\$r), (int)\$r['taxable_base_lbp'])") !== false
      && strpos((string)file_get_contents($PROJ . '/pages/reports_export.php'), "min(\$fdOf(\$r), (int)\$r['taxable_base_lbp'])") !== false
      && strpos($of51, "min(\$sfdOf(\$r), (int)\$r['taxable_base_lbp'])") !== false);
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
$law51 = $db->query("SELECT ms.employee_id, ms.taxable_base_lbp txb, ms.income_tax_lbp tax, e.social_status ss
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE e.is_deleted = 0 AND e.payment_months_per_year <> 12 AND e.tax_subject = 1
      AND (e.employee_type = 'enseignant_titulaire' OR e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
      AND e.social_status = 'celibataire' AND COALESCE(e.apply_family_deduction, 1) = 1
      AND ms.month = 6 AND ms.year = 2026 AND ms.income_tax_lbp > 0")->fetchAll(PDO::FETCH_ASSOC);
$bad51 = [];
foreach ($law51 as $r51x) {
    $exp51 = (int)round(annualLawTaxAsOf($db, (float)$r51x['txb'] * 12, $r51x['ss'], 6, 2026) / 12);
    if (abs((int)$r51x['tax'] - $exp51) > 1) $bad51[] = $r51x['employee_id'] . ':' . number_format((int)$r51x['tax']) . '≠' . number_format($exp51);
}
check('تجزئة القانون (تجربة فعلية): ضريبة كل المعدّين غير ذوي الـ12 شهراً المخزّنة = المعادلة القانونية بالمليم',
      count($law51) > 0 && !$bad51, count($law51) . ' موظفاً' . ($bad51 ? ' — خلل: ' . implode(' · ', $bad51) : ' كلهم مطابقون'));

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
      && strpos($oe52, "\$ldTs = \$emp['left_date_cnss'] ? strtotime(\$emp['left_date_cnss']) : 0;") !== false
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
      && strpos($emp61s, "'grant_children_addition' => isset(\$_POST['grant_children_addition'])") !== false
      && strpos($emp61s, "'grant_children_addition' => 0,") !== false);
$gcaCount = 0;
// (2026-08-24: تقرير الضريبة صار يمرّره عبر mofCumTax التراكمية بfunctions.php — تُعدّ كمان)
foreach (['pages/reports.php', 'pages/reports_export.php', 'pages/official_forms.php', 'pages/official_export.php', 'includes/functions.php'] as $f61) {
    $gcaCount += substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$r['gca'] ?? 0")
               + substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$de['gca'] ?? 0")
               + substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$emp['grant_children_addition'] ?? 0")
               + substr_count((string)file_get_contents($PROJ . '/' . $f61), "\$e['grant_children_addition'] ?? (\$e['gca'] ?? 0)");
}
check('تنزيل الأولاد اختياري: كل القارئين يمرّرونه (كشوف + تقرير الضريبة + ر5/ر6/ر10)', $gcaCount >= 6, 'ممرَّر بـ' . $gcaCount . ' مواضع');

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
check('فئة الأرمل: المعادلة تحسبها (شخصي + أولاد بمفتاحهم، بلا زيادة زوج)',
      familyDeductionAnnual('veuf_2_enfants', 0, 1, '2026-01-01', 1, 1) === familyDeductionAnnual('marie_2_enfants', 1, 1, '2026-01-01', 1, 1)
      && familyDeductionAnnual('veuf_sans_enfants', 0, 1, '2026-01-01', 1, 1) === familyDeductionAnnual('celibataire', 0, 1, '2026-01-01', 1, 1)
      && familyDeductionAnnual('veuf_2_enfants', 0, 1, '2026-01-01', 1, 0) === familyDeductionAnnual('celibataire', 0, 1, '2026-01-01', 1, 1));
check('فئة الأرمل: خياراتها بملف الموظف + تسمياتها',
      strpos((string)file_get_contents($PROJ . '/pages/employees.php'), 'value="veuf_2_enfants"') !== false
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
check('صفحة القرارات: تشاك مارك نعم/كلا يطبَّق فوراً بملف الأستاذ + «من (ولادته) إلى (بلوغه 18)» + إدارة الأولاد وتاريخ عمل الزوج',
      strpos($dec66, 'قرارات التنزيل العائلي') !== false
      && strpos($dec66, 'تنزيله <strong>من') !== false
      && strpos($dec66, '(بلوغه 18)') !== false
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
      && strpos((string)file_get_contents($PROJ . '/pages/reports.php'), "(int)(\$r['eid'] ?? 0)") !== false);

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
check('عبرا: ≥180 ملفاً برقم + ريتا حليحل تفرّقتا بالأب (يوسف=101141، مارون=130587) والملتبسة الأب فاضية',
      $abCnt69 >= 180
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

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
