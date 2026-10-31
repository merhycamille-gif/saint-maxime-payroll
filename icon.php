<?php
/**
 * 🖼️ أيقونة التطبيق لكل مدرسة (2026-10-22): icon.php?u=<اسم الدخول>&s=192|512
 * أيقونة البرنامج نفسها + شريط سفلي باسم منطقة المدرسة (بالأحرف اللاتينية الكبيرة) لتتميّز أيقونة كل مدرسة على سطح المكتب.
 * بلا GD أو بلا مدرسة ⇒ الأيقونة العامّة. تُخزَّن بـtmp/icons (تُعاد عند تغيّر الأصل أو الاسم).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/school_access.php';

$size = (int)($_GET['s'] ?? 192); if (!in_array($size, [192, 512], true)) $size = 192;
$u = strtolower(trim((string)($_GET['u'] ?? '')));
$u = preg_replace('/[^a-z0-9._-]/', '', $u);
$base = __DIR__ . '/assets/img/icon-' . $size . '.png';
$label = '';
if ($u !== '') {
    try {
        $st = getDB()->prepare("SELECT school_id, allowed_schools FROM users WHERE username = ? AND role = 'viewer' LIMIT 1");
        $st->execute([$u]); $r = $st->fetch();
        if ($r) {
            $sid = (int)($r['school_id'] ?? 0);
            $as = (string)($r['allowed_schools'] ?? '');
            if ($sid <= 0 && $as !== '' && $as !== 'all' && strpos($as, ',') === false) $sid = (int)$as;
            if ($sid > 0) { foreach (allSchools(false) as $s) if ((int)$s['id'] === $sid) { $label = saShortName($s); break; } }
            elseif ($as === 'all') $label = 'PRESIDENCE';
            // 🏫 (2026-10-31 «مكسيموس ما إلو علاقة بهالشعار»): المدرسة المستقلّة ⇒ أيقونتها بشعارها هي (icon-<s>-maximos.png)
            if ($sid > 0 && function_exists('hqExcludedSchoolIds') && in_array($sid, hqExcludedSchoolIds(), true) && is_file(__DIR__ . '/assets/img/icon-' . $size . '-maximos.png')) $base = __DIR__ . '/assets/img/icon-' . $size . '-maximos.png';
        }
    } catch (Throwable $e) { $label = ''; }
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
if ($label === '' || !function_exists('imagecreatefrompng') || !function_exists('imagettftext')) { readfile($base); exit; }

$cacheDir = __DIR__ . '/tmp/icons'; if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cache = $cacheDir . '/' . $u . '_' . $size . '_' . substr(md5($label . filemtime($base)), 0, 8) . '.png';
if (is_file($cache)) { readfile($cache); exit; }

$im = imagecreatefrompng($base);
imagesavealpha($im, true); imagealphablending($im, true);
$w = imagesx($im); $h = imagesy($im);
$bandH = (int)round($h * 0.26);
$navy = imagecolorallocatealpha($im, 10, 34, 64, 18); // ⬜ (2026-10-31 «رقم 2 بس الباكروند بيصير أبيض») أيقونة الرواتب بيضاء بشعار MSA ثلاثي الألوان ⇒ شريط الاسم كحلي
imagefilledrectangle($im, 0, $h - $bandH, $w, $h, $navy);
$gold = imagecolorallocate($im, 201, 169, 97);
imagefilledrectangle($im, 0, $h - $bandH, $w, $h - $bandH + max(2, (int)($h * 0.012)), $gold);
$font = __DIR__ . '/assets/fonts/ttf/arialbd.ttf';
$white = imagecolorallocate($im, 255, 255, 255);
$txt = $label; $fs = (int)round($bandH * 0.52);
do { $bb = imagettfbbox($fs, 0, $font, $txt); $tw = $bb[2] - $bb[0]; if ($tw <= $w * 0.9) break; $fs--; } while ($fs > 8);
$bb = imagettfbbox($fs, 0, $font, $txt); $tw = $bb[2] - $bb[0]; $th = $bb[1] - $bb[7];
$x = (int)(($w - $tw) / 2 - $bb[0]); $y = (int)($h - $bandH + ($bandH + $th) / 2 - 2);
imagettftext($im, $fs, 0, $x, $y, $white, $font, $txt);
ob_start(); imagepng($im, null, 6); $png = ob_get_clean(); imagedestroy($im);
@file_put_contents($cache, $png);
echo $png;
