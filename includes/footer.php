        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->

<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<script src="<?= BASE_URL ?>assets/js/export.js"></script>
<script>
// طباعة تلقائية عند المجيء من زرّ «PDF رسمي» في بيئة بلا أدوات خادم (الموقع الأونلاين):
// يفتح حوار طباعة المتصفّح → المستخدم يختار «حفظ كـ PDF / Save as PDF» فيطلع نفس الشكل الرسمي.
(function () {
  if (/[?&]_autoprint=1/.test(location.search)) {
    window.addEventListener('load', function () {
      setTimeout(function () { try { window.print(); } catch (e) {} }, 700);
    });
  }
})();
</script>
</body>
</html>
