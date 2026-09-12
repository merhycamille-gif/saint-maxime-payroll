<?php
/**
 * نبض خلفي خفيف (يستدعيه footer.php كل 4 ثوانٍ ما دام تجهيز 2026-2027 التلقائي غير مكتمل):
 * يشغّل دفعة من healOpenYear2627_20260912 (الجزء الأوّل) ثم healOpenYear2627b_20260912 (الجزء الثاني) ويرجع الحالة JSON.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$s = healOpenYear2627_20260912(6.0);
if ($s === null) $s = healOpenYear2627b_20260912(6.0);
echo json_encode($s === null ? ['done' => true] : ['done' => false, 'stage' => $s['stage'], 'grades' => $s['grades'] ?? 0, 'seen' => $s['seen'] ?? 0, 'transport' => $s['transport'] ?? 0, 'abra' => $s['abra'] ?? 0, 'lawshift' => $s['lawshift'] ?? 0], JSON_UNESCAPED_UNICODE);
