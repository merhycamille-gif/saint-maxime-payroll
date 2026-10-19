<?php
/**
 * 📦 (2026-10-19 «أوقات بدّي أبعت ملف كتير كبير — لازم طريقة بدون أخطاء ويوصل بسرعة») بوّابة رفع ملف مشارَك إلى الموقع:
 * نسخة الكمبيوتر تدخل بحساب المزامنة (msaOnlineRequest) وترفع الملف هنا (multipart: file, to) فيُخزَّن بـuploads/share
 * ويُرجَع رمز الرابط {ok, token, url}. للمدير فقط. يعمل على الموقع فقط (الملف لازم يكون حيث الرابط).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn() || !isAdmin()) { http_response_code(401); echo json_encode(['ok' => false, 'err' => 'login']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'err' => 'POST only']); exit; }
if (!msaIsOnlineHost()) { echo json_encode(['ok' => false, 'err' => 'هذه النسخة ليست الموقع']); exit; }
$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || (int)$f['size'] < 1) { echo json_encode(['ok' => false, 'err' => 'لم يصل الملف (' . (int)($f['error'] ?? -1) . ')']); exit; }
$name = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string)($f['name'] ?: 'document.pdf'));
$data = (string)file_get_contents($f['tmp_name']);
$mime = (string)($f['type'] ?: 'application/octet-stream'); if (substr($data, 0, 4) === '%PDF') $mime = 'application/pdf';
[$ok, $tok] = msaShareStoreLocal($name, $data, $mime, trim((string)($_POST['to'] ?? '')));
echo json_encode($ok ? ['ok' => true, 'token' => $tok, 'url' => msaShareUrl($tok)] : ['ok' => false, 'err' => $tok], JSON_UNESCAPED_UNICODE);
