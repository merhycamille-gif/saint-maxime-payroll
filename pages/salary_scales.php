<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'salary_scales';
$pageTitle = 'Versions de la grille / إصدارات السلسلة';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_version') {
            $nameAr = trim($_POST['name_ar'] ?? '');
            $nameFr = trim($_POST['name_fr'] ?? '');
            $from = $_POST['effective_from'] ?: null;
            $to   = $_POST['effective_to'] ?: null;
            $copyFrom = (int)($_POST['copy_from'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            if (!$nameAr || !$nameFr) throw new Exception('الاسم مطلوب');
            if (!$from) throw new Exception('تاريخ البداية مطلوب');

            $db->beginTransaction();
            $db->prepare("INSERT INTO salary_scale_versions (name_ar, name_fr, effective_from, effective_to, notes) VALUES (?,?,?,?,?)")
               ->execute([$nameAr, $nameFr, $from, $to, $notes]);
            $newId = (int)$db->lastInsertId();
            // نسخ صفوف السلسلة من إصدار مصدر (لتعديلها بعدها)
            if ($copyFrom > 0) {
                $db->prepare("INSERT INTO salary_scale_2017 (grade, version_id, base_salary_2008, glaa_maaisha_2012, old_grade_value, new_salary_2017, new_grade_value)
                              SELECT grade, ?, base_salary_2008, glaa_maaisha_2012, old_grade_value, new_salary_2017, new_grade_value
                              FROM salary_scale_2017 WHERE version_id = ?")
                   ->execute([$newId, $copyFrom]);
            }
            // إقفال الإصدار السابق المفتوح تلقائياً في اليوم السابق لبداية الجديد (اختياري وآمن)
            $prevDay = date('Y-m-d', strtotime($from . ' -1 day'));
            $db->prepare("UPDATE salary_scale_versions SET effective_to = ? WHERE id <> ? AND effective_to IS NULL AND effective_from < ?")
               ->execute([$prevDay, $newId, $from]);
            $db->commit();
            $nRec = recalcSalariesInRange($db, $from, $to); // إعادة حساب مدى الإصدار الجديد
            $_SESSION['flash_success'] = "تم إنشاء الإصدار، وأُعيد حساب $nRec راتب تلقائياً / Version créée";
            header('Location: ' . BASE_URL . 'pages/salary_scales.php?edit=' . $newId);
            exit;
        }

        if ($action === 'save_meta') {
            $id = (int)$_POST['id'];
            $db->prepare("UPDATE salary_scale_versions SET name_ar=?, name_fr=?, effective_from=?, effective_to=?, notes=?, is_active=? WHERE id=?")
               ->execute([
                   trim($_POST['name_ar']), trim($_POST['name_fr']),
                   $_POST['effective_from'] ?: null, $_POST['effective_to'] ?: null,
                   trim($_POST['notes'] ?? ''), isset($_POST['is_active']) ? 1 : 0, $id
               ]);
            $nRec = recalcSalariesInRange($db, $_POST['effective_from'] ?: null, $_POST['effective_to'] ?: null);
            $_SESSION['flash_success'] = "تم الحفظ، وأُعيد حساب $nRec راتب تلقائياً / Enregistré";
            header('Location: ' . BASE_URL . 'pages/salary_scales.php?edit=' . $id);
            exit;
        }

        if ($action === 'save_rows') {
            $vid = (int)$_POST['version_id'];
            $salaries = $_POST['new_salary_2017'] ?? [];
            $gradeVals = $_POST['new_grade_value'] ?? [];
            $db->beginTransaction();
            $up = $db->prepare("UPDATE salary_scale_2017 SET new_salary_2017=?, new_grade_value=? WHERE version_id=? AND grade=?");
            foreach ($salaries as $grade => $sal) {
                $g = (int)$grade;
                $s = (int)str_replace([',', ' '], '', (string)$sal);
                $gv = (int)str_replace([',', ' '], '', (string)($gradeVals[$grade] ?? 0));
                $up->execute([$s, $gv, $vid, $g]);
            }
            $db->commit();
            // إعادة حساب مدى هذا الإصدار (التواريخ من جدول الإصدارات)
            $vr = $db->prepare("SELECT effective_from, effective_to FROM salary_scale_versions WHERE id = ?");
            $vr->execute([$vid]); $vrr = $vr->fetch();
            $nRec = $vrr ? recalcSalariesInRange($db, $vrr['effective_from'], $vrr['effective_to']) : 0;
            $_SESSION['flash_success'] = "تم حفظ قيم السلسلة، وأُعيد حساب $nRec راتب تلقائياً / Grille enregistrée";
            header('Location: ' . BASE_URL . 'pages/salary_scales.php?edit=' . $vid);
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . BASE_URL . 'pages/salary_scales.php');
        exit;
    }
}

if (isset($_GET['delete_version'])) {
    $id = (int)$_GET['delete_version'];
    if ($id === 1) {
        $_SESSION['flash_error'] = 'لا يمكن حذف الإصدار الأساسي (سلسلة 2017)';
    } else {
        $vr = $db->prepare("SELECT effective_from, effective_to FROM salary_scale_versions WHERE id = ?");
        $vr->execute([$id]); $vrr = $vr->fetch();
        $db->prepare("DELETE FROM salary_scale_2017 WHERE version_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM salary_scale_versions WHERE id = ?")->execute([$id]);
        $nRec = $vrr ? recalcSalariesInRange($db, $vrr['effective_from'], $vrr['effective_to']) : 0;
        $_SESSION['flash_success'] = "تم حذف الإصدار، وأُعيد حساب $nRec راتب تلقائياً / Version supprimée";
    }
    header('Location: ' . BASE_URL . 'pages/salary_scales.php');
    exit;
}

$versions = $db->query("SELECT v.*, (SELECT COUNT(*) FROM salary_scale_2017 s WHERE s.version_id = v.id) AS rows_cnt
                        FROM salary_scale_versions v ORDER BY v.effective_from DESC, v.id DESC")->fetchAll();

$editVid = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editVersion = null; $scaleRows = [];
if ($editVid > 0) {
    $stmt = $db->prepare("SELECT * FROM salary_scale_versions WHERE id = ?");
    $stmt->execute([$editVid]);
    $editVersion = $stmt->fetch() ?: null;
    if ($editVersion) {
        $stmt = $db->prepare("SELECT * FROM salary_scale_2017 WHERE version_id = ? ORDER BY grade");
        $stmt->execute([$editVid]);
        $scaleRows = $stmt->fetchAll();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <?= $lang === 'ar'
        ? 'إذا صدرت سلسلة رواتب جديدة، أنشئ إصداراً جديداً بتاريخ بدايته، وانسخ القيم من الإصدار الحالي ثم عدّلها. البرنامج يحسب راتب كل شهر حسب الإصدار الساري بذلك التاريخ. الإصدار الحالي يبقى للرواتب القديمة.'
        : 'Si une nouvelle grille est publiée, créez une nouvelle version avec sa date, copiez les valeurs de la version actuelle puis modifiez-les. Le calcul de chaque mois utilise la version en vigueur à cette date.' ?>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-layer-group"></i> Versions / الإصدارات</h3></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>#</th><th>Nom / الاسم</th><th>Du / من</th><th>Au / إلى</th><th>Grades</th><th>Actif</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($versions as $v): ?>
                <tr>
                    <td><?= $v['id'] ?></td>
                    <td><strong><?= e($lang==='ar'?$v['name_ar']:$v['name_fr']) ?></strong><?php if($v['notes']):?><br><small class="text-muted"><?= e($v['notes']) ?></small><?php endif;?></td>
                    <td><?= formatDate($v['effective_from']) ?></td>
                    <td><?= $v['effective_to'] ? formatDate($v['effective_to']) : '<span class="badge badge-success">'.($lang==='ar'?'حتى الآن':'En cours').'</span>' ?></td>
                    <td><?= $v['rows_cnt'] ?></td>
                    <td><?= $v['is_active'] ? '✅' : '—' ?></td>
                    <td style="white-space:nowrap">
                        <a href="?edit=<?= $v['id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-edit"></i> <?= $lang==='ar'?'تعديل':'Éditer' ?></a>
                        <?php if ((int)$v['id'] !== 1): ?>
                            <a href="?delete_version=<?= $v['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer cette version ? / حذف الإصدار؟"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus"></i> Nouvelle version / إصدار جديد</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create_version">
            <div class="form-row cols-2">
                <div class="form-group"><label class="form-label">Nom (AR) / الاسم بالعربي</label>
                    <input type="text" name="name_ar" class="form-control" placeholder="سلسلة 2027" required></div>
                <div class="form-group"><label class="form-label">Nom (FR) / الاسم بالفرنسي</label>
                    <input type="text" name="name_fr" class="form-control" placeholder="Grille 2027" required></div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group"><label class="form-label">Du / من تاريخ</label>
                    <input type="date" name="effective_from" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group"><label class="form-label">Au / إلى <small>(<?= $lang==='ar'?'فارغ=حتى الآن':'vide' ?>)</small></label>
                    <input type="date" name="effective_to" class="form-control"></div>
                <div class="form-group"><label class="form-label">Copier depuis / نسخ القيم من</label>
                    <select name="copy_from" class="form-select">
                        <?php foreach ($versions as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= ((int)$v['id']===1)?'selected':'' ?>><?= e($lang==='ar'?$v['name_ar']:$v['name_fr']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>
            <div class="form-group"><label class="form-label">Note / ملاحظة</label>
                <input type="text" name="notes" class="form-control" placeholder="<?= $lang==='ar'?'مثال: مرسوم جديد رقم ...':'ex: nouveau décret ...' ?>"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer & copier / إنشاء ونسخ</button>
            <p class="text-muted" style="margin-top:8px"><small><?= $lang==='ar'?'سيتم نسخ كل الدرجات من الإصدار المختار لتعدّلها، وإقفال الإصدار السابق تلقائياً قبل بداية الجديد.':'Tous les grades seront copiés pour modification; l\'ancienne version sera clôturée automatiquement.' ?></small></p>
        </form>
    </div>
</div>

<?php if ($editVersion): ?>
<div class="card" id="editzone">
    <div class="card-header"><h3><i class="fas fa-edit"></i> <?= e($lang==='ar'?$editVersion['name_ar']:$editVersion['name_fr']) ?> — <?= $lang==='ar'?'تفاصيل وقيم':'Détails & valeurs' ?></h3></div>
    <div class="card-body">
        <form method="POST" class="lockedit" style="margin-bottom:20px">
            <input type="hidden" name="action" value="save_meta">
            <input type="hidden" name="id" value="<?= (int)$editVersion['id'] ?>">
            <div class="form-row cols-4">
                <div class="form-group"><label class="form-label">Nom (AR)</label><input type="text" name="name_ar" class="form-control" value="<?= e($editVersion['name_ar']) ?>" required></div>
                <div class="form-group"><label class="form-label">Nom (FR)</label><input type="text" name="name_fr" class="form-control" value="<?= e($editVersion['name_fr']) ?>" required></div>
                <div class="form-group"><label class="form-label">Du / من</label><input type="date" name="effective_from" class="form-control" value="<?= e($editVersion['effective_from']) ?>" required></div>
                <div class="form-group"><label class="form-label">Au / إلى</label><input type="date" name="effective_to" class="form-control" value="<?= e($editVersion['effective_to'] ?? '') ?>"></div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group" style="flex:2"><label class="form-label">Note</label><input type="text" name="notes" class="form-control" value="<?= e($editVersion['notes'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">&nbsp;</label>
                    <label style="display:block"><input type="checkbox" name="is_active" <?= $editVersion['is_active']?'checked':'' ?>> <?= $lang==='ar'?'مفعّل':'Actif' ?></label>
                </div>
            </div>
            <button type="submit" class="btn btn-light btn-sm"><i class="fas fa-save"></i> <?= $lang==='ar'?'حفظ التفاصيل':'Enregistrer détails' ?></button>
        </form>

        <form method="POST" class="lockedit">
            <input type="hidden" name="action" value="save_rows">
            <input type="hidden" name="version_id" value="<?= (int)$editVersion['id'] ?>">
            <div style="max-height:520px;overflow:auto">
            <table class="table">
                <thead><tr><th><?= $lang==='ar'?'الدرجة':'Grade' ?></th><th><?= $lang==='ar'?'الراتب (new_salary_2017)':'Salaire' ?></th><th><?= $lang==='ar'?'قيمة الدرجة':'Valeur grade' ?></th></tr></thead>
                <tbody>
                <?php foreach ($scaleRows as $r): ?>
                    <tr>
                        <td><strong><?= (int)$r['grade'] ?></strong></td>
                        <td><input type="number" name="new_salary_2017[<?= (int)$r['grade'] ?>]" class="form-control form-control-sm" value="<?= (int)$r['new_salary_2017'] ?>"></td>
                        <td><input type="number" name="new_grade_value[<?= (int)$r['grade'] ?>]" class="form-control form-control-sm" value="<?= (int)$r['new_grade_value'] ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:12px"><i class="fas fa-save"></i> <?= $lang==='ar'?'حفظ قيم السلسلة':'Enregistrer la grille' ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
