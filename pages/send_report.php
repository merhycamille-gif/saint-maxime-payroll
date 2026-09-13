<?php
/**
 * 📧 إرسال أي تقرير/نموذج/إفادة بالإيميل مع الملف PDF مرفقاً (2026-09-13 «الإرسال بالإيميل والواتساب مش شغالين»):
 * الصفحة تولّد الـPDF بالمتصفّح (pdf-save.js) وترسله هنا POST (multipart) مع المستلِم والموضوع والرسالة،
 * ونرسله من الخادم عبر إعدادات البريد (SMTP بـincludes/mailer.php)، وإن تعذّر SMTP نجرّب بريد الخادم نفسه (mail()).
 * يرجّع JSON {ok, msg}. محميّ بـCSRF + تسجيل الدخول + حساب يقدر يعدّل (لا حسابات القراءة).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
$out = function (bool $ok, string $msg, array $extra = []) { echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE); exit; };

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(false, 'طلب غير صالح');
if (!verifyCsrf($_POST['csrf'] ?? '')) $out(false, 'رمز الأمان غير صحيح — أعد تحميل الصفحة وحاول مجدداً');
if (!canEdit()) $out(false, 'غير مسموح — حساب قراءة فقط');

$to = trim((string)($_POST['to'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? '')) ?: 'مستند من برنامج الرواتب';
$body = trim((string)($_POST['body'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $out(false, 'البريد الإلكتروني غير صحيح');
$f = $_FILES['pdf'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || (int)$f['size'] < 300) $out(false, 'لم يصل ملف الـPDF — أعد المحاولة');
if ((int)$f['size'] > 15 * 1024 * 1024) $out(false, 'الملف كبير جداً (> 15MB)');
$data = (string)file_get_contents($f['tmp_name']);
if (substr($data, 0, 4) !== '%PDF') $out(false, 'الملف ليس PDF');
$name = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string)($f['name'] ?: 'document.pdf'));
if (substr($name, -4) !== '.pdf') $name .= '.pdf';

// اسم المدرسة المختارة (عبرا…) لا الاسم العام للمؤسسة — إلا في وضع «كل المدارس»
$schoolName = (!isAllSchools() && currentSchool()) ? (string)(currentSchool()['name_ar'] ?: currentSchool()['name_fr']) : (string)getSetting('school_name_ar', '');
$text = ($body !== '' ? $body : 'مرفق المستند المطلوب بصيغة PDF.') . "\n\n" . $schoolName;
$att = [['name' => $name, 'data' => $data, 'mime' => 'application/pdf']];
$settingsUrl = BASE_URL . 'pages/email_settings.php';

// ١) SMTP من إعدادات البريد
$cfg = smtpConfigFromSettings();
$err = '';
if ($cfg) {
    if ($schoolName !== '') $cfg['from_name'] = $schoolName; // المرسِل باسم المدرسة المختارة
    [$ok, $err] = smtpSendMail($cfg, $to, $subject, $text, $att);
    if ($ok) { logAudit('send_report_email', 'settings', 0, null, ['to' => $to, 'subject' => $subject, 'file' => $name, 'via' => 'smtp']); $out(true, 'أُرسل إلى ' . $to . ' مع الملف ' . $name . ' (عبر ' . $cfg['user'] . ')'); }
}
// ٢) بريد الخادم نفسه (mail) — احتياط عندما لا SMTP أو رُفض تسجيل الدخول (هوتميل يمنع كلمة السر العادية)
$host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
if ($host !== 'localhost' && strpos($host, '127.') !== 0 && strpos($host, '192.') !== 0 && function_exists('mail')) {
    $from = 'no-reply@' . preg_replace('/:\d+$/', '', $host);
    $fromName = $schoolName ?: 'MSA Payroll';
    $boundary = '=_pp_' . bin2hex(random_bytes(8));
    $h  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n";
    if ($cfg && !empty($cfg['user'])) $h .= "Reply-To: <" . $cfg['user'] . ">\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    $m  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n";
    $m .= "--$boundary\r\nContent-Type: application/pdf; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($data)) . "\r\n--$boundary--\r\n";
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $m, $h, '-f' . $from);
    if ($sent) { logAudit('send_report_email', 'settings', 0, null, ['to' => $to, 'subject' => $subject, 'file' => $name, 'via' => 'mail']); $out(true, 'أُرسل إلى ' . $to . ' مع الملف ' . $name . ' (من بريد الموقع ' . $from . ' — إذا ما لقيته افحص Spam)'); }
}
if (!$cfg) $out(false, 'لم تُضبَط إعدادات البريد بعد — افتح «إعدادات البريد» وأدخل إيميل المدرسة وكلمة مرور التطبيق', ['settings' => $settingsUrl]);
$out(false, 'تعذّر الإرسال: ' . ($err ?: 'خطأ غير معروف'), ['settings' => $settingsUrl]);
