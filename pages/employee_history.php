<?php
/**
 * Dossier / Histoire de l'enseignant — سيرة وتاريخ الأستاذ
 * متى باشر، راتبه، الصفوف، المواد، الترسيم، تطوّر الدرجات والراتب. طباعة وتصدير.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();

$currentPage = 'employee_history';
$pageTitle = 'Dossier enseignant / سيرة الأستاذ';
$db = getDB();

$employeeId = (int)($_GET['employee_id'] ?? 0);
$emp = null;
if ($employeeId > 0) {
    autoSwitchToEmployeeSchool($employeeId);
    requireSchoolSelected();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        $_SESSION['flash_error'] = 'الموظف غير موجود في هذه المدرسة';
        header('Location: ' . BASE_URL . 'pages/employee_history.php');
        exit;
    }
    $exportTitle = 'Dossier_' . $emp['first_name_fr'] . '_' . $emp['last_name_fr'];
    $exportOpts = ['phone' => $emp['phone1'] ?: ''];
}

// 🔴 لا doc-view هنا: سيرة الأستاذ صفحة عمل لها شكلها المعهود (شكوى المستخدم p1 بتاريخ 2026-08-01).
include __DIR__ . '/../includes/header.php';

if (!$emp):
    [$hyf, $hyp] = yearEmploymentFilter(activeSchoolYear()); // فلترة حسب السنة الدراسية المختارة
    $hStmt = $db->prepare("SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, phone1, phone2
                             FROM employees WHERE is_deleted = 0" . schoolScopeSql() . $hyf . " ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $hStmt->execute($hyp);
    $employees = $hStmt->fetchAll();
?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-user-clock"></i> <span dir="ltr">Dossier enseignant</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">سيرة الأستاذ</div></h3></div>
        <div class="card-body">
            <?php if (isAllSchools()): ?>
                <div class="alert alert-warning">اختر مدرسة محددة من الأعلى أولاً / Sélectionnez une école.</div>
            <?php elseif (!$employees): ?>
                <div class="alert alert-info">Aucun employé dans cette école / لا يوجد موظفون في هذه المدرسة.</div>
            <?php else: ?>
            <form method="GET" class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Employé / الأستاذ</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Choisir / اختر —</option>
                        <?php foreach ($employees as $em): ?>
                        <option value="<?= $em['id'] ?>" data-phone="<?= e(trim(($em['phone1'] ?? '').' '.($em['phone2'] ?? ''))) ?>"><?= e($em['employee_code'].' — '.$em['first_name_fr'].' '.$em['last_name_fr']) ?> / <?= e($em['first_name_ar'].' '.$em['last_name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">&nbsp;</label><button class="btn btn-primary w-100"><i class="fas fa-eye"></i> Afficher / عرض</button></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php else:
    $lang = $_SESSION['lang'] ?? 'fr';
    $nomFr = trim($emp['first_name_fr'].' '.($emp['father_name_fr'] ? $emp['father_name_fr'].' ' : '').$emp['last_name_fr']);
    $nomAr = trim($emp['first_name_ar'].' '.($emp['father_name_ar'] ? $emp['father_name_ar'].' ' : '').$emp['last_name_ar']);

    // الراتب الحالي — 🔴 مصدر واحد (2026-08-06): من السنة الدراسية المعروضة نفسها التي تعرضها
    // كل التقارير (كان يأخذ آخر صفّ مخزّن ولو من سنة مفتوحة لاحقة فيخالف الكشوف)؛
    // وإن لم يكن فيها صفوف (أو الوضع «كل السنين») → آخر صفّ إجمالاً كما قبل.
    $sal = null;
    $ehSy = activeSchoolYear();
    if ($ehSy !== 'all') {
        $q = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND school_year = ? ORDER BY year DESC, month DESC LIMIT 1");
        $q->execute([$employeeId, $ehSy]);
        $sal = $q->fetch();
    }
    if (!$sal) {
        $q = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? ORDER BY year DESC, month DESC LIMIT 1");
        $q->execute([$employeeId]);
        $sal = $q->fetch();
    }
    $curBase = $sal ? (float)$sal['base_plus_echelon_lbp'] : scaleSalaryLBP($emp['current_grade']);
    $curNet  = $sal ? (float)$sal['net_salary_lbp'] : $curBase;

    // أقدمية الخدمة
    $anc = '';
    if ($emp['hire_date']) {
        $d = (new DateTime($emp['hire_date']))->diff(new DateTime('now'));
        $anc = $d->y . ' سنة' . ($d->m ? " و{$d->m} شهر" : '');
    }
    $isAdminEmp = ($emp['employee_type'] === 'employe'); // موظف إداري: لا درجة/صفوف/مواد/تطوّر سلسلة
    // تطوّر الدرجة والراتب (للأستاذ فقط — الموظف الإداري بلا سلسلة رتب)
    $evolution = $isAdminEmp ? [] : buildSalaryEvolution($employeeId, $emp['current_grade']);
    $evLabels = ['entry'=>'Entrée au cadre / دخول الملاك','ordinary'=>'Échelon ordinaire / درجة عادية (تدرّج)','exceptional'=>'Échelon exceptionnel / درجة استثنائية','manual'=>'Modification manuelle / تعديل يدوي','stable'=>'— (1/10)'];
?>
    <div class="d-flex justify-between align-center mb-3 no-print">
        <a href="<?= BASE_URL ?>pages/employee_history.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Retour / رجوع</a>
        <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $employeeId ?>" class="btn btn-light"><i class="fas fa-pen"></i> Modifier / تعديل</a>
    </div>

    <div id="ppExportArea">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-id-card"></i> <?= e($nomFr) ?> <?= $nomAr ? ' / '.e($nomAr) : '' ?> — <?= e(currentSchoolName()) ?></h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <table class="table">
                        <tr><th colspan="2" style="background:var(--primary);color:#fff">Identité / الهوية</th></tr>
                        <tr><td>Code / الرمز</td><td><strong><?= e($emp['employee_code']) ?></strong></td></tr>
                        <tr><td>Fonction / الوظيفة</td><td><?= employeeTypeLabel($emp['employee_type'],$lang) ?></td></tr>
                        <tr><td>Diplôme / الشهادة</td><td><?= e(diplomaLabel($emp['diploma'],$lang)) ?><?= $emp['specialization'] ? ' — '.e($emp['specialization']) : '' ?></td></tr>
                        <tr><td>État civil / الوضع العائلي</td><td><?= socialStatusLabel($emp['social_status'],$lang) ?><?= (int)$emp['number_of_children'] ? ' ('.$emp['number_of_children'].' أولاد)' : '' ?></td></tr>
                        <tr><td>Téléphone / الهاتف</td><td><?= e($emp['phone1']) ?></td></tr>
                    </table>
                    <table class="table">
                        <tr><th colspan="2" style="background:var(--primary);color:#fff">Carrière / المسار الوظيفي</th></tr>
                        <tr><td>Date d'embauche / المباشرة</td><td><strong><?= formatDate($emp['hire_date']) ?></strong></td></tr>
                        <tr><td>Titularisation / الترسيم</td><td><?= $isAdminEmp ? '—' : formatDate($emp['titularization_date']) ?></td></tr>
                        <tr><td>Ancienneté / الأقدمية</td><td><?= e($anc ?: '—') ?></td></tr>
                        <?php if (!$isAdminEmp): ?><tr><td>Échelon départ → actuel / الدرجة</td><td><strong><?= gradeDisplay($emp['employee_type'], $emp['starting_grade']) ?> → <?= gradeDisplay($emp) ?></strong></td></tr><?php endif; ?>
                        <tr><td>Statut / الحالة</td><td><?php $s=employeeStatusLabel($emp['status'],$lang); ?><span class="badge badge-<?= $s['badge'] ?>"><?= e($s['label']) ?></span></td></tr>
                    </table>
                </div>

                <?php
                  // أسماء الصفوف (فرنسي/عربي) عبر الدالة المركزية + ترجمة المرحلة
                  $clsDisplay = classLevelNames($emp['classes_taught'] ?? '');
                  $niveauMap = ['maternelle'=>'حضانة','primaire'=>'ابتدائي','intermediaire'=>'متوسط','secondaire'=>'ثانوي'];
                  $nivNames = [];
                  foreach (array_filter(explode(',', (string)($emp['niveau_scolaire'] ?? ''))) as $nv) { $nivNames[] = $niveauMap[$nv] ?? $nv; }
                  $nivDisplay = $nivNames ? implode('، ', $nivNames) : '—';
                ?>
                <table class="table" style="margin-top:6px">
                    <tr><th colspan="2" style="background:var(--gold);color:var(--primary-dark)"><?= $isAdminEmp ? 'Fonction & Salaire / الوظيفة والراتب' : 'Enseignement & Salaire / التعليم والراتب' ?></th></tr>
                    <?php if ($isAdminEmp): ?>
                    <tr><td style="width:38%">Fonction / الوظيفة</td><td><strong><?= e(trim((string)($emp['job_title'] ?? '')) !== '' ? jobTitleLabel($emp['job_title'], 'ar') : '—') ?></strong></td></tr>
                    <?php else: ?>
                    <tr><td style="width:38%">Classes enseignées / الصفوف</td><td><strong><?= e($clsDisplay) ?></strong></td></tr>
                    <tr><td>Niveau / المرحلة</td><td><strong><?= e($nivDisplay) ?></strong></td></tr>
                    <tr><td>Matières / المواد</td><td><strong><?= e($emp['subjects_taught'] ?: '—') ?></strong></td></tr>
                    <?php endif; ?>
                    <?php $slRate = $sal ? rowRate($sal) : null; ?>
                    <tr><td>Salaire actuel (base+échelon) / الراتب الحالي</td><td><strong><?= money($curBase, $slRate) ?></strong></td></tr>
                    <?php if ($sal): // سطور «+» تتبع زرّ «الراتب يشمل» — فيبقى الراتب المركّب = مجموع السطور الظاهرة ?>
                    <?php if (salaryCompHas('extra')): ?><tr><td>+ Supplément / الأجر الإضافي</td><td><?= money((int)$sal['extra_lbp'] + (int)$sal['prime_fixe_lbp'], $slRate) ?></td></tr><?php endif; ?>
                    <?php if (salaryCompHas('aide')): ?><tr><td>+ Prime &amp; aide / المكافأة والمساعدة</td><td><?= money((int)$sal['aide_complementaire_lbp'], $slRate) ?></td></tr><?php endif; ?>
                    <?php if (salaryCompHas('transport')): ?><tr><td>+ Transport / تعويض النقل</td><td><?= money((int)$sal['transport_lbp'], $slRate) ?></td></tr><?php endif; ?>
                    <tr style="background:#eef2ff"><td><strong>Salaire composé / الراتب المركّب</strong><br><small style="color:#64748b"><?= e(salaryCompLabel()) ?></small></td><td><strong><?= money(composedSalaryLbp($sal), $slRate) ?></strong></td></tr>
                    <?php endif; ?>
                    <tr><td>Salaire net / الصافي</td><td><strong><?= money($curNet, $slRate) ?></strong><?= $sal ? ' — '.monthName((int)$sal['month'],$lang).' '.$sal['year'] : '' ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-chart-line"></i> <span dir="ltr">Évolution des échelons et du salaire</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">تطوّر الدرجات والراتب</div></h3></div>
            <div class="card-body">
                <?php if (!$evolution): ?>
                    <p class="text-muted">لا يوجد سجلّ درجات كافٍ. أدخل تواريخ الترسيم والدرجات من صفحة «Échelons / الدرجات».</p>
                <?php else: ?>
                <table class="table">
                    <thead><tr>
                        <th>Date / التاريخ</th><th>Événement / الحدث</th><th>Échelon / الدرجة</th>
                        <th class="text-end">Base / الأساس</th><th class="text-end">Ordinaire / عادية</th>
                        <th class="text-end">Except. / استثنائية</th><th class="text-end">Salaire / الراتب</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($evolution as $r): ?>
                        <tr>
                            <td><?= formatDate($r['date']) ?></td>
                            <td><?= e($evLabels[$r['type']] ?? $r['type']) ?><?= $r['law'] ? ' <small class="text-muted">('.e($r['law']).')</small>' : '' ?></td>
                            <td><?= rtrim(rtrim(number_format($r['grade_after'],1),'0'),'.') ?></td>
                            <td class="text-end"><?= formatLBP($r['base'],false) ?></td>
                            <td class="text-end"><?= $r['ordinary'] ? formatLBP($r['ordinary'],false) : '—' ?></td>
                            <td class="text-end"><?= $r['exceptional'] ? formatLBP($r['exceptional'],false) : '—' ?></td>
                            <td class="text-end"><strong><?= formatLBP($r['after'],false) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
