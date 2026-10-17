// يحفظ مفتاح خدمة الإرسال بإعدادات البريد أونلاين (كما يفعل المدير من الصفحة) ثم يبعت تجربة من إيميل البرنامج
const puppeteer = require('C:/xampp/htdocs/saint-maxime-payroll/tools/node_modules/puppeteer-core');
const fs = require('fs');
const key = fs.readFileSync(process.argv[2], 'utf8').trim(); const testTo = process.argv[3] || 'cm5h@hotmail.com';
(async () => {
  const exe = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'].find(p => fs.existsSync(p));
  const browser = await puppeteer.launch({ executablePath: exe, headless: true, args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.goto('https://msapayroll.com/login.php', { waitUntil: 'networkidle2' });
  await page.type('input[name=username]', 'admin'); await page.type('input[name=password]', 'Maxime@2026');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto('https://msapayroll.com/pages/email_settings.php', { waitUntil: 'networkidle2' });
  const has = await page.$('input[name=program_relay_key]'); console.log('relay field present:', !!has);
  if (!has) { await browser.close(); process.exit(2); }
  // 🔒 الفورم مقفول (form-lock.js) حتى كبسة «تعديل / Modifier» داخله
  await page.evaluate(() => { const f = document.querySelector('input[name=program_relay_key]').form; const b = f.querySelector('.lockedit-bar button, button.btn-warning'); if (b) b.click(); });
  await new Promise(r => setTimeout(r, 400));
  await page.type('input[name=program_relay_key]', key);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[name=save_program]')]);
  const flash1 = await page.evaluate(() => (document.querySelector('.alert-success, .alert-danger, .flash') || {}).innerText || '');
  console.log('save:', flash1.trim().slice(0, 160));
  const badge = await page.evaluate(() => { const l = Array.from(document.querySelectorAll('label')).find(x => /Resend/.test(x.textContent)); return l ? l.textContent.trim() : ''; });
  console.log('badge:', badge);
  await page.evaluate(() => { const i = document.querySelector('input[name=test_to_program]'); i.value = ''; });
  await page.type('input[name=test_to_program]', testTo);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }), page.click('button[name=test_program]')]);
  const flash2 = await page.evaluate(() => (document.querySelector('.alert-success, .alert-danger, .flash') || {}).innerText || '');
  console.log('test:', flash2.trim().slice(0, 220));
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
