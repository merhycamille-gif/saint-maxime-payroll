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
        'from' => $email, 'from_name' => (string)getSetting('smtp_from_name', getSetting('school_name_ar', '')),
    ];
}
