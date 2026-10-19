<?php
/**
 * 🪪 بطاقة الأستاذ على ورقة A4 واحدة / Fiche de l'enseignant (A4)
 * (2026-10-19 «اذا بدي شوف معلومات الاستاذ على صفحة وحدة A4… المعلومات الشخصية وكل شي معلومات لالو بالمدرسة واقدر اطبعها»)
 * كل معلومات ملفّه (شخصية · اتصال · وظيفية · أرقام رسمية · راتب ومحسومات) مرتّبة بخانتَين على كل سطر، بلا صور المستندات،
 * الخانات الفارغة لا تُطبع — فتسع ورقة واحدة. صفحة مستقلّة: لا تمسّ «الملف الكامل» ولا أي صفحة أخرى.
 * 🌐 («بطاقة الأستاذ A4 ما عجبتني… بتخليها كلها بالعربي وبتحطلي خيار إذا بدي ياها بغير لغة فرنسي أو إنكليزي أو عربي — هيك بتضلها A4 وواضحة» 2026-10-19):
 *    لغة واحدة للورقة كلّها (العربي افتراضياً) + حبوب العربية / Français / English فوق الورقة (lang_doc=ar|fr|en).
 * الطباعة/PDF/واتساب/إيميل من شريط التصدير الموحّد (جداول pdf-info جاهزة ⇒ محرّك الطباعة لا يحوّلها ولا يقسّمها).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php';   // قواميس الأسماء/الأماكن/الموادّ بالاتجاهين
requireLogin();

$currentPage = 'employee_fiche';
$db = getDB();
$employeeId = (int)($_GET['employee_id'] ?? 0);
$DOC_LANGS = ['ar' => 'العربية', 'fr' => 'Français', 'en' => 'English'];
$L = $_GET['lang_doc'] ?? 'ar';
if (!isset($DOC_LANGS[$L])) $L = 'ar';

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
                    <label class="form-label">Langue / لغة البطاقة</label>
                    <div class="school-checks att-lang-checks">
                        <?php foreach ($DOC_LANGS as $k => $lbl): ?>
                        <label class="chk"><input type="radio" name="lang_doc" value="<?= e($k) ?>" <?= $k === $L ? 'checked' : '' ?> autocomplete="off"> <span><?= e($lbl) ?></span></label>
                        <?php endforeach; ?>
                    </div>
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
if (isAllSchools()) {
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

// ===== القاموس: كل نصّ ثابت بالورقة بثلاث لغات [ar, fr, en] =====
$T = [
    'title_t'   => ['بطاقة الأستاذ', "Fiche de l'enseignant", 'Teacher Card'],
    'title_e'   => ['بطاقة الموظف', "Fiche de l'employé", 'Employee Card'],
    's_pers'    => ['معلومات شخصية', 'Informations personnelles', 'Personal information'],
    's_contact' => ['اتصال وسكن', 'Contact et adresse', 'Contact and address'],
    's_job'     => ['معلومات وظيفية', 'Informations professionnelles', 'Employment information'],
    's_off'     => ['أرقام رسمية وتواريخ الترك', 'Numéros officiels et cessations', 'Official numbers and leaving dates'],
    's_sal'     => ['الراتب والمحسومات', 'Salaire et retenues', 'Salary and deductions'],
    's_notes'   => ['ملاحظات', 'Remarques', 'Notes'],
    'name_ar'   => ['الاسم الكامل (عربي)', 'Nom complet (arabe)', 'Full name (Arabic)'],
    'name_fr'   => ['الاسم الكامل (فرنسي)', 'Nom complet', 'Full name'],
    'mother'    => ['اسم الأم', 'Nom de la mère', "Mother's name"],
    'nat'       => ['الجنسية', 'Nationalité', 'Nationality'],
    'birth'     => ['الولادة', 'Naissance', 'Birth'],
    'reg'       => ['السجل', 'Registre', 'Civil registry'],
    'social'    => ['الوضع العائلي', 'Situation familiale', 'Marital status'],
    'kids'      => ['عدد الأولاد', "Nombre d'enfants", 'Children'],
    'spouse_w'  => ['الزوج/الزوجة يعمل', 'conjoint(e) travaille', 'spouse works'],
    'tel'       => ['الهاتف', 'Téléphone', 'Phone'],
    'email'     => ['الإيميل', 'E-mail', 'E-mail'],
    'addr'      => ['العنوان', 'Adresse', 'Address'],
    'floor'     => ['طابق', 'étage', 'floor'],
    'cat'       => ['الفئة', 'Catégorie', 'Category'],
    'job'       => ['الوظيفة', 'Fonction', 'Position'],
    'dipl'      => ['الشهادة', 'Diplôme', 'Diploma'],
    'spec'      => ['الاختصاص', 'Spécialité', 'Specialization'],
    'subj'      => ['المواد', 'Matières', 'Subjects'],
    'cycle'     => ['المرحلة', 'Cycle', 'Level'],
    'classes'   => ['الصفوف', 'Classes', 'Classes'],
    'grade'     => ['الدرجة', 'Échelon', 'Grade'],
    'grade0'    => ['الابتدائية', 'initial', 'initial'],
    'hours'     => ['الدوام', 'Horaire', 'Schedule'],
    'h_wk'      => ['ساعة/أسبوع', 'h/semaine', 'h/week'],
    'd_wk'      => ['يوم/أسبوع', 'jours/semaine', 'days/week'],
    'entry'     => ['تاريخ الدخول', "Date d'entrée", 'Entry date'],
    'titul'     => ['تاريخ الملاك', 'Titularisation', 'Tenure date'],
    'confirm'   => ['تثبيت الملاك', 'Confirmation', 'Tenure confirmation'],
    'status'    => ['الحالة', 'Statut', 'Status'],
    'after64'   => ['استمرار العمل بعد ٦٤', 'Travail après 64 ans', 'Working after 64'],
    'cnss'      => ['رقم الضمان', 'N° CNSS', 'NSSF number'],
    'mof'       => ['الرقم المالي', 'N° Finances', 'Finance number'],
    'eoc'       => ['رقم الصندوق', 'N° Caisse', 'Fund number'],
    'left_all'  => ['الترك من الكل', 'Cessation (toutes)', 'Left (all)'],
    'left_cnss' => ['ترك الضمان', 'Cessation CNSS', 'Left NSSF'],
    'left_mof'  => ['ترك المالية', 'Cessation Finances', 'Left Finance'],
    'left_eoc'  => ['ترك الصندوق', 'Cessation Caisse', 'Left Fund'],
    'mode'      => ['طريقة الراتب', 'Mode de salaire', 'Salary mode'],
    'base'      => ['أساس الراتب', 'Salaire de base', 'Base salary'],
    'period'    => ['الفترة المدفوعة', 'Période payée', 'Paid period'],
    'm13'       => ['الشهر 13', '13e mois', '13th month'],
    'fam'       => ['التعويض العائلي', 'Allocations familiales', 'Family allowance'],
    'spouse'    => ['زوج', 'conjoint', 'spouse'],
    'children'  => ['أولاد', 'enfants', 'children'],
    'transport' => ['النقل', 'Transport', 'Transport'],
    'day'       => ['يوم/أسبوع', 'j/sem.', 'd/wk'],
    'week'      => ['أسبوع', 'sem.', 'wk'],
    'tax'       => ['الضريبة', 'Impôt', 'Income tax'],
    'cnss_s'    => ['الضمان', 'CNSS', 'NSSF'],
    'eoc_s'     => ['الصندوق', 'Caisse', 'Fund'],
    'yes'       => ['نعم', 'Oui', 'Yes'],
    'no'        => ['لا', 'Non', 'No'],
    'incl'      => ['يشمل', 'inclut', 'includes'],
    'base_only' => ['الأساس فقط', 'base seule', 'base only'],
    'ech'       => ['التدرّج', 'échelon', 'increments'],
    'extra'     => ['الإضافي', 'supplément', 'extra pay'],
    'aide'      => ['المكافأة', 'prime/aide', 'bonus'],
    'm_law'     => ['قانون العمل (الحد الأدنى للأجور الساري)', 'Code du travail (salaire minimum en vigueur)', 'Labour law (current minimum wage)'],
    'm_usd'     => ['راتب مباشر بالدولار', 'Salaire direct en dollars', 'Direct salary in USD'],
    'm_pct'     => ['نسبة من راتب الليرة', 'Pourcentage du salaire en livres', 'Percentage of LBP salary'],
    'm_lbp'     => ['راتب مباشر بالليرة', 'Salaire direct en livres', 'Direct salary in LBP'],
    'issued'    => ['صدر بتاريخ', 'Émis le', 'Issued on'],
    'months'    => ['شهراً', 'mois', 'months'],
    'type_tit'  => ['أستاذ في الملاك', 'Enseignant titulaire', 'Tenured teacher'],
    'type_con'  => ['أستاذ متعاقد', 'Enseignant contractuel', 'Contract teacher'],
    'type_emp'  => ['موظف إداري', 'Employé administratif', 'Administrative employee'],
    'st_actif'  => ['نشط', 'Actif', 'Active'], 'st_suspendu' => ['متوقف', 'Suspendu', 'Suspended'], 'st_retraite' => ['متقاعد', 'Retraité', 'Retired'], 'st_demissionne' => ['مستقيل', 'Démissionné', 'Resigned'],
];
$li = ['ar' => 0, 'fr' => 1, 'en' => 2][$L];
$t = fn($k) => $T[$k][$li] ?? $T[$k][1];
$vl = ($L === 'ar') ? 'ar' : 'fr';   // لغة الدوال المشتركة (عربي/فرنسي) — الإنكليزي يأخذ الفرنسي حيث لا ترجمة
$rtl = ($L === 'ar');

// 🌐 («بالعربي يعني كل شي فيها بالعربي» 2026-10-19): حتى قيم الملف المكتوبة بلغة ثانية (الجنسية، المرحلة، المواد، الصفوف، العملة)
//    تُترجَم للغة الورقة — قاموس مصطلحات [عربي, فرنسي, إنكليزي] + مرادفات كما تُكتب بالملفات؛ ما ليس بالقاموس يبقى كما هو.
$TERMS = [
    [['لبناني', 'Libanais', 'Lebanese'], ['lebanese', 'libanaise', 'libanais', 'لبنانية', 'لبناني']],
    [['سوري', 'Syrien', 'Syrian'], ['syrian', 'syrie', 'syrien', 'syrienne', 'سورية', 'سوري']],
    [['فلسطيني', 'Palestinien', 'Palestinian'], ['palestinian', 'palestinien', 'palestinienne', 'فلسطينية', 'فلسطيني']],
    [['مصري', 'Égyptien', 'Egyptian'], ['egyptian', 'égyptien', 'egyptien', 'مصرية', 'مصري']],
    [['أخرى', 'Autre', 'Other'], ['other', 'autre']],
    [['حضانة', 'Maternelle', 'Kindergarten'], ['maternelle', 'kindergarten', 'روضة']],
    [['ابتدائي', 'Primaire', 'Primary'], ['primaire', 'primary', 'ابتدائي']],
    [['متوسط', 'Intermédiaire', 'Intermediate'], ['intermediaire', 'intermédiaire', 'intermediate', 'complémentaire', 'متوسط', 'تكميلي']],
    [['ثانوي', 'Secondaire', 'Secondary'], ['secondaire', 'secondary', 'ثانوي']],
    [['لغة فرنسية', 'Langue française', 'French language'], ['langue française', 'langue francaise', 'french language', 'اللغة الفرنسيّة', 'اللغة الفرنسية', 'لغة فرنسية']],
    [['لغة إنكليزية', 'Langue anglaise', 'English language'], ['english language', 'langue anglaise', 'اللغة الإنكليزية', 'لغة إنكليزية', 'لغة انكليزية']],
    [['أدب إنكليزي', 'Littérature anglaise', 'English literature'], ['english literature', 'littérature anglaise']],
    [['لغة عربية', 'Langue arabe', 'Arabic language'], ['langue arabe', 'arabic language', 'اللغة العربية و ادابها', 'اللغة العربية وآدابها', 'اللغة العربية', 'اللّغة العربيّة', 'لغة عربية']],
    [['كل المواد', 'toutes les matières', 'all subjects'], ['toutes les matières', 'toutes les matieres', 'all subjects', 'كل المواد']],
    [['فرنسي', 'Français', 'French'], ['français', 'francais', 'french', 'فرنسي']],
    [['إنكليزي', 'Anglais', 'English'], ['english', 'anglais', 'إنكليزي', 'انكليزي', 'إنجليزي']],
    [['عربي', 'Arabe', 'Arabic'], ['arabe', 'arabic', 'عربي']],
    [['رياضيات', 'Maths', 'Maths'], ['mathématiques', 'mathematiques', 'maths', 'math', 'الرياضيات', 'رياضيات']],
    [['علوم الحياة', 'SVT', 'Life sciences'], ['svt', 'life sciences', 'علوم الحياة']],
    [['علوم', 'Sciences', 'Sciences'], ['sciences', 'science', 'علوم']],
    [['كيمياء', 'Chimie', 'Chemistry'], ['chimie', 'chemistry', 'كيمياء']],
    [['أحياء', 'Biologie', 'Biology'], ['biologie', 'biology', 'أحياء', 'بيولوجيا']],
    [['فيزياء', 'Physique', 'Physics'], ['physique', 'physics', 'فيزياء']],
    [['معلوماتية', 'Informatique', 'Computer science'], ['informatique', 'computer science', 'computer', 'معلوماتيه', 'معلوماتية', 'كمبيوتر']],
    [['رياضة', 'EPS', 'Physical education'], ['eps', 'sport', 'physical education', 'رياضة']],
    [['تربية وطنية', 'Éducation civique', 'Civics'], ['éducation civique', 'education civique', 'civisme', 'civics', 'التّربية الوطنيّة', 'التربية الوطنية', 'تربية وطنية', 'تربية']],
    [['تاريخ', 'Histoire', 'History'], ['histoire', 'history', 'تاريخ']],
    [['جغرافيا', 'Géographie', 'Geography'], ['géographie', 'geographie', 'geography', 'جغرافيا']],
    [['اجتماعيات', 'Sciences sociales', 'Social studies'], ['sciences sociales', 'social studies', 'اجتماعيات', 'اجتماع']],
    [['اقتصاد', 'Économie', 'Economics'], ['économie', 'economie', 'economics', 'اقتصاد']],
    [['فلسفة', 'Philosophie', 'Philosophy'], ['philosophie', 'philosophy', 'فلسفة']],
    [['حقوق', 'Droit', 'Law'], ['droit', 'law', 'حقوق']],
    [['تعليم مسيحي', 'Catéchèse', 'Religion'], ['catéchèse', 'catechese', 'religion', 'تعليم مسيحي', 'تعليم دبني', 'تعليم ديني']],
    [['فنون', 'Arts', 'Arts'], ['arts plastiques', 'arts', 'art', 'فنون', 'رسم']],
    [['موسيقى', 'Musique', 'Music'], ['musique', 'music', 'موسيقى']],
    [['محاسبة', 'Comptabilité', 'Accounting'], ['comptabilité', 'comptabilite', 'accounting', 'محاسبة']],
    [['إدارة', 'Administration', 'Administration'], ['administration', 'ادارة', 'إدارة']],
    [['ناظر/ة', 'Surveillant(e)', 'Supervisor'], ['surveillante', 'surveillant', 'supervisor', 'ناظرة', 'ناظر', 'نظارة', 'مراقبة الامتحانات']],
    [['و', 'et', 'and'], [' et ', ' and ', ' و ']],
];
$termMap = [];   // مرادف (بأحرف صغيرة) ⇒ [ar, fr, en]
foreach ($TERMS as [$tr, $aliases]) { foreach (array_merge($aliases, $tr) as $a) $termMap[mb_strtolower(trim($a), 'UTF-8')] = $tr; }
uksort($termMap, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));   // الأطول أوّلاً كي لا يُقصّ مصطلح داخل آخر
$trTerms = function (?string $s) use ($termMap, $li): string {
    $s = trim((string)$s); if ($s === '') return '';
    $out = ''; $rest = $s;
    while ($rest !== '') {
        $hit = null; $hitLen = 0;
        foreach ($termMap as $alias => $tr) {
            $len = mb_strlen($alias, 'UTF-8');
            if (mb_strtolower(mb_substr($rest, 0, $len, 'UTF-8'), 'UTF-8') !== $alias) continue;
            // حدود كلمة: اللاتيني لا يُقصّ داخل كلمة أطول (math داخل maths يحلّه ترتيب الطول، هنا نمنع «art» داخل «arte»)
            $next = mb_substr($rest, $len, 1, 'UTF-8'); $prev = mb_substr($out, -1, 1, 'UTF-8');
            if (preg_match('/[\p{L}]$/u', $alias)) {   // حدود كلمة بكل اللغات: لا يُقصّ مصطلح داخل كلمة أطول (تربية داخل التربية، art داخل arte)
                if ($next !== '' && preg_match('/^[\p{L}]$/u', $next)) continue;
                if ($prev !== '' && preg_match('/^[\p{L}]$/u', $prev)) continue;
            }
            $hit = $tr; $hitLen = $len; break;
        }
        if ($hit) { $pad = ($hit[0] === 'و') ? ' ' : ''; $out .= $pad . $hit[$li] . $pad; $rest = mb_substr($rest, $hitLen, null, 'UTF-8'); }
        else { $out .= mb_substr($rest, 0, 1, 'UTF-8'); $rest = mb_substr($rest, 1, null, 'UTF-8'); }
    }
    return preg_replace('/\s{2,}/u', ' ', trim($out));
};
$trList = fn(?string $csv) => implode($rtl ? '، ' : ', ', array_filter(array_map(fn($x) => $trTerms($x), preg_split('/\s*[,،]\s*/u', trim((string)$csv)))));
// أسماء الأشخاص والأماكن المكتوبة باللغة الأخرى: قاموس الأسماء والأماكن (translit_ar_fr) بالاتجاهين — كلمة مجهولة ⇒ النصّ كما هو
$deacc = fn(string $s) => strtr(mb_strtolower(trim($s), 'UTF-8'), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', '-' => ' ']);
$frToAr = null; $frPlaceToAr = null;
$nameToLang = function (?string $s) use (&$frToAr, $rtl, $deacc): string {
    $s = trim((string)$s); if ($s === '') return '';
    $isLatin = !preg_match('/\p{Arabic}/u', $s);
    if ($rtl && $isLatin) {
        if ($frToAr === null) { $frToAr = []; foreach ([arFrFirstDict(), arFrLastDict()] as $d) foreach ($d as $ar => $fr) { $k = $deacc($fr); if (!isset($frToAr[$k])) $frToAr[$k] = $ar; } }
        $out = [];
        foreach (preg_split('/\s+/u', $s) as $w) { $k = $deacc($w); if (!isset($frToAr[$k])) return $s; $out[] = $frToAr[$k]; }
        return implode(' ', $out);
    }
    if (!$rtl && !$isLatin) {
        // بالقاموس فقط (لا نقل حروف آلي يعطي «Mrghrit Hkim») — كلمة مجهولة ⇒ الاسم العربي كما هو
        $first = arFrFirstDict(); $last = arFrLastDict(); $out = [];
        foreach (preg_split('/\s+/u', $s) as $w) {
            $n = arFrNormalize($w); $core = (mb_substr($n, 0, 2, 'UTF-8') === 'ال' && mb_strlen($n, 'UTF-8') > 3) ? mb_substr($n, 2, null, 'UTF-8') : $n;
            $hit = $first[$n] ?? $last[$n] ?? $last[$core] ?? $first[$core] ?? null;
            if ($hit === null) return $s;
            $out[] = $hit;
        }
        return implode(' ', $out);
    }
    return $s;
};
$placeToLang = function (?string $s) use (&$frPlaceToAr, $rtl, $deacc): string {
    $s = trim((string)$s); if ($s === '') return '';
    $isLatin = !preg_match('/\p{Arabic}/u', $s);
    if (!$rtl && !$isLatin) return arPlaceToFr($s);
    if ($rtl && $isLatin) {
        if ($frPlaceToAr === null) { $frPlaceToAr = []; foreach (arPlaceDict() as $ar => $fr) { $k = $deacc($fr); if (!isset($frPlaceToAr[$k])) $frPlaceToAr[$k] = $ar; } }
        $segs = preg_split('/\s*[-–\/،,]\s*/u', $s); $out = [];
        foreach ($segs as $seg) { $k = $deacc($seg); if ($seg === '' ) continue; if (preg_match('/^\d+$/', $seg)) { $out[] = $seg; continue; } if (!isset($frPlaceToAr[$k])) return $s; $out[] = $frPlaceToAr[$k]; }
        return implode(' - ', $out);
    }
    return $s;
};

$isTeacher = (($emp['employee_type'] ?? '') !== 'employe');
$nameAr = trim(implode(' ', array_filter([$emp['first_name_ar'] ?? '', $emp['father_name_ar'] ?? '', $emp['last_name_ar'] ?? ''])));
$nameFr = trim(implode(' ', array_filter([$emp['first_name_fr'] ?? '', $emp['father_name_fr'] ?? '', $emp['last_name_fr'] ?? ''])));
$titleDoc = $t($isTeacher ? 'title_t' : 'title_e');
$pageTitle = ($isTeacher ? "Fiche de l'enseignant" : "Fiche de l'employé") . ' / ' . ($isTeacher ? 'بطاقة الأستاذ' : 'بطاقة الموظف');
$exportTitle = 'Fiche_' . strtoupper($L) . '_' . (preg_replace('/[^A-Za-z0-9]+/', '_', trim(($emp['first_name_fr'] ?? '') . '_' . ($emp['last_name_fr'] ?? ''))) ?: 'Fiche');
$school = currentSchool();
$exportOpts = [
    'phone' => (trim((string)($school['phone'] ?? '')) ?: ($emp['phone1'] ?: '')),
    'email' => (trim((string)($school['email'] ?? '')) ?: ($emp['email'] ?? '')),
];
$backHref = BASE_URL . 'pages/attestations.php?employee_id=' . $employeeId . '&dossier=1';

// ===== القيم — نفس مصادر «الملف الكامل»، مترجمة للغة المختارة، والفارغ لا يُطبع =====
$num  = fn($v) => rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
$typeKey = ['enseignant_titulaire' => 'type_tit', 'enseignant_contractuel' => 'type_con', 'employe' => 'type_emp'][$emp['employee_type'] ?? ''] ?? null;
$typeLbl = $typeKey ? $t($typeKey) : employeeTypeLabel($emp['employee_type'], $vl);
$stKey = 'st_' . ($emp['status'] ?? '');
$statusLbl = isset($T[$stKey]) ? $t($stKey) : (employeeStatusLabel($emp['status'], $vl)['label'] ?? '');
$addr = implode(' - ', array_values(array_filter(array_map('trim', [
    $emp['ville'] ?? '', $emp['quartier'] ?? '', $emp['rue'] ?? '', $emp['immeuble'] ?? '',
    ($emp['etage'] ?? '') !== '' ? ($t('floor') . ' ' . $emp['etage']) : '', $emp['district'] ?? '', $emp['gouvernorat'] ?? '',
]))));
$registry = trim(($emp['civil_registry_number'] ?? '') . (($emp['civil_registry_place'] ?? '') ? (' / ' . $placeToLang($emp['civil_registry_place'])) : ''));
$mother   = $nameToLang(trim(($emp['mother_first_name'] ?? '') . ' ' . ($emp['mother_last_name'] ?? '')));
$birth    = trim(($emp['birth_date'] ? formatDate($emp['birth_date']) : '') . (($emp['birth_place'] ?? '') ? (' — ' . $placeToLang($emp['birth_place'])) : ''));
$addr     = $placeToLang($addr);
$social   = trim((string)socialStatusLabel($emp['social_status'] ?? '', $vl));
if ($L === 'en' && preg_match('/^(celibataire|(marie|veuf)_(sans_enfants|(\d+)_enfants?))$/', (string)($emp['social_status'] ?? ''), $sm)) {   // الإنكليزي من الرمز
    $kind = $sm[1] === 'celibataire' ? 'Single' : ($sm[2] === 'marie' ? 'Married' : 'Widowed');
    $kids = $sm[1] === 'celibataire' ? '' : (($sm[3] === 'sans_enfants') ? ', no children' : (', ' . (int)$sm[4] . ((int)$sm[4] === 1 ? ' child' : ' children')));
    $social = $kind . $kids;
}
if ($social === '' ) $social = trim((string)($emp['social_status'] ?? ''));
if ($social !== '' && (int)($emp['spouse_works'] ?? 0) === 1) $social .= ' (' . $t('spouse_w') . ')';
$phones   = implode(' · ', array_filter([trim((string)($emp['phone1'] ?? '')), trim((string)($emp['phone2'] ?? ''))]));
$modeLbl  = isLaborLawSalary($emp) ? $t('m_law')
          : (['direct_usd' => $t('m_usd'), 'percent_of_lbp' => $t('m_pct'), 'direct_lbp' => $t('m_lbp')][$emp['salary_input_mode'] ?? ''] ?? ($emp['salary_input_mode'] ?? ''));
$baseLbl  = '';
if (($emp['salary_input_mode'] ?? '') === 'direct_usd' && (float)($emp['base_salary_usd'] ?? 0)) $baseLbl = '$' . number_format((float)$emp['base_salary_usd'], 2);
if (($emp['salary_input_mode'] ?? '') === 'percent_of_lbp' && (float)($emp['base_salary_lbp_percent'] ?? 0)) $baseLbl = $num($emp['base_salary_lbp_percent']) . '%';
if (($emp['salary_input_mode'] ?? '') === 'direct_lbp' && (float)($emp['contract_salary_lbp'] ?? 0)) $baseLbl = formatLBP($emp['contract_salary_lbp']);
$transport = ((float)($emp['transport_daily_amount'] ?? 0) > 0)
    ? ($num($emp['transport_daily_amount']) . ' ' . ($emp['transport_daily_currency'] ?? 'LBP') . ' × ' . (int)($emp['transport_days_per_week'] ?? 0) . ' ' . $t('day') . ' × ' . $num($emp['transport_weeks'] ?? 0) . ' ' . $t('week'))
    : '';
$incShort = fn($ech, $ext, $pa) => implode('، ', array_filter([(int)$ech ? $t('ech') : '', (int)$ext ? $t('extra') : '', (int)$pa ? $t('aide') : ''])) ?: $t('base_only');
if (!$rtl) $incShort = fn($ech, $ext, $pa) => implode(', ', array_filter([(int)$ech ? $t('ech') : '', (int)$ext ? $t('extra') : '', (int)$pa ? $t('aide') : ''])) ?: $t('base_only');
$subj = fn($on, $ech, $ext, $pa) => (int)$on ? ($t('yes') . ': ' . $incShort($ech, $ext, $pa)) : $t('no');
$m13 = (int)($emp['has_13th_month'] ?? 0)
    ? ($t('yes') . ((($emp['m13_include_extra'] ?? 0) || ($emp['m13_include_aide'] ?? 0)) ? ' — ' . $t('incl') . ': ' . implode($rtl ? '، ' : ', ', array_filter([($emp['m13_include_extra'] ?? 0) ? $t('extra') : '', ($emp['m13_include_aide'] ?? 0) ? $t('aide') : ''])) : ''))
    : $t('no');
$grade = trim((string)gradeDisplay($emp));
if ((float)($emp['starting_grade'] ?? 0) > 0) $grade .= ($grade !== '' ? ' ' : '') . '(' . $t('grade0') . ' ' . $num($emp['starting_grade']) . ')';
$hours = implode(' · ', array_filter([
    (float)($emp['hours_per_week'] ?? 0) > 0 ? ($num($emp['hours_per_week']) . ' ' . $t('h_wk')) : '',
    (int)($emp['days_per_week'] ?? 0) > 0 ? ((int)$emp['days_per_week'] . ' ' . $t('d_wk')) : '',
]));
$famAll = implode(' · ', array_filter([
    (float)($emp['family_allowance_spouse_lbp'] ?? 0) ? ($t('spouse') . ': ' . formatLBP($emp['family_allowance_spouse_lbp'])) : '',
    (float)($emp['family_allowance_children_lbp'] ?? 0) ? ($t('children') . ': ' . formatLBP($emp['family_allowance_children_lbp'])) : '',
]));
// الفترة المدفوعة: عربي/فرنسي من الدالة المشتركة، والإنكليزي بأسماء الأشهر الإنكليزية
if ($L === 'en') {
    $pm = paidMonthsFor($emp, 2000, 2001);
    $period = $pm ? (date('F', mktime(0, 0, 0, (int)$pm[0][0], 1)) . ' → ' . date('F', mktime(0, 0, 0, (int)$pm[count($pm) - 1][0], 1)) . ' (' . count($pm) . ' ' . $t('months') . ')') : '';
} else {
    $period = paidPeriodLabel($emp, $L);
}
// الصفوف: بالعربي الاسم العربي وحده («روضة ثالثة» بدل «GS / روضة ثالثة»)، وبغيره الرمز الفرنسي
$classes = classLevelNames($emp['classes_taught'] ?? '', !$rtl);
if ($rtl) $classes = implode('، ', array_map(fn($c) => trim((string)preg_replace('/^.*?\s\/\s/u', '', $c)), preg_split('/\s*،\s*/u', (string)$classes, -1, PREG_SPLIT_NO_EMPTY)));
if ($L === 'en') $classes = str_replace('Secondaire', 'Secondary', (string)$classes);
$natLbl  = $trTerms($emp['nationality'] ?? '');
$cycleLbl = $trList($emp['niveau_scolaire'] ?? '');
// قاموس الموادّ المشترك أوّلاً؛ إن بقي شيء بغير لغة الورقة يكمل قاموس البطاقة (EPS، informatique…)
$subjFix = function (?string $raw) use ($L, $rtl, $trTerms): string {
    $s = subjectToLang($raw ?? '', $L);
    $foreign = $rtl ? preg_match('/[A-Za-z]/', $s) : preg_match('/\p{Arabic}/u', $s);
    return $foreign ? $trTerms($s) : $s;
};
$subjLbl = $subjFix($emp['subjects_taught'] ?? '');
$specLbl = $subjFix($emp['specialization'] ?? '');
$curFix = fn($v) => $rtl ? str_replace([' L.L', 'LBP', 'USD'], [' ل.ل', 'ل.ل', '$'], (string)$v) : (string)$v;   // العملة بالعربي: ل.ل / $

// [التسمية, القيمة, كامل السطر؟] — القيمة الفارغة تُحذف
$S = [];
// الاسم بلغة الورقة وحده (الاسم باللغة الأخرى يظهر بسطر العنوان)
$S['s_pers'] = $rtl ? [[$t('name_ar'), $nameAr ?: $nameFr, true]] : [[$t('name_fr'), $nameFr ?: $nameAr, true]];
$S['s_pers'] = array_merge($S['s_pers'], [
    [$t('mother'), $mother],
    [$t('nat'), $natLbl],
    [$t('birth'), $birth],
    [$t('reg'), $registry],
    [$t('social'), $social],
    [$t('kids'), (int)($emp['number_of_children'] ?? 0) > 0 ? (string)(int)$emp['number_of_children'] : ''],
]);
$S['s_contact'] = [
    [$t('tel'), $phones],
    [$t('email'), $emp['email'] ?? ''],
    [$t('addr'), $addr, true],
];
$S['s_job'] = [
    [$t('cat'), $typeLbl],
    [$t('job'), (!$isTeacher && ($emp['job_title'] ?? '') !== '') ? jobTitleLabel($emp['job_title'], $vl) : ''],
    [$t('dipl'), ($emp['diploma'] ?? '') ? diplomaLabel($emp['diploma'], $vl) : ''],
    [$t('spec'), $specLbl],
    [$t('subj'), $subjLbl],
    [$t('cycle'), $cycleLbl],
    [$t('classes'), $classes],
    [$t('grade'), $grade],
    [$t('hours'), $hours],
    [$t('entry'), shownHireDate($emp) ? formatDate(shownHireDate($emp)) : ''],
    [$t('titul'), shownTitularizationDate($emp) ? formatDate(shownTitularizationDate($emp)) : ''],
    [$t('confirm'), $emp['tenure_confirmation_date'] ? formatDate($emp['tenure_confirmation_date']) : ''],
    [$t('status'), $statusLbl],
    [$t('after64'), (int)($emp['keep_working_past_64'] ?? 0) === 1 ? $t('yes') : ''],
];
$S['s_off'] = [
    [$t('cnss'), cnssWithBirthYear($emp['nssf_number'] ?? '', $emp['birth_date'] ?? '', '')],
    [$t('mof'), $emp['finance_ministry_number'] ?? ''],
    [$t('eoc'), $emp['caisse_number'] ?? ''],
    [$t('left_all'), !empty($emp['left_date_all']) ? formatDate($emp['left_date_all']) : ''],
    [$t('left_cnss'), !empty($emp['left_date_cnss']) ? formatDate($emp['left_date_cnss']) : ''],
    [$t('left_mof'), !empty($emp['left_date_finance']) ? formatDate($emp['left_date_finance']) : ''],
    [$t('left_eoc'), !empty($emp['left_date_eoc']) ? formatDate($emp['left_date_eoc']) : ''],
];
$S['s_sal'] = [
    [$t('mode'), $modeLbl],
    [$t('base'), $curFix($baseLbl)],
    [$t('period'), $period],
    [$t('m13'), $m13],
    [$t('fam'), $curFix($famAll)],
    [$t('transport'), $curFix($transport)],
    [$t('tax'), $subj($emp['tax_subject'] ?? 0, $emp['tax_includes_echelon'] ?? 0, $emp['tax_includes_extra'] ?? 0, $emp['tax_includes_prime_aide'] ?? 0)],
    [$t('cnss_s'), $subj($emp['cnss_subject'] ?? 0, $emp['cnss_includes_echelon'] ?? 0, $emp['cnss_includes_extra'] ?? 0, $emp['cnss_includes_prime_aide'] ?? 0)],
    [$t('eoc_s'), $subj($emp['eoc_subject'] ?? 0, $emp['eoc_includes_echelon'] ?? 0, $emp['eoc_includes_extra'] ?? 0, $emp['eoc_includes_prime_aide'] ?? 0)],
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
.fiche-a4.ltr .fiche-photo{text-align:right}
.fiche-a4 .fiche-photo img{width:26mm;height:32mm;object-fit:cover;border:1px solid #cbd5e1;border-radius:4px}
.fiche-a4 .doc-title{margin:4px 0 2px;font-size:18pt}
.fiche-a4 .doc-subtitle{margin:0 0 4px}
.fiche-a4 table.pdf-info{width:100%;border-collapse:collapse;margin:2px 0 6px;font-size:12pt}
.fiche-a4 table.pdf-info td{border:0;border-bottom:1px dotted #94a3b8;vertical-align:top}
.fiche-a4 .k{color:#334155}
.fiche-a4 .fill{font-weight:700;color:#0a3d91;display:inline;padding:0 3px}
.fiche-a4 .fiche-foot{font-size:10pt;color:#64748b;text-align:center;border-top:1px dashed #cbd5e1;margin-top:12px;padding-top:4px}
.export-toolbar .rl-chips{display:none !important}   /* لغة الورقة من حبوب البطاقة نفسها (العربية / Français / English) لا من قاموس التقارير */
.fiche-langs{display:inline-flex;gap:6px;flex-wrap:wrap;align-items:center}
.fiche-langs .btn.on{outline:3px solid #c9a961;outline-offset:1px}
</style>
<?php
// خانة = تسمية + قيمة على سطر منقّط؛ الخانة التي لا تسع نصف السطر تأخذ السطر كلّه كي لا تتكسّر
$tdStyle = 'padding:1pt 4pt 0.5pt;line-height:1.2;vertical-align:top;border:0;border-bottom:0.5pt dotted #94a3b8';
$mmEst = function (string $txt): float {   // تقدير عرض النصّ بالملم بخط Arial 12pt (نفس تقدير محرّك الطباعة MsaPdf::textMmEst)
    $w = 0.0;
    foreach (preg_split('//u', $txt, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
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
$dirAttr = $rtl ? 'rtl' : 'ltr';
$infoTable = function (array $rows) use ($cell, $tdStyle, $mmEst, $dirAttr) {
    $out = '<table class="pdf-info" dir="' . $dirAttr . '" width="100%" style="width:100%;border-collapse:collapse;margin:2pt 0 5pt">';
    $pair = [];
    $flush = function () use (&$pair, &$out, $cell, $tdStyle) {
        if (!$pair) return;
        $out .= '<tr>' . $cell($pair[0], 1) . (isset($pair[1]) ? $cell($pair[1], 1) : '<td style="width:50%;' . $tdStyle . '"></td>') . '</tr>';
        $pair = [];
    };
    foreach ($rows as $r) {
        $full = !empty($r[2]) || $mmEst($r[0] . ': ' . $r[1]) > 84;
        if ($full) { $flush(); $out .= '<tr>' . $cell($r, 2) . '</tr>'; continue; }
        $pair[] = $r;
        if (count($pair) === 2) $flush();
    }
    $flush();
    return $out . '</table>';
};
$langUrl = fn($k) => BASE_URL . 'pages/employee_fiche.php?employee_id=' . $employeeId . '&lang_doc=' . $k;
?>
<div class="d-flex justify-between align-center mb-3 no-print" style="flex-wrap:wrap;gap:8px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="<?= e($backHref) ?>" class="btn btn-light"><i class="fas fa-arrow-left"></i> Dossier complet / الملف الكامل</a>
        <span class="fiche-langs" title="لغة البطاقة / Langue de la fiche">
            <?php foreach ($DOC_LANGS as $k => $lbl): ?>
            <a href="<?= e($langUrl($k)) ?>" class="btn btn-sm btn-light<?= $k === $L ? ' on' : '' ?>"><i class="fas fa-language"></i> <?= e($lbl) ?></a>
            <?php endforeach; ?>
        </span>
    </div>
    <?php if (viewerCanSeePage('employees.php')): ?>
    <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $employeeId ?>" class="btn btn-light"><i class="fas fa-user-pen"></i> Modifier le dossier / تعديل ملف الأستاذ</a>
    <?php endif; ?>
</div>

<div id="ppExportArea">
<div class="official-doc <?= $rtl ? 'rtl' : 'ltr' ?> fiche-a4 <?= $rtl ? '' : 'ltr' ?>" dir="<?= $dirAttr ?>">
    <div style="line-height:1.25"><?= schoolLetterhead($school) ?></div>
    <table class="fiche-top" dir="<?= $dirAttr ?>"><tr>
        <td>
            <div class="doc-title" style="line-height:1.2;margin:0 0 1pt"><?= e($titleDoc) ?></div>
            <div class="doc-subtitle" style="margin:0 0 2pt;line-height:1.2"><strong><?= e($rtl ? ($nameAr ?: $nameFr) : ($nameFr ?: $nameAr)) ?></strong><?php if ($nameAr && $nameFr): ?> — <span dir="<?= $rtl ? 'ltr' : 'rtl' ?>"><?= e($rtl ? $nameFr : $nameAr) ?></span><?php endif; ?><?php if (!empty($emp['employee_code'])): ?> · <?= e($emp['employee_code']) ?><?php endif; ?></div>
        </td>
        <?php if ($photoUrl): ?><td class="fiche-photo"><img src="<?= $photoUrl ?>" alt=""></td><?php endif; ?>
    </tr></table>

    <?php foreach ($S as $sk => $rows): ?>
        <?php $rows = array_values(array_filter($rows, fn($r) => trim((string)$r[1]) !== '')); if (!$rows) continue; ?>
        <div class="doc-section" style="margin:5pt 0 1pt;line-height:1.2"><?= e($t($sk)) ?></div>
        <?= $infoTable($rows) ?>
    <?php endforeach; ?>

    <?php if ($notes !== ''): ?>
    <div class="doc-section" style="margin:5pt 0 1pt;line-height:1.2"><?= e($t('s_notes')) ?></div>
    <div style="line-height:1.3"><?= nl2br(e($notes)) ?></div>
    <?php endif; ?>

    <div class="fiche-foot"><?= e($rtl ? ($school['name_ar'] ?? '') : ($school['name_fr'] ?? ($school['name_ar'] ?? ''))) ?> · <?= e($titleDoc) ?> · <?= e($t('issued')) ?> <?= date('d/m/Y') ?><?= $rtl ? '' : ' · MSA Payroll' ?></div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
