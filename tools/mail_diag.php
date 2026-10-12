<?php
/**
 * 🩺 تشخيص بريد الخادم (mail()) — للمدير العام فقط. ?to=email
 * يجرّب عدّة صيغ ويطبع نتيجة كل واحدة وسبب الرفض إن وُجد (لا يغيّر شيئاً).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
if (!isSuperAdmin()) { http_response_code(403); exit('superadmin only'); }
header('Content-Type: text/plain; charset=utf-8');
$to = trim((string)($_GET['to'] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) exit("usage: ?to=email\n");
$dom = programMailDomain();
echo "host=", $_SERVER['HTTP_HOST'] ?? '', " domain=$dom\n";
echo "sendmail_path=", ini_get('sendmail_path'), " | sendmail_from=", ini_get('sendmail_from'), " | mail.add_x_header=", ini_get('mail.add_x_header'), " | disable_functions=", ini_get('disable_functions'), "\n";
echo "function mail exists: ", var_export(function_exists('mail'), true), "\n";
$tries = [
    'A minimal (no headers, no -f)'            => fn() => mail($to, 'MSA diag A', 'test A'),
    'B From no-reply@ (no -f)'                 => fn() => mail($to, 'MSA diag B', 'test B', "From: MSA Payroll <no-reply@$dom>\r\n"),
    'C From no-reply@ + -f'                    => fn() => mail($to, 'MSA diag C', 'test C', "From: MSA Payroll <no-reply@$dom>\r\n", "-fno-reply@$dom"),
    'D From noreply@ + -f'                     => fn() => mail($to, 'MSA diag D', 'test D', "From: MSA Payroll <noreply@$dom>\r\n", "-fnoreply@$dom"),
    'E From admin@ (no -f)'                    => fn() => mail($to, 'MSA diag E', 'test E', "From: MSA Payroll <admin@$dom>\r\n"),
    'F array headers From no-reply@'           => fn() => mail($to, 'MSA diag F', 'test F', ['From' => "no-reply@$dom", 'Reply-To' => $to]),
];
foreach ($tries as $label => $fn) {
    error_clear_last();
    $t0 = microtime(true);
    try { $ok = $fn(); } catch (Throwable $e) { $ok = false; echo "$label: EXCEPTION ", $e->getMessage(), "\n"; continue; }
    $le = error_get_last();
    printf("%s: %s (%.2fs)%s\n", $label, $ok ? 'OK' : 'FAIL', microtime(true) - $t0, $le ? ' err=' . ($le['message'] ?? '') : '');
}
echo "done\n";
