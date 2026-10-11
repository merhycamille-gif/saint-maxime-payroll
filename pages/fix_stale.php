<?php
/**
 * 🚨 (2026-10-11) «صحّح» من تنبيه الكشف (fileVsStoredBanner): يجعل أشهر الموظف المخزّنة لسنة مطابقةً لملفه.
 *   سنة سابقة فيها أشهر مدفوعة ⇒ صفحة المعاينة والتأكيد (employees.php?action=apply_paid) — القرار له.
 *   غير ذلك ⇒ إعادة حساب السنة فوراً من ملفه (الأشهر غير المدفوعة بسنة البرنامج) ثم الرجوع للكشف.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
if (!isAdmin()) { $_SESSION['flash_error'] = 'صلاحية المدير فقط'; header('Location: ' . BASE_URL . 'index.php'); exit; }
$eid = (int)($_GET['emp'] ?? 0);
$sy = preg_match('/^\d{4}-\d{4}$/', (string)($_GET['sy'] ?? '')) ? (string)$_GET['sy'] : '';
$back = (string)($_GET['back'] ?? '');
if (!preg_match('#^/#', $back) || preg_match('#^//#', $back)) $back = BASE_URL . 'pages/reports.php';
if ($eid <= 0 || $sy === '') { $_SESSION['flash_error'] = 'الموظف أو السنة غير محدّدين'; header('Location: ' . $back); exit; }
if (protectPaidMonths($sy)) {
    $db = getDB();
    $q = $db->prepare("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = ? AND school_year = ? AND COALESCE(is_paid,0) = 1"); $q->execute([$eid, $sy]);
    if ((int)$q->fetchColumn() > 0) { header('Location: ' . BASE_URL . 'pages/employees.php?action=apply_paid&id=' . $eid . '&sy=' . rawurlencode($sy)); exit; }
}
$n = (int)recalcEmployeeYear($eid, $sy);
try { logAudit('fix_stale_report', 'monthly_salaries', $eid, null, ['sy' => $sy, 'months' => $n, 'by' => $_SESSION['username'] ?? '']); } catch (Throwable $e) {}
$_SESSION['flash_success'] = '✅ أُعيد حساب ' . $n . ' شهراً لسنة ' . $sy . ' من ملف الموظف — الكشف صار مطابقاً للملف.';
header('Location: ' . $back); exit;
