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

    if ($act === 'set_gca' && $empOk) {
        // ☑ تشاك مارك مباشر («بس حط تشاك مارك على نعم او كلا بطبقو بملف الاستاذ» — 2026-08-23)
        $v = ((string)($_POST['val'] ?? '') === '1') ? 1 : 0;
        $db->prepare("UPDATE employees SET grant_children_addition = ? WHERE id = ?")->execute([$v, $empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'تنزيل الأولاد صار: ' . ($v ? 'نعم ✓' : 'كلا ✗') . ' — انطبق بملفه وأُعيد الاحتساب / Appliqué et recalculé.';
    } elseif ($act === 'set_gsa' && $empOk) {
        $v = ((string)($_POST['val'] ?? '') === '1') ? 1 : 0;
        $db->prepare("UPDATE employees SET grant_spouse_addition = ? WHERE id = ?")->execute([$v, $empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'زيادة الزوج صارت: ' . ($v ? 'نعم ✓' : 'كلا ✗') . ' — انطبقت بملفه وأُعيد الاحتساب / Appliqué et recalculé.';
    } elseif ($act === 'set_married' && $empOk) {
        // 💍 «إذا تزوّج نعم/كلا» (2026-09-06): نعم ⇒ متزوج (عدد أولاده القاصرين المسجَّلين)، كلا ⇒ أعزب
        $v = ((string)($_POST['val'] ?? '') === '1') ? 1 : 0;
        $cur = (string)$db->query("SELECT social_status FROM employees WHERE id = $empId")->fetchColumn();
        if ($v) {
            $nk = (int)$db->query("SELECT COUNT(*) FROM employee_children WHERE employee_id = $empId AND birth_date > DATE_SUB(CURDATE(), INTERVAL 18 YEAR)")->fetchColumn();
            $nk = min(5, $nk);
            $new = strpos($cur, 'marie') === 0 ? $cur : ('marie' . ($nk === 0 ? '_sans_enfants' : ($nk === 1 ? '_1_enfant' : '_' . $nk . '_enfants')));
        } else {
            $new = 'celibataire';
        }
        $db->prepare("UPDATE employees SET social_status = ? WHERE id = ?")->execute([$new, $empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'الوضع العائلي صار: ' . socialStatusLabel($new, 'ar') . ' — انحفظ بملفه وأُعيد الاحتساب / Enregistré et recalculé.';
    } elseif ($act === 'set_spouse_works' && $empOk) {
        // 👔 «الزوج يعمل نعم/كلا» (2026-09-06): نعم ⇒ تسقط زيادة الزوج حكماً
        $v = ((string)($_POST['val'] ?? '') === '1') ? 1 : 0;
        $db->prepare("UPDATE employees SET spouse_works = ? WHERE id = ?")->execute([$v, $empId]);
        $recalcFrom($empId);
        $_SESSION['flash_success'] = 'الزوج/الزوجة يعمل: ' . ($v ? 'نعم ✓ (تسقط زيادة الزوج)' : 'كلا ✗') . ' — انحفظ بملفه وأُعيد الاحتساب / Enregistré et recalculé.';
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

/* ===== ١) قرارات التنزيل لكل أستاذ — الجدول المفهوم (طلبه 2026-09-06):
 * «بدي حط قدام كل أستاذ: إذا تزوّج نعم/كلا → إذا نعم عدد الأولاد المستحقين تنزيل عائلي →
 *  الزوج يعمل نعم/كلا → وبعدين قدام الأولاد تنزيل عائلي نعم/كلا وقدام الزوج تنزيل عائلي نعم/كلا
 *  — هيك بيكون التقرير مفهوم أكتر». كل الأعمدة قرارات مباشرة (نعم/كلا) تنحفظ بملفه وتعيد
 * الاحتساب فوراً، وآخر عمود يعرض التنزيل السنوي الفعلي الناتج (نفس ما تحسبه الرواتب).
 * 📅 «لازم يبينو الاساتذة بالسنة اللي انا فيها مش بكل السنين» (2026-08-23): الفلتر
 * الموحّد yearEmploymentFilter — كل موظفي السنة الدراسية المعروضة (العازب يظهر «كلا»). */
[$tsYf, $tsYp] = yearEmploymentFilter(activeSchoolYear(), 'e.');
$empsQ = $db->prepare("SELECT e.id, e.school_id, e.social_status, e.spouse_works, e.spouse_work_start_date,
        COALESCE(e.apply_family_deduction,1) afd,
        COALESCE(e.grant_children_addition,0) gca, COALESCE(e.grant_spouse_addition,0) gsa,
        COALESCE(NULLIF(TRIM(CONCAT(e.first_name_ar,' ',e.last_name_ar)),''), TRIM(CONCAT(e.first_name_fr,' ',e.last_name_fr))) nm,
        s.name_ar school_name
    FROM employees e JOIN schools s ON s.id = e.school_id
    WHERE e.is_deleted = 0 AND " . schoolScopeWhere('e.school_id') . $tsYf . "
    ORDER BY s.id, (e.social_status LIKE 'marie%' OR e.social_status LIKE 'veuf%' OR e.social_status LIKE 'divorce%') DESC, nm");
$empsQ->execute($tsYp);
$emps = $empsQ->fetchAll();
$kidsByEmp = [];
foreach ($db->query("SELECT * FROM employee_children ORDER BY birth_date") as $k) $kidsByEmp[(int)$k['employee_id']][] = $k;
$today = date('Y-m-d');
$multiS = (count(activeSchoolIds()) !== 1);
$canE = canEdit();
/* زوج أزرار نعم/كلا (راديو يحفظ فوراً) */
$yesNo = function (string $act, int $empId, bool $on) use ($canE): string {
    if (!$canE) return '<div style="font-weight:800;color:' . ($on ? '#166534' : '#991b1b') . '">' . ($on ? 'نعم ✓' : 'كلا ✗') . '</div>';
    $h  = '<form method="POST" style="display:inline-flex;gap:4px">' . csrfField() . '<input type="hidden" name="act" value="' . e($act) . '"><input type="hidden" name="emp" value="' . $empId . '">';
    $h .= '<label style="display:inline-flex;align-items:center;gap:5px;background:' . ($on ? '#dcfce7' : '#f8fafc') . ';border:2px solid ' . ($on ? '#16a34a' : '#e2e8f0') . ';border-radius:8px;padding:2px 8px;font-weight:800;color:#166534;cursor:pointer">'
        . '<input type="radio" name="val" value="1"' . ($on ? ' checked' : '') . ' onchange="this.form.submit()" style="width:17px;height:17px;accent-color:#16a34a"> نعم</label>';
    $h .= '<label style="display:inline-flex;align-items:center;gap:5px;background:' . ($on ? '#f8fafc' : '#fee2e2') . ';border:2px solid ' . ($on ? '#e2e8f0' : '#dc2626') . ';border-radius:8px;padding:2px 8px;font-weight:800;color:#991b1b;cursor:pointer">'
        . '<input type="radio" name="val" value="0"' . ($on ? '' : ' checked') . ' onchange="this.form.submit()" style="width:17px;height:17px;accent-color:#dc2626"> كلا</label>';
    return $h . '</form>';
};
$dash = '<span style="color:#94a3b8">—</span>';
?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-sliders"></i> Décisions d'abattement familial par enseignant/employé</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">قرارات التنزيل العائلي — قدام كل أستاذ: متزوج؟ → عدد الأولاد المستحقين → الزوج يعمل؟ → تنزيل الأولاد نعم/كلا → تنزيل الزوج نعم/كلا → التنزيل السنوي الناتج</div>
    </h3></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:12px"><i class="fas fa-wand-magic-sparkles"></i>
            أي تبديل هون ينحفظ بملف الموظف ويعيد احتساب رواتبه فوراً.
            <strong>الأولاد المستحقون</strong> = من لم يبلغ 18 (كل ولد بتاريخ ولادته — تنزيله يسقط تلقائياً من شهر بلوغه الـ18).
            <strong>الزوج يعمل = نعم</strong> ⇒ تنزيل الزوج يسقط حكماً (ومن «تاريخ بدء عمله» إن حدّدته).
        </p>
        <style>.ts-dec td,.ts-dec th{padding:7px 8px !important}</style>
        <div class="report-table-wrap" dir="rtl"><table class="table ts-dec" dir="rtl" style="font-size:12px">
            <thead><tr>
                <th>الموظف</th>
                <?php if ($multiS): ?><th>المدرسة</th><?php endif; ?>
                <th>متزوج؟</th>
                <th>الأولاد المستحقون تنزيلاً<br><small>(دون 18)</small></th>
                <th>الزوج/الزوجة<br>يعمل؟</th>
                <th>تنزيل الأولاد</th>
                <th>تنزيل<br>الزوج/الزوجة</th>
                <th>التنزيل السنوي<br>الناتج</th>
            </tr></thead>
            <tbody>
            <?php foreach ($emps as $e2):
                $id2 = (int)$e2['id'];
                $ss = (string)$e2['social_status'];
                $isMarried = strpos($ss, 'marie') === 0;
                $isVeuf = (strpos($ss, 'veuf') === 0 || strpos($ss, 'divorce') === 0);
                $hasSpouseCat = $isMarried || $isVeuf; // فئة فيها أولاد محتملون
                $kids = $kidsByEmp[$id2] ?? [];
                $activeKids = 0; $lastB18 = null;
                foreach ($kids as $k) {
                    $b18k = date('Y-m-d', strtotime($k['birth_date'] . ' +18 years'));
                    if ($b18k > $today) { $activeKids++; if ($lastB18 === null || $b18k > $lastB18) $lastB18 = $b18k; }
                }
                // بلا أولاد مؤرَّخين: العدد الثابت من وضعه العائلي
                $fixedKids = 0;
                if (!$kids && $hasSpouseCat && preg_match('/_(\d)_enfant/', $ss, $mk)) $fixedKids = (int)$mk[1];
                $eligibleKids = $kids ? $activeKids : $fixedKids;
                $sws = ($e2['spouse_work_start_date'] && $e2['spouse_work_start_date'] !== '0000-00-00') ? $e2['spouse_work_start_date'] : null;
                $spouseWorks = ((int)$e2['spouse_works'] === 1) || ($sws !== null && $today >= $sws);
                $dedAnnual = (int)familyDeductionAnnual($ss, $e2['spouse_works'], $e2['afd'], $today, $e2['gsa'], $e2['gca'], $id2);
            ?>
                <tr<?= $hasSpouseCat ? '' : ' style="background:#fafafa"' ?>>
                    <td style="font-weight:700;white-space:nowrap"><?= e($e2['nm']) ?></td>
                    <?php if ($multiS): ?><td><small><?= e($e2['school_name']) ?></small></td><?php endif; ?>

                    <td style="white-space:nowrap;text-align:center">
                        <?php if ($isVeuf): ?>
                            <div style="font-weight:800;color:#475569"><?= strpos($ss, 'veuf') === 0 ? 'أرمل/ة' : 'مطلّق/ة' ?></div>
                            <small style="color:#94a3b8">(لا زوج — تُحتسب حصة الأولاد فقط)</small>
                        <?php else: ?>
                            <?= $yesNo('set_married', $id2, $isMarried) ?>
                        <?php endif; ?>
                    </td>

                    <td style="text-align:right;min-width:200px">
                        <?php if (!$hasSpouseCat): ?>
                            <?= $dash ?>
                        <?php else: ?>
                            <div style="font-weight:800;font-size:1.05em;color:<?= $eligibleKids ? '#166534' : '#64748b' ?>">
                                <?= $eligibleKids ?> <?= $eligibleKids === 1 ? 'ولد مستحقّ' : 'أولاد مستحقّون' ?>
                                <?php if (!$kids && $fixedKids): ?><small style="font-weight:600;color:#94a3b8">(من وضعه العائلي — بلا تواريخ)</small><?php endif; ?>
                            </div>
                            <details style="margin-top:3px"><summary style="cursor:pointer;color:#1F4E5F;font-weight:700"><small><?= $kids ? 'الأولاد بالتواريخ (' . count($kids) . ') ▾' : '+ سجّل الأولاد بتواريخهم ▾' ?></small></summary>
                            <div style="padding:4px 0 2px">
                            <?php foreach ($kids as $k):
                                $b18 = date('Y-m-d', strtotime($k['birth_date'] . ' +18 years'));
                                $stillMinor = $b18 > $today; ?>
                                <div style="display:flex;justify-content:space-between;gap:6px;align-items:center;<?= $stillMinor ? '' : 'color:#94a3b8' ?>">
                                    <span><?= $stillMinor ? '🟢' : '⚪' ?> <strong><?= e($k['child_name'] ?: 'ولد') ?></strong>
                                        <small><?= e(formatDate($k['birth_date'])) ?> → 18: <strong><?= e(formatDate($b18)) ?></strong><?= $stillMinor ? '' : ' (انتهى)' ?></small></span>
                                    <?php if ($canE): ?>
                                    <form method="POST" style="display:inline"><?= csrfField() ?>
                                        <input type="hidden" name="act" value="del_child"><input type="hidden" name="emp" value="<?= $id2 ?>"><input type="hidden" name="child_id" value="<?= (int)$k['id'] ?>">
                                        <button class="btn btn-danger" style="padding:1px 7px" title="حذف الولد" onclick="return confirm('يُحذف الولد ويُعاد الاحتساب — أكيد؟')">✕</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($canE): ?>
                            <form method="POST" style="display:flex;gap:4px;margin-top:5px"><?= csrfField() ?>
                                <input type="hidden" name="act" value="add_child"><input type="hidden" name="emp" value="<?= $id2 ?>">
                                <input type="text" name="child_name" class="form-control" placeholder="اسم الولد" style="max-width:95px;padding:2px 6px;font-size:12px">
                                <input type="date" name="child_birth" class="form-control" required style="max-width:125px;padding:2px 6px;font-size:12px">
                                <button class="btn btn-primary" style="padding:2px 8px">+ ولد</button>
                            </form>
                            <?php endif; ?>
                            </div></details>
                        <?php endif; ?>
                    </td>

                    <td style="white-space:nowrap;text-align:center">
                        <?php if (!$isMarried): ?>
                            <?= $dash ?>
                        <?php else: ?>
                            <?= $yesNo('set_spouse_works', $id2, (int)$e2['spouse_works'] === 1) ?>
                            <div><small><?php
                                if ($sws) echo 'يعمل من <strong>' . e(formatDate($sws)) . '</strong>' . ($today >= $sws ? '' : ' (لاحقاً)');
                            ?></small></div>
                            <?php if ($canE): ?>
                            <details style="margin-top:3px"><summary style="cursor:pointer;color:#1F4E5F;font-weight:700"><small>تاريخ بدء عمله ▾</small></summary>
                            <form method="POST" style="display:flex;gap:4px;margin-top:4px;justify-content:center"><?= csrfField() ?>
                                <input type="hidden" name="act" value="spouse_start"><input type="hidden" name="emp" value="<?= $id2 ?>">
                                <input type="date" name="spouse_work_start_date" class="form-control" style="max-width:125px;padding:2px 6px;font-size:12px" value="<?= e($sws ?? '') ?>" title="تاريخ بدء عمل الزوج">
                                <button class="btn btn-primary" style="padding:2px 8px" title="حفظ تاريخ بدء عمل الزوج">📅</button>
                            </form></details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <td style="white-space:nowrap;text-align:center">
                        <?php if (!$hasSpouseCat): ?>
                            <?= $dash ?>
                        <?php else: ?>
                            <?= $yesNo('set_gca', $id2, (bool)$e2['gca']) ?>
                            <div><small><?php
                                if ($e2['gca']) {
                                    if (!$eligibleKids) echo 'لا أولاد دون 18 ⇒ <strong>صفر تلقائياً</strong>';
                                    elseif ($kids) echo 'سارٍ <strong>إلى ' . e(formatDate($lastB18)) . '</strong> (بلوغ آخر ولد 18)';
                                    else echo 'حسب وضعه العائلي';
                                } else echo 'كلا — ما بينزل';
                            ?></small></div>
                        <?php endif; ?>
                    </td>

                    <td style="white-space:nowrap;text-align:center">
                        <?php if (!$isMarried): ?>
                            <?= $dash ?>
                        <?php else: ?>
                            <?= $yesNo('set_gsa', $id2, (bool)$e2['gsa']) ?>
                            <div><small><?php
                                if ($e2['gsa'] && $spouseWorks) echo '«الزوج يعمل» ⇒ <strong>تسقط حكماً</strong>';
                                elseif ($e2['gsa']) echo $sws ? 'سارية <strong>إلى ' . e(formatDate($sws)) . '</strong> (بدء عمل الزوج)' : 'سارية';
                                else echo 'كلا — ما بتنزل';
                            ?></small></div>
                        <?php endif; ?>
                    </td>

                    <td style="white-space:nowrap;text-align:center;font-weight:800;color:<?= $dedAnnual > 0 ? '#1F4E5F' : '#94a3b8' ?>">
                        <?= money($dedAnnual, null, ['mode' => 'lbp', 'withCur' => false]) ?>
                        <div><small style="font-weight:600;color:#64748b"><?php
                            if ((int)$e2['afd'] !== 1) echo 'التنزيل العائلي مطفأ بملفه';
                            elseif (!$hasSpouseCat) echo 'تنزيل العازب (الشخصي)';
                            else {
                                $parts = ['الشخصي'];
                                if ($isMarried && $e2['gsa'] && !$spouseWorks) $parts[] = 'الزوج';
                                if ($e2['gca'] && $eligibleKids) $parts[] = 'الأولاد';
                                echo implode(' + ', $parts);
                            }
                        ?></small></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$emps): ?><tr><td colspan="8" class="text-center">لا موظفين بالنطاق المختار / Aucun</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php /* ===== ٢) اقتراحات قراءات إخراجات القيد ===== */
// 🏫 «لازم يبين موظفين المدرسة اللي مختارها» (2026-08-25): الاقتراحات بنطاق المدرسة المختارة فقط
// 📅 «بدي اساتذة نفس السنة» (p1 — 2026-08-25): وبنفس فلتر السنة الموحّد كجدول القرارات فوق —
//    موظفو السنة الدراسية المعروضة فقط (لا تاركين قدامى متل زينة نجم 2015 أو من بلا رواتب)
$rowsQ = $db->prepare("SELECT ts.*, s.name_ar school_name FROM tax_suggestions ts
                    JOIN employees e ON e.id = ts.employee_id AND e.is_deleted = 0
                    LEFT JOIN schools s ON s.id = ts.school_id
                    WHERE " . schoolScopeWhere('ts.school_id') . $tsYf . "
                    ORDER BY FIELD(ts.status,'pending','applied','dismissed'), ts.id");
$rowsQ->execute($tsYp);
$rows = $rowsQ->fetchAll();
// 🟢 «شو يعني طبّق؟ الأفضل نعم أو كلا — نعم = بدي التنزيل، كلا = ما بدي» (2026-08-25)
$statusBadge = function ($s) {
    return [
        'pending'   => '<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:999px;font-weight:700">⏳ بانتظار قرارك / En attente</span>',
        'applied'   => '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-weight:700">✓ نعم — مطبَّق / Oui</span>',
        'dismissed' => '<span style="background:#e2e8f0;color:#475569;padding:3px 10px;border-radius:999px;font-weight:700">✗ كلا / Non</span>',
    ][$s] ?? e($s);
};
$propLabel = function ($json) {
    $p = json_decode((string)$json, true) ?: [];
    if (!$p) return '<span style="color:#94a3b8">— (معلومة فقط، بلا تعديل)</span>';
    $out = [];
    if (isset($p['social_status'])) $out[] = 'الوضع: <strong>' . e(socialStatusLabel($p['social_status'], 'ar')) . '</strong>';
    if (isset($p['number_of_children'])) $out[] = 'أولاد دون 18: <strong>' . (int)$p['number_of_children'] . '</strong>';
    if (isset($p['grant_children_addition'])) $out[] = 'تنزيل الأولاد: <strong>' . ($p['grant_children_addition'] ? 'نعم ✓' : 'كلا ✗') . '</strong>';
    if (isset($p['grant_spouse_addition'])) $out[] = 'زيادة الزوج: <strong>' . ($p['grant_spouse_addition'] ? 'نعم — تُعطى' : 'كلا — مطفأة') . '</strong>';
    return implode(' · ', $out);
};
// النصوص المخزّنة القديمة كانت تقول «اكبس طبّق وإلا تجاهل» — نعرضها بلغة نعم/كلا
$fixDetails = fn($t) => str_replace('اكبس «طبّق»، وإلا «تجاهل»', 'اكبس «نعم»، وإذا ما بدك التنزيل «كلا»', (string)$t);
?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-lightbulb"></i> Suggestions — extraits d'état civil familial</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">اقتراحات من قراءة إخراجات القيد العائلية — أنت بتقرّر: <b>نعم = بدي التنزيل/التعديل ينطبق</b> · <b>كلا = ما بدي</b> (المدرسة المختارة وأساتذة السنة المعروضة فقط)</div>
    </h3></div>
    <div class="card-body">
        <div class="report-table-wrap" dir="rtl"><table class="table" dir="rtl" style="font-size:13px">
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
                    <td style="text-align:right"><small><?= e($fixDetails($r['details'])) ?></small></td>
                    <td style="text-align:right"><small><?= $propLabel($r['proposed']) ?></small></td>
                    <td><?= $statusBadge($r['status']) ?></td>
                    <?php if (canEdit()): ?>
                    <td style="white-space:nowrap">
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline"><?= csrfField() ?>
                                <input type="hidden" name="sid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="apply">
                                <button class="btn btn-success" title="بدي التنزيل/التعديل ينطبق على ملف الموظف"
                                        onclick="return confirm('نعم = يُطبَّق على ملف الموظف ويُعاد احتساب رواتبه — أكيد؟')"><i class="fas fa-check"></i> نعم</button>
                            </form>
                            <form method="POST" style="display:inline"><?= csrfField() ?>
                                <input type="hidden" name="sid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="dismiss">
                                <button class="btn btn-secondary" title="ما بدي — بيضل كل شي متل ما هو"><i class="fas fa-xmark"></i> كلا</button>
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
