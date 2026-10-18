<?php
/**
 * 🔁 (2026-10-18 «لنفترض ما في إنترنت واضطريت اشتغل على الكمبيوتر — بس ترجع الإنترنت لازم الأونلاين يصير متل الكمبيوتر… وين ما بغيّر يبقى
 *     كل شي نفس الشي بالمكانين، وكل شي تلقائي») — المشترك بين واجهة الأونلاين (sync_api.php) ومحرّك الكمبيوتر (includes/local_sync.php):
 *   • الجداول الحقيقية (بلا جداول النسخ `_…` ولا جداول الكاش) ومفاتيحها الأساسية وأعمدتها بترتيب ثابت
 *   • بصمة كل صفّ = MD5 لكل أعمدته بنفس التعبير بالمكانين (الأعداد العشرية مدوّرة كي لا تختلف صياغة MySQL/MariaDB)
 * الدمج الثلاثي: أساس (آخر نسخة مسحوبة) · الكمبيوتر · الأونلاين ⇒ ما تغيّر على الكمبيوتر وحده يُرفع، وما تغيّر بالمكانين = تعارض يبقى الأونلاين ويُسجَّل.
 */

/** الجداول الحقيقية بقاعدة ما (بلا النسخ الاحتياطية والكاش) */
function syncRealTables(PDO $db, string $schema): array {
    $st = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'
                        AND table_name NOT LIKE '\\_%' AND table_name NOT IN ('month_stale_findings') ORDER BY table_name");
    $st->execute([$schema]);
    return array_values(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
}

/** أعمدة الجدول (مرتّبة) + المفتاح الأساسي + عمود الترقيم الذاتي */
function syncTableMeta(PDO $db, string $schema, string $table): array {
    $c = $db->prepare("SELECT column_name, data_type, column_key, extra FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position");
    $c->execute([$schema, $table]);
    $cols = []; $types = []; $auto = '';
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) { $cols[] = $r['column_name']; $types[$r['column_name']] = strtolower((string)$r['data_type']); if (stripos((string)$r['extra'], 'auto_increment') !== false) $auto = $r['column_name']; }
    $k = $db->prepare("SELECT column_name FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = 'PRIMARY' ORDER BY seq_in_index");
    $k->execute([$schema, $table]);
    $pk = array_values(array_map('strval', $k->fetchAll(PDO::FETCH_COLUMN)));
    return ['cols' => $cols, 'types' => $types, 'pk' => $pk, 'auto' => $auto];
}

/** تعبير SQL لبصمة الصفّ — نفسه بالمكانين */
function syncHashSql(array $meta): string {
    $parts = [];
    foreach ($meta['cols'] as $col) {
        if ($col === 'updated_at') continue;   // طابع تلقائي (ON UPDATE) — ليس بياناً؛ يُرفع مع الصفّ حين يتغيّر غيره
        $t = $meta['types'][$col] ?? '';
        $expr = in_array($t, ['float', 'double', 'real'], true) ? "ROUND(`$col`, 6)" : "`$col`";
        $parts[] = "IFNULL(CAST($expr AS CHAR), '<NULL>')";
    }
    return 'MD5(CONCAT_WS(CHAR(30), ' . implode(', ', $parts) . '))';
}
/** تعبير مفتاح الصفّ (أعمدة المفتاح مفصولة بـCHAR(31)) */
function syncPkSql(array $meta): string {
    return 'CONCAT_WS(CHAR(31), ' . implode(', ', array_map(fn($c) => "CAST(`$c` AS CHAR)", $meta['pk'])) . ')';
}

/** بصمات صفوف جدول: [pk => hash] (اختيارياً لمفاتيح محدّدة) */
function syncRowHashes(PDO $db, string $schema, string $table, array $meta, ?array $pks = null): array {
    if (!$meta['pk']) return [];
    $sql = "SELECT " . syncPkSql($meta) . " pk, " . syncHashSql($meta) . " h FROM `$schema`.`$table`";
    $params = [];
    if ($pks !== null) {
        if (!$pks) return [];
        $out = [];
        foreach (array_chunk($pks, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare($sql . " WHERE " . syncPkSql($meta) . " IN ($ph)"); $st->execute(array_values($chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['pk']] = $r['h'];
        }
        return $out;
    }
    $st = $db->query($sql); $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['pk']] = $r['h'];
    return $out;
}

/** صفوف كاملة بمفاتيح معيّنة: [pk => row] */
function syncRowsByPk(PDO $db, string $schema, string $table, array $meta, array $pks): array {
    $out = [];
    foreach (array_chunk($pks, 300) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT " . syncPkSql($meta) . " `__pk`, `$table`.* FROM `$schema`.`$table` WHERE " . syncPkSql($meta) . " IN ($ph)"); $st->execute(array_values($chunk));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = $r['__pk']; unset($r['__pk']); $out[$k] = $r; }
    }
    return $out;
}

/** مفاتيح إعدادات خاصّة بكل جهاز لا تُدمج (شفاءات وفحوص وأوقات) */
function syncSettingsKeyIgnored(string $key): bool {
    return preg_match('/^(heal_|compliance_pending_|month_stale_scan)/', $key) === 1 || preg_match('/_at$/', $key) === 1 || in_array($key, ['pdf_service_pass', 'pdf_service_disabled'], true);
}

/** تطبيق تغييرات على جدول: نسخة احتياطية للصفوف المتأثرة ثم REPLACE/DELETE بمعاملة واحدة. يعيد ['ok','ins','del','bk'] */
function syncApplyChanges(PDO $db, string $schema, string $table, array $meta, array $upserts, array $deletes): array {
    $pkSql = syncPkSql($meta);
    $pks = [];
    foreach ($upserts as $row) { $pks[] = implode(chr(31), array_map(fn($c) => (string)($row[$c] ?? ''), $meta['pk'])); }
    foreach ($deletes as $d) { $pks[] = implode(chr(31), array_map('strval', (array)$d)); }
    $bk = '';
    if ($pks) {
        $bk = '_sync_bk_' . substr($table, 0, 30) . '_' . date('Ymd_His');
        $db->exec("CREATE TABLE `$schema`.`$bk` LIKE `$schema`.`$table`");
        try { $db->exec("ALTER TABLE `$schema`.`$bk` MODIFY `" . ($meta['auto'] ?: $meta['pk'][0]) . "` " . syncColDef($db, $schema, $table, $meta['auto'] ?: $meta['pk'][0])); } catch (Throwable $e) {}
        foreach (array_chunk(array_unique($pks), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("INSERT INTO `$schema`.`$bk` SELECT * FROM `$schema`.`$table` WHERE $pkSql IN ($ph)"); $st->execute(array_values($chunk));
        }
    }
    $ins = 0; $del = 0;
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $db->beginTransaction();
    try {
        foreach ($upserts as $row) {
            $cols = array_values(array_intersect($meta['cols'], array_keys($row)));
            if (!$cols) continue;
            $st = $db->prepare("REPLACE INTO `$schema`.`$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
            $st->execute(array_map(fn($c) => $row[$c], $cols)); $ins++;
        }
        foreach ($deletes as $d) {
            $d = array_values((array)$d); if (count($d) !== count($meta['pk'])) continue;
            $st = $db->prepare("DELETE FROM `$schema`.`$table` WHERE " . implode(' AND ', array_map(fn($c) => "`$c` = ?", $meta['pk']))); $st->execute($d); $del += $st->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); $db->exec("SET FOREIGN_KEY_CHECKS=1"); return ['ok' => false, 'msg' => $e->getMessage(), 'bk' => $bk]; }
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    return ['ok' => true, 'ins' => $ins, 'del' => $del, 'bk' => $bk];
}
/** تعريف عمود (لإزالة AUTO_INCREMENT عن جدول النسخة) */
function syncColDef(PDO $db, string $schema, string $table, string $col): string {
    $st = $db->prepare("SELECT column_type, is_nullable FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
    $st->execute([$schema, $table, $col]); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r['column_type'] . ($r['is_nullable'] === 'NO' ? ' NOT NULL' : ' NULL') : 'INT';
}
