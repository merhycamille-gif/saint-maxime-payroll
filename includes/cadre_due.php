<?php
/**
 * 🎓 الترسيم الحكمي بالملاك بعد سنتين تعاقد / Titularisation d'office après deux ans (أمره 2026-09-13):
 * «إذا صرلو الأستاذ سنتين بالمدرسة لازم تالت سنة يصير حكماً بالملاك تلقائياً وطبّق عليه كل الدرجات حسب القوانين
 *  وطبّق عليه كمان النسب المئوية المعطاة للملاك بنفس المدرسة — وبس افتح المدرسة على سنة جديدة لازم يطلعلي مساج
 *  يعطيني أسماء الأساتذة اللازم يكونوا بالملاك ويكون عندي خيار وافق أو ما وافق، وبس وافق طبّق قانون الملاك على ملفه».
 *
 * المبدأ (لا يتغيّر شي بلا موافقته — «اوعى تخربط اللي عاملينو»):
 *  - المرشَّح = أستاذ متعاقد فاعل (لم يترك قبل 1/10 من السنة الجديدة) دخل المدرسة قبل 1 تشرين الثاني من سنة (Y1−2)
 *    أي أكمل سنتين دراسيتين كاملتين بالمدرسة نفسها ⇒ السنة الثالثة Y1-Y2 بالملاك.
 *  - المساج: عند فتح السنة (صفحة مراجعة قبل الفتح) + بطاقة بلوحة القيادة وبصفحة فتح السنة للسنة المفتوحة أصلاً.
 *  - «وافق» ⇒ titularizeContractTeacher: ملاك من 1/10/Y1 + السلسلة بدل الراتب المتفق عليه + محسومات الملاك كرفاقه بالمدرسة
 *    + سجلّ الدرجات بالمحرّك المعتمد (buildLegalGradeHistory: درجة الدخول + الفورية + 4+4+2) + نسبة الملاك بالمدرسة
 *    + إعادة حساب السنة الجديدة فقط. سنواته السابقة (متعاقد) لا تُلمَس أبداً (صمام cadre_from_sy بالمحرّك).
 *  - «لا» ⇒ يبقى متعاقداً هذه السنة (قرار مسجَّل بجدول compliance_decisions، rule_key = cadre_due، يمكن إعادة فتحه).
 */

require_once __DIR__ . '/compliance.php';

/** تركيب ذاتي: عمود «ملاك من سنة» (السنة الدراسية التي رُسِّم منها من متعاقد) — خارج أي معاملة. */
function cadreDueEnsureColumns(?PDO $db = null): void {
    static $done = false;
    if ($done) return;
    $db = $db ?: getDB();
    if ($db->inTransaction()) return; // DDL داخل معاملة = commit ضمني — نعيدها بالفتح التالي
    $done = true;
    try {
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'cadre_from_sy'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN cadre_from_sy VARCHAR(9) NULL DEFAULT NULL COMMENT 'رُسِّم بالملاك من متعاقد ابتداءً من هذه السنة الدراسية'");
        }
    } catch (Throwable $e) { $done = false; }
}

/** آخر تاريخ دخول للمدرسة يجعل الأستاذ مستحقّاً للملاك بالسنة $y1-$y2 (أكمل سنتين دراسيتين كاملتين). */
function cadreDueHireCutoff(int $y1): string {
    return sprintf('%04d-10-31', $y1 - 2);
}

function cadreDueEmpName(array $e): string {
    return trim((($e['first_name_ar'] ?: ($e['first_name_fr'] ?? '')) . ' ' . (($e['father_name_ar'] ?? '') ?: '') . ' ' . ($e['last_name_ar'] ?: ($e['last_name_fr'] ?? ''))));
}

/** القرارات المسجّلة لهذه القاعدة بسنة: employee_id → صفّ القرار */
function cadreDueDecisions(PDO $db, string $sy): array {
    complianceEnsureTable($db);
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM compliance_decisions WHERE rule_key = 'cadre_due' AND school_year = ? AND employee_id IS NOT NULL");
        $st->execute([$sy]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['employee_id']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

function cadreDueRecordDecision(PDO $db, array $c, string $sy, string $decision, ?string $result, string $who): void {
    complianceEnsureTable($db);
    try {
        $db->prepare("INSERT INTO compliance_decisions (item_key, rule_key, employee_id, school_id, school_year, emp_name, violation, fix, decision, result, decided_by, decided_at, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                      ON DUPLICATE KEY UPDATE decision = VALUES(decision), result = VALUES(result), decided_by = VALUES(decided_by), decided_at = NOW(), violation = VALUES(violation), fix = VALUES(fix)")
           ->execute(['cadre_due|' . (int)$c['id'] . '|' . $sy, 'cadre_due', (int)$c['id'], (int)$c['school_id'], $sy, $c['name'],
                      'متعاقد بالمدرسة منذ ' . $c['hire_date'] . ' (' . $c['years'] . ' سنة) — يصير بالملاك حكماً بسنة ' . $sy,
                      'ترسيمه بالملاك من ' . $c['tit'] . ' + الدرجات بالقانون + نسبة الملاك بالمدرسة', $decision, $result, $who]);
    } catch (Throwable $e) {}
}

/**
 * نسبة الإضافي المعطاة لأساتذة الملاك بهذه المدرسة بهذه السنة (الأكثر شيوعاً بين بنود النسبة الفاعلة) —
 * ['pct'=>65, 'start_month'=>10, 'end_month'=>9, 'n'=>121, 'sy'=>...] أو null إن كانت المدرسة بلا نسبة (مبالغ).
 * إن لم تُنسخ نسب السنة الجديدة بعد، تُؤخذ من السنة السابقة (نفس عادة المدرسة).
 */
function schoolCadrePercent(PDO $db, int $schoolId, string $sy): ?array {
    static $cache = [];
    $k = $schoolId . '|' . $sy;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $try = [$sy];
    if (preg_match('/^(\d{4})-(\d{4})$/', $sy, $m)) $try[] = ((int)$m[1] - 1) . '-' . $m[1];
    $res = null;
    foreach ($try as $ySy) {
        $st = $db->prepare("SELECT b.amount pct, COALESCE(b.start_month,0) sm, COALESCE(b.end_month,0) em, COUNT(DISTINCT b.employee_id) n
                            FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
                            WHERE e.school_id = ? AND e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire'
                              AND b.bonus_type = 'prime_fixe' AND b.value_type = 'percent' AND b.is_active = 1 AND b.school_year = ? AND b.amount > 0
                            GROUP BY b.amount, sm, em ORDER BY n DESC, b.amount DESC LIMIT 1");
        $st->execute([$schoolId, $ySy]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) { $res = ['pct' => (float)$r['pct'], 'start_month' => (int)$r['sm'] ?: null, 'end_month' => (int)$r['em'] ?: null, 'n' => (int)$r['n'], 'sy' => $ySy]; break; }
    }
    return $cache[$k] = $res;
}

/** خطوط النقل الفاعلة (نقل يومي/تعويض نقل) لأستاذ بسنة — قائمة موحَّدة الشكل للمقارنة والنسخ */
function cadreDueTransportLines(PDO $db, int $empId, string $sy): array {
    $st = $db->prepare("SELECT bonus_type, amount, value_type, currency, COALESCE(start_month,0) sm, COALESCE(end_month,0) em FROM employee_bonuses
                        WHERE employee_id = ? AND school_year = ? AND is_active = 1 AND amount > 0 AND bonus_type IN ('transport_daily','transport_complement')
                        ORDER BY bonus_type, amount, sm, em");
    $st->execute([$empId, $sy]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type' => $r['bonus_type'], 'amount' => round((float)$r['amount'], 2), 'vt' => $r['value_type'], 'cur' => $r['currency'], 'sm' => (int)$r['sm'], 'em' => (int)$r['em']];
    return $out;
}

/**
 * 🚌 قالب تعويض النقل لملاك المدرسة (أمره 2026-09-13 «اللي بيصيروا بالملاك لازم طبّق عليهن تعويض النقل على 5 أيام — كل القانون اللي بيطبّق على الملاك»):
 * التوقيع الأكثر شيوعاً بين أساتذة الملاك الفاعلين بالمدرسة = مجموعة خطوط النقل (النوع/المبلغ/العملة/النافذة) + حقول النقل بملفهم
 * (اليومي/أيام الأسبوع/الأسابيع). مثلاً عبرا: تعويض نقل 9,000,000 ل.ل. شهرياً (= 450,000 × 5 أيام × 4 أسابيع). إن لم تُنسخ خطوط السنة بعد
 * تُؤخذ من السنة السابقة. يرجّع ['lines'=>[...], 'fields'=>[...], 'n'=>عدد الملاك بهذا التوقيع, 'total'=>كل الملاك, 'sy'=>السنة المصدر] أو null بلا ملاك.
 */
function schoolCadreTransportTemplate(PDO $db, int $schoolId, string $sy): ?array {
    static $cache = [];
    $k = $schoolId . '|' . $sy;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $y1 = (int)substr($sy, 0, 4);
    $emps = $db->query("SELECT id, transport_daily_amount, transport_daily_currency, transport_days_per_week, transport_weeks FROM employees
                        WHERE school_id = " . (int)$schoolId . " AND is_deleted = 0 AND status = 'actif' AND employee_type = 'enseignant_titulaire'
                          AND LEAST(COALESCE(NULLIF(left_date_cnss,'0000-00-00'),'9999-12-31'), COALESCE(NULLIF(left_date_finance,'0000-00-00'),'9999-12-31'), COALESCE(NULLIF(left_date_eoc,'0000-00-00'),'9999-12-31')) >= '" . $y1 . "-10-01'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$emps) return $cache[$k] = null;
    $try = [$sy, ($y1 - 1) . '-' . $y1];
    $res = null;
    foreach ($try as $ySy) {
        $sig = []; $byKey = []; $withAny = 0;
        foreach ($emps as $e) {
            $lines = cadreDueTransportLines($db, (int)$e['id'], $ySy);
            if ($lines) $withAny++;
            $key = json_encode($lines);
            $sig[$key] = ($sig[$key] ?? 0) + 1;
            $byKey[$key][] = $e;
        }
        if ($withAny === 0 && $ySy === $sy) continue; // خطوط السنة الجديدة لم تُنسخ بعد → السنة السابقة
        arsort($sig);
        $top = array_key_first($sig);
        $members = $byKey[$top];
        $fields = [];
        foreach (['transport_daily_amount', 'transport_daily_currency', 'transport_days_per_week', 'transport_weeks'] as $f) {
            $fr = [];
            foreach ($members as $m) { $v = (string)$m[$f]; $fr[$v] = ($fr[$v] ?? 0) + 1; }
            arsort($fr);
            $fields[$f] = array_key_first($fr);
        }
        $res = ['lines' => json_decode($top, true) ?: [], 'fields' => $fields, 'n' => (int)$sig[$top], 'total' => count($emps), 'sy' => $ySy];
        break;
    }
    return $cache[$k] = $res;
}

/** نصّ موجز لقالب النقل */
function cadreDueTransportText(?array $t): string {
    if (!$t) return 'بلا ملاك بالمدرسة للمقارنة';
    if (!$t['lines'] && (float)$t['fields']['transport_daily_amount'] <= 0) return 'ملاك المدرسة بلا تعويض نقل';
    $parts = [];
    foreach ($t['lines'] as $l) {
        $amt = ($l['cur'] === 'USD' ? '$ ' : '') . number_format((float)$l['amount'], $l['cur'] === 'USD' ? 2 : 0) . ($l['cur'] === 'USD' ? '' : ' ل.ل.');
        $parts[] = ($l['type'] === 'transport_daily' ? 'نقل يومي ' . $amt : 'تعويض نقل ' . $amt . ' شهرياً') . ($l['sm'] && $l['em'] ? ' (' . monthName($l['sm'], 'ar') . ' ← ' . monthName($l['em'], 'ar') . ')' : '');
    }
    if ((float)$t['fields']['transport_daily_amount'] > 0) $parts[] = 'يومي بالملف ' . number_format((float)$t['fields']['transport_daily_amount'], 0) . ' × ' . (int)$t['fields']['transport_days_per_week'] . ' أيام';
    return implode(' + ', $parts) . ' — كـ' . (int)$t['n'] . ' من ' . (int)$t['total'] . ' ملاك المدرسة';
}

/**
 * يطبّق قالب نقل ملاك المدرسة على أستاذ لسنة (يطفئ خطوط نقله ويضع خطوط القالب + حقول النقل بملفه). يرجّع نصّاً بما تغيّر أو null إن كان مطابقاً أصلاً.
 */
function cadreDueApplyTransport(PDO $db, int $empId, int $schoolId, string $sy): ?string {
    $t = schoolCadreTransportTemplate($db, $schoolId, $sy);
    if (!$t) return null;
    $cur = cadreDueTransportLines($db, $empId, $sy);
    $e = $db->query("SELECT transport_daily_amount, transport_daily_currency, transport_days_per_week, transport_weeks FROM employees WHERE id = " . (int)$empId)->fetch(PDO::FETCH_ASSOC);
    $sameLines = json_encode($cur) === json_encode($t['lines']);
    $sameFields = $e && (float)$e['transport_daily_amount'] == (float)$t['fields']['transport_daily_amount'] && (int)$e['transport_days_per_week'] == (int)$t['fields']['transport_days_per_week'] && (float)$e['transport_weeks'] == (float)$t['fields']['transport_weeks'];
    if ($sameLines && $sameFields) return null;
    if (!$sameLines) {
        $db->prepare("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = ? AND school_year = ? AND bonus_type IN ('transport_daily','transport_complement')")->execute([$empId, $sy]);
        $ins = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?,?,?,?,?,?,?,?,?,1)");
        $pn = 0;
        foreach ($t['lines'] as $l) { $pn++; $ins->execute([$empId, $l['type'], $pn, $sy, $l['amount'], $l['vt'], $l['cur'], $l['sm'] ?: null, $l['em'] ?: null]); }
    }
    $db->prepare("UPDATE employees SET transport_daily_amount = ?, transport_daily_currency = ?, transport_days_per_week = ?, transport_weeks = ? WHERE id = ?")
       ->execute([(float)$t['fields']['transport_daily_amount'], $t['fields']['transport_daily_currency'] ?: 'LBP', (int)$t['fields']['transport_days_per_week'], (float)$t['fields']['transport_weeks'] ?: 4, $empId]);
    return 'نقل كملاك المدرسة: ' . cadreDueTransportText($t);
}

/** نسبة الإضافي كملاك المدرسة (بند واحد بدل أي إضافي كان له بهذه السنة). يرجّع النسبة أو null إن كانت المدرسة بلا نسبة أو كان عليها أصلاً. */
function cadreDueApplyPercent(PDO $db, int $empId, int $schoolId, string $sy): ?float {
    $pct = schoolCadrePercent($db, $schoolId, $sy);
    if (!$pct) return null;
    $has = $db->prepare("SELECT amount FROM employee_bonuses WHERE employee_id = ? AND school_year = ? AND bonus_type = 'prime_fixe' AND value_type = 'percent' AND is_active = 1");
    $has->execute([$empId, $sy]);
    $rows = $has->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) === 1 && abs((float)$rows[0] - (float)$pct['pct']) < 0.01) return null; // عليها أصلاً
    $db->prepare("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = ? AND school_year = ? AND bonus_type = 'prime_fixe'")->execute([$empId, $sy]);
    $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                  VALUES (?, 'prime_fixe', 1, ?, ?, 'percent', 'LBP', ?, ?, 1)")
       ->execute([$empId, $sy, $pct['pct'], $pct['start_month'], $pct['end_month']]);
    return (float)$pct['pct'];
}

/**
 * قالب محسومات الملاك بالمدرسة (القيمة الأكثر شيوعاً بين أساتذة الملاك الفاعلين) — حتى يُعامَل المرسَّم كرفاقه تماماً.
 * بلا ملاك بالمدرسة: افتراضيات الملاك (صندوق + ضمان + ضريبة على الأساس والدرجة والإضافي، 12 شهراً).
 */
function cadreDueTemplate(PDO $db, int $schoolId): array {
    $flags = ['eoc_subject' => 1, 'eoc_includes_echelon' => 1, 'eoc_includes_extra' => 1, 'eoc_includes_prime_aide' => 0,
              'cnss_subject' => 1, 'cnss_includes_echelon' => 1, 'cnss_includes_extra' => 1, 'cnss_includes_prime_aide' => 1,
              'tax_subject' => 1, 'tax_includes_echelon' => 1, 'tax_includes_extra' => 1, 'tax_includes_prime_aide' => 1,
              'payment_months_per_year' => 12];
    try {
        $rows = $db->query("SELECT " . implode(',', array_keys($flags)) . " FROM employees WHERE school_id = " . (int)$schoolId . " AND is_deleted = 0 AND status = 'actif' AND employee_type = 'enseignant_titulaire'")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach (array_keys($flags) as $f) {
                $freq = [];
                foreach ($rows as $r) { $v = (string)(int)$r[$f]; $freq[$v] = ($freq[$v] ?? 0) + 1; }
                arsort($freq);
                $flags[$f] = (int)array_key_first($freq);
            }
            if ($flags['payment_months_per_year'] !== 10) $flags['payment_months_per_year'] = 12;
        }
    } catch (Throwable $e) {}
    return $flags;
}

/**
 * المرشَّحون للملاك بسنة $sy: متعاقدون فاعلون أكملوا سنتين دراسيتين بالمدرسة نفسها.
 * $schoolIds = مدارس محدّدة (فتح السنة) أو null؛ $scoped = تقييد بنطاق المستخدم (لوحة القيادة).
 * $includeDecided = مع من قرّر فيهم (وافق/رفض) — وإلا المعلّقون فقط.
 * كل صفّ: id, name, school_id, school_name, hire_date, years, diploma, diploma_label, grade_start, immediate,
 *          pay (راتبه الآن كمتعاقد), tit (تاريخ الملاك المقترح), pct (نسبة ملاك المدرسة أو null), can (يمكن ترسيمه), why, decision, has_grades
 */
function cadreDueCandidates(PDO $db, string $sy, ?array $schoolIds = null, bool $includeDecided = false, bool $scoped = true): array {
    if (!preg_match('/^(\d{4})-(\d{4})$/', $sy, $m)) return [];
    $y1 = (int)$m[1];
    $cut = cadreDueHireCutoff($y1);
    $yearStart = sprintf('%04d-10-01', $y1);
    $syA = ($y1 - 2) . '-' . ($y1 - 1); $syB = ($y1 - 1) . '-' . $y1; // السنتان الدراسيتان المكتملتان قبل السنة الجديدة
    // «أكمل سنتين بالمدرسة نفسها» = تقاضى راتباً فعلياً بالسنتين السابقتين **بهذه المدرسة** (لا سجلّات قديمة بلا رواتب ولا منقول من مدرسة أخرى)
    $sql = "SELECT e.*, s.name_ar school_name_ar, s.name_fr school_name_fr FROM employees e JOIN schools s ON s.id = e.school_id
            WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_contractuel'
              AND e.hire_date IS NOT NULL AND e.hire_date <> '0000-00-00' AND e.hire_date <= ?
              AND LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),
                        COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),
                        COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31')) >= ?
              AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = ? AND school_id = e.school_id AND (net_salary_lbp > 0 OR base_plus_echelon_lbp > 0))
              AND e.id IN (SELECT employee_id FROM monthly_salaries WHERE school_year = ? AND school_id = e.school_id AND (net_salary_lbp > 0 OR base_plus_echelon_lbp > 0))";
    $p = [$cut, $yearStart, $syA, $syB];
    if (is_array($schoolIds)) {
        $ids = array_values(array_filter(array_map('intval', $schoolIds)));
        if (!$ids) return [];
        $sql .= " AND e.school_id IN (" . implode(',', $ids) . ")";
    }
    if ($scoped) $sql .= schoolScopeSql('e.school_id');
    $sql .= " ORDER BY s.name_ar, e.hire_date DESC, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr)";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $dec = cadreDueDecisions($db, $sy);
    $dips = [];
    foreach ($db->query("SELECT diploma_code, starting_grade, gets_immediate_grade FROM diploma_starting_grades") as $d) $dips[$d['diploma_code']] = $d;
    $gh = $db->prepare("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id = ?");
    $last = $db->prepare("SELECT base_plus_echelon_lbp FROM monthly_salaries WHERE employee_id = ? AND base_plus_echelon_lbp > 0 ORDER BY year DESC, month DESC LIMIT 1");
    $out = [];
    foreach ($rows as $e) {
        $id = (int)$e['id'];
        $d = $dec[$id] ?? null;
        if (!$includeDecided && $d && in_array($d['decision'], ['approved', 'rejected'], true)) continue;
        $dip = $dips[$e['diploma']] ?? null;
        $years = 0;
        try { $years = (int)date_diff(date_create($e['hire_date']), date_create($yearStart))->y; } catch (Throwable $t) {}
        $pay = '';
        if ($e['salary_input_mode'] === 'direct_usd' && (float)$e['base_salary_usd'] > 0) $pay = '$ ' . number_format((float)$e['base_salary_usd'], 0);
        elseif ((float)$e['contract_salary_lbp'] > 0) $pay = number_format((float)$e['contract_salary_lbp'], 0) . ' ل.ل.';
        else { $last->execute([$id]); $v = (float)$last->fetchColumn(); $pay = $v > 0 ? number_format($v, 0) . ' ل.ل. (آخر شهر مخزّن)' : '—'; }
        $gh->execute([$id]);
        $can = (bool)$dip; $why = '';
        if (!$dip) $why = 'بلا شهادة بملفه — عبّي الشهادة أوّلاً حتى تُحسب درجة الدخول';
        $out[] = [
            'id' => $id, 'name' => cadreDueEmpName($e), 'school_id' => (int)$e['school_id'],
            'school_name' => $e['school_name_ar'] ?: $e['school_name_fr'],
            'hire_date' => $e['hire_date'], 'years' => $years,
            'diploma' => (string)$e['diploma'], 'diploma_label' => $e['diploma'] ? diplomaLabel($e['diploma'], 'ar') : '—',
            'grade_start' => $dip ? max((float)$e['starting_grade'], (float)$dip['starting_grade']) : null,
            'immediate' => $dip ? (int)$dip['gets_immediate_grade'] : 1,
            'pay' => $pay, 'tit' => $yearStart,
            'pct' => schoolCadrePercent($db, (int)$e['school_id'], $sy),
            'transport' => schoolCadreTransportTemplate($db, (int)$e['school_id'], $sy),
            'can' => $can, 'why' => $why, 'decision' => $d, 'has_grades' => (int)$gh->fetchColumn(),
        ];
    }
    return $out;
}

/**
 * 🎓 ترسيم أستاذ متعاقد بالملاك ابتداءً من سنة $sy (بعد موافقة المستخدم فقط).
 * الخطوات: نسخة احتياطية (_emp_bk_cadre_due / _gh_bk_cadre_due / _bon_bk_cadre_due) ← ملفه: ملاك من 1/10/Y1، السلسلة بدل
 * الراتب المتفق عليه، محسومات الملاك كرفاقه بالمدرسة ← سجلّ الدرجات بالمحرّك المعتمد لسنة الترسيم كاملةً (درجة الدخول +
 * الفورية بتشرين + 4 بكانون) ← نسبة الملاك بالمدرسة بند واحد ← إعادة حساب سنة الترسيم فقط. يرجّع ملخّصاً.
 */
function titularizeContractTeacher(PDO $db, int $empId, string $sy, string $who = ''): array {
    require_once __DIR__ . '/payroll_calculator.php';
    cadreDueEnsureColumns($db);
    if (!preg_match('/^(\d{4})-(\d{4})$/', $sy, $m)) throw new Exception('سنة غير صحيحة');
    $y1 = (int)$m[1]; $y2 = (int)$m[2];
    $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0");
    $st->execute([$empId]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) throw new Exception('الموظف غير موجود');
    if ($emp['employee_type'] !== 'enseignant_contractuel') throw new Exception('ليس أستاذاً متعاقداً');
    if (isSchoolYearLocked((int)$emp['school_id'], $sy)) throw new Exception(yearLockedMsg((int)$emp['school_id'], $sy));
    $dq = $db->prepare("SELECT starting_grade, gets_immediate_grade FROM diploma_starting_grades WHERE diploma_code = ?");
    $dq->execute([(string)$emp['diploma']]);
    $dip = $dq->fetch(PDO::FETCH_ASSOC);
    if (!$dip) throw new Exception('بلا شهادة بملفه — عبّي الشهادة أوّلاً');
    $tit = sprintf('%04d-10-01', $y1);
    $startG = max((float)$emp['starting_grade'], (float)$dip['starting_grade']);
    if ($startG < 1) $startG = 1.0;

    // 💾 نسخ احتياطية قبل أي تعديل (للاسترجاع)
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS _emp_bk_cadre_due LIKE employees");
        $db->exec("INSERT IGNORE INTO _emp_bk_cadre_due SELECT * FROM employees WHERE id = " . (int)$empId);
        $db->exec("CREATE TABLE IF NOT EXISTS _gh_bk_cadre_due LIKE employee_grade_history");
        $db->exec("INSERT IGNORE INTO _gh_bk_cadre_due SELECT * FROM employee_grade_history WHERE employee_id = " . (int)$empId);
        $db->exec("CREATE TABLE IF NOT EXISTS _bon_bk_cadre_due LIKE employee_bonuses");
        $db->exec("INSERT IGNORE INTO _bon_bk_cadre_due SELECT * FROM employee_bonuses WHERE employee_id = " . (int)$empId);
    } catch (Throwable $e) {}

    $tpl = cadreDueTemplate($db, (int)$emp['school_id']);
    $old = ['employee_type' => $emp['employee_type'], 'titularization_date' => $emp['titularization_date'], 'salary_input_mode' => $emp['salary_input_mode'],
            'base_salary_usd' => $emp['base_salary_usd'], 'contract_salary_lbp' => $emp['contract_salary_lbp'], 'starting_grade' => $emp['starting_grade'],
            'current_grade' => $emp['current_grade'], 'payment_months_per_year' => $emp['payment_months_per_year'], 'eoc_subject' => $emp['eoc_subject']];
    $payOld = ($emp['salary_input_mode'] === 'direct_usd' && (float)$emp['base_salary_usd'] > 0) ? ('$' . (float)$emp['base_salary_usd'])
            : ((float)$emp['contract_salary_lbp'] > 0 ? number_format((float)$emp['contract_salary_lbp']) . ' ل.ل.' : 'منقول');
    $note = trim((string)$emp['notes']);
    $note .= ($note !== '' ? "\n" : '') . '[' . date('Y-m-d') . '] ترسيم بالملاك من ' . $sy . ' (قانون السنتين، بموافقة ' . ($who ?: 'المستخدم') . ') — كان متعاقداً منذ ' . $emp['hire_date'] . ' براتب ' . $payOld;
    $set = "employee_type = 'enseignant_titulaire', titularization_date = ?, tenure_confirmation_date = NULL, salary_input_mode = 'percent_of_lbp',
            base_salary_lbp_percent = 100, contract_salary_lbp = 0, base_salary_usd = 0, starting_grade = ?, current_grade = ?, cadre_from_sy = ?, notes = ?";
    $vals = [$tit, $startG, $startG, $sy, $note];
    foreach ($tpl as $f => $v) { $set .= ", `$f` = ?"; $vals[] = (int)$v; }
    $vals[] = $empId;
    $db->prepare("UPDATE employees SET $set WHERE id = ?")->execute($vals);

    // 🏆 سجلّ الدرجات بالمحرّك المعتمد (المصدر الواحد) لسنة الترسيم كاملةً: دخول الملاك + الفورية (غير التعليمية) بتشرين + 4 بكانون.
    //    force = المتعاقد لا درجات قانونية له قبل اليوم؛ الصفوف اليدوية (إن وُجدت) يحفظها المحرّك نفسه.
    try {
        buildLegalGradeHistory($empId, sprintf('%04d-09-30', $y2), false, true);
    } catch (Throwable $e) {
        // فشل البناء ⇒ أرجِع ملفه كما كان (من النسخة الاحتياطية) حتى لا يبقى ملاكاً بلا درجات
        try { $db->exec("REPLACE INTO employees SELECT * FROM _emp_bk_cadre_due WHERE id = " . (int)$empId); } catch (Throwable $t) {}
        throw new Exception('تعذّر بناء درجاته: ' . $e->getMessage());
    }
    // الدرجة الحالية = درجته عند بداية سنة الترسيم (الصفوف حتى 1/10) — كانون تدخل الراتب بتاريخها من السجلّ
    $g0 = $db->prepare("SELECT grade_after FROM employee_grade_history WHERE employee_id = ? AND grade_after >= 1 AND change_date <= ? ORDER BY change_date DESC, id DESC LIMIT 1");
    $g0->execute([$empId, $tit]);
    $gradeNow = (float)($g0->fetchColumn() ?: $startG);
    $db->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([$gradeNow, $empId]);
    $gEnd = (float)$db->query("SELECT MAX(grade_after) FROM employee_grade_history WHERE employee_id = " . (int)$empId)->fetchColumn();

    // 💯 نسبة الملاك بالمدرسة (بند واحد بدل أي إضافي كان له كمتعاقد بهذه السنة) — إن كانت المدرسة بالمبالغ يبقى ما له كما هو
    $pct = schoolCadrePercent($db, (int)$emp['school_id'], $sy);
    cadreDueApplyPercent($db, $empId, (int)$emp['school_id'], $sy);
    // 🚌 تعويض النقل كملاك المدرسة (5 أيام — «كل القانون اللي بيطبّق على الملاك»)
    $trText = cadreDueApplyTransport($db, $empId, (int)$emp['school_id'], $sy);
    // 🧮 إعادة حساب سنة الترسيم فقط — سنواته السابقة كمتعاقد لا تُلمَس (صمام cadre_from_sy بالمحرّك)
    $months = (int)recalcEmployeeYear($empId, $sy);
    $name = cadreDueEmpName($emp);
    $res = 'رُسِّم بالملاك من ' . $tit . ': درجة الدخول ' . rtrim(rtrim(number_format($startG, 1), '0'), '.') . ' ← ' . rtrim(rtrim(number_format($gEnd, 1), '0'), '.') . ' بنهاية ' . $sy
         . ($pct ? ' + إضافي ' . rtrim(rtrim((string)$pct['pct'], '0'), '.') . ' % كملاك المدرسة' : ' (المدرسة بلا نسبة — بنوده كما هي)')
         . ($trText ? ' + ' . $trText : '') . ' — حُسب ' . $months . ' شهراً';
    logAudit('cadre_titularize', 'employees', $empId, $old, ['employee_type' => 'enseignant_titulaire', 'titularization_date' => $tit, 'sy' => $sy, 'starting_grade' => $startG, 'grade_end' => $gEnd, 'pct' => $pct['pct'] ?? null, 'transport' => $trText, 'months' => $months]);
    cadreDueRecordDecision($db, ['id' => $empId, 'school_id' => (int)$emp['school_id'], 'name' => $name, 'hire_date' => $emp['hire_date'], 'years' => 0, 'tit' => $tit], $sy, 'approved', $res, $who);
    return ['ok' => true, 'id' => $empId, 'name' => $name, 'grade_start' => $startG, 'grade_now' => $gradeNow, 'grade_end' => $gEnd, 'pct' => $pct['pct'] ?? null, 'months' => $months, 'msg' => $res];
}

/**
 * معالج القرارات (POST) من لوحة القيادة/صفحة فتح السنة: cd_approve (emp_id أو emp_ids[]) يرسّم، cd_reject يترك متعاقداً هذه السنة،
 * cd_reopen يلغي الرفض. محميّ بـCSRF + canEdit + نطاق المدرسة + السنة ≥ سنة البرنامج (لا ترسيم بأثر رجعي على سنة منتهية).
 */
function handleCadreDuePost(PDO $db, string $redirectTo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['action'] ?? '', ['cd_approve', 'cd_reject', 'cd_reopen'], true)) return;
    requireCsrf();
    if (!canEdit()) { $_SESSION['flash_error'] = 'غير مسموح — حساب قراءة فقط.'; header('Location: ' . $redirectTo); exit; }
    cadreDueEnsureColumns($db);
    $act = (string)$_POST['action'];
    $sy = (string)($_POST['cd_sy'] ?? '');
    if (!preg_match('/^\d{4}-\d{4}$/', $sy) || strcmp($sy, currentSchoolYear()) < 0) { $_SESSION['flash_error'] = 'الترسيم الحكمي للسنة الحالية للبرنامج أو ما بعدها فقط (' . currentSchoolYear() . ').'; header('Location: ' . $redirectTo); exit; }
    $who = (string)($_SESSION['username'] ?? '');
    $ids = [];
    if (!empty($_POST['emp_ids']) && is_array($_POST['emp_ids'])) $ids = array_map('intval', $_POST['emp_ids']);
    elseif (!empty($_POST['emp_id'])) $ids = [(int)$_POST['emp_id']];
    $ids = array_values(array_filter(array_unique($ids)));
    if ($act === 'cd_reopen') {
        foreach ($ids as $eid) $db->prepare("DELETE FROM compliance_decisions WHERE item_key = ? AND decision = 'rejected'")->execute(['cadre_due|' . $eid . '|' . $sy]);
        $_SESSION['flash_success'] = 'أُعيد فتح القرار — سيظهر بانتظار موافقتك.';
        header('Location: ' . $redirectTo); exit;
    }
    $cands = []; foreach (cadreDueCandidates($db, $sy, null, true, true) as $c) $cands[$c['id']] = $c;
    $done = 0; $msgs = []; $errs = []; $locked = 0;
    foreach ($ids as $eid) {
        $c = $cands[$eid] ?? null;
        if (!$c) continue;
        if (isSchoolYearLocked((int)$c['school_id'], $sy)) { $locked++; continue; }
        if ($act === 'cd_approve') {
            if (!$c['can']) { $errs[] = $c['name'] . ': ' . $c['why']; continue; }
            try { $r = titularizeContractTeacher($db, $eid, $sy, $who); $msgs[] = $r['name'] . ' (' . $r['msg'] . ')'; $done++; }
            catch (Throwable $e) { $errs[] = $c['name'] . ': ' . $e->getMessage(); }
        } else {
            cadreDueRecordDecision($db, $c, $sy, 'rejected', 'بقراره: يبقى متعاقداً بسنة ' . $sy, $who);
            $msgs[] = $c['name']; $done++;
        }
    }
    if ($done) $_SESSION['flash_success'] = ($act === 'cd_approve' ? '🎓 رُسِّم بالملاك ' : '⏸️ بقي متعاقداً هذه السنة ') . $done . ': ' . implode(' · ', array_slice($msgs, 0, 10)) . (count($msgs) > 10 ? '…' : '');
    if ($errs) $_SESSION['flash_error'] = '⚠️ ' . implode(' · ', array_slice($errs, 0, 6));
    if ($locked) $_SESSION['flash_error'] = '🔒 ' . $locked . ' أستاذ تُرك كما هو لأن سنة مدرسته مقفولة.';
    if (!$done && !$errs && !$locked) $_SESSION['flash_error'] = 'لم يُنفَّذ شيء — الأستاذ لم يعد مرشَّحاً أو ليس ضمن مدرستك.';
    header('Location: ' . $redirectTo); exit;
}

/** نصّ موجز لنسبة المدرسة */
function cadreDuePctText(?array $pct): string {
    if (!$pct) return 'بلا نسبة (مدرسة بالمبالغ) — بنوده تبقى';
    $t = rtrim(rtrim((string)$pct['pct'], '0'), '.') . ' %';
    if ($pct['start_month'] && $pct['end_month']) $t .= ' (' . monthName((int)$pct['start_month'], 'ar') . ' ← ' . monthName((int)$pct['end_month'], 'ar') . ')';
    return $t . ' — كـ' . (int)$pct['n'] . ' أستاذ ملاك بالمدرسة';
}

/** صفوف جدول المرشَّحين (مشتركة بين المساج وصفحة المراجعة) */
function cadreDueRowCells(array $c): string {
    $gs = $c['grade_start'] !== null ? rtrim(rtrim(number_format($c['grade_start'], 1), '0'), '.') : '—';
    $h = '<td><a href="' . BASE_URL . 'pages/employees.php?action=edit&id=' . (int)$c['id'] . '"><strong>' . e($c['name']) . '</strong></a>'
       . ($c['has_grades'] ? '<br><small style="color:#b45309">⚠️ له سجلّ درجات قديم (' . (int)$c['has_grades'] . ' صفّاً) — يُستبدَل بسجلّ ملاك جديد من ' . e($c['tit']) . ' (اليدوية تبقى، والقديم بنسخة احتياطية)</small>' : '') . '</td>'
       . '<td style="white-space:nowrap">' . e($c['hire_date']) . '<br><small class="text-muted">' . (int)$c['years'] . ' سنة</small></td>'
       . '<td>' . e($c['diploma_label']) . ($c['can'] ? '<br><small>درجة الدخول <strong>' . $gs . '</strong>' . ($c['immediate'] ? ' + درجة فورية بتشرين' : ' (تعليمية: بلا فورية)') . ' + 4 بكانون</small>' : '<br><span class="badge badge-warning">⚠️ ' . e($c['why']) . '</span>') . '</td>'
       . '<td>' . e($c['pay']) . '</td>'
       . '<td style="white-space:nowrap">' . e($c['tit']) . '</td>'
       . '<td>' . e(cadreDuePctText($c['pct'])) . '<br><small style="color:#0a6b5e">🚌 ' . e(cadreDueTransportText($c['transport'] ?? null)) . '</small></td>';
    return $h;
}

/**
 * 🚑 شفاء ذاتي مرّة واحدة (2026-09-13): جنى لبوس (عبرا، 1785) حوّلها المستخدم بيده من متعاقدة إلى ملاك أونلاين (2026-10-01) قبل وجود
 * صمام cadre_from_sy، فأعاد البرنامج حساب سنتَيها كمتعاقدة بمحرّك الملاك (2024-2025 صارت 12 شهراً بدل 9، وتشرين 2025 صافيها 57,702,000
 * بدل 58,248,000 فاختلف كشف عبرا عن كشفه القديم بالمليم). الاسترجاع من لقطة الأونلاين 2026-09-12 (tools/data/rows_1785_pre2627_20260913.json)
 * لسنواتها قبل 2026-2027 فقط + cadre_from_sy = 2026-2027 حتى لا يتكرّر. نسخة _ms_bk_jana0913. لا يمسّ 2026-2027 (سنة ملاكها).
 */
function healJanaRestore20260913(): void {
    $flag = 'heal_jana_restore_20260913';
    if (strpos((string)getSetting($flag, ''), 'done') === 0) return;
    try {
        $db = getDB();
        if ($db->inTransaction()) return;
        $file = __DIR__ . '/../tools/data/rows_1785_pre2627_20260913.json';
        if (!is_file($file)) return;
        $e = $db->query("SELECT id, first_name_ar, last_name_ar, school_id, employee_type FROM employees WHERE id = 1785 AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
        if (!$e || mb_strpos((string)$e['first_name_ar'], 'جنى') === false || mb_strpos((string)$e['last_name_ar'], 'لبوس') === false) { setSetting($flag, 'done ' . date('Y-m-d H:i') . ' (ليست جنى لبوس — لا شيء)'); return; }
        if (isSchoolYearLocked((int)$e['school_id'], '2025-2026') || isSchoolYearLocked((int)$e['school_id'], '2024-2025')) return; // يُعاد عند فتح القفل
        $rows = json_decode((string)file_get_contents($file), true);
        if (!is_array($rows) || !$rows) { setSetting($flag, 'err: ملف اللقطة فارغ'); return; }
        $want = 0; foreach ($rows as $r) $want += (float)$r['net_salary_lbp'];
        $cur = $db->query("SELECT COUNT(*) n, COALESCE(SUM(net_salary_lbp),0) s FROM monthly_salaries WHERE employee_id = 1785 AND school_year < '2026-2027'")->fetch(PDO::FETCH_ASSOC);
        cadreDueEnsureColumns($db);
        if ((int)$cur['n'] === count($rows) && abs((float)$cur['s'] - $want) < 1) {
            $db->exec("UPDATE employees SET cadre_from_sy = COALESCE(cadre_from_sy, '2026-2027') WHERE id = 1785 AND employee_type = 'enseignant_titulaire'");
            setSetting($flag, 'done ' . date('Y-m-d H:i') . ' (سليمة أصلاً)'); return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_jana0913 LIKE monthly_salaries");
        $db->exec("INSERT IGNORE INTO _ms_bk_jana0913 SELECT * FROM monthly_salaries WHERE employee_id = 1785 AND school_year < '2026-2027'");
        $cols = $db->query("SHOW COLUMNS FROM monthly_salaries")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec("DELETE FROM monthly_salaries WHERE employee_id = 1785 AND school_year < '2026-2027'");
        $n = 0;
        foreach ($rows as $r) {
            unset($r['id']);
            $r = array_intersect_key($r, array_flip($cols));
            $c = '`' . implode('`,`', array_keys($r)) . '`';
            $db->prepare("INSERT INTO monthly_salaries ($c) VALUES (" . implode(',', array_fill(0, count($r), '?')) . ")")->execute(array_values($r));
            $n++;
        }
        if ($e['employee_type'] === 'enseignant_titulaire') $db->exec("UPDATE employees SET cadre_from_sy = COALESCE(cadre_from_sy, '2026-2027') WHERE id = 1785");
        complianceLogAuto($db, 'cadre_due', 1785, '2025-2026', cadreDueEmpName($e), 'حُوِّلت بيده لملاك فأُعيد حساب سنتَيها كمتعاقدة بمحرّك الملاك (' . (int)$cur['n'] . ' شهراً، صافي ' . number_format((float)$cur['s']) . ')',
                          'استرجاع رواتب 2024-2025 و2025-2026 كما كانت من لقطة 2026-09-12 + صمام السنين السابقة', 'استُرجع ' . $n . ' شهراً (صافي ' . number_format($want) . ') — نسخة _ms_bk_jana0913 (شفاء ذاتي 2026-09-13)');
        setSetting($flag, 'done ' . date('Y-m-d H:i') . " ($n صفاً، كان {$cur['n']}/" . (float)$cur['s'] . ')');
    } catch (Throwable $ex) { try { setSetting($flag, 'err: ' . mb_substr($ex->getMessage(), 0, 150)); } catch (Throwable $t) {} }
}

/**
 * 🩹 شفاء ذاتي (2026-09-13، بعد ملاحظته «اللي بيصيروا بالملاك لازم طبّق عليهن تعويض النقل على 5 أيام — كل القانون»):
 * كل أستاذ ملاك صار ملاكاً بالسنة الحالية للبرنامج (titularization_date ضمنها) — سواء رُسِّم من هنا قبل هذا التصحيح أو حوّله المستخدم
 * بيده من ملفه (جنى لبوس بعبرا) — يُستكمَل له قانون الملاك مرّة واحدة: (1) درجات سنة الترسيم كاملة (الفورية + 4 بكانون) إن كانت ناقصة
 * ولم يلمس المستخدم درجاته، (2) لمن كان موظّفاً قبلها (له رواتب سنين سابقة = مُحوَّل من تعاقد): cadre_from_sy + تعويض النقل ونسبة
 * الإضافي كملاك مدرسته، ثم إعادة حساب السنة. يحترم قفل السنة. يُسجَّل بتقرير المخالفات «صُحِّح تلقائياً». مرّة لكل أستاذ/سنة.
 */
function healCadreNew20260913(): void {
    $flag = 'heal_cadre_new_20260913';
    try {
        $db = getDB();
        if ($db->inTransaction()) return;
        cadreDueEnsureColumns($db);
        require_once __DIR__ . '/payroll_calculator.php';
        $sy = currentSchoolYear();
        if (!preg_match('/^(\d{4})-(\d{4})$/', $sy, $m)) return;
        $y1 = (int)$m[1]; $y2 = (int)$m[2];
        $done = json_decode((string)getSetting($flag, '[]'), true); if (!is_array($done)) $done = [];
        $st = $db->prepare("SELECT * FROM employees WHERE is_deleted = 0 AND status = 'actif' AND employee_type = 'enseignant_titulaire'
                            AND titularization_date BETWEEN ? AND ? ORDER BY id");
        $st->execute([sprintf('%04d-10-01', $y1), sprintf('%04d-09-30', $y2)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $emp) {
            $id = (int)$emp['id']; $key = $id . '|' . $sy;
            if (in_array($key, $done, true)) continue;
            if ($n >= 15) break; // دفعات خفيفة عند كل فتح
            if (isSchoolYearLocked((int)$emp['school_id'], $sy)) continue;
            $log = [];
            try {
                // (1) درجات سنة الترسيم كاملة — فقط إن لم يلمسها المستخدم وكان الناقص واضحاً (القانون يعطي أكثر مما بالسجلّ)
                if (!gradesUserAdjusted($id) && !empty($emp['diploma'])) {
                    $dry = buildLegalGradeHistory($id, sprintf('%04d-09-30', $y2), true);
                    $maxStored = (float)$db->query("SELECT COALESCE(MAX(grade_after),0) FROM employee_grade_history WHERE employee_id = $id")->fetchColumn();
                    if ((float)$dry['final_grade'] - $maxStored > 0.01) {
                        buildLegalGradeHistory($id, sprintf('%04d-09-30', $y2));
                        $g0 = $db->prepare("SELECT grade_after FROM employee_grade_history WHERE employee_id = ? AND grade_after >= 1 AND change_date <= ? ORDER BY change_date DESC, id DESC LIMIT 1");
                        $g0->execute([$id, sprintf('%04d-10-01', $y1)]);
                        $gNow = (float)($g0->fetchColumn() ?: $emp['starting_grade']);
                        $db->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([$gNow, $id]);
                        $log[] = 'درجات سنة الترسيم بالقانون (' . rtrim(rtrim(number_format($maxStored, 1), '0'), '.') . ' ← ' . rtrim(rtrim(number_format((float)$dry['final_grade'], 1), '0'), '.') . ')';
                    }
                }
                // (2) المُحوَّل من تعاقد (له رواتب قبل سنة الترسيم): نقل + نسبة كملاك مدرسته + صمام السنين السابقة
                $prev = $db->prepare("SELECT 1 FROM monthly_salaries WHERE employee_id = ? AND school_year < ? AND (net_salary_lbp > 0 OR base_plus_echelon_lbp > 0) LIMIT 1");
                $prev->execute([$id, $sy]);
                if ($prev->fetchColumn()) {
                    if (empty($emp['cadre_from_sy'])) $db->prepare("UPDATE employees SET cadre_from_sy = ? WHERE id = ?")->execute([$sy, $id]);
                    $p = cadreDueApplyPercent($db, $id, (int)$emp['school_id'], $sy);
                    if ($p !== null) $log[] = 'إضافي ' . rtrim(rtrim((string)$p, '0'), '.') . ' % كملاك المدرسة';
                    $t = cadreDueApplyTransport($db, $id, (int)$emp['school_id'], $sy);
                    if ($t !== null) $log[] = $t;
                }
                if ($log) {
                    $months = (int)recalcEmployeeYear($id, $sy);
                    complianceLogAuto($db, 'cadre_due', $id, $sy, cadreDueEmpName($emp), 'صار ملاكاً بسنة ' . $sy . ' وينقصه من قانون الملاك: ' . implode(' · ', $log),
                                      'استكمال قانون الملاك كرفاقه بالمدرسة وإعادة حساب السنة', implode(' · ', $log) . ' — حُسب ' . $months . ' شهراً (شفاء ذاتي 2026-09-13)');
                    logAudit('cadre_heal_new', 'employees', $id, null, ['sy' => $sy, 'log' => $log]);
                }
            } catch (Throwable $e) { /* أستاذ واحد لا يوقف الباقي */ }
            $done[] = $key; $n++;
        }
        if ($n) setSetting($flag, json_encode(array_slice($done, -400)));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * المساج «أساتذة استحقّوا الملاك — قرار مطلوب» (لوحة القيادة مطويّة لكل مدرسة / صفحة فتح السنة مفتوحة) + المتروكون بقراره (إعادة فتح).
 */
function renderCadreDuePending(array $cands, string $sy, bool $collapsed = false, string $formAction = '', array $rejected = []): void {
    if (!$cands && !$rejected) return;
    $bySchool = [];
    foreach ($cands as $c) $bySchool[$c['school_name']][] = $c;
    $total = count($cands);
    ?>
    <div class="card no-print" id="cadreDue" style="border:2px solid #6d28d9;margin-bottom:16px">
        <div class="card-header" style="background:#f5f3ff"><h3 style="color:#5b21b6"><i class="fas fa-graduation-cap"></i>
            <span dir="ltr">Titularisation d'office après 2 ans — décision requise</span> / أساتذة متعاقدون أكملوا سنتين — يصيرون بالملاك حكماً بسنة <?= e($sy) ?><?= $total ? ' — <span style="background:#6d28d9;color:#fff;border-radius:999px;padding:1px 10px">' . $total . ' قرار مطلوب</span>' : '' ?></h3></div>
        <div class="card-body">
            <p style="color:var(--gray-600);margin-top:0">القانون: المتعاقد الذي أكمل <strong>سنتين دراسيتين كاملتين</strong> بالمدرسة نفسها (دخلها قبل 1 تشرين الثاني <?= (int)substr($sy, 0, 4) - 2 ?>) يصير <strong>بالملاك حكماً</strong> من السنة الثالثة <?= e($sy) ?>.
                <strong style="color:#166534">وافق</strong> ⇒ يصير ملاكاً من <?= (int)substr($sy, 0, 4) ?>/10/1: راتب <strong>السلسلة حسب درجته</strong> بدل الراتب المتفق عليه، درجاته <strong>بالقانون</strong> (درجة الدخول حسب الشهادة + الفورية + 4+4+2)، محسومات الملاك كرفاقه، و<strong>نسبة الإضافي المعطاة لملاك مدرسته</strong> — وسنواته السابقة كمتعاقد لا تتغيّر أبداً.
                <strong>لا</strong> ⇒ يبقى متعاقداً هذه السنة (يُسجَّل قرارك ويمكن إعادة فتحه). لا يتغيّر شي بلا موافقتك.</p>
            <?php foreach ($bySchool as $schoolName => $rows): $fid = 'cdf' . (int)$rows[0]['school_id']; $nCan = count(array_filter($rows, fn($r) => $r['can'])); ?>
            <<?= $collapsed ? 'details' : 'div' ?> style="margin-bottom:<?= $collapsed ? '8px' : '14px' ?>">
                <?php if ($collapsed): ?><summary style="cursor:pointer;color:#5b21b6;font-weight:700;padding:6px 0"><i class="fas fa-school"></i> <?= e($schoolName) ?> — <?= count($rows) ?> أستاذاً <small style="font-weight:600;opacity:.8">(اكبس لرؤية الأسماء وتأشير قرارك)</small></summary><?php endif; ?>
                <?php // ☑️ «قدّام كل أستاذ حطّ شك مارك هيك بختار الأساتذة اللي بدي ياهن أو بدي يضلّو متل ما هني» (أمره 2026-09-13): نموذج واحد للمدرسة، صناديق تأشير، وزرّان للمؤشَّرين ?>
                <form method="post" id="<?= $fid ?>"<?= $formAction !== '' ? ' action="' . e($formAction) . '"' : '' ?> style="margin:0" onsubmit="var n=this.querySelectorAll('input[name=&quot;emp_ids[]&quot;]:checked').length; if(!n){alert('أشّر أستاذاً واحداً على الأقل');return false;} var a=this.querySelector('input[name=cd_act]').value; return confirm(a==='cd_approve' ? ('ترسيم '+n+' أستاذاً مؤشَّراً بالملاك من سنة <?= e($sy) ?>؟ (السلسلة + الدرجات بالقانون + نسبة الملاك بالمدرسة — سنواتهم السابقة لا تتغيّر)') : ('المؤشَّرون ('+n+') يبقون متعاقدين بسنة <?= e($sy) ?>؟ (يُسجَّل قرارك ويمكن إعادة فتحه)'));">
                    <?= csrfField() ?><input type="hidden" name="cd_sy" value="<?= e($sy) ?>"><input type="hidden" name="action" value=""><input type="hidden" name="cd_act" value="">
                    <div class="d-flex justify-between align-center" style="flex-wrap:wrap;gap:6px;margin-bottom:6px">
                        <strong style="color:#5b21b6"><?= $collapsed ? '' : '<i class="fas fa-school"></i> ' . e($schoolName) . ' — ' . count($rows) . ' أستاذاً' ?></strong>
                        <?php if (canEdit() && $nCan > 0): ?>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <label style="cursor:pointer;font-size:13px;margin:0"><input type="checkbox" onchange="document.querySelectorAll('#<?= $fid ?> input[name=&quot;emp_ids[]&quot;]:not(:disabled)').forEach(function(c){c.checked=this.checked;}.bind(this))"> أشّر الكل</label>
                            <button type="submit" class="btn btn-sm btn-success" onclick="var f=this.form; f.querySelector('input[name=action]').value='cd_approve'; f.querySelector('input[name=cd_act]').value='cd_approve';"><i class="fas fa-check"></i> وافق — رسّم المؤشَّرين بالملاك</button>
                            <button type="submit" class="btn btn-sm btn-light" onclick="var f=this.form; f.querySelector('input[name=action]').value='cd_reject'; f.querySelector('input[name=cd_act]').value='cd_reject';" title="يبقون متعاقدين هذه السنة — يُسجَّل قرارك ويمكن إعادة فتحه"><i class="fas fa-xmark"></i> المؤشَّرون يبقون متعاقدين</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="table-wrapper"><table class="table">
                        <thead><tr><th style="width:36px">✓</th><th>الأستاذ</th><th>بالمدرسة منذ</th><th>الشهادة ← الدرجات بالقانون</th><th>راتبه الآن (متعاقد)</th><th>بالملاك من</th><th>نسبة الملاك بالمدرسة</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $c): ?>
                        <tr><td><?php if (canEdit()): ?><input type="checkbox" name="emp_ids[]" value="<?= (int)$c['id'] ?>" <?= $c['can'] ? '' : 'disabled title="' . e($c['why']) . '"' ?> style="width:18px;height:18px"><?php endif; ?></td><?= cadreDueRowCells($c) ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </form>
            </<?= $collapsed ? 'details' : 'div' ?>>
            <?php endforeach; ?>
            <?php if ($rejected): ?>
            <details style="margin-top:8px"><summary style="cursor:pointer;color:#6b7280;font-weight:700"><i class="fas fa-user-clock"></i> تركتهم متعاقدين بسنة <?= e($sy) ?> بقرارك — <?= count($rejected) ?> <small style="font-weight:600">(اكبس لإعادة فتح قرار)</small></summary>
                <div class="table-wrapper" style="margin-top:6px"><table class="table" style="margin:0"><thead><tr><th>الأستاذ</th><th>المدرسة</th><th>بالمدرسة منذ</th><th>القرار</th><th></th></tr></thead><tbody>
                <?php foreach ($rejected as $c): ?>
                    <tr><td><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a></td><td><?= e($c['school_name']) ?></td><td><?= e($c['hire_date']) ?> (<?= (int)$c['years'] ?> سنة)</td>
                        <td><small><?= e((string)($c['decision']['result'] ?? '')) ?> — <?= e((string)($c['decision']['decided_by'] ?? '')) ?> <?= e((string)($c['decision']['decided_at'] ?? '')) ?></small></td>
                        <td style="white-space:nowrap"><?php if (canEdit()): ?><form method="post"<?= $formAction !== '' ? ' action="' . e($formAction) . '"' : '' ?> style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="cd_reopen"><input type="hidden" name="cd_sy" value="<?= e($sy) ?>"><input type="hidden" name="emp_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-light"><i class="fas fa-rotate-left"></i> أعد فتح القرار</button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </details>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * صفحة المراجعة قبل فتح السنة: لائحة المرشَّحين بصناديق تأشير + «وافق على المؤشَّرين وافتح السنة» / «ما وافق — افتح السنة بلا ترسيم».
 * $hidden = حقول طلب الفتح الأصلي (تُعاد كما هي مع cadre_reviewed=1).
 */
function renderCadreDueReview(array $cands, string $newYear, array $hidden): void {
    $bySchool = [];
    foreach ($cands as $c) $bySchool[$c['school_name']][] = $c;
    $nCan = count(array_filter($cands, fn($c) => $c['can']));
    ?>
    <div class="card" style="border:2px solid #6d28d9;max-width:1200px;margin:20px auto">
        <div class="card-header" style="background:#f5f3ff"><h3 style="color:#5b21b6"><i class="fas fa-graduation-cap"></i>
            <span dir="ltr">Avant d'ouvrir <?= e($newYear) ?> — titularisation d'office après 2 ans</span>
            <div style="font-size:0.9em;font-weight:700">قبل فتح سنة <?= e($newYear) ?>: <?= count($cands) ?> أستاذاً متعاقداً أكملوا سنتين بالمدرسة — لازم يصيروا بالملاك حكماً. وافق أو لا؟</div></h3></div>
        <div class="card-body">
            <div style="background:#fff;border:1px solid #ddd6fe;border-radius:10px;padding:10px 14px;font-size:14px;line-height:1.9;margin-bottom:12px">
                القانون: المتعاقد الذي أكمل <b>سنتين دراسيتين كاملتين</b> بالمدرسة نفسها يصير <b>بالملاك حكماً</b> من السنة الثالثة. الأسماء أدناه دخلوا مدارسهم قبل 1 تشرين الثاني <?= (int)substr($newYear, 0, 4) - 2 ?>.<br>
                <b>المؤشَّر ✓</b> يصير ملاكاً من <?= (int)substr($newYear, 0, 4) ?>/10/1: راتب <b>السلسلة حسب درجته</b> بدل الراتب المتفق عليه، درجاته <b>بالقانون</b> (درجة الدخول حسب الشهادة + الفورية بتشرين + 4 بكانون ثم 4 ثم 2)، محسومات الملاك كرفاقه بالمدرسة، و<b>نسبة الإضافي المعطاة لملاك مدرسته</b>. سنواته السابقة كمتعاقد <b>لا تتغيّر</b>.<br>
                <b>غير المؤشَّر</b> يبقى متعاقداً هذه السنة (يُسجَّل قرارك — تقدر تعيد فتحه من بطاقة «أساتذة استحقّوا الملاك»). <b>ما بيتغيّر شي بلا موافقتك.</b>
            </div>
            <form method="POST" id="cadreReviewForm">
                <?= csrfField() ?>
                <?php foreach ($hidden as $k => $v): if (is_array($v)): foreach ($v as $vv): ?><input type="hidden" name="<?= e($k) ?>[]" value="<?= e((string)$vv) ?>"><?php endforeach; else: ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endif; endforeach; ?>
                <input type="hidden" name="cadre_reviewed" value="1"><input type="hidden" name="cadre_none" id="cadreNone" value="">
                <?php foreach ($bySchool as $schoolName => $rows): ?>
                <div style="margin-bottom:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                        <strong style="color:#5b21b6"><i class="fas fa-school"></i> <?= e($schoolName) ?> — <?= count($rows) ?> أستاذاً</strong>
                        <label style="cursor:pointer;font-size:13px"><input type="checkbox" checked onchange="this.closest('div[style]').parentNode.querySelectorAll('input[name=&quot;cadre_ok[]&quot;]:not(:disabled)').forEach(function(c){c.checked=this.checked;}.bind(this))"> أشّر/شيل الكل بهذه المدرسة</label>
                    </div>
                    <div class="table-wrapper"><table class="table">
                        <thead><tr><th style="width:36px">✓</th><th>الأستاذ</th><th>بالمدرسة منذ</th><th>الشهادة ← الدرجات بالقانون</th><th>راتبه الآن (متعاقد)</th><th>بالملاك من</th><th>نسبة الملاك بالمدرسة</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $c): ?>
                        <tr><td><input type="checkbox" name="cadre_ok[]" value="<?= (int)$c['id'] ?>" <?= $c['can'] ? 'checked' : 'disabled title="' . e($c['why']) . '"' ?> style="width:18px;height:18px"></td><?= cadreDueRowCells($c) ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center">
                    <button type="submit" class="btn btn-success" style="font-size:16px;font-weight:800;padding:10px 22px" onclick="document.getElementById('cadreNone').value='';return confirm('ترسيم المؤشَّرين بالملاك من سنة <?= e($newYear) ?> ثم فتح السنة؟ (سنواتهم السابقة لا تتغيّر)');"><i class="fas fa-check"></i> وافق على المؤشَّرين وافتح السنة / Titulariser et ouvrir</button>
                    <button type="submit" class="btn btn-light" style="font-size:15px;font-weight:700;padding:10px 18px" onclick="document.getElementById('cadreNone').value='1';return confirm('فتح سنة <?= e($newYear) ?> بلا ترسيم أحد؟ (يبقون متعاقدين هذه السنة — تقدر ترسّمهم لاحقاً من لوحة القيادة)');"><i class="fas fa-xmark"></i> ما وافق — افتح السنة بلا ترسيم / Ouvrir sans titulariser</button>
                    <a href="<?= BASE_URL ?>pages/open_year.php" class="btn btn-light"><i class="fas fa-arrow-right"></i> رجوع / Retour</a>
                </div>
                <small style="color:#64748b;display:block;margin-top:8px"><?= $nCan ?> مؤهَّل للترسيم<?= count($cands) - $nCan ? ' · ' . (count($cands) - $nCan) . ' بلا شهادة (عبّيها بملفه ثم رسّمه من لوحة القيادة)' : '' ?>.</small>
            </form>
        </div>
    </div>
    <?php
}
