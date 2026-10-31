<?php
/**
 * 👁️ معاينة «كما تراه المدرسة» (2026-10-31 «فزعان أبعت… لازم شوفها أنا قبل»):
 *   key_preview.php?u=<id حساب قراءة فقط>&csrf=… ⇒ المدير يدخل بنفس جلسته كذلك الحساب (قراءة فقط، نفس الصلاحيات والمدارس والصفحات تماماً)
 *   مع شريط ثابت أعلى كل صفحة «أنت تعاين… رجوع للمدير». key_preview.php?back=1 ⇒ استرجاع جلسة المدير كما كانت.
 *   لا يغيّر أي بيانات: لقطة جلسة المدير تُحفَظ داخل الجلسة (_impersonator) وتُسترجَع.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
header('Cache-Control: no-store');

// رجوع للمدير
if (isset($_GET['back'])) {
    if (!empty($_SESSION['_impersonator']) && is_array($_SESSION['_impersonator'])) {
        $snap = $_SESSION['_impersonator'];
        $_SESSION = $snap;
        session_regenerate_id(true);
        $_SESSION['flash_success'] = 'Retour au compte administrateur / رجعت لحساب المدير';
        header('Location: ' . BASE_URL . 'pages/school_access.php'); exit;
    }
    header('Location: ' . BASE_URL . 'index.php'); exit;
}

requireLogin();
if (!isAdmin() || !empty($_SESSION['_impersonator'])) { $_SESSION['flash_error'] = 'Aperçu : administrateur seulement / المعاينة للمدير فقط'; header('Location: ' . BASE_URL . 'index.php'); exit; }
if (!verifyCsrf((string)($_GET['csrf'] ?? ''))) { $_SESSION['flash_error'] = 'Session expirée — réessayez / انتهت الجلسة — أعد المحاولة'; header('Location: ' . BASE_URL . 'pages/school_access.php'); exit; }
$st = getDB()->prepare("SELECT * FROM users WHERE id = ? AND role = 'viewer'"); $st->execute([(int)($_GET['u'] ?? 0)]); $v = $st->fetch();
if (!$v) { $_SESSION['flash_error'] = 'Compte introuvable / الحساب غير موجود'; header('Location: ' . BASE_URL . 'pages/school_access.php'); exit; }
if (!(int)$v['is_active']) { $_SESSION['flash_error'] = 'Ce compte est désactivé — activez-le d\'abord pour l\'aperçu / الحساب موقوف — فعّله أوّلاً للمعاينة'; header('Location: ' . BASE_URL . 'pages/school_access.php#s' . (int)$v['school_id']); exit; }

$snap = $_SESSION; unset($snap['flash_error'], $snap['flash_success']);
if (function_exists('logAudit')) logAudit('preview_as', 'users', (int)$v['id'], null, (string)$v['username']);
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['user_id'] = $v['id'];
$_SESSION['username'] = $v['username'];
$_SESSION['full_name'] = $v['full_name'];
$_SESSION['role'] = $v['role'];
$_SESSION['school_id'] = $v['school_id'];
$_SESSION['active_school_id'] = (int)$v['school_id'];
$_SESSION['lang'] = $snap['lang'] ?? 'fr';
$_SESSION['_impersonator'] = $snap;
header('Location: ' . BASE_URL . 'index.php'); exit;
