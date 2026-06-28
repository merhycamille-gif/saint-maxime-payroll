<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php'; // لإعادة الحساب التلقائي عند تغيير السعر
requireLogin();
requireCsrf();

$currentPage = 'rates';
$pageTitle = 'Taux de change';
$db = getDB();
$message = ''; $messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set') {
        try {
            $month = (int)$_POST['month'];
            $year = (int)$_POST['year'];
            $rate = (float)str_replace(',', '', $_POST['rate']);
            $source = trim($_POST['source'] ?? '');
            
            $db->prepare("INSERT INTO exchange_rates (month, year, rate, source) VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE rate = ?, source = ?")
               ->execute([$month, $year, $rate, $source, $rate, $source]);
            // إعادة حساب تلقائية لكل الرواتب المخزّنة (السعر له fallback لـ«الأحدث» فقد يؤثّر على عدة شهور)
            $nRec = recalcSalariesInRange($db, '2017-08-01', null);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "تم حفظ السعر وإعادة حساب الرواتب المتأثّرة تلقائياً ($nRec). / Taux enregistré, salaires recalculés."];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
        header('Location: ' . BASE_URL . 'pages/exchange_rates.php');
        exit;
    }
}

if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM exchange_rates WHERE id = ?")->execute([(int)$_GET['delete']]);
    // إعادة حساب تلقائية بعد الحذف (الشهور التي كانت تعتمد هذا السعر تنتقل لـ«الأحدث»)
    $nRec = recalcSalariesInRange($db, '2017-08-01', null);
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "تم الحذف وإعادة حساب الرواتب المتأثّرة تلقائياً ($nRec). / Supprimé, salaires recalculés."];
    header('Location: ' . BASE_URL . 'pages/exchange_rates.php');
    exit;
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

$rates = $db->query("SELECT * FROM exchange_rates ORDER BY year DESC, month DESC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus"></i> Ajouter / Modifier un taux</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="set">
            <div class="form-row cols-4">
                <div class="form-group">
                    <label class="form-label">Mois</label>
                    <select name="month" class="form-select" required>
                        <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?=$m?>" <?=$m===(int)date('n')?'selected':''?>><?=monthName($m)?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Année</label>
                    <input type="number" name="year" class="form-control" value="<?= date('Y') ?>" min="2020" max="2030" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Taux (1 USD = X L.L)</label>
                    <input type="number" name="rate" class="form-control" step="0.01" value="89500" required>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Enregistrer</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Source / Note</label>
                <input type="text" name="source" class="form-control" placeholder="ex: BDL, Sayrafa, Marché parallèle...">
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-history"></i> Historique des taux</h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Mois</th><th>Année</th><th>Taux</th><th>Source</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($rates as $r): ?>
                    <tr>
                        <td><?= monthName($r['month']) ?></td>
                        <td><?= $r['year'] ?></td>
                        <td><strong><?= formatLBP($r['rate']) ?></strong> / $1</td>
                        <td><?= e($r['source']) ?></td>
                        <td>
                            <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer ?">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
