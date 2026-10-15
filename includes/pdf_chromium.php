<?php
/**
 * 🖨️🌐 محرّك الطباعة الاحترافي — Chromium على سيرفر المدرسة الخاصّ (2026-10-15 «ما في ولا تقرير ظابط للطبع… ركّبه»)
 * الورقة تُرسَم بمحرّك المتصفّح نفسه (Gotenberg/Chromium على 144.91.110.214 عبر https://collegesaintmaxime.com/pdf/):
 *   • كل عمود بعرض محتواه الحقيقي (تخطيط المتصفّح)، خط Cairo نفسه الذي على الشاشة، رأس الجدول يتكرّر بكل صفحة، لا سطر ينقسم
 *   • الاتجاه/التقسيم أعمدةً/التنظيف = MsaPdf::prepare() نفسه (مصدر واحد)؛ والعرض الصريح بالملم يُشال ليتولّى المتصفّح القياس
 *   • أمان: سكربت داخل الورقة يصغّر (zoom) أي جدول أعرض من الورقة بدل قصّه
 *   • التذييل (المدرسة · صفحة n من N · التاريخ) عبر قالب التذييل الخاصّ بـChromium
 * الإعداد: config/pdf_service.php ⇒ ['url' => …, 'user' => …, 'pass' => …]. إن غاب الملف أو تعطّل الخادم ⇒ MsaPdf (mPDF) كما كان.
 * الإفادات ذات الترويسة-الصورة (lh_bg) تبقى على mPDF (الصورة خلفية كل صفحة).
 */
require_once __DIR__ . '/pdf_engine.php';

class MsaChromePdf
{
    private static $cfg = null;

    /** إعدادات الخدمة أو null */
    public static function config(): ?array
    {
        if (self::$cfg !== null) return self::$cfg ?: null;
        // الافتراضي: خدمة المدرسة؛ كلمة السرّ من إعدادات البرنامج (الباب «إعدادات البريد» للمدير العام — تُحفَظ بقاعدة البيانات لا بالكود)؛
        // ويمكن لملف محلي config/pdf_service.php (خارج git) أن يغطّي أيّ قيمة أو يعطّل الخدمة ('disabled' => true)
        $c = ['url' => 'https://collegesaintmaxime.com/pdf', 'user' => 'msa', 'pass' => '', 'timeout' => 120, 'disabled' => false];
        if (function_exists('getSetting')) { try { $c['pass'] = (string)getSetting('pdf_service_pass', ''); $c['disabled'] = (string)getSetting('pdf_service_disabled', '') === '1'; } catch (\Throwable $e) {} }
        $f = __DIR__ . '/../config/pdf_service.php';
        if (is_file($f)) { $o = include $f; if (is_array($o)) $c = array_merge($c, array_filter($o, fn($v) => $v !== null && $v !== '')); }
        self::$cfg = (!empty($c['url']) && $c['pass'] !== '' && empty($c['disabled'])) ? $c : false;
        return self::$cfg ?: null;
    }

    public static function available(array $o = []): bool
    {
        if (getenv('MSAPDF_ENGINE') === 'mpdf') return false;
        if (!empty($o['lh_bg'])) return false;   // ترويسة-صورة على كل صفحة: mPDF
        return self::config() !== null;
    }

    /** يبني PDF عبر Chromium؛ يرمي استثناءً عند الفشل (المنادي يرجع إلى mPDF) */
    public static function fromHtml(string $html, array $o = []): string
    {
        $cfg = self::config();
        if (!$cfg) throw new RuntimeException('pdf service not configured');
        $o['variants'] = ['wrap', 'stack'];   // المتصفّح يلفّ الخلايا الطويلة (pr-wrap) فلا معنى لنسخة «كامل بلا التفاف» بتقدير الأجزاء
        $fitOne = !empty($o['fit']);
        $o['fit'] = true;                      // التقسيم أعمدةً يقوم به المتصفّح نفسه بقياساته الحقيقية (السكربت أدناه) لا التقدير
        $p = MsaPdf::prepare($html, $o);
        $dom = $p['dom']; $body = $p['body']; $dir = $p['dir']; $rtl = $p['rtl']; $landscape = $p['landscape']; $isLetter = $p['isLetter']; $side = $p['side']; $title = $p['title'];
        // الاتجاه: الجدول الأعرض من الورقة العمودية ⇒ أفقي (أعمدة المتصفّح أضيق فالأجزاء أقلّ بالأفقي) ما لم يُفرض اتجاه
        if (!isset($o['landscape']) || $o['landscape'] === null) $landscape = $landscape || (($p['widest'] ?? 0) > MsaPdf::usableWidth(false, $side));
        $xp = new DOMXPath($dom);
        // العرض الصريح بالملم (لmPDF) يُشال: المتصفّح يقيس بنفسه؛ الخلايا القصيرة سطر واحد، الطويلة (> 38 حرفاً) تلتفّ
        foreach ($xp->query('//table[contains(concat(" ",normalize-space(@class)," ")," pdf-t ")]') as $t) {
            $t->setAttribute('style', preg_replace('/width\s*:[^;]*;?/i', '', (string)$t->getAttribute('style')));
            // صفوف المجاميع (عريضة وقليلة): مرآة الدولار تنزل سطراً ثانياً فلا يوسّع المجموع عمود الأرقام كلّه
            foreach ($xp->query('.//tr[contains(@class,"total-row") or contains(@class,"grand-row") or contains(@class,"sum-row") or contains(@class,"subtotal-row")]//*[contains(concat(" ",normalize-space(@class)," ")," money-usd ")]', $t) as $mu) {
                $mu->parentNode->insertBefore($dom->createElement('br'), $mu);
            }
            foreach ($xp->query('.//th|.//td', $t) as $c) {
                $st = preg_replace('/(^|;)\s*(width|white-space|min-width|max-width)\s*:[^;]*;?/i', '$1', (string)$c->getAttribute('style'));
                $c->removeAttribute('nowrap');
                if (strtolower($c->nodeName) === 'th' && (int)$c->getAttribute('colspan') <= 1 && $c->childNodes->length) {
                    // الرأس لا يوسّع العمود: يلتفّ على عرض أرقامه (أطول كلمة بالرأس هي الحدّ الأدنى فقط)
                    $ph = $dom->createElement('div'); $ph->setAttribute('class', 'ph');
                    while ($c->firstChild) $ph->appendChild($c->firstChild);
                    $c->appendChild($ph);
                }
                if (trim($st) === '') $c->removeAttribute('style'); else $c->setAttribute('style', $st);
                if (strtolower($c->nodeName) === 'td' && mb_strlen(trim(preg_replace('/\s+/u', ' ', $c->textContent)), 'UTF-8') > 30) {
                    // الخلية الطويلة تلتفّ عند 60mm فعلاً: كتلة داخلية بعرض أقصى (max-width على الخلية لا يعمل بتخطيط الجدول)
                    $c->setAttribute('class', trim($c->getAttribute('class') . ' pr-wrap'));
                    $pw = $dom->createElement('div'); $pw->setAttribute('class', 'pw');
                    while ($c->firstChild) $pw->appendChild($c->firstChild);
                    $c->appendChild($pw);
                }
            }
        }
        $inner = '';
        foreach ($body->childNodes as $ch) $inner .= $dom->saveHTML($ch);
        $usableMm = ($landscape ? 297 : 210) - 2 * $side;
        $school = (string)($o['school'] ?? '');
        $css = MsaPdf::css($rtl, $isLetter) . self::css($rtl, $isLetter, $landscape, $side);
        $doc = '<!DOCTYPE html><html lang="' . ($rtl ? 'ar' : 'fr') . '" dir="' . $dir . '"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>' . self::fontCss() . $css . '</style></head><body>'
            . '<div class="pdf-doc" dir="' . $dir . '">' . $inner . '</div>'
            . '<script>' . self::fitScript((int)round($usableMm * 96 / 25.4), $fitOne, $rtl) . '</script>'
            . '</body></html>';
        if (!empty($o['debug_html'])) @file_put_contents($o['debug_html'], $doc);
        $date = date('d/m/Y');
        $pg = $rtl ? ('صفحة <span class="pageNumber"></span> من <span class="totalPages"></span>') : ('Page <span class="pageNumber"></span> / <span class="totalPages"></span>');
        $footer = '<!DOCTYPE html><html dir="' . $dir . '"><head><meta charset="utf-8"><style>' . self::fontCss() . 'body{margin:0;padding:0 ' . $side . 'mm;font-family:Cairo,sans-serif;font-size:9pt;color:#64748b;-webkit-print-color-adjust:exact}'
            . 'table{width:100%;border-collapse:collapse;border-top:0.5pt solid #cbd5e1}td{padding:2pt 0 0;border:0;white-space:nowrap}.a{text-align:' . ($rtl ? 'right' : 'left') . '}.b{text-align:' . ($rtl ? 'left' : 'right') . '}.c{text-align:center}</style></head><body>'
            . '<table><tr><td class="a" style="width:40%">' . htmlspecialchars($school, ENT_QUOTES, 'UTF-8') . '</td><td class="c" style="width:20%">' . $pg . '</td><td class="b" style="width:40%">' . ($rtl ? 'صدر بتاريخ ' : 'Émis le ') . $date . '</td></tr></table></body></html>';
        $header = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body></body></html>';

        $tmp = sys_get_temp_dir() . '/msapdf_' . bin2hex(random_bytes(6)); @mkdir($tmp, 0777, true);
        $fontDir = realpath(__DIR__ . '/../assets/fonts');
        $files = [];
        try {
            file_put_contents("$tmp/index.html", $doc); $files['index.html'] = "$tmp/index.html";
            file_put_contents("$tmp/footer.html", $isLetter ? $header : $footer); $files['footer.html'] = "$tmp/footer.html";
            file_put_contents("$tmp/header.html", $header); $files['header.html'] = "$tmp/header.html";
            foreach (['f1.woff2', 'f2.woff2', 'f3.woff2'] as $f) if (is_file("$fontDir/$f")) $files[$f] = "$fontDir/$f";
            $post = [
                'paperWidth' => ($landscape ? 297 : 210) . 'mm', 'paperHeight' => ($landscape ? 210 : 297) . 'mm',
                'marginTop' => ($isLetter ? 15 : 10) . 'mm', 'marginBottom' => ($isLetter ? 15 : 14) . 'mm', 'marginLeft' => $side . 'mm', 'marginRight' => $side . 'mm',
                'printBackground' => 'true', 'emulatedMediaType' => 'print', 'preferCssPageSize' => 'false',
                'waitForExpression' => 'window.msaFitDone === true',
                'metadata' => json_encode(['Title' => $title, 'Author' => $school]),
            ];
            $i = 0;
            foreach ($files as $name => $path) { $post['files' . ($i ? $i : '')] = new CURLFile($path, self::mime($name), $name); $i++; }
            $ch = curl_init(rtrim($cfg['url'], '/') . '/forms/chromium/convert/html');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => (int)($cfg['timeout'] ?? 120), CURLOPT_CONNECTTIMEOUT => 8]);
            if (!empty($cfg['user'])) curl_setopt($ch, CURLOPT_USERPWD, $cfg['user'] . ':' . ($cfg['pass'] ?? ''));
            $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
            if ($code !== 200 || !is_string($r) || substr($r, 0, 4) !== '%PDF') throw new RuntimeException('pdf service ' . $code . ' ' . $err . ' ' . mb_substr((string)$r, 0, 200));
            return $r;
        } finally {
            foreach (['index.html', 'footer.html', 'header.html'] as $f) @unlink("$tmp/$f");
            @rmdir($tmp);
        }
    }

    /** سكربت داخل الورقة (يشتغل بـChromium قبل الطباعة): الجدول الأعرض من الورقة يُقسَّم أعمدةً بقياسات المتصفّح الحقيقية
     *  (أعمدة المفتاح # + الاسم تتكرّر بكل جزء، كل جزء بورقة) — وبوضع «ورقة وحدة» يُصغَّر بدل التقسيم */
    private static function fitScript(int $availPx, bool $fitOne, bool $rtl): string
    {
        $lbl = $rtl ? ["'الجزء '", "' من '", "' — تابع الأعمدة'"] : ["'Partie '", "' / '", "' — suite des colonnes'"];
        $fit = $fitOne ? 'true' : 'false';
        return <<<JS
(function(){
var AVAIL=$availPx, FIT=$fit;
function split(t){
  t.style.width='max-content'; t.style.maxWidth='none';   // القياس بالعرض الطبيعي الكامل (لا مضغوطاً على عرض النافذة)
  var rows=Array.prototype.slice.call(t.rows), occ=[], cols=0;
  rows.forEach(function(tr,ri){ occ[ri]=occ[ri]||[]; var c=0;
    Array.prototype.forEach.call(tr.cells,function(cell){ while(occ[ri][c]) c++; var cs=cell.colSpan||1, rs=cell.rowSpan||1;
      for(var i=0;i<rs;i++){ occ[ri+i]=occ[ri+i]||[]; for(var j=0;j<cs;j++) occ[ri+i][c+j]={cell:cell,c0:c,r0:ri,cs:cs}; } c+=cs; });
    if(c>cols) cols=c; });
  if(cols<3) return false;
  var w=[],c,r;
  for(c=0;c<cols;c++){ w[c]=0; for(r=0;r<occ.length;r++){ var e=occ[r]&&occ[r][c]; if(e&&e.cs===1){ w[c]=Math.ceil(e.cell.getBoundingClientRect().width); break; } } if(!w[c]) w[c]=Math.ceil(t.scrollWidth/cols); }
  var key=[]; if(w[0]<=60) key.push(0);
  var hd=t.tHead?Array.prototype.slice.call(t.tHead.rows):[rows[0]], name=-1;
  outer: for(r=0;r<hd.length;r++){ var ri0=rows.indexOf(hd[r]); for(c=0;c<cols;c++){ var he=occ[ri0]&&occ[ri0][c]; if(he&&he.cs===1&&/الاسم|\\bnoms?\\b|\\bname\\b/i.test(he.cell.textContent||'')){ name=c; break outer; } } }
  if(name>=0&&key.indexOf(name)<0) key.push(name); else if(name<0){ for(c=0;c<Math.min(3,cols);c++){ if(key.indexOf(c)<0){ key.push(c); break; } } }
  key.sort(function(a,b){return a-b;});
  var keyW=0; key.forEach(function(k){keyW+=w[k];}); if(keyW>AVAIL*0.45){ key=[key[0]]; keyW=w[key[0]]; }
  // توزيع متوازن: أقلّ عدد أجزاء ممكن (بسماح 4٪ يعوّضه تصغير غير ملحوظ) ثم أعمدة البيانات موزّعة بالتساوي عليها
  var data=[], dataW=0; for(c=0;c<cols;c++){ if(key.indexOf(c)<0){ data.push(c); dataW+=w[c]; } }
  var cap=AVAIL*1.05-keyW, groups=null;
  function pack(N){ var target=dataW/N, gs=[], cur=[], sum=0;
    data.forEach(function(c){ if(cur.length&&(sum+w[c]>cap||(sum>=target&&gs.length<N-1))){ gs.push(cur); cur=[]; sum=0; } cur.push(c); sum+=w[c]; });
    if(cur.length) gs.push(cur); return gs; }
  function maxSum(gs){ var m=0; gs.forEach(function(g){ var s=0; g.forEach(function(c){s+=w[c];}); if(s>m) m=s; }); return m; }
  for(var N=Math.max(1,Math.ceil(dataW/Math.max(cap,1)));N<=data.length;N++){ groups=pack(N); if(maxSum(groups)<=cap) break; }
  if(groups.length<2) return false;
  (window.msaSplitInfo=window.msaSplitInfo||[]).push({w:w,key:key,keyW:keyW,cap:cap,N:groups.length,groups:groups.map(function(g){var s=0;g.forEach(function(c){s+=w[c];});return s;})});
  var frag=document.createDocumentFragment();
  groups.forEach(function(g,gi){
    var keep={}; key.forEach(function(k){keep[k]=1;}); g.forEach(function(x){keep[x]=1;});
    var ct=t.cloneNode(false); ct.removeAttribute('id'); ct.style.zoom=''; ct.style.width=''; ct.style.maxWidth='';
    Array.prototype.forEach.call(t.children,function(sec){
      if(!/^(thead|tbody|tfoot)$/i.test(sec.tagName)){ ct.appendChild(sec.cloneNode(true)); return; }
      var cs2=sec.cloneNode(false); ct.appendChild(cs2);
      Array.prototype.forEach.call(sec.rows,function(tr){ var ri=rows.indexOf(tr), ntr=tr.cloneNode(false), any=false;
        for(var cc=0;cc<cols;cc++){ var e2=occ[ri]&&occ[ri][cc]; if(!e2||e2.r0!==ri||e2.c0!==cc) continue;
          var n=0; for(var k=e2.c0;k<e2.c0+e2.cs;k++) if(keep[k]) n++; if(!n) continue;
          var nc=e2.cell.cloneNode(true); if(n>1) nc.colSpan=n; else nc.removeAttribute('colspan'); ntr.appendChild(nc); any=true; }
        if(any) cs2.appendChild(ntr); });
    });
    if(gi){ var pb=document.createElement('div'); pb.className='pdf-break'; frag.appendChild(pb); }
    var cap=document.createElement('div'); cap.className='pdf-part'; cap.textContent={$lbl[0]}+(gi+1)+{$lbl[1]}+groups.length+(gi?{$lbl[2]}:''); frag.appendChild(cap);
    frag.appendChild(ct);
  });
  t.parentNode.insertBefore(frag,t); t.parentNode.removeChild(t);
  return true;
}
Array.prototype.slice.call(document.querySelectorAll('table.pdf-t')).forEach(function(t){
  if(t.parentNode&&t.parentNode.closest&&t.parentNode.closest('table')) return;
  t.style.width='max-content'; t.style.maxWidth='none'; var w=t.scrollWidth; t.style.width=''; t.style.maxWidth='';
  if(w<=AVAIL+1) return;
  if(FIT||!split(t)){ t.style.width=''; t.style.maxWidth=''; t.style.zoom=Math.max(0.4,AVAIL/w).toFixed(3); }
});
Array.prototype.slice.call(document.querySelectorAll('table.pdf-t')).forEach(function(t){ var w=t.scrollWidth; if(w>AVAIL+1&&!t.style.zoom) t.style.zoom=Math.max(0.4,AVAIL/w).toFixed(3); });
window.msaFitDone=true;
})();
JS;
    }

    private static function mime(string $name): string
    {
        return substr($name, -5) === '.html' ? 'text/html' : (substr($name, -6) === '.woff2' ? 'font/woff2' : 'application/octet-stream');
    }

    /** خطوط Cairo نفسها التي على الشاشة (الملفات مرفقة بالطلب) */
    private static function fontCss(): string
    {
        $ar = 'U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FEFF';
        return "@font-face{font-family:Cairo;font-style:normal;font-weight:200 900;src:url(f1.woff2) format('woff2');unicode-range:$ar}"
            . "@font-face{font-family:Cairo;font-style:normal;font-weight:200 900;src:url(f3.woff2) format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}"
            . "@font-face{font-family:Cairo;font-style:normal;font-weight:200 900;src:url(f2.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}";
    }

    /** زيادات على ستايل الورق الموحّد لتخطيط المتصفّح */
    private static function css(bool $rtl, bool $letter, bool $landscape, int $side): string
    {
        $al = $rtl ? 'right' : 'left';
        return "\n@page{size:A4 " . ($landscape ? 'landscape' : 'portrait') . "}"
            . "html,body{margin:0;padding:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}"
            . "body{font-family:Cairo,'Segoe UI',Arial,sans-serif;font-size:12pt;line-height:" . ($letter ? '1.6' : '1.3') . ";color:#111}"
            . ".pdf-doc{direction:" . ($rtl ? 'rtl' : 'ltr') . ";text-align:$al}"
            . "table.pdf-t{width:auto;border-collapse:collapse;margin:3pt 0 6pt;table-layout:auto;page-break-inside:auto}"
            . "table.pdf-t.pdf-full{width:100%}"
            . "table.pdf-t thead{display:table-header-group}table.pdf-t tr{page-break-inside:avoid;break-inside:avoid}"
            . "table.pdf-t th{white-space:normal;padding:2.5pt 4pt;line-height:1.25}table.pdf-t th .ph{display:block;width:min-content;min-width:100%;margin:0 auto;white-space:normal}"
            . "table.pdf-t td{white-space:nowrap;padding:1pt 4pt;line-height:1.2}table.pdf-t td.pr-wrap{white-space:normal}table.pdf-t td .pw{max-width:58mm;white-space:normal;display:inline-block;vertical-align:middle;text-align:inherit}"
            . "table.pdf-t td .money-usd{display:inline;font-size:9.5pt;white-space:nowrap}"
            . ".pdf-part{break-inside:avoid}.pdf-break{break-before:page;page-break-before:always;height:0}"
            . "table.pdf-lh,table.pdf-gov,table.pdf-info,table.pdf-sign{width:100%}"
            . "img{max-width:100%}";
    }
}
