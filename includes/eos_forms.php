<?php
/**
 * 🏦 نماذج تعويض نهاية الخدمة للخاضعين لقانون العمل — الضمان الاجتماعي (2026-09-19، طلبه: «p1,p2,p3 هودي ملفات لتعويض نهاية
 * الخدمة للموظف الخاضع لقانون العمل بدي متلهون طبق الأصل ينعملو ويتعبّوا ويكون عندي أنا كمان خيار عبّيهون أو غيّر فيهن
 * واحفظ واحذف وأكيد الطبع PDF أو إكسل أو وورد»):
 *   p1 = cnss_eos_doc    : مستند تصفية تعويض نهاية خدمة (إفادة المؤسسة بأساس الأجر + الأجر الأخير + مجموع الأجور والاشتراكات ٨.٥٪ + التفويض + إفادة المضمون)
 *   p2 = cnss_eos_2y     : جدول أجور السنتين الأخيرتين شهراً شهراً (الأساسي / لواحق الراتب / مقبوضات أخرى / المجموع)
 *   p3 = cnss_eos_annual : جدول بالأجور السنوية من تاريخ الدخول حتى الترك
 *
 * المبدأ: كل خانة تُعبَّأ تلقائياً من ملف الموظف ورواتبه المخزّنة (المصدر الواحد eosData)، وكل خانة قابلة للتعديل بزرّ «تعديل»؛
 * «حفظ» يخزّن ما عدّله فقط (JSON بجدول official_form_edits لكل موظف ونموذج، يتركّب ذاتياً)، و«حذف المحفوظ» يرجّع التعبئة التلقائية.
 * الطباعة/PDF/إكسل/وورد من الشريط العام (المستند HTML طبق الأصل — لا صورة).
 */

function ensureOfficialFormEdits(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS official_form_edits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            form_key VARCHAR(40) NOT NULL,
            data LONGTEXT NULL,
            updated_by VARCHAR(80) NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_ofe (employee_id, form_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/** النماذج الثلاثة (المفتاح ⇒ العنوان) */
function eosForms(): array {
    return [
        'cnss_eos_doc'    => 'Document de liquidation de l\'indemnité de fin de service (CNSS) / مستند تصفية تعويض نهاية خدمة (الضمان)',
        'cnss_eos_2y'     => 'Tableau des salaires des deux dernières années — fin de service (CNSS) / جدول أجور السنتين الأخيرتين — نهاية الخدمة',
        'cnss_eos_annual' => 'Tableau des salaires annuels — fin de service (CNSS) / جدول بالأجور السنوية — نهاية الخدمة',
    ];
}

/** القيم المحفوظة (المعدَّلة يدوياً) لموظف ونموذج */
function ofeLoad(PDO $db, int $empId, string $form): array {
    ensureOfficialFormEdits();
    try {
        $st = $db->prepare("SELECT data, updated_by, updated_at FROM official_form_edits WHERE employee_id = ? AND form_key = ?");
        $st->execute([$empId, $form]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['data' => [], 'updated_by' => null, 'updated_at' => null];
        $d = json_decode((string)$r['data'], true);
        return ['data' => is_array($d) ? $d : [], 'updated_by' => $r['updated_by'], 'updated_at' => $r['updated_at']];
    } catch (Throwable $e) { return ['data' => [], 'updated_by' => null, 'updated_at' => null]; }
}
function ofeSave(PDO $db, int $empId, string $form, array $data, string $who): void {
    ensureOfficialFormEdits();
    $clean = [];
    foreach ($data as $k => $v) {
        $k = preg_replace('/[^a-z0-9_]/', '', (string)$k);
        if ($k === '') continue;
        $clean[$k] = mb_substr(trim((string)$v), 0, 500);
    }
    $db->prepare("INSERT INTO official_form_edits (employee_id, form_key, data, updated_by, updated_at) VALUES (?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = VALUES(updated_by), updated_at = NOW()")
       ->execute([$empId, $form, json_encode($clean, JSON_UNESCAPED_UNICODE), $who]);
    logAudit('official_form_edit', 'official_form_edits', $empId, null, ['form' => $form, 'keys' => count($clean)]);
}
function ofeDelete(PDO $db, int $empId, string $form): void {
    ensureOfficialFormEdits();
    $db->prepare("DELETE FROM official_form_edits WHERE employee_id = ? AND form_key = ?")->execute([$empId, $form]);
    logAudit('official_form_edit_delete', 'official_form_edits', $empId, null, ['form' => $form]);
}

/** خانة قابلة للتعديل: القيمة المحفوظة إن وُجدت وإلا التلقائية. $cls = g | lg | '' (عرض الخانة كما بنماذج الضمان) */
function ofe(string $k, $default, string $cls = ''): string {
    $ov = $GLOBALS['OFE_DATA'] ?? [];
    $v = array_key_exists($k, $ov) ? (string)$ov[$k] : (string)$default;
    return '<span class="val ofe' . ($cls ? ' ' . $cls : '') . '" data-k="' . e($k) . '">' . e(trim($v)) . '</span>';
}
/** مربّع تأشير قابل للتعديل (X / فارغ) */
function ofeBox(string $k, string $label, bool $defaultOn): string {
    $ov = $GLOBALS['OFE_DATA'] ?? [];
    $on = array_key_exists($k, $ov) ? ((string)$ov[$k] === 'X') : $defaultOn;
    return '<span class="checkbox-opt ofe-box" data-k="' . e($k) . '" data-v="' . ($on ? 'X' : '') . '">' . e($label) . '<span class="bx">' . ($on ? 'X' : '&nbsp;') . '</span></span>';
}
/** خلية جدول قابلة للتعديل */
function ofeTd(string $k, $default, string $style = ''): string {
    $ov = $GLOBALS['OFE_DATA'] ?? [];
    $v = array_key_exists($k, $ov) ? (string)$ov[$k] : (string)$default;
    return '<td class="ofe"' . ($style ? ' style="' . e($style) . '"' : '') . ' data-k="' . e($k) . '">' . e(trim($v)) . '</td>';
}
/** رقم بالليرة للنماذج (بلا كسور) — فارغ إن صفر */
function eosNum($v): string { $v = (int)round((float)$v); return $v > 0 ? number_format($v) : ''; }
/** التاريخ كما بالنموذج: يوم / شهر / سنة */
function eosDmy(?string $d): string {
    if (!$d || $d < '1900-01-01') return '';
    return (int)substr($d, 8, 2) . ' / ' . (int)substr($d, 5, 2) . ' / ' . substr($d, 0, 4);
}

/**
 * المصدر الواحد لبيانات النماذج الثلاثة: المؤسسة، المضمون، التواريخ، الأجر الأخير، الأشهر (شهراً شهراً)، السنوات، المجاميع والاشتراكات.
 * الأجر الخاضع لكل شهر = cnssSubjectWageLbp (يتبع مفاتيح الخضوع بملف الموظف كباقي نماذج الضمان).
 */
function eosData(PDO $db, array $emp, ?array $school): array {
    $id = (int)$emp['id'];
    $left = leftDateOfFor($emp, 'cnss');
    $todayYm = (int)date('Y') * 100 + (int)date('n');
    $limYm = $left ? ((int)substr($left, 0, 4) * 100 + (int)substr($left, 5, 2)) : $todayYm;
    $limYm = min($limYm, $todayYm); // 🔴 الأشهر الفعلية الماضية فقط — لا أشهر مستقبلية من سنة مفتوحة للتجهيز
    $st = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? AND is_calculated = 1 AND (year * 100 + month) <= ? ORDER BY year, month");
    $st->execute([$id, $limYm]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $months = []; $years = []; $total = 0; $last = null;
    foreach ($rows as $r) {
        $y = (int)$r['year']; $m = (int)$r['month'];
        $base = (int)$r['base_plus_echelon_lbp'];
        $acc  = (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp'] + (int)$r['aide_complementaire_lbp']; // لواحق الراتب
        $oth  = (int)$r['transport_lbp'];                                                               // مقبوضات أخرى (النقل)
        $subj = (int)cnssSubjectWageLbp($r, $emp);                                                       // الأجر الخاضع (المصرَّح)
        $months[$y][$m] = ['base' => $base, 'acc' => $acc, 'oth' => $oth, 'sum' => $base + $acc + $oth, 'subj' => $subj];
        $years[$y] = ($years[$y] ?? 0) + $subj;
        $total += $subj;
        $last = $r;
    }
    $lastY = $last ? (int)$last['year'] : (int)date('Y');
    $lastWage = $last ? (int)cnssSubjectWageLbp($last, $emp) : 0;
    $rateY = $left ? (int)substr($left, 0, 4) : $lastY; $rateM = $left ? (int)substr($left, 5, 2) : ($last ? (int)$last['month'] : (int)date('n'));
    $contrib = (int)round($total * rateFrac('end_of_service_rate', $rateM, $rateY, 8.5));
    $firstRow = $rows ? sprintf('%04d-%02d-01', (int)$rows[0]['year'], (int)$rows[0]['month']) : null;
    // بداية الفترة = الأبكر بين تاريخ الدخول وأوّل شهر راتب مخزّن — حتى تتطابق «من تاريخ» بالمستند مع مجموع الأجور وجدول السنوات
    // (كرستيان عون: دخول 1/1/2025 بملفه ورواتب مخزّنة من تشرين 2023 ⇒ الفترة من تشرين 2023؛ تصحيح تاريخ الدخول بيده)
    $hireD = ($emp['hire_date'] && $emp['hire_date'] > '1900-01-01') ? $emp['hire_date'] : null;
    $fromD = ($hireD && (!$firstRow || $hireD <= $firstRow)) ? $hireD : $firstRow;
    $hireY = $fromD ? (int)substr($fromD, 0, 4) : $lastY;
    return [
        'school'    => $school ?: [],
        'left'      => $left,
        'from'      => $fromD,
        'to'        => $left ?: ($last ? sprintf('%04d-%02d-%02d', (int)$last['year'], (int)$last['month'], (int)date('t', mktime(0, 0, 0, (int)$last['month'], 1, (int)$last['year']))) : null),
        'last_wage' => $lastWage,
        'total'     => $total,
        'contrib'   => $contrib,
        'rate_pct'  => rateFrac('end_of_service_rate', $rateM, $rateY, 8.5) * 100,
        'months'    => $months,
        'years'     => $years,
        'y2'        => $lastY,            // السنة الأخيرة بالجدول الثاني
        'y1'        => $lastY - 1,        // السنة قبلها بالجدول الأول
        'hire_y'    => $hireY,
        'occupation'=> cnssOccupationAr($emp),
    ];
}

/** شريط التعديل/الحفظ/الحذف فوق النموذج (لا يُطبع) + JS التحرير */
function eosEditBar(string $form, int $empId, array $saved): string {
    if (!canEdit()) return '';
    ob_start(); ?>
    <div class="card no-print no-export ofe-bar" style="margin-bottom:10px">
        <div class="card-body" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span style="font-weight:800;color:#1e40af"><i class="fas fa-pen-to-square"></i> الخانات معبّأة تلقائياً من ملفه ورواتبه —</span>
            <button type="button" class="btn btn-sm btn-primary" id="ofeEdit" onclick="ofeToggle()"><i class="fas fa-pen"></i> تعديل الخانات / Modifier</button>
            <form method="post" id="ofeSaveForm" style="display:inline" onsubmit="return ofeCollect(this)">
                <?= csrfField() ?><input type="hidden" name="ofe_action" value="save"><input type="hidden" name="ofe_form" value="<?= e($form) ?>"><input type="hidden" name="ofe_emp" value="<?= $empId ?>"><input type="hidden" name="ofe_data" value="">
                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> حفظ / Enregistrer</button>
            </form>
            <?php if ($saved['updated_at']): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف التعديلات المحفوظة والرجوع للتعبئة التلقائية؟')">
                <?= csrfField() ?><input type="hidden" name="ofe_action" value="delete"><input type="hidden" name="ofe_form" value="<?= e($form) ?>"><input type="hidden" name="ofe_emp" value="<?= $empId ?>">
                <button type="submit" class="btn btn-sm btn-light"><i class="fas fa-trash"></i> حذف المحفوظ — رجّع التلقائي</button>
            </form>
            <span class="text-muted" style="font-size:12px">محفوظ: <?= e((string)$saved['updated_at']) ?> — <?= e((string)$saved['updated_by']) ?></span>
            <?php else: ?><span class="text-muted" style="font-size:12px">(لا تعديلات محفوظة — القيم تلقائية)</span><?php endif; ?>
            <span class="text-muted" style="font-size:12px;flex-basis:100%">اكبس «تعديل» ثم اكتب مباشرة داخل أي خانة (ومربّعات التأشير بكبسة)، ثم «حفظ». الطباعة/PDF/إكسل/وورد من الشريط أعلاه.</span>
        </div>
    </div>
    <style>
    .ofe-editing .ofe{outline:1.5px dashed #2563eb;background:#fffbe6;cursor:text;min-width:24px}
    .ofe-editing .ofe-box{cursor:pointer;outline:1.5px dashed #2563eb}
    .eos-box{border:1.5px solid #222;padding:8px 14px;margin-top:8px}
    .eos-title{text-align:center;font-weight:800;font-size:16pt;margin:4px 0 0}
    .eos-sub{text-align:center;font-size:11pt;margin-bottom:6px}
    .eos-p{font-size:12pt;line-height:1.9;margin:6px 0}
    .eos-sign{display:grid;grid-template-columns:repeat(5,1fr);text-align:center;margin:26px 10px 8px;font-weight:800;text-decoration:underline;font-size:12pt}
    .eos-note{font-size:10.5pt;text-align:center;margin-top:10px;line-height:1.7}
    .eos-table{width:100%;border-collapse:collapse;font-size:11.5pt;margin-top:6px}
    .eos-table th,.eos-table td{border:1.2px solid #222;padding:3px 6px;text-align:center;height:24px}
    .eos-table th{font-weight:800}
    .eos-table td.m{text-align:right;white-space:nowrap}
    .eos-table td.shade{background:#9ca3af}
    .eos-sep{border-top:1.5px solid #222;margin:10px 0 6px;text-align:center;font-weight:800;position:relative;top:-2px}
    </style>
    <script>
    function ofeToggle(){ var d=document.getElementById('ppExportArea'); var on=!d.classList.contains('ofe-editing'); d.classList.toggle('ofe-editing',on);
        d.querySelectorAll('.ofe').forEach(function(x){ x.contentEditable = on ? 'true' : 'false'; });
        var b=document.getElementById('ofeEdit'); b.innerHTML = on ? '<i class="fas fa-check"></i> إنهاء التعديل' : '<i class="fas fa-pen"></i> تعديل الخانات / Modifier'; }
    document.addEventListener('click', function(ev){ var bx=ev.target.closest('.ofe-box'); if(!bx) return; var d=document.getElementById('ppExportArea'); if(!d.classList.contains('ofe-editing')) return;
        var on = bx.getAttribute('data-v')==='X' ? '' : 'X'; bx.setAttribute('data-v',on); bx.querySelector('.bx').innerHTML = on || '&nbsp;'; });
    function ofeCollect(f){ var o={}; document.querySelectorAll('#ppExportArea .ofe').forEach(function(x){ o[x.getAttribute('data-k')] = x.innerText.trim(); });
        document.querySelectorAll('#ppExportArea .ofe-box').forEach(function(x){ o[x.getAttribute('data-k')] = x.getAttribute('data-v')||''; });
        f.querySelector('input[name=ofe_data]').value = 'b64:' + btoa(unescape(encodeURIComponent(JSON.stringify(o)))); return true; } // base64: البرنامج يعقّم $_POST (يحوّل الاقتباسات) فيتخرّب JSON الخام
    </script>
    <?php return ob_get_clean();
}

/** معالجة حفظ/حذف التعديلات (POST) — تُستدعى قبل أي إخراج */
function eosHandlePost(PDO $db): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ofe_action'])) return;
    requireCsrf();
    if (!canEdit()) { $_SESSION['flash_error'] = 'غير مسموح — حساب قراءة فقط.'; return; }
    $form = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['ofe_form'] ?? ''));
    $empId = (int)($_POST['ofe_emp'] ?? 0);
    if (!isset(eosForms()[$form]) || $empId <= 0) return;
    $who = (string)($_SESSION['username'] ?? '');
    if ($_POST['ofe_action'] === 'save') {
        $raw = (string)($_POST['ofe_data'] ?? '');
        if (strpos($raw, 'b64:') === 0) $raw = (string)base64_decode(substr($raw, 4));
        else $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // JSON خام مرّ بتعقيم $_POST
        $data = json_decode($raw, true);
        if (!is_array($data)) { $_SESSION['flash_error'] = 'لم يُحفَظ شيء — البيانات غير مقروءة.'; }
        else { ofeSave($db, $empId, $form, $data, $who); $_SESSION['flash_success'] = '💾 حُفظت خانات النموذج لهذا الموظف — تظهر بدل التلقائية.'; }
    } elseif ($_POST['ofe_action'] === 'delete') {
        ofeDelete($db, $empId, $form); $_SESSION['flash_success'] = '🗑️ حُذفت التعديلات المحفوظة — رجعت التعبئة التلقائية.';
    }
    header('Location: ' . BASE_URL . 'pages/official_forms.php?form=' . urlencode($form) . '&employee_id=' . $empId); exit;
}

/* ============================ رسم النماذج الثلاثة ============================ */

/** رأس النماذج الثلاثة كما بالورق (بلا ترويسة مدرسة — نموذج الصندوق) */
function eosHead(string $sub = ''): string {
    return '<div class="eos-title">جانب المديرية الفنية – دائرة تعويض نهاية الخدمة</div>' . ($sub !== '' ? '<div class="eos-sub">' . e($sub) . '</div>' : '');
}

/** p1 — مستند تصفية تعويض نهاية خدمة */
function eosRenderDoc(array $emp, array $d): string {
    $s = $d['school'];
    $pct = rtrim(rtrim(number_format((float)$d['rate_pct'], 2, '.', ''), '0'), '.');
    ob_start(); ?>
    <div class="official-doc cnss-form rtl eos-doc" id="ppExportArea">
        <?= eosHead('(مستند تصفية تعويض نهاية خدمة)') ?>
        <div class="eos-box">
            <div class="fline"><span class="lbl">تفيد مؤسسة :</span> <?= ofe('inst_name', $s['name_ar'] ?? '', 'lg') ?> <span class="lbl">رقمها في الصندوق :</span> <?= ofe('inst_no', $s['nssf_employer_number'] ?? '', 'g') ?></div>
            <div class="fline"><span class="lbl">العنوان :</span> <?= ofe('inst_addr', $s['address'] ?? '', 'lg') ?></div>
            <div class="fline"><span class="lbl">هاتف :</span> <?= ofe('inst_phone', $s['phone'] ?? '', 'g') ?> <span class="lbl">بريد إلكتروني :</span> <?= ofe('inst_email', $s['email'] ?? '', 'lg') ?></div>
            <div class="fline"><span class="lbl">إن المضمون :</span> <?= ofe('emp_name', empFullNameAr($emp), 'lg') ?> <span class="lbl">رقمه في الصندوق :</span> <?= ofe('emp_no', $emp['nssf_number'] ?? '', 'g') ?></div>
            <div class="fline"><span class="lbl">عدّ لحسابها من تاريخ :</span> <?= ofe('from', eosDmy($d['from']), 'g') ?> <span class="lbl">ولغاية :</span> <?= ofe('to', eosDmy($d['to']), 'g') ?> <span class="lbl">وكان أجره محدداً على أساس :</span></div>
            <div class="fline"><?= ofeBox('b_month', '', true) ?> <span class="lbl">شهري وبلغ مقدار أجره عن الشهر الأخير مع جميع لواحقه</span> <?= ofe('m_wage', eosNum($d['last_wage']), 'g') ?> <span class="lbl">ل.ل.</span></div>
            <div class="fline"><span class="lbl">فقط :</span> <?= ofe('m_words', $d['last_wage'] > 0 ? numToArabicWords((int)$d['last_wage']) . ' ليرة لبنانية' : '', 'lg') ?></div>
            <div class="fline"><?= ofeBox('b_week', '', false) ?> <span class="lbl">أسبوعي وبلغ عدد أسابيع العمل</span> <?= ofe('w_weeks', '', 'g') ?> <span class="lbl">وأجره عن الأسبوع الأخير :</span> <?= ofe('w_wage', '', 'g') ?> <span class="lbl">ل.ل.</span></div>
            <div class="fline"><span class="lbl">فقط :</span> <?= ofe('w_words', '', 'lg') ?></div>
            <div class="fline"><?= ofeBox('b_day', '', false) ?> <span class="lbl">يومي وبلغ مجموع أيام العمل</span> <?= ofe('d_days', '', 'g') ?> <span class="lbl">وأجره عن اليوم الأخير :</span> <?= ofe('d_wage', '', 'g') ?> <span class="lbl">ل.ل.</span></div>
            <div class="fline"><span class="lbl">فقط :</span> <?= ofe('d_words', '', 'lg') ?></div>
            <div class="fline"><?= ofeBox('b_hour', '', false) ?> <span class="lbl">بالساعة وبلغ مجموع ساعات العمل</span> <?= ofe('h_hours', '', 'g') ?> <span class="lbl">وأجره عن الساعة الأخيرة :</span> <?= ofe('h_wage', '', 'g') ?> <span class="lbl">ل.ل.</span></div>
            <div class="fline"><span class="lbl">فقط :</span> <?= ofe('h_words', '', 'lg') ?></div>
            <div class="fline" style="margin-top:14px"><span class="lbl">كما تفيد المؤسسة بأن الأجور المدفوعة للمضمون من تاريخ</span> <?= ofe('paid_from', eosDmy($d['from']), 'g') ?> <span class="lbl">ولغاية</span> <?= ofe('paid_to', eosDmy($d['to']), 'g') ?></div>
            <div class="fline"><span class="lbl">بلغ مجموعها :</span> <?= ofe('total', eosNum($d['total']), 'g') ?> <span class="lbl">ل.ل. فقط.</span> <?= ofe('total_words', $d['total'] > 0 ? numToArabicWords((int)$d['total']) . ' ليرة لبنانية' : '', 'lg') ?></div>
            <div class="fline"><span class="lbl">وإن الاشتراكات المتوجبة للصندوق : المجموع X <?= e($pct) ?> ٪ :</span> <?= ofe('contrib', eosNum($d['contrib']), 'g') ?> <span class="lbl">ل.ل.</span></div>
            <div class="fline"><span class="lbl">فقط :</span> <?= ofe('contrib_words', $d['contrib'] > 0 ? numToArabicWords((int)$d['contrib']) . ' ليرة لبنانية' : '', 'lg') ?></div>
            <div class="fline"><span class="lbl">وقد كانت طبيعة عمله في المؤسسة :</span> <?= ofe('work', $d['occupation'], 'lg') ?></div>
            <p class="eos-p" style="margin-top:14px">وعملاً بالأحكام القانونية والتنظيمية المعمول بها في الصندوق القاضية بتسديد المبالغ المستحقة لصندوق تعويض نهاية الخدمة (مبلغ التسوية) خلال مهلة ثلاثين يوماً من تاريخ تبلّغنا بقيمة الدين المتوجب للصندوق،</p>
            <div class="fline"><span class="lbl">تفوّض المدير / مندوب المؤسسة السيد</span> <?= ofe('delegate', $s['director_name'] ?? '', 'lg') ?> <span class="lbl">باستلام الدعوة لتسديد مبلغ التسوية والتوقيع على استلامها</span></div>
            <p class="eos-p">ويعتبر تاريخ الاستلام الناشئ عن هذا التفويض بمثابة تبلّغ رسمي من قبلنا تسري بموجبه المهلة الجديدة لدفع الدين .</p>
            <div class="eos-sign"><span>التاريـــــخ</span><span>خاتم المســـؤول</span><span>صفته</span><span>توقيعــــه</span><span>خاتم المؤسسة</span></div>
            <div class="eos-sign" style="text-decoration:none;margin-top:0;font-weight:700"><span><?= ofe('sign_date', eosDmy(date('Y-m-d')), 'g') ?></span><span>&nbsp;</span><span><?= ofe('sign_title', 'المدير', 'g') ?></span><span>&nbsp;</span><span>&nbsp;</span></div>
            <div class="eos-sep">إفادة المضمون / صاحب الحق / الوكيل</div>
            <div class="fline"><span class="lbl">أنا المضمون / صاحب الحق / الوكيل الموقع أدناه</span> <?= ofe('ack_name', empFullNameAr($emp), 'lg') ?> <span class="lbl">أوافق على قيمة الأجر أو</span></div>
            <p class="eos-p">الكسب وعلى قيمة حساب الأجور والاشتراكات المبينة في إفادة المؤسسة أعلاه .</p>
            <div class="fline"><span class="lbl">التاريــــــخ :</span> <?= ofe('ack_date', '', 'g') ?> <span class="lbl">التوقيع :</span> <?= ofe('ack_sign', '', 'lg') ?></div>
        </div>
        <div class="eos-note">إن الأجر أو الكسب الذي يتخذ أساساً لحساب تعويض نهاية الخدمة يشمل على مجموع الدخل الناتج عن العمل بما فيه جميع العناصر<br>( المواد ٩ – ٥١ – ٦٨ من قانون الصندوق الوطني للضمان الاجتماعي )</div>
    </div>
    <?php return ob_get_clean();
}

/** p2 — جدول أجور السنتين الأخيرتين */
function eosRender2y(array $emp, array $d): string {
    $s = $d['school'];
    $block = function (string $pre, int $y) use ($d): string {
        $mo = $d['months'][$y] ?? [];
        $tb = 0; $ta = 0; $to = 0;
        $h = '<table class="eos-table"><thead><tr><th style="width:15%">سنة ' . ofe($pre . '_year', (string)$y, 'g') . '</th><th>الراتب الأساسي</th><th>لواحق الراتب</th><th>مقبوضات اخرى</th><th>المجموع</th><th style="width:22%">ملاحظات</th></tr></thead><tbody>';
        for ($m = 1; $m <= 12; $m++) {
            $r = $mo[$m] ?? null;
            $tb += $r['base'] ?? 0; $ta += $r['acc'] ?? 0; $to += $r['oth'] ?? 0;
            $h .= '<tr><td class="m">شهر ' . monthName($m, 'ar') . '</td>'
                . ofeTd($pre . '_b' . $m, $r ? eosNum($r['base']) : '') . ofeTd($pre . '_a' . $m, $r ? eosNum($r['acc']) : '') . ofeTd($pre . '_o' . $m, $r ? eosNum($r['oth']) : '')
                . ofeTd($pre . '_s' . $m, $r ? eosNum($r['sum']) : '') . ofeTd($pre . '_n' . $m, '') . '</tr>';
        }
        $h .= '<tr><th>المجمـــوع</th>' . ofeTd($pre . '_tb', eosNum($tb), 'font-weight:800') . ofeTd($pre . '_ta', eosNum($ta), 'font-weight:800') . ofeTd($pre . '_to', eosNum($to), 'font-weight:800')
            . '<td class="shade"></td>' . ofeTd($pre . '_tn', '') . '</tr></tbody></table>';
        return $h;
    };
    ob_start(); ?>
    <div class="official-doc cnss-form rtl eos-doc" id="ppExportArea">
        <?= eosHead() ?>
        <div class="fline" style="margin-top:12px"><span class="lbl">تفيد مؤسسة :</span> <?= ofe('inst_name', $s['name_ar'] ?? '', 'lg') ?> <span class="lbl">رقمها في الصندوق :</span> <?= ofe('inst_no', $s['nssf_employer_number'] ?? '', 'g') ?></div>
        <div class="fline"><span class="lbl">بأن الأجير :</span> <?= ofe('emp_name', empFullNameAr($emp), 'lg') ?> <span class="lbl">رقمه في الصندوق :</span> <?= ofe('emp_no', $emp['nssf_number'] ?? '', 'g') ?></div>
        <div class="eos-p">قد تقاضى أجوره خلال السنتين الأخيرتين وفقاً لما هو مبين أدناه :</div>
        <?= $block('y1', (int)$d['y1']) ?>
        <?= $block('y2', (int)$d['y2']) ?>
        <div class="eos-sign"><span>التاريـــــخ</span><span>خاتم المســـؤول</span><span>صفته</span><span>توقيعــــه</span><span>خاتم المؤسسة</span></div>
        <div class="eos-sign" style="text-decoration:none;margin-top:0;font-weight:700"><span><?= ofe('sign_date', eosDmy(date('Y-m-d')), 'g') ?></span><span>&nbsp;</span><span><?= ofe('sign_title', 'المدير', 'g') ?></span><span>&nbsp;</span><span>&nbsp;</span></div>
    </div>
    <?php return ob_get_clean();
}

/** p3 — جدول بالأجور السنوية */
function eosRenderAnnual(array $emp, array $d): string {
    $s = $d['school'];
    $yFrom = (int)$d['hire_y']; $yTo = max((int)$d['y2'], $yFrom);
    $tot = 0;
    ob_start(); ?>
    <div class="official-doc cnss-form rtl eos-doc" id="ppExportArea">
        <div class="eos-title" style="font-size:14pt">جدول بالأجور السنوية</div>
        <div class="fline" style="margin-top:14px"><span class="lbl">تفيد مؤسسة</span> <?= ofe('inst_name', $s['name_ar'] ?? '', 'lg') ?> <span class="lbl">رقمها في الضمان</span> <?= ofe('inst_no', $s['nssf_employer_number'] ?? '', 'g') ?></div>
        <div class="fline"><span class="lbl">أن المضمون</span> <?= ofe('emp_name', empFullNameAr($emp), 'lg') ?> <span class="lbl">رقمه في الضمان</span> <?= ofe('emp_no', $emp['nssf_number'] ?? '', 'g') ?></div>
        <div class="fline"><span class="lbl">عمل لديها من تاريخ</span> <?= ofe('from', eosDmy($d['from']), 'g') ?> <span class="lbl">لتاريخ</span> <?= ofe('to', eosDmy($d['to']), 'g') ?></div>
        <div class="eos-p">وقد تقاضى أجوره السنوية على النحو الآتي:</div>
        <table class="eos-table"><thead><tr><th style="width:14%">السنة</th><th style="width:26%">الأجور</th><th style="width:34%">أجور إضافية بموجب تقرير تفتيش<br>أو ملحق التصريح السنوي</th><th>ملاحظات</th></tr></thead><tbody>
        <?php $n = 0; for ($y = $yFrom; $y <= $yTo; $y++): $v = (int)($d['years'][$y] ?? 0); $tot += $v; $n++; ?>
            <tr><?= ofeTd('yr_y' . $y, (string)$y, 'font-weight:700') ?><?= ofeTd('yr_w' . $y, eosNum($v)) ?><?= ofeTd('yr_x' . $y, '') ?><?= ofeTd('yr_n' . $y, '') ?></tr>
        <?php endfor; for ($i = $n; $i < 14; $i++): ?>
            <tr><?= ofeTd('yr_ey' . $i, '') ?><?= ofeTd('yr_ew' . $i, '') ?><?= ofeTd('yr_ex' . $i, '') ?><?= ofeTd('yr_en' . $i, '') ?></tr>
        <?php endfor; ?>
            <tr><th>المجموع</th><?= ofeTd('yr_tot', eosNum($tot), 'font-weight:800') ?><?= ofeTd('yr_totx', '') ?><td></td></tr>
        </tbody></table>
        <div class="eos-sign" style="text-decoration:none"><span>اسم وصفة الموقع: <?= ofe('sign_name', ($s['director_name'] ?? '') . ' — المدير', 'g') ?></span><span>التوقيع</span><span>ختم المؤسسة</span></div>
    </div>
    <?php return ob_get_clean();
}
