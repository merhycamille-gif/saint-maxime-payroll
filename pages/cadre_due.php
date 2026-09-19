<?php
/**
 * 🎓 Propositions de titularisation / اقتراحات الدخول بالملاك — الصفحة الدائمة (طلبه 2026-09-19):
 * «بدي اقتراح للدخول في الملاك للأساتذة اللي بيكون صارلون سنتين داخلين على المدرسة، وأنا ساعتها بوافق دخّلهن بالملاك أو لا».
 * البرنامج يقترح (المتعاقد الذي أكمل سنتين دراسيتين كاملتين براتب فعلي بالمدرسة نفسها) والقرار له وحده:
 * وافق ⇒ ترسيم بكل قانون الملاك (titularizeContractTeacher) · لا ⇒ يبقى متعاقداً (يُسجَّل ويمكن إعادة فتحه).
 * المنطق كله بـincludes/cadre_due.php (المصدر الواحد مع بطاقة لوحة القيادة وصفحة فتح السنة).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/cadre_due.php';
requireLogin();

$currentPage = 'cadre_due';
$pageTitle = 'Propositions de titularisation / اقتراحات الدخول بالملاك';
$db = getDB();
$hideExportToolbar = true;

handleCadreDuePost($db, BASE_URL . 'pages/cadre_due.php');

// السنة: الحالية للبرنامج (أو المختارة إن كانت أحدث) — القرارات للسنة الحالية أو ما بعدها فقط
$cdSy = activeSchoolYear(); if ($cdSy === 'all' || strcmp($cdSy, currentSchoolYear()) < 0) $cdSy = currentSchoolYear();
$y1 = (int)substr($cdSy, 0, 4);
$nextSy = ($y1 + 1) . '-' . ($y1 + 2);

$cdPend = $cdRej = $cdApp = $cdNext = [];
if (canEdit()) {
    $cdPend = cadreDueCandidates($db, $cdSy, null, false, true);
    $cdRej = array_values(array_filter(cadreDueCandidates($db, $cdSy, null, true, true), fn($c) => $c['decision'] && $c['decision']['decision'] === 'rejected'));
    $cdApp = cadreDueApprovedList($db, $cdSy);
    // معاينة: مَن سيستحقّ السنة القادمة (بلا أزرار) — الجدد فقط، لا مَن هو مطروح عليك هذه السنة أصلاً
    $cdThisIds = array_map(fn($c) => $c['id'], cadreDueCandidates($db, $cdSy, null, true, true));
    $cdNext = array_values(array_filter(cadreDueCandidates($db, $nextSy, null, false, true), fn($c) => !in_array($c['id'], $cdThisIds, true)));
}

include __DIR__ . '/../includes/header.php';
?>

<div class="card" style="border:2px solid #6d28d9;margin-bottom:16px">
    <div class="card-header" style="background:#f5f3ff"><h3 style="color:#5b21b6"><i class="fas fa-graduation-cap"></i>
        <span dir="ltr">Propositions de titularisation après 2 ans</span> / اقتراحات الدخول بالملاك بعد سنتين — سنة <?= e($cdSy) ?></h3></div>
    <div class="card-body" style="line-height:1.9">
        <p style="margin:0 0 6px">البرنامج بيقترح لحاله كل أستاذ <strong>متعاقد</strong> أكمل <strong>سنتين دراسيتين كاملتين</strong> براتب فعلي بالمدرسة نفسها. <strong>القرار إلك وحدك</strong>: وافق ⇒ بيدخل الملاك بكل قانونه · لا ⇒ بيضلّ متعاقداً. ما بيتغيّر شي بلا موافقتك.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
            <span style="background:#6d28d9;color:#fff;border-radius:999px;padding:2px 12px;font-weight:800">بانتظار قرارك: <?= count($cdPend) ?></span>
            <span style="background:#166534;color:#fff;border-radius:999px;padding:2px 12px;font-weight:800">دخلوا الملاك بموافقتك: <?= count($cdApp) ?></span>
            <span style="background:#6b7280;color:#fff;border-radius:999px;padding:2px 12px;font-weight:800">تركتهم متعاقدين: <?= count($cdRej) ?></span>
            <span style="background:#0369a1;color:#fff;border-radius:999px;padding:2px 12px;font-weight:800">السنة القادمة <?= e($nextSy) ?>: <?= count($cdNext) ?></span>
        </div>
    </div>
</div>

<?php if (!canEdit()): ?>
<div class="alert alert-warning">حساب قراءة فقط — القرارات للمدير.</div>
<?php elseif (!$cdPend && !$cdRej): ?>
<div class="card" style="border:2px solid #16a34a;margin-bottom:16px">
    <div class="card-body"><div class="alert alert-success" style="margin:0"><i class="fas fa-check-circle"></i> لا اقتراحات معلّقة بسنة <?= e($cdSy) ?> بالنطاق المختار — كل مَن أكمل سنتين قرّرت بشأنه.</div></div>
</div>
<?php endif; ?>

<?php if (canEdit()) renderCadreDuePending($cdPend, $cdSy, false, BASE_URL . 'pages/cadre_due.php', $cdRej); ?>

<?php if (canEdit()): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3><i class="fas fa-user-check" style="color:#166534"></i> Titularisés sur votre accord / دخلوا الملاك بموافقتك — سنة <?= e($cdSy) ?> (<?= count($cdApp) ?>)</h3></div>
    <div class="card-body">
        <?php if (!$cdApp): ?>
            <p class="text-muted" style="margin:0">لا أحد بعد.</p>
        <?php else: ?>
        <div class="table-wrapper"><table class="table" style="margin:0">
            <thead><tr><th>الأستاذ</th><th>المدرسة</th><th>بالمدرسة منذ</th><th>بالملاك من</th><th>الدرجة الآن</th><th>ما طُبِّق</th><th>القرار</th></tr></thead>
            <tbody>
            <?php foreach ($cdApp as $a): ?>
            <tr>
                <td><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$a['employee_id'] ?>"><strong><?= e($a['emp_name']) ?></strong></a></td>
                <td><?= e($a['school_name_ar'] ?: (string)$a['school_name_fr']) ?></td>
                <td style="white-space:nowrap"><?= e((string)$a['hire_date']) ?></td>
                <td style="white-space:nowrap"><?= e((string)$a['titularization_date']) ?></td>
                <td><?= $a['current_grade'] !== null ? e(rtrim(rtrim(number_format((float)$a['current_grade'], 1), '0'), '.')) : '—' ?></td>
                <td><small><?= e((string)$a['result']) ?></small></td>
                <td><small><?= e((string)$a['decided_by']) ?> — <?= e((string)$a['decided_at']) ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<details class="card" style="margin-bottom:16px">
    <summary style="cursor:pointer;padding:12px 16px;font-weight:800;color:#0369a1"><i class="fas fa-calendar-plus"></i> Aperçu année prochaine / معاينة: مَن سيكمل سنتين بالسنة القادمة <?= e($nextSy) ?> (<?= count($cdNext) ?>) <small style="font-weight:600;opacity:.8">— للعلم فقط، الاقتراح بيطلع لمّا تنفتح السنة</small></summary>
    <div class="card-body">
        <?php if (!$cdNext): ?>
            <p class="text-muted" style="margin:0">لا أحد حسب الرواتب المخزّنة حتى الآن.</p>
        <?php else: ?>
        <div class="table-wrapper"><table class="table" style="margin:0">
            <thead><tr><th>الأستاذ</th><th>المدرسة</th><th>بالمدرسة منذ</th><th>الشهادة ← درجة الدخول</th><th>راتبه الآن (متعاقد)</th><th>يستحقّ الملاك من</th></tr></thead>
            <tbody>
            <?php foreach ($cdNext as $c): ?>
            <tr>
                <td><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a></td>
                <td><?= e($c['school_name']) ?></td>
                <td style="white-space:nowrap"><?= e($c['hire_date']) ?> <small class="text-muted">(<?= (int)$c['years'] ?> سنة)</small></td>
                <td><?= e($c['diploma_label']) ?><?= $c['grade_start'] !== null ? ' — درجة ' . e(rtrim(rtrim(number_format($c['grade_start'], 1), '0'), '.')) : ' <small style="color:#b45309">' . e($c['why']) . '</small>' ?></td>
                <td><?= e($c['pay']) ?></td>
                <td style="white-space:nowrap"><?= e($c['tit']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
