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
    'cnss_travail'   => ['fr' => 'Attestation de travail CNSS (officielle)','en' => 'CNSS Work Attestation (official)','ar' => 'إفادة عمل للضمان (نموذج رسمي)'],
    'cnss_hire_new'  => ['fr' => "Déclaration d'embauche — non immatriculé (CNSS 2AA, officielle)", 'en' => 'Hiring Declaration — not registered (official)', 'ar' => 'تصريح باستخدام أجير — لا يحمل رقم ضمان (نموذج رسمي)'],
    'cnss_hire_reg'  => ['fr' => "Déclaration d'embauche — déjà immatriculé (officielle)", 'en' => 'Hiring Declaration — registered (official)', 'ar' => 'إعلام استخدام أجير — يحمل رقم ضمان (نموذج رسمي)'],
    'cnss_leave'     => ['fr' => 'Déclaration de cessation de travail (officielle)', 'en' => 'Leaving Declaration (official)', 'ar' => 'إعلام ترك أجير (نموذج رسمي)'],
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
// «صحح العنوان» (2026-08-20): تنقية المقاطع المكررة («عبرا - عبرا») من عنوان المدرسة بكل العروض
$schoolAddr   = dedupeAddress($school['address'] ?? getSetting('school_address', ''));
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
    // 💬 «أي إفادة بأي لغة وتترجم صح دغري» (بطلبه 2026-08-20): صيَغ المبالغ والتفقيط للإفادات
    // الفرنسية/الإنكليزية — نفس منطق العربي (ليرة/دولار/الاثنين) بتسميات لاتينية
    $moneyLat = function ($lbp) use ($cur, $usdOf) {
        if ($cur === 'usd') return formatUSD($usdOf($lbp));
        if ($cur === 'both') return number_format((int)round($lbp)) . ' LBP (' . formatUSD($usdOf($lbp)) . ')';
        return number_format((int)round($lbp)) . ' LBP';
    };
    $moneyWordsFr = function ($lbp) use ($cur, $usdOf) {
        if ($cur === 'usd') return numToFrenchWords((int)round($usdOf($lbp))) . ' dollars américains';
        return numToFrenchWords((int)round($lbp)) . ' livres libanaises';
    };
    $moneyWordsEn = function ($lbp) use ($cur, $usdOf) {
        if ($cur === 'usd') return numToEnglishWords((int)round($usdOf($lbp))) . ' US Dollars';
        return numToEnglishWords((int)round($lbp)) . ' Lebanese Pounds';
    };
    // مبلغ حرّ يكتبه المستخدم (تعويض الصرف المحسوب) — يُعرَض كما هو بالعملة المختارة بلا تحويل
    $eos = (int)preg_replace('/[^0-9]/', '', (string)($_GET['eos'] ?? ''));
    $embAmt = (int)preg_replace('/[^0-9]/', '', (string)($_GET['emb_amt'] ?? '')); // مبلغ إفادة السفارة (يدوي)
    // 💵 إفادة السفارة (بطلبه 2026-08-20): عملة المبلغ اليدوي (دولار افتراضياً) + الفترة شهري/سنوي
    $embCur = (($_GET['emb_cur'] ?? 'usd') === 'lbp') ? 'lbp' : 'usd';
    $embPer = (($_GET['emb_per'] ?? 'month') === 'year') ? 'year' : 'month';
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
    // 📝 سبب ترك العمل بإفادة صندوق التعويضات (بطلبه 2026-08-20): خيار جاهز
    // (استقالة/صرف/بلوغ السن القانوني) أو سبب حرّ يكتبه — والمكتوب يغلب المختار
    $LV_REASONS = ['استقالة', 'صرف من الخدمة', 'بلوغ السن القانوني'];
    $lvSel = trim((string)($_GET['lv_sel'] ?? ''));
    if ($lvSel !== '' && !in_array($lvSel, $LV_REASONS, true)) $lvSel = '';
    $lvTxt = trim((string)($_GET['lv_txt'] ?? ''));
    $lvFinal = $lvTxt !== '' ? $lvTxt : $lvSel;
    $assocTxt      = trim((string)($_GET['assoc_txt'] ?? '')); if ($assocTxt === '') $assocTxt = 'التابعة لجمعية الراهبات المخلصيات لسيدة البشارة المسجّلة لديكم تحت الرقم (.....)';
    // الترويسة الرسمية الكاملة كصورة خلفية (إن وُجدت لهذه المدرسة) — تُغني عن الترويسة المُعاد بناؤها
    // الترويسة الرسمية بلغة الوثيقة (2026-08-20 «أي إفادة بأي لغة»): فرنسية للإفادات اللاتينية
    $lhUrl = schoolLetterheadUrl($school, ($type === 'embassy' || $docLang !== 'ar') ? 'fr' : 'ar');
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
            // p1 (2026-08-21): سطر الهاتف فقرة dir=rtl مستقلة — بلا rtl صريح كان الوورد يقلب السطر فيظهر الرقم قبل كلمة «هاتف»
            . ($schoolPhone ? '<div dir="rtl" style="font-size:14px">هاتف : <span dir="ltr">' . e($schoolPhone) . '</span></div>' : '')
            . '</td><td style="border:none;padding:0"></td></tr></table>';
    };
    $schoolHeadWord = '<div style="margin-bottom:16px">' . $headBodyAr($logoImgWord) . '</div>';
    // أجنبي (مشترَك لكل الإفادات اللاتينية — 2026-08-20 «أي إفادة بأي لغة»): الاسم الفرنسي
    // والمدينة والهاتف بنفس نمط «لوغو الراهبات» على يسار الصفحة؛ العنوان والمدير المُدخَلان
    // بالأجنبي وإلا ترجمة تلقائية من العربي
    $schoolAddrFr = trim((string)($school['address_fr'] ?? '')) ?: ($schoolAddr !== '' ? arNameToFr($schoolAddr) : '');
    $directorFr   = ($sigNameFr !== '') ? $sigNameFr : ($director !== '' ? arNameToFr($director) : '');
    $cityFr = trim((string)(preg_split('/[-–,،]/u', (string)$schoolAddrFr)[0] ?? ''));
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
    $freeNum   = function ($v) use ($cur) {
        if ($cur === 'usd') return '$' . number_format($v, 2);
        return formatLBP($v, false) . ' ل.ل';
    };
    $freeWords = function ($v) use ($cur) { return numToArabicWords((int)round($v)) . ($cur === 'usd' ? ' دولار أميركي' : ' ليرة لبنانية'); };
    $L=$money($salShown); $N=$money($net); $U='';
    $nssf=cnssWithBirthYear($emp['nssf_number'], $emp['birth_date']);
    $rtl = ($docLang === 'ar');
    $qs = 'employee_id='.$employeeId.'&type='.urlencode($type).'&date='.urlencode($effDate).'&opts_set=1'.($incExtra?'&inc_extra=1':'').($incAide?'&inc_aide=1':'').($incTrans?'&inc_trans=1':'').'&cur='.$cur.($eos>0?'&eos='.$eos:'').($isqMode?'&isq='.$isqMode:'').'&logo='.($showLogo?'1':'0').($isNotice?'&subj_txt='.urlencode($subjectTxt):'').($type==='riaaya'?'&assoc_txt='.urlencode($assocTxt):'').($embAmt>0?'&emb_amt='.$embAmt:'').($embCur!=='usd'?'&emb_cur='.$embCur:'').($embPer!=='month'?'&emb_per='.$embPer:'').($grant>0?'&grant='.$grant:'').($aqdLbp>0?'&aqd_lbp='.$aqdLbp:'').($aqdUsd>0?'&aqd_usd='.$aqdUsd:'').($sigIdx>0?'&sig='.$sigIdx:'').($hasSigTitle?'&sig_t='.$sigTitle:'').($lvSel!==''?'&lv_sel='.urlencode($lvSel):'').($lvTxt!==''?'&lv_txt='.urlencode($lvTxt):'');
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
            <?php if ($type === 'afade_madrasiya'): ?>
            <div style="margin-top:6px">
                <strong>Motif de cessation / سبب ترك العمل:</strong>
                <select name="lv_sel" onchange="this.form.submit()" style="padding:3px 8px">
                    <option value="">— فراغ منقّط (تعبئة باليد) —</option>
                    <?php foreach ($LV_REASONS as $r): ?><option value="<?= e($r) ?>" <?= $lvSel===$r?'selected':'' ?>><?= e($r) ?></option><?php endforeach; ?>
                </select>
                &nbsp; <strong>أو اكتب سبباً:</strong>
                <input type="text" name="lv_txt" value="<?= e($lvTxt) ?>" placeholder="سبب آخر تكتبه بإيدك" style="width:240px;padding:3px 6px" onchange="this.form.submit()">
                <span style="color:#64748b">(المكتوب يغلب المختار)</span>
            </div>
            <?php endif; ?>
            <?php if ($type === 'riaaya'): ?>
            <div style="margin-top:6px"><strong>Organisme et n° d'enregistrement / الجهة ورقم التسجيل:</strong>
            <input type="text" name="assoc_txt" value="<?= e($assocTxt) ?>" style="width:70%;min-width:340px;padding:3px 6px" onchange="this.form.submit()"></div>
            <?php endif; ?>
            <?php if ($type === 'embassy'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>Montant (à saisir) / قيمة الراتب (اكتبها):</strong>
            <input type="text" name="emb_amt" value="<?= $embAmt>0 ? (int)$embAmt : '' ?>" placeholder="المبلغ" style="width:140px;padding:3px 6px" onchange="this.form.submit()">
            <label style="margin:0 8px;cursor:pointer"><input type="radio" name="emb_cur" value="usd" <?= $embCur==='usd'?'checked':'' ?> onchange="this.form.submit()"> $ دولار</label>
            <label style="cursor:pointer"><input type="radio" name="emb_cur" value="lbp" <?= $embCur==='lbp'?'checked':'' ?> onchange="this.form.submit()"> ل.ل ليرة</label>
            <span style="margin:0 12px;color:#cbd5e1">|</span>
            <strong>Période / الفترة:</strong>
            <label style="margin:0 8px;cursor:pointer"><input type="radio" name="emb_per" value="month" <?= $embPer==='month'?'checked':'' ?> onchange="this.form.submit()"> Mensuel / شهري</label>
            <label style="cursor:pointer"><input type="radio" name="emb_per" value="year" <?= $embPer==='year'?'checked':'' ?> onchange="this.form.submit()"> Annuel / سنوي</label>
            <span style="color:#64748b">(الفاضي = الراتب المحسوب بالدولار تلقائياً)</span>
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
            <?php if ($showLogo): ?><div class="word-head" style="display:none"><?= $docLang !== 'ar' ? $schoolHeadWordFr : $schoolHeadWord ?></div><?php endif; ?>
            <?php if ($docLang !== 'ar'): $FR = ($docLang === 'fr'); /* «أي إفادة بأي لغة وتترجم صح دغري» (2026-08-20) */ ?>
            <?php if ($showRecHead && $logoImg): ?><div class="scr-head" style="margin-bottom:8px"><?= $headBodyFr($logoImg) ?></div><?php endif; ?>
            <div dir="ltr" style="text-align:left">
              <div style="font-weight:700;line-height:1.7;margin-bottom:6px">
                  <?= $FR ? 'Caisse Nationale de<br>Sécurité Sociale' : 'National Social<br>Security Fund' ?><br>
                  <span style="font-weight:400"><?= $FR ? 'Bureau' : 'Office' ?> : ــــــــ</span><br>
                  <span style="font-weight:400"><?= $FR ? 'N° d\'entrée' : 'Entry No.' ?> : ــــــــ</span><br>
                  <span style="font-weight:400">Date : ــــــــ</span>
              </div>
              <h2 style="text-align:center;margin:18px 0 26px;text-decoration:underline"><?= $FR ? 'Attestation — À qui de droit' : 'Attestation — To whom it may concern' ?></h2>
              <p><?= $FR ? 'L\'institution' : 'The institution' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
              <p><?= $FR ? 'immatriculée à la Caisse Nationale de Sécurité Sociale sous le n°' : 'registered with the National Social Security Fund under No.' ?> <strong><?= e($employerNssf) ?></strong></p>
              <p><?= $FR ? 'atteste que l\'assuré(e)' : 'certifies that the insured' ?> <strong><?= e($nomFr) ?></strong>, <?= $FR ? 'n°' : 'No.' ?> <strong><?= e($emp['nssf_number']) ?></strong>, <?= $FR ? 'a commencé à travailler chez nous à plein temps' : 'started working with us on a full-time basis' ?></p>
              <p><?= $FR ? 'à compter du' : 'as of' ?> <strong><?= $hireFmt ?></strong> <?= $FR ? 'en qualité de' : 'in the capacity of' ?> (<strong><?= e($FR ? $fnFr['fr'] : $fnFr['en']) ?></strong>)</p>
              <p><?= $FR ? 'et perçoit un salaire mensuel :' : 'and receives a monthly salary of:' ?></p>
              <p style="margin-left:34px">- <?= $FR ? 'Salaire de base conformément à la loi' : 'Basic salary in accordance with the law' ?> : <strong><?= $moneyLat($attBase) ?></strong></p>
              <p style="margin-left:34px">- <?= $FR ? 'Rémunération supplémentaire' : 'Additional remuneration' ?> : <strong><?= $moneyLat($attSupp) ?></strong></p>
              <?php if ($attTrans > 0): ?>
              <p style="margin-left:34px">- <?= $FR ? 'Indemnité de transport' : 'Transport allowance' ?> : <strong><?= $moneyLat($attTrans) ?></strong></p>
              <?php endif; ?>
              <p>Total : <strong><?= $moneyLat($attTotal) ?></strong>, <?= $FR ? 'soit' : 'i.e.' ?> <?= e($FR ? $moneyWordsFr($attTotal) : $moneyWordsEn($attTotal)) ?> <?= $FR ? 'uniquement' : 'only' ?>.</p>
              <?php if ($emp['status']==='actif'): ?><p><?= $FR ? 'Il/Elle poursuit son travail à ce jour.' : 'He/She is still in service to date.' ?></p><?php endif; ?>
              <div style="display:flex;justify-content:space-between;margin-top:54px">
                  <div>Date : <?= $today ?></div>
                  <div style="text-align:center"><strong><?= $FR ? 'Cachet et signature' : 'Stamp and signature' ?></strong>
                      <div style="margin-top:44px;border-top:1px solid #333;width:200px"></div></div>
              </div>
            </div>
            <?php else: ?>
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
            <?php endif; ?>
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
        $dipFr = ($emp['diploma'] ?? '') ? diplomaLabel($emp['diploma'], 'fr') : ''; // للعقد الفرنسي/الإنكليزي
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
        // «بدي نفس الإفادة باللغة الفرنسية» (2026-08-20): الأقسام بالفرنسي لإفادة السفارة الفرنسية
        $nivMapFr = ['maternelle'=>'Maternelle','primaire'=>'Primaire','intermediaire'=>'Complémentaire','secondaire'=>'Secondaire'];
        $nivFr = array_values(array_filter(array_map(function ($k) use ($nivMapFr) { return $nivMapFr[$k] ?? ''; }, $nivKeys)));
        $levelsFr = count($nivFr) >= 2 ? ('aux niveaux ' . implode(' et ', $nivFr)) : (count($nivFr) == 1 ? ('au niveau ' . $nivFr[0]) : '');
        $usdSal = $fxRate > 0 ? (int)round($salShown / $fxRate) : 0;
        // سعر إفادة السفارة: المبلغ اليدوي بالعملة المختارة، وإلا المحسوب بالدولار
        // «خيار أنا حط قيمة الراتب بالدولار + شهري أو سنوي» (2026-08-20): المبلغ اليدوي بعملة
        // خانته (دولار افتراضياً)، وإلا المحسوب بالدولار (×12 حين تكون الفترة سنوية)
        if ($embAmt > 0) {
            $embRate = $embCur === 'lbp' ? number_format($embAmt) . ' L.L' : '$' . number_format($embAmt);
            // «لازم تفقط قيمة الراتب بالإنكليزي» (2026-08-20) + بالفرنسي للنسخة الفرنسية
            $embWords   = numToEnglishWords($embAmt) . ' ' . ($embCur === 'lbp' ? 'Lebanese Pounds' : 'US Dollars') . ' only';
            $embWordsFr = numToFrenchWords($embAmt) . ' ' . ($embCur === 'lbp' ? 'livres libanaises' : 'dollars américains') . ' uniquement';
        } else {
            $embAuto = $embPer === 'year' ? $usdSal * 12 : $usdSal;
            $embRate = $embAuto > 0 ? '$' . number_format($embAuto) : '';
            $embWords   = $embAuto > 0 ? numToEnglishWords($embAuto) . ' US Dollars only' : '';
            $embWordsFr = $embAuto > 0 ? numToFrenchWords($embAuto) . ' dollars américains uniquement' : '';
        }
        $embPerWord = $embPer === 'year' ? 'year' : 'month';
        $embPerWordFr = $embPer === 'year' ? 'an' : 'mois';
        $contactBits = array_filter([trim((string)($school['address'] ?? '')), $schoolPhone ? ('هاتف: ' . $schoolPhone) : '', trim((string)($school['email'] ?? ''))]);
        $contactFooter = implode('  |  ', $contactBits);
        // ترويسة عربية: الشعار يمين + اسم المدرسة (عربي) + الهاتف، بشكل رسمي
        // عربي: الشعار فوق على اليمين، واسم المدرسة والهاتف تحته على اليمين (مثل نموذج باقي المدارس)
        // «بس حط المنطقة قلنا» + «بدون الخط الأسود» + «متل لوغو الراهبات» (2026-08-20):
        // ترويسة الشاشة = نفس كتابة نموذج «لوغو الراهبات» المتوسّطة تحت الشعار، بلا خط، بالمدينة فقط
        $schoolHead = '<div class="scr-head" style="margin-bottom:18px">' . $headBodyAr($logoImg) . '</div>';
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
      <?php if ($showLogo): ?><div class="word-head" style="display:none"><?= ($type === 'embassy' || $docLang !== 'ar') ? $schoolHeadWordFr : $schoolHeadWord ?></div><?php endif; ?>

      <?php
        // ترويسة اتصال أسفل الصفحة (نمط مكسيموس) — تظهر مع الشعار
        // التذييل يظهر فقط مع ترويسة صورة (مكسيموس)؛ المخلصيات نموذجها بلا تذييل (ترويسة فقط)
        $footerHtml = '';
      ?>
      <?php if ($docLang !== 'ar' && $type !== 'embassy'):
        // 💬 «أي إفادة موجودة أنا اختار دغري بأي لغة بدي ياها وتترجم صح دغري — وبكل المؤسسات»
        // (بطلبه 2026-08-20): النسخ الفرنسية والإنكليزية لكل الإفادات — نفس نصوص العربي
        // بالمعنى حرفياً، وبيانات كل مدرسة تُسحب من ملفها (الاسم الفرنسي/المدينة/الترويسة)
        $FR = ($docLang === 'fr');
        $SIG_EN = ['raisa' => 'The Mother Superior', 'idara' => 'The Administration', 'moudir' => 'The Director'];
        $sigTitleLat = $FR ? $SIG_TITLES[$sigTitle]['fr'] : $SIG_EN[$sigTitle];
        $funcLat  = $FR ? $fnFr['fr'] : $fnFr['en'];
        $wordsLat = $FR ? $moneyWordsFr : $moneyWordsEn;
        $uniq     = $FR ? 'uniquement' : 'only';
        $levelsLat = $FR ? $levelsFr : $levelsEn;
        $lvMapLat = ['استقالة' => $FR ? 'Démission' : 'Resignation',
                     'صرف من الخدمة' => $FR ? 'Licenciement' : 'Dismissal from service',
                     'بلوغ السن القانوني' => $FR ? 'Atteinte de l\'âge légal' : 'Reaching the legal age'];
        $lvLat = $lvFinal !== '' ? ($lvMapLat[$lvFinal] ?? $lvFinal) : '';
        $mrsLat = $FR ? 'M./Mme' : 'Mr./Mrs.';
        $reqLine = $FR ? 'La présente attestation lui est délivrée à sa demande.' : 'This certificate is issued upon his/her request.';
        $stillLine = $FR ? 'toujours en fonction à ce jour' : 'and is still in service to date';
        $salParts = [];
        if ($incExtra && $extraW > 0) $salParts[] = [$FR ? 'Rémunération supplémentaire' : 'Additional remuneration', $extraW];
        if ($incAide  && $aideW  > 0) $salParts[] = [$FR ? 'Prime et aide' : 'Bonus and assistance', $aideW];
        if ($incTrans && $transW > 0) $salParts[] = [$FR ? 'Indemnité de transport' : 'Transport allowance', $transW];
      ?>
        <?php if (!in_array($type, ['anhaa_mail', 'notice_mail'], true)): ?>
        <?php if ($showRecHead): ?><?= $schoolHeadFr ?><?php endif; ?>
        <?php endif; ?>
        <div dir="ltr" style="text-align:left">

        <?php if ($type === 'salaire'): ?>
        <?php /* p1 (2026-08-21): خانة N° انشالت + التاريخ على يمين الصفحة بالإفادات اللاتينية —
               div بمحاذاة نصية لا flex حتى يثبت مكانه بالشاشة والوورد سواء */ ?>
        <div style="text-align:right;margin-bottom:10px">Date : <?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'Attestation de salaire' : 'Salary Certificate' ?></h2>
        <p><?= $FR ? 'L\'administration de l\'école' : 'The administration of' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'atteste que' : 'certifies that' ?> <?= $mrsLat ?> <strong><?= e($nomFr) ?></strong> <?php if ($isEmploye): ?><?= $FR ? 'travaille au sein de son établissement en qualité de' : 'has been working there as' ?> <strong><?= e($funcLat) ?></strong><?php else: ?><?= $FR ? 'enseigne au sein de son établissement la matière' : 'has been teaching' ?> <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsLat ?><?php endif; ?> <?= $FR ? 'depuis le' : 'since' ?> <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong>, <?= $FR ? 'toujours en fonction à ce jour, et perçoit un salaire mensuel' : 'is still in service to date, and receives a monthly salary' ?><?= $salParts ? ($FR ? ' détaillé comme suit :' : ' detailed as follows:') : ($FR ? ' de <strong>' . $moneyLat($salShown) . '</strong>.' : ' of <strong>' . $moneyLat($salShown) . '</strong>.') ?></p>
        <?php if ($salParts): ?>
        <p style="margin-left:34px">- <?= $FR ? 'Salaire de base' . ($isEmploye ? '' : ' (après échelons)') : 'Basic salary' . ($isEmploye ? '' : ' (after increments)') ?> : <strong><?= $moneyLat((int)$basePlusEch) ?></strong></p>
        <?php foreach ($salParts as $sp): ?>
        <p style="margin-left:34px">- <?= e($sp[0]) ?> : <strong><?= $moneyLat((int)$sp[1]) ?></strong></p>
        <?php endforeach; ?>
        <p style="margin-left:34px">- Total : <strong><?= $moneyLat($salShown) ?></strong></p>
        <?php endif; ?>
        <p><?= $FR ? 'Soit' : 'In words:' ?> <strong><?= e($wordsLat($salShown)) ?> <?= $uniq ?></strong>.</p>
        <p><?= $reqLine ?></p>
        <div style="width:280px;margin:42px 0 0 auto;text-align:center"><strong><?= e($sigTitleLat) ?> — <?= $FR ? 'Signature et cachet' : 'Signature & stamp' ?></strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?></div>

        <?php elseif ($type === 'tadris'): ?>
        <?php /* p1 (2026-08-21): خانة N° انشالت + التاريخ على يمين الصفحة بالإفادات اللاتينية —
               div بمحاذاة نصية لا flex حتى يثبت مكانه بالشاشة والوورد سواء */ ?>
        <div style="text-align:right;margin-bottom:10px">Date : <?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $isEmploye ? ($FR ? 'Attestation de travail' : 'Work Certificate') : ($FR ? 'Attestation de travail et d\'enseignement' : 'Work and Teaching Certificate') ?></h2>
        <p><?= $FR ? 'L\'administration de l\'école' : 'The administration of' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'atteste que' : 'certifies that' ?> <?= $mrsLat ?> <strong><?= e($nomFr) ?></strong> <?php if ($isEmploye): ?><?= $FR ? 'travaille au sein de son établissement en qualité de' : 'has been working there as' ?> <strong><?= e($funcLat) ?></strong><?php else: ?><?= $FR ? 'enseigne au sein de son établissement la matière' : 'has been teaching' ?> <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsLat ?><?php endif; ?> <?= $FR ? 'depuis le' : 'since' ?> <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong>, <?= $FR ? 'toujours en fonction à ce jour. Il/Elle fait preuve de bonne conduite et d\'assiduité dans l\'accomplissement de son travail.' : 'and is still in service to date. He/She has shown good conduct and commitment in the performance of his/her duties.' ?></p>
        <p><?= $reqLine ?></p>
        <div style="width:280px;margin:42px 0 0 auto;text-align:center"><strong><?= $FR ? 'Le Directeur — Signature et cachet' : 'The Director — Signature & stamp' ?></strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?></div>

        <?php elseif ($type === 'riaaya'): ?>
        <div style="text-align:right;margin-bottom:10px"><?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'À qui de droit' : 'To whom it may concern' ?></h2>
        <p><?= $FR ? 'L\'administration de l\'école' : 'The administration of' ?> <strong><?= e($schoolNameFr) ?></strong> <?= strpos($assocTxt, 'التابعة لجمعية') === 0 ? ($FR ? 'relevant de l\'Association des Religieuses Salvatoriennes de Notre-Dame de l\'Annonciation, enregistrée auprès de vos services sous le n° (.....)' : 'affiliated to the Association of the Salvatorian Sisters of Our Lady of the Annunciation, registered with you under No. (.....)') : e($assocTxt) ?>,</p>
        <p><?= $FR ? 'atteste que' : 'certifies that' ?> <?= $mrsLat ?> <strong><?= e($nomFr) ?></strong> <?php if ($isEmploye): ?><?= $FR ? 'travaille en qualité de' : 'works as' ?> <strong><?= e($funcLat) ?></strong> <?= $FR ? 'dans notre école' : 'at our school' ?><?php else: ?><?= $FR ? 'est enseignant(e) de la matière' : 'is a teacher of' ?> <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsLat ?> <?= $FR ? 'dans notre école' : 'at our school' ?><?php endif; ?>.</p>
        <p><?= $FR ? 'La présente attestation est délivrée à cet effet.' : 'This attestation is issued accordingly.' ?></p>
        <div style="width:280px;margin:42px 0 0 auto;text-align:center"><strong><?= $FR ? 'L\'Administration' : 'The Administration' ?></strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?></div>

        <?php elseif ($type === 'anhaa_khedme' || $type === 'anhaa_mail'): ?>
        <?php if ($type === 'anhaa_mail'): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:10px">
          <div style="text-align:left"><?php if ($showLogo && $logoImg): ?><?= $logoImg ?><br><?php endif; ?>
            <strong style="font-size:15px"><?= e($schoolNameFr) ?></strong>
            <?= $cityFr ? '<br><small>' . e($cityFr) . '</small>' : '' ?>
            <?= $schoolPhone ? '<br><small>Tel : <span dir="ltr">' . e($schoolPhone) . '</span></small>' : '' ?>
          </div>
          <div style="text-align:right;font-weight:700;font-size:13px;line-height:1.7"><?= $FR ? 'Carte découverte<br>avec accusé de réception<br>Recommandée' : 'Open card<br>with acknowledgment of receipt<br>Registered' ?></div>
        </div>
        <div style="border:1px solid #bbb;padding:8px 12px;line-height:1.95;margin-bottom:14px">
          <p style="margin:2px 0"><?= $FR ? 'De' : 'From' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
          <p style="margin:2px 0"><?= $FR ? 'À' : 'To' ?> : <strong><?= e($nomFr) ?></strong></p>
          <p style="margin:2px 0"><?= $FR ? 'Adresse' : 'Address' ?> : <?= $addr !== '' ? '<strong>' . e($addr) . '</strong>' : $blank(320) ?></p>
          <p style="margin:2px 0"><?= $FR ? 'Tél' : 'Phone' ?> : <?= trim((string)($emp['phone1'] ?? '')) !== '' ? '<strong><span dir="ltr">' . e($emp['phone1']) . '</span></strong>' : $blank(150) ?></p>
        </div>
        <?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'Lettre de fin de service' : 'End-of-Service Letter' ?></h2>
        <?php if ($type === 'anhaa_khedme'): ?><p><?= $FR ? 'Cher(ère) Professeur(e)' : 'Dear Teacher' ?> : <strong><?= e($nomFr) ?></strong></p><?php endif; ?>
        <p><?= $FR ? 'En vertu des lois en vigueur, notamment la loi sur le corps enseignant des écoles privées, et en application de l\'article 29 de ladite loi et de ses amendements,' : 'Pursuant to the laws in force, in particular the law on the teaching staff of private schools, and in application of Article 29 thereof as amended,' ?></p>
        <p><?= $FR ? 'nous vous informons, avant le cinq juillet' : 'we hereby inform you, before the fifth of July' ?> <strong><?= $effYear ?></strong>, <?= $FR ? 'de la décision à laquelle se voit contrainte l\'école' : 'of the unavoidable decision of the school' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
        <p><?= $FR ? 'de mettre fin à vos services d\'enseignement pour l\'année scolaire' : 'to terminate your teaching services for the academic year' ?> <strong><?= $curSY ?></strong> <?= $FR ? 'et les suivantes.' : 'and thereafter.' ?></p>
        <p><?= $FR ? 'Tout en regrettant de vous notifier sa décision, l\'administration de l\'école vous remercie de votre coopération dévouée et vous souhaite plein succès, avec l\'expression de son profond respect.' : 'While regretting to notify you of its decision, the school administration thanks you for your devoted cooperation and wishes you every success, with its highest consideration.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:32px">
          <div><?= $FR ? 'Le' : 'On' ?> : <?= $effFmt ?></div>
          <div style="text-align:center"><strong><?= $FR ? 'La Directrice de l\'école' : 'The School Principal' ?></strong><div style="margin-top:36px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <p style="margin-top:12px<?= $type === 'anhaa_mail' ? ';text-align:center' : '' ?>"><?= $FR ? 'Sous toutes réserves' : 'With all reservations' ?></p>
        <?php if ($type === 'anhaa_khedme'): ?>
        <div style="margin-top:24px;border-top:1px dashed #999;padding-top:12px">
          <p><?= $FR ? 'Je soussigné(e)' : 'I, the undersigned' ?> : <strong><?= e($nomFr) ?></strong></p>
          <p><?= $FR ? 'reconnais avoir reçu de la Directrice de l\'école la lettre de fin de mes services.' : 'acknowledge having received from the School Principal the letter terminating my services.' ?></p>
          <div style="display:flex;justify-content:space-between;margin-top:24px">
            <div><?= $FR ? 'Le' : 'On' ?> : <?= $blank(90) ?></div>
            <div style="text-align:center"><strong><?= $FR ? 'Nom et signature' : 'Name and signature' ?></strong><div style="margin-top:32px;border-top:1px solid #333;width:200px"></div></div>
          </div>
        </div>
        <?php endif; ?>

        <?php elseif ($type === 'talab_istiqala'): ?>
        <h2 style="text-align:center;margin:6px 0 24px;text-decoration:underline"><?= $FR ? 'Demande de démission' : 'Resignation Request' ?></h2>
        <p><?= $FR ? 'Madame la Directrice de l\'école' : 'To the Principal of' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
        <p><?= $FR ? 'Je soussigné(e)' : 'I, the undersigned' ?> : <strong><?= e($nomFr) ?></strong></p>
        <p><?= $FR ? 'vous informe de ma démission de mes fonctions à l\'école' : 'hereby inform you of my resignation from my duties at' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'à compter de la prochaine année scolaire' : 'as of the coming academic year' ?> <strong><?= $nextSY ?></strong>, <?= $FR ? 'et ce pour des raisons personnelles.' : 'for personal reasons.' ?></p>
        <p><?= $FR ? 'Veuillez agréer l\'expression de mon profond respect.' : 'Please accept my highest consideration.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:46px">
          <div><?= $FR ? 'Le' : 'On' ?> : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>Signature</strong><div style="margin-top:44px;border-top:1px solid #333;width:200px"></div></div>
        </div>

        <?php elseif ($type === 'afade_madrasiya'): ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'Attestation scolaire' : 'School Attestation' ?></h2>
        <p><?= $FR ? 'Je soussigné(e)' : 'I, the undersigned' ?> : <strong><?= $directorFr ? e($directorFr) : $blank(180) ?></strong></p>
        <p><?= $FR ? 'Chef d\'établissement de l\'école' : 'Head of' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
        <p><?= $FR ? 'certifie que' : 'certify that' ?> <?= $mrsLat ?> : <strong><?= e($nomFr) ?></strong> &nbsp; <?= $FR ? 'titulaire de la carte d\'identité n°' : 'holder of ID card No.' ?> <?= $blank(150) ?></p>
        <p><?= $FR ? 'a commencé à enseigner dans notre école le' : 'started teaching at our school on' ?> <strong><?= $emp['hire_date'] ? $hireFmt : $blank(120) ?></strong></p>
        <p><?= $FR ? 'et a cessé son travail le' : 'and ceased work on' ?> <strong><?= $effFmt ?></strong></p>
        <p><?= $FR ? 'pour les motifs suivants' : 'for the following reasons' ?> : <?= $lvLat !== '' ? '<strong>' . e($lvLat) . '</strong>' : $blank(380) ?></p>
        <?php $attParts = [];
        if ($incExtra && $extraW > 0) $attParts[] = [$FR ? 'Rémunération supplémentaire' : 'Additional remuneration', $extraW];
        if ($incAide  && $aideW  > 0) $attParts[] = [$FR ? 'Prime et aide' : 'Bonus and assistance', $aideW];
        if ($incTrans && $transW > 0) $attParts[] = [$FR ? 'Indemnité de transport' : 'Transport allowance', $transW];
        ?>
        <?php if ($attParts): ?>
        <p><?= $FR ? 'Son salaire mensuel (hors allocations familiales) au dernier mois de service effectif se composait comme suit :' : 'His/her monthly salary (excluding family allowances) in the last month of actual service was composed as follows:' ?></p>
        <p style="margin-left:34px">- <?= $FR ? 'Salaire de base' : 'Basic salary' ?> : <strong><?= $moneyLat((int)$basePlusEch) ?></strong></p>
        <?php foreach ($attParts as $ap): ?>
        <p style="margin-left:34px">- <?= e($ap[0]) ?> : <strong><?= $moneyLat((int)$ap[1]) ?></strong></p>
        <?php endforeach; ?>
        <p style="margin-left:34px">- Total : <strong><?= $moneyLat($salShown) ?></strong></p>
        <?php else: ?>
        <p><?= $FR ? 'Son salaire mensuel (hors allocations familiales) au dernier mois de service effectif était de' : 'His/her monthly salary (excluding family allowances) in the last month of actual service was' ?> <strong><?= $moneyLat($salShown) ?></strong></p>
        <?php endif; ?>
        <p><?= $FR ? 'Soit' : 'In words:' ?> <strong><?= e($wordsLat($salShown)) ?> <?= $uniq ?>.</strong></p>
        <p><?= $FR ? 'La présente attestation est établie conformément aux faits et sur la base des registres de l\'école.' : 'This attestation is issued in accordance with the facts and based on the school records.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:38px">
          <div><?= $FR ? 'Fait le' : 'Issued on' ?> <?= $effFmt ?></div>
          <div style="text-align:center"><strong><?= $FR ? 'Signature du chef d\'établissement' : 'Signature of the head of school' ?></strong><div style="margin-top:10px"><?= $FR ? 'Cachet de l\'école' : 'School stamp' ?></div>
            <div style="margin-top:28px;border-top:1px solid #333;width:220px"></div></div>
        </div>
        <p style="margin-top:22px;font-size:12pt;color:#444"><?= $FR ? 'Note : la présente attestation est à adresser à la direction de la Caisse d\'indemnités des membres du corps enseignant des écoles privées.' : 'Note: this attestation is to be sent to the administration of the Compensation Fund for Private-School Teaching Staff.' ?></p>
        <p style="text-align:center;font-size:12pt;color:#444"><?= $FR ? 'Ministère de l\'Éducation Nationale, de la Jeunesse et des Sports — Beyrouth' : 'Ministry of National Education, Youth and Sports — Beirut' ?></p>

        <?php elseif ($type === 'isqat_haq'): ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'Renonciation de droits' : 'Waiver of Rights' ?></h2>
        <p><?= $FR ? 'Je soussigné(e)' : 'I, the undersigned' ?> <strong><?= e($nomFr) ?></strong></p>
        <p><?= $FR ? 'selon ma carte d\'identité, registre n°' : 'as per my identity card, registry No.' ?> <?= $blank(150) ?> &nbsp; <?= $FR ? 'déclare ce qui suit :' : 'declare the following:' ?></p>
        <p><strong>1)</strong> <?= $FR ? 'J\'ai travaillé à l\'école' : 'I worked at' ?> : <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'en qualité de' : 'as' ?> : <strong><?= e($funcLat) ?></strong> <?= $FR ? 'depuis le' : 'since' ?> <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong>, <?= $FR ? 'mon salaire à la date de la présente renonciation s\'élevant (en chiffres) à' : 'my salary as of the date of this waiver amounting (in figures) to' ?> <strong><?= $moneyLat($salShown) ?></strong>, <?= $FR ? 'soit (en lettres)' : 'in words' ?> <strong><?= e($wordsLat($salShown)) ?> <?= $uniq ?>.</strong></p>
        <p><strong>2)</strong> <?= $FR ? 'J\'ai perçu de l\'école' : 'I received from' ?> : <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'l\'intégralité de mes salaires et de leurs accessoires pendant toute la durée de mon travail, ainsi que tous les droits que la loi me confère pour ladite période, y compris les heures supplémentaires et les congés annuels, etc.' : 'all my salaries and their accessories throughout my period of work, as well as all the rights conferred upon me by law for the said period, including overtime and annual leave, etc.' ?></p>
        <p><strong>3)</strong> <?= $FR ? 'En date du' : 'On' ?> <strong><?= $effFmt ?></strong> &nbsp; <?= $box($isqMode==='istiqala') ?> <?= $FR ? 'j\'ai présenté ma démission' : 'I submitted my resignation' ?> &nbsp;&nbsp; <?= $box($isqMode==='sarf') ?> <?= $FR ? 'j\'ai été licencié(e)' : 'I was dismissed from service' ?>.</p>
        <p><?= $FR ? 'En conséquence et après règlement des comptes, j\'ai perçu de l\'école' : 'Consequently, and after settlement of accounts, I received from' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'la somme (en chiffres) de' : 'the amount (in figures) of' ?> <strong><?= $eos>0 ? $freeNum($eos) : $blank(140) ?></strong> &nbsp; (<?= $FR ? 'en lettres' : 'in words' ?>) <strong><?= $eos>0 ? e(($FR ? numToFrenchWords($eos) : numToEnglishWords($eos)) . ' ' . ($cur === 'usd' ? ($FR ? 'dollars américains' : 'US Dollars') : ($FR ? 'livres libanaises' : 'Lebanese Pounds')) . ' ' . $uniq) : $blank(240) ?></strong></p>
        <p><?= $FR ? 'Cette somme constitue mon indemnité de licenciement.' : 'This amount constitutes my end-of-service indemnity.' ?></p>
        <p><?= $FR ? 'En conséquence, je reconnais que l\'école' : 'Accordingly, I acknowledge that' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'm\'a versé toutes les sommes qui m\'étaient dues en ma qualité d\'employé(e), notamment l\'indemnité de licenciement et l\'indemnité de préavis ; je renonce à l\'égard de ladite école à tout droit, action ou réclamation m\'appartenant en vertu du Code du travail et de ses amendements et de toutes les lois et réglementations en vigueur, et je décharge entièrement l\'école à cet égard, décharge totale couvrant l\'ensemble de la relation de travail qui existait entre nous.' : 'has paid me all amounts due to me in my capacity as its employee, in particular the end-of-service indemnity and the notice indemnity; I waive towards the said school any right, action or claim belonging to me under the Labor Law and its amendments and all applicable laws and regulations, and I fully release the school in this respect, a complete release covering the entire employment relationship that existed between us.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:38px">
          <div><?= $FR ? 'Fait le' : 'Done on' ?> <?= $effFmt ?></div>
          <div style="text-align:center"><strong><?= $FR ? 'Bon pour renonciation de droits' : 'Valid for waiver of rights' ?></strong><div style="margin-top:40px;border-top:1px solid #333;width:220px">Signature</div></div>
        </div>

        <?php elseif ($type === 'baraa_zimma'): $bzMonthLat = $FR ? monthName($eMo, 'fr') : monthName($eMo, 'en'); ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'Quittance, décharge et renonciation de droits' : 'Acknowledgment, Full Release and Waiver of Rights' ?></h2>
        <p><?= $FR ? 'Je soussigné(e)' : 'I, the undersigned' ?> : <strong><?= e($nomFr) ?></strong></p>
        <p><?= $FR ? 'déclare, en pleine capacité juridique, ce qui suit :' : 'declare, with full legal capacity, the following:' ?></p>
        <p><?= $FR ? 'J\'ai travaillé à l\'école' : 'I worked at' ?> : <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'en qualité de' : 'as' ?> <strong><?= e($funcLat) ?></strong>, <?= $FR ? 'et je reconnais avoir perçu l\'intégralité de mes droits financiers de ladite école, y compris les salaires mensuels et leurs accessoires, les indemnités — notamment les aides et rémunérations supplémentaires versées en compensation de l\'effondrement de la monnaie libanaise —, les droits et toutes les sommes qui m\'étaient dues au titre de ma période de travail ; ces montants ne constituent nullement une avance sur indemnité mais une indemnisation complète et définitive à cet égard, et ce jusqu\'à la fin du mois de' : 'and I acknowledge having received all my financial dues from the said school, including monthly salaries and their accessories, indemnities — in particular aids and additional remunerations paid in compensation for the collapse of the Lebanese currency —, rights and all amounts due to me for my period of work; these amounts shall in no way be considered an advance on indemnity but a full and final compensation in this respect, up to the end of the month of' ?> <strong><?= e($bzMonthLat) ?></strong> <?= $FR ? 'de l\'année' : 'of the year' ?> <strong><?= $effYear ?></strong>.</p>
        <p><?= $FR ? 'En conséquence, je décharge ladite école, sa direction, l\'ensemble de ses propriétaires et de ses dirigeants, de tout droit, réclamation ou action que j\'aurais ou pourrais avoir à leur encontre, décharge totale, complète et irrévocable ; je m\'engage à assumer l\'entière responsabilité juridique, financière et pénale en cas de réclamation ultérieure de ma part contraire à la présente déclaration.' : 'Accordingly, I release the said school, its administration, all its owners and directors, from any right, claim or action that I have or may have against them, a full, complete and irrevocable release; I undertake to bear full legal, financial and penal responsibility should any claim be made by me subsequently contrary to this declaration.' ?></p>
        <p><?= $FR ? 'Je renonce également, de manière définitive, irrévocable et globale, à tout droit, action ou réclamation que je pourrais avoir à l\'encontre de ladite école, de sa direction ou de l\'un quelconque de ses propriétaires ou dirigeants, en vertu de toute loi ou réglementation en vigueur, et je les décharge de toute relation de travail antérieure ayant existé entre nous, décharge totale non susceptible d\'annulation ni de rétractation.' : 'I also waive, finally, irrevocably and comprehensively, any right, action or claim that I may have against the said school, its administration or any of its owners or directors, under any applicable law or regulation, and I release them from any previous employment relationship that existed between us, a full release not subject to annulment or withdrawal.' ?></p>
        <p><?= $FR ? 'La présente déclaration et décharge a été établie volontairement, de mon plein gré, sans pression ni contrainte.' : 'This declaration and release has been made voluntarily, of my own free will, without pressure or coercion.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:40px">
          <div><?= $FR ? 'Fait le' : 'Done on' ?> : <?= $effFmt ?></div>
          <div style="text-align:center"><strong><?= $FR ? 'Nom et signature' : 'Name and signature' ?></strong><br><?= e($nomFr) ?><div style="margin-top:34px;border-top:1px solid #333;width:220px"></div></div>
        </div>

        <?php elseif ($type === 'iqrar'): $iqEndYear = $syStart + 2; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline"><?= $FR ? 'Déclaration' : 'Declaration' ?></h2>
        <p><?= $FR ? 'Je soussigné(e),' : 'I, the undersigned,' ?></p>
        <p><?= $FR ? 'Nom complet' : 'Full name' ?> : <strong><?= e($nomFr) ?></strong></p>
        <p><?= $FR ? 'ai contracté avec l\'école' : 'contracted with' ?> : <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'pour l\'année scolaire' : 'for the academic year' ?> : <strong><?= $nextSY ?></strong> <?= $FR ? 'en qualité de' : 'as' ?> <strong><?= e($funcLat) ?></strong>.</p>
        <p><?= $FR ? 'Je déclare avoir été informé(e) par l\'administration de l\'école que, en raison des circonstances économiques difficiles, je percevrai au cours de l\'année scolaire' : 'I declare that I have been informed by the school administration that, due to the pressing economic circumstances, I will receive during the academic year' ?> <strong><?= $nextSY ?></strong> <?= $FR ? 'des subventions financières d\'une valeur de' : 'financial grants amounting to' ?> <?= $grant>0 ? '<strong>'.number_format($grant).'</strong>' : $blank(110) ?> USD ( <?= $grant>0 ? '<strong>'.e($FR ? numToFrenchWords($grant) : numToEnglishWords($grant)).'</strong>' : $blank(150) ?> <?= $FR ? 'dollars américains' : 'US Dollars' ?> ), <?= $FR ? 'ou dont la valeur, les échéances et les modalités de paiement seront fixées par l\'administration de l\'école de manière unilatérale.' : 'or whose value, timing and method of payment shall be determined by the school administration unilaterally.' ?></p>
        <p><?= $FR ? 'Je reconnais en conséquence que les subventions financières que je percevrai durant l\'année scolaire' : 'I therefore acknowledge that the financial grants I will receive during the academic year' ?> <strong><?= $nextSY ?></strong> <?= $FR ? 'constituent, à titre exceptionnel, des subventions spéciales et une aide financière occasionnelle et circonstancielle, versées exceptionnellement selon l\'appréciation unilatérale de l\'administration de l\'école, compte tenu des conditions économiques et de vie difficiles et évolutives.' : 'constitute, exceptionally, special grants and an occasional, circumstantial financial aid, paid exceptionally at the sole discretion of the school administration, given the difficult and evolving economic and living conditions.' ?></p>
        <p><?= $FR ? 'Je reconnais également que ces subventions ne font pas partie des éléments du salaire mensuel versé et déclaré à toutes les autorités compétentes, qu\'aucun principe de constance et de répétition ne saurait être invoqué à ce titre, pour quelque raison que ce soit, pour les considérer comme un élément du salaire, et qu\'aucun droit financier, indemnitaire ou contractuel n\'en découle à l\'égard de l\'école.' : 'I also acknowledge that these grants do not form part of the monthly salary paid and declared to all competent authorities, that no principle of consistency and repetition may be invoked in this respect, for any reason whatsoever, to consider them a salary component, and that no financial, compensatory or contractual rights arise therefrom towards the school.' ?></p>
        <p><?= $FR ? 'En conséquence, l\'administration de l\'école est en droit, de sa seule volonté, de cesser le versement de ces subventions ou d\'en modifier la valeur, à tout moment qu\'elle jugera opportun, sans qu\'aucune responsabilité juridique ou financière n\'en résulte pour elle.' : 'Accordingly, the school administration is entitled, at its sole discretion, to stop paying these grants or to modify their value, at any time it deems appropriate, without incurring any legal or financial liability as a result.' ?></p>
        <p><?= $FR ? 'Je déclare et confirme en outre, par la présente, mon engagement de ne pas quitter l\'enseignement à :' : 'I further declare and confirm, hereby, my commitment not to leave teaching at:' ?></p>
        <p><?= $FR ? 'l\'école' : '' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'durant l\'année scolaire' : 'during the academic year' ?> <strong><?= $nextSY ?></strong>.</p>
        <p><?= $FR ? 'Et ce à compter de la date de la présente déclaration et jusqu\'au 30 juin' : 'This applies from the date of this declaration until 30 June' ?> <strong><?= $iqEndYear ?></strong>. <?= $FR ? 'Je suis conscient(e) que tout manquement à cet engagement peut m\'exposer aux dommages-intérêts prévus à l\'article 30 de la loi sur le corps enseignant du 15/6/1956 (aucun membre du corps enseignant n\'a le droit de quitter le travail au cours de l\'année scolaire, sous peine de dommages-intérêts équivalant au double de ses salaires et accessoires pour la période restante de l\'année scolaire).' : 'I am aware that any breach of this commitment may expose me to the damages provided for in Article 30 of the Teaching Staff Law of 15/6/1956 (no member of the teaching staff may leave work during the school year, under penalty of damages equal to double his salaries and accessories for the remaining period of the school year).' ?></p>
        <p><?= $FR ? 'En conséquence, en pleine capacité juridique, je signe la présente déclaration, m\'engageant à en respecter totalement et intégralement le contenu, et assumant toute responsabilité financière, juridique, pénale ou civile en cas de violation de ses termes.' : 'Therefore, with full legal capacity, I sign this declaration, undertaking to fully and completely abide by its content, and bearing any financial, legal, penal or civil liability in the event of my violation of its terms.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:40px">
          <div><?= $FR ? 'Fait le' : 'Done on' ?> : <?= $effFmt ?></div>
          <div style="text-align:center"><strong><?= $FR ? 'Nom et signature' : 'Name and signature' ?></strong><br><?= e($nomFr) ?><div style="margin-top:34px;border-top:1px solid #333;width:220px"></div></div>
        </div>

        <?php elseif ($type === 'notice_school' || $type === 'notice_mail'):
          $subjLat = ($subjectTxt === 'الإهمال في حفظ النظام والتربية')
              ? ($FR ? 'la négligence dans le maintien de l\'ordre et de la discipline' : 'negligence in maintaining order and discipline')
              : $subjectTxt; ?>
        <?php if ($type === 'notice_mail'): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:10px">
          <div style="text-align:left"><?php if ($showLogo && $logoImg): ?><?= $logoImg ?><br><?php endif; ?>
            <strong style="font-size:15px"><?= e($schoolNameFr) ?></strong>
            <?= $cityFr ? '<br><small>' . e($cityFr) . '</small>' : '' ?>
            <?= $schoolPhone ? '<br><small>Tel : <span dir="ltr">' . e($schoolPhone) . '</span></small>' : '' ?>
          </div>
          <div style="text-align:right;font-weight:700;font-size:13px;line-height:1.7"><?= $FR ? 'Carte découverte<br>avec accusé de réception<br>Recommandée' : 'Open card<br>with acknowledgment of receipt<br>Registered' ?></div>
        </div>
        <div style="border:1px solid #bbb;padding:8px 12px;line-height:1.95;margin-bottom:14px">
          <p style="margin:2px 0"><?= $FR ? 'De' : 'From' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
          <p style="margin:2px 0"><?= $FR ? 'À' : 'To' ?> : <strong><?= e($nomFr) ?></strong></p>
          <p style="margin:2px 0"><?= $FR ? 'Adresse' : 'Address' ?> : <?= $addr !== '' ? '<strong>' . e($addr) . '</strong>' : $blank(320) ?></p>
          <p style="margin:2px 0"><?= $FR ? 'Tél' : 'Phone' ?> : <?= trim((string)($emp['phone1'] ?? '')) !== '' ? '<strong><span dir="ltr">' . e($emp['phone1']) . '</span></strong>' : $blank(150) ?></p>
        </div>
        <?php endif; ?>
        <h2 style="text-align:center;margin:4px 0 18px;text-decoration:underline"><?= $FR ? 'Avertissement' : 'Warning' ?></h2>
        <p><?= $FR ? 'Cher(ère) Professeur(e)' : 'Dear Teacher' ?> : <strong><?= e($nomFr) ?></strong></p>
        <p><?= $FR ? 'Objet' : 'Subject' ?> : <strong><?= e($subjLat) ?></strong>.</p>
        <p><?= $FR ? 'Référence' : 'Reference' ?> : <strong><?= e($schoolNameFr) ?></strong></p>
        <p><?= $FR ? 'Après vos manquements répétés concernant' : 'Further to your repeated' ?> <?= e($subjLat) ?>, <?= $FR ? 'et conformément à l\'article 26 (tel que modifié par la loi n° 44/87 du 21/11/1987) : si un membre du corps enseignant ou administratif néglige ses devoirs de maintien de l\'ordre et de la discipline, commet une infraction aux dispositions de ladite loi, s\'absente sans excuse légitime, ou se conduit d\'une manière portant atteinte à la réputation de l\'école ou au bon déroulement du travail, il s\'expose aux sanctions prévues par la loi.' : 'and in accordance with Article 26 (as amended by Law No. 44/87 of 21/11/1987): if a member of the teaching or administrative staff neglects his duties of maintaining order and discipline, violates the provisions of the said law, is absent without legitimate excuse, or behaves in a manner harmful to the school\'s reputation or the orderly conduct of work, he shall be subject to the penalties provided by law.' ?></p>
        <p style="text-align:center;font-weight:700"><?= $FR ? 'En conséquence' : 'Therefore' ?></p>
        <p><?= $FR ? 'l\'administration de l\'école attire votre attention sur la nécessité de ne pas récidiver, afin de ne pas être contrainte, à regret, d\'appliquer à votre égard les lois en vigueur.' : 'the school administration draws your attention to the necessity of not repeating this, so as not to be compelled, regretfully, to apply the laws in force against you.' ?></p>
        <p><?= $FR ? 'Merci.' : 'Thank you.' ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:34px">
          <div><?= $FR ? 'Le' : 'On' ?> : <?= $effFmt ?></div>
          <div style="text-align:center"><strong><?= $FR ? 'La Directrice de l\'école' : 'The School Principal' ?></strong><div style="margin-top:38px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <?php if ($type === 'notice_school'): ?>
        <div style="margin-top:30px;border-top:1px dashed #999;padding-top:10px">
          <p><?= $FR ? 'J\'ai reçu la lettre dont le contenu figure ci-dessus,' : 'I have received the letter whose content appears above,' ?></p>
          <p><?= $FR ? 'Le/La Professeur(e)' : 'The Teacher' ?> : <?= $blank(220) ?></p>
          <p>Signature : <?= $blank(220) ?></p>
        </div>
        <?php endif; ?>

        <?php elseif ($type === 'aqd_taalim'):
        $cExtra  = $incExtra ? $extraW : 0;
        $cAide   = $incAide  ? $aideW  : 0;
        $cTrans  = $sal ? (int)$sal['transport_lbp'] : 0;
        $cFamily = $sal ? (int)$sal['family_allowance_lbp'] : 0;
        $cTotal  = (int)round($basePlusEch) + $cExtra + $cAide + $cTrans + $cFamily;
        $cDailyTrans = (float)($emp['transport_daily_amount'] ?? 0);
        $cDailyStr = $cDailyTrans > 0
            ? ((($emp['transport_daily_currency'] ?? 'LBP') === 'USD')
                ? ('$' . rtrim(rtrim(number_format($cDailyTrans, 2), '0'), '.'))
                : (number_format($cDailyTrans) . ' LBP'))
            : '';
        $natLat = $FR ? (in_array(mb_strtolower((string)($emp['nationality'] ?? '')), ['lebanese','lebanaise','libanaise','لبنانية','لبناني']) ? 'libanaise' : (string)($emp['nationality'] ?? '')) : (in_array(mb_strtolower((string)($emp['nationality'] ?? '')), ['lebanese','lebanaise','libanaise','لبنانية','لبناني']) ? 'Lebanese' : (string)($emp['nationality'] ?? ''));
        ?>
        <h2 style="text-align:center;margin:4px 0 18px;text-decoration:underline"><?= $FR ? 'Contrat d\'enseignement' : 'Teaching Contract' ?></h2>
        <p><?= $FR ? 'Entre l\'école' : 'Between' ?> : <strong><?= e($schoolNameFr) ?></strong> &nbsp; <?= $FR ? 'représentée par' : 'represented by' ?> : <?= $directorFr ? '<strong>'.e($directorFr).'</strong>' : $blank(150) ?> &nbsp; ( <strong><?= $FR ? 'Première partie' : 'First party' ?></strong> )</p>
        <p><?= $FR ? 'et M./Mme/Mlle' : 'and Mr./Mrs./Miss' ?> : <strong><?= e($nomFr) ?></strong> &nbsp; <?= $FR ? 'de nationalité' : 'of nationality' ?> : <?= $vb($natLat, 90) ?> &nbsp; ( <strong><?= $FR ? 'Seconde partie' : 'Second party' ?></strong> )</p>
        <p><?= $FR ? 'né(e) le' : 'born on' ?> : <strong><?= $dob ?></strong> &nbsp; <?= $FR ? 'à' : 'in' ?> : <strong><?= $bplace ?></strong> &nbsp; <?= $FR ? 'domicile' : 'residence' ?> : <?= $vb($emp['ville'] ?? '', 110) ?> &nbsp; <?= $FR ? 'registre n°' : 'registry No.' ?> : <?= $vb($regNo, 90) ?></p>
        <p><?= $FR ? 'demeurant à l\'adresse suivante' : 'residing at the following address' ?> : &nbsp; <?= $FR ? 'hiver' : 'winter' ?> : <?= $vb($addr, 170) ?> <?= $FR ? 'tél' : 'phone' ?> <?= $phone1 ?> &nbsp;&nbsp; <?= $FR ? 'été' : 'summer' ?> : <?= $blank(120) ?> <?= $FR ? 'tél' : 'phone' ?> <?= $vb($emp['phone2'] ?? '', 90) ?></p>
        <p><?= $FR ? 'En date du' : 'On' ?> : <strong><?= $effFmt ?></strong> <?= $FR ? 'il a été convenu entre les deux parties susmentionnées ce qui suit :' : 'it has been agreed between the two above-mentioned parties as follows:' ?></p>

        <p style="margin-top:10px"><strong><?= $FR ? 'Article premier' : 'Article One' ?> :</strong> <?= $FR ? 'La seconde partie déclare :' : 'The second party declares:' ?></p>
        <p><strong>1/1 ـ</strong> <?= $FR ? 'être titulaire des diplômes officiels suivants :' : 'to hold the following official diplomas:' ?></p>
        <p style="margin-left:26px">1 ـ <?= $vb($dipFr, 150) ?> <?= $FR ? 'délivré par' : 'issued by' ?> : <?= $blank(150) ?> <?= $FR ? 'année' : 'year' ?> <?= $blank(60) ?></p>
        <p style="margin-left:26px">2 ـ <?= $blank(150) ?> <?= $FR ? 'délivré par' : 'issued by' ?> : <?= $blank(150) ?> <?= $FR ? 'année' : 'year' ?> <?= $blank(60) ?></p>
        <p><strong>2/1 ـ</strong> <?= $FR ? 'avoir suivi les sessions de formation et de qualification suivantes :' : 'to have followed the following training and qualification courses:' ?></p>
        <p style="margin-left:26px">1 ـ <?= $blank(150) ?> <?= $FR ? 'à' : 'at' ?> : <?= $blank(150) ?> <?= $FR ? 'année' : 'year' ?> <?= $blank(60) ?></p>
        <p style="margin-left:26px">2 ـ <?= $blank(150) ?> <?= $FR ? 'à' : 'at' ?> : <?= $blank(150) ?> <?= $FR ? 'année' : 'year' ?> <?= $blank(60) ?></p>
        <p><strong>3/1 ـ</strong> <?= $FR ? 'avoir exercé l\'enseignement à :' : 'to have taught at:' ?></p>
        <p style="margin-left:26px">1 ـ <?= $FR ? 'école' : 'school' ?> : <?= $blank(120) ?> <?= $FR ? 'de' : 'from' ?> <?= $blank(70) ?> <?= $FR ? 'à' : 'to' ?> <?= $blank(70) ?> <?= $FR ? 'classes' : 'classes' ?> <?= $blank(90) ?> <?= $FR ? 'matière' : 'subject' ?> <?= $blank(90) ?></p>
        <p style="margin-left:26px">2 ـ <?= $FR ? 'école' : 'school' ?> : <?= $blank(120) ?> <?= $FR ? 'de' : 'from' ?> <?= $blank(70) ?> <?= $FR ? 'à' : 'to' ?> <?= $blank(70) ?> <?= $FR ? 'classes' : 'classes' ?> <?= $blank(90) ?> <?= $FR ? 'matière' : 'subject' ?> <?= $blank(90) ?></p>
        <p><strong>4/1 ـ</strong> <?= $FR ? 'exercer actuellement l\'enseignement à :' : 'to currently teach at:' ?></p>
        <p style="margin-left:26px">1 ـ <?= $FR ? 'école' : 'school' ?> <strong><?= e($schoolNameFr) ?></strong> <?= $FR ? 'en qualité de' : 'as' ?> <?= $box($isContr) ?> <?= $FR ? 'contractuel(le)' : 'contractual' ?> <?= $box($isTit) ?> <?= $FR ? 'cadré(e)' : 'permanent staff' ?> &nbsp; <?= $FR ? 'nombre d\'heures' : 'number of hours' ?> <?= $vb($hpw, 60) ?></p>
        <p style="margin-left:26px">2 ـ <?= $FR ? 'école' : 'school' ?> <?= $blank(120) ?> <?= $FR ? 'en qualité de' : 'as' ?> <?= $chk ?> <?= $FR ? 'contractuel(le)' : 'contractual' ?> <?= $chk ?> <?= $FR ? 'cadré(e)' : 'permanent staff' ?> &nbsp; <?= $FR ? 'nombre d\'heures' : 'number of hours' ?> <?= $blank(60) ?></p>
        <p><strong>5/1 ـ</strong> <?= $FR ? 'remplir les conditions générales requises pour l\'exercice de l\'enseignement (définies par le décret-loi 59/112 : civiles, sanitaires, .....)' : 'to meet the general conditions required for the practice of teaching (defined by Legislative Decree 59/112: civil, health, .....)' ?></p>
        <p><strong>6/1 ـ</strong> <?= $FR ? 'être disposé(e) à suivre les sessions de qualification organisées par l\'école, qui en détermine le sujet, la durée, le lieu et l\'horaire.' : 'to be prepared to attend the qualification sessions organized by the school, which determines their subject, duration, place and time.' ?></p>
        <p><strong>7/1 ـ</strong> <?= $FR ? 'être' : 'to be' ?> : <?= $box(!$isMarried) ?> <?= $FR ? 'célibataire' : 'single' ?> <?= $box($isMarried) ?> <?= $FR ? 'marié(e)' : 'married' ?> <?= $box(false) ?> <?= $FR ? 'veuf(ve)' : 'widowed' ?> <?= $box(false) ?> <?= $FR ? 'divorcé(e)' : 'divorced' ?> &nbsp; <?= $FR ? 'nombre d\'enfants à charge' : 'number of dependent children' ?> : <?= $vb((string)$nKids, 60) ?></p>
        <p><strong>8/1 ـ</strong> <?= $FR ? 'que le conjoint' : 'that the spouse' ?> <?= $box(!$spouseWorks) ?> <?= $FR ? 'n\'exerce pas' : 'does not perform' ?> <?= $box((bool)$spouseWorks) ?> <?= $FR ? 'exerce un travail rémunéré (nom de l\'institution)' : 'performs paid work (name of institution)' ?> : <?= $blank(150) ?></p>
        <p><strong>9/1 ـ</strong> <?= $FR ? 'avoir une connaissance suffisante des programmes officiels et de leurs objectifs.' : 'to be well acquainted with the official curricula and their objectives.' ?></p>
        <p><strong>10/1 ـ</strong> <?= $FR ? 'savoir pleinement que l\'école est une école catholique attachée aux directives et enseignements de l\'Église visant la formation spirituelle, humaine, scientifique et patriotique de l\'élève, en coopération avec les parents et le corps enseignant, conformément au règlement particulier établi par l\'école régissant les relations éducatives, pédagogiques et administratives entre toutes les parties concernées.' : 'to be fully aware that the school is a Catholic school committed to the directives and teachings of the Church aiming at the spiritual, human, scientific and patriotic formation of the student, in cooperation with the parents and the teaching staff, in accordance with the special regulations established by the school governing the educational and administrative relations between all concerned.' ?></p>
        <p><strong>11/1 ـ</strong> <?= $FR ? 's\'engager à remettre à la première partie les documents justificatifs de ses déclarations dans un délai maximal de' : 'to undertake to submit to the first party the documents supporting his/her declarations within a maximum period of' ?> <?= $blank(120) ?></p>

        <p style="margin-top:10px"><strong><?= $FR ? 'Article 2' : 'Article Two' ?> :</strong> <?= $FR ? 'La première partie engage la seconde partie pour enseigner durant l\'année scolaire' : 'The first party contracts the second party to teach during the academic year' ?> : <strong><?= $nextSY ?></strong></p>
        <p style="margin-left:26px">ـ <?= $FR ? 'en qualité de' : 'in the capacity of' ?> : <?= $chk ?> <?= $FR ? 'puéricultrice' : 'nursery teacher' ?> <?= $chk ?> <?= $FR ? 'instituteur(trice)' : 'instructor' ?> <?= $chk ?> <?= $FR ? 'maître(sse)' : 'teacher' ?> <?= $chk ?> <?= $FR ? 'professeur du secondaire' : 'secondary teacher' ?> <?= $box(false) ?> <?= $FR ? 'stagiaire' : 'trainee' ?> <?= $box($isContr) ?> <?= $FR ? 'contractuel(le)' : 'contractual' ?></p>
        <p style="margin-left:26px">ـ <?= $FR ? 'nombre d\'heures d\'enseignement effectif par semaine' : 'number of actual teaching hours per week' ?> : <?= $vb($hpw, 70) ?> &nbsp; <?= $FR ? 'niveau' : 'level' ?> : <?= $vb($niveau, 110) ?> &nbsp; <?= $FR ? 'matière' : 'subject' ?> : <?= $vb($subj, 110) ?></p>
        <p style="margin-left:26px">ـ <?= $FR ? 'nombre d\'heures de décharge consacrées aux activités parascolaires' : 'number of reduced hours allocated to extracurricular activities' ?> : <?= $blank(70) ?></p>

        <p style="margin-top:10px"><strong><?= $FR ? 'Article 3' : 'Article Three' ?> :</strong> <?= $FR ? 'La première partie verse mensuellement à la seconde partie, en contrepartie des missions d\'enseignement qui lui sont confiées :' : 'The first party shall pay the second party monthly, in consideration of the teaching duties entrusted to him/her:' ?></p>
        <p style="margin-left:26px"><strong>1/3 ـ</strong> <?= $FR ? 'Salaire de base' : 'Basic salary' ?> : <strong><?= (int)round($basePlusEch) > 0 ? $moneyLat((int)$basePlusEch) : $blank(120) ?></strong> &nbsp; ( <?= $FR ? 'salaire légal' : 'legal wage' ?> )</p>
        <p style="margin-left:26px"><strong>2/3 ـ</strong> <?= $FR ? 'Indemnité financière (loi 99/148)' : 'Financial allowance (Law 99/148)' ?> : <?= $blank(120) ?></p>
        <p style="margin-left:26px"><strong>3/3 ـ</strong> <?= $FR ? 'Heures supplémentaires' : 'Overtime hours' ?> : <?= $blank(120) ?></p>
        <p style="margin-left:26px"><strong>4/3 ـ</strong> <?= $FR ? 'Indemnité supplémentaire à titre de prime' : 'Additional allowance as bonus' ?> : <?= $cAide > 0 ? '<strong>'.$moneyLat($cAide).'</strong>' : $blank(120) ?></p>
        <p style="margin-left:26px"><strong>5/3 ـ</strong> <?= $FR ? 'Indemnité de transport provisoire' : 'Temporary transport allowance' ?> : <?php
            $t53 = [];
            if ($cDailyStr !== '') $t53[] = '<strong>' . $cDailyStr . '</strong> ' . ($FR ? 'par jour' : 'per day');
            if ($cTrans > 0) $t53[] = '<strong>' . $moneyLat($cTrans) . '</strong> ' . ($FR ? 'par mois' : 'per month');
            echo $t53 ? implode(' &nbsp;,&nbsp; ', $t53) : $blank(120);
        ?></p>
        <p style="margin-left:26px"><strong>6/3 ـ</strong> <?= $FR ? 'Allocations familiales' : 'Family allowances' ?> : <?= $cFamily > 0 ? '<strong>'.$moneyLat($cFamily).'</strong>' : $blank(120) ?> ( <?= $FR ? 'le cas échéant' : 'if applicable' ?> )</p>
        <p style="margin-left:26px"><strong>Total :</strong> <strong><?= $moneyLat($cTotal) ?></strong> <?= $FR ? 'soit' : 'i.e.' ?> : <strong><?= e($wordsLat($cTotal)) ?></strong></p>
        <p style="margin-left:26px"><strong><?= $FR ? 'Montant convenu' : 'Agreed amount' ?> :</strong> <?php
            $agreed = [];
            if ($aqdLbp > 0) $agreed[] = '<strong>' . number_format($aqdLbp) . ' LBP</strong> ' . ($FR ? 'soit ' : 'i.e. ') . e($FR ? numToFrenchWords($aqdLbp) . ' livres libanaises uniquement' : numToEnglishWords($aqdLbp) . ' Lebanese Pounds only');
            if ($aqdUsd > 0) $agreed[] = '<strong>$' . number_format($aqdUsd) . '</strong> ' . ($FR ? 'soit ' : 'i.e. ') . e($FR ? numToFrenchWords($aqdUsd) . ' dollars américains uniquement' : numToEnglishWords($aqdUsd) . ' US Dollars only');
            echo $agreed ? implode(' &nbsp;' . ($FR ? 'et' : 'and') . '&nbsp; ', $agreed) : $blank(240);
        ?></p>
        <p><?= $FR ? 'Sont retenues sur les droits de la seconde partie les sommes mensuelles légalement dues à la Caisse d\'indemnités, à la Caisse Nationale de Sécurité Sociale, au Ministère des Finances et au titre des timbres, que la première partie verse aux autorités compétentes sous sa responsabilité.' : 'The monthly amounts legally due to the Compensation Fund, the National Social Security Fund, the Ministry of Finance and stamp duties shall be withheld from the second party\'s dues and paid by the first party to the competent authorities under its responsibility.' ?></p>

        <p style="margin-top:10px"><strong><?= $FR ? 'Conditions particulières' : 'Special Conditions' ?></strong></p>
        <p><strong>1 ـ</strong> <?= $FR ? 'La première partie se réserve la fixation des heures d\'enseignement dans le cadre de l\'horaire scolaire au plus tard fin octobre, compte tenu des dispositions particulières relatives au corps enseignant du cycle secondaire ; elle peut modifier ces horaires si la bonne marche de l\'école l\'exige, sans que la seconde partie puisse s\'y opposer, à condition qu\'il n\'en résulte pas pour elle des heures supplémentaires.' : 'The first party reserves the right to set the teaching hours within the school schedule no later than the end of October, taking into account the special provisions relating to secondary-cycle teachers; it may modify these schedules if the proper functioning of the school so requires, without objection from the second party, provided no additional working hours result therefrom.' ?></p>
        <p><strong>2 ـ</strong> <?= $FR ? 'La seconde partie doit respecter l\'horaire qui lui est assigné et ne peut s\'absenter sans excuse légitime au regard des articles 23, 24 et 25 nouveaux de la loi du 15-6-1956 ; l\'école se réserve le droit de prendre les sanctions appropriées prévues limitativement à l\'article 26 de la même loi, au vu des conséquences de la durée de l\'absence légitime.' : 'The second party must abide by the assigned schedule and may not be absent without legitimate excuse under the new Articles 23, 24 and 25 of the Law of 15-6-1956; the school reserves the right to take the appropriate sanctions exhaustively provided for in Article 26 of the same law, in light of the consequences of the duration of the legitimate absence.' ?></p>
        <p><strong>3 ـ</strong> <?= $FR ? 'La seconde partie s\'engage à suivre toutes les directives éducatives et pédagogiques arrêtées par la première partie, à assister aux réunions et discussions de toute nature, à participer effectivement aux examens scolaires et à leur surveillance et à coopérer pleinement à leur bon déroulement, aux moments fixés par l\'administration pendant ou en dehors de l\'horaire ; les dispositions de l\'article 26 de la loi du 15-6-1956 s\'appliquent à toute violation de la présente clause.' : 'The second party undertakes to follow all educational directives set by the first party, to attend meetings and discussions of all kinds, to participate effectively in school examinations and their supervision and to cooperate fully in their proper conduct, at the times set by the administration during or outside working hours; the provisions of Article 26 of the Law of 15-6-1956 apply to any breach of this clause.' ?></p>
        <p><strong>4 ـ</strong> <?= $FR ? 'La seconde partie s\'engage à respecter les programmes officiels et propres à l\'école et à ne porter atteinte ni à l\'école, ni à ses élèves, ni à ses collègues, pendant son travail comme en dehors de l\'école ; en cas de manquement, les sanctions prévues à l\'article 26 précité lui sont applicables.' : 'The second party undertakes to abide by the official and school-specific curricula and not to harm the school, its students or colleagues, during work or outside the school; in case of breach, the penalties provided for in the aforementioned Article 26 shall apply.' ?></p>
        <p><strong>5 ـ</strong> <?= $FR ? 'La seconde partie prend acte des attributions confiées à la direction des études, aux coordinateurs et aux surveillants généraux conformément au règlement intérieur de l\'école. L\'administration fonde son évaluation du travail de la seconde partie sur les rapports établis par la direction des études, les coordinateurs et les surveillants généraux, à condition qu\'ils lui soient communiqués ; les rapports émanant du comité des parents, des parents d\'élèves ou des élèves eux-mêmes sont également pris en considération dans l\'évaluation du dossier de la seconde partie et la détermination des primes et mesures objet de la clause 3/3.' : 'The second party takes note of the powers vested in the academic direction, the coordinators and the general supervisors in accordance with the school\'s internal regulations. The administration bases its evaluation of the second party\'s work on the reports drawn up by the academic direction, the coordinators and the general supervisors, provided they are communicated to him/her; reports from the parents\' committee, parents or students themselves are also taken into consideration in evaluating the second party\'s file and determining the bonuses and measures under clause 3/3.' ?></p>
        <p><strong>6 ـ</strong> <?= $FR ? 'Les notifications sont effectuées par le secrétariat de l\'école, qui reçoit tout recours à ce sujet ; en cas de refus de notification, la seconde partie est réputée dûment notifiée par procès-verbal établi par le secrétariat, sur la base duquel il est procédé conformément à la loi. Ces règles font partie intégrante du présent contrat et lient les deux parties pour assurer la bonne marche du travail éducatif.' : 'Notifications are made through the school secretariat, which receives any petition in this regard; in case of refusal of notification, the second party is deemed duly notified by minutes drawn up by the secretariat, on the basis of which legal action is taken. These rules form an integral part of this contract and bind both parties to ensure the proper conduct of the educational work.' ?></p>
        <p><strong>7 ـ</strong> <?= $FR ? 'Le présent contrat est résilié « de plein droit » aux torts de la seconde partie si elle manque à ses obligations et s\'absente de l\'école pendant quinze jours sans excuse légitime ; la seconde partie doit alors indemniser la première partie conformément à la loi, notamment l\'article 30 de la loi du 15-6-1956 et ses amendements.' : 'This contract is terminated "ipso facto" at the fault of the second party if he/she fails in his/her obligations and is absent from the school for fifteen days without legitimate excuse; the second party must then compensate the first party in accordance with the law, in particular Article 30 of the Law of 15-6-1956 as amended.' ?></p>
        <p><strong>8 ـ</strong> <?= $FR ? 'La première partie peut résilier le contrat des stagiaires ou des nouveaux contractuels avant le quinze février si l\'incompétence scientifique ou comportementale de la seconde partie, ou son incapacité à maintenir la discipline, est établie ; ses salaires sont payés jusqu\'à la date du licenciement.' : 'The first party may terminate the contract of trainees or new contractual staff before the fifteenth of February if the second party\'s scientific or behavioral incompetence, or inability to maintain discipline, is established; his/her salaries shall be paid up to the date of dismissal.' ?></p>
        <p><strong>9 ـ</strong> <?= $FR ? 'La seconde partie ne peut quitter le travail à l\'école avant la fin de l\'année scolaire sans l\'accord de la première partie, sous peine des dommages-intérêts visés à l\'article 30 de la loi du 15-6-1956.' : 'The second party may not leave work at the school before the end of the school year without the first party\'s consent, under penalty of the damages referred to in Article 30 of the Law of 15-6-1956.' ?></p>
        <p><strong>10 ـ</strong> <?= $FR ? 'La première partie peut imposer à la seconde partie, sans contrepartie, des heures supplémentaires pour achever le programme que cette dernière a établi et déposé auprès de l\'administration en début d\'année.' : 'The first party may impose on the second party, without consideration, additional hours to complete the curriculum which the latter established and filed with the administration at the beginning of the year.' ?></p>
        <p><strong>11 ـ</strong> <?= $FR ? 'La seconde partie s\'engage à respecter les échéances relatives à la répartition annuelle des cours et à leur préparation, à la remise des questions des devoirs hebdomadaires et des examens, à leur correction et à leur restitution sans délai aux responsables, aux dates fixées par l\'administration.' : 'The second party undertakes to respect the deadlines relating to the annual distribution of lessons and their preparation, the submission of weekly assignment and examination questions, their correction and their return without delay to those in charge, on the dates set by the administration.' ?></p>
        <p><strong>12 ـ</strong> <?= $FR ? 'La seconde partie reste liée à l\'administration de l\'école pendant un mois des vacances d\'été, fixé par le chef d\'établissement avant la fin de l\'année scolaire ; elle doit répondre à toute convocation durant ce mois dans les limites de ses devoirs professionnels, à l\'exception de l\'enseignement, et dans le délai fixé par l\'administration (article 22 de la loi du 15-6-1956).' : 'The second party remains bound to the school administration for one month of the summer vacation, to be determined by the head of school before the end of the school year; he/she must respond to any summons during this month within the limits of his/her professional duties, excluding teaching, and within the period set by the administration (Article 22 of the Law of 15-6-1956).' ?></p>
        <p><strong>13 ـ</strong> <?= $FR ? 'Le présent contrat est réputé suspendu si son exécution devient impossible pour toute cause indépendante de la volonté des deux parties et que cette impossibilité persiste trois mois à compter de la suspension ; dans ce cas, la seconde partie a droit au salaire entier du premier mois et à la moitié du salaire des deux mois suivants. L\'impossibilité comprend, à titre d\'exemple non limitatif, les troubles, les guerres et les mesures de l\'État ; aucun droit d\'aucune sorte ne naît pour l\'une des parties à l\'égard de l\'autre à compter de la suspension de l\'exécution du présent contrat.' : 'This contract is deemed suspended if its performance becomes impossible for any cause beyond the control of both parties and such impossibility persists for three months from the suspension; in this case, the second party is entitled to the full salary of the first month and half the salary of the following two months. Impossibility includes, by way of non-limiting example, unrest, wars and State measures; no rights of any kind arise for either party towards the other as from the suspension of the performance of this contract.' ?></p>
        <p><strong>14 ـ</strong> <?= $FR ? 'Pour toutes les matières non mentionnées aux articles précédents, les dispositions des lois en vigueur s\'appliquent, notamment la loi du 15-6-1956 et les lois relatives à l\'organisation du budget scolaire.' : 'In all matters not mentioned in the preceding articles, the provisions of the laws in force shall apply, in particular the Law of 15-6-1956 and the laws relating to the organization of the school budget.' ?></p>
        <p><strong>15 ـ</strong> <?= $FR ? 'Le présent contrat s\'applique du' : 'This contract applies from' ?> <?= $blank(90) ?> <?= $FR ? 'au' : 'to' ?> <?= $blank(90) ?>, <?= $FR ? 'et se renouvelle automatiquement sous réserve des dispositions des articles 29 nouveau et 30 de la loi du 15-6-1956.' : 'and is automatically renewed subject to the provisions of the new Article 29 and Article 30 of the Law of 15-6-1956.' ?></p>
        <p style="text-align:center;margin-top:14px"><?= $FR ? 'Le présent contrat a été établi en deux exemplaires originaux, chaque partie en ayant reçu un pour s\'y conformer.' : 'This contract has been drawn up in two original copies, each party having received one to abide by.' ?></p>
        <p style="text-align:center"><?= $FR ? 'Le' : 'On' ?> : <?= $effFmt ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:30px">
          <div style="text-align:center"><strong><?= $FR ? 'Première partie' : 'First party' ?></strong><div style="margin-top:40px;border-top:1px solid #333;width:200px"></div></div>
          <div style="text-align:center"><strong><?= $FR ? 'Seconde partie' : 'Second party' ?></strong><div style="margin-top:40px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <?php endif; ?>

        </div>
      <?php elseif ($type === 'salaire'): ?>
        <?php // 🧾 «بدي إفادة الراتب بدون تابلو» (2026-08-20): تفصيل الراتب سطوراً لا جدولاً —
              // والأساس وحده مختاراً = جملة واحدة بالمبلغ بلا تفصيل
        $salParts = [];
        if ($incExtra && $extraW > 0) $salParts[] = ['الأجر الإضافي', $extraW];
        if ($incAide  && $aideW  > 0) $salParts[] = ['مكافأة ومساعدة', $aideW];
        if ($incTrans && $transW > 0) $salParts[] = ['تعويض النقل', $transW];
        ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <?php /* p1 (2026-08-21): خانة «الرقم» انشالت + التاريخ على شمال الصفحة بالإفادة العربية —
               dir=rtl على السطر حتى تبقى كلمة «التاريخ» قبل الرقم (يمينه) بالشاشة والوورد سواء */ ?>
        <div dir="rtl" style="text-align:left;margin-bottom:10px">التاريخ : <?= $today ?></div>
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
        <?php /* p1 (2026-08-21): خانة «الرقم» انشالت + التاريخ على شمال الصفحة بالإفادة العربية —
               dir=rtl على السطر حتى تبقى كلمة «التاريخ» قبل الرقم (يمينه) بالشاشة والوورد سواء */ ?>
        <div dir="rtl" style="text-align:left;margin-bottom:10px">التاريخ : <?= $today ?></div>
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
        <p>أنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> <?php if ($isEmploye): ?>يعمل(تعمل) <strong><?= e($fnFr['ar']) ?></strong> في مدرستنا<?php else: ?>هو(هي) معلّم(ة) لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> في مدرستنا<?php endif; ?> .</p>
        <p>وللبيان أُعطيت هذه الإفادة .</p>
        <div style="width:260px;margin:42px auto 0 0;text-align:center"><strong>الإدارة</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'embassy' && $docLang === 'fr'): ?>
        <?php /* «بدي نفس الإفادة باللغة الفرنسية» (2026-08-20) — تُختار من أزرار اللغة فوق */ ?>
        <?php if ($showRecHead): ?><?= $schoolHeadFr ?><?php endif; ?>
        <div dir="ltr" style="text-align:left">
          <div style="text-align:right;margin-bottom:10px"><?= date('d/m/Y') ?></div>
          <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">Attestation</h2>
          <p>À qui de droit,</p>
          <p>Nous certifions par la présente que <strong><?= e(trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['father_name_fr'] ? $emp['father_name_fr'] . ' ' : '') . ($emp['last_name_fr'] ?? ''))) ?></strong> <?php if ($isEmploye): ?>est employé(e) en qualité de <strong><?= e($fnFr['fr']) ?></strong> à <strong><?= e($schoolNameFr) ?></strong>, à raison de <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> par <?= $embPerWordFr ?></strong><?= $embRate !== '' ? ' (' . e($embWordsFr) . ')' : '' ?><?php else: ?>est enseignant(e) à <strong><?= e($schoolNameFr) ?></strong>. Il/Elle enseigne <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsFr ?>, à raison de <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> par <?= $embPerWordFr ?></strong><?= $embRate !== '' ? ' (' . e($embWordsFr) . ')' : '' ?><?php endif; ?>. Nous confirmons également qu'il/elle est engagé(e) dans notre établissement pour l'année scolaire <strong><?= $nextSY ?></strong>.</p>
          <p style="text-align:center">Cette attestation lui est délivrée à sa demande.</p>
          <div style="width:260px;margin:42px 0 0 auto;text-align:center"><strong>Le Directeur</strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?></div>
        </div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'embassy'): ?>
        <?php if ($showRecHead): ?><?= $schoolHeadFr ?><?php endif; ?>
        <div dir="ltr" style="text-align:left">
          <div style="text-align:right;margin-bottom:10px"><?= date('d/m/Y') ?></div>
          <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">Attestation</h2>
          <p>To whom it may concern,</p>
          <p>This is to certify that <strong><?= e(trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['father_name_fr'] ? $emp['father_name_fr'] . ' ' : '') . ($emp['last_name_fr'] ?? ''))) ?></strong> <?php if ($isEmploye): ?>has been employed as <strong><?= e($fnFr['en']) ?></strong> at <strong><?= e($schoolNameFr) ?></strong>, at a rate of <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> per <?= $embPerWord ?></strong><?= $embRate !== '' ? ' (' . e($embWords) . ')' : '' ?><?php else: ?>has been a teacher at <strong><?= e($schoolNameFr) ?></strong>. He/She has been, and continues to be, teaching <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsEn ?>, at a rate of <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> per <?= $embPerWord ?></strong><?= $embRate !== '' ? ' (' . e($embWords) . ')' : '' ?><?php endif; ?>. We also confirm that he/she is currently engaged at our school for the academic year <strong><?= $nextSY ?></strong>.</p>
          <p style="text-align:center">This certificate is issued upon his/her request.</p>
          <div style="width:260px;margin:42px 0 0 auto;text-align:center"><strong>The Director</strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?></div>
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
            <?= $schoolPhone ? '<div dir="rtl"><small>هاتف : <span dir="ltr">' . e($schoolPhone) . '</span></small></div>' : '' ?>
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
        <p>أُثبت أنّ السيّد(ة) : <strong><?= e($nomAr) ?></strong> &nbsp; حامل بطاقة الهوية رقم <?= $blank(150) ?></p>
        <p>قد باشر التدريس في مدرستنا بتاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(120) ?></strong></p>
        <p>وانقطع عن العمل بتاريخ <strong><?= $effFmt ?></strong></p>
        <?php /* سبب الترك من الخيار/النص الحرّ (2026-08-20) — والفاضي = خط منقّط يُعبّأ باليد */ ?>
        <p>للأسباب الآتية : <?= $lvFinal !== '' ? '<strong>' . e($lvFinal) . '</strong>' : $blank(380) ?></p>
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
        <p><strong>2)</strong> قبضت من مدرسة : <strong><?= e($schoolNameAr) ?></strong> رواتبي كاملةً مع لواحقها طيلة مدة عملي لديها ، كما تناولت جميع الحقوق التي يخوّلني إياها القانون طيلة المدة المذكورة بما في ذلك بدل الساعات الإضافية والفرص السنوية إلخ .....</p>
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
        <p>وذلك اعتباراً من تاريخ هذا الإقرار ولغاية 30 حزيران <strong><?= $iqEndYear ?></strong> ، وأدرك أنّ إخلالي بهذا الالتزام قد يحمّلني العطل والضرر الماليين المنوّه عنهما في المادة 30 من قانون الهيئة التعليمية تاريخ 1956/6/15 ( لا يحق لأي فرد من أفراد الهيئة التعليمية أن يترك العمل خلال السنة الدراسية وإلّا ترتّب عليه عطل وضرر يوازي ضعف رواتبه وملحقاتها عن المدة الباقية من السنة الدراسية ) .</p>
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
        <p>والمقيم في العنوان التالي : &nbsp; شتاءً : <?= $vb($addr, 170) ?> هاتف <?= $phone1 ?> &nbsp;&nbsp; صيفاً : <?= $blank(120) ?> هاتف <?= $vb($emp['phone2'] ?? '', 90) ?></p>
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
        <p>بعد تكراركم <?= e($subjectTxt) ?> ، وبحسب المادة 26 ( كما تعدّلت بموجب القانون رقم 44/87 تاريخ 21/11/1987 ) : إذا أهمل أحد أفراد الهيئة التعليمية والإدارية واجباته في حفظ النظام والتربية ، أو ارتكب مخالفةً لأحكام هذا القانون ، أو تغيّب دون عذر شرعي ، أو تصرّف تصرّفاً يضرّ بسمعة المدرسة أو بانتظام العمل فيها ، يتعرّض للعقوبات التي ينصّ عليها القانون .</p>
        <p style="text-align:center;font-weight:700">لذلك</p>
        <p>تلفت إدارة المدرسة انتباهكم إلى عدم تكرار ذلك ، كي لا تضطرّ آسفةً إلى تطبيق القوانين المرعية الإجراء بحقّكم .</p>
        <p>وشكراً .</p>
        <div style="display:flex;justify-content:space-between;margin-top:34px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:38px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <div style="margin-top:30px;border-top:1px dashed #999;padding-top:10px">
          <p>تبلّغت الكتاب الوارد مضمونه أعلاه ،</p>
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
            <?= $schoolPhone ? '<div dir="rtl"><small>هاتف : <span dir="ltr">' . e($schoolPhone) . '</span></small></div>' : '' ?>
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
        <p>بعد تكراركم <?= e($subjectTxt) ?> ، وبحسب المادة 26 ( كما تعدّلت بموجب القانون رقم 44/87 تاريخ 21/11/1987 ) : إذا أهمل أحد أفراد الهيئة التعليمية والإدارية واجباته في حفظ النظام والتربية ، أو ارتكب مخالفةً لأحكام هذا القانون ، أو تغيّب دون عذر شرعي ، أو تصرّف تصرّفاً يضرّ بسمعة المدرسة أو بانتظام العمل فيها ، يتعرّض للعقوبات التي ينصّ عليها القانون .</p>
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
