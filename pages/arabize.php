<?php
/**
 * 🔤 تعريب الأسماء والعناوين (2026-10-27 «اللي كاتب الأسماء أو العناوين بالأجنبي لازم يتحوّلوا للعربي»):
 *   كل خانة عربية بملفات الأساتذة فيها أحرف لاتينية (اسم الأب/الأم، محل الولادة، مكان القيد، المحافظة/القضاء/البلدة/الحي/الشارع/المبنى)
 *   تُعرض مع **اقتراح عربي** (translit_fr_ar.php: قاموس + قواعد) قابل للتعديل، والمدير يشيّك ويحفظ — لا حفظ آلي لأنّ التعريب قد يخطئ بحرف.
 *   النماذج الرسمية تعرّب للطباعة تلقائياً حتى قبل الحفظ (official_export).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translit_fr_ar.php';
requireLogin();
if (!canEdit()) { $_SESSION['flash_error'] = 'صلاحية التعديل مطلوبة'; header('Location: ' . BASE_URL . 'index.php'); exit; }

$db = getDB();
$currentPage = 'arabize';
$pageTitle = 'Arabiser noms & adresses / تعريب الأسماء والعناوين';
$hideExportToolbar = true;
$FIELDS = ['father_name_ar' => 'اسم الأب', 'mother_first_name' => 'اسم الأم', 'mother_last_name' => 'شهرة الأم', 'birth_place' => 'محل الولادة', 'civil_registry_place' => 'مكان القيد',
           'gouvernorat' => 'المحافظة', 'district' => 'القضاء', 'ville' => 'البلدة', 'quartier' => 'الحي', 'rue' => 'الشارع', 'immeuble' => 'المبنى'];
foreach (array_keys($FIELDS) as $f) if (!msaHasCol($db, 'employees', $f)) unset($FIELDS[$f]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!verifyCsrf($_POST['csrf'] ?? '')) { $_SESSION['flash_error'] = 'انتهت الجلسة، أعد المحاولة'; header('Location: ' . BASE_URL . 'pages/arabize.php'); exit; }
    $sel = (array)($_POST['s'] ?? []); $vals = (array)($_POST['v'] ?? []); $n = 0;
    foreach ($sel as $eid => $fields) {
        $eid = (int)$eid; if ($eid <= 0) continue;
        foreach ((array)$fields as $f => $one) {
            if (!isset($FIELDS[$f])) continue;
            $nv = trim(preg_replace('/\s+/u', ' ', (string)($vals[$eid][$f] ?? '')));
            if ($nv === '' || hasLatin($nv)) continue;   // لا نحفظ فاضياً ولا لاتينياً
            $st = $db->prepare("UPDATE employees SET `$f` = ? WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
            $st->execute([$nv, $eid]); $n += $st->rowCount();
        }
    }
    $_SESSION['flash_success'] = 'عُرِّبت ' . $n . ' خانة وحُفظت بملفات الأساتذة';
    header('Location: ' . BASE_URL . 'pages/arabize.php'); exit;
}

$or = []; foreach (array_keys($FIELDS) as $f) $or[] = "`$f` REGEXP '[A-Za-z]'";
$rows = $or ? $db->query("SELECT id, employee_code, school_id, first_name_ar, last_name_ar, first_name_fr, last_name_fr, " . implode(', ', array_map(fn($f) => "`$f`", array_keys($FIELDS))) . "
                           FROM employees WHERE is_deleted = 0" . schoolScopeSql() . " AND (" . implode(' OR ', $or) . ") ORDER BY school_id, id")->fetchAll(PDO::FETCH_ASSOC) : [];
$items = [];
foreach ($rows as $r) foreach ($FIELDS as $f => $lbl) { $v = (string)($r[$f] ?? ''); if (hasLatin($v)) $items[] = ['emp' => $r, 'f' => $f, 'lbl' => $lbl, 'old' => $v, 'new' => frToAr($v)]; }
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-language"></i> Arabiser les noms et adresses écrits en lettres latines</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">تعريب الأسماء والعناوين المكتوبة بالأحرف الأجنبية بملفات الأساتذة</div>
    </h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="line-height:1.9">
            النماذج الرسمية (الضمان) عربية، وبعض الأساتذة كتبوا بالفورم اسم الأم أو محل الولادة أو العنوان بالأحرف الأجنبية. هنا كل خانة من هالنوع مع <strong>اقتراح عربي</strong> من قاموس مدن لبنان والأسماء الشائعة.
            عدّل الاقتراح إذا لزم، شيّك على الصحيح، واكبس «احفظ». النماذج الرسمية تطبع العربي تلقائياً حتى قبل الحفظ، والفورم صار يرفض الأحرف الأجنبية بالخانات العربية.
        </div>
        <?php if (!$items): ?>
            <div class="alert alert-success"><i class="fas fa-check"></i> ما في ولا خانة عربية مكتوبة بالأجنبي بملفات مدارسك. / Rien à arabiser.</div>
        <?php else: ?>
        <form method="POST">
            <?= csrfField() ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
                <strong><?= count($items) ?> خانة عند <?= count($rows) ?> أستاذاً</strong>
                <button type="button" class="btn btn-sm btn-light" onclick="document.querySelectorAll('.az-s').forEach(function(c){c.checked=true})"><i class="fas fa-check-double"></i> شيك الكل</button>
                <button type="button" class="btn btn-sm btn-light" onclick="document.querySelectorAll('.az-s').forEach(function(c){c.checked=false})"><i class="fas fa-xmark"></i> ولا واحد</button>
                <button class="btn btn-primary" name="save" value="1"><i class="fas fa-floppy-disk"></i> احفظ المشيّك بملفات الأساتذة / Enregistrer</button>
            </div>
            <div class="report-table-wrap" dir="rtl"><table class="table doc-table" dir="rtl" style="font-size:13px">
                <thead><tr><th>#</th><th>الأستاذ / Enseignant</th><th>الخانة</th><th>المكتوب بالملف</th><th style="min-width:220px">المقترح بالعربي (عدّله إذا لزم)</th><th>✓</th></tr></thead>
                <tbody>
                <?php $n = 0; foreach ($items as $it): $e = $it['emp']; $n++; ?>
                    <tr>
                        <td><?= $n ?></td>
                        <td><a href="<?= BASE_URL ?>pages/employees.php?action=edit&id=<?= (int)$e['id'] ?>"><strong><?= e(trim(($e['first_name_ar'] ?? '') . ' ' . ($e['last_name_ar'] ?? '')) ?: trim($e['first_name_fr'] . ' ' . $e['last_name_fr'])) ?></strong></a>
                            <small class="text-muted" dir="ltr"><?= e($e['first_name_fr'] . ' ' . $e['last_name_fr']) ?></small><?php if (isAllSchools()): ?><br><small><?= e(schoolNameById($e['school_id'])) ?></small><?php endif; ?></td>
                        <td><?= e($it['lbl']) ?></td>
                        <td dir="ltr" style="text-align:left;color:#b45309;font-weight:700"><?= e($it['old']) ?></td>
                        <td><input type="text" class="form-control" name="v[<?= (int)$e['id'] ?>][<?= e($it['f']) ?>]" value="<?= e($it['new']) ?>" dir="rtl" pattern="[^A-Za-z]*" title="بالعربي فقط"></td>
                        <td><input type="checkbox" class="az-s" name="s[<?= (int)$e['id'] ?>][<?= e($it['f']) ?>]" value="1" <?= hasLatin($it['new']) ? '' : 'checked' ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div style="margin-top:10px"><button class="btn btn-primary" name="save" value="1"><i class="fas fa-floppy-disk"></i> احفظ المشيّك بملفات الأساتذة / Enregistrer</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
