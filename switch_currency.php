<?php
// مبدّل عرض العملة (ليرة/دولار/الاثنين) — يُخزَّن بالجلسة ويسري على كل التقارير والملفات والإفادات.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$c = $_GET['currency'] ?? 'both';
$_SESSION['display_currency'] = in_array($c, ['both', 'lbp', 'usd'], true) ? $c : 'both';
header('Location: ' . safeBackUrl());
exit;
