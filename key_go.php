<?php
/**
 * 🔑🖥️ رابط الدخول لمرّة واحدة الذي يفتحه ملف الأيقونة بالمتصفّح (2026-10-31): key_go.php?k=<selector>&n=<nonce> ⇒ جلسة حساب المدرسة ⇒ لوحة القيادة.
 * منتهٍ/مستهلَك ⇒ صفحة الدخول العادية برسالة.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/school_access.php';
require_once __DIR__ . '/includes/key_access.php';
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$user = kaConsumeNonce((string)($_GET['k'] ?? ''), (string)($_GET['n'] ?? ''));
if (!$user) {
    $_SESSION['flash_error'] = 'Lien d\'ouverture expiré — recliquez sur l\'icône du bureau / انتهت صلاحية رابط الفتح — اكبسوا على الأيقونة من جديد';
    header('Location: ' . BASE_URL . 'login.php'); exit;
}
if (isLoggedIn() && (int)($_SESSION['user_id'] ?? 0) !== (int)$user['id']) { $_SESSION = []; }
kaOpenSession($user, (int)$user['_key_id'], 'exe');
header('Location: ' . BASE_URL . 'index.php'); exit;
