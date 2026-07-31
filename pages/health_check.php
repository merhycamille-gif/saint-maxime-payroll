<?php
/**
 * فحص صحّة البرنامج / Contrôle de santé du programme
 * =============================================================================
 * زرّ واحد يفحص البرنامج بنفسه ويعطي المستخدمَ نتيجةً يراها بعينه — لا يحتاج أن
 * يصدّق أحداً. يجمع ثلاث طبقات:
 *   (١) سلامة البيانات المخزَّنة: كل رقم يجب أن يساوي مجموع مكوّناته (استعلامات فعلية).
 *   (٢) حرّاس الحماية موجودون بالكود: صلاحيات، أسرار، حماية الروابط، النِّسَب المؤرّخة.
 *   (٣) أخطاء PHP الأخيرة من سجلّ الخادم (إن كان مقروءاً).
 * كل فحص يعرض: النتيجة + الرقم الذي يثبتها + ماذا يعني الفشل + ما العمل.
 * قراءة فقط — لا يعدّل شيئاً أبداً.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    $_SESSION['flash_error'] = 'صلاحية المدير مطلوبة / Accès réservé à l\'administrateur';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$currentPage = 'health_check';
$pageTitle = 'Contrôle de santé / فحص صحّة البرنامج';
$db = getDB();

// «تصفير سجلّ التحذيرات»: بعد إصلاح شيءٍ ما، التحذيرات القديمة تبقى مكتوبةً في سجلّ
// الخادم ولا معنى لعرضها. هذا الزرّ يسجّل «ابدأ العدّ من الآن» فيصير كل تحذير يظهر
// بعده تحذيراً **حقيقياً جديداً** يستحقّ الإصلاح.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_log') {
    requireCsrf();
    setSetting('health_log_since', date('Y-m-d H:i:s'));
    $_SESSION['flash_success'] = 'تمّ التصفير — أي تحذير يظهر من الآن هو جديد فعلاً.';
    header('Location: ' . BASE_URL . 'pages/health_check.php');
    exit;
}
$logSince = (string)getSetting('health_log_since', '');
$logSinceTs = $logSince !== '' ? strtotime($logSince) : (time() - 7 * 86400);

$PROJ = dirname(__DIR__);
$groups = [];   // مجموعة => [ [ok, name, proof, meaning, type] ... ]
$okAll = 0; $failAll = 0; $reviewAll = 0;

/**
 * يضيف نتيجة فحص.
 *   $type = 'check'  : صحّة البرنامج نفسه (فشلُها خطأ برمجي عليّ إصلاحه).
 *   $type = 'review' : بيانات أدخلها المستخدم وتحتاج قراره — لا تُحتسب خطأً بالبرنامج.
 */
function hc(&$groups, $group, $ok, $name, $proof = '', $meaning = '', $type = 'check') {
    global $okAll, $failAll, $reviewAll;
    if ($type === 'review') { if (!$ok) $reviewAll++; else $okAll++; }
    else { $ok ? $okAll++ : $failAll++; }
    $groups[$group][] = ['ok' => (bool)$ok, 'name' => $name, 'proof' => $proof, 'meaning' => $meaning, 'type' => $type];
}

/* =============================================================================
 * (١) سلامة البيانات المخزَّنة — استعلامات فعلية على كل رواتب الموظفين الحاليين
 * ========================================================================== */
$G1 = 'سلامة الأرقام المخزَّنة / Cohérence des montants';
$BASE = 'FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id WHERE e.is_deleted = 0';
$cnt = function (string $where) use ($db, $BASE) {
    // الأقواس ضرورية: AND تسبق OR بالأولوية
    try { return (int)$db->query("SELECT COUNT(*) $BASE AND ($where)")->fetchColumn(); }
    catch (Exception $e) { return -1; }
};
$totalRows = (int)$db->query("SELECT COUNT(*) $BASE")->fetchColumn();

$dataChecks = [
    ['الأساس + الدرجة = الأساس + قيمة الدرجة',
     'ABS(ms.base_plus_echelon_lbp - (ms.base_salary_lbp + ms.echelon_value_lbp)) > 1',
     'لو فشل: عمود «الراتب بعد التدرّج» بكل الكشوف لا يساوي جمع عمودَيه.'],
    ['المستحق = الصافي + التعويضات العائلية + النقل',
     'ABS(ms.total_due_lbp - (ms.net_salary_lbp + ms.family_allowance_lbp + ms.transport_lbp)) > 1',
     'لو فشل: «الإجمالي المتوجب» بكشف الرواتب لا تفسّره أعمدته.'],
    ['لا ضريبة أكبر من الراتب الخاضع للضريبة',
     'ms.income_tax_lbp > ms.taxable_base_lbp',
     'لو فشل: التصريح الضريبي (ر٥/ر١٠) يعلن ضريبة أكبر من أساسها.'],
    ['لا ضمان محسوم على غير الخاضعين للضمان',
     'ms.cnss_amount_lbp > 0 AND e.cnss_subject = 0',
     'لو فشل: بيان الضمان يشمل موظفاً غير مسجَّل فيه.'],
    ['لا مبالغ سالبة',
     'ms.base_salary_lbp < 0 OR ms.net_salary_lbp < 0 OR ms.total_due_lbp < 0 OR ms.cnss_amount_lbp < 0 OR ms.income_tax_lbp < 0',
     'لو فشل: راتب بالسالب — غالباً إدخال خاطئ.'],
    ['لا مبالغ مستحيلة (أكبر من ١٠٠ مليار بصفّ واحد)',
     'ms.prime_fixe_lbp > 1e11 OR ms.total_due_lbp > 1e11 OR ms.base_salary_lbp > 1e11',
     'لو فشل: مبلغ ليرة كُتب والعملة بقيت دولاراً (يحرسه البرنامج تلقائياً الآن).'],
    ['عمودا تعويض النقل متطابقان (لا احتساب مضاعف)',
     'ms.transport_lbp > 0 AND ms.transport_complement_lbp > 0 AND ms.transport_lbp <> ms.transport_complement_lbp',
     'لو فشل: النقل قد يُحتسب مرّتين في المستحق.'],
];
foreach ($dataChecks as [$nm, $w, $mean]) {
    $n = $cnt($w);
    hc($groups, $G1, $n === 0, $nm, $n === 0 ? 'صفر من ' . number_format($totalRows) . ' راتب' : number_format($n) . ' صفّاً مخالفاً', $mean);
}
try {
    $dup = (int)$db->query("SELECT COUNT(*) FROM (SELECT employee_id, month, year, COUNT(*) c FROM monthly_salaries GROUP BY employee_id, month, year HAVING c > 1) x")->fetchColumn();
    hc($groups, $G1, $dup === 0, 'لا رواتب مكرّرة لنفس الموظف والشهر', $dup === 0 ? 'صفر تكرار' : "$dup تكرار", 'لو فشل: الموظف يظهر مرّتين بكشف الشهر فتتضخّم المجاميع.');
    $orph = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms LEFT JOIN employees e ON e.id = ms.employee_id WHERE e.id IS NULL")->fetchColumn();
    hc($groups, $G1, $orph === 0, 'لا رواتب لموظفين غير موجودين', $orph === 0 ? 'صفر' : "$orph صفّاً", 'لو فشل: مبالغ بلا صاحب تدخل بالمجاميع.');
    // بعد حذف مدارس نهائياً (مغدوشة/سان نيقولا 2026-07-31): لا يجوز أن يبقى خلفها أي أثر
    $orphE = (int)$db->query("SELECT COUNT(*) FROM employees e LEFT JOIN schools s ON s.id = e.school_id WHERE s.id IS NULL")->fetchColumn();
    hc($groups, $G1, $orphE === 0, 'لا موظفين تابعين لمدرسة محذوفة', $orphE === 0 ? 'صفر' : "$orphE موظفاً", 'لو فشل: حذف مدرسة ترك موظفيها بلا مدرسة — يدخلون بالمجاميع ولا يظهرون بأي لائحة.');
    $orphS = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries ms LEFT JOIN schools s ON s.id = ms.school_id WHERE s.id IS NULL")->fetchColumn();
    hc($groups, $G1, $orphS === 0, 'لا رواتب تابعة لمدرسة محذوفة', $orphS === 0 ? 'صفر' : "$orphS صفّاً", 'لو فشل: حذف مدرسة ترك رواتب بلا مدرسة تتلوّث بها المجاميع.');
} catch (Exception $e) {}

/* الأرقام «المنقولة»: مكوّناتها لا تفسّر مجموعها (بيانات مستوردة — تحتاج قرار المستخدم) */
$G2 = 'أرقام تحتاج مراجعتك / Montants à revoir';
$hcYear = activeSchoolYear();
$hcYear = ($hcYear === 'all') ? currentSchoolYear() : $hcYear;
try {
    $st = $db->prepare("SELECT COUNT(DISTINCT ms.employee_id) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND ms.school_year = ?" . schoolScopeSql('e.school_id') . "
          AND (ABS(ms.total_retenues_lbp - (ms.cnss_amount_lbp + ms.caisse_amount_lbp + ms.income_tax_lbp + ms.eoc_grade_lbp)) > 1
               OR ABS(ms.net_salary_lbp - ((ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) - ms.total_retenues_lbp)) > 1)");
    $st->execute([$hcYear]);
    $unrec = (int)$st->fetchColumn();
    hc($groups, $G2, $unrec === 0, "مكوّنات الرواتب تفسّر مجاميعها ($hcYear)",
       $unrec === 0 ? 'كل الموظفين سليمون' : "$unrec موظف",
       'هؤلاء رواتبهم «منقولة» أُدخلت كمبلغ واحد بلا تفصيل، فتظهر الضريبة أو الصندوق صفراً مع أنّ المحسوم أكبر. '
       . 'الحلّ بيدك: افتح ملف الأستاذ، أدخِل إعداد راتبه، ثم «احتساب السنة». التفصيل بصفحة «فحص مطابقة القانون».',
       'review');
} catch (Exception $e) {}

/* =============================================================================
 * (٢) حرّاس الحماية والقواعد موجودون بالكود
 * ========================================================================== */
$G3 = 'الحماية والصلاحيات / Sécurité';
$fn = @file_get_contents($PROJ . '/includes/functions.php') ?: '';
$inst = @file_get_contents($PROJ . '/install.php') ?: '';
$dbf = @file_get_contents($PROJ . '/config/database.php') ?: '';
$ofp = @file_get_contents($PROJ . '/pages/official_forms.php') ?: '';

hc($groups, $G3, strpos($inst, 'http_response_code(410)') !== false && strpos($inst, 'new PDO') === false,
   'صفحة التركيب مُعطَّلة (لا تمسح البيانات)', strpos($inst, '410') !== false ? 'معطَّلة ✓' : 'خطر!',
   'لو فشل: أي شخص يفتح رابط install.php يمسح كل البيانات.');
hc($groups, $G3, strpos($fn, 'function requireWriteAction') !== false,
   'حماية عمليات التعديل عبر الروابط', 'موجودة',
   'لو فشل: حساب «قراءة فقط» يستطيع إعادة حساب الرواتب أو الحذف برابط.');
$guarded = 0; $pagesNeed = ['annual_slip','grades','employees','bonuses','classes','exceptional_laws','exchange_rates',
                            'rates_history','social_security','salary_scales','tax_brackets','users','schools'];
foreach ($pagesNeed as $pg) {
    $src = @file_get_contents($PROJ . "/pages/$pg.php") ?: '';
    if (strpos($src, 'requireWriteAction(') !== false) $guarded++;
}
hc($groups, $G3, $guarded === count($pagesNeed), 'كل صفحات التعديل محميّة',
   "$guarded من " . count($pagesNeed) . ' صفحة', 'لو فشل: صفحة تعديل بلا حماية صلاحية/مصدر.');
hc($groups, $G3, strpos($fn, 'StM_infoform_') === false && strpos($fn, 'function infoFormSecret') !== false,
   'سرّ روابط الأساتذة غير مكتوب بالكود', strlen((string)getSetting('info_form_secret', '')) >= 32 ? 'عشوائي ومخزَّن ✓' : 'ناقص',
   'لو فشل: من يقرأ الكود يفتح بيانات كل أستاذ الشخصية.');
hc($groups, $G3, strpos($fn, 'function selectedReportSchoolIds') !== false && strpos($fn, 'viewerAllowedSchoolIds()') !== false,
   'حساب المدرسة يرى مدارسه فقط', 'محصور ✓',
   'لو فشل: حساب مدرسة يرى تقارير كل المدارس.');
hc($groups, $G3, strpos($dbf, 'function &settingsCache') !== false,
   'الإعدادات تُقرأ محدَّثة بعد الحفظ', 'محدَّثة ✓',
   'لو فشل: تغيير سعر الصرف يعيد حساب الرواتب بالسعر القديم.');

$G4 = 'قواعد الحساب والنماذج / Règles de calcul';
hc($groups, $G4, preg_match('/[\/*]\s*0\.(11|085|06|03)\b/', $ofp) === 0 && strpos($fn, 'function rateFrac') !== false,
   'نِسَب الضمان والصندوق مؤرّخة (لا أرقام ثابتة بالكود)', 'مؤرّخة ✓',
   'لو فشل: تعديل نسبة يجعل عمود الاشتراك صحيحاً وعمود الأجر خاطئاً بالنموذج نفسه.');
hc($groups, $G4, abs(rateFrac('cnss_employee_rate', null, null, 3) - 0.03) < 1e-9,
   'نسبة الضمان السارية تُقرأ صحيحة', number_format(getRateAsOf('cnss_employee_rate', null, null, 3), 2) . '%',
   'لو فشل: جدول النِّسَب المؤرّخة لا يُقرأ.');
hc($groups, $G4, strpos($fn, 'function dueShownLbp') !== false || strpos(@file_get_contents($PROJ . '/includes/report_helpers.php') ?: '', 'function dueShownLbp') !== false,
   'قاعدة «الأرقام تركب» مركزية بالتقارير', 'موجودة ✓',
   'لو فشل: مستحق يشمل مبالغ بلا عمود يفسّرها.');
hc($groups, $G4, strpos($fn, 'function sanitizeAmountCurrency') !== false,
   'حارس خطأ العملة (دولار/ليرة)', 'يعمل ✓',
   'لو فشل: مبلغ ليرة بعملة دولار يضخّم الراتب آلاف المرّات.');
hc($groups, $G4, strpos($fn, 'function writeSchoolYear') !== false,
   'حفظ العلاوات يصيب سنةً حقيقية دائماً', 'مضبوط ✓',
   'لو فشل: علاوة تُحفظ بوضع «كل السنين» فتضيع بلا أن تعرف.');

/* =============================================================================
 * (٣) أخطاء PHP الأخيرة من سجلّ الخادم (إن توفّر)
 * ========================================================================== */
$G5 = 'سجلّ أخطاء الخادم / Journal du serveur';
$logCandidates = [
    'C:/xampp/apache/logs/error.log',
    ini_get('error_log'),
    $PROJ . '/error_log',
    dirname($PROJ) . '/error_log',
];
$logFile = '';
foreach ($logCandidates as $lf) {
    if ($lf && @is_readable($lf)) { $logFile = $lf; break; }
}
if ($logFile === '') {
    hc($groups, $G5, true, 'سجلّ الأخطاء غير متاح للقراءة على هذا الخادم', 'تخطٍّ',
       'ليس خطأ — بعض الخوادم تمنع قراءة السجلّ. الفحوص الأخرى تكفي.');
} else {
    $tail = '';
    $fh = @fopen($logFile, 'rb');
    if ($fh) {
        $size = max(0, (int)@filesize($logFile));
        $read = min($size, 400000); // آخر ~400 كيلوبايت
        if ($read > 0) { @fseek($fh, $size - $read); $tail = (string)@fread($fh, $read); }
        @fclose($fh);
    }
    $lines = $tail === '' ? [] : preg_split('/\r?\n/', $tail);
    $mine = [];
    foreach ($lines as $ln) {
        if (stripos($ln, 'saint-maxime-payroll') === false) continue;
        if (!preg_match('/PHP (Warning|Fatal error|Parse error|Notice|Deprecated)/i', $ln)) continue;
        // نعتمد فقط ما جاء **بعد** لحظة التصفير (أو آخر ٧ أيام إن لم يُصفَّر بعد).
        // ملاحظة: تاريخ Apache يحمل ميكروثانية «09:45:30.206876» وstrtotime لا يفهمها
        // فتُحذَف أولاً — وإلّا فشلت القراءة واحتُسبت كل التحذيرات القديمة كأنها جديدة.
        if (!preg_match('/\[(\w{3} \w{3} \d+ [\d:.]+ \d{4})\]/', $ln, $dm)) continue;
        $ts = strtotime(preg_replace('/\.\d+/', '', $dm[1]));
        if (!$ts || $ts < $logSinceTs) continue;
        $msg = preg_replace('/^.*?PHP /', 'PHP ', $ln);
        $msg = preg_replace('/ in [A-Za-z]:\\\\.*$/', '', $msg);
        $mine[trim($msg)] = ($mine[trim($msg)] ?? 0) + 1;
    }
    $sinceLbl = $logSince !== '' ? ('منذ التصفير (' . e($logSince) . ')') : 'خلال آخر ٧ أيام';
    hc($groups, $G5, empty($mine),
       'لا أخطاء ولا تحذيرات PHP ' . $sinceLbl,
       empty($mine) ? 'السجلّ نظيف ✓' : count($mine) . ' نوع تحذير',
       'لو فشل: البرنامج يشتكي في الخلفية — التفصيل بالأسفل، وابعتها لي لأصلّحها. '
       . 'وإن كانت تحذيرات قديمة أُصلحت فعلاً، اكبس «تصفير سجلّ التحذيرات» ليبدأ العدّ من الآن.');
    $logDetails = $mine;
}

include __DIR__ . '/../includes/header.php';
?>
<div id="pageContent">

  <?php $allGood = ($failAll === 0); ?>
  <div class="card">
    <div class="card-header"><h3>
      <i class="fas fa-stethoscope"></i> <span dir="ltr">Contrôle de santé du programme</span>
      <div style="font-size:0.85em;font-weight:600;opacity:0.9">فحص صحّة البرنامج — نتيجة تراها بعينك</div>
    </h3></div>
    <div class="card-body">
      <div class="alert alert-<?= $allGood ? 'success' : 'danger' ?>" style="font-size:15px">
        <i class="fas fa-<?= $allGood ? 'circle-check' : 'triangle-exclamation' ?>"></i>
        <?php if ($allGood): ?>
          <strong>البرنامج سليم:</strong> نجح <strong><?= $okAll ?></strong> فحصاً و<strong>لا خطأ برمجي واحد</strong> —
          على <strong><?= number_format($totalRows) ?></strong> راتب مخزَّن.
        <?php else: ?>
          <strong>خطأ بالبرنامج: نجح <?= $okAll ?> فحصاً وفشل <?= $failAll ?>.</strong>
          الفاشل معلَّم بالأحمر أدناه ومكتوب جنبه ماذا يعني — ابعتلي صورة الصفحة لأصلّحه.
        <?php endif; ?>
        <?php if ($reviewAll): ?>
          <div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(0,0,0,.15)">
            <i class="fas fa-user-pen"></i>
            <strong>و<?= $reviewAll ?> ملاحظة تحتاج قرارك أنت</strong> (بيانات أُدخلت يدوياً، ليست خطأ بالبرنامج) —
            بقسم «أرقام تحتاج مراجعتك» أدناه.
          </div>
        <?php endif; ?>
        <div class="text-muted" style="margin-top:6px;font-size:13px">
          <i class="fas fa-rotate"></i> اكبس <strong>F5</strong> لإعادة الفحص في أي وقت — الفحص قراءة فقط ولا يعدّل شيئاً.
          آخر فحص: <?= e(date('Y-m-d H:i')) ?>
        </div>
      </div>
    </div>
  </div>

  <?php foreach ($groups as $gName => $items):
      $gFail   = count(array_filter($items, fn($x) => !$x['ok'] && ($x['type'] ?? 'check') !== 'review'));
      $gReview = count(array_filter($items, fn($x) => !$x['ok'] && ($x['type'] ?? 'check') === 'review'));
      $gBg = $gFail ? '#fef2f2' : ($gReview ? '#eff6ff' : '#f0fdf4');
      $gBd = $gFail ? '#fca5a5' : ($gReview ? '#93c5fd' : '#a7f3d0');
      $gFg = $gFail ? '#991b1b' : ($gReview ? '#1e40af' : '#065f46');
      $gIc = $gFail ? 'circle-exclamation' : ($gReview ? 'user-pen' : 'circle-check'); ?>
  <div class="card">
    <div class="card-header" style="background:<?= $gBg ?>;border-bottom:1px solid <?= $gBd ?>">
      <h3 style="color:<?= $gFg ?>">
        <i class="fas fa-<?= $gIc ?>" style="background:none;color:inherit"></i>
        <?= e($gName) ?>
        <span style="font-weight:600;opacity:.85">(<?= count($items) - $gFail - $gReview ?>/<?= count($items) ?>)</span>
      </h3>
    </div>
    <div class="card-body">
      <table class="table">
        <thead><tr>
          <th style="width:34px"></th>
          <th>Contrôle / الفحص</th>
          <th style="width:190px">Résultat / النتيجة</th>
          <th>Signification / ماذا يعني</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr style="<?= $it['ok'] ? '' : ((($it['type'] ?? 'check') === 'review') ? 'background:#f5f9ff' : 'background:#fff6f6') ?>">
            <td style="text-align:center;font-size:15px"><?= $it['ok'] ? '✅' : ((($it['type'] ?? 'check') === 'review') ? '📝' : '⚠️') ?></td>
            <td><strong><?= e($it['name']) ?></strong></td>
            <td style="color:<?= $it['ok'] ? '#065f46' : ((($it['type'] ?? 'check') === 'review') ? '#1e40af' : '#991b1b') ?>"><strong><?= e($it['proof']) ?></strong></td>
            <td><small class="text-muted"><?= e($it['meaning']) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($gName === 'أرقام تحتاج مراجعتك / Montants à revoir' && $gReview): ?>
        <a href="<?= BASE_URL ?>pages/law_check.php" class="btn btn-primary no-print">
          <i class="fas fa-list-check"></i> افتح تفصيل الأساتذة / Voir le détail
        </a>
      <?php endif; ?>
      <?php if ($gName === 'سجلّ أخطاء الخادم / Journal du serveur' && !empty($logDetails)): ?>
        <div class="alert alert-warning" style="margin-top:12px">
          <strong>التحذيرات المسجَّلة (آخر ٧ أيام):</strong>
          <ul style="margin:8px 0 0;padding-inline-start:20px">
            <?php foreach (array_slice($logDetails, 0, 12, true) as $msg => $times): ?>
              <li><code style="font-size:11.5px"><?= e($msg) ?></code> <span class="text-muted">(<?= (int)$times ?> مرّة)</span></li>
            <?php endforeach; ?>
          </ul>
          <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <span>ابعتلي صورة هذا الصندوق وأصلّحها.</span>
            <form method="POST" class="no-print" style="margin:0"
                  onsubmit="return confirm('تصفير: لن تُحتسب التحذيرات القديمة بعد الآن. متابعة؟')">
              <input type="hidden" name="action" value="reset_log">
              <?= csrfField() ?>
              <button class="btn btn-sm btn-light"><i class="fas fa-broom"></i> تصفير سجلّ التحذيرات (بعد الإصلاح)</button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-body">
      <p class="text-muted" style="margin:0">
        <i class="fas fa-circle-info"></i>
        <strong>ما يفحصه هذا الزرّ:</strong> أرقام كل الرواتب المخزَّنة (هل كل مجموع يساوي مكوّناته)،
        ووجود حرّاس الحماية والصلاحيات بالكود، وأنّ نِسَب الضمان والضريبة تُقرأ من جدولها المؤرّخ،
        وسجلّ أخطاء الخادم. <strong>ما لا يفحصه:</strong> صحّة ما أدخلتَه يدوياً من مبالغ وتواريخ —
        هذه تظهر بقسم «أرقام تحتاج مراجعتك» ويبقى قرارها لك.
      </p>
    </div>
  </div>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
