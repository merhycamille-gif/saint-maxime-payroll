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
$pageTitle = 'Classes / الصفوف الدراسية';
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
// تصحيح ذاتي: لو عمود الاسم الفرنسي (name_fr) ناقص أونلاين (migration 016 لم يُطبَّق) أضِفه — يمنع خطأ 500 عند الإضافة
try { $db->query("SELECT name_fr FROM class_levels LIMIT 1"); }
catch (Exception $e) { try { $db->exec("ALTER TABLE class_levels ADD COLUMN name_fr VARCHAR(100) NULL AFTER name"); } catch (Exception $e2) {} }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = BASE_URL . 'pages/classes.php';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '');
        if ($name !== '') {
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM class_levels")->fetchColumn();
            $db->prepare("INSERT INTO class_levels (name, name_fr, sort_order, is_active) VALUES (?, ?, ?, 1)")
               ->execute([$name, $nameFr, $maxOrder + 1]);
            $newId = (int)$db->lastInsertId();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تمت إضافة الصف.'];
            $redirect .= '?saved=' . $newId . '#cls' . $newId; // ارجع لنفس مكان الصف الجديد
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $nameFr = trim($_POST['name_fr'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name !== '') {
            $db->prepare("UPDATE class_levels SET name = ?, name_fr = ?, sort_order = ?, is_active = ? WHERE id = ?")
               ->execute([$name, $nameFr, $sort, $active, $id]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تم حفظ التعديل.'];
            $redirect .= '?saved=' . $id . '#cls' . $id; // ابقَ بنفس مكان الصف لرؤية تأكيد الحفظ
        }
    } elseif ($action === 'seed_defaults') {
        // إضافة الصفوف اللبنانية الافتراضية (روضات + أساسي 1-9 + ثانوي 1-3) — idempotent، لا يكرّر الموجود
        $defaults = [
            ['روضة أولى','PS'], ['روضة ثانية','MS'], ['روضة ثالثة','GS'],
            ['الأول أساسي','EB1'], ['الثاني أساسي','EB2'], ['الثالث أساسي','EB3'],
            ['الرابع أساسي','EB4'], ['الخامس أساسي','EB5'], ['السادس أساسي','EB6'],
            ['السابع أساسي','EB7'], ['الثامن أساسي','EB8'], ['التاسع أساسي','EB9'],
            ['الأول ثانوي','Secondaire 1'], ['الثاني ثانوي','Secondaire 2'], ['الثالث ثانوي','Secondaire 3'],
        ];
        $added = 0; $ord = 0;
        $exists = $db->prepare("SELECT 1 FROM class_levels WHERE name = ? OR (name_fr <> '' AND name_fr = ?) LIMIT 1");
        $ins = $db->prepare("INSERT INTO class_levels (name, name_fr, sort_order, is_active) VALUES (?, ?, ?, 1)");
        foreach ($defaults as [$nm, $fr]) {
            $ord++;
            $exists->execute([$nm, $fr]);
            if ($exists->fetchColumn()) continue;
            $ins->execute([$nm, $fr, $ord]);
            $added++;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "تمت إضافة $added صفّ افتراضي" . ($added < 15 ? ' (الباقي موجود أصلاً)' : '') . '. صارت تظهر بفورم الأساتذة.'];
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

<?php if (count($classes) === 0): ?>
<div class="card" style="border:2px solid #0a6b5e">
    <div class="card-body" style="text-align:center;padding:22px">
        <p style="font-size:15px;margin-top:0">لسا ما في صفوف معرّفة — لهيك ما بتظهر بفورم الأساتذة. اكبس لإضافة <strong>الصفوف اللبنانية الجاهزة</strong> (روضات + أساسي 1-9 + ثانوي 1-3) دفعة وحدة:</p>
        <form method="POST" style="margin:0">
            <?= csrfField() ?><input type="hidden" name="action" value="seed_defaults">
            <button class="btn" style="background:#0a6b5e;color:#fff;font-size:15px;padding:10px 22px"><i class="fas fa-magic"></i> Ajouter les classes par défaut (15) / إضافة الصفوف الافتراضية (15 صفّ)</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">
            <span dir="ltr"><i class="fas fa-plus"></i> Ajouter une classe</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">إضافة صف جديد</div>
        </h3>
        <?php if (count($classes) > 0): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('إضافة الصفوف اللبنانية الافتراضية الناقصة؟');">
            <?= csrfField() ?><input type="hidden" name="action" value="seed_defaults">
            <button class="btn btn-sm" style="background:#0a6b5e;color:#fff"><i class="fas fa-magic"></i> Ajouter les classes par défaut / إضافة الصفوف الافتراضية</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-row cols-3">
                <div class="form-group">
                    <label class="form-label">الاسم بالفرنسي / Nom (FR)</label>
                    <input type="text" name="name_fr" class="form-control" placeholder="ex: EB1, Secondaire 1">
                </div>
                <div class="form-group">
                    <label class="form-label">Nom (AR) / الاسم بالعربي</label>
                    <input type="text" name="name" class="form-control" placeholder="مثال: الأول أساسي" required>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Ajouter / إضافة</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-list"></i> Liste des classes (<?= count($classes) ?>)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">لائحة الصفوف</div>
    </h3></div>
    <div class="card-body">
        <p style="color:var(--gray-600);margin-top:0">عدّل الاسم أو الترتيب، أو ألغِ تفعيل صفّ ليختفي من خيارات ملف الأستاذ. الترتيب يحدّد تسلسل ظهور الصفوف.</p>
        <table class="table">
            <thead><tr><th style="width:80px">Ordre / الترتيب</th><th>Nom (FR) / الاسم بالفرنسي</th><th>Nom (AR) / الاسم بالعربي</th><th style="width:90px">Actif / مُفعّل</th><th style="width:200px">Action / إجراء</th></tr></thead>
            <tbody>
                <?php $savedId = (int)($_GET['saved'] ?? 0); foreach ($classes as $c): $fid = 'cls' . $c['id']; $justSaved = ($savedId === (int)$c['id']); ?>
                <tr<?= !$c['is_active'] ? ' style="opacity:.55"' : ($justSaved ? ' style="background:#e8f9ef"' : '') ?>>
                    <td><input form="<?= $fid ?>" type="number" name="sort_order" class="form-control" value="<?= (int)$c['sort_order'] ?>" style="width:70px"></td>
                    <td><input form="<?= $fid ?>" type="text" name="name_fr" class="form-control" value="<?= e($c['name_fr'] ?? '') ?>" placeholder="FR"></td>
                    <td><input form="<?= $fid ?>" type="text" name="name" class="form-control" value="<?= e($c['name']) ?>" required></td>
                    <td style="text-align:center"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>></td>
                    <td style="white-space:nowrap">
                        <form id="<?= $fid ?>" method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                        </form>
                        <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-danger" data-confirm="حذف هذا الصف؟"><i class="fas fa-trash"></i></a>
                        <?php if ($justSaved): ?><span style="color:#0a7a37;font-weight:700;margin-right:6px">✓ Enregistré / تم الحفظ</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$classes): ?><tr><td colspan="5" style="text-align:center;color:var(--gray-500)">Aucune classe — ajoutez ci-dessus / لا صفوف بعد — أضف من الأعلى.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
