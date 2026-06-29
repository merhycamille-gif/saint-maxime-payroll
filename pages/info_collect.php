<?php
/**
 * جمع معلومات الأساتذة: إرسال فورم التحديث واتساب لكل أستاذ (كل مدرسة لحالها)،
 * واستيراد الطلبات الواردة لتحديث ملفات الأساتذة. (pages/teacher_form.php هو الفورم العام.)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php'; // arNameToFr لإنشاء أستاذ جديد
require_once __DIR__ . '/../includes/payroll_calculator.php'; // recalcEmployeeYear لتوليد رواتب سنة الدخول
requireLogin();

$currentPage = 'info_collect';
$pageTitle = 'تحديث معلومات الأساتذة';
$db = getDB();

// الحقول القابلة للتحديث من الفورم (نفس مفاتيح teacher_form.php)
$textFields = ['first_name_ar','first_name_fr','last_name_ar','last_name_fr','mother_first_name','mother_last_name',
    'birth_date','birth_place','nationality','number_of_children','phone1','phone2','email',
    'gouvernorat','district','ville','quartier','rue','immeuble','etage',
    // الحقول المهنية المضافة
    'subjects_taught','niveau_scolaire','classes_taught','hours_per_week','days_per_week',
    'nssf_number','finance_ministry_number','caisse_number'];
$uploadCols = ['photo_path','id_document_path','family_doc_path','diploma_doc_path'];
$intFields = ['number_of_children','days_per_week'];
$floatFields = ['hours_per_week'];

// دالة موحّدة: تطبّق طلباً واحداً على ملف الأستاذ (يُفترض التحقّق من نطاق المدرسة قبلها)
// تُرجع رسالة تحذير إن تعذّر تطبيق تغيير الشهادة لأستاذ ملاك (يُعدَّل من ملف الأستاذ ليُعاد بناء الدرجات).
function applyOneSubmission($db, $sub, $textFields, $uploadCols) {
    $data = json_decode($sub['data'] ?: '{}', true) ?: [];
    $intFields = ['number_of_children','days_per_week']; $floatFields = ['hours_per_week'];
    $warn = '';
    $set = []; $vals = [];
    foreach ($textFields as $f) {
        if (array_key_exists($f, $data) && $data[$f] !== '') {
            $set[] = "$f = ?";
            if (in_array($f, $intFields, true)) $vals[] = (int)$data[$f];
            elseif (in_array($f, $floatFields, true)) $vals[] = (float)$data[$f];
            else $vals[] = $data[$f];
        }
    }
    // الشهادة: تطبَّق مباشرةً للمتعاقد/الموظف (لا أثر على الراتب). أمّا الملاك فتغييرها يُعيد بناء الدرجات
    // (قاعدة حرجة) → لا تُطبَّق آلياً من هنا؛ يُنبَّه المدير ليعدّلها من ملف الأستاذ.
    if (array_key_exists('diploma', $data) && $data['diploma'] !== '') {
        $cur = $db->prepare("SELECT diploma, employee_type FROM employees WHERE id = ?");
        $cur->execute([$sub['employee_id']]);
        $curRow = $cur->fetch() ?: [];
        $isTitulaire = (($curRow['employee_type'] ?? '') === 'enseignant_titulaire');
        $diplomaChanged = (($curRow['diploma'] ?? '') !== $data['diploma']);
        if (!$isTitulaire || !$diplomaChanged) {
            $set[] = "diploma = ?"; $vals[] = $data['diploma'];
        } elseif ($diplomaChanged) {
            $warn = 'الأستاذ طلب تغيير الشهادة العلمية — عدّلها يدوياً من ملف الأستاذ ليُعاد بناء الدرجات حسب القانون.';
        }
    }
    foreach ($uploadCols as $c) {
        if (!empty($sub[$c])) { $set[] = "$c = ?"; $vals[] = $sub[$c]; }
    }
    if ($set) {
        $vals[] = $sub['employee_id'];
        $db->prepare("UPDATE employees SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
    }
    $db->prepare("UPDATE info_submissions SET status='applied', applied_at=NOW() WHERE id=?")->execute([$sub['id']]);
    return $warn;
}

// اعتماد طلب واحد: تحديث ملف الأستاذ بالقيم المُرسَلة + ربط السكانات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    requireCsrf();
    $sid = (int)($_POST['submission_id'] ?? 0);
    $sub = $db->prepare("SELECT * FROM info_submissions WHERE id = ? AND status = 'pending'");
    $sub->execute([$sid]);
    $sub = $sub->fetch();
    if ($sub) {
        $empChk = $db->prepare("SELECT id FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
        $empChk->execute([$sub['employee_id']]);
        if ($empChk->fetch()) {
            $w = applyOneSubmission($db, $sub, $textFields, $uploadCols);
            $_SESSION['flash_success'] = 'تم تحديث ملف الأستاذ من الطلب الوارد.' . ($w ? ' ⚠️ ' . $w : '');
        } else {
            $_SESSION['flash_error'] = 'الأستاذ ليس ضمن المدرسة المختارة.';
        }
    }
    header('Location: ' . BASE_URL . 'pages/info_collect.php#received'); exit;
}
// اعتماد كل الطلبات الواردة دفعةً واحدة (للمدرسة الحالية فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_all') {
    requireCsrf();
    $rows = $db->prepare("SELECT s.* FROM info_submissions s JOIN employees e ON e.id = s.employee_id
        WHERE s.status = 'pending' AND e.is_deleted = 0" . schoolScopeSql('e.school_id'));
    $rows->execute();
    $n = 0; $warns = 0;
    foreach ($rows->fetchAll() as $sub) { if (applyOneSubmission($db, $sub, $textFields, $uploadCols)) $warns++; $n++; }
    $_SESSION['flash_success'] = "تم اعتماد وتحديث $n ملف أستاذ دفعةً واحدة." . ($warns ? " ⚠️ $warns منهم طلبوا تغيير الشهادة العلمية — عدّلها يدوياً من ملف الأستاذ (ملاك) ليُعاد بناء الدرجات." : '');
    header('Location: ' . BASE_URL . 'pages/info_collect.php#received'); exit;
}
// إنشاء ملف أستاذ جديد من طلب وارد (is_new_teacher)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_new') {
    requireCsrf();
    $sid = (int)($_POST['submission_id'] ?? 0);
    $sub = $db->prepare("SELECT * FROM info_submissions WHERE id = ? AND status = 'pending' AND is_new_teacher = 1");
    $sub->execute([$sid]);
    $sub = $sub->fetch();
    if ($sub) {
        $schoolOk = !$sub['school_id'] ? false : true;
        // تأكّد أن مدرسة الطلب ضمن نطاق المدير (المدير العام يرى الكل)
        if ($schoolOk && !isAllSchools() && (int)$sub['school_id'] !== currentSchoolId()) $schoolOk = false;
        if (!$schoolOk) {
            $_SESSION['flash_error'] = 'الطلب ليس ضمن المدرسة المختارة.';
            header('Location: ' . BASE_URL . 'pages/info_collect.php#newteachers'); exit;
        }
        $data = json_decode($sub['data'] ?: '{}', true) ?: [];
        // سنة الدخول: يجب أن تكون لاحقة للسنة الجارية (الجديد لا يدخل على السنة الجارية أو ما قبلها)
        $entryYear = (isset($data['entry_school_year']) && preg_match('/^\d{4}-\d{4}$/', $data['entry_school_year']) && $data['entry_school_year'] > currentSchoolYear())
            ? $data['entry_school_year']
            : ((int)substr(currentSchoolYear(),0,4) + 1) . '-' . ((int)substr(currentSchoolYear(),0,4) + 2);
        $entryStartYear = (int)substr($entryYear, 0, 4);
        // ابنِ صفّ الموظف من الحقول المُرسَلة (متعاقد افتراضياً — يُكمّل المدير الإعداد المالي/التدرّج لاحقاً)
        // hire_date = 1 تشرين الأول لسنة الدخول → فيُحسب راتبه على تلك السنة لا الجارية.
        $emp = [
            'employee_type' => 'enseignant_contractuel',
            'status' => 'actif',
            'school_id' => (int)$sub['school_id'],
            'hire_date' => $entryStartYear . '-10-01',
        ];
        $strFields = ['first_name_ar','first_name_fr','last_name_ar','last_name_fr','mother_first_name','mother_last_name',
            'birth_place','nationality','gouvernorat','district','ville','quartier','rue','immeuble','etage',
            'phone1','phone2','email','diploma','subjects_taught','niveau_scolaire','classes_taught','nssf_number','finance_ministry_number','caisse_number'];
        foreach ($strFields as $f) { if (($data[$f] ?? '') !== '') $emp[$f] = $data[$f]; }
        if (($data['birth_date'] ?? '') !== '') $emp['birth_date'] = $data['birth_date'];
        if (($data['number_of_children'] ?? '') !== '') $emp['number_of_children'] = (int)$data['number_of_children'];
        if (($data['days_per_week'] ?? '') !== '') $emp['days_per_week'] = (int)$data['days_per_week'];
        if (($data['hours_per_week'] ?? '') !== '') $emp['hours_per_week'] = (float)$data['hours_per_week'];
        foreach ($uploadCols as $c) { if (!empty($sub[$c])) $emp[$c] = $sub[$c]; }
        // ترجمة تلقائية للأسماء للفرنسي إن كان فارغاً (مثل employees.php)
        if (empty($emp['first_name_fr']) && !empty($emp['first_name_ar'])) $emp['first_name_fr'] = arNameToFr($emp['first_name_ar'], 'first');
        if (empty($emp['last_name_fr']) && !empty($emp['last_name_ar']))   $emp['last_name_fr']  = arNameToFr($emp['last_name_ar'], 'last');

        $cols = array_keys($emp);
        $ph = array_map(fn($c) => ":$c", $cols);
        $db->prepare("INSERT INTO employees (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")")->execute($emp);
        $newId = (int)$db->lastInsertId();
        $db->prepare("UPDATE employees SET employee_code = ? WHERE id = ?")->execute(['EMP' . str_pad($newId, 4, '0', STR_PAD_LEFT), $newId]);
        $db->prepare("UPDATE info_submissions SET status='applied', applied_at=NOW(), employee_id=? WHERE id=?")->execute([$newId, $sub['id']]);
        // ولّد رواتب سنة الدخول (تكون صفراً حتى يُدخل المدير الراتب التعاقدي، فلا يظهر في سنة سابقة)
        try { recalcEmployeeYear($newId, $entryYear); } catch (Exception $ex) {}
        $_SESSION['flash_success'] = "تم إنشاء ملف الأستاذ الجديد لسنة الدخول $entryYear. أكمِل الإعداد المالي (الراتب التعاقدي) من ملف الأستاذ ليظهر في تلك السنة.";
        header('Location: ' . BASE_URL . 'pages/employees.php?action=edit&id=' . $newId); exit;
    }
    header('Location: ' . BASE_URL . 'pages/info_collect.php#newteachers'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    requireCsrf();
    $db->prepare("UPDATE info_submissions SET status='rejected' WHERE id=?")->execute([(int)($_POST['submission_id'] ?? 0)]);
    $_SESSION['flash_success'] = 'تم تجاهل الطلب.';
    header('Location: ' . BASE_URL . 'pages/info_collect.php#received'); exit;
}

include __DIR__ . '/../includes/header.php';

// لم نعد نُجبر اختيار مدرسة: الرابط الموحّد لكل المدارس هو الأساس، والطلبات الواردة تُعرض
// لكل المدارس في وضع «كل المدارس». أقسام المدرسة الواحدة (رابط الغروب + الإرسال الفردي)
// تظهر فقط عند اختيار مدرسة واحدة (isAllSchools() == false).

// رابط الفورم العام (يجب أن يكون المضيف متاحاً للأساتذة — خادم المدرسة/دومين)
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$formBase = $base . BASE_URL . 'pages/teacher_form.php';

// أساتذة المدرسة الحالية للإرسال الفردي — فقط عند اختيار مدرسة واحدة (لا في وضع «كل المدارس»)
$emps = [];
if (!isAllSchools()) {
    [$yfE, $ypE] = yearEmploymentFilter(activeSchoolYear(), '');
    $stEmps = $db->prepare("SELECT * FROM employees WHERE is_deleted = 0" . schoolScopeSql('school_id') . $yfE
        . " ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr)");
    $stEmps->execute($ypE);
    $emps = $stEmps->fetchAll();
}

// الطلبات الواردة (pending) للمدرسة الحالية
$subs = $db->prepare("SELECT s.*, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
    COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
    FROM info_submissions s JOIN employees e ON e.id = s.employee_id
    LEFT JOIN schools sc ON sc.id = e.school_id
    WHERE s.status = 'pending'" . schoolScopeSql('e.school_id') . " ORDER BY s.submitted_at DESC");
$subs->execute();
$subs = $subs->fetchAll();

// المهلة + نصّ الرسالة المرفقة بالرابط (يمكن تغييرهما بسهولة)
$infoDeadline = '30-6-2026';
$infoIntro = "حضرة الأساتذة المحترمين،\nيرجى تعبئة وتحديث معلوماتكم الشخصية ورفع مستنداتكم (صورة، إخراج قيد فردي وعائلي، الشهادة) عبر الرابط التالي، وذلك قبل تاريخ $infoDeadline. شاكرين تعاونكم.";

$labels = ['first_name_ar'=>'الاسم','first_name_fr'=>'Prénom','last_name_ar'=>'الشهرة','last_name_fr'=>'Nom',
    'mother_first_name'=>'اسم الأم','mother_last_name'=>'شهرة الأم','birth_date'=>'تاريخ الولادة','birth_place'=>'محل الولادة',
    'nationality'=>'الجنسية','number_of_children'=>'عدد الأولاد','phone1'=>'هاتف 1','phone2'=>'هاتف 2','email'=>'إيميل',
    'entry_school_year'=>'سنة الدخول','diploma'=>'الشهادة','subjects_taught'=>'المواد','niveau_scolaire'=>'المرحلة','classes_taught'=>'الصفوف','hours_per_week'=>'الساعات/أسبوع','days_per_week'=>'أيام الحضور',
    'nssf_number'=>'رقم الضمان','finance_ministry_number'=>'رقم المالية','caisse_number'=>'رقم الصندوق',
    'gouvernorat'=>'محافظة','district'=>'قضاء','ville'=>'بلدة','quartier'=>'حي','rue'=>'شارع','immeuble'=>'مبنى','etage'=>'طابق'];
// خريطة أسماء الشهادات (للعرض)
$diplomaNames = [];
foreach ($db->query("SELECT diploma_code, diploma_name_ar FROM diploma_starting_grades") as $dr) { $diplomaNames[$dr['diploma_code']] = $dr['diploma_name_ar']; }
// خريطة أسماء الصفوف (للعرض): id => name (محصّنة قبل migration 015)
$classNames = [];
try { foreach ($db->query("SELECT id, name FROM class_levels") as $cr) { $classNames[(int)$cr['id']] = $cr['name']; } } catch (Exception $e) {}
// حوّل قائمة معرّفات صفوف ("13,14") إلى أسماء مفصولة بفواصل
$classIdsToNames = function ($csv) use ($classNames) {
    $names = [];
    foreach (array_filter(array_map('intval', explode(',', (string)$csv))) as $cid) { if (isset($classNames[$cid])) $names[] = $classNames[$cid]; }
    return implode('، ', $names);
};

// الطلبات الواردة للأساتذة الجدد (لا employee_id بعد) — للمدرسة الحالية
$newSubs = $db->prepare("SELECT s.*, COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
    FROM info_submissions s LEFT JOIN schools sc ON sc.id = s.school_id
    WHERE s.is_new_teacher = 1 AND s.status = 'pending'"
    . schoolScopeSql('s.school_id') . " ORDER BY s.submitted_at DESC");
$newSubs->execute();
$newSubs = $newSubs->fetchAll();
?>
<div class="alert alert-info no-print" style="margin-bottom:14px">
  <i class="fas fa-info-circle"></i> أرسل رابط الفورم لكل أستاذ عبر واتساب؛ يعبّيه ويرفع سكاناته، فيظهر طلبه تحت «الطلبات الواردة» لتعتمده فيتحدّث ملفه تلقائياً.
  <strong>الأساتذة الجدد</strong> يضغطون «أنا أستاذ جديد» داخل الرابط فيعبّون ملفاً فارغاً، ويظهر تحت «طلبات أساتذة جدد» لتنشئ ملفهم بضغطة.
  <strong>ملاحظة:</strong> ليتمكّن الأساتذة من فتح الرابط، يجب أن يكون البرنامج على خادم/دومين متاح لهم (لا «localhost»).
</div>

<?php if (!isAllSchools()):
  $sid = currentSchoolId();
  $schoolNm = currentSchool()['name_ar'] ?? '';
  $groupIntro = "حضرة أساتذة " . $schoolNm . " المحترمين،\nيرجى تعبئة وتحديث معلوماتكم الشخصية ورفع مستنداتكم (صورة، إخراج قيد فردي وعائلي، الشهادة) عبر الرابط التالي، وذلك قبل تاريخ $infoDeadline. شاكرين تعاونكم.";
  $groupLink = $formBase . '?school=' . $sid . '&token=' . schoolFormToken($sid);
  $groupMsg = rawurlencode($groupIntro . "\n(كلٌّ يختار اسمه)\n" . $groupLink);
?>
<div class="card" style="border:2px solid #25d366">
  <div class="card-header" style="background:#e8f9ef"><h3 style="color:#0a7a37"><i class="fab fa-whatsapp"></i> رابط واحد لكل المجموعة (الأسهل)</h3></div>
  <div class="card-body">
    <p style="color:var(--gray-600);margin-top:0">ابعت <strong>هالرابط الواحد</strong> لغروب الأساتذة على واتساب — كل أستاذ بيفتحه، بيختار اسمه، وبيحدّث معلوماته.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="text" readonly value="<?= e($groupLink) ?>" id="grpLink" style="flex:1;min-width:260px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px">
      <button type="button" class="btn" style="background:#25d366;color:#fff" onclick="navigator.clipboard.writeText(document.getElementById('grpLink').value);this.innerHTML='✓ تم النسخ'"><i class="fas fa-copy"></i> نسخ الرابط</button>
      <a href="https://wa.me/?text=<?= $groupMsg ?>" target="_blank" class="btn" style="background:#128c7e;color:#fff"><i class="fab fa-whatsapp"></i> فتح واتساب للإرسال</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (isSuperAdmin()):
  $allLink = $formBase . '?all=1&token=' . allFormToken();
  $allMsg = rawurlencode($infoIntro . "\n(كلٌّ يكتب اسمه وتاريخ ولادته، والبرنامج بيعرف مدرسته تلقائياً)\n" . $allLink);
?>
<div class="card" style="border:2px solid #128c7e">
  <div class="card-header" style="background:#e6f4f1"><h3 style="color:#0a6b5e"><i class="fas fa-globe"></i> رابط موحّد لكل المدارس (رابط واحد للجميع) ⭐</h3></div>
  <div class="card-body">
    <p style="color:var(--gray-600);margin-top:0">ابعت <strong>هالرابط الواحد للكل</strong> — كل أستاذ بيكتب <strong>اسمه وتاريخ ولادته</strong> بس، والبرنامج بيعرف <strong>مدرسته تلقائياً</strong> ويحدّث معلوماته. (نفس الرابط لكل المدارس.)</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="text" readonly value="<?= e($allLink) ?>" id="allLink" style="flex:1;min-width:260px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px">
      <button type="button" class="btn" style="background:#128c7e;color:#fff" onclick="navigator.clipboard.writeText(document.getElementById('allLink').value);this.innerHTML='✓ تم النسخ'"><i class="fas fa-copy"></i> نسخ الرابط</button>
      <a href="https://wa.me/?text=<?= $allMsg ?>" target="_blank" class="btn" style="background:#0a6b5e;color:#fff"><i class="fab fa-whatsapp"></i> فتح واتساب للإرسال</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!isAllSchools()): $indivSchoolNm = currentSchool()['name_ar'] ?? ''; ?>
<div class="card">
  <div class="card-header"><h3><i class="fab fa-whatsapp"></i> أو إرسال فردي لكل أستاذ — <?= e($indivSchoolNm) ?> (<?= count($emps) ?>)</h3></div>
  <div class="card-body">
    <div class="table-wrapper">
    <table class="table">
      <thead><tr><th>#</th><th>الاسم</th><th>الفئة</th><th>الهاتف</th><th>إرسال / نسخ الرابط</th></tr></thead>
      <tbody>
      <?php $i=0; foreach ($emps as $emp): $i++;
        $nm = trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: trim($emp['first_name_fr'].' '.$emp['last_name_fr']);
        $link = $formBase . '?emp=' . $emp['id'] . '&token=' . infoFormToken($emp['id']);
        $wa = waPhone($emp['phone1'] ?: $emp['phone2']);
        $msg = rawurlencode("حضرة الأستاذ(ة) " . $nm . " المحترم(ة) في " . $indivSchoolNm . "،\nيرجى تعبئة وتحديث معلوماتك الشخصية ورفع مستنداتك (صورة، إخراج قيد فردي وعائلي، الشهادة) عبر الرابط التالي، وذلك قبل تاريخ " . $infoDeadline . ". شاكرين تعاونك.\n" . $link);
      ?>
        <tr>
          <td><?= $i ?></td>
          <td><strong><?= e($nm) ?></strong></td>
          <td><?= employeeTypeLabel($emp['employee_type']) ?></td>
          <td><?= e($emp['phone1'] ?: $emp['phone2'] ?: '—') ?></td>
          <td style="white-space:nowrap">
            <?php if ($wa): ?>
              <a href="https://wa.me/<?= $wa ?>?text=<?= $msg ?>" target="_blank" class="btn btn-sm" style="background:#25d366;color:#fff"><i class="fab fa-whatsapp"></i> واتساب</a>
            <?php else: ?>
              <span class="badge badge-warning">لا هاتف</span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-light" onclick="navigator.clipboard.writeText('<?= e($link) ?>');this.innerHTML='✓ نُسخ'"><i class="fas fa-copy"></i> نسخ الرابط</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card" id="newteachers" style="border:2px solid #0a7a37">
  <div class="card-header" style="background:#e8f9ef"><h3 style="color:#0a7a37"><i class="fas fa-user-plus"></i> طلبات أساتذة جدد (<?= count($newSubs) ?>)</h3></div>
  <div class="card-body">
    <?php if (!$newSubs): ?>
      <div class="empty-state"><i class="fas fa-user-plus"></i><h4>لا طلبات أساتذة جدد</h4><p>عند تعبئة أستاذ جديد للفورم (خيار «أنا أستاذ جديد») يظهر هنا لتنشئ ملفه.</p></div>
    <?php else: foreach ($newSubs as $s):
        $data = json_decode($s['data'] ?: '{}', true) ?: [];
        $nm = trim(($data['first_name_ar']??'').' '.($data['last_name_ar']??'')) ?: trim(($data['first_name_fr']??'').' '.($data['last_name_fr']??'')) ?: '(بلا اسم)';
    ?>
      <div style="border:1px solid var(--gray-200);border-radius:8px;padding:14px;margin-bottom:14px">
        <div class="d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px">
          <div><strong style="font-size:16px"><?= e($nm) ?></strong>
            <span class="badge badge-success">أستاذ جديد</span>
            <?php if (!empty($s['school_name'])): ?><span class="badge" style="background:#0a6b5e;color:#fff"><i class="fas fa-school"></i> <?= e($s['school_name']) ?></span><?php endif; ?>
            <span class="badge badge-info"><?= e($s['submitted_at']) ?></span></div>
          <div style="display:flex;gap:8px">
            <form method="post" onsubmit="return confirm('إنشاء ملف أستاذ جديد بهذه المعلومات؟ تُكمل الإعداد المالي والتدرّج من ملف الأستاذ.')" style="margin:0">
              <?= csrfField() ?><input type="hidden" name="action" value="create_new"><input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm btn-success"><i class="fas fa-user-plus"></i> إنشاء ملف الأستاذ</button>
            </form>
            <form method="post" onsubmit="return confirm('تجاهل هذا الطلب؟')" style="margin:0">
              <?= csrfField() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm btn-light"><i class="fas fa-times"></i> تجاهل</button>
            </form>
          </div>
        </div>
        <div class="form-row cols-4" style="margin-top:10px">
          <?php foreach ($labels as $k => $lbl): if (($data[$k] ?? '') === '') continue;
            $val = ($k === 'diploma') ? ($diplomaNames[$data[$k]] ?? $data[$k]) : (($k === 'classes_taught') ? $classIdsToNames($data[$k]) : $data[$k]); ?>
            <div style="font-size:13px"><span style="color:var(--gray-500)"><?= e($lbl) ?>:</span> <strong><?= e($val) ?></strong></div>
          <?php endforeach; ?>
        </div>
        <?php
          $files = ['photo_path'=>'صورة','id_document_path'=>'إخراج فردي','family_doc_path'=>'إخراج عائلي','diploma_doc_path'=>'الشهادة'];
          $any = false; foreach ($files as $c=>$l) if (!empty($s[$c])) $any = true;
          if ($any): ?>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($files as $c => $l): if (empty($s[$c])) continue; ?>
              <a href="<?= BASE_URL . e($s[$c]) ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-paperclip"></i> <?= e($l) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="card" id="received">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h3><i class="fas fa-inbox"></i> الطلبات الواردة (<?= count($subs) ?>)</h3>
    <?php if ($subs): ?>
    <form method="post" onsubmit="return confirm('اعتماد وتحديث ملفات كل الـ<?= count($subs) ?> أستاذ دفعةً واحدة؟')" style="margin:0">
      <?= csrfField() ?><input type="hidden" name="action" value="apply_all">
      <button class="btn btn-success"><i class="fas fa-check-double"></i> اعتماد الكل وتحديث الملفات (<?= count($subs) ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$subs): ?>
      <div class="empty-state"><i class="fas fa-inbox"></i><h4>لا طلبات واردة بعد</h4><p>ستظهر هنا بعد أن يعبّئ الأساتذة الفورم.</p></div>
    <?php else: foreach ($subs as $s):
        $nm = trim($s['first_name_ar'].' '.$s['last_name_ar']) ?: trim($s['first_name_fr'].' '.$s['last_name_fr']);
        $data = json_decode($s['data'] ?: '{}', true) ?: [];
    ?>
      <div style="border:1px solid var(--gray-200);border-radius:8px;padding:14px;margin-bottom:14px">
        <div class="d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px">
          <div><strong style="font-size:16px"><?= e($nm) ?></strong>
            <?php if (!empty($s['school_name'])): ?><span class="badge" style="background:#0a6b5e;color:#fff"><i class="fas fa-school"></i> <?= e($s['school_name']) ?></span><?php endif; ?>
            <span class="badge badge-info"><?= e($s['submitted_at']) ?></span></div>
          <div style="display:flex;gap:8px">
            <form method="post" onsubmit="return confirm('تحديث ملف الأستاذ بهذه المعلومات؟')" style="margin:0">
              <?= csrfField() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm btn-success"><i class="fas fa-check"></i> اعتماد وتحديث الملف</button>
            </form>
            <form method="post" onsubmit="return confirm('تجاهل هذا الطلب؟')" style="margin:0">
              <?= csrfField() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm btn-light"><i class="fas fa-times"></i> تجاهل</button>
            </form>
          </div>
        </div>
        <div class="form-row cols-4" style="margin-top:10px">
          <?php foreach ($labels as $k => $lbl): if (($data[$k] ?? '') === '') continue;
            $val = ($k === 'diploma') ? ($diplomaNames[$data[$k]] ?? $data[$k]) : (($k === 'classes_taught') ? $classIdsToNames($data[$k]) : $data[$k]); ?>
            <div style="font-size:13px"><span style="color:var(--gray-500)"><?= e($lbl) ?>:</span> <strong><?= e($val) ?></strong></div>
          <?php endforeach; ?>
        </div>
        <?php
          $files = ['photo_path'=>'صورة','id_document_path'=>'إخراج فردي','family_doc_path'=>'إخراج عائلي','diploma_doc_path'=>'الشهادة'];
          $any = false; foreach ($files as $c=>$l) if (!empty($s[$c])) $any = true;
          if ($any): ?>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($files as $c => $l): if (empty($s[$c])) continue; ?>
              <a href="<?= BASE_URL . e($s[$c]) ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-paperclip"></i> <?= e($l) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
