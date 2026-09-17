<?php
// مبدّل مكوّنات «الراتب المركّب» (الأساس + أيّ من: الإضافي/المكافأة-المساعدة) + حالة عمود النقل — يُخزَّن بالجلسة.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$comp = array_values(array_intersect((array)($_GET['comp'] ?? []), ['extra', 'aide', 'transport', 'transport_blank']));
// 🚌 عمود النقل بثلاث حالات (2026-09-17): none = غير موجود · blank = موجود بلا مبلغ · amount = موجود مع المبلغ
if (isset($_GET['transport_mode'])) {
    $comp = array_values(array_diff($comp, ['transport', 'transport_blank']));
    $tm = (string)$_GET['transport_mode'];
    if ($tm === 'amount')     $comp[] = 'transport';
    elseif ($tm === 'blank')  $comp[] = 'transport_blank';
}
$_SESSION['salary_comp'] = $comp;
header('Location: ' . safeBackUrl());
exit;
