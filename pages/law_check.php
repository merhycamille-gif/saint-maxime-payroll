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
      <h3><i class="fas fa-balance-scale"></i> <span dir="ltr">Conformité légale</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">فحص مطابقة القانون</div></h3>
    </div>
    <div class="card-body">
      <p class="text-muted" style="margin-top:0">
        يقارن البرنامج درجة كل أستاذ ملاك <strong>المحسوبة من القوانين</strong> بالدرجة المخزّنة.
        ✅ = الراتب مبنيّ على القانون بالضبط. ⚠️ = انحراف يستوجب المراجعة (غالباً قانون خاص أو إدخال يدوي).
        الفحص <strong>قراءة فقط — لا يعدّل أي بيانات</strong>.
        <br><i class="fas fa-calendar-alt" style="color:#d97706"></i>
        <?php $lcYear = activeSchoolYear(); ?>
        <strong><?= $lcYear === 'all' ? 'Toutes années / كل السنين (يشمل التاركين القدامى)' : 'Année / السنة المفحوصة: ' . e($lcYear) ?></strong>
        — يُحتسب فقط الأساتذة الموجودون فعلاً بهذه السنة (التارك لا يظهر بعد سنة تركه). بدّل السنة من الأعلى.
      </p>

      <!-- أزرار اختيار النطاق -->
      <div class="no-print" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:6px">
        <?php if (isSuperAdmin()): ?>
          <a href="?" class="btn btn-sm <?= $reqSchool===0 ? 'btn-primary' : 'btn-light' ?>">
            <i class="fas fa-globe"></i> Toutes les écoles / كل المدارس
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
          <div class="text-muted">Titulaires vérifiés / أساتذة ملاك مفحوصين</div>
        </div>
        <div class="stat-box" style="flex:1;min-width:140px;background:#e9f9ee;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700;color:#1a7f37">✅ <?= $okCount ?></div>
          <div class="text-muted">Conforme / مطابق للقانون</div>
        </div>
        <div class="stat-box" style="flex:1;min-width:140px;background:<?= $badCount? '#fdecec':'#f3f4f6' ?>;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700;color:<?= $badCount? '#c0392b':'#888' ?>">⚠️ <?= $badCount ?></div>
          <div class="text-muted">Non conforme / منحرف عن القانون</div>
        </div>
        <?php if ($errCount): ?>
        <div class="stat-box" style="flex:1;min-width:140px;background:#fff7e6;border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:26px;font-weight:700;color:#b8860b"><?= $errCount ?></div>
          <div class="text-muted">Calcul impossible / تعذّر الحساب</div>
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

  <?php
  /* =====================================================================
   * 🔍 تدقيق سلامة الأرقام المخزَّنة (2026-07-30)
   * «الأرقام تركب» تنطبق على البيانات نفسها لا العرض فقط: نكشف الصفوف التي
   * لا تُفسِّر مكوّناتُها مجموعَها (رواتب منقولة/مستوردة أُدخلت كمبلغ واحد بلا
   * تفصيل المحسومات). لا نخمّن أرقاماً ولا نعدّل شيئاً — نعرضها ليصحّحها المستخدم.
   * =================================================================== */
  $auditYear = ($lcYear === 'all') ? currentSchoolYear() : $lcYear;
  $scAud = schoolScopeSql('e.school_id');
  $audSt = $db->prepare("SELECT ms.employee_id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
             e.employee_type, e.school_id, COUNT(*) n,
             MAX(ABS(ms.total_retenues_lbp - (ms.cnss_amount_lbp + ms.caisse_amount_lbp + ms.income_tax_lbp + ms.eoc_grade_lbp))) gapDed,
             MAX(ABS(ms.net_salary_lbp - ((ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) - ms.total_retenues_lbp))) gapNet
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
       WHERE e.is_deleted = 0 AND ms.school_year = ?" . $scAud . "
         AND (ABS(ms.total_retenues_lbp - (ms.cnss_amount_lbp + ms.caisse_amount_lbp + ms.income_tax_lbp + ms.eoc_grade_lbp)) > 1
              OR ABS(ms.net_salary_lbp - ((ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) - ms.total_retenues_lbp)) > 1)
       GROUP BY ms.employee_id ORDER BY gapDed DESC, gapNet DESC");
  $audSt->execute([$auditYear]);
  $audRows = $audSt->fetchAll();
  ?>
  <div class="card">
    <div class="card-header"><h3>
      <i class="fas fa-calculator"></i> <span dir="ltr">Cohérence des montants stockés</span>
      <div style="font-size:0.85em;font-weight:600;opacity:0.9">تدقيق سلامة الأرقام المخزَّنة — <?= e($auditYear) ?></div>
    </h3></div>
    <div class="card-body">
      <?php if (!$audRows): ?>
        <div class="alert alert-success" style="margin:0">
          <i class="fas fa-check-circle"></i>
          كل الأرقام المخزَّنة متماسكة: مكوّنات المحسومات تساوي مجموعها، والصافي = الإجمالي − المحسومات في كل الصفوف.
        </div>
      <?php else: ?>
        <div class="alert alert-warning">
          <i class="fas fa-triangle-exclamation"></i>
          <strong><?= count($audRows) ?></strong> موظف عنده صفوف رواتب <strong>مبالغها لا تُفسِّرها مكوّناتها</strong> —
          غالباً رواتب «منقولة» أُدخلت كمبلغ واحد (صافي/مستحق) بلا تفصيل الأساس والمحسومات، فتظهر الضريبة أو الصندوق صفراً
          مع أنّ المحسوم أكبر. التقارير تعرض المخزَّن كما هو، فيبقى «مجموع المحسومات» أكبر من مجموع أعمدته.
          <br><strong>ما العمل:</strong> افتح ملف الأستاذ وأدخِل إعداد راتبه (الأساس/العقد) ثم اضغط «احتساب السنة» ليُبنى راتبه من القانون بمكوّنات كاملة.
          <span class="text-muted">(البرنامج لا يخمّن أرقاماً ولا يعدّلها من تلقائه.)</span>
        </div>
        <table class="table">
          <thead><tr>
            <th>Nom / الاسم</th><th>Catégorie / الفئة</th><th>École / المدرسة</th>
            <th style="text-align:center">Mois / أشهر</th>
            <th style="text-align:center">Écart déductions / فجوة المحسومات</th>
            <th style="text-align:center">Écart net / فجوة الصافي</th>
            <th class="no-print"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($audRows as $ar): ?>
            <tr>
              <td><strong><?= e(trim($ar['first_name_ar'] . ' ' . $ar['last_name_ar']) ?: trim($ar['first_name_fr'] . ' ' . $ar['last_name_fr'])) ?></strong></td>
              <td><small><?= e(employeeTypeLabel($ar['employee_type'])) ?></small></td>
              <td><small><?= e(schoolNameById((int)$ar['school_id'], 'ar')) ?></small></td>
              <td style="text-align:center"><?= (int)$ar['n'] ?></td>
              <td style="text-align:center;color:<?= (float)$ar['gapDed'] > 1 ? '#c0392b' : '#888' ?>"><?= (float)$ar['gapDed'] > 1 ? formatLBP($ar['gapDed']) : '—' ?></td>
              <td style="text-align:center;color:<?= (float)$ar['gapNet'] > 1 ? '#c0392b' : '#888' ?>"><?= (float)$ar['gapNet'] > 1 ? formatLBP($ar['gapNet']) : '—' ?></td>
              <td class="no-print" style="text-align:center">
                <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$ar['employee_id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-user-pen"></i> ملف الأستاذ</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- تفصيل لكل مدرسة (عند فحص أكثر من مدرسة) -->
  <?php if (count($bySchool) > 1): ?>
  <div class="card">
    <div class="card-header"><h3><i class="fas fa-school"></i> <span dir="ltr">Résumé par école</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">ملخّص لكل مدرسة</div></h3></div>
    <div class="card-body">
      <table class="table">
        <thead><tr><th>École / المدرسة</th><th style="text-align:center">Total / المجموع</th><th style="text-align:center">✅ Conforme / مطابق</th><th style="text-align:center">⚠️ Non conforme / منحرف</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bySchool as $sid => $b): $tot=$b['ok']+$b['bad']+$b['err']; ?>
          <tr style="<?= $b['bad']||$b['err'] ? 'background:#fff6f6' : '' ?>">
            <td><strong><?= e(schoolNameById($sid, 'ar')) ?></strong></td>
            <td style="text-align:center"><?= $tot ?></td>
            <td style="text-align:center;color:#1a7f37"><?= $b['ok'] ?></td>
            <td style="text-align:center;color:<?= $b['bad']? '#c0392b':'#888' ?>;font-weight:<?= $b['bad']?'700':'400' ?>"><?= $b['bad'] + $b['err'] ?></td>
            <td style="text-align:center"><a href="?school=<?= (int)$sid ?>" class="btn btn-sm btn-light no-print"><i class="fas fa-search"></i> Ouvrir / افتح</a></td>
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
    <div class="card-header"><h3><i class="fas fa-triangle-exclamation"></i> <span dir="ltr">Enseignants non conformes (<?= $badCount + $errCount ?>)</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">الأساتذة المنحرفون عن القانون (<?= $badCount + $errCount ?>)</div></h3></div>
    <div class="card-body">
      <table class="table">
        <thead><tr>
          <th>#</th><th>Nom / الاسم</th><th>École / المدرسة</th><th>Diplôme / الشهادة</th>
          <th style="text-align:center">Stocké / المخزّن</th><th style="text-align:center">Loi / القانون</th><th style="text-align:center">Écart / الفجوة</th><th class="no-print"></th>
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
