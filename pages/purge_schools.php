<?php
/**
 * حذف مدرسة نهائياً مع كل بياناتها — Suppression définitive d'une école
 * للمدير العام فقط (superadmin). خطير وغير قابل للتراجع.
 * قبل الحذف: يحفظ نسخة استرجاع (INSERTs) لكل الصفوف المحذوفة في backups/.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireCsrf();

if (!isSuperAdmin()) {
    $_SESSION['flash_error'] = 'صلاحية المدير العام فقط / Réservé au directeur général';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$currentPage = 'schools';
$pageTitle = 'حذف مدرسة نهائياً / Suppression définitive';
$db = getDB();
$lang = $_SESSION['lang'] ?? 'fr';

/** يحوّل صفوفاً إلى جُمَل INSERT للاسترجاع. */
function dumpRowsSql($db, $table, $rows) {
    if (!$rows) return "-- $table: 0\n";
    $cols = array_keys($rows[0]);
    $colList = '`' . implode('`,`', $cols) . '`';
    $sql = "-- $table (" . count($rows) . ")\n";
    foreach ($rows as $r) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($r));
        $sql .= "INSERT INTO `$table` ($colList) VALUES (" . implode(',', $vals) . ");\n";
    }
    return $sql . "\n";
}

// ===== تنفيذ الحذف =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'DELETE') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['schools'] ?? [])), fn($x) => $x > 0)));
    if (empty($ids)) {
        $_SESSION['flash_error'] = 'لم تختر أي مدرسة / Aucune école sélectionnée';
        header('Location: ' . BASE_URL . 'pages/purge_schools.php'); exit;
    }
    $in = implode(',', $ids);

    // معرّفات الموظفين التابعين
    $empIds = $db->query("SELECT id FROM employees WHERE school_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    $empIn = $empIds ? implode(',', array_map('intval', $empIds)) : '0';

    // (1) نسخة استرجاع لكل الصفوف المتأثّرة قبل الحذف
    $dump = "-- نسخة استرجاع (Purge) — " . date('Y-m-d H:i:s') . "\n-- المدارس: $in\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $dump .= dumpRowsSql($db, 'schools', $db->query("SELECT * FROM schools WHERE id IN ($in)")->fetchAll());
    $dump .= dumpRowsSql($db, 'employees', $db->query("SELECT * FROM employees WHERE school_id IN ($in)")->fetchAll());
    $dump .= dumpRowsSql($db, 'monthly_salaries', $db->query("SELECT * FROM monthly_salaries WHERE school_id IN ($in) OR employee_id IN ($empIn)")->fetchAll());
    $dump .= dumpRowsSql($db, 'employee_bonuses', $db->query("SELECT * FROM employee_bonuses WHERE employee_id IN ($empIn)")->fetchAll());
    $dump .= dumpRowsSql($db, 'employee_grade_history', $db->query("SELECT * FROM employee_grade_history WHERE employee_id IN ($empIn)")->fetchAll());
    try { $dump .= dumpRowsSql($db, 'info_submissions', $db->query("SELECT * FROM info_submissions WHERE school_id IN ($in) OR employee_id IN ($empIn)")->fetchAll()); } catch (Exception $e) {}
    $dump .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

    $dir = __DIR__ . '/../backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = 'purge_' . date('Ymd_His') . '.sql';
    @file_put_contents($dir . '/' . $file, $dump);

    // (2) الحذف بترتيب آمن ضمن معاملة واحدة
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM employee_bonuses WHERE employee_id IN ($empIn)");
        $db->exec("DELETE FROM employee_grade_history WHERE employee_id IN ($empIn)");
        $db->exec("DELETE FROM monthly_salaries WHERE school_id IN ($in) OR employee_id IN ($empIn)");
        try { $db->exec("DELETE FROM info_submissions WHERE school_id IN ($in) OR employee_id IN ($empIn)"); } catch (Exception $e) {}
        $db->exec("DELETE FROM employees WHERE school_id IN ($in)");
        $db->exec("UPDATE users SET school_id = NULL WHERE school_id IN ($in)"); // فكّ ربط أي حساب
        $db->exec("DELETE FROM schools WHERE id IN ($in)");
        $db->commit();
        if (function_exists('logAudit')) logAudit('purge', 'schools', 0, null, "ids=$in");
        $_SESSION['flash_success'] = 'تم الحذف النهائي. نسخة الاسترجاع محفوظة في backups/' . $file . ' / Suppression effectuée.';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['flash_error'] = 'فشل الحذف — لم يُحذف شيء: ' . $e->getMessage();
    }
    header('Location: ' . BASE_URL . 'pages/purge_schools.php'); exit;
}

// ===== قائمة المدارس مع عدد الموظفين/الرواتب =====
$schools = $db->query("
    SELECT s.id, s.name_ar, s.name_fr, s.is_active,
        (SELECT COUNT(*) FROM employees e WHERE e.school_id = s.id) AS emp,
        (SELECT COUNT(*) FROM monthly_salaries m WHERE m.school_id = s.id) AS sal
    FROM schools s WHERE s.is_deleted = 0 ORDER BY s.name_fr
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Attention : / تحذير:</strong>
    Supprime définitivement l'école et toutes ses données. Une sauvegarde de récupération est créée avant. / هذه الصفحة تحذف المدرسة وكل موظفيها ورواتبهم وتاريخهم نهائياً — لا يمكن التراجع. تُحفظ نسخة استرجاع في مجلّد backups قبل الحذف.
</div>

<form method="POST" data-confirm="Suppression définitive ? / ⚠️ حذف نهائي للمدارس المختارة وكل بياناتها؟ لا رجعة!">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <input type="hidden" name="confirm" value="DELETE">
    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-trash-alt"></i> Écoles à supprimer définitivement</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">اختر المدرسة/المدارس للحذف النهائي</div>
        </h3></div>
        <div class="card-body">
            <table class="table table-hover">
                <thead><tr>
                    <th style="width:40px"></th>
                    <th>École / المدرسة</th>
                    <th>Employés / موظفون</th>
                    <th>Salaires / رواتب مسجّلة</th>
                    <th>État / الحالة</th>
                </tr></thead>
                <tbody>
                <?php foreach ($schools as $s): ?>
                    <tr>
                        <td><input type="checkbox" name="schools[]" value="<?= (int)$s['id'] ?>"></td>
                        <td><strong><?= e($lang==='ar'?$s['name_ar']:$s['name_fr']) ?></strong><br><small class="text-muted"><?= e($lang==='ar'?$s['name_fr']:$s['name_ar']) ?></small></td>
                        <td><span class="badge badge-info"><?= (int)$s['emp'] ?></span></td>
                        <td><span class="badge badge-info"><?= (int)$s['sal'] ?></span></td>
                        <td><?= $s['is_active'] ? '<span class="badge badge-success">Active / فعّالة</span>' : '<span class="badge badge-secondary">Inactive / موقوفة</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card"><div class="card-body d-flex gap-2" style="align-items:center">
        <label class="form-check" style="margin:0">
            <input type="checkbox" required> Je comprends que c'est définitif / أفهم أن الحذف نهائي ولا يمكن التراجع
        </label>
        <button type="submit" class="btn btn-danger btn-lg" style="margin-inline-start:auto">
            <i class="fas fa-trash-alt"></i> Supprimer définitivement / حذف نهائي
        </button>
        <a href="<?= BASE_URL ?>pages/schools.php" class="btn btn-light">Annuler / إلغاء</a>
    </div></div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
