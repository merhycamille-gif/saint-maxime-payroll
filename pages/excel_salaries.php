<?php
/**
 * 📗 Excel salaires & primes / إكسل الرواتب والأجر الإضافي — المتعاقدون والموظفون دفعة وحدة (2026-09-12)
 * «بدي ملف إكسل فيه أسماء المتعاقدين والموظفين ومحلّ أنا حطّ الراتب والأجر الإضافي وعدد الأيام بالأسبوع، بعبّيهن ورا بعضهن
 *  وانت بترجع بتوزّعهن على ملفاتهم — أسهل من ما فوت على كل ملف. خليها أوبسيون زيادة.»
 * ① نزّل الملف (معبّأ بالقيم الحالية) ← ② عبّيه ← ③ ارفعه ← معاينة الفروقات ← ④ طبّق (المحرّك يعيد الحساب).
 * المنطق كله بـincludes/excel_salaries.php (بناء/قراءة/مقارنة/تطبيق).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/excel_salaries.php';
requireLogin();
requireCsrf();
if (isViewer()) { $_SESSION['flash_error'] = 'صلاحية قراءة فقط'; header('Location: ' . BASE_URL . 'index.php'); exit; }

$currentPage = 'excel_salaries';
$pageTitle = 'Excel salaires & primes / إكسل الرواتب والأجر الإضافي';
$db = getDB();
@set_time_limit(0);

$schParam = (string)($_GET['sch'] ?? $_POST['sch'] ?? '');
$schoolId = $schParam !== '' ? (int)$schParam : (int)currentSchoolId();
if (!isSuperAdmin()) $schoolId = (int)currentSchoolId();
$schoolYear = (string)($_GET['sy'] ?? $_POST['sy'] ?? currentSchoolYear());
if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear)) $schoolYear = currentSchoolYear();
$tmpDir = dirname(__DIR__) . '/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
foreach (glob($tmpDir . '/excel_import_*.json') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
$self = BASE_URL . 'pages/excel_salaries.php?sch=' . $schoolId . '&sy=' . urlencode($schoolYear);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$preview = null; $token = '';

// ① تنزيل الملف
if ($action === 'download' && $schoolId > 0) {
    $data = excelSalariesBuild($db, $schoolId, $schoolYear);
    $name = 'رواتب-واضافي-' . preg_replace('/[\\\\\/:*?"<>|]+/', '_', schoolNameById($schoolId, 'ar')) . '-' . $schoolYear . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($name) . '; filename="salaires_' . $schoolId . '_' . $schoolYear . '.xlsx"');
    header('Content-Length: ' . strlen($data));
    echo $data; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schoolId > 0) {
    if (isAllSchools()) { $_SESSION['active_schools'] = [$schoolId]; unset($_SESSION['report_schools']); }
    // ③ رفع الملف → معاينة
    if ($action === 'upload') {
        $f = $_FILES['xlsx'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $_SESSION['flash_error'] = 'اختر ملف الإكسل أوّلاً (xlsx).';
        else {
            try {
                $parsed = excelSalariesParse($f['tmp_name']);
                if (!$parsed) throw new RuntimeException('الملف بلا أسطر أساتذة — نزّل الملف من هنا وعبّيه ثم ارفعه نفسه.');
                $preview = excelSalariesDiff($db, $schoolId, $schoolYear, $parsed);
                $preview['file'] = (string)$f['name']; $preview['rows'] = count($parsed);
                if ($preview['changes']) {
                    $token = bin2hex(random_bytes(12));
                    file_put_contents($tmpDir . '/excel_import_' . $token . '.json', json_encode(['sch' => $schoolId, 'sy' => $schoolYear, 'user' => (int)($_SESSION['user_id'] ?? 0), 'changes' => $preview['changes']], JSON_UNESCAPED_UNICODE));
                }
            } catch (Throwable $ex) { $_SESSION['flash_error'] = 'تعذّر قراءة الملف: ' . $ex->getMessage(); }
        }
    }
    // ④ تطبيق بعد التأكيد
    elseif ($action === 'apply') {
        $tk = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
        $fp = $tk ? $tmpDir . '/excel_import_' . $tk . '.json' : '';
        $st = ($fp && is_file($fp)) ? json_decode((string)file_get_contents($fp), true) : null;
        if (!$st || (int)$st['sch'] !== $schoolId || (string)$st['sy'] !== $schoolYear) $_SESSION['flash_error'] = 'انتهت المعاينة — ارفع الملف مرّة ثانية.';
        else {
            $res = excelSalariesApply($db, $schoolId, $schoolYear, $st['changes']);
            @unlink($fp);
            if ($res['applied'] > 0) $_SESSION['flash_success'] = '✅ وُزّعت التغييرات من الإكسل على ' . $res['applied'] . ' ملفاً وأُعيد حساب رواتب ' . $res['recalc'] . ' تلقائياً (' . e(schoolNameById($schoolId, 'ar')) . ' — ' . e($schoolYear) . ').' . ($res['skipped'] ? ' تُرك: ' . e(implode('؛ ', $res['skipped'])) : '');
            else $_SESSION['flash_error'] = 'لم يُطبَّق شيء' . ($res['skipped'] ? ': ' . e(implode('؛ ', $res['skipped'])) : '.');
            header('Location: ' . $self); exit;
        }
    }
}

$rowsNow = $schoolId > 0 ? excelSalariesRows($db, $schoolId, $schoolYear) : [];
$nC = count(array_filter($rowsNow, fn($r) => $r['type'] === 'enseignant_contractuel')); $nE = count($rowsNow) - $nC;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.xs-step { display:flex; gap:14px; align-items:flex-start; padding:14px 0; border-bottom:1px dashed #e2e8f0; }
.xs-step:last-child { border-bottom:none; }
.xs-num { flex:0 0 34px; width:34px; height:34px; border-radius:50%; background:#1F4E5F; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:15px; }
.xs-step > div { flex:1; min-width:0; }
.xs-hint { color:#64748b; font-size:12.5px; line-height:1.9; }
.xs-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:6px; margin-top:6px; }
.xs-col { background:#fff7cc; border:1px solid #fde68a; border-radius:8px; padding:6px 10px; font-size:12.5px; font-weight:700; }
.xs-col.ro { background:#f1f5f9; border-color:#e2e8f0; color:#475569; }
.xs-prev th { background:#1F4E5F !important; color:#fff !important; white-space:nowrap; }
.xs-old { color:#991b1b; text-decoration:line-through; } .xs-new { color:#166534; font-weight:800; }
</style>

<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-file-excel"></i> Excel — salaires, supplément &amp; jours (contractuels &amp; employés)</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">إكسل — الرواتب والأجر الإضافي وعدد الأيام للمتعاقدين والموظفين، دفعة وحدة</div>
    </h3></div>
    <div class="card-body">
        <form method="GET" class="form-row cols-2 no-print" style="margin-bottom:12px">
            <?php if (isSuperAdmin()): ?>
            <div class="form-group mb-0">
                <label class="form-label">École / المدرسة</label>
                <select name="sch" class="form-select" onchange="this.form.submit()">
                    <option value="">— Choisir / اختر —</option>
                    <?php foreach (allSchools() as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $schoolId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name_ar'] ?: $s['name_fr']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?><input type="hidden" name="sch" value="<?= $schoolId ?>"><?php endif; ?>
            <div class="form-group mb-0">
                <label class="form-label">Année scolaire / السنة الدراسية</label>
                <input type="text" name="sy" class="form-control" value="<?= e($schoolYear) ?>" onchange="this.form.submit()">
            </div>
        </form>

        <?php if ($schoolId <= 0): ?>
            <div class="alert alert-info">اختر المدرسة من الأعلى.</div>
        <?php else: ?>
        <?php if (isSchoolYearLocked($schoolId, $schoolYear)): ?>
            <div class="alert alert-warning">🔒 <?= e(yearLockedMsg($schoolId, $schoolYear)) ?> — التنزيل ممكن، التطبيق مرفوض حتى تفتح القفل.</div>
        <?php endif; ?>
        <div class="xs-hint" style="margin-bottom:6px">هذا خيار زيادة: بدل ما تفوت على كل ملف، تعبّي إكسل واحد للمدرسة كلّها وترفعه. <b><?= e(schoolNameById($schoolId, 'ar')) ?> — <?= e($schoolYear) ?>:</b> <?= $nC ?> متعاقد + <?= $nE ?> موظف فاعلون (الملاك غير مشمولين — رواتبهم بالسلسلة والدرجات).</div>

        <div class="xs-step"><span class="xs-num">١</span><div>
            <strong>نزّل الملف</strong> <span class="xs-hint">— سطر لكل متعاقد وموظف، والخانات الصفراء معبّأة بقيمه الحالية كي تعدّل ما تريد فقط.</span>
            <div style="margin-top:8px"><a class="btn btn-primary" href="<?= $self ?>&action=download" style="font-weight:800"><i class="fas fa-download"></i> Télécharger / نزّل الإكسل (<?= count($rowsNow) ?>)</a></div>
            <div class="xs-cols">
                <div class="xs-col ro">رقم الملف (لا تغيّره)</div><div class="xs-col ro">الفئة · الاسم</div>
                <div class="xs-col">الراتب الأساسي $ <u>أو</u> ل.ل</div><div class="xs-col">الأجر الإضافي ٪</div><div class="xs-col">الأجر الإضافي مبلغ ل.ل <u>أو</u> $</div>
                <div class="xs-col">الإضافي من شهر ← إلى شهر</div><div class="xs-col">عدد الأيام بالأسبوع</div>
            </div>
            <div class="xs-hint" style="margin-top:6px">فاضي = لا تغيير · <b>0</b> بالأجر الإضافي = شيله · الشهر بالاسم أو بالرقم، فاضي = كل السنة (تشرين الأول ← أيلول) · لا تحذف أعمدة ولا تغيّر ترتيبها.</div>
        </div></div>

        <div class="xs-step"><span class="xs-num">٢</span><div>
            <strong>عبّي الخانات الصفراء بالإكسل</strong> <span class="xs-hint">— ورا بعضهن، واحفظ الملف.</span>
        </div></div>

        <div class="xs-step"><span class="xs-num">٣</span><div>
            <strong>ارفع الملف المعبّى</strong> <span class="xs-hint">— بيطلع لك جدول بكل تغيير (قديم ← جديد) قبل ما يتغيّر شي.</span>
            <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
                <?= csrfField() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="sch" value="<?= $schoolId ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>">
                <input type="file" name="xlsx" accept=".xlsx" class="form-control" required style="max-width:380px">
                <button type="submit" class="btn btn-success" style="font-weight:800"><i class="fas fa-upload"></i> Vérifier / ارفع وشوف الفروقات</button>
            </form>
        </div></div>

        <?php if ($preview !== null): ?>
        <div class="xs-step"><span class="xs-num">٤</span><div>
            <strong>الفروقات من «<?= e($preview['file']) ?>»</strong> — <?= (int)$preview['rows'] ?> سطر قُرئ · <b style="color:#166534"><?= count($preview['changes']) ?></b> شخص عنده تغيير · <?= (int)$preview['unchanged'] ?> بلا تغيير<?= $preview['errors'] ? ' · <b style="color:#b91c1c">' . count($preview['errors']) . '</b> تنبيه' : '' ?>
            <?php if ($preview['errors']): ?>
                <div class="alert alert-warning" style="margin:8px 0;font-size:13px;line-height:1.9"><?php foreach ($preview['errors'] as $er): ?><div>⚠️ <?= e($er) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($preview['changes']): ?>
            <div class="table-scroll" style="margin-top:8px">
            <table class="table xs-prev" style="margin:0">
                <thead><tr><th>#</th><th>الاسم</th><th>الفئة</th><th>ماذا يتغيّر</th><th>الحالي</th><th>الجديد</th></tr></thead>
                <tbody>
                <?php $i = 0; foreach ($preview['changes'] as $ch): $first = true; foreach ($ch['fields'] as $f): ?>
                    <tr>
                        <?php if ($first): $i++; ?><td rowspan="<?= count($ch['fields']) ?>"><?= $i ?></td><td rowspan="<?= count($ch['fields']) ?>" style="font-weight:800"><?= e($ch['name']) ?> <small style="color:#64748b">#<?= (int)$ch['id'] ?></small></td><td rowspan="<?= count($ch['fields']) ?>"><?= e($ch['cat']) ?></td><?php endif; ?>
                        <td><?= e($f['what']) ?></td><td class="xs-old"><?= e($f['old']) ?></td><td class="xs-new"><?= e($f['new']) ?></td>
                    </tr>
                <?php $first = false; endforeach; endforeach; ?>
                </tbody>
            </table>
            </div>
            <form method="POST" style="margin-top:10px">
                <?= csrfField() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="sch" value="<?= $schoolId ?>"><input type="hidden" name="sy" value="<?= e($schoolYear) ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
                <button type="submit" class="btn btn-primary" style="font-weight:800" data-confirm="توزيع <?= count($preview['changes']) ?> تغييراً على ملفات الأساتذة والموظفين بـ<?= e(schoolNameById($schoolId, 'ar')) ?> — <?= e($schoolYear) ?> وإعادة حساب رواتبهم؟"><i class="fas fa-check"></i> Appliquer / طبّق ووزّع على الملفات (<?= count($preview['changes']) ?>)</button>
                <span class="xs-hint" style="margin-right:10px">الأجر الإضافي الجديد يستبدل الحالي عند الشخص (كل فتراته) ويُحسب من تشرين الأول. الرواتب تُعاد بالمحرّك فتطلع نفسها بالبطاقة السنوية وكل التقارير.</span>
            </form>
            <?php else: ?>
                <div class="alert alert-info" style="margin-top:8px">لا فروقات — الملف مطابق للوضع الحالي.</div>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
