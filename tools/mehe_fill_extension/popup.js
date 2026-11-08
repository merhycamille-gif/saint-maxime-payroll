/* نافذة المساعد: يجلب JSON من البرنامج (بجلسته — من نافذة البرنامج المفتوحة أو بكوكيز المتصفّح) ثمّ يرسله إلى سكربت الصفحة:
   - موازنة الوزارة (psb.mehe.gov.lb): pages/mehe_budget.php?export=json ⇒ content.js
   - صندوق التعويضات (caisseindemnite.com): pages/eoc_portal.php?tab=…&period=…&export=json ⇒ content_eoc.js */
const $ = (s) => document.querySelector(s);
let budget = null, eocStmt = null, eocLists = null;
const fmt = (v) => String(Math.round(Number(v) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const esc = (s) => String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

chrome.storage.local.get(['base', 'budget', 'eocStmt', 'eocLists', 'eocAuto'], (st) => {
  if (st.base) $('#base').value = st.base;
  if (st.budget) { budget = st.budget; showInfo(); }
  if (st.eocLists) { eocLists = st.eocLists; showEocLists(); }
  if (st.eocStmt) { eocStmt = st.eocStmt; showEocInfo(); }
  if (st.eocAuto) $('#eocStop').style.display = '';
});
$('#base').addEventListener('change', () => chrome.storage.local.set({ base: $('#base').value }));
// أظهر القسم المناسب للموقع المفتوح أوّلاً
chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
  const u = (tabs && tabs[0] && tabs[0].url) || '';
  if (/caisseindemnite\.com/.test(u)) { $('#secMehe').classList.add('dim'); }
  else if (/psb\.mehe\.gov\.lb/.test(u)) { $('#secEoc').classList.add('dim'); document.body.insertBefore($('#secMehe'), $('#secEoc')); }
});

/* ===== الجلب من البرنامج ===== */
const BASES = ['https://msapayroll.com/', 'http://localhost/saint-maxime-payroll/', 'https://192.168.1.60/saint-maxime-payroll/'];
// يُنفَّذ داخل تبويب البرنامج نفسه (نفس الأصل ⇒ الكوكيز تُرسَل دائماً، حتى من نافذة التطبيق المثبّت)
function inTabFetch(base, path) {
  return fetch(base + path, { credentials: 'same-origin', cache: 'no-store' })
    .then(r => (r.headers.get('content-type') || '').indexOf('json') >= 0 ? r.json().then(j => ({ ok: true, json: j })) : { ok: false, url: r.url, status: r.status }, e => ({ ok: false, err: String(e) }));
}
const DIAG = []; const T0 = { t: 0 }; const since = () => ((Date.now() - T0.t) / 1000).toFixed(1) + 's';
const withTimeout = (p, ms, label) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error('مهلة ' + label + ' انتهت (' + ms / 1000 + ' ث)')), ms))]);
async function fetchViaTab(b, path) {
  let tabs = [];
  try { tabs = await withTimeout(chrome.tabs.query({ url: b + '*' }), 5000, 'tabs.query'); } catch (e) { DIAG.push('tabs.query: ' + (e.message || e)); }
  DIAG.push(since() + ' تبويبات البرنامج المفتوحة على ' + b + ': ' + tabs.length);
  const ping = (id) => withTimeout(chrome.scripting.executeScript({ target: { tabId: id }, func: () => location.href + ' | ' + document.readyState }), 5000, 'نبضة');
  for (const t of tabs) {
    let alive = false;
    try { await ping(t.id); alive = true; } catch (e) { DIAG.push(since() + ' تبويب ' + t.id + ' لا يستجيب: ' + (e.message || e)); }
    if (!alive) { // إيدج يُنيم نافذة التطبيق الخلفية ⇒ تفعيل ثمّ إعادة تحميل
      try {
        await chrome.tabs.update(t.id, { active: true });
        const tryWake = async (limitMs) => { const end = Date.now() + limitMs; while (!alive && Date.now() < end) { await new Promise(r => setTimeout(r, 700)); const cur = await chrome.tabs.get(t.id).catch(() => null); if (!cur || cur.discarded || cur.status !== 'complete') continue; try { await withTimeout(chrome.scripting.executeScript({ target: { tabId: t.id }, func: () => document.readyState }), 2500, 'نبضة'); alive = true; } catch (e) { /* نائم */ } } };
        await tryWake(8000);
        if (!alive) { DIAG.push(since() + ' إعادة تحميل نافذة البرنامج'); await chrome.tabs.reload(t.id); await tryWake(20000); }
      } catch (e) { DIAG.push(since() + ' تعذّر إيقاظه: ' + (e.message || e)); }
      if (!alive) continue;
    }
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        const [res] = await withTimeout(chrome.scripting.executeScript({ target: { tabId: t.id }, func: inTabFetch, args: [b, path] }), 30000, 'تبويب ' + t.id);
        const rr = res && res.result;
        if (rr && rr.ok) return rr.json;
        DIAG.push(since() + ' تبويب ' + t.id + ': ' + JSON.stringify(rr || null)); break;
      } catch (e) { DIAG.push(since() + ' تبويب ' + t.id + ' محاولة ' + attempt + ': ' + (e.message || e)); }
    }
  }
  return null;
}
async function fetchFrom(b, path) {
  const viaTab = await fetchViaTab(b, path);
  if (viaTab) return viaTab;
  const ac = new AbortController(); const tm = setTimeout(() => ac.abort(), 15000);
  const r = await fetch(b + path, { credentials: 'include', cache: 'no-store', signal: ac.signal }).finally(() => clearTimeout(tm));
  const ct = r.headers.get('content-type') || '';
  if (r.ok && ct.indexOf('json') >= 0) return r.json();
  DIAG.push('مباشر ' + b + ' ⇒ ' + r.status + ' ' + ct.split(';')[0] + ' @ ' + r.url);
  const atLogin = /login\.php/.test(r.url || '');
  const er = new Error(r.status !== 200 ? 'خطأ ' + r.status : (atLogin ? 'أنت غير مسجَّل دخولك بالبرنامج في هذا المتصفّح.' : 'البرنامج لم يُرجع البيانات — افتح صفحة البرنامج في هذا المتصفّح وسجّل دخولك ثمّ أعد الجلب.'));
  er.login = atLogin; er.url = r.url; throw er;
}
/** جلب من النسخة المختارة (أو تلقائي: أوّل نسخة فيها جلسة) — يعرض العدّاد والتشخيص بالصندوق المعطى */
async function fetchJson(path, boxSel) {
  const sel = $('#base').value; const box = $(boxSel); box.textContent = 'جارٍ الجلب…'; T0.t = Date.now(); DIAG.length = 0;
  const tick = setInterval(() => { box.textContent = 'جارٍ الجلب من البرنامج… ' + Math.round((Date.now() - T0.t) / 1000) + ' ث (الاستضافة قد تتأخّر، انتظر)'; }, 1000);
  let base = sel === 'auto' ? BASES[0] : sel;
  try {
    let got = null;
    if (sel === 'auto') { const errs = []; for (const b of BASES) { try { got = await fetchFrom(b, path); base = b; break; } catch (e) { errs.push(e); } } if (!got) { const real = errs.find(e => !e.login); throw real || Object.assign(new Error('أنت غير مسجَّل دخولك بالبرنامج في هذا المتصفّح (لا أونلاين ولا محلّي).'), { login: true }); } }
    else got = await fetchFrom(base, path);
    clearInterval(tick); return got;
  } catch (e) {
    clearInterval(tick);
    box.innerHTML = '<span class="bad">تعذّر الجلب: ' + esc(e.message || e) + '</span>'
      + (e.login ? '<br><span class="muted">الجلسة تُقرأ من هذا المتصفّح فقط. افتح البرنامج هنا وسجّل دخولك ثمّ أعد الجلب.</span><br><a href="#" class="openLogin">فتح صفحة دخول البرنامج في تبويب جديد</a>' : '')
      + (DIAG.length ? '<details><summary class="muted">تفاصيل تقنية</summary><div class="muted" dir="ltr" style="font-size:11px;word-break:break-all">' + DIAG.map(x => esc(x)).join('<br>') + '</div></details>' : '');
    DIAG.length = 0;
    const a = box.querySelector('.openLogin'); if (a) a.addEventListener('click', (ev) => { ev.preventDefault(); chrome.tabs.create({ url: base + 'login.php' }); });
    return null;
  }
}
/** إرسال أمر إلى سكربت الصفحة (مع حقنه إن لم يكن محمَّلاً) */
async function sendToTab(urlRe, file, msg, badMsg, outSel) {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  const out = $(outSel); out.style.display = 'block';
  if (!tab || !urlRe.test(tab.url || '')) { out.innerHTML = '<span class="bad">' + badMsg + '</span>'; return null; }
  out.textContent = 'جارٍ…';
  try { return await chrome.tabs.sendMessage(tab.id, msg); }
  catch (e) {
    try { await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: [file] }); return await chrome.tabs.sendMessage(tab.id, msg); }
    catch (e2) { out.innerHTML = '<span class="bad">تعذّر الوصول إلى الصفحة: ' + esc(e2.message || e2) + ' — حدّث الصفحة (F5) وأعد المحاولة.</span>'; return null; }
  }
}

/* ===== 🏛️ صندوق التعويضات ===== */
function showEocLists() {
  if (!eocLists) return;
  const s = $('#eocStmt'); s.innerHTML = '<option value="">اختر البيان…</option>';
  const grp = (label, arr, kind) => { if (!arr || !arr.length) return; const g = document.createElement('optgroup'); g.label = label; arr.forEach((x) => { const o = document.createElement('option'); o.value = kind + '|' + x.period; o.textContent = x.label + (x.saved ? (x.status === 'locked' ? ' — معتمد' : ' — محفوظ') : ''); g.appendChild(o); }); s.appendChild(g); };
  grp('البيان العام (سنوي)', eocLists.general, 'general'); grp('البيان المالي (فصلي)', eocLists.financial, 'financial');
  if (eocStmt) s.value = eocStmt.kind + '|' + eocStmt.period;
  s.style.display = ''; $('#eocFetch').style.display = '';
  if (!eocStmt) $('#eocInfo').innerHTML = '<b>' + esc(eocLists.school) + '</b> — رقم المدرسة ' + esc(eocLists.school_no) + '<br><span class="muted">اختر البيان ثمّ «جلب هذا البيان»</span>';
}
function showEocInfo() {
  if (!eocStmt) return;
  const n = (eocStmt.lines || []).length; const miss = (eocStmt.lines || []).filter((l) => l.missing && l.missing.length).length;
  const t = eocStmt.totals || {};
  $('#eocInfo').innerHTML = '<b>' + esc(eocStmt.school) + '</b> — ' + (eocStmt.kind === 'general' ? 'البيان العام ' : 'البيان المالي ') + esc(eocStmt.period)
    + '<br>أسطر: <b>' + n + '</b>' + (eocStmt.kind === 'general' ? ' · ملاك ' + (t.staff || 0) + ' · متعاقدون ' + (t.contract || 0) : ' · المجموع العام ' + fmt(t.grand || 0) + ' ل.ل')
    + (miss ? '<br><span class="bad">⚠️ ' + miss + ' سطراً فيه خانات ناقصة بالبرنامج (تُعبَّأ ناقصة ويرفضها الموقع عند الحفظ)</span>' : '<br><span class="ok">✅ كل الخانات مكتملة</span>')
    + '<br><span class="muted">جُلب ' + new Date(eocStmt.generated).toLocaleString('ar-LB') + '</span>';
  ['#eocCheck', '#eocFill', '#eocAutoMissing', '#eocAutoAll'].forEach((s) => { $(s).disabled = false; });
  const p = $('#eocPick'); p.innerHTML = '<option value="">تعبئة النموذج بـ: أوّل أستاذ ناقص على الموقع (تلقائي)</option>';
  (eocStmt.lines || []).forEach((l, i) => { const o = document.createElement('option'); o.value = String(i); o.textContent = l.teacher_name + (l.teacher_no ? ' — ' + l.teacher_no : ''); p.appendChild(o); });
  p.style.display = '';
}
$('#eocList').addEventListener('click', async () => {
  const j = await fetchJson('pages/eoc_portal.php?tab=general&export=json', '#eocInfo');
  if (!j) return; eocLists = j; chrome.storage.local.set({ eocLists }); eocStmt = null; chrome.storage.local.set({ eocStmt: null }); showEocLists();
});
$('#eocFetch').addEventListener('click', async () => {
  const v = $('#eocStmt').value; if (!v) { $('#eocInfo').innerHTML = '<span class="bad">اختر البيان أوّلاً</span>'; return; }
  const [kind, period] = v.split('|');
  const j = await fetchJson('pages/eoc_portal.php?tab=' + kind + '&period=' + encodeURIComponent(period) + '&export=json', '#eocInfo');
  if (!j) return; eocStmt = j; chrome.storage.local.set({ eocStmt }); showEocInfo();
});
const EOC_RE = /^https:\/\/(www\.)?caisseindemnite\.com\//;
const EOC_BAD = 'افتح بوّابة صندوق التعويضات (caisseindemnite.com) في هذا التبويب: البيان العام ← تعبئة، أو البيان المالي ← تعبئة.';
function renderEoc(res) {
  const out = $('#eocOut'); if (!res) { out.innerHTML = '<span class="bad">لا جواب من الصفحة</span>'; return; }
  let h = '<b>' + esc(res.title || '') + '</b>';
  if (res.matched !== undefined) h += '<br>موجودون على الموقع ومطابقون: <b>' + res.matched + '</b>';
  if (res.missing && res.missing.length) h += '<br><span class="bad">ناقصون على الموقع (' + res.missing.length + '):</span><ul>' + res.missing.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>';
  else if (res.missing) h += '<br><span class="ok">لا أحد ناقص على الموقع</span>';
  if (res.extra && res.extra.length) h += '<br><span class="muted">على الموقع وليسوا ببيان البرنامج (' + res.extra.length + '): ' + res.extra.map(esc).join('، ') + '</span>';
  if (res.who) h += '<br>عُبِّئ النموذج بـ<b>' + esc(res.who) + '</b> (' + res.filled + ' خانة)';
  if (res.notes && res.notes.length) h += '<ul>' + res.notes.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>';
  if (res.next) h += '<br><span class="ok">' + esc(res.next) + '</span>';
  out.innerHTML = h;
}
$('#eocCheck').addEventListener('click', async () => renderEoc(await sendToTab(EOC_RE, 'content_eoc.js', { target: 'eoc', action: 'check', statement: eocStmt }, EOC_BAD, '#eocOut')));
$('#eocFill').addEventListener('click', async () => { const pick = $('#eocPick').value; renderEoc(await sendToTab(EOC_RE, 'content_eoc.js', { target: 'eoc', action: 'fill', statement: eocStmt, pick: pick === '' ? null : Number(pick) }, EOC_BAD, '#eocOut')); });
async function eocAuto(mode) {
  const n = (eocStmt.lines || []).length;
  if (!confirm(mode === 'all' ? 'ترحيل تلقائي لكل الأسطر (' + n + '): الناقص يُضاف والموجود يُعدَّل بأرقام البرنامج، ويكبس «حفظ» تلقائياً سطراً سطراً. متابعة؟' : 'ترحيل تلقائي: يضيف الأساتذة الناقصين على الموقع فقط ويكبس «حفظ» تلقائياً لكل واحد. الموجودون لا يُمسّون. متابعة؟')) return;
  const res = await sendToTab(EOC_RE, 'content_eoc.js', { target: 'eoc', action: 'auto', mode, statement: eocStmt }, EOC_BAD, '#eocOut');
  renderEoc(res); if (res && !(res.notes || []).some((x) => /لا شيء/.test(x))) $('#eocStop').style.display = '';
}
$('#eocAutoMissing').addEventListener('click', () => eocAuto('missing'));
$('#eocAutoAll').addEventListener('click', () => eocAuto('all'));
$('#eocStop').addEventListener('click', async () => { await chrome.storage.local.set({ eocAuto: null }); $('#eocStop').style.display = 'none'; renderEoc(await sendToTab(EOC_RE, 'content_eoc.js', { target: 'eoc', action: 'stop', statement: eocStmt || { lines: [] } }, EOC_BAD, '#eocOut')); });

/* ===== 🏫 موازنة الوزارة ===== */
function showInfo() {
  if (!budget) return;
  const s = budget.staff || {};
  $('#info').innerHTML = `<b>${esc(budget.school)}</b> — ${esc(budget.sy)}<br>ملاك ${s.tit ? s.tit.length : 0} · متعاقدون ${s.con ? s.con.length : 0} · إداريون ${s.adm ? s.adm.length : 0} · معفيون ${(budget.students || []).length} · صفوف أقساط ${(budget.revenues || []).length}`
    + `<br><span class="${budget.summary && budget.summary.okBalance && budget.summary.okRatio ? 'ok' : 'bad'}">${budget.summary && budget.summary.okBalance && budget.summary.okRatio ? '✅ متوازنة وضمن النسب' : '⚠️ غير متوازنة بعد — الفرق ' + fmt(budget.summary ? budget.summary.diffL : 0) + ' ل.ل'}</span>`
    + `<br><span class="muted">جُلبت ${new Date(budget.generated).toLocaleString('ar-LB')}</span>`;
  $('#fill').disabled = false; $('#check').disabled = false;
}
$('#fetch').addEventListener('click', async () => { const j = await fetchJson('pages/mehe_budget.php?export=json', '#info'); if (!j) return; budget = j; chrome.storage.local.set({ budget }); showInfo(); });
function render(res) {
  if (!res) { $('#out').innerHTML = '<span class="bad">لا جواب من الصفحة</span>'; return; }
  let h = `<b>${esc(res.title || '')}</b><br>خانات عُبِّئت: <b>${res.filled}</b> · موظفون طُوبقوا: <b>${res.matched}</b>`;
  if (res.unmatched && res.unmatched.length) h += `<br><span class="bad">موظفون غير موجودين بصفحة الوزارة (${res.unmatched.length}):</span><ul>` + res.unmatched.map(x => `<li>${esc(x)}</li>`).join('') + '</ul><span class="muted">أضفهم على الموقع (إن كان فيه زرّ إضافة) ثمّ أعد التعبئة.</span>';
  if (res.notes && res.notes.length) h += '<ul>' + res.notes.map(x => `<li>${esc(x)}</li>`).join('') + '</ul>';
  if (res.filled) h += '<br><span class="ok">راجع كل قسم بـ«التالي»، ثمّ اكبس «أرسل» على الموقع.</span>';
  $('#out').innerHTML = h;
}
const MEHE_RE = /^https:\/\/psb\.mehe\.gov\.lb\//; const MEHE_BAD = 'افتح صفحة الموازنة على psb.mehe.gov.lb في هذا التبويب أوّلاً (أرسل الآن ← صفحة الإدخال).';
$('#fill').addEventListener('click', async () => render(await sendToTab(MEHE_RE, 'content.js', { action: 'fill', budget }, MEHE_BAD, '#out')));
$('#check').addEventListener('click', async () => render(await sendToTab(MEHE_RE, 'content.js', { action: 'check', budget }, MEHE_BAD, '#out')));
