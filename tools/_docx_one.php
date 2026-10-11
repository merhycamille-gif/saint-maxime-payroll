<?php
// فحص: يبني وورد (MsaDocx) من ملف HTML لصفحة كاملة — php tools/_docx_one.php page.html out.docx [dir]
require_once __DIR__ . '/../config/database.php';
if (!defined('BASE_URL')) define('BASE_URL', '/saint-maxime-payroll/');
require_once __DIR__ . '/../includes/docx_engine.php';
[$in, $out] = [$argv[1], $argv[2]]; $dir = $argv[3] ?? 'rtl';
$s = file_get_contents($in);
libxml_use_internal_errors(true);
$d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $s); $xp = new DOMXPath($d);
$area = $xp->query('//*[@id="ppExportArea"]')->item(0) ?: $xp->query('//*[@id="pageContent"]')->item(0);
$inner = ''; foreach ($area->childNodes as $c) $inner .= $d->saveHTML($c);
$docx = MsaDocx::fromHtml($inner, ['title' => 'test', 'dir' => $dir, 'letter' => true, 'debug_html' => $out . '.in.html']);
file_put_contents($out, $docx); echo strlen($docx), " bytes; lastError=", MsaDocx::$lastError, "\n";
