<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$GLOBALS['msa_recalc_paid_ok'] = true; // 🔒 فعل صريح من المستخدم: يجوز إعادة حساب الأشهر المدفوعة لسنة سابقة (2026-09-23)
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/annual_slip_data.php'; // computeAnnualSlip + schoolYearMonthsFor (موحّد مع التصدير)
requireLogin();

$currentPage = 'annual';
$pageTitle = 'Relevé de salaire annuel / كشف الراتب السنوي';
$db = getDB();

$action = $_GET['action'] ?? '';
$employeeId = (int)($_GET['employee_id'] ?? 0);
$schoolYear = $_GET['school_year'] ?? activeSchoolYear();
if ($schoolYear === 'all') $schoolYear = currentSchoolYear(); // الكشف يحتاج سنة محددة
// 📝 (2026-09-26 «بدي أقدر أطبع بطاقة سنوية فيها المبالغ فاضية حتى إذا بدي عبّي أنا أي أستاذ باليد»):
//    blank=1 = بطاقة هذا الأستاذ بلا أي مبلغ أو رقم راتب (الأشهر والهوية تبقى) · blank=2 = نموذج فارغ لأي أستاذ (الهوية فارغة أيضاً، المدرسة والسنة تبقيان)
//    التصميم نفسه حرفياً (البطاقة مجمّدة) — التفريغ يُطبَّق على الـHTML الجاهز بعد الحساب، فلا يمسّ الحساب ولا العرض العادي.
$slipBlank = max(0, min(2, (int)($_GET['blank'] ?? 0)));
$GLOBALS['slip_blank'] = $slipBlank;
$blankQ = $slipBlank ? '&blank=' . $slipBlank : '';
// 🗂️ (2026-09-26 «كل سنة تبيّن البطاقة السنوية ورا بعضهن» بصفحة التاريخ الكامل): وضع مكتبة — الصفحة الأخرى تعرّف ANNUAL_SLIP_LIB
//    ثم تُدرج هذا الملف فتأخذ دوال البطاقة (annualSlipHtml) وتنسيقها (annualSlipStyleHtml) حرفياً بلا أي إخراج — البطاقة نفسها لم تُمَسّ.
if (defined('ANNUAL_SLIP_LIB')) return;
function annualSlipBlankHtml(string $html, int $level): string {
    // سطر سعر الصرف: رقم ⇒ يُزال
    $html = preg_replace('#<div class="slip-rate"[^>]*>.*?</div>#su', '', $html);
    // جدول الرواتب: كل الخلايا فارغة ما عدا خانة الشهر وخانة «TOTAL» وخانة التوقيع
    $html = preg_replace_callback('#(<table class="salary-slip-table[^"]*">)(.*?)(</table>)#su', function ($m) {
        $body = preg_replace_callback('#<(tbody|tfoot)>(.*?)</\1>#su', function ($sec) {
            $inner = preg_replace_callback('#<td([^>]*)>(.*?)</td>#su', function ($td) {
                if (preg_match('/row-month|sig-cell/', $td[1]) || stripos($td[2], 'TOTAL') !== false) return $td[0];
                return '<td' . $td[1] . '>&nbsp;</td>';
            }, $sec[2]);
            return '<' . $sec[1] . '>' . $inner . '</' . $sec[1] . '>';
        }, $m[2]);
        return $m[1] . $body . $m[3];
    }, $html);
    // رأس الجدول: نسبة الإضافي وسعر الصرف أرقام ⇒ تُزال (تبقى العناوين)
    $html = preg_replace_callback('#<thead>(.*?)</thead>#su', fn($m) => '<thead>' . preg_replace(['#1 \$ = [\d,\.]+#u', '#\d+(?:[.,]\d+)? ?%#u'], '', $m[1]) . '</thead>', $html);
    // الدرجة رقم راتب ⇒ فارغة؛ وبالنموذج العام كل خانات الهوية فارغة والاسم سطر فارغ
    $html = preg_replace('#(<span class="lbl">' . 'Échelon' . ' / ' . 'الدرجة' . '</span><span class="val">)[^<]*(</span>)#u', '$1&nbsp;$2', $html); // (النصّ مجزّأ: فحص 166 يعدّ العنوان مرّة واحدة بالملف)
    if ($level >= 2) {
        $html = preg_replace('#(<span class="val"[^>]*>)[^<]*(</span>)#u', '$1&nbsp;$2', $html);
        $html = preg_replace('#(<span class="slip-pname">)[^<]*(</span>)#u', '$1 __________________________ $2', $html);
    }
    return $html;
}

// فلتر النوع: '' = الكل، أو نوع محدّد
// ☑️ (2026-09-25) خانات تشييك بلائحة الأساتذة (البطاقة نفسها مجمّدة ولا تُمَسّ): المشيّكة فقط تبيّن، ولا واحدة ⇒ لا أحد
$typeState = empTypeSelection($_GET, 'type');
$typeFilter = (!$typeState['all'] && count($typeState['sel']) === 1) ? $typeState['sel'][0] : '';
$typeQ = empTypeQueryFrom($typeState, 'type'); // لاحقة الروابط (تبدأ بـ&)

// School year months: October -> September (or per employee)
[$y1, $y2] = schoolYearToYears($schoolYear);
// schoolYearMonthsFor() مُعرَّفة في includes/annual_slip_data.php (موحّدة مع التصدير)

/**
 * قائمة موظفي السنة الدراسية (مع فلتر النوع). للسنة الحالية/الجاية: الفاعلون + أصحاب رواتب
 * تلك السنة؛ لسنة سابقة: فقط أصحاب رواتب تلك السنة. مرتّبة أبجدياً بالاسم العربي.
 */
function getYearEmployees($db, $schoolYear, $typeFilter = '') {
    // للعرض/الطباعة: فقط من تقاضى راتباً فعلياً (غير صفري) في تلك السنة (يستبعد الأشباح).
    [$yf, $yp] = yearEmploymentFilter($schoolYear, 'e.');
    $sql = "SELECT DISTINCT e.* FROM employees e WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . $yf;
    $params = $yp;
    if (is_array($typeFilter)) $sql .= empTypeSqlFrom($db, $typeFilter, 'e.'); // ☑️ حالة خانات التشييك
    elseif ($typeFilter) { $sql .= " AND e.employee_type = ?"; $params[] = $typeFilter; }
    $sql .= " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * روستر الاحتساب: الموظفون الفاعلون (status=actif وبلا تواريخ ترك) — لاحتساب السنة
 * (يشمل من لم يُحتسب بعد). نُبقي على الفاعلين فقط حتى لا نُنشئ صفوفاً لمن ترك.
 */
function getYearCalcRoster($db, $typeFilter = '') {
    // 🔴 حماية المنقولين يدوياً: الاحتساب الجماعي يشمل فقط ذوي الإعداد الفعلي (ملاك أو أساس>0)؛
    // المتعاقد/الموظف ذو الراتب المنقول (بلا إعداد) لا يُعاد حسابه لئلا يُصفَّر راتبه المخزّن.
    $sql = "SELECT e.id, e.payment_months_per_year FROM employees e WHERE e.is_deleted = 0"
         . " AND e.status = 'actif' AND " . leftDateSql('e.') . " = '9999-12-31'"
         . " AND " . salaryConfigSql('e.')
         . schoolScopeSql('e.school_id');
    $params = [];
    if (is_array($typeFilter)) $sql .= empTypeSqlFrom($db, $typeFilter, 'e.'); // ☑️ حالة خانات التشييك
    elseif ($typeFilter) { $sql .= " AND e.employee_type = ?"; $params[] = $typeFilter; }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// احسب كل أشهر السنة الدراسية دفعة واحدة لهذا الأستاذ
if ($action === 'calc_year' && $employeeId > 0) {
    requireWriteAction(); // 🔒 قراءة-فقط ممنوع + مصدر داخلي فقط
    autoSwitchToEmployeeSchool($employeeId);
    requireSchoolSelected();
    $eC = $db->prepare("SELECT id, payment_months_per_year, employee_type, base_salary_usd, contract_salary_lbp FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $eC->execute([$employeeId]);
    $eC = $eC->fetch();
    $hasConfig = $eC && salaryEngineAllowed($eC, $db); // المصدر الواحد (الجديد بلا أساس منقول يُحسب من ملفه)
    if ($eC && !$hasConfig) {
        // المنقول: لا حساب كامل (يُصفَّر أساسه) — لكن علاواته المسجّلة تُركَّب على أشهره المخزّنة
        $nOv = overlayStoredYearBonuses($employeeId, $schoolYear);
        $_SESSION['flash_success'] = $nOv > 0
            ? "راتبه الأساسي منقول (لا يُعاد حسابه) — ورُكِّبت علاواته المسجّلة على $nOv شهر."
            : 'راتب هذا الموظف مُدخَل يدوياً (منقول) — أساسه لا يُعاد حسابه، وعلاواته مطابقة أصلاً.';
    } elseif ($eC) {
        $months = schoolYearMonthsFor($eC['payment_months_per_year'], $y1, $y2);
        $n = 0;
        foreach ($months as [$m, $y]) {
            try { (new PayrollCalculator($employeeId, $m, $y))->calculateAndSave(); $n++; } catch (Exception $e) {}
        }
        $_SESSION['flash_success'] = "تم احتساب رواتب $n شهر للسنة $schoolYear / $n mois calculés";
    }
    header('Location: ' . BASE_URL . 'pages/annual_slip.php?employee_id=' . $employeeId . '&school_year=' . urlencode($schoolYear));
    exit;
}

// احسب فترة محددة: من شهر/سنة إلى شهر/سنة (لكل أستاذ على حدة)
if ($action === 'calc_range' && $employeeId > 0) {
    requireWriteAction();
    autoSwitchToEmployeeSchool($employeeId);
    requireSchoolSelected();
    $fm = (int)($_GET['from_m'] ?? 0); $fy = (int)($_GET['from_y'] ?? 0);
    $tm = (int)($_GET['to_m'] ?? 0);   $ty = (int)($_GET['to_y'] ?? 0);
    $okEmp = $db->prepare("SELECT id, employee_type, base_salary_usd, contract_salary_lbp FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $okEmp->execute([$employeeId]);
    $ec = $okEmp->fetch();
    $hasConfig = $ec && salaryEngineAllowed($ec, $db); // المصدر الواحد
    if ($ec && !$hasConfig) {
        $_SESSION['flash_error'] = 'راتب هذا الموظف مُدخَل يدوياً (منقول) — لا يُعاد حسابه تلقائياً لئلا يُصفَّر.';
    } elseif ($hasConfig && $fm >= 1 && $fm <= 12 && $tm >= 1 && $tm <= 12 && $fy >= 2000 && $ty >= 2000) {
        $cur = $fy * 12 + ($fm - 1);
        $end = $ty * 12 + ($tm - 1);
        $n = 0;
        while ($cur <= $end && $n < 60) {
            $y = intdiv($cur, 12); $m = ($cur % 12) + 1;
            try { (new PayrollCalculator($employeeId, $m, $y))->calculateAndSave(); $n++; } catch (Exception $e) {}
            $cur++;
        }
        $_SESSION['flash_success'] = "تم احتساب $n شهر من " . monthName($fm, 'fr', true) . " $fy إلى " . monthName($tm, 'fr', true) . " $ty";
    } else {
        $_SESSION['flash_error'] = "حدّد فترة صحيحة (من شهر/سنة إلى شهر/سنة)";
    }
    header('Location: ' . BASE_URL . 'pages/annual_slip.php?employee_id=' . $employeeId . '&school_year=' . urlencode($schoolYear));
    exit;
}

// احتساب الكل للسنة الدراسية (كل أستاذ حسب أشهره) — حسب الفلتر
if ($action === 'calc_all_year') {
    requireWriteAction();
    requireSchoolSelected();
    $emps = getYearCalcRoster($db, $typeState); // الاحتساب على الفاعلين (يشمل غير المحتسَبين بعد)
    $nEmp = 0; $nMonths = 0;
    foreach ($emps as $e) {
        $months = schoolYearMonthsFor($e['payment_months_per_year'], $y1, $y2);
        $did = false;
        foreach ($months as [$m, $y]) {
            try { (new PayrollCalculator($e['id'], $m, $y))->calculateAndSave(); $nMonths++; $did = true; } catch (Exception $ex) {}
        }
        if ($did) $nEmp++;
    }
    $_SESSION['flash_success'] = "تم احتساب رواتب السنة $schoolYear لـ $nEmp موظف ($nMonths شهر) / $nEmp employés";
    header('Location: ' . BASE_URL . 'pages/annual_slip.php?school_year=' . urlencode($schoolYear) . $typeQ);
    exit;
}

/**
 * يبني ويُعيد HTML كشف الراتب السنوي لأستاذ واحد (نفس تصميم العرض الفردي).
 * يُستعمل في العرض الفردي والطباعة الجماعية معاً لضمان تطابق الأرقام.
 */
function annualSlipHtml($db, $emp, $schoolYear) {
    // كل الأرقام من المصدر الموحّد computeAnnualSlip (مطابق للتصدير الرسمي PDF/Excel تماماً)
    $slip = computeAnnualSlip($db, $emp, $schoolYear);
    $meta = $slip['meta']; $rows = $slip['rows']; $tot = $slip['tot']; $empSchool = $slip['school'];
    $slipRate = $meta['rate'];
    // موظف إداري: لا أعمدة درجة/تدرّج ولا صندوق تعويضات (يخضع لقانون العمل — راتب مباشر، نهاية خدمته من الضمان).
    $isEmp = ($emp['employee_type'] === 'employe');
    // 🏦 «ببطاقة المتعاقد ما لازم يكون فيه عمود لصندوق التعويضات — أوعى تخرب البطاقة» (2026-09-19): الصندوق للملاك فقط (المحرّك: enseignant_titulaire)
    //    ⇒ عمودا الصندوق ودرجة/نصف راتب يُخفَيان للمتعاقد كما للموظف الإداري؛ أعمدة الدرجة/التدرّج تبقى له. لا تغيير آخر بالتصميم.
    $noCaisse = $isEmp || $emp['employee_type'] === 'enseignant_contractuel';
    // عدد أعمدة الجدول (تُطرح 4 أعمدة الأستاذ للموظف الإداري) — أعمدة الإضافي/المكافأة/النقل تتبع زرّ «الراتب يشمل»
    // (بطلب المستخدم: النقل خيار بإيده). ولما يكون النقل مخفياً، يُعرض «المستحق» بلا النقل لتبقى الأرقام راكبة.
    // 🚌 خيار ثلاثي (2026-09-17): $showTrans = العمود ظاهر (بالمبلغ أو فارغاً)؛ $transAmt = فيه مبلغ (ويُجمع بالمستحق).
    $showTrans = transportColShown();
    // 💰 عمود المستحق بثلاث حالات (2026-09-19 «أوعى تخربطلي بطاقة الراتب»): نفس نمط النقل — $showDue = العمود ظاهر؛ $dueAmt = فيه مبلغ. عرض فقط.
    $showDue = dueColShown(); $dueAmt = (dueColMode() === 'amount');
    // 👨‍👩‍👧➕ عمود «الصافي + التعويض العائلي» بجانب العائلي — ثلاث حالات (2026-09-20): $showNetFam = ظاهر؛ $netFamAmt = بالمبلغ. عرض فقط.
    $showNetFam = netFamColShown(); $netFamAmt = (netFamColMode() === 'amount');
    $transAmt  = salaryCompHas('transport');
    // 🔴 «الأرقام تركب» (قاعدة ملزِمة): الإضافي والمكافأة داخلان في الإجمالي والصافي والمستحق،
    // فإذا أُخفي عمودهما وجب طرحهما من الثلاثة أيضاً — وإلّا ظهر إجماليٌّ لا يفسّره أي عمود
    // (مثال حقيقي: أساس 1,695,000 وإجمالي 100,545,000 لأنّ 98.85 مليون إضافي مخفيّة).
    // الطرح متوازن حسابياً: الإجمالي−المحسومات = الصافي، والصافي+العائلي+النقل = المستحق.
    $hidRow = function ($r) {
        return (salaryCompHas('extra') ? 0 : (int)($r['extra_wage'] ?? 0))
             + (salaryCompHas('aide')  ? 0 : (int)($r['aide'] ?? 0));
    };
    $hidTot = function ($t) {
        return (salaryCompHas('extra') ? 0 : (int)($t['extra_wage'] ?? 0))
             + (salaryCompHas('aide')  ? 0 : (int)($t['aide'] ?? 0));
    };
    $hidTotUsd = function ($t) {
        return (salaryCompHas('extra') ? 0.0 : (float)($t['extra_wage_usd'] ?? 0))
             + (salaryCompHas('aide')  ? 0.0 : (float)($t['aide_usd'] ?? 0));
    };
    $slipCols = ($isEmp ? 9 : ($noCaisse ? 11 : 13)) + compColsCount() + ($showDue ? 1 : 0) + ($showNetFam ? 1 : 0);

    ob_start();
    ?>
    <div class="salary-slip">
        <?php // سطر واحد: الاسم بالنص، المدرسة على طرف والتقرير/السنة على الطرف الآخر (توفير مساحة لشهر 13) ?>
        <div class="slip-emp-name">
            <span class="slip-school"><?= e($empSchool['name_fr'] ?: $empSchool['name_ar']) ?><?= ($empSchool['name_fr'] && $empSchool['name_ar']) ? ' — ' . e($empSchool['name_ar']) : '' ?></span>
            <span class="slip-pname"><?= e($meta['name']) ?></span>
            <span class="slip-rep">Relevé annuel <?= e($schoolYear) ?> / كشف الراتب السنوي</span>
        </div>
        <?php // 🏷️ «مش موجود ببطاقة المتعاقد» (2026-09-19): سعر الصرف المعتمد بعنوان البطاقة لكل الفئات — بطاقة النسبة كانت تعرضه برؤوس الأعمدة فقط.
              //    سطر واحد فقط يُضاف (التصميم المجمّد لم يُمسّ): سعر كل شهر + آخر سعر؛ و1,500 الرسمي فقط لأصحاب النسبة (بعد التدرّج ÷1500).
              $slipRateTxt = rateTitleText(null, null, true, (float)($meta['rate'] ?? 0) > 0 ? (float)$meta['rate'] : null, ($meta['extra_pct'] ?? '') !== ''); ?>
        <?php if ($slipRateTxt !== ''): ?><div class="slip-rate" dir="rtl"><?= e($slipRateTxt) ?></div><?php endif; ?>
        <table class="slip-info">
            <tr>
                <td><span class="lbl"><?= ($emp['employee_type'] === 'employe') ? 'Fonction / الوظيفة' : 'Diplôme / الشهادة العلمية' ?></span><span class="val"><?= e($meta['diploma']) ?></span></td>
                <td><span class="lbl">Type / الفئة</span><span class="val"><?= e($meta['type']) ?></span></td>
                <?php /* 📚 «حطّ المواد محلّ الدرجة» (طلبه المباشر 2026-09-24): تبديل الخانتين فقط — المواد بالسطر الأوّل والدرجة بالثالث؛ التخطيط المجمّد لم يُمسّ */ ?>
                <td><span class="lbl">Matières / المواد</span><span class="val"><?= e($meta['subjects']) ?></span></td>
                <td><span class="lbl">Code / الرمز</span><span class="val"><?= e($meta['code']) ?><?= ($meta['birth'] ?? '') !== '' ? ' · ولادة ' . e($meta['birth']) : '' ?></span></td><?php /* 👤 تاريخ الولادة بنفس الخانة — لا صفّ ولا خانة جديدة (البطاقة كما هي) */ ?>
            </tr>
            <tr>
                <td><span class="lbl">Embauche / تاريخ الدخول</span><span class="val"><?= e($meta['hire']) ?></span></td>
                <td><span class="lbl">Titularisation / تاريخ الملاك</span><span class="val"><?= e($meta['titul']) ?></span></td>
                <?php if (($meta['hours_red'] ?? '') !== ''): // 🕐 التناقص وحضوره بنفس الخانة (بلا خانة/صف جديد — ارتفاع الصفوف والصفحة كما هما، مفحوص) ?>
                <td><span class="lbl">Heures / jours par semaine — الساعات / الأيام أسبوعياً</span><span class="val" dir="ltr" style="white-space:nowrap;unicode-bidi:isolate"><?= e($meta['hours']) ?> h / <?= e($meta['days']) ?> j · Réduction <?= e($meta['hours_red']) ?> h = Présence <?= e($meta['hours_pres']) ?> h</span></td>
                <?php else: ?>
                <td><span class="lbl">Heures / jours par semaine — الساعات / الأيام أسبوعياً</span><span class="val"><?= e($meta['hours']) ?> h / <?= e($meta['days']) ?> j</span></td>
                <?php endif; ?>
                <td><span class="lbl">Classes / الصفوف</span><span class="val"><?= e($meta['classes']) ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">Échelon / الدرجة</span><span class="val"><?= e($meta['grade']) ?></span></td>
                <td><span class="lbl">N° CNSS / رقم الضمان</span><span class="val"><?= e($meta['cnss']) ?></span></td>
                <td><span class="lbl">N° Caisse / رقم صندوق التعويضات</span><span class="val"><?= e($meta['caisse_no']) ?></span></td>
                <td><span class="lbl">N° Fin. / الرقم المالي</span><span class="val"><?= e($meta['finance_no']) ?></span></td>
            </tr>
        </table>

        <table class="salary-slip-table curmode-<?= displayCurrency() ?>">
            <thead>
                <tr>
                    <th rowspan="2">Mois<br>الشهر</th>
                    <th rowspan="2">Salaire<br>أساس الراتب</th>
                    <?php if (!$isEmp): ?>
                    <th rowspan="2">Valeur échelon<br>قيمة الدرجة (ل.ل)</th>
                    <th rowspan="2">Après échelon<br>الراتب بعد التدرج<?= ($meta['extra_pct'] ?? '') !== '' ? '<br><span dir="ltr">1 $ = ' . e($meta['old_rate']) . '</span>' : '' /* 🧮 السعر الرسمي القديم (قانون النسبة) */ ?></th>
                    <?php endif; ?>
                    <?php if (salaryCompHas('extra')): ?><th rowspan="2">Supplément<br>الأجر الإضافي<?= ($meta['extra_pct'] ?? '') !== '' ? '<br><span dir="ltr">' . e($meta['extra_pct']) . ' %</span>' . (($meta['new_rates'] ?? '') !== '' ? '<br><span dir="ltr">1 $ = ' . e($meta['new_rates']) . '</span>' : '') : '' /* 🧮 نسبة الإضافي المعطاة له + السعر الجديد (سعر صرف الشهر) تحت عنوان العمود (p1 — 2026-09-03) */ ?></th><?php endif; ?>
                    <?php if (salaryCompHas('aide')): ?><th rowspan="2">Prime &amp; aide<br>مكافأة ومساعدة</th><?php endif; ?>
                    <th rowspan="2">Brut<br>الإجمالي</th>
                    <th colspan="<?= $noCaisse ? 3 : 5 ?>" class="deduction-header">Retenues / المحسومات</th>
                    <th rowspan="2">Net<br>الصافي</th>
                    <th rowspan="2">Alloc. fam.<br>عائلي</th>
                    <?php if ($showNetFam): ?><th rowspan="2"<?= $netFamAmt ? '' : ' class="due-blank"' ?>>Net + alloc.<br>الصافي + عائلي</th><?php endif; ?>
                    <?php if ($showTrans): ?><th rowspan="2">Transport<br>نقل</th><?php endif; ?>
                    <?php if ($showDue): ?><th rowspan="2"<?= $dueAmt ? '' : ' class="due-blank"' ?>>Total dû<br>المستحق</th><?php endif; ?>
                    <th rowspan="2" class="sig-col">Signature<br>التوقيع</th>
                </tr>
                <tr>
                    <?php if (!$noCaisse): ?>
                    <th class="deduction-header">Caisse</th>
                    <th class="deduction-header">Échelon / ½ sal.<br>درجة / نصف راتب</th>
                    <?php endif; ?>
                    <th class="deduction-header">CNSS</th>
                    <th class="deduction-header">Impôt</th>
                    <th class="deduction-header">Total ret.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="row-month"><?= e($r['label']) ?></td>
                        <?php if (!empty($r['s'])):
                            $rate = $r['rate'];
                            // ✍️ (2026-08-25) «بدون الفراطات — داون»: الدولار صحيح بالتدوير لتحت؛ الليرة بالمليم كما هي
                            $usd = function($lbp) use ($rate) { return $rate > 0 ? floor($lbp / $rate) : 0; };
                            // عرض القيمة: الليرة الرئيسية وتحتها الدولار صغيراً بالأخضر (— إن صفر)
                            // موحَّد مع كل كشوف البرنامج الرسمية (شكوى p1: الدولار فوق كان يعجّق الخانات)
                            $money = function($lbp, $bold = false) use ($rate) {
                                $lbp = (int)$lbp; if ($lbp == 0) return '—';
                                $l = formatLBP($lbp, false);
                                if ($bold) $l = '<strong>' . $l . '</strong>';
                                $u = number_format(($rate > 0 ? floor($lbp / $rate) : 0), 0) . ' $';
                                // الدولار في span قابل للإخفاء (زرّ العملة يعرض الليرة فقط/الدولار فقط)
                                return '<span class="sub-lbp">' . $l . '</span><span class="cur-usd">' . $u . '</span>';
                            };
                        ?>
                            <?php /* 🔠 «أحجام المبالغ بالليرة متل بعضها» (طلب المستخدم 2026-08-01):
                                     num-lbp = نفس حجم كل مبالغ الليرة بالجدول (14 عريض) */ ?>
                            <td class="num-lbp"><strong><?= formatLBP($r['base_shown'], false) ?></strong></td>
                            <?php if (!$isEmp): ?>
                            <td class="num-lbp"><?= $r['grade_inc'] > 0 ? formatLBP($r['grade_inc'], false) : '—' ?></td>
                            <td class="num-lbp"><?php if (($meta['extra_pct'] ?? '') !== ''): // 🧮 تحته قيمته بالدولار القديم (÷1500 داون) — أساس قانون النسبة ?><span class="sub-lbp"><strong><?= formatLBP($r['cur_sal'], false) ?></strong></span><span class="cur-usd"><?= number_format((int)$r['cur_sal_old_usd'], 0) ?> $</span><?php else: ?><strong><?= formatLBP($r['cur_sal'], false) ?></strong><?php endif; ?></td>
                            <?php endif; ?>
                            <?php if (salaryCompHas('extra')): ?><td><?php if ($r['extra_wage'] > 0): ?><span class="sub-lbp"><?= formatLBP($r['extra_wage'], false) ?></span><span class="cur-usd"><?= number_format($r['extra_law_usd'] !== null ? (int)$r['extra_law_usd'] : $usd($r['extra_wage']), 0) /* 🧮 لأصحاب النسبة: دولار القانون (844 $) */ ?> $</span><?php else: ?>—<?php endif; ?></td><?php endif; ?>
                            <?php if (salaryCompHas('aide')): ?><td><?php if ($r['aide'] > 0): ?><span class="sub-lbp"><?= formatLBP($r['aide'], false) ?></span><span class="cur-usd"><?= number_format($usd($r['aide']), 0) ?> $</span><?php else: ?>—<?php endif; ?></td><?php endif; ?>
                            <?php $hR = $hidRow($r); ?>
                            <td><?= $money($r['brut'] - $hR, true) ?></td>
                            <?php if (!$noCaisse): ?>
                            <td><?= $money($r['caisse']) ?></td>
                            <td><?= $money($r['eoc_grade']) ?></td>
                            <?php endif; ?>
                            <td><?= $money($r['cnss']) ?></td>
                            <td><?= $money($r['income_tax']) ?></td>
                            <td><?= $money($r['total_retenues']) ?></td>
                            <td><?= $money($r['net'] - $hR, true) ?></td>
                            <td><?= $money($r['family']) ?></td>
                            <?php if ($showNetFam): ?><td<?= $netFamAmt ? '' : ' class="due-blank"' ?>><?= $netFamAmt ? $money($r['net'] - $hR + $r['family'], true) : '&nbsp;' ?></td><?php endif; ?>
                            <?php if ($showTrans): ?><td><?= $transAmt ? $money($r['transport']) : '&nbsp;' ?></td><?php endif; ?>
                            <?php if ($showDue): ?><td<?= $dueAmt ? '' : ' class="due-blank"' ?>><?= $dueAmt ? $money($r['total_due'] - $hR - ($transAmt ? 0 : $r['transport']), true) : '&nbsp;' ?></td><?php endif; ?>
                            <td class="sig-cell">&nbsp;</td>
                        <?php else: ?>
                            <td colspan="<?= $slipCols ?>" class="text-muted">—</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php $moneyTot = function($lbp, $usd, $bold = true) {
                    $l = formatLBP((int)$lbp, false); if ($bold) $l = '<strong>' . $l . '</strong>';
                    // المجموع بالدولار = جمع الأرقام الشهرية المدوّرة نفسها (الأرقام تركب) — عرضه صحيحاً
                    $u = number_format(floor($usd), 0) . ' $';
                    return '<span class="sub-lbp">' . $l . '</span><span class="cur-usd">' . $u . '</span>';
                }; ?>
                <tr class="total-row">
                    <td><strong>TOTAL</strong></td>
                    <td class="num-lbp"><strong><?= formatLBP($tot['base_shown'], false) ?></strong></td>
                    <?php if (!$isEmp): ?>
                    <td class="num-lbp"><strong><?= formatLBP($tot['grade_inc'], false) ?></strong></td>
                    <td class="num-lbp"><?php if (($meta['extra_pct'] ?? '') !== ''): ?><span class="sub-lbp"><strong><?= formatLBP($tot['base_plus_echelon'], false) ?></strong></span><span class="cur-usd"><?= number_format((int)$tot['bpe_old_usd'], 0) ?> $</span><?php else: ?><strong><?= formatLBP($tot['base_plus_echelon'], false) ?></strong><?php endif; ?></td>
                    <?php endif; ?>
                    <?php if (salaryCompHas('extra')): ?><td><span class="sub-lbp"><strong><?= formatLBP($tot['extra_wage'], false) ?></strong></span><span class="cur-usd"><?= number_format(floor(!empty($meta['has_pct']) ? $tot['extra_law_usd'] : $tot['extra_wage_usd']), 0) ?> $</span></td><?php endif; ?>
                    <?php if (salaryCompHas('aide')): ?><td><span class="sub-lbp"><strong><?= formatLBP($tot['aide'], false) ?></strong></span><span class="cur-usd"><?= number_format(floor($tot['aide_usd']), 0) ?> $</span></td><?php endif; ?>
                    <td><?= $moneyTot($tot['brut'], $tot['brut_usd']) ?></td>
                    <?php if (!$noCaisse): ?>
                    <td><?= $moneyTot($tot['caisse'], $tot['caisse_usd']) ?></td>
                    <td><?= $moneyTot($tot['eoc_grade'], $tot['eoc_grade_usd']) ?></td>
                    <?php endif; ?>
                    <td><?= $moneyTot($tot['cnss'], $tot['cnss_usd']) ?></td>
                    <td><?= $moneyTot($tot['income_tax'], $tot['tax_usd']) ?></td>
                    <td><?= $moneyTot($tot['total_retenues'], $tot['totret_usd']) ?></td>
                    <td><?= $moneyTot($tot['net'], $tot['net_usd']) ?></td>
                    <td><?= $moneyTot($tot['family'], $tot['family_usd']) ?></td>
                    <?php if ($showNetFam): ?><td<?= $netFamAmt ? '' : ' class="due-blank"' ?>><?= $netFamAmt ? $moneyTot($tot['net'] + $tot['family'], $tot['net_usd'] + $tot['family_usd']) : '&nbsp;' ?></td><?php endif; ?>
                    <?php if ($showTrans): ?><td><?= $transAmt ? $moneyTot($tot['transport'], $tot['transport_usd']) : '&nbsp;' ?></td><?php endif; ?>
                    <?php if ($showDue): ?><td<?= $dueAmt ? '' : ' class="due-blank"' ?>><?= $dueAmt ? $moneyTot($tot['total_due'] - ($transAmt ? 0 : $tot['transport']), $tot['total_due_usd'] - ($transAmt ? 0 : $tot['transport_usd'])) : '&nbsp;' ?></td><?php endif; ?>
                    <td class="sig-cell"></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    $slipOut = ob_get_clean();
    if (!empty($GLOBALS['slip_blank'])) $slipOut = annualSlipBlankHtml($slipOut, (int)$GLOBALS['slip_blank']); // 📝 بطاقة فاضية
    return $slipOut;
}

// 🔴 لا doc-view هنا: بطاقة الراتب السنوية لها تصميمها الخاص وكانت تصير صغيرة ضايعة
// بفراغ رمادي (شكوى المستخدم p1 بتاريخ 2026-08-01) — الصفحة تبقى بشكلها المعهود.
// 🧹 «الأزرار مكرّرة وعجقة» (اختيار المستخدم 2026-08-01): شريط التصدير العام يُخفى —
// للصفحة أزرارها الخاصة الكاملة (PDF رسمي/Excel/طباعة) بمجموعة واحدة واضحة بلا تكرار.
$hideExportToolbar = true;
include __DIR__ . '/../includes/header.php';
?>

<?php function annualSlipStyleHtml(): string { ob_start(); ?>
<style>
/* ترويسة المدرسة: بلا خط أفقي ثقيل، مرتّبة */
.salary-slip-header { border-bottom: none !important; align-items: flex-start; padding-bottom: 6px; margin-bottom: 14px; }
.salary-slip-header .ssh-school h2 { margin:0; color:var(--primary); font-size:22px; }
.ssh-ar { margin:3px 0 0; color:var(--gray-700); font-size:16px; font-weight:700; }
.ssh-addr { margin:3px 0 0; font-size:12pt; color:var(--gray-500); }
.ssh-title { text-align:end; }
.ssh-title h3 { margin:0; color:var(--primary); font-size:18px; }
.ssh-sub { color:var(--gray-700); font-weight:700; font-size:14px; margin:2px 0 0; }
.ssh-year { margin:6px 0 0; font-weight:700; font-size:15px; color:var(--primary); }

/* سطر واحد: الاسم بالنص، المدرسة والتقرير على الطرفين (بارز فوق الجدول) */
.slip-emp-name { display:flex; align-items:center; gap:10px; font-weight:700; color:var(--primary); background:#eff6ff; border:1px solid var(--gray-300); border-radius:6px; padding:8px 12px; margin-bottom:12px; font-size:16px; }
.slip-emp-name .slip-school { flex:1; text-align:start; color:#0a2240; }
/* 🔤 «المخلصيات لازم تكون على نفس السطر» (بطلبه 2026-09-04 عن p1): اسم المدرسة وعنوان الكشف لا ينكسران
   لسطرين أبداً — سطر واحد لكل جزء (أطول اسم مدرسة 92 حرفاً مفحوص بالورق)؛ وعالشاشة الضيّقة ينزل
   عنوان الكشف كاملاً لسطر تحت بدل كسر الاسم بنصّه */
.slip-emp-name { flex-wrap:wrap; }
.slip-emp-name .slip-school, .slip-emp-name .slip-rep, .slip-emp-name .slip-pname { white-space:nowrap; }
.slip-emp-name .slip-rep { flex:1; text-align:end; color:var(--gray-700); font-weight:700; }
.salary-slip .slip-rate { text-align:center; color:var(--gray-700); font-weight:700; font-size:10.5pt; margin:2px 0 4px; } /* 🏷️ سعر الصرف المعتمد (2026-09-19) */
.slip-emp-name .slip-pname { flex:0 0 auto; text-align:center; color:var(--primary); font-size:1.12em; }

/* معلومات الموظف: شبكة مرتّبة بحدود (تسمية صغيرة + قيمة) */
.slip-info { width:100%; border-collapse:collapse; margin-bottom:16px; }
.slip-info td { border:1px solid var(--gray-300); padding:6px 10px; vertical-align:top; width:25%; }
.slip-info .lbl { display:block; color:var(--gray-500); font-weight:700; font-size:12pt; margin-bottom:2px; }
.slip-info .val { font-weight:700; font-size:12pt; color:#111827; }

/* 📄 بطاقة الراتب بذوق المستخدم النهائي (2026-08-01): + الليرة الرئيسية والدولار صغيراً تحتها +
   بلا كحلي — رؤوس هادئة فاتحة وعناوين «المحسومات» وحدها بأحمر فاتح.
   ✍️ بطلبه (2026-09-04 عن p1.png = كشف برنامجه القديم): الخط Arial بكل اللغات متل التقارير والإفادات
   («البطاقة السنوية ولكن غير الخط بس، أوعى تلخبط الصفحة») — الخط فقط تغيّر، باقي التصميم مجمّد كما هو
   🔤 «بدي الحرف بالعربي ببطاقة الأستاذ متل p1» (2026-09-04): الوزن 800 ممنوع بالبطاقة — ويندوز يعدّ Arial Black
   وزن 900 من عائلة Arial، فأي 800 يقفز إليها وهي بلا حروف عربية فينزل العربي على Tahoma (مش متل p1).
   الوزن 700 = Arial Bold بحروفها العربية (p1 كله Arial Bold) — الاسم والقيم 700 بالشاشة والورق */
.salary-slip, .salary-slip-table, .slip-info { font-family:Arial,'Segoe UI',Tahoma,sans-serif; }
.salary-slip-table { font-size: 12pt; }
.salary-slip-table th { font-size: 12pt; padding: 7px 6px; background:#f1f5f9; color:#111827; border:1px solid #94a3b8; }
.salary-slip-table .deduction-header { background:#ffe3e3 !important; color:#7f1d1d !important; }
.salary-slip-table td { padding: 8px 8px; }
/* ✍️ (2026-08-25) «P1 بدون ألوان هون»: لا تخطيط متناوباً بصفوف المبالغ — كل السطور بيض */
/* الليرة الرئيسية (سطر أول واضح) والدولار صغيراً بالأخضر تحتها — متل كل الكشوف */
.salary-slip-table .sub-lbp { display:block; font-weight:600; color:#111827; }
/* أعمدة الليرة الصرفة (أساس/قيمة الدرجة/بعد التدرج): نفس حجم باقي مبالغ الليرة تماماً */
.salary-slip-table .num-lbp { font-weight:700; color:#111827; }
/* ✍️ (2026-08-25) «بدون ألوان بخطوط المبالغ»: الدولار أسود متل الليرة — لا أخضر */
.salary-slip-table .cur-usd { display:block; font-size:0.8em; color:#111827; font-weight:600; line-height:1.2; }
/* وضع العملة المختار من الزرّ العام: إظهار/إخفاء الليرة أو الدولار */
.salary-slip-table.curmode-lbp .cur-usd { display:none; }
.salary-slip-table.curmode-lbp .sub-lbp { color:#111; font-size:1em; }
.salary-slip-table.curmode-usd .sub-lbp { display:none; }
.salary-slip-table.curmode-usd .cur-usd { font-size:1em; color:#111827; }
/* عمود التوقيع أوسع شوي */
.salary-slip-table .sig-col, .salary-slip-table .sig-cell { min-width: 120px; }
.salary-slip-table .due-blank { min-width: 130px; } /* 💰 «عمود المستحق طلع ضيق — وسّعه حتى إذا كتبت المبلغ يساع» (2026-09-19): بوضع «بلا مبلغ» فقط؛ بالمبلغ العمود كما كان */

/* الطباعة: صفحة A4 أفقية + ألوان فاتحة لتوفير الحبر
   🔠 الخط 12pt («12» متل الوورد، بطلب المستخدم 2026-07-31) — والقسيمة الأعرض/الأطول من
   ورقتها تصغّر نفسها محسوباً (--pz من app.js) فتبقى بصفحة واحدة بلا قصّ */
@media print {
    @page { size: A4 landscape; margin: 4mm; }
    html, body { width: 100%; }
    body { font-size: 12pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none !important; }
    .salary-slip, .salary-slip * { box-shadow: none !important; color: #000 !important; }
    /* 📏 «قد ورقة A4 وواضحة» (طلب المستخدم 2026-08-01): البطاقة تتمدّد على كامل طول
       الورقة والجدول يوزّع الفراغ المتبقي على صفوفه فتكبر الصفوف وتتوضّح.
       التصغير المحسوب --pz (من app.js بقياس بشروط الطباعة الحقيقية) يتعوّض بالعرض
       والطول (القسمة على --pz): يظل شكل البطاقة **قدّ الورقة تماماً** — يصغر الخط
       قليلاً فقط عند الضرورة بلا فراغ فاضي ولا فيضان لصفحة ثانية.
       🔴 التصميم مضبوط على هوامش @page 4mm: إذا فرض المستخدم هوامش أكبر بحوار
       الطباعة (مثل 1 إنش) تضيق الورقة فيفيض — بانر _autoprint يرشده لتصحيحها */
    .salary-slip { page-break-inside: avoid; page-break-after: always; padding: 0 !important;
                   width: 100%; zoom: var(--pz, 1); }
    .salary-slip:last-child { page-break-after: auto; }
    /* 🗏 (2026-09-04) صفّ المجموع كان يطلع على صفحة ثانية بالكشوف الممتلئة: مع flex الجدول يفيض
       ~13px عن صندوق البطاقة (min-height) وكروم يرمي الفائض على صفحة جديدة. شبكة grid بصفّ 1fr
       للجدول = نفس التوزيع (الجدول يأخذ باقي الورقة ويوزّعه على صفوفه) بلا أي فيضان — مفحوص
       بPDF كروم: اليانا 2025-2026 (12 شهراً + مجموع) صفحتان → صفحة واحدة */
    .salary-slip { display: grid; grid-template-columns: 100%; grid-template-rows: auto auto auto 1fr; min-height: calc(188mm / var(--pz, 1)); }
    /* صمام: لو ضاقت مساحة الورقة (هوامش مستخدم كبيرة) لا ينقسم صفّ مفرد على صفحتين */
    .salary-slip-table tr { page-break-inside: avoid; }
    /* 📏 (2026-09-20) جدول المبالغ مثبَّت بالصفّ الأخير (1fr) صراحةً: سطر سعر الصرف المضاف 2026-09-19 صار
       العنصر الثالث فأخذ جدولُ معلومات الأستاذ صفَّ 1fr وانتفخت سطوره بالفراغ الفائض بدل سطور المبالغ
       (شكواه «ضيّق أسطر المعلومات ووسّع أسطر المبالغ»). مع grid-row:4 يبقى الجدول آخِذَ باقي الورقة
       سواء وُجد سطر السعر (4 عناصر) أو لا (3 عناصر بوضع الليرة — الصفّ الثالث يبقى فارغاً بارتفاع صفر) */
    .salary-slip-table { align-self: stretch; grid-row: 4; }
    .salary-slip-header { border-bottom: none !important; padding-bottom: 0 !important; margin-bottom: 3px !important; }
    .salary-slip-header .ssh-school h2 { font-size: 15pt !important; }
    .ssh-ar { font-size: 12pt !important; } .ssh-addr { font-size: 12pt !important; }
    .ssh-title h3 { font-size: 12pt !important; } .ssh-sub { font-size: 12pt !important; } .ssh-year { font-size: 12pt !important; }
    /* 🔠 «معلومات الأستاذ فوق أكبر» + «اسم الأستاذ واضح» (طلب المستخدم 2026-08-01):
       الاسم أبرز عنصر بالورقة (17pt أسود عريض بالنص)، والقيم كبار عريضة والتسميات أصغر */
    /* ✍️ (2026-08-25) «P1 بدون تضييق»: خانات المعلومات رجعت لقياسها الأصلي —
       وتوسيع سطور المبالغ بقي (حشوة 5px بالجدول والفراغ يتوزّع بflex)
       📏 (2026-09-20 بطلبه الصريح «ضيّق شوي أسطر المعلومات وهيك منقدر نوسّع أسطر المبالغ بدون ما تتخطّى A4 —
       العواميد اتركها»): الاتجاه العمودي فقط — line-height 1.2 بدل 1.5 الموروث + حشوة أقلّ بسطر الاسم
       وسطر السعر وخانات المعلومات (الملاك 15: خانة المعلومات 56px ⇒ 41px، الكتلة 190 ⇒ ~125px).
       ما يوفَّر فوق يذهب لسطور المبالغ (حشوتها 7px تحت + الجدول يأخذ باقي الورقة) والورقة A4 وحدة.
       الخطوط والألوان والعواميد والترتيب كما هي — لا شيء آخر تغيّر */
    .slip-emp-name { font-size: 12pt !important; background:#eff6ff !important; padding:3px 10px !important; margin-bottom:3px !important; line-height:1.2; }
    .slip-emp-name .slip-pname { font-size: 17pt !important; font-weight: 700 !important; color: #000 !important; }
    .slip-emp-name .slip-school, .slip-emp-name .slip-rep { font-weight: 600 !important; color: #334155 !important; }
    .salary-slip .slip-rate { margin:1px 0 2px !important; line-height:1.2; }
    .slip-info { margin-bottom: 3px !important; }
    .slip-info td { border:1px solid #888 !important; padding: 1px 8px !important; line-height:1.2; }
    .slip-info .lbl { font-size: 10.5pt !important; margin-bottom: 0 !important; color:#555 !important; line-height:1.2; }
    /* بولد حقيقي غامق (طلب المستخدم) — الخط Arial بكل اللغات (2026-09-04)، الوزن 700 = Arial Bold بحروفها العربية متل p1 */
    .slip-info .val { font-size: 13.5pt !important; font-weight:700 !important;
                      font-family:Arial,'Segoe UI',Tahoma,sans-serif !important; color:#000 !important; }
    /* الجدول: خط 12pt بلا قصّ (table-layout تلقائي فالأرقام تظهر كاملة)؛
       التصغير المحسوب --pz يضمن صفحة A4 أفقية واحدة بلا قصّ ولا انقسام */
    .salary-slip-table { font-size: 12pt !important; width: 100% !important; border-collapse: collapse; }
    .salary-slip-table th, .salary-slip-table td { border: 1px solid #888 !important; padding: 5px 2px !important; line-height: 1.3; white-space: nowrap; text-align: center; }
    /* 🎨 بطلب المستخدم: بلا كحلي — رؤوس فاتحة هادئة، وعناوين «المحسومات» وحدها بأحمر فاتح */
    .salary-slip-table thead th { background: #f1f5f9 !important; color: #111 !important; font-size: 11pt !important; white-space: normal; padding: 4px 1px !important; }
    .salary-slip-table thead th.deduction-header { background: #ffe3e3 !important; color: #7f1d1d !important; }
    /* 🔠 «بدي ياهن متل ما كان الخط بالراتب بعد التدرج» (قرار المستخدم النهائي 2026-08-01):
       كل مبالغ الليرة بالجدول بحجم واحد = 12pt عريض (حجم «بعد التدرج» الأصلي) — موحّدة */
    .salary-slip-table .sub-lbp { white-space: nowrap; font-size: 12pt !important; font-weight: 700 !important; }
    .salary-slip-table .num-lbp, .salary-slip-table .num-lbp strong { font-size: 12pt !important; font-weight: 700 !important; white-space: nowrap; }
    /* 🔠 «مبالغ الدولار صغيرة كتير» (طلب المستخدم 2026-08-01): الدولار 11pt عريض واضح —
       يُقرأ بسهولة ومتناسق مع الليرة (14pt) والليرة تبقى الرئيسية */
    /* ✍️ (2026-08-25) «بدون ألوان بخطوط المبالغ»: كل الأرقام سوداء بالطباعة — لا أخضر */
    .salary-slip-table .cur-usd { white-space: nowrap; color: #000 !important; font-size: 11pt !important; font-weight: 700 !important; }
    /* ✍️ (2026-08-25) سطور المبالغ تتنفّس أكثر (بدل 4px) — والباقي يوزَّع عليها بflex
       📏 (2026-09-20) 7px بدل 5px «وسّع أسطر المبالغ»: ما وفّرته سطور المعلومات (~72px) يذهب لسطور المبالغ
       نفسها (14 سطراً × 4px) لا لتكبير --pz — لأنّ تكبير البطاقة كلّها كان يضيّق الجدول على رؤوسه فتنكسر
       عناوين الأعمدة الفرنسية لسطرين (Valeur/échelon، Prime &/aide…) عند الملاك، وهو قال «العواميد اتركها».
       مقيس: الملاك 15 سقف الرؤوس 0.719 و--pz صار 0.700 (كان 0.689)، الملاك 47 سقفه 0.704 و--pz 0.679 */
    .salary-slip-table td { padding: 7px 3px !important; }
    .salary-slip-table .row-month { white-space: nowrap; }
    /* ✍️ (2026-08-25) «P1 بدون لون»: صفّ المجموع بلا خلفية صفراء — أبيض عريض فقط */
    .total-row td { background: #fff !important; font-weight: bold; }
}
.salary-slip + .salary-slip { margin-top: 24px; border-top: 3px dashed var(--gray-300); padding-top: 24px; }
</style>
<?php return ob_get_clean(); } echo annualSlipStyleHtml(); /* 🗂️ CSS البطاقة كدالة تُشاركها صفحة التاريخ الكامل */ ?>
<?php if (!empty($_GET['_fit'])): /* وضع ملء الصفحة: كل الأعمدة + الدولار. الترويسة/المعلومات مكبّرة (لا تؤثّر على عرض الجدول) */ ?>
<style>
@media print {
    .salary-slip { width: 1245px !important; margin: 0 auto !important; }
    .salary-slip-table th, .salary-slip-table td { padding: 8px 1px !important; }
    /* تكبير سطر الترويسة وخانات المعلومات (أوسع من الجدول فلا تصغّره) */
    .slip-emp-name { font-size: 19px !important; padding: 7px !important; margin-bottom: 8px !important; }
    .slip-emp-name .slip-pname { font-size: 1.18em !important; }
    .slip-info .lbl { font-size: 12pt !important; } .slip-info .val { font-size: 12pt !important; }
    .slip-info td { padding: 4px 9px !important; }
}
</style>
<?php endif; ?>

<div class="card no-print">
    <div class="card-header">
        <h3>
            <span dir="ltr"><i class="fas fa-file-invoice-dollar"></i> Sélection</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">اختيار</div>
        </h3>
    </div>
    <div class="card-body">
        <form method="GET" class="form-row cols-4">
            <div class="form-group mb-0">
                <label class="form-label">Employé / موظف واحد</label>
                <select name="employee_id" class="form-select">
                    <option value="">-- (laisser vide pour impression groupée / اتركه فارغاً للطباعة الجماعية) --</option>
                    <?php
                    $emps = getYearEmployees($db, $schoolYear, $typeState);
                    foreach ($emps as $e):
                        $nm = trim($e['first_name_fr'] . ' ' . $e['last_name_fr']);
                        if ($nm === '') $nm = trim($e['first_name_ar'] . ' ' . $e['last_name_ar']);
                    ?>
                        <option value="<?= $e['id'] ?>" <?= $employeeId === (int)$e['id'] ? 'selected' : '' ?> data-phone="<?= e(trim(($e['phone1'] ?? '').' '.($e['phone2'] ?? ''))) ?>" data-search="<?= e(trim($e['first_name_ar'].' '.$e['last_name_ar'])) ?>">
                            <?= e($nm) ?> (<?= employeeTypeLabel($e['employee_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= empTypeCheckboxes($typeState, true, 'type') /* ☑️ (2026-09-25) خانات تشييك: المشيّكة فقط تبيّن */ ?>
            <div class="form-group mb-0">
                <label class="form-label">Année scolaire / السنة الدراسية</label>
                <select name="school_year" class="form-select" onchange="this.form.submit()">
                    <?php
                    $cyNow = (int)date('Y'); $cmNow = (int)date('n');
                    $startNow = ($cmNow >= 10) ? $cyNow : $cyNow - 1;
                    for ($yy = $startNow + 1; $yy >= 2006; $yy--):
                        $sy = $yy . '-' . ($yy + 1);
                    ?>
                        <option value="<?= $sy ?>" <?= $sy === $schoolYear ? 'selected' : '' ?>><?= $sy ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button>
            </div>
        </form>

        <!-- أزرار الكل: احتساب وطباعة جماعية للسنة المختارة حسب الفلتر -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:14px;border-top:1px solid var(--gray-200);padding-top:14px">
            <span style="font-weight:600;color:var(--gray-700)"><i class="fas fa-users"></i> Toute l'école / كل المدرسة (<?= e($typeFilter ? employeeTypeLabel($typeFilter) : ($typeState['all'] ? 'Tous / الكل' : empTypeTitleFrom($typeState))) ?>) année / للسنة <?= e($schoolYear) ?>:</span>
            <a href="?action=calc_all_year&school_year=<?= e($schoolYear) ?><?= $typeQ ?>" class="btn btn-gold"
               onclick="return confirm('احتساب رواتب كل الموظفين المعروضين لكامل السنة الدراسية <?= e($schoolYear) ?>؟ قد تأخذ وقتاً.')">
                <i class="fas fa-calculator"></i> Calculer toute l'année / احتساب الكل للسنة
            </a>
            <a href="?action=print_all&school_year=<?= e($schoolYear) ?><?= $typeQ ?>" class="btn btn-primary">
                <i class="fas fa-print"></i> Afficher/imprimer tous les relevés / عرض/طباعة كل الكشوف
            </a>
        </div>
    </div>
</div>

<?php
if (!empty($_SESSION['flash_success'])) { echo '<div class="alert alert-success no-print">' . e($_SESSION['flash_success']) . '</div>'; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_error'])) { echo '<div class="alert alert-danger no-print">' . e($_SESSION['flash_error']) . '</div>'; unset($_SESSION['flash_error']); }
?>

<?php if ($action === 'print_all'):
    // طباعة/عرض جماعي: كشف كل موظف للسنة المختارة (حسب الفلتر)
    $empsP = getYearEmployees($db, $schoolYear, $typeState);
    $typeLbl = $typeFilter ? employeeTypeLabel($typeFilter) : ($typeState['all'] ? 'الكل / Tous' : empTypeTitleFrom($typeState));
?>
    <div class="d-flex justify-between align-center mb-3 no-print">
        <a href="<?= BASE_URL ?>pages/annual_slip.php?school_year=<?= e($schoolYear) ?><?= $typeQ ?>" class="btn btn-light">
            <i class="fas fa-arrow-left"></i> رجوع / Retour
        </a>
        <div>
            <span class="badge badge-info"><?= count($empsP) ?> — <?= e($typeLbl) ?> — <?= e($schoolYear) ?></span>
            <?php
                $expAllQ = 'all=1' . $typeQ . '&school_year=' . urlencode($schoolYear);
                // «PDF رسمي (الكل)» = طبق الأصل عبر Chrome (صفحة لكل أستاذ، نفس تصميم الشاشة)
                $allTarget = rawurlencode('pages/annual_slip.php?action=print_all' . $typeQ . '&school_year=' . $schoolYear . $blankQ); // ☑️ الفئات المشيّكة (+ فاضية)
            ?>
            <?php /* 📏 بلا fit=1: المسار العادي صار يملأ الورقة كاملة (وضع fit القديم كان يطبع أصغر — ملاحظة المستخدم p1) */ ?>
            <a href="<?= BASE_URL ?>pages/print_pdf.php?target=<?= $allTarget ?>&name=releves_<?= e($typeFilter ?: 'tous') ?>" class="btn btn-danger" target="_blank"><i class="fas fa-file-pdf"></i> PDF officiel (tous) / PDF رسمي (الكل)</a>
            <a href="<?= BASE_URL ?>pages/annual_slip_export.php?<?= $expAllQ ?>&format=xlsx" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</a>
            <button type="button" onclick="window.print()" class="btn btn-light"><i class="fas fa-print"></i> Imprimer (navigateur) / طباعة المتصفّح</button>
            <?php if ($slipBlank): ?><a href="?action=print_all&school_year=<?= e($schoolYear) ?><?= $typeQ ?>" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> بالمبالغ / avec montants</a><?php endif; ?>
            <?php if ($slipBlank !== 1): ?><a href="?action=print_all&school_year=<?= e($schoolYear) ?><?= $typeQ ?>&blank=1" class="btn btn-warning" title="كل البطاقات بالأسماء بلا مبالغ — للتعبئة باليد"><i class="fas fa-file-lines"></i> Vierges (montants) / فاضية من المبالغ</a><?php endif; ?>
            <?php if ($slipBlank !== 2): ?><a href="?action=print_all&school_year=<?= e($schoolYear) ?><?= $typeQ ?>&blank=2" class="btn btn-warning" title="نماذج فارغة بلا اسم ولا مبالغ — نسخة لكل أستاذ مختار"><i class="fas fa-file"></i> Formulaires vierges / نماذج فارغة بلا اسم</a><?php endif; ?>
        </div>
    </div>
    <?php if ($slipBlank): ?><div class="alert alert-warning no-print" style="margin-bottom:12px">📝 بطاقات <?= $slipBlank === 2 ? 'نموذج فارغ' : 'بلا مبالغ' ?> — للتعبئة باليد. الحساب لم يُمَسّ.</div><?php endif; ?>
    <div class="alert alert-info no-print" style="margin-bottom:16px">
        <i class="fas fa-info-circle"></i> راجِع كشوف كل الموظفين تحت، وعند التأكد اضغط «طباعة الكل». كل كشف يُطبع بصفحة مستقلة.
        <strong>ملاحظة:</strong> تظهر الأرقام فقط للأشهر المُحتسَبة — إن كانت فارغة استعمل «احتساب الكل للسنة» أولاً.
    </div>
    <?php if (!$empsP): ?>
        <div class="alert alert-warning">Aucun employé correspondant au filtre cette année / لا يوجد موظفون مطابقون للفلتر في هذه السنة.</div>
    <?php else: foreach ($empsP as $empP) { echo annualSlipHtml($db, $empP, $schoolYear); } endif; ?>

<?php elseif ($employeeId > 0):
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) { echo "<div class='alert alert-danger'>Employé introuvable dans cette école</div>"; include __DIR__ . '/../includes/footer.php'; exit; }
?>
    <div class="card no-print" style="margin-bottom:14px">
        <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
            <a href="?action=calc_year&employee_id=<?= $employeeId ?>&school_year=<?= e($schoolYear) ?>" class="btn btn-gold"
               onclick="return confirm('احسب رواتب كل أشهر السنة لهذا الأستاذ؟')">
                <i class="fas fa-calculator"></i> احسب كل الأشهر / Toute l'année
            </a>
            <span style="color:var(--gray-400)">|</span>
            <form method="GET" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0">
                <input type="hidden" name="action" value="calc_range">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                <input type="hidden" name="school_year" value="<?= e($schoolYear) ?>">
                <div class="form-group mb-0"><label class="form-label" style="font-size:12px">من شهر / De</label>
                    <select name="from_m" class="form-select"><?php for($i=1;$i<=12;$i++) echo "<option value='$i'>".monthName($i,'fr',true)."</option>"; ?></select>
                </div>
                <div class="form-group mb-0"><label class="form-label" style="font-size:12px">Année / سنة</label>
                    <input type="number" name="from_y" class="form-control" value="<?= $y1 ?>" style="width:90px"></div>
                <div class="form-group mb-0"><label class="form-label" style="font-size:12px">إلى شهر / À</label>
                    <select name="to_m" class="form-select"><?php for($i=1;$i<=12;$i++) echo "<option value='$i'>".monthName($i,'fr',true)."</option>"; ?></select>
                </div>
                <div class="form-group mb-0"><label class="form-label" style="font-size:12px">Année / سنة</label>
                    <input type="number" name="to_y" class="form-control" value="<?= $y2 ?>" style="width:90px"></div>
                <button class="btn btn-secondary"><i class="fas fa-calculator"></i> احسب الفترة / Période</button>
            </form>
            <span style="flex:1"></span>
            <?php
                $expQ = 'employee_id=' . $employeeId . '&school_year=' . urlencode($schoolYear);
                // «PDF رسمي» = طبق الأصل عن الشاشة عبر Chrome (نفس تصميم الكشف بالضبط، بلا قصّ)
                $slipTarget = rawurlencode('pages/annual_slip.php?employee_id=' . $employeeId . '&school_year=' . $schoolYear . $blankQ);
            ?>
            <?php /* 📏 بلا fit=1: المسار العادي صار يملأ الورقة كاملة (وضع fit القديم كان يطبع أصغر — ملاحظة المستخدم p1) */ ?>
            <a href="<?= BASE_URL ?>pages/print_pdf.php?target=<?= $slipTarget ?>&name=releve_<?= $employeeId ?>" class="btn btn-danger" target="_blank"><i class="fas fa-file-pdf"></i> PDF officiel / PDF رسمي</a>
            <a href="<?= BASE_URL ?>pages/annual_slip_export.php?<?= $expQ ?>&format=xlsx" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</a>
            <button onclick="window.print()" class="btn btn-light"><i class="fas fa-print"></i> Imprimer (navigateur) / طباعة المتصفّح</button>
            <?php if ($slipBlank): ?><a href="?<?= $expQ ?>" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> بالمبالغ / avec montants</a>
            <?php if ($slipBlank === 1): ?><a href="?<?= $expQ ?>&blank=2" class="btn btn-warning" title="نموذج فارغ بلا اسم ولا مبالغ — لأي أستاذ"><i class="fas fa-file"></i> Formulaire vierge / نموذج فارغ بلا اسم</a><?php else: ?><a href="?<?= $expQ ?>&blank=1" class="btn btn-warning"><i class="fas fa-file-lines"></i> Vierge (montants) / فاضية من المبالغ</a><?php endif; ?>
            <?php else: ?>
            <a href="?<?= $expQ ?>&blank=1" class="btn btn-warning" title="بطاقة هذا الأستاذ بلا مبالغ — للتعبئة باليد"><i class="fas fa-file-lines"></i> Vierge (montants) / فاضية من المبالغ</a>
            <a href="?<?= $expQ ?>&blank=2" class="btn btn-warning" title="نموذج فارغ بلا اسم ولا مبالغ — لأي أستاذ"><i class="fas fa-file"></i> Formulaire vierge / نموذج فارغ لأي أستاذ</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($slipBlank): ?><div class="alert alert-warning no-print" style="margin-bottom:12px">📝 <?= $slipBlank === 2 ? 'نموذج فارغ لأي أستاذ (بلا اسم ولا مبالغ)' : 'بطاقة هذا الأستاذ بلا مبالغ' ?> — للتعبئة باليد. الحساب والبطاقة العادية لم يُمَسّا.</div><?php endif; ?>

    <?php echo annualSlipHtml($db, $emp, $schoolYear); ?>

<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-file-invoice-dollar"></i>
        <h4>
            <span dir="ltr">Choisissez un employé, ou « Afficher/imprimer tous les relevés »</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">اختر موظفاً واحداً، أو استعمل «عرض/طباعة كل الكشوف»</div>
        </h4>
        <p>Choisissez l'année scolaire et le type, puis un employé pour l'aperçu individuel, ou imprimez tout en une fois / اختر السنة الدراسية والفئة، ثم موظفاً للعرض الفردي أو اطبع الكل دفعة واحدة</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
