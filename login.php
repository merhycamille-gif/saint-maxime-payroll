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
    <title>Connexion — SALAIRES DES ÉCOLES</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">
</head>
<body>

<div class="login-page">
    <div class="login-box">
        <div class="login-header">
            <i class="fas fa-graduation-cap" style="font-size:36px;color:var(--gold);margin-bottom:12px;"></i>
            <h1>SALAIRES DES ÉCOLES</h1>
            <p>Système de gestion de la paie / رواتب المدارس</p>
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
                
                <div class="text-center mt-4" style="color:var(--gray-500);font-size:12px;">
                    Par défaut: <code>admin</code> / <code>admin123</code>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
