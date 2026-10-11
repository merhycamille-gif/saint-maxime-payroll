<?php
// فحص الترويسة-الصورة: يحاكي المتصفّح (innerHTML + lh_bg + lh_pad من ستايل المنطقة) ويبني PDF ووورد — php tools/_lh_test.php page.html outBase [dir]
require_once __DIR__ . '/../config/database.php';
if (!defined('BASE_URL')) define('BASE_URL', '/saint-maxime-payroll/');
require_once __DIR__ . '/../includes/docx_engine.php';
[$in, $outBase] = [$argv[1], $argv[2]]; $dir = $argv[3] ?? 'rtl';
$s = file_get_contents($in);
libxml_use_internal_errors(true);
$d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $s); $xp = new DOMXPath($d);
$area = $xp->query('//*[@id="ppExportArea"]')->item(0);
$inner = ''; foreach ($area->childNodes as $c) $inner .= $d->saveHTML($c);
$st = (string)$area->getAttribute('style'); $o = ['title' => 'lh', 'dir' => $dir, 'letter' => true];
if (preg_match('/url\([\'"]?([^\'")]+)[\'"]?\)/', $st, $m)) {
    $o['lh_bg'] = 'http://localhost' . $m[1];
    if (preg_match('/padding\s*:\s*([^;]+)/', $st, $pm)) { $v = preg_split('/\s+/', trim($pm[1])); $v = array_map(fn($x) => (float)$x, $v);
        $pad = count($v) === 1 ? [$v[0], $v[0], $v[0], $v[0]] : (count($v) === 2 ? [$v[0], $v[1], $v[0], $v[1]] : (count($v) === 3 ? [$v[0], $v[1], $v[2], $v[1]] : $v));
        $o['lh_pad'] = implode(',', $pad); }
}
echo "opts: ", json_encode($o, JSON_UNESCAPED_SLASHES), "\n";
file_put_contents($outBase . '.pdf', MsaPdf::fromHtml($inner, $o));
file_put_contents($outBase . '.docx', MsaDocx::fromHtml($inner, $o));
echo "pdf=", filesize($outBase . '.pdf'), " docx=", filesize($outBase . '.docx'), " err=", MsaDocx::$lastError, "\n";
