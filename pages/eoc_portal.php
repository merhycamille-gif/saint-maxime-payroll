<?php
/**
 * 🏛️💼 بوّابة صندوق التعويضات — نسخة طبق الأصل عن منصّة الصندوق www.caisseindemnite.com (بوابة المدارس) داخل البرنامج
 * (طلبه 2026-11-08 «بدي ياه طبق الأصل… وأقدر رحّلو من البرنامج على الموقع… دايماً خيارات»).
 *  - الصفحات الثلاث نفسها (الرئيسية / البيان العام / البيان المالي) بنفس الشريط الكحلي والتبويبات والبطاقات والجداول وأسماء الخانات.
 *  - الأسطر تُحسب من داتا البرنامج (includes/eoc_portal.php)، وكل سطر قدّامه ✎ تعديل و🗑 حذف كالمنصّة + «↺ تلقائي» يرجّعه محسوباً.
 *  - الطباعة / PDF / إكسل / وورد / واتساب / بريد + «بفواصل / بلا فواصل» + تصدير JSON لمساعد المتصفّح الذي يعبّئ المنصّة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/eoc_portal.php';
requireLogin();

$db = getDB();
$currentPage = 'eoc_portal';
$pageTitle = 'بوّابة صندوق التعويضات';
$hideExportToolbar = true; $ownExportRow = true;
$ids = activeSchoolIds(); if (!$ids) $ids = allActiveSchoolIdsCached(); $ids = array_values(array_map('intval', $ids));
$schools = $ids ? $db->query("SELECT * FROM schools WHERE id IN (" . implode(',', $ids) . ") AND is_deleted = 0 ORDER BY FIELD(id," . implode(',', $ids) . ")")->fetchAll() : [];
if (!$schools) { $_SESSION['flash_error'] = 'لا مدارس بالنطاق المختار / Aucune école.'; header('Location: ' . BASE_URL . 'pages/reports.php'); exit; }
$multi = count($schools) > 1;
$school = $multi ? ['id' => (int)$schools[0]['id'], 'name_ar' => implode(' + ', array_map(fn($x) => $x['name_ar'], $schools)), 'name_fr' => implode(' + ', array_map(fn($x) => $x['name_fr'], $schools)), 'caisse_number' => implode(' / ', array_filter(array_map(fn($x) => trim((string)$x['caisse_number']), $schools))), 'phone' => $schools[0]['phone'], 'address' => $schools[0]['address'], 'email' => $schools[0]['email']] : $schools[0];
$E = canEdit();
$self = BASE_URL . 'pages/eoc_portal.php';
$tab = $_GET['tab'] ?? 'home'; if (!in_array($tab, ['home', 'general', 'financial'], true)) $tab = 'home';
$period = trim((string)($_GET['period'] ?? ''));
$kind = $tab === 'general' ? 'general' : ($tab === 'financial' ? 'financial' : '');
$validPeriod = $kind === 'general' ? (bool)preg_match('/^\d{4}-\d{4}$/', $period) : ($kind === 'financial' ? eocPeriodInfo($period) !== null : false);
$back = fn(array $q = []) => $self . '?' . http_build_query(array_filter(['tab' => $tab, 'period' => $period] + $q, fn($v) => $v !== '' && $v !== null));

/* ===== الكتابة (POST) — كالمنصّة: حفظ ثمّ عودة إلى نفس الصفحة ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kind && $validPeriod) {
    if (!$E) { $_SESSION['flash_error'] = 'قراءة فقط'; header('Location: ' . $back()); exit; }
    if (!verifyCsrf($_POST['csrf'] ?? '')) { $_SESSION['flash_error'] = 'رمز الأمان غير صحيح — أعد تحميل الصفحة'; header('Location: ' . $back()); exit; }
    $data = eocLoad($db, $ids, $kind, $period);
    $act = (string)($_POST['eoc_act'] ?? '');
    $locked = ($data['status'] ?? 'open') === 'locked';
    if ($act === 'status') { $data['status'] = $locked ? 'open' : 'locked'; $_SESSION['flash_success'] = $locked ? 'فُتح البيان للتعديل' : 'اعتُمد البيان وأُقفل'; }
    elseif ($locked) { $_SESSION['flash_error'] = 'البيان معتمد — مقفل. افتحه أوّلاً للتعديل.'; header('Location: ' . $back()); exit; }
    elseif ($act === 'step1') { $data['phone'] = trim((string)($_POST['phone'] ?? '')); $st = (string)($_POST['school_type'] ?? 'non_free'); $data['school_type'] = isset(eocSchoolTypes()[$st]) ? $st : 'non_free'; $_SESSION['flash_success'] = 'حُفظت معلومات المدرسة'; }
    elseif ($act === 'line') { $res = eocApplyLine($data, $kind, $_POST); if ($res['ok']) $_SESSION['flash_success'] = $res['msg']; else { $_SESSION['flash_error'] = $res['msg']; header('Location: ' . $back(['edit' => (string)($_POST['key'] ?? ''), 'add' => ($_POST['key'] ?? '') === '' ? 1 : null])); exit; } }
    elseif ($act === 'del') { eocDeleteLine($data, (string)($_POST['key'] ?? '')); $_SESSION['flash_success'] = 'حُذف السطر من البيان'; }
    elseif ($act === 'restore') { eocRestoreLine($data, (string)($_POST['key'] ?? '')); $_SESSION['flash_success'] = 'أُرجع السطر'; }
    elseif ($act === 'reset') { eocResetLine($data, (string)($_POST['key'] ?? '')); $_SESSION['flash_success'] = 'رجع السطر محسوباً من البرنامج'; }
    elseif ($act === 'new') { $_SESSION['flash_success'] = 'أُنشئ البيان ' . $period; }
    eocSave($db, $ids, $kind, $period, $data);
    header('Location: ' . $back() . '#sp-list'); exit;
}

/* ===== البيانات المعروضة ===== */
$data = $kind && $validPeriod ? eocLoad($db, $ids, $kind, $period) : null;
if ($data && $kind === 'general' && $data['phone'] === '') $data['phone'] = preg_replace('/\D+/', '', (string)($school['phone'] ?? ''));
$rows = $data ? ($kind === 'general' ? eocGeneralRows($db, $ids, $period, $data) : eocFinancialRows($db, $ids, $period, $data)) : [];
$pi = $kind === 'financial' && $validPeriod ? eocPeriodInfo($period) : null;
$fmt0 = fn($v) => number_format((float)str_replace([',', ' '], '', (string)$v), 0);
$numv = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
$amtM = function ($v) use ($fmt0, $numv): string { $n = $numv($v); if ($n >= 1000000 && fmod($n, 1000000) == 0) return '<span class="num sp-amt" data-full="' . $fmt0($n) . '">' . ($n / 1000000) . 'M</span>'; return $fmt0($n); }; // المنصّة تختصر الأجر الإضافي «54M»

/* فلتر وترتيب كالمنصّة */
$emp = $_GET['emp'] ?? 'all'; $sort = $_GET['sort'] ?? 'name'; $search = trim((string)($_GET['search'] ?? ''));
if ($kind === 'general' && in_array($emp, ['staff', 'contract'], true)) $rows = array_values(array_filter($rows, fn($r) => $r['employment_type'] === $emp));
if ($search !== '') $rows = array_values(array_filter($rows, fn($r) => mb_stripos(eocNorm((string)$r['teacher_name']), eocNorm($search)) !== false || strpos((string)$r['teacher_no'], $search) !== false));
$sorters = ['teacher_no' => fn($a, $b) => strnatcmp((string)$a['teacher_no'], (string)$b['teacher_no']), 'salary' => fn($a, $b) => $numv($b[$kind === 'general' ? 'salary' : 'current_salary']) <=> $numv($a[$kind === 'general' ? 'salary' : 'current_salary']),
    'hours' => fn($a, $b) => $numv($b['teaching_hours'] ?? 0) <=> $numv($a['teaching_hours'] ?? 0), 'deduction' => fn($a, $b) => $numv($b['teacher_q_disc'] ?? 0) <=> $numv($a['teacher_q_disc'] ?? 0), 'mismatch' => fn($a, $b) => count($b['missing']) <=> count($a['missing'])];
if (isset($sorters[$sort])) usort($rows, $sorters[$sort]);

/* ===== لائحة البيانات المتاحة (JSON) لمساعد المتصفّح: السنوات والفصول مع ما حُفظ منها ===== */
if (($_GET['export'] ?? '') === 'json' && !$data) {
    $sg = eocSavedMap($db, $ids, 'general'); $sf = eocSavedMap($db, $ids, 'financial');
    $gen = []; foreach (array_slice(eocSchoolYears($db, $ids), 0, 6) as $p) $gen[] = ['period' => $p, 'label' => 'البيان العام ' . $p, 'saved' => isset($sg[$p]), 'status' => $sg[$p]['status'] ?? 'open'];
    $fin = []; foreach (array_slice(eocFinancialPeriods($db, $ids), 0, 14) as $p) { $pio = eocPeriodInfo($p); $fin[] = ['period' => $p, 'label' => 'البيان المالي ' . $p . ' — ' . ($pio['label'] ?? '') . ' (' . ($pio['range'] ?? '') . ')', 'saved' => isset($sf[$p]), 'status' => $sf[$p]['status'] ?? 'open']; }
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['school' => $school['name_ar'], 'school_no' => (string)($school['caisse_number'] ?? ''), 'general' => $gen, 'financial' => $fin], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
/* ===== تصدير JSON لمساعد المتصفّح (نفس أسماء خانات المنصّة) ===== */
if (($_GET['export'] ?? '') === 'json' && $data) {
    $out = ['kind' => $kind, 'period' => $period, 'school' => $school['name_ar'], 'school_no' => (string)($school['caisse_number'] ?? ''), 'generated' => date('c'), 'status' => $data['status'],
        'phone' => $data['phone'] ?? '', 'school_type' => $data['school_type'] ?? 'non_free', 'lines' => []];
    foreach ($rows as $r) {
        if ($kind === 'general') { [$by, $bm, $bd] = eocYmd($r['birth_date']); [$sy_, $sm, $sd] = eocYmd($r['start_date']);
            $out['lines'][] = ['employment_type' => $r['employment_type'], 'teacher_no' => (string)$r['teacher_no'], 'teacher_name' => (string)$r['teacher_name'], 'father_name' => (string)$r['father_name'], 'birth_day' => (string)$bd, 'birth_month' => (string)$bm, 'birth_year' => (string)$by, 'start_day' => (string)$sd, 'start_month' => (string)$sm, 'start_year' => (string)$sy_,
                'certificate_id' => (string)((int)$r['certificate_id'] ?: ''), 'certificate' => eocCertOptions()[(int)$r['certificate_id']] ?? '', 'category_id' => (string)((int)$r['category_id'] ?: ''), 'category' => eocCatOptions()[(int)$r['category_id']] ?? '', 'phase_id' => (string)((int)$r['phase_id'] ?: ''), 'phase' => eocPhaseOptions()[(int)$r['phase_id']] ?? '',
                'teaching_hours' => (string)$numv($r['teaching_hours']), 'salary' => (string)round($numv($r['salary'])), 'add_salary' => (string)round($numv($r['add_salary'])), 'missing' => $r['missing']]; }
        else $out['lines'][] = ['teacher_no' => (string)$r['teacher_no'], 'teacher_name' => (string)$r['teacher_name'], 'previous_salary' => (string)round($numv($r['previous_salary'])), 'current_salary' => (string)round($numv($r['current_salary'])), 'add_salary' => (string)round($numv($r['add_salary'])),
            'teacher_m_disc' => (string)round($numv($r['teacher_m_disc'])), 'teacher_q_disc' => (string)round($numv($r['teacher_q_disc'])), 'school_q_disc' => (string)round($numv($r['school_q_disc'])), 'training_degree' => (string)round($numv($r['training_degree'])), 'discount_half_salary' => $numv($r['discount_half_salary']) > 0 ? 1 : 0, 'half_salary_amount' => (string)round($numv($r['discount_half_salary'])),
            'is_trainee' => (int)$r['is_trainee'], 'is_contribution' => (int)$r['is_contribution'], 'remarks' => (string)$r['remarks'], 'missing' => $r['missing']];
    }
    $out['totals'] = $kind === 'general' ? eocGeneralTotals($rows) : eocFinancialTotals($rows);
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
/* ===== إكسل ===== */
if (($_GET['export'] ?? '') === 'xlsx' && $data) {
    $bytes = eocBuildXlsx($kind, $period, $rows, $school, ['phone' => $data['phone'] ?? '', 'school_type' => $data['school_type'] ?? '']);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($kind === 'general' ? 'Bilan general ' : 'Bilan financier ') . $period . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($school['name_fr'] ?? 'school')) . ' (caisse).xlsx"');
    header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
}

include __DIR__ . '/../includes/header.php';
echo officialFormStyles();
$editKey = (string)($_GET['edit'] ?? ''); $adding = !empty($_GET['add']);
$editRow = null; if ($editKey !== '') foreach ($rows as $r) if ($r['key'] === $editKey) { $editRow = $r; break; }
$showForm = $E && $data && ($data['status'] ?? 'open') !== 'locked' && ($adding || $editRow);
$locked = $data && ($data['status'] ?? 'open') === 'locked';
$pill = fn(string $status) => $status === 'locked' ? '<span class="sp-pill sp-approved">معتمد — مقفل</span>' : '<span class="sp-pill sp-open">مفتوح — قابل للتعديل</span>';
$sel = function (string $name, array $opts, $cur, string $first = 'اختر...', bool $req = true) { $h = '<select name="' . $name . '"' . ($req ? ' required' : '') . '><option value="">' . e($first) . '</option>'; foreach ($opts as $v => $l) $h .= '<option value="' . e((string)$v) . '"' . ((string)$v === (string)$cur && (string)$cur !== '' ? ' selected' : '') . '>' . e($l) . '</option>'; return $h . '</select>'; };
$date3 = function (string $pfx, string $val, int $yFrom, int $yTo) use ($sel) { [$y, $m, $d] = eocYmd($val); $days = array_combine(range(1, 31), range(1, 31)); $months = array_combine(range(1, 12), range(1, 12)); $years = []; for ($i = $yTo; $i >= $yFrom; $i--) $years[$i] = $i;
    return '<div class="sp-date3">' . $sel($pfx . '_day', $days, $d, 'يوم') . $sel($pfx . '_month', $months, $m, 'شهر') . $sel($pfx . '_year', $years, $y, 'سنة') . '</div>'; };
$exT = jsAttr('بوّابة صندوق التعويضات — ' . ($kind === 'general' ? 'البيان العام ' : ($kind === 'financial' ? 'البيان المالي ' : '')) . $period);
?>
<style>
/* 🏛️💼 شكل منصّة الصندوق (www.caisseindemnite.com) — متغيّراتها وأصنافها الحرفية مقيّدة بـ.sp-app */
.sp-app{--sp-navy:#1b2b4b;--sp-navy-2:#243a63;--sp-accent:#2f5aa8;--sp-accent-soft:#eef2f9;--sp-accent-line:#cbd8ee;--sp-bg:#f3f4f6;--sp-surface:#fff;--sp-subtle:#f7f8fa;--sp-border:#d5dae2;--sp-border-soft:#e7eaef;--sp-input:#c3cad5;--sp-text:#1c2433;--sp-label:#384253;--sp-muted:#5f697a;--sp-faint:#8a93a3;--sp-danger:#b3261e;--sp-danger-soft:#fcefee;--sp-danger-line:#ecc5c2;--sp-ok:#1e7a4f;--sp-ok-soft:#e9f5ef;--sp-ok-line:#bfe0cf;--sp-warn-line:#ecd7a6;--sp-radius:6px;--sp-radius-sm:4px;--sp-ctl-h:38px;--sp-gap:16px;
  direction:rtl;text-align:right;background:var(--sp-bg);color:var(--sp-text);font-family:"Noto Sans Arabic","Segoe UI",Tahoma,Arial,sans-serif;font-size:13.5px;line-height:1.55;border-radius:10px;overflow:hidden;border:1px solid var(--sp-border)}
.sp-app a{color:var(--sp-accent)} .sp-app .num,.sp-app .sp-amount{font-variant-numeric:tabular-nums}
.sp-top{background:var(--sp-navy);color:#fff} .sp-top-inner,.sp-body{max-width:1280px;margin:0 auto;padding-left:24px;padding-right:24px}
.sp-top-row{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:56px;padding:8px 0}
.sp-brand{color:#fff;text-decoration:none;min-width:0} .sp-brand-name{font-size:15.5px;font-weight:700;line-height:1.35} .sp-brand-sub{font-size:12px;color:#b9c3d6}
.sp-user{display:flex;align-items:center;gap:14px;font-size:12.5px;color:#c8d0df;white-space:nowrap} .sp-user-school{text-align:left;line-height:1.35} .sp-user-school strong{display:block;color:#fff;font-weight:600;font-size:13px;max-width:340px;overflow:hidden;text-overflow:ellipsis}
.sp-logout{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 10px;color:#fff;border:1px solid rgba(255,255,255,.28);border-radius:var(--sp-radius-sm);text-decoration:none;font-weight:600}
.sp-nav{border-top:1px solid rgba(255,255,255,.12)} .sp-nav-inner{display:flex;gap:4px} .sp-nav a{display:inline-block;padding:10px 14px 9px;color:#c8d0df;font-weight:600;font-size:13.5px;text-decoration:none;border-bottom:3px solid transparent} .sp-nav a:hover{color:#fff} .sp-nav a.active{color:#fff;border-bottom-color:#fff}
.sp-body{padding-top:22px;padding-bottom:40px}
.sp-page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:16px} .sp-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:4px;font-size:12.5px;color:var(--sp-muted);text-decoration:none}
.sp-title-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap} .sp-title{font-size:21px;font-weight:700;color:var(--sp-navy);margin:0;line-height:1.3} .sp-title-sub{font-weight:600;color:var(--sp-text)} .sp-title-note{font-size:13px;font-weight:500;color:var(--sp-muted)}
.sp-page-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.sp-panel{background:var(--sp-surface);border:1px solid var(--sp-border);border-radius:var(--sp-radius);margin-bottom:var(--sp-gap)}
.sp-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 20px;border-bottom:1px solid var(--sp-border-soft)} .sp-panel-title{font-size:15px;font-weight:700;color:var(--sp-navy);margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap} .sp-panel-meta{font-size:12.5px;font-weight:500;color:var(--sp-muted)} .sp-panel-body{padding:16px 20px}
.sp-panel-foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 20px;border-top:1px solid var(--sp-border-soft);background:var(--sp-subtle);border-radius:0 0 var(--sp-radius) var(--sp-radius)}
.sp-step-no{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;font-size:12px;font-weight:700;background:var(--sp-navy);color:#fff} .sp-panel.sp-step-done .sp-step-no{background:var(--sp-ok)}
.sp-foldable.sp-folded{border-color:var(--sp-accent-line);border-inline-start:4px solid var(--sp-accent);background:var(--sp-accent-soft)} .sp-foldable.sp-folded .sp-panel-head{padding-top:18px;padding-bottom:18px;border-bottom:0} .sp-folded-hint{font-size:13px;color:#23406f;margin-inline-start:auto;margin-inline-end:14px}
.sp-record{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0} .sp-record-group{padding:0 20px;min-width:0} .sp-record-group:first-child{padding-inline-start:0} .sp-record-group:last-child{padding-inline-end:0} .sp-record-group+.sp-record-group{border-inline-start:1px solid var(--sp-border-soft)} .sp-record-group h3{font-size:12px;font-weight:700;color:var(--sp-muted);margin:0 0 6px}
.sp-dl{display:grid;grid-template-columns:minmax(96px,38%) 1fr;margin:0} .sp-dl dt,.sp-dl dd{padding:8px 0;border-bottom:1px solid var(--sp-border-soft);margin:0} .sp-dl dt{font-weight:400;font-size:12.5px;color:var(--sp-muted);padding-inline-end:10px} .sp-dl dd{font-weight:600;color:var(--sp-text);min-width:0;overflow-wrap:anywhere} .sp-dl dt:last-of-type,.sp-dl dd:last-of-type{border-bottom:0} .sp-na{color:var(--sp-faint);font-weight:400;font-size:12.5px} .ltr{direction:ltr;unicode-bidi:embed}
.sp-info-note{margin-top:14px;padding-top:12px;border-top:1px solid var(--sp-border-soft);font-size:12.5px;color:var(--sp-muted);display:flex;align-items:center;gap:6px}
.sp-choices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px} .sp-choice{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border:1px solid var(--sp-border);border-radius:var(--sp-radius);color:var(--sp-text);text-decoration:none;background:var(--sp-surface)} .sp-choice:hover{border-color:var(--sp-navy);background:var(--sp-subtle);text-decoration:none} .sp-choice strong{display:block;color:var(--sp-navy);font-size:14.5px} .sp-choice span{font-size:12.5px;color:var(--sp-muted)} .sp-choice i{color:var(--sp-navy)}
.sp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:var(--sp-ctl-h);padding:0 14px;font-size:13px;font-weight:600;border-radius:var(--sp-radius-sm);border:1px solid var(--sp-input);background:var(--sp-surface);color:var(--sp-text);text-decoration:none;cursor:pointer;white-space:nowrap;line-height:1;font-family:inherit} .sp-btn:hover{background:var(--sp-subtle);border-color:#aeb7c4;color:var(--sp-text);text-decoration:none}
.sp-btn-go{background:var(--sp-navy);border-color:var(--sp-navy);color:#fff} .sp-btn-go:hover{background:var(--sp-navy-2);border-color:var(--sp-navy-2);color:#fff} .sp-btn-del{color:var(--sp-danger);border-color:var(--sp-danger-line)} .sp-btn-del:hover{background:var(--sp-danger-soft);border-color:var(--sp-danger);color:var(--sp-danger)}
.sp-btn-sm{height:30px;padding:0 10px;font-size:12.5px} .sp-btn-icon{width:28px;height:28px;padding:0;font-size:12px} .sp-btn[disabled]{opacity:.5;cursor:not-allowed;pointer-events:none} .sp-actions{display:flex;gap:6px;flex-wrap:nowrap;justify-content:center}
.sp-pill{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;line-height:1;padding:5px 9px;border-radius:999px;border:1px solid transparent;white-space:nowrap;vertical-align:middle} .sp-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor} .sp-open{color:var(--sp-label);background:var(--sp-subtle);border-color:var(--sp-border)} .sp-approved{color:var(--sp-ok);background:var(--sp-ok-soft);border-color:var(--sp-ok-line)}
.sp-app .alert{display:flex;align-items:flex-start;gap:8px;border-radius:var(--sp-radius-sm);padding:10px 14px;font-size:13px;line-height:1.6;margin-bottom:12px;border:1px solid;border-inline-start-width:3px} .sp-app .alert-warning{background:#fbf4e4;color:#7a5000;border-color:var(--sp-warn-line);border-inline-start-color:#d8a73f} .sp-app .alert-success{background:var(--sp-ok-soft);color:var(--sp-ok);border-color:var(--sp-ok-line);border-inline-start-color:var(--sp-ok)} .sp-app .alert-info{background:var(--sp-accent-soft);color:#23406f;border-color:var(--sp-accent-line);border-inline-start-color:var(--sp-accent)}
.sp-app label{font-size:12.5px;font-weight:600;color:var(--sp-label)} .sp-req{color:var(--sp-danger);margin-inline-start:2px}
.sp-app input[type=text],.sp-app input[type=tel],.sp-app input[type=number],.sp-app input:not([type]),.sp-app select,.sp-app textarea{width:100%;height:var(--sp-ctl-h);padding:0 10px;font-size:14px;color:var(--sp-text);background:var(--sp-surface);border:1px solid var(--sp-input);border-radius:var(--sp-radius-sm);box-shadow:none;font-family:inherit} .sp-app textarea{height:auto;min-height:64px;padding:8px 10px;resize:vertical}
.sp-app input:focus,.sp-app select:focus,.sp-app textarea:focus{outline:none;border-color:var(--sp-accent);box-shadow:0 0 0 3px rgba(47,90,168,.14)} .sp-app input[readonly]{background:var(--sp-subtle);color:var(--sp-muted)} .sp-app input.sp-amount,.sp-app input[type=tel]{direction:ltr;text-align:right} .sp-app input[type=checkbox]{width:16px;height:16px;margin:0;accent-color:var(--sp-navy)}
.sp-form{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:14px} .sp-form>div{min-width:260px} .sp-form label{display:block;margin-bottom:5px}
.sp-step1{display:grid;grid-template-columns:minmax(220px,300px) auto auto;justify-content:start;gap:14px 40px;align-items:start} .sp-step1-actions{align-self:end;display:flex;align-items:center;gap:10px} .sp-field label{display:block;margin-bottom:5px} .sp-hint{font-size:12px;color:var(--sp-muted);margin-top:4px}
.sp-radios{display:flex;flex-direction:column;align-items:flex-start;gap:2px} .sp-radio{display:flex;align-items:center;gap:8px;margin:0;padding:4px 8px 4px 10px;white-space:nowrap;cursor:pointer;font-size:13.5px;font-weight:500;color:var(--sp-text);border-radius:var(--sp-radius-sm)} .sp-radio:hover{background:var(--sp-subtle)} .sp-radio input{margin:0;width:17px;height:17px;accent-color:var(--sp-navy)} .sp-radio:has(input:checked){color:var(--sp-navy);font-weight:700}
.sp-fs{display:grid;grid-template-columns:170px minmax(0,1fr);gap:12px 24px;padding:16px 0;border-top:1px solid var(--sp-border-soft)} .sp-fs:first-of-type{border-top:0;padding-top:4px} .sp-fs-title{font-size:13px;font-weight:700;color:var(--sp-navy);margin:0;padding-top:4px}
.sp-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px 16px} .sp-grid-4 label{display:block;margin-bottom:5px} .sp-span-2{grid-column:span 2} .sp-date3{display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:6px} .sp-date3 select{min-width:0;text-align:center} .sp-checks{display:flex;flex-direction:column;gap:6px;justify-content:flex-end;padding-bottom:4px} .sp-checks label{display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer}
.sp-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px} .sp-toolbar-form{display:flex;align-items:center;gap:6px;flex-wrap:wrap} .sp-toolbar-form label{margin:0;color:var(--sp-muted);font-weight:600} .sp-toolbar-form select,.sp-toolbar-form input{width:auto;min-width:150px;height:32px;font-size:13px} .sp-count{font-size:13px;color:var(--sp-muted)} .sp-count strong{color:var(--sp-text);font-size:14px}
.sp-table-wrap{width:100%;overflow-x:auto;border:1px solid var(--sp-border);border-radius:var(--sp-radius-sm);background:var(--sp-surface)}
.sp-app table{width:100%;border-collapse:collapse;background:var(--sp-surface);margin:0} .sp-app thead th{background:var(--sp-subtle);color:var(--sp-label);font-size:12px;font-weight:700;padding:9px 10px;text-align:right;white-space:nowrap;vertical-align:bottom;border:0;border-bottom:1px solid var(--sp-border);position:static}
.sp-app tbody td{padding:8px 10px;border:0;border-bottom:1px solid var(--sp-border-soft);white-space:nowrap;vertical-align:middle} .sp-app tbody tr:last-child td{border-bottom:0} .sp-app thead th+th,.sp-app tbody td+td,.sp-app tfoot td+td{border-inline-start:1px dashed #d3d8e0} .sp-app tbody tr:hover>td{background:#f6f8fc}
.sp-app td.num,.sp-app th.num{text-align:left;direction:ltr} .sp-app td.sp-col-idx,.sp-app th.sp-col-idx{text-align:center;color:var(--sp-faint);width:1%} .sp-app td.sp-col-name{font-weight:600} .sp-app td.sp-col-act,.sp-app th.sp-col-act{width:1%;text-align:center} .sp-app td.sp-empty{text-align:center;color:var(--sp-muted);padding:22px 10px;white-space:normal}
.sp-app tfoot td{padding:10px 10px;font-weight:700;white-space:nowrap;background:#eef1f6;border-top:2px solid var(--sp-navy);color:var(--sp-navy)} .sp-app table.sp-data{min-width:100%;width:max-content;font-size:13px} .sp-data td.sp-wrap{white-space:normal;min-width:110px;max-width:150px;line-height:1.35}
.sp-cell-missing{background:var(--sp-danger-soft) !important} .sp-cell-missing>span:empty::after,.sp-miss{content:"ناقص";display:inline-block;color:var(--sp-danger);font-size:11px;font-weight:600;line-height:1;padding:3px 6px;border:1px solid var(--sp-danger-line);border-radius:999px;background:var(--sp-surface)} .sp-row-has-missing>td:first-child{box-shadow:inset -3px 0 0 var(--sp-danger)}
.sp-amt{position:relative;cursor:help;border-bottom:1px dotted #aab3c3} .sp-amt:hover::after{content:attr(data-full);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--sp-navy);color:#fff;padding:3px 8px;border-radius:var(--sp-radius-sm);font-size:11.5px;font-weight:600;white-space:nowrap;z-index:30}
.sp-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px} .sp-stat{border:1px solid var(--sp-border);border-radius:var(--sp-radius-sm);padding:12px 14px} .sp-stat-label{font-size:12.5px;color:var(--sp-muted)} .sp-stat-value{font-size:20px;font-weight:700;color:var(--sp-text);margin-top:2px} .sp-stat-split{font-size:12px;color:var(--sp-muted);margin-top:6px} .sp-stat-main{background:var(--sp-navy);border-color:var(--sp-navy)} .sp-stat-main .sp-stat-label{color:#c8d0df} .sp-stat-main .sp-stat-value{color:#fff} .sp-note{font-size:12.5px;color:var(--sp-muted);margin:0 0 10px}
.sp-footer{text-align:center;color:var(--sp-muted);font-size:12.5px;padding:14px;border-top:1px solid var(--sp-border)}
.sp-ovd{background:#fffbea !important} .sp-del-list{margin-top:10px;font-size:13px;color:var(--sp-muted)} .sp-del-list form{display:inline}
@media (max-width:900px){.sp-record{grid-template-columns:1fr}.sp-record-group+.sp-record-group{border:0;margin-top:16px}.sp-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.sp-fs{grid-template-columns:1fr}.sp-step1{grid-template-columns:1fr}.sp-summary{grid-template-columns:1fr}.sp-choices{grid-template-columns:1fr}.sp-top-row{flex-direction:column;align-items:flex-start}}
@media print{
  @page{size:A4 landscape;margin:8mm}
  .no-print,.sp-top,.sp-nav,.sp-page-actions,.sp-col-act,.sp-toolbar,.sp-foldable,.sp-step,.sp-del-list,.sp-footer,.sp-app .alert{display:none !important}
  .sp-app{border:0;background:#fff;border-radius:0} .sp-body{padding:0} .sp-panel{break-inside:avoid;border-color:#bbb} .sp-table-wrap{overflow:visible;border:0} .sp-app table.sp-data{width:100%;min-width:0}
  .sp-app thead th{background:#eef1f6 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact} .sp-stat-main{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>

<div class="card no-print" dir="rtl">
    <div class="card-header"><h3>
        <span><i class="fas gov-ic gov-eoc"></i> بوّابة صندوق التعويضات — <?= e($school['name_ar']) ?></span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">طبق منصّة الصندوق www.caisseindemnite.com (بوابة المدارس): الصفحات والجداول والخانات نفسها، معبّأة من الرواتب — لتُنقَل إلى المنصّة أو تُرحَّل إليها بالمساعد</div>
    </h3></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <?php if ($data): ?>
        <a class="btn btn-success" href="<?= e($back(['export' => 'xlsx', 'emp' => $emp !== 'all' ? $emp : null, 'sort' => $sort !== 'name' ? $sort : null, 'search' => $search ?: null])) ?>"><i class="fas fa-file-excel"></i> إكسل (صيغ حيّة)</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> طباعة</button>
        <button type="button" class="btn btn-danger" onclick="msaSavePdfStart(this)"><i class="fas fa-file-pdf"></i> حفظ PDF</button>
        <?php if (!isViewer()): ?><button type="button" class="btn btn-info" onclick="ppWord('<?= $exT ?>')"><i class="fas fa-file-word"></i> وورد</button><?php endif; ?>
        <button type="button" class="btn" style="background:#25D366;color:#fff" onclick="ppWhatsApp('<?= $exT ?>','')"><i class="fab fa-whatsapp"></i> واتساب</button>
        <?php if (!isViewer()): ?><button type="button" class="btn btn-light" onclick="ppEmail('<?= $exT ?>','')"><i class="fas fa-envelope"></i> بريد إلكتروني</button><?php endif; ?>
        <span class="eoc-sep" role="group" aria-label="عرض الأرقام" style="display:inline-flex;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden">
            <button type="button" class="btn btn-primary" data-sep="1" onclick="eocSep(true)" style="border-radius:0;border:0">بفواصل</button>
            <button type="button" class="btn btn-light" data-sep="0" onclick="eocSep(false)" style="border-radius:0;border:0" title="أرقام بلا فواصل للنسخ واللصق إلى خانات المنصّة">بلا فواصل</button>
        </span>
        <a class="btn btn-light" href="<?= e($back(['export' => 'json'])) ?>" target="_blank" title="ما يقرأه مساعد المتصفّح (تعبئة المنصّة من البرنامج)"><i class="fas fa-code"></i> JSON للمساعد</a>
        <?php endif; ?>
        <a class="btn btn-light" href="https://www.caisseindemnite.com/my/school" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> فتح منصّة الصندوق</a>
    </div>
</div>

<div class="sp-app o_school_portal">
  <header class="sp-top"><div class="sp-top-inner">
    <div class="sp-top-row">
      <a href="<?= e($self) ?>" class="sp-brand"><div class="sp-brand-name">صندوق التعويضات لأفراد الهيئة التعليمية في المدارس الخاصة</div><div class="sp-brand-sub">بوابة المدارس</div></a>
      <div class="sp-user"><div class="sp-user-school"><strong title="<?= e($school['name_ar']) ?>"><?= e($school['name_ar']) ?></strong>رقم المدرسة <span class="num"><?= e((string)($school['caisse_number'] ?: '—')) ?></span></div>
        <a href="https://www.caisseindemnite.com/my/school" target="_blank" rel="noopener" class="sp-logout no-print"><i class="fas fa-external-link-alt"></i> المنصّة</a></div>
    </div>
    <nav class="sp-nav" aria-label="القائمة الرئيسية"><div class="sp-nav-inner">
      <a href="<?= e($self) ?>?tab=home" class="<?= $tab === 'home' ? 'active' : '' ?>">الرئيسية</a>
      <a href="<?= e($self) ?>?tab=general" class="<?= $tab === 'general' ? 'active' : '' ?>">البيان العام</a>
      <a href="<?= e($self) ?>?tab=financial" class="<?= $tab === 'financial' ? 'active' : '' ?>">البيان المالي</a>
    </div></nav>
  </div></header>

  <main class="sp-body">
  <?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= e($_SESSION['flash_success']) ?></span></div><?php unset($_SESSION['flash_success']); endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i><span><?= e($_SESSION['flash_error']) ?></span></div><?php unset($_SESSION['flash_error']); endif; ?>

  <?php if ($tab === 'home'): /* ===== الرئيسية ===== */ ?>
    <div class="sp-page-head"><div class="sp-page-head-main"><h1 class="sp-title">الرئيسية</h1><div class="sp-title-note">معلومات المدرسة كما هي مسجلة لدى الصندوق</div></div></div>
    <section class="sp-panel">
      <div class="sp-panel-head"><h2 class="sp-panel-title">معلومات المدرسة</h2><span class="sp-panel-meta">رقم المدرسة <span class="num"><?= e((string)($school['caisse_number'] ?: '—')) ?></span></span></div>
      <div class="sp-panel-body">
        <?php $na = '<span class="sp-na">غير متوفر</span>'; $sv = fn($v) => trim((string)$v) !== '' ? e($v) : $na; ?>
        <div class="sp-record">
          <div class="sp-record-group"><h3>البيانات الأساسية</h3><dl class="sp-dl"><dt>اسم المدرسة</dt><dd><?= e($school['name_ar']) ?></dd><dt>رقم المدرسة</dt><dd class="num"><?= $sv($school['caisse_number'] ?? '') ?></dd><dt>نوع المدرسة</dt><dd>غير مجاني</dd><dt>صاحب الترخيص</dt><dd><?= $sv($school['director_name'] ?? '') ?></dd></dl></div>
          <div class="sp-record-group"><h3>الموقع</h3><dl class="sp-dl"><dt>المحافظة</dt><dd><?= $na ?></dd><dt>القضاء</dt><dd><?= $na ?></dd><dt>المدينة</dt><dd><?= $sv(trim(explode('-', (string)($school['address'] ?? ''))[0])) ?></dd><dt>العنوان</dt><dd><?= $sv($school['address'] ?? '') ?></dd></dl></div>
          <div class="sp-record-group"><h3>الاتصال</h3><dl class="sp-dl"><dt>الهاتف</dt><dd><span class="ltr"><?= $sv($school['phone'] ?? '') ?></span></dd><dt>هاتف إضافي</dt><dd><?= $na ?></dd><dt>فاكس</dt><dd><?= $na ?></dd><dt>البريد الإلكتروني</dt><dd><span class="ltr"><?= $sv($school['email'] ?? '') ?></span></dd></dl></div>
        </div>
        <div class="sp-info-note"><i class="fas fa-info-circle"></i> هذه المعلومات من ملف المدرسة بالبرنامج (رقم الصندوق من إعدادات المدرسة). على المنصّة لا تُعدَّل إلا بمراجعة الصندوق.</div>
      </div>
    </section>
    <section class="sp-panel">
      <div class="sp-panel-head"><h2 class="sp-panel-title">تعبئة البيانات</h2><span class="sp-panel-meta">اختر البيان الذي تريد تعبئته لمدرستك</span></div>
      <div class="sp-panel-body"><div class="sp-choices">
        <a href="<?= e($self) ?>?tab=general" class="sp-choice"><div><strong>البيان العام</strong><span>سنوي — قائمة أفراد الهيئة التعليمية</span></div><i class="fas fa-chevron-left"></i></a>
        <a href="<?= e($self) ?>?tab=financial" class="sp-choice"><div><strong>البيان المالي</strong><span>فصلي — الرواتب والمحسومات</span></div><i class="fas fa-chevron-left"></i></a>
      </div></div>
    </section>

  <?php elseif (!$validPeriod): /* ===== لائحة البيانات (عام أو مالي) ===== */
    $saved = eocSavedMap($db, $ids, $kind);
    $list = $kind === 'general' ? eocSchoolYears($db, $ids) : eocFinancialPeriods($db, $ids);
    foreach (array_keys($saved) as $p) if (!in_array($p, $list, true)) $list[] = $p;
    if ($kind === 'financial') usort($list, fn($a, $b) => strcmp($b, $a)); else rsort($list);
    $newOpts = [];
    if ($kind === 'general') { $cy = currentSchoolYear(); [$y1] = schoolYearToYears($cy); foreach ([$cy, ($y1 + 1) . '-' . ($y1 + 2)] as $o) if (!isset($saved[$o])) $newOpts[$o] = $o; }
    else { foreach (array_slice($list, 0, 8) as $o) if (!isset($saved[$o])) { $pio = eocPeriodInfo($o); $newOpts[$o] = $o . ' — ' . ($pio['label'] ?? ''); } }
  ?>
    <div class="sp-page-head"><div class="sp-page-head-main"><h1 class="sp-title"><?= $kind === 'general' ? 'البيان العام' : 'البيان المالي' ?> <span class="sp-title-note">(<?= $kind === 'general' ? 'سنوي' : 'فصلي' ?>)</span></h1></div></div>
    <?php if ($E): ?>
    <section class="sp-panel"><div class="sp-panel-head"><h2 class="sp-panel-title">بيان جديد</h2></div>
      <div class="sp-panel-body"><form method="post" class="sp-form" action="<?= e($self) ?>?tab=<?= $kind ?>" onsubmit="this.action='<?= e($self) ?>?tab=<?= $kind ?>&period='+encodeURIComponent(this.period.value)">
        <?= csrfField() ?><input type="hidden" name="eoc_act" value="new">
        <div><label><?= $kind === 'general' ? 'السنة الدراسية' : 'السنة الدراسية والفصل' ?><span class="sp-req">*</span></label><select name="period" required><option value=""><?= $kind === 'general' ? 'اختر السنة...' : 'اختر الفترة...' ?></option><?php foreach ($newOpts as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="sp-btn sp-btn-go">+ <?= $kind === 'general' ? 'بيان عام جديد' : 'بيان مالي جديد' ?></button>
        <div class="sp-hint" style="flex-basis:100%"><?= $kind === 'general' ? 'يُعبَّأ تلقائياً بأساتذة السنة من الرواتب (ملاك ومتعاقدون).' : 'تُنقل قائمة الأساتذة تلقائياً من رواتب أوّل شهر الفصل (ملاك الصندوق فقط).' ?></div>
      </form></div></section>
    <?php endif; ?>
    <section class="sp-panel"><div class="sp-panel-head"><h2 class="sp-panel-title">البيانات السابقة</h2></div>
      <div class="sp-panel-body"><div class="sp-table-wrap"><table>
        <thead><tr><?php if ($kind === 'general'): ?><th>السنة الدراسية</th><th class="num">عدد الأساتذة</th><th class="num">ملاك</th><th class="num">متعاقدين</th><?php else: ?><th>الفصل</th><th class="num">عدد الأساتذة</th><th class="num">المجموع العام</th><?php endif; ?><th>الحالة</th><th class="sp-col-act"></th></tr></thead>
        <tbody>
        <?php foreach ($list as $p): $d0 = $saved[$p] ?? eocDefaults($kind); $isSaved = isset($saved[$p]); $st = $d0['status'] ?? 'open';
            if ($kind === 'general') { $rr = eocGeneralRows($db, $ids, $p, array_replace(eocDefaults('general'), $d0)); $tt = eocGeneralTotals($rr); }
            else { $rr = eocFinancialRows($db, $ids, $p, array_replace(eocDefaults('financial'), $d0)); $tt = eocFinancialTotals($rr); $pio = eocPeriodInfo($p); } ?>
          <tr>
            <?php if ($kind === 'general'): ?><td class="sp-col-name"><?= e($p) ?></td><td class="num"><?= $tt['count'] ?></td><td class="num"><?= $tt['staff'] ?></td><td class="num"><?= $tt['contract'] ?></td>
            <?php else: ?><td class="sp-col-name"><span class="num"><?= e($p) ?></span> <?= e($pio['label'] ?? '') ?> <small style="color:var(--sp-muted)"><?= e($pio['range'] ?? '') ?></small></td><td class="num"><?= $tt['count'] ?></td><td class="num"><?= $fmt0($tt['grand']) ?></td><?php endif; ?>
            <td><?= $pill($st) ?><?= $isSaved ? '' : ' <small style="color:var(--sp-muted)">محسوب — لم يُحفَظ بعد</small>' ?></td>
            <td class="sp-col-act"><a class="sp-btn sp-btn-sm <?= $st === 'locked' ? 'sp-btn-view' : 'sp-btn-go' ?>" href="<?= e($self) ?>?tab=<?= $kind ?>&period=<?= e($p) ?>"><?= $st === 'locked' ? 'عرض' : 'تعبئة' ?></a></td>
          </tr>
        <?php endforeach; if (!$list): ?><tr><td colspan="6" class="sp-empty">لا بيانات بعد.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($kind === 'financial'): ?><p class="sp-note" style="margin-top:10px">رموز الفصول كالمنصّة: الرمز يحمل سنة الفصل الدراسية — الفصل الأول = تشرين الأول حتى كانون الأول، الثاني = كانون الثاني حتى آذار، الثالث = نيسان حتى حزيران، الرابع = تموز حتى أيلول. «010» و«001» بيانات خاصّة بشهرَي الدرجات (تشرين الأول وكانون الثاني).</p><?php endif; ?>
      </div></section>

  <?php elseif ($kind === 'general'): /* ===== البيان العام لسنة ===== */
    $tot = eocGeneralTotals($rows); $missCount = count(array_filter($rows, fn($r) => $r['missing'])); $delIds = array_map('intval', $data['deleted']); ?>
    <div class="sp-page-head"><div class="sp-page-head-main">
        <a href="<?= e($self) ?>?tab=general" class="sp-back no-print"><i class="fas fa-arrow-right"></i> البيان العام</a>
        <div class="sp-title-row"><h1 class="sp-title">البيان العام <span class="sp-title-sub num"><?= e($period) ?></span></h1><?= $pill($data['status']) ?></div>
        <div class="sp-title-note"><?= e($school['name_ar']) ?> — رقم المدرسة <?= e((string)($school['caisse_number'] ?: '—')) ?></div></div>
      <div class="sp-page-actions no-print">
        <?php if ($E): ?><form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="eoc_act" value="status"><button type="submit" class="sp-btn sp-btn-view"><i class="fas <?= $locked ? 'fa-lock-open' : 'fa-lock' ?>"></i> <?= $locked ? 'فتح للتعديل' : 'اعتماد وإقفال' ?></button></form><?php endif; ?>
        <button type="button" class="sp-btn sp-btn-view" onclick="window.print()" <?= $missCount ? 'disabled title="بيانات ناقصة"' : '' ?>><i class="fas fa-print"></i> <?= $missCount ? 'الطباعة غير متاحة' : 'طباعة البيان' ?></button>
      </div></div>
    <?php if ($missCount): ?><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i><span>لا يمكن طباعة البيان العام على المنصّة: بيانات <?= $missCount ?> أستاذ ناقصة (جميع حقول البيان العام إلزامية). الخانات الناقصة معلّمة بـ «ناقص» في الجدول — اضغط على ✎ لإكمالها هنا، أو أكملها بملف الأستاذ بالبرنامج فتُحسب تلقائياً.</span></div><?php endif; ?>

    <section class="sp-panel sp-step sp-step-done">
      <div class="sp-panel-head"><h2 class="sp-panel-title"><span class="sp-step-no">1</span> الخطوة 1 — معلومات المدرسة</h2><span class="sp-pill sp-approved">محفوظة</span></div>
      <div class="sp-panel-body"><form method="post" class="sp-step1"><?= csrfField() ?><input type="hidden" name="eoc_act" value="step1">
        <div class="sp-field"><label for="sp-school-phone">رقم هاتف المدرسة (خلوي)<span class="sp-req">*</span></label><input id="sp-school-phone" name="phone" type="tel" placeholder="03 123 456" value="<?= e($data['phone']) ?>" <?= $E && !$locked ? '' : 'readonly' ?>><div class="sp-hint">خلوي لبناني فقط: 03، 70، 71، 76، 78، 79، 81</div></div>
        <div class="sp-field"><label>نوع المدرسة<span class="sp-req">*</span></label><div class="sp-radios"><?php foreach (eocSchoolTypes() as $v => $l): ?><label class="sp-radio"><input type="radio" name="school_type" value="<?= $v ?>" <?= $data['school_type'] === $v ? 'checked' : '' ?> <?= $E && !$locked ? '' : 'disabled' ?>><span><?= $l ?></span></label><?php endforeach; ?></div></div>
        <?php if ($E && !$locked): ?><div class="sp-step1-actions"><button type="submit" class="sp-btn sp-btn-go">تحديث</button></div><?php endif; ?>
      </form></div>
    </section>

    <?php if ($E && !$locked): ?>
    <section id="sp-teacher-form" class="sp-panel sp-foldable <?= $showForm ? '' : 'sp-folded' ?>">
      <div class="sp-panel-head"><h2 class="sp-panel-title"><span class="sp-step-no">2</span> <?= $editRow ? 'تعديل أستاذ — <span>' . e($editRow['teacher_name']) . '</span>' : 'الخطوة 2 — الأساتذة' ?></h2>
        <?php if (!$showForm): ?><span class="sp-folded-hint">من هنا تُضاف أسطر الأساتذة إلى البيان</span><a class="sp-btn sp-btn-go" href="<?= e($back(['add' => 1])) ?>#sp-teacher-form">+ إضافة الأستاذ</a><?php else: ?><span class="sp-panel-meta">جميع الحقول إلزامية</span><?php endif; ?></div>
      <?php if ($showForm): $r = $editRow ?: array_fill_keys(eocGeneralFields(), '') + ['key' => '', 'src' => 'manual', 'ov' => []]; ?>
      <form method="post" data-sp-roster-form="1"><?= csrfField() ?><input type="hidden" name="eoc_act" value="line"><input type="hidden" name="key" value="<?= e($r['key']) ?>">
        <div class="sp-panel-body">
          <div class="sp-fs"><h3 class="sp-fs-title">التعيين</h3><div class="sp-grid-4">
            <div><label>ملاك / متعاقد<span class="sp-req">*</span></label><?= $sel('employment_type', ['staff' => 'ملاك', 'contract' => 'متعاقد'], $r['employment_type'] ?: 'staff', '', true) ?></div>
            <div><label>الرقم المالي<span class="sp-req">*</span></label><input name="teacher_no" inputmode="numeric" autocomplete="off" value="<?= e((string)$r['teacher_no']) ?>"></div>
            <div class="sp-span-2"><label>تاريخ دخول الخدمة<span class="sp-req">*</span></label><?= $date3('start', (string)$r['start_date'], 1950, (int)date('Y') + 1) ?></div>
          </div></div>
          <div class="sp-fs"><h3 class="sp-fs-title">البيانات الشخصية</h3><div class="sp-grid-4">
            <div><label>الاسم والشهرة<span class="sp-req">*</span></label><input name="teacher_name" required value="<?= e((string)$r['teacher_name']) ?>"></div>
            <div><label>اسم الاب<span class="sp-req">*</span></label><input name="father_name" required value="<?= e((string)$r['father_name']) ?>"></div>
            <div class="sp-span-2"><label>تاريخ الولادة<span class="sp-req">*</span></label><?= $date3('birth', (string)$r['birth_date'], 1940, (int)date('Y') - 16) ?></div>
          </div></div>
          <div class="sp-fs"><h3 class="sp-fs-title">المؤهلات والتصنيف</h3><div class="sp-grid-4">
            <div><label>الشهادة<span class="sp-req">*</span></label><?= $sel('certificate_id', eocCertOptions(), (int)$r['certificate_id'] ?: '') ?></div>
            <div><label>الفئة<span class="sp-req">*</span></label><?= $sel('category_id', eocCatOptions(), (int)$r['category_id'] ?: '') ?></div>
            <div><label>المرحلة<span class="sp-req">*</span></label><?= $sel('phase_id', eocPhaseOptions(), (int)$r['phase_id'] ?: '') ?></div>
          </div></div>
          <div class="sp-fs"><h3 class="sp-fs-title">الراتب والدوام</h3><div class="sp-grid-4">
            <div><label>عدد ساعات التعليم<span class="sp-req">*</span></label><input name="teaching_hours" type="number" min="0" step="any" required value="<?= e((string)$r['teaching_hours']) ?>"></div>
            <div><label>الراتب (ل.ل)<span class="sp-req">*</span></label><input name="salary" type="text" class="sp-amount" required value="<?= $r['salary'] !== '' ? $fmt0($r['salary']) : '' ?>"></div>
            <div><label>الأجر الاضافي (ل.ل)<span class="sp-req">*</span></label><input name="add_salary" type="text" class="sp-amount" required value="<?= $r['add_salary'] !== '' ? $fmt0($r['add_salary']) : '' ?>"></div>
          </div></div>
        </div>
        <div class="sp-panel-foot"><button type="submit" class="sp-btn sp-btn-go"><i class="fas fa-check"></i> <?= $editRow ? 'حفظ التعديل' : 'إضافة' ?></button><a class="sp-btn sp-btn-view" href="<?= e($back()) ?>#sp-list">إلغاء</a>
          <?php if ($editRow && $editRow['src'] === 'auto' && $editRow['ov']): ?><span class="sp-panel-meta">هذا السطر فيه قيم معدَّلة يدوياً (مظلَّلة بالأصفر بالجدول)</span><?php endif; ?></div>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <div id="ppExportArea" class="doc-sheet land-report rl-fixed" dir="rtl" style="padding:0;border:0;background:transparent;box-shadow:none">
    <div class="doc-head" style="display:none"><div class="dh-ar">البيان العام <?= e($period) ?> — <?= e($school['name_ar']) ?></div></div>
    <section class="sp-panel" id="sp-list">
      <div class="sp-panel-head"><h2 class="sp-panel-title">قائمة الأساتذة</h2><span class="sp-count">عدد الأساتذة: <strong><?= $tot['count'] ?></strong> — ملاك: <strong><?= $tot['staff'] ?></strong> — متعاقدين: <strong><?= $tot['contract'] ?></strong></span></div>
      <div class="sp-panel-body">
        <div class="sp-toolbar no-print"><form method="get" class="sp-toolbar-form"><input type="hidden" name="tab" value="general"><input type="hidden" name="period" value="<?= e($period) ?>">
          <label>الفئة</label><select name="emp" onchange="this.form.submit()"><option value="all" <?= $emp === 'all' ? 'selected' : '' ?>>الكل</option><option value="staff" <?= $emp === 'staff' ? 'selected' : '' ?>>ملاك</option><option value="contract" <?= $emp === 'contract' ? 'selected' : '' ?>>متعاقد</option></select>
          <label>ترتيب حسب</label><select name="sort" onchange="this.form.submit()"><option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>الاسم (أبجدي)</option><option value="teacher_no" <?= $sort === 'teacher_no' ? 'selected' : '' ?>>الرقم المالي</option><option value="salary" <?= $sort === 'salary' ? 'selected' : '' ?>>الراتب (الأعلى أولاً)</option><option value="hours" <?= $sort === 'hours' ? 'selected' : '' ?>>الساعات (الأعلى أولاً)</option></select>
          <input type="text" name="search" placeholder="الرقم المالي أو الاسم" value="<?= e($search) ?>"><button type="submit" class="sp-btn sp-btn-view sp-btn-sm">بحث</button></form></div>
        <div class="sp-table-wrap"><table class="sp-data">
          <thead><tr><th class="sp-col-idx">#</th><th>ملاك/متعاقد</th><th>الرقم المالي</th><th>الاسم والشهرة</th><th>اسم الاب</th><th>الشهادة</th><th>الفئة</th><th>المرحلة</th><th>تاريخ الولادة</th><th>تاريخ دخول الخدمة</th><th class="num">ساعات</th><th class="num">الراتب</th><th class="num">الأجر الاضافي</th><th class="sp-col-act no-print"></th></tr></thead>
          <tbody>
          <?php $i = 0; foreach ($rows as $r): $i++; $mc = fn(string $f, string $extra = '') => 'class="' . $extra . (in_array($f, $r['missing'], true) ? ' sp-cell-missing' : '') . (array_key_exists($f, $r['ov']) ? ' sp-ovd' : '') . '"';
                $cell = fn(string $f, string $html, string $extra = '') => '<td ' . $mc($f, $extra) . '>' . (in_array($f, $r['missing'], true) ? '<span class="sp-miss">ناقص</span>' : $html) . '</td>'; ?>
            <tr class="<?= $r['missing'] ? 'sp-row-has-missing' : '' ?>" data-key="<?= e($r['key']) ?>">
              <td class="num sp-col-idx"><?= $i ?></td>
              <?= $cell('employment_type', $r['employment_type'] === 'staff' ? 'ملاك' : 'متعاقد') ?>
              <?= $cell('teacher_no', e((string)$r['teacher_no']), 'num') ?>
              <?= $cell('teacher_name', e((string)$r['teacher_name']), 'sp-col-name') ?>
              <?= $cell('father_name', e((string)$r['father_name'])) ?>
              <?= $cell('certificate_id', e(eocCertOptions()[(int)$r['certificate_id']] ?? ''), 'sp-wrap') ?>
              <?= $cell('category_id', e(eocCatOptions()[(int)$r['category_id']] ?? '')) ?>
              <?= $cell('phase_id', e(eocPhaseOptions()[(int)$r['phase_id']] ?? '')) ?>
              <?= $cell('birth_date', e((string)$r['birth_date']), 'num') ?>
              <?= $cell('start_date', e((string)$r['start_date']), 'num') ?>
              <?= $cell('teaching_hours', e(rtrim(rtrim(number_format($numv($r['teaching_hours']), 1), '0'), '.')), 'num') ?>
              <?= $cell('salary', $fmt0($r['salary']), 'num') ?>
              <?= $cell('add_salary', $amtM($r['add_salary']), 'num') ?>
              <td class="sp-col-act no-print"><?php if ($E && !$locked): ?><div class="sp-actions">
                <a class="sp-btn sp-btn-sm sp-btn-view sp-btn-icon" title="تعديل" href="<?= e($back(['edit' => $r['key']])) ?>#sp-teacher-form"><i class="fas fa-pencil-alt"></i></a>
                <?php if ($r['src'] === 'auto' && $r['ov']): ?><form method="post" onsubmit="return confirm('إرجاع السطر محسوباً من البرنامج؟')"><?= csrfField() ?><input type="hidden" name="eoc_act" value="reset"><input type="hidden" name="key" value="<?= e($r['key']) ?>"><button class="sp-btn sp-btn-sm sp-btn-view sp-btn-icon" title="↺ تلقائي من البرنامج">↺</button></form><?php endif; ?>
                <form method="post" onsubmit="return confirm('سيتم حذف «<?= e((string)$r['teacher_name']) ?>» من البيان العام. متابعة؟')"><?= csrfField() ?><input type="hidden" name="eoc_act" value="del"><input type="hidden" name="key" value="<?= e($r['key']) ?>"><button class="sp-btn sp-btn-sm sp-btn-del sp-btn-icon" title="حذف"><i class="fas fa-trash"></i></button></form>
              </div><?php endif; ?></td>
            </tr>
          <?php endforeach; if (!$rows): ?><tr><td colspan="14" class="sp-empty">لا يوجد أساتذة بعد.</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><td colspan="10">المجموع (كل الأساتذة)</td><td class="num"><?= e(rtrim(rtrim(number_format($tot['hours'], 1), '0'), '.')) ?></td><td class="num"><?= $fmt0($tot['salary']) ?></td><td class="num"><?= $amtM($tot['add_salary']) ?></td><td class="no-print"></td></tr></tfoot>
        </table></div>
        <?php if ($E && $delIds): $nm = $db->query("SELECT id, first_name_ar, last_name_ar FROM employees WHERE id IN (" . implode(',', $delIds) . ")")->fetchAll(); ?>
        <div class="sp-del-list no-print">أساتذة محذوفون من هذا البيان (يُحسبون بالبرنامج لكن لا يظهرون هنا): <?php foreach ($nm as $x): ?><form method="post"><?= csrfField() ?><input type="hidden" name="eoc_act" value="restore"><input type="hidden" name="key" value="<?= (int)$x['id'] ?>"><button class="sp-btn sp-btn-sm sp-btn-view"><?= e(eocNameAr($x)) ?> ↩ إرجاع</button></form> <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </section>
    </div>

  <?php else: /* ===== البيان المالي لفترة ===== */
    $tot = eocFinancialTotals($rows); $delIds = array_map('intval', $data['deleted']); $nM = count($pi['months']); ?>
    <div class="sp-page-head"><div class="sp-page-head-main">
        <a href="<?= e($self) ?>?tab=financial" class="sp-back no-print"><i class="fas fa-arrow-right"></i> البيان المالي</a>
        <div class="sp-title-row"><h1 class="sp-title">البيان المالي <span class="sp-title-sub num"><?= e($period) ?></span></h1><?= $pill($data['status']) ?></div>
        <div class="sp-title-note"><?= e($pi['label']) ?> — <?= e($pi['range']) ?> — <?= e($school['name_ar']) ?></div></div>
      <div class="sp-page-actions no-print">
        <?php if ($E): ?><form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="eoc_act" value="status"><button type="submit" class="sp-btn sp-btn-view"><i class="fas <?= $locked ? 'fa-lock-open' : 'fa-lock' ?>"></i> <?= $locked ? 'فتح للتعديل' : 'اعتماد وإقفال' ?></button></form><?php endif; ?>
        <button type="button" class="sp-btn sp-btn-view" onclick="window.print()"><i class="fas fa-print"></i> طباعة البيان</button>
      </div></div>
    <?php if ($locked): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span>هذا البيان معتمد ومقفل.</span></div><?php endif; ?>
    <?php if ($pi['special']): ?><div class="alert alert-info"><i class="fas fa-info-circle"></i><span>بيان خاص بشهر <?= e(monthName($pi['first'][1], 'ar')) ?> <?= $pi['first'][0] ?>: يُعبَّأ تلقائياً بأساتذة الملاك الذين لهم درجة أو نصف راتب بهذا الشهر (المبالغ بعمودَي «درجة» و«نصف راتب»)، والمحسومات لشهر واحد. عدّل أو احذف أو أضف كما يلزم.</span></div><?php endif; ?>

    <?php if ($E && !$locked): ?>
    <section id="sp-teacher-form" class="sp-panel sp-foldable <?= $showForm ? '' : 'sp-folded' ?>">
      <div class="sp-panel-head"><h2 class="sp-panel-title"><i class="fas fa-users"></i> <?= $editRow ? 'تعديل سطر — <span>' . e($editRow['teacher_name']) . '</span>' : 'الأساتذة' ?></h2>
        <?php if (!$showForm): ?><span class="sp-folded-hint">من هنا تُضاف أسطر الأساتذة إلى البيان</span><a class="sp-btn sp-btn-go" href="<?= e($back(['add' => 1])) ?>#sp-teacher-form">+ إضافة سطر</a><?php else: ?><span class="sp-panel-meta">الحقول المشار إليها بـ <span class="sp-req">*</span> إلزامية — المحسومات تُحتسب تلقائياً</span><?php endif; ?></div>
      <?php if ($showForm): $r = $editRow ?: array_fill_keys(eocFinancialFields(), '') + ['key' => '', 'src' => 'manual', 'ov' => []]; ?>
      <form method="post" id="bm_form"><?= csrfField() ?><input type="hidden" name="eoc_act" value="line"><input type="hidden" name="key" value="<?= e($r['key']) ?>">
        <div class="sp-panel-body">
          <div class="sp-fs"><h3 class="sp-fs-title">الأستاذ والراتب</h3><div class="sp-grid-4">
            <div><label for="bm_teacherno">الرقم المالي<span class="sp-req">*</span></label><input id="bm_teacherno" name="teacher_no" inputmode="numeric" autocomplete="off" value="<?= e((string)$r['teacher_no']) ?>"></div>
            <div><label for="bm_teachername">الاسم والشهرة</label><input id="bm_teachername" name="teacher_name" placeholder="<?= $r['src'] === 'auto' ? 'من البرنامج' : 'اسم الأستاذ' ?>" value="<?= e((string)$r['teacher_name']) ?>" <?= $r['src'] === 'auto' ? 'readonly' : '' ?>></div>
            <div><label for="bm_prevsal">الراتب الشهري السابق<span class="sp-req">*</span></label><input id="bm_prevsal" name="previous_salary" type="text" class="sp-amount" required value="<?= $r['previous_salary'] !== '' ? $fmt0($r['previous_salary']) : '' ?>"></div>
            <div><label for="bm_salary">الراتب الشهري الحالي<span class="sp-req">*</span></label><input id="bm_salary" name="current_salary" type="text" class="sp-amount" required value="<?= $r['current_salary'] !== '' ? $fmt0($r['current_salary']) : '' ?>"></div>
          </div></div>
          <div class="sp-fs"><h3 class="sp-fs-title">الإضافات والحسومات</h3><div class="sp-grid-4">
            <div><label for="bm_addsal">الأجر الاضافي<span class="sp-req">*</span></label><input id="bm_addsal" name="add_salary" type="text" class="sp-amount" required value="<?= $r['add_salary'] !== '' ? $fmt0($r['add_salary']) : '' ?>"></div>
            <div><label for="bm_tmdisc">المحسومات الشهرية (<span>6.0</span>%)</label><input id="bm_tmdisc" name="teacher_m_disc" type="text" class="sp-amount" placeholder="يُحتسب تلقائياً" value="<?= array_key_exists('teacher_m_disc', $r['ov']) && $r['ov']['teacher_m_disc'] !== '' ? $fmt0($r['teacher_m_disc']) : '' ?>"></div>
            <div><label for="bm_tqdisc">المحسومات الفصلية</label><input id="bm_tqdisc" name="teacher_q_disc" type="text" class="sp-amount" placeholder="يُحتسب تلقائياً" value="<?= array_key_exists('teacher_q_disc', $r['ov']) && $r['ov']['teacher_q_disc'] !== '' ? $fmt0($r['teacher_q_disc']) : '' ?>"></div>
            <div><label for="bm_sqdisc">مساهمة المدرسة (<span>6.0</span>%)</label><input id="bm_sqdisc" name="school_q_disc" type="text" class="sp-amount" placeholder="يُحتسب تلقائياً" value="<?= array_key_exists('school_q_disc', $r['ov']) && $r['ov']['school_q_disc'] !== '' ? $fmt0($r['school_q_disc']) : '' ?>"></div>
          </div></div>
          <div class="sp-fs"><h3 class="sp-fs-title">تفاصيل إضافية</h3><div class="sp-grid-4">
            <div><label for="bm_degree">مختلف، درجة تمرين</label><input id="bm_degree" name="training_degree" type="text" class="sp-amount" value="<?= $numv($r['training_degree']) > 0 ? $fmt0($r['training_degree']) : '' ?>"></div>
            <div><label for="bm_half">نصف راتب (المبلغ)</label><input id="bm_half" name="discount_half_salary" type="text" class="sp-amount" value="<?= $numv($r['discount_half_salary']) > 0 ? $fmt0($r['discount_half_salary']) : '' ?>"><div class="sp-hint">على المنصّة خانة تفتيش «نصف راتب» — هنا المبلغ نفسه</div></div>
            <div class="sp-checks"><label><input type="checkbox" id="bm_trainee" name="is_trainee" value="1" <?= (int)$r['is_trainee'] ? 'checked' : '' ?>> متمرن</label><label><input type="checkbox" id="bm_contrib" name="is_contribution" value="1" <?= (int)$r['is_contribution'] ? 'checked' : '' ?>> مساهمة</label></div>
            <div><label for="bm_remarks">ملاحظات</label><textarea id="bm_remarks" name="remarks" rows="2" placeholder="أدخل أي ملاحظات..."><?= e((string)$r['remarks']) ?></textarea></div>
          </div></div>
        </div>
        <div class="sp-panel-foot"><button type="submit" class="sp-btn sp-btn-go"><i class="fas fa-check"></i> حفظ</button><a class="sp-btn sp-btn-view" href="<?= e($back()) ?>#sp-list">إلغاء</a><span class="sp-panel-meta">المحسومات الشهرية = 6٪ × (الراتب الحالي + الأجر الإضافي)، الفصلية = × <?= $nM ?>؛ اتركها فارغة لتُحتسب تلقائياً</span></div>
      </form>
      <script>(function(){ var g=function(id){return document.getElementById(id);}, num=function(v){v=parseFloat(String(v||'').replace(/,/g,''));return isNaN(v)?0:v;}, f=function(n){return Math.round(n).toLocaleString('en-US');};
        var sal=g('bm_salary'),add=g('bm_addsal'),tm=g('bm_tmdisc'),tq=g('bm_tqdisc'),sq=g('bm_sqdisc'),tr=g('bm_trainee'),co=g('bm_contrib'),prev=g('bm_prevsal'),deg=g('bm_degree'),N=<?= $nM ?>;
        function ph(){ var b=num(sal.value)+num(add.value); tm.placeholder=co.checked?'0':f(b*0.06); tq.placeholder=co.checked?'0':f(b*0.06*N); sq.placeholder=tr.checked?'0':f(b*0.06*N); }
        function autodeg(){ if(deg.dataset.touched) return; var d=num(sal.value)-num(prev.value); if(d>0 && !deg.value) deg.placeholder=f(d)+' (الحالي − السابق)'; }
        [sal,add,prev].forEach(function(x){ x.addEventListener('input',function(){ ph(); autodeg(); }); }); [tr,co].forEach(function(x){ x.addEventListener('change',function(){ if(x===tr&&tr.checked) co.checked=false; if(x===co&&co.checked) tr.checked=false; ph(); }); }); deg.addEventListener('input',function(){ deg.dataset.touched='1'; }); ph(); autodeg(); })();</script>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <div id="ppExportArea" class="doc-sheet land-report rl-fixed" dir="rtl" style="padding:0;border:0;background:transparent;box-shadow:none">
    <div class="doc-head" style="display:none"><div class="dh-ar">البيان المالي <?= e($period) ?> — <?= e($pi['label']) ?> — <?= e($school['name_ar']) ?></div></div>
    <section class="sp-panel" id="sp-list">
      <div class="sp-panel-head"><h2 class="sp-panel-title">سطور البيان</h2><span class="sp-count">عدد الأساتذة: <strong><?= $tot['count'] ?></strong></span></div>
      <div class="sp-panel-body">
        <div class="sp-toolbar no-print"><form method="get" class="sp-toolbar-form"><input type="hidden" name="tab" value="financial"><input type="hidden" name="period" value="<?= e($period) ?>">
          <input type="text" name="search" placeholder="الرقم المالي أو الاسم" value="<?= e($search) ?>"><button type="submit" class="sp-btn sp-btn-view sp-btn-sm">بحث</button>
          <label>ترتيب حسب</label><select name="sort" onchange="this.form.submit()"><option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>الاسم (أبجدي)</option><option value="teacher_no" <?= $sort === 'teacher_no' ? 'selected' : '' ?>>الرقم المالي</option><option value="salary" <?= $sort === 'salary' ? 'selected' : '' ?>>الراتب الحالي (الأعلى أولاً)</option><option value="deduction" <?= $sort === 'deduction' ? 'selected' : '' ?>>المحسومات الفصلية (الأعلى أولاً)</option><option value="mismatch" <?= $sort === 'mismatch' ? 'selected' : '' ?>>المخالفات أولاً</option></select></form></div>
        <div class="sp-table-wrap"><table class="sp-data">
          <thead><tr><th class="sp-col-idx">#</th><th>الرقم المالي</th><th>الاسم والشهرة</th><th class="num">الراتب السابق</th><th class="num">الراتب الحالي</th><th class="num">الأجر الاضافي</th><th class="num">محسومات شهرية</th><th class="num">محسومات فصلية</th><th class="num">نصف راتب</th><th class="num">درجة / مختلف</th><th class="num">المبلغ الإضافي</th><th class="sp-col-act no-print"></th></tr></thead>
          <tbody>
          <?php $i = 0; foreach ($rows as $r): $i++; $cl = fn(string $f, string $extra = 'num') => 'class="' . $extra . (in_array($f, $r['missing'], true) ? ' sp-cell-missing' : '') . (array_key_exists($f, $r['ov']) && $r['ov'][$f] !== '' ? ' sp-ovd' : '') . '"'; ?>
            <tr class="<?= $r['missing'] ? 'sp-row-has-missing' : '' ?>" data-key="<?= e($r['key']) ?>">
              <td class="num sp-col-idx"><?= $i ?></td>
              <td <?= $cl('teacher_no') ?>><?= (string)$r['teacher_no'] === '' ? '<span class="sp-miss">ناقص</span>' : e((string)$r['teacher_no']) ?></td>
              <td <?= $cl('teacher_name', 'sp-col-name') ?>><?= e((string)$r['teacher_name']) ?><?= (int)$r['is_trainee'] ? ' <span class="sp-pill sp-open">متمرن</span>' : '' ?><?= (int)$r['is_contribution'] ? ' <span class="sp-pill sp-open">مساهمة</span>' : '' ?></td>
              <td <?= $cl('previous_salary') ?>><?= $fmt0($r['previous_salary']) ?></td>
              <td <?= $cl('current_salary') ?>><?= $fmt0($r['current_salary']) ?></td>
              <td <?= $cl('add_salary') ?>><?= $amtM($r['add_salary']) ?></td>
              <td <?= $cl('teacher_m_disc') ?>><?= $fmt0($r['teacher_m_disc']) ?></td>
              <td <?= $cl('teacher_q_disc') ?>><?= $fmt0($r['teacher_q_disc']) ?></td>
              <td <?= $cl('discount_half_salary') ?>><?= $fmt0($r['discount_half_salary']) ?></td>
              <td <?= $cl('training_degree') ?>><?= $fmt0($r['training_degree']) ?></td>
              <td class="num"><?= $fmt0($r['extra_amount']) ?></td>
              <td class="sp-col-act no-print"><?php if ($E && !$locked): ?><div class="sp-actions">
                <a class="sp-btn sp-btn-sm sp-btn-view sp-btn-icon" title="تعديل" href="<?= e($back(['edit' => $r['key']])) ?>#sp-teacher-form"><i class="fas fa-pencil-alt"></i></a>
                <?php if ($r['src'] === 'auto' && $r['ov']): ?><form method="post" onsubmit="return confirm('إرجاع السطر محسوباً من البرنامج؟')"><?= csrfField() ?><input type="hidden" name="eoc_act" value="reset"><input type="hidden" name="key" value="<?= e($r['key']) ?>"><button class="sp-btn sp-btn-sm sp-btn-view sp-btn-icon" title="↺ تلقائي من البرنامج">↺</button></form><?php endif; ?>
                <form method="post" onsubmit="return confirm('سيتم حذف «<?= e((string)$r['teacher_name']) ?>» من هذا البيان. متابعة؟')"><?= csrfField() ?><input type="hidden" name="eoc_act" value="del"><input type="hidden" name="key" value="<?= e($r['key']) ?>"><button class="sp-btn sp-btn-sm sp-btn-del sp-btn-icon" title="حذف"><i class="fas fa-trash"></i></button></form>
              </div><?php endif; ?></td>
            </tr>
          <?php endforeach; if (!$rows): ?><tr><td colspan="12" class="sp-empty">لا سطور بعد — لا رواتب محسوبة لأوّل شهر هذا الفصل، أو أضف الأسطر يدوياً.</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><td colspan="3">المجموع (كل الأساتذة)</td><td class="num"><?= $fmt0($tot['previous_salary']) ?></td><td class="num"><?= $fmt0($tot['current_salary']) ?></td><td class="num"><?= $amtM($tot['add_salary']) ?></td><td class="num"><?= $fmt0($tot['teacher_m_disc']) ?></td><td class="num"><?= $fmt0($tot['teacher_q_disc']) ?></td><td class="num"><?= $fmt0($tot['discount_half_salary']) ?></td><td class="num"><?= $fmt0($tot['training_degree']) ?></td><td class="num"><?= $fmt0($tot['extra_amount']) ?></td><td class="no-print"></td></tr></tfoot>
        </table></div>
        <?php if ($E && $delIds): $nm = $db->query("SELECT id, first_name_ar, last_name_ar FROM employees WHERE id IN (" . implode(',', $delIds) . ")")->fetchAll(); ?>
        <div class="sp-del-list no-print">أساتذة محذوفون من هذا البيان: <?php foreach ($nm as $x): ?><form method="post"><?= csrfField() ?><input type="hidden" name="eoc_act" value="restore"><input type="hidden" name="key" value="<?= (int)$x['id'] ?>"><button class="sp-btn sp-btn-sm sp-btn-view"><?= e(eocNameAr($x)) ?> ↩ إرجاع</button></form> <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </section>
    <section class="sp-panel">
      <div class="sp-panel-head"><h2 class="sp-panel-title">المجاميع</h2></div>
      <div class="sp-panel-body">
        <div class="sp-summary">
          <div class="sp-stat"><div class="sp-stat-label">المحسومات المقتطعة</div><div class="sp-stat-value num"><?= $fmt0($tot['teacher_total']) ?></div><div class="sp-stat-split">منها محسومات فصلية: <span class="num"><?= $fmt0($tot['teacher_q_disc']) ?></span></div><div class="sp-stat-split" style="margin-top:2px">منها المبلغ الإضافي (نصف راتب + درجة): <span class="num"><?= $fmt0($tot['extra_amount']) ?></span></div></div>
          <div class="sp-stat"><div class="sp-stat-label">مساهمة المدرسة</div><div class="sp-stat-value num"><?= $fmt0($tot['school_total']) ?></div></div>
          <div class="sp-stat sp-stat-main"><div class="sp-stat-label">المجموع العام</div><div class="sp-stat-value num"><?= $fmt0($tot['grand']) ?></div></div>
        </div>
        <p class="sp-note" style="margin-top:12px">المادة 6 من المرسوم الاشتراعي 47 تاريخ 29/06/1983.</p>
      </div>
    </section>
    </div>
  <?php endif; ?>
  </main>
  <div class="sp-footer">Copyright for Educational Staff in Private Schools in Lebanon — نسخة البرنامج عن بوّابة المدارس</div>
</div>

<script>
/* 🔢 «بفواصل / بلا فواصل» على الشاشة فقط (للنسخ واللصق إلى خانات المنصّة) — الطباعة والتصدير بفواصل دائماً */
var EOC_SEP_RE=/\d{1,3}(?:,\d{3})+(?:\.\d+)?/g;
function eocSepApply(on){
    var root=document.querySelector('.sp-app'); if(!root) return;
    var tw=document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode:function(n){ var p=n.parentElement; if(!p||p.closest('input,textarea,select,script,style,form')) return NodeFilter.FILTER_REJECT; return NodeFilter.FILTER_ACCEPT; } });
    var nodes=[], n; while((n=tw.nextNode())) nodes.push(n);
    nodes.forEach(function(tn){ if(!on){ if(EOC_SEP_RE.test(tn.nodeValue)){ EOC_SEP_RE.lastIndex=0; if(tn.__sepv===undefined) tn.__sepv=tn.nodeValue; tn.nodeValue=tn.__sepv.replace(EOC_SEP_RE,function(m){ return m.replace(/,/g,''); }); } EOC_SEP_RE.lastIndex=0; } else if(tn.__sepv!==undefined){ tn.nodeValue=tn.__sepv; delete tn.__sepv; } });
    document.querySelectorAll('.eoc-sep button').forEach(function(b){ var act=(b.dataset.sep==='1')===on; b.classList.toggle('btn-primary',act); b.classList.toggle('btn-light',!act); });
    document.body.classList.toggle('eoc-nosep',!on);
}
function eocSep(on){ try{ localStorage.setItem('eoc_sep', on?'1':'0'); }catch(e){} eocSepApply(on); }
(function(){
    var on=true; try{ on=localStorage.getItem('eoc_sep')!=='0'; }catch(e){} eocSepApply(on);
    var restoreThen=function(fn){ return function(){ var cur=document.body.classList.contains('eoc-nosep'); if(cur) eocSepApply(true); var r; try{ r=fn.apply(this,arguments); } finally { if(cur) setTimeout(function(){ eocSepApply(false); },1500); } return r; }; };
    ['msaSavePdfStart','ppWord','ppWhatsApp','ppEmail'].forEach(function(k){ if(typeof window[k]==='function' && !window[k].__eocSep){ var o=window[k]; window[k]=restoreThen(o); window[k].__eocSep=true; } });
    window.addEventListener('beforeprint', function(){ if(document.body.classList.contains('eoc-nosep')){ eocSepApply(true); document.body.classList.add('eoc-nosep-print'); } });
    window.addEventListener('afterprint', function(){ if(document.body.classList.contains('eoc-nosep-print')){ document.body.classList.remove('eoc-nosep-print'); eocSepApply(false); } });
    if(location.hash){ var t=document.querySelector(location.hash); if(t) setTimeout(function(){ t.scrollIntoView({block:'start'}); },50); }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
