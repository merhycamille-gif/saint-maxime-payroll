<?php
/**
 * نبض خلفي خفيف (يستدعيه footer.php كل 4 ثوانٍ ما دام تجهيز 2026-2027 التلقائي غير مكتمل):
 * يشغّل دفعة من healOpenYear2627_20260912 ويرجع حالتها JSON — لا يعرض شيئاً ولا يغيّر الجلسة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$s = healOpenYear2627_20260912(6.0);
echo json_encode($s === null ? ['done' => true] : ['done' => false, 'stage' => $s['stage'], 'grades' => $s['grades'], 'seen' => $s['seen'], 'transport' => $s['transport'], 'abra' => $s['abra']], JSON_UNESCAPED_UNICODE);
