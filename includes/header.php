<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
requireLogin();

$currentPage = $currentPage ?? '';
$pageTitle = $pageTitle ?? 'رواتب المدارس';
$lang = $_SESSION['lang'] ?? 'fr';
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'ar' ? 'ar' : 'fr' ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(currentSchoolName()) ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- App CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">
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
            <h2><?= e(currentSchoolName()) ?></h2>
            <p>SALAIRES DES ÉCOLES / رواتب المدارس</p>
        </div>
        
        <nav class="sidebar-nav">
            <a href="<?= BASE_URL ?>index.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i>
                <span>Tableau de bord</span>
            </a>
            
            <div class="nav-section">Personnel</div>
            
            <a href="<?= BASE_URL ?>pages/employees.php" class="<?= $currentPage === 'employees' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Employés & Enseignants</span>
            </a>
            
            <a href="<?= BASE_URL ?>pages/grades.php" class="<?= $currentPage === 'grades' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i>
                <span>Échelons & Promotions</span>
            </a>

            <a href="<?= BASE_URL ?>pages/classes.php" class="<?= $currentPage === 'classes' ? 'active' : '' ?>">
                <i class="fas fa-chalkboard"></i>
                <span>الصفوف / Classes</span>
            </a>

            <a href="<?= BASE_URL ?>pages/exceptional_laws.php" class="<?= $currentPage === 'exceptional_laws' ? 'active' : '' ?>">
                <i class="fas fa-scroll"></i>
                <span>القوانين الاستثنائية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/bulk_allowances.php" class="<?= $currentPage === 'bulk_allowances' ? 'active' : '' ?>">
                <i class="fas fa-gift"></i>
                <span>المكافآت والنقل (تابلو جماعي)</span>
            </a>

            <a href="<?= BASE_URL ?>pages/law_check.php" class="<?= $currentPage === 'law_check' ? 'active' : '' ?>">
                <i class="fas fa-balance-scale"></i>
                <span>فحص مطابقة القانون</span>
            </a>

            <div class="nav-section">Paie</div>
            
            <a href="<?= BASE_URL ?>pages/monthly_payroll.php" class="<?= $currentPage === 'monthly' ? 'active' : '' ?>">
                <i class="fas fa-money-check-alt"></i>
                <span>Paie mensuelle</span>
            </a>
            
            <a href="<?= BASE_URL ?>pages/annual_slip.php" class="<?= $currentPage === 'annual' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Relevé annuel</span>
            </a>

            <a href="<?= BASE_URL ?>pages/attestations.php" class="<?= $currentPage === 'attestations' ? 'active' : '' ?>">
                <i class="fas fa-file-signature"></i>
                <span>Attestations / إفادات</span>
            </a>

            <a href="<?= BASE_URL ?>pages/employee_history.php" class="<?= $currentPage === 'employee_history' ? 'active' : '' ?>">
                <i class="fas fa-user-clock"></i>
                <span>Dossier enseignant / سيرة الأستاذ</span>
            </a>
            <a href="<?= BASE_URL ?>pages/info_collect.php" class="<?= $currentPage === 'info_collect' ? 'active' : '' ?>">
                <i class="fab fa-whatsapp"></i>
                <span>تحديث معلومات الأساتذة / Mise à jour</span>
            </a>
            <a href="<?= BASE_URL ?>pages/left_teachers.php" class="<?= $currentPage === 'left_teachers' ? 'active' : '' ?>">
                <i class="fas fa-user-slash"></i>
                <span>الأساتذة التاركون / Départs</span>
            </a>
            <a href="<?= BASE_URL ?>pages/retirement_64.php" class="<?= $currentPage === 'retirement_64' ? 'active' : '' ?>">
                <i class="fas fa-hourglass-half"></i>
                <span>بلوغ سنّ الـ64 / Retraite 64</span>
            </a>

            <div class="nav-section">Rapports</div>
            
            <a href="<?= BASE_URL ?>pages/reports.php" class="<?= $currentPage === 'reports' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            
            <a href="<?= BASE_URL ?>pages/tax_declarations.php" class="<?= $currentPage === 'tax' ? 'active' : '' ?>">
                <i class="fas fa-file-contract"></i>
                <span>Déclarations</span>
            </a>
            
            <div class="nav-section">Système</div>

            <?php if (isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/schools.php" class="<?= $currentPage === 'schools' ? 'active' : '' ?>">
                <i class="fas fa-school"></i>
                <span>Écoles / المدارس</span>
            </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>pages/open_year.php" class="<?= $currentPage === 'open_year' ? 'active' : '' ?>">
                <i class="fas fa-folder-plus"></i>
                <span>فتح سنة دراسية / Ouvrir année</span>
            </a>

            <a href="<?= BASE_URL ?>pages/exchange_rates.php" class="<?= $currentPage === 'rates' ? 'active' : '' ?>">
                <i class="fas fa-coins"></i>
                <span>Taux de change</span>
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

            <a href="<?= BASE_URL ?>pages/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
            </a>
            <?php if (isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/email_settings.php" class="<?= $currentPage === 'email_settings' ? 'active' : '' ?>">
                <i class="fas fa-envelope"></i>
                <span>إعدادات البريد</span>
            </a>
            <?php endif; ?>
        </nav>
        
        <div class="sidebar-footer">
            v<?= APP_VERSION ?> • <?= date('Y') ?>
        </div>
    </aside>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top bar -->
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
                <?php if ($currentPage !== 'dashboard'): ?>
                <button type="button" class="btn btn-light no-print" title="رجوع / Retour"
                        onclick="if(document.referrer&&history.length>1){history.back()}else{location.href='<?= BASE_URL ?>index.php'}"
                        style="white-space:nowrap">
                    <i class="fas fa-arrow-right"></i> <?= $lang === 'ar' ? 'رجوع' : 'Retour' ?>
                </button>
                <?php endif; ?>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <div class="topbar-actions">
                <?php if (isSuperAdmin()): $navSchools = allSchools(); $activeIds = activeSchoolIds();
                    $selLabel = empty($activeIds) ? ($lang==='ar'?'كل المدارس':'Toutes les écoles')
                              : (count($activeIds)===1 ? schoolNameById($activeIds[0]) : count($activeIds).($lang==='ar'?' مدارس مختارة':' écoles'));
                ?>
                <div class="school-switcher school-multi" id="schoolPicker">
                    <i class="fas fa-school"></i>
                    <button type="button" class="sm-btn" onclick="document.getElementById('schoolMenu').classList.toggle('open')">
                        <?= e($selLabel) ?> <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="school-menu" id="schoolMenu">
                        <form method="get" action="<?= BASE_URL ?>switch_school.php">
                            <label class="sm-all"><input type="checkbox" id="smAll" <?= empty($activeIds)?'checked':'' ?> onclick="document.querySelectorAll('#schoolMenu input[name=\'schools[]\']').forEach(c=>c.checked=false)"> <strong>🏫 <?= $lang==='ar'?'كل المدارس':'Toutes les écoles' ?></strong></label>
                            <div class="sm-list">
                            <?php foreach ($navSchools as $navS): ?>
                                <label><input type="checkbox" name="schools[]" value="<?= (int)$navS['id'] ?>" <?= in_array((int)$navS['id'],$activeIds,true)?'checked':'' ?> onclick="document.getElementById('smAll').checked=false"> <?= e($lang==='ar'?$navS['name_ar']:$navS['name_fr']) ?></label>
                            <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-check"></i> <?= $lang==='ar'?'تطبيق':'Appliquer' ?></button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <form method="get" action="<?= BASE_URL ?>switch_year.php" class="school-switcher" title="<?= $lang === 'ar' ? 'السنة الدراسية' : 'Année scolaire' ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <select name="school_year" onchange="this.form.submit()" class="form-control form-control-sm">
                        <?php
                        $aSY = activeSchoolYear();
                        $cyH = (int)date('Y'); $cmH = (int)date('n'); $startH = ($cmH >= 10) ? $cyH : $cyH - 1;
                        ?>
                        <option value="all" <?= $aSY === 'all' ? 'selected' : '' ?>>📅 <?= $lang === 'ar' ? 'كل السنين' : 'Toutes années' ?></option>
                        <?php for ($yh = $startH + 1; $yh >= 2006; $yh--): $syH = $yh . '-' . ($yh + 1); ?>
                            <option value="<?= $syH ?>" <?= $syH === $aSY ? 'selected' : '' ?>><?= $syH ?></option>
                        <?php endfor; ?>
                    </select>
                </form>

                <a href="<?= BASE_URL ?>switch_lang.php?lang=<?= $lang === 'ar' ? 'fr' : 'ar' ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-globe"></i>
                    <?= $lang === 'ar' ? 'Français' : 'العربية' ?>
                </a>
                
                <div class="user-menu">
                    <div class="user-avatar">
                        <?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <span><?= e($_SESSION['full_name'] ?? '') ?></span>
                </div>
                
                <a href="<?= BASE_URL ?>logout.php" class="btn btn-light btn-sm" title="Déconnexion">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </header>
        
        <div class="page-content" id="pageContent">
        <?php
        // رسائل التنبيه (flash)
        foreach (['flash_success' => 'success', 'flash_error' => 'danger', 'flash_info' => 'info'] as $fk => $cls):
            if (!empty($_SESSION[$fk])): ?>
            <div class="alert alert-<?= $cls ?>"><i class="fas fa-info-circle"></i> <?= e($_SESSION[$fk]) ?></div>
        <?php unset($_SESSION[$fk]); endif; endforeach; ?>
        <?php
        // شريط الطباعة والتصدير — يظهر تلقائياً بكل صفحة (طباعة/PDF/Excel/Word/واتساب/إيميل)
        if (empty($hideExportToolbar)) {
            echo exportToolbar($exportTitle ?? $pageTitle, $exportOpts ?? []);
        }
        ?>
