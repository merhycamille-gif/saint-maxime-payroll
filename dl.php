<?php
/**
 * 📦 (2026-10-19) تنزيل ملف مشارَك برابط آمن: dl.php?t=<رمز 32> — بلا دخول (الرمز عشوائي وصالح 90 يوماً).
 * يحصي كل تنزيل (يظهر بسجلّ الإيميلات ⬇️). الملف يُبثّ كما هو باسمه الأصلي.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
$t = strtolower(trim((string)($_GET['t'] ?? '')));
$fail = function (string $msg) { http_response_code(404); header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MSA Payroll</title></head>'
       . '<body style="font-family:Arial,sans-serif;text-align:center;padding:60px 20px;color:#173f6f"><h2>' . htmlspecialchars($msg) . '</h2><p>MSA Payroll</p></body></html>'; exit; };
if (!preg_match('/^[a-f0-9]{32}$/', $t)) $fail('الرابط غير صحيح / Lien invalide');
try {
    ensureMailLogTable();
    $db = getDB();
    $st = $db->prepare("SELECT * FROM shared_files WHERE token = ?"); $st->execute([$t]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $row = null; }
if (!$row) $fail('الملف غير موجود أو انتهت صلاحية الرابط / Fichier introuvable ou lien expiré');
if ($row['expires_at'] < date('Y-m-d H:i:s')) $fail('انتهت صلاحية الرابط (90 يوماً) — اطلب إرسال الملف من جديد / Lien expiré');
$files = glob(msaShareDir() . '/' . $t . '.*') ?: [];
if (!$files || !is_file($files[0])) $fail('الملف غير موجود على الموقع / Fichier introuvable');
$path = $files[0];
try { $db->prepare("UPDATE shared_files SET downloads = downloads + 1, last_download_at = ? WHERE token = ?")->execute([date('Y-m-d H:i:s'), $t]); } catch (Throwable $e) {}
if (function_exists('session_write_close')) @session_write_close();
$name = (string)$row['file_name'];
header('Content-Type: ' . ((string)$row['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
@ob_end_clean();
readfile($path);
exit;
