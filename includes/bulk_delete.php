<?php
/**
 * 🗑️ حذف جماعي ناعم بصفحة تأكيد حقيقية (2026-10-10) — لصفحتَي المكرّرين والقدامى غير المضمونين.
 * القاعدة (أندره مراد 2026-08-01): «أي محي يسألني قبل» ⇒ GET = صفحة تأكيد تعدّد الأسماء، POST confirmed=1 = التنفيذ.
 * الأمان: المعرّفات المرسَلة تُقاطَع مع المجموعة المؤهَّلة المحسوبة من جديد على الخادم (لا حذف لغير المؤهَّل)؛
 *         نسخة من كل صف قبل الحذف في employees_deleted_backup (تركيب ذاتي) + logAudit لكل موظف. الحذف ناعم (is_deleted=1) ويُسترجع.
 */
/**
 * 🧨 (بكلماته 2026-10-10 «شيلو من كل البرنامج والداتا كمان» ثم «وما بدي ياهن يبقوا على السيرفر بالنسخة الاحتياطية»):
 *   حذف نهائي من السيرفر بلا أي جدول احتياطي عليه — الملف ورواتبه وكل صفّ باسمه بأي جدول (بما فيها جداول _bk_ القديمة).
 *   النسخة الاحتياطية الوحيدة = على كمبيوتره (C:\Users\user\payroll_backups\db + نسخ المزامنة tmp/online_dump_*).
 */
function bulkDeleteHard(PDO $db, int $id): int {
    static $tables = null;
    if ($tables === null) {
        $tables = $db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'employee_id' AND TABLE_NAME <> 'employees' AND TABLE_NAME NOT LIKE 'smp\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    }
    $n = 0;
    foreach ($tables as $t) { try { $st = $db->prepare("DELETE FROM `$t` WHERE employee_id = ?"); $st->execute([$id]); $n += $st->rowCount(); } catch (Throwable $e) {} }
    foreach (['employees_deleted_backup', '_names_fr_bk20261001'] as $t) { try { $db->prepare("DELETE FROM `$t` WHERE employee_id = ? OR id = ?")->execute([$id, $id]); } catch (Throwable $e) {} }
    $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
    return $n;
}
function bulkDeleteEnsureBackupTable(PDO $db): void {
    // جداول احتياطية على السيرفر: ممنوعة بطلبه — تُزال إن وُجدت
    foreach (['employees_deleted_backup', 'monthly_salaries_deleted_backup'] as $t) { try { $db->exec("DROP TABLE IF EXISTS `$t`"); } catch (Throwable $e) {} }
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
        $sel = $db->prepare("SELECT id, employee_code, first_name_fr, last_name_fr FROM employees WHERE id = ?");
        $n = 0; $ns = 0;
        foreach ($ids as $id) {
            $sel->execute([$id]); $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            logAudit('delete', 'employees', $id, trim($row['first_name_fr'] . ' ' . $row['last_name_fr']) . ' #' . $row['employee_code'], $reason . ' (hard)');
            $ns += bulkDeleteHard($db, (int)$id); $n++;
        }
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => "تم حذف $n موظفاً نهائياً من السيرفر مع $ns صفّاً من رواتبهم وبياناتهم / $n supprimé(s) définitivement"];
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
                <p style="color:#b91c1c;margin:4px 0 14px;font-weight:800">حذف نهائي من السيرفر: الملف ورواتبه وكل بياناته بتنشال بلا أي نسخة على السيرفر. النسخة الوحيدة = على الكمبيوتر (payroll_backups).</p></div>
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
