<?php
/**
 * 🏫🔑 دخول المدارس وأيقوناتها — Accès écoles (2026-10-22)
 * صفّ لكل مدرسة: اسم الدخول (اسم المنطقة) + كلمة السرّ (ظاهرة للمدير) + ما تراه (✓) + مفعّل/موقوف
 * + إرسال الرابط والأيقونة بالإيميل / نسخ رسالة واتساب / فتح رابط التركيب.
 * للمدير فقط. يكتب على جدول users نفسه الذي تستعمله صفحة «حسابات المدارس» (users.php).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/school_access.php';
requireLogin();

if (!isAdmin()) {
    $_SESSION['flash_error'] = 'Accès réservé à l\'administrateur / صلاحية المدير فقط';
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$currentPage = 'school_access';
$pageTitle   = 'Accès écoles / دخول المدارس وأيقوناتها';
$db   = getDB();
saEnsureCols();
ensureUsersPermsColumn();
$lang = $_SESSION['lang'] ?? 'fr';
$pages = viewerReportPages();
$self = BASE_URL . 'pages/school_access.php';

/** أسماء الدخول المأخوذة: username ⇒ id */
function saTakenMap(PDO $db): array {
    $m = [];
    foreach ($db->query("SELECT id, username FROM users")->fetchAll() as $r) $m[strtolower((string)$r['username'])] = (int)$r['id'];
    return $m;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $_SESSION['flash_error'] = 'Session expirée / انتهت الجلسة';
        header('Location: ' . $self); exit;
    }
    $act = (string)($_POST['act'] ?? '');

    // ===== تطبيق النموذج على كل المدارس الفعّالة: اسم المنطقة + كلمة السرّ النموذجية (+ إنشاء حساب للمدرسة التي بلا حساب) =====
    if ($act === 'apply_standard') {
        $renameAll = !empty($_POST['rename_all']);
        $taken = saTakenMap($db);
        $allPages = implode(',', array_keys($pages));
        $n = 0; $out = [];
        foreach (saSchoolsForNaming() as $s) {
            $sid = (int)$s['id'];
            $u = saViewerForSchool($sid);
            if ($u) {
                $uid = (int)$u['id'];
                $cur = (string)$u['username'];
                $isAuto = (bool)preg_match('/^ecole\d*$/i', $cur);
                $newU = ($isAuto || $renameAll) ? saUniqueUsername($s, $taken, $uid) : $cur;
                unset($taken[strtolower($cur)]); $taken[strtolower($newU)] = $uid;
                $pw = (string)($u['pw_known'] ?? '');
                if ($pw === '' || $renameAll) $pw = saStandardPassword($newU);
                $db->prepare("UPDATE users SET username = ?, password_hash = ?, pw_known = ?, is_active = 1 WHERE id = ?")
                   ->execute([$newU, password_hash($pw, PASSWORD_DEFAULT), $pw, $uid]);
            } else {
                $newU = saUniqueUsername($s, $taken); $taken[strtolower($newU)] = -1;
                $pw = saStandardPassword($newU);
                $db->prepare("INSERT INTO users (username,password_hash,full_name,role,school_id,is_active,allowed_pages,allowed_schools,pw_known) VALUES (?,?,?,?,?,1,?,?,?)")
                   ->execute([$newU, password_hash($pw, PASSWORD_DEFAULT), ($s['name_fr'] ?: $s['name_ar']), 'viewer', $sid, $allPages, (string)$sid, $pw]);
            }
            $n++; $out[] = $newU;
        }
        if (function_exists('logAudit')) logAudit('school_access_standard', 'users', 0, null, implode(',', $out));
        $_SESSION['flash_success'] = $n . ' écoles : identifiant = région, mot de passe standard / ' . $n . ' مدرسة: اسم الدخول = المنطقة وكلمة السرّ النموذجية';
        header('Location: ' . $self); exit;
    }

    // ===== حفظ صفّ مدرسة =====
    if ($act === 'save_row') {
        $uid = (int)($_POST['id'] ?? 0);
        $sid = (int)($_POST['school_id'] ?? 0);
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $username = preg_replace('/[^a-z0-9._-]/', '', $username);
        $password = (string)($_POST['password'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $sel = $_POST['allowed'] ?? []; if (!is_array($sel)) $sel = [];
        $sel = array_values(array_intersect($sel, array_keys($pages)));
        $allowed = implode(',', $sel);
        $err = '';
        if ($username === '' || strlen($username) < 3) $err = 'اسم الدخول 3 أحرف على الأقل (لاتيني) / Identifiant : 3 caractères minimum';
        elseif ($password !== '' && strlen($password) < 6) $err = 'كلمة السرّ 6 أحرف على الأقل / Mot de passe : 6 caractères minimum';
        else {
            $taken = saTakenMap($db);
            if (isset($taken[$username]) && $taken[$username] !== $uid) $err = 'اسم الدخول مستخدم لحساب آخر / Identifiant déjà utilisé';
        }
        if ($err) { $_SESSION['flash_error'] = $err; header('Location: ' . $self . '#s' . $sid); exit; }
        $s = null; foreach (allSchools(false) as $x) if ((int)$x['id'] === $sid) { $s = $x; break; }
        if (!$s) { $_SESSION['flash_error'] = 'École introuvable / المدرسة غير موجودة'; header('Location: ' . $self); exit; }
        if ($uid > 0) {
            $cur = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'viewer'"); $cur->execute([$uid]); $cur = $cur->fetch();
            if (!$cur) { $_SESSION['flash_error'] = 'Compte introuvable / الحساب غير موجود'; header('Location: ' . $self); exit; }
            if ($password !== '') {
                $db->prepare("UPDATE users SET username=?, password_hash=?, pw_known=?, is_active=?, allowed_pages=? WHERE id=?")
                   ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $password, $active, $allowed, $uid]);
            } else {
                $db->prepare("UPDATE users SET username=?, is_active=?, allowed_pages=? WHERE id=?")->execute([$username, $active, $allowed, $uid]);
            }
            if (function_exists('logAudit')) logAudit('update', 'users', $uid, null, $username);
        } else {
            if ($password === '') $password = saStandardPassword($username);
            $db->prepare("INSERT INTO users (username,password_hash,full_name,role,school_id,is_active,allowed_pages,allowed_schools,pw_known) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$username, password_hash($password, PASSWORD_DEFAULT), ($s['name_fr'] ?: $s['name_ar']), 'viewer', $sid, $active, $allowed, (string)$sid, $password]);
            if (function_exists('logAudit')) logAudit('create', 'users', (int)$db->lastInsertId(), null, $username);
        }
        $_SESSION['flash_success'] = 'Enregistré / تمّ الحفظ — ' . ($s['name_fr'] ?: $s['name_ar']);
        header('Location: ' . $self . '#s' . $sid); exit;
    }

    // ===== إرسال الرابط والدخول بالإيميل للمدرسة =====
    if ($act === 'send_mail') {
        $sid = (int)($_POST['school_id'] ?? 0);
        $s = null; foreach (allSchools(false) as $x) if ((int)$x['id'] === $sid) { $s = $x; break; }
        $u = $s ? saViewerForSchool($sid) : null;
        $to = trim((string)($_POST['to'] ?? ($s['email'] ?? '')));
        if (!$s || !$u) $_SESSION['flash_error'] = 'لا حساب لهذه المدرسة بعد — احفظ الصفّ أوّلاً / Pas de compte : enregistrez d\'abord';
        elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) $_SESSION['flash_error'] = 'لا إيميل صحيح للمدرسة — اكتبه بصفحة المدارس / E-mail de l\'école manquant';
        else {
            $pw = (string)($u['pw_known'] ?? '');
            $subject = 'MSA Payroll — Accès et icône / دخول البرنامج — ' . ($s['name_fr'] ?: $s['name_ar']);
            [$ok, $errm] = msaSendMail($to, $subject, saMessageText($s, $u, $pw, true), [], true, $sid);
            if ($ok) { $_SESSION['flash_success'] = 'أُرسل إلى ' . $to . ' / Envoyé à ' . $to; if (function_exists('logAudit')) logAudit('school_access_mail', 'users', (int)$u['id'], null, $to); }
            else $_SESSION['flash_error'] = 'فشل الإرسال / Échec : ' . $errm;
        }
        header('Location: ' . $self . '#s' . $sid); exit;
    }
    header('Location: ' . $self); exit;
}

$schools = allSchools(false);
usort($schools, fn($a, $b) => ((int)$b['is_active'] <=> (int)$a['is_active']) ?: strcmp((string)$a['name_fr'], (string)$b['name_fr']));
$takenNow = saTakenMap($db);
$mailOk = programMailAvailable() || (function_exists('smtpConfigFromSettings') && !empty(smtpConfigFromSettings()['host']));

include __DIR__ . '/../includes/header.php';
?>
<style>
.sa-table td{vertical-align:top}
.sa-table .sa-school b{display:block;font-size:14px}
.sa-table .sa-school small{display:block;color:#64748b}
.sa-table input.form-control{min-width:150px;font-family:Consolas,monospace;font-size:14px;padding:6px 8px}
.sa-pages label{display:inline-flex;align-items:center;gap:5px;margin:2px 10px 2px 0;white-space:nowrap;font-size:13px}
.sa-btns{display:flex;flex-wrap:wrap;gap:6px}
.sa-btns .btn{padding:6px 10px;font-size:13px}
.sa-off{opacity:.55}
.sa-pw{position:relative;display:flex;gap:4px;align-items:center}
.sa-pw .btn{padding:6px 8px}
.sa-link{font-family:Consolas,monospace;font-size:12px;color:#1f4e79;word-break:break-all}
</style>

<div class="page-actions no-print">
    <form method="POST" style="display:inline" data-confirm="<?= e('Appliquer à toutes les écoles actives : identifiant = nom de la région, mot de passe standard (Région@' . date('Y') . ') pour celles sans mot de passe connu ? / تطبيق على كل المدارس الفعّالة: اسم الدخول = اسم المنطقة، وكلمة السرّ النموذجية (المنطقة@' . date('Y') . ') لمن ليس لها كلمة سرّ معروفة؟') ?>">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="act" value="apply_standard">
        <button type="submit" class="btn btn-success"><i class="fas fa-wand-magic-sparkles"></i> Identifiants standards / اسم المنطقة + كلمة السرّ النموذجية لكل المدارس</button>
    </form>
    <a href="<?= BASE_URL ?>pages/users.php" class="btn btn-info"><i class="fas fa-user-shield"></i> Comptes avancés / الحسابات المتقدّمة</a>
</div>

<div class="alert alert-info no-print">
    <i class="fas fa-circle-info"></i>
    <?= $lang === 'ar'
        ? 'كل مدرسة إلها <strong>اسم دخول = اسم منطقتها</strong> وكلمة سرّ ظاهرة لك هنا. ضع ✓ على ما تريدها أن تراه، ثمّ <strong>أرسِل</strong> لها الرابط بالإيميل أو انسخ الرسالة للواتساب. المدرسة تفتح الرابط بـChrome أو Edge وتكبس «تركيب على سطح المكتب» فتظهر <strong>أيقونة باسمها</strong> على المكتب. الحساب قراءة فقط ولا يعدّل شيئاً.'
        : 'Chaque école a un <strong>identifiant = sa région</strong> et un mot de passe visible ici. Cochez ce qu\'elle peut voir, puis <strong>envoyez</strong>-lui le lien par e-mail ou copiez le message pour WhatsApp. L\'école ouvre le lien avec Chrome ou Edge et clique « Installer sur le bureau » : une <strong>icône à son nom</strong> apparaît sur le bureau. Compte en lecture seule.' ?>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-school-lock"></i> <span dir="ltr">Accès par école</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">دخول كل مدرسة</div></h3></div>
    <div class="card-body">
        <table class="table sa-table">
            <thead><tr>
                <th>École / المدرسة</th>
                <th>Identifiant / اسم الدخول</th>
                <th>Mot de passe / كلمة السرّ</th>
                <th>Ce qu'elle voit / ماذا ترى</th>
                <th>Actif / مفعّل</th>
                <th class="no-print">Actions / إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach ($schools as $s): $sid = (int)$s['id']; $u = saViewerForSchool($sid);
                $uname = $u ? (string)$u['username'] : saUniqueUsername($s, $takenNow);
                $pw = $u ? (string)($u['pw_known'] ?? '') : saStandardPassword($uname);
                $raw = $u ? ($u['allowed_pages'] ?? null) : implode(',', array_keys($pages));
                $checked = ($raw === null) ? array_keys($pages) : array_filter(array_map('trim', explode(',', (string)$raw)));
                $active = $u ? (int)$u['is_active'] : 1;
                $link = saInstallUrl($uname);
                $msg = $u ? saMessageText($s, $u, $pw) : '';
                $rowOff = !(int)$s['is_active'];
            ?>
                <tr id="s<?= $sid ?>" class="<?= $rowOff ? 'sa-off' : '' ?>">
                <form method="POST" action="<?= $self ?>" id="f<?= $sid ?>">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="act" value="save_row">
                    <input type="hidden" name="id" value="<?= $u ? (int)$u['id'] : 0 ?>">
                    <input type="hidden" name="school_id" value="<?= $sid ?>">
                    <td class="sa-school">
                        <b><?= e($s['name_fr'] ?: $s['name_ar']) ?></b>
                        <small><?= e($s['name_ar']) ?></small>
                        <small><i class="fas fa-location-dot"></i> <?= e($s['address_fr'] ?: $s['address']) ?><?= $rowOff ? ' · <span style="color:#b91c1c">inactive / غير فعّالة</span>' : '' ?></small>
                        <?php if ($u): ?><small class="sa-link"><?= e($link) ?></small><?php endif; ?>
                    </td>
                    <td><input type="text" name="username" class="form-control" value="<?= e($uname) ?>" autocomplete="off" dir="ltr"></td>
                    <td>
                        <div class="sa-pw">
                            <input type="text" name="password" class="form-control" value="<?= $u ? '' : e($pw) ?>" placeholder="<?= $u ? ($pw !== '' ? e($pw) : '••••••') : '' ?>" autocomplete="off" dir="ltr" title="<?= $u ? 'اتركها فارغة لعدم التغيير / Laisser vide pour ne pas changer' : '' ?>">
                        </div>
                        <?php if ($u && $pw === ''): ?><small class="text-muted">غير معروفة — اكتب جديدة / inconnue : saisissez-en une</small><?php endif; ?>
                    </td>
                    <td class="sa-pages">
                        <?php foreach ($pages as $pk => $pl): ?>
                            <label><input type="checkbox" name="allowed[]" value="<?= e($pk) ?>" <?= in_array($pk, $checked, true) ? 'checked' : '' ?>> <?= e($pl['fr']) ?> / <?= e($pl['ar']) ?></label>
                        <?php endforeach; ?>
                    </td>
                    <td><label class="form-check" style="margin:0"><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>> <?= $active ? 'Oui / نعم' : 'Non / لا' ?></label></td>
                    <td class="no-print">
                        <div class="sa-btns">
                            <button type="submit" class="btn btn-primary" title="Enregistrer / حفظ"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
                            <?php if ($u): ?>
                            <button type="button" class="btn btn-info" onclick="saCopy(this)" data-msg="<?= e($msg) ?>" title="Copier le message (WhatsApp) / نسخ الرسالة للواتساب"><i class="fab fa-whatsapp"></i> Copier / نسخ</button>
                            <a class="btn btn-secondary" href="<?= e($link) ?>" target="_blank" rel="noopener" title="Ouvrir le lien d'installation / فتح رابط التركيب"><i class="fas fa-up-right-from-square"></i> Lien / الرابط</a>
                            <?php endif; ?>
                        </div>
                </form>
                        <?php if ($u): ?>
                        <form method="POST" action="<?= $self ?>" style="margin-top:6px;display:flex;gap:4px;align-items:center" data-confirm="<?= e('Envoyer le lien, l\'identifiant et le mot de passe à cette école ? / إرسال الرابط واسم الدخول وكلمة السرّ لهذه المدرسة؟') ?>">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="act" value="send_mail">
                            <input type="hidden" name="school_id" value="<?= $sid ?>">
                            <input type="email" name="to" class="form-control" value="<?= e((string)($s['email'] ?? '')) ?>" placeholder="e-mail" dir="ltr" style="min-width:170px;font-size:13px;padding:5px 8px">
                            <button type="submit" class="btn btn-success" <?= $mailOk ? '' : 'disabled title="Messagerie non configurée / البريد غير مهيّأ"' ?>><i class="fas fa-paper-plane"></i> Envoyer / إرسال</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function saCopy(btn){
    var t = btn.getAttribute('data-msg') || '';
    var done = function(){ var o = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i> Copié / نُسخ'; setTimeout(function(){ btn.innerHTML = o; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function(){ saCopyFallback(t); done(); }); }
    else { saCopyFallback(t); done(); }
}
function saCopyFallback(t){ var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch(e){} document.body.removeChild(ta); }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
