<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'grades';
$pageTitle = 'Échelons & Promotions / الدرجات والترقيات';
$db = getDB();
$message = ''; $messageType = 'success';

$employeeId = (int)($_GET['employee_id'] ?? 0);

// أي تعديل على الدرجات يتطلّب اختيار مدرسة محددة + أن يكون الموظف ضمنها
if ((isset($_GET['apply_law']) || isset($_GET['promote']) || $_SERVER['REQUEST_METHOD'] === 'POST') && $employeeId > 0) {
    autoSwitchToEmployeeSchool($employeeId);
    requireSchoolSelected();
    $chk = $db->prepare("SELECT id FROM employees WHERE id = ? AND school_id = ? AND is_deleted = 0");
    $chk->execute([$employeeId, currentSchoolId()]);
    if (!$chk->fetchColumn()) {
        $_SESSION['flash_error'] = 'الموظف غير موجود في هذه المدرسة';
        header('Location: ' . BASE_URL . 'pages/grades.php'); exit;
    }
}

// Apply exceptional law
if (isset($_GET['apply_law']) && $employeeId > 0) {
    requireWriteAction();
    try {
        $result = applyExceptionalLaw($employeeId, $_GET['apply_law']);
        recalcEmployeeYear($employeeId); // إعادة حساب الراتب تلقائياً حسب القانون
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Loi appliquée: échelon " . $result['old_grade'] . " → " . $result['new_grade'] . " (+" . $result['grades_added'] . ")"];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
    header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    exit;
}

// إعادة بناء الدرجات حسب القانون (تلقائياً) — دخول الملاك → تدرّج عادي بتشرين → استثنائية بكانون بعد التثبيت
/**
 * 📌 العودة بعد حفظ درجة من ملف الأستاذ **لنفس الصفحة ونفس التبويب** (طلبه 2026-08-29: «بعد الحفظ
 * بينطّ على صفحة غيرها»): نرجع للرابط الذي أتى منه الطلب (return_url، داخلي فقط) + #gradesPanel،
 * وإلا لملف الأستاذ بتبويب الدرجات. (كانت بعض المعالجات ترجع بلا &tab=grades فيفتح التبويب الأول.)
 */
function gradeReturnToEmployee($employeeId) {
    $ru = (string)($_POST['return_url'] ?? '');
    $ru = preg_replace('/#.*$/', '', $ru);
    $ok = $ru !== '' && strpos($ru, '/pages/employees.php') !== false && strpos($ru, '//') === false && strpos($ru, 'action=edit') !== false;
    if ($ok) {
        if (strpos($ru, 'tab=') === false) $ru .= '&tab=grades';
        header('Location: ' . $ru . '#gradesPanel');
    } else {
        header('Location: ' . BASE_URL . 'pages/employees.php?action=edit&id=' . (int)$employeeId . '&tab=grades#gradesPanel');
    }
    exit;
}

if (isset($_GET['rebuild_legal']) && $employeeId > 0) {
    requireWriteAction();
    try {
        $r = buildLegalGradeHistory($employeeId);
        // إعادة حساب كل سنوات الأستاذ من سنة دخول المدرسة (hire_date) حتى السنة الحالية
        $eDate = getDB()->query("SELECT hire_date FROM employees WHERE id=" . (int)$employeeId)->fetchColumn();
        $y0 = $eDate ? (int)date('Y', strtotime($eDate)) : (int)date('Y') - 5;
        for ($y = $y0; $y <= (int)date('Y'); $y++) recalcEmployeeYear($employeeId, $y . '-' . ($y + 1));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "أُعيد بناء الدرجات حسب القانون: دخول درجة {$r['start']} → تدرّج عادي {$r['ordinary']} + استثنائية +{$r['exceptional']} = درجة {$r['final_grade']} (قانون 344 يدوي)"];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
    header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    exit;
}

// (2026-08-29) أُزيلت الترقية اليدوية «+1» والترقية التلقائية القديمة: التدرّج يُحسب حصراً من القانون (0.5/سنة) عبر «إعادة البناء حسب القانون».

// تابلو الدرجات الاستثنائية (تشيك مارك): يطبّق المؤشَّر ويلغي غير المؤشَّر — تحكّم لكل أستاذ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exc_save']) && $employeeId > 0) {
    $checked = array_map('strval', (array)($_POST['exc_laws'] ?? []));
    $lawNums = $db->query("SELECT law_number FROM exceptional_grades_laws WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    $appl = $db->prepare("SELECT DISTINCT law_reference FROM employee_grade_history WHERE employee_id = ? AND law_reference IS NOT NULL");
    $appl->execute([$employeeId]);
    $appliedNow = array_map('strval', $appl->fetchAll(PDO::FETCH_COLUMN));
    $nApp = 0; $nRem = 0;
    foreach ($lawNums as $ln) {
        $want = in_array((string)$ln, $checked, true);
        $has  = in_array((string)$ln, $appliedNow, true);
        try {
            if ($want && !$has) { applyExceptionalLaw($employeeId, $ln); $nApp++; }
            elseif (!$want && $has) { removeExceptionalLaw($employeeId, $ln); $nRem++; }
        } catch (Exception $e) { /* تجاهل قانون واحد فاشل */ }
    }
    if ($nApp || $nRem) recalcEmployeeYear($employeeId);
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "تابلو الدرجات الاستثنائية: طُبِّق $nApp، أُلغي $nRem"];
    header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    exit;
}

// تشيك-مارك لكل درجة (عادية واستثنائية): المؤشَّر = محسوب، غير المؤشَّر = **يبقى ظاهراً لكن لا يُحتسب**
// (بلا حذف — تقدر ترجّع الصح لاحقاً فتُحتسب من جديد). ثم تُعاد سلسلة الدرجات والراتب تلقائياً.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_save'])
    && !isset($_POST['row_delete']) && $employeeId > 0) {
    $keep = array_map('intval', (array)($_POST['keep'] ?? []));
    $gdate = (array)($_POST['gdate'] ?? []);            // [rowId => 'Y-m-d'] تعديل تاريخ درجة موجودة
    $gamt  = (array)($_POST['gamt'] ?? []);             // [rowId => delta] تعديل مقدار درجة موجودة
    $gunit = array_map('strval', (array)($_POST['gunit'] ?? []));  // "lawNum__idx" وحدات استثنائية تُمنَح فردياً
    $gudate = (array)($_POST['gudate'] ?? []);          // ["lawNum__idx" => 'Y-m-d'] تاريخ كل وحدة
    try {
        // 1) تأكّد أن لكل صفّ delta محفوظة (الصفوف غير المؤشَّرة سابقاً صحيحة before/after)
        $db->prepare("UPDATE employee_grade_history SET delta=ROUND(grade_after-grade_before,1) WHERE employee_id=? AND delta IS NULL")->execute([$employeeId]);
        // 2) اضبط counted حسب الشيك-بوكس للأسطر **الموجودة حالياً** (قبل إضافة أي درجة جديدة!
        //    وإلا الدرجات المُعطاة تحت تنحسب صفر لأنها ما كانت ضمن keep[]). دخول الملاك دائماً محسوب.
        $all = $db->prepare("SELECT id, reason FROM employee_grade_history WHERE employee_id=?");
        $all->execute([$employeeId]);
        $upd = $db->prepare("UPDATE employee_grade_history SET counted=? WHERE id=?");
        $setDate = $db->prepare("UPDATE employee_grade_history SET change_date=? WHERE id=? AND employee_id=? AND reason<>'titularization'");
        $setAmt  = $db->prepare("UPDATE employee_grade_history SET delta=? WHERE id=? AND employee_id=? AND reason<>'titularization'");
        foreach ($all as $r) {
            $rid = (int)$r['id'];
            $on = ($r['reason'] === 'titularization') ? 1 : (in_array($rid, $keep, true) ? 1 : 0);
            $upd->execute([$on, $rid]);
            // تعديل تاريخ/مقدار الدرجة إن غيّرهما المستخدم (عدا دخول الملاك)
            if ($r['reason'] !== 'titularization' && isset($gdate[$rid]) && strtotime($gdate[$rid])) {
                $setDate->execute([date('Y-m-d', strtotime($gdate[$rid])), $rid, $employeeId]);
            }
            if ($r['reason'] !== 'titularization' && isset($gamt[$rid]) && round((float)$gamt[$rid], 1) != 0.0) {
                $setAmt->execute([round((float)$gamt[$rid], 1), $rid, $employeeId]);
            }
        }
        // 2ب) إعطاء **وحدات** الدرجات الاستثنائية المكبوسة — كل وحدة (+1/½) صفّ مستقل بتاريخها (counted=1)
        $granted = 0;
        if (!empty($gunit)) {
            $emp = $db->query("SELECT * FROM employees WHERE id=" . (int)$employeeId)->fetch();
            $byLaw = [];
            foreach ($gunit as $k) { if (preg_match('/^(.+)__(\d+)$/', $k, $m)) $byLaw[$m[1]][] = (int)$m[2]; }
            $insU = $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,law_reference,notes) VALUES (?,0,?,?,1,?,'exceptional',?,?)");
            foreach ($byLaw as $ln => $idxs) {
                $lw = $db->prepare("SELECT * FROM exceptional_grades_laws WHERE law_number=? AND is_active=1");
                $lw->execute([$ln]); $lw = $lw->fetch();
                if (!$lw) continue;
                $units = exceptionalGrantUnits($emp, $lw);      // يُحسب مرّة قبل أي إدراج (idx ثابت)
                foreach ($idxs as $i) {
                    if (!isset($units[$i])) continue;
                    $u = (float)$units[$i]['delta'];
                    $key = $ln . '__' . $i;
                    $d = (isset($gudate[$key]) && strtotime($gudate[$key])) ? date('Y-m-d', strtotime($gudate[$key])) : $units[$i]['date'];
                    $insU->execute([$employeeId, $u, $u, $d, $ln, 'قانون ' . $ln]);
                    $granted++;
                }
            }
        }
        // 3) أعِد ربط السلسلة + الدرجة الحالية (المحرّك، يحترم counted والترتيب الزمني)
        $g = rechainGradeHistory($employeeId);
        // 4) أعِد حساب الراتب لكل سنوات الأستاذ
        $eDate = $db->query("SELECT hire_date FROM employees WHERE id=" . (int)$employeeId)->fetchColumn();
        $y0 = $eDate ? (int)date('Y', strtotime($eDate)) : (int)date('Y') - 5;
        for ($y = $y0; $y <= (int)date('Y'); $y++) recalcEmployeeYear($employeeId, $y . '-' . ($y + 1));
        $okMsg = "تم تحديث الدرجات — الدرجة الحالية صارت $g وأُعيد حساب الراتب"
               . ($granted ? " (أُعطيت $granted درجة استثنائية جديدة)" : "");
        if (($_POST['return_to'] ?? '') === 'employee') { $_SESSION['flash_success'] = $okMsg; }
        else { $_SESSION['flash'] = ['type' => 'success', 'msg' => $okMsg]; }
    } catch (Exception $e) {
        if (($_POST['return_to'] ?? '') === 'employee') { $_SESSION['flash_error'] = $e->getMessage(); }
        else { $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()]; }
    }
    // العودة لملف الأستاذ إن أتى الطلب منه، وإلا لصفحة الدرجات
    if (($_POST['return_to'] ?? '') === 'employee') {
        gradeReturnToEmployee($employeeId);
    } else {
        header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    }
    exit;
}

// 🗑️ زرّ «حذف» قدّام كل درجة: يحذفها نهائياً ثم تُعاد سلسلة الدرجات (rechain) ويُعاد حساب الراتب.
// (زرّ «حفظ» قدّام كل صفّ يمرّ عبر معالج grade_save أعلاه — يحفظ كامل حالة الجدول المعروضة فوراً.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['row_delete']) && $employeeId > 0) {
    requireWriteAction(BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    // السطر الملموم «درجة كل سنتين» (عرض) = نصفان مخزّنان → يُحذفان معاً ("id1,id2")
    $rids = array_values(array_filter(array_map('intval', explode(',', (string)$_POST['row_delete']))));
    try {
        if (!$rids) throw new Exception('الدرجة غير موجودة');
        $rws = [];
        foreach ($rids as $rid) {
            $rw = $db->prepare("SELECT * FROM employee_grade_history WHERE id = ? AND employee_id = ?");
            $rw->execute([$rid, $employeeId]);
            $rw = $rw->fetch();
            if (!$rw) throw new Exception('الدرجة غير موجودة');
            if ($rw['reason'] === 'titularization') throw new Exception('درجة دخول الملاك ثابتة — لا تُعدَّل ولا تُحذف');
            $rws[$rid] = $rw;
        }
        foreach ($rws as $rid => $rw) {
            $db->prepare("DELETE FROM employee_grade_history WHERE id = ? AND employee_id = ?")->execute([$rid, $employeeId]);
            if (function_exists('logAudit')) logAudit('delete', 'employee_grade_history', $rid, $rw, null);
        }
        $g = rechainGradeHistory($employeeId);      // يعيد ربط قبل/بعد والدرجة الحالية حسب الترتيب الزمني
        $eDate = $db->query("SELECT hire_date FROM employees WHERE id=" . (int)$employeeId)->fetchColumn();
        $y0 = $eDate ? (int)date('Y', strtotime($eDate)) : (int)date('Y') - 5;
        for ($y = $y0; $y <= (int)date('Y'); $y++) recalcEmployeeYear($employeeId, $y . '-' . ($y + 1));
        $okMsg = "تم حذف الدرجة — الدرجة الحالية صارت $g وأُعيد حساب الراتب";
        if (($_POST['return_to'] ?? '') === 'employee') { $_SESSION['flash_success'] = $okMsg; }
        else { $_SESSION['flash'] = ['type' => 'success', 'msg' => $okMsg]; }
    } catch (Exception $e) {
        if (($_POST['return_to'] ?? '') === 'employee') { $_SESSION['flash_error'] = $e->getMessage(); }
        else { $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()]; }
    }
    if (($_POST['return_to'] ?? '') === 'employee') {
        gradeReturnToEmployee($employeeId);
    } else {
        header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    }
    exit;
}

// ➕ إضافة درجة يدوية (بقرار المستخدم، خارج القانون): تُضاف بمقدار وتاريخ مختارين، counted=1،
// ثم rechain + إعادة حساب الراتب فتدخل أساس الراتب فوراً ويتدرّج من بعدها تلقائياً. (لا تُمَسّ من محرّك القانون.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_add']) && $employeeId > 0) {
    $amt   = round((float)($_POST['manual_amount'] ?? 0), 1);
    $mdate = $_POST['manual_date'] ?: date('Y-m-d');
    $mnote = trim($_POST['manual_note'] ?? '');
    if ($mnote === '') $mnote = 'درجة يدوية (بقرار المدرسة)';
    try {
        if ($amt == 0) throw new Exception('حدّد مقدار الدرجة (مثلاً 1 أو ½)');
        if (!strtotime($mdate)) throw new Exception('تاريخ غير صحيح');
        $mdate = date('Y-m-d', strtotime($mdate));
        $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,law_reference,notes) VALUES (?,0,?,?,1,?,'manual',NULL,?)")
           ->execute([$employeeId, $amt, $amt, $mdate, $mnote]);
        $g = rechainGradeHistory($employeeId);  // يعيد ربط السلسلة والأساس حسب الترتيب الزمني
        $eDate = $db->query("SELECT hire_date FROM employees WHERE id=" . (int)$employeeId)->fetchColumn();
        $y0 = $eDate ? (int)date('Y', strtotime($eDate)) : (int)date('Y') - 5;
        for ($y = $y0; $y <= (int)date('Y'); $y++) recalcEmployeeYear($employeeId, $y . '-' . ($y + 1));
        $amtTxt = rtrim(rtrim(number_format($amt, 1), '0'), '.');
        $okMsg = "تمت إضافة درجة يدوية (+$amtTxt) بتاريخ $mdate — الدرجة الحالية صارت $g وأُعيد حساب الراتب (يتدرّج من بعدها).";
        if (($_POST['return_to'] ?? '') === 'employee') { $_SESSION['flash_success'] = $okMsg; }
        else { $_SESSION['flash'] = ['type' => 'success', 'msg' => $okMsg]; }
    } catch (Exception $e) {
        if (($_POST['return_to'] ?? '') === 'employee') { $_SESSION['flash_error'] = $e->getMessage(); }
        else { $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()]; }
    }
    if (($_POST['return_to'] ?? '') === 'employee') {
        gradeReturnToEmployee($employeeId);
    } else {
        header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
    }
    exit;
}

// Manual grade change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['exc_save']) && !isset($_POST['grade_save']) && !isset($_POST['manual_add']) && $employeeId > 0) {
    $newGrade = (float)$_POST['new_grade'];
    $reason = $_POST['reason'] ?? 'manual';
    $changeDate = $_POST['change_date'] ?: date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($newGrade < 1 || $newGrade > 52) {
        $message = 'Échelon invalide (1-52)';
        $messageType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT current_grade FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $oldGrade = (int)$stmt->fetchColumn();
        
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([$newGrade, $employeeId]);
            $db->prepare("INSERT INTO employee_grade_history (employee_id, grade_before, grade_after, change_date, reason, notes) VALUES (?,?,?,?,?,?)")
               ->execute([$employeeId, $oldGrade, $newGrade, $changeDate, $reason, $notes]);
            $db->commit();
            recalcEmployeeYear($employeeId); // إعادة حساب الراتب تلقائياً حسب الدرجة الجديدة
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Échelon changé: $oldGrade → $newGrade"];
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
        header('Location: ' . BASE_URL . 'pages/grades.php?employee_id=' . $employeeId);
        exit;
    }
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($employeeId == 0): 
    // List view: all titulaires with current grades
    $curScaleVid = scaleVersionIdAsOf(); // إصدار السلسلة الساري اليوم
    [$gyf, $gyp] = yearEmploymentFilter(activeSchoolYear(), 'e.'); // فلترة حسب السنة الدراسية المختارة
    $gStmt = $db->prepare("SELECT e.*, sc.new_salary_2017, sc.new_grade_value
                              FROM employees e
                              LEFT JOIN salary_scale_2017 sc ON FLOOR(e.current_grade) = sc.grade AND sc.version_id = " . (int)$curScaleVid . "
                              WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire'" . schoolScopeSql('e.school_id') . $gyf . "
                              ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $gStmt->execute($gyp);
    $employees = $gStmt->fetchAll();
?>
    <div class="card">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-layer-group"></i> Échelons des enseignants titulaires</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">درجات الأساتذة الملاك</div>
            </h3>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>📜 قواعد التدرّج / Règles de promotion:</strong><br>
                • <strong>درجة الدخول</strong> حسب الشهادة (قسم ثاني 1 · جارديني ب.ت 6 · إجازة جامعية 6 · جارديني ت.س 11 · إجازة تعليمية 15 · كابس 16).<br>
                • <strong>التدرّج العادي</strong>: نص درجة كل سنة من السنة التالية لدخول الملاك (= درجة كاملة كل سنتين) في 1/10. كل الشهادات تأخذ درجة عادية فورية عند دخول الملاك <strong>إلا الإجازة التعليمية</strong>.<br>
                • <strong>الدرجات الاستثنائية</strong> تلقائياً حسب تاريخ الملاك: القدامى قوانين 244/2000 (+3)، 102/2010 (+3)، 223/2012 (+4½) وقانون 2017 (6/2/0 حسب التاريخ والشهادة)؛ الداخلون بعد 2/4/2012: 4+4+2 بكانون ثم تقديم التدرّج نص درجة. قانون 344 يدوي فقط.<br>
                • الراتب دائماً على الدرجة الكاملة (39.5 = راتب 39)، والنص يكمّل السنة التالية. Échelle 2017 (JO n°37).
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nom / الاسم</th>
                            <?php if (isAllSchools()): ?><th>École / المدرسة</th><?php endif; ?>
                            <th>Diplôme / الشهادة</th>
                            <th>Date Titul. / تاريخ التثبيت</th>
                            <th>Initial / الأولية</th>
                            <th>Actuel / الحالية</th>
                            <th>Salaire échelle / راتب السلسلة</th>
                            <th>Val. échelon / قيمة الدرجة</th>
                            <th class="no-print">Actions / إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $e): ?>
                            <tr>
                                <td><strong><?= e($e['first_name_fr'] . ' ' . $e['last_name_fr']) ?></strong></td>
                                <?php if (isAllSchools()): ?><td><small><?= e(schoolNameById($e['school_id'])) ?></small></td><?php endif; ?>
                                <td><?= e(diplomaLabel($e['diploma'])) ?></td>
                                <td><?= formatDate($e['titularization_date']) ?></td>
                                <td><?= $e['starting_grade'] ?></td>
                                <td><span class="badge badge-gold" style="font-size:14px"><?= $e['current_grade'] ?></span></td>
                                <td><?= formatLBP($e['new_salary_2017']) ?></td>
                                <td><?= formatLBP($e['new_grade_value']) ?></td>
                                <td class="no-print">
                                    <a href="?employee_id=<?= $e['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i> Gérer / إدارة
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else:
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) { echo "<div class='alert alert-danger'>Employé introuvable / الموظف غير موجود</div>"; include __DIR__ . '/../includes/footer.php'; exit; }
    
    $history = $db->prepare("SELECT * FROM employee_grade_history WHERE employee_id = ? ORDER BY change_date ASC, id ASC");
    $history->execute([$employeeId]);
    $history = $history->fetchAll();
    
    $stmt = $db->prepare("SELECT * FROM salary_scale_2017 WHERE version_id = ? AND grade = ?");
    $stmt->execute([scaleVersionIdAsOf(), (int)floor((float)$emp['current_grade'])]);   // الراتب على الدرجة الكاملة (39.5 = 39)
    $currentScale = $stmt->fetch();
    
    $laws = $db->query("SELECT * FROM exceptional_grades_laws WHERE is_active = 1 ORDER BY law_date")->fetchAll();
    $appliedLaws = $db->prepare("SELECT law_reference FROM employee_grade_history WHERE employee_id = ? AND law_reference IS NOT NULL");
    $appliedLaws->execute([$employeeId]);
    $appliedLaws = $appliedLaws->fetchAll(PDO::FETCH_COLUMN);
?>
    <div class="d-flex justify-between align-center mb-3">
        <a href="<?= BASE_URL ?>pages/grades.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Retour / رجوع</a>
        <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $employeeId ?>" class="btn btn-light">
            <i class="fas fa-user"></i> Fiche employé / بطاقة الموظف
        </a>
    </div>
    
    <div class="form-row cols-2">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-user"></i> <?= e($emp['first_name_fr'] . ' ' . $emp['last_name_fr']) ?></h3>
            </div>
            <div class="card-body">
                <table class="table">
                    <tr><td><strong>Type / النوع</strong></td><td><?= employeeTypeLabel($emp['employee_type']) ?></td></tr>
                    <tr><td><strong>Diplôme / الشهادة</strong></td><td><?= diplomaLabel($emp['diploma']) ?></td></tr>
                    <tr><td><strong>Entrée (école) / دخول المدرسة</strong></td><td><?= formatDate($emp['hire_date']) ?></td></tr>
                    <tr><td><strong>Date titularisation / تاريخ التثبيت</strong></td><td><?= formatDate(tenureReferenceDate($emp)) ?> <small class="text-muted">(منه الاستثنائية — تلقائي دخول+سنتين)</small></td></tr>
                    <tr><td><strong>Échelon initial / الدرجة الأولية</strong></td><td><?= $emp['starting_grade'] ?></td></tr>
                    <tr><td><strong>Échelon actuel / الدرجة الحالية</strong></td><td><span class="badge badge-gold" style="font-size:16px"><?= $emp['current_grade'] ?></span></td></tr>
                    <?php if ($emp['employee_type'] === 'enseignant_titulaire'):
                        $lc = lawConsistencyCheckOne($employeeId); ?>
                    <tr>
                        <td><strong>Conformité légale / مطابقة القانون</strong></td>
                        <td>
                            <?php if ($lc['err'] !== null): ?>
                                <span class="text-muted">تعذّر الحساب: <?= e($lc['err']) ?></span>
                            <?php elseif ($lc['ok']): ?>
                                <span style="color:#1a7f37;font-weight:700">✅ مطابق للقانون</span>
                                <small class="text-muted">(القانون يعطي درجة <?= $lc['law'] ?>)</small>
                            <?php else: ?>
                                <span style="color:#c0392b;font-weight:700">⚠️ منحرف</span>
                                — القانون يعطي <strong><?= $lc['law'] ?></strong> والمخزّن <strong><?= $lc['stored'] ?></strong>
                                (فجوة <?= ($lc['gap']>0?'+':'').$lc['gap'] ?>).
                                <a href="?employee_id=<?= $employeeId ?>&rebuild_legal=1"
                                   class="btn btn-sm btn-primary" style="margin-right:6px"
                                   onclick="return confirm('إعادة بناء درجات هذا الأستاذ حسب القانون؟ الدرجة الحالية ستصبح <?= $lc['law'] ?>.')">
                                   <i class="fas fa-wand-magic-sparkles"></i> ابنِ حسب القانون
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($currentScale): ?>
                    <tr><td><strong>Salaire scale 2017 / راتب السلسلة 2017</strong></td><td><?= formatLBP($currentScale['new_salary_2017']) ?></td></tr>
                    <tr><td><strong>Valeur échelon / قيمة الدرجة</strong></td><td><?= formatLBP($currentScale['new_grade_value']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-bolt"></i> Actions rapides</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">إجراءات سريعة</div>
                </h3>
            </div>
            <div class="card-body">
                <?php if ($emp['employee_type'] === 'enseignant_titulaire'): ?>
                    <div class="mb-3">
                        <a href="?employee_id=<?= $employeeId ?>&rebuild_legal=1"
                           class="btn btn-warning btn-sm"
                           data-confirm="إعادة بناء سجلّ الدرجات حسب القانون تلقائياً (دخول الملاك → تدرّج عادي بتشرين → درجات استثنائية بكانون بعد التثبيت)؟ المجموع/الدرجة الحالية لا يتغيّران — يتغيّر توزيعها على السنين فقط. تأكّد أن تاريخ دخول الملاك وتاريخ التثبيت صحيحان أولاً.">
                            <i class="fas fa-wand-magic-sparkles"></i> Reconstruire selon la loi / إعادة بناء الدرجات حسب القانون
                        </a>
                        <small class="text-muted d-block mt-1">يلزم ضبط «دخول الملاك» و«تاريخ التثبيت» في بطاقة الأستاذ أولاً.</small>
                    </div>
                    
                    <h5 style="color:var(--primary);margin-top:16px">
                      <span dir="ltr">Grades exceptionnels (lois)</span>
                      <div style="font-size:0.85em;font-weight:600;opacity:0.9">تابلو الدرجات الاستثنائية</div>
                    </h5>
                    <p class="text-muted" style="font-size:12px;margin:0 0 8px">
                        ✔ = البرنامج يعطي هذه الدرجة الاستثنائية لهذا الأستاذ بتاريخها (كانون الثاني 1/1).
                        شِيل الصح إذا ما بدك تعطيه إيّاها. الدرجات العادية تبقى تلقائياً في تشرين الأول.
                    </p>
                    <form method="POST" class="lockedit">
                        <?= csrfField() ?>
                        <input type="hidden" name="exc_save" value="1">
                        <table class="table" style="font-size:13px">
                            <thead><tr>
                                <th style="text-align:center">Accorder / إعطاء</th><th>Loi / القانون</th><th>Date / التاريخ</th><th>Nb échelons / عدد الدرجات</th><th>Description / الوصف</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($laws as $law):
                                $applied = in_array($law['law_number'], $appliedLaws);
                                // نفس منطق التطبيق الفعلي: 1/1 بسنة بعد التثبيت (مصدر واحد)
                                $excDate = exceptionalLawEffectiveDate($law, tenureReferenceDate($emp));
                                $nGrades = lawGradesForEmployee($law, $emp); // قانون 2017 مشروط (6/2/0)
                                $na = ($nGrades <= 0);
                            ?>
                                <tr style="<?= $applied ? 'background:#ecfdf5' : ($na ? 'background:#f3f4f6;opacity:.6' : '') ?>">
                                    <td style="text-align:center">
                                        <input type="checkbox" name="exc_laws[]" value="<?= e($law['law_number']) ?>"
                                               <?= $applied ? 'checked' : '' ?> <?= $na ? 'disabled' : '' ?> style="width:20px;height:20px;cursor:pointer">
                                    </td>
                                    <td><strong><?= e($law['law_number']) ?></strong></td>
                                    <td><?= formatDate($excDate) ?> <small>(كانون الثاني)</small></td>
                                    <td><?= $na ? '<small class="text-muted">N/A / لا ينطبق</small>' : '+' . (float)$nGrades ?></td>
                                    <td><small><?= e($law['description_ar'] ?: $law['description_fr']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="submit" class="btn btn-primary w-100"
                                data-confirm="حفظ تابلو الدرجات الاستثنائية لهذا الأستاذ؟ (سيُعاد حساب راتبه)">
                            <i class="fas fa-save"></i> Enregistrer le tableau / حفظ التابلو
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">Les promotions et lois exceptionnelles s'appliquent uniquement aux enseignants titulaires. / الترقيات والقوانين الاستثنائية تنطبق فقط على الأساتذة الملاك.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-edit"></i> Modification manuelle</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">تعديل يدوي</div>
        </h3></div>
        <div class="card-body">
            <form method="POST" class="lockedit">
                <div class="form-row cols-4">
                    <div class="form-group">
                        <label class="form-label">Nouvel échelon / درجة جديدة <small>(½ permis)</small></label>
                        <input type="number" name="new_grade" class="form-control" value="<?= (float)$emp['current_grade'] ?>" min="1" max="52" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date effective / التاريخ الفعلي</label>
                        <input type="date" name="change_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Raison / السبب</label>
                        <select name="reason" class="form-select">
                            <option value="manual">Manuel / يدوي</option>
                            <option value="biennial_promotion">Promotion biennale / ترقية كل سنتين</option>
                            <option value="titularization">Titularisation / تثبيت</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes / ملاحظات</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-history"></i> Historique des changements</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">سجلّ الدرجات</div>
        </h3></div>
        <div class="card-body">
            <?php if ($emp['employee_type'] === 'enseignant_titulaire'): ?>
                <?php renderGradeChecklist($emp, 'grades'); ?>
            <?php elseif (empty($history)): ?>
                <div class="empty-state"><i class="fas fa-history"></i><h4>
                    <span dir="ltr">Aucun historique</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">لا يوجد سجلّ</div>
                </h4></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Date / التاريخ</th><th>Avant / قبل</th><th>Après / بعد</th><th>Diff. / الفرق</th><th>Note / ملاحظة</th></tr></thead>
                    <tbody>
                        <?php foreach ($history as $h): $diff=(float)$h['grade_after']-(float)$h['grade_before']; ?>
                            <tr>
                                <td><?= formatDate($h['change_date']) ?></td>
                                <td><?= $h['grade_before'] ?></td><td><strong><?= $h['grade_after'] ?></strong></td>
                                <td><span class="badge badge-<?= $diff>0?'success':'warning' ?>"><?= $diff>=0?'+':'' ?><?= $diff ?></span></td>
                                <td><small><?= e($h['notes']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ====== جدول تطوّر الراتب والتدرّج (تلقائي، متل «مثل 2») ======
    $evo = ($emp['employee_type'] === 'enseignant_titulaire') ? buildSalaryEvolution($employeeId, $emp['current_grade']) : [];
    $typeLabels = [
        'entry'       => ['Entrée / دخول الملاك', 'gold'],
        'ordinary'    => ['Ordinaire / درجة عادية (تشرين)', 'success'],
        'exceptional' => ['Except. / درجة استثنائية', 'info'],
        'manual'      => ['Manuel / تعديل يدوي', 'secondary'],
        'stable'      => ['—', 'light'],
    ];
    ?>
    <div class="card">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-chart-line"></i> Évolution du salaire</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">تطوّر الراتب والتدرّج</div>
            </h3>
            <?php /* 🧹 زرّ «طباعة» أُزيل — مكرّر مع شريط التصدير فوق (قاعدة المستخدم: لا أزرار مكرّرة) */ ?>
        </div>
        <div class="card-body">
            <?php if (empty($evo)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    لإظهار جدول التطوّر سنة-بسنة تلقائياً، أدخِل درجات الأستاذ في "سجلّ التغييرات" بتواريخها الصحيحة
                    (الترسيم 1/10، الدرجة العادية 1/10، الدرجة الاستثنائية بتاريخها).
                </div>
            <?php else: ?>
                <p style="color:var(--gray-500);font-size:13px;margin-top:0">
                    أساس الراتب يتراكم سنةً بسنة؛ تُضاف الدرجات في تاريخها فيصبح المجموع أساساً للسنة التالية.
                </p>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date / التاريخ</th>
                                <th>Événement / الحدث</th>
                                <th>Échelon / الإشلون</th>
                                <th class="text-end">Base salaire / أساس الراتب</th>
                                <th class="text-end">Échelon ordinaire / درجة عادية</th>
                                <th class="text-end">Échelons except. / درجات استثنائية</th>
                                <th class="text-end">Salaire après / الراتب بعد التدرّج</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evo as $r):
                                [$lbl, $badge] = $typeLabels[$r['type']] ?? [$r['type'], 'light'];
                            ?>
                                <tr<?= $r['type']==='stable' ? ' style="color:var(--gray-400)"' : '' ?>>
                                    <td><?= formatDate($r['date']) ?></td>
                                    <td><span class="badge badge-<?= $badge ?>"><?= e($lbl) ?><?= $r['law'] ? ' '.e($r['law']) : '' ?></span></td>
                                    <td><strong><?= rtrim(rtrim(number_format($r['grade_after'],1),'0'),'.') ?></strong></td>
                                    <td class="text-end"><?= formatLBP($r['base'], false) ?></td>
                                    <td class="text-end text-success"><?= $r['ordinary'] ? '+'.formatLBP($r['ordinary'], false) : '—' ?></td>
                                    <td class="text-end text-info"><?= $r['exceptional'] ? '+'.formatLBP($r['exceptional'], false) : '—' ?></td>
                                    <td class="text-end"><strong><?= formatLBP($r['after'], false) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
