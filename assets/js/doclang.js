// 🌐 لغة التقرير (2026-10-10 مساءً — بكلماته: «التقرير يكون عندي خيار: عربي لحالو أو فرنسي لحالو أو عربي وفرنسي… الإكسل إذا عربي
//    الأعمدة من اليمين وإذا فرنسي من الشمال وإذا عربي وفرنسي أنا بختار الجهة… العناوين مرتّبة مش معجّقة ومكرّرة… ما تقرب على البطاقة السنوية»)
//  • كل نصّ ثنائي «Français / عربي» (أو «FR — AR») بالمستند يُلَفّ مرّة واحدة بـspan (rl-fr / rl-sep / rl-ar) ثم يُظهَر/يُخفى بـstyle مباشر
//    (التصدير للإكسل/الوورد/الـPDF يقرأ الـDOM فيتبع الاختيار؛ display:none المباشر يُحذف بالتصدير).
//  • عناوين الأعمدة المكتوبة بلغة واحدة (أكثر التقارير بالعربي فقط) تُترجَم بالقاموس أدناه فيصير لها وجهان أيضاً.
//  • «عربي + فرنسي» بالرؤوس (th): الفرنسي سطر فوق والعربي سطر تحت (قاعدة الفرنسي أوّلاً) — بالإكسل خلية واحدة «FR / AR».
//  • الاتجاه: عربي = من اليمين، فرنسي = من الشمال، الاثنان = الجهة التي يختارها (على ورقة التقرير الموحّدة .doc-sheet فقط —
//    نماذج الدولة الرسمية .official-doc تبقى بنصّها واتجاهها: طبق الأصل).  • البطاقة السنوية (annual) لا تُمسّ.  • الاختيار محفوظ على الجهاز.
(function () {
    var PAGE = window.MSA_PAGE || '';
    if (PAGE === 'annual') return;
    var AR = /[؀-ۿ]/, LA = /[A-Za-zÀ-ſ]/;
    // ── قاموس عناوين الأعمدة والشارات (عربي ⇒ فرنسي) — يُستعمل بورقة التقرير الموحّدة فقط
    var DICT = {
        'المدرسة': 'École', 'الاسم': 'Nom', 'الاسم الثلاثي': 'Nom complet', 'الرمز': 'Code', 'الرقم': 'N°', 'الفئة': 'Catégorie', 'النوع': 'Type', 'الوظيفة': 'Fonction',
        'الحالة': 'Statut', 'الهاتف': 'Téléphone', 'الولادة': 'Naissance', 'تاريخ الولادة': 'Date de naissance', 'مكان الولادة': 'Lieu de naissance', 'الجنس': 'Sexe',
        'الدخول': 'Embauche', 'تاريخ الدخول': 'Date d’embauche', 'الترك': 'Départ', 'تاريخ الترك': 'Date de départ', 'اسم الأب': 'Nom du père', 'اسم الأم': 'Nom de la mère',
        'رقم الضمان': 'N° CNSS', 'الرقم المالي': 'N° Finances', 'رقم المالية': 'N° Finances', 'رقم الصندوق': 'N° Caisse', 'رقم السجل': 'N° registre',
        'الدرجة': 'Échelon', 'درجة/نصف راتب': 'Échelon / demi-salaire', 'قيمة الدرجة': 'Valeur de l’échelon', 'التدرّج': 'Échelon',
        'أساس الراتب': 'Salaire de base', 'الراتب الأساسي': 'Salaire de base', 'الأساس': 'Base', 'الراتب بعد التدرّج': 'Salaire après échelon', 'الراتب المركّب': 'Salaire composé',
        'الأجر الإضافي': 'Supplément', 'الإضافي': 'Supplément', 'مكافأة ومساعدة': 'Prime & aide', 'المكافأة': 'Prime', 'المساعدة': 'Aide', 'النقل': 'Transport', 'تعويض النقل': 'Indemnité de transport',
        'الراتب': 'Salaire', 'الراتب الشهري': 'Salaire mensuel', 'الراتب السنوي': 'Salaire annuel', 'الصافي': 'Net', 'صافي الراتب': 'Net à payer', 'الإجمالي': 'Total', 'الإجمالي المتوجب': 'Total dû', 'المتوجب': 'Dû',
        'المجموع': 'Total', 'المجموع العام': 'Total général', 'المبلغ': 'Montant', 'القيمة': 'Valeur', 'النسبة': 'Taux', 'المحسومات': 'Retenues', 'مجموع المحسومات': 'Total des retenues',
        'الضريبة': 'Impôt', 'ضريبة الدخل': 'Impôt sur le revenu', 'الراتب الخاضع للضريبة': 'Salaire imposable', 'التنزيل العائلي': 'Abattement familial', 'عدد الأشخاص': 'Nombre de personnes',
        'التعويضات العائلية': 'Allocations familiales', 'التعويض العائلي': 'Allocation familiale', 'عدد الأولاد': 'Nombre d’enfants', 'الأولاد': 'Enfants', 'الزوج/الزوجة': 'Conjoint',
        'الضمان': 'CNSS', 'وعاء الضمان': 'Assiette CNSS', 'الأجير': 'Salarié', 'حصّة الأجير': 'Part salarié', 'حصّة المدرسة': 'Part école', 'المرض والأمومة': 'Maladie & maternité',
        'الصندوق': 'Caisse', 'صندوق التعويضات': 'Caisse des indemnités', 'نهاية الخدمة': 'Fin de service', 'التعويض': 'Indemnité', 'المساهمة': 'Contribution',
        'الشهر': 'Mois', 'السنة': 'Année', 'السنة الدراسية': 'Année scolaire', 'من شهر': 'Du mois', 'إلى شهر': 'Au mois', 'الأشهر المدفوعة': 'Mois payés', 'الأشهر': 'Mois', 'الفترة': 'Période',
        'البند': 'Rubrique', 'الملاحظات': 'Notes', 'ملاحظات': 'Notes', 'التاريخ': 'Date', 'العملة': 'Devise', 'سعر الصرف': 'Taux de change', 'المدفوع': 'Payé', 'الباقي': 'Reste',
        'الملاك': 'Titulaires', 'المتعاقدون': 'Contractuels', 'الموظفون': 'Employés', 'الأساتذة': 'Enseignants', 'الساعات': 'Heures', 'عدد الساعات': 'Nombre d’heures',
        'العدد': 'Nombre', 'عدد': 'Nombre', 'المدارس': 'Écoles', 'كل المدارس': 'Toutes les écoles', 'الكل': 'Tous', 'التوقيع': 'Signature', 'الإمضاء': 'Signature', 'ختم': 'Cachet',
        // كلمات «الراتب يشمل» وشارات العنوان
        'الأساسي': 'Base', 'المعتمد': 'adopté', 'لأصحاب النسبة بالسعر الرسمي': 'pour les pourcentages au taux officiel', 'ليرة فقط': 'L.L seulement', 'دولار فقط': 'USD seulement', 'الليرة والدولار': 'L.L et USD',
        'ل.ل.': 'L.L', 'ل.ل': 'L.L', 'موظفاً': 'employés', 'موظف': 'employé', 'أستاذاً': 'enseignants', 'أستاذ': 'enseignant', 'الملاك والمتعاقدون': 'Titulaires et contractuels',
        // الأشهر (الاتجاهان)
        'درجة / نصف راتب إلى الصندوق': 'Échelon / ½ salaire à la Caisse', 'حصّة الشهر': 'part du mois', 'بعد حسم التنزيل': 'après abattement', 'بند': 'rubriques', 'سعر كل شهر': 'taux de chaque mois', 'آخر سعر': 'dernier taux',
        'الملف المالي مكتمل': 'dossier financier complet', 'غير مكتمل': 'incomplet', 'مكتمل': 'complet', 'الداخلون': 'entrés', 'إلى الصندوق': 'à la Caisse', 'نصف راتب': 'demi-salaire', 'درجة': 'échelon',
        'كانون الثاني': 'Janvier', 'شباط': 'Février', 'آذار': 'Mars', 'نيسان': 'Avril', 'أيار': 'Mai', 'حزيران': 'Juin', 'تموز': 'Juillet', 'آب': 'Août', 'أيلول': 'Septembre', 'تشرين الأول': 'Octobre', 'تشرين الثاني': 'Novembre', 'كانون الأول': 'Décembre'
    };
    var RDICT = {}; for (var k0 in DICT) if (!RDICT[DICT[k0]]) RDICT[DICT[k0]] = k0;
    DICT['صدر بتاريخ'] = 'Émis le'; DICT['الراتب يشمل'] = 'Salaire inclut'; DICT['سعر الصرف المعتمد'] = 'Taux de change adopté'; DICT['الراتب بعد التدرّج لأصحاب النسبة بالسعر الرسمي'] = 'Salaire après échelon (pourcentage) au taux officiel';
    function kind(s) { s = s.trim(); if (!s) return null; if (AR.test(s)) return 'ar'; if (LA.test(s)) return 'fr'; return null; }
    function norm(s) { return s.replace(/\s+/g, ' ').replace(/[:：]\s*$/, '').trim(); }
    function splitPair(t) {
        var seps = [/\s\/\s/g, /\s[—–]\s/g], pass, si, m, L, R, kl, kr;
        for (pass = 0; pass < 2; pass++) for (si = 0; si < seps.length; si++) {
            var re = new RegExp(seps[si].source, 'g');
            while ((m = re.exec(t))) {
                L = t.slice(0, m.index); R = t.slice(m.index + m[0].length); kl = kind(L); kr = kind(R);
                if (!kl || !kr || kl === kr) continue;
                var strict = (kl === 'fr' ? !AR.test(L) && !LA.test(R) : !AR.test(R) && !LA.test(L));
                if (pass === 0 && !strict) continue;
                var suf = ''; var mm = /([:：]\s*)$/.exec(R); if (mm) { suf = mm[1]; R = R.slice(0, -mm[1].length); }
                return kl === 'fr' ? { fr: L, ar: R, suf: suf } : { fr: R, ar: L, suf: suf };
            }
        }
        return null;
    }
    // ترجمة جملة مركّبة قطعةً قطعة (فواصل: + · — : ، والمسافات حول الأرقام/الرموز) — إن بقيت كلمة عربية بلا ترجمة ⇒ لا ترجمة (لا نصف جملة)
    // ترجمة جملة مركّبة: مسح من اليسار — المقاطع غير اللغوية (أرقام/رموز/اللغة الأخرى) تُنسخ، وكل كلمة من اللغة المصدر تُطابَق بأطول مفتاح بالقاموس؛
    // كلمة بلا ترجمة ⇒ لا ترجمة للجملة كلّها (لا نصف جملة).
    var KEYS = {}; function keysOf(map) { var id = map === DICT ? "d" : "r"; if (!KEYS[id]) KEYS[id] = Object.keys(map).sort(function (x, y) { return y.length - x.length; }); return KEYS[id]; }
    function translate(n, map, otherAlpha) {
        if (map[n] !== undefined) return map[n];
        var keys = keysOf(map), out = "", i = 0;
        while (i < n.length) {
            var ch = n.charAt(i);
            if (!otherAlpha.test(ch)) { out += ch; i++; continue; }
            var best = "";
            for (var k = 0; k < keys.length; k++) { var key = keys[k]; if (key.length <= best.length) break; if (n.substr(i, key.length) === key) { var after = n.charAt(i + key.length); if (after === "" || !otherAlpha.test(after)) { best = key; break; } } }
            if (!best) return null;
            out += map[best]; i += best.length;
        }
        return out;
    }
    function dictPair(t) { // نصّ بلغة واحدة ⇒ وجهه الآخر من القاموس
        var n = norm(t), suf = /[:：]\s*$/.test(t.trim()) ? ':' : '', k = kind(n); if (!k) return null;
        if (k === 'ar') { var fr = translate(n, DICT, AR); return fr !== null ? { ar: n, fr: fr, suf: suf, dict: 'ar' } : null; }
        var ar = translate(n, RDICT, LA); return ar !== null ? { ar: ar, fr: n, suf: suf, dict: 'fr' } : null;
    }
    function lead(t) { var m = /^(\s*)/.exec(t); return m ? m[1] : ''; }
    function trail(t) { var m = /(\s*)$/.exec(t); return m ? m[1] : ''; }
    var roots = function () { return document.querySelectorAll('#ppExportArea, .doc-sheet, .official-doc, .land-report, .xls-sheet'); };
    var SKIP = /^(SCRIPT|STYLE|INPUT|TEXTAREA|SELECT|OPTION|BUTTON)$/;
    function mkPair(tn, pr, inTh) {
        var t = tn.nodeValue;
        var sp = document.createElement('span'); sp.className = 'rl-pair' + (inTh ? ' rl-th' : '') + (pr.dict ? ' rl-dict rl-orig-' + pr.dict : '');
        var fr = document.createElement('span'); fr.className = 'rl-fr'; fr.textContent = pr.fr.trim();
        var sep = document.createElement('span'); sep.className = 'rl-sep'; sep.textContent = ' / ';
        var ar = document.createElement('span'); ar.className = 'rl-ar'; ar.textContent = pr.ar.trim();
        sp.appendChild(document.createTextNode(lead(t))); sp.appendChild(fr); sp.appendChild(sep); sp.appendChild(ar);
        if (pr.suf) sp.appendChild(document.createTextNode(pr.suf)); sp.appendChild(document.createTextNode(trail(t)));
        tn.parentNode.replaceChild(sp, tn);
    }
    function wrapAll() {
        roots().forEach(function (root) {
            if (root.__rlDone || root.closest('.rl-done')) return; root.__rlDone = true; root.classList.add('rl-done');
            var official = root.classList.contains('official-doc') || !!root.closest('.official-doc');
            root.querySelectorAll('.dh-fr').forEach(function (e) { e.classList.add('rl-fr', 'rl-block'); });
            root.querySelectorAll('.dh-ar').forEach(function (e) { e.classList.add('rl-ar', 'rl-block'); });
            var tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: function (n) {
                if (!n.nodeValue || !n.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                var p = n.parentElement; if (!p || SKIP.test(p.tagName) || p.closest('.no-print, .no-export, .export-toolbar, .rl-pair, script, style, select, .official-doc .rl-keep')) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT; } });
            var nodes = [], n; while ((n = tw.nextNode())) nodes.push(n);
            nodes.forEach(function (tn) {
                var t = tn.nodeValue, p = tn.parentElement, inTh = !!p.closest('th'), pr = splitPair(t);
                if (!pr && !official && (inTh || p.closest('.dh-chip, .dh-meta, caption, .doc-title, .doc-subtitle, label, thead, .report-scope'))) pr = dictPair(t); // عنوان بلغة واحدة ⇒ القاموس (ورقة التقرير فقط)
                if (pr) mkPair(tn, pr, inTh);
            });
            // «FR<br>AR» داخل عنصر واحد
            var INL = /^(SMALL|SPAN|B|I|EM|STRONG|SUB|SUP)$/;
            root.querySelectorAll('th, td, label, h1, h2, h3, h4, h5, p, div, span, strong, b, small').forEach(function (el) {
                if (el.closest('.rl-pair') || el.querySelector('.rl-pair') || el.classList.contains('rl-fr') || el.classList.contains('rl-ar')) return;
                var groups = [[]], brs = [], ok = true;
                for (var i = 0; i < el.childNodes.length; i++) {
                    var c = el.childNodes[i];
                    if (c.nodeType === 3) { if (c.nodeValue.trim()) groups[groups.length - 1].push(c); }
                    else if (c.nodeType === 1 && c.tagName === 'BR') { brs.push(c); groups.push([]); }
                    else if (c.nodeType === 1 && INL.test(c.tagName) && !c.querySelector('br, .rl-pair') && (c.textContent || '').trim()) groups[groups.length - 1].push(c);
                    else { ok = false; break; }
                }
                if (!ok || groups.length !== 2 || brs.length !== 1 || !groups[0].length || !groups[1].length) return;
                var txt = function (g) { return g.map(function (x) { return x.nodeType === 3 ? x.nodeValue : x.textContent; }).join(' '); };
                var k1 = kind(txt(groups[0])), k2 = kind(txt(groups[1])); if (!k1 || !k2 || k1 === k2) return;
                var mark = function (g, k) { if (g.length === 1 && g[0].nodeType === 1) { g[0].classList.add('rl-' + k); return; } var s = document.createElement('span'); s.className = 'rl-' + k; el.insertBefore(s, g[0]); g.forEach(function (x) { s.appendChild(x); }); };
                mark(groups[0], k1); mark(groups[1], k2);
                brs[0].classList.add('rl-sep', 'rl-br');
            });
        });
    }
    var state = { lang: 'both', side: '' };
    try { state.lang = localStorage.getItem('msa_rl') || 'both'; state.side = localStorage.getItem('msa_rs') || ''; } catch (e) {}
    if (['ar', 'fr', 'both'].indexOf(state.lang) < 0) state.lang = 'both';
    function effSide() { return state.lang === 'ar' ? 'rtl' : state.lang === 'fr' ? 'ltr' : (state.side === 'rtl' || state.side === 'ltr' ? state.side : ''); }
    function apply() {
        wrapAll();
        var L = state.lang, body = document.body;
        body.classList.remove('rl-ar', 'rl-fr', 'rl-both'); body.classList.add('rl-' + L);
        roots().forEach(function (root) {
            // «الاثنان»: الأزواج المصنوعة بالقاموس (شارات/عناوين كانت بلغة واحدة) تبقى بلغتها الأصلية خارج رؤوس الجداول — لا تكديس
            var dictOnly = function (e) { var p = e.parentElement; return L === 'both' && p.classList.contains('rl-dict') && !p.classList.contains('rl-th'); };
            root.querySelectorAll('.rl-fr').forEach(function (e) { e.style.display = (L === 'ar' || (dictOnly(e) && !e.parentElement.classList.contains('rl-orig-fr'))) ? 'none' : (L === 'both' && e.parentElement.classList.contains('rl-th') ? 'block' : ''); });
            root.querySelectorAll('.rl-ar').forEach(function (e) { e.style.display = (L === 'fr' || (dictOnly(e) && !e.parentElement.classList.contains('rl-orig-ar'))) ? 'none' : (L === 'both' && e.parentElement.classList.contains('rl-th') ? 'block' : ''); });
            root.querySelectorAll('.rl-sep').forEach(function (e) {
                if (L !== 'both' || dictOnly(e)) { e.style.cssText = 'display:none'; return; }
                if (e.classList.contains('rl-br')) { e.style.cssText = ''; return; }
                // رأس ثنائي: الفاصل يبقى بالنصّ (للإكسل «FR / AR» بخلية واحدة) لكن بلا ارتفاع على الشاشة
                e.style.cssText = e.parentElement.classList.contains('rl-th') ? 'display:block;height:0;overflow:hidden;font-size:0;line-height:0' : '';
            });
            // الاتجاه — ورقة التقرير الموحّدة فقط (نماذج الدولة تبقى)
            var side = effSide();
            var sheets = root.classList.contains('doc-sheet') ? [root] : Array.prototype.slice.call(root.querySelectorAll('.doc-sheet'));
            if (!root.classList.contains('official-doc') && !root.querySelector('.official-doc') && !sheets.length && root.querySelector('table')) sheets = [root];
            sheets.forEach(function (sh) {
                if (sh.__rlDir === undefined) sh.__rlDir = { dir: sh.getAttribute('dir'), ltr: sh.classList.contains('doc-ltr') };
                if (side) { sh.setAttribute('dir', side); sh.classList.toggle('doc-ltr', side === 'ltr'); sh.querySelectorAll('table').forEach(function (t) { if (t.__rlDir === undefined) t.__rlDir = t.getAttribute('dir'); t.setAttribute('dir', side); }); }
                else { if (sh.__rlDir.dir) sh.setAttribute('dir', sh.__rlDir.dir); else sh.removeAttribute('dir'); sh.classList.toggle('doc-ltr', sh.__rlDir.ltr); sh.querySelectorAll('table').forEach(function (t) { if (t.__rlDir === undefined) return; if (t.__rlDir) t.setAttribute('dir', t.__rlDir); else t.removeAttribute('dir'); }); }
            });
        });
        window.MSA_RL = { lang: L, side: effSide(), exportDir: function (area) {
            if (!area) return null; if (area.classList.contains('official-doc') || area.querySelector('.official-doc')) return null; return effSide() || null; } };
        document.querySelectorAll('.rl-chip').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-rl') === L); });
        document.querySelectorAll('.rl-side').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-rs') === effSide()); });
        document.querySelectorAll('.rl-sides').forEach(function (g) { g.style.display = (L === 'both') ? '' : 'none'; });
        // عنوان الطباعة المحقون بالجدول يُبنى من جديد بلغة العرض
        if (window.msaInjectPrintTitles) { document.querySelectorAll('.pr-title-row').forEach(function (r) { r.remove(); }); try { window.msaInjectPrintTitles(); } catch (e) {} }
        if (window.msaRefreshStickyHeads) try { window.msaRefreshStickyHeads(); } catch (e) {}
    }
    window.msaRlText = function (s) { // لنصوص تُبنى بالسكربت (عنوان الطباعة): نفس القاعدة
        var pr = splitPair(String(s || '')); if (!pr) return s; var L = state.lang;
        return (L === 'ar' ? pr.ar : L === 'fr' ? pr.fr : pr.fr + ' / ' + pr.ar) + (pr.suf || '');
    };
    function setLang(l) { state.lang = l; try { localStorage.setItem('msa_rl', l); document.cookie = 'msa_rl=' + l + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {} apply(); }
    function setSide(s) { state.side = s; try { localStorage.setItem('msa_rs', s); document.cookie = 'msa_rs=' + s + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {} apply(); }
    document.addEventListener('click', function (e) {
        var c = e.target.closest('.rl-chip'); if (c) { e.preventDefault(); setLang(c.getAttribute('data-rl')); return; }
        var s = e.target.closest('.rl-side'); if (s) { e.preventDefault(); setSide(s.getAttribute('data-rs')); }
    });
    if (roots().length) apply(); else document.querySelectorAll('.rl-chips').forEach(function (g) { g.style.display = 'none'; });
    window.msaRlApply = apply; window.msaRlTranslate = function (n) { return translate(n, DICT, AR); }; window.msaRlDict = DICT;
})();
