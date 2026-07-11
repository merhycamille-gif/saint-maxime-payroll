<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'social_security';
$pageTitle = 'Plafonds CNSS / حدود الضمان';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';
$message = ''; $messageType = 'success';

// رقم خالٍ من الفواصل أو سلسلة فارغة → null
function ssNum($v) {
    $v = trim(str_replace([',', ' '], '', (string)$v));
    return ($v === '') ? null : (int)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $branches = array_keys(cnssBranches());
            $branch = $_POST['branch'] ?? '';
            if (!in_array($branch, $branches, true)) throw new Exception('فرع غير صالح');

            $max = ssNum($_POST['max_salary_lbp'] ?? '');           // فارغ = بلا سقف
            $from = $_POST['effective_from'] ?: null;
            $to   = $_POST['effective_to'] ?: null;
            $notes = trim($_POST['notes'] ?? '');
            if (!$from) throw new Exception('تاريخ البداية مطلوب');

            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare("UPDATE cnss_brackets SET branch=?, max_salary_lbp=?, effective_from=?, effective_to=?, notes=? WHERE id=?")
                   ->execute([$branch, $max, $from, $to, $notes, $id]);
            } else {
                $db->prepare("INSERT INTO cnss_brackets (branch, max_salary_lbp, effective_from, effective_to, notes) VALUES (?,?,?,?,?)")
                   ->execute([$branch, $max, $from, $to, $notes]);
            }
            $nRec = recalcSalariesInRange($db, $from, $to); // إعادة حساب تلقائية للمدى المتأثّر
            $_SESSION['flash_success'] = "تم الحفظ، وأُعيد حساب $nRec راتب تلقائياً / Enregistré + $nRec recalculés";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . BASE_URL . 'pages/social_security.php');
    exit;
}

if (isset($_GET['delete'])) {
    $r = $db->prepare("SELECT effective_from, effective_to FROM cnss_brackets WHERE id = ?");
    $r->execute([(int)$_GET['delete']]); $rr = $r->fetch();
    $db->prepare("DELETE FROM cnss_brackets WHERE id = ?")->execute([(int)$_GET['delete']]);
    $nRec = $rr ? recalcSalariesInRange($db, $rr['effective_from'], $rr['effective_to']) : 0;
    $_SESSION['flash_success'] = "تم الحذف، وأُعيد حساب $nRec راتب تلقائياً / Supprimé";
    header('Location: ' . BASE_URL . 'pages/social_security.php');
    exit;
}

// صف قيد التعديل (إن وُجد)
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM cnss_brackets WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
}

$rows = $db->query("SELECT * FROM cnss_brackets ORDER BY FIELD(branch,'maladie_maternite','allocations_familiales','fin_de_service','eoc'), effective_from DESC, id DESC")->fetchAll();

// تجميع حسب الفرع للعرض
$byBranch = [];
foreach ($rows as $r) $byBranch[$r['branch']][] = $r;

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <?= $lang === 'ar'
        ? 'هنا تُدخل الحد الأقصى (السقف) للأجر الخاضع لاشتراك كل فرع من الضمان، مع فترة كل قيمة (من تاريخ إلى تاريخ). البرنامج يحسب الاشتراك على الأجر بشرط ألّا يتجاوز هذا السقف حسب شهر الراتب. اترك السقف فارغاً إذا لا يوجد سقف للفرع.'
        : 'Définissez le plafond du salaire soumis à chaque branche CNSS, avec une période (du / au). Le calcul plafonne le salaire à cette valeur selon le mois. Laissez vide s\'il n\'y a pas de plafond.' ?>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-plus"></i> <?= $editRow ? 'Modifier une limite' : 'Ajouter une limite' ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= $editRow ? 'تعديل حدّ' : 'إضافة حدّ' ?></div>
    </h3></div>
    <div class="card-body">
        <form method="POST"<?= $editRow ? ' class="lockedit"' : '' ?>>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">Branche / الفرع</label>
                    <select name="branch" class="form-select" required>
                        <?php foreach (cnssBranches() as $key => $names): ?>
                            <option value="<?= $key ?>" <?= ($editRow && $editRow['branch'] === $key) ? 'selected' : '' ?>>
                                <?= e($names['ar']) ?> — <?= e($names['fr']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Du / من تاريخ</label>
                    <input type="date" name="effective_from" class="form-control" value="<?= e($editRow['effective_from'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Au / إلى تاريخ <small>(vide = jusqu'à présent / فارغ = حتى الآن)</small></label>
                    <input type="date" name="effective_to" class="form-control" value="<?= e($editRow['effective_to'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Plafond (L.L) / الحد الأقصى <small>(vide = sans plafond / فارغ = بلا سقف)</small></label>
                    <input type="number" name="max_salary_lbp" class="form-control" min="0" value="<?= e($editRow['max_salary_lbp'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Note / ملاحظة</label>
                <input type="text" name="notes" class="form-control" value="<?= e($editRow['notes'] ?? '') ?>" placeholder="ex: circulaire CNSS n° ... / مثال: تعميم الضمان رقم ... بتاريخ ...">
            </div>
            <?php if ($editRow): ?>
                <a href="<?= BASE_URL ?>pages/social_security.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Annuler / إلغاء</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php foreach (cnssBranches() as $key => $names): ?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-shield-alt"></i> <?= e($names['fr']) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= e($names['ar']) ?></div>
    </h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr>
                <th>Du / من</th><th>Au / إلى</th>
                <th>Plafond / الحد الأقصى</th>
                <th>Note / ملاحظة</th><th>Action / إجراء</th>
            </tr></thead>
            <tbody>
                <?php if (empty($byBranch[$key])): ?>
                    <tr><td colspan="5" class="text-muted">Aucun / لا يوجد</td></tr>
                <?php else: foreach ($byBranch[$key] as $r): ?>
                    <tr>
                        <td><?= formatDate($r['effective_from']) ?></td>
                        <td><?= $r['effective_to'] ? formatDate($r['effective_to']) : '<span class="badge badge-success">En cours / حتى الآن</span>' ?></td>
                        <td><?= $r['max_salary_lbp'] !== null ? '<strong>'.formatLBP($r['max_salary_lbp']).'</strong>' : '<span class="text-muted">sans plafond / بلا سقف</span>' ?></td>
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
