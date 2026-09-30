<?php
/**
 * 📗 بطاقة الراتب السنوية إكسل «طبق الأصل عن الـPDF» (2026-09-30 «عم اطبع البطاقة السنوية إكسل وفاضية من المبالغ — شوف كيف طلعت،
 * بدّي ياها تطلع بالضبط متل ما بتطلع PDF»): الإكسل كان جدولاً عاماً (ReportTable) لا يشبه البطاقة ويتجاهل «فاضية من المبالغ».
 *
 * المصدر الواحد = HTML البطاقة نفسه (annualSlipHtml بكل خياراته: العملة، الأعمدة الظاهرة، الفاضية blank=1/2) ⇒ يُحوَّل هنا إلى ورقة
 * إكسل بنفس الشكل: شريط الاسم (المدرسة · الاسم · العنوان)، سطر سعر الصرف، جدول المعلومات 3×4 (التسمية صغيرة فوق القيمة)،
 * جدول الرواتب برأسَين (المحسومات بالأحمر الفاتح)، الأشهر، TOTAL، التوقيع — A4 أفقي، بطاقة لكل ورقة.
 * أي تغيير بالبطاقة يصل للإكسل تلقائياً. مولَّد بـPHP صِرف (ZipArchive) — يعمل على الخادم بلا Python/LibreOffice.
 */

/** HTML بطاقة واحدة ⇒ بنية (null إن لم يكن بطاقة). */
function annualSlipXlsxParse(string $html): ?array
{
    $txt = function (string $s): string {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    };
    $lines = function (string $s) use ($txt): array {
        $out = [];
        foreach (preg_split('#<br\s*/?>#i', $s) as $part) { $t = $txt($part); if ($t !== '') $out[] = $t; }
        return $out;
    };
    if (!preg_match('#<table class="salary-slip-table curmode-(\w+)[^"]*">(.*?)</table>#su', $html, $tm)) return null;
    $mode = $tm[1];
    $card = ['school' => '', 'name' => '', 'rep' => '', 'rate' => '', 'info' => [], 'head' => [], 'rows' => [], 'mode' => $mode];
    foreach (['school' => 'slip-school', 'name' => 'slip-pname', 'rep' => 'slip-rep'] as $k => $cls) {
        if (preg_match('#<span class="' . $cls . '">(.*?)</span>#su', $html, $m)) $card[$k] = $txt($m[1]);
    }
    if (preg_match('#<div class="slip-rate"[^>]*>(.*?)</div>#su', $html, $m)) $card['rate'] = $txt($m[1]);
    // جدول المعلومات: صفوف × خانات (تسمية + قيمة)
    if (preg_match('#<table class="slip-info">(.*?)</table>#su', $html, $im)) {
        preg_match_all('#<tr[^>]*>(.*?)</tr>#su', $im[1], $trs);
        foreach ($trs[1] as $tr) {
            $row = [];
            preg_match_all('#<td([^>]*)>(.*?)</td>#su', $tr, $tds, PREG_SET_ORDER);
            foreach ($tds as $td) {
                $l = preg_match('#<span class="lbl">(.*?)</span>#su', $td[2], $x) ? $txt($x[1]) : '';
                $v = preg_match('#<span class="val"[^>]*>(.*?)</span>#su', $td[2], $x) ? $txt($x[1]) : '';
                $row[] = ['l' => $l, 'v' => $v, 'cs' => preg_match('/colspan="(\d+)"/', $td[1], $c) ? max(1, (int)$c[1]) : 1];
            }
            if ($row) $card['info'][] = $row;
        }
    }
    // رأس الجدول (صفّان) ثم الصفوف
    if (preg_match('#<thead>(.*?)</thead>#su', $tm[2], $hm)) {
        preg_match_all('#<tr[^>]*>(.*?)</tr>#su', $hm[1], $trs);
        foreach ($trs[1] as $tr) {
            $row = [];
            preg_match_all('#<th([^>]*)>(.*?)</th>#su', $tr, $ths, PREG_SET_ORDER);
            foreach ($ths as $th) {
                $row[] = [
                    'lines' => $lines($th[2]),
                    'rs' => preg_match('/rowspan="(\d+)"/', $th[1], $c) ? max(1, (int)$c[1]) : 1,
                    'cs' => preg_match('/colspan="(\d+)"/', $th[1], $c) ? max(1, (int)$c[1]) : 1,
                    'ded' => strpos($th[1], 'deduction-header') !== false,
                    'sig' => strpos($th[1], 'sig-col') !== false,
                ];
            }
            if ($row) $card['head'][] = $row;
        }
    }
    $body = preg_replace('#<thead>.*?</thead>#su', '', $tm[2]);
    preg_match_all('#<tr([^>]*)>(.*?)</tr>#su', $body, $trs, PREG_SET_ORDER);
    foreach ($trs as $tr) {
        $cells = [];
        preg_match_all('#<td([^>]*)>(.*?)</td>#su', $tr[2], $tds, PREG_SET_ORDER);
        foreach ($tds as $td) {
            $inner = $td[2];
            if (preg_match('#<span class="sub-lbp">(.*?)</span>\s*<span class="cur-usd">(.*?)</span>#su', $inner, $x)) {
                $l = $txt($x[1]); $u = $txt($x[2]);
                $ls = $mode === 'lbp' ? [$l] : ($mode === 'usd' ? [$u] : [$l, $u]); // وضع العملة كالشاشة
            } else {
                $t = $txt($inner);
                $ls = $t === '' ? [] : [$t];
            }
            $cells[] = [
                'lines' => $ls,
                'cs' => preg_match('/colspan="(\d+)"/', $td[1], $c) ? max(1, (int)$c[1]) : 1,
                'bold' => strpos($inner, '<strong>') !== false || strpos($td[1], 'row-month') !== false,
                'month' => strpos($td[1], 'row-month') !== false,
            ];
        }
        if ($cells) $card['rows'][] = ['total' => strpos($tr[1], 'total-row') !== false, 'cells' => $cells];
    }
    if (!$card['head'] || !$card['rows']) return null;
    // أعمدة الجدول الفعلية (الأوراق): خانة rowspan=2 عمود، ومجموعة colspan تأخذ أعمدتها من الصف الثاني بالترتيب
    $leaf = [];
    foreach ($card['head'][0] as $i => $h) {
        if ($h['cs'] > 1 || $h['rs'] < 2) { for ($k = 0; $k < $h['cs']; $k++) $leaf[] = ['w' => 1.1]; }
        else $leaf[] = ['w' => $i === 0 ? 1.0 : ($h['sig'] ? 1.5 : 1.1)];
    }
    $card['leaf'] = $leaf;
    return $card;
}

/** كل بطاقات صفحة (HTML فيه بطاقة أو أكثر) ⇒ قائمة بُنى. */
function annualSlipXlsxParseAll(string $html): array
{
    $cards = [];
    foreach (preg_split('#(?=<div class="salary-slip">)#u', $html) as $chunk) {
        if (strpos($chunk, '<div class="salary-slip">') !== 0) continue;
        $c = annualSlipXlsxParse($chunk);
        if ($c) $cards[] = $c;
    }
    return $cards;
}

/** مولّد ورقة الإكسل: سجلّ أنماط + خلايا + دمج. */
final class AnnualSlipXlsx
{
    private const FONT = 'Arial';
    private const BORDER = 'FF7F7F7F';
    private array $fonts = [], $fills = [], $borders = [], $xfs = [], $xfKey = [];
    private array $cells = [], $merges = [], $heights = [], $breaks = [];
    private array $bounds = [0.0, 1.0];

    public function __construct()
    {
        $this->fonts = ['<font><sz val="10"/><name val="' . self::FONT . '"/><family val="2"/></font>'];
        $this->fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $this->borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
        $this->style([]); // xf 0 الافتراضي
    }

    private static function xa(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    private static function idx(array &$list, string $xml): int
    {
        $i = array_search($xml, $list, true);
        if ($i === false) { $list[] = $xml; $i = count($list) - 1; }
        return (int)$i;
    }

    /** نمط خلية: fill (rgb) · border (all|total|none) · h · v · wrap · shrink · num · ro (اتجاه القراءة 1 LTR / 2 RTL) · sz/bold/color لخلايا الأرقام */
    public function style(array $o): int
    {
        $key = json_encode($o);
        if (isset($this->xfKey[$key])) return $this->xfKey[$key];
        $font = 0;
        if (isset($o['sz']) || !empty($o['bold']) || isset($o['color'])) {
            $font = self::idx($this->fonts, '<font>' . (!empty($o['bold']) ? '<b/>' : '') . '<sz val="' . ($o['sz'] ?? 10) . '"/>'
                . '<color rgb="FF' . ($o['color'] ?? '000000') . '"/><name val="' . self::FONT . '"/><family val="2"/></font>');
        }
        $fill = isset($o['fill']) ? self::idx($this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $o['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>') : 0;
        $b = $o['border'] ?? 'none';
        $side = fn(string $n, string $st) => '<' . $n . ' style="' . $st . '"><color rgb="' . self::BORDER . '"/></' . $n . '>';
        $border = $b === 'none' ? 0 : self::idx($this->borders, '<border>' . $side('left', 'thin') . $side('right', 'thin')
            . $side('top', $b === 'total' ? 'medium' : 'thin') . $side('bottom', $b === 'total' ? 'medium' : 'thin') . '<diagonal/></border>');
        $al = '<alignment horizontal="' . ($o['h'] ?? 'center') . '" vertical="' . ($o['v'] ?? 'center') . '"'
            . (!empty($o['wrap']) ? ' wrapText="1"' : '') . (!empty($o['shrink']) ? ' shrinkToFit="1"' : '')
            . (!empty($o['indent']) ? ' indent="' . (int)$o['indent'] . '"' : '') . (!empty($o['ro']) ? ' readingOrder="' . (int)$o['ro'] . '"' : '') . '/>';
        $num = !empty($o['num']) ? 3 : 0; // 3 = #,##0
        $this->xfs[] = '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ($num ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . ($fill ? ' applyFill="1"' : '') . ($border ? ' applyBorder="1"' : '')
            . ' applyAlignment="1">' . $al . '</xf>';
        return $this->xfKey[$key] = count($this->xfs) - 1;
    }

    /** مقطع نصّ منسّق داخل الخلية (rich text). */
    public static function run(string $text, float $sz, bool $bold = true, string $color = '000000'): string
    {
        return '<r><rPr>' . ($bold ? '<b/>' : '') . '<sz val="' . $sz . '"/><color rgb="FF' . $color . '"/><rFont val="' . self::FONT . '"/><family val="2"/></rPr>'
            . '<t xml:space="preserve">' . str_replace("\n", '&#10;', self::xa($text)) . '</t></r>';
    }

    public function setBounds(array $fracs): void
    {
        sort($fracs);
        $out = [];
        foreach ($fracs as $f) { if (!$out || $f - end($out) > 0.0025) $out[] = (float)$f; }
        $out[0] = 0.0; $out[count($out) - 1] = 1.0;
        $this->bounds = $out;
    }

    private function col(float $f): int
    {
        $best = 0; $bd = 9.0;
        foreach ($this->bounds as $i => $b) { $d = abs($b - $f); if ($d < $bd) { $bd = $d; $best = $i; } }
        return $best;
    }

    /** خانة من الكسر $fa إلى $fb (من عرض البطاقة) على الصفوف $r0..$r1 — تُدمَج إن غطّت أكثر من خلية. $value: ['rich'=>xml] | ['num'=>n] | null */
    public function put(int $r0, int $r1, float $fa, float $fb, int $style, ?array $value = null): void
    {
        $c0 = $this->col($fa); $c1 = max($c0, $this->col($fb) - 1);
        for ($r = $r0; $r <= $r1; $r++) for ($c = $c0; $c <= $c1; $c++) $this->cells[$r][$c] = ['s' => $style, 'v' => null];
        $this->cells[$r0][$c0]['v'] = $value;
        if ($r1 > $r0 || $c1 > $c0) $this->merges[] = self::ref($c0, $r0) . ':' . self::ref($c1, $r1);
    }

    public function height(int $row, float $pt): void { $this->heights[$row] = round($pt, 2); }
    public function pageBreakAfter(int $row): void { $this->breaks[] = $row; }

    private static function letter(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
        return $s;
    }
    private static function ref(int $c, int $r): string { return self::letter($c) . $r; }

    /** الملف كاملاً (bytes). $totalChars = عرض الورقة بوحدات إكسل · $scale = نسبة الطباعة ٪ (null ⇒ ملاءمة صفحة واحدة) */
    public function bytes(string $sheetName, float $totalChars, ?int $scale): string
    {
        $g = count($this->bounds) - 1;
        $cols = '';
        for ($i = 0; $i < $g; $i++) {
            $w = max(0.4, round(($this->bounds[$i + 1] - $this->bounds[$i]) * $totalChars, 2));
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        ksort($this->cells);
        $rowsXml = ''; $maxRow = 1;
        foreach ($this->cells as $r => $rc) {
            ksort($rc); $maxRow = max($maxRow, $r);
            $rowsXml .= '<row r="' . $r . '"' . (isset($this->heights[$r]) ? ' ht="' . $this->heights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($rc as $c => $cell) {
                $ref = self::ref($c, $r); $v = $cell['v'];
                if ($v === null) $rowsXml .= '<c r="' . $ref . '" s="' . $cell['s'] . '"/>';
                elseif (isset($v['num'])) $rowsXml .= '<c r="' . $ref . '" s="' . $cell['s'] . '"><v>' . $v['num'] . '</v></c>';
                else $rowsXml .= '<c r="' . $ref . '" s="' . $cell['s'] . '" t="inlineStr"><is>' . $v['rich'] . '</is></c>';
            }
            $rowsXml .= '</row>';
        }
        $mergeXml = $this->merges ? '<mergeCells count="' . count($this->merges) . '"><mergeCell ref="' . implode('"/><mergeCell ref="', $this->merges) . '"/></mergeCells>' : '';
        $brkXml = '';
        if ($this->breaks) {
            $brkXml = '<rowBreaks count="' . count($this->breaks) . '" manualBreakCount="' . count($this->breaks) . '">';
            foreach ($this->breaks as $b) $brkXml .= '<brk id="' . $b . '" max="16383" man="1"/>';
            $brkXml .= '</rowBreaks>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="' . ($scale === null ? 1 : 0) . '"/></sheetPr>'
            . '<dimension ref="A1:' . self::ref(max(0, $g - 1), $maxRow) . '"/>'
            . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $cols . '</cols><sheetData>' . $rowsXml . '</sheetData>' . $mergeXml
            . '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.3" right="0.3" top="0.3" bottom="0.3" header="0" footer="0"/>'
            . '<pageSetup paperSize="9" orientation="landscape"' . ($scale === null ? ' fitToWidth="1" fitToHeight="1"' : ' scale="' . $scale . '"') . '/>'
            . $brkXml . '</worksheet>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        $name = self::xa(mb_substr(preg_replace('#[\\\\/?*\[\]:]+#u', '-', $sheetName), 0, 31, 'UTF-8'));
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'slx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($parts as $n => $content) $zip->addFromString($n, $content);
        $zip->close();
        $data = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}

/**
 * بطاقات (من annualSlipXlsxParseAll) ⇒ ملف xlsx: بطاقة لكل ورقة A4 أفقية، بنفس ترتيب وشكل الـPDF.
 * بطاقة واحدة = ملاءمة صفحة واحدة؛ عدّة بطاقات = نسبة طباعة محسوبة + فاصل صفحة بعد كل بطاقة.
 */
function annualSlipXlsxBuild(array $cards, string $sheetName): string
{
    $x = new AnnualSlipXlsx();
    // شبكة الأعمدة = اتحاد حدود أعمدة كل البطاقات + أرباع جدول المعلومات + حدَّي شريط الاسم
    $fr = [0.0, 1.0, 0.25, 0.5, 0.75, 0.34, 0.70];
    $maxW = 0.0;
    foreach ($cards as &$c) {
        $sum = array_sum(array_column($c['leaf'], 'w')); $maxW = max($maxW, $sum);
        $acc = 0.0; $c['b'] = [0.0];
        foreach ($c['leaf'] as $lf) { $acc += $lf['w']; $c['b'][] = $acc / $sum; $fr[] = $acc / $sum; }
    }
    unset($c);
    $x->setBounds($fr);
    // عرض الورقة: 14 وحدة لعمود المبلغ (وزن 1.1) بأعرض بطاقة ⇒ نسبة الطباعة لتدخل بعرض A4 أفقي. مقيس بإكسل الحقيقي: وحدة العرض
    // ≈ 5.4pt (Arial 10)، والمساحة المطبوعة الفعلية أصغر من الورقة ناقص الهوامش (هوامش الطابعة الصلبة: 783pt فاضت و763pt دخلت)
    // ⇒ نستهدف 745×530pt لتدخل على أي طابعة.
    $totalChars = 14 / 1.1 * $maxW;
    $s = min(1.0, 745.0 / ($totalChars * 5.4));
    $pageH = 530.0 / $s * 0.97; // الارتفاع المتاح للبطاقة بنقاط الورقة

    $B = 'all';
    $stBarL = $x->style(['fill' => 'EAF1FA', 'h' => 'left', 'wrap' => 1, 'ro' => 1, 'indent' => 1]);
    $stBarC = $x->style(['fill' => 'EAF1FA', 'h' => 'center', 'wrap' => 1]);
    $stBarR = $x->style(['fill' => 'EAF1FA', 'h' => 'right', 'wrap' => 1, 'ro' => 1, 'indent' => 1]);
    $stRate = $x->style(['h' => 'center', 'ro' => 2]);
    $stInfo = $x->style(['border' => $B, 'h' => 'left', 'v' => 'top', 'wrap' => 1, 'ro' => 1]);
    $stHead = $x->style(['border' => $B, 'fill' => 'F1F5F9', 'wrap' => 1]);
    $stDed  = $x->style(['border' => $B, 'fill' => 'FFE3E3', 'wrap' => 1]);
    $stCell = $x->style(['border' => $B, 'wrap' => 1]);
    $stNumB = $x->style(['border' => $B, 'num' => 1, 'shrink' => 1, 'sz' => 11, 'bold' => 1]);
    $stTot  = $x->style(['border' => 'total', 'wrap' => 1]);
    $stTotN = $x->style(['border' => 'total', 'num' => 1, 'shrink' => 1, 'sz' => 11, 'bold' => 1]);

    $row = 1; $multi = count($cards) > 1;
    foreach ($cards as $ci => $c) {
        $b = $c['b'];
        // ارتفاعات ثابتة ثم توزيع الباقي على صفوف الأشهر (كالـPDF: الجدول يأخذ باقي الورقة)
        $hBar = 28; $hRate = $c['rate'] !== '' ? 14 : 0; $hInfo = 31; $hGap = 4;
        $span2 = 1; $sub2 = 1;
        foreach ($c['head'][0] as $h) if ($h['rs'] >= 2) $span2 = max($span2, count($h['lines']));
        foreach (($c['head'][1] ?? []) as $h) $sub2 = max($sub2, count($h['lines']));
        $hH0 = 17; $hH1 = max($sub2 * 12 + 4, $span2 * 12 + 6 - $hH0);
        $nRows = count($c['rows']);
        $two = false; foreach ($c['rows'] as $r) foreach ($r['cells'] as $cell) if (count($cell['lines']) > 1) $two = true;
        $fixed = $hBar + $hRate + $hInfo * count($c['info']) + $hGap + $hH0 + $hH1;
        $hRow = max($two ? 27 : 21, min(58, ($pageH - $fixed) / max(1, $nRows)));

        // شريط الاسم: المدرسة · الاسم · العنوان
        $x->height($row, $hBar);
        $x->put($row, $row, 0.0, 0.34, $stBarL, $c['school'] !== '' ? ['rich' => AnnualSlipXlsx::run($c['school'], 9, true, '334155')] : null);
        $x->put($row, $row, 0.34, 0.70, $stBarC, $c['name'] !== '' ? ['rich' => AnnualSlipXlsx::run($c['name'], 15)] : null);
        $x->put($row, $row, 0.70, 1.0, $stBarR, $c['rep'] !== '' ? ['rich' => AnnualSlipXlsx::run($c['rep'], 9, true, '334155')] : null);
        $row++;
        if ($hRate) {
            $x->height($row, $hRate);
            $x->put($row, $row, 0.0, 1.0, $stRate, ['rich' => AnnualSlipXlsx::run($c['rate'], 8)]);
            $row++;
        }
        // جدول المعلومات: التسمية صغيرة رمادية فوق القيمة العريضة
        foreach ($c['info'] as $ir) {
            $x->height($row, $hInfo);
            $n = max(1, array_sum(array_column($ir, 'cs'))); $p = 0;
            foreach ($ir as $cell) {
                $rich = AnnualSlipXlsx::run($cell['l'] . ($cell['v'] !== '' ? "\n" : ''), 8, true, '6B7280') . ($cell['v'] !== '' ? AnnualSlipXlsx::run($cell['v'], 12) : '');
                $x->put($row, $row, $p / $n, ($p + $cell['cs']) / $n, $stInfo, ['rich' => $rich]);
                $p += $cell['cs'];
            }
            $row++;
        }
        $x->height($row, $hGap); $row++;
        // رأس الجدول (صفّان): rowspan=2 يمتدّ على الصفّين، ومجموعة المحسومات فوق أعمدتها
        $r0 = $row; $r1 = $row + 1;
        $x->height($r0, $hH0); $x->height($r1, $hH1);
        $headRich = function (array $lines, bool $ded): string {
            $out = ''; $col = $ded ? '7F1D1D' : '000000';
            foreach ($lines as $i => $ln) $out .= AnnualSlipXlsx::run($ln . ($i < count($lines) - 1 ? "\n" : ''), $i === 0 ? 9.5 : 8.5, true, $col);
            return $out;
        };
        $p = 0; $groups = [];
        foreach ($c['head'][0] as $h) {
            $full = $h['rs'] >= 2;
            $x->put($r0, $full ? $r1 : $r0, $b[$p], $b[$p + $h['cs']], $h['ded'] ? $stDed : $stHead, $h['lines'] ? ['rich' => $headRich($h['lines'], $h['ded'])] : null);
            if (!$full) for ($k = 0; $k < $h['cs']; $k++) $groups[] = $p + $k;
            $p += $h['cs'];
        }
        foreach (($c['head'][1] ?? []) as $i => $h) {
            if (!isset($groups[$i])) break;
            $x->put($r1, $r1, $b[$groups[$i]], $b[$groups[$i] + 1], $h['ded'] ? $stDed : $stHead, $h['lines'] ? ['rich' => $headRich($h['lines'], $h['ded'])] : null);
        }
        $row += 2;
        // الأشهر + TOTAL
        foreach ($c['rows'] as $r) {
            $x->height($row, $hRow);
            $p = 0; $nLeaf = count($c['leaf']);
            foreach ($r['cells'] as $cell) {
                $e = min($nLeaf, $p + $cell['cs']);
                $ls = $cell['lines']; $val = null; $st = $r['total'] ? $stTot : $stCell;
                if (count($ls) === 1 && preg_match('/^-?\d{1,3}(,\d{3})*$|^-?\d+$/', $ls[0])) { // مبلغ وحده ⇒ رقم حقيقي (قابل للجمع)
                    $val = ['num' => (int)str_replace(',', '', $ls[0])];
                    $st = $r['total'] ? $stTotN : $stNumB; // كل مبالغ البطاقة عريضة (وزن 700 كالـPDF)
                } elseif ($ls) {
                    $rich = AnnualSlipXlsx::run($ls[0] . (isset($ls[1]) ? "\n" : ''), $cell['month'] || ($r['total'] && $p === 0) ? 10.5 : 11);
                    for ($k = 1; $k < count($ls); $k++) $rich .= AnnualSlipXlsx::run($ls[$k] . ($k < count($ls) - 1 ? "\n" : ''), 8.5, true);
                    $val = ['rich' => $rich];
                }
                if ($p < $nLeaf) $x->put($row, $row, $b[$p], $b[$e], $st, $val);
                $p = $e;
            }
            $row++;
        }
        if ($multi && $ci < count($cards) - 1) $x->pageBreakAfter($row - 1);
    }
    return $x->bytes($sheetName, $totalChars, $multi ? max(10, min(100, (int)floor($s * 100))) : null);
}
