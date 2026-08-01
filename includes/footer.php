        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->

<script src="<?= BASE_URL ?>assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/export.js?v=<?= @filemtime(__DIR__ . '/../assets/js/export.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/form-lock.js?v=<?= @filemtime(__DIR__ . '/../assets/js/form-lock.js') ?: '1' ?>"></script>
<script>
// وضع المعاينة قبل الطباعة (_autoprint): عند المجيء من زرّ «PDF رسمي» في بيئة بلا أدوات
// خادم (الموقع الأونلاين) **لا يفتح حوار الطابعة لحاله** — بطلب المستخدم (2026-08-01):
// «بدي بس اطبع ما تطلع دغري البرنتر». تظهر الورقة أولاً للمعاينة، ومعها شريط فيه زرّ
// «اطبع الآن» كبير + إرشاد الهوامش (Margins → None مرّة واحدة والمتصفح يتذكّرها).
(function () {
  if (/[?&]_autoprint=1/.test(location.search)) {
    var b = document.createElement('div');
    b.className = 'no-print';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#fff8e1;border-bottom:2px solid #f0c419;color:#5b4a00;padding:10px 16px;font-size:15px;text-align:center;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap';
    b.innerHTML = '<button type="button" onclick="window.print()" style="background:#16a34a;color:#fff;border:0;border-radius:8px;padding:10px 26px;font-size:17px;font-weight:700;cursor:pointer;font-family:inherit">🖨️ Imprimer / اطبع الآن</button>' +
                  '<span><b>Marges / Margins</b> → <b>« Aucune / None »</b> pour une page complète — ' +
                  'بشاشة الطباعة خيار <b>الهوامش (Margins)</b> اختر <b>«بلا / None»</b> فتطلع الورقة كاملة (مرّة واحدة والمتصفّح بيتذكّرها)</span>';
    window.addEventListener('load', function () { document.body.appendChild(b); });
  }
})();
</script>
</body>
</html>
