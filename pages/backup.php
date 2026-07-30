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

// 🔧 اتصال ثانٍ «غير مخزّن» (unbuffered) للتدفّق صفاً صفاً:
// جدول الرواتب ضخم، وتحميله كاملاً بالذاكرة كان يفجّر النسخ الاحتياطي
// (PHP Fatal: Allowed memory size exhausted). التدفّق يعمل مهما كبرت الداتا.
function dumpConn() {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
    );
}

// ===== 1) نسخة SQL كاملة (ملف لكل الداتا، قابل للاستعادة) =====
if ($action === 'sql') {
    @set_time_limit(0);
    while (ob_get_level()) { ob_end_flush(); } // بثّ مباشر بلا تجميع بالذاكرة
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_backup_' . $stamp . '.sql"');
    echo "-- رواتب المدارس — نسخة احتياطية كاملة\n";
    echo "-- " . date('Y-m-d H:i:s') . "  DB: " . DB_NAME . "\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $dump = dumpConn();
    foreach (allTables($db) as $t) {
        $create = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        echo "DROP TABLE IF EXISTS `$t`;\n" . ($create['Create Table'] ?? '') . ";\n\n";
        $st = $dump->query("SELECT * FROM `$t`");
        $cols = ''; $batch = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === '') $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($row))) . ')';
            if (count($batch) >= 200) {
                echo "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
                flush();
            }
        }
        if ($batch) echo "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
        $st->closeCursor();
        echo "\n";
        flush();
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

// ===== HTML لكل الجداول (للـExcel/Word) — يبثّ مباشرة صفاً صفاً (لا يجمع بالذاكرة) =====
function renderAllTables($db, $forExport = false) {
    @set_time_limit(0);
    $dump = dumpConn();
    foreach (allTables($db) as $t) {
        $cnt = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo '<h3 style="margin-top:18px">' . htmlspecialchars($t) . ' (' . $cnt . ')</h3>';
        $st = $dump->query("SELECT * FROM `$t`");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo '<p style="color:#888">—</p>'; $st->closeCursor(); continue; }
        $cols = array_keys($row);
        echo '<table class="table" border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;width:100%;font-size:11px"><thead><tr>';
        foreach ($cols as $c) {
            if ($c === 'password_hash') continue; // لا نُظهر الهاش بالتصدير
            echo '<th>' . htmlspecialchars($c) . '</th>';
        }
        echo '</tr></thead><tbody>';
        do {
            echo '<tr>';
            foreach ($cols as $c) {
                if ($c === 'password_hash') continue;
                echo '<td>' . htmlspecialchars((string)($row[$c] ?? '')) . '</td>';
            }
            echo '</tr>';
        } while ($row = $st->fetch(PDO::FETCH_ASSOC));
        echo '</tbody></table>';
        $st->closeCursor();
        flush();
    }
}

// ===== 2) Excel كامل =====
if ($action === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_data_' . $stamp . '.xls"');
    echo "\xEF\xBB\xBF"; // BOM للعربي
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>';
    echo '<h2><span dir="ltr">Salaires des écoles — ' . date('Y-m-d H:i') . '</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">رواتب المدارس</div></h2>';
    renderAllTables($db, true);
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
    echo '<h2><span dir="ltr">Salaires des écoles — ' . date('Y-m-d H:i') . '</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">رواتب المدارس</div></h2>';
    renderAllTables($db);
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
    <div class="card-header"><h3><i class="fas fa-database"></i> <span dir="ltr">Sauvegarde des données</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">النسخ الاحتياطي للبيانات</div></h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            نزّل <strong>نسخة كاملة (SQL)</strong> لحفظ كل بياناتك واستعادتها وقت الحاجة، أو صدّرها <strong>Excel / Word / PDF</strong> للاطّلاع والطباعة.
            احفظ النسخة بمكان آمن دورياً.
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
            <a href="?action=sql" class="btn btn-primary"><i class="fas fa-download"></i> Sauvegarde complète (SQL) / نسخة كاملة (SQL) — ملف لكل الداتا</a>
            <a href="?action=excel" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel — toutes les données / كل الداتا</a>
            <a href="?action=word" class="btn btn-info"><i class="fas fa-file-word"></i> Word — toutes les données / كل الداتا</a>
            <button type="button" class="btn btn-danger" onclick="window.print()"><i class="fas fa-file-pdf"></i> PDF / Imprimer / طباعة</button>
        </div>

        <table class="table">
            <thead><tr><th>Table / الجدول</th><th class="text-end">Enregistrements / عدد السجلات</th></tr></thead>
            <tbody>
                <?php foreach ($counts as $t => $n): ?>
                <tr><td><?= e($t) ?></td><td class="text-end"><?= number_format($n) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total-row"><td><strong>TOTAL / الإجمالي</strong></td><td class="text-end"><strong><?= number_format($totalRows) ?></strong></td></tr>
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
