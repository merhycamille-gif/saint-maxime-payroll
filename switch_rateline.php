<?php
// 💱 (2026-10-17 «عم تحطّ براس كل تقرير سعر الصرف المعتمد — لازم يكون عندي خيار إنّو شيلها كمان»)
// مبدّل سطر «سعر الصرف المعتمد» بعناوين كل المستندات (تقارير/إفادات/بطاقات/PDF) — يُخزَّن بالجلسة ويسري على كل البرنامج.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$_SESSION['hide_rate_line'] = (($_GET['show'] ?? '1') === '0') ? 1 : 0;
header('Location: ' . safeBackUrl());
exit;
