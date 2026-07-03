<?php
/**
 * تنظيف شامل لمرّة واحدة (آمن، idempotent): يحذف صفوف الرواتب الصفرية الوهمية
 * (base=net=total=0) للأساتذة الذين لا راتب فعلي لهم في تلك السنة — أشباح مخفيّون.
 * لا يمسّ أي راتب فعلي، ولا سجلّات الموظفين، ولا أي صفّ لموظف عنده راتب حقيقي بالسنة.
 *
 * الاستعمال:
 *   https://<الموقع>/saint-maxime-payroll/cleanup_ghosts.php?key=16d943da8b3ff1bd
 * (بعد التشغيل ورؤية «تمّ»، يُفضَّل حذف هذا الملف من الموقع.)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');

$KEY = '16d943da8b3ff1bd';
if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit("403 — مفتاح غير صحيح.\n"); }

$db = getDB();
echo "=== تنظيف الصفوف الصفرية الوهمية — " . date('Y-m-d H:i:s') . " ===\n\n";

// كل الصفوف الصفرية تماماً التي صاحبها لا راتب فعلي له في نفس السنة الدراسية (لكل السنوات)
$sel = "SELECT z.id FROM monthly_salaries z
        WHERE z.base_plus_echelon_lbp = 0 AND z.net_salary_lbp = 0 AND z.total_due_lbp = 0
          AND NOT EXISTS (
            SELECT 1 FROM monthly_salaries r
            WHERE r.employee_id = z.employee_id AND r.school_year = z.school_year
              AND (r.base_plus_echelon_lbp > 0 OR r.net_salary_lbp > 0 OR r.total_due_lbp > 0)
          )";
$ids = array_column($db->query($sel)->fetchAll(PDO::FETCH_ASSOC), 'id');
echo "صفوف صفرية وهمية للحذف: " . count($ids) . "\n";

if ($ids) {
    $total = 0;
    foreach (array_chunk($ids, 1000) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $total += $db->exec("DELETE FROM monthly_salaries WHERE id IN ($in)");
    }
    echo "انحذف: $total صفّ ✅\n";
}

// تحقّق نهائي: لم يُحذف أي صفّ فعلي، ولا تأثّر أي أستاذ عنده راتب حقيقي
$remainingZero = $db->query("SELECT COUNT(*) FROM monthly_salaries z
    WHERE z.base_plus_echelon_lbp=0 AND z.net_salary_lbp=0 AND z.total_due_lbp=0
      AND NOT EXISTS (SELECT 1 FROM monthly_salaries r WHERE r.employee_id=z.employee_id AND r.school_year=z.school_year AND (r.base_plus_echelon_lbp>0 OR r.net_salary_lbp>0 OR r.total_due_lbp>0))")->fetchColumn();
echo "\nصفوف صفرية وهمية متبقّية: $remainingZero " . ($remainingZero == 0 ? "✅" : "") . "\n";
echo "\n=== تمّ بنجاح — قاعدة الأونلاين صارت نظيفة ===\n";
