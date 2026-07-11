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

<?php if (viewerCanSeePage('attestations.php')): ?>
<a class="home-tile home-tile-hero" href="<?= BASE_URL ?>pages/attestations.php?dossier=1">
    <span class="ht-ic ht-ic-lg" style="background:#e0e7ff;color:#4f46e5"><i class="fas fa-folder-open"></i></span>
    <span class="ht-fr" style="font-size:15px">Dossier de l'enseignant</span>
    <span class="ht-ar">ملف الأستاذ الكامل — شوف كل شي عن الأستاذ</span>
</a>
<?php endif; ?>

<?php
// 🧩 وصول سريع (Accès rapide): بطاقات لكل أقسام البرنامج — روابط فقط، تحترم صلاحيات المستخدم (نفس شروط القائمة الجانبية).
// إضافة شكليّة بحتة: لا تمسّ أي حساب/قاعدة بيانات/منطق.
// لوحة ألوان متنوّعة (خلفية فاتحة + لون أيقونة) — تُوزَّع على البطاقات لتصير ملوّنة متل النموذج
$palette = [
    ['#e0f2fe','#0284c7'], ['#dcfce7','#16a34a'], ['#fef3c7','#d97706'], ['#fee2e2','#dc2626'],
    ['#ede9fe','#7c3aed'], ['#cffafe','#0891b2'], ['#fce7f3','#db2777'], ['#ffedd5','#ea580c'],
    ['#e0e7ff','#4f46e5'], ['#d1fae5','#059669'],
];
$tile = function ($href, $icon, $fr, $ar, $bg, $fg) {
    echo '<a class="home-tile" href="' . BASE_URL . $href . '">'
       . '<span class="ht-ic" style="background:' . $bg . ';color:' . $fg . '"><i class="' . $icon . '"></i></span>'
       . '<span class="ht-fr">' . e($fr) . '</span>'
       . '<span class="ht-ar">' . e($ar) . '</span></a>';
};
$navGroups = [
    ['Personnel', 'الموظفون', array_filter([
        canEdit() ? ['pages/employees.php','fas fa-users','Employés & Enseignants','الموظفون والأساتذة'] : null,
        canEdit() ? ['pages/grades.php','fas fa-layer-group','Échelons & Promotions','الدرجات والترقيات'] : null,
        canEdit() ? ['pages/classes.php','fas fa-chalkboard','Classes','الصفوف'] : null,
        canEdit() ? ['pages/exceptional_laws.php','fas fa-scroll','Lois exceptionnelles','القوانين الاستثنائية'] : null,
        canEdit() ? ['pages/bulk_allowances.php','fas fa-gift','Primes & transport','المكافآت والنقل'] : null,
        canEdit() ? ['pages/law_check.php','fas fa-balance-scale','Conformité légale','فحص مطابقة القانون'] : null,
    ])],
    ['Paie', 'الرواتب', array_filter([
        viewerCanSeePage('monthly_payroll.php') ? ['pages/monthly_payroll.php','fas fa-money-check-alt','Paie mensuelle','الرواتب الشهرية'] : null,
        viewerCanSeePage('annual_slip.php') ? ['pages/annual_slip.php','fas fa-file-invoice-dollar','Relevé annuel','الكشف السنوي'] : null,
        viewerCanSeePage('attestations.php') ? ['pages/attestations.php','fas fa-file-signature','Attestations','إفادات'] : null,
        viewerCanSeePage('employee_history.php') ? ['pages/employee_history.php','fas fa-user-clock','Dossier enseignant','سيرة الأستاذ'] : null,
        canEdit() ? ['pages/info_collect.php','fab fa-whatsapp','Mise à jour','تحديث معلومات الأساتذة'] : null,
        canEdit() ? ['pages/info_status.php','fas fa-clipboard-check','État MàJ','حالة التحديث'] : null,
        canEdit() ? ['pages/left_teachers.php','fas fa-user-slash','Départs','الأساتذة التاركون'] : null,
        canEdit() ? ['pages/retirement_64.php','fas fa-hourglass-half','Retraite 64','بلوغ سنّ الـ64'] : null,
    ])],
    ['Rapports', 'التقارير', array_filter([
        viewerCanSeePage('reports.php') ? ['pages/reports.php','fas fa-chart-bar','Rapports','التقارير'] : null,
        canEdit() ? ['pages/tax_declarations.php','fas fa-file-contract','Déclarations','التصاريح'] : null,
    ])],
    ['Système', 'النظام', array_filter([
        isSuperAdmin() ? ['pages/schools.php','fas fa-school','Écoles','المدارس'] : null,
        isAdmin() ? ['pages/users.php','fas fa-user-shield','Utilisateurs','حسابات المدارس'] : null,
        canEdit() ? ['pages/open_year.php','fas fa-folder-plus','Ouvrir année','فتح سنة دراسية'] : null,
        canEdit() ? ['pages/exchange_rates.php','fas fa-coins','Taux de change','أسعار الصرف'] : null,
        canEdit() ? ['pages/social_security.php','fas fa-shield-alt','Plafonds CNSS','حدود الضمان'] : null,
        canEdit() ? ['pages/tax_brackets.php','fas fa-percent',"Tranches d'impôt",'الشطور الضريبية'] : null,
        canEdit() ? ['pages/rates_history.php','fas fa-percent','Taux datés','النِّسَب حسب التاريخ'] : null,
        canEdit() ? ['pages/salary_scales.php','fas fa-layer-group','Grille','إصدارات السلسلة'] : null,
        canEdit() ? ['pages/backup.php','fas fa-database','Sauvegarde','نسخة احتياطية'] : null,
        ['pages/settings.php','fas fa-cog','Paramètres','الإعدادات'],
        isSuperAdmin() ? ['pages/email_settings.php','fas fa-envelope','Paramètres Email','إعدادات البريد'] : null,
    ])],
];
?>
<div class="card no-print">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-th-large"></i> Accès rapide</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">وصول سريع لكل الأقسام</div>
    </h3></div>
    <div class="card-body">
        <?php $ci = 0; foreach ($navGroups as [$gFr, $gAr, $items]): if (!$items) continue; ?>
        <div class="ht-section"><span dir="ltr"><?= e($gFr) ?></span> / <?= e($gAr) ?></div>
        <div class="home-tiles">
            <?php foreach ($items as $it): [$bg, $fg] = $palette[$ci % count($palette)]; $ci++;
                $tile($it[0], $it[1], $it[2], $it[3], $bg, $fg); endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

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
