        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->

<script src="<?= BASE_URL ?>assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/export.js?v=<?= @filemtime(__DIR__ . '/../assets/js/export.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>assets/js/form-lock.js?v=<?= @filemtime(__DIR__ . '/../assets/js/form-lock.js') ?: '1' ?>"></script>
<script>
// طباعة تلقائية عند المجيء من زرّ «PDF رسمي» في بيئة بلا أدوات خادم (الموقع الأونلاين):
// يفتح حوار طباعة المتصفّح → المستخدم يختار «حفظ كـ PDF / Save as PDF» فيطلع نفس الشكل الرسمي.
(function () {
  if (/[?&]_autoprint=1/.test(location.search)) {
    // 🖨️ إرشاد الهوامش (2026-08-01): المطبوعات مضبوطة على هوامش الورقة نفسها (@page).
    // إذا كان حوار الطباعة حافظاً هوامش مستخدم كبيرة (مثل 1 إنش) تضيق الورقة فتطلع
    // المطبوعة ناقصة/مقصوصة — بانر ظاهر على الشاشة فقط (no-print) يرشده يختار «None/بلا»
    // مرّة واحدة والمتصفح يتذكّرها. فرنسي أولاً ثم عربي (قاعدة الواجهة الثنائية).
    var b = document.createElement('div');
    b.className = 'no-print';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#fff8e1;border-bottom:2px solid #f0c419;color:#5b4a00;padding:10px 16px;font-size:15px;text-align:center;font-family:inherit';
    b.innerHTML = '🖨️ <b>Impression :</b> dans la fenêtre d\'impression → <b>Marges / Margins</b> → choisissez <b>« Aucune / None »</b> pour une page complète. — ' +
                  '<b>للطباعة الصحيحة:</b> بشاشة الطباعة عند خيار <b>الهوامش (Margins)</b> اختر <b>«بلا / None»</b> فتطلع الورقة كاملة (مرّة واحدة والمتصفّح بيتذكّرها).';
    window.addEventListener('load', function () {
      document.body.appendChild(b);
      setTimeout(function () { try { window.print(); } catch (e) {} }, 700);
    });
  }
})();
</script>
</body>
</html>
