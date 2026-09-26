<?php
/**
 * 🗂️ تاريخ الأستاذ/الموظف الكامل / Historique complet (2026-09-26)
 * «بدك تعمل صفحة نبقى نفتّش فيها عن أستاذ أو موظف معيّن إن كان تارك أو مش تارك ونشوف كامل تاريخ الاستخدام بالمدرسة
 *  من وقت ما بيبلّش حتى تاريخه وتفاصيل رواتبه كلها مع أرقام الصندوق والمالية والضمان… صفحة مستقلّة عن كل شي»
 * صفحة قراءة مستقلّة: بحث بكل الموظفين (بغضّ النظر عن السنة والترك) ⇒ بطاقة التعريف + الأرقام الرسمية + خطّ زمني
 * (الدخول/الترسيم/الدرجات/القرارات/الترك) + كل سنوات الرواتب شهراً شهراً بحصص الموظف والمؤسّسة + البنود + التعويض العائلي.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();

$currentPage = 'employee_full_history';
$pageTitle = 'Historique employé / تاريخ الأستاذ الكامل';
$db = getDB();

$q  = trim((string)($_GET['q'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

// ===== البحث: كل الموظفين ضمن المدارس المسموحة — تارك أو لا، بأي سنة =====
$results = [];
if ($q !== '' && $id <= 0) {
    $like = '%' . $q . '%'; $start = $q . '%';
    $digits = preg_replace('/\D/', '', $q); $dlike = $digits !== '' ? '%' . $digits . '%' : "\0";
    // 🎂 «ويكون البحث كمان بتاريخ الولادة أو التلفون»: 15/08/1975 أو 15-8-1975 أو 1975-08-15 = تاريخ الولادة نفسه؛ 1975 وحدها = سنة الولادة؛
    //    وأي أرقام تُطابَق بالهاتف (بلا فواصل) كما بالبحث العلوي
    $bd = "\0"; $by = "\0";
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $q, $dm)) $bd = sprintf('%04d-%02d-%02d', $dm[3], $dm[2], $dm[1]);
    elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $q, $dm)) $bd = sprintf('%04d-%02d-%02d', $dm[1], $dm[2], $dm[3]);
    elseif (preg_match('/^(19|20)\d{2}$/', $q)) $by = $q;
    $st = $db->prepare("SELECT e.id, e.employee_code, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr, e.employee_type, e.school_id, e.hire_date, e.birth_date, e.phone1, " . leftDateSql('e.') . " left_on,
            CASE WHEN COALESCE(e.first_name_ar,'') LIKE ? OR COALESCE(e.first_name_fr,'') LIKE ? THEN 0 WHEN COALESCE(e.last_name_ar,'') LIKE ? OR COALESCE(e.last_name_fr,'') LIKE ? THEN 1 ELSE 2 END rk
        FROM employees e WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . "
          AND (e.employee_code LIKE ? OR CONCAT(COALESCE(e.first_name_fr,''),' ',COALESCE(e.last_name_fr,'')) LIKE ?
               OR CONCAT(COALESCE(e.first_name_ar,''),' ',COALESCE(e.father_name_ar,''),' ',COALESCE(e.last_name_ar,'')) LIKE ?
               OR CONCAT(COALESCE(e.first_name_ar,''),' ',COALESCE(e.last_name_ar,'')) LIKE ?
               OR REPLACE(REPLACE(REPLACE(COALESCE(e.phone1,''),'-',''),' ',''),'/','') LIKE ? OR REPLACE(REPLACE(REPLACE(COALESCE(e.phone2,''),'-',''),' ',''),'/','') LIKE ?
               OR COALESCE(e.nssf_number,'') LIKE ? OR COALESCE(e.finance_ministry_number,'') LIKE ?
               OR e.birth_date = ? OR YEAR(e.birth_date) = ?)
        ORDER BY rk, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr) LIMIT 80");
    $st->execute([$start, $start, $start, $start, $like, $like, $like, $like, $dlike, $dlike, $like, $like, $bd, $by]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($results) === 1) { header('Location: ' . BASE_URL . 'pages/employee_full_history.php?id=' . (int)$results[0]['id']); exit; }
}

// ===== الموظف المختار =====
$emp = null; $years = []; $bonuses = []; $grades = []; $decisions = []; $famChanges = [];
if ($id > 0) {
    $st = $db->prepare("SELECT e.*, s.name_ar school_ar, s.name_fr school_fr FROM employees e LEFT JOIN schools s ON s.id = e.school_id WHERE e.id = ? AND e.is_deleted = 0" . schoolScopeSql('e.school_id'));
    $st->execute([$id]); $emp = $st->fetch(PDO::FETCH_ASSOC);
    if ($emp) {
        $ms = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0 OR cnss_amount_lbp > 0) ORDER BY school_year, year, month");
        $ms->execute([$id]);
        foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $r) $years[$r['school_year']][] = $r;
        $bq = $db->prepare("SELECT * FROM employee_bonuses WHERE employee_id = ? ORDER BY school_year, id"); $bq->execute([$id]); $bonuses = $bq->fetchAll(PDO::FETCH_ASSOC);
        $gq = $db->prepare("SELECT * FROM employee_grade_history WHERE employee_id = ? ORDER BY change_date, id"); $gq->execute([$id]); $grades = $gq->fetchAll(PDO::FETCH_ASSOC);
        try { $dq = $db->prepare("SELECT * FROM compliance_decisions WHERE employee_id = ? AND decision IN ('approved','rejected','auto') ORDER BY decided_at"); $dq->execute([$id]); $decisions = $dq->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $decisions = []; }
        try { $famChanges = function_exists('familyAllowanceChangesRows') ? familyAllowanceChangesRows($id) : []; } catch (Throwable $e) { $famChanges = []; }
    }
}

include __DIR__ . '/../includes/header.php';

$fmt = fn($v) => number_format((int)$v);
$d = fn($v) => ($v && $v !== '0000-00-00' && $v >= '1900-01-01') ? date('d/m/Y', strtotime($v)) : '—';
$typeLbl = ['enseignant_titulaire' => 'أستاذ ملاك / Enseignant titulaire', 'enseignant_contractuel' => 'أستاذ متعاقد / Enseignant contractuel', 'employe' => 'موظف (قانون العمل) / Employé'];
$bonusLbl = ['prime_fixe' => 'الأجر الإضافي', 'aide_complementaire' => 'مكافأة ومساعدة', 'transport_complement' => 'نقل شهري', 'transport_daily' => 'نقل يومي', 'extra' => 'إضافي', 'prime_aide' => 'مكافأة'];
$dipLbl = [];
try { foreach ($db->query("SELECT diploma_code, diploma_name_ar, diploma_name_fr FROM diploma_starting_grades") as $dr) $dipLbl[$dr['diploma_code']] = $dr['diploma_name_ar'] . ' / ' . $dr['diploma_name_fr']; } catch (Throwable $e) {}
?>
<style>
.eh-search { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.eh-search .form-control { min-width:280px; }
.eh-res { width:100%; border-collapse:collapse; font-size:13.5px; }
.eh-res th, .eh-res td { border:1px solid #e2e8f0; padding:6px 8px; text-align:right; }
.eh-res th { background:#1F4E5F; color:#fff; }
.eh-left { color:#b91c1c; font-size:11.5px; background:#fee2e2; border-radius:6px; padding:1px 7px; white-space:nowrap; }
.eh-now { color:#166534; font-size:11.5px; background:#dcfce7; border-radius:6px; padding:1px 7px; white-space:nowrap; }
.eh-id { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:8px 14px; }
.eh-id .k { color:#64748b; font-size:11.5px; }
.eh-id .v { font-weight:700; font-size:14px; }
.eh-num { direction:ltr; text-align:left; font-family:Arial, sans-serif; }
.eh-tl { list-style:none; margin:0; padding:0; border-inline-start:3px solid #1F4E5F; }
.eh-tl li { padding:4px 14px; position:relative; font-size:13.5px; }
.eh-tl li::before { content:''; position:absolute; inset-inline-start:-8px; top:11px; width:12px; height:12px; border-radius:50%; background:#1F4E5F; }
.eh-tl li.g::before { background:#2563eb; } .eh-tl li.x::before { background:#b91c1c; } .eh-tl li.d::before { background:#d97706; }
.eh-tl .dt { display:inline-block; min-width:92px; font-weight:700; direction:ltr; text-align:left; }
.eh-wrap { overflow:auto; }
table.eh-year { width:100%; border-collapse:collapse; font-size:12.5px; white-space:nowrap; }
.eh-year th, .eh-year td { border:1px solid #e2e8f0; padding:4px 6px; text-align:center; }
.eh-year thead th { background:#1F4E5F; color:#fff; font-weight:700; position:sticky; top:var(--msa-top, 0); z-index:2; }
.eh-year thead th.sch { background:#475569; }
.eh-year td.num { text-align:right; direction:ltr; }
.eh-year tr.tot td { background:#e2e8f0; font-weight:800; }
.eh-year td.paid { color:#166534; font-weight:700; } .eh-year td.unpaid { color:#b45309; }
.eh-ytitle { display:flex; justify-content:space-between; align-items:center; background:#f1f5f9; padding:8px 12px; border-radius:8px; margin:14px 0 6px; font-weight:800; }
.card.eh-card { overflow:visible; }
@media print { .no-print { display:none !important; } .eh-year thead th { position:static; } .card { box-shadow:none; border:none; } }
</style>

<div class="card eh-card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-clock-rotate-left"></i> Historique complet — enseignant / employé</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">التاريخ الكامل للأستاذ أو الموظف — من دخوله المدرسة حتى تاريخه (تارك أو مستمرّ)</div>
    </h3></div>
    <div class="card-body">
        <form method="GET" class="eh-search no-print" autocomplete="off">
            <div class="form-group" style="margin:0">
                <label class="form-label">Rechercher / فتّش بالاسم أو الهاتف أو تاريخ الولادة (15/08/1975 أو 1975) أو رقم الضمان/المالية</label>
                <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="اسم… أو 03-123456 أو 15/08/1975" autofocus>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Chercher / فتّش</button>
            <?php if ($emp): ?><a class="btn btn-secondary" href="<?= BASE_URL ?>pages/employee_full_history.php"><i class="fas fa-rotate-left"></i> بحث جديد</a>
            <button type="button" class="btn btn-light" onclick="window.print()"><i class="fas fa-print"></i> Imprimer / طباعة</button><?php endif; ?>
        </form>

<?php if ($q !== '' && !$emp): ?>
        <div style="margin-top:12px">
        <?php if (!$results): ?>
            <div class="alert alert-info">لا نتائج لـ«<?= e($q) ?>» / Aucun résultat</div>
        <?php else: ?>
            <table class="eh-res">
                <thead><tr><th>#</th><th>Nom / الاسم</th><th>الرقم</th><th>الولادة</th><th>الهاتف</th><th>الفئة</th><th>المدرسة</th><th>الدخول</th><th>الحالة</th></tr></thead>
                <tbody>
                <?php foreach ($results as $i => $r): $ln = $r['left_on'] ?? ''; $left = ($ln && $ln !== '9999-12-31'); ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><a href="<?= BASE_URL ?>pages/employee_full_history.php?id=<?= (int)$r['id'] ?>" style="font-weight:700"><?= e(trim(($r['first_name_ar'] ?: $r['first_name_fr']) . ' ' . ($r['last_name_ar'] ?: $r['last_name_fr']))) ?></a>
                            <small dir="ltr" style="color:#64748b"> <?= e(trim($r['first_name_fr'] . ' ' . $r['last_name_fr'])) ?></small></td>
                        <td class="eh-num"><?= e($r['employee_code']) ?></td>
                        <td class="eh-num"><?= $d($r['birth_date']) ?></td>
                        <td class="eh-num"><?= e($r['phone1'] ?: '—') ?></td>
                        <td><?= e($typeLbl[$r['employee_type']] ?? $r['employee_type']) ?></td>
                        <td><?= e(schoolNameById((int)$r['school_id'], 'ar')) ?></td>
                        <td class="eh-num"><?= $d($r['hire_date']) ?></td>
                        <td><?= $left ? '<span class="eh-left">🚪 ترك ' . $d($ln) . '</span>' : '<span class="eh-now">مستمرّ</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
<?php elseif ($emp):
        $name = trim(($emp['first_name_ar'] ?: $emp['first_name_fr']) . ' ' . ($emp['father_name_ar'] ? $emp['father_name_ar'] . ' ' : '') . ($emp['last_name_ar'] ?: $emp['last_name_fr']));
        $nameFr = trim($emp['first_name_fr'] . ' ' . $emp['last_name_fr']);
        $leftOn = leftDateOf($emp);
        $isLeft = ($leftOn && $leftOn !== '9999-12-31');
        $endRef = $isLeft ? $leftOn : date('Y-m-d');
        $svc = '';
        if (!empty($emp['hire_date']) && $emp['hire_date'] >= '1900-01-01') { try { $di = date_diff(date_create($emp['hire_date']), date_create($endRef)); $svc = $di->y . ' سنة و' . $di->m . ' شهر'; } catch (Throwable $e) {} }
        $age = ageOnDate($emp['birth_date'] ?? '');
        // الخطّ الزمني
        $tl = [];
        if (!empty($emp['hire_date']) && $emp['hire_date'] >= '1900-01-01') $tl[] = ['d' => $emp['hire_date'], 'c' => '', 't' => 'دخول المدرسة / Embauche'];
        if (!empty($emp['titularization_date']) && $emp['titularization_date'] >= '1900-01-01' && $emp['employee_type'] === 'enseignant_titulaire') $tl[] = ['d' => $emp['titularization_date'], 'c' => 'g', 't' => 'دخول الملاك / Titularisation' . (!empty($emp['cadre_from_sy']) ? ' (سنة ' . $emp['cadre_from_sy'] . ')' : '')];
        foreach ($grades as $g) { if ((float)$g['grade_before'] == 0 && $g['reason'] === 'titularization') continue; $tl[] = ['d' => $g['change_date'], 'c' => 'g', 't' => 'درجة: ' . rtrim(rtrim(number_format((float)$g['grade_before'], 1), '0'), '.') . ' ← ' . rtrim(rtrim(number_format((float)$g['grade_after'], 1), '0'), '.') . ' — ' . e($g['notes'] ?: $g['reason']) . ((int)($g['counted'] ?? 1) === 0 ? ' (غير محتسبة)' : '') . (!empty($g['user_edited']) ? ' ✍️' : '')]; }
        foreach ($decisions as $dc) { $tl[] = ['d' => substr((string)$dc['decided_at'], 0, 10), 'c' => 'd', 't' => e(($dc['decision'] === 'auto' ? 'تلقائياً: ' : ($dc['decision'] === 'approved' ? 'موافقة: ' : 'رفض: ')) . mb_substr((string)$dc['violation'], 0, 140))]; }
        foreach (['left_date_all' => 'ترك من الكل (نهائي)', 'left_date_cnss' => 'ترك الضمان', 'left_date_finance' => 'ترك المالية', 'left_date_eoc' => 'ترك صندوق التعويضات'] as $k => $l) if (!empty($emp[$k]) && $emp[$k] >= '1900-01-01') $tl[] = ['d' => $emp[$k], 'c' => 'x', 't' => $l];
        if (!empty($emp['keep_working_past_64'])) $tl[] = ['d' => date('Y-m-d', strtotime(($emp['birth_date'] ?: '1900-01-01') . ' +64 years')), 'c' => 'd', 't' => 'بلوغ 64 مع قرار الإبقاء'];
        usort($tl, fn($a, $b) => strcmp($a['d'], $b['d']));
        // مجموع كل السنين
        $grand = ['net' => 0, 'due' => 0, 'caisse' => 0, 'cnss' => 0, 'tax' => 0, 'fam' => 0, 'n' => 0, 'paid' => 0];
?>
        <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
            <div>
                <div style="font-size:22px;font-weight:900;color:#1F4E5F"><?= e($name) ?> <?= $isLeft ? '<span class="eh-left">🚪 ترك ' . $d($leftOn) . '</span>' : '<span class="eh-now">مستمرّ حتى تاريخه</span>' ?></div>
                <div dir="ltr" style="color:#64748b;font-weight:600"><?= e($nameFr) ?> — <?= e($emp['school_fr'] ?: $emp['school_ar']) ?></div>
            </div>
            <div class="no-print">
                <a class="btn btn-light" href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $id ?>" target="_blank"><i class="fas fa-user-pen"></i> ملفه</a>
                <a class="btn btn-light" href="<?= BASE_URL ?>pages/attestations.php?employee_id=<?= $id ?>&dossier=1" target="_blank"><i class="fas fa-folder-open"></i> إفاداته</a>
                <a class="btn btn-light" href="<?= BASE_URL ?>pages/grades.php?employee_id=<?= $id ?>" target="_blank"><i class="fas fa-layer-group"></i> درجاته</a>
            </div>
        </div>

        <h4 style="margin:16px 0 8px;color:#1F4E5F"><i class="fas fa-id-card"></i> Identité & numéros officiels / التعريف والأرقام الرسمية</h4>
        <div class="eh-id">
            <div><div class="k">الرقم بالبرنامج / Code</div><div class="v eh-num"><?= e($emp['employee_code'] ?: $id) ?></div></div>
            <div><div class="k">الفئة / Catégorie</div><div class="v"><?= e($typeLbl[$emp['employee_type']] ?? $emp['employee_type']) ?><?= !empty($emp['job_title']) ? ' — ' . e($emp['job_title']) : '' ?></div></div>
            <div><div class="k">المدرسة / École</div><div class="v"><?= e($emp['school_ar'] ?: $emp['school_fr']) ?></div></div>
            <div><div class="k">الشهادة / Diplôme</div><div class="v"><?= e($dipLbl[$emp['diploma'] ?? ''] ?? ($emp['diploma'] ?: '—')) ?></div></div>
            <div><div class="k">تاريخ الولادة / Naissance</div><div class="v eh-num"><?= $d($emp['birth_date']) ?><?= $age !== null ? ' (' . $age . ' سنة)' : '' ?></div></div>
            <div><div class="k">دخول المدرسة / Embauche</div><div class="v eh-num"><?= $d($emp['hire_date']) ?></div></div>
            <div><div class="k">دخول الملاك / Titularisation</div><div class="v eh-num"><?= $emp['employee_type'] === 'enseignant_titulaire' ? $d($emp['titularization_date']) : '—' ?></div></div>
            <div><div class="k">مدّة الخدمة / Ancienneté</div><div class="v"><?= e($svc ?: '—') ?><?= $isLeft ? ' (حتى تركه)' : '' ?></div></div>
            <div><div class="k">الدرجة الحالية / Échelon</div><div class="v eh-num"><?= $emp['employee_type'] === 'employe' ? '—' : rtrim(rtrim(number_format((float)$emp['current_grade'], 1), '0'), '.') ?></div></div>
            <div><div class="k">رقم الضمان / N° CNSS</div><div class="v eh-num"><?= e(cnssWithBirthYear($emp['nssf_number'], $emp['birth_date'])) ?></div></div>
            <div><div class="k">رقم وزارة المالية / N° Finances</div><div class="v eh-num"><?= e($emp['finance_ministry_number'] ?: '—') ?></div></div>
            <div><div class="k">رقم صندوق التعويضات / N° Caisse</div><div class="v eh-num"><?= e($emp['caisse_number'] ?: '—') ?></div></div>
            <div><div class="k">الهاتف / Téléphone</div><div class="v eh-num"><?= e(trim(implode(' / ', array_filter([trim((string)$emp['phone1']), trim((string)$emp['phone2'])]))) ?: '—') ?></div></div>
            <div><div class="k">ترك من الكل / Départ définitif</div><div class="v eh-num"><?= $d($emp['left_date_all']) ?></div></div>
            <div><div class="k">ترك الضمان · المالية · الصندوق</div><div class="v eh-num"><?= $d($emp['left_date_cnss']) ?> · <?= $d($emp['left_date_finance']) ?> · <?= $d($emp['left_date_eoc']) ?></div></div>
            <div><div class="k">الخضوع: ضمان · ضريبة · صندوق</div><div class="v"><?= (int)$emp['cnss_subject'] ? 'ضمان ✓' : 'ضمان ✗' ?> · <?= (int)$emp['tax_subject'] ? 'ضريبة ✓' : 'ضريبة ✗' ?> · <?= (int)$emp['eoc_subject'] ? 'صندوق ✓' : 'صندوق ✗' ?></div></div>
            <div><div class="k">التعويض العائلي بالملف</div><div class="v eh-num"><?= $fmt($emp['family_allowance_spouse_lbp']) ?> زوجة · <?= $fmt($emp['family_allowance_children_lbp']) ?> أولاد</div></div>
        </div>

        <h4 style="margin:18px 0 8px;color:#1F4E5F"><i class="fas fa-timeline"></i> Chronologie / الخطّ الزمني</h4>
        <?php if (!$tl): ?><div style="color:#64748b">لا أحداث مسجّلة.</div><?php else: ?>
        <ul class="eh-tl">
            <?php foreach ($tl as $ev): ?><li class="<?= $ev['c'] ?>"><span class="dt"><?= $d($ev['d']) ?></span> <?= $ev['t'] ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($bonuses): ?>
        <h4 style="margin:18px 0 8px;color:#1F4E5F"><i class="fas fa-gift"></i> Primes & transport / بنود الإضافي والمكافآت والنقل</h4>
        <div class="eh-wrap"><table class="eh-year" style="max-width:900px">
            <thead><tr><th>السنة</th><th>البند</th><th>القيمة</th><th>من شهر</th><th>إلى شهر</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php foreach ($bonuses as $b): ?>
                <tr>
                    <td><?= e($b['school_year'] ?: '—') ?></td>
                    <td><?= e($bonusLbl[$b['bonus_type']] ?? $b['bonus_type']) ?></td>
                    <td class="num"><?= $b['value_type'] === 'percent' ? rtrim(rtrim(number_format((float)$b['amount'], 2), '0'), '.') . ' %' : number_format((float)$b['amount']) . ' ' . e($b['currency'] ?: 'LBP') ?></td>
                    <td><?= $b['start_month'] ? monthName((int)$b['start_month'], 'ar') : 'كل السنة' ?></td>
                    <td><?= $b['end_month'] ? monthName((int)$b['end_month'], 'ar') : '' ?></td>
                    <td><?= (int)$b['is_active'] ? '<span class="eh-now">فعّال</span>' : '<span style="color:#94a3b8">ملغى</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>

        <?php if ($famChanges): ?>
        <h4 style="margin:18px 0 8px;color:#1F4E5F"><i class="fas fa-people-roof"></i> تغييرات التعويض العائلي خلال السنين</h4>
        <ul class="eh-tl"><?php foreach ($famChanges as $fc): ?><li class="d"><span class="dt"><?= $d($fc['from_month'] ?? '') ?></span> <?= ($fc['kind'] ?? '') === 'spouse' ? 'الزوجة' : 'الأولاد' ?>: <?= $fmt($fc['amount_lbp'] ?? 0) ?> ل.ل من هذا الشهر</li><?php endforeach; ?></ul>
        <?php endif; ?>

        <h4 style="margin:18px 0 4px;color:#1F4E5F"><i class="fas fa-money-check-dollar"></i> Salaires — toutes les années / الرواتب كل السنين شهراً شهراً</h4>
        <?php if (!$years): ?><div style="color:#64748b">لا رواتب مخزّنة لهذا الموظف.</div><?php endif; ?>
        <?php foreach ($years as $sy => $rows):
            $t = ['bpe' => 0, 'pf' => 0, 'aide' => 0, 'tr' => 0, 'fam' => 0, 'caisse' => 0, 'eocg' => 0, 'cnss' => 0, 'tax' => 0, 'ret' => 0, 'net' => 0, 'due' => 0, 'c8' => 0, 'e6' => 0, 'f6' => 0, 'eos' => 0, 'paid' => 0];
            foreach ($rows as $r) { $t['bpe'] += $r['base_plus_echelon_lbp']; $t['pf'] += $r['prime_fixe_lbp'] + $r['extra_lbp']; $t['aide'] += $r['aide_complementaire_lbp']; $t['tr'] += $r['transport_lbp']; $t['fam'] += $r['family_allowance_lbp']; $t['caisse'] += $r['caisse_amount_lbp']; $t['eocg'] += $r['eoc_grade_lbp']; $t['cnss'] += $r['cnss_amount_lbp']; $t['tax'] += $r['income_tax_lbp']; $t['ret'] += $r['total_retenues_lbp']; $t['net'] += $r['net_salary_lbp']; $t['due'] += $r['total_due_lbp']; $t['c8'] += $r['school_cnss_8_lbp']; $t['e6'] += $r['school_eoc_6_lbp']; $t['f6'] += $r['school_family_comp_6_lbp']; $t['eos'] += $r['school_end_of_service_8_5_lbp']; $t['paid'] += (int)$r['is_paid']; }
            $grand['net'] += $t['net']; $grand['due'] += $t['due']; $grand['caisse'] += $t['caisse'] + $t['eocg']; $grand['cnss'] += $t['cnss']; $grand['tax'] += $t['tax']; $grand['fam'] += $t['fam']; $grand['n'] += count($rows); $grand['paid'] += $t['paid'];
        ?>
        <div class="eh-ytitle"><span>📅 <?= e($sy) ?> — <?= count($rows) ?> شهر (مدفوع <?= $t['paid'] ?>)</span><span>الصافي <?= $fmt($t['net']) ?> · المستحق <?= $fmt($t['due']) ?> ل.ل</span></div>
        <div class="eh-wrap"><table class="eh-year">
            <thead><tr>
                <th>الشهر</th><th>الدرجة</th><th>الأساس + الدرجة</th><th>الأجر الإضافي</th><th>مكافأة</th><th>النقل</th><th>تعويض عائلي</th>
                <th>الصندوق 6%</th><th>حسم درجة/½</th><th>الضمان 3%</th><th>الضريبة</th><th>مجموع الحسومات</th><th>الصافي</th><th>المستحق</th><th>سعر $</th>
                <th class="sch">المدرسة: ضمان 8%</th><th class="sch">صندوق 6%</th><th class="sch">عائلي 6%</th><th class="sch">نهاية خدمة 8.5%</th><th>الدفع</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= monthName((int)$r['month'], 'ar') ?> <?= (int)$r['year'] ?></td>
                    <td><?= $emp['employee_type'] === 'employe' ? '—' : rtrim(rtrim(number_format((float)$r['grade_at_month'], 1), '0'), '.') ?></td>
                    <td class="num"><?= $fmt($r['base_plus_echelon_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['prime_fixe_lbp'] + $r['extra_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['aide_complementaire_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['transport_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['family_allowance_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['caisse_amount_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['eoc_grade_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['cnss_amount_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['income_tax_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['total_retenues_lbp']) ?></td>
                    <td class="num" style="font-weight:700"><?= $fmt($r['net_salary_lbp']) ?></td>
                    <td class="num" style="font-weight:700"><?= $fmt($r['total_due_lbp']) ?></td>
                    <td class="num"><?= (float)$r['exchange_rate'] > 0 ? number_format((float)$r['exchange_rate']) : '—' ?></td>
                    <td class="num"><?= $fmt($r['school_cnss_8_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['school_eoc_6_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['school_family_comp_6_lbp']) ?></td>
                    <td class="num"><?= $fmt($r['school_end_of_service_8_5_lbp']) ?></td>
                    <td class="<?= (int)$r['is_paid'] ? 'paid' : 'unpaid' ?>"><?= (int)$r['is_paid'] ? 'مدفوع' . ($r['paid_date'] ? ' ' . $d($r['paid_date']) : '') : 'غير مدفوع' ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="tot">
                    <td colspan="2">المجموع</td>
                    <td class="num"><?= $fmt($t['bpe']) ?></td><td class="num"><?= $fmt($t['pf']) ?></td><td class="num"><?= $fmt($t['aide']) ?></td><td class="num"><?= $fmt($t['tr']) ?></td><td class="num"><?= $fmt($t['fam']) ?></td>
                    <td class="num"><?= $fmt($t['caisse']) ?></td><td class="num"><?= $fmt($t['eocg']) ?></td><td class="num"><?= $fmt($t['cnss']) ?></td><td class="num"><?= $fmt($t['tax']) ?></td><td class="num"><?= $fmt($t['ret']) ?></td>
                    <td class="num"><?= $fmt($t['net']) ?></td><td class="num"><?= $fmt($t['due']) ?></td><td></td>
                    <td class="num"><?= $fmt($t['c8']) ?></td><td class="num"><?= $fmt($t['e6']) ?></td><td class="num"><?= $fmt($t['f6']) ?></td><td class="num"><?= $fmt($t['eos']) ?></td><td></td>
                </tr>
            </tbody>
        </table></div>
        <?php endforeach; ?>

        <?php if ($years): ?>
        <div class="eh-ytitle" style="background:#1F4E5F;color:#fff;margin-top:16px"><span>🧮 كل السنين: <?= $grand['n'] ?> شهراً (مدفوع <?= $grand['paid'] ?>)</span>
            <span>الصافي <?= $fmt($grand['net']) ?> · المستحق <?= $fmt($grand['due']) ?> · الصندوق <?= $fmt($grand['caisse']) ?> · الضمان <?= $fmt($grand['cnss']) ?> · الضريبة <?= $fmt($grand['tax']) ?> · العائلي <?= $fmt($grand['fam']) ?> ل.ل</span></div>
        <?php endif; ?>
<?php elseif ($q === ''): ?>
        <div class="alert alert-info" style="margin-top:12px">اكتب اسم الأستاذ أو الموظف (أو رقمه أو هاتفه) — البحث يشمل التاركين وكل السنين. / Tapez un nom : la recherche inclut les anciens employés.</div>
<?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
