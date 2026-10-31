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
require_once __DIR__ . '/../includes/key_access.php';   // 🔑 أيقونات الدخول المباشر (كمبيوتر/موبايل) + الرئاسة العامة (2026-10-31)
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

    // ===== 🔑 أيقونات الدخول المباشر (2026-10-31 «أيقونة كوبي باست وبس») =====
    if ($act === 'key_new') {
        $uid = (int)($_POST['user_id'] ?? 0); $kind = (string)($_POST['kind'] ?? 'exe'); $label = trim((string)($_POST['label'] ?? ''));
        $back = (string)($_POST['back'] ?? '');
        try {
            $vu = kaViewer($uid);
            if ($label === '') $label = ($kind === 'link' ? 'Téléphone — ' : 'Ordinateur — ') . kaLabelFor($vu);
            if ($kind === 'link') { [$kid, $link] = kaCreateLink($uid, $label, (string)($_SESSION['username'] ?? '')); $_SESSION['ka_new_link'] = ['uid' => $uid, 'url' => $link, 'label' => kaLabelFor($vu)]; }
            else {
                [$kid, $bytes] = kaCreateExe($uid, $label, (string)($_SESSION['username'] ?? ''));
                [$pkg, $pkgName, $mime] = kaPackage($bytes, kaFileName($vu));
                $_SESSION['ka_new_file'] = ['uid' => $uid, 't' => kaStoreDownload($pkg, $pkgName, $mime), 'name' => $pkgName, 'label' => kaLabelFor($vu)];   // 📦 يُنزَّل بالصفحة عبر key_dl.php
            }
        } catch (Throwable $e) { $_SESSION['flash_error'] = $e->getMessage(); }
        header('Location: ' . $self . $back); exit;
    }
    if ($act === 'key_revoke' || $act === 'key_unbind') {
        $kid = (int)($_POST['key_id'] ?? 0); $back = (string)($_POST['back'] ?? '');
        if ($kid > 0) { $act === 'key_revoke' ? kaRevoke($kid) : kaUnbind($kid); $_SESSION['flash_success'] = $act === 'key_revoke' ? 'Accès annulé — il n\'ouvre plus rien / أُلغي هذا الدخول — ما عاد يفتح شيئاً' : 'Appareil délié — l\'icône se liera au prochain appareil qui l\'utilise / فُكّ الربط — الأيقونة ترتبط بأوّل جهاز يستعملها'; }
        header('Location: ' . $self . $back); exit;
    }
    // ===== 🏛️ الرئاسة العامة: ما تراه (كل المدارس أو ✓ مختارة) + الصفحات + كلمة السرّ — حساب viewer متعدّد المدارس (نفس آلية users.php) =====
    if ($act === 'save_hq') {
        $uid = (int)($_POST['id'] ?? 0);
        $username = strtolower(trim((string)($_POST['username'] ?? 'presidence'))); $username = preg_replace('/[^a-z0-9._-]/', '', $username) ?: 'presidence';
        $password = (string)($_POST['password'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $selP = $_POST['allowed'] ?? []; if (!is_array($selP)) $selP = [];
        $allowedPages = implode(',', array_values(array_intersect($selP, array_keys($pages))));
        $validIds = array_map(fn($x) => (int)$x['id'], allSchools(false));
        $selS = $_POST['schools'] ?? []; if (!is_array($selS)) $selS = [];
        $selS = array_values(array_unique(array_filter(array_map('intval', $selS), fn($x) => $x > 0 && in_array($x, $validIds, true))));
        $allowedSchools = !empty($_POST['all_schools']) ? 'all' : implode(',', $selS);
        $err = '';
        if (strlen($username) < 3) $err = 'Identifiant : 3 caractères minimum / اسم الدخول 3 أحرف على الأقل';
        elseif ($password !== '' && strlen($password) < 6) $err = 'Mot de passe : 6 caractères minimum / كلمة السرّ 6 أحرف على الأقل';
        elseif ($allowedSchools === '') $err = 'Choisissez « Toutes les écoles » ou au moins une école / اختر «كل المدارس» أو مدرسة واحدة على الأقل';
        else { $taken = saTakenMap($db); if (isset($taken[$username]) && $taken[$username] !== $uid) $err = 'Identifiant déjà utilisé / اسم الدخول مستخدم لحساب آخر'; }
        if ($err) { $_SESSION['flash_error'] = $err; header('Location: ' . $self . '#hq'); exit; }
        if ($uid > 0) {
            $cur = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'viewer'"); $cur->execute([$uid]); $cur = $cur->fetch();
            if (!$cur) { $_SESSION['flash_error'] = 'Compte introuvable / الحساب غير موجود'; header('Location: ' . $self . '#hq'); exit; }
            if ($password !== '') $db->prepare("UPDATE users SET username=?, password_hash=?, pw_known=?, is_active=?, allowed_pages=?, allowed_schools=?, school_id=NULL WHERE id=?")->execute([$username, password_hash($password, PASSWORD_DEFAULT), $password, $active, $allowedPages, $allowedSchools, $uid]);
            else $db->prepare("UPDATE users SET username=?, is_active=?, allowed_pages=?, allowed_schools=?, school_id=NULL WHERE id=?")->execute([$username, $active, $allowedPages, $allowedSchools, $uid]);
            if (function_exists('logAudit')) logAudit('update', 'users', $uid, null, $username . ' hq:' . $allowedSchools);
        } else {
            if ($password === '') $password = saStandardPassword($username);
            $db->prepare("INSERT INTO users (username,password_hash,full_name,role,school_id,is_active,allowed_pages,allowed_schools,pw_known) VALUES (?,?,?,?,NULL,?,?,?,?)")
               ->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'Présidence Générale / الرئاسة العامة', 'viewer', $active, $allowedPages, $allowedSchools, $password]);
            if (function_exists('logAudit')) logAudit('create', 'users', (int)$db->lastInsertId(), null, $username . ' hq:' . $allowedSchools);
        }
        $_SESSION['flash_success'] = 'Présidence enregistrée / حُفظت إعدادات الرئاسة العامة';
        header('Location: ' . $self . '#hq'); exit;
    }

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
// 🔑 المفاتيح لكل حساب + الرابط الجديد (يظهر مرّة واحدة) + حساب الرئاسة (viewer بعدّة مدارس أو all)
$keysBy = kaAllByUser();
$newLink = $_SESSION['ka_new_link'] ?? null; unset($_SESSION['ka_new_link']);
$newFile = $_SESSION['ka_new_file'] ?? null; unset($_SESSION['ka_new_file']);
$hq = null;
foreach ($db->query("SELECT * FROM users WHERE role = 'viewer' ORDER BY (username = 'presidence') DESC, id")->fetchAll() as $vu) {
    $as = (string)($vu['allowed_schools'] ?? '');
    if ($as === 'all' || strpos($as, ',') !== false) { $hq = $vu; break; }
}
$hqSchools = $hq ? (string)($hq['allowed_schools'] ?? '') : 'all';
$hqSel = ($hqSchools === 'all' || $hqSchools === '') ? [] : array_map('intval', explode(',', $hqSchools));
$hqPagesRaw = $hq ? ($hq['allowed_pages'] ?? null) : null;
$hqPages = $hqPagesRaw === null ? array_keys($pages) : array_filter(array_map('trim', explode(',', (string)$hqPagesRaw)));
/** 🔑 كتلة الأيقونات لحساب: اللائحة + كبستا الإنشاء */
function saKeysBlock(?array $u, array $keysBy, ?array $newLink, string $self, string $anchor, ?array $newFile = null): string {
    if (!$u) return '<small class="text-muted">Enregistrez d\'abord le compte / احفظ الحساب أوّلاً</small>';
    $uid = (int)$u['id']; $h = '<div class="ka-box">';
    $h .= '<div class="ka-title"><i class="fas fa-key"></i> Icône d\'accès direct <span dir="rtl">· أيقونة الدخول المباشر</span></div>';
    if ($newFile && (int)$newFile['uid'] === $uid) {
        $dl = BASE_URL . 'key_dl.php?t=' . rawurlencode((string)$newFile['t']);
        $h .= '<div class="ka-new" id="kaNewFile"><b>Fichier prêt — il se télécharge maintenant (dossier Téléchargements) ; envoyez-le par WhatsApp à l\'école / الملف جاهز — عم ينزل هلّق (مجلّد التنزيلات)؛ ابعته بالواتساب للمدرسة:</b>'
            . '<div class="ka-link-row"><a id="kaDl" class="btn btn-success" href="' . e($dl) . '" download="' . e((string)$newFile['name']) . '"><i class="fas fa-download"></i> ' . e((string)$newFile['name']) . ' — Télécharger / نزّل</a></div>'
            . '<small class="text-muted">Si rien ne se passe, cliquez le bouton vert. Ne l\'ouvrez pas vous-même. / إذا ما نزل شي اكبس الزرّ الأخضر. لا تفتحه أنت.</small></div>'
            . '<script>setTimeout(function(){var a=document.getElementById("kaDl");if(a){a.scrollIntoView({block:"center"});a.click();}},400);</script>';
    }
    if ($newLink && (int)$newLink['uid'] === $uid) $h .= '<div class="ka-new"><b>Lien pour le téléphone — à envoyer par WhatsApp (affiché une seule fois) / رابط الموبايل — ابعته بواتساب (يظهر مرّة واحدة):</b><div class="ka-link-row"><input class="form-control" value="' . e($newLink['url']) . '" readonly dir="ltr" onclick="this.select()"><button type="button" class="btn btn-info" onclick="saCopyText(this,this.previousElementSibling.value)"><i class="fas fa-copy"></i> Copier / نسخ</button></div></div>';
    foreach ($keysBy[$uid] ?? [] as $k) {
        $isLink = $k['kind'] === 'link';
        if ($k['revoked']) $st = '<span class="ka-badge ka-off">Annulé · ملغى</span>';
        elseif ($isLink) { if ($k['devices']) { $st = '<span class="ka-badge ka-on">Installé · مركَّب</span>'; foreach ($k['devices'] as $d) $st .= ' <small>' . e(kaUaShort((string)$d['label'])) . ' ' . e(date('d/m/Y', strtotime($d['created_at']))) . '</small>'; } else $st = '<span class="ka-badge ka-wait">Lien pas encore ouvert · لم يُفتح بعد</span>'; }
        else $st = $k['machine_hash'] ? '<span class="ka-badge ka-on">Liée · مربوطة</span> <small>' . e($k['machine_label']) . ' ' . e(date('d/m/Y', strtotime($k['bound_at']))) . '</small>' : '<span class="ka-badge ka-wait">Pas encore utilisée · لم تُستعمل بعد</span>';
        $h .= '<div class="ka-row' . ($k['revoked'] ? ' ka-dim' : '') . '"><i class="fas ' . ($isLink ? 'fa-mobile-screen' : 'fa-desktop') . '"></i> <span class="ka-lbl">' . e($k['label']) . '</span> ' . $st
            . ($k['last_used_at'] ? ' <small class="text-muted">dernier / آخر: ' . e(date('d/m/Y H:i', strtotime($k['last_used_at']))) . ' · ' . (int)$k['uses'] . '×</small>' : '')
            . ((int)$k['fails'] ? ' <small class="text-muted">' . (int)$k['fails'] . ' refus</small>' : '');
        if (!$k['revoked']) {
            if ($k['machine_hash'] || $k['devices']) $h .= ' <form method="POST" action="' . e($self) . '" class="ka-inline"><input type="hidden" name="csrf" value="' . csrfToken() . '"><input type="hidden" name="act" value="key_unbind"><input type="hidden" name="key_id" value="' . (int)$k['id'] . '"><input type="hidden" name="back" value="' . e($anchor) . '"><button type="submit" class="btn btn-secondary btn-xs" title="Délier l\'appareil · فكّ الربط"><i class="fas fa-rotate-left"></i> Délier / فكّ الربط</button></form>';
            $h .= ' <form method="POST" action="' . e($self) . '" class="ka-inline" data-confirm="' . e('Annuler cet accès ? Il n\'ouvrira plus rien. / إلغاء هذا الدخول؟ ما عاد يفتح شيئاً.') . '"><input type="hidden" name="csrf" value="' . csrfToken() . '"><input type="hidden" name="act" value="key_revoke"><input type="hidden" name="key_id" value="' . (int)$k['id'] . '"><input type="hidden" name="back" value="' . e($anchor) . '"><button type="submit" class="btn btn-dark btn-xs"><i class="fas fa-ban"></i> Annuler / إلغاء</button></form>';
        }
        $h .= '</div>';
    }
    $h .= '<div class="ka-actions">'
        . '<a class="btn btn-warning btn-xs" href="' . e(BASE_URL . 'key_preview.php?u=' . $uid . '&csrf=' . csrfToken()) . '" title="Voir le programme exactement comme ce compte le voit (lecture seule), puis « Retour administrateur » · شوف البرنامج تماماً كما يراه هذا الحساب ثمّ «رجوع للمدير»"><i class="fas fa-eye"></i> Aperçu — voir comme l\'école / معاينة — شوف متل ما بتشوف</a> '
        . '<form method="POST" action="' . e($self) . '" class="ka-inline"><input type="hidden" name="csrf" value="' . csrfToken() . '"><input type="hidden" name="act" value="key_new"><input type="hidden" name="kind" value="exe"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="back" value="' . e($anchor) . '"><button type="submit" class="btn btn-primary btn-xs" title="Fichier à copier sur le bureau : un double-clic ouvre le programme sans mot de passe · ملف يُنسخ على سطح المكتب: كبستان تفتحان البرنامج بلا كلمة سرّ"><i class="fas fa-download"></i> Icône ordinateur / أيقونة الكمبيوتر</button></form> '
        . '<form method="POST" action="' . e($self) . '" class="ka-inline"><input type="hidden" name="csrf" value="' . csrfToken() . '"><input type="hidden" name="act" value="key_new"><input type="hidden" name="kind" value="link"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="back" value="' . e($anchor) . '"><button type="submit" class="btn btn-info btn-xs" title="Lien à ouvrir une fois sur le téléphone · رابط يُفتح مرّة على الموبايل"><i class="fas fa-mobile-screen"></i> Icône téléphone / أيقونة الموبايل</button></form>'
        . '</div></div>';
    return $h;
}
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
/* 🔑 أيقونات الدخول المباشر */
.ka-box{margin-top:8px;padding:8px 10px;border:1px dashed #c9a961;border-radius:10px;background:#fffdf7;font-size:13px;max-width:560px}
.ka-title{font-weight:700;color:#0a2240;margin-bottom:4px}
.ka-row{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:4px 0;border-top:1px solid #f1e7cf}.ka-row:first-of-type{border-top:0}
.ka-lbl{font-weight:600}.ka-dim{opacity:.55}
.ka-badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600;color:#fff}.ka-on{background:#2a9d4a}.ka-wait{background:#8e8e93}.ka-off{background:#2c2c2e}
.ka-inline{display:inline}.btn-xs{padding:3px 8px;font-size:12px}
.ka-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.ka-new{background:#e8f5e9;border:1px solid #2a9d4a;border-radius:8px;padding:8px;margin:6px 0}
.ka-link-row{display:flex;gap:6px;margin-top:6px}.ka-link-row input{font-family:Consolas,monospace;font-size:12px}
.hq-schools{display:flex;flex-wrap:wrap;gap:4px 14px;margin:6px 0}.hq-schools label{display:inline-flex;align-items:center;gap:5px;font-size:13px;white-space:nowrap}
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
                        <?= saKeysBlock($u, $keysBy, $newLink, $self, '#s' . $sid, $newFile) ?>
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

<!-- 🏛️ الرئاسة العامة (2026-10-31): حساب قراءة فقط يرى كل المدارس أو المختارة (سان مكسيم من بينها) والصفحات التي يسمح بها المدير + أيقونة -->
<div class="card" id="hq">
    <div class="card-header"><h3><i class="fas fa-landmark"></i> <span dir="ltr">Présidence générale — ce qu'elle voit</span><div style="font-size:0.85em;font-weight:600;opacity:0.9">الرئاسة العامة — شو بتشوف</div></h3></div>
    <div class="card-body">
        <form method="POST" action="<?= $self ?>">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="act" value="save_hq">
            <input type="hidden" name="id" value="<?= $hq ? (int)$hq['id'] : 0 ?>">
            <div class="hq-form-row" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start">
                <div><label class="form-label">Identifiant / اسم الدخول</label><input type="text" name="username" class="form-control" value="<?= e($hq ? $hq['username'] : 'presidence') ?>" dir="ltr" autocomplete="off"></div>
                <div><label class="form-label">Mot de passe / كلمة السرّ</label><input type="text" name="password" class="form-control" value="" placeholder="<?= e($hq ? (($hq['pw_known'] ?? '') !== '' ? $hq['pw_known'] : '••••••') : saStandardPassword('presidence')) ?>" dir="ltr" autocomplete="off" title="Laisser vide pour ne pas changer / اتركها فارغة لعدم التغيير"></div>
                <div><label class="form-label">Actif / مفعّل</label><label class="form-check" style="margin:6px 0 0"><input type="checkbox" name="is_active" value="1" <?= (!$hq || (int)$hq['is_active']) ? 'checked' : '' ?>> Oui / نعم</label></div>
            </div>
            <div style="margin-top:10px"><b>Écoles visibles / المدارس اللي بتشوفها:</b>
                <label style="margin-inline-start:12px"><input type="checkbox" name="all_schools" value="1" id="hqAll" <?= empty($hqSel) ? 'checked' : '' ?> onchange="document.querySelectorAll('#hq .hq-schools input').forEach(function(c){c.disabled=document.getElementById('hqAll').checked})"> <b>Toutes les écoles (y compris les futures) / كل المدارس (ومن ينضمّ لاحقاً)</b></label>
                <div class="hq-schools">
                <?php foreach ($schools as $s): if (!(int)$s['is_active']) continue; ?>
                    <label><input type="checkbox" name="schools[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $hqSel, true) ? 'checked' : '' ?> <?= empty($hqSel) ? 'disabled' : '' ?>> <?= e($s['name_fr'] ?: $s['name_ar']) ?></label>
                <?php endforeach; ?>
                </div>
            </div>
            <div style="margin-top:6px" class="sa-pages"><b>Pages / الصفحات:</b>
                <?php foreach ($pages as $pk => $pl): ?>
                    <label><input type="checkbox" name="allowed[]" value="<?= e($pk) ?>" <?= in_array($pk, $hqPages, true) ? 'checked' : '' ?>> <?= e($pl['fr']) ?> / <?= e($pl['ar']) ?></label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:10px"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer / حفظ</button> <small class="text-muted">Lecture seule : la présidence ne modifie rien / عرض فقط: الرئاسة ما بتعدّل شي</small></div>
        </form>
        <?= saKeysBlock($hq, $keysBy, $newLink, $self, '#hq', $newFile) ?>
    </div>
</div>

<div class="alert alert-info no-print" style="margin-top:10px">
    <i class="fas fa-key"></i>
    <?= $lang === 'ar'
        ? '<strong>أيقونة الكمبيوتر:</strong> ملف تبعته بالواتساب، المدرسة تنسخه على سطح المكتب وتكبسه فيفتح البرنامج بلا يوزر ولا باسوورد (يرتبط بأوّل كمبيوتر يستعمله). <strong>أيقونة الموبايل:</strong> رابط يُفتح مرّة على الهاتف ثمّ «إضافة إلى الشاشة الرئيسية». كلّ أيقونة تفتح حساب المدرسة نفسه فترى ما ضبطتَه فوق. <strong>لا تفتح أنت</strong> ملفاً أو رابطاً موجَّهاً لغيرك (يرتبط بجهازك — وإلّا «فكّ الربط»).'
        : '<strong>Icône ordinateur :</strong> un fichier à envoyer par WhatsApp ; l\'école le copie sur le bureau et double-clique : le programme s\'ouvre sans identifiant ni mot de passe (lié au premier ordinateur qui l\'utilise). <strong>Icône téléphone :</strong> un lien à ouvrir une fois, puis « Ajouter à l\'écran d\'accueil ». Chaque icône ouvre le compte de l\'école : elle voit exactement ce que vous avez coché. <strong>N\'ouvrez pas vous-même</strong> un fichier ou un lien destiné à quelqu\'un d\'autre (il se lierait à votre appareil — sinon « Délier »).' ?>
</div>

<script>
function saCopyText(btn, t){ var done = function(){ var o = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i> Copié / نُسخ'; setTimeout(function(){ btn.innerHTML = o; }, 1800); }; if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, function(){ saCopyFallback(t); done(); }); else { saCopyFallback(t); done(); } }
function saCopy(btn){
    var t = btn.getAttribute('data-msg') || '';
    var done = function(){ var o = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i> Copié / نُسخ'; setTimeout(function(){ btn.innerHTML = o; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function(){ saCopyFallback(t); done(); }); }
    else { saCopyFallback(t); done(); }
}
function saCopyFallback(t){ var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch(e){} document.body.removeChild(ta); }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
