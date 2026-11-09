/* خلفية المساعد (2026-11-09 «عملّي حلّ سهل بلا هاللفّ والدوران»): كبسة «رحّل من برنامج الرواتب» داخل صفحة موقع الصندوق تطلب من هنا
   بيان المدرسة من البرنامج (سكربت الصفحة لا يستطيع طلب موقع آخر بجلسته). تُجرَّب نسخ البرنامج بالترتيب: أونلاين ثمّ هذا الكمبيوتر ثمّ سيرفر المدرسة،
   وأوّل نسخة مسجَّل الدخول فيها بهذا المتصفّح تُعتمَد. قراءة فقط من البرنامج — لا يكتب فيه شيئاً. */
const BASES = ['https://msapayroll.com/', 'http://localhost/saint-maxime-payroll/', 'https://192.168.1.60/saint-maxime-payroll/'];

function inTabFetch(base, path) {
  return fetch(base + path, { credentials: 'same-origin', cache: 'no-store' })
    .then((r) => (r.headers.get('content-type') || '').indexOf('json') >= 0 ? r.json().then((j) => ({ ok: true, json: j })) : { ok: false, url: r.url, status: r.status }, (e) => ({ ok: false, err: String(e) }));
}
const withTimeout = (p, ms) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), ms))]);

/** عبر تبويب البرنامج المفتوح (نفس الأصل ⇒ الجلسة تُرسَل دائماً) */
async function viaTab(base, path) {
  let tabs = [];
  try { tabs = await chrome.tabs.query({ url: base + '*' }); } catch (e) { return null; }
  for (const t of tabs) {
    if (t.discarded) continue;
    try {
      const [res] = await withTimeout(chrome.scripting.executeScript({ target: { tabId: t.id }, func: inTabFetch, args: [base, path] }), 25000);
      const rr = res && res.result;
      if (rr && rr.ok) return { json: rr.json };
      if (rr && /login\.php/.test(rr.url || '')) return { login: true };
    } catch (e) { /* تبويب نائم/غير متاح ⇒ جرّب التالي أو المباشر */ }
  }
  return null;
}
async function direct(base, path) {
  const ac = new AbortController(); const tm = setTimeout(() => ac.abort(), 20000);
  try {
    const r = await fetch(base + path, { credentials: 'include', cache: 'no-store', signal: ac.signal });
    const ct = r.headers.get('content-type') || '';
    if (r.ok && ct.indexOf('json') >= 0) return { json: await r.json() };
    return { login: /login\.php/.test(r.url || ''), status: r.status };
  } catch (e) { return { err: String(e && e.message || e) }; } finally { clearTimeout(tm); }
}
async function programJson(path) {
  let sawLogin = false; const errs = [];
  for (const base of BASES) {
    const a = await viaTab(base, path);
    if (a && a.json) return { ok: true, json: a.json, base };
    if (a && a.login) sawLogin = true;
    const d = await direct(base, path);
    if (d.json) return { ok: true, json: d.json, base };
    if (d.login) sawLogin = true; else if (d.err) errs.push(base + ' — ' + d.err); else if (d.status) errs.push(base + ' — ' + d.status);
  }
  return { ok: false, login: sawLogin, msg: sawLogin ? 'أنت غير مسجَّل دخولك ببرنامج الرواتب في هذا المتصفّح.' : 'تعذّر الوصول إلى برنامج الرواتب (' + errs.join(' · ') + ')', loginUrl: BASES[0] + 'login.php' };
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg || msg.target !== 'bg') return;
  if (msg.action === 'program') {
    // مسار ثابت الشكل فقط: صفحة بوّابة الصندوق بالبرنامج بصيغة JSON
    if (!/^pages\/eoc_portal\.php\?[\w=&%.\-]+$/.test(String(msg.path || '')) || String(msg.path).indexOf('export=json') < 0) { sendResponse({ ok: false, msg: 'طلب غير صالح' }); return; }
    programJson(msg.path).then(sendResponse, (e) => sendResponse({ ok: false, msg: String(e && e.message || e) }));
    return true;
  }
  if (msg.action === 'openLogin') { chrome.tabs.create({ url: BASES[0] + 'login.php' }); sendResponse({ ok: true }); return; }
});
