<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
requireLogin();
healYearAdditions2627(); // شفاء ذاتي مرّة واحدة: علاوات 2026-2027 (لا يفعل شيئاً بعد تمامه)
healCaisseNumbers();     // شفاء ذاتي مرّة واحدة: إفراغ رقم الصندوق الذي كُتب آلياً على مؤسسات غير المدرسة

$currentPage = $currentPage ?? '';
$pageTitle = $pageTitle ?? 'MSA Payroll';
$lang = $_SESSION['lang'] ?? 'fr';

// 🎨 لون + أيقونة مميّزة لكل قسم من البرنامج (تُطبَّق على العنوان وبادجات رؤوس البطاقات)
$sectionColors = [
    'dashboard' => ['#0891b2', 'rgba(8,145,178,.16)'],   // لوحة القيادة — تركوازي
    'personnel' => ['#0284c7', 'rgba(2,132,199,.16)'],   // الموظفون — أزرق
    'paie'      => ['#16a34a', 'rgba(22,163,74,.16)'],   // الرواتب — أخضر
    'rapports'  => ['#7c3aed', 'rgba(124,58,237,.16)'],  // التقارير — بنفسجي
    'systeme'   => ['#d97706', 'rgba(217,119,6,.16)'],   // النظام — برتقالي
];
$sectionIcons = ['dashboard'=>'fa-gauge-high','personnel'=>'fa-users','paie'=>'fa-money-check-dollar','rapports'=>'fa-chart-column','systeme'=>'fa-gear'];
$pageSection = [
    'dashboard'=>'dashboard',
    'employees'=>'personnel','grades'=>'personnel','classes'=>'personnel','exceptional_laws'=>'personnel','bulk_allowances'=>'personnel','law_check'=>'personnel',
    'monthly'=>'paie','annual'=>'paie','attestations'=>'paie','employee_history'=>'paie','info_collect'=>'paie','info_status'=>'paie','left_teachers'=>'paie','retirement_64'=>'paie',
    'reports'=>'rapports','tax'=>'rapports',
    'schools'=>'systeme','users'=>'systeme','open_year'=>'systeme','rates'=>'systeme','social_security'=>'systeme','tax_brackets'=>'systeme','rates_history'=>'systeme','salary_scales'=>'systeme','backup'=>'systeme','settings'=>'systeme','email_settings'=>'systeme',
];
$sec = $pageSection[$currentPage] ?? 'dashboard';
[$accentColor, $accentBg] = $sectionColors[$sec];
$secIcon = $sectionIcons[$sec] ?? 'fa-gauge-high';
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'ar' ? 'ar' : 'fr' ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(currentSchoolName()) ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: '1' ?>">
</head>
<body class="<?= $lang === 'ar' ? 'rtl' : '' ?>">

<!-- حماية CSRF: حقن توكن تلقائياً بكل نماذج POST -->
<script>
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.method && f.method.toLowerCase() === 'post' && !f.querySelector('input[name="csrf"]')) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'csrf'; i.value = '<?= csrfToken() ?>';
        f.appendChild(i);
    }
}, true);
</script>

<div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="brand-logo"><i class="fas fa-graduation-cap"></i></span>
            <div>
                <h2>MSA Payroll</h2>
                <p><?= e(currentSchoolName()) ?></p>
            </div>
        </div>

        <!-- بحث سريع بالقائمة (تصفية فورية) -->
        <div class="sidebar-search no-print">
            <div class="ss-wrap">
                <i class="fas fa-magnifying-glass ss-ic"></i>
                <input type="text" id="navFilter" placeholder="Rechercher / بحث..." autocomplete="off"
                       oninput="var q=this.value.trim().toLowerCase();document.querySelectorAll('.sidebar-nav a').forEach(function(a){a.style.display=(!q||a.textContent.toLowerCase().indexOf(q)>-1)?'':'none'});document.querySelectorAll('.sidebar-nav .nav-section').forEach(function(s){s.style.display=q?'none':''});">
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="<?= BASE_URL ?>index.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i>
                <span>Tableau de bord / لوحة القيادة</span>
            </a>
            
            <?php if (canEdit()): ?>
            <div class="nav-section" style="--ns:#38bdf8">Personnel / الموظفون</div>

            <a href="<?= BASE_URL ?>pages/employees.php" class="<?= $currentPage === 'employees' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Employés & Enseignants / الموظفون والأساتذة</span>
            </a>

            <a href="<?= BASE_URL ?>pages/grades.php" class="<?= $currentPage === 'grades' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i>
                <span>Échelons & Promotions / الدرجات والترقيات</span>
            </a>

            <a href="<?= BASE_URL ?>pages/classes.php" class="<?= $currentPage === 'classes' ? 'active' : '' ?>">
                <i class="fas fa-chalkboard"></i>
                <span>Classes / الصفوف</span>
            </a>

            <a href="<?= BASE_URL ?>pages/exceptional_laws.php" class="<?= $currentPage === 'exceptional_laws' ? 'active' : '' ?>">
                <i class="fas fa-scroll"></i>
                <span>Lois exceptionnelles / القوانين الاستثنائية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/bulk_allowances.php" class="<?= $currentPage === 'bulk_allowances' ? 'active' : '' ?>">
                <i class="fas fa-gift"></i>
                <span>Primes & transport / المكافآت والنقل</span>
            </a>

            <a href="<?= BASE_URL ?>pages/law_check.php" class="<?= $currentPage === 'law_check' ? 'active' : '' ?>">
                <i class="fas fa-balance-scale"></i>
                <span>Conformité légale / فحص مطابقة القانون</span>
            </a>
            <?php endif; ?>

            <div class="nav-section" style="--ns:#4ade80">Paie / الرواتب</div>

            <?php if (viewerCanSeePage('monthly_payroll.php')): ?>
            <a href="<?= BASE_URL ?>pages/monthly_payroll.php" class="<?= $currentPage === 'monthly' ? 'active' : '' ?>">
                <i class="fas fa-money-check-alt"></i>
                <span>Paie mensuelle / الرواتب الشهرية</span>
            </a>
            <?php endif; ?>

            <?php if (viewerCanSeePage('annual_slip.php')): ?>
            <a href="<?= BASE_URL ?>pages/annual_slip.php" class="<?= $currentPage === 'annual' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Relevé annuel / الكشف السنوي</span>
            </a>
            <?php endif; ?>

            <?php if (viewerCanSeePage('attestations.php')): ?>
            <a href="<?= BASE_URL ?>pages/attestations.php" class="<?= $currentPage === 'attestations' ? 'active' : '' ?>">
                <i class="fas fa-file-signature"></i>
                <span>Attestations / إفادات</span>
            </a>
            <?php endif; ?>

            <?php if (viewerCanSeePage('employee_history.php')): ?>
            <a href="<?= BASE_URL ?>pages/employee_history.php" class="<?= $currentPage === 'employee_history' ? 'active' : '' ?>">
                <i class="fas fa-user-clock"></i>
                <span>Dossier enseignant / سيرة الأستاذ</span>
            </a>
            <?php endif; ?>
            <?php if (canEdit()): ?>
            <a href="<?= BASE_URL ?>pages/info_collect.php" class="<?= $currentPage === 'info_collect' ? 'active' : '' ?>">
                <i class="fab fa-whatsapp"></i>
                <span>Mise à jour / تحديث معلومات الأساتذة</span>
            </a>
            <a href="<?= BASE_URL ?>pages/info_status.php" class="<?= $currentPage === 'info_status' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i>
                <span>État MàJ / حالة التحديث (مين بعت)</span>
            </a>
            <a href="<?= BASE_URL ?>pages/left_teachers.php" class="<?= $currentPage === 'left_teachers' ? 'active' : '' ?>">
                <i class="fas fa-user-slash"></i>
                <span>Départs / الأساتذة التاركون</span>
            </a>
            <a href="<?= BASE_URL ?>pages/retirement_64.php" class="<?= $currentPage === 'retirement_64' ? 'active' : '' ?>">
                <i class="fas fa-hourglass-half"></i>
                <span>Retraite 64 / بلوغ سنّ الـ64</span>
            </a>
            <?php endif; ?>

            <div class="nav-section" style="--ns:#c4b5fd">Rapports / التقارير</div>

            <?php if (viewerCanSeePage('reports.php')): ?>
            <a href="<?= BASE_URL ?>pages/reports.php" class="<?= $currentPage === 'reports' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports / التقارير</span>
            </a>
            <?php endif; ?>

            <?php if (canEdit()): ?>
            <a href="<?= BASE_URL ?>pages/tax_declarations.php" class="<?= $currentPage === 'tax' ? 'active' : '' ?>">
                <i class="fas fa-file-contract"></i>
                <span>Déclarations / التصاريح</span>
            </a>
            <?php endif; ?>

            <div class="nav-section" style="--ns:#fbbf24">Système / النظام</div>

            <?php if (isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/schools.php" class="<?= $currentPage === 'schools' ? 'active' : '' ?>">
                <i class="fas fa-school"></i>
                <span>Écoles / المدارس</span>
            </a>
            <?php endif; ?>

            <?php if (isAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i>
                <span>Utilisateurs / حسابات المدارس</span>
            </a>
            <?php endif; ?>

            <?php if (canEdit()): ?>
            <a href="<?= BASE_URL ?>pages/open_year.php" class="<?= $currentPage === 'open_year' ? 'active' : '' ?>">
                <i class="fas fa-folder-plus"></i>
                <span>Ouvrir année / فتح سنة دراسية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/exchange_rates.php" class="<?= $currentPage === 'rates' ? 'active' : '' ?>">
                <i class="fas fa-coins"></i>
                <span>Taux de change / أسعار الصرف</span>
            </a>

            <a href="<?= BASE_URL ?>pages/social_security.php" class="<?= $currentPage === 'social_security' ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i>
                <span>Plafonds CNSS / حدود الضمان</span>
            </a>

            <a href="<?= BASE_URL ?>pages/tax_brackets.php" class="<?= $currentPage === 'tax_brackets' ? 'active' : '' ?>">
                <i class="fas fa-percent"></i>
                <span>Tranches d'impôt / الشطور الضريبية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/rates_history.php" class="<?= $currentPage === 'rates_history' ? 'active' : '' ?>">
                <i class="fas fa-percent"></i>
                <span>Taux datés / النِّسَب حسب التاريخ</span>
            </a>

            <a href="<?= BASE_URL ?>pages/salary_scales.php" class="<?= $currentPage === 'salary_scales' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i>
                <span>Grille / إصدارات السلسلة</span>
            </a>

            <a href="<?= BASE_URL ?>pages/backup.php" class="<?= $currentPage === 'backup' ? 'active' : '' ?>">
                <i class="fas fa-database"></i>
                <span>Sauvegarde / نسخة احتياطية</span>
            </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>pages/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i>
                <span>Paramètres / الإعدادات</span>
            </a>
            <?php if (isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/email_settings.php" class="<?= $currentPage === 'email_settings' ? 'active' : '' ?>">
                <i class="fas fa-envelope"></i>
                <span>Paramètres Email / إعدادات البريد</span>
            </a>
            <?php endif; ?>
        </nav>
        
        <div class="sidebar-footer">
            v<?= APP_VERSION ?> • <?= date('Y') ?>
        </div>
    </aside>

    <!-- غطاء يغلق القائمة على الموبايل + إغلاق القوائم المنسدلة عند الكبس خارجها -->
    <div class="nav-overlay no-print" onclick="document.body.classList.remove('nav-open')"></div>
    <script>
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.school-menu.open').forEach(function (m) {
            if (!m.closest('.school-multi').contains(e.target)) m.classList.remove('open');
        });
    });
    </script>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top bar -->
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
                <button type="button" class="menu-toggle no-print" title="Menu / القائمة"
                        onclick="document.body.classList.toggle('nav-open')">
                    <i class="fas fa-bars"></i>
                </button>
                <?php if ($currentPage !== 'dashboard'): ?>
                <button type="button" class="btn btn-light no-print" title="رجوع / Retour"
                        onclick="if(document.referrer&&history.length>1){history.back()}else{location.href='<?= BASE_URL ?>index.php'}"
                        style="white-space:nowrap">
                    <i class="fas fa-arrow-right" style="color:#16a34a"></i> Retour / رجوع
                </button>
                <?php endif; ?>
                <?php
                // عنوان الصفحة ثنائي اللغة: الفرنسي فوق والعربي تحت (يُكتشفان تلقائياً من $pageTitle
                // مهما كان ترتيبه — أي جزء فيه حروف عربية = السطر العربي، والباقي = الفرنسي).
                $ptParts = array_map('trim', explode(' / ', (string)$pageTitle));
                $ptFr = []; $ptAr = [];
                foreach ($ptParts as $ptp) { if ($ptp === '') continue; if (preg_match('/\p{Arabic}/u', $ptp)) $ptAr[] = $ptp; else $ptFr[] = $ptp; }
                ?>
                <div style="display:flex;align-items:center;gap:12px">
                    <span style="width:44px;height:44px;min-width:44px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:<?= $accentColor ?>;color:#fff"><i class="fas <?= $secIcon ?>" style="font-size:20px"></i></span>
                    <h1 style="line-height:1.15;margin:0">
                        <?php if ($ptFr): ?><span dir="ltr"><?= e(implode(' / ', $ptFr)) ?></span><?php endif; ?>
                        <?php if ($ptAr): ?><span style="display:block;font-size:0.62em;font-weight:600;opacity:0.92"><?= e(implode(' / ', $ptAr)) ?></span><?php endif; ?>
                    </h1>
                </div>
            </div>

            <!-- 🔍 البحث الشامل (Ctrl+K): أستاذ أو صفحة — من أي مكان بالبرنامج -->
            <?php $gsCanDossier = viewerCanSeePage('attestations.php'); ?>
            <div class="global-search no-print" id="globalSearch">
                <i class="fas fa-magnifying-glass gs-ic"></i>
                <input type="text" id="gsInput" placeholder="Recherche / بحث: أستاذ أو صفحة..." autocomplete="off">
                <span class="gs-kbd">Ctrl+K</span>
                <div class="gs-panel" id="gsPanel"></div>
            </div>

            <div class="topbar-actions">
                <?php if (viewerCanSeePage('attestations.php')): ?>
                <a href="<?= BASE_URL ?>pages/attestations.php?dossier=1" class="topbar-dossier no-print" title="ملف الأستاذ الكامل / Dossier complet de l'enseignant">
                    <span class="td-ic"><i class="fas fa-folder-open"></i></span>
                    <span class="td-txt"><span dir="ltr">Dossier</span><span>ملف الأستاذ الكامل</span></span>
                </a>
                <?php endif; ?>
                <?php
                // مبدّل المدارس: للمدير العام (كل المدارس)، ولحساب المدرسة متعدّد المدارس (ضمن مدارسه فقط)
                $isMultiViewer = isViewer() && count(viewerAllowedSchoolIds()) > 1;
                if (isSuperAdmin() || $isMultiViewer):
                    $navSchools = isSuperAdmin()
                        ? allSchools()
                        : array_values(array_filter(allSchools(false), fn($s) => in_array((int)$s['id'], viewerAllowedSchoolIds(), true)));
                    $activeIds = activeSchoolIds();
                    $isAllSel = $isMultiViewer ? (count($activeIds) === count($navSchools)) : empty($activeIds);
                    $selLabel = $isAllSel ? 'Toutes les écoles / كل المدارس'
                              : (count($activeIds)===1 ? schoolNameById($activeIds[0]) : count($activeIds).' écoles / مدارس');
                ?>
                <div class="school-switcher school-multi" id="schoolPicker">
                    <i class="fas fa-school" style="color:#fff;background:#0891b2;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <button type="button" class="sm-btn" onclick="document.getElementById('schoolMenu').classList.toggle('open')">
                        <?= e($selLabel) ?> <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="school-menu" id="schoolMenu">
                        <form method="get" action="<?= BASE_URL ?>switch_school.php">
                            <label class="sm-all"><input type="checkbox" id="smAll" <?= $isAllSel?'checked':'' ?> onclick="document.querySelectorAll('#schoolMenu input[name=\'schools[]\']').forEach(c=>c.checked=false)"> <strong>🏫 Toutes les écoles / كل المدارس</strong></label>
                            <div class="sm-list">
                            <?php foreach ($navSchools as $navS): ?>
                                <label><input type="checkbox" name="schools[]" value="<?= (int)$navS['id'] ?>" <?= in_array((int)$navS['id'],$activeIds,true)?'checked':'' ?> onclick="document.getElementById('smAll').checked=false"> <?= e($lang==='ar'?$navS['name_ar']:$navS['name_fr']) ?></label>
                            <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-check"></i> Appliquer / تطبيق</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <form method="get" action="<?= BASE_URL ?>switch_year.php" class="school-switcher" title="<?= $lang === 'ar' ? 'السنة الدراسية' : 'Année scolaire' ?>">
                    <i class="fas fa-calendar-alt" style="color:#fff;background:#d97706;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <select name="school_year" onchange="this.form.submit()" class="form-control form-control-sm">
                        <?php
                        $aSY = activeSchoolYear();
                        $cyH = (int)date('Y'); $cmH = (int)date('n'); $startH = ($cmH >= 10) ? $cyH : $cyH - 1;
                        ?>
                        <option value="all" <?= $aSY === 'all' ? 'selected' : '' ?>>📅 Toutes années / كل السنين</option>
                        <?php for ($yh = $startH + 1; $yh >= 2006; $yh--): $syH = $yh . '-' . ($yh + 1); ?>
                            <option value="<?= $syH ?>" <?= $syH === $aSY ? 'selected' : '' ?>><?= $syH ?></option>
                        <?php endfor; ?>
                    </select>
                </form>

                <form method="get" action="<?= BASE_URL ?>switch_currency.php" class="school-switcher" title="<?= $lang === 'ar' ? 'عرض المبالغ' : 'Affichage des montants' ?>">
                    <i class="fas fa-money-bill-wave" style="color:#fff;background:#059669;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <?php $dispCur = displayCurrency(); ?>
                    <select name="currency" onchange="this.form.submit()" class="form-control form-control-sm">
                        <option value="both" <?= $dispCur === 'both' ? 'selected' : '' ?>>💱 <?= $lang === 'ar' ? 'ليرة + دولار' : 'L.L + $' ?></option>
                        <option value="lbp" <?= $dispCur === 'lbp' ? 'selected' : '' ?>>🇱🇧 <?= $lang === 'ar' ? 'ليرة فقط' : 'L.L seul' ?></option>
                        <option value="usd" <?= $dispCur === 'usd' ? 'selected' : '' ?>>💵 <?= $lang === 'ar' ? 'دولار فقط' : '$ seul' ?></option>
                    </select>
                </form>

                <?php $salSel = salaryComp(); ?>
                <div class="school-switcher school-multi" id="salCompPicker" title="<?= $lang === 'ar' ? 'مكوّنات الراتب المعروض' : 'Composantes du salaire affiché' ?>">
                    <i class="fas fa-layer-group" style="color:#fff;background:#7c3aed;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <button type="button" class="sm-btn" onclick="document.getElementById('salCompMenu').classList.toggle('open')">
                        <?= $lang === 'ar' ? 'الراتب يشمل' : 'Salaire inclut' ?> <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="school-menu" id="salCompMenu">
                        <form method="get" action="<?= BASE_URL ?>switch_salarycomp.php">
                            <div class="sm-list">
                                <label><input type="checkbox" name="comp[]" value="extra" <?= in_array('extra',$salSel,true)?'checked':'' ?>> <?= $lang==='ar'?'الأجر الإضافي':'Supplément' ?></label>
                                <label><input type="checkbox" name="comp[]" value="aide" <?= in_array('aide',$salSel,true)?'checked':'' ?>> <?= $lang==='ar'?'المكافأة والمساعدة':'Prime & aide' ?></label>
                                <label><input type="checkbox" name="comp[]" value="transport" <?= in_array('transport',$salSel,true)?'checked':'' ?>> <?= $lang==='ar'?'تعويض النقل':'Transport' ?></label>
                            </div>
                            <div style="font-size:11px;color:#64748b;padding:2px 4px 6px"><?= $lang==='ar'?'الأساس + الدرجة يبقى دائماً':'Base + échelon toujours inclus' ?></div>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-check"></i> Appliquer / تطبيق</button>
                        </form>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>switch_lang.php?lang=<?= $lang === 'ar' ? 'fr' : 'ar' ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-globe" style="color:#fff;background:#0284c7;width:30px;height:30px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <?= $lang === 'ar' ? 'Français' : 'العربية' ?>
                </a>
                
                <div class="user-menu">
                    <div class="user-avatar">
                        <?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <span><?= e($_SESSION['full_name'] ?? '') ?></span>
                </div>
                
                <a href="<?= BASE_URL ?>logout.php" class="btn btn-light btn-sm" title="Déconnexion / تسجيل الخروج">
                    <i class="fas fa-sign-out-alt" style="color:#dc2626"></i>
                </a>
            </div>
        </header>
        <!-- سكربت البحث الشامل -->
        <script>
        (function () {
            var inp = document.getElementById('gsInput'), panel = document.getElementById('gsPanel'), box = document.getElementById('globalSearch');
            if (!inp) return;
            var canDossier = <?= $gsCanDossier ? 'true' : 'false' ?>, timer = null;
            function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
            function close() { panel.classList.remove('open'); }
            function findPages(q) {
                var out = [];
                document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
                    var txt = a.textContent.trim();
                    if (txt.toLowerCase().indexOf(q) > -1) {
                        var ic = a.querySelector('i');
                        out.push({ t: txt, h: a.href, i: ic ? ic.className : 'fas fa-link' });
                    }
                });
                return out.slice(0, 6);
            }
            function render(pg, emps) {
                var h = '';
                if (pg.length) {
                    h += '<div class="gs-sec"><i class="fas fa-window-maximize"></i> Pages / الصفحات</div>';
                    pg.forEach(function (p) { h += '<a class="gs-item" href="' + esc(p.h) + '"><i class="' + esc(p.i) + '"></i><span>' + esc(p.t) + '</span></a>'; });
                }
                if (emps.length) {
                    h += '<div class="gs-sec"><i class="fas fa-users"></i> Enseignants / الأساتذة</div>';
                    emps.forEach(function (r) {
                        h += '<a class="gs-item" href="<?= BASE_URL ?>pages/attestations.php?dossier=1&employee_id=' + r.id + '">'
                           + '<i class="fas fa-user"></i><span>' + esc(r.fr) + ' / ' + esc(r.ar)
                           + '<small>' + esc(r.code + (r.school ? ' — ' + r.school : '')) + '</small></span></a>';
                    });
                }
                if (!h) h = '<div class="gs-empty">Aucun résultat / لا نتائج</div>';
                panel.innerHTML = h;
                panel.classList.add('open');
            }
            inp.addEventListener('input', function () {
                var q = inp.value.trim();
                clearTimeout(timer);
                if (q.length < 2) { close(); return; }
                timer = setTimeout(function () {
                    var pg = findPages(q.toLowerCase());
                    if (canDossier) {
                        fetch('<?= BASE_URL ?>ajax_search.php?q=' + encodeURIComponent(q))
                            .then(function (r) { return r.json(); })
                            .then(function (emps) { render(pg, emps); })
                            .catch(function () { render(pg, []); });
                    } else { render(pg, []); }
                }, 220);
            });
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key && e.key.toLowerCase() === 'k') { e.preventDefault(); inp.focus(); inp.select(); }
                if (e.key === 'Escape') close();
            });
            document.addEventListener('click', function (e) { if (!box.contains(e.target)) close(); });
        })();
        </script>

        <div class="page-content" id="pageContent" style="--accent: <?= $accentColor ?>; --accent-bg: <?= $accentBg ?>;">
        <?php
        // رسائل التنبيه (flash) — تنبيهات عائمة عصرية تختفي لحالها (الأخطاء تبقى ليكبس ×)
        $hasFlash = !empty($_SESSION['flash_success']) || !empty($_SESSION['flash_error']) || !empty($_SESSION['flash_info']);
        if ($hasFlash): ?>
        <div class="toast-stack no-print" id="toastStack">
        <?php foreach (['flash_success' => 'success', 'flash_error' => 'danger', 'flash_info' => 'info'] as $fk => $cls):
            if (!empty($_SESSION[$fk])): ?>
            <div class="alert alert-<?= $cls ?> toast">
                <i class="fas <?= $cls === 'success' ? 'fa-circle-check' : ($cls === 'danger' ? 'fa-circle-exclamation' : 'fa-info-circle') ?>"></i>
                <span><?= e($_SESSION[$fk]) ?></span>
                <button type="button" class="toast-x" onclick="this.closest('.toast').remove()" title="Fermer / إغلاق">&times;</button>
            </div>
        <?php unset($_SESSION[$fk]); endif; endforeach; ?>
        </div>
        <script>
        setTimeout(function () {
            document.querySelectorAll('#toastStack .toast:not(.alert-danger)').forEach(function (t) {
                t.classList.add('toast-hide');
                setTimeout(function () { t.remove(); }, 400);
            });
        }, 6500);
        </script>
        <?php endif; ?>
        <?php
        // شريط «الراتب المركّب يشمل» الظاهر (خانات اختيار متل شريط الإفادة) — على صفحات الرواتب فقط
        if (in_array($currentPage ?? '', ['reports', 'employee_history', 'monthly'], true)) {
            echo salaryCompToolbar();
        }
        ?>
        <?php
        // شريط الطباعة والتصدير — يظهر تلقائياً بكل صفحة (طباعة/PDF/Excel/Word/واتساب/إيميل)
        if (empty($hideExportToolbar)) {
            echo exportToolbar($exportTitle ?? $pageTitle, $exportOpts ?? []);
        }
        ?>
