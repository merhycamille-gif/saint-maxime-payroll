<?php
/**
 * 📲 (2026-11-20 «أنا ما فيي أبعت واتس دغري من هالبرنامج» ⇒ «فينا نبقى نبعت من الرقم اللي بالأقساط» ⇒ «أكيد بدّي ياها… الرسمي والأفضل والأسهل،
 *    ويكون في خيار أنا أختار الرقم اللي بدّي أبعتله») الإرسال المباشر بالواتساب من برنامج الرواتب:
 *    المستند PDF يُرفَع إلى WhatsApp Cloud API (Meta) ويُرسَل بقالب معتمَد «msa_payroll_document» (رأس مستند + اسم المستند، توقيع MSA Payroll بلا اسم مدرسة)
 *    من رقم المؤسّسة نفسه المستعمل ببرنامج الأقساط. الإعدادات بجدول settings (لا شيء بالكود):
 *      wa_cloud_token · wa_cloud_phone_id · wa_cloud_waba_id · wa_cloud_from (الرقم الظاهر) · wa_cloud_tpl_doc · wa_cloud_tpl_lang · wa_cloud_disabled
 *    كل إرسال يُسجَّل بسجلّ الإرسال (mail_log، via = whatsapp). لا إرسال جماعي: مستند واحد لرقم واحد بكل كبسة.
 */

/** إعدادات الربط. */
function msaWaCfg(): array {
    static $c = null; if ($c !== null) return $c;
    $g = fn(string $k, string $d = '') => function_exists('getSetting') ? trim((string)getSetting($k, $d)) : $d;
    $c = ['token' => $g('wa_cloud_token'), 'phone_id' => $g('wa_cloud_phone_id'), 'waba_id' => $g('wa_cloud_waba_id'), 'from' => $g('wa_cloud_from'),
          'tpl' => $g('wa_cloud_tpl_doc', 'msa_payroll_document') ?: 'msa_payroll_document', 'lang' => $g('wa_cloud_tpl_lang', 'ar') ?: 'ar', 'disabled' => $g('wa_cloud_disabled') === '1'];
    return $c;
}
function msaWaEnabled(): bool { $c = msaWaCfg(); return !$c['disabled'] && $c['token'] !== '' && $c['phone_id'] !== ''; }

/** رقم بصيغة واتساب الدولية بلا «+» (لبنان 961): «03 123 456» ⇒ 9613123456 · «+961 70 111 222» ⇒ 96170111222 · «0033…» ⇒ 33… — '' إن كان غير صالح. */
function msaWaPhone(string $raw): string {
    $raw = trim($raw); $plus = strpos($raw, '+') === 0;
    $p = preg_replace('/\D/', '', $raw); if ($p === '') return '';
    if (strpos($p, '00') === 0) { $p = substr($p, 2); $plus = true; }
    if (!$plus) {
        if ($p[0] === '0') $p = '961' . substr($p, 1);
        elseif (strlen($p) <= 8) $p = '961' . $p;
    }
    return (strlen($p) >= 10 && strlen($p) <= 15) ? $p : '';
}
/** هل الرقم خلوي يستقبل واتساب؟ اللبناني: 03 / 70 / 71 / 76 / 78 / 79 / 81 (الأرضي 01، 04، 05… لا)؛ غير اللبناني يُقبَل كما هو. */
function msaWaIsMobile(string $raw): bool {
    $p = msaWaPhone($raw); if ($p === '') return false;
    if (strpos($p, '961') !== 0) return true;
    return preg_match('/^961(3\d{6}|7[0168]\d{6}|79\d{6}|81\d{6})$/', $p) === 1;
}
/** عرض الرقم للقراءة: 9613115110 ⇒ +961 3 115 110. */
function msaWaPretty(string $p): string {
    if (strpos($p, '961') === 0 && strlen($p) >= 10) { $l = substr($p, 3); return '+961 ' . (strlen($l) === 7 ? $l[0] . ' ' . substr($l, 1, 3) . ' ' . substr($l, 4) : substr($l, 0, 2) . ' ' . substr($l, 2, 3) . ' ' . substr($l, 5)); }
    return '+' . $p;
}
/** نصّ متغيّر القالب: سطر واحد بلا تبويب ولا فراغات متتالية (شرط ميتا)، بحدّ 180 حرفاً. */
function msaWaParam(string $s): string {
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return $s === '' ? 'Document' : mb_substr($s, 0, 180, 'UTF-8');
}
/** جسم طلب إرسال القالب (مفصول ليُفحَص بلا اتصال). */
function msaWaDocPayload(string $to, string $mediaId, string $fileName, string $title): array {
    $c = msaWaCfg();
    return ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $c['tpl'], 'language' => ['code' => $c['lang']], 'components' => [
        ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $fileName]]]],
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => msaWaParam($title)]]],
    ]]];
}
/** اتصال Graph API. يرجع [http, json|null, raw]. */
function msaWaCall(string $method, string $path, $body = null, array $hdr = [], int $timeout = 60): array {
    $c = msaWaCfg();
    $ch = curl_init('https://graph.facebook.com/v21.0/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $c['token']], $hdr)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$http, $raw ? json_decode((string)$raw, true) : null, $raw !== false ? (string)$raw : $err];
}
/** رسالة خطأ ميتا مفهومة. */
function msaWaErr(array $r): string {
    [$http, $j, $raw] = $r;
    $e = is_array($j) ? ($j['error'] ?? []) : [];
    $code = (int)($e['code'] ?? 0); $m = trim((string)($e['error_user_msg'] ?? ($e['error_data']['details'] ?? ($e['message'] ?? ''))));
    if ($http === 0) return 'لا اتصال بخدمة واتساب (الإنترنت؟)';
    if ($code === 190) return 'مفتاح الربط بواتساب غير صالح أو منتهٍ — يُحدَّث من إعدادات البريد';
    if ($code === 131026) return 'الرقم ليس على واتساب أو لا يستقبل الرسائل';
    if ($code === 132001) return 'قالب الرسالة غير موجود أو لم يُعتمَد بعد من واتساب';
    if ($code === 131056 || $code === 130429 || $code === 80007) return 'واتساب يحدّ عدد الرسائل الآن — أعد المحاولة بعد قليل';
    return 'واتساب رفض الطلب' . ($m !== '' ? ': ' . mb_substr($m, 0, 220) : ' (HTTP ' . $http . ')');
}
/** حالة القالب عند ميتا (APPROVED / PENDING / REJECTED / '' إن تعذّر) — تُحفَظ ساعة إذا معتمَد، وإلّا تُسأل كل 3 دقائق على الأكثر. */
function msaWaTemplateStatus(bool $force = false): string {
    $c = msaWaCfg(); if ($c['token'] === '' || $c['waba_id'] === '') return '';
    $cached = (string)getSetting('wa_cloud_tpl_checked_at', ''); $p = explode('|', $cached);   // name|status|time
    $age = time() - (int)($p[2] ?? 0);
    if (!$force && ($p[0] ?? '') === $c['tpl'] && (($p[1] ?? '') === 'APPROVED' ? $age < 3600 : $age < 180) && ($p[1] ?? '') !== '') return (string)$p[1];
    $r = msaWaCall('GET', $c['waba_id'] . '/message_templates?name=' . rawurlencode($c['tpl']) . '&fields=name,status,language&limit=20', null, [], 20);
    $st = '';
    foreach ((array)($r[1]['data'] ?? []) as $t) if (($t['name'] ?? '') === $c['tpl'] && ($t['language'] ?? '') === $c['lang']) $st = (string)($t['status'] ?? '');
    if ($st !== '') { try { setSetting('wa_cloud_tpl_checked_at', $c['tpl'] . '|' . $st . '|' . time()); } catch (Throwable $e) {} }
    return $st !== '' ? $st : (string)($p[1] ?? '');
}
/** يرسل مستند PDF إلى رقم بالقالب المعتمَد. يرجع ['ok', 'err', 'id' (wamid), 'to']. */
function msaWaSendDocument(string $toRaw, string $title, string $pdfData, string $fileName): array {
    if (!msaWaEnabled()) return ['ok' => false, 'err' => 'الإرسال المباشر بالواتساب غير مفعَّل', 'id' => '', 'to' => ''];
    $to = msaWaPhone($toRaw); if ($to === '') return ['ok' => false, 'err' => 'رقم الواتساب غير صحيح', 'id' => '', 'to' => ''];
    if (substr($pdfData, 0, 4) !== '%PDF') return ['ok' => false, 'err' => 'الملف ليس PDF', 'id' => '', 'to' => $to];
    if (strlen($pdfData) > 95 * 1048576) return ['ok' => false, 'err' => 'الملف أكبر من حدّ واتساب (100MB) — أرسله بالإيميل (رابط تنزيل)', 'id' => '', 'to' => $to];
    $c = msaWaCfg();
    $tmp = tempnam(sys_get_temp_dir(), 'msawa'); file_put_contents($tmp, $pdfData);
    try {
        $up = msaWaCall('POST', $c['phone_id'] . '/media', ['messaging_product' => 'whatsapp', 'type' => 'application/pdf', 'file' => new CURLFile($tmp, 'application/pdf', $fileName)], [], 180);
    } finally { @unlink($tmp); }
    $mid = (string)($up[1]['id'] ?? '');
    if ($up[0] < 200 || $up[0] >= 300 || $mid === '') return ['ok' => false, 'err' => 'رفع الملف إلى واتساب — ' . msaWaErr($up), 'id' => '', 'to' => $to];
    $r = msaWaCall('POST', $c['phone_id'] . '/messages', json_encode(msaWaDocPayload($to, $mid, $fileName, $title), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ['Content-Type: application/json']);
    $id = (string)($r[1]['messages'][0]['id'] ?? '');
    if ($r[0] < 200 || $r[0] >= 300 || $id === '') return ['ok' => false, 'err' => msaWaErr($r), 'id' => '', 'to' => $to];
    return ['ok' => true, 'err' => '', 'id' => $id, 'to' => $to];
}
