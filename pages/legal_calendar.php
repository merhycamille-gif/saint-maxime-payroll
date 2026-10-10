<?php
/**
 * ⚖️📅 مواعيد الدولة (2026-10-10 v2026 — بكلماته «في تنبيهات قانونية: شو لازم قدّم لوائح ومدفوعات للدولة، إلها أوقات محدّدة خلال السنة»)
 *   ١) الرزنامة: كل استحقاق (ضمان / مالية / صندوق التعويضات) بتاريخه وفترته وحالته: متأخّر 🔴 · قريب 🟠 · انبعت 🟢 · لاحق ⚪ — مع رابط التقرير وكبسة «قدّمت»
 *   ٢) التقارير المبعوتة للدولة (نسخ طبق الأصل مقفولة) + اللي تغيّرت منذ الإرسال — عرض النسخة المبعوتة (?view=id)
 *   ٣) تعديل المواعيد (اليوم/الأشهر/أيام التنبيه/إيقاف) — المواعيد مسبقة وقابلة للتعديل كما طلب
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/legal.php';
requireLogin();
$currentPage = 'legal_calendar';
$pageTitle = 'Échéances État / مواعيد الدولة';
$hideExportToolbar = true;
$db = getDB(); legalEnsureTables();
$self = BASE_URL . 'pages/legal_calendar.php';
$canW = canEdit();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do'])) {
    requireWriteAction($self);
    if (!verifyCsrf($_POST['csrf'] ?? '')) { $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'جلسة منتهية — أعد المحاولة']; header('Location: ' . $self); exit; }
    $do = (string)$_POST['do'];
    if ($do === 'done') { // «قدّمت» يدوياً من الرزنامة (بلا نسخة جداول — للمدفوعات أو ما قُدِّم ورقياً)
        $dkey = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['dkey'] ?? '')); $period = preg_replace('/[^0-9A-Z\-]/', '', (string)($_POST['period'] ?? ''));
        if ($dkey && $period) {
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 250);
            $db->prepare("INSERT INTO legal_filings (dkey, period, school_scope, version, status, sent_at, sent_by, title, snapshot_hash, seen_hash, note) VALUES (?,?,'',1,'sent',NOW(),?,?,?,?,?)")
               ->execute([$dkey, $period, (string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? '')), 'manuel / يدوي', str_repeat('0', 64), str_repeat('0', 64), $note]);
            logAudit('legal_done', 'legal_filings', (int)$db->lastInsertId(), null, $dkey . ' ' . $period . ' marked done');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✓ سُجّل «قدّمت» — ' . $dkey . ' ' . $period];
        }
    } elseif ($do === 'undo') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM legal_filings WHERE id = ? AND snapshot_hash = ?")->execute([$id, str_repeat('0', 64)]); // فقط التسجيلات اليدوية تُلغى
        logAudit('legal_undo', 'legal_filings', $id, null, 'manual done removed');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'رجع الموعد معلّقاً'];
    } elseif ($do === 'save_deadline') {
        $dkey = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['dkey'] ?? ''));
        $months = implode(',', array_filter(array_unique(array_map('intval', explode(',', (string)($_POST['due_months'] ?? '')))), fn($m) => $m >= 1 && $m <= 12));
        $alerts = implode(',', array_filter(array_unique(array_map('intval', explode(',', (string)($_POST['alert_days'] ?? '')))), fn($m) => $m >= 0 && $m <= 90)) ?: '15,7,3';
        $db->prepare("UPDATE legal_deadlines SET title_fr = ?, title_ar = ?, due_day = ?, due_months = ?, alert_days = ?, notes = ?, active = ? WHERE dkey = ?")
           ->execute([mb_substr(trim((string)$_POST['title_fr']), 0, 160), mb_substr(trim((string)$_POST['title_ar']), 0, 160), max(1, min(31, (int)$_POST['due_day'])), $months ?: '1', $alerts, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255), empty($_POST['active']) ? 0 : 1, $dkey]);
        logAudit('legal_deadline', 'legal_deadlines', 0, null, $dkey . ' edited');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'تم حفظ الموعد'];
    }
    unset($_SESSION['msa_todo_light']);
    header('Location: ' . $self . ($do === 'save_deadline' ? '#settings' : '')); exit;
}

// عرض نسخة مبعوتة طبق الأصل
$view = (int)($_GET['view'] ?? 0); $viewRow = null;
if ($view) { $st = $db->prepare("SELECT * FROM legal_filings WHERE id = ?"); $st->execute([$view]); $viewRow = $st->fetch(PDO::FETCH_ASSOC) ?: null; }
if ($viewRow) {
    $docFocus = true; $pageTitle = 'Copie envoyée à l’État / النسخة المبعوتة';
    $snap = json_decode((string)$viewRow['snapshot_json'], true) ?: [];
    include __DIR__ . '/../includes/header.php'; ?>
    <div class="card"><div class="card-header"><h3><i class="fas fa-lock"></i> <?= e($viewRow['title'] ?: $viewRow['dkey']) ?> — v<?= (int)$viewRow['version'] ?></h3></div>
    <div class="card-body">
        <p class="legal-meta"><b>Envoyé le / انبعت بتاريخ:</b> <?= e(date('d/m/Y H:i', strtotime($viewRow['sent_at']))) ?> <?= $viewRow['sent_by'] ? '— ' . e($viewRow['sent_by']) : '' ?> · <b>Période / الفترة:</b> <?= e(legalPeriodLabel($viewRow['period'])) ?> · <b>Empreinte / البصمة:</b> <code><?= e(substr($viewRow['snapshot_hash'], 0, 16)) ?>…</code>
        <?= $viewRow['href'] ? ' · <a href="' . e($viewRow['href']) . '">التقرير الحالي ←</a>' : '' ?></p>
        <?php if (!$snap): ?><div class="mp-ok">سُجّل «قدّمت» يدوياً بلا نسخة جداول<?= $viewRow['note'] ? ' — ' . e($viewRow['note']) : '' ?></div>
        <?php else: foreach (($snap['tables'] ?? []) as $tbl): ?>
            <div class="table-wrapper" style="margin-bottom:14px"><table class="table legal-snap"><?php foreach ($tbl as $i => $row): ?><tr><?php foreach ($row as $c): ?><<?= $i === 0 ? 'th' : 'td' ?>><?= e($c) ?></<?= $i === 0 ? 'th' : 'td' ?>><?php endforeach; ?></tr><?php endforeach; ?></table></div>
        <?php endforeach; if (!empty($snap['text'])): ?><pre style="white-space:pre-wrap;font:inherit"><?= e($snap['text']) ?></pre><?php endif; endif; ?>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php'; exit;
}

$occ = legalOccurrences(400, 400);
$today = date('Y-m-d');
$late = array_filter($occ, fn($o) => $o['status'] === 'late'); $soon = array_filter($occ, fn($o) => $o['status'] === 'pending' && $o['days'] <= 30);
$filings = $db->query("SELECT * FROM legal_filings ORDER BY sent_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$changed = array_filter($filings, fn($f) => $f['status'] === 'sent' && (int)$f['changed_flag'] === 1);
$deadlines = $db->query("SELECT * FROM legal_deadlines ORDER BY sort_order, dkey")->fetchAll(PDO::FETCH_ASSOC);
$authLbl = ['cnss' => ['CNSS', 'الضمان', 'var(--ic4)'], 'mof' => ['Finances', 'المالية', 'var(--ic2)'], 'eoc' => ['Caisse', 'صندوق التعويضات', 'var(--ic5)']];
$stLbl = ['late' => ['Retard', 'متأخّر', 'late'], 'pending' => ['À faire', 'معلّق', 'pend'], 'sent' => ['Envoyé', 'انبعت', 'sent']];
include __DIR__ . '/../includes/header.php';
?>
<div class="mp-steps no-print" style="grid-template-columns:repeat(4,1fr)">
    <a class="mp-step <?= $late ? 'bad' : '' ?>" href="#cal"><span class="mp-n"><?= count($late) ?></span><span><b>En retard / متأخّر</b><small>مواعيد فاتت وما سُجّل «قدّمت»</small></span></a>
    <a class="mp-step cur" href="#cal"><span class="mp-n"><?= count($soon) ?></span><span><b>≤ 30 jours / خلال 30 يوم</b><small>جهّز التقرير وابعته</small></span></a>
    <a class="mp-step <?= $changed ? 'bad' : '' ?>" href="#changed"><span class="mp-n"><?= count($changed) ?></span><span><b>Modifiés après envoi / تغيّرت بعد الإرسال</b><small>صحّحه أو خلّيه</small></span></a>
    <a class="mp-step" href="#sent"><span class="mp-n"><?= count($filings) ?></span><span><b>Envoyés / المبعوتة</b><small>نسخ طبق الأصل مقفولة</small></span></a>
</div>

<div class="card" id="cal">
    <div class="card-header"><h3><i class="fas fa-landmark"></i> Calendrier légal / الرزنامة القانونية <small style="font-weight:500;opacity:.8">— الضمان · المالية · صندوق التعويضات</small></h3>
        <a class="btn btn-sm btn-light no-print" href="#settings"><i class="fas fa-sliders"></i> عدّل المواعيد</a></div>
    <div class="card-body">
        <div class="legal-grid">
        <?php $shown = 0; foreach ($occ as $o): if ($o['status'] === 'sent' && $o['days'] < -120) continue; if ($o['status'] !== 'sent' && $o['days'] > 400) continue; $shown++;
            $d = $o['d']; $a = $authLbl[$d['authority']] ?? [$d['authority'], '', 'var(--ic6)']; $s = $stLbl[$o['status']]; $f = $o['filing']; ?>
            <div class="legal-item <?= $s[2] ?>" id="due-<?= e($d['dkey']) ?>-<?= e($o['period']) ?>">
                <div class="li-date"><b><?= date('d', strtotime($o['due'])) ?></b><span><?= monthName((int)date('n', strtotime($o['due'])), 'fr', true) ?> <?= date('Y', strtotime($o['due'])) ?></span></div>
                <div class="li-body">
                    <div class="li-auth" style="--a:<?= $a[2] ?>"><?= e($a[0]) ?> / <?= e($a[1]) ?> · <?= e(legalPeriodLabel($o['period'])) ?></div>
                    <div class="li-title"><?= e($d['title_fr']) ?></div>
                    <div class="li-ar"><?= e($d['title_ar']) ?><?= $d['notes'] ? ' <small>— ' . e($d['notes']) . '</small>' : '' ?></div>
                    <div class="li-st">
                        <?php if ($o['status'] === 'sent'): ?><span class="badge badge-success"><i class="fas fa-check"></i> انبعت <?= e(date('d/m/Y', strtotime($f['sent_at']))) ?><?= $f['sent_by'] ? ' — ' . e($f['sent_by']) : '' ?></span>
                        <?php elseif ($o['status'] === 'late'): ?><span class="badge badge-danger"><i class="fas fa-triangle-exclamation"></i> متأخّر <?= abs($o['days']) ?> يوم</span>
                        <?php elseif ($o['days'] === 0): ?><span class="badge badge-warning">اليوم!</span>
                        <?php elseif ($o['days'] <= 15): ?><span class="badge badge-warning">باقي <?= $o['days'] ?> يوم</span>
                        <?php else: ?><span class="badge badge-light">بعد <?= $o['days'] ?> يوم</span><?php endif; ?>
                    </div>
                </div>
                <div class="li-act no-print">
                    <?php if ($d['report_href']): ?><a class="btn btn-sm btn-light" href="<?= e($d['report_href']) ?>"><i class="fas fa-file-lines"></i> التقرير</a><?php endif; ?>
                    <?php if ($canW && $o['status'] !== 'sent'): ?>
                    <form method="post" style="margin:0;display:inline" onsubmit="return confirm('تسجيل «قدّمت» لهالموعد؟')"><?= csrfField() ?><input type="hidden" name="do" value="done"><input type="hidden" name="dkey" value="<?= e($d['dkey']) ?>"><input type="hidden" name="period" value="<?= e($o['period']) ?>">
                        <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-check"></i> قدّمت</button></form>
                    <?php elseif ($canW && $f && $f['snapshot_hash'] === str_repeat('0', 64)): ?>
                    <form method="post" style="margin:0;display:inline" onsubmit="return confirm('إلغاء «قدّمت»؟')"><?= csrfField() ?><input type="hidden" name="do" value="undo"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-sm btn-light" type="submit"><i class="fas fa-rotate-left"></i> تراجع</button></form>
                    <?php elseif ($f): ?><a class="btn btn-sm btn-light" href="?view=<?= (int)$f['id'] ?>"><i class="fas fa-eye"></i> النسخة المبعوتة</a><?php endif; ?>
                </div>
            </div>
        <?php endforeach; if (!$shown): ?><div class="mp-ok">ما في مواعيد مفعّلة — فعّلها من «عدّل المواعيد»</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="card" id="changed">
    <div class="card-header"><h3><i class="fas fa-triangle-exclamation"></i> Modifiés après envoi / تقارير انبعتت وتغيّرت بعدها (<?= count($changed) ?>)</h3></div>
    <div class="card-body">
        <?php if (!$changed): ?><div class="mp-ok"><i class="fas fa-circle-check"></i> كل التقارير المبعوتة مطابقة لما أُرسل</div><?php else: ?>
        <p class="hint">افتح التقرير: بيطلعلك شريط «تغيّر منذ الإرسال» مع كبستين: <b>صحّحه</b> (نسخة تصحيحية جديدة، الأصل بيضلّ محفوظ) أو <b>خلّيه متل ما هو</b>.</p>
        <div class="table-wrapper"><table class="table mp-table"><thead><tr><th>Rapport / التقرير</th><th>Période / الفترة</th><th>Envoyé / انبعت</th><th>Ce qui a changé / شو تغيّر</th><th class="no-print"></th></tr></thead><tbody>
        <?php foreach ($changed as $f): ?><tr><td><strong><?= e($f['title'] ?: $f['dkey']) ?></strong><br><small><?= e($f['dkey']) ?></small></td><td><?= e(legalPeriodLabel($f['period'])) ?></td><td><?= e(date('d/m/Y H:i', strtotime($f['sent_at']))) ?> v<?= (int)$f['version'] ?></td>
            <td><small><?= nl2br(e(implode("\n", array_slice(array_filter(explode("\n", (string)$f['changed_note'])), 0, 4)))) ?></small></td>
            <td class="no-print"><?= $f['href'] ? '<a class="btn btn-sm btn-primary" href="' . e($f['href']) . '"><i class="fas fa-arrow-up-right-from-square"></i> افتح وقرّر</a> ' : '' ?><a class="btn btn-sm btn-light" href="?view=<?= (int)$f['id'] ?>"><i class="fas fa-eye"></i> المبعوت</a></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
</div>

<div class="card" id="sent">
    <div class="card-header"><h3><i class="fas fa-lock"></i> Rapports envoyés à l’État / التقارير المبعوتة للدولة (<?= count($filings) ?>)</h3></div>
    <div class="card-body">
        <?php if (!$filings): ?><div class="mp-ok">لسّا ما انبعت شي — بكل تقرير رسمي في كبسة «انبعت للدولة» بتقفله طبق الأصل</div><?php else: ?>
        <div class="mp-tool no-print"><input type="search" class="form-control" placeholder="⚡ صفّي: تقرير، فترة، سنة…" data-filter="#sent tbody tr"></div>
        <div class="table-wrapper"><table class="table mp-table"><thead><tr><th>Rapport / التقرير</th><th>Période / الفترة</th><th>Version</th><th>Envoyé / انبعت</th><th>Par / بواسطة</th><th>Statut / الحالة</th><th class="no-print"></th></tr></thead><tbody>
        <?php foreach ($filings as $f): ?><tr data-q="<?= e(mb_strtolower(($f['title'] ?: '') . ' ' . $f['dkey'] . ' ' . $f['period'] . ' ' . $f['sent_at'])) ?>"><td><strong><?= e($f['title'] ?: $f['dkey']) ?></strong><br><small><?= e($f['dkey']) ?></small></td><td><?= e(legalPeriodLabel($f['period'])) ?></td><td>v<?= (int)$f['version'] ?></td><td><?= e(date('d/m/Y H:i', strtotime($f['sent_at']))) ?></td><td><?= e((string)$f['sent_by']) ?></td>
            <td><?= $f['status'] === 'superseded' ? '<span class="badge badge-light">استُبدل بنسخة تصحيحية</span>' : ((int)$f['changed_flag'] ? '<span class="badge badge-danger">تغيّر بعد الإرسال</span>' : '<span class="badge badge-success">مقفول · مطابق</span>') ?></td>
            <td class="no-print"><a class="btn btn-sm btn-light" href="?view=<?= (int)$f['id'] ?>"><i class="fas fa-eye"></i> المبعوت</a> <?= $f['href'] ? '<a class="btn btn-sm btn-light" href="' . e($f['href']) . '"><i class="fas fa-file-lines"></i> الحالي</a>' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
</div>

<?php if ($canW): ?>
<div class="card" id="settings">
    <div class="card-header"><h3><i class="fas fa-sliders"></i> Réglage des échéances / تعديل المواعيد</h3></div>
    <div class="card-body">
        <p class="hint">المواعيد مسبقة حسب المعمول به بلبنان — تحقّق منها مع المحاسب/الضمان وعدّلها هون: يوم الاستحقاق، أشهره (مثلاً 1,4,7,10 للفصلي)، وأيام التنبيه قبل الموعد (15,7,3).</p>
        <div class="table-wrapper"><table class="table mp-table legal-set"><thead><tr><th>Clé</th><th>Titre FR</th><th>العنوان</th><th>Jour / اليوم</th><th>Mois / الأشهر</th><th>Alerte / التنبيه (أيام)</th><th>Note</th><th>Actif</th><th></th></tr></thead><tbody>
        <?php foreach ($deadlines as $d): ?>
        <tr><form method="post"><?= csrfField() ?><input type="hidden" name="do" value="save_deadline"><input type="hidden" name="dkey" value="<?= e($d['dkey']) ?>">
            <td><small><?= e($d['dkey']) ?></small><br><small style="color:var(--gray-500)"><?= e($authLbl[$d['authority']][1] ?? $d['authority']) ?> · <?= e($d['kind']) ?></small></td>
            <td><input class="form-control" name="title_fr" value="<?= e($d['title_fr']) ?>"></td><td><input class="form-control" name="title_ar" dir="rtl" value="<?= e($d['title_ar']) ?>"></td>
            <td><input class="form-control" name="due_day" type="number" min="1" max="31" value="<?= (int)$d['due_day'] ?>" style="width:70px"></td>
            <td><input class="form-control" name="due_months" value="<?= e($d['due_months']) ?>" style="width:110px"></td>
            <td><input class="form-control" name="alert_days" value="<?= e($d['alert_days']) ?>" style="width:90px"></td>
            <td><input class="form-control" name="notes" value="<?= e((string)$d['notes']) ?>"></td>
            <td style="text-align:center"><input type="checkbox" name="active" value="1" <?= $d['active'] ? 'checked' : '' ?>></td>
            <td><button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-save"></i></button></td>
        </form></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
