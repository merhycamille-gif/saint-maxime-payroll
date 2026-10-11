<?php
// يطبع عرض الأعمدة المقدَّر لأوّل جدول بصفحة (للمعايرة)
$u = $argv[1]; $jar = sys_get_temp_dir() . '/w_jar.txt'; @unlink($jar);
require_once __DIR__ . '/../config/database.php'; if (!defined('BASE_URL')) define('BASE_URL', '/saint-maxime-payroll/');
require_once __DIR__ . '/../includes/pdf_engine.php';
function rq($url, $post = null) { global $jar; $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true]); if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); } $r = curl_exec($ch); curl_close($ch); return (string)$r; }
$base = 'http://localhost/saint-maxime-payroll/'; $lg = rq($base . 'login.php'); preg_match('/name="csrf" value="([^"]+)"/', $lg, $cm);
rq($base . 'login.php', ['username' => 'admin', 'password' => ONLINE_SYNC_PASS, 'csrf' => $cm[1] ?? '']);
$html = rq($base . $u); libxml_use_internal_errors(true); $d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $html); $xp = new DOMXPath($d);
$t = $xp->query('//*[@id="pageContent"]//table[.//tr[2]]')->item(0);
$widths = []; $hdr = [];
foreach ($xp->query('.//tr', $t) as $ri => $tr) { $c = 0; foreach ($tr->childNodes as $cell) { if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td','th'], true)) continue; $cs = max(1, (int)$cell->getAttribute('colspan')); if ($cs === 1) { $txt = preg_replace('/\s+/u', ' ', trim($cell->textContent)); $w = 0; foreach (preg_split('/\n/', $txt) as $ln) $w = max($w, MsaPdf::textMm(trim($ln))); if (($widths[$c] ?? 0) < $w + 4.5) { $widths[$c] = $w + 4.5; $hdr[$c] = mb_substr($txt, 0, 40); } } $c += $cs; } }
ksort($widths); foreach ($widths as $c => $w) printf("%2d %6.1f  %s\n", $c, $w, $hdr[$c]); echo "SUM " . array_sum($widths) . "\n";
