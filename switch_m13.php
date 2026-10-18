<?php
// 🎁 (2026-10-18 قراره «شهر التعويض… بتقارير المصاريف بيظهر كمجموع — حطّلي خيار إذا شهر التعويض أو لا») مبدّل الخيار بالجلسة:
//    on=1 مشمول (الافتراضي) · on=0 غير مشمول. يسري على المجاميع السنوية وكلفة المؤسسة والتقرير العام فقط — لا يمسّ الضمان/المالية/الصندوق.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (isset($_GET['on'])) $_SESSION['m13_reports'] = $_GET['on'] === '1' ? 1 : 0;
header('Location: ' . safeBackUrl());
exit;
