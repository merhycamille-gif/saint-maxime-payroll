<?php
/**
 * 📲 (2026-11-20) إرسال أي مستند من البرنامج بالواتساب مباشرة (ملف PDF مرفق) من رقم المؤسّسة — includes/wa_cloud.php.
 * الصفحة تولّد الـPDF بالمتصفّح (نفس ملف الإيميل/الحفظ) وترسله هنا POST مع الرقم واسم المستند. يرجّع JSON {ok, msg}.
 * محميّ بـCSRF + تسجيل الدخول + حساب يقدر يعدّل (حسابات القراءة تبقى على مشاركة واتساب من جهازها). كل إرسال يُسجَّل بسجلّ الإرسال.
 *   POST: csrf · to · title · pdf (ملف)   |   GET ?status=1 ⇒ {enabled, template, from}
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/wa_cloud.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
$out = function (bool $ok, string $msg, array $extra = []) { echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['status'])) {
    if (!canEdit()) $out(false, 'غير مسموح');
    $out(true, '', ['enabled' => msaWaEnabled(), 'template' => msaWaEnabled() ? msaWaTemplateStatus() : '', 'from' => msaWaCfg()['from']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(false, 'طلب غير صالح');
if (!verifyCsrf($_POST['csrf'] ?? '')) $out(false, 'رمز الأمان غير صحيح — أعد تحميل الصفحة وحاول مجدداً');
if (!canEdit()) $out(false, 'غير مسموح — حساب قراءة فقط');
if (!msaWaEnabled()) $out(false, 'الإرسال المباشر بالواتساب غير مفعَّل', ['fallback' => true]);

$toRaw = trim((string)($_POST['to'] ?? '')); $to = msaWaPhone($toRaw);
if ($to === '') $out(false, 'رقم الواتساب غير صحيح — اكتبه مثل 03 123 456 أو +961 70 123 456');
$title = trim((string)($_POST['title'] ?? '')) ?: 'Document';
$f = $_FILES['pdf'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || (int)$f['size'] < 300) $out(false, 'لم يصل ملف الـPDF — أعد المحاولة');
$data = (string)file_get_contents($f['tmp_name']);
if (substr($data, 0, 4) !== '%PDF') $out(false, 'الملف ليس PDF');
$name = trim((string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', (string)($f['name'] ?: 'document.pdf')));   // الاسم كما هو (عربي/فرنسي) — يظهر للمستلم فوق الملف
if ($name === '' || $name === '.pdf') $name = 'document.pdf';
if (mb_strtolower(mb_substr($name, -4)) !== '.pdf') $name .= '.pdf';
$name = mb_substr($name, 0, 200, 'UTF-8');

$st = msaWaTemplateStatus();
if ($st !== 'APPROVED') $out(false, $st === 'PENDING' ? 'قالب الرسالة ما زال قيد المراجعة عند واتساب — جرّب بعد قليل' : ($st === 'REJECTED' ? 'واتساب رفض قالب الرسالة — يلزم تعديله' : 'تعذّر التأكّد من قالب الرسالة عند واتساب — أعد المحاولة'), ['fallback' => true, 'template' => $st]);

// لا تكرار: نفس الملف لنفس الرقم خلال دقيقتين
ensureMailLogTable(); $db = getDB();
$schoolId = (!isAllSchools() && currentSchool()) ? (int)currentSchool()['id'] : null;
$dup = sha1('wa|' . $to . '|' . $name . '|' . strlen($data) . '|' . md5($data));
try {
    $q = $db->prepare("SELECT id FROM mail_log WHERE dup_key = ? AND status <> 'failed' AND created_at >= ? LIMIT 1"); $q->execute([$dup, date('Y-m-d H:i:s', time() - 120)]);
    if ($q->fetchColumn()) $out(true, 'هذا الملف نفسه أُرسل إلى ' . msaWaPretty($to) . ' قبل أقلّ من دقيقتين — لم يُكرَّر.', ['dup' => true]);
} catch (Throwable $e) {}

session_write_close();
$r = msaWaSendDocument($to, $title, $data, $name);
$logId = date('ymdHis') . substr(bin2hex(random_bytes(6)), 0, 12);
try {
    $db->prepare("INSERT INTO mail_log (id, created_at, sent_by, school_id, to_addr, subject, file_name, via, provider_id, status, error, dup_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$logId, date('Y-m-d H:i:s'), (string)($_SESSION['username'] ?? ''), $schoolId, msaWaPretty($to), mb_substr($title, 0, 500), mb_substr($name, 0, 255),
                  'whatsapp', $r['ok'] ? mb_substr($r['id'], 0, 80) : null, $r['ok'] ? 'wa_sent' : 'failed', $r['ok'] ? null : $r['err'], $r['ok'] ? $dup : null]);
} catch (Throwable $e) {}
if ($r['ok']) {
    try { logAudit('send_report_whatsapp', 'settings', 0, null, ['to' => $to, 'title' => $title, 'file' => $name, 'size' => strlen($data), 'wamid' => $r['id']]); } catch (Throwable $e) {}
    $out(true, 'أُرسل الملف ' . $name . ' بالواتساب إلى ' . msaWaPretty($to) . '.', ['log' => BASE_URL . 'pages/mail_log.php', 'to' => msaWaPretty($to)]);
}
$out(false, 'ما انبعت: ' . $r['err'], ['fallback' => true]);
