<?php
/**
 * 📗 إكسل الرواتب والأجر الإضافي للمتعاقدين والموظفين — دفعة وحدة (2026-09-12)
 * طلبه: «بدي ملف إكسل فيه أسماء الأساتذة الموجودين المتعاقد أو الموظف، ومحلّ أنا حطّ الراتب والأجر الإضافي وعدد الأيام
 * بالأسبوع — بحطّهن بإكسل ورا بعضهن وانت بترجع بتوزّعهن على ملفاتهم، أسهل عليّ من ما فوت على كل ملف. خليها أوبسيون زيادة».
 *
 *  - ينزّل ملف xlsx (PHP خالص — ZipArchive) لمدرسة وسنة: سطر لكل متعاقد/موظف فاعل، الخانات معبّأة بقيمه الحالية
 *    (الراتب $ أو ل.ل · الأجر الإضافي ٪ / مبلغ ل.ل / مبلغ $ · عدد الأيام بالأسبوع). يعدّل ما يريد ويرفعه.
 *  - الرفع يقرأ الملف (ZipArchive + SimpleXML) ويعرض **معاينة الفروقات** (قديم ← جديد) لكل شخص، ثم زرّ «طبّق» يوزّع:
 *    الراتب → salary_input_mode + base_salary_usd/contract_salary_lbp · الأجر الإضافي → بند prime_fixe للسنة (كل السنة، يستبدل
 *    الحالي؛ 0 = شيله) · الأيام → days_per_week · ثم recalcEmployeeYear (المحرّك = المصدر الواحد، فالبطاقة السنوية وكل التقارير نفس الأرقام).
 *  - فاضي = لا تغيير. يحترم قفل السنة. الملاك غير مشمولين (رواتبهم بالسلسلة).
 *
 * 🗂️ (2026-10-21 «زيد كل شي موجود بملف الموظف على هيدا الإكسل، أحلى ما أبقى فوت على ملف كل موظف وعدّل»):
 *    صار الإكسل = ملف الموظف كاملاً: الأسماء (عربي/فرنسي) والأم والجنس والولادة والجنسية والسجلّ والوضع العائلي والأولاد
 *    والعنوان والهواتف والإيميل والشهادة والاختصاص والمواد والمرحلة والصفوف ونوع الوظيفة وعدد الساعات والأيام والأرقام الرسمية
 *    (ضمان/مالية/صندوق) والضريبة والضمان (نعم/لا) + الراتب والأجر الإضافي + الملاحظات. المصدر الواحد: excelSalariesFields().
 *    الفئة تُعدَّل بين متعاقد ↔ موظف فقط؛ أي تحويل من/إلى ملاك = من ملف الأستاذ (الدرجات والسلسلة وقرار الترسيم).
 *    القواعد: فاضي = لا تغيير · «-» = امسح الخانة (نصوص/تواريخ/قوائم) · الخانات الرمادية لا تُقرأ (حسب الفئة).
 * الأعمدة ثابتة بالترتيب — المصدر الواحد excelSalariesColumns() المشتقّة من excelSalariesFields().
 */

/** حرف العمود من رقمه (1 ⇒ A … 27 ⇒ AA) — الإكسل صار أكثر من 26 عموداً */
function excelSalariesColLetter(int $i): string { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }

/** المراحل — المصدر الواحد (عربي/فرنسي + مرادفات للقراءة من الإكسل) */
function excelSalariesNiveaux(): array {
    return ['maternelle'    => ['ar' => 'حضانة',   'fr' => 'Maternelle',    'syn' => ['حضانه', 'روضة', 'روضه', 'maternel', 'ps', 'ms', 'gs']],
            'primaire'      => ['ar' => 'ابتدائي', 'fr' => 'Primaire',      'syn' => ['إبتدائي', 'ابتدائى', 'أساسي', 'primary']],
            'intermediaire' => ['ar' => 'متوسط',   'fr' => 'Intermédiaire', 'syn' => ['متوسطة', 'تكميلي', 'تكميلية', 'intermediaire', 'complémentaire', 'complementaire', 'collège', 'college']],
            'secondaire'    => ['ar' => 'ثانوي',   'fr' => 'Secondaire',    'syn' => ['ثانوية', 'ثانوى', 'secondary']]];
}
/** الصفوف من class_levels (مرّة بالطلب) */
function excelSalariesClasses(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try { foreach ($db->query("SELECT id, name, name_fr FROM class_levels WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $c) $cache[(int)$c['id']] = ['ar' => trim((string)$c['name']), 'fr' => trim((string)$c['name_fr'])]; }
    catch (Throwable $e) {}
    return $cache;
}
/** الشهادات من diploma_starting_grades (code ⇒ عربي/فرنسي) */
function excelSalariesDiplomas(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try { foreach ($db->query("SELECT diploma_code, diploma_name_ar, diploma_name_fr FROM diploma_starting_grades")->fetchAll(PDO::FETCH_ASSOC) as $d) $cache[$d['diploma_code']] = ['ar' => (string)$d['diploma_name_ar'], 'fr' => (string)$d['diploma_name_fr']]; }
    catch (Throwable $e) {}
    return $cache;
}

/**
 * 🗂️ سجلّ خانات الإكسل بالترتيب — المصدر الواحد (2026-10-21).
 * type: ro (للعرض فقط) · text · date (يوم/شهر/سنة) · int · float · bool (نعم/لا) · enum (قائمة؛ free=1 يقبل نصاً حرّاً) ·
 *       niveau (مراحل بفاصلة) · classes (صفوف بفاصلة) · cat (الفئة) · special (الراتب/الإضافي/الأيام — منطقها الخاص أدناه)
 * for: الفئات التي تُقرأ الخانة لها (null = الكل) · recalc: تغييرها يستدعي إعادة حساب الراتب · what: اسمها بالمعاينة
 */
function excelSalariesFields(): array {
    static $f = null;
    if ($f !== null) return $f;
    $T = 'enseignant_titulaire'; $C = 'enseignant_contractuel'; $E = 'employe';
    $f = [
        'id'       => ['type' => 'ro', 'label' => 'رقم الملف (لا تغيّره)', 'w' => 11],
        'cat'      => ['type' => 'cat', 'label' => 'الفئة (ملاك / متعاقد / موظف)', 'w' => 13, 'what' => 'الفئة', 'recalc' => true],
        'name'     => ['type' => 'ro', 'label' => 'الاسم الكامل (للعرض)', 'w' => 30],
        'first_name_ar'  => ['type' => 'text', 'db' => 'first_name_ar',  'label' => 'الاسم (عربي)',   'w' => 14, 'what' => 'الاسم (عربي)'],
        'father_name_ar' => ['type' => 'text', 'db' => 'father_name_ar', 'label' => 'اسم الأب (عربي)', 'w' => 14, 'what' => 'اسم الأب (عربي)'],
        'last_name_ar'   => ['type' => 'text', 'db' => 'last_name_ar',   'label' => 'الشهرة (عربي)',  'w' => 14, 'what' => 'الشهرة (عربي)'],
        'first_name_fr'  => ['type' => 'text', 'db' => 'first_name_fr',  'label' => 'Prénom (FR)',    'w' => 14, 'what' => 'الاسم (فرنسي)'],
        'father_name_fr' => ['type' => 'text', 'db' => 'father_name_fr', 'label' => 'Père (FR)',      'w' => 14, 'what' => 'اسم الأب (فرنسي)'],
        'last_name_fr'   => ['type' => 'text', 'db' => 'last_name_fr',   'label' => 'Nom (FR)',       'w' => 14, 'what' => 'الشهرة (فرنسي)'],
        'mother_first_name' => ['type' => 'text', 'db' => 'mother_first_name', 'label' => 'اسم الأم', 'w' => 13, 'what' => 'اسم الأم'],
        'mother_last_name'  => ['type' => 'text', 'db' => 'mother_last_name',  'label' => 'شهرة الأم', 'w' => 13, 'what' => 'شهرة الأم'],
        'gender'     => ['type' => 'enum', 'db' => 'gender', 'label' => 'الجنس (ذكر / أنثى)', 'w' => 10, 'what' => 'الجنس',
                         'opts' => ['m' => ['ar' => 'ذكر', 'fr' => 'Masculin', 'syn' => ['m', 'homme', 'male', 'رجل']], 'f' => ['ar' => 'أنثى', 'fr' => 'Féminin', 'syn' => ['f', 'انثى', 'femme', 'female', 'feminin', 'امرأة']], 'd' => ['ar' => 'آخر', 'fr' => 'Autre', 'syn' => ['d', 'اخر']]]],
        'birth_date'  => ['type' => 'date', 'db' => 'birth_date', 'label' => 'تاريخ الولادة (يوم/شهر/سنة)', 'w' => 14, 'what' => 'تاريخ الولادة', 'recalc' => true],
        'birth_place' => ['type' => 'text', 'db' => 'birth_place', 'label' => 'محلّ الولادة', 'w' => 13, 'what' => 'محلّ الولادة'],
        'nationality' => ['type' => 'enum', 'db' => 'nationality', 'label' => 'الجنسية', 'w' => 11, 'what' => 'الجنسية', 'free' => true,
                          'opts' => ['lebanese' => ['ar' => 'لبناني', 'fr' => 'Libanais', 'syn' => ['لبنانية', 'لبنانيه', 'libanaise', 'liban', 'lebanon']], 'syrian' => ['ar' => 'سوري', 'fr' => 'Syrien', 'syn' => ['سورية', 'سوريه', 'syrienne', 'syrie', 'syria']],
                                     'palestinian' => ['ar' => 'فلسطيني', 'fr' => 'Palestinien', 'syn' => ['فلسطينية', 'فلسطينيه', 'palestinienne', 'palestine']], 'other' => ['ar' => 'أخرى', 'fr' => 'Autre', 'syn' => ['اخرى', 'غير']]]],
        'civil_registry_number' => ['type' => 'text', 'db' => 'civil_registry_number', 'label' => 'رقم السجلّ', 'w' => 11, 'what' => 'رقم السجلّ'],
        'civil_registry_place'  => ['type' => 'text', 'db' => 'civil_registry_place',  'label' => 'محلّ السجلّ', 'w' => 12, 'what' => 'محلّ السجلّ'],
        'marital'  => ['type' => 'enum', 'db' => '', 'label' => 'الوضع العائلي (أعزب / متزوج / أرمل / مطلّق)', 'w' => 14, 'what' => 'الوضع العائلي', 'recalc' => true,
                       'opts' => ['celibataire' => ['ar' => 'أعزب', 'fr' => 'Célibataire', 'syn' => ['اعزب', 'عازب', 'عزباء', 'عازبة', 'celibataire']], 'marie' => ['ar' => 'متزوج', 'fr' => 'Marié', 'syn' => ['متزوجة', 'متأهل', 'متأهلة', 'متاهل', 'mariée', 'marie', 'mariee']],
                                  'veuf' => ['ar' => 'أرمل', 'fr' => 'Veuf', 'syn' => ['ارمل', 'أرملة', 'ارملة', 'veuve']], 'divorce' => ['ar' => 'مطلّق', 'fr' => 'Divorcé', 'syn' => ['مطلق', 'مطلقة', 'مطلّقة', 'divorcée', 'divorce', 'divorcee']]]],
        'number_of_children' => ['type' => 'int', 'db' => 'number_of_children', 'label' => 'عدد الأولاد (دون 18)', 'w' => 10, 'what' => 'عدد الأولاد', 'min' => 0, 'max' => 20, 'recalc' => true],
        'spouse_works' => ['type' => 'bool', 'db' => 'spouse_works', 'label' => 'الزوج/الزوجة يعمل؟ (نعم / لا)', 'w' => 12, 'what' => 'الزوج/الزوجة يعمل', 'recalc' => true],
        'gouvernorat' => ['type' => 'text', 'db' => 'gouvernorat', 'label' => 'المحافظة', 'w' => 11, 'what' => 'المحافظة'],
        'district'    => ['type' => 'text', 'db' => 'district',    'label' => 'القضاء',   'w' => 11, 'what' => 'القضاء'],
        'ville'       => ['type' => 'text', 'db' => 'ville',       'label' => 'البلدة',   'w' => 12, 'what' => 'البلدة'],
        'quartier'    => ['type' => 'text', 'db' => 'quartier',    'label' => 'الحيّ',    'w' => 11, 'what' => 'الحيّ'],
        'rue'         => ['type' => 'text', 'db' => 'rue',         'label' => 'الشارع',   'w' => 11, 'what' => 'الشارع'],
        'immeuble'    => ['type' => 'text', 'db' => 'immeuble',    'label' => 'المبنى',   'w' => 11, 'what' => 'المبنى'],
        'etage'       => ['type' => 'text', 'db' => 'etage',       'label' => 'الطابق',   'w' => 8,  'what' => 'الطابق'],
        'phone1'      => ['type' => 'text', 'db' => 'phone1', 'label' => 'هاتف 1', 'w' => 12, 'what' => 'هاتف 1', 'digits' => true],
        'phone2'      => ['type' => 'text', 'db' => 'phone2', 'label' => 'هاتف 2', 'w' => 12, 'what' => 'هاتف 2', 'digits' => true],
        'email'       => ['type' => 'text', 'db' => 'email',  'label' => 'البريد الإلكتروني', 'w' => 20, 'what' => 'البريد الإلكتروني'],
        'diploma'     => ['type' => 'enum', 'db' => 'diploma', 'label' => 'الشهادة (متعاقد/موظف — الملاك من ملفه)', 'w' => 16, 'what' => 'الشهادة', 'for' => [$C, $E], 'opts' => '__diplomas__'],
        'specialization' => ['type' => 'text', 'db' => 'specialization', 'label' => 'الاختصاص', 'w' => 14, 'what' => 'الاختصاص'],
        'subjects_taught' => ['type' => 'text', 'db' => 'subjects_taught', 'label' => 'المواد التي يدرّسها', 'w' => 18, 'what' => 'المواد', 'for' => [$T, $C]],
        'niveau'      => ['type' => 'niveau', 'db' => 'niveau_scolaire', 'label' => 'المرحلة (حضانة، ابتدائي، متوسط، ثانوي — بفاصلة)', 'w' => 20, 'what' => 'المرحلة', 'for' => [$T, $C]],
        'classes'     => ['type' => 'classes', 'db' => 'classes_taught', 'label' => 'الصفوف (PS, EB1, Secondaire 2 … — بفاصلة)', 'w' => 22, 'what' => 'الصفوف', 'for' => [$T, $C]],
        'job_title'   => ['type' => 'enum', 'db' => 'job_title', 'label' => 'نوع الوظيفة (للموظف الإداري)', 'w' => 15, 'what' => 'نوع الوظيفة', 'for' => [$E], 'free' => true, 'opts' => '__jobs__'],
        'hours'       => ['type' => 'float', 'db' => 'hours_per_week', 'label' => 'عدد الساعات بالأسبوع', 'w' => 11, 'what' => 'الساعات بالأسبوع', 'min' => 0, 'max' => 60],
        'days'        => ['type' => 'special', 'label' => 'عدد الأيام بالأسبوع', 'w' => 11],
        'nssf_number' => ['type' => 'text', 'db' => 'nssf_number', 'label' => 'رقم الضمان', 'w' => 13, 'what' => 'رقم الضمان', 'official' => true],
        'finance_ministry_number' => ['type' => 'text', 'db' => 'finance_ministry_number', 'label' => 'رقم المالية', 'w' => 13, 'what' => 'رقم المالية', 'official' => true],
        'caisse_number' => ['type' => 'text', 'db' => 'caisse_number', 'label' => 'رقم صندوق التعويضات', 'w' => 13, 'what' => 'رقم الصندوق', 'official' => true],
        'tax_subject'  => ['type' => 'bool', 'db' => 'tax_subject',  'label' => 'خاضع للضريبة؟ (نعم / لا)', 'w' => 11, 'what' => 'خاضع للضريبة', 'recalc' => true],
        'cnss_subject' => ['type' => 'bool', 'db' => 'cnss_subject', 'label' => 'خاضع للضمان؟ (نعم / لا)', 'w' => 11, 'what' => 'خاضع للضمان', 'recalc' => true],
        'sal_usd' => ['type' => 'special', 'label' => 'الراتب الأساسي $ (بالشهر)', 'w' => 16],
        'sal_lbp' => ['type' => 'special', 'label' => 'الراتب الأساسي ل.ل (بالشهر)', 'w' => 20],
        'pct'     => ['type' => 'special', 'label' => 'الأجر الإضافي ٪ من الأساس', 'w' => 16],
        'amt_lbp' => ['type' => 'special', 'label' => 'الأجر الإضافي — الجزء بالليرة ل.ل (بالشهر)', 'w' => 22],
        'amt_usd' => ['type' => 'special', 'label' => 'الأجر الإضافي — الجزء بالدولار $ (بالشهر)', 'w' => 20],
        'from'    => ['type' => 'special', 'label' => 'الإضافي من شهر (اسم أو رقم الشهر، فاضي = تشرين الأول)', 'w' => 16],
        'to'      => ['type' => 'special', 'label' => 'الإضافي إلى شهر (فاضي = أيلول)', 'w' => 16],
        'notes'   => ['type' => 'text', 'db' => 'notes', 'label' => 'ملاحظات بملف الموظف', 'w' => 24, 'what' => 'الملاحظات'],
        'note'    => ['type' => 'ro', 'label' => 'تنبيه من البرنامج (لا يُقرأ)', 'w' => 26],
    ];
    return $f;
}
/** الأعمدة الثابتة key ⇒ [حرف، عنوان، عرض] — مشتقّة من السجلّ */
function excelSalariesColumns(): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = []; $i = 0;
    foreach (excelSalariesFields() as $k => $f) { $i++; $cols[$k] = [excelSalariesColLetter($i), $f['label'], $f['w']]; }
    return $cols;
}
/** خيارات قائمة (enum) لخانة — الشهادات ونوع الوظيفة تُقرآن من القاعدة/الدالة */
function excelSalariesOpts(PDO $db, array $f): array {
    $o = $f['opts'] ?? [];
    if ($o === '__diplomas__') return excelSalariesDiplomas($db);
    if ($o === '__jobs__') { $r = []; foreach (jobTitleOptions() as $k => $v) $r[$k] = ['ar' => $v['ar'], 'fr' => $v['fr']]; return $r; }
    return is_array($o) ? $o : [];
}
/** تطبيع نصّ للمقارنة: صغير، بلا مسافات زائدة، همزات/تاء مربوطة موحّدة */
function excelSalariesNorm(string $s): string {
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    $s = str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ّ', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ْ'], ['ا', 'ا', 'ا', 'ه', 'ي', '', '', '', '', '', '', '', ''], $s);
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u']);
    return $s;
}
/** مطابقة قيمة مكتوبة بالإكسل مع خيارات قائمة ⇒ الكود أو null */
function excelSalariesEnumMatch(string $raw, array $opts): ?string {
    $n = excelSalariesNorm($raw); if ($n === '') return null;
    foreach ($opts as $code => $o) {
        $cands = array_merge([(string)$code, (string)($o['ar'] ?? ''), (string)($o['fr'] ?? '')], (array)($o['syn'] ?? []));
        foreach ($cands as $c) if ($c !== '' && excelSalariesNorm((string)$c) === $n) return (string)$code;
    }
    return null;
}
/** تقسيم خانة متعدّدة (مراحل/صفوف) إلى عناصر: فواصل عربية/لاتينية، +، /، ؛ */
function excelSalariesSplit(string $raw): array { return array_values(array_filter(array_map('trim', preg_split('/[,،;؛+\/|\n]+/u', $raw)), fn($x) => $x !== '')); }
/** نعم/لا من الإكسل ⇒ 1/0 أو null */
function excelSalariesBool(string $raw): ?int {
    $n = excelSalariesNorm($raw); if ($n === '') return null;
    if (in_array($n, ['نعم', 'اي', 'ايه', 'oui', 'yes', 'y', 'o', '1', 'true', 'x', '✓', '✔', 'خاضع', 'يعمل', 'تعمل'], true)) return 1;
    if (in_array($n, ['لا', 'كلا', 'non', 'no', 'n', '0', 'false', '-', 'غير خاضع', 'لا يعمل', 'لا تعمل', 'معفى', 'معفي'], true)) return 0;
    return null;
}
/** نوع الوضع العائلي من قيمة social_status المخزّنة (marie_2_enfants ⇒ marie) */
function excelSalariesMaritalKind(string $ss): string { return preg_match('/^(marie|veuf|divorce)/', $ss, $m) ? $m[1] : 'celibataire'; }

/** 🩹 المرحلة «حضانة» بالقاعدة: schema.sql القديم بلا maternelle — تُضاف ذاتياً (بلا migrate) قبل أي كتابة */
function excelSalariesEnsureNiveau(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $t = (string)($db->query("SHOW COLUMNS FROM employees WHERE Field = 'niveau_scolaire'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
        if ($t !== '' && stripos($t, 'maternelle') === false) $db->exec("ALTER TABLE employees MODIFY niveau_scolaire SET('maternelle','primaire','intermediaire','secondaire') NULL DEFAULT NULL");
    } catch (Throwable $e) {}
}

/** شهر من خانة إكسل: رقم 1-12 أو اسم عربي/فرنسي → int أو null (فاضي/غير مفهوم). */
function excelSalariesMonth($v): ?int {
    $v = trim((string)$v); if ($v === '') return null;
    if (function_exists('arabicDigitsFr')) $v = arabicDigitsFr($v);
    if (preg_match('/^\d{1,2}(\.0+)?$/', $v)) { $m = (int)$v; return ($m >= 1 && $m <= 12) ? $m : null; }
    for ($m = 1; $m <= 12; $m++) { if (mb_strtolower(monthName($m, 'ar')) === mb_strtolower($v) || mb_strtolower(monthName($m, 'fr')) === mb_strtolower($v)) return $m; }
    return null;
}
function excelSalariesCatLabel(string $t): string { return $t === 'enseignant_contractuel' ? 'متعاقد' : ($t === 'employe' ? 'موظف' : 'ملاك'); }
/** الفئة المكتوبة بالإكسل ⇒ employee_type أو null */
function excelSalariesCatParse(string $raw): ?string {
    return excelSalariesEnumMatch($raw, ['enseignant_titulaire' => ['ar' => 'ملاك', 'fr' => 'Titulaire', 'syn' => ['أستاذ ملاك', 'استاذ ملاك', 'cadre', 'enseignant titulaire']],
                                         'enseignant_contractuel' => ['ar' => 'متعاقد', 'fr' => 'Contractuel', 'syn' => ['متعاقدة', 'أستاذ متعاقد', 'استاذ متعاقد', 'contractuelle', 'enseignant contractuel']],
                                         'employe' => ['ar' => 'موظف', 'fr' => 'Employé', 'syn' => ['موظفة', 'موظف إداري', 'موظف اداري', 'إداري', 'اداري', 'employe', 'employé administratif', 'administratif']]]);
}
/** 🧑‍🏫 (2026-09-13 «بدك تعطيني خيار الملاك لوحدون أو المتعاقد أو الموظف أو كلهن») خيارات الفئة بالإكسل — المصدر الواحد */
function excelSalariesCats(): array {
    return ['all' => ['label' => 'الكل (ملاك + متعاقدون + موظفون)', 'types' => ['enseignant_titulaire','enseignant_contractuel','employe']],
            'titulaire' => ['label' => 'الملاك لوحدهم', 'types' => ['enseignant_titulaire']],
            'contractuel' => ['label' => 'المتعاقدون لوحدهم', 'types' => ['enseignant_contractuel']],
            'employe' => ['label' => 'الموظفون لوحدهم', 'types' => ['employe']]];
}
function excelSalariesCat($v): string { $v = (string)$v; return isset(excelSalariesCats()[$v]) ? $v : 'all'; }
/** 🧾 (2026-09-13 «كمان بدك تحط ميزة خاضع للضريبة / لا يخضع / الكل») فلتر الضريبة — يقرأ e.tax_subject من ملف الموظف كفلتر التقارير */
function excelSalariesTaxes(): array { return ['' => 'الكل (خاضع وغير خاضع)', '1' => 'الخاضعون للضريبة فقط', '0' => 'غير الخاضعين للضريبة فقط']; }
function excelSalariesTax($v): string { $v = (string)$v; return in_array($v, ['1', '0'], true) ? $v : ''; }
/** وصف الفلترين للعنوان: «الملاك لوحدهم — الخاضعون للضريبة فقط» */
function excelSalariesFilterLabel(string $cat, string $tax): string { $l = excelSalariesCats()[excelSalariesCat($cat)]['label']; $t = excelSalariesTax($tax); return $l . ($t !== '' ? ' — ' . excelSalariesTaxes()[$t] : ''); }

/** هل الخانة تُقرأ/تُعدَّل لهذه الفئة؟ (الملاك: راتبه بالسلسلة؛ الموظف الإداري: بلا مواد/مرحلة/صفوف؛ الأستاذ: بلا نوع وظيفة) */
function excelSalariesEditable(string $key, string $type): bool {
    $f = excelSalariesFields()[$key] ?? null; if (!$f || $f['type'] === 'ro') return false;
    if (in_array($key, ['sal_usd', 'sal_lbp'], true)) return $type !== 'enseignant_titulaire';
    if (!empty($f['for'])) return in_array($type, $f['for'], true);
    return true;
}
/** قيمة خانة للعرض بالإكسل من صفّ الموظف (نفس الصيغة التي تُقرأ عند الرفع ⇒ تنزيل ثم رفع بلا تعديل = صفر فروقات) */
function excelSalariesCellValue(PDO $db, string $key, array $e) {
    $f = excelSalariesFields()[$key]; $db1 = $f['db'] ?? '';
    switch ($f['type']) {
        case 'text': $v = trim((string)($e[$db1] ?? '')); return $v === '' ? null : $v;
        case 'date': $v = displayDMY($e[$db1] ?? ''); return $v === '' ? null : $v;
        case 'int': return $e[$db1] === null || $e[$db1] === '' ? null : (int)$e[$db1];
        case 'float': return $e[$db1] === null || $e[$db1] === '' ? null : (float)$e[$db1];
        case 'bool': return $e[$db1] === null || $e[$db1] === '' ? null : ((int)$e[$db1] ? 'نعم' : 'لا');
        case 'enum':
            $raw = $key === 'marital' ? excelSalariesMaritalKind((string)($e['social_status'] ?? '')) : trim((string)($e[$db1] ?? ''));
            if ($raw === '') return null;
            $o = excelSalariesOpts($db, $f);
            if (!isset($o[$raw])) { $m = excelSalariesEnumMatch($raw, $o); if ($m !== null) $raw = $m; } // قيمة قديمة بصيغة أخرى (Syrie/لبنانية) ⇒ تُعرَض موحّدة
            return isset($o[$raw]) ? ($o[$raw]['ar'] ?: $o[$raw]['fr']) : $raw;
        case 'niveau':
            $nv = excelSalariesNiveaux(); $out = [];
            foreach (array_filter(array_map('trim', explode(',', (string)($e[$db1] ?? '')))) as $k) $out[] = $nv[$k]['ar'] ?? $k;
            return $out ? implode('، ', $out) : null;
        case 'classes':
            $cl = excelSalariesClasses($db); $out = [];
            foreach (array_filter(array_map('intval', explode(',', (string)($e[$db1] ?? '')))) as $id) $out[] = isset($cl[$id]) ? ($cl[$id]['fr'] ?: $cl[$id]['ar']) : (string)$id;
            return $out ? implode(', ', $out) : null;
    }
    return null;
}

/** الفاعلون بالمدرسة (غير التاركين) حسب الفئة المختارة مع قيمهم الحالية للسنة. الملاك: راتبهم بالسلسلة (خانتا الراتب لا تُعدَّلان)، إضافيهم وأيامهم نعم. */
// 📅 «أستاذ السنة» (2026-09-14 «وقت اللي بختار متعاقد أو ملاك أو الثنين يكونوا أساتذة نفس السنة بس مش أساتذة كل البرنامج»):
//    فتح السنة ينسخ كل مَن حالته «فاعل» بلا تاريخ ترك — حتى مَن لم يُدفَع له شيء بالسنة السابقة (229 بـ2026-2027) — فصار الإكسل
//    يعرض أساتذة كل البرنامج. المصدر الواحد لعضوية السنة بالإكسل (rows/build/diff/apply عبر rows):
//    له صفوف بالسنة المطلوبة و(راتب فعلي بالسنة السابقة — مستمرّ | دخوله منذ بداية السنة السابقة — جديد |
//    راتب فعلي بالسنة نفسها بشرط أنّ السنة بدأت فعلاً (1/10) أو لا راتب أقدم من السنة السابقة — لا نسخة راكدة من سنين قديمة)،
//    أو جديد كلياً بلا أي صفّ بأي سنة ودخوله قبل نهاية السنة (لا مَن عُيِّن لسنة لاحقة).
function excelSalariesInYearSql(string $sy): string {
    $nz = '(m.base_plus_echelon_lbp > 0 OR m.net_salary_lbp > 0 OR m.total_due_lbp > 0)';
    return "((EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = ?)
              AND (EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = ? AND $nz)
                   OR e.hire_date >= ?
                   OR (EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year = ? AND $nz)
                       AND (? = 1 OR NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.school_year < ? AND $nz)))))
             OR (NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id) AND (e.hire_date IS NULL OR e.hire_date <= ?)))";
}
function excelSalariesInYearParams(string $sy, ?string $today = null): array {
    $y1 = (int)substr($sy, 0, 4); $prev = ($y1 - 1) . '-' . $y1; $prevStart = ($y1 - 1) . '-10-01';
    $started = (($today ?? date('Y-m-d')) >= $y1 . '-10-01') ? 1 : 0;
    return [$sy, $prev, $prevStart, $sy, $started, $prev, ($y1 + 1) . '-09-30'];
}
function excelSalariesRows(PDO $db, int $schoolId, string $sy, string $cat = 'all', string $tax = ''): array {
    $types = excelSalariesCats()[excelSalariesCat($cat)]['types']; $tax = excelSalariesTax($tax);
    $st = $db->prepare("SELECT e.*, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr) fn, COALESCE(NULLIF(e.father_name_ar,''), '') fa,
                COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr) ln
            FROM employees e WHERE e.school_id = ? AND e.is_deleted = 0 AND e.employee_type IN (" . implode(',', array_map([$db, 'quote'], $types)) . ")
              AND e.status = 'actif' AND " . leftDateSql('e.') . " = '9999-12-31'
              " . ($tax !== '' ? " AND e.tax_subject = " . (int)$tax : '') . "
              AND " . excelSalariesInYearSql($sy) . "
            ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr), e.id"); // 🔤 (2026-10-21 «مرتّبين أبجدياً طبق الأصل متل بقية التقارير») نفس ترتيب الكشوف: الاسم الأوّل ثم الشهرة
    $st->execute(array_merge([$schoolId], excelSalariesInYearParams($sy)));
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $row = [
            'id' => (int)$r['id'], 'cat' => excelSalariesCatLabel($r['employee_type']), 'type' => $r['employee_type'],
            'name' => trim(preg_replace('/\s+/u', ' ', $r['fn'] . ' ' . $r['fa'] . ' ' . $r['ln'])),
            'sal_usd' => ($r['employee_type'] !== 'enseignant_titulaire' && $r['salary_input_mode'] === 'direct_usd' && (float)$r['base_salary_usd'] > 0) ? (float)$r['base_salary_usd'] : null,
            'sal_lbp' => ($r['employee_type'] !== 'enseignant_titulaire' && $r['salary_input_mode'] !== 'direct_usd' && (float)$r['contract_salary_lbp'] > 0) ? (float)$r['contract_salary_lbp'] : null,
            'pct' => null, 'amt_lbp' => null, 'amt_usd' => null, 'from' => null, 'to' => null, 'multi' => false,
            'days' => (int)$r['days_per_week'] ?: null,
            'social_status' => (string)($r['social_status'] ?? ''),
        ];
        // 🗂️ كل خانات ملف الموظف (2026-10-21) — نفس الصيغة للعرض والقراءة
        foreach (excelSalariesFields() as $k => $f) { if (!in_array($f['type'], ['ro', 'special', 'cat'], true)) $row[$k] = excelSalariesCellValue($db, $k, $r); }
        $rows[(int)$r['id']] = $row;
    }
    if ($rows) {
        $in = implode(',', array_keys($rows));
        $bq = $db->query("SELECT employee_id, value_type, amount, currency, start_month, end_month FROM employee_bonuses
                          WHERE is_active = 1 AND bonus_type = 'prime_fixe' AND school_year = " . $db->quote($sy) . " AND employee_id IN ($in) ORDER BY id");
        foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $id = (int)$b['employee_id']; $a = (float)$b['amount'];
            if ($b['value_type'] === 'percent') $rows[$id]['pct'] = ($rows[$id]['pct'] ?? 0) + $a;
            elseif ($b['currency'] === 'USD') $rows[$id]['amt_usd'] = ($rows[$id]['amt_usd'] ?? 0) + $a;
            else $rows[$id]['amt_lbp'] = ($rows[$id]['amt_lbp'] ?? 0) + $a;
            // الفترة: بنود بفترات مختلفة عند نفس الشخص = «متعدّد» (الإكسل يعرض الأولى؛ التعديل من الإكسل يستبدلها كلها بفترة واحدة)
            $f = $b['start_month'] === null ? 10 : (int)$b['start_month']; $t = $b['end_month'] === null ? 9 : (int)$b['end_month'];
            if ($rows[$id]['from'] === null) { $rows[$id]['from'] = $f; $rows[$id]['to'] = $t; }
            elseif ($rows[$id]['from'] !== $f || $rows[$id]['to'] !== $t) $rows[$id]['multi'] = true;
        }
        // بنود بفترات مختلفة عند نفس الشخص (مثلاً 59م تشرين←تموز + 69م آب←أيلول): لا تُمثَّل بسطر واحد — خاناته تُترك فاضية
        // (فاضي = يبقى كما هو) مع ملاحظة تشرح؛ إن كتب قيمة تستبدل كل فتراته بسطر واحد. تفصيله بـ'lines' للمعاينة.
        $multiIds = array_keys(array_filter($rows, fn($r) => !empty($r['multi'])));
        if ($multiIds) {
            $bq2 = $db->query("SELECT employee_id, value_type, amount, currency, start_month, end_month FROM employee_bonuses
                          WHERE is_active = 1 AND bonus_type = 'prime_fixe' AND school_year = " . $db->quote($sy) . " AND employee_id IN (" . implode(',', $multiIds) . ") ORDER BY start_month, id");
            foreach ($bq2->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $id = (int)$b['employee_id']; $a = rtrim(rtrim(number_format((float)$b['amount'], 2, '.', ','), '0'), '.');
                $f = $b['start_month'] === null ? 10 : (int)$b['start_month']; $t = $b['end_month'] === null ? 9 : (int)$b['end_month'];
                $rows[$id]['lines'][] = ($b['value_type'] === 'percent' ? $a . '٪' : $a . ($b['currency'] === 'USD' ? ' $' : ' ل.ل')) . ' (' . monthName($f, 'ar') . ' ← ' . monthName($t, 'ar') . ')';
            }
            foreach ($multiIds as $id) {
                $rows[$id]['pct'] = $rows[$id]['amt_lbp'] = $rows[$id]['amt_usd'] = $rows[$id]['from'] = $rows[$id]['to'] = null;
                $rows[$id]['note'] = 'الأجر الإضافي بأكثر من فترة: ' . implode(' + ', $rows[$id]['lines'] ?? []) . ' — فاضي = يبقى كما هو؛ اكتب قيمة (وفترة) لتستبدلها كلها بسطر واحد.';
            }
        }
        foreach ($rows as &$r) { if ($r['from'] !== null) { $r['from'] = monthName((int)$r['from'], 'ar'); $r['to'] = monthName((int)$r['to'], 'ar'); } }
        unset($r);
    }
    return $rows;
}

/** بناء ملف xlsx (بايتات) — سطر 1 عنوان، سطر 2 شرح، سطر 3 رؤوس، البيانات من سطر 4. */
function excelSalariesBuild(PDO $db, int $schoolId, string $sy, string $cat = 'all', string $tax = ''): string {
    $cat = excelSalariesCat($cat); $tax = excelSalariesTax($tax); $cols = excelSalariesColumns(); $rows = excelSalariesRows($db, $schoolId, $sy, $cat, $tax);
    $xa = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $n = count($cols); $last = excelSalariesColLetter($n);
    $cell = function ($ref, $v, $s) use ($xa) {
        if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $s . '"/>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
        return '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . $xa($v) . '</t></is></c>';
    };
    $school = schoolNameById($schoolId, 'ar');
    $catLbl = excelSalariesFilterLabel($cat, $tax);
    $xml = '<row r="1">' . $cell('A1', "ملفات الموظفين والرواتب والأجر الإضافي — $catLbl — $school — $sy", 1) . '</row>';
    $xml .= '<row r="2">' . $cell('A2', 'عبّي الخانات الصفراء وارفع الملف بالبرنامج. فاضي = لا تغيير · «-» (شرطة) = امسح الخانة · الخانات الرمادية لا تُقرأ · الفئة: متعاقد ↔ موظف فقط (الملاك من ملف الأستاذ) · التواريخ يوم/شهر/سنة · نعم/لا للضريبة والضمان والزوج · المرحلة والصفوف بفاصلة بين العناصر · 0 بالأجر الإضافي = شيله · الراتب: عمود $ أو عمود ل.ل (واحد منهما — للمتعاقد والموظف؛ الملاك راتبهم بالسلسلة فخانتاهما رمادية لا تُقرآن) · الأجر الإضافي: مبلغ ل.ل و/أو مبلغ $ لكل شخص (فيك تعبّي الاتنين معاً = جزء بالليرة + جزء بالدولار، البرنامج بيجمعهم) · الإضافي من شهر إلى شهر: فاضي = كل السنة (تشرين الأول ← أيلول) · لا تغيّر رقم الملف ولا ترتيب الأعمدة.', 2) . '</row>';
    $xml .= '<row r="3">';
    $i = 0; foreach ($cols as $k => $c) { $xml .= $cell($c[0] . '3', $c[1], 3); $i++; }
    $xml .= '</row>';
    $r = 3;
    foreach ($rows as $row) {
        $r++; $xml .= '<row r="' . $r . '">';
        foreach ($cols as $k => $c) {
            $v = $row[$k] ?? null;
            $editable = excelSalariesEditable($k, $row['type']); // الملاك: الراتب بالسلسلة · الموظف الإداري: بلا مواد/مرحلة/صفوف · الأستاذ: بلا نوع وظيفة
            $xml .= $cell($c[0] . $r, $v, $editable ? 5 : 4);
        }
        $xml .= '</row>';
    }
    $colsXml = '<cols>'; $i = 0;
    foreach ($cols as $c) { $i++; $colsXml .= '<col min="' . $i . '" max="' . $i . '" width="' . $c[2] . '" customWidth="1"/>'; }
    $colsXml .= '</cols>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0" tabSelected="1"><pane xSplit="3" ySplit="3" topLeftCell="D4" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>' . $colsXml
        . '<sheetData>' . $xml . '</sheetData>'
        . '<mergeCells count="2"><mergeCell ref="A1:' . $last . '1"/><mergeCell ref="A2:' . $last . '2"/></mergeCells>'
        . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
        . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    // أنماط: 0 عادي · 1 عنوان · 2 شرح · 3 رأس (كحلي) · 4 بيانات مقفولة (رمادي) · 5 خانة إدخال (أصفر)
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.##"/></numFmts>'
        . '<fonts count="4"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="14"/><color rgb="FF1F4E5F"/><name val="Arial"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E5F"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF7CC"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyProtection="1"><protection locked="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyProtection="1"><protection locked="0"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="ملفات الموظفين والرواتب" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xls'); @unlink($tmp); $tmp .= '.xlsx';
    $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $p => $c) $zip->addFromString($p, $c);
    $zip->close();
    $data = (string)file_get_contents($tmp); @unlink($tmp);
    return $data;
}

/** قراءة ملف xlsx مرفوع → [['id'=>..,'sal_usd'=>..,...], ...] حسب الأعمدة الثابتة (من السطر 4، أو أي سطر أوّل خانته رقم ملف). */
function excelSalariesParse(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive غير متوفّر');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('الملف ليس إكسل صالحاً (xlsx)');
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($ss);
        if ($sx) foreach ($sx->si as $si) { $t = ''; foreach ($si->xpath('.//*[local-name()="t"]') as $tt) $t .= (string)$tt; $shared[] = $t; }
    }
    // الورقة الأولى (حسب workbook.xml.rels قد تكون sheet1.xml — نأخذ أوّل worksheets/*.xml)
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) for ($i = 0; $i < $zip->numFiles; $i++) { $nm = $zip->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nm)) { $sheetXml = $zip->getFromName($nm); break; } }
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('لا ورقة بالملف');
    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) throw new RuntimeException('تعذّر قراءة الورقة');
    $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $colMap = []; foreach (excelSalariesColumns() as $k => $c) $colMap[$c[0]] = $k;
    $out = [];
    foreach ($sx->sheetData->row as $row) {
        $vals = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; $col = preg_replace('/\d+/', '', $ref);
            $t = (string)$c['t']; $v = null;
            if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
            elseif ($t === 'inlineStr') { $v = ''; foreach ($c->is->xpath('.//*[local-name()="t"]') as $tt) $v .= (string)$tt; }
            elseif (isset($c->v)) { $v = (string)$c->v; }
            if ($v === null) continue;
            if (isset($colMap[$col])) $vals[$colMap[$col]] = trim($v);
        }
        if (!isset($vals['id']) || !preg_match('/^\d+(\.0+)?$/', (string)$vals['id'])) continue; // العنوان/الشرح/الرؤوس
        $vals['id'] = (int)$vals['id'];
        $out[] = $vals;
    }
    return $out;
}

/** رقم من خانة إكسل: '' ⇒ null (لا تغيير)، وإلا float (يقبل فواصل الآلاف والأرقام العربية). */
function excelSalariesNum($v): ?float {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (function_exists('arabicDigitsFr')) $v = arabicDigitsFr($v);
    $v = str_replace([',', ' ', '٬'], '', $v);
    if (!is_numeric($v)) return null;
    return (float)$v;
}
/** رقم مكتوب بالإكسل قد يرجع «3123456.0» أو بصيغة علمية — نصّ نظيف للهواتف/الأرقام الرسمية */
function excelSalariesTextClean(string $v): string {
    if (preg_match('/^\d+\.0+$/', $v)) $v = preg_replace('/\.0+$/', '', $v);
    elseif (preg_match('/^(\d+(?:\.\d+)?)E\+?(\d+)$/i', $v, $m)) $v = number_format((float)$v, 0, '', '');
    return $v;
}

/**
 * 🗂️ قراءة خانة من ملف الموظف (نصّ/تاريخ/رقم/نعم-لا/قائمة/مرحلة/صفوف) ⇒ ['skip'] | ['err' => …] | ['val' => قيمة للقاعدة, 'show' => للمعاينة]
 * فاضي = لا تغيير · «-» = امسح (للنصوص والتواريخ والقوائم والمراحل/الصفوف).
 */
function excelSalariesFieldParse(PDO $db, string $key, string $raw) {
    $f = excelSalariesFields()[$key]; $raw = trim($raw);
    if ($raw === '') return ['skip' => true];
    $clear = ($raw === '-' || $raw === '—' || $raw === '_');
    switch ($f['type']) {
        case 'text':
            if ($clear) return ['val' => '', 'show' => '—'];
            $v = excelSalariesTextClean($raw);
            if (!empty($f['official'])) $v = officialNumberFr($v);
            elseif (!empty($f['digits'])) $v = trim(arabicDigitsFr($v));
            return ['val' => $v, 'show' => $v];
        case 'date':
            if ($clear) return ['val' => null, 'show' => '—'];
            $d = parseFlexibleDate($raw);
            if (!$d) return ['err' => 'تاريخ غير مفهوم «' . $raw . '» (اكتبه يوم/شهر/سنة)'];
            return ['val' => $d, 'show' => displayDMY($d)];
        case 'int': case 'float':
            $n = excelSalariesNum($raw);
            if ($n === null) return ['err' => 'رقم غير مفهوم «' . $raw . '»'];
            if (isset($f['min']) && $n < $f['min']) return ['err' => 'الرقم ' . $raw . ' أقل من ' . $f['min']];
            if (isset($f['max']) && $n > $f['max']) return ['err' => 'الرقم ' . $raw . ' أكبر من ' . $f['max']];
            $v = $f['type'] === 'int' ? (int)round($n) : round($n, 1);
            return ['val' => $v, 'show' => (string)$v];
        case 'bool':
            $b = excelSalariesBool($raw);
            if ($b === null) return ['err' => 'اكتب نعم أو لا (وجدت «' . $raw . '»)'];
            return ['val' => $b, 'show' => $b ? 'نعم' : 'لا'];
        case 'enum':
            if ($clear) return ['val' => $key === 'marital' ? 'celibataire' : '', 'show' => $key === 'marital' ? 'أعزب' : '—'];
            $opts = excelSalariesOpts($db, $f); $code = excelSalariesEnumMatch($raw, $opts);
            if ($code === null) {
                if (!empty($f['free'])) return ['val' => $raw, 'show' => $raw];
                $list = implode(' / ', array_map(fn($o) => $o['ar'] ?: $o['fr'], $opts));
                return ['err' => 'قيمة غير معروفة «' . $raw . '» — الخيارات: ' . $list];
            }
            return ['val' => $code, 'show' => $opts[$code]['ar'] ?: $opts[$code]['fr']];
        case 'niveau':
            if ($clear) return ['val' => '', 'show' => '—'];
            $nv = excelSalariesNiveaux(); $keys = []; $bad = [];
            foreach (excelSalariesSplit($raw) as $tok) { $c = excelSalariesEnumMatch($tok, $nv); if ($c === null) $bad[] = $tok; elseif (!in_array($c, $keys, true)) $keys[] = $c; }
            if ($bad) return ['err' => 'مرحلة غير معروفة «' . implode('، ', $bad) . '» — الخيارات: حضانة / ابتدائي / متوسط / ثانوي'];
            $keys = array_values(array_intersect(array_keys($nv), $keys)); // بترتيب المراحل
            return ['val' => implode(',', $keys), 'show' => implode('، ', array_map(fn($k) => $nv[$k]['ar'], $keys))];
        case 'classes':
            if ($clear) return ['val' => '', 'show' => '—'];
            $cl = excelSalariesClasses($db); $ids = []; $bad = [];
            $opts = []; foreach ($cl as $id => $c) $opts[$id] = ['ar' => $c['ar'], 'fr' => $c['fr'], 'syn' => [str_replace(' ', '', $c['fr'])]];
            foreach (excelSalariesSplit($raw) as $tok) { $c = excelSalariesEnumMatch(str_replace(' ', '', $tok), $opts) ?? excelSalariesEnumMatch($tok, $opts); if ($c === null) $bad[] = $tok; elseif (!in_array((int)$c, $ids, true)) $ids[] = (int)$c; }
            if ($bad) return ['err' => 'صفّ غير معروف «' . implode('، ', $bad) . '» — الصفوف: ' . implode(', ', array_map(fn($c) => $c['fr'] ?: $c['ar'], $cl))];
            $ids = array_values(array_intersect(array_keys($cl), $ids)); // بترتيب الصفوف
            return ['val' => implode(',', $ids), 'show' => implode(', ', array_map(fn($i) => $cl[$i]['fr'] ?: $cl[$i]['ar'], $ids))];
    }
    return ['skip' => true];
}

/**
 * مقارنة الملف المرفوع بالوضع الحالي → ['changes' => [emp_id => [...]], 'errors' => [...], 'unchanged' => n]
 * كل تغيير: name, cat, fields: [['what'=>'الراتب', 'old'=>'…', 'new'=>'…']], ops: تعليمات التطبيق.
 */
function excelSalariesDiff(PDO $db, int $schoolId, string $sy, array $parsed, string $cat = 'all', string $tax = ''): array {
    $cur = excelSalariesRows($db, $schoolId, $sy, $cat, $tax);
    $fmt = function ($v, $suf = '') { return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.') . $suf; };
    $changes = []; $errors = []; $unchanged = 0;
    foreach ($parsed as $p) {
        $id = (int)$p['id'];
        if (!isset($cur[$id])) { $errors[] = "رقم الملف $id ليس من الفئة/الضريبة المختارة الفاعلة بهذه المدرسة — تُرك"; continue; }
        $c = $cur[$id]; $fields = []; $ops = [];
        // 🗂️ الفئة (2026-10-21): متعاقد ↔ موظف من الإكسل؛ من/إلى ملاك = من ملف الأستاذ (الدرجات والسلسلة وقرار الترسيم)
        $effType = $c['type'];
        $catRaw = trim((string)($p['cat'] ?? ''));
        if ($catRaw !== '') {
            $newCat = excelSalariesCatParse($catRaw);
            if ($newCat === null) $errors[] = "{$c['name']} (#$id): فئة غير معروفة «{$catRaw}» — اكتب ملاك أو متعاقد أو موظف (تُركت فئته)";
            elseif ($newCat !== $c['type']) {
                if ($newCat === 'enseignant_titulaire' || $c['type'] === 'enseignant_titulaire') $errors[] = "{$c['name']} (#$id): التحويل " . ($newCat === 'enseignant_titulaire' ? 'إلى' : 'من') . " ملاك يتمّ من ملف الأستاذ (الدرجات والسلسلة وقرار الترسيم) لا من الإكسل — تُركت فئته";
                else { $fields[] = ['what' => 'الفئة', 'old' => excelSalariesCatLabel($c['type']), 'new' => excelSalariesCatLabel($newCat)]; $ops['cat'] = $newCat; $effType = $newCat; }
            }
        }
        // الراتب: عمود $ أو عمود ل.ل — للمتعاقد والموظف فقط (الملاك بالسلسلة والدرجات)
        $su = excelSalariesNum($p['sal_usd'] ?? ''); $sl = excelSalariesNum($p['sal_lbp'] ?? '');
        if ($c['type'] === 'enseignant_titulaire' && (($su !== null && $su > 0) || ($sl !== null && $sl > 0))) { $errors[] = "{$c['name']} (#$id): ملاك — راتبه بالسلسلة والدرجات لا من الإكسل (تُرك راتبه؛ إضافيه وأيامه يُقرآن)"; $su = $sl = null; }
        if ($su !== null && $su > 0 && $sl !== null && $sl > 0) { $errors[] = "{$c['name']} (#$id): الراتب بالدولار وبالليرة معاً — حدّد عملة واحدة (تُرك راتبه)"; $su = $sl = null; }
        if ($su !== null && $su > 0 && (float)($c['sal_usd'] ?? 0) !== $su) { $fields[] = ['what' => 'الراتب الأساسي', 'old' => $c['sal_usd'] !== null ? $fmt($c['sal_usd'], ' $') : ($c['sal_lbp'] !== null ? $fmt($c['sal_lbp'], ' ل.ل') : '—'), 'new' => $fmt($su, ' $')]; $ops['salary'] = ['mode' => 'direct_usd', 'usd' => $su]; }
        elseif ($sl !== null && $sl > 0 && (float)($c['sal_lbp'] ?? 0) !== $sl) { $fields[] = ['what' => 'الراتب الأساسي', 'old' => $c['sal_lbp'] !== null ? $fmt($c['sal_lbp'], ' ل.ل') : ($c['sal_usd'] !== null ? $fmt($c['sal_usd'], ' $') : '—'), 'new' => $fmt($sl, ' ل.ل')]; $ops['salary'] = ['mode' => 'direct_lbp', 'lbp' => $sl]; }
        // الأجر الإضافي: ٪ و/أو مبلغ (ل.ل أو $) + الفترة (من ← إلى) — أي خانة مكتوبة (ولو 0) تعني «الوضع الجديد للأجر الإضافي كله»
        $pc = excelSalariesNum($p['pct'] ?? ''); $al = excelSalariesNum($p['amt_lbp'] ?? ''); $au = excelSalariesNum($p['amt_usd'] ?? '');
        $fm = excelSalariesMonth($p['from'] ?? ''); $tm = excelSalariesMonth($p['to'] ?? '');
        if ((trim((string)($p['from'] ?? '')) !== '' && $fm === null) || (trim((string)($p['to'] ?? '')) !== '' && $tm === null)) { $errors[] = "{$c['name']} (#$id): شهر غير مفهوم بخانة «من/إلى» (اكتب اسم الشهر أو رقمه 1-12) — تُرك أجره الإضافي"; }
        elseif ($pc !== null || $al !== null || $au !== null || $fm !== null || $tm !== null) {
            $newPc = $pc ?? (float)($c['pct'] ?? 0); $newAl = $al ?? (float)($c['amt_lbp'] ?? 0); $newAu = $au ?? (float)($c['amt_usd'] ?? 0);
            $curF = $c['from'] !== null ? (int)excelSalariesMonth($c['from']) : 10; $curT = $c['to'] !== null ? (int)excelSalariesMonth($c['to']) : 9;
            $newF = $fm ?? $curF; $newT = $tm ?? $curT;
            // 💵 (2026-09-13 «كل أستاذ بدي أعطيه مبلغ معيّن بالدولار وبالليرة»): العمودان G وH معاً مسموحان —
            // سطر لكل عملة بنفس الفترة، والمحرّك يجمعهما (كان يُرفَض «حدّد عملة واحدة»).
            {
                $oldT = [(float)($c['pct'] ?? 0), (float)($c['amt_lbp'] ?? 0), (float)($c['amt_usd'] ?? 0), $curF, $curT];
                // متعدّد الفترات: خاناته بالملف فاضية (لا تغيير) — أي قيمة مكتوبة تستبدل كل فتراته بسطر واحد
                if ($oldT !== [$newPc, $newAl, $newAu, $newF, $newT]) {
                    $per = fn($f, $t) => ($f === 10 && $t === 9) ? 'كل السنة' : monthName($f, 'ar') . ' ← ' . monthName($t, 'ar');
                    $lbl = function ($pcv, $alv, $auv, $f, $t) use ($fmt, $per) { $x = []; if ($pcv > 0) $x[] = $fmt($pcv, '٪'); if ($alv > 0) $x[] = $fmt($alv, ' ل.ل'); if ($auv > 0) $x[] = $fmt($auv, ' $'); return $x ? implode(' + ', $x) . ' (' . $per($f, $t) . ')' : 'بلا'; };
                    $fields[] = ['what' => 'الأجر الإضافي', 'old' => !empty($c['multi']) ? implode(' + ', $c['lines'] ?? []) : $lbl(...$oldT), 'new' => $lbl($newPc, $newAl, $newAu, $newF, $newT)];
                    $lines = [];
                    if ($newPc > 0) $lines[] = ['vt' => 'percent', 'val' => $newPc, 'cur' => 'LBP', 'from' => $newF, 'to' => $newT];
                    if ($newAl > 0) $lines[] = ['vt' => 'amount', 'val' => $newAl, 'cur' => 'LBP', 'from' => $newF, 'to' => $newT];
                    if ($newAu > 0) $lines[] = ['vt' => 'amount', 'val' => $newAu, 'cur' => 'USD', 'from' => $newF, 'to' => $newT];
                    $ops['prime'] = $lines;
                }
            }
        }
        // الأيام بالأسبوع
        $d = excelSalariesNum($p['days'] ?? '');
        if ($d !== null) {
            $di = (int)round($d);
            if ($di < 1 || $di > 7) $errors[] = "{$c['name']} (#$id): عدد الأيام $di غير منطقي (1-7) — تُرك";
            elseif ($di !== (int)($c['days'] ?? 0)) { $fields[] = ['what' => 'الأيام بالأسبوع', 'old' => $c['days'] !== null ? (string)$c['days'] : '—', 'new' => (string)$di]; $ops['days'] = $di; }
        }
        // 🗂️ كل خانات ملف الموظف (2026-10-21): نصوص/تواريخ/أرقام/نعم-لا/قوائم/مرحلة/صفوف — فاضي = لا تغيير، «-» = امسح
        $setF = []; $needRecalc = false; $marital = null; $children = null;
        foreach (excelSalariesFields() as $k => $f) {
            if (in_array($f['type'], ['ro', 'special', 'cat'], true)) continue;
            $raw = trim((string)($p[$k] ?? '')); if ($raw === '') continue;
            if (!excelSalariesEditable($k, $effType)) {
                // خانة رمادية لهذه الفئة: تُقرأ فقط إن تغيّرت فعلاً عن المعروض (الملف نُزِّل بقيمها الحالية)
                if ((string)($c[$k] ?? '') !== $raw) $errors[] = "{$c['name']} (#$id): خانة «{$f['what']}» لا تخصّ فئته (" . excelSalariesCatLabel($effType) . ") — تُركت";
                continue;
            }
            $r = excelSalariesFieldParse($db, $k, $raw);
            if (!empty($r['skip'])) continue;
            if (isset($r['err'])) { $errors[] = "{$c['name']} (#$id): {$f['what']}: {$r['err']}"; continue; }
            if ($k === 'marital') { $marital = $r; continue; }
            if ($k === 'number_of_children') { $children = $r; continue; }
            $oldShow = $c[$k] ?? null; $oldShow = $oldShow === null ? '—' : (string)$oldShow;
            if ($oldShow === (string)$r['show']) continue; // نفس القيمة
            $fields[] = ['what' => $f['what'], 'old' => $oldShow, 'new' => (string)$r['show']];
            $setF[$f['db']] = $r['val'];
            if (!empty($f['recalc'])) $needRecalc = true;
        }
        // الوضع العائلي + عدد الأولاد ⇒ مفتاح social_status (نفس تركيب ملف الموظف composeSocialStatus)
        if ($marital !== null || $children !== null) {
            $kindOld = excelSalariesMaritalKind((string)($c['social_status'] ?? '')); $chOld = (int)($c['number_of_children'] ?? 0);
            $kindNew = $marital !== null ? (string)$marital['val'] : $kindOld; $chNew = $children !== null ? (int)$children['val'] : $chOld;
            $ssNew = composeSocialStatus($kindNew, $chNew);
            if ($ssNew !== (string)($c['social_status'] ?? '') || $chNew !== $chOld) {
                $fields[] = ['what' => 'الوضع العائلي', 'old' => socialStatusLabel((string)($c['social_status'] ?? ''), 'ar') . ' · ' . $chOld . ' أولاد', 'new' => socialStatusLabel($ssNew, 'ar') . ' · ' . $chNew . ' أولاد'];
                $setF['social_status'] = $ssNew; $setF['number_of_children'] = $chNew; $needRecalc = true;
            }
        }
        if ($setF) { $ops['fields'] = $setF; if ($needRecalc) $ops['recalc'] = true; }
        if ($fields) $changes[$id] = ['id' => $id, 'name' => $c['name'], 'cat' => $c['cat'], 'fields' => $fields, 'ops' => $ops];
        else $unchanged++;
    }
    return ['changes' => $changes, 'errors' => $errors, 'unchanged' => $unchanged];
}

/** تطبيق التغييرات (بعد التأكيد) → ['applied' => n, 'recalc' => n, 'skipped' => [...]] */
function excelSalariesApply(PDO $db, int $schoolId, string $sy, array $changes): array {
    require_once __DIR__ . '/payroll_calculator.php';
    $applied = 0; $recalc = 0; $skipped = [];
    if (isSchoolYearLocked($schoolId, $sy)) return ['applied' => 0, 'recalc' => 0, 'skipped' => [yearLockedMsg($schoolId, $sy)]];
    excelSalariesEnsureNiveau($db);
    $allowedCols = []; foreach (excelSalariesFields() as $f) if (!empty($f['db'])) $allowedCols[$f['db']] = true; $allowedCols['social_status'] = true; $allowedCols['number_of_children'] = true;
    $chk = $db->prepare("SELECT employee_type FROM employees WHERE id = ? AND school_id = ? AND is_deleted = 0 AND employee_type IN ('enseignant_titulaire','enseignant_contractuel','employe')");
    $delP = $db->prepare("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = ? AND bonus_type = 'prime_fixe' AND school_year = ?");
    $insP = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'prime_fixe', ?, ?, ?, ?, ?, ?, ?, 1)");
    foreach ($changes as $ch) {
        $id = (int)$ch['id']; $chk->execute([$id, $schoolId]); $ety = $chk->fetchColumn();
        if (!$ety) { $skipped[] = ($ch['name'] ?? $id) . ': لم يعد بالمدرسة'; continue; }
        $ops = $ch['ops'] ?? [];
        if ($ety === 'enseignant_titulaire') unset($ops['salary']); // 🛡️ الملاك: الراتب بالسلسلة دائماً
        $touchSalary = false;
        // 🗂️ الفئة: متعاقد ↔ موظف فقط (الملاك محميّ بالاتجاهين)
        if (!empty($ops['cat']) && in_array($ops['cat'], ['enseignant_contractuel', 'employe'], true) && $ety !== 'enseignant_titulaire' && $ops['cat'] !== $ety) {
            $db->prepare("UPDATE employees SET employee_type = ?" . ($ops['cat'] !== 'employe' ? ", job_title = NULL" : '') . " WHERE id = ?")->execute([$ops['cat'], $id]);
            try { logAudit('update', 'employees', $id, ['employee_type' => $ety], ['employee_type' => $ops['cat'], 'via' => 'excel']); } catch (Throwable $t) {}
            $touchSalary = true;
        }
        if (!empty($ops['salary'])) {
            if ($ops['salary']['mode'] === 'direct_usd') {
                // 💵📅 (2026-09-21) الانتقال من الليرة إلى الدولار يُؤرَّخ بسنة الإكسل: الأشهر قبلها تبقى على راتبه بالليرة (كشوفه القديمة لا تُخرَّب)
                ensureUsdBaseFromSyColumn();
                $pm = (string)$db->query("SELECT salary_input_mode FROM employees WHERE id = $id")->fetchColumn();
                if ($pm !== 'direct_usd') $db->prepare("UPDATE employees SET salary_input_mode = 'direct_usd', base_salary_usd = ?, usd_base_from_sy = ? WHERE id = ?")->execute([(float)$ops['salary']['usd'], $sy, $id]);
                else $db->prepare("UPDATE employees SET salary_input_mode = 'direct_usd', base_salary_usd = ? WHERE id = ?")->execute([(float)$ops['salary']['usd'], $id]);
            }
            else $db->prepare("UPDATE employees SET salary_input_mode = 'direct_lbp', contract_salary_lbp = ? WHERE id = ?")->execute([(int)round((float)$ops['salary']['lbp']), $id]);
            $touchSalary = true;
        }
        if (array_key_exists('prime', $ops)) {
            $delP->execute([$id, $sy]); $pn = 0;
            foreach ((array)$ops['prime'] as $ln) { $pn++; $insP->execute([$id, $pn, $sy, (float)$ln['val'], $ln['vt'] === 'percent' ? 'percent' : 'amount', $ln['vt'] === 'percent' ? 'LBP' : ($ln['cur'] === 'USD' ? 'USD' : 'LBP'), max(1, min(12, (int)($ln['from'] ?? 10))), max(1, min(12, (int)($ln['to'] ?? 9)))]); }
            $touchSalary = true;
        }
        if (!empty($ops['days'])) { $db->prepare("UPDATE employees SET days_per_week = ? WHERE id = ?")->execute([(int)$ops['days'], $id]); $touchSalary = true; }
        // 🗂️ خانات ملف الموظف (2026-10-21) — أعمدة من السجلّ فقط
        if (!empty($ops['fields']) && is_array($ops['fields'])) {
            $set = []; $vals = [];
            foreach ($ops['fields'] as $col => $v) { if (!isset($allowedCols[$col])) continue; $set[] = "`$col` = ?"; $vals[] = $v; }
            if ($set) {
                $vals[] = $id;
                $db->prepare("UPDATE employees SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                try { logAudit('update', 'employees', $id, null, array_merge($ops['fields'], ['via' => 'excel'])); } catch (Throwable $t) {}
            }
            if (!empty($ops['recalc'])) $touchSalary = true;
        }
        $applied++;
        // إعادة الحساب فقط لما يمسّ الراتب (الراتب/الإضافي/الأيام/الفئة/الوضع العائلي/الضريبة/الضمان/الولادة) — تعديل هاتف أو عنوان لا يعيد شيئاً
        if ($touchSalary && (int)recalcEmployeeYear($id, $sy) > 0) $recalc++;
    }
    return ['applied' => $applied, 'recalc' => $recalc, 'skipped' => $skipped];
}
