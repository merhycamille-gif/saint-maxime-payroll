<?php
/**
 * 🏛️ قوانين الدولة (2026-08-26 — بطلب المستخدم): «لازم تحط بأساس البرنامج الضمان وضريبة
 * الدخل وصندوق التعويضات كل واحد مستقل لحالو ونكتب نحنا ساعتها التقارير والمذكرات
 * والقوانين اللي بتطلع من الدولة — والنسب المئوية والحدود القصوى والحدود الادنى».
 * ثلاثة أركان مستقلة، كلٌّ يعرض نسبه المئوية وحدوده (الأقصى والأدنى) وشطوره المؤرّخة
 * (من تاريخ إلى تاريخ)، مع أزرار الإضافة/التعديل التي تفتح محرّر كل جدول (وكل تعديل
 * هناك يعيد حساب الرواتب المتأثرة تلقائياً). القراءة للجميع والتعديل بصلاحية المدير.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$currentPage = 'state_laws';
$pageTitle = 'Lois de l\'État / قوانين الدولة — الضمان والضريبة والصندوق';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';
// تركيب ذاتي: عمود الحد الأدنى المؤرّخ (نفسه في صفحة الحدود — أيّ المدخلين فُتح أولاً يركّبه)
try { $db->query("SELECT min_salary_lbp FROM cnss_brackets LIMIT 1"); }
catch (Exception $e) { try { $db->exec("ALTER TABLE cnss_brackets ADD COLUMN min_salary_lbp BIGINT NULL COMMENT 'الحد الأدنى للأجر الخاضع (فارغ = بلا حد أدنى)' AFTER max_salary_lbp"); } catch (Exception $e2) {} }

// النِّسَب المؤرّخة مجمّعة بالمفتاح
$ratesByKey = [];
try {
    foreach ($db->query("SELECT param_key, value, effective_from, effective_to, notes FROM rate_history ORDER BY param_key, effective_from DESC")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $ratesByKey[$r['param_key']][] = $r;
} catch (Exception $e) {}
// الحدود المؤرّخة مجمّعة بالفرع
$limitsByBranch = [];
try {
    foreach ($db->query("SELECT * FROM cnss_brackets ORDER BY branch, effective_from DESC")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $limitsByBranch[$r['branch']][] = $r;
} catch (Exception $e) {}
// الشطور الضريبية مجمّعة بفترتها
$taxSets = [];
try {
    foreach ($db->query("SELECT * FROM tax_brackets ORDER BY effective_from DESC, bracket_number")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $taxSets[$r['effective_from'] . '|' . ($r['effective_to'] ?: '')][] = $r;
} catch (Exception $e) {}

$fmtPct = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.') . '%';
$fmtPeriod = fn($f, $t) => formatDate($f) . ' ← ' . ($t ? formatDate($t) : '<span class="badge badge-success">حتى الآن / En cours</span>');

// جدول نِسَب لركن
function slRates(array $ratesByKey, array $keys) {
    global $fmtPct, $fmtPeriod;
    echo '<table class="table"><thead><tr><th>المعامل / Paramètre</th><th>القيمة / Valeur</th><th>سارية / Période</th><th>ملاحظة (المذكرة/القانون)</th></tr></thead><tbody>';
    $any = false;
    foreach ($keys as $k) {
        foreach ($ratesByKey[$k] ?? [] as $i => $r) {
            $any = true;
            $isLbp = (ratedParams()[$k]['unit'] ?? '') === 'lbp';
            echo '<tr' . ($i === 0 ? ' style="font-weight:700"' : ' style="opacity:.75"') . '>'
               . '<td>' . ($i === 0 ? e(ratedParamLabel($k, 'ar')) . '<br><small dir="ltr">' . e(ratedParamLabel($k, 'fr')) . '</small>' : '<small style="color:var(--gray-500)">↳ سابقاً</small>') . '</td>'
               . '<td class="num">' . ($isLbp ? formatLBP($r['value']) : $fmtPct($r['value'])) . '</td>'
               . '<td>' . $fmtPeriod($r['effective_from'], $r['effective_to']) . '</td>'
               . '<td><small>' . e($r['notes'] ?? '') . '</small></td></tr>';
        }
    }
    if (!$any) echo '<tr><td colspan="4" class="text-muted">لا نِسَب مدخلة بعد</td></tr>';
    echo '</tbody></table>';
}
// جدول حدود لفروع
function slLimits(array $limitsByBranch, array $branchKeys) {
    global $fmtPeriod;
    $names = cnssBranches();
    echo '<table class="table"><thead><tr><th>الفرع / Branche</th><th>الحد الأقصى / Plafond</th><th>الحد الأدنى / Minimum</th><th>سارية / Période</th><th>ملاحظة (المذكرة/القانون)</th></tr></thead><tbody>';
    $any = false;
    foreach ($branchKeys as $bk) {
        foreach ($limitsByBranch[$bk] ?? [] as $i => $r) {
            $any = true;
            echo '<tr' . ($i === 0 ? ' style="font-weight:700"' : ' style="opacity:.75"') . '>'
               . '<td>' . ($i === 0 ? e($names[$bk]['ar'] ?? $bk) : '<small style="color:var(--gray-500)">↳ سابقاً</small>') . '</td>'
               . '<td class="num">' . ($r['max_salary_lbp'] !== null ? formatLBP($r['max_salary_lbp']) : '<span class="text-muted">بلا سقف</span>') . '</td>'
               . '<td class="num">' . (($r['min_salary_lbp'] ?? null) !== null ? formatLBP($r['min_salary_lbp']) : '<span class="text-muted">—</span>') . '</td>'
               . '<td>' . $fmtPeriod($r['effective_from'], $r['effective_to']) . '</td>'
               . '<td><small>' . e($r['notes'] ?? '') . '</small></td></tr>';
        }
        if (empty($limitsByBranch[$bk])) {
            echo '<tr><td>' . e($names[$bk]['ar'] ?? $bk) . '</td><td colspan="4" class="text-muted">لا حدود مدخلة (بلا سقف/بلا حد أدنى)</td></tr>';
            $any = true;
        }
    }
    if (!$any) echo '<tr><td colspan="5" class="text-muted">لا حدود</td></tr>';
    echo '</tbody></table>';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-scale-balanced"></i>
    <?= $lang === 'ar'
        ? 'هذه صفحة قوانين الدولة: ثلاثة أركان مستقلة — الضمان الاجتماعي، صندوق التعويضات، وضريبة الدخل. كل نسبة مئوية وكل حدّ أقصى وأدنى وكل شطر مؤرَّخ «من تاريخ إلى تاريخ»؛ وعند صدور مذكرة أو قانون جديد اكبس زرّ التعديل بالركن المعني وأضف صفاً جديداً بتاريخه — البرنامج يطبّقه تلقائياً على الأشهر التي يسري عليها ويعيد حساب الرواتب المتأثرة.'
        : 'Lois de l\'État en trois piliers indépendants — CNSS, Caisse d\'indemnités, Impôt sur le revenu. Chaque taux, plafond, minimum et tranche est daté (du/au) ; ajoutez chaque nouvelle circulaire comme ligne datée via les boutons d\'édition.' ?>
</div>

<?php /* ========== ١) الضمان الاجتماعي ========== */ ?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-shield-alt"></i> 1) Sécurité sociale (CNSS)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">١) الضمان الاجتماعي — مستقل لحاله</div>
    </h3></div>
    <div class="card-body">
        <h4 style="color:var(--primary)">النسب المئوية / Taux</h4>
        <?php slRates($ratesByKey, ['cnss_employee_rate', 'cnss_employer_rate', 'family_compensation_rate', 'end_of_service_rate', 'minimum_wage_lbp']); ?>
        <h4 style="color:var(--primary);margin-top:14px">الحدود القصوى والدنيا لكل فرع / Plafonds &amp; minimums</h4>
        <?php slLimits($limitsByBranch, ['maladie_maternite', 'allocations_familiales', 'fin_de_service']); ?>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>pages/rates_history.php"><i class="fas fa-percent"></i> إضافة/تعديل النسب / Taux</a>
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>pages/social_security.php"><i class="fas fa-sliders"></i> إضافة/تعديل الحدود / Limites</a>
        </div>
    </div>
</div>

<?php /* ========== ٢) صندوق التعويضات ========== */ ?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-building-columns"></i> 2) Caisse d'indemnités</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">٢) صندوق التعويضات (الهيئة التعليمية) — مستقل لحاله</div>
    </h3></div>
    <div class="card-body">
        <h4 style="color:var(--primary)">النسب المئوية / Taux</h4>
        <?php slRates($ratesByKey, ['eoc_employee_rate', 'eoc_employer_rate']); ?>
        <h4 style="color:var(--primary);margin-top:14px">الحدود القصوى والدنيا / Plafonds &amp; minimums</h4>
        <?php slLimits($limitsByBranch, ['eoc']); ?>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>pages/rates_history.php"><i class="fas fa-percent"></i> إضافة/تعديل النسب / Taux</a>
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>pages/social_security.php"><i class="fas fa-sliders"></i> إضافة/تعديل الحدود / Limites</a>
        </div>
    </div>
</div>

<?php /* ========== ٣) ضريبة الدخل ========== */ ?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-file-invoice-dollar"></i> 3) Impôt sur le revenu</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">٣) ضريبة الدخل (الباب الثاني — الرواتب والأجور) — مستقلة لحالها</div>
    </h3></div>
    <div class="card-body">
        <h4 style="color:var(--primary)">الشطور المؤرّخة / Tranches datées</h4>
        <?php if (!$taxSets): ?>
            <p class="text-muted">لا شطور مدخلة بعد</p>
        <?php else: $first = true; foreach ($taxSets as $period => $set): [$pf, $pt] = explode('|', $period); ?>
            <div style="margin-bottom:10px;<?= $first ? '' : 'opacity:.75' ?>">
                <div style="font-weight:700;margin-bottom:4px"><?= $first ? '📌 السارية: ' : '↳ سابقاً: ' ?><?= $fmtPeriod($pf, $pt) ?></div>
                <table class="table" style="max-width:720px"><thead><tr><th>#</th><th>الشطر السنوي / Tranche annuelle</th><th>النسبة / Taux</th></tr></thead><tbody>
                <?php foreach ($set as $b): ?>
                    <tr><td><?= (int)$b['bracket_number'] ?></td>
                        <td class="num"><?= formatLBP($b['annual_from']) ?> ← <?= $b['annual_to'] !== null ? formatLBP($b['annual_to']) : 'وما فوق / et plus' ?></td>
                        <td><?= $fmtPct($b['rate_percent']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php $first = false; endforeach; endif; ?>
        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>pages/tax_brackets.php"><i class="fas fa-layer-group"></i> إضافة/تعديل الشطور / Tranches</a>
        </div>
        <div class="alert alert-info" style="margin-top:10px;font-size:13px">
            ℹ️ التنزيلات العائلية (الشخصي/الزوج/الأولاد) قيمها القانونية مطبّقة تلقائياً بالمعادلة حسب تاريخ كل شهر،
            وقرارات كل موظف (نعم/كلا) من صفحة «اقتراحات إخراج القيد» وملف الموظف.
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
