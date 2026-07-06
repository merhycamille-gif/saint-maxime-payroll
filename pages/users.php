<?php
/**
 * إدارة حسابات المستخدمين — Gestion des utilisateurs
 * للمدير فقط (admin / superadmin).
 * الاستعمال الأساسي: إنشاء حساب لكل مدرسة (دور «قراءة فقط» = viewer) مربوط بمدرستها،
 * مع إمكانية إيقاف/تفعيل دخول أي حساب في أي وقت (زر «إيقاف / تفعيل»).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isAdmin()) {
    $_SESSION['flash_error'] = 'Accès réservé à l\'administrateur / صلاحية المدير فقط';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$currentPage = 'users';
$pageTitle   = 'Utilisateurs / حسابات المدارس';
$db   = getDB();
ensureUsersPermsColumn(); // تركيب عمود allowed_pages ذاتياً إن لزم
$lang = $_SESSION['lang'] ?? 'fr';
$myId = (int)($_SESSION['user_id'] ?? 0);

// الأدوار المتاحة (viewer = حساب مدرسة قراءة فقط)
$ROLES = [
    'viewer'     => 'حساب مدرسة (قراءة فقط) / École (lecture seule)',
    'operator'   => 'مُشغّل (إدخال) / Opérateur',
    'admin'      => 'مدير / Administrateur',
    'superadmin' => 'مدير عام (كل المدارس) / Directeur général',
];

// عدد المدراء العامّين الفعّالين (لمنع حذف/إيقاف آخر واحد)
function activeSuperadminCount($db) {
    return (int)$db->query("SELECT COUNT(*) FROM users WHERE role='superadmin' AND is_active=1")->fetchColumn();
}

// ===== إضافة / تعديل =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée / انتهت الجلسة';
        header('Location: ' . BASE_URL . 'pages/users.php'); exit;
    }
    $id       = (int)($_POST['id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? 'viewer';
    $schoolId = (int)($_POST['school_id'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (!array_key_exists($role, $ROLES)) $role = 'viewer';
    // المدير العام يرى كل المدارس ⇒ بلا مدرسة؛ غيره لازم مدرسة محددة
    $schoolIdDb = ($role === 'superadmin') ? null : ($schoolId > 0 ? $schoolId : null);

    // الصفحات/التقارير المسموحة (فقط لحساب المدرسة viewer) — من الـcheckboxes
    $allowedPages = null; // null = لا يخصّ هذا الدور (يرى كل شيء أصلاً)
    if ($role === 'viewer') {
        $sel = $_POST['allowed'] ?? [];
        if (!is_array($sel)) $sel = [];
        $valid = array_keys(viewerReportPages());
        $sel = array_values(array_intersect($sel, $valid));
        $allowedPages = implode(',', $sel); // '' = لا شيء مختار (لوحة القيادة فقط)
    }

    $err = '';
    if ($fullName === '' || $username === '') {
        $err = 'الاسم واسم الدخول مطلوبان / Nom et identifiant requis';
    } elseif ($role !== 'superadmin' && !$schoolIdDb) {
        $err = 'يجب اختيار المدرسة لهذا الحساب / Veuillez choisir l\'école';
    } elseif ($id === 0 && $password === '') {
        $err = 'كلمة المرور مطلوبة للحساب الجديد / Mot de passe requis';
    } elseif ($password !== '' && strlen($password) < 6) {
        $err = 'كلمة المرور 6 أحرف على الأقل / Mot de passe : 6 caractères minimum';
    } else {
        // اسم الدخول فريد
        $chk = $db->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $chk->execute([$username, $id]);
        if ($chk->fetch()) {
            $err = 'اسم الدخول مستخدم مسبقاً / Identifiant déjà utilisé';
        }
    }

    if ($err) {
        $_SESSION['flash_error'] = $err;
        header('Location: ' . BASE_URL . 'pages/users.php' . ($id ? '?edit=' . $id : '') . '#user-form'); exit;
    }

    if ($id > 0) {
        // منع أن يُلغي المستخدم تفعيل نفسه أو يخفّض آخر مدير عام
        $cur = $db->prepare("SELECT * FROM users WHERE id = ?"); $cur->execute([$id]); $cur = $cur->fetch();
        if ($cur) {
            if ($id === $myId) { $isActive = 1; } // لا توقف نفسك
            if ($cur['role'] === 'superadmin' && $role !== 'superadmin' && activeSuperadminCount($db) <= 1) {
                $_SESSION['flash_error'] = 'لا يمكن تغيير دور آخر مدير عام / Impossible : dernier directeur';
                header('Location: ' . BASE_URL . 'pages/users.php?edit=' . $id . '#user-form'); exit;
            }
        }
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET full_name=?, username=?, password_hash=?, role=?, school_id=?, is_active=?, allowed_pages=? WHERE id=?")
               ->execute([$fullName, $username, $hash, $role, $schoolIdDb, $isActive, $allowedPages, $id]);
        } else {
            $db->prepare("UPDATE users SET full_name=?, username=?, role=?, school_id=?, is_active=?, allowed_pages=? WHERE id=?")
               ->execute([$fullName, $username, $role, $schoolIdDb, $isActive, $allowedPages, $id]);
        }
        if (function_exists('logAudit')) logAudit('update', 'users', $id, null, $username);
        $_SESSION['flash_success'] = 'تم تحديث الحساب / Compte mis à jour';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username, password_hash, full_name, role, school_id, is_active, allowed_pages) VALUES (?,?,?,?,?,?,?)")
           ->execute([$username, $hash, $fullName, $role, $schoolIdDb, $isActive, $allowedPages]);
        $newId = (int)$db->lastInsertId();
        if (function_exists('logAudit')) logAudit('create', 'users', $newId, null, $username);
        $_SESSION['flash_success'] = 'تمت إضافة الحساب / Compte ajouté';
    }
    header('Location: ' . BASE_URL . 'pages/users.php'); exit;
}

// ===== إيقاف / تفعيل الدخول (زر «الأوك») =====
if (isset($_GET['toggle'])) {
    $tid = (int)$_GET['toggle'];
    if ($tid === $myId) {
        $_SESSION['flash_error'] = 'لا يمكنك إيقاف حسابك أنت / Vous ne pouvez pas désactiver votre compte';
    } else {
        $u = $db->prepare("SELECT * FROM users WHERE id = ?"); $u->execute([$tid]); $u = $u->fetch();
        if ($u) {
            $newState = $u['is_active'] ? 0 : 1;
            if ($newState === 0 && $u['role'] === 'superadmin' && activeSuperadminCount($db) <= 1) {
                $_SESSION['flash_error'] = 'لا يمكن إيقاف آخر مدير عام / Impossible : dernier directeur';
            } else {
                $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newState, $tid]);
                if (function_exists('logAudit')) logAudit($newState ? 'activate' : 'deactivate', 'users', $tid, null, $u['username']);
                $_SESSION['flash_success'] = $newState
                    ? 'تم تفعيل الدخول لهذا الحساب / Accès activé'
                    : 'تم إيقاف الدخول لهذا الحساب / Accès bloqué';
            }
        }
    }
    header('Location: ' . BASE_URL . 'pages/users.php'); exit;
}

// ===== حذف =====
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    if ($did === $myId) {
        $_SESSION['flash_error'] = 'لا يمكنك حذف حسابك أنت / Suppression de votre propre compte interdite';
    } else {
        $u = $db->prepare("SELECT * FROM users WHERE id = ?"); $u->execute([$did]); $u = $u->fetch();
        if ($u && $u['role'] === 'superadmin' && activeSuperadminCount($db) <= 1) {
            $_SESSION['flash_error'] = 'لا يمكن حذف آخر مدير عام / Impossible : dernier directeur';
        } else {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$did]);
            if (function_exists('logAudit')) logAudit('delete', 'users', $did, null, $u['username'] ?? '');
            $_SESSION['flash_success'] = 'تم حذف الحساب / Compte supprimé';
        }
    }
    header('Location: ' . BASE_URL . 'pages/users.php'); exit;
}

// ===== هدف التعديل =====
$editUser = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch() ?: null;
}

// ===== القوائم =====
$schools = allSchools(false); // كل المدارس (حتى غير الفعّالة) لربط الحساب
$users = $db->query("SELECT * FROM users ORDER BY (role='superadmin') DESC, role, full_name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-actions no-print">
    <a href="#user-form" class="btn btn-primary"><i class="fas fa-user-plus"></i> <?= $lang==='ar'?'حساب جديد':'Nouveau compte' ?></a>
</div>

<div class="alert alert-info no-print">
    <i class="fas fa-info-circle"></i>
    <?= $lang==='ar'
        ? 'لإنشاء حساب مدرسة: اختر الدور «حساب مدرسة (قراءة فقط)» واربطه بمدرستها. سيرى تقاريرها وإفاداتها فقط ولا يستطيع تعديل أي شيء. يمكنك <strong>إيقاف أو تفعيل</strong> دخول أي حساب متى شئت من زرّ الحالة.'
        : 'Pour un compte école : rôle « lecture seule » lié à son école. Il ne verra que ses rapports/attestations, sans rien modifier. Vous pouvez bloquer/activer son accès à tout moment.' ?>
</div>

<!-- Liste -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-users-cog"></i> <?= $lang==='ar'?'الحسابات':'Comptes' ?></h3></div>
    <div class="card-body">
        <table class="table table-hover">
            <thead><tr>
                <th><?= $lang==='ar'?'الاسم':'Nom' ?></th>
                <th><?= $lang==='ar'?'اسم الدخول':'Identifiant' ?></th>
                <th><?= $lang==='ar'?'الدور':'Rôle' ?></th>
                <th><?= $lang==='ar'?'المدرسة':'École' ?></th>
                <th><?= $lang==='ar'?'الحالة':'État' ?></th>
                <th><?= $lang==='ar'?'آخر دخول':'Dernière connexion' ?></th>
                <th class="no-print"><?= $lang==='ar'?'إجراءات':'Actions' ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): $isMe = ((int)$u['id'] === $myId); ?>
                <tr>
                    <td><strong><?= e($u['full_name']) ?></strong><?= $isMe ? ' <span class="badge badge-info">'.($lang==='ar'?'أنت':'vous').'</span>' : '' ?></td>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td><?= e($ROLES[$u['role']] ?? $u['role']) ?></td>
                    <td><?= $u['school_id'] ? e(schoolNameById((int)$u['school_id'])) : '<span class="text-muted">'.($lang==='ar'?'الكل':'Toutes').'</span>' ?></td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> <?= $lang==='ar'?'مفعّل':'Actif' ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-ban"></i> <?= $lang==='ar'?'موقوف':'Bloqué' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $u['last_login'] ? e($u['last_login']) : '—' ?></td>
                    <td class="no-print">
                        <a href="?edit=<?= $u['id'] ?>#user-form" class="btn btn-sm btn-light" title="<?= $lang==='ar'?'تعديل':'Modifier' ?>"><i class="fas fa-edit"></i></a>
                        <?php if (!$isMe): ?>
                            <?php if ($u['is_active']): ?>
                                <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-warning" data-confirm="<?= $lang==='ar'?'إيقاف دخول هذا الحساب؟':'Bloquer l\'accès de ce compte ?' ?>" title="<?= $lang==='ar'?'إيقاف الدخول':'Bloquer' ?>"><i class="fas fa-ban"></i> <?= $lang==='ar'?'إيقاف':'Bloquer' ?></a>
                            <?php else: ?>
                                <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-success" data-confirm="<?= $lang==='ar'?'تفعيل دخول هذا الحساب؟':'Activer l\'accès ?' ?>" title="<?= $lang==='ar'?'تفعيل':'Activer' ?>"><i class="fas fa-check"></i> <?= $lang==='ar'?'تفعيل':'Activer' ?></a>
                            <?php endif; ?>
                            <a href="?delete=<?= $u['id'] ?>" class="btn btn-sm btn-danger" data-confirm="<?= $lang==='ar'?'حذف هذا الحساب نهائياً؟':'Supprimer ce compte ?' ?>" title="<?= $lang==='ar'?'حذف':'Supprimer' ?>"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Formulaire -->
<div class="card no-print" id="user-form">
    <div class="card-header">
        <h3><i class="fas fa-<?= $editUser ? 'user-edit' : 'user-plus' ?>"></i>
            <?= $editUser ? ($lang==='ar'?'تعديل الحساب':'Modifier le compte') : ($lang==='ar'?'حساب جديد':'Nouveau compte') ?></h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" value="<?= $editUser['id'] ?? 0 ?>">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label"><?= $lang==='ar'?'الاسم الكامل':'Nom complet' ?> *</label>
                    <input type="text" name="full_name" class="form-control" required value="<?= e($editUser['full_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang==='ar'?'اسم الدخول':'Identifiant' ?> *</label>
                    <input type="text" name="username" class="form-control" required autocomplete="off" value="<?= e($editUser['username'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label"><?= $lang==='ar'?'الدور':'Rôle' ?> *</label>
                    <select name="role" class="form-control" onchange="document.getElementById('schoolRow').style.display=(this.value==='superadmin')?'none':'';document.getElementById('permsRow').style.display=(this.value==='viewer')?'':'none';">
                        <?php foreach ($ROLES as $rk => $rlabel): ?>
                            <option value="<?= $rk ?>" <?= (($editUser['role'] ?? 'viewer') === $rk) ? 'selected' : '' ?>><?= e($rlabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="schoolRow" style="<?= (($editUser['role'] ?? 'viewer') === 'superadmin') ? 'display:none' : '' ?>">
                    <label class="form-label"><?= $lang==='ar'?'المدرسة':'École' ?></label>
                    <select name="school_id" class="form-control">
                        <option value="0">— <?= $lang==='ar'?'اختر مدرسة':'Choisir une école' ?> —</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (($editUser['school_id'] ?? 0) == $s['id']) ? 'selected' : '' ?>>
                                <?= e($lang==='ar' ? $s['name_ar'] : $s['name_fr']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            // ما الذي تراه هذه المدرسة؟ (checklist) — allowed_pages: null=الكل (افتراضي/قديم)، ''=لا شيء
            $curRole    = $editUser['role'] ?? 'viewer';
            $rawAllowed = $editUser ? ($editUser['allowed_pages'] ?? null) : null;
            $checkedPages = ($rawAllowed === null)
                ? array_keys(viewerReportPages())
                : array_filter(array_map('trim', explode(',', (string)$rawAllowed)));
            ?>
            <div class="form-group" id="permsRow" style="<?= $curRole === 'viewer' ? '' : 'display:none' ?>">
                <label class="form-label"><i class="fas fa-tasks"></i>
                    <?= $lang==='ar'?'ماذا ترى هذه المدرسة؟ (ضع ✓ على ما تريدها أن تراه)':'Que voit cette école ? (cochez ce qu\'elle peut voir)' ?></label>
                <div style="display:flex;flex-wrap:wrap;gap:16px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
                    <?php foreach (viewerReportPages() as $pk => $plabel): ?>
                    <label class="form-check" style="margin:0;white-space:nowrap">
                        <input type="checkbox" name="allowed[]" value="<?= e($pk) ?>" <?= in_array($pk, $checkedPages, true) ? 'checked' : '' ?>>
                        <?= e($lang==='ar' ? $plabel['ar'] : $plabel['fr']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted"><?= $lang==='ar'
                    ? 'لوحة القيادة وتغيير كلمة المرور والطباعة (PDF/واتساب) متاحة دائماً. غير المؤشَّر لا يظهر بالقائمة ولا يُفتح.'
                    : 'Tableau de bord, mot de passe et impression toujours disponibles. Le non coché est masqué et bloqué.' ?></small>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label"><?= $lang==='ar'?'كلمة المرور':'Mot de passe' ?> <?= $editUser ? '' : '*' ?></label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" minlength="6"
                           <?= $editUser ? '' : 'required' ?>
                           placeholder="<?= $editUser ? ($lang==='ar'?'اتركها فارغة لعدم التغيير':'Laisser vide pour ne pas changer') : '' ?>">
                    <small class="text-muted"><?= $lang==='ar'?'6 أحرف على الأقل.':'6 caractères minimum.' ?></small>
                </div>
                <div class="form-group" style="align-self:end">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" value="1" <?= (!$editUser || $editUser['is_active']) ? 'checked' : '' ?>>
                        <?= $lang==='ar'?'الحساب مفعّل (يسمح بالدخول)':'Compte actif (accès autorisé)' ?>
                    </label>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $lang==='ar'?'حفظ':'Enregistrer' ?></button>
                <?php if ($editUser): ?>
                <a href="<?= BASE_URL ?>pages/users.php" class="btn btn-light"><?= $lang==='ar'?'إلغاء':'Annuler' ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
