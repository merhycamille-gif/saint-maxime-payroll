<?php
/**
 * سكربت استرجاع لمرّة واحدة (محمي بمفتاح):
 * يعيّن كلمة مرور جديدة معروفة لكل حسابات المدير (superadmin/admin) ويعرضها.
 * لا يستطيع أحد استعماله بلا المفتاح السرّي أدناه.
 * بعد الدخول بنجاح، يُحذف هذا الملف من البرنامج (نشرة جديدة) لإغلاق الباب.
 */
$KEY     = 'r7k2m9x4qp5v8w3n';           // المفتاح السرّي — مطلوب برابط الدخول
$NEW_PW  = 'Maxime@2026';                // كلمة المرور الجديدة التي ستُعيَّن

if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit('403 forbidden'); }

require_once __DIR__ . '/config/database.php';
header('Content-Type: text/html; charset=utf-8');

$db = getDB();
$hash = password_hash($NEW_PW, PASSWORD_DEFAULT);

// حسابات المدير الفعّالة (superadmin أولاً ثم admin)
$admins = $db->query("SELECT id, username, full_name, role FROM users WHERE role IN ('superadmin','admin') ORDER BY (role='superadmin') DESC, id")->fetchAll();

$done = [];
foreach ($admins as $a) {
    $db->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?")->execute([$hash, (int)$a['id']]);
    $done[] = $a;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>استرجاع كلمة المرور</title>
<style>
 body{font-family:system-ui,'Segoe UI',Tahoma,sans-serif;background:#f8fafc;margin:0;padding:24px;color:#0f172a}
 .box{max-width:620px;margin:24px auto;background:#fff;border:2px solid #16a34a;border-radius:14px;overflow:hidden}
 .hd{background:#f0fdf4;padding:18px 22px;font-size:20px;font-weight:800;color:#166534}
 .bd{padding:22px}
 table{width:100%;border-collapse:collapse;margin-top:8px}
 th,td{border:1px solid #e2e8f0;padding:10px 12px;text-align:right}
 th{background:#f1f5f9}
 code{background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:17px;font-weight:700}
 .pw{color:#166534}
 .warn{margin-top:18px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;font-size:14px}
 a.btn{display:inline-block;margin-top:16px;background:#166534;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:700}
</style></head><body>
<div class="box">
  <div class="hd">✓ تمّ تعيين كلمة مرور جديدة للمدير</div>
  <div class="bd">
    <?php if ($done): ?>
    <table>
      <thead><tr><th>الاسم</th><th>اسم الدخول</th><th>كلمة المرور الجديدة</th></tr></thead>
      <tbody>
      <?php foreach ($done as $a): ?>
        <tr>
          <td><?= htmlspecialchars($a['full_name']) ?></td>
          <td><code><?= htmlspecialchars($a['username']) ?></code></td>
          <td><code class="pw"><?= htmlspecialchars($NEW_PW) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="warn">⚠️ احفظ اسم الدخول وكلمة المرور الآن. بعد ما تفوت، هالصفحة رح تنشال من الموقع لأسباب أمنية.</div>
    <a class="btn" href="login.php">→ اذهب لصفحة الدخول</a>
    <?php else: ?>
    <p>لا يوجد حساب مدير في القاعدة.</p>
    <?php endif; ?>
  </div>
</div>
</body></html>
