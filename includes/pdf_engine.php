<?php
/**
 * 🖨️ محرّك الطباعة الرسمي الموحّد — mPDF (2026-10-11)
 * «بس أرسلها للطبع تكون جاهزة دغري مش أنا أرجع رتّبها» — بدل تصوير الشاشة (html-to-image) الذي كان يطلّع صوراً
 * ثقيلة وغير مرتّبة، كل مستند (تقرير / إفادة / كشف) يُبنى هنا على الخادم كملف PDF حقيقي:
 *   • ورقة A4 (أفقية تلقائياً للجداول العريضة) بهوامش ثابتة · خط 12pt · عربي Noto Naskh / لاتيني FreeSans (Arial-like)
 *   • رأس الجدول يتكرّر على كل صفحة، السطر لا ينقسم بين صفحتين، المجاميع مرّة واحدة بآخر الجدول
 *   • الجدول الأعرض من الورقة يُقسَّم أعمدةً على أجزاء (مع تكرار عمودَي # والاسم) بدل تصغير الخط
 *   • 📏 (2026-10-15 «الأعمدة أوسع من الكلمات اللي جوّاتها… صفحات كتير») عرض كل عمود = أعرض محتوى فيه مقيساً بالخطوط الحقيقية
 *     (Cairo مشكَّلاً / FreeSans عبر mPDF نفسه) ويُكتب صريحاً على الجدول؛ عمودي إلا إذا لم يسع الجدول؛ السطر 12pt بارتفاع 1.15
 *     (كان صندوق خط Cairo يرفعه إلى 7.3mm)؛ النماذج الرسمية (.official-doc) خارج ذلك كلّه
 *   • ترويسة المدرسة (الشعار بالزاوية حسب اتجاه اللغة) · تذييل: المدرسة · صفحة n/N · تاريخ الإصدار
 * يعمل على أي استضافة (PHP فقط، بلا Chrome/LibreOffice/Python). يستعمله pages/html_export.php (format=pdf).
 */
require_once __DIR__ . '/../vendor/autoload.php';

class MsaLangToFont implements \Mpdf\Language\LanguageToFontInterface
{
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        $lang = strtolower(substr((string)$llcc, 0, 2));
        // 🔤 (2026-10-17) خطّ الورق = خطّ الشاشة (Arial بالعربي والأجنبي) — Cairo/FreeSans احتياط فقط
        return ['arial', false];
    }
}

class MsaPdf
{
    /** عرض الورقة القابل للكتابة (mm) */
    public static function usableWidth(bool $landscape, int $side): float
    {
        return ($landscape ? 297 : 210) - 2 * $side;
    }

    /** 📏 (2026-10-15 «الأعمدة أوسع من الأرقام أو الكلمات اللي جوّاتها») قياس عرض النصّ بالخطوط الحقيقية (Cairo بالتشكيل الكامل / FreeSans)
     *  عبر محرّك mPDF نفسه بدل التخمين بالملم — نسخة قياس واحدة مخفيّة + ذاكرة لكل نصّ (2000 خلية ≈ 0.3 ث) */
    private static $meas = null;
    private static $measCache = [];
    private static function measurer(): ?array
    {
        if (self::$meas !== null) return self::$meas ?: null;
        try {
            $root = realpath(__DIR__ . '/..');
            $tmp = $root . '/tmp/mpdf'; if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
            $cfg = (new \Mpdf\Config\ConfigVariables())->getDefaults();
            $fcfg = (new \Mpdf\Config\FontVariables())->getDefaults();
            $fontdata = $fcfg['fontdata'];
            $fontdata['cairo'] = ['R' => 'CairoArabic-Regular.ttf', 'B' => 'CairoArabic-Bold.ttf', 'useOTL' => 0xFF];
            $fontdata['arial'] = ['R' => 'arial.ttf', 'B' => 'arialbd.ttf', 'useOTL' => 0xFF, 'useKashida' => 75];   // 🔤 (2026-10-17) القياس بخطّ الورق الفعلي
            $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'tempDir' => $tmp, 'fontDir' => array_merge($cfg['fontDir'], [$root . '/assets/fonts/ttf']), 'fontdata' => $fontdata, 'default_font' => 'arial', 'default_font_size' => 12]);
            $otl = (function () { return $this->otl; })->call($m);   // خاصّة بالمحرّك: نحتاجها لتشكيل العربي قبل القياس
            $m->SetFont('arial', '', 12); $m->SetFont('cairo', '', 12); $m->SetFont('freesans', '', 12);
            self::$meas = [$m, $otl];
        } catch (\Throwable $e) { self::$meas = false; }
        return self::$meas ?: null;
    }

    /** عرض النصّ بالملم بالخط الحقيقي (حجم $pt، عريض $bold)؛ عند تعذّر القياس يرجع للتقدير */
    public static function textMm(string $t, bool $bold = false, float $pt = 12): float
    {
        if ($t === '') return 0.0;
        $k = ($bold ? 'b' : 'r') . $pt . '|' . $t;
        if (isset(self::$measCache[$k])) return self::$measCache[$k];
        $w = null;
        if ($mm = self::measurer()) {
            try {
                [$m, $otl] = $mm; $w = 0.0;
                foreach (preg_split('/([\p{Arabic}\x{060C}\x{061B}\x{061F}\x{0640}\s]+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $run) {
                    $ar = preg_match('/\p{Arabic}/u', $run) === 1;
                    $m->SetFont('arial', $bold ? 'B' : '', $pt);   // 🔤 (2026-10-17) القياس بـArial كما يُطبع
                    if ($ar && !empty($m->CurrentFont['useOTL'])) { $m->OTLtags = []; $sh = $otl->applyOTL($run, $m->CurrentFont['useOTL']); $w += $m->GetStringWidth($sh, true, $otl->OTLdata, 0); }
                    else $w += $m->GetStringWidth($run);
                }
            } catch (\Throwable $e) { $w = null; }
        }
        if ($w === null) $w = self::textMmEst($t) * ($pt / 12) * ($bold ? 1.07 : 1);
        if (count(self::$measCache) > 20000) self::$measCache = [];
        return self::$measCache[$k] = $w;
    }

    /** تقدير احتياطي لعرض نصّ بخط 12pt بالملم (عربي 1.9 · لاتيني 2.1 · رقم 2.3) — يُستعمل فقط إن تعذّر القياس الحقيقي */
    public static function textMmEst(string $t): float
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
        if (self::hasClass($n, 'money-usd')) return " \x03" . trim($s) . "\x04";                   // مرآة الدولار: بنفس السطر بعد فراغ، 9.5pt عريض
        if ($tag === 'small' || self::hasClass($n, 'rate-head|nm-fr')) return "\n\x02" . $s . "\n";   // سطر ثانوي 9.5pt
        if (in_array($tag, ['div', 'p', 'li'], true) || self::hasClass($n, 'badge|tag|chip|pill')) return "\n" . $s . "\n";
        return $s;
    }

    /** عرض الخلية المطلوب (mm): أطول سطر؛ النصّ القابل للّف عند الفراغات يُحدّ بـ42mm (يلتفّ على سطرين)، الأرقام والنصوص بلا فراغ كاملةً */
    /** عرض الخلية (mm) ⇒ [كامل بلا التفاف, ملتفّ]: الكامل = أطول سطر؛ الملتفّ = النصّ القابل للّف يُحدّ (الاسم 55mm · الجملة ≥5 كلمات 42mm) وأطول كلمة لا تنكسر.
     *  الرأس يُقاس ملتفّاً دائماً (سطران أو ثلاثة لا يزيدان الصفوف). */
    private static function cellMm(DOMElement $cell, bool $isHead = false, bool $bold = false): array
    {
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', self::text($cell));
        $mx = 0.0; $wrap = 0.0; $mxWrap = 0.0; $mxStack = 0.0;
        foreach (preg_split('/\n/', $t) as $ln) {
            $ln = trim($ln); if ($ln === '') continue;
            $pt = $isHead ? 11 : 12; $b = $bold || $isHead;
            if ($ln[0] === "\x01") { $pt = 10.5; $b = true; $ln = trim(substr($ln, 1)); }
            elseif ($ln[0] === "\x02") { $pt = 9.5; $b = false; $ln = trim(substr($ln, 1)); }
            if ($ln === '') continue;
            // مقاطع السطر: ما بين \x03…\x04 = مرآة الدولار 9.5pt عريض، والباقي بحجم السطر
            $w = 0.0; $longest = 0.0; $segMax = 0.0;
            foreach (preg_split('/(\x03[^\x04]*\x04)/u', $ln, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $seg) {
                $usd = ($seg[0] === "\x03"); if ($usd) $seg = trim($seg, "\x03\x04");
                $sp = $usd ? 9.5 : $pt; $sb = $usd ? true : $b;
                $sw = self::textMm($seg, $sb, $sp); $w += $sw; $segMax = max($segMax, $sw);
                foreach (explode(' ', $seg) as $wd) $longest = max($longest, self::textMm($wd, $sb, $sp)); // أطول كلمة لا تنكسر
            }
            $mx = max($mx, $w); $wrap = max($wrap, $longest);
            // صفوف المجاميع (عريضة وقليلة): بالنسخة الملتفّة يُسمح لمرآة الدولار أن تنزل سطراً ثانياً فلا يوسّع المجموع العمود كلّه
            $mxWrap = max($mxWrap, ($bold && !$isHead) ? $segMax : $w);
            $mxStack = max($mxStack, $segMax);   // النسخة المكدَّسة: مرآة الدولار سطراً ثانياً بكل الصفوف
        }
        if ($isHead) { $h = max($wrap, min($mx * 0.55 + 2, 30)); return [$h, $h, $h]; }
        $cap = (substr_count(trim($t), ' ') >= 4) ? 42 : 55;
        return [max($wrap, $mx), max($wrap, min($mxWrap, $cap)), max($wrap, min($mxStack, $cap))];
    }

    /** تحضير HTML المستند (تنظيف + تحويل التخطيطات + الجداول) — مشترك بين الـPDF (mPDF) والوورد (MsaDocx). يُرجع dom/body/dir/rtl/landscape/isLetter/side/title */
    public static function prepare(string $html, array $o = []): array
    {
        $dir = (($o['dir'] ?? 'rtl') === 'ltr') ? 'ltr' : 'rtl';
        $rtl = $dir === 'rtl';
        $title = trim((string)($o['title'] ?? 'document'));

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        $xp = new DOMXPath($dom);
        $body = $dom->getElementsByTagName('body')->item(0);

        // ---- 0) الترويسة البديلة .word-head (شعار + اسم + هاتف، بلا خط): الوورد يكشفها ويشيل ترويسة الشاشة؛ الـPDF يشيلها (display:none) ----
        $cls = function ($c) { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")'; };
        if (!empty($o['word_head'])) {
            $wh = $xp->query('//*[' . $cls('word-head') . ']');
            if ($wh->length) {
                foreach ($wh as $w) { $w->setAttribute('style', preg_replace('/display\s*:\s*none;?/i', '', (string)$w->getAttribute('style'))); $w->setAttribute('class', 'word-head-on'); } // لا تُمسح بالتنظيف أدناه
                foreach (iterator_to_array($xp->query('//*[' . $cls('scr-head') . ']')) as $sh) if ($sh->parentNode) $sh->parentNode->removeChild($sh);
            }
        }
        // ---- 1) تنظيف: ما لا يُطبع ----
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
        // مرآة الدولار بعد الليرة: فراغ قبلها ليكون الكسر ممكناً عندها (mPDF لا يفصلها سطراً مستقلاً داخل الخلية)
        foreach (iterator_to_array($xp->query('//*[' . $cls('money-usd') . ']')) as $mu) {
            $pv = $mu->previousSibling;
            if (!$pv || $pv->nodeType !== XML_TEXT_NODE || !preg_match('/\s$/u', $pv->nodeValue)) $mu->parentNode->insertBefore($dom->createTextNode(' '), $mu);
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
        // لكل جدول: عرض الأعمدة «الكامل» (بلا التفاف) و«الملتفّ» + عدد الصفوف وعدد الخلايا التي ستلتفّ لو اعتُمد الملتفّ
        $measure = function (DOMElement $t) use ($xp) {
            $full = []; $wrap = []; $stack = []; $n = 0; $rows = 0; $wrapCells = 0; $wrapRows = 0; $usdRows = 0; $nameCol = -1;
            foreach ($xp->query('.//tr', $t) as $tr) {
                $c = 0; $hasTd = false; $hasUsd = false; $hasWrap = false;
                $rowBold = preg_match('/(total|grand|sum|subtotal)-row/', $tr->getAttribute('class')) === 1;
                foreach ($tr->childNodes as $cell) {
                    if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
                    $isTh = strtolower($cell->nodeName) === 'th'; if (!$isTh) $hasTd = true;
                    $cs = max(1, (int)$cell->getAttribute('colspan'));
                    if ($isTh && $cs === 1 && $nameCol < 0 && preg_match('/الاسم|\bnoms?\b|\bname\b/iu', $cell->textContent)) $nameCol = $c;
                    if ($cs === 1) {
                        $bold = $rowBold || $xp->query('.//b|.//strong', $cell)->length > 0 || preg_match('/font-weight\s*:\s*(bold|[6-9]00)/i', (string)$cell->getAttribute('style')) === 1;
                        [$f, $w, $s] = self::cellMm($cell, $isTh, $bold);
                        $f = min($f + 3.2, 80); $w = min($w + 3.2, 80); $s = min($s + 3.2, 80);   // حشوة 3pt×2 + حدّ + أمان؛ سقف 80mm
                        if (!$isTh && $f > $w + 0.5) { $wrapCells++; $hasWrap = true; }
                        if (!$isTh && $s < $w - 0.5) $hasUsd = true;
                        $full[$c] = max($full[$c] ?? 0, $f); $wrap[$c] = max($wrap[$c] ?? 0, $w); $stack[$c] = max($stack[$c] ?? 0, $s);
                    }
                    $c += $cs;
                }
                if ($hasTd) $rows++;
                if ($hasUsd) $usdRows++;
                if ($hasWrap) $wrapRows++;
                $n = max($n, $c);
            }
            for ($i = 0; $i < $n; $i++) { $full[$i] = max($full[$i] ?? 0, 7); $wrap[$i] = max($wrap[$i] ?? 0, 7); $stack[$i] = max($stack[$i] ?? 0, 7); }
            ksort($full); ksort($wrap); ksort($stack);
            return ['full' => $full, 'wrap' => $wrap, 'stack' => $stack, 'rows' => max(1, $rows), 'wrapCells' => $wrapCells, 'wrapRows' => $wrapRows, 'usdRows' => $usdRows, 'nameCol' => $nameCol];
        };
        // تقسيم أعمدة جدول أعرض من الورقة على أجزاء: أعمدة المفتاح (# + أوّل عمود نصّي) تتكرّر بكل جزء ⇒ [key[], parts[][]]
        $partition = function (array $w, float $usable, int $nameCol = -1): array {
            $cols = count($w);
            $key = [];
            if ($w[0] <= 14) $key[] = 0;
            // عمود الاسم (من رأسه: الاسم / Nom / Name) يتكرّر بكل جزء — صفّ راتب بلا اسم لا يُقرأ؛ وإلّا أوّل عمود نصّي
            if ($nameCol >= 0 && $nameCol < $cols && !in_array($nameCol, $key, true)) $key[] = $nameCol;
            else for ($c = 0; $c < min(3, $cols); $c++) { if (!in_array($c, $key, true)) { $key[] = $c; break; } }
            sort($key);
            $keyW = 0; foreach ($key as $c) $keyW += $w[$c];
            if ($keyW > $usable * 0.45) { $key = [$key[0]]; $keyW = $w[$key[0]]; }
            $parts = []; $cur = []; $curW = $keyW;
            for ($c = 0; $c < $cols; $c++) {
                if (in_array($c, $key, true)) continue;
                if ($cur && $curW + $w[$c] > $usable) { $parts[] = $cur; $cur = []; $curW = $keyW; }
                $cur[] = $c; $curW += $w[$c];
            }
            if ($cur) $parts[] = $cur;
            return [$key, $parts];
        };
        // تقدير عدد الأوراق لجدول بعرض أعمدة معيّن على ورقة معيّنة: الأجزاء × الصفوف (سطر 6.2mm) ÷ ارتفاع الورقة المتاح
        $pagesOf = function (array $w, float $usable, bool $land, int $lines, int $nameCol = -1) use ($partition): array {
            $sum = array_sum($w);
            $parts = ($sum <= $usable * 1.15) ? 1 : count($partition($w, $usable, $nameCol)[1]);
            $avail = ($land ? 210 : 297) - 24 - 45;   // الهوامش + الترويسة والعنوان
            return [$parts * max(1, (int)ceil($lines * 6.2 / $avail)), $parts];
        };
        // 📐 الأعمدة بعرض محتواها بالضبط: عرض صريح لكل عمود + عرض الجدول = مجموعها (يُمدّ ليملأ الورقة فقط إن كان قريباً منها ≥ 85٪)
        $applyWidths = function (DOMElement $t, array $w, float $usable) use ($xp) {
            $sum = array_sum($w); if ($sum <= 0) return;
            $scale = ($sum > $usable || $sum < $usable * 0.85) ? 1.0 : $usable / $sum;
            $n = count($w);
            foreach ($xp->query('.//tr', $t) as $tr) {
                $cells = []; $plain = true;
                foreach ($tr->childNodes as $cell) {
                    if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
                    if ((int)$cell->getAttribute('colspan') > 1 || (int)$cell->getAttribute('rowspan') > 1) $plain = false;
                    $cells[] = $cell;
                }
                if (!$plain || count($cells) !== $n) continue;
                foreach ($cells as $i => $cell) {
                    $st = preg_replace('/(min-|max-)?width\s*:[^;]*;?/i', '', (string)$cell->getAttribute('style'));
                    $cell->setAttribute('style', 'width:' . round($w[$i] * $scale, 1) . 'mm;' . $st);
                    $cell->removeAttribute('width');
                }
                break;   // صفّ واحد بلا دمج يكفي لتعريف الأعمدة
            }
            $tw = $sum * $scale + 1.0;   // +1mm عمداً: إن ساوى عرض الجدول مجموع أعمدته بالضبط يأخذ mPDF فرع «كل عمود بأقصى محتواه» ويتجاهل العرض الصريح
            $t->setAttribute('style', preg_replace('/width\s*:[^;]*;?/i', '', (string)$t->getAttribute('style')) . 'width:' . ($tw > $usable ? '100%' : round($tw, 1) . 'mm') . ';');
            $t->removeAttribute('width');
        };
        $natural = [];
        foreach ($tables as $i => $t) {
            if (!self::hasClass($t, 'pdf-t')) continue;
            $natural[$i] = $measure($t);
        }
        // 📄 الاختيار بأقلّ عدد أوراق: الاتجاه (عمودي/أفقي) × شكل الأعمدة (كامل بلا التفاف / ملتفّ / مرآة الدولار سطراً ثانياً)
        //    = أقلّ (أجزاء × صفحات الصفوف)؛ عند التساوي: الكامل ثم الملتفّ ثم المكدَّس، والعمودي قبل الأفقي
        $variantOf = []; $nameColOf = [];
        $pick = function (bool $land) use ($natural, $pagesOf, $side): array {
            $usable = self::usableWidth($land, $side); $total = 0; $choice = [];
            foreach ($natural as $i => $m) {
                $best = null;
                foreach (['full' => $m['rows'], 'wrap' => $m['rows'] + $m['wrapRows'], 'stack' => $m['rows'] + $m['wrapRows'] + $m['usdRows']] as $v => $lines) {
                    [$pg, $parts] = $pagesOf($m[$v], $usable, $land, $lines, $m['nameCol']);
                    if ($best === null || $pg < $best[0] || ($pg === $best[0] && $parts < $best[2])) $best = [$pg, $v, $parts];   // التساوي: الأقلّ أجزاءً
                }
                $total += $best[0]; $choice[$i] = $best[1];
            }
            return [$total, $choice];
        };
        if ($landscape === null) {
            [$pp, $cp] = $pick(false); [$pl, $cl] = $pick(true);
            $landscape = $pl < $pp; $variantOf = $landscape ? $cl : $cp;
        } else {
            $variantOf = $pick((bool)$landscape)[1];
        }
        $usable = self::usableWidth($landscape, $side);
        foreach ($natural as $i => $m) {
            $v = $variantOf[$i] ?? 'full';
            if (getenv('MSAPDF_DEBUG')) fwrite(STDERR, sprintf("[pdf] table %d: %s %s full=%.0f wrap=%.0f stack=%.0f usable=%.0f rows=%d wrapCells=%d usdRows=%d\n  %s\n", $i, $landscape ? 'L' : 'P', $v, array_sum($m['full']), array_sum($m['wrap']), array_sum($m['stack']), $usable, $m['rows'], $m['wrapCells'], $m['usdRows'], implode(' ', array_map(fn($x) => round($x), $m[$v]))));
            if ($v === 'stack') {   // مرآة الدولار سطراً ثانياً فعلاً
                foreach (iterator_to_array($xp->query('.//*[' . $cls('money-usd') . ']', $tables[$i])) as $mu) $mu->parentNode->insertBefore($dom->createElement('br'), $mu);
            }
            $natural[$i] = $m[$v]; $nameColOf[$i] = $m['nameCol'];
            $widestMm = max($widestMm ?? 0, array_sum($m[$v]));   // أعرض جدول بعرضه المختار (mm) — يستعمله محرّك Chromium لقرار الاتجاه
        }

        // تقسيم الجدول الأعرض من الورقة (حتى بعد سماح تصغير 15٪) على أجزاء بالعرض مع تكرار أعمدة التعريف
        foreach ($tables as $i => $t) {
            if (!isset($natural[$i])) continue;
            $w = $natural[$i]; $sum = array_sum($w);
            $official = $xp->query('ancestor::*[contains(concat(" ",normalize-space(@class)," ")," official-doc ")]', $t)->length > 0;   // النماذج الرسمية طبق الأصل: لا تقسيم ولا عرض صريح
            if ($fitOne || $official || $sum <= $usable * 1.15) { if (!$fitOne && !$official) $applyWidths($t, $w, $usable); continue; }
            [$key, $parts] = $partition($w, $usable, $nameColOf[$i] ?? -1);
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
                $pw = []; foreach ($idx as $c) $pw[] = $w[$c];
                $applyWidths($nt, $pw, $usable);
                $cap = $dom->createElement('div');
                $cap->setAttribute('class', 'pdf-part');
                $cap->appendChild($dom->createTextNode(($rtl ? ('الجزء ' . ($pi + 1) . ' من ' . $np) : ('Partie ' . ($pi + 1) . ' / ' . $np)) . ($pi > 0 ? ($rtl ? ' — تابع الأعمدة' : ' — suite des colonnes') : '')));
                if ($pi > 0) { $pb = $dom->createElement('div'); $pb->setAttribute('class', 'pdf-break'); $wrap->appendChild($pb); }
                $wrap->appendChild($cap);
                $wrap->appendChild($nt);
            }
            $t->parentNode->replaceChild($wrap, $t);
        }

        // 🖼️ صورة الترويسة (خلفية المنطقة على الشاشة) ⇒ مسار محلي + الهوامش من حشوة المنطقة (px ⇒ mm)
        $lhFile = ''; $lhPad = null;
        if (!empty($o['lh_bg'])) {
            $pb = preg_replace('/[?#].*$/', '', preg_replace('#^https?://[^/]+#i', '', (string)$o['lh_bg']));
            $relB = ($base !== '/' && strpos($pb, $base) === 0) ? substr($pb, strlen($base)) : ltrim($pb, '/');
            if (is_file($root . '/' . $relB)) $lhFile = str_replace('\\', '/', $root . '/' . $relB);
            if (!empty($o['lh_pad']) && preg_match('/^[\d.]+,[\d.]+,[\d.]+,[\d.]+$/', (string)$o['lh_pad'])) $lhPad = array_map(fn($v) => round((float)$v * 25.4 / 96, 1), explode(',', (string)$o['lh_pad']));
        }
        return ['dom' => $dom, 'body' => $body, 'dir' => $dir, 'rtl' => $rtl, 'landscape' => (bool)$landscape, 'isLetter' => (bool)$isLetter, 'side' => $side, 'title' => $title, 'lh_file' => $lhFile, 'lh_pad' => $lhPad, 'widest' => (float)($widestMm ?? 0)];
    }

    // ⚡ (2026-10-18 «ليش بس اكبس طباعة بتاخد وقت تتفتح وبعدين اطبع» ⇒ «اوك بدون ما تخرب») كاش الـPDF ببصمة المحتوى:
    //    نفس HTML المستند + نفس الخيارات + نفس اليوم (التذييل فيه التاريخ) + نفس نسخة المحرّك (تواريخ ملفاته وتوفّر Chromium)
    //    ⇒ الملف الجاهز نفسه بلا إعادة بناء (أوّل طباعة تبني، والتالية فورية). لا يمسّ الرسم إطلاقاً: أي تغيير بالأرقام = بصمة جديدة.
    //    الملفات بـtmp/pdfcache؛ تنظيف ما مضى عليه 14 يوماً (عرضياً).
    public static function cacheDir(): string { $d = realpath(__DIR__ . '/..') . '/tmp/pdfcache'; if (!is_dir($d)) @mkdir($d, 0777, true); return $d; }
    public static function cacheKey(string $html, array $o): string
    {
        $eng = [];
        foreach (['pdf_engine.php', 'pdf_chromium.php', 'report_helpers.php'] as $f) $eng[] = (string)@filemtime(__DIR__ . '/' . $f);
        $eng[] = (class_exists('MsaChromePdf') && MsaChromePdf::available($o)) ? 'chrome' : 'mpdf';
        $eng[] = date('Y-m-d');
        return sha1($html . '|' . json_encode($o, JSON_UNESCAPED_UNICODE) . '|' . implode(',', $eng) . '|v1');
    }
    public static function cacheGet(string $key): ?string
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $key)) return null;
        $f = self::cacheDir() . '/' . $key . '.pdf';
        if (!is_file($f) || filesize($f) < 400) return null;
        @touch($f);
        return (string)file_get_contents($f);
    }
    public static function cachePut(string $key, string $data): void
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $key) || strlen($data) < 400 || strpos($data, '%PDF') !== 0) return;
        $d = self::cacheDir();
        @file_put_contents($d . '/' . $key . '.pdf', $data, LOCK_EX);
        if (mt_rand(1, 20) === 1) foreach ((array)glob($d . '/*.pdf') as $old) if (@filemtime($old) < time() - 14 * 86400) @unlink($old);
    }

    /** يبني PDF من HTML منطقة المستند المعروضة. $o: title · dir (rtl|ltr) · landscape (bool|null) · school (اسم للتذييل) · letter (bool|null) · fit */
    public static function fromHtml(string $html, array $o = []): string
    {
        // 🌐 (2026-10-15 «ركّبه») المحرّك الاحترافي Chromium على سيرفر المدرسة إن كان مهيّأً ومتاحاً؛ وإلّا (أو عند أي عطل) mPDF كما كان
        if (($o['engine'] ?? '') !== 'mpdf' && class_exists('MsaChromePdf') && MsaChromePdf::available($o)) {
            try { return MsaChromePdf::fromHtml($html, $o); }
            catch (\Throwable $e) { error_log('MsaChromePdf fallback: ' . $e->getMessage()); }
        }
        $p = self::prepare($html, $o);
        $dom = $p['dom']; $body = $p['body']; $dir = $p['dir']; $rtl = $p['rtl']; $landscape = $p['landscape']; $isLetter = $p['isLetter']; $side = $p['side']; $title = $p['title'];
        $fitOne = !empty($o['fit']);
        // ---- 5) mPDF ----
        $root = realpath(__DIR__ . '/..');
        $tmp = $root . '/tmp/mpdf'; if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
        $cfg = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fcfg = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontdata = $fcfg['fontdata'];
        $fontdata['naskh'] = ['R' => 'NotoNaskhArabic-Regular.ttf', 'B' => 'NotoNaskhArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75];
        $fontdata['cairo'] = ['R' => 'CairoArabic-Regular.ttf', 'B' => 'CairoArabic-Bold.ttf', 'useOTL' => 0xFF];
        $fontdata['arial'] = ['R' => 'arial.ttf', 'B' => 'arialbd.ttf', 'useOTL' => 0xFF, 'useKashida' => 75];   // 🔤 (2026-10-17) خطّ الشاشة نفسه
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'margin_left' => $side, 'margin_right' => $side,
            'margin_top' => $isLetter ? 15 : 10, 'margin_bottom' => $isLetter ? 15 : 14,
            'margin_header' => 5, 'margin_footer' => 6,
            'tempDir' => $tmp,
            'fontDir' => array_merge($cfg['fontDir'], [$root . '/assets/fonts/ttf']),
            'fontdata' => $fontdata,
            'default_font' => 'arial',   // 🔤 (2026-10-17) Arial على الورق كما على الشاشة
            'default_font_size' => 12,
            'autoScriptToLang' => true,
            'autoLanguageToFont' => true,
            'languageToFont' => new MsaLangToFont(),
            'useSubstitutions' => true,
            'backupSubsFont' => ['cairo', 'freesans', 'dejavusans'],
            'shrink_tables_to_fit' => $fitOne ? 3 : 1.15,
            'useFixedNormalLineHeight' => true,   // ارتفاع السطر من حجم الخط لا من صندوق خط Cairo الضخم (كان السطر 7.3mm لخط 12pt)
            'useFixedTextBaseline' => true,
            'use_kwt' => true,
            'img_dpi' => 96,
            'setAutoTopMargin' => false,
            'setAutoBottomMargin' => false,
        ]);
        if (!empty($p['lh_file'])) {
            // 🖼️ الترويسة الرسمية صورةً على كامل الورقة + هوامش الكتابة كما على الشاشة (فوق الترويسة وتحتها)
            $mpdf->SetWatermarkImage($p['lh_file'], 1, $landscape ? [297, 210] : [210, 297], [0, 0]);   // صورة الترويسة على كامل الورقة (خلف النصّ) بكل صفحة
            $mpdf->showWatermarkImage = true;
            $mpdf->watermarkImgBehind = true;
            if ($p['lh_pad']) { [$pt, $pr, $pb, $pl] = $p['lh_pad']; $mpdf->SetMargins($pl, $pr, $pt); $mpdf->SetAutoPageBreak(true, $pb); }
        }
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
    public static function css(bool $rtl, bool $letter): string
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
.report-scope { text-align: center; font-weight: bold; color: #1e3a8a; border-bottom: 1.5pt solid #1e3a8a; padding-bottom: 2pt; margin-bottom: 4pt; line-height: 1.3; }
/* ترويسة حكومية */
table.pdf-gov { width: 100%; border-collapse: collapse; border-bottom: 0.5pt solid #cbd5e1; margin-bottom: 8pt; }
table.pdf-gov td { border: 0; vertical-align: top; padding: 0 0 4pt 0; }
td.pdf-gov-code { width: 22mm; font-weight: bold; text-align: center; }
td.pdf-gov-c { text-align: center; font-weight: bold; }
td.pdf-gov-e { width: 22mm; text-align: center; } td.pdf-gov-e img { height: 16mm; }
.gov-title { color: #1e3a8a; font-size: 13pt; }
/* عنوان المستند */
.doc-head { text-align: center; margin: 0 0 4pt; line-height: 1.3; }
.doc-head .dh-fr { font-size: 14pt; font-weight: bold; color: #1e3a8a; }
.doc-head .dh-ar { font-size: 15pt; font-weight: bold; color: #1e3a8a; }
.doc-head .dh-meta { font-size: 10pt; color: #475569; margin-top: 1pt; line-height: 1.3; }
.doc-head .dh-chip { display: inline; padding: 0 5pt; border-right: 0.5pt solid #cbd5e1; }
.doc-title { text-align: center; font-size: 15pt; font-weight: bold; color: #1e3a8a; margin: 2pt 0 4pt; line-height: 1.3; }
.doc-subtitle, .doc-year { text-align: center; color: #475569; margin: 0 0 4pt; line-height: 1.3; }
.doc-section { font-weight: bold; color: #1e3a8a; margin: 10pt 0 4pt; border-$al: 2pt solid #d4af37; padding-$al: 6pt; }
.doc-note { font-size: 10.5pt; color: #64748b; border-top: 0.5pt dashed #cbd5e1; padding-top: 4pt; margin-top: 10pt; }
.pdf-part { font-weight: bold; color: #1e3a8a; margin: 4pt 0 1pt; font-size: 10.5pt; }
.pdf-break { page-break-before: always; }
/* الجداول */
table.pdf-t { width: 100%; border-collapse: collapse; margin: 3pt 0 6pt; page-break-inside: auto; }
table.pdf-t thead th, table.pdf-t th { background: #1F4E5F; color: #fff; font-weight: bold; text-align: center; vertical-align: middle; padding: 2pt 3pt; border: 0.5pt solid #1F4E5F; font-size: 11pt; line-height: 1.2; }
table.pdf-t td { border: 0.5pt solid #94a3b8; padding: 1.2pt 3pt; text-align: center; vertical-align: middle; line-height: 1.15; }
table.pdf-t td.num, table.pdf-t td.money, table.pdf-t td.n { text-align: $op; }
table.pdf-t tr { page-break-inside: avoid; }
table.pdf-t tr.total-row td, table.pdf-t tr.grand-row td, table.pdf-t tr.sum-row td { background: #e2e8f0; font-weight: bold; }
table.pdf-t tr.subtotal-row td { background: #f1f5f9; font-weight: bold; }
table.pdf-t tr.section-row td, table.pdf-t td.section { background: #e0e7ff; font-weight: bold; text-align: $al; }
table.pdf-t th .rate-head, table.pdf-t th small { font-weight: normal; font-size: 9pt; }
table.pdf-t.doc-table thead th, table.pdf-t.doc-table th { background: #e5e7eb; color: #000; }   /* 🖨️ (2026-10-17) رؤوس جداول التقارير على الورق رمادي فاتح بخطّ أسود — النماذج الرسمية كما كانت */
.money-usd { display: inline; color: #047857; font-weight: bold; font-size: 9.5pt; white-space: nowrap; }
table.pdf-t.doc-table tbody td { padding: 2pt 4pt; height: 23px; }   /* على الورق 23px (30 صفّاً + ترويسة + مجاميع = صفحة واحدة) — الشاشة 30 */   /* جداول التقارير فقط (لا النماذج الرسمية/الإفادات): 30 رُفعت إلى 28px على الورق ليبقى 30 صفّاً بصفحة */
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
require_once __DIR__ . '/pdf_chromium.php';   // 🌐 المحرّك الاحترافي (Chromium على سيرفر المدرسة) — يُستعمل تلقائياً من fromHtml إن كان مهيّأً
