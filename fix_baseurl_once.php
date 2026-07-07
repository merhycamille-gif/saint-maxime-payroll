<?php
/**
 * سكربت صيانة لمرّة واحدة (محمي بمفتاح):
 * يجعل تعريف BASE_URL في config/database.php يتكيّف مع الدومين:
 *   - على msapayroll.com (دومين مستقلّ، جذرُه = مجلّد البرنامج) → '/'
 *   - على أي مضيف آخر (maximos-rawatib.com / localhost) → '/saint-maxime-payroll/'
 * آمن: يأخذ نسخة احتياطية، يفحص سلامة الصياغة قبل الكتابة، ولا يعمل شيئاً إن كان مطبّقاً.
 * يُشغَّل مرّة واحدة عبر المتصفّح ثم يُحذف (اختياري — محميّ بمفتاح).
 */
$KEY = 'c4e91b7a2f6d8305';
if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit('403 forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/config/database.php';
if (!is_file($f))       { exit('config/database.php غير موجود'); }
if (!is_writable($f))   { exit('config/database.php غير قابل للكتابة (صلاحيات)'); }

$src = file_get_contents($f);

// مطبّق مسبقاً؟
if (strpos($src, 'msapayroll.com') !== false) { exit('✓ مطبّق مسبقاً — لا حاجة لأي تغيير'); }

$newLine = "define('BASE_URL', (!empty(\$_SERVER['HTTP_HOST']) && stripos(\$_SERVER['HTTP_HOST'], 'msapayroll.com') !== false) ? '/' : '/saint-maxime-payroll/');";

$count = 0;
$out = preg_replace_callback(
    "/define\\(\\s*'BASE_URL'\\s*,\\s*'[^']*'\\s*\\)\\s*;/",
    function ($m) use ($newLine) { return $newLine; },
    $src, 1, $count
);

if ($out === null || $count < 1) { exit('لم يُعثَر على سطر BASE_URL — لم يُغيَّر شيء'); }

// فحص سلامة صياغة PHP قبل أي كتابة (يمنع تعطيل الموقع)
try { token_get_all($out, TOKEN_PARSE); }
catch (\ParseError $e) { exit('فشل فحص الصياغة — أُلغيت العملية: ' . $e->getMessage()); }

// نسخة احتياطية ثم كتابة
$bak = $f . '.bak_baseurl';
@copy($f, $bak);
if (file_put_contents($f, $out) === false) { exit('فشلت الكتابة'); }

echo "✓ تمّ — BASE_URL صار يتكيّف مع الدومين.\n";
echo "نسخة احتياطية: " . basename($bak) . "\n";
echo "جرّب الآن: https://msapayroll.com/login.php";
