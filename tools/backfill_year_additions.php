<?php
/**
 * backfill_year_additions.php — نقل الأجر الإضافي والمكافأة لسنة مفتوحة سابقاً بلا نقلها
 * ==========================================================================
 * (2026-07-29) السنة 2026-2027 فُتحت قبل وجود آلية نسخ العلاوات، فطلعت رواتبها
 * بلا «الأجر الإضافي/المكافأة» رغم وجودها بالسنة الماضية (شكوى المستخدم p1).
 * هذا السكربت (CLI فقط):
 *   1. يأخذ نسخة احتياطية (_eb_bk_yradd + _ms_bk_yradd)
 *   2. ينسخ prime_fixe + aide_complementaire من السنة السابقة لكل موظف عنده رواتب
 *      بالسنة الجديدة وما عنده هذه العلاوات فيها (idempotent — لا يكرّر الموجود)
 *   3. يعيد حساب كل أشهر السنة الجديدة بالمحرّك (يقرأ العلاوات المنسوخة)
 * لا يلمس النقل (موجود أصلاً عبر transport_daily/عمود الموظف — نسخه يدوبل المبلغ).
 * التشغيل: C:\xampp\php\php.exe tools\backfill_year_additions.php 2026-2027
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$PROJ = dirname(__DIR__);
require_once $PROJ . '/config/database.php';
require_once $PROJ . '/includes/functions.php';
require_once $PROJ . '/includes/payroll_calculator.php';
$db = getDB();

$newSY = $argv[1] ?? '2026-2027';
if (!preg_match('/^\d{4}-\d{4}$/', $newSY)) die("سنة غير صحيحة: $newSY\n");
[$y1, $y2] = schoolYearToYears($newSY);
$prevSY = ($y1 - 1) . '-' . $y1;

// (1) نسخة احتياطية (مرّة واحدة لكل تشغيلة يوم)
$suf = date('md');
$db->exec("CREATE TABLE IF NOT EXISTS _eb_bk_yradd$suf AS SELECT * FROM employee_bonuses");
$db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_yradd$suf AS SELECT * FROM monthly_salaries WHERE school_year='" . $newSY . "'");
echo "باكاب: _eb_bk_yradd$suf + _ms_bk_yradd$suf\n";

// (2) الموظفون الذين لهم رواتب بالسنة الجديدة
$emps = $db->prepare("SELECT DISTINCT e.* FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                      WHERE ms.school_year = ? AND e.is_deleted = 0");
$emps->execute([$newSY]);
$emps = $emps->fetchAll(PDO::FETCH_ASSOC);

$copied = 0; $skipped = 0; $recalced = 0; $errors = 0;
foreach ($emps as $emp) {
    $id = (int)$emp['id'];
    // عنده إضافات بالسنة الجديدة أصلاً؟ لا تلمسه (خياره اليدوي محفوظ)
    $has = $db->prepare("SELECT 1 FROM employee_bonuses WHERE employee_id=? AND school_year=? AND is_active=1
                         AND bonus_type IN ('prime_fixe','aide_complementaire') LIMIT 1");
    $has->execute([$id, $newSY]);
    $didCopy = false;
    if (!$has->fetchColumn()) {
        $sel = $db->prepare("SELECT * FROM employee_bonuses WHERE employee_id=? AND school_year=? AND is_active=1
                             AND bonus_type IN ('prime_fixe','aide_complementaire')");
        $sel->execute([$id, $prevSY]);
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active)
                          VALUES (?,?,?,?,?,?,?,?,?,1)")
               ->execute([$id, $b['bonus_type'], $b['period_number'], $newSY, $b['amount'], $b['value_type'], $b['currency'], $b['start_month'], $b['end_month']]);
            $didCopy = true;
        }
        if ($didCopy) $copied++;
        else $skipped++; // ما كان عنده إضافات السنة الماضية أصلاً
    } else { $skipped++; }

    // (3) إعادة حساب كل أشهر السنة الجديدة (المحرّك يقرأ العلاوات)
    $months = ((int)$emp['payment_months_per_year'] === 10)
        ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
        : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
    $ok = true;
    foreach ($months as [$m, $y]) {
        try { (new PayrollCalculator($id, $m, $y))->calculateAndSave(); }
        catch (Exception $e) { $ok = false; }
    }
    $ok ? $recalced++ : $errors++;
    echo sprintf("%-8d %-28s %s\n", $id, trim($emp['first_name_fr'].' '.($emp['last_name_fr'] ?? '')), $didCopy ? 'نُسخت إضافاته ✓' : 'بلا نسخ (موجودة/لا شيء)');
}

echo "\n═══ الخلاصة: " . count($emps) . " موظف — نُسخت إضافات " . $copied . " · بلا نسخ " . $skipped . " · أُعيد حساب " . $recalced . " · أخطاء " . $errors . " ═══\n";
