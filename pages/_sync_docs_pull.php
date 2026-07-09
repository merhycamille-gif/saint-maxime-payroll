<?php
/**
 * أداة مؤقّتة لمزامنة مستندات الأساتذة من الأونلاين إلى نسخة الكمبيوتر (تُحذَف فور الانتهاء).
 * محميّة برمز سرّي طويل (لا جلسة). للقراءة فقط: تُصدّر مسارات المستندات وتبثّ الملفات المطلوبة.
 *   ?t=TOKEN&action=list                 → JSON: كل موظف ومساراته
 *   ?t=TOKEN&action=file&p=uploads/....   → بثّ الملف نفسه (ضمن uploads فقط)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$TOKEN = '322ae0b1ae364cdd339dc0b319eb49abfdf0c2bf4644f7f9651b8bf8591a7c2f';
if (!hash_equals($TOKEN, (string)($_GET['t'] ?? ''))) { http_response_code(403); exit('forbidden'); }

$action = $_GET['action'] ?? 'list';

if ($action === 'file') {
    // بثّ ملف من داخل uploads فقط (منع الخروج من المجلد)
    $rel = str_replace('\\', '/', (string)($_GET['p'] ?? ''));
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, 'uploads/') !== 0) { http_response_code(400); exit('bad path'); }
    $abs = realpath(__DIR__ . '/../' . $rel);
    $root = realpath(__DIR__ . '/../uploads');
    if ($abs === false || $root === false || strpos($abs, $root) !== 0 || !is_file($abs)) { http_response_code(404); exit('not found'); }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

// list: كل موظف عندو مستند + بيانات مطابقة (id/اسم/تاريخ ولادة) لربطها بأمان على الكمبيوتر
header('Content-Type: application/json; charset=utf-8');
$db = getDB();
$rows = $db->query(
    "SELECT id, first_name_ar, father_name_ar, last_name_ar, birth_date,
            photo_path, id_document_path, family_doc_path, diploma_doc_path
     FROM employees
     WHERE is_deleted = 0 AND (photo_path <> '' OR id_document_path <> ''
            OR family_doc_path <> '' OR diploma_doc_path <> '')"
)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['db' => DB_NAME, 'count' => count($rows), 'rows' => $rows], JSON_UNESCAPED_UNICODE);
