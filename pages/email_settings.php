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
        // لا تَدُس كلمة السر إن تُركت فارغة (حفاظاً على المحفوظة)
        if (trim($_POST['smtp_pass'] ?? '') !== '') setSetting('smtp_pass', trim($_POST['smtp_pass']));
        setSetting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        setSetting('smtp_port', trim($_POST['smtp_port'] ?? ''));
        setSetting('smtp_secure', trim($_POST['smtp_secure'] ?? ''));
        setSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        $_SESSION['flash_success'] = 'تم حفظ إعدادات البريد';
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
$hasPass = getSetting('smtp_pass', '') !== '';
$host = getSetting('smtp_host', '');
$port = getSetting('smtp_port', '');
$secure = getSetting('smtp_secure', '');
$fromName = getSetting('smtp_from_name', '');
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:680px">
    <div class="card-header"><h3><i class="fas fa-envelope"></i> إعدادات البريد الإلكتروني (للإرسال التلقائي)</h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="line-height:1.9">
            يكفي إدخال <strong>إيميل المدرسة وكلمة السر</strong> — الخادم يُكشَف تلقائياً (Hotmail / Gmail / Outlook…).<br>
            🔑 <strong>مهم:</strong> Gmail و Hotmail/Outlook يتطلّبان غالباً <strong>«كلمة مرور تطبيق» (App Password)</strong> بدل كلمة سرّك العادية (من إعدادات أمان حسابك)، لأنّ كلمة السر العادية مرفوضة للإرسال الخارجي.
        </div>
        <form method="post">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">البريد الإلكتروني للمدرسة</label>
                <input type="email" name="smtp_email" class="form-control" dir="ltr" value="<?= e($email) ?>" placeholder="ecole@hotmail.com" required>
            </div>
            <div class="form-group">
                <label class="form-label">كلمة السر / كلمة مرور التطبيق <?= $hasPass ? '<span style="color:#16a34a">(محفوظة — اتركها فارغة للإبقاء عليها)</span>' : '' ?></label>
                <input type="password" name="smtp_pass" class="form-control" dir="ltr" placeholder="<?= $hasPass ? '••••••••' : 'كلمة المرور' ?>" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label">اسم المُرسِل (يظهر بالبريد)</label>
                <input type="text" name="smtp_from_name" class="form-control" value="<?= e($fromName) ?>" placeholder="<?= e(getSetting('school_name_ar','')) ?>">
            </div>
            <details style="margin:10px 0">
                <summary style="cursor:pointer;color:#2563eb">إعدادات متقدّمة (اختياري — اتركها فارغة للكشف التلقائي)</summary>
                <div class="form-row cols-3" style="margin-top:8px">
                    <div class="form-group"><label class="form-label">الخادم SMTP</label><input type="text" name="smtp_host" class="form-control" dir="ltr" value="<?= e($host) ?>" placeholder="smtp-mail.outlook.com"></div>
                    <div class="form-group"><label class="form-label">المنفذ Port</label><input type="text" name="smtp_port" class="form-control" dir="ltr" value="<?= e($port) ?>" placeholder="587"></div>
                    <div class="form-group"><label class="form-label">التشفير</label>
                        <select name="smtp_secure" class="form-select">
                            <option value="" <?= $secure===''?'selected':'' ?>>تلقائي</option>
                            <option value="tls" <?= $secure==='tls'?'selected':'' ?>>STARTTLS (587)</option>
                            <option value="ssl" <?= $secure==='ssl'?'selected':'' ?>>SSL (465)</option>
                        </select>
                    </div>
                </div>
            </details>
            <button class="btn btn-primary" name="save" value="1"><i class="fas fa-save"></i> حفظ</button>
        </form>
        <hr>
        <form method="post" class="form-row" style="align-items:end;gap:10px">
            <?= csrfField() ?>
            <div class="form-group" style="flex:1"><label class="form-label">إرسال رسالة تجربة إلى</label>
                <input type="email" name="test_to" class="form-control" dir="ltr" value="<?= e($email) ?>" placeholder="جرّب على إيميلك"></div>
            <button class="btn btn-success" name="test" value="1"><i class="fas fa-paper-plane"></i> إرسال تجربة</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
