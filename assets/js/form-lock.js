/*
 * form-lock.js — قفل فورمات التعديل عبر كل البرنامج (نفس سلوك ملف الأستاذ).
 * الفورم يُفتح مقفَّلاً للقراءة → زر «تعديل» يفتح الحقول → «حفظ» يحفظ ويعيد القفل.
 *
 * التفعيل: ضع class="lockedit" (أو data-lockedit) على الفورم الذي يعرض قيماً محفوظة.
 * - يُحقَن تلقائياً شريط فيه زر «تعديل / Modifier» أعلى الفورم، وتُخفى أزرار الحفظ حتى الضغط عليه.
 * - زر تعديل خارجي اختياري (خارج الفورم): عنصر عليه data-lockedit-for="FORM_ID".
 *   (يستعمله ملف الأستاذ حيث الزر في الشريط العلوي.)
 */
(function () {
  'use strict';

  // الحقول القابلة للكتابة (نتجاهل المخفية وأزرار الإرسال/الأزرار العادية)
  // 🔒 تشمل أيضاً الحقول المربوطة بالفورم عبر السمة form="ID" وهي خارجه
  // (متل سطور جدول الصفوف) — حتى يقفلها زرّ «تعديل» نفسه (قاعدة المستخدم:
  // «كل البرنامج مسكّر ويفتح بس على التعديل البدي ياه»)
  function editableFields(form) {
    var sel = 'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]), select, textarea';
    var inside = Array.prototype.slice.call(form.querySelectorAll(sel));
    // ⚠️ getAttribute لا form.id: حقل مخفي اسمه «id» داخل الفورم يغطّي على الخاصية
    var fid = form.getAttribute('id');
    if (fid) {
      var linked = document.querySelectorAll('[form="' + fid + '"]');
      Array.prototype.forEach.call(linked, function (el) {
        if (el.matches && el.matches(sel) && inside.indexOf(el) === -1) inside.push(el);
      });
    }
    return inside;
  }

  // أزرار الإرسال التابعة لهذا الفورم تحديداً (لا فورم متداخل)
  function ownSubmits(form) {
    var all = form.querySelectorAll('button[type=submit], input[type=submit], button:not([type])');
    return Array.prototype.filter.call(all, function (b) {
      var f = b.form || b.closest('form');
      return f === form;
    });
  }

  function injectStyle() {
    if (document.getElementById('lockedit-style')) return;
    var css =
      '.lockedit-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;' +
      'background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;margin-bottom:14px;' +
      'position:sticky;top:var(--msa-topbar-h,0px);z-index:20}' +
      '.lockedit-bar .lockedit-btns{display:inline-flex;gap:8px;align-items:center}' +
      '@media print{.lockedit-bar{display:none!important}}' +
      '.lockedit-bar.is-editing{background:#fef9c3;border-color:#fde68a}' +
      '.lockedit-msg{font-size:13px;font-weight:600;color:#475569}' +
      '.lockedit-bar.is-editing .lockedit-msg{color:#92400e}';
    var st = document.createElement('style');
    st.id = 'lockedit-style';
    st.textContent = css;
    document.head.appendChild(st);
  }

  function setup(form) {
    if (form.dataset.lockInit) return;
    form.dataset.lockInit = '1';

    var fields = editableFields(form);
    if (!fields.length) return; // لا حقول قابلة للتعديل → لا داعي للقفل

    var subs = ownSubmits(form);

    // أزرار تعديل خارجية معرَّفة مسبقاً؟ (ملف الأستاذ: زر بالشريط العلوي + زر بكل تبويب)
    var lockFid = form.getAttribute('id'); // getAttribute لا .id (قد يغطّيه حقل اسمه id)
    var extBtns = lockFid
        ? Array.prototype.slice.call(document.querySelectorAll('[data-lockedit-for="' + lockFid + '"]'))
        : [];
    var extBtn = extBtns.length ? extBtns[0] : null;
    var bar = null, editBtn = extBtn, msgEl = null, proxySave = null, movedExtras = [];
    // 📌💚 (2026-11-13 «وبصفحة الموظفين كمان… رتّب هالقاعدة بكل البرنامج») القاعدة واحدة: «حفظ» الأخضر يظهر بمحلّ «تعديل» نفسه.
    // زرّ «تعديل» الخارجي صار بالشريط اللاصق (ملف الأستاذ) ⇒ زرّ الحفظ الأصلي نفسه (و«إلغاء» بجانبه) ينتقلان إلى جانبه
    // ويبقى مربوطاً بالفورم بسمة form=؛ الشريط السفلي الفارغ يُخفى فلا تتكرّر الكبسة.
    try {
      var mains0 = subs.filter(function (b) { return (b.getAttribute('type') || '').toLowerCase() === 'submit' && !b.classList.contains('btn-danger'); });
      if (extBtn && lockFid && extBtn.closest('.msa-acts') && (mains0.length === 1 || form.querySelector('.emp-savebar button[type=submit]'))) {
        var sv = mains0.length === 1 ? mains0[0] : form.querySelector('.emp-savebar button[type=submit]'), holder = sv.closest('.emp-savebar') || sv.parentElement, cancel = holder ? holder.querySelector('a.btn') : null;
        sv.setAttribute('form', lockFid); sv.classList.remove('btn-lg'); sv.classList.add('msa-save');
        extBtn.parentNode.insertBefore(sv, extBtn.nextSibling);
        if (cancel) { extBtn.parentNode.insertBefore(cancel, sv.nextSibling); movedExtras.push(cancel); }
        if (holder && !holder.querySelector('input, select, textarea, button, a')) holder.style.setProperty('display', 'none', 'important');
      }
    } catch (e) {}
    // وضع مضغوط (سطور الجداول): زر «تعديل» صغير فقط بلا شريط رسالة
    var compact = form.classList.contains('lockedit-compact');

    if (!editBtn) {
      editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-warning btn-sm';
      editBtn.innerHTML = compact ? '<i class="fas fa-pen"></i> تعديل' : '<i class="fas fa-pen"></i> تعديل / Modifier';
      if (compact) {
        form.insertBefore(editBtn, form.firstChild);
      } else {
        injectStyle();
        bar = document.createElement('div');
        bar.className = 'lockedit-bar';
        msgEl = document.createElement('span');
        msgEl.className = 'lockedit-msg';
        bar.appendChild(msgEl);
        // 📌💚 (2026-11-13 «تعديل تضلّ ظاهرة… وحفظ بعد ما عدّل لونها أخضر») الشريط يلتصق بأعلى الصفحة طول ما الفورم ظاهر،
        // وبوضع التعديل تظهر فيه «حفظ» خضراء بمحلّ «تعديل» — تكبس زرّ الحفظ الأصلي نفسه (نفس الاسم/القيمة/الحرّاس)،
        // والأصلي يُخفى فلا تتكرّر الكبسة. فورم فيه أكثر من زرّ إرسال يبقى بأزراره كما هي.
        var btns = document.createElement('span'); btns.className = 'lockedit-btns';
        btns.appendChild(editBtn);
        var mains = subs.filter(function (b) { return !b.classList.contains('btn-danger'); });
        if (subs.length === 1 && mains.length === 1) {
          proxySave = document.createElement('button');
          proxySave.type = 'button';
          proxySave.className = 'btn btn-success btn-sm msa-save lockedit-save';
          proxySave.innerHTML = '<i class="fas fa-save"></i> حفظ / Enregistrer';
          proxySave.style.display = 'none';
          proxySave.addEventListener('click', function (e) { e.preventDefault(); mains[0].click(); });
          btns.appendChild(proxySave);
        }
        bar.appendChild(btns);
        form.insertBefore(bar, form.firstChild);
      }
    }

    function setLocked(locked) {
      // 🛡️ حالة القفل معلنة على الفورم — يقرأها الحفظ الفوري فلا يظهر على فورم مقفول
      // (حادثة أندره مراد: حفظ وقع والفورم مقفول فوصل فارغاً ومسح البيانات)
      form.dataset.lockState = locked ? 'locked' : 'open';
      fields.forEach(function (el) {
        if (locked) {
          if (!el.disabled) { el.disabled = true; el.setAttribute('data-lockwas', '1'); }
        } else if (el.getAttribute('data-lockwas')) {
          el.disabled = false;
          el.removeAttribute('data-lockwas');
        }
      });
      // أزرار الإجراءات (إضافة سطر...) تُقفل أيضاً — كانت تبقى فعّالة بالوضع المقفول
      // فتضيف حقولاً مفتوحة بفورم مقفول (نفس ثغرة الحادثة). التبويبات مستثناة.
      Array.prototype.forEach.call(form.querySelectorAll('button[type=button]'), function (b) {
        if (b === editBtn || b === proxySave || b.classList.contains('tab') || b.hasAttribute('data-lockedit-for')) return;
        b.disabled = locked;
      });
      subs.forEach(function (b) { b.style.display = (locked || proxySave) ? 'none' : ''; });
      if (proxySave) proxySave.style.display = locked ? 'none' : '';
      movedExtras.forEach(function (b) { b.style.display = locked ? 'none' : ''; });
      if (extBtns.length) {
        extBtns.forEach(function (b) { b.style.display = locked ? '' : 'none'; });
      } else {
        editBtn.style.display = locked ? '' : 'none';
      }
      if (bar) {
        bar.classList.toggle('is-editing', !locked);
        msgEl.innerHTML = locked
          ? '<i class="fas fa-lock"></i> مقفَّل للحماية من التعديل غير المقصود — اضغط «تعديل» للكتابة / Verrouillé'
          : '<i class="fas fa-lock-open"></i> وضع التعديل مفعَّل — عدّل ثم اضغط «حفظ» / Mode édition';
      }
    }

    function onEdit(e) {
      e.preventDefault();
      setLocked(false);
    }
    if (extBtns.length) extBtns.forEach(function (b) { b.addEventListener('click', onEdit); });
    else editBtn.addEventListener('click', onEdit);

    setLocked(true); // يُفتح مقفَّلاً
  }

  function init() {
    document.querySelectorAll('form.lockedit, form[data-lockedit]').forEach(setup);
    // 💚 (2026-11-13) كل زرّ «حفظ» (إرسال فورم) أخضر بكل البرنامج — يُعلَّم بصنف msa-save (اللون بـapp.css)
    try {
      document.querySelectorAll('.page-content button[type=submit], .page-content input[type=submit], .page-content button:not([type])').forEach(function (b) {
        if (b.classList.contains('btn-danger')) return;
        if (/حفظ|احفظ|Enregistrer|Sauvegarder/.test(b.textContent || b.value || '')) b.classList.add('msa-save');
      });
    } catch (e) {}
    // رؤوس الجداول داخل الفورمات المقفولة تُعاد مواضعها تحت الشريط اللاصق
    try { if (window.msaStkRunIfChanged) window.msaStkRunIfChanged(); } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/*
 * 💾 الحفظ الفوري بجانب الحقل — قاعدة المستخدم (2026-08-01):
 * «بدي بس حط أي رقم يكون دغري بجانبو حفظ» — أي تغيير بأي حقل (رقم/نص/اختيار)
 * بأي فورم حفظ (POST) يُظهر زرّ «حفظ» أخضر نابضاً بجانب الحقل نفسه فوراً،
 * وكبسته تحفظ الفورم كاملاً (نفس معالج الحفظ الموجود — لا يضيع أي تغيير آخر).
 * الزرّ واحد يتبع آخر حقل معدَّل (متل الحفظ الفوري بلوحة الدرجات).
 */
(function () {
  'use strict';
  var btn = null;

  function ensureBtn() {
    if (btn) return btn;
    var st = document.createElement('style');
    st.textContent =
      '.quicksave-btn{margin:4px 6px 0;vertical-align:middle;white-space:nowrap;' +
      'animation:qsPulse 1.2s ease-in-out infinite}' +
      '@keyframes qsPulse{0%,100%{box-shadow:0 0 0 0 rgba(22,163,74,.55)}50%{box-shadow:0 0 0 8px rgba(22,163,74,0)}}';
    document.head.appendChild(st);
    btn = document.createElement('button');
    btn.type = 'submit';
    btn.className = 'btn btn-success btn-sm quicksave-btn';
    btn.innerHTML = '<i class="fas fa-save"></i> حفظ / Enregistrer';
    return btn;
  }

  function onFieldChange(e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea')) return;
    var form = el.form || (el.closest && el.closest('form'));
    if (!form || (form.method || '').toLowerCase() !== 'post') return; // فورمات الحفظ فقط (لا فلاتر البحث GET)
    if (el.closest('.no-quicksave')) return;                            // استثناء صريح عند الحاجة
    // 🛡️ فورم مقفول = ممنوع أي حفظ (الحقول المعطَّلة لا تُرسَل فيصل الحفظ فارغاً ويمسح)
    if (form.dataset.lockState === 'locked') return;
    var b = ensureBtn();
    // اربط الزرّ بفورم الحقل نفسه (يلزم للحقول المربوطة بسمة form= خارج الفورم متل سطور الجداول)
    var fid = form.getAttribute('id');
    if (fid) b.setAttribute('form', fid); else b.removeAttribute('form');
    // بجانب الحقل نفسه مباشرة (يتبع آخر حقل معدَّل)
    if (el.nextElementSibling !== b) el.insertAdjacentElement('afterend', b);
    b.style.display = '';
  }

  document.addEventListener('input', onFieldChange, true);
  document.addEventListener('change', onFieldChange, true);
})();
