<?php
/**
 * 📧🔁 (2026-10-16 «مبارح كان عم يبعت إيميل») بوّابة إرسال الإيميل عبر الموقع لنسخة الكمبيوتر:
 * نسخة الكمبيوتر (XAMPP) بلا بريد، فتدخل إلى الموقع بحساب المزامنة (ONLINE_SYNC_USER/PASS كما بـlocal_sync.php)
 * وترسل الرسالة هنا بـJSON (POST)، والموقع يبعتها من إيميل البرنامج ‹no-reply@msapayroll.com› بـprogramMailSend().
 * للمدير فقط. الردّ JSON: {ok, err, from}.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn() || !isAdmin()) { http_response_code(401); echo json_encode(['ok' => false, 'err' => 'login']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'err' => 'POST only']); exit; }
$raw = file_get_contents('php://input');
if (strlen($raw) > 40 * 1024 * 1024) { echo json_encode(['ok' => false, 'err' => 'الرسالة أكبر من 40MB']); exit; }
$in = json_decode($raw, true);
if (!is_array($in)) { echo json_encode(['ok' => false, 'err' => 'طلب غير مفهوم']); exit; }
if (!programMailAvailable()) { echo json_encode(['ok' => false, 'err' => 'خادم الموقع بلا بريد']); exit; }
$to = $in['to'] ?? '';
$tos = array_values(array_filter(array_map('trim', is_array($to) ? $to : explode(',', (string)$to)), fn($t) => filter_var($t, FILTER_VALIDATE_EMAIL)));
if (!$tos) { echo json_encode(['ok' => false, 'err' => 'عنوان المستلِم غير صالح']); exit; }
$att = [];
foreach ((array)($in['attachments'] ?? []) as $a) {
    $data = base64_decode((string)($a['data'] ?? ''), true);
    if ($data === false || $data === '') continue;
    $att[] = ['name' => (string)($a['name'] ?? 'file'), 'data' => $data, 'mime' => (string)($a['mime'] ?? 'application/octet-stream')];
}
[$ok, $err] = programMailSend($tos, (string)($in['subject'] ?? ''), (string)($in['body'] ?? ''), $att, !empty($in['isHtml']), (string)($in['fromName'] ?? ''), (string)($in['replyTo'] ?? ''));
echo json_encode(['ok' => $ok, 'err' => $err, 'from' => programMailAddress()], JSON_UNESCAPED_UNICODE);
