<?php
/**
 * official_forms.php — النماذج الرسمية اللبنانية (وزارة المالية + الضمان + صندوق التعويضات)
 * أُعيد بناؤها لتطابق النماذج الرسمية الحقيقية (Desktop\نماذج) وتُعبَّأ حسب كل أستاذ.
 *   - وزارة المالية (أخضر): ر3/ر5/ر6/ر6ت/ر10
 *   - الضمان (أزرق): إعلام استخدام/ترك أجير (41A) · إفادة عمل (489) · تصريح زوجة (485A) · التصريح السنوي (386)
 *   - تقارير داخلية: بطاقة الأستاذ · لائحة الهيئة · صندوق · كشف رواتب · فروقات · تقرير عام
 *
 * الاستعمال: official_forms.php?form=<نوع>&employee_id=<id>&year=<y>&month=<m>
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/report_export.php';
requireLogin();
// لا نشترط مدرسة واحدة — التقارير المجمّعة تعمل مع عدة مدارس/الكل،
// والنماذج الفردية تأخذ مدرستها من الموظف المختار.

$db = getDB();
$form = preg_replace('/[^a-z0-9_]/', '', $_GET['form'] ?? '');
$employeeId = (int)($_GET['employee_id'] ?? 0);
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$schoolYear = $_GET['school_year'] ?? activeSchoolYear();
if ($schoolYear === 'all') $schoolYear = currentSchoolYear();
// 🔒 تحقّق من شكل السنة: قيمة غير صالحة كانت تُفرِّغ فلتر السنة (yearEmploymentFilter)
// فيُحتسب كل التاريخ بالتصاريح المؤسّسية، وتُسبّب «Undefined array key 1» بتفكيك السنة.
if (!preg_match('/^\d{4}-\d{4}$/', (string)$schoolYear)) $schoolYear = currentSchoolYear();
$month = ($month >= 1 && $month <= 12) ? $month : (int)date('n');
$year  = ($year >= 2000 && $year <= 2100) ? $year : (int)date('Y');
$school = currentSchool();
// 🔵 نموذج لأستاذ محدّد في وضع «كل المدارس»: المؤسسة صاحبة العمل هي **مدرسة الأستاذ**
// (كان $school = null فتُطبَع ترويسة فارغة وتظهر تحذيرات «قراءة من قيمة فارغة»).
if (!$school && $employeeId > 0) {
    $sOwn = $db->prepare("SELECT s.* FROM employees e JOIN schools s ON s.id = e.school_id
                           WHERE e.id = ? AND e.is_deleted = 0" . schoolScopeSql('e.school_id'));
    $sOwn->execute([$employeeId]);
    $school = $sOwn->fetch() ?: null;
}
// 🔵 النماذج المؤسّسية (تصاريح الضريبة والضمان) تُصدَر باسم مؤسسة واحدة ورقمها لدى الضمان.
// في وضع «كل المدارس» كان $school = null فتُطبَع الترويسة فارغة وتُدمَج أرقام كل المدارس
// في تصريح واحد بلا رقم صاحب عمل. الآن نطلب اختيار مدرسة بوضوح.
$institutionForms = ['tax_r5','tax_r10','tax_r7','cnss_annual','cnss_contrib_monthly','cnss_contrib_annual','cnss_nominative_monthly'];
if (in_array($form, $institutionForms, true) && !$school) {
    $_SESSION['flash_error'] = 'هذا التصريح يُصدَر لمدرسة واحدة — اختر المدرسة من الأعلى أولاً. / Choisissez une seule école.';
    header('Location: ' . BASE_URL . 'pages/tax_declarations.php');
    exit;
}
$lang = $_SESSION['lang'] ?? 'fr';

// فلترا «موظفي الفترة» الموحّدان (نفس مصدر بيان صندوق التعويضات yearEmploymentFilter) —
// يُطبّقان على كل تصاريح وكشوف المؤسسة فيستبعدان المحذوفين والتاركين وصفوف الأشباح، فلا
// يُحتسب موظفون سابقون من سنوات ماضية تركها الاستيراد على جدول الرواتب.
//  - بحسب السنة الدراسية المعروضة $schoolYear (للتقارير السنوية المؤسّسية):
[$ofYearFilter, $ofYearParams] = yearEmploymentFilter($schoolYear, 'e.');
//  - بحسب السنة الدراسية المشتقّة من شهر/سنة الفترة (للكشوف الشهرية؛ تشرين الأول = سنة جديدة):
$ofMonthSchoolYear = ($month >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
[$ofMonthFilter, $ofMonthParams] = yearEmploymentFilter($ofMonthSchoolYear, 'e.');

// النماذج التي تحتاج موظفاً محدّداً (تُعبَّأ لكل أستاذ)
$perEmployee = ['cnss_employ','cnss_terminate','cnss_work','cnss_wife',
                'teacher_card','eoc_card','tax_register','tax_r6','tax_r6t',
                'tax_r4','cnss_employ2','cnss_work_detail','cnss_eos_invite',
                'cnss_eos_wage','cnss_parent','cnss_eos_settle'];
// نماذج خاصة بالموظف الإداري فقط (الأستاذ ياخذ نهاية خدمته من صندوق التعويضات)
$employeeOnly = ['cnss_eos_settle'];
// نماذج خاصة بالملاك فقط (صندوق التعويضات = الأستاذ الملاك)
$titulaireOnly = ['eoc_card'];
// نماذج خاصة بالأساتذة (بطاقة الأستاذ فيها درجة/مواد/مرحلة — لا تخصّ الموظف الإداري)
$teacherOnly = ['teacher_card'];

$titles = [
    'cnss_employ'    => 'Déclaration d\'embauche d\'un salarié (CNSS 41A) / إعلام عن استخدام أجير (CNSS 41A)',
    'cnss_terminate' => 'Déclaration de cessation de travail d\'un salarié (CNSS 41A) / إعلام عن ترك أجير عمله (CNSS 41A)',
    'cnss_employ2'   => 'Déclaration d\'embauche d\'un salarié + charges de famille (CNSS 2-AA) / تصريح باستخدام أجير + العيال (CNSS 2-AA)',
    'cnss_work'      => 'Attestation de travail (CNSS 489) / إفادة عمل لمن يهمه الأمر (CNSS 489)',
    'cnss_work_detail'=> 'Attestation de travail — maladie (détaillée) (CNSS 2M) / إفادة عمل — مرض (مفصّلة) (CNSS 2M)',
    'cnss_wife'      => 'Déclaration concernant l\'épouse (CNSS 485A) / تصريح عن الزوجة (CNSS 485A)',
    'cnss_annual'    => 'Déclaration nominative annuelle (CNSS 386) / التصريح الاسمي السنوي (CNSS 386)',
    'cnss_nominative_monthly' => 'État mensuel des cotisations CNSS — nominatif détaillé / كشف اشتراكات الضمان الشهري — اسمي مفصّل',
    'salary_detail'  => 'Détail des salaires — tous les enseignants/employés / معلومات تفصيلية عن الراتب — جميع الأساتذة/الموظفون',
    'cnss_contrib_monthly' => 'Déclaration mensuelle CNSS (CNSS 190A) / تصريح الضمان الشهري (CNSS 190A)',
    'cnss_contrib_annual'  => 'Déclaration trimestrielle CNSS (CNSS 190A) / تصريح الضمان الفصلي (CNSS 190A)',
    'cnss_eos_invite'=> 'Convocation/attestation de fixation des salaires — fin de service (CNSS 215A) / دعوة/إفادة تحديد الأجور — نهاية الخدمة (CNSS 215A)',
    'cnss_eos_wage'  => 'Attestation du dernier salaire — fin de service (CNSS 207) / إفادة بالأجر الأخير — نهاية الخدمة (CNSS 207)',
    'cnss_eos_settle'=> 'Demande de liquidation de l\'indemnité de fin de service — employé (CNSS) / طلب تصفية تعويض نهاية الخدمة — للموظف (CNSS)',
    'cnss_parent'    => 'Demande d\'enquête sociale pour le parent (CNSS 482) / طلب تحقيق اجتماعي للوالد (CNSS 482)',
    'tax_r5'         => 'Formulaire R5 — déclaration annuelle de l\'impôt sur les salaires / نموذج ر5 — تصريح سنوي عن ضريبة الرواتب',
    'tax_r6'         => 'Formulaire R6 — état annuel individuel / نموذج ر6 — كشف سنوي إفرادي',
    'tax_r6t'        => 'Formulaire R6/T — état annuel individuel rectificatif / نموذج ر6/ت — تعديل كشف سنوي إفرادي',
    'tax_r4'         => 'Formulaire R4 — fiche de renseignements + charges de famille / نموذج ر4 — بيان معلومات + جدول العيال',
    'tax_r7'         => 'Formulaire R7 — état des départs en cours d\'année / نموذج ر7 — كشف تاركي العمل خلال السنة',
    'tax_r10'        => 'Formulaire R10 — état périodique de versement de l\'impôt / نموذج ر10 — بيان دوري بتأدية الضريبة',
    'tax_register'   => 'Formulaire R3 — demande d\'inscription d\'un nouveau salarié / نموذج ر3 — طلب تسجيل مستخدم/أجير جديد',
    'teacher_card'   => 'Fiche de l\'enseignant / بطاقة الأستاذ',
    'teaching_staff' => 'Liste du corps enseignant / لائحة الهيئة التعليمية',
    'eoc_card'       => 'Fiche du titulaire — Caisse d\'indemnités / بطاقة ملاك — صندوق التعويضات',
    'eoc_staff'      => 'État général (titulaires/contractuels) — Caisse d\'indemnités / بيان عام (ملاك/متعاقدين) — صندوق التعويضات',
    'eoc_quarterly'  => 'Retenues trimestrielles — Caisse d\'indemnités / المحسومات الفصلية — صندوق التعويضات',
    'salary_all'     => 'État des salaires de tous les employés / كشف رواتب كل الموظفين',
    'differences'    => 'État des écarts / augmentations / كشف الفروقات / الزيادة',
    'general_report' => 'Rapport général récapitulatif / تقرير عام بالمجاميع',
    'payment_list'   => 'État de paiement / liste de paie / كشف الدفع / لائحة الرواتب',
    'employer_cost'  => 'Coût total pour l\'établissement / كلفة المؤسسة الإجمالية',
    'full_register'  => 'Tous les enseignants — état complet avec coûts / جميع الأساتذة — كشف شامل بالكلفة',
    'staff_stats'    => 'Statistiques du personnel / إحصاءات الموظفين',
    'general_info'   => 'Informations générales sur le personnel / معلومات عامة عن الموظفين',
];
$pageTitle = $titles[$form] ?? 'Rapports officiels / تقارير رسمية';
$currentPage = 'reports';

/* ===== دوال جلب البيانات ===== */
function ofGetEmployee($db, $id) {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0 AND " . schoolScopeWhere('school_id'));
    $stmt->execute([$id]);
    return $stmt->fetch();
}
function ofLatestSalary($db, $empId, $year = null) {
    $sql = "SELECT * FROM monthly_salaries WHERE employee_id = ? AND is_calculated = 1";
    $params = [$empId];
    if ($year) { $sql .= " AND year = ?"; $params[] = $year; }
    else {
        // النماذج تعرض «عن السنة المدرسية النشطة» — فضّل آخر راتب **ضمنها**:
        // السنوات المولّدة مسبقاً (المستقبلية) تكون بلا إضافي/مكافآت/نقل، فأخذ «آخر راتب
        // بالمطلق» كان يُظهر أصفاراً مع أن رواتب السنة المعروضة فيها القيم الصحيحة.
        $sy = activeSchoolYear();
        if ($sy === 'all' || !preg_match('/^\d{4}-\d{4}$/', (string)$sy)) $sy = currentSchoolYear();
        $stmt = $db->prepare($sql . " AND school_year = ? ORDER BY year DESC, month DESC LIMIT 1");
        $stmt->execute(array_merge($params, [$sy]));
        $row = $stmt->fetch();
        if ($row) return $row;
        // لا رواتب بالسنة النشطة → آخر راتب بالمطلق (متل السابق)
    }
    $sql .= " ORDER BY year DESC, month DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}
// تجميع سنوي لراتب أستاذ خلال السنة الدراسية (للنماذج الضريبية الإفرادية)
function ofAnnualAgg($db, $empId, $schoolYear) {
    $stmt = $db->prepare("SELECT
        SUM(base_plus_echelon_lbp) AS base,
        SUM(extra_lbp+prime_fixe_lbp+aide_complementaire_lbp) AS extra,
        SUM(extra_lbp+prime_fixe_lbp) AS extra_wage,
        SUM((extra_lbp+prime_fixe_lbp)/NULLIF(exchange_rate,0)) AS extra_wage_usd,
        SUM(aide_complementaire_lbp) AS aide,
        SUM(aide_complementaire_lbp/NULLIF(exchange_rate,0)) AS aide_usd,
        SUM(family_allowance_lbp) AS family,
        SUM(transport_lbp) AS transport,
        SUM(transport_lbp/NULLIF(exchange_rate,0)) AS transport_usd,
        SUM(taxable_base_lbp) AS taxable,
        SUM(income_tax_lbp) AS tax,
        SUM(cnss_amount_lbp) AS cnss_emp,
        SUM(school_cnss_8_lbp) AS cnss_school,
        SUM(caisse_amount_lbp+eoc_grade_lbp+school_eoc_6_lbp) AS eoc,
        COUNT(*) AS months
        FROM monthly_salaries WHERE employee_id = ? AND school_year = ?");
    $stmt->execute([$empId, $schoolYear]);
    return $stmt->fetch() ?: [];
}
$genderMark = fn($e,$g)=> '';

$emp = $employeeId ? ofGetEmployee($db, $employeeId) : null;
// النموذج الفردي يأخذ ترويسة مدرسة الموظف المختار (يدعم وضع عدة مدارس/الكل)
if ($emp) {
    $schStmt = $db->prepare("SELECT * FROM schools WHERE id = ? AND is_deleted = 0");
    $schStmt->execute([$emp['school_id']]);
    $school = $schStmt->fetch() ?: $school;
}
$famStatus = $emp['social_status'] ?? '';
$isMarried = strpos($famStatus, 'marie') === 0;

// النماذج الحكومية ثابتة التصميم — أخفِ Excel/Word العامّين (يطلعان متل بلوك)؛ محلّهما PDF رسمي
// + أزرار التعبئة من القالب الرسمي (للنماذج المدعومة مثل الضمان).
$exportOpts['no_office'] = true;

include __DIR__ . '/../includes/header.php';
echo officialFormStyles();

/* ===== شاشة اختيار موظف ===== */
if (in_array($form, $perEmployee) && !$emp):
    // فلترة حسب السنة الدراسية المختارة (دالة موحّدة): سنة محددة = موظفو تلك السنة فقط (إلهم رواتب فيها)
    [$yearWhere, $yearParams] = yearEmploymentFilter(activeSchoolYear());
    // تقييد لائحة الاختيار حسب النموذج: خاص بالموظف الإداري / بالملاك / بالأساتذة
    $onlyEmp = in_array($form, $employeeOnly) ? " AND employee_type='employe'"
             : (in_array($form, $titulaireOnly) ? " AND employee_type='enseignant_titulaire'"
             : (in_array($form, $teacherOnly) ? " AND employee_type IN ('enseignant_titulaire','enseignant_contractuel')" : ''));
    $list = $db->prepare("SELECT id, first_name_fr, last_name_fr, first_name_ar, last_name_ar, employee_code
                          FROM employees WHERE is_deleted = 0 AND " . schoolScopeWhere('school_id') . $onlyEmp . $yearWhere . "
                          ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $list->execute($yearParams);
    $emps = $list->fetchAll();
?>
    <div class="card no-print">
        <div class="card-header"><?php
            $ofTParts = array_map('trim', explode(' / ', $titles[$form] ?? ''));
            $ofTFr = []; $ofTAr = [];
            foreach ($ofTParts as $ofTp) { if ($ofTp === '') continue; if (preg_match('/\p{Arabic}/u', $ofTp)) $ofTAr[] = $ofTp; else $ofTFr[] = $ofTp; }
        ?><h3>
            <span dir="ltr"><i class="fas fa-user-check"></i> <?= e(implode(' / ', $ofTFr)) ?> — Choisir l'employé</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(implode(' / ', $ofTAr)) ?> — اختر الموظف</div>
        </h3></div>
        <div class="card-body">
            <form method="get" class="form-row cols-2">
                <input type="hidden" name="form" value="<?= e($form) ?>">
                <div class="form-group mb-0">
                    <label class="form-label">الموظف / Employé</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Choisir / اختر —</option>
                        <?php foreach ($emps as $x): ?>
                        <option value="<?= (int)$x['id'] ?>">
                            <?= e(trim(($x['first_name_fr'].' '.$x['last_name_fr'])) ?: ($x['first_name_ar'].' '.$x['last_name_ar'])) ?>
                            <?= $x['employee_code'] ? ' ('.e($x['employee_code']).')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary"><i class="fas fa-file-alt"></i> Afficher le formulaire / عرض النموذج</button>
                </div>
            </form>
        </div>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; exit;
endif;

echo officialFormToolbar();

/* ========================================================================
 *  النماذج الرسمية بالصورة الأصلية (طبق الأصل + البيانات فوقها)
 * ====================================================================== */
$govForms = ['cnss_employ','cnss_terminate','cnss_employ2','cnss_work','cnss_work_detail',
             'cnss_wife','cnss_annual','cnss_contrib_monthly','cnss_contrib_annual',
             'cnss_eos_invite','cnss_eos_wage','cnss_parent',
             'tax_register','tax_r4','tax_r5','tax_r6','tax_r6t','tax_r7','tax_r10'];
// إعادة بناء النموذج نفسه HTML طبق الأصل (لا لزق فوق صورة) — بطلب المستخدم 2026-06-14:
// «تنقل النموذج متل ما هو وتكتبه وتجهّزه متله تماماً فتجي المعلومات بمحلّها». وضع الصورة معطّل.
$imageForms = [];
if (in_array($form, $imageForms)) {
    // مفتاح صورة الخلفية (ر6/ت يستعمل صورة ر6)
    $bgKey = ($form === 'tax_r6t') ? 'tax_r6' : $form;

    // قيم مشتركة
    $natAr = ($emp && $emp['nationality'] === 'lebanese') ? 'لبنانية' : ($emp['nationality'] ?? '');
    $sal = $emp ? ofLatestSalary($db, $emp['id']) : null;
    $salary = $sal ? composedSalaryLbp($sal) : 0; // الراتب المركّب (يتبع «الراتب يشمل»)
    $data = [];

    if ($emp) {
        $common = [
            'school_name' => $school['name_ar'] ?? '',
            'address'     => $school['address'] ?? '',
            'emp_nbox'    => $school['nssf_employer_number'] ?? '',
            'school_finance' => $school['finance_number'] ?? '',
            'emp_reg'     => $emp['nssf_number'] ?? '',
            'nssf'        => $emp['nssf_number'] ?? '',
            'name'        => $emp['first_name_ar'] ?: $emp['first_name_fr'],
            'lastname'    => $emp['last_name_ar'] ?: $emp['last_name_fr'],
            'fullname'    => empFullNameAr($emp),
            'father'      => $emp['father_name_ar'] ?? '',
            'mother'      => trim(($emp['mother_first_name'] ?? '') . ' ' . ($emp['mother_last_name'] ?? '')),
            'birth'       => formatDate($emp['birth_date']) . '  ' . ($emp['birth_place'] ?? ''),
            'birth_date'  => formatDate($emp['birth_date']),
            'birth_place' => $emp['birth_place'] ?? '',
            'registry'    => $emp['civil_registry_number'] ?? '',
            'registry_place' => $emp['civil_registry_place'] ?? '',
            'nationality' => $natAr,
            'finance'     => $emp['finance_ministry_number'] ?? '',
            'hire'        => formatDate($emp['hire_date']),
            'leftdate'    => formatDate($emp['left_date_cnss'] ?: ($emp['left_date_finance'] ?: $emp['left_date_eoc'])),
            'children'    => (string)($emp['number_of_children'] ?? ''),
            'salary'      => $salary ? formatLBP($salary, false) : '',
            'job'         => employeeTypeLabel($emp['employee_type'], 'ar'),
            'sign_place'  => $school['ville'] ?? 'بيروت',
            'today'       => formatDate(date('Y-m-d')),
        ];
        // مجموع الأجور التاريخي + اشتراك نهاية الخدمة 8.5% (لنماذج 215A/207)
        $tw = (int)($db->query("SELECT COALESCE(SUM(base_plus_echelon_lbp+extra_lbp+prime_fixe_lbp),0)
                                FROM monthly_salaries WHERE employee_id=" . (int)$emp['id'] . " AND is_calculated=1")->fetchColumn());
        $common['total_wages'] = $tw ? formatLBP($tw, false) : '';
        $common['eos_amount']  = $tw ? formatLBP((int)round($tw * rateFrac('end_of_service_rate', $month, $year, 8.5)), false) : '';

        // بيانات ر6 السنوية (كشف إفرادي): مجاميع السنة الدراسية المختارة
        if ($form === 'tax_r6' || $form === 'tax_r6t') {
            $ag = ofAnnualAgg($db, $emp['id'], $schoolYear);
            $b6 = (int)($ag['base'] ?? 0); $e6 = (int)($ag['extra'] ?? 0);
            $f6 = (int)($ag['family'] ?? 0); $t6 = (int)($ag['transport'] ?? 0);
            $tax6 = (int)($ag['tax'] ?? 0); $txb6 = (int)($ag['taxable'] ?? 0);
            [$gy6] = schoolYearToYears($schoolYear);
            $common['r6_year']    = (string)$gy6;
            $common['r6_base']    = $b6 ? formatLBP($b6, false) : '';
            $common['r6_extra']   = $e6 ? formatLBP($e6, false) : '';
            $common['r6_family']  = $f6 ? formatLBP($f6, false) : '';
            $common['r6_trans']   = $t6 ? formatLBP($t6, false) : '';
            $common['r6_tot1']    = formatLBP($b6 + $e6 + $f6 + $t6, false);
            $common['r6_tot2']    = formatLBP($f6 + $t6, false);
            $common['r6_tot3']    = formatLBP($b6 + $e6, false);
            $common['r6_net']     = formatLBP($txb6, false);
            $common['r6_tax']     = $tax6 ? formatLBP($tax6, false) : '';
        }
        $data = $common;
    } else {
        // نماذج مستوى المؤسسة (مجاميع المدرسة)
        [$gy] = schoolYearToYears($schoolYear);
        $data = [
            'school_name'    => $school['name_ar'] ?? '',
            'school_finance' => $school['finance_number'] ?? '',
            'emp_nbox'       => $school['nssf_employer_number'] ?? '',
            'address'        => $school['address'] ?? '',
            'sign_place'     => $school['ville'] ?? 'بيروت',
            'year'           => (string)$gy,
        ];
        $tq = $db->prepare("SELECT COUNT(DISTINCT employee_id) n,
                SUM(base_plus_echelon_lbp+extra_lbp+prime_fixe_lbp+aide_complementaire_lbp) gross,
                SUM(transport_lbp) transport,
                SUM(taxable_base_lbp) taxable, SUM(income_tax_lbp) tax
            FROM monthly_salaries ms WHERE ms.school_year=? AND " . schoolScopeWhere('ms.school_id'));
        $tq->execute([$schoolYear]); $tg = $tq->fetch();
        $w = (int)($tg['taxable'] ?? 0);
        // اشتراكات الضمان من الأعمدة المخزّنة لكل فرع (لا من وعاء الضريبة × النسبة):
        // وعاء الضمان يُشتقّ من اشتراك المرض ٣٪ (يخضع له الجميع) فيبقى متّسقاً مع الحد الأقصى.
        $bSql = "SELECT COUNT(DISTINCT employee_id) n, SUM(cnss_amount_lbp) m3, SUM(school_cnss_8_lbp) m8,
                    SUM(school_end_of_service_8_5_lbp) eos, SUM(school_family_comp_6_lbp) fam6
                 FROM monthly_salaries ms WHERE ";
        if ($form === 'cnss_contrib_monthly') {
            $bq = $db->prepare($bSql . "ms.month=? AND ms.year=? AND " . schoolScopeWhere('ms.school_id'));
            $bq->execute([$month, $year]);
        } else {
            $bq = $db->prepare($bSql . "ms.school_year=? AND " . schoolScopeWhere('ms.school_id'));
            $bq->execute([$schoolYear]);
        }
        $bb = $bq->fetch();
        $cnM = (int)($bb['m3'] ?? 0) + (int)($bb['m8'] ?? 0);
        $cnE = (int)($bb['eos'] ?? 0); $cnF = (int)($bb['fam6'] ?? 0);
        if ((int)($bb['m3'] ?? 0)) $w = (int)round($bb['m3'] / rateFrac('cnss_employee_rate', $month, $year, 3));
        if ($form === 'cnss_contrib_monthly') $tg['n'] = $bb['n'] ?? 0;
        $data['count']      = (string)(int)($tg['n'] ?? 0);
        $data['gross']      = formatLBP((int)($tg['gross'] ?? 0), false);
        $data['transport']  = formatLBP((int)($tg['transport'] ?? 0), false);
        $data['net']        = formatLBP((int)(($tg['gross'] ?? 0) - ($tg['transport'] ?? 0)), false);
        $data['taxable']    = formatLBP((int)($tg['taxable'] ?? 0), false);
        $data['tax']        = formatLBP((int)($tg['tax'] ?? 0), false);
        $data['wages']      = formatLBP($w, false);
        $data['cn_maladie'] = formatLBP($cnM, false);
        $data['cn_eos']     = formatLBP($cnE, false);
        $data['cn_family']  = formatLBP($cnF, false);
        $data['cn_total']   = formatLBP($cnM + $cnE + $cnF, false);
    }
    // ===== صفوف الجداول الديناميكية =====
    $extra = [];
    if ($form === 'cnss_annual') {
        // أجور كل شهر تحت الفروع الثلاثة (المرض/العائلية/نهاية الخدمة)
        // وعاء الضمان لكل شهر = يُشتقّ من اشتراك المرض ٣٪ المخزّن (لا من وعاء الضريبة)
        $mq = $db->prepare("SELECT month, SUM(cnss_amount_lbp) m3 FROM monthly_salaries ms
                            WHERE ms.school_year=? AND " . schoolScopeWhere('ms.school_id') . " GROUP BY month");
        $mq->execute([$schoolYear]);
        $bym = []; foreach ($mq->fetchAll() as $r) $bym[(int)$r['month']] = (int)$r['m3'] ? (int)round($r['m3']/rateFrac('cnss_employee_rate', (int)$r['month'], $year, 3)) : 0;
        $ys = 24.8; $dy = 1.72; $cols = [17, 28.5, 40];
        foreach ([1,2,3,4,5,6,7,8,9,10,11,12] as $i => $mn) {
            if (empty($bym[$mn])) continue;
            foreach ($cols as $cx) $extra[] = ['x'=>$cx, 'y'=>$ys+$i*$dy, 'val'=>formatLBP($bym[$mn],false), 's'=>1.7];
        }
        $tot = array_sum($bym);
        foreach ($cols as $cx) $extra[] = ['x'=>$cx, 'y'=>$ys+13*$dy, 'val'=>formatLBP($tot,false), 's'=>1.8];
    }
    elseif ($form === 'tax_r7') {
        // لائحة تاركي العمل خلال السنة
        [$y1f,$y2f] = schoolYearToYears($schoolYear);
        $st = "$y1f-10-01"; $en = "$y2f-09-30";
        $q = $db->prepare("SELECT * FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . "
            AND ((left_date_cnss BETWEEN ? AND ?) OR (left_date_finance BETWEEN ? AND ?) OR (left_date_eoc BETWEEN ? AND ?))
            ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
        $q->execute([$st,$en,$st,$en,$st,$en]);
        $lrows = $q->fetchAll();
        $ys = 26.0; $dy = 3.62;
        foreach ($lrows as $i => $r) {
            if ($i > 15) break;
            $y = $ys + $i*$dy;
            $left = $r['left_date_cnss'] ?: ($r['left_date_finance'] ?: $r['left_date_eoc']);
            $nm = trim(($r['first_name_ar'].' '.$r['father_name_ar'].' '.$r['last_name_ar'])) ?: ($r['first_name_fr'].' '.$r['last_name_fr']);
            $extra[] = ['x'=>72,'y'=>$y,'val'=>$nm,'s'=>2.1];
            $extra[] = ['x'=>54,'y'=>$y,'val'=>$r['finance_ministry_number'],'s'=>2.0];
            $extra[] = ['x'=>40,'y'=>$y,'val'=>$r['nssf_number'],'s'=>2.0];
            $extra[] = ['x'=>26,'y'=>$y,'val'=>formatDate($r['hire_date']),'s'=>2.0];
            $extra[] = ['x'=>12,'y'=>$y,'val'=>formatDate($left),'s'=>2.0];
        }
    }
    elseif ($form === 'cnss_work_detail' && $emp) {
        // إفادة عمل (CNSS 2M): جدول الأشهر الستة (عمود «الشهر» فقط) + قسم آخر 3 أشهر (الأجر المدفوع)
        $q = $db->prepare("SELECT month, year, (base_plus_echelon_lbp+extra_lbp+prime_fixe_lbp) p
                           FROM monthly_salaries WHERE employee_id=? AND is_calculated=1
                           ORDER BY year DESC, month DESC LIMIT 6");
        $q->execute([$emp['id']]);
        $rowsP = $q->fetchAll();
        $six = array_reverse($rowsP);          // الأقدم→الأحدث للجدول (6 أشهر)
        $last3 = array_slice($rowsP, 0, 3);     // أحدث 3 أشهر لقسم الأجر المدفوع
        $last3 = array_reverse($last3);
        // عمود «الشهر» في الجدول (العمود الأقصى يميناً) للأشهر الستة
        $ys = 33.0; $dy = 3.45;
        foreach ($six as $i => $p) {
            $extra[] = ['x'=>93, 'y'=>$ys + $i*$dy, 'val'=>monthName((int)$p['month'],'ar'), 's'=>1.9];
        }
        // قسم «مفصّلاً كما يلي»: 3 أسطر (شهر ........ الأجر المدفوع ........ ل.ل)
        $yw = 59.6; $dw = 2.5;
        foreach ($last3 as $i => $p) {
            $y = $yw + $i*$dw;
            $extra[] = ['x'=>58, 'y'=>$y, 'val'=>monthName((int)$p['month'],'ar').' '.$p['year'], 's'=>2.0];
            $extra[] = ['x'=>22, 'y'=>$y, 'val'=>formatLBP((int)$p['p'],false), 's'=>2.0];
        }
        // المبلغ الإجمالي خلال الثلاثة أشهر الأخيرة (سطر «قدره»)
        $sum3 = 0; foreach ($last3 as $p) $sum3 += (int)$p['p'];
        $extra[] = ['x'=>30, 'y'=>57.0, 'val'=>formatLBP($sum3,false), 's'=>2.0];
    }
    elseif ($form === 'tax_r4' && $emp) {
        // جدول العيال (صفحة 2): الزوج/الزوجة + الأولاد
        $ys = 16.0; $dy = 7.3;
        $deps = [];
        if ($isMarried && empty($emp['spouse_works'])) $deps[] = 'الزوج / الزوجة';
        for ($cN=1; $cN <= (int)($emp['number_of_children'] ?? 0); $cN++) $deps[] = 'ولد';
        foreach ($deps as $i => $rel) {
            if ($i > 5) break;
            $extra[] = ['x'=>62,'y'=>$ys+$i*$dy,'val'=>$rel,'s'=>2.4];
        }
    }

    echo renderOfficialBg($bgKey, $data, $extra);
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ========================================================================
 *  (احتياطي HTML — يُستعمل فقط لو ما في صورة خلفية)
 *  وزارة المالية — النماذج الخضراء
 * ====================================================================== */
if ($form === 'tax_r6' || $form === 'tax_r6t'):
    $isAmend = ($form === 'tax_r6t');
    $a = ofAnnualAgg($db, $emp['id'], $schoolYear);
    [$gy1] = schoolYearToYears($schoolYear);
    $base=(int)($a['base']??0); $extra=(int)($a['extra']??0); $fam=(int)($a['family']??0);
    $extraWage=(int)($a['extra_wage']??0); $aide=(int)($a['aide']??0); // عمودان منفصلان (موحّد مع باقي التقارير)
    $trans=(int)($a['transport']??0); $taxable=(int)($a['taxable']??0); $tax=(int)($a['tax']??0);
    $totalIncome=$base+$extra+$fam+$trans; $nonTax=$fam+$trans;
    // صفوف الرموز: [رمز, شرح, إجمالي, غير خاضع, خاضع]
    // «الأجر الإضافي» (extra+prime) في الرمز ١٢٠، و«مكافأة ومساعدة» (aide) في الرمز ٣٠٠ —
    // المجموع (base+extraWage+aide) = base+extra السابق نفسه (لا يتغيّر أي رقم خاضع/ضريبة).
    $rows = [
        ['١٠٠','الراتب الأساسي/الأجور اليومية',$base,0,$base],
        ['١١٠','بدل تمثيل','','',''],
        ['١٢٠','الأجر الإضافي (مكافآت وعمولات وساعات إضافية)',$extraWage,0,$extraWage],
        ['١٣٠','تعويض عائلي عن الزوجة', $isMarried?$fam:0, $isMarried?$fam:0, ''],
        ['١٤٠','تعويض عائلي عن الأولاد', $isMarried?'':0, '', ''],
        ['١٥٠','تعويضات نقل وانتقال',$trans,$trans,''],
        ['١٦٠','بدل سيارة','','',''],['١٧٠','بدل سكن','','',''],
        ['١٨٠','بدل طعام','','',''],['١٩٠','بدل ملبس','','',''],
        ['٢٠٠','تعويض صندوق','','',''],['٢١٠','تأمينات صحية','','',''],
        ['٢٢٠','منح تعليم','','',''],['٢٣٠','منح زواج','','',''],
        ['٢٤٠','منح ولادة','','',''],['٢٥٠','مساعدات مرضية','','',''],
        ['٢٦٠','مساعدات وفاة','','',''],['٣٠٠','مكافأة ومساعدة (منح وتقديمات أخرى)',$aide,0,$aide],
    ];
?>
<div class="official-doc mof-form rtl" id="ppExportArea">
    <div class="mof-head">
        <div class="mof-gov">الجمهورية اللبنانية<br>وزارة المالية<br>مديرية المالية العامة<br>مديرية الواردات – ضريبة الرواتب والأجور</div>
        <div class="mof-titles"><div class="mof-title"><?= $isAmend?'تعديل كشف سنوي إفرادي':'كشف سنوي إفرادي' ?><br>بإجمالي إيرادات المستخدم/الأجير</div></div>
        <div class="mof-code">ر<?= $isAmend?' ٦/ت':' ٦' ?></div>
    </div>
    <div class="mof-body">
        <div class="info-grid">
            <div><span class="k">اسم الشركة/المؤسسة:</span> <?= fillVal($school['name_ar']) ?></div>
            <div><span class="k">عن أعمال سنة:</span> <?= fillVal((int)$gy1) ?></div>
            <div><span class="k">رقم التسجيل (وزارة المالية):</span> <?= digitBoxes($school['finance_number'],10) ?></div>
            <div><span class="k">عدد الأجراء/المستخدمين:</span> <?= fillVal('') ?></div>
        </div>
        <div class="doc-section">إسم الأجير/المستخدم</div>
        <div class="info-grid">
            <div><span class="k">الاسم:</span> <?= fillVal($emp['first_name_ar'] ?: $emp['first_name_fr']) ?></div>
            <div><span class="k">إسم الأب:</span> <?= fillVal($emp['father_name_ar']) ?></div>
            <div><span class="k">الشهرة:</span> <?= fillVal($emp['last_name_ar'] ?: $emp['last_name_fr']) ?></div>
            <div><span class="k">رقم التسجيل الشخصي:</span> <?= digitBoxes($emp['finance_ministry_number'],10) ?></div>
            <div><span class="k">نوع العمل:</span> <?= fillVal(employeeTypeLabel($emp['employee_type'],'ar')) ?></div>
            <div><span class="k">نوع الأجر:</span> <?= cbox('شهري',true) ?><?= cbox('يومي') ?><?= cbox('بالساعة') ?></div>
            <div><span class="k">الوضع العائلي:</span> <?= cbox('أعزب',!$isMarried) ?><?= cbox('متزوج',$isMarried) ?><?= cbox('أرمل') ?><?= cbox('مطلق') ?></div>
            <div><span class="k">عدد الأولاد:</span> <?= fillVal($emp['number_of_children']) ?></div>
            <div class="full"><span class="k">العنوان:</span> <?= fillVal(empAddress($emp)) ?></div>
        </div>

        <table class="code-table">
            <thead><tr>
                <th class="code-cell">الرمز</th><th>الشرح</th>
                <th>إجمالي الإيرادات (١)</th><th>الإيرادات غير الخاضعة (٢)</th><th>الإيرادات الخاضعة (٣)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="code-cell"><?= $r[0] ?></td>
                    <td class="lbl"><?= e($r[1]) ?></td>
                    <td class="num"><?= $r[2]!==''?formatLBP($r[2],false):'' ?></td>
                    <td class="num"><?= $r[3]!==''?formatLBP($r[3],false):'' ?></td>
                    <td class="num"><?= $r[4]!==''?formatLBP($r[4],false):'' ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="sum"><td class="code-cell">٣١٠</td><td class="lbl">المجموع</td>
                    <td class="num"><?= formatLBP($totalIncome,false) ?></td>
                    <td class="num"><?= formatLBP($nonTax,false) ?></td>
                    <td class="num"><?= formatLBP($base+$extra,false) ?></td></tr>
                <tr><td class="code-cell">٣٣٠</td><td class="lbl" colspan="3">ينزل: تنزيل عائلي</td><td class="num"><?= fillVal('') ?></td></tr>
                <tr><td class="code-cell">٣٤٠</td><td class="lbl" colspan="3">تنزيلات أخرى</td><td class="num"><?= fillVal('') ?></td></tr>
                <tr class="gray"><td class="code-cell">٣٥٠</td><td class="lbl" colspan="3">صافي الإيرادات الخاضعة</td><td class="num"><strong><?= formatLBP($taxable,false) ?></strong></td></tr>
                <tr class="sum"><td class="code-cell">٣٦٠</td><td class="lbl" colspan="3">الضريبة السنوية المتوجبة</td><td class="num"><strong><?= formatLBP($tax,false) ?></strong></td></tr>
            </tbody>
        </table>
        <div class="sign-row"><?= signatureBox('توقيع ممثل المؤسسة وخاتمها', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
        <div class="doc-note">* توضع علامة × في المربع المناسب. ** العدد يشمل الزوجة في حال كانت لا تعمل والأولاد الذين هم على عاتق المستخدم/الأجير.</div>
    </div>
</div>

<?php elseif ($form === 'tax_register'): // ر3 طلب تسجيل ?>
<div class="official-doc mof-form rtl" id="ppExportArea">
    <div class="mof-head">
        <div class="mof-gov">الجمهورية اللبنانية<br>وزارة المالية<br>مديرية المالية العامة<br>مديرية الواردات – ضريبة الرواتب والأجور</div>
        <div class="mof-titles"><div class="mof-title">طلب تسجيل مستخدم/أجير جديد</div></div>
        <div class="mof-code">ر ٣</div>
    </div>
    <div class="mof-body">
        <div class="info-grid">
            <div><span class="k">إسم الشركة/المؤسسة:</span> <?= fillVal($school['name_ar']) ?></div>
            <div><span class="k">رقم تسجيل المؤسسة:</span> <?= digitBoxes($school['finance_number'],10) ?></div>
        </div>
        <div class="doc-section">تعريف المستخدم/الأجير</div>
        <div class="info-grid">
            <div class="full"><span class="k">هل لديه رقم مالي شخصي؟</span> <?= cbox('نعم',(bool)$emp['finance_ministry_number']) ?><?= cbox('كلا',!$emp['finance_ministry_number']) ?> &nbsp; في حال نعم: <?= digitBoxes($emp['finance_ministry_number'],10) ?></div>
            <div><span class="k">١. الإسم:</span> <?= fillVal($emp['first_name_ar'] ?: $emp['first_name_fr']) ?></div>
            <div><span class="k">٢. الشهرة:</span> <?= fillVal($emp['last_name_ar'] ?: $emp['last_name_fr']) ?></div>
            <div><span class="k">٣. إسم الأب:</span> <?= fillVal($emp['father_name_ar']) ?></div>
            <div><span class="k">٤. إسم الأم وشهرتها قبل الزواج:</span> <?= fillVal(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
            <div><span class="k">٥. الجنس:</span> <?= cbox('ذكر') ?><?= cbox('أنثى') ?></div>
            <div><span class="k">٦. الجنسية:</span> <?= fillVal($emp['nationality']==='lebanese'?'لبنانية':$emp['nationality']) ?></div>
            <div><span class="k">٧. محل الولادة:</span> <?= fillVal($emp['birth_place']) ?></div>
            <div><span class="k">٨. تاريخ الولادة:</span> <?= fillVal(formatDate($emp['birth_date'])) ?></div>
            <div><span class="k">٩. رقم السجل:</span> <?= fillVal($emp['civil_registry_number']) ?></div>
            <div><span class="k">١٠. مكان السجل:</span> <?= fillVal($emp['civil_registry_place']) ?></div>
            <div><span class="k">١٢. الوضع العائلي:</span> <?= cbox('أعزب',!$isMarried) ?><?= cbox('متزوج',$isMarried) ?><?= cbox('أرمل') ?><?= cbox('مطلق') ?></div>
            <div><span class="k">١٣. عدد الأولاد:</span> <?= fillVal($emp['number_of_children']) ?></div>
            <div><span class="k">١٤. تاريخ ابتداء العمل:</span> <?= fillVal(formatDate($emp['hire_date'])) ?></div>
            <div><span class="k">١٦. نوع الأجر:</span> <?= cbox('شهري',true) ?><?= cbox('يومي') ?><?= cbox('بالساعة') ?></div>
        </div>
        <div class="doc-section">عنوان السكن</div>
        <div class="info-grid">
            <div><span class="k">محافظة:</span> <?= fillVal($emp['gouvernorat']) ?></div>
            <div><span class="k">قضاء:</span> <?= fillVal($emp['district']) ?></div>
            <div><span class="k">منطقة – بلدة:</span> <?= fillVal($emp['ville']) ?></div>
            <div><span class="k">الشارع:</span> <?= fillVal($emp['rue']) ?></div>
            <div><span class="k">المبنى:</span> <?= fillVal($emp['immeuble']) ?></div>
            <div><span class="k">الطابق:</span> <?= fillVal($emp['etage']) ?></div>
            <div><span class="k">هاتف:</span> <?= fillVal($emp['phone1'] ?: $emp['phone2']) ?></div>
            <div><span class="k">البريد الإلكتروني:</span> <?= fillVal($emp['email']) ?></div>
        </div>
        <div class="sign-row"><?= signatureBox('إسم صاحب العمل والصفة والتوقيع', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
    </div>
</div>

<?php elseif ($form === 'tax_r5' || $form === 'tax_r10'):
    // ر5 سنوي / ر10 دوري — مستوى المؤسسة (مجاميع)
    $isR10 = ($form === 'tax_r10');
    // عدد المستخدمين والأجراء والمجاميع = نفس مصدر بيان صندوق التعويضات (yearEmploymentFilter):
    // يستبعد المحذوفين والتاركين وصفوف الأشباح، فلا يُحتسب موظفون سابقون من السنوات السابقة.
    [$yf,$yp] = yearEmploymentFilter($schoolYear, 'e.');
    $q = $db->prepare("SELECT COUNT(DISTINCT e.id) n,
            SUM(ms.base_plus_echelon_lbp+ms.extra_lbp+ms.prime_fixe_lbp+ms.aide_complementaire_lbp) gross,
            SUM(ms.extra_lbp+ms.prime_fixe_lbp) extra_wage, SUM(ms.aide_complementaire_lbp) aide,
            SUM((ms.extra_lbp+ms.prime_fixe_lbp)/NULLIF(ms.exchange_rate,0)) extra_wage_usd, SUM(ms.aide_complementaire_lbp/NULLIF(ms.exchange_rate,0)) aide_usd,
            SUM(ms.transport_lbp) transport,
            SUM(ms.transport_lbp/NULLIF(ms.exchange_rate,0)) transport_usd,
            SUM(ms.family_allowance_lbp) family,
            SUM(ms.cnss_amount_lbp) cnss, SUM(ms.caisse_amount_lbp) caisse, SUM(ms.eoc_grade_lbp) eoc,
            SUM(ms.taxable_base_lbp) taxable, SUM(ms.income_tax_lbp) tax
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND e.tax_subject=1" . $yf . " AND ms.school_year=? AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id'));
    $q->execute(array_merge($yp, [$schoolYear]));
    $g = $q->fetch();
    [$gy1] = schoolYearToYears($schoolYear);
    // 🔴 إصلاح 2026-07-30 (كان التصريح خاطئاً بمقدار النقل ≈34.7 مليار على كل المدارس):
    // كان ١٠٠/١٢٠ «مجموع المبالغ المدفوعة» يُطبَع **بلا** النقل ثم يُنزَّل النقل في ١٣٠،
    // فيخرج ١٦٠ ناقصاً النقل مرّةً أخرى — تنزيلٌ لشيء لم يُضَف أصلاً. والآن يترابط السطور:
    //   ١٢٠ (كل المدفوع مع النقل) − ١٣٠ النقل − ١٥٠ (الصندوق ودرجة الصندوق، وهي التي
    //   يحسمها المحرّك فعلاً من أساس الضريبة) = ١٦٠ ، وهي تساوي مجموع taxable_base المخزَّن
    //   بالضبط (مُتحقَّق: فرق صفر). ثم ١٦٠ − ١٧٠ التنزيل العائلي/الشخصي = ١٨٠ الخاضع للضريبة
    //   الذي حُسبت منه الضريبة ١٩٠ عبر الشطور. (الضمان لا يُحسَم من أساس الضريبة فلا يُدرَج.)
    $gross=(int)($g['gross']??0); $trans=(int)($g['transport']??0);
    $paid   = $gross + $trans;                                       // ١٠٠/١٢٠ كل ما دُفع فعلاً
    $other  = (int)($g['caisse']??0) + (int)($g['eoc']??0);           // ١٥٠ ما يُحسم من أساس الضريبة
    $net    = $paid - $trans - $other;                               // ١٦٠ = مجموع الأساس الخاضع قبل التنزيل
    $tax    = (int)($g['tax']??0);
    // ١٧٠ التنزيل العائلي/الشخصي: يُمنح **مرّة واحدة سنوياً لكل موظف** حسب وضعه الاجتماعي
    // والتاريخ الساري (نفس مصدر المحرّك family_tax_deductions)، ومحدود بأساسه الخاضع.
    $qDed = $db->prepare("SELECT e.id, e.social_status, SUM(ms.taxable_base_lbp) tb
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND e.tax_subject=1" . $yf . " AND ms.school_year=?
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id') . "
        GROUP BY e.id, e.social_status");
    $qDed->execute(array_merge($yp, [$schoolYear]));
    $dedAsOf = ($gy1 + 1) . '-01-01'; // منتصف السنة الدراسية (كانون الثاني) = التنزيل الساري للسنة المصرَّح عنها
    $dedStmt = $db->prepare("SELECT annual_deduction FROM family_tax_deductions
                              WHERE social_status = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1");
    $exempt = 0; $dedCache = [];
    foreach ($qDed->fetchAll() as $de) {
        $ss = (string)$de['social_status'];
        if (!array_key_exists($ss, $dedCache)) { $dedStmt->execute([$ss, $dedAsOf]); $dedCache[$ss] = (float)($dedStmt->fetchColumn() ?: 0); }
        $exempt += (int)min($dedCache[$ss], (float)$de['tb']);
    }
    $taxable = max(0, $net - $exempt);                               // ١٨٠
    $rows = [
        ['١٠٠','الرواتب وملحقاتها',$paid],
        ['١١٠','المنافع النقدية والعينية',0],
        ['١٢٠','مجموع المبالغ المدفوعة',$paid],
        ['١٣٠','ينزل: تعويضات نقل وانتقال',$trans],
        ['١٤٠','تعويضات تمثيل',0],
        ['١٥٠','ينزل: صندوق التعويضات ودرجة الصندوق',$other],
        ['١٦٠','المبالغ الصافية',$net],
        ['١٧٠','التنزيل العائلي',$exempt],
        ['١٨٠','الرواتب والأجور الخاضعة للضريبة',$taxable],
        ['١٩٠','الضريبة المتوجبة',$tax],
    ];
?>
<div class="official-doc mof-form rtl" id="ppExportArea">
    <div class="mof-head">
        <div class="mof-gov">الجمهورية اللبنانية<br>وزارة المالية<br>مديرية المالية العامة<br>مديرية الواردات – ضريبة الرواتب والأجور</div>
        <div class="mof-titles"><div class="mof-title"><?= $isR10?'بيان دوري بتأدية ضريبة الرواتب والأجور':'تصريح سنوي عن ضريبة الدخل على الرواتب والأجور' ?></div></div>
        <div class="mof-code">ر<?= $isR10?' ١٠':' ٥' ?></div>
    </div>
    <div class="mof-body">
        <div class="info-grid">
            <div><span class="k">إسم الشركة/المؤسسة:</span> <?= fillVal($school['name_ar']) ?></div>
            <div><span class="k">رقم التسجيل:</span> <?= digitBoxes($school['finance_number'],10) ?></div>
            <div><span class="k">السنة المالية:</span> <?= fillVal((int)$gy1) ?></div>
            <div><span class="k">عدد المستخدمين والأجراء:</span> <?= fillVal((int)($g['n']??0)) ?></div>
            <div class="full"><span class="k">عنوان المركز الرئيسي:</span> <?= fillVal($school['address']) ?></div>
        </div>
        <table class="code-table">
            <thead><tr>
                <th class="code-cell">الرمز</th><th>ضريبة الباب الثاني</th>
                <th>رئيس وأعضاء مجلس الإدارة (١)</th><th>المستخدمون والأجراء (٢)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $gr = in_array($r[0],['١٢٠','١٦٠','١٨٠','١٩٠']); ?>
                <tr class="<?= $gr?'gray':'' ?>">
                    <td class="code-cell"><?= $r[0] ?></td>
                    <td class="lbl"><?= e($r[1]) ?></td>
                    <td class="num"></td>
                    <td class="num"><?= formatLBP($r[2],false) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="info-grid" style="margin-top:8px">
            <div><span class="k">منها: الأجر الإضافي:</span> <strong><?= dualFromUsd((int)($g['extra_wage']??0), (float)($g['extra_wage_usd']??0)) ?></strong></div>
            <div><span class="k">منها: مكافأة ومساعدة:</span> <strong><?= dualFromUsd((int)($g['aide']??0), (float)($g['aide_usd']??0)) ?></strong></div>
            <div><span class="k">إجمالي الرواتب الخاضعة للضريبة:</span> <strong><?= formatLBP($taxable) ?></strong></div>
            <div><span class="k">إجمالي الضريبة المتوجبة:</span> <strong><?= formatLBP($tax) ?></strong></div>
        </div>
        <div class="doc-section">إفادة</div>
        <p>أنا الموقّع أدناه أشهد بصدق وصحة المعلومات التي ينطوي عليها هذا التصريح.</p>
        <div class="sign-row"><?= signatureBox('الإسم والصفة والتوقيع', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
    </div>
</div>

<?php
/* ========================================================================
 *  الضمان الاجتماعي — النماذج الزرقاء
 * ====================================================================== */
elseif ($form === 'cnss_employ' || $form === 'cnss_terminate'):
    $isTerm = ($form === 'cnss_terminate');
    $sal = ofLatestSalary($db, $emp['id']);
    $salary = $sal ? cnssSubjectWageLbp($sal, $emp) : 0; // يتبع زرّ الخضوع بملف الأستاذ
    $exW = $sal ? extraWageLbp($sal) : 0; $aid = $sal ? aideCompLbp($sal) : 0;
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي</div>
    <div class="cnss-title">إعـــلام<br><span style="font-size:12pt"><?= $isTerm?'عن ترك أجير عمله في المؤسسة':'عن استخدام أجير' ?></span></div>

    <div class="doc-p">إن صاحب العمل الموقّع أدناه،</div>
    <div class="fline"><span class="lbl">المسؤول عن مؤسسة</span> <?= fval($school['name_ar']) ?></div>
    <div class="fline"><span class="lbl">المسجّلة في الصندوق الوطني للضمان الاجتماعي تحت رقم :</span> <?= digitBoxes($school['nssf_employer_number'],8) ?></div>
    <div class="fline"><span class="lbl">سجل تجاري : مكان</span> <?= fval('','g') ?> <span class="lbl">رقم</span> <?= fval('','g') ?> <span class="lbl">هاتف</span> <?= fval($school['phone'],'g') ?></div>
    <div class="fline"><span class="lbl">وعنوانها الكامل :</span> <?= fval($school['address'],'lg') ?></div>
    <div class="doc-p">يصرّح بأن الأجير المبيّنة هويته فيما يلي:</div>
    <?php $hasReg = !empty(trim((string)$emp['nssf_number'])); ?>
    <div class="fline">
        <?= fopt('الأجير مسجّل سابقاً في الصندوق تحت الرقم', '', $hasReg) ?> <?= $hasReg ? digitBoxes($emp['nssf_number'],8) : '<span class="val g">&nbsp;</span>' ?>
    </div>
    <div class="fline"><?= fopt('الأجير غير مسجّل سابقاً في الصندوق', '', !$hasReg) ?></div>

    <div class="fline"><span class="lbl">الجنس :</span> <?= fopt('ذكر',1) ?> <?= fopt('أنثى',2) ?></div>
    <div class="fline"><span class="lbl">اسم الأجير</span> <?= fval($emp['first_name_ar'] ?: $emp['first_name_fr']) ?> <span class="lbl">الشهرة</span> <?= fval($emp['last_name_ar'] ?: $emp['last_name_fr']) ?> <span class="lbl">رقمه في الصندوق</span> <?= fval($emp['nssf_number'],'g') ?></div>
    <div class="fline"><span class="lbl">اسم الأب</span> <?= fval($emp['father_name_ar']) ?> <span class="lbl">اسم الأم وشهرتها</span> <?= fval(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
    <div class="fline"><span class="lbl">تاريخ ومحل الولادة</span> <?= fval(formatDate($emp['birth_date']).' - '.$emp['birth_place']) ?> <span class="lbl">رقم السجل</span> <?= fval($emp['civil_registry_number'],'g') ?></div>
    <div class="fline"><span class="lbl">الجنسية</span> <?= fval($emp['nationality']==='lebanese'?'لبنانية':$emp['nationality'],'g') ?></div>
    <div class="fline"><span class="lbl">الوضع العائلي :</span> <?= fopt('أعزب',1,!$isMarried) ?> <?= fopt('متأهل',2,$isMarried) ?> <?= fopt('أرمل',3) ?> <?= fopt('مطلق',4) ?> <?= fopt('هاجر',5) ?></div>

    <?php if (!$isTerm): ?>
    <div class="fline"><span class="lbl">استُخدم فيها منذ</span> <?= fval(formatDate($emp['hire_date'])) ?> <span class="lbl">عدد ساعات العمل في الشهر</span> <?= fval('','g') ?></div>
    <div class="fline"><span class="lbl">الدوام :</span> <?= fopt('كامل',1,true) ?> <?= fopt('جزئي',2) ?></div>
    <div class="fline"><span class="lbl">عمل الأجير الحالي</span> <?= fval(employeeTypeLabel($emp['employee_type'],'ar')) ?> <span class="lbl">إن الراتب الحالي</span> <?= fval($salary?formatLBP($salary,false):'','g') ?> <span class="lbl">ل.ل</span></div>
    <div class="fline"><span class="lbl">منها الأجر الإضافي</span> <?= fval($exW?formatLBP($exW,false):'—','g') ?> <span class="lbl">ل.ل — ومكافأة ومساعدة</span> <?= fval($aid?formatLBP($aid,false):'—','g') ?> <span class="lbl">ل.ل</span></div>
    <div class="fline"><span class="lbl">طريقة دفع الأجر :</span> <?= fopt('شهري',1,true) ?> <?= fopt('اسبوعي',2) ?> <?= fopt('يومي',3) ?> <?= fopt('لقاء عمولة',4) ?> <?= fopt('على الإنتاج',5) ?></div>
    <div class="fline"><span class="lbl">هل يعمل حسب معرفتك لدى صاحب عمل آخر :</span> <?= fopt('نعم',1) ?> <?= fopt('كلا',2) ?></div>
    <?php else: ?>
    <div class="fline"><span class="lbl">استُخدم فيها منذ</span> <?= fval(formatDate($emp['hire_date'])) ?></div>
    <div class="fline"><span class="lbl">ترك العمل بها منذ</span> <?= fval(formatDate($emp['left_date_cnss'])) ?></div>
    <div class="fline"><span class="lbl">سبب ترك العمل :</span> <?= fopt('استقالة',1) ?> <?= fopt('بلوغ السن',2) ?> <?= fopt('عجز',3) ?> <?= fopt('زواج',4) ?> <?= fopt('وفاة',5) ?> <?= fopt('هجرة',6) ?> <?= fopt('عمل آخر',7) ?></div>
    <div class="fline"><span class="lbl">إن راتب الأجير بتاريخ ترك العمل هو</span> <?= fval($salary?formatLBP($salary,false):'') ?> <span class="lbl">ل.ل</span></div>
    <div class="fline"><span class="lbl">منها الأجر الإضافي</span> <?= fval($exW?formatLBP($exW,false):'—','g') ?> <span class="lbl">ل.ل — ومكافأة ومساعدة</span> <?= fval($aid?formatLBP($aid,false):'—','g') ?> <span class="lbl">ل.ل</span></div>
    <?php endif; ?>

    <div class="sign-row">
        <?= signatureBox('اسم وتوقيع الأجير', '', '') ?>
        <?= signatureBox('ختم المؤسسة واسم وتوقيع الشخص المسؤول', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?>
    </div>
</div>

<?php elseif ($form === 'cnss_work'): // 489 إفادة عمل
    $sal = ofLatestSalary($db, $emp['id']);
    $salary = $sal ? cnssSubjectWageLbp($sal, $emp) : 0; // يتبع زرّ الخضوع بملف الأستاذ
    $exW = $sal ? extraWageLbp($sal) : 0; $aid = $sal ? aideCompLbp($sal) : 0;
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي</div>
    <div class="cnss-title"><u>إفـــادة لمن يهمه الأمر</u></div>
    <p style="line-height:2.2;margin-top:24px">
        تفيد مؤسسة <?= fillVal($school['name_ar']) ?> المسجّلة في الصندوق الوطني للضمان الاجتماعي
        تحت رقم <?= fillVal($school['nssf_employer_number']) ?>، أن المضمون
        <?= fillVal(empFullNameAr($emp)) ?> رقمه <?= fillVal($emp['nssf_number']) ?>
        قد بدأ العمل لدينا بدوام كامل اعتباراً من تاريخ <?= fillVal(formatDate($emp['hire_date'])) ?>،
        ويتقاضى راتباً شهرياً قدره <?= fillVal($salary?formatLBP($salary):'') ?>،
        وهو مستمر في عمله حتى تاريخه.
    </p>
    <div class="info-grid">
        <div><span class="k">منها الأجر الإضافي:</span> <strong><?= formatLBP($exW) ?></strong></div>
        <div><span class="k">منها مكافأة ومساعدة:</span> <strong><?= formatLBP($aid) ?></strong></div>
    </div>
    <div class="sign-row" style="margin-top:50px">
        <div class="sign-box"></div>
        <?= signatureBox('الخاتم والتوقيع', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?>
    </div>
</div>

<?php elseif ($form === 'cnss_wife'): // 485A تصريح عن الزوجة ?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي
        <div class="sub">المديرية الفنية — دائرة التعويضات العائلية</div></div>
    <div class="cnss-title"><u>تصريح عن الزوجة</u></div>

    <div class="doc-section">معلومات عن المضمون</div>
    <div class="fline"><span class="lbl">أنا المضمون الموقّع أدناه المسجّل في الصندوق تحت الرقم :</span> <?= digitBoxes($emp['nssf_number'],8) ?></div>
    <div class="fline"><span class="lbl">الاسم والشهرة</span> <?= fval(empFullNameAr($emp)) ?> <span class="lbl">اسم الأم وشهرتها</span> <?= fval(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
    <div class="fline"><span class="lbl">اسم الأب</span> <?= fval($emp['father_name_ar']) ?> <span class="lbl">رقم السجل</span> <?= fval($emp['civil_registry_number'],'g') ?></div>
    <div class="fline"><span class="lbl">العامل في مؤسسة</span> <?= fval($school['name_ar']) ?> <span class="lbl">رقمها</span> <?= digitBoxes($school['nssf_employer_number'],8) ?></div>
    <div class="fline"><span class="lbl">عنوانها</span> <?= fval($school['address'],'lg') ?></div>

    <div class="doc-section">معلومات عن الزوجة</div>
    <div class="doc-p">أصرّح وعلى مسؤوليتي أن زوجتي :</div>
    <div class="fline"><span class="lbl">الاسم الثلاثي</span> <?= fval('') ?> <span class="lbl">تاريخ الولادة</span> <?= fval('','g') ?></div>
    <div class="fline"><?= fopt('',2) ?> <span class="lbl">تزاول عملاً مأجوراً. نوع العمل</span> <?= fval('') ?></div>
    <div class="fline"><?= fopt('',3) ?> <span class="lbl">لا تزاول عملاً مأجوراً وتعيش معي تحت سقف واحد.</span></div>
    <div class="sign-row">
        <?= signatureBox('توقيع المضمون','','') ?>
        <?= signatureBox('خاتم وتوقيع صاحب العمل', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?>
    </div>
    <div class="doc-note">يُقدَّم هذا التصريح سنوياً إلى مكتب التبعية، ويرفق به إخراج قيد عائلي أصلي لا يتعدى تاريخ إصداره ثلاثة أشهر.</div>
</div>

<?php elseif ($form === 'cnss_annual'): // 386 التصريح الاسمي السنوي (مؤسسة)
    [$y1,$y2] = schoolYearToYears($schoolYear);
    // مجموع الأجور الخاضعة لكل فرع لكل شهر — بنفس مصدر بيان صندوق التعويضات
    // (yearEmploymentFilter) لاستبعاد المحذوفين والتاركين وصفوف الأشباح.
    [$yf,$yp] = yearEmploymentFilter($schoolYear, 'e.');
    // الاشتراكات من الأعمدة المخزّنة لكل فرع (لا من وعاء الضريبة): المرض ٣٪+٨٪، نهاية الخدمة ٨.٥٪،
    // التعويضات العائلية ٦٪. وعاء كل شهر يُشتقّ من اشتراك المرض ٣٪ (المرض يخضع له الجميع).
    $mq = $db->prepare("SELECT ms.month,
                            SUM(ms.cnss_amount_lbp) m3, SUM(ms.school_cnss_8_lbp) m8,
                            SUM(ms.school_end_of_service_8_5_lbp) eos, SUM(ms.school_family_comp_6_lbp) fam6,
                            SUM(ms.extra_lbp+ms.prime_fixe_lbp) ex, SUM(ms.aide_complementaire_lbp) ai,
                            SUM((ms.extra_lbp+ms.prime_fixe_lbp)/NULLIF(ms.exchange_rate,0)) ex_usd, SUM(ms.aide_complementaire_lbp/NULLIF(ms.exchange_rate,0)) ai_usd
                        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
                        WHERE e.is_deleted=0 AND e.cnss_subject=1" . $yf . " AND ms.school_year=? AND " . schoolScopeWhere('e.school_id') . "
                        GROUP BY ms.month");
    $mq->execute(array_merge($yp, [$schoolYear]));
    $byMonth = []; $brM = ['m3'=>0,'m8'=>0,'eos'=>0,'fam6'=>0,'ex'=>0,'ai'=>0,'ex_usd'=>0.0,'ai_usd'=>0.0];
    foreach ($mq->fetchAll() as $r) {
        $byMonth[(int)$r['month']] = (int)$r['m3'] ? (int)round($r['m3']/rateFrac('cnss_employee_rate', (int)$r['month'], $year, 3)) : 0;
        $brM['m3']+=(int)$r['m3']; $brM['m8']+=(int)$r['m8']; $brM['eos']+=(int)$r['eos']; $brM['fam6']+=(int)$r['fam6'];
        $brM['ex']+=(int)$r['ex']; $brM['ai']+=(int)$r['ai']; $brM['ex_usd']+=(float)$r['ex_usd']; $brM['ai_usd']+=(float)$r['ai_usd'];
    }
    $order = [1=>'كانون الثاني',2=>'شباط',3=>'آذار',4=>'نيسان',5=>'أيار',6=>'حزيران',7=>'تموز',8=>'آب',9=>'أيلول',10=>'تشرين الأول',11=>'تشرين الثاني',12=>'كانون الأول'];
    $totalWages = array_sum($byMonth);
    $cMaladie = $brM['m3'] + $brM['m8'];          // اشتراك المرض والأمومة الفعلي المخزّن
    $cEos     = $brM['eos'];                        // نهاية الخدمة ٨.٥٪ (للموظفين)
    $cFamily  = $brM['fam6'];                       // التعويضات العائلية ٦٪ (للموظفين)
    $cTotal   = $cMaladie + $cEos + $cFamily;
    $baseEos  = $cEos    ? (int)round($cEos/rateFrac('end_of_service_rate', $month, $year, 8.5)) : 0;
    $baseFam  = $cFamily ? (int)round($cFamily/rateFrac('family_compensation_rate', $month, $year, 6)) : 0;
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي — جانب صاحب العمل</div>
    <div class="cnss-title"><u>التصريح الاسمي السنوي</u><br><span style="font-size:12pt">لسنة <?= e($schoolYear) ?></span></div>

    <div class="info-grid">
        <div><span class="k">إسم المؤسسة:</span> <?= fillVal($school['name_ar']) ?></div>
        <div><span class="k">رقمها في الصندوق:</span> <?= digitBoxes($school['nssf_employer_number'],8) ?></div>
    </div>

    <div class="doc-section">(أ) مراجعة الأجور التي دُفعت عنها اشتراكات خلال السنة</div>
    <table class="doc-table" style="max-width:560px;margin:6px auto">
        <thead><tr><th>المدة</th><th>الأجور الخاضعة (ل.ل)</th></tr></thead>
        <tbody>
        <?php foreach ($order as $mn=>$lbl): ?>
            <tr><td><?= $lbl ?></td><td class="num"><?= isset($byMonth[$mn])?formatLBP($byMonth[$mn],false):'' ?></td></tr>
        <?php endforeach; ?>
            <tr class="total-row"><td>المجاميع</td><td class="num"><?= formatLBP($totalWages,false) ?></td></tr>
            <tr><td>منها: الأجر الإضافي (خلال السنة)</td><td class="num"><?= dualFromUsd($brM['ex'],$brM['ex_usd'],false) ?></td></tr>
            <tr><td>منها: مكافأة ومساعدة (خلال السنة)</td><td class="num"><?= dualFromUsd($brM['ai'],$brM['ai_usd'],false) ?></td></tr>
        </tbody>
    </table>

    <div class="doc-section">(ب) اشتراكات التسوية السنوية المتوجب تسديدها</div>
    <table class="doc-table" style="max-width:640px;margin:6px auto">
        <thead><tr><th>الفرع</th><th>مجموع الأجور</th><th>المعدل</th><th>الاشتراك المتوجب</th></tr></thead>
        <tbody>
            <tr><td>المرض والأمومة</td><td class="num"><?= formatLBP($totalWages,false) ?></td><td>١١٪</td><td class="num"><?= formatLBP($cMaladie,false) ?></td></tr>
            <tr><td>نهاية الخدمة</td><td class="num"><?= formatLBP($baseEos,false) ?></td><td>٨.٥٪</td><td class="num"><?= formatLBP($cEos,false) ?></td></tr>
            <tr><td>التعويضات العائلية</td><td class="num"><?= formatLBP($baseFam,false) ?></td><td>٦٪</td><td class="num"><?= formatLBP($cFamily,false) ?></td></tr>
            <tr class="total-row"><td colspan="3">مجموع اشتراكات التسوية المتوجب دفعها</td><td class="num"><?= formatLBP($cTotal,false) ?></td></tr>
        </tbody>
    </table>
    <div class="doc-note">يتوجب إرفاق هذا التصريح بالجدول الاسمي السنوي (لائحة المضمونين).</div>
    <div class="sign-row"><?= signatureBox('ختم المؤسسة والتوقيع', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php
/* ========================================================================
 *  تقارير داخلية (بطاقات/لوائح/كشوف) — ديزاين البرنامج
 * ====================================================================== */
elseif ($form === 'teacher_card'):
    $sal = ofLatestSalary($db, $emp['id']);
    $salary = $sal ? $sal['base_plus_echelon_lbp'] : (int)scaleSalaryLBP($emp['current_grade']);
?>
<div class="official-doc rtl" id="ppExportArea">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">بطاقة الأستاذ — للعام الدراسي <?= e($schoolYear) ?></div>
    <div class="doc-section">الهوية</div>
    <div class="info-grid">
        <div><span class="k">الاسم والشهرة:</span> <?= fillVal(empFullNameAr($emp)) ?></div>
        <div><span class="k">اسم الأب:</span> <?= fillVal($emp['father_name_ar']) ?></div>
        <div><span class="k">اسم الأم وشهرتها:</span> <?= fillVal(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
        <div><span class="k">محل وتاريخ الولادة:</span> <?= fillVal($emp['birth_place'].' - '.formatDate($emp['birth_date'])) ?></div>
        <div><span class="k">الجنسية:</span> <?= fillVal($emp['nationality']==='lebanese'?'لبنانية':$emp['nationality']) ?></div>
        <div><span class="k">رقم السجل:</span> <?= fillVal($emp['civil_registry_number'].' / '.$emp['civil_registry_place']) ?></div>
        <div><span class="k">الوضع العائلي:</span> <?= fillVal(socialStatusLabel($emp['social_status'],'ar')) ?></div>
        <div><span class="k">عدد الأولاد:</span> <?= fillVal($emp['number_of_children']) ?></div>
        <div><span class="k">رقم الهاتف:</span> <?= fillVal($emp['phone1'] ?: $emp['phone2']) ?></div>
        <div class="full"><span class="k">محل الإقامة:</span> <?= fillVal(empAddress($emp)) ?></div>
    </div>
    <div class="doc-section">المسار المهني والتعليمي</div>
    <div class="info-grid">
        <div><span class="k">الوظيفة/الفئة:</span> <?= fillVal(employeeTypeLabel($emp['employee_type'],'ar')) ?></div>
        <div><span class="k">أعلى شهادة رسمية:</span> <?= fillVal(diplomaLabel($emp['diploma'],'ar')) ?></div>
        <div><span class="k">الاختصاص:</span> <?= fillVal($emp['specialization']) ?></div>
        <div><span class="k">تاريخ المباشرة:</span> <?= fillVal(formatDate($emp['hire_date'])) ?></div>
        <div><span class="k">تاريخ الدخول في الملاك:</span> <?= fillVal(formatDate($emp['titularization_date'])) ?></div>
        <div><span class="k">الدرجة الحالية:</span> <?= fillVal(rtrim(rtrim((string)$emp['current_grade'],'0'),'.')) ?></div>
        <div><span class="k">المرحلة:</span> <?= fillVal($emp['niveau_scolaire']) ?></div>
        <div><span class="k">مادة التدريس:</span> <?= fillVal($emp['subjects_taught']) ?></div>
        <div><span class="k">عدد الساعات الأسبوعية:</span> <?= fillVal(rtrim(rtrim((string)$emp['hours_per_week'],'0'),'.')) ?></div>
        <div><span class="k">رقمه في الضمان:</span> <?= fillVal($emp['nssf_number']) ?></div>
        <div><span class="k">رقمه المالي:</span> <?= fillVal($emp['finance_ministry_number']) ?></div>
        <div><span class="k">الأجر الإضافي:</span> <strong><?= money($sal ? extraWageLbp($sal) : 0, $sal ? rowRate($sal) : null) ?></strong></div>
        <div><span class="k">مكافأة ومساعدة:</span> <strong><?= money($sal ? aideCompLbp($sal) : 0, $sal ? rowRate($sal) : null) ?></strong></div>
        <div class="full"><span class="k">الراتب الشهري (سلسلة):</span> <strong><?= money($sal ? (int)$sal['base_plus_echelon_lbp'] : (int)$salary, $sal ? rowRate($sal) : null) ?></strong></div>
        <div class="full" style="background:#eef2ff;padding:4px 8px;border-radius:6px"><span class="k">الراتب المركّب (<?= e(salaryCompLabel()) ?>):</span> <strong><?= $sal ? money(composedSalaryLbp($sal), rowRate($sal)) : formatLBP($salary) ?></strong></div>
    </div>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'teaching_staff'):
    // بيان عام بمعلومات عن أفراد الهيئة التعليمية (متعاقدين / ملاك / الكل) — مطابق Ecole.exe
    $cat = $_GET['cat'] ?? 'all';   // all | titulaire | contractuel
    $catSql = '';
    if ($cat === 'titulaire')   $catSql = " AND employee_type='enseignant_titulaire'";
    elseif ($cat === 'contractuel') $catSql = " AND employee_type='enseignant_contractuel'";
    else $catSql = " AND employee_type IN ('enseignant_titulaire','enseignant_contractuel')";
    $catTitle = ['titulaire'=>'الداخلين في الملاك', 'contractuel'=>'المتعاقدين'][$cat] ?? 'المتعاقدين والملاك';
    [$yf, $yp] = yearEmploymentFilter(activeSchoolYear());
    $q = $db->prepare("SELECT * FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . $catSql . $yf . "
                       ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $q->execute($yp);
    $rows = $q->fetchAll();
    $sy = (activeSchoolYear()==='all') ? $schoolYear : activeSchoolYear();
    $X = '<span style="font-weight:700">X</span>';
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="teaching_staff">
        <div class="card-body form-row cols-2">
            <div class="form-group mb-0"><label class="form-label">Catégorie / الفئة</label>
                <select name="cat" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?=$cat==='all'?'selected':''?>>Contractuels et titulaires / المتعاقدون والملاك معاً</option>
                    <option value="titulaire" <?=$cat==='titulaire'?'selected':''?>>Titulaires uniquement / الداخلون في الملاك فقط</option>
                    <option value="contractuel" <?=$cat==='contractuel'?'selected':''?>>Contractuels uniquement / المتعاقدون فقط</option>
                </select></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary"><i class="fas fa-eye"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:12pt;margin-bottom:6px">
        <div>دائرة الهيئة التعليمية في المدارس الخاصة</div>
        <div style="text-align:left">
            <div style="font-weight:700"><?= e($school['name_ar'] ?? '') ?></div>
            <div><?= e($school['address'] ?? '') ?><?= !empty($school['phone'])?' — هاتف: '.e($school['phone']):'' ?></div>
        </div>
    </div>
    <div class="doc-title" style="margin:6px 0">بيان عام بمعلومات عن جميع أفراد الهيئة التعليمية <?= e($catTitle) ?></div>
    <div style="text-align:center;font-size:12pt;margin-bottom:8px">عن السنة المدرسية <?= e($sy) ?></div>
    <table class="doc-table">
        <thead>
            <tr>
                <th rowspan="2">#</th><th rowspan="2">الإسم والشهرة</th><th rowspan="2">أعلى شهادة علمية</th>
                <th rowspan="2">تاريخ المباشرة</th><th rowspan="2">الفئة</th>
                <th colspan="4">المرحلة التي يعلّم فيها</th>
                <th rowspan="2">مادة التدريس</th><th rowspan="2">عدد الساعات</th>
                <th rowspan="2">خاضع للضريبة</th><th rowspan="2">أساس الراتب</th><?= extraAideHeads(' rowspan="2"') ?><?= transportHead(' rowspan="2"') ?><th rowspan="2">الراتب (ل.ل)</th><th rowspan="2">توقيع</th>
            </tr>
            <tr><th>حضانة</th><th>ابتدائي</th><th>متوسط</th><th>ثانوي</th></tr>
        </thead>
        <tbody>
        <?php $ttG = ['base'=>0,'ex'=>0,'ex_u'=>0.0,'ai'=>0,'ai_u'=>0.0,'tr'=>0,'tr_u'=>0.0,'sal'=>0];
        foreach ($rows as $i=>$r):
            $nv = $r['niveau_scolaire'] ?? '';
            $sal = ofLatestSalary($db, $r['id']);
            $rsal = 0;
            if ($sal) {
                // الملاك: راتب السلسلة؛ المتعاقد (بالدولار): الصافي الفعلي
                $rsal = (int)$sal['base_plus_echelon_lbp'];
                if ($rsal <= 0) $rsal = (int)$sal['net_salary_lbp'];
            }
            if ($rsal <= 0) $rsal = (int)scaleSalaryLBP($r['current_grade']);
            // مجاميع أسفل الجدول (المكوّنات بالليرة + دولار مجموع صفّاً صفّاً بسعر شهر كل صف)
            $ttRate = $sal ? rowRate($sal) : null;
            $ttG['base'] += $sal ? (int)$sal['base_salary_lbp'] : 0;
            $ttG['ex']   += $sal ? extraWageLbp($sal) : 0;  $ttG['ex_u'] += $sal ? lbpToUsd(extraWageLbp($sal), $ttRate) : 0;
            $ttG['ai']   += $sal ? aideCompLbp($sal) : 0;   $ttG['ai_u'] += $sal ? lbpToUsd(aideCompLbp($sal), $ttRate) : 0;
            $ttG['tr']   += $sal ? (int)$sal['transport_lbp'] : 0; $ttG['tr_u'] += $sal ? lbpToUsd((int)$sal['transport_lbp'], $ttRate) : 0;
            $ttG['sal']  += $rsal;
        ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td style="text-align:right"><?= e(trim(($r['first_name_ar'].' '.$r['last_name_ar'])) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td><?= e(diplomaLabel($r['diploma'],'ar')) ?></td>
                <td><?= formatDate($r['hire_date']) ?></td>
                <td><?php // الفئة: معلّم للابتدائي/الحضانة، مدرّس للمتوسط/الثانوي
                    $isMod = (strpos($nv,'secondaire')!==false || strpos($nv,'intermediaire')!==false);
                    echo $isMod ? 'مدرّس' : ((strpos($nv,'primaire')!==false || strpos($nv,'maternelle')!==false) ? 'معلّم' : 'مدرّس'); ?></td>
                <td><?= strpos($nv,'maternelle')!==false?$X:'' ?></td>
                <td><?= strpos($nv,'primaire')!==false?$X:'' ?></td>
                <td><?= strpos($nv,'intermediaire')!==false?$X:'' ?></td>
                <td><?= strpos($nv,'secondaire')!==false?$X:'' ?></td>
                <td><?= e($r['subjects_taught']) ?></td>
                <td><?= rtrim(rtrim((string)$r['hours_per_week'],'0'),'.') ?></td>
                <td><?= $r['tax_subject']?$X:'' ?></td>
                <td class="num"><?= formatLBP($sal ? (int)$sal['base_salary_lbp'] : 0,false) ?></td>
                <?php if (salaryCompHas('extra')): ?><td class="num"><?= money($sal ? extraWageLbp($sal) : 0, $sal ? rowRate($sal) : null, ['withCur'=>false]) ?></td><?php endif; ?>
                <?php if (salaryCompHas('aide')): ?><td class="num"><?= money($sal ? aideCompLbp($sal) : 0, $sal ? rowRate($sal) : null, ['withCur'=>false]) ?></td><?php endif; ?>
                <?php if (salaryCompHas('transport')): ?><td class="num"><?= money($sal ? (int)$sal['transport_lbp'] : 0, $sal ? rowRate($sal) : null, ['withCur'=>false]) ?></td><?php endif; ?>
                <td class="num"><?= formatLBP($rsal,false) ?></td>
                <td>&nbsp;</td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="<?= 15 + compColsCount() ?>" class="text-center">لا يوجد أفراد بهذه الفئة لهذه السنة</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr class="total-row">
            <td colspan="12" style="text-align:right">المجموع — العدد: <?= count($rows) ?></td>
            <td class="num"><?= formatLBP($ttG['base'],false) ?></td>
            <?= extraAideTotalCells($ttG['ex'],$ttG['ex_u'],$ttG['ai'],$ttG['ai_u']) ?>
            <?= transportTotalCell($ttG['tr'],$ttG['tr_u']) ?>
            <td class="num"><strong><?= formatLBP($ttG['sal'],false) ?></strong></td>
            <td></td>
        </tr></tfoot><?php endif; ?>
    </table>
    <div style="margin-top:10px;font-weight:700">العدد الإجمالي: <?= count($rows) ?></div>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'eoc_staff'):
    // بيان عام — صندوق التعويضات (ملاك / متعاقدين) — طبق نموذج صندوق التعويضات الرسمي
    $cat = ($_GET['cat'] ?? 'titulaire') === 'contractuel' ? 'contractuel' : 'titulaire';
    $isMlk = ($cat === 'titulaire');
    $typeSql = $isMlk ? "employee_type='enseignant_titulaire'" : "employee_type='enseignant_contractuel'";
    $catTitle = $isMlk ? 'الداخلين في الملاك' : 'المتعاقدين';
    [$yf, $yp] = yearEmploymentFilter(activeSchoolYear());
    $q = $db->prepare("SELECT * FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . " AND $typeSql" . $yf . "
                       ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $q->execute($yp);
    $rows = $q->fetchAll();
    $sy = (activeSchoolYear()==='all') ? $schoolYear : activeSchoolYear();
    $X = '<span style="font-weight:700">X</span>';
    $nCols = ($isMlk ? 17 : 18) + compColsCount(); // الأعمدة الثابتة + أعمدة المكوّنات الظاهرة (تتبع «الراتب يشمل»)
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="eoc_staff">
        <div class="card-body form-row cols-2">
            <div class="form-group mb-0"><label class="form-label">Catégorie / الفئة</label>
                <select name="cat" class="form-select" onchange="this.form.submit()">
                    <option value="titulaire" <?=$cat==='titulaire'?'selected':''?>>Titulaires / الداخلون في الملاك</option>
                    <option value="contractuel" <?=$cat==='contractuel'?'selected':''?>>Contractuels / المتعاقدون</option>
                </select></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary"><i class="fas fa-eye"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:12pt;margin-bottom:6px">
        <div style="font-weight:700;line-height:1.6">صندوق التعويضات<br><span style="font-weight:400">لأفراد الهيئة التعليمية في المدارس الخاصة</span></div>
        <div style="text-align:left">
            <div style="font-weight:700"><?= e($school['name_ar'] ?? '') ?></div>
            <div><?= e($school['address'] ?? '') ?><?= !empty($school['phone'])?' — هاتف: '.e($school['phone']):'' ?></div>
            <div>الرقم المالي: <?= e($school['finance_number'] ?? '') ?></div>
        </div>
    </div>
    <div class="doc-title" style="margin:6px 0">بيان عام بمعلومات عن جميع أفراد الهيئة التعليمية <?= e($catTitle) ?></div>
    <div style="text-align:center;font-size:12pt;margin-bottom:8px">عن السنة المدرسية <?= e($sy) ?></div>
    <table class="doc-table">
        <thead>
            <tr>
                <th rowspan="2">#</th><th rowspan="2">الرقم المالي</th>
                <th rowspan="2">الاسم والشهرة وفقاً لبطاقة الهوية</th>
                <th rowspan="2">أعلى شهادة عند دخوله الملاك</th>
                <th rowspan="2">تاريخ دخوله الخدمة في المدرسة</th>
                <th colspan="4">الفئة</th>
                <th colspan="4">المرحلة</th>
                <th rowspan="2">عدد ساعات التعليم</th>
                <th rowspan="2">أساس الراتب</th>
                <?= extraAideHeads(' rowspan="2"') ?><?= transportHead(' rowspan="2"') ?>
                <th rowspan="2" style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th>
                <?php if (!$isMlk): ?><th rowspan="2">تعاقد رسمي</th><?php endif; ?>
                <th rowspan="2">التوقيع</th>
            </tr>
            <tr>
                <th>حاضنة</th><th>معلم</th><th>مدرس</th><th>أستاذ ثانوي</th>
                <th>ح</th><th>أ</th><th>م</th><th>ث</th>
            </tr>
        </thead>
        <tbody>
        <?php $esG = ['base'=>0,'ex'=>0,'ex_u'=>0.0,'ai'=>0,'ai_u'=>0.0,'tr'=>0,'tr_u'=>0.0,'comp'=>0,'comp_u'=>0.0,'contract'=>0];
        foreach ($rows as $i=>$r):
            $nv = $r['niveau_scolaire'] ?? '';
            $hasMat = strpos($nv,'maternelle')!==false; $hasPrim = strpos($nv,'primaire')!==false;
            $hasInt = strpos($nv,'intermediaire')!==false; $hasSec = strpos($nv,'secondaire')!==false;
            // الفئة (رتبة واحدة): حاضنة/معلم(ابتدائي)/مدرس(متوسط)/أستاذ ثانوي
            $catCol = $hasSec ? 'sec' : ($hasInt ? 'int' : ($hasPrim ? 'prim' : ($hasMat ? 'mat' : 'int')));
            $sal = ofLatestSalary($db, $r['id']);
            $rsal = $sal ? (int)$sal['base_plus_echelon_lbp'] : 0;
            if ($rsal <= 0 && $sal) $rsal = (int)$sal['net_salary_lbp'];
            if ($rsal <= 0) $rsal = (int)scaleSalaryLBP($r['current_grade']);
            $contract = (int)($r['contract_salary_lbp'] ?? 0);
            // مجاميع أسفل الجدول
            $esRate = $sal ? rowRate($sal) : null;
            $esG['base'] += $sal ? (int)$sal['base_salary_lbp'] : 0;
            $esG['ex']   += $sal ? extraWageLbp($sal) : 0;  $esG['ex_u'] += $sal ? lbpToUsd(extraWageLbp($sal), $esRate) : 0;
            $esG['ai']   += $sal ? aideCompLbp($sal) : 0;   $esG['ai_u'] += $sal ? lbpToUsd(aideCompLbp($sal), $esRate) : 0;
            $esG['tr']   += $sal ? (int)$sal['transport_lbp'] : 0; $esG['tr_u'] += $sal ? lbpToUsd((int)$sal['transport_lbp'], $esRate) : 0;
            $esG['comp'] += $sal ? composedSalaryLbp($sal) : $rsal;
            $esG['comp_u'] += $sal ? lbpToUsd(composedSalaryLbp($sal), $esRate) : 0;
            $esG['contract'] += $contract;
        ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= e($r['finance_ministry_number']) ?></td>
                <td style="text-align:right"><?= e(trim(($r['first_name_ar'].' '.$r['last_name_ar'])) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td><?= e(diplomaLabel($r['diploma'],'ar')) ?></td>
                <td><?= formatDate($isMlk ? ($r['titularization_date'] ?: $r['hire_date']) : $r['hire_date']) ?></td>
                <td><?= $catCol==='mat'?$X:'' ?></td>
                <td><?= $catCol==='prim'?$X:'' ?></td>
                <td><?= $catCol==='int'?$X:'' ?></td>
                <td><?= $catCol==='sec'?$X:'' ?></td>
                <td><?= $hasMat?$X:'' ?></td>
                <td><?= $hasPrim?$X:'' ?></td>
                <td><?= $hasInt?$X:'' ?></td>
                <td><?= $hasSec?$X:'' ?></td>
                <td><?= rtrim(rtrim((string)$r['hours_per_week'],'0'),'.') ?></td>
                <td class="num"><?= formatLBP($sal ? (int)$sal['base_salary_lbp'] : 0,false) ?></td>
                <?php if (salaryCompHas('extra')): ?><td class="num"><?= money($sal ? extraWageLbp($sal) : 0, $sal ? rowRate($sal) : null, ['withCur'=>false]) ?></td><?php endif; ?>
                <?php if (salaryCompHas('aide')): ?><td class="num"><?= money($sal ? aideCompLbp($sal) : 0, $sal ? rowRate($sal) : null, ['withCur'=>false]) ?></td><?php endif; ?>
                <?php if (salaryCompHas('transport')): ?><td class="num"><?= money($sal ? (int)$sal['transport_lbp'] : 0, $sal ? rowRate($sal) : null, ['withCur'=>false]) ?></td><?php endif; ?>
                <td class="num" style="background:#eef2ff"><strong><?= $sal ? money(composedSalaryLbp($sal), rowRate($sal), ['withCur'=>false]) : formatLBP($rsal,false) ?></strong></td>
                <?php if (!$isMlk): ?><td class="num"><?= $contract>0?formatLBP($contract,false):'' ?></td><?php endif; ?>
                <td>&nbsp;</td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="<?= $nCols ?>" class="text-center">لا يوجد أفراد بهذه الفئة لهذه السنة</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr class="total-row">
            <td colspan="14" style="text-align:right">المجموع — العدد: <?= count($rows) ?></td>
            <td class="num"><?= formatLBP($esG['base'],false) ?></td>
            <?= extraAideTotalCells($esG['ex'],$esG['ex_u'],$esG['ai'],$esG['ai_u']) ?>
            <?= transportTotalCell($esG['tr'],$esG['tr_u']) ?>
            <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($esG['comp'],$esG['comp_u'],false) ?></strong></td>
            <?php if (!$isMlk): ?><td class="num"><?= formatLBP($esG['contract'],false) ?></td><?php endif; ?>
            <td></td>
        </tr></tfoot><?php endif; ?>
    </table>
    <div style="margin-top:10px;font-weight:700">العدد الإجمالي: <?= count($rows) ?></div>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'eoc_card'):
    $sal = ofLatestSalary($db, $emp['id']);
    $base = $sal ? $sal['base_plus_echelon_lbp'] : (int)scaleSalaryLBP($emp['current_grade']);
    $eoc = $sal ? $sal['caisse_amount_lbp'] : 0;
    $eocGradeCard = $sal ? (int)($sal['eoc_grade_lbp'] ?? 0) : 0;
?>
<div class="official-doc rtl" id="ppExportArea">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">بطاقة ملاك — صندوق التعويضات لأساتذة التعليم الخاص</div>
    <div class="info-grid">
        <div><span class="k">الاسم والشهرة:</span> <?= fillVal(empFullNameAr($emp)) ?></div>
        <div><span class="k">اسم الأب:</span> <?= fillVal($emp['father_name_ar']) ?></div>
        <div><span class="k">المدرسة:</span> <?= fillVal($school['name_ar']) ?></div>
        <div><span class="k">رقم الملاك:</span> <?= fillVal($emp['caisse_number']) ?></div>
        <div><span class="k">أعلى شهادة:</span> <?= fillVal(diplomaLabel($emp['diploma'],'ar')) ?></div>
        <div><span class="k">تاريخ الدخول في الملاك:</span> <?= fillVal(formatDate($emp['titularization_date'])) ?></div>
        <div><span class="k">الدرجة الحالية:</span> <?= fillVal(rtrim(rtrim((string)$emp['current_grade'],'0'),'.')) ?></div>
        <div><span class="k">عدد ساعات التعليم:</span> <?= fillVal(rtrim(rtrim((string)$emp['hours_per_week'],'0'),'.')) ?></div>
        <div><span class="k">الراتب الأساسي (سلسلة):</span> <strong><?= money($sal ? (int)$sal['base_plus_echelon_lbp'] : (int)$base, $sal ? rowRate($sal) : null) ?></strong></div>
        <div><span class="k">الأجر الإضافي:</span> <strong><?= money($sal ? extraWageLbp($sal) : 0, $sal ? rowRate($sal) : null) ?></strong></div>
        <div><span class="k">مكافأة ومساعدة:</span> <strong><?= money($sal ? aideCompLbp($sal) : 0, $sal ? rowRate($sal) : null) ?></strong></div>
        <div style="background:#eef2ff;padding:4px 8px;border-radius:6px"><span class="k">الراتب المركّب (<?= e(salaryCompLabel()) ?>):</span> <strong><?= $sal ? money(composedSalaryLbp($sal), rowRate($sal)) : formatLBP($base) ?></strong></div>
        <div><span class="k">اشتراك الصندوق (٦٪):</span> <strong><?= formatLBP($eoc) ?></strong></div>
        <?php if ($eocGradeCard > 0): ?><div><span class="k">درجة / نصف راتب (آخر حسم):</span> <strong><?= formatLBP($eocGradeCard) ?></strong></div><?php endif; ?>
    </div>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'eoc_quarterly'):
    // المحسومات الفصلية — صندوق التعويضات: مطابق للنموذج الرسمي (بيان بالمحسومات المقتطعة ومساهمة
    // المدرسة عن أفراد الهيئة التعليمية الداخلين في الملاك) — بطلب المستخدم 2026-07-29 (صورة النموذج الورقي)
    // تركيب ذاتي: عمود «رقم المدرسة لدى صندوق التعويضات» في جدول المدارس
    try { $db->query("SELECT caisse_number FROM schools LIMIT 1"); }
    catch (Exception $e) { try { $db->exec("ALTER TABLE schools ADD COLUMN caisse_number VARCHAR(50) NULL COMMENT 'رقم المدرسة لدى صندوق التعويضات'"); } catch (Exception $e2) {} }
    // 🔴 أُزيلت التعبئة الذاتية بالاسم (2026-07-30): كان `LIKE '%مكسيموس%'` يطابق أيضاً
    // «مركز البطريرك مكسيموس الخامس حكيم» (٣ مؤسسات) فكتب رقم صندوق المدرسة 75210 عندها،
    // فكان بيانها الفصلي يُطبَع برقم مؤسسة أخرى تحت توقيع المدير وخَتمه.
    // الرقم يُدخَل يدوياً من صفحة المدارس لكل مدرسة، والإصلاح الذاتي في healCaisseNumbers().
    $qNames = [1=>'الفصل الأول (كانون الثاني - شباط - آذار)', 2=>'الفصل الثاني (نيسان - أيار - حزيران)',
               3=>'الفصل الثالث (تموز - آب - أيلول)', 4=>'الفصل الرابع (تشرين الأول - تشرين الثاني - كانون الأول)'];
    $qShort = [1=>'الفصل الأول', 2=>'الفصل الثاني', 3=>'الفصل الثالث', 4=>'الفصل الرابع'];
    $quarter = max(1, min(4, (int)($_GET['quarter'] ?? ceil($month/3))));
    [$qy1, $qy2] = schoolYearToYears($schoolYear);
    $qm = [1=>[1,2,3], 2=>[4,5,6], 3=>[7,8,9], 4=>[10,11,12]][$quarter];
    $qYear = ($quarter === 4) ? $qy1 : $qy2;       // الفصل الرابع (ت١/ت٢/ك١) بالسنة الأولى
    $ph = implode(',', array_fill(0, 3, '?'));
    // تفصيل لكل أستاذ ملاك: الراتب السابق (أساس قبل التدرّج) والحالي (بعد التدرّج) + المحسوم الفصلي لصندوق التعويضات
    $q = $db->prepare("SELECT e.id, e.school_id, e.caisse_number, e.titularization_date,
            e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr, e.father_name_ar, e.father_name_fr,
            MAX(ms.base_salary_lbp) prevSal, MAX(ms.base_plus_echelon_lbp) currSal,
            MAX(ms.extra_lbp + ms.prime_fixe_lbp) extraM,
            COUNT(DISTINCT ms.month) months, MAX(ms.caisse_amount_lbp) monthlyCaisse,
            SUM(ms.caisse_amount_lbp) qCaisse, SUM(ms.eoc_grade_lbp) gradeHalf
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
        WHERE e.employee_type='enseignant_titulaire' AND e.is_deleted=0" . $ofYearFilter . "
              AND ms.year=? AND ms.month IN ($ph) AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id') . "
        GROUP BY e.id
        ORDER BY e.school_id, COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $q->execute(array_merge($ofYearParams, [$qYear], $qm));
    $rows = $q->fetchAll();
    $multiS = (count(activeSchoolIds()) !== 1);
    $qMonthsLabel = implode(' - ', array_map(fn($m)=>monthName($m,'ar'), $qm));
    // نصف الراتب مقابل «مختلف/درجة»: إذا وقع تاريخ دخول الملاك ضمن هذا الفصل → المبلغ نصف راتب، وإلا فهو قسط درجة/زيادة
    $isTitInQuarter = function ($r) use ($qYear, $qm) {
        $td = $r['titularization_date'] ?? null;
        if (!$td || $td === '0000-00-00') return false;
        return ((int)date('Y', strtotime($td)) === $qYear) && in_array((int)date('n', strtotime($td)), $qm, true);
    };
    $allSpan = $multiS ? 12 : 11;
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="eoc_quarterly">
        <div class="card-body form-row cols-3">
            <div class="form-group mb-0"><label class="form-label">Trimestre / الفصل</label>
                <select name="quarter" class="form-select" onchange="this.form.submit()"><?php foreach ($qNames as $qn=>$ql): ?><option value="<?=$qn?>" <?=$qn===$quarter?'selected':''?>><?= e($ql) ?></option><?php endforeach; ?></select></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <?php /* ترويسة النموذج الرسمي: صندوق التعويضات يميناً، عنوان البيان ورقم الهاتف يساراً */ ?>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:4px">
        <div style="font-weight:700;line-height:1.7;font-size:12pt">صندوق التعويضات<br>
            <span style="font-weight:600">لأفراد الهيئة التعليمية في المدارس الخاصة</span><br>
            <span style="font-weight:400">في مدرسة: <?= e($school['name_ar'] ?? '') ?><?= !empty($school['ville']) ? ' - ' . e($school['ville']) : '' ?> / غير مجاني</span>
        </div>
        <div style="text-align:left;line-height:1.7;font-size:12pt">
            <strong>بيان بالمحسومات المقتطعة ومساهمة المدرسة<br>عن أفراد الهيئة التعليمية الداخلين في الملاك</strong><br>
            رقم الهاتف: <?= e($school['phone'] ?? '') ?: '<span style="display:inline-block;min-width:90px;border-bottom:1px dotted #475569">&nbsp;</span>' ?>
        </div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:12pt;margin-bottom:2px">
        <div>رقم المدرسة: <strong><?= e($school['caisse_number'] ?? '') ?: '<span style="display:inline-block;min-width:80px;border-bottom:1px dotted #475569">&nbsp;</span>' ?></strong>
             &nbsp;&nbsp; من السنة المدرسية <strong><?= e($schoolYear) ?></strong></div>
        <div>Page 1 Of 1</div>
    </div>
    <div style="font-size:12pt;margin-bottom:6px">عن الفصل: <strong><?= e($qShort[$quarter]) ?></strong> (<?= e($qMonthsLabel) ?>)</div>
    <table class="doc-table">
        <thead><tr>
            <?php if ($multiS): ?><th>المدرسة</th><?php endif; ?>
            <th>الرقم المالي</th><th>الاسم والشهرة</th><th>اسم الأب</th>
            <th>الراتب الشهري<br>السابق ل.ل</th><th>الراتب الشهري<br>الحالي ل.ل</th>
            <th>الأجر<br>الإضافي ل.ل</th>
            <th>المحسومات<br>الشهرية ل.ل</th><th>المحسومات<br>الفصلية ل.ل</th>
            <th>نصف<br>راتب ل.ل</th><th>مختلف، درجة<br>تمرين ل.ل</th><th>ملاحظات</th>
        </tr></thead>
        <tbody>
        <?php $totPrev=0;$totCur=0;$totM=0;$totQ=0;$totHalf=0;$totMisc=0;$totEx=0; foreach ($rows as $i=>$r):
            $mc=(int)$r['monthlyCaisse']; $qc=(int)$r['qCaisse']; $gh=(int)$r['gradeHalf']; $exM=(int)$r['extraM'];
            $half = ($gh > 0 && $isTitInQuarter($r)) ? $gh : 0;   // دخل الملاك بهذا الفصل → نصف راتب
            $misc = $gh - $half;                                   // وإلا → قسط درجة/زيادة (مختلف)
            $totPrev+=(int)$r['prevSal']; $totCur+=(int)$r['currSal']; $totM+=$mc; $totQ+=$qc; $totHalf+=$half; $totMisc+=$misc; $totEx+=$exM; ?>
            <tr>
                <?php if ($multiS): ?><td><small><?= e(schoolNameById($r['school_id'],'ar')) ?></small></td><?php endif; ?>
                <td><?= e($r['caisse_number']) ?></td>
                <td style="text-align:right"><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td><?= e(trim((string)$r['father_name_ar']) ?: (string)$r['father_name_fr']) ?></td>
                <td class="num"><?= formatLBP($r['prevSal'],false) ?></td>
                <td class="num"><?= formatLBP($r['currSal'],false) ?></td>
                <td class="num"><?= $exM>0?formatLBP($exM,false):'00' ?></td>
                <td class="num"><?= formatLBP($mc,false) ?></td>
                <td class="num"><strong><?= formatLBP($qc,false) ?></strong></td>
                <td class="num"><?= $half>0?formatLBP($half,false):'00' ?></td>
                <td class="num"><?= $misc>0?formatLBP($misc,false):'00' ?></td>
                <td>&nbsp;</td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="<?= $allSpan ?>" class="text-center">لا يوجد أساتذة ملاك بهذا الفصل</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row">
            <td colspan="<?= $multiS ? 4 : 3 ?>">المجموع</td>
            <td class="num"><strong><?= formatLBP($totPrev,false) ?></strong></td>
            <td class="num"><strong><?= formatLBP($totCur,false) ?></strong></td>
            <td class="num"><strong><?= formatLBP($totEx,false) ?></strong></td>
            <td class="num"><strong><?= formatLBP($totM,false) ?></strong></td>
            <td class="num"><strong><?= formatLBP($totQ,false) ?></strong></td>
            <td class="num"><strong><?= formatLBP($totHalf,false) ?></strong></td>
            <td class="num"><strong><?= formatLBP($totMisc,false) ?></strong></td>
            <td></td>
        </tr></tfoot>
    </table>

    <?php // خلاصة النموذج الرسمي: المحسومات المقتطعة + مساهمة المدرسة + المجموع العام + التفقيط + نص المادة 6
    $deducted = $totQ + $totHalf + $totMisc;   // المحسومات المقتطعة = الفصلية + نصف الراتب + المختلف
    $grand = $deducted + $totQ;                // + مساهمة المدرسة (توازي المحسومات الفصلية 6%)
    ?>
    <div style="display:flex;justify-content:space-between;gap:24px;margin-top:14px;font-size:12pt;line-height:2">
        <div style="flex:1">
            <div>عدد الأساتذة: <strong><?= count($rows) ?></strong></div>
            <div><strong>فقط <?= e(numToArabicWords($grand)) ?> ليرة لبنانية لا غير</strong></div>
            <div style="margin-top:8px;font-size:12pt">ملاحظة: نص المادة 6 من المرسوم الاشتراعي رقم 47 تاريخ 29/06/1983<br>
                تتكوّن المحسومات من:<br>
                1- نصف راتب الشهر الأول من خدمة الموظف .<br>
                2- ستة بالمئة من الراتب الشهري .<br>
                3- القسط الأول من كل زيادة تطرأ على الراتب .</div>
        </div>
        <div style="min-width:330px">
            <table style="border-collapse:collapse;width:100%;font-size:12pt">
                <tr><td style="padding:3px 8px">المحسومات المقتطعة</td>
                    <td class="num" style="padding:3px 8px;border-bottom:1px solid #333"><?= formatLBP($totQ,false) ?> + <?= formatLBP($totHalf+$totMisc,false) ?></td>
                    <td style="padding:3px 8px">=</td><td class="num" style="padding:3px 8px"><strong><?= formatLBP($deducted,false) ?></strong></td></tr>
                <tr><td style="padding:3px 8px">مساهمة المدرسة</td>
                    <td class="num" style="padding:3px 8px"><?= formatLBP($totQ,false) ?></td>
                    <td style="padding:3px 8px">=</td><td class="num" style="padding:3px 8px"><strong><?= formatLBP($totQ,false) ?></strong></td></tr>
                <tr><td style="padding:6px 8px;font-weight:700">المجموع العام:</td><td></td><td></td>
                    <td class="num" style="padding:6px 8px;border-top:2px solid #333"><strong><?= formatLBP($grand,false) ?></strong></td></tr>
            </table>
            <div style="margin-top:16px;line-height:2.2">
                نظّم وصدّق على مسؤوليتي بتاريخ <span style="display:inline-block;min-width:110px;border-bottom:1px dotted #475569">&nbsp;</span><br>
                توقيع المدير أو من يقوم مقامه<br><br>
                ختم المدرسة
            </div>
        </div>
    </div>
</div>

<?php elseif ($form === 'salary_all'):
    $stmt = $db->prepare("SELECT e.employee_type, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr, ms.*
                          FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                          WHERE ms.month=? AND ms.year=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)" . $ofMonthFilter . " AND" . schoolScopeWhere('e.school_id') . "
                          ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $stmt->execute(array_merge([$month, $year], $ofMonthParams));
    $rows = $stmt->fetchAll();
    $T = ['base'=>0,'ech'=>0,'bpe'=>0,'extra'=>0,'aide'=>0,'caisse'=>0,'txb'=>0,'tax'=>0,'cnss'=>0,'ded'=>0,'fam'=>0,'trans'=>0,'due'=>0,'net'=>0];
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="salary_all">
        <div class="card-body form-row cols-3">
            <div class="form-group mb-0"><label class="form-label">Mois / الشهر</label><select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m,'ar')?></option><?php endfor; ?></select></div>
            <div class="form-group mb-0"><label class="form-label">Année / السنة</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
<?php
    // تسمية العملة المعروضة (تظهر تحت عنوان الكشف الرسمي)
    $curMode = displayCurrency();
    $curLbl = ($curMode === 'usd') ? 'العملة: دولار أميركي' : (($curMode === 'both') ? 'العملة: ليرة لبنانية + دولار أميركي' : 'العملة: ليرة لبنانية');
?>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">كشف الرواتب والأجور الشهري — <?= monthName($month,'ar').' '.$year ?></div>
    <div class="doc-subtitle"><?= e($curLbl) ?></div>
    <table class="doc-table">
        <thead><tr>
            <th>#</th><th>الاسم</th><th>أساس الراتب</th><th>درجة عادية واستثنائية</th><th>الراتب بعد التدرّج</th>
            <?= extraAideHeads() ?>
            <th style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th>
            <th>صندوق التعويضات ٦٪</th><th>الراتب الخاضع للضريبة</th><th>ضريبة الدخل</th><th>الضمان الاجتماعي</th>
            <th>مجموع المحسومات</th><th>تعويض عائلي</th><?= transportHead('', 'تعويض نقل') ?><th>مجموع المدفوعات</th><th>الصافي</th>
            <th>توقيع الموظف</th>
        </tr></thead>
        <tbody>
        <?php
        $zeroT = array_fill_keys(array_keys($T), 0);
        foreach (['extra_usd','aide_usd','trans_usd','composed','composed_usd'] as $uk) { $zeroT[$uk]=0.0; if(!isset($T[$uk])) $T[$uk]=0.0; }
        // صفّ مجاميع (فرعي لفئة أو إجمالي عام) بنفس ترتيب أعمدة الجدول
        $drawTotal = function($label, $a, $isGrand) {
            $bg = $isGrand ? '' : 'background:#e0e7ff;'; $cls = $isGrand ? 'total-row' : 'subtotal-row'; ?>
            <tr class="<?= $cls ?>" style="<?= $bg ?>font-weight:700"><td colspan="2" style="text-align:right"><?= e($label) ?></td>
                <td class="num"><?= formatLBP($a['base'],false) ?></td><td class="num"><?= formatLBP($a['ech'],false) ?></td>
                <td class="num"><?= formatLBP($a['bpe'],false) ?></td>
                <?= extraAideTotalCells($a['extra'],$a['extra_usd'],$a['aide'],$a['aide_usd']) ?>
                <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($a['composed'],$a['composed_usd'],false) ?></strong></td>
                <td class="num"><?= formatLBP($a['caisse'],false) ?></td>
                <td class="num"><?= formatLBP($a['txb'],false) ?></td><td class="num"><?= formatLBP($a['tax'],false) ?></td>
                <td class="num"><?= formatLBP($a['cnss'],false) ?></td><td class="num"><?= formatLBP($a['ded'],false) ?></td>
                <td class="num"><?= formatLBP($a['fam'],false) ?></td><?= transportTotalCell($a['trans'],$a['trans_usd']) ?>
                <td class="num"><?= formatLBP($a['due'],false) ?></td><td class="num"><strong><?= formatLBP($a['net'],false) ?></strong></td>
                <td></td>
            </tr>
        <?php };
        $nn=0; $curCat=null; $sub=$zeroT;
        foreach ($rows as $r):
            $cat = $r['employee_type'];
            if ($cat !== $curCat):
                if ($curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                $sub = $zeroT; $curCat = $cat;
                ?><tr class="cat-row"><td colspan="<?= 15 + compColsCount() ?>" style="text-align:right;font-weight:700;background:#dbeafe"><?= e(empCategoryTitle($cat)) ?></td></tr><?php
            endif;
            $rRate = rowRate($r);
            $add = ['base'=>$r['base_salary_lbp'],'ech'=>$r['echelon_value_lbp'],'bpe'=>$r['base_plus_echelon_lbp'],'extra'=>extraWageLbp($r),'aide'=>aideCompLbp($r),'caisse'=>$r['caisse_amount_lbp'],'txb'=>$r['taxable_base_lbp'],'tax'=>$r['income_tax_lbp'],'cnss'=>$r['cnss_amount_lbp'],'ded'=>$r['total_retenues_lbp'],'fam'=>$r['family_allowance_lbp'],'trans'=>$r['transport_lbp'],'due'=>dueShownLbp($r),'net'=>$r['net_salary_lbp'],
                    'extra_usd'=>lbpToUsd(extraWageLbp($r),$rRate),'aide_usd'=>lbpToUsd(aideCompLbp($r),$rRate),'trans_usd'=>lbpToUsd((int)$r['transport_lbp'],$rRate),
                    'composed'=>composedSalaryLbp($r),'composed_usd'=>lbpToUsd(composedSalaryLbp($r),$rRate)];
            foreach ($add as $k=>$val) { $T[$k]+=$val; $sub[$k]+=$val; } ?>
            <tr><td><?= ++$nn ?></td>
                <td style="text-align:right"><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td class="num"><?= formatLBP($r['base_salary_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['echelon_value_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['base_plus_echelon_lbp'],false) ?></td>
                <?= extraAideCells($r) ?>
                <td class="num" style="background:#eef2ff"><strong><?= money(composedSalaryLbp($r), $rRate, ['withCur'=>false]) ?></strong></td>
                <td class="num"><?= formatLBP($r['caisse_amount_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['taxable_base_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['income_tax_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['cnss_amount_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['total_retenues_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['family_allowance_lbp'],false) ?></td>
                <?= transportCell($r) ?>
                <td class="num"><?= formatLBP(dueShownLbp($r),false) ?></td>
                <td class="num"><strong><?= formatLBP($r['net_salary_lbp'],false) ?></strong></td>
                <td style="min-width:60px"></td></tr>
        <?php endforeach;
        if ($rows && $curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
        if(!$rows): ?><tr><td colspan="<?= 15 + compColsCount() ?>" class="text-center">لا توجد رواتب محسوبة لهذا الشهر</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><?php $drawTotal('المجموع العام ('.count($rows).')', $T, true); ?></tfoot><?php endif; ?>
    </table>
    <div class="sign-row">
        <?= signatureBox('إعداد: المحاسب') ?>
        <?= signatureBox('تدقيق: مدير الموارد البشرية') ?>
        <?= signatureBox('اعتماد: المدير العام مع الختم', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?>
    </div>
</div>

<?php elseif ($form === 'differences'):
    [$cy1] = schoolYearToYears($schoolYear);
    $prevSY = ($cy1-1).'-'.$cy1;
    $q = $db->prepare("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
                   SUM(CASE WHEN ms.school_year=? THEN ms.net_salary_lbp ELSE 0 END) AS cur,
                   SUM(CASE WHEN ms.school_year=? THEN ms.net_salary_lbp ELSE 0 END) AS prev,
                   SUM(CASE WHEN ms.school_year=? THEN ms.extra_lbp+ms.prime_fixe_lbp ELSE 0 END) AS extra_wage,
                   SUM(CASE WHEN ms.school_year=? THEN (ms.extra_lbp+ms.prime_fixe_lbp)/NULLIF(ms.exchange_rate,0) ELSE 0 END) AS extra_wage_usd,
                   SUM(CASE WHEN ms.school_year=? THEN ms.aide_complementaire_lbp ELSE 0 END) AS aide,
                   SUM(CASE WHEN ms.school_year=? THEN ms.aide_complementaire_lbp/NULLIF(ms.exchange_rate,0) ELSE 0 END) AS aide_usd,
                   SUM(CASE WHEN ms.school_year=? THEN ms.base_plus_echelon_lbp ELSE 0 END) AS bpe,
                   SUM(CASE WHEN ms.school_year=? THEN ms.base_plus_echelon_lbp/NULLIF(ms.exchange_rate,0) ELSE 0 END) AS bpe_usd,
                   SUM(CASE WHEN ms.school_year=? THEN ms.transport_lbp ELSE 0 END) AS transport,
                   SUM(CASE WHEN ms.school_year=? THEN ms.transport_lbp/NULLIF(ms.exchange_rate,0) ELSE 0 END) AS transport_usd,
                   SUM(CASE WHEN ms.school_year=? THEN ms.base_salary_lbp ELSE 0 END) AS base_salary
            FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
            WHERE e.is_deleted=0 AND ms.school_year IN (?, ?) AND " . schoolScopeWhere('e.school_id') . "
            GROUP BY e.id, e.employee_type HAVING (cur>0 OR prev>0) ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $q->execute([$schoolYear, $prevSY, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $schoolYear, $prevSY]);
    $rows = $q->fetchAll();
    $tc=0;$tp=0;$tEx=0;$tAi=0;$tBase=0;
?>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">كشف الفروقات — <?= e($prevSY) ?> مقابل <?= e($schoolYear) ?></div>
    <table class="doc-table">
        <thead><tr><th>#</th><th>الاسم والشهرة</th><th>أساس الراتب</th><?= extraAideHeads() ?><th style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th><th>صافي <?= e($prevSY) ?></th><th>صافي <?= e($schoolYear) ?></th><th>الفرق</th></tr></thead>
        <tbody>
        <?php
        $zD = ['base'=>0,'ex'=>0,'ai'=>0,'prev'=>0,'cur'=>0,'ex_usd'=>0.0,'ai_usd'=>0.0,'composed'=>0,'composed_usd'=>0.0]; $G=$zD;
        $drawTotal = function($label,$a,$isGrand){
            $bg=$isGrand?'':'background:#e0e7ff;'; $cls=$isGrand?'total-row':'subtotal-row'; $d=$a['cur']-$a['prev']; ?>
            <tr class="<?= $cls ?>" style="<?= $bg ?>font-weight:700"><td colspan="2" style="text-align:right"><?= e($label) ?></td>
                <td class="num"><?= formatLBP($a['base'],false) ?></td>
                <?= extraAideTotalCells($a['ex'],$a['ex_usd'],$a['ai'],$a['ai_usd']) ?>
                <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($a['composed'],$a['composed_usd'],false) ?></strong></td>
                <td class="num"><?= formatLBP($a['prev'],false) ?></td><td class="num"><?= formatLBP($a['cur'],false) ?></td>
                <td class="num" style="color:<?= $d>=0?'#15803d':'#b91c1c' ?>"><strong><?= ($d>=0?'+':'').formatLBP($d,false) ?></strong></td></tr>
        <?php };
        $nn=0; $curCat=null; $sub=$zD;
        foreach ($rows as $r):
            $cat=$r['employee_type'];
            if ($cat !== $curCat):
                if ($curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                $sub=$zD; $curCat=$cat;
                ?><tr class="cat-row"><td colspan="<?= 7 + compColsCount(false) ?>" style="text-align:right;font-weight:700;background:#dbeafe"><?= e(empCategoryTitle($cat)) ?></td></tr><?php
            endif;
            $compL = (int)$r['bpe'] + (salaryCompHas('extra')?(int)$r['extra_wage']:0) + (salaryCompHas('aide')?(int)$r['aide']:0) + (salaryCompHas('transport')?(int)$r['transport']:0);
            $compU = (float)$r['bpe_usd'] + (salaryCompHas('extra')?(float)$r['extra_wage_usd']:0) + (salaryCompHas('aide')?(float)$r['aide_usd']:0) + (salaryCompHas('transport')?(float)$r['transport_usd']:0);
            $add=['base'=>(int)$r['base_salary'],'ex'=>(int)$r['extra_wage'],'ai'=>(int)$r['aide'],'prev'=>(int)$r['prev'],'cur'=>(int)$r['cur'],'ex_usd'=>(float)$r['extra_wage_usd'],'ai_usd'=>(float)$r['aide_usd'],'composed'=>$compL,'composed_usd'=>$compU];
            foreach ($add as $k=>$v){ $G[$k]+=$v; $sub[$k]+=$v; }
            $diff=$r['cur']-$r['prev']; ?>
            <tr><td><?= ++$nn ?></td>
                <td style="text-align:right"><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td class="num"><?= formatLBP((int)$r['base_salary'],false) ?></td>
                <?php if (salaryCompHas('extra')): ?><td class="num"><?= dualFromUsd((int)$r['extra_wage'],(float)$r['extra_wage_usd'],false) ?></td><?php endif; ?>
                <?php if (salaryCompHas('aide')): ?><td class="num"><?= dualFromUsd((int)$r['aide'],(float)$r['aide_usd'],false) ?></td><?php endif; ?>
                <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($compL,$compU,false) ?></strong></td>
                <td class="num"><?= formatLBP($r['prev'],false) ?></td>
                <td class="num"><?= formatLBP($r['cur'],false) ?></td>
                <td class="num" style="color:<?= $diff>=0?'#15803d':'#b91c1c' ?>"><strong><?= ($diff>=0?'+':'').formatLBP($diff,false) ?></strong></td></tr>
        <?php endforeach;
        if ($rows && $curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
        if(!$rows): ?><tr><td colspan="<?= 7 + compColsCount(false) ?>" class="text-center">لا توجد بيانات للمقارنة</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><?php $drawTotal('المجموع العام', $G, true); ?></tfoot><?php endif; ?>
    </table>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'general_report'):
    // الموازنة السنوية المقدّرة على الرواتب والأجور والمحسومات القانونية (مطابق Ecole.exe — p12)
    // سطر لكل مدرسة مختارة + سطر مجموع.
    $q = $db->prepare("SELECT ms.school_id,
            SUM(ms.base_salary_lbp) base_salary,
            SUM(ms.base_plus_echelon_lbp) bpe,
            SUM(ms.base_plus_echelon_lbp/NULLIF(ms.exchange_rate,0)) bpe_usd,
            SUM(ms.net_salary_lbp) net,
            SUM(ms.extra_lbp + ms.prime_fixe_lbp) extra_wage,
            SUM((ms.extra_lbp + ms.prime_fixe_lbp)/NULLIF(ms.exchange_rate,0)) extra_wage_usd,
            SUM(ms.aide_complementaire_lbp) aide,
            SUM(ms.aide_complementaire_lbp/NULLIF(ms.exchange_rate,0)) aide_usd,
            SUM(ms.transport_lbp) transport,
            SUM(ms.transport_lbp/NULLIF(ms.exchange_rate,0)) transport_usd,
            SUM(ms.cnss_amount_lbp + ms.school_cnss_8_lbp) cnss,
            SUM(ms.caisse_amount_lbp + ms.eoc_grade_lbp + ms.school_eoc_6_lbp) eoc,
            SUM(ms.income_tax_lbp) tax
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0" . $ofYearFilter . " AND ms.school_year=? AND " . schoolScopeWhere('ms.school_id') . " GROUP BY ms.school_id ORDER BY ms.school_id");
    $q->execute(array_merge($ofYearParams, [$schoolYear]));
    $sdata = $q->fetchAll();
    $T = ['base'=>0,'netNo'=>0,'extra'=>0,'aide'=>0,'trans'=>0,'netWith'=>0,'cnss'=>0,'eoc'=>0,'tax'=>0,'total'=>0,'extra_usd'=>0.0,'aide_usd'=>0.0,'trans_usd'=>0.0,'composed'=>0,'composed_usd'=>0.0];
    $boxName = (count($sdata)===1) ? schoolNameById($sdata[0]['school_id'],'ar') : 'المدارس المختارة';
?>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:8px">
        <div style="flex:1;text-align:center">
            <div style="font-weight:700;font-size:13pt">الموازنة السنوية المقدّرة على الرواتب والأجور</div>
            <div style="font-size:12pt">والمحسومات القانونية المتوجبة</div>
            <div style="font-weight:700;margin-top:6px"><?= e($schoolYear) ?></div>
        </div>
        <div style="border:1.5px solid #b8860b;border-radius:6px;padding:10px 16px;font-weight:700;text-align:center;min-width:160px">
            <?= e($boxName) ?>
        </div>
    </div>
    <table class="doc-table" style="margin-top:8px">
        <thead><tr>
            <th>اسم المؤسسة</th>
            <th>أساس الراتب</th>
            <th>الرواتب الصافية<br>بدون النقل</th><?= extraAideHeads() ?><th style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th><?= transportHead() ?><th>الرواتب الصافية<br>مع تعويض النقل</th>
            <th>الضمان الاجتماعي</th><th>صندوق التعويضات</th><th>ضريبة الدخل</th><th>المجموع</th>
        </tr></thead>
        <tbody>
        <?php foreach ($sdata as $sd):
            $net=(int)$sd['net']; $trans=(int)$sd['transport']; $cnss=(int)$sd['cnss']; $eoc=(int)$sd['eoc']; $tax=(int)$sd['tax'];
            $exW=(int)$sd['extra_wage']; $aid=(int)$sd['aide']; $bse=(int)$sd['base_salary'];
            $exWu=(float)($sd['extra_wage_usd']??0); $aidu=(float)($sd['aide_usd']??0); $transu=(float)($sd['transport_usd']??0);
            $bpe=(int)($sd['bpe']??0); $bpeu=(float)($sd['bpe_usd']??0);
            $composed = $bpe + (salaryCompHas('extra')?$exW:0) + (salaryCompHas('aide')?$aid:0) + (salaryCompHas('transport')?$trans:0);
            $composedu = $bpeu + (salaryCompHas('extra')?$exWu:0) + (salaryCompHas('aide')?$aidu:0) + (salaryCompHas('transport')?$transu:0);
            // 🔴 «الأرقام تركب»: عمود النقل يتبع زرّ «الراتب يشمل»، فإن كان مخفياً وجب
            // ألّا يدخل في «الصافية مع النقل» ولا في «المجموع» — وإلّا ظهرت قفزة بلا عمود يفسّرها.
            $transShown = salaryCompHas('transport') ? $trans : 0;
            $netWith=$net+$transShown; $tot=$netWith+$cnss+$eoc+$tax;
            $T['base']+=$bse;$T['netNo']+=$net;$T['extra']+=$exW;$T['aide']+=$aid;$T['trans']+=$trans;$T['netWith']+=$netWith;$T['cnss']+=$cnss;$T['eoc']+=$eoc;$T['tax']+=$tax;$T['total']+=$tot;
            $T['extra_usd']+=$exWu;$T['aide_usd']+=$aidu;$T['trans_usd']+=$transu;$T['composed']+=$composed;$T['composed_usd']+=$composedu;
        ?>
            <tr>
                <td style="text-align:right;font-weight:600"><?= e(schoolNameById($sd['school_id'],'ar')) ?></td>
                <td class="num"><?= formatLBP($bse,false) ?></td>
                <td class="num"><?= formatLBP($net,false) ?></td>
                <?php if (salaryCompHas('extra')): ?><td class="num"><?= dualFromUsd($exW,$exWu,false) ?></td><?php endif; ?>
                <?php if (salaryCompHas('aide')): ?><td class="num"><?= dualFromUsd($aid,$aidu,false) ?></td><?php endif; ?>
                <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($composed,$composedu,false) ?></strong></td>
                <?php if (salaryCompHas('transport')): ?><td class="num"><?= dualFromUsd($trans,$transu,false) ?></td><?php endif; ?>
                <td class="num"><?= formatLBP($netWith,false) ?></td>
                <td class="num"><?= formatLBP($cnss,false) ?></td>
                <td class="num"><?= formatLBP($eoc,false) ?></td>
                <td class="num"><?= formatLBP($tax,false) ?></td>
                <td class="num"><strong><?= formatLBP($tot,false) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$sdata): ?><tr><td colspan="<?= 9 + compColsCount() ?>" class="text-center">لا توجد بيانات لهذه السنة</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row">
            <td>المجموع</td>
            <td class="num"><?= formatLBP($T['base'],false) ?></td>
            <td class="num"><?= formatLBP($T['netNo'],false) ?></td>
            <?= extraAideTotalCells($T['extra'],$T['extra_usd'],$T['aide'],$T['aide_usd']) ?>
            <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($T['composed'],$T['composed_usd'],false) ?></strong></td>
            <?= transportTotalCell($T['trans'],$T['trans_usd']) ?>
            <td class="num"><?= formatLBP($T['netWith'],false) ?></td>
            <td class="num"><?= formatLBP($T['cnss'],false) ?></td>
            <td class="num"><?= formatLBP($T['eoc'],false) ?></td>
            <td class="num"><?= formatLBP($T['tax'],false) ?></td>
            <td class="num"><strong><?= formatLBP($T['total'],false) ?></strong></td>
        </tr></tfoot>
    </table>
    <div class="sign-row"><?= signatureBox('المحاسب', '', '') ?><?= signatureBox('المدير', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php
/* ========================================================================
 *  نماذج إضافية — وزارة المالية (ر4/ر7) والضمان (190A/351/215A/207/2-AA/2M/482)
 * ====================================================================== */
elseif ($form === 'tax_r4'): // بيان معلومات من الأجير إلى رب العمل + جدول العيال ?>
<div class="official-doc mof-form rtl" id="ppExportArea">
    <div class="mof-head">
        <div class="mof-gov">الجمهورية اللبنانية<br>وزارة المالية<br>مديرية المالية العامة<br>مديرية الواردات – ضريبة الرواتب والأجور</div>
        <div class="mof-titles"><div class="mof-title">بيان معلومات من المستخدم/الأجير إلى رب العمل</div></div>
        <div class="mof-code">ر ٤</div>
    </div>
    <div class="mof-body">
        <div class="info-grid">
            <div><span class="k">إسم الشركة/المؤسسة:</span> <?= fillVal($school['name_ar']) ?></div>
            <div><span class="k">رقم التسجيل:</span> <?= digitBoxes($school['finance_number'],10) ?></div>
        </div>
        <div class="doc-section">تعريف</div>
        <div class="info-grid">
            <div><span class="k">١. الإسم:</span> <?= fillVal($emp['first_name_ar'] ?: $emp['first_name_fr']) ?></div>
            <div><span class="k">٢. الشهرة:</span> <?= fillVal($emp['last_name_ar'] ?: $emp['last_name_fr']) ?></div>
            <div><span class="k">٣. إسم الأب:</span> <?= fillVal($emp['father_name_ar']) ?></div>
            <div><span class="k">٤. إسم الأم وشهرتها:</span> <?= fillVal(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
            <div><span class="k">٥. الجنس:</span> <?= cbox('ذكر') ?><?= cbox('أنثى') ?></div>
            <div><span class="k">٦. الجنسية:</span> <?= fillVal($emp['nationality']==='lebanese'?'لبنانية':$emp['nationality']) ?></div>
            <div><span class="k">٧. محل الولادة:</span> <?= fillVal($emp['birth_place']) ?></div>
            <div><span class="k">٨. تاريخ الولادة:</span> <?= fillVal(formatDate($emp['birth_date'])) ?></div>
            <div><span class="k">٩. رقم السجل:</span> <?= fillVal($emp['civil_registry_number']) ?></div>
            <div><span class="k">١٠. مكان السجل:</span> <?= fillVal($emp['civil_registry_place']) ?></div>
            <div><span class="k">١٢. الوضع العائلي:</span> <?= cbox('أعزب',!$isMarried) ?><?= cbox('متزوج',$isMarried) ?><?= cbox('أرمل') ?><?= cbox('مطلق') ?></div>
            <div><span class="k">١٤. عدد الأولاد على العاتق:</span> <?= fillVal($emp['number_of_children']) ?></div>
        </div>

        <div class="doc-section">جدول الأشخاص على عاتق المستخدم/الأجير لغاية احتساب التنزيل العائلي</div>
        <table class="doc-table">
            <thead><tr><th>#</th><th>الإسم والشهرة</th><th>صلة القرابة</th><th>تاريخ الولادة</th><th>الوضع العائلي</th><th>التنزيل العائلي (ل.ل)</th></tr></thead>
            <tbody>
            <?php
                $deps = [];
                if ($isMarried && !$emp['spouse_works']) $deps[] = ['', 'الزوج/الزوجة', '', 'متأهل'];
                for ($c=1; $c <= (int)$emp['number_of_children']; $c++) $deps[] = ['', 'ولد', '', 'أعزب'];
                if (!$deps) $deps[] = ['','','',''];
                foreach ($deps as $i=>$d): ?>
                <tr><td><?= $i+1 ?></td><td><?= fillVal($d[0]) ?></td><td><?= e($d[1]) ?></td><td><?= fillVal($d[2]) ?></td><td><?= e($d[3]) ?></td><td>&nbsp;</td></tr>
            <?php endforeach; ?>
                <tr class="total-row"><td colspan="5">المجموع ل.ل</td><td>&nbsp;</td></tr>
            </tbody>
        </table>
        <div class="sign-row"><?= signatureBox('إسم المستخدم/الأجير والتوقيع', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
    </div>
</div>

<?php elseif ($form === 'tax_r7'): // كشف تاركي العمل خلال السنة (مؤسسة)
    [$y1,$y2] = schoolYearToYears($schoolYear);
    $start = "$y1-10-01"; $end = "$y2-09-30";
    $q = $db->prepare("SELECT * FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . "
        AND ( (left_date_cnss BETWEEN ? AND ?) OR (left_date_finance BETWEEN ? AND ?) OR (left_date_eoc BETWEEN ? AND ?) )
        ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $q->execute([$start,$end,$start,$end,$start,$end]);
    $rows = $q->fetchAll();
?>
<div class="official-doc mof-form rtl" id="ppExportArea">
    <div class="mof-head">
        <div class="mof-gov">الجمهورية اللبنانية<br>وزارة المالية<br>مديرية المالية العامة<br>مديرية الواردات – ضريبة الرواتب والأجور</div>
        <div class="mof-titles"><div class="mof-title">كشف إجمالي بالمستخدمين/الأجراء<br>الذين تركوا العمل خلال السنة</div></div>
        <div class="mof-code">ر ٧</div>
    </div>
    <div class="mof-body">
        <div class="info-grid">
            <div><span class="k">إسم المؤسسة:</span> <?= fillVal($school['name_ar']) ?></div>
            <div><span class="k">عن أعمال سنة:</span> <?= fillVal((int)$y1) ?></div>
        </div>
        <table class="doc-table">
            <thead><tr><th>#</th><th>إسم المستخدم/الأجير (الثلاثي)</th><th>رقم التسجيل الشخصي</th><th>رقم الانتساب (الضمان)</th><th>تاريخ بدء العمل</th><th>تاريخ انتهاء العمل</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i=>$r):
                $left = $r['left_date_cnss'] ?: ($r['left_date_finance'] ?: $r['left_date_eoc']); ?>
                <tr><td><?= $i+1 ?></td>
                    <td><?= e(trim(($r['first_name_ar'].' '.$r['father_name_ar'].' '.$r['last_name_ar'])) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                    <td><?= e($r['finance_ministry_number']) ?></td>
                    <td><?= e($r['nssf_number']) ?></td>
                    <td><?= formatDate($r['hire_date']) ?></td>
                    <td><?= formatDate($left) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="6" class="text-center">لا أحد ترك العمل خلال هذه السنة</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="sign-row"><?= signatureBox('توقيع ممثل المؤسسة وخاتمها', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
    </div>
</div>

<?php elseif ($form === 'cnss_contrib_monthly' || $form === 'cnss_contrib_annual'):
    // تصريح الضمان — نفس فورمة Excel (CNSS 190A). شهري = شهر واحد، فصلي = 3 أشهر حسب الفصل.
    $isQuarter = ($form === 'cnss_contrib_annual');
    $qNames = [1=>'الفصل الأول (كانون الثاني - شباط - آذار)', 2=>'الفصل الثاني (نيسان - أيار - حزيران)',
               3=>'الفصل الثالث (تموز - آب - أيلول)', 4=>'الفصل الرابع (تشرين الأول - تشرين الثاني - كانون الأول)'];
    $quarter = max(1, min(4, (int)($_GET['quarter'] ?? ceil($month/3))));
    if ($isQuarter) { $months = [($quarter-1)*3+1, ($quarter-1)*3+2, ($quarter-1)*3+3]; $plabel = $qNames[$quarter].' '.$year; }
    else           { $months = [$month]; $plabel = monthName($month,'ar').' '.$year; }
    $ph = implode(',', array_fill(0, count($months), '?'));
    // الاشتراكات محسوبة بالبرنامج لكل فرع (مع الحد الأقصى للراتب المخزّن أصلاً):
    //  - فرع المرض والأمومة (١١٪ = ٣٪ أجير + ٨٪ مؤسسة): يخضع له **الجميع** (أساتذة + موظفون).
    //  - فرع نهاية الخدمة (٨.٥٪) + فرع التعويضات العائلية (٦٪): يخضع له **الموظفون فقط**
    //    (الأستاذ ياخذ نهاية خدمته من صندوق التعويضات لا من الضمان).
    // عدد الأجراء واشتراكاتهم يُحتسبان من **نفس مصدر بيان صندوق التعويضات** عبر
    // yearEmploymentFilter: يستبعد المحذوفين والتاركين (أي تاريخ ترك ضمان/مالية/صندوق)
    // والصفوف الصفرية، فلا تُحتسب «صفوف أشباح» لموظفين سابقين تركها الاستيراد على جدول
    // الرواتب. السنة الدراسية للفترة المصرَّح عنها (تشرين الأول يبدأ سنة جديدة):
    $periodSY = ($months[0] >= 10) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
    [$yf, $yp] = yearEmploymentFilter($periodSY, 'e.');
    $params = array_merge($yp, [$year], $months);
    $q1 = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN (ms.cnss_amount_lbp+ms.school_cnss_8_lbp)>0 THEN ms.employee_id END) n,
            COALESCE(SUM(ms.cnss_amount_lbp + ms.school_cnss_8_lbp),0) c
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.cnss_subject=1 AND e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month IN ($ph) AND " . schoolScopeWhere('ms.school_id'));
    $q1->execute($params); $a = $q1->fetch();
    $q2 = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN ms.school_end_of_service_8_5_lbp>0 THEN ms.employee_id END) n,
            COALESCE(SUM(ms.school_end_of_service_8_5_lbp),0) c
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.employee_type='employe' AND e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month IN ($ph) AND " . schoolScopeWhere('ms.school_id'));
    $q2->execute($params); $t = $q2->fetch();
    $q2b = $db->prepare("SELECT COUNT(DISTINCT CASE WHEN ms.school_family_comp_6_lbp>0 THEN ms.employee_id END) n,
            COALESCE(SUM(ms.school_family_comp_6_lbp),0) c
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.employee_type='employe' AND e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month IN ($ph) AND " . schoolScopeWhere('ms.school_id'));
    $q2b->execute($params); $fam = $q2b->fetch();
    $q3 = $db->prepare("SELECT COALESCE(SUM(ms.family_allowance_lbp),0) f FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0" . $yf . " AND ms.year=? AND ms.month IN ($ph) AND " . schoolScopeWhere('ms.school_id'));
    $q3->execute($params); $fpaid = (int)$q3->fetchColumn();
    // الاشتراك المخزّن لكل فرع، والأجور = الاشتراك ÷ المعدل (الأساس الفعلي بعد الحد الأقصى)
    $c1=(int)$a['c']; $n1=(int)$a['n']; $w1=$c1 ? (int)round($c1/cnssTotalFrac($month, $year)) : 0;
    $c2=(int)$t['c']; $n2=(int)$t['n']; $w2=$c2 ? (int)round($c2/rateFrac('end_of_service_rate', $month, $year, 8.5)) : 0;
    $c3=(int)$fam['c']; $n3=(int)$fam['n']; $w3=$c3 ? (int)round($c3/rateFrac('family_compensation_rate', $month, $year, 6)) : 0;
    $total=$c1+$c2+$c3; $net=$total-$fpaid;
    $vars = [
        'school'=>$school['name_ar'], 'empno'=>$school['nssf_employer_number'], 'year'=>$year, 'plabel'=>$plabel,
        'n1'=>$n1, 'w1'=>formatLBP($w1,false), 'c1'=>formatLBP($c1,false),
        'n2'=>$n2, 'w2'=>formatLBP($w2,false), 'c2'=>formatLBP($c2,false),
        'n3'=>$n3, 'w3'=>formatLBP($w3,false), 'c3'=>formatLBP($c3,false),
        'total'=>formatLBP($total,false), 'fpaid'=>formatLBP($fpaid,false), 'net'=>formatLBP($net,false),
    ];
    $ofExp = BASE_URL . 'pages/official_export.php?form=' . e($form) . '&month=' . (int)$month . '&year=' . (int)$year;
?>
    <?php if (!$isQuarter): ?>
    <div class="no-print" style="background:#e9f9ee;border:2px solid #1a7f37;border-radius:10px;padding:14px;margin-bottom:14px;text-align:center">
        <div style="font-weight:700;margin-bottom:8px;font-size:15px">📥 Formulaire officiel conforme (rempli automatiquement) — cliquez pour télécharger / النموذج الرسمي طبق الأصل (معبّى تلقائياً) — اكبس وحمّل:</div>
        <a class="btn btn-danger btn-lg" href="<?= $ofExp ?>&format=pdf"><i class="fas fa-file-pdf"></i> Télécharger PDF officiel / تحميل PDF رسمي</a>
        <a class="btn btn-success btn-lg" href="<?= $ofExp ?>&format=xlsx"><i class="fas fa-file-excel"></i> Télécharger Excel officiel / تحميل Excel رسمي</a>
    </div>
    <?php endif; ?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="<?= e($form) ?>">
        <div class="card-body form-row cols-3">
            <?php if ($isQuarter): ?>
            <div class="form-group mb-0"><label class="form-label">Trimestre / الفصل</label>
                <select name="quarter" class="form-select">
                    <?php foreach ($qNames as $qn=>$ql): ?><option value="<?=$qn?>" <?=$qn===$quarter?'selected':''?>><?= e($ql) ?></option><?php endforeach; ?>
                </select></div>
            <?php else: ?>
            <div class="form-group mb-0"><label class="form-label">Mois / الشهر</label>
                <select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m,'ar')?></option><?php endfor; ?></select></div>
            <?php endif; ?>
            <div class="form-group mb-0"><label class="form-label">Année / السنة</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
    <div class="official-doc rtl" id="ppExportArea" style="max-width:100%;padding:6mm">
        <?= renderFormTemplate('cnss_190a', $vars) ?>
    </div>

<?php elseif ($form === 'cnss_eos_settle'): // طلب تصفية تعويض نهاية الخدمة (موظف) — نفس فورمة Excel
    $hireD = $emp['hire_date'];
    $leftD = $emp['left_date_cnss'] ?: ($emp['left_date_finance'] ?: ($emp['left_date_eoc'] ?: date('Y-m-d')));
    $years = ($hireD && strtotime($hireD)) ? (int)floor((strtotime($leftD)-strtotime($hireD))/(365.25*86400)) : 0;
    $vars = [
        'empname' => empFullNameAr($emp),
        'nssf'    => $emp['nssf_number'],
        'school'  => $school['name_ar'],
        'empno'   => $school['nssf_employer_number'],
        'address' => $school['address'],
        'years'   => $years > 0 ? ($years.' سنة خدمة') : '',
    ];
?>
    <div class="alert alert-info no-print"><i class="fas fa-info-circle"></i> Formulaire réservé à l'employé administratif (l'enseignant perçoit son indemnité de fin de service de la Caisse d'indemnités, non de la CNSS). / نموذج خاص بالموظف الإداري (الأستاذ ياخذ تعويض نهاية الخدمة من صندوق التعويضات لا من الضمان).</div>
    <div class="official-doc rtl" id="ppExportArea" style="max-width:100%;padding:6mm">
        <?= renderFormTemplate('eos_settle', $vars) ?>
    </div>

<?php elseif ($form === 'cnss_eos_invite' || $form === 'cnss_eos_wage'):
    // 215A دعوة/إفادة تحديد الأجور — 207 إفادة بالأجر الأخير (نهاية الخدمة)
    $isWage = ($form === 'cnss_eos_wage');
    // مجموع الأجور بحسب خيار خضوع الأستاذ (الزرّ الأخضر): الأساس دائماً + الأجر الإضافي/المكافأة إن خاضعَين
    $tot = $db->prepare("SELECT SUM(base_plus_echelon_lbp) base, SUM(extra_lbp+prime_fixe_lbp) exw, SUM(aide_complementaire_lbp) aide FROM monthly_salaries WHERE employee_id=? AND is_calculated=1");
    $tot->execute([$emp['id']]); $tw = $tot->fetch();
    $totExW = (int)($tw['exw'] ?? 0); $totAid = (int)($tw['aide'] ?? 0);
    $totalWages = (int)($tw['base'] ?? 0)
        + (!empty($emp['cnss_includes_extra']) ? $totExW : 0)
        + (!empty($emp['cnss_includes_prime_aide']) ? $totAid : 0);
    $sal = ofLatestSalary($db, $emp['id']);
    $lastSalary = $sal ? cnssSubjectWageLbp($sal, $emp) : 0;
    $lastExW = $sal ? extraWageLbp($sal) : 0; $lastAid = $sal ? aideCompLbp($sal) : 0;
    $eos = round($totalWages*rateFrac('end_of_service_rate', $month, $year, 8.5));
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي
        <div class="sub">المديرية الفنية — دائرة تعويض نهاية الخدمة</div></div>
    <div class="cnss-title"><u>إفـــادة <?= $isWage?'بالأجر أو الكسب الأخير':'المؤسسة بالأجور والاشتراكات' ?></u></div>
    <p style="line-height:2.2;margin-top:18px">
        تفيد مؤسسة <?= fillVal($school['name_ar']) ?> رقمها <?= fillVal($school['nssf_employer_number']) ?>،
        أن المضمون <?= fillVal(empFullNameAr($emp)) ?> رقمه <?= fillVal($emp['nssf_number']) ?>
        عمل لحسابها من تاريخ <?= fillVal(formatDate($emp['hire_date'])) ?>
        لغاية <?= fillVal(formatDate($emp['left_date_cnss'] ?: $emp['left_date_eoc'])) ?>.
    </p>
    <?php if ($isWage): ?>
    <div class="info-grid">
        <div><span class="k">طريقة احتساب الأجر:</span> <?= cbox('شهري',true) ?><?= cbox('يومي') ?><?= cbox('بالساعة') ?></div>
        <div class="full"><span class="k">وبلغ مقدار أجره عن الشهر الأخير مع جميع لواحقه:</span> <strong><?= $lastSalary?formatLBP($lastSalary):fillVal('') ?></strong></div>
        <div><span class="k">منها الأجر الإضافي:</span> <strong><?= formatLBP($lastExW) ?></strong></div>
        <div><span class="k">منها مكافأة ومساعدة:</span> <strong><?= formatLBP($lastAid) ?></strong></div>
    </div>
    <?php else: ?>
    <div class="info-grid">
        <div class="full"><span class="k">بلغ مجموع الأجور المدفوعة:</span> <strong><?= $totalWages?formatLBP($totalWages):fillVal('') ?></strong></div>
        <div><span class="k">منها الأجر الإضافي:</span> <strong><?= formatLBP($totExW) ?></strong></div>
        <div><span class="k">منها مكافأة ومساعدة:</span> <strong><?= formatLBP($totAid) ?></strong></div>
        <div class="full"><span class="k">الاشتراكات المتوجبة للصندوق (المجموع × ٨.٥٪):</span> <strong><?= $totalWages?formatLBP($eos):fillVal('') ?></strong></div>
    </div>
    <?php endif; ?>
    <div class="sign-row">
        <?= signatureBox('إسم المسؤول وصفته وتوقيعه وخاتم المؤسسة', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?>
        <?= signatureBox('إفادة المضمون / صاحب الحق — التوقيع','','') ?>
    </div>
</div>

<?php elseif ($form === 'cnss_employ2'): // 2-AA تصريح باستخدام أجير + جدول العيال
    $sal = ofLatestSalary($db, $emp['id']);
    $salary = $sal ? cnssSubjectWageLbp($sal, $emp) : 0; // يتبع زرّ الخضوع بملف الأستاذ
    $exW = $sal ? extraWageLbp($sal) : 0; $aid = $sal ? aideCompLbp($sal) : 0;
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي</div>
    <div class="cnss-title"><u>تصريح باستخدام أجير</u><br><span style="font-size:12pt;font-weight:600">(يملأ هذا التصريح من قبل صاحب العمل وعلى مسؤوليته)</span></div>
    <div class="doc-section">أ – معلومات خاصة بالمؤسسة</div>
    <div class="info-grid">
        <div class="full"><span class="k">إسم صاحب العمل أو الشركة:</span> <?= fillVal($school['name_ar']) ?></div>
        <div><span class="k">رقم المؤسسة في الصندوق:</span> <?= digitBoxes($school['nssf_employer_number'],8) ?></div>
        <div class="full"><span class="k">عنوان المؤسسة (مكان عمل الأجير):</span> <?= fillVal($school['address']) ?></div>
    </div>
    <div class="doc-section">ب – معلومات خاصة بالأجير</div>
    <div class="fline"><span class="lbl">٣. الجنس :</span> <?= fopt('ذكر',1) ?> <?= fopt('أنثى',2) ?> <span class="lbl">رقمه في الصندوق</span> <?= fval($emp['nssf_number'],'g') ?></div>
    <div class="fline"><span class="lbl">٤. اسم الأجير</span> <?= fval($emp['first_name_ar'] ?: $emp['first_name_fr']) ?> <span class="lbl">الشهرة</span> <?= fval($emp['last_name_ar'] ?: $emp['last_name_fr']) ?></div>
    <div class="fline"><span class="lbl">٥. اسم الأب</span> <?= fval($emp['father_name_ar']) ?> <span class="lbl">اسم الأم وشهرتها</span> <?= fval(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
    <div class="fline"><span class="lbl">٦. تاريخ ومحل الولادة</span> <?= fval(formatDate($emp['birth_date']).' - '.$emp['birth_place']) ?> <span class="lbl">رقم السجل</span> <?= fval($emp['civil_registry_number'],'g') ?></div>
    <div class="fline"><span class="lbl">٧. الوضع العائلي :</span> <?= fopt('أعزب',1,!$isMarried) ?> <?= fopt('متأهل',2,$isMarried) ?> <?= fopt('أرمل',3) ?> <?= fopt('مطلق',4) ?></div>
    <div class="fline"><span class="lbl">٨. محل الإقامة</span> <?= fval($emp['ville']) ?> <span class="lbl">١٠. تاريخ دخول العمل</span> <?= fval(formatDate($emp['hire_date']),'g') ?></div>
    <div class="fline"><span class="lbl">دوام العمل :</span> <?= fopt('كامل',1,true) ?> <?= fopt('جزئي',2) ?></div>
    <div class="fline"><span class="lbl">١١. عمل الأجير الحالي</span> <?= fval(employeeTypeLabel($emp['employee_type'],'ar')) ?> <span class="lbl">الراتب</span> <?= fval($salary?formatLBP($salary,false):'','g') ?> <span class="lbl">ل.ل</span></div>
    <div class="fline"><span class="lbl">منها الأجر الإضافي</span> <?= fval($exW?formatLBP($exW,false):'—','g') ?> <span class="lbl">ل.ل — ومكافأة ومساعدة</span> <?= fval($aid?formatLBP($aid,false):'—','g') ?> <span class="lbl">ل.ل</span></div>
    <div class="fline"><span class="lbl">١٢. طريقة دفع الأجر :</span> <?= fopt('شهري',1,true) ?> <?= fopt('اسبوعي',2) ?> <?= fopt('يومي',3) ?> <?= fopt('لقاء عمولة',4) ?> <?= fopt('على الإنتاج',5) ?></div>
    <div class="doc-section">تصريح عن أفراد عائلة الأجير للإستفادة من التعويضات العائلية</div>
    <table class="doc-table">
        <thead><tr><th>#</th><th>الأفراد على العاتق</th><th>الإسم والشهرة</th><th>تاريخ ومحل الولادة</th><th>ذكر/أنثى</th></tr></thead>
        <tbody>
        <?php
            $deps = [];
            $deps[] = ['والد/والدة الأجير','',''];
            if ($isMarried) $deps[] = ['زوجة/زوج الأجير','',''];
            for ($c=1;$c<=(int)$emp['number_of_children'];$c++) $deps[] = ['الأولاد على العاتق','',''];
            foreach ($deps as $i=>$d): ?>
            <tr><td><?= $i+1 ?></td><td><?= e($d[0]) ?></td><td><?= fillVal($d[1]) ?></td><td><?= fillVal($d[2]) ?></td><td>&nbsp;</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="sign-row">
        <?= signatureBox('اسم الأجير والتوقيع','','') ?>
        <?= signatureBox('ختم المؤسسة واسم وتوقيع الشخص المسؤول', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?>
    </div>
</div>

<?php elseif ($form === 'cnss_work_detail'): // 2M إفادة عمل — مرض (مفصّلة)
    $rows = $db->prepare("SELECT month, year, base_plus_echelon_lbp, extra_lbp, prime_fixe_lbp, aide_complementaire_lbp
                          FROM monthly_salaries WHERE employee_id=? AND is_calculated=1
                          ORDER BY year DESC, month DESC LIMIT 6");
    $rows->execute([$emp['id']]);
    $paid = array_reverse($rows->fetchAll());
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي
        <div class="sub">مديرية ضمان المرض والأمومة</div></div>
    <div class="cnss-title"><u>إفـــادة عمل</u></div>
    <p>إن رب العمل الموقّع أدناه المسؤول عن مؤسسة <?= fillVal($school['name_ar']) ?>
       رقمها في الصندوق <?= fillVal($school['nssf_employer_number']) ?>، يفيد أن الأجير
       <?= fillVal(empFullNameAr($emp)) ?> رقمه في الصندوق <?= fillVal($emp['nssf_number']) ?>
       عمل خلال الستة أشهر السابقة للمرض، وفقاً للتوزيع التالي:</p>
    <table class="doc-table" style="max-width:680px;margin:6px auto">
        <thead><tr><th>الشهر</th><th>عدد أيام/أسابيع العمل الفعلي</th><th>الأجر الإضافي</th><th>مكافأة ومساعدة</th><th>الأجر المدفوع (ل.ل)</th></tr></thead>
        <tbody>
        <?php for ($i=0;$i<6;$i++): $p=$paid[$i] ?? null; ?>
            <tr><td><?= $p?monthName($p['month'],'ar').' '.$p['year']:'&nbsp;' ?></td><td>&nbsp;</td><td class="num"><?= $p?money(extraWageLbp($p),rowRate($p),['withCur'=>false]):'' ?></td><td class="num"><?= $p?money(aideCompLbp($p),rowRate($p),['withCur'=>false]):'' ?></td><td class="num"><?= $p?formatLBP(cnssSubjectWageLbp($p,$emp),false):'' ?></td></tr>
        <?php endfor; ?>
        </tbody>
    </table>
    <div class="info-grid" style="margin-top:8px">
        <div><span class="k">انقطع عن العمل بتاريخ:</span> <span class="fill dotted">&nbsp;</span></div>
        <div><span class="k">عاد إلى العمل بتاريخ:</span> <span class="fill dotted">&nbsp;</span></div>
        <div class="full"><?= cbox('إن أجره قد انقطع خلال فترة مرضه') ?><?= cbox('لم ينقطع') ?> &nbsp; <?= cbox('مرضه ناتج عن طارئ عمل') ?><?= cbox('غير ناتج') ?></div>
    </div>
    <div class="sign-row"><?= signatureBox('اسم وتوقيع المسؤول وخاتم المؤسسة', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
    <div class="doc-note">تاريخ المرض هو تاريخ مرض المضمون نفسه أو تاريخ مرض أحد أفراد عائلته.</div>
</div>

<?php elseif ($form === 'cnss_parent'): // 482 طلب إجراء تحقيق اجتماعي للاستفادة عن الوالد
    $sal = ofLatestSalary($db, $emp['id']);
    $salary = $sal ? $sal['base_plus_echelon_lbp']+$sal['extra_lbp']+$sal['prime_fixe_lbp'] : 0;
?>
<div class="official-doc cnss-form rtl" id="ppExportArea">
    <div class="cnss-head">الصندوق الوطني للضمان الاجتماعي</div>
    <div class="cnss-title"><u>طلب إجراء تحقيق اجتماعي</u><br><span style="font-size:12pt">للاستفادة عن الوالد</span></div>
    <div class="info-grid">
        <div><span class="k">اسم المضمون:</span> <?= fillVal(empFullNameAr($emp)) ?></div>
        <div><span class="k">رقمه في الضمان:</span> <?= fillVal($emp['nssf_number']) ?></div>
        <div><span class="k">المؤسسة ورقمها:</span> <?= fillVal($school['name_ar'].' - '.$school['nssf_employer_number']) ?></div>
        <div><span class="k">الأجر الشهري:</span> <?= fillVal($salary?formatLBP($salary):'') ?></div>
        <div><span class="k">اسم الوالد وتاريخ ولادته:</span> <span class="fill dotted" style="min-width:180px">&nbsp;</span></div>
        <div><span class="k">اسم الوالدة وتاريخ ولادتها:</span> <?= fillVal(trim($emp['mother_first_name'].' '.$emp['mother_last_name'])) ?></div>
        <div class="full"><span class="k">عنوان سكن المضمون:</span> <?= fillVal(empAddress($emp)) ?></div>
        <div class="full"><span class="k">عمل الوالد/الوالدة ومداخيلهما:</span> <span class="fill dotted" style="min-width:260px">&nbsp;</span></div>
    </div>
    <p style="font-size:12pt;margin-top:10px">أتعهّد بأنني معيل لوالدي/لوالدتي، وأفيد على مسؤوليتي بأن جميع المعلومات الواردة أعلاه صحيحة.</p>
    <div class="sign-row"><?= signatureBox('توقيع المضمون','',formatDate(date('Y-m-d'))) ?></div>
    <div class="doc-note">المستندات المرفقة: إفادة عمل من المؤسسة · إخراج قيد عائلي للوالد · إفادة من الإدارات الرسمية · تقرير اللجنة الطبية عند العجز.</div>
</div>

<?php
/* ========================================================================
 *  تقارير إدارية/مالية حديثة
 * ====================================================================== */
elseif ($form === 'payment_list'):
    // كشف الدفع: لائحة الرواتب الصافية للموظفين (لشهر) — للدفع/التحويل المصرفي + توقيع
    $stmt = $db->prepare("SELECT e.employee_type, e.employee_code, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
                                 e.nssf_number, ms.base_salary_lbp, ms.base_plus_echelon_lbp, ms.exchange_rate, ms.net_salary_lbp, ms.total_due_lbp, ms.family_allowance_lbp, ms.transport_lbp,
                                 ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp
                          FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                          WHERE ms.month=? AND ms.year=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)" . $ofMonthFilter . " AND" . schoolScopeWhere('e.school_id') . "
                          ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $stmt->execute(array_merge([$month, $year], $ofMonthParams));
    $rows = $stmt->fetchAll();
    $tNet=0; $tDue=0; $tEx=0; $tAi=0; $tBase=0; $tTrans=0;
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="payment_list">
        <div class="card-body form-row cols-3">
            <div class="form-group mb-0"><label class="form-label">Mois / الشهر</label><select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m,'ar')?></option><?php endfor; ?></select></div>
            <div class="form-group mb-0"><label class="form-label">Année / السنة</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">كشف الدفع — رواتب <?= monthName($month,'ar').' '.$year ?></div>
    <table class="doc-table">
        <thead><tr><th>#</th><th>الرمز</th><th>الاسم والشهرة</th><th>رقم الضمان</th><th>أساس الراتب</th><?= extraAideHeads() ?><th style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th><?= transportHead() ?><th>الصافي (ل.ل)</th><th>الإجمالي المتوجب (ل.ل)</th><th>التوقيع بالاستلام</th></tr></thead>
        <tbody>
        <?php
        $zP = ['base'=>0,'ex'=>0,'ai'=>0,'trans'=>0,'net'=>0,'due'=>0,'ex_usd'=>0.0,'ai_usd'=>0.0,'trans_usd'=>0.0,'composed'=>0,'composed_usd'=>0.0]; $G = $zP;
        $drawTotal = function($label,$a,$isGrand){
            $bg=$isGrand?'':'background:#e0e7ff;'; $cls=$isGrand?'total-row':'subtotal-row'; ?>
            <tr class="<?= $cls ?>" style="<?= $bg ?>font-weight:700"><td colspan="4" style="text-align:right"><?= e($label) ?></td>
                <td class="num"><?= formatLBP($a['base'],false) ?></td>
                <?= extraAideTotalCells($a['ex'],$a['ex_usd'],$a['ai'],$a['ai_usd']) ?>
                <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($a['composed'],$a['composed_usd'],false) ?></strong></td>
                <?= transportTotalCell($a['trans'],$a['trans_usd']) ?>
                <td class="num"><?= formatLBP($a['net'],false) ?></td><td class="num"><?= formatLBP($a['due'],false) ?></td><td></td>
            </tr>
        <?php };
        $nn=0; $curCat=null; $sub=$zP;
        foreach ($rows as $r):
            $cat=$r['employee_type'];
            if ($cat !== $curCat):
                if ($curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                $sub=$zP; $curCat=$cat;
                ?><tr class="cat-row"><td colspan="<?= 9 + compColsCount() ?>" style="text-align:right;font-weight:700;background:#dbeafe"><?= e(empCategoryTitle($cat)) ?></td></tr><?php
            endif;
            $rRate=rowRate($r);
            $add=['base'=>(int)$r['base_salary_lbp'],'ex'=>extraWageLbp($r),'ai'=>aideCompLbp($r),'trans'=>(int)$r['transport_lbp'],'net'=>(int)$r['net_salary_lbp'],'due'=>dueShownLbp($r),
                  'ex_usd'=>lbpToUsd(extraWageLbp($r),$rRate),'ai_usd'=>lbpToUsd(aideCompLbp($r),$rRate),'trans_usd'=>lbpToUsd((int)$r['transport_lbp'],$rRate),
                  'composed'=>composedSalaryLbp($r),'composed_usd'=>lbpToUsd(composedSalaryLbp($r),$rRate)];
            foreach ($add as $k=>$v){ $G[$k]+=$v; $sub[$k]+=$v; } ?>
            <tr>
                <td><?= ++$nn ?></td>
                <td><?= e($r['employee_code']) ?></td>
                <td style="text-align:right"><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td><?= e($r['nssf_number']) ?></td>
                <td class="num"><?= formatLBP($r['base_salary_lbp'],false) ?></td>
                <?= extraAideCells($r) ?>
                <td class="num" style="background:#eef2ff"><strong><?= money(composedSalaryLbp($r), $rRate, ['withCur'=>false]) ?></strong></td>
                <?= transportCell($r) ?>
                <td class="num"><?= formatLBP($r['net_salary_lbp'],false) ?></td>
                <td class="num"><strong><?= formatLBP(dueShownLbp($r),false) ?></strong></td>
                <td style="min-width:90px">&nbsp;</td>
            </tr>
        <?php endforeach;
        if ($rows && $curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
        if(!$rows): ?><tr><td colspan="<?= 9 + compColsCount() ?>" class="text-center">لا توجد رواتب محسوبة لهذا الشهر</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><?php $drawTotal('المجموع العام ('.count($rows).' موظف)', $G, true); ?></tfoot><?php endif; ?>
    </table>
    <div class="sign-row"><?= signatureBox('أمين الصندوق / المحاسب', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?><?= signatureBox('توقيع المدير وخاتم المدرسة','','') ?></div>
</div>

<?php elseif ($form === 'full_register'):
    // جميع الأساتذة — كشف شامل بالكلفة (مطابق p5): لكل أستاذ الراتب + مساهمات المؤسسة + الكلفة
    $stmt = $db->prepare("SELECT e.school_id, e.employee_type, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
                                 e.hours_per_week, ms.*
                          FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                          WHERE ms.month=? AND ms.year=? AND e.is_deleted=0 AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)" . $ofMonthFilter . " AND" . schoolScopeWhere('e.school_id') . "
                          ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)");
    $stmt->execute(array_merge([$month, $year], $ofMonthParams));
    $rows = $stmt->fetchAll();
    $multiS = (count(activeSchoolIds()) !== 1);
    $T = ['base'=>0,'ech'=>0,'bpe'=>0,'extra'=>0,'aide'=>0,'cnss8'=>0,'eoc6'=>0,'eos85'=>0,'fam6'=>0,'fam'=>0,'trans'=>0,'tax'=>0,'cost'=>0];
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="full_register">
        <div class="card-body form-row cols-3">
            <div class="form-group mb-0"><label class="form-label">Mois / الشهر</label><select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m,'ar')?></option><?php endfor; ?></select></div>
            <div class="form-group mb-0"><label class="form-label">Année / السنة</label><input type="number" name="year" class="form-control" value="<?= $year ?>"></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <?php if (!$multiS) echo schoolLetterhead($school); ?>
    <div class="doc-title">جميع الأساتذة — كشف شامل بالرواتب وكلفة المؤسسة</div>
    <div style="text-align:center;font-size:12pt;margin-bottom:8px"><?= monthName($month,'ar').' '.$year ?></div>
    <table class="doc-table">
        <thead><tr>
            <th>#</th><?php if ($multiS): ?><th>المدرسة</th><?php endif; ?>
            <th>اسم الأستاذ</th><th>عدد الساعات</th><th>أساس الراتب</th><th>درجة وتدرّج</th><th>الراتب بعد التدرّج</th>
            <?= extraAideHeads() ?>
            <th style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th>
            <th>مساهمة الضمان ٨٪</th><th>صندوق التعويضات ٦٪</th><th>نهاية الخدمة ٨.٥٪</th><th>تعويضات عائلية ٦٪</th>
            <th>التعويضات العائلية</th><?= transportHead() ?><th>ضريبة الدخل</th><th>الكلفة على المؤسسة</th>
        </tr></thead>
        <tbody>
        <?php
        $zeroT = array_fill_keys(array_keys($T), 0); $csLbl = $multiS?4:3;
        foreach (['extra_usd','aide_usd','trans_usd','composed','composed_usd'] as $uk) { $zeroT[$uk]=0.0; if(!isset($T[$uk])) $T[$uk]=0.0; }
        $drawTotal = function($label,$a,$isGrand) use ($csLbl){
            $bg=$isGrand?'':'background:#e0e7ff;'; $cls=$isGrand?'total-row':'subtotal-row'; ?>
            <tr class="<?= $cls ?>" style="<?= $bg ?>font-weight:700"><td colspan="<?= $csLbl ?>" style="text-align:right"><?= e($label) ?></td>
                <td class="num"><?= formatLBP($a['base'],false) ?></td><td class="num"><?= formatLBP($a['ech'],false) ?></td><td class="num"><?= formatLBP($a['bpe'],false) ?></td>
                <?= extraAideTotalCells($a['extra'],$a['extra_usd'],$a['aide'],$a['aide_usd']) ?>
                <td class="num" style="background:#eef2ff"><strong><?= dualFromUsd($a['composed'],$a['composed_usd'],false) ?></strong></td>
                <td class="num"><?= formatLBP($a['cnss8'],false) ?></td><td class="num"><?= formatLBP($a['eoc6'],false) ?></td>
                <td class="num"><?= formatLBP($a['eos85'],false) ?></td><td class="num"><?= formatLBP($a['fam6'],false) ?></td>
                <td class="num"><?= formatLBP($a['fam'],false) ?></td><?= transportTotalCell($a['trans'],$a['trans_usd']) ?><td class="num"><?= formatLBP($a['tax'],false) ?></td>
                <td class="num"><strong><?= formatLBP($a['cost'],false) ?></strong></td>
            </tr>
        <?php };
        $nn=0; $curCat=null; $sub=$zeroT;
        foreach ($rows as $r):
            $cat=$r['employee_type'];
            if ($cat !== $curCat):
                if ($curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
                $sub=$zeroT; $curCat=$cat;
                ?><tr class="cat-row"><td colspan="<?= ($multiS?15:14) + compColsCount() ?>" style="text-align:right;font-weight:700;background:#dbeafe"><?= e(empCategoryTitle($cat)) ?></td></tr><?php
            endif;
            $cost = (int)$r['base_plus_echelon_lbp'] + (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp'] + (int)$r['aide_complementaire_lbp']
                  + (int)$r['family_allowance_lbp'] + (int)$r['transport_lbp']
                  + (int)$r['school_cnss_8_lbp'] + (int)$r['school_eoc_6_lbp'] + (int)$r['school_end_of_service_8_5_lbp'] + (int)$r['school_family_comp_6_lbp']
                  - hiddenCompsLbp($r, true); // الكلفة المعروضة = مجموع الأعمدة الظاهرة فقط (زرّ «الراتب يشمل»)
            $rRate=rowRate($r);
            $add=['base'=>(int)$r['base_salary_lbp'],'ech'=>(int)$r['echelon_value_lbp'],'bpe'=>(int)$r['base_plus_echelon_lbp'],'extra'=>extraWageLbp($r),'aide'=>aideCompLbp($r),'cnss8'=>(int)$r['school_cnss_8_lbp'],'eoc6'=>(int)$r['school_eoc_6_lbp'],'eos85'=>(int)$r['school_end_of_service_8_5_lbp'],'fam6'=>(int)$r['school_family_comp_6_lbp'],'fam'=>(int)$r['family_allowance_lbp'],'trans'=>(int)$r['transport_lbp'],'tax'=>(int)$r['income_tax_lbp'],'cost'=>$cost,
                  'extra_usd'=>lbpToUsd(extraWageLbp($r),$rRate),'aide_usd'=>lbpToUsd(aideCompLbp($r),$rRate),'trans_usd'=>lbpToUsd((int)$r['transport_lbp'],$rRate),
                  'composed'=>composedSalaryLbp($r),'composed_usd'=>lbpToUsd(composedSalaryLbp($r),$rRate)];
            foreach ($add as $k=>$v){ $T[$k]+=$v; $sub[$k]+=$v; } ?>
            <tr>
                <td><?= ++$nn ?></td>
                <?php if ($multiS): ?><td><small><?= e(schoolNameById($r['school_id'],'ar')) ?></small></td><?php endif; ?>
                <td style="text-align:right"><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td><?= rtrim(rtrim((string)$r['hours_per_week'],'0'),'.') ?></td>
                <td class="num"><?= formatLBP($r['base_salary_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['echelon_value_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['base_plus_echelon_lbp'],false) ?></td>
                <?= extraAideCells($r) ?>
                <td class="num" style="background:#eef2ff"><strong><?= money(composedSalaryLbp($r), $rRate, ['withCur'=>false]) ?></strong></td>
                <td class="num"><?= formatLBP($r['school_cnss_8_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['school_eoc_6_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['school_end_of_service_8_5_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['school_family_comp_6_lbp'],false) ?></td>
                <td class="num"><?= formatLBP($r['family_allowance_lbp'],false) ?></td>
                <?= transportCell($r) ?>
                <td class="num"><?= formatLBP($r['income_tax_lbp'],false) ?></td>
                <td class="num"><strong><?= formatLBP($cost,false) ?></strong></td>
            </tr>
        <?php endforeach;
        if ($rows && $curCat !== null) $drawTotal('مجموع '.empCategoryTitle($curCat), $sub, false);
        if(!$rows): ?><tr><td colspan="<?= ($multiS?15:14) + compColsCount() ?>" class="text-center">لا توجد رواتب محسوبة لهذا الشهر</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><?php $drawTotal('المجموع العام ('.count($rows).')', $T, true); ?></tfoot><?php endif; ?>
    </table>
    <div class="sign-row"><?= signatureBox('المحاسب', '', '') ?><?= signatureBox('المدير', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'employer_cost'):
    // كلفة المؤسسة الإجمالية: الراتب + كل مساهمات رب العمل (لسنة دراسية)
    $q = $db->prepare("SELECT
            SUM(base_plus_echelon_lbp+extra_lbp+prime_fixe_lbp+aide_complementaire_lbp) gross,
            SUM(base_salary_lbp) baseS, SUM(base_plus_echelon_lbp) bpe,
            SUM(extra_lbp+prime_fixe_lbp) extraWage, SUM(aide_complementaire_lbp) aideC,
            SUM(family_allowance_lbp) family, SUM(transport_lbp) transport,
            SUM(school_cnss_8_lbp) cnss8, SUM(school_eoc_6_lbp) eoc6,
            SUM(school_family_comp_6_lbp) fam6, SUM(school_end_of_service_8_5_lbp) eos85,
            SUM(income_tax_lbp) tax, SUM(cnss_amount_lbp) cnssEmp, SUM(caisse_amount_lbp) eocEmp,
            SUM(net_salary_lbp) net, COUNT(DISTINCT ms.employee_id) n
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0" . $ofYearFilter . " AND ms.school_year=? AND " . schoolScopeWhere('ms.school_id'));
    $q->execute(array_merge($ofYearParams, [$schoolYear])); $g = $q->fetch();
    $gross=(int)($g['gross']??0); $fam=(int)($g['family']??0); $trans=(int)($g['transport']??0);
    $cnss8=(int)($g['cnss8']??0); $eoc6=(int)($g['eoc6']??0); $fam6=(int)($g['fam6']??0); $eos85=(int)($g['eos85']??0);
    $employerCharges = $cnss8+$eoc6+$fam6+$eos85;
    $totalCost = $gross+$fam+$trans+$employerCharges;
    $lines = [
        ['الرواتب الإجمالية (أساسي + درجة + إضافي + مكافآت)', $gross, false],
        ['— منها: أساس الراتب', (int)($g['baseS']??0), true],
        ['— منها: الراتب بعد التدرّج', (int)($g['bpe']??0), true],
        ['— منها: الأجر الإضافي', (int)($g['extraWage']??0), true],
        ['— منها: مكافأة ومساعدة', (int)($g['aideC']??0), true],
        ['التعويضات العائلية', $fam, false],
        ['تعويضات النقل', $trans, false],
        ['— مساهمة المؤسسة بالضمان (٨٪ مرض)', $cnss8, true],
        ['— مساهمة المؤسسة بنهاية الخدمة (٨.٥٪)', $eos85, true],
        ['— مساهمة المؤسسة بالتعويضات العائلية (٦٪)', $fam6, true],
        ['— مساهمة المؤسسة بصندوق التعويضات (٦٪)', $eoc6, true],
        ['مجموع مساهمات المؤسسة', $employerCharges, false],
        ['الكلفة الإجمالية على المؤسسة', $totalCost, false],
    ];
?>
<div class="official-doc rtl" id="ppExportArea">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">كلفة المؤسسة الإجمالية — للعام الدراسي <?= e($schoolYear) ?></div>
    <div class="kv">عدد الموظفين المشمولين: <strong><?= (int)($g['n']??0) ?></strong></div>
    <table class="doc-table" style="max-width:620px;margin:14px auto">
        <thead><tr><th>البيان</th><th>المبلغ (ل.ل)</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $ln): $isTot = ($ln[0]==='مجموع مساهمات المؤسسة' || $ln[0]==='الكلفة الإجمالية على المؤسسة'); ?>
            <tr class="<?= $isTot?'total-row':'' ?>"><td style="text-align:right;<?= $ln[2]?'padding-right:24px;color:#475569':'' ?>"><?= e($ln[0]) ?></td><td class="num"><?= formatLBP($ln[1],false) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="doc-note">الكلفة الإجمالية = الرواتب + التعويضات العائلية + النقل + كل مساهمات المؤسسة (الضمان + نهاية الخدمة + العائلية + صندوق التعويضات).</div>
    <div class="sign-row"><?= signatureBox('المحاسب', '', '') ?><?= signatureBox('المدير', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'staff_stats'):
    // إحصاءات الموظفين: توزيع حسب النوع/الشهادة/الحالة + متوسط الدرجة
    [$yf,$yp] = yearEmploymentFilter(activeSchoolYear());
    $byType = $db->prepare("SELECT employee_type t, COUNT(*) c FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . $yf . " GROUP BY employee_type");
    $byType->execute($yp); $types = $byType->fetchAll();
    $byStatus = $db->prepare("SELECT status s, COUNT(*) c FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . $yf . " GROUP BY status");
    $byStatus->execute($yp); $statuses = $byStatus->fetchAll();
    $byDip = $db->prepare("SELECT COALESCE(NULLIF(diploma,''),'(غير محدد)') d, COUNT(*) c FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . $yf . " GROUP BY diploma ORDER BY c DESC");
    $byDip->execute($yp); $dips = $byDip->fetchAll();
    $totQ = $db->prepare("SELECT COUNT(*) n FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . $yf);
    $totQ->execute($yp); $totN = (int)$totQ->fetchColumn();
?>
<div class="official-doc rtl" id="ppExportArea">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">إحصاءات الموظفين — <?= e(activeSchoolYear()==='all'?'كل السنين':activeSchoolYear()) ?></div>
    <div class="kv" style="font-size:12pt;margin-bottom:14px">العدد الإجمالي: <strong><?= $totN ?></strong></div>
    <div class="form-row cols-3">
        <div>
            <div class="doc-section">حسب الفئة</div>
            <table class="doc-table"><thead><tr><th>الفئة</th><th>العدد</th></tr></thead><tbody>
            <?php foreach ($types as $r): ?><tr><td><?= e(employeeTypeLabel($r['t'],'ar')) ?></td><td><?= (int)$r['c'] ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <div>
            <div class="doc-section">حسب الحالة</div>
            <table class="doc-table"><thead><tr><th>الحالة</th><th>العدد</th></tr></thead><tbody>
            <?php foreach ($statuses as $r): $sl=employeeStatusLabel($r['s'],'ar'); ?><tr><td><?= e($sl['label']) ?></td><td><?= (int)$r['c'] ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <div>
            <div class="doc-section">حسب الشهادة</div>
            <table class="doc-table"><thead><tr><th>الشهادة</th><th>العدد</th></tr></thead><tbody>
            <?php foreach ($dips as $r): ?><tr><td><?= e(diplomaLabel($r['d'],'ar')) ?></td><td><?= (int)$r['c'] ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
    <div class="sign-row"><?= signatureBox('المدير', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'general_info'):
    // معلومات عامة عن الموظفين (مطابق Ecole.exe — p13)
    [$yf,$yp] = yearEmploymentFilter(activeSchoolYear());
    $q = $db->prepare("SELECT * FROM employees WHERE is_deleted=0 AND " . schoolScopeWhere('school_id') . $yf . "
                       ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $q->execute($yp); $rows = $q->fetchAll();
    $today = new DateTime();
?>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <?= schoolLetterhead($school) ?>
    <div class="doc-title">معلومات عامة عن الموظفين — <?= e(activeSchoolYear()==='all'?'كل السنين':activeSchoolYear()) ?></div>
    <table class="doc-table">
        <thead><tr>
            <th>#</th><th>الإسم والشهرة</th><th>تاريخ الولادة</th><th>العمر</th>
            <th>تاريخ الدخول</th><th>تاريخ الترك</th><th>رقم الصندوق</th><th>الرقم المالي</th><th>الراتب (ل.ل)<?php if (compColsCount()): ?><br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small><?php endif; ?></th>
        </tr></thead>
        <tbody>
        <?php $curCat=null; $giTot=0; foreach ($rows as $i=>$r): echo categoryHeaderRow($curCat, $r['employee_type'], 9);
            $age = '';
            if (!empty($r['birth_date']) && strtotime($r['birth_date'])) {
                $age = $today->diff(new DateTime($r['birth_date']))->y;
            }
            $left = $r['left_date_cnss'] ?: ($r['left_date_finance'] ?: $r['left_date_eoc']);
            $sal = ofLatestSalary($db, $r['id']);
            // fallback عند غياب صف راتب: الأستاذ من السلسلة، والموظف الإداري لا سلسلة له (راتبه مباشر) → 0
            $rsalFallback = ($r['employee_type'] === 'enseignant_titulaire') ? (int)scaleSalaryLBP($r['current_grade']) : 0;
            // الراتب المعروض يتبع خيارات «الراتب يشمل» (إضافي/مكافأة/نقل) متل باقي التقارير
            $rsal = $sal ? ((int)composedSalaryLbp($sal) ?: (int)$sal['net_salary_lbp']) : $rsalFallback;
            $giTot += $rsal;
        ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td style="text-align:right"><?= e(trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: ($r['first_name_fr'].' '.$r['last_name_fr'])) ?></td>
                <td><?= $r['birth_date']?formatDate($r['birth_date']):'' ?></td>
                <td><?= $age ?></td>
                <td><?= formatDate($r['hire_date']) ?></td>
                <td><?= $left?formatDate($left):'—' ?></td>
                <td><?= e($r['nssf_number']) ?></td>
                <td><?= e($r['finance_ministry_number']) ?></td>
                <td class="num"><?= formatLBP($rsal,false) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="9" class="text-center">لا يوجد موظفون</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr class="total-row">
            <td colspan="8" style="text-align:right">المجموع — العدد: <?= count($rows) ?></td>
            <td class="num"><strong><?= formatLBP($giTot,false) ?></strong></td>
        </tr></tfoot><?php endif; ?>
    </table>
    <div style="margin-top:10px;font-weight:700">العدد الإجمالي: <?= count($rows) ?></div>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'cnss_nominative_monthly'):
    // كشف اشتراكات الضمان الشهري — اسمي مفصّل (صف لكل مضمون) — مطابق للنموذج المعتمد:
    //   المرض والأمومة = أجير ٣٪ + مدرسة ٨٪ (يخضع له الجميع)؛ نهاية الخدمة ٨.٥٪ والتعويضات
    //   العائلية ٦٪ للموظفين فقط (الأستاذ ياخذ نهاية خدمته من صندوق التعويضات لا من الضمان).
    //   الأرقام مأخوذة كما خزّنها محرّك البرنامج لكل شهر (مع سقف الأجر الخاضع لكل فرع).
    // فلتر «موظفي الفترة» الموحّد محسوب بأعلى الملف حسب شهر/سنة الكشف ($ofMonthFilter/$ofMonthParams).
    $sql = "SELECT e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
                   e.nssf_number, e.birth_date, e.employee_type, e.hire_date,
                   ms.base_salary_lbp, ms.base_plus_echelon_lbp, ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp, ms.taxable_base_lbp,
                   ms.cnss_amount_lbp, ms.school_cnss_8_lbp,
                   ms.school_end_of_service_8_5_lbp, ms.school_family_comp_6_lbp, ms.family_allowance_lbp
            FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND e.cnss_subject = 1" . $ofMonthFilter . "
              AND ms.year = ? AND ms.month = ? AND " . schoolScopeWhere('ms.school_id') . "
              AND (ms.taxable_base_lbp > 0 OR ms.cnss_amount_lbp > 0 OR ms.base_plus_echelon_lbp > 0)
            ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
                     COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr)";
    $st = $db->prepare($sql);
    $st->execute(array_merge($ofMonthParams, [$year, $month]));
    $rows = $st->fetchAll();
    $fmt = fn($v) => (int)$v ? formatLBP((int)$v, false) : '0';
    // المجاميع
    $T = ['baseSal'=>0,'teach'=>0,'cola'=>0,'bonus'=>0,'work'=>0,'base'=>0,'nT'=>0,'nW'=>0,
          'm3'=>0,'m8'=>0,'mtot'=>0,'eos'=>0,'fam6'=>0,'due'=>0,'fpaid'=>0,'rest'=>0];
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="cnss_nominative_monthly">
        <div class="card-body form-row cols-3">
            <div class="form-group mb-0"><label class="form-label">Mois / الشهر</label>
                <select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m,'ar')?></option><?php endfor; ?></select></div>
            <div class="form-group mb-0"><label class="form-label">Année / السنة</label><input type="number" name="year" class="form-control" value="<?= (int)$year ?>"></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:12pt;margin-bottom:6px">
        <div><div style="font-weight:700">المدرسة: <?= e($school['name_ar'] ?? '') ?></div>
             <div>رقمها في الضمان: <?= e($school['nssf_employer_number'] ?? '') ?></div></div>
        <div style="text-align:left">الصندوق الوطني للضمان الاجتماعي</div>
    </div>
    <div class="doc-title" style="margin:4px 0">اشتراكات الضمان المتوجبة عن شهر <?= e(monthName($month,'ar')) ?> <?= (int)$year ?></div>
    <table class="doc-table">
        <thead>
            <tr>
                <th rowspan="2">#</th>
                <th rowspan="2">رقمها في الضمان</th>
                <th rowspan="2">الاسم والشهرة</th>
                <th rowspan="2">ملاك / متعاقد / مستخدم</th>
                <th rowspan="2">أساس الراتب</th>
                <th rowspan="2">رواتب الأساتذة</th>
                <th rowspan="2">الأجر الإضافي</th>
                <th rowspan="2">مكافأة ومساعدة</th>
                <th rowspan="2">رواتب العمال</th>
                <th rowspan="2">مجموع الرواتب ضمن الحد الأقصى</th>
                <th rowspan="2">عدد الأساتذة</th>
                <th rowspan="2">عدد العمال</th>
                <th rowspan="2">تاريخ الدخول في الضمان</th>
                <th colspan="3">المرض والأمومة</th>
                <th rowspan="2">نهاية الخدمة 8.5%</th>
                <th rowspan="2">تعويضات عائلية 6%</th>
                <th rowspan="2">مجموع الاشتراكات المستحقة</th>
                <th rowspan="2">تعويضات عائلية مدفوعة</th>
                <th rowspan="2">الباقي المتوجب للصندوق</th>
            </tr>
            <tr><th>الأجير 3%</th><th>مدرسة 8%</th><th>المجموع</th></tr>
        </thead>
        <tbody>
        <?php $curCat=null; foreach ($rows as $i=>$r):
            $type = $r['employee_type'];
            echo categoryHeaderRow($curCat, $type, 21);
            $isTeacher = in_array($type, ['enseignant_titulaire','enseignant_contractuel']);
            $status = ['enseignant_titulaire'=>'ملاك','enseignant_contractuel'=>'متعاقد','employe'=>'مستخدم'][$type] ?? '';
            $base = (int)$r['base_plus_echelon_lbp'];
            $teach = $isTeacher ? $base : 0;
            $work  = $isTeacher ? 0 : $base;
            // عمودان منفصلان بطلب المستخدم: «الأجر الإضافي» = prime_fixe (+extra القديم)، «مكافأة ومساعدة» = aide_complementaire.
            $cola  = (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp']; // الأجر الإضافي
            $bonus = (int)$r['aide_complementaire_lbp'];               // مكافأة ومساعدة
            $m3    = (int)$r['cnss_amount_lbp'];
            $m8    = (int)$r['school_cnss_8_lbp'];
            // مجموع الرواتب ضمن الحد الأقصى = وعاء الضمان الفعلي الذي احتُسب عليه الاشتراك
            // (يُشتقّ من الاشتراك المخزّن ٣٪ فيبقى متّسقاً مع باقي الأعمدة حتى عند تطبيق السقف).
            $capped= $m3 ? (int)round($m3 / rateFrac('cnss_employee_rate', $month, $year, 3)) : (int)$r['taxable_base_lbp'];
            $mtot  = $m3 + $m8;
            $eos   = (int)$r['school_end_of_service_8_5_lbp'];
            $fam6  = (int)$r['school_family_comp_6_lbp'];
            $due   = $mtot + $eos + $fam6;
            $fpaid = (int)$r['family_allowance_lbp'];
            $rest  = $due - $fpaid;
            $T['baseSal']+=(int)$r['base_salary_lbp'];
            $T['teach']+=$teach; $T['cola']+=$cola; $T['bonus']+=$bonus; $T['work']+=$work;
            $T['base']+=$capped; $T['nT']+=$isTeacher?1:0; $T['nW']+=$isTeacher?0:1;
            $T['m3']+=$m3; $T['m8']+=$m8; $T['mtot']+=$mtot; $T['eos']+=$eos; $T['fam6']+=$fam6;
            $T['due']+=$due; $T['fpaid']+=$fpaid; $T['rest']+=$rest;
            $name = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
        ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= e(cnssWithBirthYear($r['nssf_number'], $r['birth_date'])) ?></td>
                <td style="text-align:right;white-space:nowrap"><?= e($name) ?></td>
                <td><?= e($status) ?></td>
                <td class="num"><?= $fmt((int)$r['base_salary_lbp']) ?></td>
                <td class="num"><?= $fmt($teach) ?></td>
                <td class="num"><?= $fmt($cola) ?></td>
                <td class="num"><?= $fmt($bonus) ?></td>
                <td class="num"><?= $fmt($work) ?></td>
                <td class="num"><?= $fmt($capped) ?></td>
                <td><?= $isTeacher?1:0 ?></td>
                <td><?= $isTeacher?0:1 ?></td>
                <td><?= $r['hire_date']?formatDate($r['hire_date']):'' ?></td>
                <td class="num"><?= $fmt($m3) ?></td>
                <td class="num"><?= $fmt($m8) ?></td>
                <td class="num"><?= $fmt($mtot) ?></td>
                <td class="num"><?= $fmt($eos) ?></td>
                <td class="num"><?= $fmt($fam6) ?></td>
                <td class="num"><?= $fmt($due) ?></td>
                <td class="num"><?= $fmt($fpaid) ?></td>
                <td class="num"><?= $fmt($rest) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="21" class="text-center">لا يوجد مضمونون لهذا الشهر</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr class="total-row">
                <td colspan="4">المجاميع</td>
                <td class="num"><?= $fmt($T['baseSal']) ?></td>
                <td class="num"><?= $fmt($T['teach']) ?></td>
                <td class="num"><?= $fmt($T['cola']) ?></td>
                <td class="num"><?= $fmt($T['bonus']) ?></td>
                <td class="num"><?= $fmt($T['work']) ?></td>
                <td class="num"><?= $fmt($T['base']) ?></td>
                <td><?= $T['nT'] ?></td>
                <td><?= $T['nW'] ?></td>
                <td></td>
                <td class="num"><?= $fmt($T['m3']) ?></td>
                <td class="num"><?= $fmt($T['m8']) ?></td>
                <td class="num"><?= $fmt($T['mtot']) ?></td>
                <td class="num"><?= $fmt($T['eos']) ?></td>
                <td class="num"><?= $fmt($T['fam6']) ?></td>
                <td class="num"><?= $fmt($T['due']) ?></td>
                <td class="num"><?= $fmt($T['fpaid']) ?></td>
                <td class="num"><?= $fmt($T['rest']) ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    <div style="margin-top:8px;font-weight:700">عدد المضمونين: <?= count($rows) ?> &nbsp;—&nbsp; المتوجب للصندوق: <?= $fmt($T['rest']) ?> ل.ل</div>
    <div class="sign-row"><?= signatureBox('اسم المسؤول وصفته وتوقيعه وخاتم المؤسسة', $school['ville'] ?? 'بيروت', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php elseif ($form === 'salary_detail'):
    // معلومات تفصيلية عن الراتب — جميع الأساتذة/الموظفون (مطابق p3/p4): مجمّع حسب الفئة،
    // صفّ «المجموع» لكل فئة + مجموع عام. الأعمدة من جدول monthly_salaries كما يخزّنها المحرّك.
    $sql = "SELECT e.first_name_ar,e.last_name_ar,e.first_name_fr,e.last_name_fr,e.employee_type, ms.*
            FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
            WHERE ms.month=? AND ms.year=? AND e.is_deleted=0
              AND (ms.base_plus_echelon_lbp>0 OR ms.net_salary_lbp>0 OR ms.total_due_lbp>0)" . $ofMonthFilter . "
              AND " . schoolScopeWhere('e.school_id') . "
            ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
                     COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr)";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$month, $year], $ofMonthParams));
    $rows = $st->fetchAll();
    $catLabel = ['enseignant_titulaire'=>'الملاك','enseignant_contractuel'=>'المتعاقدين','employe'=>'الموظفين'];
    $sdRate = getExchangeRate($month, $year); // سعر صرف الشهر (كل الصفوف بنفس الشهر)
    $fmt = fn($v) => (int)$v ? money((int)$v, $sdRate, ['withCur'=>false]) : '0';
    // مفاتيح الأعمدة الرقمية القابلة للجمع
    $keys = ['base','ech','bpe','cola','bonus','composed','half','caisse','gross','txb','tax','cnss','ret','net','fam','trans','due'];
    $zero = array_fill_keys($keys, 0);
    // استخراج قيم صفّ واحد
    $rowVals = function($r) {
        $gross = (int)$r['base_plus_echelon_lbp'] + (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp'] + (int)$r['aide_complementaire_lbp'];
        return [
            'base'=>(int)$r['base_salary_lbp'], 'ech'=>(int)$r['echelon_value_lbp'], 'bpe'=>(int)$r['base_plus_echelon_lbp'],
            // عمودان منفصلان: «الأجر الإضافي» = prime_fixe (+extra)، «مكافأة ومساعدة» = aide_complementaire (موحّد مع كشف الضمان الاسمي)
            'cola'=>(int)$r['extra_lbp']+(int)$r['prime_fixe_lbp'], 'bonus'=>(int)$r['aide_complementaire_lbp'],
            'composed'=>composedSalaryLbp($r),
            'half'=>(int)$r['eoc_grade_lbp'], 'caisse'=>(int)$r['caisse_amount_lbp'], 'gross'=>$gross,
            'txb'=>(int)$r['taxable_base_lbp'], 'tax'=>(int)$r['income_tax_lbp'], 'cnss'=>(int)$r['cnss_amount_lbp'],
            'ret'=>(int)$r['total_retenues_lbp'], 'net'=>(int)$r['net_salary_lbp'], 'fam'=>(int)$r['family_allowance_lbp'],
            'trans'=>(int)$r['transport_lbp'], 'due'=>(int)$r['total_due_lbp'],
        ];
    };
?>
    <form method="get" class="card no-print">
        <input type="hidden" name="form" value="salary_detail">
        <div class="card-body form-row cols-3">
            <div class="form-group mb-0"><label class="form-label">Mois / الشهر</label>
                <select name="month" class="form-select"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m===$month?'selected':''?>><?=monthName($m,'ar')?></option><?php endfor; ?></select></div>
            <div class="form-group mb-0"><label class="form-label">Année / السنة</label><input type="number" name="year" class="form-control" value="<?= (int)$year ?>"></div>
            <div class="form-group mb-0"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Afficher / عرض</button></div>
        </div>
    </form>
<div class="official-doc rtl land-report" id="ppExportArea" style="max-width:100%">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:12pt;margin-bottom:6px">
        <div style="border:1px solid #333;padding:6px 10px;font-size:12pt;line-height:1.7">
            <div style="font-weight:700">معلومات تفصيلية عن الراتب</div>
            <div>للعام الدراسي: <?= e($ofMonthSchoolYear) ?></div>
            <div>شهر: <?= e(monthName($month,'ar')) ?></div>
        </div>
        <div style="text-align:left;font-weight:700"><?= e($school['name_ar'] ?? '') ?></div>
    </div>
    <div class="doc-title" style="margin:4px 0">جميع الأساتذة / الموظفون</div>
    <table class="doc-table">
        <thead>
            <tr>
                <th rowspan="2">رقم</th>
                <th rowspan="2">الاسم و الشهرة</th>
                <th rowspan="2">أساس الراتب</th>
                <th rowspan="2">درجة عادية واستثنائية</th>
                <th rowspan="2">الراتب بعد الدرج</th>
                <th rowspan="2">الأجر الإضافي</th>
                <th rowspan="2">مكافأة ومساعدة</th>
                <th rowspan="2" style="background:#4338ca">الراتب المركّب<br><small style="font-weight:400"><?= e(salaryCompLabel()) ?></small></th>
                <th colspan="6">المحسومات القانونية</th>
                <th rowspan="2">الصافي</th>
                <th rowspan="2">تعويض عائلي</th>
                <th rowspan="2">تعويض نقل</th>
                <th rowspan="2">مجموع المدفوعات</th>
            </tr>
            <tr>
                <th>نصف راتب،درجة</th>
                <th>صندوق التعويضات 6%</th>
                <th>التنزيل العائلي</th>
                <th>الراتب الخاضع لضريبة الدخل</th>
                <th>ضريبة الدخل</th>
                <th>الضمان الاجتماعي<br>المرض الأمومة 3%</th>
            </tr>
            <tr style="display:none"></tr>
        </thead>
        <tbody>
        <?php
        // صفّ بيانات
        $drawRow = function($n, $name, $v) use ($fmt) { ?>
            <tr>
                <td><?= $n ?></td>
                <td style="text-align:right;white-space:nowrap"><?= e($name) ?></td>
                <td class="num"><?= $fmt($v['base']) ?></td>
                <td class="num"><?= $fmt($v['ech']) ?></td>
                <td class="num"><?= $fmt($v['bpe']) ?></td>
                <td class="num"><?= $fmt($v['cola']) ?></td>
                <td class="num"><?= $fmt($v['bonus']) ?></td>
                <td class="num" style="background:#eef2ff"><strong><?= $fmt($v['composed']) ?></strong></td>
                <td class="num"><?= $fmt($v['half']) ?></td>
                <td class="num"><?= $fmt($v['caisse']) ?></td>
                <td class="num"><?= $fmt($v['gross']) ?></td>
                <td class="num"><?= $fmt($v['txb']) ?></td>
                <td class="num"><?= $fmt($v['tax']) ?></td>
                <td class="num"><?= $fmt($v['cnss']) ?></td>
                <td class="num"><?= $fmt($v['ret']) ?></td>
                <td class="num"><?= $fmt($v['net']) ?></td>
                <td class="num"><?= $fmt($v['fam']) ?></td>
                <td class="num"><?= $fmt($v['trans']) ?></td>
                <td class="num"><?= $fmt($v['due']) ?></td>
            </tr>
        <?php };
        // صفّ مجموع
        $drawTotal = function($label, $a) use ($fmt) { ?>
            <tr class="total-row">
                <td></td><td style="text-align:right"><?= e($label) ?></td>
                <td class="num"><?= $fmt($a['base']) ?></td><td class="num"><?= $fmt($a['ech']) ?></td>
                <td class="num"><?= $fmt($a['bpe']) ?></td><td class="num"><?= $fmt($a['cola']) ?></td>
                <td class="num"><?= $fmt($a['bonus']) ?></td><td class="num" style="background:#eef2ff"><strong><?= $fmt($a['composed']) ?></strong></td><td class="num"><?= $fmt($a['half']) ?></td>
                <td class="num"><?= $fmt($a['caisse']) ?></td><td class="num"><?= $fmt($a['gross']) ?></td>
                <td class="num"><?= $fmt($a['txb']) ?></td><td class="num"><?= $fmt($a['tax']) ?></td>
                <td class="num"><?= $fmt($a['cnss']) ?></td><td class="num"><?= $fmt($a['ret']) ?></td>
                <td class="num"><?= $fmt($a['net']) ?></td><td class="num"><?= $fmt($a['fam']) ?></td>
                <td class="num"><?= $fmt($a['trans']) ?></td><td class="num"><?= $fmt($a['due']) ?></td>
            </tr>
        <?php };
        $n = 0; $curCat = null; $sub = $zero; $grand = $zero;
        foreach ($rows as $r):
            $cat = $r['employee_type'];
            if ($cat !== $curCat):
                if ($curCat !== null) $drawTotal('المجموع', $sub);
                $sub = $zero; $curCat = $cat;
                ?><tr><td colspan="19" style="text-align:right;font-weight:700;background:#eef2ff"><?= e($catLabel[$cat] ?? $cat) ?></td></tr><?php
            endif;
            $v = $rowVals($r);
            foreach ($keys as $k) { $sub[$k]+=$v[$k]; $grand[$k]+=$v[$k]; }
            $name = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
            $drawRow(++$n, $name, $v);
        endforeach;
        if ($curCat !== null) $drawTotal('المجموع', $sub);
        if (!$rows): ?><tr><td colspan="19" class="text-center">لا توجد رواتب محسوبة لهذا الشهر</td></tr><?php endif;
        ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot><?php $drawTotal('المجموع العام', $grand); ?></tfoot>
        <?php endif; ?>
    </table>
    <div style="margin-top:8px;font-weight:700">عدد الموظفين: <?= $n ?></div>
    <div class="sign-row"><?= signatureBox('توقيع المدير وخاتم المدرسة', $school['ville'] ?? '', formatDate(date('Y-m-d'))) ?></div>
</div>

<?php
endif;
include __DIR__ . '/../includes/footer.php';
