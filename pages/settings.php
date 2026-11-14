<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$GLOBALS['msa_recalc_paid_ok'] = true; // 🔒 فعل صريح من المستخدم: يجوز إعادة حساب الأشهر المدفوعة لسنة سابقة (2026-09-23)
require_once __DIR__ . '/../includes/payroll_calculator.php'; // لإعادة الحساب عند تغيير سعر الصرف الافتراضي
requireLogin();
requireCsrf();

$currentPage = 'settings';
$pageTitle = 'Paramètres / الإعدادات';
$db = getDB();
$message = ''; $messageType = 'success';

// ===== تغيير كلمة المرور للمستخدم الحالي =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    $err = '';
    if (!$row) {
        $err = 'Utilisateur introuvable / المستخدم غير موجود';
    } elseif (!password_verify($current, $row['password_hash'])) {
        $err = 'Mot de passe actuel incorrect / كلمة المرور الحالية غير صحيحة';
    } elseif (strlen($new) < 6) {
        $err = 'Le nouveau mot de passe doit comporter au moins 6 caractères / كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل';
    } elseif ($new !== $confirm) {
        $err = 'La confirmation ne correspond pas / تأكيد كلمة المرور غير مطابق';
    }

    if ($err) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $err];
    } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $uid]);
        // 🏫 (2026-10-22) غيّرها صاحب الحساب بنفسه ⇒ لم تعد معروفة للمدير بصفحة «دخول المدارس»
        try { if (msaHasCol($db, 'users', 'pw_known')) $db->prepare("UPDATE users SET pw_known = NULL WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Mot de passe modifié avec succès / تم تغيير كلمة المرور بنجاح'];
    }
    header('Location: ' . BASE_URL . 'pages/settings.php');
    exit;
}

// ===== ⛽📅 (2026-11-14) قيمة النقل اليومي بالقانون لكل شهر («صار عم يتغيّر حسب سعر تنكة البنزين الشهري» + «رقم لكل الأشهر كمان») =====
//  سطر لكل شهر من السنة المختارة (ت1 ← أيلول) بجدول rate_history مفتاح transport_daily_lbp · فارغ = يأخذ آخر قيمة قبله.
//  بعد الحفظ: نقل الأشهر التي تغيّرت قيمتها يُعاد جراحياً لغير المدفوع فقط (transportResyncMonths) — المدفوع والسنة المقفولة لا يُمسّان.
$trSy = (string)($_GET['trsy'] ?? $_POST['trsy'] ?? '');
if (!preg_match('/^(\d{4})-(\d{4})$/', $trSy, $trM) || (int)$trM[2] !== (int)$trM[1] + 1) { $trSy = activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear(); preg_match('/^(\d{4})-(\d{4})$/', $trSy, $trM); }
$trYm = []; foreach ([10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9] as $mm_) $trYm[] = [($mm_ >= 10 ? (int)$trM[1] : (int)$trM[2]), $mm_];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_transport_rates') {
    if (!isAdmin()) { $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'صلاحية المدير مطلوبة / Accès administrateur requis']; header('Location: ' . BASE_URL . 'pages/settings.php'); exit; }
    $before = []; foreach ($trYm as [$y_, $m_]) $before[$y_ * 100 + $m_] = transportDailyLawForMonth($m_, $y_);
    $in = (array)($_POST['tr_rate'] ?? []); $bad = false; $vals = [];
    foreach ($trYm as [$y_, $m_]) {
        $raw = trim((string)($in[$y_ * 100 + $m_] ?? ''));
        if ($raw === '') continue;
        $v = numLoose($raw);
        if ($v <= 0 || $v > 100000000) { $bad = true; break; }
        $vals[$y_ * 100 + $m_] = round($v);
    }
    if ($bad) { $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'قيمة غير صالحة — اكتب رقماً بالليرة أكبر من صفر، أو اترك الخانة فارغة. / Valeur invalide.']; }
    else {
        $from = sprintf('%04d-10-01', (int)$trM[1]); $to = sprintf('%04d-09-30', (int)$trM[2]);
        $old = $db->prepare("SELECT effective_from, value FROM rate_history WHERE param_key = 'transport_daily_lbp' AND effective_from BETWEEN ? AND ? ORDER BY effective_from"); $old->execute([$from, $to]);
        $oldRows = $old->fetchAll(PDO::FETCH_ASSOC);
        $db->prepare("DELETE FROM rate_history WHERE param_key = 'transport_daily_lbp' AND effective_from BETWEEN ? AND ?")->execute([$from, $to]);
        $ins = $db->prepare("INSERT INTO rate_history (param_key, value, effective_from, effective_to, notes) VALUES ('transport_daily_lbp', ?, ?, NULL, ?)");
        foreach ($vals as $ym => $v) $ins->execute([$v, sprintf('%04d-%02d-01', intdiv($ym, 100), $ym % 100), 'النقل اليومي بالقانون — ' . $trSy]);
        transportDailyLawForMonth(0, 0, true); transportLawValues(true);
        $changed = []; foreach ($trYm as [$y_, $m_]) if (abs(transportDailyLawForMonth($m_, $y_) - $before[$y_ * 100 + $m_]) > 0.001) $changed[] = $y_ * 100 + $m_;
        $res = transportResyncMonths($db, $changed);
        logAudit('transport_daily_rates', 'rate_history', 0, $oldRows, ['sy' => $trSy, 'values' => $vals, 'months_changed' => $changed, 'resync' => $res]);
        $msg = '✅ حُفظت قيمة النقل اليومي لسنة ' . $trSy . '. ';
        $msg .= $changed ? ('تغيّرت قيمة ' . count($changed) . ' شهراً ⇒ عُدّل نقل ' . $res['changed'] . ' راتباً شهرياً عند ' . $res['people'] . ' شخصاً (النقل والمستحق فقط).') : 'لا شهر تغيّرت قيمته — لم يُمسّ أي راتب.';
        if ($res['paid']) $msg .= ' 🔒 ' . $res['paid'] . ' راتباً مدفوعاً لم يُمسّ.';
        if ($res['locked']) $msg .= ' 🔒 ' . $res['locked'] . ' راتباً بسنة مقفولة لم يُمسّ.';
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg . ' / Transport journalier enregistré.'];
    }
    header('Location: ' . BASE_URL . 'pages/settings.php?trsy=' . urlencode($trSy) . '#trRates'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔒 صلاحية: هذه إعدادات عامة تمسّ كل المدارس (سعر الصرف الافتراضي يعيد حساب الرواتب
    // من 2017 لكل المدارس) — للمدير فقط.
    if (!isAdmin()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'صلاحية المدير مطلوبة / Accès administrateur requis'];
        header('Location: ' . BASE_URL . 'pages/settings.php');
        exit;
    }
    // 🔴 قائمة بيضاء (كان `foreach ($_POST ...)` يكتب **أي** حقل مُرسَل كإعداد عام —
    // فيُخزَّن توكن الـcsrf كإعداد، ويمكن لأي حقل زائد أن يطمس مفاتيح حسّاسة مثل
    // teacher_form_deadline أو علامات الشفاء الذاتي yr_additions_backfilled_*).
    $allowedSettings = ['current_exchange_rate', 'current_school_year', 'grades_baseline_year',
                        'minimum_wage_lbp', 'school_name_ar', 'school_name_fr', 'school_address',
                        'school_phone', 'teacher_form_deadline', 'teacher_form_allow_after',
                        'transport_start_month', 'transport_end_month', 'official_usd_rate_lbp',
                        'hours_reduction_presence_factor']; // ⏱️ معامل حضور التناقص (1.5)
    $oldRate = getSetting('current_exchange_rate'); // لرصد تغيّر سعر الصرف الافتراضي
    // لرصد تغيّر نافذة أشهر النقل (✍️ 2026-08-25: «إذا بدي عدل بعدل» — التعديل يعيد الحساب تلقائياً)
    $oldTrWin = getSetting('transport_start_month', '10') . '-' . getSetting('transport_end_month', '6');
    // لرصد تغيّر السعر الرسمي لقاعدة نسبة الإضافي (✍️ 2026-08-28: «بدي 1500 يكون عندي خيار عدلها»)
    $oldOfficial = (float)getSetting('official_usd_rate_lbp', 1500);
    foreach ($_POST as $k => $v) {
        if (!in_array($k, $allowedSettings, true) || is_array($v)) continue;
        setSetting($k, trim((string)$v));
    }
    // سعر الصرف الافتراضي fallback أخير لرواتب بلا سعر شهري — أعِد الحساب تلقائياً إن تغيّر
    $msg = 'Paramètres enregistrés / تم حفظ الإعدادات';
    if (isset($_POST['current_exchange_rate']) && (float)$oldRate !== (float)$_POST['current_exchange_rate']) {
        $nRec = recalcSalariesInRange($db, '2017-08-01', null);
        $msg = "تم الحفظ وإعادة حساب الرواتب المتأثّرة بسعر الصرف ($nRec). / Paramètres enregistrés, salaires recalculés.";
    }
    // نافذة أشهر النقل تغيّرت ⇒ إعادة حساب رواتب سريان القانون (من تشرين الأول 2026) تلقائياً
    $newTrWin = getSetting('transport_start_month', '10') . '-' . getSetting('transport_end_month', '6');
    if ($newTrWin !== $oldTrWin) {
        $nRec2 = recalcSalariesInRange($db, '2026-10-01', null);
        $msg .= " + أشهر النقل: أُعيد حساب الرواتب المتأثّرة ($nRec2). / Mois du transport modifiés, salaires recalculés.";
    }
    // ✍️ (2026-08-28) السعر الرسمي (قاعدة نسبة الإضافي ÷1500) تغيّر ⇒ إعادة حساب أصحاب النسب تلقائياً
    $newOfficial = (float)getSetting('official_usd_rate_lbp', 1500);
    if (isset($_POST['official_usd_rate_lbp']) && $newOfficial > 0 && $newOfficial !== $oldOfficial) {
        @set_time_limit(0);
        require_once __DIR__ . '/../includes/payroll_calculator.php';
        $pctEmps = $db->query("SELECT DISTINCT b.employee_id, b.school_year FROM employee_bonuses b
            JOIN employees e ON e.id = b.employee_id AND e.is_deleted = 0
            WHERE b.is_active = 1 AND b.value_type = 'percent'")->fetchAll(PDO::FETCH_ASSOC);
        $nRec3 = 0;
        foreach ($pctEmps as $pe) {
            $sy3 = $pe['school_year'] ?: currentSchoolYear();
            if (recalcEmployeeYear((int)$pe['employee_id'], $sy3) > 0) $nRec3++;
        }
        $msg .= " + السعر الرسمي صار " . nfc($newOfficial, 2, true) . ": أُعيد حساب أصحاب النسبة المئوية ($nRec3). / Taux officiel modifié, salaires en % recalculés.";
    }
    $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
    header('Location: ' . BASE_URL . 'pages/settings.php');
    exit;
}

if (!empty($_SESSION['flash'])) {
    $message = $_SESSION['flash']['msg'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div><?php endif; ?>

<?php if (canEdit()): ?>
<form method="POST" class="lockedit">
    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-calendar"></i> Paramètres généraux</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">الإعدادات العامة</div>
        </h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Année scolaire actuelle / السنة الدراسية</label>
                <input type="text" name="current_school_year" class="form-control" value="<?= e(getSetting('current_school_year')) ?>" placeholder="2025-2026">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-bus"></i> Mois du transport (enseignants)</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">أشهر تعويض النقل (للأساتذة)</div>
        </h3></div>
        <div class="card-body">
            <?php // ✍️ (2026-08-25) «النقل من تشرين الأول لحزيران ضمناً — وإذا بدي عدل بعدل»:
                  // نافذة أشهر النقل للملاك والمتعاقدين (الموظف الإداري يداوم الصيف فنقله كل السنة).
                  // سارية من 2026-2027 — سنة 2025-2026 المدفوعة لا تتأثّر.
                  $trMonths = [10=>'Octobre / تشرين الأول',11=>'Novembre / تشرين الثاني',12=>'Décembre / كانون الأول',
                               1=>'Janvier / كانون الثاني',2=>'Février / شباط',3=>'Mars / آذار',4=>'Avril / نيسان',
                               5=>'Mai / أيار',6=>'Juin / حزيران',7=>'Juillet / تموز',8=>'Août / آب',9=>'Septembre / أيلول'];
                  $trS = max(1, min(12, (int)getSetting('transport_start_month', 10)));
                  $trE = max(1, min(12, (int)getSetting('transport_end_month', 6))); ?>
            <div class="form-row cols-2">
                <div class="form-group mb-0">
                    <label class="form-label">Du mois / من شهر</label>
                    <select name="transport_start_month" class="form-select">
                        <?php foreach ($trMonths as $mv=>$ml): ?><option value="<?= $mv ?>" <?= $mv===$trS?'selected':'' ?>><?= $ml ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Au mois (inclus) / إلى شهر (ضمناً)</label>
                    <select name="transport_end_month" class="form-select">
                        <?php foreach ($trMonths as $mv=>$ml): ?><option value="<?= $mv ?>" <?= $mv===$trE?'selected':'' ?>><?= $ml ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <small class="text-muted">للملاك والمتعاقدين — خارج هذه الأشهر لا يُدفع تعويض نقل (الافتراضي: تشرين الأول → حزيران = 9 أشهر). الموظفون الإداريون نقلهم كل السنة. يسري من 2026-2027.</small>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>
            <span dir="ltr"><i class="fas fa-coins"></i> Taux de change actuel</span>
            <div style="font-size:0.85em;font-weight:600;opacity:0.9">سعر الصرف الحالي</div>
        </h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Taux de change actuel (par défaut) / سعر الصرف الحالي (افتراضي)</label>
                <input type="number" name="current_exchange_rate" class="form-control" value="<?= e(getSetting('current_exchange_rate')) ?>" step="0.01">
                <small class="text-muted"><?= $lang==='ar'?'الأسعار الشهرية المفصّلة في صفحة «Taux de change».':'Les taux mensuels détaillés sont dans la page « Taux de change ».' ?></small>
            </div>
            <div class="form-group">
                <label class="form-label">Taux officiel ancien (règle du % supplément) / السعر الرسمي القديم (1500) — قاعدة نسبة الأجر الإضافي</label>
                <input type="number" name="official_usd_rate_lbp" class="form-control" value="<?= e(getSetting('official_usd_rate_lbp', 1500)) ?>" step="0.01">
                <small class="text-muted">🔴 <strong>القاعدة فيها سعران معاً:</strong> ① <strong>هذا السعر (القديم 1500)</strong> — فقط لتحويل أساس الراتب بعد التدرّج إلى دولار حتى يطلع الإضافي بالدولار (÷1500 × النسبة٪ ← داون للدولار). ② <strong>السعر الجديد (89,500)</strong> — لتحويل الإضافي الدولاري رجوعاً إلى الليرة (× سعر صرف شهر الراتب من صفحة «أسعار الصرف» ← داون للمليون). <strong>تغيير أيّ منهما يعيد حساب أصحاب النسبة تلقائياً.</strong></small>
            </div>
            <div class="form-group">
                <label class="form-label">Présence par heure de réduction / ساعات الحضور مقابل كل ساعة تناقص</label>
                <input type="number" name="hours_reduction_presence_factor" class="form-control" value="<?= e(getSetting('hours_reduction_presence_factor', 1.5)) ?>" step="0.1" min="0.1">
                <small class="text-muted">⏱️ <strong>كل ساعة تناقص = ساعة ونصف حضور</strong> (افتراضي 1,5): أستاذ عنده 4 ساعات تناقص ← ساعات حضور التناقص 6. تُحسب تلقائياً بملف الأستاذ وببطاقة الرواتب من ساعات التناقص المسجّلة.</small>
            </div>
            <div class="d-flex justify-end" style="margin-top:8px">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Enregistrer / حفظ
                </button>
            </div>
        </div>
    </div>
</form>

<?php // ⛽📅 (2026-11-14) قيمة النقل اليومي بالقانون — سطر لكل شهر + «طبّق على كل أشهر السنة» + تعديل/حفظ/إلغاء (lockedit). «مرتّب ورسمي وبدون عجقة»: ثلاثة أعمدة وسطر شرح واحد.
$trStored = []; $trQ = $db->prepare("SELECT DATE_FORMAT(effective_from, '%Y%m') ym, value FROM rate_history WHERE param_key = 'transport_daily_lbp' AND effective_from BETWEEN ? AND ?");
try { $trQ->execute([sprintf('%04d-10-01', (int)$trM[1]), sprintf('%04d-09-30', (int)$trM[2])]); foreach ($trQ->fetchAll(PDO::FETCH_ASSOC) as $r_) $trStored[(int)$r_['ym']] = (float)$r_['value']; } catch (Throwable $e) {}
$trSys = []; foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE school_year >= '2026-2027' ORDER BY school_year DESC") as $r_) $trSys[] = (string)$r_['school_year'];
if (!in_array($trSy, $trSys, true)) { $trSys[] = $trSy; rsort($trSys); }
$trPaid = []; foreach ($db->query("SELECT (year * 100 + month) ym, SUM(COALESCE(is_paid,0) = 1) p FROM monthly_salaries WHERE school_year = " . $db->quote($trSy) . " AND transport_lbp > 0 GROUP BY 1") as $r_) $trPaid[(int)$r_['ym']] = (int)$r_['p'];
$trAr = $lang === 'ar';
?>
<div class="card" id="trRates">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-gas-pump"></i> Indemnité de transport journalière — par mois</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">تعويض النقل اليومي — لكل شهر</div>
    </h3></div>
    <div class="card-body">
        <form method="get" class="no-print" style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
            <label class="form-label" style="margin:0"><?= $trAr ? 'السنة الدراسية' : 'Année scolaire' ?></label>
            <select name="trsy" class="form-select" style="width:auto" onchange="this.form.submit()"><?php foreach ($trSys as $y_): ?><option value="<?= e($y_) ?>" <?= $y_ === $trSy ? 'selected' : '' ?>><?= e($y_) ?></option><?php endforeach; ?></select>
        </form>
        <form method="POST" class="lockedit" id="trRatesForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_transport_rates"><input type="hidden" name="trsy" value="<?= e($trSy) ?>">
            <table class="table" style="max-width:720px">
                <thead><tr>
                    <th><?= $trAr ? 'الشهر' : 'Mois' ?></th>
                    <th><?= $trAr ? 'النقل اليومي (ل.ل)' : 'Transport par jour (L.L)' ?></th>
                    <th><?= $trAr ? 'نقل الشهر — أستاذ 5 أيام' : 'Transport du mois — 5 jours' ?></th>
                </tr></thead>
                <tbody>
                    <tr style="background:#f1f5f9">
                        <td><strong><?= $trAr ? 'كل أشهر السنة' : "Toute l'année" ?></strong></td>
                        <td><input type="text" inputmode="numeric" id="trAllVal" class="form-control" style="max-width:200px" autocomplete="off"></td>
                        <td><button type="button" class="btn btn-primary btn-sm" id="trAllBtn"><?= $trAr ? 'طبّق على كل الأشهر' : 'Appliquer à tous les mois' ?></button></td>
                    </tr>
                <?php foreach ($trYm as [$y_, $m_]): $ym_ = $y_ * 100 + $m_; $eff_ = transportDailyLawForMonth($m_, $y_); $has_ = isset($trStored[$ym_]); ?>
                    <tr>
                        <td><?= e(monthName($m_, $trAr ? 'ar' : 'fr')) ?> <?= $y_ ?><?= !empty($trPaid[$ym_]) ? ' <span title="' . ($trAr ? 'رواتب مدفوعة لا تتغيّر' : 'Salaires payés : inchangés') . '">🔒</span>' : '' ?></td>
                        <td><input type="text" inputmode="numeric" name="tr_rate[<?= $ym_ ?>]" class="form-control tr-rate" style="max-width:200px" value="<?= $has_ ? e(number_format($trStored[$ym_])) : '' ?>" placeholder="<?= e(number_format($eff_)) ?>" autocomplete="off"></td>
                        <td class="tr-month"><?= e(number_format($eff_ * 20)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <small class="text-muted"><?= $trAr
                ? 'القيمة واحدة لكل المدارس. نقل الأستاذ = قيمة الشهر × أيام دوامه × 4 أسابيع. الخانة الفارغة تأخذ قيمة الشهر الذي قبلها. الشهر المدفوع 🔒 لا يتغيّر.'
                : "Valeur unique pour toutes les écoles. Transport = valeur du mois × jours de présence × 4 semaines. Une case vide reprend la valeur du mois précédent. Un mois payé 🔒 ne change pas." ?></small>
            <div class="d-flex justify-end" style="margin-top:10px;gap:8px">
                <button type="button" class="btn btn-secondary tr-cancel" onclick="location.reload()"><i class="fas fa-rotate-left"></i> <?= $trAr ? 'إلغاء' : 'Annuler' ?></button>
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Enregistrer / حفظ</button>
            </div>
        </form>
        <style>#trRatesForm .tr-cancel[disabled] { display: none; }</style>
        <script>
        (function () {
            var f = document.getElementById('trRatesForm'); if (!f) return;
            var fmt = function (v) { v = String(v).replace(/[^\d]/g, ''); return v ? Number(v).toLocaleString('en-US') : ''; };
            var all = document.getElementById('trAllVal');
            all.addEventListener('blur', function () { all.value = fmt(all.value); });
            f.querySelectorAll('.tr-rate').forEach(function (i) { i.addEventListener('blur', function () { i.value = fmt(i.value); }); });
            document.getElementById('trAllBtn').addEventListener('click', function () {
                var v = fmt(all.value); if (!v) { all.focus(); return; }
                f.querySelectorAll('.tr-rate').forEach(function (i) { if (!i.disabled && !i.readOnly) { i.value = v; i.dispatchEvent(new Event('input', { bubbles: true })); } });
            });
            f.addEventListener('submit', function () { var b = f.querySelectorAll('button[type=submit], .lockedit-save'); b.forEach(function (x) { x.style.opacity = .6; }); });
        })();
        </script>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-percent"></i> Taux &amp; Pourcentages (lois)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">النِّسَب والقيم القانونية</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <?= $lang === 'ar'
                ? 'النِّسَب والقيم التي تتغيّر من سنة لسنة (الضمان، الصندوق، نهاية الخدمة، الحد الأدنى للأجور) تُدار الآن مع تواريخها في صفحة '
                : 'Les taux et valeurs qui changent d\'année en année (CNSS, EOC, fin de service, salaire minimum) se gèrent désormais avec leurs dates dans la page ' ?>
            <a href="<?= BASE_URL ?>pages/rates_history.php"><strong>Taux datés / النِّسَب حسب التاريخ</strong></a>،
            <?= $lang === 'ar' ? 'والحدود الدنيا/القصوى في ' : 'et les plafonds/planchers dans ' ?>
            <a href="<?= BASE_URL ?>pages/social_security.php"><strong>Plafonds CNSS / حدود الضمان</strong></a>.
        </div>
        <table class="table">
            <thead><tr><th>Valeur / القيمة</th><th>Actuel / الساري حالياً</th></tr></thead>
            <tbody>
            <?php foreach (ratedParams() as $key => $info):
                $cur = getRateAsOf($key, (int)date('n'), (int)date('Y'));
                $disp = ($info['unit'] === 'lbp') ? formatLBP($cur) : (nfc($cur, 4, true).' %'); ?>
                <tr><td><?= e($info[$lang==='ar'?'ar':'fr']) ?></td><td><strong><?= $disp ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; /* canEdit — نهاية البطاقات القابلة للتعديل */ ?>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-key"></i> Mot de passe</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">كلمة المرور</div>
    </h3></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:16px;">
            <?= $lang==='ar'
                ? 'تغيير كلمة المرور للمستخدم الحالي: '.e($_SESSION['username'] ?? '')
                : 'Changer le mot de passe de l\'utilisateur actuel : '.e($_SESSION['username'] ?? '') ?>
        </p>
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label class="form-label">Mot de passe actuel / كلمة المرور الحالية</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label class="form-label">Nouveau mot de passe / كلمة المرور الجديدة</label>
                <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                <small class="text-muted"><?= $lang==='ar'?'6 أحرف على الأقل.':'6 caractères minimum.' ?></small>
            </div>
            <div class="form-group">
                <label class="form-label">Confirmer le nouveau mot de passe / تأكيد كلمة المرور الجديدة</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
            <div class="d-flex justify-end">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-key"></i> Changer le mot de passe / تغيير كلمة المرور
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
