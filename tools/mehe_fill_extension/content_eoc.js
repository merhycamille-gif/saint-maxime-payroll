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
  /* 🔴 (2026-11-09 الفرزل): الصفحة فيها جدولان بنفس الصنف — «أساتذة جدد» (#sp-pending: مَن أُضيف ولم يُضمّ إلى القائمة بعد) يأتي قبل «قائمة الأساتذة» (#sp-list).
     القراءة بـ«أوّل جدول» صارت تقرأ قسم الجدد بعد أوّل إضافة ⇒ حُسب 25 أستاذاً ناقصين وأُضيفوا مكرّرين. القائمة = #sp-list حصراً، والجدد يُقرأون لوحدهم. */
  function siteRows() {
    if (isMulti()) { if (!ALLROWS) throw new Error('multi-page roster not loaded'); return ALLROWS.slice(); }   // 📄 لائحة على عدّة صفحات: تُقرأ كلّها (ensureAll) وإلا لا شيء
    const t = document.querySelector('#sp-list table.sp-data') || Array.from(document.querySelectorAll('table.sp-data')).find((x) => !x.closest('#sp-pending'));
    return t ? tableRows(t) : [];
  }
  /* 📄 (2026-11-11 عبرا، 155 أستاذاً على 6 صفحات): الموقع يعرض 30 سطراً بالصفحة. المطابقة على صفحة واحدة = لائحة جزئية ⇒ ممنوعة.
     ensureAll() تقرأ **كل** صفحات القائمة (نفس الترتيب sort=name وبلا فلتر emp=all) بطلبات قراءة فقط، وتتحقّق: كل صفحة هي المطلوبة، لا سطر بلا معرّف ولا مكرّر
     بين الصفحات، والمجموع = العدد المكتوب بالموقع. أيّ خلل ⇒ لا قائمة ⇒ لا مطابقة ولا تعبئة (MULTI_ERR يُعرض). تُستدعى بأوّل كل دخول (كبسة/متابعة/نافذة). */
  let ALLROWS = null, MULTI_ERR = '';
  const pagerPages = (doc) => Array.from((doc || document).querySelectorAll('.sp-pager a.sp-page')).map((a) => +(((a.getAttribute('href') || '').match(/[?&]page=(\d+)/) || [])[1] || 0)).filter(Boolean);
  const pagerCur = (doc) => { const on = (doc || document).querySelector('.sp-pager a.sp-page-on'); return on ? (+digits(on.textContent) || 1) : 1; };
  const pagerTotal = (doc) => { const el = (doc || document).querySelector('.sp-pager-total'); const m = el ? String(el.textContent).match(/(\d+)/) : null; return m ? +m[1] : -1; };
  function isMulti() { return page() === 'roster' && Math.max(0, ...pagerPages()) > 1; }
  async function ensureAll() {
    ALLROWS = null; MULTI_ERR = '';
    if (!isMulti()) return true;
    try {
      let last = Math.max(...pagerPages()); const out = []; let total = -1;
      for (let k = 1; k <= last; k++) {
        const r = await fetch(baseUrl() + '?sort=name&emp=all&page=' + k, { credentials: 'same-origin', cache: 'no-store' });
        if (!r.ok) throw new Error('الصفحة ' + k + ' لم تنفتح (' + r.status + ')');
        const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
        const t = doc.querySelector('#sp-list table.sp-data');
        if (!t || pagerCur(doc) !== k) throw new Error('الصفحة ' + k + ' لم تُقرأ كما يجب');
        if (k === 1) { total = pagerTotal(doc); last = Math.max(last, ...pagerPages(doc)); }
        else if (pagerTotal(doc) !== total) throw new Error('عدد الأساتذة تغيّر أثناء القراءة');
        tableRows(t).forEach((x) => { x.tr = null; x.page = k; out.push(x); });
      }
      const ids = out.map((x) => x.lineId);
      if (ids.some((i) => !i)) throw new Error('سطر بلا معرّف');
      if (new Set(ids).size !== ids.length) throw new Error('سطر ظهر بصفحتين');
      if (total < 0 || ids.length !== total) throw new Error('قُرئ ' + ids.length + ' سطراً والموقع يقول ' + total);
      ALLROWS = out; return true;
    } catch (e) { ALLROWS = null; MULTI_ERR = String((e && e.message) || e); return false; }
  }
  const multiMsg = () => '⛔ لائحة الأساتذة هنا على أكثر من صفحة ولم تُقرأ كاملة (' + MULTI_ERR + ') — لا تعبئة على لائحة جزئية. حدّث الصفحة (F5) وجرّب؛ إن تكرّر خبّر المبرمج.';
  function pendingRows() { const t = document.querySelector('#sp-pending table.sp-data'); return t ? tableRows(t) : []; }
  function tableRows(t) {
    const heads = Array.from(t.querySelectorAll('thead th')).map((th) => norm(th.textContent));
    const ix = (lb) => heads.findIndex((h) => h === norm(lb));
    const iNo = ix('الرقم المالي'), iName = ix('الاسم والشهرة'), iType = ix('ملاك/متعاقد'), iFa = ix('اسم الاب'), iB = heads.findIndex((h) => h.indexOf('الولاده') >= 0);
    return Array.from(t.querySelectorAll('tbody tr')).map((tr) => {
      const tds = tr.querySelectorAll('td'); if (tds.length < 3) return null;
      const a = tr.querySelector('a[href*="edit="]');
      const df = tr.querySelector('form[action*="/delete"]');
      const m = (a ? (a.getAttribute('href') || '').match(/[?&]edit=(\d+)/) : null) || (df ? (df.getAttribute('action') || '').match(/\/line\/(\d+)\/delete/) : null);
      return { tr, no: iNo >= 0 ? digits(tds[iNo].textContent) : '', name: iName >= 0 ? norm(tds[iName].textContent) : '', type: iType >= 0 ? norm(tds[iType].textContent) : '', father: iFa >= 0 ? norm(tds[iFa].textContent) : '', birth: iB >= 0 && tds[iB] ? bdKey(tds[iB].textContent) : 0, editHref: a ? new URL(a.getAttribute('href') || '', location.href).href : '', lineId: m ? m[1] : '' };
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
  /* 🤝 مطابقة اللائحتين كلّها معاً على مراحل (2026-11-09 الفرزل: «ايلان رحمة» المتعاقدة بلا رقم سبقت بالترتيب «ايلين الياس» 14861 فأخذت سطرها ورقمها):
     المرحلة الأقوى تُستنفَد عند الجميع قبل الأضعف — الرقم المالي أوّلاً، ثمّ الاسم الحرفي، ثمّ أشكال الكتابة المختلفة. تُرجع سطر الموقع لكل سطر برنامج (أو null). */
  /* 🎂 تاريخ الولادة رقماً واحداً (سنة-شهر-يوم أو يوم/شهر/سنة)؛ فارغ أو سنة وهمية (≤1900) ⇒ 0 = غير معروف */
  function bdKey(t) {
    const s = String(t || ''); let m = s.match(/(\d{4})\D(\d{1,2})\D(\d{1,2})/); let y, mo, d;
    if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; } else { m = s.match(/(\d{1,2})\D(\d{1,2})\D(\d{4})/); if (!m) return 0; d = +m[1]; mo = +m[2]; y = +m[3]; }
    return y > 1900 ? y * 10000 + mo * 100 + d : 0;
  }
  const lineBd = (L) => (L && L.birth_year ? bdKey(L.birth_year + '-' + (L.birth_month || 0) + '-' + (L.birth_day || 0)) : 0);
  function assign(lines, rows) {
    const res = (lines || []).map(() => null); const used = new Set(); const hold = (lines || []).map(() => null);
    const free = () => rows.filter((x) => !used.has(x));
    const passes = [
      (L, no) => (no ? free().filter((x) => x.no === no).slice(0, 1) : []),
      (L, no, nm) => (nm ? free().filter((x) => x.name === nm).slice(0, 1) : []),
      (L, no, nm) => (nm ? free().filter((x) => skel(x.name) === skel(nm)) : []),
      (L, no, nm) => { if (!nm) return []; const k2 = nm.split(' ').slice(0, 2).join(' '); return free().filter((x) => x.name.indexOf(k2) === 0 || nm.indexOf(x.name.split(' ').slice(0, 2).join(' ')) === 0); },
      (L, no, nm) => { const f1 = skel(nm.split(' ')[0]); const fa = skel(norm(L_father(L))); return f1 && fa ? free().filter((x) => skel(x.name.split(' ')[0]) === f1 && skel(x.father || '') === fa && (!x.no || !no || x.no === no)) : []; },
    ];
    // 🎂 المرحلة الأخيرة (الشهرة مختلفة: الاسم الأوّل + اسم الأب فقط) أضعف المراحل: تاريخا ولادة معروفان ومختلفان ⇒ شخصان مختلفان محتملان ⇒ «معلَّق»: لا يُعدَّل سطر الموقع ولا يُضاف الأستاذ، ويُذكر للمراجعة
    passes.forEach((pass, pi) => (lines || []).forEach((L, i) => { if (res[i] || hold[i]) return; const c = pass(L, digits(L.teacher_no), norm(L.teacher_name)); if (c.length !== 1) return;
      if (pi === passes.length - 1) { const a = lineBd(L), b = c[0].birth || 0; if (a && b && a !== b) { hold[i] = c[0]; return; } }
      res[i] = c[0]; used.add(c[0]); }));
    res.hold = hold; return res;
  }
  function holdFor(L, lines, rows) {
    let i = (lines || []).indexOf(L);
    if (i < 0) i = (lines || []).findIndex((x) => String(x.key || '') === String(L.key || '') && x.teacher_name === L.teacher_name && digits(x.teacher_no) === digits(L.teacher_no));
    return i >= 0 ? assign(lines, rows).hold[i] : null;
  }
  function rowFor(L, lines, rows) {
    let i = (lines || []).indexOf(L);
    if (i < 0) i = (lines || []).findIndex((x) => String(x.key || '') === String(L.key || '') && x.teacher_name === L.teacher_name && digits(x.teacher_no) === digits(L.teacher_no));
    return i >= 0 ? assign(lines, rows)[i] : matchRow(L, rows);
  }
  /* 🕓 قسم «أساتذة جدد»: أيّ سطر فيه مكرّر عن القائمة (أستاذه موجود بالقائمة أو مكرّر بالقسم نفسه) ⇒ dup يُشال؛ أستاذ من البرنامج ناقص عن القائمة ⇒ keep يُضمّ؛
     سطر لا يطابق أيّ أستاذ بالبرنامج بالحرف (أو أستاذه «معلَّق») ⇒ foreign لا يُمسّ ويمنع الضمّ التلقائي. المطابقة هنا حرفية فقط: الرقم المالي، أو الاسم + اسم الأب. */
  function strictSame(L, p) { const n = digits(L.teacher_no); if (n && p.no) return n === p.no; return !!p.name && norm(L.teacher_name) === p.name && skel(norm(L_father(L))) === skel(p.father || ''); }
  function pendPlan(lines, rows, pend) {
    const as = assign(lines || [], rows); const dup = [], keep = [], foreign = []; const seen = new Set();
    (pend || []).forEach((p) => { const i = (lines || []).findIndex((L) => strictSame(L, p));
      if (i < 0 || as.hold[i]) { foreign.push(p); return; }
      if (as[i] || seen.has(i)) dup.push(p); else { seen.add(i); keep.push(p); } });
    return { dup, keep, foreign };
  }
  /* 🔎 اللائحة معروضة جزئياً (ملاك فقط / متعاقدين فقط / بحث): الجدول يُظهر بعض الأساتذة ⇒ المخفيّون يُحسبون «ناقصين» فيتكرّرون — لا مطابقة على لائحة مفلترة */
  function siteFiltered() {
    const emp = new URL(location.href).searchParams.get('emp') || ''; const sel = document.getElementById('sp-emp');
    if (emp === 'staff' || emp === 'contract' || (sel && sel.value && sel.value !== 'all')) return true;
    const m = String(document.body ? document.body.textContent : '').match(/(\d+)\s*من\s*[أا]صل\s*(\d+)/);
    return !!m && m[1] !== m[2];
  }

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
  /* الخطوة 1 (خلوي المدرسة ونوعها) غير محفوظة ⇒ الموقع يرفض كل حفظ. العلامة: قفل نموذج الأستاذ «أكمل الخطوة 1» أو خانة الخلوي فارغة */
  function step1Missing() {
    if (page() !== 'roster') return false;
    const ph = document.querySelector('form.sp-step1 [name="phone"]') || document.querySelector('#sp-school-phone');
    return !!ph && !digits(ph.value);
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
    const renamed = [], noDiff = [], doubt = []; const as = assign(B.lines || [], rows); const heldRows = new Set(as.hold.filter(Boolean));
    (B.lines || []).forEach((L, i) => { const r = as[i]; if (r) { matched++; used.add(r); if (r.name !== norm(L.teacher_name)) renamed.push(r.name + ' ⇐ ' + L.teacher_name); const pn = digits(L.teacher_no); if ((r.no || pn) && r.no !== pn) noDiff.push({ name: L.teacher_name, site: r.no, prog: pn, key: L.key || '' }); } else if (as.hold[i]) doubt.push(L.teacher_name + ' ↔ ' + as.hold[i].name); else missing.push(L.teacher_name + (L.teacher_no ? ' (' + L.teacher_no + ')' : '')); });
    // 🚪 (2026-11-09 «إذا موجود بالموقع وبطّل بالبرنامج بيكون موجود السنة القبلها بالبرنامج وترك — أنت تتأكّد»): الزائد على الموقع يُشال فقط إن طابق بقوّة
    //    أستاذاً سابقاً بالبرنامج (B.gone: الرقم المالي نفسه، أو الاسم الأوّل + اسم الأب + الشهرة)؛ وغيره يبقى ويُذكر.
    const gone = [], unknown = []; const usedG = new Set();
    const strong = (g, r) => { const gn = digits(g.teacher_no); if (gn && r.no) return gn === r.no; const a = norm(g.teacher_name).split(' '), b = r.name.split(' '); if (!a[0] || skel(a[0]) !== skel(b[0])) return false;
      const fa = skel(norm(g.father_name)), fb = skel(r.father || ''); const la = skel(a.slice(1).join('')), lb = skel(b.slice(1).join('')); return !!fa && fa === fb && !!la && (la === lb || la.indexOf(lb) >= 0 || lb.indexOf(la) >= 0); };
    rows.forEach((r) => { if (used.has(r)) return; const label = r.name + (r.no ? ' (' + r.no + ')' : ''); extra.push(label);
      const c = (B.gone || []).filter((g) => !usedG.has(g) && strong(g, r));
      const isCurrent = (B.lines || []).some((L) => strong(L, r));   // 🛡️ يشبه أستاذاً حالياً بالبرنامج ⇒ لا يُشال أبداً
      if (c.length === 1 && r.lineId && !isCurrent && !heldRows.has(r)) { usedG.add(c[0]); gone.push({ lineId: r.lineId, name: label, why: c[0].why }); } else unknown.push(label); });
    const pend = pendPlan(B.lines || [], rows, pendingRows());
    return { rows, matched, missing, extra, renamed, noDiff, gone, unknown, doubt, pend };
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

  /* 🔎 تحقّق آخر التعبئة (يُكتب بالشريط): كل أساتذة البرنامج لهم سطر بالقائمة، لا سطر مكرّر (برقم مالي أو باسم + اسم أب)، ولا أحد عالق بقسم «أساتذة جدد» */
  function verifyFinal(lines) {
    const rows = siteRows(); const as = assign(lines || [], rows); const bad = [];
    const miss = (lines || []).filter((L, i) => !as[i] && !as.hold[i]).map((L) => L.teacher_name); const held = (lines || []).filter((L, i) => !as[i] && as.hold[i]).map((L) => L.teacher_name);
    const seen = new Set(); const dups = [];
    rows.forEach((r) => { const k1 = r.no ? 'n:' + r.no : ''; const k2 = 'm:' + r.name + '|' + skel(r.father || ''); if ((k1 && seen.has(k1)) || seen.has(k2)) dups.push(r.name); if (k1) seen.add(k1); seen.add(k2); });
    const pend = pendingRows().length;
    if (miss.length) bad.push(miss.length + ' من البرنامج ليسوا بالقائمة: ' + miss.join('، '));
    if (held.length) bad.push(held.length + ' معلَّقون للمراجعة: ' + held.join('، '));
    if (dups.length) bad.push(dups.length + ' سطراً مكرّراً: ' + dups.join('، '));
    if (pend) bad.push(pend + ' عالقون بقسم «أساتذة جدد»');
    return bad.length ? ' ⚠ تحقّق: ' + bad.join(' · ') : ' 🔎 تحقّق: أساتذة البرنامج الـ' + (lines || []).length + ' كلّهم بالقائمة (' + rows.length + ' سطراً) بلا تكرار ✓';
  }

  /* ---- الترحيل التلقائي: طابور بـchrome.storage يتابع بعد كل إعادة تحميل ---- */
  async function autoStep(Q, warm) {
    if (!Q || !Q.lines || baseUrl() !== Q.base) return;
    // 🧯 (2026-11-11 عبرا): الموقع رجّع الصفحة بخطأ (?error=…، مثل phone_required = الخطوة 1 غير محفوظة) ⇒ السطر لم يُحفظ ⇒ توقّف فوراً (كان يُحسب «محفوظاً» ويُكمل)
    const siteErr = new URL(location.href).searchParams.get('error') || '';
    if (siteErr || step1Missing()) { await chrome.storage.local.set({ eocAuto: null });
      banner('⛔ توقّفت التعبئة: ' + (siteErr === 'phone_required' || !siteErr ? 'الموقع لا يحفظ أيّ أستاذ قبل «الخطوة 1» — رقم خلوي المدرسة ونوعها. عبّيها واكبس «حفظ» ثمّ اكبس التعبئة من جديد.' : 'الموقع رفض الحفظ (' + esc(siteErr) + ').') + ' لم يُحفظ شيء من هذه المحاولة.', false); return; }
    // 📄 لائحة على عدّة صفحات: تُقرأ كاملة بأوّل كل تحميل؛ إن لم تُقرأ ⇒ توقّف بلا أيّ إرسال
    if (!warm && !(await ensureAll())) { await chrome.storage.local.set({ eocAuto: null }); banner(esc(multiMsg()) + ' ما حُفظ قبلها بقي كما هو.', false); return; }
    // 🔎 لائحة مفلترة ⇒ ارجع إلى اللائحة الكاملة (مع إبقاء نموذج التعديل المفتوح)؛ إن بقيت مفلترة ⇒ توقّف بلا أيّ إضافة
    if (siteFiltered()) {
      const e0 = new URL(location.href).searchParams.get('edit') || '';
      if ((Q.unf || 0) >= 2) { await chrome.storage.local.set({ eocAuto: null }); banner('⛔ توقّفت التعبئة: لائحة الأساتذة هنا معروضة جزئياً (فلتر) ولم تنفتح كاملة — ما حُفظ بقي محفوظاً. افتح البيان من «البيان العام» واكبس من جديد.', false); return; }
      Q.unf = (Q.unf || 0) + 1; await chrome.storage.local.set({ eocAuto: Q }); location.href = Q.base + (e0 ? '?edit=' + encodeURIComponent(e0) : ''); return;
    }
    Q.unf = 0;
    // 🧯 سلك الأمان (2026-11-09 «ما عاد اتحمّل أخطاء»): قبل كل إرسال نسجّل عدد القائمة وعدد «أساتذة جدد» والعملية؛ بعد رجوع الصفحة، إن لم يتغيّر العددان كما تفرضه العملية
    //    (الموقع رفض، أو تصرّف بشكل لم نحسبه) ⇒ توقّف فوراً عند أوّل سطر — الضرر الأقصى سطر واحد لا لائحة كاملة.
    if (Q.expect) {
      const e = Q.expect; const m = siteRows().length, p = pendingRows().length; Q.expect = null;
      const okX = e.op === 'add' ? ((m === e.m && p === e.p + 1) || (m === e.m + 1 && p === e.p)) : e.op === 'edit' ? (m === e.m && p === e.p) : e.op === 'pdel' ? (m === e.m && p === e.p - 1)
        : e.op === 'padd' ? (p === 0 && m === e.m + e.k) : e.op === 'gdel' ? (m === e.m - 1 && p === e.p) : true;
      if (!okX) { await chrome.storage.local.set({ eocAuto: null });
        banner('⛔ توقّفت التعبئة عند «' + esc(e.who) + '»: الموقع ما تصرّف متل المتوقَّع بعد ' + ({ add: 'إضافة أستاذ', edit: 'تعديل سطر', pdel: 'شيل مكرّر من «أساتذة جدد»', padd: 'ضمّ الجدد إلى القائمة', gdel: 'شيل مَن ترك' }[e.op] || e.op)
          + ' (القائمة ' + e.m + ' ⇐ ' + m + ' · أساتذة جدد ' + e.p + ' ⇐ ' + p + '). ما حُفظ قبلها بقي كما هو ولم يُكمَل شيء بعدها — خبّر المبرمج قبل أيّ كبسة.', false); return; }
    }
    const expect = (op, who, k) => { Q.expect = { op, who: String(who || ''), m: siteRows().length, p: pendingRows().length, k: k || 0 }; };
    const total = Q.lines.length;
    if (Q.idx >= total) {
      // 🕓 قسم «أساتذة جدد» (الموقع يحفظ الأستاذ المضاف هناك أوّلاً ولا يُحتسب قبل ضمّه): المكرّر عن القائمة يُشال سطراً سطراً، ثمّ الجدد يُضمّون بكبسة الموقع نفسها «إضافة إلى القائمة»
      if (Q.kind === 'general' && !Q.pendDone) {
        const plan = pendPlan(Q.all || Q.lines, siteRows(), pendingRows()); const save = () => chrome.storage.local.set({ eocAuto: Q });
        if (plan.dup.length) {
          if (new URL(location.href).searchParams.get('edit')) { await save(); location.href = Q.base; return; }
          const d = plan.dup[0]; const df = d.lineId ? document.querySelector('#sp-pending form[action*="/line/' + d.lineId + '/delete"]') : null;
          if (df && (Q.pdel || 0) < 80) { Q.pdel = (Q.pdel || 0) + 1; expect('pdel', d.name); await save(); banner('🧹 شيل المكرّر من «أساتذة جدد» (' + plan.dup.length + ' باقي) — ' + d.name, true); await sleep(250); HTMLFormElement.prototype.submit.call(df); return; }
          Q.errors.push('بقي ' + plan.dup.length + ' مكرّراً بقسم «أساتذة جدد» لم يُشل — لا تكبس «إضافة إلى القائمة» قبل شيلهم');
        } else if (plan.keep.length && !plan.foreign.length && !Q.padd) {
          const af = document.querySelector('#sp-pending form[action*="/pending/add"]');
          if (af) { Q.padd = plan.keep.length; expect('padd', 'أساتذة جدد', plan.keep.length); await save(); banner('➕ ضمّ ' + plan.keep.length + ' أستاذاً جديداً إلى القائمة…', true); await sleep(250); HTMLFormElement.prototype.submit.call(af); return; }
        }
        if (!plan.dup.length && (plan.keep.length || plan.foreign.length)) Q.errors.push('بقي بقسم «أساتذة جدد» ' + (plan.keep.length + plan.foreign.length) + (plan.foreign.length ? ' (منهم ' + plan.foreign.length + ' ليسوا من لائحة البرنامج)' : '') + ' — راجعهم واكبس «إضافة إلى القائمة» بيدك');
        Q.pendDone = 1; await save();
      }
      // 🚪 بعد التعبئة: شيل مَن تركوا (المثبَتون بالبرنامج) سطراً سطراً — نموذج الحذف نفسه الذي بالموقع
      if (Q.del && (Q.delIdx || 0) < Q.del.length) {
        if (new URL(location.href).searchParams.get('edit')) { await chrome.storage.local.set({ eocAuto: Q }); location.href = Q.base; return; }
        const d = Q.del[Q.delIdx || 0];
        const df = document.querySelector('form[action*="/line/' + d.lineId + '/delete"]');
        // 📄 سطره بصفحة أخرى من القائمة ⇒ افتح صفحته أوّلاً (مرّتان كحدّ أقصى) ثمّ شِله بنموذج الموقع نفسه
        if (!df && isMulti()) { const rr = siteRows().find((x) => x.lineId === String(d.lineId));
          if (rr && rr.page && (Q.delNav || 0) < 2) { Q.delNav = (Q.delNav || 0) + 1; await chrome.storage.local.set({ eocAuto: Q }); location.href = Q.base + '?sort=name&emp=all&page=' + rr.page; return; } }
        Q.delNav = 0; Q.delIdx = (Q.delIdx || 0) + 1;
        if (!df) { Q.errors.push(d.name + ' (لم يُعثر على سطره لشيله)'); await chrome.storage.local.set({ eocAuto: Q }); return autoStep(Q, 1); }
        Q.deleted = (Q.deleted || 0) + 1; expect('gdel', d.name); await chrome.storage.local.set({ eocAuto: Q });
        banner('🚪 شيل مَن ترك ' + Q.delIdx + ' / ' + Q.del.length + ' — ' + d.name + ' (' + d.why + ')', true);
        await sleep(250); HTMLFormElement.prototype.submit.call(df); return;
      }
      await chrome.storage.local.set({ eocAuto: null });
      banner('✅ انتهت التعبئة من برنامج الرواتب: ' + Q.done + ' سطراً حُفظ' + (Q.pdel ? ' · ' + Q.pdel + ' مكرّراً شِيل من «أساتذة جدد»' : '') + (Q.padd ? ' · ' + Q.padd + ' جديداً ضُمّ إلى القائمة' : '') + (Q.deleted ? ' · ' + Q.deleted + ' شِيل (ترك)' : '') + (Q.note || '') + (Q.errors.length ? ' · ' + Q.errors.length + ' لم يتمّ: ' + esc(Q.errors.join('؛ ')) : '') + ' —' + esc(verifyFinal(Q.all || Q.lines)) + ' البيان ما زال مفتوحاً للتعديل.', false); return;
    }
    const L = Q.lines[Q.idx];
    banner('⏳ تعبئة من برنامج الرواتب ' + (Q.idx + 1) + ' / ' + total + ' — ' + L.teacher_name + (Q.errors.length ? ' · أخطاء: ' + Q.errors.length : ''), true);
    const rows = siteRows(); const existing = rowFor(L, Q.all || Q.lines, rows);
    const url = new URL(location.href); const curEdit = url.searchParams.get('edit') || '';
    const finish = async (ok, why) => { Q.idx++; if (ok) Q.done++; else Q.errors.push(L.teacher_name + (why ? ' (' + why + ')' : '')); await chrome.storage.local.set({ eocAuto: Q }); };
    // 🕓 ليس بالقائمة لكنّه محفوظ بقسم «أساتذة جدد» ⇒ لا يُضاف مرّة ثانية (يُضمّ بآخر التعبئة)
    if (!existing && Q.kind === 'general' && pendingRows().some((p) => strictSame(L, p))) { await finish(true, ''); return autoStep(Q, 1); }
    const held = existing ? null : holdFor(L, Q.all || Q.lines, rows);   // 🎂 معلَّق: لا تعديل ولا إضافة
    if (held) { await finish(false, 'لم يُمسّ: اسمه قريب من «' + held.name + '» على الموقع وتاريخ الولادة مختلف — راجعه بيدك'); return autoStep(Q, 1); }
    if (existing && Q.mode !== 'all') { await finish(true, ''); return autoStep(Q, 1); } // موجود ⇒ تخطَّ
    if (existing && Q.mode === 'all') {
      if (!existing.lineId) { await finish(false, 'لا رابط تعديل'); return autoStep(Q, 1); }
      if (curEdit !== existing.lineId) { await chrome.storage.local.set({ eocAuto: Q }); location.href = existing.editHref; return; } // افتح نموذج التعديل ثمّ تابع بعد التحميل
    } else if (curEdit) { await chrome.storage.local.set({ eocAuto: Q }); location.href = Q.base; return; } // نموذج تعديل مفتوح لغير أستاذنا ⇒ ارجع للقائمة
    // نموذج الإضافة/التعديل
    let f = Q.kind === 'general' ? rosterForm() : finForm();
    if (!f) { const un = document.querySelector('[data-sp-unfold]'); if (un) { un.click(); await sleep(400); } f = Q.kind === 'general' ? rosterForm() : finForm(); }
    if (!f) { await finish(false, 'لا نموذج إدخال بالصفحة'); return autoStep(Q, 1); }
    if (Q.kind === 'general' && existing && (byName('line_id', f) || {}).value !== existing.lineId) { await finish(false, 'نموذج التعديل لا يطابق السطر'); return autoStep(Q, 1); }
    const res = Q.kind === 'general' ? fillRoster(f, L, !!existing) : await fillFinancial(f, L);
    const bad = res.notes.find((x) => /غير موجود عند الصندوق|معطّل|مقفل/.test(x));
    if (bad) { await finish(false, bad); return autoStep(Q, 1); }
    if (!f.checkValidity()) { const inv = f.querySelector(':invalid'); await finish(false, 'خانة ناقصة: ' + (inv ? (inv.name || inv.id) : '؟')); if (inv) f.reportValidity(); return autoStep(Q, 1); }
    expect(existing ? 'edit' : 'add', L.teacher_name);
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
  // 🔴 بلا علامات " داخل النصّ: يوضع داخل style="…" فتقطعه (كانت الكبسات تطلع باهتة بلا لون)
  const BTN = 'border:0;border-radius:8px;padding:10px 20px;font:700 15px Noto Sans Arabic,Tahoma,Arial;cursor:pointer;margin-inline-end:8px';
  function barIdle(note) {
    const b = bar();
    b.innerHTML = '<button id="msa-eoc-go" style="' + BTN + ';background:#1F4E5F !important;color:#fff !important">⬇ عبّي هذا البيان من برنامج الرواتب</button>'
      + '<span style="font-weight:500;color:#3b5560">يجيب أرقام هذه المدرسة لهذا البيان من البرنامج ويعبّيها هنا. البيان يبقى مفتوحاً للتعديل.</span>'
      + (note ? '<div style="margin-top:8px">' + note + '</div>' : '');
    document.getElementById('msa-eoc-go').onclick = oneClick;
  }
  async function oneClick() {
    const b = bar(); const no = siteSchoolNo(); const per = sitePeriod(); const kind = page() === 'roster' ? 'general' : 'financial';
    if (!no || !per) { barIdle('<span style="color:#b3261e">ما قدرت إقرا رقم المدرسة أو ' + (kind === 'general' ? 'السنة' : 'الفصل') + ' من الصفحة.</span>'); return; }
    // لائحة الموقع على أكثر من صفحة: المطابقة ترى الصفحة الحالية فقط ⇒ خطر تكرار الأساتذة — لا ترحيل تلقائي
    // 🔎 لائحة مفلترة (ملاك فقط/متعاقدين فقط) ⇒ افتح اللائحة الكاملة وكمّل لحالك (مرّة واحدة)
    if (siteFiltered()) {
      let tried = ''; try { tried = sessionStorage.getItem('msaEocGo') || ''; } catch (e) {}
      if (tried === 'tried') { try { sessionStorage.removeItem('msaEocGo'); } catch (e) {} barIdle('<span style="color:#b3261e">⛔ لائحة الأساتذة هنا معروضة جزئياً (فلتر أو بحث) — افتح البيان من «البيان العام» بلا فلتر واكبس من جديد.</span>'); return; }
      try { sessionStorage.setItem('msaEocGo', '1'); } catch (e) {}
      b.innerHTML = '⏳ اللائحة معروضة جزئياً — عم افتح اللائحة الكاملة…'; location.href = baseUrl(); return;
    }
    try { sessionStorage.removeItem('msaEocGo'); } catch (e) {}
    if (step1Missing()) { barIdle('<span style="color:#b3261e">⛔ قبل التعبئة: الموقع يطلب «الخطوة 1» — رقم خلوي المدرسة ونوعها. عبّيها تحت واكبس «حفظ»، ثمّ اكبس هنا من جديد.</span>'); return; }
    if (isMulti()) { b.innerHTML = '⏳ لائحة الأساتذة هنا على أكثر من صفحة — عم إقراها كلّها…'; if (!(await ensureAll())) { barIdle('<span style="color:#b3261e">' + esc(multiMsg()) + '</span>'); return; } }
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
      + '</div>'
      + (rv.gone.length ? '<div style="font-weight:500;color:#b3261e">🚪 على الموقع وتركوا المدرسة بحسب البرنامج — يُشالون من هنا (' + rv.gone.length + '): ' + esc(rv.gone.map((g) => g.name + ' — ' + g.why).join(' · ')) + '</div>' : '')
      + (rv.pend.dup.length + rv.pend.keep.length + rv.pend.foreign.length ? '<div style="font-weight:700;color:#8a5a00">🕓 بقسم «أساتذة جدد» (ناطرين) ' + (rv.pend.dup.length + rv.pend.keep.length + rv.pend.foreign.length) + ': '
          + [rv.pend.dup.length ? rv.pend.dup.length + ' مكرّرين عن القائمة — يُشالون' : '', rv.pend.keep.length ? rv.pend.keep.length + ' جدد من البرنامج — يُضمّون إلى القائمة' : '', rv.pend.foreign.length ? rv.pend.foreign.length + ' ليسوا من لائحة البرنامج — لا يُمسّون (' + esc(rv.pend.foreign.map((p) => p.name).join('، ')) + ')' : ''].filter(Boolean).join(' · ') + '</div>' : '')
      + (rv.doubt.length ? '<div style="font-weight:700;color:#b3261e">✋ اسم قريب من سطر هنا لكن تاريخ الولادة مختلف — لا يُعدَّل ولا يُضاف، راجعه بيدك (' + rv.doubt.length + '): ' + esc(rv.doubt.join(' · ')) + '</div>' : '')
      + (rv.unknown.length ? '<div style="font-weight:500;color:#8a5a00">❔ على الموقع ولا أثر لهم بالبرنامج — لا يُمسّون (' + rv.unknown.length + '): ' + esc(rv.unknown.join('، ')) + '</div>' : '')
      + (incomplete.length ? '<div style="font-weight:500;color:#b3261e">⚠ ' + incomplete.length + ' بياناتهم ناقصة بالبرنامج وقد يرفضهم الموقع: ' + esc(incomplete.map((x) => x.teacher_name).join('، ')) + '</div>' : '')
      + '<div style="margin-top:8px"><button id="msa-eoc-start" style="' + BTN + ';background:#1e7a4f !important;color:#fff !important">✔ ابدأ التعبئة (' + B.lines.length + ')</button><button id="msa-eoc-cancel" style="' + BTN + ';background:#5f697a !important;color:#fff !important">إلغاء</button></div>';
    document.getElementById('msa-eoc-cancel').onclick = () => barIdle('');
    document.getElementById('msa-eoc-start').onclick = async () => {
      let fixedNote = '';
      // رقم الموقع موجود والبرنامج مختلف/فارغ ⇒ البرنامج يصير كالموقع — إلا إذا الرقم نفسه مسجَّل بالبرنامج لأستاذ آخر بهذا البيان (شخصان مختلفان)
      const fix = (rv.noDiff || []).filter((d) => d.site && /^\d+$/.test(String(d.key)) && !(B.lines || []).some((x) => String(x.key) !== String(d.key) && digits(x.teacher_no) === d.site));
      if (fix.length && B.csrf && kind === 'general') {
        b.innerHTML = '⏳ عم صحّح بالبرنامج ' + fix.length + ' رقماً ماليّاً ليصير كالموقع…';
        let fr = null; try { fr = await chrome.runtime.sendMessage({ target: 'bg', action: 'siteNumbers', base: res.base, csrf: B.csrf, path: 'pages/eoc_portal.php?tab=general&period=' + per + '&caisse_no=' + no, items: fix.map((d) => ({ key: String(d.key), no: String(d.site) })) }); } catch (e) { fr = null; }
        fixedNote = fr && fr.ok ? ' · صُحّح بالبرنامج ' + (fr.updated || 0) + ' رقماً ماليّاً كالموقع' + (fr.skipped ? ' (' + fr.skipped + ' لم يُنقل: الرقم عند أستاذ آخر بالبرنامج)' : '') :' · ⚠ لم تُصحَّح أرقام البرنامج (' + esc((fr && fr.msg) || 'لا جواب') + ') — أرقام الموقع لم تُمسّ';
      }
      const Q = { base: baseUrl(), kind: B.kind, period: B.period, mode: 'all', lines: B.lines.slice(), idx: 0, done: 0, errors: [], started: Date.now(), del: (rv.gone || []).slice(), delIdx: 0, deleted: 0, note: fixedNote };
      await chrome.storage.local.set({ eocAuto: Q, eocStmt: B });
      b.innerHTML = '⏳ بدأت التعبئة — تابع الشريط أعلى الصفحة. «إيقاف» يوقفها بأيّ لحظة.';
      setTimeout(() => autoStep(Q), 300);
    };
  }
  function initBar() {
    const p = page(); if (p !== 'roster' && p !== 'financial') return;
    if (siteLocked()) return;   // بيان معتمد/مقفل: لا كبسة
    chrome.storage.local.get(['eocAuto'], (st) => { if (st && st.eocAuto && st.eocAuto.base === baseUrl()) return; barIdle('');   // ترحيل جارٍ ⇒ الشريط العلوي يكفي
      let go = ''; try { go = sessionStorage.getItem('msaEocGo') || ''; } catch (e) {}
      if (go === '1') { try { sessionStorage.setItem('msaEocGo', 'tried'); } catch (e) {} oneClick(); }   // رجعنا من لائحة مفلترة ⇒ كمّل الكبسة تلقائياً
    });
  }
  try { initBar(); } catch (e) { /* لا تكسر الصفحة */ }

  /* ---- أوامر النافذة ---- */
  async function run(msg) {
    const B = msg.statement; const p = page();
    if (!B || !B.lines) return { title: 'لا بيان', notes: ['اجلب البيان من البرنامج أوّلاً'] };
    if (msg.action === 'stop') { await chrome.storage.local.set({ eocAuto: null }); banner('⏹ أُوقفت التعبئة.', false); return { title: 'أُوقف', notes: [] }; }
    if (!pageMatchesKind(B)) return { title: B.kind === 'general' ? 'البيان العام' : 'البيان المالي', notes: [B.kind === 'general' ? 'افتح البيان العام للسنة على الموقع (البيان العام ← تعبئة) ثمّ أعد المحاولة. أنت الآن على: ' + p : 'افتح البيان المالي للفصل على الموقع (البيان المالي ← تعبئة) ثمّ أعد المحاولة. أنت الآن على: ' + p] };
    const title = document.querySelector('h1.sp-title'); const siteTitle = title ? norm(title.textContent) : '';
    if (!(await ensureAll())) return { title: 'لائحة على أكثر من صفحة', notes: [multiMsg()] };
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
      const as = assign(B.lines || [], rv.rows);
      if (curEdit) { const r = rv.rows.find((x) => x.lineId === curEdit); if (r) L = (B.lines || [])[as.indexOf(r)] || null; if (!L) notes.push('سطر التعديل المفتوح لا يطابق أيّ أستاذ بالبرنامج'); }
      else if (msg.pick !== undefined && msg.pick !== null && B.lines[msg.pick]) L = B.lines[msg.pick];
      else { const miss = (B.lines || []).filter((x, i) => !as[i] && !as.hold[i]); L = miss[0] || null; if (!L) notes.push('كل أساتذة البرنامج موجودون على الموقع — اختر أستاذاً من النافذة لتعبئته'); }
      if (!L) return { title: head, filled: n, notes };
      const res = B.kind === 'general' ? fillRoster(f2, L, !!curEdit) : await fillFinancial(f2, L);
      return { title: head, filled: n + res.filled, who: L.teacher_name, notes: notes.concat(res.notes), next: 'راجع الخانات ثمّ اكبس «حفظ» على الموقع.' };
    }
    if (msg.action === 'auto') {
      if (siteFiltered()) return { title: head, notes: ['لائحة الأساتذة على الموقع معروضة جزئياً (ملاك فقط / متعاقدين فقط / بحث) — افتحها كاملة بلا فلتر ثمّ أعد المحاولة، حتى لا يتكرّر أحد'] };
      const as2 = assign(B.lines || [], rv.rows);
      const lines = msg.mode === 'all' ? B.lines.slice() : B.lines.filter((x, i) => !as2[i]);
      if (!lines.length) return { title: head, notes: ['لا شيء للترحيل — كل الأساتذة موجودون على الموقع'] };
      const Q = { base: baseUrl(), kind: B.kind, period: B.period, mode: msg.mode === 'all' ? 'all' : 'missing', lines, all: B.lines.slice(), idx: 0, done: 0, errors: [], started: Date.now() };
      await chrome.storage.local.set({ eocAuto: Q });
      setTimeout(() => autoStep(Q), 300);
      return { title: head, notes: ['بدأ الترحيل التلقائي لـ' + lines.length + ' سطراً — تابع الشريط أعلى الصفحة؛ «إيقاف» يوقفه بأيّ لحظة'] };
    }
    return { title: head, notes: ['أمر غير معروف'] };
  }
  window.__msaEocRun = run; window.__msaEocReview = review; window.__msaEocEnsure = ensureAll; window.__msaEocRows = () => siteRows();
  if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.onMessage) {
    chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => { if (!msg || !msg.action || msg.target !== 'eoc') return; run(msg).then(sendResponse, (e) => sendResponse({ title: 'خطأ', notes: [String(e && e.message || e)] })); return true; });
  }
})();
