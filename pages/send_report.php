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
if ((int)$f['size'] > 600 * 1024 * 1024) $out(false, 'الملف كبير جداً (> 600MB)');
$data = (string)file_get_contents($f['tmp_name']);
if (substr($data, 0, 4) !== '%PDF') $out(false, 'الملف ليس PDF');
$name = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string)($f['name'] ?: 'document.pdf'));
if (substr($name, -4) !== '.pdf') $name .= '.pdf';

// اسم المدرسة المختارة (عبرا…) لا الاسم العام للمؤسسة — إلا في وضع «كل المدارس»
$schoolName = (!isAllSchools() && currentSchool()) ? (string)(currentSchool()['name_ar'] ?: currentSchool()['name_fr']) : (string)getSetting('school_name_ar', '');
// 📦 (2026-10-19 «ملف كتير كبير — بدون أخطاء ويوصل بسرعة»): الملف يُرفَع على الموقع ويُبعَث رابط تنزيل آمن (90 يوماً) — الكبير بالرابط فقط، الصغير مرفق + رابط
$size = strlen($data);
[$shOk, $shTok] = msaShareFile($name, $data, 'application/pdf', $to);
$big = $size > MSA_SHARE_ATTACH_MAX;
if ($big && !$shOk) $out(false, 'الملف كبير (' . msaHumanSize($size) . ') ولم ينجح رفعه على الموقع: ' . $shTok . ' — حاول مرّة ثانية');
$link = $shOk ? msaShareUrl($shTok) : '';
$intro = $body !== '' ? $body : ($big ? 'المستند المطلوب جاهز للتنزيل من الرابط أدناه.' : 'مرفق المستند المطلوب بصيغة PDF.');
$text = $intro . "\n\n"
      . ($link !== '' ? ('📎 ' . ($big ? 'تنزيل الملف' : 'الملف مرفق — ويمكن تنزيله أيضاً من هنا') . ' (' . $name . ' — ' . msaHumanSize($size) . "):\n" . $link . "\n(الرابط صالح " . MSA_SHARE_DAYS . " يوماً)\n\n") : '')
      . $schoolName;
$att = $big ? [] : [['name' => $name, 'data' => $data, 'mime' => 'application/pdf']];
$settingsUrl = BASE_URL . 'pages/email_settings.php';

// 📧 (2026-10-12) الباب الواحد: إيميل البرنامج (MSA Payroll ‹noreply@الموقع›) أو إيميل المدرسة حسب خيار المدرسة بصفحة المدارس، مع بديل تلقائي
$schoolId = (!isAllSchools() && currentSchool()) ? (int)currentSchool()['id'] : null;
[$ok, $err, $via, $fromAddr] = msaSendMail($to, $subject, $text, $att, false, $schoolId, $schoolName !== '' ? $schoolName : '');
$logUrl = BASE_URL . 'pages/mail_log.php';
if ($ok && $via === 'dup') $out(true, 'هذه الرسالة نفسها أُرسلت إلى ' . $to . ' قبل أقلّ من دقيقتين — لم تُكرَّر. تابع وصولها بسجلّ الإيميلات.', ['log' => $logUrl, 'dup' => 1]);
if ($ok) {
    if ($shOk && !empty($GLOBALS['msa_mail_log_id'])) { try { getDB()->prepare("UPDATE mail_log SET share_token = ? WHERE id = ?")->execute([$shTok, $GLOBALS['msa_mail_log_id']]); } catch (Throwable $e) {} }
    logAudit('send_report_email', 'settings', 0, null, ['to' => $to, 'subject' => $subject, 'file' => $name, 'size' => $size, 'link' => $big, 'via' => $via, 'from' => $fromAddr]);
    $out(true, 'أُرسل إلى ' . $to . ($big ? ' برابط تنزيل الملف ' . $name . ' (' . msaHumanSize($size) . ')' : ' مع الملف ' . $name . ' مرفقاً') . ' — حالة الوصول والتنزيل تظهر بسجلّ الإيميلات.', ['log' => $logUrl]);
}
$out(false, 'تعذّر الإرسال: ' . ($err ?: 'خطأ غير معروف'), ['settings' => $settingsUrl]);
