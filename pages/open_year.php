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

/**
 * يطبّق «درجات القانون» المستحقّة للأستاذ الملاك عند فتح سنة جديدة:
 *   (1) التدرّج العادي  → مؤرّخ {y1}-10-01 (تشرين الأول)
 *   (2) الدرجات الاستثنائية → مؤرّخة {y2}-01-01 (كانون الثاني)
 * يعتمد على **دالة القانون المعتمدة المختبَرة `buildLegalGradeHistory` (وضع dryRun، بلا أي كتابة)**
 * لتحديد مقدار كل نوع للسنة الجديدة = الفرق بين (درجة نهاية السنة الجديدة) و(درجة نهاية السنة السابقة)
 * قانوناً — فلا تخمين، ونفس منطق 4+4+2 والقوانين والإجازة التعليمية والحقب يُحترَم تماماً.
 *  - الأساس (grade_before) = درجة الأستاذ في نهاية السنة السابقة من السجلّ (تشمل سنين فُتحت سابقاً)
 *    فيصحّ عند فتح سنين متتالية. لا يلمس current_grade ولا السنين السابقة. السقف 52.
 *  - idempotent: إن وُجد حدث بتاريخ السنة الجديدة (فُتحت سابقاً) لا يُعاد.
 *  - أمان: إن كانت الدرجة المخزّنة لا تطابق القانون (مضبوطة يدوياً/منقولة) → تدرّج عادي بسيط (≤0.5)
 *    فقط بلا استثنائي (لا نخمّن استثنائياً لأستاذ خارج القانون).
 * يُرجع عدد الأحداث المضافة (0/1/2).
 */
function applyLegalGradesForNewYear($db, $empId, $y1, $y2) {
    $e = $db->prepare("SELECT employee_type, current_grade FROM employees WHERE id = ?");
    $e->execute([$empId]);
    $e = $e->fetch(PDO::FETCH_ASSOC);
    if (!$e || $e['employee_type'] !== 'enseignant_titulaire') return 0;

    $ordDate = sprintf('%04d-10-01', $y1);  // تشرين الأول للسنة الجديدة
    $excDate = sprintf('%04d-01-01', $y2);  // كانون الثاني للسنة الجديدة
    // idempotent: السنة الجديدة فُتحت سابقاً لهذا الأستاذ؟ (الأحداث المضافة من «فتح السنة» تُعلَّم
    // بالـnotes؛ ملاحظة: العمود reason ENUM لا يقبل 'exceptional' فيُخزَّن فارغاً — لذا نعتمد notes).
    $chk = $db->prepare("SELECT 1 FROM employee_grade_history WHERE employee_id=? AND change_date IN (?,?) AND notes LIKE '%(فتح السنة)%' LIMIT 1");
    $chk->execute([$empId, $ordDate, $excDate]);
    if ($chk->fetchColumn()) return 0;

    // الأساس = درجة نهاية السنة السابقة من السجلّ (تشمل أي سنين فُتحت سابقاً)
    $rs = $db->prepare("SELECT grade_after FROM employee_grade_history WHERE employee_id=? AND grade_after>=1 AND change_date<? ORDER BY change_date DESC, id DESC LIMIT 1");
    $rs->execute([$empId, $ordDate]);
    $running = $rs->fetchColumn();
    $running = ($running === false || $running === null) ? (float)$e['current_grade'] : (float)$running;
    if ($running >= 52) return 0;

    // مقدار درجات السنة الجديدة قانوناً (بلا كتابة): فرق نهاية السنة الجديدة عن نهاية السنة السابقة
    try {
        $prev = buildLegalGradeHistory($empId, sprintf('%04d-09-30', $y1), true); // cAY = y1-1 (نهاية السنة السابقة)
        $new  = buildLegalGradeHistory($empId, sprintf('%04d-09-30', $y2), true); // cAY = y1   (نهاية السنة الجديدة)
    } catch (Exception $ex) { return 0; }
    $ordDelta = round((float)$new['ordinary']    - (float)$prev['ordinary'], 1);
    $excDelta = round((float)$new['exceptional'] - (float)$prev['exceptional'], 1);
    // أمان: درجة مخزّنة لا تطابق القانون → تدرّج عادي بسيط فقط
    if (abs((float)$prev['final_grade'] - $running) >= 0.01) { $ordDelta = min(max($ordDelta, 0.0), 0.5); $excDelta = 0.0; }

    $applied = 0;
    // (1) التدرّج العادي — تشرين الأول
    if ($ordDelta > 0 && $running < 52) {
        $after = min(52.0, round($running + $ordDelta, 1));
        if ($after > $running) {
            $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,notes)
                          VALUES (?,?,?,?,1,?,'biennial_promotion','تدرّج عادي سنوي (فتح السنة)')")
               ->execute([$empId, $running, $after, round($after-$running,1), $ordDate]);
            $running = $after; $applied++;
        }
    }
    // (2) الدرجات الاستثنائية — كانون الثاني
    if ($excDelta > 0 && $running < 52) {
        $after = min(52.0, round($running + $excDelta, 1));
        if ($after > $running) {
            $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,notes)
                          VALUES (?,?,?,?,1,?,'exceptional','درجات استثنائية بالقانون (فتح السنة)')")
               ->execute([$empId, $running, $after, round($after-$running,1), $excDate]);
            $applied++;
        }
    }
    return $applied;
}

/**
 * نسخ علاوات أستاذ (إضافات/تعويض نقل) من السنة السابقة إلى السنة الجديدة عند فتحها.
 * $mode: 'same' (كما هي) | 'none' (لا تنسخ) | 'pct' (مع زيادة/نقص بنسبة $pct%).
 * idempotent: لا تكرّر علاوة (نوع+فترة) موجودة أصلاً للسنة الجديدة. علاوات النسبة المئوية تبقى كما هي.
 */
function copyYearBonuses($db, $empId, $prevSY, $newSY, array $types, $mode, $pct) {
    if ($mode === 'none' || !$types) return 0;
    $factor = ($mode === 'pct') ? (1 + (float)$pct / 100) : 1.0;
    $in = implode(',', array_fill(0, count($types), '?'));
    $sel = $db->prepare("SELECT * FROM employee_bonuses WHERE employee_id=? AND school_year=? AND is_active=1 AND bonus_type IN ($in)");
    $sel->execute(array_merge([$empId, $prevSY], $types));
    $cnt = 0;
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $chk = $db->prepare("SELECT 1 FROM employee_bonuses WHERE employee_id=? AND school_year=? AND bonus_type=? AND period_number=? LIMIT 1");
        $chk->execute([$empId, $newSY, $b['bonus_type'], $b['period_number']]);
        if ($chk->fetchColumn()) continue;
        $amt = ($b['value_type'] === 'percent') ? (float)$b['amount'] : round((float)$b['amount'] * $factor, 2);
        $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active)
                      VALUES (?,?,?,?,?,?,?,?,?,1)")
           ->execute([$empId, $b['bonus_type'], $b['period_number'], $newSY, $amt, $b['value_type'], $b['currency'], $b['start_month'], $b['end_month']]);
        $cnt++;
    }
    return $cnt;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    $schoolId = isSuperAdmin() ? (int)($_POST['school_id'] ?? 0) : currentSchoolId();
    $newYear  = trim($_POST['new_year'] ?? '');
    if ($schoolId <= 0) {
        $_SESSION['flash_error'] = 'اختر مدرسة';
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $newYear)) {
        $_SESSION['flash_error'] = 'اختر سنة دراسية صحيحة';
    } else {
        [$y1, $y2] = schoolYearToYears($newYear);
        $prevSY = ($y1 - 1) . '-' . $y1;   // السنة السابقة (مصدر الإضافات/النقل عند النقل)
        // خيارات الإضافات (الأجر الإضافي + المكافأة) وتعويض النقل: same=نفس السنة الماضية / none=بلا / pct=بنسبة
        $addMode   = in_array($_POST['add_mode']   ?? 'same', ['same','none','pct'], true) ? ($_POST['add_mode']   ?? 'same') : 'same';
        $transMode = in_array($_POST['trans_mode'] ?? 'same', ['same','none','pct'], true) ? ($_POST['trans_mode'] ?? 'same') : 'same';
        $addPct    = (float)($_POST['add_pct']   ?? 0);
        $transPct  = (float)($_POST['trans_pct'] ?? 0);
        $addFactor   = $addMode   === 'none' ? 0.0 : ($addMode   === 'pct' ? 1 + $addPct/100   : 1.0);
        $transFactor = $transMode === 'none' ? 0.0 : ($transMode === 'pct' ? 1 + $transPct/100 : 1.0);
        // الأساتذة/الموظفون الفاعلون غير التاركين بهالمدرسة
        $emps = $db->prepare("SELECT id, payment_months_per_year, employee_type, base_salary_usd, contract_salary_lbp FROM employees
                              WHERE school_id = ? AND is_deleted = 0 AND status = 'actif'
                                AND left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL");
        $emps->execute([$schoolId]);
        // مصدر النقل للموظف المنقول بلا إعداد: آخر راتب فعلي معروف قبل السنة الجديدة
        $srcStmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND net_salary_lbp > 0
                                 AND (year < ? OR (year = ? AND month < 10)) ORDER BY year DESC, month DESC LIMIT 1");
        $n = 0; $carried = 0; $promoted = 0;
        foreach ($emps->fetchAll() as $emp) {
            $months = ((int)$emp['payment_months_per_year'] === 10)
                ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
                : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
            // الموظف المُعَدّ (ملاك أو أساس>0) يُحسب بالقانون الساري؛ المنقول بلا إعداد يُنقل راتبه كما هو
            $hasConfig = ($emp['employee_type'] === 'enseignant_titulaire'
                       || (float)$emp['base_salary_usd'] > 0
                       || (float)$emp['contract_salary_lbp'] > 0);
            if ($hasConfig) {
                // الملاك: طبّق درجات القانون المستحقّة لهذه السنة (تدرّج عادي 1/10 + استثنائية 1/1) قبل الحساب
                if ($emp['employee_type'] === 'enseignant_titulaire'
                    && applyLegalGradesForNewYear($db, (int)$emp['id'], $y1, $y2)) $promoted++;
                // انقل الإضافات وتعويض النقل للسنة الجديدة حسب اختيار المستخدم (قبل الحساب ليقرأها المحرّك)
                copyYearBonuses($db, (int)$emp['id'], $prevSY, $newYear, ['prime_fixe','aide_complementaire'], $addMode, $addPct);
                copyYearBonuses($db, (int)$emp['id'], $prevSY, $newYear, ['transport_complement','transport_daily'], $transMode, $transPct);
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
                    // المتعاقد المنقول بالصفّ: طبّق اختيار الإضافات/النقل (none=صفّر، pct=نسبة) وصحّح الصافي/المجموع
                    if ($addMode !== 'same' || $transMode !== 'same') {
                        $oldAdd = (float)($src['extra_lbp'] ?? 0) + (float)($src['prime_fixe_lbp'] ?? 0) + (float)($src['aide_complementaire_lbp'] ?? 0);
                        $oldTr  = (float)($src['transport_complement_lbp'] ?? 0) + (float)($src['transport_lbp'] ?? 0);
                        foreach (['extra_lbp','prime_fixe_lbp','aide_complementaire_lbp'] as $c) if (isset($src[$c])) $src[$c] = round((float)$src[$c] * $addFactor);
                        foreach (['transport_complement_lbp','transport_lbp'] as $c) if (isset($src[$c])) $src[$c] = round((float)$src[$c] * $transFactor);
                        $newAdd = (float)($src['extra_lbp'] ?? 0) + (float)($src['prime_fixe_lbp'] ?? 0) + (float)($src['aide_complementaire_lbp'] ?? 0);
                        $newTr  = (float)($src['transport_complement_lbp'] ?? 0) + (float)($src['transport_lbp'] ?? 0);
                        if (isset($src['net_salary_lbp'])) $src['net_salary_lbp'] = max(0, round((float)$src['net_salary_lbp'] + ($newAdd - $oldAdd)));
                        if (isset($src['total_due_lbp'])) $src['total_due_lbp'] = max(0, round((float)$src['total_due_lbp'] + ($newAdd - $oldAdd) + ($newTr - $oldTr)));
                    }
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
        $_SESSION['flash_success'] = "تم فتح السنة $newYear للمدرسة — $n موظف محسوب بالقانون (منهم $promoted أستاذ ملاك طُبّقت درجاتهم المستحقّة تلقائياً: تدرّج عادي + درجات استثنائية بالقانون) و $carried موظف نُقِل راتبه كما هو (التاركون لم يُنقَلوا).";
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
        // تواريخ درجات القانون المضافة آلياً لهذه السنة عند فتحها: التدرّج العادي (1/10 من سنة البدء)
        // والدرجات الاستثنائية (1/1 من سنة الانتهاء). بما أنّ السنة مستقبلية، أي حدث بهذين التاريخين
        // أضافه «فتح السنة» حصراً → يُحذفان ليُعكَس الفتح بالكامل.
        $clrY1 = (int)substr($clrYear, 0, 4);
        $clrOrdDate = sprintf('%04d-10-01', $clrY1);
        $clrExcDate = sprintf('%04d-01-01', $clrY1 + 1);
        if ($allSch) {
            $st = $db->prepare("DELETE FROM monthly_salaries WHERE school_year = ?");
            $st->execute([$clrYear]);
            $db->prepare("DELETE FROM employee_grade_history WHERE change_date IN (?,?) AND notes LIKE '%(فتح السنة)%'")
               ->execute([$clrOrdDate, $clrExcDate]);
            // العلاوات (إضافات/نقل) المنسوخة لهذه السنة عند فتحها
            $db->prepare("DELETE FROM employee_bonuses WHERE school_year = ?")->execute([$clrYear]);
        } else {
            $st = $db->prepare("DELETE FROM monthly_salaries WHERE school_year = ? AND school_id = ?");
            $st->execute([$clrYear, $schoolId]);
            $db->prepare("DELETE FROM employee_grade_history WHERE change_date IN (?,?) AND notes LIKE '%(فتح السنة)%'
                          AND employee_id IN (SELECT id FROM employees WHERE school_id = ?)")
               ->execute([$clrOrdDate, $clrExcDate, $schoolId]);
            $db->prepare("DELETE FROM employee_bonuses WHERE school_year = ?
                          AND employee_id IN (SELECT id FROM employees WHERE school_id = ?)")
               ->execute([$clrYear, $schoolId]);
        }
        $deleted = $st->rowCount();
        $scope = $allSch ? 'كل المدارس' : ('مدرسة ' . (currentSchool()['name_ar'] ?? $schoolId));
        $_SESSION['flash_success'] = "تم تفريغ السنة $clrYear ($scope) — حُذف $deleted صفّ راتب. صارت السنة فاضية، فيك تفتحها من جديد وقت تجهّز أساتذتها.";
    }
    header('Location: ' . BASE_URL . 'pages/open_year.php');
    exit;
}

// ⭐ تعديل الإضافات/تعويض النقل لسنة مفتوحة (شك مارك) — يضيف أو يشيل بلا تفريغ السنة كلها:
// مفعّل = ينقل من السنة الماضية (إن لم يكن موجوداً)؛ مطفأ = يشيلهم. ثم يعيد حساب رواتب تلك السنة فقط.
// محصور بالسنين المستقبلية (المفتوحة للتجهيز) حفاظاً على السنة الجارية.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_additions') {
    $schoolId = isSuperAdmin() ? (int)($_POST['ba_school_id'] ?? 0) : currentSchoolId();
    $yr = trim($_POST['ba_year'] ?? '');
    $addOn   = !empty($_POST['ba_add']);
    $transOn = !empty($_POST['ba_trans']);
    if (!preg_match('/^\d{4}-\d{4}$/', $yr)) {
        $_SESSION['flash_error'] = 'اختر سنة صحيحة';
    } elseif ($yr <= currentSchoolYear()) {
        $_SESSION['flash_error'] = 'هذا الخيار للسنين المستقبلية (المفتوحة للتجهيز) فقط.';
    } elseif ($schoolId <= 0) {
        $_SESSION['flash_error'] = 'اختر مدرسة';
    } else {
        [$y1, $y2] = schoolYearToYears($yr);
        $prevSY = ($y1 - 1) . '-' . $y1;
        $addTypes = ['prime_fixe', 'aide_complementaire'];
        $trTypes  = ['transport_complement', 'transport_daily'];
        $emps = $db->prepare("SELECT id, payment_months_per_year, employee_type, base_salary_usd, contract_salary_lbp
            FROM employees WHERE school_id = ? AND is_deleted = 0 AND status = 'actif'
              AND left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL");
        $emps->execute([$schoolId]);
        $cnt = 0;
        foreach ($emps->fetchAll(PDO::FETCH_ASSOC) as $emp) {
            $id = (int)$emp['id'];
            // العلاوات: مفعّل → انقل من السنة الماضية (idempotent)؛ مطفأ → احذفها لهذه السنة
            if ($addOn) copyYearBonuses($db, $id, $prevSY, $yr, $addTypes, 'same', 0);
            else $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND school_year=? AND bonus_type IN ('prime_fixe','aide_complementaire')")->execute([$id, $yr]);
            if ($transOn) copyYearBonuses($db, $id, $prevSY, $yr, $trTypes, 'same', 0);
            else $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND school_year=? AND bonus_type IN ('transport_complement','transport_daily')")->execute([$id, $yr]);

            $hasConfig = ($emp['employee_type'] === 'enseignant_titulaire' || (float)$emp['base_salary_usd'] > 0 || (float)$emp['contract_salary_lbp'] > 0);
            $months = ((int)$emp['payment_months_per_year'] === 10)
                ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
                : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
            if ($hasConfig) {
                // الملاك/المُعَدّ: أعِد حساب أشهر السنة فقط (المحرّك يقرأ حالة العلاوات الجديدة) — لا يمسّ الدرجات
                foreach ($months as [$m, $y]) { try { (new PayrollCalculator($id, $m, $y))->calculateAndSave(); } catch (Exception $e) {} }
            } else {
                // المتعاقد المنقول بالصفّ: عدّل أعمدة الإضافات/النقل مباشرةً وصحّح الصافي/المجموع
                foreach ($months as [$m, $y]) {
                    $rs = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id=? AND year=? AND month=? LIMIT 1");
                    $rs->execute([$id, $y, $m]); $r = $rs->fetch(PDO::FETCH_ASSOC); if (!$r) continue;
                    $oldAdd = (float)$r['extra_lbp'] + (float)$r['prime_fixe_lbp'] + (float)$r['aide_complementaire_lbp'];
                    $oldTr  = (float)$r['transport_complement_lbp'] + (float)$r['transport_lbp'];
                    if (!$addOn) { $r['extra_lbp']=0; $r['prime_fixe_lbp']=0; $r['aide_complementaire_lbp']=0; }
                    elseif ($oldAdd == 0) { $p=$db->prepare("SELECT extra_lbp,prime_fixe_lbp,aide_complementaire_lbp FROM monthly_salaries WHERE employee_id=? AND year=? AND month=? AND (extra_lbp+prime_fixe_lbp+aide_complementaire_lbp)>0 LIMIT 1"); $p->execute([$id,$y-1,$m]); if($ps=$p->fetch(PDO::FETCH_ASSOC)){ $r['extra_lbp']=$ps['extra_lbp']; $r['prime_fixe_lbp']=$ps['prime_fixe_lbp']; $r['aide_complementaire_lbp']=$ps['aide_complementaire_lbp']; } }
                    if (!$transOn) { $r['transport_complement_lbp']=0; $r['transport_lbp']=0; }
                    elseif ($oldTr == 0) { $p=$db->prepare("SELECT transport_complement_lbp,transport_lbp FROM monthly_salaries WHERE employee_id=? AND year=? AND month=? AND (transport_complement_lbp+transport_lbp)>0 LIMIT 1"); $p->execute([$id,$y-1,$m]); if($ps=$p->fetch(PDO::FETCH_ASSOC)){ $r['transport_complement_lbp']=$ps['transport_complement_lbp']; $r['transport_lbp']=$ps['transport_lbp']; } }
                    $newAdd = (float)$r['extra_lbp'] + (float)$r['prime_fixe_lbp'] + (float)$r['aide_complementaire_lbp'];
                    $newTr  = (float)$r['transport_complement_lbp'] + (float)$r['transport_lbp'];
                    $db->prepare("UPDATE monthly_salaries SET extra_lbp=?, prime_fixe_lbp=?, aide_complementaire_lbp=?, transport_complement_lbp=?, transport_lbp=?,
                        net_salary_lbp=GREATEST(0, net_salary_lbp + ?), total_due_lbp=GREATEST(0, total_due_lbp + ?) WHERE id=?")
                       ->execute([$r['extra_lbp'],$r['prime_fixe_lbp'],$r['aide_complementaire_lbp'],$r['transport_complement_lbp'],$r['transport_lbp'], round($newAdd-$oldAdd), round(($newAdd-$oldAdd)+($newTr-$oldTr)), $r['id']]);
                }
            }
            $cnt++;
        }
        $_SESSION['flash_success'] = "تم تحديث $cnt موظف لسنة $yr — الأجر الإضافي والمكافأة: " . ($addOn ? 'موجودة ✓' : 'مشيولة ✗') . "، تعويض النقل: " . ($transOn ? 'موجود ✓' : 'مشيول ✗') . ".";
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
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-folder-plus"></i> Ouvrir une nouvelle année</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">فتح سنة دراسية جديدة</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            اختر مدرسة وسنة دراسية جديدة. البرنامج بينقل **كل الأساتذة والموظفين الفاعلين** (غير التاركين — اللي محطوط إلهم تاريخ ترك ما بينتقلوا) للسنة الجديدة بدرجتهم الحالية.
        </div>
        <form method="POST" onsubmit="return confirm('فتح السنة المختارة لهذه المدرسة ونقل الموظفين الفاعلين؟');">
            <input type="hidden" name="action" value="open">
            <div class="form-row cols-2">
                <?php if (isSuperAdmin()): ?>
                <div class="form-group mb-0">
                    <label class="form-label">المدرسة / École</label>
                    <select name="school_id" class="form-select" required>
                        <option value="">— Choisir / اختر —</option>
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
            </div>

            <!-- خيارات نقل الإضافات وتعويض النقل للسنة الجديدة -->
            <div style="margin-top:14px;padding:12px 14px;background:#f0f9f6;border:1px solid #b6e3d4;border-radius:8px">
                <strong style="color:#0a6b5e"><i class="fas fa-coins"></i> Primes &amp; transport dans la nouvelle année / الإضافات وتعويض النقل في السنة الجديدة:</strong>
                <div class="form-row cols-2" style="margin-top:10px;gap:18px">
                    <div>
                        <div class="form-label" style="font-weight:bold">Salaire additionnel + prime / الأجر الإضافي + المكافأة</div>
                        <label style="display:block;cursor:pointer;margin:3px 0"><input type="radio" name="add_mode" value="same" checked onchange="document.getElementById('add_pct').disabled=true"> Comme l'an dernier / نفس السنة الماضية</label>
                        <label style="display:block;cursor:pointer;margin:3px 0"><input type="radio" name="add_mode" value="none" onchange="document.getElementById('add_pct').disabled=true"> Aucun / بلا (لا تنقلها)</label>
                        <label style="cursor:pointer;margin:3px 0"><input type="radio" name="add_mode" value="pct" onchange="document.getElementById('add_pct').disabled=false"> Avec ajustement (%) / مع تعديل بنسبة:</label>
                        <input type="number" id="add_pct" name="add_pct" value="0" step="0.5" disabled style="width:75px;padding:3px 6px"> %
                    </div>
                    <div>
                        <div class="form-label" style="font-weight:bold">Transport / تعويض النقل</div>
                        <label style="display:block;cursor:pointer;margin:3px 0"><input type="radio" name="trans_mode" value="same" checked onchange="document.getElementById('trans_pct').disabled=true"> Comme l'an dernier / نفس السنة الماضية</label>
                        <label style="display:block;cursor:pointer;margin:3px 0"><input type="radio" name="trans_mode" value="none" onchange="document.getElementById('trans_pct').disabled=true"> Aucun / بلا (لا تنقلها)</label>
                        <label style="cursor:pointer;margin:3px 0"><input type="radio" name="trans_mode" value="pct" onchange="document.getElementById('trans_pct').disabled=false"> Avec ajustement (%) / مع تعديل بنسبة:</label>
                        <input type="number" id="trans_pct" name="trans_pct" value="0" step="0.5" disabled style="width:75px;padding:3px 6px"> %
                    </div>
                </div>
                <small style="color:#64748b;display:block;margin-top:8px">«نفس السنة الماضية» = ينقل قيمة كل أستاذ كما هي. «بلا» = تبدأ السنة بلا إضافات/نقل (تُدخلها لاحقاً). «بنسبة» = ينقلها مع زيادة/نقص (مثال: 10 = +10٪، -5 = ‑5٪). وبأي حال فيك تعدّل قيمة أي أستاذ من ملفه بعد الفتح.</small>
            </div>

            <div style="margin-top:14px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-folder-plus"></i> افتح السنة / Ouvrir</button>
            </div>
        </form>
    </div>
</div>

<!-- ⭐ شك مارك: إضافة/إزالة الإضافات وتعويض النقل لسنة مفتوحة بلا تفريغها -->
<div class="card" style="border:2px solid #0a6b5e">
    <div class="card-header" style="background:#e6f4f1"><h3 style="color:#0a6b5e">
        <span dir="ltr"><i class="fas fa-toggle-on"></i> Modifier primes &amp; transport d'une année ouverte (sans la vider)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">تعديل الإضافات وتعويض النقل لسنة مفتوحة (بلا تفريغ)</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:12px">
            <i class="fas fa-info-circle"></i>
            للسنة المفتوحة: <strong>صحّ المربّع = موجودة (تُنقَل من السنة الماضية)، شيل الصحّ = تنشال</strong> — بلا ما تفرّغ السنة ولا تعيد الأساتذة. يعيد حساب رواتب تلك السنة فقط (لا يمسّ الدرجات ولا السنة الجارية).
        </div>
        <form method="POST" onsubmit="return confirm('تطبيق التعديل على إضافات/نقل السنة المختارة؟');">
            <input type="hidden" name="action" value="set_additions">
            <div class="form-row cols-3" style="align-items:end">
                <?php if (isSuperAdmin()): ?>
                <div class="form-group mb-0">
                    <label class="form-label">المدرسة / École</label>
                    <select name="ba_school_id" class="form-select" required>
                        <option value="">— Choisir / اختر —</option>
                        <?php foreach (allSchools() as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= currentSchoolId() === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?><input type="hidden" name="ba_school_id" value="<?= currentSchoolId() ?>"><?php endif; ?>
                <div class="form-group mb-0">
                    <label class="form-label">Année ouverte / السنة المفتوحة</label>
                    <select name="ba_year" class="form-select" required>
                        <?php for ($yb = $startN + 3; $yb >= $startN + 1; $yb--): $syb = $yb . '-' . ($yb + 1); ?>
                            <option value="<?= $syb ?>"><?= $syb ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label style="display:block;cursor:pointer;margin:4px 0;font-weight:bold"><input type="checkbox" name="ba_add" value="1" checked> Salaire additionnel + prime / الأجر الإضافي + المكافأة</label>
                    <label style="display:block;cursor:pointer;margin:4px 0;font-weight:bold"><input type="checkbox" name="ba_trans" value="1" checked> Transport / تعويض النقل</label>
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn" style="background:#0a6b5e;color:#fff"><i class="fas fa-check"></i> Appliquer à l'année / طبّق على السنة</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border:2px solid #e11d48">
    <div class="card-header" style="background:#fdeef1"><h3 style="color:#b91c3a">
        <span dir="ltr"><i class="fas fa-eraser"></i> Vider une année future</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">تفريغ سنة دراسية مستقبلية</div>
    </h3></div>
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
                        <option value="all">🏫 Toutes les écoles / كل المدارس</option>
                        <?php foreach (allSchools() as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="clear_school_id" value="<?= currentSchoolId() ?>">
                <?php endif; ?>
                <div class="form-group mb-0">
                    <label class="form-label">Année à vider / السنة المراد تفريغها</label>
                    <select name="clear_year_val" class="form-select" required>
                        <?php for ($yc = $startN + 3; $yc >= $startN + 1; $yc--): $syc = $yc . '-' . ($yc + 1); ?>
                            <option value="<?= $syc ?>"><?= $syc ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn w-100" style="background:#e11d48;color:#fff"><i class="fas fa-eraser"></i> Vider l'année / فرّغ السنة</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-table"></i> Années ouvertes par école</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">السنوات المفتوحة لكل مدرسة</div>
    </h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>École / المدرسة</th><th>Années existantes / السنوات الموجودة</th></tr></thead>
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
