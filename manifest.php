<?php
// بيان التطبيق (PWA): يجعل البرنامج قابلاً للتركيب على الهاتف («أضف إلى الشاشة الرئيسية» أندرويد/آيفون)
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$b = BASE_URL;
echo json_encode([
    'name' => 'MSA Payroll — الرواتب والأجور',
    'short_name' => 'MSA Payroll',
    'description' => 'Système de gestion de la paie / نظام إدارة الرواتب والأجور',
    'id' => $b . 'index.php',
    'start_url' => $b . 'index.php',
    'scope' => $b,
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0a2240',
    'theme_color' => '#0a2240',
    'lang' => 'fr',
    'icons' => [
        ['src' => $b . 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $b . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => $b . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
