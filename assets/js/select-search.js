/**
 * select-search.js — تفتيش سريع بقوائم اختيار الأستاذ (بطلب المستخدم 2026-08-18):
 * «بدنا نفتش على استاذ: نكتب أول حرف من اسمو أو اسمو أو رقم الهاتف».
 *
 * يحوّل كل <select name="employee_id"> بالبرنامج لخانة تفتيش حيّة:
 *   - أول حرف/أحرف من أي كلمة بالاسم (عربي أو فرنسي) تفلتر فوراً،
 *   - أو جزء من الاسم الكامل / رقم الملف،
 *   - أو رقم الهاتف (من data-phone على الخيار إن وُجد) — الأرقام العربية ٠-٩ مقبولة.
 * القائمة الأصلية تبقى مخفية ومتزامنة، فالفورم يشتغل متل قبل تماماً (بلا أي تغيير سيرفر).
 */
(function () {
    'use strict';

    // تطبيع عربي + أرقام: للهمزات والتاء المربوطة والياء، وشيل الحركات والتطويل،
    // وتحويل الأرقام العربية ٠-٩ للاتينية — ليطابق «احمد» أحمد و«٧٠» 70.
    function norm(s) {
        return String(s || '')
            .toLowerCase()
            .replace(/[ً-ْـ]/g, '')      // حركات + تطويل
            .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه')
            .replace(/[ىئ]/g, 'ي').replace(/ؤ/g, 'و')
            .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); })
            .replace(/\s+/g, ' ').trim();
    }
    function digits(s) { return norm(s).replace(/[^0-9]/g, ''); }

    function enhance(sel) {
        if (sel.dataset.ssDone) return;
        sel.dataset.ssDone = '1';

        var opts = Array.prototype.slice.call(sel.options).map(function (o) {
            return {
                value: o.value,
                label: o.textContent.replace(/\s+/g, ' ').trim(),
                search: norm(o.textContent + ' ' + (o.getAttribute('data-search') || '')),
                phones: (o.getAttribute('data-phone') || '').split(/\s+/).map(digits).filter(Boolean),
            };
        }).filter(function (o) { return o.value !== ''; });

        // الغلاف + خانة الكتابة + لوحة النتائج
        var wrap = document.createElement('div');
        wrap.className = 'emsr-wrap';
        wrap.style.position = 'relative';
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = sel.className.replace('form-select', 'form-control') || 'form-control';
        inp.setAttribute('autocomplete', 'off');
        inp.placeholder = 'اكتب أول حرف من الاسم أو الاسم أو رقم الهاتف… / Nom ou téléphone…';
        var panel = document.createElement('div');
        panel.className = 'emsr-panel';
        panel.style.cssText = 'position:absolute;top:100%;right:0;left:0;z-index:1000;background:#fff;'
            + 'border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.14);'
            + 'max-height:280px;overflow-y:auto;display:none;margin-top:4px';

        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(inp);
        wrap.appendChild(panel);
        sel.style.display = 'none';
        wrap.appendChild(sel); // يبقى بالفورم (مخفياً) ليُرسل employee_id متل قبل

        // required على قائمة مخفية يكسر إرسال الفورم بالمتصفح — ننقله لفحصنا عند الإرسال
        var wasRequired = sel.required;
        sel.required = false;
        if (sel.form) {
            sel.form.addEventListener('submit', function (ev) {
                if (wasRequired && sel.value === '') {
                    ev.preventDefault();
                    inp.style.borderColor = '#dc2626';
                    inp.focus();
                }
            });
        }

        // إذا في خيار محدّد مسبقاً أظهر اسمه بالخانة
        if (sel.value !== '') {
            var cur = opts.filter(function (o) { return o.value === sel.value; })[0];
            if (cur) inp.value = cur.label;
        }

        var active = -1, shown = [];

        function render(list) {
            shown = list; active = -1;
            if (!list.length) {
                panel.innerHTML = '<div style="padding:10px 12px;color:#94a3b8;font-size:13px">لا نتيجة — جرّب حرفاً أقل / Aucun résultat</div>';
                panel.style.display = 'block';
                return;
            }
            panel.innerHTML = '';
            list.slice(0, 60).forEach(function (o, i) {
                var it = document.createElement('div');
                it.className = 'emsr-item';
                it.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13.5px;border-bottom:1px dashed #f1f5f9';
                it.textContent = o.label;
                it.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(i); });
                it.addEventListener('mouseenter', function () { highlight(i); });
                panel.appendChild(it);
            });
            if (list.length > 60) {
                var more = document.createElement('div');
                more.style.cssText = 'padding:8px 12px;color:#94a3b8;font-size:12.5px';
                more.textContent = '+' + (list.length - 60) + ' أيضاً — كمّل كتابة لتضييق النتائج…';
                panel.appendChild(more);
            }
            panel.style.display = 'block';
        }

        function highlight(i) {
            active = i;
            Array.prototype.forEach.call(panel.querySelectorAll('.emsr-item'), function (el, j) {
                el.style.background = j === i ? '#eef2ff' : '';
            });
        }

        function choose(i) {
            var o = shown[i];
            if (!o) return;
            sel.value = o.value;
            inp.value = o.label;
            panel.style.display = 'none';
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function filter() {
            var q = norm(inp.value);
            if (q === '') { render(opts); return; }
            var qd = q.replace(/[^0-9]/g, '');
            var words = q.split(' ').filter(Boolean);
            // score: 0 = أول حرف من كلمة (يتصدّر) · 1 = جزء من الاسم · 2 = هاتف
            var res = [];
            opts.forEach(function (o) {
                if (qd.length >= 3 && qd === q.replace(/ /g, '')
                    && o.phones.some(function (p) { return p.indexOf(qd) !== -1; })) { res.push({ o: o, s: 2 }); return; }
                var toks = o.search.split(' ');
                var allPrefix = words.every(function (w) { return toks.some(function (t) { return t.indexOf(w) === 0; }); });
                var allSub    = words.every(function (w) { return o.search.indexOf(w) !== -1; });
                if (allPrefix) res.push({ o: o, s: 0 });        // «ري» → ريتا وريما أولاً
                else if (allSub) res.push({ o: o, s: 1 });      // ثم من الحرف بوسط اسمه (كريستل…)
            });
            res.sort(function (a, b) { return a.s - b.s; });
            render(res.map(function (r) { return r.o; }));
        }

        inp.addEventListener('input', function () {
            // كتابة جديدة تلغي الاختيار السابق حتى لا يُرسَل أستاذ قديم بالغلط
            if (sel.value !== '') sel.value = '';
            filter();
        });
        inp.addEventListener('focus', function () { inp.select(); filter(); });
        inp.addEventListener('blur', function () { setTimeout(function () { panel.style.display = 'none'; }, 150); });
        inp.addEventListener('keydown', function (ev) {
            if (panel.style.display === 'none') return;
            if (ev.key === 'ArrowDown') { ev.preventDefault(); highlight(Math.min(active + 1, Math.min(shown.length, 60) - 1)); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(Math.max(active - 1, 0)); }
            else if (ev.key === 'Enter') {
                if (shown.length) { ev.preventDefault(); choose(active >= 0 ? active : 0); }
            }
            else if (ev.key === 'Escape') { panel.style.display = 'none'; }
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('select[name="employee_id"]'), enhance);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
