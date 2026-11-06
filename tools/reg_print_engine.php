<?php
/* =====================================================================
 * 247) 🖨️ محرّك الطباعة الرسمي (2026-10-11 «بس أرسلها للطبع تكون جاهزة دغري»): mPDF على الخادم (includes/pdf_engine.php)
 *      بدل تصوير الشاشة — الطباعة/«احفظ عالكمبيوتر»/واتساب/إيميل كلّها منه؛ tfoot ⇒ آخر الجسم (المجاميع مرّة واحدة)؛
 *      الجدول الأعرض من الورقة يُقسَّم أعمدةً؛ الإكسل/الوورد: كتلة العنوان فوق الرأس + طباعة بالحجم الحقيقي.
 *      يُضمَّن من regression_check.php ويعمل مستقلاً أيضاً: C:\xampp\php\php.exe tools\reg_print_engine.php
 * =================================================================== */
if (!function_exists('check')) {
    $pass = 0; $fail = 0; $results = [];
    function check(string $name, bool $ok, string $detail = ''): void {
        global $pass, $fail, $results;
        $ok ? $pass++ : $fail++;
        $results[] = ($ok ? '✅ PASS' : '❌ FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '');
    }
    $__regStandalone = true;
}
$P247 = realpath(__DIR__ . '/..');
$en247 = (string)file_get_contents($P247 . '/includes/pdf_engine.php');
$ex247 = (string)file_get_contents($P247 . '/assets/js/export.js');
$ps247 = (string)file_get_contents($P247 . '/assets/js/pdf-save.js');
$fn247 = (string)file_get_contents($P247 . '/includes/functions.php');
$he247 = (string)file_get_contents($P247 . '/pages/html_export.php');
$re247 = (string)file_get_contents($P247 . '/includes/report_export.php');

check('🖨️ محرّك الطباعة: mPDF ضمن vendor (بلا composer أونلاين) + المحرّك + خطّ الورق Arial (كالشاشة، 2026-10-17) وCairo/FreeSans احتياطاً',
      is_file($P247 . '/vendor/autoload.php') && is_file($P247 . '/vendor/mpdf/mpdf/src/Mpdf.php')
      && is_file($P247 . '/assets/fonts/ttf/CairoArabic-Regular.ttf') && is_file($P247 . '/assets/fonts/ttf/CairoArabic-Bold.ttf')
      && is_file($P247 . '/vendor/mpdf/mpdf/ttfonts/FreeSans.ttf') && is_file($P247 . '/vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf')
      && is_file($P247 . '/tools/_make_pdf_fonts.py')
      && is_file($P247 . '/assets/fonts/ttf/arial.ttf') && is_file($P247 . '/assets/fonts/ttf/arialbd.ttf')
      && strpos($en247, "return ['arial', false];") !== false && strpos($en247, "'default_font' => 'arial'") !== false
      && strpos($en247, "\$fontdata['arial'] = ['R' => 'arial.ttf', 'B' => 'arialbd.ttf'") !== false
      && strpos((string)file_get_contents($P247 . '/includes/pdf_chromium.php'), "@font-face{font-family:Arial;font-style:normal;font-weight:400;src:url(arial.ttf)") !== false);

check('🖨️ html_export.php يبني PDF (format=pdf · disp=inline للطباعة · landscape=auto يقرّره المحرّك)',
      strpos($he247, "in_array(\$_POST['format'] ?? '', ['docx', 'pdf'], true)") !== false
      && strpos($he247, "require_once __DIR__ . '/../includes/pdf_engine.php';") !== false
      && strpos($he247, 'MsaPdf::fromHtml($html,') !== false
      && strpos($he247, "(\$_POST['landscape'] ?? 'auto') === 'auto'") !== false
      && strpos($he247, "(\$_POST['disp'] ?? '') === 'inline'") !== false);

check('🖨️ العميل: الطباعة = PDF الخادم بإطار مخفي · احفظ/واتساب/إيميل من الخادم · القسائم والنماذج طبق الأصل على طريقها القديم · زرّ «PDF رسمي» (Chrome) أُزيل',
      strpos($ex247, 'window.msaServerPdf = function (opts)') !== false
      && strpos($ex247, 'window.ppPrint = function (btn)') !== false
      && strpos($ex247, "if (document.querySelector('.salary-slip, .payslip-card, .ofs, .xls-sheet')) return false;") !== false
      && strpos($ex247, 'window.ppPrintFile(URL.createObjectURL(r.blob), false);') !== false
      && strpos($ex247, "fd.set(k, extra[k])") !== false
      && strpos($ps247, "if (window.msaServerPdf && !document.querySelector('.ofs, .xls-sheet')) return window.msaServerPdf()") !== false
      && strpos($ps247, 'return r.blob ? r : { blob: r.doc.output(\'blob\'), name: r.name };') !== false
      && strpos($ps247, 'if (r.blob) { var u = URL.createObjectURL(r.blob)') !== false
      && strpos($fn247, 'onclick="ppPrint(this)"') !== false
      && strpos($fn247, 'title="PDF رسمي طبق الأصل') === false);

check('🖨️ المحرّك: tfoot ⇒ آخر الجسم (المجاميع مرّة واحدة) · اتجاه كل جدول · شرائح العنوان بفواصل · الشارات تُحذف · تقسيم العريض أعمدةً مع تكرار # والاسم · تذييل صفحة n من N',
      strpos($en247, "\$tr->setAttribute('class', trim(\$tr->getAttribute('class') . ' total-row'))") !== false
      && strpos($en247, "foreach (\$xp->query('//table[not(@dir)]') as \$t) { \$t->setAttribute('dir', \$dir);") !== false
      && strpos($en247, "\$chip->parentNode->insertBefore(\$dom->createTextNode(' · '), \$chip);") !== false
      && strpos($en247, "\$cls('badge') . ' or ' . \$cls('tag') . ' or ' . \$cls('pill')") !== false
      && strpos($en247, "if (\$fitOne || \$official || \$sum <= \$usable * 1.15) { if (!\$fitOne && !\$official) \$applyWidths(\$t, \$w, \$usable); continue; }") !== false
      && strpos($en247, "'shrink_tables_to_fit' => \$fitOne ? 3 : 1.15,") !== false
      && strpos($en247, "//form[not(.//table)]") !== false
      && strpos($ex247, 'window.msaFitToggle = function (btn)') !== false && strpos($ex247, "fit: window.msaFitOne ? '1' : '0'") !== false
      && strpos($fn247, 'id="msaFitBtn" onclick="msaFitToggle(this)"') !== false
      && strpos($he247, "'fit' => ((\$_POST['fit'] ?? '1') !== '0'),") !== false
      && strpos($en247, "'الجزء ' . (\$pi + 1) . ' من ' . \$np") !== false
      && strpos($en247, 'صفحة {PAGENO} من {nbpg}') !== false
      && strpos($en247, "str_replace('\\\\', '/', \$abs)") !== false
      && strpos($en247, '$mpdf->simpleTables = true;') !== false);

check('📐 الإكسل/الوورد (قواعد 2026-10-21: خطّ 12 لا يصغر · العمود قدّ محتواه · أرقام لاتينية · الاتجاه من اللغة): كتلة عنوان فوق الرأس (titleLines) · الشارات لا تُصدَّر · الإكسل 100٪ على N صفحات عرضاً مع تكرار # والاسم · الوورد أجزاء docxParts بلا تصغير · مجموع مدموج · أعمدة الرمز بلا فواصل · البطاقة السنوية على المسار المجمّد (legacy)',
      strpos($re247, 'public function titleLines(array $lines)') !== false
      && strpos($re247, "foreach (\$this->titleLines as \$ln) { \$k = \$ln['k'] ?? 'meta'; \$st = \$k === 'title' ? 1 : (\$k === 'school' ? 2 : 9);") !== false
      && strpos($re247, '$pagesAcross = $fitW ? 1 : 0;') !== false && strpos($re247, '$fitW = $pxTotal > $pxPage && $pxTotal <= $pxPage * 1.15;') !== false && strpos($re247, "if (\$this->orientMode === 'auto' && !\$this->landscape && \$sumW > 76) \$this->landscape = true;") !== false
      && strpos($re247, '<sheetPr><pageSetUpPr fitToPage="\' . ($fitW ? \'1\' : \'0\') . \'"/></sheetPr>') !== false && strpos($re247, 'scale="100" orientation=') !== false
      && strpos($re247, '$parts = $this->docxParts($cols, $w, $twDoc);') !== false && strpos($re247, '$pt = 12.0;') !== false && strpos($re247, '<w:noWrap/>') !== false
      && strpos($re247, 'private function totalLabelSpan(array $cells): int') !== false && strpos($re247, 'private function isCodeCol(int $c): bool') !== false && strpos($re247, 'private function autoDir(): void') !== false
      && strpos($re247, '" fitToWidth="\' . $pagesAcross . \'" fitToHeight="0"/>\'') !== false
      && strpos($re247, "localSheetId=\"0\">' . \$printCols . '\\''") !== false
      && strpos($re247, '<headerFooter><oddFooter>') !== false
      && strpos($re247, '$len = min($len, 42.0); }') !== false
      && strpos($re247, '<cellXfs count="14">') !== false && strpos($re247, '<fonts count="6">') !== false
      && strpos($re247, 'public $legacy = false;') !== false && strpos($re247, 'public function docxLegacy()') !== false && strpos($re247, "if (\$this->legacy) { if (\$this->pyExport(\$format)) return; \$this->docxLegacy(); return; }") !== false
      && strpos((string)file_get_contents($P247 . '/pages/annual_slip_export.php'), '$rep->legacy = true;') !== false
      && strpos($he247, 'function hx_num(string $s): string') !== false && strpos($he247, "if (hx_has_class(\$n, ' money-usd ')) return '';") !== false
      && strpos($ex247, 'window.msaFitOne = false;') !== false
      /* 📐 (2026-10-22 «الدولار تحت الليرة مش حدّها») سطر ثانٍ تحت الليرة بالشاشة والـPDF */
      && strpos((string)file_get_contents($P247 . '/assets/css/app.css'), '.doc-table tbody td .money-usd { display: block; font-size: .8em;') !== false
      && strpos((string)file_get_contents($P247 . '/includes/pdf_chromium.php'), 'table.pdf-t td .money-usd{display:block;font-size:8.5pt;') !== false
      && strpos($he247, 'if ($tablesN === 0 && $pending) { $rep->titleLines($pending); $pending = []; return; }') !== false
      && strpos($he247, "\$cls('badge') . ' or ' . \$cls('tag') . ' or ' . \$cls('pill')") !== false
      && strpos($he247, "\$push(hx_text(\$tt), 'title');") !== false
      && strpos($he247, 'use (&$walk, $emitTable, &$pending, $xp, $cls, $push)') !== false);

/* 📏📗 (2026-11-06 «بدي اطبع اي تقرير اكسل… خلي العواميد عرضهن قد الكلمة او المبلغ… عالجلي ملف الاكسل جذرياً») مولّد الإكسل/الوورد:
 *    العرض بالخطّ الحقيقي (Arial عبر mPDF) معايَراً على «ملاءمة تلقائية» إكسل مايكروسوفت (xlUnits = mm/2.30 + 0.8) · الرأس على ≤3 أسطر متوازنة لا كلمةً كلمة ·
 *    رأس على صفّين بمجموعات مدموجة (الفئة/المرحلة) بالإكسل والوورد · أرقام صحيحة «#,##0» بلا نقطة زائدة والكسور «#,##0.00» · بلا فلتر تلقائي (زرّه يغطّي الرأس) ·
 *    سطر فاصل بلا إطار · العنوان لا يتكرّر (صفوف 1..الرأس تتكرّر بالطباعة بدل ترويسة &) · التذييل «التاريخ: …» (رمز الحجم لا يلتصق بالرقم) · أقواس العربي بـreadingOrder ·
 *    زيادة ≤15٪ ⇒ ملاءمة العرض كالـPDF · عنوان المجموع الوحيد ممتدّ على الصفّ · وورد بلا LRE (كان يعكس الأرقام) */
$ok248 = false; $why248 = '';
try {
    $src248 = $re247;
    $okS248 = strpos($src248, 'public static function xlUnits(string $t, bool $bold = false, float $pt = 12.0): float') !== false
        && strpos($src248, 'return self::mmText($t, $bold, $pt) / 2.30 + 0.8;') !== false
        && strpos($src248, 'private function headerWidth(string $h, float $dataW, bool $bold = true): float') !== false
        && strpos($src248, 'private function balancedLines(array $words, int $k, bool $bold): float') !== false
        && strpos($src248, 'private function linesNeeded(string $v, float $w, bool $bold = false, float $pt = 12.0): int') !== false
        && strpos($src248, 'public function head($cells, array $groups = [])') !== false && strpos($src248, 'private function docxHeaderRows(array $idx): string') !== false
        && strpos($src248, '<numFmt numFmtId="164" formatCode="#,##0"/>') !== false && strpos($src248, '$sN = ($nv == floor($nv)) ? $styleNum : ($styleNum === 7 ? 13 : 12);') !== false
        && strpos($src248, '<autoFilter ref=') === false && strpos($src248, "\$addRow([''], 0, 0, true);") !== false
        && strpos($src248, '$this->xlsxHeadRows = [1, $headerRow];') !== false && strpos($src248, '<headerFooter><oddFooter>') !== false && strpos($src248, "'التاريخ: ' : 'Date : ') . date('d/m/Y')") !== false
        && strpos($src248, '$ro = \' readingOrder="\' . ($this->isRtl() ? \'2\' : \'1\') . \'"\';') !== false
        && strpos($src248, 'private function totalSingleLabel(array $cells): ?string') !== false
        && strpos($src248, 'public static function twText(string $s, float $pt, bool $bold = false): int') !== false
        && strpos($src248, "self::xa(\"\\u{202A}\" . \$pt . \"\\u{202C}\")") === false
        && strpos($he247, "\$rep->head(\$headers, \$groups);") !== false && strpos($he247, "\$groups[] = ['t' => hx_clean(hx_text(\$top['cell'])), 'c0' => \$c, 'c1' => \$c + \$top['cs'] - 1];") !== false
        && strpos($src248, '$autoW = $this->autoWidthsLegacy($cols);') !== false;   // 🧊 البطاقة السنوية على القياس القديم كما كانت
    // تشغيل فعلي: ورقة بأسماء عربية ومبالغ ومجموعات ⇒ أعرض الأعمدة ضمن ما قاسه إكسل مايكروسوفت (36 خليّة معايَرة 06/11): اسم 13.4 ⇒ [13.3, 15.5] · مبلغ 10.8 ⇒ [10.5, 12] · X ⇒ [4, 5.5]
    if (!defined('BASE_URL')) define('BASE_URL', '/');
    require_once $P247 . '/includes/report_export.php';
    $rep248 = new ReportTable('فحص المولّد', true);
    $rep248->dir('rtl');
    $rep248->head(['#', 'الاسم والشهرة', 'حاضنة', 'معلم', 'أساس الراتب', 'الراتب المركّب (الأساسي فقط)'], [['t' => 'الفئة', 'c0' => 2, 'c1' => 3]]);
    foreach ([['جوسلين يوسف عازار', 'X', '', 2815000, 108330000], ['مارلين شفيق ابوزغيب', '', 'X', 2155000.5, 90815000], ['إجازة', 'X', '', 27, 1]] as $i => $r) $rep248->row(array_merge([$i + 1], $r));
    $rep248->totalRow(['', 'المجموع — العدد: 3', '', '', 4970027, 199145001]);
    $rep248->totalRow(['العدد الإجمالي: 3 — مكتمل: 3', '', '', '', '', '']);
    $f248 = sys_get_temp_dir() . '/reg248.xlsx'; @unlink($f248);
    $rep248->to($f248)->xlsx();
    $z = new ZipArchive(); $sheet = ''; $styles = '';
    if ($z->open($f248) === true) { $sheet = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $styles = (string)$z->getFromName('xl/styles.xml'); $z->close(); }
    @unlink($f248);
    preg_match_all('/<col min="(\d+)" max="\d+" width="([\d.]+)"/', $sheet, $cm);
    $w248 = []; foreach ($cm[1] as $i => $n) $w248[(int)$n] = (float)$cm[2][$i];
    $okW = ($w248[2] ?? 0) >= 13.3 && ($w248[2] ?? 0) <= 15.5 && ($w248[6] ?? 0) >= 10.5 && ($w248[6] ?? 0) <= 13.0 && ($w248[3] ?? 0) >= 4.0 && ($w248[3] ?? 0) <= 5.5 && ($w248[1] ?? 0) <= 4.5;
    // الصفوف: 1 العنوان · 2 فاصل · 3 المجموعات · 4 الأعمدة · 5–7 بيانات · 8 مجموع · 9 مجموع وحيد
    $okX = strpos($sheet, '<mergeCell ref="C3:D3"/>') !== false && strpos($sheet, '<mergeCell ref="A3:A4"/>') !== false    // صفّا الرأس: «الفئة» فوق حاضنة/معلم، و# على الصفّين
        && strpos($styles, 'readingOrder="2"') !== false
        && strpos($sheet, '<autoFilter') === false && preg_match('/<c r="E6" s="12"><v>2155000\.5<\/v>/', $sheet) === 1 && preg_match('/<c r="E5" s="5"><v>2815000<\/v>/', $sheet) === 1
        && strpos($sheet, '<row r="2"><c r="A2" s="0"') !== false && strpos($sheet, '<mergeCell ref="A9:F9"/>') !== false && strpos($sheet, '<mergeCell ref="A8:D8"/>') !== false   // سطر فاصل بلا إطار · عنوان المجموع الوحيد ممتدّ · عنوان المجموع على # والاسم والفارغ بعده
        && strpos($sheet, '<oddHeader>') === false && strpos($sheet, 'التاريخ: ') !== false
        && strpos($sheet, 'tabSelected') === false && strpos($sheet, '<selection pane="bottomLeft" activeCell="A5" sqref="A5"/>') !== false;   // 📋 نسخ/لصق إلى ملف آخر يعمل (كان «multiple selections»)
    $wbx = ''; $rep248->to($f248)->xlsx(); $z = new ZipArchive(); if ($z->open($f248) === true) { $wbx = (string)$z->getFromName('xl/workbook.xml'); $z->close(); } @unlink($f248);
    $okP = strpos($wbx, '!$1:$4</definedName>') !== false;
    $ok248 = $okS248 && $okW && $okX && $okP;
    $why248 = 'src=' . ($okS248 ? 'ok' : 'NO') . ' widths=' . json_encode($w248) . ' xml=' . ($okX ? 'ok' : 'NO') . ' printTitles=' . ($okP ? 'ok' : 'NO');
} catch (Throwable $e) { $why248 = get_class($e) . ': ' . $e->getMessage(); }
check('📏📗 مولّد الإكسل/الوورد (2026-11-06): عرض بالخطّ الحقيقي معايَر على إكسل + رأس ≤3 أسطر + مجموعات مدموجة + أرقام بلا نقطة زائدة + بلا فلتر + عنوان مرّة + تذييل سليم + أقواس عربية + مجموع وحيد ممتدّ + وورد بلا LRE', $ok248, $why248);

$dx247 = (string)file_get_contents($P247 . '/includes/docx_engine.php');
check('📝 الوورد للإفادة/الكتاب (بلا جداول بيانات) = مستند حقيقي عبر PHPWord من HTML المستند (MsaDocx ⇐ MsaPdf::prepare) لا جدول الكشوف',
      is_file($P247 . '/vendor/phpoffice/phpword/src/PhpWord/PhpWord.php')
      && strpos($dx247, 'class MsaDocx') !== false && strpos($dx247, 'MsaPdf::prepare($html, $o') !== false
      && strpos($dx247, '$dom->saveXML($ch)') !== false && strpos($dx247, 'setBidiVisual(true)') !== false
      && strpos($en247, 'public static function prepare(string $html, array $o = []): array') !== false
      && strpos($he247, "if (\$format === 'docx' && \$tablesN === 0) {") !== false && strpos($he247, 'MsaDocx::fromHtml($html,') !== false
      && strpos($ex247, "fd.set('lh_bg', new URL(m[1], document.baseURI).href)") !== false
      && strpos($en247, "SetWatermarkImage(\$p['lh_file'], 1,") !== false && strpos($en247, "\$w->setAttribute('class', 'word-head-on')") !== false
      && strpos($dx247, "MsaPdf::prepare(\$html, \$o + ['word_head' => true])") !== false);
// تشغيل فعلي: جدول عربي بترويسة ومجاميع ⇒ PDF حقيقي بصفحة واحدة، بلا تكرار tfoot
$ok247 = false; $why247 = '';
try {
    if (!defined('BASE_URL')) define('BASE_URL', '/');
    require_once $P247 . '/includes/pdf_engine.php';
    $rows = ''; for ($i = 1; $i <= 30; $i++) $rows .= '<tr><td>' . $i . '</td><td>أستاذ رقم ' . $i . '</td><td class="num">1,250,000</td></tr>';
    $html = '<div class="doc-sheet"><div class="doc-head"><div class="dh-fr">Test</div><div class="dh-ar">فحص المحرّك</div><div class="dh-meta"><span class="dh-chip">أ</span><span class="dh-chip">ب</span></div></div>'
        . '<table class="doc-table"><thead><tr><th>#</th><th>الاسم</th><th>المبلغ</th></tr></thead><tbody>' . $rows . '</tbody><tfoot><tr><td></td><td>المجموع</td><td>37,500,000</td></tr></tfoot></table></div>';
    $t0 = microtime(true);
    $pdf = MsaPdf::fromHtml($html, ['title' => 'reg', 'dir' => 'rtl', 'school' => 'Test']);
    $ms = (int)((microtime(true) - $t0) * 1000);
    $ok247 = strpos($pdf, '%PDF') === 0 && strlen($pdf) > 5000 && preg_match_all('/\/Type\s*\/Page[^s]/', $pdf) === 1;
    $why247 = 'bytes=' . strlen($pdf) . ' pages=' . preg_match_all('/\/Type\s*\/Page[^s]/', $pdf) . ' ms=' . $ms;
} catch (Throwable $e) { $why247 = get_class($e) . ': ' . $e->getMessage(); }
check('🖨️ المحرّك يولّد PDF فعلياً (ترويسة + جدول عربي 30 صفّاً + مجاميع = صفحة واحدة)', $ok247, $why247);

if (!empty($__regStandalone)) { echo implode("\n", $results) . "\n\n═══ النتيجة: $pass ناجح · $fail فاشل ═══\n"; exit($fail ? 1 : 0); }

/* 📏 (2026-10-15 «الأعمدة أوسع من الأرقام أو الكلمات اللي جوّاتها… لازم تساع على صفحة A4… بيطلع صفحات كتير») عرض كل عمود = محتواه
 *    الحقيقي مقيساً بخطوط mPDF نفسها (Cairo مشكَّلاً عبر OTL / FreeSans) لا تخميناً بالملم، ويُكتب صريحاً على الجدول (+1mm على عرض الجدول
 *    لأن المساواة التامة تُسقط mPDF بفرع «كل عمود بأقصى محتواه»)؛ عمودي إلا إذا لم يسع؛ السطر 12pt بارتفاع 1.15 لا صندوق خط Cairo؛
 *    النماذج الرسمية (.official-doc) بلا تقسيم ولا عرض صريح. النتيجة: كشف الضمان 7 ⇒ 3 ورقات، الشهري 85 ⇒ أقلّ، كل الأعمدة قدّ كلماتها. */
check('📏 المحرّك: قياس حقيقي بخطوط mPDF (OTL) + عرض صريح لكل عمود (+1mm) + عمودي ما لم يضق + سطر 1.15 + النماذج الرسمية مستثناة',
      strpos($en247, 'private static function measurer(): ?array') !== false
      && strpos($en247, '$otl = (function () { return $this->otl; })->call($m);') !== false
      && strpos($en247, "\$sh = \$otl->applyOTL(\$run, \$m->CurrentFont['useOTL']); \$w += \$m->GetStringWidth(\$sh, true, \$otl->OTLdata, 0);") !== false
      && strpos($en247, 'public static function textMm(string $t, bool $bold = false, float $pt = 12): float') !== false
      && strpos($en247, 'public static function textMmEst(string $t): float') !== false
      && strpos($en247, '$tw = $sum * $scale + 1.0;') !== false
      && strpos($en247, "\$cell->setAttribute('style', 'width:' . round(\$w[\$i] * \$scale, 1) . 'mm;' . \$st);") !== false
      && strpos($en247, "'useFixedNormalLineHeight' => true,") !== false
      && strpos($en247, 'table.pdf-t td { border: 0.5pt solid #94a3b8; padding: 1.2pt 3pt; text-align: center; vertical-align: middle; line-height: 1.15; }') !== false
      && strpos($en247, "\$cap = (substr_count(trim(\$t), ' ') >= 4) ? 42 : 55;") !== false
      && strpos($en247, "\$ln3 = ['full' => \$m['rows'], 'wrap' => \$m['rows'] + \$m['wrapRows'], 'stack' => \$m['rows'] + \$m['wrapRows'] + \$m['usdRows']];") !== false   // 📐 (2026-10-27) + نسخ الرؤوس الضيّقة fullh/wraph/stackh
      && strpos($en247, '$landscape = $pl < $pp; $variantOf = $landscape ? $cl : $cp;') !== false
      && strpos($en247, "\$parts = (\$sum <= \$usable * 1.15) ? 1 : count(\$partition(\$w, \$usable, \$nameCol)[1]);") !== false
      && strpos($en247, "if (\$isTh && \$cs === 1 && \$nameCol < 0 && preg_match(") !== false
      && strpos($en247, "\" official-doc \")]', \$t)->length > 0;") !== false
      && strpos($en247, '$pw = []; foreach ($idx as $c) $pw[] = $w[$c];') !== false);

/* 🌐 (2026-10-15 «ما في ولا تقرير ظابط للطبع… فتّش على برنامج ذكاء… حتى لو بدي ادفع» ⇒ «ركّبه») المحرّك الاحترافي: Chromium (Gotenberg 8 بـDocker)
 *    على سيرفر المدرسة الخاصّ 144.91.110.214 عبر https://collegesaintmaxime.com/pdf/ (Apache proxy + Basic Auth). includes/pdf_chromium.php:
 *    MsaPdf::fromHtml يحوّل إليه تلقائياً إن وُجد config/pdf_service.php وإلّا (أو عند أي عطل) mPDF كما كان؛ التحضير مشترك (MsaPdf::prepare)،
 *    العرض الصريح بالملم يُشال والمتصفّح يقيس؛ التقسيم أعمدةً داخل الورقة بسكربت (قياسات حقيقية، توزيع متوازن، # + الاسم بكل جزء)؛
 *    أفقي إن كان أعرض من العمودي؛ خط Cairo مرفق؛ تذييل المدرسة/الصفحة/التاريخ. النتيجة: الشهري 85 ⇒ 61، الضمان 43 ⇒ 32، الضريبة 42 ⇒ 32. */
$cr247 = (string)@file_get_contents($PROJ . '/includes/pdf_chromium.php');
check('🌐 محرّك Chromium: ملف المحرّك + التحويل التلقائي من fromHtml مع رجوع لـmPDF + تحضير مشترك + تقسيم بالمتصفّح (متوازن) + أفقي للعريض + الاسم بكل جزء + الخط مرفق',
    strpos($cr247, 'class MsaChromePdf') !== false
    && strpos($en247, "if ((\$o['engine'] ?? '') !== 'mpdf' && class_exists('MsaChromePdf') && MsaChromePdf::available(\$o)) {") !== false
    && strpos($en247, "catch (\Throwable \$e) { error_log('MsaChromePdf fallback: ' . \$e->getMessage()); }") !== false
    && strpos($en247, "require_once __DIR__ . '/pdf_chromium.php';") !== false
    && strpos($cr247, '$p = MsaPdf::prepare($html, $o);') !== false
    && strpos($cr247, "\$o['variants'] = ['wrap', 'stack'];") !== false && strpos($cr247, "\$o['fit'] = true;") !== false
    && strpos($cr247, "\$landscape = \$landscape || ((\$p['widest'] ?? 0) > MsaPdf::usableWidth(false, \$side));") !== false
    && strpos($cr247, "if(cur.length&&(sum+w[c]>cap||(sum>=target&&gs.length<N-1))){ gs.push(cur); cur=[]; sum=0; }") !== false
    && strpos($cr247, "if(name>=0&&key.indexOf(name)<0) key.push(name);") !== false
    && strpos($cr247, "'waitForExpression' => 'window.msaFitDone === true',") !== false
    && strpos($cr247, "foreach (['f1.woff2', 'f2.woff2', 'f3.woff2'] as \$f)") !== false
    && strpos($cr247, "if (!empty(\$o['lh_bg'])) return false;") !== false
    && strpos($cr247, "getSetting('pdf_service_pass', '')") !== false && strpos((string)@file_get_contents($PROJ . '/pages/email_settings.php'), "setSetting('pdf_service_pass', ") !== false
    && strpos((string)@file_get_contents($PROJ . '/.gitignore'), 'config/pdf_service.php') !== false
    && strpos($en247, "'widest' => (float)(\$widestMm ?? 0)") !== false);
