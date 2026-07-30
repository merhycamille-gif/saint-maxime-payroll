<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php';
requireLogin();

// AJAX: ترجمة اسم عربي → فرنسي للتعبئة الفورية في النموذج (GET، بلا CSRF)
if (($_GET['action'] ?? '') === 'translit') {
    header('Content-Type: application/json; charset=utf-8');
    $t = (($_GET['type'] ?? 'last') === 'first') ? 'first' : 'last';
    echo json_encode(['fr' => arNameToFr($_GET['text'] ?? '', $t)], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCsrf();

/**
 * رفع ملفات الأستاذ: صورة، إخراج قيد/تذكرة، إخراج قيد عائلي.
 * تُحفظ في uploads/employees ويُخزَّن المسار. تُبقي القديم إن لم يُرفع جديد.
 */
/**
 * قسم «المستندات والصور» (الصورة + إخراج قيد فردي + إخراج قيد عائلي + الشهادة) —
 * يُعرض في التبويب الشخصي (personnel). الرفع فوري عبر AJAX لحظة الاختيار.
 */
function renderEmployeeScans($employee, $id) {
    $renderFile = function ($path, $field) {
        if (empty($path)) return '<div class="doc-saved" style="margin-top:6px;color:#999;font-size:12px"><i class="fas fa-circle-xmark"></i> لا يوجد ملف بعد</div>';
        $url = BASE_URL . e($path);
        $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $path);
        $out = '<div class="doc-saved" style="margin-top:8px;display:flex;flex-direction:column;gap:6px">';
        $out .= '<span style="color:#15803d;font-weight:700;font-size:12px"><i class="fas fa-circle-check"></i> ملف محفوظ / Fichier enregistré</span>';
        if ($isImg) {
            $out .= '<a href="' . $url . '" target="_blank" title="Ouvrir / فتح كامل"><img src="' . $url . '" style="max-height:90px;border-radius:6px;border:1px solid #ccc;cursor:pointer"></a>';
        }
        $out .= '<div style="display:flex;gap:6px;flex-wrap:wrap">'
             . '<a href="' . $url . '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-eye"></i> فتح / Voir</a>'
             . '<button type="button" onclick="ppPrintFile(\'' . $url . '\',' . ($isImg ? 'true' : 'false') . ')" class="btn btn-sm btn-light"><i class="fas fa-print"></i> Imprimer / طباعة</button>'
             . '<button type="button" onclick="ppDeleteDoc(\'' . e($field) . '\', this)" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> حذف / Supprimer</button>'
             . '</div></div>';
        return $out;
    };
    ob_start();
    ?>
                <h4 style="color:var(--primary);margin-top:20px;">
                    <span dir="ltr">Documents & Scans</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">المستندات والصور</div>
                </h4>
                <p style="color:var(--gray-500);font-size:13px;">صور أو PDF. إذا في ملف محفوظ بيظهر رابطه؛ اترك الحقل فاضي حتى ما يتغيّر.</p>
                <?php if ($id <= 0): ?>
                    <div class="alert alert-warning" style="font-size:13px"><i class="fas fa-info-circle"></i> احفظ الموظف أولاً (زر «حفظ» بالأسفل)، وبعدها بترجع وبترفع الصور والوثائق وبتنزل فوراً.</div>
                <?php else: ?>
                <p style="color:var(--gray-500);font-size:12.5px"><i class="fas fa-bolt"></i> الملف <strong>بينزل فوراً</strong> لحظة ما تختاره (ما بدّك تضغط حفظ).</p>
                <div class="form-row cols-4">
                    <div class="form-group">
                        <label class="form-label">Photo / صورة شخصية</label>
                        <input type="file" name="photo" data-doc="photo" class="form-control" accept="image/*">
                        <div class="upload-status"></div>
                        <?= $renderFile($employee['photo_path'] ?? '', 'photo') ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Extrait d'état civil / إخراج قيد (تذكرة)</label>
                        <input type="file" name="id_document" data-doc="id_document" class="form-control" accept="image/*,application/pdf">
                        <div class="upload-status"></div>
                        <?= $renderFile($employee['id_document_path'] ?? '', 'id_document') ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Extrait d'état civil familial / إخراج قيد عائلي</label>
                        <input type="file" name="family_doc" data-doc="family_doc" class="form-control" accept="image/*,application/pdf">
                        <div class="upload-status"></div>
                        <?= $renderFile($employee['family_doc_path'] ?? '', 'family_doc') ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Diplôme / الشهادة</label>
                        <input type="file" name="diploma_doc" data-doc="diploma_doc" class="form-control" accept="image/*,application/pdf">
                        <div class="upload-status"></div>
                        <?= $renderFile($employee['diploma_doc_path'] ?? '', 'diploma_doc') ?>
                    </div>
                </div>
                <script>
                (function(){
                    var EMP_ID = <?= (int)$id ?>, CSRF = '<?= csrfToken() ?>';
                    var UP = '<?= BASE_URL ?>pages/employees.php?action=upload_file';
                    document.querySelectorAll('input[type=file][data-doc]').forEach(function(inp){
                        inp.addEventListener('change', function(){
                            if (!inp.files.length) return;
                            var box = inp.parentNode.querySelector('.upload-status');
                            var fd = new FormData();
                            fd.append('csrf', CSRF); fd.append('employee_id', EMP_ID);
                            fd.append('field', inp.getAttribute('data-doc')); fd.append('file', inp.files[0]);
                            box.innerHTML = '<span style="color:#1e40af">⏳ جاري الرفع...</span>';
                            fetch(UP, {method:'POST', body:fd}).then(function(r){return r.json();}).then(function(d){
                                if (d.ok) {
                                    box.innerHTML = '<span style="color:#15803d;font-weight:700"><i class="fas fa-circle-check"></i> تم رفع الملف بنجاح</span> '
                                        + (d.isImg ? '<a href="'+d.url+'" target="_blank"><img src="'+d.url+'" style="max-height:55px;border:1px solid #ccc;border-radius:4px;vertical-align:middle"></a> ' : '')
                                        + '<a href="'+d.url+'" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-eye"></i> فتح</a> '
                                        + '<button type="button" onclick="ppPrintFile(\''+d.url+'\','+(d.isImg?'true':'false')+')" class="btn btn-sm btn-light"><i class="fas fa-print"></i> طباعة</button> '
                                        + '<button type="button" onclick="ppDeleteDoc(\''+inp.getAttribute('data-doc')+'\', this)" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> حذف</button>';
                                } else {
                                    box.innerHTML = '<span style="color:#b91c1c"><i class="fas fa-triangle-exclamation"></i> '+(d.msg||'فشل الرفع')+'</span>';
                                }
                            }).catch(function(){ box.innerHTML = '<span style="color:#b91c1c">⚠ خطأ بالاتصال</span>'; });
                        });
                    });
                })();
                // حذف مستند (عام، يُستدعى من أزرار «حذف») — يمسح الملف من السيرفر ويفرّغ الحقل فوراً
                window.ppDeleteDoc = function(field, btn){
                    if(!confirm('متأكّد بدّك تحذف هالمستند نهائياً؟ / Supprimer définitivement ce document ?')) return;
                    var old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '⏳';
                    var fd = new FormData();
                    fd.append('csrf','<?= csrfToken() ?>'); fd.append('employee_id','<?= (int)$id ?>'); fd.append('field', field);
                    fetch('<?= BASE_URL ?>pages/employees.php?action=delete_file', {method:'POST', body:fd})
                        .then(function(r){return r.json();}).then(function(d){
                            if(d.ok){
                                var grp = btn.closest('.form-group');
                                if(grp){
                                    var st = grp.querySelector('.upload-status'); if(st) st.innerHTML = '';
                                    var saved = grp.querySelector('.doc-saved');
                                    if(saved) saved.outerHTML = '<div class="doc-saved" style="margin-top:6px;color:#999;font-size:12px"><i class="fas fa-circle-xmark"></i> لا يوجد ملف بعد</div>';
                                    var fi = grp.querySelector('input[type=file]'); if(fi) fi.value = '';
                                } else { btn.parentNode.innerHTML = '<span style="color:#999">تم الحذف</span>'; }
                            } else { alert(d.msg || 'فشل الحذف'); btn.disabled = false; btn.innerHTML = old; }
                        }).catch(function(){ alert('خطأ بالاتصال'); btn.disabled = false; btn.innerHTML = old; });
                };
                </script>
                <?php endif; ?>
    <?php
    return ob_get_clean();
}

function handleEmployeeUploads($db, $id) {
    $map = ['photo' => 'photo_path', 'id_document' => 'id_document_path', 'family_doc' => 'family_doc_path', 'diploma_doc' => 'diploma_doc_path'];
    $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
    $dir = __DIR__ . '/../uploads/employees';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $count = 0; $errors = 0;
    foreach ($map as $field => $col) {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? 4) === 4) continue; // 4 = لم يُرفع شيء
        $f = $_FILES[$field];
        if (($f['error'] ?? 0) !== 0 || $f['size'] <= 0) { $errors++; continue; } // خطأ بالرفع (مثلاً أكبر من حد السيرفر)
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) { $errors++; continue; }
        $name = 'emp' . (int)$id . '_' . $field . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            $db->prepare("UPDATE employees SET $col = ? WHERE id = ?")->execute(['uploads/employees/' . $name, $id]);
            $count++;
        } else { $errors++; }
    }
    // رسالة تأكيد واضحة
    $prev = $_SESSION['flash']['msg'] ?? '';
    if ($count > 0) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => trim($prev) . " — ✓ تم رفع $count ملف بنجاح"];
    } elseif ($errors > 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => "⚠ فشل رفع الملف (تأكّد من النوع: صورة أو PDF). " . trim($prev)];
    }
}

$currentPage = 'employees';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

$db = getDB();
$message = '';
$messageType = 'success';

// تركيب ذاتي لعمود وظيفة الموظف الإداري (job_title) إن كان ناقصاً — يُغني عن أي خطوة يدوية أونلاين (migration 018).
try {
    if (!$db->query("SHOW COLUMNS FROM employees LIKE 'job_title'")->fetch()) {
        $db->exec("ALTER TABLE employees ADD COLUMN job_title VARCHAR(80) NULL");
    }
} catch (Exception $e) { /* صلاحيات → الإزالة عند الحفظ تمنع الكسر */ }

// رفع ملف فوري (AJAX) — لحظة اختيار الملف بلا ما يحفظ كل النموذج
if ($action === 'upload_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $eid = (int)($_POST['employee_id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $cols = ['photo'=>'photo_path','id_document'=>'id_document_path','family_doc'=>'family_doc_path','diploma_doc'=>'diploma_doc_path'];
    if (!$eid || !isset($cols[$field])) { echo json_encode(['ok'=>false,'msg'=>'طلب غير صحيح']); exit; }
    $chk = $db->prepare("SELECT id FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $chk->execute([$eid]);
    if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false,'msg'=>'الموظف غير موجود بهذه المدرسة']); exit; }
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 4) !== 0 || $_FILES['file']['size'] <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'لم يصل الملف (قد يكون كبيراً جداً)']); exit;
    }
    $f = $_FILES['file']; $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'], true)) { echo json_encode(['ok'=>false,'msg'=>'النوع غير مسموح (صورة أو PDF)']); exit; }
    $dir = __DIR__ . '/../uploads/employees'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $name = 'emp' . $eid . '_' . $field . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        $path = 'uploads/employees/' . $name;
        $db->prepare("UPDATE employees SET {$cols[$field]} = ? WHERE id = ?")->execute([$path, $eid]);
        echo json_encode(['ok'=>true,'url'=>BASE_URL.$path,'isImg'=>(bool)preg_match('/\.(jpg|jpeg|png|gif|webp)$/i',$path),'msg'=>'تم الرفع']);
    } else { echo json_encode(['ok'=>false,'msg'=>'فشل حفظ الملف']); }
    exit;
}

// حذف مستند (AJAX) — يفرّغ عمود المسار ويحذف الملف الفعلي من القرص
if ($action === 'delete_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $eid = (int)($_POST['employee_id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $cols = ['photo'=>'photo_path','id_document'=>'id_document_path','family_doc'=>'family_doc_path','diploma_doc'=>'diploma_doc_path'];
    if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) { echo json_encode(['ok'=>false,'msg'=>'جلسة غير صالحة، أعد تحميل الصفحة']); exit; }
    if (!$eid || !isset($cols[$field])) { echo json_encode(['ok'=>false,'msg'=>'طلب غير صحيح']); exit; }
    $col = $cols[$field];
    // القراءة مقيّدة بالمدرسة الحالية (يمنع حذف مستند موظف من مدرسة أخرى)
    $chk = $db->prepare("SELECT `$col` FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $chk->execute([$eid]);
    $cur = $chk->fetch(PDO::FETCH_COLUMN);
    if ($cur === false) { echo json_encode(['ok'=>false,'msg'=>'الموظف غير موجود بهذه المدرسة']); exit; }
    // احذف الملف الفعلي فقط إن كان ملفاً خاصاً بالموظف (uploads/employees/) — ملفات الطلبات
    // (uploads/submissions/) قد يشير إليها سجلّ الطلب، فنكتفي بتفريغ العمود دون مسّ الملف.
    if (!empty($cur) && strpos($cur, 'uploads/employees/') === 0 && strpos($cur, '..') === false) {
        $abs = __DIR__ . '/../' . $cur;
        if (is_file($abs)) @unlink($abs);
    }
    $db->prepare("UPDATE employees SET `$col` = NULL WHERE id = ?")->execute([$eid]);
    logAudit('delete_doc', 'employees', $eid, ['field' => $col, 'path' => $cur], null);
    echo json_encode(['ok'=>true,'msg'=>'تم الحذف']);
    exit;
}

// الإضافة/التعديل/الحذف تتطلّب مدرسة محددة (ليس وضع "كل المدارس").
// 🔵 تعديل/حذف موظف معيّن من وضع «كل المدارس»: نبدّل الجلسة لمدرسته تلقائياً ونكمل
// (بدل تحويل المستخدم للائحة المدارس ليختار بنفسه).
if (in_array($action, ['edit', 'delete'], true)) {
    autoSwitchToEmployeeSchool((int)($_GET['id'] ?? $_POST['id'] ?? 0));
}
if (in_array($action, ['new', 'edit', 'delete'])) {
    requireSchoolSelected();
}

// ===== محرّر الأجر الإضافي/المكافآت المباشر (inline) — سطر لكل مرحلة، بالليرة أو الدولار =====
function renderBonusRow($r = null) {
    // ثلاثة أنواع مستقلة: «مكافأة ومساعدة» (aide_complementaire) · «الأجر الإضافي» (prime_fixe) · «نقل».
    $types = ['aide_complementaire' => '💰 Prime & aide / مكافأة ومساعدة', 'prime_fixe' => '➕ Supplément / الأجر الإضافي', 'transport_complement' => '🚌 Transport / نقل'];
    $t = $r['bonus_type'] ?? 'aide_complementaire';
    $cur = $r['currency'] ?? 'LBP';
    $vt = $r['value_type'] ?? 'amount';
    $val = $r ? rtrim(rtrim(number_format((float)$r['amount'], 2, '.', ''), '0'), '.') : '';
    $from = $r ? (int)$r['start_month'] : 0;
    $to = $r ? (int)$r['end_month'] : 0;
    ob_start(); ?>
    <tr>
        <td><select name="bonus_rows[type][]" class="form-select"><?php foreach ($types as $k => $lbl): ?><option value="<?= $k ?>" <?= $t === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select></td>
        <td><select name="bonus_rows[currency][]" class="form-select"><option value="LBP" <?= $cur === 'LBP' ? 'selected' : '' ?>>ل.ل</option><option value="USD" <?= $cur === 'USD' ? 'selected' : '' ?>>$</option></select></td>
        <td><select name="bonus_rows[vtype][]" class="form-select"><option value="amount" <?= $vt === 'amount' ? 'selected' : '' ?>>Montant / مبلغ</option><option value="percent" <?= $vt === 'percent' ? 'selected' : '' ?>>Taux % / نسبة</option></select></td>
        <td><input type="number" step="0.01" min="0" name="bonus_rows[value][]" value="<?= $val ?>" class="form-control" style="min-width:90px" placeholder="0"></td>
        <td><select name="bonus_rows[from][]" class="form-select"><option value="">—</option><?php for ($mm = 1; $mm <= 12; $mm++): ?><option value="<?= $mm ?>" <?= $from === $mm ? 'selected' : '' ?>><?= monthName($mm) ?></option><?php endfor; ?></select></td>
        <td><select name="bonus_rows[to][]" class="form-select"><option value="">—</option><?php for ($mm = 1; $mm <= 12; $mm++): ?><option value="<?= $mm ?>" <?= $to === $mm ? 'selected' : '' ?>><?= monthName($mm) ?></option><?php endfor; ?></select></td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
    </tr>
    <?php return ob_get_clean();
}

function saveEmployeeBonuses($db, $employeeId) {
    if (empty($_POST['bonus_editor'])) return; // المحرّر المباشر لم يكن ظاهراً
    $sy = writeSchoolYear(); // المكافآت مربوطة بالسنة المختارة («كل السنين» → السنة الحالية، لا 'all')
    $db->prepare("DELETE FROM employee_bonuses WHERE employee_id = ? AND school_year = ?")->execute([$employeeId, $sy]);
    $rows = $_POST['bonus_rows'] ?? [];
    $types = $rows['type'] ?? [];
    $allowed = ['prime_fixe', 'aide_complementaire', 'transport_complement'];
    $counters = [];
    $n = is_array($types) ? count($types) : 0;
    for ($i = 0; $i < $n; $i++) {
        $bt = $types[$i] ?? '';
        if (!in_array($bt, $allowed, true)) continue;
        $val = (float)str_replace(',', '', $rows['value'][$i] ?? '');
        if ($val <= 0) continue;
        $vt = (($rows['vtype'][$i] ?? 'amount') === 'percent') ? 'percent' : 'amount';
        $cur = (($rows['currency'][$i] ?? 'USD') === 'LBP') ? 'LBP' : 'USD';
        // 🛡️ حارس خطأ العملة: مبلغ ضخم بالدولار = مبلغ ليرة كُتب والعملة بقيت دولاراً
        $cur = sanitizeAmountCurrency($val, $cur, $curWarn);
        if ($curWarn) $_SESSION['flash_info'] = $curWarn;
        $from = (isset($rows['from'][$i]) && $rows['from'][$i] !== '') ? (int)$rows['from'][$i] : null;
        $to = (isset($rows['to'][$i]) && $rows['to'][$i] !== '') ? (int)$rows['to'][$i] : null;
        $counters[$bt] = ($counters[$bt] ?? 0) + 1;
        $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$employeeId, $bt, $counters[$bt], $sy, $val, $vt, $cur, $from, $to]);
    }

    // النقل اليومي المؤرّخ بالفترات (من شهر إلى شهر) → employee_bonuses نوع transport_daily.
    // كل سطر: قيمة يومية + عملة + من شهر→إلى شهر (الشهري = اليومي × أيام الحضور × الأسابيع، يحسبه المحرّك).
    $tlines = is_array($_POST['tlines'] ?? null) ? $_POST['tlines'] : [];
    $tpn = 0;
    foreach ($tlines as $ln) {
        $val = (float)str_replace(',', '', $ln['value'] ?? '');
        if ($val <= 0) continue;
        $cur = (($ln['currency'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP';
        $from = (isset($ln['from']) && $ln['from'] !== '') ? max(1, min(12, (int)$ln['from'])) : null;
        $to   = (isset($ln['to'])   && $ln['to']   !== '') ? max(1, min(12, (int)$ln['to']))   : null;
        $tpn++;
        $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'transport_daily', ?, ?, ?, 'amount', ?, ?, ?, 1)")
           ->execute([$employeeId, $tpn, $sy, $val, $cur, $from, $to]);
    }
    // عند استعمال الفترات: صفّر العمود الثابت لتفادي ازدواج حساب النقل
    if ($tpn > 0) {
        $db->prepare("UPDATE employees SET transport_daily_amount = 0 WHERE id = ?")->execute([$employeeId]);
    }
}

// =====================================================
// Handle Save
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['new', 'edit'])) {
    $data = [
        'employee_type' => $_POST['employee_type'] ?? 'enseignant_titulaire',
        'first_name_ar' => trim($_POST['first_name_ar'] ?? ''),
        'first_name_fr' => trim($_POST['first_name_fr'] ?? ''),
        'father_name_ar' => trim($_POST['father_name_ar'] ?? ''),
        'father_name_fr' => trim($_POST['father_name_fr'] ?? ''),
        'last_name_ar' => trim($_POST['last_name_ar'] ?? ''),
        'last_name_fr' => trim($_POST['last_name_fr'] ?? ''),
        'mother_first_name' => trim($_POST['mother_first_name'] ?? ''),
        'mother_last_name' => trim($_POST['mother_last_name'] ?? ''),
        'nationality' => trim($_POST['nationality'] ?? 'lebanese'),
        'birth_date' => $_POST['birth_date'] ?: null,
        'birth_place' => trim($_POST['birth_place'] ?? ''),
        'civil_registry_number' => trim($_POST['civil_registry_number'] ?? ''),
        'civil_registry_place' => trim($_POST['civil_registry_place'] ?? ''),
        'social_status' => $_POST['social_status'] ?? 'celibataire',
        'spouse_works' => isset($_POST['spouse_works']) ? 1 : 0,
        'number_of_children' => (int)($_POST['number_of_children'] ?? 0),
        'gouvernorat' => trim($_POST['gouvernorat'] ?? ''),
        'district' => trim($_POST['district'] ?? ''),
        'ville' => trim($_POST['ville'] ?? ''),
        'quartier' => trim($_POST['quartier'] ?? ''),
        'rue' => trim($_POST['rue'] ?? ''),
        'immeuble' => trim($_POST['immeuble'] ?? ''),
        'etage' => trim($_POST['etage'] ?? ''),
        'phone1' => trim($_POST['phone1'] ?? ''),
        'phone2' => trim($_POST['phone2'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'diploma' => trim($_POST['diploma'] ?? ''),
        'specialization' => trim($_POST['specialization'] ?? ''),
        'subjects_taught' => trim($_POST['subjects_taught'] ?? ''),
        'niveau_scolaire' => is_array($_POST['niveau_scolaire'] ?? null) ? implode(',', $_POST['niveau_scolaire']) : '',
        'classes_taught' => is_array($_POST['classes_taught'] ?? null) ? implode(',', array_map('intval', $_POST['classes_taught'])) : '',
        // وظيفة الموظف الإداري: قيمة القائمة، أو النص الحر عند اختيار «أخرى». تُحفظ فقط للموظف الإداري، وتُفرَّغ للأستاذ.
        'job_title' => (($_POST['employee_type'] ?? '') === 'employe')
            ? (($_POST['job_title'] ?? '') === '__other__' ? trim($_POST['job_title_other'] ?? '') : trim($_POST['job_title'] ?? ''))
            : null,
        'hire_date' => $_POST['hire_date'] ?: null,
        // دخول الملاك = دخول المدرسة + سنتين تلقائياً إن تُرك فاضياً (القاعدة)؛ يمكن إدخاله يدوياً للتجاوز.
        'titularization_date' => ($_POST['titularization_date'] ?: (!empty($_POST['hire_date']) ? date('Y-m-d', strtotime($_POST['hire_date'] . ' +2 years')) : null)),
        'tenure_confirmation_date' => $_POST['tenure_confirmation_date'] ?: null,
        'starting_grade' => (float)($_POST['starting_grade'] ?? 1),
        'current_grade' => (float)($_POST['current_grade'] ?? 1),
        'days_per_week' => (int)($_POST['days_per_week'] ?? 5),
        'hours_per_week' => (float)($_POST['hours_per_week'] ?? 18),
        'status' => $_POST['status'] ?? 'actif',
        'nssf_number' => trim($_POST['nssf_number'] ?? ''),
        'finance_ministry_number' => trim($_POST['finance_ministry_number'] ?? ''),
        'caisse_number' => trim($_POST['caisse_number'] ?? ''),
        'left_date_cnss' => $_POST['left_date_cnss'] ?: null,
        'left_date_finance' => $_POST['left_date_finance'] ?: null,
        'left_date_eoc' => $_POST['left_date_eoc'] ?: null,
        'salary_input_mode' => $_POST['salary_input_mode'] ?? 'percent_of_lbp',
        'base_salary_usd' => (float)str_replace(',', '', $_POST['base_salary_usd'] ?? 0),
        'base_salary_lbp_percent' => (float)($_POST['base_salary_lbp_percent'] ?? 0),
        'contract_salary_lbp' => (int)str_replace(',', '', $_POST['contract_salary_lbp'] ?? 0),
        'payment_months_per_year' => (int)($_POST['payment_months_per_year'] ?? 10),
        'has_13th_month' => isset($_POST['has_13th_month']) ? 1 : 0,
        'm13_include_extra' => isset($_POST['m13_include_extra']) ? 1 : 0,
        'm13_include_aide' => isset($_POST['m13_include_aide']) ? 1 : 0,
        'keep_working_past_64' => isset($_POST['keep_working_past_64']) ? 1 : 0,
        'tax_subject' => isset($_POST['tax_subject']) ? 1 : 0,
        'tax_includes_echelon' => isset($_POST['tax_includes_echelon']) ? 1 : 0,
        'tax_includes_extra' => isset($_POST['tax_includes_extra']) ? 1 : 0,
        'tax_includes_prime_aide' => isset($_POST['tax_includes_prime_aide']) ? 1 : 0,
        'cnss_subject' => isset($_POST['cnss_subject']) ? 1 : 0,
        'cnss_includes_echelon' => isset($_POST['cnss_includes_echelon']) ? 1 : 0,
        'cnss_includes_extra' => isset($_POST['cnss_includes_extra']) ? 1 : 0,
        'cnss_includes_prime_aide' => isset($_POST['cnss_includes_prime_aide']) ? 1 : 0,
        'eoc_subject' => isset($_POST['eoc_subject']) ? 1 : 0,
        'eoc_includes_echelon' => isset($_POST['eoc_includes_echelon']) ? 1 : 0,
        'eoc_includes_extra' => isset($_POST['eoc_includes_extra']) ? 1 : 0,
        'eoc_includes_prime_aide' => isset($_POST['eoc_includes_prime_aide']) ? 1 : 0,
        'family_allowance_spouse_lbp' => (int)str_replace(',', '', $_POST['family_allowance_spouse_lbp'] ?? 0),
        'family_allowance_children_lbp' => (int)str_replace(',', '', $_POST['family_allowance_children_lbp'] ?? 0),
        // تعويض النقل اليومي: الشهري = اليومي × أيام الأسبوع × عدد الأسابيع
        'transport_daily_amount' => (float)str_replace(',', '', $_POST['transport_daily_amount'] ?? 0),
        'transport_daily_currency' => (($_POST['transport_daily_currency'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP',
        'transport_days_per_week' => (int)($_POST['transport_days_per_week'] ?? 0),
        'transport_weeks' => (float)($_POST['transport_weeks'] ?? 4),
        'notes' => trim($_POST['notes'] ?? ''),
        'school_id' => currentSchoolId(), // ربط الموظف بالمدرسة الحالية
    ];

    // ترجمة تلقائية للأسماء إلى الفرنسي إذا كان حقل الفرنسي فارغاً (المستخدم يصحّح يدوياً عند اللزوم)
    if ($data['first_name_fr'] === '' && $data['first_name_ar'] !== '') $data['first_name_fr'] = arNameToFr($data['first_name_ar'], 'first');
    if ($data['last_name_fr'] === '' && $data['last_name_ar'] !== '')   $data['last_name_fr']  = arNameToFr($data['last_name_ar'], 'last');
    if ($data['father_name_fr'] === '' && $data['father_name_ar'] !== '') $data['father_name_fr'] = arNameToFr($data['father_name_ar'], 'first');

    // الشهادة القديمة (لكشف تغييرها عند التعديل وإعادة ضبط التدرّج)
    $oldDiploma = null; $oldDates = null;
    if ($action === 'edit' && $id) {
        $stOld = $db->prepare("SELECT diploma, hire_date, titularization_date, tenure_confirmation_date FROM employees WHERE id = ? AND school_id = ?");
        $stOld->execute([$id, currentSchoolId()]);
        $oldRow = $stOld->fetch(PDO::FETCH_ASSOC) ?: [];
        $oldDiploma = $oldRow['diploma'] ?? null;
        $oldDates = $oldRow;
    }

    // عمود classes_taught قد لا يكون موجوداً في قاعدة لم تُطبَّق عليها migration 015 بعد (مثلاً الأونلاين قبل التحديث)
    // → أزِله من الحفظ لتفادي كسر حفظ الموظف. (محلياً موجود فيُحفَظ عادي.)
    try {
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'classes_taught'")->fetch()) unset($data['classes_taught']);
    } catch (Exception $e) { unset($data['classes_taught']); }
    // عمود keep_working_past_64 قد لا يكون موجوداً قبل migration 017 أونلاين → أزِله من الحفظ لتفادي الكسر
    try {
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'keep_working_past_64'")->fetch()) unset($data['keep_working_past_64']);
    } catch (Exception $e) { unset($data['keep_working_past_64']); }
    // عمود job_title قد لا يكون موجوداً قبل migration 018 أونلاين → أزِله من الحفظ لتفادي الكسر
    try {
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'job_title'")->fetch()) unset($data['job_title']);
    } catch (Exception $e) { unset($data['job_title']); }

    try {
        if ($action === 'new') {
            $cols = array_keys($data);
            $placeholders = array_map(fn($c) => ":$c", $cols);
            $sql = "INSERT INTO employees (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute($data);
            $id = $db->lastInsertId();
            
            // Auto-generate employee_code
            $code = 'EMP' . str_pad($id, 4, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE employees SET employee_code = ? WHERE id = ?")->execute([$code, $id]);
            
            // أستاذ ملاك + تاريخ دخول الملاك → ابنِ سجلّ الدرجات كاملاً حسب القانون تلقائياً
            // (دخول + درجة فورية إلا التعليمية + تدرّج عادي + استثنائية 244/102/223/2017 أو 4+4+2، وقانون 344 يدوي).
            if ($data['employee_type'] === 'enseignant_titulaire' && $data['titularization_date']) {
                // درجة الدخول = أرضية الشهادة (القانون)، إلا إذا أدخل المستخدم أعلى (خبرة سابقة)
                $dipG = $db->prepare("SELECT starting_grade FROM diploma_starting_grades WHERE diploma_code = ?");
                $dipG->execute([$data['diploma']]);
                $dipStart = $dipG->fetchColumn();
                $startG = ($dipStart !== false) ? max((float)$data['starting_grade'], (float)$dipStart) : (float)$data['starting_grade'];
                $db->prepare("UPDATE employees SET starting_grade = ? WHERE id = ?")->execute([$startG, $id]);
                try { buildLegalGradeHistory($id); } catch (Exception $ex) { /* بيانات ناقصة → تُترك للإدخال اليدوي */ }
            }
            
            logAudit('create', 'employees', $id, null, $data);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Employé créé avec succès / تم إنشاء الموظف بنجاح'];
            saveEmployeeBonuses($db, $id); // حفظ الأجر الإضافي/المكافآت من المحرّر المباشر
            recalcEmployeeYear($id); // إعادة حساب راتب السنة الحالية تلقائياً حسب القانون والمعطيات
            // بلوغ الـ64: للمُبقَى بعد 64 أعِد حساب كل سنواته المخزّنة حتى يُطبَّق وقف محسومات
            // التقاعد على الأشهر من بلوغه 64 في السنوات السابقة أيضاً (لا السنة الحالية فقط).
            if (!empty($data['keep_working_past_64'])) {
                foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                    if ($sy) recalcEmployeeYear($id, $sy);
                }
            }
            pruneSalariesAfterDeparture($db, $id); // 🩹 احذف أي راتب بعد تاريخ الترك (شفاء ذاتي)
            handleEmployeeUploads($db, $id); // رفع الصورة والوثائق (يحدّث الرسالة بعدد الملفات)
            $tabQ = ($t = preg_replace('/[^a-z]/', '', $_POST['active_tab'] ?? '')) ? '&tab=' . $t : '';
            header('Location: ' . BASE_URL . 'pages/employees.php?action=edit&id=' . $id . $tabQ);
            exit;
        } else {
            $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($data)));
            $data['id'] = $id;
            // اسم براميتر مستقل للشرط (PDO native لا يقبل تكرار :school_id مرّتين)
            $data['where_school_id'] = currentSchoolId();
            // school_id في WHERE يمنع تعديل موظف من مدرسة أخرى
            $db->prepare("UPDATE employees SET $set WHERE id = :id AND school_id = :where_school_id")->execute($data);
            logAudit('update', 'employees', $id, null, $data);

            // أستاذ ملاك: إذا تغيّرت الشهادة أو تاريخ دخول الملاك/الترسيم → أعِد بناء الدرجات حسب القانون تلقائياً
            $diplomaChanged = ($oldDiploma !== null && $oldDiploma !== $data['diploma']);
            $datesChanged = ($oldDates && (
                ($oldDates['titularization_date'] ?? null) != $data['titularization_date'] ||
                ($oldDates['hire_date'] ?? null) != $data['hire_date'] ||
                ($oldDates['tenure_confirmation_date'] ?? null) != $data['tenure_confirmation_date']
            ));
            if (($diplomaChanged || $datesChanged) && $data['employee_type'] === 'enseignant_titulaire' && $data['titularization_date']) {
                // إن تغيّرت الشهادة، اضبط درجة الدخول من الشهادة الجديدة (أرضية)
                if ($diplomaChanged) {
                    $dipG = $db->prepare("SELECT starting_grade FROM diploma_starting_grades WHERE diploma_code = ?");
                    $dipG->execute([$data['diploma']]);
                    $dipStart = $dipG->fetchColumn();
                    if ($dipStart !== false) $db->prepare("UPDATE employees SET starting_grade = ? WHERE id = ?")->execute([(float)$dipStart, $id]);
                }
                // المحرّك يبني: دخول + فورية + تدرّج + استثنائية تلقائية (244/102/223/2017 أو 4+4+2). قانون 344 يدوي محفوظ.
                try { buildLegalGradeHistory($id); } catch (Exception $ex) {}
                foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                    recalcEmployeeYear($id, $sy);
                }
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'تم تحديث الشهادة/تاريخ دخول الملاك: أُعيد بناء الدرجات والراتب حسب القانون تلقائياً (الاستثنائية آلية عدا 344 اليدوي). راجِع صفحة الدرجات للتصليح الفردي إذا لزم. / Recalculé selon la loi.'];
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Modifications enregistrées / تم حفظ التعديلات'];
            }
            saveEmployeeBonuses($db, $id); // حفظ الأجر الإضافي/المكافآت من المحرّر المباشر
            recalcEmployeeYear($id); // إعادة حساب راتب السنة الحالية تلقائياً حسب القانون والمعطيات
            // بلوغ الـ64: للمُبقَى بعد 64 أعِد حساب كل سنواته المخزّنة حتى يُطبَّق وقف محسومات
            // التقاعد على الأشهر من بلوغه 64 في السنوات السابقة أيضاً (لا السنة الحالية فقط).
            if (!empty($data['keep_working_past_64'])) {
                foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                    if ($sy) recalcEmployeeYear($id, $sy);
                }
            }
            pruneSalariesAfterDeparture($db, $id); // 🩹 احذف أي راتب بعد تاريخ الترك (شفاء ذاتي)
            handleEmployeeUploads($db, $id); // رفع/تحديث الصورة والوثائق
            $tabQ = ($t = preg_replace('/[^a-z]/', '', $_POST['active_tab'] ?? '')) ? '&tab=' . $t : '';
            header('Location: ' . BASE_URL . 'pages/employees.php?action=edit&id=' . $id . $tabQ);
            exit;
        }
    } catch (Exception $e) {
        $message = 'Erreur: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// =====================================================
// Handle Delete
// =====================================================
if ($action === 'delete' && $id > 0) {
    requireWriteAction();
    $db->prepare("UPDATE employees SET is_deleted = 1 WHERE id = ? AND school_id = ?")
       ->execute([$id, currentSchoolId()]);
    logAudit('delete', 'employees', $id);
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Employé supprimé / تم حذف الموظف'];
    header('Location: ' . BASE_URL . 'pages/employees.php');
    exit;
}

// =====================================================
// Read flash
// =====================================================
if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// =====================================================
// View routing
// =====================================================
if ($action === 'list') {
    $pageTitle = 'Employés & Enseignants / الموظفون والأساتذة';
    include __DIR__ . '/../includes/header.php';
    
    $typeFilter = $_GET['type'] ?? '';
    $statusFilter = $_GET['status'] ?? 'actif';
    $search = $_GET['q'] ?? '';
    
    $sql = "SELECT * FROM employees WHERE is_deleted = 0" . schoolScopeSql();
    $params = [];
    if ($typeFilter) { $sql .= " AND employee_type = ?"; $params[] = $typeFilter; }
    if ($statusFilter) { $sql .= " AND status = ?"; $params[] = $statusFilter; }
    if ($search) {
        $sql .= " AND (first_name_fr LIKE ? OR last_name_fr LIKE ? OR first_name_ar LIKE ? OR last_name_ar LIKE ? OR employee_code LIKE ?)";
        $like = "%$search%";
        array_push($params, $like, $like, $like, $like, $like);
    }
    // فلترة حسب السنة الدراسية المختارة من الأعلى:
    //  «كل السنين» = الكل؛ سنة محددة = مَن إلهم رواتب فعلية بتلك السنة، **بالإضافة إلى الأساتذة الجدد
    //  بلا أي راتب فعلي بعد** (المُنشَؤون حديثاً) ليظهروا باللائحة فيتمكّن المستخدم من إيجادهم وإعداد رواتبهم.
    $activeSY = activeSchoolYear();
    if ($activeSY !== 'all') {
        // يظهر في سنة محدّدة: (أ) مَن له راتب فعلي بتلك السنة، أو (ب) أستاذ **معيَّن حديثاً**
        // (سنة التعيين ضمن السنة المختارة أو بعدها) بلا راتب فعلي بعد — ليُعِدّ المستخدم راتبه.
        // شرط سنة التعيين يمنع ظهور **الأساتذة القدامى** الذين تركوا من زمان ورواتبهم صفر
        // (بلا تاريخ ترك مسجَّل) كأنهم «جدد» في كل سنة. القديم بلا راتب لا يظهر إلا في «كل السنين».
        $syStart = substr($activeSY, 0, 4) . '-01-01';
        $sql .= " AND (
                id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = ? AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0))
                OR (id NOT IN (SELECT employee_id FROM monthly_salaries WHERE base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0) AND hire_date >= ?)
            )
            AND left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL";
        $params[] = $activeSY;
        $params[] = $syStart;
    }
    $sql .= " ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
    ?>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <?php $syLbl = (activeSchoolYear() === 'all') ? (($_SESSION['lang'] ?? 'fr') === 'ar' ? 'كل السنين' : 'Toutes années') : activeSchoolYear(); ?>
            <h3>
                <span dir="ltr"><i class="fas fa-users"></i> Liste des employés (<?= count($employees) ?>) — <?= e(currentSchoolName()) ?> — <span style="color:var(--primary)"><i class="fas fa-calendar-alt"></i> <?= e($syLbl) ?></span></span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">لائحة الموظفين والأساتذة</div>
            </h3>
            <div class="d-flex gap-2 no-print">
                <button type="button" onclick="window.print()" class="btn btn-light"><i class="fas fa-print"></i> Imprimer / طباعة</button>
                <?php if (!isAllSchools()): ?>
                <a href="?action=new" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau / جديد</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row cols-4 mb-4">
                <div class="form-group mb-0">
                    <input type="text" name="q" class="form-control" placeholder="🔍 Rechercher..." value="<?= e($search) ?>">
                </div>
                <div class="form-group mb-0">
                    <select name="type" class="form-select">
                        <option value="">Tous les types / كل الأنواع</option>
                        <option value="enseignant_titulaire" <?= $typeFilter === 'enseignant_titulaire' ? 'selected' : '' ?>>Enseignant titulaire / أستاذ في الملاك</option>
                        <option value="enseignant_contractuel" <?= $typeFilter === 'enseignant_contractuel' ? 'selected' : '' ?>>Enseignant contractuel / أستاذ متعاقد</option>
                        <option value="employe" <?= $typeFilter === 'employe' ? 'selected' : '' ?>>Employé administratif / موظف إداري</option>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <select name="status" class="form-select">
                        <option value="">Tous statuts / كل الحالات</option>
                        <option value="actif" <?= $statusFilter === 'actif' ? 'selected' : '' ?>>Actif / نشط</option>
                        <option value="suspendu" <?= $statusFilter === 'suspendu' ? 'selected' : '' ?>>Suspendu / متوقف</option>
                        <option value="retraite" <?= $statusFilter === 'retraite' ? 'selected' : '' ?>>Retraité / متقاعد</option>
                        <option value="demissionne" <?= $statusFilter === 'demissionne' ? 'selected' : '' ?>>Démissionné / مستقيل</option>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <button type="submit" class="btn btn-light w-100"><i class="fas fa-filter"></i> Filtrer / تصفية</button>
                </div>
            </form>
            
            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h4>
                        <span dir="ltr">Aucun employé trouvé</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">لا يوجد موظفون</div>
                    </h4>
                    <p>Commencez par ajouter votre premier employé / ابدأ بإضافة أول موظف</p>
                    <a href="?action=new" class="btn btn-primary mt-3">
                        <i class="fas fa-plus"></i> Nouveau / جديد
                    </a>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Code / الرمز</th>
                                <?php if (isAllSchools()): ?><th>École / المدرسة</th><?php endif; ?>
                                <th>Nom complet / الاسم الكامل</th>
                                <th>Type / النوع</th>
                                <th>Échelon / الدرجة</th>
                                <th>Date d'embauche / تاريخ الدخول</th>
                                <th>Téléphone / الهاتف</th>
                                <th>Statut / الحالة</th>
                                <th class="no-print">Actions / إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): 
                                $statusInfo = employeeStatusLabel($emp['status']);
                            ?>
                                <tr>
                                    <td><strong><?= e($emp['employee_code']) ?></strong></td>
                                    <?php if (isAllSchools()): ?><td><small><?= e(schoolNameById($emp['school_id'])) ?></small></td><?php endif; ?>
                                    <td>
                                        <strong><?= e(trim($emp['first_name_fr'] . ' ' . $emp['last_name_fr'])) ?></strong>
                                        <?php if ($emp['first_name_ar']): ?>
                                            <br><small style="color:var(--gray-500)"><?= e($emp['first_name_ar'] . ' ' . $emp['last_name_ar']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $emp['employee_type'] === 'enseignant_titulaire' ? 'gold' : ($emp['employee_type'] === 'enseignant_contractuel' ? 'info' : 'secondary') ?>">
                                            <?= e(employeeTypeLabel($emp['employee_type'])) ?>
                                        </span>
                                        <?php if ($emp['employee_type'] === 'employe' && trim((string)($emp['job_title'] ?? '')) !== ''): ?>
                                            <div style="font-size:11.5px;color:var(--gray-600);margin-top:3px"><?= e(jobTitleLabel($emp['job_title'], 'ar')) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= e(gradeDisplay($emp)) ?></strong></td>
                                    <td><?= formatDate($emp['hire_date']) ?></td>
                                    <td><?= e($emp['phone1']) ?></td>
                                    <td><span class="badge badge-<?= $statusInfo['badge'] ?>"><?= e($statusInfo['label']) ?></span></td>
                                    <td class="no-print">
                                        <div style="display:flex;gap:5px;flex-wrap:nowrap;align-items:center">
                                        <a href="?action=edit&id=<?= $emp['id'] ?>" class="btn btn-sm btn-light" title="Modifier / تعديل">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="<?= BASE_URL ?>pages/monthly_payroll.php?employee_id=<?= $emp['id'] ?>" class="btn btn-sm btn-light" title="Paie / الراتب">
                                            <i class="fas fa-money-check"></i>
                                        </a>
                                        <a href="?action=delete&id=<?= $emp['id'] ?>" class="btn btn-sm btn-danger" data-confirm="<?= e('⚠️ تأكيد الحذف — هل تريد فعلاً حذف الموظف: «' . (trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: trim($emp['first_name_fr'].' '.$emp['last_name_fr'])) . '» ؟') ?>" title="Supprimer / حذف">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// =====================================================
// New / Edit Form
// =====================================================
$employee = [
    'id' => 0, 'employee_code' => '', 'employee_type' => 'enseignant_titulaire',
    'first_name_ar' => '', 'first_name_fr' => '', 'father_name_ar' => '', 'father_name_fr' => '',
    'last_name_ar' => '', 'last_name_fr' => '', 'mother_first_name' => '', 'mother_last_name' => '',
    'nationality' => 'lebanese', 'birth_date' => '', 'birth_place' => '',
    'civil_registry_number' => '', 'civil_registry_place' => '',
    'social_status' => 'celibataire', 'spouse_works' => 0, 'number_of_children' => 0,
    'gouvernorat' => '', 'district' => '', 'ville' => '', 'quartier' => '', 'rue' => '',
    'immeuble' => '', 'etage' => '', 'phone1' => '', 'phone2' => '', 'email' => '',
    'diploma' => 'ijaza_jamiya', 'specialization' => '', 'subjects_taught' => '', 'niveau_scolaire' => '', 'classes_taught' => '', 'job_title' => '',
    'hire_date' => '', 'titularization_date' => '', 'tenure_confirmation_date' => '', 'starting_grade' => 1, 'current_grade' => 1,
    'days_per_week' => 5, 'hours_per_week' => 18, 'status' => 'actif',
    'nssf_number' => '', 'finance_ministry_number' => '', 'caisse_number' => '',
    'salary_input_mode' => 'percent_of_lbp', 'base_salary_usd' => 0, 'base_salary_lbp_percent' => 100, 'contract_salary_lbp' => 0,
    'payment_months_per_year' => 10, 'has_13th_month' => 0, 'm13_include_extra' => 0, 'm13_include_aide' => 0,
    'tax_subject' => 1, 'tax_includes_echelon' => 1, 'tax_includes_extra' => 1, 'tax_includes_prime_aide' => 1,
    'cnss_subject' => 1, 'cnss_includes_echelon' => 1, 'cnss_includes_extra' => 1, 'cnss_includes_prime_aide' => 1,
    'eoc_subject' => 1, 'eoc_includes_echelon' => 1, 'eoc_includes_extra' => 0, 'eoc_includes_prime_aide' => 0, 'keep_working_past_64' => 0,
    'family_allowance_spouse_lbp' => 0, 'family_allowance_children_lbp' => 0,
    'transport_daily_amount' => 0, 'transport_daily_currency' => 'LBP', 'transport_days_per_week' => 0, 'transport_weeks' => 4,
    'notes' => ''
];

if ($action === 'edit' && $id > 0) {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND school_id = ?");
    $stmt->execute([$id, currentSchoolId()]);
    $found = $stmt->fetch();
    if ($found) {
        $employee = $found;
    } else {
        // موظف غير موجود في هذه المدرسة
        $_SESSION['flash_error'] = 'Employé introuvable dans cette école / الموظف غير موجود في هذه المدرسة';
        header('Location: ' . BASE_URL . 'pages/employees.php');
        exit;
    }
}

$pageTitle = $action === 'new' ? 'Nouvel employé / موظف جديد' : 'Modifier / تعديل: ' . trim($employee['first_name_fr'] . ' ' . $employee['last_name_fr']);

// Get diplomas
$diplomas = $db->query("SELECT * FROM diploma_starting_grades")->fetchAll();
$exceptionalLaws = $db->query("SELECT * FROM exceptional_grades_laws WHERE is_active = 1 ORDER BY law_date")->fetchAll();

// Get applied exceptional laws for this employee
$appliedLaws = [];
if ($id > 0) {
    $stmt = $db->prepare("SELECT law_reference FROM employee_grade_history WHERE employee_id = ? AND law_reference IS NOT NULL");
    $stmt->execute([$id]);
    $appliedLaws = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get bonuses
$bonuses = ['prime_fixe' => [], 'aide_complementaire' => [], 'transport_complement' => [], 'transport_daily' => []];
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM employee_bonuses WHERE employee_id = ? AND (school_year = ? OR school_year IS NULL)");
    $stmt->execute([$id, activeSchoolYear()]);
    while ($row = $stmt->fetch()) {
        $bonuses[$row['bonus_type']][] = $row;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="d-flex justify-between align-center mb-3">
    <a href="<?= BASE_URL ?>pages/employees.php" class="btn btn-light">
        <i class="fas fa-arrow-left"></i> Retour à la liste / العودة إلى اللائحة
    </a>
    <?php if ($id > 0): ?>
        <div class="d-flex gap-2">
            <button type="button" id="btnEditEmp" data-lockedit-for="empForm" class="btn btn-warning" style="display:none">
                <i class="fas fa-pen"></i> تعديل / Modifier
            </button>
            <a href="?action=delete&id=<?= $id ?>" class="btn btn-danger" data-confirm="<?= e('⚠️ تأكيد الحذف — هل تريد فعلاً حذف الموظف: «' . (trim($employee['first_name_ar'].' '.$employee['last_name_ar']) ?: trim($employee['first_name_fr'].' '.$employee['last_name_fr'])) . '» ؟ بعد الحذف لا يعود يظهر في البرنامج (يمكن استرجاعه لاحقاً عند الحاجة).') ?>">
                <i class="fas fa-trash"></i> حذف / Supprimer
            </a>
            <a href="<?= BASE_URL ?>pages/grades.php?employee_id=<?= $id ?>" class="btn btn-light">
                <i class="fas fa-layer-group"></i> Échelons & Promotions / الدرجات والترقيات
            </a>
            <a href="<?= BASE_URL ?>pages/monthly_payroll.php?employee_id=<?= $id ?>" class="btn btn-gold">
                <i class="fas fa-money-check"></i> Calculer paie / حساب الراتب
            </a>
            <a href="<?= BASE_URL ?>pages/annual_slip.php?employee_id=<?= $id ?>" class="btn btn-primary">
                <i class="fas fa-file-invoice"></i> Bulletin annuel / الكشف السنوي
            </a>
        </div>
    <?php endif; ?>
</div>

<form method="POST" enctype="multipart/form-data" id="empForm"<?= $id > 0 ? ' class="lockedit"' : '' ?>>
    <input type="hidden" name="active_tab" id="activeTabField" value="<?= e(preg_replace('/[^a-z]/', '', $_GET['tab'] ?? '')) ?>">
    <!-- Tabs -->
    <div class="tabs">
        <button type="button" class="tab active" data-tab="personal">👤 Personnel / شخصي</button>
        <button type="button" class="tab" data-tab="address">🏠 Adresse / العنوان</button>
        <button type="button" class="tab" data-tab="employment">🎓 Emploi / التوظيف</button>
        <button type="button" class="tab" data-tab="finance">💰 Financier / مالي</button>
        <button type="button" class="tab" data-tab="bonuses">🎁 Primes & Indemnités / مكافآت وتعويضات</button>
        <button type="button" class="tab" data-tab="deductions">🧾 Retenues / محسومات</button>
        <?php if ($id > 0 && $employee['employee_type'] === 'enseignant_titulaire'): ?>
        <button type="button" class="tab" data-tab="grades">🏆 الدرجات / Échelons</button>
        <?php endif; ?>
    </div>
    
    <!-- ========== Personal Tab ========== -->
    <div class="tab-content active" data-tab-content="personal">
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-id-card"></i> Type & Identité</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">النوع والهوية</div>
                </h3>
            </div>
            <div class="card-body">
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">النوع: أستاذ أو موظف / Type <span class="req">*</span></label>
                        <select name="employee_type" class="form-select" required>
                            <option value="enseignant_titulaire" <?= $employee['employee_type'] === 'enseignant_titulaire' ? 'selected' : '' ?>>
                                👨‍🏫 أستاذ في الملاك / Enseignant titulaire
                            </option>
                            <option value="enseignant_contractuel" <?= $employee['employee_type'] === 'enseignant_contractuel' ? 'selected' : '' ?>>
                                📝 أستاذ متعاقد / Enseignant contractuel
                            </option>
                            <option value="employe" <?= $employee['employee_type'] === 'employe' ? 'selected' : '' ?>>
                                🏢 موظف إداري / Employé
                            </option>
                        </select>
                        <small style="color:var(--gray-500);font-size:11.5px;display:block;margin-top:4px;line-height:1.5">
                            ℹ️ <strong>الأستاذ</strong>: يخضع للضمان لفرع <strong>المرض والأمومة</strong> فقط، ونهاية خدمته من <strong>صندوق التعويضات</strong>.
                            <br><strong>الموظف</strong>: يخضع لكل فروع الضمان (مرض + <strong>نهاية الخدمة</strong> + تعويضات عائلية).
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Code / الرمز <small>(auto)</small></label>
                        <input type="text" class="form-control" value="<?= e($employee['employee_code']) ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Statut / الحالة</label>
                        <select name="status" class="form-select">
                            <option value="actif" <?= $employee['status'] === 'actif' ? 'selected' : '' ?>>Actif / نشط</option>
                            <option value="suspendu" <?= $employee['status'] === 'suspendu' ? 'selected' : '' ?>>Suspendu / متوقف</option>
                            <option value="retraite" <?= $employee['status'] === 'retraite' ? 'selected' : '' ?>>Retraité / متقاعد</option>
                            <option value="demissionne" <?= $employee['status'] === 'demissionne' ? 'selected' : '' ?>>Démissionné / مستقيل</option>
                        </select>
                    </div>
                </div>
                
                <h4 style="color:var(--primary);margin-top:20px;">
                    <span dir="ltr">Informations personnelles</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">المعلومات الشخصية</div>
                </h4>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">Prénom (FR) / الاسم الأول</label>
                        <input type="text" name="first_name_fr" class="form-control" value="<?= e($employee['first_name_fr']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prénom / الاسم الأول (AR) <span class="req">*</span></label>
                        <input type="text" name="first_name_ar" class="form-control" value="<?= e($employee['first_name_ar']) ?>" dir="rtl" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom du père (FR) / اسم الأب</label>
                        <input type="text" name="father_name_fr" class="form-control" value="<?= e($employee['father_name_fr']) ?>">
                    </div>
                </div>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">Nom du père / اسم الأب (AR)</label>
                        <input type="text" name="father_name_ar" class="form-control" value="<?= e($employee['father_name_ar']) ?>" dir="rtl">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom de famille (FR) / الشهرة</label>
                        <input type="text" name="last_name_fr" class="form-control" value="<?= e($employee['last_name_fr']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom de famille / الشهرة (AR) <span class="req">*</span></label>
                        <input type="text" name="last_name_ar" class="form-control" value="<?= e($employee['last_name_ar']) ?>" dir="rtl" required>
                    </div>
                </div>
                
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Prénom de la mère / اسم الأم</label>
                        <input type="text" name="mother_first_name" class="form-control" value="<?= e($employee['mother_first_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom de famille de la mère / شهرة الأم</label>
                        <input type="text" name="mother_last_name" class="form-control" value="<?= e($employee['mother_last_name']) ?>">
                    </div>
                </div>
                
                <div class="form-row cols-4">
                    <div class="form-group">
                        <label class="form-label">Nationalité / الجنسية</label>
                        <select name="nationality" class="form-select">
                            <option value="lebanese" <?= $employee['nationality'] === 'lebanese' ? 'selected' : '' ?>>Libanais / لبناني</option>
                            <option value="syrian" <?= $employee['nationality'] === 'syrian' ? 'selected' : '' ?>>Syrien / سوري</option>
                            <option value="palestinian" <?= $employee['nationality'] === 'palestinian' ? 'selected' : '' ?>>Palestinien / فلسطيني</option>
                            <option value="other" <?= $employee['nationality'] === 'other' ? 'selected' : '' ?>>Autre / أخرى</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date de naissance / تاريخ الولادة</label>
                        <input type="date" name="birth_date" class="form-control" value="<?= e($employee['birth_date']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Lieu de naissance / مكان الولادة</label>
                        <input type="text" name="birth_place" class="form-control" value="<?= e($employee['birth_place']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">N° registre civil / رقم السجل المدني</label>
                        <input type="text" name="civil_registry_number" class="form-control" value="<?= e($employee['civil_registry_number']) ?>">
                    </div>
                </div>
                
                <h4 style="color:var(--primary);margin-top:20px;">
                    <span dir="ltr">Situation familiale</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الحالة العائلية</div>
                </h4>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">Situation / الوضع العائلي</label>
                        <select name="social_status" class="form-select">
                            <option value="celibataire" <?= $employee['social_status'] === 'celibataire' ? 'selected' : '' ?>>Célibataire / أعزب</option>
                            <option value="marie_sans_enfants" <?= $employee['social_status'] === 'marie_sans_enfants' ? 'selected' : '' ?>>Marié sans enfants / متزوج بلا أولاد</option>
                            <option value="marie_1_enfant" <?= $employee['social_status'] === 'marie_1_enfant' ? 'selected' : '' ?>>Marié 1 enfant / متزوج وولد</option>
                            <option value="marie_2_enfants" <?= $employee['social_status'] === 'marie_2_enfants' ? 'selected' : '' ?>>Marié 2 enfants / متزوج وولدان</option>
                            <option value="marie_3_enfants" <?= $employee['social_status'] === 'marie_3_enfants' ? 'selected' : '' ?>>Marié 3 enfants / متزوج و3 أولاد</option>
                            <option value="marie_4_enfants" <?= $employee['social_status'] === 'marie_4_enfants' ? 'selected' : '' ?>>Marié 4 enfants / متزوج و4 أولاد</option>
                            <option value="marie_5_enfants" <?= $employee['social_status'] === 'marie_5_enfants' ? 'selected' : '' ?>>Marié 5 enfants / متزوج و5 أولاد</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Conjoint travaille ? / هل يعمل الزوج؟</label>
                        <label class="switch">
                            <input type="checkbox" name="spouse_works" value="1" <?= $employee['spouse_works'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nombre d'enfants / عدد الأولاد</label>
                        <input type="number" name="number_of_children" class="form-control" value="<?= (int)$employee['number_of_children'] ?>" min="0" max="20">
                    </div>
                </div>

                <?php /* قسم المستندات والصور — منقول إلى التبويب الشخصي (personnel) */ echo renderEmployeeScans($employee, $id); ?>
            </div>
        </div>
    </div>

    <!-- ========== Address Tab ========== -->
    <div class="tab-content" data-tab-content="address">
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-home"></i> Adresse & Contact</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">العنوان والتواصل</div>
                </h3>
            </div>
            <div class="card-body">
                <div class="form-row cols-4">
                    <div class="form-group">
                        <label class="form-label">Gouvernorat / المحافظة</label>
                        <input type="text" name="gouvernorat" class="form-control" value="<?= e($employee['gouvernorat']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Caza / القضاء</label>
                        <input type="text" name="district" class="form-control" value="<?= e($employee['district']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ville / المدينة</label>
                        <input type="text" name="ville" class="form-control" value="<?= e($employee['ville']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quartier / الحي</label>
                        <input type="text" name="quartier" class="form-control" value="<?= e($employee['quartier']) ?>">
                    </div>
                </div>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">Rue / الشارع</label>
                        <input type="text" name="rue" class="form-control" value="<?= e($employee['rue']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Immeuble / المبنى</label>
                        <input type="text" name="immeuble" class="form-control" value="<?= e($employee['immeuble']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Étage / الطابق</label>
                        <input type="text" name="etage" class="form-control" value="<?= e($employee['etage']) ?>">
                    </div>
                </div>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">Téléphone 1 / الهاتف 1</label>
                        <input type="text" name="phone1" class="form-control" value="<?= e($employee['phone1']) ?>" placeholder="+961 ...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Téléphone 2 / الهاتف 2</label>
                        <input type="text" name="phone2" class="form-control" value="<?= e($employee['phone2']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= e($employee['email']) ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ========== Employment Tab ========== -->
    <div class="tab-content" data-tab-content="employment">
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-graduation-cap"></i> Qualifications & Emploi</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">المؤهلات والتوظيف</div>
                </h3>
            </div>
            <div class="card-body">
                <?php
                  // وظيفة الموظف الإداري: كود معروف من القائمة أو نص حرّ سابق.
                  $jobOpts   = jobTitleOptions();
                  $jobVal    = trim((string)($employee['job_title'] ?? ''));
                  $jobIsCode = ($jobVal !== '' && isset($jobOpts[$jobVal]));
                  $jobIsOther= ($jobVal !== '' && !$jobIsCode);
                ?>
                <!-- ===== الموظف الإداري فقط: نوع الوظيفة (لا صفوف ولا مواد) ===== -->
                <div class="form-row emp-admin-only" style="display:none">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">نوع الوظيفة / Fonction <span class="req">*</span></label>
                        <select name="job_title" id="jobTitleSelect" class="form-select"
                                onchange="document.getElementById('jobTitleOther').style.display = (this.value==='__other__')?'block':'none'">
                            <option value="">— اختر / Choisir —</option>
                            <?php foreach ($jobOpts as $code => $lbl): ?>
                                <option value="<?= e($code) ?>" <?= $jobVal === $code ? 'selected' : '' ?>><?= e($lbl['ar'] . ' / ' . $lbl['fr']) ?></option>
                            <?php endforeach; ?>
                            <option value="__other__" <?= $jobIsOther ? 'selected' : '' ?>>أخرى (حدّد) / Autre…</option>
                        </select>
                        <input type="text" name="job_title_other" id="jobTitleOther" class="form-control"
                               style="margin-top:8px;display:<?= $jobIsOther ? 'block' : 'none' ?>"
                               placeholder="حدّد الوظيفة / Préciser la fonction" value="<?= $jobIsOther ? e($jobVal) : '' ?>">
                        <small class="text-muted d-block" style="margin-top:4px">
                            الموظف الإداري يخضع لقانون العمل ولكل فروع الضمان — لا صفوف ولا مواد ولا سلسلة رتب.
                        </small>
                    </div>
                </div>

                <div class="form-row cols-3 emp-teacher-only">
                    <div class="form-group">
                        <label class="form-label">Diplôme / الشهادة</label>
                        <select name="diploma" class="form-select">
                            <?php foreach ($diplomas as $d): ?>
                                <option value="<?= e($d['diploma_code']) ?>" data-grade="<?= $d['starting_grade'] ?>" data-immediate="<?= (int)$d['gets_immediate_grade'] ?>" <?= $employee['diploma'] === $d['diploma_code'] ? 'selected' : '' ?>>
                                    <?= e($d['diploma_name_fr']) ?> / <?= e($d['diploma_name_ar']) ?> (G<?= $d['starting_grade'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Spécialisation / الاختصاص</label>
                        <input type="text" name="specialization" class="form-control" value="<?= e($employee['specialization']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Matières enseignées / المواد التي يعلّمها</label>
                        <input type="text" name="subjects_taught" class="form-control" value="<?= e($employee['subjects_taught']) ?>" placeholder="Maths, Sciences...">
                    </div>
                </div>
                
                <div class="form-row cols-3">
                    <div class="form-group emp-teacher-only">
                        <label class="form-label">المرحلة التي يعلّم فيها / Niveau scolaire</label>
                        <div class="d-flex gap-3 mt-2" style="flex-wrap:wrap">
                            <?php $niveau = explode(',', $employee['niveau_scolaire']); ?>
                            <label><input type="checkbox" name="niveau_scolaire[]" value="maternelle" <?= in_array('maternelle', $niveau) ? 'checked' : '' ?>> حضانة / Maternelle</label>
                            <label><input type="checkbox" name="niveau_scolaire[]" value="primaire" <?= in_array('primaire', $niveau) ? 'checked' : '' ?>> ابتدائي / Primaire</label>
                            <label><input type="checkbox" name="niveau_scolaire[]" value="intermediaire" <?= in_array('intermediaire', $niveau) ? 'checked' : '' ?>> متوسط / Intermédiaire</label>
                            <label><input type="checkbox" name="niveau_scolaire[]" value="secondaire" <?= in_array('secondaire', $niveau) ? 'checked' : '' ?>> ثانوي / Secondaire</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jours/semaine / أيام بالأسبوع <small>(أيام الحضور — منها يُحسب النقل)</small></label>
                        <input type="number" id="daysPerWeek" name="days_per_week" class="form-control" value="<?= (int)$employee['days_per_week'] ?>" min="1" max="7">
                        <small id="dpwTransport" class="text-muted" style="display:block;margin-top:3px"></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Heures/semaine / ساعات بالأسبوع</label>
                        <input type="number" name="hours_per_week" class="form-control" value="<?= (float)$employee['hours_per_week'] ?>" step="0.5" min="0">
                    </div>
                </div>

                <div class="form-row emp-teacher-only">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">الصفوف التي يعلّم فيها / Classes enseignées</label>
                        <?php
                          try { $classLevels = $db->query("SELECT id, name, name_fr FROM class_levels WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(); }
                          catch (Exception $e) { $classLevels = []; } // الجدول قد لا يكون موجوداً بعد (قبل تطبيق migration)
                          $selClasses = array_filter(array_map('intval', explode(',', (string)($employee['classes_taught'] ?? ''))));
                        ?>
                        <?php if (!$classLevels): ?>
                          <small class="text-muted">لا صفوف بعد — <a href="<?= BASE_URL ?>pages/classes.php">أضف لائحة الصفوف من هنا</a> ثم اختر للأستاذ.</small>
                        <?php else: ?>
                          <div class="d-flex gap-3 mt-2" style="flex-wrap:wrap;gap:14px">
                            <?php foreach ($classLevels as $cl): ?>
                              <label style="white-space:nowrap;font-weight:400"><input type="checkbox" name="classes_taught[]" value="<?= (int)$cl['id'] ?>" <?= in_array((int)$cl['id'], $selClasses, true) ? 'checked' : '' ?> style="margin-left:4px"> <?= e(trim((string)$cl['name_fr']) !== '' ? $cl['name_fr'].' / '.$cl['name'] : $cl['name']) ?></label>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row cols-4">
                    <div class="form-group">
                        <label class="form-label">Date d'embauche / تاريخ الدخول <span class="req">*</span> <small>(دخول المدرسة)</small></label>
                        <input type="date" id="hireDate" name="hire_date" class="form-control" value="<?= e($employee['hire_date']) ?>" required>
                    </div>
                    <div class="form-group emp-teacher-only">
                        <label class="form-label">Titularisation / دخول الملاك <small>(تلقائي = دخول المدرسة + سنتين، يمكن تعديله)</small></label>
                        <input type="date" id="malakDate" name="titularization_date" class="form-control" value="<?= e($employee['titularization_date']) ?>">
                        <small id="malakHint" class="text-muted" style="display:block;margin-top:3px"></small>
                    </div>
                    <script>
                    (function(){
                        var hd=document.getElementById('hireDate'), md=document.getElementById('malakDate'), hint=document.getElementById('malakHint');
                        function plus2(d){ if(!d) return ''; var p=d.split('-'); if(p.length!==3) return ''; return (parseInt(p[0],10)+2)+'-'+p[1]+'-'+p[2]; }
                        // عند تغيير دخول المدرسة: عبّي دخول الملاك = +سنتين (إن كان فاضي أو مطابقاً للقاعدة)
                        function sync(force){ if(!hd) return; var want=plus2(hd.value);
                            if(force || !md.value){ md.value=want; }
                            hint.textContent = want ? ('القاعدة: دخول المدرسة + سنتين = '+want+(md.value!==want?' (عدّلته يدوياً)':'')) : '';
                        }
                        if(hd){ hd.addEventListener('change', function(){ sync(!md.value); }); }
                        sync(false);
                    })();
                    </script>
                    <div class="form-group emp-teacher-only">
                        <label class="form-label">Confirmation / تاريخ التثبيت <small>(اختياري — تلقائي = دخول المدرسة + سنتين؛ منه تبدأ الدرجات الاستثنائية بكانون)</small></label>
                        <input type="date" name="tenure_confirmation_date" class="form-control" value="<?= e($employee['tenure_confirmation_date']) ?>">
                    </div>
                    <div class="form-group emp-teacher-only">
                        <label class="form-label">Échelon initial / درجة الدخول</label>
                        <input type="number" name="starting_grade" class="form-control" value="<?= (float)$employee['starting_grade'] ?>" min="1" max="52" step="0.5">
                        <small class="grade-salary" data-grade-for="starting_grade" style="font-weight:600;color:var(--primary)"></small>
                    </div>
                    <div class="form-group emp-teacher-only">
                        <label class="form-label">Échelon actuel / الدرجة الحالية <span class="req">*</span> <small>(½ permis)</small></label>
                        <input type="number" name="current_grade" class="form-control" value="<?= (float)$employee['current_grade'] ?>" min="1" max="52" step="0.5" required>
                        <small class="grade-salary" data-grade-for="current_grade" style="font-weight:700;color:var(--gold-dark,#9a7b0a)"></small>
                        <small class="text-muted d-block">⬅ اختر الدرجة المطابقة لراتب الأستاذ الحالي حسب القانون (تشمل التدرّج والدرجات الاستثنائية).</small>
                    </div>
                </div>

                <h4 style="color:var(--primary);margin-top:16px;">
                    <span dir="ltr">Dates de départ</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">تواريخ الترك <small style="font-weight:normal;color:var(--gray-500)">(اتركها فاضية إذا الموظف ما زال على رأس عمله — والمتروك ما بينتقل للسنة الجديدة)</small></div>
                </h4>
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">ترك الضمان / Départ CNSS</label>
                        <input type="date" name="left_date_cnss" class="form-control" value="<?= e($employee['left_date_cnss'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ترك المالية / Départ Finances</label>
                        <input type="date" name="left_date_finance" class="form-control" value="<?= e($employee['left_date_finance'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ترك صندوق التعويضات / Départ EOC <small>(ملاك)</small></label>
                        <input type="date" name="left_date_eoc" class="form-control" value="<?= e($employee['left_date_eoc'] ?? '') ?>">
                    </div>
                </div>
                
                <h4 style="color:var(--primary);margin-top:20px;">
                    <span dir="ltr">Numéros officiels</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الأرقام الرسمية</div>
                </h4>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">N° NSSF/CNSS / رقم الضمان</label>
                        <input type="text" name="nssf_number" class="form-control" value="<?= e($employee['nssf_number']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">N° Ministère des Finances / رقم وزارة المالية</label>
                        <input type="text" name="finance_ministry_number" class="form-control" value="<?= e($employee['finance_ministry_number']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">N° Caisse d'indemnités / رقم صندوق التعويضات</label>
                        <input type="text" name="caisse_number" class="form-control" value="<?= e($employee['caisse_number']) ?>">
                    </div>
                </div>

                <?php if ($id > 0 && $employee['employee_type'] === 'enseignant_titulaire'): ?>
                <h4 style="color:var(--primary);margin-top:20px;">
                    <span dir="ltr">Grades exceptionnels</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الدرجات الاستثنائية</div>
                </h4>
                <div class="alert alert-info" style="font-size:13px">
                    <i class="fas fa-arrow-up"></i> إدارة الدرجات الاستثنائية (كل درجة لحالها بتاريخها) صارت في تبويب
                    <strong>«🏆 الدرجات»</strong> فوق — كل الدرجات مفصّلة مع شك-مارك وتاريخ قابل للتعديل، والدرجات المتاحة للإعطاء.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- ========== Finance Tab ========== -->
    <div class="tab-content" data-tab-content="finance">
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-money-bill-wave"></i> Salaire de base</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الراتب الأساسي</div>
                </h3>
            </div>
            <div class="card-body">
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">طريقة احتساب الراتب / Mode de salaire</label>
                        <select name="salary_input_mode" id="salaryModeSelect" class="form-select">
                            <option value="percent_of_lbp" <?= $employee['salary_input_mode'] === 'percent_of_lbp' ? 'selected' : '' ?>>📊 السلسلة حسب القانون (ملاك) / Échelle légale</option>
                            <option value="direct_lbp" <?= $employee['salary_input_mode'] === 'direct_lbp' ? 'selected' : '' ?>>💷 Salaire convenu en LBP / راتب متفق عليه بالليرة (متعاقد)</option>
                            <option value="direct_usd" <?= $employee['salary_input_mode'] === 'direct_usd' ? 'selected' : '' ?>>💵 Salaire convenu en USD / راتب متفق عليه بالدولار</option>
                        </select>
                    </div>
                    <div class="form-group salmode-field" data-mode="direct_lbp">
                        <label class="form-label">الراتب المتفق عليه (ل.ل) / Salaire convenu LBP</label>
                        <input type="number" name="contract_salary_lbp" class="form-control" value="<?= (int)($employee['contract_salary_lbp'] ?? 0) ?>" step="1000" min="0">
                        <small class="text-muted d-block">يُعتمد هذا المبلغ كأساس للراتب مباشرةً للأستاذ المتعاقد.</small>
                    </div>
                    <div class="form-group salmode-field" data-mode="direct_usd">
                        <label class="form-label">Salaire en USD ($) / الراتب بالدولار</label>
                        <input type="number" name="base_salary_usd" class="form-control" value="<?= e($employee['base_salary_usd']) ?>" step="0.01" min="0">
                    </div>
                    <div class="form-group salmode-field" data-mode="percent_of_lbp">
                        <label class="form-label">أساس الراتب حسب السلسلة (ل.ل) / Échelle</label>
                        <input type="text" id="scaleSalaryDisplay" class="form-control" value="" readonly style="background:#f1f5f9;font-weight:700">
                        <small class="text-muted d-block">يُحتسب تلقائياً حسب درجة الأستاذ والقانون. النسبة:
                            <input type="number" name="base_salary_lbp_percent" value="<?= e($employee['base_salary_lbp_percent']) ?>" step="0.01" min="0" max="200" style="width:70px;display:inline-block" class="form-control form-control-sm"> %
                        </small>
                    </div>
                </div>
                <script>
                (function(){
                    var modeSel = document.getElementById('salaryModeSelect');
                    var typeSel = document.querySelector('[name="employee_type"]');
                    function syncSalMode(){
                        var m = modeSel.value;
                        document.querySelectorAll('.salmode-field').forEach(function(el){
                            el.style.display = (el.getAttribute('data-mode') === m) ? '' : 'none';
                        });
                    }
                    modeSel.addEventListener('change', syncSalMode);
                    // الموظف الإداري: أخفِ حقول الأستاذ (شهادة/مواد/مرحلة/صفوف/درجة) وأظهِر نوع الوظيفة، والعكس للأستاذ.
                    function syncEmpType(){
                        var isAdmin = typeSel && typeSel.value === 'employe';
                        document.querySelectorAll('.emp-admin-only').forEach(function(el){ el.style.display = isAdmin ? '' : 'none'; });
                        document.querySelectorAll('.emp-teacher-only').forEach(function(el){ el.style.display = isAdmin ? 'none' : ''; });
                        // حقول إلزامية داخل قسم الأستاذ: انزع required عند إخفائها (وإلا يفشل الحفظ بصمت للموظف الإداري)،
                        // وأعِد نوع الوظيفة إلزامياً للموظف فقط.
                        var curGrade = document.querySelector('[name="current_grade"]');
                        if (curGrade) curGrade.required = !isAdmin;
                        var jobSel = document.getElementById('jobTitleSelect');
                        if (jobSel) jobSel.required = isAdmin;
                    }
                    // عند اختيار نوع الأستاذ: ملاك ⇐ السلسلة حسب القانون · متعاقد ⇐ ليرة متفق عليها
                    if (typeSel) typeSel.addEventListener('change', function(){
                        if (typeSel.value === 'enseignant_titulaire') modeSel.value = 'percent_of_lbp';
                        else if (modeSel.value === 'percent_of_lbp') modeSel.value = 'direct_lbp';
                        syncSalMode();
                        syncEmpType();
                    });
                    syncSalMode();
                    syncEmpType();
                })();
                </script>
                
                <div class="form-row cols-3">
                    <div class="form-group">
                        <label class="form-label">Nombre de mois payés/an / عدد الأشهر المدفوعة سنوياً</label>
                        <select name="payment_months_per_year" class="form-select">
                            <option value="10" <?= $employee['payment_months_per_year'] == 10 ? 'selected' : '' ?>>10 mois (Enseignant) / 10 أشهر (أستاذ)</option>
                            <option value="11" <?= $employee['payment_months_per_year'] == 11 ? 'selected' : '' ?>>11 mois / 11 شهراً</option>
                            <option value="12" <?= $employee['payment_months_per_year'] == 12 ? 'selected' : '' ?>>12 mois (Employé) / 12 شهراً (موظف)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">13ème mois / شهر التعويض</label>
                        <label class="switch">
                            <input type="checkbox" id="has13" name="has_13th_month" value="1" <?= $employee['has_13th_month'] ? 'checked' : '' ?> onchange="document.getElementById('m13opts').style.display=this.checked?'block':'none'">
                            <span class="slider"></span>
                        </label>
                        <div id="m13opts" style="display:<?= $employee['has_13th_month'] ? 'block' : 'none' ?>;margin-top:8px;padding:8px 10px;background:#f8fafc;border:1px solid var(--gray-200);border-radius:6px">
                            <div style="font-size:12px;color:var(--gray-600);margin-bottom:4px">يُحسب شهر 13 على الراتب الأساسي. اختر ما يُضاف معه:</div>
                            <label style="display:block;font-size:13px;margin-bottom:3px"><input type="checkbox" name="m13_include_extra" value="1" <?= !empty($employee['m13_include_extra']) ? 'checked' : '' ?>> + الأجر الإضافي / Supplément</label>
                            <label style="display:block;font-size:13px"><input type="checkbox" name="m13_include_aide" value="1" <?= !empty($employee['m13_include_aide']) ? 'checked' : '' ?>> + المكافأة والمساعدة / Prime &amp; aide</label>
                        </div>
                    </div>
                </div>
                
                <h4 style="color:var(--primary);margin-top:20px;">
                    <span dir="ltr">Allocations familiales (exonérées de toutes retenues)</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">التعويضات العائلية (معفاة من كل المحسومات)</div>
                </h4>
                <p style="color:var(--gray-500);font-size:13px;">معفاة من كل المحسومات والضرائب</p>
                
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Allocation épouse (L.L) / تعويض الزوجة</label>
                        <input type="number" name="family_allowance_spouse_lbp" class="form-control" value="<?= (int)$employee['family_allowance_spouse_lbp'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Allocation enfants (L.L) / تعويض الأولاد</label>
                        <input type="number" name="family_allowance_children_lbp" class="form-control" value="<?= (int)$employee['family_allowance_children_lbp'] ?>" min="0">
                    </div>
                </div>

                <?php
                // بذور محرّر فترات النقل اليومي: الفترات الموجودة، وإلا القيمة القديمة (العمود) كفترة كاملة السنة.
                $tdSeed = [];
                foreach (($bonuses['transport_daily'] ?? []) as $r) {
                    $tdSeed[] = [
                        'value'    => rtrim(rtrim(number_format((float)$r['amount'], 2, '.', ''), '0'), '.'),
                        'currency' => (($r['currency'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP',
                        'from'     => (int)($r['start_month'] ?: 10),
                        'to'       => (int)($r['end_month'] ?: 9),
                    ];
                }
                if (!$tdSeed && (float)($employee['transport_daily_amount'] ?? 0) > 0) {
                    $tdSeed[] = [
                        'value'    => rtrim(rtrim(number_format((float)$employee['transport_daily_amount'], 2, '.', ''), '0'), '.'),
                        'currency' => (($employee['transport_daily_currency'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP',
                        'from'     => 10, 'to' => 9,
                    ];
                }
                ?>
                <h4 style="color:var(--primary);margin-top:24px;">
                    <span dir="ltr">🚌 Transport journalier</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">تعويض النقل اليومي</div>
                </h4>
                <p style="color:var(--gray-500);font-size:13px;margin-top:0">
                    القيمة <strong>يومية</strong>، والبرنامج يحسب النقل الشهري = <strong>اليومي × الأيام × الأسابيع</strong>.
                    أضِف <strong>سطر لكل فترة (من شهر إلى شهر)</strong> إذا تغيّرت القيمة اليومية خلال السنة. (اتركه فاضي إذا ما في نقل.)
                </p>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">أيام بالأسبوع / Jours/sem.</label>
                        <input type="number" id="trDays" name="transport_days_per_week" class="form-control" value="<?= (int)($employee['transport_days_per_week'] ?? 0) ?>" min="0" max="7">
                        <small class="text-muted">إذا 0 يُعتمد «أيام الحضور» أعلاه</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">عدد الأسابيع / Semaines</label>
                        <input type="number" id="trWeeks" name="transport_weeks" class="form-control" value="<?= rtrim(rtrim(number_format((float)($employee['transport_weeks'] ?? 4),1,'.',''),'0'),'.') ?>" step="0.5" min="0">
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="table" style="min-width:560px">
                        <thead><tr>
                            <th>Valeur journalière / القيمة اليومية</th><th>Devise / العملة</th><th>De (mois) / من شهر</th><th>À (mois) / إلى شهر</th><th>Mensuel ≈ / الشهري ≈</th><th></th>
                        </tr></thead>
                        <tbody id="tLinesBody"></tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-light btn-sm" onclick="addTLine()"><i class="fas fa-plus"></i> أضِف فترة نقل / Ajouter</button>
                <p style="font-size:12px;color:var(--gray-500);margin-top:6px">كل فترة تُحسب بشهورها فقط؛ الأيام والأسابيع مشتركة للكل.</p>
                <script>
                (function(){
                    var MONTHS=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
                    var ORDER=[10,11,12,1,2,3,4,5,6,7,8,9]; var ti=0;
                    var SEED=<?= json_encode($tdSeed) ?>;
                    function mOpts(sel){var o='';ORDER.forEach(function(m){o+='<option value="'+m+'"'+(m==sel?' selected':'')+'>'+MONTHS[m]+'</option>';});return o;}
                    function fmt(n){ return n.toLocaleString('en-US',{maximumFractionDigits:2}); }
                    function sharedDW(){
                        var trDays=parseFloat((document.getElementById('trDays')||{}).value)||0;
                        var attDays=parseFloat((document.getElementById('daysPerWeek')||{}).value)||0;
                        var days=trDays>0?trDays:attDays;
                        var w=parseFloat((document.getElementById('trWeeks')||{}).value)||0; if(w<=0) w=4;
                        return {days:days,w:w};
                    }
                    function calcRow(tr){
                        var val=parseFloat(tr.querySelector('.tdVal').value)||0;
                        var cur=tr.querySelector('.tdCur').value;
                        var dw=sharedDW(); var m=val*dw.days*dw.w;
                        tr.querySelector('.tdMonthly').textContent = val>0 ? (fmt(m)+' '+(cur==='USD'?'$':'ل.ل')) : '—';
                    }
                    function calcAll(){
                        document.querySelectorAll('#tLinesBody tr').forEach(calcRow);
                        var first=document.querySelector('#tLinesBody tr'); var dt=document.getElementById('dpwTransport');
                        if(dt){ if(first){ var v=parseFloat(first.querySelector('.tdVal').value)||0; var dw=sharedDW();
                            dt.textContent = v>0 ? ('تعويض النقل الشهري ≈ '+fmt(v*dw.days*dw.w)+' ('+dw.days+' يوم × '+dw.w+' أسبوع)') : ''; } else dt.textContent=''; }
                    }
                    window.addTLine=function(val,cur,from,to){
                        from=from||10;to=to||9;cur=cur||'LBP';val=(val===undefined||val===null?'':val);
                        var i=ti++; var tr=document.createElement('tr');
                        tr.innerHTML='<td><input type="number" step="0.01" min="0" name="tlines['+i+'][value]" class="form-control tdVal" value="'+val+'" style="min-width:100px"></td>'
                          +'<td><select name="tlines['+i+'][currency]" class="form-select tdCur"><option value="LBP"'+(cur=='LBP'?' selected':'')+'>ل.ل</option><option value="USD"'+(cur=='USD'?' selected':'')+'>$</option></select></td>'
                          +'<td><select name="tlines['+i+'][from]" class="form-select">'+mOpts(from)+'</select></td>'
                          +'<td><select name="tlines['+i+'][to]" class="form-select">'+mOpts(to)+'</select></td>'
                          +'<td class="tdMonthly" style="white-space:nowrap;color:var(--primary)">—</td>'
                          +'<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();">✕</button></td>';
                        document.getElementById('tLinesBody').appendChild(tr);
                        tr.querySelector('.tdVal').addEventListener('input',calcAll);
                        tr.querySelector('.tdCur').addEventListener('change',calcAll);
                        calcRow(tr);
                    };
                    if(SEED.length){ SEED.forEach(function(s){ addTLine(s.value,s.currency,s.from,s.to); }); } else { addTLine(); }
                    ['trDays','trWeeks','daysPerWeek'].forEach(function(id){var el=document.getElementById(id); if(el){el.addEventListener('input',calcAll); el.addEventListener('change',calcAll);}});
                    calcAll();
                })();
                </script>

                <h4 style="color:var(--primary);margin-top:24px;">
                    <span dir="ltr">Prime, aide & transport</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">مكافأة ومساعدة وتعويض النقل</div>
                </h4>
                <p style="color:var(--gray-500);font-size:13px;margin-top:0">
                    كل سطر: النوع · العملة (ل.ل أو $) · مبلغ أو نسبة % · من شهر إلى شهر.
                    أضِف أسطر للّيرة وللدولار وعلى مراحل. (النسبة % تُحسب من الراتب الأساسي · شهر فاضي = كل الأشهر)
                </p>
                <input type="hidden" name="bonus_editor" value="1">
                <div class="table-wrapper">
                    <table class="table" style="min-width:680px">
                        <thead><tr>
                            <th>Type / النوع</th><th>Devise / العملة</th><th>Montant/Taux / مبلغ/نسبة</th><th>Valeur / القيمة</th><th>De (mois) / من شهر</th><th>À (mois) / إلى شهر</th><th></th>
                        </tr></thead>
                        <tbody id="bonusBody">
                            <?php
                            $bonusCount = 0;
                            foreach (['prime_fixe', 'aide_complementaire', 'transport_complement'] as $bt) {
                                foreach ($bonuses[$bt] as $r) { echo renderBonusRow($r); $bonusCount++; }
                            }
                            if ($bonusCount === 0) { for ($z = 0; $z < 3; $z++) echo renderBonusRow(null); } // ٣ فترات جاهزة
                            ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-light btn-sm" onclick="addBonusRow()"><i class="fas fa-plus"></i> إضافة سطر / Ajouter</button>
                <template id="bonusRowTpl"><?= renderBonusRow(null) ?></template>
                <script>
                function addBonusRow(){
                    var tpl = document.getElementById('bonusRowTpl');
                    document.getElementById('bonusBody').insertAdjacentHTML('beforeend', tpl.innerHTML);
                }
                </script>
            </div>
        </div>
    </div>
    
    <!-- ========== Bonuses Tab ========== -->
    <div class="tab-content" data-tab-content="bonuses">
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-gift"></i> Primes & Aides & Transport</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">المكافآت والمساعدات والنقل</div>
                </h3>
                <?php if ($id > 0): ?>
                    <a href="<?= BASE_URL ?>pages/bonuses.php?employee_id=<?= $id ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit"></i> Gérer les primes / إدارة المكافآت
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($id == 0): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Enregistrez d'abord l'employé, puis vous pourrez ajouter les primes et indemnités.
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Type / النوع</th>
                                <th>Période / الفترة</th>
                                <th>Montant / المبلغ</th>
                                <th>Mois / الأشهر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $bonusLabels = [
                                'prime_fixe' => '➕ الأجر الإضافي / Supplément',
                                'aide_complementaire' => '💰 مكافأة ومساعدة / Prime &amp; aide',
                                'transport_complement' => '🚌 Complément transport / تعويض نقل'
                            ];
                            foreach ($bonusLabels as $type => $label):
                                $hasAny = false;
                                foreach ($bonuses[$type] as $b): $hasAny = true;
                            ?>
                                <tr>
                                    <td><?= $label ?></td>
                                    <td>P<?= $b['period_number'] ?></td>
                                    <td><?= $b['currency'] === 'USD' ? formatUSD($b['amount']) : formatLBP($b['amount']) ?></td>
                                    <td><?= $b['start_month'] ? monthName($b['start_month'], 'fr', true) : 'Tous' ?> → <?= $b['end_month'] ? monthName($b['end_month'], 'fr', true) : 'Tous' ?></td>
                                </tr>
                            <?php endforeach; if (!$hasAny): ?>
                                <tr><td><?= $label ?></td><td colspan="3" style="color:var(--gray-400)">Aucune / لا يوجد</td></tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- ========== Deductions Tab ========== -->
    <div class="tab-content" data-tab-content="deductions">
        <div class="card">
            <div class="card-header">
                <h3>
                    <span dir="ltr"><i class="fas fa-percent"></i> Configuration des bases de retenues</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">إعداد أسس المحسومات</div>
                </h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Choisissez les composants soumis à chaque type de retenue. Les allocations familiales sont toujours exonérées.
                </div>
                
                <div class="form-row cols-3">
                    <!-- Tax -->
                    <div class="card" style="margin:0;">
                        <div class="card-header" style="background:#fef2f2;">
                            <h3 style="color:var(--danger)">
                                <span dir="ltr"><i class="fas fa-file-invoice-dollar"></i> Impôt sur le revenu</span>
                                <div style="font-size:0.85em;font-weight:600;opacity:0.9">ضريبة الدخل</div>
                            </h3>
                        </div>
                        <div class="card-body">
                            <label class="d-flex justify-between align-center mb-3">
                                <span><strong>خاضع للضريبة</strong> / Soumis à l'impôt</span>
                                <label class="switch"><input type="checkbox" name="tax_subject" value="1" <?= $employee['tax_subject'] ? 'checked' : '' ?>><span class="slider"></span></label>
                            </label>
                            <p style="font-size:12px;color:var(--gray-500)">Inclure dans la base:</p>
                            <label><input type="checkbox" name="tax_includes_echelon" value="1" <?= $employee['tax_includes_echelon'] ? 'checked' : '' ?>> الدرجة والتدرّج / Échelon</label><br>
                            <label><input type="checkbox" name="tax_includes_extra" value="1" <?= $employee['tax_includes_extra'] ? 'checked' : '' ?>> الأجر الإضافي / Supplément</label><br>
                            <label><input type="checkbox" name="tax_includes_prime_aide" value="1" <?= $employee['tax_includes_prime_aide'] ? 'checked' : '' ?>> مكافأة ومساعدة / Prime &amp; aide</label>
                        </div>
                    </div>
                    
                    <!-- CNSS -->
                    <div class="card" style="margin:0;">
                        <div class="card-header" style="background:#eff6ff;">
                            <h3 style="color:var(--info)">
                                <span dir="ltr"><i class="fas fa-hospital"></i> CNSS (3%)</span>
                                <div style="font-size:0.85em;font-weight:600;opacity:0.9">الضمان الاجتماعي (٣٪)</div>
                            </h3>
                        </div>
                        <div class="card-body">
                            <label class="d-flex justify-between align-center mb-3">
                                <span><strong>خاضع للضمان</strong> / Inscrit à la CNSS</span>
                                <label class="switch"><input type="checkbox" name="cnss_subject" value="1" <?= $employee['cnss_subject'] ? 'checked' : '' ?>><span class="slider"></span></label>
                            </label>
                            <p style="font-size:12px;color:var(--gray-500)">Inclure dans la base:</p>
                            <label><input type="checkbox" name="cnss_includes_echelon" value="1" <?= $employee['cnss_includes_echelon'] ? 'checked' : '' ?>> الدرجة والتدرّج / Échelon</label><br>
                            <label><input type="checkbox" name="cnss_includes_extra" value="1" <?= $employee['cnss_includes_extra'] ? 'checked' : '' ?>> الأجر الإضافي / Supplément</label><br>
                            <label><input type="checkbox" name="cnss_includes_prime_aide" value="1" <?= $employee['cnss_includes_prime_aide'] ? 'checked' : '' ?>> مكافأة ومساعدة / Prime &amp; aide</label>
                        </div>
                    </div>
                    
                    <!-- EOC -->
                    <div class="card" style="margin:0;">
                        <div class="card-header" style="background:#fffbeb;">
                            <h3 style="color:var(--warning)">
                                <span dir="ltr"><i class="fas fa-piggy-bank"></i> Caisse EOC (6%)</span>
                                <div style="font-size:0.85em;font-weight:600;opacity:0.9">صندوق التعويضات (٦٪)</div>
                            </h3>
                        </div>
                        <div class="card-body">
                            <label class="d-flex justify-between align-center mb-3">
                                <span><strong>خاضع لصندوق التعويضات</strong> / Soumis à la Caisse</span>
                                <label class="switch"><input type="checkbox" name="eoc_subject" value="1" <?= $employee['eoc_subject'] ? 'checked' : '' ?>><span class="slider"></span></label>
                            </label>
                            <p style="font-size:12px;color:var(--gray-500)">* Enseignant titulaire uniquement</p>
                            <label><input type="checkbox" name="eoc_includes_echelon" value="1" <?= $employee['eoc_includes_echelon'] ? 'checked' : '' ?>> الدرجة والتدرّج / Échelon</label><br>
                            <label><input type="checkbox" name="eoc_includes_extra" value="1" <?= $employee['eoc_includes_extra'] ? 'checked' : '' ?>> الأجر الإضافي / Supplément</label><br>
                            <label><input type="checkbox" name="eoc_includes_prime_aide" value="1" <?= $employee['eoc_includes_prime_aide'] ? 'checked' : '' ?>> مكافأة ومساعدة / Prime &amp; aide</label>
                        </div>
                    </div>
                </div>

                <?php
                    $empAge = ageOnDate($employee['birth_date'] ?? '');
                    $emp64Date = '';
                    if ($empAge !== null && ($eb = date_create(substr((string)$employee['birth_date'], 0, 10)))) {
                        $emp64Date = $eb->modify('+64 years')->format('Y-m-d');
                    }
                ?>
                <div class="card" style="margin-top:16px;border:1px solid #fed7aa;">
                    <div class="card-header" style="background:#fff7ed;">
                        <h3 style="color:#b45309;">
                            <span dir="ltr"><i class="fas fa-hourglass-half"></i> Âge de la retraite (64 ans)
                                <?php if ($empAge !== null): ?><span class="badge" style="background:<?= $empAge >= 64 ? '#b45309' : '#64748b' ?>;color:#fff">العمر: <?= (int)$empAge ?> سنة</span><?php endif; ?>
                                <?php if ($emp64Date): ?><span class="badge" style="background:#b45309;color:#fff">تاريخ بلوغ 64: <?= e(displayDMY($emp64Date)) ?></span><?php endif; ?>
                            </span>
                            <div style="font-size:0.85em;font-weight:600;opacity:0.9">بلوغ سنّ الـ64</div>
                        </h3>
                    </div>
                    <div class="card-body">
                        <label class="d-flex justify-between align-center mb-3">
                            <span><strong>الإبقاء على العمل بعد الـ64 — وقف محسومات التقاعد</strong> / Maintenu après 64 ans (arrêt des retenues de retraite)</span>
                            <label class="switch"><input type="checkbox" name="keep_working_past_64" value="1" <?= !empty($employee['keep_working_past_64']) ? 'checked' : '' ?>><span class="slider"></span></label>
                        </label>
                        <p style="font-size:12px;color:var(--gray-600);margin:0">
                            عند تفعيله لمن بلغ 64 سنة: تُوقَف تلقائياً — للأستاذ <strong>الملاك</strong>: صندوق التعويضات ٦٪ <strong>عنه وعن المدرسة</strong>؛ وللموظف: نهاية الخدمة ٨.٥٪ (المدرسة). يبدأ الإعفاء من <strong>شهر بلوغه 64</strong> فقط (الأشهر السابقة تبقى كما هي). إن كنت <strong>لا تريد إبقاءه</strong>، سجّل تاريخ الترك في أعلى البطاقة بدل ذلك.
                        </p>
                    </div>
                </div>

                <div class="form-group mt-4">
                    <label class="form-label">Notes / ملاحظات</label>
                    <textarea name="notes" class="form-control" rows="3"><?= e($employee['notes']) ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Save buttons -->
    <div class="card">
        <div class="card-body d-flex justify-between">
            <a href="<?= BASE_URL ?>pages/employees.php" class="btn btn-light">
                <i class="fas fa-times"></i> Annuler / إلغاء
            </a>
            <button type="submit" id="empSaveBtn" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i> Enregistrer / حفظ
            </button>
        </div>
    </div>
</form>

<?php if ($id > 0 && $employee['employee_type'] === 'enseignant_titulaire'): ?>
<!-- لائحة الدرجات: تظهر فقط تحت تبويب «🏆 الدرجات» (مخفية افتراضياً؛ يتحكّم بها gradesTabToggle أدناه) -->
<div class="card" id="gradesPanel" style="margin-top:18px;display:none">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <h3>
            <span dir="ltr"><i class="fas fa-layer-group"></i> Échelons de l'enseignant — chacun daté, décochez celui à ne pas calculer</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">درجات الأستاذ — كلها بتواريخها، شيل الصح عن أي وحدة ما بدّك تحسبها</div>
        </h3>
        <a href="<?= BASE_URL ?>pages/grades.php?employee_id=<?= $id ?>" class="btn btn-sm btn-light no-print">
            <i class="fas fa-up-right-from-square"></i> Page complète des échelons / صفحة الدرجات الكاملة (قوانين/تدرّج)
        </a>
    </div>
    <div class="card-body">
        <?php renderGradeChecklist($employee, 'employee'); ?>
    </div>
</div>
<script>
// إظهار لائحة الدرجات فقط عند فتح تبويب «الدرجات» (البانل خارج النموذج، فنتحكّم به يدوياً).
(function () {
    var gp = document.getElementById('gradesPanel');
    var tabsWrap = document.querySelector('.tabs');
    if (!gp || !tabsWrap) return;
    function sync(name) { gp.style.display = (name === 'grades') ? '' : 'none'; }
    tabsWrap.addEventListener('click', function (e) {
        var b = e.target.closest('.tab');
        if (b && b.getAttribute('data-tab')) sync(b.getAttribute('data-tab'));
    });
    var t = new URLSearchParams(location.search).get('tab');   // بعد الحفظ يرجع لنفس التبويب
    sync(t || 'personal');
})();
</script>
<?php endif; ?>

<?php
// ملاحظة: قفل ملف الأستاذ صار عبر السكربت المشترك assets/js/form-lock.js
// (class="lockedit" على الفورم + زر «تعديل» في الشريط العلوي عبر data-lockedit-for).
?>

<?php
// خريطة السلسلة (درجة → راتب) للإصدار الساري — لعرض الراتب فوراً عند اختيار الدرجة
$scaleMap = [];
foreach ($db->query("SELECT grade, new_salary_2017 FROM salary_scale_2017 WHERE version_id = " . (int)scaleVersionIdAsOf() . " ORDER BY grade")->fetchAll() as $sc) {
    $scaleMap[(int)$sc['grade']] = (float)$sc['new_salary_2017'];
}
?>
<script>
var SALARY_SCALE = <?= json_encode($scaleMap) ?>;
function scaleSalaryJS(grade) {
    grade = parseFloat(grade); if (isNaN(grade) || grade < 1) return null;
    var lo = Math.floor(grade), hi = Math.ceil(grade), frac = grade - lo;
    var vLo = SALARY_SCALE[lo], vHi = SALARY_SCALE[hi];
    if (vLo == null) return null;
    var v = (frac === 0 || vHi == null) ? vLo : vLo + frac * (vHi - vLo);
    return Math.round(v);
}
function fmtLBP(n) { return n == null ? '—' : n.toLocaleString('en-US') + ' L.L'; }
function refreshGradeSalaries() {
    document.querySelectorAll('.grade-salary').forEach(function (el) {
        var inp = document.querySelector('input[name="' + el.getAttribute('data-grade-for') + '"]');
        if (!inp) return;
        var s = scaleSalaryJS(inp.value);
        el.textContent = s == null ? '' : ('= ' + fmtLBP(s) + ' / شهرياً');
    });
    // عرض أساس الراتب حسب السلسلة (للملاك) انطلاقاً من الدرجة الحالية × النسبة
    var disp = document.getElementById('scaleSalaryDisplay');
    if (disp) {
        var cur = document.querySelector('input[name="current_grade"]');
        var pctEl = document.querySelector('input[name="base_salary_lbp_percent"]');
        var s = cur ? scaleSalaryJS(cur.value) : null;
        var pct = pctEl && parseFloat(pctEl.value) > 0 ? parseFloat(pctEl.value) / 100 : 1;
        disp.value = s == null ? '—' : fmtLBP(Math.round(s * pct));
    }
}
(function () {
    var dip = document.querySelector('select[name="diploma"]');
    var startEl = document.querySelector('input[name="starting_grade"]');
    var curEl = document.querySelector('input[name="current_grade"]');
    if (startEl) startEl.addEventListener('input', refreshGradeSalaries);
    if (curEl) curEl.addEventListener('input', refreshGradeSalaries);
    var pctEl = document.querySelector('input[name="base_salary_lbp_percent"]');
    if (pctEl) pctEl.addEventListener('input', refreshGradeSalaries);
    if (dip && startEl) {
        dip.addEventListener('change', function () {
            var opt = dip.options[dip.selectedIndex];
            var g = parseFloat(opt.getAttribute('data-grade'));
            var immediate = opt.getAttribute('data-immediate') === '1';
            if (!isNaN(g)) {
                startEl.value = g;
                if (curEl && (curEl.value === '' || parseFloat(curEl.value) <= g)) {
                    curEl.value = immediate ? (g + 1) : g;
                }
            }
            refreshGradeSalaries();
        });
    }
    refreshGradeSalaries();
})();

// =====================================================
// إصلاح «الزرّ الميّت»: إذا حقل إلزامي (required) فاضٍ داخل تبويب مخفي،
// المتصفح يفشل بصمت ولا يحفظ. هون منفتح تبويب الحقل الناقص تلقائياً
// ونبيّن الرسالة، حتى ما يضل زرّ الحفظ كأنو لا يعمل شيئاً.
// =====================================================
(function () {
    var form = document.querySelector('form[enctype="multipart/form-data"]');
    if (!form) return;
    var handled = false;
    function activateTab(name) {
        var tabsWrap = document.querySelector('.tabs');
        if (tabsWrap) {
            tabsWrap.querySelectorAll('.tab').forEach(function (t) {
                t.classList.toggle('active', t.getAttribute('data-tab') === name);
            });
        }
        document.querySelectorAll('.tab-content').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-tab-content') === name);
        });
    }
    // حدث invalid لا ينتشر (does not bubble) لذلك نلتقطه في طور الالتقاط (capture).
    // نتعامل مع أول حقل غير صالح فقط (المتصفح يركّز عليه)، ونعيد التصفير بعد الدورة الحالية.
    form.addEventListener('invalid', function (e) {
        if (handled) return;
        handled = true;
        setTimeout(function () { handled = false; }, 0);
        var pane = e.target.closest('.tab-content[data-tab-content]');
        if (pane) activateTab(pane.getAttribute('data-tab-content'));
    }, true);
})();
</script>

<script>
/* ترجمة تلقائية للأسماء العربية إلى الفرنسي أثناء الكتابة (يملأ حقل الفرنسي إن كان فارغاً) */
(function () {
    var pairs = [
        { ar: 'first_name_ar',  fr: 'first_name_fr',  type: 'first' },
        { ar: 'last_name_ar',   fr: 'last_name_fr',   type: 'last'  },
        { ar: 'father_name_ar', fr: 'father_name_fr', type: 'first' }
    ];
    var base = '<?= BASE_URL ?>pages/employees.php';
    pairs.forEach(function (p) {
        var arEl = document.querySelector('[name="' + p.ar + '"]');
        var frEl = document.querySelector('[name="' + p.fr + '"]');
        if (!arEl || !frEl) return;
        var fill = function (force) {
            var ar = arEl.value.trim();
            if (!ar) return;
            // لا نكتب فوق فرنسي موجود إلا إذا طُلب ذلك صراحةً
            if (frEl.value.trim() !== '' && !force) return;
            fetch(base + '?action=translit&type=' + p.type + '&text=' + encodeURIComponent(ar))
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.fr && (frEl.value.trim() === '' || force)) frEl.value = d.fr; })
                .catch(function () {});
        };
        arEl.addEventListener('blur', function () { fill(false); });
        // زر صغير لإعادة الترجمة فوق الفرنسي الموجود
        frEl.addEventListener('dblclick', function () { fill(true); });
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
