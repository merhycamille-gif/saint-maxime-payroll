<?php
/**
 * 🔑📱 رابط الموبايل (2026-10-31): key_link.php?k=<selector>&t=<token> يُفتح مرّة على الهاتف ⇒ كوكي سنة ⇒ login.php يدخل تلقائياً بعدها.
 * جالب معاينة واتساب يرى بطاقة (أيقونة المدرسة) بلا ربط. حتى 3 متصفّحات لكل رابط؛ الباقي يُرفض بصفحة واضحة.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/school_access.php';
require_once __DIR__ . '/includes/key_access.php';
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$sel = (string)($_GET['k'] ?? '');
if (kaIsPreviewBot()) {
    $un = ''; $label = 'MSA Payroll';
    try { if (preg_match('/^[a-f0-9]{16}$/', $sel)) { kaSchema(); $st = getDB()->prepare("SELECT u.* FROM app_keys k JOIN users u ON u.id = k.user_id WHERE k.selector = ?"); $st->execute([$sel]); if ($u = $st->fetch()) { $un = (string)$u['username']; $label = kaLabelFor($u); } } } catch (Throwable $e) {}
    echo kaPreviewHtml($un, $label); exit;
}
[$ok, $fr, $ar] = kaLinkBind($sel, (string)($_GET['t'] ?? ''));
if ($ok) {
    if ($fr === 'new') $_SESSION['flash_success'] = 'Accès installé sur cet appareil — plus de mot de passe ici. Pour l\'icône : iPhone → Partager → « Sur l\'écran d\'accueil » · Android → ⋮ → « Ajouter à l\'écran d\'accueil » / تمّ تركيب الدخول المباشر على هذا الجهاز. للأيقونة: آيفون ← مشاركة ← «إضافة إلى الشاشة الرئيسية» · أندرويد ← ⋮ ← «إضافة إلى الشاشة الرئيسية»';
    header('Location: ' . BASE_URL . 'index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MSA Payroll — Accès direct</title>
<link rel="icon" href="<?= BASE_URL ?>assets/img/icon-192.png">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: '1' ?>">
</head>
<body>
<div class="login-page"><div class="login-box">
    <div class="login-header"><div class="login-logo"><img src="<?= BASE_URL ?>assets/img/logo_mark.png" alt="MSA Payroll" width="130"></div><h1>MSA Payroll</h1></div>
    <div class="login-body">
        <div class="alert alert-warning" style="text-align:start"><?= e($fr) ?><br><span dir="rtl"><?= e($ar) ?></span></div>
        <a class="btn btn-primary w-100 btn-lg" href="<?= BASE_URL ?>login.php">Connexion avec mot de passe / الدخول بكلمة السرّ</a>
    </div>
</div></div>
</body>
</html>
