<?php
// بيان التطبيق (PWA): يجعل البرنامج قابلاً للتركيب على الهاتف («أضف إلى الشاشة الرئيسية» أندرويد/آيفون) وعلى سطح المكتب (Chrome/Edge).
// 🏫 (2026-10-22) ?u=<اسم دخول مدرسة> ⇒ تطبيق باسم المدرسة وأيقونتها الخاصّة (icon.php) يفتح صفحة الدخول باسمها — كل مدرسة أيقونة مستقلّة.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/school_access.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$b = BASE_URL;
$u = strtolower(trim((string)($_GET['u'] ?? '')));
$u = preg_replace('/[^a-z0-9._-]/', '', $u);
$name = 'MSA Payroll — الرواتب والأجور'; $short = 'MSA Payroll'; $id = $b . 'index.php'; $start = $b . 'index.php';
$icons = [
    ['src' => $b . 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
    ['src' => $b . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ['src' => $b . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
];
if ($u !== '') {
    $label = '';
    try {
        $st = getDB()->prepare("SELECT school_id, allowed_schools FROM users WHERE username = ? AND role = 'viewer' AND is_active = 1 LIMIT 1");
        $st->execute([$u]); $r = $st->fetch();
        if ($r) {
            $sid = (int)($r['school_id'] ?? 0); $as = (string)($r['allowed_schools'] ?? '');
            if ($sid <= 0 && $as !== '' && $as !== 'all' && strpos($as, ',') === false) $sid = (int)$as;
            if ($sid > 0) { foreach (allSchools(false) as $s) if ((int)$s['id'] === $sid) { $label = saShortName($s); break; } }
            elseif ($as === 'all') $label = 'PRESIDENCE';
        }
    } catch (Throwable $e) {}
    if ($label !== '') {
        $name = 'Paie ' . $label . ' — رواتب ' . $label; $short = 'Paie ' . $label;
        $id = $b . 'login.php?u=' . rawurlencode($u); $start = $id;
        $icons = [
            ['src' => $b . 'icon.php?u=' . rawurlencode($u) . '&s=192', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $b . 'icon.php?u=' . rawurlencode($u) . '&s=512', 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => $b . 'icon.php?u=' . rawurlencode($u) . '&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }
}
echo json_encode([
    'name' => $name,
    'short_name' => $short,
    'description' => 'Système de gestion de la paie / نظام إدارة الرواتب والأجور',
    'id' => $id,
    'start_url' => $start,
    'scope' => $b,
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0a2240',
    'theme_color' => '#0a2240',
    'lang' => 'fr',
    'icons' => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
