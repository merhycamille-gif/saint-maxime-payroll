<?php
/** 📄 (2026-10-16) أنواع الإفادات ومجموعاتها — مصدر واحد: صفحة الإفادات (pages/attestations.php) وباب «الإفادات» بالشريط العلوي (header.php).
 *  نُقلت كما هي من attestations.php بلا تغيير. */
function attestationTypes(): array {
    $ATT_TYPES = [
    'cnss'           => ['fr' => 'Attestation pour la CNSS',     'en' => 'Social Security Certificate',  'ar' => 'إفادة للضمان الاجتماعي'],
    'cnss_travail'   => ['fr' => 'Attestation de travail CNSS (officielle)','en' => 'CNSS Work Attestation (official)','ar' => 'إفادة عمل للضمان (نموذج رسمي)'],
    // 🛡️ (2026-10-27 «بدّي إفادة استخدام الأجير بالضمان تلقائياً: إذا عنده رقم يطلع نموذج اللي عنده رقم وإذا ما عنده يطلع نموذج اللي ما عنده»)
    //    خيار واحد بكل اللوائح؛ الصفحة تحوّله إلى cnss_hire_reg / cnss_hire_new (attestationHireTypes) حسب hasNssfNumber — مع كبسة تبديل للاستثناء
    'cnss_hire'      => ['fr' => "Déclaration d'embauche CNSS (officielle — automatique)", 'en' => 'CNSS Hiring Declaration (official, automatic)', 'ar' => 'إفادة استخدام أجير — الضمان (نموذج رسمي، تلقائي)'],
    'cnss_leave'     => ['fr' => 'Déclaration de cessation de travail (officielle)', 'en' => 'Leaving Declaration (official)', 'ar' => 'إعلام ترك أجير (نموذج رسمي)'],
    'mof_r3'         => ['fr' => 'Formulaire R3 — enregistrement d\'un salarié (Finances, officiel)', 'en' => 'R3 Form — Employee Registration (MoF, official)', 'ar' => 'طلب تسجيل مستخدم/أجير جديد — ر3 (المالية، نموذج رسمي)'],
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
    return $ATT_TYPES;
}
/** النوعان الداخليان لإفادة استخدام الأجير (لا يظهران باللوائح — يُختار أحدهما تلقائياً من رقم الضمان بالملف) */
function attestationHireTypes(): array {
    return [
        'cnss_hire_new'  => ['fr' => "Déclaration d'embauche — non immatriculé (CNSS 2AA, officielle)", 'en' => 'Hiring Declaration — not registered (official)', 'ar' => 'تصريح باستخدام أجير — لا يحمل رقم ضمان (نموذج رسمي)'],
        'cnss_hire_reg'  => ['fr' => "Déclaration d'embauche — déjà immatriculé (officielle)", 'en' => 'Hiring Declaration — registered (official)', 'ar' => 'إعلام استخدام أجير — يحمل رقم ضمان (نموذج رسمي)'],
    ];
}
function attestationGroups(): array {
    // كل أنواع الإفادات مقسّمة لأقسام واضحة (تُغطّى كل الأنواع + قسم «أخرى» احتياطاً لأي نوع جديد) — المصدر الواحد لملف الأستاذ ولصفحة الاختيار
$ATT_GROUPS = [
    'Salaire, travail et CNSS / راتب وعمل وضمان' => ['salaire','tadris','cnss','cnss_travail','cnss_hire','cnss_leave','mof_r3','afade_madrasiya','embassy','riaaya'],
    'Fin de service, démission et décharge / نهاية الخدمة والاستقالة وإبراء الذمّة' => ['anhaa_khedme','anhaa_mail','talab_istiqala','isqat_haq','baraa_zimma'],
    'Contrats, déclarations et avertissements / عقود وإقرارات وإنذارات' => ['aqd_taalim','iqrar','notice_school','notice_mail'],
];
    return $ATT_GROUPS;
}
