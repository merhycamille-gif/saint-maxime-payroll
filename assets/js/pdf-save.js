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
    function buildGenericPdf(area) {
        var cur = curtain('⏳ عم نجهّز ملف الـPDF... لحظة');
        var restore = flipPrintRules();
        var landscape = genericWide(area);
        var designW = landscape ? 1040 : 720;                 // عرض ورقة A4 داخل الهوامش (px)
        var prevW = area.style.width, prevMax = area.style.maxWidth, prevMg = area.style.margin;
        area.style.width = designW + 'px'; area.style.maxWidth = 'none'; area.style.margin = '0';
        // الجداول العريضة داخل حاويات تمرير (table-wrapper) كانت تُقصّ — نكشف الفيض ونوسّع الورقة على قدّ أعرض جدول (تُلاءَم بالـPDF)
        var wraps = [], need = designW;
        area.querySelectorAll('.table-wrapper, [style*="overflow"]').forEach(function (w) { wraps.push([w, w.style.overflow, w.style.overflowX]); w.style.overflow = 'visible'; w.style.overflowX = 'visible'; });
        area.querySelectorAll('table').forEach(function (t) { need = Math.max(need, t.scrollWidth + 12); });
        if (need > designW) area.style.width = need + 'px';
        var sw = area.scrollWidth; if (sw > need + 2) area.style.width = sw + 'px';
        var ratio = 2; var hPx = area.scrollHeight; if (hPx * ratio > 28000) ratio = Math.max(1, 28000 / hPx);
        function undo() { area.style.width = prevW; area.style.maxWidth = prevMax; area.style.margin = prevMg; wraps.forEach(function (x) { x[0].style.overflow = x[1]; x[0].style.overflowX = x[2]; }); restore(); cur.done(); }
        // مواضع القطع الآمنة = بدايات صفوف الجداول والفقرات (بالبكسل بعد التصوير) — الصفحة تنتهي عند حدّ صفّ لا وسطه
        var top0 = area.getBoundingClientRect().top, cuts = [];
        area.querySelectorAll('tr, .doc-head, h1, h2, h3, h4, p, .card, .alert, .table-wrapper, section').forEach(function (el) { var r = el.getBoundingClientRect(); if (r.height > 0) cuts.push(Math.round((r.top - top0) * ratio)); });
        cuts = cuts.filter(function (v, i, a) { return v > 0 && a.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
        step('toCanvas generic');
        return window.htmlToImage.toCanvas(area, { pixelRatio: ratio, backgroundColor: '#ffffff' }).then(function (canvas) {
            var doc = new window.jspdf.jsPDF({ orientation: landscape ? 'l' : 'p', unit: 'mm', format: 'a4' });
            var pageW = landscape ? 297 : 210, pageH = landscape ? 210 : 297, M = 8;
            var boxW = pageW - 2 * M, boxH = pageH - 2 * M;
            var scale = boxW / canvas.width;                   // mm لكل px
            var pagePx = Math.floor(boxH / scale);
            var ctx = canvas.getContext('2d');
            var y = 0, idx = 0;
            while (y < canvas.height) {
                var want = Math.min(pagePx, canvas.height - y);
                var h = want;
                if (y + want < canvas.height) {
                    var best = 0;
                    for (var k = 0; k < cuts.length; k++) { if (cuts[k] > y + Math.floor(want * 0.45) && cuts[k] <= y + want - 2) best = cuts[k] - y; if (cuts[k] > y + want) break; }
                    h = best > 0 ? best : safeCut(canvas, ctx, y, want);
                }
                var c = document.createElement('canvas'); c.width = canvas.width; c.height = h;
                c.getContext('2d').drawImage(canvas, 0, y, canvas.width, h, 0, 0, canvas.width, h);
                if (idx > 0) doc.addPage('a4', landscape ? 'l' : 'p');
                doc.addImage(c.toDataURL('image/jpeg', canvas.height > 9000 ? 0.8 : 0.92), 'JPEG', M, M, boxW, h * scale); // الطويل بجودة أخفّ (حجم أصغر للإيميل)
                y += h; idx++;
                if (idx > 60) break;                          // صمام: 60 صفحة كحدّ أقصى
            }
            step('generic pages=' + idx); undo(); return doc;
        }).catch(function (e) { undo(); throw e; });
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
        var restore = flipPrintRules();
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
