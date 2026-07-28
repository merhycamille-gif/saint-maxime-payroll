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

// كلمة مرور عشوائية سهلة القراءة (8 أحرف، بلا أحرف ملتبسة)
function randomReadablePassword() {
    $al = 'abcdefghjkmnpqrstuvwxyz23456789'; $p = '';
    for ($i = 0; $i < 8; $i++) $p .= $al[random_int(0, strlen($al) - 1)];
    return $p;
}

// ===== إضافة / تعديل =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée / انتهت الجلسة';
        header('Location: ' . BASE_URL . 'pages/users.php'); exit;
    }

    // ===== توليد حساب «قراءة فقط» لكل مدرسة + حساب الرئاسة العامة =====
    if (($_POST['form_action'] ?? '') === 'generate_all') {
        $created = [];
        $existing = array_map(fn($u) => $u['username'], $db->query("SELECT username FROM users")->fetchAll());
        $mkUser = function ($base) use (&$existing) {
            $base = preg_replace('/[^a-z0-9]/', '', strtolower($base ?: 'ecole'));
            if ($base === '') $base = 'ecole';
            $base = substr($base, 0, 18);
            $u = $base; $i = 1;
            while (in_array($u, $existing, true)) { $u = $base . $i; $i++; }
            $existing[] = $u; return $u;
        };
        $allPages = implode(',', array_keys(viewerReportPages()));
        // 1) حساب لكل مدرسة ليس لها حساب قراءة فقط مفرد
        foreach (allSchools(false) as $s) {
            $sid = (int)$s['id'];
            $ex = $db->prepare("SELECT id FROM users WHERE role='viewer' AND school_id = ?");
            $ex->execute([$sid]);
            if ($ex->fetch()) continue;
            $un = $mkUser('ecole' . ($s['code'] ?: $sid)); $pw = randomReadablePassword();
            $db->prepare("INSERT INTO users (username,password_hash,full_name,role,school_id,is_active,allowed_pages,allowed_schools) VALUES (?,?,?,?,?,1,?,?)")
               ->execute([$un, password_hash($pw, PASSWORD_DEFAULT), ($s['name_fr'] ?: $s['name_ar']), 'viewer', $sid, $allPages, (string)$sid]);
            $created[] = ['school' => ($s['name_fr'] ?: $s['name_ar']), 'username' => $un, 'password' => $pw];
        }
        // 2) حساب الرئاسة العامة (كل المدارس) إن لم يوجد
        if (!$db->query("SELECT id FROM users WHERE role='viewer' AND allowed_schools='all'")->fetch()) {
            $un = $mkUser('presidence'); $pw = randomReadablePassword();
            $db->prepare("INSERT INTO users (username,password_hash,full_name,role,school_id,is_active,allowed_pages,allowed_schools) VALUES (?,?,?,?,?,1,?,?)")
               ->execute([$un, password_hash($pw, PASSWORD_DEFAULT), 'Présidence Générale / الرئاسة العامة', 'viewer', null, $allPages, 'all']);
            $created[] = ['school' => '🏛️ الرئاسة العامة — كل المدارس', 'username' => $un, 'password' => $pw];
        }
        if (function_exists('logAudit')) logAudit('generate', 'users', 0, null, count($created) . ' comptes');
        $_SESSION['generated_creds'] = $created;
        $_SESSION['flash_success'] = count($created) > 0
            ? (count($created) . ' حساب أُنشئ. احفظ/اطبع كلمات المرور الآن — لن تظهر مرة ثانية. / Comptes créés, notez les mots de passe.')
            : 'كل الحسابات موجودة مسبقاً — لا جديد. / Tous les comptes existent déjà.';
        header('Location: ' . BASE_URL . 'pages/users.php'); exit;
    }

    // ===== إعادة تعيين كلمات المرور لكل حسابات المدارس وعرضها (عند فقدانها) =====
    if (($_POST['form_action'] ?? '') === 'reset_passwords') {
        $out = [];
        $viewers = $db->query("SELECT id, username, full_name, school_id, allowed_schools FROM users WHERE role='viewer' ORDER BY (allowed_schools='all') DESC, school_id")->fetchAll();
        foreach ($viewers as $v) {
            $pw = randomReadablePassword();
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$v['id']]);
            $label = ($v['allowed_schools'] === 'all')
                ? '🏛️ ' . ($lang==='ar'?'الرئاسة العامة':'Présidence')
                : ($v['school_id'] ? schoolNameById((int)$v['school_id']) : $v['full_name']);
            $out[] = ['school' => $label, 'username' => $v['username'], 'password' => $pw];
        }
        if (function_exists('logAudit')) logAudit('reset_pw', 'users', 0, null, count($out) . ' comptes');
        $_SESSION['generated_creds'] = $out;
        $_SESSION['flash_success'] = $out
            ? (count($out) . ' كلمة مرور جديدة. اطبعها الآن — لن تظهر مرة ثانية. / Nouveaux mots de passe.')
            : 'لا توجد حسابات مدارس بعد. / Aucun compte école.';
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

    $err = '';
    $allowedPages = null;   // التقارير المسموحة (viewer فقط)
    $allowedSchools = null; // المدارس المسموحة (viewer فقط): 'all' أو '2,5' أو مدرسة مفردة
    $schoolIdDb = null;

    if ($role === 'superadmin') {
        // المدير العام يرى كل المدارس ⇒ بلا قيود
        $schoolIdDb = null;
    } elseif ($role === 'viewer') {
        // التقارير المسموحة
        $selP = $_POST['allowed'] ?? []; if (!is_array($selP)) $selP = [];
        $selP = array_values(array_intersect($selP, array_keys(viewerReportPages())));
        $allowedPages = implode(',', $selP); // '' = لا شيء (لوحة القيادة فقط)
        // نطاق المدارس: «كل المدارس» أو قائمة مختارة
        $validIds = array_map(fn($s) => (int)$s['id'], allSchools(false));
        if (isset($_POST['schools_all'])) {
            $allowedSchools = 'all'; $schoolIdDb = null;
        } else {
            $vs = $_POST['vschools'] ?? []; if (!is_array($vs)) $vs = [];
            $vs = array_values(array_unique(array_filter(array_map('intval', $vs), fn($x) => $x > 0 && in_array($x, $validIds, true))));
            if (empty($vs)) {
                $err = 'اختر مدرسة واحدة على الأقل أو «كل المدارس» / Choisissez au moins une école';
            } else {
                $allowedSchools = implode(',', $vs);
                $schoolIdDb = (count($vs) === 1) ? $vs[0] : null; // مدرسة مفردة تُخزَّن أيضاً بـschool_id
            }
        }
    } else { // operator / admin: مدرسة مفردة
        $schoolIdDb = $schoolId > 0 ? $schoolId : null;
        if (!$schoolIdDb) $err = 'يجب اختيار المدرسة لهذا الحساب / Veuillez choisir l\'école';
    }

    if ($err) {
        // يُعالَج بالأسفل
    } elseif ($fullName === '' || $username === '') {
        $err = 'الاسم واسم الدخول مطلوبان / Nom et identifiant requis';
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
            $db->prepare("UPDATE users SET full_name=?, username=?, password_hash=?, role=?, school_id=?, is_active=?, allowed_pages=?, allowed_schools=? WHERE id=?")
               ->execute([$fullName, $username, $hash, $role, $schoolIdDb, $isActive, $allowedPages, $allowedSchools, $id]);
        } else {
            $db->prepare("UPDATE users SET full_name=?, username=?, role=?, school_id=?, is_active=?, allowed_pages=?, allowed_schools=? WHERE id=?")
               ->execute([$fullName, $username, $role, $schoolIdDb, $isActive, $allowedPages, $allowedSchools, $id]);
        }
        if (function_exists('logAudit')) logAudit('update', 'users', $id, null, $username);
        $_SESSION['flash_success'] = 'تم تحديث الحساب / Compte mis à jour';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username, password_hash, full_name, role, school_id, is_active, allowed_pages, allowed_schools) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$username, $hash, $fullName, $role, $schoolIdDb, $isActive, $allowedPages, $allowedSchools]);
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
    <a href="#user-form" class="btn btn-primary"><i class="fas fa-user-plus"></i> Nouveau compte / حساب جديد</a>
    <form method="POST" style="display:inline" data-confirm="Créer un compte lecture seule par école + présidence ? / إنشاء حساب «قراءة فقط» لكل مدرسة + حساب الرئاسة العامة؟">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="form_action" value="generate_all">
        <button type="submit" class="btn btn-success"><i class="fas fa-magic"></i> Générer les comptes écoles / توليد حساب لكل المدارس</button>
    </form>
    <form method="POST" style="display:inline" data-confirm="Réinitialiser tous les mots de passe des écoles ? / ⚠️ تعيين كلمات مرور جديدة لكل حسابات المدارس؟">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="form_action" value="reset_passwords">
        <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Réinitialiser les mots de passe / إعادة تعيين كلمات المرور وطباعتها</button>
    </form>
</div>

<?php if (!empty($_SESSION['generated_creds'])): $gc = $_SESSION['generated_creds']; unset($_SESSION['generated_creds']); ?>
<div class="card" style="border:2px solid #16a34a">
    <div class="card-header" style="background:#f0fdf4"><h3 style="color:#15803d"><i class="fas fa-key"></i>
        <span dir="ltr">Nouveaux mots de passe — notez-les maintenant</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">كلمات المرور الجديدة — احفظها/اطبعها الآن (لن تظهر مرة ثانية)</div></h3></div>
    <div class="card-body">
        <div class="page-actions no-print">
            <button onclick="dlCreds()" class="btn btn-success btn-lg"><i class="fas fa-file-excel"></i> Télécharger (Excel) / تنزيل كملف Excel</button>
            <button onclick="window.print()" class="btn btn-light"><i class="fas fa-print"></i> Imprimer / طباعة</button>
        </div>
        <table class="table" id="credsTable">
            <thead><tr>
                <th>École / المدرسة</th>
                <th>Identifiant / اسم الدخول</th>
                <th>Mot de passe / كلمة المرور</th>
            </tr></thead>
            <tbody>
            <?php foreach ($gc as $c): ?>
                <tr>
                    <td><?= e($c['school']) ?></td>
                    <td><code style="font-size:15px"><?= e($c['username']) ?></code></td>
                    <td><code style="font-size:15px;font-weight:700"><?= e($c['password']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        function dlCreds(){
            var html='<html><head><meta charset="utf-8"></head><body><table border="1">'
                + document.getElementById('credsTable').innerHTML + '</table></body></html>';
            var blob=new Blob(['﻿'+html],{type:'application/vnd.ms-excel'});
            var a=document.createElement('a');
            a.href=URL.createObjectURL(blob);
            a.download='mots_de_passe_ecoles_<?= date('Y-m-d') ?>.xls';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        }
        </script>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info no-print">
    <i class="fas fa-info-circle"></i>
    <?= $lang==='ar'
        ? 'لإنشاء حساب مدرسة: اختر الدور «حساب مدرسة (قراءة فقط)» واربطه بمدرستها. سيرى تقاريرها وإفاداتها فقط ولا يستطيع تعديل أي شيء. يمكنك <strong>إيقاف أو تفعيل</strong> دخول أي حساب متى شئت من زرّ الحالة.'
        : 'Pour un compte école : rôle « lecture seule » lié à son école. Il ne verra que ses rapports/attestations, sans rien modifier. Vous pouvez bloquer/activer son accès à tout moment.' ?>
</div>

<!-- Liste -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-users-cog"></i> <span dir="ltr">Comptes</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">الحسابات</div></h3></div>
    <div class="card-body">
        <table class="table table-hover">
            <thead><tr>
                <th>Nom / الاسم</th>
                <th>Identifiant / اسم الدخول</th>
                <th>Rôle / الدور</th>
                <th>École / المدرسة</th>
                <th>État / الحالة</th>
                <th>Dernière connexion / آخر دخول</th>
                <th class="no-print">Actions / إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): $isMe = ((int)$u['id'] === $myId); ?>
                <tr>
                    <td><strong><?= e($u['full_name']) ?></strong><?= $isMe ? ' <span class="badge badge-info">vous / أنت</span>' : '' ?></td>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td><?= e($ROLES[$u['role']] ?? $u['role']) ?></td>
                    <td><?php
                        $as = $u['allowed_schools'] ?? null;
                        if ($u['role'] === 'superadmin') {
                            echo '<span class="text-muted">Toutes / كل المدارس</span>';
                        } elseif ($as === 'all') {
                            echo '<strong>🏛️ Toutes / كل المدارس</strong>';
                        } elseif ($as !== null && $as !== '' && strpos($as, ',') !== false) {
                            $cnt = count(array_filter(explode(',', $as)));
                            echo '<strong>'.$cnt.'</strong> écoles / مدارس';
                        } elseif ($u['school_id']) {
                            echo e(schoolNameById((int)$u['school_id']));
                        } elseif ($as !== null && $as !== '') {
                            echo e(schoolNameById((int)$as));
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                    ?></td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> Actif / مفعّل</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-ban"></i> Bloqué / موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $u['last_login'] ? e($u['last_login']) : '—' ?></td>
                    <td class="no-print">
                        <a href="?edit=<?= $u['id'] ?>#user-form" class="btn btn-sm btn-light" title="Modifier / تعديل"><i class="fas fa-edit"></i></a>
                        <?php if (!$isMe): ?>
                            <?php if ($u['is_active']): ?>
                                <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-warning" data-confirm="Bloquer l'accès de ce compte ? / إيقاف دخول هذا الحساب؟" title="Bloquer / إيقاف الدخول"><i class="fas fa-ban"></i> Bloquer / إيقاف</a>
                            <?php else: ?>
                                <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-success" data-confirm="Activer l'accès ? / تفعيل دخول هذا الحساب؟" title="Activer / تفعيل"><i class="fas fa-check"></i> Activer / تفعيل</a>
                            <?php endif; ?>
                            <a href="?delete=<?= $u['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer ce compte ? / حذف هذا الحساب نهائياً؟" title="Supprimer / حذف"><i class="fas fa-trash"></i></a>
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
            <span dir="ltr"><?= $editUser ? 'Modifier le compte' : 'Nouveau compte' ?></span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= $editUser ? 'تعديل الحساب' : 'حساب جديد' ?></div></h3>
    </div>
    <div class="card-body">
        <form method="POST"<?= $editUser ? ' class="lockedit"' : '' ?>>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" value="<?= $editUser['id'] ?? 0 ?>">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom complet / الاسم الكامل *</label>
                    <input type="text" name="full_name" class="form-control" required value="<?= e($editUser['full_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Identifiant / اسم الدخول *</label>
                    <input type="text" name="username" class="form-control" required autocomplete="off" value="<?= e($editUser['username'] ?? '') ?>">
                </div>
            </div>
            <?php
            $curRole = $editUser['role'] ?? 'viewer';
            // نطاق مدارس الـviewer عند التعديل: 'all' أو قائمة ids (أو مدرسته المفردة للحسابات القديمة)
            $vsRaw = $editUser['allowed_schools'] ?? null;
            $vsAll = ($vsRaw === 'all');
            $vsIds = ($vsRaw && $vsRaw !== 'all') ? array_map('intval', array_filter(explode(',', $vsRaw))) : [];
            if (!$vsAll && empty($vsIds) && (int)($editUser['school_id'] ?? 0) > 0) $vsIds = [(int)$editUser['school_id']];
            $showSingle = in_array($curRole, ['operator','admin'], true);
            ?>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Rôle / الدور *</label>
                    <select name="role" class="form-control" onchange="var r=this.value;document.getElementById('singleSchoolRow').style.display=(r==='operator'||r==='admin')?'':'none';document.getElementById('viewerSchoolsRow').style.display=(r==='viewer')?'':'none';document.getElementById('permsRow').style.display=(r==='viewer')?'':'none';">
                        <?php foreach ($ROLES as $rk => $rlabel): ?>
                            <option value="<?= $rk ?>" <?= ($curRole === $rk) ? 'selected' : '' ?>><?= e($rlabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="singleSchoolRow" style="<?= $showSingle ? '' : 'display:none' ?>">
                    <label class="form-label">École / المدرسة</label>
                    <select name="school_id" class="form-control">
                        <option value="0">— Choisir une école / اختر مدرسة —</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (($editUser['school_id'] ?? 0) == $s['id']) ? 'selected' : '' ?>>
                                <?= e($lang==='ar' ? $s['name_ar'] : $s['name_fr']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- نطاق المدارس لحساب المدرسة (viewer): كل المدارس أو مدارس محددة -->
            <div class="form-group" id="viewerSchoolsRow" style="<?= $curRole === 'viewer' ? '' : 'display:none' ?>">
                <label class="form-label"><i class="fas fa-school"></i>
                    Quelles écoles voit ce compte ? / أي مدرسة/مدارس يرى هذا الحساب؟</label>
                <div style="padding:12px 14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
                    <label class="form-check" style="margin:0 0 8px;font-weight:700">
                        <input type="checkbox" name="schools_all" value="1" <?= $vsAll ? 'checked' : '' ?>
                               onclick="document.querySelectorAll('#vsList input').forEach(c=>{c.disabled=this.checked; if(this.checked)c.checked=false;});">
                        🏛️ Toutes les écoles / كل المدارس (الرئاسة العامة)
                    </label>
                    <div id="vsList" style="display:flex;flex-wrap:wrap;gap:14px;max-height:180px;overflow:auto">
                        <?php foreach ($schools as $s): ?>
                        <label class="form-check" style="margin:0;white-space:nowrap">
                            <input type="checkbox" name="vschools[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $vsIds, true) ? 'checked' : '' ?> <?= $vsAll ? 'disabled' : '' ?>>
                            <?= e($lang==='ar' ? $s['name_ar'] : $s['name_fr']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <small class="text-muted"><?= $lang==='ar'?'اختر «كل المدارس» للرئاسة العامة، أو أشِّر مدرسة/عدة مدارس محددة.':'« Toutes » pour la présidence, ou cochez des écoles précises.' ?></small>
            </div>

            <?php
            // ما الذي تراه هذه المدرسة؟ (checklist) — allowed_pages: null=الكل (افتراضي/قديم)، ''=لا شيء
            $rawAllowed = $editUser ? ($editUser['allowed_pages'] ?? null) : null;
            $checkedPages = ($rawAllowed === null)
                ? array_keys(viewerReportPages())
                : array_filter(array_map('trim', explode(',', (string)$rawAllowed)));
            ?>
            <div class="form-group" id="permsRow" style="<?= $curRole === 'viewer' ? '' : 'display:none' ?>">
                <label class="form-label"><i class="fas fa-tasks"></i>
                    Que voit cette école ? (cochez ce qu'elle peut voir) / ماذا ترى هذه المدرسة؟ (ضع ✓ على ما تريدها أن تراه)</label>
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
                    <label class="form-label">Mot de passe / كلمة المرور <?= $editUser ? '' : '*' ?></label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" minlength="6"
                           <?= $editUser ? '' : 'required' ?>
                           placeholder="<?= $editUser ? ($lang==='ar'?'اتركها فارغة لعدم التغيير':'Laisser vide pour ne pas changer') : '' ?>">
                    <small class="text-muted"><?= $lang==='ar'?'6 أحرف على الأقل.':'6 caractères minimum.' ?></small>
                </div>
                <div class="form-group" style="align-self:end">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" value="1" <?= (!$editUser || $editUser['is_active']) ? 'checked' : '' ?>>
                        Compte actif (accès autorisé) / الحساب مفعّل (يسمح بالدخول)
                    </label>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                <?php if ($editUser): ?>
                <a href="<?= BASE_URL ?>pages/users.php" class="btn btn-light">Annuler / إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
