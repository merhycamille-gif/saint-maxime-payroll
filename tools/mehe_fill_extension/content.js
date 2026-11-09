/* سكربت صفحة psb.mehe.gov.lb: يستقبل موازنة البرنامج (JSON) ويعبّئ خانات صفحة الموازنة بأسمائها الحرفية.
   لا يكبس «التالي» ولا «أرسل» ولا «حفظ» — التعبئة فقط، والمراجعة والإرسال للمستخدم. */
(function () {
  if (window.__msaMeheFill) return; window.__msaMeheFill = true;
  const norm = (s) => String(s || '').replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/[ًٌٍَُِّْـ]/g, '').replace(/\s+/g, ' ').trim();
  const txt = (el) => norm(el ? (el.innerText || el.textContent) : '');
  const fire = (el) => { ['input', 'change', 'blur'].forEach((t) => el.dispatchEvent(new Event(t, { bubbles: true }))); };
  let filled = 0, matched = 0; const unmatched = [], notes = [];

  function setVal(el, v) {
    if (!el) return false;
    if (el.disabled) el.disabled = false; if (el.readOnly) el.readOnly = false;
    const s = v === null || v === undefined ? '' : String(v);
    if (el.tagName === 'SELECT') return setSelect(el, s);
    if (el.type === 'checkbox') { const want = !!v; if (el.checked !== want) { el.checked = want; fire(el); filled++; } return true; }
    if (el.value !== s) { el.value = s; fire(el); filled++; }
    return true;
  }
  function setSelect(sel, label, { multi = false } = {}) {
    const want = Array.isArray(label) ? label.map(norm) : [norm(label)];
    let found = false, changed = false;
    Array.from(sel.options).forEach((o) => { const on = want.indexOf(norm(o.textContent)) >= 0; if (on) found = true; if (multi) { if (o.selected !== on) changed = true; o.selected = on; } else if (on && sel.value !== o.value) { sel.value = o.value; changed = true; } });
    if (changed) { fire(sel); filled++; if (window.jQuery && window.jQuery(sel).hasClass('select2-hidden-accessible')) { try { window.jQuery(sel).trigger('change'); } catch (e) {} } }
    if (!found && label && String(label).trim() !== '') notes.push('قيمة غير موجودة بقائمة الموقع: «' + (Array.isArray(label) ? label.join('، ') : label) + '»');
    return found;
  }
  const byName = (n) => document.querySelector('[name="' + n.replace(/"/g, '\\"') + '"]');
  const checkByLabel = (labelText, on) => { // خانة تفتيش بنصّ تسميتها
    const want = norm(labelText);
    const lab = Array.from(document.querySelectorAll('label')).find((l) => norm(l.textContent) === want);
    if (!lab) { notes.push('خانة تفتيش غير موجودة: «' + labelText + '»'); return; }
    const inp = lab.htmlFor ? document.getElementById(lab.htmlFor) : lab.querySelector('input') || lab.previousElementSibling;
    if (inp && inp.type === 'checkbox') setVal(inp, on);
  };
  const rowByLabel = (tableOrNull, label) => { // صفّ جدول بنصّ أوّل خلية (أو hidden ExpenceName)
    const want = norm(label);
    const rows = Array.from((tableOrNull || document).querySelectorAll('tr'));
    return rows.find((tr) => { const td = tr.querySelector('td'); if (!td) return false; const hid = td.querySelector('input[type=hidden][name$=".ExpenceName"]'); return norm(hid ? hid.value : td.textContent) === want || (hid && want.indexOf(norm(hid.value)) === 0 && hid.value.length > 6); });
  };
  const numIn = (tr) => Array.from(tr.querySelectorAll('input:not([type=hidden]):not([type=radio]):not([type=checkbox])'));

  function fillInfo(b) {
    setVal(byName('Budget.PrincipalName'), b.info.director); setVal(byName('Budget.ParentsCommitteeChairman'), b.info.parents_head); setVal(byName('Budget.ParentsCommitteeChairmanPhoneNumber'), b.info.parents_phone);
    document.querySelectorAll('input[name^="Budget.SelectedPrograms"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, b.info.programs.map(norm).indexOf(norm(l.textContent)) >= 0); });
    document.querySelectorAll('input[name^="Budget.SelectedEducationLevels"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, b.info.levels.map(norm).indexOf(norm(l.textContent)) >= 0); });
    document.querySelectorAll('input[name^="Budget.SelectedClasses"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, b.info.classes.map(norm).indexOf(norm(l.textContent)) >= 0); });
    (b.info.fin || []).forEach((nm, i) => setVal(byName('Budget.FinancialCommitteeMembers[' + i + '].Name'), nm));
  }
  function fillSurvey(b) {
    const s = b.survey;
    setVal(byName('Budget.AreaOfOpenPlaygrounds'), s.open); setVal(byName('Budget.AreaOfClosedPlaygrounds'), s.closed); setVal(byName('Budget.BuildingOwnerName'), s.owner_name);
    document.querySelectorAll('input[name="Budget.SelectedBuildingOwners"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, s.owner.map(norm).indexOf(norm(l.textContent)) >= 0); });
    document.querySelectorAll('input[name="Budget.SelectedBuildingSharedWith"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, s.shared.map(norm).indexOf(norm(l.textContent)) >= 0); });
    document.querySelectorAll('input[name="Budget.SelectedInternetUsage"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, s.internet.map(norm).indexOf(norm(l.textContent)) >= 0); });
    document.querySelectorAll('input[name="Budget.SelectedBudgetOtherDetail"]').forEach((cb) => { const l = document.querySelector('label[for="' + cb.id + '"]'); if (l) setVal(cb, s.other.map(norm).indexOf(norm(l.textContent)) >= 0); });
    Object.keys(s.buildings).forEach((k) => { const tr = rowByLabel(null, k); if (tr) setVal(numIn(tr)[0], s.buildings[k]); });
    Object.keys(s.rooms).forEach((k) => { const tr = rowByLabel(null, k); if (tr) setVal(numIn(tr)[0], s.rooms[k]); else notes.push('غرفة غير موجودة: ' + k); });
    // المعدّات: المنصّة تكتب «ناسخ scanner» وبرنامجنا «scanner ناسخ» ⇒ مطابقة بمجموعة الكلمات (بلا ترتيب، بلا حالة أحرف)
    const tok = (x) => norm(x).toLowerCase().replace(/\bicd\b/, 'lcd').split(' ').filter(Boolean).sort().join(' '); // المنصّة تكتب «Icd» بحرف I
    const eqRows = Array.from(document.querySelectorAll('input[name^="Budget.BudgetTechnologyEquipmentsPickListItemLite"]')).map((i) => i.closest('tr')).filter((r, i, a) => r && a.indexOf(r) === i);
    Object.keys(s.equipment).forEach((k) => { const tr = eqRows.find((r) => tok(r.querySelector('td').textContent) === tok(k)) || rowByLabel(null, k); if (tr) { const ins = numIn(tr); setVal(ins[0], s.equipment[k].admin); setVal(ins[1], s.equipment[k].edu); } else notes.push('معدّة غير موجودة: ' + k); });
    Object.keys(s.languages).forEach((k) => { const tr = rowByLabel(null, k); if (!tr) return; const radios = Array.from(tr.querySelectorAll('input[type=radio]')); const idx = Math.max(0, Math.min(2, (s.languages[k] || 3) - 1)); const r = radios[idx]; if (r) { if (r.disabled) r.disabled = false; if (!r.checked) { r.click(); fire(r); filled++; } }
      const hids = tr.querySelectorAll('input[type=hidden][name$=".IsPrimary"], input[type=hidden][name$=".IsSecondary"], input[type=hidden][name$=".IsNotExistInSchool"]'); hids.forEach((h, i) => { h.value = i === idx ? 'true' : 'false'; }); });
  }
  function staffTable(id) { return document.getElementById(id); }
  /* 🤝 (2026-11-09، بعد حادثة مطابقة صندوق التعويضات بالفرزل — «عم نشتغل مع الدولة مع أرزاق الناس»): مطابقة جدول الموظفين كلّه معاً على مراحل:
     معرّف الموظف (FundId) عند الجميع أوّلاً، ثمّ الاسم الحرفي، ثمّ أوّل كلمتين، ثمّ هيكل الاسم (اندره/اندريه…). الضمانات:
     · سطر الموقع لا يأخذه موظفان (كان الثاني يكتب فوق الأوّل) · سطر معرّفه لموظف آخر بالموازنة لا يُعطى لغيره ·
     · معرّف البرنامج ≠ معرّف السطر ⇒ لا تعبئة · أكثر من مرشّح ⇒ لا تعبئة. كل حالة شكّ تُذكر بالملاحظات ولا تُعبَّأ. تُرجع سطر الموقع لكل موظف (أو null). */
  function assignStaff(table, emps, allIds) {
    const rows = Array.from(table.querySelectorAll('tbody tr')).filter((tr) => tr.querySelector('input[name$=".Name"]'));
    const rn = (r) => norm((r.querySelector('input[name$=".Name"]') || {}).value); const rid = (r) => norm((r.querySelector('input[name$=".FundId"]') || {}).value);
    const sk = (s) => norm(s).replace(/[اويءآ]/g, '');
    const res = (emps || []).map(() => null); const used = new Set();
    const free = (r, idv) => { if (used.has(r)) return false; const x = rid(r); if (!x) return true; return idv ? x === idv : !allIds.has(x); };
    const passes = [
      (idv) => (idv ? rows.filter((r) => !used.has(r) && rid(r) === idv) : []),
      (idv, nm) => (nm ? rows.filter((r) => free(r, idv) && rn(r) === nm) : []),
      (idv, nm) => { if (!nm) return []; const k2 = nm.split(' ').slice(0, 2).join(' '); return rows.filter((r) => free(r, idv) && rn(r).indexOf(k2) === 0); },
      (idv, nm) => (nm ? rows.filter((r) => free(r, idv) && sk(rn(r)) === sk(nm)) : []),
    ];
    passes.forEach((pass) => (emps || []).forEach((e, i) => { if (res[i]) return; const c = pass(norm(e.mehe_id), norm(e.name)); if (c.length === 1) { res[i] = c[0]; used.add(c[0]); } }));
    (emps || []).forEach((e, i) => { if (res[i]) return; const idv = norm(e.mehe_id), nm = norm(e.name);
      const same = rows.filter((r) => rn(r) === nm);
      if (same.length > 1) notes.push('✋ ' + e.name + ': الاسم مكرّر على الموقع (' + same.length + ' أسطر) — لم يُعبَّأ، عبّه بيدك');
      else if (same.length === 1 && idv && rid(same[0]) && rid(same[0]) !== idv) notes.push('✋ ' + e.name + ': معرّف الموظف بالبرنامج (' + idv + ') غير المعرّف على الموقع (' + rid(same[0]) + ') — لم يُعبَّأ، راجعه');
      else if (same.length === 1 && used.has(same[0])) notes.push('✋ ' + e.name + ': سطره على الموقع أخذه موظف آخر بنفس الاسم/المعرّف — لم يُعبَّأ، راجعه'); });
    return res;
  }
  const staffIds = (b) => { const s = new Set(); ['tit', 'con', 'adm'].forEach((k) => ((b.staff || {})[k] || []).forEach((e) => { const v = norm(e.mehe_id); if (v) s.add(v); })); return s; };
  const setSfx = (tr, sfx, v) => setVal(tr.querySelector('input[name$=".' + sfx + '"]'), v);
  const selSfx = (tr, sfx, label, opt) => { const el = tr.querySelector('select[name$="' + sfx + '"]'); if (el && label) setSelect(el, label, opt || {}); };
  // قوائم الصفّ تُعرَف بمحتواها لا بترتيبها (ترتيب PickListItems[n] يختلف من صفّ لآخر على المنصّة): القائمة التي فيها «أستاذ \ معلم» هي الدور…
  const PROBE = { role: ['أستاذ \\ معلم', 'ناظر', 'محاسب', 'اداري'], qual: ['بكالوريا قسم ثاني', 'اجازة تعليمية', 'اجازة جامعية', 'غيره'], mode: ['دوام كامل', 'دوام جزئي'], nssf: ['مضمون', 'غير مضمون'], adm: ['فوق السن', 'اجنبي'], level: ['كل المراحل', 'روضة'] };
  const selOf = (tr, kind) => { const probes = PROBE[kind].map(norm); return Array.from(tr.querySelectorAll('select')).find((s) => { const opts = Array.from(s.options).map((o) => norm(o.textContent)); return probes.every((p) => opts.indexOf(p) >= 0); }) || null; };
  const selKind = (tr, kind, label, opt) => { const el = selOf(tr, kind); if (!label) return;
    const lab = kind === 'level' ? String(label).split(/[،,]/).map((x) => x.trim()).filter(Boolean) : label; // المستوى متعدّد: «متوسط، ثانوي»
    if (el) setSelect(el, lab, opt || {}); else notes.push('قائمة «' + kind + '» غير موجودة بصفّ ' + ((tr.querySelector('input[name$=".Name"]') || {}).value || '')); };
  function fillStaff(b) {
    const T = staffTable('permanentherTable'); const C = staffTable('conractedTeacherTable'); const A = staffTable('adminTeacherTable');
    const ids = staffIds(b); const mT = T ? assignStaff(T, b.staff.tit, ids) : [], mC = C ? assignStaff(C, b.staff.con, ids) : [], mA = A ? assignStaff(A, b.staff.adm, ids) : [];
    if (T) b.staff.tit.forEach((e, i) => { const tr = mT[i]; if (!tr) { unmatched.push('ملاك: ' + e.name); return; } matched++;
      if (e.mehe_id) setSfx(tr, 'FundId', e.mehe_id);
      selKind(tr, 'role', e.role); selKind(tr, 'level', e.level, { multi: true }); selKind(tr, 'qual', e.qual);
      setSfx(tr, 'StartDate', e.cadre_date); setSfx(tr, 'WorkStartDate', e.start_date); setSfx(tr, 'WeeklyHoursPerm', e.h_cadre); setSfx(tr, 'WeeklyHoursContract', e.h_extra);
      setSfx(tr, 'BasicSalary', e.base); setSfx(tr, 'SocialAssistanceLL', e.extra_ll); setSfx(tr, 'SocialAssistanceUSD', e.extra_usd); setSfx(tr, 'RetroEffectLL', e.retro);
      setSfx(tr, 'CompensationWorkDoneAboveQuotaLL', e.missions_ll); setSfx(tr, 'CompensationWorkGreater35HoursLL', e.missions35); setSfx(tr, 'BonuseAssistanceLL', e.bonus); setSfx(tr, 'AdditionalTasksFees', e.tasks_ll);
      setSfx(tr, 'FamilyContributionLL', e.family); setSfx(tr, 'TransportationLL', e.transport); setSfx(tr, 'NSSFContributionLL', e.cnss); setSfx(tr, 'CompensationFundLL', e.fund); });
    if (C) b.staff.con.forEach((e, i) => { const tr = mC[i]; if (!tr) { unmatched.push('متعاقد: ' + e.name); return; } matched++;
      if (e.mehe_id) setSfx(tr, 'FundId', e.mehe_id);
      selKind(tr, 'role', e.role); selKind(tr, 'level', e.level, { multi: true }); selKind(tr, 'mode', e.mode); selKind(tr, 'nssf', e.cnss_type); selKind(tr, 'qual', e.qual);
      setSfx(tr, 'WorkStartDate', e.start_date); setSfx(tr, 'WeeklyHoursContract', e.h_cadre);
      setSfx(tr, 'BasicSalary', e.base); setSfx(tr, 'SocialAssistanceLL', e.extra_ll); setSfx(tr, 'SocialAssistanceUSD', e.extra_usd); setSfx(tr, 'BonuseAssistanceLL', e.bonus); setSfx(tr, 'AdditionalTasksFees', e.tasks_ll); setSfx(tr, 'TransportationLL', e.transport); setSfx(tr, 'NSSFContributionLL', e.cnss); });
    if (A) b.staff.adm.forEach((e, i) => { const tr = mA[i]; if (!tr) { unmatched.push('إداري: ' + e.name); return; } matched++;
      if (e.mehe_id) setSfx(tr, 'FundId', e.mehe_id);
      selKind(tr, 'role', e.role); selKind(tr, 'adm', e.admin_type); selKind(tr, 'nssf', e.cnss_type);
      setSfx(tr, 'WorkStartDate', e.start_date); setSfx(tr, 'BasicSalary', e.base); setSfx(tr, 'SocialAssistanceLL', e.extra_ll); setSfx(tr, 'SocialAssistanceUSD', e.extra_usd); setSfx(tr, 'AdditionalTasksFees', e.tasks_ll); setSfx(tr, 'ScholarshipsLL', e.grants_ll); setSfx(tr, 'TransportationLL', e.transport); setSfx(tr, 'NSSFContributionLL', e.cnss); });
    setVal(byName('Budget.PermanentTeaherSalaryMonths'), b.months.tit.salary); setVal(byName('Budget.PermanentTeaherTransportationMonths'), b.months.tit.transport);
    setVal(byName('Budget.ContractedTeaherSalaryMonths'), b.months.con.salary); setVal(byName('Budget.ContractedTeaherTransportationMonths'), b.months.con.transport);
    setVal(byName('Budget.AdministrativeTeaherSalaryMonths'), b.months.adm.salary); setVal(byName('Budget.AdministrativeTeaherTransportationMonths'), b.months.adm.transport);
  }
  function fillStructure(b) {
    const s = b.structure;
    [['عدد الإداريين الخاضعين لقانون العمل', s.admin_law], ['عدد المستخدمين الخاضعين لقانون العمل', s.workers_law], ['عدد باقي المرتبطين بسير العمل', s.others], ['عدد القائمين بالإدارة التعليمية (مدير-مساعد-منسق-مشرف)', s.mgmt], ['عدد القائمين بالنظارة', s.supervision]]
      .forEach(([lb, v]) => { const tr = rowByLabel(null, lb) || Array.from(document.querySelectorAll('tr')).find((r) => { const td = r.querySelector('td'); return td && norm(td.textContent).indexOf(norm(lb).slice(0, 18)) === 0; }); if (tr) setVal(numIn(tr)[0], v); else notes.push('سطر هيكل غير موجود: ' + lb); });
    Object.keys(s.classes).forEach((lv) => { const el = Array.from(document.querySelectorAll('input[name^="Budget.BudgetEducationLevelPickListItemLite"]')).find((i) => { const tr = i.closest('tr'); return tr && norm(tr.querySelector('td').textContent) === norm(lv); }); if (el) setVal(el, s.classes[lv]); });
  }
  function fillList(tableId, items, mapRow, what) { // جداول بأسطر مرقّمة (معفيون/صرف): تعبئة الأسطر الموجودة بالترتيب؛ الزائد يُذكر
    const t = document.getElementById(tableId); if (!t) return;
    const rows = Array.from(t.querySelectorAll('tbody tr')).filter((tr) => tr.querySelector('input,select'));
    items.forEach((it, i) => { const tr = rows[i]; if (!tr) return; mapRow(tr, it); });
    if (items.length > rows.length) notes.push(what + ': الموقع فيه ' + rows.length + ' سطراً والبرنامج ' + items.length + ' — أضف ' + (items.length - rows.length) + ' أسطر على الموقع ثمّ أعد التعبئة');
  }
  function fillStudents(b) {
    fillList('studentTable', b.students, (tr, g) => { setSfx(tr, 'StudentName', g.student); selSfx(tr, 'TeacherLocalIdentifier', g.teacher); selSfx(tr, 'PickListItemId', g.class); setSfx(tr, 'ScholarshipAmountLL', g.ll); setSfx(tr, 'ScholarshipAmountUSD', g.usd); }, 'الطلاب المعفيون');
  }
  function fillSeverance(b) {
    fillList('severanceCompensationTable', b.severance, (tr, x) => { const ins = numIn(tr); const vals = [x.name, x.eos_ll, x.eos_usd, x.tasks_ll, x.tasks_usd, x.receipt_no, x.receipt_date, x.notes]; vals.forEach((v, i) => setVal(ins[i], v)); }, 'تعويضات الصرف');
  }
  function fillExpenses(b) {
    Object.keys(b.expenses).forEach((lb) => { const hid = Array.from(document.querySelectorAll('input[type=hidden][name$=".ExpenceName"]')).find((h) => norm(lb).indexOf(norm(h.value)) === 0 || norm(h.value) === norm(lb)); const tr = hid ? hid.closest('tr') : rowByLabel(null, lb); if (!tr) { notes.push('بند نفقات غير موجود: ' + lb); return; }
      setVal(tr.querySelector('input[name$=".AmountLL"]'), b.expenses[lb].ll); setVal(tr.querySelector('input[name$=".AmountUSD"]'), b.expenses[lb].usd); });
  }
  function fillRevenues(b) {
    b.revenues.forEach((rv) => { const tr = Array.from(document.querySelectorAll('input[name^="Budget.BudgetPickListItemLite"][name$=".TuitionLL"]')).map((i) => i.closest('tr')).find((r) => { const tds = r.querySelectorAll('td'); return tds.length > 1 && norm(tds[1].textContent) === norm(rv.class); });
      if (!tr) { notes.push('صفّ أقساط غير موجود: ' + rv.class); return; }
      setVal(tr.querySelector('input[name$=".TuitionLL"]'), rv.fee_ll); setVal(tr.querySelector('input[name$=".TuitionUSD"]'), rv.fee_usd); setVal(tr.querySelector('input[name$=".NumberOfStudents"]'), rv.students); });
  }
  function run(b, dry) {
    filled = 0; matched = 0; unmatched.length = 0; notes.length = 0;
    if (!b || !b.staff) return { title: 'لا موازنة', filled: 0, matched: 0, unmatched: [], notes: ['اجلب الموازنة من البرنامج أوّلاً'] };
    if (dry) { // مراجعة فقط: مطابقة الموظفين
      const T = staffTable('permanentherTable'), C = staffTable('conractedTeacherTable'), A = staffTable('adminTeacherTable');
      const ids = staffIds(b);
      if (T) { const m = assignStaff(T, b.staff.tit, ids); b.staff.tit.forEach((e, i) => { if (m[i]) matched++; else unmatched.push('ملاك: ' + e.name); }); }
      if (C) { const m = assignStaff(C, b.staff.con, ids); b.staff.con.forEach((e, i) => { if (m[i]) matched++; else unmatched.push('متعاقد: ' + e.name); }); }
      if (A) { const m = assignStaff(A, b.staff.adm, ids); b.staff.adm.forEach((e, i) => { if (m[i]) matched++; else unmatched.push('إداري: ' + e.name); }); }
      if (!T && !C && !A) notes.push('هذه ليست صفحة إدخال الموازنة (لا جداول موظفين) — افتح «أرسل الآن» بموازنة السنة');
      return { title: b.school + ' — ' + b.sy + ' (مراجعة)', filled: 0, matched, unmatched: unmatched.slice(), notes: notes.slice() };
    }
    try { fillInfo(b); } catch (e) { notes.push('معلومات المدرسة: ' + e.message); }
    try { fillSurvey(b); } catch (e) { notes.push('الاستطلاع: ' + e.message); }
    try { fillStaff(b); } catch (e) { notes.push('الموظفون: ' + e.message); }
    try { fillStructure(b); } catch (e) { notes.push('الهيكل: ' + e.message); }
    try { fillStudents(b); } catch (e) { notes.push('المعفيون: ' + e.message); }
    try { fillSeverance(b); } catch (e) { notes.push('الصرف: ' + e.message); }
    try { fillExpenses(b); } catch (e) { notes.push('النفقات: ' + e.message); }
    try { fillRevenues(b); } catch (e) { notes.push('الإيرادات: ' + e.message); }
    return { title: b.school + ' — ' + b.sy, filled, matched, unmatched: unmatched.slice(), notes: Array.from(new Set(notes)) };
  }
  window.__msaMeheRun = run; // للفحص المحلي
  if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.onMessage) {
    chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => { if (!msg || !msg.action) return; sendResponse(run(msg.budget, msg.action === 'check')); return true; });
  }
})();
