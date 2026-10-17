<?php
// ➕ (2026-10-17 «اتفقنا بكل التقارير يكون عندي خيار للمجاميع: كلها أو كل سطر كمان») مبدّل خياري المجاميع بالجلسة:
//    rowsum=1 عمود «المجموع» بآخر كل سطر · group=1 سطر مجموع تحت كل فئة. يسري على كل التقارير والكشوف (totals.js).
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (isset($_GET['rowsum'])) $_SESSION['totals_rowsum'] = $_GET['rowsum'] === '1' ? 1 : 0;
if (isset($_GET['group']))  $_SESSION['totals_group']  = $_GET['group'] === '1' ? 1 : 0;
header('Location: ' . safeBackUrl());
exit;
