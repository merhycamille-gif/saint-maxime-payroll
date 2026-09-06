<?php
/**
 * 🏛️ موازنة وزارة التربية والتعليم العالي (MEHE) — الموازنة المدرسية السنوية طبق نموذج
 * مصلحة التعليم الخاص (طلبه 2026-09-06: «خلّي البرنامج يطلّع هالموازنة لحاله من داتا كل مدرسة
 * متل نماذج ر5 ور6 — إكسل وPDF وبدون أي خطأ»).
 *
 * المبدأ: كل ما يخصّ الرواتب يُقرأ تلقائياً من monthly_salaries للمدرسة والسنة المختارتين
 * (هيئة التدريس في الملاك، المتعاقدون، الموظفون الإداريون، ومنها ملخّص الفئتين أ وب)،
 * وكل ما ليس بالبرنامج (معلومات المدرسة، الغرف، المعدات، الهيكل، النفقات ج/د، الإيرادات
 * بالصفوف، المنح، تعويضات الصرف، إداريون خارج الرواتب) خانات تُعبّأ مرّة وتُحفظ JSON
 * بجدول mehe_budget (يُركَّب ذاتياً) لكل مدرسة وسنة.
 *
 * المخرجات: صفحة معاينة/طباعة بالورقة الموحّدة (doc-view) + إكسل متعدّد الأوراق بصيغ حيّة
 * (PHP خالص — ZipArchive) بنفس بنية نموذج الوزارة: أي رقم يغيّره تتحدّث كل المجاميع.
 */

function ensureMeheBudget20260906(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS mehe_budget (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_id INT NOT NULL,
            school_year VARCHAR(9) NOT NULL,
            data LONGTEXT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_mehe (school_id, school_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 🏫 «ليش ما فيّي اختار مجموعة مدارس مع بعضها» (2026-09-06): النطاق = مدرسة أو مجموعة أو الكل —
        // عمود scope ("2" أو "2,3,4") هو مفتاح الحفظ؛ يُركَّب ذاتياً ويُعبّأ من school_id للصفوف القديمة
        if (!$db->query("SHOW COLUMNS FROM mehe_budget LIKE 'scope'")->fetch()) {
            $db->exec("ALTER TABLE mehe_budget ADD COLUMN scope VARCHAR(120) NULL AFTER school_id");
            $db->exec("UPDATE mehe_budget SET scope = CAST(school_id AS CHAR) WHERE scope IS NULL");
            try { $db->exec("ALTER TABLE mehe_budget DROP INDEX uq_mehe"); } catch (Throwable $e) {}
            $db->exec("ALTER TABLE mehe_budget ADD UNIQUE KEY uq_mehe_scope (scope, school_year)");
        }
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}
/** مفتاح النطاق من قائمة المدارس (مرتّبة) */
function meheScopeKey(array $ids): string { $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids); return implode(',', $ids); }

/* ===================== القوائم الثابتة بنموذج الوزارة (بنفس الترتيب) ===================== */
function meheRoomTypes(): array {
    return ['مسرح', 'غرفة فيديو', 'غرفة صف', 'غرف للمعلمين', 'مسكن للحارس', 'قاعة كومبيوتر', 'مستودع', 'مختبر فيزياء - كيمياء',
            'مختبر علوم طبيعية', 'كافتيريا', 'مختبر متحرك', 'مكتبة', 'غرفة تحضير للمختبر', 'غرفة السمعية', 'مختبر لغات', 'غرفة للادارة',
            'قاعة رسم', 'غرفة للتنظيف', 'مشغل فنون', 'مشغل تكنولوجيا', 'غرفة تمريض', 'قاعة رياضة'];
}
function meheEquipmentTypes(): array {
    return ['pc جهاز كمبيوتر', 'scanner ناسخ', 'lcd عاكس الكتروني', 'modem-fax ناقل معلومات', 'Active Board', 'server جهاز خادم',
            'printer طابعة', 'hub موزع', 'ups مخزن كهرباء', 'IPAD'];
}
function meheLanguages(): array {
    return ['fr' => 'الفرنسية', 'en' => 'الانكليزية', 'hy' => 'الارمنية', 'de' => 'الالمانية', 'it' => 'الايطالية', 'es' => 'الاسبانية', 'other' => 'لغات اخرى'];
}
function meheLevels(): array { return ['روضة', 'اساسي', 'متوسط', 'ثانوي']; }
/** بنود تكاليف التشغيل بترتيب صفحة «النفقات» بالنموذج — key => [التسمية, الفئة] */
function meheExpenseItems(): array {
    return [
        'supplies'    => ["لوازم إدارية /قرطاسية (بند 'ج')", 'ج'],
        'aid'         => ["مساعدات التلامذة المحتاجين (بند 'ج')", 'ج'],
        'insurance'   => ["تأمين (بند 'ج')", 'ج'],
        'medical'     => ["رقابة طبية (بند 'ج')", 'ج'],
        'foreign'     => ["ملحقات تعويضات متفرقة للاجانب (بند 'د')", 'د'],
        'heating'     => ["تدفئة (بند 'ج')", 'ج'],
        'maint'       => ["صيانة (بند 'ج')", 'ج'],
        'power'       => ["انارة وماء (بند 'ج')", 'ج'],
        'owners'      => ["تعويض اصحاب المدرسة (بند 'ج')", 'ج'],
        'cnss_extra'  => ["بدل تأمين اضافي فرق الضمان (بند 'ب')", 'ب'],
        'phone'       => ["هاتف وبريد (بند 'ج')", 'ج'],
        'cleaning'    => ["خدمة وتنظيف (مستلزمات التنظيف) (بند 'ج')", 'ج'],
        'consum'      => ["استهلاكات (بند 'ج')", 'ج'],
        'renew'       => ["تجديد وتطوير (بند 'ج')", 'ج'],
        'consol'      => ["نفقات تشغيلية للموازنة المجمعة (بند 'د')", 'د'],
        'rent'        => ["ايجارات (بند 'ج')", 'ج'],
        'eos_contr'   => ["تعويضات نهاية الخدمة للمعلمين المتعاقدين (بند 'د')", 'د'],
        'municipal'   => ["رسوم البلدية (بند 'د')", 'د'],
        'prev_year'   => ["تسوية عن السنة السابقة لتصحيح تعويض الخاضعين لقانون العمل (بند 'د')", 'د'],
        'training'    => ["دورات تأهيلية (بند 'ج')", 'ج'],
        'misc'        => ["مصاريف تشغيلية مختلفة(مصارف،ضرائب، رسوم معاملات، تلزيمات) (بند 'ج')", 'ج'],
    ];
}
/** ترتيب الفئة ج بملخّص الموازنة (صفحة 24 بالنموذج) */
function meheSummaryCOrder(): array {
    return ['phone', 'supplies', 'consum', 'heating', 'cleaning', 'rent', 'training', 'renew', 'insurance', 'maint', 'medical', 'owners', 'aid', 'power', 'misc'];
}
/** ترتيب الفئة د بملخّص الموازنة (صفحة 25): مفاتيح خاصة + بنود النفقات من فئة د */
function meheSummaryDOrder(): array {
    return ['over_limits', 'grants_contr', 'grants_admin_kids', 'grants_admin', 'severance', 'admin_tasks', 'prev_year', 'municipal', 'consol', 'foreign', 'eos_contr'];
}
function meheDLabels(): array {
    return [
        'over_limits'       => 'ما يتجاوز الحدود القصوى الملحوظة في الفقرة 3 لجهة عدد الساعات والفقرة 4 لجهة النسبة المئوية',
        'grants_contr'      => 'منح دراسية لأبناء المعلمين المتعاقدين',
        'grants_admin_kids' => 'منح دراسية لأبناء الموظفين الإداريين',
        'grants_admin'      => 'منح مدرسية للموظفين الإداريين',
        'severance'         => 'تعويضات الصرف للداخلين في الملاك',
        'admin_tasks'       => 'المهام الإضافية للموظفين للإداريين',
    ];
}

/* ===================== الافتراضيات + التحميل + الحفظ ===================== */
function meheDefaults(PDO $db, int $schoolId, string $sy): array {
    [$y1, $y2] = schoolYearToYears($sy);
    $syLbl = $y1 . '/' . $y2;
    // صفوف الإيرادات: صفوف المدرسة (class_levels الفاعلة) بتسميات الوزارة
    $revenues = [];
    $names = ['الروضة الاولى', 'الروضة الثانية', 'الروضة الثالثة', 'الاول اساسي', 'الثاني اساسي', 'الثالث اساسي', 'الرابع اساسي', 'الخامس اساسي',
              'السادس اساسي', 'السابع متوسط', 'الثامن متوسط', 'التاسع متوسط', 'العاشر ثانوي', 'الحادي عشر ثانوي', 'الثاني عشر ثانوي'];
    foreach ($names as $n) $revenues[] = ['program' => 'منهاج لبناني', 'class' => $n, 'fee_ll' => 0, 'fee_usd' => 0, 'students' => 0];
    $rooms = []; foreach (meheRoomTypes() as $r) $rooms[$r] = 0;
    $equip = []; foreach (meheEquipmentTypes() as $r) $equip[$r] = ['admin' => 0, 'edu' => 0];
    $langs = []; foreach (meheLanguages() as $k => $_) $langs[$k] = 3; // 1 اولي · 2 ثانوي · 3 غير معتمدة
    $langs['fr'] = 1; $langs['en'] = 2;
    $exp = []; foreach (meheExpenseItems() as $k => $_) $exp[$k] = ['ll' => 0, 'usd' => 0];
    return [
        'serial' => '', 'center_no' => '', 'subject' => 'موازنة السنة المدرسية ' . $syLbl,
        'reference' => 'القانون رقم 515 تاريخ 6/6/1996 الممد بالقانون رقم 281/2014',
        'director' => '', 'parents_head' => '', 'parents_phone' => '',
        'programs' => 'منهاج لبناني', 'levels' => 'منهاج لبناني: اساسي, ثانوي, روضة, متوسط',
        'classes' => "منهاج لبناني - روضة: الروضة الاولى, الروضة الثانية, الروضة الثالثة\nمنهاج لبناني - اساسي: الاول اساسي, الثاني اساسي, الثالث اساسي, الرابع اساسي, الخامس اساسي, السادس اساسي\nمنهاج لبناني - متوسط: السابع متوسط, الثامن متوسط, التاسع متوسط\nمنهاج لبناني - ثانوي: العاشر ثانوي, الحادي عشر ثانوي, الثاني عشر ثانوي",
        'fin_committee' => '', 'playground_open' => 0, 'playground_closed' => 0, 'owner' => 'خاص', 'shared_with' => '',
        'internet' => 'مفتوح على الشبكات العالمية بأكملها, مفتوح ومراقب, خاصة بالادارة والمعلميين', 'other_details' => '',
        'building_owner' => '', 'buildings_school' => 1, 'buildings_res' => 0,
        'languages' => $langs, 'rooms' => $rooms, 'equipment' => $equip,
        'struct_admin_law' => 0, 'struct_workers_law' => 0, 'struct_others' => 0,
        'classes_per_level' => ['روضة' => 0, 'اساسي' => 0, 'متوسط' => 0, 'ثانوي' => 0],
        'staff_mgmt' => 0, 'staff_supervision' => 0,
        'expenses' => $exp,
        'revenues' => $revenues,
        'grants' => [],            // [{student, teacher, cat(ملاك|بقية الكادر), class, ll, usd}]
        'severance' => [],         // [{name, eos_ll, eos_usd, tasks_ll, tasks_usd, receipt_no, receipt_date, notes}]
        'manual_admins' => [],     // إداريون خارج الرواتب [{name, mode, start_date, type, cnss_type, base, extra_ll, extra_usd, tasks_ll, grants_ll, transport, cnss, months}]
        'base_mode' => 'avg',      // avg = معدل الأشهر (تعريف النموذج) · oct = شهر تشرين الأول
        'excluded' => [],          // موظفون يُستثنون من الجداول (بقراره)
    ];
}
function meheLoad(PDO $db, array $ids, string $sy): array {
    ensureMeheBudget20260906();
    $schoolId = (int)($ids[0] ?? 0);
    $def = meheDefaults($db, $schoolId, $sy);
    try {
        $st = $db->prepare("SELECT data FROM mehe_budget WHERE scope = ? AND school_year = ?");
        $st->execute([meheScopeKey($ids), $sy]);
        $j = $st->fetchColumn();
        if ($j) {
            $saved = json_decode((string)$j, true) ?: [];
            foreach ($saved as $k => $v) {
                if (is_array($v) && isset($def[$k]) && is_array($def[$k]) && !isset($v[0]) && !in_array($k, ['revenues', 'grants', 'severance', 'manual_admins', 'excluded'], true)) {
                    $def[$k] = array_replace($def[$k], $v);   // قواميس (غرف/معدات/لغات/نفقات)
                } else {
                    $def[$k] = $v;
                }
            }
        }
    } catch (Throwable $e) {}
    // مدير المدرسة الافتراضي من ملف المدرسة إن وُجد (مدرسة واحدة)
    if ($def['director'] === '' && count($ids) === 1) {
        try { $s = $db->query("SELECT director_name FROM schools WHERE id = $schoolId")->fetchColumn(); if ($s) $def['director'] = (string)$s; } catch (Throwable $e) {}
    }
    return $def;
}
function meheSave(PDO $db, array $ids, string $sy, array $data): void {
    ensureMeheBudget20260906();
    $db->prepare("INSERT INTO mehe_budget (school_id, scope, school_year, data, updated_at) VALUES (?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE data = VALUES(data), school_id = VALUES(school_id), updated_at = NOW()")
       ->execute([(int)($ids[0] ?? 0), meheScopeKey($ids), $sy, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

/* ===================== نصوص الموظف بمصطلحات الوزارة ===================== */
function meheQualText($code): string {
    $m = ['ijaza_taalimiya' => 'اجازة تعليمية', 'ijaza_jamiya' => 'اجازة جامعية', 'jardinier_bt' => 'البكالوريا الفنية BT + BT2',
          'jardinier_ts' => 'البكالوريا الفنية TS', 'qsm2_thanawiya' => 'بكالوريا قسم ثاني', 'capes' => 'كابس'];
    $code = (string)$code;
    if ($code === '') return 'غيره';
    return $m[$code] ?? (function_exists('diplomaLabel') ? (string)diplomaLabel($code, 'ar') : $code);
}
function meheLevelText(array $e): string {
    $lv = [];
    $ids = array_filter(array_map('intval', explode(',', (string)($e['classes_taught'] ?? ''))));
    foreach ($ids as $id) {
        if ($id >= 1 && $id <= 3) $lv['روضة'] = 1;
        elseif ($id >= 4 && $id <= 9) $lv['اساسي'] = 1;
        elseif ($id >= 10 && $id <= 12) $lv['متوسط'] = 1;
        elseif ($id >= 13) $lv['ثانوي'] = 1;
    }
    if (!$lv) {
        foreach (explode(',', (string)($e['niveau_scolaire'] ?? '')) as $n) {
            $n = trim($n);
            if ($n === 'maternelle') $lv['روضة'] = 1;
            elseif ($n === 'primaire') $lv['اساسي'] = 1;
            elseif ($n === 'intermediaire') $lv['متوسط'] = 1;
            elseif ($n === 'secondaire') $lv['ثانوي'] = 1;
        }
    }
    if (count($lv) >= 4) return 'كل المراحل';
    $ordered = array_values(array_filter(meheLevels(), fn($l) => isset($lv[$l])));
    return $ordered ? implode('، ', $ordered) : 'غير محدد';
}
function meheRoleText(array $e): string {
    $jt = trim((string)($e['job_title'] ?? ''));
    if ($jt === '') return 'أستاذ \\ معلم';
    if ($jt === 'surveillant') return 'ناظر';
    if ($jt === 'comptable') return 'محاسب';
    return function_exists('jobTitleLabel') ? (string)jobTitleLabel($jt, 'ar') : $jt;
}

/* ===================== قراءة الرواتب: صفّ لكل موظف بأعمدة النموذج ===================== */
/**
 * لكل موظف بالمدرسة له رواتب بالسنة: القيمة الشهرية لكل عمود = معدل الأشهر التي فيها قيمة
 * (تعريف النموذج «الراتب الاساسي هو معدل الراتب الشهري على طول السنة») أو قيمة شهر تشرين
 * الأول (base_mode=oct)، وعدد الأشهر = الأشهر التي فيها قيمة > 0، والمجموع = الشهري × الأشهر
 * (بوضع المعدل = مجموع السنة الفعلي بالضبط).
 */
function mehePayroll(PDO $db, array $ids, string $sy, array $data): array {
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) $ids = [-1];
    $in = implode(',', $ids);
    [$y1, $y2] = schoolYearToYears($sy);
    $mode = ($data['base_mode'] ?? 'avg') === 'oct' ? 'oct' : 'avg';
    $excluded = array_map('intval', (array)($data['excluded'] ?? []));
    $cols = [
        'base' => 'base_plus_echelon_lbp', 'extra_ll' => 'extra_lbp + prime_fixe_lbp', 'bonus' => 'aide_complementaire_lbp',
        'transport' => 'transport_lbp', 'family' => 'family_allowance_lbp', 'cnss' => 'school_cnss_8_lbp', 'fund' => 'school_eoc_6_lbp',
    ];
    $sel = [];
    foreach ($cols as $k => $expr) {
        $sel[] = "SUM($expr) `{$k}_sum`";
        $sel[] = "SUM(($expr) > 0) `{$k}_m`";
        $sel[] = "SUM(CASE WHEN ms.month = 10 AND ms.year = $y1 THEN ($expr) ELSE 0 END) `{$k}_oct`";
    }
    $st = $db->prepare("SELECT e.*, s.name_ar school_name, COUNT(ms.id) months_all, " . implode(', ', $sel) . "
                        FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ?
                        LEFT JOIN schools s ON s.id = e.school_id
                        WHERE e.school_id IN ($in) AND e.is_deleted = 0
                          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)
                        GROUP BY e.id
                        ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), e.school_id, e.first_name_ar, e.last_name_ar");
    $st->execute([$sy]);
    $tit = []; $con = []; $adm = [];
    foreach ($st->fetchAll() as $r) {
        if (in_array((int)$r['id'], $excluded, true)) continue;
        $row = [
            'id' => (int)$r['id'], 'school' => (string)($r['school_name'] ?? ''),
            'name' => trim((string)$r['first_name_ar'] . ' ' . (string)$r['father_name_ar'] . ' ' . (string)$r['last_name_ar']) ?: trim($r['first_name_fr'] . ' ' . $r['last_name_fr']),
            'role' => meheRoleText($r), 'qual' => meheQualText($r['diploma'] ?? ''), 'level' => meheLevelText($r),
            'cadre_date' => ($r['titularization_date'] && $r['titularization_date'] !== '0000-00-00') ? formatDate($r['titularization_date'], 'j/n/Y') : '',
            'start_date' => ($r['hire_date'] && $r['hire_date'] !== '0000-00-00') ? formatDate($r['hire_date'], 'j/n/Y') : '',
            'h_cadre' => (float)($r['hours_per_week'] ?? 0) == floor((float)($r['hours_per_week'] ?? 0)) ? (int)$r['hours_per_week'] : (float)$r['hours_per_week'],
            'h_extra' => 0, 'mode' => 'دوام كامل', 'cnss_type' => ((int)($r['cnss_subject'] ?? 1) === 1) ? 'مضمون' : 'غير مضمون',
            'admin_type' => 'عادي', 'admin_mode' => meheRoleText($r),
            'extra_usd' => 0, 'retro' => 0, 'missions_ll' => 0, 'missions35' => 0, 'tasks_ll' => 0, 'grants_ll' => 0,
            'months' => [],
        ];
        foreach ($cols as $k => $_) {
            $sum = (float)$r[$k . '_sum']; $m = (int)$r[$k . '_m'];
            if ($mode === 'oct' && $m > 0) {
                $monthly = (float)$r[$k . '_oct'];
                if ($monthly <= 0) $monthly = $m > 0 ? $sum / $m : 0; // لا شهر تشرين ⇒ المعدل
            } else {
                $monthly = $m > 0 ? $sum / $m : 0;
            }
            $row[$k] = $monthly;
            $row['months'][$k] = $m;
            $row[$k . '_total'] = $monthly * $m;
        }
        $t = (string)$r['employee_type'];
        if ($t === 'enseignant_titulaire') $tit[] = $row;
        elseif ($t === 'enseignant_contractuel') $con[] = $row;
        else $adm[] = $row;
    }
    // إداريون خارج الرواتب (أُدخلوا يدوياً بالصفحة)
    foreach ((array)($data['manual_admins'] ?? []) as $ma) {
        if (trim((string)($ma['name'] ?? '')) === '') continue;
        $mm = max(1, (int)($ma['months'] ?? 12));
        $row = ['id' => 0, 'school' => (string)($ma['school'] ?? ''), 'name' => (string)$ma['name'], 'admin_mode' => (string)($ma['mode'] ?? ''), 'start_date' => (string)($ma['start_date'] ?? ''),
                'admin_type' => (string)($ma['type'] ?? 'عادي'), 'cnss_type' => (string)($ma['cnss_type'] ?? 'غير مضمون'), 'extra_usd' => 0, 'months' => [], 'manual' => true];
        foreach (['base', 'extra_ll', 'tasks_ll', 'grants_ll', 'transport', 'cnss'] as $k) {
            $v = (float)($ma[$k] ?? 0);
            $row[$k] = $v; $row['months'][$k] = $v > 0 ? $mm : 0; $row[$k . '_total'] = $v * ($v > 0 ? $mm : 0);
        }
        $row['extra_usd_total'] = 0;
        $adm[] = $row;
    }
    // عدد الأشهر لكل عمود (كما بالنموذج: رقم واحد للعمود = أكبر عدد أشهر بين الموظفين) + المجاميع
    $mk = function (array $rows, array $keys): array {
        $months = []; $tot = [];
        foreach ($keys as $k) {
            $months[$k] = 0; $tot[$k] = 0;
            foreach ($rows as $r) { $months[$k] = max($months[$k], (int)($r['months'][$k] ?? 0)); $tot[$k] += (float)($r[$k . '_total'] ?? 0); }
        }
        return [$months, $tot];
    };
    [$tm, $tt] = $mk($tit, ['base', 'extra_ll', 'extra_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'transport', 'family', 'cnss', 'fund']);
    [$cm, $ct] = $mk($con, ['base', 'extra_ll', 'extra_usd', 'bonus', 'tasks_ll', 'transport', 'cnss']);
    [$am, $at] = $mk($adm, ['base', 'extra_ll', 'extra_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss']);
    foreach (['tm', 'cm', 'am'] as $v) foreach ($$v as $k => $m) if ($m === 0) $$v[$k] = 12; // عمود فارغ ⇒ 12 كالنموذج
    return ['tit' => $tit, 'con' => $con, 'adm' => $adm, 'tit_months' => $tm, 'tit_tot' => $tt, 'con_months' => $cm, 'con_tot' => $ct,
            'adm_months' => $am, 'adm_tot' => $at, 'mode' => $mode, 'multi' => count($ids) > 1];
}

/* ===================== الملخّص (الفئات أ/ب/ج/د + ملخّص الميزانية + الإيرادات + المنح) ===================== */
function meheSummary(array $d, array $p): array {
    $tt = $p['tit_tot']; $ct = $p['con_tot']; $at = $p['adm_tot'];
    $exp = $d['expenses'];
    $g = function ($k, $c) use ($exp) { return (float)($exp[$k][$c] ?? 0); };
    // المنح
    $grTitLL = $grTitUSD = $grOthLL = $grOthUSD = 0; $grTitN = $grOthN = 0;
    foreach ((array)$d['grants'] as $gr) {
        if (trim((string)($gr['student'] ?? '')) === '') continue;
        if (($gr['cat'] ?? '') === 'ملاك') { $grTitN++; $grTitLL += (float)($gr['ll'] ?? 0); $grTitUSD += (float)($gr['usd'] ?? 0); }
        else { $grOthN++; $grOthLL += (float)($gr['ll'] ?? 0); $grOthUSD += (float)($gr['usd'] ?? 0); }
    }
    // تعويضات الصرف
    $sevLL = $sevUSD = 0;
    foreach ((array)$d['severance'] as $s) {
        if (trim((string)($s['name'] ?? '')) === '') continue;
        $sevLL += (float)($s['eos_ll'] ?? 0) + (float)($s['tasks_ll'] ?? 0); $sevUSD += (float)($s['eos_usd'] ?? 0) + (float)($s['tasks_usd'] ?? 0);
    }
    // الإيرادات
    $revRows = []; $students = 0; $revLL = $revUSD = 0;
    foreach ((array)$d['revenues'] as $rv) {
        if (trim((string)($rv['class'] ?? '')) === '') continue;
        $n = (int)($rv['students'] ?? 0); $fl = (float)($rv['fee_ll'] ?? 0); $fu = (float)($rv['fee_usd'] ?? 0);
        $revRows[] = ['program' => (string)($rv['program'] ?? ''), 'class' => (string)$rv['class'], 'fee_ll' => $fl, 'fee_usd' => $fu, 'students' => $n, 'tot_ll' => $fl * $n, 'tot_usd' => $fu * $n];
        $students += $n; $revLL += $fl * $n; $revUSD += $fu * $n;
    }
    $revAfterLL = $revLL - $grTitLL; $revAfterUSD = $revUSD - $grTitUSD;
    $payers = max(0, $students - $grTitN);
    $A = [
        ['أفراد الهيئة التعليمية في الملاك - رواتب', $tt['base'], 0],
        ['أفراد الهيئة التعليمية خارج الملاك - اجور', $ct['base'], 0],
        ['بدل مهمات اضافية للداخلين في الملاك من افراد الهيئة التعليمية', $tt['missions_ll'] + $tt['missions35'] + $tt['tasks_ll'], 0],
        ['تعويض المكافئات', $tt['bonus'] + $ct['bonus'], 0],
        ['أفراد هيئة ادارية ومستخدمون وسواهم من المرتبطين بسير العمل في المدرسة - اجور', $at['base'], 0],
        ['الاثر الرجعي', $tt['retro'], 0],
        ['الأجور الإضافية للمعلمين الملاك', $tt['extra_ll'], $tt['extra_usd']],
        ['الأجور الإضافية للمعلمين المتعاقدين', $ct['extra_ll'], $ct['extra_usd']],
        ['الأجور الإضافية للموظفين الإداريين', $at['extra_ll'], $at['extra_usd']],
    ];
    $B = [
        ['تعويض نقل', $tt['transport'] + $ct['transport'] + $at['transport'], 0],
        ['تعويض عائلي', $tt['family'], 0],
        ['اشتراكات الصندوق الوطني للضمان الاجتماعي', $tt['cnss'] + $ct['cnss'] + $at['cnss'], 0],
        ['مساهمة المدرسة في صندوق التعويضات لأفراد الهيئة التعليمية', ceil($tt['fund'] / 1000) * 1000, 0], // الوزارة تقرّبها للألف صعوداً
        [meheExpenseItems()['cnss_extra'][0], $g('cnss_extra', 'll'), $g('cnss_extra', 'usd')],
    ];
    $C = [];
    foreach (meheSummaryCOrder() as $k) $C[] = [meheExpenseItems()[$k][0], $g($k, 'll'), $g($k, 'usd')];
    $dl = meheDLabels();
    $Dm = [
        'over_limits' => [0, 0], 'grants_contr' => [$grOthLL, $grOthUSD], 'grants_admin_kids' => [0, 0],
        'grants_admin' => [$at['grants_ll'], 0], 'severance' => [$sevLL, $sevUSD], 'admin_tasks' => [$at['tasks_ll'], 0],
    ];
    $D = [];
    foreach (meheSummaryDOrder() as $k) {
        if (isset($Dm[$k])) $D[] = [$dl[$k], $Dm[$k][0], $Dm[$k][1]];
        else $D[] = [meheExpenseItems()[$k][0], $g($k, 'll'), $g($k, 'usd')];
    }
    $sum = fn(array $rows, int $i) => array_sum(array_map(fn($r) => (float)$r[$i], $rows));
    $abL = $sum($A, 1) + $sum($B, 1); $abU = $sum($A, 2) + $sum($B, 2);
    $abcL = $abL + $sum($C, 1); $abcU = $abU + $sum($C, 2);
    $allL = $abcL + $sum($D, 1); $allU = $abcU + $sum($D, 2);
    $pctAB = ($abcL + $abcU) > 0 ? round(($abL + $abU) / ($abcL + $abcU) * 100) : 0;
    $expTotalLL = 0; $expTotalUSD = 0;
    foreach (meheExpenseItems() as $k => $_) { $expTotalLL += $g($k, 'll'); $expTotalUSD += $g($k, 'usd'); }
    // هيكل الموظفين
    $staffTeaching = count($p['tit']) + count($p['con']);
    $staffTotal = (int)$d['staff_mgmt'] + (int)$d['staff_supervision'] + $staffTeaching;
    return [
        'A' => $A, 'B' => $B, 'C' => $C, 'D' => $D,
        'abL' => $abL, 'abU' => $abU, 'abcL' => $abcL, 'abcU' => $abcU, 'allL' => $allL, 'allU' => $allU,
        'pctAB' => $pctAB, 'pctC' => 100 - $pctAB,
        'revRows' => $revRows, 'students' => $students, 'revLL' => $revLL, 'revUSD' => $revUSD,
        'revAfterLL' => $revAfterLL, 'revAfterUSD' => $revAfterUSD, 'payers' => $payers,
        'avgFeeLL' => $payers > 0 ? $revAfterLL / $payers : 0, 'avgFeeUSD' => $payers > 0 ? $revAfterUSD / $payers : 0,
        'diffL' => $allL - $revAfterLL, 'diffU' => $allU - $revAfterUSD,
        'avgDueL' => $students > 0 ? round($allL / $students / 1000) * 1000 : 0, 'avgDueU' => $students > 0 ? round($allU / $students / 1000) * 1000 : 0,
        'grTitN' => $grTitN, 'grTitLL' => $grTitLL, 'grTitUSD' => $grTitUSD, 'grOthN' => $grOthN, 'grOthLL' => $grOthLL, 'grOthUSD' => $grOthUSD,
        'sevLL' => $sevLL, 'sevUSD' => $sevUSD, 'expTotalLL' => $expTotalLL, 'expTotalUSD' => $expTotalUSD,
        'staffTeaching' => $staffTeaching, 'staffInCadre' => count($p['tit']), 'staffOutCadre' => count($p['con']), 'staffTotal' => $staffTotal,
        'classesTotal' => array_sum(array_map('intval', (array)$d['classes_per_level'])),
    ];
}

/* ===================== مولّد إكسل متعدّد الأوراق (PHP خالص) بصيغ حيّة ===================== */
class MeheXlsx {
    private array $sheets = [];
    private static function xa($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
    public static function col(int $i): string { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
    /** أنماط: 0 عادي · 1 عنوان · 2 رأس · 3 نص بإطار · 4 رقم · 5 رقم بخانتين · 6 مجموع نص · 7 مجموع رقم · 8 مجموع رقم بخانتين · 9 نص عريض · 10 ملاحظة حمراء · 11 نسبة · 12 نص عادي · 13 وسط بإطار · 14 أخضر بإطار */
    public function sheet(string $name, array $widths, bool $landscape = true): int {
        $this->sheets[] = ['name' => mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':', "'"], ' ', $name), 0, 31, 'UTF-8'), 'widths' => $widths, 'rows' => [], 'merges' => [], 'breaks' => [], 'landscape' => $landscape];
        return count($this->sheets) - 1;
    }
    /** خلية: ['v'=>قيمة, 's'=>نمط, 'f'=>صيغة بلا =] — أو قيمة خام (نص/رقم) */
    public function row(int $si, array $cells, ?float $height = null): int {
        $this->sheets[$si]['rows'][] = ['cells' => $cells, 'h' => $height];
        return count($this->sheets[$si]['rows']); // رقم الصفّ (1-based)
    }
    public function merge(int $si, string $ref): void { $this->sheets[$si]['merges'][] = $ref; }
    public function pageBreak(int $si, int $afterRow): void { $this->sheets[$si]['breaks'][] = $afterRow; }
    public function rowCount(int $si): int { return count($this->sheets[$si]['rows']); }
    public static function c($v, int $s = 3, ?string $f = null): array { return ['v' => $v, 's' => $s, 'f' => $f]; }
    public static function n($v, int $s = 4): array { return ['v' => $v, 's' => $s, 'f' => null]; }
    public static function fx(string $f, int $s = 4): array { return ['v' => null, 's' => $s, 'f' => $f]; }

    private function sheetXml(array $sh): string {
        $rowsXml = '';
        foreach ($sh['rows'] as $ri => $row) {
            $r = $ri + 1;
            $rowsXml .= '<row r="' . $r . '"' . ($row['h'] ? ' ht="' . $row['h'] . '" customHeight="1"' : '') . '>';
            foreach ($row['cells'] as $ci => $cell) {
                if (!is_array($cell)) $cell = ['v' => $cell, 's' => is_numeric($cell) && !is_string($cell) ? 4 : 3, 'f' => null];
                $ref = self::col($ci + 1) . $r;
                $s = (int)($cell['s'] ?? 3);
                if (!empty($cell['f'])) {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $s . '"><f>' . self::xa($cell['f']) . '</f></c>';
                } elseif ($cell['v'] === null || $cell['v'] === '') {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                } elseif (is_int($cell['v']) || is_float($cell['v'])) {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . (is_float($cell['v']) ? rtrim(rtrim(number_format($cell['v'], 4, '.', ''), '0'), '.') : $cell['v']) . '</v></c>';
                } else {
                    $rowsXml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::xa($cell['v']) . '</t></is></c>';
                }
            }
            $rowsXml .= '</row>';
        }
        $colsXml = '<cols>';
        foreach ($sh['widths'] as $i => $w) $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        $colsXml .= '</cols>';
        $merges = '';
        foreach ($sh['merges'] as $m) $merges .= '<mergeCell ref="' . $m . '"/>';
        $mergesXml = $merges ? '<mergeCells count="' . count($sh['merges']) . '">' . $merges . '</mergeCells>' : '';
        $breaksXml = '';
        if ($sh['breaks']) {
            $breaksXml = '<rowBreaks count="' . count($sh['breaks']) . '" manualBreakCount="' . count($sh['breaks']) . '">';
            foreach ($sh['breaks'] as $b) $breaksXml .= '<brk id="' . $b . '" max="16383" man="1"/>';
            $breaksXml .= '</rowBreaks>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0" zoomScale="90"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>' . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>' . $mergesXml
            . '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="' . ($sh['landscape'] ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>'
            . $breaksXml
            . '</worksheet>';
    }
    private function styles(): string {
        $b = '<border><left style="thin"><color rgb="FFBBBBBB"/></left><right style="thin"><color rgb="FFBBBBBB"/></right><top style="thin"><color rgb="FFBBBBBB"/></top><bottom style="thin"><color rgb="FFBBBBBB"/></bottom><diagonal/></border>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="#,##0.00"/><numFmt numFmtId="166" formatCode="0.00 %"/></numFmts>'
            . '<fonts count="6">'
            . '<font><sz val="10"/><name val="Arial"/></font>'                               // 0
            . '<font><b/><sz val="14"/><name val="Arial"/></font>'                           // 1 عنوان
            . '<font><b/><sz val="10"/><name val="Arial"/></font>'                           // 2 عريض
            . '<font><sz val="10"/><color rgb="FFC00000"/><name val="Arial"/></font>'        // 3 أحمر
            . '<font><b/><sz val="11"/><name val="Arial"/></font>'                           // 4 عنوان فرعي
            . '<font><sz val="10"/><color rgb="FF2E7D32"/><name val="Arial"/></font>'        // 5 أخضر
            . '</fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill>'   // 2 رأس
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4D6"/></patternFill></fill>'   // 3 مجموع
            . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $b . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="15">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'                                                                                              // 0
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'       // 1 عنوان
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 2 رأس
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>'  // 3 نص
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 4 رقم
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 5 رقم.00
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' // 6 مجموع نص
            . '<xf numFmtId="164" fontId="2" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 7
            . '<xf numFmtId="165" fontId="2" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 8
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'       // 9 عريض
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'       // 10 أحمر
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 11 نسبة
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>'         // 12 نص عادي
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 13 وسط بإطار
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 14 أخضر
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
    public function bytes(): string {
        $parts = [];
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $sheetsXml = ''; $rels = '';
        foreach ($this->sheets as $i => $sh) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetsXml .= '<sheet name="' . self::xa($sh['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $parts['xl/worksheets/sheet' . $n . '.xml'] = $this->sheetXml($sh);
        }
        $ct .= '</Types>';
        $ns = count($this->sheets) + 1;
        $parts['[Content_Types].xml'] = $ct;
        $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $parts['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets><calcPr fullCalcOnLoad="1"/></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
            . '<Relationship Id="rId' . $ns . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $parts['xl/styles.xml'] = $this->styles();
        $tmp = tempnam(sys_get_temp_dir(), 'mehe');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($parts as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}

/** يبني ملف الإكسل الكامل (11 ورقة بترتيب النموذج) ويعيد بايتاته */
function meheBuildXlsx(array $d, array $p, array $s, array $school, string $sy): string {
    $X = new MeheXlsx();
    $c = [MeheXlsx::class, 'c']; $n = [MeheXlsx::class, 'n']; $fx = [MeheXlsx::class, 'fx']; $L = [MeheXlsx::class, 'col'];
    [$y1, $y2] = schoolYearToYears($sy);
    $schoolName = (string)($school['name_ar'] ?? '');
    $head = function (int $si, array $labels, float $h = 30) use ($X, $c) { $X->row($si, array_map(fn($l) => $c($l, 2), $labels), $h); };
    $tot = fn(array $cells) => $cells;

    // ١) الطلب
    $si = $X->sheet('الطلب', [26, 44]);
    $X->row($si, [$c('الرقم التسلسلي: ' . $d['serial'], 1)], 24);
    $X->row($si, [$c('جانب وزارة التربية والتعليم العالي', 1)], 24);
    $X->row($si, []);
    $X->row($si, [$c('مصلحة التعليم الخاص', 1)], 24);
    $X->row($si, []);
    $X->row($si, [$c('المستدعية', 6), $c($schoolName, 3)], 22);
    $X->row($si, [$c('رقم المركز التربوي', 6), $c((string)$d['center_no'], 3)], 22);
    $X->row($si, [$c('الموضوع', 6), $c($d['subject'], 3)], 22);
    $X->row($si, [$c('المرجع', 6), $c($d['reference'], 3)], 22);
    $X->row($si, []);
    $X->row($si, [$c('نودعكم ربط الموازنة المدرسية للسنة ' . $y1 . '/' . $y2 . ' مع المستندات المرفقة:', 12)]);
    foreach (['1 - محاضر اللجنة المالية', '2 - بيان صندوق التعويضات', '3 - تقرير التدقيق'] as $t) $X->row($si, [$c($t, 12)]);
    $X->row($si, []); $X->row($si, [$c('واقبلوا فائق الاحترام', 12)]); $X->row($si, []); $X->row($si, []);
    $X->row($si, [$c('توقيع مدير(ة) المدرسة', 12), $c('ختم المدرسة', 12)]);

    // ٢) معلومات المدرسة
    $si = $X->sheet('معلومات المدرسة', [34, 30, 30, 30]);
    $X->row($si, [$c('معلومات المدرسة', 1)], 26); $X->row($si, []);
    $X->row($si, [$c('اسم المدير: ' . $d['director'], 9)]);
    $X->row($si, [$c('رئيس لجنة أولياء الأمور: ' . $d['parents_head'], 9)]);
    $X->row($si, [$c('رقم هاتف رئيس لجنة أولياء الأمور: ' . $d['parents_phone'], 9)]); $X->row($si, []);
    $r = $X->row($si, [$c('البرامج', 6), $c($d['programs'], 3)]); $X->merge($si, "B$r:D$r");
    $r = $X->row($si, [$c('مستوى التعليم', 6), $c($d['levels'], 3)]); $X->merge($si, "B$r:D$r");
    $r = $X->row($si, [$c('الصفوف', 6), $c($d['classes'], 3)], 16 * max(1, substr_count($d['classes'], "\n") + 1)); $X->merge($si, "B$r:D$r");
    $X->row($si, []); $X->row($si, [$c('أعضاء اللجنة المالية', 9)]);
    $i = 0; foreach (array_filter(array_map('trim', explode("\n", (string)$d['fin_committee']))) as $m) $X->row($si, [$c(++$i . '. ' . $m, 12)]);
    $X->row($si, []); $X->row($si, [$c('استطلاع', 1)], 24);
    $X->row($si, [$c('مساحة الملاعب المفتوحة (م²): ' . $d['playground_open'], 9)]);
    $X->row($si, [$c('مساحة الملاعب المغلقة (م²): ' . $d['playground_closed'], 9)]); $X->row($si, []);
    foreach ([['مالك العقار', $d['owner']], ['مباني مشتركة مع', $d['shared_with']], ['استخدام الانترنت', $d['internet']], ['تفاصيل اخرى', $d['other_details']]] as [$lb, $v]) {
        $r = $X->row($si, [$c($lb, 6), $c($v, 3)]); $X->merge($si, "B$r:D$r");
    }
    $X->row($si, []); $X->row($si, [$c('اسم مالك المبنى: ' . $d['building_owner'], 9)]);
    $head($si, ['نوع المبنى', 'عدد المباني']);
    $X->row($si, [$c('بناء مدرسي', 6), $n((int)$d['buildings_school'])]); $X->row($si, [$c('بناء سكني', 6), $n((int)$d['buildings_res'])]);
    $X->row($si, []); $X->row($si, [$c('اللغات', 9)]);
    $head($si, ['اللغة', 'اولي', 'ثانوي', 'غير معتمدة في المدرسة']);
    foreach (meheLanguages() as $k => $lb) {
        $v = (int)($d['languages'][$k] ?? 3);
        $X->row($si, [$c($lb, 6), $c($v === 1 ? '◉' : '○', 13), $c($v === 2 ? '◉' : '○', 13), $c($v === 3 ? '◉' : '○', 13)]);
    }
    $X->row($si, []); $X->row($si, [$c('الغرف والقاعات', 9)]);
    $head($si, ['نوع الغرفة', 'عدد الغرف']);
    foreach (meheRoomTypes() as $rt) $X->row($si, [$c($rt, 6), $n((int)($d['rooms'][$rt] ?? 0))]);
    $X->row($si, []); $X->row($si, [$c('المعدات التقنية', 9)]);
    $head($si, ['المعدات', 'عدد المستخدم من قبل الإدارة', 'عدد المستخدم لأغراض تعليمية']);
    foreach (meheEquipmentTypes() as $et) $X->row($si, [$c($et, 6), $n((int)($d['equipment'][$et]['admin'] ?? 0)), $n((int)($d['equipment'][$et]['edu'] ?? 0))]);

    // ٣-٥) جداول الموظفين — دالة مشتركة
    $staffSheet = function (string $name, string $title, array $cols, array $keys, array $rows, array $months, array $widths, int $firstNum, array $dec2, int $sigCol) use ($X, $c, $n, $fx, $L, $head): array {
        $si = $X->sheet($name, $widths);
        $X->row($si, [$c($title, 1)], 26);
        $head($si, $cols, 48);
        $first = $X->rowCount($si) + 1;
        foreach ($rows as $r) {
            $cells = [];
            foreach ($keys as $i => $k) {
                $col = $i + 1;
                if ($col >= $firstNum) $cells[] = $n((float)($r[$k] ?? 0), in_array($k, $dec2, true) ? 5 : 4);
                elseif ($col <= 1 || $k === 'school') $cells[] = $c((string)($r[$k] ?? ''), 3);
                else $cells[] = $c((string)($r[$k] ?? ''), 13);
            }
            $X->row($si, $cells, 20);
        }
        $last = $X->rowCount($si);
        $mcells = [$c('عدد الاشهر', 2)];
        foreach ($keys as $i => $k) { $col = $i + 1; if ($col === 1) continue; $mcells[] = $col >= $firstNum ? $n((int)($months[$k] ?? 12), 4) : $c('', 2); }
        $mrow = $X->row($si, $mcells);
        $tcells = [$c('المجموع', 6)];
        foreach ($keys as $i => $k) {
            $col = $i + 1; if ($col === 1) continue;
            $tcells[] = $col >= $firstNum ? $fx('SUM(' . $L($col) . $first . ':' . $L($col) . $last . ')*' . $L($col) . $mrow, 8) : $c('', 6);
        }
        $trow = $X->row($si, $tcells, 20);
        $X->row($si, []);
        $X->row($si, [$c('الراتب الاساسي هو معدل الراتب الشهري على طول السنة', 10)]);
        $X->row($si, []);
        $sig = array_fill(0, $sigCol, $c('', 0)); $sig[0] = $c('توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)', 12); $sig[$sigCol - 1] = $c('توقيع مدير المدرسة', 12);
        $X->row($si, $sig);
        $refs = [];
        foreach ($keys as $i => $k) if ($i + 1 >= $firstNum) $refs[$k] = "'" . $name . "'!" . $L($i + 1) . $trow;
        return $refs;
    };
    // عند مجموعة مدارس: عمود «المدرسة» بعد الاسم بكل جدول موظفين
    $multi = !empty($p['multi']);
    $mc = fn(array $cols) => $multi ? array_merge([$cols[0], 'المدرسة'], array_slice($cols, 1)) : $cols;
    $mk = fn(array $keys) => $multi ? array_merge([$keys[0], 'school'], array_slice($keys, 1)) : $keys;
    $mw = fn(array $w) => $multi ? array_merge([$w[0], 24], array_slice($w, 1)) : $w;
    $mf = fn(int $f) => $multi ? $f + 1 : $f;
    $TIT = $staffSheet('هيئة التدريس في الملاك', 'أعضاء هيئة التدريس في الملاك',
        $mc(['الاسم', 'دور الموظف', 'مؤهلات المعلم', 'مستوى التعليم', 'تاريخ الدخول الى الملاك', 'تاريخ مباشرة العمل', 'ساعات أسبوعية (ملاك)', 'ساعات أسبوعية (اضافية)',
         'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'الأثر الرجعي', 'أجور مهمات تتجاوز نصاب العمل ل.ل', 'أجور مهمات تجاوز الـ35 ساعه', 'المكافآت', 'مهام إضافية ل.ل',
         'تعويض نقل', 'تعويض عائلي', 'مساهمة الصندوق الوطني للضمان الاجتماعي', 'صندوق التعويضات']),
        $mk(['name', 'role', 'qual', 'level', 'cadre_date', 'start_date', 'h_cadre', 'h_extra', 'base', 'extra_ll', 'extra_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'transport', 'family', 'cnss', 'fund']),
        $p['tit'], $p['tit_months'], $mw([22, 12, 18, 12, 12, 12, 9, 9, 16, 19, 12, 10, 13, 13, 11, 11, 18, 14, 18, 17]), $mf(9), ['missions_ll', 'missions35', 'cnss', 'fund'], 12);
    $CON = $staffSheet('هيئة التدريس المتعاقدون', 'أعضاء هيئة التدريس المتعاقدين',
        $mc(['الاسم', 'دور الموظف', 'نمط العمل', 'نوع الضمان', 'مؤهلات المعلم', 'مستوى التعليم', 'تاريخ مباشرة العمل', 'ساعات أسبوعية', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'المكافآت', 'مهام إضافية ل.ل', 'تعويض نقل', 'مساهمة الصندوق الوطني للضمان الاجتماعي']),
        $mk(['name', 'role', 'mode', 'cnss_type', 'qual', 'level', 'start_date', 'h_cadre', 'base', 'extra_ll', 'extra_usd', 'bonus', 'tasks_ll', 'transport', 'cnss']),
        $p['con'], $p['con_months'], $mw([22, 12, 10, 10, 18, 12, 12, 9, 16, 19, 13, 11, 12, 17, 18]), $mf(9), ['cnss'], 10);
    $ADM = $staffSheet('الموظفون الإداريون', 'الموظفون الإداريون',
        $mc(['الاسم', 'نمط العمل', 'تاريخ مباشرة العمل', 'نوع الموظف الاداري', 'نوع الضمان', 'أساس الراتب', 'الأجور الإضافية ل.ل', 'الأجور الإضافية د.أ', 'مهام إضافية ل.ل', 'منح مدرسية ل.ل', 'تعويض نقل', 'مساهمة الصندوق الوطني للضمان الاجتماعي']),
        $mk(['name', 'admin_mode', 'start_date', 'admin_type', 'cnss_type', 'base', 'extra_ll', 'extra_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss']),
        $p['adm'], $p['adm_months'], $mw([22, 20, 13, 14, 12, 16, 19, 13, 13, 13, 17, 18]), $mf(6), ['cnss'], 8);

    // ٦) الهيكل
    $si = $X->sheet('الهيكل الإداري والتعليمي', [50, 16]);
    $X->row($si, [$c('الهيكل الإداري والتعليمي', 1)], 26); $X->row($si, []);
    $X->row($si, [$c('الهيكل الإداري والتعليمي', 9)]); $head($si, ['الوصف', 'العدد']);
    $X->row($si, [$c('عدد الإداريين الخاضعين لقانون العمل', 6), $n((int)$d['struct_admin_law'])]);
    $X->row($si, [$c('عدد المستخدمين الخاضعين لقانون العمل', 6), $n((int)$d['struct_workers_law'])]);
    $X->row($si, [$c('عدد باقي المرتبطين بسير العمل', 6), $n((int)$d['struct_others'])]);
    $X->row($si, []); $X->row($si, [$c('الهيكل التعليمي', 9)]); $head($si, ['المستوى التعليمي', 'عدد الفصول']);
    $f1 = $X->rowCount($si) + 1;
    foreach (meheLevels() as $lv) $X->row($si, [$c($lv, 6), $n((int)($d['classes_per_level'][$lv] ?? 0))]);
    $f2 = $X->rowCount($si);
    $X->row($si, [$c('إجمالي عدد الفصول', 6), $fx("SUM(B$f1:B$f2)", 7)]);
    $X->row($si, []); $X->row($si, [$c('هيكل الموظفين', 9)]); $head($si, ['الوصف', 'العدد']);
    $rm = $X->row($si, [$c('عدد القائمين بالإدارة التعليمية (مدير-مساعد-منسق- مشرف)', 6), $n((int)$d['staff_mgmt'])]);
    $rn = $X->row($si, [$c('عدد القائمين بالنظارة', 6), $n((int)$d['staff_supervision'])]);
    $rt = $X->row($si, [$c('عدد القائمين بالتدريس', 6), $n($s['staffTeaching'])]);
    $X->row($si, [$c('عدد الداخلين في الملاك', 6), $n($s['staffInCadre'])]);
    $X->row($si, [$c('عدد غير الداخلين في الملاك', 6), $n($s['staffOutCadre'])]);
    $X->row($si, [$c('إجمالي عدد الموظفين', 6), $fx("B$rm+B$rn+B$rt", 7)]);

    // ٧) الطلاب المعفيون
    $si = $X->sheet('الطلاب المعفيون', [22, 26, 14, 34, 18, 16]);
    $X->row($si, [$c('قائمة الطلاب المعفيين', 1)], 26);
    $head($si, ['اسم الطالب', 'عضو هيئة التدريس', 'فئة المعلم', 'الصف', 'المنحة المقدمة ل.ل', 'المنحة المقدمة د.أ']);
    $g1 = $X->rowCount($si) + 1;
    $grants = array_values(array_filter((array)$d['grants'], fn($g) => trim((string)($g['student'] ?? '')) !== ''));
    foreach ($grants as $g) $X->row($si, [$c($g['student'], 3), $c($g['teacher'] ?? '', 3), $c($g['cat'] ?? '', 13), $c($g['class'] ?? '', 3), $n((float)($g['ll'] ?? 0)), $n((float)($g['usd'] ?? 0))]);
    if (!$grants) $X->row($si, [$c('', 3), $c('', 3), $c('', 13), $c('', 3), $n(0), $n(0)]);
    $g2 = $X->rowCount($si);
    $GR = [];
    foreach ([['ملاك', 'المنح الدراسية للمعلمين داخل الملاك', 'tit'], ['بقية الكادر', 'المنح الدراسية لبقية الكادر', 'oth']] as [$cat, $ttl, $key]) {
        $X->row($si, []);
        $r = $X->row($si, [$c($ttl, 2)]); $X->merge($si, "A$r:F$r");
        $rc = $X->row($si, [$c('العدد', 6), $fx("COUNTIF(C$g1:C$g2,\"$cat\")", 7)]);
        $rl = $X->row($si, [$c('إجمالي المنحة ل.ل', 6), $fx("SUMIF(C$g1:C$g2,\"$cat\",E$g1:E$g2)", 7)]);
        $ru = $X->row($si, [$c('إجمالي المنحة د.أ', 6), $fx("SUMIF(C$g1:C$g2,\"$cat\",F$g1:F$g2)", 7)]);
        $GR[$key] = ['n' => "'الطلاب المعفيون'!B$rc", 'll' => "'الطلاب المعفيون'!B$rl", 'usd' => "'الطلاب المعفيون'!B$ru"];
    }

    // ٨) تعويضات الصرف
    $si = $X->sheet('تعويضات الصرف', [22, 20, 20, 22, 22, 16, 16, 20]);
    $X->row($si, [$c('تعويضات الصرف للداخلين في الملاك', 1)], 26);
    $head($si, ['اسم المستفيد', 'تعويضات نهاية الخدمة ل.ل', 'تعويضات نهاية الخدمة د.أ', 'تعويضات المهام الإضافية ل.ل', 'تعويضات المهام الإضافية د.أ', 'رقم إيصال الدفع', 'تاريخ الإيصال', 'ملاحظات']);
    $s1 = $X->rowCount($si) + 1;
    $sev = array_values(array_filter((array)$d['severance'], fn($x) => trim((string)($x['name'] ?? '')) !== ''));
    foreach ($sev as $x) $X->row($si, [$c($x['name'], 3), $n((float)($x['eos_ll'] ?? 0)), $n((float)($x['eos_usd'] ?? 0)), $n((float)($x['tasks_ll'] ?? 0)), $n((float)($x['tasks_usd'] ?? 0)), $c($x['receipt_no'] ?? '', 13), $c($x['receipt_date'] ?? '', 13), $c($x['notes'] ?? '', 3)]);
    for ($i = count($sev); $i < 5; $i++) $X->row($si, [$c('', 3), $n(0), $n(0), $n(0), $n(0), $c('', 13), $c('', 13), $c('', 3)]);
    $s2 = $X->rowCount($si);
    $X->row($si, []);
    $rsl = $X->row($si, [$c('المجموع بالليرة:', 6), $fx("SUM(B$s1:B$s2)+SUM(D$s1:D$s2)", 7)]);
    $rsu = $X->row($si, [$c('المجموع بالدولار:', 6), $fx("SUM(C$s1:C$s2)+SUM(E$s1:E$s2)", 7)]);
    $SEV = ['ll' => "'تعويضات الصرف'!B$rsl", 'usd' => "'تعويضات الصرف'!B$rsu"];

    // ٩) تكاليف التشغيل
    $si = $X->sheet('تكاليف التشغيل', [64, 22, 18]);
    $X->row($si, [$c('تكاليف التشغيل', 1)], 26); $X->row($si, [$c('النفقات', 9)]);
    $head($si, ['النفقة', 'القيمة بالليرة اللبنانية', 'القيمة بالدولار']);
    $e1 = $X->rowCount($si) + 1; $EXP = [];
    foreach (meheExpenseItems() as $k => [$lb, $cat]) {
        $r = $X->row($si, [$c($lb, 6), $n((float)($d['expenses'][$k]['ll'] ?? 0)), $n((float)($d['expenses'][$k]['usd'] ?? 0))]);
        $EXP[$k] = ["'تكاليف التشغيل'!B$r", "'تكاليف التشغيل'!C$r"];
    }
    $e2 = $X->rowCount($si);
    $X->row($si, [$c('مجموع', 6), $fx("SUM(B$e1:B$e2)", 7), $fx("SUM(C$e1:C$e2)", 7)]);

    // ١٠) الإيرادات
    $si = $X->sheet('الإيرادات', [26, 30, 20, 18, 12, 22, 16]);
    $X->row($si, [$c('الإيرادات', 1)], 26);
    $head($si, ['البرنامج', 'الصف', 'الرسوم الدراسيه ل.ل', 'الرسوم الدراسيه د.أ', 'عدد الطلاب', 'المجموع للصف ل.ل', 'المجموع للصف د.أ']);
    $v1 = $X->rowCount($si) + 1;
    foreach ($s['revRows'] as $rv) {
        $r = $X->rowCount($si) + 1;
        $X->row($si, [$c($rv['program'], 6), $c($rv['class'], 3), $n($rv['fee_ll']), $n($rv['fee_usd']), $n($rv['students']), $fx("C$r*E$r"), $fx("D$r*E$r")]);
    }
    if (!$s['revRows']) $X->row($si, [$c('', 6), $c('', 3), $n(0), $n(0), $n(0), $n(0), $n(0)]);
    $v2 = $X->rowCount($si);
    $vt = $X->row($si, [$c('المجموع', 6), $c('', 6), $c('', 6), $c('', 6), $fx("SUM(E$v1:E$v2)", 7), $fx("SUM(F$v1:F$v2)", 7), $fx("SUM(G$v1:G$v2)", 7)]);
    $X->row($si, []);
    $rs = $X->row($si, [$c('عدد الطلاب الكلي', 6), $c('', 6), $fx("E$vt")]); $X->merge($si, "A$rs:B$rs");
    $rg = $X->row($si, [$c('عدد طلاب المنح للمدرسين داخل الملاك', 6), $c('', 6), $fx($GR['tit']['n'])]); $X->merge($si, "A$rg:B$rg");
    $rl = $X->row($si, [$c('إجمالي الايرادات بعد حسم المنح الدراسية لأبناء المعلمين الملاك ل.ل', 6), $c('', 6), $fx("F$vt-" . $GR['tit']['ll'], 7)], 30); $X->merge($si, "A$rl:B$rl");
    $ru = $X->row($si, [$c('إجمالي الايرادات بعد حسم المنح الدراسية لأبناء المعلمين الملاك د.أ', 6), $c('', 6), $fx("G$vt-" . $GR['tit']['usd'], 7)], 30); $X->merge($si, "A$ru:B$ru");
    $r = $X->row($si, [$c('متوسط الرسوم الدراسية ل.ل', 6), $c('', 6), $fx("IF(C$rs-C$rg>0,C$rl/(C$rs-C$rg),0)", 5)]); $X->merge($si, "A$r:B$r");
    $r = $X->row($si, [$c('متوسط الرسوم الدراسية د.أ', 6), $c('', 6), $fx("IF(C$rs-C$rg>0,C$ru/(C$rs-C$rg),0)", 5)]); $X->merge($si, "A$r:B$r");
    $X->row($si, []);
    $X->row($si, [$c('توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)', 12), $c('', 0), $c('', 0), $c('', 0), $c('توقيع مدير المدرسة', 12)]);
    $REV = ['ll' => "'الإيرادات'!C$rl", 'usd' => "'الإيرادات'!C$ru", 'students' => "'الإيرادات'!C$rs"];

    // ١١) ملخّص الموازنة
    $si = $X->sheet('ملخص الموازنة', [66, 22, 18, 22, 16]);
    $X->row($si, [$c('ملخص الموازنة', 1)], 26); $X->row($si, []);
    $section = function (string $ttl) use ($X, $si, $c, $head) { $X->row($si, [$c($ttl, 9)]); $head($si, ['اسم النفقة', 'المجموع بالليرة', 'المجموع بالدولار']); return $X->rowCount($si) + 1; };
    $a1 = $section('النفقات من الفئة أ');
    $A = [
        ['أفراد الهيئة التعليمية في الملاك - رواتب', $TIT['base'], '0'],
        ['أفراد الهيئة التعليمية خارج الملاك - اجور', $CON['base'], '0'],
        ['بدل مهمات اضافية للداخلين في الملاك من افراد الهيئة التعليمية', $TIT['missions_ll'] . '+' . $TIT['missions35'] . '+' . $TIT['tasks_ll'], '0'],
        ['تعويض المكافئات', $TIT['bonus'] . '+' . $CON['bonus'], '0'],
        ['أفراد هيئة ادارية ومستخدمون وسواهم من المرتبطين بسير العمل في المدرسة - اجور', $ADM['base'], '0'],
        ['الاثر الرجعي', $TIT['retro'], '0'],
        ['الأجور الإضافية للمعلمين الملاك', $TIT['extra_ll'], $TIT['extra_usd']],
        ['الأجور الإضافية للمعلمين المتعاقدين', $CON['extra_ll'], $CON['extra_usd']],
        ['الأجور الإضافية للموظفين الإداريين', $ADM['extra_ll'], $ADM['extra_usd']],
    ];
    foreach ($A as [$lb, $fl, $fu]) $X->row($si, [$c($lb, 6), $fx($fl, 4), $fx($fu, 4)]);
    $a2 = $X->rowCount($si); $X->row($si, []);
    $b1 = $section('النفقات من الفئة ب');
    $B = [
        ['تعويض نقل', $TIT['transport'] . '+' . $CON['transport'] . '+' . $ADM['transport'], '0'],
        ['تعويض عائلي', $TIT['family'], '0'],
        ['اشتراكات الصندوق الوطني للضمان الاجتماعي', $TIT['cnss'] . '+' . $CON['cnss'] . '+' . $ADM['cnss'], '0'],
        ['مساهمة المدرسة في صندوق التعويضات لأفراد الهيئة التعليمية', 'ROUNDUP(' . $TIT['fund'] . ',-3)', '0'],
        [meheExpenseItems()['cnss_extra'][0], $EXP['cnss_extra'][0], $EXP['cnss_extra'][1]],
    ];
    foreach ($B as [$lb, $fl, $fu]) $X->row($si, [$c($lb, 6), $fx($fl, 5), $fx($fu, 5)]);
    $b2 = $X->rowCount($si); $X->row($si, []);
    $X->pageBreak($si, $X->rowCount($si));
    $c1 = $section('النفقات من الفئة ج');
    foreach (meheSummaryCOrder() as $k) $X->row($si, [$c(meheExpenseItems()[$k][0], 6), $fx($EXP[$k][0], 4), $fx($EXP[$k][1], 4)]);
    $c2 = $X->rowCount($si); $X->row($si, []);
    $X->pageBreak($si, $X->rowCount($si));
    $d1 = $section('النفقات من الفئة د');
    $dl = meheDLabels();
    $Dm = ['over_limits' => ['0', '0'], 'grants_contr' => [$GR['oth']['ll'], $GR['oth']['usd']], 'grants_admin_kids' => ['0', '0'],
           'grants_admin' => [$ADM['grants_ll'], '0'], 'severance' => [$SEV['ll'], $SEV['usd']], 'admin_tasks' => [$ADM['tasks_ll'], '0']];
    foreach (meheSummaryDOrder() as $k) {
        if (isset($Dm[$k])) $X->row($si, [$c($dl[$k], 6), $fx($Dm[$k][0], 5), $fx($Dm[$k][1], 5)]);
        else $X->row($si, [$c(meheExpenseItems()[$k][0], 6), $fx($EXP[$k][0], 5), $fx($EXP[$k][1], 5)]);
    }
    $d2 = $X->rowCount($si); $X->row($si, []);
    $X->pageBreak($si, $X->rowCount($si));
    $X->row($si, [$c('ملخص الميزانية', 9)]);
    $head($si, ['المعيار', 'المجموع بالليرة', 'المجموع بالدولار', 'المجموع الكلي', 'ملاحظات']);
    $rab = $X->row($si, [$c("مجموع البندين 'أ' و 'ب'", 6), $fx("SUM(B$a1:B$a2)+SUM(B$b1:B$b2)", 5), $fx("SUM(C$a1:C$a2)+SUM(C$b1:C$b2)", 5), $fx('B' . ($X->rowCount($si) + 1) . '+C' . ($X->rowCount($si) + 1), 5), $c('', 3)]);
    $rabc = $X->row($si, [$c("مجموع البنود 'أ' و 'ب' و 'ج'", 6), $fx("B$rab+SUM(B$c1:B$c2)", 5), $fx("C$rab+SUM(C$c1:C$c2)", 5), $fx('B' . ($X->rowCount($si) + 1) . '+C' . ($X->rowCount($si) + 1), 5), $c('', 3)]);
    $rp = $X->row($si, [$c("ما يمثله مجموع البندين 'أ' و 'ب' من مجموع البنود 'أ' و 'ب' و 'ج'", 6), $c('-', 13), $c('-', 13), $fx("IF(D$rabc>0,ROUND(D$rab/D$rabc*100,0)/100,0)", 11), $c('', 3)]);
    $rq = $X->rowCount($si) + 1;
    $X->row($si, [$c("ما يمثله مجموع البند 'ج' من مجموع البنود 'أ' و 'ب' و 'ج'", 6), $c('-', 13), $c('-', 13), $fx("1-D$rp", 11), $fx("IF(D$rq<=0.35,\"امتثال كامل\",\"تجاوز 35%\")", 14)]);
    $rex = $X->row($si, [$c("إجمالي النفقات (مجموع البنود 'أ' و 'ب' و 'ج' و 'د')", 6), $fx("B$rabc+SUM(B$d1:B$d2)", 5), $fx("C$rabc+SUM(C$d1:C$d2)", 5), $fx('B' . ($X->rowCount($si) + 1) . '+C' . ($X->rowCount($si) + 1), 5), $c('', 3)]);
    $rrv = $X->row($si, [$c('إجمالي الإيرادات', 6), $fx($REV['ll'], 5), $fx($REV['usd'], 5), $fx('B' . ($X->rowCount($si) + 1) . '+C' . ($X->rowCount($si) + 1), 5), $c('', 3)]);
    $rdf = $X->rowCount($si) + 1;
    $X->row($si, [$c('الفرق بين النفقات والايرادات', 6), $fx("B$rex-B$rrv", 5), $fx("C$rex-C$rrv", 5), $fx("D$rex-D$rrv", 5), $fx("IF(ABS(D$rdf)<1,\"امتثال كامل\",\"غير متوازنة\")", 14)]);
    $X->row($si, [$c('متوسط القسط المدرسي الواجب', 6), $fx("IF(" . $REV['students'] . ">0,ROUND(B$rex/" . $REV['students'] . ",-3),0)", 5), $fx("IF(" . $REV['students'] . ">0,ROUND(C$rex/" . $REV['students'] . ",-3),0)", 5), $fx('B' . ($X->rowCount($si) + 1) . '+C' . ($X->rowCount($si) + 1), 5), $c('', 3)]);
    $X->row($si, []);
    $X->row($si, [$c('توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)', 12), $c('', 0), $c('', 0), $c('توقيع مدير المدرسة', 12)]);
    return $X->bytes();
}
