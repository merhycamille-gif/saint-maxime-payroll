<?php
/**
 * 🏛️💼 بوّابة صندوق التعويضات لأفراد الهيئة التعليمية في المدارس الخاصة — طبق منصّة الصندوق www.caisseindemnite.com (بوابة المدارس)
 * (طلبه 2026-11-08 «بدي ياه طبق الأصل… وأقدر رحّلو من البرنامج على الموقع»). الصفحات الثلاث نفسها:
 *  - الرئيسية: معلومات المدرسة كما هي بالصندوق (رقم المدرسة = schools.caisse_number).
 *  - البيان العام (سنوي): كل أساتذة السنة ملاكاً ومتعاقدين بأعمدة المنصّة (ملاك/متعاقد، الرقم المالي، الاسم والشهرة، اسم الأب، الشهادة،
 *    الفئة، المرحلة، تاريخ الولادة، تاريخ دخول الخدمة، ساعات، الراتب، الأجر الإضافي) + الخطوة 1 (هاتف المدرسة ونوعها).
 *  - البيان المالي (فصلي): أساتذة الملاك الخاضعون للصندوق فقط — الراتب السابق/الحالي، الأجر الإضافي، المحسومات الشهرية = 6٪ × (الراتب + الإضافي)،
 *    الفصلية = ×3، نصف راتب، درجة/مختلف، المبلغ الإضافي، ومساهمة المدرسة = نفس المحسومات ⇒ المجموع العام = الضعف
 *    (المادة 6 من المرسوم الاشتراعي 47 تاريخ 29/06/1983).
 *  🔴 قاعدته (2026-11-08): راتب الملاك لا يتغيّر إلا بأوّل الفصل (1/10 درجات عادية، 1/1 استثنائية) ⇒ الفصل = راتب أوّل شهر فيه × 3؛
 *    الدرجة بالفصل الأوّل لها على عمود «درجة»، ونصف راتب الملاك الجديد على عمود «نصف راتب»، ولا يُغيَّر الراتب بسببهما.
 *  - الأسطر تُحسب تلقائياً من داتا البرنامج؛ أيّ خانة تُعدَّل تُحفَظ كـoverride للبيان، وأسطر يدوية تُضاف، وأسطر محسوبة تُحذف (تُستثنى)،
 *    والخانة الناقصة تظهر «ناقص» بالأحمر كالمنصّة. رموز الفترات كالمنصّة: YYYYQQ للفصول (1=ك2-آذار … 4=ت1-ك1) وYYYY001/YYYY010 للبيانات الخاصّة.
 */

/* ===== قوائم المنصّة الحرفية (القيمة = معرّف المنصّة) ===== */
function eocCertOptions(): array {
    return [10 => 'إجازة جامعية', 11 => 'إجازة تعليمية', 12 => 'بكالوريا قسم 2', 13 => 'كفاءة للتعليم الثانوي', 14 => 'بكالوريا فنية-تربية حضانية', 15 => 'امتياز فني-تربية حضانية',
        16 => 'قانون-67/62', 17 => 'قانون -96/512', 18 => 'مرسوم 73/5144', 19 => 'قسم أول', 20 => 'بروفيه', 21 => 'لا شهادة', 22 => 'مختلف', 23 => 'ختصاص علوم تربوية', 24 => 'دار معلمين',
        25 => 'BT3مهني', 26 => 'TSمهني', 27 => 'ماستر', 28 => 'ماجستر', 29 => 'دبلوم دراسات عليا', 30 => 'دبلوم دراسات معمقة', 31 => 'دبلوم في التعليم', 32 => 'بكالوريوس', 33 => 'افادة مدرسية',
        34 => 'دكتوراه', 35 => 'الجدارة', 36 => 'LTمهني', 37 => 'ثانوي مهني', 38 => 'تكميلي مهني', 39 => 'TS مهني'];
}
function eocCatOptions(): array { return [7 => 'إدارة', 4 => 'استاذ ثانوي', 1 => 'حادقة', 8 => 'سكريتاريا', 2 => 'مدرس', 5 => 'مدير', 6 => 'مديرة', 3 => 'معلم', 9 => 'منسق', 10 => 'ناظر', 11 => 'وكيل']; }
function eocPhaseOptions(): array { return [2 => 'إ', 6 => 'إ - م', 15 => 'اجازة فنية', 8 => 'ادارة', 14 => 'امتياز فني', 13 => 'بكالوريا فنية', 12 => 'تكميلية مهنية', 9 => 'تنسيق', 4 => 'ث', 1 => 'ح', 10 => 'ح - إ', 11 => 'كل المراحل', 3 => 'م', 5 => 'م - ث', 7 => 'نشاطات']; }
function eocSchoolTypes(): array { return ['free' => 'مجاني', 'non_free' => 'غير مجاني', 'professional' => 'مهني']; }
function eocQuarterNames(): array { return [1 => 'الفصل الأول', 2 => 'الفصل الثاني', 3 => 'الفصل الثالث', 4 => 'الفصل الرابع']; }

/* شهادة البرنامج ⇒ شهادة المنصّة */
function eocCertFromDiploma(?string $code): int {
    return [ 'ijaza_taalimiya' => 11, 'ijaza_jamiya' => 10, 'qsm2_thanawiya' => 12, 'jardinier_bt' => 14, 'jardinier_ts' => 15, 'capes' => 13 ][(string)$code] ?? 0;
}
/* المرحلة من niveau_scolaire (maternelle,primaire,intermediaire,secondaire) ⇒ ح/إ/م/ث ومجموعاتها كالمنصّة */
function eocPhaseFromNiveau(?string $niv): int {
    $set = array_values(array_filter(array_map('trim', explode(',', (string)$niv))));
    $m = in_array('maternelle', $set, true); $p = in_array('primaire', $set, true); $i = in_array('intermediaire', $set, true); $s = in_array('secondaire', $set, true);
    $n = (int)$m + (int)$p + (int)$i + (int)$s;
    if ($n === 0) return 0;
    if ($n >= 3) return 11;                 // كل المراحل
    if ($n === 1) return $m ? 1 : ($p ? 2 : ($i ? 3 : 4));
    if ($m && $p) return 10;                // ح - إ
    if ($p && $i) return 6;                 // إ - م
    if ($i && $s) return 5;                 // م - ث
    if ($m && $i) return 10; if ($p && $s) return 6; if ($m && $s) return 11;
    return 0;
}
/* الفئة: حضانة ⇒ حادقة · ابتدائي ⇒ معلم · متوسط/ثانوي ⇒ مدرس · ثانوي فقط ⇒ استاذ ثانوي؛ الوظائف الإدارية من job_title */
function eocCatFromEmp(array $e): int {
    $jt = mb_strtolower(trim((string)($e['job_title'] ?? '')));
    if ($jt !== '') { if (strpos($jt, 'surveill') !== false || $jt === 'ناظر') return 10; if (strpos($jt, 'secret') !== false) return 8; if (strpos($jt, 'coord') !== false) return 9; if (strpos($jt, 'direct') !== false) return 5; }
    $set = array_values(array_filter(array_map('trim', explode(',', (string)($e['niveau_scolaire'] ?? '')))));
    if (!$set) return 0;
    if ($set === ['maternelle']) return 1;
    if ($set === ['secondaire']) return 4;
    if (in_array('intermediaire', $set, true) || in_array('secondaire', $set, true)) return 2;
    return 3;
}

/* ===== الجدول ===== */
function eocEnsureTable(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS eoc_portal (
        id INT AUTO_INCREMENT PRIMARY KEY, school_id INT NOT NULL, scope VARCHAR(120) NOT NULL, kind VARCHAR(12) NOT NULL, period VARCHAR(20) NOT NULL,
        data LONGTEXT NULL, updated_at DATETIME NULL, UNIQUE KEY uq_eoc_scope (scope, kind, period)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
function eocScope(array $ids): string { $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids); return implode(',', $ids); }
function eocDefaults(string $kind): array {
    return ['status' => 'open', 'phone' => '', 'school_type' => 'non_free', 'lines' => [], 'manual' => [], 'deleted' => [], 'notes' => ''];
}
function eocLoad(PDO $db, array $ids, string $kind, string $period): array {
    eocEnsureTable($db);
    $st = $db->prepare("SELECT data FROM eoc_portal WHERE scope = ? AND kind = ? AND period = ?"); $st->execute([eocScope($ids), $kind, $period]);
    $j = $st->fetchColumn(); $d = $j ? (json_decode((string)$j, true) ?: []) : [];
    $d = array_replace(eocDefaults($kind), $d);
    foreach (['lines', 'manual', 'deleted'] as $k) if (!is_array($d[$k])) $d[$k] = [];
    return $d;
}
function eocSave(PDO $db, array $ids, string $kind, string $period, array $data): void {
    eocEnsureTable($db);
    $db->prepare("INSERT INTO eoc_portal (school_id, scope, kind, period, data, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()")
       ->execute([(int)($ids[0] ?? 0), eocScope($ids), $kind, $period, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}
/** كل البيانات المحفوظة لنطاق (لقوائم «البيانات السابقة») */
function eocSavedMap(PDO $db, array $ids, string $kind): array {
    eocEnsureTable($db);
    $st = $db->prepare("SELECT period, data FROM eoc_portal WHERE scope = ? AND kind = ?"); $st->execute([eocScope($ids), $kind]);
    $out = []; foreach ($st->fetchAll() as $r) $out[$r['period']] = json_decode((string)$r['data'], true) ?: [];
    return $out;
}

/* ===== الفترات ===== */
/** رمز الفترة المالية ⇒ [y, q|null, special, months[], label, first]
 *  🔴 فصول المنصّة تتبع السنة الدراسية (تحقّق 2026-11-08: بيان 202601 المعتمد = رواتب ت1 2025 بالمليم): YYYY01 = ت1–ك1 من السنة السابقة،
 *  YYYY02 = ك2–آذار، YYYY03 = نيسان–حزيران، YYYY04 = تموز–أيلول. البيانات الخاصّة YYYY010 = تشرين الأول YYYY وYYYY001 = كانون الثاني YYYY (شهرا الدرجات). */
function eocPeriodInfo(string $code): ?array {
    if (preg_match('/^(\d{4})0([1-4])$/', $code, $m)) { $y = (int)$m[1]; $q = (int)$m[2];
        $months = $q === 1 ? [[$y - 1, 10], [$y - 1, 11], [$y - 1, 12]] : [[$y, ($q - 2) * 3 + 1], [$y, ($q - 2) * 3 + 2], [$y, ($q - 2) * 3 + 3]];
        return ['code' => $code, 'y' => $y, 'q' => $q, 'special' => '', 'months' => $months, 'label' => eocQuarterNames()[$q], 'first' => $months[0],
                'range' => monthName($months[0][1], 'ar') . ' – ' . monthName($months[2][1], 'ar') . ' ' . $months[2][0]]; }
    if (preg_match('/^(\d{4})(001|010)$/', $code, $m)) { $y = (int)$m[1]; $mo = (int)$m[2];
        return ['code' => $code, 'y' => $y, 'q' => null, 'special' => $m[2], 'months' => [[$y, $mo]], 'label' => $m[2], 'first' => [$y, $mo], 'range' => monthName($mo, 'ar') . ' ' . $y . ' — بيان خاص (درجات / نصف راتب)']; }
    return null;
}
/** أوّل شهر الفصل السابق (للراتب السابق) */
function eocPrevFirstMonth(array $pi): array {
    [$y, $m] = $pi['first'];
    return $m <= 3 ? [$y - 1, 10] : [$y, $m - 3];
}
/** رمز فصل المنصّة الذي يقع فيه شهر معيّن */
function eocCodeOfMonth(int $y, int $m): string { return $m >= 10 ? ($y + 1) . '01' : $y . '0' . (intdiv($m - 1, 3) + 2); }
/** السنوات التي فيها رواتب بالنطاق */
function eocYearsWithSalaries(PDO $db, array $ids): array {
    $in = implode(',', array_map('intval', $ids)) ?: '0';
    return array_map('intval', $db->query("SELECT DISTINCT year FROM monthly_salaries WHERE school_id IN ($in) AND base_plus_echelon_lbp > 0 ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN));
}
function eocSchoolYears(PDO $db, array $ids): array {
    $in = implode(',', array_map('intval', $ids)) ?: '0';
    $ys = $db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE school_id IN ($in) AND base_plus_echelon_lbp > 0 AND school_year REGEXP '^[0-9]{4}-[0-9]{4}$' ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $cur = currentSchoolYear(); [$cy] = schoolYearToYears($cur);
    $ys = array_values(array_filter($ys, fn($s) => (int)substr($s, 0, 4) <= $cy)); // لا سنوات مستقبلية (صفوف منقولة)
    if (!in_array($cur, $ys, true)) { $ys[] = $cur; rsort($ys); }
    return $ys;
}
/** لائحة الفترات المالية كالمنصّة (ترتيب الرمز تنازلياً): الفصول التي بدأ أوّل شهر فيها حتى الفصل القادم، والبيانات الخاصّة 010/001 لشهور مضت */
function eocFinancialPeriods(PDO $db, array $ids): array {
    $years = eocYearsWithSalaries($db, $ids); $nowY = (int)date('Y'); $nowM = (int)date('n');
    $years = array_values(array_filter($years, fn($y) => $y <= $nowY + 1)); if (!$years) $years = [$nowY];
    $minY = min($years);
    $started = fn(int $y, int $m) => $y < $nowY || ($y === $nowY && $m <= $nowM);
    $out = [];
    for ($y = $nowY + 1; $y >= $minY; $y--) {
        for ($q = 4; $q >= 1; $q--) {
            $code = sprintf('%d0%d', $y, $q); $pi = eocPeriodInfo($code); [$fy, $fm] = $pi['first'];
            // الفصل الجاري وكل ما قبله + الفصل القادم مباشرة (للتحضير) — كالمنصّة التي تعرض 202701 بتشرين الثاني 2026
            $nextY = $fm <= 3 ? $fy - 1 : $fy; $nextM = $fm <= 3 ? $fm + 9 : $fm - 3; // أوّل شهر الفصل السابق
            if ($started($fy, $fm) || $started($nextY, $nextM)) $out[] = $code;
        }
        if ($started($y, 10)) $out[] = $y . '010';
        if ($started($y, 1)) $out[] = $y . '001';
    }
    usort($out, fn($a, $b) => strcmp($b, $a));
    return $out;
}

/* ===== الموظفون والرواتب ===== */
function eocNameAr(array $e): string { return trim(trim((string)($e['first_name_ar'] ?? '')) . ' ' . trim((string)($e['last_name_ar'] ?? ''))) ?: trim((string)($e['first_name_fr'] ?? '') . ' ' . (string)($e['last_name_fr'] ?? '')); }
function eocArSort(string $col): string { return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,'أ','ا'),'إ','ا'),'آ','ا'),'ى','ي'),'ة','ه')"; }
/** راتب شهر معيّن لموظفي النطاق: [emp_id => ['base'=>,'extra'=>,'caisse'=>,'grade'=>]] مع بديل: آخر شهر له قيمة قبله بنفس السنة الدراسية */
function eocMonthSalaries(PDO $db, array $ids, int $y, int $m): array {
    $in = implode(',', array_map('intval', $ids)) ?: '0';
    $st = $db->prepare("SELECT employee_id, base_plus_echelon_lbp b, (extra_lbp + prime_fixe_lbp) x, caisse_amount_lbp c, eoc_grade_lbp g, school_eoc_6_lbp s FROM monthly_salaries WHERE school_id IN ($in) AND year = ? AND month = ? AND base_plus_echelon_lbp > 0");
    $st->execute([$y, $m]); $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['employee_id']] = ['base' => (float)$r['b'], 'extra' => (float)$r['x'], 'caisse' => (float)$r['c'], 'grade' => (float)$r['g'], 'school' => (float)$r['s']];
    return $out;
}
/** أساتذة السنة الدراسية (ملاك + متعاقدون) الذين لهم رواتب فيها */
function eocTeachersOfYear(PDO $db, array $ids, string $sy): array {
    $in = implode(',', array_map('intval', $ids)) ?: '0';
    $st = $db->prepare("SELECT e.*, (SELECT MAX(CONCAT(ms.year, LPAD(ms.month,2,'0'))) FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND ms.base_plus_echelon_lbp > 0) last_ym
        FROM employees e WHERE e.school_id IN ($in) AND e.is_deleted = 0 AND e.employee_type IN ('enseignant_titulaire','enseignant_contractuel')
          AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND ms.base_plus_echelon_lbp > 0)
        ORDER BY " . eocArSort("COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)") . ", " . eocArSort("COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)") . ", e.id");
    $st->execute([$sy, $sy]);
    return $st->fetchAll();
}
function eocYmd(?string $d): array { if (!$d || $d === '0000-00-00' || substr($d, 0, 4) === '1900') return ['', '', '']; [$y, $m, $dd] = array_pad(explode('-', substr($d, 0, 10)), 3, ''); return [(int)$y ?: '', (int)$m ?: '', (int)$dd ?: '']; }
function eocDateStr($y, $m, $d): string { return ($y && $m && $d) ? sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d) : ''; }

/** حقول سطر البيان العام (بأسماء خانات المنصّة) */
function eocGeneralFields(): array { return ['employment_type', 'teacher_no', 'teacher_name', 'father_name', 'certificate_id', 'category_id', 'phase_id', 'birth_date', 'start_date', 'teaching_hours', 'salary', 'add_salary']; }
function eocGeneralRequired(): array { return ['teacher_name', 'father_name', 'certificate_id', 'category_id', 'phase_id', 'birth_date', 'start_date', 'teaching_hours', 'salary', 'add_salary']; } // الرقم المالي للملاك فقط

/** أسطر البيان العام لسنة: محسوبة من البرنامج + overrides + يدوية − محذوفة */
function eocGeneralRows(PDO $db, array $ids, string $sy, array $data): array {
    [$y1] = schoolYearToYears($sy);
    $oct = eocMonthSalaries($db, $ids, $y1, 10);
    $rows = [];
    foreach (eocTeachersOfYear($db, $ids, $sy) as $e) {
        $id = (int)$e['id']; if (in_array($id, array_map('intval', $data['deleted']), true)) continue;
        $sal = $oct[$id] ?? null;
        if (!$sal && !empty($e['last_ym'])) { $ly = (int)substr($e['last_ym'], 0, 4); $lm = (int)substr($e['last_ym'], 4, 2); $sal = eocMonthSalaries($db, [(int)$e['school_id']], $ly, $lm)[$id] ?? null; }
        $auto = [
            'employment_type' => $e['employee_type'] === 'enseignant_titulaire' ? 'staff' : 'contract',
            'teacher_no' => trim((string)($e['caisse_number'] ?? '')),
            'teacher_name' => eocNameAr($e), 'father_name' => trim((string)($e['father_name_ar'] ?? '')),
            'certificate_id' => eocCertFromDiploma($e['diploma'] ?? null), 'category_id' => eocCatFromEmp($e), 'phase_id' => eocPhaseFromNiveau($e['niveau_scolaire'] ?? null),
            'birth_date' => eocDateStr(...eocYmd($e['birth_date'] ?? null)), 'start_date' => eocDateStr(...eocYmd($e['display_hire_date'] ?: ($e['hire_date'] ?? null))),
            'teaching_hours' => $e['hours_per_week'] !== null && $e['hours_per_week'] !== '' ? (float)$e['hours_per_week'] : '',
            'salary' => $sal ? round($sal['base']) : '', 'add_salary' => $sal ? round($sal['extra']) : '',
        ];
        $ov = (array)($data['lines'][(string)$id] ?? []);
        $row = ['key' => (string)$id, 'src' => 'auto', 'emp_id' => $id, 'auto' => $auto, 'ov' => $ov] + array_replace($auto, array_intersect_key($ov, array_flip(eocGeneralFields())));
        $rows[] = $row;
    }
    foreach ((array)$data['manual'] as $k => $m) { if (!is_array($m)) continue; $rows[] = ['key' => (string)$k, 'src' => 'manual', 'emp_id' => 0, 'auto' => [], 'ov' => []] + array_replace(array_fill_keys(eocGeneralFields(), ''), array_intersect_key($m, array_flip(eocGeneralFields()))); }
    foreach ($rows as &$r) { $miss = []; foreach (eocGeneralRequired() as $f) if ($r[$f] === '' || $r[$f] === 0 || $r[$f] === null) $miss[] = $f; if ($r['employment_type'] === 'staff' && $r['teacher_no'] === '') $miss[] = 'teacher_no'; $r['missing'] = $miss; }
    unset($r);
    usort($rows, fn($a, $b) => strcmp(eocNorm($a['teacher_name']), eocNorm($b['teacher_name'])));
    return $rows;
}
function eocNorm(string $s): string { return trim(str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ّ', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ْ'], ['ا', 'ا', 'ا', 'ي', 'ه', '', '', '', '', '', '', '', ''], $s)); }

/** حقول سطر البيان المالي (بأسماء خانات المنصّة) */
function eocFinancialFields(): array { return ['teacher_no', 'teacher_name', 'previous_salary', 'current_salary', 'add_salary', 'teacher_m_disc', 'teacher_q_disc', 'school_q_disc', 'discount_half_salary', 'training_degree', 'extra_amount', 'is_trainee', 'is_contribution', 'remarks']; }
const EOC_RATE = 0.06;

/** أسطر البيان المالي لفترة: ملاك خاضعون للصندوق برواتب أوّل شهر الفصل */
function eocFinancialRows(PDO $db, array $ids, string $code, array $data): array {
    $pi = eocPeriodInfo($code); if (!$pi) return [];
    [$y, $m] = $pi['first']; [$py, $pm] = eocPrevFirstMonth($pi);
    $cur = eocMonthSalaries($db, $ids, $y, $m); $prev = eocMonthSalaries($db, $ids, $py, $pm);
    $in = implode(',', array_map('intval', $ids)) ?: '0';
    $emps = [];
    if ($cur) {
        $st = $db->prepare("SELECT e.* FROM employees e WHERE e.id IN (" . implode(',', array_keys($cur)) . ") AND e.school_id IN ($in) AND e.is_deleted = 0 AND e.employee_type = 'enseignant_titulaire' AND COALESCE(e.eoc_subject,1) = 1
            ORDER BY " . eocArSort("COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr)") . ", " . eocArSort("COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr)") . ", e.id");
        $st->execute(); $emps = $st->fetchAll();
    }
    $rows = [];
    foreach ($emps as $e) {
        $id = (int)$e['id']; if (in_array($id, array_map('intval', $data['deleted']), true)) continue;
        $s = $cur[$id]; $p = $prev[$id] ?? null;
        $base = round($s['base']); $extra = round($s['extra']);
        $monthly = round(($base + $extra) * EOC_RATE);
        // 🔴 نصف الراتب (الدخول في الملاك/الخيار اليدوي) ≈ نصف الأساس ⇒ عمود «نصف راتب»؛ وإلا فالمحسوم للصندوق بالشهر = درجة
        $g = round($s['grade']); $half = 0; $deg = 0;
        if ($g > 0) { if (abs($g - $base / 2) <= 1) $half = $g; else $deg = $g; }
        if ($pi['special'] === '') { // بالفصل: الدرجة تلقائياً = الحالي − السابق كالمنصّة إن لم تكن بالرواتب
            if ($deg === 0 && $half === 0 && $p && $base > round($p['base'])) $deg = $base - round($p['base']);
        }
        $auto = [
            'teacher_no' => trim((string)($e['caisse_number'] ?? '')), 'teacher_name' => eocNameAr($e),
            'previous_salary' => $p ? round($p['base']) : $base, 'current_salary' => $base, 'add_salary' => $extra,
            'teacher_m_disc' => $monthly, 'teacher_q_disc' => $monthly * count($pi['months']), 'school_q_disc' => $monthly * count($pi['months']),
            'discount_half_salary' => $half, 'training_degree' => $deg, 'extra_amount' => $half + $deg, 'is_trainee' => 0, 'is_contribution' => 0, 'remarks' => '',
        ];
        $ov = (array)($data['lines'][(string)$id] ?? []);
        $row = ['key' => (string)$id, 'src' => 'auto', 'emp_id' => $id, 'auto' => $auto, 'ov' => $ov] + array_replace($auto, array_intersect_key($ov, array_flip(eocFinancialFields())));
        $rows[] = eocFinRecalc($row);
    }
    foreach ((array)$data['manual'] as $k => $mrow) { if (!is_array($mrow)) continue; $r = ['key' => (string)$k, 'src' => 'manual', 'emp_id' => 0, 'auto' => [], 'ov' => []] + array_replace(array_fill_keys(eocFinancialFields(), ''), array_intersect_key($mrow, array_flip(eocFinancialFields()))); $rows[] = eocFinRecalc($r, count($pi['months'])); }
    usort($rows, fn($a, $b) => strcmp(eocNorm((string)$a['teacher_name']), eocNorm((string)$b['teacher_name'])));
    return $rows;
}
/** إعادة احتساب الأعمدة المشتقّة لسطر مالي بعد التعديل: المحسومات تلقائية إلا إذا كُتبت يدوياً؛ «مساهمة» تصفّر حصّة الأستاذ، «متمرن» تصفّر حصّة المدرسة؛ المبلغ الإضافي = نصف راتب + درجة */
function eocFinRecalc(array $r, int $nMonths = 3): array {
    $num = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
    $base = $num($r['current_salary']) + $num($r['add_salary']);
    $ov = (array)($r['ov'] ?? []);
    if (!array_key_exists('teacher_m_disc', $ov) || $ov['teacher_m_disc'] === '') $r['teacher_m_disc'] = (int)$r['is_contribution'] ? 0 : round($base * EOC_RATE);
    if (!array_key_exists('teacher_q_disc', $ov) || $ov['teacher_q_disc'] === '') $r['teacher_q_disc'] = (int)$r['is_contribution'] ? 0 : round($num($r['teacher_m_disc']) * $nMonths);
    if (!array_key_exists('school_q_disc', $ov) || $ov['school_q_disc'] === '') $r['school_q_disc'] = (int)$r['is_trainee'] ? 0 : round($base * EOC_RATE * $nMonths);
    $r['extra_amount'] = round($num($r['discount_half_salary']) + $num($r['training_degree']));
    $r['missing'] = [];
    if ((string)$r['teacher_no'] === '') $r['missing'][] = 'teacher_no';
    if ($num($r['current_salary']) <= 0) $r['missing'][] = 'current_salary';
    return $r;
}
function eocFinancialTotals(array $rows): array {
    $t = array_fill_keys(['previous_salary', 'current_salary', 'add_salary', 'teacher_m_disc', 'teacher_q_disc', 'school_q_disc', 'discount_half_salary', 'training_degree', 'extra_amount'], 0.0);
    $num = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
    foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += $num($r[$k] ?? 0);
    $t['teacher_total'] = $t['teacher_q_disc'] + $t['extra_amount']; // المحسومات المقتطعة = فصلية + المبلغ الإضافي (نصف راتب + درجة)
    $t['school_total'] = $t['school_q_disc'];
    $t['grand'] = $t['teacher_total'] + $t['school_total'];
    $t['count'] = count($rows);
    return $t;
}
function eocGeneralTotals(array $rows): array {
    $t = ['hours' => 0.0, 'salary' => 0.0, 'add_salary' => 0.0, 'count' => count($rows), 'staff' => 0, 'contract' => 0];
    $num = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
    foreach ($rows as $r) { $t['hours'] += $num($r['teaching_hours']); $t['salary'] += $num($r['salary']); $t['add_salary'] += $num($r['add_salary']); if ($r['employment_type'] === 'staff') $t['staff']++; else $t['contract']++; }
    return $t;
}

/* ===== حفظ سطر من النموذج (نفس أسماء خانات المنصّة) ===== */
function eocNumIn($v): string { $s = str_replace([',', ' ', '٬'], '', trim((string)$v)); return $s === '' ? '' : (string)(float)$s; }
function eocApplyLine(array &$data, string $kind, array $P): array {
    $key = trim((string)($P['key'] ?? '')); $isNew = $key === '';
    $fields = $kind === 'general' ? eocGeneralFields() : eocFinancialFields();
    $vals = [];
    foreach ($fields as $f) {
        if (in_array($f, ['birth_date', 'start_date'], true)) { $pfx = $f === 'birth_date' ? 'birth' : 'start'; $vals[$f] = eocDateStr($P[$pfx . '_year'] ?? '', $P[$pfx . '_month'] ?? '', $P[$pfx . '_day'] ?? ''); continue; }
        if (in_array($f, ['is_trainee', 'is_contribution'], true)) { $vals[$f] = !empty($P[$f]) ? 1 : 0; continue; }
        if (!array_key_exists($f, $P)) continue;
        $v = trim((string)$P[$f]);
        if (in_array($f, ['salary', 'add_salary', 'previous_salary', 'current_salary', 'teacher_m_disc', 'teacher_q_disc', 'school_q_disc', 'discount_half_salary', 'training_degree', 'teaching_hours'], true)) $v = eocNumIn($v);
        if (in_array($f, ['certificate_id', 'category_id', 'phase_id'], true)) $v = (int)$v;
        $vals[$f] = $v;
    }
    if ($kind === 'general') {
        if (($vals['teacher_name'] ?? '') === '') return ['ok' => false, 'msg' => 'الاسم والشهرة إلزامي'];
        if (($vals['employment_type'] ?? 'staff') === 'staff' && ($vals['teacher_no'] ?? '') === '') return ['ok' => false, 'msg' => 'الرقم المالي إلزامي لأستاذ الملاك'];
        if (($vals['salary'] ?? '') === '' || (float)$vals['salary'] <= 0) return ['ok' => false, 'msg' => 'الراتب إلزامي'];
    } else {
        if (($vals['teacher_no'] ?? '') === '' && $isNew) return ['ok' => false, 'msg' => 'الرقم المالي إلزامي'];
        if (($vals['current_salary'] ?? '') === '' || (float)$vals['current_salary'] <= 0) return ['ok' => false, 'msg' => 'الراتب الشهري الحالي إلزامي'];
        if (!empty($vals['is_trainee']) && !empty($vals['is_contribution'])) $vals['is_contribution'] = 0;
    }
    if ($isNew) { $k = 'm' . substr(md5(uniqid('', true)), 0, 8); $data['manual'][$k] = $vals; return ['ok' => true, 'msg' => 'أُضيف السطر', 'key' => $k]; }
    if (isset($data['manual'][$key])) { $data['manual'][$key] = array_replace($data['manual'][$key], $vals); return ['ok' => true, 'msg' => 'حُفظ التعديل', 'key' => $key]; }
    $data['lines'][$key] = array_replace((array)($data['lines'][$key] ?? []), $vals);
    return ['ok' => true, 'msg' => 'حُفظ التعديل', 'key' => $key];
}
function eocDeleteLine(array &$data, string $key): void {
    if (isset($data['manual'][$key])) { unset($data['manual'][$key]); return; }
    if (ctype_digit($key)) { $data['deleted'][] = (int)$key; $data['deleted'] = array_values(array_unique(array_map('intval', $data['deleted']))); unset($data['lines'][$key]); }
}
function eocRestoreLine(array &$data, string $key): void { $data['deleted'] = array_values(array_filter(array_map('intval', $data['deleted']), fn($x) => $x !== (int)$key)); }
function eocResetLine(array &$data, string $key): void { unset($data['lines'][$key]); }

/* ===== إكسل (نفس أعمدة المنصّة) ===== */
function eocBuildXlsx(string $kind, string $period, array $rows, array $school, array $extra = []): string {
    require_once __DIR__ . '/mehe_budget.php';
    $X = new MeheXlsx(); $c = [MeheXlsx::class, 'c']; $n = [MeheXlsx::class, 'n']; $fx = [MeheXlsx::class, 'fx']; $L = [MeheXlsx::class, 'col'];
    $num = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
    $name = (string)($school['name_ar'] ?? '');
    if ($kind === 'general') {
        $si = $X->sheet('البيان العام ' . $period, [5, 11, 13, 28, 14, 26, 12, 12, 13, 15, 9, 14, 14]);
        $X->row($si, [$c('البيان العام ' . $period . ' — ' . $name . ' — رقم المدرسة ' . ($school['caisse_number'] ?? ''), 1)], 26); $X->merge($si, 'A1:M1');
        $X->row($si, [$c('رقم هاتف المدرسة: ' . ($extra['phone'] ?? '') . ' — نوع المدرسة: ' . (eocSchoolTypes()[$extra['school_type'] ?? 'non_free'] ?? ''), 12)]); $X->merge($si, 'A2:M2');
        $X->row($si, array_map(fn($l) => $c($l, 2), ['#', 'ملاك/متعاقد', 'الرقم المالي', 'الاسم والشهرة', 'اسم الاب', 'الشهادة', 'الفئة', 'المرحلة', 'تاريخ الولادة', 'تاريخ دخول الخدمة', 'ساعات', 'الراتب', 'الأجر الاضافي']), 30);
        $r0 = $X->rowCount($si) + 1; $i = 0;
        foreach ($rows as $r) { $i++; $X->row($si, [$n($i, 13), $c($r['employment_type'] === 'staff' ? 'ملاك' : 'متعاقد'), $c($r['teacher_no']), $c($r['teacher_name']), $c($r['father_name']), $c(eocCertOptions()[(int)$r['certificate_id']] ?? ''), $c(eocCatOptions()[(int)$r['category_id']] ?? ''), $c(eocPhaseOptions()[(int)$r['phase_id']] ?? ''), $c($r['birth_date']), $c($r['start_date']), $n($num($r['teaching_hours'])), $n($num($r['salary'])), $n($num($r['add_salary']))]); }
        $r1 = $X->rowCount($si);
        if ($i) $X->row($si, [$c('المجموع (كل الأساتذة)', 6), $c('', 6), $c('', 6), $c('', 6), $c('', 6), $c('', 6), $c('', 6), $c('', 6), $c('', 6), $c('', 6), $fx("SUM(K$r0:K$r1)", 7), $fx("SUM(L$r0:L$r1)", 7), $fx("SUM(M$r0:M$r1)", 7)]);
        return $X->bytes();
    }
    $pi = eocPeriodInfo($period);
    $si = $X->sheet('البيان المالي ' . $period, [5, 13, 30, 14, 14, 14, 15, 15, 12, 13, 14]);
    $X->row($si, [$c('البيان المالي ' . $period . ($pi ? ' — ' . $pi['label'] : '') . ' — ' . $name . ' — رقم المدرسة ' . ($school['caisse_number'] ?? ''), 1)], 26); $X->merge($si, 'A1:K1');
    $X->row($si, array_map(fn($l) => $c($l, 2), ['#', 'الرقم المالي', 'الاسم والشهرة', 'الراتب السابق', 'الراتب الحالي', 'الأجر الاضافي', 'محسومات شهرية', 'محسومات فصلية', 'نصف راتب', 'درجة / مختلف', 'المبلغ الإضافي']), 30);
    $r0 = $X->rowCount($si) + 1; $i = 0;
    foreach ($rows as $r) { $i++; $rr = $X->rowCount($si) + 1; $X->row($si, [$n($i, 13), $c($r['teacher_no']), $c($r['teacher_name']), $n($num($r['previous_salary'])), $n($num($r['current_salary'])), $n($num($r['add_salary'])), $n($num($r['teacher_m_disc'])), $n($num($r['teacher_q_disc'])), $n($num($r['discount_half_salary'])), $n($num($r['training_degree'])), $fx("I$rr+J$rr")]); }
    $r1 = $X->rowCount($si);
    if ($i) {
        $X->row($si, [$c('المجموع (كل الأساتذة)', 6), $c('', 6), $c('', 6), $fx("SUM(D$r0:D$r1)", 7), $fx("SUM(E$r0:E$r1)", 7), $fx("SUM(F$r0:F$r1)", 7), $fx("SUM(G$r0:G$r1)", 7), $fx("SUM(H$r0:H$r1)", 7), $fx("SUM(I$r0:I$r1)", 7), $fx("SUM(J$r0:J$r1)", 7), $fx("SUM(K$r0:K$r1)", 7)]);
        $rt = $X->rowCount($si); $X->row($si, []);
        $X->row($si, [$c('المحسومات المقتطعة (فصلية + المبلغ الإضافي)', 6), $c('', 6), $c('', 6), $fx("H$rt+K$rt", 7)]);
        $t = eocFinancialTotals($rows);
        $X->row($si, [$c('مساهمة المدرسة', 6), $c('', 6), $c('', 6), $n($t['school_total'], 7)]);
        $rA = $X->rowCount($si) - 1; $rB = $X->rowCount($si);
        $X->row($si, [$c('المجموع العام', 6), $c('', 6), $c('', 6), $fx("D$rA+D$rB", 7)]);
        $X->row($si, [$c('المادة 6 من المرسوم الاشتراعي 47 تاريخ 29/06/1983.', 12)]);
    }
    return $X->bytes();
}
