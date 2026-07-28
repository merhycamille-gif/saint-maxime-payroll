<?php
/**
 * طباعة أي صفحة/نموذج في البرنامج إلى PDF رسمي طبق الأصل (كما تظهر بالطباعة) عبر Chrome (puppeteer).
 *   ?target=<مسار داخلي مُرمَّز> مثل: pages/official_forms.php?form=cnss_contrib_monthly&month=5&year=2026
 * يحافظ على الجلسة (نفس كوكي PHPSESSID) فتظهر الصفحة مسجَّلة الدخول وبالمدرسة المختارة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$target = $_GET['target'] ?? '';
// أمان: مسار داخلي فقط (يبدأ بـ pages/ أو الجذر) بلا مضيف/سكيم خارجي
$target = ltrim($target, '/');
if ($target === '' || preg_match('#^[a-z]+://#i', $target) || strpos($target, '..') !== false
    || !preg_match('#^[A-Za-z0-9_./?&=%\-+:]+$#', $target)) {
    http_response_code(400); die('طلب غير صالح');
}

$node = null;
foreach (['C:/Program Files/nodejs/node.exe', 'node'] as $n) {
    if ($n === 'node' || @is_file($n)) { $node = $n; break; }
}
$script = __DIR__ . '/../tools/page_to_pdf.js';
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
// رابط العودة لصفحة الطباعة مع تشغيل طباعة المتصفّح تلقائياً (للبيئة بلا أدوات خادم = الموقع الأونلاين):
// المستخدم يختار «حفظ كـ PDF» من حوار الطباعة فيطلع نفس الشكل الرسمي بلا أي أداة على الخادم.
$autoprintUrl = BASE_URL . $target . (strpos($target, '?') !== false ? '&' : '?') . '_autoprint=1';
if (!$node || !is_file($script) || !is_dir(__DIR__ . '/../tools/node_modules/puppeteer-core')) {
    header('Location: ' . $autoprintUrl); exit;
}

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$url = $base . BASE_URL . $target . (strpos($target, '?') !== false ? '&' : '?') . '_pdf=1';
if (!empty($_GET['fit'])) $url .= '&_fit=1'; // وضع ملء صفحة A4 واحدة (عرض تصميم ثابت متناسب)

$cookieName = session_name();
$cookieValue = session_id();
session_write_close(); // حرّر قفل الجلسة حتى يقدر Chrome يفتح الصفحة بنفس الجلسة

$uid = uniqid('page', true);
$out = $tmpDir . '/' . $uid . '.pdf';
// fit=1 → تصغير/تكبير تلقائي ليملأ صفحة A4 واحدة بلا قصّ (للكشوف المفردة)
$fitArg = !empty($_GET['fit']) ? 'fit' : '';
// 🔴 ويندوز: escapeshellarg يستبدل علامة % بمسافة، فيكسر روابط فيها معاملات مرمّزة
// (مثل cols%5B%5D لأعمدة التقارير المختارة) → نمرّر الرابط base64 (بلا %) ويفكّه السكربت.
$cmd = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' '
     . escapeshellarg(str_replace('\\', '/', $out)) . ' ' . escapeshellarg('b64:' . base64_encode($url)) . ' '
     . escapeshellarg($cookieName) . ' ' . escapeshellarg($cookieValue) . ' '
     . escapeshellarg($fitArg) . ' 2>&1';
$res = @shell_exec($cmd);
if (!empty($_GET['pdebug'])) @file_put_contents($tmpDir . '/page_pdf_debug.txt', "URL: $url\nCMD: $cmd\nOUT: $res\nPDF: " . (is_file($out) ? filesize($out) : 'no'));

if (!is_file($out) || filesize($out) < 400) {
    @unlink($out);
    header('Location: ' . $autoprintUrl); exit; // fallback للطباعة اليدوية (طباعة المتصفّح → حفظ كـPDF)
}
$data = file_get_contents($out);
@unlink($out);

// اسم الملف من نوع النموذج/التقرير
$name = preg_replace('/[^a-z0-9_]+/i', '_', (string)($_GET['name'] ?? 'document'));
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '_' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-cache');
echo $data;
