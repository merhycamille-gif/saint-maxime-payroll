<?php
/**
 * صيانة لمرّة واحدة: تصحيح تواريخ ترك 3 أساتذة سان مكسيم + حذف كل الرواتب الوهمية بعد الترك
 * لكل التاركين في كل المدارس. آمن للتشغيل أكثر من مرّة (idempotent).
 *
 * الاستعمال: افتح بالمتصفّح:
 *   https://<الموقع>/saint-maxime-payroll/fix_departures_once.php?key=16d943da8b3ff1bd
 *
 * يعتمد على pruneSalariesAfterDeparture() في includes/functions.php.
 * بعد ما يشتغل مرّة ويقول «تمّ»، يُفضَّل حذف هذا الملف من الموقع.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

// حماية بمفتاح سرّي (مفتاح غلط = رفض)
$KEY = '16d943da8b3ff1bd';
if (($_GET['key'] ?? '') !== $KEY) {
    http_response_code(403);
    exit("403 — مفتاح غير صحيح.\n");
}

$db = getDB();

echo "=== صيانة تواريخ الترك — " . date('Y-m-d H:i:s') . " ===\n\n";

// 1) تصحيح تواريخ الترك الصحيحة للأساتذة الثلاثة (مشتقّة من آخر راتب أصلي — مؤكَّدة)
$fix = [
    1082 => '2017-09-30', // ميرا ابي صافي
    1753 => '2024-09-30', // لوتشيا ابو رجيلي
    31   => '2024-12-31', // جوزيف ابي طايع
];
echo "1) تصحيح تواريخ الترك:\n";
foreach ($fix as $id => $ld) {
    $st = $db->prepare("UPDATE employees SET left_date_cnss=?, left_date_finance=?, left_date_eoc=?, updated_at=NOW()
                        WHERE id=? AND (left_date_cnss <> ? OR left_date_cnss IS NULL)");
    $st->execute([$ld, $ld, $ld, $id, $ld]);
    $nm = $db->query("SELECT CONCAT(first_name_ar,' ',last_name_ar) n FROM employees WHERE id=$id")->fetchColumn();
    echo "   id=$id ($nm) → $ld " . ($st->rowCount() ? "(صُحِّح)" : "(صحيح مسبقاً)") . "\n";
}

// 2) حذف كل الرواتب الوهمية بعد تاريخ الترك لكل التاركين في كل المدارس
echo "\n2) حذف الرواتب بعد تاريخ الترك (كل المدارس):\n";
$leavers = $db->query("SELECT id FROM employees WHERE is_deleted=0
    AND (left_date_cnss IS NOT NULL OR left_date_finance IS NOT NULL OR left_date_eoc IS NOT NULL)")->fetchAll(PDO::FETCH_COLUMN);
$totalDeleted = 0; $affectedEmps = 0;
foreach ($leavers as $eid) {
    $n = pruneSalariesAfterDeparture($db, $eid);
    if ($n > 0) { $totalDeleted += $n; $affectedEmps++; }
}
echo "   تاركون مفحوصون: " . count($leavers) . "\n";
echo "   أساتذة نُظِّفت رواتبهم: $affectedEmps\n";
echo "   صفوف راتب محذوفة (وهمية): $totalDeleted\n";

// 3) تحقّق نهائي
echo "\n3) تحقّق نهائي:\n";
$bad = 0;
foreach ($leavers as $eid) {
    $ld = $db->query("SELECT LEAST(COALESCE(left_date_cnss,'9999-12-31'),COALESCE(left_date_finance,'9999-12-31'),COALESCE(left_date_eoc,'9999-12-31')) FROM employees WHERE id=$eid")->fetchColumn();
    if ($ld === '9999-12-31') continue;
    $y = (int)substr($ld,0,4); $m = (int)substr($ld,5,2); $rank = ($m>=10)?$y:$y-1;
    $c = $db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=$eid AND ((month>=10 AND year>$rank) OR (month<10 AND year-1>$rank))")->fetchColumn();
    if ($c > 0) $bad++;
}
echo ($bad === 0)
    ? "   ✅ نظيف تماماً — ما في ولا تارك عندو راتب بعد تاريخ تركه.\n\n=== تمّ بنجاح ===\n"
    : "   ⚠️ لسا في $bad حالة — راجِع.\n";
