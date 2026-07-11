<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php'; // لإعادة الحساب التلقائي
requireLogin();
requireCsrf();

$currentPage = 'employees';
$pageTitle = 'Primes & Indemnités / المكافآت والتعويضات';
$db = getDB();
$employeeId = (int)($_GET['employee_id'] ?? 0);

if ($employeeId == 0) {
    header('Location: ' . BASE_URL . 'pages/employees.php');
    exit;
}

// إضافة/حذف المكافآت تتطلّب مدرسة محددة
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete'])) {
    requireSchoolSelected();
}

$stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
$stmt->execute([$employeeId]);
$emp = $stmt->fetch();
if (!$emp) {
    $_SESSION['flash_error'] = 'الموظف غير موجود في هذه المدرسة';
    header('Location: ' . BASE_URL . 'pages/employees.php');
    exit;
}

$message = ''; $messageType = 'success';

// Add bonus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    try {
        $vtype = (($_POST['value_type'] ?? 'amount') === 'percent') ? 'percent' : 'amount';
        $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([
               $employeeId,
               $_POST['bonus_type'],
               (int)$_POST['period_number'],
               activeSchoolYear(),
               (float)str_replace(',', '', $_POST['amount']),
               $vtype,
               ($vtype === 'percent') ? 'LBP' : ($_POST['currency'] ?? 'USD'),
               $_POST['start_month'] ?: null,
               $_POST['end_month'] ?: null
           ]);
        recalcEmployeeYear($employeeId, (activeSchoolYear() === 'all') ? currentSchoolYear() : activeSchoolYear()); // إعادة حساب الراتب تلقائياً
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Prime ajoutée'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
    }
    header("Location: ?employee_id=$employeeId");
    exit;
}

// Delete bonus
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM employee_bonuses WHERE id = ? AND employee_id = ?")->execute([(int)$_GET['delete'], $employeeId]);
    recalcEmployeeYear($employeeId, (activeSchoolYear() === 'all') ? currentSchoolYear() : activeSchoolYear()); // إعادة حساب الراتب تلقائياً
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Supprimée'];
    header("Location: ?employee_id=$employeeId");
    exit;
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

$bonuses = $db->prepare("SELECT * FROM employee_bonuses WHERE employee_id = ? AND (school_year = ? OR school_year IS NULL) ORDER BY bonus_type, period_number");
$bonuses->execute([$employeeId, activeSchoolYear()]);
$bonuses = $bonuses->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div><?php endif; ?>

<div class="d-flex justify-between align-center mb-3">
    <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $employeeId ?>" class="btn btn-light">
        <i class="fas fa-arrow-left"></i> Retour / رجوع
    </a>
    <h3 style="margin:0">
        <span dir="ltr"><?= e($emp['first_name_fr'].' '.$emp['last_name_fr']) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e(trim($emp['first_name_ar'].' '.$emp['last_name_ar']) ?: 'المكافآت') ?></div>
    </h3>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-plus"></i> Ajouter une prime</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">إضافة مكافأة</div>
    </h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row cols-4">
                <div class="form-group">
                    <label class="form-label">Type / النوع</label>
                    <select name="bonus_type" class="form-select" required>
                        <option value="prime_fixe">💰 Prime fixe / مكافأة ثابتة</option>
                        <option value="aide_complementaire">🤝 Aide complémentaire / مساعدة</option>
                        <option value="transport_complement">🚌 Transport / تعويض نقل</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Période / الفترة</label>
                    <select name="period_number" class="form-select" required>
                        <option value="1">Période 1 / الفترة 1</option>
                        <option value="2">Période 2 / الفترة 2</option>
                        <option value="3">Période 3 / الفترة 3</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Type de valeur / النوع</label>
                    <select name="value_type" id="b_value_type" class="form-select" onchange="bonusValTypeChange()">
                        <option value="amount">مبلغ ثابت / Montant</option>
                        <option value="percent">Pourcentage % de la base / نسبة % من الأساس</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Valeur / القيمة <small id="b_val_hint">(مبلغ)</small></label>
                    <input type="number" name="amount" class="form-control" step="0.01" required>
                </div>
            </div>
            <div class="form-row cols-4">
                <div class="form-group">
                    <label class="form-label">Devise / العملة</label>
                    <select name="currency" class="form-select">
                        <option value="USD">USD ($)</option>
                        <option value="LBP">LBP (ل.ل)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Du mois / من شهر</label>
                    <select name="start_month" class="form-select">
                        <option value="">Tous / الكل</option>
                        <?php for ($m=1;$m<=12;$m++): ?><option value="<?=$m?>"><?=monthName($m)?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Au mois / إلى شهر</label>
                    <select name="end_month" class="form-select">
                        <option value="">Tous / الكل</option>
                        <?php for ($m=1;$m<=12;$m++): ?><option value="<?=$m?>"><?=monthName($m)?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus"></i> Ajouter / إضافة</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-list"></i> Primes existantes</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">المكافآت الموجودة</div>
    </h3></div>
    <div class="card-body">
        <?php if (empty($bonuses)): ?>
            <div class="empty-state"><i class="fas fa-gift"></i><h4>
                <span dir="ltr">Aucune prime</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">لا توجد مكافآت</div>
            </h4></div>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Type / النوع</th><th>Période / الفترة</th><th>Montant / المبلغ</th><th>Devise / العملة</th><th>Du / من</th><th>Au / إلى</th><th>Action / إجراء</th></tr></thead>
                <tbody>
                    <?php 
                    $typeLabels = ['prime_fixe'=>'💰 Prime fixe','aide_complementaire'=>'🤝 Aide','transport_complement'=>'🚌 Transport'];
                    foreach ($bonuses as $b): ?>
                        <tr>
                            <td><?= $typeLabels[$b['bonus_type']] ?? $b['bonus_type'] ?></td>
                            <td>P<?= $b['period_number'] ?></td>
                            <td><strong><?= number_format($b['amount'], 2) ?><?= ($b['value_type'] ?? 'amount')==='percent' ? ' %' : '' ?></strong></td>
                            <td><?= ($b['value_type'] ?? 'amount')==='percent' ? '% من الأساس' : $b['currency'] ?></td>
                            <td><?= $b['start_month'] ? monthName($b['start_month'], 'fr', true) : 'Tous' ?></td>
                            <td><?= $b['end_month'] ? monthName($b['end_month'], 'fr', true) : 'Tous' ?></td>
                            <td><a href="?employee_id=<?= $employeeId ?>&delete=<?= $b['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer ?"><i class="fas fa-trash"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function bonusValTypeChange(){
    var t = document.getElementById('b_value_type').value;
    document.getElementById('b_val_hint').textContent = (t === 'percent') ? '(%)' : '(مبلغ)';
    var cur = document.querySelector('select[name=currency]');
    if (cur) { cur.disabled = (t === 'percent'); }
}
document.addEventListener('DOMContentLoaded', bonusValTypeChange);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
