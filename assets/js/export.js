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
        var css = '<style>'
            + '@page WordSection1{size:' + size + ';mso-page-orientation:' + orient + ';margin:1cm 1cm;}'
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
    window.ppWhatsApp = function (title, phone) {
        var txt = title || document.title;
        var doc = ppDocText();
        if (doc) txt += '\n\n' + doc;
        var def = phone ? String(phone).replace(/[^0-9]/g, '') : '';
        // اسأل المستخدم عن الرقم (معبّى مسبقاً برقم الأستاذ، ويقدر يكتب أي رقم)
        var input = window.prompt('رقم الواتساب المُرسَل إليه (رقم لبناني أو مع رمز البلد):', def);
        if (input === null) return; // أُلغي
        var num = String(input).replace(/[^0-9]/g, '');
        // أرقام لبنان: حوّل البادئة 03/70/71/76/78/79/81 إلى صيغة دولية 961 إن لزم
        if (num && num.length === 8 && /^(03|70|71|76|78|79|81)/.test(num)) num = '961' + num.replace(/^0/, '');
        window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(txt), '_blank');
    };

    // ===== WhatsApp + PDF (لحسابات المدارس) =====
    // واتساب عبر الرابط لا يرفق ملفات؛ لذا نفتح ملف الـPDF بتبويب جاهزاً ليُرفَق، ثم نفتح محادثة واتساب.
    window.ppWhatsAppPdf = function (title, phone, pdfUrl) {
        // 1) افتح ملف الـPDF أولاً (ضمن ضغطة المستخدم = لا يُحجَب) ليحفظه/يرفقه
        if (pdfUrl) window.open(pdfUrl, '_blank');
        else window.print(); // احتياط: لا PDF رسمي → حوار "حفظ كـ PDF"
        // 2) افتح محادثة واتساب (يسأل عن الرقم ثم يفتح المحادثة)
        ppWhatsApp(title, phone);
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

    // ===== Email =====
    window.ppEmail = function (title, to) {
        var doc = ppDocText();
        var body = doc ? doc : (title || '');
        // اسأل المستخدم عن الإيميل (معبّى مسبقاً بإيميل المدرسة، ويقدر يكتب أي إيميل)
        var input = window.prompt('البريد الإلكتروني المُرسَل إليه:', to || '');
        if (input === null) return; // أُلغي
        window.location.href = 'mailto:' + String(input).trim()
            + '?subject=' + encodeURIComponent(title || document.title)
            + '&body=' + encodeURIComponent(body);
    };
})();
