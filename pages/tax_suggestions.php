<?php
/**
 * tax_suggestions.php — اقتراحات وقرارات التنزيل العائلي («لازم يضوي بالبرنامج وانا
 * بساعتها بطبق او لاء» + «نحط امام كل استاذ تنزيل الاولاد نعم/كلا وزيادة الزوج نعم/كلا
 * ... ابتداءً من تاريخ الى تاريخ» — 2026-08-23):
 *   ١) قرارات التنزيل لكل أستاذ: مفتاحا الأولاد والزوج بنعم/كلا مع نطاق السريان المؤرَّخ —
 *      الأولاد بتواريخ ولادتهم (التنزيل يسقط تلقائياً من شهر بلوغ كلٍّ منهم الـ18) وزيادة
 *      الزوج تسقط تلقائياً من «تاريخ بدء عمل الزوج» إن حُدِّد.
 *   ٢) اقتراحات قراءات إخراجات القيد (طبّق/تجاهل) — الإشارة الحمراء تضوي ما دام في معلَّق.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();
ensureTaxSuggestions20260823();
ensureEmployeeFlagColumns();
ensureEmployeeChildren20260823();

$recalcFrom = function (int $empId) use ($db) {
    require_once __DIR__ . '/../includes/payroll_calculator.php';
    if (function_exists('set_time_limit')) @set_time_limit(300);
    $cur = currentSchoolYear();
    foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . $empId . " AND school_year >= " . $db->quote($cur) . " ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN) as $sy) {
        try { recalcEmployeeYear($empId, $sy); } catch (Throwable $e) {}
    }
};

// ===== الإجراءات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    requireCsrf();
    $act = $_POST['act'] ?? '';
    $empId = (int)($_POST['emp'] ?? 0);
    $empOk = $empId ? $db->query("SELECT id FROM employees WHERE id = $empId AND is_deleted = 0 AND " . schoolScopeWhere('school_id'))->fetch() : null;

    if ($act === 'toggle_gca' && $empOk) {
        $db->prepare("UPDATE employees SET grant_children_addition = 1 - COALESCE(grant_children_addition,0) WHERE id = ?")->execute([$empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'تبدّل تنزيل الأولاد وأُعيد احتساب رواتبه تلقائياً / Abattement enfants basculé et recalculé.';
    } elseif ($act === 'toggle_gsa' && $empOk) {
        $db->prepare("UPDATE employees SET grant_spouse_addition = 1 - COALESCE(grant_spouse_addition,0) WHERE id = ?")->execute([$empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'تبدّلت زيادة الزوج/الزوجة وأُعيد الاحتساب / Majoration conjoint basculée et recalculée.';
    } elseif ($act === 'spouse_start' && $empOk) {
        $d = $_POST['spouse_work_start_date'] ?? '';
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        $db->prepare("UPDATE employees SET spouse_work_start_date = ? WHERE id = ?")->execute([$d, $empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = $d
            ? 'انحفظ تاريخ بدء عمل الزوج — الزيادة تنشال تلقائياً من ' . formatDate($d) . ' / Date enregistrée.'
            : 'انشال تاريخ بدء عمل الزوج / Date effacée.';
    } elseif ($act === 'add_child' && $empOk) {
        $nm = trim((string)($_POST['child_name'] ?? ''));
        $bd = $_POST['child_birth'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd)) {
            $db->prepare("INSERT IGNORE INTO employee_children (employee_id, child_name, birth_date, source) VALUES (?,?,?, 'manual')")
               ->execute([$empId, ($nm !== '' ? $nm : null), $bd]);
            $recalcFrom($empId);
            $_SESSION['flash_success'] = 'انضاف الولد — تنزيله يسقط تلقائياً من بلوغه 18 (' . formatDate(date('Y-m-d', strtotime($bd . ' +18 years'))) . ') / Enfant ajouté.';
        }
    } elseif ($act === 'del_child' && $empOk) {
        $db->prepare("DELETE FROM employee_children WHERE id = ? AND employee_id = ?")->execute([(int)($_POST['child_id'] ?? 0), $empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'انشال الولد وأُعيد الاحتساب / Enfant retiré.';
    } elseif ($act === 'apply' || $act === 'dismiss') {
        $sid = (int)($_POST['sid'] ?? 0);
        $st = $db->prepare("SELECT * FROM tax_suggestions WHERE id=? AND status='pending'");
        $st->execute([$sid]);
        $sg = $st->fetch();
        if ($sg && $act === 'apply') {
            $prop = json_decode((string)$sg['proposed'], true) ?: [];
            $allowed = ['social_status', 'number_of_children', 'grant_children_addition', 'grant_spouse_addition', 'spouse_works'];
            $set = array_intersect_key($prop, array_flip($allowed));
            if ($sg['employee_id'] && $set) {
                $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
                $db->prepare("UPDATE employees SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [(int)$sg['employee_id']]));
                $recalcFrom((int)$sg['employee_id']);
            }
            $db->prepare("UPDATE tax_suggestions SET status='applied', decided_at=NOW() WHERE id=?")->execute([$sid]);
            $_SESSION['flash_success'] = 'طُبّق الاقتراح وأُعيد الاحتساب / Suggestion appliquée.';
        } elseif ($sg) {
            $db->prepare("UPDATE tax_suggestions SET status='dismissed', decided_at=NOW() WHERE id=?")->execute([$sid]);
            $_SESSION['flash_success'] = 'تم تجاهل الاقتراح / Suggestion ignorée.';
        }
    }
    header('Location: ' . BASE_URL . 'pages/tax_suggestions.php');
    exit;
}

$pageTitle = 'Décisions & suggestions (état civil) / قرارات التنزيل واقتراحات إخراج القيد';
$currentPage = 'tax_suggestions';
$hideExportToolbar = true;
include __DIR__ . '/../includes/header.php';

/* ===== ١) قرارات التنزيل لكل أستاذ (متزوج/أرمل أو له أولاد مسجّلون) =====
 * 📅 «لازم يبينو الاساتذة بالسنة اللي انا فيها مش بكل السنين» (2026-08-23): الفلتر
 * الموحّد yearEmploymentFilter — موظفو السنة الدراسية المعروضة فقط (لهم رواتب فيها،
 * بلا تاركين/قدامى)، كسائر شاشات الاختيار. */
[$tsYf, $tsYp] = yearEmploymentFilter(activeSchoolYear(), 'e.');
$empsQ = $db->prepare("SELECT e.id, e.school_id, e.social_status, e.spouse_works, e.spouse_work_start_date,
        COALESCE(e.grant_children_addition,0) gca, COALESCE(e.grant_spouse_addition,0) gsa,
        COALESCE(NULLIF(TRIM(CONCAT(e.first_name_ar,' ',e.last_name_ar)),''), TRIM(CONCAT(e.first_name_fr,' ',e.last_name_fr))) nm,
        s.name_ar school_name
    FROM employees e JOIN schools s ON s.id = e.school_id
    WHERE e.is_deleted = 0 AND " . schoolScopeWhere('e.school_id') . $tsYf . "
      AND (e.social_status LIKE 'marie%' OR e.social_status LIKE 'veuf%' OR e.social_status LIKE 'divorce%'
           OR EXISTS (SELECT 1 FROM employee_children c WHERE c.employee_id = e.id))
    ORDER BY s.id, nm");
$empsQ->execute($tsYp);
$emps = $empsQ->fetchAll();
$kidsByEmp = [];
foreach ($db->query("SELECT * FROM employee_children ORDER BY birth_date") as $k) $kidsByEmp[(int)$k['employee_id']][] = $k;
$today = date('Y-m-d');
$multiS = (count(activeSchoolIds()) !== 1);
?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-sliders"></i> Décisions d'abattement par enseignant/employé</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">قرارات التنزيل العائلي — قدام كل أستاذ: تنزيل الأولاد نعم/كلا وزيادة الزوج نعم/كلا، مع «ابتداءً من — إلى» تلقائياً</div>
    </h3></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:12px"><i class="fas fa-wand-magic-sparkles"></i>
            <strong>تلقائي بالكامل:</strong> كل ولد مسجَّل بتاريخ ولادته — تنزيله يسقط من البرنامج <strong>من شهر بلوغه الـ18</strong> حتى لو المفتاح مضوّى.
            وزيادة الزوج تسقط تلقائياً <strong>من «تاريخ بدء عمل الزوج»</strong> إن حدّدته. أي تبديل هون يعيد احتساب رواتب الموظف فوراً.
        </p>
        <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl">
            <thead><tr>
                <th>الموظف</th>
                <?php if ($multiS): ?><th>المدرسة</th><?php endif; ?>
                <th>الوضع العائلي</th>
                <th>الأولاد (كلٌّ بتاريخه — التنزيل حتى بلوغه 18)</th>
                <th>تنزيل الأولاد</th>
                <th>زيادة الزوج/الزوجة</th>
            </tr></thead>
            <tbody>
            <?php foreach ($emps as $e2):
                $kids = $kidsByEmp[(int)$e2['id']] ?? [];
                $activeKids = 0;
                foreach ($kids as $k) { if (date('Y-m-d', strtotime($k['birth_date'] . ' +18 years')) > $today) $activeKids++; }
                $isVeuf = (strpos((string)$e2['social_status'], 'veuf') === 0 || strpos((string)$e2['social_status'], 'divorce') === 0);
                $sws = ($e2['spouse_work_start_date'] && $e2['spouse_work_start_date'] !== '0000-00-00') ? $e2['spouse_work_start_date'] : null;
            ?>
                <tr>
                    <td style="font-weight:700;white-space:nowrap"><?= e($e2['nm']) ?></td>
                    <?php if ($multiS): ?><td><small><?= e($e2['school_name']) ?></small></td><?php endif; ?>
                    <td style="white-space:nowrap"><?= e(socialStatusLabel($e2['social_status'], 'ar')) ?></td>
                    <td style="text-align:right;min-width:260px">
                        <?php if ($kids): foreach ($kids as $k):
                            $b18 = date('Y-m-d', strtotime($k['birth_date'] . ' +18 years'));
                            $stillMinor = $b18 > $today; ?>
                            <div style="display:flex;justify-content:space-between;gap:6px;align-items:center;<?= $stillMinor ? '' : 'color:#94a3b8' ?>">
                                <span><?= $stillMinor ? '🟢' : '⚪' ?> <?= e($k['child_name'] ?: 'ولد') ?> <small>(ولادة <?= e(formatDate($k['birth_date'])) ?>)</small>
                                    — <small><?= $stillMinor ? 'تنزيله <strong>إلى ' . e(formatDate($b18)) . '</strong> (بلوغه 18)' : 'انتهى تنزيله بتاريخ ' . e(formatDate($b18)) ?></small></span>
                                <?php if (canEdit()): ?>
                                <form method="POST" style="display:inline"><?= csrfField() ?>
                                    <input type="hidden" name="act" value="del_child"><input type="hidden" name="emp" value="<?= (int)$e2['id'] ?>"><input type="hidden" name="child_id" value="<?= (int)$k['id'] ?>">
                                    <button class="btn btn-danger" style="padding:1px 7px" title="حذف الولد" onclick="return confirm('يُحذف الولد ويُعاد الاحتساب — أكيد؟')">✕</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; else: ?>
                            <small style="color:#94a3b8">لا أولاد مسجّلين بتواريخ — <?= strpos((string)$e2['social_status'], '_enfant') !== false && strpos((string)$e2['social_status'], 'sans') === false ? 'يُحتسب العدد الثابت من وضعه العائلي' : '—' ?></small>
                        <?php endif; ?>
                        <?php if (canEdit()): ?>
                        <form method="POST" style="display:flex;gap:4px;margin-top:5px"><?= csrfField() ?>
                            <input type="hidden" name="act" value="add_child"><input type="hidden" name="emp" value="<?= (int)$e2['id'] ?>">
                            <input type="text" name="child_name" class="form-control" placeholder="اسم الولد" style="max-width:130px;padding:3px 8px">
                            <input type="date" name="child_birth" class="form-control" required style="max-width:150px;padding:3px 8px">
                            <button class="btn btn-primary" style="padding:3px 10px">+ ولد</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;text-align:center">
                        <?php if ($e2['gca']): ?>
                            <div style="color:#166534;font-weight:800">نعم ✓</div>
                            <small><?= $kids ? ($activeKids ? 'محتسب الآن: ' . $activeKids . ' — ينقص تلقائياً ببلوغ كلٍّ 18' : 'لا أولاد دون 18 حالياً ⇒ صفر تلقائياً') : 'حسب وضعه العائلي' ?></small>
                        <?php else: ?>
                            <div style="color:#991b1b;font-weight:800">كلا</div>
                        <?php endif; ?>
                        <?php if (canEdit()): ?>
                        <form method="POST" style="margin-top:4px"><?= csrfField() ?>
                            <input type="hidden" name="act" value="toggle_gca"><input type="hidden" name="emp" value="<?= (int)$e2['id'] ?>">
                            <button class="btn <?= $e2['gca'] ? 'btn-secondary' : 'btn-success' ?>" style="padding:2px 10px" onclick="return confirm('يُبدَّل تنزيل الأولاد ويُعاد الاحتساب — أكيد؟')"><?= $e2['gca'] ? 'طفّيه' : 'ضوّيه' ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;text-align:center">
                        <?php if ($isVeuf): ?>
                            <small style="color:#94a3b8">— (أرمل/مطلق: لا زيادة زوج)</small>
                        <?php else: ?>
                            <?php if ($e2['gsa'] && (int)$e2['spouse_works'] === 1): ?>
                                <div style="color:#991b1b;font-weight:800">كلا</div><small>«الزوج يعمل» ✓ يُسقطها حكماً</small>
                            <?php elseif ($e2['gsa']): ?>
                                <div style="color:#166534;font-weight:800">نعم ✓</div>
                                <small><?= $sws ? 'إلى <strong>' . e(formatDate($sws)) . '</strong> (بدء عمل الزوج — تنشال تلقائياً)' : 'سارية بلا نهاية — حدّد تاريخ بدء عمل الزوج لتنشال تلقائياً' ?></small>
                            <?php else: ?>
                                <div style="color:#991b1b;font-weight:800">كلا</div>
                            <?php endif; ?>
                            <?php if (canEdit()): ?>
                            <form method="POST" style="margin-top:4px"><?= csrfField() ?>
                                <input type="hidden" name="act" value="toggle_gsa"><input type="hidden" name="emp" value="<?= (int)$e2['id'] ?>">
                                <button class="btn <?= $e2['gsa'] ? 'btn-secondary' : 'btn-success' ?>" style="padding:2px 10px" onclick="return confirm('تُبدَّل زيادة الزوج ويُعاد الاحتساب — أكيد؟')"><?= $e2['gsa'] ? 'طفّيها' : 'ضوّيها' ?></button>
                            </form>
                            <form method="POST" style="display:flex;gap:4px;margin-top:4px;justify-content:center"><?= csrfField() ?>
                                <input type="hidden" name="act" value="spouse_start"><input type="hidden" name="emp" value="<?= (int)$e2['id'] ?>">
                                <input type="date" name="spouse_work_start_date" class="form-control" style="max-width:150px;padding:3px 8px" value="<?= e($sws ?? '') ?>" title="تاريخ بدء عمل الزوج">
                                <button class="btn btn-primary" style="padding:3px 10px" title="حفظ تاريخ بدء عمل الزوج">📅</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$emps): ?><tr><td colspan="6" class="text-center">لا متزوجين/أرامل بالمدرسة المختارة / Aucun</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php /* ===== ٢) اقتراحات قراءات إخراجات القيد ===== */
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
