<?php
/* =====================================================================
 * 248) 🔒❓ حارس الأرقام (2026-10-11 مارسيلا داود «حطّيت 54,000,000 وبقي بالتقارير 54,060,000 — هودي أرزاق الناس»):
 *      • حفظ الملف وهو على سنة سابقة فيها أشهر مدفوعة ⇒ سؤال قبل الحفظ «هذه السنة محمية — تطبّق؟ نعم/لا» (مودال) والقرار يُنفَّذ:
 *        نعم = recalc بالعلم الصريح (موقَّع بالتدقيق recalc_paid_decision_yes) · لا = الملف يُحفَظ والأشهر المدفوعة تبقى (بلا تنبيه ملحّ).
 *      • بلا جافاسكربت: ما تخطّاه المحرّك يُعلَن (msa_recalc_skipped) ⇒ تنبيه أصفر بالملف + معاينة وتطبيق (action=apply_paid).
 *      • الكشوف الشهرية الأربعة: تنبيه أحمر فوق الكشف إن كان الإضافي/المكافأة المخزّن ≠ ملف الموظف اليوم + كبسة «صحّح» (fix_stale.php).
 *      يُضمَّن من regression_check.php ويعمل مستقلاً: C:\xampp\php\php.exe tools\reg_paid_guard.php
 * =================================================================== */
if (!function_exists('check')) {
    $pass = 0; $fail = 0; $results = [];
    function check(string $name, bool $ok, string $detail = ''): void { global $pass, $fail, $results; $ok ? $pass++ : $fail++; $results[] = ($ok ? '✅ PASS' : '❌ FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : ''); }
    $__regStandalone = true;
}
$P248 = realpath(__DIR__ . '/..');
$pc248 = (string)file_get_contents($P248 . '/includes/payroll_calculator.php');
$em248 = (string)file_get_contents($P248 . '/pages/employees.php');
$rh248 = (string)file_get_contents($P248 . '/includes/report_helpers.php');
$rp248 = (string)file_get_contents($P248 . '/pages/reports.php');
$fx248 = (string)file_get_contents($P248 . '/pages/fix_stale.php');

check('🔒 المحرّك يعلن ما تخطّاه من أشهر مدفوعة محمية (msa_recalc_skipped) + معاينة paidMonthsDryRun + تطبيق صريح applyPaidMonthsExplicit موقَّع بالتدقيق',
      strpos($pc248, "\$GLOBALS['msa_recalc_skipped'][(int)\$employeeId][\$sy] = array_values(array_unique(array_merge(") !== false
      && strpos($pc248, "{ \$GLOBALS['msa_recalc_skipped'][(int)\$employeeId][(string)\$schoolYear][] = \$r['year'] . '-' . \$r['month']; continue; }") !== false
      && strpos($pc248, 'function paidMonthsDryRun(int $employeeId, string $schoolYear): array') !== false
      && strpos($pc248, 'function applyPaidMonthsExplicit(int $employeeId, string $schoolYear): int') !== false
      && strpos($pc248, "logAudit('recalc_paid_explicit'") !== false);

check('❓ ملف الموظف: سؤال «هذه السنة محمية — نعم/لا» قبل الحفظ (data-paid-protected + paid_decision) وتنفيذ القرار + التنبيه الأصفر/المعاينة/التطبيق بلا جافاسكربت',
      strpos($em248, "\$paidDecision = in_array(\$_POST['paid_decision'] ?? '', ['yes', 'no'], true)") !== false
      && strpos($em248, "if (\$paidDecision === 'yes') {") !== false && strpos($em248, "\$GLOBALS['msa_recalc_paid_ok'] = true;") !== false
      && strpos($em248, "logAudit('recalc_paid_decision_yes'") !== false
      && strpos($em248, "if ((\$GLOBALS['msa_paid_decision'] ?? '') === 'no') {") !== false
      && strpos($em248, 'data-paid-protected="1" data-paid-sy="') !== false
      && strpos($em248, '<input type="hidden" name="paid_decision" id="paidDecision" value="">') !== false
      && strpos($em248, 'هذه السنة محمية — ') !== false && strpos($em248, 'data-d="yes"') !== false && strpos($em248, 'data-d="no"') !== false
      && substr_count($em248, 'msaNotePaidSkipped((int)$id);') === 2
      && strpos($em248, "if (\$action === 'apply_paid' && \$id > 0) {") !== false && strpos($em248, "if (\$action === 'dismiss_paid' && \$id > 0) {") !== false
      && strpos($em248, 'id="paidPending-<?= e($ppSy) ?>"') !== false
      && strpos($em248, "['edit', 'delete', 'copy_year', 'apply_paid', 'dismiss_paid']") !== false);

check('🚨 الكشوف الشهرية الأربعة: حارس «المخزّن ≠ الملف» (fileVsStoredBanner بكاش البصمة) + ms.employee_id بالاستعلامات + كبسة «صحّح» ⇒ fix_stale.php',
      strpos($rh248, 'function fileVsStoredBanner(array $rows, int $month, int $year): string') !== false
      && strpos($rh248, "msaFpCacheGet(\$key, 900)") !== false && strpos($rh248, "pages/fix_stale.php?emp=") !== false
      && substr_count($rp248, ' . fileVsStoredBanner($data, $month, $year)') === 4
      && substr_count($rp248, '$stmt = $db->prepare("SELECT ms.employee_id, ') >= 4
      && strpos($fx248, 'if (protectPaidMonths($sy)) {') !== false && strpos($fx248, "action=apply_paid&id=") !== false
      && strpos($fx248, "logAudit('fix_stale_report'") !== false);

// تشغيل فعلي (قراءة فقط): معاينة الأشهر المدفوعة لمارسيلا داود 1677 بسنة 2025-2026 — 12 شهراً بقيم مخزّنة وجديدة
$ok248 = false; $why248 = '';
try {
    require_once $P248 . '/config/database.php'; require_once $P248 . '/includes/functions.php'; require_once $P248 . '/includes/payroll_calculator.php';
    $rows = paidMonthsDryRun(1677, '2025-2026');
    $ok248 = count($rows) === 12 && isset($rows[0]['old']['prime'], $rows[0]['new']['prime']) && $rows[0]['month'] === 10 && $rows[0]['year'] === 2025;
    $why248 = 'rows=' . count($rows) . ($rows ? ' first old=' . $rows[0]['old']['prime'] . ' new=' . ($rows[0]['new']['prime'] ?? '?') : '');
} catch (Throwable $e) { $why248 = get_class($e) . ': ' . $e->getMessage(); }
check('🔒 المعاينة تعمل فعلياً على مارسيلا داود (1677) لسنة 2025-2026: 12 شهراً مدفوعاً بقيمهما', $ok248, $why248);

if (!empty($__regStandalone)) { echo implode("\n", $results) . "\n\n═══ النتيجة: $pass ناجح · $fail فاشل ═══\n"; exit($fail ? 1 : 0); }
