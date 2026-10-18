<?php
/**
 * 🔁 مزامنة بيانات الأونلاين → نسخة الكمبيوتر تلقائياً («انا بدي كل شي تلقائي» 2026-09-07)
 *
 * الأونلاين (msapayroll.com) هو المرجع دائماً. نسخة الكمبيوتر (XAMPP) صورة عنه كوداً (النشر) وبياناتاً (هذه المزامنة).
 * تعمل فقط على الكمبيوتر: المضيف localhost + ثوابت ONLINE_SYNC_* بـconfig/database.php المحلي (غير منشور) + mysql.exe بـXAMPP.
 * الآلية: عند فتح أي صفحة محلياً (مدير) وقد مضت ONLINE_SYNC_HOURS على آخر مزامنة، تُطلق الصفحة طلباً خلفياً
 * إلى sync_local.php يقوم بـ: دخول أونلاين → تحميل النسخة الكاملة (backup.php?action=sql) → تحقّق اكتمالها →
 * تركيبها بقاعدة مؤقتة والتحقّق من الجداول والأرقام → تبديل ذرّي بـRENAME TABLE (الحالية → smp_pc_prev، والأولى
 * تُحفظ نهائياً بـ smp_pc_archive_first). أي فشل قبل التبديل = الكمبيوتر لا يُمسّ.
 * الحالة تُحفظ بملف tmp/local_sync_state.json (لا بجدول settings لأنّه يُستبدل بالمزامنة).
 */

function localSyncMysqlExe(): string {
    $cands = [dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe', 'C:\\xampp\\mysql\\bin\\mysql.exe'];
    foreach ($cands as $c) if (is_file($c)) return $c;
    return '';
}
/** هل المزامنة ممكنة هنا؟ (كمبيوتر محلي فقط، لا أونلاين ولا CLI) */
function localSyncEnabled(): bool {
    if (php_sapi_name() === 'cli') return false;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false)) return false;
    if (!defined('ONLINE_SYNC_URL') || !defined('ONLINE_SYNC_USER') || !defined('ONLINE_SYNC_PASS')) return false;
    return localSyncMysqlExe() !== '' && function_exists('curl_init');
}
function localSyncStateFile(): string { return dirname(__DIR__) . '/tmp/local_sync_state.json'; }
function localSyncState(): array {
    $f = localSyncStateFile();
    if (!is_file($f)) return [];
    return json_decode((string)file_get_contents($f), true) ?: [];
}
function localSyncSaveState(array $st): void {
    $f = localSyncStateFile();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0777, true);
    @file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function localSyncHours(): float { return defined('ONLINE_SYNC_HOURS') ? max(0.25, (float)ONLINE_SYNC_HOURS) : 4.0; }
/** هل حان وقت مزامنة جديدة؟ (مضت المدة + لا قفل فاعل) */
function localSyncDue(): bool {
    $st = localSyncState();
    $lock = (int)($st['lock'] ?? 0);
    if ($lock && time() - $lock < 1800) return false;               // مزامنة جارية (القفل يبلى بعد 30 دقيقة)
    $last = (int)($st['last_ok'] ?? 0);
    $lastTry = (int)($st['last_try'] ?? 0);
    if (time() - $lastTry < 900) return false;                       // لا نعيد المحاولة أكثر من مرّة كل 15 دقيقة
    return time() - $last >= localSyncHours() * 3600;
}
/** 🔁 (2026-10-18 «بدي تلقائياً إذا غيّرت شي أونلاين يتغيّر كمان على الكمبيوتر») فحص خفيف كل 5 دقائق: هل تغيّرت بصمة الأونلاين؟ */
function localSyncCheckDue(): bool {
    $st = localSyncState();
    if (!empty($st['lock']) && time() - (int)$st['lock'] < 1800) return false;
    return time() - (int)($st['last_check'] ?? 0) >= 300;
}
/** الدخول أونلاين + قراءة البصمة (sync_version.php). يعيد البصمة أو '' عند أي عطل. $cj = ملف كوكي موجود (اختياري) */
function localSyncOnlineVersion(?string $cj = null): string {
    $site = rtrim((string)ONLINE_SYNC_URL, '/');
    $own = $cj === null; if ($own) $cj = tempnam(sys_get_temp_dir(), 'syncv');
    $ch = function (string $url, array $opt = []) use ($cj) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cj, CURLOPT_COOKIEFILE => $cj, CURLOPT_FOLLOWLOCATION => false,
                               CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MSA-Payroll-LocalSync'] + $opt);
        $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$out, $code];
    };
    try {
        [$html, $code] = $ch($site . '/login.php');
        if ($html === false || $code !== 200) return '';
        preg_match('/name="csrf" value="([^"]+)"/', (string)$html, $m);
        [, $code] = $ch($site . '/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username' => ONLINE_SYNC_USER, 'password' => ONLINE_SYNC_PASS, 'csrf' => $m[1] ?? ''])]);
        if ($code !== 302) return '';
        [$js, $code] = $ch($site . '/sync_version.php');
        if ($code !== 200) return '';
        $j = json_decode((string)$js, true);
        return is_array($j) && !empty($j['ok']) ? (string)$j['fp'] : '';
    } finally { if ($own) @unlink($cj); }
}
/** هل تغيّر الأونلاين عن آخر مزامنة؟ (يسجّل وقت الفحص والبصمة المقروءة) */
function localSyncOnlineChanged(): bool {
    $st = localSyncState(); $st['last_check'] = time(); localSyncSaveState($st);
    $fp = localSyncOnlineVersion();
    if ($fp === '') return false;                              // لا اتصال/لا نقطة ⇒ نبقى على المزامنة الدورية
    $st = localSyncState(); $st['online_fp_seen'] = $fp; localSyncSaveState($st);
    return $fp !== (string)($st['online_fp'] ?? '');
}
function localSyncLog(string $msg): void {
    $f = dirname(__DIR__) . '/tmp/local_sync.log';
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0777, true);
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
/** وصف قصير لآخر مزامنة (للوحة القيادة المحلية) */
function localSyncStatusText(): string {
    $st = localSyncState();
    if (!empty($st['lock']) && time() - (int)$st['lock'] < 1800) return 'جارية الآن…';
    if (!empty($st['last_ok'])) return 'آخر مزامنة ' . date('d/m/Y H:i', (int)$st['last_ok']) . (!empty($st['last_msg']) ? ' — ' . $st['last_msg'] : '');
    return !empty($st['last_msg']) ? 'لم تتمّ بعد — ' . $st['last_msg'] : 'لم تتمّ بعد';
}

/**
 * تنفيذ المزامنة كاملة. يعيد ['ok'=>bool,'msg'=>..].
 */
function localSyncRun(): array {
    $st = localSyncState();
    if (!empty($st['lock']) && time() - (int)$st['lock'] < 1800) return ['ok' => false, 'msg' => 'مزامنة جارية'];
    $st['lock'] = time(); $st['last_try'] = time(); localSyncSaveState($st);
    $done = function (bool $ok, string $msg) use (&$st): array {
        $st = localSyncState(); unset($st['lock']);
        $st['last_msg'] = $msg; if ($ok) $st['last_ok'] = time();
        localSyncSaveState($st); localSyncLog(($ok ? '✅ ' : '❌ ') . $msg);
        return ['ok' => $ok, 'msg' => $msg];
    };
    @set_time_limit(0); @ignore_user_abort(true);
    $mysql = localSyncMysqlExe();
    $site = rtrim((string)ONLINE_SYNC_URL, '/');
    $dl = dirname(__DIR__) . '/tmp';
    if (!is_dir($dl)) @mkdir($dl, 0777, true);
    $dump = $dl . '/online_dump_' . date('Ymd_Hi') . '.sql';
    $cj = tempnam(sys_get_temp_dir(), 'sync');
    localSyncLog('بدء المزامنة من ' . $site);

    // 1) الدخول أونلاين + التحميل
    $ch = function (string $url, array $opt = []) use ($cj) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cj, CURLOPT_COOKIEFILE => $cj, CURLOPT_FOLLOWLOCATION => false,
                               CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MSA-Payroll-LocalSync'] + $opt);
        $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
        return [$out, $code, $err];
    };
    [$html, $code, $err] = $ch($site . '/login.php');
    if ($html === false || $code !== 200) { @unlink($cj); return $done(false, 'لا اتصال بالأونلاين (' . ($err ?: $code) . ')'); }
    preg_match('/name="csrf" value="([^"]+)"/', (string)$html, $m);
    [, $code] = $ch($site . '/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username' => ONLINE_SYNC_USER, 'password' => ONLINE_SYNC_PASS, 'csrf' => $m[1] ?? ''])]);
    if ($code !== 302) { @unlink($cj); return $done(false, 'فشل الدخول أونلاين (رمز ' . $code . ')'); }
    // 🔁 بصمة الأونلاين لحظة التحميل — تُحفظ بعد نجاح التبديل فيعرف الكمبيوتر لاحقاً إن تغيّر شيء
    [$fpJs, $fpCode] = $ch($site . '/sync_version.php');
    $fpNow = ''; if ($fpCode === 200) { $fpJ = json_decode((string)$fpJs, true); if (is_array($fpJ) && !empty($fpJ['ok'])) $fpNow = (string)$fpJ['fp']; }
    $fh = fopen($dump, 'wb');
    $c = curl_init($site . '/pages/backup.php?action=sql');
    curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_COOKIEFILE => $cj, CURLOPT_TIMEOUT => 900, CURLOPT_USERAGENT => 'MSA-Payroll-LocalSync']);
    $okDl = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c); fclose($fh); @unlink($cj);
    if (!$okDl || $code !== 200) { @unlink($dump); return $done(false, 'فشل تحميل النسخة (' . ($err ?: $code) . ')'); }

    // 2) اكتمال الملف
    $size = (int)@filesize($dump);
    if ($size < 5000000) { @unlink($dump); return $done(false, 'الملف صغير جداً (' . round($size / 1048576, 1) . ' MB) — ليس نسخة كاملة'); }
    $tail = ''; $fh = fopen($dump, 'rb'); fseek($fh, max(0, $size - 4096)); $tail = (string)fread($fh, 4096); fclose($fh);
    if (!preg_match('/SET FOREIGN_KEY_CHECKS=1;\s*$/', $tail)) { @unlink($dump); return $done(false, 'الملف مقصوص — لم يُلمس شيء'); }
    $ntab = 0; $hasEmp = false; $hasSal = false;
    $fh = fopen($dump, 'rb');
    while (($line = fgets($fh)) !== false) {
        if (strncmp($line, 'CREATE TABLE ', 13) === 0) { $ntab++; if (strpos($line, '`employees`') !== false) $hasEmp = true; if (strpos($line, '`monthly_salaries`') !== false) $hasSal = true; }
    }
    fclose($fh);
    if (!$hasEmp || !$hasSal) { @unlink($dump); return $done(false, 'الملف بلا جدول الموظفين/الرواتب'); }
    localSyncLog('الملف كامل: ' . round($size / 1048576, 1) . ' MB، ' . $ntab . ' جدولاً');

    // 3) التركيب بقاعدة مؤقتة + التحقّق
    $tmpdb = 'smp_sync_tmp'; $prevdb = 'smp_pc_prev'; $firstdb = 'smp_pc_archive_first'; $dbname = DB_NAME;
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `$tmpdb`"); $pdo->exec("CREATE DATABASE `$tmpdb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $args = [$mysql, '-h', DB_HOST, '-u', DB_USER]; if (DB_PASS !== '') $args[] = '-p' . DB_PASS;
        $args[] = '--default-character-set=utf8mb4'; $args[] = $tmpdb;
        $proc = proc_open($args, [0 => ['file', $dump, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) return $done(false, 'تعذّر تشغيل mysql.exe');
        $out = stream_get_contents($pipes[1]); $errOut = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($rc !== 0) { $pdo->exec("DROP DATABASE IF EXISTS `$tmpdb`"); return $done(false, 'فشل تركيب الملف بالقاعدة المؤقتة: ' . trim(mb_substr($errOut ?: $out, 0, 300)) . ' — الكمبيوتر لم يُمسّ'); }
        $got = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$tmpdb'")->fetchColumn();
        if ($got !== $ntab) { $pdo->exec("DROP DATABASE IF EXISTS `$tmpdb`"); return $done(false, "عدد الجداول المركّبة $got ≠ $ntab — الكمبيوتر لم يُمسّ"); }
        $nemp = (int)$pdo->query("SELECT COUNT(*) FROM `$tmpdb`.employees")->fetchColumn();
        $nsal = (int)$pdo->query("SELECT COUNT(*) FROM `$tmpdb`.monthly_salaries")->fetchColumn();
        if ($nemp < 500 || $nsal < 10000) { $pdo->exec("DROP DATABASE IF EXISTS `$tmpdb`"); return $done(false, "أرقام غير منطقية (موظفون $nemp، رواتب $nsal) — الكمبيوتر لم يُمسّ"); }

        // 4) التبديل الذرّي — 🔴 بيان RENAME TABLE **واحد** يحرّك القديم → prev والجديد → القاعدة معاً (حادثة
        //    2026-09-10 09:00: كان بيانين، وبين الأول والثاني فتحت صفحة أخرى فخلق شفاء ذاتي جدولَي employee_children
        //    وtax_suggestions بالقاعدة الفارغة، ففشل الثاني «already exists» وبقيت القاعدة بجدولين). البيان الواحد
        //    ذرّي: إمّا ينجح كله أو لا يتحرّك شيء.
        $hasFirst = (bool)$pdo->query("SHOW DATABASES LIKE '$firstdb'")->fetchColumn();
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $cur = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='$dbname' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $new = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='$tmpdb' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$hasFirst) { $target = $firstdb; $pdo->exec("CREATE DATABASE `$firstdb` CHARACTER SET utf8mb4"); localSyncLog("أول مزامنة: القاعدة القديمة بكل جداولها تُحفظ نهائياً بـ $firstdb"); }
        elseif (count($cur) < (int)ceil(count($new) / 2)) {
            // القاعدة الحالية ناقصة (مزامنة سابقة انقطعت بعد نقل القديم إلى prev): لا نمحو prev — ما بقي يُحفظ بقاعدة إنقاذ مؤرّخة
            $target = 'smp_pc_rescue_' . date('Ymd_His'); $pdo->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4");
            localSyncLog('القاعدة الحالية ناقصة (' . count($cur) . ' جداول) — smp_pc_prev محفوظة كما هي، والبقايا إلى ' . $target);
        }
        else { $target = $prevdb; $pdo->exec("DROP DATABASE IF EXISTS `$prevdb`"); $pdo->exec("CREATE DATABASE `$prevdb` CHARACTER SET utf8mb4"); }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pairs = [];
        foreach ($cur as $t) $pairs[] = "`$dbname`.`$t` TO `$target`.`$t`";
        foreach ($new as $t) $pairs[] = "`$tmpdb`.`$t` TO `$dbname`.`$t`";
        $pdo->exec('RENAME TABLE ' . implode(',', $pairs));
        $pdo->exec("DROP DATABASE IF EXISTS `$tmpdb`");
        $final = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$dbname'")->fetchColumn();
    } catch (Throwable $e) {
        return $done(false, 'خطأ بقاعدة البيانات: ' . mb_substr($e->getMessage(), 0, 200));
    }
    // الاحتفاظ بآخر 3 ملفات تحميل فقط
    $files = glob($dl . '/online_dump_*.sql') ?: []; rsort($files);
    foreach (array_slice($files, 3) as $f) @unlink($f);
    // 🗄️ (2026-10-10 «اعمول حسب معرفتك») نسخة ثانية مضغوطة خارج البرنامج: C:\Users\<user>\payroll_backups\db (+ OneDrive إن كان شغّالاً) — آخر 14 نسخة
    try {
        $src = $dump;
        if (is_file($src)) {
            $dests = [];
            $home = getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') . getenv('HOMEPATH'));
            if ($home) $dests[] = $home . DIRECTORY_SEPARATOR . 'payroll_backups' . DIRECTORY_SEPARATOR . 'db';
            if ($home && is_dir($home . DIRECTORY_SEPARATOR . 'OneDrive')) $dests[] = $home . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR . 'MSA_Payroll_Backups';
            foreach ($dests as $dir) {
                if (!is_dir($dir) && !@mkdir($dir, 0777, true)) continue;
                $gz = $dir . DIRECTORY_SEPARATOR . 'payroll_online_' . date('Ymd_Hi') . '.sql.gz';
                $in = @fopen($src, 'rb'); $out = @gzopen($gz, 'wb6');
                if ($in && $out) { while (!feof($in)) gzwrite($out, (string)fread($in, 1048576)); }
                if ($in) fclose($in); if ($out) gzclose($out);
                $old = glob($dir . DIRECTORY_SEPARATOR . 'payroll_online_*.sql.gz') ?: []; rsort($old);
                foreach (array_slice($old, 14) as $f) @unlink($f);
                localSyncLog('نسخة احتياطية مضغوطة: ' . $gz);
            }
        }
    } catch (Throwable $e) { localSyncLog('تعذّرت النسخة الثانية: ' . $e->getMessage()); }
    if ($fpNow !== '') { $stF = localSyncState(); $stF['online_fp'] = $fpNow; $stF['last_check'] = time(); localSyncSaveState($stF); }
    // 🔁 بعد السحب: الأساس الجديد للدمج الثلاثي + ترقيم ذاتي بعيد (لا تصادم مفاتيح بين الكمبيوتر والأونلاين)
    try { localSyncBaseRebuild(); } catch (Throwable $e) { localSyncLog('تعذّر بناء أساس الدمج: ' . $e->getMessage()); }
    return $done(true, "طبق الأصل عن الأونلاين: $final جدولاً، $nemp موظفاً، $nsal صفّ رواتب");
}

/* =====================================================================================================================
 * 🔁 المزامنة باتّجاهين (2026-10-18 «لنفترض ما في إنترنت واضطريت اشتغل على الكمبيوتر — بس ترجع الإنترنت لازم الأونلاين يصير متل الكمبيوتر…
 *    وين ما بغيّر يبقى كل شي نفس الشي بالمكانين، وكل شي تلقائي»):
 *    الأساس = بصمات صفوف آخر نسخة مسحوبة (_sync_base). كل 5 دقائق (محلياً): ما تغيّر على الكمبيوتر عن الأساس يُرفع للأونلاين (sync_api)
 *    — إلا ما تغيّر بالمكانين معاً فيبقى الأونلاين ويُسجَّل تعارضاً (_sync_conflicts) ويظهر بلوحة القيادة — ثم تُسحب النسخة الكاملة فيتطابقان.
 *    بلا إنترنت: التعديلات تبقى محفوظة على الكمبيوتر وتُرفع أوّل ما يرجع (لا سحب قبل نجاح الرفع ⇒ لا ضياع).
 * =================================================================================================================== */
function localSyncPdo(): PDO {
    return new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
/** الأساس: بصمات كل صفوف الجداول الحقيقية الآن (تُبنى بعد كل سحب) + جدول التعارضات + ترقيم ذاتي بعيد */
function localSyncBaseRebuild(): void {
    require_once __DIR__ . '/sync_common.php';
    $pdo = localSyncPdo(); $s = DB_NAME;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$s`.`_sync_base` (tbl VARCHAR(64) NOT NULL, pk VARCHAR(191) NOT NULL, h CHAR(32) NOT NULL, PRIMARY KEY (tbl, pk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$s`.`_sync_conflicts` (id INT AUTO_INCREMENT PRIMARY KEY, tbl VARCHAR(64) NOT NULL, pk VARCHAR(191) NOT NULL, kind VARCHAR(16) NOT NULL, label VARCHAR(255) NULL, row_json MEDIUMTEXT NULL, created_at DATETIME NOT NULL, seen TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("TRUNCATE TABLE `$s`.`_sync_base`");
    foreach (syncRealTables($pdo, $s) as $t) {
        $m = syncTableMeta($pdo, $s, $t); if (!$m['pk']) continue;
        $pdo->exec("INSERT INTO `$s`.`_sync_base` (tbl, pk, h) SELECT " . $pdo->quote($t) . ", " . syncPkSql($m) . ", " . syncHashSql($m) . " FROM `$s`.`$t`");
        if ($m['auto'] !== '') { // ترقيم ذاتي بعيد عن الأونلاين (+1,000,000) كي لا يتصادم ملف جديد على الكمبيوتر مع ملف جديد أونلاين
            $mx = (int)$pdo->query("SELECT COALESCE(MAX(`{$m['auto']}`), 0) FROM `$s`.`$t`")->fetchColumn();
            try { $pdo->exec("ALTER TABLE `$s`.`$t` AUTO_INCREMENT = " . ($mx + 1000000)); } catch (Throwable $e) {}
        }
    }
    $st = localSyncState(); $st['base_built'] = time(); localSyncSaveState($st);
}
/** ما تغيّر على الكمبيوتر عن الأساس: [table => ['ins'=>[pk], 'upd'=>[pk], 'del'=>[pk], 'base'=>[pk=>h]]] — null إن لا أساس (أوّل مرّة ⇒ يُبنى) */
function localSyncLocalDiff(): ?array {
    require_once __DIR__ . '/sync_common.php';
    $pdo = localSyncPdo(); $s = DB_NAME;
    $has = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($s) . " AND table_name = '_sync_base'")->fetchColumn();
    if (!$has) { localSyncBaseRebuild(); return null; }
    $diff = [];
    foreach (syncRealTables($pdo, $s) as $t) {
        $m = syncTableMeta($pdo, $s, $t); if (!$m['pk']) continue;
        $cur = syncRowHashes($pdo, $s, $t, $m);
        $st = $pdo->prepare("SELECT pk, h FROM `$s`.`_sync_base` WHERE tbl = ?"); $st->execute([$t]);
        $base = []; foreach ($st->fetchAll() as $r) $base[$r['pk']] = $r['h'];
        $ins = []; $upd = []; $del = [];
        foreach ($cur as $pk => $h) { if ($t === 'settings' && syncSettingsKeyIgnored((string)$pk)) continue; if (!isset($base[$pk])) $ins[] = (string)$pk; elseif ($base[$pk] !== $h) $upd[] = (string)$pk; }
        foreach ($base as $pk => $h) { if ($t === 'settings' && syncSettingsKeyIgnored((string)$pk)) continue; if (!isset($cur[$pk])) $del[] = (string)$pk; }
        if ($ins || $upd || $del) $diff[$t] = ['ins' => $ins, 'upd' => $upd, 'del' => $del, 'base' => array_intersect_key($base, array_flip(array_merge($upd, $del)))];
    }
    return $diff;
}
/** دخول أونلاين بملف كوكي؛ يعيد دالّة طلب أو null */
function localSyncHttp(string $cj): ?callable {
    $site = rtrim((string)ONLINE_SYNC_URL, '/');
    $ch = function (string $url, array $opt = []) use ($cj) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cj, CURLOPT_COOKIEFILE => $cj, CURLOPT_FOLLOWLOCATION => false,
                               CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MSA-Payroll-LocalSync'] + $opt);
        $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$out, $code];
    };
    [$html, $code] = $ch($site . '/login.php');
    if ($html === false || $code !== 200) return null;
    preg_match('/name="csrf" value="([^"]+)"/', (string)$html, $m);
    [, $code] = $ch($site . '/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username' => ONLINE_SYNC_USER, 'password' => ONLINE_SYNC_PASS, 'csrf' => $m[1] ?? ''])]);
    return $code === 302 ? $ch : null;
}
/** رفع تعديلات الكمبيوتر إلى الأونلاين (دمج ثلاثي). يعيد ['ok','pushed','conflicts','msg'] */
function localSyncPush(array $diff): array {
    require_once __DIR__ . '/sync_common.php';
    $site = rtrim((string)ONLINE_SYNC_URL, '/'); $cj = tempnam(sys_get_temp_dir(), 'syncp');
    $ch = localSyncHttp($cj);
    if (!$ch) { @unlink($cj); return ['ok' => false, 'pushed' => 0, 'conflicts' => 0, 'msg' => 'لا اتصال بالأونلاين — التعديلات محفوظة للرفع لاحقاً']; }
    $pdo = localSyncPdo(); $s = DB_NAME; $pushed = 0; $conf = 0; $msgs = [];
    try {
        [$tj, $code] = $ch($site . '/sync_api.php?action=tables'); $tables = json_decode((string)$tj, true);
        if ($code !== 200 || !is_array($tables) || empty($tables['ok'])) { @unlink($cj); return ['ok' => false, 'pushed' => 0, 'conflicts' => 0, 'msg' => 'واجهة المزامنة أونلاين غير متاحة (رمز ' . $code . ')']; }
        foreach ($diff as $t => $d) {
            if (empty($tables['tables'][$t])) { $msgs[] = "$t: غير موجود أونلاين"; continue; }
            $m = syncTableMeta($pdo, $s, $t);
            $pks = array_merge($d['ins'], $d['upd'], $d['del']);
            [$hj, $code] = $ch($site . '/sync_api.php?action=hashes&table=' . rawurlencode($t), [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['pks' => $pks])]);
            $hr = json_decode((string)$hj, true);
            if ($code !== 200 || !is_array($hr) || empty($hr['ok'])) { $msgs[] = "$t: تعذّرت قراءة بصمات الأونلاين"; continue; }
            $online = (array)($hr['hashes'] ?? []);
            $upPks = []; $delPks = [];
            $conflict = function (string $pk, string $kind) use ($pdo, $s, $t, $m, &$conf) {
                $label = $t . ' #' . str_replace(chr(31), '/', $pk);
                if ($t === 'employees') { $r = syncRowsByPk($pdo, $s, $t, $m, [$pk]); $r = reset($r); if ($r) $label = trim(($r['first_name_ar'] ?? '') . ' ' . ($r['last_name_ar'] ?? '')) ?: $label; }
                $rows = syncRowsByPk($pdo, $s, $t, $m, [$pk]);
                $pdo->prepare("INSERT INTO `$s`.`_sync_conflicts` (tbl, pk, kind, label, row_json, created_at) VALUES (?,?,?,?,?,NOW())")->execute([$t, $pk, $kind, $label, json_encode(reset($rows) ?: null, JSON_UNESCAPED_UNICODE), ]);
                $conf++;
            };
            foreach ($d['upd'] as $pk) { if (($online[$pk] ?? null) === ($d['base'][$pk] ?? '')) $upPks[] = $pk; else $conflict($pk, 'upd'); }   // الأونلاين كما كان ⇒ نرفع؛ تغيّر ⇒ تعارض
            foreach ($d['ins'] as $pk) { if (!isset($online[$pk])) $upPks[] = $pk; else $conflict($pk, 'ins'); }
            foreach ($d['del'] as $pk) { if (!isset($online[$pk])) continue; if ($online[$pk] === ($d['base'][$pk] ?? '')) $delPks[] = $pk; else $conflict($pk, 'del'); }
            $rows = $upPks ? syncRowsByPk($pdo, $s, $t, $m, $upPks) : [];
            $ups = array_values($rows); $dels = array_map(fn($pk) => explode(chr(31), $pk), $delPks);
            $chunksU = array_chunk($ups, 400); $chunksD = array_chunk($dels, 400);
            $nb = max(count($chunksU), count($chunksD));
            for ($i = 0; $i < $nb; $i++) {
                $body = json_encode(['table' => $t, 'upserts' => $chunksU[$i] ?? [], 'deletes' => $chunksD[$i] ?? []], JSON_UNESCAPED_UNICODE);
                [$aj, $code] = $ch($site . '/sync_api.php?action=apply', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
                $ar = json_decode((string)$aj, true);
                if ($code !== 200 || !is_array($ar) || empty($ar['ok'])) { $msgs[] = "$t: فشل الرفع (" . (is_array($ar) ? ($ar['msg'] ?? $code) : $code) . ')'; @unlink($cj); return ['ok' => false, 'pushed' => $pushed, 'conflicts' => $conf, 'msg' => implode(' · ', $msgs)]; }
                $pushed += (int)($ar['ins'] ?? 0) + (int)($ar['del'] ?? 0);
            }
            $msgs[] = "$t: " . count($upPks) . '↑ ' . count($delPks) . '🗑';
        }
    } catch (Throwable $e) { @unlink($cj); return ['ok' => false, 'pushed' => $pushed, 'conflicts' => $conf, 'msg' => 'خطأ بالرفع: ' . mb_substr($e->getMessage(), 0, 200)]; }
    @unlink($cj);
    localSyncLog("رُفعت تعديلات الكمبيوتر: $pushed صفّاً، تعارضات $conf — " . implode(' · ', $msgs));
    return ['ok' => true, 'pushed' => $pushed, 'conflicts' => $conf, 'msg' => "رُفع $pushed صفّاً" . ($conf ? "، تعارضات $conf (بقي الأونلاين)" : '')];
}
/** الدورة الكاملة: رفع ما تغيّر على الكمبيوتر (إن وُجد) ثم سحب إن لزم (دوري/تغيّر الأونلاين/بعد الرفع). لا سحب قبل نجاح الرفع. */
function localSyncCycle(bool $force = false): array {
    $st = localSyncState();
    if (!empty($st['lock']) && time() - (int)$st['lock'] < 1800) return ['ok' => false, 'msg' => 'مزامنة جارية'];
    $st['last_check'] = time(); localSyncSaveState($st);
    try { $diff = localSyncLocalDiff(); } catch (Throwable $e) { localSyncLog('تعذّر فحص تعديلات الكمبيوتر: ' . $e->getMessage()); $diff = []; }
    $hasLocal = is_array($diff) && count($diff) > 0;
    if ($hasLocal) {
        $p = localSyncPush($diff);
        $st = localSyncState(); $st['pending_push'] = $p['ok'] ? 0 : 1; $st['last_push_msg'] = $p['msg']; if ($p['ok']) $st['last_push_ok'] = time(); localSyncSaveState($st);
        if (!$p['ok']) { localSyncLog('⏳ ' . $p['msg']); return ['ok' => false, 'msg' => $p['msg']]; }
    }
    if ($force || $hasLocal || localSyncDue() || localSyncOnlineChanged()) {
        $r = localSyncRun();
        if ($hasLocal && !empty($r['ok'])) $r['msg'] = ($p['msg'] ?? '') . ' ⇒ ' . $r['msg'];
        return $r;
    }
    return ['ok' => true, 'msg' => 'no change'];
}
/** عدد التعارضات غير المقروءة + أحدثها (للوحة القيادة) */
function localSyncConflicts(int $limit = 10): array {
    try { $pdo = localSyncPdo(); $s = DB_NAME;
        $has = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($s) . " AND table_name = '_sync_conflicts'")->fetchColumn();
        if (!$has) return ['n' => 0, 'rows' => []];
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$s`.`_sync_conflicts` WHERE seen = 0")->fetchColumn();
        $rows = $n ? $pdo->query("SELECT id, tbl, pk, kind, label, created_at FROM `$s`.`_sync_conflicts` WHERE seen = 0 ORDER BY id DESC LIMIT $limit")->fetchAll() : [];
        return ['n' => $n, 'rows' => $rows];
    } catch (Throwable $e) { return ['n' => 0, 'rows' => []]; }
}
