<?php
/**
 * فتح سنة دراسية جديدة لمدرسة: ينقل الأساتذة الفاعلين (غير التاركين) للسنة الجديدة
 * بحساب رواتبهم لكل أشهرها بدرجتهم الحالية.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'open_year';
$pageTitle = 'Ouvrir une année / فتح سنة دراسية';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    $schoolId = isSuperAdmin() ? (int)($_POST['school_id'] ?? 0) : currentSchoolId();
    $newYear  = trim($_POST['new_year'] ?? '');
    if ($schoolId <= 0) {
        $_SESSION['flash_error'] = 'اختر مدرسة';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $newYear)) {
        $_SESSION['flash_error'] = 'اختر سنة دراسية صحيحة';
    } else {
        [$y1, $y2] = schoolYearToYears($newYear);
        // الأساتذة/الموظفون الفاعلون غير التاركين بهالمدرسة
        $emps = $db->prepare("SELECT id, payment_months_per_year, employee_type, base_salary_usd, contract_salary_lbp FROM employees
                              WHERE school_id = ? AND is_deleted = 0 AND status = 'actif'
                                AND left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL");
        $emps->execute([$schoolId]);
        // مصدر النقل للموظف المنقول بلا إعداد: آخر راتب فعلي معروف قبل السنة الجديدة
        $srcStmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND net_salary_lbp > 0
                                 AND (year < ? OR (year = ? AND month < 10)) ORDER BY year DESC, month DESC LIMIT 1");
        $n = 0; $carried = 0;
        foreach ($emps->fetchAll() as $emp) {
            $months = ((int)$emp['payment_months_per_year'] === 10)
                ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
                : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
            // الموظف المُعَدّ (ملاك أو أساس>0) يُحسب بالقانون الساري؛ المنقول بلا إعداد يُنقل راتبه كما هو
            $hasConfig = ($emp['employee_type'] === 'enseignant_titulaire'
                       || (float)$emp['base_salary_usd'] > 0
                       || (float)$emp['contract_salary_lbp'] > 0);
            if ($hasConfig) {
                foreach ($months as [$m, $y]) {
                    try { (new PayrollCalculator((int)$emp['id'], $m, $y))->calculateAndSave(); } catch (Exception $e) {}
                }
                $n++;
            } else {
                // نقل راتب المتعاقد من السنة السابقة **شهر مقابل شهر** (الأدق) لكل أشهر السنة الجديدة:
                // كل شهر جديد = نفس الشهر من السنة السابقة (year-1)؛ وإن غاب ذلك الشهر يُستعمل آخر راتب
                // معروف (fallback). هكذا لا يُصفَّر المتعاقد عند فتح السنة، وتنتقل تفاصيل كل شهر بدقّة.
                $prevByMonth = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND year = ? AND month = ? AND net_salary_lbp > 0 LIMIT 1");
                $srcStmt->execute([(int)$emp['id'], $y1, $y1]);
                $fallback = $srcStmt->fetch(PDO::FETCH_ASSOC);
                $anyCarried = false;
                foreach ($months as [$m, $y]) {
                    $prevByMonth->execute([(int)$emp['id'], $y - 1, $m]);
                    $src = $prevByMonth->fetch(PDO::FETCH_ASSOC);
                    if (!$src) $src = $fallback;          // الشهر المطابق مفقود → آخر راتب معروف
                    if (!$src) continue;                  // لا مصدر إطلاقاً → يحتاج إدخالاً يدوياً
                    unset($src['id'], $src['created_at'], $src['updated_at']);
                    $src['month'] = $m; $src['year'] = $y; $src['school_year'] = $newYear;
                    $src['is_paid'] = 0; $src['paid_date'] = null;
                    $cols = array_keys($src);
                    $colList = '`' . implode('`,`', $cols) . '`';
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $updc = [];
                    foreach ($cols as $c) $updc[] = "`$c`=VALUES(`$c`)";
                    $sql = "INSERT INTO monthly_salaries ($colList) VALUES ($ph) ON DUPLICATE KEY UPDATE " . implode(',', $updc);
                    try { $db->prepare($sql)->execute(array_values($src)); $anyCarried = true; } catch (Exception $e) {}
                }
                if ($anyCarried) $carried++;
            }
        }
        $_SESSION['active_school_year'] = $newYear;
        $_SESSION['flash_success'] = "تم فتح السنة $newYear للمدرسة — $n موظف محسوب بالقانون و $carried موظف نُقِل راتبه كما هو (التاركون لم يُنقَلوا).";
        header('Location: ' . BASE_URL . 'pages/open_year.php');
        exit;
    }
    header('Location: ' . BASE_URL . 'pages/open_year.php');
    exit;
}

// تفريغ سنة دراسية مستقبلية: يحذف كل رواتب تلك السنة (للمدرسة أو لكل المدارس) — للسنين
// اللاحقة للسنة الجارية فقط (أمان: لا يمكن مسح رواتب السنة الحالية أو السابقة من هنا).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_year') {
    $clrYear = trim($_POST['clear_year_val'] ?? '');
    $allSch  = isSuperAdmin() && ($_POST['clear_school_id'] ?? '') === 'all';
    $schoolId = $allSch ? 0 : (isSuperAdmin() ? (int)($_POST['clear_school_id'] ?? 0) : currentSchoolId());
    if (!preg_match('/^\d{4}-\d{4}$/', $clrYear)) {
        $_SESSION['flash_error'] = 'اختر سنة دراسية صحيحة';
    } elseif ($clrYear <= currentSchoolYear()) {
        $_SESSION['flash_error'] = 'لا يمكن تفريغ السنة الجارية أو سنة سابقة — فقط السنين المستقبلية.';
    } elseif (!$allSch && $schoolId <= 0) {
        $_SESSION['flash_error'] = 'اختر مدرسة (أو «كل المدارس»)';
    } else {
        if ($allSch) {
            $st = $db->prepare("DELETE FROM monthly_salaries WHERE school_year = ?");
            $st->execute([$clrYear]);
        } else {
            $st = $db->prepare("DELETE FROM monthly_salaries WHERE school_year = ? AND school_id = ?");
            $st->execute([$clrYear, $schoolId]);
        }
        $deleted = $st->rowCount();
        $scope = $allSch ? 'كل المدارس' : ('مدرسة ' . (currentSchool()['name_ar'] ?? $schoolId));
        $_SESSION['flash_success'] = "تم تفريغ السنة $clrYear ($scope) — حُذف $deleted صفّ راتب. صارت السنة فاضية، فيك تفتحها من جديد وقت تجهّز أساتذتها.";
    }
    header('Location: ' . BASE_URL . 'pages/open_year.php');
    exit;
}

include __DIR__ . '/../includes/header.php';

// السنوات الموجودة لكل مدرسة (للعرض)
$existing = $db->query("SELECT ms.school_id, ms.school_year, COUNT(DISTINCT ms.employee_id) emps
                        FROM monthly_salaries ms GROUP BY ms.school_id, ms.school_year")->fetchAll();
$bySchool = [];
foreach ($existing as $r) $bySchool[$r['school_id']][$r['school_year']] = $r['emps'];

$cyN = (int)date('Y'); $cmN = (int)date('n'); $startN = ($cmN >= 10) ? $cyN : $cyN - 1;
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-folder-plus"></i> فتح سنة دراسية جديدة / Ouvrir une année</h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            اختر مدرسة وسنة دراسية جديدة. البرنامج بينقل **كل الأساتذة والموظفين الفاعلين** (غير التاركين — اللي محطوط إلهم تاريخ ترك ما بينتقلوا) للسنة الجديدة بدرجتهم الحالية.
        </div>
        <form method="POST" onsubmit="return confirm('فتح السنة المختارة لهذه المدرسة ونقل الموظفين الفاعلين؟');">
            <input type="hidden" name="action" value="open">
            <div class="form-row cols-3">
                <?php if (isSuperAdmin()): ?>
                <div class="form-group mb-0">
                    <label class="form-label">المدرسة / École</label>
                    <select name="school_id" class="form-select" required>
                        <option value="">— اختر —</option>
                        <?php foreach (allSchools() as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= currentSchoolId() === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="school_id" value="<?= currentSchoolId() ?>">
                <?php endif; ?>
                <div class="form-group mb-0">
                    <label class="form-label">السنة الجديدة / Nouvelle année</label>
                    <select name="new_year" class="form-select" required>
                        <?php for ($yy = $startN + 1; $yy >= 2006; $yy--): $sy = $yy . '-' . ($yy + 1); ?>
                            <option value="<?= $sy ?>" <?= $sy === ($startN + 1) . '-' . ($startN + 2) ? 'selected' : '' ?>><?= $sy ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-folder-plus"></i> افتح السنة / Ouvrir</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border:2px solid #e11d48">
    <div class="card-header" style="background:#fdeef1"><h3 style="color:#b91c3a"><i class="fas fa-eraser"></i> تفريغ سنة دراسية مستقبلية</h3></div>
    <div class="card-body">
        <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b">
            <i class="fas fa-exclamation-triangle"></i>
            بيحذف <strong>كل رواتب السنة المختارة</strong> فتصير فاضية (مثلاً إذا انفتحت بالغلط أو بدّك تجهّزها من جديد). للأمان: <strong>بس السنين المستقبلية</strong> (السنة الجارية <?= e(currentSchoolYear()) ?> والسابقة ما بتنحذف من هون). ملفات الأساتذة ودرجاتهم بتضل سليمة — بس بيتفضّى حساب رواتب تلك السنة.
        </div>
        <form method="POST" onsubmit="return confirm('متأكّد إنّك بدّك تفرّغ كل رواتب السنة المختارة؟ بترجع تفتحها وقت بدّك.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_year">
            <div class="form-row cols-3">
                <?php if (isSuperAdmin()): ?>
                <div class="form-group mb-0">
                    <label class="form-label">المدرسة / École</label>
                    <select name="clear_school_id" class="form-select" required>
                        <option value="all">🏫 كل المدارس</option>
                        <?php foreach (allSchools() as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="clear_school_id" value="<?= currentSchoolId() ?>">
                <?php endif; ?>
                <div class="form-group mb-0">
                    <label class="form-label">السنة المراد تفريغها</label>
                    <select name="clear_year_val" class="form-select" required>
                        <?php for ($yc = $startN + 3; $yc >= $startN + 1; $yc--): $syc = $yc . '-' . ($yc + 1); ?>
                            <option value="<?= $syc ?>"><?= $syc ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn w-100" style="background:#e11d48;color:#fff"><i class="fas fa-eraser"></i> فرّغ السنة</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-table"></i> السنوات المفتوحة لكل مدرسة</h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>المدرسة / École</th><th>السنوات الموجودة</th></tr></thead>
            <tbody>
                <?php foreach (allSchools() as $s): $sy = $bySchool[$s['id']] ?? []; krsort($sy); ?>
                    <tr>
                        <td><strong><?= e($s['name_ar'] ?: $s['name_fr']) ?></strong></td>
                        <td><?= $sy ? implode('، ', array_keys($sy)) : '<span style="color:var(--gray-400)">— لا يوجد —</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
