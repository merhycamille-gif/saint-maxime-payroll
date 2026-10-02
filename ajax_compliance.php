<?php
/**
 * 🚀 تحديث تقرير المخالفات للوحة القيادة بالخلفية (2026-10-02 «بدي سرعة البرنامج صاروخ ما فيي انطر»):
 * اللوحة تعرض آخر تقرير محفوظ فوراً، وتطلب من هنا التقرير المحدَّث (يُبنى فقط إن تغيّرت الداتا) ويُرجَع HTML الخانة.
 * الجلسة تُقفَل للقراءة قبل البناء حتى لا ينتظر المستخدم إن انتقل لصفحة ثانية أثناء الفحص.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payroll_calculator.php';
require_once __DIR__ . '/includes/compliance.php';
requireLogin();
header('Content-Type: text/html; charset=utf-8');
if (!canEdit()) exit;
csrfField(); // الرمز موجود بالجلسة قبل إقفالها (أزرار «موافق/لا» داخل الخانة)
session_write_close();
@set_time_limit(120);
$rep = complianceBuildCached(getDB());
renderCompliancePending($rep, true);
