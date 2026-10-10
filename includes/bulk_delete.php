<?php
/**
 * 🗑️ حذف جماعي ناعم بصفحة تأكيد حقيقية (2026-10-10) — لصفحتَي المكرّرين والقدامى غير المضمونين.
 * القاعدة (أندره مراد 2026-08-01): «أي محي يسألني قبل» ⇒ GET = صفحة تأكيد تعدّد الأسماء، POST confirmed=1 = التنفيذ.
 * الأمان: المعرّفات المرسَلة تُقاطَع مع المجموعة المؤهَّلة المحسوبة من جديد على الخادم (لا حذف لغير المؤهَّل)؛
 *         نسخة من كل صف قبل الحذف في employees_deleted_backup (تركيب ذاتي) + logAudit لكل موظف. الحذف ناعم (is_deleted=1) ويُسترجع.
 */
function bulkDeleteEnsureBackupTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS employees_deleted_backup (bk_id INT AUTO_INCREMENT PRIMARY KEY, bk_at DATETIME NOT NULL, bk_by VARCHAR(100) NULL, bk_reason VARCHAR(100) NULL, employee_id INT NOT NULL, row_json LONGTEXT NOT NULL, INDEX (employee_id)) DEFAULT CHARSET=utf8mb4");
}
/**
 * @param array $eligible  [id => 'label'] المؤهَّلون للحذف (محسوبون على الخادم)
 * @param string $reason   سبب قصير يُسجَّل
 * @param string $backUrl  صفحة الرجوع
 * @return bool  true = عُرضت صفحة تأكيد أو نُفّذ الحذف (المستدعي ينهي الصفحة)، false = لا شيء مطلوب
 */
function bulkDeleteFlow(PDO $db, array $eligible, string $reason, string $backUrl, string $titleFr, string $titleAr): bool {
    if (($_GET['action'] ?? '') !== 'bulk_delete') return false;
    requireWriteAction();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? $_GET['ids'] ?? [])), fn($i) => isset($eligible[$i])));
    if (!$ids) { $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'لا أحد مؤهَّل للحذف / Rien à supprimer']; header('Location: ' . $backUrl); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirmed'])) {
        bulkDeleteEnsureBackupTable($db);
        $sel = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0");
        $ins = $db->prepare("INSERT INTO employees_deleted_backup (bk_at, bk_by, bk_reason, employee_id, row_json) VALUES (NOW(), ?, ?, ?, ?)");
        $upd = $db->prepare("UPDATE employees SET is_deleted = 1 WHERE id = ? AND is_deleted = 0");
        $n = 0; $by = (string)($_SESSION['username'] ?? ($_SESSION['full_name'] ?? ''));
        foreach ($ids as $id) {
            $sel->execute([$id]); $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $ins->execute([$by, $reason, $id, json_encode($row, JSON_UNESCAPED_UNICODE)]);
            $upd->execute([$id]);
            if ($upd->rowCount()) { $n++; logAudit('delete', 'employees', $id, null, $reason); }
        }
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => "تم حذف $n موظفاً (حذف ناعم، نسخة محفوظة) / $n supprimé(s)"];
        header('Location: ' . $backUrl); exit;
    }
    // صفحة التأكيد
    $currentPage = $GLOBALS['currentPage'] ?? ''; $pageTitle = 'Confirmer la suppression / تأكيد الحذف'; $hideExportToolbar = true;
    include __DIR__ . '/header.php';
    ?>
    <div class="card" style="max-width:860px;margin:30px auto;border:2px solid #dc2626">
        <div class="card-body" style="padding:24px">
            <div style="text-align:center"><div style="font-size:44px;margin-bottom:6px">⚠️</div>
                <h3 style="margin:0 0 4px"><span dir="ltr"><?= e($titleFr) ?></span> / <?= e($titleAr) ?></h3>
                <p style="color:#64748b;margin:4px 0 14px">حذف ناعم: بيختفوا من كل البرنامج، ورواتبهم القديمة ونسخة من ملفهم بتضلّ محفوظة بالقاعدة ومنقدر نرجّعهم.</p></div>
            <form method="post" action="?action=bulk_delete">
                <input type="hidden" name="confirmed" value="1">
                <div class="table-wrapper"><table class="table" style="margin-bottom:14px"><thead><tr><th>#</th><th>Employé / الموظف</th></tr></thead><tbody>
                <?php foreach ($ids as $i => $id): ?>
                    <tr><td><?= $i + 1 ?></td><td><strong><?= e($eligible[$id]) ?></strong><input type="hidden" name="ids[]" value="<?= (int)$id ?>"></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <div class="d-flex gap-2" style="justify-content:center">
                    <a href="<?= e($backUrl) ?>" class="btn btn-light btn-lg"><i class="fas fa-arrow-left"></i> لا، رجوع / Non</a>
                    <button type="submit" class="btn btn-danger btn-lg"><i class="fas fa-trash"></i> نعم، احذف الـ<?= count($ids) ?> / Oui, supprimer</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}
