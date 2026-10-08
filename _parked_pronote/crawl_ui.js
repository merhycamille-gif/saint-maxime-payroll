// يفتح كل صفحات القائمة بعد الدخول (محلي) ويبحث عن أخطاء PHP ويلتقط صوراً: node tools/crawl_ui.js [shots]
const puppeteer = require('puppeteer-core');
const BASE = process.env.MSA_URL || 'http://localhost/saint-maxime-payroll/';
const SHOTS = process.argv.includes('shots'); const fs = require('fs'); fs.mkdirSync(__dirname + '/shots', { recursive: true });
(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--no-sandbox'] });
  const p = await b.newPage(); await p.setViewport({ width: 1440, height: 900 });
  await p.goto(BASE + 'login.php', { waitUntil: 'networkidle2' });
  await p.type('input[name=username]', 'admin'); await p.type('input[name=password]', 'Maxime@2026');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('button[type=submit]')]);
  const links = await p.evaluate(() => [...document.querySelectorAll('.pn-menu a[href], .pn-ddm a[href]')].map(a => a.href));
  const uniq = [...new Set(links)]; let err = 0, n = 0, slow = 0;
  console.log('menu:', await p.evaluate(() => [...document.querySelectorAll('.pn-dd > button')].map(b => b.querySelector('.pn-fr').textContent + '(' + b.parentNode.querySelectorAll('a').length + ')').join(' | ')));
  if (SHOTS) await p.screenshot({ path: __dirname + '/shots/_home.png' });
  for (const u of uniq) { n++; const t0 = Date.now(); const r = await p.goto(u, { waitUntil: 'networkidle2' }).catch(() => null); const dt = Date.now() - t0; if (dt > 1500) slow++;
    const t = await p.evaluate(() => document.body.innerText);
    const bad = !r || r.status() >= 500 || /Fatal error|Warning:|Notice:|Parse error|Uncaught|Deprecated:/.test(t);
    const name = u.replace(BASE, '').replace(/[^a-z0-9_]+/gi, '_');
    if (bad) { err++; console.log('!! ' + u + ' ' + (r ? r.status() : 'x') + ' ' + t.slice(0, 160).replace(/\n/g, ' ')); }
    if (SHOTS && /employees|monthly_payroll|reports|settings/.test(u)) await p.screenshot({ path: __dirname + '/shots/' + name + '.png' });
    const ov = await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2); if (ov) console.log('~~ debordement horizontal: ' + u);
  }
  console.log(`pages: ${n}, erreurs: ${err}, lentes: ${slow}`);
  await b.close(); process.exit(err ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
