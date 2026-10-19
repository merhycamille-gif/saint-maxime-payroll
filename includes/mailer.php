<?php
/**
 * إرسال بريد عبر SMTP بلا أي مكتبة خارجية (PHP صرف: sockets + openssl).
 * يدعم SSL (465) و STARTTLS (587) + مرفقات (PDF) عبر MIME multipart.
 */

/**
 * يرسل بريداً مع مرفقات.
 * @param array $cfg  ['host','port','secure'(ssl|tls|none),'user','pass','from','from_name']
 * @param string|array $to  المستلِم/ون
 * @param array $attachments  كل عنصر ['name'=>, 'data'=>, 'mime'=>]
 * @return array [bool ok, string error]
 */
function smtpSendMail(array $cfg, $to, $subject, $bodyText, array $attachments = [], $isHtml = false)
{
    $host = $cfg['host'] ?? '';
    $port = (int)($cfg['port'] ?? 0);
    $secure = $cfg['secure'] ?? 'tls';
    $user = $cfg['user'] ?? '';
    $pass = $cfg['pass'] ?? '';
    $from = ($cfg['from'] ?? '') ?: $user;
    $fromName = $cfg['from_name'] ?? '';
    $to = array_filter(array_map('trim', (array)$to));
    if ($host === '' || $user === '' || empty($to)) return [false, 'إعدادات البريد غير مكتملة'];
    if (!$port) $port = ($secure === 'ssl') ? 465 : 587;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "تعذّر الاتصال بخادم البريد ($host:$port): $errstr"];
    stream_set_timeout($fp, 25);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $code = function ($r) { return (int)substr(ltrim($r), 0, 3); };

    $read(); // greeting
    $cmd("EHLO localhost");
    if ($secure === 'tls') {
        $r = $cmd("STARTTLS");
        if ($code($r) !== 220) { fclose($fp); return [false, "STARTTLS رُفض: " . trim($r)]; }
        $ok = @stream_socket_enable_crypto($fp, true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT);
        if (!$ok) { fclose($fp); return [false, "فشل تفعيل تشفير TLS"]; }
        $cmd("EHLO localhost");
    }
    $r = $cmd("AUTH LOGIN");
    if ($code($r) !== 334) { fclose($fp); return [false, "الخادم لا يدعم AUTH LOGIN: " . trim($r)]; }
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    if ($code($r) !== 235) { fclose($fp); return [false, "فشل تسجيل الدخول للبريد (تحقّق من الإيميل وكلمة السر/كلمة مرور التطبيق): " . trim($r)]; }

    $r = $cmd("MAIL FROM:<$from>");
    if ($code($r) !== 250) { fclose($fp); return [false, "MAIL FROM رُفض: " . trim($r)]; }
    foreach ($to as $rcpt) {
        $r = $cmd("RCPT TO:<$rcpt>");
        if ($code($r) !== 250 && $code($r) !== 251) { fclose($fp); return [false, "المستلِم رُفض ($rcpt): " . trim($r)]; }
    }
    $r = $cmd("DATA");
    if ($code($r) !== 354) { fclose($fp); return [false, "DATA رُفض: " . trim($r)]; }

    $boundary = '=_pp_' . bin2hex(random_bytes(8));
    $H  = "From: " . ($fromName ? '=?UTF-8?B?' . base64_encode($fromName) . '?= ' : '') . "<$from>\r\n";
    if (!empty($cfg['reply_to']) && filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) $H .= "Reply-To: <" . $cfg['reply_to'] . ">\r\n";   // 📧 (2026-10-17) الردّ لإيميل المدرسة
    $H .= "To: " . implode(', ', $to) . "\r\n";
    $H .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $H .= "MIME-Version: 1.0\r\n";
    $H .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    $body  = $H . "\r\n";
    $bodyMime = $isHtml ? 'text/html' : 'text/plain';
    $body .= "--$boundary\r\nContent-Type: $bodyMime; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyText)) . "\r\n";
    foreach ($attachments as $att) {
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: " . ($att['mime'] ?? 'application/octet-stream') . "; name=\"" . $att['name'] . "\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"" . $att['name'] . "\"\r\n\r\n";
        $body .= chunk_split(base64_encode($att['data'])) . "\r\n";
    }
    $body .= "--$boundary--\r\n";
    $body = preg_replace('/^\./m', '..', $body); // dot-stuffing

    fwrite($fp, $body . "\r\n.\r\n");
    $r = $read();
    $cmd("QUIT");
    fclose($fp);
    if ($code($r) !== 250) return [false, "الخادم رفض الرسالة: " . trim($r)];
    return [true, ''];
}

/**
 * 🔑 (2026-10-11 «عم حاول غيّر كلمة السر… بيرجع للقديم») فحص تسجيل الدخول فقط (بلا إرسال): يتّصل، STARTTLS، AUTH LOGIN، ثم QUIT.
 * @return array [bool ok, string error]
 */
function smtpCheckLogin(array $cfg)
{
    $host = $cfg['host'] ?? ''; $port = (int)($cfg['port'] ?? 0); $secure = $cfg['secure'] ?? 'tls';
    $user = $cfg['user'] ?? ''; $pass = $cfg['pass'] ?? '';
    if ($host === '' || $user === '') return [false, 'إعدادات البريد غير مكتملة'];
    if (!$port) $port = ($secure === 'ssl') ? 465 : 587;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client((($secure === 'ssl') ? "ssl://$host" : $host) . ":$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "تعذّر الاتصال بخادم البريد ($host:$port): $errstr"];
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $d; };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $code = function ($r) { return (int)substr(ltrim($r), 0, 3); };
    $read(); $cmd("EHLO localhost");
    if ($secure === 'tls') {
        $r = $cmd("STARTTLS");
        if ($code($r) !== 220) { fclose($fp); return [false, "STARTTLS رُفض: " . trim($r)]; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT)) { fclose($fp); return [false, 'فشل تفعيل تشفير TLS']; }
        $cmd("EHLO localhost");
    }
    $r = $cmd("AUTH LOGIN");
    if ($code($r) !== 334) { fclose($fp); return [false, "الخادم لا يدعم AUTH LOGIN: " . trim($r)]; }
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    $ok = $code($r) === 235;
    $cmd("QUIT"); fclose($fp);
    return $ok ? [true, ''] : [false, 'الخادم رفض الإيميل أو كلمة السر: ' . trim($r)];
}

/**
 * يبني إعدادات SMTP من جدول settings مع كشف الخادم تلقائياً حسب نطاق الإيميل.
 * @return array|null
 */
function smtpConfigFromSettings()
{
    $email = trim((string)getSetting('smtp_email', ''));
    if ($email === '') return null;
    $host = trim((string)getSetting('smtp_host', ''));
    $port = (int)getSetting('smtp_port', 0);
    $secure = trim((string)getSetting('smtp_secure', ''));
    if ($host === '') {
        $dom = strtolower(substr((string)strrchr($email, '@'), 1));
        if (in_array($dom, ['hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'hotmail.fr', 'outlook.fr'])) {
            $host = 'smtp-mail.outlook.com'; $port = 587; $secure = 'tls';
        } elseif ($dom === 'gmail.com' || $dom === 'googlemail.com') {
            $host = 'smtp.gmail.com'; $port = 587; $secure = 'tls';
        } elseif (in_array($dom, ['yahoo.com', 'yahoo.fr'])) {
            $host = 'smtp.mail.yahoo.com'; $port = 587; $secure = 'tls';
        } else {
            $host = 'mail.' . $dom; $port = 587; $secure = 'tls';
        }
    }
    if (!$port) $port = ($secure === 'ssl') ? 465 : 587;
    if ($secure === '') $secure = 'tls';
    return [
        'host' => $host, 'port' => $port, 'secure' => $secure,
        'user' => $email, 'pass' => (string)getSetting('smtp_pass', ''),
        'from' => $email, 'from_name' => programMailName(), // 📧 (2026-10-16 «لازم يطلع الإيميل المرسل من البرنامج باسم MSA Payroll هيك اتفقنا») لا اسم المدرسة
    ];
}

/* =====================================================================
 * 📧 (2026-10-12 «يكون في إيميل محدّد للبرنامج أرسل منّو، وأنا يكون عندي خيار أي مدارس بدّي أستعمل هالإيميل»)
 * إيميل البرنامج: MSA Payroll ‹noreply@<دومين الموقع>› يبعت منه خادم الموقع لحاله بلا كلمة سرّ (mail()).
 * لكل مدرسة خيار بصفحة المدارس: «الإرسال من إيميل البرنامج» (الافتراضي) أو «من إيميل المدرسة» (SMTP بإعدادات البريد).
 * الردّ (Reply-To) يرجع دائماً لإيميل المدرسة إن وُجد. msaSendMail = الباب الواحد لكل كبسات الإيميل بالبرنامج.
 * =================================================================== */
function programMailDomain(): string {
    $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return preg_replace('/:\d+$/', '', $host);
}
function programMailAddress(): string {
    $custom = trim((string)getSetting('program_email_addr', ''));
    if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_EMAIL)) return $custom;
    $d = programMailDomain();
    if (!programMailAvailable() && defined('ONLINE_SYNC_URL')) $d = preg_replace('/^www\./', '', (string)parse_url((string)ONLINE_SYNC_URL, PHP_URL_HOST)) ?: $d; // نسخة الكمبيوتر تبعت عبر الموقع ⇒ العنوان بدومين الموقع
    return 'no-reply@' . $d; // العنوان الذي ثبت أنّ خادم الموقع يقبله (تجربة 2026-09-13)
}
function programMailName(): string {
    $n = trim((string)getSetting('program_email_name', ''));
    return $n !== '' ? $n : 'MSA Payroll';
}
/** 🚀 (2026-10-17 «ما انبعت اليوم الإيميل من الرواتب» ⇒ مايكروسوفت تحظر IP السيرفر (S3150) مرّة بعد مرّة)
 *  خدمة الإرسال المتخصّصة (Resend) لإيميل البرنامج: المفتاح بالإعدادات `program_relay_key` (صفحة إعدادات البريد)، الدومين msapayroll.com موثَّق
 *  عندها (DKIM + SPF). الإرسال عبر SMTP smtp.resend.com:465 بالمفتاح؛ إن غاب المفتاح أو فشل ⇒ بريد الخادم (mail()) كما قبل. المرسِل يبقى «MSA Payroll». */
function programRelayConfig(string $fromName = '', string $replyTo = ''): ?array {
    $key = trim((string)getSetting('program_relay_key', ''));
    if ($key === '' || !function_exists('stream_socket_client')) return null;
    $from = programMailAddress();
    $dom = strtolower(substr((string)strrchr($from, '@'), 1)); $rd = strtolower((string)getSetting('program_relay_domain', 'msapayroll.com'));
    if ($rd !== '' && $dom !== $rd) $from = 'no-reply@' . $rd;   // الخدمة تبعت فقط من دومينها الموثَّق
    return ['host' => trim((string)getSetting('program_relay_host', '')) ?: 'smtp.resend.com', 'port' => 465, 'secure' => 'ssl',
            'user' => trim((string)getSetting('program_relay_user', '')) ?: 'resend', 'pass' => $key,
            'from' => $from, 'from_name' => $fromName !== '' ? $fromName : programMailName(), 'reply_to' => $replyTo];
}
function programMailAvailable(): bool {
    $d = programMailDomain();
    return function_exists('mail') && $d !== 'localhost' && strpos($d, '127.') !== 0 && strpos($d, '192.') !== 0 && strpos($d, '10.') !== 0;
}
/** خيار المدرسة: 'program' (إيميل البرنامج) أو 'school' (إيميل المدرسة بإعدادات البريد) */
function schoolEmailMode($schoolId = null): string {
    try {
        $db = getDB();
        if ($schoolId === null) { $s = function_exists('currentSchool') ? currentSchool() : null; $schoolId = (int)($s['id'] ?? 0); }
        if ((int)$schoolId > 0 && msaHasCol($db, 'schools', 'email_sender_mode')) {
            $m = (string)$db->query("SELECT email_sender_mode FROM schools WHERE id = " . (int)$schoolId)->fetchColumn();
            if ($m === 'school') return 'school';
        }
    } catch (Throwable $e) {}
    return 'program';
}
/** إيميل المدرسة المختارة (للردّ) */
function schoolReplyEmail($schoolId = null): string {
    try {
        $db = getDB();
        if ($schoolId === null) { $s = function_exists('currentSchool') ? currentSchool() : null; $schoolId = (int)($s['id'] ?? 0); }
        $e = (int)$schoolId > 0 ? trim((string)$db->query("SELECT email FROM schools WHERE id = " . (int)$schoolId)->fetchColumn()) : '';
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
    } catch (Throwable $e) {}
    $e = trim((string)getSetting('smtp_email', ''));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}
/**
 * 🔁 (2026-10-16) نسخة الكمبيوتر بلا بريد ⇒ تبعت الرسالة عبر الموقع: دخول بحساب المزامنة (كما local_sync.php) ثم POST JSON إلى pages/mail_relay.php.
 * الجلسة تُحفَظ بـtmp/mail_relay_cookies.txt فلا دخول بكل رسالة؛ عند انتهائها (401/302) يعاد الدخول مرّة.
 */
/**
 * 🔐 (2026-10-19) جلسة الموقع من نسخة الكمبيوتر — المصدر الواحد: دخول بحساب المزامنة، كوكي محفوظة بـtmp، وإعادة الدخول مرّة عند انتهائها.
 * @return array [string|false $out, int $code, string $err]  — $code 0 = لا اتصال
 */
function msaOnlineRequest(string $path, array $opt = [], int $timeout = 300): array {
    if (!defined('ONLINE_SYNC_URL') || !defined('ONLINE_SYNC_USER') || !defined('ONLINE_SYNC_PASS') || !function_exists('curl_init')) return [false, 0, 'إعدادات الموقع غير متوفّرة بهذه النسخة'];
    $site = rtrim((string)ONLINE_SYNC_URL, '/');
    $dir = dirname(__DIR__) . '/tmp'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $cj = $dir . '/mail_relay_cookies.txt';
    $ch = function (string $url, array $o = []) use ($cj, $timeout) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cj, CURLOPT_COOKIEFILE => $cj, CURLOPT_FOLLOWLOCATION => false,
                               CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MSA-Payroll-MailRelay'] + $o);
        $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
        return [$out, $code, $err];
    };
    $login = function () use ($ch, $site, $cj): string {
        @unlink($cj);
        [$html, $code, $err] = $ch($site . '/login.php');
        if ($html === false || $code !== 200) return 'لا اتصال بالموقع (' . ($err ?: $code) . ')';
        preg_match('/name="csrf" value="([^"]+)"/', (string)$html, $m);
        [, $code] = $ch($site . '/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username' => ONLINE_SYNC_USER, 'password' => ONLINE_SYNC_PASS, 'csrf' => $m[1] ?? ''])]);
        return $code === 302 ? '' : 'فشل الدخول إلى الموقع (رمز ' . $code . ')';
    };
    $go = fn() => $ch($site . '/' . ltrim($path, '/'), $opt);
    [$out, $code, $err] = is_file($cj) ? $go() : [null, 401, ''];
    if ($code === 401 || $code === 302 || $code === 0) {   // جلسة منتهية أو لا جلسة ⇒ دخول ثم إعادة
        $le = $login(); if ($le !== '') return [false, 0, $le];
        [$out, $code, $err] = $go();
    }
    return [$out, $code, (string)$err];
}
function programMailRelay($to, $subject, $body, array $attachments = [], $isHtml = false, $fromName = '', $replyTo = ''): array {
    if (!defined('ONLINE_SYNC_URL') || !defined('ONLINE_SYNC_USER') || !defined('ONLINE_SYNC_PASS') || !function_exists('curl_init'))
        return [false, 'الإرسال من إيميل البرنامج يعمل على الموقع (msapayroll.com) لا على نسخة الكمبيوتر'];
    $payload = json_encode([
        'to' => $to, 'subject' => (string)$subject, 'body' => (string)$body, 'isHtml' => (bool)$isHtml, 'fromName' => (string)$fromName, 'replyTo' => (string)$replyTo,
        'attachments' => array_map(fn($a) => ['name' => (string)($a['name'] ?? 'file'), 'mime' => (string)($a['mime'] ?? 'application/octet-stream'), 'data' => base64_encode((string)($a['data'] ?? ''))], $attachments),
    ], JSON_UNESCAPED_UNICODE);
    [$out, $code, $err] = msaOnlineRequest('pages/mail_relay.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json']], 120);
    if ($out === false || $code !== 200) return [false, 'عبر الموقع: تعذّر الاتصال (' . ($err ?: $code) . ')'];
    $r = json_decode((string)$out, true);
    if (!is_array($r)) return [false, 'عبر الموقع: ردّ غير مفهوم'];
    if (!empty($r['ok'])) $GLOBALS['msa_mail_last'] = ['via' => 'relay:' . (string)($r['via'] ?? ''), 'id' => (string)($r['id'] ?? '')];
    return [!empty($r['ok']), !empty($r['ok']) ? '' : 'عبر الموقع: ' . (string)($r['err'] ?? 'تعذّر الإرسال')];
}
/**
 * إرسال من إيميل البرنامج عبر بريد الخادم (mail()) — نصّ أو HTML + مرفقات.
 * @return array [bool ok, string error]
 */
function programMailSend($to, $subject, $body, array $attachments = [], $isHtml = false, $fromName = '', $replyTo = ''): array {
    // 🚀 (2026-10-17) خدمة الإرسال أوّلاً (تعمل من أي مكان، حتى من نسخة الكمبيوتر) ثم بريد الخادم كبديل
    // 📬 (2026-10-19 «أوقات بيوصلو أوقات ما بيوصلو — بدّي حلّ جذري»): الخدمة عبر HTTPS (API) أوّلاً — أثبت من منفذ 465 من الكمبيوتر —
    //    ثم SMTP الخدمة، ثم عبر الموقع من نسخة الكمبيوتر. وما دام مفتاح الخدمة موجوداً **لا نرجع أبداً** لبريد الخادم (mail()) الذي تحظره
    //    مايكروسوفت ويقول «أُرسل» بلا وصول: الفشل يُقال صراحةً ويُسجَّل. كل محاولة تترك أثرها بـ$GLOBALS['msa_mail_last'] (via/id) لسجلّ الإيميلات.
    $relayErr = '';
    $GLOBALS['msa_mail_last'] = ['via' => '', 'id' => ''];
    if ($rc = programRelayConfig((string)$fromName, (string)$replyTo)) {
        if (function_exists('curl_init')) {
            [$ok, $err, $pid] = resendApiSend($rc, $to, $subject, $body, $attachments, $isHtml);
            if ($ok) { $GLOBALS['msa_mail_last'] = ['via' => 'resend_api', 'id' => $pid]; return [true, '']; }
            $relayErr = 'خدمة الإرسال (API): ' . $err;
        }
        [$ok, $err] = smtpSendMail($rc, $to, $subject, $body, $attachments, $isHtml);
        if ($ok) { $GLOBALS['msa_mail_last'] = ['via' => 'resend_smtp', 'id' => '', 'note' => $relayErr]; return [true, '']; }
        $relayErr .= ($relayErr !== '' ? ' · ' : '') . 'خدمة الإرسال (SMTP): ' . $err;
        if (!programMailAvailable()) { $r = programMailRelay($to, $subject, $body, $attachments, $isHtml, $fromName, $replyTo); if (!$r[0]) $r[1] = $relayErr . ' · ' . $r[1]; return $r; }
        return [false, $relayErr . ' — لم يُرسَل (لا نستعمل بريد الخادم كبديل لأنّه لا يصل إلى Hotmail/Outlook)'];
    }
    if (!programMailAvailable()) { $r = programMailRelay($to, $subject, $body, $attachments, $isHtml, $fromName, $replyTo); if (!$r[0] && $relayErr !== '') $r[1] = $relayErr . ' · ' . $r[1]; return $r; }
    $from = programMailAddress();
    $fromName = $fromName !== '' ? $fromName : programMailName();
    $boundary = '=_pp_' . bin2hex(random_bytes(8));
    $h  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n";
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h .= "Reply-To: <$replyTo>\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    $m  = "--$boundary\r\nContent-Type: " . ($isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$body)) . "\r\n";
    foreach ($attachments as $a) {
        $an = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string)($a['name'] ?? 'file'));
        $m .= "--$boundary\r\nContent-Type: " . ($a['mime'] ?? 'application/octet-stream') . "; name=\"$an\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$an\"\r\n\r\n" . chunk_split(base64_encode((string)($a['data'] ?? ''))) . "\r\n";
    }
    $m .= "--$boundary--\r\n";
    $tos = is_array($to) ? implode(', ', $to) : (string)$to;
    $subj = '=?UTF-8?B?' . base64_encode((string)$subject) . '?=';
    // محاولات متدرّجة: بمغلّف المرسِل (-f) ثم بلا مغلّف (بعض الاستضافات ترفض -f لعنوان بلا صندوق) — ويُسجَّل سبب الرفض
    $lastErr = '';
    foreach (['-f' . $from, null] as $extra) {
        error_clear_last();
        $sent = $extra === null ? @mail($tos, $subj, $m, $h) : @mail($tos, $subj, $m, $h, $extra);
        if ($sent) { $GLOBALS['msa_mail_last'] = ['via' => 'server_mail', 'id' => '']; return [true, '']; }
        $le = error_get_last(); if ($le) $lastErr = (string)($le['message'] ?? '');
    }
    return [false, ($relayErr !== '' ? $relayErr . ' · ' : '') . 'خادم الموقع رفض إرسال البريد' . ($lastErr !== '' ? ' (' . mb_substr(strip_tags($lastErr), 0, 160) . ')' : '')];
}
/**
 * 🚪 الباب الواحد لإرسال الإيميل بكل البرنامج: يختار المصدر حسب خيار المدرسة، ويرجع للبديل إذا فشل الأوّل.
 * @return array [bool ok, string error, string via ('program'|'smtp'), string fromAddress]
 */
function msaSendMail($to, $subject, $body, array $attachments = [], $isHtml = false, $schoolId = null, $fromName = ''): array {
    // 📬 (2026-10-19) الرسالة نفسها (المستلِم + الموضوع + الملف) خلال دقيقتين لا تُرسَل مرّتين — تُعاد نتيجة الأولى (via = 'dup')
    $dupKey = msaMailDupKey($to, $subject, $attachments, $body);
    if ($prev = msaMailRecentDuplicate($dupKey)) return [true, '', 'dup', (string)$prev['via']];
    $r = msaSendMailInner($to, $subject, $body, $attachments, $isHtml, $schoolId, $fromName);
    msaMailLogWrite($to, $subject, $attachments, $schoolId, $r, $dupKey);
    return $r;
}
function msaSendMailInner($to, $subject, $body, array $attachments, $isHtml, $schoolId, $fromName): array {
    $fromName = programMailName(); // 📧 (2026-10-16 «طالع باسم كلية سان مكسيم ما بدي هيك») اسم المرسِل دائماً «MSA Payroll» مهما مرّرت الصفحة (المدرسة تبقى بعنوان الردّ وبنصّ الرسالة)
    $mode = schoolEmailMode($schoolId);
    $cfg = smtpConfigFromSettings();
    $reply = schoolReplyEmail($schoolId);
    $errs = [];
    $trySmtp = function () use ($cfg, $to, $subject, $body, $attachments, $isHtml, $fromName, &$errs) {
        if (!$cfg) { $errs[] = 'إعدادات البريد (إيميل المدرسة) غير مضبوطة'; return [false, '']; }
        $c = $cfg; if ($fromName !== '') $c['from_name'] = $fromName;
        [$ok, $err] = smtpSendMail($c, $to, $subject, $body, $attachments, $isHtml);
        if (!$ok) $errs[] = 'إيميل المدرسة: ' . $err;
        return [$ok, $c['from']];
    };
    $tryProgram = function () use ($to, $subject, $body, $attachments, $isHtml, $fromName, $reply, &$errs) {
        [$ok, $err] = programMailSend($to, $subject, $body, $attachments, $isHtml, $fromName, $reply);
        if (!$ok) $errs[] = 'إيميل البرنامج: ' . $err;
        return [$ok, programMailAddress()];
    };
    $GLOBALS['msa_mail_last'] = ['via' => '', 'id' => ''];
    if ($mode === 'school') {
        [$ok, $from] = $trySmtp(); if ($ok) { $GLOBALS['msa_mail_last'] = ['via' => 'school_smtp', 'id' => '']; return [true, '', 'smtp', $from]; }
        [$ok, $from] = $tryProgram(); if ($ok) return [true, '', 'program', $from];
    } else {
        [$ok, $from] = $tryProgram(); if ($ok) return [true, '', 'program', $from];
        [$ok, $from] = $trySmtp(); if ($ok) { $GLOBALS['msa_mail_last'] = ['via' => 'school_smtp', 'id' => '']; return [true, '', 'smtp', $from]; }
    }
    return [false, implode(' · ', array_unique($errs)) ?: 'تعذّر الإرسال', '', ''];
}

/* ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
 * 📬 (2026-10-19 «في شغلة عم بتصير بإرسال الإيميلات: أوقات بيوصلو أوقات ما بيوصلو — بدّي حلّ جذري وعملي بدون أخطاء»)
 *  1) الإرسال عبر خدمة الإرسال بالـAPI (HTTPS) — يرجع رقم الرسالة عند الخدمة.
 *  2) سجلّ الإيميلات (mail_log، يتركّب ذاتياً، مفتاحه نصّي كي يندمج بالمزامنة بلا تصادم): كل رسالة ومن أين خرجت وحالتها الحقيقية
 *     عند الخدمة (وصل / ارتدّ / متأخّر / شكوى) — تُحدَّث بسؤال الخدمة عن الرسائل المعلّقة (pages/mail_log.php).
 *  3) لا تكرار: الرسالة نفسها خلال دقيقتين لا تخرج مرّتين.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════════════════ */
/** إرسال عبر Resend HTTP API. @return [ok, err, id] */
function resendApiSend(array $rc, $to, $subject, $body, array $attachments = [], $isHtml = false): array {
    $tos = array_values(array_filter(array_map('trim', (array)$to), fn($t) => filter_var($t, FILTER_VALIDATE_EMAIL)));
    if (!$tos) return [false, 'عنوان المستلِم غير صالح', ''];
    $payload = ['from' => ($rc['from_name'] !== '' ? '"' . str_replace('"', '', $rc['from_name']) . '" ' : '') . '<' . $rc['from'] . '>',
                'to' => $tos, 'subject' => (string)$subject];
    // نصّ + نسخة HTML دائماً (الحالة «فُتح» عند الخدمة تحتاج HTML)
    if ($isHtml) { $payload['html'] = (string)$body; $payload['text'] = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</div>|</tr>#i', "
", (string)$body)), ENT_QUOTES, 'UTF-8')); }
    else { $payload['text'] = (string)$body; $payload['html'] = '<div dir="auto" style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.8;white-space:pre-wrap">' . nl2br(htmlspecialchars((string)$body, ENT_QUOTES, 'UTF-8')) . '</div>'; }
    if (!empty($rc['reply_to']) && filter_var($rc['reply_to'], FILTER_VALIDATE_EMAIL)) $payload['reply_to'] = [$rc['reply_to']];
    foreach ($attachments as $a) {
        $payload['attachments'][] = ['filename' => (string)($a['name'] ?? 'file'), 'content' => base64_encode((string)($a['data'] ?? '')),
                                     'content_type' => (string)($a['mime'] ?? 'application/octet-stream')];
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);   // نصّ بترميز معطوب لا يمنع الإرسال
    if ($json === false) return [false, 'تعذّر تجهيز الرسالة (' . json_last_error_msg() . ')', ''];
    $lastErr = '';
    for ($try = 1; $try <= 2; $try++) {   // محاولتان على انقطاع الشبكة فقط (لا على رفض الخدمة)
        $c = curl_init('https://api.resend.com/emails');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $rc['pass'], 'Content-Type: application/json', 'Idempotency-Key: ' . substr(sha1($json), 0, 40)],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MSA-Payroll']);
        $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $cerr = curl_error($c); curl_close($c);
        if ($out === false || $code === 0) { $lastErr = 'لا اتصال بخدمة الإرسال (' . $cerr . ')'; usleep(800000); continue; }
        $r = json_decode((string)$out, true);
        if ($code >= 200 && $code < 300 && !empty($r['id'])) return [true, '', (string)$r['id']];
        return [false, 'رفضت الخدمة الرسالة (' . $code . '): ' . (string)($r['message'] ?? mb_substr((string)$out, 0, 160)), ''];
    }
    return [false, $lastErr, ''];
}
/** حالة رسالة عند الخدمة: delivered / bounced / delivery_delayed / complained / sent / … أو '' إن تعذّر */
function resendApiStatus(string $id): string {
    $rc = programRelayConfig(); if (!$rc || $id === '' || !function_exists('curl_init')) return '';
    $c = curl_init('https://api.resend.com/emails/' . rawurlencode($id));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $rc['pass']], CURLOPT_SSL_VERIFYPEER => true]);
    $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    if ($out === false || $code !== 200) return '';
    $r = json_decode((string)$out, true);
    return strtolower(trim((string)($r['last_event'] ?? '')));
}
function ensureMailLogTable(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS mail_log (
            id CHAR(24) NOT NULL PRIMARY KEY,
            created_at DATETIME NOT NULL,
            sent_by VARCHAR(80) NULL,
            school_id INT NULL,
            to_addr VARCHAR(500) NOT NULL,
            subject VARCHAR(500) NULL,
            file_name VARCHAR(255) NULL,
            via VARCHAR(30) NOT NULL DEFAULT '',
            provider_id VARCHAR(80) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'sent',
            error TEXT NULL,
            dup_key CHAR(40) NULL,
            checked_at DATETIME NULL,
            KEY (created_at), KEY (status), KEY (dup_key)
        ) DEFAULT CHARSET=utf8mb4");
        $db = getDB();
        if (!msaHasCol($db, 'mail_log', 'share_token')) $db->exec("ALTER TABLE mail_log ADD COLUMN share_token CHAR(32) NULL AFTER file_name, ADD KEY (share_token)");
        // 📦 الملفات المشارَكة برابط (الكبيرة) — الملف نفسه على الموقع uploads/share، والصفّ يندمج بالمزامنة (مفتاح نصّي)
        $db->exec("CREATE TABLE IF NOT EXISTS shared_files (
            token CHAR(32) NOT NULL PRIMARY KEY,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
            size_bytes BIGINT NOT NULL DEFAULT 0,
            to_addr VARCHAR(500) NULL,
            downloads INT NOT NULL DEFAULT 0,
            last_download_at DATETIME NULL,
            KEY (created_at)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}
/* ═══ 📦 (2026-10-19 «أوقات بدّي أبعت ملف كتير كبير — لازم طريقة بدون أخطاء ويوصل بسرعة») ═══
 *  الملف يُرفَع على الموقع (uploads/share) ويُبعَث للمستلم رابط تنزيل آمن (dl.php?t=…) — الإيميل يبقى صغيراً فيصل فوراً ولا يرفضه أي بريد.
 *  ≤ 7MB يُرفَق أيضاً. من نسخة الكمبيوتر يُدفَع الملف إلى الموقع عبر pages/file_share.php (جلسة المزامنة). الرابط صالح 90 يوماً.
 *  كل تنزيل يُحصى ويظهر بسجلّ الإيميلات (⬇️ نُزِّل). */
const MSA_SHARE_ATTACH_MAX = 7 * 1024 * 1024;
const MSA_SHARE_DAYS = 90;
function msaIsOnlineHost(): bool { return stripos((string)($_SERVER['HTTP_HOST'] ?? ''), 'msapayroll.com') !== false; }
function msaShareDir(): string { $d = dirname(__DIR__) . '/uploads/share'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function msaShareUrl(string $token): string {
    $base = msaIsOnlineHost() ? ('https://' . preg_replace('/^www\./', '', (string)$_SERVER['HTTP_HOST']) . '/') : (defined('ONLINE_SYNC_URL') ? rtrim((string)ONLINE_SYNC_URL, '/') . '/' : BASE_URL);
    return $base . 'dl.php?t=' . $token;
}
/** تخزين الملف هنا (على هذه النسخة) — يُستعمل على الموقع فقط. @return [ok, token|err] */
function msaShareStoreLocal(string $name, string $data, string $mime, string $toAddr = ''): array {
    try {
        ensureMailLogTable();
        if ($data === '') return [false, 'الملف فارغ'];
        $token = bin2hex(random_bytes(16));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin'; $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $path = msaShareDir() . '/' . $token . '.' . $ext;
        if (file_put_contents($path, $data) === false) return [false, 'تعذّر حفظ الملف على الموقع'];
        getDB()->prepare("INSERT INTO shared_files (token, created_at, expires_at, file_name, mime, size_bytes, to_addr) VALUES (?,?,?,?,?,?,?)")
            ->execute([$token, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + MSA_SHARE_DAYS * 86400), mb_substr($name, 0, 255), mb_substr($mime, 0, 100), strlen($data), mb_substr($toAddr, 0, 500)]);
        msaSharePurgeExpired();
        return [true, $token];
    } catch (Throwable $e) { return [false, 'تعذّر تسجيل الملف: ' . $e->getMessage()]; }
}
/** رفع الملف إلى الموقع من أي نسخة: على الموقع = تخزين مباشر؛ من الكمبيوتر = دفع إلى pages/file_share.php. @return [ok, token|err] */
function msaShareFile(string $name, string $data, string $mime, string $toAddr = ''): array {
    if (msaIsOnlineHost()) return msaShareStoreLocal($name, $data, $mime, $toAddr);
    if (!function_exists('curl_init')) return [false, 'الرفع إلى الموقع يحتاج curl'];
    $tmpDir = dirname(__DIR__) . '/tmp'; if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    $tmp = $tmpDir . '/share_' . bin2hex(random_bytes(6)) . '.bin';
    if (file_put_contents($tmp, $data) === false) return [false, 'تعذّر تجهيز الملف للرفع'];
    $post = ['to' => $toAddr, 'file' => new CURLFile($tmp, $mime, $name)];
    [$out, $code, $err] = msaOnlineRequest('pages/file_share.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post], 900);
    @unlink($tmp);
    if ($out === false || $code !== 200) return [false, 'تعذّر رفع الملف إلى الموقع (' . ($err ?: $code) . ')'];
    $r = json_decode((string)$out, true);
    if (!is_array($r) || empty($r['ok']) || empty($r['token'])) return [false, 'الموقع رفض الملف: ' . (string)($r['err'] ?? mb_substr((string)$out, 0, 120))];
    return [true, (string)$r['token']];
}
function msaSharePurgeExpired(): void {
    try {
        $db = getDB();
        $old = $db->query("SELECT token, file_name FROM shared_files WHERE expires_at < NOW() LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($old as $o) {
            foreach (glob(msaShareDir() . '/' . $o['token'] . '.*') ?: [] as $f) @unlink($f);
            $db->prepare("DELETE FROM shared_files WHERE token = ?")->execute([$o['token']]);
        }
    } catch (Throwable $e) {}
}
function msaHumanSize(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024) . ' KB' : $b . ' B'); }
function msaMailDupKey($to, $subject, array $attachments, $body): string {
    $tos = array_map('strtolower', array_map('trim', (array)$to)); sort($tos);
    $h = implode(',', $tos) . '|' . trim((string)$subject) . '|' . md5((string)$body);
    foreach ($attachments as $a) $h .= '|' . (string)($a['name'] ?? '') . ':' . md5((string)($a['data'] ?? ''));
    return sha1($h);
}
/** الرسالة نفسها أُرسلت بنجاح خلال آخر دقيقتين؟ ⇒ صفّها */
function msaMailRecentDuplicate(string $dupKey): ?array {
    try {
        ensureMailLogTable();
        $st = getDB()->prepare("SELECT * FROM mail_log WHERE dup_key = ? AND status <> 'failed' AND created_at >= ? ORDER BY created_at DESC LIMIT 1");
        $st->execute([$dupKey, date('Y-m-d H:i:s', time() - 120)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function msaMailLogWrite($to, $subject, array $attachments, $schoolId, array $r, string $dupKey): void {
    try {
        ensureMailLogTable();
        $last = $GLOBALS['msa_mail_last'] ?? ['via' => '', 'id' => ''];
        $via = (string)($last['via'] ?? ''); if ($via === '' && !empty($r[0])) $via = (string)($r[2] ?? '');
        $files = implode(', ', array_map(fn($a) => (string)($a['name'] ?? ''), $attachments));
        $id = date('ymdHis') . substr(bin2hex(random_bytes(6)), 0, 12);
        getDB()->prepare("INSERT INTO mail_log (id, created_at, sent_by, school_id, to_addr, subject, file_name, via, provider_id, status, error, dup_key)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, date('Y-m-d H:i:s'), (string)($_SESSION['username'] ?? (php_sapi_name() === 'cli' ? 'cli' : '')), $schoolId !== null ? (int)$schoolId : null,
                       mb_substr(implode(', ', (array)$to), 0, 500), mb_substr((string)$subject, 0, 500), mb_substr($files, 0, 255),
                       $via, (string)($last['id'] ?? '') ?: null, !empty($r[0]) ? 'sent' : 'failed', !empty($r[0]) ? ((string)($last['note'] ?? '') ?: null) : (string)($r[1] ?? ''), $dupKey]);
        $GLOBALS['msa_mail_log_id'] = $id;
    } catch (Throwable $e) {}
}
/** يسأل الخدمة عن الرسائل المعلّقة (آخر 10 أيام، حتى 30 رسالة) ويحدّث حالتها. يرجع عدد المحدَّثة. */
/** آخر رسائل الخدمة (لمطابقة ما خرج بـSMTP بلا رقم) — [ [id, to[], subject, created_at(UTC), last_event], … ] */
function resendApiRecent(int $limit = 50): array {
    $rc = programRelayConfig(); if (!$rc || !function_exists('curl_init')) return [];
    $c = curl_init('https://api.resend.com/emails?limit=' . (int)$limit);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $rc['pass']], CURLOPT_SSL_VERIFYPEER => true]);
    $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    if ($out === false || $code !== 200) return [];
    $r = json_decode((string)$out, true);
    return is_array($r['data'] ?? null) ? $r['data'] : [];
}
function msaMailLogRefresh(int $max = 30): int {
    $n = 0;
    try {
        ensureMailLogTable(); $db = getDB();
        // رسائل خرجت بـSMTP الخدمة (بلا رقم): نطابقها بالمستلم + الموضوع + الوقت (±30 د) مع سجلّ الخدمة لنعرف حالتها
        $noId = $db->prepare("SELECT id, to_addr, subject, created_at FROM mail_log WHERE (provider_id IS NULL OR provider_id = '') AND via IN ('resend_smtp','relay:resend_smtp') AND status = 'sent' AND created_at >= ? ORDER BY created_at DESC LIMIT 20");
        $noId->execute([date('Y-m-d H:i:s', time() - 10 * 86400)]);
        $pending = $noId->fetchAll(PDO::FETCH_ASSOC);
        if ($pending) {
            $recent = resendApiRecent(100); $setId = $db->prepare("UPDATE mail_log SET provider_id = ? WHERE id = ?");
            foreach ($pending as $pr) {
                $tP = strtotime($pr['created_at']); $tosP = array_map('strtolower', array_map('trim', explode(',', (string)$pr['to_addr'])));
                foreach ($recent as $e) {
                    $tE = strtotime(substr((string)($e['created_at'] ?? ''), 0, 19) . ' UTC');
                    $tosE = array_map('strtolower', (array)($e['to'] ?? []));
                    $sameSubj = (string)($e['subject'] ?? '') === (string)$pr['subject'];
                    if (!empty($e['id']) && !array_diff($tosP, $tosE) && (($sameSubj && abs($tE - $tP) <= 1800) || abs($tE - $tP) <= 120)) { $setId->execute([(string)$e['id'], $pr['id']]); break; }
                }
            }
        }
        $st = $db->prepare("SELECT id, provider_id, status FROM mail_log WHERE provider_id IS NOT NULL AND provider_id <> '' AND status IN ('sent','delivery_delayed','queued')
                            AND created_at >= ? ORDER BY created_at DESC LIMIT " . (int)$max);
        $st->execute([date('Y-m-d H:i:s', time() - 10 * 86400)]);
        $up = $db->prepare("UPDATE mail_log SET status = ?, checked_at = ? WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ev = resendApiStatus((string)$row['provider_id']); if ($ev === '') continue;
            $up->execute([mb_substr($ev, 0, 24), date('Y-m-d H:i:s'), $row['id']]);
            if ($ev !== $row['status']) $n++;
        }
    } catch (Throwable $e) {}
    return $n;
}
/** تسمية الحالة بالعربي/الفرنسي + لون (بلا أحمر ولا برتقالي: كحلي للفشل، تركواز للتأخير) */
function msaMailStatusLabel(string $st): array {
    switch ($st) {
        case 'delivered': return ['✅ وصل / Délivré', '#dcfce7', '#166534'];
        case 'opened': case 'clicked': return ['✅ وصل وفُتح / Ouvert', '#dcfce7', '#166534'];
        case 'bounced': return ['⛔ ارتدّ — العنوان غير موجود / Rejeté', '#e0e7ff', '#173f6f'];
        case 'complained': return ['🚫 المستلم علّمها Spam / Signalé', '#e0e7ff', '#173f6f'];
        case 'delivery_delayed': return ['⏳ متأخّر — الخدمة تعيد المحاولة / Retardé', '#cffafe', '#0e7490'];
        case 'failed': return ['✕ لم يُرسَل / Non envoyé', '#e0e7ff', '#173f6f'];
        case 'canceled': return ['✕ أُلغي / Annulé', '#e0e7ff', '#173f6f'];
        case 'sent': case 'queued': return ['📤 خرج — بانتظار تأكيد الوصول / Envoyé', '#f1f5f9', '#334155'];
    }
    return [$st ?: '—', '#f1f5f9', '#334155'];
}
