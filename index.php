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
// 🔢 الأعداد حسب **السنة الدراسية المختارة** + المدرسة/المدارس المختارة (نفس فلتر السنة المستعمَل بكل البرنامج).
// «كل السنين» → بلا فلتر سنة (تُعيد yearEmploymentFilter فراغاً) فيُحتسب كل الفاعلين الحاليين.
[$yfStat, $ypStat] = yearEmploymentFilter(activeSchoolYear());
$dashCount = function ($typeSql) use ($db, $notLeft, $sc, $yfStat, $ypStat) {
    $st = $db->prepare("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND status = 'actif'" . $typeSql . $notLeft . $sc . $yfStat);
    $st->execute($ypStat);
    return (int)$st->fetchColumn();
};
$stats = [
    'total_employees' => $dashCount(''),
    'titulaires'      => $dashCount(" AND employee_type = 'enseignant_titulaire'"),
    'contractuels'    => $dashCount(" AND employee_type = 'enseignant_contractuel'"),
    'employes'        => $dashCount(" AND employee_type = 'employe'"),
];

// 💰 مجموع المدفوع في **السنة الدراسية المختارة** + المدرسة/المدارس المختارة (بدل الشهر — ليكون حسب السنة).
// «كل السنين» → مجموع كل السنوات ضمن النطاق.
$scE = schoolScopeSql('e.school_id');
$ayPaid = activeSchoolYear();
if ($ayPaid === 'all') {
    $stmtPaid = $db->prepare("SELECT COALESCE(SUM(ms.total_due_lbp), 0)
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0" . $scE);
    $stmtPaid->execute();
} else {
    $stmtPaid = $db->prepare("SELECT COALESCE(SUM(ms.total_due_lbp), 0)
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE ms.school_year = ? AND e.is_deleted = 0" . $scE);
    $stmtPaid->execute([$ayPaid]);
}
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

// لوحة القيادة لا يُطبَع منها شي → أخفِ شريط الطباعة/التصدير (يبقى ظاهراً في صفحات التقارير)
$hideExportToolbar = true;

include __DIR__ . '/includes/header.php';
?>

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
<div class="home-tiles" style="margin-bottom:16px">
    <?php $ci = 0; foreach ($navGroups as [$gFr, $gAr, $items]): foreach ($items as $it): [$bg, $fg] = $palette[$ci % count($palette)]; $ci++;
        $tile($it[0], $it[1], $it[2], $it[3], $bg, $fg); endforeach; endforeach; ?>
</div>

<!-- 📊 نظرة عامة: أرقام + معلومات النظام كبطاقات أيقونات ملوّنة -->
<div class="ht-section"><span dir="ltr">Aperçu</span> / نظرة عامة</div>
<?php
$kpi = function ($icon, $bg, $fg, $val, $fr, $ar) {
    echo '<div class="home-tile kpi-tile">'
       . '<span class="ht-ic" style="background:' . $bg . ';color:' . $fg . '"><i class="' . $icon . '"></i></span>'
       . '<span class="kpi-val">' . $val . '</span>'
       . '<span class="kpi-lbl">' . $fr . ' / ' . $ar . '</span></div>';
};
?>
<div class="home-tiles" style="margin-bottom:18px">
    <?php
    $kpi('fas fa-users', '#e0f2fe', '#0284c7', (int)$stats['total_employees'], 'Total Personnel', 'إجمالي الموظفين');
    $kpi('fas fa-chalkboard-teacher', '#dcfce7', '#16a34a', (int)$stats['titulaires'], 'Titulaires', 'أساتذة الملاك');
    $kpi('fas fa-user-clock', '#fef3c7', '#d97706', (int)$stats['contractuels'], 'Contractuels', 'المتعاقدون');
    $kpi('fas fa-user-tie', '#ede9fe', '#7c3aed', (int)$stats['employes'], 'Employés', 'الموظفون الإداريون');
    $kpi('fas fa-calendar-alt', '#cffafe', '#0891b2', (activeSchoolYear() === 'all' ? 'كل السنين / Toutes' : e(activeSchoolYear())), 'Année scolaire', 'السنة الدراسية');
    $kpi('fas fa-coins', '#fef9c3', '#ca8a04', formatLBP($exchangeRate) . ' / $1', 'Taux de change', 'سعر الصرف');
    $kpi('fas fa-money-bill-wave', '#fce7f3', '#db2777', formatLBP(getSetting('minimum_wage_lbp', 28000000)), 'Salaire min. (Loi)', 'الحد الأدنى للأجور');
    $kpi('fas fa-wallet', '#d1fae5', '#059669', formatLBP($totalPaid), 'Payé (année)', 'المدفوع بالسنة');
    ?>
</div>

<?php /* «إجراءات سريعة» أُزيلت من لوحة القيادة — أقسامها موجودة أصلاً ضمن «وصول سريع» (لتوفير المساحة). */ ?>

<?php if ($home64): ?>
<details class="reg-details no-print">
    <summary>
        <span class="rd-ic" style="background:rgba(217,119,6,.16);color:#d97706"><i class="fas fa-hourglass-half"></i></span>
        <span dir="ltr">Retraite 64</span> <span style="opacity:.85">/ بلغوا سنّ الـ64 (<?= count($home64) ?>)</span>
        <i class="fas fa-chevron-down rd-chev"></i>
    </summary>
    <div class="rd-body">
        <?php renderAge64Cards($home64); ?>
    </div>
</details>
<?php endif; ?>

<details class="reg-details">
    <summary>
        <span class="rd-ic" style="background:var(--accent-bg);color:var(--accent)"><i class="fas fa-gavel"></i></span>
        <span dir="ltr">Réglementation appliquée</span> <span style="opacity:.85">/ القوانين المطبَّقة</span>
        <i class="fas fa-chevron-down rd-chev"></i>
    </summary>
    <div class="rd-body">
        <div class="form-row cols-2">
            <div>
                <h4 style="color:var(--primary);">
                    <span dir="ltr">Enseignants Titulaires</span>
                    <div style="font-size:0.85em;font-weight:600;opacity:0.9">الأساتذة الملاك</div>
                </h4>
                <ul class="reg-list">
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
                <ul class="reg-list">
                    <li>Salaire minimum : <?= formatLBP(getSetting('minimum_wage_lbp', 28000000)) ?> (2025)</li>
                    <li>CNSS Maladie/Maternité : 3% (employé) + 8% (école)</li>
                    <li>Allocations familiales : 6% (école)</li>
                    <li>Indemnités de fin de service : 8.5% (école)</li>
                    <li>Impôt sur le revenu : Loi 324/2024 — Titre II</li>
                </ul>
            </div>
        </div>
    </div>
</details>

<?php include __DIR__ . '/includes/footer.php'; ?>
