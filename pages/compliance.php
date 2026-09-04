<?php
/**
 * ⚖️ Rapport de conformité / تقرير المخالفات والتصحيحات — الصفحة الدائمة (طلبه 2026-09-04):
 * «دايماً يكون في تقرير بهيدا الشي»: المعلّق بانتظار قراره + ما تركه بقراره (يقدر يعيد فتحه) +
 * ما صُحِّح (بموافقته أو تلقائياً). المنطق كله بـincludes/compliance.php.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/compliance.php';
requireLogin();

$currentPage = 'compliance';
$pageTitle = 'Rapport de conformité / تقرير المخالفات والتصحيحات';
$db = getDB();

handleCompliancePost($db, BASE_URL . 'pages/compliance.php');

$rep = complianceBuild($db);
$rules = complianceRules();
$hideExportToolbar = true;

include __DIR__ . '/../includes/header.php';
?>

<?php renderCompliancePending($rep, false); ?>

<?php if (!$rep['pending'] && !$rep['auto']): ?>
<div class="card" style="border:2px solid #16a34a;margin-bottom:16px">
    <div class="card-header" style="background:#f0fdf4"><h3 style="color:#166534"><i class="fas fa-scale-balanced"></i> Rapport de conformité / تقرير المخالفات — سنة <?= e($rep['sy']) ?></h3></div>
    <div class="card-body"><div class="alert alert-success" style="margin:0"><i class="fas fa-check-circle"></i> لا مخالفات معلّقة بالنطاق المختار — كل الأساتذة والموظفين مطابقون للقانون ولبنود ملفاتهم.</div></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-circle-pause"></i> Laissé sur décision / ما تركته بقرارك (<?= count($rep['rejected']) ?>)</h3></div>
    <div class="card-body">
        <?php if (!$rep['rejected']): ?>
            <p class="text-muted" style="margin:0">لا شيء.</p>
        <?php else: ?>
            <p class="text-muted" style="margin-top:0">هذه المخالفات ما زالت موجودة لكنك قرّرت تركها كما هي — «إعادة فتح» تُرجعها لقائمة الانتظار.</p>
            <div class="table-wrapper"><table class="table">
                <thead><tr><th>الأستاذ / المدرسة</th><th>المخالفة</th><th>التصحيح الذي رفضته</th><th>قرارك</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rep['rejected'] as $it): $d = $it['decision']; ?>
                <tr>
                    <td><strong><?= e($it['emp_name']) ?></strong><?= $it['school_name'] !== '' ? '<br><small class="text-muted">' . e($it['school_name']) . '</small>' : '' ?></td>
                    <td><span class="badge" style="background:<?= $rules[$it['rule']][2] ?? '#64748b' ?>;color:#fff"><?= e($rules[$it['rule']][1] ?? $it['rule']) ?></span> <?= e($it['violation']) ?></td>
                    <td><?= e($it['fix']) ?></td>
                    <td><small><?= e((string)$d['decided_by']) ?> — <?= e((string)$d['decided_at']) ?></small></td>
                    <td style="white-space:nowrap"><?php if (canEdit()): ?><form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="comp_reopen"><input type="hidden" name="key" value="<?= e($it['key']) ?>"><button class="btn btn-sm btn-light"><i class="fas fa-rotate-left"></i> إعادة فتح</button></form><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-clipboard-check"></i> Corrigé / ما صُحِّح (<?= count($rep['applied']) + count($rep['auto']) ?>)</h3></div>
    <div class="card-body">
        <?php $log = array_merge($rep['applied'], $rep['auto']); usort($log, fn($a, $b) => strcmp((string)$b['decided_at'], (string)$a['decided_at'])); ?>
        <?php if (!$log): ?>
            <p class="text-muted" style="margin:0">لا شيء بعد.</p>
        <?php else: ?>
            <div class="table-wrapper"><table class="table">
                <thead><tr><th>التاريخ</th><th>الأستاذ</th><th>السنة</th><th>المخالفة</th><th>التصحيح</th><th>النتيجة</th><th>مَن</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($log, 0, 300) as $d): ?>
                <tr>
                    <td style="white-space:nowrap"><small><?= e((string)$d['decided_at']) ?></small></td>
                    <td><strong><?= e((string)$d['emp_name']) ?></strong></td>
                    <td><?= e((string)$d['school_year']) ?></td>
                    <td><span class="badge" style="background:<?= $rules[$d['rule_key']][2] ?? '#64748b' ?>;color:#fff"><?= e($rules[$d['rule_key']][1] ?? $d['rule_key']) ?></span> <?= e((string)$d['violation']) ?></td>
                    <td><?= e((string)$d['fix']) ?></td>
                    <td><?= e((string)$d['result']) ?></td>
                    <td><?= $d['decision'] === 'auto' ? '<span class="badge badge-success">تلقائياً</span>' : e((string)$d['decided_by']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-book"></i> Règles vérifiées / القواعد التي يفحصها التقرير (<?= count($rules) ?>)</h3></div>
    <div class="card-body"><ul style="margin:0;padding-inline-start:18px;columns:2">
        <?php foreach ($rules as $rk => $m): ?><li><strong style="color:<?= $m[2] ?>"><?= e($m[1]) ?></strong> <span dir="ltr" style="opacity:.75">/ <?= e($m[0]) ?></span></li><?php endforeach; ?>
    </ul></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
