<?php
/**
 * tax_suggestions.php — اقتراحات من إخراج القيد العائلي («لازم يضوي بالبرنامج وانا
 * بساعتها بطبق او لاء» — 2026-08-23): كل ما يُقرأ من إخراجات القيد يظهر هنا اقتراحاً؛
 * المستخدم يقرّر «طبّق» (يُعدَّل ملف الموظف ويُعاد احتساب سنواته من السنة الجارية) أو
 * «تجاهل». الإشارة الحمراء بالقائمة تضوي ما دام في معلَّق.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();
ensureTaxSuggestions20260823();
ensureEmployeeFlagColumns();

// ===== تطبيق / تجاهل =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    requireCsrf();
    $sid = (int)($_POST['sid'] ?? 0);
    $act = $_POST['act'] ?? '';
    $sg = null;
    if ($sid) {
        $st = $db->prepare("SELECT * FROM tax_suggestions WHERE id=? AND status='pending'");
        $st->execute([$sid]);
        $sg = $st->fetch();
    }
    if ($sg && $act === 'apply') {
        $ok = false;
        $prop = json_decode((string)$sg['proposed'], true) ?: [];
        $allowed = ['social_status', 'number_of_children', 'grant_children_addition', 'grant_spouse_addition', 'spouse_works'];
        $set = array_intersect_key($prop, array_flip($allowed));
        if ($sg['employee_id'] && $set) {
            $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
            $db->prepare("UPDATE employees SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [(int)$sg['employee_id']]));
            // إعادة احتساب سنواته من السنة الدراسية الجارية وما بعدها (نفس قاعدة حفظ الملف)
            require_once __DIR__ . '/../includes/payroll_calculator.php';
            if (function_exists('set_time_limit')) @set_time_limit(300);
            $cur = currentSchoolYear();
            foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$sg['employee_id'] . " AND school_year >= " . $db->quote($cur) . " ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                try { recalcEmployeeYear((int)$sg['employee_id'], $sy); } catch (Throwable $e) {}
            }
            $ok = true;
        }
        $db->prepare("UPDATE tax_suggestions SET status='applied', decided_at=NOW() WHERE id=?")->execute([$sid]);
        $_SESSION['flash_success'] = $ok
            ? 'طُبّق الاقتراح على ملف الموظف وأُعيد احتساب سنواته تلقائياً / Suggestion appliquée et salaires recalculés.'
            : 'سُجّل الاقتراح كمقروء / Marqué comme traité.';
    } elseif ($sg && $act === 'dismiss') {
        $db->prepare("UPDATE tax_suggestions SET status='dismissed', decided_at=NOW() WHERE id=?")->execute([$sid]);
        $_SESSION['flash_success'] = 'تم تجاهل الاقتراح — ما تغيّر شي بملف الموظف / Suggestion ignorée.';
    }
    header('Location: ' . BASE_URL . 'pages/tax_suggestions.php');
    exit;
}

$pageTitle = 'Suggestions (extraits d\'état civil) / اقتراحات من إخراج القيد';
$currentPage = 'tax_suggestions';
$hideExportToolbar = true;
include __DIR__ . '/../includes/header.php';

$rows = $db->query("SELECT ts.*, s.name_ar school_name FROM tax_suggestions ts
                    LEFT JOIN schools s ON s.id = ts.school_id
                    ORDER BY FIELD(ts.status,'pending','applied','dismissed'), ts.id")->fetchAll();
$statusBadge = function ($s) {
    return [
        'pending'   => '<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:999px;font-weight:700">⏳ بانتظار قرارك / En attente</span>',
        'applied'   => '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-weight:700">✓ مطبَّق / Appliqué</span>',
        'dismissed' => '<span style="background:#e2e8f0;color:#475569;padding:3px 10px;border-radius:999px;font-weight:700">✗ متجاهَل / Ignoré</span>',
    ][$s] ?? e($s);
};
$propLabel = function ($json) {
    $p = json_decode((string)$json, true) ?: [];
    if (!$p) return '<span style="color:#94a3b8">— (معلومة فقط، بلا تعديل)</span>';
    $out = [];
    if (isset($p['social_status'])) $out[] = 'الوضع: <strong>' . e(socialStatusLabel($p['social_status'], 'ar')) . '</strong>';
    if (isset($p['number_of_children'])) $out[] = 'أولاد دون 18: <strong>' . (int)$p['number_of_children'] . '</strong>';
    if (isset($p['grant_children_addition'])) $out[] = 'تنزيل الأولاد: <strong>' . ($p['grant_children_addition'] ? 'يُضوّى ✓' : 'مطفأ') . '</strong>';
    if (isset($p['grant_spouse_addition'])) $out[] = 'زيادة الزوج: <strong>' . ($p['grant_spouse_addition'] ? 'تُعطى' : 'مطفأة') . '</strong>';
    return implode(' · ', $out);
};
?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-lightbulb"></i> Suggestions — extraits d'état civil familial</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">اقتراحات من قراءة إخراجات القيد العائلية — أنت بتقرّر: طبّق أو تجاهل</div>
    </h3></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:14px"><i class="fas fa-info-circle"></i>
            كل ما تُقرأ إخراجات قيد جديدة (المرفوعة عبر روابط الأساتذة) تنزل هنا كاقتراح والإشارة الحمراء بالقائمة بتضوي.
            «طبّق» = يعدَّل ملف الموظف (الوضع العائلي + عدد الأولاد دون 18 + المفاتيح) ويُعاد احتساب رواتبه من السنة الجارية تلقائياً. «تجاهل» = ما بيتغيّر شي.
        </p>
        <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl">
            <thead><tr>
                <th>#</th><th>الموظف</th><th>المدرسة</th><th>الاكتشاف من إخراج القيد</th>
                <th>التفاصيل</th><th>الاقتراح</th><th>الحالة</th>
                <?php if (canEdit()): ?><th>القرار</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr style="<?= $r['status'] === 'pending' ? 'background:#fffbeb' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td style="font-weight:700;white-space:nowrap"><?= e($r['emp_name']) ?></td>
                    <td><small><?= e($r['school_name'] ?? '') ?></small></td>
                    <td style="text-align:right;font-weight:600"><?= e($r['title']) ?></td>
                    <td style="text-align:right"><small><?= e($r['details']) ?></small></td>
                    <td style="text-align:right"><small><?= $propLabel($r['proposed']) ?></small></td>
                    <td><?= $statusBadge($r['status']) ?></td>
                    <?php if (canEdit()): ?>
                    <td style="white-space:nowrap">
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline"><?= csrfField() ?>
                                <input type="hidden" name="sid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="apply">
                                <button class="btn btn-success" onclick="return confirm('يُطبَّق الاقتراح على ملف الموظف ويُعاد احتساب رواتبه — أكيد؟')"><i class="fas fa-check"></i> طبّق</button>
                            </form>
                            <form method="POST" style="display:inline"><?= csrfField() ?>
                                <input type="hidden" name="sid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="dismiss">
                                <button class="btn btn-secondary"><i class="fas fa-xmark"></i> تجاهل</button>
                            </form>
                        <?php elseif ($r['decided_at']): ?>
                            <small style="color:#64748b"><?= e(formatDate(substr($r['decided_at'], 0, 10))) ?></small>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center">لا اقتراحات بعد / Aucune suggestion</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
