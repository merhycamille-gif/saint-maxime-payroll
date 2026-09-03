<?php
/**
 * 🕐 تناقص عدد ساعات التدريس الأسبوعية للأستاذ الملاك حسب سنوات الخدمة
 * المرسوم رقم 2601 تاريخ 27/3/2018 (الجريدة الرسمية العدد 15 — 5/4/2018) المعدِّل للمرسومين
 * 5343 (5/11/2010) و784 (6/7/1983) — من ملفَي المستخدم: Desktop\قانون تناقص الساعات.jpg + تناقص ص 1.jpg
 *
 * ⚠️ لا يغيّر أي حساب راتب. خانتا «ساعات بالأسبوع» (ساعات الملاك) و«ساعات التناقص» المخزّنتان
 *    لا تُكتبان إلا **بإذن المستخدم الصريح** (زرّ «موافق» بمساج «قرار مطلوب» — طلبه 2026-09-03):
 *    كل أستاذ يصير عنده تناقص جديد ببداية السنة المعروضة يظهر بمساج بكل مدرسة (لوحة القيادة + صفحة
 *    التناقص + ملفه) يطلب الإذن ليسجّل له: ساعات الملاك الجديدة (= الأساس − التناقص) وساعات التناقص.
 *
 * القاعدة: التناقص التدريجي يُطبَّق بعد خدمة فعلية في الملاك الدائم لا تقل عن:
 *   16 سنة في التعليم الثانوي (الجدول 1) · 20 سنة في الروضة والتعليم الأساسي (الجدولان 2 و3).
 * «خلال سنة الخدمة N» تُحسب بالسنوات الدراسية: سنة دخول الملاك الدراسية = سنة الخدمة 1.
 */

/** جداول المرسوم الثلاثة: base = الساعات الأسبوعية الكاملة قبل التناقص، steps = [منN, إلىN, الساعات] */
function hoursReductionLawTables() {
    return [
        1 => ['label' => 'التعليم الثانوي', 'label_fr' => 'Secondaire', 'minYears' => 16, 'base' => 20,
              'steps' => [[17, 19, 19], [20, 22, 18], [23, 24, 17], [25, 26, 16], [27, 28, 15], [29, 999, 14]]],
        2 => ['label' => 'الحلقة الثالثة (المتوسط)', 'label_fr' => 'Cycle 3 (Intermédiaire)', 'minYears' => 20, 'base' => 24,
              'steps' => [[21, 23, 23], [24, 25, 22], [26, 27, 21], [28, 29, 20], [30, 31, 19], [32, 32, 18], [33, 999, 17]]],
        3 => ['label' => 'الروضة والحلقتان الأولى والثانية', 'label_fr' => 'Maternelle + Cycles 1-2', 'minYears' => 20, 'base' => 27,
              'steps' => [[21, 23, 26], [24, 25, 25], [26, 27, 24], [28, 29, 23], [30, 30, 22], [31, 31, 21], [32, 32, 20], [33, 999, 19]]],
    ];
}

/**
 * تحديد الجدول المنطبق على الأستاذ — سلّم استدلال:
 *  ١) خانة «المرحلة» (niveau_scolaire) إن كانت معبّأة (الأعلى يغلب: ثانوي > متوسط > ابتدائي/حضانة)
 *  ٢) «الصفوف التي يعلّم فيها» (classes_taught: ids ‏1-9 روضة/حلقتان · 10-12 حلقة ثالثة · 13-15 ثانوي)
 *  ٣) افتراضي حسب المدرسة: اسمها فيه «ثانوية» → الجدول 1، وإلا الجدول 3 — مع وسم assumed=true
 * يرجع [tableNo, source_label, assumed].
 */
function hoursReductionResolveStage(array $emp, $schoolName = '') {
    $niv = array_filter(array_map('trim', explode(',', (string)($emp['niveau_scolaire'] ?? ''))));
    if ($niv) {
        if (in_array('secondaire', $niv, true)) return [1, 'مرحلته المحدّدة بملفه', false];
        if (in_array('intermediaire', $niv, true)) return [2, 'مرحلته المحدّدة بملفه', false];
        return [3, 'مرحلته المحدّدة بملفه', false];
    }
    $cls = array_filter(array_map('intval', explode(',', (string)($emp['classes_taught'] ?? ''))));
    if ($cls) {
        $mx = max($cls);
        if ($mx >= 13) return [1, 'الصفوف التي يعلّم فيها', false];
        if ($mx >= 10) return [2, 'الصفوف التي يعلّم فيها', false];
        return [3, 'الصفوف التي يعلّم فيها', false];
    }
    if (mb_strpos((string)$schoolName, 'ثانوية') !== false) return [1, 'افتراضي حسب المدرسة (ثانوية)', true];
    return [3, 'افتراضي حسب المدرسة', true];
}

/**
 * حساب التناقص لأستاذ ملاك بسنة دراسية معيّنة. يرجع null إذا غير منطبق (غير ملاك / بلا تاريخ ملاك)،
 * وإلا: serviceYear، table، stageLabel، source، assumed، baseHours، lawHours، reduction، startSy
 * (startSy = أول سنة دراسية يبدأ فيها تناقصه — مفيدة لمن لم يبلغ العتبة بعد، reduction=0 عندها).
 */
function hoursReductionFor(array $emp, $sy, $schoolName = '') {
    if (($emp['employee_type'] ?? '') !== 'enseignant_titulaire') return null;
    $td = substr((string)($emp['titularization_date'] ?? ''), 0, 10);
    if (!$td || !preg_match('/^(\d{4})-(\d{2})/', $td, $m)) return null;
    $syStart = (int)substr((string)$sy, 0, 4);
    if ($syStart < 1900) return null;
    $malakStart = ((int)$m[2] >= 10) ? (int)$m[1] : (int)$m[1] - 1;
    $serviceYear = $syStart - $malakStart + 1;
    if ($serviceYear < 1) return null;
    [$tNo, $source, $assumed] = hoursReductionResolveStage($emp, $schoolName);
    $t = hoursReductionLawTables()[$tNo];
    $lawHours = $t['base']; $reduction = 0;
    foreach ($t['steps'] as [$from, $to, $h]) {
        if ($serviceYear >= $from && $serviceYear <= $to) { $lawHours = $h; $reduction = $t['base'] - $h; break; }
    }
    $firstN = $t['steps'][0][0]; // أول سنة خدمة فيها تناقص
    $startYr = $malakStart + $firstN - 1;
    return [
        'serviceYear' => $serviceYear, 'table' => $tNo,
        'stageLabel' => $t['label'], 'stageLabelFr' => $t['label_fr'],
        'source' => $source, 'assumed' => $assumed,
        'baseHours' => $t['base'], 'lawHours' => $lawHours, 'reduction' => $reduction,
        'startSy' => $startYr . '-' . ($startYr + 1),
    ];
}

/**
 * لائحة أساتذة الملاك الذين لهم تناقص بالسنة المعروضة، ضمن نطاق مدرسة المستخدم،
 * مجمّعة لكل مدرسة: ['اسم المدرسة' => [ ['emp'=>صف الموظف, 'hr'=>نتيجة hoursReductionFor], ... ]]
 */
function hoursReductionList($db, $sy) {
    [$yf, $yp] = yearEmploymentFilter($sy, 'e.');
    $st = $db->prepare("SELECT e.*, s.name_ar school_name FROM employees e JOIN schools s ON s.id = e.school_id
        WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire'" . schoolScopeSql('e.school_id') . $yf . "
        ORDER BY s.name_ar, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr)");
    $st->execute($yp);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $hr = hoursReductionFor($e, $sy, $e['school_name']);
        if ($hr && $hr['reduction'] > 0) $out[$e['school_name']][] = ['emp' => $e, 'hr' => $hr];
    }
    return $out;
}

/* =====================================================================
 * 🔔 «قرار مطلوب» — تسجيل التناقص بإذن المستخدم (طلبه 2026-09-03)
 * ===================================================================== */

/** تركيب ذاتي لأعمدة التسجيل (بلا أي خطوة يدوية أونلاين): hours_reduction + hours_reduction_sy + hours_reduction_later_sy. */
function hoursReductionEnsureColumns($db) {
    static $done = false; if ($done) return; $done = true;
    try {
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'hours_reduction'")->fetch())
            $db->exec("ALTER TABLE employees ADD COLUMN hours_reduction DECIMAL(4,1) NOT NULL DEFAULT 0");
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'hours_reduction_sy'")->fetch())
            $db->exec("ALTER TABLE employees ADD COLUMN hours_reduction_sy VARCHAR(9) NULL");
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'hours_reduction_later_sy'")->fetch())
            $db->exec("ALTER TABLE employees ADD COLUMN hours_reduction_later_sy VARCHAR(9) NULL");
    } catch (Exception $e) { /* صلاحيات → الصفحات محصّنة بـ ?? */ }
}

/**
 * هل يحتاج هذا الأستاذ إذناً بالسنة $sy؟ (دالة صافية بلا قاعدة بيانات — مقفولة بفحص regression)
 * القاعدة: له تناقص بالقانون هذه السنة، والمسجّل بملفه لا يطابقه (ساعات الملاك ≠ القانونية أو
 * التناقص المسجّل ≠ القانوني) ولم يقل «لاحقاً» لهذه السنة. فمن سُجِّل له السنة الماضية ولم تتغيّر
 * ساعاته هذه السنة لا يظهر — يظهر فقط من **صار** عنده تناقص جديد أو تناقص أكبر ببداية السنة.
 */
function hoursReductionNeedsDecision(array $emp, $hr, $sy) {
    if (!$hr || (int)$hr['reduction'] <= 0) return false;
    if ((string)($emp['hours_reduction_later_sy'] ?? '') === (string)$sy) return false;
    $storedRed = (float)($emp['hours_reduction'] ?? 0);
    $storedHpw = (float)($emp['hours_per_week'] ?? 0);
    return abs($storedRed - (float)$hr['reduction']) > 0.01 || abs($storedHpw - (float)$hr['lawHours']) > 0.01;
}

/**
 * الأساتذة الذين ينتظرون إذن المستخدم بالسنة $sy، مجمّعون لكل مدرسة (ضمن نطاق المستخدم):
 * ['اسم المدرسة' => [ ['emp'=>..., 'hr'=>...], ... ]]
 */
function hoursReductionPending($db, $sy) {
    hoursReductionEnsureColumns($db);
    $out = [];
    try {
        [$yf, $yp] = yearEmploymentFilter($sy, 'e.');
        $st = $db->prepare("SELECT e.*, s.name_ar school_name FROM employees e JOIN schools s ON s.id = e.school_id
            WHERE e.is_deleted = 0 AND e.status = 'actif' AND e.employee_type = 'enseignant_titulaire'" . schoolScopeSql('e.school_id') . $yf . "
            ORDER BY s.name_ar, COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr), COALESCE(NULLIF(e.last_name_ar,''), e.last_name_fr)");
        $st->execute($yp);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $hr = hoursReductionFor($e, $sy, $e['school_name']);
            if (hoursReductionNeedsDecision($e, $hr, $sy)) $out[$e['school_name']][] = ['emp' => $e, 'hr' => $hr];
        }
    } catch (Exception $e) { $out = []; }
    return $out;
}

/**
 * معالج الإذن (POST): hr_apply (emp_id واحد أو emp_ids[] لمدرسة كاملة) يسجّل ساعات الملاك = القانونية
 * وساعات التناقص = القانونية للسنة المعروضة؛ hr_later يؤجّل المساج لهذه السنة فقط بلا أي تغيير.
 * لا يلمس أي راتب (ساعات الملاك لا تدخل بحساب راتب الملاك). محميّ بـCSRF + canEdit + نطاق المدرسة.
 */
function handleHoursReductionPost($db, $redirectTo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['action'] ?? '', ['hr_apply', 'hr_later'], true)) return;
    requireCsrf();
    // رجوع لصفحة داخلية (ملف الأستاذ) إن طُلب — مسار داخلي فقط
    $rt = (string)($_POST['return_to'] ?? '');
    if ($rt !== '' && preg_match('~^[a-z_]+\.php(\?[A-Za-z0-9_=&%-]*)?(#[A-Za-z0-9_-]+)?$~', $rt)) $redirectTo = BASE_URL . 'pages/' . $rt;
    if (!canEdit()) { $_SESSION['flash_error'] = 'غير مسموح — حساب قراءة فقط.'; header('Location: ' . $redirectTo); exit; }
    hoursReductionEnsureColumns($db);
    $sy = activeSchoolYear(); if ($sy === 'all') $sy = currentSchoolYear();
    $ids = [];
    if (!empty($_POST['emp_ids']) && is_array($_POST['emp_ids'])) $ids = array_map('intval', $_POST['emp_ids']);
    elseif (!empty($_POST['emp_id'])) $ids = [(int)$_POST['emp_id']];
    $ids = array_values(array_filter(array_unique($ids)));
    $done = 0; $names = [];
    foreach ($ids as $eid) {
        $st = $db->prepare("SELECT e.*, s.name_ar school_name FROM employees e JOIN schools s ON s.id = e.school_id
            WHERE e.id = ? AND e.is_deleted = 0" . schoolScopeSql('e.school_id'));
        $st->execute([$eid]);
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$emp) continue;
        $nm = trim((($emp['first_name_ar'] ?: $emp['first_name_fr']) . ' ' . ($emp['last_name_ar'] ?: $emp['last_name_fr'])));
        if ($_POST['action'] === 'hr_later') {
            $db->prepare("UPDATE employees SET hours_reduction_later_sy = ? WHERE id = ?")->execute([$sy, $eid]);
            logAudit('hours_reduction_later', 'employees', $eid, null, ['sy' => $sy]);
            $done++; $names[] = $nm; continue;
        }
        $hr = hoursReductionFor($emp, $sy, $emp['school_name']);
        if (!$hr || (int)$hr['reduction'] <= 0) continue;
        $old = ['hours_per_week' => $emp['hours_per_week'], 'hours_reduction' => $emp['hours_reduction'] ?? 0];
        $new = ['hours_per_week' => (float)$hr['lawHours'], 'hours_reduction' => (float)$hr['reduction'], 'sy' => $sy, 'serviceYear' => $hr['serviceYear'], 'table' => $hr['table']];
        $db->prepare("UPDATE employees SET hours_per_week = ?, hours_reduction = ?, hours_reduction_sy = ?, hours_reduction_later_sy = NULL WHERE id = ?")
           ->execute([$new['hours_per_week'], $new['hours_reduction'], $sy, $eid]);
        logAudit('hours_reduction_apply', 'employees', $eid, $old, $new);
        $done++; $names[] = $nm . ' (' . (int)$hr['lawHours'] . ' ساعة ملاك − ' . (int)$hr['reduction'] . ' تناقص)';
    }
    if ($done) {
        $_SESSION['flash_success'] = ($_POST['action'] === 'hr_later'
            ? 'تم التأجيل لسنة ' . $sy . ' (بلا أي تغيير) لـ' : 'تم تسجيل ساعات الملاك وساعات التناقص بسنة ' . $sy . ' لـ')
            . $done . ': ' . implode('، ', array_slice($names, 0, 12)) . (count($names) > 12 ? '…' : '');
    } else {
        $_SESSION['flash_error'] = 'لم يُسجَّل شيء — الأستاذ ليس ضمن مدرستك أو لا تناقص له بسنة ' . $sy . '.';
    }
    header('Location: ' . $redirectTo); exit;
}

/**
 * ⏱️ ساعات حضور التناقص (طلبه 2026-09-03): «كل ساعة تناقص بتصير ساعة ونص» — 4 ساعات تناقص = 6 ساعات حضور.
 * المعامل إعداد عام قابل للتعديل (hours_reduction_presence_factor، افتراضي 1.5) — البرنامج يحسبها تلقائياً من ساعات التناقص المسجّلة بملفه.
 */
function hoursReductionPresenceFactor() {
    $f = (float)getSetting('hours_reduction_presence_factor', 1.5);
    return $f > 0 ? $f : 1.5;
}
function hoursReductionPresence($reductionHours) {
    return round((float)$reductionHours * hoursReductionPresenceFactor(), 1);
}
/** رقم ساعات بلا أصفار زائدة (4 · 6 · 1.5). */
function hoursFmt($v) {
    return rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
}
/**
 * نص سطر التناقص لبطاقة الرواتب/القسيمة بجانب الساعات الفعلية: «تناقص 4 س — حضور التناقص 6 س»، أو '' إن لا تناقص مسجّلاً.
 */
function hoursReductionSlipText(array $emp, $lang = 'ar') {
    $red = (float)($emp['hours_reduction'] ?? 0);
    if ($red <= 0) return '';
    $pres = hoursReductionPresence($red);
    return $lang === 'fr'
        ? 'Réduction ' . hoursFmt($red) . ' h — présence de réduction ' . hoursFmt($pres) . ' h'
        : 'تناقص ' . hoursFmt($red) . ' س — حضور التناقص ' . hoursFmt($pres) . ' س';
}

/** اسم الأستاذ الثلاثي للعرض. */
function hoursReductionEmpName(array $e) {
    return trim((($e['first_name_ar'] ?: $e['first_name_fr']) . ' ' . ($e['father_name_ar'] ?: '') . ' ' . ($e['last_name_ar'] ?: $e['last_name_fr'])));
}

/** زرّا «موافق» / «لاحقاً» لأستاذ واحد (يُستعملان بالمساج الجماعي وبملف الأستاذ). */
function hoursReductionDecisionButtons(array $e, $hr, $returnTo = '') {
    if (!canEdit()) return '<span class="text-muted">قراءة فقط</span>';
    $nm = hoursReductionEmpName($e);
    // من ملف الأستاذ يُرسَل لصفحة التناقص (employees.php معالج حفظه يلتقط كل POST) ثم يرجع لملفه
    $act = $returnTo !== '' ? ' action="' . BASE_URL . 'pages/hours_reduction.php"' : '';
    $ret = $returnTo !== '' ? '<input type="hidden" name="return_to" value="' . e($returnTo) . '">' : '';
    return '<form method="post"' . $act . ' style="display:inline" onsubmit="return confirm(\'تسجيل ' . (int)$hr['lawHours'] . ' ساعة ملاك و' . (int)$hr['reduction'] . ' ساعات تناقص لـ' . e($nm) . '؟\')">'
        . csrfField() . $ret . '<input type="hidden" name="action" value="hr_apply"><input type="hidden" name="emp_id" value="' . (int)$e['id'] . '">'
        . '<button class="btn btn-sm btn-success"><i class="fas fa-check"></i> موافق — سجّل ' . (int)$hr['lawHours'] . ' ساعة ملاك و−' . (int)$hr['reduction'] . ' تناقص</button></form>'
        . '<form method="post"' . $act . ' style="display:inline;margin-inline-start:4px">'
        . csrfField() . $ret . '<input type="hidden" name="action" value="hr_later"><input type="hidden" name="emp_id" value="' . (int)$e['id'] . '">'
        . '<button class="btn btn-sm btn-light"><i class="fas fa-clock-rotate-left"></i> لاحقاً</button></form>';
}

/**
 * مساج «قرار مطلوب» بكل مدرسة (يُستعمل بلوحة القيادة وصفحة التناقص): جدول لكل مدرسة فيه لكل أستاذ
 * ساعاته المخزّنة الآن → ما تصير عليه، وزرّا «موافق» / «لاحقاً»، وزرّ «موافق على الكل» للمدرسة.
 */
function renderHoursReductionPending($bySchool, $sy, $collapsed = false) {
    // $collapsed = true (لوحة القيادة): كل مدرسة سطر واحد (الاسم + العدد) يُفتح لرؤية الأسماء — حتى لا تتعجّق الرئيسية بعشرات الأسطر
    if (!$bySchool) return;
    $total = 0; foreach ($bySchool as $rows) $total += count($rows);
    $fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
    ?>
    <div class="card no-print" id="hrPending" style="border:2px solid #d97706;margin-bottom:16px">
        <div class="card-header" style="background:#fffbeb"><h3 style="color:#b45309"><i class="fas fa-clock"></i>
            Réduction d'horaire — décision requise / تناقص ساعات التدريس — قرار مطلوب بسنة <?= e($sy) ?> (<?= $total ?>)</h3></div>
        <div class="card-body">
            <p style="color:var(--gray-600);margin-top:0">هؤلاء الأساتذة الملاك <strong>صار عندهم تناقص جديد ببداية سنة <?= e($sy) ?></strong> حسب المرسوم 2601/2018.
                بإذنك يسجّل البرنامج بملف كلٍّ منهم <strong>عدد ساعات الملاك الجديد</strong> و<strong>ساعات التناقص</strong> — لا يتغيّر أي راتب. «لاحقاً» يخفي المساج لهذه السنة فقط.</p>
            <?php foreach ($bySchool as $schoolName => $rows): ?>
            <<?= $collapsed ? 'details' : 'div' ?> style="margin-bottom:<?= $collapsed ? '8px' : '14px' ?>">
                <?php if ($collapsed): ?><summary style="cursor:pointer;color:#b45309;font-weight:700;padding:6px 0"><i class="fas fa-school"></i> <?= e($schoolName) ?> — <?= count($rows) ?> أستاذاً <small style="font-weight:600;opacity:.8">(اكبس لرؤية الأسماء والقرار)</small></summary><?php endif; ?>
                <div class="d-flex justify-between align-center" style="flex-wrap:wrap;gap:6px;margin-bottom:6px">
                    <strong style="color:#b45309"><?= $collapsed ? '' : '<i class="fas fa-school"></i> ' . e($schoolName) . ' — ' . count($rows) . ' أستاذاً' ?></strong>
                    <?php if (canEdit()): ?>
                    <form method="post" style="margin:0" onsubmit="return confirm('تسجيل ساعات الملاك وساعات التناقص لكل أساتذة <?= e($schoolName) ?> (<?= count($rows) ?>) بسنة <?= e($sy) ?>؟')">
                        <?= csrfField() ?><input type="hidden" name="action" value="hr_apply">
                        <?php foreach ($rows as $r): ?><input type="hidden" name="emp_ids[]" value="<?= (int)$r['emp']['id'] ?>"><?php endforeach; ?>
                        <button class="btn btn-sm btn-success"><i class="fas fa-check-double"></i> موافق على الكل بهذه المدرسة</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="table-wrapper"><table class="table">
                    <thead><tr><th>الأستاذ</th><th>سنة الخدمة</th><th>المرحلة</th><th>ساعات الملاك الآن ← تصير</th><th>ساعات التناقص الآن ← تصير</th><th>القرار</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $e = $r['emp']; $hr = $r['hr']; ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$e['id'] ?>"><strong><?= e(hoursReductionEmpName($e)) ?></strong></a></td>
                        <td>سنة <?= (int)$hr['serviceYear'] ?></td>
                        <td><?= e($hr['stageLabel']) ?> (ج<?= (int)$hr['table'] ?>)<?php if ($hr['assumed']): ?> <span class="badge badge-warning" title="المرحلة غير محدّدة بملفه — افتراضي">؟</span><?php endif; ?></td>
                        <td style="white-space:nowrap"><?= $fmt($e['hours_per_week']) ?> ← <strong style="color:#b45309"><?= (int)$hr['lawHours'] ?></strong> <small>(أساس <?= (int)$hr['baseHours'] ?>)</small></td>
                        <td style="white-space:nowrap"><?= $fmt($e['hours_reduction'] ?? 0) ?> ← <strong style="color:#b91c1c">−<?= (int)$hr['reduction'] ?></strong></td>
                        <td style="white-space:nowrap"><?= hoursReductionDecisionButtons($e, $hr) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </<?= $collapsed ? 'details' : 'div' ?>>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
