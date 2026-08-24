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
    $r6 = []; $r7 = 0;
    foreach ($x->xpath('//Attached_Form') ?: [] as $af) {
        if ((string)$af['FormNo'] === 'R6') $r6[] = $af;
        elseif ((string)$af['FormNo'] === 'R7') $r7++;
    }
    $add(count($r6) > 0, 'ملف الموظفين (ر6) موجود', count($r6) . ' موظفاً');
    $add($r7 > 0, 'كشف التاركين (ر7) موجود', $r7 ? 'موجود' : 'مفقود — الماكرو ما ولّده');

    // خانات ر5 المالية بالملف
    $L5 = [];
    foreach ($as->FC as $fc) $L5[(string)$fc['Int_Line_No']] = (float)$fc->Submitted_AMT;
    $g5 = fn($k) => $L5[$k] ?? 0.0;

    // ٣) قراءة صفوف الموظفين + الأخطاء القاتلة
    $S6 = []; $bad = ['geo' => [], 'fin' => [], 'mar' => []];
    $byFin = [];
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
        $ms = (int)($g['1389'] ?? 0);
        if ($ms < 1 || $ms > 4) $bad['mar'][] = $nm;
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

    $ok = true;
    foreach ($C as $c) if (!$c['ok']) $ok = false;
    return ['ok' => $ok, 'checks' => $C, 'fy' => $fy, 'info' => [
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
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
