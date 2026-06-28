<?php
/**
 * Install Script - تركيب أولي
 * Run once via browser: http://192.168.1.60/saint-maxime-payroll/install.php
 */

require_once __DIR__ . '/config/database.php';

$pdo = null;
$messages = [];

try {
    // Connect without database
    $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Run schema
    $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
    $pdo->exec($schema);
    $messages[] = "✅ Schéma de base de données créé / تم إنشاء قاعدة البيانات";
    
    // Run seed
    $seed = file_get_contents(__DIR__ . '/sql/seed_data.sql');
    $pdo->exec($seed);
    $messages[] = "✅ Données initiales insérées / تم إدخال البيانات الأولية";
    
    // Set admin password properly
    $pdo->exec("USE saint_maxime_payroll");
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
    $stmt->execute([$hash]);
    $messages[] = "✅ Utilisateur admin configuré: admin / admin123";
    
} catch (Exception $e) {
    $messages[] = "❌ Erreur: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Installation</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="login-header">
            <h1>Installation</h1>
            <p>Saint Maxime Payroll System</p>
        </div>
        <div class="login-body">
            <?php foreach ($messages as $msg): ?>
                <div class="alert alert-<?= strpos($msg, '❌') !== false ? 'danger' : 'success' ?>">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endforeach; ?>
            
            <?php if (!empty($messages) && !array_filter($messages, fn($m) => strpos($m, '❌') !== false)): ?>
                <div class="alert alert-info">
                    <strong>🔐 Connexion par défaut:</strong><br>
                    Utilisateur: <code>admin</code><br>
                    Mot de passe: <code>admin123</code>
                </div>
                <a href="<?= BASE_URL ?>login.php" class="btn btn-primary w-100 btn-lg">
                    Aller à la page de connexion →
                </a>
                
                <div class="alert alert-warning mt-4">
                    <strong>⚠️ Important:</strong> Supprimez ce fichier (<code>install.php</code>) après l'installation !
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
