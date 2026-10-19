<?php
/**
 * 📬 سجلّ الإيميلات (2026-10-19 «أوقات بيوصلو أوقات ما بيوصلو — بدّي حلّ جذري ورسمي، ما في ضلّ قاعد تلفن إذا وصلون»):
 * كل رسالة خرجت من البرنامج (تقرير/إفادة/إشعار، من الكمبيوتر أو الموقع) مع حالتها الحقيقية عند خدمة الإرسال:
 * وصل / فُتح / ارتدّ / متأخّر / شكوى / لم يُرسَل. الحالات المعلّقة تُسأل عنها الخدمة عند فتح الصفحة وبكبسة «تحديث».
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
if (!isAdmin()) { $_SESSION['flash_error'] = 'صلاحية المدير فقط'; header('Location: ' . BASE_URL . 'index.php'); exit; }

$currentPage = 'mail_log';
$pageTitle = 'سجلّ الإيميلات / Suivi des e-mails';
ensureMailLogTable();
$refreshed = msaMailLogRefresh(isset($_GET['refresh']) ? 60 : 25);
if (isset($_GET['refresh'])) { $_SESSION['flash_success'] = 'حُدِّثت حالات الرسائل من خدمة الإرسال' . ($refreshed ? " ($refreshed تغيّرت)" : ''); header('Location: ' . BASE_URL . 'pages/mail_log.php'); exit; }

$db = getDB();
$q = trim((string)($_GET['q'] ?? ''));
$days = max(1, min(365, (int)($_GET['days'] ?: 30)));
$where = 'created_at >= ?'; $args = [date('Y-m-d 00:00:00', time() - ($days - 1) * 86400)];
if ($q !== '') { $where .= ' AND (to_addr LIKE ? OR subject LIKE ? OR file_name LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
$st = $db->prepare("SELECT * FROM mail_log WHERE $where ORDER BY created_at DESC LIMIT 400");
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$schools = [];
try { foreach ($db->query("SELECT id, name_ar, name_fr FROM schools")->fetchAll(PDO::FETCH_ASSOC) as $s) $schools[(int)$s['id']] = $s['name_ar'] ?: $s['name_fr']; } catch (Throwable $e) {}
$cnt = ['delivered' => 0, 'pending' => 0, 'problem' => 0];
foreach ($rows as $r) {
    $s = (string)$r['status'];
    if (in_array($s, ['delivered', 'opened', 'clicked'], true)) $cnt['delivered']++;
    elseif (in_array($s, ['sent', 'queued', 'delivery_delayed'], true)) $cnt['pending']++;
    else $cnt['problem']++;
}
$viaLabel = function (string $v): string {
    $v = strtolower($v);
    if (strpos($v, 'relay:') === 0) $v = substr($v, 6) ?: 'relay';
    switch ($v) {
        case 'resend_api': case 'resend_smtp': case 'program': return 'إيميل البرنامج (خدمة الإرسال)';
        case 'school_smtp': case 'smtp': return 'إيميل المدرسة';
        case 'server_mail': return 'بريد الخادم';
        case 'relay': return 'عبر الموقع';
    }
    return $v !== '' ? $v : '—';
};
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-envelope-open-text"></i> Suivi des e-mails envoyés</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">سجلّ الإيميلات المرسَلة وحالة وصولها</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="line-height:1.9">
            كل إيميل يخرج من البرنامج يُسجَّل هنا مع حالته الحقيقية عند خدمة الإرسال: <strong>✅ وصل</strong> = استلمه بريد المستلم (إذا ما لقيه بالوارد فهو بمجلّد Junk/Spam عنده) ·
            <strong>✅ وصل وفُتح</strong> = فتح الرسالة · <strong>⛔ ارتدّ</strong> = العنوان غلط أو غير موجود · <strong>⏳ متأخّر</strong> = بريد المستلم بطيء والخدمة تعيد المحاولة لحالها ·
            <strong>✕ لم يُرسَل</strong> = ما خرج أصلاً (السبب مكتوب). الرسالة نفسها لا تُرسَل مرّتين خلال دقيقتين.
        </div>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
            <div class="form-group" style="margin:0;flex:1;min-width:220px"><label class="form-label">بحث / Recherche (إيميل، موضوع، ملف)</label><input type="text" name="q" class="form-control" value="<?= e($q) ?>"></div>
            <div class="form-group" style="margin:0"><label class="form-label">آخر / Derniers</label>
                <select name="days" class="form-select"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $d ?> يوم</option><?php endforeach; ?></select></div>
            <button class="btn btn-primary"><i class="fas fa-search"></i> عرض / Afficher</button>
            <a class="btn btn-success" href="<?= BASE_URL ?>pages/mail_log.php?refresh=1&days=<?= $days ?>&q=<?= rawurlencode($q) ?>"><i class="fas fa-sync"></i> تحديث الحالات من الخدمة / Actualiser</a>
            <?php if (isSuperAdmin()): ?><a class="btn btn-info" href="<?= BASE_URL ?>pages/email_settings.php"><i class="fas fa-cog"></i> إعدادات البريد</a><?php endif; ?>
        </form>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;font-weight:700">
            <span class="badge" style="background:#dcfce7;color:#166534;padding:6px 12px;font-size:14px">✅ وصل: <?= $cnt['delivered'] ?></span>
            <span class="badge" style="background:#f1f5f9;color:#334155;padding:6px 12px;font-size:14px">📤 بانتظار التأكيد: <?= $cnt['pending'] ?></span>
            <span class="badge" style="background:#e0e7ff;color:#173f6f;padding:6px 12px;font-size:14px">⛔ مشكلة: <?= $cnt['problem'] ?></span>
            <span style="color:var(--gray-500);font-weight:400;align-self:center">المجموع <?= count($rows) ?> خلال آخر <?= $days ?> يوم</span>
        </div>
        <div class="table-wrapper tbl-scroll">
            <table class="table" style="font-size:14px">
                <thead><tr>
                    <th>التاريخ / Date</th>
                    <th>إلى / À</th>
                    <th>الموضوع / Objet</th>
                    <th>الملف / Fichier</th>
                    <th>المدرسة / École</th>
                    <th>من / Par</th>
                    <th>الحالة / Statut</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="7" style="color:var(--gray-500);text-align:center">لا رسائل بهذه الفترة / Aucun e-mail</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): [$lbl, $bg, $fg] = msaMailStatusLabel((string)$r['status']); ?>
                    <tr>
                        <td style="white-space:nowrap" dir="ltr"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                        <td dir="ltr" style="white-space:nowrap"><?= e($r['to_addr']) ?></td>
                        <td><?= e($r['subject']) ?></td>
                        <td dir="ltr" style="font-size:12.5px;color:var(--gray-600)"><?= e($r['file_name'] ?: '—') ?></td>
                        <td style="white-space:nowrap"><?= e($schools[(int)$r['school_id']] ?? '—') ?></td>
                        <td style="white-space:nowrap;font-size:12.5px"><?= e($viaLabel((string)$r['via'])) ?><?= $r['sent_by'] ? '<br><span style="color:var(--gray-500)">' . e($r['sent_by']) . '</span>' : '' ?></td>
                        <td style="white-space:nowrap">
                            <span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>;padding:5px 10px;font-size:13px;font-weight:700"><?= e($lbl) ?></span>
                            <?php if ($r['status'] === 'failed' && $r['error']): ?><div style="font-size:12px;color:#173f6f;max-width:420px;white-space:normal;margin-top:3px"><?= e($r['error']) ?></div><?php endif; ?>
                            <?php if ($r['checked_at']): ?><div style="font-size:11.5px;color:var(--gray-500)">آخر فحص <?= e(date('d/m H:i', strtotime($r['checked_at']))) ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
