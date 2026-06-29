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

    // نسخة نظيفة من المحتوى بدون أزرار/عناصر لا تُصدَّر
    function cleanHtml() {
        var node = area().cloneNode(true);
        node.querySelectorAll('.no-print, .no-export, .export-toolbar, script, button, .btn, form.no-print')
            .forEach(function (n) { n.remove(); });
        return node.innerHTML;
    }

    function download(filename, content, mime) {
        var blob = new Blob(['﻿' + content], { type: mime + ';charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 800);
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
        download(safeName(title) + '_' + stamp() + '.doc', head + cleanHtml() + '</div></body></html>',
            'application/msword');
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
