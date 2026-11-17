<?php
/**
 * 🖨️🏛️ ورقة الطباعة «طبق ورقة موقع الصندوق» (2026-11-17 قراره: «بيهمّني شكل التقرير وطباعتو وشكلو ومحتواه… أكيد لازم يكونو مطابقين بدون ما تخرب البرنامج»).
 *  - صفحة مستقلّة (HTML كامل) بنفس ترتيب ورقة www.caisseindemnite.com بالحرف: /my/financial/{id}/print و /my/roster/{id}/print?emp=staff|contract
 *    — الأنماط وسكربت «لا شيء يُقطع» وسكربت تقسيم الصفحات منقولة كما هي من ورقة الموقع (نُسخت 2026-11-17)، والمحتوى من داتا البرنامج.
 *  - إضافة فقط: لا تمسّ شاشة البوّابة ولا تصديراتها (طباعة/PDF/إكسل/وورد). تُفتح بـ pages/eoc_portal.php?...&export=sheet
 *  - المبلغ بالأحرف = نفس صياغة الموقع (مكتبة num2words العربية) — eocWordsAr منقولة عنها حرفياً للأعداد الصحيحة.
 *  - هذا الملف مولَّد: msa_helper_tests/mk_eoc_sheet.py (القالب + نسخ ورقة الموقع). لتحديث شكل الورقة إن غيّرها الصندوق: احفظ ورقتيه من جديد وأعد التوليد.
 */
function eocSheetCss(string $kind): string {
    if ($kind === 'general') return <<<'EOCX'
          @page{ size:A4 portrait; margin:7mm }
          *{box-sizing:border-box}
          body{font-family:'Segoe UI','Noto Naskh Arabic','Arial',sans-serif;color:#111;
               font-size:12px;margin:0;padding:14px 18px;background:#fff}
          .ba-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
          .ba-title{font-weight:bold;font-size:17px;line-height:1.3;text-align:right}
          .ba-desc{font-size:11px;max-width:280px;text-align:left}
          .ba-center{text-align:center;font-size:12px;padding-top:4px}
          .ba-school{margin-top:8px;font-size:14px;font-weight:bold}
          /* school number, phone, year: on the left, larger */
          .ba-meta{margin-top:2px;font-size:14px;font-weight:bold;text-align:left}
          .ba-quarter{margin:8px 0;font-size:13px;font-weight:bold}
          /* Cells stay on ONE line (numbers and dates never break); a value wider
             than its column is shrunk a little by the fit script at the end.
             Headers may take two lines, whole words. */
          table.ba{width:100%;border-collapse:collapse;table-layout:fixed;font-size:9.5px;margin-top:4px}
          table.ba th,table.ba td{border:1px solid #333;padding:3px 3px;text-align:center;
                                  vertical-align:middle;line-height:1.25}
          table.ba td{white-space:nowrap;overflow:hidden}
          table.ba thead th{background:#eef0f4;font-size:9px;
                            white-space:normal;word-break:keep-all;overflow-wrap:normal}
          table.ba td.name{text-align:right}
          table.ba td.num,table.ba th.num{font-variant-numeric:tabular-nums}
          table.ba td:empty::after{content:"\00a0"}
          /* blank signing box — needs room for an actual signature */
          table.ba td.sign{height:26px}
          table.ba tfoot td{font-weight:bold;background:#f7f7f7}
          .ba-sign,.ba-sign-page{width:100%;border-collapse:collapse;margin-top:14px;font-size:12px;
                   border-top:1px dashed #333;page-break-inside:avoid}
          .ba-sign td,.ba-sign-page td{padding:8px 4px 0;vertical-align:top}
          /* room under خاتم المدرسة / توقيع المدير to stamp and sign, on every page */
          .ba-sign tr:nth-child(2) td,.ba-sign-page tr:nth-child(2) td{padding-bottom:55px}
          .toolbar{position:sticky;top:0;text-align:center;margin:-14px -18px 12px;padding:10px;
                   background:#33405c}
          .toolbar button{font-size:14px;font-weight:bold;padding:8px 22px;border:0;border-radius:8px;
                          background:#f2c778;color:#33405c;cursor:pointer}
          /* one printed sheet per .ba-page (built by the script at the end) */
          .ba-page{page-break-after:always}
          .ba-page:last-child{page-break-after:auto}
          .ba-pageno{text-align:left;font-size:10px;font-weight:bold;margin-bottom:2px}
          .ba-note{margin-top:6px;font-size:9.5px;text-align:right;line-height:1.5}
          @media screen{ body{width:calc(196mm + 36px);margin:0 auto} }
          @media print{ .toolbar{display:none} body{padding:0} }
        
EOCX;
    return <<<'EOCX'
          @page{ size:A4 portrait; margin:7mm }
          *{box-sizing:border-box}
          body{font-family:'Segoe UI','Noto Naskh Arabic','Arial',sans-serif;color:#111;
               font-size:11px;margin:0;padding:12px 14px;background:#fff}
          .bm-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
          .bm-title{font-weight:bold;font-size:15px;line-height:1.3;text-align:right}
          .bm-desc{font-size:10px;max-width:240px;text-align:left}
          .bm-center{text-align:center;font-size:11px;padding-top:4px}
          .bm-school{margin-top:7px;font-size:13px;font-weight:bold}
          /* school number, phone, year: on the left, larger */
          .bm-meta{margin-top:2px;font-size:14px;font-weight:bold;text-align:left}
          .bm-quarter{margin:7px 0;font-size:12px;font-weight:bold}
          /* Fixed layout, A4 portrait. Cells stay on ONE line (numbers and dates
             never break); a value wider than its column is shrunk a little by
             the fit script at the end. Headers may take two lines, whole words. */
          table.bm{width:100%;border-collapse:collapse;table-layout:fixed;
                   font-size:10px;margin-top:4px}
          table.bm th,table.bm td{border:1px solid #333;padding:3px 2px;text-align:center;
                                  vertical-align:middle;line-height:1.25}
          table.bm td{white-space:nowrap;overflow:hidden}
          table.bm thead th{background:#eef0f4;font-size:9px;font-weight:bold;
                            white-space:normal;word-break:keep-all;overflow-wrap:normal}
          table.bm td.name{text-align:right}
          table.bm td.num{font-variant-numeric:tabular-nums}
          table.bm td:empty::after{content:"\00a0"}
          table.bm tfoot td{font-weight:bold;background:#f7f7f7}
          .bm-sum{margin-top:10px;font-size:13px;border-collapse:collapse}
          .bm-sum td{padding:3px 10px}
          .bm-sum .lbl{text-align:right;font-weight:bold}
          .bm-sum .val{text-align:left;font-weight:bold;border-bottom:1px solid #333;min-width:150px}
          .bm-words{margin-top:8px;font-size:13px;font-weight:bold}
          .bm-note{margin-top:10px;font-size:12px;line-height:1.55}
          table.bm-foot{width:100%;border-collapse:collapse}
          table.bm-foot td{vertical-align:top;padding:0;border:0}
          table.bm-foot td.bm-foot-sum{width:58%}
          table.bm-foot td.bm-foot-note{width:42%;padding-right:16px}
          .bm-sign{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;text-align:center}
          /* room under each to stamp and sign, on every page */
          .bm-sign div{width:32%;padding-bottom:28px}
          .num{font-variant-numeric:tabular-nums}
          .toolbar{position:sticky;top:0;text-align:center;margin:-14px -18px 12px;padding:10px;
                   background:#33405c}
          .toolbar button{font-size:14px;font-weight:bold;padding:8px 22px;border:0;border-radius:8px;
                          background:#f2c778;color:#33405c;cursor:pointer}
          /* on screen, the same width as the printed A4 area (210mm - 2x7mm),
             so the preview wraps / fits exactly like the print */
          /* never split across pages: a teacher row, the totals, the summary
             box (with its words line), the note + signatures */
          table.bm tr{page-break-inside:avoid;break-inside:avoid}
          table.bm thead{display:table-header-group}
          table.bm tfoot{display:table-row-group}
          .bm-keep{page-break-inside:avoid;break-inside:avoid}
          /* one printed sheet per .bm-page (built by the script at the end) */
          .bm-page{page-break-after:always}
          .bm-page:last-child{page-break-after:auto}
          .bm-pageno{text-align:left;font-size:11px;font-weight:bold;margin-bottom:2px}
          .bm-nb{margin-top:8px;font-size:13px;font-weight:bold}
          @media screen{ body{width:calc(196mm + 28px);margin:0 auto} }
          @media print{ .toolbar{display:none} body{padding:0} }
        
EOCX;
}
function eocSheetJs(string $kind): array {
    if ($kind === 'general') return [<<<'EOCX'
          /* Nothing is ever cut off. A value wider than its column is first
             shrunk a little (never below 75% of the normal size); text that
             still does not fit (names, certificate, category, notes…) wraps to
             a second line. Numbers and dates (.num) stay on one line. */
          (function () {
            function fit() {
              document.querySelectorAll('table.bm td, table.ba td').forEach(function (td) {
                td.style.fontSize = '';
                td.style.whiteSpace = '';
                if (!td.textContent.trim()) { return; }
                var size = parseFloat(window.getComputedStyle(td).fontSize);
                var min = size * 0.75;
                while (td.scrollWidth > td.clientWidth + 1 && size > min) {
                  size -= 0.25;
                  td.style.fontSize = size + 'px';
                }
                if (td.scrollWidth > td.clientWidth + 1 && !td.classList.contains('num')) {
                  td.style.whiteSpace = 'normal';
                  td.style.overflowWrap = 'anywhere';
                }
              });
            }
            window.spFit = fit;
            fit();
            window.addEventListener('beforeprint', fit);
          })();
        
EOCX
    , <<<'EOCX'
          (function () {
            var body  = document.body;
            var table = document.querySelector('table.ba');
            if (!table) { return; }
            if (window.spFit) { window.spFit(); }   // measure rows at their final size
            // real printable A4 height (297mm - 2 x 7mm margins) in CSS px
            var probe = document.createElement('div');
            probe.style.cssText = 'position:absolute;visibility:hidden;height:283mm';
            body.appendChild(probe);
            var PAGE = probe.getBoundingClientRect().height;
            probe.parentNode.removeChild(probe);
            var SAFETY = 28;                     // page number line + rounding
            var thead = table.querySelector('thead'), tfoot = table.querySelector('tfoot');
            var head  = thead.outerHTML;
            var group = table.querySelector('colgroup');
            var cols  = group ? group.outerHTML : '';
            var rows  = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
            var note  = document.querySelector('.ba-note-src');
            var noteHtml = note ? note.innerHTML : '';
            var sign  = document.querySelector('.ba-sign');
            // everything above the table = the repeated page header
            var top = [], firstTop = null;
            var n = body.firstElementChild;
            while (n && n !== table) {
              if (!n.classList.contains('toolbar') && n.tagName !== 'SCRIPT') {
                top.push(n.outerHTML); firstTop = firstTop || n;
              }
              n = n.nextElementSibling;
            }
            var topHtml = top.join('');
            // heights: header, column titles, running-count row, note, closing block
            var tRect = table.getBoundingClientRect();
            var topH  = firstTop ? tRect.top - firstTop.getBoundingClientRect().top : 0;
            var headH = thead.getBoundingClientRect().height;
            var footH = tfoot ? tfoot.getBoundingClientRect().height : 0;
            var probeNote = document.createElement('div');
            probeNote.className = 'ba-note'; probeNote.innerHTML = noteHtml;
            body.appendChild(probeNote);
            var noteH = probeNote.getBoundingClientRect().height + 8;
            probeNote.parentNode.removeChild(probeNote);
            var signH = sign ? sign.getBoundingClientRect().height + 16 : 0;
            var avail = PAGE - SAFETY - topH - headH - footH - noteH;
            var sheets = [], cur = [], used = 0;
            rows.forEach(function (r) {
              var h = r.getBoundingClientRect().height;
              if (cur.length && used + h > avail) { sheets.push(cur); cur = []; used = 0; }
              cur.push(r); used += h;
            });
            sheets.push(cur);
            // closing block on the last sheet; when it does not fit, the last few
            // teachers move to the next sheet with it (never signatures alone)
            if (sign && used + signH > avail + noteH && sheets[sheets.length - 1].length > 1) {
              var lastSheet = sheets[sheets.length - 1];
              sheets.push(lastSheet.splice(Math.max(1, lastSheet.length - 3)));
            }
            var pages = sheets.length;
            var out = '';
            var running = 0;
            sheets.forEach(function (slice, p) {
              var last = p === sheets.length - 1;
              running += slice.length;
              out += '<div class="ba-page">'
                   +   '<div class="ba-pageno">صفحة ' + (p + 1) + ' / ' + pages + '</div>'
                   +   topHtml
                   +   '<table class="ba">' + cols + head + '<tbody>'
                   +     slice.map(function (r) { return r.outerHTML; }).join('')
                   +   '</tbody>'
                   +   '<tfoot><tr><td colspan="9">عدد الأساتذة حتى هذه الصفحة: '
                   +     running + ' من ' + rows.length + '</td><td></td><td></td><td></td><td></td></tr></tfoot>'
                   +   '</table>'
                   + (last && sign ? sign.outerHTML
                                : '<div class="ba-note">' + noteHtml + '</div>')
                   + '</div>';
            });
            var toolbar = document.querySelector('.toolbar');
            body.innerHTML = (toolbar ? toolbar.outerHTML : '') + out;
          })();
        
EOCX
    ];
    return [<<<'EOCX'
          /* Nothing is ever cut off. A value wider than its column is first
             shrunk a little (never below 75% of the normal size); text that
             still does not fit (names, certificate, category, notes…) wraps to
             a second line. Numbers and dates (.num) stay on one line. */
          (function () {
            function fit() {
              document.querySelectorAll('table.bm td, table.ba td').forEach(function (td) {
                td.style.fontSize = '';
                td.style.whiteSpace = '';
                if (!td.textContent.trim()) { return; }
                var size = parseFloat(window.getComputedStyle(td).fontSize);
                var min = size * 0.75;
                while (td.scrollWidth > td.clientWidth + 1 && size > min) {
                  size -= 0.25;
                  td.style.fontSize = size + 'px';
                }
                if (td.scrollWidth > td.clientWidth + 1 && !td.classList.contains('num')) {
                  td.style.whiteSpace = 'normal';
                  td.style.overflowWrap = 'anywhere';
                }
              });
            }
            window.spFit = fit;
            fit();
            window.addEventListener('beforeprint', fit);
          })();
        
EOCX
    , <<<'EOCX'
          (function () {
            var body  = document.body;
            var table = document.querySelector('table.bm');
            if (!table) { return; }
            if (window.spFit) { window.spFit(); }   // measure rows at their final size
            // real printable A4 height (297mm - 2 x 7mm margins) in CSS px
            var probe = document.createElement('div');
            probe.style.cssText = 'position:absolute;visibility:hidden;height:283mm';
            body.appendChild(probe);
            var PAGE = probe.getBoundingClientRect().height;
            probe.parentNode.removeChild(probe);
            var SAFETY = 28;                     // page number line + rounding
            var cols  = (table.querySelector('colgroup') || {}).outerHTML || '';
            var thead = table.querySelector('thead'), tfoot = table.querySelector('tfoot');
            var head  = thead.outerHTML, foot = tfoot ? tfoot.outerHTML : '';
            var rows  = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
            var top = [], after = [], firstTop = null, lastAfter = null;
            var n = body.firstElementChild, seen = false;
            while (n) {
              if (n === table) { seen = true; }
              else if (n.tagName !== 'SCRIPT' && !n.classList.contains('toolbar')) {
                if (seen) { after.push(n.outerHTML); lastAfter = n; }
                else { top.push(n.outerHTML); firstTop = firstTop || n; }
              }
              n = n.nextElementSibling;
            }
            var tRect = table.getBoundingClientRect();
            var topH   = firstTop ? tRect.top - firstTop.getBoundingClientRect().top : 0;
            var headH  = thead.getBoundingClientRect().height;
            var footH  = tfoot ? tfoot.getBoundingClientRect().height : 0;
            var afterH = lastAfter ? lastAfter.getBoundingClientRect().bottom - tRect.bottom : 0;
            // the stamp / signature block, repeated under every sheet's table
            var sign = document.querySelector('.bm-sign');
            var signHtml = sign ? sign.outerHTML : '';
            var signH = sign ? sign.getBoundingClientRect().height + 10 : 0;
            var avail  = PAGE - SAFETY - topH - headH - signH;
            // fill every sheet to the bottom with as many rows as really fit
            var sheets = [], cur = [], used = 0;
            rows.forEach(function (r) {
              var h = r.getBoundingClientRect().height;
              if (cur.length && used + h > avail) { sheets.push(cur); cur = []; used = 0; }
              cur.push(r); used += h;
            });
            sheets.push(cur);
            // totals + NB / summary / note / signatures go on the last sheet;
            // when they do not fit, the last few teachers move to the next
            // sheet with them, so the totals never stand alone on a page
            if (used + footH + afterH > avail + signH && sheets[sheets.length - 1].length > 1) {
              var lastSheet = sheets[sheets.length - 1];
              sheets.push(lastSheet.splice(Math.max(1, lastSheet.length - 3)));
            }
            var pages = sheets.length;
            var out = '';
            sheets.forEach(function (sl, p) {
              var last = p === sheets.length - 1;
              out += '<div class="bm-page">'
                   +   '<div class="bm-pageno">صفحة ' + (p + 1) + ' / ' + pages + '</div>'
                   +   top.join('')
                   +   '<table class="bm">' + cols + head + '<tbody>'
                   +     sl.map(function (r) { return r.outerHTML; }).join('')
                   +   '</tbody>' + (last ? foot : '') + '</table>'
                   +   (last ? after.join('') : signHtml)
                   + '</div>';
            });
            var toolbar = document.querySelector('.toolbar');
            body.innerHTML = (toolbar ? toolbar.outerHTML : '') + out;
          })();
        
EOCX
    ];
}

/** العدد الصحيح بالأحرف كما يكتبه موقع الصندوق (num2words lang=ar، to_cardinal) */
function eocWordsAr($n): string {
    $n = (int)round((float)$n); if ($n <= 0) return 'صفر';
    $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tensW = ['عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hund = ['', 'مائة', 'مئتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    $appTwos = ['مئتا', 'ألفا', 'مليونا', 'مليارا', 'تريليونا']; $twos = ['مئتان', 'ألفان', 'مليونان', 'ملياران', 'تريليونان'];
    $grp = ['مائة', 'ألف', 'مليون', 'مليار', 'تريليون']; $appGrp = ['', 'ألفاً', 'مليوناً', 'ملياراً', 'تريليوناً']; $plGrp = ['', 'آلاف', 'ملايين', 'مليارات', 'تريليونات'];
    $group = function (int $g, int $level) use ($n, $ones, $tensW, $hund, $appTwos, $twos, $grp): string {
        $tens = $g % 100; $h = intdiv($g, 100); $r = '';
        if ($h > 0) { if ($tens === 0 && $h === 2) $r = $appTwos[0]; else { $r = $hund[$h]; if ($r !== '' && $tens !== 0) $r .= ' و '; } }
        if ($tens > 0) {
            if ($tens < 20) {
                if ($tens === 2 && $h === 0 && $level > 0) { $pow = strlen((string)$n) - 1; $r = ($n > 10 && $pow % 3 === 0 && $n === 2 * (10 ** $pow)) ? $appTwos[$level] : $twos[$level]; }
                elseif ($tens === 1 && $level > 0) $r .= $grp[$level];
                else $r .= $ones[$tens];
            } else { $o = $tens % 10; $t = intdiv($tens, 10) - 2; if ($o > 0) $r .= $ones[$o]; if ($r !== '' && $o !== 0) $r .= ' و '; $r .= $tensW[$t]; }
        }
        return $r;
    };
    $ret = ''; $level = 0; $t = $n;
    while ($t > 0) {
        $p = $t % 1000; $t = intdiv($t, 1000); $d = $group($p, $level);
        if ($d !== '') {
            if ($level > 0) {
                if ($ret !== '') $ret = 'و ' . $ret;
                if ($p !== 2 && $p !== 1) {
                    if ($p % 100 !== 1) { if ($p >= 3 && $p <= 10) $ret = $plGrp[$level] . ' ' . $ret; else $ret = ($ret !== '' ? $appGrp[$level] : $grp[$level]) . ' ' . $ret; }
                    else $ret = $grp[$level] . ' ' . $ret;
                }
            }
            $ret = $d . ' ' . $ret;
        }
        $level++;
    }
    return trim($ret);
}

/**
 * @param string $kind 'financial' | 'general'
 * @param array  $c    school_name, school_type, caisse_no, phone, sy, rows + (مالي) code, quarter, staff, contract, tot  ·  (عام) emp ('staff'|'contract')
 */
function eocSheetHtml(string $kind, array $c): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $num = fn($v) => (float)str_replace([',', ' '], '', (string)$v);
    $f0 = fn($v) => number_format($num($v), 0);
    $hrs = function ($v) use ($num) { $s = number_format($num($v), 2, '.', ''); return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s; };
    $typeLbl = ['free' => 'مجاني', 'non_free' => 'غيرمجاني', 'professional' => 'مهني'][$c['school_type'] ?? 'non_free'] ?? 'غيرمجاني';   // الورقة تكتبها «غيرمجاني» ملتصقة
    $P = $kind === 'general' ? 'ba' : 'bm'; $isStaff = ($c['emp'] ?? 'staff') === 'staff';
    $o = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>'
       . '<title>' . $e($kind === 'general' ? 'البيان العام ' . $c['sy'] . ' — ' . ($isStaff ? 'ملاك' : 'متعاقدين') : 'البيان المالي ' . ($c['code'] ?? '')) . '</title><style>' . eocSheetCss($kind) . '</style></head><body>'
       . '<div class="toolbar"><button onclick="window.print()">🖨 طباعة</button></div>'
       . '<div class="' . $P . '-top"><div class="' . $P . '-title">صندوق التعويضات<br/>لافراد الهيئة التعليمية في المدارس الخاصة</div>'
       . '<div class="' . $P . '-desc">' . ($kind === 'general' ? 'البيان العام السنوي لأفراد الهيئة التعليمية الداخلين في ' . ($isStaff ? 'الملاك' : 'التعاقد') : 'بيان بالمحسومات المقتطعة ومساهمة المدرسة عن افراد الهيئة التعليمية الداخلين في الملاك') . '</div></div>'
       . '<div class="' . $P . '-school">في مدرسة <span>' . $e($c['school_name']) . '</span> / ' . $typeLbl . '</div>'
       . '<div class="' . $P . '-meta">رقم المدرسة <span>' . $e($c['caisse_no']) . '</span></div>'
       . '<div class="' . $P . '-meta">هاتف المدرسة <span class="num">' . $e($c['phone']) . '</span></div>'
       . '<div class="' . $P . '-meta">من السنة المدرسية <span>' . $e($c['sy']) . '</span></div>';
    $td = fn($v, $cl = 'num') => '<td class="' . $cl . '"><span>' . $e($v) . '</span></td>';
    if ($kind === 'general') {
        $rows = $c['rows']; $n = count($rows); $tH = 0; $tS = 0; $tA = 0;
        $o .= '<div class="ba-quarter">فئة: <span>' . ($isStaff ? 'ملاك' : 'متعاقد') . '</span> — عدد الأساتذة: <span>' . $n . '</span></div>'
            . '<table class="ba"><colgroup><col style="width:3%"/><col style="width:6.5%"/><col style="width:16.5%"/><col style="width:8%"/><col style="width:8%"/><col style="width:10.5%"/><col style="width:6%"/><col style="width:5%"/><col style="width:8%"/><col style="width:4%"/><col style="width:8%"/><col style="width:7%"/><col style="width:9.5%"/></colgroup>'
            . '<thead><tr><th class="num">#</th><th>الرقم المالي</th><th>الاسم والشهرة</th><th>اسم الاب</th><th>تاريخ الولادة</th><th>الشهادة</th><th>الفئة</th><th>المرحلة</th><th>تاريخ دخول الخدمة</th><th class="num">ساعات</th><th class="num">الراتب</th><th class="num">الأجر الاضافي</th><th>التوقيع</th></tr></thead><tbody>';
        $i = 0;
        foreach ($rows as $r) { $i++; $tH += $num($r['teaching_hours']); $tS += $num($r['salary']); $tA += $num($r['add_salary']);
            $o .= '<tr>' . $td($i) . $td($r['teacher_no']) . $td($r['teacher_name'], 'name') . $td($r['father_name'], 'name') . $td($r['birth_date'])
                . '<td><span>' . $e($r['certificate'] ?? '') . '</span></td><td><span>' . $e($r['category'] ?? '') . '</span></td><td><span>' . $e($r['phase'] ?? '') . '</span></td>'
                . $td($r['start_date']) . $td((string)$r['teaching_hours'] === '' ? '' : $hrs($r['teaching_hours'])) . $td((string)$r['salary'] === '' ? '' : $f0($r['salary'])) . $td((string)$r['add_salary'] === '' ? '' : $f0($r['add_salary'])) . '<td class="sign"></td></tr>'; }
        $o .= '</tbody><tfoot><tr><td colspan="9">المجموع — عدد الاساتذة <span>' . $n . '</span></td>' . $td($hrs($tH)) . $td($f0($tS)) . $td($f0($tA)) . '<td></td></tr></tfoot></table>';
        $sign = '<tr><td style="width:50%;text-align:right">ملحوظة: ينظم هذا البيان عن جميع أفراد الهيئة التعليمية في المدرسة</td><td style="width:50%;text-align:center">نظم وصدق على مسؤوليتي بتاريخ</td></tr>'
              . '<tr><td style="text-align:center;padding-top:14px">خاتم المدرسة</td><td style="text-align:center;padding-top:14px">توقيع المدير أو من يقوم مقامه</td></tr>';
        $o .= '<div class="ba-note-src" style="display:none"><table class="ba-sign-page">' . $sign . '</table></div>'
            . '<table class="ba-sign">' . $sign . '<tr><td style="text-align:right;padding-top:0;font-weight:bold">عدد الاساتذة: <span>' . $n . '</span></td><td></td></tr></table>';
    } else {
        $rows = $c['rows']; $t = $c['tot']; $n = count($rows);
        $o .= '<div class="bm-quarter">عن الفصل: <span>' . $e($c['quarter']) . '</span></div>'
            . '<div class="bm-meta">البيان العام لهذه السنة — ملاك: <span>' . (int)$c['staff'] . '</span> — متعاقدين: <span>' . (int)$c['contract'] . '</span></div>'
            . '<table class="bm"><colgroup><col style="width:3%"/><col style="width:6%"/><col style="width:14%"/><col style="width:7.5%"/><col style="width:8.5%"/><col style="width:8.5%"/><col style="width:8%"/><col style="width:8.5%"/><col style="width:9.5%"/><col style="width:8%"/><col style="width:8%"/><col style="width:10.5%"/></colgroup>'
            . '<thead><tr><th class="num">#</th><th>الرقم المالي</th><th>الاسم والشهرة</th><th>اسم الاب</th><th>الراتب الشهري السابق</th><th>الراتب الشهري الحالي</th><th>الأجر الاضافي</th><th>المحسومات الشهرية ل.ل</th><th>المحسومات الفصلية ل.ل</th><th>نصف راتب ل.ل</th><th>مختلف، درجة تمرين ل.ل</th><th>ملاحظات</th></tr></thead><tbody>';
        $i = 0;
        foreach ($rows as $r) { $i++;
            $o .= '<tr>' . $td($i) . $td($r['teacher_no']) . $td($r['teacher_name'], 'name') . $td($r['father_name'] ?? '', 'name')
                . $td($f0($r['previous_salary'])) . $td($f0($r['current_salary'])) . $td($f0($r['add_salary'])) . $td($f0($r['teacher_m_disc'])) . $td($f0($r['teacher_q_disc'])) . $td($f0($r['discount_half_salary'])) . $td($f0($r['training_degree']))
                . '<td class="name">' . (trim((string)($r['remarks'] ?? '')) !== '' ? '<span>' . $e($r['remarks']) . '</span>' : '') . '</td></tr>'; }
        $o .= '</tbody><tfoot><tr><td colspan="4">المجموع — عدد الاساتذة <span>' . $n . '</span></td>' . $td($f0($t['previous_salary'])) . $td($f0($t['current_salary'])) . $td($f0($t['add_salary'])) . $td($f0($t['teacher_m_disc'])) . $td($f0($t['teacher_q_disc'])) . $td($f0($t['discount_half_salary'])) . $td($f0($t['training_degree'])) . '<td></td></tr></tfoot></table>';
        $sum = fn($l, $v) => '<tr><td class="lbl">' . $l . '</td><td class="val num"><span>' . $f0($v) . '</span></td></tr>';
        $o .= '<div class="bm-keep"><table class="bm-foot"><tr><td class="bm-foot-sum"><table class="bm-sum">'
            . $sum('المحسومات الفصلية', $t['teacher_q_disc']) . $sum('المبلغ الإضافي (نصف راتب + درجة)', $t['extra_amount']) . $sum('المحسومات المقتطعة', $t['teacher_total']) . $sum('مساهمة المدرسة', $t['school_total']) . $sum('المجموع العام', $t['grand'])
            . '</table><div class="bm-words"><span>فقط ' . $e(eocWordsAr($t['grand'])) . ' ليرة لبنانية</span></div></td><td class="bm-foot-note"><div class="bm-note">'
            . 'ملاحظة: نص المادة 6 من المرسوم الاشتراعي رقم 47 تاريخ 29/06/1983<br/>تتكون المحسومات من:<br/>1- نصف راتب الشهر الأول من خدمة الموظف.<br/>2- ستة بالمئة من الراتب الشهري.<br/>3- القسط الأول من كل زيادة تطرأ على الراتب.'
            . '</div></td></tr></table><div class="bm-sign"><div>ختم المدرسة</div><div>توقيع المدير أو من يقوم مقامه</div><div>نظم وصدق على مسؤوليتي بتاريخ<br/>____ / ____ / ______</div></div></div>';
    }
    foreach (eocSheetJs($kind) as $js) $o .= '<script type="text/javascript">' . $js . '</script>';
    return $o . '</body></html>';
}

/**
 * 🖨️ البيان العام بورقة واحدة للطباعة/الحفظ: ورقة الملاك ثمّ ورقة المتعاقدين (كل واحدة كما يطبعها الموقع لوحدها، بترقيمها الخاصّ، والثانية تبدأ بصفحة جديدة).
 * سكربت تقسيم الصفحات هو سكربت الموقع نفسه، مُشغَّلاً مرّة لكل قسم (document.body ⇒ القسم). أيّ تغيّر بنصّ السكربت يُكشف هنا (استثناء) بدل ورقة مخرَّبة.
 */
function eocSheetGeneralBoth(array $cStaff, array $cContract): string {
    $docs = [eocSheetHtml('general', ['emp' => 'staff'] + $cStaff), eocSheetHtml('general', ['emp' => 'contract'] + $cContract)];
    $secs = [];
    foreach ($docs as $d) {
        if (!preg_match('#<body>(.*)</body>#su', $d, $m)) throw new RuntimeException('eoc sheet: body');
        $b = preg_replace('#<script\b.*?</script>#su', '', $m[1]); $b = preg_replace('#<div class="toolbar">.*?</div>#su', '', $b, 1);
        $secs[] = '<div class="ba-sec">' . $b . '</div>';
    }
    [$fit, $pg] = eocSheetJs('general'); $n = 0;
    $pg2 = str_replace('var body  = document.body;', 'var body  = ROOT;', $pg, $c1); $pg2 = str_replace("document.querySelector('", "ROOT.querySelector('", $pg2, $c2);
    $pg2 = str_replace("body.innerHTML = (toolbar ? toolbar.outerHTML : '') + out;", 'body.innerHTML = out;', $pg2, $c3);
    $pg2 = preg_replace('/^\s*\(function \(\) \{/u', 'function eocPaginate(ROOT) {', $pg2, 1, $c4); $pg2 = preg_replace('/\}\)\(\);\s*$/u', '}', $pg2, 1, $c5);
    if ($c1 !== 1 || $c2 < 4 || $c3 !== 1 || $c4 !== 1 || $c5 !== 1 || strpos($pg2, 'document.') !== false && preg_match('/document\.(?!createElement)/', $pg2)) throw new RuntimeException('eoc sheet: fund script changed');
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>' . $e('البيان العام ' . ($cStaff['sy'] ?? '')) . '</title><style>' . eocSheetCss('general')
         . ' .ba-sec{position:relative} .ba-sec + .ba-sec{page-break-before:always;break-before:page} @media screen{ .ba-sec + .ba-sec{margin-top:28px;border-top:3px double #33405c;padding-top:14px} }</style></head><body>'
         . '<div class="toolbar"><button onclick="window.print()">🖨 طباعة</button></div>' . implode('', $secs)
         . '<script type="text/javascript">' . $fit . '</script><script type="text/javascript">' . $pg2 . "\n" . 'Array.prototype.slice.call(document.querySelectorAll(".ba-sec")).forEach(eocPaginate);</script></body></html>';
}

/**
 * 📝 وورد «طبق ورقة الصندوق» (2026-11-17 «صحّح الكل متل الصندوق»): نفس محتوى الورقة وترتيبها بمستند يفتحه وورد ويُعدَّل فيه.
 * يُبنى من ورقة eocSheetHtml نفسها (مصدر واحد): تُشال السكربتات وشريط الطباعة، والترويسة/التواقيع (flex) تصير جداول لأنّ وورد لا يفهم flex؛
 * تقسيم الصفحات وتكرار رأس الجدول يتولّاهما وورد. $docs = ورقة واحدة أو عدّة أوراق (العام: ملاك ثمّ متعاقدين، كل واحدة بصفحة جديدة).
 */
function eocSheetWordDoc(array $docs, string $title): string {
    $parts = []; $css = '';
    foreach ($docs as $d) {
        if (!preg_match('#<style>(.*?)</style>#su', $d, $mc) || !preg_match('#<body>(.*)</body>#su', $d, $m)) throw new RuntimeException('eoc sheet: word');
        $css = $mc[1]; $b = preg_replace('#<script\b.*?</script>#su', '', $m[1]); $b = preg_replace('#<div class="toolbar">.*?</div>#su', '', $b, 1);
        $b = preg_replace('#<div class="ba-note-src".*?</table>\s*</div>#su', '', $b);
        $b = preg_replace('#<div class="(b[am])-top"><div class="b[am]-title">(.*?)</div><div class="b[am]-desc">(.*?)</div></div>#su',
            '<table class="w-top" dir="rtl" width="100%" cellspacing="0" cellpadding="0"><tr><td class="$1-title" width="60%" align="right" valign="top">$2</td><td class="$1-desc" width="40%" align="left" valign="top">$3</td></tr></table>', $b, 1, $c1);
        $b = preg_replace('#<div class="bm-sign"><div>(.*?)</div><div>(.*?)</div><div>(.*?)</div></div>#su',
            '<table class="w-sign" dir="rtl" width="100%" cellspacing="0" cellpadding="0"><tr><td width="33%" align="center" valign="top">$1<br/><br/><br/></td><td width="34%" align="center" valign="top">$2</td><td width="33%" align="center" valign="top">$3</td></tr></table>', $b);
        if ($c1 !== 1) throw new RuntimeException('eoc sheet: word header');
        $b = str_replace(['<table class="bm">', '<table class="ba">', '<table class="ba-sign">', '<table class="bm-foot">', '<table class="bm-sum">'],
                         ['<table class="bm" dir="rtl" width="100%" border="1" cellspacing="0" cellpadding="2">', '<table class="ba" dir="rtl" width="100%" border="1" cellspacing="0" cellpadding="2">', '<table class="ba-sign" dir="rtl" width="100%">', '<table class="bm-foot" dir="rtl" width="100%">', '<table class="bm-sum" dir="rtl">'], $b);
        $b = preg_replace('#<td class="sign"></td>#', '<td class="sign">&nbsp;</td>', $b); $b = preg_replace('#<td class="name"></td>#', '<td class="name">&nbsp;</td>', $b); $b = str_replace('<td></td>', '<td>&nbsp;</td>', $b);
        // أرقام لاتينية كالورقة (وورد يقلبها هندية بالنصّ العربي): كل رقم/تاريخ/مبلغ بنصّ الورقة يُعلَّم لغة EN واتجاه LTR
        $b = preg_replace_callback('#>([^<]+)<#u', fn($t) => '>' . preg_replace('#\d[\d,./\-]*\d|\d#u', '<span lang="EN-US" dir="ltr" style="mso-ansi-language:EN-US;mso-bidi-language:EN-US">$0</span>', $t[1]) . '<', $b);
        $parts[] = $b;
    }
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $wcss = '@page WordSection1{size:595.3pt 841.9pt;margin:19.85pt 19.85pt 19.85pt 19.85pt;mso-header-margin:0;mso-footer-margin:0;mso-paper-source:0}'
          . ' div.WordSection1{page:WordSection1} body,table,td,th,div,span{font-family:"Segoe UI","Arial",sans-serif}'
          . ' table.w-top td,table.w-sign td{border:0;padding:0} table.w-sign{margin-top:8px;font-size:12px} table.bm thead th,table.ba thead th{mso-yfti-irow:0}'
          . ' td.sign{height:20pt} table.bm,table.ba{mso-table-layout-alt:fixed}';
    $css = preg_replace('#@page\{[^}]*\}#u', '', $css); $css = preg_replace('#@media (screen|print)\{.*?\}\s*\}#su', '', $css);
    return "\xEF\xBB\xBF" . '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40" dir="rtl" lang="ar"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>'
         . '<title>' . $e($title) . '</title><!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]--><style>' . $css . $wcss . '</style></head>'
         . '<body dir="rtl"><div class="WordSection1" dir="rtl">' . implode('<br clear="all" style="mso-special-character:line-break;page-break-before:always"/>', $parts) . '</div></body></html>';
}

/** لا خدمة PDF ⇒ الورقة نفسها مع فتح حوار الطباعة تلقائياً («حفظ بصيغة PDF» من المتصفّح) */
function eocSheetAutoPrint(string $html): string { return str_replace('</body>', '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},600)});</script></body>', $html); }
