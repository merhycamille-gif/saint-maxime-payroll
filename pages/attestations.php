<?php
/**
 * Attestations / إفادات — كل لغة مستقلة (عربي / فرنسي / إنكليزي)، رسمية، طباعة وتصدير.
 * الأنواع: راتب (مع الصفوف والمواد) / عمل / ضمان / استقالة / صرف من الخدمة / براءة ذمة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll_calculator.php';
require_once __DIR__ . '/../includes/translit_ar_fr.php';
requireLogin();

$currentPage = 'attestations';
$pageTitle = 'Attestations / إفادات';
$db = getDB();

$ATT_TYPES = [
    'cnss'           => ['fr' => 'Attestation pour la CNSS',     'en' => 'Social Security Certificate',  'ar' => 'إفادة للضمان الاجتماعي'],
    'cnss_travail'   => ['fr' => 'Attestation de travail CNSS (officiel)','en' => 'CNSS Work Attestation (official)','ar' => 'إفادة عمل للضمان (نموذج رسمي)'],
    'salaire'        => ['fr' => 'Attestation de salaire',        'en' => 'Salary Certificate',          'ar' => 'إفادة راتب'],
    'tadris'         => ['fr' => "Attestation d'enseignement",    'en' => 'Teaching Certificate',        'ar' => 'إفادة تدريس'],
    'embassy'        => ['fr' => 'Attestation (ambassade, EN)',   'en' => 'Attestation (Embassy, EN)',   'ar' => 'إفادة للسفارة (إنكليزي)'],
    'riaaya'         => ['fr' => 'Attestation (organisme)',       'en' => 'Attestation (sponsor body)',  'ar' => 'إفادة للرعاية'],
    'anhaa_khedme'   => ['fr' => 'Lettre de fin de service (remise)', 'en' => 'End-of-Service Letter (handed)', 'ar' => 'كتاب إنهاء خدمات (في المدرسة)'],
    'anhaa_mail'     => ['fr' => 'Lettre de fin de service (courrier)','en' => 'End-of-Service Letter (by mail)','ar' => 'كتاب إنهاء خدمات (بالبريد المضمون)'],
    'talab_istiqala' => ['fr' => 'Demande de démission',         'en' => 'Resignation Request',          'ar' => 'طلب استقالة'],
    'afade_madrasiya'=> ['fr' => 'Attestation scolaire (Caisse)','en' => 'School Attestation (Fund)',     'ar' => 'إفادة مدرسية (صندوق التعويضات)'],
    'isqat_haq'      => ['fr' => 'Renonciation de droits',       'en' => 'Waiver of Rights',             'ar' => 'إفادة إسقاط حق'],
    'baraa_zimma'    => ['fr' => 'Quittance et décharge',        'en' => 'Release and Discharge',        'ar' => 'إقرار وإبراء ذمّة وإسقاط حق'],
    'iqrar'          => ['fr' => 'Déclaration (subventions)',    'en' => 'Declaration (grants)',         'ar' => 'إقرار (منح مالية + عدم ترك التعليم)'],
    'aqd_taalim'     => ['fr' => "Contrat d'enseignement",       'en' => 'Teaching Contract',            'ar' => 'عقد تعليم'],
    'notice_school'  => ['fr' => 'Avertissement (remis à l\'école)','en' => 'Warning (handed at school)','ar' => 'إنذار (يُسلَّم في المدرسة)'],
    'notice_mail'    => ['fr' => 'Avertissement (par courrier)',  'en' => 'Warning (by mail)',           'ar' => 'إنذار (بالبريد المضمون)'],
];
$DOC_LANGS = ['ar' => 'العربية', 'fr' => 'Français', 'en' => 'English'];

$employeeId = (int)($_GET['employee_id'] ?? 0);
$type    = $_GET['type'] ?? '';
$docLang = $_GET['lang_doc'] ?? ($_SESSION['lang'] ?? 'ar');
if (!isset($DOC_LANGS[$docLang])) $docLang = 'ar';
$effDate = $_GET['date'] ?? date('Y-m-d');
$emp = null;

if ($employeeId > 0) {
    requireSchoolSelected();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        $_SESSION['flash_error'] = 'الموظف غير موجود في هذه المدرسة / Employé introuvable';
        header('Location: ' . BASE_URL . 'pages/attestations.php');
        exit;
    }
    $exportTitle = 'Attestation_' . ($type ?: 'doc') . '_' . strtoupper($docLang) . '_' . $emp['first_name_fr'] . '_' . $emp['last_name_fr'];
    // الأولوية لرقم/إيميل المدرسة (يظهران افتراضاً بالبرومبت)، وإلا رقم/إيميل الأستاذ
    $schForExport = currentSchool();
    $exportOpts = [
        'phone' => (trim((string)($schForExport['phone'] ?? '')) ?: ($emp['phone1'] ?: '')),
        'email' => (trim((string)($schForExport['email'] ?? '')) ?: ($emp['email'] ?? '')),
    ];
}

$school = currentSchool();
$schoolNameFr = $school['name_fr'] ?? getSetting('school_name_fr', 'Collège');
$schoolNameAr = $school['name_ar'] ?? getSetting('school_name_ar', 'المدرسة');
$schoolAddr   = $school['address'] ?? getSetting('school_address', '');
$schoolPhone  = $school['phone'] ?? getSetting('school_phone', '');
$director     = $school['director_name'] ?? '';
$employerNssf = $school['nssf_employer_number'] ?? '';

// المسؤولون الموقّعون: المستخدم يختار أيّهم يظهر اسمه وهاتفه في التوقيع (الافتراضي الأول/المدير)
$signatories = schoolSignatories($school);
$sigIdx = (int)($_GET['sig'] ?? 0);
if ($sigIdx < 0 || $sigIdx >= count($signatories)) $sigIdx = 0;
$sig = $signatories[$sigIdx];
if (trim((string)($sig['name'] ?? '')) !== '') $director = $sig['name'];
$sigNameFr = $sig['name_fr'] ?? '';
$sigPhone  = $sig['phone'] ?? '';

include __DIR__ . '/../includes/header.php';

if (!$emp):
    [$ayf, $ayp] = yearEmploymentFilter(activeSchoolYear()); // فلترة حسب السنة الدراسية المختارة
    $aStmt = $db->prepare("SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar
                             FROM employees WHERE is_deleted = 0" . schoolScopeSql() . $ayf . " ORDER BY FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)");
    $aStmt->execute($ayp);
    $employees = $aStmt->fetchAll();
?>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-file-signature"></i> Générer une attestation / إصدار إفادة</h3></div>
        <div class="card-body">
            <?php if (isAllSchools()): ?>
                <div class="alert alert-warning">اختر مدرسة محددة من الأعلى أولاً / Sélectionnez une école.</div>
            <?php elseif (!$employees): ?>
                <div class="alert alert-info">لا يوجد موظفون في هذه المدرسة.</div>
            <?php else: ?>
            <form method="GET" class="form-row cols-4">
                <div class="form-group">
                    <label class="form-label">Employé / الموظف</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Choisir / اختر —</option>
                        <?php foreach ($employees as $em): ?>
                        <option value="<?= $em['id'] ?>"><?= e($em['employee_code'].' — '.$em['first_name_fr'].' '.$em['last_name_fr']) ?> / <?= e($em['first_name_ar'].' '.$em['last_name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Type / نوع الإفادة</label>
                    <select name="type" class="form-select" required>
                        <?php foreach ($ATT_TYPES as $k => $lbl): ?>
                        <option value="<?= $k ?>"><?= e($lbl['fr']) ?> / <?= e($lbl['ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Langue / اللغة</label>
                    <select name="lang_doc" class="form-select">
                        <?php foreach ($DOC_LANGS as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $k===$docLang?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date (استقالة/صرف/براءة)</label>
                    <input type="date" name="date" class="form-control" value="<?= e($effDate) ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <button class="btn btn-primary"><i class="fas fa-file-export"></i> Générer / إصدار</button>
                </div>
            </form>
            <p class="text-muted mt-3"><i class="fas fa-info-circle"></i> كل إفادة تصدر بلغة واحدة مستقلة ومرتّبة. تقدر تبدّل اللغة من الوثيقة وتطبع/تصدّر كل لغة لحالها. حقل التاريخ للاستقالة والصرف وبراءة الذمة.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else:
    // نموذج «إفادة عمل للضمان» الرسمي: يُملأ من قالب المستخدم → أزرار تحميل (لا رسالة HTML)
    if ($type === 'cnss_travail'):
        $ts  = strtotime($effDate) ?: time();
        $d   = (int)date('j', $ts); $mo = (int)date('n', $ts); $yr = (int)date('Y', $ts);
        $nm  = preg_replace('/\s+/', ' ', trim(($emp['first_name_ar'] ?? '') . ' ' . ($emp['father_name_ar'] ?? '') . ' ' . ($emp['last_name_ar'] ?? '')));
        if ($nm === '') $nm = trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['last_name_fr'] ?? ''));
        $end = $mo - 1; $mlist = [];
        for ($i = 0; $i < 7; $i++) { $idx = $end - (6 - $i); $norm = (($idx - 1) % 12 + 12) % 12 + 1; $mlist[] = monthName($norm, 'ar'); }
        $expBase = BASE_URL . 'pages/official_export.php?form=cnss_work_attestation&emp=' . (int)$employeeId . '&d=' . $d . '&mo=' . $mo . '&yr=' . $yr;
    ?>
    <div class="card no-print" style="max-width:760px;margin:0 auto">
        <div class="card-header"><h3><i class="fas fa-file-medical"></i> إفادة عمل للضمان (نموذج رسمي) — <?= e($nm) ?></h3></div>
        <div class="card-body">
            <form method="GET" class="form-row cols-3" style="align-items:end;margin-bottom:14px">
                <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                <input type="hidden" name="type" value="cnss_travail">
                <div class="form-group">
                    <label class="form-label">تاريخ الإفادة (في:)</label>
                    <input type="date" name="date" class="form-control" value="<?= e($effDate) ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group"><button class="btn btn-secondary"><i class="fas fa-sync"></i> تحديث الأشهر</button></div>
            </form>
            <div class="alert alert-info">
                الأشهر السبعة المحتسَبة تلقائياً من التاريخ (من الأقدم للأحدث): <strong><?= e(implode(' ، ', $mlist)) ?></strong> — كلّها «دوام كامل».<br>
                التاريخ تحت الإفادة: <strong><?= $d . ' / ' . $mo . ' / ' . $yr ?></strong>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <a class="btn btn-danger btn-lg" href="<?= e($expBase . '&format=pdf') ?>"><i class="fas fa-file-pdf"></i> تحميل PDF رسمي</a>
                <a class="btn btn-success btn-lg" href="<?= e($expBase . '&format=xlsx') ?>"><i class="fas fa-file-excel"></i> Excel رسمي</a>
                <a class="btn btn-light" href="<?= BASE_URL ?>pages/attestations.php"><i class="fas fa-arrow-right"></i> رجوع</a>
            </div>
            <p class="text-muted mt-3"><i class="fas fa-info-circle"></i> يُملأ تلقائياً من القالب الرسمي: المدرسة ورقم مؤسستها في الضمان، واسم الأجير ورقم ضمانه وسنة ولادته، والأشهر حسب تاريخ الإفادة.</p>
        </div>
    </div>
    <?php
        include __DIR__ . '/../includes/footer.php';
        return;
    endif;
    if (!isset($ATT_TYPES[$type])) $type = 'cnss';
    $sessLang = $_SESSION['lang'] ?? 'fr';
    $nomFr = trim($emp['first_name_fr'].' '.($emp['father_name_fr'] ? $emp['father_name_fr'].' ' : '').$emp['last_name_fr']);
    $nomAr = trim($emp['first_name_ar'].' '.($emp['father_name_ar'] ? $emp['father_name_ar'].' ' : '').$emp['last_name_ar']);
    if ($nomAr === '') $nomAr = $nomFr;
    $fnFr = ['fr'=>employeeTypeLabel($emp['employee_type'],'fr'),
             'ar'=>employeeTypeLabel($emp['employee_type'],'ar'),
             'en'=>(['enseignant_titulaire'=>'Tenured teacher','enseignant_contractuel'=>'Contract teacher','employe'=>'Administrative employee'][$emp['employee_type']] ?? $emp['employee_type'])];
    $today = date('d/m/Y');
    $hireFmt = formatDate($emp['hire_date']);
    $titFmt  = formatDate($emp['titularization_date']);
    $effFmt  = formatDate($effDate);
    $classesAr = trim((string)$emp['niveau_scolaire']) !== '' ? str_replace(',', '، ', $emp['niveau_scolaire']) : '';
    $classesLat = trim((string)$emp['niveau_scolaire']) !== '' ? str_replace(',', ', ', $emp['niveau_scolaire']) : '';
    $subjects = trim((string)$emp['subjects_taught']);
    $isTit = $emp['employee_type'] === 'enseignant_titulaire';

    $sal = $db->prepare("SELECT * FROM monthly_salaries WHERE employee_id = ? ORDER BY year DESC, month DESC LIMIT 1");
    $sal->execute([$employeeId]); $sal = $sal->fetch();
    if ($sal) {
        $basePlusEch=(float)$sal['base_plus_echelon_lbp']; $net=(float)$sal['net_salary_lbp'];
        $netUsd=(float)$sal['net_salary_usd']; $cnssAmt=(float)$sal['cnss_amount_lbp']; $schoolCnss=(float)$sal['school_cnss_8_lbp'];
        $salPeriodAr = monthName((int)$sal['month'],'ar').' '.$sal['year'];
        $salPeriodLat = monthName((int)$sal['month'],'fr').' '.$sal['year'];
    } else {
        $basePlusEch=scaleSalaryLBP($emp['current_grade']); $net=$basePlusEch; $netUsd=0;
        $cnssAmt=round($basePlusEch*0.03); $schoolCnss=round($basePlusEch*0.08);
        $salPeriodAr=currentSchoolYear(); $salPeriodLat=$salPeriodAr;
    }
    $yAr=$yFr=$yEn='';
    if ($emp['hire_date']) {
        $diff=(new DateTime($emp['hire_date']))->diff(new DateTime($effDate));
        $yAr=$diff->y.' سنة'.($diff->m?" و{$diff->m} شهر":'');
        $yFr=$diff->y.' an(s)'.($diff->m?" et {$diff->m} mois":'');
        $yEn=$diff->y.' year(s)'.($diff->m?" and {$diff->m} month(s)":'');
    }
    // خيار مكوّنات الراتب في الإفادة: الأساس بعد التدرّج وحده، أو + الأجر الإضافي، أو + مكافأة ومساعدة
    $extraW = $sal ? (int)((float)$sal['extra_lbp'] + (float)$sal['prime_fixe_lbp']) : 0;
    $aideW  = $sal ? (int)(float)$sal['aide_complementaire_lbp'] : 0;
    $incExtra = !empty($_GET['inc_extra']);
    $incAide  = !empty($_GET['inc_aide']);
    $salShown = (int)$basePlusEch + ($incExtra ? $extraW : 0) + ($incAide ? $aideW : 0);
    // العملة: ليرة (افتراضي) أو دولار — التحويل عبر سعر صرف شهر الراتب
    $cur = (($_GET['cur'] ?? 'lbp') === 'usd') ? 'usd' : 'lbp';
    $fxRate = $sal ? getExchangeRate((int)$sal['month'], (int)$sal['year']) : getExchangeRate();
    $money   = function ($lbp) use ($cur, $fxRate) { return $cur === 'usd' ? formatUSD($fxRate > 0 ? $lbp / $fxRate : 0) : formatLBP($lbp); };
    $moneyAr = function ($lbp) use ($cur, $fxRate) { return $cur === 'usd' ? formatUSD($fxRate > 0 ? $lbp / $fxRate : 0) : (formatLBP($lbp, false) . ' ل.ل'); };
    $moneyWords = function ($lbp) use ($cur, $fxRate) {
        if ($cur === 'usd') return numToArabicWords((int)round($fxRate > 0 ? $lbp / $fxRate : 0)) . ' دولار أميركي';
        return numToArabicWords((int)round($lbp)) . ' ليرة لبنانية';
    };
    // مبلغ حرّ يكتبه المستخدم (تعويض الصرف المحسوب) — يُعرَض كما هو بالعملة المختارة بلا تحويل
    $eos = (int)preg_replace('/[^0-9]/', '', (string)($_GET['eos'] ?? ''));
    $embAmt = (int)preg_replace('/[^0-9]/', '', (string)($_GET['emb_amt'] ?? '')); // مبلغ إفادة السفارة (يدوي)
    $isqMode = in_array(($_GET['isq'] ?? ''), ['istiqala', 'sarf'], true) ? $_GET['isq'] : ''; // إسقاط الحق: استقالة أو صرف
    $grant   = (int)preg_replace('/[^0-9]/', '', (string)($_GET['grant'] ?? '')); // قيمة المنحة بالدولار (إقرار) — تظهر بالأرقام والحروف
    // شعار المدرسة للترويسة (خاص بالمدرسة أو الموحّد)
    $logoUrl = schoolLogoUrl($school);
    $logoImg = $logoUrl ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="" style="max-height:88px;max-width:150px;object-fit:contain">' : '';
    // مجموعات حسب نوع الإفادة + خيار «رأس/شعار المدرسة» (يختاره المستخدم لكل إفادة)
    $hasComponents = in_array($type, ['cnss', 'afade_madrasiya', 'isqat_haq', 'salaire', 'embassy'], true);
    $hasCurrency   = in_array($type, ['cnss', 'afade_madrasiya', 'isqat_haq', 'aqd_taalim', 'salaire', 'embassy'], true);
    $isNotice      = in_array($type, ['notice_school', 'notice_mail'], true);
    $defaultLogo   = in_array($type, ['anhaa_khedme', 'anhaa_mail', 'aqd_taalim', 'cnss', 'notice_school', 'notice_mail', 'salaire', 'tadris', 'embassy', 'riaaya'], true); // الصادرة عن المدرسة: الشعار افتراضياً
    $showLogo      = isset($_GET['logo']) ? ($_GET['logo'] === '1') : $defaultLogo;
    $subjectTxt    = trim((string)($_GET['subj_txt'] ?? '')); if ($subjectTxt === '') $subjectTxt = 'الإهمال في حفظ النظام والتربية';
    $assocTxt      = trim((string)($_GET['assoc_txt'] ?? '')); if ($assocTxt === '') $assocTxt = 'التابعة لجمعية الراهبات المخلصيات لسيدة البشارة المسجّلة لديكم تحت الرقم (.....)';
    // الترويسة الرسمية الكاملة كصورة خلفية (إن وُجدت لهذه المدرسة) — تُغني عن الترويسة المُعاد بناؤها
    $lhUrl = schoolLetterheadUrl($school, ($type === 'embassy') ? 'fr' : 'ar');
    $lhOn  = ($showLogo && $lhUrl !== '' && !in_array($type, ['notice_mail', 'anhaa_mail'], true)); // نماذج البريد لها ترويسة بريدية خاصة
    $showRecHead = ($showLogo && !$lhOn); // عرض الترويسة المُعاد بناؤها فقط حين لا توجد صورة ترويسة
    $lhStyle = $lhOn
        ? "width:794px;min-height:1123px;margin:0 auto;background:url('" . htmlspecialchars($lhUrl, ENT_QUOTES) . "') no-repeat;background-size:794px 1123px;padding:195px 95px 150px;box-sizing:border-box"
        : "max-width:820px;margin:0 auto;padding:28px 32px";
    $lhClass = $lhOn ? '' : 'card';
    $freeNum   = function ($v) use ($cur) { return $cur === 'usd' ? ('$' . number_format($v, 2)) : (formatLBP($v, false) . ' ل.ل'); };
    $freeWords = function ($v) use ($cur) { return numToArabicWords((int)round($v)) . ($cur === 'usd' ? ' دولار أميركي' : ' ليرة لبنانية'); };
    $L=$money($salShown); $N=$money($net); $U='';
    $nssf=cnssWithBirthYear($emp['nssf_number'], $emp['birth_date']);
    $rtl = ($docLang === 'ar');
    $qs = 'employee_id='.$employeeId.'&type='.urlencode($type).'&date='.urlencode($effDate).($incExtra?'&inc_extra=1':'').($incAide?'&inc_aide=1':'').($cur==='usd'?'&cur=usd':'').($eos>0?'&eos='.$eos:'').($isqMode?'&isq='.$isqMode:'').'&logo='.($showLogo?'1':'0').($isNotice?'&subj_txt='.urlencode($subjectTxt):'').($type==='riaaya'?'&assoc_txt='.urlencode($assocTxt):'').($embAmt>0?'&emb_amt='.$embAmt:'').($grant>0?'&grant='.$grant:'').($sigIdx>0?'&sig='.$sigIdx:'');
?>
    <div class="d-flex justify-between align-center mb-3 no-print" style="flex-wrap:wrap;gap:8px">
        <a href="<?= BASE_URL ?>pages/attestations.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Retour / رجوع</a>
        <div class="btn-group" role="group">
            <?php foreach ($DOC_LANGS as $lk => $lbl): ?>
            <a href="?<?= $qs ?>&lang_doc=<?= $lk ?>" class="btn btn-sm <?= $lk===$docLang?'btn-primary':'btn-light' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm" style="background:#0ea5e9;color:#fff" onclick="ppSendAtt('<?= e(addslashes('pages/attestations.php?'.$qs)) ?>','<?= e($exportOpts['email'] ?? '') ?>','<?= e($exportTitle ?? 'attestation') ?>')"><i class="fas fa-paper-plane"></i> إرسال بالإيميل (PDF تلقائي)</button>
    </div>
    <script>
    function ppSendAtt(target, defEmail, name){
        var to = window.prompt('إرسال الإفادة (PDF مرفق) إلى بريد:', defEmail || '');
        if (to === null || !to.trim()) return;
        window.location.href = '<?= BASE_URL ?>pages/send_attestation.php?target=' + encodeURIComponent(target) + '&to=' + encodeURIComponent(to.trim()) + '&name=' + encodeURIComponent(name);
    }
    </script>

    <form method="get" class="card no-print" style="margin-bottom:14px;background:#eef6ff">
        <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="lang_doc" value="<?= e($docLang) ?>">
        <input type="hidden" name="date" value="<?= e($effDate) ?>">
        <div class="card-body" style="padding:10px 14px">
            <strong>رأس المدرسة:</strong>
            <input type="hidden" name="logo" value="0">
            <label style="margin:0 10px;cursor:pointer"><input type="checkbox" name="logo" value="1" <?= $showLogo?'checked':'' ?> onchange="this.form.submit()"> ضع شعار المدرسة على الإفادة</label>
            <?php if (count($signatories) > 1): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>الموقّع المسؤول:</strong>
            <select name="sig" onchange="this.form.submit()" style="padding:3px 6px;margin-right:6px">
                <?php foreach ($signatories as $si => $sgo): ?>
                <option value="<?= $si ?>" <?= $si===$sigIdx?'selected':'' ?>><?= e($sgo['name'] . ($sgo['title']?' — '.$sgo['title']:'') . ($sgo['phone']?' ('.$sgo['phone'].')':'')) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?><input type="hidden" name="sig" value="0"><?php endif; ?>
            <?php if ($hasComponents || $hasCurrency || $type==='isqat_haq' || $isNotice): ?><span style="margin:0 16px;color:#cbd5e1">|</span><?php endif; ?>
            <?php if ($hasComponents): ?>
            <strong>مكوّنات الراتب:</strong>
            <label style="margin:0 12px;cursor:pointer"><input type="checkbox" name="inc_extra" value="1" <?= $incExtra?'checked':'' ?> onchange="this.form.submit()"> + الأجر الإضافي (<?= formatLBP($extraW,false) ?>)</label>
            <label style="cursor:pointer"><input type="checkbox" name="inc_aide" value="1" <?= $incAide?'checked':'' ?> onchange="this.form.submit()"> + مكافأة ومساعدة (<?= formatLBP($aideW,false) ?>)</label>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <?php endif; ?>
            <?php if ($hasCurrency): ?>
            <strong>العملة:</strong>
            <label style="margin:0 10px;cursor:pointer"><input type="radio" name="cur" value="lbp" <?= $cur==='lbp'?'checked':'' ?> onchange="this.form.submit()"> ليرة (ل.ل)</label>
            <label style="cursor:pointer"><input type="radio" name="cur" value="usd" <?= $cur==='usd'?'checked':'' ?> onchange="this.form.submit()"> دولار ($)</label>
            <?php endif; ?>
            <?php if ($type === 'isqat_haq'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>مبلغ تعويض الصرف المحسوب:</strong>
            <input type="text" name="eos" value="<?= $eos>0 ? (int)$eos : '' ?>" placeholder="اكتب المبلغ" style="width:150px;padding:3px 6px" onchange="this.form.submit()">
            <div style="margin-top:6px">
                <strong>الحالة:</strong>
                <label style="margin:0 10px;cursor:pointer"><input type="radio" name="isq" value="istiqala" <?= $isqMode==='istiqala'?'checked':'' ?> onchange="this.form.submit()"> قدّمت استقالتي</label>
                <label style="cursor:pointer"><input type="radio" name="isq" value="sarf" <?= $isqMode==='sarf'?'checked':'' ?> onchange="this.form.submit()"> صار صرفي من الخدمة</label>
            </div>
            <?php endif; ?>
            <?php if ($isNotice): ?>
            <div style="margin-top:6px"><strong>الموضوع / سبب الإنذار:</strong>
            <input type="text" name="subj_txt" value="<?= e($subjectTxt) ?>" style="width:60%;min-width:300px;padding:3px 6px" onchange="this.form.submit()"></div>
            <?php endif; ?>
            <?php if ($type === 'riaaya'): ?>
            <div style="margin-top:6px"><strong>الجهة ورقم التسجيل:</strong>
            <input type="text" name="assoc_txt" value="<?= e($assocTxt) ?>" style="width:70%;min-width:340px;padding:3px 6px" onchange="this.form.submit()"></div>
            <?php endif; ?>
            <?php if ($type === 'embassy'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>المبلغ الشهري (اكتبه):</strong>
            <input type="text" name="emb_amt" value="<?= $embAmt>0 ? (int)$embAmt : '' ?>" placeholder="المبلغ" style="width:140px;padding:3px 6px" onchange="this.form.submit()">
            <span style="color:#64748b">(اختر العملة دولار/ليرة من فوق)</span>
            <?php endif; ?>
            <?php if ($type === 'iqrar'): ?>
            <span style="margin:0 16px;color:#cbd5e1">|</span>
            <strong>قيمة المنحة (دولار أميركي):</strong>
            <input type="text" name="grant" value="<?= $grant>0 ? (int)$grant : '' ?>" placeholder="اكتب المبلغ" style="width:150px;padding:3px 6px" onchange="this.form.submit()">
            <?php if ($grant>0): ?><div style="margin-top:6px;color:#1e40af"><?= number_format($grant) ?> دولار أميركي — بالحروف: <strong><?= e(numToArabicWords($grant)) ?> دولار أميركي</strong></div><?php endif; ?>
            <?php endif; ?>
            <?php if ($hasComponents): ?>
            <div style="margin-top:6px;color:#1e40af">الراتب المعتمد بالإفادة: <strong><?= $moneyAr($salShown) ?></strong> (الأساس بعد التدرّج <?= $moneyAr((int)$basePlusEch) ?><?= $incExtra?' + الإضافي':'' ?><?= $incAide?' + المكافأة':'' ?>)<?php if ($cur==='usd'): ?> — سعر الصرف <?= formatLBP((int)$fxRate,false) ?><?php endif; ?></div>
            <?php elseif ($type === 'aqd_taalim'): ?>
            <div style="margin-top:6px;color:#1e40af">أساس الراتب بالعقد: <strong><?= $moneyAr((int)$basePlusEch) ?></strong><?php if ($cur==='usd'): ?> — سعر الصرف <?= formatLBP((int)$fxRate,false) ?><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </form>

    <?php
    // إفادة الضمان الرسمية «لمن يهمه الأمر» — بنفس نصّ نموذج المستخدم بالضبط.
    $attBase = (int)round($basePlusEch);
    $attSupp = ($incExtra ? $extraW : 0) + ($incAide ? $aideW : 0);
    $attTotal = $attBase + $attSupp;
    if ($type === 'cnss'):
    ?>
    <?php if ($lhOn): ?><style>@page{size:A4;margin:0}</style><?php endif; ?>
    <div id="ppExportArea" class="<?= $lhClass ?>" style="<?= $lhStyle ?>" dir="rtl">
        <div class="card-body" style="line-height:2.15;text-align:right;font-size:12px;<?= $lhOn?'padding:0':'' ?>">
            <?php if ($showRecHead && $logoImg): ?><div style="text-align:right;margin-bottom:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px"><?= $logoImg ?> &nbsp; <strong style="font-size:16px"><?= e($schoolNameAr) ?></strong></div><?php endif; ?>
            <div style="text-align:left;font-weight:700;line-height:1.7;margin-bottom:6px">
                الصندوق الوطني<br>للضمان الاجتماعي<br>
                <span style="font-weight:400">مكتب ـــــــــــــ</span><br>
                <span style="font-weight:400">رقم الوارد : ــــــــ</span><br>
                <span style="font-weight:400">تاريــــــخ : ــــــــ</span>
            </div>
            <h2 style="text-align:center;margin:18px 0 26px;text-decoration:underline">إفـــادة لمن يهمه الأمر</h2>
            <p>تفيد مؤسسة : <strong><?= e($schoolNameAr) ?></strong></p>
            <p>المسجَّلة في الصندوق الوطني للضمان الاجتماعي تحت الرقم <strong><?= e($employerNssf) ?></strong></p>
            <p>أنّ المضمون <strong><?= e($nomAr) ?></strong> رقمه <strong><?= e($emp['nssf_number']) ?></strong> قد بدأ العمل لدينا بدوام كامل</p>
            <p>اعتباراً من تاريخ <strong><?= $hireFmt ?></strong> بصفة (<strong><?= e($fnFr['ar']) ?></strong>)</p>
            <p>ويتقاضى راتباً شهرياً :</p>
            <p style="margin-right:34px">- أساس راتب عملاً بالقانون : <strong><?= $moneyAr($attBase) ?></strong></p>
            <p style="margin-right:34px">- ملحقات مدفوعة من أشخاص ثالثين : <strong><?= $moneyAr($attSupp) ?></strong></p>
            <p>المجموع : <strong><?= $moneyAr($attTotal) ?></strong> فقط <?= e($moneyWords($attTotal)) ?> لا غير .</p>
            <?php if ($emp['status']==='actif'): ?><p>وهو مستمر في عمله حتى تاريخه .</p><?php endif; ?>
            <div style="display:flex;justify-content:space-between;margin-top:54px">
                <div>التاريخ : <?= $today ?></div>
                <div style="text-align:center"><strong>الخاتم والتوقيع</strong>
                    <div style="margin-top:44px;border-top:1px solid #333;width:200px"></div></div>
            </div>
        </div>
    </div>
    <?php elseif (in_array($type, ['salaire','tadris','embassy','riaaya','anhaa_khedme','anhaa_mail','talab_istiqala','afade_madrasiya','isqat_haq','baraa_zimma','iqrar','aqd_taalim','notice_school','notice_mail'])):
        // النماذج الرسمية الخمسة من ملف المستخدم «افادات.XLS» — نفس النص حرفياً، مرتّبة على A4.
        $blank = function ($w = 110) { return '<span style="display:inline-block;min-width:' . (int)$w . 'px;border-bottom:1px dotted #555">&nbsp;</span>'; };
        $salFig  = $moneyAr($salShown);       // الراتب (أساس + المكوّنات المختارة) بالعملة المختارة — للإفادة المدرسية وإسقاط الحق
        $salWrd  = $moneyWords($salShown);
        $baseFig = $moneyAr((int)$basePlusEch); // أساس الراتب وحده — لعقد التعليم
        $dob      = $emp['birth_date'] ? formatDate($emp['birth_date']) : $blank(110);
        $bplace   = trim((string)($emp['birth_place'] ?? '')) ?: $blank(120);
        $phone1   = trim((string)($emp['phone1'] ?? '')) ?: $blank(120);
        $chk = '<span style="display:inline-block;width:13px;height:13px;border:1px solid #333;vertical-align:middle;margin:0 3px"></span>';
        $box = function ($on) { return '<span style="display:inline-block;width:13px;height:13px;border:1px solid #333;vertical-align:middle;margin:0 3px;text-align:center;line-height:11px;font-weight:700">' . ($on ? '×' : '') . '</span>'; };
        $vb  = function ($val, $w = 120) use ($blank) { $val = trim((string)$val); return $val !== '' ? '<strong>' . htmlspecialchars($val, ENT_QUOTES) . '</strong>' : $blank($w); };
        // السنوات
        $effYear = (int)date('Y', strtotime($effDate));
        $eMo = (int)date('n', strtotime($effDate));
        $syStart = ($eMo >= 10) ? $effYear : $effYear - 1;
        $nextSY  = ($syStart + 1) . '-' . ($syStart + 2);
        $curSY   = $syStart . '-' . ($syStart + 1);
        // معلومات العقد من ملف الأستاذ
        $natAr = in_array(strtolower((string)($emp['nationality'] ?? '')), ['lebanese','lebanaise','libanaise','لبنانية','لبناني']) ? 'لبنانية' : (string)($emp['nationality'] ?? '');
        $regNo = trim((string)($emp['civil_registry_number'] ?? '')) . (($emp['civil_registry_place'] ?? '') ? ' / ' . $emp['civil_registry_place'] : '');
        $addr  = implode(' - ', array_values(array_unique(array_filter(array_map(function ($x) { return trim((string)$x); }, [$emp['ville'] ?? '', $emp['quartier'] ?? '', $emp['rue'] ?? '', $emp['immeuble'] ?? '', ($emp['etage'] ?? '') ? ('طابق ' . $emp['etage']) : '', $emp['district'] ?? '', $emp['gouvernorat'] ?? ''])))));
        $dipAr = ($emp['diploma'] ?? '') ? diplomaLabel($emp['diploma'], 'ar') : '';
        $subj  = trim((string)($emp['subjects_taught'] ?? ''));
        $niveau = trim((string)($emp['niveau_scolaire'] ?? ''));
        $hpw   = ($emp['hours_per_week'] ?? null) !== null && $emp['hours_per_week'] !== '' ? rtrim(rtrim((string)$emp['hours_per_week'], '0'), '.') : '';
        $social = (string)($emp['social_status'] ?? 'celibataire');
        $isMarried = (strpos($social, 'marie') === 0);
        $nKids = (int)($emp['number_of_children'] ?? 0);
        $spouseWorks = (int)($emp['spouse_works'] ?? 0);
        $isTit = ($emp['employee_type'] === 'enseignant_titulaire');
        $isContr = ($emp['employee_type'] === 'enseignant_contractuel');
        // إفادات نمط مكسيموس (راتب/تدريس/سفارة/رعاية): الأقسام من ملف الأستاذ + ترويسة اتصال أسفل
        $nivMapAr = ['maternelle'=>'الحضانة','primaire'=>'الابتدائي','intermediaire'=>'المتوسط','secondaire'=>'الثانوي'];
        $nivMapEn = ['maternelle'=>'Kindergarten','primaire'=>'Primary','intermediaire'=>'Intermediate','secondaire'=>'Secondary'];
        $nivKeys = array_values(array_filter(array_map('trim', explode(',', (string)($emp['niveau_scolaire'] ?? '')))));
        $nivAr = array_values(array_filter(array_map(function ($k) use ($nivMapAr) { return $nivMapAr[$k] ?? ''; }, $nivKeys)));
        $nivEn = array_values(array_filter(array_map(function ($k) use ($nivMapEn) { return $nivMapEn[$k] ?? ''; }, $nivKeys)));
        $levelsAr = count($nivAr) > 2 ? ('في الأقسام ' . implode(' و', $nivAr)) : (count($nivAr) == 2 ? ('في القسمين ' . $nivAr[0] . ' و' . $nivAr[1]) : (count($nivAr) == 1 ? ('في قسم ' . $nivAr[0]) : ''));
        $levelsEn = count($nivEn) >= 2 ? ('at both ' . implode(' and ', $nivEn) . ' levels') : (count($nivEn) == 1 ? ('at the ' . $nivEn[0] . ' level') : '');
        $usdSal = $fxRate > 0 ? (int)round($salShown / $fxRate) : 0;
        // سعر إفادة السفارة: المبلغ اليدوي بالعملة المختارة، وإلا المحسوب بالدولار
        $embRate = $embAmt > 0 ? ($cur === 'usd' ? '$' . number_format($embAmt) : number_format($embAmt) . ' L.L') : ($usdSal > 0 ? '$' . number_format($usdSal) : '');
        $contactBits = array_filter([trim((string)($school['address'] ?? '')), $schoolPhone ? ('هاتف: ' . $schoolPhone) : '', trim((string)($school['email'] ?? ''))]);
        $contactFooter = implode('  |  ', $contactBits);
        // ترويسة عربية: الشعار يمين + اسم المدرسة (عربي) + الهاتف، بشكل رسمي
        // عربي: الشعار فوق على اليمين، واسم المدرسة والهاتف تحته على اليمين (مثل نموذج باقي المدارس)
        $schoolHead = '<div style="border-bottom:2px solid #1e3a5f;padding-bottom:10px;margin-bottom:18px;text-align:right">'
            . ($logoImg ? '<div style="margin-bottom:2px">' . $logoImg . '</div>' : '')
            . '<strong style="font-size:17px">' . e($schoolNameAr) . '</strong>'
            . ($schoolAddr ? '<br><small>' . e($schoolAddr) . '</small>' : '')
            . ($schoolPhone ? '<br><small>هاتف : ' . e($schoolPhone) . '</small>' : '') . '</div>';
        // أجنبي: الشعار فوق على اليسار، والاسم الفرنسي والعنوان (بالأجنبي) والهاتف تحته على اليسار
        // العنوان واسم المدير بالأجنبي: المُدخَل، وإلا ترجمة تلقائية من العربي (لا العربي نفسه)
        $schoolAddrFr = trim((string)($school['address_fr'] ?? '')) ?: ($schoolAddr !== '' ? arNameToFr($schoolAddr) : '');
        $directorFr   = ($sigNameFr !== '') ? $sigNameFr : ($director !== '' ? arNameToFr($director) : '');
        $schoolHeadFr = '<div style="border-bottom:2px solid #1e3a5f;padding-bottom:10px;margin-bottom:18px;text-align:left">'
            . ($logoImg ? '<div style="margin-bottom:2px">' . $logoImg . '</div>' : '')
            . '<strong style="font-size:17px">' . e($schoolNameFr) . '</strong>'
            . ($schoolAddrFr ? '<br><small>' . e($schoolAddrFr) . '</small>' : '')
            . ($schoolPhone ? '<br><small>Tel : ' . e($schoolPhone) . '</small>' : '') . '</div>';
    ?>
    <?php if ($lhOn): ?><style>@page{size:A4;margin:0}</style><?php endif; ?>
    <div id="ppExportArea" class="<?= $lhClass ?>" style="<?= $lhStyle ?>" dir="rtl">
      <div class="card-body" style="line-height:2.15;text-align:justify;font-size:12px;<?= $lhOn?'padding:0':'' ?>">

      <?php
        // ترويسة اتصال أسفل الصفحة (نمط مكسيموس) — تظهر مع الشعار
        // التذييل يظهر فقط مع ترويسة صورة (مكسيموس)؛ المخلصيات نموذجها بلا تذييل (ترويسة فقط)
        $footerHtml = '';
      ?>
      <?php if ($type === 'salaire'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <div style="text-align:left;margin-bottom:10px"><?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إلى من يهمه الأمر</h2>
        <p>يفيد مدير <strong><?= e($schoolNameAr) ?></strong> :</p>
        <p>أنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> هو(ـي) مدرّس(ة) في مدرستنا لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> ، باشر(ت) التدريس في <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong> ، ويتقاضى راتباً شهرياً قدره <strong><?= $moneyAr($salShown) ?></strong> ، فقط <strong><?= e($moneyWords($salShown)) ?> لا غير</strong> ، ولا يزال(تزال) مستمراً(ة) حتى تاريخه .</p>
        <p>وللبيان أُعطيت هذه الإفادة .</p>
        <div style="text-align:center;margin-top:42px"><strong>المدير</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?><?php if ($sigPhone): ?><br><small>هاتف : <?= e($sigPhone) ?></small><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'tadris'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <div style="text-align:left;margin-bottom:10px"><?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إلى من يهمه الأمر</h2>
        <p>يفيد مدير <strong><?= e($schoolNameAr) ?></strong> :</p>
        <p>أنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> هو(ـي) مدرّس(ة) في مدرستنا لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> ، باشر(ت) التدريس في <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong> ، ولا يزال(تزال) مستمراً(ة) حتى تاريخه .</p>
        <p>وللبيان أُعطيت هذه الإفادة .</p>
        <div style="text-align:center;margin-top:42px"><strong>المدير</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?><?php if ($sigPhone): ?><br><small>هاتف : <?= e($sigPhone) ?></small><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'riaaya'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <div style="text-align:left;margin-bottom:10px"><?= $today ?></div>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إلى من يهمه الأمر</h2>
        <p>تفيد إدارة <strong><?= e($schoolNameAr) ?></strong> <?= e($assocTxt) ?> ،</p>
        <p>أنّ السيّد(ة) <strong><?= e($nomAr) ?></strong> هو(ـي) معلّم(ة) لمادة <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsAr ?> في مدرستنا .</p>
        <p>وللبيان أُعطيت هذه الإفادة .</p>
        <div style="text-align:center;margin-top:42px"><strong>الإدارة</strong><?php if ($director): ?><br><?= e($director) ?><?php endif; ?><?php if ($sigPhone): ?><br><small>هاتف : <?= e($sigPhone) ?></small><?php endif; ?></div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'embassy'): ?>
        <?php if ($showRecHead): ?><?= $schoolHeadFr ?><?php endif; ?>
        <div dir="ltr" style="text-align:left">
          <div style="text-align:right;margin-bottom:10px"><?= date('d/m/Y') ?></div>
          <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">ATTESTATION</h2>
          <p>To whom it may concern,</p>
          <p>This is to certify that <strong><?= e(trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['father_name_fr'] ? $emp['father_name_fr'] . ' ' : '') . ($emp['last_name_fr'] ?? ''))) ?></strong> has been a teacher at <strong><?= e($schoolNameFr) ?></strong>. He/She has been, and continues to be, teaching <strong><?= $subj !== '' ? e($subj) : $blank(140) ?></strong> <?= $levelsEn ?>, at a rate of <strong><?= $embRate !== '' ? e($embRate) : $blank(90) ?> per month</strong>. We also confirm that he/she is currently engaged at our school for the academic year <strong><?= $nextSY ?></strong>.</p>
          <p style="text-align:center">This certificate is given upon his/her request.</p>
          <div style="text-align:center;margin-top:42px"><strong>Director</strong><?php if ($directorFr): ?><br><?= e($directorFr) ?><?php endif; ?><?php if ($sigPhone): ?><br><small>Tel : <?= e($sigPhone) ?></small><?php endif; ?></div>
        </div>
        <?= $footerHtml ?>

      <?php elseif ($type === 'anhaa_khedme'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">كتاب إنهاء خدمات</h2>
        <p>حضرة الأستاذ(ة) : <strong><?= e($nomAr) ?></strong></p>
        <p>استناداً إلى القوانين المرعية الإجراء وخاصةً قانون الهيئة التعليمية للمدارس الخاصة ، وعملاً بالمادة 29 وتعديلاتها من القانون المذكور ،</p>
        <p>نُعلمكم قبل الخامس من شهر تموز <strong><?= $effYear ?></strong> قرار اضطرار مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>إنهاء خدماتكم التعليمية لديها عن العام الدراسي <strong><?= $curSY ?></strong> وما يليه .</p>
        <p>وإذ تأسف إدارة المدرسة لإبلاغكم قرارها تشكر لكم تعاونكم المخلص معها داعيةً لكم بالتوفيق راجيةً قبول احترامها .</p>
        <div style="display:flex;justify-content:space-between;margin-top:32px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:36px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <p style="margin-top:12px">مع جميع التحفظات</p>
        <div style="margin-top:24px;border-top:1px dashed #999;padding-top:12px">
          <p>أنا الموقّع(ة) أدناه : <strong><?= e($nomAr) ?></strong></p>
          <p>لقد استلمت من رئيسة المدرسة كتاب إنهاء خدماتي .</p>
          <div style="display:flex;justify-content:space-between;margin-top:24px">
            <div>في : <?= $blank(90) ?></div>
            <div style="text-align:center"><strong>الاسم والتوقيع</strong><div style="margin-top:32px;border-top:1px solid #333;width:200px"></div></div>
          </div>
        </div>

      <?php elseif ($type === 'anhaa_mail'): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:10px">
          <div style="text-align:left;font-weight:700;font-size:13px;line-height:1.7">بطاقة مكشوفة<br>مع إشعار بالاستلام<br>مُرسَلة</div>
          <div style="text-align:right">
            <?php if ($showLogo && $logoImg): ?><?= $logoImg ?><br><?php endif; ?>
            <strong style="font-size:15px"><?= e($schoolNameAr) ?></strong>
            <?= $schoolAddr ? '<br><small>' . e($schoolAddr) . '</small>' : '' ?>
            <?= $schoolPhone ? '<br><small>هاتف : ' . e($schoolPhone) . '</small>' : '' ?>
          </div>
        </div>
        <div style="border:1px solid #bbb;padding:8px 12px;line-height:1.95;margin-bottom:14px">
          <p style="margin:2px 0">من : <strong><?= e($schoolNameAr) ?></strong></p>
          <p style="margin:2px 0">إلى الأستاذ(ة) : <strong><?= e($nomAr) ?></strong></p>
          <p style="margin:2px 0">العنوان : <?= $addr !== '' ? '<strong>' . e($addr) . '</strong>' : $blank(320) ?></p>
          <p style="margin:2px 0">الهاتف : <?= trim((string)($emp['phone1'] ?? '')) !== '' ? '<strong>' . e($emp['phone1']) . '</strong>' : $blank(150) ?></p>
        </div>
        <h2 style="text-align:center;margin:4px 0 18px;text-decoration:underline">كتاب إنهاء خدمات</h2>
        <p>استناداً إلى القوانين المرعية الإجراء وخاصةً قانون الهيئة التعليمية للمدارس الخاصة ، وعملاً بالمادة 29 وتعديلاتها من القانون المذكور ،</p>
        <p>نُعلمكم قبل الخامس من شهر تموز <strong><?= $effYear ?></strong> قرار اضطرار مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>إنهاء خدماتكم التعليمية لديها عن العام الدراسي <strong><?= $curSY ?></strong> وما يليه .</p>
        <p>وإذ تأسف إدارة المدرسة لإبلاغكم قرارها تشكر لكم تعاونكم المخلص معها داعيةً لكم بالتوفيق راجيةً قبول احترامها .</p>
        <div style="display:flex;justify-content:space-between;margin-top:32px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:36px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <p style="margin-top:12px;text-align:center">مع جميع التحفظات</p>

      <?php elseif ($type === 'talab_istiqala'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 24px;text-decoration:underline">طلب استقالة</h2>
        <p>حضرة مديرة مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>أنا الموقّع أدناه : <strong><?= e($nomAr) ?></strong></p>
        <p>أُبلغكم استقالتي من الخدمة في مدرسة <strong><?= e($schoolNameAr) ?></strong> اعتباراً من السنة المدرسية المقبلة <strong><?= $nextSY ?></strong> ، وذلك لأسبابٍ شخصية .</p>
        <p>وتفضّلوا بقبول الاحترام .</p>
        <div style="display:flex;justify-content:space-between;margin-top:46px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>التوقيع</strong><div style="margin-top:44px;border-top:1px solid #333;width:200px"></div></div>
        </div>

      <?php elseif ($type === 'afade_madrasiya'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إفـادة مدرسية</h2>
        <p>أنا الموقّعة أدناه : <strong><?= $director ? e($director) : $blank(180) ?></strong></p>
        <p>رئيسة أو مديرة مدرسة : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>أُثبت أنّ السيّد (ة) : <strong><?= e($nomAr) ?></strong> &nbsp; حامل بطاقة الهوية رقم <?= $blank(150) ?></p>
        <p>قد باشر التدريس في مدرستنا بتاريخ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(120) ?></strong></p>
        <p>وانقطع عن العمل بتاريخ <strong><?= $effFmt ?></strong></p>
        <p>للأسباب الآتية : <?= $blank(380) ?></p>
        <p>وكان راتبه الشهري ( دون التعويض العائلي ) في الشهر الأخير من الخدمة الفعلية <strong><?= $salFig ?></strong></p>
        <p>فقط <strong><?= e($salWrd) ?> لا غير .</strong></p>
        <p>وبياناً للواقع ، وبالاستناد إلى قيود سجلات المدرسة ، أُعطيت هذه الإفادة .</p>
        <div style="display:flex;justify-content:space-between;margin-top:38px">
          <div>تحريراً في <?= $effFmt ?></div>
          <div style="text-align:center"><strong>توقيع رئيس أو مدير المدرسة</strong><div style="margin-top:10px">خاتم المدرسة</div>
            <div style="margin-top:28px;border-top:1px solid #333;width:220px"></div></div>
        </div>
        <p style="margin-top:22px;font-size:13px;color:#444">ملاحظة : تُرسل هذه الإفادة إلى إدارة صندوق تعويضات أفراد الهيئة التعليمية في المدارس الخاصة .</p>
        <p style="text-align:center;font-size:13px;color:#444">وزارة التربية الوطنية والشباب والرياضة - بيروت</p>
        <p style="text-align:center;font-size:13px;color:#444">( راجع ظهر الصفحة )</p>

      <?php elseif ($type === 'isqat_haq'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إفـادة إسقاط حـق</h2>
        <p>أنا الموقّع أدناه <strong><?= e($nomAr) ?></strong></p>
        <p>حسب تذكرة هويتي ، رقم السجل <?= $blank(150) ?> &nbsp; أُصرّح بما يلي :</p>
        <p><strong>1)</strong> عملت لدى مدرسة : <strong><?= e($schoolNameAr) ?></strong> بصفة : <strong><?= e($fnFr['ar']) ?></strong> منذ <strong><?= $emp['hire_date'] ? $hireFmt : $blank(110) ?></strong> ، وأصبح راتبي بتاريخ هذا الإسقاط بالغاً ( بالأرقام ) <strong><?= $salFig ?></strong> ، بالحروف <strong><?= e($salWrd) ?> لا غير .</strong></p>
        <p><strong>2)</strong> قبضت من مدرسة : <strong><?= e($schoolNameAr) ?></strong> رواتبي كاملةً مع لواحقها طيلة مدة عملي لديها ، كما تناولت جميع الحقوق التي يخوّلني إياها القانون طيلة المدة المذكورة بما في ذلك بدل الساعات الإضافية والفرص السنوية الخ .....</p>
        <p><strong>3)</strong> بتاريخ <strong><?= $effFmt ?></strong> &nbsp; <?= $box($isqMode==='istiqala') ?> قدّمت استقالتي &nbsp;&nbsp; <?= $box($isqMode==='sarf') ?> صار صرفي من الخدمة .</p>
        <p>وبنتيجة ذلك وبعد المحاسبة قبضت من مدرسة <strong><?= e($schoolNameAr) ?></strong> مبلغ ( بالأرقام ) <strong><?= $eos>0 ? $freeNum($eos) : $blank(140) ?></strong> &nbsp; (بالحروف) <strong><?= $eos>0 ? 'فقط '.e($freeWords($eos)).' لا غير' : $blank(240) ?></strong></p>
        <p>وهذا المبلغ يشكّل تعويض صرفي من الخدمة .</p>
        <p>بناءً عليه ، أُقرّ وأعترف بأنّ مدرسة <strong><?= e($schoolNameAr) ?></strong> دفعت لي جميع المبالغ المترتبة لي بصفتي مستخدَماً لديها ولا سيّما تعويض الصرف من الخدمة وبدل مدة الإنذار ، وأنني أُسقط عن المدرسة المذكورة كل حقٍّ أو دعوى أو مطلب يعود لي بموجب قانون العمل وتعديلاته وسائر القوانين والأنظمة المعمول بها ، وأُبرئ ذمة المدرسة من هذا القبيل إبراءً تاماً شاملاً مجمل علاقة الاستخدام التي كانت قائمةً فيما بيننا .</p>
        <div style="display:flex;justify-content:space-between;margin-top:38px">
          <div>للبيان حُرّر في <?= $effFmt ?></div>
          <div style="text-align:center"><strong>بيد الموقّع صالح لأجل إسقاط الحق</strong><div style="margin-top:40px;border-top:1px solid #333;width:220px">التوقيع</div></div>
        </div>

      <?php elseif ($type === 'baraa_zimma'):
        $bzMonth = monthName($eMo, 'ar'); ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إقـرار وإبـراء ذمّـة وإسقـاط حـق</h2>
        <p>أنا الموقّع أدناه : <strong><?= e($nomAr) ?></strong></p>
        <p>أُصرّح وأنا بكامل الأهلية القانونية بما يلي :</p>
        <p>إنني قد عملت لدى مدرسة : <strong><?= e($schoolNameAr) ?></strong> بصفة <strong><?= e($fnFr['ar']) ?></strong> ، وأُقرّ وأعترف بأنني قد قبضت كامل مستحقاتي المالية من المدرسة المذكورة أعلاه ، بما في ذلك الرواتب الشهرية ولواحقها ، والتعويضات لا سيّما منها المساعدات والأجور الإضافية تعويضاً عن انهيار العملة اللبنانية ، والحقوق ، وجميع المبالغ التي كانت مترتبة لي عن فترة عملي لديها ، ولا يُعتبر ذلك أبداً كسلفة على حساب التعويض بل تعويضاً كاملاً لا رجوع عنه بهذا الخصوص ، وذلك حتى نهاية شهر <strong><?= e($bzMonth) ?></strong> من العام <strong><?= $effYear ?></strong> .</p>
        <p>وبناءً عليه ، فإنني أُبرئ ذمّة المدرسة المذكورة ، وإدارتها ، وجميع مالكيها ، ومديريها ، من أي حق أو مطلب أو دعوى كانت أو قد تكون لي تجاههم ، إبراءً تامًّا شاملاً لا رجوع فيه ، كما أتعهّد بتحمّل كامل المسؤولية القانونية والمالية والجزائية في حال صدرت عني أي مطالبة لاحقاً خلافاً لهذا الإقرار .</p>
        <p>كما أُسقط إسقاطاً نهائياً لا رجعة فيه ، وبصورة شاملة ، أي حق أو دعوى أو مطالبة قد تكون لي تجاه المدرسة المذكورة ، أو إدارتها ، أو أي من مالكيها أو مديريها ، بموجب أي قانون أو نظام معمول به ، وأُبرئ ذممهم من أي علاقة استخدام سابقة كانت قائمة بيننا ، إبراءً تامًّا غير قابل للنقض أو الرجوع .</p>
        <p>وقد حُرّر هذا الإقرار والإبراء طوعاً وبكامل إرادتي دون ضغط أو إكراه .</p>
        <div style="display:flex;justify-content:space-between;margin-top:40px">
          <div>حرّر في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>الاسم والتوقيع</strong><br><?= e($nomAr) ?><div style="margin-top:34px;border-top:1px solid #333;width:220px"></div></div>
        </div>

      <?php elseif ($type === 'iqrar'):
        $iqEndYear = $syStart + 2; ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:6px 0 22px;text-decoration:underline">إقــرار</h2>
        <p>أنا الموقّع أدناه ،</p>
        <p>الاسم الكامل : <strong><?= e($nomAr) ?></strong></p>
        <p>تعاقدت مع مدرسة : <strong><?= e($schoolNameAr) ?></strong> للعام الدراسي : <strong><?= $nextSY ?></strong> بصفة <strong><?= e($fnFr['ar']) ?></strong> .</p>
        <p>أقرّ بأنني قد أُبلغت من قبل إدارة المدرسة أنه ، وبسبب الظروف الاقتصادية الضاغطة ، سوف أتقاضى خلال السنة الدراسية <strong><?= $nextSY ?></strong> منحاً مالية بقيمة <?= $grant>0 ? '<strong>'.number_format($grant).'</strong>' : $blank(110) ?> دولار أميركي ( <?= $grant>0 ? '<strong>'.e(numToArabicWords($grant)).'</strong>' : $blank(150) ?> دولار أميركي ) ، أو يتم تحديد قيمتها وأوقات وطريقة دفعها من قبل إدارة المدرسة بصورة منفردة .</p>
        <p>وعليه ، أقرّ وأعترف بأنّ المنح المالية التي سوف أتقاضاها خلال السنة الدراسية <strong><?= $nextSY ?></strong> تُعتبر بصورة استثنائية منحاً خاصة ، وتُعدّ مساعدة مالية عرضية وظرفية تُدفع بشكل استثنائي وفقاً لتقدير إدارة المدرسة المنفرد تبعاً للظروف الاقتصادية والمعيشية الصعبة والمستجدّة .</p>
        <p>كما أقرّ وأعترف بأن هذه المنح المالية لا تُعدّ جزءاً من عناصر الراتب الشهري المدفوع والمصرّح عنه لكافة الجهات المختصة ، ولا يمكن التذرّع في هذا السياق بمبدأ الثبات والتكرار لأي سبب كان لاعتبارها عنصراً من عناصر الراتب ، وبالتالي لا تترتّب عنها أية حقوق مالية أو تعويضية أو تعاقدية تجاه المدرسة .</p>
        <p>وبناءً عليه ، يحق لإدارة المدرسة وبإرادتها المنفردة التوقف عن دفع هذه المنح أو تعديل قيمتها ، وفي أي وقت تراه مناسباً ، دون أن تترتّب عليها أية مسؤولية قانونية أو مالية نتيجة هذا القرار .</p>
        <p>كذلك ، أقرّ وأؤكّد بموجب هذا الإقرار التزامي بعدم ترك التعليم في :</p>
        <p>مدرسة <strong><?= e($schoolNameAr) ?></strong> خلال العام الدراسي <strong><?= $nextSY ?></strong> .</p>
        <p>وذلك اعتباراً من تاريخ هذا الإقرار ولغاية 30 حزيران <strong><?= $iqEndYear ?></strong> ، وأدرك أن إخلالي بهذا الالتزام قد يحمّلني عطلاً وضرراً مالياً منوّه عنهما في المادة 30 من قانون الهيئة التعليمية تاريخ 1956/6/15 ( لا يحق لأي فرد من أفراد الهيئة التعليمية أن يترك العمل خلال السنة الدراسية وإلّا ترتّب عليه عطل وضرر يوازي ضعف رواتبه وملحقاتها عن المدة الباقية من السنة الدراسية ) .</p>
        <p>لذلك ، وبكامل أهليتي القانونية ، أوقّع هذا الإقرار ، متعهّداً بالالتزام التام والكامل بمضمونه ، ومتحمّلاً بذلك أية مسؤولية مالية أو قانونية أو جزائية أو مدنية في حال مخالفتي لما ورد فيه .</p>
        <p>وللبيان ، حرّرت ووقّعت هذا الإقرار غير القابل للرجوع عنه ، وعلى كامل مسؤوليتي القانونية .</p>
        <div style="display:flex;justify-content:space-between;margin-top:40px">
          <div>حرّر في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>الاسم والتوقيع</strong><br><?= e($nomAr) ?><div style="margin-top:34px;border-top:1px solid #333;width:220px"></div></div>
        </div>

      <?php elseif ($type === 'aqd_taalim'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <h2 style="text-align:center;margin:4px 0 18px;text-decoration:underline">عقــد تعليــم</h2>
        <p>بين مدرسة : <strong><?= e($schoolNameAr) ?></strong> &nbsp; الممثَّلة بشخص: <?= $director ? '<strong>'.e($director).'</strong>' : $blank(150) ?> &nbsp; ( <strong>فريق أول</strong> )</p>
        <p>والسيّد / السيّدة / الآنسة : <strong><?= e($nomAr) ?></strong> &nbsp; من الجنسية: <?= $vb($natAr, 90) ?> &nbsp; ( <strong>فريق ثانٍ</strong> )</p>
        <p>المولود(ة) بتاريخ : <strong><?= $dob ?></strong> &nbsp; في : <strong><?= $bplace ?></strong> &nbsp; محل الإقامة: <?= $vb($emp['ville'] ?? '', 110) ?> &nbsp; رقم السجل: <?= $vb($regNo, 90) ?></p>
        <p>والمقيم على العنوان التالي : &nbsp; شتاءً : <?= $vb($addr, 170) ?> هاتف <?= $phone1 ?> &nbsp;&nbsp; صيفاً : <?= $blank(120) ?> هاتف <?= $vb($emp['phone2'] ?? '', 90) ?></p>
        <p>بتاريخ : <?= $blank(110) ?> تمّ الاتفاق بين الفريقين المحدَّدَين أعلاه على ما يلي :</p>

        <p style="margin-top:10px"><strong>المادة الأولى :</strong> صرّح الفريق الثاني :</p>
        <p><strong>1/1 ـ</strong> بأنه يحمل الشهادات الرسميّة التالية :</p>
        <p style="margin-right:26px">1 ـ <?= $vb($dipAr, 150) ?> الصادرة عن : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p style="margin-right:26px">2 ـ <?= $blank(150) ?> الصادرة عن : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p><strong>2/1 ـ</strong> وأنه تابع الدورات التدريبيّة والتأهيليّة التالية :</p>
        <p style="margin-right:26px">1 ـ <?= $blank(150) ?> في : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p style="margin-right:26px">2 ـ <?= $blank(150) ?> في : <?= $blank(150) ?> سنة <?= $blank(60) ?></p>
        <p><strong>3/1 ـ</strong> وأنه مارس التعليم في :</p>
        <p style="margin-right:26px">1 ـ مدرسة : <?= $blank(120) ?> من <?= $blank(70) ?> إلى <?= $blank(70) ?> في الصفوف <?= $blank(90) ?> مادة <?= $blank(90) ?></p>
        <p style="margin-right:26px">2 ـ مدرسة : <?= $blank(120) ?> من <?= $blank(70) ?> إلى <?= $blank(70) ?> في الصفوف <?= $blank(90) ?> مادة <?= $blank(90) ?></p>
        <p><strong>4/1 ـ</strong> وأنه يمارس التعليم حالياً في :</p>
        <p style="margin-right:26px">1 ـ مدرسة <strong><?= e($schoolNameAr) ?></strong> بصفة <?= $box($isContr) ?> متعاقد <?= $box($isTit) ?> داخل في الملاك &nbsp; عدد الساعات <?= $vb($hpw, 60) ?></p>
        <p style="margin-right:26px">2 ـ مدرسة <?= $blank(120) ?> بصفة <?= $chk ?> متعاقد <?= $chk ?> داخل في الملاك &nbsp; عدد الساعات <?= $blank(60) ?></p>
        <p><strong>5/1 ـ</strong> وأنه يتمتع بالشروط العامة المؤهِّلة لممارسة التعليم ( والمحددة بالمرسوم الاشتراعي 59/112 ، مدنية ، صحية ..... )</p>
        <p><strong>6/1 ـ</strong> وأنه على استعداد لمتابعة الدورات التأهيلية التي تعدّها المدرسة وتحدّد موضوعها ومدتها ومكانها وزمانها .</p>
        <p><strong>7/1 ـ</strong> وأنه : <?= $box(!$isMarried) ?> عازب <?= $box($isMarried) ?> متأهل <?= $box(false) ?> أرمل <?= $box(false) ?> مطلّق &nbsp; وأنّ عدد الأولاد الذين هم على عاتقه هو : <?= $vb((string)$nKids, 60) ?></p>
        <p><strong>8/1 ـ</strong> وأنّ الزوج(ة) <?= $box(!$spouseWorks) ?> لا يقوم <?= $box((bool)$spouseWorks) ?> يقوم بعمل مأجور ( اسم المؤسسة التي يعمل فيها ): <?= $blank(150) ?></p>
        <p><strong>9/1 ـ</strong> وأنه على اطّلاع وافٍ بالمناهج الرسميّة وأهدافها .</p>
        <p><strong>10/1 ـ</strong> وأنه على اطّلاع وافٍ بأنّ المدرسة هي مدرسة كاثوليكيّة ملتزمة بتوجيهات الكنيسة وتعاليمها الهادفة إلى بناء الطالب روحياً وإنسانياً وعلمياً ووطنياً ، وهي تسعى إلى ذلك بالتعاون مع الأهل والهيئة التعليميّة وفقاً للنظام الخاص الذي تضعه المدرسة ويحدّد العلاقة التربوية والتعليمية والإداريّة بين جميع المعنيين بالمدرسة .</p>
        <p><strong>11/1 ـ</strong> وأنه يتعهّد بإيداع الفريق الأول المستندات المثبتة لتصريحه في مهلةٍ أقصاها <?= $blank(120) ?></p>

        <p style="margin-top:10px"><strong>المادة الثانية :</strong> تعاقد الفريق الأول مع الفريق الثاني للتعليم خلال السنة الدراسية : <strong><?= $nextSY ?></strong></p>
        <p style="margin-right:26px">ـ بصفة : <?= $chk ?> حاضنة أطفال <?= $chk ?> مدرّس <?= $chk ?> معلّم <?= $chk ?> أستاذ تعليم ثانوي <?= $box(false) ?> متمرّن <?= $box($isContr) ?> متعاقد</p>
        <p style="margin-right:26px">ـ عدد ساعات التعليم الفعلي في الأسبوع : <?= $vb($hpw, 70) ?> &nbsp; المرحلة: <?= $vb($niveau, 110) ?> &nbsp; المادة: <?= $vb($subj, 110) ?></p>
        <p style="margin-right:26px">ـ عدد ساعات التناقص المخصصة للنشاطات اللاصفيّة : <?= $blank(70) ?></p>

        <p style="margin-top:10px"><strong>المادة الثالثة :</strong> يدفع الفريق الأول شهرياً للفريق الثاني لقاء المهمات التعليميّة الموكولة إليه ما يلي :</p>
        <p style="margin-right:26px"><strong>1/3 ـ</strong> أساس الراتب: <strong><?= $emp['employee_type']==='enseignant_titulaire' ? $baseFig : $blank(120) ?></strong> &nbsp; ( الأجر القانوني )</p>
        <p style="margin-right:26px"><strong>2/3 ـ</strong> بدل مالي ( قانون 99/148 ) : <?= $blank(120) ?></p>
        <p style="margin-right:26px"><strong>3/3 ـ</strong> ساعات إضافية : <?= $blank(120) ?></p>
        <p style="margin-right:26px"><strong>4/3 ـ</strong> بدل إضافي بمثابة مكافأة : <?= $blank(120) ?></p>
        <p style="margin-right:26px"><strong>5/3 ـ</strong> تعويض نقل مؤقت : <?= $blank(120) ?></p>
        <p style="margin-right:26px"><strong>6/3 ـ</strong> تعويض عائلي : <?= $blank(120) ?> ( في حال توجّبه )</p>
        <p style="margin-right:26px"><strong>المجموع :</strong> <?= $blank(140) ?> فقط : <?= $blank(220) ?></p>
        <p>تُحسم من مستحقات الفريق الثاني المبالغ الشهرية المترتبة قانوناً لصندوق التعويضات ، والصندوق الوطني للضمان الاجتماعي ولوزارة المال وبدل الطوابع ، ويدفعها الفريق الأول إلى المراجع المختصة على مسؤوليته .</p>

        <p style="margin-top:10px"><strong>شـروط خصوصيـة</strong></p>
        <p><strong>1 ـ</strong> يحتفظ الفريق الأول بتحديد ساعات التعليم من ضمن الدوام المدرسي في مهلةٍ أقصاها نهاية شهر تشرين الأول مع مراعاة الأحكام الخاصة المتعلقة بأفراد الهيئة التعليمية في المرحلة الثانوية ، ويحق للفريق الأول أن يعدّل في هذه المواعيد إذا تطلّب ذلك سير العمل في المدرسة دون أن يكون للفريق الثاني حق الاعتراض على الدوام الجديد ، شرط أن لا تترتب على الفريق الثاني ساعات عمل إضافية من جرّاء التعديل .</p>
        <p><strong>2 ـ</strong> على الفريق الثاني أن يتقيّد بالدوام المخصص له ولا يحق له أن يتغيّب بدون عذر شرعي في ضوء أحكام المواد 23 و 24 و 25 الجديدة من قانون 15 ـ 6 ـ 1956 ، وتحتفظ المدرسة بحقها في اتخاذ العقوبات المناسبة المنصوص عنها حصراً في المادة 26 من القانون نفسه وذلك في ضوء النتائج المترتبة عن مدة الغياب المشروع .</p>
        <p><strong>3 ـ</strong> يلتزم الفريق الثاني بجميع التوجيهات التربوية والتعليمية التي يقرّها الفريق الأول كما يلتزم بحضور الاجتماعات على أنواعها والمناقشات وبالاشتراك الفعلي في أعمال الامتحانات المدرسية ومراقبتها والتعاون الكلي في ضبطها وذلك في الأوقات التي تحددها الإدارة من ضمن الدوام أو خارجه ، وتطبّق على مخالفة هذا البند الأحكام المبيّنة في المادة 26 من قانون 15 ـ 6 ـ 1956 .</p>
        <p><strong>4 ـ</strong> يلتزم الفريق الثاني بأن يتقيّد بالمناهج الرسمية والخاصة بالمدرسة ويلتزم عدم الإساءة إلى المدرسة وطلابها وإلى زملائه خلال عمله وأثناء وجوده خارج المدرسة ، وتطبّق عليه في حال الإخلال بهذا الالتزام العقوبات المنصوص عليها في المادة 26 المشار إليها سابقاً .</p>
        <p><strong>5 ـ</strong> يأخذ الفريق الثاني علماً بالصلاحيات المنوطة بإدارة الدروس والمنسقين والنظار العامين وفقاً للنظام الداخلي في المدرسة . تستند إدارة المدرسة في تقييمها أعمال الفريق الثاني إلى التقارير التي تضعها إدارة الدروس والمنسقون والنظار العامون شرط أن يصار إلى إبلاغها إلى الفريق الثاني ، كما وتؤخذ التقارير الواردة من لجنة الأهل أو من ذوي الطلاب أو من الطلاب أنفسهم بعين الاعتبار عند تقييم ملف الفريق الثاني وعند تحديد المكافآت والتدابير موضوع البند 3/3 .</p>
        <p><strong>6 ـ</strong> يتم التبليغ بواسطة أمانة السر في المدرسة التي تتلقى أيّ مراجعة في هذا الشأن ، وفي حال رفض التبليغ يعتبر الفريق الثاني مبلّغاً أصولاً بموجب محضر تضعه أمانة السر ويُبنى على هذا المحضر المقتضى القانوني . إنّ هذه الأصول تشكّل جزءاً لا يتجزأ من هذا العقد يلتزم بها الفريقان لتأمين حسن سير العمل التربوي .</p>
        <p><strong>7 ـ</strong> يعتبر هذا العقد مفسوخاً « حكماً » على مسؤولية الفريق الثاني في حال تخلّفه عن القيام بموجباته وانقطاعه عن المدرسة مدة خمسة عشر يوماً دون عذر شرعي ، ويتوجب على الفريق الثاني في هذه الحالة التعويض على الفريق الأول وفقاً لأحكام القانون خاصة المادة 30 من قانون 15 ـ 6 ـ 1956 وتعديلاته .</p>
        <p><strong>8 ـ</strong> يحق للفريق الأول أن يفسخ عقد المتمرنين أو المتعاقدين الجدد قبل الخامس عشر من شهر شباط إذا ثبتت عدم كفاءة الفريق الثاني العلمية أو المسلكية أو في حفظ النظام وتُدفع رواتبه حتى تاريخ الصرف .</p>
        <p><strong>9 ـ</strong> لا يحق للفريق الثاني أن يترك العمل في المدرسة قبل نهاية السنة الدراسية بدون رضى الفريق الأول وإلا ترتّب عليه العطل والضرر المنوّه عنهما في المادة 30 من قانون 15 ـ 6 ـ 1956 .</p>
        <p><strong>10 ـ</strong> يحق للفريق الأول أن يفرض على الفريق الثاني ودون مقابل ساعات إضافية لإتمام المنهج الذي يكون قد وضعه هذا الأخير وأودعه الإدارة في بداية السنة .</p>
        <p><strong>11 ـ</strong> يلتزم الفريق الثاني احترام المواعيد المتعلقة بالتوزيع السنوي للدروس وتحضيرها وتقديم أسئلة الفروض الأسبوعية وأسئلة الامتحانات وتصحيحها وإعادتها دون إبطاء إلى المسؤولين في المواعيد التي تحدّدها الإدارة .</p>
        <p><strong>12 ـ</strong> يبقى الفريق الثاني مرتبطاً بإدارة المدرسة مدة شهر من أشهر العطلة الصيفية على أن يحدَّد هذا الشهر من قبل رئيس المدرسة قبل نهاية السنة الدراسيّة ، وعلى الفريق الثاني أن يلبّي كل دعوة توجَّه إليه خلال هذا الشهر في حدود واجباته المهنية باستثناء التدريس وضمن المهلة التي تحددها له الإدارة ( المادة 22 من قانون 15 ـ 6 ـ 1956 ) .</p>
        <p><strong>13 ـ</strong> يعتبر هذا العقد معلّقاً إذا تعذّر تنفيذه لأي سبب خارج عن إرادة الفريقين واستمر هذا التعذّر مدة ثلاثة أشهر من تاريخ وقف تنفيذه ، وفي هذه الحال يترتّب للفريق الثاني راتب الشهر الأول كاملاً ونصف راتب الشهرين التاليين . ويشمل التعذّر على سبيل المثال لا الحصر الاضطرابات والحروب وتدابير الدولة ، ولا يترتب لأيٍّ من الفريقين أيّ حقوق على الفريق الآخر من أي نوع كان اعتباراً من وقف تنفيذ هذا العقد .</p>
        <p><strong>14 ـ</strong> في جميع الشؤون التي لم يرد ذكرها في المواد السابقة تطبّق أحكام القوانين المرعية الإجراء ، لا سيّما قانون 15 ـ 6 ـ 1956 والقوانين المتعلّقة بتنظيم الموازنة المدرسية .</p>
        <p><strong>15 ـ</strong> يُعمل بهذا العقد من <?= $blank(90) ?> لغاية <?= $blank(90) ?> ، ويتجدد تلقائياً مع مراعاة أحكام المادتين 29 الجديدة و 30 من قانون 15 ـ 6 ـ 1956 .</p>
        <p style="text-align:center;margin-top:14px">نُظّم هذا العقد على نسختين أصليتين أودع كل فريق نسخةً للعمل بموجبها .</p>
        <p style="text-align:center">في : <?= $effFmt ?></p>
        <div style="display:flex;justify-content:space-between;margin-top:30px">
          <div style="text-align:center"><strong>الفريق الثاني</strong><div style="margin-top:40px;border-top:1px solid #333;width:200px"></div></div>
          <div style="text-align:center"><strong>الفريق الأول</strong><div style="margin-top:40px;border-top:1px solid #333;width:200px"></div></div>
        </div>

      <?php elseif ($type === 'notice_school'): ?>
        <?php if ($showRecHead): ?><?= $schoolHead ?><?php endif; ?>
        <p>حضرة الأستاذ(ة) : <strong><?= e($nomAr) ?></strong> المحترم(ة) .</p>
        <p>الموضوع : <strong><?= e($subjectTxt) ?></strong> .</p>
        <p>المرجــــع : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>تحيّة وبعد ،</p>
        <p>بعد تكراركم <?= e($subjectTxt) ?> ، وبحسب المادة 26 ( كما تعدّلت بموجب القانون رقم 44/87 تاريخ 21/11/1987 ) : إذا أهمل أحد أفراد الهيئة التعليمية والإدارية واجباته في حفظ النظام والتربية ، أو ارتكب مخالفةً لأحكام هذا القانون ، أو تغيّب دون عذر شرعي ، أو تصرّف تصرّفاً يضرّ بسمعة المدرسة أو بانتظام العمل فيها ، يُستهدَف للعقوبات التي ينصّ عليها القانون .</p>
        <p style="text-align:center;font-weight:700">لذلك</p>
        <p>تلفت إدارة المدرسة انتباهكم إلى عدم تكرار ذلك ، كي لا تضطرّ آسفةً إلى تطبيق القوانين المرعية الإجراء بحقّكم .</p>
        <p>وشكراً .</p>
        <div style="display:flex;justify-content:space-between;margin-top:34px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:38px;border-top:1px solid #333;width:200px"></div></div>
        </div>
        <div style="margin-top:30px;border-top:1px dashed #999;padding-top:10px">
          <p>تبلّغت الكتاب المضمون أعلاه ،</p>
          <p>الأستاذ(ة) : <?= $blank(220) ?></p>
          <p>الإمضاء : <?= $blank(220) ?></p>
        </div>

      <?php elseif ($type === 'notice_mail'): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:10px">
          <div style="text-align:left;font-weight:700;font-size:13px;line-height:1.7">بطاقة مكشوفة<br>مع إشعار بالاستلام<br>مُرسَلة</div>
          <div style="text-align:right">
            <?php if ($showLogo && $logoImg): ?><?= $logoImg ?><br><?php endif; ?>
            <strong style="font-size:15px"><?= e($schoolNameAr) ?></strong>
            <?= $schoolAddr ? '<br><small>' . e($schoolAddr) . '</small>' : '' ?>
            <?= $schoolPhone ? '<br><small>هاتف : ' . e($schoolPhone) . '</small>' : '' ?>
          </div>
        </div>
        <div style="border:1px solid #bbb;padding:8px 12px;line-height:1.95;margin-bottom:14px">
          <p style="margin:2px 0">من : <strong><?= e($schoolNameAr) ?></strong></p>
          <p style="margin:2px 0">إلى الأستاذ(ة) : <strong><?= e($nomAr) ?></strong></p>
          <p style="margin:2px 0">العنوان : <?= $addr !== '' ? '<strong>' . e($addr) . '</strong>' : $blank(320) ?></p>
          <p style="margin:2px 0">الهاتف : <?= trim((string)($emp['phone1'] ?? '')) !== '' ? '<strong>' . e($emp['phone1']) . '</strong>' : $blank(150) ?></p>
        </div>
        <p>حضرة الأستاذ(ة) : <strong><?= e($nomAr) ?></strong> المحترم(ة) .</p>
        <p>الموضوع : <strong><?= e($subjectTxt) ?></strong> .</p>
        <p>المرجــــع : <strong><?= e($schoolNameAr) ?></strong></p>
        <p>تحيّة وبعد ،</p>
        <p>بعد تكراركم <?= e($subjectTxt) ?> ، وبحسب المادة 26 ( كما تعدّلت بموجب القانون رقم 44/87 تاريخ 21/11/1987 ) : إذا أهمل أحد أفراد الهيئة التعليمية والإدارية واجباته في حفظ النظام والتربية ، أو ارتكب مخالفةً لأحكام هذا القانون ، أو تغيّب دون عذر شرعي ، أو تصرّف تصرّفاً يضرّ بسمعة المدرسة أو بانتظام العمل فيها ، يُستهدَف للعقوبات التي ينصّ عليها القانون .</p>
        <p style="text-align:center;font-weight:700">لذلك</p>
        <p>تلفت إدارة المدرسة انتباهكم إلى عدم تكرار ذلك ، كي لا تضطرّ آسفةً إلى تطبيق القوانين المرعية الإجراء بحقّكم .</p>
        <p>وشكراً .</p>
        <div style="display:flex;justify-content:space-between;margin-top:34px">
          <div>في : <?= $effFmt ?></div>
          <div style="text-align:center"><strong>رئيسة المدرسة</strong><div style="margin-top:38px;border-top:1px solid #333;width:200px"></div></div>
        </div>
      <?php endif; ?>

      </div>
    </div>
    <?php else: ?>
    <div id="ppExportArea" class="card" style="max-width:820px;margin:0 auto;padding:10px" <?= $rtl?'dir="rtl"':'' ?>>
        <div class="card-body" style="line-height:1.95;font-size:12px;<?= $rtl?'text-align:right':'' ?>">
            <!-- ترويسة باللغة المختارة -->
            <div style="border-bottom:2px solid var(--primary,#1e3a5f);padding-bottom:10px;margin-bottom:22px;<?= $rtl?'text-align:right':'text-align:left' ?>">
                <strong style="font-size:19px"><?= $rtl ? e($schoolNameAr) : e($schoolNameFr) ?></strong><br>
                <small><?= e($schoolAddr) ?><?= $schoolPhone ? ' — '.e($schoolPhone) : '' ?></small>
                <?php if ($employerNssf && ($type==='cnss')): ?><br><small><?= $rtl?'رقم رب العمل في الضمان: ':'N° employeur CNSS: ' ?><?= e($employerNssf) ?></small><?php endif; ?>
            </div>

            <h2 style="text-align:center;margin:6px 0 26px;text-decoration:underline"><?= e($ATT_TYPES[$type][$docLang]) ?></h2>

            <?php if ($docLang === 'ar'): ?>
                <p>نشهد نحن، <strong><?= e($schoolNameAr) ?></strong>، بأنّ:</p>
                <?php if (in_array($type,['salaire','travail','cnss'])): ?>
                <p><strong><?= e($nomAr) ?></strong>، يشغل وظيفة <strong><?= e($fnFr['ar']) ?></strong><?php if ($emp['hire_date']): ?>، منذ تاريخ <strong><?= $hireFmt ?></strong><?php endif; ?><?php if ($isTit && $emp['titularization_date']): ?>، تاريخ الترسيم <strong><?= $titFmt ?></strong><?php endif; ?>، من ضمن ملاك مدرستنا<?= $emp['status']==='actif'?' وهو على رأس عمله حالياً':'' ?>.</p>
                    <?php if ($classesAr || $subjects): ?><p><?php if ($classesAr): ?>الصفوف التي يُدرّسها: <strong><?= e($classesAr) ?></strong>. <?php endif; ?><?php if ($subjects): ?>المواد: <strong><?= e($subjects) ?></strong>.<?php endif; ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($type==='salaire'): ?><p>ويبلغ راتبه الشهري (الأساس + الدرجات) <strong><?= $L ?></strong>، وصافي راتبه <strong><?= $N ?></strong><?= $U ?><?php if ($sal): ?> عن <?= e($salPeriodAr) ?><?php endif; ?>.</p>
                <?php elseif ($type==='cnss'): ?><p>وهو مسجَّل في الصندوق الوطني للضمان الاجتماعي تحت الرقم <strong><?= e($nssf) ?></strong>، على راتب شهري خاضع قدره <strong><?= $L ?></strong> (حصة الأجير 3%: <?= formatLBP($cnssAmt) ?>؛ حصة المدرسة 8%: <?= formatLBP($schoolCnss) ?>).</p>
                <?php elseif ($type==='resignation'): ?><p>وقد تقدّم <strong><?= e($nomAr) ?></strong> باستقالته من عمله في مدرستنا<?php if ($emp['hire_date']): ?> (المباشرة منذ <strong><?= $hireFmt ?></strong>)<?php endif; ?>، وقد قُبلت اعتباراً من <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='fin_de_service'): ?><p>وقد انتهت خدمة <strong><?= e($nomAr) ?></strong> في مدرستنا<?php if ($emp['hire_date']): ?>، بعد خدمة من <strong><?= $hireFmt ?></strong> حتى <strong><?= $effFmt ?></strong><?= $yAr?' ('.e($yAr).')':'' ?><?php endif; ?>، اعتباراً من <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='decharge'): ?><p>يُقرّ <strong><?= e($nomAr) ?></strong> بأنه قبض من مدرستنا كامل حقوقه ورواتبه ومستحقاته (بما فيها التعويضات وتعويض نهاية الخدمة) لغاية تاريخ <strong><?= $effFmt ?></strong>، وأنه لا يطالب المدرسة بأي حقّ أو مستحق بعد هذا التاريخ (براءة ذمة تامة).</p>
                <?php endif; ?>
                <p>أُعطيت هذه الإفادة بناءً على طلبه لاستعمالها عند الحاجة.</p>

            <?php elseif ($docLang === 'fr'): ?>
                <p>Nous soussignés, <strong><?= e($schoolNameFr) ?></strong>, attestons que :</p>
                <?php if (in_array($type,['salaire','travail','cnss'])): ?>
                <p><strong><?= e($nomFr) ?></strong>, exerçant la fonction de <strong><?= e($fnFr['fr']) ?></strong><?php if ($emp['hire_date']): ?>, engagé(e) depuis le <strong><?= $hireFmt ?></strong><?php endif; ?><?php if ($isTit && $emp['titularization_date']): ?>, titularisé(e) le <strong><?= $titFmt ?></strong><?php endif; ?>, fait partie de notre personnel<?= $emp['status']==='actif'?' et est actuellement en activité':'' ?>.</p>
                    <?php if ($classesLat || $subjects): ?><p><?php if ($classesLat): ?>Classes enseignées : <strong><?= e($classesLat) ?></strong>. <?php endif; ?><?php if ($subjects): ?>Matières : <strong><?= e($subjects) ?></strong>.<?php endif; ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($type==='salaire'): ?><p>Son salaire mensuel (base + échelon) s'élève à <strong><?= $L ?></strong>, salaire net <strong><?= $N ?></strong><?= $U ?><?php if ($sal): ?> au titre de <?= e($salPeriodLat) ?><?php endif; ?>.</p>
                <?php elseif ($type==='cnss'): ?><p>Immatriculé(e) à la CNSS sous le n° <strong><?= e($nssf) ?></strong>, sur un salaire mensuel soumis de <strong><?= $L ?></strong> (part employé 3% : <?= formatLBP($cnssAmt) ?> ; part employeur 8% : <?= formatLBP($schoolCnss) ?>).</p>
                <?php elseif ($type==='resignation'): ?><p><strong><?= e($nomFr) ?></strong> a présenté sa démission, acceptée à compter du <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='fin_de_service'): ?><p><strong><?= e($nomFr) ?></strong> a cessé ses fonctions à compter du <strong><?= $effFmt ?></strong><?php if ($emp['hire_date']): ?>, après une période de service du <?= $hireFmt ?> au <?= $effFmt ?><?= $yFr?' ('.e($yFr).')':'' ?><?php endif; ?>.</p>
                <?php elseif ($type==='decharge'): ?><p><strong><?= e($nomFr) ?></strong> reconnaît avoir perçu de notre établissement l'intégralité de ses droits et salaires (y compris indemnités et fin de service) à la date du <strong><?= $effFmt ?></strong>, et déclare n'avoir aucune réclamation envers l'établissement (reçu pour solde de tout compte).</p>
                <?php endif; ?>
                <p>La présente attestation est délivrée pour servir et valoir ce que de droit.</p>

            <?php else: /* en */ ?>
                <p>We, the undersigned, <strong><?= e($schoolNameFr) ?></strong>, hereby certify that:</p>
                <?php if (in_array($type,['salaire','travail','cnss'])): ?>
                <p><strong><?= e($nomFr) ?></strong>, holding the position of <strong><?= e($fnFr['en']) ?></strong><?php if ($emp['hire_date']): ?>, employed since <strong><?= $hireFmt ?></strong><?php endif; ?><?php if ($isTit && $emp['titularization_date']): ?>, tenured on <strong><?= $titFmt ?></strong><?php endif; ?>, is a member of our staff<?= $emp['status']==='actif'?' and is currently in service':'' ?>.</p>
                    <?php if ($classesLat || $subjects): ?><p><?php if ($classesLat): ?>Classes taught: <strong><?= e($classesLat) ?></strong>. <?php endif; ?><?php if ($subjects): ?>Subjects: <strong><?= e($subjects) ?></strong>.<?php endif; ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($type==='salaire'): ?><p>His/her monthly salary (base + increment) is <strong><?= $L ?></strong>, net salary <strong><?= $N ?></strong><?= $U ?><?php if ($sal): ?> for <?= e($salPeriodLat) ?><?php endif; ?>.</p>
                <?php elseif ($type==='cnss'): ?><p>Registered with the National Social Security Fund under no. <strong><?= e($nssf) ?></strong>, on a monthly contributory salary of <strong><?= $L ?></strong> (employee share 3%: <?= formatLBP($cnssAmt) ?>; employer share 8%: <?= formatLBP($schoolCnss) ?>).</p>
                <?php elseif ($type==='resignation'): ?><p><strong><?= e($nomFr) ?></strong> has submitted his/her resignation, accepted effective <strong><?= $effFmt ?></strong>.</p>
                <?php elseif ($type==='fin_de_service'): ?><p><strong><?= e($nomFr) ?></strong> ended his/her duties effective <strong><?= $effFmt ?></strong><?php if ($emp['hire_date']): ?>, after a service period from <?= $hireFmt ?> to <?= $effFmt ?><?= $yEn?' ('.e($yEn).')':'' ?><?php endif; ?>.</p>
                <?php elseif ($type==='decharge'): ?><p><strong><?= e($nomFr) ?></strong> acknowledges having received from our establishment all of his/her dues and salaries (including allowances and end-of-service) as of <strong><?= $effFmt ?></strong>, and declares having no claim whatsoever against the establishment (final discharge / receipt in full).</p>
                <?php endif; ?>
                <p>This certificate is issued to whom it may concern.</p>
            <?php endif; ?>

            <!-- التاريخ والتوقيع -->
            <div style="display:flex;justify-content:space-between;margin-top:46px">
                <div>
                    <?php if ($docLang==='ar'): ?>حُرّر في <?= $today ?>
                    <?php elseif ($docLang==='fr'): ?><?= e($schoolAddr ? explode(',', $schoolAddr)[0] : '') ?>, le <?= $today ?>
                    <?php else: ?>Issued on <?= $today ?><?php endif; ?>
                </div>
                <div style="text-align:center">
                    <?php if ($type==='decharge'): ?>
                        <strong><?= $docLang==='ar'?'الموظف':($docLang==='fr'?"L'employé(e)":'The employee') ?></strong>
                        <div style="margin-top:46px;border-top:1px solid #333;width:200px"><?= $docLang==='ar'?'التوقيع':'Signature' ?></div>
                    <?php else: ?>
                        <strong><?= $docLang==='ar'?'الإدارة':($docLang==='fr'?'La Direction':'The Administration') ?></strong><br>
                        <?php if ($director): ?><span><?= e($director) ?></span><br><?php endif; ?>
                        <div style="margin-top:46px;border-top:1px solid #333;width:210px"><?= $docLang==='ar'?'التوقيع والخاتم':($docLang==='fr'?'Signature & cachet':'Signature & stamp') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; /* type cnss vs generic */ ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
