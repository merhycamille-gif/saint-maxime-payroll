<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();

$currentPage = 'tax_brackets';
$pageTitle = 'Tranches d\'impôt / الشطور الضريبية';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';

// رقم خالٍ من الفواصل/المسافات → null (للحد الأعلى غير المحدود)
function tbNum($v) {
    $v = trim(str_replace([',', ' '], '', (string)$v));
    return ($v === '') ? null : (int)$v;
}
// اسم الشطر حسب ترتيبه (الأخير = ما زاد)
function bracketName($i, $isLast) {
    if ($isLast) return 'ما زاد';
    $ord = ['الأول','الثاني','الثالث','الرابع','الخامس','السادس','السابع','الثامن','التاسع','العاشر','الحادي عشر','الثاني عشر'];
    return 'الشطر ' . ($ord[$i] ?? ('رقم ' . ($i + 1)));
}

// إعادة الحساب التلقائي يستعمل recalcSalariesInRange المشتركة (محميّة: لا تمسّ المنقولين بلا إعداد)
function tbRecalcRange($db, $fromDate, $toDate = null) {
    return recalcSalariesInRange($db, $fromDate, $toDate);
}

$statuses = ['celibataire','marie_sans_enfants','marie_1_enfant','marie_2_enfants','marie_3_enfants','marie_4_enfants','marie_5_enfants'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        // ===== حفظ مجموعة شطور كاملة لتاريخ سريان واحد (إدخال شهري، يُخزَّن شهري + سنوي) =====
        if ($action === 'save_set') {
            $from = $_POST['effective_from'] ?: null;
            $to   = $_POST['effective_to'] ?: null;
            $origFrom = $_POST['orig_from'] ?: null; // عند التعديل: التاريخ الأصلي للمجموعة
            if (!$from) throw new Exception('تاريخ السريان (من) مطلوب');

            $rates = $_POST['rate'] ?? [];
            $froms = $_POST['monthly_from'] ?? [];
            $tos   = $_POST['monthly_to'] ?? [];
            $tiers = [];
            for ($i = 0; $i < count($rates); $i++) {
                $r = trim((string)($rates[$i] ?? ''));
                if ($r === '') continue;
                $mf = tbNum($froms[$i] ?? '0') ?? 0;
                $mt = tbNum($tos[$i] ?? '');     // null = غير محدود (الشطر الأخير)
                $tiers[] = ['rate' => (float)$r, 'from' => $mf, 'to' => $mt];
            }
            if (empty($tiers)) throw new Exception('أدخل شطراً واحداً على الأقل بنسبة صحيحة');

            $db->beginTransaction();
            $delDate = $origFrom ?: $from;
            $db->prepare("DELETE FROM tax_brackets WHERE effective_from = ?")->execute([$delDate]);

            $ins = $db->prepare("INSERT INTO tax_brackets
                (bracket_number, bracket_name, rate_percent, annual_from, annual_to, monthly_from, monthly_to, effective_from, effective_to)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $cnt = count($tiers);
            foreach ($tiers as $idx => $t) {
                $isLast = ($idx === $cnt - 1);
                $aFrom = $t['from'] * 12;
                $aTo   = $t['to'] !== null ? $t['to'] * 12 : null;
                $ins->execute([$idx + 1, bracketName($idx, $isLast), $t['rate'],
                               $aFrom, $aTo, $t['from'], $t['to'], $from, $to]);
            }
            $db->commit();
            // إعادة حساب تلقائية للأشهر المتأثّرة (من تاريخ السريان إلى نهايته أو حتى الآن)
            $nRec = tbRecalcRange($db, $from, $to);
            $_SESSION['flash_success'] = "تم حفظ مجموعة الشطور ($cnt شطر) السارية من $from، وأُعيد حساب ضريبة $nRec راتب تلقائياً / Enregistré + $nRec recalculés";
        }

        // ===== حفظ تنزيل عائلي =====
        elseif ($action === 'save_deduction') {
            $st = $_POST['social_status'] ?? '';
            if (!in_array($st, $statuses, true)) throw new Exception('وضع عائلي غير صالح');
            $amt = tbNum($_POST['annual_deduction'] ?? '');
            $from = $_POST['effective_from'] ?: null;
            if ($amt === null) throw new Exception('قيمة التنزيل مطلوبة');
            if (!$from) throw new Exception('تاريخ السريان مطلوب');
            $id = (int)($_POST['ded_id'] ?? 0);
            if ($id > 0) {
                $db->prepare("UPDATE family_tax_deductions SET social_status=?, social_status_ar=?, social_status_fr=?, annual_deduction=?, effective_from=? WHERE id=?")
                   ->execute([$st, socialStatusLabel($st,'ar'), socialStatusLabel($st,'fr'), $amt, $from, $id]);
            } else {
                $db->prepare("INSERT INTO family_tax_deductions (social_status, social_status_ar, social_status_fr, annual_deduction, effective_from) VALUES (?,?,?,?,?)")
                   ->execute([$st, socialStatusLabel($st,'ar'), socialStatusLabel($st,'fr'), $amt, $from]);
            }
            // إعادة حساب تلقائية من تاريخ سريان التنزيل وطالع
            $nRec = tbRecalcRange($db, $from, null);
            $_SESSION['flash_success'] = "تم حفظ التنزيل العائلي، وأُعيد حساب ضريبة $nRec راتب تلقائياً / Enregistré + $nRec recalculés";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . BASE_URL . 'pages/tax_brackets.php');
    exit;
}

if (isset($_GET['delete_set'])) {
    $d = $_GET['delete_set'];
    $et = $db->prepare("SELECT effective_to FROM tax_brackets WHERE effective_from = ? LIMIT 1");
    $et->execute([$d]); $etVal = $et->fetchColumn() ?: null;
    $db->prepare("DELETE FROM tax_brackets WHERE effective_from = ?")->execute([$d]);
    $nRec = tbRecalcRange($db, $d, $etVal); // إعادة حساب المدى المتأثّر بالشطور المتبقّية
    $_SESSION['flash_success'] = "تم حذف مجموعة الشطور، وأُعيد حساب $nRec راتب تلقائياً / Supprimé";
    header('Location: ' . BASE_URL . 'pages/tax_brackets.php');
    exit;
}
if (isset($_GET['delete_ded'])) {
    $df = $db->prepare("SELECT effective_from FROM family_tax_deductions WHERE id = ?");
    $df->execute([(int)$_GET['delete_ded']]); $dfVal = $df->fetchColumn();
    $db->prepare("DELETE FROM family_tax_deductions WHERE id = ?")->execute([(int)$_GET['delete_ded']]);
    $nRec = $dfVal ? tbRecalcRange($db, $dfVal, null) : 0;
    $_SESSION['flash_success'] = "تم حذف التنزيل، وأُعيد حساب $nRec راتب تلقائياً / Supprimé";
    header('Location: ' . BASE_URL . 'pages/tax_brackets.php');
    exit;
}

// المجموعة قيد التعديل
$editSet = null; $editSetFrom = $_GET['edit_set'] ?? '';
if ($editSetFrom !== '') {
    $stmt = $db->prepare("SELECT * FROM tax_brackets WHERE effective_from = ? ORDER BY bracket_number");
    $stmt->execute([$editSetFrom]);
    $editSet = $stmt->fetchAll() ?: null;
}
$editEffTo = $editSet ? ($editSet[0]['effective_to'] ?? '') : '';

$editDed = null;
if (isset($_GET['edit_ded'])) {
    $stmt = $db->prepare("SELECT * FROM family_tax_deductions WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_ded']]);
    $editDed = $stmt->fetch() ?: null;
}

$allRows = $db->query("SELECT * FROM tax_brackets ORDER BY effective_from DESC, bracket_number ASC")->fetchAll();
$sets = [];
foreach ($allRows as $r) $sets[$r['effective_from']][] = $r;

$dedRows = $db->query("SELECT * FROM family_tax_deductions ORDER BY effective_from DESC, FIELD(social_status,'celibataire','marie_sans_enfants','marie_1_enfant','marie_2_enfants','marie_3_enfants','marie_4_enfants','marie_5_enfants')")->fetchAll();
$dedByDate = [];
foreach ($dedRows as $r) $dedByDate[$r['effective_from']][] = $r;

include __DIR__ . '/../includes/header.php';

// صفوف النموذج: قيم شهرية (من السنوي/12 لضمان التطابق) أو صف فارغ
$formTiers = [];
if ($editSet) {
    foreach ($editSet as $r) {
        $formTiers[] = [
            'from' => (int)round($r['annual_from'] / 12),
            'to'   => $r['annual_to'] !== null ? (int)round($r['annual_to'] / 12) : '',
            'rate' => $r['rate_percent'],
        ];
    }
} else {
    $formTiers[] = ['from'=>0,'to'=>'','rate'=>''];
}
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <?= $lang === 'ar'
        ? 'أدخل شطور <strong>ضريبة الرواتب والأجور (الباب الثاني)</strong> كما وردت في القانون، بالقيم <strong>الشهرية</strong>، مع تاريخ سريان كل قانون. كل ما تصدر الدولة قانوناً جديداً (مثل القانون 324/2024)، أضف مجموعة شطور جديدة بتاريخها — والبرنامج يحسب ضريبة كل شهر تلقائياً حسب الشطور السارية في تاريخه. اترك «لغاية» فارغاً في الشطر الأخير (ما زاد = غير محدود). يُحوّل البرنامج الشهري إلى سنوي (×12) لاحتساب الضريبة.'
        : 'Saisissez les tranches de l\'impôt sur les salaires (Titre 2) en valeurs <strong>mensuelles</strong>, avec la date d\'effet de chaque loi. Le calcul utilise les tranches en vigueur selon le mois. Laissez le plafond vide pour la dernière tranche.' ?>
</div>

<!-- ===== نموذج إضافة/تعديل مجموعة شطور ===== -->
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-layer-group"></i> <?= $editSet ? ('Modifier le jeu de tranches dès ' . e($editSetFrom)) : 'Nouveau jeu de tranches' ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= $editSet ? ('تعديل مجموعة الشطور السارية من ' . e($editSetFrom)) : 'إضافة مجموعة شطور جديدة' ?></div>
    </h3></div>
    <div class="card-body">
        <form method="POST"<?= $editSet ? ' class="lockedit"' : '' ?>>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_set">
            <input type="hidden" name="orig_from" value="<?= e($editSetFrom) ?>">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Du / تاريخ السريان (من) <span style="color:#b91c1c">*</span></label>
                    <input type="date" name="effective_from" class="form-control" value="<?= e($editSetFrom ?: date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Au / إلى تاريخ <small>(vide = jusqu'à nouvelle loi / فارغ = حتى صدور قانون جديد)</small></label>
                    <input type="date" name="effective_to" class="form-control" value="<?= e($editEffTo) ?>">
                </div>
            </div>

            <table class="table" id="tiersTable">
                <thead><tr>
                    <th style="width:120px">Tranche / الشطر</th>
                    <th>De (mensuel) / من (شهري ل.ل)</th>
                    <th>À (mensuel) / لغاية (شهري ل.ل)</th>
                    <th style="width:110px">Taux % / النسبة %</th>
                    <th style="width:50px"></th>
                </tr></thead>
                <tbody id="tiersBody">
                    <?php foreach ($formTiers as $i => $t): $isLast = ($i === count($formTiers)-1); ?>
                    <tr>
                        <td class="tier-num"><?= e(bracketName($i, $isLast && count($formTiers)>1)) ?></td>
                        <td><input type="number" name="monthly_from[]" class="form-control" min="0" step="1" value="<?= e($t['from']) ?>"></td>
                        <td><input type="number" name="monthly_to[]" class="form-control" min="0" step="1" value="<?= e($t['to']) ?>" placeholder="illimité / فارغ=غير محدود"></td>
                        <td><input type="number" name="rate[]" class="form-control" min="0" step="0.01" value="<?= e($t['rate']) ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeTier(this)"><i class="fas fa-times"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-light btn-sm" onclick="addTier()"><i class="fas fa-plus"></i> Ajouter / إضافة شطر</button>

            <div style="margin-top:14px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                <?php if ($editSet): ?>
                    <a href="<?= BASE_URL ?>pages/tax_brackets.php" class="btn btn-light"><i class="fas fa-times"></i> Annuler / إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ===== عرض مجموعات الشطور (متل القانون: اسم الشطر + شهري + سنوي + نسبة) ===== -->
<?php if (empty($sets)): ?>
    <div class="alert alert-warning">Aucune tranche. / لا توجد شطور مُدخلة بعد.</div>
<?php else: foreach ($sets as $date => $rows): ?>
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <?php $to = $rows[0]['effective_to'] ?? null; ?>
        <h3>
            <span dir="ltr"><i class="fas fa-percent"></i> Impôt sur salaires — dès <?= formatDate($date) ?><?= $to ? (' — au ' . formatDate($to)) : ' <span class="badge badge-success">En vigueur</span>' ?></span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">ضريبة الرواتب والأجور (الباب الثاني) — سارية من <?= formatDate($date) ?><?= $to ? (' — إلى ' . formatDate($to)) : ' <span class="badge badge-success">سارية</span>' ?></div>
        </h3>
        <div style="white-space:nowrap">
            <a href="?edit_set=<?= e($date) ?>" class="btn btn-sm btn-light"><i class="fas fa-edit"></i> Modifier / تعديل</a>
            <a href="?delete_set=<?= e($date) ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer ? / حذف كل شطور هذه المجموعة؟"><i class="fas fa-trash"></i></a>
        </div>
    </div>
    <div class="card-body">
        <table class="table">
            <thead><tr>
                <th>Tranche / الشطر</th>
                <th>Mensuel de / شهري من</th><th>Mensuel à / شهري لغاية</th>
                <th>Annuel de / سنوي من</th><th>Annuel à / سنوي لغاية</th>
                <th>Taux / النسبة</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['bracket_name'] ?: ('الشطر '.$r['bracket_number'])) ?></strong></td>
                    <td><?= formatLBP($r['monthly_from']) ?></td>
                    <td><?= $r['monthly_to'] !== null ? formatLBP($r['monthly_to']) : '<span class="badge badge-info">illimité / غير محدود</span>' ?></td>
                    <td><?= formatLBP($r['annual_from']) ?></td>
                    <td><?= $r['annual_to'] !== null ? formatLBP($r['annual_to']) : '<span class="badge badge-info">illimité / غير محدود</span>' ?></td>
                    <td><strong><?= rtrim(rtrim(number_format((float)$r['rate_percent'],2),'0'),'.') ?>%</strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; endif; ?>

<!-- ===== التنزيلات العائلية ===== -->
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-users"></i> <?= $editDed ? 'Modifier une déduction familiale' : 'Déductions familiales annuelles' ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9"><?= $editDed ? 'تعديل تنزيل عائلي' : 'التنزيلات العائلية السنوية' ?></div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:14px">
            <i class="fas fa-info-circle"></i>
            <?= $lang==='ar'
                ? 'التنزيل العائلي السنوي يُطرح من الأساس الخاضع قبل تطبيق الشطور، حسب الوضع العائلي وتاريخ سريان القانون.'
                : 'Déduction annuelle soustraite avant les tranches, selon la situation et la date.' ?>
        </div>
        <form method="POST"<?= $editDed ? ' class="lockedit"' : '' ?>>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_deduction">
            <input type="hidden" name="ded_id" value="<?= $editDed ? (int)$editDed['id'] : 0 ?>">
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">Situation / الوضع العائلي</label>
                    <select name="social_status" class="form-select" required>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= ($editDed && $editDed['social_status']===$s)?'selected':'' ?>><?= e(socialStatusLabel($s,$lang)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Déduction annuelle / التنزيل السنوي (ل.ل)</label>
                    <input type="number" name="annual_deduction" class="form-control" min="0" value="<?= e($editDed['annual_deduction'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Du / من تاريخ</label>
                    <input type="date" name="effective_from" class="form-control" value="<?= e($editDed['effective_from'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
            <?php if ($editDed): ?>
                <a href="<?= BASE_URL ?>pages/tax_brackets.php" class="btn btn-light"><i class="fas fa-times"></i> Annuler / إلغاء</a>
            <?php endif; ?>
        </form>

        <?php foreach ($dedByDate as $date => $rows): ?>
        <h4 style="margin-top:20px">
            <span dir="ltr">Dès <?= formatDate($date) ?></span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">سارية من <?= formatDate($date) ?></div>
        </h4>
        <table class="table">
            <thead><tr><th>Situation / الوضع العائلي</th><th>Déduction / التنزيل السنوي</th><th style="width:120px"></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(socialStatusLabel($r['social_status'],$lang)) ?></td>
                    <td><strong><?= formatLBP($r['annual_deduction']) ?></strong></td>
                    <td style="white-space:nowrap">
                        <a href="?edit_ded=<?= $r['id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-edit"></i></a>
                        <a href="?delete_ded=<?= $r['id'] ?>" class="btn btn-sm btn-danger" data-confirm="Supprimer ? / حذف؟"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
    </div>
</div>

<script>
var ORD = ['الأول','الثاني','الثالث','الرابع','الخامس','السادس','السابع','الثامن','التاسع','العاشر','الحادي عشر','الثاني عشر'];
function renumberTiers() {
    var rows = document.querySelectorAll('#tiersBody tr');
    rows.forEach(function (tr, i) {
        var c = tr.querySelector('.tier-num');
        if (c) c.textContent = (i === rows.length - 1 && rows.length > 1) ? 'ما زاد' : ('الشطر ' + (ORD[i] || (i+1)));
    });
}
function addTier() {
    var body = document.getElementById('tiersBody');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="tier-num"></td>'
        + '<td><input type="number" name="monthly_from[]" class="form-control" min="0" step="1" value="0"></td>'
        + '<td><input type="number" name="monthly_to[]" class="form-control" min="0" step="1" placeholder="غير محدود"></td>'
        + '<td><input type="number" name="rate[]" class="form-control" min="0" step="0.01"></td>'
        + '<td><button type="button" class="btn btn-sm btn-danger" onclick="removeTier(this)"><i class="fas fa-times"></i></button></td>';
    body.appendChild(tr);
    renumberTiers();
}
function removeTier(btn) {
    var tr = btn.closest('tr');
    if (document.querySelectorAll('#tiersBody tr').length > 1) tr.remove();
    else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    renumberTiers();
}
renumberTiers();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
