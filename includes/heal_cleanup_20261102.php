<?php
/**
 * 🧹 شفاء مرّة واحدة — تنظيف 2026-11-02 (قراراته على الـ35 فحصاً فاشلاً، واحداً واحداً، ثمّ «نفّذ وبلّش بس بدون أخطاء»):
 *   المرجع: memory/saint-maxime-regression-cleanup-decisions.md. كل خطوة مستقلّة (try/catch)، نسخة احتياطية لكل صفّ يُمسّ
 *   في جداول `_bk_cleanup1102_<table>`، وسجلّ تدقيق. يعمل محلياً وأونلاين (مفتاح الإعداد خاصّ بالجهاز) ومتسامح مع غياب
 *   الصفوف (الأونلاين كان يحذف نهائياً). الأشهر المدفوعة لا تُمسّ إلّا حيث قرّر (عيف عيد: إرجاع من نسخة 20/8).
 */
function healCleanup20261102(): void {
    if (getSetting('heal_cleanup_20261102', '') !== '') return;
    $db = getDB();
    require_once __DIR__ . '/payroll_calculator.php';
    require_once __DIR__ . '/bulk_delete.php';
    $log = [];
    // النسخ الاحتياطية بجداول `_bk_…` على كمبيوتره فقط (قاعدته 10/10: لا جداول احتياطية للمحذوفين على السيرفر — نسخة السيرفر = دمب المزامنة على الكمبيوتر)
    $isLocal = php_sapi_name() === 'cli' || in_array(strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]), ['localhost', '127.0.0.1'], true);
    $bk = function (string $table, string $where, array $p = []) use ($db, $isLocal): int {
        if (!$isLocal) return 0;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `_bk_cleanup1102_$table` LIKE `$table`");
            $st = $db->prepare("INSERT IGNORE INTO `_bk_cleanup1102_$table` SELECT * FROM `$table` WHERE $where"); $st->execute($p); return $st->rowCount();
        } catch (Throwable $e) { return -1; }
    };
    $bkEmployee = function (int $id) use ($db, $bk): void {   // كل جدول فيه employee_id + صفّ الموظف نفسه
        $bk('employees', 'id = ?', [$id]);
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'employee_id' AND TABLE_NAME NOT LIKE '\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) $bk($t, 'employee_id = ?', [$id]);
    };
    $exists = fn(int $id) => (bool)$db->query("SELECT 1 FROM employees WHERE id = $id")->fetchColumn();
    $audit = function (string $what, int $rid, array $d) { try { logAudit('heal_cleanup_1102_' . $what, 'employees', $rid, null, $d); } catch (Throwable $e) {} };

    // ── S1 ألماس فرح: ملف واحد = القديم 279 يرجع، صفوف وعلاوات 2026-2027 من الجديد 1000025 تنتقل إليه، ثمّ الجديد يُحذف نهائياً
    try {
        if ($exists(279)) {
            $bk('employees', 'id IN (279, 1000025)');
            $db->exec("UPDATE employees SET is_deleted = 0, status = 'actif' WHERE id = 279");
            if ($exists(1000025)) {
                $bkEmployee(1000025);
                $newPhone = (string)$db->query("SELECT phone1 FROM employees WHERE id = 1000025")->fetchColumn();
                if ($newPhone !== '') $db->prepare("UPDATE employees SET phone2 = IF(COALESCE(phone2,'') = '' OR phone2 = phone1, ?, phone2) WHERE id = 279 AND COALESCE(phone1,'') <> ?")->execute([$newPhone, $newPhone]);
                $bk('employee_bonuses', "employee_id = 279 AND school_year = '2026-2027'");
                $db->exec("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = 279 AND school_year = '2026-2027'");
                $db->exec("UPDATE employee_bonuses SET employee_id = 279 WHERE employee_id = 1000025 AND school_year = '2026-2027'");
                $bk('monthly_salaries', "employee_id = 279 AND school_year = '2026-2027'");
                $db->exec("DELETE FROM monthly_salaries WHERE employee_id = 279 AND school_year = '2026-2027' AND COALESCE(is_paid,0) = 0");
                $moved = $db->exec("UPDATE IGNORE monthly_salaries SET employee_id = 279 WHERE employee_id = 1000025 AND school_year = '2026-2027'");
                try { $db->exec("UPDATE info_submissions SET employee_id = 279 WHERE employee_id = 1000025"); } catch (Throwable $e) {}
                bulkDeleteHard($db, 1000025);
                $log['S1_almaz'] = "279 restored, moved rows=$moved, 1000025 hard-deleted";
            } else $log['S1_almaz'] = '279 restored (1000025 absent)';
            recalcEmployeeYear(279, '2026-2027');
            $audit('almaz_single_file', 279, ['merged_from' => 1000025]);
        } else $log['S1_almaz'] = '279 absent';
    } catch (Throwable $e) { $log['S1_almaz'] = 'ERR ' . $e->getMessage(); }

    // ── S2 حذف نهائي (بقراره): ريتا حليحل 917 (أب «.»)، لور لور 761، ناتالي غسطين بجون 1000027 و1000031
    foreach ([917 => 'ريتا حليحل (أب .)', 761 => 'لور لور', 1000027 => 'ناتالي غسطين جون 1', 1000031 => 'ناتالي غسطين جون 2'] as $id => $who) {
        try {
            if (!$exists($id)) { $log["S2_$id"] = 'absent'; continue; }
            $paid = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = $id AND is_paid = 1")->fetchColumn();
            if ($paid > 0) { $log["S2_$id"] = "SKIP paid rows=$paid"; continue; }   // صمّام: لا حذف لملف له أشهر مدفوعة
            $bkEmployee($id); $n = bulkDeleteHard($db, $id); $audit('hard_delete', $id, ['who' => $who, 'rows' => $n]); $log["S2_$id"] = "deleted ($who) rows=$n";
        } catch (Throwable $e) { $log["S2_$id"] = 'ERR ' . $e->getMessage(); }
    }

    // ── S3 صفوف يتيمة: موظف محذوف (is_deleted=1) وله رواتب/علاوات ⇒ تُشال (قراره 10/10 «من كل الداتا») — ألماس أُرجعت قبلها
    try {
        $orph = $db->query("SELECT DISTINCT ms.employee_id FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id WHERE e.is_deleted = 1")->fetchAll(PDO::FETCH_COLUMN);
        $n = 0; foreach ($orph as $id) { $id = (int)$id; $bk('monthly_salaries', 'employee_id = ?', [$id]); $bk('employee_bonuses', 'employee_id = ?', [$id]); $n += (int)$db->exec("DELETE FROM monthly_salaries WHERE employee_id = $id"); $db->exec("DELETE FROM employee_bonuses WHERE employee_id = $id"); }
        $log['S3_orphans'] = 'employees=' . count($orph) . " rows=$n (" . implode(',', $orph) . ')';
    } catch (Throwable $e) { $log['S3_orphans'] = 'ERR ' . $e->getMessage(); }

    // ── S4 جانيت فرح 37: تاريخ ترك 30/9 من آخر سنة عملت فيها (بلا رواتب ⇒ سنة دخولها 1995-1996)
    try {
        $j = $db->query("SELECT id, hire_date, left_date_all FROM employees WHERE id = 37 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if ($j && empty($j['left_date_all'])) {
            $last = (string)$db->query("SELECT MAX(school_year) FROM monthly_salaries WHERE employee_id = 37 AND base_plus_echelon_lbp > 0")->fetchColumn();
            $ld = preg_match('/^\d{4}-(\d{4})$/', $last, $m) ? $m[1] . '-09-30' : ((int)substr((string)$j['hire_date'], 0, 4) + 1) . '-09-30';
            $bk('employees', 'id = 37');
            $db->prepare("UPDATE employees SET left_date_all = ?, notes = CONCAT(COALESCE(notes,''), ' | تاريخ الترك مُستكمَل 2026-11-02 (ملف قديم بلا رواتب)') WHERE id = 37")->execute([$ld]);
            $audit('leave_date', 37, ['left_date_all' => $ld]); $log['S4_jeannette'] = "left $ld";
        } else $log['S4_jeannette'] = $j ? 'already has leave date' : 'absent';
    } catch (Throwable $e) { $log['S4_jeannette'] = 'ERR ' . $e->getMessage(); }

    // ── S5 إيليو زعرور 1000030: متعاقد (بأجر اتفاق = أساسه الحالي حتى لا يتغيّر راتبه)
    try {
        $el = $db->query("SELECT id, employee_type, contract_salary_lbp, base_salary_usd FROM employees WHERE id = 1000030 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if ($el && $el['employee_type'] !== 'enseignant_contractuel') {
            $bk('employees', 'id = 1000030');
            $base = (int)$db->query("SELECT MAX(base_plus_echelon_lbp) FROM monthly_salaries WHERE employee_id = 1000030 AND school_year = '2026-2027'")->fetchColumn();
            $setBase = ((float)$el['contract_salary_lbp'] <= 0 && (float)$el['base_salary_usd'] <= 0 && $base > 0) ? ", salary_input_mode = 'direct_lbp', contract_salary_lbp = $base" : '';
            $db->exec("UPDATE employees SET employee_type = 'enseignant_contractuel', titularization_date = NULL $setBase WHERE id = 1000030");
            recalcEmployeeYear(1000030, '2026-2027'); $audit('type_contract', 1000030, ['base' => $base]); $log['S5_elio'] = "contractuel base=$base";
        } else $log['S5_elio'] = $el ? 'already contractuel' : 'absent';
    } catch (Throwable $e) { $log['S5_elio'] = 'ERR ' . $e->getMessage(); }

    // ── S6 عبد القادر الحجار 1000028: دخول 1/10/2026 (كان 2027 خطأً)، أوّل سنة ⇒ 1/10→30/6، أشهره على 2026-2027
    try {
        $ab = $db->query("SELECT id, hire_date FROM employees WHERE id = 1000028 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if ($ab && (string)$ab['hire_date'] === '2027-10-01') {
            $bk('employees', 'id = 1000028'); $bk('monthly_salaries', "employee_id = 1000028"); $bk('employee_bonuses', 'employee_id = 1000028');
            $db->exec("UPDATE employees SET hire_date = '2026-10-01', payment_months_per_year = 9, pay_from_month = 10, pay_to_month = 6 WHERE id = 1000028");
            $db->exec("UPDATE employee_bonuses SET school_year = '2026-2027' WHERE employee_id = 1000028 AND school_year = '2027-2028'");
            $db->exec("DELETE FROM monthly_salaries WHERE employee_id = 1000028 AND school_year = '2027-2028' AND COALESCE(is_paid,0) = 0");
            $n = recalcEmployeeYear(1000028, '2026-2027'); $audit('hire_fix', 1000028, ['hire' => '2026-10-01', 'months' => $n]); $log['S6_abed'] = "hire 2026-10-01, months=$n";
        } else $log['S6_abed'] = $ab ? 'hire already ' . $ab['hire_date'] : 'absent';
    } catch (Throwable $e) { $log['S6_abed'] = 'ERR ' . $e->getMessage(); }

    // ── S7 الأربعة بعبرا على 9 أشهر وليسوا أوّل سنة ⇒ 12 شهراً (قاعدته: مَن كان قبل = 12)
    try {
        $ids = [1817, 1822, 1820, 1646]; $done = [];
        foreach ($ids as $id) {
            $r = $db->query("SELECT payment_months_per_year pm, hire_date FROM employees WHERE id = $id AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int)$r['pm'] === 12 || (string)$r['hire_date'] >= '2026-10-01') continue;
            $bk('employees', 'id = ?', [$id]); $bk('employee_bonuses', "employee_id = $id AND school_year = '2026-2027'");
            $db->exec("UPDATE employees SET payment_months_per_year = 12, pay_from_month = 10, pay_to_month = 9 WHERE id = $id");
            // 12 شهراً = علاواته الفعّالة لهذه السنة على 12 شهراً أيضاً (سطر «ت1 ← حزيران» من إعداد 9 أشهر يمتدّ إلى أيلول) — وإلّا بقي تمّوز→أيلول بالأساس وحده
            $db->exec("UPDATE employee_bonuses SET end_month = 9 WHERE employee_id = $id AND school_year = '2026-2027' AND is_active = 1 AND start_month = 10 AND end_month = 6");
            $done[$id] = recalcEmployeeYear($id, '2026-2027');
        }
        $audit('months12', 0, $done); $log['S7_months12'] = json_encode($done);
    } catch (Throwable $e) { $log['S7_months12'] = 'ERR ' . $e->getMessage(); }

    // ── S8 شهر 13 تعويضاً: متعاقد فاعل دخل قبل سنتين فأكثر (hire ≤ 1/10 قبل سنتين) ولم يُرسَّم **وله راتب هذه السنة** ⇒ has_13th_month=1 (+ خيارا الشهر 13 كالافتراضي)
    //    (ملف «فاعل» بلا أي راتب هذه السنة لا يُعلَّم — الشهر 13 تعويض عن راتب يُقبض)
    try {
        $cur = currentSchoolYear(); $y1 = (int)substr($cur, 0, 4); $cut = ($y1 - 2) . '-10-01';
        $st = $db->prepare("SELECT e.id FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.left_date_all IS NULL AND e.employee_type = 'enseignant_contractuel' AND e.hire_date <= ? AND COALESCE(e.has_13th_month,0) = 0
            AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND (ms.net_salary_lbp > 0 OR ms.base_plus_echelon_lbp > 0 OR ms.prime_fixe_lbp > 0))");
        $st->execute([$cut, $cur]); $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) { $in = implode(',', $ids); $bk('employees', "id IN ($in)"); $db->exec("UPDATE employees SET has_13th_month = 1, m13_include_extra = 1, m13_include_aide = 1 WHERE id IN ($in)"); }
        $audit('month13', 0, ['cut' => $cut, 'n' => count($ids), 'ids' => $ids]); $log['S8_month13'] = 'n=' . count($ids) . " (hire<=$cut)";
    } catch (Throwable $e) { $log['S8_month13'] = 'ERR ' . $e->getMessage(); }

    // ── S9 سنة بلا ضمان بعد سنوات مضمونة ⇒ تُشال صفوفها غير المدفوعة (قاعدته العامّة؛ اليوم: جومانا بصيبص 283 سنة 2026-2027)
    try {
        $rows = $db->query("SELECT e.id, ms.school_year FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id
            WHERE e.is_deleted = 0 AND COALESCE(e.cnss_subject,1) = 0
              AND EXISTS (SELECT 1 FROM monthly_salaries m2 WHERE m2.employee_id = e.id AND m2.cnss_amount_lbp > 0 AND m2.school_year < ms.school_year)
              AND NOT EXISTS (SELECT 1 FROM monthly_salaries m3 WHERE m3.employee_id = e.id AND m3.school_year = ms.school_year AND m3.cnss_amount_lbp > 0)
            GROUP BY e.id, ms.school_year")->fetchAll(PDO::FETCH_ASSOC);
        $done = [];
        foreach ($rows as $r) {
            $id = (int)$r['id']; $sy = $r['school_year'];
            $bk('monthly_salaries', 'employee_id = ? AND school_year = ?', [$id, $sy]);
            $del = $db->prepare("DELETE FROM monthly_salaries WHERE employee_id = ? AND school_year = ? AND COALESCE(is_paid,0) = 0"); $del->execute([$id, $sy]);
            $done["$id/$sy"] = $del->rowCount();
        }
        $audit('uninsured_year_rows', 0, $done); $log['S9_uninsured'] = json_encode($done);
    } catch (Throwable $e) { $log['S9_uninsured'] = 'ERR ' . $e->getMessage(); }

    // ── S10 عيف عيد 1815: أشهر 2025-2026 المدفوعة أُعيد حسابها خطأً بـ21/9 (سلفة 0، صافي 970,000) ⇒ تُرجَع من نسخة 20/8 (_ms_bk_restore20260829) + سطر سلفته 2025-2026 يعود فعّالاً
    try {
        $has = (int)$db->query("SELECT COUNT(*) FROM _ms_bk_restore20260829 WHERE employee_id = 1815 AND school_year = '2025-2026'")->fetchColumn();
        $broken = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id = 1815 AND school_year = '2025-2026' AND prime_fixe_lbp = 0 AND net_salary_lbp < 5000000")->fetchColumn();
        if ($has >= 9 && $broken > 0) {   // النسخة فيها الأشهر المدفوعة التسعة (ت1→حزيران)؛ تمّوز→أيلول غير مدفوعة تُعاد بالمحرّك من ملفه
            $bk('monthly_salaries', "employee_id = 1815 AND school_year = '2025-2026'"); $bk('employee_bonuses', 'employee_id = 1815');
            $cols = array_column($db->query("SHOW COLUMNS FROM monthly_salaries")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $bcols = array_column($db->query("SHOW COLUMNS FROM _ms_bk_restore20260829")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $common = array_values(array_diff(array_intersect($cols, $bcols), ['id']));
            $cl = '`' . implode('`,`', $common) . '`';
            $db->exec("DELETE ms FROM monthly_salaries ms JOIN _ms_bk_restore20260829 b ON b.employee_id = ms.employee_id AND b.year = ms.year AND b.month = ms.month WHERE ms.employee_id = 1815 AND ms.school_year = '2025-2026'");
            $n = $db->exec("INSERT INTO monthly_salaries ($cl) SELECT $cl FROM _ms_bk_restore20260829 WHERE employee_id = 1815 AND school_year = '2025-2026'");
            $db->exec("UPDATE employee_bonuses SET is_active = 1 WHERE employee_id = 1815 AND bonus_type = 'prime_fixe' AND school_year = '2025-2026' AND value_type = 'amount' AND amount = 36000000");
            $db->exec("UPDATE monthly_salaries SET is_paid = 1 WHERE employee_id = 1815 AND school_year = '2025-2026' AND (year*100+month) BETWEEN 202510 AND 202606");   // كانت مدفوعة قبل الكسر
            // تمّوز→أيلول 2026 غير مدفوعة: كان أوّل سنة (دخول 1/10/2025) ⇒ قاعدته «أوّل سنة 1/10→30/6» ⇒ الصفوف الثلاثة المكسورة تُشال لا تُعاد
            $re = (int)$db->exec("DELETE FROM monthly_salaries WHERE employee_id = 1815 AND school_year = '2025-2026' AND COALESCE(is_paid,0) = 0");
            $audit('aif_restore', 1815, ['rows' => $n, 'recalc_unpaid' => $re, 'from' => '_ms_bk_restore20260829']); $log['S10_aif'] = "restored rows=$n recalc=$re";
        } else $log['S10_aif'] = "skip (backup=$has broken=$broken)";
    } catch (Throwable $e) { $log['S10_aif'] = 'ERR ' . $e->getMessage(); }

    // ── S11 نهاية شمس الدين 259: سطر ترقية 1/10/2026 (45.0 ⇒ 45.5) ناقص بسجلّ الدرجات — الدرجة نفسها لا تتغيّر
    try {
        $g = $db->query("SELECT current_grade FROM employees WHERE id = 259 AND is_deleted = 0")->fetchColumn();
        $last = $db->query("SELECT grade_after FROM employee_grade_history WHERE employee_id = 259 AND (counted = 1 OR reason = 'titularization') ORDER BY change_date DESC, id DESC LIMIT 1")->fetchColumn();
        if ($g !== false && (float)$g === 45.5 && (float)$last === 45.0) {
            $db->prepare("INSERT INTO employee_grade_history (employee_id, grade_before, grade_after, delta, counted, change_date, reason, notes, created_at) VALUES (259, 45.0, 45.5, 0.5, 1, '2026-10-01', 'biennial_promotion', 'ترقية 1/10/2026 — استُكمل السجلّ 2026-11-02', NOW())")->execute();
            $audit('grade_history', 259, ['added' => '2026-10-01 45.0→45.5']); $log['S11_nahia'] = 'history row added';
        } else $log['S11_nahia'] = "skip (grade=$g last=$last)";
    } catch (Throwable $e) { $log['S11_nahia'] = 'ERR ' . $e->getMessage(); }

    setSetting('heal_cleanup_20261102', json_encode(['at' => date('Y-m-d H:i:s'), 'log' => $log], JSON_UNESCAPED_UNICODE));
    if (function_exists('msaLookupFlush')) msaLookupFlush();
}

/**
 * 🧹 الجزء الثاني (2026-11-02 بعد التشغيل الأوّل والفحص): تصحيحات ظهرت بالمقارنة صفّاً صفّاً مع نسخة ما قبل التنظيف.
 *   B1 شهر 13: الخطوة S8 الأولى علّمت 157 متعاقداً منهم 127 بلا أي راتب هذه السنة ⇒ يُرجَع علمهم كما كان (من النسخة _bk_cleanup1102_employees حيث وُجدت)
 *      ويبقى لمن له راتب (القاعدة: متعاقد > سنتين وله راتب). أونلاين S8 تعمل بالقاعدة الضيّقة أصلاً.
 *   B2 النجاة — علاء شمعون 54 وكلود كامل 57: نقل مكرّر بسنة 2025-2026 (تكملة نقل 9,000,000 + نقل يومي 450,000×20 = 18,000,000) بينما كشفه القديم 9,000,000
 *      (قرار ب: الأشهر المدفوعة = كشفه القديم) ⇒ سطر النقل اليومي 2025-2026 يُطفأ، وأشهر 2025-2026 ذات النقل 18,000,000 تُرجَع إلى 9,000,000 (المستحق −9,000,000).
 *   B3 باميلا نضّور 1802: ملاك من 1/10/2026 (قرار معتمَد 25/9) — صمّام ضدّ أي شفاء قديم يرجّعها متعاقدة.
 *   B4 الأربعة 12 شهراً (S7): علاواتهم الفعّالة 2026-2027 المحدودة «ت1 ← حزيران» تمتدّ إلى أيلول ثمّ يُعاد حساب السنة (تمّوز→أيلول كانت بالأساس وحده).
 */
function healCleanup20261102b(): void {
    if (getSetting('heal_cleanup_20261102b', '') !== '') return;
    $db = getDB();
    require_once __DIR__ . '/payroll_calculator.php';
    $log = [];
    $isLocal = php_sapi_name() === 'cli' || in_array(strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]), ['localhost', '127.0.0.1'], true);
    $bk = function (string $table, string $where, array $p = []) use ($db, $isLocal): int {
        if (!$isLocal) return 0;
        try { $db->exec("CREATE TABLE IF NOT EXISTS `_bk_cleanup1102_$table` LIKE `$table`"); $st = $db->prepare("INSERT IGNORE INTO `_bk_cleanup1102_$table` SELECT * FROM `$table` WHERE $where"); $st->execute($p); return $st->rowCount(); }
        catch (Throwable $e) { return -1; }
    };
    $audit = function (string $what, int $rid, array $d) { try { logAudit('heal_cleanup_1102_' . $what, 'employees', $rid, null, $d); } catch (Throwable $e) {} };

    // ── B1
    try {
        $cur = currentSchoolYear(); $y1 = (int)substr($cur, 0, 4); $cut = ($y1 - 2) . '-10-01';
        $hasBk = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_bk_cleanup1102_employees'")->fetchColumn();
        $reverted = [];
        if ($hasBk) {
            $st = $db->prepare("SELECT e.id FROM employees e JOIN `_bk_cleanup1102_employees` b ON b.id = e.id AND COALESCE(b.has_13th_month,0) = 0
                WHERE e.is_deleted = 0 AND COALESCE(e.has_13th_month,0) = 1 AND e.employee_type = 'enseignant_contractuel'
                  AND NOT EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND (ms.net_salary_lbp > 0 OR ms.base_plus_echelon_lbp > 0 OR ms.prime_fixe_lbp > 0))");
            $st->execute([$cur]); $reverted = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($reverted) {
                $in = implode(',', $reverted);
                $db->exec("UPDATE employees e JOIN `_bk_cleanup1102_employees` b ON b.id = e.id SET e.has_13th_month = b.has_13th_month, e.m13_include_extra = b.m13_include_extra, e.m13_include_aide = b.m13_include_aide WHERE e.id IN ($in)");
            }
        }
        $kept = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND has_13th_month = 1 AND employee_type = 'enseignant_contractuel' AND hire_date <= '$cut'")->fetchColumn();
        $audit('month13_narrow', 0, ['cut' => $cut, 'reverted' => $reverted, 'kept' => $kept]); $log['B1_month13'] = 'reverted=' . count($reverted) . " kept=$kept";
    } catch (Throwable $e) { $log['B1_month13'] = 'ERR ' . $e->getMessage(); }

    // ── B2
    try {
        $done = [];
        foreach ([54, 57] as $id) {
            $e = $db->query("SELECT id, school_id FROM employees WHERE id = $id AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
            if (!$e || (int)$e['school_id'] !== 3) { $done[$id] = 'absent'; continue; }
            $bk('employee_bonuses', "employee_id = $id AND school_year = '2025-2026'"); $bk('monthly_salaries', "employee_id = $id AND school_year = '2025-2026'");
            $nb = (int)$db->exec("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = $id AND school_year = '2025-2026' AND bonus_type = 'transport_daily' AND is_active = 1");
            $nr = (int)$db->exec("UPDATE monthly_salaries SET transport_lbp = 9000000, transport_complement_lbp = 9000000, total_due_lbp = net_salary_lbp + 9000000,
                total_due_usd = IF(exchange_rate > 0, ROUND((net_salary_lbp + 9000000) / exchange_rate, 2), total_due_usd)
                WHERE employee_id = $id AND school_year = '2025-2026' AND transport_lbp = 18000000 AND transport_complement_lbp = 18000000");
            $done[$id] = "bonus_off=$nb rows=$nr";
        }
        $audit('najat_transport_dup', 0, $done); $log['B2_najat_transport'] = json_encode($done);
    } catch (Throwable $e) { $log['B2_najat_transport'] = 'ERR ' . $e->getMessage(); }

    // ── B3
    try {
        $p = $db->query("SELECT employee_type, titularization_date, cadre_from_sy FROM employees WHERE id = 1802 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if ($p && $p['employee_type'] !== 'enseignant_titulaire' && (string)$p['titularization_date'] === '2026-10-01' && (string)$p['cadre_from_sy'] === '2026-2027') {
            $bk('employees', 'id = 1802'); $db->exec("UPDATE employees SET employee_type = 'enseignant_titulaire' WHERE id = 1802");
            $audit('pamela_cadre_guard', 1802, ['from' => $p['employee_type']]); $log['B3_pamela'] = 'restored titulaire';
        } else $log['B3_pamela'] = $p ? 'ok (' . $p['employee_type'] . ')' : 'absent';
    } catch (Throwable $e) { $log['B3_pamela'] = 'ERR ' . $e->getMessage(); }

    // ── B4
    try {
        $done = [];
        foreach ([1817, 1822, 1820, 1646] as $id) {
            $r = $db->query("SELECT payment_months_per_year pm FROM employees WHERE id = $id AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int)$r['pm'] !== 12) continue;
            $bk('employee_bonuses', "employee_id = $id AND school_year = '2026-2027'");
            $n = (int)$db->exec("UPDATE employee_bonuses SET end_month = 9 WHERE employee_id = $id AND school_year = '2026-2027' AND is_active = 1 AND start_month = 10 AND end_month = 6");
            if ($n > 0) { $bk('monthly_salaries', "employee_id = $id AND school_year = '2026-2027'"); $done[$id] = ['lines' => $n, 'recalc' => recalcEmployeeYear($id, '2026-2027')]; }
        }
        $audit('months12_bonus_range', 0, $done); $log['B4_bonus_range'] = json_encode($done);
    } catch (Throwable $e) { $log['B4_bonus_range'] = 'ERR ' . $e->getMessage(); }

    // ── B5 جانيت فرح 37 (تاركة 30/9/1996 بقرار S4): صفوفها المولَّدة غير المدفوعة بعد سنة تركها تُشال فوراً (قاعدة التارك §١٠ — لا انتظار شفاء الـ3 ساعات)
    try {
        $j = $db->query("SELECT left_date_all FROM employees WHERE id = 37 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if ($j && !empty($j['left_date_all'])) {
            $ly = (int)substr((string)$j['left_date_all'], 0, 4); $lm = (int)substr((string)$j['left_date_all'], 5, 2); $dep = $lm >= 10 ? $ly : $ly - 1;
            $bk('monthly_salaries', "employee_id = 37 AND COALESCE(is_paid,0) = 0");
            $n = (int)$db->exec("DELETE FROM monthly_salaries WHERE employee_id = 37 AND COALESCE(is_paid,0) = 0 AND (CASE WHEN month >= 10 THEN year ELSE year - 1 END) > $dep");
            $audit('jeannette_phantoms', 37, ['deleted' => $n, 'dep' => $dep]); $log['B5_jeannette_rows'] = "deleted=$n";
        } else $log['B5_jeannette_rows'] = 'skip';
    } catch (Throwable $e) { $log['B5_jeannette_rows'] = 'ERR ' . $e->getMessage(); }

    setSetting('heal_cleanup_20261102b', json_encode(['at' => date('Y-m-d H:i:s'), 'log' => $log], JSON_UNESCAPED_UNICODE));
    if (function_exists('msaLookupFlush')) msaLookupFlush();
}

/**
 * 🧹 الجزء الثالث (11-02 مساءً): أونلاين لا جدول `_bk_cleanup1102_employees` فلم تعمل B1 — جومانا بصيبص 283 عُلِّمت بـS8 ثمّ شالت S9 صفوفها 2026-2027
 *   فبقي علمها بلا راتب. القاعدة بلا اعتماد على نسخة: مَن علّمه شفاء التنظيف (سجلّ التدقيق heal_cleanup_1102_month13) وليس له راتب هذه السنة ⇒ العلم يرجع 0.
 */
function healCleanup20261102c(): void {
    if (getSetting('heal_cleanup_20261102c', '') !== '') return;
    $db = getDB(); $log = [];
    try {
        $cur = currentSchoolYear(); $ids = [];
        foreach ($db->query("SELECT new_value FROM audit_log WHERE action = 'heal_cleanup_1102_month13' ORDER BY id") as $r) {
            $d = json_decode((string)$r['new_value'], true); foreach ((array)($d['ids'] ?? []) as $i) $ids[(int)$i] = true;
        }
        $reverted = [];
        if ($ids) {
            $in = implode(',', array_keys($ids));
            $st = $db->prepare("SELECT e.id FROM employees e WHERE e.id IN ($in) AND e.is_deleted = 0 AND COALESCE(e.has_13th_month,0) = 1 AND e.employee_type = 'enseignant_contractuel'
                AND NOT EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND (ms.net_salary_lbp > 0 OR ms.base_plus_echelon_lbp > 0 OR ms.prime_fixe_lbp > 0))");
            $st->execute([$cur]); $reverted = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($reverted) $db->exec("UPDATE employees SET has_13th_month = 0, m13_include_extra = 0, m13_include_aide = 0 WHERE id IN (" . implode(',', $reverted) . ")");
        }
        try { logAudit('heal_cleanup_1102_month13_revert', 'employees', 0, null, ['reverted' => $reverted]); } catch (Throwable $e) {}
        $log['C1_month13_nosalary'] = 'reverted=' . json_encode($reverted);
    } catch (Throwable $e) { $log['C1_month13_nosalary'] = 'ERR ' . $e->getMessage(); }
    setSetting('heal_cleanup_20261102c', json_encode(['at' => date('Y-m-d H:i:s'), 'log' => $log], JSON_UNESCAPED_UNICODE));
    if (function_exists('msaLookupFlush')) msaLookupFlush();
}
