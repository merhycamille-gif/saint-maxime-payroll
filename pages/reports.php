<?php
/**
 * Rapports / التقارير — متعدد المدارس
 * اختيار: مدرسة واحدة / عدة مدارس / الكل
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
requireLogin();

$currentPage = 'reports';
$pageTitle = 'Rapports';
$db = getDB();

$report = $_GET['report'] ?? '';
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$schoolYear = $_GET['school_year'] ?? activeSchoolYear();
if ($schoolYear === 'all') $schoolYear = currentSchoolYear(); // تقارير السنة تحتاج سنة محددة

// فلتر المدارس (آمن — أرقام)
$schoolSql = reportSchoolSql('ms.school_id');     // للجداول المرتبطة برواتب (alias ms)
$schoolSqlEmp = reportSchoolSql('e.school_id');   // لجداول الموظفين (alias e)
$multi = reportIsMultiSchool();

// فلتر «موظفي السنة المعروضة» الموحّد — نفس مصدر بيان صندوق التعويضات (yearEmploymentFilter):
// يستبعد المحذوفين والتاركين (أي تاريخ ترك) وصفوف الأشباح، فلا يُحتسب موظفون سابقون تركها
// الاستيراد على جدول الرواتب. السنة الدراسية مشتقّة من الشهر/السنة (تشرين الأول = سنة جديدة).
$periodSchoolYear = ($month >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
[$empYearFilter, $empYearParams] = yearEmploymentFilter($periodSchoolYear, 'e.');
// النسخة السنوية من الفلتر (للتقارير المبنية على السنة الدراسية المختارة $schoolYear)
[$annualEmpFilter, $annualEmpParams] = yearEmploymentFilter($schoolYear, 'e.');
$selectedNames = (function() {
    $ids = selectedReportSchoolIds();
    if (empty($ids)) return 'Toutes les écoles / كل المدارس';
    return implode(' + ', array_map('schoolNameById', $ids));
})();

// تصدير Excel/Word حقيقي منسّق عبر الخادم (reports_export.php) لهذه التقارير
$exportableReports = ['monthly_summary','cnss_summary','tax_summary','eoc_summary','employee_list','annual_totals'];
if ($report && in_array($report, $exportableReports, true)) {
    $qs = http_build_query(array_filter([
        'report' => $report, 'month' => $month, 'year' => $year,
        'school_year' => $schoolYear, 'emp_type' => $_GET['emp_type'] ?? null,
    ], fn($v) => $v !== null && $v !== ''));
    $colsQ = '';
    if (!empty($_GET['cols']) && is_array($_GET['cols'])) foreach ($_GET['cols'] as $c) $colsQ .= '&cols[]=' . urlencode($c);
    $exportOpts['server'] = BASE_URL . 'pages/reports_export.php?' . $qs . $colsQ;
    $exportTitle = 'تقرير';
}

include __DIR__ . '/../includes/header.php';

/**
 * منتقي المدارس (للمدير العام فقط) — يوضع داخل كل نموذج تقرير
 */
function reportSchoolPicker() {
    if (!isSuperAdmin()) return;
    $schools = allSchools(false);
    $selected = selectedReportSchoolIds(); // فارغة = الكل
    ?>
    <div class="form-group mb-0" style="grid-column:1 / -1">
        <label class="form-label"><i class="fas fa-school"></i> Écoles / المدارس</label>
        <div class="school-checks">
            <label class="chk all"><input type="checkbox" value="all" onclick="toggleAllSchools(this)" <?= empty($selected) ? 'checked' : '' ?>> <strong>Toutes / الكل</strong></label>
            <?php foreach ($schools as $s): ?>
            <label class="chk"><input type="checkbox" name="schools[]" value="<?= (int)$s['id'] ?>" onclick="onSchoolCheck()" <?= in_array((int)$s['id'], $selected) ? 'checked' : '' ?>> <?= e($s['name_fr']) ?></label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
?>

<?php if (!$report):
    // ====== مركز التقارير المنظّم بأقسام ======
    $OF = BASE_URL . 'pages/official_forms.php?form=';   // النماذج الرسمية
    $PG = BASE_URL . 'pages/';                            // صفحات أخرى
    $categories = [
        ['title'=>'كشوف الرواتب / Bulletins de salaire', 'icon'=>'fa-money-check-alt', 'color'=>'var(--primary)', 'tiles'=>[
            ['url'=>$PG.'annual_slip.php', 'icon'=>'fa-file-invoice-dollar', 'color'=>'var(--primary)', 'fr'=>'Bulletin annuel', 'ar'=>'بطاقة راتب الأستاذ'],
            ['url'=>'?report=monthly_summary', 'icon'=>'fa-calendar-check', 'color'=>'var(--info)', 'fr'=>'Résumé mensuel', 'ar'=>'كشف رواتب شهري'],
            ['url'=>$OF.'salary_all', 'icon'=>'fa-table-list', 'color'=>'var(--success)', 'fr'=>'État des salaires', 'ar'=>'كشف رواتب كل الموظفين'],
            ['url'=>$OF.'salary_detail', 'icon'=>'fa-rectangle-list', 'color'=>'var(--primary)', 'fr'=>'Détail des salaires (par catégorie)', 'ar'=>'معلومات تفصيلية عن الراتب (جميع الأساتذة/الموظفون)'],
            ['url'=>'?report=annual_totals', 'icon'=>'fa-chart-pie', 'color'=>'var(--warning)', 'fr'=>'Totaux annuels', 'ar'=>'مجاميع سنوية'],
            ['url'=>$OF.'payment_list', 'icon'=>'fa-money-bill-transfer', 'color'=>'var(--success)', 'fr'=>'Liste de paiement', 'ar'=>'كشف الدفع'],
            ['url'=>$OF.'employer_cost', 'icon'=>'fa-sack-dollar', 'color'=>'var(--danger)', 'fr'=>'Coût employeur total', 'ar'=>'كلفة المؤسسة الإجمالية'],
            ['url'=>$OF.'full_register', 'icon'=>'fa-table-cells-large', 'color'=>'var(--primary)', 'fr'=>'Registre complet', 'ar'=>'كشف شامل بالكلفة (جميع الأساتذة)'],
        ]],
        ['title'=>'الضمان الاجتماعي / CNSS', 'icon'=>'fa-hospital', 'color'=>'var(--info)', 'tiles'=>[
            ['url'=>'?report=cnss_summary', 'icon'=>'fa-list', 'color'=>'var(--info)', 'fr'=>'CNSS mensuel', 'ar'=>'كشف ضمان شهري'],
            ['url'=>$OF.'cnss_nominative_monthly', 'icon'=>'fa-table-list', 'color'=>'var(--success)', 'fr'=>'Bordereau nominatif mensuel', 'ar'=>'كشف اشتراكات الضمان الشهري (اسمي)'],
            ['url'=>$OF.'cnss_annual', 'icon'=>'fa-file-contract', 'color'=>'var(--primary)', 'fr'=>'Décl. annuelle nominative', 'ar'=>'التصريح الاسمي السنوي'],
            ['url'=>$OF.'cnss_employ', 'icon'=>'fa-user-plus', 'color'=>'var(--success)', 'fr'=>'Emploi d\'un salarié', 'ar'=>'إعلام عن استخدام أجير'],
            ['url'=>$OF.'cnss_terminate', 'icon'=>'fa-user-minus', 'color'=>'var(--danger)', 'fr'=>'Cessation d\'emploi', 'ar'=>'إعلام عن ترك أجير'],
            ['url'=>$OF.'cnss_employ2', 'icon'=>'fa-user-tag', 'color'=>'var(--success)', 'fr'=>'Décl. emploi + ayants droit', 'ar'=>'تصريح استخدام أجير + العيال'],
            ['url'=>$OF.'cnss_work', 'icon'=>'fa-notes-medical', 'color'=>'var(--info)', 'fr'=>'Attestation travail', 'ar'=>'إفادة عمل لمن يهمه الأمر'],
            ['url'=>$OF.'cnss_work_detail', 'icon'=>'fa-file-medical', 'color'=>'var(--info)', 'fr'=>'Attestation maladie (détail)', 'ar'=>'إفادة عمل — مرض (مفصّلة)'],
            ['url'=>$OF.'cnss_wife', 'icon'=>'fa-person-dress', 'color'=>'var(--gold)', 'fr'=>'Déclaration épouse', 'ar'=>'تصريح عن الزوجة'],
            ['url'=>$OF.'cnss_parent', 'icon'=>'fa-people-roof', 'color'=>'var(--warning)', 'fr'=>'Enquête sociale (parent)', 'ar'=>'تحقيق اجتماعي للوالد'],
            ['url'=>$OF.'cnss_eos_invite', 'icon'=>'fa-hand-holding-dollar', 'color'=>'var(--primary)', 'fr'=>'Fin de service (salaires)', 'ar'=>'نهاية الخدمة — تحديد الأجور'],
            ['url'=>$OF.'cnss_eos_wage', 'icon'=>'fa-money-bill-wave', 'color'=>'var(--danger)', 'fr'=>'Fin de service (dernier salaire)', 'ar'=>'نهاية الخدمة — الأجر الأخير'],
            ['url'=>$OF.'cnss_eos_settle', 'icon'=>'fa-file-invoice', 'color'=>'var(--gold)', 'fr'=>'Liquidation FDS (employé)', 'ar'=>'طلب تصفية نهاية الخدمة (موظف)'],
            ['url'=>$OF.'cnss_contrib_monthly', 'icon'=>'fa-table', 'color'=>'var(--info)', 'fr'=>'Déclaration mensuelle', 'ar'=>'تصريح الضمان الشهري'],
            ['url'=>$OF.'cnss_contrib_annual', 'icon'=>'fa-table-cells', 'color'=>'var(--primary)', 'fr'=>'Déclaration trimestrielle', 'ar'=>'تصريح الضمان الفصلي'],
        ]],
        ['title'=>'ضريبة الدخل / Impôt sur le revenu', 'icon'=>'fa-file-invoice-dollar', 'color'=>'var(--danger)', 'tiles'=>[
            ['url'=>'?report=tax_summary', 'icon'=>'fa-list', 'color'=>'var(--danger)', 'fr'=>'Impôt mensuel', 'ar'=>'كشف ضريبة شهري'],
            ['url'=>$OF.'tax_register', 'icon'=>'fa-id-card-clip', 'color'=>'var(--gold)', 'fr'=>'R3 — Enregistrement', 'ar'=>'ر3 تسجيل أجير'],
            ['url'=>$OF.'tax_r4', 'icon'=>'fa-address-book', 'color'=>'var(--info)', 'fr'=>'R4 — Infos + ayants droit', 'ar'=>'ر4 معلومات + العيال'],
            ['url'=>$OF.'tax_r5', 'icon'=>'fa-file-lines', 'color'=>'var(--primary)', 'fr'=>'R5 — Décl. annuelle', 'ar'=>'ر5 تصريح سنوي'],
            ['url'=>$OF.'tax_r6', 'icon'=>'fa-file-lines', 'color'=>'var(--info)', 'fr'=>'R6 — Kachf individuel', 'ar'=>'ر6 كشف سنوي إفرادي'],
            ['url'=>$OF.'tax_r6t', 'icon'=>'fa-file-pen', 'color'=>'var(--warning)', 'fr'=>'R6/T — Rectificatif', 'ar'=>'ر6/ت تعديل'],
            ['url'=>$OF.'tax_r7', 'icon'=>'fa-user-xmark', 'color'=>'var(--danger)', 'fr'=>'R7 — Départs', 'ar'=>'ر7 تاركو العمل'],
            ['url'=>$OF.'tax_r10', 'icon'=>'fa-file-lines', 'color'=>'var(--success)', 'fr'=>'R10 — Décl. périodique', 'ar'=>'ر10 بيان دوري'],
        ]],
        ['title'=>'صندوق التعليم الخاص / Caisse EOC', 'icon'=>'fa-piggy-bank', 'color'=>'var(--gold)', 'tiles'=>[
            ['url'=>'?report=eoc_summary', 'icon'=>'fa-list', 'color'=>'var(--gold)', 'fr'=>'Caisse mensuelle', 'ar'=>'كشف صندوق شهري'],
            ['url'=>$OF.'eoc_card', 'icon'=>'fa-id-badge', 'color'=>'var(--primary)', 'fr'=>'Carte titulaire', 'ar'=>'بطاقة ملاك'],
            ['url'=>$OF.'eoc_staff&cat=titulaire', 'icon'=>'fa-users', 'color'=>'var(--primary)', 'fr'=>'État général — titulaires', 'ar'=>'بيان عام — الملاك'],
            ['url'=>$OF.'eoc_staff&cat=contractuel', 'icon'=>'fa-users', 'color'=>'var(--info)', 'fr'=>'État général — contractuels', 'ar'=>'بيان عام — المتعاقدين'],
            ['url'=>$OF.'eoc_quarterly', 'icon'=>'fa-calendar-week', 'color'=>'var(--info)', 'fr'=>'Retenues trimestrielles', 'ar'=>'المحسومات الفصلية'],
        ]],
        ['title'=>'بطاقات وموظفين / Cartes & Personnel', 'icon'=>'fa-id-card', 'color'=>'var(--success)', 'tiles'=>[
            ['url'=>'?report=employee_list', 'icon'=>'fa-users', 'color'=>'var(--success)', 'fr'=>'Liste du personnel', 'ar'=>'لائحة الموظفين'],
            ['url'=>$OF.'teacher_card', 'icon'=>'fa-address-card', 'color'=>'var(--primary)', 'fr'=>'Carte enseignant', 'ar'=>'بطاقة الأستاذ'],
            ['url'=>$OF.'teaching_staff', 'icon'=>'fa-chalkboard-user', 'color'=>'var(--info)', 'fr'=>'Corps enseignant', 'ar'=>'لائحة الهيئة التعليمية'],
            ['url'=>$PG.'employee_history.php', 'icon'=>'fa-user-clock', 'color'=>'var(--warning)', 'fr'=>'Dossier enseignant', 'ar'=>'سيرة الأستاذ'],
            ['url'=>$PG.'attestations.php', 'icon'=>'fa-file-signature', 'color'=>'var(--gold)', 'fr'=>'Attestations', 'ar'=>'إفادات'],
            ['url'=>$OF.'staff_stats', 'icon'=>'fa-chart-simple', 'color'=>'var(--info)', 'fr'=>'Statistiques du personnel', 'ar'=>'إحصاءات الموظفين'],
            ['url'=>$OF.'general_info', 'icon'=>'fa-table-list', 'color'=>'var(--gold)', 'fr'=>'Infos générales', 'ar'=>'معلومات عامة عن الموظفين'],
        ]],
        ['title'=>'فروقات وتقرير عام / Différences & Général', 'icon'=>'fa-scale-balanced', 'color'=>'var(--warning)', 'tiles'=>[
            ['url'=>$OF.'differences', 'icon'=>'fa-right-left', 'color'=>'var(--warning)', 'fr'=>'Différences / Augmentation', 'ar'=>'فروقات / زيادة'],
            ['url'=>$OF.'general_report', 'icon'=>'fa-chart-column', 'color'=>'var(--primary)', 'fr'=>'Rapport général', 'ar'=>'تقرير عام بالمجاميع'],
        ]],
    ];
?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-bar"></i> Centre de rapports / مركز التقارير</h3></div>
        <div class="card-body">
            <?php if (isSuperAdmin()): ?>
            <div class="alert alert-info no-print">
                <i class="fas fa-info-circle"></i>
                Dans chaque rapport, vous pouvez choisir <strong>une école, plusieurs écoles ou toutes</strong>.
            </div>
            <?php endif; ?>
            <?php foreach ($categories as $cat): ?>
            <div class="report-cat">
                <h4 class="report-cat-title"><i class="fas <?= $cat['icon'] ?>" style="color:<?= $cat['color'] ?>"></i> <?= e($cat['title']) ?></h4>
                <div class="report-grid">
                    <?php foreach ($cat['tiles'] as $t): ?>
                    <a href="<?= e($t['url']) ?>" class="report-card">
                        <span class="report-card-icon" style="background:<?= $t['color'] ?>1a;color:<?= $t['color'] ?>"><i class="fas <?= $t['icon'] ?>"></i></span>
                        <span class="report-card-text">
                            <span class="rc-fr"><?= e($t['fr']) ?></span>
                            <span class="rc-ar"><?= e($t['ar']) ?></span>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-between align-center mb-3 no-print">
        <a href="<?= BASE_URL ?>pages/reports.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Retour</a>
        <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Imprimer</button>
    </div>

    <?php echo officialFormStyles(); ?>
    <style>
    /* تقارير reports.php كلّها جداول واسعة (١٦ عموداً) → A4 أفقي مرتّب يسع كل الأعمدة بلا قصّ */
    @media print {
        @page { size: A4 landscape; margin: 7mm; }
        /* حجم 12 للحرف والرقم (بطلب المستخدم) + جدول يتأقلم على A4 ويلفّ النص بلا قصّ */
        .doc-table { font-size: 12px !important; table-layout: fixed; width: 100% !important; }
        .doc-table th, .doc-table td { padding: 3px 5px !important; word-wrap: break-word; overflow-wrap: anywhere; }
        .no-print { display: none !important; }
    }
    /* على الشاشة: تمرير أفقي للجداول الواسعة بدل ضغط الأعمدة */
    .report-table-wrap { overflow-x: auto; }
    </style>
    <!-- ترويسة رسمية: ترويسة المدرسة (مدرسة واحدة) أو بانر المدارس المختارة (عدة) -->
    <?php if (!$multi && !isAllSchools()): ?>
        <?= schoolLetterhead(currentSchool()) ?>
    <?php else: ?>
        <div class="report-scope"><i class="fas fa-school"></i> <strong>المدارس / Écoles:</strong> <?= e($selectedNames) ?></div>
    <?php endif; ?>

    <?php if ($report === 'monthly_summary'):
        $stmt = $db->prepare("SELECT e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.employee_type, e.school_id, ms.*
                              FROM monthly_salaries ms
                              JOIN employees e ON e.id = ms.employee_id
                              WHERE ms.year = ? AND ms.month = ? AND e.is_deleted = 0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)" . $schoolSql . $empYearFilter . "
                              ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
        $stmt->execute(array_merge([$year, $month], $empYearParams));
        $data = $stmt->fetchAll();
        $totals = ['cnss'=>0,'caisse'=>0,'tax'=>0,'net'=>0,'family'=>0,'total'=>0,'extra'=>0,'aide'=>0,'base'=>0,'ech'=>0,'bpe'=>0,'trans'=>0];
        $rn = 0;
    ?>
        <form method="GET" class="card no-print">
            <input type="hidden" name="report" value="monthly_summary">
            <div class="card-body form-row cols-3">
                <div class="form-group mb-0">
                    <label class="form-label">Mois</label>
                    <select name="month" class="form-select">
                        <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m)?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Année</label>
                    <input type="number" name="year" class="form-control" value="<?= $year ?>">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher</button>
                </div>
                <?php reportSchoolPicker(); ?>
            </div>
        </form>

        <div class="card">
            <div class="card-header"><h3>📅 Résumé mensuel — <?= monthName($month) ?> <?= $year ?></h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <thead><tr>
                        <th>#</th>
                        <?php if ($multi): ?><th>المدرسة</th><?php endif; ?>
                        <th>الاسم</th><th>الفئة</th><th>الدرجة</th>
                        <th>أساس الراتب</th><th>قيمة الدرجة</th><th>الراتب بعد التدرّج</th>
                        <?= extraAideHeads() ?>
                        <th>الضمان (٣٪)</th><th>الصندوق (٦٪)</th><th>الضريبة</th>
                        <th>الصافي</th><th>التعويضات العائلية</th><th>تعويض النقل</th><th>الإجمالي المتوجب</th>
                    </tr></thead>
                    <tbody>
                        <?php
                        $colspanLbl = $multi ? 5 : 4;
                        $zeroT = ['base'=>0,'ech'=>0,'bpe'=>0,'extra'=>0,'aide'=>0,'cnss'=>0,'caisse'=>0,'tax'=>0,'net'=>0,'family'=>0,'trans'=>0,'total'=>0];
                        // صفّ مجاميع (فرعي لكل فئة أو إجمالي عام) بنفس ترتيب الأعمدة
                        $sumRow = function($label, $t, $isGrand) use ($colspanLbl) {
                            $bg = $isGrand ? '' : 'background:#e0e7ff;';
                            ob_start(); ?>
                            <tr class="<?= $isGrand ? 'total-row' : 'subtotal-row' ?>" style="<?= $bg ?>font-weight:700">
                                <td colspan="<?= $colspanLbl ?>" style="text-align:right"><?= $label ?></td>
                                <td><?= formatLBP($t['base']) ?></td><td><?= formatLBP($t['ech']) ?></td><td><?= formatLBP($t['bpe']) ?></td>
                                <td><?= formatLBP($t['extra']) ?></td><td><?= formatLBP($t['aide']) ?></td>
                                <td><?= formatLBP($t['cnss']) ?></td><td><?= formatLBP($t['caisse']) ?></td><td><?= formatLBP($t['tax']) ?></td>
                                <td><?= formatLBP($t['net']) ?></td><td><?= formatLBP($t['family']) ?></td><td><?= formatLBP($t['trans']) ?></td>
                                <td><strong><?= formatLBP($t['total']) ?></strong></td>
                            </tr>
                        <?php return ob_get_clean(); };
                        $curCat=null; $catTot=$zeroT; $catN=0;
                        foreach ($data as $r):
                            $cat = $r['employee_type'];
                            if ($curCat !== null && $cat !== $curCat) { echo $sumRow('مجموع '.empCategoryTitle($curCat).' — العدد: '.$catN, $catTot, false); $catTot=$zeroT; $catN=0; }
                            $rTrans = (int)$r['transport_lbp']; // من ملف الأستاذ — transport_lbp = transport_complement_lbp فلا تجمعهما (دوبل)
                            $v = ['base'=>(int)$r['base_salary_lbp'],'ech'=>(int)$r['echelon_value_lbp'],'bpe'=>(int)$r['base_plus_echelon_lbp'],
                                  'extra'=>extraWageLbp($r),'aide'=>aideCompLbp($r),'cnss'=>(int)$r['cnss_amount_lbp'],'caisse'=>(int)$r['caisse_amount_lbp'],
                                  'tax'=>(int)$r['income_tax_lbp'],'net'=>(int)$r['net_salary_lbp'],'family'=>(int)$r['family_allowance_lbp'],
                                  'trans'=>$rTrans,'total'=>(int)$r['total_due_lbp']];
                            foreach ($v as $k=>$val) { $catTot[$k]+=$val; $totals[$k]+=$val; }
                            $catN++;
                            echo categoryHeaderRow($curCat, $cat, $multi?17:16);
                        ?>
                            <tr>
                                <td><?= ++$rn ?></td>
                                <?php if ($multi): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                                <td><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                                <td><small><?= employeeTypeLabel($r['employee_type']) ?></small></td>
                                <td><?= $r['grade_at_month'] ?></td>
                                <td><?= formatLBP($r['base_salary_lbp']) ?></td>
                                <td><?= formatLBP($r['echelon_value_lbp']) ?></td>
                                <td><?= formatLBP($r['base_plus_echelon_lbp']) ?></td>
                                <td><?= formatLBP(extraWageLbp($r)) ?></td>
                                <td><?= formatLBP(aideCompLbp($r)) ?></td>
                                <td><?= formatLBP($r['cnss_amount_lbp']) ?></td>
                                <td><?= formatLBP($r['caisse_amount_lbp']) ?></td>
                                <td><?= formatLBP($r['income_tax_lbp']) ?></td>
                                <td><?= formatLBP($r['net_salary_lbp']) ?></td>
                                <td><?= formatLBP($r['family_allowance_lbp']) ?></td>
                                <td><?= formatLBP($rTrans) ?></td>
                                <td><strong><?= formatLBP($r['total_due_lbp']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data) echo $sumRow('مجموع '.empCategoryTitle($curCat).' — العدد: '.$catN, $catTot, false); ?>
                        <?php if (!$data): ?><tr><td colspan="<?= $multi?17:16 ?>" class="text-center text-muted">لا توجد بيانات — احسب رواتب هذا الشهر أولاً</td></tr><?php endif; ?>
                        <?php if ($data) echo $sumRow('الإجمالي العام — مجموع كل الفئات (العدد: '.$rn.')', $totals, true); ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($report === 'cnss_summary'):
        $stmt = $db->prepare("SELECT e.employee_type, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.nssf_number, e.birth_date, e.school_id, ms.base_salary_lbp, ms.cnss_amount_lbp, ms.school_cnss_8_lbp, ms.taxable_base_lbp, ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp
                              FROM monthly_salaries ms
                              JOIN employees e ON e.id = ms.employee_id
                              WHERE ms.year = ? AND ms.month = ? AND e.is_deleted = 0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND e.cnss_subject = 1" . $schoolSql . $empYearFilter . "
                              ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
        $stmt->execute(array_merge([$year, $month], $empYearParams));
        $data = $stmt->fetchAll();
        $te=0;$ts=0;$teExtra=0;$teAide=0;$teBase=0;
    ?>
        <form method="GET" class="card no-print">
            <input type="hidden" name="report" value="cnss_summary">
            <div class="card-body form-row cols-3">
                <div class="form-group mb-0"><label class="form-label">Mois</label><select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m)?></option><?php endfor; ?></select></div>
                <div class="form-group mb-0"><label class="form-label">Année</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
                <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100">Afficher</button></div>
                <?php reportSchoolPicker(); ?>
            </div>
        </form>
        <div class="card">
            <div class="card-header"><h3>🏥 CNSS — <?= monthName($month) ?> <?= $year ?></h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <thead><tr><th>#</th><?php if ($multi): ?><th>المدرسة</th><?php endif; ?><th>رقم الضمان</th><th>الاسم</th><th>أساس الراتب</th><?= extraAideHeads() ?><th>وعاء الضمان</th><th>الأجير ٣٪</th><th>المدرسة ٨٪</th></tr></thead>
                    <tbody>
                        <?php
                        $zC = ['base'=>0,'extra'=>0,'aide'=>0,'cnss'=>0,'school'=>0]; $G = $zC; $csL = $multi?4:3;
                        $drawTotal = function($label,$a,$isGrand) use ($csL){
                            $bg=$isGrand?'':'background:#e0e7ff;'; $cls=$isGrand?'total-row':'subtotal-row'; ?>
                            <tr class="<?= $cls ?>" style="<?= $bg ?>font-weight:700"><td colspan="<?= $csL ?>" style="text-align:right"><?= e($label) ?></td>
                                <td><?= formatLBP($a['base']) ?></td><td><?= formatLBP($a['extra']) ?></td><td><?= formatLBP($a['aide']) ?></td>
                                <td></td><td><?= formatLBP($a['cnss']) ?></td><td><?= formatLBP($a['school']) ?></td></tr>
                        <?php };
                        $rn=0; $curCat=null; $sub=$zC;
                        foreach ($data as $r):
                            $cat=$r['employee_type'];
                            if ($cat !== $curCat):
                                if ($curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                                $sub=$zC; $curCat=$cat;
                                ?><tr class="cat-row"><td colspan="<?= $multi?10:9 ?>" style="text-align:right;font-weight:700;background:#dbeafe"><?= e(empCategoryTitle($cat)) ?></td></tr><?php
                            endif;
                            $add=['base'=>(int)$r['base_salary_lbp'],'extra'=>extraWageLbp($r),'aide'=>aideCompLbp($r),'cnss'=>(int)$r['cnss_amount_lbp'],'school'=>(int)$r['school_cnss_8_lbp']];
                            foreach ($add as $k=>$v){ $G[$k]+=$v; $sub[$k]+=$v; } ?>
                            <tr>
                                <td><?= ++$rn ?></td>
                                <?php if ($multi): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                                <td><?= e(cnssWithBirthYear($r['nssf_number'], $r['birth_date'])) ?></td>
                                <td><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                                <td><?= formatLBP($r['base_salary_lbp']) ?></td>
                                <td><?= formatLBP(extraWageLbp($r)) ?></td>
                                <td><?= formatLBP(aideCompLbp($r)) ?></td>
                                <?php /* وعاء الضمان الفعلي مشتقّاً من اشتراك ٣٪ المخزّن — لا وعاء الضريبة (يطلع صفر تحت العتبة) */ ?>
                                <td><?= formatLBP($r['cnss_amount_lbp'] ? (int)round($r['cnss_amount_lbp']/0.03) : 0) ?></td>
                                <td><?= formatLBP($r['cnss_amount_lbp']) ?></td>
                                <td><?= formatLBP($r['school_cnss_8_lbp']) ?></td>
                            </tr>
                        <?php endforeach;
                        if ($data && $curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                        if (!$data): ?><tr><td colspan="<?= $multi?10:9 ?>" class="text-center text-muted">لا توجد بيانات</td></tr><?php endif; ?>
                        <?php if ($data) $drawTotal('المجموع العام — العدد: '.$rn, $G, true); ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($report === 'tax_summary'):
        $stmt = $db->prepare("SELECT e.employee_type, e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.finance_ministry_number, e.school_id, ms.base_salary_lbp, ms.income_tax_lbp, ms.taxable_base_lbp, ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp
                              FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
                              WHERE ms.year = ? AND ms.month = ? AND e.is_deleted = 0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND e.tax_subject = 1" . $schoolSql . $empYearFilter . "
                              ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
        $stmt->execute(array_merge([$year, $month], $empYearParams));
        $data = $stmt->fetchAll();
        $t=0;$txExtra=0;$txAide=0;$txBase=0;
    ?>
        <form method="GET" class="card no-print">
            <input type="hidden" name="report" value="tax_summary">
            <div class="card-body form-row cols-3">
                <div class="form-group mb-0"><label class="form-label">Mois</label><select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m)?></option><?php endfor; ?></select></div>
                <div class="form-group mb-0"><label class="form-label">Année</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
                <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100">Afficher</button></div>
                <?php reportSchoolPicker(); ?>
            </div>
        </form>
        <div class="card">
            <div class="card-header"><h3>💵 Impôt sur le revenu — <?= monthName($month) ?> <?= $year ?></h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <thead><tr><th>#</th><?php if ($multi): ?><th>المدرسة</th><?php endif; ?><th>الرقم المالي</th><th>الاسم</th><th>أساس الراتب</th><?= extraAideHeads() ?><th>الراتب الخاضع للضريبة</th><th>الضريبة</th></tr></thead>
                    <tbody>
                        <?php
                        $zX = ['base'=>0,'extra'=>0,'aide'=>0,'tax'=>0]; $G = $zX; $csL = $multi?4:3;
                        $drawTotal = function($label,$a,$isGrand) use ($csL){
                            $bg=$isGrand?'':'background:#e0e7ff;'; $cls=$isGrand?'total-row':'subtotal-row'; ?>
                            <tr class="<?= $cls ?>" style="<?= $bg ?>font-weight:700"><td colspan="<?= $csL ?>" style="text-align:right"><?= e($label) ?></td>
                                <td><?= formatLBP($a['base']) ?></td><td><?= formatLBP($a['extra']) ?></td><td><?= formatLBP($a['aide']) ?></td>
                                <td></td><td><strong><?= formatLBP($a['tax']) ?></strong></td></tr>
                        <?php };
                        $rn=0; $curCat=null; $sub=$zX;
                        foreach ($data as $r):
                            $cat=$r['employee_type'];
                            if ($cat !== $curCat):
                                if ($curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                                $sub=$zX; $curCat=$cat;
                                ?><tr class="cat-row"><td colspan="<?= $multi?9:8 ?>" style="text-align:right;font-weight:700;background:#dbeafe"><?= e(empCategoryTitle($cat)) ?></td></tr><?php
                            endif;
                            $add=['base'=>(int)$r['base_salary_lbp'],'extra'=>extraWageLbp($r),'aide'=>aideCompLbp($r),'tax'=>(int)$r['income_tax_lbp']];
                            foreach ($add as $k=>$v){ $G[$k]+=$v; $sub[$k]+=$v; } ?>
                            <tr>
                                <td><?= ++$rn ?></td>
                                <?php if ($multi): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                                <td><?= e($r['finance_ministry_number']) ?></td>
                                <td><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                                <td><?= formatLBP($r['base_salary_lbp']) ?></td>
                                <td><?= formatLBP(extraWageLbp($r)) ?></td>
                                <td><?= formatLBP(aideCompLbp($r)) ?></td>
                                <td><?= formatLBP($r['taxable_base_lbp']) ?></td>
                                <td><strong><?= formatLBP($r['income_tax_lbp']) ?></strong></td>
                            </tr>
                        <?php endforeach;
                        if ($data && $curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                        if (!$data): ?><tr><td colspan="<?= $multi?9:8 ?>" class="text-center text-muted">لا توجد بيانات</td></tr><?php endif; ?>
                        <?php if ($data) $drawTotal('المجموع العام — العدد: '.$rn, $G, true); ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($report === 'eoc_summary'):
        $stmt = $db->prepare("SELECT e.first_name_fr, e.last_name_fr, e.first_name_ar, e.last_name_ar, e.caisse_number, e.school_id, ms.base_salary_lbp, ms.caisse_amount_lbp, ms.eoc_grade_lbp, ms.school_eoc_6_lbp, ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp
                              FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
                              WHERE ms.year = ? AND ms.month = ? AND e.is_deleted = 0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND e.employee_type = 'enseignant_titulaire'" . $schoolSql . $empYearFilter . "
                              ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
        $stmt->execute(array_merge([$year, $month], $empYearParams));
        $data = $stmt->fetchAll();
        $te=0;$ts=0;$teg=0;$teEx=0;$teAi=0;$teBaseE=0;
    ?>
        <form method="GET" class="card no-print">
            <input type="hidden" name="report" value="eoc_summary">
            <div class="card-body form-row cols-3">
                <div class="form-group mb-0"><label class="form-label">Mois</label><select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m)?></option><?php endfor; ?></select></div>
                <div class="form-group mb-0"><label class="form-label">Année</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
                <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100">Afficher</button></div>
                <?php reportSchoolPicker(); ?>
            </div>
        </form>
        <div class="card">
            <div class="card-header"><h3>🏦 Caisse EOC — <?= monthName($month) ?> <?= $year ?></h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <thead><tr><th>#</th><?php if ($multi): ?><th>المدرسة</th><?php endif; ?><th>رقم الصندوق</th><th>الاسم</th><th>أساس الراتب</th><?= extraAideHeads() ?><th>الأجير ٦٪</th><th>درجة/نصف راتب</th><th>المدرسة ٦٪</th></tr></thead>
                    <tbody>
                        <?php $rn=0; foreach ($data as $r): $te += $r['caisse_amount_lbp']; $ts += $r['school_eoc_6_lbp']; $teg += $r['eoc_grade_lbp']; $teEx += extraWageLbp($r); $teAi += aideCompLbp($r); $teBaseE += (int)$r['base_salary_lbp']; ?>
                            <tr>
                                <td><?= ++$rn ?></td>
                                <?php if ($multi): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                                <td><?= e($r['caisse_number']) ?></td>
                                <td><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                                <td><?= formatLBP($r['base_salary_lbp']) ?></td>
                                <td><?= formatLBP(extraWageLbp($r)) ?></td>
                                <td><?= formatLBP(aideCompLbp($r)) ?></td>
                                <td><?= formatLBP($r['caisse_amount_lbp']) ?></td>
                                <td><?= (int)$r['eoc_grade_lbp'] > 0 ? formatLBP($r['eoc_grade_lbp']) : '—' ?></td>
                                <td><?= formatLBP($r['school_eoc_6_lbp']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$data): ?><tr><td colspan="<?= $multi?10:9 ?>" class="text-center text-muted">لا توجد بيانات</td></tr><?php endif; ?>
                        <tr class="total-row"><td colspan="<?= $multi?4:3 ?>">المجاميع — العدد: <?= $rn ?></td><td><?= formatLBP($teBaseE) ?></td><td><?= formatLBP($teEx) ?></td><td><?= formatLBP($teAi) ?></td><td><?= formatLBP($te) ?></td><td><?= formatLBP($teg) ?></td><td><?= formatLBP($ts) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($report === 'employee_list'):
        $empType = $_GET['emp_type'] ?? '';
        $typeAllowed = ['enseignant_titulaire', 'enseignant_contractuel', 'employe'];
        $typeSql = in_array($empType, $typeAllowed, true) ? " AND e.employee_type = " . $db->quote($empType) : "";
        // فلترة حسب السنة الدراسية المختارة (سنة محددة = موظفو تلك السنة؛ كل السنين = الكل)
        [$yf, $yp] = yearEmploymentFilter(activeSchoolYear(), 'e.');
        $stmtEL = $db->prepare("SELECT e.* FROM employees e WHERE e.is_deleted = 0" . $schoolSqlEmp . $typeSql . $yf . " ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
        $stmtEL->execute($yp);
        $data = $stmtEL->fetchAll();
        $typeLabels = [
            'enseignant_titulaire'   => 'أساتذة الملاك / Titulaires',
            'enseignant_contractuel' => 'أساتذة متعاقدون / Contractuels',
            'employe'                => 'موظفون / Employés',
        ];
        $listTitle = ($empType && isset($typeLabels[$empType])) ? $typeLabels[$empType] : 'كل الموظفين / Tout le personnel';
    ?>
        <?php
        // سلسلة 2017: الراتب حسب الدرجة (لعمود الراتب)
        $scaleMap = [];
        foreach ($db->query("SELECT grade, new_salary_2017 FROM salary_scale_2017 WHERE version_id = 1") as $sc) $scaleMap[(int)$sc['grade']] = (float)$sc['new_salary_2017'];
        // آخر راتب محسوب لكل موظف (لعمودَي الأجر الإضافي ومكافأة ومساعدة الاختياريين)
        $bonusMap = [];
        foreach ($db->query("SELECT ms.employee_id, ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp
                             FROM monthly_salaries ms
                             JOIN (SELECT employee_id, MAX(year*12+month) ym FROM monthly_salaries WHERE is_calculated=1 GROUP BY employee_id) lt
                               ON lt.employee_id=ms.employee_id AND (ms.year*12+ms.month)=lt.ym") as $b) {
            $bonusMap[(int)$b['employee_id']] = $b;
        }
        // الأعمدة المتاحة: key => [label, دالة العرض]
        $availCols = [
            'code'    => ['Code', fn($r) => '<strong>'.e($r['employee_code']).'</strong>'],
            'name'    => ['الاسم / Nom', fn($r) => e(trim($r['first_name_fr'].' '.$r['last_name_fr']) ?: trim($r['first_name_ar'].' '.$r['last_name_ar']))],
            'name_ar' => ['الاسم بالعربي', fn($r) => e(trim($r['first_name_ar'].' '.$r['last_name_ar']))],
            'type'    => ['الفئة / Type', fn($r) => employeeTypeLabel($r['employee_type'])],
            'diploma' => ['الشهادة / Diplôme', fn($r) => diplomaLabel($r['diploma'])],
            'grade'   => ['الدرجة / Échelon', fn($r) => $r['current_grade']],
            'salary'  => ['الراتب (قانون) / Salaire', fn($r) => formatLBP($scaleMap[(int)round($r['current_grade'])] ?? 0)],
            'extra_wage' => ['الأجر الإضافي', fn($r) => formatLBP(isset($bonusMap[(int)$r['id']]) ? extraWageLbp($bonusMap[(int)$r['id']]) : 0)],
            'aide'    => ['مكافأة ومساعدة', fn($r) => formatLBP(isset($bonusMap[(int)$r['id']]) ? aideCompLbp($bonusMap[(int)$r['id']]) : 0)],
            'nssf'    => ['ضمان / N° CNSS', fn($r) => e($r['nssf_number'])],
            'mof'     => ['مالية / N° MOF', fn($r) => e($r['finance_ministry_number'])],
            'caisse'  => ['صندوق / N° Caisse', fn($r) => e($r['caisse_number'])],
            'phone'   => ['هاتف / Tél.', fn($r) => e(implode(' / ', array_filter([trim($r['phone1']), trim($r['phone2'])])))],
            'email'   => ['Email', fn($r) => e($r['email'])],
            'address' => ['السكن / Adresse', fn($r) => e(trim(implode(' ', array_filter([$r['gouvernorat'],$r['district'],$r['ville'],$r['rue'],$r['immeuble']]))))],
            'birth'   => ['الولادة / Naissance', fn($r) => trim(formatDate($r['birth_date']).' '.e($r['birth_place']))],
            'social'  => ['عائلي / Famille', fn($r) => e($r['social_status']).' ('.(int)$r['number_of_children'].')'],
            'hire'    => ['الدخول / Embauche', fn($r) => formatDate($r['hire_date'])],
            'titul'   => ['الملاك / Titularisation', fn($r) => formatDate($r['titularization_date'])],
            'hours'   => ['ساعات/أسبوع', fn($r) => rtrim(rtrim(number_format((float)$r['hours_per_week'],1),'0'),'.')],
            'days'    => ['أيام/أسبوع', fn($r) => (int)$r['days_per_week']],
            'status'  => ['الحالة / Statut', fn($r) => '<span class="badge badge-'.employeeStatusLabel($r['status'])['badge'].'">'.e(employeeStatusLabel($r['status'])['label']).'</span>'],
        ];
        $defaultCols = ['code','name','type','grade','status'];
        $selectedCols = $_GET['cols'] ?? $defaultCols;
        if (!is_array($selectedCols)) $selectedCols = $defaultCols;
        $selectedCols = array_values(array_filter($selectedCols, fn($c) => isset($availCols[$c])));
        if (empty($selectedCols)) $selectedCols = $defaultCols;
        ?>
        <form method="GET" class="card no-print">
            <input type="hidden" name="report" value="employee_list">
            <div class="card-body">
                <div class="form-row cols-3">
                    <div class="form-group mb-0">
                        <label class="form-label">الفئة / Type</label>
                        <select name="emp_type" class="form-select">
                            <option value="">الكل / Tous</option>
                            <option value="enseignant_titulaire" <?= $empType==='enseignant_titulaire'?'selected':'' ?>>أساتذة الملاك / Titulaires</option>
                            <option value="enseignant_contractuel" <?= $empType==='enseignant_contractuel'?'selected':'' ?>>أساتذة متعاقدون / Contractuels</option>
                            <option value="employe" <?= $empType==='employe'?'selected':'' ?>>موظفون / Employés</option>
                        </select>
                    </div>
                    <?php reportSchoolPicker(); ?>
                </div>
                <label class="form-label" style="margin-top:14px;display:block">📋 المعلومات اللي بدّك ياها بالتقرير / Colonnes à afficher:</label>
                <div class="school-checks" style="display:flex;flex-wrap:wrap;gap:8px 18px">
                    <?php foreach ($availCols as $k => $c): ?>
                        <label class="chk"><input type="checkbox" name="cols[]" value="<?= $k ?>" <?= in_array($k, $selectedCols, true) ? 'checked' : '' ?>> <?= e($c[0]) ?></label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-primary" style="margin-top:14px"><i class="fas fa-filter"></i> عرض التقرير / Afficher</button>
            </div>
        </form>
        <div class="card">
            <div class="card-header"><h3>👥 <?= $listTitle ?> (<?= count($data) ?>)</h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <thead><tr>
                        <th>#</th>
                        <?php if ($multi): ?><th>المدرسة</th><?php endif; ?>
                        <?php foreach ($selectedCols as $k) echo '<th>'.e($availCols[$k][0]).'</th>'; ?>
                    </tr></thead>
                    <tbody>
                        <?php $rn=0; $curCat=null; foreach ($data as $r): ?>
                            <?= categoryHeaderRow($curCat, $r['employee_type'], count($selectedCols) + 1 + ($multi?1:0)) ?>
                            <tr>
                                <td><?= ++$rn ?></td>
                                <?php if ($multi): ?><td><small><?= e(schoolNameById($r['school_id'])) ?></small></td><?php endif; ?>
                                <?php foreach ($selectedCols as $k) echo '<td>'.($availCols[$k][1])($r).'</td>'; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$data): ?><tr><td colspan="<?= count($selectedCols) + 1 + ($multi?1:0) ?>" class="text-center text-muted">Aucun employé</td></tr><?php endif; ?>
                        <tr class="total-row"><td colspan="<?= count($selectedCols) + 1 + ($multi?1:0) ?>">العدد الإجمالي / Total: <?= count($data) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($report === 'annual_totals'):
        [$y1,$y2] = schoolYearToYears($schoolYear);
        // إجمالي عام
        $stmt = $db->prepare("SELECT COUNT(*) cnt, SUM(ms.net_salary_lbp) net, SUM(ms.total_due_lbp) total,
                              SUM(ms.cnss_amount_lbp) cnss, SUM(ms.income_tax_lbp) tax, SUM(ms.caisse_amount_lbp) caisse,
                              SUM(ms.school_cnss_8_lbp) scnss, SUM(ms.school_eoc_6_lbp) seoc,
                              SUM(ms.base_salary_lbp) base_sal, SUM(ms.base_plus_echelon_lbp) bpe,
                              SUM(ms.extra_lbp + ms.prime_fixe_lbp) extra_wage, SUM(ms.aide_complementaire_lbp) aide,
                              SUM(ms.transport_lbp) transport
                              FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                              WHERE e.is_deleted=0" . $annualEmpFilter . " AND ms.school_year = ? AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)" . $schoolSql);
        $stmt->execute(array_merge($annualEmpParams, [$schoolYear]));
        $tot = $stmt->fetch();
        // تفصيل لكل مدرسة (عند تعدد المدارس)
        $perSchool = [];
        if ($multi) {
            $ps = $db->prepare("SELECT ms.school_id, COUNT(*) cnt, SUM(ms.total_due_lbp) total, SUM(ms.cnss_amount_lbp) cnss, SUM(ms.income_tax_lbp) tax
                                FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                                WHERE e.is_deleted=0" . $annualEmpFilter . " AND ms.school_year = ? AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)" . $schoolSql . " GROUP BY ms.school_id ORDER BY ms.school_id");
            $ps->execute(array_merge($annualEmpParams, [$schoolYear]));
            $perSchool = $ps->fetchAll();
        }
    ?>
        <form method="GET" class="card no-print">
            <input type="hidden" name="report" value="annual_totals">
            <div class="card-body form-row cols-3">
                <div class="form-group mb-0"><label class="form-label">Année scolaire</label><input type="text" name="school_year" class="form-control" value="<?= e($schoolYear) ?>"></div>
                <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100">Afficher</button></div>
                <?php reportSchoolPicker(); ?>
            </div>
        </form>

        <?php if ($multi && $perSchool): ?>
        <div class="card">
            <div class="card-header"><h3>🏫 Détail par école — <?= e($schoolYear) ?></h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <thead><tr><th>#</th><th>المدرسة</th><th>عدد الكشوف</th><th>الإجمالي المتوجب</th><th>الضمان</th><th>الضريبة</th></tr></thead>
                    <tbody>
                        <?php $rn=0; foreach ($perSchool as $p): ?>
                        <tr>
                            <td><?= ++$rn ?></td>
                            <td><strong><?= e(schoolNameById($p['school_id'])) ?></strong></td>
                            <td><?= $p['cnt'] ?></td>
                            <td><?= formatLBP($p['total']) ?></td>
                            <td><?= formatLBP($p['cnss']) ?></td>
                            <td><?= formatLBP($p['tax']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="6">عدد المدارس / Écoles: <?= count($perSchool) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h3>📊 Totaux annuels (cumulés) — <?= e($schoolYear) ?></h3></div>
            <div class="card-body">
                <table class="doc-table" dir="rtl">
                    <tr><td><strong>عدد الكشوف المحسوبة</strong></td><td><?= $tot['cnt'] ?: 0 ?></td></tr>
                    <tr style="background:var(--gold-light)"><td><strong>إجمالي المدفوع (الصافي)</strong></td><td><strong><?= formatLBP($tot['net']) ?></strong></td></tr>
                    <tr style="background:var(--gold-light)"><td><strong>الإجمالي المتوجب (صافي + تعويضات)</strong></td><td><strong><?= formatLBP($tot['total']) ?></strong></td></tr>
                    <tr><td>أساس الراتب</td><td><?= formatLBP($tot['base_sal']) ?></td></tr>
                    <tr><td>الراتب بعد التدرّج</td><td><?= formatLBP($tot['bpe']) ?></td></tr>
                    <tr><td>الأجر الإضافي</td><td><?= formatLBP($tot['extra_wage']) ?></td></tr>
                    <tr><td>مكافأة ومساعدة</td><td><?= formatLBP($tot['aide']) ?></td></tr>
                    <tr><td>تعويض النقل</td><td><?= formatLBP($tot['transport']) ?></td></tr>
                    <tr><td>الضمان — الأجير ٣٪</td><td><?= formatLBP($tot['cnss']) ?></td></tr>
                    <tr><td>الضمان — المدرسة ٨٪</td><td><?= formatLBP($tot['scnss']) ?></td></tr>
                    <tr><td>صندوق التعويضات — الأجير ٦٪</td><td><?= formatLBP($tot['caisse']) ?></td></tr>
                    <tr><td>صندوق التعويضات — المدرسة ٦٪</td><td><?= formatLBP($tot['seoc']) ?></td></tr>
                    <tr><td>ضريبة الدخل</td><td><?= formatLBP($tot['tax']) ?></td></tr>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <script>
    // منتقي المدارس: "الكل" يلغي الباقي، واختيار مدرسة يلغي "الكل"
    function toggleAllSchools(allBox){
        if(allBox.checked){
            document.querySelectorAll('input[name="schools[]"]').forEach(c=>c.checked=false);
        }
    }
    function onSchoolCheck(){
        var any = Array.from(document.querySelectorAll('input[name="schools[]"]')).some(c=>c.checked);
        var allBox = document.querySelector('.school-checks .all input');
        if(allBox) allBox.checked = !any;
    }
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
