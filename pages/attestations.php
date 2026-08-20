<?php
/**
 * Attestations / إفادات — كل لغة مستقلة (عربي / فرنسي / إنكليزي)، رسمية، طباعة وتصدير.
 * الأنواع: راتب (مع الصفوف والمواد) / عمل / ضمان / استقالة / صرف من الخدمة / براءة ذمة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php';
requireLogin();

$currentPage = 'attestations';
$pageTitle = 'Attestations / إفادات';
$db = getDB();

$ATT_TYPES = [
    'cnss'           => ['fr' => 'Attestation pour la CNSS',     'en' => 'Social Security Certificate',  'ar' => 'إفادة للضمان الاجتماعي'],
    'cnss_travail'   => ['fr' => 'Attestation de travail CNSS (officiel)','en' => 'CNSS Work Attestation (official)','ar' => 'إفادة عمل للضمان (نموذج رسمي)'],
    'cnss_hire_new'  => ['fr' => "Déclaration d'embauche — non immatriculé (CNSS 2AA, officiel)", 'en' => 'Hiring Declaration — not registered (official)', 'ar' => 'تصريح باستخدام أجير — ما عندو رقم ضمان (نموذج رسمي)'],
    'cnss_hire_reg'  => ['fr' => "Déclaration d'embauche — déjà immatriculé (officiel)", 'en' => 'Hiring Declaration — registered (official)', 'ar' => 'إعلام استخدام أجير — عندو رقم ضمان (نموذج رسمي)'],
    'cnss_leave'     => ['fr' => 'Déclaration de cessation de travail (officiel)', 'en' => 'Leaving Declaration (official)', 'ar' => 'إعلام ترك أجير (نموذج رسمي)'],
    'salaire'        => ['fr' => 'Attestation de salaire',        'en' => 'Salary Certificate',          'ar' => 'إفادة راتب'],
    'tadris'         => ['fr' => "Attestation d'enseignement",    'en' => 'Teaching Certificate',        'ar' => 'إفادة تدريس'],
    'embassy'        => ['fr' => 'Attestation (ambassade, EN)',   'en' => 'Attestation (Embassy, EN)',   'ar' => 'إفادة للسفارة (إنكليزي)'],
    'riaaya'         => ['fr' => 'Attestation (organisme)',       'en' => 'Attestation (sponsor body)',  'ar' => 'إفادة للرعاية'],
    'anhaa_khedme'   => ['fr' => 'Lettre de fin de service (remise)', 'en' => 'End-of-Service Letter (handed)', 'ar' => 'كتاب إنهاء خدمات (في المدرسة)'],
    'anhaa_mail'     => ['fr' => 'Lettre de fin de service (courrier)','en' => 'End-of-Service Letter (by mail)','ar' => 'كتاب إنهاء خدمات (بالبريد المضمون)'],
    'talab_istiqala' => ['fr' => 'Demande de démission',         'en' => 'Resignation Request',          'ar' => 'طلب استقالة'],
    'afade_madrasiya'=> ['fr' => 'Attestation scolaire (Caisse)','en' => 'School Attestation (Fund)',     'ar' => 'إفادة مدرسية (صندوق التعويضات)'],
    'isqat_haq'      => ['fr' => 'Renonciation de droits',       'en' => 'Waiver of Rights',             'ar' => 'إفادة إسقاط حق'],
    'baraa_zimma'    => ['fr' => 'Quittance et décharge',        'en' => 'Release and Discharge',        'ar' => 'إقرار وإبراء ذمّة وإسقاط حق'],
    'iqrar'          => ['fr' => 'Déclaration (subventions)',    'en' => 'Declaration (grants)',         'ar' => 'إقرار (منح مالية + عدم ترك التعليم)'],
    'aqd_taalim'     => ['fr' => "Contrat d'enseignement",       'en' => 'Teaching Contract',            'ar' => 'عقد تعليم'],
    'notice_school'  => ['fr' => 'Avertissement (remis à l\'école)','en' => 'Warning (handed at school)','ar' => 'إنذار (يُسلَّم في المدرسة)'],
    'notice_mail'    => ['fr' => 'Avertissement (par courrier)',  'en' => 'Warning (by mail)',           'ar' => 'إنذار (بالبريد المضمون)'],
];
$DOC_LANGS = ['ar' => 'العربية', 'fr' => 'Français', 'en' => 'English'];

$employeeId = (int)($_GET['employee_id'] ?? 0);
$type    = $_GET['type'] ?? '';
$docLang = $_GET['lang_doc'] ?? ($_SESSION['lang'] ?? 'ar');
if (!isset($DOC_LANGS[$docLang])) $docLang = 'ar';
$effDate = $_GET['date'] ?? date('Y-m-d');
$emp = null;

// وضع «كل المدارس»: عند اختيار أستاذ، بدّل المدرسة الفعّالة لمدرسته تلقائياً — حتى يفتّش عن الأستاذ
// بلا ما يضطرّ يختار مدرسة أولاً (يُقيَّد بالمدارس المسموحة له إن كان حساب مدرسة).
if ($employeeId > 0 && isAllSchools()) {
    $sidQ = $db->prepare("SELECT school_id FROM employees WHERE id = ? AND is_deleted = 0");
    $sidQ->execute([$employeeId]);
    $sid = (int)$sidQ->fetchColumn();
    $mayView = isSuperAdmin() || (function_exists('viewerAllowedSchoolIds') && in_array($sid, viewerAllowedSchoolIds(), true));
    if ($sid > 0 && $mayView) { $_SESSION['active_schools'] = [$sid]; unset($_SESSION['report_schools']); }
}

if ($employeeId > 0) {
    requireSchoolSelected();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        $_SESSION['flash_error'] = 'الموظف غير موجود في هذه المدرسة / Employé introuvable';
        header('Location: ' . BASE_URL . 'pages/attestations.php');
        exit;
    }
    $exportTitle = 'Attestation_' . ($type ?: 'doc') . '_' . strtoupper($docLang) . '_' . $emp['first_name_fr'] . '_' . $emp['last_name_fr'];
    // الأولوية لرقم/إيميل المدرسة (يظهران افتراضاً بالبرومبت)، وإلا رقم/إيميل الأستاذ
    $schForExport = currentSchool();
    $exportOpts = [
        'phone' => (trim((string)($schForExport['phone'] ?? '')) ?: ($emp['phone1'] ?: '')),
        'email' => (trim((string)($schForExport['email'] ?? '')) ?: ($emp['email'] ?? '')),
    ];

    // 💾 حفظ تاريخ ترك العمل بملف الموظف من شاشة «إعلام ترك أجير» («بعدك ما عم بتحط
    // تاريخ الترك» 2026-08-18): النموذج يقرأ التاريخ من الملف حصراً — فإن كان الملف
    // فارغاً تعرض الشاشة خانة تحفظه بخانة «تاريخ ترك الضمان» بملف الموظف نفسه.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_leave_date'])) {
        requireCsrf();
        $newLd = trim((string)($_POST['ld_new'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newLd)) {
            $up = $db->prepare("UPDATE employees SET left_date_cnss = ? WHERE id = ?");
            $up->execute([$newLd, $employeeId]);
            $_SESSION['flash_success'] = 'انحفظ تاريخ ترك العمل بملف الموظف (خانة ترك الضمان): ' . formatDate($newLd) . ' / Date de cessation enregistrée dans le dossier.';
        } else {
            $_SESSION['flash_error'] = 'تاريخ غير صالح / Date invalide';
        }
        header('Location: ' . BASE_URL . 'pages/attestations.php?employee_id=' . $employeeId . '&type=cnss_leave&lang_doc=' . urlencode($docLang));
        exit;
    }
}

$school = currentSchool();
$schoolNameFr = $school['name_fr'] ?? getSetting('school_name_fr', 'Collège');
$schoolNameAr = $school['name_ar'] ?? getSetting('school_name_ar', 'المدرسة');
$schoolAddr   = $school['address'] ?? getSetting('school_address', '');
$schoolPhone  = $school['phone'] ?? getSetting('school_phone', '');
$director     = $school['director_name'] ?? '';
$employerNssf = $school['nssf_employer_number'] ?? '';
// 🏛️ إفادات الضمان تصدر باسم صاحب العمل المسجَّل برقمه لدى الصندوق
// (25-82-043 ⇒ «الراهبات المخلصيات لسيدة البشارة» — بطلب المستخدم 2026-08-19):
if (strpos((string)$type, 'cnss') === 0 && $school) {
    $schCn = cnssEmployerSchool($school);
    $schoolNameFr = $schCn['name_fr'] ?? $schoolNameFr;
    $schoolNameAr = $schCn['name_ar'] ?? $schoolNameAr;
}

// المسؤولون الموقّعون: المستخدم يختار أيّهم يظهر اسمه وهاتفه في التوقيع (الافتراضي الأول/المدير)
$signatories = schoolSignatories($school);
$sigIdx = (int)($_GET['sig'] ?? 0);
if ($sigIdx < 0 || $sigIdx >= count($signatories)) $sigIdx = 0;
$sig = $signatories[$sigIdx];
if (trim((string)($sig['name'] ?? '')) !== '') $director = $sig['name'];
$sigNameFr = $sig['name_fr'] ?? '';
$sigPhone  = $sig['phone'] ?? '';

// 🔴 لا doc-view هنا: الإفادات وملف الأستاذ يبقيان بشكلهما المعهود (شكوى المستخدم p1 بتاريخ 2026-08-01).
// صفحة اختيار الأستاذ (بلا موظف): لا شيء يُصدَّر — شريط التصدير زائد يعجّق الواجهة (2026-08-19)
if (!$emp) $hideExportToolbar = true;
include __DIR__ . '/../includes/header.php';

// ====== ملف الأستاذ الكامل / Dossier: كل شي عن الأستاذ بمكان واحد ======
// (صور المستندات: الشهادة/التذكرة/العائلي/الصورة + ر6 + بطاقة الراتب السنوية + سيرة الأستاذ + كل الإفادات)
if ($emp && !empty($_GET['dossier'])):
    $dNameAr = trim($emp['first_name_ar'].' '.$emp['last_name_ar']);
    $dNameFr = trim($emp['first_name_fr'].' '.$emp['last_name_fr']);
    $dTitle  = $dNameAr ?: $dNameFr;
    // بطاقة مستند: صورة مصغّرة + فتح + طباعة، أو PDF، أو «لا يوجد»
    $docCard = function($path, $label, $icon) {
        $out = '<div style="flex:1 1 180px;min-width:170px;border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#fff">';
        $out .= '<div style="font-weight:700;color:var(--primary);margin-bottom:8px"><i class="fas '.$icon.'"></i> '.e($label).'</div>';
        if (empty($path)) {
            $out .= '<div style="color:#b91c1c;font-size:13px"><i class="fas fa-circle-xmark"></i> Aucun fichier / لا يوجد ملف مرفوع</div>';
        } else {
            // المعاينة المحلية: الصور مرفوعة على السيرفر (أونلاين)؛ إذا الملف مش موجود محلياً اعرضه من msapayroll.com
            $absLocal = __DIR__ . '/../' . $path;
            $url = (BASE_URL !== '/' && !is_file($absLocal)) ? ('https://msapayroll.com/' . e($path)) : (BASE_URL . e($path));
            $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $path);
            if ($isImg) {
                $out .= '<a href="'.$url.'" target="_blank" title="Ouvrir en plein écran / فتح كامل"><img src="'.$url.'" style="max-height:150px;max-width:100%;border:1px solid #ccc;border-radius:6px;display:block;margin-bottom:8px"></a>';
            } else {
                $out .= '<div style="font-size:34px;color:#b91c1c;margin-bottom:8px"><i class="fas fa-file-pdf"></i> PDF</div>';
            }
            $out .= '<div style="display:flex;gap:6px;flex-wrap:wrap">'
                 . '<a href="'.$url.'" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-eye"></i> Ouvrir / فتح</a>'
                 . '<button type="button" onclick="ppPrintFile(\''.$url.'\','.($isImg?'true':'false').')" class="btn btn-sm btn-light"><i class="fas fa-print"></i> Imprimer / طباعة</button>'
                 . '</div>';
        }
        return $out . '</div>';
    };
    $r6Url    = BASE_URL.'pages/official_forms.php?form=tax_r6&employee_id='.$employeeId;
    $slipUrl  = BASE_URL.'pages/annual_slip.php?employee_id='.$employeeId.'&school_year='.urlencode(activeSchoolYear());
    $histUrl  = BASE_URL.'pages/employee_history.php?employee_id='.$employeeId;
    $editUrl  = BASE_URL.'pages/employees.php?action=edit&id='.$employeeId;
?>
    <div class="d-flex justify-between align-center mb-3 no-print" style="flex-wrap:wrap;gap:8px">
        <a href="<?= BASE_URL ?>pages/attestations.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> رجوع / Retour</a>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php /* 🧹 زرّ «طباعة الملف» أُزيل — مكرّر مع «طباعة» بشريط التصدير فوق (قاعدة المستخدم: لا أزرار مكرّرة) */ ?>
            <a href="<?= e($editUrl) ?>" class="btn btn-light"><i class="fas fa-user-pen"></i> Modifier le dossier / تعديل ملف الأستاذ</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>
          <span dir="ltr"><i class="fas fa-folder-open"></i> Dossier complet de l'enseignant — <?= e($dTitle) ?><?php if ($emp['employee_code']): ?> <small style="opacity:.7">(<?= e($emp['employee_code']) ?>)</small><?php endif; ?></span>
          <div style="font-size:0.85em;font-weight:600;opacity:0.9">ملف الأستاذ الكامل</div>
        </h3></div>
        <div class="card-body">
            <?php
            // ===== كل معلومات الأستاذ — كل حقول ملفّه مقسّمة لأقسام =====
            $yn  = fn($v) => ((int)$v === 1) ? 'نعم' : 'لا';
            $row2 = function($label, $value) {
                $v = trim((string)$value);
                return '<div style="display:flex;gap:10px;padding:5px 2px;border-bottom:1px dashed #eef2f7;font-size:13px">'
                     . '<span style="min-width:170px;max-width:170px;color:#64748b;font-weight:600">'.e($label).'</span>'
                     . '<span style="flex:1;color:#0f172a">'.($v===''?'—':e($v)).'</span></div>';
            };
            $section = function($title, $icon, $rows) {
                return '<div style="flex:1 1 340px;min-width:290px;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;background:#fff">'
                     . '<h4 style="color:var(--primary);margin:0 0 10px;font-size:15px"><i class="fas '.$icon.'"></i> '.e($title).'</h4>'.$rows.'</div>';
            };
            $incLbl = fn($ech,$ext,$pa) => implode('، ', array_filter([ (int)$ech?'التدرّج':'', (int)$ext?'الأجر الإضافي':'', (int)$pa?'المكافأة/المساعدة':'' ])) ?: 'الأساس فقط';
            $addr = implode(' - ', array_values(array_filter(array_map('trim', [
                $emp['ville']??'', $emp['quartier']??'', $emp['rue']??'', $emp['immeuble']??'',
                ($emp['etage']??'')!==''?('طابق '.$emp['etage']):'', $emp['district']??'', $emp['gouvernorat']??''
            ]))));
            $registry = trim(($emp['civil_registry_number']??'') . (($emp['civil_registry_place']??'')?(' / '.$emp['civil_registry_place']):''));
            $mother = trim(($emp['mother_first_name']??'').' '.($emp['mother_last_name']??''));
            $modeLbl = ['direct_usd'=>'راتب مباشر بالدولار','percent_of_lbp'=>'نسبة من راتب الليرة','direct_lbp'=>'راتب مباشر بالليرة'][$emp['salary_input_mode']??''] ?? ($emp['salary_input_mode']??'');
            $transport = ((float)($emp['transport_daily_amount']??0) > 0)
                ? (rtrim(rtrim(number_format((float)$emp['transport_daily_amount'],2),'0'),'.').' '.($emp['transport_daily_currency']??'LBP').' × '.(int)($emp['transport_days_per_week']??0).' يوم/أسبوع × '.rtrim(rtrim(number_format((float)($emp['transport_weeks']??0),1),'0'),'.').' أسبوع')
                : '';
            $m13 = (int)($emp['has_13th_month']??0) ? ('نعم'.(($emp['m13_include_extra']??0)||($emp['m13_include_aide']??0)?' — يشمل: '.implode('، ',array_filter([($emp['m13_include_extra']??0)?'الإضافي':'',($emp['m13_include_aide']??0)?'المكافأة':''])):'')) : 'لا';

            // معلومات شخصية
            $p  = $row2('الاسم الكامل (عربي)', trim(($emp['first_name_ar']??'').' '.($emp['father_name_ar']??'').' '.($emp['last_name_ar']??'')));
            $p .= $row2('Nom complet (FR)', trim(($emp['first_name_fr']??'').' '.($emp['father_name_fr']??'').' '.($emp['last_name_fr']??'')));
            $p .= $row2('اسم الأم', $mother);
            $p .= $row2('الجنسية', $emp['nationality']??'');
            $p .= $row2('تاريخ الولادة', $emp['birth_date'] ? formatDate($emp['birth_date']) : '');
            $p .= $row2('محل الولادة', $emp['birth_place']??'');
            $p .= $row2('رقم السجل / محله', $registry);
            $p .= $row2('الوضع العائلي', $emp['social_status']??'');
            $p .= $row2('الزوج/الزوجة يعمل', $yn($emp['spouse_works']??0));
            $p .= $row2('عدد الأولاد', (int)($emp['number_of_children']??0));

            // اتصال وسكن
            $c  = $row2('هاتف ١', $emp['phone1']??'');
            $c .= $row2('هاتف ٢', $emp['phone2']??'');
            $c .= $row2('البريد الإلكتروني', $emp['email']??'');
            $c .= $row2('العنوان الكامل', $addr);

            // معلومات وظيفية
            $w  = $row2('الفئة', employeeTypeLabel($emp['employee_type']));
            if (($emp['employee_type']??'')==='employe') $w .= $row2('نوع الوظيفة', ($emp['job_title']??'')!=='' ? jobTitleLabel($emp['job_title']) : '');
            $w .= $row2('الشهادة', ($emp['diploma']??'') ? diplomaLabel($emp['diploma']) : '');
            $w .= $row2('الاختصاص', $emp['specialization']??'');
            $w .= $row2('المواد التي يعلّمها', $emp['subjects_taught']??'');
            $w .= $row2('المرحلة', $emp['niveau_scolaire']??'');
            $w .= $row2('الصفوف', classLevelNames($emp['classes_taught']??''));
            $w .= $row2('الدرجة الحالية', gradeDisplay($emp));
            $w .= $row2('الدرجة الابتدائية', rtrim(rtrim(number_format((float)($emp['starting_grade']??0),1),'0'),'.'));
            $w .= $row2('ساعات/أسبوع', rtrim(rtrim(number_format((float)($emp['hours_per_week']??0),1),'0'),'.'));
            $w .= $row2('أيام/أسبوع', (int)($emp['days_per_week']??0));
            $w .= $row2('تاريخ الدخول', $emp['hire_date'] ? formatDate($emp['hire_date']) : '');
            $w .= $row2('تاريخ الملاك', $emp['titularization_date'] ? formatDate($emp['titularization_date']) : '');
            $w .= $row2('تاريخ تثبيت الملاك', $emp['tenure_confirmation_date'] ? formatDate($emp['tenure_confirmation_date']) : '');
            $w .= $row2('الحالة', employeeStatusLabel($emp['status'])['label']);
            $w .= $row2('استمرار العمل بعد ٦٤', $yn($emp['keep_working_past_64']??0));

            // أرقام رسمية وتواريخ الترك
            $o  = $row2('رقم الضمان (CNSS)', $emp['nssf_number']??'');
            $o .= $row2('الرقم المالي (MOF)', $emp['finance_ministry_number']??'');
            $o .= $row2('رقم الصندوق (Caisse)', $emp['caisse_number']??'');
            $o .= $row2('تاريخ ترك الضمان', $emp['left_date_cnss'] ? formatDate($emp['left_date_cnss']) : '');
            $o .= $row2('تاريخ ترك المالية', $emp['left_date_finance'] ? formatDate($emp['left_date_finance']) : '');
            $o .= $row2('تاريخ ترك الصندوق', $emp['left_date_eoc'] ? formatDate($emp['left_date_eoc']) : '');

            // الراتب والاقتطاعات
            $s  = $row2('طريقة إدخال الراتب', $modeLbl);
            if (($emp['salary_input_mode']??'')==='direct_usd') $s .= $row2('أساس الراتب بالدولار', (float)($emp['base_salary_usd']??0) ? ('$'.number_format((float)$emp['base_salary_usd'],2)) : '');
            if (($emp['salary_input_mode']??'')==='percent_of_lbp') $s .= $row2('النسبة من راتب الليرة', (float)($emp['base_salary_lbp_percent']??0) ? (rtrim(rtrim(number_format((float)$emp['base_salary_lbp_percent'],2),'0'),'.').'%') : '');
            if (($emp['salary_input_mode']??'')==='direct_lbp') $s .= $row2('راتب العقد (ل.ل)', (float)($emp['contract_salary_lbp']??0) ? formatLBP($emp['contract_salary_lbp']) : '');
            $s .= $row2('أشهر الدفع بالسنة', (int)($emp['payment_months_per_year']??0) ?: '');
            $s .= $row2('الشهر الثالث عشر', $m13);
            $s .= $row2('تعويض عائلي (زوج)', (float)($emp['family_allowance_spouse_lbp']??0) ? formatLBP($emp['family_allowance_spouse_lbp']) : '');
            $s .= $row2('تعويض عائلي (أولاد)', (float)($emp['family_allowance_children_lbp']??0) ? formatLBP($emp['family_allowance_children_lbp']) : '');
            $s .= $row2('تعويض النقل', $transport);

            // الخضوع للاقتطاعات
            $t  = $row2('خاضع للضريبة', (int)($emp['tax_subject']??0) ? ('نعم — يشمل: '.$incLbl($emp['tax_includes_echelon']??0,$emp['tax_includes_extra']??0,$emp['tax_includes_prime_aide']??0)) : 'لا');
            $t .= $row2('خاضع للضمان', (int)($emp['cnss_subject']??0) ? ('نعم — يشمل: '.$incLbl($emp['cnss_includes_echelon']??0,$emp['cnss_includes_extra']??0,$emp['cnss_includes_prime_aide']??0)) : 'لا');
            $t .= $row2('خاضع لصندوق التعويضات', (int)($emp['eoc_subject']??0) ? ('نعم — يشمل: '.$incLbl($emp['eoc_includes_echelon']??0,$emp['eoc_includes_extra']??0,$emp['eoc_includes_prime_aide']??0)) : 'لا');
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px">
                <?= $section('معلومات شخصية', 'fa-user', $p) ?>
                <?= $section('اتصال وسكن', 'fa-location-dot', $c) ?>
                <?= $section('معلومات وظيفية', 'fa-briefcase', $w) ?>
                <?= $section('أرقام رسمية وتواريخ الترك', 'fa-hashtag', $o) ?>
                <?= $section('الراتب والتعويضات', 'fa-money-bill-wave', $s) ?>
                <?= $section('الخضوع للاقتطاعات', 'fa-scale-balanced', $t) ?>
            </div>
            <?php if (trim((string)($emp['notes']??'')) !== ''): ?>
            <div style="border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:12px 16px;margin-bottom:22px">
                <h4 style="color:#b45309;margin:0 0 6px;font-size:15px">
                    <span dir="ltr"><i class="fas fa-note-sticky"></i> Remarques</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">ملاحظات</div>
                </h4>
                <div style="font-size:13.5px;white-space:pre-wrap"><?= e($emp['notes']) ?></div>
            </div>
            <?php endif; ?>

            <h4 class="no-print" style="color:var(--primary);margin:6px 0 10px">
                <span dir="ltr"><i class="fas fa-bolt"></i> Rapports de l'enseignant</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">تقارير الأستاذ</div>
            </h4>
            <div class="no-print" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:22px">
                <a href="<?= e($r6Url) ?>" class="btn btn-primary"><i class="fas fa-file-lines"></i> R6 — Relevé annuel individuel / ر6 — كشف سنوي إفرادي</a>
                <a href="<?= e($slipUrl) ?>" class="btn btn-gold"><i class="fas fa-file-invoice-dollar"></i> Fiche de salaire annuelle / بطاقة الراتب السنوية</a>
                <a href="<?= e($histUrl) ?>" class="btn btn-info"><i class="fas fa-user-clock"></i> Parcours de l'enseignant / سيرة الأستاذ</a>
            </div>

            <h4 style="color:var(--primary);margin:6px 0 10px">
                <span dir="ltr"><i class="fas fa-images"></i> Documents et photos de l'enseignant</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">مستندات وصور الأستاذ</div>
            </h4>
            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:22px">
                <?= $docCard($emp['diploma_doc_path'] ?? '', 'صورة الشهادة / Diplôme', 'fa-graduation-cap') ?>
                <?= $docCard($emp['id_document_path'] ?? '', 'Extrait d\'état civil / إخراج قيد / تذكرة', 'fa-id-card') ?>
                <?= $docCard($emp['family_doc_path'] ?? '', 'Extrait d\'état civil familial / إخراج قيد عائلي', 'fa-people-roof') ?>
                <?= $docCard($emp['photo_path'] ?? '', 'صورة شخصية / Photo', 'fa-image') ?>
            </div>

            <h4 class="no-print" style="color:var(--primary);margin:6px 0 10px">
                <span dir="ltr"><i class="fas fa-file-signature"></i> Générer une attestation pour cet enseignant — toutes les attestations classées</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">إصدار إفادة لهذا الأستاذ — كل الإفادات مرتّبة</div>
            </h4>
            <?php
            // كل أنواع الإفادات مقسّمة لأقسام واضحة (تُغطّى كل الأنواع + قسم «أخرى» احتياطاً لأي نوع جديد)
            $attGroups = [
                'Salaire, travail et CNSS / راتب وعمل وضمان' => ['salaire','tadris','cnss','cnss_travail','cnss_hire_new','cnss_hire_reg','cnss_leave','afade_madrasiya','embassy','riaaya'],
                'Fin de service, démission et décharge / نهاية الخدمة والاستقالة وإبراء الذمّة' => ['anhaa_khedme','anhaa_mail','talab_istiqala','isqat_haq','baraa_zimma'],
                'Contrats, déclarations et avertissements / عقود وإقرارات وإنذارات' => ['aqd_taalim','iqrar','notice_school','notice_mail'],
            ];
            $grouped = array_merge(...array_values($attGroups));
            $others = array_values(array_diff(array_keys($ATT_TYPES), $grouped));
            if ($others) $attGroups['Autres / أخرى'] = $others;
            ?>
            <div class="no-print">
                <?php foreach ($attGroups as $gTitle => $keys): ?>
                <div style="margin-bottom:12px">
                    <div style="font-weight:700;color:#334155;font-size:13px;margin-bottom:6px"><i class="fas fa-angle-left" style="color:var(--primary)"></i> <?= e($gTitle) ?></div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <?php foreach ($keys as $k): if (!isset($ATT_TYPES[$k])) continue; ?>
                        <a href="<?= BASE_URL ?>pages/attestations.php?employee_id=<?= (int)$employeeId ?>&type=<?= e($k) ?>&lang_doc=<?= e($docLang) ?>" class="btn btn-sm btn-light" style="border:1px solid #cbd5e1"><i class="fas fa-file-lines" style="color:var(--primary);opacity:.6"></i> <?= e($ATT_TYPES[$k]['fr'].' / '.$ATT_TYPES[$k]['ar']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script>
    // فتح/طباعة ملف (صورة أو PDF) بنافذة جديدة
    function ppPrintFile(url, isImg){
        var w = window.open('', '_blank');
        if(!w){ window.open(url, '_blank'); return; }
        if(isImg){
            w.document.write('<html><head><title>طباعة</title></head><body style="margin:0;text-align:center">'
                + '<img src="'+url+'" style="max-width:100%" onload="setTimeout(function(){window.print();},200)"></body></html>');
            w.document.close();
        } else {
            w.location.href = url; // PDF: يفتح ويطبع من عارض المتصفح
        }
    }
    </script>
<?php
    include __DIR__ . '/../includes/footer.php';
    return;
endif;

if (!$emp):
    [$ayf, $ayp] = yearEmploymentFilter(activeSchoolYear()); // فلترة حسب السنة الدراسية المختارة
    $aStmt = $db->prepare("SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, school_id, phone1, phone2
                             FROM employees WHERE is_deleted = 0" . schoolScopeSql() . $ayf . " ORDER BY school_id, FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $aStmt->execute($ayp);
    $employees = $aStmt->fetchAll();
    $attShowSch = isAllSchools(); // في وضع «كل المدارس» نعرض اسم المدرسة جنب كل أستاذ
?>
    <div class="card">
        <div class="card-header"><h3>
          <span dir="ltr"><i class="fas fa-user-check"></i> Enseignant : dossier complet ou attestation</span>
          <div style="font-size:0.85em;font-weight:600;opacity:0.9">الأستاذ: الملف الكامل أو إصدار إفادة</div>
        </h3></div>
        <div class="card-body">
            <?php if (!$employees): ?>
                <div class="alert alert-info">Aucun employé / لا يوجد موظفون.</div>
            <?php else: ?>
            <p class="text-muted" style="margin-bottom:10px"><i class="fas fa-info-circle"></i> فتّش عن الأستاذ مرّة وحدة (بتقدر تفتّش عنه حتى لو «كل المدارس» مختارة). بعدها إمّا اكبس «الملف الكامل» لتشوف كل شي عنه (مستنداته + ر6 + بطاقة الراتب + سيرته + كل الإفادات)، أو اختَر نوع الإفادة واللغة واكبس «إصدار الإفادة».</p>
            <form method="GET" class="form-row cols-4" style="align-items:end">
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Employé / الأستاذ</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Choisir / اختر —</option>
                        <?php foreach ($employees as $em): $pfx = $attShowSch ? (schoolNameById($em['school_id']).' — ') : ''; ?>
                        <option value="<?= $em['id'] ?>" data-phone="<?= e(trim(($em['phone1'] ?? '').' '.($em['phone2'] ?? ''))) ?>"><?= e($pfx.$em['employee_code'].' — '.$em['first_name_fr'].' '.$em['last_name_fr']) ?> / <?= e($em['first_name_ar'].' '.$em['last_name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Type / نوع الإفادة</label>
                    <select name="type" class="form-select">
                        <?php foreach ($ATT_TYPES as $k => $lbl): ?>
                        <option value="<?= $k ?>"><?= e($lbl['fr']) ?> / <?= e($lbl['ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Langue / اللغة</label>
                    <select name="lang_doc" class="form-select">
                        <?php foreach ($DOC_LANGS as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $k===$docLang?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date (استقالة/صرف/براءة)</label>
                    <input type="date" name="date" class="form-control" value="<?= e($effDate) ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap">
                    <button class="btn btn-primary"><i class="fas fa-file-export"></i> Générer l'attestation / إصدار الإفادة</button>
                    <button name="dossier" value="1" class="btn btn-info"><i class="fas fa-folder-open"></i> Dossier complet / الملف الكامل</button>
                </div>
            </form>
            <p class="text-muted mt-3"><i class="fas fa-info-circle"></i> كل إفادة تصدر بلغة واحدة مستقلة ومرتّبة. تقدر تبدّل اللغة من الوثيقة وتطبع/تصدّر كل لغة لحالها. حقل التاريخ للاستقالة والصرف وبراءة الذمة.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else:
    // نموذج «إفادة عمل للضمان» الرسمي: يُملأ من قالب المستخدم → أزرار تحميل (لا رسالة HTML)
    if ($type === 'cnss_travail'):
        $ts  = strtotime($effDate) ?: time();
        $d   = (int)date('j', $ts); $mo = (int)date('n', $ts); $yr = (int)date('Y', $ts);
        $nm  = preg_replace('/\s+/', ' ', trim(($emp['first_name_ar'] ?? '') . ' ' . ($emp['father_name_ar'] ?? '') . ' ' . ($emp['last_name_ar'] ?? '')));
        if ($nm === '') $nm = trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['last_name_fr'] ?? ''));
        $end = $mo - 1; $mlist = [];
        for ($i = 0; $i < 7; $i++) { $idx = $end - (6 - $i); $norm = (($idx - 1) % 12 + 12) % 12 + 1; $mlist[] = monthName($norm, 'ar'); }
        $expBase = BASE_URL . 'pages/official_export.php?form=cnss_work_attestation&emp=' . (int)$employeeId . '&d=' . $d . '&mo=' . $mo . '&yr=' . $yr;
    ?>
    <div class="card no-print" style="max-width:760px;margin:0 auto">
        <div class="card-header"><h3>
          <span dir="ltr"><i class="fas fa-file-medical"></i> Attestation de travail CNSS (officiel) — <?= e($nm) ?></span>
          <div style="font-size:0.85em;font-weight:600;opacity:0.9">إفادة عمل للضمان (نموذج رسمي)</div>
        </h3></div>
        <div class="card-body">
            <form method="GET" class="form-row cols-3" style="align-items:end;margin-bottom:14px">
                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                <input type="hidden" name="type" value="cnss_travail">
                <div class="form-group">
                    <label class="form-label">Date de l'attestation / تاريخ الإفادة (في:)</label>
                    <input type="date" name="date" class="form-control" value="<?= e($effDate) ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group"><button class="btn btn-secondary"><i class="fas fa-sync"></i> Actualiser les mois / تحديث الأشهر</button></div>
            </form>
            <div class="alert alert-info">
                الأشهر السبعة المحتسَبة تلقائياً من التاريخ (من الأقدم للأحدث): <strong><?= e(implode(' ، ', $mlist)) ?></strong> — كلّها «دوام كامل».<br>
                التاريخ تحت الإفادة: <strong><?= $d . ' / ' . $mo . ' / ' . $yr ?></strong>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <a class="btn btn-danger btn-lg" href="<?= e($expBase . '&format=pdf') ?>" target="_blank"><i class="fas fa-print"></i> Attestation officielle (Impression / PDF) / الإفادة الرسمية (طباعة / PDF)</a>
                <a class="btn btn-success btn-lg" href="<?= e($expBase . '&format=xlsx') ?>"><i class="fas fa-file-excel"></i> Télécharger Excel (modifiable) / تحميل Excel (للتعديل)</a>
            </div>
            <p class="text-muted mt-3"><i class="fas fa-info-circle"></i> «الإفادة الرسمية» تفتح النموذج الرسمي كاملاً معبّأً (المدرسة ورقمها في الضمان، اسم الأجير ورقم ضمانه وسنة ولادته، والأشهر) جاهز للطباعة — <strong>نفس الشكل تماماً أونلاين وعلى الكمبيوتر</strong>. زر Excel للتحميل والتعديل.</p>
        </div>
    </div>
    <?php
        include __DIR__ . '/../includes/footer.php';
        return;
    endif;
    // نماذج الضمان الرسمية الثلاثة (قوالب المستخدم الأصلية): استخدام أجير جديد/مضمون سابقاً + ترك أجير.
    // شاشة خيارات صغيرة (الجنس + الساعات + تاريخ/سبب الترك) ثم أزرار PDF/Excel طبق الأصل.
    if (in_array($type, ['cnss_hire_new', 'cnss_hire_reg', 'cnss_leave'], true)):
        $isLeave = ($type === 'cnss_leave');
        $ts  = strtotime($effDate) ?: time();
        $d   = (int)date('j', $ts); $mo = (int)date('n', $ts); $yr = (int)date('Y', $ts);
        $nm  = preg_replace('/\s+/', ' ', trim(($emp['first_name_ar'] ?? '') . ' ' . ($emp['father_name_ar'] ?? '') . ' ' . ($emp['last_name_ar'] ?? '')));
        if ($nm === '') $nm = trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['last_name_fr'] ?? ''));
        $sexSel = (($_GET['sex'] ?? 'm') === 'f') ? 'f' : 'm';
        $hrsDef = trim((string)($_GET['hrs'] ?? ''));
        if ($hrsDef === '' && (float)($emp['hours_per_week'] ?? 0) > 0) $hrsDef = (string)round((float)$emp['hours_per_week'] * 52 / 12);
        // 🔴 تاريخ الترك من ملف الموظف (تاريخ ترك الضمان) حصراً — يُعرض للعلم فقط ويُعدَّل من ملف الموظف
        $leaveDate = $emp['left_date_cnss'] ?: '';
        $reasonSel = (int)($_GET['reason'] ?? 1); if ($reasonSel < 1 || $reasonSel > 7) $reasonSel = 1;
        $REASONS = [1=>'استقالة / Démission',2=>'بلوغ السن / Âge légal',3=>'عجز / Invalidité',4=>'زواج / Mariage',5=>'وفاة / Décès',6=>'هجرة / Émigration',7=>'عمل آخر / Autre emploi'];
        $expBase = BASE_URL . 'pages/official_export.php?form=' . e($type) . '&emp=' . (int)$employeeId
                 . '&d=' . $d . '&mo=' . $mo . '&yr=' . $yr . '&sex=' . $sexSel . '&hrs=' . urlencode($hrsDef)
                 . ($isLeave ? '&reason=' . $reasonSel : '');
    ?>
    <div class="card no-print" style="max-width:760px;margin:0 auto">
        <div class="card-header"><h3>
          <span dir="ltr"><i class="fas fa-file-medical"></i> <?= e($ATT_TYPES[$type]['fr']) ?> — <?= e($nm) ?></span>
          <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e($ATT_TYPES[$type]['ar']) ?></div>
        </h3></div>
        <div class="card-body">
            <?php if ($isLeave && $leaveDate === ''): ?>
            <div style="border:1px solid #fecaca;background:#fef2f2;border-radius:10px;padding:12px 16px;margin-bottom:14px">
                <div style="color:#b91c1c;font-weight:700;margin-bottom:8px"><i class="fas fa-triangle-exclamation"></i>
                    ملف الموظف ما فيه تاريخ ترك عمل — حطّه هون مرّة وحدة وينحفظ بملفه (خانة «تاريخ ترك الضمان»)، والنموذج يقرأه من الملف دائماً.
                    <span style="font-weight:400;font-size:12.5px;display:block">Aucune date de cessation dans le dossier — enregistrez-la ici une seule fois.</span>
                </div>
                <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_leave_date" value="1">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Date de cessation / تاريخ ترك العمل</label>
                        <input type="date" name="ld_new" class="form-control" required>
                    </div>
                    <button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer dans le dossier / احفظ بملف الموظف</button>
                </form>
            </div>
            <?php endif; ?>
            <form method="GET" class="form-row cols-3" style="align-items:end;margin-bottom:14px">
                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <div class="form-group">
                    <label class="form-label">Date de la déclaration / تاريخ التصريح</label>
                    <input type="date" name="date" class="form-control" value="<?= e($effDate) ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group">
                    <label class="form-label">Sexe / الجنس</label>
                    <select name="sex" class="form-select" onchange="this.form.submit()">
                        <option value="m" <?= $sexSel==='m'?'selected':'' ?>>Homme / ذكر</option>
                        <option value="f" <?= $sexSel==='f'?'selected':'' ?>>Femme / أنثى</option>
                    </select>
                </div>
                <?php if (!$isLeave): ?>
                <div class="form-group">
                    <label class="form-label">Heures de travail par mois / ساعات العمل في الشهر</label>
                    <input type="number" name="hrs" class="form-control" value="<?= e($hrsDef) ?>" onchange="this.form.submit()">
                </div>
                <?php else: ?>
                <?php if ($leaveDate !== ''): ?>
                <div class="form-group">
                    <label class="form-label">Date de cessation / تاريخ ترك العمل (من ملف الموظف)</label>
                    <input type="text" class="form-control" value="<?= e(formatDate($leaveDate)) ?>" readonly style="background:#f1f5f9;font-weight:700">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Motif / سبب ترك العمل</label>
                    <select name="reason" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($REASONS as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $k===$reasonSel?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <a class="btn btn-danger btn-lg" href="<?= e($expBase . '&format=pdf') ?>" target="_blank"><i class="fas fa-print"></i> النموذج الرسمي (طباعة / PDF) / Formulaire officiel (PDF)</a>
                <a class="btn btn-success btn-lg" href="<?= e($expBase . '&format=xlsx') ?>"><i class="fas fa-file-excel"></i> Télécharger Excel (modifiable) / تحميل Excel (للتعديل)</a>
            </div>
            <p class="text-muted mt-3"><i class="fas fa-info-circle"></i> النموذج يطلع <strong>طبق الأصل عن نموذج الضمان الرسمي</strong> معبّأً تلقائياً: المدرسة ورقمها في الضمان وهاتفها وعنوانها، واسم الأجير وأهله وولادته وسجلّه وعنوانه، وتاريخ الاستخدام وراتبه الخاضع للضمان رقماً وحروفاً<?= $isLeave ? '، وتاريخ الترك وسببه' : '' ?>. عدّل الخيارات فوق قبل الطباعة إذا لزم.</p>
        </div>
    </div>
    <?php
        include __DIR__ . '/../includes/footer.php';
        return;
    endif;
    if (!isset($ATT_TYPES[$type])) $type = 'cnss';
    $sessLang = $_SESSION['lang'] ?? 'fr';
    $nomFr = trim($emp['first_name_fr'].' '.($emp['father_name_fr'] ? $emp['father_name_fr'].' ' : '').$emp['last_name_fr']);
    $nomAr = trim($emp['first_name_ar'].' '.($emp['father_name_ar'] ? $emp['father_name_ar'].' ' : '').$emp['last_name_ar']);
    if ($nomAr === '') $nomAr = $nomFr;
    $isEmploye = ($emp['employee_type'] === 'employe');
    $jobT = trim((string)($emp['job_title'] ?? ''));
    // «الصفة» في الإفادات: للموظف الإداري نوع وظيفته الفعلي (سكرتير/محاسب/سائق...)، وإلا فئته (أستاذ ملاك/متعاقد/موظف إداري).
    $fnFr = ['fr'=> ($isEmploye && $jobT !== '') ? jobTitleLabel($emp['job_title'],'fr') : employeeTypeLabel($emp['employee_type'],'fr'),
             'ar'=> ($isEmploye && $jobT !== '') ? jobTitleLabel($emp['job_title'],'ar') : employeeTypeLabel($emp['employee_type'],'ar'),
             'en'=> ($isEmploye && $jobT !== '') ? jobTitleLabel($emp['job_title'],'fr') : (['enseignant_titulaire'=>'Tenured teacher','enseignant_contractuel'=>'Contract teacher','employe'=>'Administrative employee'][$emp['employee_type']] ?? $emp['employee_type'])];
    $today = date('d/m/Y');
    $hireFmt = formatDate($emp['hire_date']);
    $titFmt  = formatDate($emp['titularization_date']);
    $effFmt  = formatDate($effDate);
    $clsNm = classLevelNames($emp['classes_taught'] ?? ''); if ($clsNm === '—') $clsNm = '';
    $classesAr = $clsNm;
    $classesLat = $clsNm;
    $subjects = trim((string)$emp['subjects_taught']);
    $isTit = $emp['employee_type'] === 'enseignant_titulaire';

    // اختيار شهر الراتب الأمثل للإفادة (الأولوية بالترتيب):
    //  (1) شهر فيه **أجر إضافي/مكافأة** (prime/extra) — غالباً معظم الراتب، فلا تطلع الإفادة ناقصة،
    //  (2) ثمّ شهر فيه **صافي/أساس** فعلي، (3) ثمّ الأحدث زمنياً. fallback: آخر صفّ مهما كان.
    // ملاحظة: لا نعتمد «الصافي» وحده لأنّ بعض الصفوف أونلاين صافيها صفر (غير محسوب) رغم وجود الإضافي.
    // 🔴 مصدر واحد (2026-08-06): الشهر يُختار من **السنة الدراسية المعروضة نفسها** التي تعرضها
    // كل التقارير والبطاقات — لا من كل التاريخ (كانت الإفادة تلقّط شهراً من سنة أخرى، مثلاً
    // السنة الجديدة المفتوحة، فتخالف أرقامُها الكشوفَ). إن لم يكن للموظف صفوف بالسنة المعروضة
    // (أو الوضع «كل السنين») → أفضل شهر إجمالاً كما قبل.
    $salPickSql = "ORDER BY (prime_fixe_lbp > 0 OR extra_lbp > 0) DESC,
                 (net_salary_lbp > 0 OR base_plus_echelon_lbp > 0) DESC,
                 year DESC, month DESC LIMIT 1";
    $sal = null;
    $attSy = activeSchoolYear();
    if ($attSy !== 'all') {
        $q = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND school_year = ? " . $salPickSql);
        $q->execute([$employeeId, $attSy]);
        $sal = $q->fetch();
    }
    if (!$sal) {
        $q = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? " . $salPickSql);
        $q->execute([$employeeId]);
        $sal = $q->fetch();
    }
    if ($sal) {
        $basePlusEch=(float)$sal['base_plus_echelon_lbp']; $net=(float)$sal['net_salary_lbp'];
        $netUsd=rowUsd($sal, 'net_salary_usd', 'net_salary_lbp'); $cnssAmt=(float)$sal['cnss_amount_lbp']; $schoolCnss=(float)$sal['school_cnss_8_lbp'];
        $salPeriodAr = monthName((int)$sal['month'],'ar').' '.$sal['year'];
        $salPeriodLat = monthName((int)$sal['month'],'fr').' '.$sal['year'];
    } else {
        // لا صفّ راتب محسوب → قدّر الأساس: الأستاذ من السلسلة، والموظف/المتعاقد من راتبه المباشر
        // (الموظف الإداري يخضع لقانون العمل بلا سلسلة رتب — نفس منطق payroll_calculator::calculateBaseAndEchelon).
        if ($emp['employee_type'] === 'enseignant_titulaire') {
            $basePlusEch = scaleSalaryLBP($emp['current_grade']);
        } elseif (($emp['salary_input_mode'] ?? '') === 'direct_usd' && (float)$emp['base_salary_usd'] > 0) {
            $basePlusEch = round((float)$emp['base_salary_usd'] * getExchangeRate());
        } else {
            $basePlusEch = (float)($emp['contract_salary_lbp'] ?? 0);
        }
        $net=$basePlusEch; $netUsd=0;
        $cnssAmt=round($basePlusEch*0.03); $schoolCnss=round($basePlusEch*0.08);
        $salPeriodAr=currentSchoolYear(); $salPeriodLat=$salPeriodAr;
    }
    $yAr=$yFr=$yEn='';
    if ($emp['hire_date']) {
        $diff=(new DateTime($emp['hire_date']))->diff(new DateTime($effDate));
        $yAr=$diff->y.' سنة'.($diff->m?" و{$diff->m} شهر":'');
        $yFr=$diff->y.' an(s)'.($diff->m?" et {$diff->m} mois":'');
        $yEn=$diff->y.' year(s)'.($diff->m?" and {$diff->m} month(s)":'');
    }
    // خيار مكوّنات الراتب في الإفادة: الأساس بعد التدرّج وحده، أو + الأجر الإضافي، أو + مكافأة ومساعدة
    $extraW = $sal ? (int)((float)$sal['extra_lbp'] + (float)$sal['prime_fixe_lbp']) : 0;
    $aideW  = $sal ? (int)(float)$sal['aide_complementaire_lbp'] : 0;
    $transW = $sal ? (int)(float)$sal['transport_lbp'] : 0;
    // المكوّنات: عند أوّل فتح (opts_set غير موجود) تتبع اختيار «الراتب يشمل» العام بالترويسة
    // (salaryComp)؛ بعد أي تفاعل مع الفورم (opts_set=1) تُحترَم حالة المربّعات الفعلية.
    $optsSet  = !empty($_GET['opts_set']);
    // «الأجر الإضافي بكل الإفادات بدها تكون» (بطلبه 2026-08-20): الإضافي محطوط افتراضياً بكل
    // الإفادات (لا يتبع زر «الراتب يشمل» العام)، ومربّع الخيار بالفورم يبقى بيد المستخدم
    $incExtra = $optsSet ? !empty($_GET['inc_extra']) : true;
    $incAide  = $optsSet ? !empty($_GET['inc_aide'])  : salaryCompHas('aide');
    $incTrans = $optsSet ? !empty($_GET['inc_trans']) : salaryCompHas('transport');
    $salShown = (int)$basePlusEch + ($incExtra ? $extraW : 0) + ($incAide ? $aideW : 0) + ($incTrans ? $transW : 0);
    // العملة: ليرة (افتراضي) أو دولار — التحويل عبر سعر صرف شهر الراتب
    // العملة: تتبع الوضع العام (ليرة/دولار/الاثنين) ما لم يُحدَّد يدوياً لهذه الإفادة عبر ?cur=
    $cur = $_GET['cur'] ?? displayCurrency();
    if (!in_array($cur, ['lbp', 'usd', 'both'], true)) $cur = 'lbp';
    $fxRate = $sal ? getExchangeRate((int)$sal['month'], (int)$sal['year']) : getExchangeRate();
    $usdOf   = function ($lbp) use ($fxRate) { return $fxRate > 0 ? $lbp / $fxRate : 0; };
    $money   = function ($lbp) use ($cur, $usdOf) {
        if ($cur === 'usd') return formatUSD($usdOf($lbp));
        if ($cur === 'both') return formatLBP($lbp) . ' (' . formatUSD($usdOf($lbp)) . ')';
        return formatLBP($lbp);
    };
    $moneyAr = function ($lbp) use ($cur, $usdOf) {
        if ($cur === 'usd') return formatUSD($usdOf($lbp));
        if ($cur === 'both') return formatLBP($lbp, false) . ' ل.ل (' . formatUSD($usdOf($lbp)) . ')';
        return formatLBP($lbp, false) . ' ل.ل';
    };
    $moneyWords = function ($lbp) use ($cur, $usdOf) {
        if ($cur === 'usd') return numToArabicWords((int)round($usdOf($lbp))) . ' دولار أميركي';
        // «الاثنين»: تُعتمد الليرة بالحروف (المبلغ القانوني)، والدولار يظهر رقماً في المتن
        return numToArabicWords((int)round($lbp)) . ' ليرة لبنانية';
    };
    // مبلغ حرّ يكتبه المستخدم (تعويض الصرف المحسوب) — يُعرَض كما هو بالعملة المختارة بلا تحويل
    $eos = (int)preg_replace('/[^0-9]/', '', (string)($_GET['eos'] ?? ''));
    $embAmt = (int)preg_replace('/[^0-9]/', '', (string)($_GET['emb_amt'] ?? '')); // مبلغ إفادة السفارة (يدوي)
    // عقد التعليم: المبلغ المتفق عليه (يدوي) — بالليرة و/أو بالدولار، تُعبَّأ عملة وحدها أو الاثنتان معاً
    $aqdLbp = (int)preg_replace('/[^0-9]/', '', (string)($_GET['aqd_lbp'] ?? ''));
    $aqdUsd = (int)preg_replace('/[^0-9]/', '', (string)($_GET['aqd_usd'] ?? ''));
    $isqMode = in_array(($_GET['isq'] ?? ''), ['istiqala', 'sarf'], true) ? $_GET['isq'] : ''; // إسقاط الحق: استقالة أو صرف
    $grant   = (int)preg_replace('/[^0-9]/', '', (string)($_GET['grant'] ?? '')); // قيمة المنحة بالدولار (إقرار) — تظهر بالأرقام والحروف
    // صفة الموقّع أسفل الإفادة: الرئيسة / الإدارة / المدير — يختارها المستخدم لكل إفادة
    $sigTitle = $_GET['sig_t'] ?? 'moudir';
    if (!in_array($sigTitle, ['raisa', 'idara', 'moudir'], true)) $sigTitle = 'moudir';
    $SIG_TITLES = ['raisa' => ['ar' => 'الرئيسة', 'fr' => 'La Supérieure'],
                   'idara' => ['ar' => 'الإدارة', 'fr' => 'La Direction'],
                   'moudir'=> ['ar' => 'المدير',  'fr' => 'Le Directeur']];
    $sigTitleAr = $SIG_TITLES[$sigTitle]['ar'];
    $hasSigTitle = ($type === 'salaire'); // الإفادات التي فيها خيار صفة الموقّع
    // شعار المدرسة للترويسة (خاص بالمدرسة أو الموحّد)
    $logoUrl = schoolLogoUrl($school);
    $logoImg = $logoUrl ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="" style="max-height:88px;max-width:150px;object-fit:contain">' : '';
    // مجموعات حسب نوع الإفادة + خيار «رأس/شعار المدرسة» (يختاره المستخدم لكل إفادة)
    $hasComponents = in_array($type, ['cnss', 'afade_madrasiya', 'isqat_haq', 'salaire', 'embassy', 'aqd_taalim'], true);
    $hasCurrency   = in_array($type, ['cnss', 'afade_madrasiya', 'isqat_haq', 'aqd_taalim', 'salaire', 'embassy'], true);
    $isNotice      = in_array($type, ['notice_school', 'notice_mail'], true);
    $defaultLogo   = in_array($type, ['anhaa_khedme', 'anhaa_mail', 'aqd_taalim', 'cnss', 'notice_school', 'notice_mail', 'salaire', 'tadris', 'embassy', 'riaaya'], true); // الصادرة عن المدرسة: الشعار افتراضياً
    $showLogo      = isset($_GET['logo']) ? ($_GET['logo'] === '1') : $defaultLogo;
    $subjectTxt    = trim((string)($_GET['subj_txt'] ?? '')); if ($subjectTxt === '') $subjectTxt = 'الإهمال في حفظ النظام والتربية';
    $assocTxt      = trim((string)($_GET['assoc_txt'] ?? '')); if ($assocTxt === '') $assocTxt = 'التابعة لجمعية الراهبات المخلصيات لسيدة البشارة المسجّلة لديكم تحت الرقم (.....)';
    // الترويسة الرسمية الكاملة كصورة خلفية (إن وُجدت لهذه المدرسة) — تُغني عن الترويسة المُعاد بناؤها
    $lhUrl = schoolLetterheadUrl($school, ($type === 'embassy') ? 'fr' : 'ar');
    $lhOn  = ($showLogo && $lhUrl !== '' && !in_array($type, ['notice_mail', 'anhaa_mail'], true)); // نماذج البريد لها ترويسة بريدية خاصة
    $showRecHead = ($showLogo && !$lhOn); // عرض الترويسة المُعاد بناؤها فقط حين لا توجد صورة ترويسة
    $lhStyle = $lhOn
        // 🔴 1122 لا 1123: ورقة A4 = 297mm = 1122.5px — صندوق 1123px أطول من الورقة بنصف
        // بكسل فينكسر آخر سطر (التوقيع) لصفحة ثانية فاضية (شكوى المستخدم 2026-08-03)
        ? "width:794px;min-height:1122px;margin:0 auto;background:url('" . htmlspecialchars($lhUrl, ENT_QUOTES) . "') no-repeat;background-size:794px 1122px;padding:195px 95px 150px;box-sizing:border-box"
        : "max-width:820px;margin:0 auto;padding:28px 32px";
    $lhClass = $lhOn ? '' : 'card';
    // 🪪 ترويسة تصدير الوورد (بطلبه 2026-08-20): بلا خط تحت الشعار + العنوان اسم المدينة فقط
    // + الهاتف LTR حتى يبقى الكود على شمال الرقم (بسياق RTL كان يطلع «531450-04»)
    // الشعار بقياس width/height صريح: وورد لا يفهم max-height فكان الشعار الكبير يطلع بحجمه الكامل
    $logoImgWord = $logoImg;
    if ($logoUrl) {
        $lf = dirname(__DIR__) . '/' . ltrim(substr($logoUrl, strlen(BASE_URL)), '/');
        $sz = @getimagesize($lf);
        if ($sz && $sz[0] > 0 && $sz[1] > 0) {
            $k = min(88 / $sz[1], 150 / $sz[0], 1);
            $logoImgWord = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="" width="' . (int)round($sz[0] * $k) . '" height="' . (int)round($sz[1] * $k) . '">';
        }
    }
    $cityAr = trim((string)(preg_split('/[-–,،]/u', (string)$schoolAddr)[0] ?? ''));
    // 🏷️ «لوغو الراهبات» (نموذجه عالدسك توب، بطلبه 2026-08-20): الكتابة لكل مدرسة متوسّطة تحت
    // الشعار — اسم المدرسة، فسطر «للراهبات المخلصيات – المدينة» (إن كان بالاسم)، فالهاتف.
    // جدول بلا حدود بدل inline-block لأن وورد لا يفهم inline-block (والحدود/العرض إنلاين تغلب ستايل تصدير الوورد)
    $headL1 = trim((string)$schoolNameAr); $headL2 = $cityAr;
    if (mb_strpos($headL1, 'للراهبات المخلصيات') !== false) {
        $headL2 = trim('للراهبات المخلصيات' . ($cityAr !== '' ? ' – ' . $cityAr : ''));
        $headL1 = trim(mb_substr($headL1, 0, mb_strpos($headL1, 'للراهبات المخلصيات')));
    }
    // جدول بعرض كامل وخانة المحتوى الأولى (= جهة البداية: يمين بالعربي) — «جدول width:auto» كان
    // الوورد يوسّطه بنص الصفحة، وهالشكل يثبت الكتلة بجهتها بالشاشة والوورد سواء
    $headBodyAr = function ($logoHtml) use ($headL1, $headL2, $schoolPhone) {
        return '<table style="width:100%;border-collapse:collapse"><tr><td style="border:none;padding:0;width:32%;text-align:center;line-height:1.8">'
            . ($logoHtml ? '<div style="margin-bottom:2px">' . $logoHtml . '</div>' : '')
            . '<strong style="font-size:16px">' . e($headL1) . '</strong>'
            . ($headL2 !== '' ? '<br><strong style="font-size:15px">' . e($headL2) . '</strong>' : '')
            . ($schoolPhone ? '<br><span style="font-size:14px">هاتف : <span dir="ltr">' . e($schoolPhone) . '</span></span>' : '')
            . '</td><td style="border:none;padding:0"></td></tr></table>';
    };
    $schoolHeadWord = '<div style="margin-bottom:16px">' . $headBodyAr($logoImgWord) . '</div>';
    $freeNum   = function ($v) use ($cur) {
        if ($cur === 'usd') return '$' . number_format($v, 2);
        return formatLBP($v, false) . ' ل.ل';
    };
    $freeWords = function ($v) use ($cur) { return numToArabicWords((int)round($v)) . ($cur === 'usd' ? ' دولار أميركي' : ' ليرة لبنانية'); };
    $L=$money($salShown); $N=$money($net); $U='';
    $nssf=cnssWithBirthYear($emp['nssf_number'], $emp['birth_date']);
    $rtl = ($docLang === 'ar');
    $qs = 'employee_id='.$employeeId.'&type='.urlencode($type).'&date='.urlencode($effDate).'&opts_set=1'.($incExtra?'&inc_extra=1':'').($incAide?'&inc_aide=1':'').($incTrans?'&inc_trans=1':'').'&cur='.$cur.($eos>0?'&eos='.$eos:'').($isqMode?'&isq='.$isqMode:'').'&logo='.($showLogo?'1':'0').($isNotice?'&subj_txt='.urlencode($subjectTxt):'').($type==='riaaya'?'&assoc_txt='.urlencode($assocTxt):'').($embAmt>0?'&emb_amt='.$embAmt:'').($grant>0?'&grant='.$grant:'').($aqdLbp>0?'&aqd_lbp='.$aqdLbp:'').($aqdUsd>0?'&aqd_usd='.$aqdUsd:'').($sigIdx>0?'&sig='.$sigIdx:'').($hasSigTitle?'&sig_t='.$sigTitle:'');
?>
    <div class="d-flex justify-between align-center mb-3 no-print" style="flex-wrap:wrap;gap:8px">
        <div class="btn-group" role="group">
            <?php foreach ($DOC_LANGS as $lk => $lbl): ?>
            <a href="?<?= $qs ?>&lang_doc=<?= $lk ?>" class="btn btn-sm <?= $lk===$docLang?'btn-primary':'btn-light' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm" style="background:#0ea5e9;color:#fff" onclick="ppSendAtt('<?= e(addslashes('pages/attestations.php?'.$qs)) ?>','<?= e($exportOpts['email'] ?? '') ?>','<?= e($exportTitle ?? 'attestation') ?>')"><i class="fas fa-paper-plane"></i> Envoyer par email (PDF auto) / إرسال بالإيميل (PDF تلقائي)</button>
    </div>
    <script>
    function ppSendAtt(target, defEmail, name){
        var to = window.prompt('إرسال الإفادة (PDF مرفق) إلى بريد:', defEmail || '');
        if (to === null || !to.trim()) return;
        window.location.href = '<?= BASE_URL ?>pages/send_attestation.php?target=' + encodeURIComponent(target) + '&to=' + encodeURIComponent(to.trim()) + '&name=' + encodeURIComponent(name);
    }
    </script>

    <form method="get" class="card no-print" style="margin-bottom:14px;background:#eef6ff">
        <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="lang_doc" value="<?= e($docLang) ?>">
        <input type="hidden" name="date" value="<?= e($effDate) ?>">
        <input type="hidden" name="opts_set" value="1">
        <div class="card-body" style="padding:10px 14px">
            <strong>En-tête de l'école / رأس المدرسة:</strong>
            <input type="hidden" name="logo" value="0">
            <label style="margin:0 10px;cursor:pointer"><input type="checkbox" name="logo" value="1" <?= $showLogo?'checked':'' ?> onchange="this.form.submit()"> Mettre le logo de l'école / ضع شعار المدرسة على الإفادة</label>
            <?php if (count($signatories) > 1): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>Signataire responsable / الموقّع المسؤول:</strong>
            <select name="sig" onchange="this.form.submit()" style="padding:3px 6px;margin-right:6px">
                <?php foreach ($signatories as $si => $sgo): ?>
                <option value="<?= $si ?>" <?= $si===$sigIdx?'selected':'' ?>><?= e($sgo['name'] . ($sgo['title']?' — '.$sgo['title']:'') . ($sgo['phone']?' ('.$sgo['phone'].')':'')) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?><input type="hidden" name="sig" value="0"><?php endif; ?>
            <?php if ($hasSigTitle): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>Signature / الإمضاء:</strong>
            <?php foreach ($SIG_TITLES as $stk => $stl): ?>
            <label style="margin:0 10px;cursor:pointer"><input type="radio" name="sig_t" value="<?= $stk ?>" <?= $sigTitle===$stk?'checked':'' ?> onchange="this.form.submit()"> <?= e($stl['fr']) ?> / <?= e($stl['ar']) ?></label>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($hasComponents || $hasCurrency || $type==='isqat_haq' || $isNotice): ?><span style="margin:0 16px;color:#cbd5e1">|</span><?php endif; ?>
            <?php if ($hasComponents): ?>
            <strong>Composantes du salaire / مكوّنات الراتب:</strong>
            <label style="margin:0 12px;cursor:pointer"><input type="checkbox" name="inc_extra" value="1" <?= $incExtra?'checked':'' ?> onchange="this.form.submit()"> + Rémunération suppl. / + الأجر الإضافي (<?= formatLBP($extraW,false) ?>)</label>
            <label style="margin:0 12px;cursor:pointer"><input type="checkbox" name="inc_aide" value="1" <?= $incAide?'checked':'' ?> onchange="this.form.submit()"> + Prime et aide / + مكافأة ومساعدة (<?= formatLBP($aideW,false) ?>)</label>
            <label style="cursor:pointer"><input type="checkbox" name="inc_trans" value="1" <?= $incTrans?'checked':'' ?> onchange="this.form.submit()"> + Transport / + تعويض النقل (<?= formatLBP($transW,false) ?>)</label>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <?php endif; ?>
            <?php if ($hasCurrency): ?>
            <strong>Devise / العملة:</strong>
            <label style="margin:0 10px;cursor:pointer"><input type="radio" name="cur" value="lbp" <?= $cur==='lbp'?'checked':'' ?> onchange="this.form.submit()"> Livre (LBP) / ليرة (ل.ل)</label>
            <label style="margin:0 10px;cursor:pointer"><input type="radio" name="cur" value="usd" <?= $cur==='usd'?'checked':'' ?> onchange="this.form.submit()"> Dollar / دولار ($)</label>
            <label style="cursor:pointer"><input type="radio" name="cur" value="both" <?= $cur==='both'?'checked':'' ?> onchange="this.form.submit()"> Les deux / الاثنين (ل.ل + $)</label>
            <?php endif; ?>
            <?php if ($type === 'isqat_haq'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>Montant de l'indemnité calculée / مبلغ تعويض الصرف المحسوب:</strong>
            <input type="text" name="eos" value="<?= $eos>0 ? (int)$eos : '' ?>" placeholder="اكتب المبلغ" style="width:150px;padding:3px 6px" onchange="this.form.submit()">
            <div style="margin-top:6px">
                <strong>Situation / الحالة:</strong>
                <label style="margin:0 10px;cursor:pointer"><input type="radio" name="isq" value="istiqala" <?= $isqMode==='istiqala'?'checked':'' ?> onchange="this.form.submit()"> J'ai présenté ma démission / قدّمت استقالتي</label>
                <label style="cursor:pointer"><input type="radio" name="isq" value="sarf" <?= $isqMode==='sarf'?'checked':'' ?> onchange="this.form.submit()"> J'ai été licencié(e) / صار صرفي من الخدمة</label>
            </div>
            <?php endif; ?>
            <?php if ($isNotice): ?>
            <div style="margin-top:6px"><strong>Objet / motif de l'avertissement / الموضوع / سبب الإنذار:</strong>
            <input type="text" name="subj_txt" value="<?= e($subjectTxt) ?>" style="width:60%;min-width:300px;padding:3px 6px" onchange="this.form.submit()"></div>
            <?php endif; ?>
            <?php if ($type === 'riaaya'): ?>
            <div style="margin-top:6px"><strong>Organisme et n° d'enregistrement / الجهة ورقم التسجيل:</strong>
            <input type="text" name="assoc_txt" value="<?= e($assocTxt) ?>" style="width:70%;min-width:340px;padding:3px 6px" onchange="this.form.submit()"></div>
            <?php endif; ?>
            <?php if ($type === 'embassy'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>Montant mensuel (à saisir) / المبلغ الشهري (اكتبه):</strong>
            <input type="text" name="emb_amt" value="<?= $embAmt>0 ? (int)$embAmt : '' ?>" placeholder="المبلغ" style="width:140px;padding:3px 6px" onchange="this.form.submit()">
            <span style="color:#64748b">(Choisir la devise ci-dessus) / (اختر العملة دولار/ليرة من فوق)</span>
            <?php endif; ?>
            <?php if ($type === 'iqrar'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>Valeur de la subvention (USD) / قيمة المنحة (دولار أميركي):</strong>
            <input type="text" name="grant" value="<?= $grant>0 ? (int)$grant : '' ?>" placeholder="اكتب المبلغ" style="width:150px;padding:3px 6px" onchange="this.form.submit()">
            <?php if ($grant>0): ?><div style="margin-top:6px;color:#1e40af"><?= number_format($grant) ?> دولار أميركي — بالحروف: <strong><?= e(numToArabicWords($grant)) ?> دولار أميركي</strong></div><?php endif; ?>
            <?php endif; ?>
            <?php if ($hasComponents): ?>
            <div style="margin-top:6px;color:#1e40af">الراتب المعتمد بالإفادة: <strong><?= $moneyAr($salShown) ?></strong> (<?= $isEmploye ? 'الراتب الأساسي' : 'الأساس بعد التدرّج' ?> <?= $moneyAr((int)$basePlusEch) ?><?= $incExtra?' + الإضافي':'' ?><?= $incAide?' + المكافأة':'' ?><?= $incTrans?' + النقل':'' ?>)<?php if ($cur==='usd'): ?> — سعر الصرف <?= formatLBP((int)$fxRate,false) ?><?php endif; ?></div>
            <?php elseif ($type === 'aqd_taalim'): ?>
            <div style="margin-top:6px;color:#1e40af">أساس الراتب بالعقد: <strong><?= $moneyAr((int)$basePlusEch) ?></strong><?php if ($cur==='usd'): ?> — سعر الصرف <?= formatLBP((int)$fxRate,false) ?><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($type === 'aqd_taalim'): ?>
            <div style="margin-top:6px">
                <strong>Montant convenu / المبلغ المتفق عليه:</strong>
                L.L <input type="text" name="aqd_lbp" value="<?= $aqdLbp>0 ? (int)$aqdLbp : '' ?>" placeholder="بالليرة" style="width:140px;padding:3px 6px" onchange="this.form.submit()">
                &nbsp; $ <input type="text" name="aqd_usd" value="<?= $aqdUsd>0 ? (int)$aqdUsd : '' ?>" placeholder="بالدولار" style="width:110px;padding:3px 6px" onchange="this.form.submit()">
                <span style="color:#64748b">(عبّي عملة وحدها أو الاثنتين معاً — الفاضية ما بتظهر بالعقد)</span>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <?php
    // إفادة الضمان الرسمية «لمن يهمه الأمر» — بنفس نصّ نموذج المستخدم بالضبط.
    $attBase = (int)round($basePlusEch);
    $attSupp = ($incExtra ? $extraW : 0) + ($incAide ? $aideW : 0);
    // 🔴 «الأرقام تركب» (2026-07-30): خانة «+ تعويض النقل» كانت تُعرَض للإفادة والشاشة تقول
    // إنّ المبلغ يشمله، لكن نصّ الوثيقة يجمع الأساس والملحقات فقط — فيظهر المجموع أقلّ
    // بمقدار النقل (٩ ملايين بمثال حقيقي) في ورقةٍ موقّعة ومختومة. الآن النقل سطر مستقل
    // يظهر فقط إن اختاره المستخدم، والمجموع يساويه فعلاً.
    $attTrans = $incTrans ? (int)round($transW) : 0;
    $attTotal = $attBase + $attSupp + $attTrans;
    if ($type === 'cnss'):
    ?>
    <style media="print">/* هوامش الورقة بيد الإفادة لا بيد إعدادات المتصفح (هوامش 1 إنش كانت تكسر الصفحة)،
    وحشوة .page-content (16px فوق/32px تحت) كانت تدفع الإفادة فتنكسر آخر شلفة لصفحة ثانية */
    @page{size:A4;margin:<?= $lhOn ? '0' : '12mm' ?>}
    .page-content{padding:0 !important;margin:0 !important}</style>
    <style>/* خط الإفادات Arial بكل اللغات (بطلب المستخدم 2026-08-20) */ #ppExportArea{font-family:Arial,'Segoe UI',Tahoma,sans-serif}</style>
    <div id="ppExportArea" class="<?= $lhClass ?>" style="<?= $lhStyle ?>" dir="rtl"<?= $type === 'aqd_taalim' ? '' : ' data-fit1="1"' ?>>
        <div class="card-body" style="line-height:2.15;text-align:right;font-size:12pt;font-family:Arial,'Segoe UI',Tahoma,sans-serif;<?= $lhOn?'padding:0':'' ?>">
            <?php /* 🪪 ترويسة وورد بديلة لكل المدارس (2026-08-20): بلا خط + المدينة فقط — تصدير Word يكشفها ويشيل scr-head */ ?>
            <?php if ($showLogo): ?><div class="word-head" style="display:none"><?= $schoolHeadWord ?></div><?php endif; ?>
            <?php if ($showRecHead && $logoImg): ?><div class="scr-head" style="margin-bottom:8px"><?= $headBodyAr($logoImg) ?></div><?php endif; ?>
            <?php /* «p1: بدو يكونو على اليمين» (2026-08-20): كتلة الصندوق الوطني عاليمين لا عاليسار */ ?>
            <div style="text-align:right;font-weight:700;line-height:1.7;margin-bottom:6px">
                الصندوق الوطني<br>للضمان الاجتماعي<br>
                <span style="font-weight:400">مكتب ـــــــــــــ</span><br>
                <span style="font-weight:400">رقم الوارد : ــــــــ</span><br>
                <span style="font-weight:400">تاريــــــخ : ــــــــ</span>
            </div>
            <h2 style="text-align:center;margin:18px 0 26px;text-decoration:underline">إفـــادة لمن يهمه الأمر</h2>
            <p>تفيد مؤسسة : <strong><?= e($schoolNameAr) ?></strong></p>
            <p>المسجَّلة في الصندوق الوطني للضمان الاجتماعي تحت الرقم <strong><?= e($employerNssf) ?></strong></p>
            <p>أنّ المضمون <strong><?= e($nomAr) ?></strong> رقمه <strong><?= e($emp['nssf_number']) ?></strong> قد بدأ العمل لدينا بدوام كامل</p>
            <p>اعتباراً من تاريخ <strong><?= $hireFmt ?></strong> بصفة (<strong><?= e($fnFr['ar']) ?></strong>)</p>
            <p>ويتقاضى راتباً شهرياً :</p>
            <p style="margin-right:34px;text-align:right">- أساس راتب عملاً بالقانون : <strong><?= $moneyAr($attBase) ?></strong></p>
            <?php /* «مكان جملة ملحقات مدفوعة بدو يكون الأجر الإضافي» (بطلبه 2026-08-20) */ ?>
            <p style="margin-right:34px;text-align:right">- الأجر الإضافي : <strong><?= $moneyAr($attSupp) ?></strong></p>
            <?php if ($attTrans > 0): ?>
            <p style="margin-right:34px;text-align:right">- تعويض نقل : <strong><?= $moneyAr($attTrans) ?></strong></p>
            <?php endif; ?>
            <p>المجموع : <strong><?= $moneyAr($attTotal) ?></strong> فقط <?= e($moneyWords($attTotal)) ?> لا غير .</p>
            <?php if ($emp['status']==='actif'): ?><p>وهو مستمر في عمله حتى تاريخه .</p><?php endif; ?>
            <div style="display:flex;justify-content:space-between;margin-top:54px">
                <div>التاريخ : <?= $today ?></div>
                <div style="text-align:center"><strong>الخاتم والتوقيع</strong>
                    <div style="margin-top:44px;border-top:1px solid #333;width:200px"></div></div>
            </div>
        </div>
    </div>
    <?php elseif (in_array($type, ['salaire','tadris','embassy','riaaya','anhaa_khedme','anhaa_mail','talab_istiqala','afade_madrasiya','isqat_haq','baraa_zimma','iqrar','aqd_taalim','notice_school','notice_mail'])):
        // النماذج الرسمية الخمسة من ملف المستخدم «افادات.XLS» — نفس النص حرفياً، مرتّبة على A4.
        $blank = function ($w = 110) { return '<span style="display:inline-block;min-width:' . (int)$w . 'px;border-bottom:1px dotted #555">&nbsp;</span>'; };
        $salFig  = $moneyAr($salShown);       // الراتب (أساس + المكوّنات المختارة) بالعملة المختارة — للإفادة المدرسية وإسقاط الحق
        $salWrd  = $moneyWords($salShown);
        $baseFig = $moneyAr((int)$basePlusEch); // أساس الراتب وحده — لعقد التعليم
        $dob      = $emp['birth_date'] ? formatDate($emp['birth_date']) : $blank(110);
        $bplace   = trim((string)($emp['birth_place'] ?? '')) ?: $blank(120);
        $phone1   = trim((string)($emp['phone1'] ?? '')) ?: $blank(120);
        $chk = '<span style="display:inline-block;width:13px;height:13px;border:1px solid #333;vertical-align:middle;margin:0 3px"></span>';
        $box = function ($on) { return '<span style="display:inline-block;width:13px;height:13px;border:1px solid #333;vertical-align:middle;margin:0 3px;text-align:center;line-height:11px;font-weight:700">' . ($on ? '×' : '') . '</span>'; };
        $vb  = function ($val, $w = 120) use ($blank) { $val = trim((string)$val); return $val !== '' ? '<strong>' . htmlspecialchars($val, ENT_QUOTES) . '</strong>' : $blank($w); };
        // السنوات
        $effYear = (int)date('Y', strtotime($effDate));
        $eMo = (int)date('n', strtotime($effDate));
        $syStart = ($eMo >= 10) ? $effYear : $effYear - 1;
        $nextSY  = ($syStart + 1) . '-' . ($syStart + 2);
        $curSY   = $syStart . '-' . ($syStart + 1);
        // معلومات العقد من ملف الأستاذ
        $natAr = in_array(strtolower((string)($emp['nationality'] ?? '')), ['lebanese','lebanaise','libanaise','لبنانية','لبناني']) ? 'لبنانية' : (string)($emp['nationality'] ?? '');
        $regNo = trim((string)($emp['civil_registry_number'] ?? '')) . (($emp['civil_registry_place'] ?? '') ? ' / ' . $emp['civil_registry_place'] : '');
        $addr  = implode(' - ', array_values(array_unique(array_filter(array_map(function ($x) { return trim((string)$x); }, [$emp['ville'] ?? '', $emp['quartier'] ?? '', $emp['rue'] ?? '', $emp['immeuble'] ?? '', ($emp['etage'] ?? '') ? ('طابق ' . $emp['etage']) : '', $emp['district'] ?? '', $emp['gouvernorat'] ?? ''])))));
        $dipAr = ($emp['diploma'] ?? '') ? diplomaLabel($emp['diploma'], 'ar') : '';
        $subj  = trim((string)($emp['subjects_taught'] ?? ''));
        $niveau = trim((string)($emp['niveau_scolaire'] ?? ''));
        $hpw   = ($emp['hours_per_week'] ?? null) !== null && $emp['hours_per_week'] !== '' ? rtrim(rtrim((string)$emp['hours_per_week'], '0'), '.') : '';
        $social = (string)($emp['social_status'] ?? 'celibataire');
        $isMarried = (strpos($social, 'marie') === 0);
        $nKids = (int)($emp['number_of_children'] ?? 0);
        $spouseWorks = (int)($emp['spouse_works'] ?? 0);
        $isTit = ($emp['employee_type'] === 'enseignant_titulaire');
        $isContr = ($emp['employee_type'] === 'enseignant_contractuel');
        // إفادات نمط مكسيموس (راتب/تدريس/سفارة/رعاية): الأقسام من ملف الأستاذ + ترويسة اتصال أسفل
        $nivMapAr = ['maternelle'=>'الحضانة','primaire'=>'الابتدائي','intermediaire'=>'المتوسط','secondaire'=>'الثانوي'];
        $nivMapEn = ['maternelle'=>'Kindergarten','primaire'=>'Primary','intermediaire'=>'Intermediate','secondaire'=>'Secondary'];
        $nivKeys = array_values(array_filter(array_map('trim', explode(',', (string)($emp['niveau_scolaire'] ?? '')))));
        $nivAr = array_values(array_filter(array_map(function ($k) use ($nivMapAr) { return $nivMapAr[$k] ?? ''; }, $nivKeys)));
        $nivEn = array_values(array_filter(array_map(function ($k) use ($nivMapEn) { return $nivMapEn[$k] ?? ''; }, $nivKeys)));
        $levelsAr = count($nivAr) > 2 ? ('في الأقسام ' . implode(' و', $nivAr)) : (count($nivAr) == 2 ? ('في القسمين ' . $nivAr[0] . ' و' . $nivAr[1]) : (count($nivAr) == 1 ? ('في قسم ' . $nivAr[0]) : ''));
        $levelsEn = count($nivEn) >= 2 ? ('at both ' . implode(' and ', $nivEn) . ' levels') : (count($nivEn) == 1 ? ('at the ' . $nivEn[0] . ' level') : '');
        $usdSal = $fxRate > 0 ? (int)round($salShown / $fxRate) : 0;
        // سعر إفادة السفارة: المبلغ اليدوي بالعملة المختارة، وإلا المحسوب بالدولار
        $embRate = $embAmt > 0 ? ($cur === 'usd' ? '$' . number_format($embAmt) : number_format($embAmt) . ' L.L') : ($usdSal > 0 ? '$' . number_format($usdSal) : '');
        $contactBits = array_filter([trim((string)($school['address'] ?? '')), $schoolPhone ? ('هاتف: ' . $schoolPhone) : '', trim((string)($school['email'] ?? ''))]);
        $contactFooter = implode('  |  ', $contactBits);
        // ترويسة عربية: الشعار يمين + اسم المدرسة (عربي) + الهاتف، بشكل رسمي
        // عربي: الشعار فوق على اليمين، واسم المدرسة والهاتف تحته على اليمين (مثل نموذج باقي المدارس)
        // «بس حط المنطقة قلنا» + «بدون الخط الأسود» + «متل لوغو الراهبات» (2026-08-20):
        // ترويسة الشاشة = نفس كتابة نموذج «لوغو الراهبات» المتوسّطة تحت الشعار، بلا خط، بالمدينة فقط
        $schoolHead = '<div class="scr-head" style="margin-bottom:18px">' . $headBodyAr($logoImg) . '</div>';
        // أجنبي: الشعار فوق على اليسار، والاسم الفرنسي والعنوان (بالأجنبي) والهاتف تحته على اليسار
        // العنوان واسم المدير بالأجنبي: المُدخَل، وإلا ترجمة تلقائية من العربي (لا العربي نفسه)
        $schoolAddrFr = trim((string)($school['address_fr'] ?? '')) ?: ($schoolAddr !== '' ? arNameToFr($schoolAddr) : '');
        $directorFr   = ($sigNameFr !== '') ? $sigNameFr : ($director !== '' ? arNameToFr($director) : '');
        $cityFr = trim((string)(preg_split('/[-–,،]/u', (string)$schoolAddrFr)[0] ?? ''));
        // بالفرنسي (للسفارة): نفس نمط «لوغو الراهبات» متوسّطاً تحت الشعار، على يسار الصفحة
        $headBodyFr = function ($logoHtml) use ($schoolNameFr, $cityFr, $schoolPhone) {
            return '<div dir="ltr"><table style="width:100%;border-collapse:collapse"><tr><td style="border:none;padding:0;width:32%;text-align:center;line-height:1.8">'
                . ($logoHtml ? '<div style="margin-bottom:2px">' . $logoHtml . '</div>' : '')
                . '<strong style="font-size:16px">' . e($schoolNameFr) . '</strong>'
                . ($cityFr !== '' ? '<br><strong style="font-size:15px">' . e($cityFr) . '</strong>' : '')
                . ($schoolPhone ? '<br><span style="font-size:14px">Tel : <span dir="ltr">' . e($schoolPhone) . '</span></span>' : '')
                . '</td><td style="border:none;padding:0"></td></tr></table></div>';
        };
        $schoolHeadFr = '<div class="scr-head" style="margin-bottom:18px">' . $headBodyFr($logoImg) . '</div>';
        $schoolHeadWordFr = '<div style="margin-bottom:16px">' . $headBodyFr($logoImgWord) . '</div>';
    ?>
    <style media="print">/* هوامش الورقة بيد الإفادة لا بيد إعدادات المتصفح (هوامش 1 إنش كانت تكسر الصفحة)،
    وحشوة .page-content (16px فوق/32px تحت) كانت تدفع الإفادة فتنكسر آخر شلفة لصفحة ثانية */
    @page{size:A4;margin:<?= $lhOn ? '0' : '12mm' ?>}
    .page-content{padding:0 !important;margin:0 !important}</style>
    <style>/* خط الإفادات Arial بكل اللغات (بطلب المستخدم 2026-08-20) */ #ppExportArea{font-family:Arial,'Segoe UI',Tahoma,sans-serif}</style>
    <div id="ppExportArea" class="<?= $lhClass ?>" style="<?= $lhStyle ?>" dir="rtl"<?= $type === 'aqd_taalim' ? '' : ' data-fit1="1"' ?>>
      <div class="card-body" style="line-height:2.15;text-align:justify;font-size:12pt;font-family:Arial,'Segoe UI',Tahoma,sans-serif;<?= $lhOn?'padding:0':'' ?>">
      <?php /* 🪪 ترويسة وورد بديلة لكل المدارس (بطلب المستخدم 2026-08-20): بلا خط تحت الشعار + المدينة فقط —
              تصدير Word يكشفها ويشيل ترويسة الشاشة scr-head (وخلفية الترويسة أصلاً لا يعرضها وورد) */ ?>
      <?php if ($showLogo): ?><div class="word-head" style="display:none"><?= $type === 'embassy' ? $schoolHeadWordFr : $schoolHeadWord ?></div><?php endif; ?>

      <?php
        // ترويسة اتصال أسفل الصفحة (نمط مكسيموس) — تظهر مع الشعار
        // التذييل يظهر فقط مع ترويسة صورة (مكسيموس)؛ المخلصيات نموذجها بلا تذييل (ترويسة فقط)
        $footerHtml = '';
      ?>
      <?php if ($type === 'salaire'): ?>
        <?php // 🧾 «بدي إفادة الراتب بدون تابلو» (2026-08-20): تفصيل الراتب سطوراً لا جدولاً —
              // والأساس وحده مختاراً = جملة واحدة بالمبلغ بلا تفصيل
        $salParts = [];
        if ($incExtra && $extraW > 0) $salParts[] = ['الأجر الإضافي', $extraW];
        if ($incAide  && $aideW  > 0) $salParts[] = ['مكافأة ومساعدة', $aideW];
        if ($incTrans && $transW > 0) $salParts[] = ['تعويض النقل', $transW];
        ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <div style="display:flex;justify-content:space-between;margin-bottom:10px">
            <span>الرقم : <span style="display:inline-block;min-width:90px;border-bottom:1px dotted #475569">&nbsp;</span></span>
            <span>التاريخ : <?= $today ?></span>
        </div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إفادة راتب</h2>
        <p>تفيد إدارة <strong><?= e($schoolNameAr) ?></strong> بأنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> <?php if ($isEmploye): ?>يعمل(تعمل) لديها بوظيفة <strong><?= e($fnFr['ar']) ?></strong> منذ تاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong><?php else: ?>يعمل(تعمل) لديها بوظيفة مدرّس(ة) لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> منذ تاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong><?php endif; ?> ولا يزال(تزال) حتى تاريخه ، ويتقاضى راتباً شهرياً<?= $salParts ? ' وفق التفصيل الآتي :' : ' قدره <strong>' . $moneyAr($salShown) . '</strong> .' ?></p>
        <?php if ($salParts): ?>
        <?php /* كل مكوّن مختار سطر مستقل واضح (الإضافي/المكافأة/النقل) — لا يُدمج بسطر «بدلات» عام */ ?>
        <p style="margin-right:34px;text-align:right">- الراتب الأساسي<?= $isEmploye ? '' : ' (بعد التدرّج)' ?> : <strong><?= $moneyAr((int)$basePlusEch) ?></strong></p>
        <?php foreach ($salParts as $sp): ?>
        <p style="margin-right:34px;text-align:right">- <?= e($sp[0]) ?> : <strong><?= $moneyAr((int)$sp[1]) ?></strong></p>
        <?php endforeach; ?>
        <p style="margin-right:34px;text-align:right">- الإجمالي : <strong><?= $moneyAr($salShown) ?></strong></p>
        <?php endif; ?>
        <p>فقط <strong><?= e($moneyWords($salShown)) ?> لا غير</strong> .</p>
        <?php /* صيغة «لمن يلزم» شِيلت من كل الإفادات (بطلبه 2026-08-20) */ ?>
        <p>وقد أُعطيت هذه الإفادة بناءً على طلبه(ا) .</p>
        <div style="width:260px;margin:42px auto 0 0;text-align:center"><strong><?= e($sigTitleAr) ?> — التوقيع والختم</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'tadris'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <div style="display:flex;justify-content:space-between;margin-bottom:10px">
            <span>الرقم : <span style="display:inline-block;min-width:90px;border-bottom:1px dotted #475569">&nbsp;</span></span>
            <span>التاريخ : <?= $today ?></span>
        </div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $isEmploye ? 'إفادة عمل' : 'إفادة عمل وتدريس' ?></h2>
        <p>تفيد إدارة <strong><?= e($schoolNameAr) ?></strong> بأنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> <?php if ($isEmploye): ?>يعمل(تعمل) لديها بوظيفة <strong><?= e($fnFr['ar']) ?></strong> منذ تاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong><?php else: ?>يعمل(تعمل) لديها بوظيفة مدرّس(ة) لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> منذ تاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong><?php endif; ?> ولا يزال(تزال) حتى تاريخه ، وهو(هي) على حسن سلوك والتزام في أداء عمله(ا) .</p>
        <?php /* صيغة «لمن يلزم» وجملة عدم المسؤولية شِيلتا من كل الإفادات (بطلبه 2026-08-20) */ ?>
        <p>وقد أُعطيت هذه الإفادة بناءً على طلبه(ا) .</p>
        <div style="width:260px;margin:42px auto 0 0;text-align:center"><strong>المدير — التوقيع والختم</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'riaaya'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <div style="text-align:left;margin-bottom:10px"><?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إلى من يهمه الأمر</h2>
        <p>تفيد إدارة <strong><?= e($schoolNameAr) ?></strong> <?= e($assocTxt) ?> ،</p>
        <p>أنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> <?php if ($isEmploye): ?>يعمل(تعمل) <strong><?= e($fnFr['ar']) ?></strong> في مدرستنا<?php else: ?>هو(ـي) معلّم(ة) لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> في مدرستنا<?php endif; ?> .</p>
        <p>وللبيان أُعطيت هذه الإفادة .</p>
        <div style="width:260px;margin:42px auto 0 0;text-align:center"><strong>الإدارة</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'embassy'): ?>
        <?php if ($showRecHead): ?><?= $schoolHeadFr ?><?php endif; ?>
        <div dir="ltr" style="text-align:left">
          <div style="text-align:right;margin-bottom:10px"><?= date('d/m/Y') ?></div>
          <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إفادة</h2>
          <p>To whom it may concern,</p>
          <p>This is to certify that <strong><?= e(trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['father_name_fr'] ? $emp['father_name_fr'] . ' ' : '') . ($emp['last_name_fr'] ?? ''))) ?></strong> <?php if ($isEmploye): ?>has been employed as <strong><?= e($fnFr['en']) ?></strong> at <strong><?= e($schoolNameFr) ?></strong>, at a rate of <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> per month</strong><?php else: ?>has been a teacher at <strong><?= e($schoolNameFr) ?></strong>. He/She has been, and continues to be, teaching <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsEn ?>, at a rate of <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> per month</strong><?php endif; ?>. We also confirm that he/she is currently engaged at our school for the academic year <strong><?= $nextSY ?></strong>.</p>
          <p style="text-align:center">This certificate is given upon his/her request.</p>
          <div style="width:260px;margin:42px 0 0 auto;text-align:center"><strong>Director</strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?></div>
        </div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'anhaa_khedme'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">كتاب إنهاء خدمات</h2>
        <p>حضرة الأستاذ(ة) : <strong><?= e($nomAr) ?></strong></p>
        <p>استناداً إلى القوانين المرعية الإجراء وخاصةً قانون الهيئة التعليمية للمدارس الخاصة ، وعملاً بالمادة 29 وتعديلاتها من القانون المذكور ،</p>
        <p>نُعلمكم قبل الخامس من شهر تموز <strong><?= $effYear ?></strong> قرار اضطرار مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>إنهاء خدماتكم التعليمية لديها عن العام الدراسي <strong><?= $curSY ?></strong> وما يليه .</p>
        <p>وإذ تأسف إدارة المدرسة لإبلاغكم قرارها تشكر لكم تعاونكم المخلص معها داعيةً لكم بالتوفيق راجيةً قبول احترامها .</p>
        <div style="display:flex;justify-content:space-between;margin-top:32px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:36px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <p style="margin-top:12px">مع جميع التحفظات</p>
        <div style="margin-top:24px;border-top:1px dashed #999;padding-top:12px">
          <p>أنا الموقّع(ة) أدناه : <strong><?= e($nomAr) ?></strong></p>
          <p>لقد استلمت من رئيسة المدرسة كتاب إنهاء خدماتي .</p>
          <div style="display:flex;justify-content:space-between;margin-top:24px">
            <div>في : <?= $blank(90) ?></div>
            <div style="text-align:center"><strong>الاسم والتوقيع</strong><div style="margin-top:32px;border-top:1px solid #333;width:200px"></div></div>
          </div>
        </div>

      <?php elseif ($type === 'anhaa_mail'): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:10px">
          <div style="text-align:left;font-weight:700;font-size:13px;line-height:1.7">بطاقة مكشوفة<br>مع إشعار بالاستلام<br>مُرسَلة</div>
          <div style="text-align:right">
            <?php if ($showLogo && $logoImg): ?><?= $logoImg ?><br><?php endif; ?>
            <strong style="font-size:15px"><?= e($schoolNameAr) ?></strong>
            <?= $schoolAddr ? '<br><small>' . e($schoolAddr) . '</small>' : '' ?>
            <?= $schoolPhone ? '<br><small>هاتف : <span dir="ltr">' . e($schoolPhone) . '</span></small>' : '' ?>
          </div>
        </div>
        <div style="border:1px solid #bbb;padding:8px 12px;line-height:1.95;margin-bottom:14px">
          <p style="margin:2px 0">من : <strong><?= e($schoolNameAr) ?></strong></p>
          <p style="margin:2px 0">إلى الأستاذ(ة) : <strong><?= e($nomAr) ?></strong></p>
          <p style="margin:2px 0">العنوان : <?= $addr !== '' ? '<strong>' . e($addr) . '</strong>' : $blank(320) ?></p>
          <p style="margin:2px 0">الهاتف : <?= trim((string)($emp['phone1'] ?? '')) !== '' ? '<strong>' . e($emp['phone1']) . '</strong>' : $blank(150) ?></p>
        </div>
        <h2 style="text-align:center;margin:4px 0 18px;text-decoration:underline">كتاب إنهاء خدمات</h2>
        <p>استناداً إلى القوانين المرعية الإجراء وخاصةً قانون الهيئة التعليمية للمدارس الخاصة ، وعملاً بالمادة 29 وتعديلاتها من القانون المذكور ،</p>
        <p>نُعلمكم قبل الخامس من شهر تموز <strong><?= $effYear ?></strong> قرار اضطرار مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>إنهاء خدماتكم التعليمية لديها عن العام الدراسي <strong><?= $curSY ?></strong> وما يليه .</p>
        <p>وإذ تأسف إدارة المدرسة لإبلاغكم قرارها تشكر لكم تعاونكم المخلص معها داعيةً لكم بالتوفيق راجيةً قبول احترامها .</p>
        <div style="display:flex;justify-content:space-between;margin-top:32px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:36px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <p style="margin-top:12px;text-align:center">مع جميع التحفظات</p>

      <?php elseif ($type === 'talab_istiqala'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 24px;text-decoration:underline">طلب استقالة</h2>
        <p>حضرة مديرة مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>أنا الموقّع أدناه : <strong><?= e($nomAr) ?></strong></p>
        <p>أُبلغكم استقالتي من الخدمة في مدرسة <strong><?= e($schoolNameAr) ?></strong> اعتباراً من السنة المدرسية المقبلة <strong><?= $nextSY ?></strong> ، وذلك لأسبابٍ شخصية .</p>
        <p>وتفضّلوا بقبول الاحترام .</p>
        <div style="display:flex;justify-content:space-between;margin-top:46px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>التوقيع</strong><div style="margin-top:44px;border-top:1px solid #333;width:200px"></div></div>
        </div>

      <?php elseif ($type === 'afade_madrasiya'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إفـادة مدرسية</h2>
        <p>أنا الموقّعة أدناه : <strong><?= $director ? e($director) : $blank(180) ?></strong></p>
        <p>رئيسة أو مديرة مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>أُثبت أنّ السيّد (ة) : <strong><?= e($nomAr) ?></strong> &nbsp; حامل بطاقة الهوية رقم <?= $blank(150) ?></p>
        <p>قد باشر التدريس في مدرستنا بتاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(120) ?></strong></p>
        <p>وانقطع عن العمل بتاريخ <strong><?= $effFmt ?></strong></p>
        <p>للأسباب الآتية : <?= $blank(380) ?></p>
        <?php /* 🧾 تفصيل الراتب بالإفادة المدرسية (بطلب المستخدم 2026-08-19): أساس الراتب لحاله
               والأجر الإضافي لحاله والمكافأة لحالها ثم المجموع — كلٌّ حسب خيارات «الراتب يشمل»
               (اختيارية)؛ وإن كان الأساس وحده مختاراً يبقى سطراً واحداً كما كان. */
        $attParts = [];
        if ($incExtra && $extraW > 0) $attParts[] = ['الأجر الإضافي', $extraW];
        if ($incAide  && $aideW  > 0) $attParts[] = ['المكافأة والمساعدة', $aideW];
        if ($incTrans && $transW > 0) $attParts[] = ['تعويض النقل', $transW];
        ?>
        <?php if ($attParts): ?>
        <p>وكان راتبه الشهري ( دون التعويض العائلي ) في الشهر الأخير من الخدمة الفعلية مؤلّفاً ممّا يلي :</p>
        <p style="margin-right:34px;text-align:right">- أساس الراتب : <strong><?= $moneyAr((int)$basePlusEch) ?></strong></p>
        <?php foreach ($attParts as $ap): ?>
        <p style="margin-right:34px;text-align:right">- <?= e($ap[0]) ?> : <strong><?= $moneyAr((int)$ap[1]) ?></strong></p>
        <?php endforeach; ?>
        <p style="margin-right:34px;text-align:right">- المجموع : <strong><?= $salFig ?></strong></p>
        <p>فقط <strong><?= e($salWrd) ?> لا غير .</strong></p>
        <?php else: ?>
        <p>وكان راتبه الشهري ( دون التعويض العائلي ) في الشهر الأخير من الخدمة الفعلية <strong><?= $salFig ?></strong></p>
        <p>فقط <strong><?= e($salWrd) ?> لا غير .</strong></p>
        <?php endif; ?>
        <p>وبياناً للواقع ، وبالاستناد إلى قيود سجلات المدرسة ، أُعطيت هذه الإفادة .</p>
        <div style="display:flex;justify-content:space-between;margin-top:38px">
          <div>تحريراً في <?= $effFmt ?></div>
          <div style="text-align:center"><strong>توقيع رئيس أو مدير المدرسة</strong><div style="margin-top:10px">خاتم المدرسة</div>
            <div style="margin-top:28px;border-top:1px solid #333;width:220px"></div></div>
        </div>
        <p style="margin-top:22px;font-size:12pt;color:#444">ملاحظة : تُرسل هذه الإفادة إلى إدارة صندوق تعويضات أفراد الهيئة التعليمية في المدارس الخاصة .</p>
        <p style="text-align:center;font-size:12pt;color:#444">وزارة التربية الوطنية والشباب والرياضة - بيروت</p>
      <?php elseif ($type === 'isqat_haq'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إفـادة إسقاط حـق</h2>
        <p>أنا الموقّع أدناه <strong><?= e($nomAr) ?></strong></p>
        <p>حسب تذكرة هويتي ، رقم السجل <?= $blank(150) ?> &nbsp; أُصرّح بما يلي :</p>
        <p><strong>1)</strong> عملت لدى مدرسة : <strong><?= e($schoolNameAr) ?></strong> بصفة : <strong><?= e($fnFr['ar']) ?></strong> منذ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong> ، وأصبح راتبي بتاريخ هذا الإسقاط بالغاً ( بالأرقام ) <strong><?= $salFig ?></strong> ، بالحروف <strong><?= e($salWrd) ?> لا غير .</strong></p>
        <p><strong>2)</strong> قبضت من مدرسة : <strong><?= e($schoolNameAr) ?></strong> رواتبي كاملةً مع لواحقها طيلة مدة عملي لديها ، كما تناولت جميع الحقوق التي يخوّلني إياها القانون طيلة المدة المذكورة بما في ذلك بدل الساعات الإضافية والفرص السنوية الخ .....</p>
        <p><strong>3)</strong> بتاريخ <strong><?= $effFmt ?></strong> &nbsp; <?= $box($isqMode==='istiqala') ?> قدّمت استقالتي &nbsp;&nbsp; <?= $box($isqMode==='sarf') ?> صار صرفي من الخدمة .</p>
        <p>وبنتيجة ذلك وبعد المحاسبة قبضت من مدرسة <strong><?= e($schoolNameAr) ?></strong> مبلغ ( بالأرقام ) <strong><?= $eos>0 ? $freeNum($eos) : $blank(140) ?></strong> &nbsp; (بالحروف) <strong><?= $eos>0 ? 'فقط '.e($freeWords($eos)).' لا غير' : $blank(240) ?></strong></p>
        <p>وهذا المبلغ يشكّل تعويض صرفي من الخدمة .</p>
        <p>بناءً عليه ، أُقرّ وأعترف بأنّ مدرسة <strong><?= e($schoolNameAr) ?></strong> دفعت لي جميع المبالغ المترتبة لي بصفتي مستخدَماً لديها ولا سيّما تعويض الصرف من الخدمة وبدل مدة الإنذار ، وأنني أُسقط عن المدرسة المذكورة كل حقٍّ أو دعوى أو مطلب يعود لي بموجب قانون العمل وتعديلاته وسائر القوانين والأنظمة المعمول بها ، وأُبرئ ذمة المدرسة من هذا القبيل إبراءً تاماً شاملاً مجمل علاقة الاستخدام التي كانت قائمةً فيما بيننا .</p>
        <div style="display:flex;justify-content:space-between;margin-top:38px">
          <div>للبيان حُرّر في <?= $effFmt ?></div>
          <div style="text-align:center"><strong>بيد الموقّع صالح لأجل إسقاط الحق</strong><div style="margin-top:40px;border-top:1px solid #333;width:220px">التوقيع</div></div>
        </div>

      <?php elseif ($type === 'baraa_zimma'):
        $bzMonth = monthName($eMo, 'ar'); ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إقـرار وإبـراء ذمّـة وإسقـاط حـق</h2>
        <p>أنا الموقّع أدناه : <strong><?= e($nomAr) ?></strong></p>
        <p>أُصرّح وأنا بكامل الأهلية القانونية بما يلي :</p>
        <p>إنني قد عملت لدى مدرسة : <strong><?= e($schoolNameAr) ?></strong> بصفة <strong><?= e($fnFr['ar']) ?></strong> ، وأُقرّ وأعترف بأنني قد قبضت كامل مستحقاتي المالية من المدرسة المذكورة أعلاه ، بما في ذلك الرواتب الشهرية ولواحقها ، والتعويضات لا سيّما منها المساعدات والأجور الإضافية تعويضاً عن انهيار العملة اللبنانية ، والحقوق ، وجميع المبالغ التي كانت مترتبة لي عن فترة عملي لديها ، ولا يُعتبر ذلك أبداً كسلفة على حساب التعويض بل تعويضاً كاملاً لا رجوع عنه بهذا الخصوص ، وذلك حتى نهاية شهر <strong><?= e($bzMonth) ?></strong> من العام <strong><?= $effYear ?></strong> .</p>
        <p>وبناءً عليه ، فإنني أُبرئ ذمّة المدرسة المذكورة ، وإدارتها ، وجميع مالكيها ، ومديريها ، من أي حق أو مطلب أو دعوى كانت أو قد تكون لي تجاههم ، إبراءً تامًّا شاملاً لا رجوع فيه ، كما أتعهّد بتحمّل كامل المسؤولية القانونية والمالية والجزائية في حال صدرت عني أي مطالبة لاحقاً خلافاً لهذا الإقرار .</p>
        <p>كما أُسقط إسقاطاً نهائياً لا رجعة فيه ، وبصورة شاملة ، أي حق أو دعوى أو مطالبة قد تكون لي تجاه المدرسة المذكورة ، أو إدارتها ، أو أي من مالكيها أو مديريها ، بموجب أي قانون أو نظام معمول به ، وأُبرئ ذممهم من أي علاقة استخدام سابقة كانت قائمة بيننا ، إبراءً تامًّا غير قابل للنقض أو الرجوع .</p>
        <p>وقد حُرّر هذا الإقرار والإبراء طوعاً وبكامل إرادتي دون ضغط أو إكراه .</p>
        <div style="display:flex;justify-content:space-between;margin-top:40px">
          <div>حرّر في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>الاسم والتوقيع</strong><br><?= e($nomAr) ?><div style="margin-top:34px;border-top:1px solid #333;width:220px"></div></div>
        </div>

      <?php elseif ($type === 'iqrar'):
        $iqEndYear = $syStart + 2; ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إقــرار</h2>
        <p>أنا الموقّع أدناه ،</p>
        <p>الاسم الكامل : <strong><?= e($nomAr) ?></strong></p>
        <p>تعاقدت مع مدرسة : <strong><?= e($schoolNameAr) ?></strong> للعام الدراسي : <strong><?= $nextSY ?></strong> بصفة <strong><?= e($fnFr['ar']) ?></strong> .</p>
        <p>أقرّ بأنني قد أُبلغت من قبل إدارة المدرسة أنه ، وبسبب الظروف الاقتصادية الضاغطة ، سوف أتقاضى خلال السنة الدراسية <strong><?= $nextSY ?></strong> منحاً مالية بقيمة <?= $grant>0 ? '<strong>'.number_format($grant).'</strong>' : $blank(110) ?> دولار أميركي ( <?= $grant>0 ? '<strong>'.e(numToArabicWords($grant)).'</strong>' : $blank(150) ?> دولار أميركي ) ، أو يتم تحديد قيمتها وأوقات وطريقة دفعها من قبل إدارة المدرسة بصورة منفردة .</p>
        <p>وعليه ، أقرّ وأعترف بأنّ المنح المالية التي سوف أتقاضاها خلال السنة الدراسية <strong><?= $nextSY ?></strong> تُعتبر بصورة استثنائية منحاً خاصة ، وتُعدّ مساعدة مالية عرضية وظرفية تُدفع بشكل استثنائي وفقاً لتقدير إدارة المدرسة المنفرد تبعاً للظروف الاقتصادية والمعيشية الصعبة والمستجدّة .</p>
        <p>كما أقرّ وأعترف بأن هذه المنح المالية لا تُعدّ جزءاً من عناصر الراتب الشهري المدفوع والمصرّح عنه لكافة الجهات المختصة ، ولا يمكن التذرّع في هذا السياق بمبدأ الثبات والتكرار لأي سبب كان لاعتبارها عنصراً من عناصر الراتب ، وبالتالي لا تترتّب عنها أية حقوق مالية أو تعويضية أو تعاقدية تجاه المدرسة .</p>
        <p>وبناءً عليه ، يحق لإدارة المدرسة وبإرادتها المنفردة التوقف عن دفع هذه المنح أو تعديل قيمتها ، وفي أي وقت تراه مناسباً ، دون أن تترتّب عليها أية مسؤولية قانونية أو مالية نتيجة هذا القرار .</p>
        <p>كذلك ، أقرّ وأؤكّد بموجب هذا الإقرار التزامي بعدم ترك التعليم في :</p>
        <p>مدرسة <strong><?= e($schoolNameAr) ?></strong> خلال العام الدراسي <strong><?= $nextSY ?></strong> .</p>
        <p>وذلك اعتباراً من تاريخ هذا الإقرار ولغاية 30 حزيران <strong><?= $iqEndYear ?></strong> ، وأدرك أن إخلالي بهذا الالتزام قد يحمّلني عطلاً وضرراً مالياً منوّه عنهما في المادة 30 من قانون الهيئة التعليمية تاريخ 1956/6/15 ( لا يحق لأي فرد من أفراد الهيئة التعليمية أن يترك العمل خلال السنة الدراسية وإلّا ترتّب عليه عطل وضرر يوازي ضعف رواتبه وملحقاتها عن المدة الباقية من السنة الدراسية ) .</p>
        <p>لذلك ، وبكامل أهليتي القانونية ، أوقّع هذا الإقرار ، متعهّداً بالالتزام التام والكامل بمضمونه ، ومتحمّلاً بذلك أية مسؤولية مالية أو قانونية أو جزائية أو مدنية في حال مخالفتي لما ورد فيه .</p>
        <p>وللبيان ، حرّرت ووقّعت هذا الإقرار غير القابل للرجوع عنه ، وعلى كامل مسؤوليتي القانونية .</p>
        <div style="display:flex;justify-content:space-between;margin-top:40px">
          <div>حرّر في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>الاسم والتوقيع</strong><br><?= e($nomAr) ?><div style="margin-top:34px;border-top:1px solid #333;width:220px"></div></div>
        </div>

      <?php elseif ($type === 'aqd_taalim'):
        // تعبئة تلقائية للمكوّنات المالية من آخر راتب محسوب (كلّها موجودة بملف الأستاذ)
        // الأجر الإضافي والمكافأة يظهران حسب خيار «مكوّنات الراتب» أعلى الصفحة ($incExtra/$incAide).
        $cExtra  = $incExtra ? $extraW : 0;
        $cAide   = $incAide  ? $aideW  : 0;
        $cTrans  = $sal ? (int)$sal['transport_lbp'] : 0;
        $cFamily = $sal ? (int)$sal['family_allowance_lbp'] : 0;
        $cTotal  = (int)round($basePlusEch) + $cExtra + $cAide + $cTrans + $cFamily;
        // قيمة تعويض النقل اليومي من ملف الأستاذ (بعملته الخاصة كما أُدخِلت)
        $cDailyTrans = (float)($emp['transport_daily_amount'] ?? 0);
        $cDailyStr = $cDailyTrans > 0
            ? ((($emp['transport_daily_currency'] ?? 'LBP') === 'USD')
                ? ('$' . rtrim(rtrim(number_format($cDailyTrans, 2), '0'), '.'))
                : (formatLBP($cDailyTrans, false) . ' ل.ل'))
            : '';
      ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:4px 0 18px;text-decoration:underline">عقــد تعليــم</h2>
        <p>بين مدرسة : <strong><?= e($schoolNameAr) ?></strong> &nbsp; الممثَّلة بشخص: <?= $director ? '<strong>'.e($director).'</strong>' : $blank(150) ?> &nbsp; ( <strong>فريق أول</strong> )</p>
        <p>والسيّد / السيّدة / الآنسة : <strong><?= e($nomAr) ?></strong> &nbsp; من الجنسية: <?= $vb($natAr, 90) ?> &nbsp; ( <strong>فريق ثانٍ</strong> )</p>
        <p>المولود(ة) بتاريخ : <strong><?= $dob ?></strong> &nbsp; في : <strong><?= $bplace ?></strong> &nbsp; محل الإقامة: <?= $vb($emp['ville'] ?? '', 110) ?> &nbsp; رقم السجل: <?= $vb($regNo, 90) ?></p>
        <p>والمقيم على العنوان التالي : &nbsp; شتاءً : <?= $vb($addr, 170) ?> هاتف <?= $phone1 ?> &nbsp;&nbsp; صيفاً : <?= $blank(120) ?> هاتف <?= $vb($emp['phone2'] ?? '', 90) ?></p>
        <p>بتاريخ : <strong><?= $effFmt ?></strong> تمّ الاتفاق بين الفريقين المحدَّدَين أعلاه على ما يلي :</p>

        <p style="margin-top:10px"><strong>المادة الأولى :</strong> صرّح الفريق الثاني :</p>
        <p><strong>1/1 ـ</strong> بأنه يحمل الشهادات الرسميّة التالية :</p>
        <p style="margin-right:26px">1 ـ <?= $vb($dipAr, 150) ?> الصادرة عن : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p style="margin-right:26px">2 ـ <?= $blank(150) ?> الصادرة عن : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p><strong>2/1 ـ</strong> وأنه تابع الدورات التدريبيّة والتأهيليّة التالية :</p>
        <p style="margin-right:26px">1 ـ <?= $blank(150) ?> في : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p style="margin-right:26px">2 ـ <?= $blank(150) ?> في : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p><strong>3/1 ـ</strong> وأنه مارس التعليم في :</p>
        <p style="margin-right:26px">1 ـ مدرسة : <?= $blank(120) ?> من <?= $blank(70) ?> إلى <?= $blank(70) ?> في الصفوف <?= $blank(90) ?> مادة <?= $blank(90) ?></p>
        <p style="margin-right:26px">2 ـ مدرسة : <?= $blank(120) ?> من <?= $blank(70) ?> إلى <?= $blank(70) ?> في الصفوف <?= $blank(90) ?> مادة <?= $blank(90) ?></p>
        <p><strong>4/1 ـ</strong> وأنه يمارس التعليم حالياً في :</p>
        <p style="margin-right:26px">1 ـ مدرسة <strong><?= e($schoolNameAr) ?></strong> بصفة <?= $box($isContr) ?> متعاقد <?= $box($isTit) ?> داخل في الملاك &nbsp; عدد الساعات <?= $vb($hpw, 60) ?></p>
        <p style="margin-right:26px">2 ـ مدرسة <?= $blank(120) ?> بصفة <?= $chk ?> متعاقد <?= $chk ?> داخل في الملاك &nbsp; عدد الساعات <?= $blank(60) ?></p>
        <p><strong>5/1 ـ</strong> وأنه يتمتع بالشروط العامة المؤهِّلة لممارسة التعليم ( والمحددة بالمرسوم الاشتراعي 59/112 ، مدنية ، صحية ..... )</p>
        <p><strong>6/1 ـ</strong> وأنه على استعداد لمتابعة الدورات التأهيلية التي تعدّها المدرسة وتحدّد موضوعها ومدتها ومكانها وزمانها .</p>
        <p><strong>7/1 ـ</strong> وأنه : <?= $box(!$isMarried) ?> عازب <?= $box($isMarried) ?> متأهل <?= $box(false) ?> أرمل <?= $box(false) ?> مطلّق &nbsp; وأنّ عدد الأولاد الذين هم على عاتقه هو : <?= $vb((string)$nKids, 60) ?></p>
        <p><strong>8/1 ـ</strong> وأنّ الزوج(ة) <?= $box(!$spouseWorks) ?> لا يقوم <?= $box((bool)$spouseWorks) ?> يقوم بعمل مأجور ( اسم المؤسسة التي يعمل فيها ): <?= $blank(150) ?></p>
        <p><strong>9/1 ـ</strong> وأنه على اطّلاع وافٍ بالمناهج الرسميّة وأهدافها .</p>
        <p><strong>10/1 ـ</strong> وأنه على اطّلاع وافٍ بأنّ المدرسة هي مدرسة كاثوليكيّة ملتزمة بتوجيهات الكنيسة وتعاليمها الهادفة إلى بناء الطالب روحياً وإنسانياً وعلمياً ووطنياً ، وهي تسعى إلى ذلك بالتعاون مع الأهل والهيئة التعليميّة وفقاً للنظام الخاص الذي تضعه المدرسة ويحدّد العلاقة التربوية والتعليمية والإداريّة بين جميع المعنيين بالمدرسة .</p>
        <p><strong>11/1 ـ</strong> وأنه يتعهّد بإيداع الفريق الأول المستندات المثبتة لتصريحه في مهلةٍ أقصاها <?= $blank(120) ?></p>

        <p style="margin-top:10px"><strong>المادة الثانية :</strong> تعاقد الفريق الأول مع الفريق الثاني للتعليم خلال السنة الدراسية : <strong><?= $nextSY ?></strong></p>
        <p style="margin-right:26px">ـ بصفة : <?= $chk ?> حاضنة أطفال <?= $chk ?> مدرّس <?= $chk ?> معلّم <?= $chk ?> أستاذ تعليم ثانوي <?= $box(false) ?> متمرّن <?= $box($isContr) ?> متعاقد</p>
        <p style="margin-right:26px">ـ عدد ساعات التعليم الفعلي في الأسبوع : <?= $vb($hpw, 70) ?> &nbsp; المرحلة: <?= $vb($niveau, 110) ?> &nbsp; المادة: <?= $vb($subj, 110) ?></p>
        <p style="margin-right:26px">ـ عدد ساعات التناقص المخصصة للنشاطات اللاصفيّة : <?= $blank(70) ?></p>

        <p style="margin-top:10px"><strong>المادة الثالثة :</strong> يدفع الفريق الأول شهرياً للفريق الثاني لقاء المهمات التعليميّة الموكولة إليه ما يلي :</p>
        <p style="margin-right:26px"><strong>1/3 ـ</strong> أساس الراتب: <strong><?= (int)round($basePlusEch) > 0 ? $baseFig : $blank(120) ?></strong> &nbsp; ( الأجر القانوني )</p>
        <p style="margin-right:26px"><strong>2/3 ـ</strong> بدل مالي ( قانون 99/148 ) : <?= $blank(120) ?></p>
        <p style="margin-right:26px"><strong>3/3 ـ</strong> ساعات إضافية : <?= $blank(120) ?></p>
        <p style="margin-right:26px"><strong>4/3 ـ</strong> بدل إضافي بمثابة مكافأة : <?= $cAide > 0 ? '<strong>'.$moneyAr($cAide).'</strong>' : $blank(120) ?></p>
        <p style="margin-right:26px"><strong>5/3 ـ</strong> تعويض نقل مؤقت : <?php
            $t53 = [];
            if ($cDailyStr !== '') $t53[] = '<strong>' . $cDailyStr . '</strong> يومياً';
            if ($cTrans > 0) $t53[] = '<strong>' . $moneyAr($cTrans) . '</strong> شهرياً';
            echo $t53 ? implode(' &nbsp;،&nbsp; ', $t53) : $blank(120);
        ?></p>
        <p style="margin-right:26px"><strong>6/3 ـ</strong> تعويض عائلي : <?= $cFamily > 0 ? '<strong>'.$moneyAr($cFamily).'</strong>' : $blank(120) ?> ( في حال توجّبه )</p>
        <p style="margin-right:26px"><strong>المجموع :</strong> <strong><?= $moneyAr($cTotal) ?></strong> فقط : <strong><?= e($moneyWords($cTotal)) ?></strong></p>
        <?php // المبلغ المتفق عليه (يدوي من الخانات فوق): عملة وحدها أو الاثنتان — وإن لم يُعبَّأ شيء يظهر فراغ منقّط ليُكتب بخط اليد ?>
        <p style="margin-right:26px"><strong>المبلغ المتفق عليه :</strong> <?php
            $agreed = [];
            if ($aqdLbp > 0) $agreed[] = '<strong>' . number_format($aqdLbp) . ' ل.ل</strong> فقط ' . e(numToArabicWords($aqdLbp)) . ' ليرة لبنانية لا غير';
            if ($aqdUsd > 0) $agreed[] = '<strong>$' . number_format($aqdUsd) . '</strong> فقط ' . e(numToArabicWords($aqdUsd)) . ' دولار أميركي لا غير';
            echo $agreed ? implode(' &nbsp;و&nbsp; ', $agreed) : $blank(240);
        ?></p>
        <p>تُحسم من مستحقات الفريق الثاني المبالغ الشهرية المترتبة قانوناً لصندوق التعويضات ، والصندوق الوطني للضمان الاجتماعي ولوزارة المال وبدل الطوابع ، ويدفعها الفريق الأول إلى المراجع المختصة على مسؤوليته .</p>

        <p style="margin-top:10px"><strong>شـروط خصوصيـة</strong></p>
        <p><strong>1 ـ</strong> يحتفظ الفريق الأول بتحديد ساعات التعليم من ضمن الدوام المدرسي في مهلةٍ أقصاها نهاية شهر تشرين الأول مع مراعاة الأحكام الخاصة المتعلقة بأفراد الهيئة التعليمية في المرحلة الثانوية ، ويحق للفريق الأول أن يعدّل في هذه المواعيد إذا تطلّب ذلك سير العمل في المدرسة دون أن يكون للفريق الثاني حق الاعتراض على الدوام الجديد ، شرط أن لا تترتب على الفريق الثاني ساعات عمل إضافية من جرّاء التعديل .</p>
        <p><strong>2 ـ</strong> على الفريق الثاني أن يتقيّد بالدوام المخصص له ولا يحق له أن يتغيّب بدون عذر شرعي في ضوء أحكام المواد 23 و 24 و 25 الجديدة من قانون 15 ـ 6 ـ 1956 ، وتحتفظ المدرسة بحقها في اتخاذ العقوبات المناسبة المنصوص عنها حصراً في المادة 26 من القانون نفسه وذلك في ضوء النتائج المترتبة عن مدة الغياب المشروع .</p>
        <p><strong>3 ـ</strong> يلتزم الفريق الثاني بجميع التوجيهات التربوية والتعليمية التي يقرّها الفريق الأول كما يلتزم بحضور الاجتماعات على أنواعها والمناقشات وبالاشتراك الفعلي في أعمال الامتحانات المدرسية ومراقبتها والتعاون الكلي في ضبطها وذلك في الأوقات التي تحددها الإدارة من ضمن الدوام أو خارجه ، وتطبّق على مخالفة هذا البند الأحكام المبيّنة في المادة 26 من قانون 15 ـ 6 ـ 1956 .</p>
        <p><strong>4 ـ</strong> يلتزم الفريق الثاني بأن يتقيّد بالمناهج الرسمية والخاصة بالمدرسة ويلتزم عدم الإساءة إلى المدرسة وطلابها وإلى زملائه خلال عمله وأثناء وجوده خارج المدرسة ، وتطبّق عليه في حال الإخلال بهذا الالتزام العقوبات المنصوص عليها في المادة 26 المشار إليها سابقاً .</p>
        <p><strong>5 ـ</strong> يأخذ الفريق الثاني علماً بالصلاحيات المنوطة بإدارة الدروس والمنسقين والنظار العامين وفقاً للنظام الداخلي في المدرسة . تستند إدارة المدرسة في تقييمها أعمال الفريق الثاني إلى التقارير التي تضعها إدارة الدروس والمنسقون والنظار العامون شرط أن يصار إلى إبلاغها إلى الفريق الثاني ، كما وتؤخذ التقارير الواردة من لجنة الأهل أو من ذوي الطلاب أو من الطلاب أنفسهم بعين الاعتبار عند تقييم ملف الفريق الثاني وعند تحديد المكافآت والتدابير موضوع البند 3/3 .</p>
        <p><strong>6 ـ</strong> يتم التبليغ بواسطة أمانة السر في المدرسة التي تتلقى أيّ مراجعة في هذا الشأن ، وفي حال رفض التبليغ يعتبر الفريق الثاني مبلّغاً أصولاً بموجب محضر تضعه أمانة السر ويُبنى على هذا المحضر المقتضى القانوني . إنّ هذه الأصول تشكّل جزءاً لا يتجزأ من هذا العقد يلتزم بها الفريقان لتأمين حسن سير العمل التربوي .</p>
        <p><strong>7 ـ</strong> يعتبر هذا العقد مفسوخاً « حكماً » على مسؤولية الفريق الثاني في حال تخلّفه عن القيام بموجباته وانقطاعه عن المدرسة مدة خمسة عشر يوماً دون عذر شرعي ، ويتوجب على الفريق الثاني في هذه الحالة التعويض على الفريق الأول وفقاً لأحكام القانون خاصة المادة 30 من قانون 15 ـ 6 ـ 1956 وتعديلاته .</p>
        <p><strong>8 ـ</strong> يحق للفريق الأول أن يفسخ عقد المتمرنين أو المتعاقدين الجدد قبل الخامس عشر من شهر شباط إذا ثبتت عدم كفاءة الفريق الثاني العلمية أو المسلكية أو في حفظ النظام وتُدفع رواتبه حتى تاريخ الصرف .</p>
        <p><strong>9 ـ</strong> لا يحق للفريق الثاني أن يترك العمل في المدرسة قبل نهاية السنة الدراسية بدون رضى الفريق الأول وإلا ترتّب عليه العطل والضرر المنوّه عنهما في المادة 30 من قانون 15 ـ 6 ـ 1956 .</p>
        <p><strong>10 ـ</strong> يحق للفريق الأول أن يفرض على الفريق الثاني ودون مقابل ساعات إضافية لإتمام المنهج الذي يكون قد وضعه هذا الأخير وأودعه الإدارة في بداية السنة .</p>
        <p><strong>11 ـ</strong> يلتزم الفريق الثاني احترام المواعيد المتعلقة بالتوزيع السنوي للدروس وتحضيرها وتقديم أسئلة الفروض الأسبوعية وأسئلة الامتحانات وتصحيحها وإعادتها دون إبطاء إلى المسؤولين في المواعيد التي تحدّدها الإدارة .</p>
        <p><strong>12 ـ</strong> يبقى الفريق الثاني مرتبطاً بإدارة المدرسة مدة شهر من أشهر العطلة الصيفية على أن يحدَّد هذا الشهر من قبل رئيس المدرسة قبل نهاية السنة الدراسيّة ، وعلى الفريق الثاني أن يلبّي كل دعوة توجَّه إليه خلال هذا الشهر في حدود واجباته المهنية باستثناء التدريس وضمن المهلة التي تحددها له الإدارة ( المادة 22 من قانون 15 ـ 6 ـ 1956 ) .</p>
        <p><strong>13 ـ</strong> يعتبر هذا العقد معلّقاً إذا تعذّر تنفيذه لأي سبب خارج عن إرادة الفريقين واستمر هذا التعذّر مدة ثلاثة أشهر من تاريخ وقف تنفيذه ، وفي هذه الحال يترتّب للفريق الثاني راتب الشهر الأول كاملاً ونصف راتب الشهرين التاليين . ويشمل التعذّر على سبيل المثال لا الحصر الاضطرابات والحروب وتدابير الدولة ، ولا يترتب لأيٍّ من الفريقين أيّ حقوق على الفريق الآخر من أي نوع كان اعتباراً من وقف تنفيذ هذا العقد .</p>
        <p><strong>14 ـ</strong> في جميع الشؤون التي لم يرد ذكرها في المواد السابقة تطبّق أحكام القوانين المرعية الإجراء ، لا سيّما قانون 15 ـ 6 ـ 1956 والقوانين المتعلّقة بتنظيم الموازنة المدرسية .</p>
        <p><strong>15 ـ</strong> يُعمل بهذا العقد من <?= $blank(90) ?> لغاية <?= $blank(90) ?> ، ويتجدد تلقائياً مع مراعاة أحكام المادتين 29 الجديدة و 30 من قانون 15 ـ 6 ـ 1956 .</p>
        <p style="text-align:center;margin-top:14px">نُظّم هذا العقد على نسختين أصليتين أودع كل فريق نسخةً للعمل بموجبها .</p>
        <p style="text-align:center">في : <?= $effFmt ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:30px">
          <div style="text-align:center"><strong>الفريق الثاني</strong><div style="margin-top:40px;border-top:1px solid #333;width:200px"></div></div>
          <div style="text-align:center"><strong>الفريق الأول</strong><div style="margin-top:40px;border-top:1px solid #333;width:200px"></div></div>
        </div>

      <?php elseif ($type === 'notice_school'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <p>حضرة الأستاذ(ة) : <strong><?= e($nomAr) ?></strong> المحترم(ة) .</p>
        <p>الموضوع : <strong><?= e($subjectTxt) ?></strong> .</p>
        <p>المرجــــع : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>تحيّة وبعد ،</p>
        <p>بعد تكراركم <?= e($subjectTxt) ?> ، وبحسب المادة 26 ( كما تعدّلت بموجب القانون رقم 44/87 تاريخ 21/11/1987 ) : إذا أهمل أحد أفراد الهيئة التعليمية والإدارية واجباته في حفظ النظام والتربية ، أو ارتكب مخالفةً لأحكام هذا القانون ، أو تغيّب دون عذر شرعي ، أو تصرّف تصرّفاً يضرّ بسمعة المدرسة أو بانتظام العمل فيها ، يُستهدَف للعقوبات التي ينصّ عليها القانون .</p>
        <p style="text-align:center;font-weight:700">لذلك</p>
        <p>تلفت إدارة المدرسة انتباهكم إلى عدم تكرار ذلك ، كي لا تضطرّ آسفةً إلى تطبيق القوانين المرعية الإجراء بحقّكم .</p>
        <p>وشكراً .</p>
        <div style="display:flex;justify-content:space-between;margin-top:34px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:38px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <div style="margin-top:30px;border-top:1px dashed #999;padding-top:10px">
          <p>تبلّغت الكتاب المضمون أعلاه ،</p>
          <p>الأستاذ(ة) : <?= $blank(220) ?></p>
          <p>الإمضاء : <?= $blank(220) ?></p>
        </div>

      <?php elseif ($type === 'notice_mail'): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:10px">
          <div style="text-align:left;font-weight:700;font-size:13px;line-height:1.7">بطاقة مكشوفة<br>مع إشعار بالاستلام<br>مُرسَلة</div>
          <div style="text-align:right">
            <?php if ($showLogo && $logoImg): ?><?= $logoImg ?><br><?php endif; ?>
            <strong style="font-size:15px"><?= e($schoolNameAr) ?></strong>
            <?= $schoolAddr ? '<br><small>' . e($schoolAddr) . '</small>' : '' ?>
            <?= $schoolPhone ? '<br><small>هاتف : <span dir="ltr">' . e($schoolPhone) . '</span></small>' : '' ?>
          </div>
        </div>
        <div style="border:1px solid #bbb;padding:8px 12px;line-height:1.95;margin-bottom:14px">
          <p style="margin:2px 0">من : <strong><?= e($schoolNameAr) ?></strong></p>
          <p style="margin:2px 0">إلى الأستاذ(ة) : <strong><?= e($nomAr) ?></strong></p>
          <p style="margin:2px 0">العنوان : <?= $addr !== '' ? '<strong>' . e($addr) . '</strong>' : $blank(320) ?></p>
          <p style="margin:2px 0">الهاتف : <?= trim((string)($emp['phone1'] ?? '')) !== '' ? '<strong>' . e($emp['phone1']) . '</strong>' : $blank(150) ?></p>
        </div>
        <p>حضرة الأستاذ(ة) : <strong><?= e($nomAr) ?></strong> المحترم(ة) .</p>
        <p>الموضوع : <strong><?= e($subjectTxt) ?></strong> .</p>
        <p>المرجــــع : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>تحيّة وبعد ،</p>
        <p>بعد تكراركم <?= e($subjectTxt) ?> ، وبحسب المادة 26 ( كما تعدّلت بموجب القانون رقم 44/87 تاريخ 21/11/1987 ) : إذا أهمل أحد أفراد الهيئة التعليمية والإدارية واجباته في حفظ النظام والتربية ، أو ارتكب مخالفةً لأحكام هذا القانون ، أو تغيّب دون عذر شرعي ، أو تصرّف تصرّفاً يضرّ بسمعة المدرسة أو بانتظام العمل فيها ، يُستهدَف للعقوبات التي ينصّ عليها القانون .</p>
        <p style="text-align:center;font-weight:700">لذلك</p>
        <p>تلفت إدارة المدرسة انتباهكم إلى عدم تكرار ذلك ، كي لا تضطرّ آسفةً إلى تطبيق القوانين المرعية الإجراء بحقّكم .</p>
        <p>وشكراً .</p>
        <div style="display:flex;justify-content:space-between;margin-top:34px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:38px;border-top:1px solid #333;width:200px"></div></div>
        </div>
      <?php endif; ?>

      </div>
    </div>
    <?php else: ?>
    <style media="print">/* هوامش الورقة بيد الإفادة لا بيد إعدادات المتصفح */
    @page{size:A4;margin:12mm}
    .page-content{padding:0 !important;margin:0 !important}</style>
    <style>/* خط الإفادات Arial بكل اللغات (بطلب المستخدم 2026-08-20) */ #ppExportArea{font-family:Arial,'Segoe UI',Tahoma,sans-serif}</style>
    <div id="ppExportArea" class="card" style="max-width:820px;margin:0 auto;padding:10px" data-fit1="1" <?= $rtl?'dir="rtl"':'' ?>>
        <div class="card-body" style="line-height:1.95;font-size:12pt;font-family:Arial,'Segoe UI',Tahoma,sans-serif;<?= $rtl?'text-align:right':'' ?>">
            <!-- ترويسة باللغة المختارة -->
            <div style="margin-bottom:22px;<?= $rtl?'text-align:right':'text-align:left' ?>">
                <strong style="font-size:19px"><?= $rtl ? e($schoolNameAr) : e($schoolNameFr) ?></strong><br>
                <small><?= e($cityAr) ?><?= $schoolPhone ? ' — <span dir="ltr">'.e($schoolPhone).'</span>' : '' ?></small>
                <?php if ($employerNssf && ($type==='cnss')): ?><br><small><?= $rtl?'رقم رب العمل في الضمان: ':'N° employeur CNSS: ' ?><?= e($employerNssf) ?></small><?php endif; ?>
            </div>

            <h2 style="text-align:center;margin:6px 0 26px;text-decoration:underline">
                <?= e($ATT_TYPES[$type][$docLang]) ?>
            </h2>

            <?php if ($docLang === 'ar'): ?>
                <p>نشهد نحن، <strong><?= e($schoolNameAr) ?></strong>، بأنّ:</p>
                <?php if (in_array($type,['salaire','travail','cnss'])): ?>
                <p><strong><?= e($nomAr) ?></strong>، يشغل وظيفة <strong><?= e($fnFr['ar']) ?></strong><?php if ($emp['hire_date']): ?>، منذ تاريخ <strong><?= $hireFmt ?></strong><?php endif; ?><?php if ($isTit && $emp['titularization_date']): ?>، تاريخ الترسيم <strong><?= $titFmt ?></strong><?php endif; ?>، من ضمن ملاك مدرستنا<?= $emp['status']==='actif'?' ولا يزال حتى تاريخه':'' ?>.</p>
                    <?php if (!$isEmploye && ($classesAr || $subjects)): ?><p><?php if ($classesAr): ?>الصفوف التي يُدرّسها: <strong><?= e($classesAr) ?></strong>. <?php endif; ?><?php if ($subjects): ?>المواد: <strong><?= e($subjects) ?></strong>.<?php endif; ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($type==='salaire'): ?><p>ويبلغ راتبه الشهري<?= $isEmploye ? '' : ' (الأساس + الدرجات)' ?> <strong><?= $L ?></strong>، وصافي راتبه <strong><?= $N ?></strong><?= $U ?><?php if ($sal): ?> عن <?= e($salPeriodAr) ?><?php endif; ?>.</p>
                <?php elseif ($type==='cnss'): ?><p>وهو مسجَّل في الصندوق الوطني للضمان الاجتماعي تحت الرقم <strong><?= e($nssf) ?></strong>، على راتب شهري خاضع قدره <strong><?= $L ?></strong> (حصة الأجير 3%: <?= formatLBP($cnssAmt) ?>؛ حصة المدرسة 8%: <?= formatLBP($schoolCnss) ?>).</p>
                <?php elseif ($type==='resignation'): ?><p>وقد تقدّم <strong><?= e($nomAr) ?></strong> باستقالته من عمله في مدرستنا<?php if ($emp['hire_date']): ?> (المباشرة منذ <strong><?= $hireFmt ?></strong>)<?php endif; ?>، وقد قُبلت اعتباراً من <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='fin_de_service'): ?><p>وقد انتهت خدمة <strong><?= e($nomAr) ?></strong> في مدرستنا<?php if ($emp['hire_date']): ?>، بعد خدمة من <strong><?= $hireFmt ?></strong> حتى <strong><?= $effFmt ?></strong><?= $yAr?' ('.e($yAr).')':'' ?><?php endif; ?>، اعتباراً من <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='decharge'): ?><p>يُقرّ <strong><?= e($nomAr) ?></strong> بأنه قبض من مدرستنا كامل حقوقه ورواتبه ومستحقاته (بما فيها التعويضات وتعويض نهاية الخدمة) لغاية تاريخ <strong><?= $effFmt ?></strong>، وأنه لا يطالب المدرسة بأي حقّ أو مستحق بعد هذا التاريخ (براءة ذمة تامة).</p>
                <?php endif; ?>
                <p>أُعطيت هذه الإفادة بناءً على طلبه.</p>

            <?php elseif ($docLang === 'fr'): ?>
                <p>Nous soussignés, <strong><?= e($schoolNameFr) ?></strong>, attestons que :</p>
                <?php if (in_array($type,['salaire','travail','cnss'])): ?>
                <p><strong><?= e($nomFr) ?></strong>, exerçant la fonction de <strong><?= e($fnFr['fr']) ?></strong><?php if ($emp['hire_date']): ?>, engagé(e) depuis le <strong><?= $hireFmt ?></strong><?php endif; ?><?php if ($isTit && $emp['titularization_date']): ?>, titularisé(e) le <strong><?= $titFmt ?></strong><?php endif; ?>, fait partie de notre personnel<?= $emp['status']==='actif'?' et est actuellement en activité':'' ?>.</p>
                    <?php if (!$isEmploye && ($classesLat || $subjects)): ?><p><?php if ($classesLat): ?>Classes enseignées : <strong><?= e($classesLat) ?></strong>. <?php endif; ?><?php if ($subjects): ?>Matières : <strong><?= e($subjects) ?></strong>.<?php endif; ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($type==='salaire'): ?><p>Son salaire mensuel<?= $isEmploye ? '' : ' (base + échelon)' ?> s'élève à <strong><?= $L ?></strong>, salaire net <strong><?= $N ?></strong><?= $U ?><?php if ($sal): ?> au titre de <?= e($salPeriodLat) ?><?php endif; ?>.</p>
                <?php elseif ($type==='cnss'): ?><p>Immatriculé(e) à la CNSS sous le n° <strong><?= e($nssf) ?></strong>, sur un salaire mensuel soumis de <strong><?= $L ?></strong> (part employé 3% : <?= formatLBP($cnssAmt) ?> ; part employeur 8% : <?= formatLBP($schoolCnss) ?>).</p>
                <?php elseif ($type==='resignation'): ?><p><strong><?= e($nomFr) ?></strong> a présenté sa démission, acceptée à compter du <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='fin_de_service'): ?><p><strong><?= e($nomFr) ?></strong> a cessé ses fonctions à compter du <strong><?= $effFmt ?></strong><?php if ($emp['hire_date']): ?>, après une période de service du <?= $hireFmt ?> au <?= $effFmt ?><?= $yFr?' ('.e($yFr).')':'' ?><?php endif; ?>.</p>
                <?php elseif ($type==='decharge'): ?><p><strong><?= e($nomFr) ?></strong> reconnaît avoir perçu de notre établissement l'intégralité de ses droits et salaires (y compris indemnités et fin de service) à la date du <strong><?= $effFmt ?></strong>, et déclare n'avoir aucune réclamation envers l'établissement (reçu pour solde de tout compte).</p>
                <?php endif; ?>
                <p>La présente attestation est délivrée à sa demande.</p>

            <?php else: /* en */ ?>
                <p>We, the undersigned, <strong><?= e($schoolNameFr) ?></strong>, hereby certify that:</p>
                <?php if (in_array($type,['salaire','travail','cnss'])): ?>
                <p><strong><?= e($nomFr) ?></strong>, holding the position of <strong><?= e($fnFr['en']) ?></strong><?php if ($emp['hire_date']): ?>, employed since <strong><?= $hireFmt ?></strong><?php endif; ?><?php if ($isTit && $emp['titularization_date']): ?>, tenured on <strong><?= $titFmt ?></strong><?php endif; ?>, is a member of our staff<?= $emp['status']==='actif'?' and is currently in service':'' ?>.</p>
                    <?php if (!$isEmploye && ($classesLat || $subjects)): ?><p><?php if ($classesLat): ?>Classes taught: <strong><?= e($classesLat) ?></strong>. <?php endif; ?><?php if ($subjects): ?>Subjects: <strong><?= e($subjects) ?></strong>.<?php endif; ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($type==='salaire'): ?><p>His/her monthly salary<?= $isEmploye ? '' : ' (base + increment)' ?> is <strong><?= $L ?></strong>, net salary <strong><?= $N ?></strong><?= $U ?><?php if ($sal): ?> for <?= e($salPeriodLat) ?><?php endif; ?>.</p>
                <?php elseif ($type==='cnss'): ?><p>Registered with the National Social Security Fund under no. <strong><?= e($nssf) ?></strong>, on a monthly contributory salary of <strong><?= $L ?></strong> (employee share 3%: <?= formatLBP($cnssAmt) ?>; employer share 8%: <?= formatLBP($schoolCnss) ?>).</p>
                <?php elseif ($type==='resignation'): ?><p><strong><?= e($nomFr) ?></strong> has submitted his/her resignation, accepted effective <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='fin_de_service'): ?><p><strong><?= e($nomFr) ?></strong> ended his/her duties effective <strong><?= $effFmt ?></strong><?php if ($emp['hire_date']): ?>, after a service period from <?= $hireFmt ?> to <?= $effFmt ?><?= $yEn?' ('.e($yEn).')':'' ?><?php endif; ?>.</p>
                <?php elseif ($type==='decharge'): ?><p><strong><?= e($nomFr) ?></strong> acknowledges having received from our establishment all of his/her dues and salaries (including allowances and end-of-service) as of <strong><?= $effFmt ?></strong>, and declares having no claim whatsoever against the establishment (final discharge / receipt in full).</p>
                <?php endif; ?>
                <p>This certificate is issued upon his/her request.</p>
            <?php endif; ?>

            <!-- التاريخ والتوقيع -->
            <div style="display:flex;justify-content:space-between;margin-top:46px">
                <div>
                    <?php if ($docLang==='ar'): ?>حُرّر في <?= $today ?>
                    <?php elseif ($docLang==='fr'): ?><?= e($schoolAddr ? explode(',', $schoolAddr)[0] : '') ?>, le <?= $today ?>
                    <?php else: ?>Issued on <?= $today ?><?php endif; ?>
                </div>
                <div style="text-align:center">
                    <?php if ($type==='decharge'): ?>
                        <strong><?= $docLang==='ar'?'الموظف':($docLang==='fr'?"L'employé(e)":'The employee') ?></strong>
                        <div style="margin-top:46px;border-top:1px solid #333;width:200px"><?= $docLang==='ar'?'التوقيع':'Signature' ?></div>
                    <?php else: ?>
                        <strong><?= $docLang==='ar'?'الإدارة':($docLang==='fr'?'La Direction':'The Administration') ?></strong><br>
                        <?php if ($director): ?><span><?= e($director) ?></span><br><?php endif; ?>
                        <div style="margin-top:46px;border-top:1px solid #333;width:210px"><?= $docLang==='ar'?'التوقيع والخاتم':($docLang==='fr'?'Signature & cachet':'Signature & stamp') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; /* type cnss vs generic */ ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
