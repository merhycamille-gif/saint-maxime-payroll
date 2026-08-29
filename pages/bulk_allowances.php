<?php
/**
 * تابلو المكافآت وتعويض النقل الجماعي / Primes & Transport groupés
 * يضع قيمة مكافأة واحدة (ليرة/دولار/نسبة) أو قيمة نقل يومي واحدة، ويطبّقها دفعةً واحدة
 * على فئة مختارة (ملاك/متعاقد/موظف/الكل) في مدرسة معيّنة **أو كل المدارس** (للمدير العام) —
 * بدل التعديل ملفاً ملفاً. التعديل الفردي يبقى من ملف الأستاذ ويتجاوز الجماعي.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'bulk_allowances';
$pageTitle = 'Primes & Transport groupés / المكافآت والنقل الجماعي';
$db = getDB();
@set_time_limit(0);

// نطاق المدرسة: المدير العام يختار مدرسة أو **كل المدارس** (sch=all)؛ غيره مدرسته فقط.
$schParam = (string)($_GET['sch'] ?? $_POST['sch'] ?? '');
$scopeAll = isSuperAdmin() && ($schParam === 'all');
$schoolId = $scopeAll ? 0 : ($schParam !== '' ? (int)$schParam : (int)currentSchoolId());
if (!isSuperAdmin()) { $scopeAll = false; $schoolId = (int)currentSchoolId(); }
// الفئات المختارة (شيك-ماركس — يمكن اختيار أكثر من فئة معاً). فارغة = كل الفئات.
$validCats = ['titulaire','contractuel','employe'];
$rawCats = $_POST['cat'] ?? $_GET['cat'] ?? null;
$categories = array_values(array_intersect(is_array($rawCats) ? $rawCats : ($rawCats !== null ? [$rawCats] : []), $validCats));
if (!$categories) $categories = $validCats;
$schoolYear = $_GET['sy'] ?? $_POST['sy'] ?? currentSchoolYear();
if (!preg_match('/^\d{4}-\d{4}$/', (string)$schoolYear)) $schoolYear = currentSchoolYear();
$hasScope = $scopeAll || $schoolId > 0;

// شرط الفئة + شرط المدرسة بصيغة SQL
function catTypeMap() { return ['titulaire'=>'enseignant_titulaire','contractuel'=>'enseignant_contractuel','employe'=>'employe']; }
function catWhere($cats) {
    $map = catTypeMap();
    if (count($cats) >= 3) return '';   // الفئات الثلاث كلها = بلا قيد
    $types = [];
    foreach ($cats as $c) if (isset($map[$c])) $types[] = "'" . $map[$c] . "'";
    return $types ? " AND employee_type IN (" . implode(',', $types) . ")" : " AND 1=0";
}
function catLabel($cats) {
    $lbl = ['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'];
    if (count($cats) >= 3) return 'كل الفئات';
    return implode(' + ', array_map(fn($c)=>$lbl[$c] ?? $c, $cats));
}
function catHidden($cats) { $h=''; foreach ($cats as $c) $h .= '<input type="hidden" name="cat[]" value="'.e($c).'">'; return $h; }
function catQuery($cats) { $q=''; foreach ($cats as $c) $q .= '&cat[]='.urlencode($c); return $q; }
// شيك-ماركس فئات **داخل كل نموذج** (مستقلة لكل قسم) — الافتراضي كل الفئات مختارة.
function catChecks($selected) {
    $out = '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin:4px 0 10px;padding:6px 10px;background:#f8fafc;border-radius:6px"><strong style="font-size:12px;color:#475569">الفئات:</strong>';
    foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l) {
        $chk = in_array($k, $selected, true) ? 'checked' : '';
        $out .= '<label style="font-weight:normal;cursor:pointer;white-space:nowrap;font-size:13px"><input type="checkbox" name="cat[]" value="'.$k.'" '.$chk.'> '.$l.'</label>';
    }
    return $out . '</div>';
}
function schoolWhere($scopeAll, $schoolId) {
    return $scopeAll ? '' : (' AND school_id = ' . (int)$schoolId);
}
function scopeLabel($scopeAll, $schoolId) {
    return $scopeAll ? 'كل المدارس' : schoolNameById($schoolId, 'ar');
}

// قيد «موجود فعلاً بالسنة الدراسية المختارة»: له راتب غير صفري بتلك السنة وغير تارك.
// (السنة مُتحقَّق منها بالتعبير النمطي فآمنة للإدراج المباشر.) prefix لجداول ذات بادئة (مثل e.).
function yearFilter($schoolYear, $prefix = '') {
    if ($schoolYear === 'all' || !preg_match('/^\d{4}-\d{4}$/', (string)$schoolYear)) return '';
    return " AND {$prefix}id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = '" . $schoolYear . "'"
         . " AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0))"
         . " AND {$prefix}left_date_cnss IS NULL AND {$prefix}left_date_finance IS NULL AND {$prefix}left_date_eoc IS NULL";
}

// معرّفات موظفي النطاق+الفئة **الموجودين بالسنة المختارة فقط**
function scopeEmployeeIds($db, $scopeAll, $schoolId, $cat, $sy) {
    return $db->query("SELECT id FROM employees WHERE is_deleted = 0" . schoolWhere($scopeAll, $schoolId) . catWhere($cat) . yearFilter($sy))->fetchAll(PDO::FETCH_COLUMN);
}
// إعادة حساب الفئة (recalcEmployeeYear يتخطّى تلقائياً ذوي الراتب المنقول بلا إعداد فيحميهم)
function recalcScope($db, $scopeAll, $schoolId, $cat, $sy) {
    $ids = scopeEmployeeIds($db, $scopeAll, $schoolId, $cat, $sy);
    $done = 0;
    foreach ($ids as $id) { if (recalcEmployeeYear((int)$id, $sy) > 0) $done++; }
    return [count($ids), $done];
}

// ===== المعالجات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasScope) {
    if (!$scopeAll) requireSchoolSelected();   // نطاق مدرسة واحدة يتطلّب اختيارها؛ «كل المدارس» للمدير العام فقط
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_periods') {
        // أسطر متعدّدة، كل سطر = نوع + من شهر + إلى شهر + قيمة + (مبلغ/نسبة) + عملة.
        // يدعم تغيّر القيمة خلال السنة (مثلاً نقل تشرين-كانون قيمة، ومن شباط قيمة أخرى).
        $lines = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
        $valid = [];
        foreach ($lines as $ln) {
            $type = in_array($ln['type'] ?? '', ['aide_complementaire','prime_fixe','transport_complement'], true) ? $ln['type'] : null;
            $val  = (float)($ln['value'] ?? 0);
            if (!$type || $val <= 0) continue;
            $from = max(1, min(12, (int)($ln['from'] ?? 10)));
            $to   = max(1, min(12, (int)($ln['to'] ?? 9)));
            $vt   = ($ln['vtype'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
            $cur  = ($ln['currency'] ?? 'LBP') === 'USD' ? 'USD' : 'LBP';
            $valid[] = ['type'=>$type, 'val'=>$val, 'from'=>$from, 'to'=>$to, 'vt'=>$vt, 'cur'=>$cur];
        }
        if ($valid) {
            $ids = scopeEmployeeIds($db, $scopeAll, $schoolId, $categories, $schoolYear);
            $typesUsed = array_values(array_unique(array_map(fn($v)=>$v['type'], $valid)));
            $del = $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = ? AND bonus_type = ? AND school_year = ?");
            $ins = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            foreach ($ids as $id) {
                foreach ($typesUsed as $t) $del->execute([$id, $t, $schoolYear]);   // امسح الأنواع المستعملة ثم أعد بناءها بالأسطر
                $pn = 0;
                foreach ($valid as $v) { $pn++; $ins->execute([$id, $v['type'], $pn, $schoolYear, $v['val'], $v['vt'], ($v['vt']==='percent'?'LBP':$v['cur']), $v['from'], $v['to']]); }
            }
            [$tot, $done] = recalcScope($db, $scopeAll, $schoolId, $categories, $schoolYear);
            $_SESSION['flash_success'] = "طُبّقت " . count($valid) . " سطور (مكافأة/نقل) على " . count($ids) . " (" . catLabel($categories) . " — " . scopeLabel($scopeAll,$schoolId) . ") — أُعيد حساب $done (المنقولون يدوياً لم يُمَسّوا).";
        } else $_SESSION['flash_error'] = 'أضِف سطراً واحداً على الأقل بقيمة أكبر من صفر';
    }

    /* ✍️ (2026-08-28، طلبه «مقابل كل سطر اعمل ايديت وحفظ وديليت»): تعديل/حذف سطر واحد من
       جدول «البنود السارية». السطر مجموعة (نوع/قيمة/عملة/فترة/فئة) — التعديل أو الحذف يطال
       كل بنود هذه المجموعة ضمن النطاق والسنة، ثم إعادة حساب المعنيين تلقائياً. */
    elseif ($action === 'row_update' || $action === 'row_delete') {
        $obt = in_array($_POST['old_type'] ?? '', ['prime_fixe','aide_complementaire','transport_complement','transport_daily'], true) ? $_POST['old_type'] : null;
        $ovt = ($_POST['old_vtype'] ?? '') === 'percent' ? 'percent' : 'amount';
        $oam = (string)($_POST['old_amount'] ?? '');
        $ocu = ($_POST['old_currency'] ?? 'LBP') === 'USD' ? 'USD' : 'LBP';
        $osm = ($_POST['old_from'] ?? '') === '' ? null : (int)$_POST['old_from'];
        $oem = ($_POST['old_to'] ?? '') === '' ? null : (int)$_POST['old_to'];
        $oty = in_array($_POST['old_emp_type'] ?? '', array_values(catTypeMap()), true) ? $_POST['old_emp_type'] : null;
        if ($obt && $oty && $oam !== '') {
            $q = $db->prepare("SELECT b.id, b.employee_id FROM employee_bonuses b
                JOIN employees e ON e.id = b.employee_id AND e.is_deleted = 0" . str_replace(' AND school_id', ' AND e.school_id', schoolWhere($scopeAll,$schoolId)) . yearFilter($schoolYear,'e.') . "
                WHERE b.bonus_type = ? AND b.value_type = ? AND b.amount = ? AND b.currency = ?
                  AND (b.start_month <=> ?) AND (b.end_month <=> ?) AND b.school_year = ?
                  AND e.employee_type = ? AND b.is_active = 1");
            $q->execute([$obt, $ovt, $oam, $ocu, $osm, $oem, $schoolYear, $oty]);
            $hits = $q->fetchAll(PDO::FETCH_ASSOC);
            $bidList = array_map(fn($h) => (int)$h['id'], $hits);
            $empIds = array_values(array_unique(array_map(fn($h) => (int)$h['employee_id'], $hits)));
            if ($bidList) {
                $in = implode(',', $bidList);
                if ($action === 'row_delete') {
                    $db->exec("DELETE FROM employee_bonuses WHERE id IN ($in)");
                    $verb = 'حُذف السطر';
                } else {
                    $nval = (float)str_replace(',', '', $_POST['value'] ?? '');
                    $nvt  = ($_POST['vtype'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
                    if ($obt === 'transport_daily') $nvt = 'amount'; // النقل اليومي مبلغ فقط
                    $ncu  = ($_POST['currency'] ?? 'LBP') === 'USD' ? 'USD' : 'LBP';
                    if ($nvt === 'percent') $ncu = 'LBP';
                    $nfrom = ($_POST['from'] ?? '') === '' ? null : max(1, min(12, (int)$_POST['from']));
                    $nto   = ($_POST['to'] ?? '') === '' ? null : max(1, min(12, (int)$_POST['to']));
                    if ($nval > 0) {
                        $db->prepare("UPDATE employee_bonuses SET amount = ?, value_type = ?, currency = ?, start_month = ?, end_month = ? WHERE id IN ($in)")
                           ->execute([$nval, $nvt, $ncu, $nfrom, $nto]);
                        $verb = 'عُدّل السطر';
                    } else { $verb = null; $_SESSION['flash_error'] = 'القيمة يجب أن تكون أكبر من صفر'; }
                }
                if (!empty($verb)) {
                    $done = 0;
                    foreach ($empIds as $eid) { if (recalcEmployeeYear((int)$eid, $schoolYear) > 0) $done++; }
                    $_SESSION['flash_success'] = "$verb (" . count($empIds) . " موظف) وأُعيد حساب $done تلقائياً.";
                }
            } else $_SESSION['flash_error'] = 'السطر لم يعد موجوداً — حدّث الصفحة.';
        }
    }

    elseif ($action === 'remove_bonus') {
        $bonusType = in_array($_POST['bonus_type'] ?? '', ['aide_complementaire','prime_fixe','transport_complement'], true) ? $_POST['bonus_type'] : 'aide_complementaire';
        $ids = scopeEmployeeIds($db, $scopeAll, $schoolId, $categories, $schoolYear);
        $del = $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = ? AND bonus_type = ? AND school_year = ?");
        foreach ($ids as $id) $del->execute([$id, $bonusType, $schoolYear]);
        [$tot, $done] = recalcScope($db, $scopeAll, $schoolId, $categories, $schoolYear);
        $_SESSION['flash_success'] = "أُزيلت المكافأة عن " . count($ids) . " (" . catLabel($categories) . " — " . scopeLabel($scopeAll,$schoolId) . ") — أُعيد حساب $done.";
    }

    elseif ($action === 'apply_transport_periods') {
        // أسطر نقل يومي مؤرّخة (تتغيّر خلال السنة) → employee_bonuses نوع transport_daily.
        $lines = is_array($_POST['tlines'] ?? null) ? $_POST['tlines'] : [];
        $valid = [];
        foreach ($lines as $ln) {
            $val = (float)($ln['value'] ?? 0);
            if ($val <= 0) continue;
            $from = max(1, min(12, (int)($ln['from'] ?? 10)));
            $to   = max(1, min(12, (int)($ln['to'] ?? 9)));
            $cur  = ($ln['currency'] ?? 'LBP') === 'USD' ? 'USD' : 'LBP';
            $valid[] = ['val'=>$val, 'from'=>$from, 'to'=>$to, 'cur'=>$cur];
        }
        if ($valid) {
            $ids = scopeEmployeeIds($db, $scopeAll, $schoolId, $categories, $schoolYear);
            $del = $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = ? AND bonus_type = 'transport_daily' AND school_year = ?");
            // امسح النقل اليومي الثابت (العمود) للمختارين لتفادي ازدواج مع الفترات
            $delCol = $db->prepare("UPDATE employees SET transport_daily_amount = 0 WHERE id = ?");
            $ins = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'transport_daily', ?, ?, ?, 'amount', ?, ?, ?, 1)");
            foreach ($ids as $id) {
                $del->execute([$id, $schoolYear]);
                $delCol->execute([$id]);
                $pn = 0;
                foreach ($valid as $v) { $pn++; $ins->execute([$id, $pn, $schoolYear, $v['val'], $v['cur'], $v['from'], $v['to']]); }
            }
            [$tot, $done] = recalcScope($db, $scopeAll, $schoolId, $categories, $schoolYear);
            $_SESSION['flash_success'] = "طُبّقت " . count($valid) . " سطور نقل يومي على " . count($ids) . " (" . catLabel($categories) . " — " . scopeLabel($scopeAll,$schoolId) . ") — الشهري = اليومي × أيام الحضور × 4. أُعيد حساب $done.";
        } else $_SESSION['flash_error'] = 'أضِف سطر نقل واحد على الأقل بقيمة أكبر من صفر';
    }

    /* 🧍 (2026-08-29، طلبه «المتعاقدين ما في مبلغ ثابت للكل، كل واحد عندو مبلغ — وين بحط؟»):
       مبالغ فردية لكل موظف بجدول واحد (أجر إضافي / مكافأة / نقل شهري، لكل السنة). فاضي = لا تغيير،
       0 = شيل المبلغ. يُستبدل فقط بند «مبلغ لكل السنة» من نفس النوع للموظف؛ النسب والفترات لا تُمسّ. */
    elseif ($action === 'apply_individual') {
        $ind = is_array($_POST['ind'] ?? null) ? $_POST['ind'] : [];
        $scopeIds = array_map('intval', scopeEmployeeIds($db, $scopeAll, $schoolId, ['titulaire','contractuel','employe'], $schoolYear));
        $typesMap = ['prime' => 'prime_fixe', 'aide' => 'aide_complementaire', 'trans' => 'transport_complement'];
        $fullYearSql = "(start_month IS NULL OR (start_month = 10 AND end_month = 9))";
        $delI = $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = ? AND bonus_type = ? AND school_year = ? AND value_type = ? AND $fullYearSql");
        $insI = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, ?, 1, ?, ?, ?, ?, NULL, NULL, 1)");
        $norm = fn($v) => trim(str_replace(',', '', (string)$v));
        $changed = 0; $warns = [];
        foreach ($ind as $eid => $vals) {
            $eid = (int)$eid;
            if (!in_array($eid, $scopeIds, true) || !is_array($vals)) continue;
            $touched = false;
            foreach ($typesMap as $k => $bt) {
                // (أ) النسبة ٪ لكل السنة — خانة مستقلة
                if (array_key_exists($k . '_pct', $vals)) {
                    $raw = $norm($vals[$k . '_pct']); $orig = $norm($vals[$k . '_pct_orig'] ?? '');
                    if ($raw !== '' && !($orig !== '' && (float)$raw == (float)$orig)) {
                        $delI->execute([$eid, $bt, $schoolYear, 'percent']);
                        if ((float)$raw > 0) $insI->execute([$eid, $bt, $schoolYear, (float)$raw, 'percent', 'LBP']);
                        $touched = true;
                    }
                }
                // (ب) المبلغ الثابت لكل السنة — خانة مستقلة (يجتمع مع النسبة إن وُجدت: المحرّك يجمعهما)
                if (array_key_exists($k, $vals)) {
                    $raw = $norm($vals[$k]); $orig = $norm($vals[$k . '_orig'] ?? '');
                    $cur = (($vals[$k . '_cur'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP';
                    $origCur = (($vals[$k . '_origcur'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP';
                    if ($raw !== '' && !($orig !== '' && (float)$raw == (float)$orig && $cur === $origCur)) {
                        $val = (float)$raw;
                        $delI->execute([$eid, $bt, $schoolYear, 'amount']);
                        if ($val > 0) {
                            $w = null; $cur = sanitizeAmountCurrency($val, $cur, $w); if ($w) $warns[] = $w;
                            $insI->execute([$eid, $bt, $schoolYear, $val, 'amount', $cur]);
                        }
                        $touched = true;
                    }
                }
            }
            if ($touched) { recalcEmployeeYear($eid, $schoolYear); $changed++; }
        }
        $_SESSION['flash_success'] = "حُفظت المبالغ الفردية لـ $changed موظف (" . scopeLabel($scopeAll,$schoolId) . ") — أُعيد حساب رواتبهم.";
        if ($warns) $_SESSION['flash_info'] = implode(' · ', array_unique($warns));
    }

    elseif ($action === 'remove_transport_periods') {
        $ids = scopeEmployeeIds($db, $scopeAll, $schoolId, $categories, $schoolYear);
        $del = $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = ? AND bonus_type = 'transport_daily' AND school_year = ?");
        $delCol = $db->prepare("UPDATE employees SET transport_daily_amount = 0 WHERE id = ?");
        foreach ($ids as $id) { $del->execute([$id, $schoolYear]); $delCol->execute([$id]); }
        [$tot, $done] = recalcScope($db, $scopeAll, $schoolId, $categories, $schoolYear);
        $_SESSION['flash_success'] = "أُزيل النقل اليومي عن " . count($ids) . " (" . catLabel($categories) . " — " . scopeLabel($scopeAll,$schoolId) . ") — أُعيد حساب $done.";
    }

    header('Location: ' . BASE_URL . "pages/bulk_allowances.php?sch=" . ($scopeAll ? 'all' : $schoolId) . catQuery($categories) . "&sy=" . urlencode($schoolYear));
    exit;
}

include __DIR__ . '/../includes/header.php';

// موظفو النطاق للمعاينة
$preview = [];
if ($hasScope) {
    $q = $db->prepare("SELECT e.id, e.school_id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr, e.employee_type,
                              e.days_per_week, e.transport_daily_amount, e.transport_daily_currency,
                              (SELECT GROUP_CONCAT(CONCAT(b.bonus_type,':',b.amount,IF(b.value_type='percent','%',''),b.currency) SEPARATOR ' | ')
                               FROM employee_bonuses b WHERE b.employee_id = e.id AND b.school_year = ? AND b.is_active = 1) AS bonuses
                       FROM employees e
                       WHERE e.is_deleted = 0" . schoolWhere($scopeAll,$schoolId) . catWhere($categories) . yearFilter($schoolYear,'e.') . "
                       ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
                                COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $q->execute([$schoolYear]);
    $preview = $q->fetchAll(PDO::FETCH_ASSOC);
}
$exchangeRate = (float)getExchangeRate();

// 🧍 القيم الفردية الحالية لكل موظف (بند «لكل السنة» لكل نوع) — لجدول «مبالغ فردية»
$indivCur = [];
if ($hasScope && $preview) {
    $qi = $db->prepare("SELECT employee_id, bonus_type, value_type, amount, currency FROM employee_bonuses
                        WHERE school_year = ? AND is_active = 1 AND bonus_type IN ('prime_fixe','aide_complementaire','transport_complement')
                          AND (start_month IS NULL OR (start_month = 10 AND end_month = 9))
                          AND employee_id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $preview)) . ")
                        ORDER BY value_type DESC, id");
    $qi->execute([$schoolYear]);
    // البنود المؤرّخة بفترات (ليست لكل السنة) — تُعرَض للعلم فقط (تُعدَّل من ملف الموظف)
    $indivPer = [];
    $qp = $db->prepare("SELECT employee_id, bonus_type, value_type, amount, currency, start_month, end_month FROM employee_bonuses
                        WHERE school_year = ? AND is_active = 1 AND bonus_type IN ('prime_fixe','aide_complementaire','transport_complement')
                          AND NOT (start_month IS NULL OR (start_month = 10 AND end_month = 9))
                          AND employee_id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $preview)) . ") ORDER BY id");
    $qp->execute([$schoolYear]);
    foreach ($qp as $r) {
        $k = ['prime_fixe'=>'prime','aide_complementaire'=>'aide','transport_complement'=>'trans'][$r['bonus_type']];
        $indivPer[(int)$r['employee_id']][$k][] = ($r['value_type'] === 'percent' ? rtrim(rtrim(number_format((float)$r['amount'], 2), '0'), '.') . '٪' : number_format((float)$r['amount']) . ($r['currency'] === 'USD' ? ' $' : ''))
            . ' (' . monthName((int)$r['start_month'], 'ar') . ' ← ' . monthName((int)$r['end_month'], 'ar') . ')';
    }
    foreach ($qi as $r) {
        $k = ['prime_fixe'=>'prime','aide_complementaire'=>'aide','transport_complement'=>'trans'][$r['bonus_type']];
        $e = (int)$r['employee_id'];
        if (!isset($indivCur[$e][$k])) $indivCur[$e][$k] = ['pct' => null, 'amount' => null, 'cur' => 'LBP'];
        if ($r['value_type'] === 'percent') { $indivCur[$e][$k]['pct'] = ($indivCur[$e][$k]['pct'] ?? 0) + (float)$r['amount']; }
        else { $indivCur[$e][$k]['amount'] = ($indivCur[$e][$k]['amount'] ?? 0) + (float)$r['amount']; $indivCur[$e][$k]['cur'] = $r['currency']; }
    }
}

/* ✍️ (2026-08-28، طلبه «لازم يبين السطر اللي فيه النسبة بعد الحفظ»): جدول «المطبّق حالياً» —
   كل تركيبة بنود سارية على النطاق (نوع/قيمة/نسبة أو مبلغ/عملة/فترة) مع عدد الموظفين،
   فيشوف نتيجة حفظه فوراً بدل ما ترجع الصفحة فاضية وكأن شيئاً لم يكن. */
$appliedLines = [];
if ($hasScope) {
    $qa = $db->prepare("SELECT b.bonus_type, b.value_type, b.amount, b.currency, b.start_month, b.end_month, e.employee_type, COUNT(DISTINCT b.employee_id) n
                        FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id AND e.is_deleted = 0
                        WHERE b.is_active = 1 AND b.school_year = ?" . schoolWhere($scopeAll,$schoolId) . str_replace('employee_type','e.employee_type',catWhere($categories)) . yearFilter($schoolYear,'e.') . "
                        GROUP BY b.bonus_type, b.value_type, b.amount, b.currency, b.start_month, b.end_month, e.employee_type
                        ORDER BY FIELD(b.bonus_type,'prime_fixe','aide_complementaire','transport_complement','transport_daily'), FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), n DESC, b.amount DESC");
    $qa->execute([$schoolYear]);
    $appliedLines = $qa->fetchAll(PDO::FETCH_ASSOC);
}
$bonusTypeLbl = ['prime_fixe'=>'➕ الأجر الإضافي / Supplément', 'aide_complementaire'=>'💰 مكافأة ومساعدة / Prime & aide',
                 'transport_complement'=>'🚌 تعويض نقل (شهري) / Transport mensuel', 'transport_daily'=>'🚌 نقل يومي / Transport journalier'];
?>
<style>
/* ✍️ (2026-08-28 ج، طلبه: «اعمل متل ما عاملين هالبرامج العالمية») — نمط أنظمة الرواتب الاحترافية:
   مؤشرات + جدول بنود مركزي + نافذة منبثقة للإضافة مع معاينة حساب حيّة */
.ba-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:14px; }
.ba-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; display:flex; gap:12px; align-items:center; }
.ba-kpi .ic { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex:0 0 38px; }
.ba-kpi .v { font-weight:800; font-size:17px; color:#0f172a; }
.ba-kpi .l { font-size:11.5px; color:#64748b; }
.ba-kpi a { font-size:11px; }
.ba-toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
.ba-badge-type { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; }
.bt-prime { background:#e0e7ff; color:#3730a3; } .bt-aide { background:#fef9c3; color:#854d0e; }
.bt-trans { background:#dcfce7; color:#166534; } .bt-daily { background:#cffafe; color:#155e75; }
.ba-badge-pct { display:inline-block; padding:3px 10px; border-radius:999px; background:#f3e8ff; color:#6b21a8; font-weight:800; font-size:12.5px; }
.ba-badge-cat { display:inline-block; padding:2px 9px; border-radius:999px; background:#f1f5f9; color:#334155; font-size:11.5px; font-weight:700; }
.ba-table th { background:#1F4E5F !important; color:#fff !important; white-space:nowrap; }
.ba-table tbody tr:hover td { background:#f8fafc; }
.ba-empty { text-align:center; color:#94a3b8; padding:34px 10px; }
.ba-empty i { font-size:34px; margin-bottom:8px; display:block; }
/* المودال */
.ba-overlay { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1000; display:none; align-items:flex-start; justify-content:center; padding:4vh 12px; overflow:auto; }
.ba-overlay.open { display:flex; }
.ba-modal { background:#fff; border-radius:14px; width:100%; max-width:760px; box-shadow:0 24px 60px rgba(0,0,0,.25); }
.ba-modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid #e2e8f0; }
.ba-modal-head h4 { margin:0; font-size:15px; color:#1F4E5F; }
.ba-modal-body { padding:16px 20px; }
.ba-modal-foot { padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; }
.ba-x { background:none; border:none; font-size:18px; cursor:pointer; color:#64748b; }
.ba-cats { display:flex; gap:16px; flex-wrap:wrap; align-items:center; padding:8px 12px;
           background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; margin:8px 0; }
.ba-cats strong { color:#9a3412; } .ba-cats label { font-weight:600; cursor:pointer; white-space:nowrap; }
.ba-live { background:#f0fdf4; border:1px dashed #86efac; border-radius:10px; padding:10px 14px; font-size:13px; margin-top:10px; }
.ba-live strong { color:#166534; }
.ba-editor td { vertical-align:middle; }
.ba-editor select[name$="[type]"] { min-width:170px; }
.ba-editor select[name$="[vtype]"] { min-width:110px; }
.ba-editor select[name$="[from]"], .ba-editor select[name$="[to]"] { min-width:100px; }
.ind-cell { display:inline-flex; align-items:center; gap:4px; }
.ind-cell .ind-pct { width:58px; padding:4px 6px; text-align:center; }
.ind-cell .ind-amt { width:128px; padding:4px 8px; text-align:left; }
.ind-cell .ind-cur { width:60px; padding:4px; }
.ind-plus { color:#94a3b8; font-weight:800; }
.ba-step { display:flex; gap:12px; align-items:flex-start; padding:10px 0; border-bottom:1px dashed #e2e8f0; }
.ba-step:last-child { border-bottom:none; }
.ba-step > div { flex:1; min-width:0; }
.ba-num { flex:0 0 30px; width:30px; height:30px; border-radius:50%; background:#1F4E5F; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:14px; }
.ba-hint { color:#64748b; font-size:12.5px; line-height:1.8; }
.ba-warn { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:8px 12px; font-size:12.5px; line-height:1.8; margin-top:8px; color:#78350f; }
.ba-help { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; margin:0 0 12px; font-size:13px; line-height:2; }
.ba-help summary { cursor:pointer; font-weight:800; color:#1F4E5F; }
.ba-help ol { margin:6px 18px 0 0; padding:0; }
</style>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-gift"></i> Primes, supplément &amp; transport</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">المكافآت والأجر الإضافي والنقل</div>
    </h3></div>
    <div class="card-body">
        <form method="GET" class="form-row cols-2 no-print" style="margin-bottom:12px">
            <?php if (isSuperAdmin()): ?>
            <div class="form-group mb-0">
                <label class="form-label">École / المدرسة</label>
                <select name="sch" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choisir / اختر —</option>
                    <option value="all" <?= $scopeAll?'selected':'' ?>>🌐 كل المدارس / Toutes les écoles</option>
                    <?php foreach (allSchools() as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (!$scopeAll && $schoolId===(int)$s['id'])?'selected':'' ?>><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?><input type="hidden" name="sch" value="<?= $schoolId ?>"><?php endif; ?>
            <div class="form-group mb-0">
                <label class="form-label">Année scolaire / السنة الدراسية</label>
                <input type="text" name="sy" class="form-control" value="<?= e($schoolYear) ?>" onchange="this.form.submit()">
            </div>
        </form>
<?php if ($hasScope): $scopeIn = ($scopeAll ? 'all' : $schoolId); ?>
        <div class="ba-kpis">
            <div class="ba-kpi"><span class="ic" style="background:#e0e7ff;color:#3730a3"><i class="fas fa-users"></i></span>
                <span><div class="v"><?= count($preview) ?></div><div class="l">موظف مشمول / Employés</div></span></div>
            <div class="ba-kpi"><span class="ic" style="background:#dcfce7;color:#166534"><i class="fas fa-list-check"></i></span>
                <span><div class="v"><?= count($appliedLines) ?></div><div class="l">بند ساري / Lignes actives</div></span></div>
            <div class="ba-kpi"><span class="ic" style="background:#fef9c3;color:#854d0e"><i class="fas fa-coins"></i></span>
                <span><div class="v"><?= e(officialUsdRateLbl()) ?></div><div class="l">السعر الرسمي القديم (قسمة الأساس) — <a href="<?= BASE_URL ?>pages/settings.php">تعديل</a></div></span></div>
            <div class="ba-kpi"><span class="ic" style="background:#cffafe;color:#155e75"><i class="fas fa-money-bill-trend-up"></i></span>
                <span><div class="v"><?= number_format($exchangeRate) ?></div><div class="l">السعر الجديد — سعر الشهر (تحويل لليرة) — <a href="<?= BASE_URL ?>pages/exchange_rates.php">تعديل</a></div></span></div>
        </div>

        <details class="ba-help no-print">
            <summary><i class="fas fa-circle-question"></i> كيف بشتغل بهالصفحة؟ (اكبس للشرح)</summary>
            <ol>
                <li><b>«+ بند جديد»</b> ← اختر <b>الفئة</b> (ملاك / متعاقدين / موظفين) ← كل سطر = بند: <b>النوع</b> (أجر إضافي / مكافأة ومساعدة / نقل شهري) + <b>نسبة ٪</b> أو <b>مبلغ ثابت</b> + <b>الفترة</b> ← «طبّق». البرنامج بيعيد حساب رواتب الفئة لحاله.</li>
                <li><b>النسبة ٪</b> بتنحسب من أساس الراتب بعد التدرّج (÷<?= e(officialUsdRateLbl()) ?> ← × سعر الشهر) وبتتحرّك مع الدرجة — منطقية للملاك. <b>المبلغ الثابت</b> بالليرة أو بالدولار — للمتعاقدين والموظفين أو لأي زيادة ثابتة.</li>
                <li><b>نسبة + مبلغ ثابت مع بعض</b> (مثلاً 45٪ + 2,000,000 ثابت): حطّهم <b>سطرين بنفس النافذة</b> وكبس طبّق مرّة وحدة. (لو طبّقتهم بمرّتين منفصلتين، التانية بتشيل الأولى لأنها من نفس النوع.)</li>
                <li><b>كل واحد إلو رقمه</b> (المتعاقدون، أو أستاذ ملاك بدّك تعطيه شي خاص): زرّ <b>«مبالغ فردية (لكل واحد)»</b> ← جدول بأسماء الفئة، قدّام كل اسم ولكل نوع خانتان <b>نسبة ٪ + مبلغ ثابت</b> — عبّي وحدة أو الاتنين واحفظ مرّة وحدة. فاضي = ما بيتغيّر، 0 = شيله.</li>
                <li>الجدول تحت = <b>البنود السارية</b>: <i class="fas fa-pen" style="color:#1d4ed8"></i> تعديل سطر لحاله · <i class="fas fa-trash" style="color:#b91c1c"></i> حذفه. <b>«نقل يومي»</b> للمتعاقدين حسب أيام الحضور. <b>استثناء لشخص واحد</b>: من ملفه ← تبويب «المالي».</li>
            </ol>
        </details>

        <div class="ba-toolbar">
            <div style="font-weight:800;color:#1F4E5F;font-size:14.5px"><i class="fas fa-table-list"></i> البنود السارية — <?= e(scopeLabel($scopeAll,$schoolId)) ?> — <?= e($schoolYear) ?></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-primary" onclick="baOpen('baModalAdd')"><i class="fas fa-plus"></i> بند جديد / Ajouter</button>
                <button type="button" class="btn btn-success" onclick="baOpen('baModalIndiv')"><i class="fas fa-user-pen"></i> مبالغ فردية (لكل واحد)</button>
                <button type="button" class="btn btn-light" onclick="baOpen('baModalDaily')"><i class="fas fa-bus"></i> نقل يومي</button>
                <button type="button" class="btn btn-light" style="color:#b91c1c" onclick="baOpen('baModalRemove')"><i class="fas fa-trash"></i> إزالة</button>
            </div>
        </div>

        <?php if ($appliedLines): $catLbl2 = ['enseignant_titulaire'=>'الملاك','enseignant_contractuel'=>'المتعاقدين','employe'=>'الموظفين']; ?>
        <div style="overflow:auto"><table class="table ba-table" style="font-size:13px;min-width:640px">
            <thead><tr><th>النوع / Type</th><th>القيمة / Valeur</th><th>من شهر ← إلى شهر</th><th>الفئة</th><th>عدد الموظفين</th><th style="width:96px">إجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($appliedLines as $al):
                $isPct = ($al['value_type'] === 'percent');
                $typeCls = ['prime_fixe'=>'bt-prime','aide_complementaire'=>'bt-aide','transport_complement'=>'bt-trans','transport_daily'=>'bt-daily'][$al['bonus_type']] ?? 'bt-prime';
                $typeTxt = ['prime_fixe'=>'الأجر الإضافي','aide_complementaire'=>'مكافأة ومساعدة','transport_complement'=>'نقل شهري','transport_daily'=>'نقل يومي'][$al['bonus_type']] ?? $al['bonus_type'];
                $perStr = ($al['start_month'] === null && $al['end_month'] === null)
                    ? 'كل السنة'
                    : monthName((int)$al['start_month'], 'ar') . ' ← ' . monthName((int)$al['end_month'], 'ar');
            ?>
                <tr>
                    <td><span class="ba-badge-type <?= $typeCls ?>"><?= e($typeTxt) ?></span></td>
                    <td><?php if ($isPct): ?>
                            <span class="ba-badge-pct"><?= rtrim(rtrim(number_format((float)$al['amount'], 2), '0'), '.') ?>٪</span>
                            <small style="color:#64748b">من الأساس (÷<?= e(officialUsdRateLbl()) ?>)</small>
                        <?php else: ?>
                            <strong><?= $al['currency'] === 'USD' ? formatUSD($al['amount']) : formatLBP($al['amount']) ?></strong>
                            <?= $al['bonus_type'] === 'transport_daily' ? '<small style="color:#64748b">يومياً</small>' : '' ?>
                        <?php endif; ?></td>
                    <td><?= e($perStr) ?></td>
                    <td><span class="ba-badge-cat"><?= e($catLbl2[$al['employee_type']] ?? $al['employee_type']) ?></span></td>
                    <td><strong><?= (int)$al['n'] ?></strong></td>
                    <td style="white-space:nowrap">
                        <?php $rowJson = e(json_encode(['type'=>$al['bonus_type'],'vt'=>$al['value_type'],'amount'=>(string)$al['amount'],'cur'=>$al['currency'],'from'=>$al['start_month'],'to'=>$al['end_month'],'ety'=>$al['employee_type'],'n'=>(int)$al['n'],'typeTxt'=>$typeTxt,'catTxt'=>$catLbl2[$al['employee_type']] ?? ''], JSON_UNESCAPED_UNICODE)); ?>
                        <button type="button" class="btn btn-sm btn-light" title="تعديل / Modifier" onclick='baEditRow(<?= $rowJson ?>)'><i class="fas fa-pen" style="color:#1d4ed8"></i></button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا السطر عن <?= (int)$al['n'] ?> موظف (<?= e($catLbl2[$al['employee_type']] ?? '') ?>)؟')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="row_delete"><input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>"><?= catHidden($validCats) ?>
                            <input type="hidden" name="old_type" value="<?= e($al['bonus_type']) ?>"><input type="hidden" name="old_vtype" value="<?= e($al['value_type']) ?>">
                            <input type="hidden" name="old_amount" value="<?= e((string)$al['amount']) ?>"><input type="hidden" name="old_currency" value="<?= e($al['currency']) ?>">
                            <input type="hidden" name="old_from" value="<?= $al['start_month'] === null ? '' : (int)$al['start_month'] ?>"><input type="hidden" name="old_to" value="<?= $al['end_month'] === null ? '' : (int)$al['end_month'] ?>">
                            <input type="hidden" name="old_emp_type" value="<?= e($al['employee_type']) ?>">
                            <button type="submit" class="btn btn-sm btn-light" title="حذف / Supprimer"><i class="fas fa-trash" style="color:#b91c1c"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?>
        <div class="ba-empty"><i class="fas fa-inbox"></i>ما في بنود مطبّقة بعد لهالنطاق — اكبس <strong>«+ بند جديد»</strong> لتبلّش.</div>
        <?php endif; ?>

        <details class="ba-fold" style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-top:14px;background:#fff">
            <summary style="cursor:pointer;font-weight:700;color:#1F4E5F;font-size:13.5px"><i class="fas fa-list"></i> لائحة الموظفين المشمولين (<?= count($preview) ?>)</summary>
            <div id="baPreviewHere"></div>
        </details>
    </div>
</div>

<!-- ═══ مودال: بند جديد ═══ -->
<div class="ba-overlay" id="baModalAdd">
  <div class="ba-modal">
    <div class="ba-modal-head"><h4><i class="fas fa-plus-circle"></i> بند جديد — مكافأة / أجر إضافي / نقل شهري</h4><button type="button" class="ba-x" onclick="baClose('baModalAdd')">✕</button></div>
    <form method="POST" id="periodsForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply_periods">
        <input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
        <div class="ba-modal-body">
            <div class="ba-step"><span class="ba-num">١</span><div>
                <strong>على مين؟</strong> <span class="ba-hint">اختر الفئة — البند بينطبق على كل موظفي هالفئة بالمدرسة. (لأستاذ واحد بس: من ملفه ← تبويب «المالي».)</span>
                <div class="ba-cats" style="margin:6px 0 0">
                <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                <label><input type="checkbox" name="cat[]" value="<?= $k ?>" <?= $k==='titulaire'?'checked':'' ?> onchange="baPrefill()"> <?= $l ?></label>
                <?php endforeach; ?>
                </div>
            </div></div>

            <div class="ba-step"><span class="ba-num">٢</span><div style="min-width:0">
                <strong>شو بدّك تعطي؟</strong> <span class="ba-hint">كل سطر = بند: النوع + نسبة ٪ <u>أو</u> مبلغ ثابت + الفترة. بدّك نسبة <u>و</u>مبلغ ثابت مع بعض؟ حطّهم سطرين هون (بنفس الكبسة).</span>
                <div style="overflow:auto;margin-top:6px">
                <table class="table ba-editor" style="font-size:13px;min-width:760px">
                    <thead><tr><th>النوع</th><th>نسبة أو مبلغ؟</th><th>القيمة</th><th>العملة</th><th>من شهر</th><th>إلى شهر</th><th></th></tr></thead>
                    <tbody id="linesBody"></tbody>
                </table>
                </div>
                <button type="button" class="btn btn-sm btn-light" onclick="addLine()"><i class="fas fa-plus"></i> أضِف سطراً (بند تاني أو فترة تانية)</button>
                <div class="ba-hint" style="margin-top:6px">• <b>نسبة ٪</b> = من أساس الراتب <u>بعد التدرّج</u> (للملاك)، وبتتحرّك مع الدرجة لحالها. &nbsp;• <b>مبلغ ثابت</b> = رقم بالليرة أو بالدولار كل شهر. &nbsp;• <b>الفترة</b>: افتراضياً كل السنة (تشرين ← أيلول)؛ قيمة بتتغيّر بنص السنة = سطرين بفترتين.</div>
            </div></div>

            <div class="ba-step"><span class="ba-num">٣</span><div>
                <strong>تأكّد وطبّق.</strong>
                <div class="ba-live" id="baLive" style="margin-top:6px">
                    🧮 <strong>معاينة حيّة:</strong> جرّب على أساس راتب
                    <input type="number" id="baSampleBase" value="1755000" style="width:110px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:6px"> ل.ل ←
                    <span id="baLiveOut" style="font-weight:800;color:#166534">—</span>
                    <div style="font-size:11.5px;color:#64748b;margin-top:4px">قاعدة النسبة: الأساس ÷ <?= e(officialUsdRateLbl()) ?> × النسبة ← داون بالدولار ← × <?= number_format($exchangeRate) ?> ← داون للمليون (النسب المتعدّدة تُجمَع ثم تُدوَّر مرّة وحدة).</div>
                </div>
                <div class="ba-warn" id="baIndivNote" style="display:none;background:#fef2f2;border-color:#fecaca;color:#7f1d1d"></div>
                <div class="ba-warn" id="baReplaceNote">⚠️ عند «طبّق»: الأسطر يلي هون <b>تحلّ محلّ</b> بنود <b>نفس النوع</b> الحالية لهالفئة (مثلاً أجر إضافي جديد بيشيل الأجر الإضافي القديم عند كل الملاك). الأنواع التانية ما بتتأثّر. لهيك النافذة بتفتح <b>معبّاية بالبنود الحالية</b> للفئة — عدّل عليها أو زيد.</div>
            </div></div>
        </div>
        <div class="ba-modal-foot">
            <button type="button" class="btn btn-light" onclick="baClose('baModalAdd')">إلغاء / Annuler</button>
            <button type="submit" class="btn btn-primary" data-confirm="تطبيق هذه الأسطر على الفئات المشيّكة — <?= e(scopeLabel($scopeAll,$schoolId)) ?>؟ (تستبدل الأنواع المُدخَلة لكامل السنة)"><i class="fas fa-check"></i> طبّق / Appliquer</button>
        </div>
    </form>
  </div>
</div>

<!-- ═══ مودال: مبالغ فردية لكل موظف ═══ -->
<div class="ba-overlay" id="baModalIndiv">
  <div class="ba-modal" style="max-width:1100px">
    <div class="ba-modal-head"><h4><i class="fas fa-user-pen"></i> مبالغ فردية — لكل موظف رقمه</h4><button type="button" class="ba-x" onclick="baClose('baModalIndiv')">✕</button></div>
    <form method="POST" id="indivForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply_individual">
        <input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>"><?= catHidden($validCats) ?>
        <div class="ba-modal-body">
            <div class="ba-hint" style="margin-bottom:6px">قدّام كل اسم ولكل نوع خانتان: <b>نسبة ٪</b> (من أساس الراتب بعد التدرّج) <b>+ مبلغ ثابت</b> (ليرة أو دولار) — عبّي وحدة أو الاتنين، والبرنامج بيجمعهم. القيم <b>شهرية لكل السنة</b> (تشرين ← أيلول). <b>فاضي</b> = ما بيتغيّر · <b>0</b> = شيله. للفترات (قيمة بتتغيّر بنص السنة): من ملف الموظف ← تبويب «المالي».</div>
            <div class="ba-cats"><strong>عرض:</strong>
                <?php $indivCats = []; foreach ($preview as $pr) $indivCats[$pr['employee_type']] = true;
                      $catLblI = ['enseignant_titulaire'=>'الملاك','enseignant_contractuel'=>'المتعاقدين','employe'=>'الموظفين']; ?>
                <label><input type="radio" name="indiv_cat_view" value="" checked onchange="baIndivFilter()"> الكل (كل الموظفين والأساتذة)</label>
                <?php foreach ($catLblI as $ek => $el): if (!isset($indivCats[$ek])) continue; ?>
                <label><input type="radio" name="indiv_cat_view" value="<?= $ek ?>" onchange="baIndivFilter()"> <?= $el ?> فقط</label>
                <?php endforeach; ?>
                <span class="ba-hint" id="baIndivCount"></span>
            </div>
            <div style="overflow:auto;max-height:60vh">
            <table class="table ba-editor" id="indivTable" style="font-size:13px;min-width:1000px">
                <thead><tr><th>#</th><th>الاسم</th><th>➕ الأجر الإضافي <small>(٪ + ثابت)</small></th><th>💰 مكافأة ومساعدة <small>(٪ + ثابت)</small></th><th>🚌 نقل شهري <small>(٪ + ثابت)</small></th></tr></thead>
                <tbody>
                <?php $ri = 0; $lastEty = null; foreach ($preview as $pr): $ri++; $eid = (int)$pr['id']; $nm = trim(($pr['first_name_ar'] ?: $pr['first_name_fr']) . ' ' . ($pr['last_name_ar'] ?: $pr['last_name_fr']));
                      if ($pr['employee_type'] !== $lastEty): $lastEty = $pr['employee_type']; ?>
                <tr data-ety="<?= e($lastEty) ?>" class="ind-cat-row"><td colspan="5" style="background:#f1f5f9;font-weight:800;color:#1F4E5F"><?= e($catLblI[$lastEty] ?? $lastEty) ?></td></tr>
                <?php endif; ?>
                <tr data-ety="<?= e($pr['employee_type']) ?>">
                    <td><?= $ri ?></td>
                    <td style="white-space:nowrap"><strong><?= e($nm) ?></strong> <small class="text-muted"><?= e($catLblI[$pr['employee_type']] ?? '') ?></small></td>
                    <?php foreach (['prime','aide','trans'] as $k): $c = $indivCur[$eid][$k] ?? null;
                        $fmtN = fn($v) => ($v === null) ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
                        $vp = $fmtN($c['pct'] ?? null); $va = $fmtN($c['amount'] ?? null); $cu = $c['cur'] ?? 'LBP';
                        if ($va !== '') { $vaP = explode('.', $va); $vaP[0] = number_format((float)$vaP[0]); $va = implode('.', $vaP); } ?>
                    <td style="white-space:nowrap">
                        <span class="ind-cell">
                            <input type="text" inputmode="decimal" name="ind[<?= $eid ?>][<?= $k ?>_pct]" value="<?= e($vp) ?>" placeholder="٪" title="نسبة ٪ من الأساس بعد التدرّج" class="form-control ind-pct" dir="ltr">
                            <input type="hidden" name="ind[<?= $eid ?>][<?= $k ?>_pct_orig]" value="<?= e($vp) ?>">
                            <span class="ind-plus">+</span>
                            <input type="text" inputmode="decimal" name="ind[<?= $eid ?>][<?= $k ?>]" value="<?= e($va) ?>" placeholder="مبلغ ثابت" title="مبلغ ثابت شهري" class="form-control ind-amt" dir="ltr">
                            <select name="ind[<?= $eid ?>][<?= $k ?>_cur]" class="form-select ind-cur"><option value="LBP" <?= $cu==='LBP'?'selected':'' ?>>ل.ل</option><option value="USD" <?= $cu==='USD'?'selected':'' ?>>$</option></select>
                            <input type="hidden" name="ind[<?= $eid ?>][<?= $k ?>_orig]" value="<?= e($va) ?>"><input type="hidden" name="ind[<?= $eid ?>][<?= $k ?>_origcur]" value="<?= e($cu) ?>">
                        </span>
                        <?php if (!empty($indivPer[$eid][$k])): ?>
                            <div style="font-size:11px;color:#b45309;margin-top:2px" title="بند بفترة محدّدة — يُعدَّل من ملف الموظف ← تبويب المالي">📅 فترة: <?= e(implode(' · ', $indivPer[$eid][$k])) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="ba-modal-foot">
            <button type="button" class="btn btn-light" onclick="baClose('baModalIndiv')">إلغاء / Annuler</button>
            <button type="submit" class="btn btn-primary" data-confirm="حفظ المبالغ الفردية المعدَّلة وإعادة حساب رواتب أصحابها؟"><i class="fas fa-save"></i> احفظ الكل / Enregistrer</button>
        </div>
    </form>
  </div>
</div>

<!-- ═══ مودال: نقل يومي ═══ -->
<div class="ba-overlay" id="baModalDaily">
  <div class="ba-modal">
    <div class="ba-modal-head"><h4><i class="fas fa-bus"></i> النقل اليومي — حسب أيام الحضور</h4><button type="button" class="ba-x" onclick="baClose('baModalDaily')">✕</button></div>
    <form method="POST" id="tPeriodsForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply_transport_periods">
        <input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
        <div class="ba-modal-body">
            <div style="font-size:12.5px;color:#64748b;margin-bottom:8px">القيمة <strong>يومية</strong> — الشهري = اليومي × أيام حضور كل أستاذ (من ملفه) × 4 أسابيع.</div>
            <div class="ba-cats"><strong>على مين؟</strong>
                <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                <label><input type="checkbox" name="cat[]" value="<?= $k ?>" checked> <?= $l ?></label>
                <?php endforeach; ?>
            </div>
            <div style="overflow:auto">
            <table class="table ba-editor" style="font-size:13px;min-width:440px">
                <thead><tr><th>القيمة اليومية</th><th>العملة</th><th>من شهر</th><th>إلى شهر</th><th></th></tr></thead>
                <tbody id="tLinesBody"></tbody>
            </table>
            </div>
            <button type="button" class="btn btn-sm btn-light" onclick="addTLine()"><i class="fas fa-plus"></i> أضِف فترة تانية</button>
        </div>
        <div class="ba-modal-foot">
            <button type="button" class="btn btn-light" onclick="baClose('baModalDaily')">إلغاء</button>
            <button type="submit" class="btn btn-primary" data-confirm="تطبيق أسطر النقل اليومي على الفئات المشيّكة — <?= e(scopeLabel($scopeAll,$schoolId)) ?>؟"><i class="fas fa-check"></i> طبّق</button>
        </div>
    </form>
  </div>
</div>

<!-- ═══ مودال: تعديل سطر ═══ -->
<div class="ba-overlay" id="baModalEdit">
  <div class="ba-modal" style="max-width:560px">
    <div class="ba-modal-head"><h4><i class="fas fa-pen"></i> تعديل السطر — <span id="beTitle"></span></h4><button type="button" class="ba-x" onclick="baClose('baModalEdit')">✕</button></div>
    <form method="POST" id="beForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="row_update">
        <input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>"><?= catHidden($validCats) ?>
        <input type="hidden" name="old_type" id="beOldType"><input type="hidden" name="old_vtype" id="beOldVt">
        <input type="hidden" name="old_amount" id="beOldAmount"><input type="hidden" name="old_currency" id="beOldCur">
        <input type="hidden" name="old_from" id="beOldFrom"><input type="hidden" name="old_to" id="beOldTo">
        <input type="hidden" name="old_emp_type" id="beOldEty">
        <div class="ba-modal-body">
            <div style="font-size:12.5px;color:#64748b;margin-bottom:10px">التعديل بيطال <strong id="beCount"></strong> موظف (<span id="beCat"></span>) وبتنعاد رواتبهم تلقائياً.</div>
            <div class="form-row cols-2">
                <div class="form-group"><label class="form-label">مبلغ أو نسبة؟</label>
                    <select name="vtype" id="beVt" class="form-select" onchange="beVtToggle()"><option value="amount">مبلغ ثابت</option><option value="percent">نسبة ٪</option></select></div>
                <div class="form-group"><label class="form-label">القيمة</label>
                    <input type="number" step="0.01" min="0" name="value" id="beVal" class="form-control" required></div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group"><label class="form-label">العملة</label>
                    <select name="currency" id="beCur" class="form-select"><option value="LBP">ل.ل</option><option value="USD">$</option></select></div>
                <div class="form-group"><label class="form-label">من شهر</label><select name="from" id="beFrom" class="form-select"></select></div>
                <div class="form-group"><label class="form-label">إلى شهر</label><select name="to" id="beTo" class="form-select"></select></div>
            </div>
        </div>
        <div class="ba-modal-foot">
            <button type="button" class="btn btn-light" onclick="baClose('baModalEdit')">إلغاء / Annuler</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ / Enregistrer</button>
        </div>
    </form>
  </div>
</div>

<!-- ═══ مودال: إزالة ═══ -->
<div class="ba-overlay" id="baModalRemove">
  <div class="ba-modal" style="max-width:560px">
    <div class="ba-modal-head"><h4 style="color:#b91c1c"><i class="fas fa-trash"></i> إزالة نوع كامل عن فئة</h4><button type="button" class="ba-x" onclick="baClose('baModalRemove')">✕</button></div>
    <form method="POST" onsubmit="return confirm('إزالة هذا النوع عن الفئات المشيّكة كلها؟')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="remove_bonus"><input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
        <div class="ba-modal-body">
            <div class="ba-cats"><strong>عن مين؟</strong>
                <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                <label><input type="checkbox" name="cat[]" value="<?= $k ?>"> <?= $l ?></label>
                <?php endforeach; ?>
            </div>
            <select name="bonus_type" class="form-select" style="margin-top:8px">
                <option value="prime_fixe">➕ الأجر الإضافي</option>
                <option value="aide_complementaire">💰 مكافأة ومساعدة</option>
                <option value="transport_complement">🚌 تعويض نقل (شهري)</option>
            </select>
        </div>
        <div class="ba-modal-foot">
            <button type="button" class="btn btn-light" onclick="baClose('baModalRemove')">إلغاء</button>
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> إزالة</button>
        </div>
    </form>
    <form method="POST" onsubmit="return confirm('إزالة كل النقل اليومي عن كل الفئات؟')" style="padding:0 20px 16px">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="remove_transport_periods"><input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><?= catHidden($validCats) ?><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
        <button type="submit" class="btn btn-sm btn-light" style="color:#b91c1c"><i class="fas fa-bus"></i> إزالة كل النقل اليومي (كل الفئات)</button>
    </form>
  </div>
</div>

<script>
(function(){
    var MONTHS=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    var ORDER=[10,11,12,1,2,3,4,5,6,7,8,9]; var li=0; var ti=0;
    var OFFICIAL=<?= json_encode(officialUsdRate()) ?>, RATE=<?= json_encode($exchangeRate) ?>;
    function mOpts(sel){var o='';ORDER.forEach(function(m){o+='<option value="'+m+'"'+(m==sel?' selected':'')+'>'+MONTHS[m]+'</option>';});return o;}
    // البنود السارية (للتعبئة المسبقة بنافذة «بند جديد» حسب الفئة المختارة)
    var BA_LINES=<?= json_encode(array_values(array_map(function ($al) {
        return ['type'=>$al['bonus_type'],'vt'=>$al['value_type'],'amount'=>(float)$al['amount'],'cur'=>$al['currency'],
                'from'=>$al['start_month']===null?null:(int)$al['start_month'],'to'=>$al['end_month']===null?null:(int)$al['end_month'],'ety'=>$al['employee_type'],'n'=>(int)$al['n']];
    }, $appliedLines ?? [])), JSON_UNESCAPED_UNICODE) ?>;
    // عدد موظفي كل فئة بالنطاق (لتمييز البند العام عن البنود الفردية)
    var BA_CAT_N=<?= json_encode((function () use ($preview) { $c = []; foreach ($preview as $pr) { $c[$pr['employee_type']] = ($c[$pr['employee_type']] ?? 0) + 1; } return $c; })(), JSON_UNESCAPED_UNICODE) ?>;
    var ETY={titulaire:'enseignant_titulaire',contractuel:'enseignant_contractuel',employe:'employe'};
    window.baPrefill=function(){
        var body=document.getElementById('linesBody'); if(!body) return;
        var cats=[].slice.call(document.querySelectorAll('#baModalAdd input[name="cat[]"]:checked')).map(function(c){return ETY[c.value];});
        body.innerHTML=''; li=0;
        var all=BA_LINES.filter(function(r){return r.type!=='transport_daily' && cats.indexOf(r.ety)>=0;});
        // البند «العام» = مطبّق على كل موظفي فئته → يُعبّأ؛ البند الفردي (على أشخاص معيّنين) لا يُعبّأ بل يُنبَّه عليه
        // البند العام لكل (فئة+نوع) = الأكثر انتشاراً (على نصف الفئة أو أكثر)؛ ما عداه فردي (استثناءات أشخاص)
        var best={};
        all.forEach(function(r){var k=r.ety+'|'+r.type; if(!best[k]||r.n>best[k].n) best[k]=r;});
        var general=[], indiv=[];
        all.forEach(function(r){var k=r.ety+'|'+r.type; var cn=BA_CAT_N[r.ety]||0;
            if(best[k]===r && cn>0 && r.n*2>=cn) general.push(r); else indiv.push(r);});
        var seen={}; var added=0;
        general.forEach(function(r){var k=[r.type,r.vt,r.amount,r.cur,r.from,r.to].join('|'); if(seen[k]) return; seen[k]=1;
            addLine(r.type,r.amount,r.vt,r.cur,r.from===null?10:r.from,r.to===null?9:r.to); added++;});
        if(!added) addLine();
        var note=document.getElementById('baReplaceNote'); if(note) note.style.display=(added||indiv.length)?'':'none';
        var iv=document.getElementById('baIndivNote');
        if(iv){
            if(indiv.length){
                var names={prime_fixe:'أجر إضافي',aide_complementaire:'مكافأة',transport_complement:'نقل شهري'};
                var who={}; indiv.forEach(function(r){who[r.ety]=(who[r.ety]||0)+r.n;});
                var lst=indiv.slice(0,6).map(function(r){return names[r.type]+' '+(r.vt==='percent'?r.amount+'٪':r.amount.toLocaleString('en-US'))+' ('+r.n+')';}).join(' · ');
                iv.innerHTML='🧍 <b>بنود فردية موجودة بهالفئة</b> (لأشخاص معيّنين، ما انعبّت هون): '+lst+(indiv.length>6?' …':'')+'<br>«طبّق» على الفئة <b>بيشيلها</b> عن أصحابها وبيوحّدهم مع الكل — إذا بدّك تخلّيها، عدّل البند العام من زرّ ✏️ بجدول البنود بدل هون، أو رجّع بند الشخص من ملفه بعدين.';
                iv.style.display='';
            } else iv.style.display='none';
        }
        baLiveCalc();
    };
    window.baIndivFilter=function(){
        var sel=document.querySelector('input[name="indiv_cat_view"]:checked'); var ety=sel?sel.value:'';
        var n=0; document.querySelectorAll('#indivTable tbody tr').forEach(function(tr){var show=!ety||tr.dataset.ety===ety; tr.style.display=show?'':'none'; if(show&&!tr.classList.contains('ind-cat-row'))n++;});
        var c=document.getElementById('baIndivCount'); if(c) c.textContent='('+n+' موظف)';
    };
    // تنسيق الأرقام بالفواصل أثناء الكتابة بخانات المبالغ الفردية (مثلاً 33,000,000)
    document.addEventListener('input',function(e){ if(e.target.classList&&e.target.classList.contains('ind-amt')){ var v=e.target.value.replace(/[^\d.]/g,''); var parts=v.split('.'); parts[0]=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,','); e.target.value=parts.join('.'); } });
    window.baOpen=function(id){document.getElementById(id).classList.add('open'); if(id==='baModalAdd') baPrefill(); if(id==='baModalIndiv') baIndivFilter();};
    window.baClose=function(id){document.getElementById(id).classList.remove('open');};
    document.querySelectorAll('.ba-overlay').forEach(function(ov){ov.addEventListener('click',function(e){if(e.target===ov)ov.classList.remove('open');});});
    window.baVt=function(sel){
        var tr=sel.closest('tr'); var cur=tr.querySelector('.baCur');
        if(sel.value==='percent'){ cur.disabled=true; cur.style.opacity=0.4; } else { cur.disabled=false; cur.style.opacity=1; }
        baLiveCalc();
    };
    window.baLiveCalc=function(){
        var out=document.getElementById('baLiveOut'); if(!out) return;
        var base=parseFloat((document.getElementById('baSampleBase')||{}).value)||0;
        var rows=[].slice.call(document.querySelectorAll('#linesBody tr')); if(!rows.length||!base){out.textContent='—';return;}
        // متل المحرّك: النسب (لنفس النوع) تُجمَع ثم تُدوَّر مرّة وحدة؛ المبالغ الثابتة تُضاف كما هي
        var parts=[]; var byType={};
        rows.forEach(function(row){
            var type=row.querySelector('select[name$="[type]"]').value, vt=row.querySelector('.baVtSel').value;
            var val=parseFloat(row.querySelector('input[type=number]').value)||0; if(!val) return;
            var cur=row.querySelector('.baCur').value;
            byType[type]=byType[type]||{pct:0,fixed:0};
            if(vt==='percent') byType[type].pct+=val; else byType[type].fixed+=(cur==='USD'?Math.floor(val*RATE):val);
        });
        var names={prime_fixe:'الأجر الإضافي',aide_complementaire:'مكافأة ومساعدة',transport_complement:'نقل شهري'};
        Object.keys(byType).forEach(function(t){
            var b=byType[t], txt=[];
            if(b.pct>0){var usd=Math.floor(Math.floor(base/OFFICIAL)*(b.pct/100)); var lbp=Math.floor(Math.floor(usd*RATE)/1000000)*1000000; txt.push(b.pct+'٪ = '+usd.toLocaleString('en-US')+'$ = '+lbp.toLocaleString('en-US')+' ل.ل');}
            if(b.fixed>0) txt.push('ثابت '+b.fixed.toLocaleString('en-US')+' ل.ل');
            if(txt.length) parts.push(names[t]+': '+txt.join(' + '));
        });
        out.textContent=parts.length?parts.join(' · '):'—';
    };
    document.addEventListener('input',function(e){ if(e.target.closest('#baModalAdd')||e.target.id==='baSampleBase') baLiveCalc(); });
    window.addLine=function(type,val,vt,cur,from,to){
        type=type||'prime_fixe';from=from||10;to=to||9;cur=cur||'LBP';vt=vt||'amount';val=(val===undefined?'':val);
        var i=li++; var tr=document.createElement('tr');
        tr.innerHTML='<td><select name="lines['+i+'][type]" class="form-select">'
          +'<option value="prime_fixe"'+(type=='prime_fixe'?' selected':'')+'>\u2795 الأجر الإضافي</option>'
          +'<option value="aide_complementaire"'+(type=='aide_complementaire'?' selected':'')+'>\uD83D\uDCB0 مكافأة ومساعدة</option>'
          +'<option value="transport_complement"'+(type=='transport_complement'?' selected':'')+'>\uD83D\uDE8C تعويض نقل (شهري)</option></select></td>'
          +'<td><select name="lines['+i+'][vtype]" class="form-select baVtSel" onchange="baVt(this)"><option value="amount"'+(vt=='amount'?' selected':'')+'>مبلغ ثابت</option><option value="percent"'+(vt=='percent'?' selected':'')+'>نسبة \u066A</option></select></td>'
          +'<td><input type="number" step="0.01" min="0" name="lines['+i+'][value]" class="form-control" value="'+val+'" placeholder="مثلاً 45" style="min-width:100px"></td>'
          +'<td><select name="lines['+i+'][currency]" class="form-select baCur"><option value="LBP"'+(cur=='LBP'?' selected':'')+'>ل.ل</option><option value="USD"'+(cur=='USD'?' selected':'')+'>$</option></select></td>'
          +'<td><select name="lines['+i+'][from]" class="form-select">'+mOpts(from)+'</select></td>'
          +'<td><select name="lines['+i+'][to]" class="form-select">'+mOpts(to)+'</select></td>'
          +'<td><button type="button" class="btn btn-sm btn-light" style="color:#b91c1c" onclick="this.closest(&quot;tr&quot;).remove()">\u2715</button></td>';
        document.getElementById('linesBody').appendChild(tr);
        baVt(tr.querySelector('.baVtSel'));
    };
    window.addTLine=function(val,cur,from,to){
        from=from||10;to=to||9;cur=cur||'LBP';val=(val===undefined?'':val);
        var i=ti++; var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="number" step="0.01" min="0" name="tlines['+i+'][value]" class="form-control" value="'+val+'" placeholder="مثلاً 4.5" style="min-width:100px"></td>'
          +'<td><select name="tlines['+i+'][currency]" class="form-select"><option value="LBP"'+(cur=='LBP'?' selected':'')+'>ل.ل</option><option value="USD"'+(cur=='USD'?' selected':'')+'>$</option></select></td>'
          +'<td><select name="tlines['+i+'][from]" class="form-select">'+mOpts(from)+'</select></td>'
          +'<td><select name="tlines['+i+'][to]" class="form-select">'+mOpts(to)+'</select></td>'
          +'<td><button type="button" class="btn btn-sm btn-light" style="color:#b91c1c" onclick="this.closest(&quot;tr&quot;).remove()">\u2715</button></td>';
        document.getElementById('tLinesBody').appendChild(tr);
    };
    var MONTH_OPTS='';ORDER.forEach(function(m){MONTH_OPTS+='<option value="'+m+'">'+MONTHS[m]+'</option>';});
    window.beVtToggle=function(){
        var vt=document.getElementById('beVt').value, cur=document.getElementById('beCur');
        if(vt==='percent'){cur.disabled=true;cur.style.opacity=0.4;} else {cur.disabled=false;cur.style.opacity=1;}
    };
    window.baEditRow=function(r){
        document.getElementById('beTitle').textContent=r.typeTxt;
        document.getElementById('beCount').textContent=r.n;
        document.getElementById('beCat').textContent=r.catTxt;
        document.getElementById('beOldType').value=r.type; document.getElementById('beOldVt').value=r.vt;
        document.getElementById('beOldAmount').value=r.amount; document.getElementById('beOldCur').value=r.cur;
        document.getElementById('beOldFrom').value=(r.from===null?'':r.from); document.getElementById('beOldTo').value=(r.to===null?'':r.to);
        document.getElementById('beOldEty').value=r.ety;
        var vtSel=document.getElementById('beVt');
        vtSel.value=r.vt;
        // النقل اليومي مبلغ فقط — النسبة غير متاحة له
        vtSel.querySelector('option[value=percent]').disabled=(r.type==='transport_daily');
        document.getElementById('beVal').value=parseFloat(r.amount);
        document.getElementById('beCur').value=r.cur;
        var f=document.getElementById('beFrom'), t=document.getElementById('beTo');
        f.innerHTML='<option value="">— كل السنة —</option>'+MONTH_OPTS; t.innerHTML='<option value="">— كل السنة —</option>'+MONTH_OPTS;
        f.value=(r.from===null?'':r.from); t.value=(r.to===null?'':r.to);
        beVtToggle();
        baOpen('baModalEdit');
    };
    addLine(); addTLine();
    // انقل بطاقة المعاينة (لائحة الموظفين) جوّا الطيّة
    var prev=document.getElementById('baPreviewCard'), slot=document.getElementById('baPreviewHere');
    if(prev&&slot){ slot.appendChild(prev); prev.style.marginTop='10px'; }
})();
</script>

<!-- معاينة النطاق -->
<div class="card" id="baPreviewCard">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-list"></i> Aperçu : <?= catLabel($categories) ?> (<?= count($preview) ?>) — <?= e(scopeLabel($scopeAll,$schoolId)) ?> — <?= e($schoolYear) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">معاينة: <?= catLabel($categories) ?> (<?= count($preview) ?>) — <?= e(scopeLabel($scopeAll,$schoolId)) ?> — <?= e($schoolYear) ?></div>
    </h3></div>
    <div class="card-body" style="overflow:auto">
        <table class="table" style="font-size:13px">
            <thead><tr><th>#</th><?php if ($scopeAll): ?><th>École / المدرسة</th><?php endif; ?><th>Nom / الاسم</th><th>Catégorie / الفئة</th><th>Jours/sem. / أيام/أسبوع</th><th>Transport journalier / نقل يومي</th><th>Transport mensuel (calculé) / نقل شهري (محسوب)</th><th>Primes actuelles / المكافآت الحالية</th></tr></thead>
            <tbody>
            <?php $i=1; $baTot=0.0; foreach ($preview as $p):
                $nm = trim(($p['first_name_ar'] ?: $p['first_name_fr']).' '.($p['last_name_ar'] ?: $p['last_name_fr']));
                $td = (float)$p['transport_daily_amount']; $days = (float)$p['days_per_week'];
                $monthly = $td * $days * 4; if (($p['transport_daily_currency'] ?? 'LBP')==='USD') $monthly = usdToLbp($monthly, $exchangeRate);
                $baTot += $monthly;
            ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <?php if ($scopeAll): ?><td style="font-size:11px"><?= e(schoolNameById($p['school_id'],'ar')) ?></td><?php endif; ?>
                    <td><?= e($nm) ?></td>
                    <td><?= empCategoryTitle($p['employee_type']) ?></td>
                    <td><?= (int)$days ?></td>
                    <td><?= $td>0 ? number_format($td).' '.(($p['transport_daily_currency']??'LBP')==='USD'?'$':'ل.ل') : '—' ?></td>
                    <td><?= $monthly>0 ? formatLBP($monthly) : '—' ?></td>
                    <td style="font-size:11px"><?= e($p['bonuses'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($preview): ?><tfoot><tr style="font-weight:700;background:#fdf6e3">
                <td colspan="<?= $scopeAll ? 6 : 5 ?>" style="text-align:right">المجموع — العدد: <?= count($preview) ?> / Total</td>
                <td><?= formatLBP($baTot) ?></td>
                <td></td>
            </tr></tfoot><?php endif; ?>
        </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">Choisissez une école (ou « toutes les écoles ») ci-dessus / اختر مدرسة (أو «كل المدارس») من الأعلى.</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
