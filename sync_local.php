<?php
/**
 * 🔁 نقطة المزامنة الخلفية: بيانات الأونلاين → نسخة الكمبيوتر (تُستدعى تلقائياً من الهيدر محلياً).
 * تعمل فقط على الكمبيوتر (localSyncEnabled) وللمدير. يعيد JSON.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/local_sync.php';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn() || !isAdmin()) { echo json_encode(['ok' => false, 'msg' => 'admin only']); exit; }
if (!localSyncEnabled()) { echo json_encode(['ok' => false, 'msg' => 'disabled']); exit; }
$force = isset($_GET['force']);
if (!$force && !localSyncDue()) { echo json_encode(['ok' => true, 'msg' => 'not due', 'status' => localSyncStatusText()]); exit; }
session_write_close(); // لا نحجز الجلسة طوال المزامنة
$res = localSyncRun();
$res['status'] = localSyncStatusText();
echo json_encode($res, JSON_UNESCAPED_UNICODE);
