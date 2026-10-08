/**
 * 💾 «احفظها عالكمبيوتر» = تنزيل ملف PDF حقيقي بكبسة واحدة — بلا شاشة طباعة
 * (طلب المستخدم 2026-08-01: «ما بيّن عندي Save as PDF» بحوار المتصفح).
 *
 * الطريقة: تصوير القسيمة/البطاقة كما تُطبع تماماً (بقلب قواعد @media print مؤقتاً
 * وعرض ورقة التصميم) عبر html-to-image (رسم أصلي بالمتصفح = نفس الشكل بالحرف،
 * عربي وخطوط مضبوطة)، ثم تركيب الصور بملف PDF عبر jsPDF وتنزيله مباشرة.
 * المكتبتان محليتان في assets/vendor (بلا إنترنت خارجي) وتُحمَّلان عند أول كبسة فقط.
 * صفحة بلا قسائم (نموذج رسمي أو تقرير): نرجع لحوار طباعة المتصفح كما كان.
 */
(function () {
    'use strict';

    // سجلّ خطوات داخلي للتشخيص (يُقرأ من window.__pdfSaveLog عند أي مشكلة)
    var LOG = []; window.__pdfSaveLog = LOG;
    function step(m) { LOG.push(m); }

    function loadScript(src) {
        return new Promise(function (res, rej) {
            var s = document.createElement('script');
            s.src = src; s.onload = res; s.onerror = function () { rej(new Error('load ' + src)); };
            document.head.appendChild(s);
        });
    }

    // قلب قواعد @media print إلى الشاشة (نفس نمط قياس --pz في app.js) — يُعاد كل شيء في restore
    function flipPrintRules() {
        var flipped = [];
        for (var s = 0; s < document.styleSheets.length; s++) {
            var rules; try { rules = document.styleSheets[s].cssRules; } catch (e) { continue; }
            if (!rules) continue;
            for (var r = 0; r < rules.length; r++) {
                var rule = rules[r];
                if (rule.media && /print/.test(rule.media.mediaText)) {
                    flipped.push([rule, rule.media.mediaText]);
                    rule.media.mediaText = 'all';
                }
                // تلوين الشاشة (بطاقات/جداول زرقاء فاتحة — app.css قسم 216) يُطفأ أثناء التصوير: المحفوظ أبيض كالورق
                else if (rule.media && /min-width:\s*1px/.test(rule.media.mediaText)) {
                    flipped.push([rule, rule.media.mediaText]);
                    rule.media.mediaText = 'not all';
                }
            }
        }
        return function () {
            for (var i = 0; i < flipped.length; i++) flipped[i][0].media.mediaText = flipped[i][1];
        };
    }

    // ستارة بيضاء تغطي الشاشة أثناء التصوير (يتبدّل تنسيق الصفحة للحظة — ما في داعي يشوفها)
    // ترجع {done, set}: set لتحديث نص التقدّم («صفحة X من Y» بالطباعة الجماعية)
    function curtain(msg) {
        var d = document.createElement('div');
        d.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#334155;font-family:inherit';
        d.textContent = msg;
        document.body.appendChild(d);
        return { done: function () { d.remove(); }, set: function (t) { d.textContent = t; } };
    }

    function fileName() {
        var n = document.querySelector('.slip-emp-name .slip-pname');
        var base = document.querySelector('.salary-slip') ? 'releve_annuel' : 'bulletin';
        var who = n ? '_' + n.textContent.trim().replace(/[\\/:*?"<>|\s]+/g, '_').slice(0, 40) : '';
        return base + who + '.pdf';
    }

    function loadLibs() {
        var vend = (window.BASE_URL || '') + 'assets/vendor/';
        return Promise.resolve()
            .then(function () { step('load h2i'); return window.htmlToImage ? 0 : loadScript(vend + 'html-to-image.js'); })
            .then(function () { step('load jspdf'); return window.jspdf ? 0 : loadScript(vend + 'jspdf.umd.min.js'); });
    }

    // ===== 📄 أي صفحة أخرى (تقرير/نموذج/إفادة): تصوير ورقة المستند وتقطيعها صفحات A4 بلا قصّ سطر =====
    // («كبسة احفظها على الكمبيوتر عم تطلع متل طباعة على الورق» 2026-09-13 — كانت ترجع لحوار المتصفح للتقارير)
    function genericArea() {
        return document.getElementById('ppExportArea')
            || document.querySelector('.doc-sheet, .xls-sheet, .land-report')
            || document.getElementById('pageContent') || document.body;
    }
    function genericWide(a) {
        if (a.matches && a.matches('.xls-sheet, .land-report')) return true;
        if (a.querySelector('.xls-sheet, .land-report')) return true;
        var wide = false;
        a.querySelectorAll('table').forEach(function (t) { var rows = t.querySelectorAll('tr'); for (var i = 0; i < rows.length && i < 6; i++) if (rows[i].children.length >= 9) wide = true; });
        return wide;
    }
    function genericName() {
        var h = document.querySelector('.doc-head .dh-ar, .doc-head .dh-fr, #ppExportArea h1, #ppExportArea h2');
        var t = ((h && h.textContent.trim()) || (document.title || 'document').split(/[—|]/)[0]).trim();
        return (t.replace(/[\\/:*?"<>|\s]+/g, '_').slice(0, 60) || 'document') + '.pdf';
    }
    // أقرب سطر أبيض فوق الموضع المطلوب (حتى 45٪ من ارتفاع الصفحة) — كي لا يُقطع سطر جدول بين صفحتين
    function safeCut(canvas, ctx, from, want) {
        var minY = Math.max(from + Math.floor(want * 0.55), from + 40);
        var w = canvas.width;
        for (var y = from + want; y > minY; y -= 2) {
            var row = ctx.getImageData(0, y, w, 1).data, white = true;
            for (var x = 0; x < row.length; x += 16) { if (row[x] < 235 || row[x + 1] < 235 || row[x + 2] < 235) { white = false; break; } }
            if (white) return y - from;
        }
        return want;
    }
    // 🔠 (2026-10-08 «أي تقرير بدّي أكبس احفظ عالكمبيوتر عم يطلع الخط والأرقام كتير صغار — لازم يكون حجم الخط 12»):
    // لا تصغير بعد اليوم. الجدول الأعرض من الورقة (بخط 12) ينقسم أعمدةً على أكثر من جزء: كل جزء يكرّر أعمدة
    // التعريف (الرقم + الاسم) ثم مجموعة أعمدة تسع الورقة — كما تطبع إكسل الجداول العريضة. الأصل يُخفى وقت التصوير فقط.
    function splitWideTables(area, maxW) {
        var made = [], tables = area.querySelectorAll('table');
        Array.prototype.forEach.call(tables, function (t) {
            if (!t.rows.length || t.closest('[data-pdf-clone]')) return;
            var natW = t.scrollWidth;
            if (natW <= maxW + 2) return;
            // شبكة الخلايا (colspan/rowspan)
            var occ = [], cols = 0, rows = Array.prototype.slice.call(t.rows);
            rows.forEach(function (tr, ri) {
                occ[ri] = occ[ri] || []; var c = 0;
                Array.prototype.forEach.call(tr.cells, function (cell) {
                    while (occ[ri][c]) c++;
                    var cs = cell.colSpan || 1, rs = cell.rowSpan || 1;
                    for (var i = 0; i < rs; i++) { occ[ri + i] = occ[ri + i] || []; for (var j = 0; j < cs; j++) occ[ri + i][c + j] = { cell: cell, c0: c, r0: ri, cs: cs }; }
                    c += cs;
                });
                if (c > cols) cols = c;
            });
            if (cols < 3) return;
            // عرض كل عمود (من خلية بلا دمج)
            var w = [], c, r;
            for (c = 0; c < cols; c++) {
                w[c] = 0;
                for (r = 0; r < occ.length; r++) { var e = occ[r] && occ[r][c]; if (e && e.cs === 1) { w[c] = Math.ceil(e.cell.getBoundingClientRect().width); break; } }
                if (!w[c]) w[c] = Math.ceil(natW / cols);
            }
            // أعمدة التعريف: حتى عمود الاسم (وإلا أوّل عمودين)
            var K = Math.min(2, cols), hdRows = t.tHead ? Array.prototype.slice.call(t.tHead.rows) : [rows[0]];
            outer: for (r = 0; r < hdRows.length; r++) {
                var ri0 = rows.indexOf(hdRows[r]);
                for (c = 0; c < Math.min(cols, 4); c++) {
                    var he = occ[ri0] && occ[ri0][c];
                    if (he && he.cs === 1 && /اسم|الأستاذ|الموظف|الأجير|nom|employ|enseignant|name/i.test(he.cell.textContent || '')) { K = c + 1; break outer; }
                }
            }
            var keyW = 0; for (c = 0; c < K; c++) keyW += w[c];
            if (keyW > maxW * 0.5) { K = 1; keyW = w[0]; }
            var avail = Math.max(maxW - keyW - 8, w[K] || 1), groups = [], cur = [], sum = 0;
            for (c = K; c < cols; c++) {
                if (cur.length && sum + w[c] > avail) { groups.push(cur); cur = []; sum = 0; }
                cur.push(c); sum += w[c];
            }
            if (cur.length) groups.push(cur);
            if (groups.length < 2) return;
            var frag = document.createDocumentFragment();
            groups.forEach(function (g, gi) {
                var keep = {}; for (c = 0; c < K; c++) keep[c] = 1; g.forEach(function (x) { keep[x] = 1; });
                var ct = t.cloneNode(false); ct.setAttribute('data-pdf-clone', '1'); ct.removeAttribute('id');
                ct.style.width = ''; ct.style.minWidth = '0'; ct.style.maxWidth = maxW + 'px'; ct.style.zoom = '1'; ct.style.setProperty('--pz', 1);
                Array.prototype.forEach.call(t.children, function (sec) {
                    if (!/^(thead|tbody|tfoot)$/i.test(sec.tagName)) { ct.appendChild(sec.cloneNode(true)); return; }
                    var cs2 = sec.cloneNode(false); ct.appendChild(cs2);
                    Array.prototype.forEach.call(sec.rows, function (tr) {
                        var ri = rows.indexOf(tr), ntr = tr.cloneNode(false), any = false;
                        for (c = 0; c < cols; c++) {
                            var e2 = occ[ri] && occ[ri][c];
                            if (!e2 || e2.r0 !== ri || e2.c0 !== c) continue;
                            var n = 0; for (var k = e2.c0; k < e2.c0 + e2.cs; k++) if (keep[k]) n++;
                            if (!n) continue;
                            var nc = e2.cell.cloneNode(true);
                            if (n > 1) nc.colSpan = n; else nc.removeAttribute('colspan');
                            nc.style.position = 'static'; nc.style.transform = ''; nc.style.top = '';
                            ntr.appendChild(nc); any = true;
                        }
                        if (any) cs2.appendChild(ntr);
                    });
                });
                var note = document.createElement('div');
                note.setAttribute('data-pdf-clone', '1');
                note.style.cssText = 'font-size:12pt;font-weight:700;margin:' + (gi ? '14px' : '0') + ' 0 4px;color:#1F4E5F';
                note.textContent = (gi ? 'تتمّة الجدول' : 'الجدول') + ' — جزء ' + (gi + 1) + ' من ' + groups.length + ' / Tableau — partie ' + (gi + 1) + '/' + groups.length;
                frag.appendChild(note); frag.appendChild(ct);
                made.push(note); made.push(ct);
            });
            t.parentNode.insertBefore(frag, t.nextSibling);
            made.push({ hidden: t, display: t.style.display }); t.style.display = 'none';
        });
        return function () {
            made.forEach(function (m) { if (m.hidden) m.hidden.style.display = m.display || ''; else if (m.parentNode) m.parentNode.removeChild(m); });
        };
    }
    function buildGenericPdf(area) {
        var cur = curtain('⏳ عم نجهّز ملف الـPDF... لحظة');
        document.body.classList.add('pr-capture'); // عنوان الورقة يبقى ظاهراً بالتصوير (الصفّ المحقون للطباعة الورقية يُخفى هنا)
        try { if (window.msaFitPrintZoom) window.msaFitPrintZoom(); if (window.msaFitDocTables) window.msaFitDocTables(); } catch (eFit) {} var restore = flipPrintRules();
        var landscape = window.msaOrientForced ? (window.msaOrientForced === 'landscape') : genericWide(area); // 🔄 زرّ الاتجاه أولاً
        // 🔠 خط 12 ثابت (2026-10-08): كل جدول بحجمه الطبيعي (--pz=1، zoom=1) — لا تصغير محسوب للورق هنا
        var pzPrev = [];
        area.querySelectorAll('table').forEach(function (t) { pzPrev.push([t, t.style.getPropertyValue('--pz'), t.style.zoom]); t.style.setProperty('--pz', 1); t.style.zoom = '1'; });
        var PORT_W = 733, LAND_W = 1062;                      // عرض ورقة A4 داخل هوامش 8mm بمقياس 96px/inch (12pt = 16px)
        var prevW = area.style.width, prevMax = area.style.maxWidth, prevMg = area.style.margin;
        area.style.width = PORT_W + 'px'; area.style.maxWidth = 'none'; area.style.margin = '0';
        // صفّ «عنوان التقرير بكل ورقة» الذي يحقنه app.js برأس الجدول للطباعة الورقية — هنا نعيد الترويسة بأنفسنا
        // فوق كل ورقة فيُخفى وقت القياس والتصوير معاً (وإلا ظهر العنوان مرّتين)
        var prRows = []; area.querySelectorAll('.pr-title-row').forEach(function (r) { prRows.push([r, r.style.display]); r.style.display = 'none'; });
        // رؤوس الأعمدة اللاصقة (position:sticky من app.js) تنزاح بالتصوير فتترك صفّاً فارغاً فوقها — ثابتة وقت التصوير فقط
        var stStyle = document.createElement('style'); stStyle.textContent = '#ppExportArea th, .doc-sheet th, .land-report th { position: static !important; }'; document.head.appendChild(stStyle);
        // الجداول العريضة داخل حاويات تمرير (table-wrapper) كانت تُقصّ — نكشف الفيض ونوسّع الورقة على قدّ أعرض جدول (تُلاءَم بالـPDF)
        var wraps = [], need = designW;
        area.querySelectorAll('.table-wrapper, [style*="overflow"]').forEach(function (w) { wraps.push([w, w.style.overflow, w.style.overflowX]); w.style.overflow = 'visible'; w.style.overflowX = 'visible'; });
        // أعرض جدول بخط 12: أعرض من الورقة العمودية ⇒ أفقي؛ أعرض من الأفقية ⇒ ينقسم أعمدةً (splitWideTables) — الخط لا يصغر
        var natMax = 0; area.querySelectorAll('table').forEach(function (t) { natMax = Math.max(natMax, t.scrollWidth); });
        if (!window.msaOrientForced && natMax > PORT_W - 4) landscape = true;
        var designW = landscape ? LAND_W : PORT_W; need = designW;
        area.style.width = designW + 'px';
        var unsplit = splitWideTables(area, designW - 4);
        area.querySelectorAll('table').forEach(function (t) { if (t.offsetParent && t.scrollWidth + 12 > need) need = t.scrollWidth + 12; });
        if (need > designW) area.style.width = need + 'px';   // صمام فقط (جدول لا ينقسم: أقلّ من 3 أعمدة)
        var sw = area.scrollWidth; if (sw > need + 2) area.style.width = sw + 'px';
        function undo() { try { unsplit(); } catch (eU) {} pzPrev.forEach(function (x) { if (x[1]) x[0].style.setProperty('--pz', x[1]); else x[0].style.removeProperty('--pz'); x[0].style.zoom = x[2]; }); area.style.width = prevW; area.style.maxWidth = prevMax; area.style.margin = prevMg; wraps.forEach(function (x) { x[0].style.overflow = x[1]; x[0].style.overflowX = x[2]; }); prRows.forEach(function (x) { x[0].style.display = x[1]; }); if (stStyle.parentNode) stStyle.parentNode.removeChild(stStyle); restore(); document.body.classList.remove('pr-capture'); cur.done(); }
        // 📄 (2026-10-08) ترقيم صفحات بالصفوف: كل ورقة تُبنى DOM صغيراً (ترويسة المستند + رأس الجدول + ما يسع الورقة
        //    من صفوفه بخط 12) على مسرح مخفي ثم تُصوَّر وحدها — بدل تصوير المستند كلّه لوحة واحدة (كان المتصفّح يسقفها
        //    عند 16384px فيصغّر التقرير الطويل كلّه إلى خط 3 نقاط). رأس الجدول يُعاد فوق كل ورقة، والجدول الأعرض من
        //    الورقة سبق أن انقسم أعمدةً (splitWideTables). الترويسة (كل ما قبل أوّل جدول) تُعاد فوق كل ورقة.
        var aRect = area.getBoundingClientRect(), top0 = aRect.top, areaW = Math.ceil(aRect.width);
        var pageW = landscape ? 297 : 210, pageH = landscape ? 210 : 297, M = 8;
        var boxW = pageW - 2 * M, boxH = pageH - 2 * M;
        var scale = boxW / areaW;                           // mm لكل CSS px (خط 12 = 16px ⇒ 4.2mm)
        var pageCss = Math.floor(boxH / scale * 0.985);      // ارتفاع الورقة بالـCSS px (هامش أمان ١.٥٪)
        // (١) تسطيح المحتوى عناصر متتالية: كتلة (تُنسخ كما هي) أو جدول (رأس + صفوف)
        var items = [];
        function hOf(el) { var r = el.getBoundingClientRect(); return Math.ceil(r.height); }
        function flatten(node, chain) {
            Array.prototype.forEach.call(node.children, function (ch) {
                if (!(ch instanceof Element)) return;
                var st = getComputedStyle(ch);
                if (st.display === 'none' || ch.matches('script, style, .no-print, .export-toolbar, .pr-mask')) return;
                if (ch.tagName === 'TABLE') {
                    if (ch.rows.length === 0) return;
                    var headRows = [], bodyRows = [], footRows = [];
                    Array.prototype.forEach.call(ch.children, function (sec) {
                        if (sec.tagName === 'THEAD') Array.prototype.push.apply(headRows, sec.rows);
                        else if (sec.tagName === 'TFOOT') Array.prototype.push.apply(footRows, sec.rows);
                        else if (sec.tagName === 'TBODY' && !/(^|\s)msa-top-totals(\s|$)/.test(sec.className || '')) Array.prototype.push.apply(bodyRows, sec.rows);
                    });
                    var headH = 0; headRows.forEach(function (r) { headH += hOf(r); });
                    var rows = []; bodyRows.forEach(function (r) { rows.push({ tr: r, h: hOf(r), foot: false }); }); footRows.forEach(function (r) { rows.push({ tr: r, h: hOf(r), foot: true }); });
                    items.push({ type: 'table', node: ch, headRows: headRows, headH: headH, rows: rows, chain: chain });
                } else if (ch.querySelector('table') && !ch.matches('.salary-slip, .payslip-card')) {
                    flatten(ch, chain.concat([ch]));          // غلاف فيه جدول: ننزل فيه — قشرته تُعاد ببناء الورقة (حدود/خلفية/قواعد الطباعة)
                } else {
                    var h = hOf(ch); if (h <= 0) return;
                    items.push({ type: 'block', node: ch, h: h + Math.ceil(parseFloat(st.marginTop) || 0) + Math.ceil(parseFloat(st.marginBottom) || 0), chain: chain });
                }
            });
        }
        flatten(area, []);
        // الترويسة = الكتل قبل أوّل جدول (تُعاد فوق كل ورقة إن كانت أقصر من ٤٠٪ الورقة)
        var firstT = -1; for (var fi = 0; fi < items.length; fi++) if (items[fi].type === 'table') { firstT = fi; break; }
        // يُعاد فوق كل ورقة من الترويسة ما هو عنوان/ترويسة مستند فقط (لا بطاقات الإحصاء ولا شرائط الخيارات)
        var headSel = '.doc-head, .doc-title, .doc-subtitle, .doc-meta, .doc-sub, .report-head, .report-title, .sheet-head, .letterhead, .gov-header, .page-header, h1, h2, h3';
        var headItems = firstT > 0 ? items.slice(0, firstT).filter(function (it) { return it.node.matches && (it.node.matches(headSel) || it.node.querySelector(headSel)); }) : [], headTotal = 0; headItems.forEach(function (it) { headTotal += it.h; });
        if (headTotal > pageCss * 0.4) { headItems = []; headTotal = 0; }
        // (٢) المسرح المخفي: كل ورقة تُبنى تدريجياً (كتلة كتلة وصفّاً صفّاً) وتُقاس بنفسها — ما يتجاوز الورقة ينتقل للتالية.
        //     (القياس من الأصل كان يخطئ: أعمدة الشاشة المخفية بالطباعة تغيّر ارتفاع الصفوف)
        var stage = document.createElement('div');
        stage.id = 'pdfStage';
        stage.style.cssText = 'position:absolute;left:0;top:0;width:' + areaW + 'px;z-index:-1;visibility:visible;pointer-events:none;background:#fff';
        var stStyle2 = document.createElement('style'); stStyle2.textContent = '#pdfStage th, #pdfStage td { position: static !important; transform: none !important; } #pdfStage table { zoom: 1 !important; --pz: 1; } #pdfStage, #pdfStage * { max-height: none !important; overflow: visible !important; height: auto !important; visibility: visible !important; opacity: 1 !important; animation: none !important; transition: none !important; }';
        document.head.appendChild(stStyle2); document.body.appendChild(stage);
        var totalRows = 0; items.forEach(function (it) { if (it.type === 'table') totalRows += it.rows.length; });
        var ratio = totalRows > 150 ? 1.5 : 2;
        var fontCssP = (window.htmlToImage.getFontEmbedCSS ? window.htmlToImage.getFontEmbedCSS(area) : Promise.resolve(undefined)).catch(function () { return undefined; });
        var cursor = { i: 0, row: 0 }, pageNo = 0;
        function sheetH(sheet) { return Math.max(sheet.scrollHeight, Math.ceil(sheet.getBoundingClientRect().height)); }
        // يبني الورقة التالية على المسرح ويرجعها، أو null عند النهاية
        function nextPage() {
            if (cursor.i >= items.length) return null;
            var sheet = area.cloneNode(false); sheet.style.width = areaW + 'px'; sheet.style.maxWidth = 'none'; sheet.style.margin = '0';
            while (stage.firstChild) stage.removeChild(stage.firstChild);
            stage.appendChild(sheet);
            var lastChain = [], lastShells = [];
            function shellFor(chain) {
                var parent = sheet, i;
                for (i = 0; i < chain.length; i++) {
                    if (lastChain[i] === chain[i] && lastShells[i]) { parent = lastShells[i]; continue; }
                    var sh = chain[i].cloneNode(false); sh.style.maxHeight = 'none'; sh.style.overflow = 'visible'; sh.style.height = 'auto';
                    parent.appendChild(sh); lastShells[i] = sh; lastChain[i] = chain[i]; parent = sh;
                    lastChain.length = i + 1; lastShells.length = i + 1;
                }
                if (chain.length === 0) { lastChain = []; lastShells = []; }
                return parent;
            }
            var hasContent = false;
            // الترويسة المعادة (بعد الأولى)
            if (pageNo > 0) headItems.forEach(function (it) { shellFor(it.chain || []).appendChild(it.node.cloneNode(true)); });
            while (cursor.i < items.length) {
                var it = items[cursor.i];
                if (it.type === 'block') {
                    var host = shellFor(it.chain || []), c = it.node.cloneNode(true);
                    host.appendChild(c);
                    if (sheetH(sheet) > pageCss && hasContent) { host.removeChild(c); break; }
                    hasContent = true; cursor.i++; continue;
                }
                // جدول: هيكل + رأس ثم صفوف حتى تمتلئ الورقة
                var host2 = shellFor(it.chain || []), t = it.node.cloneNode(false); t.style.width = '100%';
                if (it.headRows.length) { var th = document.createElement('thead'); it.headRows.forEach(function (r) { th.appendChild(r.cloneNode(true)); }); t.appendChild(th); }
                var tb = document.createElement('tbody'), tf = null; t.appendChild(tb);
                host2.appendChild(t);
                if (sheetH(sheet) > pageCss && hasContent) { host2.removeChild(t); break; }   // حتى الرأس لا يسع: ورقة جديدة
                var put = 0;
                while (cursor.row < it.rows.length) {
                    var row = it.rows[cursor.row], rc = row.tr.cloneNode(true);
                    if (row.foot) { if (!tf) { tf = document.createElement('tfoot'); t.appendChild(tf); } tf.appendChild(rc); } else tb.appendChild(rc);
                    if (sheetH(sheet) > pageCss && (put > 0 || hasContent)) { rc.parentNode.removeChild(rc); if (tf && !tf.rows.length) { t.removeChild(tf); tf = null; } break; }
                    put++; cursor.row++;
                }
                hasContent = true;
                if (cursor.row >= it.rows.length) { cursor.i++; cursor.row = 0; if (sheetH(sheet) > pageCss * 0.9) break; continue; }
                break;                                      // الورقة امتلأت وبقي من الجدول صفوف
            }
            pageNo++;
            return sheet;
        }
        var doc = new window.jspdf.jsPDF({ orientation: landscape ? 'l' : 'p', unit: 'mm', format: 'a4' });
        step('generic start areaW=' + areaW + ' items=' + items.length + ' rows=' + totalRows);
        function loop() {
            var sheet = nextPage();
            if (!sheet) return Promise.resolve();
            if (pageNo > 400) return Promise.resolve();        // صمام
            cur.set('⏳ عم نجهّز الـPDF... صفحة ' + pageNo);
            return fontCssP.then(function (fc) {
                var o = { pixelRatio: ratio, backgroundColor: '#ffffff', width: areaW, height: Math.max(1, sheetH(sheet)) };
                if (fc !== undefined) o.fontEmbedCSS = fc;
                return window.htmlToImage.toCanvas(sheet, o);
            }).then(function (cv) {
                if (pageNo > 1) doc.addPage('a4', landscape ? 'l' : 'p');
                var hmm = cv.height / ratio * scale;             // بلا عصر: الورقة الأطول قليلاً تُقصّ من أسفلها لا تُشوَّه
                doc.addImage(cv.toDataURL('image/jpeg', totalRows > 100 ? 0.8 : 0.9), 'JPEG', M, M, boxW, hmm);
                return loop();
            });
        }
        var chain = loop();
        function undo2() { try { if (stage.parentNode) stage.parentNode.removeChild(stage); if (stStyle2.parentNode) stStyle2.parentNode.removeChild(stStyle2); } catch (e2) {} undo(); }
        // (توافق: اختيار القطع القديم على حدود الصفوف — cuts[k] > y + Math.floor(want * 0.45) — صار بالتوزيع أعلاه)
        return chain.then(function () {
            step('generic done pages=' + pageNo); undo2(); return doc;
        }).catch(function (e) { undo2(); throw e; });
    }
    // يبني مستند الـPDF لأي صفحة (قسائم/بطاقات أو تقرير عام) — للحفظ وللإرسال (واتساب/إيميل)
    function buildAnyPdf() {
        var cards = document.querySelectorAll('.salary-slip');
        var landscape = cards.length > 0;
        if (!cards.length) cards = document.querySelectorAll('.payslip-card');
        if (cards.length > 40) return Promise.reject(new Error('too many cards'));
        if (cards.length) return loadLibs().then(function () { step('buildPdf'); return buildPdf(cards, landscape); }).then(function (doc) { return { doc: doc, name: fileName() }; });
        return loadLibs().then(function () { return buildGenericPdf(genericArea()); }).then(function (doc) { return { doc: doc, name: genericName() }; });
    }
    window.msaPdfBlob = function () {
        return buildAnyPdf().then(function (r) { return { blob: r.doc.output('blob'), name: r.name }; });
    };

    window.msaSavePdfStart = function (btn) {
        var cardsN = document.querySelectorAll('.salary-slip, .payslip-card').length;
        // مجموعة ضخمة (طباعة الكل لمئات الأساتذة): التوليد الفوري يستغرق دقائق طويلة
        // ويستهلك ذاكرة هائلة — حوار طباعة المتصفح أنسب لها (سريع ومضبوط)
        if (cardsN > 40) { window.print(); return; }
        var old = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.textContent = '⏳ عم نجهّز الملف...'; }
        buildAnyPdf()
            .then(function (r) { r.doc.save(r.name); step('saved ' + r.name); if (btn) { btn.textContent = '✅ نزل الملف عندك'; setTimeout(function () { btn.innerHTML = old; btn.disabled = false; }, 4000); } })
            .catch(function (e) {
                step('CATCH: ' + (e && (e.message || e))); if (btn) { btn.innerHTML = old; btn.disabled = false; }
                try { console.error(e); } catch (_) {}
                window.print(); // أي فشل → حوار المتصفح كما كان (لا يُترك المستخدم بلا شيء)
            });
    };
    function buildPdf(cards, landscape) {
        var cur = curtain('⏳ عم نجهّز ملف الـPDF... لحظة');
        try { if (window.msaFitPrintZoom) window.msaFitPrintZoom(); if (window.msaFitDocTables) window.msaFitDocTables(); } catch (eFit) {} var restore = flipPrintRules();
        var designW = landscape ? 1085 : 745;                 // عرض ورقة A4 داخل هوامش 4mm
        var ratio = cards.length > 10 ? 1.6 : 2.5;            // دقة أعلى للفردي، أخف للجماعي
        var prev = [];
        for (var i = 0; i < cards.length; i++) {
            prev.push([cards[i].style.width, cards[i].style.getPropertyValue('--pz')]);
            cards[i].style.width = designW + 'px';
            cards[i].style.setProperty('--pz', 1);            // التصوير بالحجم الكامل — الملاءمة تتم بالـPDF
            // الجدول قد يفيض عن عرض التصميم قليلاً (أرقام لا تلتف) → وسّع إطار التصوير
            // على قدّ المحتوى الفعلي حتى لا يُقصّ عمود؛ الملاءمة النهائية يتولاها الـPDF
            var sw = cards[i].scrollWidth;
            if (sw > designW + 2) cards[i].style.width = sw + 'px';
        }
        step('new jsPDF'); var doc = new window.jspdf.jsPDF({ orientation: landscape ? 'l' : 'p', unit: 'mm', format: 'a4' });
        var pageW = landscape ? 297 : 210, pageH = landscape ? 210 : 297, M = 4;
        var boxW = pageW - 2 * M, boxH = pageH - 2 * M;
        var chain = Promise.resolve();
        Array.prototype.forEach.call(cards, function (c, idx) {
            chain = chain.then(function () {
                step('toJpeg ' + idx); if (cards.length > 1) cur.set('⏳ عم نجهّز الـPDF... صفحة ' + (idx + 1) + ' من ' + cards.length); return window.htmlToImage.toJpeg(c, { pixelRatio: ratio, backgroundColor: '#ffffff', quality: 0.95 });
            }).then(function (url) {
                // أبعاد الصورة من jsPDF مباشرة (متزامن — لا انتظار تحميل Image)
                step('props ' + idx + ' len=' + url.length); var p = doc.getImageProperties(url);
                if (idx > 0) doc.addPage('a4', landscape ? 'l' : 'p');
                var w = boxW, h = w * p.height / p.width;
                if (h > boxH) { h = boxH; w = h * p.width / p.height; }
                step('addImage ' + idx); doc.addImage(url, 'JPEG', M + (boxW - w) / 2, M + (boxH - h) / 2, w, h); step('added ' + idx);
            });
        });
        return chain.then(function () {
            for (var i = 0; i < cards.length; i++) {
                cards[i].style.width = prev[i][0];
                cards[i].style.setProperty('--pz', prev[i][1] || '');
            }
            restore(); cur.done(); step('built');
            return doc;
        }).catch(function (e) {
            for (var i = 0; i < cards.length; i++) {
                cards[i].style.width = prev[i][0];
                cards[i].style.setProperty('--pz', prev[i][1] || '');
            }
            restore(); cur.done();
            throw e;
        });
    }
})();
