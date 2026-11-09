<?php
/**
 * 🏫 Écoles & établissements — المدارس والمؤسسات: كل مدرسة ببطاقة واحدة فيها كل معلوماتها (2026-11-05)
 * طلبه: «إعدادات المدارس وفتح مدرسة جديدة وبأيّ سنة نحنا وقدّيش النسبة للإضافي بكل مدرسة والنقل… معجّقين» ثمّ
 * «الاتجاه من الشمال لليمين · واضح وعالمي · بدون تعقيدات · خيار تعديل وحذف · كل مدرسة كامل معلوماتها وما روح من محل لمحل
 *  · خيار لمين بدي طبّق: للملاك للمتعاقد للموظف».
 * البطاقة: Identité (الأرقام الرسمية) · Personnel · Paie لكل فئة (الإضافي، النقل اليومي والشهري، شهر 13) · Accès (الحساب، الأقفال)
 * + Modifier (نفس البطاقة تفتح خاناتها) + Supprimer (بتأكيد) + Nouvelle école (4 خطوات بصفحة واحدة).
 * الحسابات: تطبيق النسب/النقل = نفس SQL «المكافآت الجماعية» (bulk_allowances: إطفاء بنود النوع للسنة ثمّ سطر «كل السنة» ثمّ recalcEmployeeYear)
 * — لا مسار حسابي جديد. الصفحات القديمة (schools/bulk_allowances/school_access/open_year) تبقى كما هي.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php';
require_once __DIR__ . '/../includes/school_access.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
requireLogin();
if (!isSuperAdmin()) {
    $_SESSION['flash_error'] = 'Accès réservé au directeur général / صلاحية المدير العام فقط';
    header('Location: ' . BASE_URL . 'index.php'); exit;
}
$currentPage = 'ecoles';
$pageTitle = 'Écoles & établissements / المدارس والمؤسسات';
$hideExportToolbar = true;
$db = getDB();
ensureEmployeeFlagColumns(); saEnsureCols(); ensureYearLockTable();
try { $db->query("SELECT caisse_number, email_sender_mode FROM schools LIMIT 1"); } catch (Throwable $e) {
    try { $db->exec("ALTER TABLE schools ADD COLUMN caisse_number VARCHAR(50) NULL"); } catch (Throwable $e2) {}
    try { $db->exec("ALTER TABLE schools ADD COLUMN email_sender_mode VARCHAR(10) NULL"); } catch (Throwable $e3) {}
}

$CATS = [
    'titulaire'   => ['type' => 'enseignant_titulaire',   'fr' => 'Titulaires',   'ar' => 'ملاك'],
    'contractuel' => ['type' => 'enseignant_contractuel', 'fr' => 'Contractuels', 'ar' => 'متعاقد'],
    'employe'     => ['type' => 'employe',                'fr' => 'Employés',     'ar' => 'موظف'],
];
$sy = (string)($_GET['sy'] ?? $_POST['sy'] ?? '');
if (!preg_match('/^\d{4}-\d{4}$/', $sy)) $sy = activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear();
$self = BASE_URL . 'pages/ecoles.php';
$fmtN = fn($v) => number_format((float)$v);

/** موظفو المدرسة بفئة (الموجودون فعلاً بالسنة: راتب فعلي ولم يتركوا قبلها) — نفس فلتر لائحة الموظفين */
function ecolesEmpIds(PDO $db, int $sid, string $type, string $sy): array {
    [$yf, $yp] = yearEmploymentFilter($sy);
    $q = $db->prepare("SELECT id FROM employees WHERE is_deleted = 0 AND school_id = ? AND employee_type = ?" . $yf);
    $q->execute(array_merge([$sid, $type], $yp));
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}
/**
 * تطبيق بند على فئة بمدرسة لسنة — نفس منطق bulk_allowances ($applyLinesTo / apply_transport_periods):
 * value > 0 ⇒ إطفاء بنود النوع للسنة ثمّ سطر «كل السنة» (10 ← 9) · value = 0 ⇒ إطفاء فقط · ثمّ إعادة حساب السنة لكل موظف.
 * يُرجع [عدد الموظفين، عدد المحسوبين].
 */
function ecolesApplyPay(PDO $db, int $sid, string $type, string $bonusType, float $value, string $vt, string $cur, string $sy): array {
    if (!in_array($bonusType, ['prime_fixe', 'transport_daily', 'transport_complement'], true)) return [0, 0];
    if ($bonusType !== 'prime_fixe') $vt = 'amount';                     // 🚌 النقل مبلغ دائماً
    if ($vt === 'percent') $cur = 'LBP';
    $ids = ecolesEmpIds($db, $sid, $type, $sy);
    $off = $db->prepare("UPDATE employee_bonuses SET is_active = 0 WHERE employee_id = ? AND bonus_type = ? AND school_year = ?");
    $ins = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, ?, 1, ?, ?, ?, ?, 10, 9, 1)");
    $zeroCol = $db->prepare("UPDATE employees SET transport_daily_amount = 0 WHERE id = ?");
    $done = 0;
    foreach ($ids as $id) {
        $off->execute([$id, $bonusType, $sy]);
        if ($bonusType === 'transport_daily') $zeroCol->execute([$id]);   // لا ازدواج مع عمود النقل الثابت
        if ($value > 0) $ins->execute([$id, $bonusType, $sy, $value, $vt, $cur]);
        if (recalcEmployeeYear($id, $sy) > 0) $done++;
    }
    return [count($ids), $done];
}
/** وصف قيمة بند للرسائل/العرض */
function ecolesValLabel(string $vt, $amount, string $cur): string {
    if ($vt === 'percent') return nfc((float)$amount, 2, true) . ' %';
    return number_format((float)$amount) . ($cur === 'USD' ? ' $' : ' L.L');
}

// ===================== المعالجات =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWriteAction(); requireCsrf();
    $act = (string)($_POST['action'] ?? '');
    $sid = (int)($_POST['id'] ?? 0);
    $back = $self . '?sy=' . urlencode($sy) . ($sid > 0 ? '#ecole-' . $sid : '');

    if ($act === 'save_school' || $act === 'create_school') {
        $nameFr = trim((string)($_POST['name_fr'] ?? '')); $nameAr = trim((string)($_POST['name_ar'] ?? ''));
        if ($nameFr === '' && $nameAr === '') { $_SESSION['flash_error'] = 'Le nom est requis / الاسم مطلوب'; header('Location: ' . ($act === 'create_school' ? $self . '?new=1' : $back)); exit; }
        if ($nameFr === '') $nameFr = $nameAr; if ($nameAr === '') $nameAr = $nameFr;
        $addrAr = trim((string)($_POST['address'] ?? '')); $addrFr = trim((string)($_POST['address_fr'] ?? '')); if ($addrFr === '' && $addrAr !== '') $addrFr = arPlaceToFr($addrAr);
        $dirAr = trim((string)($_POST['director_name'] ?? '')); $dirFr = trim((string)($_POST['director_name_fr'] ?? '')); if ($dirFr === '' && $dirAr !== '') $dirFr = arNameToFr($dirAr);
        $vals = [
            'code' => trim((string)($_POST['code'] ?? '')) ?: null,
            'name_ar' => $nameAr, 'name_fr' => $nameFr,
            'address' => $addrAr, 'address_fr' => $addrFr,
            'phone' => normalizeLbPhone($_POST['phone'] ?? ''),            // 📞 الشكل الموحَّد 03-888849
            'email' => trim((string)($_POST['email'] ?? '')),
            'nssf_employer_number' => officialNumberFr($_POST['nssf_employer_number'] ?? ''),
            'finance_number' => officialNumberFr($_POST['finance_number'] ?? ''),
            'caisse_number' => officialNumberFr($_POST['caisse_number'] ?? ''),
            'director_name' => $dirAr, 'director_name_fr' => $dirFr,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'email_sender_mode' => (($_POST['email_sender_mode'] ?? 'program') === 'school') ? 'school' : 'program',
        ];
        if ($act === 'save_school' && $sid > 0) {
            $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($vals)));
            $db->prepare("UPDATE schools SET $set WHERE id = :id AND is_deleted = 0")->execute($vals + ['id' => $sid]);
            logAudit('update', 'schools', $sid, null, $nameFr);
            $_SESSION['flash_success'] = 'École mise à jour / تمّ تحديث المدرسة : ' . $nameFr;
            header('Location: ' . $back); exit;
        }
        // ➕ مدرسة جديدة (+ حساب دخول اختياري بنفس الخطوة)
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($vals))); $ph = implode(',', array_map(fn($c) => ":$c", array_keys($vals)));
        $db->prepare("INSERT INTO schools ($cols) VALUES ($ph)")->execute($vals);
        $newId = (int)$db->lastInsertId();
        logAudit('create', 'schools', $newId, null, $nameFr);
        $msg = 'École créée / أُنشئت المدرسة : ' . $nameFr;
        if (!empty($_POST['create_account'])) {
            $s = ['id' => $newId, 'name_fr' => $nameFr, 'name_ar' => $nameAr, 'address_fr' => $addrFr, 'address' => $addrAr];
            $taken = []; foreach ($db->query("SELECT id, username FROM users")->fetchAll() as $u) $taken[strtolower((string)$u['username'])] = (int)$u['id'];
            $username = strtolower(preg_replace('/[^a-z0-9_.-]/i', '', (string)($_POST['username'] ?? ''))) ?: saUniqueUsername($s, $taken);
            if (isset($taken[$username])) $username = saUniqueUsername($s, $taken);
            $pw = trim((string)($_POST['password'] ?? '')) ?: saStandardPassword($username);
            $db->prepare("INSERT INTO users (username, password_hash, full_name, role, school_id, is_active, allowed_pages, allowed_schools, pw_known) VALUES (?,?,?,?,?,1,?,?,?)")
               ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $nameFr, 'viewer', $newId, implode(',', array_keys(viewerReportPages())), (string)$newId, $pw]);
            logAudit('create', 'users', (int)$db->lastInsertId(), null, $username);
            $msg .= ' — Compte / حساب الدخول : ' . $username . ' · ' . $pw;
        }
        $_SESSION['flash_success'] = $msg;
        header('Location: ' . $self . '?sy=' . urlencode($sy) . '#ecole-' . $newId); exit;
    }

    if ($act === 'toggle_active' && $sid > 0) {
        $cur = (int)$db->query("SELECT is_active FROM schools WHERE id = $sid")->fetchColumn();
        $db->prepare("UPDATE schools SET is_active = ? WHERE id = ?")->execute([$cur ? 0 : 1, $sid]);
        logAudit('update', 'schools', $sid, $cur ? 'active' : 'inactive', $cur ? 'inactive' : 'active');
        $_SESSION['flash_success'] = $cur ? 'École désactivée / عُطّلت المدرسة' : 'École réactivée / أُعيد تفعيل المدرسة';
        header('Location: ' . $back); exit;
    }

    if ($act === 'delete_school' && $sid > 0) {
        // 🛡️ قاعدة أندره مراد: الحذف بعد صفحة تأكيد حقيقية فقط — وإن كان فيها موظفون لا تُحذف (تُعطَّل) حتى لا تضيع رواتبهم من التقارير
        $n = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND school_id = $sid")->fetchColumn();
        if ($n > 0) {
            $_SESSION['flash_error'] = "Impossible de supprimer : $n employé(s) y sont rattachés — désactivez-la ou transférez-les d'abord / لا يمكن الحذف: فيها $n موظفاً — عطّلها أو انقلهم أوّلاً";
        } else {
            $nm = (string)$db->query("SELECT name_fr FROM schools WHERE id = $sid")->fetchColumn();
            $db->prepare("UPDATE schools SET is_deleted = 1, is_active = 0 WHERE id = ?")->execute([$sid]);
            logAudit('delete', 'schools', $sid, $nm, null);
            $_SESSION['flash_success'] = 'École supprimée / حُذفت المدرسة : ' . $nm;
            $back = $self . '?sy=' . urlencode($sy);
        }
        header('Location: ' . $back); exit;
    }

    if ($act === 'apply_pay' && $sid > 0) {
        if (isSchoolYearLocked($sid, $sy)) { $_SESSION['flash_error'] = yearLockedMsg($sid, $sy); header('Location: ' . $back); exit; }
        $parts = []; $errs = [];
        foreach ($CATS as $ck => $c) {
            if (empty($_POST['apply'][$ck])) continue;   // الفئة غير المؤشَّرة لا تُمسّ
            $p = (array)($_POST['pay'][$ck] ?? []);
            $norm = fn($v) => trim(str_replace(',', '', (string)$v));
            // الإضافي: نسبة أو مبلغ (ليرة/دولار) · النقل اليومي والشهري: مبلغ بالليرة · فارغ = لا تغيير · 0 = شيل
            $lines = [];
            $sv = $norm($p['sup'] ?? '');
            if ($sv !== '') { $vt = (($p['sup_vt'] ?? 'percent') === 'percent') ? 'percent' : 'amount'; $cur = (($p['sup_cur'] ?? 'LBP') === 'USD') ? 'USD' : 'LBP'; $lines[] = ['prime_fixe', (float)$sv, $vt, $cur, 'Supplément / الأجر الإضافي']; }
            $tj = $norm($p['tj'] ?? ''); if ($tj !== '') $lines[] = ['transport_daily', (float)$tj, 'amount', 'LBP', 'Transport / jour / النقل اليومي'];
            $tm = $norm($p['tm'] ?? ''); if ($tm !== '') $lines[] = ['transport_complement', (float)$tm, 'amount', 'LBP', 'Transport / mois / النقل الشهري'];
            foreach ($lines as [$bt, $val, $vt, $cur, $lbl]) {
                if ($val < 0 || ($vt === 'percent' && $val > 300)) { $errs[] = $lbl . ' : valeur invalide / قيمة غير صالحة'; continue; }
                [$n, $done] = ecolesApplyPay($db, $sid, $c['type'], $bt, $val, $vt, $cur, $sy);
                $parts[] = $c['fr'] . ' · ' . $c['ar'] . ' (' . $n . ') : ' . $lbl . ' = ' . ($val > 0 ? ecolesValLabel($vt, $val, $cur) : 'retiré / شُيل') . ' — recalculé / أُعيد حساب ' . $done;
                logAudit('bulk_allowance', 'employee_bonuses', $sid, null, ['school' => $sid, 'cat' => $ck, 'type' => $bt, 'value' => $val, 'vt' => $vt, 'cur' => $cur, 'sy' => $sy, 'n' => $n]);
            }
        }
        if ($parts) $_SESSION['flash_success'] = '✅ ' . $sy . ' — ' . implode(' · ', $parts) . ' (les salaires transférés manuellement ne sont pas touchés / المنقولون يدوياً لم يُمَسّوا)';
        elseif (!$errs) $_SESSION['flash_error'] = 'Cochez une catégorie et remplissez une valeur / أشّر فئة واكتب قيمة';
        if ($errs) $_SESSION['flash_error'] = implode(' · ', $errs);
        header('Location: ' . $back); exit;
    }
    header('Location: ' . $back); exit;
}

// ===================== القراءة =====================
$schools = $db->query("SELECT * FROM schools WHERE is_deleted = 0 ORDER BY is_active DESC, name_fr, name_ar")->fetchAll(PDO::FETCH_ASSOC);
[$yfSql, $yfParams] = yearEmploymentFilter($sy, 'e.');
$nowYm = (int)date('Y') * 100 + (int)date('n');
$locks = yearLocksMap(true);
$usersBySchool = [];
foreach ($db->query("SELECT id, username, is_active, school_id, allowed_schools FROM users WHERE role = 'viewer'") as $u) {
    $one = (int)$u['school_id']; $as = (string)$u['allowed_schools'];
    if ($one <= 0 && $as !== '' && $as !== 'all' && strpos($as, ',') === false) $one = (int)$as;
    if ($one > 0) $usersBySchool[$one][] = $u;
}
$rate = (float)getExchangeRate();
$progSy = (string)getSetting('program_school_year', currentSchoolYear());
$info = []; $typeToCat = []; foreach ($CATS as $ck => $c) $typeToCat[$c['type']] = $ck;
foreach ($schools as $s) {
    $sid = (int)$s['id']; $i = ['cnt' => [], 'pay' => [], 'm13' => [], 'tot' => 0];
    $i['all'] = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND school_id = $sid")->fetchColumn();   // كل الملفات (الكبسة) — tot = الموجودون فعلاً بالسنة
    foreach ($CATS as $ck => $c) {
        $q = $db->prepare("SELECT COUNT(*), SUM(e.has_13th_month = 1) FROM employees e WHERE e.is_deleted = 0 AND e.school_id = ? AND e.employee_type = ?" . $yfSql);
        $q->execute(array_merge([$sid, $c['type']], $yfParams)); [$n, $m13] = $q->fetch(PDO::FETCH_NUM);
        $i['cnt'][$ck] = (int)$n; $i['m13'][$ck] = (int)$m13; $i['tot'] += (int)$n;
    }
    // البنود السائدة لكل فئة ونوع (سطر «كل السنة» فقط — كما تعرضها المكافآت الجماعية)
    $q = $db->prepare("SELECT e.employee_type t, b.bonus_type bt, b.value_type vt, b.amount, b.currency cur, COUNT(DISTINCT b.employee_id) n
                       FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id AND e.is_deleted = 0 AND e.school_id = ?" . $yfSql . "
                       WHERE b.school_year = ? AND b.is_active = 1 AND b.bonus_type IN ('prime_fixe','transport_daily','transport_complement')
                         AND (b.start_month IS NULL OR (b.start_month = 10 AND b.end_month = 9))
                       GROUP BY e.employee_type, b.bonus_type, b.value_type, b.amount, b.currency ORDER BY n DESC");
    $q->execute(array_merge([$sid], $yfParams, [$sy]));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ck = $typeToCat[$r['t']] ?? null;
        if ($ck === null) continue;
        if (!isset($i['pay'][$ck][$r['bt']])) $i['pay'][$ck][$r['bt']] = ['label' => ecolesValLabel($r['vt'], $r['amount'], $r['cur']), 'n' => (int)$r['n'], 'variants' => 1, 'vt' => $r['vt'], 'amount' => $r['amount'], 'cur' => $r['cur']];
        else $i['pay'][$ck][$r['bt']]['variants']++;
    }
    // النقل اليومي الثابت بعمود الملف (القديم) إن لم يكن بنداً
    $q = $db->prepare("SELECT e.employee_type t, e.transport_daily_amount a, e.transport_daily_currency c, COUNT(*) n FROM employees e WHERE e.is_deleted = 0 AND e.school_id = ? AND e.transport_daily_amount > 0" . $yfSql . " GROUP BY 1,2,3 ORDER BY n DESC");
    $q->execute(array_merge([$sid], $yfParams));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ck = $typeToCat[$r['t']] ?? null;
        if ($ck !== null && !isset($i['pay'][$ck]['transport_daily'])) $i['pay'][$ck]['transport_daily'] = ['label' => ecolesValLabel('amount', $r['a'], (string)$r['c']), 'n' => (int)$r['n'], 'variants' => 1, 'vt' => 'amount', 'amount' => $r['a'], 'cur' => (string)$r['c']];
    }
    $q = $db->prepare("SELECT MAX(m.year * 100 + m.month), MAX(m.school_year) FROM monthly_salaries m JOIN employees e ON e.id = m.employee_id AND e.is_deleted = 0 WHERE e.school_id = ? AND m.net_salary_lbp > 0 AND (m.year * 100 + m.month) <= ?");
    $q->execute([$sid, $nowYm]); [$lastYm, $maxSy] = $q->fetch(PDO::FETCH_NUM);
    $i['last'] = $lastYm ? monthName((int)substr((string)$lastYm, 4), 'fr') . ' ' . substr((string)$lastYm, 0, 4) : '';
    $q = $db->prepare("SELECT MAX(m.school_year) FROM monthly_salaries m JOIN employees e ON e.id = m.employee_id AND e.is_deleted = 0 WHERE e.school_id = ?"); $q->execute([$sid]);
    $i['max_sy'] = (string)$q->fetchColumn();
    $q = $db->prepare("SELECT COUNT(*) FROM monthly_salaries m JOIN employees e ON e.id = m.employee_id AND e.is_deleted = 0 WHERE e.school_id = ? AND m.school_year = ? AND m.net_salary_lbp > 0"); $q->execute([$sid, $sy]);
    $i['paid_rows_sy'] = (int)$q->fetchColumn();
    $i['users'] = $usersBySchool[$sid] ?? [];
    $i['locks'] = array_keys($locks[$sid] ?? []); sort($i['locks']);
    // التنبيهات
    $al = [];
    if (!(int)$s['is_active']) $al[] = ['g', 'Inactive / معطّلة'];
    if ($i['tot'] > 0 && $i['paid_rows_sy'] === 0) $al[] = ['w', 'Aucune paie ' . $sy . ' / لا رواتب هالسنة'];
    if ($i['max_sy'] !== '' && $i['max_sy'] > $sy) $al[] = ['w', 'Année ' . $i['max_sy'] . ' ouverte / سنة مستقبلية مفتوحة'];
    if (trim((string)$s['nssf_employer_number']) === '' || trim((string)$s['finance_number']) === '') $al[] = ['w', 'N° officiels manquants / أرقام رسمية ناقصة'];
    if (trim((string)$s['director_name_fr']) === '' && trim((string)$s['director_name']) === '') $al[] = ['g', 'Directeur à compléter / المدير ناقص'];   // معلومة لا تنبيه
    $i['alerts'] = $al;
    $info[$sid] = $i;
}
usort($schools, fn($a, $b) => [(int)$b['is_active'], $info[(int)$b['id']]['all'], $a['name_fr']] <=> [(int)$a['is_active'], $info[(int)$a['id']]['all'], $b['name_fr']]);   // الفاعلة أوّلاً ثمّ الأكبر
$nAlerts = count(array_filter($info, fn($i) => array_filter($i['alerts'], fn($a) => $a[0] === 'w')));
$sys = []; foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE school_year <> '' ORDER BY school_year DESC") as $r) $sys[] = (string)$r['school_year'];
if (!in_array($sy, $sys, true)) { $sys[] = $sy; rsort($sys); }
$newMode = !empty($_GET['new']); $confirmDel = (int)($_GET['confirm_delete'] ?? 0);

include __DIR__ . '/../includes/header.php';
$chip = fn(string $cls, string $txt) => '<span class="sc-chip ' . $cls . '">' . e($txt) . '</span>';
$miss = '<b class="sc-miss">— manquant / ناقص</b>';
$kv = function (string $fr, string $ar, $val, bool $rtl = false) use ($miss) {
    $v = trim((string)$val);
    return '<div class="sc-kv"><span>' . e($fr) . ' <i>' . e($ar) . '</i></span>' . ($v === '' ? $miss : '<b' . ($rtl ? ' dir="rtl"' : '') . '>' . e($v) . '</b>') . '</div>';
};
?>
<?php if ($confirmDel > 0 && ($ds = $db->query("SELECT * FROM schools WHERE id = $confirmDel AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC))):
    $dn = (int)$db->query("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND school_id = $confirmDel")->fetchColumn(); ?>
<div class="card sc-confirm">
    <div class="card-body" style="text-align:center;padding:30px">
        <div style="font-size:44px;margin-bottom:10px">⚠️</div>
        <h3 style="margin:0 0 8px">Supprimer cette école ? <span class="ar-sub">هل تريد فعلاً حذف هذه المدرسة؟</span></h3>
        <p style="font-size:18px;font-weight:800;color:#b91c1c;margin:6px 0 4px"><?= e($ds['name_fr']) ?><br><span style="font-size:15px;color:#334155"><?= e($ds['name_ar']) ?></span></p>
        <?php if ($dn > 0): ?>
        <p style="color:#92400e;font-weight:700;margin:8px 0 18px">Elle a <?= $dn ?> employé(s) : elle ne peut pas être supprimée — vous pouvez la <b>désactiver</b> (elle disparaît des listes, les dossiers et salaires restent).<br><span class="ar-sub">فيها <?= $dn ?> موظفاً: ما بتنحذف، بس فيك تعطّلها (بتختفي من اللوائح والملفات والرواتب بتبقى).</span></p>
        <div class="d-flex gap-2" style="justify-content:center">
            <a href="<?= e($self) ?>?sy=<?= e($sy) ?>#ecole-<?= $confirmDel ?>" class="btn btn-light btn-lg"><i class="fas fa-arrow-left"></i> Non, retour / لا، رجوع</a>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $confirmDel ?>"><input type="hidden" name="sy" value="<?= e($sy) ?>"><button class="btn btn-warning btn-lg"><i class="fas fa-power-off"></i> Désactiver / عطّلها</button></form>
        </div>
        <?php else: ?>
        <p style="color:#64748b;margin:4px 0 18px">Elle disparaît du programme ; elle peut être restaurée par le support.<br><span class="ar-sub">بتختفي من البرنامج، وبترجع عند الحاجة.</span></p>
        <div class="d-flex gap-2" style="justify-content:center">
            <a href="<?= e($self) ?>?sy=<?= e($sy) ?>#ecole-<?= $confirmDel ?>" class="btn btn-light btn-lg"><i class="fas fa-arrow-left"></i> Non, retour / لا، رجوع</a>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="delete_school"><input type="hidden" name="id" value="<?= $confirmDel ?>"><input type="hidden" name="sy" value="<?= e($sy) ?>"><button class="btn btn-danger btn-lg"><i class="fas fa-trash"></i> Oui, supprimer / نعم، احذف</button></form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<div class="sc-top">
    <div>
        <div class="sc-crumb">Système / النظام &rsaquo; <b>Écoles &amp; établissements</b></div>
        <h1 class="sc-h1"><i class="fas fa-school"></i> Écoles &amp; établissements <span class="ar-sub">المدارس والمؤسسات — كل مدرسة بكل معلوماتها</span></h1>
    </div>
    <div class="sc-acts">
        <a href="<?= e($self) ?>?new=1&sy=<?= e($sy) ?>" class="btn btn-success sc-btn2"><i class="fas fa-plus"></i> Nouvelle école<small>مدرسة جديدة</small></a>
        <a href="<?= BASE_URL ?>pages/open_year.php" class="btn btn-light sc-btn2"><i class="fas fa-folder-plus"></i> Nouvelle année<small>فتح سنة</small></a>
        <a href="<?= BASE_URL ?>pages/exchange_rates.php" class="btn btn-light sc-btn2"><i class="fas fa-coins"></i> Taux de change<small>سعر الصرف</small></a>
        <a href="<?= BASE_URL ?>pages/school_access.php" class="btn btn-light sc-btn2"><i class="fas fa-school-lock"></i> Accès &amp; icônes<small>دخول المدارس</small></a>
    </div>
</div>
<div class="sc-bar no-print">
    <input type="search" id="scSearch" class="form-control sc-search" placeholder="🔍 Rechercher une école… / فتّش عن مدرسة…" autocomplete="off">
    <form method="get" class="sc-year"><label>Année / السنة</label><select name="sy" class="form-select" onchange="this.form.submit()"><?php foreach ($sys as $y): ?><option value="<?= e($y) ?>" <?= $y === $sy ? 'selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select></form>
    <div class="sc-info">Année du programme <b><?= e($progSy) ?></b> <i>سنة البرنامج</i></div>
    <div class="sc-info">Taux <b><?= number_format($rate) ?> L.L / $</b> <i>سعر الصرف</i></div>
    <div class="sc-info"><b><?= count($schools) ?></b> établissements<?= $nAlerts ? ' · <b style="color:#b45309">' . $nAlerts . ' ⚠️</b>' : '' ?> <i>مؤسسة</i></div>
</div>

<?php if ($newMode): ?>
<div class="sc-card sc-new" id="ecole-new">
    <form method="post" class="sc-form">
        <?= csrfField() ?><input type="hidden" name="action" value="create_school"><input type="hidden" name="sy" value="<?= e($sy) ?>">
        <div class="sc-ch"><div class="sc-name">＋ Nouvelle école <span class="ar-sub">مدرسة جديدة — أربع خطوات بصفحة واحدة</span></div></div>
        <div class="sc-steps"><div class="on">1 · Identité &amp; numéros<small>الاسم والأرقام الرسمية</small></div><div>2 · Année<small>السنة</small></div><div>3 · % &amp; transport<small>النسب والنقل</small></div><div>4 · Accès<small>حساب الدخول</small></div></div>
        <div class="sc-sec"><h4>1 · Identité &amp; numéros officiels <i>الاسم والأرقام الرسمية</i></h4>
            <div class="sc-grid">
                <div class="f"><label>Nom (FR) <i>الاسم بالفرنسي</i> *</label><input class="form-control" name="name_fr" required></div>
                <div class="f"><label>Nom (AR) <i>الاسم بالعربي</i> *</label><input class="form-control" name="name_ar" dir="rtl" required></div>
                <div class="f"><label>Code <i>الرمز</i></label><input class="form-control" name="code"></div>
                <div class="f"><label>N° CNSS employeur <i>رقم رب العمل بالضمان</i></label><input class="form-control" name="nssf_employer_number"></div>
                <div class="f"><label>N° Finances <i>الرقم المالي</i></label><input class="form-control" name="finance_number"></div>
                <div class="f"><label>N° Caisse <i>رقم صندوق التعويضات</i></label><input class="form-control" name="caisse_number"></div>
                <div class="f"><label>Directeur(trice) (FR) <i>المدير بالفرنسي</i></label><input class="form-control" name="director_name_fr"></div>
                <div class="f"><label>Directeur(trice) (AR) <i>المدير بالعربي</i></label><input class="form-control" name="director_name" dir="rtl"></div>
                <div class="f"><label>Adresse (FR) <i>العنوان بالفرنسي</i></label><input class="form-control" name="address_fr"></div>
                <div class="f"><label>Adresse (AR) <i>العنوان بالعربي</i></label><input class="form-control" name="address" dir="rtl"></div>
                <div class="f"><label>Téléphone <i>الهاتف</i></label><input class="form-control" name="phone" placeholder="03-888849"></div>
                <div class="f"><label>E-mail <i>البريد</i></label><input class="form-control" name="email" type="email"></div>
            </div>
        </div>
        <div class="sc-sec"><h4>2 · Année scolaire <i>السنة الدراسية</i></h4>
            <p class="sc-note">Première année : <b><?= e($progSy) ?></b> (année du programme) · mois payés Octobre → Septembre (12) · taux <?= number_format($rate) ?> L.L / $. <span class="ar-sub">أوّل سنة = سنة البرنامج، أشهر الدفع تشرين ← أيلول، وسعر الصرف العام. تتغيّر لاحقاً من فتح السنة وسعر الصرف.</span></p>
        </div>
        <div class="sc-sec"><h4>3 · Supplément &amp; transport <i>النسب والنقل</i></h4>
            <p class="sc-note">Se règlent dès que l'école a des employés : dans sa carte, bouton <b>Modifier</b> → bloc Paie, par catégorie (titulaires / contractuels / employés). <span class="ar-sub">بتنضبط بس يصير فيها موظفون: من بطاقة المدرسة ← تعديل ← الرواتب، لكل فئة.</span></p>
        </div>
        <div class="sc-sec"><h4>4 · Accès de l'école <i>حساب دخول المدرسة</i></h4>
            <label class="sc-check"><input type="checkbox" name="create_account" value="1" checked> Créer un compte lecture seule pour cette école <i>أنشئ حساب دخول (قراءة فقط) للمدرسة</i></label>
            <div class="sc-grid">
                <div class="f"><label>Identifiant <i>اسم الدخول</i></label><input class="form-control" name="username" placeholder="(auto : nom de la région)"></div>
                <div class="f"><label>Mot de passe <i>كلمة السرّ</i></label><input class="form-control" name="password" placeholder="(auto : Région@<?= date('Y') ?>)"></div>
            </div>
        </div>
        <div class="sc-save"><button class="btn btn-success btn-lg"><i class="fas fa-check"></i> Confirmer et créer / تأكيد وإنشاء</button> <a href="<?= e($self) ?>?sy=<?= e($sy) ?>" class="btn btn-light btn-lg">Annuler / إلغاء</a></div>
    </form>
</div>
<?php endif; ?>

<?php foreach ($schools as $s): $sid = (int)$s['id']; $i = $info[$sid]; $act = (int)$s['is_active'];
    $hasW = (bool)array_filter($i['alerts'], fn($a) => $a[0] === 'w');
    $q = strtolower($s['name_fr'] . ' ' . $s['name_ar'] . ' ' . $s['address_fr'] . ' ' . $s['address'] . ' ' . $s['code']); ?>
<div class="sc-card <?= $act ? ($hasW ? 'sc-w' : '') : 'sc-off' ?>" id="ecole-<?= $sid ?>" data-q="<?= e($q) ?>">
    <div class="sc-ch">
        <div class="sc-name"><?= e($s['name_fr']) ?><span class="ar-sub"><?= e($s['name_ar']) ?></span></div>
        <div class="sc-acts no-print">
            <button type="button" class="btn btn-primary btn-sm sc-btn2" onclick="scEdit(<?= $sid ?>)"><i class="fas fa-pen"></i> Modifier<small>تعديل</small></button>
            <a href="<?= BASE_URL ?>switch_school.php?school=<?= $sid ?>&amp;back=<?= e(rawurlencode(BASE_URL . 'pages/employees.php')) ?>" class="btn btn-light btn-sm sc-btn2"><i class="fas fa-users"></i> Employés (<?= $i['all'] ?>)<small>الموظفون</small></a>
            <a href="<?= BASE_URL ?>pages/school_access.php#school-<?= $sid ?>" class="btn btn-light btn-sm sc-btn2"><i class="fas fa-key"></i> Icône d'accès<small>أيقونة الدخول</small></a>
            <?php if (!$act): ?><form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $sid ?>"><input type="hidden" name="sy" value="<?= e($sy) ?>"><button class="btn btn-light btn-sm sc-btn2"><i class="fas fa-play"></i> Réactiver<small>إعادة تفعيل</small></button></form><?php endif; ?>
            <a href="<?= e($self) ?>?confirm_delete=<?= $sid ?>&sy=<?= e($sy) ?>" class="btn btn-sm sc-btn2 sc-del"><i class="fas fa-trash"></i> Supprimer<small>حذف</small></a>
        </div>
    </div>
    <div class="sc-chips">
        <?= $chip($act ? 'ok' : 'g', $act ? 'Active' : 'Inactive') ?>
        <?= $chip($i['paid_rows_sy'] ? 'ok' : 'g', 'Année ' . $sy) ?>
        <?php if (trim((string)$s['address_fr']) !== ''): ?><?= $chip('', preg_replace('/\s*-.*$/u', '', (string)$s['address_fr'])) ?><?php endif; ?>
        <?php foreach ($i['alerts'] as $a): if ($a[0] === 'g' && !$act && $a[1] === 'Inactive / معطّلة') continue; ?><?= $chip($a[0], ($a[0] === 'w' ? '⚠️ ' : '') . $a[1]) ?><?php endforeach; ?>
        <?php if ($act && !array_filter($i['alerts'], fn($a) => $a[0] === 'w')): ?><?= $chip('ok', '✓ Complet / مكتمل') ?><?php endif; ?>
    </div>

    <div class="sc-view" id="scView<?= $sid ?>">
        <div class="sc-cols">
            <div class="sc-sec"><h4>Identité <i>المعلومات الرسمية</i></h4>
                <?= $kv('N° CNSS employeur', 'ضمان', $s['nssf_employer_number']) ?>
                <?= $kv('N° Finances', 'مالية', $s['finance_number']) ?>
                <?= $kv('N° Caisse', 'صندوق', $s['caisse_number']) ?>
                <?= $kv('Directeur(trice)', 'المدير', trim((string)$s['director_name_fr']) !== '' ? $s['director_name_fr'] : $s['director_name'], trim((string)$s['director_name_fr']) === '') ?>
                <?= $kv('Adresse', 'العنوان', trim((string)$s['address_fr']) !== '' ? $s['address_fr'] : $s['address'], trim((string)$s['address_fr']) === '') ?>
                <?= $kv('Tél.', 'هاتف', $s['phone']) ?>
                <?= $kv('E-mail', 'بريد', $s['email']) ?>
            </div>
            <div class="sc-sec"><h4>Personnel <?= e($sy) ?> <i>الموظفون</i></h4>
                <?php foreach ($CATS as $ck => $c): ?><div class="sc-kv"><span><?= e($c['fr']) ?> <i><?= e($c['ar']) ?></i></span><b><?= $i['cnt'][$ck] ?></b></div><?php endforeach; ?>
                <div class="sc-kv"><span>Total <?= e($sy) ?> <i>المجموع</i></span><b><?= $i['tot'] ?></b></div>
                <?php if ($i['all'] !== $i['tot']): ?><div class="sc-kv"><span>Dossiers au total <i>كل الملفات</i></span><b><?= $i['all'] ?></b></div><?php endif; ?>
                <div class="sc-kv"><span>Dernière paie calculée <i>آخر راتب محسوب</i></span><?= $i['last'] !== '' ? '<b class="nw">' . e($i['last']) . '</b>' : '<b class="sc-miss">—</b>' ?></div>
            </div>
            <div class="sc-sec sc-pay"><h4>Paie <?= e($sy) ?> — par catégorie <i>الرواتب والنسب والنقل لكل فئة</i></h4>
                <table class="sc-pt">
                    <tr><th></th><?php foreach ($CATS as $ck => $c): ?><th class="h-<?= $ck ?>"><?= e($c['fr']) ?><small><?= e($c['ar']) ?></small></th><?php endforeach; ?></tr>
                    <?php foreach (['prime_fixe' => ['Supplément', 'الإضافي'], 'transport_daily' => ['Transport / jour', 'نقل يومي'], 'transport_complement' => ['Transport / mois', 'نقل شهري']] as $bt => $lb): ?>
                    <tr><td><?= e($lb[0]) ?> <i><?= e($lb[1]) ?></i></td>
                        <?php foreach ($CATS as $ck => $c): $p = $i['pay'][$ck][$bt] ?? null; ?>
                        <td><?php if ($i['cnt'][$ck] === 0): ?><span class="sc-dim">—</span><?php elseif ($p): ?><b class="<?= $bt === 'prime_fixe' ? 'sc-big' : '' ?>"><?= e($p['label']) ?></b><?php if ($p['variants'] > 1 || $p['n'] < $i['cnt'][$ck]): ?><small><?= $p['n'] ?>/<?= $i['cnt'][$ck] ?><?= $p['variants'] > 1 ? ' · +' . ($p['variants'] - 1) . ' autres' : '' ?></small><?php endif; ?><?php else: ?><span class="sc-dim">—</span><?php endif; ?></td>
                        <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    <tr><td>13e mois <i>شهر 13</i></td><?php foreach ($CATS as $ck => $c): ?><td><?= $i['cnt'][$ck] === 0 ? '<span class="sc-dim">—</span>' : ($i['m13'][$ck] === $i['cnt'][$ck] ? '<b>Oui</b>' : ($i['m13'][$ck] === 0 ? '<b>Non</b>' : '<b>' . $i['m13'][$ck] . '/' . $i['cnt'][$ck] . '</b>')) ?></td><?php endforeach; ?></tr>
                </table>
            </div>
            <div class="sc-sec"><h4>Accès <i>الدخول</i></h4>
                <div class="sc-kv"><span>Compte(s) école <i>حساب</i></span><?= $i['users'] ? '<b>' . e(implode(' · ', array_map(fn($u) => $u['username'] . ((int)$u['is_active'] ? '' : ' (off)'), $i['users']))) . '</b>' : $miss ?></div>
                <div class="sc-kv"><span>Lecture seule <i>قراءة فقط</i></span><b><?= $i['users'] ? 'Oui' : '—' ?></b></div>
                <div class="sc-kv"><span>Années verrouillées <i>سنوات مقفولة</i></span><b><?= $i['locks'] ? e(implode(' · ', $i['locks'])) . ' 🔒' : '—' ?></b></div>
                <div class="sc-kv"><span>E-mail expéditeur <i>المرسِل</i></span><b><?= ($s['email_sender_mode'] ?? 'program') === 'school' ? 'École' : 'Programme' ?></b></div>
            </div>
        </div>
    </div>

    <div class="sc-edit no-print" id="scEdit<?= $sid ?>" style="display:none">
        <form method="post" class="sc-form">
            <?= csrfField() ?><input type="hidden" name="action" value="save_school"><input type="hidden" name="id" value="<?= $sid ?>"><input type="hidden" name="sy" value="<?= e($sy) ?>">
            <div class="sc-sec"><h4>Identité <i>المعلومات الرسمية</i></h4>
                <div class="sc-grid">
                    <div class="f"><label>Nom (FR) <i>الاسم بالفرنسي</i></label><input class="form-control" name="name_fr" value="<?= e($s['name_fr']) ?>"></div>
                    <div class="f"><label>Nom (AR) <i>الاسم بالعربي</i></label><input class="form-control" name="name_ar" dir="rtl" value="<?= e($s['name_ar']) ?>"></div>
                    <div class="f"><label>Code <i>الرمز</i></label><input class="form-control" name="code" value="<?= e((string)$s['code']) ?>"></div>
                    <div class="f"><label>N° CNSS employeur <i>ضمان</i></label><input class="form-control" name="nssf_employer_number" value="<?= e((string)$s['nssf_employer_number']) ?>"></div>
                    <div class="f"><label>N° Finances <i>مالية</i></label><input class="form-control" name="finance_number" value="<?= e((string)$s['finance_number']) ?>"></div>
                    <div class="f"><label>N° Caisse <i>صندوق</i></label><input class="form-control" name="caisse_number" value="<?= e((string)$s['caisse_number']) ?>"></div>
                    <div class="f"><label>Directeur(trice) (FR) <i>المدير</i></label><input class="form-control" name="director_name_fr" value="<?= e((string)$s['director_name_fr']) ?>"></div>
                    <div class="f"><label>Directeur(trice) (AR) <i>المدير بالعربي</i></label><input class="form-control" name="director_name" dir="rtl" value="<?= e((string)$s['director_name']) ?>"></div>
                    <div class="f"><label>Adresse (FR) <i>العنوان</i></label><input class="form-control" name="address_fr" value="<?= e((string)$s['address_fr']) ?>"></div>
                    <div class="f"><label>Adresse (AR) <i>العنوان بالعربي</i></label><input class="form-control" name="address" dir="rtl" value="<?= e((string)$s['address']) ?>"></div>
                    <div class="f"><label>Téléphone <i>الهاتف</i></label><input class="form-control" name="phone" value="<?= e((string)$s['phone']) ?>" placeholder="03-888849"></div>
                    <div class="f"><label>E-mail <i>البريد</i></label><input class="form-control" name="email" type="email" value="<?= e((string)$s['email']) ?>"></div>
                    <div class="f"><label>E-mail expéditeur <i>المرسِل</i></label><select class="form-select" name="email_sender_mode"><option value="program" <?= ($s['email_sender_mode'] ?? 'program') !== 'school' ? 'selected' : '' ?>>Programme / إيميل البرنامج</option><option value="school" <?= ($s['email_sender_mode'] ?? '') === 'school' ? 'selected' : '' ?>>École / إيميل المدرسة</option></select></div>
                    <div class="f"><label>&nbsp;</label><label class="sc-check"><input type="checkbox" name="is_active" value="1" <?= $act ? 'checked' : '' ?>> Active <i>مفعّلة</i></label></div>
                </div>
            </div>
            <div class="sc-save"><button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer / حفظ</button> <button type="button" class="btn btn-light" onclick="scEdit(<?= $sid ?>, true)">Annuler / إلغاء</button></div>
        </form>
        <?php if ($i['tot'] > 0): $locked = isSchoolYearLocked($sid, $sy); ?>
        <form method="post" class="sc-form sc-payform" onsubmit="return scConfirmPay(this)">
            <?= csrfField() ?><input type="hidden" name="action" value="apply_pay"><input type="hidden" name="id" value="<?= $sid ?>"><input type="hidden" name="sy" value="<?= e($sy) ?>">
            <div class="sc-sec"><h4>Paie <?= e($sy) ?> — appliquer par catégorie <i>الرواتب: طبّق لكل فئة</i></h4>
                <?php if ($locked): ?><p class="sc-note" style="color:#b45309">🔒 <?= e(yearLockedMsg($sid, $sy)) ?></p><?php else: ?>
                <p class="sc-note">Cochez la catégorie, remplissez ce qui change. <b>Vide = inchangé · 0 = retirer.</b> La valeur remplace la ligne « toute l'année » de chaque employé de la catégorie et les salaires <?= e($sy) ?> sont recalculés (sauf les salaires transférés manuellement). <span class="ar-sub">أشّر الفئة واكتب اللي بدّك تغيّره. فارغ = ما بيتغيّر · 0 = شيل. القيمة بتنزل على كل موظفي الفئة بهالمدرسة وبيتعاد حساب رواتبهم (المنقولون يدوياً لا).</span></p>
                <table class="sc-pt sc-ptedit">
                    <tr><th></th><?php foreach ($CATS as $ck => $c): ?><th class="h-<?= $ck ?>"><label class="sc-check"><input type="checkbox" name="apply[<?= $ck ?>]" value="1" <?= $i['cnt'][$ck] === 0 ? 'disabled' : '' ?>> <?= e($c['fr']) ?> (<?= $i['cnt'][$ck] ?>)<small><?= e($c['ar']) ?></small></label></th><?php endforeach; ?></tr>
                    <tr><td>Supplément <i>الإضافي</i></td><?php foreach ($CATS as $ck => $c): $p = $i['pay'][$ck]['prime_fixe'] ?? null; ?>
                        <td><div class="sc-in"><input class="form-control" name="pay[<?= $ck ?>][sup]" inputmode="decimal" placeholder="<?= $p ? e($p['label']) : '—' ?>" <?= $i['cnt'][$ck] === 0 ? 'disabled' : '' ?>>
                            <select class="form-select" name="pay[<?= $ck ?>][sup_vt]" onchange="this.nextElementSibling.style.display=this.value==='amount'?'':'none'"><option value="percent" <?= (!$p || $p['vt'] === 'percent') ? 'selected' : '' ?>>% de la base</option><option value="amount" <?= ($p && $p['vt'] === 'amount') ? 'selected' : '' ?>>Montant</option></select>
                            <select class="form-select" name="pay[<?= $ck ?>][sup_cur]" style="<?= ($p && $p['vt'] === 'amount') ? '' : 'display:none' ?>"><option value="LBP" <?= (!$p || $p['cur'] !== 'USD') ? 'selected' : '' ?>>L.L</option><option value="USD" <?= ($p && $p['cur'] === 'USD') ? 'selected' : '' ?>>$</option></select></div></td><?php endforeach; ?></tr>
                    <tr><td>Transport / jour (L.L) <i>نقل يومي</i></td><?php foreach ($CATS as $ck => $c): $p = $i['pay'][$ck]['transport_daily'] ?? null; ?><td><input class="form-control" name="pay[<?= $ck ?>][tj]" inputmode="numeric" placeholder="<?= $p ? e($p['label']) : '—' ?>" <?= $i['cnt'][$ck] === 0 ? 'disabled' : '' ?>></td><?php endforeach; ?></tr>
                    <tr><td>Transport / mois (L.L) <i>نقل شهري</i></td><?php foreach ($CATS as $ck => $c): $p = $i['pay'][$ck]['transport_complement'] ?? null; ?><td><input class="form-control" name="pay[<?= $ck ?>][tm]" inputmode="numeric" placeholder="<?= $p ? e($p['label']) : '—' ?>" <?= $i['cnt'][$ck] === 0 ? 'disabled' : '' ?>></td><?php endforeach; ?></tr>
                </table>
                <p class="sc-note">Montants individuels, périodes (de mois à mois), 13e mois et primes exceptionnelles : <a href="<?= BASE_URL ?>pages/bulk_allowances.php?sch=<?= $sid ?>&sch_set=1&sy=<?= e($sy) ?>">Primes &amp; transport (détail)</a>. <span class="ar-sub">المبالغ الفردية والفترات وشهر 13 من صفحة المكافآت المفصّلة.</span></p>
                <div class="sc-save"><button class="btn btn-success"><i class="fas fa-check"></i> Appliquer / طبّق</button></div>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
function scEdit(id, close) {
    var v = document.getElementById('scView' + id), ed = document.getElementById('scEdit' + id);
    var open = close ? false : (ed.style.display === 'none');
    ed.style.display = open ? '' : 'none'; v.style.display = open ? 'none' : '';
    if (open) ed.scrollIntoView({ block: 'start', behavior: 'smooth' });
}
function scConfirmPay(f) {
    var cats = { titulaire: 'Titulaires / ملاك', contractuel: 'Contractuels / متعاقد', employe: 'Employés / موظف' }, lines = [];
    Object.keys(cats).forEach(function (ck) {
        var on = f.querySelector('[name="apply[' + ck + ']"]'); if (!on || !on.checked) return;
        var sup = f.querySelector('[name="pay[' + ck + '][sup]"]').value.trim(), tj = f.querySelector('[name="pay[' + ck + '][tj]"]').value.trim(), tm = f.querySelector('[name="pay[' + ck + '][tm]"]').value.trim();
        var vt = f.querySelector('[name="pay[' + ck + '][sup_vt]"]').value, cur = f.querySelector('[name="pay[' + ck + '][sup_cur]"]').value;
        if (sup !== '') lines.push(cats[ck] + ' : Supplément = ' + (sup === '0' ? 'retiré' : sup + (vt === 'percent' ? ' %' : ' ' + (cur === 'USD' ? '$' : 'L.L'))));
        if (tj !== '') lines.push(cats[ck] + ' : Transport / jour = ' + (tj === '0' ? 'retiré' : tj + ' L.L'));
        if (tm !== '') lines.push(cats[ck] + ' : Transport / mois = ' + (tm === '0' ? 'retiré' : tm + ' L.L'));
    });
    if (!lines.length) { alert('Cochez une catégorie et remplissez une valeur / أشّر فئة واكتب قيمة'); return false; }
    return confirm('Appliquer à tous les employés de la catégorie et recalculer les salaires ?\nطبّق على كل موظفي الفئة وأعد حساب الرواتب؟\n\n' + lines.join('\n'));
}
(function () {
    var s = document.getElementById('scSearch'); if (!s) return;
    s.addEventListener('input', function () {
        var q = s.value.trim().toLowerCase();
        document.querySelectorAll('.sc-card[data-q]').forEach(function (c) { c.style.display = (!q || c.getAttribute('data-q').indexOf(q) !== -1) ? '' : 'none'; });
    });
    if (location.hash && /^#ecole-\d+$/.test(location.hash)) { var t = document.querySelector(location.hash); if (t) setTimeout(function () { t.scrollIntoView({ block: 'start' }); t.classList.add('sc-flash'); }, 150); }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
