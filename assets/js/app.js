/**
 * رواتب المدارس / SALAIRES DES ÉCOLES - Main JS
 */

// Format LBP numbers
function formatLBP(n) {
    return new Intl.NumberFormat('en-US').format(Math.round(n)) + ' L.L';
}

function formatUSD(n) {
    return '$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

// Tab switching
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('tab')) {
        const tabGroup = e.target.closest('.tabs');
        const tabName = e.target.dataset.tab;
        if (!tabGroup || !tabName) return;

        tabGroup.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        e.target.classList.add('active');

        const container = tabGroup.parentElement;
        container.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        const target = container.querySelector(`.tab-content[data-tab-content="${tabName}"]`);
        if (target) target.classList.add('active');

        // تذكّر التبويب الحالي في الفورم ليرجع إليه بعد الحفظ
        const hidden = document.getElementById('activeTabField');
        if (hidden) hidden.value = tabName;
    }
});

// بعد الحفظ: ارجع لنفس التبويب الذي كان مفتوحاً (?tab=...)
document.addEventListener('DOMContentLoaded', function() {
    const t = new URLSearchParams(location.search).get('tab');
    if (t) {
        const btn = document.querySelector('.tab[data-tab="' + t + '"]');
        if (btn) btn.click();
    }
});

// Confirm delete
document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-confirm]');
    if (btn) {
        const msg = btn.dataset.confirm || 'Êtes-vous sûr ?';
        if (!confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }
});

// Auto-format number inputs
document.querySelectorAll('.format-number').forEach(input => {
    input.addEventListener('blur', function() {
        const val = parseFloat(this.value.replace(/[^0-9.-]/g, ''));
        if (!isNaN(val)) {
            this.value = new Intl.NumberFormat('en-US').format(val);
        }
    });
});

// Toggle visibility
document.addEventListener('change', function(e) {
    if (e.target.dataset.toggle) {
        const targetId = e.target.dataset.toggle;
        const target = document.getElementById(targetId);
        if (target) {
            target.style.display = e.target.checked ? '' : 'none';
        }
    }
});

// 📌 تثبيت رؤوس الجداول أثناء التمرير (على كل البرنامج):
// كل جدول طويل يتمرّر داخل حاويته (tbl-scroll) ورأسه يبقى ظاهراً فوق.
// يدعم الرؤوس المركّبة من صفّين (rowspan/colspan): كل صف يلتصق تحت الذي قبله.
(function () {
    function initStickyHeads() {
        var tables = document.querySelectorAll('table.table, table.doc-table, table.salary-slip-table');
        for (var k = 0; k < tables.length; k++) {
            var t = tables[k];
            if (!t.tHead || t.tHead.rows.length === 0) continue;
            // الحاوية التي سيتمرّر الجدول داخلها: أقرب أب يمرّر عمودياً،
            // وإلا (أب مقصوص overflow:hidden مثل .card) الأب المباشر للجدول
            var sc = null, p = t.parentElement;
            while (p && p !== document.body) {
                var oy = getComputedStyle(p).overflowY;
                if (oy === 'auto' || oy === 'scroll') { sc = p; break; }
                if (oy === 'hidden' || oy === 'clip') break;
                p = p.parentElement;
            }
            (sc || t.parentElement).classList.add('tbl-scroll');
            // صفوف الرأس المتعدّدة: top تراكمي حتى لا يغطي الصف الأول الثاني
            var top = 0, rows = t.tHead.rows;
            for (var i = 0; i < rows.length; i++) {
                for (var j = 0; j < rows[i].cells.length; j++) {
                    rows[i].cells[j].style.top = top + 'px';
                }
                top += rows[i].offsetHeight;
            }
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initStickyHeads);
    else initStickyHeads();
    window.addEventListener('load', initStickyHeads);   // بعد الخطوط/الصور وملاءمة fitDocTables
    window.addEventListener('resize', initStickyHeads);
})();

// Alert function
function showAlert(msg, type = 'info') {
    const div = document.createElement('div');
    div.className = `alert alert-${type}`;
    div.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.15)';
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}
