<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php'; // لإعادة الحساب عند تغيير سعر الصرف الافتراضي
requireLogin();
requireCsrf();

$currentPage = 'settings';
$pageTitle = 'Paramètres / الإعدادات';
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
    // 🔒 صلاحية: هذه إعدادات عامة تمسّ كل المدارس (سعر الصرف الافتراضي يعيد حساب الرواتب
    // من 2017 لكل المدارس) — للمدير فقط.
    if (!isAdmin()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'صلاحية المدير مطلوبة / Accès administrateur requis'];
        header('Location: ' . BASE_URL . 'pages/settings.php');
        exit;
    }
    // 🔴 قائمة بيضاء (كان `foreach ($_POST ...)` يكتب **أي** حقل مُرسَل كإعداد عام —
    // فيُخزَّن توكن الـcsrf كإعداد، ويمكن لأي حقل زائد أن يطمس مفاتيح حسّاسة مثل
    // teacher_form_deadline أو علامات الشفاء الذاتي yr_additions_backfilled_*).
    $allowedSettings = ['current_exchange_rate', 'current_school_year', 'grades_baseline_year',
                        'minimum_wage_lbp', 'school_name_ar', 'school_name_fr', 'school_address',
                        'school_phone', 'teacher_form_deadline', 'teacher_form_allow_after',
                        'transport_start_month', 'transport_end_month', 'official_usd_rate_lbp'];
    $oldRate = getSetting('current_exchange_rate'); // لرصد تغيّر سعر الصرف الافتراضي
    // لرصد تغيّر نافذة أشهر النقل (✍️ 2026-08-25: «إذا بدي عدل بعدل» — التعديل يعيد الحساب تلقائياً)
    $oldTrWin = getSetting('transport_start_month', '10') . '-' . getSetting('transport_end_month', '6');
    // لرصد تغيّر السعر الرسمي لقاعدة نسبة الإضافي (✍️ 2026-08-28: «بدي 1500 يكون عندي خيار عدلها»)
    $oldOfficial = (float)getSetting('official_usd_rate_lbp', 1500);
    foreach ($_POST as $k => $v) {
        if (!in_array($k, $allowedSettings, true) || is_array($v)) continue;
        setSetting($k, trim((string)$v));
    }
    // سعر الصرف الافتراضي fallback أخير لرواتب بلا سعر شهري — أعِد الحساب تلقائياً إن تغيّر
    $msg = 'Paramètres enregistrés / تم حفظ الإعدادات';
    if (isset($_POST['current_exchange_rate']) && (float)$oldRate !== (float)$_POST['current_exchange_rate']) {
        $nRec = recalcSalariesInRange($db, '2017-08-01', null);
        $msg = "تم الحفظ وإعادة حساب الرواتب المتأثّرة بسعر الصرف ($nRec). / Paramètres enregistrés, salaires recalculés.";
    }
    // نافذة أشهر النقل تغيّرت ⇒ إعادة حساب رواتب سريان القانون (من تشرين الأول 2026) تلقائياً
    $newTrWin = getSetting('transport_start_month', '10') . '-' . getSetting('transport_end_month', '6');
    if ($newTrWin !== $oldTrWin) {
        $nRec2 = recalcSalariesInRange($db, '2026-10-01', null);
        $msg .= " + أشهر النقل: أُعيد حساب الرواتب المتأثّرة ($nRec2). / Mois du transport modifiés, salaires recalculés.";
    }
    // ✍️ (2026-08-28) السعر الرسمي (قاعدة نسبة الإضافي ÷1500) تغيّر ⇒ إعادة حساب أصحاب النسب تلقائياً
    $newOfficial = (float)getSetting('official_usd_rate_lbp', 1500);
    if (isset($_POST['official_usd_rate_lbp']) && $newOfficial > 0 && $newOfficial !== $oldOfficial) {
        @set_time_limit(0);
        require_once __DIR__ . '/../includes/payroll_calculator.php';
        $pctEmps = $db->query("SELECT DISTINCT b.employee_id, b.school_year FROM employee_bonuses b
            JOIN employees e ON e.id = b.employee_id AND e.is_deleted = 0
            WHERE b.is_active = 1 AND b.value_type = 'percent'")->fetchAll(PDO::FETCH_ASSOC);
        $nRec3 = 0;
        foreach ($pctEmps as $pe) {
            $sy3 = $pe['school_year'] ?: currentSchoolYear();
            if (recalcEmployeeYear((int)$pe['employee_id'], $sy3) > 0) $nRec3++;
        }
        $msg .= " + السعر الرسمي صار " . rtrim(rtrim(number_format($newOfficial, 2), '0'), '.') . ": أُعيد حساب أصحاب النسبة المئوية ($nRec3). / Taux officiel modifié, salaires en % recalculés.";
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
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-school"></i> Informations des écoles</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">معلومات المدارس</div>
    </h3></div>
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
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-calendar"></i> Paramètres généraux</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">الإعدادات العامة</div>
        </h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Année scolaire actuelle / السنة الدراسية</label>
                <input type="text" name="current_school_year" class="form-control" value="<?= e(getSetting('current_school_year')) ?>" placeholder="2025-2026">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-bus"></i> Mois du transport (enseignants)</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">أشهر تعويض النقل (للأساتذة)</div>
        </h3></div>
        <div class="card-body">
            <?php // ✍️ (2026-08-25) «النقل من تشرين الأول لحزيران ضمناً — وإذا بدي عدل بعدل»:
                  // نافذة أشهر النقل للملاك والمتعاقدين (الموظف الإداري يداوم الصيف فنقله كل السنة).
                  // سارية من 2026-2027 — سنة 2025-2026 المدفوعة لا تتأثّر.
                  $trMonths = [10=>'Octobre / تشرين الأول',11=>'Novembre / تشرين الثاني',12=>'Décembre / كانون الأول',
                               1=>'Janvier / كانون الثاني',2=>'Février / شباط',3=>'Mars / آذار',4=>'Avril / نيسان',
                               5=>'Mai / أيار',6=>'Juin / حزيران',7=>'Juillet / تموز',8=>'Août / آب',9=>'Septembre / أيلول'];
                  $trS = max(1, min(12, (int)getSetting('transport_start_month', 10)));
                  $trE = max(1, min(12, (int)getSetting('transport_end_month', 6))); ?>
            <div class="form-row cols-2">
                <div class="form-group mb-0">
                    <label class="form-label">Du mois / من شهر</label>
                    <select name="transport_start_month" class="form-select">
                        <?php foreach ($trMonths as $mv=>$ml): ?><option value="<?= $mv ?>" <?= $mv===$trS?'selected':'' ?>><?= $ml ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Au mois (inclus) / إلى شهر (ضمناً)</label>
                    <select name="transport_end_month" class="form-select">
                        <?php foreach ($trMonths as $mv=>$ml): ?><option value="<?= $mv ?>" <?= $mv===$trE?'selected':'' ?>><?= $ml ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <small class="text-muted">للملاك والمتعاقدين — خارج هذه الأشهر لا يُدفع تعويض نقل (الافتراضي: تشرين الأول → حزيران = 9 أشهر). الموظفون الإداريون نقلهم كل السنة. يسري من 2026-2027.</small>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-coins"></i> Taux de change actuel</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">سعر الصرف الحالي</div>
        </h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Taux de change actuel (par défaut) / سعر الصرف الحالي (افتراضي)</label>
                <input type="number" name="current_exchange_rate" class="form-control" value="<?= e(getSetting('current_exchange_rate')) ?>" step="0.01">
                <small class="text-muted"><?= $lang==='ar'?'الأسعار الشهرية المفصّلة في صفحة «Taux de change».':'Les taux mensuels détaillés sont dans la page « Taux de change ».' ?></small>
            </div>
            <div class="form-group">
                <label class="form-label">Taux officiel (règle du % supplément) / السعر الرسمي القديم — قاعدة نسبة الأجر الإضافي</label>
                <input type="number" name="official_usd_rate_lbp" class="form-control" value="<?= e(getSetting('official_usd_rate_lbp', 1500)) ?>" step="0.01">
                <small class="text-muted">قاعدة النسبة المئوية للأجر الإضافي: الأساس بعد التدرّج ÷ هذا السعر × النسبة٪ ← داون للدولار ← × سعر السوق ← داون للمليون ليرة. <strong>تغييره يعيد حساب رواتب أصحاب النسبة تلقائياً.</strong></small>
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
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-percent"></i> Taux &amp; Pourcentages (lois)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">النِّسَب والقيم القانونية</div>
    </h3></div>
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
            <thead><tr><th>Valeur / القيمة</th><th>Actuel / الساري حالياً</th></tr></thead>
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
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-key"></i> Mot de passe</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">كلمة المرور</div>
    </h3></div>
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
                    <i class="fas fa-key"></i> Changer le mot de passe / تغيير كلمة المرور
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
