<?php
/**
 * 🖨️ محرّك الطباعة الرسمي الموحّد — mPDF (2026-10-11)
 * «بس أرسلها للطبع تكون جاهزة دغري مش أنا أرجع رتّبها» — بدل تصوير الشاشة (html-to-image) الذي كان يطلّع صوراً
 * ثقيلة وغير مرتّبة، كل مستند (تقرير / إفادة / كشف) يُبنى هنا على الخادم كملف PDF حقيقي:
 *   • ورقة A4 (أفقية تلقائياً للجداول العريضة) بهوامش ثابتة · خط 12pt · عربي Noto Naskh / لاتيني FreeSans (Arial-like)
 *   • رأس الجدول يتكرّر على كل صفحة، السطر لا ينقسم بين صفحتين، المجاميع مرّة واحدة بآخر الجدول
 *   • الجدول الأعرض من الورقة يُقسَّم أعمدةً على أجزاء (مع تكرار عمودَي # والاسم) بدل تصغير الخط
 *   • ترويسة المدرسة (الشعار بالزاوية حسب اتجاه اللغة) · تذييل: المدرسة · صفحة n/N · تاريخ الإصدار
 * يعمل على أي استضافة (PHP فقط، بلا Chrome/LibreOffice/Python). يستعمله pages/html_export.php (format=pdf).
 */
require_once __DIR__ . '/../vendor/autoload.php';

class MsaLangToFont implements \Mpdf\Language\LanguageToFontInterface
{
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        $lang = strtolower(substr((string)$llcc, 0, 2));
        if (in_array($lang, ['ar', 'fa', 'ur', 'ps'], true)) return ['cairo', false];
        return ['freesans', false];
    }
}

class MsaPdf
{
    /** عرض الورقة القابل للكتابة (mm) */
    private static function usableWidth(bool $landscape, int $side): float
    {
        return ($landscape ? 297 : 210) - 2 * $side;
    }

    /** تقدير عرض نصّ بخط 12pt بالملم (عربي 1.9 · لاتيني 2.1 · رقم 2.3) */
    public static function textMm(string $t): float
    {
        $w = 0.0;
        if (preg_match_all('/./us', $t, $m)) {
            foreach ($m[0] as $ch) {
                $o = mb_ord($ch, 'UTF-8');
                if ($o >= 0x064B && $o <= 0x065F) continue;                 // تشكيل
                if ($o >= 0x0600 && $o <= 0x06FF) $w += 1.9;
                elseif (ctype_digit($ch)) $w += 2.3;
                elseif ($ch === ' ' || $ch === ',' || $ch === '.') $w += 1.1;
                elseif ($o > 0x2000) $w += 2.6;
                elseif (ctype_upper($ch)) $w += 2.6;
                else $w += 2.0;
            }
        }
        return $w;
    }

    private static function hasClass(DOMNode $n, string $re): bool
    {
        return $n instanceof DOMElement && preg_match('/(^|\s)(' . $re . ')(\s|$)/', $n->getAttribute('class')) === 1;
    }

    private static function text(DOMNode $n): string
    {
        if ($n->nodeType === XML_TEXT_NODE) return $n->nodeValue;
        if ($n->nodeType !== XML_ELEMENT_NODE) return '';
        $tag = strtolower($n->nodeName);
        if ($tag === 'br') return "\n";
        $s = '';
        foreach ($n->childNodes as $c) $s .= self::text($c);
        // كتلة أو شارة أو سطر ثانٍ (مرآة الدولار / نسبة الإضافي) = سطر مستقلّ بالقياس
        if (in_array($tag, ['div', 'p', 'small', 'li'], true) || self::hasClass($n, 'money-usd|rate-head|badge|tag|chip|pill|nm-fr')) return "\n" . $s . "\n";
        return $s;
    }

    /** عرض الخلية المطلوب (mm): أطول سطر؛ النصّ القابل للّف عند الفراغات يُحدّ بـ42mm (يلتفّ على سطرين)، الأرقام والنصوص بلا فراغ كاملةً */
    private static function cellMm(DOMElement $cell, bool $isHead = false): float
    {
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', self::text($cell));
        $mx = 0.0; $wrap = 0.0;
        foreach (preg_split('/\n/', $t) as $ln) {
            $ln = trim($ln); if ($ln === '') continue;
            $w = self::textMm($ln);
            $longest = 0.0; foreach (explode(' ', $ln) as $wd) $longest = max($longest, self::textMm($wd)); // أطول كلمة لا تنكسر
            $mx = max($mx, $w); $wrap = max($wrap, $longest);
        }
        if ($isHead) return max($wrap, min($mx * 0.55 + 3, 30));   // الرأس يلتفّ على سطرين أو ثلاثة
        return max($wrap, min($mx, 42));
    }

    /** يبني PDF من HTML منطقة المستند المعروضة. $o: title · dir (rtl|ltr) · landscape (bool|null) · school (اسم للتذييل) · letter (bool|null) */
    public static function fromHtml(string $html, array $o = []): string
    {
        $dir = (($o['dir'] ?? 'rtl') === 'ltr') ? 'ltr' : 'rtl';
        $rtl = $dir === 'rtl';
        $title = trim((string)($o['title'] ?? 'document'));

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        $xp = new DOMXPath($dom);
        $body = $dom->getElementsByTagName('body')->item(0);

        // ---- 1) تنظيف: ما لا يُطبع ----
        $cls = function ($c) { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")'; };
        $q = '//*[' . implode(' or ', array_map($cls, ['no-print', 'no-export', 'export-toolbar', 'pr-title-row', 'pr-mask', 'word-head', 'scr-head-hidden', 'msa-top-totals', 'att-opts', 'rl-chips', 'legal-bar', 'school-strip']))
            . ' or contains(translate(@style," ",""),"display:none") or self::script or self::style or self::button or self::select or self::input or self::textarea or self::svg or self::iframe or self::canvas'
            . ' or (self::i and contains(@class,"fa"))]';
        $rm = [];
        foreach ($xp->query($q) as $n) $rm[] = $n;
        foreach ($rm as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
        foreach ($xp->query('//a') as $a) { $a->removeAttribute('href'); }
        // نماذج الإدخال (إضافة/تعديل) بلا جدول داخلها ليست من المستند
        foreach (iterator_to_array($xp->query('//form[not(.//table)]')) as $f) if ($f->parentNode) $f->parentNode->removeChild($f);

        // ---- 2) الصور: رابط ⇒ مسار محلي ----
        $root = realpath(__DIR__ . '/..');
        $base = defined('BASE_URL') ? BASE_URL : '/';
        foreach ($xp->query('//img') as $img) {
            $src = (string)$img->getAttribute('src');
            if ($src === '' || strpos($src, 'data:') === 0) continue;
            $p = preg_replace('#^https?://[^/]+#i', '', $src);
            $p = preg_replace('/[?#].*$/', '', $p);
            $rel = null;
            if ($base !== '/' && strpos($p, $base) === 0) $rel = substr($p, strlen($base));
            elseif (strpos($p, '/') !== 0) $rel = preg_replace('#^(\.\./)+#', '', $p);
            else $rel = ltrim($p, '/');
            $abs = $root . '/' . $rel;
            if ($rel !== null && is_file($abs)) $img->setAttribute('src', str_replace('\\', '/', $abs));   // ويندوز: شرطات أمامية (saveHTML يرمّز الشرطة الخلفية بالرابط)
        }

        // ---- 3) تحويل التخطيطات المرنة (flex/grid) إلى جداول يفهمها المحرّك ----
        $mkTable = function (string $class) use ($dom) {
            $t = $dom->createElement('table'); $t->setAttribute('class', $class); $t->setAttribute('width', '100%');
            $tr = $dom->createElement('tr'); $t->appendChild($tr);
            return [$t, $tr];
        };
        $moveChildren = function (DOMNode $from, DOMNode $to) { while ($from->firstChild) $to->appendChild($from->firstChild); };
        // ترويسة المدرسة: شعار | اسم وعنوان | أرقام
        foreach (iterator_to_array($xp->query('//*[' . $cls('letterhead') . ']')) as $lh) {
            [$t, $tr] = $mkTable('pdf-lh');
            foreach (['lh-left' => 'pdf-lh-logo', 'lh-center' => 'pdf-lh-c', 'lh-right' => 'pdf-lh-r'] as $c => $tc) {
                $td = $dom->createElement('td'); $td->setAttribute('class', $tc);
                $src = $xp->query('.//*[' . $cls($c) . ']', $lh)->item(0);
                if ($src) $moveChildren($src, $td);
                $tr->appendChild($td);
            }
            $lh->parentNode->replaceChild($t, $lh);
        }
        // ترويسة حكومية: رمز النموذج | النصّ | الشعار
        foreach (iterator_to_array($xp->query('//*[' . $cls('gov-header') . ']')) as $gh) {
            [$t, $tr] = $mkTable('pdf-gov');
            foreach (['gov-code' => 'pdf-gov-code', 'gov-center' => 'pdf-gov-c', 'gov-emblem' => 'pdf-gov-e'] as $c => $tc) {
                $td = $dom->createElement('td'); $td->setAttribute('class', $tc);
                $src = $xp->query('.//*[' . $cls($c) . ']', $gh)->item(0);
                if ($src) $moveChildren($src, $td);
                $tr->appendChild($td);
            }
            $gh->parentNode->replaceChild($t, $gh);
        }
        // صفّ التواقيع
        foreach (iterator_to_array($xp->query('//*[' . $cls('sign-row') . ']')) as $sr) {
            [$t, $tr] = $mkTable('pdf-sign');
            $boxes = [];
            foreach ($sr->childNodes as $b) if ($b instanceof DOMElement) $boxes[] = $b;
            foreach ($boxes as $b) { $td = $dom->createElement('td'); $td->setAttribute('class', 'pdf-sign-box'); $moveChildren($b, $td); $tr->appendChild($td); }
            $sr->parentNode->replaceChild($t, $sr);
        }
        // شبكة المعلومات (عمودان)
        foreach (iterator_to_array($xp->query('//*[' . $cls('info-grid') . ']')) as $ig) {
            $t = $dom->createElement('table'); $t->setAttribute('class', 'pdf-info'); $t->setAttribute('width', '100%');
            $items = []; foreach ($ig->childNodes as $b) if ($b instanceof DOMElement) $items[] = $b;
            $tr = null; $col = 0;
            foreach ($items as $it) {
                $full = self::hasClass($it, 'full');
                if ($tr === null || $col >= 2 || $full) { $tr = $dom->createElement('tr'); $t->appendChild($tr); $col = 0; }
                $td = $dom->createElement('td'); if ($full) { $td->setAttribute('colspan', '2'); }
                $moveChildren($it, $td); $tr->appendChild($td); $col += $full ? 2 : 1;
                if ($full) $tr = null;
            }
            $ig->parentNode->replaceChild($t, $ig);
        }
        // اتجاه كل جدول = اتجاه المستند (ما لم يُحدَّد صراحةً) — mPDF لا يقلب أعمدة الجدول من حاويته
        foreach ($xp->query('//table[not(@dir)]') as $t) { $t->setAttribute('dir', $dir); $t->setAttribute('style', 'direction:' . $dir . ';' . $t->getAttribute('style')); }
        foreach ($xp->query('//table[@dir]') as $t) { $d2 = $t->getAttribute('dir') === 'ltr' ? 'ltr' : 'rtl'; if (strpos($t->getAttribute('style'), 'direction:') === false) $t->setAttribute('style', 'direction:' . $d2 . ';' . $t->getAttribute('style')); }
        // شرائح سطر المعلومات تحت العنوان: فاصل « · » بينها
        foreach (iterator_to_array($xp->query('//*[' . $cls('dh-chip') . ']')) as $chip) {
            $prev = $chip->previousSibling; while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) $prev = $prev->previousSibling;
            if ($prev && self::hasClass($prev, 'dh-chip')) $chip->parentNode->insertBefore($dom->createTextNode(' · '), $chip);
        }
        // شارات الشاشة (🆕 جديد / ⚠️ ملف…) ليست من المستند الرسمي
        foreach (iterator_to_array($xp->query('//*[' . $cls('badge') . ' or ' . $cls('tag') . ' or ' . $cls('pill') . ' or ' . $cls('msa-flag') . ']')) as $b) if ($b->parentNode) $b->parentNode->removeChild($b);
        // سمات الشاشة التي تخرّب الورق
        foreach ($xp->query('//*[@style]') as $el) {
            $st = (string)$el->getAttribute('style');
            if (preg_match('/zoom|transform|position\s*:\s*(sticky|fixed)|overflow|max-height|min-width|box-shadow|cursor/i', $st)) {
                $st = preg_replace('/(zoom|transform|position|top|left|overflow(-x|-y)?|max-height|min-width|box-shadow|cursor|white-space)\s*:[^;]*;?/i', '', $st);
                if (trim($st) === '') $el->removeAttribute('style'); else $el->setAttribute('style', $st);
            }
        }

        // ---- 4) الجداول: tfoot ⇒ آخر الجسم (المجاميع مرّة واحدة لا بكل صفحة) + تقسيم العريض أعمدةً ----
        $landscape = $o['landscape'] ?? null;
        $fitOne = !empty($o['fit']);   // 📄 «ورقة وحدة»: لا تقسيم أعمدةً — الجدول العريض يصغّر خطّه ليسع عرض الورقة
        $isLetter = $o['letter'] ?? null;
        $tables = [];
        foreach ($xp->query('//table') as $t) if (!$xp->query('ancestor::table', $t)->length) $tables[] = $t;
        $maxCols = 0; $dataTables = 0;
        foreach ($tables as $t) {
            if (self::hasClass($t, 'pdf-lh|pdf-gov|pdf-sign|pdf-info|xlsf')) continue;
            foreach (iterator_to_array($xp->query('./tfoot', $t)) as $tf) {
                $tb = $xp->query('./tbody', $t); $tb = $tb->length ? $tb->item($tb->length - 1) : null;
                if (!$tb) { $tb = $dom->createElement('tbody'); $t->appendChild($tb); }
                foreach (iterator_to_array($xp->query('./tr', $tf)) as $tr) { $tr->setAttribute('class', trim($tr->getAttribute('class') . ' total-row')); $tb->appendChild($tr); }
                $tf->parentNode->removeChild($tf);
            }
            $rows = $xp->query('.//tr', $t);
            if ($rows->length < 2) continue;
            $dataTables++;
            $t->setAttribute('class', trim($t->getAttribute('class') . ' pdf-t'));
            $t->removeAttribute('style');
            $first = $rows->item(0); $nc = 0;
            foreach ($first->childNodes as $c) if ($c instanceof DOMElement && in_array(strtolower($c->nodeName), ['td', 'th'], true)) $nc += max(1, (int)$c->getAttribute('colspan'));
            $maxCols = max($maxCols, $nc);
        }
        if ($isLetter === null) $isLetter = ($dataTables === 0);
        $side = $isLetter ? 20 : 10;

        // عرض الأعمدة الطبيعي لكل جدول (لتقرير الاتجاه والتقسيم)
        $measure = function (DOMElement $t) use ($xp) {
            $widths = []; $n = 0;
            foreach ($xp->query('.//tr', $t) as $tr) {
                $c = 0;
                foreach ($tr->childNodes as $cell) {
                    if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
                    $cs = max(1, (int)$cell->getAttribute('colspan'));
                    if ($cs === 1) { $w = self::cellMm($cell, strtolower($cell->nodeName) === 'th') + 4.5; $widths[$c] = max($widths[$c] ?? 0, min($w, 70)); }
                    $c += $cs;
                }
                $n = max($n, $c);
            }
            for ($i = 0; $i < $n; $i++) $widths[$i] = max($widths[$i] ?? 0, 8);
            ksort($widths);
            return $widths;
        };
        $natural = []; $widest = 0;
        foreach ($tables as $i => $t) {
            if (!self::hasClass($t, 'pdf-t')) continue;
            $natural[$i] = $measure($t); $widest = max($widest, array_sum($natural[$i]));
        }
        if ($landscape === null) $landscape = $widest > self::usableWidth(false, $side) || $maxCols >= 9;
        $usable = self::usableWidth($landscape, $side);

        // تقسيم الجدول الأعرض من الورقة (حتى بعد سماح تصغير 15٪) على أجزاء بالعرض مع تكرار أعمدة التعريف
        foreach ($tables as $i => $t) {
            if (!isset($natural[$i])) continue;
            $w = $natural[$i]; $sum = array_sum($w);
            if ($fitOne || $sum <= $usable * 1.15) continue;
            $cols = count($w);
            // أعمدة المفتاح: الأوّل إن كان ضيّقاً (#) + أوّل عمود نصّي بعده (الاسم)
            $key = [];
            if ($w[0] <= 14) $key[] = 0;
            for ($c = 0; $c < min(3, $cols); $c++) { if (!in_array($c, $key, true)) { $key[] = $c; break; } }
            $keyW = 0; foreach ($key as $c) $keyW += $w[$c];
            if ($keyW > $usable * 0.4) { $key = [$key[0]]; $keyW = $w[$key[0]]; }
            $parts = []; $cur = []; $curW = $keyW;
            for ($c = 0; $c < $cols; $c++) {
                if (in_array($c, $key, true)) continue;
                if ($cur && $curW + $w[$c] > $usable) { $parts[] = $cur; $cur = []; $curW = $keyW; }
                $cur[] = $c; $curW += $w[$c];
            }
            if ($cur) $parts[] = $cur;
            if (count($parts) < 2) continue;
            // صفوف الجدول مع شبكة colspan/rowspan — نبني نسخة لكل جزء
            $trs = iterator_to_array($xp->query('.//tr', $t));
            $occ = [];
            foreach ($trs as $ri => $tr) {
                if (!isset($occ[$ri])) $occ[$ri] = [];
                $c = 0;
                foreach ($tr->childNodes as $cell) {
                    if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
                    while (isset($occ[$ri][$c])) $c++;
                    $cs = max(1, (int)$cell->getAttribute('colspan')); $rs = max(1, (int)$cell->getAttribute('rowspan'));
                    for ($a = 0; $a < $rs; $a++) { if (!isset($occ[$ri + $a])) $occ[$ri + $a] = []; for ($b = 0; $b < $cs; $b++) $occ[$ri + $a][$c + $b] = ['cell' => $cell, 'c0' => $c, 'r0' => $ri]; }
                    $c += $cs;
                }
            }
            $wrap = $dom->createElement('div');
            $np = count($parts);
            foreach ($parts as $pi => $part) {
                $idx = array_merge($key, $part); sort($idx);
                $nt = $dom->createElement('table');
                $nt->setAttribute('class', $t->getAttribute('class'));
                $nt->setAttribute('width', '100%');
                // الأقسام (thead/tbody) تُعاد كما هي
                foreach ($t->childNodes as $sec) {
                    if (!($sec instanceof DOMElement)) continue;
                    $sn = strtolower($sec->nodeName);
                    if (!in_array($sn, ['thead', 'tbody', 'tr'], true)) continue;
                    $nsec = ($sn === 'tr') ? $nt : $dom->createElement($sn);
                    $secRows = ($sn === 'tr') ? [$sec] : iterator_to_array($xp->query('./tr', $sec));
                    foreach ($secRows as $tr) {
                        $ri = array_search($tr, $trs, true);
                        $ntr = $dom->createElement('tr'); if ($tr->getAttribute('class')) $ntr->setAttribute('class', $tr->getAttribute('class'));
                        $done = [];
                        foreach ($idx as $c) {
                            $e = $occ[$ri][$c] ?? null;
                            if (!$e || $e['r0'] !== $ri) continue;
                            $k = spl_object_id($e['cell']); if (isset($done[$k])) continue; $done[$k] = 1;
                            $nc = $e['cell']->cloneNode(true);
                            // colspan: عدد أعمدة هذه الخلية الموجودة بهذا الجزء
                            $span = 0; $cs = max(1, (int)$e['cell']->getAttribute('colspan'));
                            for ($b = $e['c0']; $b < $e['c0'] + $cs; $b++) if (in_array($b, $idx, true)) $span++;
                            if ($span > 1) $nc->setAttribute('colspan', (string)$span); else $nc->removeAttribute('colspan');
                            $ntr->appendChild($nc);
                        }
                        if ($ntr->childNodes->length) $nsec->appendChild($ntr);
                    }
                    if ($sn !== 'tr') $nt->appendChild($nsec);
                }
                $cap = $dom->createElement('div');
                $cap->setAttribute('class', 'pdf-part');
                $cap->appendChild($dom->createTextNode(($rtl ? ('الجزء ' . ($pi + 1) . ' من ' . $np) : ('Partie ' . ($pi + 1) . ' / ' . $np)) . ($pi > 0 ? ($rtl ? ' — تابع الأعمدة' : ' — suite des colonnes') : '')));
                if ($pi > 0) { $pb = $dom->createElement('div'); $pb->setAttribute('class', 'pdf-break'); $wrap->appendChild($pb); }
                $wrap->appendChild($cap);
                $wrap->appendChild($nt);
            }
            $t->parentNode->replaceChild($wrap, $t);
        }

        // ---- 5) mPDF ----
        $root = realpath(__DIR__ . '/..');
        $tmp = $root . '/tmp/mpdf'; if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
        $cfg = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fcfg = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontdata = $fcfg['fontdata'];
        $fontdata['naskh'] = ['R' => 'NotoNaskhArabic-Regular.ttf', 'B' => 'NotoNaskhArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75];
        $fontdata['cairo'] = ['R' => 'CairoArabic-Regular.ttf', 'B' => 'CairoArabic-Bold.ttf', 'useOTL' => 0xFF];
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'margin_left' => $side, 'margin_right' => $side,
            'margin_top' => $isLetter ? 15 : 10, 'margin_bottom' => $isLetter ? 15 : 14,
            'margin_header' => 5, 'margin_footer' => 6,
            'tempDir' => $tmp,
            'fontDir' => array_merge($cfg['fontDir'], [$root . '/assets/fonts/ttf']),
            'fontdata' => $fontdata,
            'default_font' => $rtl ? 'cairo' : 'freesans',
            'default_font_size' => 12,
            'autoScriptToLang' => true,
            'autoLanguageToFont' => true,
            'languageToFont' => new MsaLangToFont(),
            'useSubstitutions' => true,
            'backupSubsFont' => ['freesans', 'dejavusans'],
            'shrink_tables_to_fit' => $fitOne ? 3 : 1.15,
            'use_kwt' => true,
            'img_dpi' => 96,
            'setAutoTopMargin' => false,
            'setAutoBottomMargin' => false,
        ]);
        $mpdf->SetDirectionality($dir);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor((string)($o['school'] ?? ''));
        $mpdf->simpleTables = true;   // حدود موحّدة = أسرع بكثير بالجداول الكبيرة
        $mpdf->packTableData = true;
        $mpdf->keep_table_proportions = true;
        if (!$isLetter) {
            $school = htmlspecialchars((string)($o['school'] ?? ''), ENT_QUOTES, 'UTF-8');
            $date = date('d/m/Y');
            $pg = $rtl ? 'صفحة {PAGENO} من {nbpg}' : 'Page {PAGENO} / {nbpg}';
            $mpdf->SetHTMLFooter('<table width="100%" class="pdf-foot"><tr><td width="40%" class="pdf-foot-a">' . $school . '</td><td width="20%" align="center">' . $pg . '</td><td width="40%" class="pdf-foot-b">' . ($rtl ? 'صدر بتاريخ ' : 'Émis le ') . $date . '</td></tr></table>');
        }
        $mpdf->WriteHTML(self::css($rtl, $isLetter), \Mpdf\HTMLParserMode::HEADER_CSS);
        $inner = '';
        foreach ($body->childNodes as $ch) $inner .= $dom->saveHTML($ch);
        if (!empty($o['debug_html'])) @file_put_contents($o['debug_html'], $inner);
        $mpdf->WriteHTML('<div class="pdf-doc" dir="' . $dir . '">' . $inner . '</div>', \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** ستايل الورق الموحّد — مجموعة CSS يفهمها mPDF (بلا flex/grid/position) */
    private static function css(bool $rtl, bool $letter): string
    {
        $al = $rtl ? 'right' : 'left';
        $op = $rtl ? 'left' : 'right';
        return <<<CSS
body { font-size: 12pt; color: #111; line-height: 1.55; }
.pdf-doc { text-align: $al; }
h1, h2, h3, h4 { color: #1e3a8a; margin: 4pt 0 6pt; line-height: 1.3; }
h1 { font-size: 18pt; } h2 { font-size: 15pt; } h3 { font-size: 13pt; } h4 { font-size: 12pt; }
p, .doc-p { margin: 4pt 0; }
.doc-sheet, .official-doc, .land-report, #ppExportArea, .card, .card-body { background: #fff; border: 0; padding: 0; margin: 0; }
/* ترويسة المدرسة */
table.pdf-lh { width: 100%; border-collapse: collapse; border-bottom: 1.5pt solid #1e3a8a; margin-bottom: 8pt; }
table.pdf-lh td { border: 0; padding: 0 0 6pt 0; vertical-align: middle; }
td.pdf-lh-logo { width: 26mm; text-align: $al; }
td.pdf-lh-logo img { width: 22mm; max-height: 22mm; }
td.pdf-lh-c { text-align: center; }
td.pdf-lh-r { width: 38mm; text-align: $op; font-size: 10.5pt; color: #334155; }
.lh-name-ar { font-size: 16pt; font-weight: bold; color: #1e3a8a; }
.lh-name-fr { font-size: 11pt; font-weight: bold; color: #475569; }
.lh-contact { font-size: 10pt; color: #64748b; }
.report-scope { text-align: center; font-weight: bold; color: #1e3a8a; border-bottom: 1.5pt solid #1e3a8a; padding-bottom: 5pt; margin-bottom: 8pt; }
/* ترويسة حكومية */
table.pdf-gov { width: 100%; border-collapse: collapse; border-bottom: 0.5pt solid #cbd5e1; margin-bottom: 8pt; }
table.pdf-gov td { border: 0; vertical-align: top; padding: 0 0 4pt 0; }
td.pdf-gov-code { width: 22mm; font-weight: bold; text-align: center; }
td.pdf-gov-c { text-align: center; font-weight: bold; }
td.pdf-gov-e { width: 22mm; text-align: center; } td.pdf-gov-e img { height: 16mm; }
.gov-title { color: #1e3a8a; font-size: 13pt; }
/* عنوان المستند */
.doc-head { text-align: center; margin: 2pt 0 8pt; }
.doc-head .dh-fr { font-size: 14pt; font-weight: bold; color: #1e3a8a; }
.doc-head .dh-ar { font-size: 16pt; font-weight: bold; color: #1e3a8a; }
.doc-head .dh-meta { font-size: 10pt; color: #475569; margin-top: 3pt; }
.doc-head .dh-chip { display: inline; padding: 0 5pt; border-right: 0.5pt solid #cbd5e1; }
.doc-title { text-align: center; font-size: 16pt; font-weight: bold; color: #1e3a8a; margin: 6pt 0 10pt; }
.doc-subtitle, .doc-year { text-align: center; color: #475569; margin: -4pt 0 8pt; }
.doc-section { font-weight: bold; color: #1e3a8a; margin: 10pt 0 4pt; border-$al: 2pt solid #d4af37; padding-$al: 6pt; }
.doc-note { font-size: 10.5pt; color: #64748b; border-top: 0.5pt dashed #cbd5e1; padding-top: 4pt; margin-top: 10pt; }
.pdf-part { font-weight: bold; color: #1e3a8a; margin: 8pt 0 2pt; }
.pdf-break { page-break-before: always; }
/* الجداول */
table.pdf-t { width: 100%; border-collapse: collapse; margin: 6pt 0 8pt; page-break-inside: auto; }
table.pdf-t thead th, table.pdf-t th { background: #1F4E5F; color: #fff; font-weight: bold; text-align: center; vertical-align: middle; padding: 3.5pt 4pt; border: 0.5pt solid #1F4E5F; font-size: 11pt; }
table.pdf-t td { border: 0.5pt solid #94a3b8; padding: 3pt 4pt; text-align: center; vertical-align: middle; }
table.pdf-t td.num, table.pdf-t td.money, table.pdf-t td.n { text-align: $op; }
table.pdf-t tr { page-break-inside: avoid; }
table.pdf-t tr.total-row td, table.pdf-t tr.grand-row td, table.pdf-t tr.sum-row td { background: #e2e8f0; font-weight: bold; }
table.pdf-t tr.subtotal-row td { background: #f1f5f9; font-weight: bold; }
table.pdf-t tr.section-row td, table.pdf-t td.section { background: #e0e7ff; font-weight: bold; text-align: $al; }
table.pdf-t th .rate-head, table.pdf-t th small { font-weight: normal; font-size: 9pt; }
.money-usd { display: block; color: #047857; font-weight: bold; font-size: 10.5pt; }
.money-usd-inline { color: #047857; font-weight: bold; }
small { font-size: 9.5pt; }
/* نماذج وإفادات */
table.pdf-info { width: 100%; border-collapse: collapse; margin: 8pt 0; }
table.pdf-info td { width: 50%; border: 0; border-bottom: 0.5pt dotted #475569; padding: 4pt 4pt 2pt; vertical-align: bottom; }
table.pdf-info .k { color: #334155; } table.pdf-info .fill { font-weight: bold; color: #0a3d91; }
.fill { font-weight: bold; color: #0f172a; padding: 0 3pt; }
.fill.dotted { border-bottom: 1pt dotted #475569; }
.fline { margin: 6pt 0; } .fline .lbl { color: #111; } .fline .val { font-weight: bold; color: #0a3d91; border-bottom: 0.8pt dotted #475569; padding: 0 5pt; }
.opt .n { border: 0.8pt solid #333; padding: 0 4pt; font-weight: bold; margin: 0 2pt; } .opt.on .n { background: #0a3d91; color: #fff; }
.digit-boxes span { border: 0.8pt solid #333; padding: 1pt 4pt; margin: 0 1pt; font-family: freesans; }
.kv { margin: 3pt 0; } .kv .k { color: #475569; }
/* التواقيع */
table.pdf-sign { width: 100%; border-collapse: collapse; margin-top: 18pt; page-break-inside: avoid; }
table.pdf-sign td { border: 0; text-align: center; vertical-align: top; padding: 0 10pt; }
.sign-label { color: #334155; margin-bottom: 30pt; } .sign-place { color: #64748b; margin-bottom: 6pt; }
.sign-line { border-top: 0.8pt solid #475569; margin: 0 14pt; height: 2pt; }
/* التذييل */
table.pdf-foot { width: 100%; font-size: 9pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; }
table.pdf-foot td { border: 0; padding: 2pt 0 0; }
td.pdf-foot-a { text-align: $al; } td.pdf-foot-b { text-align: $op; }
/* قسائم / نماذج من إكسل */
table.xlsf td, table.xlsf th { font-size: 10pt; }
.badge, .chip, .tag { padding: 1pt 4pt; }
img { max-width: 100%; }
CSS;
    }
}
