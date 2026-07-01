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

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h3><i class="fas fa-user-slash"></i> التاركون في <?= $selYear === 'all' ? 'كل السنوات / Toutes les années' : e($selYear) ?> (<?= $total ?>)</h3>
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
        <h4>لا يوجد تاركون في <?= $selYear === 'all' ? 'أي سنة' : e($selYear) ?> / Aucun départ</h4>
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
          <th>الاسم / Nom</th>
          <th>الفئة / Catégorie</th>
          <th>تاريخ الترك / Date de départ</th>
          <th>ترك الضمان / CNSS</th>
          <th>ترك المالية / Finances</th>
          <th>ترك الصندوق / EOC</th>
          <th>الهاتف / Tél.</th>
          <th class="no-print">الملف</th>
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
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-light" title="فتح الملف">
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
