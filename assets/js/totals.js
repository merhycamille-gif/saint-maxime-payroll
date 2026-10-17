// ➕ (2026-10-17 «p1 — اتفقنا بكل التقارير يكون عندي خيار للمجاميع: كلها أو كل سطر كمان — صحّح» + «مش بس بالضمان، بكل التقارير
//    دايماً يكون في خيارات لكل الاحتمالات» + «إذا أي تقرير بشوفه وبدّي مجموع لشي معيّن يكون خيار كمان»)
// ثلاثة خيارات بكل جدول مستند (.doc-table) بكل التقارير والكشوف:
//  (١) rowsum (جلسة switch_totals.php): عمود «المجموع / Total» بآخر كل سطر = مجموع الخانات الرقمية بالسطر (الليرة والدولار كلٌّ على حدة).
//  (٢) group  (جلسة، ظاهر افتراضياً): سطور مجموع كل فئة/مدرسة — الموجودة تبقى، وتُضاف حيث لا توجد؛ مطفأ ⇒ تُخفى فتبقى «المجاميع كلها» فقط.
//  (٣) اختيار عمود: كبسة Σ على رأس كل عمود رقمي ⇒ شريط عائم فيه مجموع هذا العمود (وأعمدة أخرى معه)، والعمود المختار يصير هو ما يُجمع بـ«مجموع السطر».
// الإكسل/الوورد/الـPDF تأخذ الصفحة كما تُعرض فتحمل الإضافات؛ لا يُمسّ أي رقم أصلي ولا سطور المجاميع الموجودة.
(function () {
    var b = document.body; if (!b) return;
    var ROWSUM = b.getAttribute('data-totals-rowsum') === '1', GROUP = b.getAttribute('data-totals-group') !== '0';
    var NUM = /^-?[\d,]+(\.\d+)?$/;
    function txt(el) { return (el.textContent || '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim(); }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    // يقرأ خلية: {lbp, usd, ok} — ok=false إن لم تكن رقمية (نسبة %، تاريخ، رقم مركّب، نصّ)
    function parseCell(td) {
        if (!td) return { ok: false };
        var t = txt(td); if (t === '' || t === '—' || t === '-') return { lbp: 0, usd: 0, ok: false, empty: true };
        if (/%|\/|[A-Za-z]{3,}(?!\.L)|\d-\d/.test(t.replace(/L\.L|ل\.ل/g, ''))) return { ok: false };
        var lbp = 0, usd = 0, found = false, rest = t;
        // الدولار: «$643» أو «643 $» — لا «57,560,000 $643» (الرقم قبل $ يجب ألّا يتبعه رقم آخر بعد الـ$)
        rest = rest.replace(/\$\s*(-?[\d,]+(?:\.\d+)?)|(-?[\d,]+(?:\.\d+)?)\s*\$(?!\s*\d)/g, function (m, a, c) { usd += parseFloat((a || c).replace(/,/g, '')) || 0; found = true; return ' '; });
        rest = rest.replace(/(?:L\.L|ل\.ل)\s*(-?[\d,]+(?:\.\d+)?)|(-?[\d,]+(?:\.\d+)?)\s*(?:L\.L|ل\.ل\.?)/g, function (m, a, c) { lbp += parseFloat((a || c).replace(/,/g, '')) || 0; found = true; return ' '; });
        rest = rest.trim();
        if (rest !== '') { if (NUM.test(rest)) { lbp += parseFloat(rest.replace(/,/g, '')) || 0; found = true; } else return { ok: false }; }
        return { lbp: lbp, usd: usd, ok: found };
    }
    function fmt(n) { n = Math.round(n * 100) / 100; var s = Math.abs(n).toLocaleString('en-US', { maximumFractionDigits: 2 }); return (n < 0 ? '-' : '') + s; }
    function cellHtml(s) { var h = []; if (s.lbp) h.push('<span dir="ltr">' + fmt(s.lbp) + ' L.L</span>'); if (s.usd) h.push('<span dir="ltr" class="msa-usd">$' + fmt(s.usd) + '</span>'); return h.length ? h.join('<br>') : '—'; }
    function sumRows(rows, cols) { var s = { lbp: 0, usd: 0, n: 0 }; rows.forEach(function (r) { cols.forEach(function (c) { var p = parseCell(r.cells[c]); if (p.ok) { s.lbp += p.lbp; s.usd += p.usd; s.n++; } }); }); return s; }
    function isTotalRow(tr) { return /(^|\s)(total-row|subtotal-row|msa-grp-row)(\s|$)/.test(tr.className) || tr.parentElement.tagName === 'TFOOT'; }
    function isGroupRow(tr) { if (/(^|\s)cat-row(\s|$)/.test(tr.className)) return true; var c = tr.children; return c.length === 1 && c[0].tagName === 'TD' && (parseInt(c[0].getAttribute('colspan') || '1', 10) >= 3); }
    // شبكة الرأس: لكل عمود الخانة الورقية (آخر صفّ تصل إليه، colspan=1) مع مراعاة rowspan/colspan؛ صفّ عنوان التقرير (خانة واحدة بـcolspan) لا يُحتسب
    function headerGrid(thead) {
        var rows = Array.prototype.filter.call(thead.rows, function (r) { return !(r.cells.length === 1 && parseInt(r.cells[0].getAttribute('colspan') || '1', 10) >= 3); });
        var R = rows.length, occ = [], leaf = [], nCols = 0;
        for (var r = 0; r < R; r++) { occ[r] = occ[r] || []; var col = 0;
            Array.prototype.forEach.call(rows[r].cells, function (c) { while (occ[r][col]) col++; var cs = parseInt(c.getAttribute('colspan') || '1', 10), rs = parseInt(c.getAttribute('rowspan') || '1', 10);
                for (var i = 0; i < rs; i++) { occ[r + i] = occ[r + i] || []; for (var k = 0; k < cs; k++) occ[r + i][col + k] = true; }
                if (cs === 1 && r + rs >= R) leaf[col] = c; col += cs; });
            if (col > nCols) nCols = col; }
        return { nCols: nCols, leaf: leaf, colRows: rows, last: rows[R - 1] || null };
    }
    function bump(cell) { if (cell) cell.setAttribute('colspan', String(parseInt(cell.getAttribute('colspan') || '1', 10) + 1)); }
    // ── اختيار الأعمدة (محفوظ لكل صفحة/تقرير بالمتصفّح) ──
    var KEY = 'msa_colpick:' + location.pathname + location.search.replace(/[?&](rowsum|group)=\d/g, '');
    var picked = {}; try { picked = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { picked = {}; }
    function savePick() { try { localStorage.setItem(KEY, JSON.stringify(picked)); } catch (e) {} }
    var ctxs = [];
    function process(tbl, ti) {
        if (tbl.closest('.no-print, .export-toolbar') || tbl.dataset.msaTotals) return;
        var thead = tbl.tHead, bodies = Array.prototype.slice.call(tbl.tBodies); if (!thead || !bodies.length) return;
        // صفّ الأعمدة = آخر صفّ بالرأس فيه ≥3 خانات (صفّ عنوان التقرير بـcolspan لا يُحتسب)؛ عدد الأعمدة = مجموع colspan فيه
        var G = headerGrid(thead), nCols = G.nCols, headOf = G.leaf, hRow = G.last; if (!hRow || nCols < 3) return;
        var dataRows = [], oddRows = [];
        bodies.forEach(function (tb) { Array.prototype.forEach.call(tb.rows, function (tr) { if (isTotalRow(tr) || isGroupRow(tr)) return; if (tr.cells.length === nCols) dataRows.push(tr); else oddRows.push(tr); }); });
        if (dataRows.length < 2) return;
        // الأعمدة الرقمية: ≥60٪ من خاناتها أرقام (الفارغة لا تُحتسب)؛ العمود الأوّل (#) مستثنى
        var numeric = [];
        for (var c = 1; c < nCols; c++) { var ok = 0, tot = 0; dataRows.forEach(function (r) { var p = parseCell(r.cells[c]); if (p.empty) return; tot++; if (p.ok) ok++; }); numeric[c] = tot > 0 && ok / tot >= 0.6; }
        if (!numeric.some(Boolean)) return;
        tbl.dataset.msaTotals = '1';
        var ctx = { tbl: tbl, ti: ti, nCols: nCols, numeric: numeric, dataRows: dataRows, headOf: headOf, sel: (picked[ti] || []).filter(function (c) { return numeric[c]; }), sumTds: [], grpTds: [] };
        ctxs.push(ctx);
        // (٣) كبسة Σ على رأس كل عمود رقمي
        for (var c = 1; c < nCols; c++) {
            if (!numeric[c] || !headOf[c]) continue;
            var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'msa-colpick no-print'; btn.setAttribute('data-col', String(c)); btn.title = 'مجموع هذا العمود / Total de cette colonne'; btn.textContent = 'Σ';
            headOf[c].insertBefore(btn, headOf[c].firstChild); headOf[c].classList.add('msa-pickable');
            (function (col, ct) { btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); var i = ct.sel.indexOf(col); if (i >= 0) ct.sel.splice(i, 1); else ct.sel.push(col); picked[ct.ti] = ct.sel.slice(); savePick(); refresh(ct); }); })(c, ctx);
        }
        // (١) عمود مجموع السطر
        if (ROWSUM) {
            var th = document.createElement('th'); th.className = 'msa-rowsum'; th.innerHTML = 'Total<br>المجموع'; ctx.sumTh = th;
            // صفوف عنوان التقرير تتّسع بواحد؛ خانة «المجموع» على أوّل صفّ أعمدة بـrowspan يغطّي صفوف الأعمدة كلّها (رؤوس بصفّين)
            Array.prototype.forEach.call(thead.rows, function (r) { if (G.colRows.indexOf(r) < 0) bump(r.cells[0]); });
            if (G.colRows.length > 1) th.setAttribute('rowspan', String(G.colRows.length));
            G.colRows[0].appendChild(th);
            oddRows.forEach(function (r) { bump(r.cells[r.cells.length - 1]); });
            dataRows.forEach(function (r) { var td = document.createElement('td'); td.className = 'msa-rowsum'; r.appendChild(td); ctx.sumTds.push({ row: r, td: td }); });
            bodies.forEach(function (tb) { Array.prototype.forEach.call(tb.rows, function (tr) { if (isTotalRow(tr) || isGroupRow(tr)) { if (tr.cells.length === nCols) { var td = document.createElement('td'); td.className = 'msa-rowsum'; tr.appendChild(td); } else bump(tr.cells[tr.cells.length - 1]); } }); });
            if (tbl.tFoot) Array.prototype.forEach.call(tbl.tFoot.rows, function (tr) { if (tr.cells.length === nCols) { var td = document.createElement('td'); td.className = 'msa-rowsum'; tr.appendChild(td); } else bump(tr.cells[tr.cells.length - 1]); });
        }
        // (٢) مجاميع الفئات
        if (!GROUP) tbl.querySelectorAll('tr.subtotal-row').forEach(function (tr) { tr.classList.add('msa-sub-hidden'); });
        if (GROUP && !tbl.querySelector('.subtotal-row')) {
            bodies.forEach(function (tb) {
                var groups = [], cur = null;
                Array.prototype.forEach.call(tb.rows, function (tr) {
                    if (isGroupRow(tr)) { cur = { head: tr, name: txt(tr), rows: [], last: tr }; groups.push(cur); }
                    else if (cur && !isTotalRow(tr) && tr.cells.length >= nCols) { cur.rows.push(tr); cur.last = tr; }
                });
                if (groups.length < 2) return;
                groups.forEach(function (g) {
                    if (!g.rows.length) return;
                    var tr = document.createElement('tr'); tr.className = 'msa-grp-row';
                    var first = -1; for (var c = 1; c < nCols; c++) if (numeric[c]) { first = c; break; }
                    var lab = document.createElement('td'); lab.setAttribute('colspan', String(first)); lab.innerHTML = 'مجموع ' + esc(g.name) + ' <small>(' + g.rows.length + ')</small>'; tr.appendChild(lab);
                    for (var c = first; c < nCols; c++) { var td = document.createElement('td'); if (numeric[c]) td.innerHTML = cellHtml(sumRows(g.rows, [c])); tr.appendChild(td); }
                    if (ROWSUM) { var tdr = document.createElement('td'); tdr.className = 'msa-rowsum'; tr.appendChild(tdr); ctx.grpTds.push({ rows: g.rows, td: tdr }); }
                    g.last.parentNode.insertBefore(tr, g.last.nextSibling);
                });
            });
        }
        refresh(ctx);
    }
    // يعيد حساب عمود مجموع السطر حسب الأعمدة المختارة (أو كل الرقمية إن لم يُختر شيء) ويحدّث الشريط العائم
    function refresh(ctx) {
        var cols = ctx.sel.length ? ctx.sel.slice() : []; if (!cols.length) for (var c = 1; c < ctx.nCols; c++) if (ctx.numeric[c]) cols.push(c);
        for (var c = 1; c < ctx.nCols; c++) if (ctx.headOf[c]) ctx.headOf[c].classList.toggle('msa-picked', ctx.sel.indexOf(c) >= 0);
        ctx.sumTds.forEach(function (x) { x.td.innerHTML = cellHtml(sumRows([x.row], cols)); });
        ctx.grpTds.forEach(function (x) { x.td.innerHTML = cellHtml(sumRows(x.rows, cols)); });
        if (ctx.sumTh) ctx.sumTh.innerHTML = ctx.sel.length ? 'Total<br>مجموع المختار' : 'Total<br>المجموع';
        renderBar();
    }
    function renderBar() {
        var bar = document.getElementById('msaColBar'); var items = [];
        ctxs.forEach(function (ct) { ct.sel.forEach(function (c) { var s = sumRows(ct.dataRows, [c]); items.push({ name: txt(ct.headOf[c]).replace(/^Σ\s*|\s*Σ$/g, '').trim(), s: s, ct: ct, c: c }); }); });
        if (!items.length) { if (bar) bar.remove(); return; }
        if (!bar) { bar = document.createElement('div'); bar.id = 'msaColBar'; bar.className = 'no-print no-export'; document.body.appendChild(bar); }
        var tot = { lbp: 0, usd: 0 }; items.forEach(function (i) { tot.lbp += i.s.lbp; tot.usd += i.s.usd; });
        bar.innerHTML = '<div class="cb-h"><i class="fas fa-calculator"></i> مجموع العمود المختار / Total de la colonne <button type="button" class="cb-x" title="إلغاء الاختيار">✕</button></div>'
            + items.map(function (i) { return '<div class="cb-i"><span class="cb-n">' + esc(i.name) + '</span> <span class="cb-v">' + cellHtml(i.s) + '</span> <small>(' + i.s.n + ' سطر)</small></div>'; }).join('')
            + (items.length > 1 ? '<div class="cb-i cb-t"><span class="cb-n">المجموع معاً / Ensemble</span> <span class="cb-v">' + cellHtml(tot) + '</span></div>' : '');
        bar.querySelector('.cb-x').addEventListener('click', function () { ctxs.forEach(function (ct) { ct.sel = []; picked[ct.ti] = []; refresh(ct); }); savePick(); });
    }
    // 💱 (2026-10-17 «تحت الكتابة دغري بعناوين الأعمدة اللي فيها مبالغ نكتب نوع العملة: ل.ل / $ / ل.ل + $») لكل عمود مبالغ سطر العملة تحت رأسه
    function curHeads(tbl) {
        var thead = tbl.tHead; if (!thead) return;
        var G = headerGrid(thead), nCols = G.nCols, headOf = G.leaf, hRow = G.last; if (!hRow || nCols < 3) return;
        var rows = []; Array.prototype.forEach.call(tbl.tBodies, function (tb) { Array.prototype.forEach.call(tb.rows, function (tr) { if (!isTotalRow(tr) && !isGroupRow(tr) && tr.cells.length === nCols) rows.push(tr); }); });
        if (!rows.length) return;
        for (var c = 1; c < nCols; c++) {
            var th = headOf[c]; if (!th || th.querySelector('.cur-head') || th.classList.contains('msa-rowsum')) continue;
            var hc = th.cloneNode(true); Array.prototype.forEach.call(hc.querySelectorAll('.rate-head, .msa-colpick, small'), function (x) { x.parentNode.removeChild(x); }); var ht = txt(hc); if (/ل\.ل|L\.L|\$|USD|LBP|ليرة|دولار/.test(ht)) continue;   // الرأس نفسه يذكر العملة أصلاً (لا سطر سعر الصرف الصغير)
            var lbp = 0, usd = 0, bare = 0, tot = 0;
            rows.forEach(function (r) { var t = txt(r.cells[c]); if (!t || t === '—' || t === '-') return; tot++; var hasL = /L\.L|ل\.ل/.test(t), hasU = /\$/.test(t); var restT = t.replace(/\$\s*-?[\d,]+(\.\d+)?|-?[\d,]+(\.\d+)?\s*\$/g, ' ').replace(/L\.L|ل\.ل\.?/g, ' ').trim(); if (!hasL && hasU && /^-?\d+(,\d{3})*(\.\d+)?$/.test(restT)) hasL = true;   /* «54,000,000 $603»: ليرة بلا وحدة + دولار = العملتان */ if (hasL) lbp++; if (hasU) usd++; if (!hasL && !hasU && /^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(t)) bare++; });
            if (!tot) continue;
            var lab = '';
            if (lbp + usd >= tot * 0.6) lab = (lbp && usd) ? 'ل.ل + $' : (usd ? '$' : 'ل.ل');
            else if (bare >= tot * 0.6) lab = 'ل.ل';   // رقم بفواصل الآلاف بلا وحدة = ليرة (أساس الراتب…)
            if (!lab) continue;
            var sm = document.createElement('small'); sm.className = 'cur-head'; sm.setAttribute('dir', 'ltr'); sm.textContent = lab;
            var pick = th.querySelector('.msa-colpick'); th.appendChild(sm); if (pick) th.appendChild(pick);
        }
    }
    function run() { document.querySelectorAll('#pageContent table.doc-table').forEach(process); document.querySelectorAll('#pageContent table.doc-table').forEach(curHeads); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
