/* نافذة المساعد: جلب JSON الموازنة من البرنامج (بجلسته) ثمّ إرسالها إلى سكربت الصفحة على psb.mehe.gov.lb ليعبّئ الخانات */
const $ = (s) => document.querySelector(s);
let budget = null;
const fmt = (v) => String(Math.round(Number(v) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

chrome.storage.local.get(['base', 'budget'], (st) => {
  if (st.base) $('#base').value = st.base;
  if (st.budget) { budget = st.budget; showInfo(); }
});
$('#base').addEventListener('change', () => chrome.storage.local.set({ base: $('#base').value }));

function showInfo() {
  if (!budget) return;
  const s = budget.staff || {};
  $('#info').innerHTML = `<b>${budget.school}</b> — ${budget.sy}<br>ملاك ${s.tit ? s.tit.length : 0} · متعاقدون ${s.con ? s.con.length : 0} · إداريون ${s.adm ? s.adm.length : 0} · معفيون ${(budget.students || []).length} · صفوف أقساط ${(budget.revenues || []).length}`
    + `<br><span class="${budget.summary && budget.summary.okBalance && budget.summary.okRatio ? 'ok' : 'bad'}">${budget.summary && budget.summary.okBalance && budget.summary.okRatio ? '✅ متوازنة وضمن النسب' : '⚠️ غير متوازنة بعد — الفرق ' + fmt(budget.summary ? budget.summary.diffL : 0) + ' ل.ل'}</span>`
    + `<br><span class="muted">جُلبت ${new Date(budget.generated).toLocaleString('ar-LB')}</span>`;
  $('#fill').disabled = false; $('#check').disabled = false;
}

const BASES = ['https://msapayroll.com/', 'http://localhost/saint-maxime-payroll/', 'https://192.168.1.60/saint-maxime-payroll/'];
// يُنفَّذ داخل تبويب البرنامج نفسه (نفس الأصل ⇒ الكوكيز تُرسَل دائماً، حتى من نافذة التطبيق المثبّت)
function inTabFetch(base) {
  return fetch(base + 'pages/mehe_budget.php?export=json', { credentials: 'same-origin', cache: 'no-store' })
    .then(r => (r.headers.get('content-type') || '').indexOf('json') >= 0 ? r.json().then(j => ({ ok: true, json: j })) : { ok: false, url: r.url, status: r.status }, e => ({ ok: false, err: String(e) }));
}
const DIAG = []; // سجلّ المحاولات (يُعرض تحت رسالة الخطأ للتشخيص)
async function fetchViaTab(b) {
  // أيّ تبويب/نافذة مفتوحة على هذه النسخة من البرنامج (بما فيها نافذة التطبيق المثبّت)
  let tabs = [];
  try { tabs = await chrome.tabs.query({ url: b + '*' }); } catch (e) { DIAG.push('tabs.query: ' + (e.message || e)); }
  DIAG.push('تبويبات البرنامج المفتوحة على ' + b + ': ' + tabs.length);
  for (const t of tabs) {
    try {
      const [res] = await chrome.scripting.executeScript({ target: { tabId: t.id }, func: inTabFetch, args: [b] });
      const rr = res && res.result;
      if (rr && rr.ok) return rr.json;
      DIAG.push('تبويب ' + t.id + ': ' + JSON.stringify(rr || null));
    } catch (e) { DIAG.push('تبويب ' + t.id + ' حقن: ' + (e.message || e)); }
  }
  return null;
}
async function fetchFrom(b) {
  // أوّلاً من داخل نافذة/تبويب البرنامج المفتوح (= المدرسة والسنة اللتان يراهما المستخدم أمامه، ولو كانت نافذة التطبيق المثبّت)
  const viaTab = await fetchViaTab(b);
  if (viaTab) return viaTab;
  // ثمّ مباشرة بكوكيز المتصفّح (البرنامج مغلق لكن الجلسة قائمة)
  const r = await fetch(b + 'pages/mehe_budget.php?export=json', { credentials: 'include', cache: 'no-store' });
  const ct = r.headers.get('content-type') || '';
  if (r.ok && ct.indexOf('json') >= 0) return r.json();
  DIAG.push('مباشر ' + b + ' ⇒ ' + r.status + ' ' + ct.split(';')[0] + ' @ ' + r.url);
  const atLogin = /login\.php/.test(r.url || '');
  const er = new Error(r.status !== 200 ? 'خطأ ' + r.status : (atLogin ? 'أنت غير مسجَّل دخولك بالبرنامج في هذا المتصفّح.' : 'البرنامج لم يُرجع الموازنة — افتح صفحة البرنامج في هذا المتصفّح وسجّل دخولك ثمّ أعد الجلب.'));
  er.login = atLogin; er.url = r.url; throw er;
}
$('#fetch').addEventListener('click', async () => {
  const sel = $('#base').value; $('#info').textContent = 'جارٍ الجلب…';
  let base = sel === 'auto' ? BASES[0] : sel;
  try {
    if (sel === 'auto') {
      // جرّب النسخ بالترتيب؛ أوّل نسخة فيها جلسة دخول تُعتمَد
      let got = null; const errs = [];
      for (const b of BASES) { try { got = await fetchFrom(b); base = b; break; } catch (e) { errs.push(e); } }
      if (!got) {
        // نسخة فيها جلسة لكن ردّها ليس JSON (مثلاً نسخة قديمة بلا تصدير) أولى بالعرض من «غير مسجَّل دخولك»
        const real = errs.find(e => !e.login);
        throw real || Object.assign(new Error('أنت غير مسجَّل دخولك بالبرنامج في هذا المتصفّح (لا أونلاين ولا محلّي).'), { login: true });
      }
      budget = got;
    } else budget = await fetchFrom(base);
    chrome.storage.local.set({ budget });
    showInfo();
  } catch (e) {
    $('#info').innerHTML = '<span class="bad">تعذّر الجلب: ' + (e.message || e) + '</span>'
      + (e.login ? '<br><span class="muted">الجلسة تُقرأ من هذا المتصفّح فقط (لو البرنامج مفتوح بمتصفّح آخر لا تصل). افتح البرنامج هنا وسجّل دخولك ثمّ أعد الجلب.</span><br><a href="#" id="openLogin">فتح صفحة دخول البرنامج في تبويب جديد</a>' : '')
      + (DIAG.length ? '<details><summary class="muted">تفاصيل تقنية</summary><div class="muted" dir="ltr" style="font-size:11px;word-break:break-all">' + DIAG.map(x => String(x).replace(/</g, '&lt;')).join('<br>') + '</div></details>' : '');
    DIAG.length = 0;
    const a = $('#openLogin'); if (a) a.addEventListener('click', (ev) => { ev.preventDefault(); chrome.tabs.create({ url: base + 'login.php' }); });
  }
});

async function sendToPage(action) {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || !/^https:\/\/psb\.mehe\.gov\.lb\//.test(tab.url || '')) { $('#out').style.display = 'block'; $('#out').innerHTML = '<span class="bad">افتح صفحة الموازنة على psb.mehe.gov.lb في هذا التبويب أوّلاً (أرسل الآن ← صفحة الإدخال).</span>'; return; }
  $('#out').style.display = 'block'; $('#out').textContent = action === 'fill' ? 'جارٍ التعبئة…' : 'جارٍ المراجعة…';
  try {
    const res = await chrome.tabs.sendMessage(tab.id, { action, budget });
    render(res);
  } catch (e) {
    // سكربت الصفحة غير محمَّل (فُتحت الصفحة قبل تركيب الإضافة) ⇒ حقنه ثمّ إعادة المحاولة
    try { await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['content.js'] }); const res = await chrome.tabs.sendMessage(tab.id, { action, budget }); render(res); }
    catch (e2) { $('#out').innerHTML = '<span class="bad">تعذّر الوصول إلى الصفحة: ' + (e2.message || e2) + ' — حدّث صفحة الوزارة (F5) وأعد المحاولة.</span>'; }
  }
}
function render(res) {
  if (!res) { $('#out').innerHTML = '<span class="bad">لا جواب من الصفحة</span>'; return; }
  let h = `<b>${res.title || ''}</b><br>خانات عُبِّئت: <b>${res.filled}</b> · موظفون طُوبقوا: <b>${res.matched}</b>`;
  if (res.unmatched && res.unmatched.length) h += `<br><span class="bad">موظفون غير موجودين بصفحة الوزارة (${res.unmatched.length}):</span><ul>` + res.unmatched.map(x => `<li>${x}</li>`).join('') + '</ul><span class="muted">أضفهم على الموقع (إن كان فيه زرّ إضافة) ثمّ أعد التعبئة.</span>';
  if (res.notes && res.notes.length) h += '<ul>' + res.notes.map(x => `<li>${x}</li>`).join('') + '</ul>';
  if (res.filled) h += '<br><span class="ok">راجع كل قسم بـ«التالي»، ثمّ اكبس «أرسل» على الموقع.</span>';
  $('#out').innerHTML = h;
}
$('#fill').addEventListener('click', () => sendToPage('fill'));
$('#check').addEventListener('click', () => sendToPage('check'));
