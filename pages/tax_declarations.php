<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$currentPage = 'tax';
$pageTitle = 'Déclarations fiscales / التصاريح الضريبية';
$db = getDB();

// صفحة قائمة فقط: لا شيء يُصدَّر منها — شريط التصدير زائد (2026-08-19)
$hideExportToolbar = true;
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-file-contract"></i> Déclarations officielles</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">التصاريح الرسمية</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Ces formulaires reprennent les données calculées dans la paie mensuelle/annuelle.
        </div>
        
        <div class="form-row cols-3">
            <a href="?form=r5" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body text-center">
                    <i class="fas fa-file-alt" style="font-size:36px;color:var(--primary)"></i>
                    <h4 style="margin:12px 0 4px">
                        <span dir="ltr">R5 — Trimestriel</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">R5 — فصلي</div>
                    </h4>
                    <p style="color:var(--gray-500);font-size:13px">Retenues à la source / اقتطاعات من المصدر</p>
                </div>
            </a>
            <a href="?form=r6" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body text-center">
                    <i class="fas fa-file-invoice" style="font-size:36px;color:var(--info)"></i>
                    <h4 style="margin:12px 0 4px">
                        <span dir="ltr">R6 — Annuel</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">R6 — سنوي</div>
                    </h4>
                    <p style="color:var(--gray-500);font-size:13px">Récapitulatif annuel / ملخّص سنوي</p>
                </div>
            </a>
            <a href="?form=r7" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body text-center">
                    <i class="fas fa-receipt" style="font-size:36px;color:var(--success)"></i>
                    <h4 style="margin:12px 0 4px">
                        <span dir="ltr">R7 — Individuel</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">R7 — فردي</div>
                    </h4>
                    <p style="color:var(--gray-500);font-size:13px">Par employé / لكل موظف</p>
                </div>
            </a>
            <a href="?form=r10" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body text-center">
                    <i class="fas fa-file-export" style="font-size:36px;color:var(--warning)"></i>
                    <h4 style="margin:12px 0 4px">
                        <span dir="ltr">R10</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">تصريح شهري</div>
                    </h4>
                    <p style="color:var(--gray-500);font-size:13px">Déclaration mensuelle / تصريح شهري</p>
                </div>
            </a>
            <a href="?form=190a" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body text-center">
                    <i class="fas fa-hospital-symbol" style="font-size:36px;color:var(--danger)"></i>
                    <h4 style="margin:12px 0 4px">
                        <span dir="ltr">190A — CNSS</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">190A — الضمان الاجتماعي</div>
                    </h4>
                    <p style="color:var(--gray-500);font-size:13px">Déclaration CNSS / تصريح الضمان</p>
                </div>
            </a>
            <a href="?form=cnss_summary" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body text-center">
                    <i class="fas fa-list" style="font-size:36px;color:var(--gold)"></i>
                    <h4 style="margin:12px 0 4px">
                        <span dir="ltr">Récap CNSS</span>
                        <div style="font-size:0.85em;font-weight:600;opacity:0.9">ملخّص الضمان</div>
                    </h4>
                    <p style="color:var(--gray-500);font-size:13px">Détail trimestriel / تفصيل فصلي</p>
                </div>
            </a>
        </div>
        
        <div class="alert alert-warning mt-4">
            <i class="fas fa-tools"></i>
            <strong>Note:</strong> Les formulaires officiels peuvent être imprimés à partir de la page Rapports avec les données déjà calculées.
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
