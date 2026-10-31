<?php
/**
 * 🔒👁️ فحص «عرض فقط» التلقائي لحسابات المدارس/الرئاسة (2026-10-31)
 *  طلبه: «أهمّ شي ما يكون ولا تقرير يقدر يشتغلو فيه بس يشوفو… بدّك تعمل تلقائياً شي يشيّك على كل صفحات التقارير بس يشوفوهن».
 *  يفتح جلسة حساب قراءة فقط مؤقّت (كل الصفحات + كل المدارس) على نسخة الكمبيوتر ثمّ:
 *   (1) GET كل صفحة مسموحة ⇒ 200 بلا خطأ، وفحص HTML: لا نماذج كتابة (POST) ولا أزرار حفظ/حذف/تعديل ظاهرة.
 *   (2) POST على كل ملفّ PHP بالبرنامج (الجذر + pages/) بحقول «تخريب» ⇒ مرفوض (لا 200)، وقاعدة البيانات لم تتغيّر (CHECKSUM TABLE).
 *   (3) GET بوسائط كتابة مشبوهة (delete/action/approve/recalc/save/send…) على كل صفحة ⇒ قاعدة البيانات لم تتغيّر.
 *   (4) الصفحات غير المسموحة ⇒ طرد للوحة (302).
 *  التشغيل: C:\xampp\php\php.exe tools\viewer_audit.php [base_url]   (الافتراضي http://localhost/saint-maxime-payroll/) — يطبع ملخّصاً، exit 1 عند أي خلل.
 *  يُستدعى أيضاً من regression_check.php (فحص 281).
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
$PROJ = dirname(__DIR__);
session_start();
require_once $PROJ . '/config/database.php';
require_once $PROJ . '/includes/functions.php';
$db = getDB();
$base = rtrim($argv[1] ?? 'http://localhost/saint-maxime-payroll', '/') . '/';
@mkdir($PROJ . '/tmp', 0777, true); $pausedByMe = !file_exists($PROJ . '/tmp/local_sync_pause'); if ($pausedByMe) @touch($PROJ . '/tmp/local_sync_pause');
$cj = tempnam(sys_get_temp_dir(), 'va_cj'); $issues = []; $notes = [];

/* ───── حساب مؤقّت: قراءة فقط، كل الصفحات، كل المدارس ───── */
$un = '_audit_ro_' . bin2hex(random_bytes(3)); $pw = 'Audit@' . bin2hex(random_bytes(4));
ensureUsersPermsColumn();
$db->prepare("INSERT INTO users (username,password_hash,full_name,role,school_id,is_active,allowed_pages,allowed_schools) VALUES (?,?,?,?,NULL,1,?,?)")
   ->execute([$un, password_hash($pw, PASSWORD_DEFAULT), 'Audit lecture seule', 'viewer', implode(',', array_keys(viewerReportPages())), 'all']);
$uid = (int)$db->lastInsertId();
register_shutdown_function(function () use ($db, $uid, $cj, $PROJ, $pausedByMe) {
    try { $db->prepare("DELETE FROM users WHERE id = ? AND role = 'viewer' AND username LIKE '\\_audit\\_ro\\_%'")->execute([$uid]); } catch (Throwable $e) {}
    @unlink($cj); if ($pausedByMe) @unlink($PROJ . '/tmp/local_sync_pause');
});

function http(string $method, string $url, array $post = [], string $cj = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $cj, CURLOPT_COOKIEFILE => $cj, CURLOPT_USERAGENT => 'MSA-ViewerAudit/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($raw, 0, $hs); $body = substr($raw, $hs); $loc = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    return [$code, $loc, $body];
}
function checksums(PDO $db): array {
    $out = [];
    $tabs = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabs as $t) { if ($t === 'users' || $t === 'audit_log' || strpos($t, '_sync') === 0 || strpos($t, 'sync_') === 0) continue; $r = $db->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM); $out[$t] = $r[1] ?? null; }
    return $out;
}
function diffSums(array $a, array $b): array { $d = []; foreach ($a as $t => $v) if (($b[$t] ?? null) !== $v) $d[] = $t; foreach ($b as $t => $v) if (!isset($a[$t])) $d[] = $t . '(new)'; return array_values(array_unique($d)); }

/* ───── دخول ───── */
[$c, $loc] = http('POST', $base . 'login.php', ['username' => $un, 'password' => $pw], $cj);
if ($c !== 302 || strpos($loc, 'index.php') === false) { echo "FAIL login ($c $loc)\n"; exit(1); }
$usersBefore = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

/* ───── (1) الصفحات المسموحة: 200 + بلا عناصر كتابة ───── */
$allowed = array_merge(array_keys(viewerReportPages()), ...array_values(viewerReportExports()));
$allowed = array_values(array_unique(array_merge(['index.php'], $allowed)));
$writeWords = '~(Enregistrer|Sauvegarder|Supprimer|Modifier|Ajouter|Valider le paiement|Recalculer|Payer|Approuver|Envoyer au|حفظ|احفظ|حذف|امسح|تعديل|عدّل|إضافة|أضف|ادفع|اعتمد|إعادة احتساب)~u';
$pagesOk = 0;
foreach ($allowed as $p) {
    $url = $base . ($p === 'index.php' ? $p : 'pages/' . $p);
    [$c, $loc, $body] = http('GET', $url, [], $cj);
    if ($c === 400 && substr($p, -11) === '_export.php') { $pagesOk++; continue; }   // منافذ التصدير بلا وسائط = 400 طبيعي
    if ($c !== 200) { $issues[] = "GET $p ⇒ $c $loc"; continue; }
    if (preg_match('/Fatal error|Parse error|Uncaught/i', $body)) { $issues[] = "GET $p ⇒ خطأ PHP"; continue; }
    // نماذج كتابة: أي <form method=post> ما عدا البحث/الملاحة/الخروج — يُذكر action + أوّل حقل مخفي + نصّ الزرّ ليُعرف مصدره
    if (preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $body, $fm, PREG_SET_ORDER)) {
        foreach ($fm as $f) {
            $tag = $f[1]; $inner = $f[2];
            if (!preg_match('/method\s*=\s*["\']?post/i', $tag)) continue;
            if (preg_match('/logout|search|switch_|lang/i', $tag)) continue;
            $act = preg_match('/action\s*=\s*["\']([^"\']*)/i', $tag, $am) ? $am[1] : '(même page)';
            $hid = preg_match('/<input[^>]*type=["\']hidden["\'][^>]*name=["\']([^"\']+)["\'][^>]*value=["\']([^"\']*)/i', $inner, $hm) ? $hm[1] . '=' . $hm[2] : '';
            $btn = preg_match('/<button[^>]*>(.*?)<\/button>/is', $inner, $bm2) ? trim(preg_replace('/\s+/', ' ', strip_tags($bm2[1]))) : '';
            $issues[] = "$p: نموذج POST ظاهر لحساب قراءة فقط ⇒ action=$act $hid «" . mb_substr($btn, 0, 50) . '»';
        }
    }
    // روابط لصفحات غير مسموحة (أزرار تعديل/إدارة ظاهرة رغم أنّها ستُطرد)
    if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $body, $am2, PREG_SET_ORDER)) {
        $allowedScripts = viewerAllowedScriptsForUser(implode(',', array_keys(viewerReportPages())));
        foreach ($am2 as $a) {
            $href = html_entity_decode($a[1]);
            if (!preg_match('~(?:^|/)(pages/)?([a-z0-9_]+\.php)~i', $href, $hm2)) continue;
            if (preg_match('~^(https?:)?//~i', $href) && stripos($href, parse_url($base, PHP_URL_HOST)) === false) continue;
            $script = $hm2[2];
            if (in_array($script, $allowedScripts, true) || in_array($script, ['teacher_form.php', 'login.php'], true)) continue;
            $txt = trim(preg_replace('/\s+/', ' ', strip_tags($a[2])));
            $issues[] = "$p: رابط لصفحة غير مسموحة ظاهر ⇒ $script «" . mb_substr($txt, 0, 50) . '»';
        }
    }
    // أزرار بسكربت كتابة (حفظ/حذف عبر fetch/ajax)
    if (preg_match_all('/<button\b[^>]*onclick=["\']([^"\']*)["\'][^>]*>(.*?)<\/button>/is', $body, $ob, PREG_SET_ORDER)) {
        foreach ($ob as $b) {
            if (!preg_match('/(delete|remove|save|store|approve|recalc|ajax_tick|ajax_compliance|att_rules|fetch\(|XMLHttpRequest)/i', $b[1])) continue;
            if (preg_match('/print|pdf|export|copy|copier|نسخ/i', $b[1] . ' ' . $b[2])) continue;
            $issues[] = "$p: زرّ بسكربت كتابة ظاهر «" . mb_substr(trim(strip_tags($b[2])), 0, 50) . '» onclick=' . mb_substr($b[1], 0, 60);
        }
    }
    $pagesOk++;
}

/* ───── (2) POST «تخريب» على كل ملفّ PHP ⇒ مرفوض وبلا أثر ───── */
$skip = ['extrawage_data_2025-2026.php', 'login.php', 'logout.php', 'sync_api.php', 'sync_local.php', 'sync_version.php', 'db_backup.php', 'install.php', 'migrate.php', 'migrate_extrawage.php', 'reset_admin_once.php', 'fix_baseurl_once.php', 'fix_departures_once.php', 'cleanup_ghosts.php', 'recu.php', 'dl.php', 'key_login.php', 'key_go.php', 'key_link.php', 'switch_currency.php', 'switch_lang.php', 'switch_school.php', 'switch_year.php', 'switch_m13.php', 'switch_rateline.php', 'switch_totals.php', 'switch_salarycomp.php', 'ajax_search.php', 'manifest.php', 'icon.php', 'sw.js'];
$targets = [];
foreach (glob($PROJ . '/*.php') as $f) { $b = basename($f); if (!in_array($b, $skip, true) && strpos($b, '_') !== 0) $targets[] = $b; }
foreach (glob($PROJ . '/pages/*.php') as $f) { $b = basename($f); if (strpos($b, '_') !== 0 && $b !== 'teacher_form.php') $targets[] = 'pages/' . $b; }   // teacher_form = فورم الأستاذ العامّ برمز (بلا دخول) بتصميمه
$evil = ['action' => 'delete', 'act' => 'save', 'id' => 1, 'employee_id' => 1, 'delete' => 1, 'save' => 1, 'approve' => 1, 'recalc' => 1, 'amount' => 1, 'csrf' => 'x', '_t' => 'x', 'decision' => 'yes', 'mark' => 1, 'paid' => 1, 'month' => 1, 'year' => 2026];
$sumBefore = checksums($db);
$postBad = 0; $postN = 0;
foreach ($targets as $t) {
    [$c, $loc, $body] = http('POST', $base . $t . '?delete=1&action=delete&approve=1&recalc=1&save=1&send=1&mark=1', $evil, $cj); $postN++;
    $okResp = in_array($c, [301, 302, 303, 400, 401, 403, 404, 405], true) || ($c === 200 && preg_match('/Lecture seule|قراءة فقط|login-box|Connexion|"ok":false/u', $body));
    if (!$okResp) { $postBad++; $issues[] = "POST $t ⇒ $c (غير مرفوض)"; }
}
$sumAfterPost = checksums($db);
$d = diffSums($sumBefore, $sumAfterPost); if ($d) $issues[] = 'POST غيّر جداول: ' . implode(', ', $d);

/* ───── (3) GET بوسائط كتابة على كل صفحة ⇒ بلا أثر ───── */
foreach ($targets as $t) { http('GET', $base . $t . '?delete=1&action=delete&approve=1&recalc=1&save=1&send=1&mark=1&decision=yes&id=1&employee_id=1', [], $cj); }
$sumAfterGet = checksums($db);
$d = diffSums($sumAfterPost, $sumAfterGet); if ($d) $issues[] = 'GET بوسائط كتابة غيّر جداول: ' . implode(', ', $d);
$usersAfter = $db->query("SELECT COUNT(*) FROM users")->fetchColumn(); if ($usersAfter !== $usersBefore) $issues[] = 'جدول users تغيّر عدده';

/* ───── (4) صفحات غير مسموحة ⇒ طرد ───── */
$forbidN = 0; $forbidBad = 0;
$forbidden = [];
$baseScripts = viewerBaseScripts();
foreach (glob($PROJ . '/pages/*.php') as $f) { $b = basename($f); if (strpos($b, '_') === 0 || $b === 'teacher_form.php' || in_array($b, $allowed, true) || in_array($b, $baseScripts, true)) continue; $forbidden[] = 'pages/' . $b; }
foreach ($forbidden as $t) {
    [$c, $loc, $body] = http('GET', $base . $t, [], $cj); $forbidN++;
    $ok = ($c === 302 && strpos($loc, 'index.php') !== false) || in_array($c, [401, 403, 404], true) || ($c === 200 && preg_match('/"ok":false|قراءة فقط|Lecture seule/u', $body));
    if (!$ok) { $forbidBad++; $issues[] = "صفحة ممنوعة مفتوحة: $t ⇒ $c"; }
}

/* ───── الخلاصة ───── */
$ok = empty($issues);
echo ($ok ? "OK" : "FAIL") . " — pages_ok=$pagesOk/" . count($allowed) . " post_tested=$postN post_not_refused=$postBad forbidden_tested=$forbidN forbidden_open=$forbidBad db_changed=" . (empty($d) ? 'no' : 'yes') . "\n";
foreach ($issues as $i) echo "  ⚠ $i\n";
exit($ok ? 0 : 1);
