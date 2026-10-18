<?php
/**
 * 🔁 نقطة المزامنة الخلفية على الكمبيوتر (تُستدعى تلقائياً من الهيدر محلياً). للمدير فقط. يعيد JSON.
 * (2026-10-18) صارت دورة باتّجاهين (localSyncCycle): كل 5 دقائق — ما تغيّر على الكمبيوتر يُرفع للأونلاين، ثم إن تغيّر الأونلاين
 * (بصمة sync_version.php) أو حان الموعد الدوري تُسحب النسخة الكاملة فيتطابق المكانان. force=1 = الآن.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/local_sync.php';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn() || !isAdmin()) { echo json_encode(['ok' => false, 'msg' => 'admin only']); exit; }
if (!localSyncEnabled()) { echo json_encode(['ok' => false, 'msg' => 'disabled']); exit; }
$force = isset($_GET['force']);
if (!$force && !localSyncDue() && !localSyncCheckDue()) { echo json_encode(['ok' => true, 'msg' => 'not due', 'status' => localSyncStatusText()]); exit; }
session_write_close(); // لا نحجز الجلسة طوال المزامنة
$res = localSyncCycle($force);
$res['status'] = localSyncStatusText();
echo json_encode($res, JSON_UNESCAPED_UNICODE);
