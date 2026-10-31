#!/usr/bin/env php
<?php
/** 🔑 أيقونة دخول مباشر لحساب قراءة فقط (سطر الأوامر، للصيانة):
 *   php tools/make_key.php <username> "<étiquette>" <sortie.exe>   ⇒ ملف الكمبيوتر
 *   php tools/make_key.php <username> "<étiquette>" --link         ⇒ رابط الموبايل */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
$PROJ = dirname(__DIR__);
session_start();
require_once $PROJ . '/config/database.php';
require_once $PROJ . '/includes/functions.php';
require_once $PROJ . '/includes/school_access.php';
require_once $PROJ . '/includes/key_access.php';
[$_, $un, $label, $out] = array_pad($argv, 4, '');
if ($un === '' || $out === '') { fwrite(STDERR, "usage: make_key.php <username> \"<étiquette>\" <sortie.exe | --link>\n"); exit(1); }
$st = getDB()->prepare("SELECT id FROM users WHERE username = ?"); $st->execute([$un]); $u = $st->fetch();
if (!$u) { fwrite(STDERR, "utilisateur inconnu: $un\n"); exit(1); }
if ($out === '--link') { [$id, $link] = kaCreateLink((int)$u['id'], $label, 'cli'); echo "key id=$id\n$link\n"; exit; }
[$id, $exe] = kaCreateExe((int)$u['id'], $label, 'cli');
file_put_contents($out, $exe);
echo "key id=$id\tfile=$out\tbytes=" . strlen($exe) . "\n";
