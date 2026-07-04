<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payroll_calculator.php'; // recalcEmployeeYear لإجراءات الـ64
requireLogin();

$currentPage = 'dashboard';
$pageTitle = 'Tableau de bord';

$db = getDB();

// ===== إجراءات تنبيه بلوغ الـ64 (من لوحة القيادة) =====
// keep64 = إبقاؤه بعد 64 مع وقف محسومات التقاعد · unkeep64 = إلغاء الإبقاء · depart64 = تسجيل ترك
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['keep64','unkeep64','depart64'], true)) {
    requireCsrf();
    $eid = (int)($_POST['emp_id'] ?? 0);
    // تأكّد أن الموظف ضمن نطاق مدرسة المستخدم (المدير العام يرى الكل)
    $chk = $db->prepare("SELECT id, birth_date FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $chk->execute([$eid]);
    $row = $chk->fetch();
    if ($row) {
        $act = $_POST['action'];
        if ($act === 'keep64' || $act === 'unkeep64') {
            $val = $act === 'keep64' ? 1 : 0;
            $db->prepare("UPDATE employees SET keep_working_past_64 = ? WHERE id = ?")->execute([$val, $eid]);
            // أعِد حساب كل سنواته المخزّنة ليُطبَّق/يُلغى وقف المحسومات من شهر بلوغه 64
            foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . $eid)->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                if ($sy) recalcEmployeeYear($eid, $sy);
            }
            recalcEmployeeYear($eid);
            $_SESSION['flash_success'] = $val
                ? 'تم الإبقاء على العمل بعد الـ64 ووقف محسومات التقاعد تلقائياً اعتباراً من شهر بلوغه 64.'
                : 'تم إلغاء الإبقاء — عادت محسومات التقاعد كالمعتاد.';
        } elseif ($act === 'depart64') {
            $d = parseFlexibleDate($_POST['depart_date'] ?? '');
            if (!$d && !empty($row['birth_date'])) {   // افتراضي: تاريخ بلوغه 64
                $bd = date_create(substr($row['birth_date'], 0, 10));
                if ($bd) $d = $bd->modify('+64 years')->format('Y-m-d');
            }
            if ($d) {
                $db->prepare("UPDATE employees SET left_date_cnss = ?, left_date_finance = ?, left_date_eoc = ? WHERE id = ?")
                   ->execute([$d, $d, $d, $eid]);
                pruneSalariesAfterDeparture($db, $eid);
                $_SESSION['flash_success'] = 'تم تسجيل ترك العمل بتاريخ ' . displayDMY($d) . ' — خرج من السنة الجارية وحُذفت رواتبه بعد هذا التاريخ.';
            } else {
                $_SESSION['flash_error'] = 'تعذّر تحديد تاريخ الترك — اكتب التاريخ يدوياً.';
            }
        }
    } else {
        $_SESSION['flash_error'] = 'الموظف ليس ضمن مدرستك.';
    }
    header('Location: ' . BASE_URL . 'index.php'); exit;
}

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

// تنبيه بلوغ سنّ الـ64: موظفون/أساتذة فاعلون غير تاركين بلغوا 64 (تاريخ ولادة صحيح مسجّل)
// محصّن: قبل تطبيق migration 017 (إضافة عمود keep_working_past_64) قد لا يكون العمود موجوداً
// أونلاين → نتجاهل التنبيه بدل كسر الصفحة (يُطبَّق العمود بزيارة migrate.php مرّة).
$over64 = [];
try {
    $st64 = $db->prepare("SELECT e.id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
            e.employee_type, e.birth_date, e.keep_working_past_64,
            COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
        FROM employees e LEFT JOIN schools sc ON sc.id = e.school_id
        WHERE e.is_deleted = 0 AND e.status = 'actif'
          AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL
          AND e.birth_date IS NOT NULL AND e.birth_date NOT IN ('0000-00-00','1900-01-01')
          AND TIMESTAMPDIFF(YEAR, e.birth_date, CURDATE()) >= 64" . schoolScopeSql('e.school_id')
        . " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
            COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr)");
    $st64->execute();
    $over64 = $st64->fetchAll();
} catch (Exception $e) { $over64 = []; }
$need64 = array_filter($over64, fn($r) => empty($r['keep_working_past_64']));
$kept64 = array_filter($over64, fn($r) => !empty($r['keep_working_past_64']));

include __DIR__ . '/includes/header.php';
?>

<?php if ($need64): ?>
<div class="card no-print" style="border:2px solid #dc2626;margin-bottom:16px">
    <div class="card-header" style="background:#fef2f2"><h3 style="color:#b91c1c"><i class="fas fa-triangle-exclamation"></i> تنبيه: بلغوا سنّ الـ64 — قرار مطلوب (<?= count($need64) ?>)</h3></div>
    <div class="card-body">
        <p style="color:var(--gray-600);margin-top:0">هؤلاء بلغوا <strong>64 سنة</strong>. قرّر لكلٍّ: <strong>يبقى</strong> (تُوقَف محسومات التقاعد تلقائياً — للأستاذ الملاك صندوق التعويضات ٦٪ <strong>عنه وعن المدرسة</strong>، وللموظف نهاية الخدمة ٨.٥٪)، أو <strong>تركه</strong> (يُسجَّل تاريخ الترك فيخرج من السنة).</p>
        <div class="table-wrapper"><table class="table">
            <thead><tr><th>الاسم</th><th>الفئة</th><th>العمر</th><th>تاريخ بلوغ 64</th><th>المدرسة</th><th>القرار</th></tr></thead>
            <tbody>
            <?php foreach ($need64 as $r):
                $nm = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
                $age = ageOnDate($r['birth_date']);
                $b64 = date_create(substr($r['birth_date'], 0, 10)); $def = $b64 ? $b64->modify('+64 years')->format('Y-m-d') : '';
            ?>
            <tr>
                <td><strong><?= e($nm) ?></strong></td>
                <td><?= employeeTypeLabel($r['employee_type']) ?></td>
                <td><span class="badge" style="background:#b45309;color:#fff"><?= (int)$age ?> سنة</span></td>
                <td style="white-space:nowrap"><strong style="color:#b45309"><?= $def ? e(displayDMY($def)) : '—' ?></strong></td>
                <td><?= e($r['school_name']) ?></td>
                <td style="white-space:nowrap">
                    <form method="post" style="display:inline" onsubmit="return confirm('إبقاؤه على العمل بعد الـ64 ووقف محسومات التقاعد؟')">
                        <?= csrfField() ?><input type="hidden" name="action" value="keep64"><input type="hidden" name="emp_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-success"><i class="fas fa-user-check"></i> يبقى — وقف المحسومات</button>
                    </form>
                    <form method="post" style="display:inline-flex;gap:4px;align-items:center;margin-inline-start:6px" onsubmit="return confirm('تسجيل ترك العمل بهذا التاريخ؟')">
                        <?= csrfField() ?><input type="hidden" name="action" value="depart64"><input type="hidden" name="emp_id" value="<?= $r['id'] ?>">
                        <input type="date" name="depart_date" value="<?= e($def) ?>" style="padding:5px;border:1px solid #cbd5e1;border-radius:6px">
                        <button class="btn btn-sm btn-light"><i class="fas fa-door-open"></i> تركه</button>
                    </form>
                    <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-user"></i> الملف</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<?php if ($kept64): ?>
<div class="card no-print" style="border:1px solid #86efac;margin-bottom:16px">
    <div class="card-header" style="background:#f0fdf4"><h3 style="color:#15803d"><i class="fas fa-user-check"></i> مُبقَون بعد الـ64 — محسومات التقاعد موقوفة (<?= count($kept64) ?>)</h3></div>
    <div class="card-body"><div class="table-wrapper"><table class="table">
        <thead><tr><th>الاسم</th><th>الفئة</th><th>العمر</th><th>تاريخ بلوغ 64</th><th>المدرسة</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($kept64 as $r):
            $nm = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
            $age = ageOnDate($r['birth_date']);
            $b64k = date_create(substr($r['birth_date'], 0, 10)); $d64k = $b64k ? $b64k->modify('+64 years')->format('Y-m-d') : ''; ?>
        <tr>
            <td><strong><?= e($nm) ?></strong></td>
            <td><?= employeeTypeLabel($r['employee_type']) ?></td>
            <td><?= (int)$age ?> سنة</td>
            <td style="white-space:nowrap"><strong style="color:#15803d"><?= $d64k ? e(displayDMY($d64k)) : '—' ?></strong></td>
            <td><?= e($r['school_name']) ?></td>
            <td><form method="post" onsubmit="return confirm('إلغاء الإبقاء وإعادة محسومات التقاعد كالمعتاد؟')" style="margin:0">
                <?= csrfField() ?><input type="hidden" name="action" value="unkeep64"><input type="hidden" name="emp_id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-light"><i class="fas fa-rotate-left"></i> إلغاء الإبقاء</button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat-label">Total Personnel</div>
            <div class="stat-value"><?= $stats['total_employees'] ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-chalkboard-teacher"></i></div>
        <div>
            <div class="stat-label">Enseignants Titulaires</div>
            <div class="stat-value"><?= $stats['titulaires'] ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon info"><i class="fas fa-user-clock"></i></div>
        <div>
            <div class="stat-label">Contractuels</div>
            <div class="stat-value"><?= $stats['contractuels'] ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-user-tie"></i></div>
        <div>
            <div class="stat-label">Employés</div>
            <div class="stat-value"><?= $stats['employes'] ?></div>
        </div>
    </div>
</div>

<div class="form-row cols-2">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> Informations système</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <tr>
                    <td><strong>Année scolaire</strong></td>
                    <td><?= e(getSetting('current_school_year', currentSchoolYear())) ?></td>
                </tr>
                <tr>
                    <td><strong>Taux de change actuel</strong></td>
                    <td><?= formatLBP($exchangeRate) ?> / $1</td>
                </tr>
                <tr>
                    <td><strong>Salaire minimum (Loi)</strong></td>
                    <td><?= formatLBP(getSetting('minimum_wage_lbp', 28000000)) ?></td>
                </tr>
                <tr>
                    <td><strong>Total payé ce mois</strong></td>
                    <td><strong class="text-success"><?= formatLBP($totalPaid) ?></strong></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-bolt"></i> Actions rapides</h3>
        </div>
        <div class="card-body">
            <div class="d-flex gap-3" style="flex-direction:column">
                <a href="<?= BASE_URL ?>pages/employees.php?action=new" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Ajouter un employé
                </a>
                <a href="<?= BASE_URL ?>pages/monthly_payroll.php" class="btn btn-gold">
                    <i class="fas fa-calculator"></i> Calculer la paie mensuelle
                </a>
                <a href="<?= BASE_URL ?>pages/annual_slip.php" class="btn btn-light">
                    <i class="fas fa-file-invoice"></i> Voir relevé annuel
                </a>
                <a href="<?= BASE_URL ?>pages/exchange_rates.php" class="btn btn-light">
                    <i class="fas fa-coins"></i> Gérer les taux de change
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-gavel"></i> Réglementation appliquée</h3>
    </div>
    <div class="card-body">
        <div class="form-row cols-2">
            <div>
                <h4 style="color:var(--primary);">Enseignants Titulaires</h4>
                <ul>
                    <li>Échelle des grades : Loi 2017 (Journal Officiel n°37)</li>
                    <li>CNSS Maladie/Maternité : 3% (employé) + 8% (école)</li>
                    <li>Caisse d'indemnités (EOC) : 6% (employé) + 6% (école)</li>
                    <li>Impôt sur le revenu : Loi 324/2024 — Titre II</li>
                    <li>Grades exceptionnels : Lois 244, 344, 102, 223</li>
                </ul>
            </div>
            <div>
                <h4 style="color:var(--primary);">Employés (Code du travail)</h4>
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
