<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php'; // لإعادة الحساب عند تغيير سعر الصرف الافتراضي
requireLogin();
requireCsrf();

$currentPage = 'settings';
$pageTitle = 'Paramètres';
$db = getDB();
$message = ''; $messageType = 'success';

// ===== تغيير كلمة المرور للمستخدم الحالي =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    $err = '';
    if (!$row) {
        $err = 'Utilisateur introuvable / المستخدم غير موجود';
    } elseif (!password_verify($current, $row['password_hash'])) {
        $err = 'Mot de passe actuel incorrect / كلمة المرور الحالية غير صحيحة';
    } elseif (strlen($new) < 6) {
        $err = 'Le nouveau mot de passe doit comporter au moins 6 caractères / كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل';
    } elseif ($new !== $confirm) {
        $err = 'La confirmation ne correspond pas / تأكيد كلمة المرور غير مطابق';
    }

    if ($err) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $err];
    } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $uid]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Mot de passe modifié avec succès / تم تغيير كلمة المرور بنجاح'];
    }
    header('Location: ' . BASE_URL . 'pages/settings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldRate = getSetting('current_exchange_rate'); // لرصد تغيّر سعر الصرف الافتراضي
    foreach ($_POST as $k => $v) {
        setSetting($k, trim((string)$v));
    }
    // سعر الصرف الافتراضي fallback أخير لرواتب بلا سعر شهري — أعِد الحساب تلقائياً إن تغيّر
    $msg = 'Paramètres enregistrés / تم حفظ الإعدادات';
    if (isset($_POST['current_exchange_rate']) && (float)$oldRate !== (float)$_POST['current_exchange_rate']) {
        $nRec = recalcSalariesInRange($db, '2017-08-01', null);
        $msg = "تم الحفظ وإعادة حساب الرواتب المتأثّرة بسعر الصرف ($nRec). / Paramètres enregistrés, salaires recalculés.";
    }
    $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
    header('Location: ' . BASE_URL . 'pages/settings.php');
    exit;
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div><?php endif; ?>

<?php if (canEdit()): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-school"></i> Informations des écoles</h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Le nom, l'adresse et les infos de chaque école se gèrent désormais dans la page
            <a href="<?= BASE_URL ?>pages/schools.php"><strong>Écoles / المدارس</strong></a>
            (chaque école a ses propres données).
        </div>
    </div>
</div>

<form method="POST" class="lockedit">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-calendar"></i> Paramètres généraux</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Année scolaire actuelle / السنة الدراسية</label>
                <input type="text" name="current_school_year" class="form-control" value="<?= e(getSetting('current_school_year')) ?>" placeholder="2025-2026">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-coins"></i> Taux de change actuel / سعر الصرف الحالي</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Taux de change actuel (par défaut)</label>
                <input type="number" name="current_exchange_rate" class="form-control" value="<?= e(getSetting('current_exchange_rate')) ?>" step="0.01">
                <small class="text-muted"><?= $lang==='ar'?'الأسعار الشهرية المفصّلة في صفحة «Taux de change».':'Les taux mensuels détaillés sont dans la page « Taux de change ».' ?></small>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body d-flex justify-end">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i> Enregistrer / حفظ
            </button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-percent"></i> Taux & Pourcentages (lois) / النِّسَب والقيم القانونية</h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <?= $lang === 'ar'
                ? 'النِّسَب والقيم التي تتغيّر من سنة لسنة (الضمان، الصندوق، نهاية الخدمة، الحد الأدنى للأجور) تُدار الآن مع تواريخها في صفحة '
                : 'Les taux et valeurs qui changent d\'année en année (CNSS, EOC, fin de service, salaire minimum) se gèrent désormais avec leurs dates dans la page ' ?>
            <a href="<?= BASE_URL ?>pages/rates_history.php"><strong>Taux datés / النِّسَب حسب التاريخ</strong></a>،
            <?= $lang === 'ar' ? 'والحدود الدنيا/القصوى في ' : 'et les plafonds/planchers dans ' ?>
            <a href="<?= BASE_URL ?>pages/social_security.php"><strong>Plafonds CNSS / حدود الضمان</strong></a>.
        </div>
        <table class="table">
            <thead><tr><th><?= $lang==='ar'?'القيمة':'Valeur' ?></th><th><?= $lang==='ar'?'الساري حالياً':'Actuel' ?></th></tr></thead>
            <tbody>
            <?php foreach (ratedParams() as $key => $info):
                $cur = getRateAsOf($key, (int)date('n'), (int)date('Y'));
                $disp = ($info['unit'] === 'lbp') ? formatLBP($cur) : (rtrim(rtrim(number_format($cur,4,'.',''),'0'),'.').' %'); ?>
                <tr><td><?= e($info[$lang==='ar'?'ar':'fr']) ?></td><td><strong><?= $disp ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; /* canEdit — نهاية البطاقات القابلة للتعديل */ ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-key"></i> Mot de passe / كلمة المرور</h3></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:16px;">
            <?= $lang==='ar'
                ? 'تغيير كلمة المرور للمستخدم الحالي: '.e($_SESSION['username'] ?? '')
                : 'Changer le mot de passe de l\'utilisateur actuel : '.e($_SESSION['username'] ?? '') ?>
        </p>
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label class="form-label">Mot de passe actuel / كلمة المرور الحالية</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label class="form-label">Nouveau mot de passe / كلمة المرور الجديدة</label>
                <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                <small class="text-muted"><?= $lang==='ar'?'6 أحرف على الأقل.':'6 caractères minimum.' ?></small>
            </div>
            <div class="form-group">
                <label class="form-label">Confirmer le nouveau mot de passe / تأكيد كلمة المرور الجديدة</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
            <div class="d-flex justify-end">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-key"></i> <?= $lang==='ar'?'تغيير كلمة المرور':'Changer le mot de passe' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
