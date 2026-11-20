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
    // 📧 (2026-10-12) إيميل البرنامج: اسم المُرسِل + عنوان اختياري (الافتراضي noreply@دومين الموقع)
    if (isset($_POST['save_program'])) {
        setSetting('program_email_name', trim((string)($_POST['program_email_name'] ?? '')));
        $pa = trim((string)($_POST['program_email_addr'] ?? ''));
        setSetting('program_email_addr', ($pa !== '' && filter_var($pa, FILTER_VALIDATE_EMAIL)) ? $pa : '');
        // 🚀 (2026-10-17) مفتاح خدمة الإرسال (Resend): فارغ = لا تغيير؛ «-» = إلغاء
        $rk = trim((string)($_POST['program_relay_key'] ?? ''));
        if ($rk === '-') setSetting('program_relay_key', ''); elseif ($rk !== '') setSetting('program_relay_key', $rk);
        $_SESSION['flash_success'] = 'تم حفظ إيميل البرنامج: ' . programMailName() . ' ‹' . programMailAddress() . '›' . (programRelayConfig() ? ' — خدمة الإرسال مفعّلة ✓' : '');
        header('Location: ' . BASE_URL . 'pages/email_settings.php'); exit;
    }
    // 🌐 (2026-10-15 «ركّبه») محرّك الطباعة الاحترافي (Chromium على سيرفر المدرسة): كلمة سرّ الخدمة تُحفَظ هنا لا بالكود
    // 📲 (2026-11-20) الواتساب المباشر (WhatsApp Cloud API — رقم المؤسّسة): المفتاح والمعرّفات تُحفَظ هنا لا بالكود
    // 🏛️ (2026-11-20) جهة اتّصال الرئاسة العامة (الاسم + واتساب + إيميل) — تظهر خياراً جاهزاً بنافذتَي الإرسال
    if (isset($_POST['save_hq_contact'])) {
        setSetting('hq_contact_name', mb_substr(trim((string)($_POST['hq_contact_name'] ?? '')), 0, 120));
        setSetting('hq_contact_whatsapp', mb_substr(trim((string)($_POST['hq_contact_whatsapp'] ?? '')), 0, 30));
        $hqE = trim((string)($_POST['hq_contact_email'] ?? '')); setSetting('hq_contact_email', ($hqE === '' || filter_var($hqE, FILTER_VALIDATE_EMAIL)) ? $hqE : (string)getSetting('hq_contact_email', ''));
        $_SESSION[($hqE === '' || filter_var($hqE, FILTER_VALIDATE_EMAIL)) ? 'flash_success' : 'flash_error'] = ($hqE === '' || filter_var($hqE, FILTER_VALIDATE_EMAIL)) ? 'تم حفظ جهة اتّصال الرئاسة العامة' : 'الإيميل غير صحيح — لم يتغيّر (الاسم والرقم حُفظا)';
        header('Location: ' . BASE_URL . 'pages/email_settings.php'); exit;
    }
    if (isset($_POST['save_wa_cloud'])) {
        $wt = trim((string)($_POST['wa_cloud_token'] ?? '')); if ($wt !== '') setSetting('wa_cloud_token', $wt);
        foreach (['wa_cloud_phone_id', 'wa_cloud_waba_id'] as $wk) { $wv = preg_replace('/\D/', '', (string)($_POST[$wk] ?? '')); if ($wv !== '') setSetting($wk, $wv); }
        setSetting('wa_cloud_from', mb_substr(trim((string)($_POST['wa_cloud_from'] ?? '')), 0, 30));
        setSetting('wa_cloud_disabled', !empty($_POST['wa_cloud_disabled']) ? '1' : '');
        setSetting('wa_cloud_tpl_checked_at', '');
        $_SESSION['flash_success'] = 'تم حفظ إعدادات الواتساب المباشر';
        header('Location: ' . BASE_URL . 'pages/email_settings.php'); exit;
    }
    if (isset($_POST['save_pdf_engine'])) {
        $pp = (string)($_POST['pdf_service_pass'] ?? '');
        if ($pp !== '') setSetting('pdf_service_pass', $pp);
        setSetting('pdf_service_disabled', !empty($_POST['pdf_service_disabled']) ? '1' : '');
        $_SESSION['flash_success'] = 'تم حفظ إعدادات محرّك الطباعة';
        header('Location: ' . BASE_URL . 'pages/email_settings.php'); exit;
    }
    if (isset($_POST['test_program'])) {
        $testTo = trim($_POST['test_to_program'] ?? '');
        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) { $_SESSION['flash_error'] = 'اكتب إيميلاً صحيحاً للتجربة'; }
        else {
            [$ok, $err] = programMailSend($testTo, 'تجربة إيميل البرنامج - ' . programMailName(), 'هذه رسالة تجربة من إيميل البرنامج ' . programMailAddress() . '. إذا وصلتك فالإرسال من إيميل البرنامج شغّال.', [], false, '', schoolReplyEmail());
            $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok ? "تم إرسال رسالة التجربة من إيميل البرنامج إلى $testTo ✓ (إذا ما لقيتها افحص Spam)" : "فشل الإرسال من إيميل البرنامج: $err";
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
<?php // 🏛️ (2026-11-20 «رقم الرئاسة العامة… يكون عندي الاسم ورقمو محفوظين») جهة اتّصال الرئاسة العامة
    $hqN = (string)getSetting('hq_contact_name', ''); $hqW = (string)getSetting('hq_contact_whatsapp', ''); $hqM = (string)getSetting('hq_contact_email', ''); ?>
<div class="card" style="max-width:680px;border:1px solid #c7d2fe">
    <div class="card-header" style="background:#eef2ff"><h3>
        <span dir="ltr"><i class="fas fa-landmark"></i> Supérieure Générale — contact</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">الرئاسة العامة — الاسم والرقم والإيميل المحفوظة للإرسال</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="line-height:1.9">بنافذة الواتساب وبنافذة الإيميل بيطلع خيار «🏛️ <?= e($hqN !== '' ? $hqN : 'الرئاسة العامة') ?>» جاهز — كبسة وحدة بتعبّي الرقم أو الإيميل.</div>
        <form method="post" class="lockedit">
            <?= csrfField() ?>
            <div class="form-row cols-2">
                <div class="form-group"><label class="form-label">Nom / الاسم</label><input type="text" name="hq_contact_name" class="form-control" value="<?= e($hqN) ?>" placeholder="الرئاسة العامة"></div>
                <div class="form-group"><label class="form-label">WhatsApp / رقم الواتساب</label><input type="tel" name="hq_contact_whatsapp" class="form-control" dir="ltr" value="<?= e($hqW) ?>" placeholder="03 123 456"></div>
                <div class="form-group"><label class="form-label">E-mail / الإيميل</label><input type="email" name="hq_contact_email" class="form-control" dir="ltr" value="<?= e($hqM) ?>" placeholder="name@example.com"></div>
            </div>
            <button class="btn btn-primary" name="save_hq_contact" value="1"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
        </form>
    </div>
</div>
<?php // 📲 (2026-11-20) الواتساب المباشر — نفس رقم المؤسّسة المستعمل ببرنامج الأقساط (WhatsApp Cloud API)
    require_once __DIR__ . '/../includes/wa_cloud.php';
    $waC = msaWaCfg(); $waOn = msaWaEnabled(); $waTpl = $waOn ? msaWaTemplateStatus() : '';
    $waTplLbl = ['APPROVED' => 'معتمَد ✓', 'PENDING' => 'قيد المراجعة عند واتساب ⏳', 'REJECTED' => 'مرفوض ✕', '' => 'غير معروف'][$waTpl] ?? $waTpl; ?>
<div class="card" style="max-width:680px;border:1px solid #bbf7d0">
    <div class="card-header" style="background:#f0fdf4"><h3>
        <span dir="ltr"><i class="fab fa-whatsapp"></i> WhatsApp direct</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">الواتساب المباشر (إرسال المستندات من رقم المؤسّسة)</div>
    </h3></div>
    <div class="card-body">
        <div class="alert <?= ($waOn && $waTpl === 'APPROVED') ? 'alert-success' : 'alert-warning' ?>" style="line-height:1.9">
            الحالة: <strong><?= $waC['disabled'] ? 'معطَّل (كبسة واتساب ترجع للطريقة اليدوية)' : ($waOn ? ('مربوط ✓ — الرقم ' . e($waC['from'] ?: '—')) : 'غير مربوط — يلزم المفتاح ومعرّف الرقم') ?></strong><?php if ($waOn): ?> · قالب الرسالة «<span dir="ltr"><?= e($waC['tpl']) ?></span>»: <strong><?= e($waTplLbl) ?></strong><?php endif; ?><br>
            كبسة WhatsApp بكل مستند بتسأل عن الرقم وبتبعت الملف PDF دغري برسالة رسمية موقَّعة «MSA Payroll»، وكل إرسال بيتسجّل بسجلّ الإرسال. لحسابات القراءة تبقى الطريقة اليدوية.
        </div>
        <form method="post" class="lockedit">
            <?= csrfField() ?>
            <div class="form-row cols-2">
                <div class="form-group"><label class="form-label">Jeton d'accès / مفتاح الربط <?= $waC['token'] !== '' ? '(محفوظ — اتركه فارغاً ليبقى)' : '' ?></label><input type="password" name="wa_cloud_token" class="form-control" dir="ltr" autocomplete="new-password"></div>
                <div class="form-group"><label class="form-label">Numéro affiché / الرقم الظاهر</label><input type="text" name="wa_cloud_from" class="form-control" dir="ltr" value="<?= e($waC['from']) ?>" placeholder="03 115 110"></div>
                <div class="form-group"><label class="form-label">Phone number ID</label><input type="text" name="wa_cloud_phone_id" class="form-control" dir="ltr" value="<?= e($waC['phone_id']) ?>"></div>
                <div class="form-group"><label class="form-label">WABA ID</label><input type="text" name="wa_cloud_waba_id" class="form-control" dir="ltr" value="<?= e($waC['waba_id']) ?>"></div>
                <div class="form-group"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="wa_cloud_disabled" value="1" <?= $waC['disabled'] ? 'checked' : '' ?>> Désactiver / تعطيل مؤقّت (ارجع للطريقة اليدوية)</label></div>
            </div>
            <button class="btn btn-primary" name="save_wa_cloud" value="1"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
        </form>
    </div>
</div>
<?php // 🌐 (2026-10-15) محرّك الطباعة الاحترافي — Chromium على سيرفر المدرسة (collegesaintmaxime.com/pdf)
    $pdfPassSet = (string)getSetting('pdf_service_pass', '') !== ''; $pdfOff = (string)getSetting('pdf_service_disabled', '') === '1';
    $pdfHealth = null; try { $chH = curl_init('https://collegesaintmaxime.com/pdf/health'); curl_setopt_array($chH, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 4]); curl_exec($chH); $pdfHealth = (int)curl_getinfo($chH, CURLINFO_HTTP_CODE); curl_close($chH); } catch (Throwable $e) {} ?>
<div class="card" style="max-width:680px;border:1px solid #c7d2fe">
    <div class="card-header" style="background:#eef2ff"><h3>
        <span dir="ltr"><i class="fas fa-print"></i> Moteur d'impression professionnel</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">محرّك الطباعة الاحترافي (Chromium على سيرفر المدرسة)</div>
    </h3></div>
    <div class="card-body">
        <div class="alert <?= ($pdfPassSet && !$pdfOff && $pdfHealth === 200) ? 'alert-success' : 'alert-warning' ?>" style="line-height:1.9">
            الحالة: <strong><?= $pdfOff ? 'معطَّل (الطباعة بالمحرّك الداخلي)' : ($pdfPassSet ? ($pdfHealth === 200 ? 'شغّال ✓' : 'الخدمة لا تردّ (' . (int)$pdfHealth . ') — الطباعة بالمحرّك الداخلي مؤقّتاً') : 'بلا كلمة سرّ — الطباعة بالمحرّك الداخلي') ?></strong><br>
            كل الطباعة والـPDF والإيميل والواتساب تمرّ من هذا المحرّك حين يكون شغّالاً؛ وإذا تعطّل يرجع البرنامج تلقائياً إلى محرّكه الداخلي.
        </div>
        <form method="post" class="lockedit">
            <?= csrfField() ?>
            <div class="form-row cols-2">
                <div class="form-group"><label class="form-label">Mot de passe du service / كلمة سرّ الخدمة <?= $pdfPassSet ? '(محفوظة — اتركها فارغة لتبقى)' : '' ?></label><input type="password" name="pdf_service_pass" class="form-control" dir="ltr" autocomplete="new-password"></div>
                <div class="form-group"><label class="form-label">&nbsp;</label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="pdf_service_disabled" value="1" <?= $pdfOff ? 'checked' : '' ?>> Désactiver / تعطيل مؤقّت (ارجع للمحرّك الداخلي)</label></div>
            </div>
            <button class="btn btn-primary" name="save_pdf_engine" value="1"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
        </form>
    </div>
</div>
<?php // 📧 (2026-10-12) إيميل البرنامج — يبعت منه خادم الموقع بلا كلمة سرّ؛ كل مدرسة تختار بصفحة المدارس: إيميل البرنامج (الافتراضي) أو إيميلها
    $progSchools = []; try { $progSchools = getDB()->query("SELECT name_ar, name_fr, email, IFNULL(email_sender_mode,'program') m FROM schools WHERE is_active = 1" . schoolScopeSql('id') . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $t) {} ?>
<div class="card" style="max-width:680px;border:1px solid #bfdbfe">
    <div class="card-header" style="background:#eff6ff"><h3>
        <span dir="ltr"><i class="fas fa-paper-plane"></i> E-mail du programme</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">إيميل البرنامج (الإرسال بلا كلمة سرّ)</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="line-height:1.9">
            الإيميل الحالي: <strong><?= e(programMailName()) ?></strong> ‹<span dir="ltr"><?= e(programMailAddress()) ?></span>›<br>
            <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>pages/mail_log.php" style="float:left;margin-inline-start:8px"><i class="fas fa-envelope-open-text"></i> سجلّ الإيميلات / Suivi</a>
            كل الإيميلات تخرج عبر خدمة الإرسال (HTTPS) وتُسجَّل بسجلّ الإيميلات مع حالة وصولها الحقيقية (وصل / فُتح / ارتدّ). والردّ يرجع لإيميل المدرسة المكتوب بصفحة المدارس. يعمل على الموقع (<?= programMailAvailable() ? '✅ متاح هنا' : '⚠️ غير متاح على نسخة الكمبيوتر — على الموقع فقط' ?>).
        </div>
        <form method="post" class="lockedit">
            <?= csrfField() ?>
            <div class="form-row cols-2">
                <div class="form-group"><label class="form-label">Nom de l'expéditeur / اسم المُرسِل</label><input type="text" name="program_email_name" class="form-control" value="<?= e(getSetting('program_email_name', '')) ?>" placeholder="MSA Payroll"></div>
                <div class="form-group"><label class="form-label">Clé du service d'envoi (Resend) / مفتاح خدمة الإرسال <?= programRelayConfig() ? '<span style="color:#15803d">✓ مفعّلة</span>' : '<span style="color:#b45309">غير مفعّلة — الإرسال من خادم الموقع</span>' ?></label><input type="password" name="program_relay_key" class="form-control" dir="ltr" value="" autocomplete="new-password" placeholder="<?= programRelayConfig() ? 'محفوظ — اتركه فارغاً، أو «-» للإلغاء' : 're_…' ?>"></div>
                <div class="form-group"><label class="form-label">Adresse (optionnel) / العنوان (اختياري — الافتراضي no-reply@<?= e(programMailDomain()) ?>)</label><input type="email" name="program_email_addr" class="form-control" dir="ltr" value="<?= e(getSetting('program_email_addr', '')) ?>" placeholder="no-reply@<?= e(programMailDomain()) ?>"></div>
            </div>
            <button class="btn btn-primary" name="save_program" value="1"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
        </form>
        <form method="post" class="form-row" style="align-items:end;gap:10px;margin-top:10px">
            <?= csrfField() ?>
            <div class="form-group" style="flex:1"><label class="form-label">Test depuis l'e-mail du programme / تجربة من إيميل البرنامج إلى</label>
                <input type="email" name="test_to_program" class="form-control" dir="ltr" value="<?= e($email) ?>" placeholder="جرّب على إيميلك"></div>
            <button class="btn btn-success" name="test_program" value="1"><i class="fas fa-paper-plane"></i> Envoyer un test / إرسال تجربة</button>
        </form>
        <?php // 🧾 سجلّات DNS المطلوبة للتوقيع (يكتبها سكربت تركيب بريد السيرفر) — للمدير العام
            $dnsNeeded = @file_get_contents(__DIR__ . '/../tmp/dns_records_needed.txt');
            if ($dnsNeeded !== false && trim($dnsNeeded) !== ''): ?>
        <details style="margin-top:10px"><summary style="cursor:pointer;color:#2563eb;font-size:13px">سجلّات DNS للدومين (SPF + DKIM) — تقنية</summary>
            <pre id="dnsNeeded" dir="ltr" style="font-size:11px;white-space:pre-wrap;word-break:break-all;background:#f8fafc;padding:8px;border-radius:8px"><?= e($dnsNeeded) ?></pre></details>
        <?php endif; ?>
        <?php if ($progSchools): ?>
        <div style="margin-top:12px;font-size:13px;line-height:1.9">
            <strong>المدارس — الإرسال من</strong> (يتغيّر من صفحة <a href="<?= BASE_URL ?>pages/schools.php">المدارس</a>):
            <?php foreach ($progSchools as $ps): ?>
                <span class="badge" style="background:<?= $ps['m'] === 'school' ? '#fef3c7' : '#dcfce7' ?>;color:#1f2937;margin:2px"><?= e($ps['name_ar'] ?: $ps['name_fr']) ?>: <?= $ps['m'] === 'school' ? '🏫 إيميل المدرسة' : '📨 إيميل البرنامج' ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="card" style="max-width:680px">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-envelope"></i> E-mail de l'école (SMTP)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">إيميل المدرسة (للمدارس التي اختارت الإرسال من إيميلها)</div>
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
