// عامل الخدمة (PWA): وجوده يجعل البرنامج قابلاً للتركيب كتطبيق على الهاتف.
// لا يخزّن أي صفحة فيها بيانات — كل الصفحات من السيرفر مباشرة (السرعة والصحّة كما هي).
// يخزّن ملفات الشكل الثابتة فقط (خطوط/أيقونات/CSS) ويعرض «لا اتصال» عند انقطاع الإنترنت.
const CACHE = 'msa-payroll-v1';
const BASE = new URL('./', self.location.href).pathname;
const OFFLINE = BASE + 'hors-ligne.html';
self.addEventListener('install', (e) => { e.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE])).then(() => self.skipWaiting())); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', (e) => {
  const req = e.request; if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (/\/assets\/(fonts|vendor|img|logos)\//.test(url.pathname)) {
    e.respondWith(fetch(req).then((r) => { if (r.ok) { const c = r.clone(); caches.open(CACHE).then((x) => x.put(req, c)); } return r; }).catch(() => caches.match(req, { ignoreSearch: true })));
    return;
  }
  if (req.mode === 'navigate') e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
});
