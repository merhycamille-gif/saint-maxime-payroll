// طباعة أي صفحة من البرنامج إلى PDF رسمي طبق الأصل (كما تظهر بالطباعة) عبر Chrome.
// الاستعمال: node page_to_pdf.js <out.pdf> <url> <cookieName> <cookieValue> [fit]
//   fit = "fit" → يقيس حجم التقرير الطبيعي ويصغّره/يكبّره تلقائياً ليملأ صفحة A4 أفقية واحدة
//                 بلا قصّ ولا فراغ (للكشوف المفردة). بدونه: طباعة عادية (للتقارير متعددة الصفحات).
const puppeteer = require('puppeteer-core');

function chromePath() {
  const cands = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ];
  const fs = require('fs');
  for (const c of cands) { try { if (fs.statSync(c).isFile()) return c; } catch (e) {} }
  return null;
}

(async () => {
  let [out, url, cookieName, cookieValue, fit] = process.argv.slice(2);
  if (!out || !url) { console.error('args: out url [cookieName cookieValue] [fit]'); process.exit(2); }
  // الرابط قد يصل base64 (بادئة b64:) — لأن ويندوز يشوّه علامة % في وسائط الأوامر
  if (url.startsWith('b64:')) url = Buffer.from(url.slice(4), 'base64').toString('utf8');
  const exe = chromePath();
  if (!exe) { console.error('no chrome'); process.exit(3); }
  const browser = await puppeteer.launch({ executablePath: exe, headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
  try {
    const page = await browser.newPage();
    // نافذة عريضة حتى يتمدّد الجدول لعرضه الطبيعي بلا لفّ عند القياس
    await page.setViewport({ width: 1600, height: 1200, deviceScaleFactor: 1 });
    if (cookieName && cookieValue) {
      await page.setCookie({ name: cookieName, value: cookieValue, domain: 'localhost', path: '/' });
    }
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.emulateMediaType('print');
    await new Promise(r => setTimeout(r, 400));

    const pdfOpts = { path: out, printBackground: true, margin: { top: '5mm', bottom: '5mm', left: '5mm', right: '5mm' } };

    if (fit === 'fit') {
      // قياس حجم أكبر كشف ثم حساب معامل تصغير/تكبير ليملأ كل كشف صفحة A4 أفقية واحدة.
      // عند تعدّد الكشوف (طباعة الكل) كلها بنفس العرض الثابت فمعامل واحد يناسب الجميع،
      // وكل كشف على صفحة مستقلّة (page-break-after في CSS).
      const m = await page.evaluate(() => {
        const els = Array.from(document.querySelectorAll('.salary-slip'));
        if (!els.length) return { w: document.body.scrollWidth, h: document.body.scrollHeight, n: 0 };
        let w = 0, h = 0;
        for (const el of els) {
          const r = el.getBoundingClientRect();
          const t = el.querySelector('.salary-slip-table');
          // أوسع قيمة فعلية (تشمل أي تجاوز للجدول) لتفادي القصّ
          const ew = Math.max(r.width, el.scrollWidth, t ? t.scrollWidth : 0);
          w = Math.max(w, Math.ceil(ew));
          h = Math.max(h, Math.ceil(Math.max(r.height, el.scrollHeight)));
        }
        return { w, h, n: els.length };
      });
      // A4 أفقي. المساحة القابلة للطباعة (96dpi) ناقص هوامش 5مم: ≈ 1093×756 px
      const PW = 1093, PH = 756;
      let scale = Math.min(PW / m.w, PH / m.h);
      if (!isFinite(scale) || scale <= 0) scale = 1;
      scale = Math.max(0.3, Math.min(1.4, scale)); // حدود أمان (page.pdf يقبل 0.1–2)
      pdfOpts.width = '297mm';   // A4 landscape
      pdfOpts.height = '210mm';
      pdfOpts.scale = scale;
      if (m.n <= 1) pdfOpts.pageRanges = '1'; // كشف مفرد = صفحة واحدة فقط
    } else {
      pdfOpts.preferCSSPageSize = true; // احترام @page لكل صفحة (تقارير متعددة الصفحات)
    }

    await page.pdf(pdfOpts);
    console.log('OK');
  } finally {
    await browser.close();
  }
})().catch(e => { console.error('ERR ' + e.message); process.exit(1); });
