<?php
/**
 * لائحة الأساتذة التاركين — Enseignants ayant quitté.
 * يعرض كل من له تاريخ ترك (ضمان/مالية/صندوق) مجمّعين حسب المدرسة، مع تواريخ الترك ورابط ملفه.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$currentPage = 'left_teachers';
$pageTitle = 'الأساتذة التاركون / Enseignants ayant quitté';
$db = getDB();

// تنسيق تاريخ d/m/Y (أو — إن فارغ)
function ltDate($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : e($d);
}

// كل التاركين ضمن نطاق المدرسة: عنده أيّ تاريخ ترك (الكل/ضمان/مالية/صندوق). مرتَّبون حسب المدرسة ثم الفئة ثم الاسم.
ensureLeftDateAllColumn();
$sql = "SELECT e.*, COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
        FROM employees e LEFT JOIN schools sc ON sc.id = e.school_id
        WHERE e.is_deleted = 0
          AND (e.left_date_all IS NOT NULL OR e.left_date_cnss IS NOT NULL OR e.left_date_finance IS NOT NULL OR e.left_date_eoc IS NOT NULL)"
     . schoolScopeSql('e.school_id')
     . " ORDER BY school_name,
         FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
         COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
$allRows = $db->query($sql)->fetchAll();

// 🚪 (2026-09-18) تاريخ الترك الأساسي = «الترك من الكل» (هو الذي يُخرج الاسم والرواتب)؛ مَن ترك جهةً فقط
// (ضمان/مالية/صندوق) يظهر هنا للعلم بتاريخ جهته الأبكر ويبقى فاعلاً بالرواتب — والسنة الدراسية تُشتقّ من الأساسي
foreach ($allRows as &$r) {
    $all = leftDateOf($r);
    $ds = array_filter([$r['left_date_cnss'], $r['left_date_finance'], $r['left_date_eoc']],
            fn($d) => !empty($d) && $d !== '0000-00-00');
    $r['_partial'] = ($all === null);
    $r['_primary_ts'] = $all !== null ? strtotime($all) : ($ds ? min(array_map('strtotime', $ds)) : 0);
    $r['_sy'] = $r['_primary_ts'] ? schoolYearOfDate(date('Y-m-d', $r['_primary_ts'])) : null;
    $r['_cy'] = $r['_primary_ts'] ? date('Y', $r['_primary_ts']) : null;   // 📅 السنة الميلادية للترك (2026-10-18)
}
unset($r);

// 👥 (2026-10-18 «انتبه إذا في أسماء مكرّرة بدك تشيلها إذا بنفس المدرسة») الاسم نفسه بالمدرسة نفسها يظهر مرّة واحدة:
//    نُبقي الملف الأكمل (ترك من الكل قبل الجزئي) ثم الأحدث تركاً. العدّ والفحص التلقائي على اللائحة بعد الحذف.
$ltNameKey = function (array $r): string {
    $n = trim(($r['first_name_ar'] ?? '') . ' ' . ($r['father_name_ar'] ?? '') . ' ' . ($r['last_name_ar'] ?? ''));
    if (trim(($r['first_name_ar'] ?? '') . ($r['last_name_ar'] ?? '')) === '') $n = trim(($r['first_name_fr'] ?? '') . ' ' . ($r['last_name_fr'] ?? ''));
    $n = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $n);                 // بلا تشكيل ولا تطويل
    $n = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $n);  // توحيد الهمزات والتاء المربوطة
    return (int)$r['school_id'] . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $n), 'UTF-8');
};
$ltSeen = []; $dupRemoved = 0;
foreach ($allRows as $i => $r) {
    $k = $ltNameKey($r);
    if (!isset($ltSeen[$k])) { $ltSeen[$k] = $i; continue; }
    $j = $ltSeen[$k]; $keep = $allRows[$j];
    $better = ($keep['_partial'] && !$r['_partial']) || ($keep['_partial'] === $r['_partial'] && $r['_primary_ts'] > $keep['_primary_ts']);
    if ($better) { unset($allRows[$j]); $ltSeen[$k] = $i; } else { unset($allRows[$i]); }
    $dupRemoved++;
}
$allRows = array_values($allRows);

// 📅 (2026-10-18 «بدي التاركين يبيّنوا تركهن بالسنة الميلادية مش المدرسية — أنا بختار») وضعان: ym=school (1/10→30/9) | ym=civil (1/1→31/12)
$ym = (($_GET['ym'] ?? 'school') === 'civil') ? 'civil' : 'school';
$ltYearOf = fn(array $r) => $ym === 'civil' ? $r['_cy'] : $r['_sy'];
$ltYearRe = $ym === 'civil' ? '/^\d{4}$/' : '/^\d{4}-\d{4}$/';

// السنوات المتوفّرة في البيانات (تنازلياً) لبناء الفلتر — مدرسية أو ميلادية حسب الوضع
$availYears = [];
foreach ($allRows as $r) { if ($ltYearOf($r)) $availYears[$ltYearOf($r)] = true; }
$availYears = array_keys($availYears);
rsort($availYears);

// الفلتر: السنة المختارة أو "all" لكل السنوات
// 📅 (2026-10-18 «وملف اللي تركوا بنفس السنة») الافتراضي = السنة الدراسية المعروضة بالبرنامج (كباقي التقارير)؛ «كل السنين» بالعرض ⇒ سنة البرنامج الحالية
//    وبالوضع الميلادي: السنة الميلادية الحالية
$ltDefYear = preg_match('/^\d{4}-\d{4}$/', (string)activeSchoolYear()) ? (string)activeSchoolYear() : currentSchoolYear();
if ($ym === 'civil') $ltDefYear = date('Y');
$selYear = (string)($_GET['sy'] ?? $ltDefYear);
if ($selYear === '') $selYear = $ltDefYear;   // تبديل الوضع يفرّغ السنة ⇒ الافتراضي
if ($selYear !== 'all' && !preg_match($ltYearRe, $selYear)) $selYear = $ltDefYear;

// طبّق الفلتر
$rows = ($selYear === 'all') ? $allRows : array_values(array_filter($allRows, fn($r) => $ltYearOf($r) === $selYear));

// 🔍 فحص تلقائي: أي تارك عنده راتب في سنة دراسية بعد سنة تركه = تناقض (راتب وهمي).
// يُحسب لكل التاركين ضمن النطاق (بغضّ النظر عن فلتر السنة) بطلب واحد فعّال.
$anomalies = [];
if ($allRows) {
    $ids = array_map(fn($r) => (int)$r['id'], $allRows);
    $maxRank = [];
    $q = $db->query("SELECT employee_id, MAX(CASE WHEN month >= 10 THEN year ELSE year - 1 END) AS mr
                     FROM monthly_salaries WHERE employee_id IN (" . implode(',', $ids) . ") GROUP BY employee_id");
    foreach ($q as $row) { $maxRank[(int)$row['employee_id']] = (int)$row['mr']; }
    foreach ($allRows as $r) {
        if (!$r['_primary_ts'] || $r['_partial']) continue; // الترك الجزئي لا يوقف الراتب — لا تناقض
        $ly = (int)date('Y', $r['_primary_ts']); $lm = (int)date('n', $r['_primary_ts']);
        $depRank = ($lm >= 10) ? $ly : $ly - 1;
        $mr = $maxRank[(int)$r['id']] ?? null;
        if ($mr !== null && $mr > $depRank) {
            $nm = empFullNameAr($r);
            $anomalies[] = ['id' => (int)$r['id'], 'name' => $nm, 'school' => $r['school_name'], 'left' => date('d/m/Y', $r['_primary_ts']), 'until' => $mr];
        }
    }
}

// جمّع حسب المدرسة
$bySchool = [];
foreach ($rows as $r) {
    $k = $r['school_name'] ?: '—';
    $bySchool[$k][] = $r;
}
$total = count($rows);
$grandTotal = count($allRows);

include __DIR__ . '/../includes/header.php';
?>
<div class="alert alert-info no-print" style="margin-bottom:14px">
  <i class="fas fa-door-open"></i> لائحة <strong>الأساتذة والموظفين الذين تركوا العمل</strong> (سُجِّل لهم تاريخ ترك عبر ملف الأستاذ أو عبر رابط تحديث المعلومات) — مصنّفة حسب <strong><?= $ym === 'civil' ? 'السنة الميلادية (1/1 → 31/12)' : 'السنة الدراسية (1/10 → 30/9)' ?></strong> التي وقع فيها الترك، وتختار الوضع من الكبستين. الافتراضي: <strong><?= e($ltDefYear) ?></strong>. «ترك من الكل» وحده يُخرج الاسم والرواتب؛ مَن ترك الضمان أو المالية أو الصندوق فقط يظهر «ترك جزئي» ويبقى براتبه.
  <?php if ($dupRemoved): ?><br><i class="fas fa-user-group"></i> أُخفيت <strong><?= $dupRemoved ?></strong> أسماء مكرّرة بنفس المدرسة (الاسم نفسه مرّتين) — بقي الملف الأكمل والأحدث.<?php endif; ?><br>
  <span dir="ltr">Liste des enseignants / employés ayant quitté, classée par <?= $ym === 'civil' ? 'année civile' : 'année scolaire' ?> du départ.</span>
</div>

<?php // 🔍 نتيجة الفحص التلقائي: بانر أخضر إن كل شي سليم، أو تحذير بالتناقضات ?>
<?php if (empty($anomalies)): ?>
  <div class="alert alert-success no-print" style="margin-bottom:14px">
    <i class="fas fa-check-circle"></i> <strong>فحص تلقائي:</strong> كل تواريخ الترك سليمة — ما في ولا أستاذ تارك عندو راتب بعد تاريخ تركه. ✅
    <span dir="ltr" style="opacity:.8">/ Vérification automatique : aucune incohérence.</span>
  </div>
<?php else: ?>
  <div class="alert alert-danger no-print" style="margin-bottom:14px">
    <i class="fas fa-exclamation-triangle"></i> <strong>فحص تلقائي — انتبه:</strong> في <strong><?= count($anomalies) ?></strong> أستاذ تارك عندو رواتب <strong>بعد</strong> تاريخ تركه (تاريخ ترك غلط أو رواتب وهمية). افتح ملف كل واحد وصحّح تاريخ الترك — البرنامج بيحذف الرواتب الزائدة تلقائياً عند الحفظ:
    <ul style="margin:8px 0 0;padding-inline-start:22px">
      <?php foreach ($anomalies as $a): ?>
        <li><strong><?= e($a['name']) ?></strong> (<?= e($a['school']) ?>) — تاريخ الترك <?= e($a['left']) ?> لكن عندو رواتب حتى سنة <?= e($a['until']) ?>-<?= e($a['until']+1) ?>
          <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $a['id'] ?>#tab-emploi" class="btn btn-sm btn-light" style="padding:1px 8px"><i class="fas fa-folder-open"></i> Corriger / صحّح</a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php /* جدول التاركين 8 أعمدة مطبوعة → A4 أفقي حتى يطلع الخط 12 كاملاً بلا تصغير (2026-08-04) */ ?>
<style>@media print{ @page{size:A4 landscape;margin:7mm} }</style>
<div class="card land-report">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h3><i class="fas fa-user-slash"></i>
      <span dir="ltr">Départs en <?= $selYear === 'all' ? 'toutes les années' : e($selYear) ?> (<?= $total ?>)</span>
      <div style="font-size:0.85em;font-weight:600;opacity:0.9">التاركون في <?= $selYear === 'all' ? 'كل السنوات' : e($selYear) ?> (<?= $total ?>)</div></h3>
    <form method="get" class="no-print" style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <?php /* 📅 (2026-10-18) الوضع: سنة مدرسية أو ميلادية — كبستان ملوّنتان (قاعدته: الاختيار القصير = خانات) */ ?>
      <div class="school-checks emp-type-checks" style="margin:0">
        <label class="chk"><input type="radio" name="ym" value="school" <?= $ym === 'school' ? 'checked' : '' ?> onchange="this.form.sy.value='';this.form.submit()" autocomplete="off"> <span>Année scolaire / سنة مدرسية (1/10 → 30/9)</span></label>
        <label class="chk"><input type="radio" name="ym" value="civil" <?= $ym === 'civil' ? 'checked' : '' ?> onchange="this.form.sy.value='';this.form.submit()" autocomplete="off"> <span>Année civile / سنة ميلادية (1/1 → 31/12)</span></label>
      </div>
      <label style="margin:0;font-weight:700"><?= $ym === 'civil' ? 'السنة الميلادية / Année civile' : 'السنة الدراسية / Année scolaire' ?> :</label>
      <select name="sy" onchange="this.form.submit()" style="padding:8px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px">
        <option value="all" <?= $selYear === 'all' ? 'selected' : '' ?>>كل السنوات / Toutes (<?= $grandTotal ?>)</option>
        <?php
          // اعرض السنة الافتراضية دائماً حتى لو ما فيها تاركين بعد
          $yearsForMenu = $availYears;
          if (!in_array($ltDefYear, $yearsForMenu, true)) { $yearsForMenu[] = $ltDefYear; rsort($yearsForMenu); }
          foreach ($yearsForMenu as $yy): ?>
          <option value="<?= e($yy) ?>" <?= $selYear === $yy ? 'selected' : '' ?>><?= e($yy) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body">
    <?php if (!$total): ?>
      <div class="empty-state"><i class="fas fa-user-check"></i>
        <h4><span dir="ltr">Aucun départ</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">لا يوجد تاركون في <?= $selYear === 'all' ? 'أي سنة' : e($selYear) ?></div></h4>
        <p>عند تسجيل تاريخ ترك لأي أستاذ في هذه السنة (من ملفه أو عبر رابط تحديث المعلومات) يظهر هنا مباشرةً.
        <?php if ($grandTotal > 0 && $selYear !== 'all'): ?><br>يوجد <strong><?= $grandTotal ?></strong> تارك في سنوات أخرى — اختر «كل السنوات» لعرضهم.<?php endif; ?></p></div>
    <?php else: foreach ($bySchool as $schoolName => $list): ?>
      <h4 style="color:#0a6b5e;border-bottom:2px solid #e5e7eb;padding-bottom:6px;margin:18px 0 10px">
        <i class="fas fa-school"></i> <?= e($schoolName) ?> <span class="badge badge-info"><?= count($list) ?></span>
      </h4>
      <div class="table-wrapper">
      <table class="table">
        <thead><tr>
          <th>#</th>
          <th>Nom / الاسم</th>
          <th>Catégorie / الفئة</th>
          <th>Départ définitif / ترك من الكل</th>
          <th>CNSS / ترك الضمان</th>
          <th>Finances / ترك المالية</th>
          <th>EOC / ترك الصندوق</th>
          <th>Tél. / الهاتف</th>
          <th class="no-print">Dossier / الملف</th>
        </tr></thead>
        <tbody>
        <?php $i = 0; foreach ($list as $emp): $i++;
          $nm = empFullNameAr($emp);
          $primary = $emp['_primary_ts']; // الأبكر بين تواريخ الترك (محسوب مسبقاً)
        ?>
          <tr>
            <td><?= $i ?></td>
            <td><strong><?= e($nm) ?></strong></td>
            <td><?= e(employeeTypeLabel($emp['employee_type'], 'ar')) ?></td>
            <td><?php if ($emp['_partial']): ?><span style="color:#92400e" title="ترك جهة فقط — الراتب والاسم مستمرّان">ترك جزئي / partiel</span><?php else: ?><strong style="color:#b91c1c"><?= $primary ? date('d/m/Y', $primary) : '—' ?></strong><?php endif; ?></td>
            <td><?= ltDate($emp['left_date_cnss']) ?></td>
            <td><?= ltDate($emp['left_date_finance']) ?></td>
            <td><?= ltDate($emp['left_date_eoc']) ?></td>
            <td><?= e($emp['phone1'] ?: $emp['phone2'] ?: '—') ?></td>
            <td class="no-print">
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-light" title="Ouvrir le dossier / فتح الملف">
                <i class="fas fa-folder-open"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
