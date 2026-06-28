<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();

$currentPage = 'law_check';
$pageTitle = 'فحص مطابقة القانون / Conformité légale';
$db = getDB();

// نطاق الفحص: ?school=ID لمدرسة محددة، وإلا المدارس المختارة من الأعلى، وإلا كل المدارس (للمدير العام).
$reqSchool = isset($_GET['school']) ? (int)$_GET['school'] : 0;
if ($reqSchool > 0) {
    // المدير العام يقدر يفحص أي مدرسة؛ غيره فقط مدرسته.
    $allowed = isSuperAdmin() ? true : in_array($reqSchool, activeSchoolIds(), true);
    $scopeIds = $allowed ? [$reqSchool] : activeSchoolIds();
} else {
    $scopeIds = activeSchoolIds(); // [] = كل المدارس (مدير عام بوضع الكل)
}
$scopeIdsForCheck = !empty($scopeIds) ? $scopeIds : null; // null = الكل

$results = lawConsistencyCheck($scopeIdsForCheck);

// تجميع حسب المدرسة + إجماليات
$total = count($results);
$okCount = 0; $badCount = 0; $errCount = 0;
$bySchool = []; // school_id => ['ok'=>, 'bad'=>, 'err'=>, 'rows'=>[]]
foreach ($results as $r) {
    $sid = $r['school_id'];
    if (!isset($bySchool[$sid])) $bySchool[$sid] = ['ok'=>0,'bad'=>0,'err'=>0,'rows'=>[]];
    if ($r['err'] !== null) { $errCount++; $bySchool[$sid]['err']++; }
    elseif ($r['ok']) { $okCount++; $bySchool[$sid]['ok']++; }
    else { $badCount++; $bySchool[$sid]['bad']++; }
    if (!$r['ok'] || $r['err'] !== null) $bySchool[$sid]['rows'][] = $r; // نخزّن المنحرفين فقط للعرض
}

// قائمة المدارس للأزرار (مدير عام يرى الكل)
$schoolsList = allSchools();

$exportTitle = 'فحص_مطابقة_القانون';
include __DIR__ . '/../includes/header.php';
?>
<div id="pageContent">

  <div class="card">
    <div class="card-header">
      <h3><i class="fas fa-balance-scale"></i> فحص مطابقة القانون / Conformité légale</h3>
    </div>
    <div class="card-body">
      <p class="text-muted" style="margin-top:0">
        يقارن البرنامج درجة كل أستاذ ملاك <strong>المحسوبة من القوانين</strong> بالدرجة المخزّنة.
        ✅ = الراتب مبنيّ على القانون بالضبط. ⚠️ = انحراف يستوجب المراجعة (غالباً قانون خاص أو إدخال يدوي).
        الفحص <strong>قراءة فقط — لا يعدّل أي بيانات</strong>.
      </p>

      <!-- أزرار اختيار النطاق -->
      <div class="no-print" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:6px">
        <?php if (isSuperAdmin()): ?>
          <a href="?" class="btn btn-sm <?= $reqSchool===0 ? 'btn-primary' : 'btn-light' ?>">
            <i class="fas fa-globe"></i> كل المدارس
          </a>
          <?php foreach ($schoolsList as $s): ?>
            <a href="?school=<?= (int)$s['id'] ?>" class="btn btn-sm <?= $reqSchool===(int)$s['id'] ? 'btn-primary' : 'btn-light' ?>">
              <i class="fas fa-school"></i> <?= e($s['name_ar'] ?: $s['name_fr']) ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- بطاقات الإجمالي -->
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:8px">
        <div class="stat-box" style="flex:1;min-width:140px;background:#eef6ff;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700"><?= $total ?></div>
          <div class="text-muted">أساتذة ملاك مفحوصين</div>
        </div>
        <div class="stat-box" style="flex:1;min-width:140px;background:#e9f9ee;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700;color:#1a7f37">✅ <?= $okCount ?></div>
          <div class="text-muted">مطابق للقانون</div>
        </div>
        <div class="stat-box" style="flex:1;min-width:140px;background:<?= $badCount? '#fdecec':'#f3f4f6' ?>;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700;color:<?= $badCount? '#c0392b':'#888' ?>">⚠️ <?= $badCount ?></div>
          <div class="text-muted">منحرف عن القانون</div>
        </div>
        <?php if ($errCount): ?>
        <div class="stat-box" style="flex:1;min-width:140px;background:#fff7e6;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700;color:#b8860b"><?= $errCount ?></div>
          <div class="text-muted">تعذّر الحساب</div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($total > 0 && $badCount === 0 && $errCount === 0): ?>
        <div class="alert alert-success" style="margin-top:10px">
          <i class="fas fa-check-circle"></i>
          كل الأساتذة (<?= $okCount ?>) درجاتهم مبنيّة على القانون بالضبط — البرنامج سليم ١٠٠٪.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تفصيل لكل مدرسة (عند فحص أكثر من مدرسة) -->
  <?php if (count($bySchool) > 1): ?>
  <div class="card">
    <div class="card-header"><h3><i class="fas fa-school"></i> ملخّص لكل مدرسة</h3></div>
    <div class="card-body">
      <table class="table">
        <thead><tr><th>المدرسة</th><th style="text-align:center">المجموع</th><th style="text-align:center">✅ مطابق</th><th style="text-align:center">⚠️ منحرف</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bySchool as $sid => $b): $tot=$b['ok']+$b['bad']+$b['err']; ?>
          <tr style="<?= $b['bad']||$b['err'] ? 'background:#fff6f6' : '' ?>">
            <td><strong><?= e(schoolNameById($sid, 'ar')) ?></strong></td>
            <td style="text-align:center"><?= $tot ?></td>
            <td style="text-align:center;color:#1a7f37"><?= $b['ok'] ?></td>
            <td style="text-align:center;color:<?= $b['bad']? '#c0392b':'#888' ?>;font-weight:<?= $b['bad']?'700':'400' ?>"><?= $b['bad'] + $b['err'] ?></td>
            <td style="text-align:center"><a href="?school=<?= (int)$sid ?>" class="btn btn-sm btn-light no-print"><i class="fas fa-search"></i> افتح</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- قائمة المنحرفين (إن وُجدوا) -->
  <?php if ($badCount > 0 || $errCount > 0): ?>
  <div class="card">
    <div class="card-header"><h3><i class="fas fa-triangle-exclamation"></i> الأساتذة المنحرفون عن القانون (<?= $badCount + $errCount ?>)</h3></div>
    <div class="card-body">
      <table class="table">
        <thead><tr>
          <th>#</th><th>الاسم</th><th>المدرسة</th><th>الشهادة</th>
          <th style="text-align:center">المخزّن</th><th style="text-align:center">القانون</th><th style="text-align:center">الفجوة</th><th class="no-print"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($results as $r): if ($r['ok'] && $r['err']===null) continue; ?>
          <tr style="background:#fff6f6">
            <td><?= $r['id'] ?></td>
            <td><strong><?= e($r['name_ar'] ?: $r['name_fr']) ?></strong></td>
            <td><?= e(schoolNameById($r['school_id'], 'ar')) ?></td>
            <td><?= e(diplomaLabel($r['diploma'], 'ar')) ?></td>
            <td style="text-align:center"><span class="badge badge-gold"><?= $r['stored'] ?></span></td>
            <td style="text-align:center">
              <?= $r['err']!==null ? '<span class="text-muted">—</span>' : '<span class="badge" style="background:#2e86de;color:#fff">'.$r['law'].'</span>' ?>
            </td>
            <td style="text-align:center;font-weight:700;color:<?= ($r['gap']??0)>0 ? '#1a7f37':'#c0392b' ?>">
              <?= $r['err']!==null ? 'خطأ' : ($r['gap']>0?'+':'').$r['gap'] ?>
            </td>
            <td class="no-print" style="text-align:center">
              <a href="<?= BASE_URL ?>pages/grades.php?employee_id=<?= $r['id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-up-right-from-square"></i> ملف الدرجات</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="text-muted" style="font-size:13px">
        <strong>الفجوة</strong> = (درجة القانون − المخزّن). موجبة: القانون يعطي أعلى (قد ينقص راتب الأستاذ).
        سالبة: المخزّن أعلى (غالباً قانون 344 يدوي أو وضع خاص). افتح ملف الدرجات لمراجعته أو إعادة بنائه حسب القانون.
      </p>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
