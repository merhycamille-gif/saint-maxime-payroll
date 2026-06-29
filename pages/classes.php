<?php
/**
 * إدارة لائحة الصفوف الدراسية (عامة لكل المدارس).
 * تُستعمل في ملف الموظف لاختيار «الصفوف التي يعلّم فيها» الأستاذ.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireCsrf();

$currentPage = 'classes';
$pageTitle = 'الصفوف الدراسية';
$db = getDB();

// الجدول قد لا يكون موجوداً أونلاين قبل تطبيق migration 015 — اعرض إرشاداً بدل خطأ
$tableExists = true;
try { $db->query("SELECT 1 FROM class_levels LIMIT 1"); } catch (Exception $e) { $tableExists = false; }
if (!$tableExists) {
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">جدول الصفوف غير موجود بعد في قاعدة البيانات. طبّق ملف <code>sql/migrations/015_class_levels.sql</code> مرّة واحدة لتفعيل الميزة.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = BASE_URL . 'pages/classes.php';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM class_levels")->fetchColumn();
            $db->prepare("INSERT INTO class_levels (name, sort_order, is_active) VALUES (?, ?, 1)")
               ->execute([$name, $maxOrder + 1]);
            $newId = (int)$db->lastInsertId();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تمت إضافة الصف.'];
            $redirect .= '?saved=' . $newId . '#cls' . $newId; // ارجع لنفس مكان الصف الجديد
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name !== '') {
            $db->prepare("UPDATE class_levels SET name = ?, sort_order = ?, is_active = ? WHERE id = ?")
               ->execute([$name, $sort, $active, $id]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تم حفظ التعديل.'];
            $redirect .= '?saved=' . $id . '#cls' . $id; // ابقَ بنفس مكان الصف لرؤية تأكيد الحفظ
        }
    }
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // كم أستاذ يستعمل هذا الصف؟ (المعرّف ضمن قائمة classes_taught المفصولة بفواصل)
    $used = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted=0 AND FIND_IN_SET($id, classes_taught)")->fetchColumn();
    if ($used > 0) {
        // لا نحذف فعلياً كي لا نكسر اختيارات الأساتذة؛ نُعطّله فقط (يختفي من قوائم الاختيار)
        $db->prepare("UPDATE class_levels SET is_active = 0 WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => "هذا الصف مستعمَل عند $used أستاذ، فعُطّل (أُخفي من الاختيار) بدل حذفه نهائياً حفاظاً على بياناتهم."];
    } else {
        $db->prepare("DELETE FROM class_levels WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تم حذف الصف.'];
    }
    header('Location: ' . BASE_URL . 'pages/classes.php');
    exit;
}

$message = ''; $messageType = 'success';
if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

$classes = $db->query("SELECT * FROM class_levels ORDER BY sort_order, id")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus"></i> إضافة صف جديد / Ajouter une classe</h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">اسم الصف / Nom de la classe</label>
                    <input type="text" name="name" class="form-control" placeholder="مثال: الأول ثانوي – علوم" required>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> إضافة</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i> لائحة الصفوف (<?= count($classes) ?>)</h3></div>
    <div class="card-body">
        <p style="color:var(--gray-600);margin-top:0">عدّل الاسم أو الترتيب، أو ألغِ تفعيل صفّ ليختفي من خيارات ملف الأستاذ. الترتيب يحدّد تسلسل ظهور الصفوف.</p>
        <table class="table">
            <thead><tr><th style="width:80px">الترتيب</th><th>اسم الصف</th><th style="width:90px">مُفعّل</th><th style="width:200px">إجراء</th></tr></thead>
            <tbody>
                <?php $savedId = (int)($_GET['saved'] ?? 0); foreach ($classes as $c): $fid = 'cls' . $c['id']; $justSaved = ($savedId === (int)$c['id']); ?>
                <tr<?= !$c['is_active'] ? ' style="opacity:.55"' : ($justSaved ? ' style="background:#e8f9ef"' : '') ?>>
                    <td><input form="<?= $fid ?>" type="number" name="sort_order" class="form-control" value="<?= (int)$c['sort_order'] ?>" style="width:70px"></td>
                    <td><input form="<?= $fid ?>" type="text" name="name" class="form-control" value="<?= e($c['name']) ?>" required></td>
                    <td style="text-align:center"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>></td>
                    <td style="white-space:nowrap">
                        <form id="<?= $fid ?>" method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> حفظ</button>
                        </form>
                        <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-danger" data-confirm="حذف هذا الصف؟"><i class="fas fa-trash"></i></a>
                        <?php if ($justSaved): ?><span style="color:#0a7a37;font-weight:700;margin-right:6px">✓ تم الحفظ</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$classes): ?><tr><td colspan="4" style="text-align:center;color:var(--gray-500)">لا صفوف بعد — أضف من الأعلى.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
