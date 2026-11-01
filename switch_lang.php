<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$lang = $_GET['lang'] ?? 'fr';
$_SESSION['lang'] = in_array($lang, ['fr', 'ar']) ? $lang : 'fr';
// 🌐🔴 (2026-11-01) لغة البرنامج هي الأساس لكل شيء (اتجاه + لغة التقارير تلقائياً من <html lang> بـdoclang.js) — الكوكيز القديمة لحبّة التقارير تُمحى
setcookie('msa_rl', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
setcookie('msa_rs', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
header('Location: ' . safeBackUrl());
exit;
