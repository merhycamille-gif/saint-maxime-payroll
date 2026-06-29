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

// Alert function
function showAlert(msg, type = 'info') {
    const div = document.createElement('div');
    div.className = `alert alert-${type}`;
    div.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.15)';
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}
