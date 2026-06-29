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
    // (أ) المسار الأساسي: الاسم + تاريخ الولادة بالضبط
    $ts = strtotime($bd);
    if ($ts) {
        $bdNorm = date('Y-m-d', $ts);
        $st = $db->prepare("SELECT * FROM employees WHERE school_id = ? AND is_deleted = 0 AND birth_date = ?");
        $st->execute([$schoolId, $bdNorm]);
        $strong = [];
        foreach ($st->fetchAll() as $c) { if (tf_nameMatches($c, $nq, $nqNS)) $strong[] = $c; }
        if (count($strong) === 1) return $strong[0];
        if (count($strong) > 1) return null; // غموض (تطابق متعدد) — لا تخمّن
    }
    // (ب) المسار الاحتياطي: تاريخ ولادة ناقص/وهمي عند الإدارة → بالاسم وحده، ضمن أساتذة هالسنة فقط
    [$yf, $yp] = yearEmploymentFilter(currentSchoolYear(), '');
    $st2 = $db->prepare("SELECT * FROM employees WHERE school_id = ? AND is_deleted = 0
        AND (birth_date IS NULL OR birth_date = '0000-00-00' OR birth_date = '1900-01-01')" . $yf);
    $st2->execute(array_merge([$schoolId], $yp));
    $strong2 = [];
    foreach ($st2->fetchAll() as $c) { if (tf_nameMatches($c, $nq, $nqNS)) $strong2[] = $c; }
    return (count($strong2) === 1) ? $strong2[0] : null;
}

$db = getDB();
$empId = (int)($_GET['emp'] ?? $_POST['emp'] ?? 0);
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$schoolId = (int)($_GET['school'] ?? $_POST['school'] ?? 0);

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
if ($allMode && $schoolId <= 0) {
    // الوضع الموحّد بلا مدرسة → اعرض قائمة المدارس ليختار الأستاذ مدرسته
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
        if ($findName !== '' && $findDob !== '') {
            $emp = findTeacherByNameDob($db, $schoolId, $findName, $findDob);
            if ($emp) { $empId = (int)$emp['id']; $verified = true; }
            else { $needFind = true; $findError = 'ما قدرنا نلاقي ملفك بهالمعلومات. تأكّد من كتابة اسمك وشهرتك وتاريخ ولادتك متل ما هنّي مسجّلين عند الإدارة، أو تواصل مع إدارة المدرسة.'; }
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

// سنوات الدخول للأستاذ الجديد: السنة الدراسية **القادمة فأكثر** فقط (لا يجوز للجديد الدخول على
// السنة الجارية أو ما قبلها). الافتراضي = السنة القادمة (مثلاً 2026-2027 المفتوحة).
$curStart = (int)substr(currentSchoolYear(), 0, 4);   // 2025 إذا الحالية 2025-2026
$entryYearOptions = [];
for ($yy = $curStart + 1; $yy <= $curStart + 3; $yy++) { $entryYearOptions[] = $yy . '-' . ($yy + 1); }
$defaultEntryYear = $entryYearOptions[0];

// الحقول النصية القابلة للتحديث
$textFields = [
    'first_name_ar' => 'الاسم (عربي)', 'first_name_fr' => 'Prénom (FR)',
    'last_name_ar' => 'الشهرة (عربي)', 'last_name_fr' => 'Nom (FR)',
    'mother_first_name' => 'اسم الأم', 'mother_last_name' => 'شهرة الأم',
    'birth_date' => 'تاريخ الولادة', 'birth_place' => 'محل الولادة',
    'nationality' => 'الجنسية', 'number_of_children' => 'عدد الأولاد',
    'phone1' => 'هاتف 1', 'phone2' => 'هاتف 2', 'email' => 'البريد الإلكتروني',
    'gouvernorat' => 'المحافظة', 'district' => 'القضاء', 'ville' => 'البلدة',
    'quartier' => 'الحي', 'rue' => 'الشارع', 'immeuble' => 'المبنى', 'etage' => 'الطابق',
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
    'photo' => 'صورة شخصية / Photo',
    'id_document' => 'إخراج قيد فردي / Extrait individuel',
    'family_doc' => 'إخراج قيد عائلي / Extrait familial',
    'diploma_doc' => 'الشهادة / Diplôme',
];
$uploadCol = ['photo' => 'photo_path', 'id_document' => 'id_document_path', 'family_doc' => 'family_doc_path', 'diploma_doc' => 'diploma_doc_path'];

$done = false; $error = ''; $uploadWarn = [];
// كشف تجاوز حجم الرفع الكلّي (post_max_size): عندها PHP يُفرّغ POST و FILES مع بقاء Content-Length
$postTooBig = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);
if ($valid && ($isNew || $emp) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // اجمع الحقول النصية
    $data = [];
    foreach ($textFields as $k => $_) { $data[$k] = trim((string)($_POST[$k] ?? '')); }
    // الحقول المهنية + الشهادة + المرحلة
    $data['diploma'] = trim((string)($_POST['diploma'] ?? ''));
    foreach ($profFields as $k => $_) { $data[$k] = trim((string)($_POST[$k] ?? '')); }
    $data['niveau_scolaire'] = is_array($_POST['niveau_scolaire'] ?? null)
        ? implode(',', array_intersect($_POST['niveau_scolaire'], array_keys($niveauOptions))) : '';
    // سنة الدخول (للأستاذ الجديد فقط) — يجب أن تكون ضمن الخيارات المسموحة (القادمة فأكثر)
    if ($isNew) {
        $ey = trim((string)($_POST['entry_school_year'] ?? ''));
        $data['entry_school_year'] = in_array($ey, $entryYearOptions, true) ? $ey : $defaultEntryYear;
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
<title>تحديث معلومات الأستاذ</title>
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
    <h1>تحديث / تجديد معلومات الأستاذ</h1>
    <p><?= e($schoolName) ?> — Mise à jour des informations</p>
  </div>
  <div class="bd">
  <?php if ($postTooBig): ?>
    <div class="err">📁 لم تصل المعلومات (قد يكون مجموع حجم الملفات كبيراً جداً أو الإنترنت ضعيفاً). الرجاء المحاولة مجدداً، أو رفع المستندات على دفعتين، أو إعادة المحاولة على إنترنت أقوى.</div>
  <?php elseif (!$valid): ?>
    <div class="err">الرابط غير صالح أو منتهي. اطلب رابطاً جديداً من إدارة المدرسة.</div>
  <?php elseif ($done): ?>
    <div class="ok"><div class="ic">✅</div>
      <h2>تمّ استلام معلوماتك، شكراً!</h2>
      <p>سيتم تحديثها في النظام من قبل الإدارة. يمكنك إغلاق هذه الصفحة.</p>
      <?php if ($uploadWarn): ?>
        <div class="err" style="text-align:right;margin-top:18px">⚠️ هذه المستندات كانت كبيرة جداً (أكثر من 200 ميغا) فلم تُرفَع: <strong><?= e(implode('، ', $uploadWarn)) ?></strong>.<br>الرجاء إعادة إرسالها عبر نفس الرابط (ويمكن تصويرها صورة لتصغر تلقائياً).</div>
      <?php endif; ?>
    </div>
  <?php elseif ($needSchoolSelect): ?>
    <div class="note">أهلاً بك. اختر <strong>مدرستك</strong> أوّلاً للمتابعة.</div>
    <form method="get">
      <input type="hidden" name="all" value="1">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>اختر مدرستك / Choisissez votre école</label>
      <select name="school" required style="width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px;margin-bottom:6px">
        <option value="">— اختر —</option>
        <?php foreach ($activeSchools as $sch): ?>
          <option value="<?= $sch['id'] ?>"><?= e($sch['name_ar'] ?: $sch['name_fr']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">متابعة ➜</button>
    </form>
  <?php elseif ($needFind):
    // رابط «أستاذ جديد» بنفس التوكن والمدرسة + new=1
    $newQs = ($allMode ? 'all=1&' : '') . 'school=' . (int)$schoolId . '&token=' . rawurlencode($token) . '&new=1';
  ?>
    <?php if ($findError): ?><div class="err" style="margin-bottom:14px"><?= e($findError) ?></div><?php endif; ?>
    <div class="note">للحفاظ على الخصوصية، لا تظهر أسماء الأساتذة. إذا كنت <strong>أستاذاً حالياً</strong> اكتب اسمك وشهرتك وتاريخ ولادتك للوصول إلى <strong>ملفك أنت فقط</strong>. وإذا كنت <strong>أستاذاً جديداً</strong> اضغط الزرّ بالأسفل.</div>
    <form method="get">
      <?= $allHidden ?>
      <input type="hidden" name="school" value="<?= (int)$schoolId ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>اكتب اسمك الكامل (الاسم والشهرة) / Nom complet</label>
      <input type="text" name="q" required value="<?= e($findName) ?>" placeholder="مثال: جورج خليل" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px;margin-bottom:10px">
      <label>تاريخ ولادتك / Date de naissance</label>
      <input type="date" name="bd" required value="<?= e($findDob) ?>" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px;margin-bottom:6px">
      <button class="btn" type="submit">عرض ملفي ➜</button>
    </form>
    <div style="text-align:center;margin:16px 0 4px;color:#94a3b8">— أو —</div>
    <a href="?<?= $newQs ?>" class="btn" style="background:#0a7a37;display:block;text-align:center;text-decoration:none">🆕 أنا أستاذ جديد (تعبئة ملف جديد)</a>
  <?php else: ?>
    <?php if ($isNew): ?>
      <div class="note">أهلاً بالأستاذ الجديد 👋 — عبّئ معلوماتك التالية وارفع صورك إن أمكن، ثم اضغط «إرسال». سيُنشئ الإدارة ملفك بعد المراجعة.</div>
    <?php else: ?>
      <div class="note">أهلاً <strong><?= e(trim(($emp['first_name_ar']??'').' '.($emp['last_name_ar']??'')) ?: trim(($emp['first_name_fr']??'').' '.($emp['last_name_fr']??''))) ?></strong> — عبّئ/صحّح معلوماتك التالية وارفع صورك إن أمكن، ثم اضغط «إرسال».</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="tf">
      <?php if ($isNew): // أستاذ جديد: يبقى ضمن توكن المدرسة ?>
        <?= $allHidden ?><?= $newHidden ?>
        <input type="hidden" name="school" value="<?= (int)$schoolId ?>">
      <?php endif; ?>
      <input type="hidden" name="emp" value="<?= (int)$empId ?>">
      <input type="hidden" name="token" value="<?= e($formToken) ?>">
      <?php if ($isNew): ?>
      <h3>سنة الدخول</h3>
      <div class="note" style="margin-bottom:8px">إنت أستاذ جديد، فدخولك يكون على <strong>السنة الدراسية القادمة</strong> (مش السنة الجارية أو اللي قبلها).</div>
      <div>
        <label>سنة الدخول (السنة الدراسية) / Année d'entrée</label>
        <select name="entry_school_year" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:16px">
          <?php foreach ($entryYearOptions as $ey): ?>
            <option value="<?= e($ey) ?>" <?= $ey === $defaultEntryYear ? 'selected' : '' ?>><?= e($ey) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <h3>المعلومات الشخصية</h3>
      <div class="grid">
        <?php foreach ($textFields as $k => $lbl): if (in_array($k, ['gouvernorat','district','ville','quartier','rue','immeuble','etage'])) continue; ?>
          <div>
            <label><?= e($lbl) ?></label>
            <input type="<?= $k === 'birth_date' ? 'date' : ($k === 'email' ? 'email' : ($k === 'number_of_children' ? 'number' : 'text')) ?>"
                   name="<?= e($k) ?>" value="<?= e($emp[$k] ?? '') ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <h3>المعلومات المهنية</h3>
      <div class="grid">
        <div>
          <label>الشهادة العلمية / Diplôme</label>
          <select name="diploma" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:15px">
            <option value="">— اختر —</option>
            <?php foreach ($diplomas as $d): ?>
              <option value="<?= e($d['diploma_code']) ?>" <?= (($emp['diploma'] ?? '') === $d['diploma_code']) ? 'selected' : '' ?>><?= e($d['diploma_name_ar']) ?> / <?= e($d['diploma_name_fr']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>المواد التي يدرّسها / Matières enseignées</label>
          <input type="text" name="subjects_taught" value="<?= e($emp['subjects_taught'] ?? '') ?>" placeholder="رياضيات، علوم...">
        </div>
      </div>
      <div style="margin-top:12px">
        <label>المرحلة / الصفوف التي يعلّم فيها — Niveau scolaire</label>
        <?php $niveauSel = explode(',', (string)($emp['niveau_scolaire'] ?? '')); ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px">
          <?php foreach ($niveauOptions as $nv => $nlbl): ?>
            <label style="font-weight:400"><input type="checkbox" name="niveau_scolaire[]" value="<?= $nv ?>" <?= in_array($nv, $niveauSel) ? 'checked' : '' ?> style="width:auto;margin-left:4px"> <?= e($nlbl) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="grid" style="margin-top:12px">
        <div>
          <label>عدد الساعات الأسبوعية / Heures par semaine</label>
          <input type="number" step="0.5" min="0" name="hours_per_week" value="<?= e($emp['hours_per_week'] ?? '') ?>">
        </div>
        <div>
          <label>عدد أيام الحضور أسبوعياً / Jours par semaine</label>
          <input type="number" min="1" max="7" name="days_per_week" value="<?= e($emp['days_per_week'] ?? '') ?>">
        </div>
        <div>
          <label>رقم الضمان الاجتماعي / N° CNSS</label>
          <input type="text" name="nssf_number" value="<?= e($emp['nssf_number'] ?? '') ?>">
        </div>
        <div>
          <label>رقم المالية / N° Finances</label>
          <input type="text" name="finance_ministry_number" value="<?= e($emp['finance_ministry_number'] ?? '') ?>">
        </div>
        <div>
          <label>رقم صندوق التعويضات / N° Caisse</label>
          <input type="text" name="caisse_number" value="<?= e($emp['caisse_number'] ?? '') ?>">
        </div>
      </div>
      <h3>العنوان</h3>
      <div class="grid">
        <?php foreach (['gouvernorat','district','ville','quartier','rue','immeuble','etage'] as $k): ?>
          <div>
            <label><?= e($textFields[$k]) ?></label>
            <input type="text" name="<?= e($k) ?>" value="<?= e($emp[$k] ?? '') ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <h3>السكانات (اختياري — صورة أو PDF)</h3>
      <div class="note" style="margin-bottom:8px"><i>📎 ارفع مستنداتك بأي شكل عندك (صورة أو PDF، كبيرة أو صغيرة) — كلها مقبولة حتى لو حجمها كبير. 📷 والصور تُضغط تلقائياً قبل الرفع (وتبقى واضحة) فترفع أسرع وتوفّر باقة الإنترنت.</i></div>
      <div class="grid">
        <?php foreach ($uploadFields as $field => $lbl): ?>
          <div>
            <label><?= e($lbl) ?></label>
            <input type="file" name="<?= e($field) ?>" accept="image/*,application/pdf">
          </div>
        <?php endforeach; ?>
      </div>
      <button class="btn" type="submit">📤 إرسال المعلومات</button>
    </form>
  <?php endif; ?>
  </div>
</div>
<script>
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
