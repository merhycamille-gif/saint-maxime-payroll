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
    private $school = null;
    private $period = '';

    public function __construct($title, $landscape = true)
    {
        $this->title = $title;
        $this->landscape = $landscape;
    }

    public function schoolHeader($school) { $this->school = $school; return $this; }
    public function period($p) { $this->period = $p; return $this; }
    public function widths($w) { $this->colWidths = $w; return $this; }

    public function head($cells) { $this->headers = array_values($cells); return $this; }
    public function row($cells) { $this->rows[] = ['type' => 'data', 'cells' => array_values($cells)]; return $this; }
    public function totalRow($cells) { $this->rows[] = ['type' => 'total', 'cells' => array_values($cells)]; return $this; }
    public function sectionRow($label) { $this->rows[] = ['type' => 'section', 'cells' => [$label]]; return $this; }

    private function colCount()
    {
        $n = count($this->headers);
        foreach ($this->rows as $r) $n = max($n, count($r['cells']));
        return max(1, $n);
    }

    private function isNum($v) { return is_int($v) || is_float($v) || (is_string($v) && $v !== '' && preg_match('/^-?\d+(\.\d+)?$/', str_replace(',', '', $v))); }
    private function numVal($v) { return (float)str_replace(',', '', (string)$v); }

    private static function xa($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    private function fileBase()
    {
        $t = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $this->title);
        return mb_substr($t, 0, 60, 'UTF-8') . '_' . date('Y-m-d');
    }

    /* ============================== XLSX ============================== */
    public function xlsx()
    {
        $cols = $this->colCount();
        $colLetter = function ($i) { // 0-based → A,B,...
            $s = ''; $i++;
            while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
            return $s;
        };

        $rowsXml = ''; $rIdx = 0;
        $addRow = function ($cells, $styleText, $styleNum, $mergeAll = false) use (&$rowsXml, &$rIdx, $cols, $colLetter) {
            $rIdx++;
            $rowsXml .= '<row r="' . $rIdx . '">';
            if ($mergeAll) {
                $rowsXml .= '<c r="A' . $rIdx . '" s="' . $styleText . '" t="inlineStr"><is><t xml:space="preserve">' . self::xa($cells[0]) . '</t></is></c>';
                for ($c = 1; $c < $cols; $c++) $rowsXml .= '<c r="' . $colLetter($c) . $rIdx . '" s="' . $styleText . '"/>';
            } else {
                for ($c = 0; $c < $cols; $c++) {
                    $ref = $colLetter($c) . $rIdx;
                    $v = $cells[$c] ?? '';
                    if ($this->isNum($v) && $v !== '') {
                        $rowsXml .= '<c r="' . $ref . '" s="' . $styleNum . '"><v>' . $this->numVal($v) . '</v></c>';
                    } else {
                        $rowsXml .= '<c r="' . $ref . '" s="' . $styleText . '" t="inlineStr"><is><t xml:space="preserve">' . self::xa($v) . '</t></is></c>';
                    }
                }
            }
            $rowsXml .= '</row>';
        };

        // أنماط: 1=عنوان, 2=ترويسة مدرسة, 3=رأس عمود, 4=نص بيانات, 5=رقم بيانات, 6=نص مجموع, 7=رقم مجموع, 8=قسم
        // عنوان كبير
        $addRow([$this->title], 1, 1, true);
        if ($this->period) $addRow([$this->period], 2, 2, true);
        if ($this->school) {
            $sn = $this->school['name_ar'] ?? ($this->school['name_fr'] ?? '');
            $extra = trim(($this->school['address'] ?? ''));
            $addRow([$sn . ($extra ? ' — ' . $extra : '')], 2, 2, true);
        }
        $addRow([''], 4, 4, true); // سطر فاصل
        $titleRows = $rIdx;

        // رؤوس
        if ($this->headers) $addRow($this->headers, 3, 3);
        $headerRow = $rIdx;

        // بيانات
        foreach ($this->rows as $r) {
            if ($r['type'] === 'section') $addRow($r['cells'], 8, 8, true);
            elseif ($r['type'] === 'total') $addRow($r['cells'], 6, 7);
            else $addRow($r['cells'], 4, 5);
        }

        // دمج صفوف العنوان/الترويسة عبر كل الأعمدة
        $merges = '';
        for ($m = 1; $m <= $titleRows; $m++) $merges .= '<mergeCell ref="A' . $m . ':' . $colLetter($cols - 1) . $m . '"/>';
        // دمج صفوف الأقسام والمجاميع غير الرقمية
        $rr = $titleRows + ($this->headers ? 1 : 0);
        foreach ($this->rows as $r) { $rr++; if ($r['type'] === 'section') $merges .= '<mergeCell ref="A' . $rr . ':' . $colLetter($cols - 1) . $rr . '"/>'; }
        $mergesXml = $merges ? '<mergeCells count="' . substr_count($merges, '<mergeCell') . '">' . $merges . '</mergeCells>' : '';

        // أعمدة
        $colsXml = '<cols>';
        for ($c = 0; $c < $cols; $c++) {
            $w = $this->colWidths[$c] ?? ($c === 1 ? 32 : 16);
            $colsXml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $orient = $this->landscape ? 'landscape' : 'portrait';
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0" tabSelected="1">'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $mergesXml
            . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="' . $orient . '" fitToWidth="1" fitToHeight="0"/>'
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
            . '<sheets><sheet name="' . self::xa(mb_substr($this->title, 0, 28, 'UTF-8')) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
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

    private function xlsxStyles()
    {
        // ألوان: رأس أزرق #1E3A8A نص أبيض، مجموع رمادي فاتح، قسم أزرق فاتح
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.##"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Arial"/></font>'                                  // 0 default
            . '<font><b/><sz val="15"/><color rgb="FF1E3A8A"/><name val="Arial"/></font>'        // 1 title
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'        // 2 header white
            . '<font><sz val="11"/><name val="Arial"/></font>'                                   // 3 data
            . '<font><b/><sz val="11"/><name val="Arial"/></font>'                               // 4 bold
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'                                   // 0
            . '<fill><patternFill patternType="gray125"/></fill>'                                // 1
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/></patternFill></fill>' // 2 header blue
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/></patternFill></fill>' // 3 total grey
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/></patternFill></fill>' // 4 section light blue
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'                       // 0 none
            . '<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right>'
            . '<top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border>' // 1 thin grey
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 1 title
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // 2 subheader
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 3 col header
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' // 4 data text
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' // 5 data num
            . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' // 6 total text
            . '<xf numFmtId="164" fontId="4" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>' // 7 total num
            . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>' // 8 section
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /* ============================== DOCX ============================== */
    public function docx()
    {
        $cols = $this->colCount();
        $twDoc = $this->landscape ? 15840 : 11900; // عرض المحتوى تقريباً (twips) داخل الهوامش
        $colW = (int)floor($twDoc / $cols);

        $body = '';
        // العنوان
        $body .= $this->docxPara($this->title, ['b' => true, 'sz' => 30, 'align' => 'center', 'color' => '1E3A8A']);
        if ($this->period) $body .= $this->docxPara($this->period, ['b' => true, 'sz' => 22, 'align' => 'center']);
        if ($this->school) {
            $sn = $this->school['name_ar'] ?? ($this->school['name_fr'] ?? '');
            $extra = trim(($this->school['address'] ?? ''));
            $body .= $this->docxPara($sn . ($extra ? ' — ' . $extra : ''), ['b' => true, 'sz' => 22, 'align' => 'center']);
        }
        $body .= '<w:p/>';

        // الجدول
        $grid = '<w:tblGrid>';
        for ($c = 0; $c < $cols; $c++) $grid .= '<w:gridCol w:w="' . $colW . '"/>';
        $grid .= '</w:tblGrid>';

        $tblRows = '';
        if ($this->headers) $tblRows .= $this->docxTr($this->headers, $cols, 'header');
        foreach ($this->rows as $r) {
            if ($r['type'] === 'section') $tblRows .= $this->docxTr([$r['cells'][0]], $cols, 'section', true);
            elseif ($r['type'] === 'total') $tblRows .= $this->docxTr($r['cells'], $cols, 'total');
            else $tblRows .= $this->docxTr($r['cells'], $cols, 'data');
        }

        $tbl = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="' . $twDoc . '" w:type="dxa"/>'
            . '<w:bidiVisual/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="777777"/><w:left w:val="single" w:sz="4" w:color="777777"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="777777"/><w:right w:val="single" w:sz="4" w:color="777777"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="777777"/><w:insideV w:val="single" w:sz="4" w:color="777777"/>'
            . '</w:tblBorders></w:tblPr>' . $grid . $tblRows . '</w:tbl>';

        // إعداد الصفحة A4 + اتجاه
        $sect = $this->landscape
            ? '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567"/><w:bidi/></w:sectPr>'
            : '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/><w:bidi/></w:sectPr>';

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

    private function docxPara($text, $o = [])
    {
        $rpr = '<w:rPr>';
        if (!empty($o['b'])) $rpr .= '<w:b/>';
        if (!empty($o['sz'])) $rpr .= '<w:sz w:val="' . (int)$o['sz'] . '"/>';
        if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        $rpr .= '<w:rtl/></w:rPr>';
        $ppr = '<w:pPr><w:bidi/>';
        if (!empty($o['align'])) $ppr .= '<w:jc w:val="' . $o['align'] . '"/>';
        if (!empty($o['fill'])) $ppr .= '<w:shd w:val="clear" w:fill="' . $o['fill'] . '"/>';
        $ppr .= '</w:pPr>';
        return '<w:p>' . $ppr . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::xa($text) . '</w:t></w:r></w:p>';
    }

    private function docxTr($cells, $cols, $kind, $mergeAll = false)
    {
        $fill = ['header' => '1E3A8A', 'total' => 'F3F4F6', 'section' => 'E0E7FF', 'data' => ''][$kind] ?? '';
        $bold = in_array($kind, ['header', 'total', 'section'], true);
        $white = ($kind === 'header');
        $tr = '<w:tr>';
        if ($mergeAll) {
            $tc = '<w:tcPr><w:tcW w:w="0" w:type="auto"/><w:gridSpan w:val="' . $cols . '"/>';
            $tc .= $fill ? '<w:shd w:val="clear" w:fill="' . $fill . '"/>' : '';
            $tc .= '<w:vAlign w:val="center"/></w:tcPr>';
            $tr .= '<w:tc>' . $tc . $this->docxCellPara($cells[0], $bold, $white, 'right') . '</w:tc>';
        } else {
            for ($c = 0; $c < $cols; $c++) {
                $v = $cells[$c] ?? '';
                $align = ($this->isNum($v) && $v !== '') ? 'center' : ($kind === 'header' ? 'center' : 'right');
                if ($this->isNum($v) && $v !== '') {
                    $nv = $this->numVal($v);
                    $disp = ($nv == floor($nv)) ? number_format($nv) : rtrim(rtrim(number_format($nv, 2), '0'), '.');
                } else { $disp = $v; }
                $tc = '<w:tcPr><w:tcW w:w="0" w:type="auto"/>';
                $tc .= $fill ? '<w:shd w:val="clear" w:fill="' . $fill . '"/>' : '';
                $tc .= '<w:vAlign w:val="center"/></w:tcPr>';
                $tr .= '<w:tc>' . $tc . $this->docxCellPara($disp, $bold, $white, $align) . '</w:tc>';
            }
        }
        return $tr . '</w:tr>';
    }

    private function docxCellPara($text, $bold, $white, $align)
    {
        $rpr = '<w:rPr>';
        if ($bold) $rpr .= '<w:b/>';
        if ($white) $rpr .= '<w:color w:val="FFFFFF"/>';
        $rpr .= '<w:sz w:val="' . ($this->landscape ? 18 : 20) . '"/><w:rtl/></w:rPr>';
        return '<w:p><w:pPr><w:bidi/><w:jc w:val="' . $align . '"/></w:pPr>'
            . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::xa($text) . '</w:t></w:r></w:p>';
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
        if ($this->pyExport($format)) return;
        if ($format === 'docx') $this->docx(); else $this->xlsx();
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
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache');
        echo $data;
        exit;
    }
}
