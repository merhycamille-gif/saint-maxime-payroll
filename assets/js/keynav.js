/**
 * ⌨️ التنقّل بالكيبورد بين الخانات — كل صفحات البرنامج (2026-11-09):
 * «وأنا عم عبّي بشي خانة بالبرنامج لازم إذا استعملت الإنتر ينقلني للبعدها دغري مش ينطّ على مكان آخر… بس عبّي خانة وحطّ إنتر
 *   بياخدني على الحفظ وأنا بعد ما خلصت شغل بقلب الصفحة — وكمان لازم اقدر استعمل الأسهم طالع نازل شمال يمين».
 *
 *  • Enter  = الخانة التالية (Shift+Enter = السابقة). لا يحفظ ولا يرسل النموذج. بآخر خانة يقف على كبسة «حفظ» بلا ما يحفظ؛ Enter ثانٍ عليها يحفظ.
 *  • ↑ ↓    = الخانة فوق / تحت (بالجداول: العمود نفسه بالسطر السابق / التالي).
 *  • ← →    = الخانة المجاورة — فقط حين المؤشّر بآخر الكتابة (أو الخانة فاضية / محدَّدة كلّها)؛ داخل الكتابة يحرّكان المؤشّر كالعادة.
 *  يبقى كما هو: خانات البحث والفلترة (نموذج GET، type=search، خانة وحيدة)، نماذج كلمة السرّ، مربّع النصّ الطويل (Enter = سطر جديد)،
 *  القوائم المنسدلة (↑↓ تغيّر الاختيار)، خانات التاريخ (الأسهم تعدّل اليوم/الشهر)، وأي خانة لها تصرّف خاصّ يمنع الافتراضي (preventDefault).
 *  صفحة لها حفظ بلا نموذج (موازنة الوزارة) تسمع الحدث «msa:keynav-end» وتوقف على كبسة حفظها.
 */
(function () {
    'use strict';
    var TEXT_TYPES = { text: 1, number: 1, tel: 1, email: 1, url: 1, password: 1, search: 1, date: 1, month: 1, week: 1, time: 1, 'datetime-local': 1, '': 1 };
    var NATIVE_ARROWS = { date: 1, month: 1, week: 1, time: 1, 'datetime-local': 1, range: 1, color: 1 };

    function typeOf(el) { return el.tagName === 'INPUT' ? String(el.getAttribute('type') || 'text').toLowerCase() : ''; }
    function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }
    /** خانة تُعبّأ: نصّ/رقم/تاريخ/قائمة/نصّ طويل — ظاهرة، غير معطّلة، غير للقراءة فقط */
    function fillable(el) {
        if (!el || el.disabled || el.readOnly) return false;
        var tag = el.tagName;
        if (tag === 'INPUT') { if (!TEXT_TYPES[typeOf(el)]) return false; }
        else if (tag !== 'SELECT' && tag !== 'TEXTAREA') return false;
        if (el.getAttribute('tabindex') === '-1') return false;
        return visible(el);
    }
    function isOff(el) { return !!(el.closest && el.closest('[data-keynav="off"], .emsr-wrap, .gs-box, .export-toolbar')); }
    function fieldsIn(root) { return Array.prototype.filter.call(root.querySelectorAll('input, select, textarea'), function (x) { return fillable(x) && !isOff(x); }); }
    /** النطاق الذي تُرتَّب فيه الخانات: النموذج، وإلا الجدول/البطاقة */
    function scopeOf(el) { return el.form || el.closest('[data-keynav]') || el.closest('table') || el.closest('.card, .doc-sheet, .modal') || document.body; }
    function go(to) {
        if (!to) return false;
        to.focus();
        try { if (to.select && to.tagName !== 'SELECT' && !NATIVE_ARROWS[typeOf(to)]) to.select(); } catch (e) {}
        try { to.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) {}
        return true;
    }

    /** هل Enter بهذه الخانة يُترك للمتصفّح (بحث/فلترة/دخول/خانة وحيدة)؟ */
    function enterIsNative(el) {
        var tag = el.tagName, t = typeOf(el);
        if (tag === 'TEXTAREA' || tag === 'BUTTON' || tag === 'A' || tag === 'SUMMARY' || el.isContentEditable) return true;
        if (tag !== 'INPUT' && tag !== 'SELECT') return true;
        if (tag === 'INPUT' && !TEXT_TYPES[t] && t !== 'checkbox' && t !== 'radio') return true;   // submit/button/file…
        if (t === 'search' || isOff(el)) return true;
        var f = el.form;
        if (!f) return !el.closest('td, th, [data-keynav]');   // خارج النماذج: فقط خانات الجداول (إدخال مباشر)
        if (f.hasAttribute('data-enter-submit')) return true;
        if (String(f.getAttribute('method') || 'get').toLowerCase() !== 'post') return true;   // بحث/فلترة
        if (f.querySelector('input[type="password"]')) return true;                              // دخول / تأكيد بكلمة سرّ
        return fieldsIn(f).length < 2;                                                          // خانة وحيدة = Enter يرسل
    }
    function saveButtonOf(el) {
        var f = el.form; if (!f) return null;
        var bs = Array.prototype.filter.call(f.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'), function (b) { return !b.disabled && visible(b); });
        if (!bs.length) return null;
        for (var i = 0; i < bs.length; i++) if (/btn-(primary|success)/.test(bs[i].className)) return bs[i];
        return bs[bs.length - 1];
    }
    function onEnter(e, el) {
        if (enterIsNative(el)) return;
        e.preventDefault();
        var list = fieldsIn(scopeOf(el)), i = list.indexOf(el), to = null;
        if (i < 0) {   // خانة تفتيش/اختيار: التالية بعدها بترتيب الصفحة
            for (var k = 0; k < list.length; k++) if (el.compareDocumentPosition(list[k]) & Node.DOCUMENT_POSITION_FOLLOWING) { to = e.shiftKey ? list[k - 1] : list[k]; break; }
            if (!to && e.shiftKey) to = list[list.length - 1];
        } else to = list[i + (e.shiftKey ? -1 : 1)];
        if (go(to)) return;
        if (e.shiftKey) return;
        // آخر خانة: الصفحة تقرّر (حفظ بلا نموذج)، وإلا كبسة حفظ النموذج — وقوف عليها بلا كبس
        var ev = new CustomEvent('msa:keynav-end', { bubbles: true, cancelable: true });
        if (!el.dispatchEvent(ev)) return;
        var b = saveButtonOf(el); if (b) { b.focus(); try { b.scrollIntoView({ block: 'nearest' }); } catch (x) {} }
    }

    /* ===== الأسهم ===== */
    function rect(el) { return el.getBoundingClientRect(); }
    /** أقرب خانة هندسياً بالاتجاه المطلوب ضمن القائمة */
    function nearest(el, list, dir) {
        var r = rect(el), cx = (r.left + r.right) / 2, cy = (r.top + r.bottom) / 2, best = null, bestScore = Infinity;
        for (var i = 0; i < list.length; i++) {
            var o = list[i]; if (o === el) continue;
            var q = rect(o), ox = (q.left + q.right) / 2, oy = (q.top + q.bottom) / 2, score;
            if (dir === 'up' || dir === 'down') {
                var dy = dir === 'down' ? q.top - r.bottom : r.top - q.bottom; if (dy < -2) continue;
                var overlap = Math.min(r.right, q.right) - Math.max(r.left, q.left) > 2;
                score = Math.max(dy, 0) * 4 + (overlap ? 0 : 2000 + Math.abs(ox - cx));
            } else {
                var dx = dir === 'right' ? q.left - r.right : r.left - q.right; if (dx < -2) continue;
                var vo = Math.min(r.bottom, q.bottom) - Math.max(r.top, q.top) > 2; if (!vo) continue;   // شمال/يمين: السطر نفسه فقط
                score = Math.max(dx, 0) + Math.abs(oy - cy) * 0.01;
            }
            if (score < bestScore) { bestScore = score; best = o; }
        }
        return best;
    }
    /** فوق/تحت بالجدول: العمود نفسه (والترتيب نفسه داخل الخلية) بأقرب سطر فيه خانة */
    function tableVertical(el, down) {
        var cell = el.closest('td, th'), tr = cell && cell.parentNode, table = tr && tr.closest('table'); if (!table) return null;
        var rows = Array.prototype.slice.call(table.rows), ri = rows.indexOf(tr), ci = cell.cellIndex; if (ri < 0) return null;
        var pos = fieldsIn(cell).indexOf(el);
        for (var r = ri + (down ? 1 : -1); r >= 0 && r < rows.length; r += (down ? 1 : -1)) {
            var c = rows[r].cells[ci]; if (!c || rows[r].cells.length !== tr.cells.length) continue;   // سطر ببنية أخرى (عنوان/مجموع ممتدّ)
            var fs = fieldsIn(c); if (fs.length) return fs[Math.min(Math.max(pos, 0), fs.length - 1)];
        }
        return undefined;   // جدول بلا خانة بهذا الاتجاه
    }
    function onArrow(e, el, key) {
        var tag = el.tagName, t = typeOf(el);
        if (tag !== 'INPUT' || !TEXT_TYPES[t] || NATIVE_ARROWS[t] || isOff(el) || el.readOnly || e.shiftKey) return;
        if (el.hasAttribute('list')) return;                       // اقتراحات المتصفّح
        if (!el.form && !el.closest('td, th, [data-keynav]')) return;   // خانات البحث الحرّة تبقى كما هي
        var vertical = key === 'ArrowUp' || key === 'ArrowDown', dir = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right' }[key];
        var pick = function () {
            if (vertical) { var tv = tableVertical(el, dir === 'down'); if (tv) return tv; if (tv === undefined && el.closest('td, th')) return null; }
            var row = !vertical && el.closest('tr');
            return nearest(el, fieldsIn(row || scopeOf(el)), dir);
        };
        if (vertical) {
            if (t === 'number') e.preventDefault();   // ↑↓ بخانة رقم كانت تزيد/تنقص القيمة بالغلط
            var to = pick(); if (to) { e.preventDefault(); go(to); }
            return;
        }
        // شمال/يمين: داخل الكتابة = تحريك المؤشّر؛ الانتقال فقط إن كانت فاضية/محدَّدة كلّها، أو إن لم يتحرّك المؤشّر (وصل لآخر الكتابة)
        var len = el.value.length, s = null, en = null;
        try { s = el.selectionStart; en = el.selectionEnd; } catch (x) {}
        if (len === 0 || (s === 0 && en === len && s !== null)) { var to2 = pick(); if (to2) { e.preventDefault(); go(to2); } return; }
        if (s === null || s !== en) return;   // خانة رقم (لا تكشف موضع المؤشّر) أو تحديد جزئي: للمتصفّح
        setTimeout(function () {
            var s2 = null, e2 = null; try { s2 = el.selectionStart; e2 = el.selectionEnd; } catch (x) {}
            if (document.activeElement === el && s2 === s && e2 === en) go(pick());
        }, 0);
    }

    document.addEventListener('keydown', function (e) {
        if (e.defaultPrevented || e.isComposing || e.ctrlKey || e.altKey || e.metaKey) return;
        var el = e.target; if (!el || !el.tagName) return;
        if (e.key === 'Enter') onEnter(e, el);
        else if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight') onArrow(e, el, e.key);
    });
    window.msaKeyNav = { fieldsIn: fieldsIn, go: go };
})();

/**
 * 🔢 خانات الأرقام تفتح بلا «.00» (2026-11-09 «ببرنامج الرواتب ما تحطّلي الرقم في نقطة ومن بعدو صفورا»): القيمة الآتية من القاعدة
 *    «9000000.00» تُعرض «9000000» و«1250.50» تُعرض «1250.5». القيمة نفسها لا تتغيّر (أصفار آخر الكسر فقط)، و defaultValue يتبعها
 *    فلا تُحسب الخانة «معدَّلة». لخانات type=number وخانات المبالغ النصّية (inputmode=decimal/numeric).
 */
(function () {
    'use strict';
    function clean(v) { var m = /^(-?\d+)\.(\d*?)0+$/.exec(String(v)); return m ? (m[2] ? m[1] + '.' + m[2] : m[1]) : null; }
    function run(root) {
        var list = (root || document).querySelectorAll('input[type="number"], input[inputmode="decimal"], input[inputmode="numeric"]');
        for (var i = 0; i < list.length; i++) { var el = list[i], c = clean(el.value); if (c !== null && document.activeElement !== el) { var same = el.defaultValue === el.value; el.value = c; if (same) el.defaultValue = c; } }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { run(); }); else run();
    window.msaNumClean = run;
})();
