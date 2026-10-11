<?php
/**
 * فحص محرّك الطباعة محلياً: يدخل للبرنامج، يجلب صفحات، يستخرج منطقة المستند (كما يفعل export.js) ويبني PDF لكل واحدة.
 *   php tools/_pdf_test.php <outDir> <url1> <url2> ...   (الروابط نسبية لـ http://localhost/saint-maxime-payroll/)
 */
$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/\\');
$urls = array_slice($argv, 2);
$base = 'http://localhost/saint-maxime-payroll/';
$jar = $out . '/cookies.txt'; @unlink($jar);
require_once __DIR__ . '/../config/database.php';
if (!defined('BASE_URL')) define('BASE_URL', '/saint-maxime-payroll/');
require_once __DIR__ . '/../includes/pdf_engine.php';

function req(string $url, array $post = null): string {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = curl_exec($ch); curl_close($ch); return (string)$r;
}
$lg = req($base . 'login.php');
preg_match('/name="csrf" value="([^"]+)"/', $lg, $cm);
$pw = defined('ONLINE_SYNC_PASS') ? ONLINE_SYNC_PASS : 'admin123';
$r = req($base . 'login.php', ['username' => 'admin', 'password' => $pw, 'csrf' => $cm[1] ?? '']);
if (strpos($r, 'name="password"') !== false) { fwrite(STDERR, "login failed
"); exit(1); }

foreach ($urls as $i => $u) {
    $html = req($base . $u);
    libxml_use_internal_errors(true);
    $d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($d);
    $area = $xp->query('//*[@id="ppExportArea"]')->item(0)
        ?: $xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," doc-sheet ") or contains(concat(" ",normalize-space(@class)," ")," land-report ")]')->item(0)
        ?: $xp->query('//*[@id="pageContent"]')->item(0);
    if (!$area) { echo "[$i] $u: NO AREA\n"; continue; }
    $inner = ''; foreach ($area->childNodes as $c) $inner .= $d->saveHTML($c);
    // اتجاه المستند كما يقرّره export.js: عربي ⇒ rtl
    $dir = preg_match('/[\x{0600}-\x{06FF}]/u', strip_tags($inner)) ? 'rtl' : 'ltr';
    $t0 = microtime(true);
    try {
        $pdf = MsaPdf::fromHtml($inner, ['title' => 'test' . $i, 'dir' => $dir, 'landscape' => null, 'school' => 'Collège Saint Maxime']);
    } catch (Throwable $e) { echo "[$i] $u: ERROR " . $e->getMessage() . "\n"; continue; }
    $name = $out . '/t' . $i . '.pdf';
    file_put_contents($name, $pdf);
    printf("[%d] %s: %d KB, %.1fs, html=%dKB\n", $i, $u, strlen($pdf) / 1024, microtime(true) - $t0, strlen($inner) / 1024);
}
