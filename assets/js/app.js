/**
 * رواتب المدارس / SALAIRES DES ÉCOLES - Main JS
 */

// Format LBP numbers
function formatLBP(n) {
    return new Intl.NumberFormat('en-US').format(Math.round(n)) + ' L.L';
}

function formatUSD(n) {
    return '$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

// Tab switching
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('tab')) {
        const tabGroup = e.target.closest('.tabs');
        const tabName = e.target.dataset.tab;
        if (!tabGroup || !tabName) return;

        tabGroup.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        e.target.classList.add('active');

        const container = tabGroup.parentElement;
        container.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        const target = container.querySelector(`.tab-content[data-tab-content="${tabName}"]`);
        if (target) target.classList.add('active');

        // تذكّر التبويب الحالي في الفورم ليرجع إليه بعد الحفظ
        const hidden = document.getElementById('activeTabField');
        if (hidden) hidden.value = tabName;
    }
});

// بعد الحفظ: ارجع لنفس التبويب الذي كان مفتوحاً (?tab=...)
document.addEventListener('DOMContentLoaded', function() {
    const t = new URLSearchParams(location.search).get('tab');
    if (t) {
        const btn = document.querySelector('.tab[data-tab="' + t + '"]');
        if (btn) btn.click();
    }
});

// Confirm delete
document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-confirm]');
    if (btn) {
        const msg = btn.dataset.confirm || 'Êtes-vous sûr ?';
        if (!confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }
});

// Auto-format number inputs
document.querySelectorAll('.format-number').forEach(input => {
    input.addEventListener('blur', function() {
        const val = parseFloat(this.value.replace(/[^0-9.-]/g, ''));
        if (!isNaN(val)) {
            this.value = new Intl.NumberFormat('en-US').format(val);
        }
    });
});

// Toggle visibility
document.addEventListener('change', function(e) {
    if (e.target.dataset.toggle) {
        const targetId = e.target.dataset.toggle;
        const target = document.getElementById(targetId);
        if (target) {
            target.style.display = e.target.checked ? '' : 'none';
        }
    }
});

// 📌 تثبيت رؤوس الجداول أثناء التمرير (على كل البرنامج):
// كل جدول طويل يتمرّر داخل حاويته (tbl-scroll) ورأسه يبقى ظاهراً فوق.
// يدعم الرؤوس المركّبة من صفّين (rowspan/colspan): كل صف يلتصق تحت الذي قبله.
(function () {
    function initStickyHeads() {
        var tables = document.querySelectorAll('table.table, table.doc-table, table.salary-slip-table');
        for (var k = 0; k < tables.length; k++) {
            var t = tables[k];
            if (!t.tHead || t.tHead.rows.length === 0) continue;
            // الحاوية التي سيتمرّر الجدول داخلها: أقرب أب يمرّر عمودياً،
            // وإلا (أب مقصوص overflow:hidden مثل .card) الأب المباشر للجدول
            var sc = null, p = t.parentElement;
            while (p && p !== document.body) {
                var oy = getComputedStyle(p).overflowY;
                if (oy === 'auto' || oy === 'scroll') { sc = p; break; }
                if (oy === 'hidden' || oy === 'clip') break;
                p = p.parentElement;
            }
            (sc || t.parentElement).classList.add('tbl-scroll');
            // صفوف الرأس المتعدّدة: top تراكمي حتى لا يغطي الصف الأول الثاني
            var top = 0, rows = t.tHead.rows;
            for (var i = 0; i < rows.length; i++) {
                for (var j = 0; j < rows[i].cells.length; j++) {
                    rows[i].cells[j].style.top = top + 'px';
                }
                top += rows[i].offsetHeight;
            }
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initStickyHeads);
    else initStickyHeads();
    window.addEventListener('load', initStickyHeads);   // بعد الخطوط/الصور وملاءمة fitDocTables
    window.addEventListener('resize', initStickyHeads);
})();

// 🔠 طباعة بخط 12 (12pt متل الوورد) بلا قصّ: الجدول/القسيمة الأعرض من ورقتها تصغّر نفسها
// محسوباً (--pz = مقاس الورقة ÷ المقاس الطبيعي) — نفس نظام doc-table في report_helpers.
// تُستثنى doc-table (لها نظامها) والجداول داخل القسائم (تصغير القسيمة كلّها يكفي — لا دوبل).
(function () {
    function fitPrintZoom() {
        // (١) الجداول العادية (.table) خارج القسائم و doc-table
        var tables = document.querySelectorAll('table.table');
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i];
            if (t.classList.contains('doc-table') || t.closest('.payslip-card, .salary-slip, .no-print')) continue;
            var target = t.closest('.land-report') ? 1075 : 745;   // عرض A4 المطبوع بالبكسل
            t.classList.add('pz-measure');                          // شروط الطباعة (12pt + عرض طبيعي)
            var natW = t.getBoundingClientRect().width || t.scrollWidth;
            t.classList.remove('pz-measure');
            var pz = target / natW;
            // 🔠 «حجم الخط 12 بكل شي» (2026-08-01): لا تكبير فوق خط 12 — الجدول الأصغر من
            // الورقة يملؤها بتوسيع أعمدته وخطه 12 تماماً؛ الأعرض وحده يتصغّر حتى لا يُقصّ
            t.style.setProperty('--pz', pz < 1 ? Math.max(pz, 0.4).toFixed(3) : 1);
        }
        // (٢-أ) 🔒 البطاقات السنوية تُقاس **بشروط الطباعة الحقيقية** لا بتنسيق الشاشة:
        // beforeprint يشتغل والصفحة بعدها بتنسيق الشاشة فتلتفّ الأسطر ويطلع ارتفاع
        // وهمي → تصغير خاطئ ~0.42 والورقة نصها فاضي (شكوى المستخدم 2026-08-01).
        // الحل: قلب قواعد @media print إلى الشاشة مؤقتاً (خطوة متزامنة لا تُرى)،
        // قياس البطاقة على مقاس ورقة التصميم (A4 أفقي بهوامش 4mm)، ثم تصغير محسوب
        // فقط عند الضرورة. الطباعة الجماعية: كل جولة قياس تُنفَّذ للبطاقات **كلها معاً**
        // (كتابة العروض ثم قراءة القياسات دفعة) = إعادة تخطيط واحدة للجولة لا لكل بطاقة.
        var slips = document.querySelectorAll('.salary-slip');
        if (slips.length) {
            var flipped = [];
            try {
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
                // مساحة A4 أفقية داخل هوامش 4mm — الطول بهامش أمان (710 لا 763)
                // يستوعب حواشي الصفحة فوق البطاقة وفروق تدفّق الطباعة الفعلية
                var twS = 1085, thS = 710, k;
                var zArr = [], prevWArr = [];
                for (k = 0; k < slips.length; k++) {
                    zArr.push(1); prevWArr.push(slips[k].style.width);
                    slips[k].style.setProperty('--pz', 1);
                }
                for (var it = 0; it < 5; it++) {
                    var stable = true;
                    for (k = 0; k < slips.length; k++) slips[k].style.width = Math.round(twS / zArr[k]) + 'px';
                    for (k = 0; k < slips.length; k++) {
                        var ws = slips[k].scrollWidth, hs = slips[k].scrollHeight;
                        // المطلوب بصرياً: ws×z ≤ عرض الورقة و hs×z ≤ طولها → z الجديد
                        var nz = Math.max(Math.min(1, twS / ws, thS / hs), 0.4);
                        if (Math.abs(nz - zArr[k]) >= 0.005) stable = false;
                        zArr[k] = nz;
                    }
                    if (stable) break;
                }
                for (k = 0; k < slips.length; k++) {
                    slips[k].style.width = prevWArr[k];
                    slips[k].style.setProperty('--pz', zArr[k] < 1 ? zArr[k].toFixed(3) : 1);
                }
            } finally {
                for (var fI = 0; fI < flipped.length; fI++) flipped[fI][0].media.mediaText = flipped[fI][1];
            }
        }
        // (٢-ب) القسيمة الشهرية: قياس على تنسيق الشاشة بنسبة الخط (كما كان)
        var cards = document.querySelectorAll('.payslip-card');
        for (var j = 0; j < cards.length; j++) {
            var c = cards[j];
            var land = !!c.closest('.land-report');
            // مقاس ورقة A4 (أفقي/عمودي) — القسيمة الشهرية العمودية بهامش أمان (960 لا 1030)
            // حتى تبقى صفحة واحدة **بتواقيعها** بعد فروق تدفّق الطباعة الفعلية
            var tw = land ? 1075 : 745, th = land ? 720 : 960;
            c.classList.add('pz-measure-page');                     // إخفاء ما لا يُطبع قبل القياس
            // استقرار القياس: صفّر أي تصغير سابق قبل القياس (وإلا القياس الثاني يقيس المصغَّر)
            c.style.setProperty('--pz', 1);
            // 📏 «قد ورقة A4 وواضحة» (2026-08-01): القياس على **عرض الورقة الحقيقي** لا عرض
            // الشاشة — الشاشة العريضة كانت تمدّد المحتوى فيُحسب تصغير زائد (~0.5) ويطلع
            // الخط صغيراً والورقة نصها فاضي
            var prevW = c.style.width;
            c.style.width = tw + 'px';
            var w = c.scrollWidth, h = c.scrollHeight;
            c.style.width = prevW;
            c.classList.remove('pz-measure-page');
            // الطباعة 12pt والشاشة أصغر — نقيس بنسبة الخط الفعلية حتى لا نصغّر أقلّ من اللازم
            var fs = parseFloat(getComputedStyle(c).fontSize) || 16;
            var scale = Math.max(1, 16 / fs);                       // 12pt = 16px
            // 🔠 «حجم الخط 12 بكل شي»: لا تكبير فوق خط 12 (سقف 1) — ملء طول الورقة يتمّ
            // بتوزيع الفراغ على الصفوف (flex بالبطاقة السنوية) لا بتكبير الخط؛
            // والتصغير فقط عند الضرورة (محتوى أعرض/أطول من الورقة) حتى لا يُقصّ شيء
            var pz2 = Math.min(tw / (w * scale), th / (h * scale), 1);
            c.style.setProperty('--pz', pz2 < 1 ? Math.max(pz2, 0.4).toFixed(3) : 1);
        }
        // (٢-ج) الإفادات والنماذج الرسمية (#ppExportArea): «ما عم تكون قد ورقة A4، عم تطلع
        // على صفحتين» (2026-08-03) — تُقاس بشروط الطباعة الحقيقية (قلب @media print كالبطاقة
        // السنوية، لأن 12pt الطباعة يغيّر ارتفاع العناوين عن الشاشة) والأطول من الورقة
        // يتصغّر محسوباً فيطلع صفحة A4 واحدة دائماً — ولا تكبير فوق خط 12 (سقف 1)
        // عقد التعليم (وأمثاله) متعدّد الصفحات بطبيعته — يُستثنى (بلا data-fit1) حتى لا يُضغط لصفحة
        var pp = document.getElementById('ppExportArea');
        if (pp && pp.getAttribute('data-fit1') === '1') {
            var flipped3 = [];
            try {
                for (var s3 = 0; s3 < document.styleSheets.length; s3++) {
                    var rules3; try { rules3 = document.styleSheets[s3].cssRules; } catch (e3) { continue; }
                    if (!rules3) continue;
                    for (var r3 = 0; r3 < rules3.length; r3++) {
                        var rule3 = rules3[r3];
                        if (rule3.media && /print/.test(rule3.media.mediaText)) {
                            flipped3.push([rule3, rule3.media.mediaText]);
                            rule3.media.mediaText = 'all';
                        }
                    }
                }
                pp.classList.add('pz-measure-page');
                pp.style.setProperty('--pz', 1);
                // الترويسة الرسمية صورة خلفية بمقاس الورقة كاملة (@page margin:0) → هدفها
                // مقاس A4 نفسه؛ وإلا مقاس داخل الهوامش بهامش أمان لفروق تدفّق الطباعة
                // المقاسات على هوامش @page المفروضة من الإفادة نفسها: ترويسة رسمية = هامش 0
                // (صندوق الورقة 794×1122 — أقل من A4=1122.5px بنصف بكسل حتى لا ينكسر)،
                // وإلا هامش 12mm (~45px) = 704×1033 بأمان 990
                var lh3 = pp.style.minHeight === '1122px';
                var tw3 = lh3 ? 794 : 704, th3 = lh3 ? 1115 : 990;
                var prevW3 = pp.style.width, prevMW3 = pp.style.maxWidth;
                pp.style.maxWidth = 'none'; pp.style.width = tw3 + 'px';
                var w3 = pp.scrollWidth, h3 = pp.scrollHeight;
                pp.style.width = prevW3; pp.style.maxWidth = prevMW3;
                pp.classList.remove('pz-measure-page');
                // ترويسة تملأ ورقتها تماماً (المحتوى ضمن الصندوق) = لا تصغير إطلاقاً؛
                // التصغير فقط حين يفيض المحتوى فوق مقاس الصندوق/الورقة
                var pz3 = (lh3 && h3 <= 1122) ? Math.min(tw3 / w3, 1)
                                              : Math.min(tw3 / w3, th3 / h3, 1);
                pp.style.setProperty('--pz', pz3 < 1 ? Math.max(pz3, 0.4).toFixed(3) : 1);
            } finally {
                for (var f3 = 0; f3 < flipped3.length; f3++) flipped3[f3][0].media.mediaText = flipped3[f3][1];
            }
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fitPrintZoom);
    else fitPrintZoom();
    window.addEventListener('load', fitPrintZoom);
    window.addEventListener('resize', fitPrintZoom);
    window.addEventListener('beforeprint', fitPrintZoom);
})();

// Alert function
function showAlert(msg, type = 'info') {
    const div = document.createElement('div');
    div.className = `alert alert-${type}`;
    div.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.15)';
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}
