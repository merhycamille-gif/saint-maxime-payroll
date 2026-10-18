const puppeteer = require('C:/xampp/htdocs/saint-maxime-payroll/tools/node_modules/puppeteer-core');
const fs = require('fs'); const base = 'https://msapayroll.com/';
(async () => {
  const exe = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'].find(p => fs.existsSync(p));
  const browser = await puppeteer.launch({ executablePath: exe, headless: true, args: ['--no-sandbox'] });
  const page = await browser.newPage(); await page.setViewport({ width: 1600, height: 1000 }); page.setDefaultNavigationTimeout(120000);
  page.on('pageerror', e => console.log('JSERR', e.message));
  await page.goto(base + 'login.php', { waitUntil: 'networkidle2' });
  await page.type('input[name=username]', 'admin'); await page.type('input[name=password]', 'Maxime@2026');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type=submit], input[type=submit]')]);
  const js = await page.evaluate(async (u) => (await (await fetch(u, { cache: 'no-store' })).text()), base + 'assets/js/totals.js?v=' + Date.now());
  console.log('totals.js has gov-form exclusion:', js.includes(".cnss-form, .mof-form"));
  for (const u of ['pages/official_forms.php?form=cnss_terminate&employee_id=111', 'pages/official_forms.php?form=cnss_employ&employee_id=111', 'pages/official_forms.php?form=cnss_work&employee_id=111', 'pages/official_forms.php?form=cnss_work_detail&employee_id=111', 'pages/official_forms.php?form=cnss_annual&fy=2025']) {
    await page.goto(base + u, { waitUntil: 'networkidle2' }); await new Promise(r => setTimeout(r, 800));
    const info = await page.evaluate(() => { const a = document.getElementById('ppExportArea'); const q = s => a ? a.querySelectorAll(s).length : -1; const tb = document.querySelector('.export-toolbar'); return { url: location.pathname.replace(/.*\//, '') + location.search.slice(0, 45), colpick: q('.msa-colpick'), totalRow: q('tr.msa-total-row'), curHead: q('.cur-head'), toolbar: tb ? Array.from(tb.querySelectorAll('a,button')).map(b => b.textContent.replace(/\s+/g, ' ').trim().slice(0, 22)).slice(0, 5).join(' | ') : null }; });
    console.log(JSON.stringify(info));
  }
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
