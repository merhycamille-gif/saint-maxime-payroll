<?php
/**
 * (2026-09-11) «صفحة المكافآت والمساعدات بدها ترتيب لأن مش واضح كيفية استعمالها»
 * الصفحة المستقلة أُلغيت: المكان الواحد للمكافآت والمساعدات والنقل صار تبويب
 * «🎁 المكافآت والمساعدات والنقل» بملف الأستاذ (pages/employees.php?action=edit&id=…&tab=bonuses).
 * تبقى هذه الصفحة تحويلاً فقط حتى لا يتعطّل أي رابط قديم.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$employeeId = (int)($_GET['employee_id'] ?? 0);
header('Location: ' . BASE_URL . 'pages/employees.php' . ($employeeId > 0 ? '?action=edit&id=' . $employeeId . '&tab=bonuses' : ''));
exit;
