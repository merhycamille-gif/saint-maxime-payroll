<?php
/**
 * 🔑 أيقونات الدخول المباشر — Accès direct par icône (2026-10-31)
 *  طلبه: «بدي لكل مدرسة أيقونة تنزّلها على الكمبيوتر عندها، كوبي باست وبس، بتشوف مدرستها واللي أنا بسمح؛ والرئاسة العامة
 *  بتشوف الجميع أو اللي أنا بدّي… وأوعى تلعب بالبرنامج».
 *  طبقة مستقلّة فوق حسابات «قراءة فقط» (users.role=viewer) — لا تمسّ محرّك الصلاحيات (enforceViewerRestrictions) ولا أي حساب:
 *  الأيقونة تفتح جلسة الحساب نفسه، فترى تماماً ما ضبطه المدير بصفحة «دخول المدارس» (المدارس المسموحة + الصفحات ✓).
 *  نوعان (app_keys.kind):
 *   • exe  — كمبيوتر ويندوز: ملف tools/desktop_icon/MSA Payroll.exe يحمل بذيله «MSAKEY1|selector|secret|base|key_login.php|».
 *            عند الكبس يرسلهما مع بصمة الجهاز (MachineGuid مهشَّر) إلى key_login.php ⇒ يرتبط بأوّل جهاز، ويأخذ رابط دخول لمرّة
 *            واحدة (90 ث) ⇒ key_go.php يفتح الجلسة. نسخة على جهاز آخر تُرفض.
 *   • link — موبايل/تابلت: key_link.php?k=…&t=… يُفتح مرّة ⇒ كوكي سنة (key_devices، حتى 3 متصفّحات) ⇒ login.php يدخل تلقائياً.
 *  🔒 لحسابات viewer فقط؛ السيرفر يخزّن الهاشات فقط؛ كل شيء بسجلّ التدقيق (logAudit)؛ المدير ينشئ/يلغي/يفكّ الربط من «دخول المدارس».
 *  الجداول تتركّب ذاتياً (قاعدة «DB Self-Install»).
 */

const KA_COOKIE = 'msapay_key';
const KA_COOKIE_DAYS = 365;
const KA_LINK_MAX_DEVICES = 3;
const KA_NONCE_SECONDS = 90;

function kaSchema(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS app_keys (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          kind ENUM('exe','link') NOT NULL DEFAULT 'exe',
          selector CHAR(16) NOT NULL UNIQUE,
          secret_hash CHAR(64) NOT NULL,
          label VARCHAR(160) NOT NULL DEFAULT '',
          machine_hash CHAR(64) NULL,
          machine_label VARCHAR(160) NOT NULL DEFAULT '',
          bound_at DATETIME NULL,
          nonce_hash CHAR(64) NULL,
          nonce_exp DATETIME NULL,
          last_used_at DATETIME NULL,
          last_ip VARCHAR(64) NOT NULL DEFAULT '',
          uses INT NOT NULL DEFAULT 0,
          fails INT NOT NULL DEFAULT 0,
          revoked TINYINT NOT NULL DEFAULT 0,
          created_by VARCHAR(60) NOT NULL DEFAULT '',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS key_devices (
          id INT AUTO_INCREMENT PRIMARY KEY,
          key_id INT NOT NULL,
          selector CHAR(24) NOT NULL UNIQUE,
          validator_hash CHAR(64) NOT NULL,
          label VARCHAR(160) NOT NULL DEFAULT '',
          last_used_at DATETIME NULL,
          last_ip VARCHAR(64) NOT NULL DEFAULT '',
          uses INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX (key_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
}

/** قاعدة الروابط العامّة (الأونلاين دائماً — نفس saPublicBase) */
function kaBase(): string { return function_exists('saPublicBase') ? saPublicBase() : 'https://msapayroll.com/'; }
/** قاعدة الطلب الحالي (نفس العنوان الذي كلّمته الأيقونة: أونلاين أو نسخة الكمبيوتر/السيرفر المحلّي) — لرابط الدخول لمرّة واحدة */
function kaRequestBase(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/[^A-Za-z0-9.:\-\[\]]/', $host)) return kaBase();
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . $host . BASE_URL;
}
function kaTemplatePath(int $schoolId = 0): string {
    // 🏫 (2026-10-31 «مكسيموس ما إلو علاقة بهالشعار»): المدارس المستقلّة عن الرئاسة (القديس مكسيموس) أيقونتها بشعارها هي لا شعار MSA
    if ($schoolId > 0 && function_exists('hqExcludedSchoolIds') && in_array($schoolId, hqExcludedSchoolIds(), true)) {
        $own = dirname(__DIR__) . '/tools/desktop_icon/MSA Payroll Maximos.exe';
        if (is_file($own) && filesize($own) > 10000) return $own;
    }
    return dirname(__DIR__) . '/tools/desktop_icon/MSA Payroll.exe';
}
/** مدرسة حساب القراءة فقط المفرد (school_id أو allowed_schools برقم واحد)، 0 للرئاسة/المتعدّد */
function kaUserSchoolId(array $u): int {
    $sid = (int)($u['school_id'] ?? 0); $as = trim((string)($u['allowed_schools'] ?? ''));
    if ($sid <= 0 && $as !== '' && $as !== 'all' && strpos($as, ',') === false) $sid = (int)$as;
    return max(0, $sid);
}
function kaIp(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function kaAudit(string $action, int $uid, string $detail): void { try { if (function_exists('logAudit')) logAudit($action, 'app_keys', $uid, null, mb_substr($detail, 0, 250)); } catch (Throwable $e) {} }

/** حساب «قراءة فقط» فعّال فقط */
function kaViewer(int $userId): array {
    $st = getDB()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1"); $st->execute([$userId]); $u = $st->fetch();
    if (!$u) throw new RuntimeException('Compte introuvable ou inactif / الحساب غير موجود أو غير فعّال');
    if (($u['role'] ?? '') !== 'viewer') throw new RuntimeException('Accès direct : comptes « lecture seule » (écoles / présidence) seulement / الدخول المباشر لحسابات القراءة فقط');
    return $u;
}

/** التسمية القصيرة للحساب (اسم المنطقة بالكبيرة أو PRESIDENCE) لاسم الملف وبطاقة المعاينة */
function kaLabelFor(array $u): string {
    $sid = (int)($u['school_id'] ?? 0); $as = (string)($u['allowed_schools'] ?? '');
    if ($sid <= 0 && $as !== '' && $as !== 'all' && strpos($as, ',') === false) $sid = (int)$as;
    if ($sid > 0 && function_exists('saShortName')) { foreach (allSchools(false) as $s) if ((int)$s['id'] === $sid) return saShortName($s); }
    if ($as === 'all' || strpos($as, ',') !== false) return 'PRESIDENCE';
    return strtoupper((string)$u['username']);
}
function kaFileName(array $u): string { return 'MSA Payroll - ' . kaLabelFor($u) . '.exe'; }

/** 🖥️ مفتاح كمبيوتر: [id, exe_bytes] — السرّ لا يُحفظ إلّا داخل الملف المنزَّل */
function kaCreateExe(int $userId, string $label, string $by): array {
    kaSchema(); $u = kaViewer($userId);
    $tpl = kaTemplatePath(kaUserSchoolId($u));
    if (!is_file($tpl) || filesize($tpl) < 10000) throw new RuntimeException('Modèle d\'icône manquant sur le serveur / قالب الأيقونة غير موجود: tools/desktop_icon/MSA Payroll.exe');
    $sel = bin2hex(random_bytes(8)); $sec = bin2hex(random_bytes(32));
    getDB()->prepare("INSERT INTO app_keys(user_id, kind, selector, secret_hash, label, created_by) VALUES(?,'exe',?,?,?,?)")
           ->execute([$userId, $sel, hash('sha256', $sec), mb_substr(trim($label), 0, 160), mb_substr($by, 0, 60)]);
    $id = (int)getDB()->lastInsertId();
    kaAudit('key_new_exe', $userId, '#' . $id . ' ' . $label);
    return [$id, file_get_contents($tpl) . "\n\0MSAKEY1|$sel|$sec|" . kaBase() . "|key_login.php|\n"];
}

/** 📱 رابط موبايل: [id, url] — بلا انتهاء؛ الحماية = حدّ الأجهزة + الإلغاء */
function kaCreateLink(int $userId, string $label, string $by): array {
    kaSchema(); kaViewer($userId);
    $sel = bin2hex(random_bytes(8)); $tok = bin2hex(random_bytes(24));
    getDB()->prepare("INSERT INTO app_keys(user_id, kind, selector, secret_hash, label, created_by) VALUES(?,'link',?,?,?,?)")
           ->execute([$userId, $sel, hash('sha256', $tok), mb_substr(trim($label), 0, 160), mb_substr($by, 0, 60)]);
    $id = (int)getDB()->lastInsertId();
    kaAudit('key_new_link', $userId, '#' . $id . ' ' . $label);
    return [$id, kaBase() . 'key_link.php?k=' . $sel . '&t=' . $tok];
}

/* ───── 📦 تسليم ملف الأيقونة عبر رابط تنزيل (لا عبر ردّ النموذج): نافذة التطبيق المركَّب (PWA) كانت تعرض الملف الثنائي كصفحة خربانة
 *        بدل تنزيله ⇒ الملف يُحفَظ مؤقّتاً بـtmp/keys ويُنزَّل بـ<a download href="key_dl.php?t=…"> (15 دقيقة، مرّة واحدة، للمدير فقط) ───── */
function kaTmpDir(): string { $d = dirname(__DIR__) . '/tmp/keys'; if (!is_dir($d)) @mkdir($d, 0770, true); return $d; }
/** ZIP يحوي الـexe (أسهل بالواتساب وبلا تحذير «ملف خطر» بالمتصفّح)؛ بلا ZipArchive ⇒ الـexe نفسه */
function kaPackage(string $exeBytes, string $exeName): array {
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'kaz'); $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) === true) {
            $z->addFromString($exeName, $exeBytes);
            $z->addFromString('LISEZ-MOI.txt', "MSA Payroll — Icône d'accès direct / أيقونة الدخول المباشر\r\n\r\n1) Copiez le fichier « $exeName » sur le Bureau.\r\n2) Double-cliquez : le programme s'ouvre directement (sans identifiant ni mot de passe).\r\nSi Windows affiche un écran bleu la première fois : « Informations complémentaires » puis « Exécuter quand même ».\r\n\r\n1) انسخوا الملف «$exeName» على سطح المكتب.\r\n2) اكبسوا عليه مرّتين: يفتح البرنامج مباشرة (بلا اسم مستخدم ولا كلمة سرّ).\r\nإذا ظهرت شاشة زرقاء أوّل مرّة: «More info» ثمّ «Run anyway».\r\n");
            $z->close(); $zip = (string)file_get_contents($tmp); @unlink($tmp);
            if ($zip !== '') return [$zip, preg_replace('/\.exe$/i', '.zip', $exeName), 'application/zip'];
        }
        @unlink($tmp);
    }
    return [$exeBytes, $exeName, 'application/octet-stream'];
}
/** يحفظ الملف مؤقّتاً ويرجع رمز التنزيل */
function kaStoreDownload(string $bytes, string $fileName, string $mime): string {
    $d = kaTmpDir();
    foreach (glob($d . '/*.bin') ?: [] as $old) if (filemtime($old) < time() - 3600) { @unlink($old); @unlink(substr($old, 0, -4) . '.json'); }
    $t = bin2hex(random_bytes(16));
    file_put_contents($d . '/' . $t . '.bin', $bytes);
    file_put_contents($d . '/' . $t . '.json', json_encode(['name' => $fileName, 'mime' => $mime, 'uid' => (int)($_SESSION['user_id'] ?? 0), 'at' => time()]));
    return $t;
}
/** يرسل الملف المؤقّت (للمدير صاحب الجلسة نفسها، خلال 15 دقيقة) ويحذفه؛ false إن لم يوجد */
function kaSendStored(string $t): bool {
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) return false;
    $d = kaTmpDir(); $bin = $d . '/' . $t . '.bin'; $meta = $d . '/' . $t . '.json';
    if (!is_file($bin) || !is_file($meta)) return false;
    $m = json_decode((string)file_get_contents($meta), true) ?: [];
    if ((int)($m['uid'] ?? -1) !== (int)($_SESSION['user_id'] ?? 0) || time() - (int)($m['at'] ?? 0) > 900) { @unlink($bin); @unlink($meta); return false; }
    $bytes = (string)file_get_contents($bin); @unlink($bin); @unlink($meta);
    while (ob_get_level()) ob_end_clean();
    $fn = (string)($m['name'] ?? 'MSA-Payroll.zip');
    header('Content-Type: ' . ($m['mime'] ?? 'application/octet-stream')); header('Content-Length: ' . strlen($bytes)); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $fn) . '"; filename*=UTF-8\'\'' . rawurlencode($fn));
    echo $bytes; return true;
}

function kaSendExe(string $bytes, string $fileName): void {
    header('Content-Type: application/octet-stream'); header('Content-Length: ' . strlen($bytes)); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9 ._-]/', '', $fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    echo $bytes; exit;
}

/** فتح جلسة الحساب — نفس ما تفعله login.php حرفياً (بلا مسّها) */
function kaOpenSession(array $user, int $keyId, string $how): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['school_id'] = $user['school_id'];
    $_SESSION['active_school_id'] = ($user['role'] === 'superadmin') ? 0 : (int)$user['school_id'];
    $_SESSION['lang'] = 'fr';
    $_SESSION['via_key'] = $keyId;
    try { getDB()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]); } catch (Throwable $e) {}
    kaAudit('key_login_' . $how, (int)$user['id'], '#' . $keyId . ' ' . kaIp());
}

/** 🖥️ تحقّق الـexe: [ok, msgFr, msgAr | url] */
function kaVerifyExe(string $sel, string $sec, string $mach, string $mlabel): array {
    kaSchema();
    if (!preg_match('/^[a-f0-9]{16}$/', $sel) || !preg_match('/^[a-f0-9]{64}$/', $sec) || !preg_match('/^[a-f0-9]{64}$/', $mach)) { usleep(300000); return [false, 'Clé invalide', 'المفتاح غير صالح']; }
    $st = getDB()->prepare("SELECT k.*, u.username, u.is_active, u.role FROM app_keys k JOIN users u ON u.id = k.user_id WHERE k.selector = ? AND k.kind = 'exe'"); $st->execute([$sel]); $k = $st->fetch();
    if (!$k || !hash_equals((string)$k['secret_hash'], hash('sha256', $sec))) {
        if ($k) getDB()->prepare("UPDATE app_keys SET fails = fails + 1 WHERE id = ?")->execute([(int)$k['id']]);
        kaAudit('key_fail', $k ? (int)$k['user_id'] : 0, 'secret ' . kaIp()); usleep(500000);
        return [false, "Cette icône n'est plus valable — demandez une nouvelle icône à l'administration.", 'هذه الأيقونة لم تعد صالحة — اطلبوا أيقونة جديدة من الإدارة.'];
    }
    if ($k['revoked'] || !$k['is_active'] || $k['role'] !== 'viewer') { kaAudit('key_revoked', (int)$k['user_id'], '#' . $k['id'] . ' ' . kaIp()); return [false, "Cette icône a été annulée — demandez une nouvelle icône à l'administration.", 'أُلغيت هذه الأيقونة — اطلبوا أيقونة جديدة من الإدارة.']; }
    if ($k['machine_hash'] === null) { getDB()->prepare("UPDATE app_keys SET machine_hash = ?, machine_label = ?, bound_at = NOW() WHERE id = ?")->execute([$mach, mb_substr($mlabel, 0, 160), (int)$k['id']]); kaAudit('key_bind', (int)$k['user_id'], '#' . $k['id'] . ' ' . $mlabel); }
    elseif (!hash_equals((string)$k['machine_hash'], $mach)) { getDB()->prepare("UPDATE app_keys SET fails = fails + 1 WHERE id = ?")->execute([(int)$k['id']]); kaAudit('key_machine', (int)$k['user_id'], '#' . $k['id'] . ' ' . $mlabel); return [false, "Cette icône est liée à un autre ordinateur (" . $k['machine_label'] . "). L'administration peut la délier depuis « Accès écoles ».", 'هذه الأيقونة مربوطة بكمبيوتر آخر. الإدارة تقدر تفكّ الربط من «دخول المدارس».']; }
    $n = bin2hex(random_bytes(32));
    getDB()->prepare("UPDATE app_keys SET nonce_hash = ?, nonce_exp = DATE_ADD(NOW(), INTERVAL " . KA_NONCE_SECONDS . " SECOND), fails = 0 WHERE id = ?")->execute([hash('sha256', $n), (int)$k['id']]);
    return [true, kaRequestBase() . 'key_go.php?k=' . $sel . '&n=' . $n, ''];
}

/** 🖥️ استهلاك رابط الدخول لمرّة واحدة ⇒ صفّ المستخدم أو null */
function kaConsumeNonce(string $sel, string $n): ?array {
    kaSchema();
    if (!preg_match('/^[a-f0-9]{16}$/', $sel) || !preg_match('/^[a-f0-9]{64}$/', $n)) return null;
    $st = getDB()->prepare("SELECT * FROM app_keys WHERE selector = ? AND kind = 'exe' AND revoked = 0 AND nonce_hash IS NOT NULL AND nonce_exp > NOW()"); $st->execute([$sel]); $k = $st->fetch();
    if (!$k || !hash_equals((string)$k['nonce_hash'], hash('sha256', $n))) { kaAudit('key_go_fail', $k ? (int)$k['user_id'] : 0, kaIp()); return null; }
    getDB()->prepare("UPDATE app_keys SET nonce_hash = NULL, nonce_exp = NULL, uses = uses + 1, last_used_at = NOW(), last_ip = ? WHERE id = ?")->execute([kaIp(), (int)$k['id']]);
    $u = getDB()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 AND role = 'viewer'"); $u->execute([(int)$k['user_id']]); $user = $u->fetch();
    return $user ? $user + ['_key_id' => (int)$k['id']] : null;
}

/* ───── 📱 الموبايل: كوكي + أجهزة ───── */
function kaCookieSet(string $sel, string $val): void {
    setcookie(KA_COOKIE, $sel . ':' . $val, ['expires' => time() + 86400 * KA_COOKIE_DAYS, 'path' => BASE_URL, 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}
function kaCookieClear(): void {
    setcookie(KA_COOKIE, '', ['expires' => time() - 3600, 'path' => BASE_URL, 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}
function kaCookieParse(): ?array {
    $c = (string)($_COOKIE[KA_COOKIE] ?? ''); if (strpos($c, ':') === false) return null;
    [$sel, $val] = explode(':', $c, 2);
    return (preg_match('/^[a-f0-9]{24}$/', $sel) && preg_match('/^[a-f0-9]{64}$/', $val)) ? [$sel, $val] : null;
}
function kaCookieDevice(): ?array {
    $p = kaCookieParse(); if (!$p) return null;
    kaSchema();
    $st = getDB()->prepare("SELECT d.*, k.revoked, k.user_id FROM key_devices d JOIN app_keys k ON k.id = d.key_id WHERE d.selector = ?"); $st->execute([$p[0]]); $d = $st->fetch();
    if (!$d || $d['revoked'] || !hash_equals((string)$d['validator_hash'], hash('sha256', $p[1]))) return null;
    return $d + ['validator' => $p[1]];
}
/** 📱 دخول تلقائي بالكوكي (تستدعيه login.php حين لا جلسة) ⇒ true إن فُتحت الجلسة */
function kaCookieLogin(): bool {
    if (empty($_COOKIE[KA_COOKIE])) return false;
    $d = kaCookieDevice();
    if (!$d) { kaCookieClear(); return false; }
    $u = getDB()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 AND role = 'viewer'"); $u->execute([(int)$d['user_id']]); $user = $u->fetch();
    if (!$user) { kaCookieClear(); return false; }
    getDB()->prepare("UPDATE key_devices SET last_used_at = NOW(), uses = uses + 1, last_ip = ? WHERE id = ?")->execute([kaIp(), (int)$d['id']]);
    getDB()->prepare("UPDATE app_keys SET last_used_at = NOW(), uses = uses + 1, last_ip = ? WHERE id = ?")->execute([kaIp(), (int)$d['key_id']]);
    kaCookieSet($d['selector'], $d['validator']);
    kaOpenSession($user, (int)$d['key_id'], 'cookie');
    return true;
}
/** 📱 ربط هذا المتصفّح برابط موبايل: [ok, msgFr, msgAr] (ok ⇒ الجلسة مفتوحة) */
function kaLinkBind(string $sel, string $tok): array {
    kaSchema();
    if (!preg_match('/^[a-f0-9]{16}$/', $sel) || !preg_match('/^[a-f0-9]{48}$/', $tok)) { usleep(300000); return [false, 'Lien invalide', 'الرابط غير صالح']; }
    $st = getDB()->prepare("SELECT k.*, u.username, u.is_active, u.role FROM app_keys k JOIN users u ON u.id = k.user_id WHERE k.selector = ? AND k.kind = 'link'"); $st->execute([$sel]); $k = $st->fetch();
    if (!$k || !hash_equals((string)$k['secret_hash'], hash('sha256', $tok))) { if ($k) getDB()->prepare("UPDATE app_keys SET fails = fails + 1 WHERE id = ?")->execute([(int)$k['id']]); kaAudit('key_fail', $k ? (int)$k['user_id'] : 0, 'link ' . kaIp()); usleep(500000); return [false, "Ce lien n'est pas valable — demandez un nouveau lien à l'administration.", 'هذا الرابط غير صالح — اطلبوا رابطاً جديداً من الإدارة.']; }
    if ($k['revoked'] || !$k['is_active'] || $k['role'] !== 'viewer') { kaAudit('key_revoked', (int)$k['user_id'], '#' . $k['id']); return [false, 'Ce lien a été annulé — demandez un nouveau lien à l\'administration.', 'أُلغي هذا الرابط — اطلبوا رابطاً جديداً من الإدارة.']; }
    $user = getDB()->prepare("SELECT * FROM users WHERE id = ?"); $user->execute([(int)$k['user_id']]); $user = $user->fetch();
    $d = kaCookieDevice();
    if ($d && (int)$d['key_id'] === (int)$k['id']) { kaCookieSet($d['selector'], $d['validator']); kaOpenSession($user, (int)$k['id'], 'link'); return [true, '', '']; }
    $cnt = getDB()->prepare("SELECT COUNT(*) FROM key_devices WHERE key_id = ?"); $cnt->execute([(int)$k['id']]);
    if ((int)$cnt->fetchColumn() >= KA_LINK_MAX_DEVICES) { getDB()->prepare("UPDATE app_keys SET fails = fails + 1 WHERE id = ?")->execute([(int)$k['id']]); kaAudit('key_machine', (int)$k['user_id'], '#' . $k['id'] . ' link'); return [false, 'Ce lien est déjà installé sur un autre appareil. L\'administration peut le délier depuis « Accès écoles ».', 'هذا الرابط مركَّب على جهاز آخر. الإدارة تقدر تفكّ الربط من «دخول المدارس».']; }
    $dsel = bin2hex(random_bytes(12)); $dval = bin2hex(random_bytes(32));
    getDB()->prepare("INSERT INTO key_devices(key_id, selector, validator_hash, label, last_used_at, last_ip, uses) VALUES(?,?,?,?,NOW(),?,1)")->execute([(int)$k['id'], $dsel, hash('sha256', $dval), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), kaIp()]);
    getDB()->prepare("UPDATE app_keys SET machine_hash = COALESCE(machine_hash, 'link'), bound_at = COALESCE(bound_at, NOW()), uses = uses + 1, last_used_at = NOW(), last_ip = ?, fails = 0 WHERE id = ?")->execute([kaIp(), (int)$k['id']]);
    kaCookieSet($dsel, $dval);
    kaAudit('key_bind', (int)$k['user_id'], '#' . $k['id'] . ' ' . kaUaShort((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    kaOpenSession($user, (int)$k['id'], 'link');
    return [true, 'new', ''];
}

/** 🤖 جالب معاينة الروابط (واتساب…) أو HEAD: بطاقة فقط، بلا ربط */
function kaIsPreviewBot(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return true;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || preg_match('~WhatsApp/|facebookexternalhit|Facebot|TelegramBot|Twitterbot|Slackbot|LinkedInBot|Discordbot|Googlebot|bingbot|SkypeUriPreview|iMessage|preview|bot\b|crawler|spider|curl/|Wget/~i', $ua) === 1;
}
function kaPreviewHtml(string $username, string $label): string {
    $img = kaBase() . 'icon.php?u=' . rawurlencode($username) . '&s=512'; $t = 'MSA Payroll — ' . $label; $d = 'Appuyez pour ouvrir · اكبسوا لفتح البرنامج';
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($t) . '</title>'
        . '<meta property="og:type" content="website"><meta property="og:site_name" content="MSA Payroll"><meta property="og:title" content="' . e($t) . '"><meta property="og:description" content="' . e($d) . '"><meta property="og:image" content="' . e($img) . '"><meta property="og:image:width" content="512"><meta property="og:image:height" content="512"><meta name="twitter:card" content="summary"><meta name="robots" content="noindex,nofollow"></head>'
        . '<body style="font-family:Segoe UI,Arial;text-align:center;padding:40px 16px;background:#0a2240;color:#fff"><img src="' . e($img) . '" alt="" style="width:120px;border-radius:24px"><h2 style="margin:16px 0 4px">' . e($t) . '</h2><p>' . e($d) . '</p></body></html>';
}
function kaUaShort(string $ua): string {
    $os = preg_match('/iPhone/i', $ua) ? 'iPhone' : (preg_match('/iPad/i', $ua) ? 'iPad' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Macintosh/i', $ua) ? 'Mac' : ''))));
    $br = preg_match('/WhatsApp/i', $ua) ? 'WhatsApp' : (preg_match('/EdgA?\//i', $ua) ? 'Edge' : (preg_match('/CriOS|Chrome\//i', $ua) ? 'Chrome' : (preg_match('/Safari\//i', $ua) ? 'Safari' : (preg_match('/Firefox/i', $ua) ? 'Firefox' : ''))));
    return trim($os . ' ' . $br) ?: mb_substr($ua, 0, 30);
}

/** المفاتيح مجمّعة حسب المستخدم (لصفحة دخول المدارس) */
function kaAllByUser(): array {
    kaSchema();
    $out = [];
    try {
        $rows = getDB()->query("SELECT * FROM app_keys ORDER BY revoked, id DESC")->fetchAll();
        $devs = [];
        foreach (getDB()->query("SELECT * FROM key_devices ORDER BY id")->fetchAll() as $d) $devs[(int)$d['key_id']][] = $d;
        foreach ($rows as $r) { $r['devices'] = $devs[(int)$r['id']] ?? []; $out[(int)$r['user_id']][] = $r; }
    } catch (Throwable $e) {}
    return $out;
}
function kaRevoke(int $id): void { kaSchema(); getDB()->prepare("UPDATE app_keys SET revoked = 1, nonce_hash = NULL WHERE id = ?")->execute([$id]); getDB()->prepare("DELETE FROM key_devices WHERE key_id = ?")->execute([$id]); kaAudit('key_revoke', 0, '#' . $id); }
function kaUnbind(int $id): void { kaSchema(); getDB()->prepare("UPDATE app_keys SET machine_hash = NULL, machine_label = '', bound_at = NULL, fails = 0, nonce_hash = NULL WHERE id = ?")->execute([$id]); getDB()->prepare("DELETE FROM key_devices WHERE key_id = ?")->execute([$id]); kaAudit('key_unbind', 0, '#' . $id); }
