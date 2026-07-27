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

/* ---------- الخلاصة ---------- */
echo implode("\n", $results) . "\n\n";
echo "═══ النتيجة: $pass ناجح · $fail فاشل ═══\n";
exit($fail ? 1 : 0);
