<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$lang = $_GET['lang'] ?? 'fr';
$_SESSION['lang'] = in_array($lang, ['fr', 'ar']) ? $lang : 'fr';
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? BASE_URL . 'index.php'));
exit;
