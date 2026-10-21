<?php
/**
 * 🔁 واجهة المزامنة الثنائية على الأونلاين (2026-10-18 «يبقى كل شي نفس الشي بالمكانين الأونلاين والكمبيوتر، وكل شي تلقائي») — للمدير العام فقط.
 * تستدعيها نسخة الكمبيوتر (includes/local_sync.php) بعد الدخول:
 *   GET  ?action=tables                      → الجداول الحقيقية ومفاتيحها وأعمدتها
 *   POST ?action=hashes&table=T  pks[]=…     → بصمة كل صفّ (أو للمفاتيح المطلوبة فقط)
 *   POST ?action=apply  (JSON {table, upserts:[{col:val}], deletes:[[pk…]]}) → نسخة احتياطية للصفوف المتأثرة (_sync_bk_…) ثم REPLACE/DELETE بمعاملة واحدة
 * حدود أمان: دفعة ≤ 1000 صفّ؛ لا حذف جماعي (> 20٪ من الجدول) — يُرفض ويُسجَّل.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sync_common.php';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!isLoggedIn() || !isSuperAdmin()) { http_response_code(403); echo json_encode(['ok' => false, 'msg' => 'superadmin only']); exit; }
$db = getDB(); $schema = DB_NAME;
$act = (string)($_GET['action'] ?? '');
$out = ['ok' => false, 'msg' => 'bad action'];
try {
    if ($act === 'tables') {
        $tables = [];
        foreach (syncRealTables($db, $schema) as $t) { $m = syncTableMeta($db, $schema, $t); $n = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); $tables[$t] = ['pk' => $m['pk'], 'cols' => $m['cols'], 'auto' => $m['auto'], 'n' => $n]; }
        $out = ['ok' => true, 'tables' => $tables, 'fp' => (string)msaDataFingerprint(true)];
    } elseif ($act === 'hashes') {
        $t = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['table'] ?? ''));
        if ($t === '' || !in_array($t, syncRealTables($db, $schema), true)) throw new RuntimeException('table?');
        $meta = syncTableMeta($db, $schema, $t);
        $pks = isset($_POST['pks']) ? array_values(array_map('strval', (array)$_POST['pks'])) : null;
        $out = ['ok' => true, 'table' => $t, 'hashes' => syncRowHashes($db, $schema, $t, $meta, $pks)];
    } elseif ($act === 'rows') {
        // ⚡ (2026-10-21 السحب الجزئي من الكمبيوتر/سيرفر المدرسة) صفوف كاملة بمفاتيح محدّدة — للصفوف المختلفة فقط بدل الملف الكامل 47MB
        $t = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['table'] ?? ''));
        if ($t === '' || !in_array($t, syncRealTables($db, $schema), true)) throw new RuntimeException('table?');
        $meta = syncTableMeta($db, $schema, $t);
        $pks = array_values(array_map('strval', (array)($_POST['pks'] ?? $_GET['pks'] ?? [])));
        if (count($pks) > 1000) throw new RuntimeException('batch too large');
        $out = ['ok' => true, 'table' => $t, 'rows' => $pks ? syncRowsByPk($db, $schema, $t, $meta, $pks) : []];
    } elseif ($act === 'apply') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) throw new RuntimeException('json?');
        $t = preg_replace('/[^a-z0-9_]/i', '', (string)($body['table'] ?? ''));
        if ($t === '' || !in_array($t, syncRealTables($db, $schema), true)) throw new RuntimeException('table?');
        $meta = syncTableMeta($db, $schema, $t);
        if (!$meta['pk']) throw new RuntimeException('no primary key');
        $ups = array_values(array_filter((array)($body['upserts'] ?? []), 'is_array'));
        $dels = array_values((array)($body['deletes'] ?? []));
        if (count($ups) + count($dels) > 1000) throw new RuntimeException('batch too large');
        $n = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        if ($dels && $n > 0 && count($dels) > max(50, (int)($n * 0.2))) throw new RuntimeException('mass delete refused (' . count($dels) . " of $n)");
        if ($t === 'settings') { $ups = array_values(array_filter($ups, fn($r) => !syncSettingsKeyIgnored((string)($r['key'] ?? '')))); $dels = array_values(array_filter($dels, fn($d) => !syncSettingsKeyIgnored((string)((array)$d)[0]))); }
        $res = syncApplyChanges($db, $schema, $t, $meta, $ups, $dels);
        $out = $res + ['table' => $t, 'fp' => (string)msaDataFingerprint(true)];
        try { logAudit('sync_apply', $t, 0, null, ['ins' => $res['ins'] ?? 0, 'del' => $res['del'] ?? 0, 'bk' => $res['bk'] ?? '']); } catch (Throwable $e) {}
    }
} catch (Throwable $e) { http_response_code(400); $out = ['ok' => false, 'msg' => $e->getMessage()]; }
echo json_encode($out, JSON_UNESCAPED_UNICODE);
