<?php
/**
 * 🏛️ Budget MEHE / موازنة وزارة التربية — الموازنة المدرسية السنوية طبق نموذج مصلحة التعليم
 * الخاص، لكل مدرسة وسنة (طلبه 2026-09-06). المنطق كله بـincludes/mehe_budget.php.
 *  - جداول الأساتذة (ملاك/متعاقدون/إداريون) والملخّص أ/ب من الرواتب تلقائياً.
 *  - الباقي (معلومات المدرسة، الغرف، المعدات، الهيكل، النفقات، الإيرادات، المنح، تعويضات
 *    الصرف، إداريون خارج الرواتب) يُعبّأ مرّة بالنموذج المقفول (تعديل → حفظ) ويُحفظ للمدرسة والسنة.
 *  - المخرجات: معاينة/طباعة PDF بالورقة الموحّدة + إكسل متعدّد الأوراق بصيغ حيّة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/mehe_budget.php';
requireLogin();

$db = getDB();
$currentPage = 'mehe_budget';
$pageTitle = 'Budget MEHE / موازنة وزارة التربية';
$hideExportToolbar = true;
$school = currentSchool();
$sy = activeSchoolYear();
if ($sy === 'all' || !preg_match('/^\d{4}-\d{4}$/', (string)$sy)) $sy = currentSchoolYear();

if (!$school) {
    $_SESSION['flash_error'] = 'موازنة الوزارة تُعدّ لمدرسة واحدة — اختر المدرسة من الأعلى أولاً. / Choisissez une seule école.';
    header('Location: ' . BASE_URL . 'pages/reports.php');
    exit;
}
$sid = (int)$school['id'];
$data = meheLoad($db, $sid, $sy);

/* ===== حفظ النموذج ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && isset($_POST['mehe_save'])) {
    requireCsrf();
    $P = $_POST;
    $txt = fn($k) => trim((string)($P[$k] ?? ''));
    $num = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
    foreach (['serial', 'center_no', 'subject', 'reference', 'director', 'parents_head', 'parents_phone', 'programs', 'levels', 'classes', 'fin_committee',
              'owner', 'shared_with', 'internet', 'other_details', 'building_owner'] as $k) $data[$k] = $txt($k);
    foreach (['playground_open', 'playground_closed', 'buildings_school', 'buildings_res', 'struct_admin_law', 'struct_workers_law', 'struct_others', 'staff_mgmt', 'staff_supervision'] as $k) $data[$k] = (int)$num($P[$k] ?? 0);
    foreach (meheLanguages() as $k => $_) $data['languages'][$k] = in_array((int)($P['lang'][$k] ?? 3), [1, 2, 3], true) ? (int)$P['lang'][$k] : 3;
    foreach (meheRoomTypes() as $rt) $data['rooms'][$rt] = (int)$num($P['room'][$rt] ?? 0);
    foreach (meheEquipmentTypes() as $et) $data['equipment'][$et] = ['admin' => (int)$num($P['eq_admin'][$et] ?? 0), 'edu' => (int)$num($P['eq_edu'][$et] ?? 0)];
    foreach (meheLevels() as $lv) $data['classes_per_level'][$lv] = (int)$num($P['cpl'][$lv] ?? 0);
    foreach (meheExpenseItems() as $k => $_) $data['expenses'][$k] = ['ll' => $num($P['exp_ll'][$k] ?? 0), 'usd' => $num($P['exp_usd'][$k] ?? 0)];
    $rows = function (string $key, array $fields, string $required) use ($P, $num) {
        $out = [];
        foreach ((array)($P[$key] ?? []) as $r) {
            if (trim((string)($r[$required] ?? '')) === '') continue;
            $o = [];
            foreach ($fields as $f => $type) $o[$f] = $type === 'n' ? $num($r[$f] ?? 0) : trim((string)($r[$f] ?? ''));
            $out[] = $o;
        }
        return $out;
    };
    $data['revenues'] = $rows('rev', ['program' => 's', 'class' => 's', 'fee_ll' => 'n', 'fee_usd' => 'n', 'students' => 'n'], 'class');
    $data['grants'] = $rows('gr', ['student' => 's', 'teacher' => 's', 'cat' => 's', 'class' => 's', 'll' => 'n', 'usd' => 'n'], 'student');
    $data['severance'] = $rows('sev', ['name' => 's', 'eos_ll' => 'n', 'eos_usd' => 'n', 'tasks_ll' => 'n', 'tasks_usd' => 'n', 'receipt_no' => 's', 'receipt_date' => 's', 'notes' => 's'], 'name');
    $data['manual_admins'] = $rows('ma', ['name' => 's', 'mode' => 's', 'start_date' => 's', 'type' => 's', 'cnss_type' => 's', 'base' => 'n', 'extra_ll' => 'n', 'tasks_ll' => 'n', 'grants_ll' => 'n', 'transport' => 'n', 'cnss' => 'n', 'months' => 'n'], 'name');
    $data['base_mode'] = (($P['base_mode'] ?? 'avg') === 'oct') ? 'oct' : 'avg';
    $data['excluded'] = array_values(array_map('intval', (array)($P['excluded'] ?? [])));
    meheSave($db, $sid, $sy, $data);
    $_SESSION['flash_success'] = 'انحفظت بيانات موازنة ' . $sy . ' لـ' . $school['name_ar'] . ' / Budget enregistré.';
    header('Location: ' . BASE_URL . 'pages/mehe_budget.php');
    exit;
}

$p = mehePayroll($db, $sid, $sy, $data);
$s = meheSummary($data, $p);
[$y1, $y2] = schoolYearToYears($sy);

/* ===== تصدير إكسل ===== */
if (($_GET['export'] ?? '') === 'xlsx') {
    $bytes = meheBuildXlsx($data, $p, $s, $school, $sy);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Budget ' . $sy . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($school['name_fr'] ?? 'school')) . ' (mehe).xlsx"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

include __DIR__ . '/../includes/header.php';
echo officialFormStyles(); // ستايلات الورقة الموحّدة + الملاءمة التلقائية للجداول الواسعة بالطباعة (--pz) كسائر التقارير

$fmt0 = fn($v) => number_format((float)$v, 0);
$fmt2 = fn($v) => number_format((float)$v, 2);
$allEmps = array_merge($p['tit'], $p['con'], array_filter($p['adm'], fn($a) => empty($a['manual'])));
// كل موظفي السنة (لخيار الاستثناء) — بمن فيهم المستثنون حالياً
$empList = $db->prepare("SELECT DISTINCT e.id, CONCAT(e.first_name_ar,' ',e.last_name_ar) nm, e.employee_type FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ? WHERE e.school_id = ? AND e.is_deleted = 0 ORDER BY e.employee_type, nm");
$empList->execute([$sy, $sid]);
$empList = $empList->fetchAll();
?>
<style>
.mehe-form .form-control{padding:4px 8px;font-size:13px}
.mehe-form details{border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;margin-bottom:10px;background:#fff}
.mehe-form summary{cursor:pointer;font-weight:800;color:#1F4E5F;padding:4px 0}
.mehe-form table.mini{width:100%;border-collapse:collapse;font-size:12.5px}
.mehe-form table.mini th,.mehe-form table.mini td{border:1px solid #e2e8f0;padding:3px 5px;text-align:center}
.mehe-form table.mini th{background:#f8fafc;font-weight:700}
.mehe-form table.mini input{width:100%;min-width:70px;border:1px solid #cbd5e1;border-radius:6px;padding:3px 6px;font-size:12.5px}
.mehe-form table.mini input[type=number]{text-align:center}
.mehe-form .grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px 14px}
.mehe-form .grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:8px 14px}
.mehe-form label.lb{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:2px}
.mehe-doc .doc-table td,.mehe-doc .doc-table th{font-size:11.5px;padding:4px 6px}
.mehe-doc .doc-table th{background:#f2f2f2}
.mehe-doc .tot td{background:#fff4d6;font-weight:800}
.mehe-doc .kv td:first-child{font-weight:700;background:#fafafa;width:40%}
.mehe-doc h4.sec{margin:14px 0 6px;color:#1F4E5F;font-size:14px}
.mehe-doc .note{color:#c00000;font-size:12px;margin:8px 0}
.mehe-doc .sig{display:flex;justify-content:space-between;margin-top:14px;font-size:12px}
.mehe-doc .mehe-cover{max-width:760px;margin:0 auto}
.mehe-doc .mehe-cover .h{font-weight:800;font-size:15px;margin:6px 0;text-align:center}
@media print{@page{size:A4 landscape;margin:8mm}.no-print{display:none !important}.mehe-doc .doc-sheet{page-break-after:always;page-break-inside:auto}.mehe-doc .doc-sheet:last-child{page-break-after:auto}.mehe-doc .report-table-wrap{overflow:visible !important}}
</style>

<div class="card no-print">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-landmark"></i> Budget MEHE — <?= e($school['name_fr'] ?? '') ?> — <?= e($sy) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">موازنة وزارة التربية والتعليم العالي (مصلحة التعليم الخاص) — <?= e($school['name_ar']) ?> — السنة الدراسية <?= e($sy) ?></div>
    </h3></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <a class="btn btn-success" href="?export=xlsx"><i class="fas fa-file-excel"></i> Excel (صيغ حيّة)</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimer / PDF</button>
        <span class="text-muted" style="font-size:12.5px">جداول الأساتذة والموظفين والملخّص (أ، ب) تُقرأ من رواتب <?= e($sy) ?> تلقائياً — المدرسة والسنة من الأعلى. الباقي تعبّيه مرّة بالنموذج تحت (تعديل ← حفظ) ويبقى محفوظاً لهذه المدرسة والسنة.</span>
    </div>
</div>

<?php if (canEdit()): ?>
<form method="POST" class="card no-print mehe-form lockedit" id="meheForm">
    <?= csrfField() ?>
    <input type="hidden" name="mehe_save" value="1">
    <div class="card-header"><h3><i class="fas fa-pen-to-square"></i> Données du budget / بيانات الموازنة (تُعبّأ مرّة لكل سنة)</h3></div>
    <div class="card-body">
        <details open>
            <summary>١) الطلب ومعلومات المدرسة</summary>
            <div class="grid3">
                <div><label class="lb">الرقم التسلسلي</label><input class="form-control" name="serial" value="<?= e($data['serial']) ?>"></div>
                <div><label class="lb">رقم المركز التربوي</label><input class="form-control" name="center_no" value="<?= e($data['center_no']) ?>"></div>
                <div><label class="lb">الموضوع</label><input class="form-control" name="subject" value="<?= e($data['subject']) ?>"></div>
                <div style="grid-column:1/-1"><label class="lb">المرجع</label><input class="form-control" name="reference" value="<?= e($data['reference']) ?>"></div>
                <div><label class="lb">اسم المدير</label><input class="form-control" name="director" value="<?= e($data['director']) ?>"></div>
                <div><label class="lb">رئيس لجنة أولياء الأمور</label><input class="form-control" name="parents_head" value="<?= e($data['parents_head']) ?>"></div>
                <div><label class="lb">هاتف رئيس لجنة أولياء الأمور</label><input class="form-control" name="parents_phone" value="<?= e($data['parents_phone']) ?>"></div>
                <div><label class="lb">البرامج</label><input class="form-control" name="programs" value="<?= e($data['programs']) ?>"></div>
                <div style="grid-column:span 2"><label class="lb">مستوى التعليم</label><input class="form-control" name="levels" value="<?= e($data['levels']) ?>"></div>
                <div style="grid-column:1/-1"><label class="lb">الصفوف (سطر لكل مرحلة)</label><textarea class="form-control" name="classes" rows="4"><?= e($data['classes']) ?></textarea></div>
                <div style="grid-column:1/-1"><label class="lb">أعضاء اللجنة المالية (اسم بكل سطر)</label><textarea class="form-control" name="fin_committee" rows="2"><?= e($data['fin_committee']) ?></textarea></div>
            </div>
        </details>
        <details>
            <summary>٢) الاستطلاع — المباني واللغات والغرف والمعدات</summary>
            <div class="grid3">
                <div><label class="lb">مساحة الملاعب المفتوحة (م²)</label><input type="number" class="form-control" name="playground_open" value="<?= (int)$data['playground_open'] ?>"></div>
                <div><label class="lb">مساحة الملاعب المغلقة (م²)</label><input type="number" class="form-control" name="playground_closed" value="<?= (int)$data['playground_closed'] ?>"></div>
                <div><label class="lb">مالك العقار</label><input class="form-control" name="owner" value="<?= e($data['owner']) ?>"></div>
                <div><label class="lb">مباني مشتركة مع</label><input class="form-control" name="shared_with" value="<?= e($data['shared_with']) ?>"></div>
                <div style="grid-column:span 2"><label class="lb">استخدام الانترنت</label><input class="form-control" name="internet" value="<?= e($data['internet']) ?>"></div>
                <div><label class="lb">تفاصيل اخرى</label><input class="form-control" name="other_details" value="<?= e($data['other_details']) ?>"></div>
                <div><label class="lb">اسم مالك المبنى</label><input class="form-control" name="building_owner" value="<?= e($data['building_owner']) ?>"></div>
                <div><label class="lb">عدد الأبنية: مدرسي / سكني</label><div style="display:flex;gap:6px"><input type="number" class="form-control" name="buildings_school" value="<?= (int)$data['buildings_school'] ?>"><input type="number" class="form-control" name="buildings_res" value="<?= (int)$data['buildings_res'] ?>"></div></div>
            </div>
            <div class="grid2" style="margin-top:10px">
                <div>
                    <label class="lb">اللغات</label>
                    <table class="mini"><tr><th>اللغة</th><th>اولي</th><th>ثانوي</th><th>غير معتمدة</th></tr>
                    <?php foreach (meheLanguages() as $k => $lb): $v = (int)($data['languages'][$k] ?? 3); ?>
                        <tr><td style="text-align:right;font-weight:700"><?= e($lb) ?></td>
                        <?php foreach ([1, 2, 3] as $o): ?><td><input type="radio" name="lang[<?= e($k) ?>]" value="<?= $o ?>" <?= $v === $o ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
                    <?php endforeach; ?></table>
                    <label class="lb" style="margin-top:10px">المعدات التقنية</label>
                    <table class="mini"><tr><th>المعدات</th><th>من قبل الإدارة</th><th>لأغراض تعليمية</th></tr>
                    <?php foreach (meheEquipmentTypes() as $et): ?>
                        <tr><td style="text-align:right;font-weight:700"><?= e($et) ?></td>
                        <td><input type="number" name="eq_admin[<?= e($et) ?>]" value="<?= (int)($data['equipment'][$et]['admin'] ?? 0) ?>"></td>
                        <td><input type="number" name="eq_edu[<?= e($et) ?>]" value="<?= (int)($data['equipment'][$et]['edu'] ?? 0) ?>"></td></tr>
                    <?php endforeach; ?></table>
                </div>
                <div>
                    <label class="lb">الغرف والقاعات</label>
                    <table class="mini"><tr><th>نوع الغرفة</th><th>عدد الغرف</th></tr>
                    <?php foreach (meheRoomTypes() as $rt): ?>
                        <tr><td style="text-align:right;font-weight:700"><?= e($rt) ?></td><td><input type="number" name="room[<?= e($rt) ?>]" value="<?= (int)($data['rooms'][$rt] ?? 0) ?>"></td></tr>
                    <?php endforeach; ?></table>
                </div>
            </div>
        </details>
        <details>
            <summary>٣) الهيكل الإداري والتعليمي</summary>
            <div class="grid3">
                <div><label class="lb">عدد الإداريين الخاضعين لقانون العمل</label><input type="number" class="form-control" name="struct_admin_law" value="<?= (int)$data['struct_admin_law'] ?>"></div>
                <div><label class="lb">عدد المستخدمين الخاضعين لقانون العمل</label><input type="number" class="form-control" name="struct_workers_law" value="<?= (int)$data['struct_workers_law'] ?>"></div>
                <div><label class="lb">عدد باقي المرتبطين بسير العمل</label><input type="number" class="form-control" name="struct_others" value="<?= (int)$data['struct_others'] ?>"></div>
                <?php foreach (meheLevels() as $lv): ?>
                <div><label class="lb">عدد الفصول — <?= e($lv) ?></label><input type="number" class="form-control" name="cpl[<?= e($lv) ?>]" value="<?= (int)($data['classes_per_level'][$lv] ?? 0) ?>"></div>
                <?php endforeach; ?>
                <div><label class="lb">القائمون بالإدارة التعليمية (مدير-مساعد-منسق-مشرف)</label><input type="number" class="form-control" name="staff_mgmt" value="<?= (int)$data['staff_mgmt'] ?>"></div>
                <div><label class="lb">القائمون بالنظارة</label><input type="number" class="form-control" name="staff_supervision" value="<?= (int)$data['staff_supervision'] ?>"></div>
                <div class="text-muted" style="font-size:12px;align-self:end">القائمون بالتدريس (<?= $s['staffTeaching'] ?>) والداخلون في الملاك (<?= $s['staffInCadre'] ?>) وغير الداخلين (<?= $s['staffOutCadre'] ?>) يُحسبون من الرواتب تلقائياً.</div>
            </div>
        </details>
        <details>
            <summary>٤) تكاليف التشغيل — النفقات (بالليرة والدولار)</summary>
            <table class="mini"><tr><th style="text-align:right">النفقة</th><th>القيمة بالليرة اللبنانية</th><th>القيمة بالدولار</th></tr>
            <?php foreach (meheExpenseItems() as $k => [$lb, $cat]): ?>
                <tr><td style="text-align:right;font-weight:700"><?= e($lb) ?></td>
                <td><input type="number" step="any" name="exp_ll[<?= e($k) ?>]" value="<?= (float)($data['expenses'][$k]['ll'] ?? 0) ?>"></td>
                <td><input type="number" step="any" name="exp_usd[<?= e($k) ?>]" value="<?= (float)($data['expenses'][$k]['usd'] ?? 0) ?>"></td></tr>
            <?php endforeach; ?></table>
        </details>
        <details>
            <summary>٥) الإيرادات — الرسوم وعدد الطلاب لكل صف</summary>
            <table class="mini" id="tblRev"><tr><th>البرنامج</th><th>الصف</th><th>الرسوم ل.ل</th><th>الرسوم د.أ</th><th>عدد الطلاب</th></tr>
            <?php $rv = array_merge((array)$data['revenues'], array_fill(0, 2, ['program' => 'منهاج لبناني', 'class' => '', 'fee_ll' => 0, 'fee_usd' => 0, 'students' => 0]));
            foreach ($rv as $i => $r): ?>
                <tr><td><input name="rev[<?= $i ?>][program]" value="<?= e($r['program']) ?>"></td><td><input name="rev[<?= $i ?>][class]" value="<?= e($r['class']) ?>" placeholder="اسم الصف"></td>
                <td><input type="number" step="any" name="rev[<?= $i ?>][fee_ll]" value="<?= (float)$r['fee_ll'] ?>"></td><td><input type="number" step="any" name="rev[<?= $i ?>][fee_usd]" value="<?= (float)$r['fee_usd'] ?>"></td>
                <td><input type="number" name="rev[<?= $i ?>][students]" value="<?= (int)$r['students'] ?>"></td></tr>
            <?php endforeach; ?></table>
            <button type="button" class="btn btn-light btn-sm" onclick="meheAddRow('tblRev')">+ صف</button>
        </details>
        <details>
            <summary>٦) الطلاب المعفيون — المنح الدراسية</summary>
            <table class="mini" id="tblGr"><tr><th>اسم الطالب</th><th>عضو هيئة التدريس</th><th>فئة المعلم</th><th>الصف</th><th>المنحة ل.ل</th><th>المنحة د.أ</th></tr>
            <?php $gr = array_merge((array)$data['grants'], array_fill(0, 2, ['student' => '', 'teacher' => '', 'cat' => 'ملاك', 'class' => '', 'll' => 0, 'usd' => 0]));
            foreach ($gr as $i => $g): ?>
                <tr><td><input name="gr[<?= $i ?>][student]" value="<?= e($g['student']) ?>"></td><td><input name="gr[<?= $i ?>][teacher]" value="<?= e($g['teacher']) ?>"></td>
                <td><select name="gr[<?= $i ?>][cat]" class="form-control"><option value="ملاك" <?= ($g['cat'] ?? '') === 'ملاك' ? 'selected' : '' ?>>ملاك</option><option value="بقية الكادر" <?= ($g['cat'] ?? '') === 'بقية الكادر' ? 'selected' : '' ?>>بقية الكادر</option></select></td>
                <td><input name="gr[<?= $i ?>][class]" value="<?= e($g['class']) ?>"></td><td><input type="number" step="any" name="gr[<?= $i ?>][ll]" value="<?= (float)$g['ll'] ?>"></td><td><input type="number" step="any" name="gr[<?= $i ?>][usd]" value="<?= (float)$g['usd'] ?>"></td></tr>
            <?php endforeach; ?></table>
            <button type="button" class="btn btn-light btn-sm" onclick="meheAddRow('tblGr')">+ طالب</button>
        </details>
        <details>
            <summary>٧) تعويضات الصرف للداخلين في الملاك</summary>
            <table class="mini" id="tblSev"><tr><th>اسم المستفيد</th><th>نهاية الخدمة ل.ل</th><th>نهاية الخدمة د.أ</th><th>المهام الإضافية ل.ل</th><th>المهام الإضافية د.أ</th><th>رقم الإيصال</th><th>تاريخ الإيصال</th><th>ملاحظات</th></tr>
            <?php $sv = array_merge((array)$data['severance'], array_fill(0, 1, ['name' => '', 'eos_ll' => 0, 'eos_usd' => 0, 'tasks_ll' => 0, 'tasks_usd' => 0, 'receipt_no' => '', 'receipt_date' => '', 'notes' => '']));
            foreach ($sv as $i => $x): ?>
                <tr><td><input name="sev[<?= $i ?>][name]" value="<?= e($x['name']) ?>"></td><td><input type="number" step="any" name="sev[<?= $i ?>][eos_ll]" value="<?= (float)$x['eos_ll'] ?>"></td><td><input type="number" step="any" name="sev[<?= $i ?>][eos_usd]" value="<?= (float)$x['eos_usd'] ?>"></td>
                <td><input type="number" step="any" name="sev[<?= $i ?>][tasks_ll]" value="<?= (float)$x['tasks_ll'] ?>"></td><td><input type="number" step="any" name="sev[<?= $i ?>][tasks_usd]" value="<?= (float)$x['tasks_usd'] ?>"></td>
                <td><input name="sev[<?= $i ?>][receipt_no]" value="<?= e($x['receipt_no']) ?>"></td><td><input name="sev[<?= $i ?>][receipt_date]" value="<?= e($x['receipt_date']) ?>"></td><td><input name="sev[<?= $i ?>][notes]" value="<?= e($x['notes']) ?>"></td></tr>
            <?php endforeach; ?></table>
            <button type="button" class="btn btn-light btn-sm" onclick="meheAddRow('tblSev')">+ سطر</button>
        </details>
        <details>
            <summary>٨) إداريون خارج الرواتب (يُضافون لجدول الموظفين الإداريين) + خيارات الرواتب</summary>
            <table class="mini" id="tblMa"><tr><th>الاسم</th><th>نمط العمل</th><th>تاريخ مباشرة العمل</th><th>نوع الموظف</th><th>نوع الضمان</th><th>أساس الراتب (شهري)</th><th>الأجور الإضافية ل.ل (شهري)</th><th>مهام إضافية ل.ل</th><th>منح مدرسية ل.ل</th><th>تعويض نقل (شهري)</th><th>مساهمة الضمان (شهري)</th><th>عدد الأشهر</th></tr>
            <?php $ma = array_merge((array)$data['manual_admins'], array_fill(0, 1, ['name' => '', 'mode' => '', 'start_date' => '', 'type' => 'عادي', 'cnss_type' => 'غير مضمون', 'base' => 0, 'extra_ll' => 0, 'tasks_ll' => 0, 'grants_ll' => 0, 'transport' => 0, 'cnss' => 0, 'months' => 12]));
            foreach ($ma as $i => $x): ?>
                <tr><td><input name="ma[<?= $i ?>][name]" value="<?= e($x['name']) ?>"></td><td><input name="ma[<?= $i ?>][mode]" value="<?= e($x['mode']) ?>" placeholder="مدير / اداري"></td><td><input name="ma[<?= $i ?>][start_date]" value="<?= e($x['start_date']) ?>" placeholder="1/10/2025"></td>
                <td><input name="ma[<?= $i ?>][type]" value="<?= e($x['type']) ?>"></td><td><input name="ma[<?= $i ?>][cnss_type]" value="<?= e($x['cnss_type']) ?>"></td>
                <?php foreach (['base', 'extra_ll', 'tasks_ll', 'grants_ll', 'transport', 'cnss', 'months'] as $f): ?><td><input type="number" step="any" name="ma[<?= $i ?>][<?= $f ?>]" value="<?= (float)($x[$f] ?? 0) ?>"></td><?php endforeach; ?></tr>
            <?php endforeach; ?></table>
            <button type="button" class="btn btn-light btn-sm" onclick="meheAddRow('tblMa')">+ إداري</button>
            <div class="grid2" style="margin-top:12px">
                <div>
                    <label class="lb">أساس الراتب الشهري بالجداول</label>
                    <select name="base_mode" class="form-control">
                        <option value="avg" <?= $data['base_mode'] === 'avg' ? 'selected' : '' ?>>معدل الأشهر على طول السنة (تعريف النموذج) — المجموع = مجموع السنة الفعلي</option>
                        <option value="oct" <?= $data['base_mode'] === 'oct' ? 'selected' : '' ?>>قيمة شهر تشرين الأول × عدد الأشهر</option>
                    </select>
                </div>
                <div>
                    <label class="lb">استثناء موظفين من الموازنة (بقرارك)</label>
                    <div style="max-height:160px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:6px;font-size:12.5px">
                        <?php foreach ($empList as $em): ?>
                            <label style="display:block"><input type="checkbox" name="excluded[]" value="<?= (int)$em['id'] ?>" <?= in_array((int)$em['id'], (array)$data['excluded'], true) ? 'checked' : '' ?>> <?= e($em['nm']) ?> <small class="text-muted">(<?= e(empCategoryTitle($em['employee_type'])) ?>)</small></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </details>
        <div style="display:flex;gap:10px;align-items:center;margin-top:6px">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
        </div>
    </div>
</form>
<script>
function meheAddRow(id){
    var t=document.getElementById(id); var rows=t.querySelectorAll('tr'); var last=rows[rows.length-1]; var clone=last.cloneNode(true);
    var n=rows.length-1;
    clone.querySelectorAll('input,select').forEach(function(el){ el.name=el.name.replace(/\[\d+\]/, '['+n+']'); if(el.type==='number'){el.value=el.name.indexOf('[months]')>0?12:0;} else if(el.tagName==='SELECT'){el.selectedIndex=0;} else if(el.name.indexOf('[program]')>0){} else {el.value='';} el.disabled=false; el.readOnly=false; });
    t.appendChild(clone);
}
</script>
<?php endif; ?>

<?php
/* ===================== المعاينة / الطباعة ===================== */
$chips = [$sy];
$optsDoc = ['comp' => false, 'no_letterhead' => true];
$staffTable = function (string $title, array $cols, array $keys, array $rows, array $months, int $firstNum, array $dec2) use ($fmt0, $fmt2) {
    ob_start(); ?>
    <h4 class="sec"><?= e($title) ?></h4>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl">
        <thead><tr><?php foreach ($cols as $cl): ?><th><?= e($cl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?><tr>
            <?php foreach ($keys as $i => $k): $col = $i + 1; ?>
                <td style="<?= $col === 1 ? 'text-align:right;font-weight:700;white-space:nowrap' : 'text-align:center' ?>"><?= $col >= $firstNum ? (in_array($k, $dec2, true) ? $fmt2($r[$k] ?? 0) : $fmt0($r[$k] ?? 0)) : e((string)($r[$k] ?? '')) ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($cols) ?>" style="text-align:center">لا صفوف / Aucun</td></tr><?php endif; ?>
        <tr style="background:#f8fafc;font-weight:700"><td style="text-align:right">عدد الاشهر</td>
            <?php foreach ($keys as $i => $k): $col = $i + 1; if ($col === 1) continue; ?><td style="text-align:center"><?= $col >= $firstNum ? (int)($months[$k] ?? 12) : '' ?></td><?php endforeach; ?></tr>
        <tr class="tot"><td style="text-align:right">المجموع</td>
            <?php $tot = []; foreach ($keys as $i => $k) { $col = $i + 1; if ($col === 1) continue; $tot[] = $col >= $firstNum ? $fmt2(array_sum(array_map(fn($r) => (float)($r[$k . '_total'] ?? 0), $rows))) : ''; } ?>
            <?php foreach ($tot as $t): ?><td style="text-align:center;white-space:nowrap"><?= $t ?></td><?php endforeach; ?></tr>
        </tbody>
    </table></div>
    <div class="note">الراتب الاساسي هو معدل الراتب الشهري على طول السنة</div>
    <div class="sig"><span>توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)</span><span>توقيع مدير المدرسة</span></div>
    <?php return ob_get_clean();
};
$kv = function (array $rows, string $fmt = 's') use ($fmt0) {
    $h = '<table class="doc-table kv" dir="rtl" style="width:100%">';
    foreach ($rows as [$lb, $v]) $h .= '<tr><td>' . e($lb) . '</td><td style="text-align:' . ($fmt === 'n' ? 'center' : 'right') . '">' . ($fmt === 'n' ? $fmt0($v) : nl2br(e((string)$v))) . '</td></tr>';
    return $h . '</table>';
};
$money3 = function (array $rows, string $lb0, bool $dec = false) use ($fmt0, $fmt2) {
    $h = '<div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>' . e($lb0) . '</th><th>المجموع بالليرة</th><th>المجموع بالدولار</th></tr></thead><tbody>';
    foreach ($rows as [$lb, $l, $u]) $h .= '<tr><td style="text-align:right;font-weight:700">' . e($lb) . '</td><td style="text-align:center">' . ($dec ? $fmt2($l) : $fmt0($l)) . '</td><td style="text-align:center">' . ($dec ? $fmt2($u) : $fmt0($u)) . '</td></tr>';
    return $h . '</tbody></table></div>';
};
?>
<div class="mehe-doc land-report">
<?= docSheetStart('Budget scolaire ' . $y1 . '/' . $y2 . ' — Ministère de l\'Éducation', 'موازنة السنة المدرسية ' . $y1 . '/' . $y2 . ' — وزارة التربية والتعليم العالي', $chips, $optsDoc) ?>
    <div class="mehe-cover">
        <div class="h">الرقم التسلسلي: <?= e($data['serial'] ?: '—') ?></div>
        <div class="h">جانب وزارة التربية والتعليم العالي</div>
        <div class="h" style="margin-top:14px">مصلحة التعليم الخاص</div>
        <?= $kv([['المستدعية', $school['name_ar']], ['رقم المركز التربوي', $data['center_no']], ['الموضوع', $data['subject']], ['المرجع', $data['reference']]]) ?>
        <p style="margin-top:14px">نودعكم ربط الموازنة المدرسية للسنة <?= $y1 ?>/<?= $y2 ?> مع المستندات المرفقة:</p>
        <p style="margin:0 20px">1 - محاضر اللجنة المالية<br>2 - بيان صندوق التعويضات<br>3 - تقرير التدقيق</p>
        <p style="margin-top:24px">واقبلوا فائق الاحترام</p>
        <div class="sig" style="margin-top:40px"><span>توقيع مدير(ة) المدرسة</span><span>ختم المدرسة</span></div>
    </div>
<?= docSheetEnd() ?>

<?= docSheetStart('Informations sur l\'école', 'معلومات المدرسة', $chips, $optsDoc) ?>
    <p><strong>اسم المدير:</strong> <?= e($data['director']) ?> &nbsp;&nbsp; <strong>رئيس لجنة أولياء الأمور:</strong> <?= e($data['parents_head']) ?> &nbsp;&nbsp; <strong>رقم هاتف رئيس لجنة أولياء الأمور:</strong> <?= e($data['parents_phone']) ?></p>
    <?= $kv([['البرامج', $data['programs']], ['مستوى التعليم', $data['levels']], ['الصفوف', $data['classes']]]) ?>
    <h4 class="sec">أعضاء اللجنة المالية</h4>
    <ol style="margin:0 20px"><?php foreach (array_filter(array_map('trim', explode("\n", (string)$data['fin_committee']))) as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ol>
    <h4 class="sec">استطلاع</h4>
    <p><strong>مساحة الملاعب المفتوحة (م²):</strong> <?= (int)$data['playground_open'] ?> &nbsp;&nbsp; <strong>مساحة الملاعب المغلقة (م²):</strong> <?= (int)$data['playground_closed'] ?></p>
    <?= $kv([['مالك العقار', $data['owner']], ['مباني مشتركة مع', $data['shared_with']], ['استخدام الانترنت', $data['internet']], ['تفاصيل اخرى', $data['other_details']]]) ?>
    <p style="margin-top:8px"><strong>اسم مالك المبنى:</strong> <?= e($data['building_owner']) ?></p>
    <?= $kv([['بناء مدرسي', $data['buildings_school']], ['بناء سكني', $data['buildings_res']]], 'n') ?>
    <h4 class="sec">اللغات</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><th>اللغة</th><th>اولي</th><th>ثانوي</th><th>غير معتمدة في المدرسة</th></tr></thead><tbody>
    <?php foreach (meheLanguages() as $k => $lb): $v = (int)($data['languages'][$k] ?? 3); ?>
        <tr><td style="text-align:right;font-weight:700"><?= e($lb) ?></td><?php foreach ([1, 2, 3] as $o): ?><td style="text-align:center"><?= $v === $o ? '◉' : '○' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table>
    <div class="grid2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:10px">
        <div><h4 class="sec">الغرف والقاعات</h4><?= $kv(array_map(fn($rt) => [$rt, (int)($data['rooms'][$rt] ?? 0)], meheRoomTypes()), 'n') ?></div>
        <div><h4 class="sec">المعدات التقنية</h4>
            <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><th>المعدات</th><th>من قبل الإدارة</th><th>لأغراض تعليمية</th></tr></thead><tbody>
            <?php foreach (meheEquipmentTypes() as $et): ?><tr><td style="text-align:right;font-weight:700"><?= e($et) ?></td><td style="text-align:center"><?= (int)($data['equipment'][$et]['admin'] ?? 0) ?></td><td style="text-align:center"><?= (int)($data['equipment'][$et]['edu'] ?? 0) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
<?= docSheetEnd() ?>

<?= docSheetStart('Corps enseignant — cadre', 'أعضاء هيئة التدريس في الملاك', array_merge($chips, [count($p['tit']) . ' أستاذاً', $p['mode'] === 'oct' ? 'أساس شهر تشرين الأول' : 'معدل الأشهر']), $optsDoc) ?>
    <?= $staffTable('', ['الاسم', 'دور الموظف', 'مؤهلات المعلم', 'مستوى التعليم', 'تاريخ الدخول الى الملاك', 'تاريخ مباشرة العمل', 'ساعات أسبوعية (ملاك)', 'ساعات أسبوعية (اضافية)', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'الأثر الرجعي', 'أجور مهمات تتجاوز نصاب العمل ل.ل', 'أجور مهمات تجاوز الـ35 ساعه', 'المكافآت', 'مهام إضافية ل.ل', 'تعويض نقل', 'تعويض عائلي', 'مساهمة الصندوق الوطني للضمان الاجتماعي', 'صندوق التعويضات'],
        ['name', 'role', 'qual', 'level', 'cadre_date', 'start_date', 'h_cadre', 'h_extra', 'base', 'extra_ll', 'extra_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'transport', 'family', 'cnss', 'fund'],
        $p['tit'], $p['tit_months'], 9, ['missions_ll', 'missions35', 'cnss', 'fund']) ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Corps enseignant — contractuels', 'أعضاء هيئة التدريس المتعاقدين', array_merge($chips, [count($p['con']) . ' أستاذاً']), $optsDoc) ?>
    <?= $staffTable('', ['الاسم', 'دور الموظف', 'نمط العمل', 'نوع الضمان', 'مؤهلات المعلم', 'مستوى التعليم', 'تاريخ مباشرة العمل', 'ساعات أسبوعية', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'المكافآت', 'مهام إضافية ل.ل', 'تعويض نقل', 'مساهمة الصندوق الوطني للضمان الاجتماعي'],
        ['name', 'role', 'mode', 'cnss_type', 'qual', 'level', 'start_date', 'h_cadre', 'base', 'extra_ll', 'extra_usd', 'bonus', 'tasks_ll', 'transport', 'cnss'],
        $p['con'], $p['con_months'], 9, ['cnss']) ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Personnel administratif', 'الموظفون الإداريون', array_merge($chips, [count($p['adm']) . ' موظفاً']), $optsDoc) ?>
    <?= $staffTable('', ['الاسم', 'نمط العمل', 'تاريخ مباشرة العمل', 'نوع الموظف الاداري', 'نوع الضمان', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'مهام إضافية ل.ل', 'منح مدرسية ل.ل', 'تعويض نقل', 'مساهمة الصندوق الوطني للضمان الاجتماعي'],
        ['name', 'admin_mode', 'start_date', 'admin_type', 'cnss_type', 'base', 'extra_ll', 'extra_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss'],
        $p['adm'], $p['adm_months'], 6, ['cnss']) ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Structure administrative et pédagogique', 'الهيكل الإداري والتعليمي', $chips, $optsDoc) ?>
    <h4 class="sec">الهيكل الإداري والتعليمي</h4>
    <?= $kv([['عدد الإداريين الخاضعين لقانون العمل', $data['struct_admin_law']], ['عدد المستخدمين الخاضعين لقانون العمل', $data['struct_workers_law']], ['عدد باقي المرتبطين بسير العمل', $data['struct_others']]], 'n') ?>
    <h4 class="sec">الهيكل التعليمي</h4>
    <?= $kv(array_merge(array_map(fn($lv) => [$lv, (int)($data['classes_per_level'][$lv] ?? 0)], meheLevels()), [['إجمالي عدد الفصول', $s['classesTotal']]]), 'n') ?>
    <h4 class="sec">هيكل الموظفين</h4>
    <?= $kv([['عدد القائمين بالإدارة التعليمية (مدير-مساعد-منسق- مشرف)', $data['staff_mgmt']], ['عدد القائمين بالنظارة', $data['staff_supervision']], ['عدد القائمين بالتدريس', $s['staffTeaching']], ['عدد الداخلين في الملاك', $s['staffInCadre']], ['عدد غير الداخلين في الملاك', $s['staffOutCadre']], ['إجمالي عدد الموظفين', $s['staffTotal']]], 'n') ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Élèves exemptés — bourses', 'قائمة الطلاب المعفيين', $chips, $optsDoc) ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>اسم الطالب</th><th>عضو هيئة التدريس</th><th>فئة المعلم</th><th>الصف</th><th>المنحة المقدمة ل.ل</th><th>المنحة المقدمة د.أ</th></tr></thead><tbody>
    <?php $gl = array_filter((array)$data['grants'], fn($g) => trim((string)($g['student'] ?? '')) !== ''); foreach ($gl as $g): ?>
        <tr><td style="text-align:right;font-weight:700"><?= e($g['student']) ?></td><td style="text-align:right"><?= e($g['teacher'] ?? '') ?></td><td style="text-align:center"><?= e($g['cat'] ?? '') ?></td><td style="text-align:right"><?= e($g['class'] ?? '') ?></td><td style="text-align:center"><?= $fmt0($g['ll'] ?? 0) ?></td><td style="text-align:center"><?= $fmt0($g['usd'] ?? 0) ?></td></tr>
    <?php endforeach; if (!$gl): ?><tr><td colspan="6" style="text-align:center">لا طلاب معفيين / Aucun</td></tr><?php endif; ?>
    </tbody></table></div>
    <h4 class="sec">المنح الدراسية للمعلمين داخل الملاك</h4>
    <?= $kv([['العدد', $s['grTitN']], ['إجمالي المنحة ل.ل', $s['grTitLL']], ['إجمالي المنحة د.أ', $s['grTitUSD']]], 'n') ?>
    <h4 class="sec">المنح الدراسية لبقية الكادر</h4>
    <?= $kv([['العدد', $s['grOthN']], ['إجمالي المنحة ل.ل', $s['grOthLL']], ['إجمالي المنحة د.أ', $s['grOthUSD']]], 'n') ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Indemnités de licenciement — cadre', 'تعويضات الصرف للداخلين في الملاك', $chips, $optsDoc) ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>اسم المستفيد</th><th>تعويضات نهاية الخدمة ل.ل</th><th>تعويضات نهاية الخدمة د.أ</th><th>تعويضات المهام الإضافية ل.ل</th><th>تعويضات المهام الإضافية د.أ</th><th>رقم إيصال الدفع</th><th>تاريخ الإيصال</th><th>ملاحظات</th></tr></thead><tbody>
    <?php $sl = array_filter((array)$data['severance'], fn($x) => trim((string)($x['name'] ?? '')) !== ''); foreach ($sl as $x): ?>
        <tr><td style="text-align:right;font-weight:700"><?= e($x['name']) ?></td><td style="text-align:center"><?= $fmt0($x['eos_ll'] ?? 0) ?></td><td style="text-align:center"><?= $fmt0($x['eos_usd'] ?? 0) ?></td><td style="text-align:center"><?= $fmt0($x['tasks_ll'] ?? 0) ?></td><td style="text-align:center"><?= $fmt0($x['tasks_usd'] ?? 0) ?></td><td style="text-align:center"><?= e($x['receipt_no'] ?? '') ?></td><td style="text-align:center"><?= e($x['receipt_date'] ?? '') ?></td><td style="text-align:right"><?= e($x['notes'] ?? '') ?></td></tr>
    <?php endforeach; if (!$sl): ?><tr><td colspan="8" style="text-align:center">—</td></tr><?php endif; ?>
    </tbody></table></div>
    <p><strong>المجموع بالليرة:</strong> <?= $fmt0($s['sevLL']) ?> &nbsp;&nbsp; <strong>المجموع بالدولار:</strong> <?= $fmt0($s['sevUSD']) ?></p>
<?= docSheetEnd() ?>

<?= docSheetStart('Coûts de fonctionnement — dépenses', 'تكاليف التشغيل — النفقات', $chips, $optsDoc) ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>النفقة</th><th>القيمة بالليرة اللبنانية</th><th>القيمة بالدولار</th></tr></thead><tbody>
    <?php foreach (meheExpenseItems() as $k => [$lb, $cat]): ?><tr><td style="text-align:right;font-weight:700"><?= e($lb) ?></td><td style="text-align:center"><?= $fmt0($data['expenses'][$k]['ll'] ?? 0) ?></td><td style="text-align:center"><?= $fmt0($data['expenses'][$k]['usd'] ?? 0) ?></td></tr><?php endforeach; ?>
    <tr class="tot"><td style="text-align:right">مجموع</td><td style="text-align:center"><?= $fmt0($s['expTotalLL']) ?></td><td style="text-align:center"><?= $fmt0($s['expTotalUSD']) ?></td></tr>
    </tbody></table></div>
<?= docSheetEnd() ?>

<?= docSheetStart('Recettes', 'الإيرادات', $chips, $optsDoc) ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>البرنامج</th><th>الصف</th><th>الرسوم الدراسيه ل.ل</th><th>الرسوم الدراسيه د.أ</th><th>عدد الطلاب</th><th>المجموع للصف</th></tr></thead><tbody>
    <?php foreach ($s['revRows'] as $rv): ?><tr><td style="text-align:right;font-weight:700"><?= e($rv['program']) ?></td><td style="text-align:right"><?= e($rv['class']) ?></td><td style="text-align:center"><?= $fmt0($rv['fee_ll']) ?></td><td style="text-align:center"><?= $fmt0($rv['fee_usd']) ?></td><td style="text-align:center"><?= (int)$rv['students'] ?></td><td style="text-align:center;white-space:nowrap">LL: <?= $fmt0($rv['tot_ll']) ?><br>USD: <?= $fmt0($rv['tot_usd']) ?></td></tr><?php endforeach; ?>
    <?php if (!$s['revRows']): ?><tr><td colspan="6" style="text-align:center">لا صفوف — عبّئ الرسوم وعدد الطلاب بالنموذج</td></tr><?php endif; ?>
    <tr class="tot"><td colspan="4" style="text-align:right">المجموع</td><td style="text-align:center"><?= (int)$s['students'] ?></td><td style="text-align:center;white-space:nowrap">LL: <?= $fmt0($s['revLL']) ?><br>USD: <?= $fmt0($s['revUSD']) ?></td></tr>
    </tbody></table></div>
    <?= $kv([['عدد الطلاب الكلي', $s['students']], ['عدد طلاب المنح للمدرسين داخل الملاك', $s['grTitN']], ['إجمالي الايرادات بعد حسم المنح الدراسية لأبناء المعلمين الملاك', 'LL:' . $fmt0($s['revAfterLL']) . ' USD:' . $fmt0($s['revAfterUSD'])], ['متوسط الرسوم الدراسية', 'LL:' . $fmt2($s['avgFeeLL']) . ' USD:' . $fmt2($s['avgFeeUSD'])]]) ?>
    <div class="sig"><span>توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)</span><span>توقيع مدير المدرسة</span></div>
<?= docSheetEnd() ?>

<?= docSheetStart('Résumé du budget', 'ملخص الموازنة', $chips, $optsDoc) ?>
    <h4 class="sec">النفقات من الفئة أ</h4><?= $money3($s['A'], 'اسم النفقة') ?>
    <h4 class="sec">النفقات من الفئة ب</h4><?= $money3($s['B'], 'اسم النفقة', true) ?>
    <h4 class="sec">النفقات من الفئة ج</h4><?= $money3($s['C'], 'اسم النفقة') ?>
    <h4 class="sec">النفقات من الفئة د</h4><?= $money3($s['D'], 'اسم النفقة', true) ?>
    <h4 class="sec">ملخص الميزانية</h4>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>المعيار</th><th>المجموع بالليرة</th><th>المجموع بالدولار</th><th>المجموع الكلي</th><th>ملاحظات</th></tr></thead><tbody>
        <tr><td style="text-align:right;font-weight:700">مجموع البندين 'أ' و 'ب'</td><td style="text-align:center"><?= $fmt2($s['abL']) ?></td><td style="text-align:center"><?= $fmt2($s['abU']) ?></td><td style="text-align:center"><?= $fmt2($s['abL'] + $s['abU']) ?></td><td></td></tr>
        <tr><td style="text-align:right;font-weight:700">مجموع البنود 'أ' و 'ب' و 'ج'</td><td style="text-align:center"><?= $fmt2($s['abcL']) ?></td><td style="text-align:center"><?= $fmt2($s['abcU']) ?></td><td style="text-align:center"><?= $fmt2($s['abcL'] + $s['abcU']) ?></td><td></td></tr>
        <tr><td style="text-align:right;font-weight:700">ما يمثله مجموع البندين 'أ' و 'ب' من مجموع البنود 'أ' و 'ب' و 'ج'</td><td style="text-align:center">-</td><td style="text-align:center">-</td><td style="text-align:center"><?= $fmt2($s['pctAB']) ?> %</td><td></td></tr>
        <tr><td style="text-align:right;font-weight:700">ما يمثله مجموع البند 'ج' من مجموع البنود 'أ' و 'ب' و 'ج'</td><td style="text-align:center">-</td><td style="text-align:center">-</td><td style="text-align:center"><?= $fmt2($s['pctC']) ?> %</td><td style="text-align:center;color:<?= $s['pctC'] <= 35 ? '#2e7d32' : '#c00000' ?>"><?= $s['pctC'] <= 35 ? 'امتثال كامل' : 'تجاوز 35%' ?></td></tr>
        <tr><td style="text-align:right;font-weight:700">إجمالي النفقات (مجموع البنود 'أ' و 'ب' و 'ج' و 'د')</td><td style="text-align:center"><?= $fmt2($s['allL']) ?></td><td style="text-align:center"><?= $fmt2($s['allU']) ?></td><td style="text-align:center"><?= $fmt2($s['allL'] + $s['allU']) ?></td><td></td></tr>
        <tr><td style="text-align:right;font-weight:700">إجمالي الإيرادات</td><td style="text-align:center"><?= $fmt2($s['revAfterLL']) ?></td><td style="text-align:center"><?= $fmt2($s['revAfterUSD']) ?></td><td style="text-align:center"><?= $fmt2($s['revAfterLL'] + $s['revAfterUSD']) ?></td><td></td></tr>
        <tr><td style="text-align:right;font-weight:700">الفرق بين النفقات والايرادات</td><td style="text-align:center"><?= $fmt2($s['diffL']) ?></td><td style="text-align:center"><?= $fmt2($s['diffU']) ?></td><td style="text-align:center"><?= $fmt2($s['diffL'] + $s['diffU']) ?></td><td style="text-align:center;color:<?= abs($s['diffL'] + $s['diffU']) < 1 ? '#2e7d32' : '#c00000' ?>"><?= abs($s['diffL'] + $s['diffU']) < 1 ? 'امتثال كامل' : 'غير متوازنة' ?></td></tr>
        <tr><td style="text-align:right;font-weight:700">متوسط القسط المدرسي الواجب</td><td style="text-align:center"><?= $fmt2($s['avgDueL']) ?></td><td style="text-align:center"><?= $fmt2($s['avgDueU']) ?></td><td style="text-align:center"><?= $fmt2($s['avgDueL'] + $s['avgDueU']) ?></td><td></td></tr>
    </tbody></table></div>
    <div class="sig"><span>توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)</span><span>توقيع مدير المدرسة</span></div>
<?= docSheetEnd() ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
