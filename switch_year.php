<?php
/**
 * تبديل السنة الدراسية الفعّالة (تُحفظ بالجلسة، تنطبق على كل الصفحات)
 * Switch active school year
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$sy = $_GET['school_year'] ?? '';
if ($sy === 'all' || preg_match('/^\d{4}-\d{4}$/', $sy)) {
    $_SESSION['active_school_year'] = $sy;
}

$back = safeBackUrl();
header('Location: ' . $back);
exit;
