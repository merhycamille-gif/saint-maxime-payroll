<?php
/**
 * 🔁 (2026-10-18 «يعني أنا لازم ضلّ فاتح البرنامج على الكمبيوتر حتى يتعدّل؟» ⇒ لا) دورة المزامنة باتّجاهين من سطر الأوامر —
 * تشغّلها مهمّة مجدولة بويندوز كل 5 دقائق (tools/sync_cycle_hidden.vbs ⇒ بلا نافذة) على كمبيوتر المدرسة فقط:
 * الكمبيوتر شغّال = المزامنة شغّالة، حتى لو البرنامج مسكّر. تحتاج XAMPP (MySQL) شغّالاً وثوابت ONLINE_SYNC_* بـconfig المحلي.
 * على الأونلاين أو بلا الثوابت: لا تعمل شيئاً.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/local_sync.php';
if (!defined('ONLINE_SYNC_URL') || !defined('ONLINE_SYNC_USER') || !defined('ONLINE_SYNC_PASS') || localSyncMysqlExe() === '' || !function_exists('curl_init')) { echo "sync not configured here\n"; exit(0); }
$force = in_array('--force', $argv ?? [], true);
if (!$force && !localSyncDue() && !localSyncCheckDue()) { echo "not due\n"; exit(0); }
$res = localSyncCycle($force);
echo ($res['ok'] ? 'OK ' : 'FAIL ') . ($res['msg'] ?? '') . "\n";
exit($res['ok'] ? 0 : 1);
