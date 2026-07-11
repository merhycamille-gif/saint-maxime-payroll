<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payroll_calculator.php'; // recalcEmployeeYear لإجراءات الـ64
require_once __DIR__ . '/includes/age64.php';               // أدوات تنبيه بلوغ الـ64 (مشتركة)
requireLogin();

$currentPage = 'dashboard';
$pageTitle = 'Tableau de bord / لوحة القيادة';

$db = getDB();

// إجراءات تنبيه بلوغ الـ64 (يبقى/إلغاء/ترك) — تُعالَج وتعيد التوجيه للرئيسية
handleAge64Post($db, BASE_URL . 'index.php');

// Stats (مقيّدة بالمدرسة الحالية — أو كل المدارس للمدير العام)
// «الموظفون الحاليون» = غير محذوفين، فاعلون، وغير تاركين (أي تاريخ ترك يُخرجهم) — حتى لا
// تُحتسب صفوف موظفين سابقين تركها الاستيراد (نفس مبدأ yearEmploymentFilter بكل البرنامج).
$sc = schoolScopeSql();
$notLeft = " AND left_date_cnss IS NULL AND left_date_finance IS NULL AND left_date_eoc IS NULL";
$stats = [
    'total_employees' => $db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND status = 'actif'" . $notLeft . $sc)->fetchColumn(),
    'titulaires' => $db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND status = 'actif' AND employee_type = 'enseignant_titulaire'" . $notLeft . $sc)->fetchColumn(),
    'contractuels' => $db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND status = 'actif' AND employee_type = 'enseignant_contractuel'" . $notLeft . $sc)->fetchColumn(),
    'employes' => $db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND status = 'actif' AND employee_type = 'employe'" . $notLeft . $sc)->fetchColumn(),
];

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');

// مجموع المدفوع للشهر الحالي — يُستثنى المحذوفون والتاركون (صفوف الأشباح) عبر ربط الموظف.
$scE = schoolScopeSql('e.school_id');
$stmtPaid = $db->prepare("SELECT COALESCE(SUM(ms.total_due_lbp), 0)
    FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
    WHERE ms.year = ? AND ms.month = ? AND e.is_deleted = 0"
    . " AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL" . $scE);
$stmtPaid->execute([$currentYear, $currentMonth]);
$totalPaid = (float)$stmtPaid->fetchColumn();

$exchangeRate = getExchangeRate();

// تنبيه بلوغ سنّ الـ64 — الرئيسية تعرض **فقط** من بلغوا 64 ضمن السنة الدراسية المختارة؛
// أما القائمة الكاملة (كل من بلغوا 64) ففي صفحتها الخاصة pages/retirement_64.php.
$home64 = age64List($db, true);

// أساتذة لبطاقة «ملف الأستاذ الكامل» على الداشبورد — تشمل كل المدارس المسموحة (حتى في وضع «كل المدارس»
// يقدر يفتّش عن الأستاذ؛ عند اختياره تُبدَّل المدرسة تلقائياً لمدرسته). schoolScopeSql تقيّد تلقائياً حسب المسموح.
$homeEmps = [];
if (viewerCanSeePage('attestations.php')) {
    [$ayf, $ayp] = yearEmploymentFilter(activeSchoolYear());
    $stHE = $db->prepare("SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, school_id
        FROM employees WHERE is_deleted = 0" . schoolScopeSql() . $ayf . "
        ORDER BY school_id, FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $stHE->execute($ayp);
    $homeEmps = $stHE->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($home64): ?>
<div class="d-flex justify-between align-center no-print" style="margin-bottom:8px;flex-wrap:wrap;gap:8px">
    <div style="color:var(--gray-600);font-size:14px"><i class="fas fa-hourglass-half" style="color:#b45309"></i> بلغوا 64 في السنة الدراسية المختارة (<strong><?= e(activeSchoolYear()) ?></strong>).</div>
    <?php if (canEdit()): ?>
    <a href="<?= BASE_URL ?>pages/retirement_64.php" class="btn btn-sm btn-light"><i class="fas fa-list"></i> عرض كل من بلغوا 64</a>
    <?php endif; ?>
</div>
<?php renderAge64Cards($home64); endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat-label">Total Personnel / إجمالي الموظفين</div>
            <div class="stat-value"><?= $stats['total_employees'] ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-chalkboard-teacher"></i></div>
        <div>
            <div class="stat-label">Enseignants Titulaires / أساتذة الملاك</div>
            <div class="stat-value"><?= $stats['titulaires'] ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-user-clock"></i></div>
        <div>
            <div class="stat-label">Contractuels / المتعاقدون</div>
            <div class="stat-value"><?= $stats['contractuels'] ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-user-tie"></i></div>
        <div>
            <div class="stat-label">Employés / الموظفون الإداريون</div>
            <div class="stat-value"><?= $stats['employes'] ?></div>
        </div>
    </div>
</div>

<?php if (viewerCanSeePage('attestations.php')): ?>
<div class="card no-print" style="border:2px solid var(--primary);background:#f0f7ff;margin-bottom:16px">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-folder-open"></i> Dossier complet de l'enseignant</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">ملف الأستاذ الكامل — كل شي عن الأستاذ بكبسة</div>
    </h3></div>
    <div class="card-body">
        <?php if (!$homeEmps): ?>
            <div class="alert alert-info" style="margin:0">لا يوجد أساتذة.</div>
        <?php else: ?>
            <p class="text-muted" style="margin-bottom:10px"><i class="fas fa-info-circle"></i> اختر الأستاذ (بتقدر تفتّش عنه حتى لو «كل المدارس» مختارة)، وبتفتح صفحة فيها كل شي عنه: معلوماته كاملة، صور مستنداته (الشهادة/التذكرة/العائلي)، وأي إفادة أو تقرير إلو (ر6، بطاقة الراتب السنوية، سيرته...) جاهز للطباعة.</p>
            <form method="GET" action="<?= BASE_URL ?>pages/attestations.php" class="form-row cols-4" style="align-items:end">
                <input type="hidden" name="dossier" value="1">
                <div class="form-group" style="grid-column:1/3">
                    <label class="form-label">الأستاذ / Employé</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— اختر / Choisir —</option>
                        <?php $showSch = isAllSchools(); foreach ($homeEmps as $em): $pfx = $showSch ? (schoolNameById($em['school_id']).' — ') : ''; ?>
                        <option value="<?= $em['id'] ?>"><?= e($pfx.$em['employee_code'].' — '.$em['first_name_fr'].' '.$em['last_name_fr']) ?> / <?= e($em['first_name_ar'].' '.$em['last_name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button class="btn btn-primary w-100"><i class="fas fa-folder-open"></i> افتح الملف الكامل</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="form-row cols-2">
    <div class="card">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-info-circle"></i> Informations système</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">معلومات النظام</div>
            </h3>
        </div>
        <div class="card-body">
            <table class="table">
                <tr>
                    <td><strong>Année scolaire / السنة الدراسية</strong></td>
                    <td><?= e(getSetting('current_school_year', currentSchoolYear())) ?></td>
                </tr>
                <tr>
                    <td><strong>Taux de change actuel / سعر الصرف الحالي</strong></td>
                    <td><?= formatLBP($exchangeRate) ?> / $1</td>
                </tr>
                <tr>
                    <td><strong>Salaire minimum (Loi) / الحد الأدنى للأجور (القانون)</strong></td>
                    <td><?= formatLBP(getSetting('minimum_wage_lbp', 28000000)) ?></td>
                </tr>
                <tr>
                    <td><strong>Total payé ce mois / إجمالي المدفوع هذا الشهر</strong></td>
                    <td><strong class="text-success"><?= formatLBP($totalPaid) ?></strong></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>
                <span dir="ltr"><i class="fas fa-bolt"></i> Actions rapides</span>
                <div style="font-size:0.85em;font-weight:600;opacity:0.9">إجراءات سريعة</div>
            </h3>
        </div>
        <div class="card-body">
            <div class="d-flex gap-3" style="flex-direction:column">
                <?php if (canEdit()): ?>
                <a href="<?= BASE_URL ?>pages/employees.php?action=new" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Ajouter un employé / إضافة موظف
                </a>
                <?php endif; ?>
                <?php if (viewerCanSeePage('monthly_payroll.php')): ?>
                <a href="<?= BASE_URL ?>pages/monthly_payroll.php" class="btn btn-gold">
                    <i class="fas fa-calculator"></i> Calculer la paie mensuelle / حساب الرواتب الشهرية
                </a>
                <?php endif; ?>
                <?php if (viewerCanSeePage('annual_slip.php')): ?>
                <a href="<?= BASE_URL ?>pages/annual_slip.php" class="btn btn-light">
                    <i class="fas fa-file-invoice"></i> Voir relevé annuel / كشف سنوي
                </a>
                <?php endif; ?>
                <?php if (canEdit()): ?>
                <a href="<?= BASE_URL ?>pages/exchange_rates.php" class="btn btn-light">
                    <i class="fas fa-coins"></i> Gérer les taux de change / أسعار الصرف
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>
            <span dir="ltr"><i class="fas fa-gavel"></i> Réglementation appliquée</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">القوانين المطبَّقة</div>
        </h3>
    </div>
    <div class="card-body">
        <div class="form-row cols-2">
            <div>
                <h4 style="color:var(--primary);">
                    <span dir="ltr">Enseignants Titulaires</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الأساتذة الملاك</div>
                </h4>
                <ul>
                    <li>Échelle des grades : Loi 2017 (Journal Officiel n°37)</li>
                    <li>CNSS Maladie/Maternité : 3% (employé) + 8% (école)</li>
                    <li>Caisse d'indemnités (EOC) : 6% (employé) + 6% (école)</li>
                    <li>Impôt sur le revenu : Loi 324/2024 — Titre II</li>
                    <li>Grades exceptionnels : Lois 244, 344, 102, 223</li>
                </ul>
            </div>
            <div>
                <h4 style="color:var(--primary);">
                    <span dir="ltr">Employés (Code du travail)</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الموظفون (قانون العمل)</div>
                </h4>
                <ul>
                    <li>Salaire minimum : <?= formatLBP(getSetting('minimum_wage_lbp', 28000000)) ?> (2025)</li>
                    <li>CNSS Maladie/Maternité : 3% (employé) + 8% (école)</li>
                    <li>Allocations familiales : 6% (école)</li>
                    <li>Indemnités de fin de service : 8.5% (école)</li>
                    <li>Impôt sur le revenu : Loi 324/2024 — Titre II</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
