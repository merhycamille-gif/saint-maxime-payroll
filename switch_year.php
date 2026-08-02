<?php
/**
 * تبديل السنة الدراسية الفعّالة (تُحفظ بالجلسة، تنطبق على كل الصفحات)
 * Switch active school year
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$sy = $_GET['school_year'] ?? '';
$valid = ($sy === 'all' || preg_match('/^\d{4}-\d{4}$/', $sy));
if ($valid) {
    $_SESSION['active_school_year'] = $sy;
}

$back = safeBackUrl();
// 🔴 صفحات تثبّت السنة داخل رابطها (البطاقة السنوية/التقارير/النماذج: ?school_year=...):
// العودة للرابط القديم كانت تفرض السنة القديمة فتبدو القائمة العلوية «ما بتغيّر شي».
// نبدّل السنة في الرابط نفسه بالسنة المختارة، وعند «كل السنين» نشيل الوسيط ليتبع الجلسة.
if ($valid && preg_match('/[?&]school_year=/', $back)) {
    if ($sy === 'all') {
        $back = preg_replace('/([?&])school_year=[^&]*&?/', '$1', $back);
        $back = rtrim($back, '?&');
    } else {
        $back = preg_replace('/([?&]school_year=)[^&]*/', '${1}' . urlencode($sy), $back);
    }
}
header('Location: ' . $back);
exit;
