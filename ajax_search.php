<?php
/**
 * البحث الشامل (Ctrl+K) — يرجع أساتذة/موظفين مطابقين ضمن المدارس المسموحة فقط.
 * يُستدعى من الشريط العلوي في كل الصفحات (includes/header.php).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo '[]'; exit; }

$like = '%' . $q . '%';
$st = getDB()->prepare(
    "SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, school_id
       FROM employees
      WHERE is_deleted = 0" . schoolScopeSql() . "
        AND (employee_code LIKE ?
             OR CONCAT(COALESCE(first_name_fr,''),' ',COALESCE(last_name_fr,'')) LIKE ?
             OR CONCAT(COALESCE(first_name_ar,''),' ',COALESCE(last_name_ar,'')) LIKE ?)
      ORDER BY COALESCE(NULLIF(first_name_ar,''), first_name_fr), COALESCE(NULLIF(last_name_ar,''), last_name_fr)
      LIMIT 12"
);
$st->execute([$like, $like, $like]);

$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = [
        'id'     => (int)$r['id'],
        'code'   => (string)$r['employee_code'],
        'fr'     => trim($r['first_name_fr'] . ' ' . $r['last_name_fr']),
        'ar'     => trim($r['first_name_ar'] . ' ' . $r['last_name_ar']),
        'school' => (string)schoolNameById((int)$r['school_id']),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
