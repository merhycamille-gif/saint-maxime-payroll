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
        node.querySelectorAll('.no-print, .no-export, .export-toolbar, script, button, .btn, form.no-print, .word-head')
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
    window.ppPrint = function () { window.print(); };

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
    window.ppExcel = function (title) {
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
    window.ppWord = function (title) {
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
    function waNumber(v) {
        var num = String(v || '').replace(/[^0-9]/g, '');
        if (num.length === 8 && /^(03|70|71|76|78|79|81)/.test(num)) num = '961' + num.replace(/^0/, '');
        else if (num.length === 7 && /^(3|70|71|76|78|79|81)/.test(num)) num = '961' + num;
        else if (num.length === 8 && /^0/.test(num)) num = '961' + num.slice(1);
        return num;
    }
    function shortDoc() { var d = ppDocText(); return d.length > 900 ? d.slice(0, 900) + '…' : d; }
    function pdfBtnHandler(btn, after) {
        btn.disabled = true; var old = btn.innerHTML; btn.textContent = '⏳ عم نجهّز ملف الـPDF...';
        if (!window.msaPdfBlob) { btn.innerHTML = old; btn.disabled = false; window.print(); return; }
        window.msaPdfBlob().then(function (r) {
            var url = URL.createObjectURL(r.blob); var a = document.createElement('a'); a.href = url; a.download = r.name; document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1500);
            btn.innerHTML = '✅ نزل الملف: ' + escH(r.name); if (after) after(r);
        }).catch(function (e) { btn.innerHTML = old; btn.disabled = false; try { console.error(e); } catch (_) {} window.print(); });
    }

    // ===== WhatsApp =====
    window.ppWhatsApp = function (title, phone) {
        var t = title || document.title;
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
    };

    // ===== WhatsApp + PDF (لحسابات المدارس) — نفس النافذة =====
    window.ppWhatsAppPdf = function (title, phone) { ppWhatsApp(title, phone); };

    // ===== Email — يُرسَل من الخادم مع الـPDF مرفقاً (pages/send_report.php + إعدادات البريد) =====
    window.ppEmail = function (title, to) {
        var t = title || document.title;
        var m = shareModal(
            '<div style="font-size:18px;font-weight:800;color:#1e3a5f;margin-bottom:6px"><i class="fas fa-envelope"></i> إرسال بالإيميل مع الملف PDF / Envoyer par e-mail</div>'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">إلى / À</label>'
            + '<input type="email" id="ppEmTo" class="form-control" dir="ltr" value="' + escH(to || '') + '" placeholder="name@example.com" style="margin-bottom:8px">'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">الموضوع / Objet</label>'
            + '<input type="text" id="ppEmSub" class="form-control" value="' + escH(t) + '" style="margin-bottom:8px">'
            + '<label style="display:block;font-weight:700;margin-bottom:4px">الرسالة / Message</label>'
            + '<textarea id="ppEmBody" class="form-control" rows="3" style="margin-bottom:10px">مرفق ' + escH(t) + ' بصيغة PDF.</textarea>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            + '<button type="button" id="ppEmSend" class="btn btn-primary" style="font-weight:700"><i class="fas fa-paper-plane"></i> أرسل مع الملف مرفقاً</button>'
            + '<button type="button" id="ppEmPdf" class="btn btn-light">💾 أو نزّل الملف فقط</button>'
            + '</div><div id="ppEmMsg" style="font-size:13.5px;margin-top:10px;line-height:1.7"></div>');
        var toEl = m.el.querySelector('#ppEmTo'), msg = m.el.querySelector('#ppEmMsg'), send = m.el.querySelector('#ppEmSend');
        toEl.focus();
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
                return fetch((window.BASE_URL || '') + 'pages/send_report.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (x) { return x.json(); });
            }).then(function (j) {
                if (j && j.ok) { msg.innerHTML = '<span style="color:#166534;font-weight:700">✅ ' + escH(j.msg || 'أُرسل') + '</span>'; send.textContent = '✅ أُرسل'; }
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

})();
