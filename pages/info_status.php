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
$pageTitle = 'حالة تحديث معلومات الأساتذة / État des mises à jour';
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

// 1) أساتذة/موظفو السنة الحالية في المدارس الفعّالة ضمن النطاق (غير محذوفين، موجودون فعلاً بهذه السنة، غير تاركين)
[$yf, $yp] = yearEmploymentFilter(activeSchoolYear(), 'e.');
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

// 2) كل من بعت طلباً (موجود، applied أو pending) في المدارس الفعّالة ضمن النطاق —
//    نقودها من جدول الطلبات مباشرةً حتى يظهر **كل** من بعت (ولو ما عاد ضمن فلتر السنة الحالية).
$rank = ['applied' => 2, 'pending' => 1];
$sentByEmp = []; // employee_id => أفضل صفّ طلب (مع اسم الأستاذ ومدرسته)
$sq = $db->prepare("SELECT s.status, s.submitted_at, e.id, e.first_name_ar, e.last_name_ar,
        e.first_name_fr, e.last_name_fr, e.employee_type,
        COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
    FROM info_submissions s
    JOIN employees e ON e.id = s.employee_id
    JOIN schools sc ON sc.id = e.school_id
    WHERE s.is_new_teacher = 0 AND s.status IN ('applied','pending') AND e.is_deleted = 0"
    . $activeSchoolCond . schoolScopeSql('e.school_id') . "
    ORDER BY s.submitted_at");
$sq->execute();
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
    WHERE s.is_new_teacher = 1 AND s.status <> 'rejected'" . $activeSchoolCond . schoolScopeSql('s.school_id') . "
    ORDER BY school_name, FIELD(s.status,'pending','applied'), s.submitted_at DESC");
$newRows->execute();
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
$showLabels = ['all' => 'الكل', 'sent' => 'اللي حدّثوا وبعتوا', 'notsent' => 'اللي ما بعتوا', 'new' => 'الأساتذة الجدد'];

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
  <i class="fas fa-clipboard-check"></i> تقرير <strong>حالة تحديث المعلومات</strong> لكل مدرسة: مين <strong>بعت وحدّث</strong> معلوماته (مقبول أو بانتظار اعتمادك)، مين <strong>ما بعت بعد</strong>، ومين <strong>الأساتذة الجدد</strong>. أساتذة السنة الحالية <strong><?= e(currentSchoolYear()) ?></strong>.
  <br><span dir="ltr" style="opacity:.85">Qui a envoyé et mis à jour, qui n'a pas encore envoyé, et les nouveaux enseignants — par école.</span>
</div>

<div class="card no-print" style="margin-bottom:14px">
  <div class="card-body">
    <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:center">
      <div><span class="badge badge-success" style="font-size:14px"><i class="fas fa-check"></i> بعتوا وحدّثوا</span> <strong style="font-size:20px"><?= $gSent ?></strong>
        <?php if ($gPending): ?><span style="color:#b45309">(منهم <?= $gPending ?> بانتظار اعتمادك)</span><?php endif; ?></div>
      <div><span class="badge badge-warning" style="font-size:14px"><i class="fas fa-hourglass-half"></i> ما بعتوا بعد</span> <strong style="font-size:20px"><?= $gNot ?></strong></div>
      <div><span class="badge" style="background:#0a7a37;color:#fff;font-size:14px"><i class="fas fa-user-plus"></i> أساتذة جدد</span> <strong style="font-size:20px"><?= $gNew ?></strong></div>
      <button type="button" onclick="window.print()" class="btn btn-light" style="margin-inline-start:auto"><i class="fas fa-print"></i> طباعة <?= $show === 'all' ? '' : '«' . e($showLabels[$show]) . '»' ?></button>
    </div>
    <div style="margin-top:12px;border-top:1px solid var(--gray-200);padding-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span style="font-weight:700;color:var(--gray-600)"><i class="fas fa-filter"></i> اعرض / اطبع:</span>
      <?php foreach ($showLabels as $key => $lbl):
        $on = ($show === $key);
        $icon = ['all'=>'fa-list','sent'=>'fa-check','notsent'=>'fa-hourglass-half','new'=>'fa-user-plus'][$key];
      ?>
        <a href="<?= BASE_URL ?>pages/info_status.php?show=<?= $key ?>"
           class="btn btn-sm <?= $on ? 'btn-success' : 'btn-light' ?>"><i class="fas <?= $icon ?>"></i> <?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($show !== 'all'): ?>
<div class="print-only" style="text-align:center;margin-bottom:10px"><h2 style="margin:0"><?= e($showLabels[$show]) ?> — <?= e(currentSchoolYear()) ?></h2></div>
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
      <i class="fas fa-check-circle"></i> بعتوا وحدّثوا معلوماتهم (<?= count($sent) ?>)
    </h4>
    <?php if (!$sent): ?>
      <p style="color:var(--gray-500);margin:0 0 8px">لا أحد بعت بعد في هذه المدرسة.</p>
    <?php else: ?>
      <div class="table-wrapper">
      <table class="table">
        <thead><tr><th>#</th><th>الاسم / Nom</th><th>الفئة</th><th>الحالة</th><th>تاريخ الإرسال</th><th class="no-print">الملف</th></tr></thead>
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
                <span class="badge badge-success"><i class="fas fa-check"></i> مقبول ومحدَّث</span>
              <?php else: ?>
                <span class="badge" style="background:#b45309;color:#fff"><i class="fas fa-hourglass-half"></i> بانتظار اعتمادك</span>
              <?php endif; ?>
            </td>
            <td><?= isDate($emp['submitted_at'] ?? '') ?></td>
            <td class="no-print">
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-light" title="فتح الملف"><i class="fas fa-folder-open"></i></a>
              <?php if (!$isApplied): ?>
                <a href="<?= BASE_URL ?>pages/info_collect.php#received" class="btn btn-sm btn-success" title="اعتماد الطلب"><i class="fas fa-check"></i> اعتمِد</a>
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
      <i class="fas fa-hourglass-half"></i> ما بعتوا بعد (<?= count($notsent) ?>)
    </h4>
    <?php if (!$notsent): ?>
      <p style="color:#15803d;margin:0 0 8px"><i class="fas fa-check-circle"></i> كل الأساتذة بعتوا معلوماتهم. 🎉</p>
    <?php else: ?>
      <div class="table-wrapper">
      <table class="table">
        <thead><tr><th>#</th><th>الاسم / Nom</th><th>الفئة</th><th>الهاتف</th><th class="no-print">تذكير واتساب</th><th class="no-print">الملف</th></tr></thead>
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
              <?php else: ?><span class="badge badge-warning">لا هاتف</span><?php endif; ?>
            </td>
            <td class="no-print">
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-light" title="فتح الملف"><i class="fas fa-folder-open"></i></a>
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
      <i class="fas fa-user-plus"></i> أساتذة جدد (<?= count($news) ?>)
    </h4>
    <div class="table-wrapper">
    <table class="table">
      <thead><tr><th>#</th><th>الاسم / Nom</th><th>الحالة</th><th>تاريخ الطلب</th><th class="no-print">إجراء</th></tr></thead>
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
              <span class="badge" style="background:#0a7a37;color:#fff"><i class="fas fa-user-check"></i> نُشئ ملفه
                <?php if (!empty($r['hire_date'])): ?>(دخول <?= e(schoolYearOfDate($r['hire_date']) ?: date('Y', strtotime($r['hire_date']))) ?>)<?php endif; ?></span>
            <?php else: ?>
              <span class="badge" style="background:#b45309;color:#fff"><i class="fas fa-hourglass-half"></i> بانتظار إنشاء الملف</span>
            <?php endif; ?>
          </td>
          <td><?= isDate($r['submitted_at']) ?></td>
          <td class="no-print">
            <?php if ($isApplied): ?>
              <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$r['employee_id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-folder-open"></i> فتح الملف</a>
            <?php else: ?>
              <a href="<?= BASE_URL ?>pages/info_collect.php#newteachers" class="btn btn-sm btn-success"><i class="fas fa-user-plus"></i> إنشاء الملف</a>
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
