<?php
/**
 * أدوات تنبيه بلوغ سنّ الـ64 — مشتركة بين الصفحة الرئيسية (index.php) والصفحة الخاصة (pages/retirement_64.php).
 * الصفحة الخاصة تعرض كل من بلغوا 64؛ الرئيسية تعرض فقط من بلغوا 64 ضمن السنة الدراسية المختارة.
 * القرار لكلٍّ: يبقى (وقف محسومات التقاعد) / يُلغى الإبقاء / يُسجَّل ترك.
 */

/**
 * معالجة إجراءات keep64/unkeep64/depart64 (POST). تُستدعى قبل أي إخراج HTML.
 * عند وجود إجراء: تنفّذه ثم تعيد التوجيه إلى $redirectTo وتنهي.
 */
function handleAge64Post($db, $redirectTo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['action'] ?? '', ['keep64','unkeep64','depart64'], true)) return;
    requireCsrf();
    $eid = (int)($_POST['emp_id'] ?? 0);
    // تأكّد أن الموظف ضمن نطاق مدرسة المستخدم (المدير العام يرى الكل)
    $chk = $db->prepare("SELECT id, birth_date FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $chk->execute([$eid]);
    $row = $chk->fetch();
    if ($row) {
        $act = $_POST['action'];
        if ($act === 'keep64' || $act === 'unkeep64') {
            $val = $act === 'keep64' ? 1 : 0;
            $db->prepare("UPDATE employees SET keep_working_past_64 = ? WHERE id = ?")->execute([$val, $eid]);
            // أعِد حساب كل سنواته المخزّنة ليُطبَّق/يُلغى وقف المحسومات من شهر بلوغه 64
            foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . $eid)->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                if ($sy) recalcEmployeeYear($eid, $sy);
            }
            recalcEmployeeYear($eid);
            $_SESSION['flash_success'] = $val
                ? 'تم الإبقاء على العمل بعد الـ64 ووقف محسومات التقاعد تلقائياً اعتباراً من شهر بلوغه 64.'
                : 'تم إلغاء الإبقاء — عادت محسومات التقاعد كالمعتاد.';
        } elseif ($act === 'depart64') {
            $d = parseFlexibleDate($_POST['depart_date'] ?? '');
            if (!$d && !empty($row['birth_date'])) {   // افتراضي: تاريخ بلوغه 64
                $bd = date_create(substr($row['birth_date'], 0, 10));
                if ($bd) $d = $bd->modify('+64 years')->format('Y-m-d');
            }
            if ($d) {
                $db->prepare("UPDATE employees SET left_date_cnss = ?, left_date_finance = ?, left_date_eoc = ? WHERE id = ?")
                   ->execute([$d, $d, $d, $eid]);
                pruneSalariesAfterDeparture($db, $eid);
                $_SESSION['flash_success'] = 'تم تسجيل ترك العمل بتاريخ ' . displayDMY($d) . ' — خرج من السنة الجارية وحُذفت رواتبه بعد هذا التاريخ.';
            } else {
                $_SESSION['flash_error'] = 'تعذّر تحديد تاريخ الترك — اكتب التاريخ يدوياً.';
            }
        }
    } else {
        $_SESSION['flash_error'] = 'الموظف ليس ضمن مدرستك.';
    }
    header('Location: ' . $redirectTo); exit;
}

/**
 * تاريخ بلوغ الموظف 64 = تاريخ الولادة + 64 سنة (Y-m-d)، أو '' إن كان تاريخ الولادة ناقصاً.
 */
function age64Date($birthDate) {
    $b = date_create(substr((string)$birthDate, 0, 10));
    return $b ? $b->modify('+64 years')->format('Y-m-d') : '';
}

/**
 * قائمة من بلغوا 64 (فاعلون، غير تاركين، تاريخ ولادة صحيح) ضمن نطاق مدرسة المستخدم.
 * $activeYearOnly = true → فقط من يقع تاريخ بلوغه 64 ضمن السنة الدراسية المختارة (للصفحة الرئيسية).
 * محصّن: يركّب العمود ذاتياً إن كان ناقصاً، ولا يكسر الصفحة إن تعذّر.
 */
function age64List($db, $activeYearOnly = false) {
    // تركيب ذاتي للعمود إن كان ناقصاً (يُغني عن أي خطوة يدوية أونلاين)
    try {
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'keep_working_past_64'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN keep_working_past_64 TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* صلاحيات → التحصين أدناه يمنع الكسر */ }

    $rows = [];
    try {
        $st = $db->prepare("SELECT e.id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
                e.employee_type, e.birth_date, e.keep_working_past_64,
                COALESCE(NULLIF(sc.name_ar,''), sc.name_fr) AS school_name
            FROM employees e LEFT JOIN schools sc ON sc.id = e.school_id
            WHERE e.is_deleted = 0 AND e.status = 'actif'
              AND e.left_date_cnss IS NULL AND e.left_date_finance IS NULL AND e.left_date_eoc IS NULL
              AND e.birth_date IS NOT NULL AND e.birth_date NOT IN ('0000-00-00','1900-01-01')
              AND TIMESTAMPDIFF(YEAR, e.birth_date, CURDATE()) >= 64" . schoolScopeSql('e.school_id')
            . " ORDER BY FIELD(e.employee_type,'enseignant_titulaire','enseignant_contractuel','employe'),
                COALESCE(NULLIF(e.first_name_ar,''), e.first_name_fr)");
        $st->execute();
        $rows = $st->fetchAll();
    } catch (Exception $e) { $rows = []; }

    if ($activeYearOnly) {
        $ay = activeSchoolYear();
        if ($ay !== 'all') {   // «كل السنوات» → أظهر الكل بالرئيسية أيضاً
            $rows = array_values(array_filter($rows, function ($r) use ($ay) {
                $d64 = age64Date($r['birth_date']);
                return $d64 && schoolYearOfDate($d64) === $ay;
            }));
        }
    }
    return $rows;
}

/**
 * عرض بطاقتَي «قرار مطلوب» (أحمر، بأزرار) و«مُبقَون» (أخضر، بزرّ إلغاء) لقائمة معطاة.
 * $emptyHtml يُعرض إن كانت القائمة فارغة (اختياري — للصفحة الخاصة).
 */
function renderAge64Cards($rows, $emptyHtml = '') {
    $need = array_values(array_filter($rows, fn($r) => empty($r['keep_working_past_64'])));
    $kept = array_values(array_filter($rows, fn($r) => !empty($r['keep_working_past_64'])));
    if (!$need && !$kept) { echo $emptyHtml; return; }
    ?>
    <?php if ($need): ?>
    <div class="card no-print" style="border:2px solid #dc2626;margin-bottom:16px">
        <div class="card-header" style="background:#fef2f2"><h3 style="color:#b91c1c"><i class="fas fa-triangle-exclamation"></i> بلغوا سنّ الـ64 — قرار مطلوب (<?= count($need) ?>)</h3></div>
        <div class="card-body">
            <p style="color:var(--gray-600);margin-top:0">قرّر لكلٍّ: <strong>يبقى</strong> (تُوقَف محسومات التقاعد تلقائياً — للأستاذ الملاك صندوق التعويضات ٦٪ <strong>عنه وعن المدرسة</strong>، وللموظف نهاية الخدمة ٨.٥٪)، أو <strong>تركه</strong> (يُسجَّل تاريخ الترك فيخرج من السنة).</p>
            <div class="table-wrapper"><table class="table">
                <thead><tr><th>الاسم</th><th>الفئة</th><th>العمر</th><th>تاريخ بلوغ 64</th><th>المدرسة</th><th>القرار</th></tr></thead>
                <tbody>
                <?php foreach ($need as $r):
                    $nm = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
                    $age = ageOnDate($r['birth_date']); $d64 = age64Date($r['birth_date']); ?>
                <tr>
                    <td><strong><?= e($nm) ?></strong></td>
                    <td><?= employeeTypeLabel($r['employee_type']) ?></td>
                    <td><span class="badge" style="background:#b45309;color:#fff"><?= (int)$age ?> سنة</span></td>
                    <td style="white-space:nowrap"><strong style="color:#b45309"><?= $d64 ? e(displayDMY($d64)) : '—' ?></strong></td>
                    <td><?= e($r['school_name']) ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline" onsubmit="return confirm('إبقاؤه على العمل بعد الـ64 ووقف محسومات التقاعد؟')">
                            <?= csrfField() ?><input type="hidden" name="action" value="keep64"><input type="hidden" name="emp_id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-success"><i class="fas fa-user-check"></i> يبقى — وقف المحسومات</button>
                        </form>
                        <form method="post" style="display:inline-flex;gap:4px;align-items:center;margin-inline-start:6px" onsubmit="return confirm('تسجيل ترك العمل بهذا التاريخ؟')">
                            <?= csrfField() ?><input type="hidden" name="action" value="depart64"><input type="hidden" name="emp_id" value="<?= $r['id'] ?>">
                            <input type="date" name="depart_date" value="<?= e($d64) ?>" style="padding:5px;border:1px solid #cbd5e1;border-radius:6px">
                            <button class="btn btn-sm btn-light"><i class="fas fa-door-open"></i> تركه</button>
                        </form>
                        <a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-user"></i> الملف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($kept): ?>
    <div class="card no-print" style="border:1px solid #86efac;margin-bottom:16px">
        <div class="card-header" style="background:#f0fdf4"><h3 style="color:#15803d"><i class="fas fa-user-check"></i> مُبقَون بعد الـ64 — محسومات التقاعد موقوفة (<?= count($kept) ?>)</h3></div>
        <div class="card-body"><div class="table-wrapper"><table class="table">
            <thead><tr><th>الاسم</th><th>الفئة</th><th>العمر</th><th>تاريخ بلوغ 64</th><th>المدرسة</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($kept as $r):
                $nm = trim($r['first_name_ar'].' '.$r['last_name_ar']) ?: trim($r['first_name_fr'].' '.$r['last_name_fr']);
                $age = ageOnDate($r['birth_date']); $d64 = age64Date($r['birth_date']); ?>
            <tr>
                <td><strong><?= e($nm) ?></strong></td>
                <td><?= employeeTypeLabel($r['employee_type']) ?></td>
                <td><?= (int)$age ?> سنة</td>
                <td style="white-space:nowrap"><strong style="color:#15803d"><?= $d64 ? e(displayDMY($d64)) : '—' ?></strong></td>
                <td><?= e($r['school_name']) ?></td>
                <td><form method="post" onsubmit="return confirm('إلغاء الإبقاء وإعادة محسومات التقاعد كالمعتاد؟')" style="margin:0">
                    <?= csrfField() ?><input type="hidden" name="action" value="unkeep64"><input type="hidden" name="emp_id" value="<?= $r['id'] ?>">
                    <button class="btn btn-sm btn-light"><i class="fas fa-rotate-left"></i> إلغاء الإبقاء</button></form></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>
    <?php endif; ?>
    <?php
}
