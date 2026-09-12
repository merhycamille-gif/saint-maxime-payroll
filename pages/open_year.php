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

// 📅 درجات الملاك عند فتح السنة: applyLegalGradesForNewYear() بـincludes/payroll_calculator.php (المصدر الواحد —
// «درجته كما رتّبتها + ما يضيفه القانون لهذه السنة»، لا إعادة بناء ولا مقارنة بالقانون؛ 2026-09-12).

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
        // 🛡️ (2026-09-04) صمام أنطوني: نسبة فاعلة موجودة بالسنة الجديدة (ولو بفترة أخرى) = لا تنسخ ثانيةً
        if (bonusDuplicateExists($db, (int)$empId, $newSY, $b)) continue;
        if ((float)$b['amount'] <= 0) continue; // بند بصفر بلا معنى — لا يُنسخ
        $amt = ($b['value_type'] === 'percent') ? (float)$b['amount'] : round((float)$b['amount'] * $factor, 2);
        $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active)
                      VALUES (?,?,?,?,?,?,?,?,?,1)")
           ->execute([$empId, $b['bonus_type'], $b['period_number'], $newSY, $amt, $b['value_type'], $b['currency'], $b['start_month'], $b['end_month']]);
        $cnt++;
    }
    return $cnt;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    // (2026-09-11 «بس افتح السنة الجديدة بكل المدارس ينقل نفس الرواتب مع التدرّج تلقائياً — هيك لازم يشتغل البرنامج»)
    // (2026-09-12 «يكون عنا خيار نفتح الكل أو نختار») المدير العام يؤشّر «كل المدارس» أو يختار مدرسة أو أكثر (school_ids[])؛
    // school_id=all/رقم يبقى مقبولاً. مدير المدرسة: مدرسته فقط.
    $validIds = array_map(fn($sc) => (int)$sc['id'], allSchools());
    if (isSuperAdmin()) {
        $allSchoolsOpen = !empty($_POST['all_schools']) || (($_POST['school_id'] ?? '') === 'all');
        $chosen = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['school_ids'] ?? [])), fn($i) => in_array($i, $validIds, true))));
        if (!$allSchoolsOpen && !$chosen && (int)($_POST['school_id'] ?? 0) > 0) $chosen = [(int)$_POST['school_id']];
        if ($allSchoolsOpen) $chosen = $validIds;
    } else {
        $allSchoolsOpen = false; $chosen = [currentSchoolId()];
    }
    $newYear  = trim($_POST['new_year'] ?? '');
    if (!$chosen) {
        $_SESSION['flash_error'] = 'أشّر «كل المدارس» أو اختر مدرسة واحدة على الأقل';
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
        // الأساتذة/الموظفون الفاعلون بهالمدرسة — قاعدة التارك (§١٠): مَن ترك **قبل بداية**
        // السنة المفتوحة (1/10) لا يُنقَل إليها؛ ومَن ترك خلالها أو بعدها يُشمَل (يبقى بسنة
        // عمله حتى 30-9) — فيصحّ أيضاً فتح سنين قديمة كان يعمل فيها تارك لاحق.
        $openOne = function (int $schoolId) use ($db, $y1, $y2, $prevSY, $newYear, $addMode, $transMode, $addPct, $transPct, $addFactor, $transFactor) {
        $emps = $db->prepare("SELECT id, payment_months_per_year, employee_type, base_salary_usd, contract_salary_lbp FROM employees
                              WHERE school_id = ? AND is_deleted = 0 AND status = 'actif'
                                AND LEAST(COALESCE(NULLIF(left_date_cnss,'0000-00-00'),'9999-12-31'),
                                          COALESCE(NULLIF(left_date_finance,'0000-00-00'),'9999-12-31'),
                                          COALESCE(NULLIF(left_date_eoc,'0000-00-00'),'9999-12-31')) >= ?");
        $emps->execute([$schoolId, $y1 . '-10-01']);
        // مصدر النقل للموظف المنقول بلا إعداد: آخر راتب فعلي معروف قبل السنة الجديدة
        $srcStmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND net_salary_lbp > 0
                                 AND (year < ? OR (year = ? AND month < 10)) ORDER BY year DESC, month DESC LIMIT 1");
        $n = 0; $carried = 0; $promoted = 0;
        foreach ($emps->fetchAll() as $emp) {
            $months = ((int)$emp['payment_months_per_year'] === 10)
                ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
                : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
            // الموظف المُعَدّ (ملاك أو أساس>0) يُحسب بالقانون الساري؛ المنقول بلا إعداد يُنقل راتبه كما هو
            $hasConfig = salaryEngineAllowed($emp, $db); // المصدر الواحد (الجديد بلا أساس منقول يُحسب من ملفه)
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
                    // §٠-ب أشهر النقل (2026-09-11 كشفه فحص الانحدار عند فتح 2026-2027 محلياً): المتعاقد المنقول بالصفّ لا يأخذ
                    // نقلاً بالأشهر خارج نافذة النقل (تموز/آب/أيلول…) حتى لو كان بصفوف السنة السابقة — يُصفَّر مع إنقاص المستحق ومرآة دولاره
                    if (!transportMonthActive($m, (string)$emp['employee_type'], $newYear) && (float)($src['transport_lbp'] ?? 0) > 0) {
                        $oldT = (float)$src['transport_lbp'];
                        $src['transport_lbp'] = 0; if (isset($src['transport_complement_lbp'])) $src['transport_complement_lbp'] = 0;
                        if (isset($src['total_due_lbp'])) $src['total_due_lbp'] = max(0, round((float)$src['total_due_lbp'] - $oldT));
                        if (isset($src['total_due_usd']) && (float)($src['exchange_rate'] ?? 0) > 0) $src['total_due_usd'] = round((float)$src['total_due_lbp'] / (float)$src['exchange_rate'], 2);
                    }
                    // المتعاقد المنقول بالصفّ: طبّق اختيار الإضافات/النقل (none=صفّر، pct=نسبة) وصحّح الصافي/المجموع
                    if ($addMode !== 'same' || $transMode !== 'same') {
                        $oldAdd = (float)($src['extra_lbp'] ?? 0) + (float)($src['prime_fixe_lbp'] ?? 0) + (float)($src['aide_complementaire_lbp'] ?? 0);
                        // النقل داخل total_due مرّة واحدة فقط (transport_lbp = transport_complement_lbp نفس القيمة مخزّنة مرّتين) — لا تجمع العمودين وإلا تضاعف الفرق
                        $oldTr  = (float)($src['transport_lbp'] ?? 0);
                        foreach (['extra_lbp','prime_fixe_lbp','aide_complementaire_lbp'] as $c) if (isset($src[$c])) $src[$c] = round((float)$src[$c] * $addFactor);
                        foreach (['transport_complement_lbp','transport_lbp'] as $c) if (isset($src[$c])) $src[$c] = round((float)$src[$c] * $transFactor);
                        $newAdd = (float)($src['extra_lbp'] ?? 0) + (float)($src['prime_fixe_lbp'] ?? 0) + (float)($src['aide_complementaire_lbp'] ?? 0);
                        $newTr  = (float)($src['transport_lbp'] ?? 0);
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
        return [$n, $promoted, $carried];
        };
        // 🔒 قفل السنة (2026-09-12): المدرسة المقفولة على هذه السنة تُتخطّى (حساباتها ما بتتغيّر)
        $lockedT = array_values(array_filter($chosen, fn($sid) => isSchoolYearLocked((int)$sid, $newYear)));
        $targets = array_values(array_diff($chosen, $lockedT));
        $n = 0; $promoted = 0; $carried = 0; $perSchool = [];
        foreach ($targets as $sid) {
            [$a1, $b1, $c1] = $openOne($sid);
            $n += $a1; $promoted += $b1; $carried += $c1;
            $perSchool[] = schoolNameById($sid, 'ar') . ' (' . ($a1 + $c1) . ')';
        }
        $_SESSION['active_school_year'] = $newYear;
        // 📅 السنة المفتوحة تصير السنة الحالية للبرنامج كله (التقارير/الإفادات/القسائم/لوحة القيادة) — لا رجوع لسنة أقدم من التقويم
        if (strcmp($newYear, calendarSchoolYear()) >= 0 && strcmp($newYear, currentSchoolYear()) >= 0) setSetting('program_school_year', $newYear);
        $_SESSION['flash_success'] = "تم فتح السنة $newYear " . ($allSchoolsOpen ? 'لكل المدارس' : (count($targets) > 1 ? 'للمدارس المختارة' : 'للمدرسة')) . ' (' . implode(' · ', $perSchool) . ')'
            . " — $n موظف محسوب بالقانون (منهم $promoted أستاذ ملاك كُمِّل تدرّجهم على درجتهم كما رتّبتها) + $carried متعاقد نُقل راتبه كما كان"
            . " — الإضافات وتعويض النقل " . ($addMode === 'same' && $transMode === 'same' ? 'نُقلت كما كانت' : 'حسب اختيارك') . ". ما تغيّر شي إلا إذا عدّلته أنت بالسنة الجديدة (المكافآت الجماعية / ملف الأستاذ)."
            . ($lockedT ? ' 🔒 تُركت مقفولة كما هي: ' . implode(' · ', array_map(fn($sid) => schoolNameById($sid, 'ar'), $lockedT)) . '.' : '');
        if (!$targets) { unset($_SESSION['flash_success']); $_SESSION['flash_error'] = '🔒 كل المدارس المختارة مقفولة على سنة ' . $newYear . ' — ما تغيّر شي. افتح القفل أوّلاً إذا بدّك.'; }
        header('Location: ' . BASE_URL . 'pages/open_year.php');
        exit;
    }
    header('Location: ' . BASE_URL . 'pages/open_year.php');
    exit;
}

// 📅 تبديل السنة الحالية للبرنامج يدوياً (المدير العام): كل التقارير والإفادات تصير عليها
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_program_year' && isSuperAdmin()) {
    $py = trim($_POST['program_year'] ?? '');
    if ($py === '' || $py === 'auto') { setSetting('program_school_year', ''); $_SESSION['flash_success'] = 'صارت السنة الحالية للبرنامج حسب التقويم: ' . calendarSchoolYear(); }
    elseif (preg_match('/^\d{4}-\d{4}$/', $py) && strcmp($py, calendarSchoolYear()) >= 0) { setSetting('program_school_year', $py); $_SESSION['active_school_year'] = $py; $_SESSION['flash_success'] = "صارت السنة الحالية للبرنامج كله $py (التقارير، الإفادات، القسائم، لوحة القيادة)."; }
    else $_SESSION['flash_error'] = 'لا يمكن اعتماد سنة أقدم من سنة التقويم ' . calendarSchoolYear();
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
    } elseif ($clrYear <= calendarSchoolYear()) {
        $_SESSION['flash_error'] = 'لا يمكن تفريغ السنة الجارية أو سنة سابقة — فقط السنين المستقبلية.';
    } elseif (!$allSch && $schoolId <= 0) {
        $_SESSION['flash_error'] = 'اختر مدرسة (أو «كل المدارس»)';
    } elseif ($lkC = array_values(array_filter($allSch ? array_map(fn($sc) => (int)$sc['id'], allSchools()) : [$schoolId], fn($sid) => isSchoolYearLocked((int)$sid, $clrYear)))) {
        $_SESSION['flash_error'] = yearLockedMsg($lkC[0], $clrYear) . ' (لا تفريغ لسنة مقفولة)'; // 🔒
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
        if ((string)getSetting('program_school_year', '') === $clrYear) setSetting('program_school_year', ''); // فُرِّغت السنة الحالية للبرنامج → الافتراضي حسب التقويم
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
    } elseif ($yr <= calendarSchoolYear()) {
        $_SESSION['flash_error'] = 'هذا الخيار للسنين المستقبلية (المفتوحة للتجهيز) فقط.';
    } elseif ($schoolId <= 0) {
        $_SESSION['flash_error'] = 'اختر مدرسة';
    } elseif (isSchoolYearLocked($schoolId, $yr)) {
        $_SESSION['flash_error'] = yearLockedMsg($schoolId, $yr); // 🔒
    } else {
        [$y1, $y2] = schoolYearToYears($yr);
        $prevSY = ($y1 - 1) . '-' . $y1;
        $addTypes = ['prime_fixe', 'aide_complementaire'];
        $trTypes  = ['transport_complement', 'transport_daily'];
        // نفس قاعدة التارك (§١٠) المطبَّقة عند فتح السنة: مَن ترك قبل بداية السنة لا يُشمَل
        $emps = $db->prepare("SELECT id, payment_months_per_year, employee_type, base_salary_usd, contract_salary_lbp
            FROM employees WHERE school_id = ? AND is_deleted = 0 AND status = 'actif'
              AND LEAST(COALESCE(NULLIF(left_date_cnss,'0000-00-00'),'9999-12-31'),
                        COALESCE(NULLIF(left_date_finance,'0000-00-00'),'9999-12-31'),
                        COALESCE(NULLIF(left_date_eoc,'0000-00-00'),'9999-12-31')) >= ?");
        $emps->execute([$schoolId, $y1 . '-10-01']);
        $cnt = 0;
        foreach ($emps->fetchAll(PDO::FETCH_ASSOC) as $emp) {
            $id = (int)$emp['id'];
            // العلاوات: مفعّل → انقل من السنة الماضية (idempotent)؛ مطفأ → احذفها لهذه السنة
            if ($addOn) copyYearBonuses($db, $id, $prevSY, $yr, $addTypes, 'same', 0);
            else $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND school_year=? AND bonus_type IN ('prime_fixe','aide_complementaire')")->execute([$id, $yr]);
            if ($transOn) copyYearBonuses($db, $id, $prevSY, $yr, $trTypes, 'same', 0);
            else $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND school_year=? AND bonus_type IN ('transport_complement','transport_daily')")->execute([$id, $yr]);

            $hasConfig = salaryEngineAllowed($emp, $db); // المصدر الواحد
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
                    // النقل داخل total_due مرّة واحدة فقط (العمودان نفس القيمة) — الفرق يُحسب من transport_lbp وحده
                    $oldTr  = (float)$r['transport_lbp'];
                    if (!$addOn) { $r['extra_lbp']=0; $r['prime_fixe_lbp']=0; $r['aide_complementaire_lbp']=0; }
                    elseif ($oldAdd == 0) { $p=$db->prepare("SELECT extra_lbp,prime_fixe_lbp,aide_complementaire_lbp FROM monthly_salaries WHERE employee_id=? AND year=? AND month=? AND (extra_lbp+prime_fixe_lbp+aide_complementaire_lbp)>0 LIMIT 1"); $p->execute([$id,$y-1,$m]); if($ps=$p->fetch(PDO::FETCH_ASSOC)){ $r['extra_lbp']=$ps['extra_lbp']; $r['prime_fixe_lbp']=$ps['prime_fixe_lbp']; $r['aide_complementaire_lbp']=$ps['aide_complementaire_lbp']; } }
                    if (!$transOn) { $r['transport_complement_lbp']=0; $r['transport_lbp']=0; }
                    elseif ($oldTr == 0) { $p=$db->prepare("SELECT transport_complement_lbp,transport_lbp FROM monthly_salaries WHERE employee_id=? AND year=? AND month=? AND (transport_complement_lbp+transport_lbp)>0 LIMIT 1"); $p->execute([$id,$y-1,$m]); if($ps=$p->fetch(PDO::FETCH_ASSOC)){ $r['transport_complement_lbp']=$ps['transport_complement_lbp']; $r['transport_lbp']=$ps['transport_lbp']; } }
                    $newAdd = (float)$r['extra_lbp'] + (float)$r['prime_fixe_lbp'] + (float)$r['aide_complementaire_lbp'];
                    $newTr  = (float)$r['transport_lbp'];
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

// 🔒 قفل/فتح السنة الدراسية لمدرسة بكلمة سرّ (أمره 2026-09-12): «حتى ما نخلص حسابات المدرسة بتضلّ متل ما هي ما بتتغيّر إلا إذا أنا عملت أن-لوك»
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['lock_pw', 'lock_year', 'unlock_year'], true)) {
    $la = $_POST['action']; $who = (string)($_SESSION['username'] ?? '');
    if (!isSuperAdmin()) { $_SESSION['flash_error'] = 'الأقفال للمدير العام فقط.'; header('Location: ' . BASE_URL . 'pages/open_year.php#yearLocks'); exit; }
    if ($la === 'lock_pw') {
        $old = (string)($_POST['pw_old'] ?? ''); $new = (string)($_POST['pw_new'] ?? ''); $new2 = (string)($_POST['pw_new2'] ?? '');
        if (yearLockPasswordSet() && !yearLockPasswordOk($old)) $_SESSION['flash_error'] = 'كلمة السرّ الحالية غير صحيحة.';
        elseif (strlen($new) < 4) $_SESSION['flash_error'] = 'كلمة السرّ الجديدة قصيرة (4 أحرف على الأقل).';
        elseif ($new !== $new2) $_SESSION['flash_error'] = 'كلمتا السرّ غير متطابقتين.';
        else { setSetting('year_lock_password_hash', password_hash($new, PASSWORD_DEFAULT)); $_SESSION['flash_success'] = '🔑 حُفظت كلمة سرّ الأقفال. استعملها للقفل والفتح.'; }
    } else {
        $lsid = (int)($_POST['lock_school_id'] ?? 0); $lsy = trim((string)($_POST['lock_year'] ?? '')); $pw = (string)($_POST['lock_pw'] ?? '');
        $validL = in_array($lsid, array_map(fn($sc) => (int)$sc['id'], allSchools()), true);
        if (!$validL || !preg_match('/^\d{4}-\d{4}$/', $lsy)) $_SESSION['flash_error'] = 'اختر مدرسة وسنة صحيحتين.';
        elseif (!yearLockPasswordSet()) $_SESSION['flash_error'] = 'حطّ كلمة سرّ الأقفال أوّلاً (البطاقة نفسها).';
        elseif (!yearLockPasswordOk($pw)) $_SESSION['flash_error'] = '❌ كلمة السرّ غير صحيحة — ما تغيّر شي.';
        elseif ($la === 'lock_year') { lockSchoolYear($lsid, $lsy, $who); $_SESSION['flash_success'] = '🔒 قُفلت سنة ' . $lsy . ' لمدرسة «' . schoolNameById($lsid, 'ar') . '» — حساباتها ما بتتغيّر من أي مكان بالبرنامج حتى تفتح القفل.'; }
        else { unlockSchoolYear($lsid, $lsy, $who); $_SESSION['flash_success'] = '🔓 فُتح قفل سنة ' . $lsy . ' لمدرسة «' . schoolNameById($lsid, 'ar') . '» — صار التعديل ممكناً.'; }
    }
    header('Location: ' . BASE_URL . 'pages/open_year.php#yearLocks');
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
<?php if (isSuperAdmin()): $pyForced = (string)getSetting('program_school_year', ''); $pyNow = currentSchoolYear(); ?>
<div class="card" style="border:2px solid #1F4E5F">
    <div class="card-header" style="background:#1F4E5F;color:#fff"><h3 style="color:#fff">
        <span dir="ltr"><i class="fas fa-calendar-check"></i> Année en cours du programme</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.95">السنة الحالية للبرنامج كله — <?= e($pyNow) ?><?= $pyForced !== '' && $pyForced === $pyNow ? ' (مثبّتة بعد فتح السنة)' : ' (حسب التقويم)' ?></div>
    </h3></div>
    <div class="card-body">
        <div style="font-size:13px;line-height:1.8;margin-bottom:8px">كل التقارير والإفادات والقسائم ولوحة القيادة تفتح افتراضياً على هذه السنة. تتبدّل <b>تلقائياً</b> بمجرّد فتح السنة الجديدة من الأسفل، وفيك تبدّلها هون يدوياً إذا احتجت.</div>
        <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?= csrfField() ?><input type="hidden" name="action" value="set_program_year">
            <select name="program_year" class="form-select" style="max-width:220px">
                <option value="auto" <?= $pyForced === '' ? 'selected' : '' ?>>حسب التقويم (<?= e(calendarSchoolYear()) ?>)</option>
                <?php for ($yy = $startN + 2; $yy >= $startN; $yy--): $sy = $yy . '-' . ($yy + 1); ?>
                    <option value="<?= $sy ?>" <?= $pyForced === $sy ? 'selected' : '' ?>><?= $sy ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> اعتمد / Appliquer</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-folder-plus"></i> Ouvrir une nouvelle année</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">فتح سنة دراسية جديدة</div>
    </h3></div>
    <div class="card-body">
        <?php
        // 📊 حالة السنة الجديدة بكل مدرسة (كم موظفاً فاعلاً وكم منهم فُتحت له السنة) — ليرى بعينه أين فُتحت وأين لم تُفتح
        $stY = ($startN + 1) . '-' . ($startN + 2);
        $stRows = [];
        if (isSuperAdmin()) {
            foreach (allSchools() as $sc) {
                $sid = (int)$sc['id'];
                $act = (int)$db->query("SELECT COUNT(*) FROM employees WHERE school_id = $sid AND is_deleted = 0 AND status = 'actif'
                    AND LEAST(COALESCE(NULLIF(left_date_cnss,'0000-00-00'),'9999-12-31'), COALESCE(NULLIF(left_date_finance,'0000-00-00'),'9999-12-31'), COALESCE(NULLIF(left_date_eoc,'0000-00-00'),'9999-12-31')) >= '" . ($startN + 1) . "-10-01'")->fetchColumn();
                $opn = (int)$db->query("SELECT COUNT(DISTINCT ms.employee_id) FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id AND e.is_deleted = 0
                    WHERE e.school_id = $sid AND ms.school_year = " . $db->quote($stY) . " AND (ms.net_salary_lbp > 0 OR ms.base_plus_echelon_lbp > 0)")->fetchColumn();
                $stRows[] = ['name' => $sc['name_ar'] ?: $sc['name_fr'], 'id' => $sid, 'act' => $act, 'opn' => $opn];
            }
        }
        ?>
        <div style="background:#f0f7ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;font-size:14px;line-height:1.9;margin-bottom:14px">
            <b>١</b> أشّر المدارس (أو «كل المدارس») &nbsp;→&nbsp; <b>٢</b> اختر السنة الجديدة &nbsp;→&nbsp; <b>٣</b> اكبس «افتح».<br>
            البرنامج بينقل <b>كل شي كما هو</b> من السنة الماضية (الرواتب، الإضافي، المكافآت، النقل) — <b>ما بيتغيّر شي إلا إذا عدّلته أنت</b> بعد الفتح.<br>
            <b>الدرجات:</b> بياخد درجة كل أستاذ ملاك <b>كما رتّبتها</b> (مع أي زيادة عطيتها) وبيكمّل عليها تدرّج هالسنة بس. التارك قبل 1 تشرين ما بينتقل. الفتح مرّة تانية آمن (بيكمّل الناقص بلا تكرار).
        </div>
        <form method="POST" id="openYearForm" onsubmit="var all=document.getElementById('oy_all'); var n=this.querySelectorAll('input[name=&quot;school_ids[]&quot;]:checked').length; if(all&&!all.checked&&n===0){alert('أشّر «كل المدارس» أو اختر مدرسة واحدة على الأقل');return false;} return confirm((all&&all.checked) ? 'فتح السنة المختارة لكل المدارس ونقل كل الموظفين الفاعلين برواتبهم وتدرّجهم وإضافاتهم كما كانت؟ (قد يستغرق دقائق)' : 'فتح السنة المختارة للمدارس المؤشَّرة ونقل موظفيها الفاعلين كما كانوا؟');">
            <input type="hidden" name="action" value="open">
            <div class="form-row cols-2">
                <?php if (isSuperAdmin()): ?>
                <div class="form-group mb-0">
                    <label class="form-label">المدارس / Écoles — أشّر الكل أو اختر</label>
                    <div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;background:#fff">
                        <label style="display:block;cursor:pointer;margin:2px 0;font-weight:700;border-bottom:1px solid #e2e8f0;padding-bottom:6px;margin-bottom:6px">
                            <input type="checkbox" id="oy_all" name="all_schools" value="1" checked onchange="document.querySelectorAll('#openYearForm input[name=&quot;school_ids[]&quot;]').forEach(function(c){c.checked=this.checked;c.disabled=this.checked;}.bind(this))" title="مؤشَّر = كل المدارس؛ شيل الصحّ واختر"> 🌐 كل المدارس دفعة وحدة / Toutes les écoles
                        </label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:2px 14px">
                        <?php foreach (allSchools() as $s): ?>
                            <label style="display:block;cursor:pointer;margin:2px 0"><input type="checkbox" name="school_ids[]" value="<?= (int)$s['id'] ?>" checked disabled> <?= e($s['name_ar'] ?: $s['name_fr']) ?></label>
                        <?php endforeach; ?>
                        </div>
                        <small style="color:#64748b;display:block;margin-top:6px">شيل صحّ «كل المدارس» لتختار مدرسة أو أكثر.</small>
                    </div>
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

            <!-- خيارات نقل الإضافات وتعويض النقل للسنة الجديدة — مطوية (الافتراضي: نفس السنة الماضية) -->
            <details style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:700;color:#0a6b5e;font-size:14px"><i class="fas fa-sliders-h"></i> خيارات إضافية (الافتراضي: الإضافات والنقل نفس السنة الماضية) / Options</summary>
            <div style="margin-top:10px;padding:12px 14px;background:#f0f9f6;border:1px solid #b6e3d4;border-radius:8px">
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
            </details>

            <div style="margin-top:16px">
                <button type="submit" class="btn btn-primary" style="font-size:17px;font-weight:800;padding:10px 26px"><i class="fas fa-folder-plus"></i> افتح السنة / Ouvrir</button>
            </div>
        </form>
    </div>
</div>

<?php if (isSuperAdmin()): $locksAll = yearLocksMap(true); $pwSet = yearLockPasswordSet(); ?>
<!-- 🔒 حالة كل مدرسة على السنة + الأقفال بكلمة سرّ (2026-09-12) -->
<div class="card" id="yearLocks" style="border:2px solid #991b1b">
    <div class="card-header" style="background:#fef2f2"><h3 style="color:#991b1b">
        <span dir="ltr"><i class="fas fa-lock"></i> Verrouillage des comptes par école et par année</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">قفل حسابات كل مدرسة على السنة الدراسية — بكلمة سرّ</div>
    </h3></div>
    <div class="card-body">
        <div style="font-size:14px;line-height:1.9;margin-bottom:12px">
            بس تخلّص حسابات مدرسة على سنة، <b>اقفلها</b>: رواتبها وبنودها ودرجاتها بتضلّ <b>متل ما هي</b> — ما بيغيّرها لا احتساب ولا فتح سنة ولا مكافآت جماعية ولا تقرير مخالفات ولا تصليح تلقائي — لغاية ما <b>تفتح القفل بكلمة السرّ</b> وتعدّل. المقفولة بتبيّن 🔒 بأعلى الشاشة.
        </div>
        <form method="POST" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;background:#fff;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;margin-bottom:12px" onsubmit="return confirm('حفظ كلمة سرّ الأقفال؟');">
            <?= csrfField() ?><input type="hidden" name="action" value="lock_pw">
            <div style="font-weight:800;color:#991b1b;align-self:center">🔑 كلمة سرّ الأقفال<?= $pwSet ? ' (موجودة — لتغييرها)' : ' (حطّها أوّل مرّة)' ?>:</div>
            <?php if ($pwSet): ?><div><label class="form-label" style="margin:0">الحالية</label><input type="password" name="pw_old" class="form-control" style="max-width:150px" autocomplete="current-password"></div><?php endif; ?>
            <div><label class="form-label" style="margin:0">الجديدة</label><input type="password" name="pw_new" class="form-control" style="max-width:150px" required minlength="4" autocomplete="new-password"></div>
            <div><label class="form-label" style="margin:0">تأكيد</label><input type="password" name="pw_new2" class="form-control" style="max-width:150px" required minlength="4" autocomplete="new-password"></div>
            <button type="submit" class="btn" style="background:#991b1b;color:#fff;font-weight:700"><i class="fas fa-key"></i> احفظ</button>
        </form>
        <form method="POST" id="lockForm" onsubmit="var a=document.getElementById('lockAct').value; return confirm(a==='lock_year' ? 'قفل حسابات هذه المدرسة على السنة المختارة؟' : 'فتح القفل؟ بعدها بتقدر تعدّل حساباتها.');">
            <?= csrfField() ?><input type="hidden" name="action" id="lockAct" value=""><input type="hidden" name="lock_school_id" id="lockSid" value="">
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:10px">
                <div><label class="form-label">السنة / Année</label>
                    <select name="lock_year" id="lockYear" class="form-select" style="max-width:160px">
                        <?php for ($yl = $startN + 2; $yl >= $startN - 3; $yl--): $syl = $yl . '-' . ($yl + 1); ?><option value="<?= $syl ?>" <?= $syl === $stY ? 'selected' : '' ?>><?= $syl ?></option><?php endfor; ?>
                    </select></div>
                <div><label class="form-label">كلمة السرّ / Mot de passe</label><input type="password" name="lock_pw" class="form-control" style="max-width:180px" <?= $pwSet ? '' : 'disabled placeholder="حطّ كلمة السرّ فوق أوّلاً"' ?> autocomplete="off"></div>
            </div>
            <div class="table-wrapper">
            <table class="table" style="font-size:14px">
                <thead><tr><th>École / المدرسة</th><th>الفاعلون</th><th>مفتوح لهم <?= e($stY) ?></th><th>حالة <?= e($stY) ?></th><th>السنوات المقفولة 🔒</th><th>القفل على السنة المختارة</th></tr></thead>
                <tbody>
                <?php foreach ($stRows as $sr): $st = $sr['act'] === 0 ? '—' : ($sr['opn'] >= $sr['act'] ? '✅ مفتوحة' : ($sr['opn'] > 0 ? '⚠️ جزئياً (' . $sr['opn'] . ' من ' . $sr['act'] . ')' : '❌ غير مفتوحة'));
                      $lks = array_keys($locksAll[$sr['id']] ?? []); rsort($lks); ?>
                    <tr>
                        <td><strong><?= e($sr['name']) ?></strong></td><td><?= $sr['act'] ?></td><td><?= $sr['opn'] ?></td><td style="font-weight:700"><?= $st ?></td>
                        <td style="font-weight:700;color:#991b1b"><?= $lks ? '🔒 ' . implode('، ', $lks) : '<span style="color:#94a3b8">—</span>' ?></td>
                        <td style="white-space:nowrap">
                            <button type="submit" class="btn btn-sm" data-lk="1" style="background:#991b1b;color:#fff;font-weight:700" <?= $pwSet ? '' : 'disabled' ?> onclick="document.getElementById('lockAct').value='lock_year';document.getElementById('lockSid').value='<?= (int)$sr['id'] ?>'">🔒 اقفل</button>
                            <button type="submit" class="btn btn-sm btn-light" data-lk="1" style="font-weight:700" <?= $pwSet ? '' : 'disabled' ?> onclick="document.getElementById('lockAct').value='unlock_year';document.getElementById('lockSid').value='<?= (int)$sr['id'] ?>'">🔓 افتح</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<details style="margin-top:6px">
<summary style="cursor:pointer;font-weight:700;color:#475569;font-size:14px;padding:8px 4px"><i class="fas fa-tools"></i> أدوات إضافية (تعديل الإضافات لسنة مفتوحة · تفريغ سنة مستقبلية · السنوات الموجودة) / Outils</summary>
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
            بيحذف <strong>كل رواتب السنة المختارة</strong> فتصير فاضية (مثلاً إذا انفتحت بالغلط أو بدّك تجهّزها من جديد). للأمان: <strong>بس السنين المستقبلية</strong> (سنة التقويم الجارية <?= e(calendarSchoolYear()) ?> والسابقة ما بتنحذف من هون). ملفات الأساتذة ودرجاتهم بتضل سليمة — بس بيتفضّى حساب رواتب تلك السنة.
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
</details>

<?php include __DIR__ . '/../includes/footer.php'; ?>
