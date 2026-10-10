// ⚖️📤 قفل التقارير المبعوتة للدولة (v2026): بصمة الجداول المعروضة + زرّ «انبعت للدولة» + شريط «تغيّر منذ الإرسال: صحّحه / خلّيه»
(function () {
    var L = window.MSA_LEGAL; if (!L || !L.key) return;
    var MANUAL = 'manual:' + L.key + ':' + L.period; // صفحة خيارات بلا مستند (مثل R10 الذي يُولَّد ملفاً): يُسجَّل «انبعت» بلا نسخة، والتغيير يُكتشف من المحرّك (changed_flag)
    function snapshot() {
        var area = document.getElementById('ppExportArea') || document.querySelector('.doc-sheet, .official-doc');
        if (!area) return null;
        var tables = area.querySelectorAll('table'), out = { title: (document.title || '').trim(), period: L.period, tables: [] };
        tables.forEach(function (t) {
            if (t.closest('.no-print, .no-export')) return;
            var rows = [];
            t.querySelectorAll('tr').forEach(function (tr) { if (tr.closest('.no-print')) return; var cells = []; tr.querySelectorAll('th,td').forEach(function (c) { cells.push((c.textContent || '').replace(/\s+/g, ' ').trim()); }); if (cells.join('').trim()) rows.push(cells); }); // textContent: لا يتأثّر بلغة العرض المختارة
            if (rows.length) out.tables.push(rows);
        });
        if (!out.tables.length) { var clone = area.cloneNode(true); clone.querySelectorAll('.no-print, .no-export, script, style').forEach(function (n) { n.remove(); }); var txt = (clone.textContent || '').replace(/\s+/g, ' ').trim(); if (!txt) return null; out.text = txt.slice(0, 200000); }
        return JSON.stringify(out);
    }
    function sha256(str) {
        if (!window.crypto || !crypto.subtle) return Promise.resolve(null);
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(str)).then(function (buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); });
    }
    function post(body) { body.csrf = window.CSRF_TOKEN; body.key = L.key; body.period = L.period; body.scope = L.scope; body.href = location.href; body.title = document.title; return fetch(L.base + 'pages/legal_mark.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }); }
    function bar(html, cls) { var b = document.getElementById('legalBar'); if (!b) { b = document.createElement('div'); b.id = 'legalBar'; b.className = 'legal-bar no-print no-export'; var tb = document.querySelector('.export-toolbar'); (tb && tb.parentNode ? tb.parentNode : document.querySelector('.page-content')).insertBefore(b, tb && tb.parentNode ? tb.nextSibling : null); } b.className = 'legal-bar no-print no-export ' + (cls || ''); b.innerHTML = html; return b; }
    var btn = document.getElementById('legalSendBtn');
    function fmt(d) { try { var x = new Date(d.replace(' ', 'T')); return x.toLocaleDateString('fr-LB') + ' ' + x.toLocaleTimeString('fr-LB', { hour: '2-digit', minute: '2-digit' }); } catch (e) { return d; } }
    function render(f, same, curHash) {
        if (!f) { if (btn) { btn.style.display = ''; btn.disabled = false; } return; }
        if (btn) btn.style.display = 'none';
        var who = f.sent_by ? ' — ' + f.sent_by : '';
        if (same) {
            bar('<i class="fas fa-lock"></i> <b>Envoyé à l’État</b> le ' + fmt(f.sent_at) + ' (v' + f.version + ')' + who + ' — <span dir="rtl">انبعت للدولة · النسخة مطابقة لما أُرسل</span>'
                + '<span class="lb-sp"></span><a class="btn btn-sm btn-light" href="' + L.base + 'pages/legal_calendar.php?view=' + f.id + '" target="_blank"><i class="fas fa-eye"></i> النسخة المبعوتة</a>', 'ok');
        } else {
            var b = bar('<i class="fas fa-triangle-exclamation"></i> <b>Modifié depuis l’envoi</b> — <span dir="rtl">هالتقرير انبعت للدولة بتاريخ ' + fmt(f.sent_at) + ' (v' + f.version + ') <b>وتغيّر منذ ذلك</b>. شو بدّك؟</span>'
                + (f.changed_note ? '<div class="lb-note" dir="rtl">' + String(f.changed_note).split('\n').slice(0, 5).map(function (s) { return '• ' + s.replace(/[<>]/g, ''); }).join('<br>') + '</div>' : '')
                + '<span class="lb-sp"></span><button type="button" class="btn btn-sm btn-primary" id="lbFix"><i class="fas fa-rotate"></i> صحّحه — نسخة تصحيحية v' + (parseInt(f.version, 10) + 1) + '</button>'
                + '<button type="button" class="btn btn-sm btn-light" id="lbKeep"><i class="fas fa-check"></i> خلّيه متل ما هو (المبعوت يبقى)</button>'
                + '<a class="btn btn-sm btn-light" href="' + L.base + 'pages/legal_calendar.php?view=' + f.id + '" target="_blank"><i class="fas fa-eye"></i> النسخة المبعوتة</a>', 'warn');
            b.querySelector('#lbFix').onclick = function () { if (!confirm('تسجيل نسخة تصحيحية جديدة مبعوتة للدولة بالأرقام الحالية؟ (النسخة القديمة بتضلّ محفوظة)')) return; send(this); };
            b.querySelector('#lbKeep').onclick = function () { var me = this; me.disabled = true; post({ action: 'ack', hash: curHash }).then(function (r) { if (r.ok) { L.filing.seen_hash = curHash; L.filing.changed_flag = 0; render(L.filing, true, curHash); showAlert('✓ المبعوت بيضلّ متل ما هو — التعديلات بتدخل بالتقرير الجاي', 'success'); } else { me.disabled = false; showAlert(r.err || 'خطأ', 'danger'); } }); };
        }
    }
    function send(el) {
        var snap = snapshot(); var manual = !snap;
        if (el) el.disabled = true;
        sha256(manual ? MANUAL : snap).then(function (h) { if (!h) { showAlert('المتصفّح لا يدعم البصمة (افتح الموقع بـhttps)', 'danger'); if (el) el.disabled = false; return; }
            return post({ action: 'send', hash: h, snapshot: manual ? '' : snap }).then(function (r) {
                if (r.ok) { L.filing = { id: r.id, version: r.version, sent_at: r.sent_at, sent_by: L.me, snapshot_hash: h, seen_hash: h, changed_flag: 0 }; render(L.filing, true, h); showAlert('📤 انبعت للدولة — التقرير مقفول بنسخة طبق الأصل (v' + r.version + ')', 'success'); }
                else { showAlert(r.err || 'خطأ', 'danger'); if (el) el.disabled = false; }
            });
        });
    }
    if (btn) btn.addEventListener('click', function () { if (!confirm('تسجيل هالتقرير «انبعت للدولة»؟ بينحفظ طبق الأصل وما بيتغيّر بعدها إلا بنسخة تصحيحية.')) return; send(btn); });
    function check() {
        var f = L.filing; if (!f) { render(null); return; }
        var snap = snapshot(); if (!snap) { render(f, !parseInt(f.changed_flag, 10), f.snapshot_hash); return; }
        sha256(snap).then(function (h) { if (!h) { render(f, true, f.snapshot_hash); return; } var same = (h === f.snapshot_hash) || (h === f.seen_hash && !parseInt(f.changed_flag, 10));
            if (same && parseInt(f.changed_flag, 10)) { post({ action: 'ack', hash: h }); f.changed_flag = 0; } // أُعيد الاحتساب بنفس الأرقام ⇒ لا تغيير فعلي
            render(f, same, h); });
    }
    if (document.readyState === 'complete') setTimeout(check, 300); else window.addEventListener('load', function () { setTimeout(check, 300); });
})();
