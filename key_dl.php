<?php
/**
 * 🔑📦 تنزيل ملف الأيقونة المُعدّ (2026-10-31): key_dl.php?t=<رمز> — للمدير صاحب الجلسة فقط، خلال 15 دقيقة، مرّة واحدة.
 * (الملف يُعدّ بصفحة «دخول المدارس» ويُسلَّم من هنا بـ<a download> لأنّ نافذة التطبيق المركَّب كانت تعرض ردّ النموذج الثنائي كصفحة)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/school_access.php';
require_once __DIR__ . '/includes/key_access.php';
requireLogin();
if (!isAdmin()) { $_SESSION['flash_error'] = 'Accès réservé à l\'administrateur / صلاحية المدير فقط'; header('Location: ' . BASE_URL . 'index.php'); exit; }
if (!kaSendStored((string)($_GET['t'] ?? ''))) {
    $_SESSION['flash_error'] = 'Fichier expiré — recréez l\'icône / انتهت صلاحية الملف — أعد إنشاء الأيقونة';
    header('Location: ' . BASE_URL . 'pages/school_access.php'); exit;
}
exit;
