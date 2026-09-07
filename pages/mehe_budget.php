<?php
/**
 * 🏛️ Budget MEHE / موازنة وزارة التربية — الموازنة المدرسية السنوية طبق نموذج مصلحة التعليم
 * الخاص، لكل مدرسة وسنة (طلبه 2026-09-06). المنطق كله بـincludes/mehe_budget.php.
 *  - جداول الأساتذة (ملاك/متعاقدون/إداريون) والملخّص أ/ب من الرواتب تلقائياً.
 *  - الباقي (معلومات المدرسة، الغرف، المعدات، الهيكل، النفقات، الإيرادات، المنح، تعويضات
 *    الصرف، إداريون خارج الرواتب) يُعبّأ مرّة بالنموذج المقفول (تعديل → حفظ) ويُحفظ للمدرسة والسنة.
 *  - ✏️💾 «بدي قدام كل سطر من صفحات الموازنة الخيار تعديل وحفظ» (2026-09-07): كل سطر بأوراق
 *    الموازنة نفسها (المعاينة) قدّامه زرّ تعديل يفتح خاناته وزرّ حفظ يحفظه فوراً (fetch → JSON →
 *    إعادة تحميل بنفس الموضع). أسطر الموظفين المحسوبة من الرواتب تُخزَّن قيمها اليدوية بـoverrides
 *    ولها «↺ تلقائي» للرجوع؛ صفّ «عدد الاشهر» يُعدَّل لكل عمود؛ أسطر الملخّص المحسوبة (أ/ب/د) تُفرض
 *    يدوياً أو ترجع محسوبة؛ بنود النفقات تُعدَّل من أي ورقة تظهر فيها. المجاميع تبقى محسوبة دائماً.
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
$sy = activeSchoolYear();
if ($sy === 'all' || !preg_match('/^\d{4}-\d{4}$/', (string)$sy)) $sy = currentSchoolYear();
// 🏫 النطاق: مدرسة واحدة أو مجموعة أو الكل (من مبدّل المدارس بالأعلى) — «ليش ما فيّي اختار مجموعة
// مدارس مع بعضها» (2026-09-06): موازنة مجمّعة لكل النطاق، وبياناتها تُحفظ لهذه المجموعة بعينها
$ids = activeSchoolIds();
if (!$ids) $ids = allActiveSchoolIdsCached();
$ids = array_values(array_map('intval', $ids));
$schools = [];
if ($ids) { $q = $db->query("SELECT * FROM schools WHERE id IN (" . implode(',', $ids) . ") AND is_deleted = 0 ORDER BY FIELD(id," . implode(',', $ids) . ")"); $schools = $q->fetchAll(); }
if (!$schools) {
    $_SESSION['flash_error'] = 'لا مدارس بالنطاق المختار / Aucune école.';
    header('Location: ' . BASE_URL . 'pages/reports.php');
    exit;
}
$multi = count($schools) > 1;
$school = $multi
    ? ['id' => (int)$schools[0]['id'], 'name_ar' => implode(' + ', array_map(fn($x) => $x['name_ar'], $schools)), 'name_fr' => implode(' + ', array_map(fn($x) => $x['name_fr'], $schools))]
    : $schools[0];
$sid = (int)$school['id'];
$data = meheLoad($db, $ids, $sy);

/* ===== ✏️ حفظ سطر واحد من الورقة (fetch → JSON) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mehe_row'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!canEdit()) { echo json_encode(['ok' => false, 'msg' => 'قراءة فقط']); exit; }
    if (!verifyCsrf($_POST['csrf'] ?? '')) { echo json_encode(['ok' => false, 'msg' => 'رمز الأمان غير صحيح — أعد تحميل الصفحة']); exit; }
    $res = meheApplyRowEdit($data, $_POST);
    if ($res['ok']) { meheSave($db, $ids, $sy, $data); $_SESSION['flash_success'] = $res['msg']; }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

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
    $oldMa = array_values((array)$data['manual_admins']);
    $data['manual_admins'] = $rows('ma', ['name' => 's', 'school' => 's', 'mode' => 's', 'start_date' => 's', 'type' => 's', 'cnss_type' => 's', 'base' => 'n', 'extra_ll' => 'n', 'tasks_ll' => 'n', 'grants_ll' => 'n', 'transport' => 'n', 'cnss' => 'n', 'months' => 'n'], 'name');
    foreach ($data['manual_admins'] as $i => &$maRow) $maRow['extra_usd'] = (float)($oldMa[$i]['extra_usd'] ?? 0); // الإضافي بالدولار يُدخل من الورقة فقط — لا يضيع بحفظ النموذج
    unset($maRow);
    $data['base_mode'] = (($P['base_mode'] ?? 'avg') === 'oct') ? 'oct' : 'avg';
    $data['excluded'] = array_values(array_map('intval', (array)($P['excluded'] ?? [])));
    meheSave($db, $ids, $sy, $data);
    $_SESSION['flash_success'] = 'انحفظت بيانات موازنة ' . $sy . ' لـ' . $school['name_ar'] . ' / Budget enregistré.';
    header('Location: ' . BASE_URL . 'pages/mehe_budget.php');
    exit;
}

$p = mehePayroll($db, $ids, $sy, $data);
$s = meheSummary($data, $p);
[$y1, $y2] = schoolYearToYears($sy);

/* ===== تصدير إكسل ===== */
if (($_GET['export'] ?? '') === 'xlsx') {
    $bytes = meheBuildXlsx($data, $p, $s, $school, $sy);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Budget ' . $sy . ' ' . ($multi ? 'Groupe ' . count($schools) . ' ecoles' : preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($school['name_fr'] ?? 'school'))) . ' (mehe).xlsx"');
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
$empList = $db->prepare("SELECT DISTINCT e.id, e.school_id, CONCAT(e.first_name_ar,' ',e.last_name_ar) nm, e.employee_type FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ? WHERE e.school_id IN (" . implode(',', $ids) . ") AND e.is_deleted = 0 ORDER BY e.school_id, e.employee_type, nm");
$empList->execute([$sy]);
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
.mehe-doc h4.sec{margin:14px 0 6px;color:#000;font-size:14px}
.mehe-doc .doc-head .dh-fr,.mehe-doc .doc-head .dh-ar,.mehe-doc .doc-head,.mehe-doc .mehe-cover .h,.mehe-doc .doc-table th{color:#000 !important}
.mehe-doc .doc-table th{background:#f2f2f2 !important}
.mehe-form .rowctl{white-space:nowrap;flex:0 0 auto}
.mehe-form td.rowctl,.mehe-form th.rowctl{width:1%}
.mehe-form .rowctl button,.mehe-form .fld .rowctl button{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:2px 7px;font-size:12px;cursor:pointer;margin-inline-end:3px}
.mehe-form .rowctl button.sv{background:#16a34a;color:#fff;border-color:#16a34a;display:none}
.mehe-form .rowctl.editing button.sv{display:inline-block}
.mehe-form .rowctl.editing button.ed{display:none}
.mehe-form .fld{display:flex;align-items:flex-end;gap:6px;min-width:0}
.mehe-form .fld>div{flex:1;min-width:0}
.mehe-form .fld .rowctl{padding-bottom:3px;flex:0 0 auto;white-space:nowrap;overflow:visible}
.mehe-form input[readonly],.mehe-form textarea[readonly]{background:#f8fafc;color:#334155}
.mehe-form select:disabled,.mehe-form input:disabled{background:#f8fafc;color:#334155;opacity:1}
.mehe-doc .rowctl{white-space:nowrap;width:1%;text-align:center;vertical-align:middle;padding:2px 4px !important}
.mehe-doc .rowctl button{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:1px 6px;font-size:11px;cursor:pointer;margin:1px;line-height:1.5}
.mehe-doc .rowctl button.sv{background:#16a34a;color:#fff;border-color:#16a34a;display:none}
.mehe-doc .rowctl button.cx{display:none}
.mehe-doc .rowctl button.rs{color:#b45309}
.mehe-doc .rowctl.editing button.sv,.mehe-doc .rowctl.editing button.cx{display:inline-block}
.mehe-doc .rowctl.editing button.ed,.mehe-doc .rowctl.editing button.rs{display:none}
.mehe-doc span.rowctl{display:inline-flex;gap:2px;width:auto;margin-inline-end:6px;vertical-align:middle}
.mehe-doc div.mline{display:flex;align-items:center;gap:6px;margin:4px 0;font-size:12px}
.mehe-doc div.mline ol{margin:0 20px;flex:1}
.mehe-doc .mi{width:100%;min-width:60px;box-sizing:border-box;border:1px solid #16a34a;border-radius:5px;padding:2px 4px;font-size:11.5px;font-family:inherit;background:#f0fdf4}
.mehe-doc textarea.mi{min-height:64px;min-width:260px}
.mehe-doc td.ovd{background:#fffbea}
.mehe-doc .addrow{margin:6px 0;font-size:12px}
.mehe-doc tr.mtpl{display:none}
.mehe-doc .note{color:#c00000;font-size:12px;margin:8px 0}
.mehe-doc .sig{display:flex;justify-content:space-between;margin-top:14px;font-size:12px}
.mehe-doc .mehe-cover{max-width:760px;margin:0 auto}
.mehe-doc .mehe-cover .h{font-weight:800;font-size:15px;margin:6px 0;text-align:center}
@media print{@page{size:A4 landscape;margin:8mm}.mehe-doc .doc-table th{background:#f2f2f2 !important;color:#000 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.mehe-doc .doc-head .dh-fr,.mehe-doc .doc-head .dh-ar{color:#000 !important}.no-print{display:none !important}.mehe-doc .doc-sheet{page-break-after:always;page-break-inside:auto}.mehe-doc .doc-sheet:last-child{page-break-after:auto}.mehe-doc .report-table-wrap{overflow:visible !important}}
</style>

<div class="card no-print">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-landmark"></i> Budget MEHE — <?= e($school['name_fr'] ?? '') ?> — <?= e($sy) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">موازنة وزارة التربية والتعليم العالي (مصلحة التعليم الخاص) — <?= e($school['name_ar']) ?> — السنة الدراسية <?= e($sy) ?></div>
    </h3></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <a class="btn btn-success" href="?export=xlsx"><i class="fas fa-file-excel"></i> Excel (صيغ حيّة)</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimer / PDF</button>
        <span class="text-muted" style="font-size:12.5px">جداول الأساتذة والموظفين والملخّص (أ، ب) تُقرأ من رواتب <?= e($sy) ?> تلقائياً — المدرسة (أو مجموعة مدارس أو الكل) والسنة من الأعلى<?= $multi ? '؛ الآن موازنة مجمّعة لـ' . count($schools) . ' مدارس' : '' ?>. كل سطر بالأوراق تحت قدّامه «✏️ تعديل» ثم «💾 حفظ» ويبقى محفوظاً لهذه المدرسة والسنة؛ أسطر الموظفين المعدَّلة يدوياً لها «↺ تلقائي» للرجوع إلى الرواتب.</span>
    </div>
</div>

<?php if (canEdit()): ?>
<form method="POST" class="card no-print mehe-form" id="meheForm">
    <?= csrfField() ?>
    <input type="hidden" name="mehe_save" value="1">
    <details>
    <summary class="card-header" style="cursor:pointer"><h3 style="display:inline"><i class="fas fa-pen-to-square"></i> Données du budget / النموذج الكامل لبيانات الموازنة</h3> <small class="text-muted" style="font-size:12px">— اختياري: كل سطر بأوراق الموازنة تحت قدّامه «تعديل/حفظ» مباشرة؛ هنا أيضاً أساس الراتب واستثناء الموظفين</small></summary>
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
            <table class="mini" id="tblMa"><tr><th>الاسم</th><?php if ($multi): ?><th>المدرسة</th><?php endif; ?><th>نمط العمل</th><th>تاريخ مباشرة العمل</th><th>نوع الموظف</th><th>نوع الضمان</th><th>أساس الراتب (شهري)</th><th>الأجور الإضافية ل.ل (شهري)</th><th>مهام إضافية ل.ل</th><th>منح مدرسية ل.ل</th><th>تعويض نقل (شهري)</th><th>مساهمة الضمان (شهري)</th><th>عدد الأشهر</th></tr>
            <?php $ma = array_merge((array)$data['manual_admins'], array_fill(0, 1, ['name' => '', 'mode' => '', 'start_date' => '', 'type' => 'عادي', 'cnss_type' => 'غير مضمون', 'base' => 0, 'extra_ll' => 0, 'tasks_ll' => 0, 'grants_ll' => 0, 'transport' => 0, 'cnss' => 0, 'months' => 12]));
            foreach ($ma as $i => $x): ?>
                <tr><td><input name="ma[<?= $i ?>][name]" value="<?= e($x['name']) ?>"></td><?php if ($multi): ?><td><input name="ma[<?= $i ?>][school]" value="<?= e($x['school'] ?? '') ?>" placeholder="اسم المدرسة"></td><?php endif; ?><td><input name="ma[<?= $i ?>][mode]" value="<?= e($x['mode']) ?>" placeholder="مدير / اداري"></td><td><input name="ma[<?= $i ?>][start_date]" value="<?= e($x['start_date']) ?>" placeholder="1/10/2025"></td>
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
                            <label style="display:block"><input type="checkbox" name="excluded[]" value="<?= (int)$em['id'] ?>" <?= in_array((int)$em['id'], (array)$data['excluded'], true) ? 'checked' : '' ?>> <?= e($em['nm']) ?> <small class="text-muted">(<?= e(empCategoryTitle($em['employee_type'])) ?><?= $multi ? ' — ' . e(schoolNameById((int)$em['school_id'])) : '' ?>)</small></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </details>
    </div>
    </details>
</form>
<script>
/* ✏️💾 «قدام كل سطر تعديل حفظ» (2026-09-06): كل سطر بالنموذج (خانة أو صفّ جدول) مقفول، زرّ «تعديل»
   يفتح السطر وحده، و«حفظ» يحفظ فوراً (يرسل النموذج كله — القيم غير المعدَّلة تبقى كما هي). */
(function(){
    var form=document.getElementById('meheForm'); if(!form) return;
    function fields(scope){ return scope.querySelectorAll('input:not([type=hidden]):not([type=button]):not([type=submit]),select,textarea'); }
    function lock(scope,on){ fields(scope).forEach(function(el){ if(el.tagName==='SELECT'||el.type==='radio'||el.type==='checkbox'){ el.disabled=on; } else { el.readOnly=on; } }); }
    function ctl(){ var sp=document.createElement('span'); sp.className='rowctl';
        sp.innerHTML='<button type="button" class="ed" title="تعديل / Modifier">✏️ تعديل</button><button type="button" class="sv" title="حفظ / Enregistrer">💾 حفظ</button>'; return sp; }
    function wire(scope, sp){ lock(scope,true);
        sp.querySelector('.ed').onclick=function(){ lock(scope,false); sp.classList.add('editing'); var f=fields(scope)[0]; if(f) f.focus(); };
        sp.querySelector('.sv').onclick=function(){ lock(form,false); form.submit(); }; }
    // خانات الشبكات: كل خانة سطر
    form.querySelectorAll('.grid3>div,.grid2>div').forEach(function(d){
        if(d.querySelector('table.mini')||!fields(d).length) return;
        var wrap=document.createElement('div'); wrap.className='fld'; wrap.style.cssText=d.getAttribute('style')||''; d.removeAttribute('style'); d.parentNode.insertBefore(wrap,d); wrap.appendChild(d);
        var sp=ctl(); wrap.insertBefore(sp,d); wire(d,sp);
    });
    // صفوف الجداول الصغيرة: خلية أزرار بأول كل صف
    form.querySelectorAll('table.mini').forEach(function(t){
        t.querySelectorAll('tr').forEach(function(tr){
            if(!fields(tr).length){ var th=document.createElement('th'); th.className='rowctl'; tr.insertBefore(th,tr.firstChild); return; }
            var td=document.createElement('td'); td.className='rowctl'; var sp=ctl(); td.appendChild(sp); tr.insertBefore(td,tr.firstChild); wire(tr,sp);
        });
    });
    // قائمة الاستثناء (خانات تفتيش) كسطر واحد
    form.querySelectorAll('.grid2>div').forEach(function(d){ /* معالَجة أعلاه */ });
    window.meheWireRow=function(tr){ var old=tr.querySelector('td.rowctl'); if(old) old.remove(); var td=document.createElement('td'); td.className='rowctl'; var sp=ctl(); td.appendChild(sp); tr.insertBefore(td,tr.firstChild); wire(tr,sp); sp.querySelector('.ed').click(); };
    form.addEventListener('submit',function(){ lock(form,false); });
})();
function meheAddRow(id){
    var t=document.getElementById(id); var rows=t.querySelectorAll('tr'); var last=rows[rows.length-1]; var clone=last.cloneNode(true);
    var n=rows.length-1;
    clone.querySelectorAll('input,select').forEach(function(el){ el.name=el.name.replace(/\[\d+\]/, '['+n+']'); if(el.type==='number'){el.value=el.name.indexOf('[months]')>0?12:0;} else if(el.tagName==='SELECT'){el.selectedIndex=0;} else if(el.name.indexOf('[program]')>0){} else {el.value='';} el.disabled=false; el.readOnly=false; });
    t.appendChild(clone);
    if(window.meheWireRow) window.meheWireRow(clone);
}
</script>
<?php endif; ?>

<?php
/* ===================== المعاينة / الطباعة ===================== */
$chips = [$sy];
$optsDoc = ['comp' => false, 'no_letterhead' => true];
$E = canEdit(); // ✏️ أزرار التعديل تظهر للمحرّرين فقط (حسابات المدارس قراءة)
/* خلية أزرار قدّام السطر (تُخفى بالطباعة). $rs = سطر له قيم يدوية ⇒ زرّ «↺ تلقائي» */
$ctl = function (bool $rs = false, string $tag = 'td') use ($E): string {
    if (!$E) return '';
    return "<$tag class=\"rowctl no-print\"><button type=\"button\" class=\"ed\" title=\"تعديل / Modifier\">✏️ تعديل</button><button type=\"button\" class=\"sv\" title=\"حفظ / Enregistrer\">💾 حفظ</button><button type=\"button\" class=\"cx\" title=\"إلغاء\">✖</button>" . ($rs ? "<button type=\"button\" class=\"rs\" title=\"رجوع القيمة التلقائية من الرواتب\">↺ تلقائي</button>" : '') . "</$tag>";
};
$ctlTh = fn() => $E ? '<th class="rowctl no-print"></th>' : '';
/* خلية قابلة للتعديل: data-f اسم الحقل · data-t النوع (s نص، n رقم، ta نص طويل، sel اختيار) · data-v القيمة الخام */
$ed = function (string $f, $raw, string $shown, string $t = 's', string $style = '', string $opts = '', bool $ovd = false) use ($E): string {
    if (!$E) return '<td style="' . $style . '">' . $shown . '</td>';
    return '<td data-f="' . e($f) . '" data-t="' . $t . '" data-v="' . e((string)$raw) . '"' . ($opts !== '' ? ' data-o="' . e($opts) . '"' : '') . ' style="' . $style . '"' . ($ovd ? ' class="ovd"' : '') . '>' . $shown . '</td>';
};
$rowAttr = fn(string $kind, string $key, bool $new = false) => $E ? ' data-mrow="' . e($kind . '|' . $key) . '"' . ($new ? ' data-new="1"' : '') : '';
$staffTable = function (string $title, string $tk, array $cols, array $keys, array $rows, array $months, int $firstNum, array $dec2, array $data) use ($fmt0, $fmt2, $multi, $E, $ctl, $ctlTh, $ed, $rowAttr) {
    if ($multi) { $cols = array_merge([$cols[0], 'المدرسة'], array_slice($cols, 1)); $keys = array_merge([$keys[0], 'school'], array_slice($keys, 1)); $firstNum++; }
    $ovM = (array)($data['overrides']['months'][$tk] ?? []);
    $manualMap = ['admin_mode' => 'mode', 'admin_type' => 'type']; // أعمدة الإداري اليدوي بأسماء حقوله المخزّنة
    $rowHtml = function (array $r, bool $tpl = false) use ($keys, $firstNum, $dec2, $fmt0, $fmt2, $ctl, $ed, $rowAttr, $manualMap): string {
        $manual = !empty($r['manual']);
        $h = '<tr' . ($tpl ? ' class="mtpl"' : '') . ($manual ? $rowAttr('list', 'manual_admins|' . ($tpl ? 'new' : substr((string)$r['key'], 1)), $tpl) : $rowAttr('emp', (string)($r['key'] ?? ''))) . '>';
        $h .= $ctl(!$manual && !empty($r['overridden']));
        foreach ($keys as $i => $k) {
            $col = $i + 1;
            $style = $col === 1 ? 'text-align:right;font-weight:700;white-space:nowrap' : ($k === 'school' ? 'text-align:right;white-space:nowrap' : 'text-align:center');
            if ($k === 'school') { $h .= $manual ? $ed('school', (string)($r[$k] ?? ''), e((string)($r[$k] ?? '')), 's', $style) : '<td style="' . $style . '">' . e((string)($r[$k] ?? '')) . '</td>'; continue; }
            $f = $manual ? ($manualMap[$k] ?? $k) : $k;
            if ($col >= $firstNum) { $raw = (float)($r[$k] ?? 0); $h .= $ed($f, $raw == floor($raw) ? (string)(int)$raw : (string)$raw, in_array($k, $dec2, true) ? $fmt2($raw) : $fmt0($raw), 'n', $style); }
            else { $h .= $ed($f, (string)($r[$k] ?? ''), e((string)($r[$k] ?? '')), 's', $style); }
        }
        return $h . '</tr>';
    };
    ob_start(); ?>
    <?php if ($title !== ''): ?><h4 class="sec"><?= e($title) ?></h4><?php endif; ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl">
        <thead><tr><?= $ctlTh() ?><?php foreach ($cols as $cl): ?><th><?= e($cl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r) echo $rowHtml($r); ?>
        <?php if (!$rows): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="<?= count($cols) ?>" style="text-align:center">لا صفوف / Aucun</td></tr><?php endif; ?>
        <?php if ($tk === 'adm' && $E): $tplRow = ['manual' => true, 'key' => 'mnew', 'name' => '', 'school' => '', 'admin_mode' => '', 'start_date' => '', 'admin_type' => 'عادي', 'cnss_type' => 'غير مضمون']; echo $rowHtml($tplRow, true); endif; ?>
        <tr style="background:#f8fafc;font-weight:700"<?= $rowAttr('months', $tk) ?>><?= $ctl((bool)$ovM) ?><td style="text-align:right">عدد الاشهر</td>
            <?php foreach ($keys as $i => $k): $col = $i + 1; if ($col === 1) continue; ?><?= $col >= $firstNum ? $ed($k, (int)($months[$k] ?? 12), (string)(int)($months[$k] ?? 12), 'n', 'text-align:center', '', isset($ovM[$k])) : '<td></td>' ?><?php endforeach; ?></tr>
        <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">المجموع</td>
            <?php $tot = []; foreach ($keys as $i => $k) { $col = $i + 1; if ($col === 1) continue; $tot[] = $col >= $firstNum ? $fmt2(array_sum(array_map(fn($r) => (float)($r[$k . '_total'] ?? 0), $rows))) : ''; } ?>
            <?php foreach ($tot as $t): ?><td style="text-align:center;white-space:nowrap"><?= $t ?></td><?php endforeach; ?></tr>
        </tbody>
    </table></div>
    <?php if ($tk === 'adm' && $E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ إداري خارج الرواتب (الأخوات مثلاً)</button></div><?php endif; ?>
    <div class="note">الراتب الاساسي هو معدل الراتب الشهري على طول السنة</div>
    <div class="sig"><span>توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)</span><span>توقيع مدير المدرسة</span></div>
    <?php return ob_get_clean();
};
/* جدول «عنوان: قيمة» — الصفّ [عنوان، قيمة] أو [عنوان، قيمة، مسار الحقل، النوع] فيصير قابلاً للتعديل */
$kv = function (array $rows, string $fmt = 's') use ($fmt0, $E, $ctl, $ed, $rowAttr) {
    $h = '<table class="doc-table kv" dir="rtl" style="width:100%">';
    foreach ($rows as $row) {
        [$lb, $v] = $row; $path = $row[2] ?? null; $t = $row[3] ?? ($fmt === 'n' ? 'n' : 's');
        $shown = $fmt === 'n' ? $fmt0($v) : nl2br(e((string)$v));
        $style = 'text-align:' . ($fmt === 'n' ? 'center' : 'right');
        $h .= '<tr' . ($path !== null ? $rowAttr('field', $path) : '') . '>' . ($path !== null ? $ctl() : ($E ? '<td class="rowctl no-print"></td>' : '')) . '<td>' . e($lb) . '</td>'
            . ($path !== null ? $ed('v', (string)$v, $shown, $t, $style) : '<td style="' . $style . '">' . $shown . '</td>') . '</tr>';
    }
    return $h . '</table>';
};
/* أسطر الملخّص أ/ب/ج/د: بند نفقات ⇒ يُعدَّل بنده مباشرة؛ سطر محسوب ⇒ يُفرض يدوياً (↺ يرجّعه محسوباً) */
$money3 = function (array $rows, string $lb0, string $cat, bool $dec = false) use ($fmt0, $fmt2, $E, $ctl, $ctlTh, $ed, $rowAttr) {
    $h = '<div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr>' . $ctlTh() . '<th>' . e($lb0) . '</th><th>المجموع بالليرة</th><th>المجموع بالدولار</th></tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        [$lb, $l, $u] = $row; $meta = (array)($row[3] ?? []);
        $ov = !empty($meta['ov']);
        $attr = isset($meta['exp']) ? $rowAttr('exp', $meta['exp']) : $rowAttr('sum', $cat . '|' . $i);
        $h .= '<tr' . $attr . '>' . $ctl($ov) . '<td style="text-align:right;font-weight:700">' . e($lb) . '</td>'
            . $ed('ll', (string)$l, $dec ? $fmt2($l) : $fmt0($l), 'n', 'text-align:center', '', $ov)
            . $ed('usd', (string)$u, $dec ? $fmt2($u) : $fmt0($u), 'n', 'text-align:center', '', $ov) . '</tr>';
    }
    return $h . '</tbody></table></div>';
};
/* سطر نصّي حرّ (خارج الجداول) قدّامه زرّ التعديل */
$line = function (string $label, string $path, $v, string $t = 's') use ($E, $ctl, $rowAttr) {
    $shown = $t === 'ta' ? nl2br(e((string)$v)) : e((string)$v);
    if (!$E) return '<div class="mline"><strong>' . e($label) . ':</strong> <span>' . $shown . '</span></div>';
    return '<div class="mline"' . $rowAttr('field', $path) . '>' . $ctl(false, 'span') . '<strong>' . e($label) . ':</strong> <span data-f="v" data-t="' . $t . '" data-v="' . e((string)$v) . '" style="flex:1">' . $shown . '</span></div>';
};
?>
<div class="mehe-doc land-report">
<?= docSheetStart('Budget scolaire ' . $y1 . '/' . $y2 . ' — Ministère de l\'Éducation', 'موازنة السنة المدرسية ' . $y1 . '/' . $y2 . ' — وزارة التربية والتعليم العالي', $chips, $optsDoc) ?>
    <div class="mehe-cover">
        <div class="h"<?= $rowAttr('field', 'serial') ?>><?= $ctl(false, 'span') ?>الرقم التسلسلي: <?php if ($E): ?><span data-f="v" data-t="s" data-v="<?= e($data['serial']) ?>"><?= e($data['serial'] ?: '—') ?></span><?php else: ?><?= e($data['serial'] ?: '—') ?><?php endif; ?></div>
        <div class="h">جانب وزارة التربية والتعليم العالي</div>
        <div class="h" style="margin-top:14px">مصلحة التعليم الخاص</div>
        <?= $kv([['المستدعية', $school['name_ar']], ['رقم المركز التربوي', $data['center_no'], 'center_no'], ['الموضوع', $data['subject'], 'subject'], ['المرجع', $data['reference'], 'reference']]) ?>
        <p style="margin-top:14px">نودعكم ربط الموازنة المدرسية للسنة <?= $y1 ?>/<?= $y2 ?> مع المستندات المرفقة:</p>
        <p style="margin:0 20px">1 - محاضر اللجنة المالية<br>2 - بيان صندوق التعويضات<br>3 - تقرير التدقيق</p>
        <p style="margin-top:24px">واقبلوا فائق الاحترام</p>
        <div class="sig" style="margin-top:40px"><span>توقيع مدير(ة) المدرسة</span><span>ختم المدرسة</span></div>
    </div>
<?= docSheetEnd() ?>

<?= docSheetStart('Informations sur l\'école', 'معلومات المدرسة', $chips, $optsDoc) ?>
    <?= $line('اسم المدير', 'director', $data['director']) ?>
    <?= $line('رئيس لجنة أولياء الأمور', 'parents_head', $data['parents_head']) ?>
    <?= $line('رقم هاتف رئيس لجنة أولياء الأمور', 'parents_phone', $data['parents_phone']) ?>
    <?= $kv([['البرامج', $data['programs'], 'programs'], ['مستوى التعليم', $data['levels'], 'levels'], ['الصفوف', $data['classes'], 'classes', 'ta']]) ?>
    <h4 class="sec">أعضاء اللجنة المالية</h4>
    <div class="mline"<?= $rowAttr('field', 'fin_committee') ?>><?= $ctl(false, 'span') ?><ol<?= $E ? ' data-f="v" data-t="ta" data-v="' . e($data['fin_committee']) . '"' : '' ?>><?php foreach (array_filter(array_map('trim', explode("\n", (string)$data['fin_committee']))) as $m): ?><li><?= e($m) ?></li><?php endforeach; ?><?php if (trim((string)$data['fin_committee']) === ''): ?><li style="list-style:none;color:#94a3b8">— (اسم بكل سطر)</li><?php endif; ?></ol></div>
    <h4 class="sec">استطلاع</h4>
    <?= $kv([['مساحة الملاعب المفتوحة (م²)', $data['playground_open'], 'playground_open'], ['مساحة الملاعب المغلقة (م²)', $data['playground_closed'], 'playground_closed']], 'n') ?>
    <?= $kv([['مالك العقار', $data['owner'], 'owner'], ['مباني مشتركة مع', $data['shared_with'], 'shared_with'], ['استخدام الانترنت', $data['internet'], 'internet'], ['تفاصيل اخرى', $data['other_details'], 'other_details']]) ?>
    <?= $line('اسم مالك المبنى', 'building_owner', $data['building_owner']) ?>
    <?= $kv([['بناء مدرسي', $data['buildings_school'], 'buildings_school'], ['بناء سكني', $data['buildings_res'], 'buildings_res']], 'n') ?>
    <h4 class="sec">اللغات</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>اللغة</th><th>اولي</th><th>ثانوي</th><th>غير معتمدة في المدرسة</th></tr></thead><tbody>
    <?php foreach (meheLanguages() as $k => $lb): $v = (int)($data['languages'][$k] ?? 3); ?>
        <tr<?= $rowAttr('field', 'languages|' . $k) ?>><?= $ctl() ?><td style="text-align:right;font-weight:700"><?= e($lb) ?></td><?php foreach ([1, 2, 3] as $o): ?><?= $o === 1 ? $ed('v', $v, $v === $o ? '◉' : '○', 'sel', 'text-align:center', '1:اولي,2:ثانوي,3:غير معتمدة') : '<td style="text-align:center">' . ($v === $o ? '◉' : '○') . '</td>' ?><?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table>
    <div class="grid2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:10px">
        <div><h4 class="sec">الغرف والقاعات</h4><?= $kv(array_map(fn($rt) => [$rt, (int)($data['rooms'][$rt] ?? 0), 'rooms|' . $rt], meheRoomTypes()), 'n') ?></div>
        <div><h4 class="sec">المعدات التقنية</h4>
            <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>المعدات</th><th>من قبل الإدارة</th><th>لأغراض تعليمية</th></tr></thead><tbody>
            <?php foreach (meheEquipmentTypes() as $et): ?><tr<?= $rowAttr('field', 'equipment|' . $et . '|admin') ?>><?= $ctl() ?><td style="text-align:right;font-weight:700"><?= e($et) ?></td><?= $ed('v', (int)($data['equipment'][$et]['admin'] ?? 0), (string)(int)($data['equipment'][$et]['admin'] ?? 0), 'n', 'text-align:center') ?><?= $ed('edu', (int)($data['equipment'][$et]['edu'] ?? 0), (string)(int)($data['equipment'][$et]['edu'] ?? 0), 'n', 'text-align:center') ?></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
<?= docSheetEnd() ?>

<?= docSheetStart('Corps enseignant — cadre', 'أعضاء هيئة التدريس في الملاك', array_merge($chips, [count($p['tit']) . ' أستاذاً', $p['mode'] === 'oct' ? 'أساس شهر تشرين الأول' : 'معدل الأشهر']), $optsDoc) ?>
    <?= $staffTable('', 'tit', ['الاسم', 'دور الموظف', 'مؤهلات المعلم', 'مستوى التعليم', 'تاريخ الدخول الى الملاك', 'تاريخ مباشرة العمل', 'ساعات أسبوعية (ملاك)', 'ساعات أسبوعية (اضافية)', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'الأثر الرجعي', 'أجور مهمات تتجاوز نصاب العمل ل.ل', 'أجور مهمات تجاوز الـ35 ساعه', 'المكافآت', 'مهام إضافية ل.ل', 'تعويض نقل', 'تعويض عائلي', 'مساهمة الصندوق الوطني للضمان الاجتماعي', 'صندوق التعويضات'],
        ['name', 'role', 'qual', 'level', 'cadre_date', 'start_date', 'h_cadre', 'h_extra', 'base', 'extra_ll', 'extra_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'transport', 'family', 'cnss', 'fund'],
        $p['tit'], $p['tit_months'], 9, ['missions_ll', 'missions35', 'cnss', 'fund'], $data) ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Corps enseignant — contractuels', 'أعضاء هيئة التدريس المتعاقدين', array_merge($chips, [count($p['con']) . ' أستاذاً']), $optsDoc) ?>
    <?= $staffTable('', 'con', ['الاسم', 'دور الموظف', 'نمط العمل', 'نوع الضمان', 'مؤهلات المعلم', 'مستوى التعليم', 'تاريخ مباشرة العمل', 'ساعات أسبوعية', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'المكافآت', 'مهام إضافية ل.ل', 'تعويض نقل', 'مساهمة الصندوق الوطني للضمان الاجتماعي'],
        ['name', 'role', 'mode', 'cnss_type', 'qual', 'level', 'start_date', 'h_cadre', 'base', 'extra_ll', 'extra_usd', 'bonus', 'tasks_ll', 'transport', 'cnss'],
        $p['con'], $p['con_months'], 9, ['cnss'], $data) ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Personnel administratif', 'الموظفون الإداريون', array_merge($chips, [count($p['adm']) . ' موظفاً']), $optsDoc) ?>
    <?= $staffTable('', 'adm', ['الاسم', 'نمط العمل', 'تاريخ مباشرة العمل', 'نوع الموظف الاداري', 'نوع الضمان', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'مهام إضافية ل.ل', 'منح مدرسية ل.ل', 'تعويض نقل', 'مساهمة الصندوق الوطني للضمان الاجتماعي'],
        ['name', 'admin_mode', 'start_date', 'admin_type', 'cnss_type', 'base', 'extra_ll', 'extra_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss'],
        $p['adm'], $p['adm_months'], 6, ['cnss'], $data) ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Structure administrative et pédagogique', 'الهيكل الإداري والتعليمي', $chips, $optsDoc) ?>
    <h4 class="sec">الهيكل الإداري والتعليمي</h4>
    <?= $kv([['عدد الإداريين الخاضعين لقانون العمل', $data['struct_admin_law'], 'struct_admin_law'], ['عدد المستخدمين الخاضعين لقانون العمل', $data['struct_workers_law'], 'struct_workers_law'], ['عدد باقي المرتبطين بسير العمل', $data['struct_others'], 'struct_others']], 'n') ?>
    <h4 class="sec">الهيكل التعليمي</h4>
    <?= $kv(array_merge(array_map(fn($lv) => [$lv, (int)($data['classes_per_level'][$lv] ?? 0), 'classes_per_level|' . $lv], meheLevels()), [['إجمالي عدد الفصول', $s['classesTotal']]]), 'n') ?>
    <h4 class="sec">هيكل الموظفين</h4>
    <?= $kv([['عدد القائمين بالإدارة التعليمية (مدير-مساعد-منسق- مشرف)', $data['staff_mgmt'], 'staff_mgmt'], ['عدد القائمين بالنظارة', $data['staff_supervision'], 'staff_supervision'], ['عدد القائمين بالتدريس', $s['staffTeaching']], ['عدد الداخلين في الملاك', $s['staffInCadre']], ['عدد غير الداخلين في الملاك', $s['staffOutCadre']], ['إجمالي عدد الموظفين', $s['staffTotal']]], 'n') ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Élèves exemptés — bourses', 'قائمة الطلاب المعفيين', $chips, $optsDoc) ?>
    <?php $grRow = function (array $g, $i, bool $tpl = false) use ($ed, $ctl, $rowAttr, $fmt0): string {
        return '<tr' . ($tpl ? ' class="mtpl"' : '') . $rowAttr('list', 'grants|' . $i, $tpl) . '>' . $ctl()
            . $ed('student', (string)($g['student'] ?? ''), e((string)($g['student'] ?? '')), 's', 'text-align:right;font-weight:700')
            . $ed('teacher', (string)($g['teacher'] ?? ''), e((string)($g['teacher'] ?? '')), 's', 'text-align:right')
            . $ed('cat', (string)($g['cat'] ?? 'ملاك'), e((string)($g['cat'] ?? '')), 'sel', 'text-align:center', 'ملاك:ملاك,بقية الكادر:بقية الكادر')
            . $ed('class', (string)($g['class'] ?? ''), e((string)($g['class'] ?? '')), 's', 'text-align:right')
            . $ed('ll', (string)(float)($g['ll'] ?? 0), $fmt0($g['ll'] ?? 0), 'n', 'text-align:center')
            . $ed('usd', (string)(float)($g['usd'] ?? 0), $fmt0($g['usd'] ?? 0), 'n', 'text-align:center') . '</tr>';
    }; ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>اسم الطالب</th><th>عضو هيئة التدريس</th><th>فئة المعلم</th><th>الصف</th><th>المنحة المقدمة ل.ل</th><th>المنحة المقدمة د.أ</th></tr></thead><tbody>
    <?php $gl = array_filter(array_values((array)$data['grants']), fn($g) => trim((string)($g['student'] ?? '')) !== ''); foreach ($gl as $i => $g) echo $grRow($g, $i); ?>
    <?php if (!$gl): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="6" style="text-align:center">لا طلاب معفيين / Aucun</td></tr><?php endif; ?>
    <?php if ($E) echo $grRow(['cat' => 'ملاك'], 'new', true); ?>
    </tbody></table></div>
    <?php if ($E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ طالب معفى</button></div><?php endif; ?>
    <h4 class="sec">المنح الدراسية للمعلمين داخل الملاك</h4>
    <?= $kv([['العدد', $s['grTitN']], ['إجمالي المنحة ل.ل', $s['grTitLL']], ['إجمالي المنحة د.أ', $s['grTitUSD']]], 'n') ?>
    <h4 class="sec">المنح الدراسية لبقية الكادر</h4>
    <?= $kv([['العدد', $s['grOthN']], ['إجمالي المنحة ل.ل', $s['grOthLL']], ['إجمالي المنحة د.أ', $s['grOthUSD']]], 'n') ?>
<?= docSheetEnd() ?>

<?= docSheetStart('Indemnités de licenciement — cadre', 'تعويضات الصرف للداخلين في الملاك', $chips, $optsDoc) ?>
    <?php $svRow = function (array $x, $i, bool $tpl = false) use ($ed, $ctl, $rowAttr, $fmt0): string {
        $h = '<tr' . ($tpl ? ' class="mtpl"' : '') . $rowAttr('list', 'severance|' . $i, $tpl) . '>' . $ctl() . $ed('name', (string)($x['name'] ?? ''), e((string)($x['name'] ?? '')), 's', 'text-align:right;font-weight:700');
        foreach (['eos_ll', 'eos_usd', 'tasks_ll', 'tasks_usd'] as $f) $h .= $ed($f, (string)(float)($x[$f] ?? 0), $fmt0($x[$f] ?? 0), 'n', 'text-align:center');
        $h .= $ed('receipt_no', (string)($x['receipt_no'] ?? ''), e((string)($x['receipt_no'] ?? '')), 's', 'text-align:center') . $ed('receipt_date', (string)($x['receipt_date'] ?? ''), e((string)($x['receipt_date'] ?? '')), 's', 'text-align:center') . $ed('notes', (string)($x['notes'] ?? ''), e((string)($x['notes'] ?? '')), 's', 'text-align:right');
        return $h . '</tr>';
    }; ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>اسم المستفيد</th><th>تعويضات نهاية الخدمة ل.ل</th><th>تعويضات نهاية الخدمة د.أ</th><th>تعويضات المهام الإضافية ل.ل</th><th>تعويضات المهام الإضافية د.أ</th><th>رقم إيصال الدفع</th><th>تاريخ الإيصال</th><th>ملاحظات</th></tr></thead><tbody>
    <?php $sl = array_filter(array_values((array)$data['severance']), fn($x) => trim((string)($x['name'] ?? '')) !== ''); foreach ($sl as $i => $x) echo $svRow($x, $i); ?>
    <?php if (!$sl): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="8" style="text-align:center">—</td></tr><?php endif; ?>
    <?php if ($E) echo $svRow([], 'new', true); ?>
    </tbody></table></div>
    <?php if ($E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ مستفيد</button></div><?php endif; ?>
    <p><strong>المجموع بالليرة:</strong> <?= $fmt0($s['sevLL']) ?> &nbsp;&nbsp; <strong>المجموع بالدولار:</strong> <?= $fmt0($s['sevUSD']) ?></p>
<?= docSheetEnd() ?>

<?= docSheetStart('Coûts de fonctionnement — dépenses', 'تكاليف التشغيل — النفقات', $chips, $optsDoc) ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>النفقة</th><th>القيمة بالليرة اللبنانية</th><th>القيمة بالدولار</th></tr></thead><tbody>
    <?php foreach (meheExpenseItems() as $k => [$lb, $cat]): ?><tr<?= $rowAttr('exp', $k) ?>><?= $ctl() ?><td style="text-align:right;font-weight:700"><?= e($lb) ?></td><?= $ed('ll', (string)(float)($data['expenses'][$k]['ll'] ?? 0), $fmt0($data['expenses'][$k]['ll'] ?? 0), 'n', 'text-align:center') ?><?= $ed('usd', (string)(float)($data['expenses'][$k]['usd'] ?? 0), $fmt0($data['expenses'][$k]['usd'] ?? 0), 'n', 'text-align:center') ?></tr><?php endforeach; ?>
    <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">مجموع</td><td style="text-align:center"><?= $fmt0($s['expTotalLL']) ?></td><td style="text-align:center"><?= $fmt0($s['expTotalUSD']) ?></td></tr>
    </tbody></table></div>
<?= docSheetEnd() ?>

<?= docSheetStart('Recettes', 'الإيرادات', $chips, $optsDoc) ?>
    <?php $rvRow = function (array $rv, $i, bool $tpl = false) use ($ed, $ctl, $rowAttr, $fmt0): string {
        return '<tr' . ($tpl ? ' class="mtpl"' : '') . $rowAttr('list', 'revenues|' . $i, $tpl) . '>' . $ctl()
            . $ed('program', (string)($rv['program'] ?? ''), e((string)($rv['program'] ?? '')), 's', 'text-align:right;font-weight:700')
            . $ed('class', (string)($rv['class'] ?? ''), e((string)($rv['class'] ?? '')), 's', 'text-align:right')
            . $ed('fee_ll', (string)(float)($rv['fee_ll'] ?? 0), $fmt0($rv['fee_ll'] ?? 0), 'n', 'text-align:center')
            . $ed('fee_usd', (string)(float)($rv['fee_usd'] ?? 0), $fmt0($rv['fee_usd'] ?? 0), 'n', 'text-align:center')
            . $ed('students', (string)(int)($rv['students'] ?? 0), (string)(int)($rv['students'] ?? 0), 'n', 'text-align:center')
            . '<td style="text-align:center;white-space:nowrap">LL: ' . $fmt0($rv['tot_ll'] ?? 0) . '<br>USD: ' . $fmt0($rv['tot_usd'] ?? 0) . '</td></tr>';
    }; ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>البرنامج</th><th>الصف</th><th>الرسوم الدراسيه ل.ل</th><th>الرسوم الدراسيه د.أ</th><th>عدد الطلاب</th><th>المجموع للصف</th></tr></thead><tbody>
    <?php foreach ($s['revRows'] as $rv) echo $rvRow($rv, $rv['idx']); ?>
    <?php if (!$s['revRows']): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="6" style="text-align:center">لا صفوف — أضف صفّاً بالزرّ تحت</td></tr><?php endif; ?>
    <?php if ($E) echo $rvRow(['program' => 'منهاج لبناني'], 'new', true); ?>
    <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="4" style="text-align:right">المجموع</td><td style="text-align:center"><?= (int)$s['students'] ?></td><td style="text-align:center;white-space:nowrap">LL: <?= $fmt0($s['revLL']) ?><br>USD: <?= $fmt0($s['revUSD']) ?></td></tr>
    </tbody></table></div>
    <?php if ($E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ صف</button></div><?php endif; ?>
    <?= $kv([['عدد الطلاب الكلي', $s['students']], ['عدد طلاب المنح للمدرسين داخل الملاك', $s['grTitN']], ['إجمالي الايرادات بعد حسم المنح الدراسية لأبناء المعلمين الملاك', 'LL:' . $fmt0($s['revAfterLL']) . ' USD:' . $fmt0($s['revAfterUSD'])], ['متوسط الرسوم الدراسية', 'LL:' . $fmt2($s['avgFeeLL']) . ' USD:' . $fmt2($s['avgFeeUSD'])]]) ?>
    <div class="sig"><span>توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)</span><span>توقيع مدير المدرسة</span></div>
<?= docSheetEnd() ?>

<?= docSheetStart('Résumé du budget', 'ملخص الموازنة', $chips, $optsDoc) ?>
    <h4 class="sec">النفقات من الفئة أ</h4><?= $money3($s['A'], 'اسم النفقة', 'A') ?>
    <h4 class="sec">النفقات من الفئة ب</h4><?= $money3($s['B'], 'اسم النفقة', 'B', true) ?>
    <h4 class="sec">النفقات من الفئة ج</h4><?= $money3($s['C'], 'اسم النفقة', 'C') ?>
    <h4 class="sec">النفقات من الفئة د</h4><?= $money3($s['D'], 'اسم النفقة', 'D', true) ?>
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
<?php if ($E): ?>
<script>
/* ✏️💾 «قدام كل سطر من صفحات الموازنة تعديل وحفظ» (2026-09-07): كل عنصر data-mrow سطر؛ خاناته data-f.
   تعديل ⇒ الخانات تصير حقولاً؛ حفظ ⇒ تُرسل القيم المتغيّرة فقط (fetch) ثم تُعاد الصفحة بنفس الموضع؛
   ↺ تلقائي ⇒ يمسح القيم اليدوية للسطر فيرجع محسوباً من الرواتب. */
(function(){
    var CSRF = <?= json_encode(csrfToken()) ?>;
    function mk(c){ var t=c.dataset.t||'s', v=c.dataset.v||'', inp;
        if(t==='sel'){ inp=document.createElement('select'); (c.dataset.o||'').split(',').forEach(function(o){ var p=o.split(':'); inp.add(new Option(p[1]||p[0], p[0], false, p[0]===v)); }); }
        else if(t==='ta'){ inp=document.createElement('textarea'); inp.value=v; }
        else { inp=document.createElement('input'); inp.type='text'; inp.value=v; if(t==='n'){ inp.inputMode='decimal'; inp.style.textAlign='center'; inp.dir='ltr'; } }
        inp.className='mi'; return inp; }
    function open(row){ row.querySelectorAll('[data-f]').forEach(function(c){ if(c.querySelector('.mi')) return; c.dataset.html=c.innerHTML; c.innerHTML=''; c.appendChild(mk(c)); });
        var f=row.querySelector('.mi'); if(f){ f.focus(); if(f.select) f.select(); } }
    function close(row){ row.querySelectorAll('[data-f]').forEach(function(c){ if(c.dataset.html!==undefined){ c.innerHTML=c.dataset.html; delete c.dataset.html; } }); }
    function save(row, reset){
        var p=row.dataset.mrow.split('|'), fd=new FormData(); fd.append('csrf',CSRF); fd.append('mehe_row',p.shift()); fd.append('key',p.join('|')); if(reset) fd.append('reset','1');
        var changed=0, isNew=!!row.dataset.new;
        if(!reset) row.querySelectorAll('[data-f]').forEach(function(c){ var i=c.querySelector('.mi'); if(!i) return; if(isNew||i.value!==c.dataset.v){ fd.append('f['+c.dataset.f+']', i.value); changed++; } });
        if(!reset && !changed){ close(row); row.querySelector('.rowctl').classList.remove('editing'); return; }
        var kind=row.dataset.mrow.split('|')[0];
        if(kind==='list' && !isNew){ var reqf={revenues:'class',grants:'student',severance:'name',manual_admins:'name'}[row.dataset.mrow.split('|')[1]]; var rc=row.querySelector('[data-f="'+reqf+'"] .mi'); if(rc && rc.value.trim()===''){ if(!confirm('الخانة الأساسية فارغة — السطر رح ينحذف نهائياً. متأكد؟')) return; } }
        row.querySelectorAll('.rowctl button').forEach(function(b){ b.disabled=true; });
        fetch(location.href, {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'fetch'}})
            .then(function(r){ return r.json(); })
            .then(function(j){ if(j && j.ok){ try{ sessionStorage.setItem('meheScroll', String(window.scrollY)); }catch(e){} location.reload(); }
                               else { alert((j && j.msg) || 'تعذّر الحفظ'); row.querySelectorAll('.rowctl button').forEach(function(b){ b.disabled=false; }); } })
            .catch(function(){ alert('تعذّر الحفظ — تحقّق من الاتصال'); row.querySelectorAll('.rowctl button').forEach(function(b){ b.disabled=false; }); });
    }
    function wire(row){ var ctl=row.querySelector('.rowctl'); if(!ctl||ctl.dataset.w) return; ctl.dataset.w='1';
        var q=function(c){ return ctl.querySelector(c); };
        q('.ed').onclick=function(){ open(row); ctl.classList.add('editing'); };
        q('.cx').onclick=function(){ if(row.dataset.new){ row.remove(); return; } close(row); ctl.classList.remove('editing'); };
        q('.sv').onclick=function(){ save(row,false); };
        var rs=q('.rs'); if(rs) rs.onclick=function(){ if(confirm('رجوع القيم التلقائية من الرواتب لهذا السطر؟')) save(row,true); };
        row.addEventListener('keydown', function(ev){ if(ev.target.classList && ev.target.classList.contains('mi')){ if(ev.key==='Enter' && ev.target.tagName!=='TEXTAREA'){ ev.preventDefault(); save(row,false); } if(ev.key==='Escape'){ q('.cx').click(); } } });
    }
    document.querySelectorAll('[data-mrow]').forEach(wire);
    window.meheAddSheetRow=function(btn){ var tbl=btn.parentNode.previousElementSibling.querySelector('table'); var tpl=tbl.querySelector('tr.mtpl'); if(!tpl) return;
        var tr=tpl.cloneNode(true); tr.classList.remove('mtpl'); tr.dataset.new='1'; delete tr.querySelector('.rowctl').dataset.w;
        tbl.querySelectorAll('tbody tr:not([data-mrow]):not(.tot):not(.mtpl)').forEach(function(ph){ ph.style.display='none'; }); // صفّ «لا صفوف»
        tpl.parentNode.insertBefore(tr, tpl); wire(tr); tr.querySelector('.rowctl .ed').click(); };
    try{ var sc=sessionStorage.getItem('meheScroll'); if(sc!==null){ sessionStorage.removeItem('meheScroll'); window.scrollTo(0, parseInt(sc,10)||0); } }catch(e){}
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
