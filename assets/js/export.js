/**
 * رواتب المدارس — طباعة وتصدير موحّد
 * Print / PDF / Excel / Word / WhatsApp / Email
 * يعمل على المحتوى المعروض (المنطقة #ppExportArea إن وُجدت وإلا #pageContent).
 */
(function () {
    'use strict';

    function area() {
        return document.getElementById('ppExportArea')
            || document.getElementById('pageContent')
            || document.body;
    }

    function stamp() {
        try { return new Date().toISOString().slice(0, 10); } catch (e) { return ''; }
    }

    // نسخة نظيفة من المحتوى بدون أزرار/عناصر لا تُصدَّر (.word-head خاصة بتصدير الوورد فقط)
    function cleanHtml() {
        var node = area().cloneNode(true);
        node.querySelectorAll('.no-print, .no-export, .export-toolbar, script, button, .btn, form.no-print')
            .forEach(function (n) { n.remove(); });
        // أزل تصغير الشاشة (zoom) عن الجداول — التصدير لوورد/إكسل يجب أن يكون بالحجم الكامل
        node.querySelectorAll('[style*="zoom"]').forEach(function (n) { n.style.zoom = ''; });
        return node.innerHTML;
    }

    function rawDownload(filename, content, mime) {
        var blob = new Blob([content], { type: mime });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 800);
    }

    function download(filename, content, mime) {
        rawDownload(filename, '﻿' + content, mime + ';charset=utf-8');
    }

    function safeName(t) {
        return (t || 'document').replace(/[\\/:*?"<>|]+/g, '_').slice(0, 80);
    }

    // ===== طباعة =====
    // 🖨️ (2026-10-11 «بس أرسلها للطبع تكون جاهزة دغري»): الطباعة = ورقة PDF جاهزة من محرّك الطباعة الرسمي على الخادم
    // (includes/pdf_engine.php) تُفتح بإطار مخفي وتُطبع — نفس الورقة المرتّبة بكل مكان. القسائم/النماذج طبق الأصل تبقى على طباعة المتصفّح.
    function serverPrintable() {
        if (document.querySelector('.salary-slip, .payslip-card, .ofs, .xls-sheet')) return false;
        return !!document.querySelector('#ppExportArea, .doc-sheet, .land-report, .official-doc, #pageContent table');
    }
    window.ppPrint = function (btn) {
        if (!serverPrintable() || !window.msaServerPdf) { window.print(); return; }
        var b = btn || (document.activeElement && document.activeElement.tagName === 'BUTTON' ? document.activeElement : null);
        var old = b ? b.innerHTML : ''; if (b) { b.disabled = true; b.textContent = '⏳ عم نجهّز الورقة...'; }
        window.msaServerPdf({ inline: true }).then(function (r) {
            window.ppPrintFile(URL.createObjectURL(r.blob), false);
            if (b) { b.innerHTML = old; b.disabled = false; }
        }).catch(function (e) { try { console.error(e); } catch (_) {} if (b) { b.innerHTML = old; b.disabled = false; } window.print(); });
    };

    // ⌨️ (2026-10-15 «ما في ولا تقرير ظابط للطبع»): Ctrl+P / Cmd+P من لوحة المفاتيح = نفس كبسة الطباعة (PDF الخادم المرتّب)
    //    لا طباعة المتصفّح الخام — حيث يُطبع المستند من الخادم أصلاً.
    document.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey || !e.key || e.key.toLowerCase() !== 'p') return;
        if (!serverPrintable() || !window.msaServerPdf) return;
        e.preventDefault(); e.stopPropagation();
        window.ppPrint(null);
    }, true);

    // ===== 🔄 اتجاه الورقة (2026-09-14 «وقت عم اطبع بتضل لاندسكيب ما بيغير على بورتريه بالبرنت») =====
    // الاتجاه مثبّت بالـCSS (@page landscape للعريض) فحوار الطباعة يرفض تغييره. الزرّ يقلبه: يضيف
    // body.print-portrait / .print-landscape (app.css يفرض @page مسمّاة + عرض الورقة --pz-target) ويعيد
    // حساب تصغير الجداول. pdf-save.js يقرأ window.msaOrientForced فيتبع نفس الاتجاه. لا يُحفَظ: كل صفحة تبدأ بافتراضها.
    window.msaOrientForced = '';
    function msaDefaultOrient() {
        if (document.querySelector('.land-report, .xls-sheet')) return 'landscape';
        try {
            var walk = function (rules) {
                for (var i = 0; i < rules.length; i++) {
                    var r = rules[i];
                    if (r.type === CSSRule.PAGE_RULE && !r.selectorText && /landscape/.test(r.cssText)) return true;
                    if (r.cssRules && walk(r.cssRules)) return true;
                }
                return false;
            };
            for (var s = 0; s < document.styleSheets.length; s++) { try { if (walk(document.styleSheets[s].cssRules || [])) return 'landscape'; } catch (e) {} }
        } catch (e) {}
        return 'portrait';
    }
    window.msaPrintOrient = function () { return window.msaOrientForced || msaDefaultOrient(); };
    function msaOrientApply(fire) {
        var o = window.msaPrintOrient();
        document.body.classList.toggle('print-portrait', !!window.msaOrientForced && o === 'portrait');
        document.body.classList.toggle('print-landscape', !!window.msaOrientForced && o === 'landscape');
        var b = document.getElementById('msaOrientBtn');
        if (b) {
            var sp = b.querySelector('span');
            // 🔄 (2026-10-15 «ما عم يغير من أفقي إلى عمودي»): الاتجاه التلقائي يقرّره محرّك الطباعة (الخادم) لا CSS الصفحة، فكان الزرّ
            //    يظنّ الورقة عمودية ويقلبها «أفقية» وهي أفقية أصلاً. الآن ثلاث حالات واضحة: تلقائي ⇒ عمودي ⇒ أفقي ⇒ تلقائي.
            if (sp) sp.textContent = (!window.msaOrientForced ? 'Auto / تلقائي' : (o === 'landscape' ? 'Paysage / أفقي' : 'Portrait / عمودي')) + ' ⇄';
            b.title = 'Orientation de la page : automatique → portrait → paysage / اتجاه الورقة: تلقائي ← عمودي ← أفقي';
            b.classList.toggle('btn-warning', !!window.msaOrientForced);
            b.classList.toggle('btn-light', !window.msaOrientForced);
        }
        // 🚀 (2026-10-03): إشارة تغيير المقاس فقط حين يقلب المستخدم الاتجاه فعلاً — كانت تُطلق مرّتين عند كل فتحة (DOMContentLoaded + load)
        // فتعيد تثبيت رؤوس كل الجداول وتخطيطها مرّتين بلا أي تغيير (~0.2ث بالصفحات الكبيرة).
        if (fire === true) window.dispatchEvent(new Event('resize'));
    }
    window.msaToggleOrient = function () {
        window.msaOrientForced = !window.msaOrientForced ? 'portrait' : (window.msaOrientForced === 'portrait' ? 'landscape' : '');
        msaOrientApply(true);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', msaOrientApply); else msaOrientApply();
    window.addEventListener('load', msaOrientApply);

    // ===== PDF (عبر حوار الطباعة → حفظ كـPDF) =====
    window.ppPdf = function () {
        // معظم المتصفحات: اختر "حفظ كـ PDF / Save as PDF" من وجهة الطباعة
        window.print();
    };

    // هل المحتوى عريض (نموذج رسمي/تقرير بأعمدة كثيرة) → يحتاج صفحة أفقية؟
    function isWide() {
        var a = area();
        if (a.querySelector('.xls-sheet, .land-report')) return true;
        var t = a.querySelector('table.doc-table, table');
        if (t) { var r = t.querySelector('tr'); if (r && r.children.length >= 9) return true; }
        return false;
    }

    // ===== Excel — صفحة A4 بالاتجاه الصحيح + ملاءمة العرض لصفحة واحدة + بلا خطوط شبكة =====
    window.ppExcelLegacy = function (title) {
        var wide = isWide();
        var xml = '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'
            + '<x:Name>' + safeName(title).slice(0, 28) + '</x:Name><x:WorksheetOptions>'
            + '<x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>9</x:PaperSizeIndex>' // 9 = A4
            + '<x:FitWidth>1</x:FitWidth><x:FitHeight>0</x:FitHeight>'
            + '<x:Orientation>' + (wide ? 'Landscape' : 'Portrait') + '</x:Orientation>'
            + '<x:LeftMargin>0.3</x:LeftMargin><x:RightMargin>0.3</x:RightMargin>'
            + '<x:TopMargin>0.4</x:TopMargin><x:BottomMargin>0.4</x:BottomMargin></x:Print>'
            + '<x:DoNotDisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>'
            + '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
        var head = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
            + 'xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8">' + xml
            + '<style>body{font-family:Cairo,Arial,sans-serif;direction:rtl}'
            + 'table{border-collapse:collapse}td,th{border:1px solid #999;padding:3px 5px;font-size:11pt;'
            + 'vertical-align:middle}th{background:#1e3a8a;color:#fff;font-weight:bold}</style></head><body>';
        download(safeName(title) + '_' + stamp() + '.xls', head + cleanHtml() + '</body></html>',
            'application/vnd.ms-excel');
    };

    // ===== Word — صفحة A4 بالاتجاه الصحيح (أفقي للعريض) + هوامش، فيطلع بصفحة مرتّبة لا ينقصّ =====
    // 🖼️ «وقت عم اطبع وورد ما عم يبين لوغو المدرسة» (2026-08-20): وورد لا يعرض الصور بروابط
    // نسبية ولا data: ولا خلفيات CSS — فصار الملف بصيغة MHT (multipart/related) والصور
    // (الشعار/الترويسة) مضمَّنة base64 داخل الملف نفسه، والترويسة البديلة .word-head تُكشف هنا فقط.
    function b64Wrap(b64) { return b64.replace(/(.{76})/g, '$1\r\n'); }
    function utf8B64(str) { return b64Wrap(btoa(unescape(encodeURIComponent(str)))); }
    function imgPart(absUrl, idx) {
        return fetch(absUrl, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('http ' + r.status);
            return r.blob();
        }).then(function (blob) {
            return new Promise(function (res, rej) {
                var fr = new FileReader();
                fr.onload = function () {
                    var m = String(fr.result).match(/^data:([^;]+);base64,(.*)$/);
                    if (!m) return rej(new Error('no b64'));
                    var ext = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/bmp': 'bmp' }[m[1]] || 'img';
                    res({ mime: m[1], b64: m[2], loc: 'http://msa.doc/img' + idx + '.' + ext });
                };
                fr.onerror = function () { rej(new Error('read')); };
                fr.readAsDataURL(blob);
            });
        });
    }
    window.ppWordLegacy = function (title) {
        var wide = isWide();
        // A4: عمودي 595.3×841.9pt، أفقي 841.9×595.3pt
        var size = wide ? '841.9pt 595.3pt' : '595.3pt 841.9pt';
        var orient = wide ? 'landscape' : 'portrait';
        // 🗏 «بدي بس 2 سنتم» (2026-08-21): وورد الإفادات (ppExportArea العمودي) هوامش جوانبه
        // 2 سم بالضبط — والتقارير/الجداول العريضة تبقى 1 سم حتى لا تنحشر
        var att = !wide && !!document.getElementById('ppExportArea');
        var css = '<style>'
            + '@page WordSection1{size:' + size + ';mso-page-orientation:' + orient + ';margin:' + (att ? '1.2cm 2cm' : '1cm 1cm') + ';}'
            + 'div.WordSection1{page:WordSection1;}'
            + 'body{font-family:Cairo,Arial,sans-serif;direction:rtl}'
            + 'table{border-collapse:collapse;width:100%;table-layout:fixed}'
            + 'td,th{border:1px solid #777;padding:3px 5px;font-size:' + (wide ? '8.5pt' : '11pt') + ';word-wrap:break-word;text-align:center}'
            + 'th{background:#1e3a8a;color:#fff;font-weight:bold}'
            + 'h1,h2,h3{margin:6px 0;font-size:13pt}.doc-title{font-size:13pt;font-weight:bold;text-align:center}'
            + '</style>';
        var head = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
            + 'xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8">' + css + '</head>'
            + '<body dir="rtl"><div class="WordSection1">';
        // نسخة نظيفة + كشف ترويسة الوورد البديلة (بلا خط، المدينة فقط) بدل ترويسة الشاشة scr-head
        var node = area().cloneNode(true);
        node.querySelectorAll('.no-print, .no-export, .export-toolbar, script, button, .btn, form.no-print')
            .forEach(function (n) { n.remove(); });
        node.querySelectorAll('[style*="zoom"]').forEach(function (n) { n.style.zoom = ''; });
        var wh = node.querySelectorAll('.word-head');
        if (wh.length) {
            wh.forEach(function (n) { n.style.display = ''; });
            node.querySelectorAll('.scr-head').forEach(function (n) { n.remove(); });
        }
        // كل صورة → جزء مضمَّن بالملف؛ وإن تعذّر جلبها يبقى رابطها المطلق (أفضل من النسبي)
        var jobs = Array.prototype.slice.call(node.querySelectorAll('img')).map(function (img, i) {
            var src = img.getAttribute('src') || '';
            if (!src || src.slice(0, 5) === 'data:') return Promise.resolve(null);
            var abs; try { abs = new URL(src, document.baseURI).href; } catch (e) { return Promise.resolve(null); }
            return imgPart(abs, i)
                .then(function (p) { img.setAttribute('src', p.loc); return p; })
                .catch(function () { img.setAttribute('src', abs); return null; });
        });
        Promise.all(jobs).then(function (parts) {
            var htmlDoc = head + node.innerHTML + '</div></body></html>';
            var B = '----=_MSA_DOC_PART';
            var mht = 'MIME-Version: 1.0\r\n'
                + 'Content-Type: multipart/related; type="text/html"; boundary="' + B + '"\r\n\r\n'
                + '--' + B + '\r\n'
                + 'Content-Type: text/html; charset="utf-8"\r\n'
                + 'Content-Transfer-Encoding: base64\r\n'
                + 'Content-Location: http://msa.doc/doc.html\r\n\r\n'
                + utf8B64(htmlDoc) + '\r\n';
            parts.filter(Boolean).forEach(function (p) {
                mht += '--' + B + '\r\n'
                    + 'Content-Type: ' + p.mime + '\r\n'
                    + 'Content-Transfer-Encoding: base64\r\n'
                    + 'Content-Location: ' + p.loc + '\r\n\r\n'
                    + b64Wrap(p.b64) + '\r\n';
            });
            mht += '--' + B + '--\r\n';
            rawDownload(safeName(title) + '_' + stamp() + '.doc', mht, 'application/msword');
        });
    };

    // ===== ✏️ تعديل / كتابة قبل الطباعة (2026-10-08 «دايماً عمول هيك بكل الإفادات») =====
    // المستند المعروض (ppExportArea / official-doc / doc-sheet / القسائم) يصير قابلاً للكتابة بالمكان؛ كبسة ثانية تقفله. للطباعة فقط.
    function ppEditTargets() {
        var a = document.querySelectorAll('#ppExportArea, .official-doc, .doc-sheet, .xls-sheet, .salary-slip, .payslip-card');
        return Array.prototype.filter.call(a, function (n) { return !n.closest('.no-print') && n.offsetParent; });
    }
    window.ppEditToggle = function (btn) {
        var on = !document.body.classList.contains('pp-editing');
        document.body.classList.toggle('pp-editing', on);
        ppEditTargets().forEach(function (n) { n.contentEditable = on ? 'true' : 'false'; n.style.outline = on ? '2px dashed #1b7a3d' : ''; n.style.outlineOffset = on ? '4px' : ''; });
        btn = btn || document.getElementById('msaEditBtn');
        if (btn) { btn.classList.toggle('btn-success', on); btn.classList.toggle('btn-gold', !on); var sp = btn.querySelector('span'); if (sp) sp.textContent = on ? 'Terminé / خلص التعديل' : 'Modifier / تعديل / كتابة'; }
    };
    // ===== ✏️💾 احفظ التعديل كأساس (2026-10-24 «الكلمة أو الجملة اللي بعدّلها تضلّ هي الأساسية بالبرنامج… وهالخيار يكون عندو باسوورد») =====
    // بالإفادات فقط (window.MSA_ATT من attestations.php): عند فتح «تعديل / كتابة» نصوّر نصّ كل فقرة؛ عند «احفظ كأساس» نحسب لكل فقرة تغيّرت
    // الجزء المختلف (بكلمات كاملة) ونحفظه استبدالاً دائماً لهذا النوع/اللغة عبر pages/att_rules.php بكلمة السرّ. الخادم يطبّقه على كل إفادة من بعدها.
    var attSnap = null;
    // 🧩 (2026-10-24 p1 «بيحفظ بس بترجع متل ما كانت باللغة الأجنبية») المقارنة على مستوى عقدة النصّ لا الفقرة:
    //    innerText يدمج نصّ <strong> (الاسم/المبلغ) مع ما حوله فيتوسّع التعديل عبر حدّ الوسم، والخادم يطابق داخل عقدة واحدة فقط ⇒ لا ينطبق فترجع كما كانت.
    function attTextNodes() {
        var out = [];
        ppEditTargets().forEach(function (root) {
            var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: function (n) {
                if (!n.nodeValue || !n.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                if (n.parentElement && n.parentElement.closest('.no-print, .att-rules, script, style')) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            } });
            var n; while (n = w.nextNode()) out.push(n);
        });
        return out;
    }
    function attSnapshot() { return attTextNodes().map(function (n) { return { node: n, val: (n.nodeValue || '').replace(/[\s\u00A0]+/g, ' ').trim() }; }); }
    function attDiff(a, b) {
        var p = 0; while (p < a.length && p < b.length && a[p] === b[p]) p++;
        var s = 0; while (s < a.length - p && s < b.length - p && a[a.length - 1 - s] === b[b.length - 1 - s]) s++;
        while (p > 0 && !/\s/.test(a[p - 1])) p--;                     // بداية كلمة
        while (s > 0 && !/\s/.test(a[a.length - s])) s--;             // نهاية كلمة
        var words = function (str) { return str.trim() ? str.trim().split(/\s+/).length : 0; };
        // جملة قصيرة جداً (كلمة أو اثنتان) تُوسَّع بكلمات مجاورة حتى لا تُبدَّل بكل مكان تظهر فيه
        while (words(a.slice(p, a.length - s)) < 3 && (p > 0 || s > 0)) {
            if (p > 0) { p--; while (p > 0 && !/\s/.test(a[p - 1])) p--; }
            else { s--; while (s > 0 && !/\s/.test(a[a.length - s])) s--; }
        }
        var from = a.slice(p, a.length - s).trim(), to = b.slice(p, b.length - s).trim();
        if (!from) { from = a.trim(); to = b.trim(); }
        return { from: from, to: to };
    }
    function attPost(body) {
        body.csrf = window.CSRF_TOKEN || '';
        return fetch((window.MSA_ATT && window.MSA_ATT.base ? window.MSA_ATT.base : (window.BASE_URL || '')) + 'pages/att_rules.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(function (r) { return r.json(); });
    }
    function attAskPw(msg) { var pw = window.prompt(msg || 'كلمة سرّ التعديل الأساسي / Mot de passe :', ''); return pw === null ? null : pw; }
    function attSetNewPw(then) {
        var a = window.prompt('ما في كلمة سرّ بعد للتعديل الأساسي. اكتب كلمة سرّ جديدة (4 أحرف على الأقلّ) — المدير العام فقط:', ''); if (a === null) return;
        var b = window.prompt('أعد كتابة كلمة السرّ الجديدة:', ''); if (b === null) return;
        if (a !== b || a.trim().length < 4) { alert('الكلمتان مختلفتان أو قصيرتان — ما انحفظ شي'); return; }
        attPost({ action: 'set_pw', pw_old: '', pw_new: a }).then(function (j) { if (!j.ok) { alert('ما انحفظت كلمة السرّ: ' + (j.err || '')); return; } alert('✅ انحفظت كلمة السرّ'); if (then) then(a); });
    }
    function attRun(body, okMsg) {
        var pw = attAskPw(); if (pw === null) return;
        body.pw = pw;
        attPost(body).then(function (j) {
            if (j.ok) { alert(okMsg); location.reload(); return; }
            if (j.err === 'no_pw') { if (j.super) attSetNewPw(function (npw) { body.pw = npw; attPost(body).then(function (k) { if (k.ok) { alert(okMsg); location.reload(); } else alert('ما انحفظ: ' + (k.err || '')); }); }); else alert('ما في كلمة سرّ بعد — المدير العام يحدّدها من «كلمة سرّ التعديل الأساسي»'); return; }
            if (j.err === 'bad_pw') { alert('كلمة السرّ غلط — ما انحفظ شي'); return; }
            alert('ما انحفظ: ' + (j.err || ''));
        }).catch(function (e) { alert('انقطع الاتصال بالخادم: ' + e); });
    }
    window.msaAttKeep = function () {
        if (!attSnap) { alert('افتح «تعديل / كتابة» وغيّر الكلمة أوّلاً'); return; }
        var rules = [], seen = {};
        attSnap.forEach(function (sn) {
            if (!sn.node.isConnected) return;
            var now = (sn.node.nodeValue || '').replace(/[\s\u00A0]+/g, ' ').trim();
            if (now && now !== sn.val) { var d = attDiff(sn.val, now); if (d.from && d.from !== d.to && !seen[d.from]) { seen[d.from] = 1; rules.push(d); } }
        });
        if (!rules.length) { alert('ما في أي تعديل بالنصّ'); return; }
        if (!confirm('حفظ ' + rules.length + ' تعديل كنصّ أساسي لهذا النوع من الإفادات (' + (window.MSA_ATT.lang || 'ar') + ')؟' + String.fromCharCode(10) + rules.map(function (r) { return '«' + r.from + '» ⇒ «' + r.to + '»'; }).join(String.fromCharCode(10)) + String.fromCharCode(10) + 'بيطبّق تلقائياً على كل إفادة من هذا النوع من بعد هلّق.')) return;
        attRun({ action: 'save', type: window.MSA_ATT.type, lang: window.MSA_ATT.lang, rules: rules }, '✅ انحفظ كأساس — رح يطلع هيك بكل إفادة من هذا النوع');
    };
    window.msaAttRuleDelete = function (id) { if (!confirm('حذف هذا التعديل؟ ترجع الإفادة لنصّها الأصلي.')) return; attRun({ action: 'delete', id: id }, '✅ انحذف'); };
    window.msaAttRulePw = function () {
        var old = window.prompt('كلمة السرّ الحالية (اتركها فاضية إن لم تُحدَّد بعد):', ''); if (old === null) return;
        var a = window.prompt('كلمة السرّ الجديدة (4 أحرف على الأقلّ):', ''); if (a === null) return;
        var b = window.prompt('أعد كتابتها:', ''); if (b === null) return;
        if (a !== b || a.trim().length < 4) { alert('الكلمتان مختلفتان أو قصيرتان — ما تغيّر شي'); return; }
        attPost({ action: 'set_pw', pw_old: old, pw_new: a }).then(function (j) { alert(j.ok ? '✅ تغيّرت كلمة السرّ' : (j.err === 'bad_pw' ? 'كلمة السرّ الحالية غلط' : 'ما تغيّرت: ' + (j.err || ''))); });
    };
    var ppEditToggleBase = window.ppEditToggle;
    window.ppEditToggle = function (btn) {
        ppEditToggleBase(btn);
        if (!window.MSA_ATT) return;
        var on = document.body.classList.contains('pp-editing'), k = document.getElementById('msaKeepBtn'), eb = btn || document.getElementById('msaEditBtn');
        if (on) {
            attSnap = attSnapshot();
            if (!k && eb) { k = document.createElement('button'); k.type = 'button'; k.id = 'msaKeepBtn'; k.className = 'btn btn-sm btn-primary'; k.title = 'الكلمة/الجملة اللي عدّلتها تصير هي الأساسية بكل إفادة من هذا النوع — بكلمة سرّ'; k.innerHTML = '<i class="fas fa-floppy-disk"></i> احفظ كأساس / Garder comme base'; k.onclick = window.msaAttKeep; eb.insertAdjacentElement('afterend', k); }
            if (k) k.style.display = '';
        } else { attSnap = null; if (k) k.style.display = 'none'; }
    };
    function ppEditInit() { var b = document.getElementById('msaEditBtn'); if (b) b.style.display = ppEditTargets().length ? '' : 'none'; }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ppEditInit); else ppEditInit();
    window.addEventListener('load', ppEditInit);

    // ===== 📤 Excel/Word حقيقيان (2026-10-08 «بس نضغط إكسل وبدنا نحفظها عالدسك توب دغري عم يحفظها ويب») =====
    // HTML المنطقة المعروضة يُبعَث للخادم (pages/html_export.php) فيرجع ملف .xlsx/.docx حقيقياً يُنزَّل دغري
    // باسمه وامتداده — أوفيس يحفظه إكسل/وورد لا «صفحة ويب». الاتجاه يتبع المستند (عربي = من اليمين، فرنسي = من الشمال).
    // إن تعذّر الخادم يرجع للتصدير القديم (ملف HTML) حتى لا يُترك المستخدم بلا شيء.
    // اتجاه المستند نفسه لا الواجهة: أوّل جدول محتوى (أو ورقة المستند) وما فوقه حتى المنطقة — أوّل dir صريح
    // (الجداول العربية dir=rtl، لوائح الدولة الخمس dir=ltr بقراره)، وإلا نصّه: عربي أكثر من اللاتيني ⇒ من اليمين
    // 📊 (2026-10-10 مساءً «إكسل عربي لازم العامود A يكون من اليمين»): بلا اختيار صريح = من اليمين متى وُجد عربي بالمستند
    function docDir(a) {
        var dir = 'rtl';
        try {
            var start = null, tbs = a.querySelectorAll('table');
            for (var ti = 0; ti < tbs.length; ti++) if (tbs[ti].rows.length > 1 && !tbs[ti].closest('.no-print, .export-toolbar, form')) { start = tbs[ti]; break; }
            if (!start) { var cands = a.querySelectorAll('.official-doc, .doc-sheet, .xls-sheet, .land-report'); for (var pi = 0; pi < cands.length; pi++) if (!cands[pi].closest('.no-print, .export-toolbar, form')) { start = cands[pi]; break; } }
            var rlDir = (window.MSA_RL && window.MSA_RL.exportDir) ? window.MSA_RL.exportDir(a) : null; // 🌐 اختياره: عربي = يمين، فرنسي = شمال، الاثنان = جهته
            var hasAr = /[؀-ۿ]/.test(((start || a).textContent || '').slice(0, 20000));
            // 📄 (2026-10-24 p1 «اخترت اطبع وورد» — بطاقة الأستاذ بالفرنسي طلعت من اليمين) أوّل dir صريح من الجدول صعوداً حتى المنطقة يغلب «فيه عربي»
            var explicit = '', el = start; while (el && el !== a.parentElement) { var dv = el.getAttribute && el.getAttribute('dir'); if (dv === 'rtl' || dv === 'ltr') { explicit = dv; break; } el = el.parentElement; }
            // 📐 (2026-10-31 «آخر سطرين بلّشين بالآخر») الإفادات/المستندات ذات الترويسة (.scr-head/.word-head) لغتها واتجاهها من أزرارها هي (dir على المنطقة):
            //    إعداد لغة التقارير (فرنسي=شمال) كان يغلب فتُبنى الورقة ltr وتطلع أسطر الإفادة العربية القصيرة من الشمال
            var ownDoc = a.querySelector('.scr-head, .word-head'), aDir = a.getAttribute('dir');
            dir = (ownDoc && (aDir === 'rtl' || aDir === 'ltr')) ? aDir : (rlDir ? rlDir : (explicit ? explicit : (hasAr ? 'rtl' : 'ltr')));
        } catch (e) {}
        return dir;
    }
    function exportForm(format, title, extra) {
        var fd = new FormData();
        fd.append('format', format); fd.append('title', title || document.title || 'document');
        // 🔄 (2026-10-15 «بملف الإكسل ما عم يغير من أفقي لعمودي») الاتجاه بكل الصيغ من زرّ الاتجاه: تلقائي (الخادم يقرّر) أو مفروض
        fd.append('dir', docDir(area())); fd.append('landscape', window.msaOrientForced ? (window.msaOrientForced === 'landscape' ? '1' : '0') : 'auto'); fd.append('html', cleanHtml());
        // 🖼️ (2026-10-11 «حطّينا شعار المدرسة على الإفادة ليش ما عم يطلع بالوورد») الترويسة-الصورة خلفيةٌ على المنطقة نفسها (خارج innerHTML):
        //    تُبعَث للخادم (الرابط + الحشوة بالبكسل) فيضعها محرّك الـPDF خلفية الورقة، ويستعمل الوورد الترويسة البديلة .word-head
        try {
            var cs = getComputedStyle(area()), bg = cs.backgroundImage || '';
            var m = /url\(["']?([^"')]+)["']?\)/.exec(bg);
            if (m) { fd.set('lh_bg', new URL(m[1], document.baseURI).href); fd.set('lh_pad', [cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft].map(function (v) { return parseFloat(v) || 0; }).join(',')); }
        } catch (e) {}
        if (extra) Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); });
        return fd;
    }
    // يبعت HTML المنطقة للخادم (pages/html_export.php) ويرجع الملف الحقيقي {blob, name}
    function fetchFile(fd, fallbackName) {
        return fetch((window.BASE_URL || '') + 'pages/html_export.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) return r.text().then(function (t) { throw new Error('http ' + r.status + ' ' + String(t || '').slice(0, 300)); });
                var cd = r.headers.get('Content-Disposition') || '', m = /filename\*=UTF-8''([^;]+)/i.exec(cd), name = '';
                try { name = m ? decodeURIComponent(m[1]) : ''; } catch (e) { name = ''; }
                if (!name) name = fallbackName;
                return r.blob().then(function (b) { if (b.size < 200) throw new Error('empty'); return { blob: b, name: name }; });
            });
    }
    function saveBlob(r) {
        var url = URL.createObjectURL(r.blob), l = document.createElement('a');
        l.href = url; l.download = r.name; document.body.appendChild(l); l.click();
        setTimeout(function () { URL.revokeObjectURL(url); l.remove(); }, 1500);
    }
    // 📤 Excel/Word حقيقيان (2026-10-08): الخادم يبني .xlsx/.docx من HTML المنطقة المعروضة؛ إن تعذّر يرجع للتصدير القديم
    function officeExport(format, title) {
        var btn = document.activeElement && document.activeElement.tagName === 'BUTTON' ? document.activeElement : null;
        var old = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.textContent = '⏳ عم نجهّز الملف...'; }
        var done = function () { if (btn) { btn.innerHTML = old; btn.disabled = false; } };
        return fetchFile(exportForm(format, title), safeName(title) + '_' + stamp() + (format === 'docx' ? '.docx' : '.xlsx'))
            .then(function (r) { saveBlob(r); done(); })
            .catch(function (e) { try { console.error(e); } catch (_) {} done(); if (format === 'docx') window.ppWordLegacy(title); else window.ppExcelLegacy(title); });
    }
    window.ppExcel = function (title) { return officeExport('xlsx', title); };
    window.ppWord = function (title) { return officeExport('docx', title); };
    // 🖨️ (2026-10-11) PDF جاهز من محرّك الطباعة الرسمي على الخادم (mPDF) — للطباعة والحفظ والواتساب والإيميل.
    // pdf-save.js يستعمله لكل صفحة ليست قسائم. opts: {inline, title}. الاتجاه: تلقائي بعرض الجدول إلا إن قلبه المستخدم بزرّ الورقة.
    // ⏳ (2026-10-18 «ليش بس اكبس طباعة بتاخد وقت تتفتح») مؤشّر واضح وسط الشاشة طول ما الخادم عم يحضّر الورقة (طباعة/حفظ/واتساب/إيميل)
    window.msaPdfWait = function (on, txt) {
        var w = document.getElementById('msaPdfWait');
        if (!on) { if (w) w.remove(); return; }
        if (!w) {
            w = document.createElement('div'); w.id = 'msaPdfWait'; w.className = 'no-print';
            w.innerHTML = '<div class="pw-box"><i class="fas fa-spinner fa-spin"></i><b></b><small>أوّل مرّة بتاخد ثواني — بعدها نفس الورقة بتطلع فوراً<br>La première fois prend quelques secondes, ensuite c\'est immédiat</small></div>';
            document.body.appendChild(w);
        }
        w.querySelector('b').textContent = txt || 'عم نحضّر الورقة للطباعة… / Préparation de la page…';
    };
    window.msaServerPdf = function (opts) {
        opts = opts || {};
        var land = window.msaOrientForced ? (window.msaOrientForced === 'landscape' ? '1' : '0') : 'auto';
        var t = opts.title || docTitle();
        window.msaPdfWait(true, opts.inline ? 'عم نحضّر الورقة للطباعة… / Préparation de la page…' : 'عم نحضّر ملف PDF… / Préparation du PDF…');
        return fetchFile(exportForm('pdf', t, { landscape: land, disp: opts.inline ? 'inline' : 'attachment', fit: window.msaFitOne ? '1' : '0' }), safeName(t) + '_' + stamp() + '.pdf')
            .then(function (r) { window.msaPdfWait(false); return r; }, function (e) { window.msaPdfWait(false); throw e; });
    };
    // 📄 (2026-10-11) الجدول العريض: «أجزاء» (الافتراضي، خط 12 مع تكرار # والاسم بكل جزء) ⇄ «ورقة وحدة» (كل الأعمدة بصفحة واحدة بخط أصغر).
    //    الزرّ يظهر فقط حين يكون بالمستند جدول من 10 أعمدة وأكثر. لا يُحفَظ: كل صفحة تبدأ بالأجزاء.
    // 📄 (2026-10-15 «أي تقرير بدي اطبعو بدي العواميد كلّها نفس الصفحة… السطور ما في مشكلة صفحات عديدة») الافتراضي = ورقة وحدة:
    //    كل الأعمدة على عرض الورقة (أفقية إن لزم، بخط أصغر إن لزم) والسطور تمتدّ على صفحات؛ الزرّ يقلب إلى «أجزاء» لمن يريد.
    window.msaFitOne = false;   // 🔠 (2026-10-21 «الخطّ 12 حتى نقدر نقراه… أنا بختار العواميد») الافتراضي أجزاء بخطّ الشاشة لا تصغير
    function msaFitInit() {
        var b = document.getElementById('msaFitBtn'); if (!b) return;
        var sp0 = b.querySelector('span'); if (sp0) sp0.textContent = window.msaFitOne ? 'Une page / ورقة وحدة ⇄' : 'Parties / أجزاء ⇄';
        b.classList.toggle('btn-warning', !window.msaFitOne); b.classList.toggle('btn-light', window.msaFitOne);
        var wide = false;
        area().querySelectorAll('table').forEach(function (t) { if (t.closest('.no-print, .export-toolbar, .official-doc')) return; /* 🏛️ النماذج الرسمية بلا «أجزاء» */ for (var i = 0; i < t.rows.length && i < 4; i++) if (t.rows[i].cells.length >= 10) wide = true; });
        b.style.display = wide ? '' : 'none';
    }
    window.msaFitToggle = function (btn) {
        window.msaFitOne = !window.msaFitOne;
        var sp = btn.querySelector('span'); if (sp) sp.textContent = window.msaFitOne ? 'Une page / ورقة وحدة ⇄' : 'Parties / أجزاء ⇄';
        btn.classList.toggle('btn-warning', !window.msaFitOne); btn.classList.toggle('btn-light', window.msaFitOne);   // الأصفر = الوضع غير الافتراضي (أجزاء)
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', msaFitInit); else msaFitInit();
    window.addEventListener('load', msaFitInit);

    // ===== WhatsApp =====
    // ملاحظة: واتساب عبر الرابط يرسل نصاً فقط (لا ملفات). للمستندات: نزّل PDF/Excel ثم أرفقه.
    // نصّ المستند للإرسال: ملخّص waSummary إن وُجد، وإلا نصّ الإفادة نفسها (ppExportArea)
    function ppDocText() {
        var el = document.getElementById('waSummary');
        if (el && el.textContent.trim()) return el.textContent.trim();
        var area = document.getElementById('ppExportArea');
        if (area) return (area.innerText || area.textContent || '').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
        return '';
    }
    // ===== 📤 نافذة الإرسال الموحّدة (واتساب / إيميل) — «تكون شغالة ومظبوطة وسهلة الاستعمال» (2026-09-13) =====
    // واتساب عبر الرابط لا يرفق ملفات: النافذة تنزّل ملف الـPDF (msaPdfBlob من pdf-save.js) وتفتح المحادثة برابط حقيقي
    // (لا window.open بعد prompt — كان يُحجَب). الإيميل: يُرسَل من الخادم (إعدادات البريد) مع الـPDF مرفقاً.
    function shareModal(html) {
        var old = document.getElementById('ppShare'); if (old) old.remove();
        var w = document.createElement('div'); w.id = 'ppShare'; w.className = 'no-print';
        w.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px';
        w.innerHTML = '<div dir="rtl" style="background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:min(560px,100%);padding:18px 20px;font-family:inherit;position:relative">'
            + '<button type="button" data-x="1" style="position:absolute;top:8px;left:10px;border:0;background:none;font-size:22px;cursor:pointer;color:#64748b" title="إغلاق">&times;</button>' + html + '</div>';
        document.body.appendChild(w);
        function close() { w.remove(); }
        w.addEventListener('click', function (e) { if (e.target === w || e.target.getAttribute('data-x')) close(); });
        document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); } });
        return { el: w, close: close };
    }
    function escH(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    // 📧 (2026-10-12 «يروح على إيميل المدرسة تلقائياً وإذا بدّي أبعته لأستاذ يكون عندي خيار»): منتقي المستلِم المشترك لكل نوافذ الإيميل
    //    خيارات: 🏫 المدرسة (الافتراضي إن كان إيميلها مكتوباً بصفحة المدارس) · 👨‍🏫 الأستاذ المعروض (إن كان له إيميل بملفه) · ✍️ إيميل آخر
    function msaRecipHtml(inputId, to) {
        var sch = window.MSA_SCHOOL_EMAIL || '', tch = window.MSA_TEACHER_EMAIL || '';
        var def = sch ? 'school' : (tch ? 'teacher' : 'other');
        var val = def === 'school' ? sch : (def === 'teacher' ? tch : (to || ''));
        var h = '<div class="msaRecip" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;font-size:14px">';
        if (sch) h += '<label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="radio" name="msaRecip" value="school" data-mail="' + escH(sch) + '"' + (def === 'school' ? ' checked' : '') + '> 🏫 <b>للمدرسة</b> ' + escH(window.MSA_SCHOOL_NAME || '') + ' <span dir="ltr" style="color:#64748b">‹' + escH(sch) + '›</span></label>';
        if (tch) h += '<label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="radio" name="msaRecip" value="teacher" data-mail="' + escH(tch) + '"' + (def === 'teacher' ? ' checked' : '') + '> 👨‍🏫 <b>للأستاذ</b> ' + escH(window.MSA_TEACHER_NAME || '') + ' <span dir="ltr" style="color:#64748b">‹' + escH(tch) + '›</span></label>';
        else if (window.MSA_TEACHER_NAME) h += '<div style="color:#92400e;font-size:12.5px">👨‍🏫 الأستاذ ' + escH(window.MSA_TEACHER_NAME) + ' ما إله إيميل بملفه — اكتبه بملفه (تبويب العنوان) ليظهر هون.</div>';
        h += '<label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="radio" name="msaRecip" value="other"' + (def === 'other' ? ' checked' : '') + '> ✍️ <b>إيميل آخر</b></label></div>';
        h += '<input type="email" id="' + inputId + '" class="form-control" dir="ltr" value="' + escH(val) + '" placeholder="name@example.com" style="margin-bottom:8px">';
        return h;
    }
    function msaRecipWire(root, inputId) {
        var inp = root.querySelector('#' + inputId);
        Array.prototype.forEach.call(root.querySelectorAll('input[name="msaRecip"]'), function (r) {
            r.addEventListener('change', function () { if (r.value === 'other') { inp.value = ''; inp.focus(); } else { inp.value = r.getAttribute('data-mail') || ''; } });
        });
        inp.addEventListener('input', function () { var o = root.querySelector('input[name="msaRecip"][value="other"]'); var match = false;
            Array.prototype.forEach.call(root.querySelectorAll('input[name="msaRecip"][data-mail]'), function (r) { if (r.getAttribute('data-mail') === inp.value.trim()) { r.checked = true; match = true; } });
            if (!match && o) o.checked = true; });
        return inp;
    }
    // نافذة اختيار المستلِم فقط (للإفادات التي يولّد الخادم PDF لها): cb(email)
    window.msaAskEmail = function (title, defEmail, cb) {
        var m = shareModal('<div style="font-size:18px;font-weight:800;color:#1e3a5f;margin-bottom:6px"><i class="fas fa-envelope"></i> ' + escH(title || 'إرسال بالإيميل') + '</div>'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">إلى / À</label>' + msaRecipHtml('msaAskTo', defEmail)
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><button type="button" id="msaAskGo" class="btn btn-primary" style="font-weight:700"><i class="fas fa-paper-plane"></i> أرسل</button></div>'
            + '<div id="msaAskMsg" style="font-size:13.5px;margin-top:8px;color:#b91c1c"></div>');
        var inp = msaRecipWire(m.el, 'msaAskTo');
        m.el.querySelector('#msaAskGo').addEventListener('click', function () {
            var to = inp.value.trim();
            if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { m.el.querySelector('#msaAskMsg').textContent = 'اكتب إيميلاً صحيحاً'; inp.focus(); return; }
            m.close(); cb(to);
        });
    };
    function waNumber(v) {
        var num = String(v || '').replace(/[^0-9]/g, '');
        if (num.length === 8 && /^(03|70|71|76|78|79|81)/.test(num)) num = '961' + num.replace(/^0/, '');
        else if (num.length === 7 && /^(3|70|71|76|78|79|81)/.test(num)) num = '961' + num;
        else if (num.length === 8 && /^0/.test(num)) num = '961' + num.slice(1);
        return num;
    }
    function shortDoc() { var d = ppDocText(); return d.length > 900 ? d.slice(0, 900) + '…' : d; }
    // عنوان المستند الحقيقي للإرسال («عم ابعت رواتب عبرا بس عم يحطّ العنوان كشف رواتب سان مكسيم» 2026-09-13):
    // عنوان الورقة (.doc-head) + اسم المدرسة من الترويسة/نطاق المدارس + الفترة (أوّل شريحة ليست تاريخ الإصدار/العملة/الراتب يشمل)
    function docTitle(fallback) {
        var h = document.querySelector('.doc-head .dh-ar') || document.querySelector('.doc-head .dh-fr') || document.querySelector('#ppExportArea h1, #ppExportArea h2');
        var t = h ? h.textContent.trim() : (fallback || document.title || '').split(/[—|]/)[0].trim();
        var sc = document.querySelector('.letterhead .lh-name-ar') || document.querySelector('.report-scope') || document.querySelector('.slip-school, .school-name');
        var school = sc ? sc.textContent.replace(/^[\s\S]*?:/, '').replace(/\s+/g, ' ').trim() : '';
        var period = '';
        document.querySelectorAll('.doc-head .dh-chip').forEach(function (c) { var x = c.textContent.trim(); if (!period && !/^(صدر|العملة|الراتب يشمل|العدد)/.test(x)) period = x; });
        return t + (school ? ' — ' + school : '') + (period ? ' — ' + period : '');
    }
    function pdfBtnHandler(btn, after) {
        btn.disabled = true; var old = btn.innerHTML; btn.textContent = '⏳ عم نجهّز ملف الـPDF...';
        if (!window.msaPdfBlob) { btn.innerHTML = old; btn.disabled = false; window.print(); return; }
        window.msaPdfBlob().then(function (r) {
            var url = URL.createObjectURL(r.blob); var a = document.createElement('a'); a.href = url; a.download = r.name; document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1500);
            btn.innerHTML = '✅ نزل الملف: ' + escH(r.name); if (after) after(r);
        }).catch(function (e) { btn.innerHTML = old; btn.disabled = false; try { console.error(e); } catch (_) {} window.print(); });
    }

    // 📲 «بدي دغري بس اكبس واتس اب تنبعت ب د ف» (2026-10-03): الكبسة تجهّز الـPDF وتسلّمه مباشرةً لواتساب عبر
    // مشاركة النظام (navigator.share بالملف) — بلا تنزيل عالدسك توب وبلا إرفاق يدوي. المتصفّح الذي لا يشارك ملفات
    // (أو تعذّر تجهيز الملف) يرجع للنافذة القديمة (تنزيل + فتح المحادثة).
    function canSharePdf() {
        try {
            return !!(navigator.share && navigator.canShare && window.File
                && navigator.canShare({ files: [new File([new Blob(['%PDF-'], { type: 'application/pdf' })], 'a.pdf', { type: 'application/pdf' })] }));
        } catch (e) { return false; }
    }
    function waSendPdf(title, getPdf, fallback) {
        if (!canSharePdf()) { fallback(); return; }
        var m = shareModal(
            '<div style="font-size:18px;font-weight:800;color:#128C7E;margin-bottom:10px"><i class="fab fa-whatsapp"></i> إرسال عبر واتساب / Envoyer par WhatsApp</div>'
            + '<div id="ppWaMsg" style="font-size:14.5px;color:#334155;line-height:1.8;margin-bottom:10px">⏳ عم نجهّز ملف الـPDF...</div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            + '<button type="button" id="ppWaGo" class="btn" style="display:none;background:#25D366;color:#fff;font-weight:700;font-size:15px"><i class="fab fa-whatsapp"></i> ابعت الملف عالواتساب</button>'
            + '<button type="button" id="ppWaDl" class="btn btn-light" style="display:none">💾 نزّل الملف</button>'
            + '</div>');
        var msg = m.el.querySelector('#ppWaMsg'), go = m.el.querySelector('#ppWaGo'), dl = m.el.querySelector('#ppWaDl');
        getPdf().then(function (r) {
            var file = new File([r.blob], r.name, { type: 'application/pdf' });
            function ready(txt) { msg.textContent = txt; go.style.display = ''; dl.style.display = ''; }
            function send() {
                navigator.share({ files: [file], title: title }).then(function () {
                    msg.innerHTML = '<span style="color:#166534;font-weight:700">✅ تسلّم الملف لواتساب</span>';
                    setTimeout(m.close, 1500);
                }, function (e) {
                    // NotAllowedError = تجهيز الملف طوّل فانتهت صلاحية الكبسة ⇒ كبسة وحدة تكفي؛ AbortError = سكّر نافذة المشاركة
                    ready(e && e.name === 'AbortError' ? 'ما انبعت الملف — اكبس لتبعته:' : '✅ الملف جاهز: ' + r.name + ' — اكبس واختار واتساب:');
                });
            }
            go.addEventListener('click', send);
            dl.addEventListener('click', function () {
                var url = URL.createObjectURL(r.blob); var a = document.createElement('a'); a.href = url; a.download = r.name; document.body.appendChild(a); a.click();
                setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1500);
            });
            send();
        }).catch(function (e) { try { console.error(e); } catch (_) {} m.close(); fallback(); });
    }

    // ===== WhatsApp =====
    window.ppWhatsApp = function (title, phone) {
        waSendPdf(docTitle(title), function () {
            return window.msaPdfBlob ? window.msaPdfBlob() : Promise.reject(new Error('no pdf builder'));
        }, function () { ppWhatsAppManual(title, phone); });
    };
    // النافذة القديمة (تنزيل الملف ثم فتح المحادثة) — احتياط للمتصفّح الذي لا يشارك ملفات
    function ppWhatsAppManual(title, phone) {
        var t = docTitle(title);
        var text = t + '\n\n' + shortDoc();
        var m = shareModal(
            '<div style="font-size:18px;font-weight:800;color:#128C7E;margin-bottom:6px"><i class="fab fa-whatsapp"></i> إرسال عبر واتساب / Envoyer par WhatsApp</div>'
            + '<div style="font-size:13.5px;color:#475569;line-height:1.8;margin-bottom:10px">واتساب ما بيقبل إرفاق ملف من الرابط. الطريقة: <b>١</b> نزّل الملف PDF (بينزل عالكمبيوتر) ← <b>٢</b> افتح المحادثة ← ارفق الملف من واتساب (📎).</div>'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">رقم الواتساب / Numéro</label>'
            + '<input type="tel" id="ppWaNum" class="form-control" dir="ltr" value="' + escH(phone || '') + '" placeholder="03 123 456 أو 961…" style="margin-bottom:10px">'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            + '<button type="button" id="ppWaPdf" class="btn btn-primary" style="font-weight:700">💾 ١ — نزّل الملف PDF</button>'
            + '<a id="ppWaOpen" class="btn" target="_blank" rel="noopener" style="background:#25D366;color:#fff;font-weight:700" href="#"><i class="fab fa-whatsapp"></i> ٢ — افتح المحادثة</a>'
            + '</div><div id="ppWaHint" style="font-size:12.5px;color:#64748b;margin-top:8px"></div>');
        var num = m.el.querySelector('#ppWaNum'), open = m.el.querySelector('#ppWaOpen'), hint = m.el.querySelector('#ppWaHint');
        function upd() {
            var n = waNumber(num.value);
            open.href = 'https://wa.me/' + n + '?text=' + encodeURIComponent(text);
            hint.textContent = n ? ('بيفتح محادثة الرقم +' + n + ' مع نصّ المستند — وبعدين ارفق الملف من 📎') : 'بلا رقم: بيفتح واتساب لتختار جهة الاتصال ثم الصق النصّ وارفق الملف';
            if (!n) open.href = 'https://wa.me/?text=' + encodeURIComponent(text);
        }
        num.addEventListener('input', upd); upd(); num.focus();
        m.el.querySelector('#ppWaPdf').addEventListener('click', function () { pdfBtnHandler(this); });
    }

    // ===== WhatsApp + PDF (لحسابات المدارس) — نفس النافذة =====
    window.ppWhatsAppPdf = function (title, phone) { ppWhatsApp(title, phone); };

    // 🏛️ «تصريح باستخدام أجير ما مشي الحال» (2026-09-15): على شاشات النماذج الرسمية (ضمان/ر3) كانت أزرار الشريط
    // العلوي (PDF/Excel/Word/واتساب/إيميل) تعمل على صفحة الخيارات لا على النموذج نفسه. هذه الدالة تجعل الشريط
    // نفسه (مجموعة أزرار وحدة بالصفحة) يعمل على النموذج الرسمي المعبّأ بخيارات الشاشة الحالية.
    // o = {pdf, xlsx, target (مسار داخلي للنموذج HTML للإيميل/الواتساب), title, phone, email, base}
    window.msaOfficialToolbar = function (o) {
        var tb = document.querySelector('.export-toolbar'); if (!tb) return;
        tb.innerHTML = ''
            + '<a class="btn btn-sm btn-danger" href="' + escH(o.pdf) + '" target="_blank" rel="noopener"><i class="fas fa-print"></i> النموذج الرسمي — طباعة / PDF</a>'
            + (o.xlsx ? '<a class="btn btn-sm btn-success" href="' + escH(o.xlsx) + '"><i class="fas fa-file-excel"></i> Excel رسمي (معبّى) / Excel officiel</a>' : '')
            + '<button type="button" class="btn btn-sm" style="background:#25D366;color:#fff" id="msaOffWa"><i class="fab fa-whatsapp"></i> WhatsApp</button>'
            + '<button type="button" class="btn btn-sm btn-light" id="msaOffMail"><i class="fas fa-envelope"></i> Email</button>';
        tb.querySelector('#msaOffWa').addEventListener('click', function () {
            // النموذج الرسمي PDF من الخادم (حيث الأداة متوفّرة) يُسلَّم مباشرةً لواتساب؛ وإلا النافذة القديمة
            waSendPdf(o.title || 'formulaire', function () {
                // raw_pdf=1 (2026-10-27): أونلاين بلا LibreOffice يرجع PDF طبق الأصل من محرّك المدرسة بدل صفحة الطباعة
                return fetch(o.pdf + (o.pdf.indexOf('?') >= 0 ? '&' : '?') + 'raw_pdf=1', { credentials: 'same-origin' }).then(function (x) { return x.blob(); }).then(function (bl) {
                    if (!/pdf/i.test(bl.type)) throw new Error('not a pdf');
                    return { blob: bl, name: safeName(o.title || 'formulaire') + '.pdf' };
                });
            }, offWaManual);
        });
        function offWaManual() {
            var text =(o.title || '') + '\n' + location.origin + o.base + o.target;
            var m = shareModal(
                '<div style="font-size:18px;font-weight:800;color:#128C7E;margin-bottom:6px"><i class="fab fa-whatsapp"></i> إرسال عبر واتساب / Envoyer par WhatsApp</div>'
                + '<div style="font-size:13.5px;color:#475569;line-height:1.8;margin-bottom:10px">واتساب ما بيقبل إرفاق ملف من الرابط. الطريقة: <b>١</b> افتح النموذج واحفظه PDF، <b>٢</b> افتح المحادثة وارفق الملف من 📎.</div>'
                + '<label style="display:block;font-weight:700;margin-bottom:4px">رقم الواتساب / Numéro</label>'
                + '<input type="tel" id="ppWaNum" class="form-control" dir="ltr" value="' + escH(o.phone || '') + '" placeholder="03 123 456 أو 961…" style="margin-bottom:10px">'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
                + '<a class="btn btn-primary" target="_blank" rel="noopener" style="font-weight:700" href="' + escH(o.pdf) + '">💾 ١ — افتح النموذج واحفظه PDF</a>'
                + '<a id="ppWaOpen" class="btn" target="_blank" rel="noopener" style="background:#25D366;color:#fff;font-weight:700" href="#"><i class="fab fa-whatsapp"></i> ٢ — افتح المحادثة</a>'
                + '</div><div id="ppWaHint" style="font-size:12.5px;color:#64748b;margin-top:8px"></div>');
            var num = m.el.querySelector('#ppWaNum'), open = m.el.querySelector('#ppWaOpen'), hint = m.el.querySelector('#ppWaHint');
            function upd() {
                var n = waNumber(num.value);
                open.href = 'https://wa.me/' + (n || '') + '?text=' + encodeURIComponent(text);
                hint.textContent = n ? ('بيفتح محادثة الرقم +' + n + ' — وبعدين ارفق الملف من 📎') : 'بلا رقم: بيفتح واتساب لتختار المحادثة';
            }
            num.addEventListener('input', upd); upd(); num.focus();
        }
        tb.querySelector('#msaOffMail').addEventListener('click', function () {
            window.msaAskEmail('إرسال النموذج بالإيميل (PDF مرفق)', o.email || '', function (to) {
                window.location.href = o.base + 'pages/send_attestation.php?target=' + encodeURIComponent(o.target) + '&to=' + encodeURIComponent(to) + '&name=' + encodeURIComponent(o.title || 'formulaire');
            });
        });
    };

    // ===== Email — يُرسَل من الخادم مع الـPDF مرفقاً (pages/send_report.php + إعدادات البريد) =====
    window.ppEmail = function (title, to) {
        var t = docTitle(title);
        var m = shareModal(
            '<div style="font-size:18px;font-weight:800;color:#1e3a5f;margin-bottom:6px"><i class="fas fa-envelope"></i> إرسال بالإيميل مع الملف PDF / Envoyer par e-mail</div>'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">إلى / À</label>'
            + msaRecipHtml('ppEmTo', to)
            + '<label style="display:block;font-weight:700;margin-bottom:4px">الموضوع / Objet</label>'
            + '<input type="text" id="ppEmSub" class="form-control" value="' + escH(t) + '" style="margin-bottom:8px">'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">الرسالة / Message</label>'
            + '<textarea id="ppEmBody" class="form-control" rows="3" style="margin-bottom:10px">مرفق ' + escH(t) + ' بصيغة PDF.</textarea>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            + '<button type="button" id="ppEmSend" class="btn btn-primary" style="font-weight:700"><i class="fas fa-paper-plane"></i> أرسل مع الملف مرفقاً</button>'
            + '<button type="button" id="ppEmPdf" class="btn btn-light">💾 أو نزّل الملف فقط</button>'
            + '</div><div id="ppEmMsg" style="font-size:13.5px;margin-top:10px;line-height:1.7"></div>');
        var toEl = msaRecipWire(m.el, 'ppEmTo'), msg = m.el.querySelector('#ppEmMsg'), send = m.el.querySelector('#ppEmSend');
        if (!toEl.value) toEl.focus();
        m.el.querySelector('#ppEmPdf').addEventListener('click', function () { pdfBtnHandler(this); });
        send.addEventListener('click', function () {
            var to = toEl.value.trim();
            if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { msg.innerHTML = '<span style="color:#b91c1c">اكتب إيميلاً صحيحاً</span>'; toEl.focus(); return; }
            if (!window.msaPdfBlob) { msg.innerHTML = '<span style="color:#b91c1c">تعذّر تجهيز الملف — أعد تحميل الصفحة</span>'; return; }
            send.disabled = true; var old = send.innerHTML; send.textContent = '⏳ عم نجهّز الملف ونرسل...';
            window.msaPdfBlob().then(function (r) {
                var fd = new FormData();
                fd.append('csrf', window.CSRF_TOKEN || ''); fd.append('to', to);
                fd.append('subject', m.el.querySelector('#ppEmSub').value); fd.append('body', m.el.querySelector('#ppEmBody').value);
                fd.append('pdf', r.blob, r.name);
                var mb = r.blob.size / 1048576;
                // 📦 (2026-10-19) ملف كبير: رفع بتقدّم مرئي (XHR) — الكبير يروح برابط تنزيل من الموقع لا كمرفق
                return new Promise(function (resolve, reject) {
                    var x = new XMLHttpRequest();
                    x.open('POST', (window.BASE_URL || '') + 'pages/send_report.php', true);
                    x.withCredentials = true; x.timeout = 20 * 60 * 1000;
                    x.upload.onprogress = function (ev) { if (ev.lengthComputable) { var p = Math.round(ev.loaded * 100 / ev.total); send.textContent = (p < 100 ? '⏳ عم يرفع الملف ' + p + '% (' + mb.toFixed(1) + ' MB)' : '⏳ عم يرسل...'); } };
                    x.onload = function () { try { resolve(JSON.parse(x.responseText)); } catch (e) { reject(new Error('ردّ غير مفهوم من الخادم')); } };
                    x.onerror = function () { reject(new Error('انقطع الاتصال أثناء الرفع — حاول مرّة ثانية')); };
                    x.ontimeout = function () { reject(new Error('الرفع أخذ وقتاً طويلاً — حاول مرّة ثانية')); };
                    x.send(fd);
                });
            }).then(function (j) {
                if (j && j.ok) { msg.innerHTML = '<span style="color:#166534;font-weight:700">✅ ' + escH(j.msg || 'أُرسل') + '</span>' + (j.log ? ' <a href="' + escH(j.log) + '" target="_blank" style="font-weight:700">📬 سجلّ الإيميلات</a>' : ''); send.textContent = '✅ أُرسل'; }
                else { msg.innerHTML = '<span style="color:#b91c1c;font-weight:700">✕ ' + escH((j && j.msg) || 'تعذّر الإرسال') + '</span>' + (j && j.settings ? ' <a href="' + escH(j.settings) + '">إعدادات البريد</a>' : ''); send.innerHTML = old; send.disabled = false; }
            }).catch(function (e) { msg.innerHTML = '<span style="color:#b91c1c">تعذّر الإرسال: ' + escH(e && e.message) + '</span>'; send.innerHTML = old; send.disabled = false; });
        });
    };
    // ===== طباعة ملف مرفوع (صورة/PDF) عبر إطار مخفي — بلا نوافذ منبثقة =====
    window.ppPrintFile = function (url, isImg) {
        var frame = document.createElement('iframe');
        frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:800px;height:1100px;border:0;';
        document.body.appendChild(frame);
        var done = false;
        function go() {
            if (done) return; done = true;
            try { frame.contentWindow.focus(); frame.contentWindow.print(); }
            catch (e) { window.open(url, '_blank'); } // احتياط: افتح الملف ليطبعه يدوياً
        }
        if (isImg) {
            var doc = frame.contentWindow.document;
            doc.open();
            doc.write('<!DOCTYPE html><html><head><meta charset="utf-8">'
                + '<style>@page{size:A4;margin:8mm}html,body{margin:0;padding:0;text-align:center}img{max-width:100%}</style>'
                + '</head><body><img src="' + url + '"></body></html>');
            doc.close();
            var img = doc.images[0];
            if (img && img.complete) { setTimeout(go, 200); }
            else if (img) { img.onload = function () { setTimeout(go, 200); }; img.onerror = function () { window.open(url, '_blank'); }; }
            else { setTimeout(go, 600); }
        } else {
            frame.onload = function () { setTimeout(go, 500); };
            frame.src = url;
        }
        setTimeout(function () { try { document.body.removeChild(frame); } catch (e) {} }, 120000);
    };

    // 🖨️ (2026-10-03 «كل خيارات الطبع بأي صفحة»): النماذج الرسمية أخذت Excel/Word أيضاً — حيث للصفحة إكسل رسمي معبّى
    // (رابط format=xlsx خارج الشريط) يُزال الإكسل العامّ حتى لا يتكرّر الشيء نفسه.
    function dropGenericXls() {
        var g = document.querySelector('.export-toolbar [data-generic-xls]'); if (!g) return;
        var off = Array.prototype.some.call(document.querySelectorAll('#pageContent a[href*="format=xlsx"]'), function (a) { return !a.closest('.export-toolbar') || a !== g; });
        if (off) g.remove();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', dropGenericXls); else dropGenericXls();

})();
