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
    var lockFid = form.getAttribute('id'); // getAttribute لا .id (قد يغطّيه حقل اسمه id)
    var extBtn = lockFid ? document.querySelector('[data-lockedit-for="' + lockFid + '"]') : null;
    var bar = null, editBtn = extBtn, msgEl = null;
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
        bar.appendChild(editBtn);
        form.insertBefore(bar, form.firstChild);
      }
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
      editBtn.style.display = locked ? '' : 'none';
      if (bar) {
        bar.classList.toggle('is-editing', !locked);
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
