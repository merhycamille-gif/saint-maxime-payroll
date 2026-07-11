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
  function editableFields(form) {
    return form.querySelectorAll(
      'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]), select, textarea'
    );
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
      'background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;margin-bottom:14px}' +
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

    // زر تعديل خارجي معرَّف مسبقاً؟ (ملف الأستاذ) وإلا نحقن شريطاً
    var extBtn = form.id ? document.querySelector('[data-lockedit-for="' + form.id + '"]') : null;
    var bar = null, editBtn = extBtn, msgEl = null;

    if (!editBtn) {
      injectStyle();
      bar = document.createElement('div');
      bar.className = 'lockedit-bar';
      msgEl = document.createElement('span');
      msgEl.className = 'lockedit-msg';
      editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-warning btn-sm';
      editBtn.innerHTML = '<i class="fas fa-pen"></i> تعديل / Modifier';
      bar.appendChild(msgEl);
      bar.appendChild(editBtn);
      form.insertBefore(bar, form.firstChild);
    }

    function setLocked(locked) {
      fields.forEach(function (el) {
        if (locked) {
          if (!el.disabled) { el.disabled = true; el.setAttribute('data-lockwas', '1'); }
        } else if (el.getAttribute('data-lockwas')) {
          el.disabled = false;
          el.removeAttribute('data-lockwas');
        }
      });
      subs.forEach(function (b) { b.style.display = locked ? 'none' : ''; });
      if (extBtn) extBtn.style.display = locked ? '' : 'none';
      if (bar) {
        bar.classList.toggle('is-editing', !locked);
        editBtn.style.display = locked ? '' : 'none';
        msgEl.innerHTML = locked
          ? '<i class="fas fa-lock"></i> مقفَّل للحماية من التعديل غير المقصود — اضغط «تعديل» للكتابة / Verrouillé'
          : '<i class="fas fa-lock-open"></i> وضع التعديل مفعَّل — عدّل ثم اضغط «حفظ» / Mode édition';
      }
    }

    editBtn.addEventListener('click', function (e) {
      e.preventDefault();
      setLocked(false);
    });

    setLocked(true); // يُفتح مقفَّلاً
  }

  function init() {
    document.querySelectorAll('form.lockedit, form[data-lockedit]').forEach(setup);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
