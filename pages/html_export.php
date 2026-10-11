<?php
/**
 * 📤 Excel/Word حقيقي من أي صفحة (2026-10-08 «بس نضغط إكسل وبدنا نحفظها عالدسك توب دغري عم يحفظها ويب —
 * لازم تلقائياً يحفظها إكسل إذا إكسل، وورد إذا وورد، PDF إذا PDF، مش أنا أرجع روح على صفحة الطبع وأختار»):
 * الكبسات العامّة (ppExcel/ppWord بـexport.js) كانت تنزّل HTML باسم .xls/.doc فيفتحه أوفيس «صفحة ويب».
 * الآن المتصفّح يبعت HTML المنطقة المعروضة هنا، ونبني ملف .xlsx/.docx حقيقياً عبر ReportTable
 * (العناوين/الترويسة = صفوف أقسام، كل جدول = رأس + صفوف + مجاميع). الاتجاه يتبع المستند:
 * عربي (rtl) = الورقة من اليمين، فرنسي/لوائح الدولة (ltr) = من الشمال («إذا كانت بالعربي على اليمين وإذا بالفرنسي على الشمال»).
 *   POST: format=xlsx|docx · title · dir=rtl|ltr · landscape=0|1 · html
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/report_export.php';
requireLogin();

$format = in_array($_POST['format'] ?? '', ['docx', 'pdf'], true) ? $_POST['format'] : 'xlsx';
$title  = trim((string)($_POST['title'] ?? ''));
if ($title === '') $title = 'document';
$title  = mb_substr($title, 0, 120, 'UTF-8');
$dir    = (($_POST['dir'] ?? 'rtl') === 'ltr') ? 'ltr' : 'rtl';
$land   = !empty($_POST['landscape']) && $_POST['landscape'] !== '0';
$html   = (string)($_POST['html'] ?? '');
if (trim($html) === '') { http_response_code(400); die('no html'); }

// 🖨️ (2026-10-11) PDF جاهز للطباعة من محرّك الطباعة الرسمي (includes/pdf_engine.php — mPDF على الخادم):
//    الطباعة / «احفظ عالكمبيوتر» / واتساب / إيميل كلّها تمرّ من هنا — ورقة A4 مرتّبة بخط 12، لا تصوير شاشة.
//    landscape=auto ⇒ المحرّك يقرّر بعرض الجدول · disp=inline ⇒ يُعرض بالمتصفّح (للطباعة) وإلا تنزيل.
if ($format === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_engine.php';
    try {
        $sch = currentSchool();
        $schoolName = $sch ? (($dir === 'ltr' ? ($sch['name_fr'] ?? '') : ($sch['name_ar'] ?? '')) ?: ($sch['name_ar'] ?? $sch['name_fr'] ?? '')) : '';
        $landOpt = (($_POST['landscape'] ?? 'auto') === 'auto') ? null : $land;
        $data = MsaPdf::fromHtml($html, ['title' => $title, 'dir' => $dir, 'landscape' => $landOpt, 'school' => $schoolName, 'fit' => !empty($_POST['fit']) && $_POST['fit'] !== '0', 'lh_bg' => (string)($_POST['lh_bg'] ?? ''), 'lh_pad' => (string)($_POST['lh_pad'] ?? '')]);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('pdf error: ' . $e->getMessage());
    }
    $fname = preg_replace('/[\\\\\/:*?"<>|]+/', '_', mb_substr($title, 0, 60, 'UTF-8')) . '_' . date('Y-m-d') . '.pdf';
    $inline = (($_POST['disp'] ?? '') === 'inline');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-cache');
    echo $data; exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
$xp = new DOMXPath($dom);

// ما لا يُصدَّر: أزرار/شرائط/سطور الطباعة المحقونة/المخفي/سكربت
$cls = function ($c) { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")'; };
$drop = $xp->query('//*[' . $cls('no-print') . ' or ' . $cls('no-export') . ' or ' . $cls('export-toolbar') . ' or ' . $cls('pr-title-row') . ' or ' . $cls('pr-mask') . ' or ' . $cls('word-head') . ' or ' . $cls('scr-head-hidden') . ' or ' . $cls('badge') . ' or ' . $cls('tag') . ' or ' . $cls('pill') . ' or ' . $cls('msa-flag')
    . ' or contains(translate(@style," ",""),"display:none") or self::script or self::style or self::button or self::select or self::input or self::textarea]');
$rm = [];
foreach ($drop as $n) $rm[] = $n;
foreach ($rm as $n) if ($n->parentNode) $n->parentNode->removeChild($n);

/** نص عنصر: النصوص الفرعية مفصولة بمسافة عند حدود العناصر (192,000,000 L.L + $2,155 لا يلتصقان) */
function hx_text(DOMNode $n): string {
    if ($n->nodeType === XML_TEXT_NODE) return $n->nodeValue;
    if ($n->nodeType !== XML_ELEMENT_NODE) return '';
    $tag = strtolower($n->nodeName);
    if ($tag === 'br') return "\n";
    if (hx_has_class($n, ' money-usd ')) { $s0 = ''; foreach ($n->childNodes as $c0) $s0 .= hx_text($c0); return "\n" . $s0; } // 💵 مرآة الدولار سطر تحت الليرة (كما على الشاشة) لا جنبها // 📏 2026-10-10: سطر جديد داخل الخلية (الاسم العربي فوق الفرنسي) بدل مسافة تعرّض العمود
    $s = '';
    foreach ($n->childNodes as $c) $s .= hx_text($c) . ' ';
    return $s;
}
function hx_clean(string $s): string { $s = preg_replace('/[ \t\r\x{00A0}]+/u', ' ', $s); $s = preg_replace('/ *\n */u', "\n", $s); $s = preg_replace('/\n{2,}/u', "\n", $s); return trim($s); }
function hx_has_class(DOMNode $n, string $re): bool {
    return $n instanceof DOMElement && preg_match('/' . $re . '/', ' ' . $n->getAttribute('class') . ' ') === 1;
}

/** شبكة صفوف جدول (colspan/rowspan) ⇒ [occ[r][c] = ['cell'=>DOMElement,'c0'=>,'r0'=>,'cs'=>], cols] */
function hx_grid(array $trs): array {
    $occ = []; $cols = 0;
    foreach ($trs as $ri => $tr) {
        if (!isset($occ[$ri])) $occ[$ri] = [];
        $c = 0;
        foreach ($tr->childNodes as $cell) {
            if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
            while (isset($occ[$ri][$c])) $c++;
            $cs = max(1, (int)$cell->getAttribute('colspan')); $rs = max(1, (int)$cell->getAttribute('rowspan'));
            for ($i = 0; $i < $rs; $i++) { if (!isset($occ[$ri + $i])) $occ[$ri + $i] = []; for ($j = 0; $j < $cs; $j++) $occ[$ri + $i][$c + $j] = ['cell' => $cell, 'c0' => $c, 'r0' => $ri, 'cs' => $cs]; }
            $c += $cs;
        }
        $cols = max($cols, $c);
    }
    foreach ($occ as $r) $cols = max($cols, count($r));
    return [$occ, $cols];
}
function hx_rows_of(DOMElement $sec): array {
    $out = [];
    foreach ($sec->childNodes as $tr) if ($tr instanceof DOMElement && strtolower($tr->nodeName) === 'tr') $out[] = $tr;
    return $out;
}

$rep = new ReportTable($title, $land);
$rep->dir($dir);
$firstHead = true; $tablesN = 0; $pending = [];
// 📐 (2026-10-11) ما قبل أوّل جدول (ترويسة المدرسة/عنوان المستند/سطر المعلومات) = كتلة عنوان فوق رأس الجدول — لا «أقساماً» داخله؛
//    ما بين الجداول وبعدها يبقى أقساماً. $pending عناصره ['t' => نصّ, 'k' => school|title|meta]
$push = function (string $t, string $k = 'meta') use (&$pending) { $t = hx_clean($t); if ($t !== '' && !in_array($t, array_column($pending, 't'), true)) $pending[] = ['t' => $t, 'k' => $k]; };
$flushPending = function () use (&$pending, $rep, &$tablesN) {
    if ($tablesN === 0 && $pending) { $rep->titleLines($pending); $pending = []; return; }
    foreach ($pending as $p) $rep->sectionRow($p['t']); $pending = [];
};

$emitTable = function (DOMElement $t) use ($rep, &$firstHead, &$tablesN, &$pending, $flushPending) {
    $theadRows = []; $bodyRows = []; $footRows = [];
    foreach ($t->childNodes as $sec) {
        if (!($sec instanceof DOMElement)) continue;
        $sn = strtolower($sec->nodeName);
        if ($sn === 'thead') $theadRows = array_merge($theadRows, hx_rows_of($sec));
        elseif ($sn === 'tbody') { if (hx_has_class($sec, 'msa-top-totals')) continue; $bodyRows = array_merge($bodyRows, hx_rows_of($sec)); }
        elseif ($sn === 'tfoot') $footRows = array_merge($footRows, hx_rows_of($sec));
        elseif ($sn === 'tr') $bodyRows[] = $sec;
        elseif ($sn === 'caption') { $c = hx_clean(hx_text($sec)); if ($c !== '') $pending[] = ['t' => $c, 'k' => 'title']; }
    }
    // صف أوّل بالجسم كلّه th = رأس
    if (!$theadRows && $bodyRows) {
        $allTh = true; foreach ($bodyRows[0]->childNodes as $c) { if ($c instanceof DOMElement && strtolower($c->nodeName) === 'td') { $allTh = false; break; } }
        if ($allTh) $theadRows[] = array_shift($bodyRows);
    }
    if (!$bodyRows && !$footRows) return;
    $flushPending();
    // الرأس: أعمدة مركّبة (صفّان) تُدمج «الأب — الابن»
    $headers = [];
    if ($theadRows) {
        [$occ, $cols] = hx_grid($theadRows);
        for ($c = 0; $c < $cols; $c++) {
            $parts = []; $seen = [];
            foreach ($occ as $r) {
                if (!isset($r[$c])) continue;
                $e = $r[$c]; $k = spl_object_id($e['cell']);
                if (isset($seen[$k])) continue; $seen[$k] = 1;
                $tx = hx_clean(hx_text($e['cell']));
                if ($tx !== '') $parts[] = $tx;
            }
            $headers[] = implode(' — ', $parts);
        }
        if ($firstHead) { $rep->head($headers); $firstHead = false; } else $rep->headRow($headers);
    }
    $tablesN++;
    $emitRows = function (array $trs, bool $foot) use ($rep, $headers) {
        if (!$trs) return;
        [$occ, $cols] = hx_grid($trs);
        foreach ($trs as $ri => $tr) {
            $cells = []; $firstSpan = 0; $nonEmpty = 0;
            for ($c = 0; $c < $cols; $c++) {
                $e = $occ[$ri][$c] ?? null;
                if ($e && $e['r0'] === $ri && $e['c0'] === $c) {
                    $tx = hx_clean(hx_text($e['cell']));
                    $cells[] = $tx; if ($tx !== '') $nonEmpty++;
                    if ($c === 0) $firstSpan = $e['cs'];
                } else $cells[] = '';
            }
            if ($nonEmpty === 0) continue;
            $isTotal = $foot || hx_has_class($tr, 'total|grand|sum') || ($firstSpan > 1 && $nonEmpty > 1 && $firstSpan >= max(2, (int)($cols / 3)));
            if ($firstSpan >= max(2, $cols - 1) && $nonEmpty === 1) { $rep->sectionRow($cells[0]); continue; }
            if ($isTotal) $rep->totalRow($cells); else $rep->row($cells);
        }
    };
    $emitRows($bodyRows, false);
    $emitRows($footRows, true);
};

// المشي بترتيب المستند: عناوين/ترويسة = صفوف أقسام قبل الجدول التالي، الجداول تُصدَّر، والباقي يُنزَل فيه
$walk = function (DOMNode $node) use (&$walk, $emitTable, &$pending, $xp, $cls, $push) {
    foreach ($node->childNodes as $ch) {
        if (!($ch instanceof DOMElement)) continue;
        $tag = strtolower($ch->nodeName);
        if ($tag === 'table') { $emitTable($ch); continue; }
        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5'], true) || hx_has_class($ch, 'doc-title|card-title')) { $push(hx_text($ch), 'title'); continue; }
        if (hx_has_class($ch, 'doc-subtitle|doc-year|doc-period|report-scope')) { $push(hx_text($ch), hx_has_class($ch, 'report-scope') ? 'school' : 'meta'); continue; }
        if (hx_has_class($ch, 'doc-head')) {
            // عنوان المستند: فرنسي ثم عربي (عنوان) + الشرائح سطراً واحداً (معلومات)
            foreach ($xp->query('.//*[' . $cls('dh-fr') . ' or ' . $cls('dh-ar') . ']', $ch) as $tt) $push(hx_text($tt), 'title');
            $chips = []; foreach ($xp->query('.//*[' . $cls('dh-chip') . ']', $ch) as $cp) { $c = hx_clean(hx_text($cp)); if ($c !== '') $chips[] = $c; }
            if ($chips) $push(implode(' · ', $chips), 'meta');
            continue;
        }
        if (hx_has_class($ch, 'letterhead|gov-header|scr-head')) {
            // الترويسة: كل عنصر نهائي فيه نصّ = سطر (الشعار يُهمَل)
            $leaf = function (DOMNode $n) use (&$leaf, $push) {
                $hasEl = false; foreach ($n->childNodes as $k) if ($k instanceof DOMElement && !in_array(strtolower($k->nodeName), ['br', 'img', 'i', 'strong', 'b', 'span', 'small'], true)) { $hasEl = true; break; }
                if (!$hasEl) { $push(hx_text($n), 'school'); return; }
                foreach ($n->childNodes as $k) if ($k instanceof DOMElement) $leaf($k);
            };
            $leaf($ch); continue;
        }
        if (in_array($tag, ['img', 'svg', 'i', 'a'], true)) continue;
        $walk($ch);
    }
};
$walk($dom->getElementsByTagName('body')->item(0));
if ($tablesN === 0) {
    // صفحة بلا جداول (إفادة/كتاب): كل فقرة سطراً
    $flushPending();
    foreach ($xp->query('//body//*[self::p or self::div or self::li][not(.//p) and not(.//div) and not(.//li)]') as $p) { $tx = hx_clean(hx_text($p)); if ($tx !== '') $rep->sectionRow($tx); }
} else $flushPending();

// 📝 (2026-10-11 «طبعت إفادة وورد شوف كيف طلعت») الإفادة/الكتاب (بلا جداول بيانات) ⇒ وورد حقيقي من المستند نفسه (MsaDocx) لا جدول الكشوف
if ($format === 'docx' && $tablesN === 0) {
    require_once __DIR__ . '/../includes/docx_engine.php';
    try { $data = MsaDocx::fromHtml($html, ['title' => $title, 'dir' => $dir, 'landscape' => $land ? true : null, 'letter' => true, 'lh_bg' => (string)($_POST['lh_bg'] ?? '')]); }
    catch (Throwable $e) { $data = ''; }
    if ($data !== '' && strlen($data) > 1000) {
        $fname = preg_replace('/[\\\/:*?"<>|]+/', '_', mb_substr($title, 0, 60, 'UTF-8')) . '_' . date('Y-m-d') . '.docx';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
        header('Content-Length: ' . strlen($data)); header('Cache-Control: no-cache');
        echo $data; exit;
    }
}
if ($format === 'docx') $rep->docx(); else $rep->xlsx();
