<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'exceptional_laws';
$pageTitle = 'القوانين الاستثنائية / Lois exceptionnelles';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';

// عدد الدرجات: رقم بفاصلة عشرية (يدعم 4.5)، فارغ → 0
function elNum($v) {
    $v = trim(str_replace([',', ' '], '', (string)$v));
    return ($v === '') ? 0.0 : (float)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $lawNumber = trim($_POST['law_number'] ?? '');
            if ($lawNumber === '') throw new Exception('رقم القانون مطلوب');
            $lawDate = $_POST['law_date'] ?: null;
            $effFrom = $_POST['effective_from'] ?: ($lawDate ?: date('Y-m-d')); // «من»
            $effTo   = $_POST['effective_to'] ?: null;                          // «إلى» (NULL = مفتوح)
            $effDate = $effFrom; // متزامن مع «من» (تستعمله exceptionalLawEffectiveDate كالسنة الدنيا)
            if ($effTo && strtotime($effTo) < strtotime($effFrom)) throw new Exception('«إلى» يجب أن يكون بعد «من»');
            $grades  = elNum($_POST['grades_count'] ?? '');
            $descAr  = trim($_POST['description_ar'] ?? '');
            $descFr  = trim($_POST['description_fr'] ?? '');
            $applies = trim($_POST['applies_to'] ?? 'preprimary_primary_intermediate');
            $active  = isset($_POST['is_active']) ? 1 : 0;
            $autoApply = isset($_POST['auto_apply']) ? 1 : 0; // يُطبّق تلقائياً (344 = يدوي)
            if (!$lawDate) throw new Exception('تاريخ القانون مطلوب');

            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare("UPDATE exceptional_grades_laws SET law_number=?, law_date=?, grades_count=?, description_ar=?, description_fr=?, effective_date=?, effective_from=?, effective_to=?, applies_to=?, is_active=?, auto_apply=? WHERE id=?")
                   ->execute([$lawNumber, $lawDate, $grades, $descAr, $descFr, $effDate, $effFrom, $effTo, $applies, $active, $autoApply, $id]);
                $_SESSION['flash_success'] = "تم تعديل القانون $lawNumber / Loi modifiée";
            } else {
                // منع تكرار رقم القانون
                $chk = $db->prepare("SELECT COUNT(*) FROM exceptional_grades_laws WHERE law_number=?");
                $chk->execute([$lawNumber]);
                if ((int)$chk->fetchColumn() > 0) throw new Exception("القانون رقم $lawNumber موجود مسبقاً");
                $db->prepare("INSERT INTO exceptional_grades_laws (law_number, law_date, grades_count, description_ar, description_fr, effective_date, effective_from, effective_to, applies_to, is_active, auto_apply) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$lawNumber, $lawDate, $grades, $descAr, $descFr, $effDate, $effFrom, $effTo, $applies, $active, $autoApply]);
                $msg = $autoApply ? 'يُطبّق تلقائياً' : 'يدوي — طبّقه من ملف كل أستاذ';
                $_SESSION['flash_success'] = "تمت إضافة القانون $lawNumber ($msg) / Loi ajoutée";
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE exceptional_grades_laws SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            $_SESSION['flash_success'] = 'تم تغيير حالة القانون / Statut modifié';
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . BASE_URL . 'pages/exceptional_laws.php');
    exit;
}

if (isset($_GET['delete'])) {
    requireWriteAction();
    $id = (int)$_GET['delete'];
    $r = $db->prepare("SELECT law_number FROM exceptional_grades_laws WHERE id=?");
    $r->execute([$id]); $ln = $r->fetchColumn();
    if ($ln !== false) {
        // منع الحذف إذا مطبّق على أساتذة (وإلا ينكسر إلغاء التطبيق)
        $cnt = $db->prepare("SELECT COUNT(*) FROM employee_grade_history WHERE law_reference=?");
        $cnt->execute([$ln]); $applied = (int)$cnt->fetchColumn();
        if ($applied > 0) {
            $_SESSION['flash_error'] = "لا يمكن حذف القانون $ln لأنّه مطبّق على $applied أستاذ. عطّله بدلاً من حذفه (زرّ تعطيل).";
        } else {
            $db->prepare("DELETE FROM exceptional_grades_laws WHERE id=?")->execute([$id]);
            $_SESSION['flash_success'] = "تم حذف القانون $ln / Loi supprimée";
        }
    }
    header('Location: ' . BASE_URL . 'pages/exceptional_laws.php');
    exit;
}

// صف قيد التعديل
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM exceptional_grades_laws WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
}

$rows = $db->query("SELECT * FROM exceptional_grades_laws ORDER BY law_date, law_number")->fetchAll();
// عدد **الأساتذة** المطبّق عليهم كل قانون.
// 🔴 كان COUNT(*) يعدّ صفوف السجلّ لا الأساتذة: الدرجة الاستثنائية تُخزَّن مفردة (+1/½)
// صفّاً لكل وحدة، فكان العدد منفوخاً ٣ أضعاف (قانون 102: 573 بدل 191)، وكان يشمل
// المحذوفين ومدارس لا يراها المستخدم. الصحيح: DISTINCT + is_deleted=0 + نطاق المدارس.
$appliedCounts = [];
$acSt = $db->prepare("SELECT gh.law_reference, COUNT(DISTINCT gh.employee_id) c
                        FROM employee_grade_history gh JOIN employees e ON e.id = gh.employee_id
                       WHERE gh.law_reference IS NOT NULL AND e.is_deleted = 0" . schoolScopeSql('e.school_id') . "
                       GROUP BY gh.law_reference");
$acSt->execute();
foreach ($acSt->fetchAll() as $ac) {
    $appliedCounts[$ac['law_reference']] = (int)$ac['c'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <?= $lang === 'ar'
        ? 'هنا تُدير القوانين الاستثنائية: تزيد قانوناً جديداً (بتاريخ <strong>من – إلى</strong>)، تعدّل، تعطّل، أو تحذف. <strong>القوانين «تلقائية» تُطبّق تلقائياً على الأساتذة المستحقّين</strong>، أمّا «اليدوية» (متل قانون 344) فتظهر كخانة اختيار (✔) في ملف كل أستاذ وإنت تطبّقها لمن تريد. تقدر دائماً تعدّل أي قانون.'
        : 'Gérez ici les lois exceptionnelles : ajouter (avec période du–au), modifier, désactiver, supprimer. Les lois « Auto » s\'appliquent automatiquement ; les lois « Manuel » (ex: loi 344) sont cochées par enseignant.' ?>
</div>

<div class="alert alert-warning">
    <i class="fas fa-bolt"></i>
    <?= $lang === 'ar'
        ? '<strong>قانون 2017 خاص:</strong> عدد درجاته يُحسب تلقائياً لكل أستاذ (6 لمن دخل الملاك قبل 1/1/2010 أو قسم ثاني 2010-2017؛ درجتان لإجازة جامعية/جاردينير 2010-2017؛ صفر لغير ذلك). فحقل «عدد الدرجات» له تقريبي فقط.'
        : 'Loi 2017 : le nombre d\'échelons est calculé par enseignant (6 ou 2 ou 0 selon date de titularisation + diplôme).' ?>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-<?= $editRow ? 'edit' : 'plus' ?>"></i>
        <?= $editRow ? 'Modifier une loi' : 'Ajouter une loi' ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= $editRow ? 'تعديل قانون' : 'إضافة قانون جديد' ?></div></h3></div>
    <div class="card-body">
        <form method="POST"<?= $editRow ? ' class="lockedit"' : '' ?>>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">رقم القانون / N° loi</label>
                    <input type="text" name="law_number" class="form-control" value="<?= e($editRow['law_number'] ?? '') ?>" required placeholder="مثال: 46">
                </div>
                <div class="form-group">
                    <label class="form-label">تاريخ القانون / Date de la loi</label>
                    <input type="date" name="law_date" class="form-control" value="<?= e($editRow['law_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">عدد الدرجات / Nb échelons <small>(ex: 4.5 / يدعم 4.5)</small></label>
                    <input type="number" step="0.5" name="grades_count" class="form-control" value="<?= e($editRow['grades_count'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">من تاريخ / Du <small>(début / بداية السريان)</small></label>
                    <input type="date" name="effective_from" class="form-control" value="<?= e($editRow['effective_from'] ?? $editRow['effective_date'] ?? date('Y-01-01')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">إلى تاريخ / Au <small>(vide = en cours / فارغ = مفتوح)</small></label>
                    <input type="date" name="effective_to" class="form-control" value="<?= e($editRow['effective_to'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">يطبَّق على / Applies to</label>
                    <input type="text" name="applies_to" class="form-control" value="<?= e($editRow['applies_to'] ?? 'preprimary_primary_intermediate') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">الوصف بالعربي / Description (ar)</label>
                <input type="text" name="description_ar" class="form-control" value="<?= e($editRow['description_ar'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">الوصف بالفرنسي / Description (fr)</label>
                <input type="text" name="description_fr" class="form-control" value="<?= e($editRow['description_fr'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" style="cursor:pointer">
                    <input type="checkbox" name="is_active" value="1" <?= (!$editRow || $editRow['is_active']) ? 'checked' : '' ?> style="width:18px;height:18px;vertical-align:middle">
                    Actif (visible dans les fiches) / مفعّل (يظهر للأساتذة)
                </label>
            </div>
            <div class="form-group">
                <label class="form-label" style="cursor:pointer">
                    <input type="checkbox" name="auto_apply" value="1" <?= (!$editRow || $editRow['auto_apply']) ? 'checked' : '' ?> style="width:18px;height:18px;vertical-align:middle">
                    <strong>Application automatique / يُطبّق تلقائياً على المستحقّين</strong>
                    <small class="text-muted">(décocher = manuel (ex: loi 344) / شيل الصح = يدوي فقط)</small>
                </label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ / Enregistrer</button>
            <?php if ($editRow): ?>
                <a href="<?= BASE_URL ?>pages/exceptional_laws.php" class="btn btn-light"><i class="fas fa-times"></i> إلغاء / Annuler</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-scroll"></i> Lois exceptionnelles</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">القوانين الاستثنائية</div>
    </h3></div>
    <div class="card-body">
        <table class="table" style="font-size:13px">
            <thead><tr>
                <th>N° loi / رقم القانون</th><th>Date loi / تاريخ القانون</th><th>Nb échelons / عدد الدرجات</th><th>Du / من</th><th>Au / إلى</th>
                <th>Application / التطبيق</th><th>Description / الوصف</th><th>Statut / الحالة</th><th>Appliqué à / مطبّق على</th><th>Action / إجراء</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="10" class="text-muted">Aucune loi / لا يوجد قوانين</td></tr>
            <?php else: foreach ($rows as $r):
                $applied = $appliedCounts[$r['law_number']] ?? 0;
                $is2017 = ((string)$r['law_number'] === '2017');
            ?>
                <tr style="<?= $r['is_active'] ? '' : 'opacity:.5;background:#f3f4f6' ?>">
                    <td><strong><?= e($r['law_number']) ?></strong></td>
                    <td><?= formatDate($r['law_date']) ?></td>
                    <td><?= $is2017 ? '<span class="badge badge-gold">6/2/0 selon ens. / حسب الأستاذ</span>' : '+'.(float)$r['grades_count'] ?></td>
                    <td><?= formatDate($r['effective_from'] ?? $r['effective_date']) ?></td>
                    <td><?= $r['effective_to'] ? formatDate($r['effective_to']) : '<span class="badge badge-success">En cours / مفتوح</span>' ?></td>
                    <td><?= $r['auto_apply']
                        ? '<span class="badge badge-success">Auto / تلقائي</span>'
                        : '<span class="badge badge-warning">Manuel / يدوي</span>' ?></td>
                    <td><small><?= e($r['description_ar'] ?: $r['description_fr']) ?></small></td>
                    <td><?= $r['is_active']
                        ? '<span class="badge badge-success">Actif / مفعّل</span>'
                        : '<span class="badge badge-secondary">Inactif / معطّل</span>' ?></td>
                    <td><?= $applied > 0 ? '<span class="badge badge-info">'.$applied.' '.'ens. / أستاذ'.'</span>' : '<span class="text-muted">0</span>' ?></td>
                    <td style="white-space:nowrap">
                        <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm btn-light" title="Éditer / تعديل"><i class="fas fa-edit"></i></a>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-warning" title="<?= $r['is_active']?'Désactiver / تعطيل':'Activer / تفعيل' ?>">
                                <i class="fas fa-<?= $r['is_active']?'eye-slash':'eye' ?>"></i>
                            </button>
                        </form>
                        <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm btn-danger"
                           data-confirm="<?= $applied>0 ? 'القانون مطبّق على '.$applied.' أستاذ — عطّله بدل حذفه' : 'حذف القانون نهائياً؟' ?>"
                           title="Supprimer / حذف"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
