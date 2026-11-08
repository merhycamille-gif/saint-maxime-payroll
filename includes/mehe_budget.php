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
        if (!msaHasCol($db, 'mehe_budget', 'scope')) {
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
/* 🏛️🖥️ (2026-11-08 «فيك تعملها بنفس الترتيب والتنظيم والصفحات والشكل») قوائم منصّة الوزارة psb.mehe.gov.lb حرفياً — مأخوذة من
 *  صفحة «موازنة 2025» المُرسَلة (قوائم الاختيار نفسها بالترتيب نفسه) كي يُنقَل كل سطر إلى المنصّة بلا ترجمة */
function meheSectionTitles(): array {
    return ['تحديث معلومات المدرسة', 'استطلاع', 'أعضاء هيئة التدريس في الملاك', 'أعضاء هيئة التدريس المتعاقدين', 'الموظفون الإداريون', 'الهيكل الإداري والتعليمي',
            'قائمة الطلاب المعفيين', 'تعويضات الصرف للداخلين في الملاك', 'تكاليف التشغيل', 'الإيرادات', 'ملخص الموازنة', 'الوثائق المطلوبة'];
}
function meheProgramOptions(): array { return ['منهاج لبناني', 'منهاج اجنبي', 'البكالوريا الفرنسية', 'بكالوريا الدولية', 'AP', 'برامج وصفوف أخرى']; }
function meheClassesByLevel(): array {
    return ['روضة' => ['الروضة الاولى', 'الروضة الثانية', 'الروضة الثالثة'],
            'اساسي' => ['الاول اساسي', 'الثاني اساسي', 'الثالث اساسي', 'الرابع اساسي', 'الخامس اساسي', 'السادس اساسي'],
            'متوسط' => ['السابع متوسط', 'الثامن متوسط', 'التاسع متوسط'],
            'ثانوي' => ['العاشر ثانوي', 'الحادي عشر ثانوي', 'الثاني عشر ثانوي']];
}
function meheOwnerOptions(): array { return ['بلدية', 'خاص', 'الدولة']; }
function meheSharedWithOptions(): array { return ['مساكن', 'محلات تجارية', 'مدرسة اخرى/مركز تربوي', 'مرکز دینی']; }
function meheInternetOptions(): array { return ['محصورة بشبكة تربوية', 'مفتوح ومراقب', 'مفتوح وغير مراقب', 'خاصة بالادارة والمعلميين', 'مفتوح على الشبكات العالمية بأكملها', 'مفتوح للطلاب']; }
function meheOtherDetailOptions(): array { return ['معدات لذوي الاحتياجات الخاصة', 'الطاقة الخضراء', 'المدارس الدامجة']; }
function meheRoleOptions(): array {
    return ['أستاذ \\ معلم', 'محاسب', 'مسؤول قسم الرياضة', 'ناظر', 'مسؤول قسم المعلوماتية', 'مدير المدرسة المتوسطة', 'مدير الحضانة', 'مدير مساعد', 'مسؤول شؤون الاملاك', 'اخصائي موارد بشرية',
            'مسؤول قسم النشاطات: حدد', 'تربية مختصة: حدد', 'ممرضة', 'مرشد/ة صحي/ة', 'منسق مادة', 'اخصائي شؤون الطلاب', 'موظف مساعد في المكتبة', 'سكرتير: حدد', 'عامل تنظيفات', 'مدير مدرسة',
            'مسؤول عن المكتبة', 'أمينة مكتبة', 'موظف معلوماتية', 'مساعد/ة لمدرسات صف الروضة التحضيرية', 'موظف اعمال ادارية مدرسية: حدد', 'مساعد مدير', 'مسؤول شؤون مالية', 'مسؤول قسم الفنون', 'سكرتير',
            'موظف خدمات الغذائية', 'عاملة اجتماعية', 'مدير القسم المهني', 'موظف اعمال ادارية مدرسية', 'مسؤول عن الخدمات الغذائية', 'أخصائي إرشاد', 'مدير القسم الفني', 'مدبر', 'عامل صيانة', 'اخصائي علم نفس',
            'سائق', 'حارس', 'مسؤول قسم النشاطات', 'تربية مختصة', 'مدير المدرسة الإبتدائية', 'مدير الدروس', 'مدير المدرسة ثانوي', 'موجه تربوي', 'غير محدد', 'اداري'];
}
function meheQualOptions(): array {
    return ['بكالوريا قسم ثاني', 'البكالوريا الفنية BT + BT2', 'اجازة تعليمية', 'اجازة جامعية', 'غيره', 'هاي سكول', 'هندسة', 'توجيهيه', 'دبلوم فني', 'دبلوم ديني', 'لا يحمل شهادة', 'بكالوريا الفنية قسم اول BT1',
            'الاجازة التعليمية الفنية (LET,LT)', 'دراسات معمقة', 'دكتوراه', 'دبلوم جامعي', 'ثانوية عامة', 'التكميلية المهنية (BP)', 'غيرها من الشهادات التعليمية', 'بكالوريوس', 'اجازة', 'التعليمية المتوسطة',
            'الامتياز الفني TS', 'دراسات عليا- MA - MS', 'معادلة بكالوريا قسم ثاني', 'دبلوم تربوي', 'جدارة - متريز', 'البكالوريا اوالابتدائية التعليمية', 'الابتدائية العالية', 'فريشمن', 'بكالوريا فرنسية',
            'افادة من مصلحة التعليم الخاص', 'كفاءة كلية التربية', 'غيرها من الشهادات الاكاديمية', 'c.a.p', 'بكالوريا قسم اول', 'غير محدد'];
}
function meheLevelOptions(): array { return ['غير محدد', 'روضة', 'اساسي', 'متوسط', 'ثانوي', 'كل المراحل']; }
function meheWorkModeOptions(): array { return ['دوام كامل', 'دوام جزئي']; }
function meheCnssTypeOptions(): array { return ['مضمون', 'غير مضمون']; }
function meheAdminTypeOptions(): array { return ['عادي', 'فوق السن', 'اجنبي']; }
/** الوثائق المطلوبة (القسم 12 بالمنصّة) — key => تسمية */
function meheDocTypes(): array {
    return ['minutes' => 'محاضر اللجنة المالية', 'fund_statement' => 'بيان صندوق التعويضات', 'eos_receipts' => 'إيصالات تسوية نهاية الخدمة من الضمان الاجتماعي', 'audit' => 'تقرير التدقيق'];
}
/** قيمة متعدّدة (خانات تفتيش) مخزّنة نصّاً مفصولاً بـ«|» ⇒ مصفوفة */
function meheMulti($v): array { return array_values(array_filter(array_map('trim', explode('|', (string)$v)), fn($x) => $x !== '')); }
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
        // 🏛️🖥️ (2026-11-08) خانات المنصّة: خانات تفتيش مخزّنة «أ|ب|ج» + أشهر الراتب/النقل لكل فئة + الوثائق المرفوعة
        'programs_sel' => 'منهاج لبناني', 'levels_sel' => implode('|', meheLevels()), 'classes_sel' => implode('|', array_merge(...array_values(meheClassesByLevel()))),
        'owner_sel' => 'خاص', 'shared_sel' => '', 'internet_sel' => '', 'other_sel' => '',
        'months' => ['tit' => ['salary' => 12, 'transport' => 12], 'con' => ['salary' => 12, 'transport' => 12], 'adm' => ['salary' => 12, 'transport' => 12]],
        'docs' => [],              // key => ['file' => اسم الملف المخزّن, 'orig' => الاسم الأصلي, 'at' => وقت]
        'base_mode' => 'last',     // last = راتب الموظف الحالي (آخر شهر مدفوع) × عدد الأشهر [موازنة تقديرية، طلبه 2026-11-08] · oct = شهر تشرين الأول
        'excluded' => [],          // موظفون يُستثنون من الجداول (بقراره)
        // ✏️ تعديلات يدوية من أوراق الموازنة نفسها («قدام كل سطر تعديل وحفظ» 2026-09-07):
        //   emp[<employee_id>][field] قيم موظف تحلّ محلّ المحسوب من الرواتب · months[tit|con|adm][col] عدد أشهر العمود
        //   sum[A|B|D][idx] = [ll, usd] سطر ملخّص مفروض يدوياً
        'overrides' => ['emp' => [], 'months' => [], 'sum' => []],
    ];
}
function meheLoad(PDO $db, array $ids, string $sy): array {
    ensureMeheBudget20260906();
    $schoolId = (int)($ids[0] ?? 0);
    $def = meheDefaults($db, $schoolId, $sy);
    $apply = function (array $saved) use (&$def) {
        foreach ($saved as $k => $v) {
            if (is_array($v) && isset($def[$k]) && is_array($def[$k]) && !isset($v[0]) && !in_array($k, ['revenues', 'grants', 'severance', 'manual_admins', 'excluded', 'docs'], true)) {
                $def[$k] = array_replace($def[$k], $v);   // قواميس (غرف/معدات/لغات/نفقات)
            } else {
                $def[$k] = $v;
            }
        }
    };
    try {
        $st = $db->prepare("SELECT data FROM mehe_budget WHERE scope = ? AND school_year = ?");
        $st->execute([meheScopeKey($ids), $sy]);
        $j = $st->fetchColumn();
        if ($j) {
            $apply(json_decode((string)$j, true) ?: []);
        } else {
            // 🏛️🖥️ (2026-11-08) سنة جديدة بلا حفظ ⇒ ترحيل ما يثبت من آخر موازنة محفوظة لهذا النطاق (معلومات المدرسة، الاستطلاع، الهيكل،
            //    الأشهر، معرّفات الموظفين بالمنصّة، الإداريون خارج الرواتب) — لا الأرقام (نفقات/أقساط/منح/صرف/قيم يدوية) ولا الوثائق
            $pv = $db->prepare("SELECT data FROM mehe_budget WHERE scope = ? AND school_year < ? ORDER BY school_year DESC LIMIT 1");
            $pv->execute([meheScopeKey($ids), $sy]);
            $pj = $pv->fetchColumn();
            if ($pj) {
                $prev = json_decode((string)$pj, true) ?: [];
                $carry = [];
                foreach (['director', 'parents_head', 'parents_phone', 'fin_committee', 'programs_sel', 'levels_sel', 'classes_sel', 'playground_open', 'playground_closed', 'owner_sel', 'building_owner',
                          'buildings_school', 'buildings_res', 'shared_sel', 'internet_sel', 'other_sel', 'languages', 'rooms', 'equipment', 'struct_admin_law', 'struct_workers_law', 'struct_others',
                          'classes_per_level', 'staff_mgmt', 'staff_supervision', 'months', 'manual_admins', 'base_mode', 'center_no', 'serial'] as $k) if (array_key_exists($k, $prev)) $carry[$k] = $prev[$k];
                $ids2 = [];
                foreach ((array)($prev['overrides']['emp'] ?? []) as $eid => $ov) if (isset($ov['mehe_id']) && trim((string)$ov['mehe_id']) !== '') $ids2[$eid] = ['mehe_id' => (string)$ov['mehe_id']];
                if ($ids2) $carry['overrides'] = ['emp' => $ids2, 'months' => [], 'sum' => []];
                $apply($carry);
                $def['carried_from'] = 'prev';
            }
        }
    } catch (Throwable $e) {}
    // الخانات المتعدّدة الجديدة فارغة وعندها نصّ قديم (programs/internet/…) ⇒ حوّله مرّة (كان نصّاً حرّاً مفصولاً بفواصل)
    foreach ([['programs_sel', 'programs'], ['internet_sel', 'internet'], ['shared_sel', 'shared_with'], ['other_sel', 'other_details'], ['owner_sel', 'owner']] as [$nk, $ok]) {
        if (trim((string)($def[$nk] ?? '')) === '' && trim((string)($def[$ok] ?? '')) !== '') $def[$nk] = implode('|', array_filter(array_map('trim', preg_split('/[,،|]/u', (string)$def[$ok]))));
    }
    if (!is_array($def['months'] ?? null)) $def['months'] = [];
    foreach (['tit', 'con', 'adm'] as $tk) $def['months'][$tk] = array_replace(['salary' => 12, 'transport' => 12], (array)($def['months'][$tk] ?? []));
    if (!is_array($def['docs'] ?? null)) $def['docs'] = [];
    // 🏛️ 2026-11-08 «هيدي موازنة: منشوف الأستاذ قديش راتبه ومنحط عدد الأشهر ومنضرب»: الوضع القديم «معدل الأشهر المدفوعة» أُلغي ⇒ راتب حالي × أشهر
    if (($def['base_mode'] ?? '') !== 'oct') $def['base_mode'] = 'last';
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
 * 🏛️ موازنة تقديرية (طلبه 2026-11-08 «هيدي موازنة ونحنا منشتغلها هيك: منشوف الأستاذ قديش راتبه ومنحط عدد الأشهر ومنضرب»):
 * لكل موظف بالمدرسة له رواتب بالسنة: القيمة الشهرية لكل عمود = راتبه الحالي (قيمة آخر شهر مدفوع فيه هذا العمود > 0؛
 * base_mode=last) أو قيمة شهر تشرين الأول (base_mode=oct)، وعدد الأشهر = 12 افتراضاً لكل عمود له قيمة (يعدّله من صفّ
 * «عدد الاشهر» بالورقة فيسري على العمود كلّه، مثلاً النقل 10)، والمجموع = الشهري × عدد الأشهر.
 * (الوضع القديم «معدل الأشهر المدفوعة × الأشهر المدفوعة» أُلغي: كان يُظهر لمَن قبض 3 أشهر ×3 — الموازنة للسنة كلّها.)
 */
function mehePayroll(PDO $db, array $ids, string $sy, array $data): array {
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) $ids = [-1];
    $in = implode(',', $ids);
    [$y1, $y2] = schoolYearToYears($sy);
    $mode = ($data['base_mode'] ?? 'last') === 'oct' ? 'oct' : 'last';
    $excluded = array_map('intval', (array)($data['excluded'] ?? []));
    $cols = [
        'base' => 'base_plus_echelon_lbp', 'extra_ll' => 'extra_lbp + prime_fixe_lbp', 'bonus' => 'aide_complementaire_lbp',
        'transport' => 'transport_lbp', 'family' => 'family_allowance_lbp', 'cnss' => 'school_cnss_8_lbp', 'fund' => 'school_eoc_6_lbp',
        // 🏛️🖥️ (2026-11-08 مطابقة المنصّة): «مساهمة الضمان» للموظف الخاضع لقانون العمل = فروعه الثلاثة (8٪ مرض وأمومة + 6٪ عائلي + 8.5٪ نهاية خدمة)
        //   كما بموازنة 2025 المُرسَلة (انطوان الدايخ 6,056,400)؛ الأستاذ 8٪ فقط
        'cnss3' => 'school_cnss_8_lbp + school_family_comp_6_lbp + school_end_of_service_8_5_lbp',
    ];
    $sel = [];
    foreach ($cols as $k => $expr) {
        $sel[] = "SUM($expr) `{$k}_sum`";
        $sel[] = "SUM(($expr) > 0) `{$k}_m`";
        $sel[] = "SUM(CASE WHEN ms.month = 10 AND ms.year = $y1 THEN ($expr) ELSE 0 END) `{$k}_oct`";
        // الراتب الحالي = قيمة العمود بآخر شهر فيه قيمة > 0 (الأحدث بالسنة والشهر)
        $sel[] = "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN ($expr) > 0 THEN ($expr) END ORDER BY ms.year DESC, ms.month DESC SEPARATOR '|'), '|', 1) `{$k}_last`";
    }
    $st = $db->prepare("SELECT e.*, s.name_ar school_name, COUNT(ms.id) months_all, " . implode(', ', $sel) . "
                        FROM employees e JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.school_year = ?
                        LEFT JOIN schools s ON s.id = e.school_id
                        WHERE e.school_id IN ($in) AND e.is_deleted = 0
                          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)
                        GROUP BY e.id
                        ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), e.school_id, REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(NULLIF(e.first_name_ar,''),e.first_name_fr),'أ','ا'),'إ','ا'),'آ','ا'),'ى','ي'),'ة','ه'), REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(NULLIF(e.last_name_ar,''),e.last_name_fr),'أ','ا'),'إ','ا'),'آ','ا'),'ى','ي'),'ة','ه'), e.id");
    $st->execute([$sy]);
    $tit = []; $con = []; $adm = [];
    foreach ($st->fetchAll() as $r) {
        if (in_array((int)$r['id'], $excluded, true)) continue;
        $row = [
            'id' => (int)$r['id'], 'key' => 'e' . (int)$r['id'], 'school' => (string)($r['school_name'] ?? ''),
            'name' => trim((string)$r['first_name_ar'] . ' ' . (string)$r['father_name_ar'] . ' ' . (string)$r['last_name_ar']) ?: empFullNameFr($r),
            'role' => meheRoleText($r), 'qual' => meheQualText($r['diploma'] ?? ''), 'level' => meheLevelText($r),
            'cadre_date' => ($tD = shownTitularizationDate($r)) ? formatDate($tD, 'j/n/Y') : '',
            'start_date' => ($hD = shownHireDate($r)) ? formatDate($hD, 'j/n/Y') : '',
            'h_cadre' => (float)($r['hours_per_week'] ?? 0) == floor((float)($r['hours_per_week'] ?? 0)) ? (int)$r['hours_per_week'] : (float)$r['hours_per_week'],
            'h_extra' => 0, 'mode' => 'دوام كامل', 'cnss_type' => ((int)($r['cnss_subject'] ?? 1) === 1) ? 'مضمون' : 'غير مضمون',
            'admin_type' => 'عادي', 'admin_mode' => meheRoleText($r),
            'extra_usd' => 0, 'retro' => 0, 'missions_ll' => 0, 'missions35' => 0, 'tasks_ll' => 0, 'grants_ll' => 0,
            'mehe_id' => '',   // معرّف الموظف بمنصّة الوزارة (يُدخل مرّة من الورقة ويُرحَّل سنة بعد سنة)
            'months' => [],
        ];
        foreach ($cols as $k => $_) {
            $sum = (float)$r[$k . '_sum']; $m = (int)$r[$k . '_m'];
            $monthly = 0.0;
            if ($m > 0) {
                $monthly = $mode === 'oct' ? (float)$r[$k . '_oct'] : (float)$r[$k . '_last'];
                if ($monthly <= 0) $monthly = (float)$r[$k . '_last'] > 0 ? (float)$r[$k . '_last'] : $sum / $m; // لا شهر تشرين ⇒ الراتب الحالي ثم المعدل
            }
            // 🏛️🖥️ أشهر الراتب/النقل لكل فئة كما بالمنصّة (أشهر الراتب لكل الأعمدة، أشهر النقل لعمود النقل) — افتراضها 12
            $tk0 = $r['employee_type'] === 'enseignant_titulaire' ? 'tit' : ($r['employee_type'] === 'enseignant_contractuel' ? 'con' : 'adm');
            $mm = $monthly > 0 ? max(0, (int)($data['months'][$tk0][$k === 'transport' ? 'transport' : 'salary'] ?? 12)) : 0;
            $row[$k] = $monthly;
            $row['months'][$k] = $mm;
            $row[$k . '_total'] = $monthly * $mm;
        }
        $row['months_all'] = (int)$r['months_all'];
        if ((string)$r['employee_type'] === 'employe') { $row['cnss'] = $row['cnss3']; $row['cnss_total'] = $row['cnss3_total']; $row['months']['cnss'] = $row['months']['cnss3']; }
        unset($row['cnss3'], $row['cnss3_total'], $row['months']['cnss3']);
        meheApplyEmpOverride($row, (array)($data['overrides']['emp'][(string)(int)$r['id']] ?? []));
        $t = (string)$r['employee_type'];
        if ($t === 'enseignant_titulaire') $tit[] = $row;
        elseif ($t === 'enseignant_contractuel') $con[] = $row;
        else $adm[] = $row;
    }
    // إداريون خارج الرواتب (أُدخلوا يدوياً بالصفحة)
    foreach (array_values((array)($data['manual_admins'] ?? [])) as $mi => $ma) {
        if (trim((string)($ma['name'] ?? '')) === '') continue;
        $mm = max(1, (int)($ma['months'] ?? ($data['months']['adm']['salary'] ?? 12)));
        $mmT = max(0, (int)($data['months']['adm']['transport'] ?? $mm));
        $row = ['id' => 0, 'key' => 'm' . $mi, 'school' => (string)($ma['school'] ?? ''), 'name' => (string)$ma['name'], 'admin_mode' => (string)($ma['mode'] ?? ''), 'start_date' => (string)($ma['start_date'] ?? ''),
                'admin_type' => (string)($ma['type'] ?? 'عادي'), 'cnss_type' => (string)($ma['cnss_type'] ?? 'غير مضمون'), 'mehe_id' => (string)($ma['mehe_id'] ?? ''), 'months' => [], 'manual' => true];
        foreach (['base', 'extra_ll', 'extra_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss'] as $k) {
            $v = (float)($ma[$k] ?? 0); $m1 = $k === 'transport' ? $mmT : $mm;
            $row[$k] = $v; $row['months'][$k] = $v > 0 ? $m1 : 0; $row[$k . '_total'] = $v * ($v > 0 ? $m1 : 0);
        }
        $adm[] = $row;
    }
    // إجمالي الراتب الشهري كما بالمنصّة = أساس + أجور إضافية (ل.ل) · والدولار على حدة
    foreach (['tit', 'con', 'adm'] as $tk) foreach ($$tk as &$row) { $row['total_ll'] = (float)($row['base'] ?? 0) + (float)($row['extra_ll'] ?? 0); $row['total_usd'] = (float)($row['extra_usd'] ?? 0); }
    unset($row);
    // ✏️ عدد أشهر العمود مفروض يدوياً من الورقة (مثلاً النقل 10 أشهر كالوزارة): يسري على كل موظف له قيمة بالعمود
    $ovM = (array)($data['overrides']['months'] ?? []);
    foreach (['tit', 'con', 'adm'] as $tk) {
        foreach ((array)($ovM[$tk] ?? []) as $k => $m) {
            $m = (int)$m; if ($m <= 0) continue;
            foreach ($$tk as &$row) { if ((float)($row[$k] ?? 0) > 0) { $row['months'][$k] = $m; $row[$k . '_total'] = (float)$row[$k] * $m; } }
            unset($row);
        }
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
    // عمود فارغ ⇒ أشهر الفئة (أشهر الراتب، وللنقل أشهر النقل) كما بالمنصّة — لا 12 ثابتة
    foreach (['tm' => 'tit', 'cm' => 'con', 'am' => 'adm'] as $v => $tk) foreach ($$v as $k => $m) if ($m === 0) $$v[$k] = max(0, (int)($data['months'][$tk][$k === 'transport' ? 'transport' : 'salary'] ?? 12));
    return ['tit' => $tit, 'con' => $con, 'adm' => $adm, 'tit_months' => $tm, 'tit_tot' => $tt, 'con_months' => $cm, 'con_tot' => $ct,
            'adm_months' => $am, 'adm_tot' => $at, 'mode' => $mode, 'multi' => count($ids) > 1];
}

/* ===================== ✏️ التعديل اليدوي من أوراق الموازنة («قدام كل سطر تعديل وحفظ» 2026-09-07) ===================== */
/** أعمدة الموظف القابلة للتعديل من الورقة: نصّية / رقمية (شهري) */
function meheEmpTextFields(): array { return ['name', 'role', 'qual', 'level', 'cadre_date', 'start_date', 'mode', 'cnss_type', 'admin_type', 'admin_mode', 'mehe_id']; }
function meheEmpNumFields(): array { return ['h_cadre', 'h_extra', 'base', 'extra_ll', 'extra_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'grants_ll', 'transport', 'family', 'cnss', 'fund']; }
/** يحلّ القيم اليدوية محلّ المحسوب لصفّ موظف (الشهري × أشهره = المجموع) */
function meheApplyEmpOverride(array &$row, array $ov): void {
    if (!$ov) return;
    $row['overridden'] = true;
    foreach (meheEmpTextFields() as $f) if (array_key_exists($f, $ov)) $row[$f] = (string)$ov[$f];
    foreach (['h_cadre', 'h_extra'] as $f) if (array_key_exists($f, $ov)) { $v = (float)$ov[$f]; $row[$f] = $v == floor($v) ? (int)$v : $v; }
    foreach (meheEmpNumFields() as $f) {
        if (in_array($f, ['h_cadre', 'h_extra'], true) || !array_key_exists($f, $ov)) continue;
        $v = (float)$ov[$f];
        $m = (int)($row['months'][$f] ?? 0);
        if ($v > 0 && $m <= 0) $m = (int)($row['months']['base'] ?? 0) ?: (int)($row['months_all'] ?? 0) ?: 12;
        if ($v <= 0) $m = 0;
        $row[$f] = $v; $row['months'][$f] = $m; $row[$f . '_total'] = $v * $m;
    }
}
/** مواصفات صفوف القوائم (الإيرادات/المنح/الصرف/الإداريون اليدويون): الحقول وأنواعها + الحقل الإلزامي */
function meheListSpecs(): array {
    return [
        'revenues'      => [['program' => 's', 'class' => 's', 'fee_ll' => 'n', 'fee_usd' => 'n', 'students' => 'n'], 'class'],
        'grants'        => [['student' => 's', 'teacher' => 's', 'cat' => 's', 'class' => 's', 'll' => 'n', 'usd' => 'n'], 'student'],
        'severance'     => [['name' => 's', 'eos_ll' => 'n', 'eos_usd' => 'n', 'tasks_ll' => 'n', 'tasks_usd' => 'n', 'receipt_no' => 's', 'receipt_date' => 's', 'notes' => 's'], 'name'],
        'manual_admins' => [['name' => 's', 'school' => 's', 'mehe_id' => 's', 'mode' => 's', 'start_date' => 's', 'type' => 's', 'cnss_type' => 's', 'base' => 'n', 'extra_ll' => 'n', 'extra_usd' => 'n', 'tasks_ll' => 'n', 'grants_ll' => 'n', 'transport' => 'n', 'cnss' => 'n', 'months' => 'n'], 'name'],
    ];
}
/**
 * يطبّق حفظ سطر واحد من الورقة على بيانات الموازنة (بلا DB — المُستدعي يحفظ).
 *   $P: mehe_row = field|exp|list|emp|months|sum · key · f[<حقل>] القيم المتغيّرة · reset=1 رجوع للتلقائي
 * يعيد ['ok' => bool, 'msg' => نص]
 */
function meheApplyRowEdit(array &$data, array $P): array {
    $kind = (string)($P['mehe_row'] ?? ''); $key = (string)($P['key'] ?? ''); $f = (array)($P['f'] ?? []); $reset = !empty($P['reset']);
    $num = fn($v) => (float)str_replace([',', ' ', '٬'], '', (string)$v);
    $txt = fn($v) => trim((string)$v);
    $ok = fn($m = 'انحفظ السطر') => ['ok' => true, 'msg' => $m];
    $bad = fn($m) => ['ok' => false, 'msg' => $m];
    $data['overrides'] = array_replace(['emp' => [], 'months' => [], 'sum' => []], (array)($data['overrides'] ?? []));
    $parts = explode('|', $key);
    switch ($kind) {
        case 'field': // خانة مفردة: serial · rooms|<غرفة> · equipment|<معدّة>|admin · languages|fr · classes_per_level|<مرحلة>
            if (!array_key_exists('v', $f)) return $bad('لا قيمة');
            $v = $f['v']; $k0 = $parts[0] ?? '';
            $strs = ['serial', 'center_no', 'subject', 'reference', 'director', 'parents_head', 'parents_phone', 'programs', 'levels', 'classes', 'fin_committee', 'owner', 'shared_with', 'internet', 'other_details', 'building_owner',
                     'programs_sel', 'levels_sel', 'classes_sel', 'owner_sel', 'shared_sel', 'internet_sel', 'other_sel'];
            $ints = ['playground_open', 'playground_closed', 'buildings_school', 'buildings_res', 'struct_admin_law', 'struct_workers_law', 'struct_others', 'staff_mgmt', 'staff_supervision'];
            if ($k0 === 'base_mode' && count($parts) === 1) { $data['base_mode'] = $txt($v) === 'oct' ? 'oct' : 'last'; return $ok(); }
            if ($k0 === 'fin_member' && isset($parts[1])) { // عضو اللجنة المالية رقم n (المنصّة: خانتان «الاسم»)
                $lines = array_map('trim', explode("\n", (string)($data['fin_committee'] ?? '')));
                $i = max(0, (int)$parts[1]); while (count($lines) <= $i) $lines[] = '';
                $lines[$i] = $txt($v); // الموضع يبقى موضعه (العضو 2 يبقى بالسطر الثاني ولو كان الأوّل فارغاً)
                while ($lines && end($lines) === '') array_pop($lines);
                $data['fin_committee'] = implode("\n", $lines); return $ok();
            }
            if (in_array($k0, $strs, true) && count($parts) === 1) { $data[$k0] = $txt($v); return $ok(); }
            if (in_array($k0, $ints, true) && count($parts) === 1) { $data[$k0] = (int)$num($v); return $ok(); }
            if ($k0 === 'rooms' && isset($parts[1]) && in_array($parts[1], meheRoomTypes(), true)) { $data['rooms'][$parts[1]] = (int)$num($v); return $ok(); }
            if ($k0 === 'classes_per_level' && isset($parts[1]) && in_array($parts[1], meheLevels(), true)) { $data['classes_per_level'][$parts[1]] = (int)$num($v); return $ok(); }
            if ($k0 === 'languages' && isset($parts[1]) && isset(meheLanguages()[$parts[1]])) { $data['languages'][$parts[1]] = in_array((int)$v, [1, 2, 3], true) ? (int)$v : 3; return $ok(); }
            if ($k0 === 'equipment' && isset($parts[1], $parts[2]) && in_array($parts[1], meheEquipmentTypes(), true) && in_array($parts[2], ['admin', 'edu'], true)) { $data['equipment'][$parts[1]][$parts[2]] = (int)$num($v); return $ok(); }
            return $bad('خانة غير معروفة: ' . $key);
        case 'exp': // بند نفقات: key = رمز البند، f[ll] و/أو f[usd]
            if (!isset(meheExpenseItems()[$key])) return $bad('بند غير معروف');
            $cur = (array)($data['expenses'][$key] ?? ['ll' => 0, 'usd' => 0]);
            foreach (['ll', 'usd'] as $c) if (array_key_exists($c, $f)) $cur[$c] = $num($f[$c]);
            $data['expenses'][$key] = $cur; return $ok();
        case 'list': // صفّ قائمة: key = revenues|3 أو grants|new — الحقل الإلزامي فارغ ⇒ يُحذف السطر
            [$lst, $idx] = [$parts[0] ?? '', $parts[1] ?? ''];
            if (!isset(meheListSpecs()[$lst])) return $bad('قائمة غير معروفة');
            [$spec, $req] = meheListSpecs()[$lst];
            $rows = array_values((array)($data[$lst] ?? []));
            if ($idx === 'new') { $row = []; foreach ($spec as $fk => $t) $row[$fk] = $t === 'n' ? ($fk === 'months' ? 12 : 0) : ''; if ($lst === 'grants') $row['cat'] = 'ملاك'; if ($lst === 'revenues') $row['program'] = 'منهاج لبناني'; if ($lst === 'manual_admins') { $row['type'] = 'عادي'; $row['cnss_type'] = 'غير مضمون'; } $i = count($rows); }
            else { $i = (int)$idx; if (!isset($rows[$i])) return $bad('السطر غير موجود — أعد تحميل الصفحة'); $row = $rows[$i]; }
            foreach ($spec as $fk => $t) if (array_key_exists($fk, $f)) $row[$fk] = $t === 'n' ? $num($f[$fk]) : $txt($f[$fk]);
            if ($txt($row[$req] ?? '') === '') { if ($idx !== 'new') array_splice($rows, $i, 1); $data[$lst] = $rows; return $ok('انحذف السطر'); }
            $rows[$i] = $row; $data[$lst] = $rows; return $ok();
        case 'emp': // موظف من الرواتب: key = e<id> — تُخزَّن القيم المتغيّرة فقط؛ reset يرجّع كل شي تلقائياً
            if (!preg_match('/^e(\d+)$/', $key, $m)) return $bad('موظف غير معروف');
            $id = $m[1];
            if ($reset) { unset($data['overrides']['emp'][$id]); return $ok('رجع السطر تلقائياً من الرواتب'); }
            $cur = (array)($data['overrides']['emp'][$id] ?? []);
            foreach (meheEmpTextFields() as $fk) if (array_key_exists($fk, $f)) $cur[$fk] = $txt($f[$fk]);
            foreach (meheEmpNumFields() as $fk) if (array_key_exists($fk, $f)) $cur[$fk] = $num($f[$fk]);
            if ($cur) $data['overrides']['emp'][$id] = $cur; else unset($data['overrides']['emp'][$id]);
            return $ok();
        case 'months2': // 🏛️🖥️ «أشهر الراتب» و«أشهر النقل» لفئة كما بالمنصّة: key = tit|con|adm، f[salary] و/أو f[transport]
            if (!in_array($key, ['tit', 'con', 'adm'], true)) return $bad('جدول غير معروف');
            $cur = array_replace(['salary' => 12, 'transport' => 12], (array)($data['months'][$key] ?? []));
            foreach (['salary', 'transport'] as $fk) if (array_key_exists($fk, $f)) { $m = (int)$num($f[$fk]); if ($m < 0 || $m > 24) return $bad('عدد الأشهر بين 0 و24'); $cur[$fk] = $m; }
            $data['months'][$key] = $cur;
            unset($data['overrides']['months'][$key]); // الأشهر العامّة للفئة تلغي أشهر الأعمدة اليدوية القديمة
            return $ok('انحفظت الأشهر');
        case 'exclude': // 🏛️🖥️ استثناء موظف من الموازنة / إرجاعه: key = e<id>، f[v] = 1 استثناء · 0 إرجاع
            if (!preg_match('/^e(\d+)$/', $key, $m)) return $bad('موظف غير معروف');
            $id = (int)$m[1]; $ex = array_values(array_filter(array_map('intval', (array)($data['excluded'] ?? [])), fn($x) => $x !== $id));
            if (!empty($f['v']) && (string)$f['v'] !== '0') $ex[] = $id;
            $data['excluded'] = $ex; return $ok(!empty($f['v']) && (string)$f['v'] !== '0' ? 'استُثني من الموازنة' : 'رجع إلى الموازنة');
        case 'months': // صفّ «عدد الاشهر» لجدول: key = tit|con|adm، f[<عمود>] = أشهر
            if (!in_array($key, ['tit', 'con', 'adm'], true)) return $bad('جدول غير معروف');
            if ($reset) { unset($data['overrides']['months'][$key]); return $ok('رجعت الأشهر تلقائياً'); }
            $cur = (array)($data['overrides']['months'][$key] ?? []);
            foreach (meheEmpNumFields() as $fk) if (array_key_exists($fk, $f)) { $m = (int)$num($f[$fk]); if ($m > 0) $cur[$fk] = $m; else unset($cur[$fk]); }
            if ($cur) $data['overrides']['months'][$key] = $cur; else unset($data['overrides']['months'][$key]);
            return $ok();
        case 'sum': // سطر ملخّص محسوب (أ/ب/د): key = A|3 — يُفرض يدوياً [ll, usd]؛ reset يرجّعه محسوباً
            [$cat, $i] = [$parts[0] ?? '', (int)($parts[1] ?? -1)];
            if (!in_array($cat, ['A', 'B', 'D'], true) || $i < 0) return $bad('سطر غير معروف');
            if ($reset) { unset($data['overrides']['sum'][$cat][(string)$i]); return $ok('رجع السطر محسوباً تلقائياً'); }
            $cur = (array)($data['overrides']['sum'][$cat][(string)$i] ?? [null, null]);
            if (array_key_exists('ll', $f)) $cur[0] = $num($f['ll']);
            if (array_key_exists('usd', $f)) $cur[1] = $num($f['usd']);
            $data['overrides']['sum'][$cat][(string)$i] = $cur; return $ok();
    }
    return $bad('نوع غير معروف');
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
    foreach (array_values((array)$d['revenues']) as $ri => $rv) {
        if (trim((string)($rv['class'] ?? '')) === '') continue;
        $n = (int)($rv['students'] ?? 0); $fl = (float)($rv['fee_ll'] ?? 0); $fu = (float)($rv['fee_usd'] ?? 0);
        $revRows[] = ['idx' => $ri, 'program' => (string)($rv['program'] ?? ''), 'class' => (string)$rv['class'], 'fee_ll' => $fl, 'fee_usd' => $fu, 'students' => $n, 'tot_ll' => $fl * $n, 'tot_usd' => $fu * $n];
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
        [meheExpenseItems()['cnss_extra'][0], $g('cnss_extra', 'll'), $g('cnss_extra', 'usd'), ['exp' => 'cnss_extra']],
    ];
    $C = [];
    foreach (meheSummaryCOrder() as $k) $C[] = [meheExpenseItems()[$k][0], $g($k, 'll'), $g($k, 'usd'), ['exp' => $k]];
    $dl = meheDLabels();
    $Dm = [
        'over_limits' => [0, 0], 'grants_contr' => [$grOthLL, $grOthUSD], 'grants_admin_kids' => [0, 0],
        'grants_admin' => [$at['grants_ll'], 0], 'severance' => [$sevLL, $sevUSD], 'admin_tasks' => [$at['tasks_ll'], 0],
    ];
    $D = [];
    foreach (meheSummaryDOrder() as $k) {
        if (isset($Dm[$k])) $D[] = [$dl[$k], $Dm[$k][0], $Dm[$k][1]];
        else $D[] = [meheExpenseItems()[$k][0], $g($k, 'll'), $g($k, 'usd'), ['exp' => $k]];
    }
    // ✏️ أسطر ملخّص مفروضة يدوياً من الورقة (المحسوبة فقط؛ بنود النفقات تُعدَّل من بندها مباشرة)
    $ovS = (array)($d['overrides']['sum'] ?? []);
    foreach (['A', 'B', 'D'] as $cat) {
        foreach ($$cat as $i => &$r) {
            if (isset($r[3]['exp'])) continue;
            $r[3] = ['ov' => false];
            if (isset($ovS[$cat][(string)$i]) && is_array($ovS[$cat][(string)$i])) {
                $o = $ovS[$cat][(string)$i];
                if (isset($o[0]) && $o[0] !== '') $r[1] = (float)$o[0];
                if (isset($o[1]) && $o[1] !== '') $r[2] = (float)$o[1];
                $r[3] = ['ov' => true];
            }
        }
        unset($r);
    }
    $sum = fn(array $rows, int $i) => array_sum(array_map(fn($r) => (float)$r[$i], $rows));
    $abL = $sum($A, 1) + $sum($B, 1); $abU = $sum($A, 2) + $sum($B, 2);
    $abcL = $abL + $sum($C, 1); $abcU = $abU + $sum($C, 2);
    $allL = $abcL + $sum($D, 1); $allU = $abcU + $sum($D, 2);
    $pctAB = ($abcL + $abcU) > 0 ? round(($abL + $abU) / ($abcL + $abcU) * 100, 2) : 0;
    // ⚖️ (2026-11-08 «كل رقم بالبند ج فوق 35 غلط… وكل رقم تحت 65 غلط»): سقف ج = 35٪ من (أ+ب+ج) ⇔ ج ≤ (أ+ب) × 35/65؛ ما فوقه = تجاوز
    $cSumL = $sum($C, 1); $cSumU = $sum($C, 2);
    $cMaxL = $abL * 35 / 65; $cMaxU = $abU * 35 / 65;
    $cOverL = max(0, $cSumL - $cMaxL); $cOverU = max(0, $cSumU - $cMaxU);
    // 🏛️🖥️ «فرق الرسوم الدراسية عن العام الماضي» (آخر سطر بملخّص المنصّة): متوسّط الرسوم هذه السنة − متوسّط آخر موازنة محفوظة سابقة
    $prevAvg = $d['_prev_avg'] ?? null;
    $avgFeeLL = $payers > 0 ? $revAfterLL / $payers : 0; $avgFeeUSD = $payers > 0 ? $revAfterUSD / $payers : 0;
    $feeDiffL = is_array($prevAvg) ? $avgFeeLL - (float)($prevAvg['ll'] ?? 0) : null; $feeDiffU = is_array($prevAvg) ? $avgFeeUSD - (float)($prevAvg['usd'] ?? 0) : null;
    $expTotalLL = 0; $expTotalUSD = 0;
    foreach (meheExpenseItems() as $k => $_) { $expTotalLL += $g($k, 'll'); $expTotalUSD += $g($k, 'usd'); }
    // هيكل الموظفين
    $staffTeaching = count($p['tit']) + count($p['con']);
    $staffTotal = (int)$d['staff_mgmt'] + (int)$d['staff_supervision'] + $staffTeaching;
    return [
        'A' => $A, 'B' => $B, 'C' => $C, 'D' => $D,
        'abL' => $abL, 'abU' => $abU, 'abcL' => $abcL, 'abcU' => $abcU, 'allL' => $allL, 'allU' => $allU,
        'pctAB' => $pctAB, 'pctC' => round(100 - $pctAB, 2), 'cSumL' => $cSumL, 'cSumU' => $cSumU, 'cMaxL' => $cMaxL, 'cMaxU' => $cMaxU, 'cOverL' => $cOverL, 'cOverU' => $cOverU,
        'okRatio' => ($cSumL + $cSumU) <= ($cMaxL + $cMaxU) + 0.5, 'okBalance' => abs(($allL - $revAfterLL) + ($allU - $revAfterUSD)) < 1,
        'revRows' => $revRows, 'students' => $students, 'revLL' => $revLL, 'revUSD' => $revUSD,
        'revAfterLL' => $revAfterLL, 'revAfterUSD' => $revAfterUSD, 'payers' => $payers,
        'avgFeeLL' => $avgFeeLL, 'avgFeeUSD' => $avgFeeUSD, 'feeDiffL' => $feeDiffL, 'feeDiffU' => $feeDiffU,
        'diffL' => $allL - $revAfterLL, 'diffU' => $allU - $revAfterUSD,
        'avgDueL' => $students > 0 ? round($allL / $students / 1000) * 1000 : 0, 'avgDueU' => $students > 0 ? round($allU / $students / 1000) * 1000 : 0,
        'grTitN' => $grTitN, 'grTitLL' => $grTitLL, 'grTitUSD' => $grTitUSD, 'grOthN' => $grOthN, 'grOthLL' => $grOthLL, 'grOthUSD' => $grOthUSD,
        'sevLL' => $sevLL, 'sevUSD' => $sevUSD, 'expTotalLL' => $expTotalLL, 'expTotalUSD' => $expTotalUSD,
        'staffTeaching' => $staffTeaching, 'staffInCadre' => count($p['tit']), 'staffOutCadre' => count($p['con']), 'staffTotal' => $staffTotal,
        'classesTotal' => array_sum(array_map('intval', (array)$d['classes_per_level'])),
    ];
}

/* ===================== ⚖️ «وازِن» (2026-11-08 «بدي كل الخيارات متاحة») ===================== */
/**
 * يحسب اقتراح توازن بلا حفظ. $opt: mode = fees | c_item | d_item | mix · c_key بند من ج · d_key بند من د.
 * القاعدة: الفرق = الإيرادات بعد المنح − النفقات (أ+ب+ج+د). موجب ⇒ فائض نضيفه للنفقات؛ سالب ⇒ عجز نخفّض النفقات (أو نرفع الأقساط).
 * ج لا يتجاوز سقفه (أ+ب)×35/65؛ د خارج النسبة. يُعاد: changes [{kind,key,label,field,old,new}] + after {diffL,diffU,pctC,pctAB,okRatio,okBalance} + notes.
 */
function meheBalancePlan(array $data, array $p, array $s, array $opt): array {
    $mode = (string)($opt['mode'] ?? 'c_item'); $cKey = (string)($opt['c_key'] ?? 'aid'); $dKey = (string)($opt['d_key'] ?? 'eos_contr');
    $items = meheExpenseItems();
    if (!isset($items[$cKey]) || $items[$cKey][1] !== 'ج') $cKey = 'aid';
    if (!isset($items[$dKey]) || $items[$dKey][1] !== 'د') $dKey = 'eos_contr';
    $changes = []; $notes = [];
    $d = $data; // نسخة عمل
    $gapL = $s['revAfterLL'] - $s['allL']; $gapU = $s['revAfterUSD'] - $s['allU']; // موجب = فائض
    $rnd = fn($v) => (float)round($v);
    $expItem = function (string $key, float $addL, float $addU, string $cat) use (&$d, &$changes, $items, $rnd): array { // يضيف للبند (بلا سالب) ويرجع ما تعذّر إضافته
        $oldL = (float)($d['expenses'][$key]['ll'] ?? 0); $oldU = (float)($d['expenses'][$key]['usd'] ?? 0);
        $newL = max(0, $oldL + $addL); $newU = max(0, $oldU + $addU);
        $restL = ($oldL + $addL) - $newL; $restU = ($oldU + $addU) - $newU; // ما لم يُمتصّ (لو نزل تحت الصفر)
        if ($rnd($newL) !== $rnd($oldL)) $changes[] = ['kind' => 'exp', 'key' => $key, 'label' => $items[$key][0], 'field' => 'll', 'old' => $rnd($oldL), 'new' => $rnd($newL)];
        if ($rnd($newU) !== $rnd($oldU)) $changes[] = ['kind' => 'exp', 'key' => $key, 'label' => $items[$key][0], 'field' => 'usd', 'old' => $rnd($oldU), 'new' => $rnd($newU)];
        $d['expenses'][$key] = ['ll' => $rnd($newL), 'usd' => $rnd($newU)];
        return [$restL, $restU];
    };
    if ($mode === 'fees') {
        // الأقساط بالنسبة: الهدف Σ(قسط×عدد) = النفقات + منح الملاك ⇒ معامل واحد لكل الصفوف، تدوير للألف، والباقي الصغير يُذكر
        $totL = 0; $totU = 0; foreach ($s['revRows'] as $rv) { $totL += $rv['fee_ll'] * $rv['students']; $totU += $rv['fee_usd'] * $rv['students']; }
        if ($totL <= 0 && $totU <= 0) { $notes[] = 'لا أقساط ولا طلاب بعد — لا يمكن التوازن بالأقساط'; }
        else {
            $fL = $totL > 0 ? ($s['allL'] + $s['grTitLL']) / $totL : 1; $fU = $totU > 0 ? ($s['allU'] + $s['grTitUSD']) / $totU : 1;
            foreach ($s['revRows'] as $rv) {
                $i = $rv['idx'];
                $nL = $totL > 0 ? round($rv['fee_ll'] * $fL / 1000) * 1000 : $rv['fee_ll']; $nU = $totU > 0 ? round($rv['fee_usd'] * $fU) : $rv['fee_usd'];
                if ($nL != $rv['fee_ll']) { $changes[] = ['kind' => 'rev', 'key' => (string)$i, 'label' => 'قسط ' . $rv['class'], 'field' => 'fee_ll', 'old' => $rv['fee_ll'], 'new' => $nL]; $d['revenues'][$i]['fee_ll'] = $nL; }
                if ($nU != $rv['fee_usd']) { $changes[] = ['kind' => 'rev', 'key' => (string)$i, 'label' => 'قسط ' . $rv['class'], 'field' => 'fee_usd', 'old' => $rv['fee_usd'], 'new' => $nU]; $d['revenues'][$i]['fee_usd'] = $nU; }
            }
            $notes[] = 'الأقساط عُدِّلت بنسبة واحدة لكل الصفوف (' . round(($fL - 1) * 100, 2) . ' %) مع تدوير القسط للألف — الباقي الصغير، إن وُجد، يُمتصّ ببند ' . $items[$cKey][0];
            $s2 = meheSummary($d, $p); $gapL = $s2['revAfterLL'] - $s2['allL']; $gapU = $s2['revAfterUSD'] - $s2['allU'];
            if (abs($gapL) >= 1 || abs($gapU) >= 1) { [$rL, $rU] = $expItem($cKey, $gapL, $gapU, 'ج'); if (abs($rL) >= 1 || abs($rU) >= 1) $notes[] = 'باقي التدوير ' . number_format(abs($rL)) . ' ل.ل لم يُمتصّ لأنّ البند عند الصفر — ارفع قسط صفّ واحد بهذا المقدار أو اختر بنداً فيه رصيد'; }
        }
    } elseif ($mode === 'c_item') {
        // بند من ج يسدّ الفرق، مع سقف 35٪
        $roomL = $s['cMaxL'] - $s['cSumL']; $roomU = $s['cMaxU'] - $s['cSumU']; // المتاح قبل السقف (سالب = متجاوز أصلاً)
        $addL = $gapL; $addU = $gapU;
        if ($addL > $roomL) { $notes[] = 'بند ج وصل إلى سقف 35 ٪ — ما بقي (' . number_format($addL - $roomL) . ' ل.ل) لم يُضَف؛ استعمل «مزيج» ليذهب إلى بند د'; $addL = max($roomL, 0); }
        if ($addU > $roomU) { $addU = max($roomU, 0); }
        [$rL, $rU] = $expItem($cKey, $addL, $addU, 'ج');
        if (abs($rL) >= 1 || abs($rU) >= 1) $notes[] = 'البند لا ينزل تحت الصفر — بقي ' . number_format(abs($rL)) . ' ل.ل غير مغطّى؛ اختر بنداً آخر أو خفّض د';
    } elseif ($mode === 'd_item') {
        [$rL, $rU] = $expItem($dKey, $gapL, $gapU, 'د');
        if (abs($rL) >= 1 || abs($rU) >= 1) $notes[] = 'البند لا ينزل تحت الصفر — بقي ' . number_format(abs($rL)) . ' ل.ل غير مغطّى؛ اختر بنداً آخر';
    } else { // mix: ج حتى سقفه ثم الباقي إلى د
        $roomL = max(0, $s['cMaxL'] - $s['cSumL']); $roomU = max(0, $s['cMaxU'] - $s['cSumU']);
        $toCL = $gapL > 0 ? min($gapL, $roomL) : $gapL; $toCU = $gapU > 0 ? min($gapU, $roomU) : $gapU;
        [$rL, $rU] = $expItem($cKey, $toCL, $toCU, 'ج');
        $restL = ($gapL - $toCL) + $rL; $restU = ($gapU - $toCU) + $rU;
        if (abs($restL) >= 1 || abs($restU) >= 1) { [$rL2, $rU2] = $expItem($dKey, $restL, $restU, 'د'); if (abs($rL2) >= 1 || abs($rU2) >= 1) $notes[] = 'بقي ' . number_format(abs($rL2)) . ' ل.ل غير مغطّى (البنود لا تنزل تحت الصفر)'; }
        $notes[] = 'ج مُلئ حتى سقف 35 ٪ بالضبط والباقي ذهب إلى ' . $items[$dKey][0];
    }
    $after = meheSummary($d, $p);
    return ['changes' => $changes, 'notes' => $notes,
            'before' => ['diffL' => $s['diffL'], 'diffU' => $s['diffU'], 'pctC' => $s['pctC'], 'pctAB' => $s['pctAB']],
            'after' => ['diffL' => $after['diffL'], 'diffU' => $after['diffU'], 'pctC' => $after['pctC'], 'pctAB' => $after['pctAB'], 'okRatio' => $after['okRatio'], 'okBalance' => $after['okBalance'], 'cOverL' => $after['cOverL']],
            'data' => $d];
}
/** متوسّط الرسوم (ل.ل/د.أ) من بيانات موازنة محفوظة (الإيرادات والمنح فقط — بلا رواتب) — لسطر «فرق الرسوم عن العام الماضي» */
function meheAvgFeeFromData(array $d): array {
    $students = 0; $revLL = $revUSD = 0; $grN = 0; $grLL = $grUSD = 0;
    foreach ((array)($d['revenues'] ?? []) as $rv) { if (trim((string)($rv['class'] ?? '')) === '') continue; $n = (int)($rv['students'] ?? 0); $students += $n; $revLL += (float)($rv['fee_ll'] ?? 0) * $n; $revUSD += (float)($rv['fee_usd'] ?? 0) * $n; }
    foreach ((array)($d['grants'] ?? []) as $g) { if (trim((string)($g['student'] ?? '')) === '' || ($g['cat'] ?? '') !== 'ملاك') continue; $grN++; $grLL += (float)($g['ll'] ?? 0); $grUSD += (float)($g['usd'] ?? 0); }
    $payers = max(0, $students - $grN);
    return ['ll' => $payers > 0 ? ($revLL - $grLL) / $payers : 0, 'usd' => $payers > 0 ? ($revUSD - $grUSD) / $payers : 0, 'students' => $students];
}
/** آخر موازنة محفوظة سابقة لهذا النطاق (بياناتها) أو null */
function mehePrevSaved(PDO $db, array $ids, string $sy): ?array {
    try {
        $pv = $db->prepare("SELECT school_year, data FROM mehe_budget WHERE scope = ? AND school_year < ? ORDER BY school_year DESC LIMIT 1");
        $pv->execute([meheScopeKey($ids), $sy]);
        $r = $pv->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $d = json_decode((string)$r['data'], true);
        return is_array($d) ? ['sy' => $r['school_year'], 'data' => $d] : null;
    } catch (Throwable $e) { return null; }
}
/** 🏛️🖥️ المنصّة لا تعرض «فئة المعلم» بقائمة المعفيين بل تستنتجها من عضو هيئة التدريس المختار ⇒ فئة كل منحة = ملاك إن كان اسمه بجدول الملاك وإلا بقية الكادر */
function meheSyncGrantCats(array &$data, array $p): void {
    $tit = []; foreach ((array)($p['tit'] ?? []) as $r) $tit[trim((string)$r['name'])] = true;
    foreach ((array)($data['grants'] ?? []) as $i => $g) {
        $t = trim((string)($g['teacher'] ?? ''));
        if ($t === '') continue;
        $data['grants'][$i]['cat'] = isset($tit[$t]) ? 'ملاك' : 'بقية الكادر';
    }
}
/** 🏛️🖥️ الوثائق المطلوبة: مجلّد الرفع لنطاق وسنة (uploads/mehe/) — يُنشأ عند الحاجة */
function meheDocsDir(): string { $dir = dirname(__DIR__) . '/uploads/mehe'; if (!is_dir($dir)) @mkdir($dir, 0775, true); return $dir; }
function meheDocFileName(array $ids, string $sy, string $key, string $ext): string { return preg_replace('/[^A-Za-z0-9_.-]/', '_', meheScopeKey($ids) . '_' . $sy . '_' . $key) . '.' . strtolower($ext); }

/* ===================== مولّد إكسل متعدّد الأوراق (PHP خالص) بصيغ حيّة ===================== */
class MeheXlsx {
    private array $sheets = [];
    private static function xa($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
    public static function col(int $i): string { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
    /** أنماط: 0 عادي · 1 عنوان · 2 رأس · 3 نص بإطار · 4 رقم · 5 رقم بخانتين · 6 مجموع نص · 7 مجموع رقم · 8 مجموع رقم بخانتين · 9 نص عريض · 10 ملاحظة حمراء · 11 نسبة · 12 نص عادي · 13 وسط بإطار · 14 أخضر بإطار */
    public function sheet(string $name, array $widths, bool $landscape = true): int {
        $this->sheets[] = ['name' => mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':', "'"], ' ', $name), 0, 31, 'UTF-8'), 'widths' => $widths, 'rows' => [], 'merges' => [], 'breaks' => [], 'validations' => [], 'landscape' => $landscape];
        return count($this->sheets) - 1;
    }
    /** خلية: ['v'=>قيمة, 's'=>نمط, 'f'=>صيغة بلا =] — أو قيمة خام (نص/رقم) */
    public function row(int $si, array $cells, ?float $height = null): int {
        $this->sheets[$si]['rows'][] = ['cells' => $cells, 'h' => $height];
        return count($this->sheets[$si]['rows']); // رقم الصفّ (1-based)
    }
    public function merge(int $si, string $ref): void { $this->sheets[$si]['merges'][] = $ref; }
    public function pageBreak(int $si, int $afterRow): void { $this->sheets[$si]['breaks'][] = $afterRow; }
    /** قائمة منسدلة على نطاق (مثل G3:G200) بخيارات نصّية؛ الكتابة الحرّة مسموحة (لا رسالة خطأ) */
    public function listValidation(int $si, string $range, array $options): void { $this->sheets[$si]['validations'][] = [$range, $options]; }
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
        $dvXml = '';
        if (!empty($sh['validations'])) { $dvXml = '<dataValidations count="' . count($sh['validations']) . '">'; foreach ($sh['validations'] as [$rg, $opts]) $dvXml .= '<dataValidation type="list" allowBlank="1" showDropDown="0" showInputMessage="1" showErrorMessage="0" sqref="' . $rg . '"><formula1>"' . self::xa(implode(',', $opts)) . '"</formula1></dataValidation>'; $dvXml .= '</dataValidations>'; }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0" zoomScale="90"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>' . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>' . $mergesXml . $dvXml
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
            . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill>'   // 2 رأس
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4D6"/></patternFill></fill>'   // 3 مجموع
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFCE4E4"/></patternFill></fill>'   // 4 ناقص (أحمر فاتح)
            . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $b . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="16">'
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
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' // 15 ناقص: خلفية حمراء فاتحة بإطار
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

    // 🏛️🖥️ (2026-11-08) الأوراق الاثنتا عشرة بترتيب منصّة الوزارة psb.mehe.gov.lb وبعناوينها
    $T = meheSectionTitles();
    $multiTxt = fn($v) => implode('، ', meheMulti($v));
    // ١) تحديث معلومات المدرسة
    $si = $X->sheet($T[0], [34, 30, 30, 30]);
    $X->row($si, [$c($T[0] . ' — ' . $schoolName . ' — ' . $y1 . '/' . $y2, 1)], 26); $X->row($si, []);
    $X->row($si, [$c('اسم المدير', 6), $c($d['director'], 3)]);
    $X->row($si, [$c('رئيس لجنة أولياء الأمور', 6), $c($d['parents_head'], 3)]);
    $X->row($si, [$c('رقم هاتف رئيس لجنة أولياء الأمور', 6), $c($d['parents_phone'], 3)]); $X->row($si, []);
    $r = $X->row($si, [$c('البرامج', 6), $c($multiTxt($d['programs_sel'] ?? ''), 3)]); $X->merge($si, "B$r:D$r");
    $r = $X->row($si, [$c('مستوى التعليم — منهاج لبناني', 6), $c($multiTxt($d['levels_sel'] ?? ''), 3)]); $X->merge($si, "B$r:D$r");
    $X->row($si, []); $X->row($si, [$c('الصفوف', 9)]);
    $selC = meheMulti($d['classes_sel'] ?? '');
    foreach (meheClassesByLevel() as $lv => $cls) { $r = $X->row($si, [$c($lv, 6), $c(implode('، ', array_values(array_filter($cls, fn($x) => in_array($x, $selC, true)))), 3)]); $X->merge($si, "B$r:D$r"); }
    $X->row($si, []); $X->row($si, [$c('أعضاء اللجنة المالية', 9)]);
    $i = 0; foreach (array_filter(array_map('trim', explode("\n", (string)$d['fin_committee']))) as $m) $X->row($si, [$c('الاسم', 6), $c($m, 3)]);
    // ٢) استطلاع
    $si = $X->sheet($T[1], [34, 30, 30, 30]);
    $X->row($si, [$c($T[1], 1)], 26); $X->row($si, [$c('مباني المدرسة', 9)]);
    $X->row($si, [$c('مساحة الملاعب المفتوحة (م²)', 6), $n((int)$d['playground_open'])]);
    $X->row($si, [$c('مساحة الملاعب المغلقة (م²)', 6), $n((int)$d['playground_closed'])]);
    $X->row($si, [$c('مالك العقار', 6), $c($multiTxt($d['owner_sel'] ?? ''), 3)]);
    $X->row($si, [$c('اسم مالك المبنى', 6), $c($d['building_owner'], 3)]);
    $head($si, ['نوع المبنى', 'عدد المباني']);
    $X->row($si, [$c('بناء مدرسي', 6), $n((int)$d['buildings_school'])]); $X->row($si, [$c('بناء سكني', 6), $n((int)$d['buildings_res'])]);
    $r = $X->row($si, [$c('مباني مشتركة مع', 6), $c($multiTxt($d['shared_sel'] ?? ''), 3)]); $X->merge($si, "B$r:D$r");
    $X->row($si, []); $X->row($si, [$c('الغرف والقاعات', 9)]);
    $head($si, ['نوع الغرفة', 'عدد الغرف']);
    foreach (meheRoomTypes() as $rt) $X->row($si, [$c($rt, 6), $n((int)($d['rooms'][$rt] ?? 0))]);
    $X->row($si, []); $X->row($si, [$c('اللغات', 9)]);
    $head($si, ['اللغة', 'اولي', 'ثانوي', 'غير معتمدة في المدرسة']);
    foreach (meheLanguages() as $k => $lb) {
        $v = (int)($d['languages'][$k] ?? 3);
        $X->row($si, [$c($lb, 6), $c($v === 1 ? '◉' : '○', 13), $c($v === 2 ? '◉' : '○', 13), $c($v === 3 ? '◉' : '○', 13)]);
    }
    $X->row($si, []); $X->row($si, [$c('المعدات التقنية', 9)]);
    $head($si, ['المعدات', 'عدد المستخدم من قبل الإدارة', 'عدد المستخدم لأغراض تعليمية']);
    foreach (meheEquipmentTypes() as $et) $X->row($si, [$c($et, 6), $n((int)($d['equipment'][$et]['admin'] ?? 0)), $n((int)($d['equipment'][$et]['edu'] ?? 0))]);
    $X->row($si, []);
    $r = $X->row($si, [$c('استخدام الانترنت', 6), $c($multiTxt($d['internet_sel'] ?? ''), 3)]); $X->merge($si, "B$r:D$r");
    $r = $X->row($si, [$c('تفاصيل اخرى', 6), $c($multiTxt($d['other_sel'] ?? ''), 3)]); $X->merge($si, "B$r:D$r");

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
        $mcells = [$c('الأشهر', 2)];
        foreach ($keys as $i => $k) { $col = $i + 1; if ($col === 1) continue; $mcells[] = $col >= $firstNum ? ($k === 'total_ll' || $k === 'total_usd' ? $c('', 2) : $n((int)($months[$k] ?? 12), 4)) : $c('', 2); }
        $mrow = $X->row($si, $mcells);
        $tcells = [$c('المجموع', 6)];
        foreach ($keys as $i => $k) {
            $col = $i + 1; if ($col === 1) continue;
            $tcells[] = $col >= $firstNum ? ($k === 'total_ll' || $k === 'total_usd' ? $c('', 6) : $fx('SUM(' . $L($col) . $first . ':' . $L($col) . $last . ')*' . $L($col) . $mrow, 8)) : $c('', 6);
        }
        $trow = $X->row($si, $tcells, 20);
        $X->row($si, []);
        $X->row($si, [$c('أشهر الراتب', 6), $n((int)($months['_salary'] ?? 12), 4), $c('أشهر النقل', 6), $n((int)($months['_transport'] ?? 12), 4)]);
        $X->row($si, []);
        $sig = array_fill(0, $sigCol, $c('', 0)); $sig[0] = $c('توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)', 12); $sig[$sigCol - 1] = $c('توقيع مدير المدرسة', 12);
        $X->row($si, $sig);
        $refs = [];
        foreach ($keys as $i => $k) if ($i + 1 >= $firstNum) $refs[$k] = "'" . $name . "'!" . $L($i + 1) . $trow;
        return $refs;
    };
    // أشهر الراتب/النقل للفئة (المنصّة) تُعرض تحت كل جدول
    $mwith = function (array $months, string $tk) use ($d): array { $months['_salary'] = (int)($d['months'][$tk]['salary'] ?? 12); $months['_transport'] = (int)($d['months'][$tk]['transport'] ?? 12); return $months; };
    // عند مجموعة مدارس: عمود «المدرسة» بعد الاسم بكل جدول موظفين
    $multi = !empty($p['multi']);
    $mc = fn(array $cols) => $multi ? array_merge([$cols[0], 'المدرسة'], array_slice($cols, 1)) : $cols;
    $mk = fn(array $keys) => $multi ? array_merge([$keys[0], 'school'], array_slice($keys, 1)) : $keys;
    $mw = fn(array $w) => $multi ? array_merge([$w[0], 24], array_slice($w, 1)) : $w;
    $mf = fn(int $f) => $multi ? $f + 1 : $f;
    // أعمدة الجداول = أعمدة المنصّة حرفياً وبترتيبها (معرّف الموظف أوّلاً، إجمالي الراتب بعد الأجور الإضافية)
    $TIT = $staffSheet($T[2], $T[2],
        $mc(['معرف الموظف', 'الاسم والشهرة', 'دور الموظف', 'مستوى التعليم', 'مؤهلات المعلم', 'تاريخ الدخول الى الملاك', 'تاريخ مباشرة العمل', 'ساعات أسبوعية (ملاك)', 'ساعات أسبوعية (اضافية)',
         'أساس الراتب', 'أجور إضافية ل.ل', 'أجور إضافية د.أ', 'إجمالي الراتب ل.ل', 'إجمالي الراتب د.أ', 'الأثر الرجعي ل.ل', 'أجور مهمات تتجاوز نصاب العمل ل.ل', 'ما يتجاوز حد ال35 ساعة ل.ل', 'المكافآت ل.ل', 'مهام إضافية ل.ل',
         'تعويض عائلي ل.ل', 'تعويض النقل ل.ل', 'مساهمة الصندوق الوطني للضمان الاجتماعي ل.ل', 'صندوق التعويضات ل.ل']),
        $mk(['mehe_id', 'name', 'role', 'level', 'qual', 'cadre_date', 'start_date', 'h_cadre', 'h_extra', 'base', 'extra_ll', 'extra_usd', 'total_ll', 'total_usd', 'retro', 'missions_ll', 'missions35', 'bonus', 'tasks_ll', 'family', 'transport', 'cnss', 'fund']),
        $p['tit'], $mwith($p['tit_months'], 'tit'), $mw([17, 22, 12, 12, 18, 12, 12, 9, 9, 16, 19, 12, 18, 12, 10, 13, 13, 11, 11, 14, 18, 18, 17]), $mf(10), ['missions_ll', 'missions35', 'cnss', 'fund'], 12);
    $CON = $staffSheet($T[3], $T[3],
        $mc(['معرف الموظف', 'الاسم والشهرة', 'دور الموظف', 'مستوى التعليم', 'نمط العمل', 'نوع الضمان الاجتماعي', 'مؤهلات المعلم', 'تاريخ مباشرة العمل', 'ساعات أسبوعية', 'أساس الراتب', 'أجور إضافية ل.ل', 'أجور إضافية د.أ', 'إجمالي الراتب ل.ل', 'إجمالي الراتب د.أ', 'المكافآت ل.ل', 'مهام إضافية ل.ل', 'تعويض النقل ل.ل', 'مساهمة الصندوق الوطني للضمان الاجتماعي ل.ل']),
        $mk(['mehe_id', 'name', 'role', 'level', 'mode', 'cnss_type', 'qual', 'start_date', 'h_cadre', 'base', 'extra_ll', 'extra_usd', 'total_ll', 'total_usd', 'bonus', 'tasks_ll', 'transport', 'cnss']),
        $p['con'], $mwith($p['con_months'], 'con'), $mw([17, 22, 12, 12, 10, 10, 18, 12, 9, 16, 19, 13, 18, 12, 11, 12, 17, 18]), $mf(10), ['cnss'], 10);
    $ADM = $staffSheet($T[4], $T[4],
        $mc(['معرف الموظف', 'الاسم والشهرة', 'دور الموظف', 'نوع الموظف الاداري', 'نوع الضمان الاجتماعي', 'تاريخ مباشرة العمل', 'أساس الراتب', 'أجور إضافية ل.ل', 'أجور إضافية د.أ', 'إجمالي الراتب ل.ل', 'إجمالي الراتب د.أ', 'مهام إضافية ل.ل', 'منح مدرسية ل.ل', 'تعويض النقل ل.ل', 'مساهمة الصندوق الوطني للضمان الاجتماعي ل.ل']),
        $mk(['mehe_id', 'name', 'admin_mode', 'admin_type', 'cnss_type', 'start_date', 'base', 'extra_ll', 'extra_usd', 'total_ll', 'total_usd', 'tasks_ll', 'grants_ll', 'transport', 'cnss']),
        $p['adm'], $mwith($p['adm_months'], 'adm'), $mw([17, 22, 20, 14, 12, 13, 16, 19, 13, 18, 12, 13, 13, 17, 18]), $mf(7), ['cnss'], 8);

    // ٦) الهيكل
    $si = $X->sheet($T[5], [50, 16]);
    $X->row($si, [$c($T[5], 1)], 26); $X->row($si, []);
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

    // ٧) الطلاب المعفيون (المنصّة: الفئة تُستنتج من عضو هيئة التدريس؛ تبقى عموداً بالإكسل لأنّ صيغ المجاميع تحتاجها)
    $si = $X->sheet($T[6], [22, 26, 14, 34, 18, 16]);
    $X->row($si, [$c($T[6], 1)], 26);
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
    $X->row($si, [$c($T[7], 1)], 26);
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
    foreach ($A as $ai => [$lb, $fl, $fu]) $X->row($si, !empty($s['A'][$ai][3]['ov']) ? [$c($lb, 6), $n((float)$s['A'][$ai][1], 4), $n((float)$s['A'][$ai][2], 4)] : [$c($lb, 6), $fx($fl, 4), $fx($fu, 4)]); // ✏️ سطر مفروض يدوياً ⇒ قيمة ثابتة
    $a2 = $X->rowCount($si); $X->row($si, []);
    $b1 = $section('النفقات من الفئة ب');
    $B = [
        ['تعويض نقل', $TIT['transport'] . '+' . $CON['transport'] . '+' . $ADM['transport'], '0'],
        ['تعويض عائلي', $TIT['family'], '0'],
        ['اشتراكات الصندوق الوطني للضمان الاجتماعي', $TIT['cnss'] . '+' . $CON['cnss'] . '+' . $ADM['cnss'], '0'],
        ['مساهمة المدرسة في صندوق التعويضات لأفراد الهيئة التعليمية', 'ROUNDUP(' . $TIT['fund'] . ',-3)', '0'],
        [meheExpenseItems()['cnss_extra'][0], $EXP['cnss_extra'][0], $EXP['cnss_extra'][1]],
    ];
    foreach ($B as $bi => [$lb, $fl, $fu]) $X->row($si, !empty($s['B'][$bi][3]['ov']) ? [$c($lb, 6), $n((float)$s['B'][$bi][1], 5), $n((float)$s['B'][$bi][2], 5)] : [$c($lb, 6), $fx($fl, 5), $fx($fu, 5)]);
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
    foreach (meheSummaryDOrder() as $di => $k) {
        if (!empty($s['D'][$di][3]['ov'])) $X->row($si, [$c($dl[$k] ?? meheExpenseItems()[$k][0], 6), $n((float)$s['D'][$di][1], 5), $n((float)$s['D'][$di][2], 5)]);
        elseif (isset($Dm[$k])) $X->row($si, [$c($dl[$k], 6), $fx($Dm[$k][0], 5), $fx($Dm[$k][1], 5)]);
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
    $X->row($si, [$c('فرق الرسوم الدراسية عن العام الماضي', 6), $s['feeDiffL'] === null ? $c('-', 13) : $n(round((float)$s['feeDiffL'], 2), 5), $s['feeDiffU'] === null ? $c('-', 13) : $n(round((float)$s['feeDiffU'], 2), 5), $c('', 13), $c($s['feeDiffL'] === null ? 'لا موازنة سابقة محفوظة' : '', 3)]);
    $X->row($si, []);
    $X->row($si, [$c('توقيع رئيس لجنة الأهل ومندوبي اللجنة في الهيئة الحالية مادة 10 (أ فقرة 8)', 12), $c('', 0), $c('', 0), $c('توقيع مدير المدرسة', 12)]);
    // ١٢) الوثائق المطلوبة
    $si = $X->sheet($T[11], [46, 50]);
    $X->row($si, [$c($T[11], 1)], 26); $head($si, ['الوثيقة', 'الملف المرفوع']);
    foreach (meheDocTypes() as $k => $lb) $X->row($si, [$c($lb, 6), $c((string)($d['docs'][$k]['orig'] ?? '—'), 3)]);
    return $X->bytes();
}
