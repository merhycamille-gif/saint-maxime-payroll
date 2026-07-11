<?php
/**
 * صفحة «بلوغ سنّ الـ64» — تعرض كل الموظفين/الأساتذة الذين بلغوا 64 (ضمن نطاق مدرسة المستخدم)،
 * مع قرار لكلٍّ: يبقى (وقف محسومات التقاعد) / إلغاء الإبقاء / تسجيل ترك.
 * (الصفحة الرئيسية تعرض فقط من بلغوا 64 ضمن السنة المختارة؛ هذه الصفحة تعرض الجميع.)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php'; // recalcEmployeeYear
require_once __DIR__ . '/../includes/age64.php';
requireLogin();

$db = getDB();
handleAge64Post($db, BASE_URL . 'pages/retirement_64.php'); // يعالج keep64/unkeep64/depart64 ثم يعيد التوجيه

$currentPage = 'retirement_64';
$pageTitle = 'Atteinte de 64 ans / بلوغ سنّ الـ64';

$rows = age64List($db, false); // الجميع (كل السنوات)

include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info no-print" style="margin-bottom:14px">
    <i class="fas fa-info-circle"></i> هنا كل من بلغ <strong>64 سنة</strong> وما زال على رأس عمله. لكلٍّ قراران: <strong>يبقى</strong> فتُوقَف محسومات التقاعد تلقائياً (للأستاذ الملاك صندوق التعويضات ٦٪ عنه وعن المدرسة، وللموظف نهاية الخدمة ٨.٥٪ اعتباراً من شهر بلوغه 64)، أو <strong>تركه</strong> فيُسجَّل تاريخ الترك. تظهر في <strong>الصفحة الرئيسية</strong> فقط حالات <strong>السنة الدراسية المختارة</strong>.
</div>

<?php
renderAge64Cards($rows, '<div class="card"><div class="card-body"><div class="empty-state"><i class="fas fa-user-check"></i><h4><span dir="ltr">Personne n\'a atteint 64 ans actuellement</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">لا أحد بلغ 64 حالياً</div></h4><p>ستظهر هنا أسماء من يبلغون 64 سنة وهم على رأس عملهم.</p></div></div></div>');
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
