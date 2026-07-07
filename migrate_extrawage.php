<?php
/**
 * نقل الأجر الإضافي + تعويض النقل 2025-2026 لكل المدارس من البرنامج القديم (ecoleNew) — تشغيل مرّة وحدة.
 * + إصلاحان قانونيان: (أ) سقف الضمان 120م ساري من 1/10/2025، (ب) الصندوق (6%) يشمل الأجر الإضافي.
 * آمن + idempotent؛ لا يمسّ الكاملين أصلاً (بلا مضاعفة)؛ مكسيموس يستبدل رقم التجربة.
 * الحماية: superadmin فقط.
 */
@set_time_limit(0);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payroll_calculator.php';
requireLogin();
if (!isSuperAdmin()) { http_response_code(403); die('غير مصرّح — لازم تكون مدير عام.'); }

$DATA = require __DIR__ . '/extrawage_data_2025-2026.php';
$sy = '2025-2026';
$db = getDB();
function nrm($s){ return str_replace(' ', '', trim((string)$s)); }

// إصلاح قانوني (أ): سقف ضمان المرض/الأمومة 120م ساري من بداية السنة الدراسية (كان 1/4/2026)
$db->exec("UPDATE cnss_brackets SET effective_from='2025-10-01' WHERE branch='maladie_maternite' AND max_salary_lbp=120000000 AND effective_from>'2025-10-01'");

$applied=0; $skipFull=0; $noSal=0; $notFound=0; $errors=0; $log=[];
foreach ($DATA as $row) {
    [$school, $fn, $ln, $base, $salaire, $idafi, $transport, $hasFund] = array_pad($row, 8, 0);
    $force = ($school == 2);
    $st = $db->prepare("SELECT e.id,
        (SELECT MAX(ms.net_salary_lbp) FROM monthly_salaries ms WHERE ms.employee_id=e.id AND ((ms.year=2025 AND ms.month>=10) OR (ms.year=2026 AND ms.month<=9))) AS cur_net,
        (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id=e.id AND ((ms.year=2025 AND ms.month>=10) OR (ms.year=2026 AND ms.month<=9))) AS n
        FROM employees e WHERE e.school_id=? AND REPLACE(e.first_name_ar,' ','')=? AND REPLACE(e.last_name_ar,' ','')=?
        ORDER BY n DESC LIMIT 1");
    $st->execute([$school, nrm($fn), nrm($ln)]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) { $notFound++; $log[]="غير موجود: $fn $ln (مدرسة $school)"; continue; }
    if ((int)$e['n'] === 0) { $noSal++; $log[]="بلا راتب: $fn $ln (مدرسة $school)"; continue; }
    if (!$force && (int)$e['cur_net'] >= $salaire*0.85) { $skipFull++; continue; }
    try {
        $id = (int)$e['id'];
        // إصلاح قانوني (ب): اشتراك صندوق التعويضات حسب القديم (له صندوق؟ يشمل الإضافي : بلا صندوق)
        if ((int)$hasFund === 1) {
            $db->prepare("UPDATE employees SET eoc_subject=1, eoc_includes_extra=1 WHERE id=? AND employee_type='enseignant_titulaire'")->execute([$id]);
        } else {
            $db->prepare("UPDATE employees SET eoc_subject=0 WHERE id=?")->execute([$id]);
        }
        // الأجر الإضافي = راتب القديم − الأساس الجديد (فيطلع المجموع = راتب القديم بالضبط مهما اختلف الأساس)
        $bstmt = $db->prepare("SELECT base_plus_echelon_lbp FROM monthly_salaries WHERE employee_id=? AND year=2025 AND month=11 LIMIT 1");
        $bstmt->execute([$id]);
        $curBase = (int)$bstmt->fetchColumn();
        $extraAmt = max(0, (int)$salaire - $curBase);
        if ($extraAmt <= 0) $extraAmt = (int)$idafi; // احتياط
        $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND bonus_type='prime_fixe' AND school_year=?")->execute([$id,$sy]);
        $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active) VALUES (?, 'prime_fixe',1,?,?, 'amount','LBP',NULL,NULL,1)")->execute([$id,$sy,$extraAmt]);
        // تعويض النقل — امسح كل أنواع النقل السابقة (تفادي المضاعفة) ثم أضِف الصحيح
        $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND bonus_type IN ('transport_complement','transport_daily') AND school_year=?")->execute([$id,$sy]);
        if ((int)$transport > 0) {
            $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active) VALUES (?, 'transport_complement',1,?,?, 'amount','LBP',NULL,NULL,1)")->execute([$id,$sy,(int)$transport]);
        }
        recalcEmployeeYear($id, $sy);
        $applied++;
    } catch (Throwable $ex) { $errors++; $log[]="خطأ: $fn $ln — ".$ex->getMessage(); }
}
header('Content-Type: text/html; charset=utf-8');
echo "<div style='font-family:sans-serif;direction:rtl;padding:20px;font-size:16px'>";
echo "<h2>✅ نقل الأجر الإضافي + النقل 2025-2026 (حسب القانون)</h2>";
echo "<p><b>طُبّق:</b> $applied أستاذ (أجر إضافي + نقل + صندوق على الإضافي + سقف الضمان)</p>";
echo "<p>كامل أصلاً: $skipFull &nbsp;|&nbsp; بلا راتب: $noSal &nbsp;|&nbsp; غير موجود: $notFound &nbsp;|&nbsp; أخطاء: $errors</p>";
if ($log) { echo "<hr><b>المتبقّي (يُضاف يدوياً):</b><ul>"; foreach(array_slice($log,0,80) as $l) echo "<li>".htmlspecialchars($l)."</li>"; echo "</ul>"; }
echo "<p style='color:green'>تمّ. الضمان (بسقفه) والصندوق والضريبة والنقل محسوبة حسب القانون. آمن لإعادة الفتح (idempotent).</p></div>";
