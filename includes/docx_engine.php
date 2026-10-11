<?php
/**
 * 📝 محرّك الوورد للإفادات والكتب (2026-10-11 «طبعت إفادة وورد شوف كيف طلعت»): المستند الذي لا جداول بيانات فيه (إفادة/كتاب/إقرار)
 * كان يمرّ بمحوّل الكشوف (ReportTable) فيطلع فقرات داخل جدول رمادي. الآن: وورد حقيقي عبر PHPWord من HTML المستند نفسه
 * بعد تنظيفه بنفس تحضير محرّك الـPDF (MsaPdf::prepare): ترويسة بالشعار، فقرات، عريض، توقيع — A4، هوامش 2 سم، عربي من اليمين.
 * الكشوف ذات الجداول تبقى على مولّد الكشوف (أعمدة على قدّ المحتوى وأجزاء أفقية).
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/pdf_engine.php';

class MsaDocx
{
    public static $lastError = '';
    public static function fromHtml(string $html, array $o = []): string
    {
        $p = MsaPdf::prepare($html, $o);
        /** @var DOMDocument $dom */ $dom = $p['dom']; $body = $p['body']; $rtl = $p['rtl']; $isLetter = $p['isLetter']; $landscape = $p['landscape']; $side = $p['side'];
        $xp = new DOMXPath($dom);

        // تهيئة للوورد: ستايلات مضمّنة يفهمها PHPWord (لا CSS خارجي)
        foreach ($xp->query('//table') as $t) {
            $cls = ' ' . $t->getAttribute('class') . ' ';
            $isData = strpos($cls, ' pdf-t ') !== false;
            $isLh = strpos($cls, ' pdf-lh ') !== false || strpos($cls, ' pdf-gov ') !== false || strpos($cls, ' pdf-sign ') !== false || strpos($cls, ' pdf-info ') !== false;
            $t->setAttribute('width', '100%');
            $t->setAttribute('style', ($isData ? 'border:1px solid #94a3b8;border-collapse:collapse;' : 'border:0;') . 'width:100%;' . $t->getAttribute('style'));
            foreach ($xp->query('.//th|.//td', $t) as $c) {
                $st = $c->getAttribute('style');
                if (strtolower($c->nodeName) === 'th') {
                    $st = 'background-color:#1F4E5F;color:#FFFFFF;text-align:center;border:1px solid #1F4E5F;padding:3px;' . $st;
                    // عريض: PHPWord لا يقرأ font-weight ⇒ لفّ النصّ بـ<b>
                    if (!$xp->query('.//b|.//strong', $c)->length) { $b = $dom->createElement('b'); while ($c->firstChild) $b->appendChild($c->firstChild); $c->appendChild($b); }
                } elseif ($isData) {
                    $st = 'border:1px solid #94a3b8;padding:3px;' . $st;
                } else {
                    $st = 'border:0;' . $st;
                }
                $c->setAttribute('style', $st);
            }
        }
        // الشعار: مقاس ثابت بالوورد (العرض بالبكسل)
        foreach ($xp->query('//img') as $img) {
            $src = (string)$img->getAttribute('src');
            if ($src === '' || !is_file($src)) { if ($img->parentNode) $img->parentNode->removeChild($img); continue; }
            $w = 110;
            if (preg_match('/max-width:\s*(\d+)px/', (string)$img->getAttribute('style'), $m)) $w = min((int)$m[1], 180);
            [$iw, $ih] = @getimagesize($src) ?: [0, 0];
            $img->removeAttribute('style'); $img->removeAttribute('class');
            $img->setAttribute('width', (string)$w);
            if ($iw > 0 && $ih > 0) $img->setAttribute('height', (string)(int)round($w * $ih / $iw));
        }
        // عناوين المستند / أقسام: تحويلات بسيطة إلى وسوم يفهمها PHPWord
        foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," doc-title ") or contains(concat(" ",normalize-space(@class)," ")," dh-ar ") or contains(concat(" ",normalize-space(@class)," ")," dh-fr ")]') as $el) {
            $el->setAttribute('style', 'text-align:center;font-size:16pt;color:#1e3a8a;' . $el->getAttribute('style'));
            if (!$xp->query('.//b|.//strong', $el)->length) { $b = $dom->createElement('b'); while ($el->firstChild) $b->appendChild($el->firstChild); $el->appendChild($b); }
        }
        foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," pdf-break ")]') as $el) { if ($el->parentNode) $el->parentNode->removeChild($el); }
        // العناوين h1–h6: PHPWord يحوّلها لأنماط Heading (تتجاهل المحاذاة والاتجاه) ⇒ فقرات عريضة بحجم مناسب تحفظ text-align
        foreach (iterator_to_array($xp->query('//h1|//h2|//h3|//h4|//h5|//h6')) as $h) {
            $sz = ['h1' => '18pt', 'h2' => '16pt', 'h3' => '14pt', 'h4' => '13pt', 'h5' => '12pt', 'h6' => '12pt'][strtolower($h->nodeName)];
            $pEl = $dom->createElement('p');
            $pEl->setAttribute('style', 'font-size:' . $sz . ';' . (string)$h->getAttribute('style'));
            $b = $dom->createElement('b'); while ($h->firstChild) $b->appendChild($h->firstChild); $pEl->appendChild($b);
            $h->parentNode->replaceChild($pEl, $h);
        }
        // صفّ مرن (display:flex: التاريخ | التوقيع) ⇒ جدول بصفّ واحد وخليّة لكل عنصر (الوورد لا يعرف flex)
        foreach (iterator_to_array($xp->query('//div[contains(@style,"display:flex") or contains(@style,"display: flex")]')) as $fx) {
            $kids = []; foreach ($fx->childNodes as $k) if ($k instanceof DOMElement) $kids[] = $k;
            if (count($kids) < 2) continue;
            $t = $dom->createElement('table'); $t->setAttribute('width', '100%'); $t->setAttribute('style', 'border:0;width:100%;margin-top:12pt');
            $tr = $dom->createElement('tr'); $t->appendChild($tr);
            $w = (int)floor(100 / count($kids));
            foreach ($kids as $k) { $td = $dom->createElement('td'); $td->setAttribute('style', 'border:0;width:' . $w . '%;vertical-align:top'); $td->appendChild($k); $tr->appendChild($td); }
            $fx->parentNode->replaceChild($t, $fx);
        }
        // ترويسة الإفادة: خليّة الشعار 32٪ ضيّقة بالوورد ⇒ 44٪؛ و<br> قبل عنصر كتلي يصنع سطراً فارغاً ⇒ يُشال
        foreach ($xp->query('//td[contains(@style,"width:32%")]') as $td) $td->setAttribute('style', str_replace('width:32%', 'width:44%', (string)$td->getAttribute('style')));
        foreach (iterator_to_array($xp->query('//br')) as $br) {
            $nx = $br->nextSibling; while ($nx && $nx->nodeType === XML_TEXT_NODE && trim($nx->nodeValue) === '') $nx = $nx->nextSibling;
            if (!$nx || ($nx instanceof DOMElement && in_array(strtolower($nx->nodeName), ['div', 'p', 'table', 'ul', 'ol'], true))) $br->parentNode->removeChild($br);
        }
        // PHPWord لا يعرف <div>: نصوصه تصير فقرات متفرّقة. كل div لا يحوي عناصر كتلية يصير <p> (الأعمق أوّلاً)؛ الباقي يبقى حاوية شفّافة.
        $blocks = ['div', 'p', 'table', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'td', 'th', 'li', 'tbody', 'thead'];
        $divs = iterator_to_array($xp->query('//div'));
        foreach (array_reverse($divs) as $dv) {
            $hasBlock = false;
            foreach ($dv->childNodes as $k) if ($k instanceof DOMElement && in_array(strtolower($k->nodeName), $blocks, true)) { $hasBlock = true; break; }
            if ($hasBlock) continue;
            if (trim($dv->textContent) === '' && !$xp->query('.//img', $dv)->length) { if ($dv->parentNode) $dv->parentNode->removeChild($dv); continue; }
            $pEl = $dom->createElement('p');
            foreach (iterator_to_array($dv->attributes) as $at) $pEl->setAttribute($at->name, $at->value);
            while ($dv->firstChild) $pEl->appendChild($dv->firstChild);
            $dv->parentNode->replaceChild($pEl, $dv);
        }
        // تباعد الأسطر: الافتراضي الموحّد (line-height الشاشة 2.15 يترك فراغات كبيرة بالوورد)
        foreach ($xp->query('//*[@style]') as $el) { $st = preg_replace('/line-height\s*:[^;]*;?/i', '', (string)$el->getAttribute('style')); $el->setAttribute('style', $st); }
        // الفقرات العربية: محاذاة يمين صريحة (PHPWord يقرأ text-align/direction من الستايل)
        foreach ($xp->query('//p|//div') as $el) {
            $st = (string)$el->getAttribute('style');
            if (strpos($st, 'text-align') === false) $el->setAttribute('style', 'text-align:' . ($rtl ? 'right' : 'left') . ';' . $st);
            if (strpos($st, 'direction') === false) $el->setAttribute('style', 'direction:' . ($rtl ? 'rtl' : 'ltr') . ';' . $el->getAttribute('style'));
        }

        // PHPWord يقرأ XML (loadXML): نكتب XHTML صالحاً (img/br مغلقة) ونستبدل كيانات HTML التي لا يعرفها XML
        $inner = '';
        foreach ($body->childNodes as $ch) $inner .= $dom->saveXML($ch);
        $inner = str_replace(['&nbsp;', '&rsquo;', '&lsquo;', '&laquo;', '&raquo;', '&hellip;'], ["\u{00A0}", '’', '‘', '«', '»', '…'], $inner);
        $inner = preg_replace('/&(?!(amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $inner);
        // تنظيف ما لا يفهمه المحلّل
        $inner = preg_replace('/<\/?(section|article|header|footer|main|nav|figure)[^>]*>/i', '', $inner);
        if (!empty($o['debug_html'])) @file_put_contents($o['debug_html'], $inner);

        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $pw = new \PhpOffice\PhpWord\PhpWord();
        $pw->setDefaultFontName('Arial');
        $pw->setDefaultFontSize(12);
        $pw->setDefaultParagraphStyle(['bidi' => $rtl, 'alignment' => $rtl ? \PhpOffice\PhpWord\SimpleType\Jc::RIGHT : \PhpOffice\PhpWord\SimpleType\Jc::LEFT, 'spaceAfter' => 80, 'lineHeight' => 1.5]);
        $mm = fn(float $v) => (int)round($v * 56.6929);
        $section = $pw->addSection([
            'paperSize' => 'A4', 'orientation' => $landscape ? 'landscape' : 'portrait',
            'marginLeft' => $mm($side), 'marginRight' => $mm($side), 'marginTop' => $mm($isLetter ? 15 : 12), 'marginBottom' => $mm($isLetter ? 15 : 12),
        ]);
        try {
            \PhpOffice\PhpWord\Shared\Html::addHtml($section, $inner, false, false);
        } catch (Throwable $e) {
            self::$lastError = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
            // احتياط: نصّ المستند فقرات (لا يُترك المستخدم بلا ملف)
            $txt = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '<br>', '<br/>', '</div>', '</tr>'], "\n", $inner))));
            foreach (preg_split('/\n+/', $txt) as $ln) { $ln = trim($ln); if ($ln !== '') $section->addText($ln, ['rtl' => $rtl]); }
        }
        if ($rtl) self::rtlTables($section);
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        \PhpOffice\PhpWord\IOFactory::createWriter($pw, 'Word2007')->save($tmp);
        $data = (string)file_get_contents($tmp); @unlink($tmp);
        return $data;
    }

    /** الجداول بالمستند العربي من اليمين لليسار (bidiVisual) — PHPWord لا يقرأها من dir/direction */
    private static function rtlTables($container): void
    {
        if (!method_exists($container, 'getElements')) return;
        foreach ($container->getElements() as $el) {
            if ($el instanceof \PhpOffice\PhpWord\Element\Table) {
                $st = $el->getStyle();
                if ($st instanceof \PhpOffice\PhpWord\Style\Table && method_exists($st, 'setBidiVisual')) $st->setBidiVisual(true);
                foreach ($el->getRows() as $row) foreach ($row->getCells() as $cell) self::rtlTables($cell);
            } elseif (method_exists($el, 'getElements')) self::rtlTables($el);
        }
    }
}
