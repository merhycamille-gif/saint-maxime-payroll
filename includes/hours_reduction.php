<?php
/**
 * 🕐 تناقص عدد ساعات التدريس الأسبوعية للأستاذ الملاك حسب سنوات الخدمة
 * المرسوم رقم 2601 تاريخ 27/3/2018 (الجريدة الرسمية العدد 15 — 5/4/2018) المعدِّل للمرسومين
 * 5343 (5/11/2010) و784 (6/7/1983) — من ملفَي المستخدم: Desktop\قانون تناقص الساعات.jpg + تناقص ص 1.jpg
 *
 * ⚠️ عرض ومعلومات فقط — لا يغيّر أي حساب راتب ولا يلمس خانة «ساعات بالأسبوع» المخزّنة.
 *
 * القاعدة: التناقص التدريجي يُطبَّق بعد خدمة فعلية في الملاك الدائم لا تقل عن:
 *   16 سنة في التعليم الثانوي (الجدول 1) · 20 سنة في الروضة والتعليم الأساسي (الجدولان 2 و3).
 * «خلال سنة الخدمة N» تُحسب بالسنوات الدراسية: سنة دخول الملاك الدراسية = سنة الخدمة 1.
 */

/** جداول المرسوم الثلاثة: base = الساعات الأسبوعية الكاملة قبل التناقص، steps = [منN, إلىN, الساعات] */
function hoursReductionLawTables() {
    return [
        1 => ['label' => 'التعليم الثانوي', 'label_fr' => 'Secondaire', 'minYears' => 16, 'base' => 20,
              'steps' => [[17, 19, 19], [20, 22, 18], [23, 24, 17], [25, 26, 16], [27, 28, 15], [29, 999, 14]]],
        2 => ['label' => 'الحلقة الثالثة (المتوسط)', 'label_fr' => 'Cycle 3 (Intermédiaire)', 'minYears' => 20, 'base' => 24,
              'steps' => [[21, 23, 23], [24, 25, 22], [26, 27, 21], [28, 29, 20], [30, 31, 19], [32, 32, 18], [33, 999, 17]]],
        3 => ['label' => 'الروضة والحلقتان الأولى والثانية', 'label_fr' => 'Maternelle + Cycles 1-2', 'minYears' => 20, 'base' => 27,
              'steps' => [[21, 23, 26], [24, 25, 25], [26, 27, 24], [28, 29, 23], [30, 30, 22], [31, 31, 21], [32, 32, 20], [33, 999, 19]]],
    ];
}

/**
 * تحديد الجدول المنطبق على الأستاذ — سلّم استدلال:
 *  ١) خانة «المرحلة» (niveau_scolaire) إن كانت معبّأة (الأعلى يغلب: ثانوي > متوسط > ابتدائي/حضانة)
 *  ٢) «الصفوف التي يعلّم فيها» (classes_taught: ids ‏1-9 روضة/حلقتان · 10-12 حلقة ثالثة · 13-15 ثانوي)
 *  ٣) افتراضي حسب المدرسة: اسمها فيه «ثانوية» → الجدول 1، وإلا الجدول 3 — مع وسم assumed=true
 * يرجع [tableNo, source_label, assumed].
 */
function hoursReductionResolveStage(array $emp, $schoolName = '') {
    $niv = array_filter(array_map('trim', explode(',', (string)($emp['niveau_scolaire'] ?? ''))));
    if ($niv) {
        if (in_array('secondaire', $niv, true)) return [1, 'مرحلته المحدّدة بملفه', false];
        if (in_array('intermediaire', $niv, true)) return [2, 'مرحلته المحدّدة بملفه', false];
        return [3, 'مرحلته المحدّدة بملفه', false];
    }
    $cls = array_filter(array_map('intval', explode(',', (string)($emp['classes_taught'] ?? ''))));
    if ($cls) {
        $mx = max($cls);
        if ($mx >= 13) return [1, 'الصفوف التي يعلّم فيها', false];
        if ($mx >= 10) return [2, 'الصفوف التي يعلّم فيها', false];
        return [3, 'الصفوف التي يعلّم فيها', false];
    }
    if (mb_strpos((string)$schoolName, 'ثانوية') !== false) return [1, 'افتراضي حسب المدرسة (ثانوية)', true];
    return [3, 'افتراضي حسب المدرسة', true];
}

/**
 * حساب التناقص لأستاذ ملاك بسنة دراسية معيّنة. يرجع null إذا غير منطبق (غير ملاك / بلا تاريخ ملاك)،
 * وإلا: serviceYear، table، stageLabel، source، assumed، baseHours، lawHours، reduction، startSy
 * (startSy = أول سنة دراسية يبدأ فيها تناقصه — مفيدة لمن لم يبلغ العتبة بعد، reduction=0 عندها).
 */
function hoursReductionFor(array $emp, $sy, $schoolName = '') {
    if (($emp['employee_type'] ?? '') !== 'enseignant_titulaire') return null;
    $td = substr((string)($emp['titularization_date'] ?? ''), 0, 10);
    if (!$td || !preg_match('/^(\d{4})-(\d{2})/', $td, $m)) return null;
    $syStart = (int)substr((string)$sy, 0, 4);
    if ($syStart < 1900) return null;
    $malakStart = ((int)$m[2] >= 10) ? (int)$m[1] : (int)$m[1] - 1;
    $serviceYear = $syStart - $malakStart + 1;
    if ($serviceYear < 1) return null;
    [$tNo, $source, $assumed] = hoursReductionResolveStage($emp, $schoolName);
    $t = hoursReductionLawTables()[$tNo];
    $lawHours = $t['base']; $reduction = 0;
    foreach ($t['steps'] as [$from, $to, $h]) {
        if ($serviceYear >= $from && $serviceYear <= $to) { $lawHours = $h; $reduction = $t['base'] - $h; break; }
    }
    $firstN = $t['steps'][0][0]; // أول سنة خدمة فيها تناقص
    $startYr = $malakStart + $firstN - 1;
    return [
        'serviceYear' => $serviceYear, 'table' => $tNo,
        'stageLabel' => $t['label'], 'stageLabelFr' => $t['label_fr'],
        'source' => $source, 'assumed' => $assumed,
        'baseHours' => $t['base'], 'lawHours' => $lawHours, 'reduction' => $reduction,
        'startSy' => $startYr . '-' . ($startYr + 1),
    ];
}

/**
 * لائحة أساتذة الملاك الذين لهم تناقص بالسنة المعروضة، ضمن نطاق مدرسة المستخدم،
 * مجمّعة لكل مدرسة: ['اسم المدرسة' => [ ['emp'=>صف الموظف, 'hr'=>نتيجة hoursReductionFor], ... ]]
 */
function hoursReductionList($db, $sy) {
    [$yf, $yp] = yearEmploymentFilter($sy, 'e.');
    $st = $db->prepare("SELECT e.*, s.name_ar school_name FROM employees e JOIN schools s ON s.id = e.school_id
        WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire'" . schoolScopeSql('e.school_id') . $yf . "
        ORDER BY s.name_ar, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr)");
    $st->execute($yp);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $hr = hoursReductionFor($e, $sy, $e['school_name']);
        if ($hr && $hr['reduction'] > 0) $out[$e['school_name']][] = ['emp' => $e, 'hr' => $hr];
    }
    return $out;
}
