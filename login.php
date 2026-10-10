<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        $stmt = getDB()->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        // Support both proper hashed password OR fallback admin/admin123 for first install
        $valid = false;
        if ($user) {
            if (!empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
                $valid = true;
            } elseif ($username === 'admin' && $password === 'admin123' && empty($user['password_hash'])) {
                // أول تنصيب فقط (لا يوجد hash مخزّن): ثبّت الـhash بشكل صحيح
                // ملاحظة: بعد تعيين كلمة سر، لا يعود admin123 يعمل كباب خلفي
                $newHash = password_hash('admin123', PASSWORD_DEFAULT);
                $u = getDB()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $u->execute([$newHash, $user['id']]);
                $valid = true;
            }
        }
        
        if ($valid) {
            // 🔒 جدّد معرّف الجلسة عند نجاح الدخول (يمنع تثبيت جلسة مزروعة مسبقاً — session fixation)
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['school_id'] = $user['school_id']; // مدرسة المستخدم (NULL للمدير العام)
            // المدير العام يبدأ بوضع "كل المدارس"؛ يمكنه التبديل من الأعلى
            $_SESSION['active_school_id'] = ($user['role'] === 'superadmin') ? 0 : (int)$user['school_id'];
            $_SESSION['lang'] = 'fr';
            
            getDB()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        }
        $error = 'Nom d\'utilisateur ou mot de passe incorrect / اسم المستخدم أو كلمة المرور غير صحيحة';
    } else {
        $error = 'Veuillez remplir tous les champs / يرجى ملء جميع الحقول';
    }
}
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — MSA Payroll</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- تطبيق الهاتف (PWA): manifest + أيقونة الشاشة الرئيسية + عامل الخدمة -->
    <link rel="icon" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <link rel="manifest" href="<?= BASE_URL ?>manifest.php">
    <meta name="theme-color" content="#0a2240">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="MSA Payroll">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('<?= BASE_URL ?>sw.js').catch(function () {});</script>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: '1' ?>">
    <style>
    /* زرّ «تثبيت على الهاتف»: يظهر على الهاتف فقط ويختفي حين يُفتح البرنامج كتطبيق مركَّب */
    #installApp{display:none;margin-top:14px;width:100%;background:#fff;color:#0a2240;border:1px solid #c9a961;border-radius:8px;padding:10px;font-size:14px;font-weight:600;cursor:pointer}
    #installHelp{display:none;margin-top:10px;background:#fffbea;border:1px solid #c9a961;border-radius:8px;padding:10px 12px;font-size:13px;line-height:1.8;text-align:start}
    @media (max-width:768px){ #installApp{display:block} }
    @media (display-mode:standalone){ #installApp,#installHelp{display:none!important} }
    </style>
</head>
<body>

<div class="login-page">
    <div class="login-box">
        <div class="login-header">
            <div class="login-logo"><i class="fas fa-graduation-cap"></i></div>
            <h1>MSA Payroll</h1>
            <p>Système de gestion de la paie / نظام إدارة الرواتب</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Nom d'utilisateur / اسم المستخدم</label>
                    <input type="text" name="username" class="form-control" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Mot de passe / كلمة المرور</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 btn-lg">
                    <i class="fas fa-sign-in-alt"></i>
                    Se connecter / دخول
                </button>
            </form>
            <button type="button" id="installApp"><i class="fas fa-mobile-screen-button"></i> Installer l'application / تثبيت التطبيق على الهاتف</button>
            <div id="installHelp"></div>
            <script>
            // 📲 تثبيت التطبيق: أندرويد (كروم) يعرض نافذة التثبيت مباشرة؛ آيفون/غيره يعرض الخطوات بالصورة الكلامية
            (function () {
                var btn = document.getElementById('installApp'), help = document.getElementById('installHelp'), deferred = null;
                window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; btn.style.display = 'block'; });
                btn.addEventListener('click', function () {
                    if (deferred) { deferred.prompt(); deferred.userChoice.then(function () { deferred = null; btn.style.display = 'none'; }); return; }
                    var ios = /iPhone|iPad|iPod/i.test(navigator.userAgent), huawei = /HuaweiBrowser|HarmonyOS|HUAWEI|HONOR/i.test(navigator.userAgent);
                    help.innerHTML = huawei
                        ? '<b>Huawei (navigateur Huawei) :</b> appuyez sur <b>⋯</b> en bas de l\'écran, puis <b>« Ajouter à l\'écran d\'accueil »</b> puis <b>« Ajouter »</b>.<br>'
                          + '<span dir="rtl"><b>هواوي (متصفّح هواوي):</b> اكبس <b>⋯</b> بأسفل الشاشة ثم <b>«إضافة إلى الشاشة الرئيسية»</b> ثم <b>«إضافة»</b>. إذا ما ظهرت، اسحب القائمة لليسار لتشوف باقي الخيارات.</span>'
                        : ios
                        ? '<b>iPhone (Safari) :</b> appuyez sur <b>Partager</b> (le carré avec la flèche en bas) puis <b>« Sur l\'écran d\'accueil »</b>.<br>'
                          + '<span dir="rtl"><b>آيفون (سفاري):</b> اكبس <b>مشاركة</b> (المربّع مع السهم أسفل الشاشة) ثم <b>«إضافة إلى الشاشة الرئيسية»</b>.</span>'
                        : '<b>Android (Chrome) :</b> appuyez sur le menu <b>⋮</b> en haut à droite puis <b>« Installer l\'application »</b> ou <b>« Ajouter à l\'écran d\'accueil »</b>.<br>'
                          + '<span dir="rtl"><b>أندرويد (كروم):</b> اكبس القائمة <b>⋮</b> أعلى اليمين ثم <b>«تثبيت التطبيق»</b> أو <b>«إضافة إلى الشاشة الرئيسية»</b>.</span>';
                    help.style.display = 'block';
                });
                window.addEventListener('appinstalled', function () { btn.style.display = 'none'; help.style.display = 'none'; });
            })();
            </script>
        </div>
    </div>
</div>

</body>
</html>
