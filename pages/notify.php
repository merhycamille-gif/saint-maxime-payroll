<?php
/**
 * 📣 إشعار بريدي عام من إيميل البرنامج (MSA Payroll) — للمدير العام فقط، POST مع csrf.
 * يستعمله المراقب المحلي (مواعيد VFS) وأي أداة أخرى: to, subject, body ⇒ JSON {ok,msg}.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
$out = function (bool $ok, string $msg) { echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(false, 'POST only');
if (!verifyCsrf($_POST['csrf'] ?? '')) $out(false, 'csrf');
if (!isSuperAdmin()) $out(false, 'superadmin only');
$tos = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['to'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
if (!$tos) $out(false, 'no valid recipient');
$subject = trim((string)($_POST['subject'] ?? '')) ?: 'إشعار من MSA Payroll';
$body = (string)($_POST['body'] ?? '');
[$ok, $err, $via, $from] = msaSendMail($tos, $subject, $body, [], false, null, 'MSA Payroll');
logAudit('notify_mail', 'settings', 0, null, ['to' => $tos, 'subject' => $subject, 'ok' => $ok, 'via' => $via]);
$out($ok, $ok ? "sent via $via from $from" : $err);
