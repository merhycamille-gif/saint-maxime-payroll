<?php
/**
 * Sauvegarde / النسخ الاحتياطي
 * - نسخة كاملة لكل الداتا (ملف SQL يرجّع كل شيء) — ?action=sql
 * - تصدير كل الداتا Excel (?action=excel) و Word (?action=word)
 * - PDF/طباعة عبر شريط الأدوات
 * للمدير فقط (admin/superadmin). نسخة كاملة بلا فلترة مدرسة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    $_SESSION['flash_error'] = 'صلاحية المدير مطلوبة / Accès réservé à l\'administrateur';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$db = getDB();
$action = $_GET['action'] ?? '';
$stamp = date('Y-m-d_His');

// كل جداول القاعدة
function allTables($db) {
    return $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
}

// ===== 1) نسخة SQL كاملة (ملف لكل الداتا، قابل للاستعادة) =====
if ($action === 'sql') {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_backup_' . $stamp . '.sql"');
    echo "-- رواتب المدارس — نسخة احتياطية كاملة\n";
    echo "-- " . date('Y-m-d H:i:s') . "  DB: " . DB_NAME . "\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach (allTables($db) as $t) {
        $create = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        echo "DROP TABLE IF EXISTS `$t`;\n" . ($create['Create Table'] ?? '') . ";\n\n";
        $rows = $db->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($row)));
            echo "INSERT INTO `$t` ($cols) VALUES ($vals);\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

// ===== HTML لكل الجداول (للـExcel/Word والعرض) =====
function renderAllTables($db, $forExport = false) {
    $html = '';
    foreach (allTables($db) as $t) {
        $rows = $db->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $html .= '<h3 style="margin-top:18px">' . htmlspecialchars($t) . ' (' . count($rows) . ')</h3>';
        if (!$rows) { $html .= '<p style="color:#888">—</p>'; continue; }
        $cols = array_keys($rows[0]);
        $html .= '<table class="table" border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;width:100%;font-size:11px"><thead><tr>';
        foreach ($cols as $c) {
            if ($c === 'password_hash') continue; // لا نُظهر الهاش بالتصدير
            $html .= '<th>' . htmlspecialchars($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($cols as $c) {
                if ($c === 'password_hash') continue;
                $html .= '<td>' . htmlspecialchars((string)($row[$c] ?? '')) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    }
    return $html;
}

// ===== 2) Excel كامل =====
if ($action === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_data_' . $stamp . '.xls"');
    echo "\xEF\xBB\xBF"; // BOM للعربي
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>';
    echo '<h2>رواتب المدارس — ' . date('Y-m-d H:i') . '</h2>';
    echo renderAllTables($db, true);
    echo '</body></html>';
    exit;
}

// ===== 3) Word كامل =====
if ($action === 'word') {
    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_data_' . $stamp . '.doc"');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8">'
       . '<style>table{border-collapse:collapse;width:100%}td,th{border:1px solid #999;padding:3px;font-size:10px}</style></head><body>';
    echo '<h2>رواتب المدارس — ' . date('Y-m-d H:i') . '</h2>';
    echo renderAllTables($db);
    echo '</body></html>';
    exit;
}

// ===== صفحة العرض =====
$currentPage = 'backup';
$pageTitle = 'Sauvegarde / النسخ الاحتياطي';
$hideExportToolbar = true; // عنا أزرارنا الخاصة
include __DIR__ . '/../includes/header.php';

$tables = allTables($db);
$counts = [];
$totalRows = 0;
foreach ($tables as $t) {
    $n = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $counts[$t] = $n; $totalRows += $n;
}
?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-database"></i> Sauvegarde des données / النسخ الاحتياطي للبيانات</h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            نزّل <strong>نسخة كاملة (SQL)</strong> لحفظ كل بياناتك واستعادتها وقت الحاجة، أو صدّرها <strong>Excel / Word / PDF</strong> للاطّلاع والطباعة.
            احفظ النسخة بمكان آمن دورياً.
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
            <a href="?action=sql" class="btn btn-primary"><i class="fas fa-download"></i> نسخة كاملة (SQL) — ملف لكل الداتا</a>
            <a href="?action=excel" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel — كل الداتا</a>
            <a href="?action=word" class="btn btn-info"><i class="fas fa-file-word"></i> Word — كل الداتا</a>
            <button type="button" class="btn btn-danger" onclick="window.print()"><i class="fas fa-file-pdf"></i> PDF / Imprimer</button>
        </div>

        <table class="table">
            <thead><tr><th>Table / الجدول</th><th class="text-end">Enregistrements / عدد السجلات</th></tr></thead>
            <tbody>
                <?php foreach ($counts as $t => $n): ?>
                <tr><td><?= e($t) ?></td><td class="text-end"><?= number_format($n) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total-row"><td><strong>TOTAL</strong></td><td class="text-end"><strong><?= number_format($totalRows) ?></strong></td></tr>
            </tbody>
        </table>

        <div class="alert alert-warning mt-3 no-print">
            <i class="fas fa-shield-alt"></i>
            <strong>للاستعادة:</strong> افتح phpMyAdmin → اختر قاعدة <code><?= e(DB_NAME) ?></code> → Import → اختر ملف الـSQL.
            (أو اطلب مني وقت الحاجة.)
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
