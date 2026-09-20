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
// 💰 عمود المستحق بثلاث حالات (2026-09-19): none / blank / amount — عرض فقط
if (isset($_GET['due_mode']) && in_array((string)$_GET['due_mode'], ['none', 'blank', 'amount'], true)) $_SESSION['due_col_mode'] = (string)$_GET['due_mode'];
// 👨‍👩‍👧➕ عمود «الصافي + التعويض العائلي» بثلاث حالات (2026-09-20): none / blank / amount — عرض فقط
if (isset($_GET['netfam_mode']) && in_array((string)$_GET['netfam_mode'], ['none', 'blank', 'amount'], true)) $_SESSION['netfam_col_mode'] = (string)$_GET['netfam_mode'];
$_SESSION['salary_comp'] = $comp;
header('Location: ' . safeBackUrl());
exit;
