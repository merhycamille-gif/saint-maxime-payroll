<?php
/**
 * 🔎 الفحص الرسمي النهائي للبيانات (2026-08-29 — «شيك على كل البرنامج، ما بدي أغلاط»)
 * قواعد سلامة تُطبَّق على قاعدة البيانات نفسها (المحلية أو الأونلاين) وتُرجِع لكل قاعدة:
 * عدد المخالفات + عيّنة أسماء. صفر بكل القواعد = البرنامج سليم. تُعرض ببطاقة «الفحص الرسمي»
 * بصفحة فحص الصحة، وتُستعمل بأداة tools/data_audit.php للمقارنة بين النسخ.
 * لا تعدّل أي بيانات — قراءة فقط.
 */

function dataAuditRules(PDO $db, string $sy = '2025-2026'): array {
    $out = [];
    $q = function (string $sql, array $p = []) use ($db) { $st = $db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); };
    $add = function (string $key, string $label, array $rows, string $nameCol = 'nm') use (&$out) {
        $out[] = ['key' => $key, 'label' => $label, 'n' => count($rows), 'samples' => array_slice(array_map(fn($r) => $r[$nameCol] ?? json_encode($r, JSON_UNESCAPED_UNICODE), $rows), 0, 8)];
    };
    $nm = "CONCAT(e.first_name_ar,' ',e.last_name_ar)";

    // 1) إضافي/مكافأة عالقان بالأشهر بلا أي سطر علاوة (فعّال أو مطفأ) — «شبح» (قصة كلاديس)
    $add('ghost_add', 'إضافي أو مكافأة مخزّنان بالأشهر بلا أي سطر بملف الموظف (رقم عالق)', $q("
        SELECT $nm nm, ms.school_year FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0 AND ms.school_year=? AND (ms.prime_fixe_lbp>0 OR ms.aide_complementaire_lbp>0)
          AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id=ms.employee_id AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND (b.school_year IS NULL OR b.school_year=ms.school_year))
        GROUP BY ms.employee_id, ms.school_year", [$sy]));

    // 2) العكس: سطر إضافي/مكافأة فعّال لكل السنة بمبلغ لكن الأشهر المخزّنة بصفر (لم يُطبَّق)
    $add('missing_add', 'سطر إضافي/مكافأة فعّال بملفه لكن أشهره بصفر (لم يُطبَّق)', $q("
        SELECT $nm nm FROM employees e
        WHERE e.is_deleted=0 AND EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id=e.id AND b.is_active=1 AND b.amount>0 AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND b.school_year=? AND b.start_month IS NULL)
          AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id=e.id AND ms.school_year=?)
          AND NOT EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id=e.id AND ms.school_year=? AND (ms.prime_fixe_lbp>0 OR ms.aide_complementaire_lbp>0))", [$sy, $sy, $sy]));

    // 3) الإضافي المخزّن ≠ مجموع الأسطر الثابتة بالليرة (لمن لا نسبة ولا دولار ولا فترات عنده)
    $add('add_mismatch', 'الإضافي المخزّن بتشرين الثاني ≠ مجموع أسطره الثابتة بالليرة (رقم قديم)', $q("
        SELECT $nm nm, ms.prime_fixe_lbp stored, t.s expected FROM employees e
        JOIN monthly_salaries ms ON ms.employee_id=e.id AND ms.school_year=? AND ms.month=11
        JOIN (SELECT employee_id, SUM(amount) s FROM employee_bonuses WHERE is_active=1 AND bonus_type='prime_fixe' AND school_year=? AND value_type='amount' AND currency='LBP' AND start_month IS NULL GROUP BY employee_id) t ON t.employee_id=e.id
        WHERE e.is_deleted=0
          AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id=e.id AND b.is_active=1 AND b.bonus_type='prime_fixe' AND (b.school_year IS NULL OR b.school_year=?) AND (b.value_type='percent' OR b.currency='USD' OR b.start_month IS NOT NULL))
          AND ms.prime_fixe_lbp <> t.s", [$sy, $sy, $sy]));

    // 4) الأرقام تركب: الصافي = floor((الأساس+الدرجة+الإضافي+المكافأة+extra) − المحسومات، للألف) ؛ المستحق = الصافي + النقل + العائلية
    $add('net_math', 'صافي لا يساوي (الإجمالي − المحسومات) منزَّلاً للألف أو مستحق لا يساوي (الصافي + النقل + العائلية)', $q("
        SELECT $nm nm, ms.year, ms.month FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0 AND ms.school_year=? AND (
              ((ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) - ms.total_retenues_lbp - ms.net_salary_lbp) NOT BETWEEN -1 AND 999 /* الصافي داون للألف (قراره 2026-09-04) */
           OR (ms.net_salary_lbp % 1000) <> 0
           OR ABS(ms.net_salary_lbp + ms.transport_lbp + COALESCE(ms.family_allowance_lbp,0) - ms.total_due_lbp) > 1)
        GROUP BY ms.employee_id", [$sy]));

    // 5) الملاك: الأساس بعد التدرّج ≠ سلسلة الدرجة الكاملة للشهر
    $add('base_scale', 'أستاذ ملاك أساسه بعد التدرّج لا يطابق السلسلة عند درجته (الدرجة الكاملة)', $q("
        SELECT $nm nm, ms.month, ms.grade_at_month g, ms.base_plus_echelon_lbp b, sc.new_salary_2017 s
        FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        JOIN salary_scale_2017 sc ON sc.version_id=1 AND sc.grade=FLOOR(ms.grade_at_month)
        WHERE e.is_deleted=0 AND e.employee_type='enseignant_titulaire' AND ms.school_year=? AND ms.grade_at_month IS NOT NULL
          AND ms.base_plus_echelon_lbp <> sc.new_salary_2017
          AND NOT (e.last_name_ar LIKE '%حليحل%' AND e.first_name_ar IN ('ريتا','ماريا') AND e.father_name_ar IN ('مارون','الياس'))  -- استثناء موثّق: كشفه بالمليم (سلفة) 2026-08-27
        GROUP BY ms.employee_id", [$sy]));

    // 6) الملاك: الدرجة الحالية ≠ آخر درجة بسجلّه (سلسلة مكسورة)
    $add('grade_chain', 'أستاذ ملاك درجته الحالية لا تساوي آخر درجة بسجلّ درجاته المحسوبة', $q("
        SELECT $nm nm, e.current_grade cg, (SELECT h.grade_after FROM employee_grade_history h WHERE h.employee_id=e.id AND (h.counted=1 OR h.reason='titularization') AND h.change_date<=CURDATE() ORDER BY h.change_date DESC, h.id DESC LIMIT 1) last
        FROM employees e WHERE e.is_deleted=0 AND e.employee_type='enseignant_titulaire' AND e.status='actif'
        HAVING last IS NOT NULL AND ABS(cg-last) > 0.01"));

    // 7) الملاك بلا شهادة (درجة الدخول تُفترض 1)
    $add('no_diploma', 'أستاذ ملاك فاعل بلا شهادة بملفه (البرنامج يفترض قسم ثاني = درجة 1)', $q("
        SELECT $nm nm FROM employees e WHERE e.is_deleted=0 AND e.status='actif' AND e.employee_type='enseignant_titulaire' AND (e.diploma IS NULL OR e.diploma='')"));

    // 8) الملاك بلا تاريخ ملاك، أو ملاك قبل دخول المدرسة
    $add('dates', 'أستاذ ملاك بلا تاريخ دخول الملاك أو ملاكه قبل دخوله المدرسة', $q("
        SELECT $nm nm FROM employees e WHERE e.is_deleted=0 AND e.status='actif' AND e.employee_type='enseignant_titulaire'
          AND (e.titularization_date IS NULL OR (e.hire_date IS NOT NULL AND e.titularization_date < e.hire_date))"));

    // 9) نسبة ٪ عند غير الملاك (النسبة من أساس السلسلة — لا معنى لها للمتعاقد/الموظف)
    $add('pct_nontit', 'سطر نسبة ٪ فعّال عند متعاقد أو موظف (النسبة للملاك فقط)', $q("
        SELECT $nm nm FROM employees e JOIN employee_bonuses b ON b.employee_id=e.id AND b.is_active=1 AND b.value_type='percent' AND (b.school_year IS NULL OR b.school_year=?)
        WHERE e.is_deleted=0 AND e.employee_type<>'enseignant_titulaire' GROUP BY e.id", [$sy]));

    // 10) سطر علاوة بلا سنة (ينطبق على كل السنين)
    $add('bonus_nosy', 'سطر علاوة فعّال بلا سنة دراسية (ينطبق على كل السنين — خطر)', $q("
        SELECT $nm nm FROM employees e JOIN employee_bonuses b ON b.employee_id=e.id AND b.is_active=1 AND b.school_year IS NULL WHERE e.is_deleted=0 GROUP BY e.id"));

    // 11) مبلغ علاوة شهري غير معقول (> 400 مليون ل.ل أو > 5000 $)
    $add('bonus_huge', 'مبلغ علاوة شهري غير معقول (> 400,000,000 ل.ل أو > 5,000 $)', $q("
        SELECT $nm nm, b.amount, b.currency FROM employees e JOIN employee_bonuses b ON b.employee_id=e.id AND b.is_active=1 AND b.value_type='amount'
        WHERE e.is_deleted=0 AND ((b.currency='LBP' AND b.amount>400000000) OR (b.currency='USD' AND b.amount>5000)) GROUP BY e.id"));

    // 12) رواتب شهرية غير معقولة (صافي > 600 مليون أو سالب)
    $add('net_huge', 'صافي شهري غير معقول (> 600,000,000 ل.ل أو سالب)', $q("
        SELECT $nm nm, ms.year, ms.month, ms.net_salary_lbp FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0 AND ms.school_year=? AND (ms.net_salary_lbp>600000000 OR ms.net_salary_lbp<0 OR ms.total_due_lbp<0) GROUP BY ms.employee_id", [$sy]));

    // 13) النقل مخزّن بعمودين يجب أن يتساويا
    $add('transport_cols', 'النقل: عمود النقل ≠ عمود تعويض النقل (ازدواج محتمل)', $q("
        SELECT $nm nm, ms.month FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
        WHERE e.is_deleted=0 AND ms.school_year=? AND ms.transport_complement_lbp>0 AND ms.transport_complement_lbp<>ms.transport_lbp GROUP BY ms.employee_id", [$sy]));

    // 14) موظف فاعل بلا أي شهر بالسنة (ما دخل السنة بعد) — للمراجعة
    $add('active_nomonths', 'موظف فاعل بلا أي راتب مخزّن بالسنة', $q("
        SELECT $nm nm FROM employees e WHERE e.is_deleted=0 AND e.status='actif'
          AND COALESCE(e.left_date_cnss, e.left_date_finance, e.left_date_eoc) IS NULL
          AND (e.hire_date IS NULL OR e.hire_date < ?)
          AND NOT EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id=e.id AND ms.school_year=?)", [substr($sy, 5, 4) . '-10-01', $sy]));

    // 15) تارك عنده رواتب بعد تركه
    $add('left_rows', 'تارك عنده رواتب مخزّنة بعد تاريخ تركه', $q("
        SELECT $nm nm FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')) < '9999-12-31'
          AND STR_TO_DATE(CONCAT(ms.year,'-',ms.month,'-01'),'%Y-%m-%d') > DATE_ADD(LEAST(COALESCE(e.left_date_cnss,'9999-12-31'),COALESCE(e.left_date_finance,'9999-12-31'),COALESCE(e.left_date_eoc,'9999-12-31')), INTERVAL 1 MONTH)
        GROUP BY e.id"));

    // 16) أسماء مكرّرة فاعلة بنفس المدرسة (اسم + شهرة + أب)
    $add('dupes', 'موظفان فاعلان بنفس الاسم والشهرة والأب بنفس المدرسة', $q("
        SELECT CONCAT(e1.first_name_ar,' ',e1.father_name_ar,' ',e1.last_name_ar) nm FROM employees e1 JOIN employees e2 ON e2.id>e1.id AND e2.school_id=e1.school_id
          AND e2.first_name_ar=e1.first_name_ar AND e2.last_name_ar=e1.last_name_ar AND COALESCE(e2.father_name_ar,'')=COALESCE(e1.father_name_ar,'')
        WHERE e1.is_deleted=0 AND e2.is_deleted=0 AND e1.status='actif' AND e2.status='actif'"));

    // 17) شهر بلا سعر صرف (يقع على «الأحدث»)
    $add('rate_missing', 'شهر من السنة بلا سعر صرف مسجّل (يُستعمل آخر سعر)', $q("
        SELECT CONCAT(m.year,'-',LPAD(m.month,2,'0')) nm FROM (SELECT DISTINCT year, month FROM monthly_salaries WHERE school_year=?) m
        LEFT JOIN exchange_rates r ON r.year=m.year AND r.month=m.month WHERE r.rate IS NULL ORDER BY m.year, m.month", [$sy]));

    // 18) صف راتب بلا سعر صرف أو بصفر
    $add('row_rate0', 'صف راتب مخزّن بسعر صرف صفر أو فارغ', $q("
        SELECT $nm nm FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=0 AND ms.school_year=? AND COALESCE(ms.exchange_rate,0)<=0 GROUP BY ms.employee_id", [$sy]));

    // 19) الملاك: شهر بلا درجة مسجّلة (grade_at_month فارغ)
    $add('grade_null', 'أستاذ ملاك عنده شهر مخزّن بلا درجة مسجّلة', $q("
        SELECT $nm nm FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=0 AND e.employee_type='enseignant_titulaire' AND ms.school_year=? AND ms.grade_at_month IS NULL GROUP BY ms.employee_id", [$sy]));

    // 20) صفوف رواتب يتيمة لموظف محذوف
    $add('orphan_rows', 'رواتب مخزّنة لموظف محذوف (صفوف يتيمة)', $q("
        SELECT $nm nm FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=1 AND ms.school_year=? GROUP BY ms.employee_id", [$sy]));

    // 21) بند نسبة مئوية فاعل مكرّر بنفس السنة (يُجمع بالمحرّك: 55 % + 55 % = 110 % — حادثة أنطوني جبور 2026-09-04) — كل السنوات
    // (SQL مضمّن لا دالة: الأداة tools/data_audit.php تعمل على دمب الأونلاين بـPDO مجرّد بلا functions.php)
    $add('dup_percent', 'بند نسبة مئوية فاعل مكرّر لنفس الموظف والسنة (تتضاعف نسبة الإضافي)', $q("
        SELECT CONCAT($nm,' ',b.school_year,' (',GROUP_CONCAT(b.id ORDER BY b.id),')') nm FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id
        WHERE b.is_active=1 AND b.value_type='percent' AND b.school_year IS NOT NULL
        GROUP BY b.employee_id, b.school_year, b.bonus_type, b.amount, COALESCE(b.start_month,0), COALESCE(b.end_month,0) HAVING COUNT(*)>1"));

    // 22) نسبتان مختلفتان فاعلتان بنفس السنة بلا نافذة شهرية (تُجمعان معاً) — تحتاج قراره
    $add('multi_percent', 'أكثر من نسبة إضافي فاعلة لنفس الموظف والسنة بلا نافذة شهرية (تُجمع معاً)', $q("
        SELECT CONCAT($nm,' ',b.school_year,' (',GROUP_CONCAT(b.amount ORDER BY b.id),')') nm FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id
        WHERE e.is_deleted=0 AND b.is_active=1 AND b.value_type='percent' AND b.bonus_type='prime_fixe' AND b.start_month IS NULL AND b.school_year IS NOT NULL
        GROUP BY b.employee_id, b.school_year HAVING COUNT(*)>1"));

    return $out;
}
