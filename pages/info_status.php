<?php
/**
 * تقرير حالة تحديث معلومات الأساتذة — لكل مدرسة:
 *   1) مين بعت وحدّث معلوماته (مع حالة: مقبول ومحدَّث / بعت وبانتظار اعتمادك).
 *   2) مين ما بعت بعد (أساتذة السنة الحالية بلا أي طلب).
 *   3) مين الأساتذة الجدد (طلبات «أنا أستاذ جديد» — نُشئ ملفهم أو لسا بانتظار).
 * يعتمد على جدول info_submissions (pending/applied/rejected + is_new_teacher).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$currentPage = 'info_status';
$pageTitle = 'État des mises à jour / حالة تحديث معلومات الأساتذة';
$db = getDB();

// تنسيق تاريخ/وقت مقروء (أو — إن فارغ)
function isDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : e($d);
}

// فلتر المدارس الفعّالة فقط (مثل باقي البرنامج allSchools(): is_active=1 AND is_deleted=0).
// المدارس المعطّلة/المحذوفة (مثل مغدوشة/سان نيقولا) لا تظهر في هذا التقرير.
$activeSchoolCond = " AND sc.is_active = 1 AND sc.is_deleted = 0";

// 🗓️ فلتر «سنة التحديث» (طلب المستخدم 2026-08-27: «يبينو الأساتذة اللي بدون تحديث بنفس
// السنة أو أنا كمان اختار السنة»): التحديث يُحسب للسنة المختارة فقط — يلي بعت السنة الماضية
// وما بعت هالسنة يظهر مع «ما بعتوا». نافذة سنة التحديث Y1-Y2 = من 1 تموز Y1 حتى 30 حزيران Y2:
// تحديثات الصيف (تموز-أيلول) تُحسب للسنة الجاية يلي عم تتحضّر لا للسنة يلي عم تخلص
// (مثال: إرسال آب 2026 = حملة 2026-2027). و«كل السنين» = السلوك القديم (أي إرسال بالتاريخ كله).
function updateYearOfToday() {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 7) ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);
}
$selYear = $_GET['sy'] ?? updateYearOfToday();
if ($selYear !== 'all' && !preg_match('/^\d{4}-\d{4}$/', $selYear)) $selYear = updateYearOfToday();
$syCond = ''; $syParams = [];
if ($selYear !== 'all') {
    [$sy1, $sy2] = schoolYearToYears($selYear);
    $syCond = " AND s.submitted_at >= ? AND s.submitted_at < ?";
    $syParams = [$sy1 . '-07-01 00:00:00', $sy2 . '-07-01 00:00:00'];
}
// لائحة السنين للمنتقي: سنين الرواتب الموجودة + سنة الحملة الحالية (بلا مكرّر، الأحدث أولاً)
$yearOptions = $db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE school_year REGEXP '^[0-9]{4}-[0-9]{4}$' ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
foreach ([updateYearOfToday(), $selYear === 'all' ? null : $selYear] as $extraY)
    if ($extraY && !in_array($extraY, $yearOptions, true)) { $yearOptions[] = $extraY; rsort($yearOptions); }
$selYearLabel = ($selYear === 'all') ? 'Toutes les années / كل السنين' : $selYear;

// 1) أساتذة/موظفو السنة المختارة في المدارس الفعّالة ضمن النطاق (غير محذوفين، موجودون فعلاً بهذه السنة، غير تاركين)
[$yf, $yp] = yearEmploymentFilter($selYear !== 'all' ? $selYear : activeSchoolYear(), 'e.');
$activeSql = "SELECT e.id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
        e.employee_type, e.phone1, e.phone2, e.school_id,
        COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
    FROM employees e JOIN schools sc ON sc.id = e.school_id
    WHERE e.is_deleted = 0" . $activeSchoolCond . schoolScopeSql('e.school_id') . $yf . "
    ORDER BY school_name,
        FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
        COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
$st = $db->prepare($activeSql);
$st->execute($yp);
$active = $st->fetchAll();

// 2) كل من بعت طلباً (موجود، applied أو pending) **ضمن سنة التحديث المختارة** في المدارس
//    الفعّالة ضمن النطاق — نقودها من جدول الطلبات مباشرةً حتى يظهر **كل** من بعت بهذه السنة.
$rank = ['applied' => 2, 'pending' => 1];
$sentByEmp = []; // employee_id => أفضل صفّ طلب (مع اسم الأستاذ ومدرسته)
$sq = $db->prepare("SELECT s.status, s.submitted_at, e.id, e.first_name_ar, e.last_name_ar,
        e.first_name_fr, e.last_name_fr, e.employee_type,
        COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
    FROM info_submissions s
    JOIN employees e ON e.id = s.employee_id
    JOIN schools sc ON sc.id = e.school_id
    WHERE s.is_new_teacher = 0 AND s.status IN ('applied','pending') AND e.is_deleted = 0"
    . $syCond . $activeSchoolCond . schoolScopeSql('e.school_id') . "
    ORDER BY s.submitted_at");
$sq->execute($syParams);
foreach ($sq->fetchAll() as $r) {
    $eid = (int)$r['id'];
    $cur = $sentByEmp[$eid] ?? null;
    $better = !$cur
        || ($rank[$r['status']] ?? 0) > ($rank[$cur['status']] ?? 0)
        || (($rank[$r['status']] ?? 0) === ($rank[$cur['status']] ?? 0) && ($r['submitted_at'] ?? '') >= ($cur['submitted_at'] ?? ''));
    if ($better) $sentByEmp[$eid] = $r;
}

// صنّف حسب المدرسة: بعتوا (من الطلبات) + ما بعتوا (أساتذة السنة الحالية بلا طلب)
$bySchool = []; // school_name => ['sent'=>[], 'notsent'=>[]]
$ensureSchool = function (&$arr, $k) { if (!isset($arr[$k])) $arr[$k] = ['sent' => [], 'notsent' => []]; };
foreach ($sentByEmp as $eid => $r) {
    $k = $r['school_name'] ?: '—';
    $ensureSchool($bySchool, $k);
    $bySchool[$k]['sent'][] = $r;
}
foreach ($active as $emp) {
    if (isset($sentByEmp[(int)$emp['id']])) continue; // بعت → مذكور ضمن «بعتوا»
    $k = $emp['school_name'] ?: '—';
    $ensureSchool($bySchool, $k);
    $bySchool[$k]['notsent'][] = $emp;
}

// 3) طلبات الأساتذة الجدد في المدارس الفعّالة ضمن النطاق (نُشئ ملفهم = applied مع employee_id، أو لسا pending)
$newRows = $db->prepare("SELECT s.id, s.employee_id, s.school_id, s.status, s.submitted_at, s.applied_at, s.data,
        COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name,
        e.first_name_ar e_fna, e.last_name_ar e_lna, e.first_name_fr e_fnf, e.last_name_fr e_lnf,
        e.hire_date, e.employee_code
    FROM info_submissions s
    JOIN schools sc ON sc.id = s.school_id
    LEFT JOIN employees e ON e.id = s.employee_id
    WHERE s.is_new_teacher = 1 AND s.status <> 'rejected'" . $syCond . $activeSchoolCond . schoolScopeSql('s.school_id') . "
    ORDER BY school_name, FIELD(s.status,'pending','applied'), s.submitted_at DESC");
$newRows->execute($syParams);
$newRows = $newRows->fetchAll();

$newBySchool = [];
foreach ($newRows as $r) {
    $k = $r['school_name'] ?: '—';
    $newBySchool[$k][] = $r;
}

// وحّد قائمة المدارس (قد يكون فيها أساتذة جدد بلا نشاط تحديث والعكس) بترتيب أبجدي
$schoolKeys = array_keys($bySchool);
foreach (array_keys($newBySchool) as $k) if (!in_array($k, $schoolKeys, true)) $schoolKeys[] = $k;
sort($schoolKeys);

// إجماليات عامة
$gSent = 0; $gNot = 0; $gPending = 0; $gNew = 0;
foreach ($bySchool as $b) {
    $gNot += count($b['notsent']);
    foreach ($b['sent'] as $e) { $gSent++; if (($e['status'] ?? '') === 'pending') $gPending++; }
}
foreach ($newBySchool as $list) $gNew += count($list);

// اسم أستاذ جديد من ملفه إن نُشئ، وإلا من الطلب المرسَل
function newTeacherName($r) {
    $nm = trim(($r['e_fna'] ?? '') . ' ' . ($r['e_lna'] ?? '')) ?: trim(($r['e_fnf'] ?? '') . ' ' . ($r['e_lnf'] ?? ''));
    if ($nm !== '') return $nm;
    $d = json_decode($r['data'] ?: '{}', true) ?: [];
    return trim(($d['first_name_ar'] ?? '') . ' ' . ($d['last_name_ar'] ?? ''))
        ?: (trim(($d['first_name_fr'] ?? '') . ' ' . ($d['last_name_fr'] ?? '')) ?: '(بلا اسم)');
}

// فلتر العرض: الكل / بعتوا فقط / ما بعتوا فقط / جدد فقط — ليطبع المدير كل مجموعة لحالها
$show = $_GET['show'] ?? 'all';
if (!in_array($show, ['all', 'sent', 'notsent', 'new'], true)) $show = 'all';
$showSent = ($show === 'all' || $show === 'sent');
$showNot  = ($show === 'all' || $show === 'notsent');
$showNew  = ($show === 'all' || $show === 'new');
// عناوين المجموعات: الفرنسي أولاً ثم العربي (تُعرَض كـ«فرنسي فوق / عربي تحت» في العناوين)
$showLabels    = ['all' => 'Tout / الكل', 'sent' => 'Ont mis à jour / اللي حدّثوا وبعتوا', 'notsent' => "N'ont pas envoyé / اللي ما بعتوا", 'new' => 'Nouveaux enseignants / الأساتذة الجدد'];
$showLabelsFr  = ['all' => 'Tout', 'sent' => 'Ont envoyé et mis à jour', 'notsent' => "N'ont pas encore envoyé", 'new' => 'Nouveaux enseignants'];
$showLabelsAr  = ['all' => 'الكل', 'sent' => 'اللي حدّثوا وبعتوا', 'notsent' => 'اللي ما بعتوا', 'new' => 'الأساتذة الجدد'];

// المدارس التي فيها محتوى ضمن الفلتر المختار (حتى لا تظهر مدرسة فارغة عند فلترة مجموعة واحدة)
$renderKeys = [];
foreach ($schoolKeys as $sk) {
    $hasS = $showSent && !empty($bySchool[$sk]['sent']);
    $hasN = $showNot  && !empty($bySchool[$sk]['notsent']);
    $hasW = $showNew  && !empty($newBySchool[$sk]);
    if ($hasS || $hasN || $hasW) $renderKeys[] = $sk;
}

include __DIR__ . '/../includes/header.php';
?>
<div class="alert alert-info no-print" style="margin-bottom:14px">
  <span dir="ltr"><i class="fas fa-clipboard-check"></i> <strong>État des mises à jour</strong> par école : qui a envoyé et mis à jour, qui n'a pas encore envoyé, et les nouveaux enseignants — année <strong><?= e($selYearLabel) ?></strong>.</span>
  <br>تقرير <strong>حالة تحديث المعلومات</strong> لكل مدرسة <strong>للسنة المختارة</strong>: مين <strong>بعت وحدّث</strong> معلوماته بهذه السنة (مقبول أو بانتظار اعتمادك)، مين <strong>ما بعت بهذه السنة</strong> (ولو بعت بسنة قبل)، ومين <strong>الأساتذة الجدد</strong>.
</div>

<div class="card no-print" style="margin-bottom:14px">
  <div class="card-body">
    <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:center">
      <div><span class="badge badge-success" style="font-size:14px"><i class="fas fa-check"></i> Ont mis à jour / بعتوا وحدّثوا</span> <strong style="font-size:20px"><?= $gSent ?></strong>
        <?php if ($gPending): ?><span style="color:#b45309">(منهم <?= $gPending ?> بانتظار اعتمادك / en attente)</span><?php endif; ?></div>
      <div><span class="badge badge-warning" style="font-size:14px"><i class="fas fa-hourglass-half"></i> N'ont pas envoyé / ما بعتوا</span> <strong style="font-size:20px"><?= $gNot ?></strong></div>
      <div><span class="badge" style="background:#0a7a37;color:#fff;font-size:14px"><i class="fas fa-user-plus"></i> Nouveaux / جدد</span> <strong style="font-size:20px"><?= $gNew ?></strong></div>
      <?php /* 🧹 زرّ «طباعة» أُزيل — مكرّر مع شريط التصدير فوق (قاعدة المستخدم: لا أزرار مكرّرة) */ ?>
    </div>
    <div style="margin-top:12px;border-top:1px solid var(--gray-200);padding-top:12px;display:flex;gap:8px 16px;flex-wrap:wrap;align-items:center">
      <span style="font-weight:700;color:var(--gray-600)"><i class="fas fa-calendar-days"></i> Année de mise à jour / سنة التحديث:</span>
      <form method="GET" style="display:inline-flex;align-items:center;gap:6px;margin:0">
        <input type="hidden" name="show" value="<?= e($show) ?>">
        <select name="sy" class="form-select" style="width:auto;min-width:170px" onchange="this.form.submit()">
          <?php foreach ($yearOptions as $yOpt): ?>
            <option value="<?= e($yOpt) ?>" <?= $selYear === $yOpt ? 'selected' : '' ?>><?= e($yOpt) ?></option>
          <?php endforeach; ?>
          <option value="all" <?= $selYear === 'all' ? 'selected' : '' ?>>Toutes les années / كل السنين</option>
        </select>
      </form>
      <?php if ($selYear !== 'all'): ?>
        <small style="color:var(--gray-500)">التحديث محسوب من 1/7/<?= (int)$sy1 ?> حتى 30/6/<?= (int)$sy2 ?> (إرسال الصيف يُحسب للسنة الجاية)</small>
      <?php endif; ?>
    </div>
    <div style="margin-top:12px;border-top:1px solid var(--gray-200);padding-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span style="font-weight:700;color:var(--gray-600)"><i class="fas fa-filter"></i> Afficher / imprimer — اعرض / اطبع:</span>
      <?php foreach ($showLabels as $key => $lbl):
        $on = ($show === $key);
        $icon = ['all'=>'fa-list','sent'=>'fa-check','notsent'=>'fa-hourglass-half','new'=>'fa-user-plus'][$key];
      ?>
        <a href="<?= BASE_URL ?>pages/info_status.php?show=<?= $key ?>&sy=<?= e($selYear) ?>"
           class="btn btn-sm <?= $on ? 'btn-success' : 'btn-light' ?>"><i class="fas <?= $icon ?>"></i> <?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($show !== 'all'): ?>
<div class="print-only" style="text-align:center;margin-bottom:10px">
  <h2 style="margin:0" dir="ltr"><?= e($showLabelsFr[$show]) ?> — <?= e($selYearLabel) ?></h2>
  <div style="font-size:0.8em;color:#444"><?= e($showLabelsAr[$show]) ?></div>
</div>
<?php endif; ?>

<?php if (!$renderKeys): ?>
  <div class="card"><div class="card-body">
    <div class="empty-state"><i class="fas fa-inbox"></i>
      <h4><?= $show === 'all' ? 'لا بيانات بعد' : 'لا أحد ضمن «' . e($showLabels[$show]) . '»' ?></h4>
      <p><?= $show === 'all'
          ? 'ابعت رابط تحديث المعلومات للأساتذة من صفحة «تحديث معلومات الأساتذة»، وبمجرّد ما يعبّوه بيظهروا هون.'
          : 'جرّب فلتر تاني من فوق، أو «الكل».' ?></p></div>
  </div></div>
<?php else: foreach ($renderKeys as $schoolName):
    $sent    = $bySchool[$schoolName]['sent'] ?? [];
    $notsent = $bySchool[$schoolName]['notsent'] ?? [];
    $news    = $newBySchool[$schoolName] ?? [];
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:#eef6ff">
    <h3 style="color:#0a6b5e"><i class="fas fa-school"></i> <?= e($schoolName) ?>
      <?php if ($showSent): ?><span class="badge badge-success" style="margin-inline-start:6px"><i class="fas fa-check"></i> <?= count($sent) ?> بعتوا</span><?php endif; ?>
      <?php if ($showNot): ?><span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> <?= count($notsent) ?> ما بعتوا</span><?php endif; ?>
      <?php if ($showNew && $news): ?><span class="badge" style="background:#0a7a37;color:#fff"><i class="fas fa-user-plus"></i> <?= count($news) ?> جدد</span><?php endif; ?>
    </h3>
  </div>
  <div class="card-body">

    <!-- ✅ بعتوا وحدّثوا -->
    <?php if ($showSent && ($sent || $show === 'sent')): ?>
    <h4 style="color:#15803d;border-bottom:2px solid #dcfce7;padding-bottom:6px;margin:6px 0 10px">
      <span dir="ltr"><i class="fas fa-check-circle"></i> Ont envoyé et mis à jour (<?= count($sent) ?>)</span>
      <div style="font-size:0.85em;font-weight:600;color:#166534">بعتوا وحدّثوا معلوماتهم</div>
    </h4>
    <?php if (!$sent): ?>
      <p style="color:var(--gray-500);margin:0 0 8px">لا أحد بعت بعد في هذه المدرسة.</p>
    <?php else: ?>
      <div class="table-wrapper">
      <table class="table">
        <thead><tr><th>#</th><th>Nom / الاسم</th><th>Catégorie / الفئة</th><th>Statut / الحالة</th><th>Date d'envoi / تاريخ الإرسال</th><th class="no-print">Dossier / الملف</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($sent as $emp): $i++;
          $nm = trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: trim($emp['first_name_fr'].' '.$emp['last_name_fr']);
          $isApplied = ($emp['status'] ?? '') === 'applied';
        ?>
          <tr>
            <td><?= $i ?></td>
            <td><strong><?= e($nm) ?></strong></td>
            <td><?= e(employeeTypeLabel($emp['employee_type'], 'ar')) ?></td>
            <td>
              <?php if ($isApplied): ?>
                <span class="badge badge-success"><i class="fas fa-check"></i> Approuvé et mis à jour / مقبول ومحدَّث</span>
              <?php else: ?>
                <span class="badge" style="background:#b45309;color:#fff"><i class="fas fa-hourglass-half"></i> En attente d'approbation / بانتظار اعتمادك</span>
              <?php endif; ?>
            </td>
            <td><?= isDate($emp['submitted_at'] ?? '') ?></td>
            <td class="no-print">
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-light" title="Ouvrir le dossier / فتح الملف"><i class="fas fa-folder-open"></i></a>
              <?php if (!$isApplied): ?>
                <a href="<?= BASE_URL ?>pages/info_collect.php#received" class="btn btn-sm btn-success" title="Approuver la demande / اعتماد الطلب"><i class="fas fa-check"></i> Approuver / اعتمِد</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
    <?php endif; /* showSent */ ?>

    <!-- ⏳ ما بعتوا -->
    <?php if ($showNot && ($notsent || $show === 'notsent')): ?>
    <h4 style="color:#b45309;border-bottom:2px solid #fef3c7;padding-bottom:6px;margin:18px 0 10px">
      <span dir="ltr"><i class="fas fa-hourglass-half"></i> N'ont pas encore envoyé (<?= count($notsent) ?>)</span>
      <div style="font-size:0.85em;font-weight:600;color:#92400e">ما بعتوا بعد</div>
    </h4>
    <?php if (!$notsent): ?>
      <p style="color:#15803d;margin:0 0 8px"><i class="fas fa-check-circle"></i> كل الأساتذة بعتوا معلوماتهم. 🎉</p>
    <?php else: ?>
      <div class="table-wrapper">
      <table class="table">
        <thead><tr><th>#</th><th>Nom / الاسم</th><th>Catégorie / الفئة</th><th>Téléphone / الهاتف</th><th class="no-print">Rappel WhatsApp / تذكير واتساب</th><th class="no-print">Dossier / الملف</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($notsent as $emp): $i++;
          $nm = trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: trim($emp['first_name_fr'].' '.$emp['last_name_fr']);
          $wa = waPhone($emp['phone1'] ?: $emp['phone2']);
        ?>
          <tr>
            <td><?= $i ?></td>
            <td><strong><?= e($nm) ?></strong></td>
            <td><?= e(employeeTypeLabel($emp['employee_type'], 'ar')) ?></td>
            <td><?= e($emp['phone1'] ?: $emp['phone2'] ?: '—') ?></td>
            <td class="no-print">
              <?php if ($wa): ?>
                <a href="https://wa.me/<?= $wa ?>" target="_blank" class="btn btn-sm" style="background:#25d366;color:#fff"><i class="fab fa-whatsapp"></i> واتساب</a>
              <?php else: ?><span class="badge badge-warning">Pas de téléphone / لا هاتف</span><?php endif; ?>
            </td>
            <td class="no-print">
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-light" title="Ouvrir le dossier / فتح الملف"><i class="fas fa-folder-open"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
    <?php endif; /* showNot */ ?>

    <!-- 🆕 الأساتذة الجدد -->
    <?php if ($showNew && $news): ?>
    <h4 style="color:#0a7a37;border-bottom:2px solid #dcfce7;padding-bottom:6px;margin:18px 0 10px">
      <span dir="ltr"><i class="fas fa-user-plus"></i> Nouveaux enseignants (<?= count($news) ?>)</span>
      <div style="font-size:0.85em;font-weight:600;color:#166534">أساتذة جدد</div>
    </h4>
    <div class="table-wrapper">
    <table class="table">
      <thead><tr><th>#</th><th>Nom / الاسم</th><th>Statut / الحالة</th><th>Date de la demande / تاريخ الطلب</th><th class="no-print">Action / إجراء</th></tr></thead>
      <tbody>
      <?php $i = 0; foreach ($news as $r): $i++;
        $nm = newTeacherName($r); $isApplied = ($r['status'] === 'applied' && !empty($r['employee_id']));
      ?>
        <tr>
          <td><?= $i ?></td>
          <td><strong><?= e($nm) ?></strong>
            <?php if ($isApplied && !empty($r['employee_code'])): ?><span class="badge badge-light"><?= e($r['employee_code']) ?></span><?php endif; ?></td>
          <td>
            <?php if ($isApplied): ?>
              <span class="badge" style="background:#0a7a37;color:#fff"><i class="fas fa-user-check"></i> Dossier créé / نُشئ ملفه
                <?php if (!empty($r['hire_date'])): ?>(دخول <?= e(schoolYearOfDate($r['hire_date']) ?: date('Y', strtotime($r['hire_date']))) ?>)<?php endif; ?></span>
            <?php else: ?>
              <span class="badge" style="background:#b45309;color:#fff"><i class="fas fa-hourglass-half"></i> En attente de création / بانتظار إنشاء الملف</span>
            <?php endif; ?>
          </td>
          <td><?= isDate($r['submitted_at']) ?></td>
          <td class="no-print">
            <?php if ($isApplied): ?>
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['employee_id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-folder-open"></i> Ouvrir le dossier / فتح الملف</a>
            <?php else: ?>
              <a href="<?= BASE_URL ?>pages/info_collect.php#newteachers" class="btn btn-sm btn-success"><i class="fas fa-user-plus"></i> Créer le dossier / إنشاء الملف</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
