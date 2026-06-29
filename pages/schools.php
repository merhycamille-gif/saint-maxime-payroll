<?php
/**
 * إدارة المدارس — Gestion des écoles
 * للمدير العام فقط (superadmin)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php';
requireLogin();

if (!isSuperAdmin()) {
    $_SESSION['flash_error'] = 'Accès réservé au directeur général / صلاحية المدير العام فقط';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$currentPage = 'schools';
$pageTitle = 'Écoles / المدارس';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';

// ===== Actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée';
        header('Location: ' . BASE_URL . 'pages/schools.php'); exit;
    }
    $id        = (int)($_POST['id'] ?? 0);
    // العنوان واسم المدير: إذا تُرك الحقل الأجنبي فارغاً يُترجَم تلقائياً من العربي
    $addrAr = trim($_POST['address'] ?? '');
    $dirAr  = trim($_POST['director_name'] ?? '');
    $addrFr = trim($_POST['address_fr'] ?? '');        if ($addrFr === '' && $addrAr !== '') $addrFr = arNameToFr($addrAr);
    $dirFr  = trim($_POST['director_name_fr'] ?? '');  if ($dirFr === '' && $dirAr !== '') $dirFr = arNameToFr($dirAr);
    $data = [
        trim($_POST['code'] ?? '') ?: null,
        trim($_POST['name_ar'] ?? ''),
        trim($_POST['name_fr'] ?? ''),
        $addrAr,
        $addrFr,
        trim($_POST['phone'] ?? ''),
        trim($_POST['email'] ?? ''),
        trim($_POST['nssf_employer_number'] ?? ''),
        trim($_POST['finance_number'] ?? ''),
        $dirAr,
        $dirFr,
        isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($data[1] === '' && $data[2] === '') {
        $_SESSION['flash_error'] = 'الاسم مطلوب / Le nom est requis';
    } elseif ($id > 0) {
        $sql = "UPDATE schools SET code=?, name_ar=?, name_fr=?, address=?, address_fr=?, phone=?, email=?,
                nssf_employer_number=?, finance_number=?, director_name=?, director_name_fr=?, is_active=? WHERE id=?";
        $stmt = $db->prepare($sql);
        $stmt->execute([...$data, $id]);
        logAudit('update', 'schools', $id, null, $data[2]);
        $savedId = $id;
        $_SESSION['flash_success'] = 'تم تحديث المدرسة / École mise à jour';
    } else {
        $sql = "INSERT INTO schools (code, name_ar, name_fr, address, address_fr, phone, email,
                nssf_employer_number, finance_number, director_name, director_name_fr, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($sql);
        $stmt->execute($data);
        $newId = $db->lastInsertId();
        logAudit('create', 'schools', $newId, null, $data[2]);
        $savedId = (int)$newId;
        $_SESSION['flash_success'] = 'تمت إضافة المدرسة / École ajoutée';
    }
    // حفظ المسؤولين الموقّعين (اسم/أجنبي/صفة/هاتف) كـ JSON بجدول الإعدادات لكل مدرسة
    if (!empty($savedId)) {
        $names = $_POST['sig_name'] ?? []; $namesFr = $_POST['sig_name_fr'] ?? [];
        $titles = $_POST['sig_title'] ?? []; $phones = $_POST['sig_phone'] ?? [];
        $sigs = [];
        for ($i = 0; $i < count($names); $i++) {
            $nm = trim((string)($names[$i] ?? ''));
            if ($nm === '') continue;
            $sigs[] = [
                'name'    => $nm,
                'name_fr' => trim((string)($namesFr[$i] ?? '')),
                'title'   => trim((string)($titles[$i] ?? '')),
                'phone'   => trim((string)($phones[$i] ?? '')),
            ];
        }
        setSetting('school_signatories_' . $savedId, $sigs ? json_encode($sigs, JSON_UNESCAPED_UNICODE) : '');
    }
    // بعد الحفظ ارجع إلى نفس المدرسة (لا قائمة المدارس) ليبقى المستخدم يرى كل ما حفظه
    header('Location: ' . BASE_URL . 'pages/schools.php' . (!empty($savedId) ? '?edit=' . $savedId . '#school-form' : '')); exit;
}

// Soft delete
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    // منع حذف المدرسة إذا فيها موظفين
    $count = $db->prepare("SELECT COUNT(*) FROM employees WHERE school_id = ? AND is_deleted = 0");
    $count->execute([$delId]);
    if ($count->fetchColumn() > 0) {
        $_SESSION['flash_error'] = 'لا يمكن حذف مدرسة فيها موظفون. انقل أو احذف الموظفين أولاً.';
    } else {
        $db->prepare("UPDATE schools SET is_deleted = 1, is_active = 0 WHERE id = ?")->execute([$delId]);
        logAudit('delete', 'schools', $delId);
        $_SESSION['flash_success'] = 'تم حذف المدرسة / École supprimée';
    }
    header('Location: ' . BASE_URL . 'pages/schools.php'); exit;
}

// Edit target
$editSchool = null;
$sigList = [];
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ? AND is_deleted = 0");
    $stmt->execute([(int)$_GET['edit']]);
    $editSchool = $stmt->fetch();
    if ($editSchool) {
        $raw = getSetting('school_signatories_' . $editSchool['id'], '');
        $dec = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($dec)) $sigList = $dec;
        // إن لم تُعرّف لائحة بعد، اعرض المدير الحالي كصفّ أوّل ليبدأ منه المستخدم
        elseif (trim((string)($editSchool['director_name'] ?? '')) !== '') {
            $sigList[] = ['name' => $editSchool['director_name'], 'name_fr' => $editSchool['director_name_fr'] ?? '', 'title' => 'المدير', 'phone' => $editSchool['phone'] ?? ''];
        }
    }
}
while (count($sigList) < 3) $sigList[] = ['name' => '', 'name_fr' => '', 'title' => '', 'phone' => ''];

// List with employee counts
$schools = $db->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM employees e WHERE e.school_id = s.id AND e.is_deleted = 0) AS emp_count
    FROM schools s WHERE s.is_deleted = 0 ORDER BY s.name_fr
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-actions no-print">
    <button onclick="window.print()" class="btn btn-light"><i class="fas fa-print"></i> Imprimer</button>
    <a href="#school-form" class="btn btn-primary"><i class="fas fa-plus"></i> Nouvelle école</a>
</div>

<!-- Liste -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-school"></i> Liste des écoles / قائمة المدارس</h3></div>
    <div class="card-body">
        <table class="table table-hover">
            <thead><tr>
                <th>Code</th><th>Nom FR</th><th>الاسم</th><th>Tél</th>
                <th>Employés</th><th>État</th><th class="no-print">Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach ($schools as $s): ?>
                <tr>
                    <td><?= e($s['code']) ?></td>
                    <td><strong><?= e($s['name_fr']) ?></strong></td>
                    <td dir="rtl"><?= e($s['name_ar']) ?></td>
                    <td><?= e($s['phone']) ?></td>
                    <td><span class="badge badge-info"><?= $s['emp_count'] ?></span></td>
                    <td>
                        <?php if ($s['is_active']): ?>
                            <span class="badge badge-success">Actif</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <a href="?edit=<?= $s['id'] ?>#school-form" class="btn btn-sm btn-light" title="Modifier"><i class="fas fa-edit"></i></a>
                        <a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer cette école ? / حذف هذه المدرسة؟" title="Supprimer"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$schools): ?>
                <tr><td colspan="7" class="text-center text-muted">Aucune école — ajoutez la première ci-dessous</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Formulaire -->
<div class="card no-print" id="school-form">
    <div class="card-header">
        <h3><i class="fas fa-<?= $editSchool ? 'edit' : 'plus' ?>"></i>
            <?= $editSchool ? 'Modifier l\'école' : 'Nouvelle école' ?></h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" value="<?= $editSchool['id'] ?? 0 ?>">
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control" value="<?= e($editSchool['code'] ?? '') ?>" placeholder="ex: SM">
                </div>
                <div class="form-group">
                    <label class="form-label">Nom (Français) *</label>
                    <input type="text" name="name_fr" class="form-control" required value="<?= e($editSchool['name_fr'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">الاسم (عربي) *</label>
                    <input type="text" name="name_ar" class="form-control" dir="rtl" required value="<?= e($editSchool['name_ar'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">العنوان (عربي)</label>
                    <input type="text" name="address" class="form-control" dir="rtl" value="<?= e($editSchool['address'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Adresse (français) / العنوان بالأجنبي</label>
                    <input type="text" name="address_fr" class="form-control" dir="ltr" value="<?= e($editSchool['address_fr'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone / الهاتف</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($editSchool['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($editSchool['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">N° employeur CNSS / رقم الضمان</label>
                    <input type="text" name="nssf_employer_number" class="form-control" value="<?= e($editSchool['nssf_employer_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">N° Finances / رقم المالية</label>
                    <input type="text" name="finance_number" class="form-control" value="<?= e($editSchool['finance_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">المدير (عربي)</label>
                    <input type="text" name="director_name" class="form-control" dir="rtl" value="<?= e($editSchool['director_name'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">Directeur (français) / اسم المدير بالأجنبي</label>
                    <input type="text" name="director_name_fr" class="form-control" dir="ltr" value="<?= e($editSchool['director_name_fr'] ?? '') ?>">
                </div>
            </div>

            <!-- المسؤولون الموقّعون: تختار منهم عند طباعة/إرسال أي ملف -->
            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin:6px 0 14px">
                <legend style="font-weight:700;padding:0 8px;color:#1e3a8a"><i class="fas fa-user-tie"></i> المسؤولون الموقّعون / Responsables (اسم + هاتف — تختار منهم عند الطباعة والإرسال)</legend>
                <p class="text-muted" style="margin:0 0 10px;font-size:13px">يمكنك إدخال حتى <strong>٣ مسؤولين</strong> بأسمائهم وأرقام هواتفهم. عند طباعة أو إرسال أي إفادة، تختار أيّهم يظهر اسمه وهاتفه في التوقيع. (اتركها فارغة ليُستعمل اسم المدير أعلاه.)</p>
                <?php foreach ($sigList as $si => $sg): ?>
                <div class="form-row cols-4" style="align-items:end;margin-bottom:6px">
                    <div class="form-group">
                        <label class="form-label">الاسم <?= $si + 1 ?> (عربي)</label>
                        <input type="text" name="sig_name[]" class="form-control" dir="rtl" value="<?= e($sg['name'] ?? '') ?>" placeholder="<?= $si === 0 ? 'اسم المسؤول' : 'اختياري' ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom <?= $si + 1 ?> (français)</label>
                        <input type="text" name="sig_name_fr[]" class="form-control" dir="ltr" value="<?= e($sg['name_fr'] ?? '') ?>" placeholder="تلقائي إن تُرك فارغاً">
                    </div>
                    <div class="form-group">
                        <label class="form-label">الصفة / Titre</label>
                        <input type="text" name="sig_title[]" class="form-control" dir="rtl" value="<?= e($sg['title'] ?? '') ?>" placeholder="مثلاً: المدير / المنسّق">
                    </div>
                    <div class="form-group">
                        <label class="form-label">الهاتف / Téléphone</label>
                        <input type="text" name="sig_phone[]" class="form-control" dir="ltr" value="<?= e($sg['phone'] ?? '') ?>" placeholder="03/000000">
                    </div>
                </div>
                <?php endforeach; ?>
            </fieldset>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="is_active" value="1" <?= (!$editSchool || $editSchool['is_active']) ? 'checked' : '' ?>>
                    École active / مدرسة فعّالة
                </label>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                <?php if ($editSchool): ?>
                <a href="<?= BASE_URL ?>pages/schools.php" class="btn btn-light">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
