<?php
/**
 * Helper Functions
 */

require_once __DIR__ . '/../config/database.php';

// =====================================================
// Authentication
// =====================================================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role' => $_SESSION['role']
    ];
}

function isAdmin() {
    return isLoggedIn() && in_array($_SESSION['role'] ?? '', ['admin', 'superadmin']);
}

// =====================================================
// Multi-School (تعدد المدارس)
// =====================================================

// مدير عام: يرى كل المدارس ويبدّل بينها
function isSuperAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'superadmin';
}

/**
 * المدرسة الفعّالة الحالية.
 * - مستخدم عادي: مدرسته الثابتة دائماً.
 * - مدير عام: المدرسة المختارة من الأعلى (أو 0 = كل المدارس).
 * يرجع int (0 يعني "كل المدارس" — متاح للمدير العام فقط).
 */
/**
 * المدارس الفعّالة المختارة من الأعلى (تطبّق على كل البرنامج: اللوائح والتقارير).
 * مصفوفة ids — فارغة = كل المدارس. المستخدم العادي: مدرسته فقط.
 * يدعم اختيار عدة مدارس معاً (واحدة/تنتين/تلاتة/الكل).
 */
function activeSchoolIds() {
    if (!isSuperAdmin()) {
        $sid = (int)($_SESSION['school_id'] ?? 0);
        return $sid > 0 ? [$sid] : [];
    }
    // توافق خلفي: إذا في active_school_id قديم مفرد ولا يوجد active_schools
    if (!isset($_SESSION['active_schools']) && !empty($_SESSION['active_school_id'])) {
        $_SESSION['active_schools'] = [(int)$_SESSION['active_school_id']];
    }
    $ids = $_SESSION['active_schools'] ?? [];
    if (!is_array($ids)) $ids = [];
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
}

// المدرسة الحالية المفردة (لعمليات الإدخال/النماذج): الرقم إذا مختارة وحدة فقط، وإلا 0
function currentSchoolId() {
    $ids = activeSchoolIds();
    return count($ids) === 1 ? $ids[0] : 0;
}

// هل نحن في وضع "كل المدارس" أو عدة مدارس (عرض مجمّع)؟
function isAllSchools() {
    return count(activeSchoolIds()) !== 1;
}

/**
 * جملة SQL لتقييد الاستعلام بالمدارس المختارة (واحدة أو عدة). آمنة (أرقام).
 * مثال: "SELECT * FROM employees WHERE is_deleted=0" . schoolScopeSql()
 */
function schoolScopeSql($column = 'school_id') {
    $ids = activeSchoolIds();
    if (empty($ids)) return ' ';
    $in = implode(',', array_map('intval', $ids));
    return " AND {$column} IN ({$in}) ";
}

// نفس الفكرة لكن كأول شرط (بدون AND بادئة)
function schoolScopeWhere($column = 'school_id') {
    $ids = activeSchoolIds();
    if (empty($ids)) return ' 1 ';
    $in = implode(',', array_map('intval', $ids));
    return " {$column} IN ({$in}) ";
}

/**
 * يتطلّب اختيار مدرسة محددة (لصفحات إدخال البيانات).
 * المدير العام في وضع "كل المدارس" لا يمكنه إضافة/تعديل — يُعاد توجيهه لاختيار مدرسة.
 */
function requireSchoolSelected() {
    if (currentSchoolId() === 0) {
        $_SESSION['flash_error'] = 'يرجى اختيار مدرسة محددة من الأعلى أولاً / Veuillez sélectionner une école';
        header('Location: ' . BASE_URL . 'pages/schools.php');
        exit;
    }
}

// بيانات المدرسة الحالية (صف من جدول schools) أو null في وضع "الكل"
function currentSchool() {
    static $cache = [];
    $sid = currentSchoolId();
    if ($sid === 0) return null;
    if (!array_key_exists($sid, $cache)) {
        $stmt = getDB()->prepare("SELECT * FROM schools WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$sid]);
        $cache[$sid] = $stmt->fetch() ?: null;
    }
    return $cache[$sid];
}

// اسم المدرسة الحالية حسب اللغة (مع بديل في وضع "الكل")
function currentSchoolName($lang = null) {
    $lang = $lang ?: ($_SESSION['lang'] ?? 'fr');
    $school = currentSchool();
    if (!$school) {
        return $lang === 'ar' ? 'كل المدارس' : 'Toutes les écoles';
    }
    return $lang === 'ar' ? $school['name_ar'] : $school['name_fr'];
}

// قائمة كل المدارس الفعّالة
function allSchools($activeOnly = true) {
    $sql = "SELECT * FROM schools WHERE is_deleted = 0";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $sql .= " ORDER BY name_fr";
    return getDB()->query($sql)->fetchAll();
}

/**
 * المدارس المختارة في التقرير.
 * - مستخدم عادي: مدرسته فقط.
 * - مدير عام: يقرأ $_GET['schools'][] (مصفوفة ids). فارغة = كل المدارس.
 * يرجع مصفوفة ids (فارغة تعني "كل المدارس").
 */
function selectedReportSchoolIds() {
    if (!isSuperAdmin()) {
        $sid = (int)($_SESSION['school_id'] ?? 0);
        return $sid > 0 ? [$sid] : [];
    }
    // اختيار عدة مدارس معاً (٣ أو ٥ أو ٨ أو الكل) — يُثبَّت بالجلسة ليبقى محفوظاً
    if (isset($_GET['schools'])) {
        $sel = array_map('intval', (array)$_GET['schools']);
        $sel = array_values(array_unique(array_filter($sel, fn($x) => $x > 0)));
        $_SESSION['report_schools'] = $sel;       // فارغة = الكل
        return $sel;
    }
    if (isset($_SESSION['report_schools'])) {
        return $_SESSION['report_schools'];
    }
    // الافتراضي: يتبع اختيار المدارس العام من الأعلى (واحدة/عدة/الكل)
    return activeSchoolIds();
}

/**
 * فلترة «مَن كان موظّفاً خلال السنة الدراسية» — يرجع [sql, params].
 * السنة الدراسية تبدأ 1 تشرين الأول وتنتهي 30 أيلول. «all» أو فارغ = بلا فلترة.
 * $prefix: بادئة الأعمدة (مثلاً 'e.').
 */
function yearEmploymentFilter($schoolYear, $prefix = '') {
    if ($schoolYear === 'all' || !preg_match('/^(\d{4})-(\d{4})$/', (string)$schoolYear, $m)) {
        return ['', []];
    }
    // «موجود فعلاً بهذه السنة» = عنده راتب **فعلي (غير صفري)** بتلك السنة الدراسية،
    // **وغير تارك**. الاعتماد على **تاريخ الترك**: بمجرّد إدخال أيّ تاريخ ترك (ضمان/مالية/
    // صندوق) لا يعود الأستاذ يظهر في أي سنة محدّدة. ولرؤية السابقين يُستعمل «كل السنين»
    // (تُعيد الدالة بلا فلترة فيظهر الجميع). شرط القيمة>0 يستبعد الصفوف الصفرية (الأشباح).
    $sql = " AND {$prefix}id IN (SELECT employee_id FROM monthly_salaries"
         . " WHERE school_year = ? AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0))"
         . " AND {$prefix}left_date_cnss IS NULL AND {$prefix}left_date_finance IS NULL AND {$prefix}left_date_eoc IS NULL";
    return [$sql, [$schoolYear]];
}

// جملة SQL لتقييد التقرير بالمدارس المختارة (آمنة لأنها أرقام)
function reportSchoolSql($column = 'ms.school_id') {
    $ids = selectedReportSchoolIds();
    if (empty($ids)) return ' '; // كل المدارس
    $in = implode(',', array_map('intval', $ids));
    return " AND {$column} IN ({$in}) ";
}

// هل التقرير يشمل أكثر من مدرسة؟ (لإظهار عمود المدرسة)
function reportIsMultiSchool() {
    $ids = selectedReportSchoolIds();
    if (empty($ids)) {
        // "الكل": متعدد إذا في أكثر من مدرسة فعلاً
        return count(allSchools(false)) > 1;
    }
    return count($ids) > 1;
}

// اسم مدرسة معيّنة بالـ id
function schoolNameById($id, $lang = null) {
    $lang = $lang ?: ($_SESSION['lang'] ?? 'fr');
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (getDB()->query("SELECT id, name_ar, name_fr FROM schools")->fetchAll() as $s) {
            $map[$s['id']] = $s;
        }
    }
    if (!isset($map[$id])) return '';
    return $lang === 'ar' ? $map[$id]['name_ar'] : $map[$id]['name_fr'];
}

/**
 * رابط شعار المدرسة للترويسة:
 * 1) إن كان `schools.logo_path` مضبوطاً والملف موجود → يُستعمل (شعار خاص، مثل مكسيموس).
 * 2) وإلا الشعار الموحّد `assets/logos/unified.(png|jpg|jpeg|svg)` إن وُجد.
 * 3) وإلا فراغ (تظهر الترويسة بالاسم فقط).
 */
function schoolLogoUrl($school) {
    $base = dirname(__DIR__);
    $lp = is_array($school) ? trim((string)($school['logo_path'] ?? '')) : '';
    if ($lp !== '' && is_file($base . '/' . ltrim($lp, '/'))) return BASE_URL . ltrim($lp, '/');
    foreach (['png', 'jpg', 'jpeg', 'svg'] as $e) {
        if (is_file($base . "/assets/logos/unified.$e")) return BASE_URL . "assets/logos/unified.$e";
    }
    return '';
}

/**
 * ترويسة المدرسة الرسمية الكاملة كصورة A4 (شعار + اسم + تذييل) إن وُجدت:
 * `assets/letterheads/{schoolId}_{ar|fr}.(jpg|jpeg|png)`. تُستعمل كخلفية للإفادة فتطلع طبق الأصل.
 */
function schoolLetterheadUrl($school, $lang = 'ar') {
    $base = dirname(__DIR__);
    $id = is_array($school) ? (int)($school['id'] ?? 0) : 0;
    if (!$id) return '';
    $lang = ($lang === 'fr' || $lang === 'en') ? 'fr' : 'ar';
    foreach (['jpg', 'jpeg', 'png'] as $e) {
        if (is_file($base . "/assets/letterheads/{$id}_{$lang}.$e")) return BASE_URL . "assets/letterheads/{$id}_{$lang}.$e";
    }
    return '';
}

// =====================================================
// Security
// =====================================================
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * توكن رابط فورم تحديث معلومات الأستاذ (يمنع تخمين الروابط). ثابت لكل أستاذ.
 */
function infoFormToken($empId) {
    $secret = 'StM_infoform_' . (defined('DB_NAME') ? DB_NAME : 'x') . '_v1';
    return substr(hash_hmac('sha256', (string)((int)$empId) . '|info', $secret), 0, 24);
}

/**
 * توكن رابط المدرسة (رابط واحد للمجموعة): الأستاذ يفتحه ويختار اسمه.
 */
function schoolFormToken($schoolId) {
    $secret = 'StM_infoform_' . (defined('DB_NAME') ? DB_NAME : 'x') . '_v1';
    return substr(hash_hmac('sha256', (string)((int)$schoolId) . '|school', $secret), 0, 24);
}

/**
 * توكن الرابط الموحّد لكل المدارس: الأستاذ يختار مدرسته ثم اسمه.
 */
function allFormToken() {
    $secret = 'StM_infoform_' . (defined('DB_NAME') ? DB_NAME : 'x') . '_v1';
    return substr(hash_hmac('sha256', 'ALL|school', $secret), 0, 24);
}

/**
 * المسؤولون الموقّعون للمدرسة (اسم + اسم أجنبي + صفة + هاتف) — يختار المستخدم منهم عند الطباعة/الإرسال.
 * مخزّنون كـ JSON في جدول الإعدادات بمفتاح `school_signatories_<id>` (بلا تعديل بنية قاعدة البيانات).
 * يُرجِع دائماً عنصراً واحداً على الأقل: إن لم تُعرّف لائحة، يقع على مدير المدرسة (director_name) وهاتفها.
 */
function schoolSignatories($school) {
    $sid = (int)($school['id'] ?? 0);
    $tr = function ($ar) { return ($ar !== '' && function_exists('arNameToFr')) ? arNameToFr($ar) : $ar; };
    $out = [];
    if ($sid) {
        $raw = getSetting('school_signatories_' . $sid, '');
        if ($raw !== '') {
            $arr = json_decode($raw, true);
            if (is_array($arr)) {
                foreach ($arr as $s) {
                    $nm = trim((string)($s['name'] ?? ''));
                    if ($nm === '') continue;
                    $nmFr = trim((string)($s['name_fr'] ?? ''));
                    $out[] = [
                        'name'     => $nm,
                        'name_fr'  => $nmFr !== '' ? $nmFr : $tr($nm),
                        'title'    => trim((string)($s['title'] ?? '')),
                        'title_fr' => trim((string)($s['title_fr'] ?? '')),
                        'phone'    => trim((string)($s['phone'] ?? '')),
                    ];
                }
            }
        }
    }
    if (!$out) { // الافتراضي: مدير المدرسة
        $dn = trim((string)($school['director_name'] ?? ''));
        $dnFr = trim((string)($school['director_name_fr'] ?? ''));
        $out[] = [
            'name'     => $dn,
            'name_fr'  => $dnFr !== '' ? $dnFr : $tr($dn),
            'title'    => 'المدير',
            'title_fr' => 'Directeur',
            'phone'    => trim((string)($school['phone'] ?? '')),
        ];
    }
    return $out;
}

/**
 * رقم هاتف لبناني → صيغة wa.me الدولية (961...) لإرسال واتساب.
 */
function waPhone($phone) {
    $d = preg_replace('/\D+/', '', (string)$phone);
    if ($d === '') return '';
    if (strpos($d, '961') === 0) return $d;
    $d = ltrim($d, '0');
    return '961' . $d;
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** حقل مخفي للتوكن — يوضع داخل النماذج. */
function csrfField() {
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}

/**
 * يتحقّق من توكن CSRF عند أي طلب POST؛ يوقف التنفيذ برسالة إن كان غير صحيح.
 * يُستدعى في بداية كل صفحة فيها معالج POST (بعد requireLogin).
 */
function requireCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#b91c1c">'
          . '⚠️ Jeton de sécurité invalide / رمز الأمان غير صحيح — أعد تحميل الصفحة وحاول مجدداً.<br><br>'
          . '<a href="javascript:history.back()">Retour / رجوع</a></div>');
    }
}

// =====================================================
// Barre d'export / شريط الطباعة والتصدير
// =====================================================
/**
 * يطبع شريط أزرار: طباعة / PDF / Excel / Word / WhatsApp / Email.
 * $title: اسم الملف/العنوان. $opts: ['phone'=>..., 'email'=>..., 'wa'=>true, 'email_btn'=>true]
 * يعمل عبر assets/js/export.js على المنطقة #ppExportArea أو #pageContent.
 */
function exportToolbar($title = 'document', $opts = []) {
    $t  = htmlspecialchars($title, ENT_QUOTES);
    $ph = isset($opts['phone']) ? htmlspecialchars(preg_replace('/[^0-9]/', '', (string)$opts['phone']), ENT_QUOTES) : '';
    $em = isset($opts['email']) ? htmlspecialchars($opts['email'], ENT_QUOTES) : '';
    $showWa    = $opts['wa']        ?? true;
    $showEmail = $opts['email_btn'] ?? true;
    // النماذج الحكومية الثابتة: نخفي Excel/Word العامّين (يطلعان متل بلوك/صورة) — محلّهما
    // التعبئة من القالب الرسمي (أزرار خاصة بالصفحة) + PDF رسمي. $opts['no_office']=true.
    $noOffice = $opts['no_office'] ?? false;
    // وضع التوليد على الخادم: ملفات Excel/Word حقيقية منسّقة (أنظف من تصدير المتصفّح).
    // $opts['server'] = رابط أساس (مثل reports_export.php?report=...) فتُضاف &format=xlsx|docx.
    $server = $opts['server'] ?? '';
    $sv = $server ? htmlspecialchars($server, ENT_QUOTES) : '';
    $sep = (strpos($server, '?') !== false) ? '&' : '?';
    // «PDF رسمي» موحّد: طباعة الصفحة الحالية طبق الأصل عبر Chrome (يحافظ على شكل النماذج الرسمية).
    $reqUri = $_SERVER['REQUEST_URI'] ?? '';
    $relTarget = ltrim(preg_replace('#^' . preg_quote(rtrim(BASE_URL, '/'), '#') . '#', '', $reqUri), '/');
    $pdfName = preg_replace('/[^A-Za-z0-9_]+/', '_', $title) ?: 'document';
    $officialPdf = ($relTarget && strpos($relTarget, 'print_pdf.php') === false)
        ? BASE_URL . 'pages/print_pdf.php?target=' . rawurlencode($relTarget) . '&name=' . rawurlencode($pdfName)
        : '';
    ob_start(); ?>
    <div class="export-toolbar no-print no-export" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
        <button type="button" class="btn btn-sm btn-primary" onclick="ppPrint()"><i class="fas fa-print"></i> Imprimer / طباعة</button>
        <?php if ($officialPdf): ?>
        <a class="btn btn-sm btn-danger" href="<?= htmlspecialchars($officialPdf, ENT_QUOTES) ?>" title="PDF رسمي طبق الأصل جاهز للدولة"><i class="fas fa-file-pdf"></i> PDF رسمي</a>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-danger" onclick="ppPdf()" title="اختر: حفظ كـ PDF"><i class="fas fa-file-pdf"></i> PDF</button>
        <?php endif; ?>
        <?php if ($server): ?>
        <a class="btn btn-sm btn-success" href="<?= $sv . $sep ?>format=xlsx"><i class="fas fa-file-excel"></i> Excel</a>
        <a class="btn btn-sm btn-info" href="<?= $sv . $sep ?>format=docx"><i class="fas fa-file-word"></i> Word</a>
        <?php elseif (!$noOffice): ?>
        <button type="button" class="btn btn-sm btn-success" onclick="ppExcel('<?= $t ?>')"><i class="fas fa-file-excel"></i> Excel</button>
        <button type="button" class="btn btn-sm btn-info" onclick="ppWord('<?= $t ?>')"><i class="fas fa-file-word"></i> Word</button>
        <?php endif; ?>
        <?php if ($showWa): ?>
        <button type="button" class="btn btn-sm" style="background:#25D366;color:#fff" onclick="ppWhatsApp('<?= $t ?>','<?= $ph ?>')"><i class="fab fa-whatsapp"></i> WhatsApp</button>
        <?php endif; ?>
        <?php if ($showEmail): ?>
        <button type="button" class="btn btn-sm btn-light" onclick="ppEmail('<?= $t ?>','<?= $em ?>')"><i class="fas fa-envelope"></i> Email</button>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

// =====================================================
// Formatting
// =====================================================
function formatLBP($amount, $withCurrency = true) {
    $formatted = number_format((float)$amount, 0, '.', ',');
    return $withCurrency ? $formatted . ' L.L' : $formatted;
}

// تحويل رقم صحيح إلى كلمات عربية (للإفادات الرسمية: «فقط ... ليرة لبنانية لا غير»)
function numToArabicWords($num) {
    $num = (int)round((float)$num);
    if ($num === 0) return 'صفر';
    $ones = ['','واحد','اثنان','ثلاثة','أربعة','خمسة','ستة','سبعة','ثمانية','تسعة','عشرة','أحد عشر','اثنا عشر','ثلاثة عشر','أربعة عشر','خمسة عشر','ستة عشر','سبعة عشر','ثمانية عشر','تسعة عشر'];
    $tens = ['','','عشرون','ثلاثون','أربعون','خمسون','ستون','سبعون','ثمانون','تسعون'];
    $hund = ['','مئة','مئتان','ثلاثمئة','أربعمئة','خمسمئة','ستمئة','سبعمئة','ثمانمئة','تسعمئة'];
    $b3 = function($n) use ($ones,$tens,$hund) {
        $r = []; $h = intdiv($n,100); $rem = $n%100;
        if ($h) $r[] = $hund[$h];
        if ($rem) {
            if ($rem < 20) $r[] = $ones[$rem];
            else { $t = intdiv($rem,10); $o = $rem%10; if ($o) $r[] = $ones[$o]; $r[] = $tens[$t]; }
        }
        return implode(' و', $r);
    };
    $parts = [];
    foreach ([1000000000=>['مليار','ملياران','مليارات'],1000000=>['مليون','مليونان','ملايين'],1000=>['ألف','ألفان','آلاف']] as $val=>$nm) {
        if ($num >= $val) {
            $cnt = intdiv($num,$val); $num %= $val;
            if ($cnt==1) $parts[]=$nm[0];
            elseif ($cnt==2) $parts[]=$nm[1];
            elseif ($cnt>=3 && $cnt<=10) $parts[]=$b3($cnt).' '.$nm[2];
            else $parts[]=$b3($cnt).' '.$nm[0];
        }
    }
    if ($num>0) $parts[]=$b3($num);
    return implode(' و', $parts);
}

function formatUSD($amount, $withCurrency = true) {
    $formatted = number_format((float)$amount, 2, '.', ',');
    return $withCurrency ? '$' . $formatted : $formatted;
}

function formatDate($date, $format = 'd/m/Y') {
    if (!$date || $date === '0000-00-00') return '—';
    $ts = is_numeric($date) ? $date : strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

function formatMonthYear($month, $year, $lang = 'fr') {
    $months_fr = [1=>'Janv.', 'Févr.', 'Mars', 'Avril', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
    $months_ar = [1=>'كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين 1', 'تشرين 2', 'كانون الأول'];
    $months = $lang === 'ar' ? $months_ar : $months_fr;
    return ($months[$month] ?? '') . ' ' . $year;
}

function monthName($month, $lang = 'fr', $short = false) {
    $fr_long = [1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    $fr_short = [1=>'Janv.','Févr.','Mars','Avril','Mai','Juin','Juil.','Août','Sept.','Oct.','Nov.','Déc.'];
    $ar = [1=>'كانون الثاني','شباط','آذار','نيسان','أيار','حزيران','تموز','آب','أيلول','تشرين الأول','تشرين الثاني','كانون الأول'];
    if ($lang === 'ar') return $ar[$month] ?? '';
    return $short ? ($fr_short[$month] ?? '') : ($fr_long[$month] ?? '');
}

// =====================================================
// Labels
// =====================================================
function employeeTypeLabel($type, $lang = 'fr') {
    $labels = [
        'enseignant_titulaire' => ['fr' => 'Enseignant titulaire', 'ar' => 'أستاذ في الملاك'],
        'enseignant_contractuel' => ['fr' => 'Enseignant contractuel', 'ar' => 'أستاذ متعاقد'],
        'employe' => ['fr' => 'Employé administratif', 'ar' => 'موظف إداري']
    ];
    return $labels[$type][$lang] ?? $type;
}

/**
 * عنوان فئة الموظف لعناوين أقسام الكشوف (2026-06-16): الملاك / المتعاقدين / الموظفين.
 * يُستعمل لتقسيم كل التقارير: أساتذة الملاك معاً ثم المتعاقدون ثم الموظفون (كل فئة أبجدياً).
 */
function empCategoryTitle($type) {
    return ['enseignant_titulaire'=>'الملاك','enseignant_contractuel'=>'المتعاقدين','employe'=>'الموظفين'][$type] ?? $type;
}

/**
 * يُصدِر صفّ عنوان فئة (الملاك/المتعاقدين/الموظفين) عند تغيّر الفئة داخل حلقة عرض
 * موظفين مرتّبة حسب الفئة. مرّر $curCat بالمرجع (ابدأها = null)، و$colspan = عدد أعمدة الجدول.
 */
function categoryHeaderRow(&$curCat, $type, $colspan) {
    if ($type === $curCat) return '';
    $curCat = $type;
    return '<tr class="cat-row"><td colspan="' . (int)$colspan . '" style="text-align:right;font-weight:700;background:#dbeafe;font-size:1.05em">'
         . e(empCategoryTitle($type)) . '</td></tr>';
}

function socialStatusLabel($status, $lang = 'fr') {
    $labels = [
        'celibataire' => ['fr' => 'Célibataire', 'ar' => 'أعزب'],
        'marie_sans_enfants' => ['fr' => 'Marié sans enfants', 'ar' => 'متزوج بدون أولاد'],
        'marie_1_enfant' => ['fr' => 'Marié 1 enfant', 'ar' => 'متزوج وله ولد'],
        'marie_2_enfants' => ['fr' => 'Marié 2 enfants', 'ar' => 'متزوج وله ولدين'],
        'marie_3_enfants' => ['fr' => 'Marié 3 enfants', 'ar' => 'متزوج وله 3 أولاد'],
        'marie_4_enfants' => ['fr' => 'Marié 4 enfants', 'ar' => 'متزوج وله 4 أولاد'],
        'marie_5_enfants' => ['fr' => 'Marié 5 enfants', 'ar' => 'متزوج وله 5 أولاد']
    ];
    return $labels[$status][$lang] ?? $status;
}

function diplomaLabel($code, $lang = 'fr') {
    static $diplomas = null;
    if ($diplomas === null) {
        $stmt = getDB()->query("SELECT diploma_code, diploma_name_ar, diploma_name_fr FROM diploma_starting_grades");
        $diplomas = [];
        while ($row = $stmt->fetch()) {
            $diplomas[$row['diploma_code']] = $row;
        }
    }
    if (!isset($diplomas[$code])) return $code;
    return $lang === 'ar' ? $diplomas[$code]['diploma_name_ar'] : $diplomas[$code]['diploma_name_fr'];
}

/**
 * رقم الضمان مع سنة الولادة تلقائياً (سنة الولادة على الشمال): «1967-788049».
 * تُستعمل في كل مستندات المدرسة (الكشوف/الإفادات/التقارير) لتوحيد العرض.
 * النماذج الرسمية الحكومية تبقى تعرض الرقم الأصلي وحده.
 */
function cnssWithBirthYear($nssf, $birthDate, $fallback = '—') {
    $nssf = trim((string)$nssf);
    $by = $birthDate ? date('Y', strtotime($birthDate)) : '';
    if ($nssf === '' && $by === '') return $fallback;
    if ($nssf === '') return $by;
    return $by !== '' ? $by . '-' . $nssf : $nssf;
}

function employeeStatusLabel($status, $lang = 'fr') {
    $labels = [
        'actif' => ['fr' => 'Actif', 'ar' => 'نشط', 'badge' => 'success'],
        'suspendu' => ['fr' => 'Suspendu', 'ar' => 'متوقف', 'badge' => 'warning'],
        'retraite' => ['fr' => 'Retraité', 'ar' => 'متقاعد', 'badge' => 'secondary'],
        'demissionne' => ['fr' => 'Démissionné', 'ar' => 'مستقيل', 'badge' => 'danger']
    ];
    if (!isset($labels[$status])) return ['label' => $status, 'badge' => 'secondary'];
    return ['label' => $labels[$status][$lang], 'badge' => $labels[$status]['badge']];
}

// =====================================================
// Exchange Rate
// =====================================================
function getExchangeRate($month = null, $year = null) {
    if ($month === null) $month = (int)date('n');
    if ($year === null) $year = (int)date('Y');
    
    $stmt = getDB()->prepare("SELECT rate FROM exchange_rates WHERE year = ? AND month = ?");
    $stmt->execute([$year, $month]);
    $rate = $stmt->fetchColumn();
    
    if (!$rate) {
        $stmt = getDB()->prepare("SELECT rate FROM exchange_rates ORDER BY year DESC, month DESC LIMIT 1");
        $stmt->execute();
        $rate = $stmt->fetchColumn();
    }
    
    return $rate ? (float)$rate : (float)getSetting('current_exchange_rate', 89500);
}

// =====================================================
// CNSS Brackets — حدود الأجر الخاضع للضمان لكل فرع
// =====================================================
/** كل فروع الضمان مع تسمياتها (عربي/فرنسي). */
function cnssBranches() {
    return [
        'maladie_maternite'      => ['ar' => 'ضمان المرض والأمومة', 'fr' => 'Maladie & Maternité'],
        'allocations_familiales' => ['ar' => 'التعويضات العائلية',   'fr' => 'Allocations familiales'],
        'fin_de_service'         => ['ar' => 'تعويض نهاية الخدمة',   'fr' => 'Indemnité fin de service'],
        'eoc'                    => ['ar' => 'صندوق التعاضد (EOC)',  'fr' => 'Caisse EOC'],
    ];
}

function cnssBranchLabel($branch, $lang = 'fr') {
    $b = cnssBranches();
    return $b[$branch][$lang] ?? $branch;
}

/**
 * يعيد صف الحدود (min/max) الساري لفرع معيّن بتاريخ شهر/سنة، أو null إن لا يوجد.
 * يختار أحدث صف effective_from ≤ التاريخ وضمن نطاق effective_to (أو مفتوح).
 */
function getCnssBracket($branch, $month = null, $year = null) {
    if ($month === null) $month = (int)date('n');
    if ($year === null)  $year  = (int)date('Y');
    $asOf = sprintf('%04d-%02d-01', $year, $month);
    $stmt = getDB()->prepare(
        "SELECT * FROM cnss_brackets
         WHERE branch = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to >= ?)
         ORDER BY effective_from DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$branch, $asOf, $asOf]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * يطبّق الحد الأقصى (السقف) لفرع على أجر خاضع. إن لم يوجد سقف يرجع القيمة كما هي.
 * لا يوجد حد أدنى (لا أحد يُدفع أقل من الحد الأدنى للأجور أصلاً).
 */
function clampCnssBase($base, $branch, $month = null, $year = null) {
    $b = getCnssBracket($branch, $month, $year);
    if (!$b) return $base;
    if ($b['max_salary_lbp'] !== null && $base > (float)$b['max_salary_lbp']) {
        $base = (float)$b['max_salary_lbp'];
    }
    return $base;
}

// =====================================================
// Rate History — النِّسَب والقيم المؤرّخة (من تاريخ إلى تاريخ)
// =====================================================
/** المعاملات (النِّسَب والقيم) القابلة للتأريخ مع تسمياتها. */
function ratedParams() {
    return [
        'minimum_wage_lbp'         => ['ar' => 'الحد الأدنى للأجور (ل.ل)',          'fr' => 'Salaire minimum (L.L)',        'unit' => 'lbp'],
        'cnss_employee_rate'       => ['ar' => 'الضمان — حصة الموظف %',             'fr' => 'CNSS — part employé %',         'unit' => 'pct'],
        'cnss_employer_rate'       => ['ar' => 'الضمان — حصة المدرسة %',            'fr' => 'CNSS — part école %',           'unit' => 'pct'],
        'eoc_employee_rate'        => ['ar' => 'صندوق التعاضد — حصة الأستاذ %',     'fr' => 'EOC — part enseignant %',       'unit' => 'pct'],
        'eoc_employer_rate'        => ['ar' => 'صندوق التعاضد — حصة المدرسة %',     'fr' => 'EOC — part école %',            'unit' => 'pct'],
        'family_compensation_rate' => ['ar' => 'التعويضات العائلية — المدرسة %',    'fr' => 'Alloc. familiales — école %',   'unit' => 'pct'],
        'end_of_service_rate'      => ['ar' => 'تعويض نهاية الخدمة — المدرسة %',    'fr' => 'Fin de service — école %',      'unit' => 'pct'],
    ];
}

function ratedParamLabel($key, $lang = 'fr') {
    $p = ratedParams();
    return $p[$key][$lang] ?? $key;
}

/**
 * يعيد قيمة معامل سارية بتاريخ شهر/سنة من rate_history.
 * إن لم يوجد صف مؤرّخ → يرجع إلى قيمة settings (getSetting) ثم $default.
 */
function getRateAsOf($key, $month = null, $year = null, $default = null) {
    if ($month === null) $month = (int)date('n');
    if ($year === null)  $year  = (int)date('Y');
    $asOf = sprintf('%04d-%02d-01', $year, $month);
    $stmt = getDB()->prepare(
        "SELECT value FROM rate_history
         WHERE param_key = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to >= ?)
         ORDER BY effective_from DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$key, $asOf, $asOf]);
    $v = $stmt->fetchColumn();
    if ($v !== false && $v !== null) return (float)$v;
    $fallback = getSetting($key, '');
    if ($fallback !== '') return (float)$fallback;
    return (float)$default;
}

/**
 * يحدّث جدول settings بالقيمة السارية اليوم لكل معامل مؤرّخ،
 * حتى تبقى الشاشات التي تستعمل getSetting (لوحة القيادة...) متطابقة مع الحالي.
 */
function syncCurrentRatesToSettings() {
    foreach (array_keys(ratedParams()) as $key) {
        $stmt = getDB()->prepare(
            "SELECT value FROM rate_history
             WHERE param_key = ? AND effective_from <= CURDATE()
               AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v !== false && $v !== null) {
            // أرقام الليرة بلا كسور؛ النِّسَب نحافظ على شكلها
            $out = (floor((float)$v) == (float)$v) ? (string)(int)$v : rtrim(rtrim((string)$v, '0'), '.');
            setSetting($key, $out);
        }
    }
}

// =====================================================
// Audit
// =====================================================
function logAudit($action, $tableName, $recordId, $oldValue = null, $newValue = null) {
    if (!isLoggedIn()) return;
    try {
        $stmt = getDB()->prepare("INSERT INTO audit_log (user_id, username, action, table_name, record_id, old_value, new_value, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SESSION['username'] ?? '',
            $action,
            $tableName,
            $recordId,
            is_array($oldValue) ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : $oldValue,
            is_array($newValue) ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : $newValue,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Exception $e) {
        // silent fail
    }
}

// =====================================================
// JSON Response
// =====================================================
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================
// School Year
// =====================================================
function currentSchoolYear() {
    // السنة الدراسية تبدأ في تشرين الأول (10) وتنتهي في أيلول (9).
    $month = (int)date('n');
    $year = (int)date('Y');
    if ($month >= 10) {
        return $year . '-' . ($year + 1);
    }
    return ($year - 1) . '-' . $year;
}

// السنة الدراسية التي يقع فيها تاريخ معيّن (تشرين الأول→أيلول). تُرجع مثل "2026-2027" أو null.
function schoolYearOfDate($date) {
    $ts = strtotime((string)$date);
    if (!$ts) return null;
    $m = (int)date('n', $ts); $y = (int)date('Y', $ts);
    return ($m >= 10) ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);
}

// السنة الدراسية الفعّالة المختارة من الأعلى (من الجلسة)، أو الحالية افتراضياً
function activeSchoolYear() {
    $sy = $_SESSION['active_school_year'] ?? '';
    if ($sy === 'all') return 'all';
    return preg_match('/^\d{4}-\d{4}$/', $sy) ? $sy : currentSchoolYear();
}

function schoolYearToYears($schoolYear) {
    // "2025-2026" => [2025, 2026]
    return array_map('intval', explode('-', $schoolYear));
}

/**
 * معرّف إصدار السلسلة الساري بتاريخ معيّن (الافتراضي: اليوم).
 * يدعم سلاسل جديدة «من تاريخ إلى تاريخ»؛ الافتراضي الإصدار 1 (سلسلة 2017).
 */
function scaleVersionIdAsOf($asOfDate = null) {
    if ($asOfDate === null) $asOfDate = date('Y-m-d');
    $stmt = getDB()->prepare(
        "SELECT id FROM salary_scale_versions
         WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) AND is_active = 1
         ORDER BY effective_from DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$asOfDate, $asOfDate]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 1;
}

/**
 * راتب السلسلة (new_salary_2017) لدرجة قد تكون كسرية — يُحسب على **الدرجة الكاملة** (floor)
 * بلا استيفاء: نصف الدرجة (.5) تعني نزول الدرجة الكاملة التالية السنة القادمة، لا نصف راتب.
 * يختار إصدار السلسلة الساري بتاريخ $asOfDate (الافتراضي اليوم) لدعم سلسلة جديدة.
 * نفس منطق PayrollCalculator::getScaleColumn ليتطابق العرض مع الحساب.
 */
function scaleSalaryLBP($grade, $asOfDate = null) {
    static $cache = [];
    $grade = (float)$grade;
    if ($grade < 1) $grade = 1;
    if ($grade > 52) $grade = 52;
    $vid = scaleVersionIdAsOf($asOfDate);
    $key = $vid . ':' . $grade;
    if (isset($cache[$key])) return $cache[$key];

    $low = (int)floor($grade);   // الدرجة الكاملة (floor) — لا استيفاء لنصف الدرجة

    $stmt = getDB()->prepare("SELECT new_salary_2017 AS v FROM salary_scale_2017 WHERE version_id = ? AND grade = ?");
    $stmt->execute([$vid, $low]);
    $res = (float)($stmt->fetchColumn() ?: 0);
    return $cache[$key] = $res;
}

/**
 * يبني جدول تطوّر الراتب والتدرّج (متل «مثل 2») من سجلّ الدرجات.
 * صفّ لكل حدث درجة + صفّ لكل 1/10 خالٍ من حدث (سنة ساكنة) لإظهار الاستمرار.
 * يعيد صفوفاً: date, type, grade_before, grade_after, base, ordinary, exceptional, after
 */
function buildSalaryEvolution($employeeId, $currentGrade) {
    $stmt = getDB()->prepare("SELECT * FROM employee_grade_history WHERE employee_id = ? ORDER BY change_date ASC, id ASC");
    $stmt->execute([$employeeId]);
    $events = $stmt->fetchAll();
    if (!$events) return [];

    $eventDates = array_map(fn($e) => $e['change_date'], $events);
    $firstYear  = (int)substr($events[0]['change_date'], 0, 4);
    $curYear    = (int)date('Y');

    $items = [];
    foreach ($events as $e) $items[] = ['date' => $e['change_date'], 'ev' => $e];
    for ($y = $firstYear; $y <= $curYear; $y++) {
        $d = sprintf('%04d-10-01', $y);
        if (!in_array($d, $eventDates, true)) $items[] = ['date' => $d, 'ev' => null];
    }
    usort($items, function ($a, $b) {
        if ($a['date'] === $b['date']) return ($a['ev'] === null ? 1 : 0) - ($b['ev'] === null ? 1 : 0);
        return strcmp($a['date'], $b['date']);
    });

    $rows = [];
    $running = null;
    foreach ($items as $it) {
        if ($it['ev']) {
            $e = $it['ev'];
            $gb = (float)$e['grade_before'];
            $ga = (float)$e['grade_after'];
            $running = $ga;
            $isEntry = ($e['reason'] === 'titularization' || $gb < 1);
            $isExc   = (strpos($e['reason'], 'exceptional') === 0) || !empty($e['law_reference']);
            $baseG   = $isEntry ? $ga : $gb;             // الأساس قبل الحدث
            $base    = scaleSalaryLBP($baseG, $e['change_date']);
            $after   = scaleSalaryLBP($ga, $e['change_date']);
            $added   = max(0, $after - ($isEntry ? $after : scaleSalaryLBP($gb, $e['change_date'])));
            $rows[] = [
                'date' => $e['change_date'],
                'type' => $isEntry ? 'entry' : ($isExc ? 'exceptional' : ($e['reason'] === 'manual' ? 'manual' : 'ordinary')),
                'grade_before' => $gb, 'grade_after' => $ga,
                'base' => $base,
                'ordinary'    => (!$isEntry && !$isExc) ? $added : 0,
                'exceptional' => ($isExc) ? $added : 0,
                'after' => $after,
                'law' => $e['law_reference'],
            ];
        } else {
            if ($running === null) continue; // قبل أول حدث
            $s = scaleSalaryLBP($running, $it['date']);
            $rows[] = [
                'date' => $it['date'], 'type' => 'stable',
                'grade_before' => $running, 'grade_after' => $running,
                'base' => $s, 'ordinary' => 0, 'exceptional' => 0, 'after' => $s, 'law' => null,
            ];
        }
    }
    return $rows;
}

/**
 * لوحة درجات الأستاذ (مشتركة بين صفحة الدرجات grades.php وملف الأستاذ employees.php):
 * تعرض كل الدرجات بترتيب زمني مع شاك-مارك «محسوبة؟» لكل درجة (عدا دخول الملاك المقفل).
 * شيل الصح = الدرجة تبقى ظاهرة لكن لا تُحتسب (delta محفوظ، قابلة للإرجاع). تُحفظ بإرسال
 * النموذج إلى grades.php (المعالج الموحّد grade_save)، ويعود إلى الصفحة حسب $returnTo.
 * @param array  $emp      صفّ الموظف (يحتاج id, employee_type).
 * @param string $returnTo 'grades' (افتراضي) أو 'employee' (يرجع لملف الأستاذ).
 */
function renderGradeChecklist($emp, $returnTo = 'grades') {
    if (($emp['employee_type'] ?? '') !== 'enseignant_titulaire') return;
    $db = getDB();
    $st = $db->prepare("SELECT * FROM employee_grade_history WHERE employee_id = ? ORDER BY change_date ASC, id ASC");
    $st->execute([(int)$emp['id']]);
    $history = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($history)) {
        echo '<div class="empty-state"><i class="fas fa-history"></i><h4>لا يوجد سجلّ درجات بعد</h4>'
           . '<p class="text-muted">اضبط دخول الملاك والشهادة واضغط «إعادة بناء الدرجات حسب القانون» في صفحة الدرجات.</p></div>';
        return;
    }
    $reasonLabels = [
        'titularization'     => ['دخول الملاك', 'gold'],
        'biennial_promotion' => ['درجة عادية (تشرين)', 'success'],
        'exceptional'        => ['درجة استثنائية (كانون)', 'info'],
        'manual'             => ['تعديل يدوي', 'secondary'],
    ];
    $excludedCount = 0;
    foreach ($history as $hh) { if ($hh['reason'] !== 'titularization' && (int)$hh['counted'] === 0) $excludedCount++; }
    $fmtG = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
    ?>
    <p class="text-muted" style="font-size:13px;margin:0 0 8px">
        ✅ = البرنامج حاطّ هذه الدرجة تلقائياً وهي <strong>محسوبة</strong> للأستاذ.
        <strong>شِيل الصح عن أي درجة ما بدّك تعطيه ياها</strong> — تبقى ظاهرة لكن <strong>لا تُحسب</strong>
        (وتقدر ترجّع الصح وقت ما بدّك فتُحسب من جديد). ثم اضغط «حفظ» — تتحدّث الدرجة الحالية والراتب تلقائياً.
        (درجة دخول الملاك ثابتة دائماً.)
        <?php if ($excludedCount): ?><br><span style="color:#c0392b">حالياً <?= $excludedCount ?> درجة مستثناة (غير محسوبة).</span><?php endif; ?>
    </p>
    <form method="POST" action="<?= BASE_URL ?>pages/grades.php?employee_id=<?= (int)$emp['id'] ?>">
        <?= csrfField() ?>
        <input type="hidden" name="grade_save" value="1">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <table class="table">
            <thead><tr>
                <th style="text-align:center">محسوبة؟</th>
                <th>التاريخ</th><th>نوع الدرجة</th><th style="text-align:center">مقدارها</th>
                <th style="text-align:center">الدرجة بعدها</th><th>ملاحظة</th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $h):
                $isTitul = ($h['reason'] === 'titularization');
                $counted = $isTitul || (int)$h['counted'] === 1;
                $amount  = ($h['delta'] !== null) ? (float)$h['delta'] : ((float)$h['grade_after'] - (float)$h['grade_before']);
                [$rlabel, $rcolor] = $reasonLabels[$h['reason']] ?? [$h['reason'], 'light'];
                if ($h['law_reference']) $rlabel .= ' — قانون ' . $h['law_reference'];
            ?>
                <tr style="<?= !$counted ? 'opacity:.5;background:#fbfbfb' : '' ?>">
                    <td style="text-align:center">
                        <?php if ($isTitul): ?>
                            <i class="fas fa-lock text-muted" title="درجة دخول الملاك — ثابتة دائماً"></i>
                        <?php else: ?>
                            <input type="checkbox" name="keep[]" value="<?= (int)$h['id'] ?>" <?= $counted ? 'checked' : '' ?> style="width:20px;height:20px;cursor:pointer">
                        <?php endif; ?>
                    </td>
                    <td><strong><?= formatDate($h['change_date']) ?></strong></td>
                    <td><span class="badge badge-<?= $rcolor ?>"><?= e($rlabel) ?></span></td>
                    <td style="text-align:center"><span class="badge badge-<?= $amount > 0 ? 'success' : 'warning' ?>"><?= $amount >= 0 ? '+' : '' ?><?= $fmtG($amount) ?></span></td>
                    <td style="text-align:center"><?= $counted ? '<strong>' . $fmtG($h['grade_after']) . '</strong>' : '<span class="text-muted" title="غير محسوبة">—</span>' ?></td>
                    <td><small><?= e($h['notes']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary" data-confirm="حفظ الدرجات المحتسَبة وإعادة حساب الراتب؟">
            <i class="fas fa-save"></i> حفظ الدرجات
        </button>
    </form>
    <?php
}
