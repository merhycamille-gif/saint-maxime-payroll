<?php
/**
 * 🧾 رابط وصل الأقساط للأهل (واتساب) — 2026-10-03
 * صفحة مستقلّة لا علاقة لها ببيانات الرواتب: برنامج الأقساط (شبكة المدرسة الداخلية) يرفع نسخة الأهل من الوصل إلى هنا
 * (POST بكلمة سرّ — يُحفظ هنا بصمتها فقط)، والأهل يفتحونها من رسالة الواتساب برابط سرّي: recu.php?t=<40 حرفاً>.
 * التخزين: uploads/_recus/{token}.html (مجلّد uploads خارج git ويبقى عند النشر). لا فهرسة.
 */
const RECU_KEY_SHA256 = '052783b62f8c6f5f46727a49f5b49dcd95e9e7d24fbe3a9e3b739d7bdce6b289';
header('X-Robots-Tag: noindex, nofollow, noarchive');
$dir = __DIR__ . '/uploads/_recus';

function recu_json(array $p, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $key = (string)($_POST['key'] ?? '');
    if ($key === '' || !hash_equals(RECU_KEY_SHA256, hash('sha256', $key))) recu_json(['ok' => false, 'error' => 'unauthorized'], 401);
    $token = (string)($_POST['token'] ?? '');
    $html  = (string)($_POST['html'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) recu_json(['ok' => false, 'error' => 'bad_token'], 400);
    if ($html === '' || strlen($html) > 3 * 1024 * 1024) recu_json(['ok' => false, 'error' => 'bad_html'], 400);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    if (@file_put_contents($dir . '/' . $token . '.html', $html, LOCK_EX) === false) recu_json(['ok' => false, 'error' => 'write_failed'], 500);
    recu_json(['ok' => true, 'token' => $token]);
}

$t = (string)($_GET['t'] ?? '');
$file = preg_match('/^[a-f0-9]{40}$/', $t) ? $dir . '/' . $t . '.html' : '';
header('Content-Type: text/html; charset=utf-8');
if ($file === '' || !is_file($file)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<body style="font-family:sans-serif;text-align:center;padding:40px">Reçu introuvable / الوصل غير موجود</body>';
    exit;
}
header('Cache-Control: private, no-store');
readfile($file);
