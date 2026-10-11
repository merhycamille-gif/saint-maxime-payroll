<?php
// فحص: يبني PDF من ملف HTML لصفحة كاملة (يستخرج منطقة المستند) — php tools/_pdf_one.php page.html out.pdf [debug.html] [dir]
require_once __DIR__ . '/../config/database.php';
if (!defined('BASE_URL')) define('BASE_URL', '/saint-maxime-payroll/');
require_once __DIR__ . '/../includes/pdf_engine.php';
[$in, $out] = [$argv[1], $argv[2]]; $dbg = $argv[3] ?? ''; $dir = $argv[4] ?? 'rtl';
$s = file_get_contents($in);
libxml_use_internal_errors(true);
$d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $s); $xp = new DOMXPath($d);
$area = $xp->query('//*[@id="ppExportArea"]')->item(0)
    ?: $xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," doc-sheet ") or contains(concat(" ",normalize-space(@class)," ")," land-report ")]')->item(0)
    ?: $xp->query('//*[@id="pageContent"]')->item(0);
$inner = ''; foreach ($area->childNodes as $c) $inner .= $d->saveHTML($c);
$pdf = MsaPdf::fromHtml($inner, ['title' => 'test', 'dir' => $dir, 'debug_html' => $dbg ?: null, 'school' => 'Test']);
file_put_contents($out, $pdf); echo strlen($pdf), " bytes\n";
