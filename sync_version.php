<?php
/**
 * 🔁 (2026-10-18 «بدي تلقائياً إذا غيّرت شي أونلاين يتغيّر كمان على الكمبيوتر») بصمة بيانات الأونلاين الحالية — للمدير فقط.
 * نسخة الكمبيوتر تسألها كل 5 دقائق (includes/local_sync.php): إن تغيّرت البصمة عن آخر مزامنة ⇒ تسحب النسخة الكاملة فوراً،
 * وإلا لا شيء. البصمة = msaDataFingerprint (آخر تعديل بالجداول + الأعداد + الإعدادات + اليوم) — خفيفة، بلا تحميل.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn() || !isAdmin()) { http_response_code(403); echo json_encode(['ok' => false, 'msg' => 'admin only']); exit; }
$fp = (string)msaDataFingerprint(true);
echo json_encode(['ok' => $fp !== '', 'fp' => $fp, 'at' => date('c')]);
