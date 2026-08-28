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

        <div class="ba-toolbar">
            <div style="font-weight:800;color:#1F4E5F;font-size:14.5px"><i class="fas fa-table-list"></i> البنود السارية — <?= e(scopeLabel($scopeAll,$schoolId)) ?> — <?= e($schoolYear) ?></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-primary" onclick="baOpen('baModalAdd')"><i class="fas fa-plus"></i> بند جديد / Ajouter</button>
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
            <div class="ba-cats"><strong>على مين؟</strong>
                <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                <label><input type="checkbox" name="cat[]" value="<?= $k ?>" <?= $k==='titulaire'?'checked':'' ?>> <?= $l ?></label>
                <?php endforeach; ?>
            </div>
            <div style="overflow:auto">
            <table class="table ba-editor" style="font-size:13px;min-width:640px">
                <thead><tr><th>النوع</th><th>مبلغ أو نسبة؟</th><th>القيمة</th><th>العملة</th><th>من شهر</th><th>إلى شهر</th><th></th></tr></thead>
                <tbody id="linesBody"></tbody>
            </table>
            </div>
            <button type="button" class="btn btn-sm btn-light" onclick="addLine()"><i class="fas fa-plus"></i> أضِف فترة تانية</button>
            <div class="ba-live" id="baLive">
                🧮 <strong>معاينة حيّة (نسبة ٪):</strong> جرّب على أساس راتب
                <input type="number" id="baSampleBase" value="1755000" style="width:110px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:6px"> ل.ل ←
                <span id="baLiveOut" style="font-weight:800;color:#166534">—</span>
                <div style="font-size:11.5px;color:#64748b;margin-top:4px">القاعدة: الأساس ÷ <?= e(officialUsdRateLbl()) ?> × النسبة ← داون بالدولار ← × <?= number_format($exchangeRate) ?> ← داون للمليون. النسبة بتتحرّك مع الدرجة لحالها.</div>
            </div>
        </div>
        <div class="ba-modal-foot">
            <button type="button" class="btn btn-light" onclick="baClose('baModalAdd')">إلغاء / Annuler</button>
            <button type="submit" class="btn btn-primary" data-confirm="تطبيق هذه الأسطر على الفئات المشيّكة — <?= e(scopeLabel($scopeAll,$schoolId)) ?>؟ (تستبدل الأنواع المُدخَلة لكامل السنة)"><i class="fas fa-check"></i> طبّق / Appliquer</button>
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
    window.baOpen=function(id){document.getElementById(id).classList.add('open');};
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
        var row=document.querySelector('#linesBody tr'); if(!row||!base){out.textContent='—';return;}
        var vt=row.querySelector('.baVtSel').value, val=parseFloat(row.querySelector('input[type=number]').value)||0;
        if(!val){out.textContent='—';return;}
        if(vt==='percent'){
            var usd=Math.floor((base/OFFICIAL)*(val/100));
            var lbp=Math.floor(Math.floor(usd*RATE)/1000000)*1000000;
            out.textContent=val+'٪ = '+usd.toLocaleString('en-US')+'$ = '+lbp.toLocaleString('en-US')+' ل.ل';
        } else {
            out.textContent='مبلغ ثابت: '+val.toLocaleString('en-US');
        }
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
