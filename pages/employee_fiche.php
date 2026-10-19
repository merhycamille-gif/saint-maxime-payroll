<?php
/**
 * 🪪 بطاقة الأستاذ على ورقة A4 واحدة / Fiche de l'enseignant (A4)
 * (2026-10-19 «اذا بدي شوف معلومات الاستاذ على صفحة وحدة A4… المعلومات الشخصية وكل شي معلومات لالو بالمدرسة واقدر اطبعها»)
 * كل معلومات ملفّه (شخصية · اتصال · وظيفية · أرقام رسمية · راتب ومحسومات) مرتّبة بخانتَين على كل سطر، بلا صور المستندات،
 * الخانات الفارغة لا تُطبع — فتسع ورقة واحدة. صفحة مستقلّة: لا تمسّ «الملف الكامل» ولا أي صفحة أخرى.
 * الطباعة/PDF/واتساب/إيميل من شريط التصدير الموحّد (محرّك الطباعة الرسمي يحوّل .letterhead و.info-grid إلى جداول الورق).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/report_helpers.php';
requireLogin();

$currentPage = 'employee_fiche';
$db = getDB();
$employeeId = (int)($_GET['employee_id'] ?? 0);

// 🪪 («حطلي ياها تبين أيقونة ضمن ملف الأستاذ دغري بدون ما افتح ملفو» 2026-10-19) بلا أستاذ: صفحة اختيار الأستاذ مباشرة ⇒ البطاقة
if ($employeeId <= 0) {
    $pageTitle = "Fiche A4 / بطاقة الأستاذ A4";
    $hideExportToolbar = true;
    [$ayf, $ayp] = yearEmploymentFilter(activeSchoolYear());
    $aStmt = $db->prepare("SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, school_id
                             FROM employees WHERE is_deleted = 0" . schoolScopeSql() . $ayf . " ORDER BY school_id, FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr), id");
    $aStmt->execute($ayp);
    $list = $aStmt->fetchAll();
    $showSch = isAllSchools();
    include __DIR__ . '/../includes/header.php';
?>
    <div class="card">
        <div class="card-header"><h3>
          <span dir="ltr"><i class="fas fa-id-card"></i> Fiche A4 de l'enseignant</span>
          <div style="font-size:0.85em;font-weight:600;opacity:0.9">بطاقة الأستاذ على ورقة A4 واحدة — كل معلومات ملفّه للطباعة</div>
        </h3></div>
        <div class="card-body">
            <?php if (!$list): ?>
                <div class="alert alert-info">Aucun employé / لا يوجد موظفون.</div>
            <?php else: ?>
            <form method="GET" class="form-row cols-4" style="align-items:end">
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Employé / الأستاذ</label>
                    <select name="employee_id" class="form-select" required onchange="this.form.submit()">
                        <option value="">— Choisir / اختر —</option>
                        <?php foreach ($list as $em): $pfx = $showSch ? (schoolNameById($em['school_id']) . ' — ') : ''; ?>
                        <option value="<?= (int)$em['id'] ?>"><?= e($pfx . $em['employee_code'] . ' — ' . $em['first_name_fr'] . ' ' . $em['last_name_fr']) ?> / <?= e($em['first_name_ar'] . ' ' . $em['last_name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <button class="btn btn-success btn-lg"><i class="fas fa-id-card"></i> Afficher la fiche A4 / اعرض بطاقة الأستاذ A4</button>
                </div>
            </form>
            <p class="text-muted mt-3"><i class="fas fa-info-circle"></i> اختر الأستاذ وبتطلع البطاقة فوراً (بتقدر كمان تفتّش عنه من خانة البحث فوق). بعدها اطبعها أو احفظها PDF أو ابعتها واتساب/إيميل من الشريط.</p>
            <?php endif; ?>
        </div>
    </div>
<?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// وضع «كل المدارس»: بدّل المدرسة الفعّالة لمدرسة الأستاذ تلقائياً (كما بالملف الكامل)
if ($employeeId > 0 && isAllSchools()) {
    $sidQ = $db->prepare("SELECT school_id FROM employees WHERE id = ? AND is_deleted = 0");
    $sidQ->execute([$employeeId]);
    $sid = (int)$sidQ->fetchColumn();
    $mayView = isSuperAdmin() || (function_exists('viewerAllowedSchoolIds') && in_array($sid, viewerAllowedSchoolIds(), true));
    if ($sid > 0 && $mayView) { $_SESSION['active_schools'] = [$sid]; unset($_SESSION['report_schools']); }
}
requireSchoolSelected();
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
$stmt->execute([$employeeId]);
$emp = $stmt->fetch();
if (!$emp) {
    $_SESSION['flash_error'] = 'الموظف غير موجود في هذه المدرسة / Employé introuvable';
    header('Location: ' . BASE_URL . 'pages/attestations.php');
    exit;
}

$isTeacher = (($emp['employee_type'] ?? '') !== 'employe');   // «أستاذ» للمعلّم، «موظف» لقانون العمل
$nameAr = trim(implode(' ', array_filter([$emp['first_name_ar'] ?? '', $emp['father_name_ar'] ?? '', $emp['last_name_ar'] ?? ''])));
$nameFr = trim(implode(' ', array_filter([$emp['first_name_fr'] ?? '', $emp['father_name_fr'] ?? '', $emp['last_name_fr'] ?? ''])));
$titleFr = $isTeacher ? "Fiche de l'enseignant" : "Fiche de l'employé";
$titleAr = $isTeacher ? 'بطاقة الأستاذ' : 'بطاقة الموظف';
$pageTitle = $titleFr . ' / ' . $titleAr;
$exportTitle = 'Fiche_' . preg_replace('/[^A-Za-z0-9]+/', '_', trim(($emp['first_name_fr'] ?? '') . '_' . ($emp['last_name_fr'] ?? ''))) ?: 'Fiche';
$school = currentSchool();
$exportOpts = [
    'phone' => (trim((string)($school['phone'] ?? '')) ?: ($emp['phone1'] ?: '')),
    'email' => (trim((string)($school['email'] ?? '')) ?: ($emp['email'] ?? '')),
];
$backHref = BASE_URL . 'pages/attestations.php?employee_id=' . $employeeId . '&dossier=1';

// ===== الخانات — نفس مصادر «الملف الكامل»، مدموجة باختصار والفارغ لا يُطبع =====
$yn   = fn($v) => ((int)$v === 1) ? 'نعم' : 'لا';
$num  = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
$incLbl = fn($ech, $ext, $pa) => implode('، ', array_filter([(int)$ech ? 'التدرّج' : '', (int)$ext ? 'الأجر الإضافي' : '', (int)$pa ? 'المكافأة/المساعدة' : ''])) ?: 'الأساس فقط';
$addr = implode(' - ', array_values(array_filter(array_map('trim', [
    $emp['ville'] ?? '', $emp['quartier'] ?? '', $emp['rue'] ?? '', $emp['immeuble'] ?? '',
    ($emp['etage'] ?? '') !== '' ? ('طابق ' . $emp['etage']) : '', $emp['district'] ?? '', $emp['gouvernorat'] ?? '',
]))));
$registry = trim(($emp['civil_registry_number'] ?? '') . (($emp['civil_registry_place'] ?? '') ? (' / ' . $emp['civil_registry_place']) : ''));
$mother   = trim(($emp['mother_first_name'] ?? '') . ' ' . ($emp['mother_last_name'] ?? ''));
$birth    = trim(($emp['birth_date'] ? formatDate($emp['birth_date']) : '') . (($emp['birth_place'] ?? '') ? (' — ' . $emp['birth_place']) : ''));
$social   = trim((string)($emp['social_status'] ?? ''));
if ($social !== '' && (int)($emp['spouse_works'] ?? 0) === 1) $social .= ' (الزوج/الزوجة يعمل)';
$phones   = implode(' · ', array_filter([trim((string)($emp['phone1'] ?? '')), trim((string)($emp['phone2'] ?? ''))]));
$modeLbl  = isLaborLawSalary($emp) ? 'قانون العمل (الحد الأدنى للأجور الساري)'
          : (['direct_usd' => 'راتب مباشر بالدولار', 'percent_of_lbp' => 'نسبة من راتب الليرة', 'direct_lbp' => 'راتب مباشر بالليرة'][$emp['salary_input_mode'] ?? ''] ?? ($emp['salary_input_mode'] ?? ''));
$baseLbl  = '';
if (($emp['salary_input_mode'] ?? '') === 'direct_usd' && (float)($emp['base_salary_usd'] ?? 0)) $baseLbl = '$' . number_format((float)$emp['base_salary_usd'], 2);
if (($emp['salary_input_mode'] ?? '') === 'percent_of_lbp' && (float)($emp['base_salary_lbp_percent'] ?? 0)) $baseLbl = $num($emp['base_salary_lbp_percent']) . '%';
if (($emp['salary_input_mode'] ?? '') === 'direct_lbp' && (float)($emp['contract_salary_lbp'] ?? 0)) $baseLbl = formatLBP($emp['contract_salary_lbp']);
$transport = ((float)($emp['transport_daily_amount'] ?? 0) > 0)
    ? ($num($emp['transport_daily_amount']) . ' ' . ($emp['transport_daily_currency'] ?? 'LBP') . ' × ' . (int)($emp['transport_days_per_week'] ?? 0) . ' يوم/أسبوع × ' . $num($emp['transport_weeks'] ?? 0) . ' أسبوع')
    : '';
$m13 = (int)($emp['has_13th_month'] ?? 0)
    ? ('نعم' . ((($emp['m13_include_extra'] ?? 0) || ($emp['m13_include_aide'] ?? 0)) ? ' — يشمل: ' . implode('، ', array_filter([($emp['m13_include_extra'] ?? 0) ? 'الإضافي' : '', ($emp['m13_include_aide'] ?? 0) ? 'المكافأة' : ''])) : ''))
    : 'لا';
$grade = trim((string)gradeDisplay($emp));
if ((float)($emp['starting_grade'] ?? 0) > 0) $grade .= ($grade !== '' ? ' ' : '') . '(الابتدائية ' . $num($emp['starting_grade']) . ')';
$hours = implode(' · ', array_filter([
    (float)($emp['hours_per_week'] ?? 0) > 0 ? ($num($emp['hours_per_week']) . ' ساعة/أسبوع') : '',
    (int)($emp['days_per_week'] ?? 0) > 0 ? ((int)$emp['days_per_week'] . ' يوم/أسبوع') : '',
]));
$famAll = implode(' · ', array_filter([
    (float)($emp['family_allowance_spouse_lbp'] ?? 0) ? ('زوج: ' . formatLBP($emp['family_allowance_spouse_lbp'])) : '',
    (float)($emp['family_allowance_children_lbp'] ?? 0) ? ('أولاد: ' . formatLBP($emp['family_allowance_children_lbp'])) : '',
]));

// [التسمية, القيمة, كامل السطر؟] — القيمة الفارغة تُحذف
$S = [];
// 🌐 («عملتها باللغتين… فرنسي وتحت الفرنسي دغري عربي» 2026-10-19) عنوان القسم: الفرنسي فوق والعربي تحته؛ تسمية الخانة: «Français / عربي» على السطر نفسه
//    التسميات قصيرة عمداً كي تسع خانتان بالسطر وتبقى الورقة واحدة
$incShort = fn($ech, $ext, $pa) => implode('، ', array_filter([(int)$ech ? 'التدرّج' : '', (int)$ext ? 'الإضافي' : '', (int)$pa ? 'المكافأة' : ''])) ?: 'الأساس فقط';
$subj = fn($on, $ech, $ext, $pa) => (int)$on ? ('نعم: ' . $incShort($ech, $ext, $pa)) : 'لا';
$S['Informations personnelles|معلومات شخصية'] = [
    ['Nom complet (AR) / الاسم الكامل', $nameAr, true],
    ['Nom complet (FR) / الاسم بالفرنسي', $nameFr, true],
    ['Mère / اسم الأم', $mother],
    ['Nationalité / الجنسية', $emp['nationality'] ?? ''],
    ['Naissance / الولادة', $birth],
    ['Registre / السجل', $registry],
    ['Situation / الوضع العائلي', $social],
    ["Enfants / عدد الأولاد", (int)($emp['number_of_children'] ?? 0) > 0 ? (string)(int)$emp['number_of_children'] : ''],
];
$S['Contact et adresse|اتصال وسكن'] = [
    ['Tél. / الهاتف', $phones],
    ['E-mail / الإيميل', $emp['email'] ?? ''],
    ['Adresse / العنوان', $addr, true],
];
$S['Informations professionnelles|معلومات وظيفية'] = [
    ['Catégorie / الفئة', employeeTypeLabel($emp['employee_type'])],
    ['Fonction / الوظيفة', (!$isTeacher && ($emp['job_title'] ?? '') !== '') ? jobTitleLabel($emp['job_title']) : ''],
    ['Diplôme / الشهادة', ($emp['diploma'] ?? '') ? diplomaLabel($emp['diploma']) : ''],
    ['Spécialité / الاختصاص', $emp['specialization'] ?? ''],
    ['Matières / المواد', $emp['subjects_taught'] ?? ''],
    ['Cycle / المرحلة', $emp['niveau_scolaire'] ?? ''],
    ['Classes / الصفوف', classLevelNames($emp['classes_taught'] ?? '')],
    ['Échelon / الدرجة', $grade],
    ['Horaire / الدوام', $hours],
    ["Entrée / الدخول", shownHireDate($emp) ? formatDate(shownHireDate($emp)) : ''],
    ['Titularisation / الملاك', shownTitularizationDate($emp) ? formatDate(shownTitularizationDate($emp)) : ''],
    ['Confirmation / تثبيت الملاك', $emp['tenure_confirmation_date'] ? formatDate($emp['tenure_confirmation_date']) : ''],
    ['Statut / الحالة', employeeStatusLabel($emp['status'])['label'] ?? ''],
    ['Après 64 ans / بعد ٦٤', (int)($emp['keep_working_past_64'] ?? 0) === 1 ? 'نعم' : ''],
];
$S['Numéros officiels et cessations|أرقام رسمية وتواريخ الترك'] = [
    ['CNSS / الضمان', cnssWithBirthYear($emp['nssf_number'] ?? '', $emp['birth_date'] ?? '', '')],
    ['Finances / المالية', $emp['finance_ministry_number'] ?? ''],
    ['Caisse / الصندوق', $emp['caisse_number'] ?? ''],
    ['Cessation / الترك من الكل', !empty($emp['left_date_all']) ? formatDate($emp['left_date_all']) : ''],
    ['Cessation CNSS / ترك الضمان', !empty($emp['left_date_cnss']) ? formatDate($emp['left_date_cnss']) : ''],
    ['Cessation Finances / ترك المالية', !empty($emp['left_date_finance']) ? formatDate($emp['left_date_finance']) : ''],
    ['Cessation Caisse / ترك الصندوق', !empty($emp['left_date_eoc']) ? formatDate($emp['left_date_eoc']) : ''],
];
$S['Salaire et retenues|الراتب والمحسومات'] = [
    ['Mode / طريقة الراتب', $modeLbl],
    ['Base / أساس الراتب', $baseLbl],
    ['Période payée / الفترة المدفوعة', paidPeriodLabel($emp, 'ar')],
    ['13e mois / الشهر 13', $m13],
    ['Allocations / التعويض العائلي', $famAll],
    ['Transport / النقل', $transport],
    ["Impôt / الضريبة", $subj($emp['tax_subject'] ?? 0, $emp['tax_includes_echelon'] ?? 0, $emp['tax_includes_extra'] ?? 0, $emp['tax_includes_prime_aide'] ?? 0)],
    ['CNSS / الضمان', $subj($emp['cnss_subject'] ?? 0, $emp['cnss_includes_echelon'] ?? 0, $emp['cnss_includes_extra'] ?? 0, $emp['cnss_includes_prime_aide'] ?? 0)],
    ['Caisse / الصندوق', $subj($emp['eoc_subject'] ?? 0, $emp['eoc_includes_echelon'] ?? 0, $emp['eoc_includes_extra'] ?? 0, $emp['eoc_includes_prime_aide'] ?? 0)],
];

// الصورة الشخصية (إن وُجدت على هذا الجهاز — محرّك الطباعة يقرأها من الملف نفسه)
$photoUrl = '';
if (!empty($emp['photo_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', (string)$emp['photo_path']) && is_file(__DIR__ . '/../' . $emp['photo_path'])) {
    $photoUrl = BASE_URL . e($emp['photo_path']);
}
$notes = trim((string)($emp['notes'] ?? ''));
if (mb_strlen($notes) > 320) $notes = mb_substr($notes, 0, 320) . '…';   // الملاحظات الطويلة تبقى كاملة بالملف — هنا سطران كي تبقى الورقة واحدة

include __DIR__ . '/../includes/header.php';
echo officialFormStyles();
?>
<style>
/* 🪪 بطاقة A4 على الشاشة: ورقة بحجمها الحقيقي — المحتوى ذاته يذهب لمحرّك الطباعة (جداول pdf-info جاهزة: لا تحويل ولا تقسيم) */
.fiche-a4 .fiche-top{width:100%;border-collapse:collapse;margin:2px 0 6px}
.fiche-a4 .fiche-top td{border:0;padding:0;vertical-align:middle}
.fiche-a4 .fiche-photo{width:30mm;text-align:left}
.fiche-a4 .fiche-photo img{width:26mm;height:32mm;object-fit:cover;border:1px solid #cbd5e1;border-radius:4px}
.fiche-a4 .doc-title{margin:4px 0 2px;font-size:18pt}
.fiche-a4 .doc-subtitle{margin:0 0 4px}
.fiche-a4 table.pdf-info{width:100%;border-collapse:collapse;margin:2px 0 6px;font-size:12pt}
.fiche-a4 table.pdf-info td{border:0;border-bottom:1px dotted #94a3b8;vertical-align:top}
.fiche-a4 .k{color:#334155}
.fiche-a4 .fill{font-weight:700;color:#0a3d91;display:inline;padding:0 3px}
.fiche-a4 .fiche-foot{font-size:10pt;color:#64748b;text-align:center;border-top:1px dashed #cbd5e1;margin-top:12px;padding-top:4px}
</style>
<?php
// خانة = تسمية + قيمة على سطر منقّط؛ القيمة الطويلة (> 36 حرفاً) تأخذ السطر كلّه كي لا تتكسّر على أسطر كثيرة
$tdStyle = 'padding:1pt 4pt 0.5pt;line-height:1.2;vertical-align:top;border:0;border-bottom:0.5pt dotted #94a3b8';
$mmEst = function (string $t): float {   // تقدير عرض النصّ بالملم بخط Arial 12pt (نفس تقدير محرّك الطباعة MsaPdf::textMmEst)
    $w = 0.0;
    foreach (preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $o = mb_ord($ch, 'UTF-8');
        if ($o >= 0x064B && $o <= 0x065F) continue;
        if ($o >= 0x0600 && $o <= 0x06FF) $w += 1.9;
        elseif (ctype_digit($ch)) $w += 2.3;
        elseif ($ch === ' ' || $ch === ',' || $ch === '.' || $ch === ':' || $ch === '/') $w += 1.1;
        elseif ($o > 0x2000) $w += 2.6;
        elseif (ctype_upper($ch)) $w += 2.6;
        else $w += 2.0;
    }
    return $w;
};
$cell = fn($r, $span) => '<td' . ($span > 1 ? ' colspan="2" style="width:100%;' : ' style="width:50%;') . $tdStyle . '"><span class="k">' . e($r[0]) . ':</span> <span class="fill">' . e($r[1]) . '</span></td>';
$infoTable = function (array $rows) use ($cell, $tdStyle, $mmEst) {
    $out = '<table class="pdf-info" dir="rtl" width="100%" style="width:100%;border-collapse:collapse;margin:2pt 0 5pt">';
    $pair = [];
    $flush = function () use (&$pair, &$out, $cell, $tdStyle) {
        if (!$pair) return;
        $out .= '<tr>' . $cell($pair[0], 1) . (isset($pair[1]) ? $cell($pair[1], 1) : '<td style="width:50%;' . $tdStyle . '"></td>') . '</tr>';
        $pair = [];
    };
    foreach ($rows as $r) {
        // الخانة التي لا تسع نصف السطر (≈80mm بخط 12: عربي 1.9mm/حرف، لاتيني 2.0، رقم 2.3) تأخذ السطر كلّه بدل أن تلتفّ
        $full = !empty($r[2]) || $mmEst($r[0] . ': ' . $r[1]) > 84;
        if ($full) { $flush(); $out .= '<tr>' . $cell($r, 2) . '</tr>'; continue; }
        $pair[] = $r;
        if (count($pair) === 2) $flush();
    }
    $flush();
    return $out . '</table>';
};
?>
<div class="d-flex justify-between align-center mb-3 no-print" style="flex-wrap:wrap;gap:8px">
    <a href="<?= e($backHref) ?>" class="btn btn-light"><i class="fas fa-arrow-left"></i> Dossier complet / الملف الكامل</a>
    <?php if (viewerCanSeePage('employees.php')): ?>
    <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $employeeId ?>" class="btn btn-light"><i class="fas fa-user-pen"></i> Modifier le dossier / تعديل ملف الأستاذ</a>
    <?php endif; ?>
</div>

<div id="ppExportArea">
<div class="official-doc rtl fiche-a4" dir="rtl">
    <div style="line-height:1.25"><?= schoolLetterhead($school) ?></div>
    <table class="fiche-top"><tr>
        <td>
            <div class="doc-title" style="line-height:1.2;margin:0 0 1pt"><span dir="ltr"><?= e($titleFr) ?></span><div style="font-size:0.85em"><?= e($titleAr) ?></div></div>
            <div class="doc-subtitle" style="margin:0 0 2pt;line-height:1.2"><strong><?= e($nameAr ?: $nameFr) ?></strong><?php if ($nameAr && $nameFr): ?> — <span dir="ltr"><?= e($nameFr) ?></span><?php endif; ?><?php if (!empty($emp['employee_code'])): ?> · <?= e($emp['employee_code']) ?><?php endif; ?></div>
        </td>
        <?php if ($photoUrl): ?><td class="fiche-photo"><img src="<?= $photoUrl ?>" alt=""></td><?php endif; ?>
    </tr></table>

    <?php foreach ($S as $title => $rows): ?>
        <?php $rows = array_values(array_filter($rows, fn($r) => trim((string)$r[1]) !== '')); if (!$rows) continue; ?>
        <?php [$tFr, $tAr] = array_pad(explode('|', $title, 2), 2, ''); ?>
        <div class="doc-section" style="margin:5pt 0 1pt;line-height:1.2"><span dir="ltr"><?= e($tFr) ?></span><div style="font-size:0.9em;font-weight:600"><?= e($tAr) ?></div></div>
        <?= $infoTable($rows) ?>
    <?php endforeach; ?>

    <?php if ($notes !== ''): ?>
    <div class="doc-section" style="margin:6pt 0 2pt;line-height:1.25"><span dir="ltr">Remarques</span><div style="font-size:0.9em;font-weight:600">ملاحظات</div></div>
    <div style="line-height:1.3"><?= nl2br(e($notes)) ?></div>
    <?php endif; ?>

    <div class="fiche-foot"><?= e($school['name_ar'] ?? '') ?> · <?= e($titleAr) ?> · صدر بتاريخ <?= date('d/m/Y') ?> · MSA Payroll</div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
