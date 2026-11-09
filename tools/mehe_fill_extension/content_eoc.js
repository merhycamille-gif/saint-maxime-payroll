/* سكربت صفحات بوّابة صندوق التعويضات www.caisseindemnite.com: يستقبل بيان البرنامج (JSON من pages/eoc_portal.php?export=json)
   ويطابق سطوره مع سطور البيان المفتوح على الموقع (بالرقم المالي ثمّ بالاسم)، ثمّ:
   - «مراجعة»: مَن ناقص على الموقع ومَن زائد فيه.
   - «تعبئة»: يعبّئ النموذج المفتوح (إضافة أو تعديل) بالأستاذ المناسب — والمستخدم يكبس «حفظ».
   - «ترحيل تلقائي»: يعبّئ ويحفظ سطراً سطراً (الناقصين فقط أو الكل مع تحديث الموجودين) مع شريط تقدّم وزرّ إيقاف؛
     حفظ السطر على الموقع قابل للتعديل والحذف لاحقاً (ليس إرسالاً نهائياً). لا يعتمد ولا يقفل أيّ بيان. */
(function () {
  if (window.__msaEocFill) return; window.__msaEocFill = true;
  const norm = (s) => String(s || '').replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/[ًٌٍَُِّْـ]/g, '').replace(/\s+/g, ' ').trim();
  const skel = (s) => norm(s).replace(/[اويءآ]/g, '');
  const digits = (v) => String(v === null || v === undefined ? '' : v).replace(/[^\d.]/g, '');
  const fire = (el, types) => (types || ['input', 'change']).forEach((t) => el.dispatchEvent(new Event(t, { bubbles: true })));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const byName = (n, root) => (root || document).querySelector('[name="' + n + '"]');
  const visible = (el) => !!el && el.offsetParent !== null && getComputedStyle(el).display !== 'none';

  /* ---- أيّ صفحة نحن؟ ---- */
  const page = () => {
    const p = location.pathname;
    if (/\/my\/roster\/\d+/.test(p)) return 'roster';
    if (/\/my\/financial\/\d+/.test(p)) return 'financial';
    if (/\/my\/general\b/.test(p)) return 'general_list';
    if (/\/my\/financial\b/.test(p)) return 'financial_list';
    return 'other';
  };
  const baseUrl = () => location.origin + location.pathname.replace(/\/+$/, '');

  /* ---- سطور الجدول على الموقع ---- */
  function siteRows() {
    const t = document.querySelector('table.sp-data'); if (!t) return [];
    const heads = Array.from(t.querySelectorAll('thead th')).map((th) => norm(th.textContent));
    const ix = (lb) => heads.findIndex((h) => h === norm(lb));
    const iNo = ix('الرقم المالي'), iName = ix('الاسم والشهرة'), iType = ix('ملاك/متعاقد'), iFa = ix('اسم الاب');
    return Array.from(t.querySelectorAll('tbody tr')).map((tr) => {
      const tds = tr.querySelectorAll('td'); if (tds.length < 3) return null;
      const a = tr.querySelector('a[href*="edit="]');
      const m = a ? (a.getAttribute('href') || '').match(/[?&]edit=(\d+)/) : null;
      return { tr, no: iNo >= 0 ? digits(tds[iNo].textContent) : '', name: iName >= 0 ? norm(tds[iName].textContent) : '', type: iType >= 0 ? norm(tds[iType].textContent) : '', father: iFa >= 0 ? norm(tds[iFa].textContent) : '', editHref: a ? a.href : '', lineId: m ? m[1] : '' };
    }).filter(Boolean);
  }
  function matchRow(line, rows) {
    const no = digits(line.teacher_no); const nm = norm(line.teacher_name);
    let r = no ? rows.find((x) => x.no === no) : null;
    if (!r && nm) r = rows.find((x) => x.name === nm);
    if (!r && nm) { const c = rows.filter((x) => skel(x.name) === skel(nm)); if (c.length === 1) r = c[0]; }
    if (!r && nm) { const k2 = nm.split(' ').slice(0, 2).join(' '); const c = rows.filter((x) => x.name.indexOf(k2) === 0 || nm.indexOf(x.name.split(' ').slice(0, 2).join(' ')) === 0); if (c.length === 1) r = c[0]; }
    // 🤝 (2026-11-09 «لائحة البرنامج هي الصحّ»): الشهرة مكتوبة بشكل مختلف على الموقع — نفس الاسم الأوّل ونفس اسم الأب (ولا رقمان ماليان مختلفان) وواحد فقط ⇒ هو نفسه؛ اسمه يُصحَّح كالبرنامج عند الحفظ
    if (!r && nm) { const f1 = skel(nm.split(' ')[0]); const fa = skel(norm(L_father(line))); if (f1 && fa) { const c = rows.filter((x) => skel(x.name.split(' ')[0]) === f1 && skel(x.father || '') === fa && (!x.no || !no || x.no === no)); if (c.length === 1) r = c[0]; } }
    return r || null;
  }
  function L_father(line) { return line && line.father_name ? line.father_name : ''; }

  /* ---- تعبئة نموذج البيان العام ---- */
  function setSel(sel, value, label) {
    if (!sel) return false;
    const v = String(value || '');
    if (v && Array.from(sel.options).some((o) => o.value === v)) { sel.value = v; fire(sel, ['change']); return true; }
    if (label) { const want = norm(label); const o = Array.from(sel.options).find((x) => norm(x.textContent) === want); if (o) { sel.value = o.value; fire(sel, ['change']); return true; } }
    return false;
  }
  function setIn(el, v) { if (!el) return false; el.value = v === null || v === undefined ? '' : String(v); fire(el, ['input', 'change']); return true; }
  function rosterForm() { const f = document.querySelector('form[data-sp-roster-form]'); return f && visible(f) ? f : null; }
  function rosterFormOpenFor() { const f = rosterForm(); if (!f) return null; const id = (byName('line_id', f) || {}).value || ''; return { form: f, lineId: id, isNew: !id }; }
  // 🔢 (2026-11-09 «أرقام مالية الصندوق الموجودة على الموقع هي الصحّ، أرقام البرنامج ما شيّكت عليها»): keepNo ⇒ سطر موجود: رقم الموقع يبقى كما هو
  function fillRoster(f, L, keepNo) {
    const notes = []; let n = 0;
    const ok = (b, what) => { if (b) n++; else notes.push('لم تُعبَّأ: ' + what); };
    ok(setSel(byName('employment_type', f), L.employment_type), 'ملاك/متعاقد');
    const noEl = byName('teacher_no', f);
    if (keepNo && noEl && digits(noEl.value)) n++;   // رقم الموقع موجود ⇒ لا يُمسّ
    else ok(setIn(noEl, digits(L.teacher_no)), 'الرقم المالي');
    ok(setSel(byName('start_day', f), L.start_day), 'يوم الدخول'); ok(setSel(byName('start_month', f), L.start_month), 'شهر الدخول'); ok(setSel(byName('start_year', f), L.start_year), 'سنة الدخول');
    ok(setIn(byName('teacher_name', f), L.teacher_name), 'الاسم'); ok(setIn(byName('father_name', f), L.father_name), 'اسم الأب');
    if (L.birth_year) { ok(setSel(byName('birth_day', f), L.birth_day), 'يوم الولادة'); ok(setSel(byName('birth_month', f), L.birth_month), 'شهر الولادة'); ok(setSel(byName('birth_year', f), L.birth_year), 'سنة الولادة'); } else notes.push('تاريخ الولادة ناقص بالبرنامج');
    ok(setSel(byName('certificate_id', f), L.certificate_id, L.certificate), 'الشهادة'); ok(setSel(byName('category_id', f), L.category_id, L.category), 'الفئة'); ok(setSel(byName('phase_id', f), L.phase_id, L.phase), 'المرحلة');
    ok(setIn(byName('teaching_hours', f), L.teaching_hours), 'الساعات'); ok(setIn(byName('salary', f), digits(L.salary)), 'الراتب'); ok(setIn(byName('add_salary', f), digits(L.add_salary)), 'الأجر الإضافي');
    return { filled: n, notes };
  }
  function fillStep1(B) { // هاتف المدرسة ونوعها (بلا حفظ)
    const f = document.querySelector('form.sp-step1'); if (!f) return 0; let n = 0;
    if (B.phone && setIn(byName('phone', f), B.phone)) n++;
    const r = f.querySelector('input[name="school_type"][value="' + (B.school_type || 'non_free') + '"]'); if (r && !r.checked) { r.checked = true; fire(r, ['change']); n++; }
    return n;
  }

  /* ---- تعبئة نموذج البيان المالي ---- */
  function finForm() { const f = document.querySelector('form[action*="/financial/"][action*="/line/save"]'); return f && visible(f) && document.getElementById('bm_teacherno') ? f : null; }
  async function fillFinancial(f, L) {
    const notes = []; let n = 0; const g = (id) => document.getElementById(id);
    const tno = g('bm_teacherno'); const nameEl = g('bm_teachername'); const err = g('bm_teacher_err');
    if (tno && digits(L.teacher_no)) {
      if (tno.readOnly || tno.disabled) notes.push('الرقم المالي مقفل بالنموذج (تعديل سطر موجود)');
      else { setIn(tno, digits(L.teacher_no)); n++; fire(tno, ['blur', 'change']);
        // المنصّة تبحث عن الأستاذ بالرقم المالي وتعبّئ الاسم؛ ننتظر الجواب
        for (let i = 0; i < 40; i++) { await sleep(200); if ((nameEl && nameEl.value) || (err && visible(err))) break; }
        if (err && visible(err)) notes.push('الرقم المالي ' + digits(L.teacher_no) + ' غير موجود عند الصندوق (' + L.teacher_name + ')');
      }
    } else if (!digits(L.teacher_no)) notes.push('بلا رقم مالي بالبرنامج: ' + L.teacher_name);
    if (setIn(g('bm_prevsal'), digits(L.previous_salary))) n++;
    if (setIn(g('bm_salary'), digits(L.current_salary))) n++;
    if (setIn(g('bm_addsal'), digits(L.add_salary))) n++;
    const tr = g('bm_trainee'), co = g('bm_contrib');
    if (tr && !!tr.checked !== !!Number(L.is_trainee)) { tr.click(); n++; }
    if (co && !!co.checked !== !!Number(L.is_contribution)) { co.click(); n++; }
    const deg = g('bm_degree'); if (deg) { setIn(deg, Number(L.training_degree) > 0 ? digits(L.training_degree) : ''); n++; } // input يعلّم «touched» فلا تعيد المنصّة كتابته
    const half = g('bm_half'); if (half && !!half.checked !== !!Number(L.discount_half_salary)) { half.click(); n++; }
    const rem = g('bm_remarks'); if (rem && L.remarks) { setIn(rem, L.remarks); n++; }
    const sub = g('bm_submit'); if (sub && sub.disabled) notes.push('زرّ الحفظ معطّل على الموقع (الأستاذ غير معروف بالرقم المالي)');
    return { filled: n, notes };
  }

  /* ---- المراجعة ---- */
  function review(B) {
    const rows = siteRows(); const missing = [], extra = []; let matched = 0;
    const used = new Set();
    const renamed = [], noDiff = [];
    (B.lines || []).forEach((L) => { const r = matchRow(L, rows.filter((x) => !used.has(x))); if (r) { matched++; used.add(r); if (r.name !== norm(L.teacher_name)) renamed.push(r.name + ' ⇐ ' + L.teacher_name); const pn = digits(L.teacher_no); if ((r.no || pn) && r.no !== pn) noDiff.push({ name: L.teacher_name, site: r.no, prog: pn, key: L.key || '' }); } else missing.push(L.teacher_name + (L.teacher_no ? ' (' + L.teacher_no + ')' : '')); });
    rows.forEach((r) => { if (!used.has(r)) extra.push(r.name + (r.no ? ' (' + r.no + ')' : '')); });
    return { rows, matched, missing, extra, renamed, noDiff };
  }
  function pageMatchesKind(B) { const p = page(); return (B.kind === 'general' && p === 'roster') || (B.kind === 'financial' && p === 'financial'); }

  /* ---- شريط التقدّم ---- */
  function banner(html, withStop) {
    let b = document.getElementById('msa-eoc-banner');
    if (!b) { b = document.createElement('div'); b.id = 'msa-eoc-banner'; b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#1F4E5F;color:#fff;font:600 14px/1.5 "Noto Sans Arabic",Tahoma,Arial;padding:10px 16px;direction:rtl;text-align:right;display:flex;gap:14px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.25)'; document.body.appendChild(b); }
    b.innerHTML = '<span style="flex:1">' + html + '</span>' + (withStop ? '<button id="msa-eoc-stop" style="background:#b3261e;color:#fff;border:0;border-radius:6px;padding:6px 14px;font-weight:700;cursor:pointer">⏹ إيقاف</button>' : '<button id="msa-eoc-close" style="background:transparent;color:#fff;border:1px solid #fff;border-radius:6px;padding:4px 10px;cursor:pointer">إغلاق</button>');
    const s = document.getElementById('msa-eoc-stop'); if (s) s.onclick = () => { chrome.storage.local.set({ eocAuto: null }); banner('⏹ أُوقفت التعبئة. ما حُفظ بقي محفوظاً على الموقع ويمكن تعديله.', false); };
    const c = document.getElementById('msa-eoc-close'); if (c) c.onclick = () => b.remove();
  }

  /* ---- الترحيل التلقائي: طابور بـchrome.storage يتابع بعد كل إعادة تحميل ---- */
  async function autoStep(Q) {
    if (!Q || !Q.lines || baseUrl() !== Q.base) return;
    const total = Q.lines.length;
    if (Q.idx >= total) { await chrome.storage.local.set({ eocAuto: null }); banner('✅ انتهت التعبئة من برنامج الرواتب: ' + Q.done + ' سطراً حُفظ' + (Q.errors.length ? ' · ' + Q.errors.length + ' لم يُحفَظ: ' + Q.errors.join('؛ ') : '') + ' — راجع الجدول. البيان ما زال مفتوحاً للتعديل.', false); return; }
    const L = Q.lines[Q.idx];
    banner('⏳ تعبئة من برنامج الرواتب ' + (Q.idx + 1) + ' / ' + total + ' — ' + L.teacher_name + (Q.errors.length ? ' · أخطاء: ' + Q.errors.length : ''), true);
    const rows = siteRows(); const existing = matchRow(L, rows);
    const url = new URL(location.href); const curEdit = url.searchParams.get('edit') || '';
    const finish = async (ok, why) => { Q.idx++; if (ok) Q.done++; else Q.errors.push(L.teacher_name + (why ? ' (' + why + ')' : '')); await chrome.storage.local.set({ eocAuto: Q }); };
    if (existing && Q.mode !== 'all') { await finish(true, ''); return autoStep(Q); } // موجود ⇒ تخطَّ
    if (existing && Q.mode === 'all') {
      if (!existing.lineId) { await finish(false, 'لا رابط تعديل'); return autoStep(Q); }
      if (curEdit !== existing.lineId) { await chrome.storage.local.set({ eocAuto: Q }); location.href = existing.editHref; return; } // افتح نموذج التعديل ثمّ تابع بعد التحميل
    } else if (curEdit) { await chrome.storage.local.set({ eocAuto: Q }); location.href = Q.base; return; } // نموذج تعديل مفتوح لغير أستاذنا ⇒ ارجع للقائمة
    // نموذج الإضافة/التعديل
    let f = Q.kind === 'general' ? rosterForm() : finForm();
    if (!f) { const un = document.querySelector('[data-sp-unfold]'); if (un) { un.click(); await sleep(400); } f = Q.kind === 'general' ? rosterForm() : finForm(); }
    if (!f) { await finish(false, 'لا نموذج إدخال بالصفحة'); return autoStep(Q); }
    if (Q.kind === 'general' && existing && (byName('line_id', f) || {}).value !== existing.lineId) { await finish(false, 'نموذج التعديل لا يطابق السطر'); return autoStep(Q); }
    const res = Q.kind === 'general' ? fillRoster(f, L, !!existing) : await fillFinancial(f, L);
    const bad = res.notes.find((x) => /غير موجود عند الصندوق|معطّل|مقفل/.test(x));
    if (bad) { await finish(false, bad); return autoStep(Q); }
    if (!f.checkValidity()) { const inv = f.querySelector(':invalid'); await finish(false, 'خانة ناقصة: ' + (inv ? (inv.name || inv.id) : '؟')); if (inv) f.reportValidity(); return autoStep(Q); }
    Q.idx++; Q.done++; await chrome.storage.local.set({ eocAuto: Q }); // نحسبه محفوظاً قبل الإرسال كي لا يتكرّر لو أعاد الموقع الصفحة بخطأ
    await sleep(250);
    if (f.requestSubmit) f.requestSubmit(); else f.submit();
  }
  chrome.storage.local.get(['eocAuto'], (st) => { if (st && st.eocAuto && st.eocAuto.base === baseUrl()) setTimeout(() => autoStep(st.eocAuto), 600); });

  /* ---- 🔘 الكبسة الواحدة داخل الصفحة (2026-11-09 «عملّي حلّ سهل بلا هاللفّ والدوران»): «رحّل من برنامج الرواتب» ----
     تقرأ رقم المدرسة والسنة/الفصل من الصفحة نفسها، تطلب البيان من البرنامج بهذا الرقم (bg.js)، تعرض الخلاصة، ثمّ ترحّل (تحديث الموجود + إضافة الناقص).
     رقم مدرسة البرنامج ≠ رقم مدرسة الموقع ⇒ لا ترحيل. لا تعتمد ولا تقفل ولا تحذف شيئاً. */
  function siteSchoolNo() { const el = document.querySelector('.sp-user-school'); const t = el ? String(el.textContent) : ''; const m = t.match(/رقم المدرسة\s*(\d+)/) || t.match(/(\d{3,})\s*$/); return m ? m[1] : ''; }
  function sitePeriod() { const h = document.querySelector('h1.sp-title'); const t = h ? h.textContent : ''; const m = page() === 'roster' ? t.match(/(\d{4})\s*-\s*(\d{4})/) : t.match(/(\d{6,7})/); return m ? (page() === 'roster' ? m[1] + '-' + m[2] : m[1]) : ''; }
  function siteLocked() { return !document.querySelector('a[href*="edit="], [data-sp-unfold], form[data-sp-roster-form], #bm_teacherno'); }
  const esc = (s) => String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function bar() {
    let b = document.getElementById('msa-eoc-bar'); if (b) return b;
    const host = document.querySelector('.sp-page-head') || document.querySelector('main') || document.body;
    b = document.createElement('div'); b.id = 'msa-eoc-bar';
    b.style.cssText = 'direction:rtl;text-align:right;background:#eef7f3;border:2px solid #1F4E5F;border-radius:10px;padding:12px 16px;margin:0 0 14px;font:600 14px/1.7 "Noto Sans Arabic",Tahoma,Arial;color:#12303a';
    host.parentNode.insertBefore(b, host.nextSibling); return b;
  }
  const BTN = 'border:0;border-radius:8px;padding:10px 20px;font:700 15px "Noto Sans Arabic",Tahoma,Arial;cursor:pointer;margin-inline-end:8px';
  function barIdle(note) {
    const b = bar();
    b.innerHTML = '<button id="msa-eoc-go" style="' + BTN + ';background:#1F4E5F;color:#fff">⬇ عبّي هذا البيان من برنامج الرواتب</button>'
      + '<span style="font-weight:500;color:#3b5560">يجيب أرقام هذه المدرسة لهذا البيان من البرنامج ويعبّيها هنا. البيان يبقى مفتوحاً للتعديل.</span>'
      + (note ? '<div style="margin-top:8px">' + note + '</div>' : '');
    document.getElementById('msa-eoc-go').onclick = oneClick;
  }
  async function oneClick() {
    const b = bar(); const no = siteSchoolNo(); const per = sitePeriod(); const kind = page() === 'roster' ? 'general' : 'financial';
    if (!no || !per) { barIdle('<span style="color:#b3261e">ما قدرت إقرا رقم المدرسة أو ' + (kind === 'general' ? 'السنة' : 'الفصل') + ' من الصفحة.</span>'); return; }
    // لائحة الموقع على أكثر من صفحة: المطابقة ترى الصفحة الحالية فقط ⇒ خطر تكرار الأساتذة — لا ترحيل تلقائي
    if (document.querySelector('a[href*="page=2"]')) { barIdle('<span style="color:#b3261e">⛔ لائحة الأساتذة هنا على أكثر من صفحة — التعبئة بكبسة واحدة غير متاحة لها بعد (حتى لا يتكرّر أحد). خبّر المبرمج.</span>'); return; }
    b.innerHTML = '⏳ عم جيب بيان المدرسة رقم ' + esc(no) + ' (' + esc(per) + ') من برنامج الرواتب…';
    let res;
    try { res = await chrome.runtime.sendMessage({ target: 'bg', action: 'program', path: 'pages/eoc_portal.php?tab=' + kind + '&period=' + encodeURIComponent(per) + '&export=json&caisse_no=' + no }); }
    catch (e) { res = { ok: false, msg: 'المساعد لم يستجب — حدّث الصفحة (F5) وجرّب.' }; }
    if (!res || !res.ok) {
      barIdle('<span style="color:#b3261e">' + esc((res && res.msg) || 'تعذّر الجلب') + '</span>' + (res && res.login ? ' <a href="#" id="msa-eoc-login" style="color:#1F4E5F">افتح البرنامج وسجّل دخولك</a> ثمّ ارجع واكبس من جديد.' : ''));
      const a = document.getElementById('msa-eoc-login'); if (a) a.onclick = (ev) => { ev.preventDefault(); chrome.runtime.sendMessage({ target: 'bg', action: 'openLogin' }); };
      return;
    }
    const B = res.json;
    if (!B || B.ok === false || !B.lines) { barIdle('<span style="color:#b3261e">' + esc((B && B.msg) || 'البرنامج لم يُرجع البيان') + '</span>'); return; }
    // 🛡️ الحارس: المدرسة والبيان يجب أن يطابقا الصفحة
    if (digits(B.school_no) !== no || B.kind !== kind || String(B.period) !== per) { barIdle('<span style="color:#b3261e">⛔ لا تعبئة: البرنامج أرجع «' + esc(B.school) + '» رقم ' + esc(B.school_no || '—') + ' (' + esc(B.period) + ') وهذه الصفحة للمدرسة رقم ' + esc(no) + ' (' + esc(per) + ').</span>'); return; }
    const rv = review(B); const incomplete = (B.lines || []).filter((x) => x.missing && x.missing.length);
    b.innerHTML = '<div><b>' + esc(B.school) + '</b> — ' + (kind === 'general' ? 'البيان العام ' : 'البيان المالي ') + esc(B.period) + '</div>'
      + '<div style="font-weight:500">بالبرنامج <b>' + B.lines.length + '</b> أستاذاً · موجودون هنا <b>' + rv.matched + '</b> (تتحدّث أرقامهم) · ناقصون هنا <b>' + rv.missing.length + '</b> (يُضافون)'
      + '<div style="font-weight:500;color:#1F4E5F">🔢 الأرقام المالية الموجودة هنا على الموقع هي المرجع — لا تتغيّر.' + (rv.noDiff.length ? ' <span style="color:#b3261e">البرنامج مختلف عنها عند <b>' + rv.noDiff.length + '</b>: ' + esc(rv.noDiff.map((d) => d.name + ' (الموقع ' + (d.site || 'فارغ') + ' / البرنامج ' + (d.prog || 'فارغ') + ')').join(' · ')) + '</span>' : ' البرنامج مطابق لها عند الجميع ✓') + '</div>'
      + (rv.renamed.length ? '<div style="font-weight:500;color:#1F4E5F">✎ أسماء مكتوبة هنا بشكل مختلف — تتصحّح كالبرنامج: ' + esc(rv.renamed.join(' · ')) + '</div>' : '')
      + (rv.extra.length ? ' · <span style="color:#8a5a00">هنا وليسوا بالبرنامج <b>' + rv.extra.length + '</b>: ' + esc(rv.extra.join('، ')) + ' — لا يُمسّون</span>' : '') + '</div>'
      + (incomplete.length ? '<div style="font-weight:500;color:#b3261e">⚠ ' + incomplete.length + ' بياناتهم ناقصة بالبرنامج وقد يرفضهم الموقع: ' + esc(incomplete.map((x) => x.teacher_name).join('، ')) + '</div>' : '')
      + '<div style="margin-top:8px"><button id="msa-eoc-start" style="' + BTN + ';background:#1e7a4f;color:#fff">✔ ابدأ التعبئة (' + B.lines.length + ')</button><button id="msa-eoc-cancel" style="' + BTN + ';background:#fff;color:#12303a;border:1px solid #9bb">إلغاء</button></div>';
    document.getElementById('msa-eoc-cancel').onclick = () => barIdle('');
    document.getElementById('msa-eoc-start').onclick = async () => {
      const Q = { base: baseUrl(), kind: B.kind, period: B.period, mode: 'all', lines: B.lines.slice(), idx: 0, done: 0, errors: [], started: Date.now() };
      await chrome.storage.local.set({ eocAuto: Q, eocStmt: B });
      b.innerHTML = '⏳ بدأت التعبئة — تابع الشريط أعلى الصفحة. «إيقاف» يوقفها بأيّ لحظة.';
      setTimeout(() => autoStep(Q), 300);
    };
  }
  function initBar() {
    const p = page(); if (p !== 'roster' && p !== 'financial') return;
    if (siteLocked()) return;   // بيان معتمد/مقفل: لا كبسة
    chrome.storage.local.get(['eocAuto'], (st) => { if (st && st.eocAuto && st.eocAuto.base === baseUrl()) return; barIdle(''); });   // ترحيل جارٍ ⇒ الشريط العلوي يكفي
  }
  try { initBar(); } catch (e) { /* لا تكسر الصفحة */ }

  /* ---- أوامر النافذة ---- */
  async function run(msg) {
    const B = msg.statement; const p = page();
    if (!B || !B.lines) return { title: 'لا بيان', notes: ['اجلب البيان من البرنامج أوّلاً'] };
    if (msg.action === 'stop') { await chrome.storage.local.set({ eocAuto: null }); banner('⏹ أُوقفت التعبئة.', false); return { title: 'أُوقف', notes: [] }; }
    if (!pageMatchesKind(B)) return { title: B.kind === 'general' ? 'البيان العام' : 'البيان المالي', notes: [B.kind === 'general' ? 'افتح البيان العام للسنة على الموقع (البيان العام ← تعبئة) ثمّ أعد المحاولة. أنت الآن على: ' + p : 'افتح البيان المالي للفصل على الموقع (البيان المالي ← تعبئة) ثمّ أعد المحاولة. أنت الآن على: ' + p] };
    const title = document.querySelector('h1.sp-title'); const siteTitle = title ? norm(title.textContent) : '';
    const rv = review(B);
    const head = (B.kind === 'general' ? 'البيان العام ' : 'البيان المالي ') + B.period + ' ⇐ ' + siteTitle;
    if (msg.action === 'check') return { title: head, matched: rv.matched, missing: rv.missing, extra: rv.extra, notes: [] };
    if (msg.action === 'fill') {
      let n = 0; const notes = [];
      if (B.kind === 'general') n += fillStep1(B);
      const f = B.kind === 'general' ? rosterForm() : finForm();
      if (!f) { const un = document.querySelector('[data-sp-unfold]'); if (un) { un.click(); await sleep(300); } }
      const f2 = B.kind === 'general' ? rosterForm() : finForm();
      if (!f2) return { title: head, filled: n, notes: ['لا نموذج مفتوح — اكبس «+ إضافة» على الموقع أو ✎ لتعديل سطر، ثمّ أعد التعبئة'] };
      // أيّ أستاذ؟ نموذج تعديل ⇒ صاحب السطر؛ نموذج إضافة ⇒ المختار من النافذة أو أوّل ناقص
      let L = null; const curEdit = new URL(location.href).searchParams.get('edit') || (byName('line_id', f2) || {}).value || '';
      if (curEdit) { const r = rv.rows.find((x) => x.lineId === curEdit); if (r) L = (B.lines || []).find((x) => matchRow(x, [r])); if (!L) notes.push('سطر التعديل المفتوح لا يطابق أيّ أستاذ بالبرنامج'); }
      else if (msg.pick !== undefined && msg.pick !== null && B.lines[msg.pick]) L = B.lines[msg.pick];
      else { const miss = (B.lines || []).filter((x) => !matchRow(x, rv.rows)); L = miss[0] || null; if (!L) notes.push('كل أساتذة البرنامج موجودون على الموقع — اختر أستاذاً من النافذة لتعبئته'); }
      if (!L) return { title: head, filled: n, notes };
      const res = B.kind === 'general' ? fillRoster(f2, L, !!curEdit) : await fillFinancial(f2, L);
      return { title: head, filled: n + res.filled, who: L.teacher_name, notes: notes.concat(res.notes), next: 'راجع الخانات ثمّ اكبس «حفظ» على الموقع.' };
    }
    if (msg.action === 'auto') {
      const lines = msg.mode === 'all' ? B.lines.slice() : B.lines.filter((x) => !matchRow(x, rv.rows));
      if (!lines.length) return { title: head, notes: ['لا شيء للترحيل — كل الأساتذة موجودون على الموقع'] };
      const Q = { base: baseUrl(), kind: B.kind, period: B.period, mode: msg.mode === 'all' ? 'all' : 'missing', lines, idx: 0, done: 0, errors: [], started: Date.now() };
      await chrome.storage.local.set({ eocAuto: Q });
      setTimeout(() => autoStep(Q), 300);
      return { title: head, notes: ['بدأ الترحيل التلقائي لـ' + lines.length + ' سطراً — تابع الشريط أعلى الصفحة؛ «إيقاف» يوقفه بأيّ لحظة'] };
    }
    return { title: head, notes: ['أمر غير معروف'] };
  }
  window.__msaEocRun = run; window.__msaEocReview = review;
  if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.onMessage) {
    chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => { if (!msg || !msg.action || msg.target !== 'eoc') return; run(msg).then(sendResponse, (e) => sendResponse({ title: 'خطأ', notes: [String(e && e.message || e)] })); return true; });
  }
})();
