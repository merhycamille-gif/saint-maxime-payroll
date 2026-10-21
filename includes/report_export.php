<?php
/**
 * تعبئة قالب رسمي (Excel) بقيم الخلايا عبر openpyxl ثم بثّه Excel أو PDF (LibreOffice).
 * النموذج يطلع طبق الأصل لأنّه قالب المستخدم الرسمي نفسه. يُستعمل للنماذج الحكومية (CNSS...).
 *   $templateAbs: مسار القالب .xlsx | $cells: [خلية => قيمة] | $format: xlsx|pdf | $name: اسم التنزيل
 */

/**
 * 🟢 مولّد احتياطي بلا أي أداة خارجية (2026-07-31، «ما عم في اطبع اكسل ولا PDF» أونلاين):
 * يعبّي قالب الـxlsx مباشرةً بـPHP (ZipArchive + DOM) — فيعمل زرّ Excel الرسمي على
 * الاستضافة أونلاين حيث لا Python/openpyxl. يكتب القيم بالخلايا المطلوبة بورقة القالب
 * الأولى محافظاً على تنسيقها (style)، ويفرض إعادة حساب الصيغ عند الفتح (fullCalcOnLoad)
 * حتى لا تبقى مجاميع القالب (P43/P47...) على قيمها القديمة المخبّأة.
 */
/**
 * 📤 تعبئة قالب إكسل متعدد الأوراق بالاسم (لملف الوزارة R567 وأمثاله — 2026-08-23):
 * $sheetsCells = ['R5' => [خلية => قيمة], 'R6' => [...], ...] — تعديل الخانات فقط،
 * والقالب (بما فيه الماكرو vbaProject والصيَغ والأكواد) يبقى كما هو بايت-بايت.
 */
function phpFillXlsxTemplateSheets($templateAbs, array $sheetsCells, $outPath)
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) return false;
    if (!@copy($templateAbs, $outPath)) return false;
    $zip = new ZipArchive();
    if ($zip->open($outPath) !== true) { @unlink($outPath); return false; }
    try {
        // ورقة بالاسم → ملفها (workbook.xml + rels)
        $wbx = (string)$zip->getFromName('xl/workbook.xml');
        $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
        $rid2file = [];
        if (preg_match_all('#Id="(rId\d+)"[^>]*Target="(worksheets/sheet\d+\.xml)"#', $rels, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) $rid2file[$m[1]] = 'xl/' . $m[2];
        }
        $name2file = [];
        if (preg_match_all('#<sheet name="([^"]+)"[^>]*r:id="(rId\d+)"#', $wbx, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { if (isset($rid2file[$m[2]])) $name2file[$m[1]] = $rid2file[$m[2]]; }
        }
        foreach ($sheetsCells as $sheetName => $cells) {
            if (!isset($name2file[$sheetName]) || !$cells) continue;
            $path = $name2file[$sheetName];
            $xml = $zip->getFromName($path);
            if ($xml === false) continue;
            $xml2 = phpFillSheetXmlCells($xml, $cells);
            if ($xml2 !== null) $zip->addFromString($path, $xml2);
        }
        // فرض إعادة حساب الصيغ عند الفتح (أكواد المناطق والوضع العائلي INDEX/MATCH)
        if (strpos($wbx, '<calcPr') !== false) {
            $wb2 = preg_replace('/<calcPr\b(?![^>]*fullCalcOnLoad)/', '<calcPr fullCalcOnLoad="1" ', $wbx, 1);
        } elseif (strpos($wbx, '</sheets>') !== false) {
            $wb2 = str_replace('</sheets>', '</sheets><calcPr fullCalcOnLoad="1"/>', $wbx);
        } else { $wb2 = $wbx; }
        if ($wb2 && $wb2 !== $wbx) $zip->addFromString('xl/workbook.xml', $wb2);
        $zip->close();
        return is_file($outPath) && filesize($outPath) > 200;
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable $e2) {}
        @unlink($outPath);
        return false;
    }
}

/**
 * يكتب خانات في XML ورقة واحدة — معالجة نصّية بمسار واحد (لا DOM: ورقة R6 بقالب
 * الوزارة 15MB والصيَغ حتى الصف 600، وDOM عليها يعلّق). يعيد XML أو null.
 */
function phpFillSheetXmlCells($xml, array $cells)
{
    $colIdx = function ($letters) {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    };
    // تجميع الخانات حسب الصف
    $byRow = [];
    foreach ($cells as $ref => $val) {
        if (!preg_match('/^([A-Za-z]+)(\d+)$/', $ref, $m)) continue;
        $byRow[(int)$m[2]][strtoupper($m[1])] = $val;
    }
    if (!$byRow) return $xml;
    // ستايل كل عمود من أول صف يحويه (لخلايا تُستحدث بصفوف ما فيها العمود أصلاً)
    $colStyle = [];
    $buildCell = function ($ref, $attrs, $val) {
        $attrs = preg_replace('/\s+t="[^"]*"/', '', $attrs);
        if ($val === '' || $val === null) return '<c r="' . $ref . '"' . $attrs . '/>';
        if (is_int($val) || is_float($val)
            || (is_string($val) && preg_match('/^-?\d+(\.\d+)?$/', $val) && !preg_match('/^0\d/', $val))) {
            return '<c r="' . $ref . '"' . $attrs . '><v>' . $val . '</v></c>';
        }
        $t = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<c r="' . $ref . '"' . $attrs . ' t="inlineStr"><is><t xml:space="preserve">' . $t . '</t></is></c>';
    };
    $out = preg_replace_callback('#(<row\b[^>]*\br="(\d+)"[^>]*)(/>|>(.*?)</row>)#s',
        function ($rm) use (&$byRow, &$colStyle, $colIdx, $buildCell) {
            $rowNum = (int)$rm[2];
            $inner = ($rm[3] === '/>') ? '' : $rm[4];
            // خرائط خلايا الصف الموجودة (بترتيبها)
            $exist = [];   // colIdx => [ref, attrs, fullXml, hasFormula]
            if ($inner !== '' && preg_match_all('#<c\b[^>]*\br="([A-Za-z]+)(\d+)"[^>]*?(?:/>|>.*?</c>)#s', $inner, $cm2, PREG_SET_ORDER)) {
                foreach ($cm2 as $c2) {
                    $ci = $colIdx($c2[1]);
                    preg_match('#^<c\b([^>]*?)/?>#s', $c2[0], $am);
                    $a = trim(preg_replace('/\br="[^"]*"/', '', $am[1] ?? ''));
                    $exist[$ci] = ['ref' => $c2[1] . $c2[2], 'attrs' => ($a === '' ? '' : ' ' . $a),
                                   'xml' => $c2[0], 'f' => strpos($c2[0], '<f') !== false];
                    if (!isset($colStyle[$ci]) && preg_match('/\bs="(\d+)"/', $c2[0], $sm2)) $colStyle[$ci] = $sm2[1];
                }
            }
            if (!isset($byRow[$rowNum])) return $rm[0];
            $vals = $byRow[$rowNum];
            unset($byRow[$rowNum]);
            foreach ($vals as $colL => $val) {
                $ci = $colIdx($colL);
                if (isset($exist[$ci])) {
                    if ($exist[$ci]['f']) { continue; } // 🔴 ممنوع دهس خلايا الصيغ (أعمدة أكواد الوزارة)
                    $exist[$ci]['xml'] = $buildCell($colL . $rowNum, $exist[$ci]['attrs'], $val);
                } else {
                    $attrs = isset($colStyle[$ci]) ? ' s="' . $colStyle[$ci] . '"' : '';
                    $exist[$ci] = ['ref' => $colL . $rowNum, 'attrs' => $attrs,
                                   'xml' => $buildCell($colL . $rowNum, $attrs, $val), 'f' => false];
                }
            }
            ksort($exist);
            return rtrim($rm[1]) . '>' . implode('', array_column($exist, 'xml')) . '</row>';
        }, $xml);
    if ($out === null) return null;
    // صفوف مطلوبة غير موجودة أصلاً بالقالب = خطأ صريح (قالب الوزارة صفوفه جاهزة سلفاً)
    if ($byRow) return null;
    // شطب القيم المخبّأة لخلايا الصيغ — تُحسب من جديد عند الفتح (أكواد المناطق)
    $out = preg_replace('#(<f\b[^>]*/>|</f>)<v>[^<]*</v>#', '$1', $out);
    return $out;
}

function phpFillXlsxTemplate($templateAbs, array $cells, $outPath)
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) return false;
    if (!@copy($templateAbs, $outPath)) return false;
    $zip = new ZipArchive();
    if ($zip->open($outPath) !== true) { @unlink($outPath); return false; }
    try {
        // الورقة الأولى (قوالبنا الرسمية كلها بورقة واحدة)
        $sheetPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetPath = $n; break; }
        }
        if (!$sheetPath) { $zip->close(); @unlink($outPath); return false; }
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) { $zip->close(); @unlink($outPath); return false; }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml)) { $zip->close(); @unlink($outPath); return false; }
        $sheetData = $doc->getElementsByTagNameNS($ns, 'sheetData')->item(0);
        if (!$sheetData) { $zip->close(); @unlink($outPath); return false; }

        // فهرسة الصفوف الموجودة حسب رقمها
        $rows = [];
        foreach ($sheetData->getElementsByTagNameNS($ns, 'row') as $r) $rows[(int)$r->getAttribute('r')] = $r;
        $colIdx = function ($letters) {
            $n = 0;
            foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
            return $n;
        };

        foreach ($cells as $ref => $val) {
            if (!preg_match('/^([A-Za-z]+)(\d+)$/', $ref, $m)) continue;
            $rowNum = (int)$m[2];
            // الصفّ: موجود أو يُدرَج بمكانه الصحيح (ترتيب تصاعدي)
            if (isset($rows[$rowNum])) {
                $row = $rows[$rowNum];
            } else {
                $row = $doc->createElementNS($ns, 'row');
                $row->setAttribute('r', (string)$rowNum);
                $before = null;
                foreach ($rows as $rn => $rEl) { if ($rn > $rowNum && ($before === null || $rn < (int)$before->getAttribute('r'))) $before = $rEl; }
                $sheetData->insertBefore($row, $before);
                $rows[$rowNum] = $row;
            }
            // الخلية: موجودة (نحافظ على style) أو تُدرَج بمكانها حسب ترتيب الأعمدة
            $cell = null;
            foreach ($row->getElementsByTagNameNS($ns, 'c') as $c) {
                if (strtoupper($c->getAttribute('r')) === strtoupper($ref)) { $cell = $c; break; }
            }
            if (!$cell) {
                $cell = $doc->createElementNS($ns, 'c');
                $cell->setAttribute('r', strtoupper($ref));
                $before = null; $myCol = $colIdx($m[1]);
                foreach ($row->getElementsByTagNameNS($ns, 'c') as $c) {
                    if (preg_match('/^([A-Za-z]+)\d+$/', $c->getAttribute('r'), $cm) && $colIdx($cm[1]) > $myCol) { $before = $c; break; }
                }
                $row->insertBefore($cell, $before);
            }
            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
            $cell->removeAttribute('t');
            // نصّ رقمي بصفر بادئ (مثل «043» برقم مؤسسة الضمان) يبقى نصاً — لا يُحوَّل لرقم فيضيع صفره
            if (is_int($val) || is_float($val)
                || (is_string($val) && preg_match('/^-?\d+(\.\d+)?$/', $val) && !preg_match('/^0\d/', $val))) {
                $cell->appendChild($doc->createElementNS($ns, 'v', (string)$val));
            } else {
                $cell->setAttribute('t', 'inlineStr');
                $is = $doc->createElementNS($ns, 'is');
                $t  = $doc->createElementNS($ns, 't');
                $t->setAttribute('xml:space', 'preserve');
                $t->appendChild($doc->createTextNode((string)$val));
                $is->appendChild($t);
                $cell->appendChild($is);
            }
        }
        // 🔴 شطب القيم المخبّأة لكل خلايا الصيغ (متل openpyxl): LibreOffice الصامت لا يحترم
        // fullCalcOnLoad ويعرض المجموع القديم المخبّأ من آخر استعمال يدوي للقالب — بلا قيمة
        // مخبّأة يُجبَر أي برنامج (Excel/LibreOffice) على حساب الصيغة من جديد عند الفتح.
        foreach ($doc->getElementsByTagNameNS($ns, 'c') as $c) {
            if ($c->getElementsByTagNameNS($ns, 'f')->length === 0) continue;
            foreach (iterator_to_array($c->getElementsByTagNameNS($ns, 'v')) as $v) $c->removeChild($v);
        }
        $zip->addFromString($sheetPath, $doc->saveXML());

        // فرض إعادة حساب الصيغ عند الفتح (وإلا تبقى المجاميع المخبّأة القديمة ظاهرة)
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb !== false) {
            if (strpos($wb, '<calcPr') !== false) {
                $wb2 = preg_replace('/<calcPr\b(?![^>]*fullCalcOnLoad)/', '<calcPr fullCalcOnLoad="1" ', $wb, 1);
            } elseif (strpos($wb, '</definedNames>') !== false) {
                $wb2 = str_replace('</definedNames>', '</definedNames><calcPr fullCalcOnLoad="1"/>', $wb);
            } else {
                $wb2 = str_replace('</sheets>', '</sheets><calcPr fullCalcOnLoad="1"/>', $wb);
            }
            if ($wb2 && $wb2 !== $wb) $zip->addFromString('xl/workbook.xml', $wb2);
        }
        $zip->close();
        return is_file($outPath) && filesize($outPath) > 200;
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable $e2) {}
        @unlink($outPath);
        return false;
    }
}

function officialTemplateExport($templateAbs, array $cells, $format, $name, $disposition = 'attachment')
{
    $py = null;
    foreach (['C:/Python314/python.exe', 'C:/Python313/python.exe', 'C:/Python312/python.exe', 'python'] as $p) {
        if ($p === 'python' || @is_file($p)) { $py = $p; break; }
    }
    $script = __DIR__ . '/../tools/fill_template.py';
    if (!$py || !is_file($script) || !is_file($templateAbs)) return false;
    $tmpDir = __DIR__ . '/../tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    $uid = uniqid('tpl', true);
    $outXlsx = $tmpDir . '/' . $uid . '.xlsx';
    $spec = [
        'template' => str_replace('\\', '/', $templateAbs),
        'out' => str_replace('\\', '/', $outXlsx),
        'sheet' => null,
        'cells' => $cells,
    ];
    $specFile = $tmpDir . '/' . $uid . '.json';
    file_put_contents($specFile, json_encode($spec, JSON_UNESCAPED_UNICODE));
    @shell_exec(escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($specFile) . ' 2>&1');
    @unlink($specFile);
    if (!is_file($outXlsx) || filesize($outXlsx) < 200) {
        // 🟢 لا بايثون/openpyxl (الاستضافة أونلاين)؟ المولّد الاحتياطي بـPHP يعبّي القالب نفسه
        @unlink($outXlsx);
        if (!phpFillXlsxTemplate($templateAbs, $cells, $outXlsx)) { @unlink($outXlsx); return false; }
    }

    if ($format === 'pdf') {
        $soffice = null;
        foreach (['C:/Program Files/LibreOffice/program/soffice.exe', 'C:/Program Files (x86)/LibreOffice/program/soffice.exe'] as $s) {
            if (@is_file($s)) { $soffice = $s; break; }
        }
        if ($soffice) {
            $profile = 'file:///' . str_replace('\\', '/', $tmpDir . '/lo_profile');
            @shell_exec(escapeshellarg($soffice) . ' --headless --norestore -env:UserInstallation=' . $profile
                . ' --convert-to "pdf:calc_pdf_Export" --outdir ' . escapeshellarg(str_replace('\\', '/', $tmpDir))
                . ' ' . escapeshellarg(str_replace('\\', '/', $outXlsx)) . ' 2>&1');
            $outPdf = $tmpDir . '/' . $uid . '.pdf';
            @unlink($outXlsx);
            if (!is_file($outPdf) || filesize($outPdf) < 400) { @unlink($outPdf); return false; }
            $data = file_get_contents($outPdf); @unlink($outPdf);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/pdf');
            // inline = معاينة النموذج الرسمي داخل الصفحة (iframe) بدل التنزيل
            header('Content-Disposition: ' . ($disposition === 'inline' ? 'inline' : 'attachment') . '; filename="' . $name . '.pdf"');
            header('Content-Length: ' . strlen($data));
            echo $data; exit;
        }
        // لا LibreOffice (أونلاين) → لا نخطف طلب الـPDF بملف إكسل: نرجع false ليشتغل
        // البديل الصحيح عند كل صفحة (إفادة الضمان: النسخة المصوّرة للطباعة؛
        // نموذج الاشتراكات: العرض الرسمي بالمتصفح مع رسالة توضيحية) — زرّ Excel يبقى يعمل.
        @unlink($outXlsx);
        return false;
    }
    $data = file_get_contents($outXlsx); @unlink($outXlsx);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
    header('Content-Length: ' . strlen($data));
    echo $data; exit;
}

/**
 * مولّد تقارير Office حقيقية (Excel .xlsx + Word .docx) — بلا أي مكتبة خارجية،
 * عبر ZipArchive المدمج في PHP. ملفات OOXML نظيفة ومنسّقة ورسمية:
 *  - عربي UTF-8، اتجاه RTL، ترويسة مدرسة، عنوان، صفّ رؤوس ملوّن، حدود، تنسيق أرقام،
 *    عرض أعمدة، طباعة A4 (أفقي/عمودي) مع ملاءمة العرض لصفحة واحدة.
 *
 * الاستعمال:
 *   $rep = new ReportTable('كشف الرواتب', $landscape=true);
 *   $rep->schoolHeader($school);                       // ترويسة المدرسة (اختياري)
 *   $rep->head(['#','الاسم','الراتب', ...]);            // رؤوس الأعمدة
 *   $rep->row(['1','أحمد', 1525000, ...]);              // صفوف (الأرقام تُنسَّق تلقائياً)
 *   $rep->sectionRow('الملاك', $cols);                  // صفّ عنوان قسم (اختياري)
 *   $rep->totalRow(['', 'المجموع', 50000000, ...]);     // صفّ مجاميع مظلَّل
 *   $rep->widths([6,40,22, ...]);                        // عرض الأعمدة (اختياري)
 *   $rep->xlsx();   // ينزّل .xlsx     | $rep->docx();  // ينزّل .docx
 */

class ReportTable
{
    private $title;
    private $landscape;
    private $headers = [];
    private $rows = [];      // كل صف: ['type'=>'data|total|section|head', 'cells'=>[...], 'span'=>n]
    private $colWidths = [];
    private $docxFontScale = 1.0;
    private $docxPt = 12.0;
    public $legacy = false;      // 🧊 البطاقة السنوية (مجمّدة بأمره): الوورد عبر المسار القديم (بايثون ثم docxLegacy) لا المولّد الجديد      // 📝 حجم خطّ جدول الوورد (يُختار من عدد الأعمدة ثم يصغر حتى تسع الورقة)
    public $orientMode = 'auto';   // 🔄 auto | 0 (عمودي مفروض) | 1 (أفقي مفروض) — الأفقي التلقائي للعريض فقط بوضع auto   // 📄 تصغير خط الوورد نسبياً حين لا تسع الأعمدة الورقة
    private $school = null;
    private $period = '';
    private $docxColWs = []; // عرض أعمدة الوورد (twips) من autoWidths
    private $dir = 'rtl';   // ↔️ (2026-10-08) اتجاه الورقة/المستند: عربي rtl (من اليمين) · فرنسي/لوائح الدولة ltr (من الشمال)
    // 📐 (2026-10-11 «ما في تقرير بيطلع مظبوط بالإكسل/الوورد»): كتلة العنوان كما بالورقة المعروضة — ترويسة المدرسة ثم عنوان المستند ثم سطر المعلومات —
    //    فوق رأس الجدول (كانت سطور الترويسة تنزل «أقساماً» داخل الجدول تحت رأس الأعمدة). [['t' => نصّ, 'k' => 'school'|'title'|'meta'], …]
    private $titleLines = [];

    public function __construct($title, $landscape = true)
    {
        $this->title = $title;
        $this->landscape = $landscape;
    }

    public function schoolHeader($school) { $this->school = $school; return $this; }
    public function period($p) { $this->period = $p; return $this; }
    public function widths($w) { $this->colWidths = $w; return $this; }
    public function dir($d) { $this->dir = ($d === 'ltr') ? 'ltr' : 'rtl'; $this->dirSet = true; return $this; }
    private $dirSet = false;
    /** ↔️ (2026-10-21 «إذا بالعربي من اليمين للشمال وإذا بالفرنسي من الشمال لليمين»): الاتجاه تلقائياً من لغة العنوان والرؤوس ما لم يُحدَّد صراحةً */
    private function autoDir(): void
    {
        if ($this->dirSet) return;
        $t = $this->title . ' ' . implode(' ', $this->headers); foreach ($this->titleLines as $ln) $t .= ' ' . ($ln['t'] ?? '');
        $ar = preg_match_all('/[\x{0600}-\x{06FF}]/u', $t); $lat = preg_match_all('/[A-Za-zÀ-ÿ]/u', $t);
        $this->dir = ($ar >= $lat) ? 'rtl' : 'ltr';
    }
    public function titleLines(array $lines) { $this->titleLines = array_values(array_filter($lines, fn($l) => trim((string)($l['t'] ?? '')) !== '')); return $this; }
    public function isRtl() { return $this->dir === 'rtl'; }

    public function head($cells) { $this->headers = array_values($cells); return $this; }
    public function row($cells) { $this->rows[] = ['type' => 'data', 'cells' => array_values($cells)]; return $this; }
    public function headRow($cells) { $this->rows[] = ['type' => 'head', 'cells' => array_values($cells)]; return $this; } // رأس جدول ثانٍ بنفس الورقة (تصدير صفحة فيها عدّة جداول)
    public function totalRow($cells) { $this->rows[] = ['type' => 'total', 'cells' => array_values($cells)]; return $this; }
    public function sectionRow($label) { $this->rows[] = ['type' => 'section', 'cells' => [$label]]; return $this; }

    private function colCount()
    {
        $n = count($this->headers);
        foreach ($this->rows as $r) $n = max($n, count($r['cells']));
        return max(1, $n);
    }

    private function isNum($v) { return is_int($v) || is_float($v) || (is_string($v) && $v !== '' && preg_match('/^-?\d+(\.\d+)?$/', str_replace(',', '', $v))); }
    /** عمود «رمز/رقم» (#، Code، الرمز، رقم الضمان…): أرقامه معرِّفات تُكتب كما هي بلا فواصل آلاف */
    private function isCodeCol(int $c): bool
    {
        static $cache = [];
        if (!isset($cache[$c])) { $h = trim((string)($this->headers[$c] ?? '')); $cache[$c] = ($c === 0 && ($h === '#' || $h === '')) || preg_match('/^(#|code|الرمز|رقم|n°|no\.?|id)\b/iu', $h) === 1; }
        return $cache[$c];
    }
    private function numVal($v) { return (float)str_replace(',', '', (string)$v); }

    private static function xa($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    /** اسم الورقة: إكسل يرفض [ ] : * ? / \ ويحدّه بـ31 حرفاً (كان «Rapports / التقارير» يفتح بإصلاح) */
    private function sheetName()
    {
        $n = preg_replace('/[\[\]:*?\/\]+/u', '-', (string)$this->title);
        $n = trim(preg_replace('/\s+/u', ' ', $n), " -'");
        $n = mb_substr($n, 0, 28, 'UTF-8');
        return $n !== '' ? $n : 'Sheet1';
    }
    /** عرض نصّ بوحدة «حرف إكسل» تقريباً: العربي والعريض 1.25، اللاتيني 1.0، الأرقام والفواصل 0.95 */
    public static function textW(string $t): float {
        $w = 0.0;
        if (preg_match_all('/./us', $t, $m)) {
            foreach ($m[0] as $ch) {
                $o = mb_ord($ch, 'UTF-8');
                if ($o >= 0x0600 && $o <= 0x06FF) $w += 1.1;   // 📏 2026-10-10: كان 1.25 فتطلع الأعمدة العربية أعرض من كلماتها
                elseif ($o >= 0x0640 && $o <= 0x065F) $w += 0;      // تشكيل
                elseif (ctype_digit($ch) || $ch === ',' || $ch === '.') $w += 0.95;
                elseif (ctype_upper($ch)) $w += 1.25;               // الكبيرة اللاتينية أعرض من الصفر (EMP1000024 كان يلتفّ)
                elseif ($o > 0x2000) $w += 1.3;
                else $w += 1.0;
            }
        }
        return $w;
    }
    /** عرض كل عمود على قدّ أطول محتوى (الرأس يُلفّ على سطرين فلا يفرض عرضه كلّه) — بين 8 و60 */
    private function autoWidths(int $cols): array {
        $w = array_fill(0, $cols, 0.0);
        for ($c = 0; $c < $cols; $c++) {
            $h = (string)($this->headers[$c] ?? '');
            // 📏 (2026-10-21 «عرض العامود تلقائياً لازم يكون قدّ عرض الكتابة اللي جوّاتو»): الرأس لا يوسّع العمود أكثر من أطول كلمة فيه — يلتفّ كلمةً كلمة
            $hw = 0.0; foreach (preg_split('/[\s\x{00A0}]+/u', $h) as $wd) $hw = max($hw, self::textW($wd));
            $w[$c] = max($w[$c], $hw + 1.0);
        }
        // 📏 (2026-10-10 مساءً «وسّع العامود لازم يكون مرتّب قدّ الكلمات اللي فيه مش هالقدّ واسع»): عنوان صفّ المجموع («مجموع الملاك»…)
        //    لا يوسّع عموده — يمتدّ على الخلايا الفارغة جنبه بالإكسل؛ والعرض = أطول محتوى فعلي + هامش صغير.
        foreach ($this->rows as $r) {
            if ($r['type'] === 'section') continue;
            for ($c = 0; $c < $cols; $c++) {
                $v = (string)($r['cells'][$c] ?? '');
                if ($v === '') continue;
                if ($r['type'] === 'total' && !$this->isNum($v)) continue;
                if ($this->isNum($v)) $len = strlen(number_format($this->numVal($v), 2)) * 0.95;
                else { $len = 0.0; foreach (preg_split('/\r\n|\n/', $v) as $ln) $len = max($len, self::textW($ln)); } // أطول سطر لا مجموع الأسطر
                $w[$c] = max($w[$c], min($len, $this->isNum($v) ? 58.0 : 42.0) + 1.5);   // 📐 النصّ الطويل يلتفّ (42 حرفاً)، الأرقام لا تنكسر
            }
        }
        for ($c = 0; $c < $cols; $c++) $w[$c] = round(max(5.0, min(60.0, $w[$c])), 1);
        return $w;
    }
    private function fileBase()
    {
        $t = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $this->title);
        return mb_substr($t, 0, 60, 'UTF-8') . '_' . date('Y-m-d');
    }

    /* ============================== XLSX ============================== */
    public function xlsx()
    {
        $this->autoDir();
        $cols = $this->colCount();
        $colLetter = function ($i) { // 0-based → A,B,...
            $s = ''; $i++;
            while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
            return $s;
        };

        // 📐 (2026-10-08 «بدّي ياها تطلع مرتّبة ودغري مظبوطة بدل ما أرجع أظبّط بالإكسل عواميد وأسطر»):
        //    عرض كل عمود على قدّ أطول محتوى فيه (رأس/بيانات/مجاميع، العربي أعرض)، وارتفاع كل سطر على قدّ أسطره الملفوفة،
        //    رأس الأعمدة مثبّت + فلتر تلقائي + يتكرّر على كل ورقة مطبوعة + الورقة تتّسع عرضاً على صفحة واحدة.
        $autoW = $this->autoWidths($cols);
        $rowsXml = ''; $rIdx = 0;
        $lineH = 17;   // ارتفاع السطر بخط 12 (نقاط)
        $linesOf = function ($v, $w) {
            $t = (string)$v; if ($t === '') return 1;
            $n = 0;
            foreach (preg_split('/\r\n|\n/', $t) as $ln) { $n += max(1, (int)ceil(self::textW($ln) / max(1.0, $w + 1.0))); } // متسامح قليلاً: كان يرفع ارتفاع سطر اسم يسع فعلاً
            return $n;
        };
        $extraMerges = [];
        $addRow = function ($cells, $styleText, $styleNum, $mergeAll = false, $spanTo = -1) use (&$rowsXml, &$rIdx, $cols, $colLetter, $autoW, $lineH, $linesOf, &$extraMerges) {
            $rIdx++;
            // 📐 (2026-10-21) عنوان صفّ المجموع يمتدّ على الأعمدة النصّية الأولى (# … الدرجة) بدل أن يُحشر بعمود ضيّق ويلتفّ على 4 أسطر
            if ($spanTo > 0) {
                $label = ''; for ($c = 0; $c <= $spanTo; $c++) { $v = (string)($cells[$c] ?? ''); if ($v !== '') { $label = $v; break; } }
                for ($c = 0; $c <= $spanTo; $c++) $cells[$c] = '';
                $cells[0] = $label;
                $extraMerges[] = 'A' . $rIdx . ':' . $colLetter($spanTo) . $rIdx;
            }
            // ارتفاع السطر = أكثر خلية أسطراً (الخلية المدموجة على عرض الورقة كلّه)
            $maxL = 1;
            if ($mergeAll) { $maxL = $linesOf($cells[0] ?? '', array_sum($autoW)); }
            else { for ($c = 0; $c < $cols; $c++) { $v = $cells[$c] ?? ''; if ($v !== '' && !$this->isNum($v)) $maxL = max($maxL, $linesOf($v, ($spanTo > 0 && $c === 0) ? array_sum(array_slice($autoW, 0, $spanTo + 1)) : ($autoW[$c] ?? 16))); } }
            $ht = $maxL > 1 ? ' ht="' . ($maxL * $lineH + 4) . '" customHeight="1"' : ($styleText === 3 ? ' ht="' . ($lineH + 6) . '" customHeight="1"' : '');
            $rowsXml .= '<row r="' . $rIdx . '"' . $ht . '>';
            if ($mergeAll) {
                $rowsXml .= '<c r="A' . $rIdx . '" s="' . $styleText . '" t="inlineStr"><is><t xml:space="preserve">' . self::xa($cells[0]) . '</t></is></c>';
                for ($c = 1; $c < $cols; $c++) $rowsXml .= '<c r="' . $colLetter($c) . $rIdx . '" s="' . $styleText . '"/>';
            } else {
                for ($c = 0; $c < $cols; $c++) {
                    $ref = $colLetter($c) . $rIdx;
                    $v = $cells[$c] ?? '';
                    if ($this->isNum($v) && $v !== '' && !$this->isCodeCol($c)) {
                        $rowsXml .= '<c r="' . $ref . '" s="' . $styleNum . '"><v>' . $this->numVal($v) . '</v></c>';
                    } elseif ($this->isNum($v) && $v !== '') {
                        $rowsXml .= '<c r="' . $ref . '" s="' . ($styleNum === 7 ? 11 : 10) . '"><v>' . $this->numVal($v) . '</v></c>'; // رمز/رقم: بلا فواصل آلاف
                    } else {
                        $rowsXml .= '<c r="' . $ref . '" s="' . $styleText . '" t="inlineStr"><is><t xml:space="preserve">' . self::xa($v) . '</t></is></c>';
                    }
                }
            }
            $rowsXml .= '</row>';
        };

        // أنماط: 1=عنوان, 2=ترويسة مدرسة, 3=رأس عمود, 4=نص بيانات, 5=رقم بيانات, 6=نص مجموع, 7=رقم مجموع, 8=قسم, 9=سطر معلومات
        if ($this->titleLines) {
            // كتلة العنوان كما بالورقة: ترويسة (غامق) ← عنوان (كبير) ← معلومات (رمادي)
            foreach ($this->titleLines as $ln) { $k = $ln['k'] ?? 'meta'; $st = $k === 'title' ? 1 : ($k === 'school' ? 2 : 9); $addRow([$ln['t']], $st, $st, true); }
        } else {
            // عنوان كبير
            $addRow([$this->title], 1, 1, true);
            if ($this->period) $addRow([$this->period], 2, 2, true);
            if ($this->school) {
                $sn = $this->school['name_ar'] ?? ($this->school['name_fr'] ?? '');
                $extra = trim(($this->school['address'] ?? ''));
                $addRow([$sn . ($extra ? ' — ' . $extra : '')], 2, 2, true);
            }
        }
        $addRow([''], 4, 4, true); // سطر فاصل
        $titleRows = $rIdx;

        // رؤوس
        if ($this->headers) $addRow($this->headers, 3, 3);
        $headerRow = $rIdx;

        // بيانات
        foreach ($this->rows as $r) {
            if ($r['type'] === 'section') $addRow($r['cells'], 8, 8, true);
            elseif ($r['type'] === 'head') $addRow($r['cells'], 3, 3);
            elseif ($r['type'] === 'total') $addRow($r['cells'], 6, 7, false, $this->totalLabelSpan($r['cells']));
            else $addRow($r['cells'], 4, 5);
        }

        // دمج صفوف العنوان/الترويسة عبر كل الأعمدة
        $merges = '';
        foreach ($extraMerges as $em) $merges .= '<mergeCell ref="' . $em . '"/>';
        // كتلة العنوان تُدمج على أوّل 8 أعمدة فقط (بالطباعة 100٪ الورقة الأولى تعرض أوّل الأعمدة؛ الدمج على 20 عموداً كان يرمي العنوان خارجها)
        $titleSpan = min($cols, 8);
        for ($m = 1; $m <= $titleRows; $m++) $merges .= '<mergeCell ref="A' . $m . ':' . $colLetter($titleSpan - 1) . $m . '"/>';
        // دمج صفوف الأقسام والمجاميع غير الرقمية
        $rr = $titleRows + ($this->headers ? 1 : 0);
        foreach ($this->rows as $r) { $rr++; if ($r['type'] === 'section') $merges .= '<mergeCell ref="A' . $rr . ':' . $colLetter($cols - 1) . $rr . '"/>'; }
        $mergesXml = $merges ? '<mergeCells count="' . substr_count($merges, '<mergeCell') . '">' . $merges . '</mergeCells>' : '';

        // أعمدة
        $colsXml = '<cols>'; $sumW = 0.0;
        for ($c = 0; $c < $cols; $c++) {
            $w = isset($this->colWidths[$c]) ? max((float)$this->colWidths[$c], (float)($autoW[$c] ?? 0)) : ($autoW[$c] ?? 16);   // العرض المحدّد يدوياً لا يقلّ عن قدّ المحتوى
            $colsXml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>'; $sumW += $w;
        }
        $colsXml .= '</cols>';
        // 🖨️ (2026-10-11 «بس أطبع إكسل ما في تقرير بيطلع مظبوط»): الورقة التي تسع عرض الصفحة تُطبع «ملاءمة العرض»؛ الأعرض (كشوف 20 عموداً)
        //    تُطبع بحجمها الحقيقي 12 على صفحات متجاورة مع تكرار عمودَي # والاسم (Print_Titles أعمدة) — لا تصغير يجعل الخط غير مقروء.
        // 📄 (2026-10-15 «ب د ف مشي الحال بس اكسل ما مشي الحال» بعد «العواميد كلّها نفس الصفحة»): الإكسل يُطبع دائماً بكل أعمدته على عرض ورقة
        //    واحدة (fitToWidth=1، الصفوف على صفحات)؛ الجدول الأعرض من الورقة العمودية ⇒ أفقي تلقائياً — كما الـPDF
        if ($this->orientMode === 'auto' && !$this->landscape && $sumW > 100) $this->landscape = true;
        // 🔠 (2026-10-21 «الخطّ 12 حتى نقدر نقراه»): لا تصغير بالطباعة (100٪)؛ الأعمدة الزائدة على صفحات متجاورة مع تكرار # والاسم (Print_Titles أعمدة)
        $pagesAcross = 0;
        $fitW = false;
        $keyLast = 0;
        for ($c = 0; $c < min(3, $cols); $c++) { $n = 0; $t = 0; foreach ($this->rows as $r) { if ($r['type'] !== 'data') continue; $v = (string)($r['cells'][$c] ?? ''); if ($v === '') continue; $n++; if (!$this->isNum($v)) $t++; } if ($n > 0 && $t / $n >= 0.5) { $keyLast = $c; break; } }
        $printCols = $fitW ? '' : ("'" . str_replace("'", "''", self::xa($this->sheetName())) . "'!\$A:\$" . $colLetter($keyLast) . ',');

        $orient = $this->landscape ? 'landscape' : 'portrait';
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="0"/></sheetPr>'
            . '<sheetViews><sheetView rightToLeft="' . ($this->isRtl() ? '1' : '0') . '" workbookViewId="0" tabSelected="1">'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . ($this->headers && $rIdx > $headerRow ? '<autoFilter ref="A' . $headerRow . ':' . $colLetter($cols - 1) . $rIdx . '"/>' : '')
            . $mergesXml
            . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" scale="100" orientation="' . $orient . '" fitToWidth="' . $pagesAcross . '" fitToHeight="0"/>'
            // 🖨️ (2026-10-21) ترويسة/تذييل الطباعة لا يتصغّران مع ملاءمة العرض: المدرسة + عنوان التقرير فوق كل ورقة، و«صفحة n من N» + التاريخ تحتها
            . '<headerFooter><oddHeader>' . self::xa($this->xlsxHeaderText()) . '</oddHeader>'
            . '<oddFooter>' . self::xa('&L&"Arial"&9' . date('d/m/Y') . '&C&"Arial"&9' . ($this->isRtl() ? 'صفحة &P من &N' : 'Page &P / &N') . '&R&"Arial"&9' . str_replace('&', '&&', mb_substr($this->title, 0, 60, 'UTF-8'))) . '</oddFooter></headerFooter>'
            . '</worksheet>';

        $styles = $this->xlsxStyles();
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xa($this->sheetName()) . '" sheetId="1" r:id="rId1"/></sheets>'
            . ($this->headers ? '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">' . $printCols . '\'' . str_replace("'", "''", self::xa($this->sheetName())) . '\'!$' . $headerRow . ':$' . $headerRow . '</definedName></definedNames>' : '')
            . '</workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $this->sendZip($this->fileBase() . '.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'xl/workbook.xml' => $wb,
            'xl/_rels/workbook.xml.rels' => $wbRels,
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /** نصّ ترويسة الطباعة بالإكسل: اسم المدرسة (عريض) ثم عنوان التقرير — بصيغة &C/&"خط"/&حجم */
    private function xlsxHeaderText(): string
    {
        $school = ''; $title = $this->title; $meta = '';
        if ($this->titleLines) {
            foreach ($this->titleLines as $ln) { $k = $ln['k'] ?? 'meta'; if ($k === 'school' && $school === '') $school = $ln['t']; elseif ($k === 'title') $title = $ln['t']; elseif ($k === 'meta' && $meta === '') $meta = $ln['t']; }
        } elseif ($this->school) { $school = $this->school['name_ar'] ?? ($this->school['name_fr'] ?? ''); $meta = $this->period; }
        $esc = fn($s) => str_replace('&', '&&', mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, 90, 'UTF-8'));
        $h = '&C';
        if ($school !== '') $h .= '&"Arial,Bold"&11' . $esc($school) . "\n";
        $h .= '&"Arial,Bold"&12' . $esc($title);
        if ($meta !== '') $h .= "\n" . '&"Arial,Regular"&9' . $esc($meta);
        return $h;
    }

    private function xlsxStyles()
    {
        // ألوان: رأس أزرق #1E3A8A نص أبيض، مجموع رمادي فاتح، قسم أزرق فاتح
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.##"/></numFmts>'
            . '<fonts count="6">'
            . '<font><sz val="12"/><name val="Arial"/></font>'                                  // 0 default — 🔠 خط 12 بكل المطبوعات
            . '<font><b/><sz val="15"/><color rgb="FF1E3A8A"/><name val="Arial"/></font>'        // 1 title
            . '<font><b/><sz val="12"/><color rgb="FF111827"/><name val="Arial"/></font>'        // 2 header — 🖨️ أسود على فاتح (قاعدة 2026-10-17: يُطبع على أي طابعة)
            . '<font><sz val="12"/><name val="Arial"/></font>'                                   // 3 data
            . '<font><b/><sz val="12"/><name val="Arial"/></font>'                               // 4 bold
            . '<font><sz val="11"/><color rgb="FF475569"/><name val="Arial"/></font>'             // 5 meta (سطر المعلومات)
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'                                   // 0
            . '<fill><patternFill patternType="gray125"/></fill>'                                // 1
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/></patternFill></fill>' // 2 header light grey (كان كحلياً بخطّ أبيض)
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/></patternFill></fill>' // 3 total grey
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/></patternFill></fill>' // 4 section light blue
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'                       // 0 none
            . '<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right>'
            . '<top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border>' // 1 thin grey
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="12">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 1 title
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 2 subheader
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 3 col header
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . ($this->isRtl() ? 'right' : 'left') . '" vertical="center" wrapText="1"/></xf>' // 4 data text
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' // 5 data num — الأرقام لليمين كما بكل الكشوف
            . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . ($this->isRtl() ? 'right' : 'left') . '" vertical="center" wrapText="1"/></xf>' // 6 total text
            . '<xf numFmtId="164" fontId="4" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' // 7 total num
            . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . ($this->isRtl() ? 'right' : 'left') . '" vertical="center" wrapText="1"/></xf>' // 8 section
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 9 meta
            . '<xf numFmtId="1" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 10 code num (بلا فواصل)
            . '<xf numFmtId="1" fontId="4" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 11 code num total
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /* ============================== DOCX ============================== */
    public function docx()
    {
        $this->autoDir();
        $cols = $this->colCount();
        // 📝 (2026-10-21 «صرلك شهرين… عم يطلعو مش مرتبين»): مولّد الوورد أُعيد بناؤه كبرامج التقارير الرسمية —
        //    • حجم الخطّ يُختار من عدد الأعمدة (11pt حتى 7 أعمدة … 7pt لـ19+) ثم يصغر نصف نقطة فنصف نقطة حتى تسع كل الأعمدة الورقة (حدّ 6.5pt).
        //    • الأرقام **لا تنكسر أبداً** (<w:noWrap/>) وعرض عمود الأرقام = أطول رقم فيه؛ أعمدة النصّ (الاسم/الفئة) تلتفّ عند الحاجة لكن ليس أضيق من أطول كلمة.
        //    • رأس الأعمدة يلتفّ على كلمات كاملة (لا حرفاً حرفاً)، فاتح بخطّ أسود (يُطبع على أي طابعة)، ويتكرّر بكل ورقة.
        //    • صفّ المجموع: عنوانه يمتدّ على الأعمدة النصّية الأولى (لا يُحشر بعمود الدرجة الضيّق)، وأفقي تلقائياً فوق 8 أعمدة.
        $isText = []; for ($c = 0; $c < $cols; $c++) { $n = 0; $t = 0; foreach ($this->rows as $r) { if ($r['type'] !== 'data') continue; $v = (string)($r['cells'][$c] ?? ''); if ($v === '') continue; $n++; if (!$this->isNum($v)) $t++; } $isText[$c] = $n > 0 && $t / $n >= 0.5; }
        if ($this->orientMode === 'auto' && !$this->landscape && $cols > 8) $this->landscape = true;
        $twDoc = $this->landscape ? 15704 : 10466; // عرض المحتوى داخل الهوامش (twips)
        // 🔠 (2026-10-21 «حجم الخطّ جوّات التقرير لازم يكون 12 حتى نقدر نقراه… أنا بختار العواميد»): الخطّ 12 ثابت لا يصغر؛
        //    الأعمدة التي لا تسعها الورقة تنتقل إلى ورقة تالية («الجزء n من N») مع تكرار عمودَي # والاسم — لا تصغير ولا كسر أرقام.
        $pt = 12.0;
        [$w, $sum, $slack] = $this->docxWidths($cols, $pt, $twDoc, $isText, false);
        if ($this->orientMode === 'auto' && !$this->landscape && $sum > $twDoc && $sum <= 15704) { $this->landscape = true; $twDoc = 15704; } // العمودي لا يسع والأفقي يسع ⇒ أفقي بدل جزأين
        $this->docxPt = $pt; $this->docxColWs = $w;
        $parts = $this->docxParts($cols, $w, $twDoc);
        if (getenv('MSA_DOCX_DEBUG')) @file_put_contents(__DIR__ . '/../tmp/docx_debug.txt', json_encode(['pt' => $pt, 'sum' => $sum, 'doc' => $twDoc, 'w' => $w, 'h' => $this->headers], JSON_UNESCAPED_UNICODE));

        $body = '';
        if ($this->titleLines) {
            foreach ($this->titleLines as $ln) {
                $k = $ln['k'] ?? 'meta';
                $body .= $this->docxPara($ln['t'], $k === 'title' ? ['b' => true, 'sz' => 30, 'align' => 'center', 'color' => '1E3A8A'] : ($k === 'school' ? ['b' => true, 'sz' => 24, 'align' => 'center'] : ['sz' => 20, 'align' => 'center', 'color' => '475569']));
            }
        } else {
            $body .= $this->docxPara($this->title, ['b' => true, 'sz' => 30, 'align' => 'center', 'color' => '1E3A8A']);
            if ($this->period) $body .= $this->docxPara($this->period, ['b' => true, 'sz' => 22, 'align' => 'center']);
            if ($this->school) {
                $sn = $this->school['name_ar'] ?? ($this->school['name_fr'] ?? '');
                $extra = trim(($this->school['address'] ?? ''));
                $body .= $this->docxPara($sn . ($extra ? ' — ' . $extra : ''), ['b' => true, 'sz' => 22, 'align' => 'center']);
            }
        }
        $body .= '<w:p><w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr></w:p>';

        $tbl = ''; $np = count($parts);
        foreach ($parts as $pi => $idx) {
            if ($pi > 0) $tbl .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            if ($np > 1) $tbl .= $this->docxPara(($this->isRtl() ? 'الجزء ' . ($pi + 1) . ' من ' . $np : 'Partie ' . ($pi + 1) . ' / ' . $np) . ' — ' . $this->title, ['b' => true, 'sz' => 24, 'align' => 'center', 'color' => '1E3A8A']);
            $grid = '<w:tblGrid>'; foreach ($idx as $c) $grid .= '<w:gridCol w:w="' . $w[$c] . '"/>'; $grid .= '</w:tblGrid>';
            $tw = 0; foreach ($idx as $c) $tw += $w[$c];
            $tblRows = '';
            if ($this->headers) $tblRows .= $this->docxTr($this->headers, $idx, 'header');
            foreach ($this->rows as $r) {
                if ($r['type'] === 'section') $tblRows .= $this->docxTr([$r['cells'][0]], $idx, 'section', true);
                elseif ($r['type'] === 'head') $tblRows .= $this->docxTr($r['cells'], $idx, 'header');
                elseif ($r['type'] === 'total') $tblRows .= $this->docxTr($r['cells'], $idx, 'total');
                else $tblRows .= $this->docxTr($r['cells'], $idx, 'data');
            }
            $tbl .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="' . $tw . '" w:type="dxa"/><w:jc w:val="center"/><w:tblLayout w:type="fixed"/>'
                . ($this->isRtl() ? '<w:bidiVisual/>' : '')
                . '<w:tblBorders>'
                . '<w:top w:val="single" w:sz="4" w:color="8A8A8A"/><w:left w:val="single" w:sz="4" w:color="8A8A8A"/>'
                . '<w:bottom w:val="single" w:sz="4" w:color="8A8A8A"/><w:right w:val="single" w:sz="4" w:color="8A8A8A"/>'
                . '<w:insideH w:val="single" w:sz="4" w:color="8A8A8A"/><w:insideV w:val="single" w:sz="4" w:color="8A8A8A"/>'
                . '</w:tblBorders><w:tblCellMar><w:top w:w="15" w:type="dxa"/><w:left w:w="40" w:type="dxa"/><w:bottom w:w="15" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tblCellMar></w:tblPr>' . $grid . $tblRows . '</w:tbl>';
        }

        // إعداد الصفحة A4 + اتجاه + تذييل «صفحة n من N»
        $pgSz = $this->landscape ? '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567" w:header="283" w:footer="283"/>'
                                 : '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="283" w:footer="283"/>';
        $sect = '<w:sectPr><w:footerReference w:type="default" r:id="rIdF"/>' . $pgSz . ($this->isRtl() ? '<w:bidi/>' : '') . '</w:sectPr>';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $body . $tbl . $sect . '</w:body></w:document>';
        $footTxt = ($this->school ? (($this->school['name_ar'] ?? ($this->school['name_fr'] ?? '')) . '  ·  ') : '') . $this->title;
        $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:p><w:pPr>' . ($this->isRtl() ? '<w:bidi/>' : '') . '<w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="475569"/>' . ($this->isRtl() ? '<w:rtl/>' : '') . '</w:rPr><w:t xml:space="preserve">' . self::xa($footTxt) . '   —   ' . ($this->isRtl() ? 'صفحة ' : 'Page ') . '</w:t></w:r>'
            . '<w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="475569"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="475569"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>'
            . '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="475569"/>' . ($this->isRtl() ? '<w:rtl/>' : '') . '</w:rPr><w:t xml:space="preserve"> ' . ($this->isRtl() ? 'من' : '/') . ' </w:t></w:r>'
            . '<w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="475569"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:instrText xml:space="preserve"> NUMPAGES </w:instrText></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/><w:color w:val="475569"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>'
            . '</w:p></w:ftr>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdF" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            . '</Relationships>';

        $this->sendZip($this->fileBase() . '.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $doc,
            'word/_rels/document.xml.rels' => $docRels,
            'word/footer1.xml' => $footer,
        ]);
    }

    /** عرض نصّ بخطّ Arial بحجم $pt تقريباً — بوحدة twips (1/20 نقطة) */
    public static function twText(string $s, float $pt): int
    {
        $em = 0.0;
        if (preg_match_all('/./us', $s, $m)) {
            foreach ($m[0] as $ch) {
                $o = mb_ord($ch, 'UTF-8');
                if ($o >= 0x064B && $o <= 0x0652) continue;                // تشكيل
                if (ctype_digit($ch)) $em += 0.556;
                elseif ($ch === ',' || $ch === '.' || $ch === ' ' || $ch === ':') $em += 0.28;
                elseif ($o >= 0x0600 && $o <= 0x06FF) $em += 0.5;
                elseif (ctype_upper($ch)) $em += 0.68;
                elseif (ctype_alpha($ch)) $em += 0.52;
                else $em += 0.6;
            }
        }
        return (int)ceil($em * $pt * 20 * 1.06); // +6٪ أمان (Arial الحقيقي أعرض قليلاً من التقدير — «EMP1000024» كان يلتفّ)
    }

    /** عرض كل عمود (twips) بحجم خطّ $pt: الأرقام لا تنكسر، النصّ يلتفّ حتى أطول كلمة — يعيد [widths, sum] */
    private function docxWidths(int $cols, float $pt, int $twDoc, array $isText, bool $compress = true): array
    {
        $padC = 60 + 24; // حشوة الخلية يمين+يسار (30+30) + هامش صغير — بـ20 عموداً كل 50 twips زيادة تكلّف الاسم كلمة
        $noWrap = array_fill(0, $cols, 0); $full = array_fill(0, $cols, 0);
        $longestWord = function (string $t, float $pt) { $w = 0; foreach (preg_split('/[\s\x{00A0}]+/u', $t) as $wd) $w = max($w, self::twText($wd, $pt)); return $w; };
        $longestLine = function (string $t, float $pt) { $w = 0; foreach (preg_split('/\r\n|\n/', $t) as $ln) $w = max($w, self::twText($ln, $pt)); return $w; };
        for ($c = 0; $c < $cols; $c++) {
            $h = (string)($this->headers[$c] ?? '');
            $noWrap[$c] = max($noWrap[$c], $longestWord($h, $pt));
            $full[$c] = max($full[$c], min($longestLine($h, $pt), 2200)); // الرأس يلتفّ فوق ~3.9 سم
        }
        foreach ($this->rows as $r) {
            if ($r['type'] === 'section') continue;
            $span = $r['type'] === 'total' ? $this->totalLabelSpan($r['cells']) : -1;
            foreach ($r['cells'] as $c => $v) {
                $v = (string)$v; if ($v === '' || $c >= $cols) continue;
                if ($span >= 0 && $c <= $span) continue; // عنوان المجموع ممتدّ على عدّة أعمدة — لا يوسّع عموداً
                if ($this->isNum($v)) { $nv = $this->numVal($v); $disp = ($nv == floor($nv)) ? number_format($nv) : number_format($nv, 2); $tw = self::twText($disp, $pt) + ($r['type'] === 'total' ? (int)($pt * 2) : 0); $noWrap[$c] = max($noWrap[$c], $tw); $full[$c] = max($full[$c], $tw); }
                else { $noWrap[$c] = max($noWrap[$c], $longestWord($v, $pt)); $full[$c] = max($full[$c], min($longestLine($v, $pt), 4800)); } // النصّ الطويل جداً (> ~8.5 سم) يلتفّ
            }
        }
        $w = []; for ($c = 0; $c < $cols; $c++) $w[$c] = max($noWrap[$c], $full[$c]) + $padC;
        $sum = array_sum($w);
        $slack = []; $tot = 0; for ($c = 0; $c < $cols; $c++) { $s = max(0, $w[$c] - ($noWrap[$c] + $padC)); $slack[$c] = $s; $tot += $s; }
        if ($compress && $sum > $twDoc) {
            // ضغط أعمدة النصّ نحو أطول كلمة فيها — الأرقام لا تُمسّ، و**عمود الاسم آخر ما يُضغط** (الفئة والرؤوس تلتفّ قبل أن ينكسر اسم)
            $need = $sum - $twDoc;
            $isName = []; for ($c = 0; $c < $cols; $c++) $isName[$c] = preg_match('/الاسم|\bnom\b|\bname\b/iu', (string)($this->headers[$c] ?? '')) === 1;
            foreach ([false, true] as $pass) { // الجولة الأولى: كل الأعمدة عدا الاسم، الثانية: الاسم إن بقي عجز
                $t = 0; foreach ($slack as $c => $s) if ($isName[$c] === $pass) $t += $s;
                if ($need <= 0 || $t <= 0) continue;
                $f = min(1.0, $need / $t);
                foreach ($slack as $c => $s) { if ($isName[$c] !== $pass) continue; $d = (int)round($s * $f); $w[$c] -= $d; $need -= $d; }
            }
            $sum = array_sum($w);
        }
        return [$w, $sum, $tot];
    }

    /** أعمدة المفتاح (# إن كان ضيّقاً + عمود الاسم) تتكرّر بكل جزء؛ الأجزاء تُملأ بالتتابع حتى عرض الورقة — يعيد قائمة أجزاء كل منها قائمة أعمدة */
    private function docxParts(int $cols, array $w, int $twDoc): array
    {
        if (array_sum($w) <= $twDoc) return [range(0, $cols - 1)];
        $key = [];
        if ($cols > 3 && $w[0] <= 900) $key[] = 0;
        for ($c = 0; $c < min(4, $cols); $c++) { if (in_array($c, $key, true)) continue; if (preg_match('/الاسم|\bnom\b|\bname\b/iu', (string)($this->headers[$c] ?? '')) || (!isset($key[1]) && $c === 1)) { $key[] = $c; break; } }
        $keyW = 0; foreach ($key as $c) $keyW += $w[$c];
        if ($keyW > $twDoc * 0.45) { $key = $key ? [$key[0]] : []; $keyW = $key ? $w[$key[0]] : 0; }
        $parts = []; $cur = []; $curW = $keyW;
        for ($c = 0; $c < $cols; $c++) {
            if (in_array($c, $key, true)) continue;
            if ($cur && $curW + $w[$c] > $twDoc) { $parts[] = array_merge($key, $cur); $cur = []; $curW = $keyW; }
            $cur[] = $c; $curW += $w[$c];
        }
        if ($cur) $parts[] = array_merge($key, $cur);
        return $parts;
    }

    /** صفّ المجموع: الخلايا الأولى فارغة ثم عنوان نصّي ⇒ رقم عمود العنوان (ليمتدّ عليها كلّها)، وإلا −1 */
    private function totalLabelSpan(array $cells): int
    {
        $k = -1;
        foreach ($cells as $c => $v) { $v = (string)$v; if ($v === '') continue; if (!$this->isNum($v)) $k = $c; break; }
        if ($k < 0) return -1;
        $end = $k; $n = count($cells);
        while ($end + 1 < $n && (string)$cells[$end + 1] === '') $end++; // يمتدّ أيضاً على الخلايا الفارغة بعده
        return $end > 0 ? $end : -1;
    }

    private function docxPara($text, $o = [])
    {
        $rpr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>';
        if (!empty($o['b'])) $rpr .= '<w:b/><w:bCs/>';
        if (!empty($o['sz'])) $rpr .= '<w:sz w:val="' . (int)$o['sz'] . '"/><w:szCs w:val="' . (int)$o['sz'] . '"/>';
        if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        $rpr .= ($this->isRtl() ? '<w:rtl/>' : '') . '</w:rPr>';
        $ppr = '<w:pPr>' . ($this->isRtl() ? '<w:bidi/>' : '');
        if (!empty($o['align'])) $ppr .= '<w:jc w:val="' . $o['align'] . '"/>';
        if (!empty($o['fill'])) $ppr .= '<w:shd w:val="clear" w:fill="' . $o['fill'] . '"/>';
        $ppr .= '</w:pPr>';
        return '<w:p>' . $ppr . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::xa($text) . '</w:t></w:r></w:p>';
    }

    private function docxTr($cells, $idx, $kind, $mergeAll = false)
    {
        // 🖨️ رأس فاتح بخطّ أسود (يُطبع على أي طابعة — قاعدة 2026-10-17)، المجموع رمادي، القسم أزرق فاتح
        $fill = ['header' => 'E5E7EB', 'total' => 'F3F4F6', 'section' => 'E0E7FF', 'data' => ''][$kind] ?? '';
        $bold = in_array($kind, ['header', 'total', 'section'], true);
        $tr = '<w:tr>' . ($kind === 'header' ? '<w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>' : '<w:trPr><w:cantSplit/></w:trPr>');
        $shd = $fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '';
        $sumW = function (array $cs) { $s = 0; foreach ($cs as $c) $s += (int)($this->docxColWs[$c] ?? 0); return $s; };
        if ($mergeAll) {
            $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $sumW($idx) . '" w:type="dxa"/><w:gridSpan w:val="' . count($idx) . '"/>' . $shd . '<w:vAlign w:val="center"/></w:tcPr>'
                 . $this->docxCellPara($cells[0], $bold, $this->isRtl() ? 'right' : 'left', false) . '</w:tc>';
            return $tr . '</w:tr>';
        }
        $span = ($kind === 'total') ? $this->totalLabelSpan($cells) : -1;
        $c0 = 0;
        if ($span > 0) {
            // عنوان المجموع يمتدّ على الأعمدة النصّية الأولى (من # حتى عمود العنوان) — بالجزء الحالي فقط (الأجزاء التالية تحمل # والاسم فقط منها)
            $label = ''; for ($c = 0; $c <= $span; $c++) { $v = (string)($cells[$c] ?? ''); if ($v !== '') { $label = $v; break; } }
            $sc = array_values(array_filter($idx, fn($c) => $c <= $span));
            if ($sc) {
                $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $sumW($sc) . '" w:type="dxa"/>' . (count($sc) > 1 ? '<w:gridSpan w:val="' . count($sc) . '"/>' : '') . $shd . '<w:vAlign w:val="center"/></w:tcPr>'
                     . $this->docxCellPara($label, true, $this->isRtl() ? 'right' : 'left', false) . '</w:tc>';
            }
            $c0 = $span + 1;
        }
        foreach ($idx as $c) {
            if ($c < $c0) continue;
            $v = $cells[$c] ?? '';
            $num = $this->isNum($v) && $v !== '';
            if ($num && $this->isCodeCol($c)) $disp = (string)$v;
            elseif ($num) { $nv = $this->numVal($v); $disp = ($nv == floor($nv)) ? number_format($nv) : rtrim(rtrim(number_format($nv, 2), '0'), '.'); }
            else $disp = (string)$v;
            $align = $num ? ($this->isCodeCol($c) ? 'center' : 'right') : (($kind === 'header' || in_array(trim($disp), ['—', '-', '–', '✓', '✗', '×'], true)) ? 'center' : ($this->isRtl() ? 'right' : 'left'));
            $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)($this->docxColWs[$c] ?? 0) . '" w:type="dxa"/>' . $shd . ($num ? '<w:noWrap/>' : '') . '<w:vAlign w:val="center"/></w:tcPr>'
                 . $this->docxCellPara($disp, $bold, $align, $num) . '</w:tc>';
        }
        return $tr . '</w:tr>';
    }

    private function docxCellPara($text, $bold, $align, $num = false)
    {
        $sz = (int)round(($this->docxPt ?? 12.0) * 2);
        $rpr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>' . ($bold ? '<w:b/><w:bCs/>' : '')
             . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>' . ($this->isRtl() && !$num ? '<w:rtl/>' : '') . '</w:rPr>';
        $runs = ''; $lines = preg_split('/\r\n|\n/', (string)$text);
        foreach ($lines as $i => $ln) { if ($i > 0) $runs .= '<w:r>' . $rpr . '<w:br/></w:r>'; $runs .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::xa($ln) . '</w:t></w:r>'; }
        return '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>' . ($this->isRtl() && !$num ? '<w:bidi/>' : '') . '<w:jc w:val="' . $align . '"/></w:pPr>' . $runs . '</w:p>';
    }

    public function docxLegacy()
    {
        $cols = $this->colCount();
        // 🧊 نسخة مجمّدة (كما كانت قبل 2026-10-21) للبطاقة السنوية فقط — لا تُعدَّل
        // 📐 (2026-10-10 مساءً «رتّبلي كل التقارير… رسمي ومنظّم»): مولّد الوورد صار كبرامج التقارير الرسمية —
        //    • عرض كل عمود = قدّ محتواه الفعلي (رأس/بيانات) بخط 12 (لا أعمدة متساوية تلفّ الكلمات حرفاً حرفاً).
        //    • الجدول الأعرض من الورقة يُقسَّم **بالعرض** على أجزاء (ورقة لكل جزء) مع تكرار أعمدة المفتاح (# والاسم) بكل جزء،
        //      وعنوان «الجزء n/N» — كما تفعل أدوات التقارير (Crystal/Jasper) بدل تصغير الخطّ أو لفّ الأرقام.
        //    • أفقي تلقائياً حين لا يتّسع المحتوى عمودياً، ورأس الجدول يتكرّر بكل ورقة، والسطر داخل الخلية (ل.ل فوق $) سطر حقيقي.
        $autoW = $this->autoWidths($cols);
        $nat = []; foreach ($autoW as $c => $w) $nat[$c] = (int)round($w * 118 + 200); // وحدة الإكسل ⇒ twips بخط 12 + حشوة الخلية
        $sumNat = array_sum($nat);
        if ($this->orientMode === 'auto' && !$this->landscape && $sumNat > 10466) $this->landscape = true;
        $twDoc = $this->landscape ? 15700 : 10466; // عرض المحتوى داخل الهوامش (twips)

        // أعمدة المفتاح: الأوّل (#/الرمز) إن كان ضيّقاً + أوّل عمود نصّي بعده (الاسم) — تتكرّر بكل جزء
        $isText = []; for ($c = 0; $c < $cols; $c++) { $n = 0; $t = 0; foreach ($this->rows as $r) { if ($r['type'] !== 'data') continue; $v = (string)($r['cells'][$c] ?? ''); if ($v === '') continue; $n++; if (!$this->isNum($v)) $t++; } $isText[$c] = $n > 0 && $t / $n >= 0.5; }
        $key = [];
        if ($cols > 3) { if ($nat[0] <= 1400) $key[] = 0; for ($c = 0; $c < min(3, $cols); $c++) { if (!in_array($c, $key, true) && !empty($isText[$c])) { $key[] = $c; break; } } }
        $keyW = 0; foreach ($key as $c) $keyW += $nat[$c];
        if ($keyW > $twDoc * 0.4) { $key = $key ? [$key[0]] : []; $keyW = $key ? $nat[$key[0]] : 0; }
        // 📄 (2026-10-15 «العواميد كلّها نفس الصفحة… السطور ما في مشكلة صفحات عديدة»): لا تقسيم أعمدةً بالوورد — كل الأعمدة بورقة واحدة
        //    (أفقية إن لزم) والخط يصغر نسبياً ليسع العرض (حدّ أدنى 7pt)؛ الصفوف تمتدّ على صفحات
        $key = []; $keyW = 0;
        $parts = [range(0, $cols - 1)];
        // التصغير على النصّ وحده (حشوة الخلية 200 twips لا تتصغّر): نسبة = (عرض الورقة − الحشوات) ÷ (عرض النصوص)، حدّ أدنى 6pt
        $pad = 200 * $cols; $txtNat = max(1, $sumNat - $pad);
        $this->docxFontScale = ($sumNat > $twDoc) ? max(0.5, ($twDoc - $pad) / $txtNat) : 1.0;
        if ($sumNat > $twDoc) { foreach ($nat as $c => $v) $nat[$c] = (int)round(($v - 200) * (($twDoc - $pad) / $txtNat) + 200); }

        $body = '';
        if ($this->titleLines) {
            // كتلة العنوان كما بالورقة: ترويسة (غامق) ← عنوان (كبير) ← معلومات (رمادي)
            foreach ($this->titleLines as $ln) {
                $k = $ln['k'] ?? 'meta';
                $body .= $this->docxPara($ln['t'], $k === 'title' ? ['b' => true, 'sz' => 30, 'align' => 'center', 'color' => '1E3A8A'] : ($k === 'school' ? ['b' => true, 'sz' => 24, 'align' => 'center'] : ['sz' => 20, 'align' => 'center', 'color' => '475569']));
            }
        } else {
            // العنوان
            $body .= $this->docxPara($this->title, ['b' => true, 'sz' => 30, 'align' => 'center', 'color' => '1E3A8A']);
            if ($this->period) $body .= $this->docxPara($this->period, ['b' => true, 'sz' => 22, 'align' => 'center']);
            if ($this->school) {
                $sn = $this->school['name_ar'] ?? ($this->school['name_fr'] ?? '');
                $extra = trim(($this->school['address'] ?? ''));
                $body .= $this->docxPara($sn . ($extra ? ' — ' . $extra : ''), ['b' => true, 'sz' => 22, 'align' => 'center']);
            }
        }
        $body .= '<w:p/>';

        $tbl = ''; $np = count($parts);
        foreach ($parts as $pi => $part) {
            $idx = array_merge($key, $part);
            // عرض الأعمدة بهذا الجزء: قدّ المحتوى ثم تمديد نسبي ليملأ الورقة (لا فراغ يمين/يسار)
            $w = []; $sum = 0; foreach ($idx as $c) { $w[$c] = $nat[$c]; $sum += $nat[$c]; }
            $scale = $sum > 0 ? $twDoc / $sum : 1; $acc = 0; $last = end($idx);
            foreach ($idx as $c) { $w[$c] = ($c === $last) ? ($twDoc - $acc) : (int)round($nat[$c] * $scale); $acc += $w[$c]; }
            $this->docxColWs = $w;
            if ($pi > 0) $tbl .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            if ($np > 1) $tbl .= $this->docxPara(($this->isRtl() ? 'الجزء ' . ($pi + 1) . ' من ' . $np : 'Partie ' . ($pi + 1) . ' / ' . $np) . ' — ' . $this->title, ['b' => true, 'sz' => 22, 'align' => 'center', 'color' => '1E3A8A']);
            $grid = '<w:tblGrid>'; foreach ($idx as $c) $grid .= '<w:gridCol w:w="' . $w[$c] . '"/>'; $grid .= '</w:tblGrid>';
            $tblRows = '';
            if ($this->headers) $tblRows .= $this->docxTrLegacy($this->headers, $idx, 'header');
            foreach ($this->rows as $r) {
                if ($r['type'] === 'section') $tblRows .= $this->docxTrLegacy([$r['cells'][0]], $idx, 'section', true);
                elseif ($r['type'] === 'head') $tblRows .= $this->docxTrLegacy($r['cells'], $idx, 'header');
                elseif ($r['type'] === 'total') $tblRows .= $this->docxTrLegacy($r['cells'], $idx, 'total');
                else $tblRows .= $this->docxTrLegacy($r['cells'], $idx, 'data');
            }
            $tbl .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="' . $twDoc . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
                . ($this->isRtl() ? '<w:bidiVisual/>' : '')
                . '<w:tblBorders>'
                . '<w:top w:val="single" w:sz="4" w:color="777777"/><w:left w:val="single" w:sz="4" w:color="777777"/>'
                . '<w:bottom w:val="single" w:sz="4" w:color="777777"/><w:right w:val="single" w:sz="4" w:color="777777"/>'
                . '<w:insideH w:val="single" w:sz="4" w:color="777777"/><w:insideV w:val="single" w:sz="4" w:color="777777"/>'
                . '</w:tblBorders><w:tblCellMar><w:left w:w="60" w:type="dxa"/><w:right w:w="60" w:type="dxa"/></w:tblCellMar></w:tblPr>' . $grid . $tblRows . '</w:tbl>';
        }

        // إعداد الصفحة A4 + اتجاه
        $sect = $this->landscape
            ? '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567"/>' . ($this->isRtl() ? '<w:bidi/>' : '') . '</w:sectPr>'
            : '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/>' . ($this->isRtl() ? '<w:bidi/>' : '') . '</w:sectPr>';

        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . $tbl . $sect . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $this->sendZip($this->fileBase() . '.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $doc,
        ]);
    }


    private function docxTrLegacy($cells, $idx, $kind, $mergeAll = false)
    {
        $fill = ['header' => '1E3A8A', 'total' => 'F3F4F6', 'section' => 'E0E7FF', 'data' => ''][$kind] ?? '';
        $bold = in_array($kind, ['header', 'total', 'section'], true);
        $white = ($kind === 'header');
        $tr = '<w:tr>' . ($kind === 'header' ? '<w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>' : '<w:trPr><w:cantSplit/></w:trPr>'); // الرأس يتكرّر بكل ورقة، والسطر لا ينقسم بين ورقتين
        if ($mergeAll) {
            $tc = '<w:tcPr><w:tcW w:w="0" w:type="auto"/><w:gridSpan w:val="' . count($idx) . '"/>';
            $tc .= $fill ? '<w:shd w:val="clear" w:fill="' . $fill . '"/>' : '';
            $tc .= '<w:vAlign w:val="center"/></w:tcPr>';
            $tr .= '<w:tc>' . $tc . $this->docxCellParaLegacy($cells[0], $bold, $white, $this->isRtl() ? 'right' : 'left') . '</w:tc>';
        } else {
            foreach ($idx as $c) {
                $v = $cells[$c] ?? '';
                $align = ($this->isNum($v) && $v !== '') ? 'center' : ($kind === 'header' ? 'center' : ($this->isRtl() ? 'right' : 'left'));
                if ($this->isNum($v) && $v !== '') {
                    $nv = $this->numVal($v);
                    $disp = ($nv == floor($nv)) ? number_format($nv) : rtrim(rtrim(number_format($nv, 2), '0'), '.');
                } else { $disp = $v; }
                $tc = '<w:tcPr><w:tcW w:w="' . (int)($this->docxColWs[$c] ?? 0) . '" w:type="' . (isset($this->docxColWs[$c]) ? 'dxa' : 'auto') . '"/>';
                $tc .= $fill ? '<w:shd w:val="clear" w:fill="' . $fill . '"/>' : '';
                $tc .= '<w:vAlign w:val="center"/></w:tcPr>';
                $tr .= '<w:tc>' . $tc . $this->docxCellParaLegacy($disp, $bold, $white, $align) . '</w:tc>';
            }
        }
        return $tr . '</w:tr>';
    }

    private function docxCellParaLegacy($text, $bold, $white, $align)
    {
        $rpr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>';
        if ($bold) $rpr .= '<w:b/><w:bCs/>';
        if ($white) $rpr .= '<w:color w:val="FFFFFF"/>';
        $szc = (int)max(14, round(24 * ($this->docxFontScale ?? 1.0)));   // 📄 12pt، ويصغر نسبياً فقط حين لا تسع الأعمدة عرض الورقة
        $rpr .= '<w:sz w:val="' . $szc . '"/><w:szCs w:val="' . $szc . '"/>' . ($this->isRtl() ? '<w:rtl/>' : '') . '</w:rPr>';   // 🔠 12pt بكل المطبوعات
        $runs = ''; $lines = preg_split('/\r\n|\n/', (string)$text);
        foreach ($lines as $i => $ln) { if ($i > 0) $runs .= '<w:r>' . $rpr . '<w:br/></w:r>'; $runs .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::xa($ln) . '</w:t></w:r>'; }
        return '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/>' . ($this->isRtl() ? '<w:bidi/>' : '') . '<w:jc w:val="' . $align . '"/></w:pPr>' . $runs . '</w:p>';
    }


    private $savePath = null;
    /** للاختبار/التوليد على الخادم: احفظ الملف بدل إرساله للتنزيل. */
    public function to($path) { $this->savePath = $path; return $this; }

    /** مسار مفسّر بايثون (openpyxl/python-docx) إن وُجد — للتوليد الاحترافي. */
    private static function pythonExe()
    {
        foreach (['C:/Python314/python.exe', 'C:/Python313/python.exe', 'C:/Python312/python.exe', 'python'] as $p) {
            if ($p === 'python' || @is_file($p)) return $p;
        }
        return null;
    }

    /**
     * توليد احترافي عبر مكتبات Python (openpyxl/python-docx) — أنظف وأقوى من بناء OOXML يدوياً.
     * يبني spec JSON من بيانات الجدول ويستدعي tools/make_report.py. يعود false إذا تعذّر (fallback لليدوي).
     */
    public function pyExport($format)
    {
        $py = self::pythonExe();
        $script = __DIR__ . '/../tools/make_report.py';
        if (!$py || !is_file($script)) return false;
        // مجلد temp داخل البرنامج (C:\WINDOWS\TEMP قد لا يكون قابلاً للكتابة من Apache)
        $tmpDir = __DIR__ . '/../tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $uid = uniqid('rep', true);
        $out = $tmpDir . '/' . $uid . '.' . $format;
        $sch = null;
        if ($this->school) {
            $sch = ['name' => $this->school['name_ar'] ?? ($this->school['name_fr'] ?? ''), 'address' => $this->school['address'] ?? ''];
        }
        $spec = [
            'format' => $format, 'out' => str_replace('\\', '/', $out),
            'title' => $this->title, 'period' => $this->period, 'school' => $sch,
            'landscape' => (bool)$this->landscape, 'headers' => $this->headers,
            'widths' => $this->colWidths,
            'rows' => array_map(function ($r) {
                return ['type' => $r['type'], 'cells' => array_map(function ($c) {
                    return (is_int($c) || is_float($c)) ? $c : (string)$c;
                }, $r['cells'])];
            }, $this->rows),
        ];
        $json = json_encode($spec, JSON_UNESCAPED_UNICODE);
        $jsonFile = $tmpDir . '/' . $uid . '.json';
        file_put_contents($jsonFile, $json);
        // تمرير ملف JSON كـ argument (أمتن من stdin عبر Apache)
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jsonFile) . ' 2>&1';
        $res = @shell_exec($cmd);
        @unlink($jsonFile);
        if (!empty($_GET['pydebug'])) { @file_put_contents($tmpDir . '/pyrep_debug.txt', "CMD: $cmd\nOUT: $res\nOUT_EXISTS: " . (is_file($out) ? filesize($out) : 'no')); }
        if (!is_file($out) || filesize($out) < 200) { @unlink($out); return false; }
        $data = file_get_contents($out);
        @unlink($out);
        if ($this->savePath) { file_put_contents($this->savePath, $data); return true; }
        $mime = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $this->fileBase() . '.' . $format . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache');
        echo $data;
        exit;
    }

    /** مسار LibreOffice soffice (لتحويل التقارير إلى PDF رسمي) إن وُجد. */
    private static function sofficeExe()
    {
        foreach ([
            'C:/Program Files/LibreOffice/program/soffice.exe',
            'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
        ] as $p) { if (@is_file($p)) return $p; }
        return null;
    }

    /**
     * PDF رسمي عبر LibreOffice: يولّد xlsx بـopenpyxl ثم يحوّله soffice إلى PDF (A4 مضبوط بلا أخطاء).
     * يعود false إذا LibreOffice غير منصَّب (يقع المنادي على الطباعة).
     */
    public function pdf()
    {
        $soffice = self::sofficeExe();
        if (!$soffice) return false;
        $tmpDir = __DIR__ . '/../tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $uid = uniqid('pdf', true);
        $xlsx = $tmpDir . '/' . $uid . '.xlsx';
        $prev = $this->savePath;
        $this->savePath = $xlsx;
        $ok = $this->pyExport('xlsx');           // اكتب xlsx لملف
        $this->savePath = $prev;
        if (!$ok || !is_file($xlsx)) return false;
        // soffice يحتاج مجلد profile قابل للكتابة (Apache)
        $profile = str_replace('\\', '/', $tmpDir . '/lo_profile');
        $cmd = escapeshellarg($soffice) . ' --headless --norestore'
            . ' -env:UserInstallation=file:///' . $profile
            . ' --convert-to pdf:calc_pdf_Export --outdir ' . escapeshellarg(str_replace('\\', '/', $tmpDir))
            . ' ' . escapeshellarg(str_replace('\\', '/', $xlsx)) . ' 2>&1';
        $res = @shell_exec($cmd);
        $pdf = $tmpDir . '/' . $uid . '.pdf';
        @unlink($xlsx);
        if (!empty($_GET['pydebug'])) @file_put_contents($tmpDir . '/pdf_debug.txt', "CMD: $cmd\nOUT: $res\nPDF: " . (is_file($pdf) ? filesize($pdf) : 'no'));
        if (!is_file($pdf) || filesize($pdf) < 400) { @unlink($pdf); return false; }
        $data = file_get_contents($pdf);
        @unlink($pdf);
        if ($this->savePath) { file_put_contents($this->savePath, $data); return true; }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $this->fileBase() . '.pdf"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache');
        echo $data;
        exit;
    }

    /** تصدير: PDF رسمي عبر LibreOffice، أو Excel/Word عبر Python، وإلا المولّد اليدوي المدمج. */
    public function export($format)
    {
        if ($format === 'pdf') { if ($this->pdf()) return; $format = 'xlsx'; } // fallback لو LibreOffice غير منصَّب
        // 📐 (2026-10-08) الإكسل دائماً من المولّد المدمج (أعمدة على قدّ المحتوى + ارتفاع الأسطر + فلتر + رأس يتكرّر بالطباعة + الاتجاه) —
        //    مولّد بايثون كان يتجاهلها ولا وجود له أونلاين. الوورد يبقى عبر بايثون حيث يتوفّر وإلا المدمج.
        if ($format === 'xlsx') { $this->xlsx(); return; }
        if ($this->legacy) { if ($this->pyExport($format)) return; $this->docxLegacy(); return; } // 🧊 البطاقة السنوية كما كانت
        // 📝 (2026-10-21) الوورد أيضاً من المولّد المدمج دائماً (أرقام لا تنكسر + خطّ بحسب عدد الأعمدة + تذييل) — مولّد بايثون كان يكسر الأرقام ولا وجود له أونلاين
        $this->docx();
    }

    /* ============================== ZIP بناء/إرسال ============================== */
    private function zipBytes($parts)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rep');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($parts as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private function sendZip($filename, $mime, $parts)
    {
        $data = $this->zipBytes($parts);
        if ($this->savePath) { file_put_contents($this->savePath, $data); return; } // وضع الحفظ (تست/خادم)
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache');
        echo $data;
        exit;
    }
}
