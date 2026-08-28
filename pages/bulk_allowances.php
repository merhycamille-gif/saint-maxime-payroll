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
    $qa = $db->prepare("SELECT b.bonus_type, b.value_type, b.amount, b.currency, b.start_month, b.end_month, COUNT(DISTINCT b.employee_id) n
                        FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id AND e.is_deleted = 0
                        WHERE b.is_active = 1 AND b.school_year = ?" . schoolWhere($scopeAll,$schoolId) . str_replace('employee_type','e.employee_type',catWhere($categories)) . yearFilter($schoolYear,'e.') . "
                        GROUP BY b.bonus_type, b.value_type, b.amount, b.currency, b.start_month, b.end_month
                        ORDER BY FIELD(b.bonus_type,'prime_fixe','aide_complementaire','transport_complement','transport_daily'), n DESC, b.amount DESC");
    $qa->execute([$schoolYear]);
    $appliedLines = $qa->fetchAll(PDO::FETCH_ASSOC);
}
$bonusTypeLbl = ['prime_fixe'=>'➕ الأجر الإضافي / Supplément', 'aide_complementaire'=>'💰 مكافأة ومساعدة / Prime & aide',
                 'transport_complement'=>'🚌 تعويض نقل (شهري) / Transport mensuel', 'transport_daily'=>'🚌 نقل يومي / Transport journalier'];
?>
<style>
/* ✍️ (2026-08-28، طلبه: «رتبها ونظمها بطريقة اقدر افهمها وتكون واضحة وبروفيسيونال») */
.ba-step { display:flex; align-items:center; gap:10px; }
.ba-badge { width:34px; height:34px; border-radius:50%; background:#1F4E5F; color:#fff; display:inline-flex;
            align-items:center; justify-content:center; font-weight:800; font-size:16px; flex:0 0 34px; }
.ba-sub { font-size:0.8em; font-weight:600; opacity:0.85; }
.ba-hint { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:8px 12px; font-size:12.5px; color:#1e3a5f; margin:8px 0; }
.ba-applied th { background:#0f766e !important; color:#fff !important; }
.ba-applied tr td { background:#f0fdfa; }
.ba-editor td { vertical-align:middle; }
.ba-cats { display:flex; gap:16px; flex-wrap:wrap; align-items:center; padding:10px 14px;
           background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; margin:10px 0; }
.ba-cats strong { color:#9a3412; }
.ba-cats label { font-weight:600; cursor:pointer; white-space:nowrap; }
</style>

<div class="card">
    <div class="card-header"><h3>
        <div class="ba-step"><span class="ba-badge">١</span>
            <div>اختر المدرسة والسنة <div class="ba-sub" dir="ltr">École &amp; année scolaire</div></div>
        </div>
    </h3></div>
    <div class="card-body">
        <div class="ba-hint">من هون بتضيف مكافآت وأجر إضافي ونقل <strong>لمجموعة كاملة بضغطة وحدة</strong>. التعديل الفردي بيبقى من ملف الأستاذ وبيتجاوز الجماعي، والمنقولون يدوياً محميّون.</div>
        <form method="GET" class="form-row cols-2 no-print" style="margin-bottom:0">
            <?php if (isSuperAdmin()): ?>
            <div class="form-group mb-0">
                <label class="form-label">École / Portée / المدرسة والنطاق</label>
                <select name="sch" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choisir / اختر —</option>
                    <option value="all" <?= $scopeAll?'selected':'' ?>>🌐 Toutes les écoles / كل المدارس (كل البرنامج)</option>
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
    </div>
</div>

<?php if ($hasScope): $scopeIn = ($scopeAll ? 'all' : $schoolId); ?>

<div class="card">
    <div class="card-header"><h3>
        <div class="ba-step"><span class="ba-badge">٢</span>
            <div>شو المطبّق حالياً؟ <div class="ba-sub" dir="ltr">Lignes appliquées actuellement</div></div>
        </div>
    </h3></div>
    <div class="card-body">
        <div class="ba-hint">هيدا الجدول بيفرجيك كل البنود السارية على <strong><?= e(scopeLabel($scopeAll,$schoolId)) ?> — <?= e($schoolYear) ?></strong>. بعد أي حفظ بيتحدّث فوراً.</div>
        <?php if ($appliedLines): ?>
        <div style="overflow:auto"><table class="table ba-applied" style="font-size:13px;min-width:560px">
            <thead><tr><th>النوع / Type</th><th>القيمة / Valeur</th><th>الفترة / Période</th><th>عدد الموظفين / Employés</th></tr></thead>
            <tbody>
            <?php foreach ($appliedLines as $al):
                $isPct = ($al['value_type'] === 'percent');
                $valStr = $isPct
                    ? rtrim(rtrim(number_format((float)$al['amount'], 2), '0'), '.') . '٪ <small style="color:#64748b">من الأساس (قاعدة ÷<?= e(officialUsdRateLbl()) ?>)</small>'
                    : ($al['currency'] === 'USD' ? formatUSD($al['amount']) : formatLBP($al['amount']))
                      . ($al['bonus_type'] === 'transport_daily' ? ' <small style="color:#64748b">يومياً</small>' : '');
                $perStr = ($al['start_month'] === null && $al['end_month'] === null)
                    ? 'كل السنة'
                    : monthName((int)$al['start_month'], 'ar') . ' ← ' . monthName((int)$al['end_month'], 'ar');
            ?>
                <tr>
                    <td><?= $bonusTypeLbl[$al['bonus_type']] ?? e($al['bonus_type']) ?></td>
                    <td><strong><?= $valStr ?></strong></td>
                    <td><?= e($perStr) ?></td>
                    <td><?= (int)$al['n'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?>
        <div class="text-muted" style="font-size:13px;padding:6px 2px">لا بنود مطبّقة على هذا النطاق لهذه السنة بعد / Aucune ligne appliquée.</div>
        <?php endif; ?>
        <details style="margin-top:10px">
            <summary style="cursor:pointer;color:#b91c1c;font-size:13px;font-weight:600"><i class="fas fa-trash"></i> بدّك تشيل نوعاً كاملاً عن فئة؟ / Retirer un type</summary>
            <form method="POST" onsubmit="return confirm('إزالة هذا النوع عن الفئات المشيّكة كلها؟')" style="margin-top:8px">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="remove_bonus"><input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
                <div class="ba-cats"><strong>عن مين؟</strong>
                    <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                    <label><input type="checkbox" name="cat[]" value="<?= $k ?>" <?= in_array($k,$categories,true)?'checked':'' ?>> <?= $l ?></label>
                    <?php endforeach; ?>
                </div>
                <select name="bonus_type" class="form-select" style="display:inline-block;width:auto">
                    <option value="prime_fixe">➕ الأجر الإضافي / Supplément</option>
                    <option value="aide_complementaire">💰 مكافأة ومساعدة / Prime &amp; aide</option>
                    <option value="transport_complement">🚌 تعويض نقل (شهري) / Transport mensuel</option>
                </select>
                <button type="submit" class="btn btn-sm btn-light" style="color:#b91c1c"><i class="fas fa-trash"></i> إزالة / Retirer</button>
            </form>
            <form method="POST" onsubmit="return confirm('إزالة كل النقل اليومي عن الفئات المشيّكة؟')" style="margin-top:8px">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="remove_transport_periods"><input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><?= catHidden($categories) ?><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
                <button type="submit" class="btn btn-sm btn-light" style="color:#b91c1c"><i class="fas fa-bus"></i> إزالة كل النقل اليومي / Retirer transport journalier</button>
            </form>
        </details>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <div class="ba-step"><span class="ba-badge">٣</span>
            <div>ضيف أجر إضافي أو مكافأة أو نقل شهري <div class="ba-sub" dir="ltr">Ajouter supplément / primes / transport mensuel</div></div>
        </div>
    </h3></div>
    <div class="card-body">
        <div class="ba-hint">
            <strong>كيف؟</strong> ① اكبس «تعديل» ② شيّك على مين بدك تطبّق ③ بالسطر: اختار النوع، وقرّر «مبلغ ثابت» أو «نسبة ٪»، واكتب القيمة (النسبة: اكتب <strong>45 بس، بلا علامة ٪</strong>) ④ اكبس «طبّق».<br>
            <strong>النسبة ٪ (قانون الإضافي):</strong> بتنحسب لحالها: (أساس الراتب بعد التدرّج ÷ <?= e(officialUsdRateLbl()) ?>) × النسبة ← داون للدولار ← × سعر السوق ← داون للمليون — وبتتحرّك مع الدرجة تلقائياً.
        </div>
        <form method="POST" id="periodsForm" class="lockedit">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="apply_periods">
            <input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
            <div class="ba-cats"><strong>على مين بدك تطبّق؟</strong>
                <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                <label><input type="checkbox" name="cat[]" value="<?= $k ?>" <?= in_array($k,$categories,true)?'checked':'' ?>> <?= $l ?></label>
                <?php endforeach; ?>
            </div>
            <div style="overflow:auto">
            <table class="table ba-editor" style="font-size:13px;min-width:680px">
                <thead><tr><th>النوع / Type</th><th>مبلغ أو نسبة؟</th><th>القيمة / Valeur</th><th>العملة / Devise</th><th>من شهر / Du</th><th>إلى شهر / Au</th><th></th></tr></thead>
                <tbody id="linesBody"></tbody>
            </table>
            </div>
            <button type="button" class="btn btn-sm btn-light" onclick="addLine()"><i class="fas fa-plus"></i> أضِف سطر تاني / Ajouter ligne</button>
            <button type="submit" class="btn btn-primary" style="float:left" data-confirm="تطبيق هذه الأسطر على «الفئات المشيّكة — <?= e(scopeLabel($scopeAll,$schoolId)) ?>»؟ (تستبدل الأنواع المُدخَلة لكامل السنة)"><i class="fas fa-check"></i> طبّق / Appliquer</button>
            <div style="clear:both"></div>
        </form>
        <script>
        (function(){
            var MONTHS=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            var ORDER=[10,11,12,1,2,3,4,5,6,7,8,9]; var li=0;
            function mOpts(sel){var o='';ORDER.forEach(function(m){o+='<option value="'+m+'"'+(m==sel?' selected':'')+'>'+MONTHS[m]+'</option>';});return o;}
            // النسبة ٪ = ليرة تلقائياً (القاعدة) — نطفي خانة العملة عندها لتوضيح أنها غير مطلوبة
            window.baVt=function(sel){
                var tr=sel.closest('tr'); var cur=tr.querySelector('.baCur');
                if(sel.value==='percent'){ cur.disabled=true; cur.style.opacity=0.4; }
                else { cur.disabled=false; cur.style.opacity=1; }
            };
            window.addLine=function(type,val,vt,cur,from,to){
                /* النوع الافتراضي «الأجر الإضافي» — الأكثر استعمالاً (كان «مكافأة» فسبّب لبساً) */
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
            addLine(); // سطر افتراضي
        })();
        </script>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <div class="ba-step"><span class="ba-badge">٤</span>
            <div>النقل اليومي (حسب أيام الحضور) <div class="ba-sub" dir="ltr">Transport journalier</div></div>
        </div>
    </h3></div>
    <div class="card-body">
        <div class="ba-hint">القيمة هون <strong>يومية</strong> — الشهري بينحسب لحالو: اليومي × أيام حضور كل أستاذ (من ملفه) × 4 أسابيع. القيمة بتتغيّر خلال السنة؟ ضيف سطر لكل فترة.</div>
        <form method="POST" id="tPeriodsForm" class="lockedit">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="apply_transport_periods">
            <input type="hidden" name="sch" value="<?= e($scopeIn) ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
            <div class="ba-cats"><strong>على مين بدك تطبّق؟</strong>
                <?php foreach (['titulaire'=>'الملاك','contractuel'=>'المتعاقدين','employe'=>'الموظفين'] as $k=>$l): ?>
                <label><input type="checkbox" name="cat[]" value="<?= $k ?>" <?= in_array($k,$categories,true)?'checked':'' ?>> <?= $l ?></label>
                <?php endforeach; ?>
            </div>
            <div style="overflow:auto">
            <table class="table ba-editor" style="font-size:13px;min-width:460px">
                <thead><tr><th>القيمة اليومية / Valeur journalière</th><th>العملة / Devise</th><th>من شهر / Du</th><th>إلى شهر / Au</th><th></th></tr></thead>
                <tbody id="tLinesBody"></tbody>
            </table>
            </div>
            <button type="button" class="btn btn-sm btn-light" onclick="addTLine()"><i class="fas fa-plus"></i> أضِف سطر تاني / Ajouter ligne</button>
            <button type="submit" class="btn btn-primary" style="float:left" data-confirm="تطبيق أسطر النقل اليومي على «الفئات المشيّكة — <?= e(scopeLabel($scopeAll,$schoolId)) ?>»؟ (تستبدل النقل اليومي لكامل السنة)"><i class="fas fa-check"></i> طبّق / Appliquer</button>
            <div style="clear:both"></div>
        </form>
        <script>
        (function(){
            var MONTHS=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            var ORDER=[10,11,12,1,2,3,4,5,6,7,8,9]; var ti=0;
            function mOpts(sel){var o='';ORDER.forEach(function(m){o+='<option value="'+m+'"'+(m==sel?' selected':'')+'>'+MONTHS[m]+'</option>';});return o;}
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
            addTLine(); // سطر افتراضي
        })();
        </script>
    </div>
</div>

<!-- معاينة النطاق -->
<div class="card">
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
