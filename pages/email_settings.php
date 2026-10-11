<?php
/**
 * إعدادات البريد الإلكتروني (SMTP) للإرسال التلقائي للإفادات.
 * للمدير العام. يكفي إدخال الإيميل وكلمة السر (الخادم يُكشَف تلقائياً للـHotmail/Gmail...).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
if (!isSuperAdmin()) { $_SESSION['flash_error'] = 'صلاحية المدير العام فقط'; header('Location: ' . BASE_URL . 'index.php'); exit; }

$currentPage = 'email_settings';
$pageTitle = 'إعدادات البريد / Email';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (isset($_POST['save'])) {
        setSetting('smtp_email', trim($_POST['smtp_email'] ?? ''));
        setSetting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        setSetting('smtp_port', trim($_POST['smtp_port'] ?? ''));
        setSetting('smtp_secure', trim($_POST['smtp_secure'] ?? ''));
        setSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        // 🔑 (2026-10-11 «عم حاول غيّر كلمة السر… بس حطّ حفظ بيرجع للقديم»): كلمة السر الجديدة تُحفَظ فوراً، ويُسجَّل وقت تغييرها،
        //    ويُفحَص تسجيل الدخول بها على خادم البريد بالحال — فتعرف دغري إنّها انحفظت وإن كان الخادم قبلها. الفارغة = إبقاء المحفوظة.
        $newPass = (string)($_POST['smtp_pass'] ?? '');
        // كلمة مرور تطبيق Google تُعرض «xxxx xxxx xxxx xxxx» — الفراغات ليست جزءاً منها
        if (preg_match('/^\s*([a-z]{4}\s+){3}[a-z]{4}\s*$/i', $newPass)) $newPass = preg_replace('/\s+/', '', $newPass);
        else $newPass = trim($newPass);
        if ($newPass !== '') {
            setSetting('smtp_pass', $newPass);
            setSetting('smtp_pass_changed_at', date('Y-m-d H:i'));
            $cfg = smtpConfigFromSettings();
            [$okL, $errL] = $cfg ? smtpCheckLogin($cfg) : [false, 'إعدادات البريد غير مكتملة'];
            $hint = 'كلمة السر الجديدة انحفظت (' . mb_strlen($newPass, 'UTF-8') . ' حرفاً، تبدأ بـ«' . mb_substr($newPass, 0, 1, 'UTF-8') . '» وتنتهي بـ«' . mb_substr($newPass, -1, 1, 'UTF-8') . '»)';
            if ($okL) $_SESSION['flash_success'] = '✅ ' . $hint . ' — وخادم البريد قبلها: الإرسال جاهز.';
            else $_SESSION['flash_error'] = '⚠️ ' . $hint . ' — لكن خادم البريد رفضها: ' . $errL . ' — تأكّد أنّها «كلمة مرور التطبيق» لا كلمة سرّ الحساب العادية.';
        } else {
            $_SESSION['flash_success'] = 'تم حفظ إعدادات البريد (كلمة السر المحفوظة لم تتغيّر لأنّ خانتها تُركت فارغة)';
        }
        header('Location: ' . BASE_URL . 'pages/email_settings.php'); exit;
    }
    if (isset($_POST['test'])) {
        $cfg = smtpConfigFromSettings();
        $testTo = trim($_POST['test_to'] ?? '') ?: ($cfg['user'] ?? '');
        if (!$cfg) { $_SESSION['flash_error'] = 'احفظ الإعدادات أولاً'; }
        else {
            [$ok, $err] = smtpSendMail($cfg, $testTo, 'تجربة البريد - ' . getSetting('school_name_ar', ''), 'هذه رسالة تجربة من برنامج الرواتب. إذا وصلتك فالإعدادات صحيحة.', []);
            $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok ? "تم إرسال رسالة التجربة إلى $testTo بنجاح ✓" : "فشل الإرسال: $err";
        }
        header('Location: ' . BASE_URL . 'pages/email_settings.php'); exit;
    }
}

$email = getSetting('smtp_email', '');
$savedPass = (string)getSetting('smtp_pass', '');
$hasPass = $savedPass !== '';
$passChanged = (string)getSetting('smtp_pass_changed_at', '');
$host = getSetting('smtp_host', '');
$port = getSetting('smtp_port', '');
$secure = getSetting('smtp_secure', '');
$fromName = getSetting('smtp_from_name', '');
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:680px">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-envelope"></i> Paramètres de messagerie (envoi automatique)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">إعدادات البريد الإلكتروني (للإرسال التلقائي)</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="line-height:1.9">
            يكفي إدخال <strong>إيميل المدرسة وكلمة السر</strong> — الخادم يُكشَف تلقائياً (Hotmail / Gmail / Outlook…).<br>
            🔑 <strong>مهم:</strong> Gmail و Hotmail/Outlook يتطلّبان غالباً <strong>«كلمة مرور تطبيق» (App Password)</strong> بدل كلمة سرّك العادية (من إعدادات أمان حسابك)، لأنّ كلمة السر العادية مرفوضة للإرسال الخارجي.
        </div>
        <form method="post" class="lockedit">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">E-mail de l'école / البريد الإلكتروني للمدرسة</label>
                <input type="email" name="smtp_email" class="form-control" dir="ltr" value="<?= e($email) ?>" placeholder="ecole@hotmail.com" required>
            </div>
            <div class="form-group">
                <label class="form-label">Mot de passe / كلمة السر / كلمة مرور التطبيق <?= $hasPass ? '<span style="color:#16a34a">(محفوظة — اتركها فارغة للإبقاء عليها)</span>' : '' ?></label>
                <div style="display:flex;gap:6px;align-items:center">
                    <input type="password" name="smtp_pass" id="smtpPass" class="form-control" dir="ltr" value="" placeholder="<?= $hasPass ? 'اكتب كلمة سرّ جديدة هون فقط إذا بدّك تغيّرها' : 'كلمة المرور' ?>" autocomplete="new-password" data-lpignore="true" style="flex:1">
                    <button type="button" class="btn btn-sm btn-light" id="smtpEye" title="إظهار / إخفاء ما كتبته"><i class="fas fa-eye"></i></button>
                </div>
                <?php if ($hasPass): ?>
                <div style="font-size:12.5px;color:#475569;margin-top:4px;line-height:1.7">
                    🔑 المحفوظة حالياً: <b><?= mb_strlen($savedPass, 'UTF-8') ?></b> حرفاً، تبدأ بـ«<b dir="ltr"><?= e(mb_substr($savedPass, 0, 1, 'UTF-8')) ?></b>» وتنتهي بـ«<b dir="ltr"><?= e(mb_substr($savedPass, -1, 1, 'UTF-8')) ?></b>»<?= $passChanged !== '' ? ' — آخر تغيير: ' . e($passChanged) : '' ?>.
                    بعد الحفظ بتنفحص فوراً على خادم البريد وبتعرف إذا قبلها.
                </div>
                <?php endif; ?>
                <script>document.getElementById('smtpEye').addEventListener('click', function () { var p = document.getElementById('smtpPass'); p.type = p.type === 'password' ? 'text' : 'password'; this.innerHTML = p.type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>'; });</script>
            </div>
            <div class="form-group">
                <label class="form-label">Nom de l'expéditeur / اسم المُرسِل (يظهر بالبريد)</label>
                <input type="text" name="smtp_from_name" class="form-control" value="<?= e($fromName) ?>" placeholder="<?= e(getSetting('school_name_ar','')) ?>">
            </div>
            <details style="margin:10px 0">
                <summary style="cursor:pointer;color:#2563eb">Paramètres avancés (optionnel — laisser vide pour la détection auto) / إعدادات متقدّمة (اختياري — اتركها فارغة للكشف التلقائي)</summary>
                <div class="form-row cols-3" style="margin-top:8px">
                    <div class="form-group"><label class="form-label">Serveur SMTP / الخادم SMTP</label><input type="text" name="smtp_host" class="form-control" dir="ltr" value="<?= e($host) ?>" placeholder="smtp-mail.outlook.com"></div>
                    <div class="form-group"><label class="form-label">Port / المنفذ</label><input type="text" name="smtp_port" class="form-control" dir="ltr" value="<?= e($port) ?>" placeholder="587"></div>
                    <div class="form-group"><label class="form-label">Chiffrement / التشفير</label>
                        <select name="smtp_secure" class="form-select">
                            <option value="" <?= $secure===''?'selected':'' ?>>Auto / تلقائي</option>
                            <option value="tls" <?= $secure==='tls'?'selected':'' ?>>STARTTLS (587)</option>
                            <option value="ssl" <?= $secure==='ssl'?'selected':'' ?>>SSL (465)</option>
                        </select>
                    </div>
                </div>
            </details>
            <button class="btn btn-primary" name="save" value="1"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
        </form>
        <hr>
        <form method="post" class="form-row" style="align-items:end;gap:10px">
            <?= csrfField() ?>
            <div class="form-group" style="flex:1"><label class="form-label">Envoyer un e-mail de test à / إرسال رسالة تجربة إلى</label>
                <input type="email" name="test_to" class="form-control" dir="ltr" value="<?= e($email) ?>" placeholder="جرّب على إيميلك"></div>
            <button class="btn btn-success" name="test" value="1"><i class="fas fa-paper-plane"></i> Envoyer un test / إرسال تجربة</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
