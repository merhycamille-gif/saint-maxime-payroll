<?php
/**
 * 🏛️ Budget MEHE / موازنة وزارة التربية — الموازنة المدرسية السنوية طبق منصّة مصلحة التعليم الخاص
 * psb.mehe.gov.lb، لكل مدرسة وسنة (طلبه 2026-09-06). المنطق كله بـincludes/mehe_budget.php.
 *  - جداول الأساتذة (ملاك/متعاقدون/إداريون) والملخّص أ/ب من الرواتب تلقائياً (راتب الموظف الحالي × أشهر الراتب).
 *  - ✏️💾 «بدي قدام كل سطر من صفحات الموازنة الخيار تعديل وحفظ» (2026-09-07): كل سطر قدّامه زرّ تعديل يفتح خاناته
 *    وزرّ حفظ يحفظه فوراً (fetch → JSON → إعادة تحميل بنفس القسم والموضع). أسطر الموظفين المحسوبة تُخزَّن قيمها اليدوية
 *    بـoverrides ولها «↺ تلقائي»؛ أسطر الملخّص المحسوبة (أ/ب/د) تُفرض يدوياً أو ترجع محسوبة؛ المجاميع محسوبة دائماً.
 *  - 📄✏️ «كبسة تعديل لكل صفحة بصفحتها وبعدين حفظ» (2026-11-06): فوق كل قسم «تعديل الصفحة» يفتح كل أسطره دفعة واحدة
 *    و«حفظ الصفحة» يرسلها كلّها بطلب واحد (mehe_rows JSON).
 *  - 🏛️🇱🇧 (2026-11-06): المستند عربي صرف واتجاهه من اليمين دائماً مهما كانت لغة البرنامج (.rl-fixed).
 *  - 🏛️🖥️ (2026-11-08 «فيك تعملها بنفس الترتيب والتنظيم والصفحات والشكل»): الصفحة صارت نسخة طبق الأصل عن منصّة الوزارة:
 *    القائمة الجانبية بأقسامها الاثني عشر وبترتيبها وعناوينها، كل قسم صفحة بذاتها بالجداول والخانات نفسها وبترتيب الأعمدة
 *    نفسه (معرّف الموظف، إجمالي الراتب، أشهر الراتب/النقل لكل فئة، خانات التفتيش للبرامج والمراحل والصفوف ومالك العقار
 *    والإنترنت، الوثائق المطلوبة بالرفع)، «السابق/التالي» أسفل كل قسم، والطباعة/PDF/إكسل تطلع الأقسام كلّها بالترتيب نفسه —
 *    كي يُنقَل كل سطر إلى المنصّة سطراً سطراً. النموذج القديم المطويّ أُزيل (كل شيء يُعدَّل من مكانه).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/mehe_budget.php';
requireLogin();

$db = getDB();
$currentPage = 'mehe_budget';
$pageTitle = 'موازنة وزارة التربية والتعليم العالي';
$hideExportToolbar = true; $ownExportRow = true;
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
$jsonOut = function (array $res): void { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE); exit; };
/* بعد أي تعديل: فئة المنح من عضو هيئة التدريس (كالمنصّة) ثم الحفظ */
$saveAll = function () use (&$data, $db, $ids, $sy): void { meheSyncGrantCats($data, mehePayroll($db, $ids, $sy, $data)); meheSave($db, $ids, $sy, $data); };

/* ===== ✏️ حفظ سطر واحد من الورقة (fetch → JSON) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mehe_row'])) {
    if (!canEdit()) $jsonOut(['ok' => false, 'msg' => 'قراءة فقط']);
    if (!verifyCsrf($_POST['csrf'] ?? '')) $jsonOut(['ok' => false, 'msg' => 'رمز الأمان غير صحيح — أعد تحميل الصفحة']);
    $res = meheApplyRowEdit($data, $_POST);
    if ($res['ok']) { $saveAll(); $_SESSION['flash_success'] = $res['msg']; }
    $jsonOut($res);
}
/* ===== 📄✏️ حفظ صفحة كاملة: كل أسطر الورقة المعدَّلة بطلب واحد (mehe_rows = JSON [{mehe_row,key,f,reset}]) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mehe_rows'])) {
    if (!canEdit()) $jsonOut(['ok' => false, 'msg' => 'قراءة فقط']);
    if (!verifyCsrf($_POST['csrf'] ?? '')) $jsonOut(['ok' => false, 'msg' => 'رمز الأمان غير صحيح — أعد تحميل الصفحة']);
    $items = json_decode((string)$_POST['mehe_rows'], true);
    if (!is_array($items) || !$items) $jsonOut(['ok' => false, 'msg' => 'لا تعديلات']);
    // أسطر القوائم (إيرادات/منح/صرف/إداريون) تُطبَّق من الأعلى فهرساً إلى الأدنى ثم الجديدة أخيراً: حذف سطر لا يزحزح فهارس ما بعده
    $norm = []; foreach ($items as $it) { if (!is_array($it) || !isset($it['mehe_row'])) continue; $it['f'] = is_array($it['f'] ?? null) ? $it['f'] : []; $norm[] = $it; }
    $isList = fn($it) => ($it['mehe_row'] ?? '') === 'list';
    $lists = array_values(array_filter($norm, $isList)); $others = array_values(array_filter($norm, fn($it) => !$isList($it)));
    usort($lists, function ($a, $b) { $ia = explode('|', (string)($a['key'] ?? ''))[1] ?? ''; $ib = explode('|', (string)($b['key'] ?? ''))[1] ?? '';
        if ($ia === 'new' || $ib === 'new') return ($ia === 'new') <=> ($ib === 'new'); return (int)$ib <=> (int)$ia; });
    $n = 0;
    foreach (array_merge($others, $lists) as $it) {
        $res = meheApplyRowEdit($data, $it);
        if (!$res['ok']) $jsonOut(['ok' => false, 'msg' => $res['msg']]);
        $n++;
    }
    $saveAll();
    $_SESSION['flash_success'] = 'انحفظت الصفحة (' . $n . ' ' . ($n === 1 ? 'سطر' : 'أسطر') . ')';
    $jsonOut(['ok' => true, 'n' => $n]);
}
/* ===== ⚖️ «وازِن» (2026-11-08 «بدي كل الخيارات متاحة»): اقتراح توازن (fetch → JSON) ثمّ تطبيقه بموافقته (apply=1) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mehe_balance'])) {
    if (!canEdit()) $jsonOut(['ok' => false, 'msg' => 'قراءة فقط']);
    if (!verifyCsrf($_POST['csrf'] ?? '')) $jsonOut(['ok' => false, 'msg' => 'رمز الأمان غير صحيح — أعد تحميل الصفحة']);
    $pB = mehePayroll($db, $ids, $sy, $data); $sB = meheSummary($data, $pB);
    $plan = meheBalancePlan($data, $pB, $sB, $_POST);
    if (!empty($_POST['apply'])) {
        if (!$plan['changes']) $jsonOut(['ok' => false, 'msg' => 'لا تعديلات لتطبيقها']);
        $data = $plan['data']; $saveAll();
        $_SESSION['flash_success'] = 'وُزِّنت الموازنة — ' . count($plan['changes']) . ' ' . (count($plan['changes']) === 1 ? 'تعديل' : 'تعديلات');
        $jsonOut(['ok' => true, 'applied' => count($plan['changes'])]);
    }
    unset($plan['data']);
    $jsonOut(['ok' => true] + $plan);
}
/* ===== 🏛️🖥️ الوثائق المطلوبة (القسم 12): رفع ملف لوثيقة / حذفه — PDF أو صورة، يُخزَّن بـuploads/mehe ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && isset($_POST['mehe_doc'])) {
    requireCsrf();
    $key = (string)$_POST['mehe_doc'];
    if (!isset(meheDocTypes()[$key])) { $_SESSION['flash_error'] = 'وثيقة غير معروفة'; header('Location: ' . BASE_URL . 'pages/mehe_budget.php#sec=11'); exit; }
    $dir = meheDocsDir();
    if (!empty($_POST['mehe_doc_del'])) {
        $old = (string)($data['docs'][$key]['file'] ?? '');
        if ($old !== '' && is_file($dir . '/' . basename($old))) @unlink($dir . '/' . basename($old));
        unset($data['docs'][$key]);
        meheSave($db, $ids, $sy, $data);
        $_SESSION['flash_success'] = 'انحذفت الوثيقة: ' . meheDocTypes()[$key];
    } elseif (!empty($_FILES['doc']['tmp_name']) && is_uploaded_file($_FILES['doc']['tmp_name'])) {
        $ext = strtolower(pathinfo((string)$_FILES['doc']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) { $_SESSION['flash_error'] = 'الملف يجب أن يكون PDF أو صورة (JPG/PNG)'; }
        elseif ((int)$_FILES['doc']['size'] > 15 * 1024 * 1024) { $_SESSION['flash_error'] = 'حجم الملف أكبر من 15MB'; }
        else {
            $fn = meheDocFileName($ids, $sy, $key, $ext);
            $old = (string)($data['docs'][$key]['file'] ?? '');
            if ($old !== '' && $old !== $fn && is_file($dir . '/' . basename($old))) @unlink($dir . '/' . basename($old));
            if (move_uploaded_file($_FILES['doc']['tmp_name'], $dir . '/' . $fn)) {
                $data['docs'][$key] = ['file' => $fn, 'orig' => (string)$_FILES['doc']['name'], 'at' => date('Y-m-d H:i')];
                meheSave($db, $ids, $sy, $data);
                $_SESSION['flash_success'] = 'رُفعت الوثيقة: ' . meheDocTypes()[$key];
            } else $_SESSION['flash_error'] = 'تعذّر حفظ الملف';
        }
    } else $_SESSION['flash_error'] = 'لم يُختر ملف';
    header('Location: ' . BASE_URL . 'pages/mehe_budget.php#sec=11');
    exit;
}

// 🚀 (2026-10-03): جداول الرواتب للموازنة من كاش ببصمة الداتا (GET) — أي تعديل على الرواتب/الموظفين/بيانات الموازنة يغيّر البصمة أو المفتاح
$pKey = 'mehe_p|' . md5(json_encode([$ids, $sy, $data]));
$p = msaFpCacheGet($pKey);
if (!is_array($p)) { $p = mehePayroll($db, $ids, $sy, $data); msaFpCachePut($pKey, $p); }
// 🏛️🖥️ سطر «فرق الرسوم الدراسية عن العام الماضي»: متوسّط آخر موازنة محفوظة سابقة
$prevSaved = mehePrevSaved($db, $ids, $sy);
if ($prevSaved) $data['_prev_avg'] = meheAvgFeeFromData($prevSaved['data']);
$s = meheSummary($data, $p);
unset($data['_prev_avg']);
[$y1, $y2] = schoolYearToYears($sy);

/* ===== 🧩 تصدير JSON لمساعد التعبئة (إضافة كروم tools/mehe_fill_extension) — 2026-11-08 «فاتحين موقع الوزارة والبرنامج… نبعت الموازنة على الموقع»:
 *  البنية بتسميات المنصّة الحرفية؛ المساعد يقرأها وهو على صفحة psb.mehe.gov.lb ويعبّئ الخانات، والإرسال يبقى بيده على الموقع ===== */
if (($_GET['export'] ?? '') === 'json') {
    $dmy = function ($s) { $s = trim((string)$s); if ($s === '') return ''; if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $s, $m)) return sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3]); return $s; };
    $staffRow = fn(array $r) => ['mehe_id' => (string)($r['mehe_id'] ?? ''), 'name' => (string)$r['name'], 'role' => (string)($r['role'] ?? $r['admin_mode'] ?? ''), 'level' => (string)($r['level'] ?? ''), 'qual' => (string)($r['qual'] ?? ''),
        'mode' => (string)($r['mode'] ?? ''), 'cnss_type' => (string)($r['cnss_type'] ?? ''), 'admin_type' => (string)($r['admin_type'] ?? ''), 'cadre_date' => $dmy($r['cadre_date'] ?? ''), 'start_date' => $dmy($r['start_date'] ?? ''),
        'h_cadre' => (float)($r['h_cadre'] ?? 0), 'h_extra' => (float)($r['h_extra'] ?? 0), 'base' => round((float)($r['base'] ?? 0)), 'extra_ll' => round((float)($r['extra_ll'] ?? 0)), 'extra_usd' => round((float)($r['extra_usd'] ?? 0)),
        'retro' => round((float)($r['retro'] ?? 0)), 'missions_ll' => round((float)($r['missions_ll'] ?? 0)), 'missions35' => round((float)($r['missions35'] ?? 0)), 'bonus' => round((float)($r['bonus'] ?? 0)), 'tasks_ll' => round((float)($r['tasks_ll'] ?? 0)),
        'grants_ll' => round((float)($r['grants_ll'] ?? 0)), 'family' => round((float)($r['family'] ?? 0)), 'transport' => round((float)($r['transport'] ?? 0)), 'cnss' => round((float)($r['cnss'] ?? 0)), 'fund' => round((float)($r['fund'] ?? 0)), 'manual' => !empty($r['manual'])];
    $lblVal = function (array $keys, array $src, string $sub = '') use ($data) { $o = []; foreach ($keys as $k) $o[$k] = $sub === '' ? (int)($src[$k] ?? 0) : ['admin' => (int)($src[$k]['admin'] ?? 0), 'edu' => (int)($src[$k]['edu'] ?? 0)]; return $o; };
    $langs = []; foreach (meheLanguages() as $k => $lb) $langs[$lb] = (int)($data['languages'][$k] ?? 3);
    $exp = []; foreach (meheExpenseItems() as $k => [$lb, $cat]) $exp[$lb] = ['ll' => round((float)($data['expenses'][$k]['ll'] ?? 0)), 'usd' => round((float)($data['expenses'][$k]['usd'] ?? 0))];
    $fin = array_values(array_filter(array_map('trim', explode("\n", (string)$data['fin_committee'])), fn($x) => $x !== ''));
    $out = [
        'program' => 'MSA Payroll', 'generated' => date('c'), 'school' => (string)$school['name_ar'], 'school_id' => $sid, 'sy' => $sy, 'multi' => $multi,
        'info' => ['director' => (string)$data['director'], 'parents_head' => (string)$data['parents_head'], 'parents_phone' => (string)$data['parents_phone'],
                   'programs' => meheMulti($data['programs_sel'] ?? ''), 'levels' => meheMulti($data['levels_sel'] ?? ''), 'classes' => meheMulti($data['classes_sel'] ?? ''), 'fin' => $fin],
        'survey' => ['open' => (int)$data['playground_open'], 'closed' => (int)$data['playground_closed'], 'owner' => meheMulti($data['owner_sel'] ?? ''), 'owner_name' => (string)$data['building_owner'],
                     'buildings' => ['بناء مدرسي' => (int)$data['buildings_school'], 'بناء سكني' => (int)$data['buildings_res']], 'shared' => meheMulti($data['shared_sel'] ?? ''),
                     'rooms' => $lblVal(meheRoomTypes(), (array)$data['rooms']), 'languages' => $langs, 'equipment' => $lblVal(meheEquipmentTypes(), (array)$data['equipment'], 'x'),
                     'internet' => meheMulti($data['internet_sel'] ?? ''), 'other' => meheMulti($data['other_sel'] ?? '')],
        'staff' => ['tit' => array_map($staffRow, $p['tit']), 'con' => array_map($staffRow, $p['con']), 'adm' => array_map($staffRow, $p['adm'])],
        'months' => ['tit' => $data['months']['tit'], 'con' => $data['months']['con'], 'adm' => $data['months']['adm']],
        'structure' => ['admin_law' => (int)$data['struct_admin_law'], 'workers_law' => (int)$data['struct_workers_law'], 'others' => (int)$data['struct_others'],
                        'classes' => $lblVal(meheLevels(), (array)$data['classes_per_level']), 'mgmt' => (int)$data['staff_mgmt'], 'supervision' => (int)$data['staff_supervision']],
        'students' => array_values(array_map(fn($g) => ['student' => (string)$g['student'], 'teacher' => (string)($g['teacher'] ?? ''), 'class' => (string)($g['class'] ?? ''), 'll' => round((float)($g['ll'] ?? 0)), 'usd' => round((float)($g['usd'] ?? 0))], array_filter((array)$data['grants'], fn($g) => trim((string)($g['student'] ?? '')) !== ''))),
        'severance' => array_values(array_map(fn($x) => ['name' => (string)$x['name'], 'eos_ll' => round((float)($x['eos_ll'] ?? 0)), 'eos_usd' => round((float)($x['eos_usd'] ?? 0)), 'tasks_ll' => round((float)($x['tasks_ll'] ?? 0)), 'tasks_usd' => round((float)($x['tasks_usd'] ?? 0)), 'receipt_no' => (string)($x['receipt_no'] ?? ''), 'receipt_date' => $dmy($x['receipt_date'] ?? ''), 'notes' => (string)($x['notes'] ?? '')], array_filter((array)$data['severance'], fn($x) => trim((string)($x['name'] ?? '')) !== ''))),
        'expenses' => $exp,
        'revenues' => array_values(array_map(fn($rv) => ['program' => (string)$rv['program'], 'class' => (string)$rv['class'], 'fee_ll' => round((float)$rv['fee_ll']), 'fee_usd' => round((float)$rv['fee_usd']), 'students' => (int)$rv['students']], $s['revRows'])),
        'summary' => ['diffL' => round($s['diffL']), 'diffU' => round($s['diffU']), 'pctC' => $s['pctC'], 'pctAB' => $s['pctAB'], 'okBalance' => $s['okBalance'], 'okRatio' => $s['okRatio']],
    ];
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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
$fmtLU = fn($l, $u) => number_format((float)$l, 0) . 'LL ' . number_format((float)$u, 0) . 'USD'; // صيغة المنصّة للمجاميع المزدوجة
// كل موظفي السنة (لقائمة المستثنين) — بمن فيهم المستثنون حالياً
$empList = $db->prepare("SELECT DISTINCT e.id, e.school_id, CONCAT_WS(' ', e.first_name_ar, NULLIF(e.father_name_ar,''), e.last_name_ar) nm, e.employee_type FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ? AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) WHERE e.school_id IN (" . implode(',', $ids) . ") AND e.is_deleted = 0 ORDER BY e.school_id, e.employee_type, nm"); // 🚪 (2026-09-28) أساتذة السنة فقط: صفوف صفرية منقولة لا تُدخل التارك
$empList->execute([$sy]);
$empList = $empList->fetchAll();
$excludedIds = array_map('intval', (array)($data['excluded'] ?? []));
$T = meheSectionTitles();
$E = canEdit(); // ✏️ أزرار التعديل تظهر للمحرّرين فقط (حسابات المدارس قراءة)
?>
<style>
/* 🏛️🖥️ شكل منصّة الوزارة: قائمة جانبية يمين + بطاقة بيضاء للقسم + رؤوس جداول خضراء زرقاء */
.mehe-app{display:flex;gap:16px;align-items:flex-start;direction:rtl}
.mehe-nav{width:235px;flex:0 0 235px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px 8px;position:sticky;top:12px}
.mehe-nav a{display:block;padding:10px 14px;margin:2px 0;border-radius:10px;color:#4e9f91;text-decoration:none;font-weight:700;font-size:14px;line-height:1.5;text-align:right}
.mehe-nav a:hover{background:#f0faf8}
.mehe-nav a.on{background:#5fb3a4;color:#fff}
.mehe-main{flex:1;min-width:0}
.mehe-doc{direction:rtl}
.mehe-doc .doc-sheet{display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px 26px 18px}
.mehe-doc .doc-sheet.on{display:block}
.mehe-doc .doc-head{display:none}
.mehe-doc .secttl{font-weight:800;font-size:17px;color:#1f2937;margin:0 0 14px}
.mehe-doc .doc-table{width:100%}
.mehe-doc .doc-table td,.mehe-doc .doc-table th{font-size:12pt;padding:7px 9px;border:1px solid #e3e6ea}
.mehe-doc .doc-table th{background:#5fb3a4 !important;color:#fff !important;font-weight:700;white-space:normal}
.mehe-doc .doc-table td{background:#fff}
.mehe-doc .doc-table tr.tot td{background:#eef7f5;font-weight:800}
.mehe-doc .doc-table tr.mrow td{background:#f7f9fa;font-weight:700}
.mehe-doc h4.sec{margin:16px 0 8px;color:#4e9f91;font-size:14px;font-weight:800;text-decoration:underline}
.mehe-doc .pf{margin:0 0 12px}
.mehe-doc .pf label{display:block;font-size:13.5px;color:#374151;margin:0 0 4px;font-weight:600}
.mehe-doc .pf .pv{border:1px solid #d7dde3;border-radius:8px;padding:8px 12px;min-height:38px;background:#fff;font-size:12pt}
.mehe-doc .pf .pv.num{text-align:left;direction:ltr}
.mehe-doc .pf.ro .pv{background:#f8fafc}
.mehe-doc .chkl{display:flex;flex-direction:column;gap:6px;font-size:12pt}
.mehe-doc .chkl span.i{display:inline-block;width:17px;height:17px;border-radius:4px;border:1px solid #cbd5e1;vertical-align:-3px;margin-inline-start:8px;background:#fff}
.mehe-doc .chkl span.i.on{background:#5fb3a4;border-color:#5fb3a4;position:relative}
.mehe-doc .chkl span.i.on::after{content:'✓';color:#fff;font-size:12px;position:absolute;inset:0;text-align:center;line-height:17px}
.mehe-doc .chkgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px 20px}
.mehe-doc .chkgrid strong{display:block;margin-bottom:6px;color:#111}
.mehe-doc .badge-n{display:inline-block;background:#5fb3a4;color:#fff;border-radius:8px;padding:8px 14px;font-weight:800;font-size:15px}
.mehe-doc .tophead{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 14px;flex-wrap:wrap}
.mehe-doc .srch{border:1px solid #d7dde3;border-radius:8px;padding:9px 14px;width:420px;max-width:100%;font-size:14px;direction:rtl}
.mehe-doc .months2{display:flex;gap:24px;flex-wrap:wrap;margin-top:14px}
.mehe-doc .months2 .pf{width:260px;margin:0}
.mehe-doc .navbtns{display:flex;justify-content:flex-start;gap:10px;margin-top:22px;flex-direction:row-reverse}
.mehe-doc .navbtns .btn{border-radius:8px;padding:10px 26px;font-weight:700;font-size:15px}
.mehe-doc .navbtns .nxt{background:#5fb3a4;border-color:#5fb3a4;color:#fff}
.mehe-doc .navbtns .prv{background:#6b7280;border-color:#6b7280;color:#fff}
.mehe-doc .rowctl{white-space:nowrap;width:1%;text-align:center;vertical-align:middle;padding:2px 4px !important}
.mehe-doc .rowctl button{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:1px 6px;font-size:11px;cursor:pointer;margin:1px;line-height:1.5}
.mehe-doc .rowctl button.sv{background:#16a34a;color:#fff;border-color:#16a34a;display:none}
.mehe-doc .rowctl button.cx{display:none}
.mehe-doc .rowctl button.rs{color:#b45309}
.mehe-doc .rowctl button.xc{color:#991b1b}
.mehe-doc .rowctl.editing button.sv,.mehe-doc .rowctl.editing button.cx{display:inline-block}
.mehe-doc .rowctl.editing button.ed,.mehe-doc .rowctl.editing button.rs,.mehe-doc .rowctl.editing button.xc{display:none}
.mehe-doc span.rowctl{display:inline-flex;gap:2px;width:auto;margin-inline-end:6px;vertical-align:middle}
.mehe-doc .pf span.rowctl{float:left;margin:0 0 0 2px}
.mehe-doc .mi{width:100%;min-width:60px;box-sizing:border-box;border:1px solid #16a34a;border-radius:5px;padding:2px 4px;font-size:12pt;font-family:inherit;background:#f0fdf4}
.mehe-doc textarea.mi{min-height:64px;min-width:260px}
.mehe-doc .chkedit{display:flex;flex-direction:column;gap:4px;font-size:12pt}
.mehe-doc .chkedit label{display:flex;align-items:center;gap:8px;cursor:pointer}
.mehe-doc td.ovd{background:#fffbea !important}
.mehe-doc .addrow{margin:8px 0;font-size:13px}
.mehe-doc tr.mtpl{display:none}
.mehe-doc .excl{margin-top:10px;font-size:13px;color:#475569}
.mehe-doc .excl button{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:1px 8px;font-size:12px;cursor:pointer;margin-inline-start:6px}
.mehe-doc .sig{display:flex;justify-content:space-between;margin-top:14px;font-size:12pt}
.mehe-doc .docrow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px;margin:0 0 10px}
.mehe-doc .docrow .lb{flex:1 1 260px;font-weight:700}
.mehe-doc .docrow a.f{color:#2563eb;word-break:break-all}
.mehe-doc .docrow input[type=file]{font-size:13px}
.mehe-doc .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px 28px}
/* ⚖️ وازِن + التنبيه الفوري لنسبة ج */
.mehe-doc .balbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 12px}
.mehe-doc .balstat{flex:1 1 320px;border-radius:10px;padding:9px 14px;font-weight:700;font-size:13.5px}
.mehe-doc .balstat.ok{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.mehe-doc .balstat.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.mehe-doc .balpanel{flex-basis:100%;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;background:#fff}
.mehe-doc .balopts label{display:block;margin:4px 0;font-size:13.5px;cursor:pointer}
.mehe-doc .balsel{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin:10px 0;font-size:13px}
.mehe-doc .balsel select{max-width:360px;padding:5px 8px;border:1px solid #d7dde3;border-radius:8px}
.mehe-doc .balout table{width:100%;margin:8px 0}
.mehe-doc .balout .after{font-weight:700;margin:6px 0}
.mehe-doc .balout .ok{color:#166534}.mehe-doc .balout .bad{color:#991b1b}
.mehe-doc .ballive{display:none;margin:0 0 10px;border-radius:10px;padding:8px 14px;font-weight:700;font-size:13px}
.mehe-doc .ballive.bad{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.mehe-doc .ballive.ok{display:block;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
/* 📄✏️ شريط الصفحة */
.mehe-doc .sheetctl{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 14px}
.mehe-doc .sheetctl .btn{border-radius:10px;padding:7px 16px;font-weight:700;font-size:13.5px}
.mehe-doc .sheetctl .btn-primary{background:#1F4E5F;border-color:#1F4E5F;color:#fff}
.mehe-doc .sheetctl .btn-success{background:#16a34a;border-color:#16a34a;color:#fff}
.mehe-doc .sheetctl .sv,.mehe-doc .sheetctl .cx,.mehe-doc .sheetctl .hint{display:none}
.mehe-doc .sheetctl.editing .sv,.mehe-doc .sheetctl.editing .cx,.mehe-doc .sheetctl.editing .hint{display:inline-block}
.mehe-doc .sheetctl.editing .ed{display:none}
.mehe-doc .sheetctl .hint{font-size:12px;color:#475569}
@media (max-width:900px){.mehe-app{flex-direction:column}.mehe-nav{width:100%;flex:none;position:static;display:flex;flex-wrap:wrap;gap:4px}.mehe-nav a{padding:6px 10px;font-size:13px}.mehe-doc .chkgrid{grid-template-columns:1fr 1fr}.mehe-doc .grid2{grid-template-columns:1fr}}
@media print{
  @page{size:A4 landscape;margin:8mm}
  .mehe-nav,.no-print{display:none !important}
  .mehe-app{display:block}
  .mehe-doc .doc-sheet{display:block !important;border:0;border-radius:0;padding:0;page-break-after:always;page-break-inside:auto}
  .mehe-doc .doc-sheet:last-child{page-break-after:auto}
  .mehe-doc .doc-head{display:block}
  .mehe-doc .doc-table th{background:#5fb3a4 !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .mehe-doc .chkl span.i.on{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .mehe-doc .report-table-wrap{overflow:visible !important}
  .mehe-doc .srch,.mehe-doc .navbtns{display:none !important}
}
</style>

<div class="card no-print" dir="rtl">
    <div class="card-header"><h3>
        <span><i class="fas fa-landmark"></i> موازنة وزارة التربية والتعليم العالي — <?= e($school['name_ar']) ?> — <?= e($sy) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">مصلحة التعليم الخاص — طبق منصّة psb.mehe.gov.lb: الأقسام نفسها بالترتيب نفسه لتُنقَل سطراً سطراً</div>
    </h3></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <a class="btn btn-success" href="?export=xlsx"><i class="fas fa-file-excel"></i> إكسل (صيغ حيّة)</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> طباعة</button>
        <?php /* 📤 (2026-10-03 «كل شي من كل شي»): نفس كبسات باقي التقارير */ $mbT = jsAttr('موازنة وزارة التربية ' . $sy); ?>
        <button type="button" class="btn btn-danger" onclick="msaSavePdfStart(this)"><i class="fas fa-file-pdf"></i> حفظ PDF</button>
        <?php if (!isViewer()): ?><button type="button" class="btn btn-info" onclick="ppWord('<?= $mbT ?>')"><i class="fas fa-file-word"></i> وورد</button><?php endif; ?>
        <button type="button" class="btn" style="background:#25D366;color:#fff" onclick="ppWhatsApp('<?= $mbT ?>','')"><i class="fab fa-whatsapp"></i> واتساب</button>
        <?php if (!isViewer()): ?><button type="button" class="btn btn-light" onclick="ppEmail('<?= $mbT ?>','')"><i class="fas fa-envelope"></i> بريد إلكتروني</button><?php endif; ?>
        <?php /* 🔢 (2026-11-08 «خيار بفواصل أو بلا فواصل» للنسخ إلى منصّة الوزارة): يبدّل عرض أرقام الشاشة فقط؛ الطباعة/PDF/وورد/إكسل تبقى بفواصل */ ?>
        <span class="mehe-sep" role="group" aria-label="عرض الأرقام" style="display:inline-flex;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden">
            <button type="button" class="btn btn-light sep-on" data-sep="1" onclick="meheSep(true)" title="54,000,000">بفواصل</button><button type="button" class="btn btn-light sep-off" data-sep="0" onclick="meheSep(false)" title="54000000 — للنسخ واللصق إلى موقع الوزارة">بلا فواصل</button>
        </span>
        <span class="text-muted" style="font-size:12.5px">جداول الأساتذة والموظفين والملخّص (أ، ب) تُقرأ من رواتب <?= e($sy) ?> تلقائياً (راتب الموظف الحالي × أشهر الراتب). المدرسة (أو مجموعة مدارس) والسنة من الأعلى<?= $multi ? '؛ الآن موازنة مجمّعة لـ' . count($schools) . ' مدارس' : '' ?>. فوق كل قسم «✏️ تعديل الصفحة» ثم «💾 حفظ الصفحة»؛ وقدّام كل سطر «✏️ تعديل» ثم «💾 حفظ». الأسطر المعدَّلة يدوياً لها «↺ تلقائي» للرجوع إلى الرواتب.<?= !empty($data['carried_from']) ? ' <strong>سنة جديدة:</strong> معلومات المدرسة والاستطلاع والهيكل والأشهر ومعرّفات الموظفين رُحِّلت من آخر موازنة محفوظة؛ النفقات والأقساط والمنح تُدخل من جديد.' : '' ?></span>
    </div>
</div>

<?php
/* ===================== أدوات العرض/التعديل ===================== */
/* خلية أزرار قدّام السطر (تُخفى بالطباعة). $rs = سطر له قيم يدوية ⇒ زرّ «↺ تلقائي»؛ $xc = موظف من الرواتب ⇒ زرّ «استثناء» */
$ctl = function (bool $rs = false, string $tag = 'td', bool $xc = false) use ($E): string {
    if (!$E) return '';
    // 🚀 (2026-10-03): «💾 حفظ» و«✖» يُخلقان بالمتصفّح عند أوّل «تعديل» (كانا ~1000 كبسة مخفية تُحسب بتخطيط كل فتحة)
    return "<$tag class=\"rowctl no-print\"><button type=\"button\" class=\"ed\" title=\"تعديل هذا السطر\">✏️ تعديل</button>" . ($rs ? "<button type=\"button\" class=\"rs\" title=\"رجوع القيمة التلقائية من الرواتب\">↺ تلقائي</button>" : '') . ($xc ? "<button type=\"button\" class=\"xc\" title=\"استثناء هذا الموظف من الموازنة\">⊘</button>" : '') . "</$tag>";
};
$ctlTh = fn() => $E ? '<th class="rowctl no-print"></th>' : '';
/* خلية قابلة للتعديل: data-f اسم الحقل · data-t النوع (s نص، n رقم، ta نص طويل، sel اختيار k:v، sel2 اختيار «أ|ب|ج»، chk خانات تفتيش «أ|ب|ج») · data-v القيمة الخام */
$ed = function (string $f, $raw, string $shown, string $t = 's', string $style = '', string $opts = '', bool $ovd = false) use ($E): string {
    if (!$E) return '<td style="' . $style . '">' . $shown . '</td>';
    return '<td data-f="' . e($f) . '" data-t="' . $t . '" data-v="' . e((string)$raw) . '"' . ($opts !== '' ? ' data-o="' . e($opts) . '"' : '') . ' style="' . $style . '"' . ($ovd ? ' class="ovd"' : '') . '>' . $shown . '</td>';
};
$rowAttr = fn(string $kind, string $key, bool $new = false) => $E ? ' data-mrow="' . e($kind . '|' . $key) . '"' . ($new ? ' data-new="1"' : '') : '';
/* خانة منصّة: عنوان فوق صندوق القيمة — قابلة للتعديل (field|path) */
$box = function (string $label, string $path, $v, string $t = 's', bool $required = false) use ($E, $ctl, $rowAttr, $fmt0): string {
    $shown = $t === 'n' ? $fmt0($v) : ($t === 'ta' ? nl2br(e((string)$v)) : e((string)$v));
    $h = '<div class="pf"' . $rowAttr('field', $path) . '>' . ($E ? $ctl(false, 'span') : '') . '<label>' . e($label) . ($required ? ' *' : '') . '</label>';
    $h .= $E ? '<div class="pv' . ($t === 'n' ? ' num' : '') . '" data-f="v" data-t="' . $t . '" data-v="' . e((string)$v) . '">' . $shown . '</div>' : '<div class="pv' . ($t === 'n' ? ' num' : '') . '">' . $shown . '</div>';
    return $h . '</div>';
};
/* خانة للقراءة فقط (محسوبة) */
$boxRo = fn(string $label, string $shown) => '<div class="pf ro"><label>' . e($label) . '</label><div class="pv">' . $shown . '</div></div>';
/* قائمة خانات تفتيش كالمنصّة (قيمة «أ|ب|ج») — تُعدَّل كسطر واحد */
$chk = function (string $label, string $path, array $options, $v, string $cls = 'chkl') use ($E, $ctl, $rowAttr): string {
    $sel = meheMulti($v);
    $list = '<div class="' . $cls . '">';
    foreach ($options as $o) $list .= '<div><span class="i' . (in_array($o, $sel, true) ? ' on' : '') . '"></span>' . e($o) . '</div>';
    $list .= '</div>';
    $h = '<div class="pf"' . $rowAttr('field', $path) . '>' . ($E ? $ctl(false, 'span') : '') . ($label !== '' ? '<h4 class="sec" style="margin-top:4px">' . e($label) . '</h4>' : '');
    $h .= $E ? '<div data-f="v" data-t="chk" data-v="' . e((string)$v) . '" data-o="' . e(implode('|', $options)) . '">' . $list . '</div>' : $list;
    return $h . '</div>';
};
/* جدول موظفين بأعمدة المنصّة: $cols العناوين · $keys المفاتيح · $firstNum أوّل عمود رقمي (1-based) · $dec2 أعمدة بخانتين */
$staffTable = function (string $tk, array $cols, array $keys, array $rows, array $months, int $firstNum, array $dec2, array $data) use ($fmt0, $fmt2, $fmtLU, $multi, $E, $ctl, $ctlTh, $ed, $rowAttr, $T) {
    if ($multi) { $cols = array_merge(array_slice($cols, 0, 2), ['المدرسة'], array_slice($cols, 2)); $keys = array_merge(array_slice($keys, 0, 2), ['school'], array_slice($keys, 2)); $firstNum++; }
    $ovM = (array)($data['overrides']['months'][$tk] ?? []);
    $manualMap = ['admin_mode' => 'mode', 'admin_type' => 'type']; // أعمدة الإداري اليدوي بأسماء حقوله المخزّنة
    $selOf = function (string $k) { // أعمدة اختيار كالمنصّة
        return match ($k) { 'role', 'admin_mode' => implode('|', meheRoleOptions()), 'qual' => implode('|', meheQualOptions()), 'level' => implode('|', meheLevelOptions()),
            'mode' => implode('|', meheWorkModeOptions()), 'cnss_type' => implode('|', meheCnssTypeOptions()), 'admin_type' => implode('|', meheAdminTypeOptions()), default => '' };
    };
    $rowHtml = function (array $r, bool $tpl = false) use ($keys, $firstNum, $dec2, $fmt0, $fmt2, $fmtLU, $ctl, $ed, $rowAttr, $manualMap, $selOf): string {
        $manual = !empty($r['manual']);
        $h = '<tr' . ($tpl ? ' class="mtpl"' : '') . ($manual ? $rowAttr('list', 'manual_admins|' . ($tpl ? 'new' : substr((string)$r['key'], 1)), $tpl) : $rowAttr('emp', (string)($r['key'] ?? ''))) . '>';
        $h .= $ctl(!$manual && !empty($r['overridden']), 'td', !$manual && !$tpl);
        foreach ($keys as $i => $k) {
            $col = $i + 1;
            $style = $k === 'name' || $k === 'school' ? 'text-align:right;white-space:nowrap' . ($k === 'name' ? ';font-weight:700' : '') : 'text-align:center';
            if ($k === 'school') { $h .= $manual ? $ed('school', (string)($r[$k] ?? ''), e((string)($r[$k] ?? '')), 's', $style) : '<td style="' . $style . '">' . e((string)($r[$k] ?? '')) . '</td>'; continue; }
            if ($k === 'total_ll') { $h .= '<td style="text-align:center;white-space:nowrap;font-weight:700">' . $fmtLU($r['total_ll'] ?? 0, $r['total_usd'] ?? 0) . '</td>'; continue; }
            if ($k === 'total_usd') continue;
            $f = $manual ? ($manualMap[$k] ?? $k) : $k;
            if ($col >= $firstNum) { $raw = (float)($r[$k] ?? 0); $h .= $ed($f, $raw == floor($raw) ? (string)(int)$raw : (string)$raw, in_array($k, $dec2, true) ? $fmt2($raw) : $fmt0($raw), 'n', $style); }
            else { $o = $selOf($k); $h .= $ed($f, (string)($r[$k] ?? ''), e((string)($r[$k] ?? '')), $o !== '' ? 'sel2' : 's', $style, $o); }
        }
        return $h . '</tr>';
    };
    $colsShown = array_values(array_filter($cols, fn($c, $i) => ($keys[$i] ?? '') !== 'total_usd', ARRAY_FILTER_USE_BOTH));
    ob_start(); ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl">
        <thead><tr><?= $ctlTh() ?><?php foreach ($colsShown as $cl): ?><th><?= e($cl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r) echo $rowHtml($r); ?>
        <?php if (!$rows): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="<?= count($colsShown) ?>" style="text-align:center">لا يوجد نتائج مطابقة</td></tr><?php endif; ?>
        <?php if ($tk === 'adm' && $E): $tplRow = ['manual' => true, 'key' => 'mnew', 'name' => '', 'school' => '', 'admin_mode' => '', 'start_date' => '', 'admin_type' => 'عادي', 'cnss_type' => 'غير مضمون', 'mehe_id' => '']; echo $rowHtml($tplRow, true); endif; ?>
        <tr class="mrow"<?= $rowAttr('months', $tk) ?>><?= $ctl((bool)$ovM) ?><td style="text-align:right">الأشهر</td>
            <?php foreach ($keys as $i => $k): $col = $i + 1; if ($col === 1 || $k === 'total_usd') continue; ?><?= $col >= $firstNum && $k !== 'total_ll' ? $ed($k, (int)($months[$k] ?? 12), (string)(int)($months[$k] ?? 12), 'n', 'text-align:center', '', isset($ovM[$k])) : '<td></td>' ?><?php endforeach; ?></tr>
        <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">المجموع</td>
            <?php foreach ($keys as $i => $k) { $col = $i + 1; if ($col === 1 || $k === 'total_usd') continue;
                if ($col >= $firstNum && $k !== 'total_ll') echo '<td style="text-align:center;white-space:nowrap">' . $fmt0(array_sum(array_map(fn($r) => (float)($r[$k . '_total'] ?? 0), $rows))) . '</td>';
                elseif ($k === 'total_ll') echo '<td style="text-align:center;white-space:nowrap">' . $fmtLU(array_sum(array_map(fn($r) => (float)($r['base_total'] ?? 0) + (float)($r['extra_ll_total'] ?? 0), $rows)), array_sum(array_map(fn($r) => (float)($r['extra_usd_total'] ?? 0), $rows))) . '</td>';
                else echo '<td></td>'; } ?></tr>
        </tbody>
    </table></div>
    <?php if ($tk === 'adm' && $E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ إداري خارج الرواتب (الأخوات مثلاً)</button></div><?php endif; ?>
    <div class="months2">
        <?php $mS = (int)($data['months'][$tk]['salary'] ?? 12); $mT = (int)($data['months'][$tk]['transport'] ?? 12); ?>
        <div class="pf"<?= $rowAttr('months2', $tk) ?>><?= $E ? $ctl(false, 'span') : '' ?><label>أشهر الراتب</label><?= $E ? '<div class="pv num" data-f="salary" data-t="n" data-v="' . $mS . '">' . $mS . '</div>' : '<div class="pv num">' . $mS . '</div>' ?></div>
        <div class="pf"<?= $rowAttr('months2', $tk . '|t') ?>><?= $E ? $ctl(false, 'span') : '' ?><label>أشهر النقل</label><?= $E ? '<div class="pv num" data-f="transport" data-t="n" data-v="' . $mT . '">' . $mT . '</div>' : '<div class="pv num">' . $mT . '</div>' ?></div>
    </div>
    <?php return ob_get_clean();
};
/* أسطر الملخّص أ/ب/ج/د: بند نفقات ⇒ يُعدَّل بنده مباشرة؛ سطر محسوب ⇒ يُفرض يدوياً (↺ يرجّعه محسوباً) */
$money3 = function (array $rows, string $cat, bool $dec = false) use ($fmt0, $fmt2, $E, $ctl, $ctlTh, $ed, $rowAttr) {
    $h = '<div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr>' . $ctlTh() . '<th>اسم النفقة</th><th>المجموع بالليرة</th><th>المجموع بالدولار</th></tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        [$lb, $l, $u] = $row; $meta = (array)($row[3] ?? []);
        $ov = !empty($meta['ov']);
        $attr = isset($meta['exp']) ? $rowAttr('exp', $meta['exp']) : $rowAttr('sum', $cat . '|' . $i);
        $h .= '<tr' . $attr . '>' . $ctl($ov) . '<td style="text-align:right">' . e($lb) . '</td>'
            . $ed('ll', (string)$l, $dec ? $fmt2($l) : $fmt0($l), 'n', 'text-align:center', '', $ov)
            . $ed('usd', (string)$u, $dec ? $fmt2($u) : $fmt0($u), 'n', 'text-align:center', '', $ov) . '</tr>';
    }
    return $h . '</tbody></table></div>';
};
/* قسم من أقسام المنصّة: ورقة بعنوانها (الترويسة تظهر بالطباعة فقط) + أزرار السابق/التالي */
$secStart = function (int $i) use ($T, $sy, $school): string {
    $chips = [$sy];
    // docSheetStart يفتح div.doc-sheet (الترويسة للطباعة) — نضيف إليه صنف القسم ومعرّفه
    $open = preg_replace('/<div class="doc-sheet([^"]*)"/u', '<div class="doc-sheet$1 mehe-sec" id="msec' . $i . '" data-sec="' . $i . '"', docSheetStart('', $T[$i], $chips, ['comp' => false, 'no_letterhead' => true]), 1);
    return $open . '<h2 class="secttl no-print">' . e($T[$i]) . '</h2>';
};
$secEnd = function (int $i) use ($T): string {
    $h = '<div class="navbtns no-print">';
    if ($i < count($T) - 1) $h .= '<button type="button" class="btn nxt" onclick="meheGo(' . ($i + 1) . ')">التالي</button>';
    if ($i > 0) $h .= '<button type="button" class="btn prv" onclick="meheGo(' . ($i - 1) . ')">السابق</button>';
    if ($i === count($T) - 1) $h .= '<a class="btn nxt" href="' . BASE_URL . 'pages/reports.php">عودة للقائمة</a>';
    return $h . '</div></div>';
};
$excludedList = function (string $type) use ($empList, $excludedIds, $E): string { // مستثنون من هذه الفئة ⇒ سطر «إرجاع»
    $xs = array_values(array_filter($empList, fn($em) => in_array((int)$em['id'], $excludedIds, true) && $em['employee_type'] === $type));
    if (!$xs) return '';
    $h = '<div class="excl no-print"><strong>مستثنون من الموازنة:</strong> ';
    foreach ($xs as $em) $h .= '<span>' . e($em['nm']) . ($E ? '<button type="button" class="xr" data-eid="' . (int)$em['id'] . '" title="إرجاعه إلى الموازنة">↩ إرجاع</button>' : '') . '</span> ';
    return $h . '</div>';
};
$finLines = array_values(array_filter(array_map('trim', explode("\n", (string)$data['fin_committee'])), fn($x) => $x !== ''));
?>
<div class="mehe-app">
<nav class="mehe-nav no-print" aria-label="أقسام الموازنة">
    <?php foreach ($T as $i => $ttl): ?><a href="#sec=<?= $i ?>" data-sec="<?= $i ?>" onclick="meheGo(<?= $i ?>);return false"><?= e($ttl) ?></a><?php endforeach; ?>
</nav>
<div class="mehe-main"><div class="mehe-doc land-report rl-fixed" dir="rtl">

<?= $secStart(0) ?>
    <?= $box('اسم المدير', 'director', $data['director'], 's', true) ?>
    <?= $box('رئيس لجنة أولياء الأمور', 'parents_head', $data['parents_head'], 's', true) ?>
    <?= $box('رقم هاتف رئيس لجنة أولياء الأمور', 'parents_phone', $data['parents_phone'], 's', true) ?>
    <?= $chk('البرامج', 'programs_sel', meheProgramOptions(), $data['programs_sel'] ?? '') ?>
    <h4 class="sec">مستوى التعليم</h4>
    <div class="pf"<?= $rowAttr('field', 'levels_sel') ?>><?= $E ? $ctl(false, 'span') : '' ?><strong style="display:block;margin-bottom:6px">منهاج لبناني</strong>
        <?php $selL = meheMulti($data['levels_sel'] ?? ''); $lst = '<div class="chkl">'; foreach (meheLevels() as $lv) $lst .= '<div><span class="i' . (in_array($lv, $selL, true) ? ' on' : '') . '"></span>' . e($lv) . '</div>'; $lst .= '</div>'; ?>
        <?= $E ? '<div data-f="v" data-t="chk" data-v="' . e((string)($data['levels_sel'] ?? '')) . '" data-o="' . e(implode('|', meheLevels())) . '">' . $lst . '</div>' : $lst ?>
    </div>
    <h4 class="sec">الصفوف</h4>
    <div class="pf"<?= $rowAttr('field', 'classes_sel') ?>><?= $E ? $ctl(false, 'span') : '' ?>
        <?php $selC = meheMulti($data['classes_sel'] ?? ''); $grid = '<div class="chkgrid">'; foreach (meheClassesByLevel() as $lv => $cls) { $grid .= '<div><strong>' . e($lv) . '</strong><div class="chkl">'; foreach ($cls as $cn) $grid .= '<div><span class="i' . (in_array($cn, $selC, true) ? ' on' : '') . '"></span>' . e($cn) . '</div>'; $grid .= '</div></div>'; } $grid .= '</div>'; ?>
        <?= $E ? '<div data-f="v" data-t="chk" data-v="' . e((string)($data['classes_sel'] ?? '')) . '" data-o="' . e(implode('|', array_merge(...array_values(meheClassesByLevel())))) . '" data-grid="1">' . $grid . '</div>' : $grid ?>
    </div>
    <h4 class="sec">أعضاء اللجنة المالية</h4>
    <?= $box('الاسم', 'fin_member|0', $finLines[0] ?? '') ?>
    <?= $box('الاسم', 'fin_member|1', $finLines[1] ?? '') ?>
<?= $secEnd(0) ?>

<?= $secStart(1) ?>
    <h4 class="sec">مباني المدرسة</h4>
    <?= $box('مساحة الملاعب المفتوحة (م²)', 'playground_open', $data['playground_open'], 'n') ?>
    <?= $box('مساحة الملاعب المغلقة (م²)', 'playground_closed', $data['playground_closed'], 'n') ?>
    <?= $chk('مالك العقار', 'owner_sel', meheOwnerOptions(), $data['owner_sel'] ?? '') ?>
    <?= $box('اسم مالك المبنى', 'building_owner', $data['building_owner'], 's', true) ?>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>نوع المبنى</th><th>عدد المباني</th></tr></thead><tbody>
        <tr<?= $rowAttr('field', 'buildings_school') ?>><?= $ctl() ?><td style="text-align:right">بناء مدرسي</td><?= $ed('v', (int)$data['buildings_school'], (string)(int)$data['buildings_school'], 'n', 'text-align:center') ?></tr>
        <tr<?= $rowAttr('field', 'buildings_res') ?>><?= $ctl() ?><td style="text-align:right">بناء سكني</td><?= $ed('v', (int)$data['buildings_res'], (string)(int)$data['buildings_res'], 'n', 'text-align:center') ?></tr>
    </tbody></table>
    <?= $chk('مباني مشتركة مع', 'shared_sel', meheSharedWithOptions(), $data['shared_sel'] ?? '') ?>
    <h4 class="sec">الغرف والقاعات</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>نوع الغرفة</th><th>عدد الغرف</th></tr></thead><tbody>
    <?php foreach (meheRoomTypes() as $rt): ?><tr<?= $rowAttr('field', 'rooms|' . $rt) ?>><?= $ctl() ?><td style="text-align:right"><?= e($rt) ?></td><?= $ed('v', (int)($data['rooms'][$rt] ?? 0), (string)(int)($data['rooms'][$rt] ?? 0), 'n', 'text-align:center') ?></tr><?php endforeach; ?>
    </tbody></table>
    <h4 class="sec">اللغات</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>اللغة</th><th>اولي</th><th>ثانوي</th><th>غير معتمدة في المدرسة</th></tr></thead><tbody>
    <?php foreach (meheLanguages() as $k => $lb): $v = (int)($data['languages'][$k] ?? 3); ?>
        <tr<?= $rowAttr('field', 'languages|' . $k) ?>><?= $ctl() ?><td style="text-align:right"><?= e($lb) ?></td><?php foreach ([1, 2, 3] as $o): ?><?= $o === 1 ? $ed('v', $v, $v === $o ? '◉' : '○', 'sel', 'text-align:center', '1:اولي,2:ثانوي,3:غير معتمدة') : '<td style="text-align:center">' . ($v === $o ? '◉' : '○') . '</td>' ?><?php endforeach; ?></tr>
    <?php endforeach; ?></tbody></table>
    <h4 class="sec">المعدات التقنية</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>المعدات</th><th>عدد المستخدم من قبل الإدارة</th><th>عدد المستخدم لأغراض تعليمية</th></tr></thead><tbody>
    <?php foreach (meheEquipmentTypes() as $et): ?><tr<?= $rowAttr('field', 'equipment|' . $et . '|admin') ?>><?= $ctl() ?><td style="text-align:right"><?= e($et) ?></td><?= $ed('v', (int)($data['equipment'][$et]['admin'] ?? 0), (string)(int)($data['equipment'][$et]['admin'] ?? 0), 'n', 'text-align:center') ?><?= $ed('edu', (int)($data['equipment'][$et]['edu'] ?? 0), (string)(int)($data['equipment'][$et]['edu'] ?? 0), 'n', 'text-align:center') ?></tr><?php endforeach; ?>
    </tbody></table>
    <?= $chk('استخدام الانترنت', 'internet_sel', meheInternetOptions(), $data['internet_sel'] ?? '') ?>
    <?= $chk('تفاصيل اخرى', 'other_sel', meheOtherDetailOptions(), $data['other_sel'] ?? '') ?>
<?= $secEnd(1) ?>

<?= $secStart(2) ?>
    <div class="tophead no-print"><input class="srch" type="search" placeholder="بحث من خلال الاسم او المعرف" oninput="meheFilter(this)"><span class="badge-n">العدد الكلي : <?= count($p['tit']) ?></span></div>
    <?= $staffTable('tit', ['معرف الموظف', 'الاسم والشهرة', 'دور الموظف', 'مستوى التعليم', 'مؤهلات المعلم', 'تاريخ الدخول الى الملاك', 'تاريخ مباشرة العمل', 'ساعات أسبوعية (ملاك)', 'ساعات أسبوعية (اضافية)', 'أساس الراتب', 'أجور إضافية ل.ل', 'أجور إضافية د.أ', 'إجمالي الراتب', '', 'الأثر الرجعي ل.ل', 'أجور مهمات تتجاوز نصاب العمل ل.ل', 'ما يتجاوز حد ال35 ساعة ل.ل', 'المكافآت ل.ل', 'مهام إضافية ل.ل', 'تعويض عائلي ل.ل', 'تعويض النقل ل.ل', 'مساهمة الصندوق الوطني للضمان الاجتماعي ل.ل', 'صندوق التعويضات ل.ل'],
        ['mehe_id', 'name', 'role', 'level', 'qual', 'cadre_date', 'start_date', 'h_cadre', 'h_extra', 'base', 'extra_ll', 'extra_usd', 'total_ll', 'total_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'family', 'transport', 'cnss', 'fund'],
        $p['tit'], $p['tit_months'], 10, ['missions_ll', 'missions35', 'cnss', 'fund'], $data) ?>
    <?= $excludedList('enseignant_titulaire') ?>
<?= $secEnd(2) ?>

<?= $secStart(3) ?>
    <div class="tophead no-print"><input class="srch" type="search" placeholder="بحث من خلال الاسم او المعرف" oninput="meheFilter(this)"><span class="badge-n">العدد الكلي : <?= count($p['con']) ?></span></div>
    <?= $staffTable('con', ['معرف الموظف', 'الاسم والشهرة', 'دور الموظف', 'مستوى التعليم', 'نمط العمل', 'نوع الضمان الاجتماعي', 'مؤهلات المعلم', 'تاريخ مباشرة العمل', 'ساعات أسبوعية', 'أساس الراتب', 'أجور إضافية ل.ل', 'أجور إضافية د.أ', 'إجمالي الراتب', '', 'المكافآت ل.ل', 'مهام إضافية ل.ل', 'تعويض النقل ل.ل', 'مساهمة الصندوق الوطني للضمان الاجتماعي ل.ل'],
        ['mehe_id', 'name', 'role', 'level', 'mode', 'cnss_type', 'qual', 'start_date', 'h_cadre', 'base', 'extra_ll', 'extra_usd', 'total_ll', 'total_usd', 'bonus', 'tasks_ll', 'transport', 'cnss'],
        $p['con'], $p['con_months'], 10, ['cnss'], $data) ?>
    <?= $excludedList('enseignant_contractuel') ?>
<?= $secEnd(3) ?>

<?= $secStart(4) ?>
    <div class="tophead no-print"><input class="srch" type="search" placeholder="بحث من خلال الاسم او المعرف" oninput="meheFilter(this)"><span class="badge-n">العدد الكلي : <?= count($p['adm']) ?></span></div>
    <?= $staffTable('adm', ['معرف الموظف', 'الاسم والشهرة', 'دور الموظف', 'نوع الموظف الاداري', 'نوع الضمان الاجتماعي', 'تاريخ مباشرة العمل', 'أساس الراتب', 'أجور إضافية ل.ل', 'أجور إضافية د.أ', 'إجمالي الراتب', '', 'مهام إضافية ل.ل', 'منح مدرسية ل.ل', 'تعويض النقل ل.ل', 'مساهمة الصندوق الوطني للضمان الاجتماعي ل.ل'],
        ['mehe_id', 'name', 'admin_mode', 'admin_type', 'cnss_type', 'start_date', 'base', 'extra_ll', 'extra_usd', 'total_ll', 'total_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss'],
        $p['adm'], $p['adm_months'], 7, ['cnss'], $data) ?>
    <?= $excludedList('employe') ?>
    <?php if ($E): ?><div class="pf no-print" style="margin-top:14px;max-width:560px"<?= $rowAttr('field', 'base_mode') ?>><?= $ctl(false, 'span') ?><label>أساس الراتب الشهري بالجداول (الفئات الثلاث)</label>
        <div class="pv" data-f="v" data-t="sel" data-v="<?= e($data['base_mode']) ?>" data-o="last:راتب الموظف الحالي (آخر شهر مدفوع),oct:قيمة شهر تشرين الأول"><?= $data['base_mode'] === 'oct' ? 'قيمة شهر تشرين الأول' : 'راتب الموظف الحالي (آخر شهر مدفوع)' ?></div></div><?php endif; ?>
<?= $secEnd(4) ?>

<?= $secStart(5) ?>
    <h4 class="sec" style="margin-top:0">الهيكل الإداري والتعليمي</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>الوصف</th><th>العدد</th></tr></thead><tbody>
        <?php foreach ([['عدد الإداريين الخاضعين لقانون العمل', 'struct_admin_law'], ['عدد المستخدمين الخاضعين لقانون العمل', 'struct_workers_law'], ['عدد باقي المرتبطين بسير العمل', 'struct_others']] as [$lb, $k]): ?>
        <tr<?= $rowAttr('field', $k) ?>><?= $ctl() ?><td style="text-align:right"><?= e($lb) ?></td><?= $ed('v', (int)$data[$k], (string)(int)$data[$k], 'n', 'text-align:center') ?></tr>
        <?php endforeach; ?>
    </tbody></table>
    <h4 class="sec">الهيكل التعليمي</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>المستوى التعليمي</th><th>عدد الفصول</th></tr></thead><tbody>
        <?php foreach (meheLevels() as $lv): ?><tr<?= $rowAttr('field', 'classes_per_level|' . $lv) ?>><?= $ctl() ?><td style="text-align:right"><?= e($lv) ?></td><?= $ed('v', (int)($data['classes_per_level'][$lv] ?? 0), (string)(int)($data['classes_per_level'][$lv] ?? 0), 'n', 'text-align:center') ?></tr><?php endforeach; ?>
        <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">إجمالي عدد الفصول</td><td style="text-align:center"><?= (int)$s['classesTotal'] ?></td></tr>
    </tbody></table>
    <h4 class="sec">هيكل الموظفين</h4>
    <table class="doc-table" dir="rtl" style="width:100%"><thead><tr><?= $ctlTh() ?><th>الوصف</th><th>العدد</th></tr></thead><tbody>
        <tr<?= $rowAttr('field', 'staff_mgmt') ?>><?= $ctl() ?><td style="text-align:right">عدد القائمين بالإدارة التعليمية (مدير-مساعد-منسق-مشرف)</td><?= $ed('v', (int)$data['staff_mgmt'], (string)(int)$data['staff_mgmt'], 'n', 'text-align:center') ?></tr>
        <tr<?= $rowAttr('field', 'staff_supervision') ?>><?= $ctl() ?><td style="text-align:right">عدد القائمين بالنظارة</td><?= $ed('v', (int)$data['staff_supervision'], (string)(int)$data['staff_supervision'], 'n', 'text-align:center') ?></tr>
        <tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">عدد القائمين بالتدريس</td><td style="text-align:center"><?= (int)$s['staffTeaching'] ?></td></tr>
        <tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">عدد الداخلين في الملاك</td><td style="text-align:center"><?= (int)$s['staffInCadre'] ?></td></tr>
        <tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">عدد غير الداخلين في الملاك</td><td style="text-align:center"><?= (int)$s['staffOutCadre'] ?></td></tr>
        <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">إجمالي عدد الموظفين</td><td style="text-align:center"><?= (int)$s['staffTotal'] ?></td></tr>
    </tbody></table>
<?= $secEnd(5) ?>

<?= $secStart(6) ?>
    <?php $staffNames = implode('|', array_values(array_unique(array_filter(array_map(fn($r) => trim((string)$r['name']), array_merge($p['tit'], $p['con'], $p['adm'])))))); $classOpts = implode('|', array_map(fn($c) => 'منهاج لبناني-' . $c, array_merge(...array_values(meheClassesByLevel()))));
    $grRow = function (array $g, $i, bool $tpl = false) use ($ed, $ctl, $rowAttr, $fmt0, $staffNames, $classOpts): string {
        return '<tr' . ($tpl ? ' class="mtpl"' : '') . $rowAttr('list', 'grants|' . $i, $tpl) . ' data-cat="' . e((string)($g['cat'] ?? '')) . '">' . $ctl()
            . $ed('student', (string)($g['student'] ?? ''), e((string)($g['student'] ?? '')), 's', 'text-align:right;font-weight:700')
            . $ed('teacher', (string)($g['teacher'] ?? ''), e((string)($g['teacher'] ?? '')), 'sel2', 'text-align:right', $staffNames)
            . $ed('class', (string)($g['class'] ?? ''), e((string)($g['class'] ?? '')), 'sel2', 'text-align:right', $classOpts)
            . $ed('ll', (string)(float)($g['ll'] ?? 0), $fmt0($g['ll'] ?? 0), 'n', 'text-align:center')
            . $ed('usd', (string)(float)($g['usd'] ?? 0), $fmt0($g['usd'] ?? 0), 'n', 'text-align:center') . '</tr>';
    }; $gl = array_filter(array_values((array)$data['grants']), fn($g) => trim((string)($g['student'] ?? '')) !== ''); ?>
    <div class="tophead no-print"><div style="display:flex;flex-direction:column;gap:8px"><input class="srch" type="search" placeholder="بحث من خلال الاسم او عضو هيئة التدريس" oninput="meheFilter(this)"><select class="srch" onchange="meheFilterCat(this)"><option value="">اختر نمط الموظف</option><option value="ملاك">داخل الملاك</option><option value="تعاقد">تعاقد</option><option value="إداري">إداري</option></select></div><span class="badge-n">العدد الكلي : <?= count($gl) ?></span></div>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>اسم الطالب</th><th>عضو هيئة التدريس</th><th>الصف</th><th>المنحة المقدمة ل.ل</th><th>المنحة المقدمة د.أ</th></tr></thead><tbody>
    <?php foreach ($gl as $i => $g) echo $grRow($g, $i); ?>
    <?php if (!$gl): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="5" style="text-align:center">لا يوجد نتائج مطابقة</td></tr><?php endif; ?>
    <?php if ($E) echo $grRow(['cat' => 'ملاك'], 'new', true); ?>
    </tbody></table></div>
    <?php if ($E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ طالب معفى</button></div><?php endif; ?>
    <div class="grid2" style="margin-top:14px">
        <div><h4 class="sec">المنح الدراسية للمعلمين داخل الملاك:</h4><?= $boxRo('العدد', (string)$s['grTitN']) . $boxRo('إجمالي المنحة ل.ل', $fmt0($s['grTitLL'])) . $boxRo('إجمالي المنحة د.أ', $fmt0($s['grTitUSD'])) ?></div>
        <div><h4 class="sec">المنح الدراسية لبقية الكادر:</h4><?= $boxRo('العدد', (string)$s['grOthN']) . $boxRo('إجمالي المنحة ل.ل', $fmt0($s['grOthLL'])) . $boxRo('إجمالي المنحة د.أ', $fmt0($s['grOthUSD'])) ?></div>
    </div>
<?= $secEnd(6) ?>

<?= $secStart(7) ?>
    <h4 class="sec" style="margin-top:0">بيان تفصيلي بتعويضات الصرف القانونية التي دفعت عن السنة السابقة للداخلين في الملاك:</h4>
    <?php $svRow = function (array $x, $i, bool $tpl = false) use ($ed, $ctl, $rowAttr, $fmt0): string {
        $h = '<tr' . ($tpl ? ' class="mtpl"' : '') . $rowAttr('list', 'severance|' . $i, $tpl) . '>' . $ctl() . $ed('name', (string)($x['name'] ?? ''), e((string)($x['name'] ?? '')), 's', 'text-align:right;font-weight:700');
        foreach (['eos_ll', 'eos_usd', 'tasks_ll', 'tasks_usd'] as $f) $h .= $ed($f, (string)(float)($x[$f] ?? 0), $fmt0($x[$f] ?? 0), 'n', 'text-align:center');
        $h .= $ed('receipt_no', (string)($x['receipt_no'] ?? ''), e((string)($x['receipt_no'] ?? '')), 's', 'text-align:center') . $ed('receipt_date', (string)($x['receipt_date'] ?? ''), e((string)($x['receipt_date'] ?? '')), 's', 'text-align:center') . $ed('notes', (string)($x['notes'] ?? ''), e((string)($x['notes'] ?? '')), 's', 'text-align:right');
        return $h . '</tr>';
    }; $sl = array_filter(array_values((array)$data['severance']), fn($x) => trim((string)($x['name'] ?? '')) !== ''); ?>
    <div class="tophead no-print"><input class="srch" type="search" placeholder="بحث من خلال اسم المستفيد" oninput="meheFilter(this)"><span class="badge-n">العدد الكلي : <?= count($sl) ?></span></div>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>اسم المستفيد</th><th>تعويضات نهاية الخدمة ل.ل</th><th>تعويضات نهاية الخدمة د.أ</th><th>تعويضات المهام الإضافية ل.ل</th><th>تعويضات المهام الإضافية د.أ</th><th>رقم إيصال الدفع</th><th>تاريخ الإيصال</th><th>ملاحظات</th></tr></thead><tbody>
    <?php foreach ($sl as $i => $x) echo $svRow($x, $i); ?>
    <?php if (!$sl): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="8" style="text-align:center">لا يوجد نتائج مطابقة</td></tr><?php endif; ?>
    <?php if ($E) echo $svRow([], 'new', true); ?>
    </tbody></table></div>
    <?php if ($E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ مستفيد</button></div><?php endif; ?>
    <div style="max-width:560px;margin-inline-start:auto"><?= $boxRo('المجموع بالليرة', $fmt2($s['sevLL'])) . $boxRo('المجموع بالدولار', $fmt2($s['sevUSD'])) ?></div>
<?= $secEnd(7) ?>

<?= $secStart(8) ?>
    <h4 class="sec" style="margin-top:0">النفقات</h4>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>النفقة</th><th>القيمة بالليرة اللبنانية</th><th>القيمة بالدولار</th></tr></thead><tbody>
    <?php foreach (meheExpenseItems() as $k => [$lb, $cat]): ?><tr<?= $rowAttr('exp', $k) ?>><?= $ctl() ?><td style="text-align:right"><?= e($lb) ?></td><?= $ed('ll', (string)(float)($data['expenses'][$k]['ll'] ?? 0), $fmt0($data['expenses'][$k]['ll'] ?? 0), 'n', 'text-align:center') ?><?= $ed('usd', (string)(float)($data['expenses'][$k]['usd'] ?? 0), $fmt0($data['expenses'][$k]['usd'] ?? 0), 'n', 'text-align:center') ?></tr><?php endforeach; ?>
    <tr class="tot"><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td style="text-align:right">مجموع</td><td style="text-align:center"><?= $fmt2($s['expTotalLL']) ?></td><td style="text-align:center"><?= $fmt2($s['expTotalUSD']) ?></td></tr>
    </tbody></table></div>
<?= $secEnd(8) ?>

<?= $secStart(9) ?>
    <?php $rvRow = function (array $rv, $i, bool $tpl = false) use ($ed, $ctl, $rowAttr, $fmt0, $fmtLU): string {
        return '<tr' . ($tpl ? ' class="mtpl"' : '') . $rowAttr('list', 'revenues|' . $i, $tpl) . '>' . $ctl()
            . $ed('program', (string)($rv['program'] ?? ''), e((string)($rv['program'] ?? '')), 'sel2', 'text-align:right', implode('|', meheProgramOptions()))
            . $ed('class', (string)($rv['class'] ?? ''), e((string)($rv['class'] ?? '')), 's', 'text-align:right')
            . $ed('fee_ll', (string)(float)($rv['fee_ll'] ?? 0), $fmt0($rv['fee_ll'] ?? 0), 'n', 'text-align:center')
            . $ed('fee_usd', (string)(float)($rv['fee_usd'] ?? 0), $fmt0($rv['fee_usd'] ?? 0), 'n', 'text-align:center')
            . $ed('students', (string)(int)($rv['students'] ?? 0), (string)(int)($rv['students'] ?? 0), 'n', 'text-align:center')
            . '<td style="text-align:center;white-space:nowrap;font-weight:700">' . $fmtLU($rv['tot_ll'] ?? 0, $rv['tot_usd'] ?? 0) . '</td></tr>';
    }; ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><?= $ctlTh() ?><th>البرنامج</th><th>الصف</th><th>الرسوم الدراسيه ل.ل</th><th>الرسوم الدراسيه د.أ</th><th>عدد الطلاب</th><th>المجموع للصف</th></tr></thead><tbody>
    <?php foreach ($s['revRows'] as $rv) echo $rvRow($rv, $rv['idx']); ?>
    <?php if (!$s['revRows']): ?><tr><?= $E ? '<td class="rowctl no-print"></td>' : '' ?><td colspan="6" style="text-align:center">لا صفوف — أضف صفّاً بالزرّ تحت</td></tr><?php endif; ?>
    <?php if ($E) echo $rvRow(['program' => 'منهاج لبناني'], 'new', true); ?>
    </tbody></table></div>
    <?php if ($E): ?><div class="addrow no-print"><button type="button" class="btn btn-light btn-sm" onclick="meheAddSheetRow(this)">+ صف</button></div><?php endif; ?>
    <table class="doc-table" dir="rtl" style="width:100%;margin-top:14px"><tbody>
        <tr><td style="text-align:right;font-weight:700;width:60%">عدد الطلاب الكلي</td><td style="text-align:center"><?= (int)$s['students'] ?></td></tr>
        <tr><td style="text-align:right;font-weight:700">عدد طلاب المنح للمدرسين داخل الملاك</td><td style="text-align:center"><?= (int)$s['grTitN'] ?></td></tr>
        <tr><td style="text-align:right;font-weight:700">إجمالي الايرادات بعد حسم المنح الدراسية لأبناء المعلمين الملاك</td><td style="text-align:center;white-space:nowrap"><?= $fmtLU($s['revAfterLL'], $s['revAfterUSD']) ?></td></tr>
        <tr><td style="text-align:right;font-weight:700">متوسط الرسوم الدراسية</td><td style="text-align:center;white-space:nowrap"><?= $fmt2($s['avgFeeLL']) ?>LL <?= $fmt2($s['avgFeeUSD']) ?>USD</td></tr>
    </tbody></table>
<?= $secEnd(9) ?>

<?= $secStart(10) ?>
    <h4 class="sec" style="margin-top:0">النفقات من الفئة أ</h4><?= $money3($s['A'], 'A') ?>
    <h4 class="sec">النفقات من الفئة ب</h4><?= $money3($s['B'], 'B', true) ?>
    <h4 class="sec">النفقات من الفئة ج</h4><?= $money3($s['C'], 'C') ?>
    <h4 class="sec">النفقات من الفئة د</h4><?= $money3($s['D'], 'D', true) ?>
    <h4 class="sec">ملخص الموازنة</h4>
    <?php if ($E): /* ⚖️ وازِن: الفرق الحالي + 4 طرق — اقتراح بالأرقام ثمّ «طبّق» بموافقته */ ?>
    <div class="balbar no-print">
        <div class="balstat <?= $s['okBalance'] && $s['okRatio'] ? 'ok' : 'bad' ?>">
            <?php if ($s['okBalance'] && $s['okRatio']): ?>✅ الموازنة متوازنة وضمن النسب (ج <?= $fmt2($s['pctC']) ?> ٪ · أ+ب <?= $fmt2($s['pctAB']) ?> ٪)
            <?php else: ?>⚠️ <?= !$s['okBalance'] ? 'الفرق بين النفقات والإيرادات: ' . $fmt0($s['diffL']) . ' ل.ل' . ($s['diffU'] ? ' و' . $fmt0($s['diffU']) . ' $' : '') . ($s['diffL'] + $s['diffU'] > 0 ? ' (النفقات أكبر — عجز)' : ' (الإيرادات أكبر — فائض)') : '' ?><?= !$s['okRatio'] ? ($s['okBalance'] ? '' : ' · ') . 'بند ج تجاوز 35 ٪ بـ ' . $fmt0($s['cOverL']) . ' ل.ل' : '' ?><?php endif; ?>
        </div>
        <button type="button" class="btn btn-primary" onclick="meheBalToggle()">⚖️ وازِن</button>
        <div class="balpanel" id="balPanel" style="display:none">
            <div class="balopts">
                <label><input type="radio" name="balmode" value="c_item" checked> تعديل بند واحد من <b>ج</b> يسدّ الفرق (يتوقّف عند سقف 35 ٪)</label>
                <label><input type="radio" name="balmode" value="d_item"> تعديل بند واحد من <b>د</b> يسدّ الفرق (خارج النسبة)</label>
                <label><input type="radio" name="balmode" value="mix"> <b>مزيج</b>: ج حتى سقف 35 ٪ بالضبط والباقي إلى بند د</label>
                <label><input type="radio" name="balmode" value="fees"> تعديل <b>الأقساط</b> بنسبة واحدة على كل الصفوف (إذا كانت الأقساط حرّة)</label>
            </div>
            <div class="balsel">
                <label>بند ج: <select id="balC"><?php foreach (meheExpenseItems() as $k => [$lb, $cat]) if ($cat === 'ج') echo '<option value="' . e($k) . '"' . ($k === 'aid' ? ' selected' : '') . '>' . e($lb) . '</option>'; ?></select></label>
                <label>بند د: <select id="balD"><?php foreach (meheExpenseItems() as $k => [$lb, $cat]) if ($cat === 'د') echo '<option value="' . e($k) . '"' . ($k === 'eos_contr' ? ' selected' : '') . '>' . e($lb) . '</option>'; ?></select></label>
                <button type="button" class="btn btn-light" onclick="meheBalPlan()">احسب الاقتراح</button>
            </div>
            <div id="balOut"></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="report-table-wrap" dir="rtl"><table class="doc-table" dir="rtl"><thead><tr><th>المعيار</th><th>المجموع بالليرة</th><th>المجموع بالدولار</th><th>المجموع الكلي</th><th>ملاحظات</th></tr></thead><tbody>
        <tr><td style="text-align:right">مجموع البندين 'أ' و 'ب'</td><td style="text-align:center"><?= $fmt2($s['abL']) ?></td><td style="text-align:center"><?= $fmt2($s['abU']) ?></td><td style="text-align:center"><?= $fmt2($s['abL'] + $s['abU']) ?></td><td></td></tr>
        <tr><td style="text-align:right">مجموع البنود 'أ' و 'ب' و 'ج'</td><td style="text-align:center"><?= $fmt2($s['abcL']) ?></td><td style="text-align:center"><?= $fmt2($s['abcU']) ?></td><td style="text-align:center"><?= $fmt2($s['abcL'] + $s['abcU']) ?></td><td></td></tr>
        <?php /* ⚖️ القاعدة (2026-11-08): أ+ب ≥ 65 ٪ و ج ≤ 35 ٪ من (أ+ب+ج) — أخضر «امتثال كامل»، أحمر مع مقدار التجاوز */ ?>
        <tr><td style="text-align:right">ما يمثله مجموع البندين 'أ' و 'ب' من مجموع البنود 'أ' و 'ب' و 'ج'</td><td style="text-align:center">-</td><td style="text-align:center">-</td><td style="text-align:center"><?= $fmt2($s['pctAB']) ?> %</td><td class="bal-ab" style="text-align:center;color:<?= $s['okRatio'] ? '#2e7d32' : '#c00000' ?>"><?= $s['okRatio'] ? 'امتثال كامل' : 'تحت 65 %' ?></td></tr>
        <tr><td style="text-align:right">ما يمثله مجموع البند 'ج' من مجموع البنود 'أ' و 'ب' و 'ج'</td><td style="text-align:center">-</td><td style="text-align:center">-</td><td style="text-align:center"><?= $fmt2($s['pctC']) ?> %</td><td class="bal-c" style="text-align:center;color:<?= $s['okRatio'] ? '#2e7d32' : '#c00000' ?>"><?= $s['okRatio'] ? 'امتثال كامل' : 'تجاوز 35 % بـ ' . $fmt0($s['cOverL']) . ' ل.ل' ?></td></tr>
        <tr><td style="text-align:right">إجمالي النفقات (مجموع البنود 'أ' و 'ب' و 'ج' و 'د')</td><td style="text-align:center"><?= $fmt2($s['allL']) ?></td><td style="text-align:center"><?= $fmt2($s['allU']) ?></td><td style="text-align:center"><?= $fmt2($s['allL'] + $s['allU']) ?></td><td></td></tr>
        <tr><td style="text-align:right">إجمالي الايرادات بعد حسم المنح الدراسية لأبناء المعلمين الملاك</td><td style="text-align:center"><?= $fmt2($s['revAfterLL']) ?></td><td style="text-align:center"><?= $fmt2($s['revAfterUSD']) ?></td><td style="text-align:center"><?= $fmt2($s['revAfterLL'] + $s['revAfterUSD']) ?></td><td></td></tr>
        <tr><td style="text-align:right">الفرق بين النفقات والايرادات</td><td style="text-align:center"><?= $fmt2($s['diffL']) ?></td><td style="text-align:center"><?= $fmt2($s['diffU']) ?></td><td style="text-align:center"><?= $fmt2($s['diffL'] + $s['diffU']) ?></td><td style="text-align:center;color:<?= abs($s['diffL'] + $s['diffU']) < 1 ? '#2e7d32' : '#c00000' ?>"><?= abs($s['diffL'] + $s['diffU']) < 1 ? 'امتثال كامل' : 'غير متوازنة' ?></td></tr>
        <tr><td style="text-align:right">متوسط القسط المدرسي الواجب</td><td style="text-align:center"><?= $fmt2($s['avgDueL']) ?></td><td style="text-align:center"><?= $fmt2($s['avgDueU']) ?></td><td style="text-align:center"><?= $fmt2($s['avgDueL'] + $s['avgDueU']) ?></td><td></td></tr>
        <tr><td style="text-align:right">فرق الرسوم الدراسية عن العام الماضي</td><td style="text-align:center"><?= $s['feeDiffL'] === null ? '-' : $fmt2($s['feeDiffL']) ?></td><td style="text-align:center"><?= $s['feeDiffU'] === null ? '-' : $fmt2($s['feeDiffU']) ?></td><td style="text-align:center"><?= $s['feeDiffL'] === null ? '-' : $fmt2($s['feeDiffL'] + $s['feeDiffU']) ?></td><td style="text-align:center;font-size:12px"><?= $s['feeDiffL'] === null ? 'لا موازنة سابقة محفوظة' : 'مقارنةً بموازنة ' . e((string)$prevSaved['sy']) ?></td></tr>
    </tbody></table></div>
    <div class="sig"><span>توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)</span><span>توقيع مدير المدرسة</span></div>
<?= $secEnd(10) ?>

<?= $secStart(11) ?>
    <?php foreach (meheDocTypes() as $k => $lb): $doc = (array)($data['docs'][$k] ?? []); $has = !empty($doc['file']); ?>
    <div class="docrow">
        <span class="lb"><?= e($lb) ?></span>
        <?php if ($has): ?><a class="f" target="_blank" href="<?= BASE_URL ?>uploads/mehe/<?= e(rawurlencode((string)$doc['file'])) ?>"><?= e((string)($doc['orig'] ?? $doc['file'])) ?></a><small class="text-muted"><?= e((string)($doc['at'] ?? '')) ?></small><?php endif; ?>
        <?php if ($E): ?>
        <form method="POST" enctype="multipart/form-data" class="no-print" style="display:flex;gap:6px;align-items:center;margin:0"><?= csrfField() ?><input type="hidden" name="mehe_doc" value="<?= e($k) ?>"><input type="file" name="doc" accept=".pdf,.jpg,.jpeg,.png" required><button class="btn btn-light btn-sm" type="submit">رفع</button></form>
        <?php if ($has): ?><form method="POST" class="no-print" style="margin:0" onsubmit="return confirm('حذف هذه الوثيقة من الموازنة؟')"><?= csrfField() ?><input type="hidden" name="mehe_doc" value="<?= e($k) ?>"><input type="hidden" name="mehe_doc_del" value="1"><button class="btn btn-light btn-sm" type="submit" style="color:#991b1b">حذف</button></form><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?= $secEnd(11) ?>

</div></div>
</div>
<script>
/* 🏛️🖥️ التنقّل بين الأقسام كالمنصّة: قسم واحد ظاهر، القائمة الجانبية تضيء، #sec=N بالرابط، والطباعة تطلع الأقسام كلّها */
function meheGo(i){
    var secs=document.querySelectorAll('.mehe-sec'), n=secs.length; i=Math.max(0,Math.min(n-1,i|0));
    secs.forEach(function(s,k){ s.classList.toggle('on',k===i); });
    document.querySelectorAll('.mehe-nav a').forEach(function(a){ a.classList.toggle('on',parseInt(a.dataset.sec,10)===i); });
    try{ history.replaceState(null,'','#sec='+i); sessionStorage.setItem('meheSec',String(i)); }catch(e){}
    var card=document.querySelector('.mehe-app'); if(card && window.scrollY>card.offsetTop) window.scrollTo(0, Math.max(0, card.offsetTop-8));
}
(function(){ var m=(location.hash||'').match(/sec=(\d+)/), i=m?parseInt(m[1],10):null; if(i===null){ try{ var s=sessionStorage.getItem('meheSec'); if(s!==null) i=parseInt(s,10); }catch(e){} } meheGo(i||0); })();
/* 🔢 «بفواصل / بلا فواصل» (2026-11-08): على الشاشة فقط — كل رقم بفواصل آلاف داخل الموازنة يُعرض بلا فواصل لينسخه ويلصقه بخانات منصّة الوزارة.
   الأصل يُحفظ بـdata-sepv؛ الطباعة/PDF/وورد/واتساب/بريد ترجع الفواصل مؤقّتاً. الاختيار يبقى على هذا الجهاز (localStorage). */
var MEHE_SEP_RE=/\d{1,3}(?:,\d{3})+(?:\.\d+)?/g;
function meheSepApply(on){
    var root=document.querySelector('.mehe-doc'); if(!root) return;
    var tw=document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode:function(n){ var p=n.parentElement; if(!p||p.closest('input,textarea,select,script,style,.mi')) return NodeFilter.FILTER_REJECT; return NodeFilter.FILTER_ACCEPT; } });
    var nodes=[], n; while((n=tw.nextNode())) nodes.push(n);
    nodes.forEach(function(tn){
        if(!on){ if(MEHE_SEP_RE.test(tn.nodeValue)){ MEHE_SEP_RE.lastIndex=0; if(tn.__sepv===undefined) tn.__sepv=tn.nodeValue; tn.nodeValue=tn.__sepv.replace(MEHE_SEP_RE,function(m){ return m.replace(/,/g,''); }); } MEHE_SEP_RE.lastIndex=0; }
        else if(tn.__sepv!==undefined){ tn.nodeValue=tn.__sepv; delete tn.__sepv; }
    });
    document.querySelectorAll('.mehe-sep button').forEach(function(b){ var act=(b.dataset.sep==='1')===on; b.classList.toggle('btn-primary',act); b.classList.toggle('btn-light',!act); });
    document.body.classList.toggle('mehe-nosep',!on);
}
function meheSep(on){ try{ localStorage.setItem('mehe_sep', on?'1':'0'); }catch(e){} meheSepApply(on); }
(function(){
    var on=true; try{ on=localStorage.getItem('mehe_sep')!=='0'; }catch(e){}
    meheSepApply(on);
    // الطباعة والتصدير بفواصل دائماً: نرجّعها قبل، ونعيد خيار الشاشة بعد
    var restoreThen=function(fn){ return function(){ var cur=document.body.classList.contains('mehe-nosep'); if(cur) meheSepApply(true); var r; try{ r=fn.apply(this,arguments); } finally { if(cur) setTimeout(function(){ meheSepApply(false); },1500); } return r; }; };
    ['msaSavePdfStart','ppWord','ppWhatsApp','ppEmail'].forEach(function(k){ if(typeof window[k]==='function' && !window[k].__meheSep){ var o=window[k]; window[k]=restoreThen(o); window[k].__meheSep=true; } });
    window.addEventListener('beforeprint', function(){ if(document.body.classList.contains('mehe-nosep')){ meheSepApply(true); document.body.classList.add('mehe-nosep-print'); } });
    window.addEventListener('afterprint', function(){ if(document.body.classList.contains('mehe-nosep-print')){ document.body.classList.remove('mehe-nosep-print'); meheSepApply(false); } });
})();
/* بحث داخل جدول القسم (الاسم أو المعرّف) + فلتر نمط الموظف بقائمة المعفيين */
function meheFilter(inp){ var q=inp.value.trim().toLowerCase(), sec=inp.closest('.mehe-sec'); sec.querySelectorAll('tbody tr[data-mrow]:not(.mtpl)').forEach(function(tr){ tr.style.display=(!q||tr.textContent.toLowerCase().indexOf(q)>=0)?'':'none'; }); }
function meheFilterCat(sel){ var v=sel.value, sec=sel.closest('.mehe-sec'); sec.querySelectorAll('tbody tr[data-mrow]:not(.mtpl)').forEach(function(tr){ var c=tr.dataset.cat||''; var ok=!v||(v==='ملاك'?c==='ملاك':c!=='ملاك'); tr.style.display=ok?'':'none'; }); }
</script>
<?php if ($E): ?>
<script>
/* ✏️💾 «قدام كل سطر من صفحات الموازنة تعديل وحفظ» (2026-09-07): كل عنصر data-mrow سطر؛ خاناته data-f.
   تعديل ⇒ الخانات تصير حقولاً؛ حفظ ⇒ تُرسل القيم المتغيّرة فقط (fetch) ثم تُعاد الصفحة بنفس القسم والموضع؛
   ↺ تلقائي ⇒ يمسح القيم اليدوية للسطر فيرجع محسوباً من الرواتب. ⊘ ⇒ استثناء الموظف من الموازنة (↩ إرجاع من اللائحة تحت الجدول). */
(function(){
    var CSRF = <?= json_encode(csrfToken()) ?>;
    function mk(c){ var t=c.dataset.t||'s', v=c.dataset.v||'', inp;
        if(t==='sel'){ inp=document.createElement('select'); (c.dataset.o||'').split(',').forEach(function(o){ var p=o.split(':'); inp.add(new Option(p[1]||p[0], p[0], false, p[0]===v)); }); }
        else if(t==='sel2'){ inp=document.createElement('select'); var found=false; inp.add(new Option('—','',false,v==='')); (c.dataset.o||'').split('|').forEach(function(o){ if(o===v) found=true; inp.add(new Option(o,o,false,o===v)); }); if(v!=='' && !found) inp.add(new Option(v,v,false,true)); }
        else if(t==='chk'){ // خانات تفتيش «أ|ب|ج» ⇒ حقل مخفي .mi يحمل المختار
            var wrap=document.createElement('div'); wrap.className='chkedit'; var sel=v?v.split('|'):[]; var hid=document.createElement('input'); hid.type='hidden'; hid.className='mi'; hid.value=v;
            (c.dataset.o||'').split('|').forEach(function(o){ var lb=document.createElement('label'); var cb=document.createElement('input'); cb.type='checkbox'; cb.value=o; cb.checked=sel.indexOf(o)>=0;
                cb.onchange=function(){ var vals=[]; wrap.querySelectorAll('input[type=checkbox]').forEach(function(x){ if(x.checked) vals.push(x.value); }); hid.value=vals.join('|'); }; lb.appendChild(cb); lb.appendChild(document.createTextNode(o)); wrap.appendChild(lb); });
            wrap.appendChild(hid); return wrap; }
        else if(t==='ta'){ inp=document.createElement('textarea'); inp.value=v; }
        else { inp=document.createElement('input'); inp.type='text'; inp.value=v; if(t==='n'){ inp.inputMode='decimal'; inp.style.textAlign='center'; inp.dir='ltr'; } }
        inp.className='mi'; return inp; }
    function open(row){ row.querySelectorAll('[data-f]').forEach(function(c){ if(c.querySelector('.mi')) return; c.dataset.html=c.innerHTML; c.innerHTML=''; c.appendChild(mk(c)); });
        var f=row.querySelector('.mi:not([type=hidden])'); if(f){ f.focus(); if(f.select) f.select(); } }
    function close(row){ row.querySelectorAll('[data-f]').forEach(function(c){ if(c.dataset.html!==undefined){ c.innerHTML=c.dataset.html; delete c.dataset.html; } }); }
    /* القيم المتغيّرة بسطر مفتوح ⇒ {mehe_row,key,f} أو null إن لم يتغيّر شيء */
    function changesOf(row){
        var p=row.dataset.mrow.split('|'), it={mehe_row:p.shift(), key:p.join('|'), f:{}}, changed=0, isNew=!!row.dataset.new;
        if(it.mehe_row==='months2') it.key=it.key.replace(/\|t$/,'');
        row.querySelectorAll('[data-f]').forEach(function(c){ var i=c.querySelector('.mi'); if(!i) return; if(isNew||i.value!==c.dataset.v){ it.f[c.dataset.f]=i.value; changed++; } });
        return changed ? it : null;
    }
    /* سطر قائمة خانته الإلزامية فارغة ⇒ سيُحذف: تأكيد */
    function confirmDelete(rows){
        var del=0;
        rows.forEach(function(row){ if(row.dataset.new || row.dataset.mrow.split('|')[0]!=='list') return; var reqf={revenues:'class',grants:'student',severance:'name',manual_admins:'name'}[row.dataset.mrow.split('|')[1]]; var rc=row.querySelector('[data-f="'+reqf+'"] .mi'); if(rc && rc.value.trim()==='') del++; });
        return !del || confirm(del===1 ? 'الخانة الأساسية فارغة — السطر رح ينحذف نهائياً. متأكد؟' : 'في '+del+' أسطر خانتها الأساسية فارغة — رح تنحذف نهائياً. متأكد؟');
    }
    function reloadKeep(){ try{ sessionStorage.setItem('meheScroll', String(window.scrollY)); }catch(e){} location.reload(); }
    function post(fd, onFail){
        fetch(location.pathname, {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'fetch'}})
            .then(function(r){ return r.json(); })
            .then(function(j){ if(j && j.ok) reloadKeep(); else { alert((j && j.msg) || 'تعذّر الحفظ'); onFail(); } })
            .catch(function(){ alert('تعذّر الحفظ — تحقّق من الاتصال'); onFail(); });
    }
    function save(row, reset){
        var fd=new FormData(); fd.append('csrf',CSRF);
        var it=reset ? null : changesOf(row);
        if(!reset && !it){ close(row); row.querySelector('.rowctl').classList.remove('editing'); return; }
        var p=row.dataset.mrow.split('|'); var kind=p.shift(), key=p.join('|'); if(kind==='months2') key=key.replace(/\|t$/,'');
        fd.append('mehe_row',kind); fd.append('key',key); if(reset) fd.append('reset','1');
        if(it) Object.keys(it.f).forEach(function(k){ fd.append('f['+k+']', it.f[k]); });
        if(!reset && !confirmDelete([row])) return;
        var btns=row.querySelectorAll('.rowctl button'); btns.forEach(function(b){ b.disabled=true; });
        post(fd, function(){ btns.forEach(function(b){ b.disabled=false; }); });
    }
    /* ⊘ استثناء / ↩ إرجاع موظف */
    function exclude(eid, on){ var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mehe_row','exclude'); fd.append('key','e'+eid); fd.append('f[v]', on?'1':'0'); post(fd, function(){}); }
    /* 📄✏️ (2026-11-06 «كبسة تعديل لكل صفحة بصفحتها وبعدين حفظ») شريط فوق كل قسم: يفتح كل أسطره، ويحفظها كلّها بطلب واحد */
    function sheetRows(sh){ return Array.prototype.filter.call(sh.querySelectorAll('[data-mrow]'), function(r){ return !r.classList.contains('mtpl') && r.querySelector('.rowctl .ed'); }); }
    function openSheet(sh, bar){ var rows=sheetRows(sh); rows.forEach(function(row){ var ctl=row.querySelector('.rowctl'); full(ctl); open(row); ctl.classList.add('editing'); }); bar.classList.add('editing');
        if(!rows.length){ var add=sh.querySelector('.addrow button'); if(add) add.click(); }   // قسم فارغ (منح/صرف): أوّل سطر جديد يُفتح مباشرة
        var f=sh.querySelector('.mi:not([type=hidden])'); if(f){ f.focus(); if(f.select) f.select(); } }
    function cancelSheet(sh, bar){ sheetRows(sh).forEach(function(row){ cancel(row, row.querySelector('.rowctl')); }); bar.classList.remove('editing'); }
    function saveSheet(sh, bar){
        var rows=sheetRows(sh).filter(function(r){ return r.querySelector('.mi'); }), items=[];
        rows.forEach(function(row){ var it=changesOf(row); if(it) items.push(it); });
        if(!items.length){ cancelSheet(sh, bar); return; }
        if(!confirmDelete(rows)) return;
        var btns=bar.querySelectorAll('button'); btns.forEach(function(b){ b.disabled=true; });
        var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mehe_rows', JSON.stringify(items));
        post(fd, function(){ btns.forEach(function(b){ b.disabled=false; }); });
    }
    document.querySelectorAll('.mehe-doc .doc-sheet').forEach(function(sh){
        if(!sheetRows(sh).length && !sh.querySelector('tr.mtpl')) return;
        var bar=document.createElement('div'); bar.className='sheetctl no-print';
        bar.innerHTML='<button type="button" class="btn btn-primary ed">✏️ تعديل الصفحة</button><button type="button" class="btn btn-success sv">💾 حفظ الصفحة</button><button type="button" class="btn btn-light cx">✖ إلغاء</button><span class="hint">عدّل ما تشاء بهذا القسم ثم «حفظ الصفحة» — تُحفظ كل الأسطر دفعة واحدة</span>';
        var ttl=sh.querySelector('.secttl'); if(ttl && ttl.nextSibling) sh.insertBefore(bar, ttl.nextSibling); else sh.appendChild(bar);
        bar.querySelector('.ed').onclick=function(){ openSheet(sh, bar); };
        bar.querySelector('.sv').onclick=function(){ saveSheet(sh, bar); };
        bar.querySelector('.cx').onclick=function(){ cancelSheet(sh, bar); };
    });
    // 🚀 (2026-10-03): مستمع واحد للصفحة كلها (بدل 4 مستمعات لكل سطر عند كل فتحة)، و«حفظ/إلغاء» يُخلقان للسطر عند أوّل «تعديل»
    function full(ctl){ if(ctl.querySelector('.sv')) return;
        var sv=document.createElement('button'); sv.type='button'; sv.className='sv'; sv.title='حفظ / Enregistrer'; sv.textContent='💾 حفظ';
        var cx=document.createElement('button'); cx.type='button'; cx.className='cx'; cx.title='إلغاء'; cx.textContent='✖';
        var ed=ctl.querySelector('.ed'); ctl.insertBefore(cx, ed.nextSibling); ctl.insertBefore(sv, cx); }
    function cancel(row, ctl){ if(row.dataset.new){ row.remove(); return; } close(row); ctl.classList.remove('editing'); }
    var doc=document;
    doc.addEventListener('click', function(ev){
        var xr=ev.target.closest ? ev.target.closest('button.xr') : null; if(xr){ exclude(xr.dataset.eid, false); return; }
        var b=ev.target.closest ? ev.target.closest('.rowctl button') : null; if(!b) return;
        var row=b.closest('[data-mrow]'), ctl=b.closest('.rowctl'); if(!row||!ctl) return;
        if(b.classList.contains('ed')){ full(ctl); open(row); ctl.classList.add('editing'); }
        else if(b.classList.contains('cx')){ cancel(row, ctl); }
        else if(b.classList.contains('sv')){ save(row,false); }
        else if(b.classList.contains('rs')){ if(confirm('رجوع القيم التلقائية من الرواتب لهذا السطر؟')) save(row,true); }
        else if(b.classList.contains('xc')){ var nm=(row.querySelector('[data-f="name"]')||{}).textContent||''; if(confirm('استثناء «'+nm.trim()+'» من هذه الموازنة؟ (يرجع من لائحة المستثنين تحت الجدول)')) exclude(row.dataset.mrow.split('|')[1].replace(/^e/,''), true); }
    });
    doc.addEventListener('keydown', function(ev){
        if(!(ev.target.classList && ev.target.classList.contains('mi'))) return;
        var row=ev.target.closest('[data-mrow]'); if(!row) return;
        var sh=row.closest('.doc-sheet'), bar=sh ? sh.querySelector('.sheetctl.editing') : null;   // القسم كلّه مفتوح ⇒ Enter/Escape على القسم
        if(ev.key==='Enter' && ev.target.tagName!=='TEXTAREA'){ ev.preventDefault(); if(bar) saveSheet(sh, bar); else save(row,false); }
        if(ev.key==='Escape'){ if(bar) cancelSheet(sh, bar); else cancel(row, row.querySelector('.rowctl')); }
    });
    /* ⚖️ وازِن: اقتراح ثمّ تطبيق بموافقته */
    var BAL = <?= json_encode(['abL' => $s['abL'], 'abU' => $s['abU'], 'cSumL' => $s['cSumL'], 'cSumU' => $s['cSumU'], 'cMaxL' => $s['cMaxL'], 'cMaxU' => $s['cMaxU'], 'cats' => array_map(fn($x) => $x[1], meheExpenseItems())], JSON_UNESCAPED_UNICODE) ?>;
    var fmt=function(v){ v=Math.round(Number(v)||0); return (v<0?'-':'')+String(Math.abs(v)).replace(/\B(?=(\d{3})+(?!\d))/g,','); };
    window.meheBalToggle=function(){ var p=document.getElementById('balPanel'); p.style.display=p.style.display==='none'?'block':'none'; };
    function balForm(){ var fd=new FormData(); fd.append('csrf',CSRF); fd.append('mehe_balance','1'); var m=document.querySelector('input[name=balmode]:checked'); fd.append('mode', m?m.value:'c_item'); fd.append('c_key', document.getElementById('balC').value); fd.append('d_key', document.getElementById('balD').value); return fd; }
    window.meheBalPlan=function(){
        var out=document.getElementById('balOut'); out.className='balout'; out.innerHTML='<span class="text-muted">جارٍ الحساب…</span>';
        fetch(location.pathname,{method:'POST',body:balForm(),credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
            if(!j||!j.ok){ out.innerHTML='<span class="bad">'+((j&&j.msg)||'تعذّر الحساب')+'</span>'; return; }
            var h='';
            if(!j.changes.length){ h+='<div class="after ok">لا تعديلات مطلوبة — الموازنة كما هي.</div>'; }
            else { h+='<table class="doc-table" dir="rtl"><thead><tr><th>البند</th><th>الحالي</th><th>المقترح</th><th>الفرق</th></tr></thead><tbody>';
                j.changes.forEach(function(c){ h+='<tr><td style="text-align:right">'+c.label+(c.field==='usd'||c.field==='fee_usd'?' ($)':'')+'</td><td style="text-align:center">'+fmt(c.old)+'</td><td style="text-align:center;font-weight:700">'+fmt(c.new)+'</td><td style="text-align:center">'+fmt(c.new-c.old)+'</td></tr>'; });
                h+='</tbody></table>'; }
            var a=j.after; h+='<div class="after '+(a.okBalance&&a.okRatio?'ok':'bad')+'">بعد التطبيق: الفرق '+fmt(a.diffL)+' ل.ل'+(a.diffU?' و'+fmt(a.diffU)+' $':'')+' · ج '+a.pctC+' ٪ · أ+ب '+a.pctAB+' ٪ — '+(a.okBalance&&a.okRatio?'امتثال كامل':(a.okBalance?'متوازنة لكن ج فوق 35 ٪':'ما زالت غير متوازنة'))+'</div>';
            (j.notes||[]).forEach(function(n){ h+='<div class="text-muted" style="font-size:12.5px">• '+n+'</div>'; });
            if(j.changes.length) h+='<div style="margin-top:8px"><button type="button" class="btn btn-success" onclick="meheBalApply()">✔ طبّق</button> <button type="button" class="btn btn-light" onclick="document.getElementById(\'balOut\').innerHTML=\'\'">إلغاء</button></div>';
            out.innerHTML=h;
        }).catch(function(){ out.innerHTML='<span class="bad">تعذّر الحساب — تحقّق من الاتصال</span>'; });
    };
    window.meheBalApply=function(){ if(!confirm('تطبيق الاقتراح وحفظه بالموازنة؟')) return; var fd=balForm(); fd.append('apply','1'); post(fd, function(){}); };
    /* تنبيه فوري أثناء تعديل بند من ج (بقسمَي التشغيل والملخّص): النسبة الجديدة قبل الحفظ */
    function balLive(sh){
        var sumL=BAL.cSumL, sumU=BAL.cSumU;
        sh.querySelectorAll('[data-mrow^="exp|"]').forEach(function(row){ var key=row.dataset.mrow.split('|')[1]; if(BAL.cats[key]!=='ج') return;
            row.querySelectorAll('[data-f] .mi').forEach(function(i){ var c=i.closest('[data-f]'); var o=parseFloat(String(c.dataset.v||'0').replace(/,/g,''))||0, n=parseFloat(String(i.value||'0').replace(/,/g,''))||0; if(c.dataset.f==='ll') sumL+=n-o; else if(c.dataset.f==='usd') sumU+=n-o; }); });
        var note=sh.querySelector('.ballive'); if(!note){ note=document.createElement('div'); note.className='ballive'; var bar=sh.querySelector('.sheetctl'); (bar&&bar.parentNode?bar.parentNode:sh).insertBefore(note, bar?bar.nextSibling:sh.firstChild); }
        var over=(sumL-BAL.cMaxL)+(sumU-BAL.cMaxU), tot=BAL.abL+BAL.abU+sumL+sumU, pct=tot>0?((sumL+sumU)/tot*100):0;
        if(over>0.5){ note.className='ballive bad'; note.textContent='⚠️ بند ج سيصير '+pct.toFixed(2)+' ٪ — تجاوز 35 ٪ بـ '+fmt(over)+' ل.ل (أ+ب تحت 65 ٪). قلّل البند أو انقل الزيادة إلى بند د'; }
        else { note.className='ballive ok'; note.textContent='✓ بند ج '+pct.toFixed(2)+' ٪ — ضمن 35 ٪ (المتبقّي قبل السقف '+fmt(-over)+' ل.ل)'; }
    }
    doc.addEventListener('input', function(ev){ if(!(ev.target.classList&&ev.target.classList.contains('mi'))) return; var row=ev.target.closest('[data-mrow^="exp|"]'); if(!row) return; var sh=row.closest('.doc-sheet'); if(sh) balLive(sh); });
    window.meheAddSheetRow=function(btn){ var tbl=btn.parentNode.previousElementSibling.querySelector('table'); var tpl=tbl.querySelector('tr.mtpl'); if(!tpl) return;
        var tr=tpl.cloneNode(true); tr.classList.remove('mtpl'); tr.dataset.new='1';
        tbl.querySelectorAll('tbody tr:not([data-mrow]):not(.tot):not(.mtpl):not(.mrow)').forEach(function(ph){ ph.style.display='none'; }); // صفّ «لا نتائج»
        tpl.parentNode.insertBefore(tr, tpl); tr.querySelector('.rowctl .ed').click(); };
    try{ var sc=sessionStorage.getItem('meheScroll'); if(sc!==null){ sessionStorage.removeItem('meheScroll'); window.scrollTo(0, parseInt(sc,10)||0); } }catch(e){}
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
