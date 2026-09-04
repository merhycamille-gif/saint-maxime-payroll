<?php
/**
 * ⚖️ تقرير المخالفات والتصحيحات / Rapport de conformité (طلبه 2026-09-04 بعد حادثة أنطوني جبور 110 %):
 * «لازم تعطيني تقرير مساج يطلع عندي بس افتح البرنامج بيقول هيدا الأستاذ مخالف القانون وشو نوع المخالفة
 *  والتصحيح وإذا أنا بوافق أو لا — دايماً يكون في تقرير بهيدا الشي».
 *
 * المبدأ: البرنامج يفحص نفسه عند كل فتح للوحة القيادة (وبصفحة التقرير الدائمة) ويعرض لكل أستاذ:
 *   نوع المخالفة + شرحها بالأرقام + التصحيح المقترح + زرّان: «موافق — صحّح» (ينفّذ التصحيح فوراً)
 *   أو «لا — اتركه» (يُسجَّل قراره ولا يعود يزعجه بهذه المخالفة نفسها؛ يقدر يعيد فتحها من الصفحة).
 * لا يُنفَّذ أي تصحيح بلا موافقته. ما يصحّحه البرنامج ذاتياً (المكرّر الحرفي) يُسجَّل بالتقرير «صُحِّح تلقائياً».
 * القرارات محفوظة بجدول compliance_decisions (يتركّب ذاتياً).
 */

function complianceEnsureTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS compliance_decisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_key VARCHAR(190) NOT NULL UNIQUE,
            rule_key VARCHAR(40) NOT NULL,
            employee_id INT NULL,
            school_id INT NULL,
            school_year VARCHAR(9) NULL,
            emp_name VARCHAR(160) NULL,
            violation TEXT NULL,
            fix TEXT NULL,
            decision VARCHAR(12) NOT NULL DEFAULT 'pending',
            result TEXT NULL,
            decided_by VARCHAR(80) NULL,
            decided_at DATETIME NULL,
            created_at DATETIME NULL
        ) DEFAULT CHARSET=utf8mb4");
        // بذر: التصحيح التلقائي الذي جرى قبل وجود التقرير (أنطوني جبور 110 % — الشفاء healDuplicatePercent20260904 سجّله بالإعداد فقط)
        $flag = (string)getSetting('heal_dup_percent_20260904', '');
        if (preg_match('/off=(\d+)/', $flag, $m) && (int)$m[1] > 0 && (int)$db->query("SELECT COUNT(*) FROM compliance_decisions WHERE rule_key = 'dup_percent' AND decision = 'auto'")->fetchColumn() === 0) {
            $who = trim((string)(explode('|', $flag)[1] ?? ''));
            if (preg_match('/^(.*?)#(\d+)\s+(\d{4}-\d{4})/u', $who, $mm)) {
                complianceLogAuto($db, 'dup_percent', (int)$mm[2], $mm[3], trim($mm[1]),
                    'بند نسبة مكرّر فاعل بنفس السنة فكانت النسبة تُجمع مرّتين (55 % + 55 % = 110 %)',
                    'إطفاء المكرّر والإبقاء على الأقدم وإعادة حساب السنة', $flag);
            }
        }
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/** تعريف القواعد: مفتاح → [عنوان فرنسي، عنوان عربي، لون] */
function complianceRules(): array {
    return [
        'grade_law'      => ['Échelon ≠ loi',              'الدرجة لا تطابق القانون',                           '#b91c1c'],
        'base_scale'     => ['Base ≠ grille',              'أساس الراتب لا يطابق السلسلة عند درجته',             '#b91c1c'],
        'pct_law'        => ['Supplément ≠ loi du %',      'الإضافي لا يطابق قانون النسبة (÷1500 × سعر الشهر)', '#b91c1c'],
        'dup_percent'    => ['% en double',                'بند نسبة مكرّر (النسبة تتضاعف)',                     '#b91c1c'],
        'multi_percent'  => ['Plusieurs %',                'أكثر من نسبة فاعلة بنفس السنة (تُجمع معاً)',          '#b45309'],
        'add_stale'      => ['Supplément périmé',          'الإضافي بالأشهر لا يطابق بنود ملفه',                  '#b45309'],
        'ghost_add'      => ['Supplément fantôme',         'إضافي/مكافأة بالأشهر بلا أي بند بملفه',              '#b45309'],
        'missing_add'    => ['Supplément non appliqué',    'بند إضافي بملفه لكن أشهره بصفر',                     '#b45309'],
        'pct_nontit'     => ['% hors titulaire',           'نسبة مئوية عند متعاقد أو موظف (للملاك فقط)',          '#b45309'],
        'bonus_nosy'     => ['Prime sans année',           'بند علاوة بلا سنة دراسية (ينطبق على كل السنين)',      '#b45309'],
        'net_math'       => ['Net incohérent',             'صافي أو مستحق لا يساوي مكوّناته',                    '#b91c1c'],
        'row_rate0'      => ['Taux = 0',                   'صف راتب بسعر صرف صفر أو فارغ',                        '#b45309'],
        'left_rows'      => ['Salaires après départ',      'تارك عنده رواتب بعد تركه',                            '#7c3aed'],
        'active_nomonths'=> ['Actif sans salaires',        'موظف فاعل بلا رواتب بسنة مفتوحة',                     '#64748b'],
        'no_diploma'     => ['Sans diplôme',               'أستاذ ملاك بلا شهادة بملفه (يُفترض قسم ثاني)',        '#64748b'],
        'dupes'          => ['Doublon',                    'موظفان فاعلان بنفس الاسم بنفس المدرسة',               '#64748b'],
        'rate_missing'   => ['Taux manquant',              'شهر بلا سعر صرف مسجّل',                               '#64748b'],
    ];
}

function complianceScopeKey(): string {
    $ids = function_exists('activeSchoolIds') ? activeSchoolIds() : [];
    return $ids ? implode(',', array_map('intval', $ids)) : 'all';
}

function complianceYear(): string {
    $sy = activeSchoolYear();
    if ($sy === 'all' || !preg_match('/^\d{4}-\d{4}$/', (string)$sy)) $sy = currentSchoolYear();
    return $sy;
}

function complianceEmpName(array $e): string {
    $n = trim(($e['first_name_ar'] ?: ($e['first_name_fr'] ?? '')) . ' ' . ($e['last_name_ar'] ?: ($e['last_name_fr'] ?? '')));
    return $n !== '' ? $n : ('#' . (int)($e['id'] ?? 0));
}

function complianceMonthLabel(int $m, int $y): string {
    static $ar = [1=>'كانون الثاني',2=>'شباط',3=>'آذار',4=>'نيسان',5=>'أيار',6=>'حزيران',7=>'تموز',8=>'آب',9=>'أيلول',10=>'تشرين الأول',11=>'تشرين الثاني',12=>'كانون الأول'];
    return ($ar[$m] ?? $m) . ' ' . $y;
}

function complianceFmt($v): string { return number_format((float)$v, 0, '.', ','); }

/**
 * النسبة المئوية الفاعلة لشهر معيّن من بنود الموظف (مع النوافذ الشهرية) — نفس منطق المحرّك.
 */
function compliancePercentForMonth(array $rows, int $month): float {
    $pct = 0.0;
    foreach ($rows as $r) {
        $s = $r['start_month']; $e = $r['end_month'];
        if ($s !== null && $e !== null) {
            $s = (int)$s; $e = (int)$e;
            if ($s <= $e) { if ($month < $s || $month > $e) continue; }
            else { if ($month < $s && $month > $e) continue; }
        }
        $pct += (float)$r['amount'];
    }
    return $pct;
}

/**
 * يبني كل بنود التقرير للنطاق الحالي (المدارس المختارة) والسنة المعروضة.
 * كل بند: key, rule, emp_id, emp_name, school_id, school_name, sy, violation, fix, auto (تصحيح آلي متاح؟), link, data
 */
function complianceItems(PDO $db, string $sy): array {
    $items = [];
    $sc = schoolScopeSql('e.school_id');
    $schools = [];
    foreach ($db->query("SELECT id, name_ar, name_fr FROM schools")->fetchAll(PDO::FETCH_ASSOC) as $s) $schools[(int)$s['id']] = $s['name_ar'] ?: $s['name_fr'];
    [$yf, $yp] = yearEmploymentFilter($sy, 'e.');
    $nm = "CONCAT(COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr),' ',COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr))";
    $add = function (string $rule, array $e, string $violation, string $fix, bool $auto, array $data = [], string $keySuffix = '') use (&$items, $schools, $sy) {
        $eid = (int)($e['id'] ?? 0);
        $items[] = [
            'key' => $rule . '|' . $eid . '|' . $sy . ($keySuffix !== '' ? '|' . $keySuffix : ''),
            'rule' => $rule, 'emp_id' => $eid, 'emp_name' => $eid ? complianceEmpName($e) : '—',
            'school_id' => (int)($e['school_id'] ?? 0), 'school_name' => $schools[(int)($e['school_id'] ?? 0)] ?? '',
            'sy' => $sy, 'violation' => $violation, 'fix' => $fix, 'auto' => $auto,
            'link' => $eid ? (BASE_URL . 'pages/employees.php?action=edit&id=' . $eid) : '',
            'data' => $data,
        ];
    };
    $q = function (string $sql, array $p = []) use ($db) { $st = $db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); };
    [$y1, $y2] = schoolYearToYears($sy);
    $prevSy = ($y1 - 1) . '-' . $y1;

    // ── 1) الدرجة ≠ القانون (الملاك) ──
    try {
        $ids = activeSchoolIds();
        foreach (lawConsistencyCheck($ids ?: null, $sy) as $r) {
            if ($r['ok']) continue;
            $e = ['id' => $r['id'], 'first_name_ar' => $r['name_ar'], 'last_name_ar' => '', 'first_name_fr' => $r['name_fr'], 'last_name_fr' => '', 'school_id' => $r['school_id']];
            if ($r['err'] !== null) {
                $add('grade_law', $e, 'تعذّر حساب درجته من القانون: ' . $r['err'], 'أكمل بملفه الشهادة وتاريخ الدخول وتاريخ الملاك ثم أعد الفحص', false);
            } else {
                $add('grade_law', $e,
                    'الدرجة بملفه ' . rtrim(rtrim(number_format($r['stored'], 1), '0'), '.') . ' والقانون (شهادته + تاريخ دخوله + ملاكه + تدرّج كل سنتين + استثنائية) يعطي ' . rtrim(rtrim(number_format($r['law'], 1), '0'), '.') . ' — فرق ' . rtrim(rtrim(number_format($r['gap'], 1), '0'), '.'),
                    'ضبط درجته على القانون (' . rtrim(rtrim(number_format($r['law'], 1), '0'), '.') . ') وإعادة بناء سلسلة درجاته وإعادة حساب رواتب سنة ' . $sy,
                    true, ['law' => $r['law'], 'stored' => $r['stored']]);
            }
        }
    } catch (Throwable $ex) {}

    // ── 2) أساس الراتب ≠ السلسلة عند الدرجة (الملاك، الدرجة الكاملة) ──
    foreach ($q("SELECT e.*, ms.month, ms.year, ms.base_plus_echelon_lbp bpe, ms.grade_at_month g, sc.new_salary_2017 scale_base
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        JOIN salary_scale_2017 sc ON sc.version_id = 1 AND sc.grade = FLOOR(ms.grade_at_month)
        WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND ms.school_year = ? AND ms.grade_at_month IS NOT NULL
          AND ms.base_plus_echelon_lbp <> sc.new_salary_2017" . $sc . "
          AND NOT (e.last_name_ar LIKE '%حليحل%' AND e.first_name_ar IN ('ريتا','ماريا') AND e.father_name_ar IN ('مارون','الياس')) /* استثناء موثّق: كشفه بالمليم (سلفة) 2026-08-27 */
        GROUP BY ms.employee_id ORDER BY ms.year, ms.month", [$sy]) as $r) {
        $add('base_scale', $r,
            'بشهر ' . complianceMonthLabel((int)$r['month'], (int)$r['year']) . ' أساسه بعد التدرّج ' . complianceFmt($r['bpe']) . ' ل.ل والسلسلة عند الدرجة ' . rtrim(rtrim(number_format((float)$r['g'], 1), '0'), '.') . ' تعطي ' . complianceFmt($r['scale_base']),
            'إعادة حساب رواتب سنة ' . $sy . ' من السلسلة القانونية', true);
    }

    // ── 3) قانون النسبة: الإضافي المخزّن ≠ floor(floor(bpe÷1500)×%) × سعر الشهر (داون للمليون) ──
    $pctEmps = $q("SELECT e.*, b.employee_id bid FROM employees e JOIN (SELECT DISTINCT employee_id FROM employee_bonuses WHERE is_active = 1 AND bonus_type = 'prime_fixe' AND value_type = 'percent' AND (school_year IS NULL OR school_year = ?)) b ON b.employee_id = e.id
        WHERE e.is_deleted = 0" . $sc . $yf, array_merge([$sy], $yp));
    $official = officialUsdRate();
    foreach ($pctEmps as $e) {
        $eid = (int)$e['id'];
        $brows = $q("SELECT * FROM employee_bonuses WHERE employee_id = ? AND is_active = 1 AND bonus_type = 'prime_fixe' AND (school_year IS NULL OR school_year = ?)", [$eid, $sy]);
        $pctRows = array_values(array_filter($brows, fn($b) => $b['value_type'] === 'percent'));
        $amtRows = array_values(array_filter($brows, fn($b) => $b['value_type'] !== 'percent'));
        if (array_filter($amtRows, fn($b) => ($b['currency'] ?? 'LBP') === 'USD')) continue; // مبالغ بالدولار — خارج هذا الفحص
        $months = $q("SELECT month, year, base_plus_echelon_lbp bpe, exchange_rate rate, prime_fixe_lbp prime FROM monthly_salaries WHERE employee_id = ? AND school_year = ? AND is_calculated = 1 AND COALESCE(is_indemnity_month,0) = 0 ORDER BY year, month", [$eid, $sy]);
        foreach ($months as $m) {
            $mo = (int)$m['month'];
            $pct = compliancePercentForMonth($pctRows, $mo);
            $rate = (float)$m['rate'];
            if ($rate <= 0) continue;
            $exp = $pct > 0 ? bonusPercentLbp($pct, (float)$m['bpe'], $rate) : 0.0;
            foreach ($amtRows as $b) {
                $s = $b['start_month']; $en = $b['end_month'];
                if ($s !== null && $en !== null) { $s = (int)$s; $en = (int)$en; if ($s <= $en) { if ($mo < $s || $mo > $en) continue; } else { if ($mo < $s && $mo > $en) continue; } }
                $exp += (float)$b['amount'];
            }
            if (abs($exp - (float)$m['prime']) > 1) {
                $add('pct_law', $e,
                    'بشهر ' . complianceMonthLabel($mo, (int)$m['year']) . ' الإضافي المخزّن ' . complianceFmt($m['prime']) . ' ل.ل — والقانون (نسبة ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . ' % من ' . complianceFmt($m['bpe']) . ' ÷ ' . complianceFmt($official) . ' × سعر الشهر ' . complianceFmt($rate) . ') يعطي ' . complianceFmt($exp),
                    'إعادة حساب رواتب سنة ' . $sy . ' بقانون النسبة', true);
                break;
            }
        }
    }

    // ── 4) أكثر من نسبة فاعلة بنفس السنة بلا نافذة (تُجمع) ──
    foreach ($q("SELECT e.*, GROUP_CONCAT(b.id ORDER BY b.id) ids, GROUP_CONCAT(b.amount ORDER BY b.id) amts FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
        WHERE e.is_deleted = 0 AND b.is_active = 1 AND b.value_type = 'percent' AND b.bonus_type = 'prime_fixe' AND b.start_month IS NULL AND b.school_year = ?" . $sc . "
        GROUP BY b.employee_id HAVING COUNT(*) > 1", [$sy]) as $r) {
        $ids = explode(',', $r['ids']); $amts = explode(',', $r['amts']);
        $lbl = implode(' % + ', array_map(fn($a) => rtrim(rtrim($a, '0'), '.'), $amts)) . ' %';
        $add('multi_percent', $r, 'عنده بنود نسبة فاعلة معاً بسنة ' . $sy . ': ' . $lbl . ' — المحرّك يجمعها فتصير ' . rtrim(rtrim(number_format(array_sum(array_map('floatval', $amts)), 2), '0'), '.') . ' %',
            'الإبقاء على الأحدث (' . rtrim(rtrim(end($amts), '0'), '.') . ' %) وإطفاء الأقدم وإعادة حساب سنة ' . $sy, true, ['keep' => (int)end($ids), 'off' => array_map('intval', array_slice($ids, 0, -1))]);
    }

    // ── 5) الإضافي بالأشهر ≠ مجموع بنوده الثابتة بالليرة (لمن لا نسبة ولا دولار ولا نافذة) ──
    foreach ($q("SELECT e.*, ms.month, ms.year, ms.prime_fixe_lbp stored, t.s expected FROM employees e
        JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ? AND ms.is_calculated = 1 AND COALESCE(ms.is_indemnity_month,0) = 0
        JOIN (SELECT employee_id, SUM(amount) s FROM employee_bonuses WHERE is_active = 1 AND bonus_type = 'prime_fixe' AND school_year = ? AND value_type = 'amount' AND currency = 'LBP' AND start_month IS NULL GROUP BY employee_id) t ON t.employee_id = e.id
        WHERE e.is_deleted = 0" . $sc . "
          AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = e.id AND b.is_active = 1 AND b.bonus_type = 'prime_fixe' AND (b.school_year IS NULL OR b.school_year = ?) AND (b.value_type = 'percent' OR b.currency = 'USD' OR b.start_month IS NOT NULL))
          AND ms.prime_fixe_lbp <> t.s
        GROUP BY e.id ORDER BY ms.year, ms.month", [$sy, $sy, $sy]) as $r) {
        $add('add_stale', $r, 'بشهر ' . complianceMonthLabel((int)$r['month'], (int)$r['year']) . ' الإضافي المخزّن ' . complianceFmt($r['stored']) . ' ل.ل وبند ملفه ' . complianceFmt($r['expected']) . ' ل.ل',
            'إعادة حساب رواتب سنة ' . $sy . ' من بنود ملفه', true);
    }

    // ── 6) إضافي/مكافأة بالأشهر بلا أي بند بملفه ──
    foreach ($q("SELECT e.*, SUM(ms.prime_fixe_lbp + ms.aide_complementaire_lbp) tot, COUNT(*) n FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND ms.school_year = ? AND (ms.prime_fixe_lbp > 0 OR ms.aide_complementaire_lbp > 0)" . $sc . "
          AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = ms.employee_id AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND (b.school_year IS NULL OR b.school_year = ms.school_year))
        GROUP BY ms.employee_id", [$sy]) as $r) {
        $add('ghost_add', $r, 'أشهره (' . (int)$r['n'] . ') فيها إضافي/مكافأة مجموعها ' . complianceFmt($r['tot']) . ' ل.ل بلا أي بند بملفه (رقم عالق)',
            'إعادة حساب سنة ' . $sy . ' من ملفه (يصير الإضافي صفراً) — أو أدخل له بند الإضافي بملفه ثم ارفض هذا التصحيح', true);
    }

    // ── 7) بند إضافي فاعل لكن أشهره بصفر ──
    foreach ($q("SELECT e.* FROM employees e
        WHERE e.is_deleted = 0" . $sc . " AND EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = e.id AND b.is_active = 1 AND b.amount > 0 AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND b.school_year = ? AND b.start_month IS NULL)
          AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ?)
          AND NOT EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND (ms.prime_fixe_lbp > 0 OR ms.aide_complementaire_lbp > 0))", [$sy, $sy, $sy]) as $r) {
        $add('missing_add', $r, 'بملفه بند إضافي/مكافأة فاعل لسنة ' . $sy . ' لكن كل أشهره المخزّنة بصفر (لم يُطبَّق)', 'إعادة حساب سنة ' . $sy . ' من ملفه', true);
    }

    // ── 8) نسبة عند غير الملاك ──
    foreach ($q("SELECT e.*, GROUP_CONCAT(b.id) ids, GROUP_CONCAT(b.amount) amts FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
        WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire' AND b.is_active = 1 AND b.value_type = 'percent' AND (b.school_year IS NULL OR b.school_year = ?)" . $sc . " GROUP BY e.id", [$sy]) as $r) {
        $add('pct_nontit', $r, 'عنده بند نسبة (' . str_replace(',', ' % / ', rtrim($r['amts'], '0')) . ' %) وهو ' . ($r['employee_type'] === 'employe' ? 'موظف إداري' : 'متعاقد') . ' — قانون النسبة للملاك فقط',
            'إطفاء بند النسبة وإعادة حساب سنة ' . $sy . ' (وإدخال مبلغه الثابت بملفه إن لزم)', true, ['off' => array_map('intval', explode(',', $r['ids']))]);
    }

    // ── 9) بند بلا سنة دراسية ──
    foreach ($q("SELECT e.*, GROUP_CONCAT(b.id) ids, COUNT(*) n FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
        WHERE e.is_deleted = 0 AND b.is_active = 1 AND b.school_year IS NULL" . $sc . " GROUP BY e.id") as $r) {
        $add('bonus_nosy', $r, 'عنده ' . (int)$r['n'] . ' بند علاوة بلا سنة دراسية — ينطبق على كل السنين (خطر تكرار)', 'تثبيت هذه البنود على سنة ' . $sy, true, ['ids' => array_map('intval', explode(',', $r['ids']))]);
    }

    // ── 10) تارك عنده رواتب بعد تركه (بنفس السنة — الأشهر التالية لشهر الترك +1) ──
    foreach ($q("SELECT e.*, LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) ld,
            COUNT(*) n, SUM(ms.net_salary_lbp) net, MIN(ms.year*100+ms.month) m1, MAX(ms.year*100+ms.month) m2
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ?
        WHERE e.is_deleted = 0" . $sc . " AND LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) < '9999-12-31'
          AND STR_TO_DATE(CONCAT(ms.year,'-',ms.month,'-01'),'%Y-%m-%d') > DATE_ADD(LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')), INTERVAL 1 MONTH)
        GROUP BY e.id", [$sy]) as $r) {
        $m1 = (int)$r['m1']; $m2 = (int)$r['m2'];
        $add('left_rows', $r, 'ترك بتاريخ ' . formatDate($r['ld']) . ' وعنده ' . (int)$r['n'] . ' أشهر مخزّنة بعد تركه (' . complianceMonthLabel($m1 % 100, intdiv($m1, 100)) . ' → ' . complianceMonthLabel($m2 % 100, intdiv($m2, 100)) . ') مجموع صافيها ' . complianceFmt($r['net']) . ' ل.ل',
            'حذف هذه الأشهر الـ' . (int)$r['n'] . ' (قاعدة التارك: يبقى حتى شهر تركه) — أو أبقِها إن كان استمراره بقرارك', true, ['ld' => $r['ld']]);
    }

    // ── 11) صافي/مستحق لا يساوي مكوّناته ──
    foreach ($q("SELECT e.*, ms.month, ms.year, ms.base_plus_echelon_lbp bpe, ms.extra_lbp ex, ms.prime_fixe_lbp pf, ms.aide_complementaire_lbp ac, ms.total_retenues_lbp ret, ms.net_salary_lbp net, ms.transport_lbp tr, COALESCE(ms.family_allowance_lbp,0) fam, ms.total_due_lbp due,
            (SELECT COUNT(*) FROM monthly_salaries p WHERE p.employee_id = e.id AND p.school_year = ? AND p.month = ms.month) prev_has
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND ms.school_year = ?" . $sc . " AND (
              ((ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) - ms.total_retenues_lbp - ms.net_salary_lbp) NOT BETWEEN -1 AND 999
           OR (ms.net_salary_lbp % 1000) <> 0
           OR ABS(ms.net_salary_lbp + ms.transport_lbp + COALESCE(ms.family_allowance_lbp,0) - ms.total_due_lbp) > 1)
        ORDER BY e.id, ms.year, ms.month", [$prevSy, $sy]) as $r) {
        $mo = (int)$r['month']; $yr = (int)$r['year'];
        $gross = (float)$r['bpe'] + (float)$r['ex'] + (float)$r['pf'] + (float)$r['ac'];
        $del = ((int)$r['prev_has'] === 0 && $sy >= '2026-2027');
        $add('net_math', $r,
            'بشهر ' . complianceMonthLabel($mo, $yr) . ': الإجمالي ' . complianceFmt($gross) . ' − المحسومات ' . complianceFmt($r['ret']) . ' ≠ الصافي ' . complianceFmt($r['net']) . ' (المستحق ' . complianceFmt($r['due']) . ')',
            $del ? 'حذف صف ' . complianceMonthLabel($mo, $yr) . ' (لا يوجد له هذا الشهر بسنة ' . $prevSy . ' — عقده لا يشمله)' : 'إعادة حساب شهر ' . complianceMonthLabel($mo, $yr) . ' من ملفه',
            true, ['month' => $mo, 'year' => $yr, 'delete' => $del], $yr . '-' . $mo);
    }

    // ── 12) صف بسعر صرف صفر (لغير التاركين) ──
    foreach ($q("SELECT e.*, ms.month, ms.year FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND ms.school_year = ? AND COALESCE(ms.exchange_rate,0) <= 0" . $sc . "
          AND LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) = '9999-12-31'
        GROUP BY e.id ORDER BY ms.year, ms.month", [$sy]) as $r) {
        $add('row_rate0', $r, 'صف ' . complianceMonthLabel((int)$r['month'], (int)$r['year']) . ' مخزّن بسعر صرف صفر أو فارغ (الدولار فيه غلط)', 'إعادة حساب سنة ' . $sy . ' بأسعار الصرف المسجّلة', true);
    }

    // ── 13) موظف فاعل بلا رواتب بسنة مفتوحة لمدرسته ──
    foreach ($q("SELECT e.* FROM employees e
        WHERE e.is_deleted = 0 AND e.status = 'actif'" . $sc . $yf . "
          AND LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) = '9999-12-31'
          AND NOT EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ?)
          AND EXISTS (SELECT 1 FROM monthly_salaries m2 JOIN employees e2 ON e2.id = m2.employee_id WHERE e2.school_id = e.school_id AND m2.school_year = ?)
        ORDER BY e.school_id, e.id", array_merge($yp, [$sy, $sy])) as $r) {
        $hasCfg = ($r['employee_type'] === 'enseignant_titulaire') || (float)$r['base_salary_usd'] > 0 || (float)$r['contract_salary_lbp'] > 0;
        $add('active_nomonths', $r, 'فاعل بملفه ولا راتب مخزّناً له بسنة ' . $sy . ' مع أنّ مدرسته سنتها مفتوحة',
            $hasCfg ? 'احتساب رواتب سنة ' . $sy . ' من ملفه' : 'أدخل إعداد راتبه (الأساس/العقد) بملفه أو تاريخ تركه — لا يُحتسب بلا إعداد', $hasCfg);
    }

    // ── 14) ملاك بلا شهادة ──
    foreach ($q("SELECT e.* FROM employees e WHERE e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND (e.diploma IS NULL OR e.diploma = '')" . $sc . $yf . " ORDER BY e.school_id, e.id", $yp) as $r) {
        $add('no_diploma', $r, 'أستاذ ملاك بلا شهادة بملفه — البرنامج يفترض «قسم ثاني» (درجة دخول 1) فقد تكون درجته وراتبه أقل من حقّه', 'أدخل شهادته بملفه (تُحسب درجته وراتبه تلقائياً)', false);
    }

    // ── 15) مكرّرون ──
    foreach ($q("SELECT e1.*, e2.id id2 FROM employees e1 JOIN employees e2 ON e2.id > e1.id AND e2.school_id = e1.school_id
          AND e2.first_name_ar = e1.first_name_ar AND e2.last_name_ar = e1.last_name_ar AND COALESCE(e2.father_name_ar,'') = COALESCE(e1.father_name_ar,'')
        WHERE e1.is_deleted = 0 AND e2.is_deleted = 0 AND e1.status = 'actif' AND e2.status = 'actif'" . str_replace('e.school_id', 'e1.school_id', $sc)) as $r) {
        $add('dupes', $r, 'موظفان فاعلان بنفس الاسم والشهرة والأب بنفس المدرسة (#' . (int)$r['id'] . ' و#' . (int)$r['id2'] . ')', 'راجع الملفّين وادمجهما أو احذف الزائد من صفحة الموظفين (أو أبقِهما إن كانا شخصين)', false, ['id2' => (int)$r['id2']]);
    }

    // ── 16) شهر بلا سعر صرف (حتى الشهر الحالي فقط) ──
    $nowYM = (int)date('Y') * 100 + (int)date('n');
    foreach ($q("SELECT m.year, m.month FROM (SELECT DISTINCT year, month FROM monthly_salaries WHERE school_year = ?) m
        LEFT JOIN exchange_rates r ON r.year = m.year AND r.month = m.month WHERE r.rate IS NULL ORDER BY m.year, m.month", [$sy]) as $r) {
        if ((int)$r['year'] * 100 + (int)$r['month'] > $nowYM) continue;
        $items[] = ['key' => 'rate_missing|0|' . $sy . '|' . $r['year'] . '-' . $r['month'], 'rule' => 'rate_missing', 'emp_id' => 0, 'emp_name' => '—',
            'school_id' => 0, 'school_name' => '', 'sy' => $sy,
            'violation' => 'شهر ' . complianceMonthLabel((int)$r['month'], (int)$r['year']) . ' بلا سعر صرف مسجّل — الرواتب تستعمل آخر سعر معروف',
            'fix' => 'سجّل سعر الشهر بصفحة أسعار الصرف', 'auto' => false, 'link' => BASE_URL . 'pages/exchange_rates.php', 'data' => []];
    }

    return $items;
}

/** القرارات المسجّلة: key → صف */
function complianceDecisions(PDO $db): array {
    complianceEnsureTable($db);
    $out = [];
    try { foreach ($db->query("SELECT * FROM compliance_decisions")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['item_key']] = $r; } catch (Throwable $e) {}
    return $out;
}

/**
 * تقسيم البنود: pending (بانتظار قراره) / rejected (تركها بقراره) — والمصحَّح تلقائياً من الجدول.
 */
function complianceSplit(array $items, array $decisions): array {
    $pending = []; $rejected = [];
    foreach ($items as $it) {
        $d = $decisions[$it['key']] ?? null;
        if ($d && $d['decision'] === 'rejected') { $it['decision'] = $d; $rejected[] = $it; }
        else $pending[] = $it; // approved لكنها ما زالت موجودة = التصحيح لم يُزلها → تعود للانتظار
    }
    $auto = array_values(array_filter($decisions, fn($d) => $d['decision'] === 'auto'));
    $applied = array_values(array_filter($decisions, fn($d) => $d['decision'] === 'approved'));
    usort($auto, fn($a, $b) => strcmp((string)$b['decided_at'], (string)$a['decided_at']));
    usort($applied, fn($a, $b) => strcmp((string)$b['decided_at'], (string)$a['decided_at']));
    return [$pending, $rejected, $auto, $applied];
}

/** عدد المعلّق المخزّن للشارة بالقائمة (يُحدَّث عند كل بناء للتقرير) */
function compliancePendingCount(): int {
    return (int)getSetting('compliance_pending_' . complianceScopeKey(), 0);
}

function complianceBuild(PDO $db): array {
    $sy = complianceYear();
    $items = complianceItems($db, $sy);
    $dec = complianceDecisions($db);
    [$pending, $rejected, $auto, $applied] = complianceSplit($items, $dec);
    try { setSetting('compliance_pending_' . complianceScopeKey(), (string)count($pending)); } catch (Throwable $e) {}
    return ['sy' => $sy, 'items' => $items, 'pending' => $pending, 'rejected' => $rejected, 'auto' => $auto, 'applied' => $applied];
}

/** تسجيل تصحيح تلقائي بالتقرير (يستدعيه الشفاء الذاتي) */
function complianceLogAuto(PDO $db, string $rule, int $empId, string $sy, string $empName, string $violation, string $fix, string $result): void {
    complianceEnsureTable($db);
    try {
        $db->prepare("INSERT INTO compliance_decisions (item_key, rule_key, employee_id, school_id, school_year, emp_name, violation, fix, decision, result, decided_by, decided_at, created_at)
                      VALUES (?,?,?,?,?,?,?,?,'auto',?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE result = VALUES(result), decided_at = NOW()")
           ->execute([$rule . '|' . $empId . '|' . $sy . '|auto' . date('YmdHis'), $rule, $empId, (int)$db->query("SELECT school_id FROM employees WHERE id = $empId")->fetchColumn(), $sy, $empName, $violation, $fix, $result, 'البرنامج']);
    } catch (Throwable $e) {}
}

/** تنفيذ التصحيح المقترح لبند — يرجّع نصّ النتيجة */
function complianceApply(PDO $db, array $it): string {
    require_once __DIR__ . '/payroll_calculator.php';
    $eid = (int)$it['emp_id']; $sy = (string)$it['sy']; $d = $it['data'] ?? [];
    $recalcYear = function () use ($eid, $sy) { return (int)recalcEmployeeYear($eid, $sy); };
    switch ($it['rule']) {
        case 'grade_law':
            $old = (float)$db->query("SELECT current_grade FROM employees WHERE id = $eid")->fetchColumn();
            $db->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([(float)$d['law'], $eid]);
            try { buildLegalGradeHistory($eid); } catch (Throwable $e) {}
            $n = $recalcYear();
            logAudit('compliance_grade_law', 'employees', $eid, ['current_grade' => $old], ['current_grade' => (float)$d['law'], 'sy' => $sy]);
            return 'الدرجة ' . rtrim(rtrim(number_format($old, 1), '0'), '.') . ' → ' . rtrim(rtrim(number_format((float)$d['law'], 1), '0'), '.') . ' وأُعيد حساب ' . $n . ' شهراً';
        case 'base_scale': case 'pct_law': case 'add_stale': case 'ghost_add': case 'missing_add': case 'row_rate0': case 'active_nomonths':
            $n = $recalcYear();
            return 'أُعيد حساب ' . $n . ' شهراً بسنة ' . $sy;
        case 'multi_percent': case 'pct_nontit':
            $off = array_map('intval', (array)($d['off'] ?? []));
            if ($off) $db->exec("UPDATE employee_bonuses SET is_active = 0 WHERE id IN (" . implode(',', $off) . ")");
            $n = $recalcYear();
            logAudit('compliance_bonus_off', 'employee_bonuses', $eid, null, ['off' => $off, 'sy' => $sy]);
            return 'أُطفئت ' . count($off) . ' بند وأُعيد حساب ' . $n . ' شهراً';
        case 'bonus_nosy':
            $ids = array_map('intval', (array)($d['ids'] ?? []));
            if ($ids) $db->prepare("UPDATE employee_bonuses SET school_year = ? WHERE id IN (" . implode(',', $ids) . ")")->execute([$sy]);
            $n = $recalcYear();
            return 'ثُبّت ' . count($ids) . ' بند على ' . $sy . ' وأُعيد حساب ' . $n . ' شهراً';
        case 'left_rows':
            $ld = (string)($d['ld'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ld)) return 'تاريخ ترك غير صالح';
            $st = $db->prepare("DELETE FROM monthly_salaries WHERE employee_id = ? AND school_year = ? AND STR_TO_DATE(CONCAT(year,'-',month,'-01'),'%Y-%m-%d') > DATE_ADD(?, INTERVAL 1 MONTH)");
            $st->execute([$eid, $sy, $ld]);
            logAudit('compliance_left_rows_delete', 'monthly_salaries', $eid, null, ['sy' => $sy, 'deleted' => $st->rowCount(), 'left' => $ld]);
            return 'حُذف ' . $st->rowCount() . ' شهراً بعد تركه (' . formatDate($ld) . ')';
        case 'net_math':
            $mo = (int)($d['month'] ?? 0); $yr = (int)($d['year'] ?? 0);
            if (!empty($d['delete'])) {
                $st = $db->prepare("DELETE FROM monthly_salaries WHERE employee_id = ? AND school_year = ? AND month = ? AND year = ?");
                $st->execute([$eid, $sy, $mo, $yr]);
                logAudit('compliance_row_delete', 'monthly_salaries', $eid, null, ['sy' => $sy, 'month' => $mo, 'year' => $yr]);
                return 'حُذف صف ' . complianceMonthLabel($mo, $yr);
            }
            try { (new PayrollCalculator($eid, $mo, $yr))->calculateAndSave(); } catch (Throwable $e) { return 'تعذّر الحساب: ' . $e->getMessage(); }
            return 'أُعيد حساب ' . complianceMonthLabel($mo, $yr);
    }
    return 'لا تصحيح آلياً لهذه المخالفة — راجع ملفه';
}

/** معالج القرارات (موافق/لا/إعادة فتح) — يُستدعى من لوحة القيادة وصفحة التقرير */
function handleCompliancePost(PDO $db, string $redirectTo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['action'] ?? '', ['comp_approve', 'comp_reject', 'comp_reopen', 'comp_approve_rule'], true)) return;
    requireCsrf();
    if (!canEdit()) { $_SESSION['flash_error'] = 'غير مسموح — حساب قراءة فقط.'; header('Location: ' . $redirectTo); exit; }
    complianceEnsureTable($db);
    $act = $_POST['action'];
    $who = (string)($_SESSION['username'] ?? '');
    if ($act === 'comp_reopen') {
        $db->prepare("DELETE FROM compliance_decisions WHERE item_key = ? AND decision = 'rejected'")->execute([(string)($_POST['key'] ?? '')]);
        $_SESSION['flash_success'] = 'أُعيد فتح المخالفة — ستظهر بانتظار قرارك.';
        header('Location: ' . $redirectTo); exit;
    }
    $sy = complianceYear();
    $items = complianceItems($db, $sy);
    $byKey = []; foreach ($items as $it) $byKey[$it['key']] = $it;
    $keys = [];
    if ($act === 'comp_approve_rule') {
        $rule = (string)($_POST['rule'] ?? '');
        foreach ($items as $it) if ($it['rule'] === $rule && $it['auto'] && $rule !== 'left_rows' && $rule !== 'net_math') $keys[] = $it['key'];
        $act = 'comp_approve';
    } else {
        $keys = [(string)($_POST['key'] ?? '')];
    }
    $done = 0; $msgs = [];
    $ins = $db->prepare("INSERT INTO compliance_decisions (item_key, rule_key, employee_id, school_id, school_year, emp_name, violation, fix, decision, result, decided_by, decided_at, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE decision = VALUES(decision), result = VALUES(result), decided_by = VALUES(decided_by), decided_at = NOW(), violation = VALUES(violation), fix = VALUES(fix)");
    foreach ($keys as $k) {
        $it = $byKey[$k] ?? null;
        if (!$it) continue;
        if ($act === 'comp_approve') {
            if (!$it['auto']) continue;
            try { $res = complianceApply($db, $it); } catch (Throwable $e) { $res = 'خطأ: ' . $e->getMessage(); }
            $ins->execute([$k, $it['rule'], $it['emp_id'] ?: null, $it['school_id'] ?: null, $sy, $it['emp_name'], $it['violation'], $it['fix'], 'approved', $res, $who]);
            $msgs[] = $it['emp_name'] . ': ' . $res;
        } else {
            $ins->execute([$k, $it['rule'], $it['emp_id'] ?: null, $it['school_id'] ?: null, $sy, $it['emp_name'], $it['violation'], $it['fix'], 'rejected', null, $who]);
            $msgs[] = $it['emp_name'] . ': تُرك كما هو بقرارك';
        }
        $done++;
    }
    // تحديث عداد الشارة
    try { complianceBuild($db); } catch (Throwable $e) {}
    if ($done) $_SESSION['flash_success'] = ($act === 'comp_approve' ? '✅ صُحِّح ' : '⏸️ تُرك ') . $done . ' — ' . implode(' · ', array_slice($msgs, 0, 8)) . (count($msgs) > 8 ? '…' : '');
    else $_SESSION['flash_error'] = 'لم يُنفَّذ شيء — المخالفة لم تعد موجودة أو لا تصحيح آلياً لها.';
    header('Location: ' . $redirectTo); exit;
}

/** أزرار القرار لبند */
function complianceButtons(array $it, string $formAction = ''): string {
    if (!canEdit()) return '<span class="text-muted">قراءة فقط</span>';
    $act = $formAction !== '' ? ' action="' . e($formAction) . '"' : '';
    $h = '';
    if ($it['auto']) {
        $h .= '<form method="post"' . $act . ' style="display:inline" onsubmit="return confirm(\'' . e($it['fix']) . ' — لـ' . e($it['emp_name']) . '؟\')">' . csrfField()
            . '<input type="hidden" name="action" value="comp_approve"><input type="hidden" name="key" value="' . e($it['key']) . '">'
            . '<button class="btn btn-sm btn-success"><i class="fas fa-check"></i> موافق — صحّح</button></form> ';
    } elseif ($it['link'] !== '') {
        $h .= '<a class="btn btn-sm btn-primary" href="' . e($it['link']) . '"><i class="fas fa-pen"></i> افتح الملف</a> ';
    }
    $h .= '<form method="post"' . $act . ' style="display:inline">' . csrfField()
        . '<input type="hidden" name="action" value="comp_reject"><input type="hidden" name="key" value="' . e($it['key']) . '">'
        . '<button class="btn btn-sm btn-light" title="يُسجَّل قرارك ولا تعود هذه المخالفة تظهر (تقدر تعيد فتحها من صفحة التقرير)"><i class="fas fa-xmark"></i> لا — اتركه</button></form>';
    return $h;
}

/** جدول بنود مجمّعة حسب القاعدة */
function renderComplianceTable(array $items, string $formAction = '', bool $collapsed = false, int $limitPerRule = 0): void {
    $rules = complianceRules();
    $byRule = [];
    foreach ($items as $it) $byRule[$it['rule']][] = $it;
    foreach ($rules as $rk => $meta) {
        if (empty($byRule[$rk])) continue;
        $rows = $byRule[$rk];
        $n = count($rows);
        $canBulk = canEdit() && $rk !== 'left_rows' && $rk !== 'net_math' && count(array_filter($rows, fn($r) => $r['auto'])) > 1;
        $tag = $collapsed ? 'details' : 'div';
        echo '<' . $tag . ' class="comp-rule" style="margin-bottom:10px;border:1px solid var(--gray-200);border-radius:8px;padding:6px 10px"' . ($collapsed && $n <= 3 ? ' open' : '') . '>';
        echo ($collapsed ? '<summary style="cursor:pointer;padding:4px 0">' : '<div style="padding:4px 0">')
           . '<strong style="color:' . $meta[2] . '"><i class="fas fa-triangle-exclamation"></i> ' . e($meta[1]) . '</strong> <span dir="ltr" style="opacity:.75">/ ' . e($meta[0]) . '</span> — <span class="badge" style="background:' . $meta[2] . ';color:#fff">' . $n . '</span>'
           . ($collapsed ? '</summary>' : '</div>');
        if ($canBulk) {
            echo '<form method="post"' . ($formAction !== '' ? ' action="' . e($formAction) . '"' : '') . ' style="margin:2px 0 6px" onsubmit="return confirm(\'تصحيح كل بنود «' . e($meta[1]) . '» (' . $n . ')؟\')">' . csrfField()
               . '<input type="hidden" name="action" value="comp_approve_rule"><input type="hidden" name="rule" value="' . e($rk) . '">'
               . '<button class="btn btn-sm btn-success"><i class="fas fa-check-double"></i> موافق على الكل بهذه المخالفة (' . $n . ')</button></form>';
        }
        echo '<div class="table-wrapper"><table class="table" style="margin:0"><thead><tr><th style="width:16%">الأستاذ / المدرسة</th><th>المخالفة</th><th style="width:28%">التصحيح المقترح</th><th style="width:16%">قرارك</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($rows as $it) {
            if ($limitPerRule && $shown >= $limitPerRule) { echo '<tr><td colspan="4" class="text-muted">… و' . ($n - $shown) . ' غيره — بصفحة التقرير الكاملة</td></tr>'; break; }
            $shown++;
            echo '<tr><td>' . ($it['link'] !== '' && $it['emp_id'] ? '<a href="' . e($it['link']) . '"><strong>' . e($it['emp_name']) . '</strong></a>' : '<strong>' . e($it['emp_name']) . '</strong>')
               . ($it['school_name'] !== '' ? '<br><small class="text-muted">' . e($it['school_name']) . '</small>' : '') . '</td>'
               . '<td>' . e($it['violation']) . '</td><td>' . e($it['fix']) . '</td><td style="white-space:nowrap">' . complianceButtons($it, $formAction) . '</td></tr>';
        }
        echo '</tbody></table></div></' . $tag . '>';
    }
}

/** مساج لوحة القيادة: «قرارات مطلوبة» */
function renderCompliancePending(array $rep, bool $compact = true): void {
    $pending = $rep['pending'];
    $sy = $rep['sy'];
    $autoN = count($rep['auto']);
    if (!$pending && !$autoN) return;
    $n = count($pending);
    ?>
    <div class="card no-print" id="compPending" style="border:2px solid <?= $n ? '#b91c1c' : '#16a34a' ?>;margin-bottom:16px">
        <div class="card-header" style="background:<?= $n ? '#fef2f2' : '#f0fdf4' ?>"><h3 style="color:<?= $n ? '#991b1b' : '#166534' ?>"><i class="fas fa-scale-balanced"></i>
            Rapport de conformité / تقرير المخالفات والتصحيحات — سنة <?= e($sy) ?><?= $n ? ' — <span style="background:#dc2626;color:#fff;border-radius:999px;padding:1px 10px">' . $n . ' قرار مطلوب</span>' : ' — لا مخالفات' ?></h3></div>
        <div class="card-body">
            <p style="color:var(--gray-600);margin-top:0">البرنامج فحص كل الأساتذة والموظفين بالنطاق المختار على القانون وعلى بنود ملفاتهم. لكل مخالفة: نوعها بالأرقام، والتصحيح المقترح، وقرارك:
                <strong style="color:#166534">موافق — صحّح</strong> ينفّذ التصحيح فوراً، و<strong>لا — اتركه</strong> يسجّل قرارك ولا يعود يظهر. لا يُصحَّح شيء بلا موافقتك.
                <a href="<?= BASE_URL ?>pages/compliance.php"><i class="fas fa-file-lines"></i> التقرير الكامل (المعلّق + ما تركته + ما صُحِّح)</a></p>
            <?php if ($pending): renderComplianceTable($pending, BASE_URL . 'index.php', $compact, $compact ? 5 : 0); endif; ?>
            <?php if ($autoN): ?>
            <details style="margin-top:8px"><summary style="cursor:pointer;color:#166534;font-weight:700"><i class="fas fa-wand-magic-sparkles"></i> صُحِّح تلقائياً (مكرّر حرفي لا يحتاج قراراً) — <?= $autoN ?></summary>
                <ul style="margin:6px 0 0;padding-inline-start:18px">
                <?php foreach (array_slice($rep['auto'], 0, 10) as $a): ?>
                    <li><strong><?= e($a['emp_name']) ?></strong> (<?= e($a['school_year']) ?>): <?= e($a['violation']) ?> → <?= e($a['result']) ?> <small class="text-muted"><?= e($a['decided_at']) ?></small></li>
                <?php endforeach; ?>
                </ul></details>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
