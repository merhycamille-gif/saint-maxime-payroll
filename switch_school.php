<?php
/**
 * تبديل المدارس الفعّالة (للمدير العام فقط) — يدعم اختيار عدة مدارس معاً.
 * Switch active school(s) — superadmin only. Supports multiple schools.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

// المدير العام يبدّل بين كل المدارس؛ حساب المدرسة متعدّد المدارس يبدّل ضمن مدارسه المسموحة فقط.
if (isSuperAdmin() || (isViewer() && count(viewerAllowedSchoolIds()) > 1)) {
    $valid = isSuperAdmin()
        ? array_map(fn($s) => (int)$s['id'], allSchools(false)) // كل المدارس
        : viewerAllowedSchoolIds();                            // مدارس هذا الحساب فقط

    if (isset($_GET['schools'])) {
        // اختيار عدة مدارس: schools[]=1&schools[]=2... (فارغ = كل المدارس)
        $sel = array_map('intval', (array)$_GET['schools']);
        $sel = array_values(array_unique(array_filter($sel, fn($x) => $x > 0 && in_array($x, $valid, true))));
        $_SESSION['active_schools'] = $sel;          // [] = الكل
    } else {
        // توافق خلفي: school=ID مفرد (0 = الكل)
        $sid = (int)($_GET['school'] ?? 0);
        $_SESSION['active_schools'] = ($sid > 0 && in_array($sid, $valid, true)) ? [$sid] : [];
    }
    unset($_SESSION['active_school_id']);            // إلغاء المفتاح القديم المفرد
    unset($_SESSION['report_schools']);              // التقارير تتبع الاختيار الجديد
}

$back = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . 'index.php');
header('Location: ' . $back);
exit;
