<?php
/**
 * 📤 «انبعت للدولة» (POST JSON): يقفل التقرير المعروض بنسخة طبق الأصل (JSON جداوله + بصمة SHA-256) كنسخة جديدة (v+1)،
 *    أو «خلّيه متل ما هو» (ack): يثبّت البصمة الحالية كمقروءة ويطفئ علم «تغيّر» بلا إرسال جديد.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/legal.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!canEdit()) { echo json_encode(['ok' => false, 'err' => 'قراءة فقط']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!verifyCsrf((string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'err' => 'جلسة منتهية — أعد تحميل الصفحة']); exit; }
$key = trim((string)($in['key'] ?? '')); $period = trim((string)($in['period'] ?? '')); $scope = trim((string)($in['scope'] ?? 'all'));
$action = (string)($in['action'] ?? 'send'); $json = (string)($in['snapshot'] ?? ''); $hash = strtolower(trim((string)($in['hash'] ?? '')));
if ($key === '' || $period === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) { echo json_encode(['ok' => false, 'err' => 'بيانات ناقصة']); exit; }
if ($json !== '' && hash('sha256', $json) !== $hash) { echo json_encode(['ok' => false, 'err' => 'البصمة لا تطابق النسخة']); exit; }
legalEnsureTables(); $db = getDB();
$st = $db->prepare("SELECT * FROM legal_filings WHERE dkey = ? AND period = ? AND school_scope = ? ORDER BY version DESC LIMIT 1"); $st->execute([$key, $period, $scope]); $cur = $st->fetch(PDO::FETCH_ASSOC);
$who = (string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''));
if ($action === 'ack' && $cur) {
    $db->prepare("UPDATE legal_filings SET seen_hash = ?, changed_flag = 0 WHERE id = ?")->execute([$hash, $cur['id']]);
    logAudit('legal_ack', 'legal_filings', (int)$cur['id'], null, $key . ' ' . $period . ' kept as sent');
    unset($_SESSION['msa_todo_light']);
    echo json_encode(['ok' => true, 'version' => (int)$cur['version'], 'sent_at' => $cur['sent_at']]); exit;
}
if ($action === 'send') {
    $v = $cur ? (int)$cur['version'] + 1 : 1;
    if (strlen($json) > 8 * 1024 * 1024) $json = '';
    $db->prepare("INSERT INTO legal_filings (dkey, period, school_scope, version, status, sent_at, sent_by, title, href, snapshot_hash, snapshot_json, seen_hash, changed_flag, note) VALUES (?,?,?,?,'sent',NOW(),?,?,?,?,?,?,0,?)")
       ->execute([$key, $period, $scope, $v, $who, mb_substr((string)($in['title'] ?? ''), 0, 250), mb_substr((string)($in['href'] ?? ''), 0, 490), $hash, $json ?: null, $hash, mb_substr((string)($in['note'] ?? ''), 0, 250)]);
    $id = (int)$db->lastInsertId();
    if ($cur) $db->prepare("UPDATE legal_filings SET status = 'superseded', changed_flag = 0 WHERE id = ?")->execute([$cur['id']]);
    logAudit('legal_sent', 'legal_filings', $id, null, $key . ' ' . $period . ' v' . $v);
    unset($_SESSION['msa_todo_light']);
    echo json_encode(['ok' => true, 'version' => $v, 'sent_at' => date('Y-m-d H:i:s'), 'id' => $id]); exit;
}
echo json_encode(['ok' => false, 'err' => 'إجراء غير معروف']);
