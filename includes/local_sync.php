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

        // 4) التبديل الذرّي
        $hasFirst = (bool)$pdo->query("SHOW DATABASES LIKE '$firstdb'")->fetchColumn();
        if ($hasFirst) { $target = $prevdb; $pdo->exec("DROP DATABASE IF EXISTS `$prevdb`"); $pdo->exec("CREATE DATABASE `$prevdb` CHARACTER SET utf8mb4"); }
        else { $target = $firstdb; $pdo->exec("CREATE DATABASE `$firstdb` CHARACTER SET utf8mb4"); localSyncLog("أول مزامنة: القاعدة القديمة بكل جداولها تُحفظ نهائياً بـ $firstdb"); }
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $cur = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='$dbname' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        if ($cur) $pdo->exec('RENAME TABLE ' . implode(',', array_map(fn($t) => "`$dbname`.`$t` TO `$target`.`$t`", $cur)));
        $new = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='$tmpdb' AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('RENAME TABLE ' . implode(',', array_map(fn($t) => "`$tmpdb`.`$t` TO `$dbname`.`$t`", $new)));
        $pdo->exec("DROP DATABASE IF EXISTS `$tmpdb`");
        $final = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$dbname'")->fetchColumn();
    } catch (Throwable $e) {
        return $done(false, 'خطأ بقاعدة البيانات: ' . mb_substr($e->getMessage(), 0, 200));
    }
    // الاحتفاظ بآخر 3 ملفات تحميل فقط
    $files = glob($dl . '/online_dump_*.sql') ?: []; rsort($files);
    foreach (array_slice($files, 3) as $f) @unlink($f);
    return $done(true, "طبق الأصل عن الأونلاين: $final جدولاً، $nemp موظفاً، $nsal صفّ رواتب");
}
