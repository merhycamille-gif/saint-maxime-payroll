<?php
/**
 * فورم تحديث/تجديد معلومات الأستاذ — عام (بلا تسجيل دخول)، عبر رابط بتوكن يُرسَل واتساب.
 * يعبّيه الأستاذ ويرفع سكاناته، فيُحفَظ كطلب (pending) ليستورده المدير من pages/info_collect.php.
 *   ?emp=ID&token=TOKEN
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// تطبيع نصّ للمقارنة (عربي/فرنسي): حروف صغيرة، إزالة التشكيل والتطويل، توحيد الألف/الياء/التاء المربوطة، ضغط الفراغات
function tf_norm($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $s); // تشكيل + تطويل
    $s = strtr($s, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا','ى'=>'ي','ئ'=>'ي','ؤ'=>'و','ة'=>'ه']);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}
/**
 * يطابق اسماً مُدخَلاً (مطبَّعاً) مع صفّ أستاذ: تطابق كامل بأي من الصيغ الأربع (عربي/فرنسي،
 * عادي/معكوس)، أو احتواء المُدخَل على الاسم الأول + الشهرة معاً بأي لغة.
 */
function tf_nameMatches($c, $nq, $nqNS) {
    $arFull = tf_norm(($c['first_name_ar'] ?? '') . ' ' . ($c['last_name_ar'] ?? ''));
    $frFull = tf_norm(($c['first_name_fr'] ?? '') . ' ' . ($c['last_name_fr'] ?? ''));
    $arRev  = tf_norm(($c['last_name_ar'] ?? '') . ' ' . ($c['first_name_ar'] ?? ''));
    $frRev  = tf_norm(($c['last_name_fr'] ?? '') . ' ' . ($c['first_name_fr'] ?? ''));
    foreach ([$arFull, $frFull, $arRev, $frRev] as $cf) {
        if ($cf !== '' && str_replace(' ', '', $cf) === $nqNS) return true;
    }
    // قبول إذا احتوى المُدخَل على الاسم الأول والشهرة معاً (بأي لغة)
    $la = tf_norm($c['last_name_ar'] ?? ''); $lf = tf_norm($c['last_name_fr'] ?? '');
    $fa = tf_norm($c['first_name_ar'] ?? ''); $ff = tf_norm($c['first_name_fr'] ?? '');
    $hasLast  = ($la !== '' && mb_strpos($nq, $la) !== false) || ($lf !== '' && mb_strpos($nq, $lf) !== false);
    $hasFirst = ($fa !== '' && mb_strpos($nq, $fa) !== false) || ($ff !== '' && mb_strpos($nq, $ff) !== false);
    return ($hasLast && $hasFirst);
}

/**
 * خصوصية: يلاقي ملف الأستاذ من اسمه+شهرته+تاريخ ولادته داخل مدرسته فقط، بلا عرض أي لائحة.
 * يُرجع صفّ الأستاذ عند تطابق وحيد مؤكَّد، وإلا null (0 أو أكثر من تطابق).
 *
 * مسار احتياطي (للأساتذة يلي تاريخ ولادتهم ناقص/وهمي «1900-01-01» عند الإدارة): يُلاقَون
 * بالاسم وحده إذا كانوا **وحيدين بمدرستهم هالسنة الدراسية**، فيعبّون تاريخ ولادتهم الصحيح
 * عبر الفورم فيتصحّح ملفهم تلقائياً. لا يُضعِف الخصوصية: الأستاذ ذو التاريخ الصحيح المخزّن
 * يبقى يحتاج تاريخه الصحيح (ليس ضمن مرشّحي الاحتياط).
 */
function findTeacherByNameDob($db, $schoolId, $q, $bd) {
    $nq = tf_norm($q);
    $nqNS = str_replace(' ', '', $nq);
    // الرابط الموحّد لكل المدارس: $schoolId = 0 → بحث عبر كل المدارس (تُكتشف المدرسة من الملف).
    // يبقى التطابق وحيداً أو null عند الغموض، فالخصوصية محفوظة حتى عبر كل المدارس.
    $allSchools = ((int)$schoolId <= 0);
    $schoolClause = $allSchools ? '' : ' AND school_id = ?';
    // اقصر البحث على **الموجودين فعلاً بالسنة الدراسية الحالية** (راتب فعلي بهالسنة + غير تاركين):
    // فلا يُطابَق من ترك أو من سنوات سابقة، ويُحلّ تلقائياً تكرارٌ يكون أحد ملفّيه قديماً/تاركاً.
    [$yf, $yp] = yearEmploymentFilter(currentSchoolYear(), '');
    // (أ) المسار الأساسي: الاسم + تاريخ الولادة بالضبط (يقبل الكتابة اليدوية بأي صيغة)
    $bdNorm = parseFlexibleDate($bd);
    if ($bdNorm) {
        $st = $db->prepare("SELECT * FROM employees WHERE is_deleted = 0$schoolClause AND birth_date = ?" . $yf);
        $st->execute(array_merge($allSchools ? [$bdNorm] : [$schoolId, $bdNorm], $yp));
        $strong = [];
        foreach ($st->fetchAll() as $c) { if (tf_nameMatches($c, $nq, $nqNS)) $strong[] = $c; }
        if (count($strong) === 1) return $strong[0];
        if (count($strong) > 1) return null; // غموض (تطابق متعدد) — لا تخمّن
    }
    // (ب) المسار الاحتياطي: تاريخ ولادة ناقص/وهمي عند الإدارة → بالاسم وحده، ضمن أساتذة هالسنة فقط
    $st2 = $db->prepare("SELECT * FROM employees WHERE is_deleted = 0$schoolClause
        AND (birth_date IS NULL OR birth_date = '0000-00-00' OR birth_date = '1900-01-01')" . $yf);
    $st2->execute($allSchools ? $yp : array_merge([$schoolId], $yp));
    $strong2 = [];
    foreach ($st2->fetchAll() as $c) { if (tf_nameMatches($c, $nq, $nqNS)) $strong2[] = $c; }
    return (count($strong2) === 1) ? $strong2[0] : null;
}

$db = getDB();
$empId = (int)($_GET['emp'] ?? $_POST['emp'] ?? 0);
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$schoolId = (int)($_GET['school'] ?? $_POST['school'] ?? 0);

// 🔒 موعد إقفال باب تحديث/إدخال معلومات الأساتذة. بعده يُقفل الرابط للجميع، إلا إذا فعّل
// الأدمن «السماح بالإدخال بعد الموعد» من صفحة تحديث المعلومات. الافتراضي: 30/8/2026.
$formDeadline   = getSetting('teacher_form_deadline', '2026-08-30');
$formAllowAfter = getSetting('teacher_form_allow_after', '0') === '1';
$formClosed     = ($formDeadline !== '' && !$formAllowAfter && date('Y-m-d') > $formDeadline);

// ثلاثة أوضاع: (أ) رابط موحّد لكل المدارس (يختار مدرسته ثم اسمه) (ب) رابط مدرسة (يختار اسمه) (ج) رابط فردي.
$allFlag = !empty($_GET['all']) || !empty($_POST['all']);
$newFlag = !empty($_GET['new']) || !empty($_POST['new']);   // وضع «أستاذ جديد»
$allMode = $allFlag && hash_equals(allFormToken(), $token);
$schoolLinkValid = $schoolId > 0 && hash_equals(schoolFormToken($schoolId), $token);
$schoolMode = $allMode || $schoolLinkValid;   // بعد اختيار المدرسة في الوضع الموحّد
$directMode = $empId > 0 && hash_equals(infoFormToken($empId), $token);
$valid = $allMode || $schoolLinkValid || $directMode;

// وضع أستاذ جديد: لا يُختار اسم — يُفتح ملف فارغ. متاح فقط بعد التحقّق من رابط المدرسة + اختيار مدرسة.
$newMode = $newFlag && $schoolMode && $schoolId > 0;
$isNew = false;

$emp = null; $needFind = false; $needSchoolSelect = false; $activeSchools = [];
$findError = ''; $verified = false; $findName = ''; $findDob = '';
if ($allMode && $newFlag && $schoolId <= 0) {
    // أستاذ جديد عبر الرابط الموحّد: لا ملف بعد ⇒ لا يمكن كشف مدرسته → يختارها أولاً
    $needSchoolSelect = true;
    $activeSchools = $db->query("SELECT id, name_ar, name_fr FROM schools WHERE is_active = 1 AND is_deleted = 0
        ORDER BY COALESCE(NULLIF(name_ar,''),name_fr)")->fetchAll();
} elseif ($schoolMode) {
    if ($newMode) {
        // أستاذ جديد: ملف فارغ يعبّيه
        $isNew = true;
        $emp = [];
    } else {
        // 🔒 خصوصية: لا لائحة أسماء ولا فتح بالـid. الأستاذ يُثبت هويته باسمه+شهرته+تاريخ ولادته،
        // فيُلاقى ملفه هو فقط ثم يُكمل عبر توكنه الخاص (infoFormToken) — فلا يرى/يفتح ملف أي زميل.
        $findName = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $findDob  = trim((string)($_GET['bd'] ?? $_POST['bd'] ?? ''));
        if ($findName !== '' && $findDob !== '' && !$formClosed) {
            // $schoolId قد يكون 0 في الرابط الموحّد → بحث عبر كل المدارس، وتُكتشف مدرسة الأستاذ من ملفه
            $emp = findTeacherByNameDob($db, $schoolId, $findName, $findDob);
            if ($emp) { $empId = (int)$emp['id']; $schoolId = (int)$emp['school_id']; $verified = true; }
            else { $needFind = true; $findError = 'ما قدرنا نلاقي ملفك بهالمعلومات. تأكّد من كتابة اسمك وشهرتك وتاريخ ولادتك متل ما هنّي مسجّلين عند الإدارة، أو تواصل مع إدارة المدرسة. / Nous n\'avons pas trouvé votre dossier avec ces informations. Vérifiez votre nom, prénom et date de naissance tels qu\'enregistrés par l\'administration, ou contactez l\'école.'; }
        } else {
            $needFind = true;
        }
    }
} elseif ($directMode) {
    $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0");
    $st->execute([$empId]);
    $emp = $st->fetch();
}
// بعد التطابق (أو الرابط الفردي) يُكمل الأستاذ عبر توكنه الخاص فقط — لا توكن المدرسة المشترك
$formToken = (!$isNew && $empId > 0) ? infoFormToken($empId) : $token;
$allHidden = $allMode ? '<input type="hidden" name="all" value="1">' : '';
$newHidden = $isNew ? '<input type="hidden" name="new" value="1">' : '';

// لائحة الشهادات للقائمة المنسدلة
$diplomas = $db->query("SELECT diploma_code, diploma_name_ar, diploma_name_fr, starting_grade FROM diploma_starting_grades ORDER BY starting_grade")->fetchAll();
// لائحة الصفوف (محصّنة: قد لا يكون الجدول موجوداً قبل migration 015، أو عمود name_fr ناقصاً قبل 016 — لذا SELECT *)
try { $classLevels = $db->query("SELECT * FROM class_levels WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(); }
catch (Exception $e) { $classLevels = []; }
$selClasses = array_filter(array_map('intval', explode(',', (string)($emp['classes_taught'] ?? ''))));

// سنوات الدخول للأستاذ الجديد: السنة الدراسية **القادمة فأكثر** فقط (لا يجوز للجديد الدخول على
// السنة الجارية أو ما قبلها). الافتراضي = السنة القادمة (مثلاً 2026-2027 المفتوحة).
$curStart = (int)substr(currentSchoolYear(), 0, 4);   // 2025 إذا الحالية 2025-2026
$entryYearOptions = [];
for ($yy = $curStart + 1; $yy <= $curStart + 3; $yy++) { $entryYearOptions[] = $yy . '-' . ($yy + 1); }
$defaultEntryYear = $entryYearOptions[0];

// الحقول النصية القابلة للتحديث
$textFields = [
    'first_name_ar' => 'الاسم (عربي) / Prénom (arabe)', 'first_name_fr' => 'الاسم (فرنسي) / Prénom (FR)',
    'last_name_ar' => 'الشهرة (عربي) / Nom (arabe)', 'last_name_fr' => 'الشهرة (فرنسي) / Nom (FR)',
    'mother_first_name' => 'اسم الأم / Prénom de la mère', 'mother_last_name' => 'شهرة الأم / Nom de la mère',
    'birth_date' => 'تاريخ الولادة / Date de naissance', 'birth_place' => 'محل الولادة / Lieu de naissance',
    'nationality' => 'الجنسية / Nationalité', 'number_of_children' => 'عدد الأولاد / Nombre d\'enfants',
    'phone1' => 'هاتف 1 / Téléphone 1', 'phone2' => 'هاتف 2 / Téléphone 2', 'email' => 'البريد الإلكتروني / E-mail',
    'gouvernorat' => 'المحافظة / Gouvernorat', 'district' => 'القضاء / District', 'ville' => 'البلدة / Ville',
    'quartier' => 'الحي / Quartier', 'rue' => 'الشارع / Rue', 'immeuble' => 'المبنى / Immeuble', 'etage' => 'الطابق / Étage',
];
// الحقول المهنية الإضافية (شهادة/مواد/ساعات/صفوف/أيام/أرقام رسمية)
$profFields = [
    'subjects_taught' => 'المواد التي يدرّسها / Matières enseignées',
    'hours_per_week' => 'عدد الساعات الأسبوعية / Heures par semaine',
    'days_per_week' => 'عدد أيام الحضور أسبوعياً / Jours par semaine',
    'nssf_number' => 'رقم الضمان الاجتماعي / N° CNSS',
    'finance_ministry_number' => 'رقم المالية / N° Finances',
    'caisse_number' => 'رقم صندوق التعويضات / N° Caisse',
];
$niveauOptions = ['maternelle' => 'حضانة / Maternelle', 'primaire' => 'ابتدائي / Primaire',
    'intermediaire' => 'متوسط / Intermédiaire', 'secondaire' => 'ثانوي / Secondaire'];

$uploadFields = [
    'photo' => 'صورة شخصية / Photo personnelle',
    'id_document' => 'إخراج قيد فردي أو بطاقة الهوية / Extrait individuel ou carte d\'identité',
    'family_doc' => 'إخراج قيد عائلي / Extrait familial',
    'diploma_doc' => 'صورة عن الشهادة / Copie du diplôme',
];
$uploadCol = ['photo' => 'photo_path', 'id_document' => 'id_document_path', 'family_doc' => 'family_doc_path', 'diploma_doc' => 'diploma_doc_path'];
// المستندات الإلزامية (الصورة الشخصية تبقى اختيارية)
$requiredUploads = ['id_document', 'family_doc', 'diploma_doc'];

// 🔴 الحقول غير الإلزامية (كل ما عداها إلزامي). التعديل من هنا فقط.
$optionalFields = ['email', 'phone2', 'nssf_number', 'finance_ministry_number', 'caisse_number', 'leave_date', 'photo'];
$tfReq = function ($k) use ($optionalFields) { return !in_array($k, $optionalFields, true); };
// قيمة الحقل عند إعادة العرض بعد خطأ: يُفضَّل ما أدخله الأستاذ (POST) ثم قيمة ملفه
$fv = function ($k) use ($emp) { return isset($_POST[$k]) ? (string)$_POST[$k] : (string)($emp[$k] ?? ''); };
// وسوم العرض: إلزامي (نجمة حمراء) / اختياري
$reqStar = ' <span style="color:#dc2626;font-weight:700">*</span>';
$optTag  = ' <span style="color:#64748b;font-weight:400;font-size:12px">(اختياري / optionnel)</span>';

$done = false; $error = ''; $uploadWarn = [];
// كشف تجاوز حجم الرفع الكلّي (post_max_size): عندها PHP يُفرّغ POST و FILES مع بقاء Content-Length
$postTooBig = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);
if ($valid && ($isNew || $emp) && $_SERVER['REQUEST_METHOD'] === 'POST' && !$formClosed) {
    // اجمع الحقول النصية
    $data = [];
    foreach ($textFields as $k => $_) { $data[$k] = trim((string)($_POST[$k] ?? '')); }
    // تاريخ الولادة مكتوب يدوياً (يوم/شهر/سنة) → طبّعه إلى Y-m-d قبل الحفظ
    if (isset($data['birth_date']) && $data['birth_date'] !== '') {
        $data['birth_date'] = parseFlexibleDate($data['birth_date']) ?? $data['birth_date'];
    }
    // الحقول المهنية + الشهادة + المرحلة
    $data['diploma'] = trim((string)($_POST['diploma'] ?? ''));
    foreach ($profFields as $k => $_) { $data[$k] = trim((string)($_POST[$k] ?? '')); }
    $data['niveau_scolaire'] = is_array($_POST['niveau_scolaire'] ?? null)
        ? implode(',', array_intersect($_POST['niveau_scolaire'], array_keys($niveauOptions))) : '';
    // الصفوف التي يعلّم فيها (معرّفات class_levels مفصولة بفواصل)
    $data['classes_taught'] = is_array($_POST['classes_taught'] ?? null)
        ? implode(',', array_map('intval', $_POST['classes_taught'])) : '';
    // تاريخ ترك العمل (للأستاذ الحالي فقط، اختياري): إن كتبه الأستاذ فهو ينوي ترك العمل.
    // يُعتمد من المدير فيُسجَّل تاريخ الترك (الضمان/المالية/الصندوق) فيخرج من السنة الجارية.
    if (!$isNew) {
        $ld = parseFlexibleDate($_POST['leave_date'] ?? '');
        if ($ld) $data['leave_date'] = $ld;
    }
    // سنة الدخول (للأستاذ الجديد فقط) — يجب أن تكون ضمن الخيارات المسموحة (القادمة فأكثر)
    if ($isNew) {
        $ey = trim((string)($_POST['entry_school_year'] ?? ''));
        $data['entry_school_year'] = in_array($ey, $entryYearOptions, true) ? $ey : $defaultEntryYear;
        // الراتب الأساسي + الأجر الإضافي + تعويض النقل (كلٌّ بعملته) — تُجهَّز عند إنشاء الملف
        foreach (['new_salary','new_extra','new_transport'] as $nf) {
            $data[$nf] = (float)($_POST[$nf] ?? 0);
            $data[$nf . '_cur'] = (($_POST[$nf . '_cur'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP';
        }
    }

    // ارفع السكانات (اختياري) إلى uploads/submissions
    $dir = __DIR__ . '/../uploads/submissions';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
    $savedFiles = [];
    foreach ($uploadFields as $field => $lbl) {
        $f = $_FILES[$field] ?? null;
        if (!$f || ($f['name'] ?? '') === '') continue; // لم يُرفَع شيء لهذا الحقل
        // نقبل الملف كيفما جاء (صورة أو PDF، كبيراً أو صغيراً) حتى 200 ميغا.
        // فقط إن فشل الرفع فعلاً (تجاوز حدّ السيرفر/رفع جزئي) أو تخطّى 200 ميغا → ننبّه الأستاذ بلا إسقاط صامت.
        if (in_array((int)($f['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL], true)
            || (int)($f['size'] ?? 0) > 200 * 1024 * 1024) {
            $uploadWarn[] = $lbl; continue;
        }
        if (!is_uploaded_file($f['tmp_name'])) continue;
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $subKey = $isNew ? 'new' : $empId;
            $name = 'sub_' . $subKey . '_' . $field . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                $savedFiles[$uploadCol[$field]] = 'uploads/submissions/' . $name;
            }
        }
    }

    // المستندات الإلزامية: إخراج قيد فردي/هوية + إخراج عائلي + صورة الشهادة (الصورة الشخصية اختيارية).
    // يُعفى منها: الأستاذ التارك (كتب تاريخ ترك)، أو مستند موجود مسبقاً بملف أستاذ حالي.
    $leaving = !empty($data['leave_date']);
    $reqUploadCols = [
        'id_document_path' => 'إخراج القيد الفردي أو بطاقة الهوية / Extrait individuel ou carte d\'identité',
        'family_doc_path'  => 'إخراج القيد العائلي / Extrait familial',
        'diploma_doc_path' => 'صورة عن الشهادة / Copie du diplôme',
    ];
    $missingDocs = [];
    if (!$leaving) {
        foreach ($reqUploadCols as $col => $lbl) {
            $onFile = !$isNew && !empty($emp[$col] ?? '');
            if (empty($savedFiles[$col]) && !$onFile) $missingDocs[] = $lbl;
        }
    }
    // الحقول الإلزامية الناقصة: كل الحقول إلزامية إلا $optionalFields. يُعفى منها الأستاذ التارك.
    $missingFields = [];
    if (!$leaving) {
        $reqLabels = [];
        foreach ($textFields as $k => $lbl) { if ($tfReq($k)) $reqLabels[$k] = $lbl; }
        $reqLabels['diploma'] = 'الشهادة العلمية / Diplôme';
        foreach ($profFields as $k => $lbl) { if ($tfReq($k)) $reqLabels[$k] = $lbl; }
        $reqLabels['niveau_scolaire'] = 'المرحلة / Niveau scolaire';
        if ($classLevels) $reqLabels['classes_taught'] = 'الصفوف / Classes';
        foreach ($reqLabels as $k => $lbl) {
            if (trim((string)($data[$k] ?? '')) === '') $missingFields[] = $lbl;
        }
    }
    if ($missingFields || $missingDocs) {
        $all = array_merge($missingFields, $missingDocs);
        $error = 'لا يمكن الإرسال قبل تعبئة كل الحقول الإلزامية. الناقص: ' . implode(' — ', $all)
               . ' / Envoi impossible : champs obligatoires manquants : ' . implode(' — ', $all);
    } else {
        $ins = $db->prepare("INSERT INTO info_submissions (employee_id, is_new_teacher, school_id, data, photo_path, id_document_path, family_doc_path, diploma_doc_path, status, submitted_at)
            VALUES (?,?,?,?,?,?,?,?, 'pending', NOW())");
        $ins->execute([
            $isNew ? null : $empId, $isNew ? 1 : 0,
            $isNew ? $schoolId : ($emp['school_id'] ?? null), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $savedFiles['photo_path'] ?? null, $savedFiles['id_document_path'] ?? null,
            $savedFiles['family_doc_path'] ?? null, $savedFiles['diploma_doc_path'] ?? null,
        ]);
        $done = true;
    }
}

// اسم المدرسة المعروض بالترويسة: يُؤخَذ من مدرسة الرابط (schoolId) منذ أوّل شاشة،
// وإلا من مدرسة الأستاذ المُلاقى. هكذا يظهر اسم المدرسة المحدَّدة دائماً (لا اسم البرنامج).
$schoolName = '';
$nameSchoolId = $schoolId ?: (int)($emp['school_id'] ?? 0);
if ($nameSchoolId) {
    $sc = $db->prepare("SELECT name_ar, name_fr FROM schools WHERE id = ?");
    $sc->execute([$nameSchoolId]);
    $sc = $sc->fetch();
    $schoolName = $sc ? ($sc['name_ar'] ?: $sc['name_fr']) : '';
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تحديث معلومات الأستاذ — Mise à jour des informations de l'enseignant</title>
<?php
  // معاينة الرابط على واتساب/الشبكات: عنوان ووصف ثنائيا اللغة يظهران فور وصول الرابط
  $ogTitle = ($schoolName ? $schoolName . ' — ' : '') . 'تحديث معلومات الأستاذ / Mise à jour des informations';
  $ogDesc  = 'رابط رسمي من إدارة المدرسة لتعبئة وتحديث معلوماتك ورفع مستنداتك. / Lien officiel de l\'école pour mettre à jour vos informations et joindre vos documents.';
?>
<meta name="description" content="<?= e($ogDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($ogTitle) ?>">
<meta property="og:description" content="<?= e($ogDesc) ?>">
<meta property="og:locale" content="ar_AR">
<meta property="og:locale:alternate" content="fr_FR">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<meta name="twitter:description" content="<?= e($ogDesc) ?>">
<style>
  body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:#eef2f7;margin:0;padding:16px;color:#1f2937}
  .wrap{max-width:760px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden}
  .hd{background:#1e3a8a;color:#fff;padding:18px 22px}
  .hd h1{margin:0;font-size:20px}.hd p{margin:4px 0 0;opacity:.9;font-size:14px}
  .bd{padding:22px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:560px){.grid{grid-template-columns:1fr}}
  label{display:block;font-size:13px;font-weight:700;margin-bottom:4px;color:#374151}
  input{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:15px}
  h3{color:#1e3a8a;border-bottom:2px solid #e5e7eb;padding-bottom:6px;margin:22px 0 12px}
  .btn{background:#1e3a8a;color:#fff;border:0;padding:13px 26px;border-radius:8px;font-size:17px;font-weight:700;cursor:pointer;width:100%;margin-top:18px}
  .note{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:13px;color:#475569;margin-bottom:14px}
  .ok{text-align:center;padding:40px 20px}.ok .ic{font-size:54px}
  .err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:14px;border-radius:8px}
</style>
</head>
<body>
<div class="wrap">
  <div class="hd">
    <h1>تحديث / تجديد معلومات الأستاذ — Mise à jour des informations de l'enseignant</h1>
    <p><?= e($schoolName) ?></p>
  </div>
  <div class="bd">
  <?php if ($formClosed): ?>
    <div class="err" style="text-align:center">
      <div style="font-size:44px;margin-bottom:8px">🔒</div>
      <strong>باب تحديث وإدخال المعلومات مقفل حالياً.</strong><br>
      انتهت مهلة الإدخال بتاريخ <strong><?= e(displayDMY($formDeadline)) ?></strong>. للضرورة، الرجاء التواصل مع إدارة المدرسة.<br><br>
      <span dir="ltr">La saisie des informations est actuellement fermée (échéance : <?= e(displayDMY($formDeadline)) ?>). En cas de nécessité, veuillez contacter l'administration de l'école.</span>
    </div>
  <?php elseif ($postTooBig): ?>
    <div class="err">📁 لم تصل المعلومات (قد يكون مجموع حجم الملفات كبيراً جداً أو الإنترنت ضعيفاً). الرجاء المحاولة مجدداً، أو رفع المستندات على دفعتين، أو إعادة المحاولة على إنترنت أقوى.<br><span dir="ltr">Les informations ne sont pas parvenues (fichiers trop volumineux ou connexion faible). Veuillez réessayer, envoyer les documents en deux fois, ou utiliser une meilleure connexion.</span></div>
  <?php elseif (!$valid): ?>
    <div class="err">الرابط غير صالح أو منتهي. اطلب رابطاً جديداً من إدارة المدرسة.<br><span dir="ltr">Lien invalide ou expiré. Demandez un nouveau lien à l'administration de l'école.</span></div>
  <?php elseif ($done): ?>
    <div class="ok"><div class="ic">✅</div>
      <h2>تمّ استلام معلوماتك، شكراً! — Vos informations ont été reçues, merci !</h2>
      <p>سيتم تحديثها في النظام من قبل الإدارة. يمكنك إغلاق هذه الصفحة.<br><span dir="ltr">Elles seront mises à jour dans le système par l'administration. Vous pouvez fermer cette page.</span></p>
      <?php if ($uploadWarn): ?>
        <div class="err" style="text-align:right;margin-top:18px">⚠️ هذه المستندات كانت كبيرة جداً (أكثر من 200 ميغا) فلم تُرفَع: <strong><?= e(implode('، ', $uploadWarn)) ?></strong>.<br>الرجاء إعادة إرسالها عبر نفس الرابط (ويمكن تصويرها صورة لتصغر تلقائياً).<br><span dir="ltr">Ces documents étaient trop volumineux (plus de 200 Mo) et n'ont pas été envoyés. Veuillez les renvoyer via le même lien (une photo est automatiquement compressée).</span></div>
      <?php endif; ?>
    </div>
  <?php elseif ($needSchoolSelect): ?>
    <div class="note">أهلاً بك أيها الأستاذ الجديد 👋 — اختر <strong>مدرستك</strong> أوّلاً للمتابعة.<br><span dir="ltr">Bienvenue, nouvel enseignant 👋 — choisissez d'abord votre <strong>école</strong> pour continuer.</span></div>
    <form method="get">
      <input type="hidden" name="all" value="1">
      <?php if ($newFlag): ?><input type="hidden" name="new" value="1"><?php endif; ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>اختر مدرستك / Choisissez votre école</label>
      <select name="school" required style="width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px;margin-bottom:6px">
        <option value="">— اختر / Choisir —</option>
        <?php foreach ($activeSchools as $sch): ?>
          <option value="<?= $sch['id'] ?>"><?= e($sch['name_ar'] ?: $sch['name_fr']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">متابعة / Continuer ➜</button>
    </form>
  <?php elseif ($needFind):
    // رابط «أستاذ جديد» بنفس التوكن والمدرسة + new=1
    $newQs = ($allMode ? 'all=1&' : '') . 'school=' . (int)$schoolId . '&token=' . rawurlencode($token) . '&new=1';
  ?>
    <?php if ($findError): ?><div class="err" style="margin-bottom:14px"><?= e($findError) ?></div><?php endif; ?>
    <div class="note">للحفاظ على الخصوصية، لا تظهر أسماء الأساتذة. إذا كنت <strong>أستاذاً حالياً</strong> اكتب اسمك وشهرتك وتاريخ ولادتك للوصول إلى <strong>ملفك أنت فقط</strong>. وإذا كنت <strong>أستاذاً جديداً</strong> اضغط الزرّ بالأسفل.<br><span dir="ltr">Pour préserver la confidentialité, les noms ne sont pas affichés. Si vous êtes un <strong>enseignant actuel</strong>, saisissez votre nom, prénom et date de naissance pour accéder à <strong>votre dossier uniquement</strong>. Si vous êtes un <strong>nouvel enseignant</strong>, cliquez sur le bouton ci-dessous.</span></div>
    <form method="get">
      <?= $allHidden ?>
      <input type="hidden" name="school" value="<?= (int)$schoolId ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>اكتب اسمك الكامل (الاسم والشهرة) / Nom complet</label>
      <input type="text" name="q" required value="<?= e($findName) ?>" placeholder="مثال / ex : جورج خليل" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px;margin-bottom:10px">
      <label>تاريخ ولادتك / Date de naissance</label>
      <input type="text" name="bd" required inputmode="numeric" autocomplete="off" class="date-manual" placeholder="يوم/شهر/سنة — مثلاً 15/08/1980" value="<?= e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $findDob) ? displayDMY($findDob) : $findDob) ?>" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px;margin-bottom:6px">
      <button class="btn" type="submit">عرض ملفي / Voir mon dossier ➜</button>
    </form>
    <div style="text-align:center;margin:16px 0 4px;color:#94a3b8">— أو / ou —</div>
    <a href="?<?= $newQs ?>" class="btn" style="background:#0a7a37;display:block;text-align:center;text-decoration:none">🆕 أنا أستاذ جديد (تعبئة ملف جديد) / Je suis un nouvel enseignant</a>
  <?php else: ?>
    <?php if ($error): ?><div class="err" style="margin-bottom:14px">⚠️ <?= e($error) ?></div><?php endif; ?>
    <?php if ($isNew): ?>
      <div class="note">أهلاً بالأستاذ الجديد 👋 — عبّئ معلوماتك التالية وارفع مستنداتك، ثم اضغط «إرسال». سيُنشئ الإدارة ملفك بعد المراجعة.<br><span dir="ltr">Bienvenue, nouvel enseignant 👋 — remplissez vos informations et joignez vos documents, puis cliquez sur « Envoyer ». L'administration créera votre dossier après vérification.</span></div>
    <?php else: ?>
      <div class="note">أهلاً <strong><?= e(trim(($emp['first_name_ar']??'').' '.($emp['last_name_ar']??'')) ?: trim(($emp['first_name_fr']??'').' '.($emp['last_name_fr']??''))) ?></strong> — عبّئ/صحّح معلوماتك التالية وارفع مستنداتك، ثم اضغط «إرسال».<br><span dir="ltr">Bonjour — complétez / corrigez vos informations et joignez vos documents, puis cliquez sur « Envoyer ».</span></div>
    <?php endif; ?>
    <div class="note" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;margin-bottom:14px">
      📌 الحقول المعلَّمة بـ<span style="color:#dc2626;font-weight:700">*</span> <strong>إلزامية</strong> — ما بتقدر تبعت قبل ما تعبّيها كلّها. أمّا <strong>غير الإلزامية</strong> (تركها فارغة ما بيمنع الإرسال) فهي: <strong>الصورة الشخصية، البريد الإلكتروني، الهاتف الثاني، رقم الضمان، رقم المالية، رقم صندوق التعويضات، وتاريخ الترك</strong>.<br>
      <span dir="ltr">📌 Les champs marqués d'un <span style="color:#dc2626;font-weight:700">*</span> sont <strong>obligatoires</strong> ; l'envoi est bloqué tant qu'ils ne sont pas remplis. Champs <strong>facultatifs</strong> : photo, e-mail, 2ᵉ téléphone, N° CNSS, N° Finances, N° Caisse et date de départ.</span>
    </div>
    <form method="post" enctype="multipart/form-data" id="tf">
      <?php if ($isNew): // أستاذ جديد: يبقى ضمن توكن المدرسة ?>
        <?= $allHidden ?><?= $newHidden ?>
        <input type="hidden" name="school" value="<?= (int)$schoolId ?>">
      <?php endif; ?>
      <input type="hidden" name="emp" value="<?= (int)$empId ?>">
      <input type="hidden" name="token" value="<?= e($formToken) ?>">
      <?php if ($isNew): ?>
      <h3>سنة الدخول / Année d'entrée</h3>
      <div class="note" style="margin-bottom:8px">إنت أستاذ جديد، فدخولك يكون على <strong>السنة الدراسية القادمة</strong> (مش السنة الجارية أو اللي قبلها).<br><span dir="ltr">Vous êtes un nouvel enseignant : votre entrée se fait sur <strong>l'année scolaire prochaine</strong> (pas l'année en cours ni la précédente).</span></div>
      <div>
        <label>سنة الدخول (السنة الدراسية) / Année d'entrée</label>
        <select name="entry_school_year" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px">
          <?php foreach ($entryYearOptions as $ey): ?>
            <option value="<?= e($ey) ?>" <?= $ey === $defaultEntryYear ? 'selected' : '' ?>><?= e($ey) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <h3 style="margin-top:16px">الراتب والإضافات (للأستاذ الجديد) / Salaire et indemnités</h3>
      <div class="note" style="margin-bottom:8px">الراتب والأجر الإضافي <strong>شهريان</strong>؛ تعويض النقل <strong>لليوم الواحد</strong> (يُضرَب تلقائياً بعدد أيام الحضور الأسبوعية × 4). كلٌّ بعملته (ليرة أو دولار). تُجهَّز تلقائياً عند إنشاء الملف، وفيك تعدّلها لاحقاً من ملف الأستاذ.<br><span dir="ltr">Le salaire et l'indemnité supplémentaire sont <strong>mensuels</strong> ; le transport est <strong>par jour</strong> (multiplié automatiquement par le nombre de jours de présence × 4). Chacun dans sa devise (LL ou USD).</span></div>
      <div class="grid">
        <?php
          $newPayFields = [
            'new_salary'    => 'الراتب الأساسي الشهري / Salaire de base',
            'new_extra'     => 'الأجر الإضافي الشهري / Indemnité supplémentaire',
            'new_transport' => 'تعويض النقل — لليوم الواحد / Transport (par jour)',
          ];
          foreach ($newPayFields as $nf => $nlbl): $curName = $nf . '_cur'; ?>
          <div>
            <label><?= e($nlbl) ?></label>
            <div style="display:flex;gap:6px">
              <input type="number" name="<?= $nf ?>" value="<?= e($_POST[$nf] ?? '') ?>" step="any" min="0" placeholder="0" style="flex:1;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px">
              <select name="<?= $curName ?>" style="width:80px;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px">
                <option value="LBP" <?= (($_POST[$curName] ?? 'LBP') === 'LBP') ? 'selected' : '' ?>>ل.ل</option>
                <option value="USD" <?= (($_POST[$curName] ?? '') === 'USD') ? 'selected' : '' ?>>$</option>
              </select>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <h3>المعلومات الشخصية / Informations personnelles</h3>
      <div class="grid">
        <?php foreach ($textFields as $k => $lbl): if (in_array($k, ['gouvernorat','district','ville','quartier','rue','immeuble','etage'])) continue;
          $req = $tfReq($k); ?>
          <div>
            <label><?= e($lbl) ?><?= $req ? $reqStar : $optTag ?></label>
            <?php if ($k === 'birth_date'): ?>
            <input type="text" inputmode="numeric" autocomplete="off" class="date-manual" placeholder="يوم/شهر/سنة — مثلاً 15/08/1980"
                   name="<?= e($k) ?>" value="<?= e(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fv($k)) ? displayDMY($fv($k)) : $fv($k)) ?>"<?= $req ? ' required data-req="1"' : '' ?>>
            <?php else: ?>
            <input type="<?= $k === 'email' ? 'email' : ($k === 'number_of_children' ? 'number' : 'text') ?>"
                   name="<?= e($k) ?>" value="<?= e($fv($k)) ?>"<?= $req ? ' required data-req="1"' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <h3>المعلومات المهنية / Informations professionnelles</h3>
      <div class="grid">
        <div>
          <label>الشهادة العلمية / Diplôme<?= $reqStar ?></label>
          <select name="diploma" required data-req="1" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:15px">
            <option value="">— اختر / Choisir —</option>
            <?php foreach ($diplomas as $d): ?>
              <option value="<?= e($d['diploma_code']) ?>" <?= ($fv('diploma') === $d['diploma_code']) ? 'selected' : '' ?>><?= e($d['diploma_name_ar']) ?> / <?= e($d['diploma_name_fr']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>المواد التي يدرّسها / Matières enseignées<?= $reqStar ?></label>
          <input type="text" name="subjects_taught" value="<?= e($fv('subjects_taught')) ?>" placeholder="رياضيات، علوم... / maths, sciences..." required data-req="1">
        </div>
      </div>
      <div style="margin-top:12px">
        <label>المرحلة — Niveau scolaire<?= $reqStar ?></label>
        <?php $niveauSel = isset($_POST['niveau_scolaire']) && is_array($_POST['niveau_scolaire']) ? $_POST['niveau_scolaire'] : explode(',', (string)($emp['niveau_scolaire'] ?? '')); ?>
        <div class="req-checkgroup" data-reqmsg="الرجاء اختيار المرحلة (خيار واحد على الأقل) / Veuillez choisir au moins un niveau" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px">
          <?php foreach ($niveauOptions as $nv => $nlbl): ?>
            <label style="font-weight:400"><input type="checkbox" name="niveau_scolaire[]" value="<?= $nv ?>" <?= in_array($nv, $niveauSel) ? 'checked' : '' ?> style="width:auto;margin-left:4px"> <?= e($nlbl) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($classLevels): $selClasses = isset($_POST['classes_taught']) && is_array($_POST['classes_taught']) ? array_map('intval', $_POST['classes_taught']) : $selClasses; ?>
      <div style="margin-top:12px">
        <label>الصفوف التي تعلّم فيها — Classes<?= $reqStar ?></label>
        <div class="req-checkgroup" data-reqmsg="الرجاء اختيار الصفوف (خيار واحد على الأقل) / Veuillez choisir au moins une classe" style="display:flex;gap:14px;flex-wrap:wrap;margin-top:4px">
          <?php foreach ($classLevels as $cl): ?>
            <?php $clFr = trim((string)($cl['name_fr'] ?? '')); ?>
            <label style="font-weight:400;white-space:nowrap"><input type="checkbox" name="classes_taught[]" value="<?= (int)$cl['id'] ?>" <?= in_array((int)$cl['id'], $selClasses, true) ? 'checked' : '' ?> style="width:auto;margin-left:4px"> <?= e($clFr !== '' ? $clFr.' / '.$cl['name'] : $cl['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="grid" style="margin-top:12px">
        <div>
          <label>عدد الساعات الأسبوعية / Heures par semaine<?= $reqStar ?></label>
          <input type="number" step="0.5" min="0" name="hours_per_week" value="<?= e($fv('hours_per_week')) ?>" required data-req="1">
        </div>
        <div>
          <label>عدد أيام الحضور أسبوعياً / Jours par semaine<?= $reqStar ?></label>
          <input type="number" min="1" max="7" name="days_per_week" value="<?= e($fv('days_per_week')) ?>" required data-req="1">
        </div>
        <div>
          <label>رقم الضمان الاجتماعي / N° CNSS<?= $optTag ?></label>
          <input type="text" name="nssf_number" value="<?= e($fv('nssf_number')) ?>">
        </div>
        <div>
          <label>رقم المالية / N° Finances<?= $optTag ?></label>
          <input type="text" name="finance_ministry_number" value="<?= e($fv('finance_ministry_number')) ?>">
        </div>
        <div>
          <label>رقم صندوق التعويضات / N° Caisse<?= $optTag ?></label>
          <input type="text" name="caisse_number" value="<?= e($fv('caisse_number')) ?>">
        </div>
      </div>
      <h3>العنوان / Adresse</h3>
      <div class="grid">
        <?php foreach (['gouvernorat','district','ville','quartier','rue','immeuble','etage'] as $k): $req = $tfReq($k); ?>
          <div>
            <label><?= e($textFields[$k]) ?><?= $req ? $reqStar : $optTag ?></label>
            <input type="text" name="<?= e($k) ?>" value="<?= e($fv($k)) ?>"<?= $req ? ' required data-req="1"' : '' ?>>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$isNew): ?>
      <h3 style="color:#b45309;border-bottom-color:#fde68a">ترك العمل (اختياري) / Départ</h3>
      <div class="note" style="background:#fffbeb;border-color:#fde68a;color:#92400e;margin-bottom:8px">
        إذا كنت <strong>ستترك العمل</strong>، اكتب <strong>تاريخ الترك</strong> بالأسفل. أمّا إذا كنت <strong>مستمراً في عملك</strong> فاترك هذا الحقل <strong>فارغاً</strong>.<br>
        <span dir="ltr">Si vous <strong>quittez votre poste</strong>, indiquez la <strong>date de départ</strong> ci-dessous. Si vous <strong>continuez</strong>, laissez ce champ <strong>vide</strong>.</span>
      </div>
      <div style="max-width:300px">
        <label>تاريخ ترك العمل / Date de départ</label>
        <input type="text" name="leave_date" inputmode="numeric" autocomplete="off" class="date-manual" placeholder="يوم/شهر/سنة — مثلاً 30/09/2026" value="<?= e(displayDMY($emp['left_date_cnss'] ?? '')) ?>">
      </div>
      <?php endif; ?>
      <h3>المستندات (صورة أو PDF) / Documents (image ou PDF)</h3>
      <div class="note" style="margin-bottom:8px">
        المستندات المعلَّمة بـ<span style="color:#dc2626;font-weight:700">*</span> <strong>إلزامية</strong> (صورة عن الشهادة، إخراج القيد الفردي أو الهوية، إخراج القيد العائلي)؛ <strong>الصورة الشخصية اختيارية</strong>.<br>
        <span dir="ltr">Les documents marqués d'un <span style="color:#dc2626;font-weight:700">*</span> sont <strong>obligatoires</strong> (copie du diplôme, extrait individuel ou carte d'identité, extrait familial) ; la <strong>photo personnelle est optionnelle</strong>.</span><br>
        <i>📎 ارفع مستنداتك بأي شكل (صورة أو PDF). 📷 والصور تُضغط تلقائياً فترفع أسرع. / Joignez vos documents (image ou PDF) ; les images sont compressées automatiquement.</i>
      </div>
      <div class="grid">
        <?php foreach ($uploadFields as $field => $lbl):
          $isReq = in_array($field, $requiredUploads, true);
          $onFile = !$isNew && !empty($emp[$uploadCol[$field]] ?? '');
          $mustUpload = $isReq && !$onFile; ?>
          <div>
            <label><?= e($lbl) ?><?php if ($isReq): ?> <span style="color:#dc2626;font-weight:700">*</span><?php endif; ?>
              <?php if ($onFile): ?><span style="color:#0a7a37;font-weight:400;font-size:12px">✓ مرفوع مسبقاً / déjà fourni</span><?php endif; ?></label>
            <input type="file" name="<?= e($field) ?>" accept="image/*,application/pdf"<?= $mustUpload ? ' data-req="1" required' : '' ?>>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="btn" type="submit">📤 إرسال المعلومات / Envoyer</button>
    </form>
  <?php endif; ?>
  </div>
</div>
<script>
// تنسيق تلقائي لحقول التاريخ اليدوية: يكتب الأستاذ أرقاماً فقط فتُدرَج الشرطات تلقائياً → يوم/شهر/سنة.
// (بدل روزنامة type=date التي تُجبر على النقر للوصول لسنة الولادة على الهاتف.)
(function(){
  function mask(el){
    function fmt(){
      var v = el.value.replace(/\D/g,'').slice(0,8), out = v;
      if(v.length > 4)      out = v.slice(0,2)+'/'+v.slice(2,4)+'/'+v.slice(4);
      else if(v.length > 2) out = v.slice(0,2)+'/'+v.slice(2);
      el.value = out;
    }
    el.addEventListener('input', fmt);
    el.addEventListener('blur', fmt);
  }
  [].forEach.call(document.querySelectorAll('input.date-manual'), mask);
})();
// الإلزامية: الأستاذ التارك (كتب تاريخ ترك) يُعفى منها؛ ومجموعات المربّعات تتطلّب اختياراً واحداً على الأقل
(function(){
  var form = document.getElementById('tf'); if(!form) return;
  var ld = document.querySelector('input[name=leave_date]');
  var reqCtrls = [].slice.call(form.querySelectorAll('[data-req]'));
  var groups   = [].slice.call(form.querySelectorAll('.req-checkgroup'));
  function leaving(){ return ld && ld.value.trim() !== ''; }
  function syncReq(){
    var lv = leaving();
    reqCtrls.forEach(function(c){ if(lv) c.removeAttribute('required'); else c.setAttribute('required','required'); });
  }
  if(ld){ ld.addEventListener('change', syncReq); ld.addEventListener('input', syncReq); }
  syncReq();
  // «اختَر واحداً على الأقل» لمجموعات المربّعات الإلزامية (المرحلة/الصفوف) — يعمل قبل ضغط الصور
  form.addEventListener('submit', function(e){
    if(leaving()) return;
    for(var i=0;i<groups.length;i++){
      if(groups[i].querySelectorAll('input[type=checkbox]:checked').length === 0){
        e.preventDefault(); e.stopImmediatePropagation();
        alert(groups[i].getAttribute('data-reqmsg') || 'الرجاء اختيار خيار واحد على الأقل / Sélectionnez au moins une option');
        try{ groups[i].scrollIntoView({block:'center'}); }catch(_){}
        return;
      }
    }
  }, true);
})();
// ضغط الصور على جهاز الأستاذ قبل الرفع (تصغير الأبعاد + JPEG) — يقلّل الحجم كثيراً عبر النفق
(function(){
  var form = document.getElementById('tf'); if(!form) return;
  var MAXW = 2400, Q = 0.85, THRESH = 1200*1024;
  function resize(file){
    return new Promise(function(res){
      if(!file || !/^image\//.test(file.type) || file.size <= THRESH){ res(file); return; }
      var img = new Image();
      img.onload = function(){
        var w = img.width, h = img.height;
        if(w > MAXW){ h = Math.round(h*MAXW/w); w = MAXW; }
        try{
          var c = document.createElement('canvas'); c.width=w; c.height=h;
          c.getContext('2d').drawImage(img,0,0,w,h);
          c.toBlob(function(b){ res(b ? new File([b], file.name.replace(/\.\w+$/,'')+'.jpg', {type:'image/jpeg'}) : file); }, 'image/jpeg', Q);
        }catch(e){ res(file); }
      };
      img.onerror = function(){ res(file); };
      img.src = URL.createObjectURL(file);
    });
  }
  form.addEventListener('submit', function(e){
    if(form.dataset.ready) return; // المرّة الثانية: أرسِل عادي
    var inputs = [].slice.call(form.querySelectorAll('input[type=file]'));
    var has = inputs.some(function(i){ return i.files.length && /^image\//.test(i.files[0].type) && i.files[0].size > THRESH; });
    if(!has) return; // لا صور كبيرة → إرسال عادي
    e.preventDefault();
    var btn = form.querySelector('button[type=submit]'); if(btn){ btn.disabled=true; btn.textContent='⏳ جاري تجهيز الصور...'; }
    Promise.all(inputs.map(function(inp){
      if(!inp.files.length) return Promise.resolve();
      return resize(inp.files[0]).then(function(f){ try{ var dt=new DataTransfer(); dt.items.add(f); inp.files=dt.files; }catch(e){} });
    })).then(function(){ form.dataset.ready='1'; form.submit(); });
  });
})();
</script>
</body>
</html>
