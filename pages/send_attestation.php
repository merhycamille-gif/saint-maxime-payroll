<?php
/**
 * إرسال إفادة تلقائياً بالبريد مع مرفق PDF رسمي (يُولَّد عبر Chrome/puppeteer ثم يُرسَل عبر SMTP).
 *   ?target=<مسار الإفادة الداخلي مُرمَّز>&to=<إيميل>&name=<اسم الملف>
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();

function sendResult($ok, $msg, $back)
{
    $color = $ok ? '#16a34a' : '#dc2626';
    $icon = $ok ? '✓' : '✕';
    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{font-family:Tahoma,Arial;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.box{background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.12);padding:34px;max-width:480px;text-align:center}'
        . '.ic{font-size:46px;color:' . $color . '}a{display:inline-block;margin-top:18px;background:#1e3a5f;color:#fff;padding:9px 20px;border-radius:8px;text-decoration:none}</style></head>'
        . '<body><div class="box"><div class="ic">' . $icon . '</div><h2 style="color:' . $color . '">' . htmlspecialchars($msg) . '</h2>';
    // 📬 (2026-11-04 «أوقات بيوصل أوقات ما بيوصل») بعد الإرسال نتابع الوصول عند خدمة الإرسال حتى 90 ثانية ونقول «✅ وصلت» (أو Junk/ارتدّت/متأخّرة) — كنافذة إرسال التقارير
    $logId = (string)($GLOBALS['msa_mail_log_id'] ?? '');
    if ($ok && $logId !== '') {
        echo '<div id="trk" style="margin-top:10px;font-weight:700;color:#334155;line-height:1.8">⏳ عم نتأكّد إنّها وصلت لبريد المستلم…</div>'
           . '<script>(function(){var u=' . json_encode(BASE_URL . 'pages/mail_status.php?id=' . $logId) . ',l=document.getElementById("trk"),t0=Date.now(),d=3000;'
           . 'function tick(){fetch(u,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json()}).then(function(s){var st=(s&&s.status)||"";'
           . 'if(st==="delivered"||st==="opened"||st==="clicked"){l.style.color="#166534";l.innerHTML="✅ وصلت لبريد المستلم — إذا ما لقيها بالوارد فليفتّش بمجلّد <b>Junk / Courrier indésirable</b> ويعلّمها «ليست غير مرغوبة» مرّة واحدة.";return}'
           . 'if(st==="bounced"){l.style.color="#173f6f";l.textContent="⛔ ارتدّت — العنوان غير موجود أو فيه غلطة.";return}'
           . 'if(st==="complained"||st==="failed"||st==="canceled"){l.style.color="#173f6f";l.textContent=(s&&s.label)||st;return}'
           . 'if(Date.now()-t0>90000){l.style.color="#0e7490";l.textContent="⏳ خرجت من البرنامج، وبريد المستلم بعده ما أكّد الاستلام — هوتميل أحياناً يتأخّر دقائق. البرنامج يتابعها لحاله وتشوف النتيجة بسجلّ الإيميلات.";return}'
           . 'if(st==="delivery_delayed")l.textContent="⏳ بريد المستلم متأخّر — خدمة الإرسال تعيد المحاولة لحالها…";setTimeout(tick,d);d=Math.min(d+1000,8000)}).catch(function(){if(Date.now()-t0<90000)setTimeout(tick,5000)})}'
           . 'setTimeout(tick,2500)})();</script>';
    }
    echo '<a href="' . htmlspecialchars($back) . '">Retour / رجوع</a></div></body></html>';
    exit;
}

$target = ltrim($_GET['target'] ?? '', '/');
$to = trim($_GET['to'] ?? '');
$name = trim(preg_replace('/[^a-z0-9_]+/i', '_', (string)($_GET['name'] ?? 'attestation')), '_');
$back = BASE_URL . ($target ?: 'pages/attestations.php');
// 📎 (2026-10-27 p1 «الملف وصل باسم _.pdf») العنوان العربي يضيع بالتنقية ⇒ اسم لاتيني واضح من الرابط: نوع النموذج/الإفادة + اسم الأستاذ الفرنسي + التاريخ
if ($name === '' || $name === 'attestation' || $name === 'formulaire') {
    $q = []; parse_str((string)parse_url($target, PHP_URL_QUERY), $q);
    $formKey = (string)($q['form'] ?? $q['type'] ?? 'attestation');
    $latin = ['cnss_hire_new' => 'Declaration_embauche_CNSS', 'cnss_hire_reg' => 'Declaration_embauche_CNSS', 'cnss_leave' => 'Declaration_cessation_CNSS', 'cnss_travail' => 'Attestation_travail_CNSS',
              'mof_r3' => 'Formulaire_R3', 'salaire' => 'Attestation_salaire', 'tadris' => 'Attestation_enseignement', 'cnss' => 'Attestation_CNSS'][$formKey] ?? ('Document_' . preg_replace('/[^a-z0-9_]+/i', '_', $formKey));
    $eid = (int)($q['emp'] ?? $q['employee_id'] ?? 0); $who = '';
    if ($eid > 0) { $st = getDB()->prepare("SELECT first_name_fr, last_name_fr FROM employees WHERE id = ?"); $st->execute([$eid]); if ($e = $st->fetch()) $who = trim(preg_replace('/[^a-z0-9]+/i', '_', $e['first_name_fr'] . '_' . $e['last_name_fr']), '_'); }
    $name = trim($latin . ($who !== '' ? '_' . $who : '') . '_' . date('Y-m-d'), '_');
}

if ($target === '' || preg_match('#^[a-z]+://#i', $target) || strpos($target, '..') !== false
    || !preg_match('#^[A-Za-z0-9_./?&=%\-+:\[\]]+$#', $target)) { // ☑️ (2026-09-26) روابط الفئة بخانات type[]=… تحمل [ ]
    sendResult(false, 'طلب غير صالح', BASE_URL . 'pages/attestations.php');
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    sendResult(false, 'البريد الإلكتروني غير صحيح', $back);
}

// 📧 (2026-10-12) الإرسال عبر الباب الواحد msaSendMail: إيميل البرنامج أو إيميل المدرسة حسب خيار المدرسة — لا حاجة لإعدادات البريد إلا إذا اختارت المدرسة إيميلها
$attSchoolId = (!isAllSchools() && currentSchool()) ? (int)currentSchool()['id'] : null;
if (schoolEmailMode($attSchoolId) === 'school' && !smtpConfigFromSettings() && !programMailAvailable()) {
    sendResult(false, 'لم تُضبَط إعدادات البريد بعد. افتح «إعدادات البريد» وأدخل إيميل المدرسة وكلمة المرور.', BASE_URL . 'pages/email_settings.php');
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cookieName = session_name();
$cookieValue = session_id();
$schoolName = getSetting('school_name_ar', currentSchool()['name_ar'] ?? '');
$subject = 'إفادة - ' . $schoolName;

// أداة توليد PDF عبر Chrome/puppeteer (متوفّرة محلياً). أونلاين (بلا الأداة): نرسل الإفادة HTML.
$node = null;
foreach (['C:/Program Files/nodejs/node.exe', 'node'] as $n) {
    if ($n === 'node' || @is_file($n)) { $node = $n; break; }
}
$script = __DIR__ . '/../tools/page_to_pdf.js';
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
$hasPuppeteer = ($node && is_file($script) && is_dir(__DIR__ . '/../tools/node_modules/puppeteer-core'));

if ($hasPuppeteer) {
    // ----- محلياً: PDF رسمي مرفق -----
    $url = $baseUrl . BASE_URL . $target . (strpos($target, '?') !== false ? '&' : '?') . '_pdf=1';
    session_write_close();
    $uid = uniqid('mail', true);
    $out = $tmpDir . '/' . $uid . '.pdf';
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' '
         . escapeshellarg(str_replace('\\', '/', $out)) . ' ' . escapeshellarg($url) . ' '
         . escapeshellarg($cookieName) . ' ' . escapeshellarg($cookieValue) . ' \'\' 2>&1';
    @shell_exec($cmd);
    if (!is_file($out) || filesize($out) < 400) { @unlink($out); sendResult(false, 'تعذّر توليد ملف الإفادة', $back); }
    $pdf = file_get_contents($out);
    @unlink($out);
    $body = "السلام عليكم،\n\nمرفق الإفادة المطلوبة بصيغة PDF.\n\n" . $schoolName;
    [$ok, $err] = msaSendMail($to, $subject, $body, [['name' => $name . '.pdf', 'data' => $pdf, 'mime' => 'application/pdf']], false, $attSchoolId, $schoolName);
} else {
    // ----- أونلاين (بلا puppeteer): نجلب نصّ الإفادة (#ppExportArea) ونرسله كبريد HTML -----
    $url = $baseUrl . BASE_URL . $target;
    session_write_close();
    $html = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cookieName . '=' . $cookieValue,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false]);
        $html = (string)curl_exec($ch); curl_close($ch);
    }
    // 🏛️📧 (2026-10-27 «بعتت الاستخدام لعندي على الإيميل شوف شو طلع») النموذج الرسمي المصوّر (official_export mode=image) كان يُبعَث صفحةَ ويب مبعثرة
    //    بجسم الرسالة. الآن: PDF طبق الأصل عبر محرّك المدرسة (Chromium) مرفقاً — كما على الكمبيوتر. إن تعطّل المحرّك يبقى المسار القديم.
    if ($html !== '' && stripos($html, 'class="pbg"') !== false) {
        require_once __DIR__ . '/../includes/pdf_chromium.php';
        if (MsaChromePdf::available()) {
            try {
                $pdf = MsaChromePdf::overlayPageToPdf($html);
                $body = "السلام عليكم،\n\nمرفق النموذج الرسمي المطلوب بصيغة PDF.\n\n" . $schoolName;
                [$ok, $err] = msaSendMail($to, $subject, $body, [['name' => $name . '.pdf', 'data' => $pdf, 'mime' => 'application/pdf']], false, $attSchoolId, $schoolName);
                if ($ok) sendResult(true, 'تمّ إرسال النموذج الرسمي (PDF مرفق) إلى ' . $to, $back);
                sendResult(false, 'تعذّر الإرسال: ' . $err, $back);
            } catch (Throwable $e) { /* المحرّك متعطّل ⇒ المسار القديم أدناه */ }
        }
    }
    $content = '';
    if ($html !== '') {
        // استخرج محتوى منطقة الإفادة #ppExportArea (وإلا أرسل الصفحة كما هي)
        if (preg_match('#<div[^>]*id=["\']ppExportArea["\'][^>]*>(.*?)</div>\s*(?:<div[^>]*class=["\'][^"\']*export|<script|</body)#is', $html, $mm)) {
            $content = $mm[1];
        } else { $content = $html; }
    }
    if ($content === '') sendResult(false, 'تعذّر جلب نصّ الإفادة لإرسالها', $back);
    $body = '<div dir="rtl" style="font-family:Arial,sans-serif">' . $content
          . '<hr><div style="color:#555;font-size:13px">' . htmlspecialchars($schoolName) . '</div></div>';
    [$ok, $err] = msaSendMail($to, $subject, $body, [], true, $attSchoolId, $schoolName);
}

if ($ok) sendResult(true, "تم إرسال الإفادة إلى $to بنجاح", $back);
sendResult(false, "فشل الإرسال: $err", $back);
