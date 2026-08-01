<?php
/**
 * report_helpers.php — مساعدات موحّدة للتقارير والنماذج الرسمية
 * ترويسة المدرسة الرسمية + ترويسة حكومية + ستايل طباعة A4 + دوال أسماء/تعبئة
 *
 * يُستعمل من: pages/official_forms.php و reports.php وأي صفحة تقرير.
 */

require_once __DIR__ . '/functions.php';

/**
 * الاسم الكامل للموظف (عربي مع fallback فرنسي والعكس).
 */
function empFullNameAr(array $e): string {
    $parts = array_filter([$e['first_name_ar'] ?? '', $e['father_name_ar'] ?? '', $e['last_name_ar'] ?? '']);
    $name = trim(implode(' ', $parts));
    if ($name !== '') return $name;
    return trim(($e['first_name_fr'] ?? '') . ' ' . ($e['last_name_fr'] ?? ''));
}
function empFullNameFr(array $e): string {
    $name = trim(($e['first_name_fr'] ?? '') . ' ' . ($e['last_name_fr'] ?? ''));
    if ($name !== '') return $name;
    return empFullNameAr($e);
}
function empFullName(array $e): string {
    $lang = $_SESSION['lang'] ?? 'fr';
    return $lang === 'ar' ? empFullNameAr($e) : empFullNameFr($e);
}

/**
 * عمودا «الأجر الإضافي» و«مكافأة ومساعدة» الموحّدان لكل التقارير والكشوف.
 * (مصدر واحد للتسمية والحساب — لا تكرّر المنطق ولا تغيّر الأسماء؛ موحّد مع
 *  كشف الضمان الاسمي وتفصيل الراتب والكشف السنوي وبطاقة الراتب.)
 *   - الأجر الإضافي  = extra_lbp + prime_fixe_lbp
 *   - مكافأة ومساعدة = aide_complementaire_lbp
 * يتطلّب أن يحتوي صف الراتب على هذه الأعمدة (SELECT ms.* أو إضافتها صراحةً).
 */
function extraWageLbp(array $r): int {
    return (int)($r['extra_lbp'] ?? 0) + (int)($r['prime_fixe_lbp'] ?? 0);
}
function aideCompLbp(array $r): int {
    return (int)($r['aide_complementaire_lbp'] ?? 0);
}
/** رأسا العمودين (يُدرجان في <thead><tr>).
 *  يتبعان زرّ «الراتب المركّب يشمل» بالترويسة: العمود غير المختار يُخفى كلياً من التقرير.
 *  $attrs: سمات إضافية للـ<th> (مثل ' rowspan="2"'). */
function extraAideHeads(string $attrs = ''): string {
    $h = '';
    if (salaryCompHas('extra')) $h .= '<th' . $attrs . '>الأجر الإضافي</th>';
    if (salaryCompHas('aide'))  $h .= '<th' . $attrs . '>مكافأة ومساعدة</th>';
    return $h;
}
/** خليّتا العمودين لصف الجسم (تتبعان زرّ «الراتب المركّب يشمل» — تُخفيان إن لم تُختارا).
 *  $num=true يضيف class="num" (للنماذج الرسمية ذات الأرقام يساراً).
 *  تعرض العملة حسب وضع المستخدم (ليرة/دولار/الاثنين) عبر money() بسعر صرف شهر الصف. */
function extraAideCells(array $r, bool $num = true): string {
    $cls = $num ? ' class="num"' : '';
    $rate = rowRate($r);
    $h = '';
    if (salaryCompHas('extra')) $h .= '<td' . $cls . '>' . money(extraWageLbp($r), $rate, ['withCur' => false]) . '</td>';
    if (salaryCompHas('aide'))  $h .= '<td' . $cls . '>' . money(aideCompLbp($r), $rate, ['withCur' => false]) . '</td>';
    return $h;
}
/** خليّتا مجاميع العمودين (لصفوف subtotal/total المبنية من مصفوفات مجاميع بالليرة والدولار). */
function extraAideTotalCells($exLbp, $exUsd, $aiLbp, $aiUsd, bool $num = true): string {
    $cls = $num ? ' class="num"' : '';
    $h = '';
    if (salaryCompHas('extra')) $h .= '<td' . $cls . '>' . dualFromUsd($exLbp, $exUsd, false) . '</td>';
    if (salaryCompHas('aide'))  $h .= '<td' . $cls . '>' . dualFromUsd($aiLbp, $aiUsd, false) . '</td>';
    return $h;
}
/** رأس عمود «تعويض النقل» (يتبع زرّ «الراتب المركّب يشمل» — يُخفى إن لم يُختَر النقل). */
function transportHead(string $attrs = '', string $label = 'تعويض النقل'): string {
    return salaryCompHas('transport') ? '<th' . $attrs . '>' . $label . '</th>' : '';
}
/** خلية «تعويض النقل» لصف الجسم (تُخفى إن لم يُختَر النقل). */
function transportCell(array $r, bool $num = true): string {
    if (!salaryCompHas('transport')) return '';
    $cls = $num ? ' class="num"' : '';
    return '<td' . $cls . '>' . money((int)($r['transport_lbp'] ?? 0), rowRate($r), ['withCur' => false]) . '</td>';
}
/** خلية مجموع «تعويض النقل» (لصفوف المجاميع). */
function transportTotalCell($lbp, $usd, bool $num = true): string {
    if (!salaryCompHas('transport')) return '';
    $cls = $num ? ' class="num"' : '';
    return '<td' . $cls . '>' . dualFromUsd($lbp, $usd, false) . '</td>';
}
/**
 * مجموع المكوّنات **المخفية** بزرّ «الراتب يشمل» — يُطرَح من الإجماليات المعروضة
 * (المستحق/الكلفة) كي يبقى كل رقم إجمالي = مجموع الأعمدة الظاهرة أمام المستخدم
 * («الأرقام تركب» — نمط الكشف السنوي المعتمد). $extraAide=true يشمل أيضاً
 * الإضافي والمكافأة (للإجماليات التي تجمعها مباشرةً كالكلفة على المؤسسة).
 */
function hiddenCompsLbp(array $r, bool $extraAide = false): int {
    $h = 0;
    if (!salaryCompHas('transport')) $h += (int)($r['transport_lbp'] ?? 0);
    if ($extraAide) {
        if (!salaryCompHas('extra')) $h += (int)($r['extra_lbp'] ?? 0) + (int)($r['prime_fixe_lbp'] ?? 0);
        if (!salaryCompHas('aide'))  $h += (int)($r['aide_complementaire_lbp'] ?? 0);
    }
    return $h;
}
/** «المستحق المعروض»: total_due ناقص النقل عندما يكون عموده مخفياً (زرّ «الراتب يشمل»). */
function dueShownLbp(array $r): int {
    return (int)($r['total_due_lbp'] ?? 0) - hiddenCompsLbp($r);
}
/**
 * الأجر الخاضع للضمان/نهاية الخدمة حسب **خيار خضوع الأستاذ** (الزرّ الأخضر بملف الأستاذ):
 * أساس+درجة + (الأجر الإضافي إن `cnss_includes_extra`) + (مكافأة ومساعدة إن `cnss_includes_prime_aide`).
 * نهاية الخدمة (٨.٥٪) تشتقّ وعاءها من وعاء الضمان (نفس الفلاغين) فتُستعمل لها أيضاً.
 * تُستعمل بنماذج الضمان الإفرادية ليتبع المبلغُ خيارَ المستخدم لا أن يُحشَر دائماً.
 */
function cnssSubjectWageLbp(array $row, array $emp): int {
    $w = (int)($row['base_plus_echelon_lbp'] ?? 0);
    if (!empty($emp['cnss_includes_extra']))      $w += extraWageLbp($row);
    if (!empty($emp['cnss_includes_prime_aide']))  $w += aideCompLbp($row);
    return $w;
}

/**
 * عنوان الموظف الكامل (مدينة - شارع - مبنى - طابق).
 */
function empAddress(array $e): string {
    $parts = array_filter([
        $e['ville'] ?? '', $e['quartier'] ?? '', $e['rue'] ?? '',
        ($e['immeuble'] ?? '') ? ('مبنى ' . $e['immeuble']) : '',
        ($e['etage'] ?? '') ? ('طابق ' . $e['etage']) : '',
    ]);
    return implode(' - ', $parts);
}

/**
 * قيمة أو خط منقّط للتعبئة اليدوية عند الفراغ (متل النماذج الرسمية).
 */
function fillVal($value, string $minWidth = '120px'): string {
    $value = trim((string)$value);
    if ($value !== '' && $value !== '—') {
        return '<span class="fill">' . e($value) . '</span>';
    }
    return '<span class="fill dotted" style="min-width:' . $minWidth . '">&nbsp;</span>';
}

/**
 * مربعات أرقام التسجيل (متل النماذج الرسمية): خانة لكل رقم.
 */
function digitBoxes($value, int $count = 8): string {
    $value = preg_replace('/\D/', '', (string)$value);
    $out = '<span class="digit-boxes">';
    for ($i = 0; $i < $count; $i++) {
        $out .= '<span>' . (isset($value[$i]) ? e($value[$i]) : '&nbsp;') . '</span>';
    }
    return $out . '</span>';
}

/**
 * خانة اختيار رسمية مع رقم/علامة (يُعلَّم إن $checked).
 */
function cbox(string $label, bool $checked = false, string $mark = '×'): string {
    return '<span class="checkbox-opt">' . e($label)
         . '<span class="bx">' . ($checked ? $mark : '&nbsp;') . '</span></span>';
}

/**
 * خيار مرقّم بأسلوب نماذج الضمان: «التسمية [رقم]» — يُظلَّل إن $checked.
 */
function fopt(string $label, $num, bool $checked = false): string {
    return '<span class="opt' . ($checked ? ' on' : '') . '">' . e($label)
         . '<span class="n">' . e((string)$num) . '</span></span>';
}

/**
 * قيمة سطر نموذج (تظهر فوق الخط المنقّط). $cls: g (قصير) / lg (طويل) / '' (يملأ).
 */
function fval($value, string $cls = ''): string {
    return '<span class="val' . ($cls ? ' ' . $cls : '') . '">' . e(trim((string)$value)) . '</span>';
}

/**
 * ترويسة المدرسة الرسمية (لوغو + اسم + عنوان + أرقام رسمية).
 * $opts: show_numbers (افتراضي true) — إظهار أرقام الضمان/المالية.
 */
function schoolLetterhead(?array $school = null, array $opts = []): string {
    $school = $school ?? currentSchool();
    if (!$school) return '';
    $showNumbers = $opts['show_numbers'] ?? true;

    $logo = '';
    if (!empty($school['logo_path'])) {
        $logo = '<img src="' . BASE_URL . e($school['logo_path']) . '" class="lh-logo" alt="logo">';
    }
    ob_start(); ?>
    <div class="letterhead">
        <div class="lh-left">
            <?= $logo ?>
        </div>
        <div class="lh-center">
            <div class="lh-name-ar"><?= e($school['name_ar'] ?? '') ?></div>
            <div class="lh-name-fr"><?= e($school['name_fr'] ?? '') ?></div>
            <?php if (!empty($school['address']) || !empty($school['phone'])): ?>
            <div class="lh-contact">
                <?= e($school['address'] ?? '') ?>
                <?php if (!empty($school['phone'])): ?> &nbsp;•&nbsp; <i class="fas fa-phone"></i> <?= e($school['phone']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="lh-right">
            <?php if ($showNumbers): ?>
                <?php if (!empty($school['nssf_employer_number'])): ?>
                <div class="lh-num">ر.الضمان: <strong><?= e($school['nssf_employer_number']) ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($school['finance_number'])): ?>
                <div class="lh-num">ر.المالية: <strong><?= e($school['finance_number']) ?></strong></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * ترويسة حكومية رسمية (الجمهورية اللبنانية / الوزارة / المديرية / رمز النموذج).
 */
function govHeader(string $ministry, string $directorate, string $formCode = '', string $formTitle = ''): string {
    ob_start(); ?>
    <div class="gov-header">
        <?php if ($formCode !== ''): ?><div class="gov-code"><?= e($formCode) ?></div><?php endif; ?>
        <div class="gov-center">
            <div>الجمهورية اللبنانية</div>
            <div><?= e($ministry) ?></div>
            <div><?= e($directorate) ?></div>
            <?php if ($formTitle !== ''): ?><div class="gov-title"><?= e($formTitle) ?></div><?php endif; ?>
        </div>
        <div class="gov-emblem"><img src="<?= BASE_URL ?>assets/img/lebanon.png" onerror="this.style.display='none'" alt=""></div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * صندوق توقيع وخاتم.
 */
function signatureBox(string $label = 'توقيع المدير وخاتم المدرسة', string $place = '', string $date = ''): string {
    ob_start(); ?>
    <div class="sign-box">
        <?php if ($place !== '' || $date !== ''): ?>
        <div class="sign-place"><?= e($place ?: '..............') ?> في <?= e($date ?: '....../....../..........') ?></div>
        <?php endif; ?>
        <div class="sign-label"><?= e($label) ?></div>
        <div class="sign-line">&nbsp;</div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * شريط أدوات النموذج (رجوع + طباعة) — لا يظهر بالطباعة.
 * $back: رابط الرجوع (افتراضي «نفس الصفحة اللي كان فيها» عبر docBackUrl).
 * بوضع «عرض المستند» (doc-view) لا يُطبع شيء: الرجوع صار بالشريط العلوي الموحّد
 * والطباعة بشريط التصدير — حتى لا تتكرّر الأزرار وتعجّق الشاشة.
 */
function officialFormToolbar(string $back = ''): string {
    if (!empty($GLOBALS['docFocus'])) return '';
    $back = $back ?: docBackUrl();
    ob_start(); ?>
    <div class="page-actions no-print">
        <a href="<?= e($back) ?>" class="btn btn-light"><i class="fas fa-arrow-left"></i> Retour / رجوع</a>
        <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Imprimer / طباعة</button>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * ===== الورقة الموحّدة لكل التقارير والإفادات (docSheet) =====
 * قالب واحد ثابت: ورقة بيضاء نظيفة تحوي (١) ترويسة المدرسة الرسمية — أو بانر
 * المدارس المختارة عند التعدّد — (٢) عنوان التقرير موحّداً (فرنسي فوق وعربي تحت)
 * (٣) سطر معلومات موحّد (الفترة/العدد + العملة + «الراتب يشمل» + تاريخ الإصدار).
 * تُظبَط مرّة واحدة هنا فتنظبط كل التقارير معاً — ممنوع ترتيب تقرير «لحاله».
 *
 * $chips: معلومات التقرير (مثل الشهر/السنة أو العدد). $opts:
 *   - school: ترويسة مدرسة محدّدة بدل الحالية · no_letterhead: بلا ترويسة
 *   - comp: false = بلا شارة «الراتب يشمل» (لتقارير لا تعرض رواتب)
 */
function docSheetStart(string $titleFr, string $titleAr, array $chips = [], array $opts = []): string {
    $multi = (function_exists('reportIsMultiSchool') && reportIsMultiSchool()) || isAllSchools();
    $auto = [];
    $cur = displayCurrency();
    if ($cur !== 'both') $auto[] = 'العملة: ' . ($cur === 'usd' ? 'دولار فقط' : 'ليرة فقط');
    if (($opts['comp'] ?? true) && function_exists('salaryCompLabel')) $auto[] = 'الراتب يشمل: ' . salaryCompLabel();
    $auto[] = 'صدر بتاريخ ' . formatDate(date('Y-m-d'));
    ob_start(); ?>
    <div class="doc-sheet">
        <?php if (empty($opts['no_letterhead'])): ?>
            <?php if (!$multi || !empty($opts['school'])): ?>
                <?= schoolLetterhead($opts['school'] ?? currentSchool()) ?>
            <?php else: ?>
                <div class="report-scope"><i class="fas fa-school"></i> <strong>المدارس / Écoles:</strong>
                    <?= e(implode(' + ', array_map('schoolNameById', selectedReportSchoolIds())) ?: 'Toutes les écoles / كل المدارس') ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="doc-head">
            <div class="dh-fr" dir="ltr"><?= e($titleFr) ?></div>
            <div class="dh-ar"><?= e($titleAr) ?></div>
            <div class="dh-meta">
                <?php foreach (array_merge($chips, $auto) as $c): ?><span class="dh-chip"><?= e($c) ?></span><?php endforeach; ?>
            </div>
        </div>
    <?php
    return ob_get_clean();
}
function docSheetEnd(): string {
    return '</div>';
}

/**
 * يحمّل قالب نموذج مولّد من Excel (نفس الفورمة/الأسماء) ويعبّي خانات البيانات @@key@@.
 */
function renderFormTemplate(string $tpl, array $vars): string {
    $path = __DIR__ . '/form_templates/' . $tpl . '.html';
    $html = @file_get_contents($path);
    if ($html === false) return '<div class="alert alert-danger">قالب غير موجود: ' . e($tpl) . '</div>';
    $s = []; $r = [];
    foreach ($vars as $k => $v) { $s[] = '@@' . $k . '@@'; $r[] = e((string)$v); }
    $body = str_replace($s, $r, $html);
    // أي خانات غير معبّأة → تُفرَّغ
    $body = preg_replace('/@@[a-z0-9_]+@@/', '', $body);
    // الجدول بعرض نسبي ١٠٠٪ فيملا الصفحة تلقائياً بلا تصغير
    return '<div class="xls-sheet">' . $body . '</div>';
}

/**
 * محرّك النماذج الرسمية بالصورة: يعرض صورة النموذج الأصلي طبق الأصل كخلفية،
 * ويركّب فوقها قيم البيانات بأماكنها (من assets/forms/coords.json).
 * $key: مفتاح النموذج (= اسم صورة الخلفية). $data: قيم الحقول [k => value].
 */
function renderOfficialBg(string $key, array $data = [], array $extra = []): string {
    $dir = __DIR__ . '/../assets/forms/';
    static $meta = null, $coords = null;
    if ($meta === null)   $meta   = json_decode(@file_get_contents($dir . '_meta.json'), true) ?: [];
    if ($coords === null) $coords = json_decode(@file_get_contents($dir . 'coords.json'), true) ?: [];
    if (!isset($meta[$key])) return '';
    $m = $meta[$key];
    $landscape = ($m['w_px'] ?? 0) > ($m['h_px'] ?? 1);
    $wmm = $landscape ? 297 : 210;
    $img = BASE_URL . 'assets/forms/' . $m['png'];
    // الحقول الثابتة من coords.json + الحقول الديناميكية (صفوف الجداول) مع قيمها مباشرة
    $fields = [];
    foreach (($coords[$key] ?? []) as $f) {
        $v = $data[$f['k']] ?? '';
        if ($v === '' || $v === null) continue;
        $f['_v'] = $v; $fields[] = $f;
    }
    foreach ($extra as $f) {                       // [{x,y,val,s?,a?}]
        if (($f['val'] ?? '') === '' || $f['val'] === null) continue;
        $f['_v'] = $f['val']; $fields[] = $f;
    }
    ob_start(); ?>
    <div class="ofs<?= $landscape ? ' land' : '' ?>" id="ppExportArea" style="width:<?= $wmm ?>mm">
        <img class="ofs-bg" src="<?= e($img) ?>" alt="">
        <?php foreach ($fields as $f):
            $sz = $f['s'] ?? 3.0;                 // حجم الخط بالملم
            $al = $f['a'] ?? 'center';            // المحاذاة
            $sp = isset($f['sp']) ? 'letter-spacing:' . $f['sp'] . 'mm;' : '';
            $tx = $al === 'center' ? 'translate(-50%,-50%)' : ($al === 'right' ? 'translate(-100%,-50%)' : 'translate(0,-50%)');
        ?>
        <div class="ofs-ov" style="left:<?= $f['x'] ?>%;top:<?= $f['y'] ?>%;font-size:<?= $sz ?>mm;transform:<?= $tx ?>;<?= $sp ?>"><?= e($f['_v']) ?></div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * ستايل موحّد للنماذج الرسمية — A4، ترويسة، خطوط تعبئة، صناديق توقيع، جداول.
 * يُطبع مرة واحدة برأس الصفحة.
 */
function officialFormStyles(): string {
    return <<<'CSS'
<style>
/* ===== النماذج الرسمية — A4 ===== */
/* 🔠 القاعدة (بطلب المستخدم 2026-07-31): «12» = 12pt متل الوورد — نصّ كل التقارير
   والإفادات والنماذج 12pt كحدّ أدنى (العناوين الكبيرة تبقى أكبر)، والجدول الأعرض من
   ورقته يصغّر نفسه محسوباً (--pz) فلا يُقصّ عمود. المستثنى الوحيد: النماذج طبق الأصل
   (xlsf من إكسل + ofs فوق صورة رسمية) لأن محاذاتها على النموذج الرسمي أهم من حجم خطها. */
.official-doc{background:#fff;color:#111;max-width:210mm;margin:0 auto;padding:14mm 14mm;font-size:12pt;
  border:1px solid #e5e7eb;border-radius:10px;font-family:'Sakkal Majalla','Traditional Arabic','Amiri','Cairo','Inter',sans-serif;line-height:1.7;}
.official-doc.rtl,.official-doc[dir="rtl"]{direction:rtl;text-align:right;}

/* ترويسة المدرسة */
.letterhead{display:flex;align-items:center;gap:14px;border-bottom:2px solid var(--primary,#1e3a8a);
  padding-bottom:10px;margin-bottom:14px;}
.letterhead .lh-left{flex:0 0 auto;}
.letterhead .lh-logo{max-height:64px;max-width:90px;object-fit:contain;}
.letterhead .lh-center{flex:1 1 auto;text-align:center;}
.lh-name-ar{font-size:20px;font-weight:700;color:var(--primary-dark,#1e3a8a);}
.lh-name-fr{font-size:12pt;font-weight:600;color:#475569;letter-spacing:.3px;}
.lh-contact{font-size:12pt;color:#64748b;margin-top:3px;}
.letterhead .lh-right{flex:0 0 auto;font-size:12pt;color:#334155;text-align:left;min-width:120px;}
.lh-num{margin-bottom:2px;}

/* ترويسة حكومية */
.gov-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;
  border-bottom:1px solid #cbd5e1;padding-bottom:8px;}
.gov-header .gov-center{flex:1 1 auto;text-align:center;font-size:12pt;font-weight:600;line-height:1.6;}
.gov-header .gov-title{margin-top:6px;font-size:13pt;font-weight:700;color:var(--primary-dark,#1e3a8a);}
.gov-header .gov-code{flex:0 0 60px;font-weight:700;font-size:12pt;border:1.5px solid #111;border-radius:6px;
  padding:4px 8px;text-align:center;height:fit-content;}
.gov-header .gov-emblem{flex:0 0 60px;text-align:center;}
.gov-header .gov-emblem img{max-height:54px;}

/* عنوان النموذج */
.doc-title{text-align:center;font-size:18px;font-weight:700;margin:8px 0 16px;
  color:var(--primary-dark,#1e3a8a);}
.doc-subtitle{text-align:center;font-size:12pt;color:#475569;margin-top:-10px;margin-bottom:14px;}

/* خطوط التعبئة */
.fill{font-weight:600;color:#0f172a;display:inline-block;padding:0 4px;}
.fill.dotted{border-bottom:1.5px dotted #475569;min-width:120px;}
.kv{margin:5px 0;}
.kv .k{color:#475569;}

/* جداول النماذج */
/* على الشاشة: أي جدول واسع داخل بطاقة يُمرَّر أفقياً بدل ما تنقصّ أعمدته (الطباعة لها قياسها الخاص A4) */
.card-body:has(> .doc-table){overflow-x:auto;}
/* الجدول العربي (dir=rtl) يبدأ عرضه من اليمين — حتى يظهر العمود الأول (الاسم) فوراً بلا سحب */
.card-body:has(> .doc-table[dir="rtl"]){direction:rtl;}
.report-table-wrap{overflow-x:auto;}
/* شريط تمرير بارز وواضح ليعرف المستخدم أن في أعمدة إضافية */
.report-table-wrap::-webkit-scrollbar,.card-body::-webkit-scrollbar{height:12px;}
.report-table-wrap::-webkit-scrollbar-track,.card-body::-webkit-scrollbar-track{background:#e8edf3;border-radius:8px;}
.report-table-wrap::-webkit-scrollbar-thumb,.card-body::-webkit-scrollbar-thumb{background:var(--primary,#1e3a8a);border-radius:8px;border:2px solid #e8edf3;}
/* حجم الخط 12pt (متل «12» بالوورد) بطلب المستخدم — للتقارير والإفادات كلها */
.doc-table{width:100%;border-collapse:collapse;font-size:12pt;margin:10px 0;}
/* خط عربي واضح موحّد للمستندات والتقارير المطبوعة */
.doc-table,#ppExportArea,.payslip-card{font-family:'Sakkal Majalla','Traditional Arabic','Amiri','Cairo','Inter',sans-serif;}
.doc-table th{background:#1F4E5F;color:#fff;padding:7px 8px;text-align:center;
  font-weight:600;border:1px solid #1F4E5F;font-size:12pt;}
.doc-table td{border:1px solid #94a3b8;padding:6px 8px;text-align:center;}
.doc-table tfoot td,.doc-table .total-row td{background:#eef1f4;font-weight:700;}
.doc-table .subtotal-row td{background:#f1f5f9;font-weight:700;}
.doc-table .num{text-align:left;font-variant-numeric:tabular-nums;}
.doc-table tbody tr:nth-child(even){background:#f8fafc;}
.money-usd{display:block;font-size:12pt;color:#047857;font-weight:600;line-height:1.2;}
.money-usd-inline{color:#047857;font-weight:600;white-space:nowrap;}

/* شبكة معلومات — كل خانة على سطر مسطّر متل النموذج الأصلي */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px 30px;margin:12px 0;font-size:12pt;}
.info-grid > div{display:flex;align-items:flex-end;gap:6px;border-bottom:1px dotted #475569;
  padding-bottom:3px;min-height:21px;line-height:1.5;}
.info-grid .k{white-space:nowrap;color:#334155;}
.info-grid > div > .fill{flex:1;font-weight:700;color:#0a3d91;border-bottom:none;}
.info-grid .full{grid-column:1 / -1;}

/* سطر النموذج الرسمي: تسمية + قيمة على خط منقّط يملأ السطر (متل النماذج الرسمية) */
.fline{display:flex;align-items:flex-end;gap:6px;margin:8px 0;font-size:12pt;line-height:1.7;flex-wrap:wrap;}
.fline .lbl{white-space:nowrap;color:#111;}
.fline .val{flex:1 1 60px;border-bottom:1px dotted #475569;min-height:18px;font-weight:700;
  color:#0a3d91;padding:0 5px;text-align:center;}
.fline .val.g{flex:0 1 auto;min-width:80px;}
.fline .val.lg{flex:2 1 140px;}
.opt{display:inline-flex;align-items:center;gap:3px;margin:0 8px 0 0;white-space:nowrap;}
.opt .n{display:inline-block;min-width:20px;height:20px;border:1.2px solid #333;text-align:center;
  line-height:19px;font-size:12pt;font-weight:700;}
.opt.on .n{background:#0a3d91;color:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.doc-p{font-size:12pt;line-height:1.8;margin:8px 0;}

/* صناديق التوقيع */
.sign-row{display:flex;justify-content:space-between;gap:30px;margin-top:30px;}
.sign-box{flex:1;text-align:center;font-size:12pt;}
.sign-box .sign-label{margin-bottom:36px;color:#334155;}
.sign-box .sign-place{margin-bottom:10px;color:#64748b;}
.sign-box .sign-line{border-top:1px solid #475569;margin:0 18px;}

.doc-note{font-size:12pt;color:#64748b;margin-top:14px;border-top:1px dashed #cbd5e1;padding-top:6px;}
.doc-section{font-weight:700;color:var(--primary-dark,#1e3a8a);margin:14px 0 6px;
  border-right:3px solid var(--gold,#d4af37);padding-right:8px;}
.official-doc:not(.rtl) .doc-section{border-right:none;border-left:3px solid var(--gold,#d4af37);padding-right:0;padding-left:8px;}

/* الطباعة — A4 مرتّبة مع ترقيم صفحات صحيح */
@media print{
  @page{size:A4 portrait;margin:10mm;}
  /* تقرير عريض → A4 أفقي (يُضاف .land-report للحاوية) */
  .land-report{page:landscapePage;}
  body{background:#fff;}
  .official-doc{border:none !important;border-radius:0;padding:0;max-width:100%;box-shadow:none;page-break-inside:auto;}
  .doc-table th{background:#1F4E5F !important;color:#fff !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .doc-table tfoot td,.doc-table .total-row td{background:#e2e8f0 !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .doc-table .subtotal-row td{background:#f1f5f9 !important;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;}
  /* تكرار رأس الجدول وذيله على كل صفحة عند تعدّد الصفحات */
  .doc-table{page-break-inside:auto;}
  .doc-table thead{display:table-header-group;}
  .doc-table tfoot{display:table-footer-group;}
  .doc-table tr{page-break-inside:avoid;break-inside:avoid;}
  .doc-table th,.doc-table td{border:1px solid #555 !important;}
  /* الترويسة وعنوان التقرير لا ينفصلان عن بداية الجدول */
  .letterhead,.doc-title,.gov-header,.cnss-head,.mof-head{page-break-after:avoid;border-color:#000;}
  .page-break{page-break-after:always;}
  .mof-form,.cnss-form{box-shadow:none;}
  .sign-row{page-break-inside:avoid;}
}
@page landscapePage{size:A4 landscape;margin:8mm;}

/* ===== نموذج وزارة المالية (إطار أخضر + صندوق رمز) ===== */
.mof-form{border:3px solid #2f9e44;border-radius:5px;padding:0;overflow:hidden;}
.mof-head{display:flex;align-items:stretch;border-bottom:3px solid #2f9e44;}
.mof-gov{padding:8px 12px;font-size:12pt;font-weight:700;line-height:1.7;text-align:right;min-width:185px;color:#111;}
.mof-titles{flex:1;display:flex;align-items:center;justify-content:center;text-align:center;padding:8px;}
.mof-title{font-size:17px;font-weight:800;color:#111;line-height:1.4;}
.mof-code{min-width:56px;background:#2f9e44;color:#fff;display:flex;align-items:center;
  justify-content:center;font-size:21px;font-weight:800;letter-spacing:2px;}
.mof-body{padding:12px 16px;}
/* جدول الرموز (ر6/ر5/ر10) */
.code-table{width:100%;border-collapse:collapse;font-size:12pt;margin:6px 0;}
.code-table th{background:#e9ecef;border:1px solid #adb5bd;padding:5px 6px;font-size:12pt;text-align:center;}
.code-table td{border:1px solid #adb5bd;padding:4px 8px;}
.code-table td.lbl{text-align:right;}
.code-table td.num{text-align:left;font-variant-numeric:tabular-nums;min-width:90px;}
.code-cell{background:#212529;color:#fff;font-weight:700;text-align:center;width:34px;
  font-size:12pt;letter-spacing:1px;}
.code-table tr.gray td{background:#dee2e6;}
.code-table tr.sum td{background:#ced4da;font-weight:800;}
/* مربعات أرقام التسجيل */
.digit-boxes{display:inline-flex;gap:0;vertical-align:middle;}
.digit-boxes span{display:inline-block;min-width:22px;height:24px;border:1px solid #495057;
  border-right:none;text-align:center;font-size:12pt;line-height:23px;}
.digit-boxes span:last-child{border-right:1px solid #495057;}
.checkbox-opt{display:inline-flex;align-items:center;gap:4px;margin-left:14px;}
.checkbox-opt .bx{display:inline-block;width:20px;height:20px;border:1.3px solid #333;text-align:center;
  line-height:19px;font-weight:700;font-size:12pt;}

/* ===== نموذج الضمان الاجتماعي (أزرق) ===== */
.cnss-form{border:1px solid #1e3a8a;}
.cnss-head{color:#1e40af;font-weight:800;font-size:12pt;line-height:1.5;text-align:right;margin-bottom:4px;}
.cnss-head .sub{font-size:12pt;font-weight:600;color:#1e3a8a;}
.cnss-title{text-align:center;font-size:19px;font-weight:800;color:#1e40af;margin:12px 0 16px;}
.cnss-title u{text-underline-offset:5px;}
.cnss-form .doc-section{color:#1e40af;border-color:#1e40af;}
.cnss-form .sign-box .sign-line,.mof-form .sign-box .sign-line{border-top-color:#333;}

/* ===== محرّك الصورة الأصلية + بيانات فوقها ===== */
.ofs{position:relative;margin:0 auto;max-width:100%;background:#fff;
  box-shadow:0 1px 8px rgba(0,0,0,.12);}
.ofs-bg{width:100%;display:block;}
.ofs-ov{position:absolute;white-space:nowrap;text-align:center;line-height:1;
  color:#0a3d91;font-weight:600;font-family:'Cairo','Inter',sans-serif;}
@media screen and (max-width:820px){ .ofs{ width:100% !important; } }
@media print{
  /* وضع الصورة معطّل؛ لا نعرّف @page هنا حتى لا يطغى على A4 العمودي الافتراضي */
  .ofs{box-shadow:none;width:210mm !important;}
  .ofs.land{width:297mm !important;}
  .ofs-ov{color:#0a3d91;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
/* جدول نموذج مولّد من Excel (نفس الفورمة والأسماء) — عرض نسبي يملأ الصفحة */
.xls-sheet{direction:rtl;margin:0 auto;width:100%;overflow-x:auto;}
table.xlsf{border-collapse:collapse;table-layout:fixed;background:#fff;width:100%;
  font-family:'Cairo','Inter',sans-serif;color:#111;font-size:9px;}
table.xlsf td{overflow:hidden;padding:0 2px;line-height:1.15;white-space:normal;}
/* 🔵 قيم البرنامج (المتغيّرة) بخط أكبر وأعرض لتكون واضحة — النص الثابت بالنموذج يبقى بحجمه المصمّم
   (فلا يتراكب). تظهر في الأعمدة الواسعة (الأجور/الاشتراكات/المجاميع) فلا تطفح. */
table.xlsf .xv{font-size:13px;font-weight:800;white-space:nowrap;color:#0a2240;}
@media print{
  /* جداول الإكسل العريضة → صفحة A4 أفقية مسمّاة (لا @page عام حتى لا يقلب باقي النماذج) */
  .xls-sheet{overflow:visible;page:landscapePage;}
  table.xlsf{font-size:9px;}
  table.xlsf .xv{font-size:13px;font-weight:800;}
  @page landscapePage{size:A4 landscape;margin:6mm;}
}
/* الطباعة: تصغير محسوب خاص بها (--pz يحسبه السكربت أدناه = عرض الورقة ÷ عرض الجدول الطبيعي)
   فلا يُقصّ أي عمود على الورق مهما اتّسع الجدول؛ الجدول الذي يسع ورقته يبقى بحجمه (--pz=1) */
@media print{ .doc-table{zoom:var(--pz,1) !important;} }
/* عرض الورقة المستهدف بالبكسل (A4 ناقص الهوامش): أفقي للتقارير العريضة، عمودي لسواها */
.doc-table{--pz-target:745;}
.land-report .doc-table,.xls-sheet .doc-table{--pz-target:1075;}
/* وضع قياس خاطف: نفس شروط الطباعة (عرض طبيعي + خط الطباعة) لقياس العرض الحقيقي قبل حساب --pz */
.doc-table.pz-measure{width:max-content !important;table-layout:auto !important;}
.doc-table.cols-many.pz-measure{font-size:12pt !important;}
.doc-table.cols-many.pz-measure th,.doc-table.cols-many.pz-measure td{font-size:12pt !important;padding:2px 3px !important;}
/* جدول بأعمدة كثيرة (١٤+): نفس حجم 12pt (بطلب المستخدم) وحشوة مضغوطة،
   والتصغير المحسوب --pz يضمن دخول كل الأعمدة بالورقة بلا قصّ */
@media print{
  .doc-table.cols-many,.doc-table.cols-many th,.doc-table.cols-many td{font-size:12pt !important;}
  .doc-table.cols-many th,.doc-table.cols-many td{padding:2px 3px !important;}
  /* توزيع الأعمدة حسب المحتوى (لا بالتساوي) → العمود المالي يأخذ عرضه فلا ينقسم الرقم أبداً */
  .doc-table.cols-many{table-layout:auto !important;}
}
</style>
<script>
// 🔍 ملاءمة تلقائية لكل جداول التقارير (doc-table): الجدول الواسع يصغّر نفسه ليظهر
// بكل أعمدته على الشاشة مرة واحدة (متل الطباعة) بلا سحب. تعمل في مركز التقارير
// والنماذج الرسمية معاً. إن كان أوسع من الضعف نكتفي بـ0.5 ويبقى تمرير للباقي.
(function () {
    function fitDocTables() {
        var tables = document.querySelectorAll('table.doc-table');
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i], w = t.parentElement;
            if (!w) continue;
            w.style.overflowX = 'auto';                       // ضمان التمرير مهما كانت الحاوية
            if (t.getAttribute('dir') === 'rtl') w.style.direction = 'rtl'; // البداية من اليمين (الاسم أولاً)
            var row = t.querySelector('tr');                  // أعمدة كثيرة → خط أصغر بالطباعة
            if (row && row.children.length >= 14) t.classList.add('cols-many');
            t.style.zoom = '';
            // تصغير الطباعة (--pz): عرض الورقة المستهدف ÷ عرض الجدول الطبيعي — يضمن أن
            // كل الأعمدة تدخل بالورقة مهما اتّسع الجدول (يقرأه CSS الطباعة أعلاه)
            var target = parseFloat(getComputedStyle(t).getPropertyValue('--pz-target')) || 745;
            // قياس حقيقي: نفعّل شروط الطباعة لحظياً (خط/حشوة/عرض طبيعي) ونقيس عرض الجدول الفعلي
            t.classList.add('pz-measure');
            var natW = t.getBoundingClientRect().width || t.scrollWidth;
            t.classList.remove('pz-measure');
            var pz = target / natW;
            t.style.setProperty('--pz', pz < 1 ? Math.max(pz, 0.4).toFixed(3) : 1);
            var z = w.clientWidth / t.scrollWidth;
            if (z < 1) t.style.zoom = Math.max(z, 0.5);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fitDocTables);
    else fitDocTables();
    window.addEventListener('resize', fitDocTables);
    window.addEventListener('load', fitDocTables); // بعد تحميل الصور/الخطوط (تغيّر عرض الأعمدة)
})();
</script>
CSS;
}
