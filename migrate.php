<?php
/**
 * تطبيق تعديلات قاعدة البيانات (migrations) بنقرة واحدة — للمدير العام فقط.
 * آمن للتكرار (idempotent): إذا التعديل مطبَّق مسبقاً لا يعيده.
 * يُستعمل أونلاين بعد نشر الكود ليضيف جدول/أعمدة جديدة دون الحاجة لـphpMyAdmin.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isSuperAdmin()) { http_response_code(403); die('غير مصرّح — لازم تكون مدير عام.'); }

$db = getDB();
$log = [];

// --- 015: جدول الصفوف class_levels ---
try {
    $db->query("SELECT 1 FROM class_levels LIMIT 1");
    $log[] = ['ok', 'جدول الصفوف (class_levels) موجود مسبقاً — لا حاجة لإجراء.'];
} catch (Exception $e) {
    $db->exec("CREATE TABLE class_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT INTO class_levels (name, sort_order) VALUES
        ('روضة أولى',1),('روضة ثانية',2),('روضة ثالثة',3),
        ('الأول أساسي',4),('الثاني أساسي',5),('الثالث أساسي',6),
        ('الرابع أساسي',7),('الخامس أساسي',8),('السادس أساسي',9),
        ('السابع أساسي',10),('الثامن أساسي',11),('التاسع أساسي',12),
        ('الأول ثانوي',13),('الثاني ثانوي',14),('الثالث ثانوي',15)");
    $log[] = ['done', 'تم إنشاء جدول الصفوف + إضافة 15 صفّاً افتراضياً.'];
}

// --- 015: عمود classes_taught في employees ---
try {
    $db->query("SELECT classes_taught FROM employees LIMIT 1");
    $log[] = ['ok', 'عمود الصفوف للأستاذ (classes_taught) موجود مسبقاً — لا حاجة لإجراء.'];
} catch (Exception $e) {
    $db->exec("ALTER TABLE employees ADD COLUMN classes_taught VARCHAR(255) NULL AFTER niveau_scolaire");
    $log[] = ['done', 'تم إضافة عمود الصفوف (classes_taught) لجدول الموظفين.'];
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>تحديث قاعدة البيانات</title>
<style>body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.box{max-width:620px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:24px}
h1{font-size:20px;color:#1e3a8a;margin-top:0}.row{padding:10px 12px;border-radius:8px;margin:8px 0;font-size:15px}
.done{background:#e8f9ef;color:#0a7a37;border:1px solid #bbe9cd}.ok{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
a{color:#1e3a8a}</style></head><body><div class="box">
<h1>✅ تحديث قاعدة البيانات</h1>
<?php foreach ($log as [$t,$m]): ?><div class="row <?= $t ?>"><?= $t==='done'?'✓ ':'• ' ?><?= e($m) ?></div><?php endforeach; ?>
<p style="margin-top:16px">خلص التحديث. هلق ميزة «الصفوف» شغّالة. ترجع لـ<a href="<?= BASE_URL ?>pages/classes.php">صفحة الصفوف</a> أو <a href="<?= BASE_URL ?>index.php">الرئيسية</a>.</p>
</div></body></html>
