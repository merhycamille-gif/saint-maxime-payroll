<?php
/**
 * ✏️💾 (2026-10-24) حفظ/حذف نصوص الإفادات المعدَّلة الدائمة — POST JSON، محميّ بكلمة سرّ خاصّة (راجع includes/att_rules.php)
 *   {action:'save', type, lang, rules:[{from,to}], pw, csrf}         ⇒ {ok, n}
 *   {action:'delete', id, pw, csrf}                                   ⇒ {ok}
 *   {action:'set_pw', pw_old, pw_new, csrf}  (المدير العام فقط؛ pw_old فارغ إن لم تُحدَّد بعد) ⇒ {ok}
 * أخطاء كلمة السرّ: err:'no_pw' (لم تُحدَّد بعد — المدير العام يحدّدها) · err:'bad_pw'
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/att_rules.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!canEdit()) { echo json_encode(['ok' => false, 'err' => 'قراءة فقط']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!verifyCsrf((string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'err' => 'جلسة منتهية — أعد تحميل الصفحة']); exit; }
$action = (string)($in['action'] ?? '');
$pw = (string)($in['pw'] ?? '');
attRulesInstall();

if ($action === 'set_pw') {
    if (!isSuperAdmin()) { echo json_encode(['ok' => false, 'err' => 'المدير العام فقط يحدّد كلمة السرّ']); exit; }
    $new = trim((string)($in['pw_new'] ?? '')); $old = (string)($in['pw_old'] ?? '');
    if (mb_strlen($new) < 4) { echo json_encode(['ok' => false, 'err' => 'كلمة السرّ قصيرة (4 أحرف على الأقلّ)']); exit; }
    if (attRulesPwHash() !== '' && attRulesPwCheck($old) !== 'ok') { echo json_encode(['ok' => false, 'err' => 'bad_pw']); exit; }
    attRulesPwSet($new);
    echo json_encode(['ok' => true]); exit;
}

$chk = attRulesPwCheck($pw);
if ($chk !== 'ok') { echo json_encode(['ok' => false, 'err' => $chk === 'no_pw' ? 'no_pw' : 'bad_pw', 'super' => isSuperAdmin()]); exit; }

if ($action === 'save') {
    $type = preg_replace('/[^a-z0-9_]/', '', (string)($in['type'] ?? '')); $lang = in_array($in['lang'] ?? '', ['ar', 'fr', 'en'], true) ? $in['lang'] : 'ar';
    $rules = is_array($in['rules'] ?? null) ? $in['rules'] : [];
    if ($type === '' || !$rules) { echo json_encode(['ok' => false, 'err' => 'لا تعديل لحفظه']); exit; }
    $n = 0; $st = getDB()->prepare("INSERT INTO attestation_text_rules (att_type, lang, from_text, to_text, created_by) VALUES (?, ?, ?, ?, ?)");
    $who = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
    foreach ($rules as $r) {
        $from = trim((string)($r['from'] ?? '')); $to = trim((string)($r['to'] ?? ''));
        if ($from === '' || $from === $to || mb_strlen($from) > 2000 || mb_strlen($to) > 2000) continue;
        $st->execute([$type, $lang, $from, $to]); $n++;
    }
    echo json_encode(['ok' => true, 'n' => $n]); exit;
}
if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    getDB()->prepare("DELETE FROM attestation_text_rules WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]); exit;
}
echo json_encode(['ok' => false, 'err' => 'إجراء غير معروف']);
