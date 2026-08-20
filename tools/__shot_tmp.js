const puppeteer = require('puppeteer-core');
(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--no-sandbox'] });
  const p = await b.newPage();
  await p.setViewport({ width: 1000, height: 1200, deviceScaleFactor: 1.5 });
  await p.goto('http://localhost/saint-maxime-payroll/login.php', { waitUntil: 'networkidle2' });
  await p.type('input[name=username]', 'admin');
  await p.type('input[name=password]', 'Maxime@2026');
  await Promise.all([p.keyboard.press('Enter'), p.waitForNavigation({ waitUntil: 'networkidle2' })]);
  await p.goto('http://localhost/saint-maxime-payroll/pages/attestations.php?employee_id=43&type=salaire&lang_doc=ar', { waitUntil: 'networkidle2' });
  const el = await p.$('#ppExportArea');
  await el.screenshot({ path: 'C:/Users/user/AppData/Local/Temp/claude/C--Users-user/d4b42029-c1b2-4ffe-a076-27d33c82951b/scratchpad/niyah_center.png' });
  await b.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
