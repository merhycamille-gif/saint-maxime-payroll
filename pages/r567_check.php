<?php
/**
 * 🔍 فحص ملف الوزارة R567.xml قبل إرساله
 * ==========================================================================
 * «بعد الجنريت ما بقدر اعرف الملف اللي طلع صح او غلط — كيف بعرف» (2026-08-24):
 * المستخدم يرفع الـXML الذي أنتجه زرّ الوزارة (Generate XML) فنفكّ تشفيره
 * (DES-CBC بمفتاح الماكرو نفسه) ونقرأ ما سيصل الوزارة فعلياً، ثم:
 *   ١) فحوص بنيوية  ٢) تماسك داخلي (مجموع صفوف ر6 = سطور ر5 داخل الملف)
 *   ٣) مطابقة أرقام البرنامج (المصدر الموحّد mofYearEmpData) بالمليم
 *   ٤) أخطاء قاتلة يعرفها ملفنا: أكواد مناطق = 0، رقم مالية فارغ، موظف ساقط
 *      (ماكرو الوزارة يقف عند أول رقم مالية فارغ فيسقط هو ومن بعده)
 * 🔴 قراءة فقط: لا يعدّل الملف ولا الداتا — تشخيص بحت قبل الإرسال.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireCsrf();
$db = getDB();

$pageTitle = 'فحص ملف الوزارة R567 / Vérification du fichier ministère';
$currentPage = 'official_forms';

// مفتاح التشفير وIV مأخوذان من ماكرو الوزارة نفسه بالقالب الرسمي (ConvertXML.bas)
const R567_KEY = '6E79A445';
const R567_IV_HEX = '1314531830a13d1f';

/** يفكّ تشفير ملف الوزارة ويعيد نصّ الـXML أو '' */
function r567XmlDecrypt(string $raw): string {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', trim($raw));
    if ($raw === '') return '';
    // ملف الوزارة = Base64 لناتج DES-CBC؛ وإن كان غير مشفّر (XML صريح) نقبله كما هو
    if (stripos(ltrim($raw), '<?xml') === 0) return $raw;
    $bin = base64_decode(preg_replace('/\s+/', '', $raw), true);
    if ($bin === false || strlen($bin) < 16 || strlen($bin) % 8 !== 0) return '';
    if (!in_array('des-cbc', openssl_get_cipher_methods(), true)) return '';
    $out = openssl_decrypt($bin, 'des-cbc', R567_KEY, OPENSSL_RAW_DATA, hex2bin(R567_IV_HEX));
    if ($out === false) return '';
    return stripos(ltrim($out), '<?xml') === 0 ? $out : '';
}

$res = null; $fatal = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xml'])) {
    $f = $_FILES['xml'];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $fatal = 'ما وصل الملف (خطأ رفع رقم ' . (int)($f['error'] ?? -1) . ') — جرّب مرّة تانية.';
    } elseif ((int)$f['size'] > 25 * 1024 * 1024) {
        $fatal = 'الملف أكبر من 25 ميغا — مش ملف R567.xml.';
    } else {
        $xmlTxt = r567XmlDecrypt((string)@file_get_contents($f['tmp_name']));
        if ($xmlTxt === '') {
            $fatal = !in_array('des-cbc', openssl_get_cipher_methods(), true)
                ? 'السيرفر ما بيدعم فكّ تشفير ملفات الوزارة (des-cbc غير متوفر) — جرّب من الكمبيوتر المحلي.'
                : 'ما قدرت افتح الملف: تأكّد إنه ملف R567.xml الطالع من زرّ الوزارة (مش ملف الإكسل نفسه).';
        } else {
            libxml_use_internal_errors(true);
            $x = simplexml_load_string($xmlTxt);
            if (!$x) $fatal = 'الملف مفكوك بس محتواه مش XML سليم — أعد توليده من الإكسل.';
            else $res = r567Analyze($db, $x);
        }
    }
}

/** كل الفحوص — يعيد ['ok'=>bool,'checks'=>[[ok,عنوان,تفصيل]],'info'=>[]] */
function r567Analyze($db, SimpleXMLElement $x): array {
    $C = []; $add = function ($ok, $t, $d = '') use (&$C) { $C[] = ['ok' => (bool)$ok, 't' => $t, 'd' => $d]; };
    $money = fn($v) => number_format((float)$v, 0, '.', ',');
    $eq = fn($a, $b) => abs((float)$a - (float)$b) < 0.5;

    $as = $x->Assessment;
    $add($as && (string)$as->Form_No === 'R5', 'الملف تصريح ر5 سنوي سليم البنية',
        $as ? 'نموذج ' . (string)$as->Form_No . ' نسخة ' . (string)$as->Version_No : 'ما في قسم Assessment');
    if (!$as) return ['ok' => false, 'checks' => $C, 'info' => []];

    // ١) الهوية والفترة
    $tp = preg_replace('/\D/', '', (string)$as->Tax_Payer_No);
    $sch = currentSchool();
    $ourFin = preg_replace('/\D/', '', (string)($sch['finance_number'] ?? ''));
    $add($ourFin !== '' && $tp === $ourFin, 'رقم المكلّف بالملف = رقم المدرسة لدى المالية',
        'بالملف: ' . $tp . ($ourFin !== '' ? ' / بالبرنامج: ' . $ourFin : ' (المدرسة بلا رقم مالي)'));
    $from = (string)$as->TP_Start_Date; $to = (string)$as->TP_End_Date;
    $fy = (int)substr($from, 0, 4);
    $add(preg_match('/^\d{4}-01-01$/', $from) && $to === $fy . '-12-31',
        'الفترة سنة ميلادية كاملة (1/1 → 31/12)', $from . ' → ' . $to);

    // ٢) النماذج المرفقة
    $r6 = []; $r7 = 0; $r7fs = [];
    foreach ($x->xpath('//Attached_Form') ?: [] as $af) {
        if ((string)$af['FormNo'] === 'R6') $r6[] = $af;
        elseif ((string)$af['FormNo'] === 'R7') { $r7++; $r7fs[] = $af; }
    }
    $add(count($r6) > 0, 'ملف الموظفين (ر6) موجود', count($r6) . ' موظفاً');
    $add($r7 > 0, 'كشف التاركين (ر7) موجود', $r7 ? 'موجود' : 'مفقود — الماكرو ما ولّده');

    // خانات ر5 المالية والنصية بالملف
    $L5 = []; $g5h = [];
    foreach ($as->FC as $fc) $L5[(string)$fc['Int_Line_No']] = (float)$fc->Submitted_AMT;
    foreach ($as->FCG as $e) $g5h[(string)$e['Int_Line_No']] = trim((string)$e->Cell_Value);
    $g5 = fn($k) => $L5[$k] ?? 0.0;

    // أسماء المناطق من لوائح الوزارة نفسها (للعرض التفصيلي — الكود هو المُرسَل فعلياً)
    $geoJ = json_decode((string)@file_get_contents(__DIR__ . '/../assets/templates/mof_r567_geo.json'), true) ?: [];
    $govNm = array_flip($geoJ['govs'] ?? []);
    $cazaNm = []; foreach (($geoJ['cazas'] ?? []) as $c) $cazaNm[(int)$c['id']] = $c['name'];
    $townNm = []; foreach (($geoJ['towns'] ?? []) as $t) $townNm[(int)$t['id']] = $t['name'];

    // ٣) قراءة صفوف الموظفين + الأخطاء القاتلة
    // 🔴 خريطة أعمدة ورقة R6 بقالب الوزارة (الصف 15): G/H = نوع الأجر (1389، شهري=1)
    //    وI/J = «الوضع العائلي*» (1391) — الوضع العائلي بالخانة 1391 لا 1389.
    $S6 = []; $bad = ['geo' => [], 'fin' => [], 'mar' => []];
    $byFin = []; $emps = [];
    foreach ($r6 as $af) {
        $g = []; $fc = [];
        foreach ($af->FCG as $e) $g[(string)$e['Int_Line_No']] = trim((string)$e->Cell_Value);
        foreach ($af->FC as $e) $fc[(string)$e['Int_Line_No']] = (float)$e->Submitted_AMT;
        foreach ($fc as $k => $v) $S6[$k] = ($S6[$k] ?? 0) + $v;
        $nm = trim(($g['1384'] ?? '') . ' ' . ($g['1386'] ?? ''));
        if ($nm === '') $nm = '(بلا اسم)';
        $finN = preg_replace('/\D/', '', $g['1387'] ?? '');
        if ($finN === '' || $finN === '0') $bad['fin'][] = $nm;
        else $byFin[$finN] = ['name' => $nm, 'fc' => $fc];
        // أكواد المناطق: أي صفر = الوزارة ما لقت الاسم بلوائحها
        $gv = (int)($g['1015'] ?? 0); $cz = (int)($g['1020'] ?? 0); $tw = (int)($g['1025'] ?? 0);
        if (!$gv || !$cz || !$tw) {
            $miss = [];
            if (!$gv) $miss[] = 'المحافظة'; if (!$cz) $miss[] = 'القضاء'; if (!$tw) $miss[] = 'البلدة';
            $bad['geo'][] = $nm . ' (' . implode(' + ', $miss) . ')';
        }
        $ms = (int)($g['1391'] ?? 0);
        if ($ms < 1 || $ms > 4) $bad['mar'][] = $nm;
        $emps[] = [
            'seq' => (int)($g['1382'] ?? 0) ?: count($emps) + 1,
            'year' => $g['1001'] ?? '', 'org' => $g['1005'] ?? '', 'orgFin' => $g['1090'] ?? '',
            'tot' => (int)($g['1381'] ?? 0),
            'first' => $g['1384'] ?? '', 'father' => $g['1385'] ?? '', 'last' => $g['1386'] ?? '',
            'fin' => $finN === '0' ? '' : $finN, 'job' => $g['1388'] ?? '',
            'pay' => (int)($g['1389'] ?? 0), 'mar' => $ms,
            'kids' => (int)($g['1392'] ?? 0), 'kidsDed' => (int)($g['1393'] ?? 0), 'days' => (int)($g['1394'] ?? 0),
            'gov' => $gv, 'caza' => $cz, 'town' => $tw,
            'govN' => $govNm[$gv] ?? '', 'cazaN' => $cazaNm[$cz] ?? '', 'townN' => $townNm[$tw] ?? '',
            'quarter' => $g['1030'] ?? '', 'street' => $g['1035'] ?? '', 'bldg' => $g['1040'] ?? '',
            'floor' => $g['1045'] ?? '', 'phone' => $g['1065'] ?? '', 'cell' => $g['1070'] ?? '', 'email' => $g['1080'] ?? '',
            'fc' => $fc,
        ];
    }
    $s6 = fn($k) => $S6[$k] ?? 0.0;
    $add(!$bad['geo'], 'أكواد المناطق كلها انحسبت (ما في كود صفر)',
        $bad['geo'] ? count($bad['geo']) . ' موظفاً كودهم صفر: ' . implode('، ', array_slice($bad['geo'], 0, 8)) : 'كلها سليمة');
    $add(!$bad['fin'], 'كل موظف براس الملف معه رقم مالية',
        $bad['fin'] ? implode('، ', $bad['fin']) : 'كلها موجودة');
    $add(!$bad['mar'], 'الوضع العائلي محدَّد لكل موظف',
        $bad['mar'] ? implode('، ', $bad['mar']) : 'كلها محدَّدة');

    // ٤) تماسك داخلي: مجموع صفوف ر6 = سطور ر5 داخل الملف نفسه
    $pairs = [['66', '10', 'مجموع المبالغ المدفوعة'], ['15', '12', 'تعويضات النقل'],
              ['81', '16', 'تنزيلات أخرى'], ['80', '24', 'التنزيل العائلي'],
              ['84', '26', 'الرواتب الخاضعة للضريبة'], ['89', '28', 'الضريبة المتوجبة']];
    $bad2 = [];
    foreach ($pairs as [$a, $b, $n]) if (!$eq($s6($a), $g5($b))) $bad2[] = $n . ' (ر6: ' . $money($s6($a)) . ' ≠ ر5: ' . $money($g5($b)) . ')';
    $add(!$bad2, 'داخل الملف: مجموع صفوف الموظفين = سطور ر5 بالمليم',
        $bad2 ? implode(' · ', $bad2) : 'كل السطور متطابقة');

    // ٥) مطابقة أرقام البرنامج (المصدر الموحّد نفسه الذي يبني ر5/ر6/ر10)
    $yd = mofYearEmpData($db, $fy, '');
    $S = $yd['sum'];
    $ours = ['10' => $S['paid'], '12' => $S['trans'], '16' => $S['other'] + $S['fam'],
             '22' => $S['tb'], '24' => $S['fd'], '26' => $S['net'], '28' => $S['tax']];
    $names = ['10' => 'مجموع المبالغ المدفوعة', '12' => 'تعويضات النقل', '16' => 'تنزيلات أخرى',
              '22' => 'المبالغ الصافية', '24' => 'التنزيل العائلي', '26' => 'الخاضع للضريبة', '28' => 'الضريبة المتوجبة'];
    $bad3 = [];
    foreach ($ours as $k => $v) if (!$eq($g5($k), $v)) $bad3[] = $names[$k] . ' (الملف: ' . $money($g5($k)) . ' ≠ البرنامج: ' . $money($v) . ')';
    $add(!$bad3, 'أرقام الملف = أرقام البرنامج لسنة ' . $fy . ' بالمليم',
        $bad3 ? implode(' · ', $bad3) : 'كل السطور السبعة متطابقة');

    // ٦) ما في موظف ساقط (الماكرو يقف عند أول رقم مالية فارغ)
    $missing = []; $diff = [];
    foreach ($yd['rows'] as $r) {
        $finN = preg_replace('/\D/', '', (string)($r['e']['finance_ministry_number'] ?? ''));
        $nm = trim((($r['e']['first_name_ar'] ?? '') ?: ($r['e']['first_name_fr'] ?? '')) . ' ' . (($r['e']['last_name_ar'] ?? '') ?: ($r['e']['last_name_fr'] ?? '')));
        if ($finN === '' || !isset($byFin[$finN])) { $missing[] = $nm . ($finN === '' ? ' (بلا رقم مالية)' : ''); continue; }
        $f6 = $byFin[$finN]['fc'];
        if (!$eq($f6['66'] ?? 0, $r['d']['tot1']) || !$eq($f6['89'] ?? 0, $r['d']['tax'])) {
            $diff[] = $nm . ' (إجمالي بالملف ' . $money($f6['66'] ?? 0) . ' مقابل ' . $money($r['d']['tot1'])
                    . ' · ضريبة ' . $money($f6['89'] ?? 0) . ' مقابل ' . $money($r['d']['tax']) . ')';
        }
    }
    $add(!$missing, 'كل موظفي البرنامج موجودون بالملف (ما في موظف ساقط)',
        $missing ? count($missing) . ' موظفاً ناقصاً: ' . implode('، ', array_slice($missing, 0, 8)) : count($yd['rows']) . ' موظفاً');
    $add(!$diff, 'مبالغ كل موظف بالملف = مبالغه بالبرنامج',
        $diff ? count($diff) . ' مختلفاً: ' . implode(' · ', array_slice($diff, 0, 5)) : 'كلها متطابقة');

    // كشف التاركين (ر7): كل سطر «اسم ثلاثي|رقم مالية|رقم ضمان|تاريخ تعيين|تاريخ ترك»
    $leavers = []; $r7cnss = '';
    foreach ($r7fs as $af) {
        foreach ($af->FCG as $e) if ((string)$e['Int_Line_No'] === '1390') $r7cnss = trim((string)$e->Cell_Value);
        if (!isset($af->Table)) continue;
        foreach ($af->Table->FCG as $row) {
            $p = array_pad(explode('|', trim((string)$row->Cell_Value)), 5, '');
            if (trim(implode('', $p)) === '') continue;
            $leavers[] = ['name' => $p[0], 'fin' => $p[1], 'cnss' => $p[2], 'hired' => $p[3], 'left' => $p[4]];
        }
    }

    $ok = true;
    foreach ($C as $c) if (!$c['ok']) $ok = false;
    $detail = [
        'g5h' => $g5h, 'L5' => $L5, 'atbp' => (float)$as->AmountToBePaid,
        'govN' => $govNm[(int)($g5h['1015'] ?? 0)] ?? '', 'cazaN' => $cazaNm[(int)($g5h['1020'] ?? 0)] ?? '',
        'townN' => $townNm[(int)($g5h['1025'] ?? 0)] ?? '',
        'emps' => $emps, 'sums' => $S6, 'leavers' => $leavers, 'r7cnss' => $r7cnss, 'from' => $from, 'to' => $to,
    ];
    return ['ok' => $ok, 'checks' => $C, 'fy' => $fy, 'detail' => $detail, 'info' => [
        'السنة المالية' => $fy, 'رقم المكلّف' => $tp, 'المؤسسة' => (string)$as->UserID,
        'عدد الموظفين (ر6)' => count($r6), 'كشف التاركين (ر7)' => $r7 ? 'موجود' : 'مفقود',
        'تاريخ التصريح' => (string)$as->Declaration_Date,
        'الضريبة المتوجبة' => $money($g5('28')) . ' ل.ل.',
    ]];
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card no-print">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-file-shield"></i>
        Vérifier le fichier ministère / فحص ملف الوزارة قبل الإرسال</h3></div>
    <div class="card-body">
        <p style="font-size:12pt;margin-top:0">
            بعد ما تكبس زرّ الوزارة (<strong>Generate XML</strong>) بالإكسل وبيطلع ملف <code>R567.xml</code> —
            ارفعه هون قبل ما تبعتو، والبرنامج بيفتحو وبيقرا شو رح يوصل الوزارة فعلاً وبيقلّك صح أو غلط.
        </p>
        <form method="post" enctype="multipart/form-data" class="form-row cols-2" style="align-items:end">
            <?= csrfField() ?>
            <div class="form-group mb-0">
                <label class="form-label">ملف R567.xml</label>
                <input type="file" name="xml" accept=".xml,text/xml" required class="form-control">
            </div>
            <div class="form-group mb-0">
                <button class="btn btn-primary btn-lg w-100"><i class="fas fa-magnifying-glass-chart"></i> افحص الملف / Vérifier</button>
            </div>
        </form>
    </div>
</div>

<?php if ($fatal): ?>
    <div class="card"><div class="card-body" style="text-align:center;font-size:14pt;color:#b91c1c;font-weight:700">
        ⚠️ <?= e($fatal) ?>
    </div></div>
<?php elseif ($res): ?>
    <?php $bad = array_values(array_filter($res['checks'], fn($c) => !$c['ok'])); ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:22px;background:<?= $res['ok'] ? '#dcfce7' : '#fee2e2' ?>">
            <div style="font-size:22pt;font-weight:800;color:<?= $res['ok'] ? '#166534' : '#b91c1c' ?>">
                <?= $res['ok'] ? '✅ الملف سليم — فيك تبعتو للوزارة' : '⛔ لا تبعتو — في ' . count($bad) . ' مشكلة' ?>
            </div>
            <?php if (!$res['ok']): ?>
                <div style="font-size:13pt;color:#7f1d1d;margin-top:6px">صحّح المشكلة بالبرنامج، أعد تنزيل ملف الإكسل، اكبس زرّ الوزارة من جديد، وافحصه مرّة تانية.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">ملخّص الملف</h3></div>
        <div class="card-body"><table class="doc-table" dir="rtl" style="max-width:640px;margin:0 auto">
            <?php foreach ($res['info'] as $k => $v): ?>
                <tr><td style="text-align:right;font-weight:700;background:#f8fafc"><?= e($k) ?></td><td style="text-align:right"><?= e((string)$v) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">الفحوص (<?= count($res['checks']) ?>)</h3></div>
        <div class="card-body"><table class="doc-table" dir="rtl">
            <thead><tr><th style="width:60px">النتيجة</th><th>الفحص</th><th>التفصيل</th></tr></thead>
            <tbody>
            <?php foreach ($res['checks'] as $c): ?>
                <tr style="background:<?= $c['ok'] ? 'transparent' : '#fef2f2' ?>">
                    <td style="text-align:center;font-size:15pt"><?= $c['ok'] ? '✅' : '❌' ?></td>
                    <td style="text-align:right;font-weight:<?= $c['ok'] ? '400' : '700' ?>"><?= e($c['t']) ?></td>
                    <td style="text-align:right;color:<?= $c['ok'] ? '#475569' : '#b91c1c' ?>"><?= e($c['d']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <?php if (!empty($res['detail'])):
        /* 🖥️ عرض مضمون الملف بالتفصيل متل ما بيبين على موقع المالية بعد الإرسال
         * («لازم يبين بالتفصيل ر6 لكل موظف ور5 ور7 متل ما بيبينو بموقع المالية» — 2026-08-25):
         * ر5 بسطوره الرسمية (100..190) + ر6 مستقل كامل لكل موظف (كبسة = نموذجه لحاله) + ر7 التاركون.
         * التسميات حرفياً من ورقتي R5/R6 بقالب الوزارة الرسمي. */
        $D = $res['detail']; $L5 = $D['L5']; $g5h = $D['g5h'];
        $money = fn($v) => number_format((float)$v, 0, '.', ',');
        $v5 = fn($k) => number_format((float)($L5[$k] ?? 0), 0, '.', ',');
        $marL = [1 => 'أعزب', 2 => 'متزوج', 3 => 'أرمل', 4 => 'مطلق'];
        $payL = [1 => 'شهري', 2 => 'يومي', 3 => 'بالساعة'];
        // مدة العمل بالملف مخزّنة يوماً فلكياً (Julian Day — هيك بيبعتها الماكرو)
        $jdDate = fn($j) => ((float)$j > 2000000) ? gmdate('d/m/Y', (int)round(((float)$j - 2440587.5) * 86400)) : '';
        // أرقام السطور بمربعات سود بالأرقام العربية — متل نموذج ر6 الأصلي بالضبط
        $lnBox = fn($n) => '<span style="background:#111;color:#fff;padding:1px 8px;border-radius:3px;font-weight:700;display:inline-block;min-width:36px;text-align:center">'
            . strtr((string)$n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) . '</span>';
        // سطور ر5 الرسمية: [رقم السطر بالنموذج، التسمية، خانة عمود ١ (مجلس الإدارة)، خانة عمود ٢ (المستخدمون)]
        $r5Rows = [
            ['100', 'الرواتب وملحقاتها', '5', '6'],
            ['110', 'المنافع النقدية والعينية', '7', '8'],
            ['120', 'مجموع المبالغ المدفوعة', '9', '10'],
            ['130', 'ينزل: تعويضات نقل وانتقال', '11', '12'],
            ['140', 'تعويضات تمثيل', '13', '14'],
            ['150', 'تنزيلات أخرى', '15', '16'],
            ['160', 'المبالغ الصافية', '21', '22'],
            ['170', 'التنزيل العائلي', '23', '24'],
            ['180', 'الرواتب والأجور الخاضعة للضريبة', '25', '26'],
            ['190', 'الضريبة المتوجبة', '27', '28'],
        ];
        // بنود ر6 الرسمية: [رقم السطر، التسمية، إجمالي (١)، غير خاضع (٢)، خاضع (٣)] — null = خانة مسدودة بالنموذج
        $r6Rows = [
            ['100', 'الراتب الأساسي/الأجور اليومية', '1', '2', '3'],
            ['110', 'بدل تمثيل', '4', '5', '6'],
            ['120', 'مكافآت وعمولات وساعات إضافية', '7', null, '8'],
            ['130', 'تعويض عائلي عن الزوجة', '9', '10', '11'],
            ['140', 'تعويض عائلي عن الأولاد', '12', '13', '14'],
            ['150', 'تعويضات نقل وانتقال', '15', '16', '17'],
            ['160', 'بدل سيارة', '18', null, '19'],
            ['170', 'بدل سكن', '20', null, '21'],
            ['180', 'بدل طعام', '22', '23', '24'],
            ['190', 'بدل ملبس', '25', '26', '27'],
            ['200', 'تعويض صندوق', '28', '29', '30'],
            ['210', 'تأمينات صحية على أنواعها', '31', null, '32'],
            ['220', 'منح تعليم', '33', '34', '35'],
            ['230', 'منح زواج', '36', '37', '38'],
            ['240', 'منح ولادة', '39', '40', '41'],
            ['250', 'مساعدات مرضية', '42', null, '43'],
            ['260', 'مساعدات وفاة', '44', '45', '46'],
            ['300', 'منح وتقديمات أخرى', '47', '48', '49'],
        ];
        $S6 = $D['sums']; $s6 = fn($k) => number_format((float)($S6[$k] ?? 0), 0, '.', ',');
        $thS = 'style="background:#1F4E5F;color:#fff;text-align:center"';
        $tdL = 'style="text-align:right"'; $tdN = 'style="text-align:left;direction:ltr;font-variant-numeric:tabular-nums"';
    ?>
    <div class="card">
        <div class="card-body" style="text-align:center;background:#f0f9ff;font-size:13pt;font-weight:700;color:#0c4a6e">
            📋 هيدا شو رح يشوفو موظف الوزارة بالضبط — نفس تفاصيل موقع المالية بعد الإرسال
        </div>
    </div>

    <!-- ر5: التصريح السنوي -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-file-invoice-dollar"></i>
            Déclaration annuelle R5 / التصريح السنوي ر5</h3></div>
        <div class="card-body">
            <table class="doc-table" dir="rtl" style="max-width:860px;margin:0 auto 14px">
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc;width:230px">إسم الشركة/المؤسسة</td>
                    <td <?= $tdL ?>><?= e($g5h['1005'] ?? '') ?> (<?= e($g5h['1010'] ?? '') ?>)</td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">رقم التسجيل (لدى وزارة المالية)</td>
                    <td <?= $tdL ?>><?= e($g5h['1090'] ?? '') ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">السنة المالية</td>
                    <td <?= $tdL ?>>من <?= e($D['from']) ?> إلى <?= e($D['to']) ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">عنوان المركز الرئيسي</td>
                    <td <?= $tdL ?>>محافظة <?= e($D['govN'] ?: ('كود ' . (int)($g5h['1015'] ?? 0))) ?> —
                        قضاء <?= e($D['cazaN'] ?: ('كود ' . (int)($g5h['1020'] ?? 0))) ?> —
                        بلدة <?= e($D['townN'] ?: ('كود ' . (int)($g5h['1025'] ?? 0))) ?>
                        <?= ($g5h['1035'] ?? '') !== '' && ($g5h['1035'] ?? '') !== '0' ? '— الحي ' . e($g5h['1035']) : '' ?>
                        <?= ($g5h['1060'] ?? '') !== '' && ($g5h['1060'] ?? '') !== '0' ? '— العقار ' . e($g5h['1060']) : '' ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">الهاتف / البريد الإلكتروني</td>
                    <td <?= $tdL ?>><?= e($g5h['1065'] ?? '') ?> / <?= e($g5h['1080'] ?? '') ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">الشخص المكلف بتبليغ البريد</td>
                    <td <?= $tdL ?>><?= e($g5h['1081'] ?? '') ?> — رقم التسجيل <?= e($g5h['1082'] ?? '') ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">الشخص الذي ساهم بتحضير التصريح</td>
                    <td <?= $tdL ?>><?= e($g5h['1200'] ?? '') ?> — رقم التسجيل <?= e($g5h['1210'] ?? '') ?>
                        (<?= e($g5h['1270'] ?? '') ?> — <?= e($g5h['1280'] ?? '') ?>)</td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">عدد رئيس وأعضاء مجلس الإدارة (70)</td>
                    <td <?= $tdN ?>><?= $v5('2') ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">عدد المستخدمين والأجراء للفترة المصرح عنها (80)</td>
                    <td <?= $tdN ?>><?= $v5('3') ?></td></tr>
                <tr><td <?= $tdL ?> style="font-weight:700;background:#f8fafc">عدد العمال الذين يتقاضون أجوراً مقطوعة (90)</td>
                    <td <?= $tdN ?>><?= $v5('4') ?></td></tr>
            </table>
            <table class="doc-table" dir="rtl" style="max-width:860px;margin:0 auto">
                <thead><tr>
                    <th style="background:#1F4E5F;color:#fff;text-align:right">ضريبة الباب الثاني</th>
                    <th <?= $thS ?>>رئيس وأعضاء مجلس الإدارة (١)</th>
                    <th <?= $thS ?>>المستخدمون والأجراء (٢)</th>
                </tr></thead>
                <tbody>
                <?php foreach ($r5Rows as [$ln, $lb, $c1, $c2]): $bold = in_array($ln, ['120', '160', '180', '190'], true); ?>
                    <tr style="<?= $bold ? 'font-weight:700;background:#f8fafc' : '' ?>">
                        <td <?= $tdL ?>><?= e($lb) ?> <span style="color:#94a3b8">(<?= $ln ?>)</span></td>
                        <td <?= $tdN ?>><?= $v5($c1) ?></td>
                        <td <?= $tdN ?>><?= $v5($c2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:800;background:#fef9c3">
                    <td <?= $tdL ?>>المبلغ الواجب دفعه</td>
                    <td></td>
                    <td <?= $tdN ?>><?= $money($D['atbp']) ?> ل.ل.</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ر6: نموذج مستقل كامل لكل موظف -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <h3 class="card-title"><i class="fas fa-users"></i>
                Formulaires R6 / النماذج المرفقة — ر6 مستقل لكل موظف (<?= count($D['emps']) ?>)</h3>
            <span>
                <button type="button" class="btn btn-primary" style="padding:4px 12px;font-size:10pt"
                        onclick="document.querySelectorAll('details.r6emp').forEach(d=>d.open=true)">افتح الكل</button>
                <button type="button" class="btn btn-primary" style="padding:4px 12px;font-size:10pt"
                        onclick="document.querySelectorAll('details.r6emp').forEach(d=>d.open=false)">سكّر الكل</button>
            </span>
        </div>
        <div class="card-body">
            <p style="margin-top:0;color:#475569">اكبس على أي موظف بيفتحلك نموذج ر6 الكامل تبعو لحالو — متل ما بيبين على موقع المالية.</p>
            <?php foreach ($D['emps'] as $emp):
                $fc = $emp['fc']; $fv = fn($k) => number_format((float)($fc[$k] ?? 0), 0, '.', ',');
                $geoBad = !$emp['gov'] || !$emp['caza'] || !$emp['town'];
                $prob = $geoBad || $emp['fin'] === '' || $emp['mar'] < 1 || $emp['mar'] > 4;
                $fullNm = trim($emp['first'] . ' ' . $emp['father'] . ' ' . $emp['last']);
            ?>
            <details class="r6emp" style="border:1px solid <?= $prob ? '#fca5a5' : '#e2e8f0' ?>;border-radius:10px;margin-bottom:8px;background:<?= $prob ? '#fef2f2' : '#fff' ?>">
                <summary style="cursor:pointer;padding:10px 14px;display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;font-size:11.5pt">
                    <span style="background:#1F4E5F;color:#fff;border-radius:6px;padding:2px 10px;font-weight:700">ر6 — <?= (int)$emp['seq'] ?></span>
                    <b><?= e($fullNm ?: '(بلا اسم)') ?></b>
                    <span>رقم المالية: <?= $emp['fin'] !== '' ? e($emp['fin']) : '<b style="color:#b91c1c">⚠️ ناقص</b>' ?></span>
                    <span style="color:#475569">الإجمالي: <?= $fv('66') ?></span>
                    <span style="color:#475569">الضريبة: <?= $fv('89') ?></span>
                    <?= $prob ? '<b style="color:#b91c1c">⚠️ فيه مشكلة</b>' : '' ?>
                </summary>
                <div style="padding:4px 14px 14px">
                    <!-- ترويسة النموذج الرسمي متل موقع الوزارة -->
                    <div style="background:#3aaa35;color:#fff;border:2px solid #111;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:0">
                        <div style="font-size:14pt;font-weight:800;text-align:center;flex:1">كشف سنوي إفرادي بإجمالي إيرادات المستخدم/الأجير</div>
                        <div style="font-size:16pt;font-weight:800">ر6</div>
                    </div>
                    <table class="doc-table" dir="rtl" style="margin-bottom:10px">
                        <tr>
                            <td <?= $tdL ?> style="background:#f8fafc;width:170px">إسم الشركة/المؤسسة</td>
                            <td <?= $tdL ?>><?= e($emp['org']) ?></td>
                            <td <?= $tdL ?> style="background:#f8fafc;width:130px">عن أعمال سنة</td>
                            <td <?= $tdL ?>><?= e($emp['year']) ?></td>
                        </tr>
                        <tr>
                            <td <?= $tdL ?> style="background:#f8fafc">رقم التسجيل (لدى وزارة المالية)</td>
                            <td <?= $tdL ?>><?= e($emp['orgFin']) ?></td>
                            <td <?= $tdL ?> style="background:#f8fafc">عدد الأجراء/المستخدمين</td>
                            <td <?= $tdL ?>><?= (int)$emp['seq'] ?> من <?= (int)($emp['tot'] ?: count($D['emps'])) ?></td>
                        </tr>
                    </table>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px">
                        <table class="doc-table" dir="rtl" style="flex:1;min-width:300px">
                            <tr><td colspan="2" style="background:#1F4E5F;color:#fff;text-align:right;font-weight:700">الأجير/المستخدم</td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc;width:200px">إسم الأجير/المستخدم</td><td <?= $tdL ?>><?= e($emp['first']) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">إسم الأب</td><td <?= $tdL ?>><?= e($emp['father']) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">الشهرة</td><td <?= $tdL ?>><?= e($emp['last']) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">رقم التسجيل الشخصي (لدى وزارة المالية)</td>
                                <td <?= $tdL ?>><?= $emp['fin'] !== '' ? e($emp['fin']) : '<b style="color:#b91c1c">⚠️ ناقص — الماكرو بيوقف عندو وبيسقط اللي بعدو</b>' ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">نوع العمل</td><td <?= $tdL ?>><?= e($emp['job']) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">نوع الأجر</td><td <?= $tdL ?>><?= e($payL[$emp['pay']] ?? ('كود ' . $emp['pay'])) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">الوضع العائلي</td>
                                <td <?= $tdL ?>><?= isset($marL[$emp['mar']]) ? e($marL[$emp['mar']]) : '<b style="color:#b91c1c">⚠️ غير محدَّد</b>' ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">عدد الأولاد</td><td <?= $tdL ?>><?= (int)$emp['kids'] ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">عدد الأشخاص الذين يستفيدون من التنزيل العائلي*</td>
                                <td <?= $tdL ?>><?= (int)$emp['kidsDed'] ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">مدة العمل</td>
                                <td <?= $tdL ?>><?= $jdDate($fc['86'] ?? 0) !== '' ? 'من ' . $jdDate($fc['86'] ?? 0) . ' إلى ' . $jdDate($fc['87'] ?? 0) : '—' ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">عدد أيام العمل للمستفيد من التنزيل اليومي</td><td <?= $tdL ?>><?= $emp['days'] ?: '0' ?></td></tr>
                        </table>
                        <table class="doc-table" dir="rtl" style="flex:1;min-width:300px">
                            <tr><td colspan="2" style="background:#1F4E5F;color:#fff;text-align:right;font-weight:700">عنوان المستخدم/الأجير</td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc;width:130px">محافظة</td>
                                <td <?= $tdL ?>><?= $emp['gov'] ? e($emp['govN'] ?: ('كود ' . $emp['gov'])) : '<b style="color:#b91c1c">⚠️ كود صفر — الوزارة ما لقت الاسم</b>' ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">قضاء</td>
                                <td <?= $tdL ?>><?= $emp['caza'] ? e($emp['cazaN'] ?: ('كود ' . $emp['caza'])) : '<b style="color:#b91c1c">⚠️ كود صفر — الوزارة ما لقت الاسم</b>' ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">منطقة - بلدة</td>
                                <td <?= $tdL ?>><?= $emp['town'] ? e($emp['townN'] ?: ('كود ' . $emp['town'])) : '<b style="color:#b91c1c">⚠️ كود صفر — الوزارة ما لقت الاسم</b>' ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">الحي</td><td <?= $tdL ?>><?= e($emp['quarter']) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">الشارع</td><td <?= $tdL ?>><?= e($emp['street']) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">المبنى / الطابق</td>
                                <td <?= $tdL ?>><?= e(trim($emp['bldg'] . ' / ' . $emp['floor'], ' /')) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">هاتف</td><td <?= $tdL ?>><?= e(trim($emp['phone'] . ' ' . $emp['cell'])) ?></td></tr>
                            <tr><td <?= $tdL ?> style="background:#f8fafc">البريد الإلكتروني (e-mail)</td><td <?= $tdL ?>><?= e($emp['email']) ?></td></tr>
                        </table>
                    </div>
                    <!-- جدول البنود: كل السطور متل النموذج الأصلي (حتى الصفر) والخانات المسدودة سودا -->
                    <table class="doc-table" dir="rtl">
                        <thead><tr>
                            <th style="background:#1F4E5F;color:#fff;text-align:right">الشرح</th>
                            <th <?= $thS ?>>إجمالي الإيرادات (١)</th>
                            <th <?= $thS ?>>الإيرادات غير الخاضعة للضريبة (٢)</th>
                            <th <?= $thS ?>>الإيرادات الخاضعة للضريبة (٣)</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($r6Rows as [$ln, $lb, $c1, $c2, $c3]): ?>
                            <tr>
                                <td <?= $tdL ?>><?= $lnBox($ln) ?> <?= e($lb) ?></td>
                                <td <?= $tdN ?>><?= $fv($c1) ?></td>
                                <?= $c2 !== null ? '<td ' . $tdN . '>' . $fv($c2) . '</td>' : '<td style="background:#111"></td>' ?>
                                <td <?= $tdN ?>><?= $fv($c3) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight:800;background:#e2e8f0">
                            <td <?= $tdL ?>><?= $lnBox('310') ?> المجموع</td>
                            <td <?= $tdN ?>><?= $fv('66') ?></td>
                            <td <?= $tdN ?>><?= $fv('78') ?></td>
                            <td <?= $tdN ?>><?= $fv('79') ?></td>
                        </tr>
                        </tbody>
                    </table>
                    <table class="doc-table" dir="rtl" style="max-width:560px;margin:10px 0 0 auto">
                        <tr><td <?= $tdL ?> style="background:#f8fafc;font-weight:700" colspan="2">يُنزل:</td></tr>
                        <tr><td <?= $tdL ?>><?= $lnBox('330') ?> تنزيل عائلي</td><td <?= $tdN ?> style="width:180px"><?= $fv('80') ?></td></tr>
                        <tr><td <?= $tdL ?>><?= $lnBox('340') ?> تنزيلات أخرى</td><td <?= $tdN ?>><?= $fv('81') ?></td></tr>
                        <tr style="font-weight:700"><td <?= $tdL ?>><?= $lnBox('350') ?> صافي الإيرادات</td><td <?= $tdN ?>><?= $fv('84') ?></td></tr>
                        <tr style="font-weight:800;background:#fef9c3"><td <?= $tdL ?>><?= $lnBox('360') ?> الضريبة السنوية المتوجبة</td><td <?= $tdN ?>><?= $fv('89') ?></td></tr>
                    </table>
                    <p style="margin:8px 0 0;color:#475569;font-size:10pt">* العدد يشمل الزوجة في حال كانت لا تعمل والأولاد الذين هم على عاتق المستخدم/الأجير</p>
                </div>
            </details>
            <?php endforeach; ?>
            <table class="doc-table" dir="rtl" style="margin-top:14px">
                <thead><tr>
                    <th style="background:#1F4E5F;color:#fff;text-align:right">مجموع كل الموظفين (بيطابق ر5)</th>
                    <th <?= $thS ?>>الإجمالي</th><th <?= $thS ?>>غير الخاضع</th><th <?= $thS ?>>الخاضع</th>
                    <th <?= $thS ?>>التنزيل العائلي</th><th <?= $thS ?>>تنزيلات أخرى</th>
                    <th <?= $thS ?>>صافي الخاضع</th><th <?= $thS ?>>الضريبة</th>
                </tr></thead>
                <tbody><tr style="font-weight:700">
                    <td <?= $tdL ?>><?= count($D['emps']) ?> موظفاً</td>
                    <td <?= $tdN ?>><?= $s6('66') ?></td><td <?= $tdN ?>><?= $s6('78') ?></td><td <?= $tdN ?>><?= $s6('79') ?></td>
                    <td <?= $tdN ?>><?= $s6('80') ?></td><td <?= $tdN ?>><?= $s6('81') ?></td>
                    <td <?= $tdN ?>><?= $s6('84') ?></td><td <?= $tdN ?>><?= $s6('89') ?></td>
                </tr></tbody>
            </table>
        </div>
    </div>

    <!-- ر7: كشف التاركين -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-person-walking-arrow-right"></i>
            Formulaire R7 / كشف التاركين ر7 (<?= count($D['leavers']) ?>)</h3></div>
        <div class="card-body">
            <?php if ($D['r7cnss'] !== ''): ?>
                <p style="margin-top:0;color:#475569">رقم المؤسسة بالضمان الاجتماعي: <b><?= e($D['r7cnss']) ?></b></p>
            <?php endif; ?>
            <?php if (!$D['leavers']): ?>
                <p style="margin:0;color:#475569">ما في تاركين مسجّلين بالملف.</p>
            <?php else: ?>
            <table class="doc-table" dir="rtl">
                <thead><tr>
                    <th <?= $thS ?>>#</th><th style="background:#1F4E5F;color:#fff;text-align:right">الاسم الثلاثي</th>
                    <th <?= $thS ?>>رقم المالية</th><th <?= $thS ?>>رقم الضمان</th>
                    <th <?= $thS ?>>تاريخ التعيين</th><th <?= $thS ?>>تاريخ الترك</th>
                </tr></thead>
                <tbody>
                <?php foreach ($D['leavers'] as $i => $lv): ?>
                    <tr>
                        <td style="text-align:center"><?= $i + 1 ?></td>
                        <td <?= $tdL ?>><?= e($lv['name']) ?></td>
                        <td style="text-align:center"><?= e($lv['fin']) !== '' ? e($lv['fin']) : '<b style="color:#b91c1c">⚠️ ناقص</b>' ?></td>
                        <td style="text-align:center"><?= e($lv['cnss']) ?></td>
                        <td style="text-align:center"><?= e($lv['hired']) ?></td>
                        <td style="text-align:center"><?= e($lv['left']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
