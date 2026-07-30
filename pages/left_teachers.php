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

// كل التاركين ضمن نطاق المدرسة: عنده أيّ تاريخ ترك. مرتَّبون حسب المدرسة ثم الفئة ثم الاسم.
$sql = "SELECT e.*, COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
        FROM employees e LEFT JOIN schools sc ON sc.id = e.school_id
        WHERE e.is_deleted = 0
          AND (e.left_date_cnss IS NOT NULL OR e.left_date_finance IS NOT NULL OR e.left_date_eoc IS NOT NULL)"
     . schoolScopeSql('e.school_id')
     . " ORDER BY school_name,
         FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
         COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
$allRows = $db->query($sql)->fetchAll();

// تاريخ الترك الأساسي لكل صفّ = الأبكر بين تواريخه، والسنة الدراسية المشتقّة منه
foreach ($allRows as &$r) {
    $ds = array_filter([$r['left_date_cnss'], $r['left_date_finance'], $r['left_date_eoc']],
            fn($d) => !empty($d) && $d !== '0000-00-00');
    $r['_primary_ts'] = $ds ? min(array_map('strtotime', $ds)) : 0;
    $r['_sy'] = $r['_primary_ts'] ? schoolYearOfDate(date('Y-m-d', $r['_primary_ts'])) : null;
}
unset($r);

// السنوات الدراسية المتوفّرة في البيانات (تنازلياً) لبناء الفلتر
$availYears = [];
foreach ($allRows as $r) { if ($r['_sy']) $availYears[$r['_sy']] = true; }
$availYears = array_keys($availYears);
rsort($availYears);

// الفلتر: السنة المختارة (افتراضياً السنة الدراسية الحالية 2025-2026)، أو "all" لكل السنوات
$selYear = (string)($_GET['sy'] ?? currentSchoolYear());
if ($selYear !== 'all' && !preg_match('/^\d{4}-\d{4}$/', $selYear)) $selYear = currentSchoolYear();

// طبّق الفلتر
$rows = ($selYear === 'all') ? $allRows : array_values(array_filter($allRows, fn($r) => $r['_sy'] === $selYear));

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
        if (!$r['_primary_ts']) continue;
        $ly = (int)date('Y', $r['_primary_ts']); $lm = (int)date('n', $r['_primary_ts']);
        $depRank = ($lm >= 10) ? $ly : $ly - 1;
        $mr = $maxRank[(int)$r['id']] ?? null;
        if ($mr !== null && $mr > $depRank) {
            $nm = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
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
  <i class="fas fa-door-open"></i> لائحة <strong>الأساتذة والموظفين الذين تركوا العمل</strong> (سُجِّل لهم تاريخ ترك عبر ملف الأستاذ أو عبر رابط تحديث المعلومات) — مصنّفة <strong>حسب السنة الدراسية</strong> التي وقع فيها الترك. الافتراضي: السنة الحالية <strong><?= e(currentSchoolYear()) ?></strong>.<br>
  <span dir="ltr">Liste des enseignants / employés ayant quitté, classée par année scolaire du départ. Par défaut : l'année en cours.</span>
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

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h3><i class="fas fa-user-slash"></i>
      <span dir="ltr">Départs en <?= $selYear === 'all' ? 'toutes les années' : e($selYear) ?> (<?= $total ?>)</span>
      <div style="font-size:0.85em;font-weight:600;opacity:0.9">التاركون في <?= $selYear === 'all' ? 'كل السنوات' : e($selYear) ?> (<?= $total ?>)</div></h3>
    <form method="get" class="no-print" style="margin:0;display:flex;align-items:center;gap:8px">
      <label style="margin:0;font-weight:700">السنة الدراسية / Année :</label>
      <select name="sy" onchange="this.form.submit()" style="padding:8px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px">
        <option value="all" <?= $selYear === 'all' ? 'selected' : '' ?>>كل السنوات / Toutes (<?= $grandTotal ?>)</option>
        <?php
          // اعرض السنة الحالية دائماً حتى لو ما فيها تاركين بعد
          $yearsForMenu = $availYears;
          if (!in_array(currentSchoolYear(), $yearsForMenu, true)) { $yearsForMenu[] = currentSchoolYear(); rsort($yearsForMenu); }
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
          <th>Date de départ / تاريخ الترك</th>
          <th>CNSS / ترك الضمان</th>
          <th>Finances / ترك المالية</th>
          <th>EOC / ترك الصندوق</th>
          <th>Tél. / الهاتف</th>
          <th class="no-print">Dossier / الملف</th>
        </tr></thead>
        <tbody>
        <?php $i = 0; foreach ($list as $emp): $i++;
          $nm = trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: trim($emp['first_name_fr'].' '.$emp['last_name_fr']);
          $primary = $emp['_primary_ts']; // الأبكر بين تواريخ الترك (محسوب مسبقاً)
        ?>
          <tr>
            <td><?= $i ?></td>
            <td><strong><?= e($nm) ?></strong></td>
            <td><?= e(employeeTypeLabel($emp['employee_type'], 'ar')) ?></td>
            <td><strong style="color:#b91c1c"><?= $primary ? date('d/m/Y', $primary) : '—' ?></strong></td>
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
