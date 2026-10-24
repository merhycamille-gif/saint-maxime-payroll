<?php
/**
 * ✏️💾 (2026-10-24) حفظ/حذف نصوص الإفادات المعدَّلة الدائمة — POST JSON، محميّ بكلمة سرّ خاصّة (راجع includes/att_rules.php)
 *   {action:'save', type, lang, rules:[{from,to}], pw, csrf}         ⇒ {ok, n}
 *   {action:'delete', id, pw, csrf}                                   ⇒ {ok}
 *   {action:'set_pw', pw_old, pw_new, csrf}  (المدير العام فقط؛ pw_old فارغ إن لم تُحدَّد بعد) ⇒ {ok}
 * أخطاء كلمة السرّ: err:'no_pw' (لم تُحدَّد بعد — المدير العام يحدّدها) · err:'bad_pw'
 * 🛡️ (2026-10-24 p1 «Unexpected end of JSON input»): الرد دائماً JSON — أي استثناء/خطأ قاتل يُلتقط ويُرجَع كـ{ok:false,err}
 *    (كان خطأ قاعدة بيانات غير ملفوف يوقف السكربت فيرجع ردّاً فاضياً فيفشل r.json() بالمتصفّح).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/att_rules.php';

header('Content-Type: application/json; charset=utf-8');
// 🛡️ ضمان JSON عند أي خطأ قاتل (لا ردّ فاضٍ يكسر r.json())
$GLOBALS['__att_done'] = false;
register_shutdown_function(function () {
    if (!empty($GLOBALS['__att_done'])) return;
    $e = error_get_last();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'err' => 'خطأ بالخادم' . ($e ? ' (' . mb_substr((string)$e['message'], 0, 160) . ')' : '')], JSON_UNESCAPED_UNICODE);
});
function attDone(array $out): void { $GLOBALS['__att_done'] = true; echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; }

try {
    if (!isLoggedIn()) attDone(['ok' => false, 'err' => 'انتهت الجلسة — أعد تحميل الصفحة ثمّ سجّل الدخول']);
    if (!canEdit()) attDone(['ok' => false, 'err' => 'قراءة فقط']);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!verifyCsrf((string)($in['csrf'] ?? ''))) attDone(['ok' => false, 'err' => 'جلسة منتهية — أعد تحميل الصفحة']);
    $action = (string)($in['action'] ?? '');
    $pw = (string)($in['pw'] ?? '');
    attRulesInstall();

    if ($action === 'set_pw') {
        if (!isSuperAdmin()) attDone(['ok' => false, 'err' => 'المدير العام فقط يحدّد كلمة السرّ']);
        $new = trim((string)($in['pw_new'] ?? '')); $old = (string)($in['pw_old'] ?? '');
        if (mb_strlen($new) < 4) attDone(['ok' => false, 'err' => 'كلمة السرّ قصيرة (4 أحرف على الأقلّ)']);
        if (attRulesPwHash() !== '' && attRulesPwCheck($old) !== 'ok') attDone(['ok' => false, 'err' => 'bad_pw']);
        attRulesPwSet($new);
        attDone(['ok' => true]);
    }

    $chk = attRulesPwCheck($pw);
    if ($chk !== 'ok') attDone(['ok' => false, 'err' => $chk === 'no_pw' ? 'no_pw' : 'bad_pw', 'super' => isSuperAdmin()]);

    if ($action === 'save') {
        $type = preg_replace('/[^a-z0-9_]/', '', (string)($in['type'] ?? '')); $lang = in_array($in['lang'] ?? '', ['ar', 'fr', 'en'], true) ? $in['lang'] : 'ar';
        $rules = is_array($in['rules'] ?? null) ? $in['rules'] : [];
        if ($type === '' || !$rules) attDone(['ok' => false, 'err' => 'لا تعديل لحفظه']);
        $n = 0; $st = getDB()->prepare("INSERT INTO attestation_text_rules (att_type, lang, from_text, to_text, created_by) VALUES (?, ?, ?, ?, ?)");
        $who = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
        foreach ($rules as $r) {
            $from = trim((string)($r['from'] ?? '')); $to = trim((string)($r['to'] ?? ''));
            if ($from === '' || $from === $to || mb_strlen($from) > 2000 || mb_strlen($to) > 2000) continue;
            $st->execute([$type, $lang, $from, $to, $who]); $n++;
        }
        attDone(['ok' => true, 'n' => $n]);
    }
    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        getDB()->prepare("DELETE FROM attestation_text_rules WHERE id = ?")->execute([$id]);
        attDone(['ok' => true]);
    }
    attDone(['ok' => false, 'err' => 'إجراء غير معروف']);
} catch (Throwable $e) {
    attDone(['ok' => false, 'err' => 'خطأ بالخادم: ' . mb_substr($e->getMessage(), 0, 200)]);
}
