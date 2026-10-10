<?php
/**
 * 🖼️⚡ مصغّر صورة الموظف (2026-10-10 v2026 — «اسم الأستاذ وحدو صورة منيحة» + «كل البرنامج متل البرق»):
 *   الصور المرفوعة (uploads/…) حجمها 100KB–1MB؛ اللائحة فيها 380 اسماً ⇒ هذا الملف يرجّع نسخة 96×96 مقصوصة من الوسط (JPEG 80٪)
 *   مخزّنة بـuploads/thumbs/ (تركيب ذاتي) مع كاش متصفّح شهر. بلا GD ⇒ الأصل كما هو. للمستخدمين المسجّلين فقط (الصور خاصة).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$p = str_replace('\\', '/', (string)($_GET['p'] ?? ''));
$s = max(32, min(256, (int)($_GET['s'] ?? 96)));
if ($p === '' || strpos($p, '..') !== false || strpos($p, 'uploads/') !== 0 || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $p)) { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..'); $file = $root . '/' . $p;
if (!is_file($file)) { http_response_code(404); exit; }
$mt = (int)filemtime($file); $etag = '"' . md5($p . '|' . $mt . '|' . filesize($file) . '|' . $s) . '"';
header('Cache-Control: private, max-age=2592000'); header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
$dir = $root . '/uploads/thumbs'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$cache = $dir . '/' . md5($p . '|' . $mt . '|' . $s) . '.jpg';
$serve = function (string $f, string $type) { header('Content-Type: ' . $type); header('Content-Length: ' . (string)filesize($f)); readfile($f); exit; };
if (is_file($cache)) $serve($cache, 'image/jpeg');
if (!function_exists('imagecreatefromstring')) $serve($file, mime_content_type($file) ?: 'image/jpeg'); // بلا GD ⇒ الأصل
try {
    $im = @imagecreatefromstring((string)file_get_contents($file));
    if (!$im) $serve($file, mime_content_type($file) ?: 'image/jpeg');
    if (function_exists('exif_read_data') && preg_match('/\.jpe?g$/i', $p)) { // دوران الصور من الهاتف
        $ex = @exif_read_data($file); $o = (int)($ex['Orientation'] ?? 1);
        if ($o === 3) $im = imagerotate($im, 180, 0); elseif ($o === 6) $im = imagerotate($im, -90, 0); elseif ($o === 8) $im = imagerotate($im, 90, 0);
    }
    $w = imagesx($im); $h = imagesy($im); $side = min($w, $h); $x = (int)(($w - $side) / 2); $y = (int)(($h - $side) / 4); // أعلى قليلاً من الوسط (الوجه)
    $out = imagecreatetruecolor($s, $s); $white = imagecolorallocate($out, 255, 255, 255); imagefill($out, 0, 0, $white);
    imagecopyresampled($out, $im, 0, 0, $x, $y, $s, $s, $side, $side);
    imagejpeg($out, $cache, 82); imagedestroy($out); imagedestroy($im);
    if (is_file($cache)) $serve($cache, 'image/jpeg');
} catch (Throwable $e) {}
$serve($file, mime_content_type($file) ?: 'image/jpeg');
