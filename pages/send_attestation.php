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
        . '<body><div class="box"><div class="ic">' . $icon . '</div><h2 style="color:' . $color . '">' . htmlspecialchars($msg) . '</h2>'
        . '<a href="' . htmlspecialchars($back) . '">Retour / رجوع</a></div></body></html>';
    exit;
}

$target = ltrim($_GET['target'] ?? '', '/');
$to = trim($_GET['to'] ?? '');
$name = preg_replace('/[^a-z0-9_]+/i', '_', (string)($_GET['name'] ?? 'attestation'));
$back = BASE_URL . ($target ?: 'pages/attestations.php');

if ($target === '' || preg_match('#^[a-z]+://#i', $target) || strpos($target, '..') !== false
    || !preg_match('#^[A-Za-z0-9_./?&=%\-+:]+$#', $target)) {
    sendResult(false, 'طلب غير صالح', BASE_URL . 'pages/attestations.php');
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    sendResult(false, 'البريد الإلكتروني غير صحيح', $back);
}

$cfg = smtpConfigFromSettings();
if (!$cfg) {
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
    [$ok, $err] = smtpSendMail($cfg, $to, $subject, $body, [['name' => $name . '.pdf', 'data' => $pdf, 'mime' => 'application/pdf']]);
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
    [$ok, $err] = smtpSendMail($cfg, $to, $subject, $body, [], true);
}

if ($ok) sendResult(true, "تم إرسال الإفادة إلى $to بنجاح", $back);
sendResult(false, "فشل الإرسال: $err", $back);
