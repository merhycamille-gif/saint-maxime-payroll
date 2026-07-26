<?php
// مبدّل مكوّنات «الراتب المركّب» (الأساس + أيّ من: الإضافي/المكافأة-المساعدة/النقل) — يُخزَّن بالجلسة.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$comp = $_GET['comp'] ?? [];
$_SESSION['salary_comp'] = array_values(array_intersect((array)$comp, ['extra', 'aide', 'transport']));
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? BASE_URL . 'index.php'));
exit;
