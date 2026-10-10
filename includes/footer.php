<?php if (!empty($msaTopBars) && ob_get_level() > 0) ob_end_flush(); // 📐 يطبع الصفحة بالترتيب (msaOrderTopBars) ?>
        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->

<script src="<?= BASE_URL ?>assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/export.js?v=<?= @filemtime(__DIR__ . '/../assets/js/export.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/form-lock.js?v=<?= @filemtime(__DIR__ . '/../assets/js/form-lock.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/select-search.js?v=<?= @filemtime(__DIR__ . '/../assets/js/select-search.js') ?: '1' ?>"></script>
<script>window.BASE_URL = <?= json_encode(BASE_URL) ?>; window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>; window.MSA_PAGE = <?= json_encode((string)($currentPage ?? '')) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/doclang.js?v=<?= @filemtime(__DIR__ . '/../assets/js/doclang.js') ?: '1' ?>"></script>
<?php if (!empty($GLOBALS['msaLegalCtx'])): $lc = $GLOBALS['msaLegalCtx']; $lf = $lc['filing'] ? array_intersect_key($lc['filing'], array_flip(['id','version','sent_at','sent_by','snapshot_hash','seen_hash','changed_flag','changed_note'])) : null; ?>
<script>window.MSA_LEGAL = <?= json_encode(['key' => $lc['key'], 'period' => $lc['period'], 'scope' => $lc['scope'], 'base' => BASE_URL, 'me' => (string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? '')), 'filing' => $lf], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/legal.js?v=<?= @filemtime(__DIR__ . '/../assets/js/legal.js') ?: '1' ?>"></script>
<?php endif; ?>
<?php /* 🚀 (2026-10-02 «بدي يفتح بجزء من الثانية — كل شي بالبرنامج»): التحضير المسبق — حين يقف الماوس على رابط تنقّل (القائمة الجانبية،
         بلاطات لوحة القيادة ومركز التقارير) يحضّر المتصفّح الصفحة بالخلفية، وعند الكبس تُفتح فوراً (Chrome/Edge؛ غيرهما يتجاهله).
         روابط عرض فقط — لا روابط إجراء/حذف/تصدير؛ والصفحات الثقيلة عمداً (تقرير المخالفات/فحص الصحّة/النسخ الاحتياطي) مستثناة. */ ?>
<?php if (function_exists('monthStaleScanDue') && monthStaleScanDue()): ?>
<script>
// 🔎🚀 الفحص الشامل الدوري بنبض خلفي: بعد ظهور الصفحة بثانيتين، ثم كل 5 ثوانٍ ما دامت الجولة لم تكتمل والصفحة مفتوحة ومرئية
(function () {
  function tick() {
    if (document.hidden || document.prerendering) { setTimeout(tick, 5000); return; }
    fetch(window.BASE_URL + 'ajax_tick.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) { if (s && s.due) setTimeout(tick, 5000); }).catch(function () {});
  }
  window.addEventListener('load', function () { setTimeout(tick, 2000); });
})();
</script>
<?php endif; ?>
<script type="speculationrules">
{"prerender":[{"where":{"selector_matches":".sidebar-nav a:not([href*='logout']):not([href*='backup']):not([href*='compliance']):not([href*='health_check']):not([target='_blank']), .dash-link:not([href*='backup']):not([target='_blank']), .report-card:not([target='_blank'])"},"eagerness":"moderate"}]}
</script>
<?php if (function_exists('openYearHealPending20260912') && openYearHealPending20260912()): ?>
<script>
// 📅 تجهيز 2026-2027 التلقائي (2026-09-12): نبض خلفي كل 4 ثوانٍ يشغّل دفعة من الشفاء حتى يكتمل — شريط صغير يُظهر التقدّم ثم يختفي
(function () {
  var bar = document.createElement('div'); bar.className = 'no-print';
  bar.style.cssText = 'position:fixed;bottom:12px;left:50%;transform:translateX(-50%);z-index:99998;background:#1F4E5F;color:#fff;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.25);direction:rtl';
  bar.textContent = '⏳ البرنامج عم يجهّز سنة 2026-2027 تلقائياً…';
  window.addEventListener('load', function () { document.body.appendChild(bar); });
  var busy = false, stop = false;
  function tick() {
    if (busy || stop) return; busy = true;
    fetch(window.BASE_URL + 'pages/heal_tick.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (s) {
      busy = false;
      if (!s || s.done) { stop = true; bar.textContent = '✅ خلص تجهيز 2026-2027 — الدرجات والنقل و85٪ عبرا'; setTimeout(function () { bar.remove(); }, 6000); return; }
      var st = { grades: 'تصحيح درجات الملاك (' + s.grades + ' من ' + s.seen + ')', transport: 'إطفاء النقل بالنسبة (' + s.transport + ')', abra85: 'عبرا 85٪ (' + s.abra + ')', finish: 'إنهاء',
                 lawshift: 'نصف تقديم التدرّج بأوّل تشرين (' + s.lawshift + ' من ' + s.seen + ')', grades2: 'مراجعة درجات 2026-2027 (' + s.grades + ')', transport_restore: 'إرجاع النقل المطفأ (' + s.transport + ')' }[s.stage] || s.stage;
      bar.textContent = '⏳ البرنامج عم يجهّز 2026-2027 تلقائياً: ' + st;
    }).catch(function () { busy = false; });
  }
  setInterval(tick, 4000); setTimeout(tick, 800);
})();
</script>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/pdf-save.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pdf-save.js') ?: '1' ?>"></script>
<script>
// وضع المعاينة قبل الطباعة (_autoprint): عند المجيء من زرّ «PDF رسمي» في بيئة بلا أدوات
// خادم (الموقع الأونلاين) **لا يفتح حوار الطابعة لحاله** — بطلب المستخدم (2026-08-01):
// «بدي بس اطبع ما تطلع دغري البرنتر». تظهر الورقة أولاً للمعاينة، ومعها شريط فيه زرّ
// «اطبع الآن» كبير + إرشاد الهوامش (Margins → None مرّة واحدة والمتصفح يتذكّرها).
(function () {
  if (/[?&]_autoprint=1/.test(location.search)) {
    // 🛡️ صمام الورقة الفاضية («p1 شوف» 2026-08-22): إذا الصفحة كلها شاشة اختيار/قوائم
    // (كل محتواها no-print — متل شاشة «اختر الموظف» بالنماذج الفردية) ما في شي يُطبع —
    // فلا نعرض زرّي الطباعة/الحفظ لئلا تطلع ورقة بيضاء، بل رسالة توجّهه لاختيار الموظف.
    var pc = document.querySelector('.page-content') || document.body;
    var hasPrintable = Array.prototype.some.call(pc.children, function (el) {
      if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE') return false;
      return !(el.classList && el.classList.contains('no-print'));
    });
    if (!hasPrintable) {
      var w = document.createElement('div');
      w.className = 'no-print';
      w.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#fef2f2;border-bottom:2px solid #dc2626;color:#7f1d1d;padding:12px 16px;font-size:15px;text-align:center;font-weight:700';
      w.textContent = 'ما في مستند معروض للطباعة — اختر الموظف/النموذج أولاً ثم اكبس زرّ الطباعة. / Choisissez d\'abord l\'employé.';
      window.addEventListener('load', function () { document.body.appendChild(w); });
      return;
    }
    // متل الوورد (طلب المستخدم): الورقة معروضة، وخياران واضحان — «اطبع عالورق» (حوار
    // المتصفح) أو «احفظها عالكمبيوتر» = تنزيل ملف PDF حقيقي فوراً بلا أي شاشة
    // (msaSavePdfStart في pdf-save.js — «ما بيّن عندي Save as PDF» 2026-08-01).
    var b = document.createElement('div');
    b.className = 'no-print';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#fff8e1;border-bottom:2px solid #f0c419;color:#5b4a00;padding:10px 16px;font-size:15px;text-align:center;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap';
    b.innerHTML = '<button type="button" onclick="window.print()" style="background:#16a34a;color:#fff;border:0;border-radius:8px;padding:10px 22px;font-size:17px;font-weight:700;cursor:pointer;font-family:inherit;border-bottom:3px solid rgba(0,0,0,.32);background-image:linear-gradient(180deg,rgba(255,255,255,.24),rgba(0,0,0,.10));box-shadow:inset 0 1px 0 rgba(255,255,255,.4),0 3px 6px rgba(15,23,42,.28)">🖨️ Imprimer / اطبع عالورق</button>' +
                  '<button type="button" onclick="msaSavePdfStart(this)" style="background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 22px;font-size:17px;font-weight:700;cursor:pointer;font-family:inherit;border-bottom:3px solid rgba(0,0,0,.32);background-image:linear-gradient(180deg,rgba(255,255,255,.24),rgba(0,0,0,.10));box-shadow:inset 0 1px 0 rgba(255,255,255,.4),0 3px 6px rgba(15,23,42,.28)">💾 احفظها عالكمبيوتر PDF</button>' +
                  '<span style="font-size:13.5px">للورق: خيار الهوامش <b>Margins</b> خلّيه <b>«None / بلا»</b> لتطلع الورقة كاملة</span>';
    window.addEventListener('load', function () { document.body.appendChild(b); });
  }
})();
</script>
</body>
</html>
