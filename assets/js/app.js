/**
 * رواتب المدارس / SALAIRES DES ÉCOLES - Main JS
 */

// Format LBP numbers
function formatLBP(n) {
    return new Intl.NumberFormat('en-US').format(Math.round(n)) + ' L.L';
}

function formatUSD(n) {
    // قاعدة «بدون فراطات — داون»: الدولار المعروض رقم صحيح بالتدوير لتحت (580.39 ⇒ 580)
    return '$' + new Intl.NumberFormat('en-US').format(Math.floor(n));
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

// 🖥️ «في تقارير وقت بدي شوفها على شاشة الكمبيوتر ما بتطلع كلها قبل الطبع» (2026-09-15):
// الجدول الأعرض من حاويته كان يُقصّ طرفه على الشاشة، وشريط التمرير الأفقي مدفون بآخر
// الحاوية (بعد آلاف البكسلات بالكشوف الطويلة) فلا يراه أحد. الحلّ على كل البرنامج: الجدول
// الأعرض من شاشته يصغّر نفسه (zoom = عرض الحاوية ÷ عرضه الطبيعي، حدّ أدنى 0.5) فتظهر
// كل أعمدته دفعة واحدة — كما على الورق. الطباعة وPDF لهما تصغيرهما المحسوب (--pz) الذي
// يغلب هذا التصغير (!important بقواعد @media print)، ووورد/إكسل يمسحانه (cleanHtml).
// 🔒 البطاقة السنوية (.salary-slip) والقسيمة الشهرية والإفادات مستثناة تماماً — لا تُلمَس.
window.msaFitScreenTables = function () {
    var tables = document.querySelectorAll('table.table, table.doc-table, table.xlsf');
    for (var i = 0; i < tables.length; i++) {
        var t = tables[i], host = t.parentElement;
        if (!host) continue;
        if (t.closest('.salary-slip, .payslip-card, [data-fit1], .no-print, .ba-overlay, .modal, [role="dialog"]')) continue;
        t.style.zoom = '';                                   // القياس بالحجم الطبيعي (خط 12)
        var hs = getComputedStyle(host);   // عرض المحتوى الحقيقي للحاوية (بلا حشوتها) — وإلا فاض الطرف بقدر الحشوة
        var avail = host.clientWidth - (parseFloat(hs.paddingLeft) || 0) - (parseFloat(hs.paddingRight) || 0);
        var natW = t.scrollWidth;
        if (!natW || avail <= 0) continue;
        // تسامح 4px (حدود الجدول): الجدول الذي يسع حاويته يبقى بحجمه 12 تماماً — الأعرض وحده يتصغّر
        if (natW > avail + 4) t.style.zoom = Math.max((avail - 2) / natW, 0.5).toFixed(3);
    }
};

// 📌 تثبيت رؤوس الجداول أثناء التمرير (على كل البرنامج):
// «العناوين تبقى براس الصفحة مش بنص الصفحة» (2026-08-03): الصفحة نفسها هي الأسانسور،
// والرأس يلتصق بأعلى الشاشة تحت الشريط العلوي (لا صناديق تمرير داخلية إلا للجدول
// الأعرض من شاشته — فيبقى بصندوقه الأفقي ورأسه لاصقاً بأعلى الصندوق).
// يدعم الرؤوس المركّبة من صفّين (rowspan/colspan): كل صف يلتصق تحت الذي قبله.
(function () {
    var xTables = [];   // الجداول الأعرض من شاشتها: رأسها يُثبَّت يدوياً مع تمرير الصفحة
    var topOffG = 0;
    function initStickyHeads() {
        // إرجاع ما عدّلناه بجولة سابقة (تغيّر المقاس قد يقلب الحالة)
        var prev = document.querySelectorAll('[data-stkvis]');
        for (var r0 = 0; r0 < prev.length; r0++) { prev[r0].style.overflow = ''; prev[r0].style.overflowX = ''; prev[r0].removeAttribute('data-stkvis'); }
        xTables = [];
        window.msaFitScreenTables();   // 🖥️ الجدول الأعرض من شاشته يصغّر نفسه أوّلاً (ثم الرأس يُحسب على تصغيره)
        // ارتفاع الشريط العلوي الملتصق — الرأس يلتصق تحته لا خلفه
        topOffG = 0;
        var tb = document.querySelector('.topbar');
        if (tb) { var tbs = getComputedStyle(tb); if (tbs.position === 'sticky' && tbs.display !== 'none') topOffG = Math.ceil(tb.getBoundingClientRect().height); }
        // 📌 (2026-10-01) شريط تبويبات ملف الموظف ثابت تحت الشريط العلوي ⇒ رؤوس الجداول تلتصق تحته لا خلفه
        try { document.documentElement.style.setProperty('--msa-topbar-h', topOffG + 'px'); var tabsEl = document.querySelector('.main-content .tabs'); if (tabsEl && tabsEl.offsetParent && getComputedStyle(tabsEl).position === 'sticky') topOffG += Math.ceil(tabsEl.getBoundingClientRect().height); } catch (e) {}
        var tables = document.querySelectorAll('table.table, table.doc-table, table.salary-slip-table');
        for (var k = 0; k < tables.length; k++) {
            var t = tables[k];
            if (!t.tHead || t.tHead.rows.length === 0) continue;
            // الجداول داخل النوافذ المنبثقة (مودال) لا تُثبَّت رؤوسها: الرأس اللاصق كان يغطي خانات
            // الإدخال بنافذة «بند جديد» فتبدو النافذة فاضية (قصة صفحة المكافآت 2026-08-29)
            if (t.closest('.ba-overlay, .modal, [role="dialog"]')) continue;
            // فتح كل حاويات الجداول المقصوصة/المتمرّرة على السلسلة: الصفحة نفسها هي
            // الأسانسور الوحيد عمودياً — لا صناديق تمرير داخلية بعد اليوم
            var p = t.parentElement;
            while (p && p !== document.body) {
                var st = getComputedStyle(p);
                if ((st.overflow !== 'visible' || st.overflowX !== 'visible' || st.overflowY !== 'visible')
                    && /(^|\s)(card|card-body|report-table-wrap|table-wrapper|tbl-scroll|official-doc|doc-sheet|xls-sheet|mof-form)(\s|$)/.test(p.className || '')) {
                    p.classList.remove('tbl-scroll');
                    p.style.overflow = 'visible';
                    p.setAttribute('data-stkvis', '1');
                }
                p = p.parentElement;
            }
            var z = parseFloat(getComputedStyle(t).zoom) || 1;
            var host = t.parentElement;
            var needX = t.scrollWidth > host.clientWidth + 2;   // أعرض من حاويته = بدو أسانسور أفقي
            var top = 0;
            if (needX) {
                // أسانسور أفقي فقط على الحاوية (بلا حبس عمودي)، والرأس يُثبَّت يدوياً
                // بالتمرير (translateY) لأن sticky لا يخترق حاوية متمرّرة
                host.style.overflowX = 'auto';
                host.setAttribute('data-stkvis', '1');
                xTables.push({ t: t, z: z });
            } else {
                // الرأس يلتصق بأعلى الشاشة تحت الشريط (sticky عادي — الإحداثيات داخل
                // الجدول المصغَّر بالـzoom مقسومة على تصغيره)
                top = Math.ceil(topOffG / z);
            }
            // صفوف الرأس المتعدّدة: top تراكمي حتى لا يغطي الصف الأول الثاني
            var rows = t.tHead.rows;
            for (var i = 0; i < rows.length; i++) {
                for (var j = 0; j < rows[i].cells.length; j++) {
                    rows[i].cells[j].style.top = top + 'px';
                }
                top += rows[i].offsetHeight;
            }
        }
        stickXHeads();
    }
    // التثبيت اليدوي للجداول العريضة: خلايا الرأس (وهي sticky = فوق الجسم بالتراصف)
    // تنزاح translateY بمقدار ما غاص الجدول فوق رأس الشاشة، وتتوقف قرب نهايته
    function stickXHeads() {
        for (var k = 0; k < xTables.length; k++) {
            var t = xTables[k].t, z = xTables[k].z;
            var r = t.getBoundingClientRect();
            var hH = t.tHead.getBoundingClientRect().height;
            var dy = topOffG - r.top;
            if (dy > 0) dy = Math.min(dy, r.height - hH * 1.5);
            var tf = dy > 0 ? 'translateY(' + Math.round(dy / z) + 'px)' : '';
            var rows = t.tHead.rows;
            for (var i = 0; i < rows.length; i++) {
                for (var j = 0; j < rows[i].cells.length; j++) rows[i].cells[j].style.transform = tf;
            }
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initStickyHeads);
    else initStickyHeads();
    window.addEventListener('load', initStickyHeads);   // بعد الخطوط/الصور وملاءمة fitDocTables
    window.addEventListener('resize', initStickyHeads);
    window.addEventListener('scroll', stickXHeads, { passive: true });
})();

// 🔠 طباعة بخط 12 (12pt متل الوورد) بلا قصّ: الجدول/القسيمة الأعرض من ورقتها تصغّر نفسها
// محسوباً (--pz = مقاس الورقة ÷ المقاس الطبيعي) — نفس نظام doc-table في report_helpers.
// تُستثنى doc-table (لها نظامها) والجداول داخل القسائم (تصغير القسيمة كلّها يكفي — لا دوبل).
(function () {
    function fitPrintZoom() {
        // (١) الجداول العادية (.table) خارج القسائم و doc-table
        var tables = document.querySelectorAll('table.table');
        // 🖨️ «ما بتطلع كلها قبل الطبع» (2026-09-15): القياس القديم كان بتنسيق الشاشة (pz-measure يكبّر
        // خط الخلايا وحده) بينما الورق يكبّر أيضاً الشارات (badge) والعريض والروابط إلى 12pt — فكان
        // العرض المقيس أقلّ من الحقيقي (~1040 بدل ~1160 بجدول الرواتب الشهرية) ويُقصّ طرف الجدول
        // (عمود الحالة) على الورق. الآن: قلب قواعد @media print إلى الشاشة لحظياً (خطوة متزامنة لا
        // تُرى — نفس أسلوب البطاقة السنوية ٢-أ) فالقياس = تدفّق الطباعة الحقيقي نفسه.
        var flipped1 = [];
        if (tables.length) {
            try {
                for (var s1 = 0; s1 < document.styleSheets.length; s1++) {
                    var rules1; try { rules1 = document.styleSheets[s1].cssRules; } catch (e1) { continue; }
                    if (!rules1) continue;
                    for (var r1 = 0; r1 < rules1.length; r1++) {
                        var rule1 = rules1[r1];
                        if (rule1.media && /print/.test(rule1.media.mediaText)) { flipped1.push([rule1, rule1.media.mediaText]); rule1.media.mediaText = 'all'; }
                    }
                }
            } catch (e0) {}
        }
        try {
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i];
            if (t.classList.contains('doc-table') || t.closest('.payslip-card, .salary-slip, .no-print')) continue;
            var target = t.closest('.land-report') ? 1062 : 718;   // عرض A4 الفعلي داخل هوامش @page (كان 1075/745 أعرض من الورقة فيُقصّ الطرف — 2026-08-20)
            // 🖨️ الجدول داخل بطاقة (card-body) لا يأخذ عرض الورقة كله: حشوة البطاقة وحدودها تُخصم
            // (تُقاس بالقواعد المقلوبة = كما على الورق) — جدول الرواتب الشهرية كان يُقصّ عمود الحالة
            var host1 = t.parentElement, hs1 = host1 ? getComputedStyle(host1) : null;
            if (hs1) {
                var inner1 = host1.clientWidth - (parseFloat(hs1.paddingLeft) || 0) - (parseFloat(hs1.paddingRight) || 0);
                var over1 = document.documentElement.clientWidth - inner1;
                if (over1 > 0 && over1 < target / 2) target -= over1;
            }
            // شروط الطباعة الحقيقية: خط 12 + عرض الورقة + لفّ الرؤوس + إخفاء أعمدة الأزرار —
            // فلا يُصغَّر إلا الجدول الذي لا تسعه الورقة فعلاً (أرقامه لا تلتفّ)
            t.classList.add('pz-measure');
            var prevW = t.style.width, prevZ = t.style.zoom;
            t.style.zoom = '';                                    // تصغير الشاشة (msaFitScreenTables) لا يدخل بالقياس
            t.style.setProperty('--pz', 1);                       // ولا تصغير الورق السابق (القواعد مقلوبة الآن)
            t.style.setProperty('width', target + 'px', 'important');
            var natW = Math.max(t.scrollWidth, Math.ceil(t.getBoundingClientRect().width), 1);
            t.style.width = prevW; t.style.zoom = prevZ;
            t.classList.remove('pz-measure');
            // ×0.96 هامش أمان: قياس المحاكاة يختلف عن تدفّق الطباعة الحقيقي قليلاً (جردة 2026-08-20 كان 0.98؛
            // 2026-09-15: عمود الحالة بجدول الرواتب الشهرية كان يُقصّ بضعة بكسلات — الجداول داخل البطاقات تحتاج هامشاً أوسع)
            var pz = (target * 0.96) / natW;
            // 🔠 «حجم الخط 12 بكل شي» (2026-08-01): لا تكبير فوق خط 12 — الجدول الأصغر من
            // الورقة يملؤها بتوسيع أعمدته وخطه 12 تماماً؛ الأعرض وحده يتصغّر حتى لا يُقصّ
            t.style.setProperty('--pz', pz < 1 ? Math.max(pz, 0.4).toFixed(3) : 1);
        }
        } finally {
            for (var f1 = 0; f1 < flipped1.length; f1++) flipped1[f1][0].media.mediaText = flipped1[f1][1];
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
                // (2026-08-21) هوامش @page صارت صفراً بكل الإفادات (المسافات من جوّا الإفادة
                // نفسها) → هدف القياس مقاس A4 كاملاً بالحالتين، بأمان بسيط لفروق تدفّق الطباعة
                var tw3 = 794, th3 = lh3 ? 1115 : 1100;
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

// 🏷️ عنوان التقرير على كل ورقة مطبوعة (طلب المستخدم 2026-08-04):
// يُحقن صفّ عنوان داخل thead أول جدول doc-table بكل تقرير/نموذج، فيتكرّر مع رأس
// الجدول أعلى كل صفحة بالطباعة (thead = table-header-group). مخفيّ على الشاشة.
// setTimeout(0): يعمل بعد سكربتات الصفحة (مثل حقن «الفلتر: …» بالنماذج) فيلتقطها بالعنوان.
(function () {
    function injectPrintTitles() {
        document.querySelectorAll('.doc-sheet, .official-doc').forEach(function (root) {
            var t = root.querySelector('.doc-head .dh-ar, .doc-title');
            if (!t) return;
            var txt = (t.textContent || '').trim();
            var fr = root.querySelector('.doc-head .dh-fr');
            if (fr && fr.textContent.trim()) txt += ' — ' + fr.textContent.trim();
            // أول chip (الفترة + الفلتر) بالورقة الموحّدة، أو السطر الفرعي بالنماذج الرسمية
            var sub = root.querySelector('.dh-meta .dh-chip, .doc-subtitle');
            if (sub && sub.textContent.trim()) txt += ' — ' + sub.textContent.trim();
            if (!txt) return;
            var table = root.querySelector('table.doc-table');
            if (!table || table.querySelector('.pr-title-row')) return;
            var thead = table.tHead || table.createTHead();
            var row = thead.insertRow(0);
            row.className = 'pr-title-row';
            var th = document.createElement('th');
            // 🔴 colSpan بعدد الأعمدة الحقيقي حصراً — colspan=99 (أكبر من الأعمدة) كان يخرّب
            // تقطيع الجدول بالطباعة فيولّد ورقة أخيرة بيضاء بالتقارير الطويلة (جردة 2026-08-20)
            var ref = table.querySelector('thead tr:not(.pr-title-row)') || table.querySelector('tbody tr');
            var nCols = 0;
            if (ref) for (var ci = 0; ci < ref.children.length; ci++) nCols += (ref.children[ci].colSpan || 1);
            th.colSpan = Math.max(1, nCols);
            // العنوان بdiv داخلية (width:0/min-width:100%): سطر واحد دائماً بقصّ أنيق،
            // بلا ما يلتفّ (يطوّل الرأس المكرر ويخرّب التقطيع) وبلا ما يمدّد أعمدة الجدول
            var tt = document.createElement('div');
            tt.className = 'pr-title-text';
            tt.textContent = txt;
            th.appendChild(tt);
            row.appendChild(th);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(injectPrintTitles, 0); });
    } else {
        setTimeout(injectPrintTitles, 0);
    }
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

// 📌 «بعد الحفظ بضلّ بنفس المحل» (طلب المستخدم 2026-08-29): أي حفظ/إجراء يرجّع للصفحة نفسها بنفس
// موضع التمرير — لا ينطّ لفوق ولا يضيّع السطر اللي كان يعدّله. عام لكل البرنامج: عند إرسال أي فورم
// (أو كبس رابط إجراء على نفس الصفحة) نخزّن موضع التمرير، وبعد إعادة التحميل نرجّعه (خلال دقيقة).
(function () {
    var KEY = 'ppStay:' + location.pathname;
    function remember() {
        try { sessionStorage.setItem(KEY, JSON.stringify({ y: window.pageYOffset || document.documentElement.scrollTop || 0, t: Date.now() })); } catch (e) {}
    }
    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        if (!f || f.tagName !== 'FORM') return;
        if ((f.getAttribute('target') || '') === '_blank') return;
        remember();
    }, true);
    document.addEventListener('click', function (ev) {
        var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
        var href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
        try {
            var u = new URL(a.href, location.href);
            // رابط إجراء على نفس الصفحة (promote/rebuild/حذف...) = يرجع لنفس الصفحة → خزّن الموضع
            if (u.pathname === location.pathname && u.search && u.search !== location.search) remember();
        } catch (e) {}
    }, true);
    function restore() {
        var raw = null;
        try { raw = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY); } catch (e) {}
        if (!raw) return;
        var st = null;
        try { st = JSON.parse(raw); } catch (e) { return; }
        if (!st || typeof st.y !== 'number' || (Date.now() - (st.t || 0)) > 60000) return;
        if (st.y < 1) return;
        var go = function () { window.scrollTo(0, st.y); };
        go();
        requestAnimationFrame(go);
        setTimeout(go, 60);
        window.addEventListener('load', function () { setTimeout(go, 0); }, { once: true });
    }
    if ('scrollRestoration' in history) { try { history.scrollRestoration = 'manual'; } catch (e) {} }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restore); else restore();
})();

// ☑️⏳ msaSubmitSoon (2026-09-26 «مشيّك على الموظفين ولسا مبيّن الملاك»): خانات الفئة/المدارس كانت ترسل الفورم فوراً
// عند كل كبسة، فلمّا يشيل فئة ويحطّ أخرى بسرعة تضيع الكبسة الثانية والسيرفر البطيء يعرض صفحة قديمة.
// الحلّ: تأخير قصير يجمع الكبسات المتتالية بإرسال واحد، مع طبقة «جارٍ التحديث» حتى تصل الصفحة الجديدة.
window.msaSubmitSoon = function (form, ms) {
    if (!form) return;
    if (form._msaTimer) clearTimeout(form._msaTimer);
    form._msaTimer = setTimeout(function () {
        form._msaTimer = null;
        var ov = document.getElementById('msaBusyOverlay');
        if (!ov) {
            ov = document.createElement('div'); ov.id = 'msaBusyOverlay';
            ov.setAttribute('style', 'position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;font:700 18px Arial,sans-serif;color:#1F4E5F;direction:rtl');
            ov.innerHTML = '<div style="background:#fff;border:2px solid #1F4E5F;border-radius:12px;padding:16px 28px;box-shadow:0 8px 30px rgba(0,0,0,.15)">⏳ جارٍ التحديث… / Mise à jour…</div>';
            document.body.appendChild(ov);
        }
        ov.style.display = 'flex';
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    }, ms == null ? 700 : ms);
};
window.addEventListener('pageshow', function () { var ov = document.getElementById('msaBusyOverlay'); if (ov) ov.style.display = 'none'; });

// ☑️🔁 msaSyncForms (2026-09-26 «مشيّك على الموظفين والجدول ملاك»): كروم يرجّع حالة الخانات كما كانت (رجوع/تحديث/bfcache)
// بينما الجدول محسوب على اختيار آخر. كل مجموعة خانات تحمل علامة <span data-msa-sync-name data-msa-sync-values> بالقيم
// التي حُسبت عليها الصفحة فعلاً؛ عند كل عرض نقارن — اختلاف ⇒ إعادة تحميل واحدة (حارس 8 ثوانٍ ضدّ التكرار).
window.msaSyncForms = function () {
    var marks = document.querySelectorAll('span[data-msa-sync-name]');
    if (!marks.length) return false;
    var bad = null;
    marks.forEach(function (m) {
        var form = m.closest('form'); if (!form || bad) return;
        var name = m.getAttribute('data-msa-sync-name');
        var boxes = form.querySelectorAll('input[type=checkbox][name="' + name + '"]');
        if (!boxes.length) return; // حقول مخفية (غير المدير العام) — لا شيء يقارَن
        var cur = [].slice.call(boxes).filter(function (c) { return c.checked; }).map(function (c) { return c.value; }).sort().join(',');
        var exp = (m.getAttribute('data-msa-sync-values') || '').split(',').filter(Boolean).sort().join(',');
        if (cur !== exp) bad = form;
    });
    if (!bad) return false;
    var key = 'msaSync:' + location.pathname, now = Date.now();
    try { var last = parseInt(sessionStorage.getItem(key) || '0', 10); if (now - last < 8000) return false; sessionStorage.setItem(key, String(now)); } catch (e) {}
    window.msaSubmitSoon(bad, 0);
    return true;
};
window.addEventListener('pageshow', function () { setTimeout(window.msaSyncForms, 50); });

// 🔙 «بدي دايماً أي صفحة بكون فيها كبسة ترجعني للصفحة اللي كنت قبلها» (طلبه 2026-09-29): زرّ «رجوع» بكل صفحة
// يرجّع للصفحة **الحقيقية** السابقة عبر مكدّس صفحات مميّزة بالجلسة — يتجاهل تبديل الخيارات وإعادة تحميل نفس
// الصفحة (نفس التوقيع)، فلا يعلق «رجوع» على حالة سابقة كما كان history.back(). عامّ لكل البرنامج، بلا خادم.
(function () {
    var NK = 'msa_nav', MAX = 30;
    // بارامترات تحدّد «هوية الصفحة» (النوع/التقرير/الموظف…) — تبديل خيار شكلي لا يغيّرها فيُعدّ نفس الصفحة
    var IDP = ['report', 'form', 'type', 'dossier', 'action', 'employee_id', 'emp', 'eid', 'id', 'edit', 'q', 'sy', 'school_year', 'month', 'year', 'tab', 'report_type'];
    function here() { return location.pathname + location.search; }
    function sig() {
        try {
            var u = new URL(location.href), parts = [u.pathname];
            IDP.forEach(function (k) { if (u.searchParams.has(k)) parts.push(k + '=' + u.searchParams.get(k)); });
            return parts.join('|');
        } catch (e) { return location.pathname; }
    }
    function readNav() { try { return JSON.parse(sessionStorage.getItem(NK)) || []; } catch (e) { return []; } }
    function writeNav(a) { try { sessionStorage.setItem(NK, JSON.stringify(a.slice(-MAX))); } catch (e) {} }
    function curY() { return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0; }
    function restoreY(y) { y = parseInt(y, 10) || 0; if (!y) return; window.scrollTo(0, y); setTimeout(function () { window.scrollTo(0, y); }, 60); setTimeout(function () { window.scrollTo(0, y); }, 250); setTimeout(function () { window.scrollTo(0, y); }, 600); }
    function record() {
        var a = readNav(), s = sig(), e = { u: here(), n: (document.title || '').replace(/\s+/g, ' ').trim(), s: s, y: 0 };
        if (a.length && a[a.length - 1].s === s) { e.y = a[a.length - 1].y || 0; a[a.length - 1] = e; }   // نفس الصفحة (تبديل خيار/حفظ) ⇒ استبدال مع إبقاء موضعها
        else if (a.length >= 2 && a[a.length - 2].s === s) { a.pop(); restoreY(a[a.length - 1].y); }        // 🎯 رجعنا للسابقة ⇒ اقفز عنها وارجع لنفس محلّ التمرير («محل ما كنت»)
        else a.push(e);                                                                                     // صفحة جديدة ⇒ أضِف
        writeNav(a);
    }
    // احفظ موضع التمرير الحالي بمدخل هذه الصفحة عند مغادرتها، حتى نرجع لنفس المحل لمّا نضغط «رجوع» إليها لاحقاً
    function saveY() { var a = readNav(); if (a.length && a[a.length - 1].s === sig()) { a[a.length - 1].y = curY(); writeNav(a); } }
    window.addEventListener('pagehide', saveY);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') saveY(); });
    // عند أي كبسة على رابط/زرّ داخل الصفحة نحدّث الموضع أيضاً (يغطّي الانتقالات التي لا تُطلق pagehead بموثوقية)
    document.addEventListener('click', function (ev) { var t = ev.target && ev.target.closest ? ev.target.closest('a[href],button') : null; if (t) saveY(); }, true);
    window.msaPrevPage = function () {
        var a = readNav(), cur = sig();
        saveY();
        for (var i = a.length - 2; i >= 0; i--) if (a[i].s !== cur) { location.href = a[i].u; return true; }
        return false; // لا صفحة سابقة ⇒ يترك المستدعي يقرّر البديل (docBackUrl / اللوحة)
    };
    window.msaHasPrev = function () { var a = readNav(), cur = sig(); for (var i = a.length - 2; i >= 0; i--) if (a[i].s !== cur) return true; return false; };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', record); else record();
})();

// ↩️ «بدي إذا كنت بشي صفحة وأرجع للي قبلها يكون في كمان سهم حتى أرجع محل ما كنت» (طلبه 2026-09-27):
// عند كبس «رجوع» نتذكّر الصفحة اللي تركها (الرابط + موضع التمرير + عنوانها) بالجلسة 30 دقيقة، وبالصفحة السابقة يظهر
// زرّ «Revenir / لمحل ما كنت» (#msaFwdBtn بالهيدر) يعيده لذاك الرابط ويرجّع التمرير لنفس السطر. عام لكل البرنامج.
(function () {
    var K = 'msa_fwd', KR = 'msa_fwd_restore', TTL = 30 * 60 * 1000;
    function here() { return location.pathname + location.search; }
    window.msaRememberFwd = function () {
        try { sessionStorage.setItem(K, JSON.stringify({ u: here(), y: window.pageYOffset || document.documentElement.scrollTop || 0, t: Date.now(), n: (document.title || '').replace(/\s+/g, ' ').trim() })); } catch (e) {}
    };
    function read() {
        var raw = null; try { raw = sessionStorage.getItem(K); } catch (e) { return null; }
        if (!raw) return null;
        var s = null; try { s = JSON.parse(raw); } catch (e) { return null; }
        if (!s || !s.u || (Date.now() - (s.t || 0)) > TTL) { try { sessionStorage.removeItem(K); } catch (e) {} return null; }
        return s;
    }
    window.msaGoFwd = function () {
        var s = read(); if (!s) return;
        try { sessionStorage.setItem(KR, JSON.stringify({ u: s.u, y: s.y || 0, t: Date.now() })); sessionStorage.removeItem(K); } catch (e) {}
        location.href = s.u;
    };
    function init() {
        // 1) رجعنا «لمحل ما كنّا» ⇒ رجّع التمرير
        try {
            var r = sessionStorage.getItem(KR);
            if (r) {
                sessionStorage.removeItem(KR);
                var st = JSON.parse(r);
                if (st && st.u === here() && (Date.now() - (st.t || 0)) < 120000) {
                    var y = parseInt(st.y, 10) || 0;
                    window.scrollTo(0, y); setTimeout(function () { window.scrollTo(0, y); }, 60); setTimeout(function () { window.scrollTo(0, y); }, 300);
                }
            }
        } catch (e) {}
        // 2) في محلّ محفوظ غير هذه الصفحة ⇒ أظهر السهم
        var s = read(); var b = document.getElementById('msaFwdBtn');
        if (!b) return;
        if (s && s.u !== here()) { b.hidden = false; if (s.n) b.title = 'Revenir / ارجع إلى: ' + s.n; }
        else b.hidden = true;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

// 📌 (2026-10-01 «بأي صفحة كنت بالبرنامج وعم انزل، لازم عناوين الصفحة يضلّوا مبيّنين كيف ما حرّكت الصفحة — بكل البرنامج»):
// عناوين الأعمدة ثابتة أصلاً (أعلاه) والشريط العلوي ثابت بعنوان الصفحة؛ الناقص كان عنوان **القسم/التقرير** الذي يختفي مع النزول
// (عنوان البطاقة «لائحة الموظفين…»، عنوان التقرير «كشف رواتب شهري — تشرين»، اسم الأستاذ بالبطاقات الجماعية). الحلّ: السطر الثاني من
// عنوان الشريط العلوي يعرض 📌 عنوان القسم الذي أنت داخله الآن، ويرجع لاسم الصفحة العربي عند الصعود — بلا تغيير ارتفاع الشريط.
(function () {
    function init() {
        var tb = document.querySelector('.topbar'), h1 = tb && tb.querySelector('h1');
        if (!h1 || getComputedStyle(tb).position !== 'sticky') return;
        var line = h1.querySelector('span[style*="display:block"]');
        if (!line) { line = document.createElement('span'); line.style.cssText = 'display:block;font-size:0.62em;font-weight:600;opacity:0.92'; line.innerHTML = '&nbsp;'; h1.appendChild(line); }
        line.classList.add('sec-now');
        var orig = line.innerHTML, shown = '';
        var SEL = '.main-content .card > .card-header h3, .main-content .doc-head, .main-content .salary-slip .slip-pname, .main-content [data-sec-title]';
        function titleOf(el) {
            if (el.hasAttribute('data-sec-title')) return el.getAttribute('data-sec-title');
            if (el.classList.contains('doc-head')) { var a = el.querySelector('.dh-fr'), b = el.querySelector('.dh-ar'), c = el.querySelector('.dh-chip'); return [a && a.innerText, b && b.innerText, c && c.innerText].filter(Boolean).join(' · '); }
            return el.innerText;
        }
        function boxOf(el) { return el.closest('.card, .doc-sheet, .salary-slip, [data-sec-box]') || el.parentElement; }
        var ticking = false;
        function update() {
            ticking = false;
            var top = tb.getBoundingClientRect().bottom, els = document.querySelectorAll(SEL), cur = null;
            for (var i = 0; i < els.length; i++) {
                var el = els[i]; if (!el.offsetParent) continue;
                var r = el.getBoundingClientRect(); if (r.bottom > top + 2) continue;      // عنوانه ما زال ظاهراً (أو تحت)
                var b = boxOf(el).getBoundingClientRect(); if (b.bottom < top + 60) continue; // خرجنا من قسمه
                cur = el;
            }
            var t = cur ? String(titleOf(cur) || '').replace(/\s*\n+\s*/g, ' · ').replace(/\s+/g, ' ').trim() : '';
            if (t === shown) return;
            shown = t;
            if (t) { line.textContent = '📌 ' + t; line.classList.add('is-sec'); line.title = t; }
            else { line.innerHTML = orig; line.classList.remove('is-sec'); line.removeAttribute('title'); }
        }
        function req() { if (!ticking) { ticking = true; (window.requestAnimationFrame || setTimeout)(update); } }
        window.addEventListener('scroll', req, { passive: true });
        window.addEventListener('resize', req);
        window.addEventListener('beforeprint', function () { line.innerHTML = orig; line.classList.remove('is-sec'); shown = ''; });
        update();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
