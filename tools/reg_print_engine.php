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

check('🖨️ محرّك الطباعة: mPDF ضمن vendor (بلا composer أونلاين) + المحرّك + خطوط Cairo/FreeSans الجاهزة لـmPDF',
      is_file($P247 . '/vendor/autoload.php') && is_file($P247 . '/vendor/mpdf/mpdf/src/Mpdf.php')
      && is_file($P247 . '/assets/fonts/ttf/CairoArabic-Regular.ttf') && is_file($P247 . '/assets/fonts/ttf/CairoArabic-Bold.ttf')
      && is_file($P247 . '/vendor/mpdf/mpdf/ttfonts/FreeSans.ttf') && is_file($P247 . '/vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf')
      && is_file($P247 . '/tools/_make_pdf_fonts.py')
      && strpos($en247, "return ['cairo', false];") !== false && strpos($en247, "'default_font' => \$rtl ? 'cairo' : 'freesans'") !== false);

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
      && strpos($en247, "if (\$fitOne || \$sum <= \$usable * 1.15) continue;") !== false
      && strpos($en247, "'shrink_tables_to_fit' => \$fitOne ? 3 : 1.15,") !== false
      && strpos($en247, "//form[not(.//table)]") !== false
      && strpos($ex247, 'window.msaFitToggle = function (btn)') !== false && strpos($ex247, "fit: window.msaFitOne ? '1' : '0'") !== false
      && strpos($fn247, 'id="msaFitBtn" onclick="msaFitToggle(this)"') !== false
      && strpos($he247, "'fit' => !empty(\$_POST['fit'])") !== false
      && strpos($en247, "'الجزء ' . (\$pi + 1) . ' من ' . \$np") !== false
      && strpos($en247, 'صفحة {PAGENO} من {nbpg}') !== false
      && strpos($en247, "str_replace('\\\\', '/', \$abs)") !== false
      && strpos($en247, '$mpdf->simpleTables = true;') !== false);

check('📐 الإكسل/الوورد: ما قبل أوّل جدول = كتلة عنوان فوق رأس الأعمدة (titleLines) · الشارات لا تُصدَّر · الإكسل يُطبع بالحجم الحقيقي على N صفحات عرضاً مع تكرار عمودَي # والاسم · النصّ يُحدّ بـ42 حرفاً',
      strpos($re247, 'public function titleLines(array $lines)') !== false
      && strpos($re247, "foreach (\$this->titleLines as \$ln) { \$k = \$ln['k'] ?? 'meta'; \$st = \$k === 'title' ? 1 : (\$k === 'school' ? 2 : 9);") !== false
      && strpos($re247, '$pagesAcross = max(1, (int)ceil($sumW / ($this->landscape ? 150 : 100)));') !== false
      && strpos($re247, '" fitToWidth="\' . $pagesAcross . \'" fitToHeight="0"/>\'') !== false
      && strpos($re247, "localSheetId=\"0\">' . \$printCols . '\\''") !== false
      && strpos($re247, 'min($len, $this->isNum($v) ? 58.0 : 42.0)') !== false
      && strpos($re247, '<cellXfs count="10">') !== false && strpos($re247, '<fonts count="6">') !== false
      && strpos($he247, 'if ($tablesN === 0 && $pending) { $rep->titleLines($pending); $pending = []; return; }') !== false
      && strpos($he247, "\$cls('badge') . ' or ' . \$cls('tag') . ' or ' . \$cls('pill')") !== false
      && strpos($he247, "\$push(hx_text(\$tt), 'title');") !== false
      && strpos($he247, 'use (&$walk, $emitTable, &$pending, $xp, $cls, $push)') !== false);

$dx247 = (string)file_get_contents($P247 . '/includes/docx_engine.php');
check('📝 الوورد للإفادة/الكتاب (بلا جداول بيانات) = مستند حقيقي عبر PHPWord من HTML المستند (MsaDocx ⇐ MsaPdf::prepare) لا جدول الكشوف',
      is_file($P247 . '/vendor/phpoffice/phpword/src/PhpWord/PhpWord.php')
      && strpos($dx247, 'class MsaDocx') !== false && strpos($dx247, 'MsaPdf::prepare($html, $o)') !== false
      && strpos($dx247, '$dom->saveXML($ch)') !== false && strpos($dx247, 'setBidiVisual(true)') !== false
      && strpos($en247, 'public static function prepare(string $html, array $o = []): array') !== false
      && strpos($he247, "if (\$format === 'docx' && \$tablesN === 0) {") !== false && strpos($he247, 'MsaDocx::fromHtml($html,') !== false);
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
