<?php
/**
 * حساب أرقام «كشف الراتب السنوي» لأستاذ واحد — مصدر موحّد للعرض على الشاشة وللتصدير الرسمي
 * (annual_slip.php للعرض، annual_slip_export.php لتوليد Excel/Word/PDF عبر ReportTable).
 * يضمن تطابق الأرقام في الشاشة والملف الرسمي تماماً (قاعدة: أي تعديل يُطبَّق بمكان واحد).
 *
 * يعيد: ['meta'=>[...], 'rows'=>[ كل شهر => قيَمه ], 'tot'=>[ المجاميع ], 'school'=>صف المدرسة]
 */

if (!function_exists('schoolYearMonthsFor')) {
    /** أشهر السنة الدراسية لأستاذ حسب عدد أشهر الدفع (10 = تشرين→تموز، غيره = تشرين→أيلول). */
    function schoolYearMonthsFor($paymentMonths, $y1, $y2) {
        if ((int)$paymentMonths === 10) {
            return [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]];
        }
        return [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
    }
}

/**
 * يحسب صفوف ومجاميع الكشف السنوي لأستاذ.
 * كل صف يحوي القيم بالليرة (المخزّنة الرسمية) + ما يلزم للعرض المزدوج (دولار) على الشاشة.
 */
function computeAnnualSlip($db, $emp, $schoolYear) {
    [$y1, $y2] = schoolYearToYears($schoolYear);

    $empSchool = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $empSchool->execute([$emp['school_id']]);
    $empSchool = $empSchool->fetch() ?: ['name_fr'=>'', 'name_ar'=>'', 'address'=>''];

    $stmt = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND school_year = ? ORDER BY year, month");
    $stmt->execute([$emp['id'], $schoolYear]);
    $salaries = $stmt->fetchAll();

    $expectedMonths = schoolYearMonthsFor($emp['payment_months_per_year'], $y1, $y2);

    $byMonth = [];
    foreach ($salaries as $s) { $byMonth["{$s['year']}-{$s['month']}"] = $s; }

    $displayRows = []; $seen = [];
    foreach ($expectedMonths as [$m, $y]) {
        $k = "$y-$m"; $seen[$k] = true;
        $row = $byMonth[$k] ?? null;
        $lbl = monthName($m, 'fr', true) . ' ' . $y . (($row && (int)$row['is_indemnity_month']) ? ' (تعويض)' : '');
        $displayRows[] = ['label' => $lbl, 's' => $row];
    }
    foreach ($salaries as $s) {
        $k = "{$s['year']}-{$s['month']}";
        if (empty($seen[$k])) {
            $seen[$k] = true;
            $displayRows[] = ['label' => ((int)$s['is_indemnity_month'] ? 'تعويض ' : '') . monthName((int)$s['month'], 'fr', true) . ' ' . $s['year'], 's' => $s];
        }
    }
    if ((int)$emp['has_13th_month'] === 1) {
        $already13 = false;
        foreach ($displayRows as $dr0) { if (!empty($dr0['s']) && (int)$dr0['s']['is_indemnity_month']) { $already13 = true; break; } }
        if (!$already13 && !empty($salaries)) {
            // شهر 13 = الراتب الأساسي + (اختيارياً الأجر الإضافي و/أو المكافأة حسب خيار ملف الأستاذ)
            // الحسومات متناسبة مع نسبة إجمالي شهر 13 إلى إجمالي الشهر المرجعي (بلا نقل/عائلي).
            $ref = end($salaries);
            $incExtra = !empty($emp['m13_include_extra']);
            $incAide  = !empty($emp['m13_include_aide']);
            $s13 = $ref; // نسخة من آخر شهر ثم نعدّلها
            $s13['is_indemnity_month'] = 1;
            $extra13 = $incExtra ? ((int)$ref['extra_lbp'] + (int)$ref['prime_fixe_lbp']) : 0;
            $aide13  = $incAide ? (int)$ref['aide_complementaire_lbp'] : 0;
            $s13['extra_lbp'] = $incExtra ? (int)$ref['extra_lbp'] : 0;
            $s13['prime_fixe_lbp'] = $incExtra ? (int)$ref['prime_fixe_lbp'] : 0;
            $s13['aide_complementaire_lbp'] = $aide13;
            $salary13 = (int)$ref['base_plus_echelon_lbp'];
            $brut13 = $salary13 + $extra13 + $aide13;
            $brutRef = (int)$ref['base_plus_echelon_lbp'] + (int)$ref['extra_lbp'] + (int)$ref['prime_fixe_lbp'] + (int)$ref['aide_complementaire_lbp'];
            $ratio = $brutRef > 0 ? $brut13 / $brutRef : 1;
            $s13['eoc_grade_lbp'] = 0; // لا حسم درجة/نصف راتب في شهر 13
            $s13['caisse_amount_lbp'] = (int)round((int)$ref['caisse_amount_lbp'] * $ratio);
            $s13['cnss_amount_lbp']   = (int)round((int)$ref['cnss_amount_lbp'] * $ratio);
            $s13['income_tax_lbp']    = (int)round((int)$ref['income_tax_lbp'] * $ratio);
            $s13['taxable_base_lbp']  = (int)round((int)$ref['taxable_base_lbp'] * $ratio);
            $s13['total_retenues_lbp'] = $s13['caisse_amount_lbp'] + $s13['cnss_amount_lbp'] + $s13['income_tax_lbp'];
            $s13['net_salary_lbp'] = $brut13 - $s13['total_retenues_lbp'];
            $s13['family_allowance_lbp'] = 0; // شهر 13: بلا تعويض عائلي ولا نقل
            $s13['transport_lbp'] = 0;
            $s13['total_due_lbp'] = $s13['net_salary_lbp'];
            $rate13 = (float)$ref['exchange_rate'];
            $s13['net_salary_usd'] = $rate13 > 0 ? round($s13['net_salary_lbp'] / $rate13, 2) : 0;
            $s13['total_due_usd']  = $rate13 > 0 ? round($s13['total_due_lbp'] / $rate13, 2) : 0;
            $displayRows[] = ['label' => 'شهر التعويض (13) / Indemnité', 's' => $s13];
        }
    }

    $tot = [
        'base_shown'=>0,'grade_inc'=>0,'base_plus_echelon'=>0,'extra_wage'=>0,'aide'=>0,'brut'=>0,
        'caisse'=>0,'eoc_grade'=>0,'cnss'=>0,'income_tax'=>0,'total_retenues'=>0,'net'=>0,
        'family'=>0,'transport'=>0,'total_due'=>0,
        // نُسخ بالدولار للعرض على الشاشة فقط
        'brut_usd'=>0,'caisse_usd'=>0,'eoc_grade_usd'=>0,'cnss_usd'=>0,'tax_usd'=>0,'totret_usd'=>0,
        'net_usd'=>0,'family_usd'=>0,'transport_usd'=>0,'total_due_usd'=>0,'extra_wage_usd'=>0,'aide_usd'=>0,
    ];

    // سعر الصرف المعروض (آخر سعر غير صفري في السنة)
    $slipRate = 0; foreach ($salaries as $s2) { if ((float)$s2['exchange_rate'] > 0) $slipRate = (float)$s2['exchange_rate']; }

    $prevSal = null;
    $rows = [];
    foreach ($displayRows as $dr) {
        $s = $dr['s'];
        if (!$s) { $rows[] = ['label' => $dr['label'], 's' => null]; continue; }
        $rate = (float)$s['exchange_rate'];
        $usd = function($lbp) use ($rate) { return $rate > 0 ? $lbp / $rate : 0; };

        $primeAide = (int)$s['prime_fixe_lbp'] + (int)$s['aide_complementaire_lbp'];
        $extraWageSlip = (int)$s['extra_lbp'] + (int)$s['prime_fixe_lbp']; // الأجر الإضافي
        $aideSlip = (int)$s['aide_complementaire_lbp'];                    // مكافأة ومساعدة

        $curSal = (int)$s['base_plus_echelon_lbp'];
        if ($prevSal === null) $prevSal = (int)$s['base_salary_lbp'];
        $gradeInc = $curSal - $prevSal; if ($gradeInc < 0) $gradeInc = 0;
        $baseShown = $curSal - $gradeInc;
        $prevSal = $curSal;

        $eocGrade = (int)($s['eoc_grade_lbp'] ?? 0);
        $brut = $curSal + (int)$s['extra_lbp'] + $primeAide;

        $row = [
            'label'        => $dr['label'],
            's'            => $s,
            'rate'         => $rate,
            'base_shown'   => $baseShown,
            'grade_inc'    => $gradeInc,
            'cur_sal'      => $curSal,
            'extra_wage'   => $extraWageSlip,
            'aide'         => $aideSlip,
            'brut'         => $brut,
            'caisse'       => (int)$s['caisse_amount_lbp'],
            'eoc_grade'    => $eocGrade,
            'cnss'         => (int)$s['cnss_amount_lbp'],
            'income_tax'   => (int)$s['income_tax_lbp'],
            'total_retenues'=> (int)$s['total_retenues_lbp'],
            'net'          => (int)$s['net_salary_lbp'],
            'family'       => (int)$s['family_allowance_lbp'],
            'transport'    => (int)$s['transport_lbp'],
            'total_due'    => (int)$s['total_due_lbp'],
        ];
        $rows[] = $row;

        // مجاميع
        $tot['base_shown'] += $baseShown; $tot['grade_inc'] += $gradeInc;
        $tot['base_plus_echelon'] += $curSal; $tot['extra_wage'] += $extraWageSlip; $tot['aide'] += $aideSlip;
        $tot['brut'] += $brut; $tot['caisse'] += $row['caisse']; $tot['eoc_grade'] += $eocGrade;
        $tot['cnss'] += $row['cnss']; $tot['income_tax'] += $row['income_tax'];
        $tot['total_retenues'] += $row['total_retenues']; $tot['net'] += $row['net'];
        $tot['family'] += $row['family']; $tot['transport'] += $row['transport']; $tot['total_due'] += $row['total_due'];
        // دولار (شاشة)
        $tot['extra_wage_usd'] += $usd($extraWageSlip); $tot['aide_usd'] += $usd($aideSlip);
        $tot['brut_usd'] += $usd($brut); $tot['caisse_usd'] += $usd($row['caisse']); $tot['eoc_grade_usd'] += $usd($eocGrade);
        $tot['cnss_usd'] += $usd($row['cnss']); $tot['tax_usd'] += $usd($row['income_tax']);
        $tot['totret_usd'] += $usd($row['total_retenues']); $tot['net_usd'] += (float)$s['net_salary_usd'];
        $tot['family_usd'] += $usd($row['family']); $tot['transport_usd'] += $usd($row['transport']);
        $tot['total_due_usd'] += (float)$s['total_due_usd'];
    }

    $empNameAr = trim($emp['first_name_ar'].' '.$emp['last_name_ar']);
    $empNameFr = trim($emp['first_name_fr'].' '.$emp['last_name_fr']);
    if ($empNameAr !== '' && $empNameFr !== '') $empNameDisp = $empNameAr.' — '.$empNameFr;
    else $empNameDisp = $empNameAr !== '' ? $empNameAr : $empNameFr;

    $meta = [
        'name'        => $empNameDisp,
        'name_ar'     => $empNameAr,
        'name_fr'     => $empNameFr,
        'diploma'     => !empty($emp['diploma']) ? diplomaLabel($emp['diploma'], 'ar') : '—',
        'type'        => employeeTypeLabel($emp['employee_type']),
        'grade'       => rtrim(rtrim(number_format((float)$emp['current_grade'], 1), '0'), '.'),
        'code'        => $emp['employee_code'] ?: '—',
        'hire'        => formatDate($emp['hire_date']),
        'titul'       => formatDate($emp['titularization_date']),
        'hours'       => rtrim(rtrim(number_format((float)$emp['hours_per_week'],1),'0'),'.'),
        'days'        => (int)$emp['days_per_week'],
        'classes'     => $emp['niveau_scolaire'] ? str_replace(',', '، ', $emp['niveau_scolaire']) : '—',
        'subjects'    => $emp['subjects_taught'] ?: '—',
        'cnss'        => cnssWithBirthYear($emp['nssf_number'], $emp['birth_date']),
        'caisse_no'   => $emp['caisse_number'] ?: '—',
        'finance_no'  => $emp['finance_ministry_number'] ?: '—',
        'rate'        => $slipRate,
        'school_year' => $schoolYear,
    ];

    return ['meta' => $meta, 'rows' => $rows, 'tot' => $tot, 'school' => $empSchool];
}
