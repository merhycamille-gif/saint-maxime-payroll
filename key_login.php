<?php
/**
 * 🔑🖥️ أيقونة الكمبيوتر تتكلّم هنا (2026-10-31): POST selector+secret+machine(+label) ⇒ نصّ «OK\n<رابط دخول لمرّة واحدة>» أو «ERR\n<fr>\n<ar>».
 * لا جلسة ولا كوكي هنا؛ المنطق كلّه بـincludes/key_access.php. لحسابات «قراءة فقط» حصراً.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/school_access.php';
require_once __DIR__ . '/includes/key_access.php';
header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { echo "ERR\nMéthode non autorisée\n\n"; exit; }
[$ok, $a, $b] = kaVerifyExe((string)($_POST['selector'] ?? ''), (string)($_POST['secret'] ?? ''), (string)($_POST['machine'] ?? ''), trim((string)($_POST['label'] ?? '')));
echo $ok ? "OK\n$a\n" : "ERR\n$a\n$b\n";
