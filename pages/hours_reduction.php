<?php
/**
 * صفحة «تناقص ساعات التدريس» — المرسوم 2601/2018: أساتذة الملاك الذين يحقّ لهم تناقص
 * عدد ساعات التدريس الأسبوعية بالسنة الدراسية المعروضة، مجمّعين لكل مدرسة.
 * عرض ومعلومات فقط — لا تغيّر أي حساب راتب.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/hours_reduction.php';
requireLogin();

$db = getDB();
$currentPage = 'hours_reduction';
$pageTitle = 'Réduction d\'horaire / تناقص ساعات التدريس';

$sy = activeSchoolYear();
if ($sy === 'all') $sy = currentSchoolYear();
$bySchool = hoursReductionList($db, $sy);
$total = 0; foreach ($bySchool as $rows) $total += count($rows);

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info no-print" style="margin-bottom:14px">
    <i class="fas fa-info-circle"></i>
    <strong>المرسوم رقم 2601 تاريخ 27/3/2018:</strong> يتناقص عدد ساعات التدريس الأسبوعية للأستاذ في <strong>الملاك الدائم</strong> تدريجياً حسب سنوات خدمته —
    بعد <strong>16 سنة</strong> خدمة فعلية في التعليم الثانوي (الأساس 20 ساعة)، وبعد <strong>20 سنة</strong> في الروضة والتعليم الأساسي
    (الحلقة الثالثة: الأساس 24 · الروضة والحلقتان الأولى والثانية: الأساس 27).
    الجدول أدناه يعرض مستحقّي التناقص بسنة <strong><?= e($sy) ?></strong> وعددهم بكل مدرسة. هذا عرض معلوماتي — لا يغيّر أي راتب.
</div>

<?php if (!$bySchool): ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="fas fa-clock"></i>
    <h4><span dir="ltr">Aucune réduction d'horaire cette année</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">لا أحد يستحق تناقص ساعات بسنة <?= e($sy) ?></div></h4>
    <p>يظهر هنا كل أستاذ ملاك بلغت خدمته الفعلية حدّ التناقص حسب المرسوم 2601/2018.</p>
</div></div></div>
<?php else: ?>

<div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:10px 14px">
    <strong><i class="fas fa-users"></i> المجموع بسنة <?= e($sy) ?>: <?= $total ?> أستاذاً</strong>
    — <?php $parts = []; foreach ($bySchool as $sn => $rows) $parts[] = e($sn) . ': <strong>' . count($rows) . '</strong>'; echo implode(' · ', $parts); ?>
</div></div>

<?php foreach ($bySchool as $schoolName => $rows): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3><i class="fas fa-school"></i> <?= e($schoolName) ?> — <?= count($rows) ?> أستاذاً</h3></div>
    <div class="card-body" style="overflow-x:auto">
        <table class="table">
            <thead><tr>
                <th>Professeur / الأستاذ</th>
                <th>Titularisation / دخول الملاك</th>
                <th>Année de service / سنة الخدمة</th>
                <th>Cycle / المرحلة (الجدول)</th>
                <th>Heures légales / ساعاته القانونية أسبوعياً</th>
                <th>Réduction / التناقص</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $e = $r['emp']; $hr = $r['hr'];
                $nm = trim((($e['first_name_ar'] ?: $e['first_name_fr']) . ' ' . ($e['father_name_ar'] ?: '') . ' ' . ($e['last_name_ar'] ?: $e['last_name_fr']))); ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$e['id'] ?>"><?= e($nm) ?></a></td>
                    <td><?= e(substr((string)$e['titularization_date'], 0, 10)) ?></td>
                    <td>خلال سنة الخدمة <?= (int)$hr['serviceYear'] ?></td>
                    <td><?= e($hr['stageLabel']) ?> (جدول <?= (int)$hr['table'] ?>)<?php if ($hr['assumed']): ?>
                        <span class="badge badge-warning" title="المرحلة غير محدّدة بملفه — اعتُمد الافتراضي؛ حدّد «المرحلة» أو «الصفوف» بملفه للتدقيق">؟ افتراضي</span>
                    <?php endif; ?></td>
                    <td><strong><?= (int)$hr['lawHours'] ?></strong> بدل <?= (int)$hr['baseHours'] ?></td>
                    <td><span class="badge badge-danger" style="font-size:12px">−<?= (int)$hr['reduction'] ?> ساعات</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<details class="no-print" style="margin-top:8px">
    <summary style="cursor:pointer;font-weight:700"><i class="fas fa-balance-scale"></i> جداول المرسوم 2601/2018 الثلاثة (المرجع)</summary>
    <div class="card" style="margin-top:8px"><div class="card-body" style="overflow-x:auto">
        <?php foreach (hoursReductionLawTables() as $no => $t): ?>
            <p style="margin:6px 0 2px"><strong>الجدول <?= $no ?> — <?= e($t['label']) ?></strong> (بعد إتمام <?= (int)$t['minYears'] ?> سنة خدمة فعلية بالملاك — الأساس <?= (int)$t['base'] ?> ساعة):</p>
            <p style="margin:0 0 8px"><?php $ps = [];
                foreach ($t['steps'] as [$f, $to, $h]) $ps[] = 'سنوات ' . $f . ($to >= 999 ? ' وما بعد' : '-' . $to) . ' ← ' . $h . ' ساعة (−' . ($t['base'] - $h) . ')';
                echo e(implode(' · ', $ps)); ?></p>
        <?php endforeach; ?>
    </div></div>
</details>

<?php include __DIR__ . '/../includes/footer.php'; ?>
