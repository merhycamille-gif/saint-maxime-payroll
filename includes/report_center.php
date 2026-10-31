<?php
/** 📚 (2026-10-16) أقسام مركز التقارير — مصدر واحد: صفحة التقارير (pages/reports.php) وأبواب الشريط العلوي (الضمان · صندوق التعويضات · المالية) بـheader.php.
 *  نُقلت كما هي من reports.php (2026-10-16) بلا أي تغيير بالمحتوى. */
function reportCenterCategories(): array {
    $OF = BASE_URL . 'pages/official_forms.php?form=';   // النماذج الرسمية
    $PG = BASE_URL . 'pages/';                            // صفحات أخرى
    $categories = [
        ['title'=>'كشوف الرواتب / Bulletins de salaire', 'icon'=>'fa-money-check-alt', 'color'=>'var(--primary)', 'tiles'=>[
            ['url'=>$PG.'annual_slip.php', 'icon'=>'fa-file-invoice-dollar', 'color'=>'var(--primary)', 'fr'=>'Bulletin annuel', 'ar'=>'بطاقة راتب الأستاذ'],
            // ✍️ (2026-08-25) «في شي هون مكرر — انت بدك تعرف شو المكرر»: بطاقة «Résumé mensuel /
            // كشف رواتب شهري» أُزيلت من المركز — أعمدتها كلها ضمن «État des salaires / كشف رواتب
            // كل الموظفين» (الأغنى: تنزيل عائلي + خاضع للضريبة + محسومات + توقيع). الرابط القديم
            // ?report=monthly_summary يبقى شغّالاً لمن يملكه ولفحوص regression.
            ['url'=>$OF.'salary_all', 'icon'=>'fa-table-list', 'color'=>'var(--success)', 'fr'=>'État des salaires', 'ar'=>'كشف رواتب كل الموظفين'],
            ['url'=>$OF.'salary_detail', 'icon'=>'fa-rectangle-list', 'color'=>'var(--primary)', 'fr'=>'Détail des salaires (par catégorie)', 'ar'=>'معلومات تفصيلية عن الراتب (جميع الأساتذة/الموظفون)'],
            ['url'=>'?report=annual_totals', 'icon'=>'fa-chart-pie', 'color'=>'var(--warning)', 'fr'=>'Totaux annuels', 'ar'=>'مجاميع سنوية'],
            ['url'=>$OF.'payment_list', 'icon'=>'fa-money-bill-transfer', 'color'=>'var(--success)', 'fr'=>'Liste de paiement', 'ar'=>'كشف الدفع'],
            ['url'=>$OF.'employer_cost', 'icon'=>'fa-sack-dollar', 'color'=>'var(--danger)', 'fr'=>'Coût employeur total', 'ar'=>'كلفة المؤسسة الإجمالية'],
            ['url'=>$OF.'full_register', 'icon'=>'fa-table-cells-large', 'color'=>'var(--primary)', 'fr'=>'Registre complet', 'ar'=>'كشف شامل بالكلفة (جميع الأساتذة)'],
        ]],
        // 🏛️🖼️ (2026-10-18) gov-ic = شعار الجهة الرسمية محلّ الأيقونة (الضمان/المالية/الصندوق — app.css قسم 500)
        ['title'=>'الضمان الاجتماعي / CNSS', 'icon'=>'gov-ic gov-cnss', 'color'=>'var(--info)', 'tiles'=>[
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
            ['url'=>$OF.'cnss_eos_doc', 'icon'=>'fa-file-signature', 'color'=>'var(--danger)', 'fr'=>'Liquidation FDS — document (p1)', 'ar'=>'مستند تصفية تعويض نهاية خدمة'],
            ['url'=>$OF.'cnss_eos_2y', 'icon'=>'fa-table-list', 'color'=>'var(--danger)', 'fr'=>'FDS — salaires 2 dernières années (p2)', 'ar'=>'نهاية الخدمة — جدول أجور السنتين الأخيرتين'],
            ['url'=>$OF.'cnss_eos_annual', 'icon'=>'fa-table', 'color'=>'var(--danger)', 'fr'=>'FDS — salaires annuels (p3)', 'ar'=>'نهاية الخدمة — جدول بالأجور السنوية'],
            ['url'=>$OF.'cnss_contrib_monthly', 'icon'=>'fa-table', 'color'=>'var(--info)', 'fr'=>'Déclaration mensuelle', 'ar'=>'تصريح الضمان الشهري'],
            ['url'=>$OF.'cnss_contrib_annual', 'icon'=>'fa-table-cells', 'color'=>'var(--primary)', 'fr'=>'Déclaration trimestrielle', 'ar'=>'تصريح الضمان الفصلي'],
            ['url'=>$OF.'cnss_taswiya', 'icon'=>'fa-file-shield', 'color'=>'var(--danger)', 'fr'=>'Régularisation annuelle (officielle)', 'ar'=>'تسوية الضمان السنوية — طبق الأصل'],
        ]],
        ['title'=>'ضريبة الدخل / Impôt sur le revenu', 'icon'=>'gov-ic gov-mof', 'color'=>'var(--danger)', 'tiles'=>[
            ['url'=>'?report=tax_summary', 'icon'=>'fa-list', 'color'=>'var(--danger)', 'fr'=>'Impôt mensuel', 'ar'=>'كشف ضريبة شهري'],
            ['url'=>$OF.'tax_register', 'icon'=>'fa-id-card-clip', 'color'=>'var(--gold)', 'fr'=>'R3 — Enregistrement', 'ar'=>'ر3 تسجيل أجير'],
            ['url'=>$OF.'tax_r4', 'icon'=>'fa-address-book', 'color'=>'var(--info)', 'fr'=>'R4 — Infos + ayants droit', 'ar'=>'ر4 معلومات + العيال'],
            ['url'=>$OF.'tax_r5', 'icon'=>'fa-file-lines', 'color'=>'var(--primary)', 'fr'=>'R5 — Décl. annuelle', 'ar'=>'ر5 تصريح سنوي'],
            ['url'=>$OF.'tax_r6', 'icon'=>'fa-file-lines', 'color'=>'var(--info)', 'fr'=>'R6 — Kachf individuel', 'ar'=>'ر6 كشف سنوي إفرادي'],
            ['url'=>$OF.'tax_r6t', 'icon'=>'fa-file-pen', 'color'=>'var(--warning)', 'fr'=>'R6/T — Rectificatif', 'ar'=>'ر6/ت تعديل'],
            ['url'=>$OF.'tax_r7', 'icon'=>'fa-user-xmark', 'color'=>'var(--danger)', 'fr'=>'R7 — Départs', 'ar'=>'ر7 تاركو العمل'],
            ['url'=>$OF.'tax_r10', 'icon'=>'fa-file-lines', 'color'=>'var(--success)', 'fr'=>'R10 — Décl. périodique', 'ar'=>'ر10 بيان دوري'],
            ['url'=>$OF.'tax_emp_report', 'icon'=>'fa-table-list', 'color'=>'var(--danger)', 'fr'=>'Impôt sur le revenu — détaillé', 'ar'=>'تقرير ضريبة الدخل - الأستاذ/الموظف'],
        ]],
        ['title'=>'صندوق التعليم الخاص / Caisse EOC', 'icon'=>'gov-ic gov-eoc', 'color'=>'var(--gold)', 'tiles'=>[
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
            ['url'=>'?report=new_teachers', 'icon'=>'fa-user-plus', 'color'=>'var(--warning)', 'fr'=>'Nouveaux enseignants', 'ar'=>'الأساتذة الجدد — كامل الملف + مين مكمّل ملفه المالي'], // 🆕 2026-09-23
            // 📅 (2026-10-18) الافتراضي بالتقرير = 1/10 من السنة المعروضة (reports.php) — الأساتذة والموظفون الداخلون إلى المدرسة بهذا التاريخ
            ['url'=>'?report=titularized&tmode=hire', 'icon'=>'fa-door-open', 'color'=>'var(--success)', 'fr'=>"Entrés à l'école le 1/10 (année affichée, date modifiable)", 'ar'=>'الأساتذة والموظفون الداخلون إلى المدرسة بتاريخ 1/10 من السنة المعروضة (التاريخ قابل للتغيير)'],
            ['url'=>'?report=titularized', 'icon'=>'fa-user-check', 'color'=>'var(--primary)', 'fr'=>'Entrés au cadre le 1/10 (année affichée, date modifiable)', 'ar'=>'الداخلون في الملاك بتاريخ 1/10 من السنة المعروضة (التاريخ قابل للتغيير)'],
            // 🚪 (2026-10-18 «وملف اللي تركوا بنفس السنة») التاركون خلال السنة المعروضة (صفحة left_teachers بفلتر السنة)
            ['url'=>$PG.'left_teachers.php', 'icon'=>'fa-user-slash', 'color'=>'var(--danger)', 'fr'=>"Départs — enseignants et employés partis (année affichée)", 'ar'=>'الأساتذة والموظفون التاركون خلال السنة المعروضة'],
            ['url'=>$PG.'employee_history.php', 'icon'=>'fa-user-clock', 'color'=>'var(--warning)', 'fr'=>'Dossier enseignant', 'ar'=>'سيرة الأستاذ'],
            ['url'=>$PG.'attestations.php', 'icon'=>'fa-file-signature', 'color'=>'var(--gold)', 'fr'=>'Attestations', 'ar'=>'إفادات'],
            ['url'=>$OF.'staff_stats', 'icon'=>'fa-chart-simple', 'color'=>'var(--info)', 'fr'=>'Statistiques du personnel', 'ar'=>'إحصاءات الموظفين'],
            // ✍️ (2026-08-25) «وهون شو المكرر»: بطاقة «Infos générales / معلومات عامة عن الموظفين»
            // أُزيلت — أعمدتها الثمانية كلها ضمن «لائحة الموظفين» (منتقي الأعمدة يغطيها وأكثر).
            // النموذج نفسه (p13 مطابق Ecole.exe) باقٍ شغّالاً بالرابط المباشر ?form=general_info.
        ]],
        ['title'=>'فروقات وتقرير عام / Différences & Général', 'icon'=>'fa-scale-balanced', 'color'=>'var(--warning)', 'tiles'=>[
            ['url'=>$OF.'differences', 'icon'=>'fa-right-left', 'color'=>'var(--warning)', 'fr'=>'Différences / Augmentation', 'ar'=>'فروقات / زيادة'],
            ['url'=>$OF.'general_report', 'icon'=>'fa-chart-column', 'color'=>'var(--primary)', 'fr'=>'Rapport général', 'ar'=>'تقرير عام بالمجاميع'],
        ]],
    ];
    // 🔒 (2026-10-31 «ولا تقرير يقدر يشتغلو فيه بس يشوفو») حساب المدرسة: لا بطاقات تقود لصفحات خارج صلاحياته (كانت تطرده للوحة)
    if (isViewer()) {
        foreach ($categories as &$c) {
            $c['tiles'] = array_values(array_filter($c['tiles'], function ($t) { return !preg_match('~pages/([a-z0-9_]+\.php)~', (string)($t['url'] ?? ''), $m) || viewerCanSeePage($m[1]); }));
        }
        unset($c);
        $categories = array_values(array_filter($categories, fn($c) => !empty($c['tiles'])));
    }
    return $categories;
}
