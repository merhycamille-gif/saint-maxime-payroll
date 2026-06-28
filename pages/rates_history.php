<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'rates_history';
$pageTitle = 'Taux datés / النِّسَب حسب التاريخ';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';

function rhNum($v) {
    $v = trim(str_replace([',', ' '], '', (string)$v));
    return ($v === '') ? null : (float)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $keys = array_keys(ratedParams());
            $key = $_POST['param_key'] ?? '';
            if (!in_array($key, $keys, true)) throw new Exception('قيمة غير صالحة');
            $value = rhNum($_POST['value'] ?? '');
            if ($value === null) throw new Exception('القيمة مطلوبة');
            $from = $_POST['effective_from'] ?: null;
            $to   = $_POST['effective_to'] ?: null;
            $notes = trim($_POST['notes'] ?? '');
            if (!$from) throw new Exception('تاريخ البداية مطلوب');

            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare("UPDATE rate_history SET param_key=?, value=?, effective_from=?, effective_to=?, notes=? WHERE id=?")
                   ->execute([$key, $value, $from, $to, $notes, $id]);
            } else {
                $db->prepare("INSERT INTO rate_history (param_key, value, effective_from, effective_to, notes) VALUES (?,?,?,?,?)")
                   ->execute([$key, $value, $from, $to, $notes]);
            }
            syncCurrentRatesToSettings(); // حدّث القيم الحالية المعروضة باللوحة
            $nRec = recalcSalariesInRange($db, $from, $to); // إعادة حساب تلقائية للمدى المتأثّر
            $_SESSION['flash_success'] = "تم الحفظ، وأُعيد حساب $nRec راتب تلقائياً / Enregistré + $nRec recalculés";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . BASE_URL . 'pages/rates_history.php');
    exit;
}

if (isset($_GET['delete'])) {
    $r = $db->prepare("SELECT effective_from, effective_to FROM rate_history WHERE id = ?");
    $r->execute([(int)$_GET['delete']]); $rr = $r->fetch();
    $db->prepare("DELETE FROM rate_history WHERE id = ?")->execute([(int)$_GET['delete']]);
    syncCurrentRatesToSettings();
    $nRec = $rr ? recalcSalariesInRange($db, $rr['effective_from'], $rr['effective_to']) : 0;
    $_SESSION['flash_success'] = "تم الحذف، وأُعيد حساب $nRec راتب تلقائياً / Supprimé";
    header('Location: ' . BASE_URL . 'pages/rates_history.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM rate_history WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
}

$rows = $db->query("SELECT * FROM rate_history ORDER BY param_key, effective_from DESC, id DESC")->fetchAll();
$byKey = [];
foreach ($rows as $r) $byKey[$r['param_key']][] = $r;

// عرض القيمة بشكل لطيف (ليرة بلا كسور، نسبة مختصرة)
function fmtRate($key, $val) {
    $p = ratedParams();
    if (($p[$key]['unit'] ?? '') === 'lbp') return formatLBP($val);
    $v = rtrim(rtrim(number_format((float)$val, 4, '.', ''), '0'), '.');
    return $v . ' %';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <?= $lang === 'ar'
        ? 'هنا تُدخل النِّسَب والقيم (الضمان، الصندوق، نهاية الخدمة، الحد الأدنى للأجور) مع فترة كل قيمة (من تاريخ إلى تاريخ). البرنامج يستعمل القيمة السارية حسب شهر الراتب. أضف صفّاً جديداً لكل تغيير رسمي بدل تعديل القديم، حتى تبقى الرواتب القديمة صحيحة.'
        : 'Définissez les taux et valeurs (CNSS, EOC, fin de service, salaire minimum) avec une période (du / au). Le calcul utilise la valeur en vigueur selon le mois. Ajoutez une nouvelle ligne à chaque changement officiel au lieu de modifier l\'ancienne.' ?>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus"></i> <?= $editRow ? 'Modifier une valeur / تعديل قيمة' : 'Ajouter une valeur / إضافة قيمة' ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
            <div class="form-row cols-4">
                <div class="form-group">
                    <label class="form-label">Valeur / القيمة</label>
                    <select name="param_key" class="form-select" required>
                        <?php foreach (ratedParams() as $key => $info): ?>
                            <option value="<?= $key ?>" <?= ($editRow && $editRow['param_key'] === $key) ? 'selected' : '' ?>>
                                <?= e($info['ar']) ?> — <?= e($info['fr']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Montant / المقدار</label>
                    <input type="number" name="value" class="form-control" step="0.0001" value="<?= e($editRow['value'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Du / من تاريخ</label>
                    <input type="date" name="effective_from" class="form-control" value="<?= e($editRow['effective_from'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Au / إلى <small>(<?= $lang==='ar'?'فارغ=حتى الآن':'vide=en cours' ?>)</small></label>
                    <input type="date" name="effective_to" class="form-control" value="<?= e($editRow['effective_to'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group" style="flex:2">
                    <label class="form-label">Note / ملاحظة</label>
                    <input type="text" name="notes" class="form-control" value="<?= e($editRow['notes'] ?? '') ?>" placeholder="<?= $lang==='ar'?'مثال: مرسوم رقم ... بتاريخ ...':'ex: décret n° ...' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                </div>
            </div>
            <?php if ($editRow): ?>
                <a href="<?= BASE_URL ?>pages/rates_history.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Annuler / إلغاء</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php foreach (ratedParams() as $key => $info): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-percent"></i> <?= e($info['ar']) ?> — <?= e($info['fr']) ?></h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Du / من</th><th>Au / إلى</th><th>Valeur / القيمة</th><th>Note</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($byKey[$key])): ?>
                    <tr><td colspan="5" class="text-muted">—</td></tr>
                <?php else: foreach ($byKey[$key] as $r): ?>
                    <tr>
                        <td><?= formatDate($r['effective_from']) ?></td>
                        <td><?= $r['effective_to'] ? formatDate($r['effective_to']) : '<span class="badge badge-success">'.($lang==='ar'?'حتى الآن':'En cours').'</span>' ?></td>
                        <td><strong><?= fmtRate($key, $r['value']) ?></strong></td>
                        <td><small><?= e($r['notes']) ?></small></td>
                        <td style="white-space:nowrap">
                            <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-edit"></i></a>
                            <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer ? / حذف؟"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
