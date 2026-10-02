<?php
/**
 * 🚀 نبض خلفي للفحص الشامل الدوري (2026-10-02 «كل البرنامج متل البرق»): يستدعيه footer.php بعد ظهور الصفحة ما دامت جولة
 * مستحقّة — دفعة من إعادة احتساب الأشهر المخزّنة ومقارنتها بالمحرّك الحيّ (monthStaleScanStep). كانت الدفعة تشتغل داخل كل صفحة
 * قبل عرضها. الجلسة تُقفَل للقراءة أوّلاً حتى لا ينتظر المستخدم إن انتقل لصفحة ثانية أثناء الدفعة.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_write_close();
@set_time_limit(60);
require_once __DIR__ . '/includes/payroll_calculator.php';
monthStaleScanStep(400);
echo json_encode(['due' => monthStaleScanDue()]);
