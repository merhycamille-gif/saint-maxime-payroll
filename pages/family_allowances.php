<?php
/**
 * 👨‍👩‍👧📋 ملف التعويض العائلي الجماعي / Allocations familiales groupées (2026-09-24)
 * «بدل ما فوت على كل موظف: ملف فيه أسماء الموظفين وقدام كل موظف قديش تعويض الأولاد وقديش تعويض الزوجة ومن تاريخ لتاريخ
 *  متل الموجود بملفه — بحدّد أي فئة وأي مدرسة — وبس أحطّهم وأكبس طبّق يروح كل تعويض على ملف كل موظف»
 * الحفظ = نفس حفظ ملف الموظف حرفياً (familyAllowanceBulkApply ← applyFamilyAllowanceDates + recalcEmployeeYear) — لا قانون جديد ولا حساب جديد.
 * المتغيّر فقط يُحفَظ؛ المتعاقد لا يستحقّ (قانون المعلمين) فخاناته مقفولة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
requireCsrf();
if (!canEdit()) { header('Location: ' . BASE_URL . 'index.php'); exit; }
ensureFamilyAllowanceDateColumns();

$currentPage = 'family_allowances';
$pageTitle = 'Allocations familiales / التعويض العائلي';
$db = getDB();
@set_time_limit(0);

// ===== النطاق: المدرسة (المدير العام: مدرسة أو كل المدارس) + الفئات + السنة + بحث + عرض =====
$schParam = (string)($_GET['sch'] ?? $_POST['sch'] ?? '');
$scopeAll = isSuperAdmin() && ($schParam === 'all');
$schoolId = $scopeAll ? 0 : ($schParam !== '' ? (int)$schParam : (int)currentSchoolId());
if (!isSuperAdmin()) { $scopeAll = false; $schoolId = (int)currentSchoolId(); }
$hasScope = $scopeAll || $schoolId > 0;
$validCats = ['titulaire' => 'enseignant_titulaire', 'contractuel' => 'enseignant_contractuel', 'employe' => 'employe'];
$catLbl = ['titulaire' => 'الملاك', 'contractuel' => 'المتعاقدين', 'employe' => 'الموظفين'];
$rawCats = $_POST['cat'] ?? $_GET['cat'] ?? null;
$categories = array_values(array_intersect(is_array($rawCats) ? $rawCats : ($rawCats !== null ? [$rawCats] : []), array_keys($validCats)));
// ✅ (2026-09-24 «الفئة اللي حاطط عليها تشك مارك بس هي تبيّن، وإذا ما حطّيت على أي فئة ما لازم يبيّنوا موظفينها» ثم «اتفقنا نغيّر تشك مارك»):
//    حتى أوّل فتحة بلا أي تشك مارك — لا أحد يبيّن حتى يشيّك فئة (أو أكثر)؛ المشيّكة فقط تبيّن
$schoolYear = (string)($_GET['sy'] ?? $_POST['sy'] ?? (activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear()));
if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear)) $schoolYear = currentSchoolYear();
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$show = in_array($_GET['show'] ?? $_POST['show'] ?? 'all', ['all', 'with', 'without'], true) ? ($_GET['show'] ?? $_POST['show'] ?? 'all') : 'all';

// قيد النطاق بصيغة SQL (alias e) — المصدر الواحد للعرض والحفظ معاً
$scopeSql = ''; $scopeParams = [];
if (!$scopeAll) { $scopeSql .= ' AND e.school_id = ?'; $scopeParams[] = $schoolId; }
if (!$categories) $scopeSql .= " AND 1=0"; // لا فئة مشيّكة = لا أحد
elseif (count($categories) < 3) $scopeSql .= " AND e.employee_type IN (" . implode(',', array_map(fn($c) => "'" . $validCats[$c] . "'", $categories)) . ")";
[$yf, $yp] = yearEmploymentFilter($schoolYear, 'e.'); // موظفو السنة (راتب أو دخول ضمنها؛ التارك من الكل قبلها لا يظهر)
$scopeSql .= $yf; $scopeParams = array_merge($scopeParams, $yp);

$backQ = 'sch=' . ($scopeAll ? 'all' : $schoolId) . '&sy=' . urlencode($schoolYear) . '&show=' . $show . '&cat_set=1' . ($q !== '' ? '&q=' . urlencode($q) : '');
foreach ($categories as $c) $backQ .= '&cat[]=' . urlencode($c);

// ===== طبّق: المتغيّر فقط يُحفَظ كما يحفظه ملف الموظف =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasScope && ($_POST['action'] ?? '') === 'apply') {
    if (!$scopeAll && $schoolId > 0 && isAllSchools()) { $_SESSION['active_schools'] = [$schoolId]; unset($_SESSION['report_schools']); }
    $lkSchools = $scopeAll ? array_map(fn($sc) => (int)$sc['id'], allSchools()) : [(int)$schoolId];
    $lkHit = array_values(array_filter($lkSchools, fn($sid) => isSchoolYearLocked($sid, $schoolYear)));
    if ($lkHit) { $_SESSION['flash_error'] = yearLockedMsg($lkHit[0], $schoolYear); }
    else {
        $rows = is_array($_POST['fa'] ?? null) ? $_POST['fa'] : [];
        $res = familyAllowanceBulkApply($db, $rows, $schoolYear, $scopeSql, $scopeParams, (string)($_SESSION['username'] ?? ''));
        if ($res['changed'] > 0) {
            $names = $res['names']; $more = count($names) > 8 ? ' … (+' . (count($names) - 8) . ')' : '';
            $_SESSION['flash_success'] = '✅ طُبّق التعويض العائلي على ملف ' . $res['changed'] . ' موظف وأُعيد حساب رواتب ' . $res['recalc'] . ' للسنة ' . $schoolYear
                . ' — ' . implode('، ', array_slice($names, 0, 8)) . $more . ($res['skipped'] ? ' (تُخطّي ' . $res['skipped'] . ': متعاقد أو خارج النطاق)' : '');
        } else $_SESSION['flash_error'] = 'ما في أي تغيير — كل القيم مطابقة لملفات الموظفين.' . ($res['skipped'] ? ' (' . $res['skipped'] . ' متعاقد لا يستحقّ)' : '');
    }
    header('Location: ' . BASE_URL . 'pages/family_allowances.php?' . $backQ);
    exit;
}

include __DIR__ . '/../includes/header.php';

// ===== الصفوف =====
$rows = [];
if ($hasScope) {
    $sql = "SELECT e.*, s.name_ar AS school_ar, s.name_fr AS school_fr FROM employees e LEFT JOIN schools s ON s.id = e.school_id WHERE e.is_deleted = 0" . $scopeSql;
    $params = $scopeParams;
    if ($q !== '') {
        $sql .= " AND (CONCAT_WS(' ', e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr, e.father_name_ar) LIKE ? OR CONCAT_WS(' ', e.last_name_ar, e.first_name_ar) LIKE ?)";
        $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%';
    }
    if ($show === 'with') $sql .= " AND (COALESCE(e.family_allowance_spouse_lbp,0) > 0 OR COALESCE(e.family_allowance_children_lbp,0) > 0)";
    if ($show === 'without') $sql .= " AND COALESCE(e.family_allowance_spouse_lbp,0) = 0 AND COALESCE(e.family_allowance_children_lbp,0) = 0";
    $sql .= " ORDER BY e.school_id, FIELD(e.employee_type,'enseignant_titulaire','employe','enseignant_contractuel'),
              COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr), COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)";
    $st = $db->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
// الشهر المرجعي لعمود «الساري»: هذا الشهر إن كان ضمن السنة المختارة وإلا أوّل شهر فيها (تشرين الأول)
[$syY1] = schoolYearToYears($schoolYear);
$refM = (int)date('n'); $refY = (int)date('Y');
if (schoolYearOfDate(date('Y-m-01')) !== $schoolYear) { $refM = 10; $refY = (int)$syY1; }
$typeLbl = ['enseignant_titulaire' => 'ملاك', 'enseignant_contractuel' => 'متعاقد', 'employe' => 'موظف'];
$typeCls = ['enseignant_titulaire' => 'fa-t', 'enseignant_contractuel' => 'fa-c', 'employe' => 'fa-e'];
$nWith = 0; $totSp = 0; $totCh = 0; $totCur = 0;
foreach ($rows as $r) { $v = familyAllowanceForMonth($r, $refM, $refY); if ($v > 0) $nWith++; $totCur += $v; if (familyAllowanceEligible($r)) { $totSp += (int)$r['family_allowance_spouse_lbp']; $totCh += (int)$r['family_allowance_children_lbp']; } }
$mo = fn($d) => ($d && (string)$d !== '0000-00-00') ? substr((string)$d, 0, 7) : '';
?>
<style>
.fa-wrap { overflow:auto; }
table.fa-table { width:100%; border-collapse:collapse; font-size:13px; }
.fa-table th, .fa-table td { border:1px solid #e2e8f0; padding:5px 6px; text-align:center; vertical-align:middle; white-space:nowrap; }
.fa-table thead th { background:#1F4E5F; color:#fff; font-weight:700; position:sticky; top:0; z-index:2; }
.fa-table thead tr.sub th { background:#2b6478; font-weight:600; font-size:12px; top:33px; }
.fa-table th.sp, .fa-table td.sp { background:#fdf2f8; } .fa-table thead th.sp { background:#9d174d; } .fa-table thead tr.sub th.sp { background:#be185d; }
.fa-table th.ch, .fa-table td.ch { background:#eff6ff; } .fa-table thead th.ch { background:#1d4ed8; } .fa-table thead tr.sub th.ch { background:#2563eb; }
.fa-table td.nm { text-align:right; font-weight:700; white-space:normal; min-width:180px; }
.fa-table td.nm a { color:#1F4E5F; text-decoration:none; } .fa-table td.nm a:hover { text-decoration:underline; }
.fa-table td.nm small { display:block; color:#64748b; font-weight:400; font-size:11.5px; }
.fa-table input.amt { width:118px; text-align:center; font-weight:700; direction:ltr; }
.fa-table input.mon { width:128px; direction:ltr; font-size:12px; }
.fa-table input { padding:4px 6px; border:1px solid #cbd5e1; border-radius:5px; background:#fff; }
.fa-table input:focus { outline:2px solid #3b82f6; border-color:#3b82f6; }
.fa-table input[readonly] { border-color:transparent; background:transparent; cursor:default; }
.fa-table input[readonly]::-webkit-calendar-picker-indicator { display:none; }
.fa-table tr.editing td { background:#fefce8 !important; }
.fa-table tr.editing input:not([disabled]) { border-color:#f59e0b; background:#fff; }
.fa-act .btn { padding:3px 8px; font-size:12px; }
.fa-table tr.changed td { background:#fef9c3 !important; }
.fa-table tr.changed td.nm::before { content:'✏️ '; }
.fa-table tr.na td { background:#f8fafc; color:#94a3b8; }
.fa-table tfoot th { font-size:13.5px; font-weight:800; position:sticky; bottom:0; }
.fa-table tr.na input { background:#f1f5f9; color:#94a3b8; }
.fa-chg-tbl td { border:none; padding:3px 4px; }
.fa-chg-tbl input.amt { width:130px; } .fa-chg-tbl input.mon { width:140px; }
.fa-badge { display:inline-block; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.fa-t { background:#dcfce7; color:#166534; } .fa-c { background:#fee2e2; color:#991b1b; } .fa-e { background:#e0e7ff; color:#3730a3; }
.fa-tot { font-weight:800; color:#1F4E5F; }
.fa-note { color:#b45309; font-size:11px; white-space:normal; max-width:150px; }
.fa-kpis { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
.fa-kpi { flex:1; min-width:150px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:8px 12px; display:flex; gap:10px; align-items:center; }
.fa-kpi .v { font-size:20px; font-weight:800; color:#1F4E5F; } .fa-kpi .l { font-size:11.5px; color:#64748b; }
.fa-bar { position:sticky; bottom:0; background:#fff; border-top:2px solid #1F4E5F; padding:10px 14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; z-index:5; box-shadow:0 -4px 12px rgba(0,0,0,.06); }
.fa-bar .cnt { font-weight:800; color:#b45309; }
.fa-law { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; font-size:12.5px; color:#475569; line-height:1.8; margin-bottom:10px; }
.fa-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:10px; }
.fa-filters .form-group { margin:0; min-width:150px; }
.fa-cats label { font-weight:normal; cursor:pointer; white-space:nowrap; margin-inline-end:10px; }
@media print { .fa-bar, .fa-filters, .no-print { display:none !important; } .fa-table input { border:none; background:transparent; } }
</style>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-people-roof"></i> Allocations familiales — fichier groupé</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">التعويض العائلي — ملف جماعي لكل الموظفين</div>
    </h3></div>
    <div class="card-body">
        <div class="fa-law">
            <div dir="ltr" style="text-align:left"><strong>Allocations familiales</strong> (épouse / enfants, du mois → au mois) — enregistrées dans le dossier de chaque employé exactement comme depuis sa fiche.</div>
            <div dir="rtl">اكتب قدّام كل موظف <strong>تعويض الزوجة</strong> و<strong>تعويض الأولاد</strong> بالليرة ومدّة كل واحد «من شهر ← إلى شهر»، ثم اكبس <strong>طبّق</strong>:
            يروح كل شي على ملف كل موظف (نفس حفظ ملفه) ويُعاد حساب رواتب السنة المختارة. «من» فارغ مع مبلغ = من أوّل شهر غير مدفوع · «إلى» فارغ = مستمرّ · لإيقافه حطّ «إلى شهر» لا تصفّر المبلغ.
            <strong>الملاك</strong> من المدرسة · <strong>الموظف</strong> خاضع لقانون العمل من الضمان · <strong>المتعاقد</strong> لا يستحقّ (قانون المعلمين) فخاناته مقفولة · لا يُقسَّم بين الزوجين.
            <br>📅 <strong>تغيّر المبلغ خلال السنة:</strong> زرّ «شهري» قدّام الموظف يفتح سطوراً: النوع + من شهر + المبلغ الجديد — يسري من ذلك الشهر ويبقى نفسه بكل الأشهر بعده حتى تغيّره بشهر آخر (0 = يوقف).</div>
        </div>

        <form method="GET" class="fa-filters no-print" id="faFilter">
            <?php if (isSuperAdmin()): ?>
            <div class="form-group">
                <label class="form-label">École / المدرسة</label>
                <select name="sch" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choisir / اختر —</option>
                    <option value="all" <?= $scopeAll ? 'selected' : '' ?>>🌐 كل المدارس / Toutes les écoles</option>
                    <?php foreach (allSchools() as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (!$scopeAll && $schoolId === (int)$s['id']) ? 'selected' : '' ?>><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?><input type="hidden" name="sch" value="<?= $schoolId ?>"><?php endif; ?>
            <div class="form-group">
                <label class="form-label">Année scolaire / السنة الدراسية</label>
                <input type="text" name="sy" class="form-control" value="<?= e($schoolYear) ?>" onchange="this.form.submit()" style="width:120px">
            </div>
            <div class="form-group fa-cats">
                <label class="form-label">Catégorie / الفئة</label>
                <input type="hidden" name="cat_set" value="1">
                <div style="padding:6px 0">
                <?php foreach ($catLbl as $k => $l): ?>
                    <label><input type="checkbox" name="cat[]" value="<?= $k ?>" <?= in_array($k, $categories, true) ? 'checked' : '' ?> onchange="document.getElementById('faFilter').submit()"> <?= $l ?></label>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Afficher / أظهر</label>
                <select name="show" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $show === 'all' ? 'selected' : '' ?>>الكل / Tous</option>
                    <option value="with" <?= $show === 'with' ? 'selected' : '' ?>>عندهم تعويض / Avec allocation</option>
                    <option value="without" <?= $show === 'without' ? 'selected' : '' ?>>بلا تعويض / Sans allocation</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Recherche / بحث بالاسم</label>
                <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="اسم…" style="width:160px">
            </div>
            <div class="form-group"><button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filtrer / فلتر</button></div>
        </form>

<?php if ($hasScope): ?>
        <div class="fa-kpis">
            <div class="fa-kpi"><span><div class="v"><?= count($rows) ?></div><div class="l">موظف ظاهر / Employés</div></span></div>
            <div class="fa-kpi"><span><div class="v"><?= $nWith ?></div><div class="l">عندهم تعويض ساري (<?= monthName($refM, 'ar') . ' ' . $refY ?>)</div></span></div>
            <div class="fa-kpi"><span><div class="v"><?= number_format($totCur) ?></div><div class="l">مجموع الساري <?= monthName($refM, 'ar') . ' ' . $refY ?> (ل.ل)</div></span></div>
            <div class="fa-kpi"><span><div class="v"><?= number_format($totSp) ?></div><div class="l">مجموع تعويض الزوجة بالملفات (ل.ل)</div></span></div>
            <div class="fa-kpi"><span><div class="v"><?= number_format($totCh) ?></div><div class="l">مجموع تعويض الأولاد بالملفات (ل.ل)</div></span></div>
        </div>

        <form method="POST" id="faForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="sch" value="<?= $scopeAll ? 'all' : (int)$schoolId ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
            <input type="hidden" name="show" value="<?= e($show) ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="cat_set" value="1">
            <?php foreach ($categories as $c): ?><input type="hidden" name="cat[]" value="<?= e($c) ?>"><?php endforeach; ?>
            <div class="fa-wrap">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th rowspan="2">#</th>
                        <th rowspan="2">Modifier / تعديل</th>
                        <th rowspan="2">Nom / الاسم</th>
                        <th rowspan="2">Catégorie / الفئة</th>
                        <?php if ($scopeAll): ?><th rowspan="2">École / المدرسة</th><?php endif; ?>
                        <th colspan="3" class="sp">Épouse / تعويض الزوجة</th>
                        <th colspan="3" class="ch">Enfants / تعويض الأولاد</th>
                        <th rowspan="2" style="min-width:120px">الساري<br><small><?= monthName($refM, 'ar') . ' ' . $refY ?></small></th>
                        <th rowspan="2">Mensuel / شهري</th>
                        <th rowspan="2">Remarque / ملاحظة</th>
                    </tr>
                    <tr class="sub">
                        <th class="sp">L.L / المبلغ</th><th class="sp">Du / من شهر</th><th class="sp">Au / إلى شهر</th>
                        <th class="ch">L.L / المبلغ</th><th class="ch">Du / من شهر</th><th class="ch">Au / إلى شهر</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="14" style="padding:24px;color:#64748b"><?= !$categories ? '☐ ما في ولا فئة مشيّكة — أشّر الملاك أو المتعاقدين أو الموظفين فوق / Cochez une catégorie' : 'لا موظفين ضمن هذا النطاق / Aucun employé' ?></td></tr>
                <?php endif; ?>
                <?php $i = 0; foreach ($rows as $r): $i++; $id = (int)$r['id']; $elig = familyAllowanceEligible($r);
                    $name = trim(($r['first_name_ar'] ?: $r['first_name_fr']) . ' ' . ($r['last_name_ar'] ?: $r['last_name_fr']));
                    $nameFr = trim(($r['first_name_fr'] ?? '') . ' ' . ($r['last_name_fr'] ?? ''));
                    $cur = familyAllowanceForMonth($r, $refM, $refY);
                    $notes = [];
                    if (!$elig) $notes[] = 'متعاقد: لا يستحقّ';
                    else {
                        if (!empty($r['spouse_works'])) $notes[] = 'الزوج يعمل: لا تعويض زوجة';
                        if (isset($r['count_spouse_allowance']) && (int)$r['count_spouse_allowance'] !== 1) $notes[] = 'احتساب الزوجة مطفأ بملفه';
                        if (isset($r['count_children_allowance']) && (int)$r['count_children_allowance'] !== 1) $notes[] = 'احتساب الأولاد مطفأ بملفه';
                    }
                    $dis = $elig ? '' : ' disabled'; $ro = $elig ? ' readonly' : ''; // ✏️ الصفّ مقفول للقراءة حتى يُكبس «تعديل» (2026-09-24 «لازم يكون قدام الموظف في إديت») ?>
                    <tr class="<?= $elig ? '' : 'na' ?>" data-id="<?= $id ?>">
                        <td><?= $i ?></td>
                        <td class="fa-act" style="white-space:nowrap">
                            <?php if ($elig): ?>
                            <button type="button" class="btn btn-sm btn-primary fa-edit-btn" title="تعديل هذا الصفّ / Modifier"><i class="fas fa-pen"></i> تعديل</button>
                            <button type="button" class="btn btn-sm btn-success fa-save-btn" style="display:none" title="حفظ هذا الموظف وحده / Enregistrer"><i class="fas fa-save"></i> حفظ</button>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $id ?>&tab=finance" target="_blank" class="btn btn-sm btn-light" title="فتح ملفه / Ouvrir la fiche"><i class="fas fa-folder-open"></i></a>
                        </td>
                        <td class="nm"><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $id ?>&tab=finance" target="_blank" title="فتح الملف / Ouvrir la fiche"><?= e($name) ?></a><?php if ($nameFr && $nameFr !== $name): ?><small dir="ltr" style="text-align:left"><?= e($nameFr) ?></small><?php endif; ?></td>
                        <td><span class="fa-badge <?= $typeCls[$r['employee_type']] ?? '' ?>"><?= $typeLbl[$r['employee_type']] ?? e($r['employee_type']) ?></span></td>
                        <?php if ($scopeAll): ?><td style="white-space:normal;font-size:12px"><?= e($r['school_ar'] ?: $r['school_fr']) ?></td><?php endif; ?>
                        <td class="sp"><input type="text" inputmode="numeric" class="amt" name="fa[<?= $id ?>][sp]" value="<?= $elig ? number_format((int)$r['family_allowance_spouse_lbp']) : '' ?>" data-orig="<?= $elig ? number_format((int)$r['family_allowance_spouse_lbp']) : '' ?>"<?= $dis . $ro ?>></td>
                        <td class="sp"><input type="month" class="mon" name="fa[<?= $id ?>][spf]" value="<?= e($mo($r['family_allowance_spouse_from'])) ?>" data-orig="<?= e($mo($r['family_allowance_spouse_from'])) ?>"<?= $dis . $ro ?>></td>
                        <td class="sp"><input type="month" class="mon" name="fa[<?= $id ?>][spt]" value="<?= e($mo($r['family_allowance_spouse_to'])) ?>" data-orig="<?= e($mo($r['family_allowance_spouse_to'])) ?>"<?= $dis . $ro ?>></td>
                        <td class="ch"><input type="text" inputmode="numeric" class="amt" name="fa[<?= $id ?>][ch]" value="<?= $elig ? number_format((int)$r['family_allowance_children_lbp']) : '' ?>" data-orig="<?= $elig ? number_format((int)$r['family_allowance_children_lbp']) : '' ?>"<?= $dis . $ro ?>></td>
                        <td class="ch"><input type="month" class="mon" name="fa[<?= $id ?>][chf]" value="<?= e($mo($r['family_allowance_children_from'])) ?>" data-orig="<?= e($mo($r['family_allowance_children_from'])) ?>"<?= $dis . $ro ?>></td>
                        <td class="ch"><input type="month" class="mon" name="fa[<?= $id ?>][cht]" value="<?= e($mo($r['family_allowance_children_to'])) ?>" data-orig="<?= e($mo($r['family_allowance_children_to'])) ?>"<?= $dis . $ro ?>></td>
                        <td class="fa-tot"><?= $cur > 0 ? number_format($cur) : '—' ?></td>
                        <?php $chgRows = $elig ? familyAllowanceChangesRows($id) : []; ?>
                        <td><?php if ($elig): ?><button type="button" class="btn btn-sm <?= $chgRows ? 'btn-warning' : 'btn-light' ?> fa-chg-btn" data-id="<?= $id ?>" title="تغييرات المبلغ خلال السنة"><i class="fas fa-calendar-days"></i> <?= $chgRows ? count($chgRows) : '' ?></button><?php else: ?>—<?php endif; ?></td>
                        <td class="fa-note"><?= e(implode(' · ', $notes)) ?></td>
                    </tr>
                    <?php if ($elig): /* 📅💱 سطر التغييرات الشهرية (مخفيّ حتى يُكبس «شهري») — نفس صيغة ملف الموظف: النوع + من شهر + المبلغ الجديد */ ?>
                    <tr class="fa-chg-row" data-for="<?= $id ?>" style="display:none">
                        <td colspan="<?= $scopeAll ? 14 : 13 ?>" style="text-align:right;background:#fffbeb;padding:8px 14px">
                            <input type="hidden" name="fa[<?= $id ?>][chg_set]" value="1" data-orig="1">
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                                <strong style="font-size:12.5px"><i class="fas fa-calendar-days"></i> <?= e($name) ?> — تغييرات المبلغ خلال السنة / Changements en cours d'année</strong>
                                <button type="button" class="btn btn-sm btn-light fa-chg-add" data-id="<?= $id ?>"><i class="fas fa-plus"></i> تغيير جديد</button>
                                <small style="color:#78350f">يسري من الشهر المختار ويبقى نفسه حتى التغيير التالي · 0 = يوقف</small>
                            </div>
                            <table class="fa-chg-tbl" style="border-collapse:collapse;font-size:12.5px">
                                <?php foreach ($chgRows as $ci => $cr): ?>
                                <tr>
                                    <td><select name="fa[<?= $id ?>][chg][<?= $ci ?>][kind]" class="form-select" style="padding:3px 6px" data-orig="<?= e($cr['kind']) ?>"><option value="children" <?= $cr['kind'] === 'children' ? 'selected' : '' ?>>الأولاد</option><option value="spouse" <?= $cr['kind'] === 'spouse' ? 'selected' : '' ?>>الزوجة</option></select></td>
                                    <td><input type="month" class="mon" name="fa[<?= $id ?>][chg][<?= $ci ?>][from]" value="<?= e($cr['from']) ?>" data-orig="<?= e($cr['from']) ?>"></td>
                                    <td><input type="text" inputmode="numeric" class="amt" name="fa[<?= $id ?>][chg][<?= $ci ?>][amt]" value="<?= number_format((int)$cr['amt']) ?>" data-orig="<?= number_format((int)$cr['amt']) ?>"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger fa-chg-del" title="حذف">✕</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
                <?php /* 🧮 «ما تنسى ديماً يكون في مجموع للكل» (2026-09-24): صفّ المجموع لكل الظاهرين — يتحدّث فوراً بالـJS وأنت تكتب */ ?>
                <tfoot>
                    <tr class="fa-total-row">
                        <th colspan="<?= $scopeAll ? 5 : 4 ?>" style="text-align:right;background:#1F4E5F;color:#fff">Total / المجموع (<?= count($rows) ?> موظف)</th>
                        <th class="sp" style="background:#9d174d;color:#fff" id="faTotSp"><?= number_format($totSp) ?></th>
                        <th class="sp" colspan="2" style="background:#9d174d;color:#fff;font-weight:400;font-size:11.5px">ل.ل / L.L</th>
                        <th class="ch" style="background:#1d4ed8;color:#fff" id="faTotCh"><?= number_format($totCh) ?></th>
                        <th class="ch" colspan="2" style="background:#1d4ed8;color:#fff;font-weight:400;font-size:11.5px">ل.ل / L.L</th>
                        <th style="background:#1F4E5F;color:#fff" id="faTotCur"><?= number_format($totCur) ?></th>
                        <th colspan="2" style="background:#1F4E5F;color:#fff;font-weight:400;font-size:11.5px">الساري <?= monthName($refM, 'ar') . ' ' . $refY ?></th>
                    </tr>
                </tfoot>
            </table>
            </div>
            <div class="fa-bar">
                <button type="submit" class="btn btn-primary" id="faApply" disabled><i class="fas fa-check-double"></i> Appliquer / طبّق على ملفات الموظفين</button>
                <span>المعدَّلون: <span class="cnt" id="faCnt">0</span> — يُحفَظ المتغيّر فقط، ويُعاد حساب رواتب <?= e($schoolYear) ?> للمعنيين.</span>
                <span style="color:#64748b;font-size:12px">النطاق: <?= $scopeAll ? 'كل المدارس' : e(schoolNameById($schoolId, 'ar')) ?> · <?= count($categories) >= 3 ? 'كل الفئات' : ($categories ? e(implode(' + ', array_map(fn($c) => $catLbl[$c], $categories))) : 'لا فئة') ?> · <?= e($schoolYear) ?></span>
            </div>
        </form>
<?php else: ?>
        <div class="alert alert-info">اختر مدرسة من الأعلى / Choisissez une école.</div>
<?php endif; ?>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('faForm'); if (!form) return;
    var btn = document.getElementById('faApply'), cnt = document.getElementById('faCnt');
    function fmt(v) { v = String(v || '').replace(/[^\d]/g, ''); return v ? Number(v).toLocaleString('en-US') : ''; }
    function subRow(tr) { var n = tr.nextElementSibling; return (n && n.classList.contains('fa-chg-row')) ? n : null; }
    function rowChanged(tr) {
        var ch = false, sub = subRow(tr);
        var scan = function (el) {
            el.querySelectorAll('input,select').forEach(function (inp) {
                if (inp.type === 'hidden') return;
                if (inp.dataset.orig === undefined) { ch = true; return; } // سطر تغيير جديد
                var cur = inp.classList.contains('amt') ? fmt(inp.value) : inp.value;
                var org = inp.classList.contains('amt') ? fmt(inp.dataset.orig) : inp.dataset.orig;
                if (cur !== org) ch = true;
            });
        };
        scan(tr);
        if (sub) { scan(sub); if (sub.dataset.dirty === '1') ch = true; }
        tr.classList.toggle('changed', ch);
        return ch;
    }
    // ✏️ «لازم يكون قدام الموظف في إديت» (2026-09-24): الصفّ مقفول للقراءة؛ «تعديل» يفتحه (وسطر «شهري» معه)، و«حفظ» يرسل هذا الموظف وحده
    function unlockRow(tr) {
        if (!tr || tr.classList.contains('editing')) return;
        tr.classList.add('editing');
        tr.querySelectorAll('input[readonly]').forEach(function (i) { i.removeAttribute('readonly'); });
        var eb = tr.querySelector('.fa-edit-btn'), sb = tr.querySelector('.fa-save-btn');
        if (eb) eb.style.display = 'none'; if (sb) sb.style.display = '';
    }
    form.addEventListener('click', function (ev) {
        var eb = ev.target.closest('.fa-edit-btn'), sb = ev.target.closest('.fa-save-btn');
        if (eb) { var tr = eb.closest('tr'); unlockRow(tr); var f = tr.querySelector('input.amt'); if (f) { f.focus(); f.select && f.select(); } return; }
        if (sb) {
            var tr2 = sb.closest('tr');
            if (!rowChanged(tr2)) { alert('ما في تغيير بهذا الصفّ / Aucun changement'); return; }
            if (!confirm('حفظ التعويض العائلي لهذا الموظف وحده وإعادة حساب رواتبه؟\nEnregistrer ce dossier ?')) return;
            form.querySelectorAll('tbody tr[data-id]').forEach(function (o) {
                if (o === tr2) return;
                o.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
                var so = subRow(o); if (so) so.querySelectorAll('input,select').forEach(function (i) { i.disabled = true; });
            });
            form.dataset.rowSubmit = '1'; form.submit(); return;
        }
    });
    // 📅💱 فتح/إغلاق سطر التغييرات الشهرية + إضافة/حذف سطر
    form.addEventListener('click', function (ev) {
        var b = ev.target.closest('.fa-chg-btn, .fa-chg-add, .fa-chg-del'); if (!b) return;
        if (b.classList.contains('fa-chg-btn')) {
            unlockRow(b.closest('tr'));
            var sub = form.querySelector('tr.fa-chg-row[data-for="' + b.dataset.id + '"]'); if (!sub) return;
            sub.style.display = sub.style.display === 'none' ? '' : 'none';
            if (sub.style.display === '' && !sub.querySelector('.fa-chg-tbl tr')) addChg(b.dataset.id);
        } else if (b.classList.contains('fa-chg-add')) { addChg(b.dataset.id); }
        else { var tr = b.closest('tr'), sub2 = b.closest('tr.fa-chg-row'); tr.remove(); if (sub2) sub2.dataset.dirty = '1'; refresh(); }
    });
    function addChg(id) {
        var sub = form.querySelector('tr.fa-chg-row[data-for="' + id + '"]'); if (!sub) return;
        var tb = sub.querySelector('.fa-chg-tbl'), n = 'n' + Date.now(), tr = document.createElement('tr');
        tr.innerHTML = '<td><select name="fa[' + id + '][chg][' + n + '][kind]" class="form-select" style="padding:3px 6px"><option value="children">الأولاد</option><option value="spouse">الزوجة</option></select></td>'
            + '<td><input type="month" class="mon" name="fa[' + id + '][chg][' + n + '][from]"></td>'
            + '<td><input type="text" inputmode="numeric" class="amt" name="fa[' + id + '][chg][' + n + '][amt]" placeholder="المبلغ الجديد"></td>'
            + '<td><button type="button" class="btn btn-sm btn-danger fa-chg-del" title="حذف">✕</button></td>';
        tb.appendChild(tr); tr.querySelector('input[type=month]').focus(); refresh();
    }
    function refresh() {
        var n = 0, tSp = 0, tCh = 0;
        form.querySelectorAll('tbody tr[data-id]').forEach(function (tr) {
            if (rowChanged(tr)) n++;
            var a = tr.querySelector('input.amt[name$="[sp]"]'), b = tr.querySelector('input.amt[name$="[ch]"]');
            if (a && !a.disabled) tSp += parseInt(fmt(a.value).replace(/,/g, ''), 10) || 0;
            if (b && !b.disabled) tCh += parseInt(fmt(b.value).replace(/,/g, ''), 10) || 0;
        });
        cnt.textContent = n; btn.disabled = n === 0;
        var eSp = document.getElementById('faTotSp'), eCh = document.getElementById('faTotCh');
        if (eSp) eSp.textContent = tSp.toLocaleString('en-US');
        if (eCh) eCh.textContent = tCh.toLocaleString('en-US');
    }
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.querySelectorAll('input.amt').forEach(function (inp) {
        inp.addEventListener('blur', function () { inp.value = fmt(inp.value); refresh(); });
        // Enter = انزل للخانة نفسها بالصفّ التالي (إدخال سريع عمودياً)
        inp.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return; ev.preventDefault();
            var tr = inp.closest('tr'), idx = Array.prototype.indexOf.call(tr.querySelectorAll('input'), inp), nx = tr.nextElementSibling;
            while (nx && nx.querySelectorAll('input').length <= idx) nx = nx.nextElementSibling;
            if (nx) { var t = nx.querySelectorAll('input')[idx]; if (t && !t.disabled) { t.focus(); t.select && t.select(); } }
        });
    });
    form.addEventListener('submit', function (ev) {
        if (form.dataset.rowSubmit === '1') return; // «حفظ» صفّ واحد — مؤكَّد ومجهَّز
        var n = parseInt(cnt.textContent, 10) || 0;
        if (n === 0) { ev.preventDefault(); return; }
        if (!confirm('طبّق التعويض العائلي على ملفات ' + n + ' موظف وأعِد حساب رواتبهم؟\nAppliquer sur ' + n + ' dossier(s) ?')) ev.preventDefault();
        // الصفوف غير المتغيّرة لا تُرسَل أصلاً (أخفّ وأسرع؛ الخادم يقارن أيضاً)
        else form.querySelectorAll('tbody tr[data-id]:not(.changed)').forEach(function (tr) {
            tr.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
            var sub = subRow(tr); if (sub) sub.querySelectorAll('input,select').forEach(function (i) { i.disabled = true; });
        });
    });
    refresh();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
