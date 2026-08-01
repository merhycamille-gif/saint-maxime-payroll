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

    window.msaSavePdfStart = function (btn) {
        var cards = document.querySelectorAll('.salary-slip');
        var landscape = cards.length > 0;
        if (!cards.length) cards = document.querySelectorAll('.payslip-card');
        if (!cards.length) { window.print(); return; } // نموذج/تقرير عام → حوار المتصفح
        // مجموعة ضخمة (طباعة الكل لمئات الأساتذة): التوليد الفوري يستغرق دقائق طويلة
        // ويستهلك ذاكرة هائلة — حوار طباعة المتصفح أنسب لها (سريع ومضبوط)
        if (cards.length > 40) { window.print(); return; }
        var old = btn.textContent;
        btn.disabled = true; btn.textContent = '⏳ عم نجهّز الملف...';
        var vend = (window.BASE_URL || '') + 'assets/vendor/';
        Promise.resolve()
            .then(function () { step('load h2i'); return window.htmlToImage ? 0 : loadScript(vend + 'html-to-image.js'); })
            .then(function () { step('load jspdf'); return window.jspdf ? 0 : loadScript(vend + 'jspdf.umd.min.js'); })
            .then(function () { step('buildPdf'); return buildPdf(cards, landscape); })
            .then(function () { btn.textContent = '✅ نزل الملف عندك'; setTimeout(function () { btn.textContent = old; btn.disabled = false; }, 4000); })
            .catch(function (e) {
                step('CATCH: ' + (e && (e.message || e))); btn.textContent = old; btn.disabled = false;
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
            restore(); cur.done(); step('saving');
            doc.save(fileName()); step('saved');
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
