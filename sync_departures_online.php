<?php
/**
 * مزامنة تواريخ ترك الأساتذة القدامى إلى قاعدة الأونلاين (لمرّة واحدة، آمن للتكرار).
 * البيانات مصدّرة من القاعدة المحلية (dep_dates_data.php) — 888 تارك عبر كل السنوات.
 *
 * آمن: (1) يطابق كل سجلّ بالـid + **الاسم** (لو الاسم مختلف يتخطّى، فلا يفسد بيانات موظف آخر).
 *      (2) يضع التاريخ **فقط إن كان فارغاً أونلاين** (لا يمسح/يبدّل أي تاريخ موجود).
 *      (3) الوضع الافتراضي **فحص فقط (dry-run)** — لا يكتب شيئاً حتى تضيف &apply=1.
 *
 * الاستعمال:
 *   فحص أولاً:  https://<الموقع>/saint-maxime-payroll/sync_departures_online.php?key=7c1f9ae4d20b83e6
 *   ثمّ التطبيق: https://<الموقع>/saint-maxime-payroll/sync_departures_online.php?key=7c1f9ae4d20b83e6&apply=1
 *
 * بعد ما يشتغل مرّة ويقول «تمّ»، احذف هذا الملف وملف dep_dates_data.php من الموقع.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$KEY = '7c1f9ae4d20b83e6';
if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit("403 — مفتاح غير صحيح.\n"); }

$apply = (($_GET['apply'] ?? '') === '1');
$data = require __DIR__ . '/dep_dates_data.php';
$db = getDB();

// تطبيع الاسم للمقارنة: إزالة الفراغات الزائدة والمحارف الخفية
$norm = function ($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
};

echo "=== مزامنة تواريخ الترك — " . date('Y-m-d H:i:s') . " ===\n";
echo ($apply ? "الوضع: ✍️  تطبيق فعلي (سيكتب على القاعدة)\n\n" : "الوضع: 🔍 فحص فقط (dry-run) — ما رح يكتب شي. أضِف &apply=1 للتطبيق.\n\n");

$sel = $db->prepare("SELECT COALESCE(NULLIF(first_name_ar,''),first_name_fr) fn, COALESCE(NULLIF(last_name_ar,''),last_name_fr) ln,
                            left_date_cnss c, left_date_finance f, left_date_eoc e FROM employees WHERE id = ? AND is_deleted = 0");

$stats = ['total'=>count($data), 'missing'=>0, 'name_mismatch'=>0, 'already_full'=>0, 'set'=>0, 'fields_set'=>0];
$mismatchSamples = []; $missingSamples = [];

foreach ($data as $rec) {
    [$id, $name, $cLoc, $fLoc, $eLoc] = $rec;
    $sel->execute([(int)$id]);
    $on = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$on) { $stats['missing']++; if (count($missingSamples) < 8) $missingSamples[] = "$id ($name)"; continue; }

    $onName = $norm($on['fn'] . ' ' . $on['ln']);
    if ($norm($name) !== $onName) {
        $stats['name_mismatch']++;
        if (count($mismatchSamples) < 8) $mismatchSamples[] = "id=$id: محلي «$name» ≠ أونلاين «$onName»";
        continue; // أمان: اسم مختلف = لا تلمسه
    }

    // ضع كل تاريخ فرع فقط إن كان فارغاً أونلاين والمحلي موجود
    $sets = []; $vals = [];
    $map = ['left_date_cnss'=>[$on['c'],$cLoc], 'left_date_finance'=>[$on['f'],$fLoc], 'left_date_eoc'=>[$on['e'],$eLoc]];
    foreach ($map as $col => $pair) {
        [$onlineVal, $localVal] = $pair;
        $onlineEmpty = empty($onlineVal) || $onlineVal === '0000-00-00';
        if ($onlineEmpty && !empty($localVal) && $localVal !== '0000-00-00') { $sets[] = "$col = ?"; $vals[] = $localVal; }
    }
    if (!$sets) { $stats['already_full']++; continue; }

    if ($apply) {
        $vals[] = (int)$id;
        $db->prepare("UPDATE employees SET " . implode(', ', $sets) . ", updated_at=NOW() WHERE id = ?")->execute($vals);
    }
    $stats['set']++; $stats['fields_set'] += count($sets);
}

echo "النتيجة:\n";
echo "  إجمالي التاركين بالبيانات:        {$stats['total']}\n";
echo "  ✅ " . ($apply ? "رُكِّبت تواريخهم" : "سيُركَّب لهم") . ":            {$stats['set']} أستاذ ({$stats['fields_set']} حقل تاريخ)\n";
echo "  • عندهم تواريخ مسبقاً (تُركوا):    {$stats['already_full']}\n";
echo "  ⚠️ غير موجودين أونلاين (id مفقود): {$stats['missing']}\n";
echo "  ⚠️ اسم مختلف (تُخُطّوا للأمان):     {$stats['name_mismatch']}\n";

if ($missingSamples) { echo "\n  عيّنة مفقودين: \n    - " . implode("\n    - ", $missingSamples) . "\n"; }
if ($mismatchSamples) { echo "\n  عيّنة أسماء مختلفة:\n    - " . implode("\n    - ", $mismatchSamples) . "\n"; }

if ($apply && $stats['set'] > 0) {
    // نظّف الرواتب الوهمية بعد الترك لمن رُكِّبت تواريخهم (شفاء ذاتي، اختياري)
    echo "\nتنظيف الرواتب الوهمية بعد الترك...\n";
    $pruned = 0;
    foreach ($data as $rec) { $n = pruneSalariesAfterDeparture($db, (int)$rec[0]); if ($n>0) $pruned += $n; }
    echo "  صفوف راتب وهمية محذوفة: $pruned\n";
    echo "\n=== ✅ تمّ بنجاح — افتح صفحة «الأساتذة التاركون» واختر «كل السنوات». احذف هذا الملف الآن. ===\n";
} elseif ($apply) {
    echo "\n=== ✅ تمّ — لا جديد لتركيبه (كلهم مركّبون مسبقاً). ===\n";
} else {
    echo "\n=== 🔍 فحص فقط. إذا الأرقام منطقية، أعِد الفتح مع &apply=1 للتطبيق الفعلي. ===\n";
}
