<?php
/**
 * 🩺📧 (2026-10-16 «قلي انبعت بس ما وصل») تشخيص بريد الموقع — للمدير فقط، نصّ صرف:
 * طابور Postfix، آخر سطور سجلّ البريد (إن سُمح للقراءة)، سجلّات DNS (SPF/DKIM/MX)، وإرسال تجربة (?test=email).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
header('Content-Type: text/plain; charset=utf-8');
if (!isLoggedIn() || !isAdmin()) { http_response_code(401); exit("login required\n"); }
$sh = function (string $cmd): string {
    if (!function_exists('shell_exec')) return '(shell_exec معطّل)';
    $o = @shell_exec($cmd . ' 2>&1'); return $o === null || trim((string)$o) === '' ? '(لا شيء)' : trim((string)$o);
};
echo "host: " . programMailDomain() . " | mail() available: " . var_export(programMailAvailable(), true) . " | from: " . programMailAddress() . " | " . date('Y-m-d H:i:s') . "\n\n";
echo "=== mail queue (mailq)\n" . $sh('mailq | tail -n 40') . "\n\n";
echo "=== /var/log/mail.log (آخر 60)\n" . $sh('tail -n 60 /var/log/mail.log') . "\n\n";
foreach (['msapayroll.com' => DNS_TXT, 'msa._domainkey.msapayroll.com' => DNS_TXT, 'msapayroll.com MX' => DNS_MX] as $k => $t) {
    $name = explode(' ', $k)[0];
    echo "=== DNS $k\n";
    foreach ((array)@dns_get_record($name, $t) as $r) echo '  ' . ($r['txt'] ?? ($r['target'] ?? json_encode($r))) . "\n";
}
$test = trim((string)($_GET['test'] ?? ''));
if ($test !== '' && filter_var($test, FILTER_VALIDATE_EMAIL)) {
    echo "\n=== test send to $test\n";
    [$ok, $err] = programMailSend($test, 'اختبار بريد MSA Payroll ' . date('H:i:s'), "رسالة تجربة من تشخيص البريد — " . date('Y-m-d H:i:s') . "\nإذا وصلتك فالبريد شغّال.");
    echo $ok ? "mail() accepted\n" : "FAILED: $err\n";
    sleep(3);
    echo "--- queue after 3s\n" . $sh('mailq | tail -n 20') . "\n--- log after\n" . $sh('tail -n 15 /var/log/mail.log') . "\n";
}
