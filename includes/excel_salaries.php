<?php
/**
 * 📗 إكسل الرواتب والأجر الإضافي للمتعاقدين والموظفين — دفعة وحدة (2026-09-12)
 * طلبه: «بدي ملف إكسل فيه أسماء الأساتذة الموجودين المتعاقد أو الموظف، ومحلّ أنا حطّ الراتب والأجر الإضافي وعدد الأيام
 * بالأسبوع — بحطّهن بإكسل ورا بعضهن وانت بترجع بتوزّعهن على ملفاتهم، أسهل عليّ من ما فوت على كل ملف. خليها أوبسيون زيادة».
 *
 *  - ينزّل ملف xlsx (PHP خالص — ZipArchive) لمدرسة وسنة: سطر لكل متعاقد/موظف فاعل، الخانات معبّأة بقيمه الحالية
 *    (الراتب $ أو ل.ل · الأجر الإضافي ٪ / مبلغ ل.ل / مبلغ $ · عدد الأيام بالأسبوع). يعدّل ما يريد ويرفعه.
 *  - الرفع يقرأ الملف (ZipArchive + SimpleXML) ويعرض **معاينة الفروقات** (قديم ← جديد) لكل شخص، ثم زرّ «طبّق» يوزّع:
 *    الراتب → salary_input_mode + base_salary_usd/contract_salary_lbp · الأجر الإضافي → بند prime_fixe للسنة (كل السنة، يستبدل
 *    الحالي؛ 0 = شيله) · الأيام → days_per_week · ثم recalcEmployeeYear (المحرّك = المصدر الواحد، فالبطاقة السنوية وكل التقارير نفس الأرقام).
 *  - فاضي = لا تغيير. يحترم قفل السنة. الملاك غير مشمولين (رواتبهم بالسلسلة).
 * الأعمدة ثابتة (A..J) — المصدر الواحد excelSalariesColumns().
 */

function excelSalariesColumns(): array {
    return [
        'id'      => ['A', 'رقم الملف (لا تغيّره)', 12],
        'cat'     => ['B', 'الفئة', 12],
        'name'    => ['C', 'الاسم الكامل', 34],
        'sal_usd' => ['D', 'الراتب الأساسي $ (بالشهر)', 16],
        'sal_lbp' => ['E', 'الراتب الأساسي ل.ل (بالشهر)', 20],
        'pct'     => ['F', 'الأجر الإضافي ٪ من الأساس', 16],
        'amt_lbp' => ['G', 'الأجر الإضافي مبلغ ل.ل (بالشهر)', 20],
        'amt_usd' => ['H', 'الأجر الإضافي مبلغ $ (بالشهر)', 18],
        'from'    => ['I', 'الإضافي من شهر (اسم أو رقم الشهر، فاضي = تشرين الأول)', 16],
        'to'      => ['J', 'الإضافي إلى شهر (فاضي = أيلول)', 16],
        'days'    => ['K', 'عدد الأيام بالأسبوع', 12],
        'note'    => ['L', 'ملاحظة (لا تُقرأ)', 26],
    ];
}
/** شهر من خانة إكسل: رقم 1-12 أو اسم عربي/فرنسي → int أو null (فاضي/غير مفهوم). */
function excelSalariesMonth($v): ?int {
    $v = trim((string)$v); if ($v === '') return null;
    if (function_exists('arabicDigitsFr')) $v = arabicDigitsFr($v);
    if (preg_match('/^\d{1,2}(\.0+)?$/', $v)) { $m = (int)$v; return ($m >= 1 && $m <= 12) ? $m : null; }
    for ($m = 1; $m <= 12; $m++) { if (mb_strtolower(monthName($m, 'ar')) === mb_strtolower($v) || mb_strtolower(monthName($m, 'fr')) === mb_strtolower($v)) return $m; }
    return null;
}
function excelSalariesCatLabel(string $t): string { return $t === 'enseignant_contractuel' ? 'متعاقد' : ($t === 'employe' ? 'موظف' : 'ملاك'); }

/** المتعاقدون والموظفون الفاعلون بالمدرسة (غير التاركين) مع قيمهم الحالية للسنة. */
function excelSalariesRows(PDO $db, int $schoolId, string $sy): array {
    $st = $db->prepare("SELECT e.id, e.employee_type, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr) fn, COALESCE(NULLIF(e.father_name_ar,''), '') fa,
                COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr) ln, e.salary_input_mode, e.base_salary_usd, e.contract_salary_lbp, e.days_per_week
            FROM employees e WHERE e.school_id = ? AND e.is_deleted = 0 AND e.employee_type IN ('enseignant_contractuel','employe')
              AND e.status = 'actif' AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL
              -- موجود بهذه السنة: له رواتب فيها، أو جديد لم يُحسب له شيء بعد (بلا أي صفّ بأي سنة) ودخوله قبل نهايتها —
              -- لا مَن عُيِّن لسنة لاحقة (وإلا خلق التطبيق له رواتب بسنة لم يعمل فيها)
              AND (EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ?)
                   OR (NOT EXISTS (SELECT 1 FROM monthly_salaries ms2 WHERE ms2.employee_id = e.id) AND (e.hire_date IS NULL OR e.hire_date <= ?)))
            ORDER BY FIELD(e.employee_type,'enseignant_contractuel','employe'), e.last_name_ar, e.first_name_ar, e.id");
    $st->execute([$schoolId, $sy, substr($sy, 5, 4) . '-09-30']);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[(int)$r['id']] = [
            'id' => (int)$r['id'], 'cat' => excelSalariesCatLabel($r['employee_type']), 'type' => $r['employee_type'],
            'name' => trim(preg_replace('/\s+/u', ' ', $r['fn'] . ' ' . $r['fa'] . ' ' . $r['ln'])),
            'sal_usd' => ($r['salary_input_mode'] === 'direct_usd' && (float)$r['base_salary_usd'] > 0) ? (float)$r['base_salary_usd'] : null,
            'sal_lbp' => ($r['salary_input_mode'] !== 'direct_usd' && (float)$r['contract_salary_lbp'] > 0) ? (float)$r['contract_salary_lbp'] : null,
            'pct' => null, 'amt_lbp' => null, 'amt_usd' => null, 'from' => null, 'to' => null, 'multi' => false,
            'days' => (int)$r['days_per_week'] ?: null,
        ];
    }
    if ($rows) {
        $in = implode(',', array_keys($rows));
        $bq = $db->query("SELECT employee_id, value_type, amount, currency, start_month, end_month FROM employee_bonuses
                          WHERE is_active = 1 AND bonus_type = 'prime_fixe' AND school_year = " . $db->quote($sy) . " AND employee_id IN ($in) ORDER BY id");
        foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $id = (int)$b['employee_id']; $a = (float)$b['amount'];
            if ($b['value_type'] === 'percent') $rows[$id]['pct'] = ($rows[$id]['pct'] ?? 0) + $a;
            elseif ($b['currency'] === 'USD') $rows[$id]['amt_usd'] = ($rows[$id]['amt_usd'] ?? 0) + $a;
            else $rows[$id]['amt_lbp'] = ($rows[$id]['amt_lbp'] ?? 0) + $a;
            // الفترة: بنود بفترات مختلفة عند نفس الشخص = «متعدّد» (الإكسل يعرض الأولى؛ التعديل من الإكسل يستبدلها كلها بفترة واحدة)
            $f = $b['start_month'] === null ? 10 : (int)$b['start_month']; $t = $b['end_month'] === null ? 9 : (int)$b['end_month'];
            if ($rows[$id]['from'] === null) { $rows[$id]['from'] = $f; $rows[$id]['to'] = $t; }
            elseif ($rows[$id]['from'] !== $f || $rows[$id]['to'] !== $t) $rows[$id]['multi'] = true;
        }
        // بنود بفترات مختلفة عند نفس الشخص (مثلاً 59م تشرين←تموز + 69م آب←أيلول): لا تُمثَّل بسطر واحد — خاناته تُترك فاضية
        // (فاضي = يبقى كما هو) مع ملاحظة تشرح؛ إن كتب قيمة تستبدل كل فتراته بسطر واحد. تفصيله بـ'lines' للمعاينة.
        $multiIds = array_keys(array_filter($rows, fn($r) => !empty($r['multi'])));
        if ($multiIds) {
            $bq2 = $db->query("SELECT employee_id, value_type, amount, currency, start_month, end_month FROM employee_bonuses
                          WHERE is_active = 1 AND bonus_type = 'prime_fixe' AND school_year = " . $db->quote($sy) . " AND employee_id IN (" . implode(',', $multiIds) . ") ORDER BY start_month, id");
            foreach ($bq2->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $id = (int)$b['employee_id']; $a = rtrim(rtrim(number_format((float)$b['amount'], 2, '.', ','), '0'), '.');
                $f = $b['start_month'] === null ? 10 : (int)$b['start_month']; $t = $b['end_month'] === null ? 9 : (int)$b['end_month'];
                $rows[$id]['lines'][] = ($b['value_type'] === 'percent' ? $a . '٪' : $a . ($b['currency'] === 'USD' ? ' $' : ' ل.ل')) . ' (' . monthName($f, 'ar') . ' ← ' . monthName($t, 'ar') . ')';
            }
            foreach ($multiIds as $id) {
                $rows[$id]['pct'] = $rows[$id]['amt_lbp'] = $rows[$id]['amt_usd'] = $rows[$id]['from'] = $rows[$id]['to'] = null;
                $rows[$id]['note'] = 'الأجر الإضافي بأكثر من فترة: ' . implode(' + ', $rows[$id]['lines'] ?? []) . ' — فاضي = يبقى كما هو؛ اكتب قيمة (وفترة) لتستبدلها كلها بسطر واحد.';
            }
        }
        foreach ($rows as &$r) { if ($r['from'] !== null) { $r['from'] = monthName((int)$r['from'], 'ar'); $r['to'] = monthName((int)$r['to'], 'ar'); } }
        unset($r);
    }
    return $rows;
}

/** بناء ملف xlsx (بايتات) — سطر 1 عنوان، سطر 2 شرح، سطر 3 رؤوس، البيانات من سطر 4. */
function excelSalariesBuild(PDO $db, int $schoolId, string $sy): string {
    $cols = excelSalariesColumns(); $rows = excelSalariesRows($db, $schoolId, $sy);
    $xa = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $n = count($cols); $last = chr(64 + $n);
    $cell = function ($ref, $v, $s) use ($xa) {
        if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $s . '"/>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
        return '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . $xa($v) . '</t></is></c>';
    };
    $school = schoolNameById($schoolId, 'ar');
    $xml = '<row r="1">' . $cell('A1', "الرواتب والأجر الإضافي — المتعاقدون والموظفون — $school — $sy", 1) . '</row>';
    $xml .= '<row r="2">' . $cell('A2', 'عبّي الخانات الصفراء وارفع الملف بالبرنامج. فاضي = لا تغيير · 0 بالأجر الإضافي = شيله · الراتب: عمود $ أو عمود ل.ل (واحد منهما) · الإضافي من شهر إلى شهر: فاضي = كل السنة (تشرين الأول ← أيلول) · لا تغيّر رقم الملف ولا ترتيب الأعمدة.', 2) . '</row>';
    $xml .= '<row r="3">';
    $i = 0; foreach ($cols as $k => $c) { $xml .= $cell($c[0] . '3', $c[1], 3); $i++; }
    $xml .= '</row>';
    $r = 3;
    foreach ($rows as $row) {
        $r++; $xml .= '<row r="' . $r . '">';
        foreach ($cols as $k => $c) {
            $v = $row[$k] ?? null;
            $editable = in_array($k, ['sal_usd','sal_lbp','pct','amt_lbp','amt_usd','from','to','days','note'], true);
            $xml .= $cell($c[0] . $r, $v, $editable ? 5 : 4);
        }
        $xml .= '</row>';
    }
    $colsXml = '<cols>'; $i = 0;
    foreach ($cols as $c) { $i++; $colsXml .= '<col min="' . $i . '" max="' . $i . '" width="' . $c[2] . '" customWidth="1"/>'; }
    $colsXml .= '</cols>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0" tabSelected="1"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>' . $colsXml
        . '<sheetData>' . $xml . '</sheetData>'
        . '<mergeCells count="2"><mergeCell ref="A1:' . $last . '1"/><mergeCell ref="A2:' . $last . '2"/></mergeCells>'
        . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
        . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    // أنماط: 0 عادي · 1 عنوان · 2 شرح · 3 رأس (كحلي) · 4 بيانات مقفولة (رمادي) · 5 خانة إدخال (أصفر)
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.##"/></numFmts>'
        . '<fonts count="4"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="14"/><color rgb="FF1F4E5F"/><name val="Arial"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E5F"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF7CC"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyProtection="1"><protection locked="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyProtection="1"><protection locked="0"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="الرواتب والإضافي" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xls'); @unlink($tmp); $tmp .= '.xlsx';
    $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $p => $c) $zip->addFromString($p, $c);
    $zip->close();
    $data = (string)file_get_contents($tmp); @unlink($tmp);
    return $data;
}

/** قراءة ملف xlsx مرفوع → [['id'=>..,'sal_usd'=>..,...], ...] حسب الأعمدة الثابتة (من السطر 4، أو أي سطر أوّل خانته رقم ملف). */
function excelSalariesParse(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive غير متوفّر');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('الملف ليس إكسل صالحاً (xlsx)');
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($ss);
        if ($sx) foreach ($sx->si as $si) { $t = ''; foreach ($si->xpath('.//*[local-name()="t"]') as $tt) $t .= (string)$tt; $shared[] = $t; }
    }
    // الورقة الأولى (حسب workbook.xml.rels قد تكون sheet1.xml — نأخذ أوّل worksheets/*.xml)
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) for ($i = 0; $i < $zip->numFiles; $i++) { $nm = $zip->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nm)) { $sheetXml = $zip->getFromName($nm); break; } }
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('لا ورقة بالملف');
    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) throw new RuntimeException('تعذّر قراءة الورقة');
    $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $colMap = []; foreach (excelSalariesColumns() as $k => $c) $colMap[$c[0]] = $k;
    $out = [];
    foreach ($sx->sheetData->row as $row) {
        $vals = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; $col = preg_replace('/\d+/', '', $ref);
            $t = (string)$c['t']; $v = null;
            if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
            elseif ($t === 'inlineStr') { $v = ''; foreach ($c->is->xpath('.//*[local-name()="t"]') as $tt) $v .= (string)$tt; }
            elseif (isset($c->v)) { $v = (string)$c->v; }
            if ($v === null) continue;
            if (isset($colMap[$col])) $vals[$colMap[$col]] = trim($v);
        }
        if (!isset($vals['id']) || !preg_match('/^\d+(\.0+)?$/', (string)$vals['id'])) continue; // العنوان/الشرح/الرؤوس
        $vals['id'] = (int)$vals['id'];
        $out[] = $vals;
    }
    return $out;
}

/** رقم من خانة إكسل: '' ⇒ null (لا تغيير)، وإلا float (يقبل فواصل الآلاف والأرقام العربية). */
function excelSalariesNum($v): ?float {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (function_exists('arabicDigitsFr')) $v = arabicDigitsFr($v);
    $v = str_replace([',', ' ', '٬'], '', $v);
    if (!is_numeric($v)) return null;
    return (float)$v;
}

/**
 * مقارنة الملف المرفوع بالوضع الحالي → ['changes' => [emp_id => [...]], 'errors' => [...], 'unchanged' => n]
 * كل تغيير: name, cat, fields: [['what'=>'الراتب', 'old'=>'…', 'new'=>'…']], ops: تعليمات التطبيق.
 */
function excelSalariesDiff(PDO $db, int $schoolId, string $sy, array $parsed): array {
    $cur = excelSalariesRows($db, $schoolId, $sy);
    $fmt = function ($v, $suf = '') { return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.') . $suf; };
    $changes = []; $errors = []; $unchanged = 0;
    foreach ($parsed as $p) {
        $id = (int)$p['id'];
        if (!isset($cur[$id])) { $errors[] = "رقم الملف $id ليس متعاقداً/موظفاً فاعلاً بهذه المدرسة — تُرك"; continue; }
        $c = $cur[$id]; $fields = []; $ops = [];
        // الراتب: عمود $ أو عمود ل.ل
        $su = excelSalariesNum($p['sal_usd'] ?? ''); $sl = excelSalariesNum($p['sal_lbp'] ?? '');
        if ($su !== null && $su > 0 && $sl !== null && $sl > 0) { $errors[] = "{$c['name']} (#$id): الراتب بالدولار وبالليرة معاً — حدّد عملة واحدة (تُرك راتبه)"; $su = $sl = null; }
        if ($su !== null && $su > 0 && (float)($c['sal_usd'] ?? 0) !== $su) { $fields[] = ['what' => 'الراتب الأساسي', 'old' => $c['sal_usd'] !== null ? $fmt($c['sal_usd'], ' $') : ($c['sal_lbp'] !== null ? $fmt($c['sal_lbp'], ' ل.ل') : '—'), 'new' => $fmt($su, ' $')]; $ops['salary'] = ['mode' => 'direct_usd', 'usd' => $su]; }
        elseif ($sl !== null && $sl > 0 && (float)($c['sal_lbp'] ?? 0) !== $sl) { $fields[] = ['what' => 'الراتب الأساسي', 'old' => $c['sal_lbp'] !== null ? $fmt($c['sal_lbp'], ' ل.ل') : ($c['sal_usd'] !== null ? $fmt($c['sal_usd'], ' $') : '—'), 'new' => $fmt($sl, ' ل.ل')]; $ops['salary'] = ['mode' => 'direct_lbp', 'lbp' => $sl]; }
        // الأجر الإضافي: ٪ و/أو مبلغ (ل.ل أو $) + الفترة (من ← إلى) — أي خانة مكتوبة (ولو 0) تعني «الوضع الجديد للأجر الإضافي كله»
        $pc = excelSalariesNum($p['pct'] ?? ''); $al = excelSalariesNum($p['amt_lbp'] ?? ''); $au = excelSalariesNum($p['amt_usd'] ?? '');
        $fm = excelSalariesMonth($p['from'] ?? ''); $tm = excelSalariesMonth($p['to'] ?? '');
        if ((trim((string)($p['from'] ?? '')) !== '' && $fm === null) || (trim((string)($p['to'] ?? '')) !== '' && $tm === null)) { $errors[] = "{$c['name']} (#$id): شهر غير مفهوم بخانة «من/إلى» (اكتب اسم الشهر أو رقمه 1-12) — تُرك أجره الإضافي"; }
        elseif ($pc !== null || $al !== null || $au !== null || $fm !== null || $tm !== null) {
            $newPc = $pc ?? (float)($c['pct'] ?? 0); $newAl = $al ?? (float)($c['amt_lbp'] ?? 0); $newAu = $au ?? (float)($c['amt_usd'] ?? 0);
            $curF = $c['from'] !== null ? (int)excelSalariesMonth($c['from']) : 10; $curT = $c['to'] !== null ? (int)excelSalariesMonth($c['to']) : 9;
            $newF = $fm ?? $curF; $newT = $tm ?? $curT;
            if ($newAl > 0 && $newAu > 0) { $errors[] = "{$c['name']} (#$id): مبلغ الأجر الإضافي بالليرة وبالدولار معاً — حدّد عملة واحدة (تُرك)"; }
            else {
                $oldT = [(float)($c['pct'] ?? 0), (float)($c['amt_lbp'] ?? 0), (float)($c['amt_usd'] ?? 0), $curF, $curT];
                // متعدّد الفترات: خاناته بالملف فاضية (لا تغيير) — أي قيمة مكتوبة تستبدل كل فتراته بسطر واحد
                if ($oldT !== [$newPc, $newAl, $newAu, $newF, $newT]) {
                    $per = fn($f, $t) => ($f === 10 && $t === 9) ? 'كل السنة' : monthName($f, 'ar') . ' ← ' . monthName($t, 'ar');
                    $lbl = function ($pcv, $alv, $auv, $f, $t) use ($fmt, $per) { $x = []; if ($pcv > 0) $x[] = $fmt($pcv, '٪'); if ($alv > 0) $x[] = $fmt($alv, ' ل.ل'); if ($auv > 0) $x[] = $fmt($auv, ' $'); return $x ? implode(' + ', $x) . ' (' . $per($f, $t) . ')' : 'بلا'; };
                    $fields[] = ['what' => 'الأجر الإضافي', 'old' => !empty($c['multi']) ? implode(' + ', $c['lines'] ?? []) : $lbl(...$oldT), 'new' => $lbl($newPc, $newAl, $newAu, $newF, $newT)];
                    $lines = [];
                    if ($newPc > 0) $lines[] = ['vt' => 'percent', 'val' => $newPc, 'cur' => 'LBP', 'from' => $newF, 'to' => $newT];
                    if ($newAl > 0) $lines[] = ['vt' => 'amount', 'val' => $newAl, 'cur' => 'LBP', 'from' => $newF, 'to' => $newT];
                    if ($newAu > 0) $lines[] = ['vt' => 'amount', 'val' => $newAu, 'cur' => 'USD', 'from' => $newF, 'to' => $newT];
                    $ops['prime'] = $lines;
                }
            }
        }
        // الأيام بالأسبوع
        $d = excelSalariesNum($p['days'] ?? '');
        if ($d !== null) {
            $di = (int)round($d);
            if ($di < 1 || $di > 7) $errors[] = "{$c['name']} (#$id): عدد الأيام $di غير منطقي (1-7) — تُرك";
            elseif ($di !== (int)($c['days'] ?? 0)) { $fields[] = ['what' => 'الأيام بالأسبوع', 'old' => $c['days'] !== null ? (string)$c['days'] : '—', 'new' => (string)$di]; $ops['days'] = $di; }
        }
        if ($fields) $changes[$id] = ['id' => $id, 'name' => $c['name'], 'cat' => $c['cat'], 'fields' => $fields, 'ops' => $ops];
        else $unchanged++;
    }
    return ['changes' => $changes, 'errors' => $errors, 'unchanged' => $unchanged];
}

/** تطبيق التغييرات (بعد التأكيد) → ['applied' => n, 'recalc' => n, 'skipped' => [...]] */
function excelSalariesApply(PDO $db, int $schoolId, string $sy, array $changes): array {
    require_once __DIR__ . '/payroll_calculator.php';
    $applied = 0; $recalc = 0; $skipped = [];
    if (isSchoolYearLocked($schoolId, $sy)) return ['applied' => 0, 'recalc' => 0, 'skipped' => [yearLockedMsg($schoolId, $sy)]];
    $chk = $db->prepare("SELECT id FROM employees WHERE id = ? AND school_id = ? AND is_deleted = 0 AND employee_type IN ('enseignant_contractuel','employe')");
    $delP = $db->prepare("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = ? AND bonus_type = 'prime_fixe' AND school_year = ?");
    $insP = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'prime_fixe', ?, ?, ?, ?, ?, ?, ?, 1)");
    foreach ($changes as $ch) {
        $id = (int)$ch['id']; $chk->execute([$id, $schoolId]);
        if (!$chk->fetchColumn()) { $skipped[] = ($ch['name'] ?? $id) . ': لم يعد بالمدرسة'; continue; }
        $ops = $ch['ops'] ?? [];
        if (!empty($ops['salary'])) {
            if ($ops['salary']['mode'] === 'direct_usd') $db->prepare("UPDATE employees SET salary_input_mode = 'direct_usd', base_salary_usd = ? WHERE id = ?")->execute([(float)$ops['salary']['usd'], $id]);
            else $db->prepare("UPDATE employees SET salary_input_mode = 'direct_lbp', contract_salary_lbp = ? WHERE id = ?")->execute([(int)round((float)$ops['salary']['lbp']), $id]);
        }
        if (array_key_exists('prime', $ops)) {
            $delP->execute([$id, $sy]); $pn = 0;
            foreach ((array)$ops['prime'] as $ln) { $pn++; $insP->execute([$id, $pn, $sy, (float)$ln['val'], $ln['vt'] === 'percent' ? 'percent' : 'amount', $ln['vt'] === 'percent' ? 'LBP' : ($ln['cur'] === 'USD' ? 'USD' : 'LBP'), max(1, min(12, (int)($ln['from'] ?? 10))), max(1, min(12, (int)($ln['to'] ?? 9)))]); }
        }
        if (!empty($ops['days'])) $db->prepare("UPDATE employees SET days_per_week = ? WHERE id = ?")->execute([(int)$ops['days'], $id]);
        $applied++;
        if ((int)recalcEmployeeYear($id, $sy) > 0) $recalc++;
    }
    return ['applied' => $applied, 'recalc' => $recalc, 'skipped' => $skipped];
}
