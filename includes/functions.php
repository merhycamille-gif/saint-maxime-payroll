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
    // قفل مركزي لمستخدمي «قراءة فقط» (يسري على كل الصفحات والمنافذ لأن requireLogin تُستدعى في كلٍّ منها)
    enforceViewerRestrictions();
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
// صلاحية «قراءة فقط» (حساب مدرسة يشاهد التقارير والإفادات فقط)
// =====================================================

/** مستخدم بدور viewer = قراءة فقط، لا يعدّل ولا فاصلة. */
function isViewer() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'viewer';
}

/** هل يملك المستخدم صلاحية التعديل؟ (أي دور ما عدا viewer). */
function canEdit() {
    return isLoggedIn() && !isViewer();
}

/**
 * التقارير/الصفحات التي يستطيع المدير تفعيلها/تعطيلها لكل حساب مدرسة (checklist).
 * المفتاح = اسم الملف (basename) ؛ القيمة = التسمية بالعربي/الفرنسي.
 */
function viewerReportPages() {
    return [
        'reports.php'          => ['fr' => 'Rapports',            'ar' => 'التقارير'],
        'attestations.php'     => ['fr' => 'Attestations',        'ar' => 'إفادات الأساتذة'],
        'annual_slip.php'      => ['fr' => 'Relevé annuel',       'ar' => 'الكشف السنوي'],
        'monthly_payroll.php'  => ['fr' => 'Paie mensuelle',      'ar' => 'الراتب الشهري'],
        'employee_history.php' => ['fr' => 'Dossier enseignant',  'ar' => 'سيرة الأستاذ'],
    ];
}

/** منافذ التصدير/الطباعة التابعة لكل صفحة (تُفتح فقط إن كانت الصفحة الأمّ مسموحة). */
function viewerReportExports() {
    return [
        'reports.php'     => ['reports_export.php'],
        'annual_slip.php' => ['annual_slip_export.php'],
    ];
}

/** صفحات البنية التحتية المسموحة دائماً لأي حساب مدرسة (ملاحة/طباعة/كلمة مرور). */
function viewerBaseScripts() {
    return [
        'index.php',                                 // لوحة القيادة (صفحة الوصول)
        'print_pdf.php',                             // طباعة PDF
        'official_forms.php', 'official_export.php', // النماذج الرسمية (طباعة)
        'settings.php',                              // لتغيير كلمة المرور فقط
        'switch_year.php', 'switch_lang.php', 'switch_school.php', 'logout.php', // ملاحة
    ];
}

/**
 * يبني قائمة الصفحات المسموحة لحساب مدرسة بناءً على اختياره المخزّن (allowed_pages).
 * - $allowedRaw = null  ⇒ حساب لم يُضبط بعد (قديم) ⇒ كل التقارير (توافق خلفي).
 * - $allowedRaw = ''    ⇒ لا شيء مختار ⇒ لوحة القيادة فقط.
 * - '"a.php,b.php"'     ⇒ الصفحات المختارة فقط.
 */
function viewerAllowedScriptsForUser($allowedRaw) {
    $pages = viewerReportPages();
    $exports = viewerReportExports();
    if ($allowedRaw === null) {
        $sel = array_keys($pages); // قديم: اعرض الكل
    } else {
        $sel = array_filter(array_map('trim', explode(',', (string)$allowedRaw)));
    }
    $allowed = viewerBaseScripts();
    foreach ($sel as $s) {
        if (!isset($pages[$s])) continue;         // تجاهل أي قيمة غير معروفة
        $allowed[] = $s;
        if (!empty($exports[$s])) $allowed = array_merge($allowed, $exports[$s]);
    }
    return array_values(array_unique($allowed));
}

/** قائمة الصفحات المسموحة للمستخدم (viewer) الحالي — تُحسب مرّة وتُخزَّن. */
function currentViewerAllowedScripts() {
    static $cache = null;
    if ($cache !== null) return $cache;
    ensureUsersPermsColumn();
    $raw = null;
    try {
        $st = getDB()->prepare("SELECT allowed_pages FROM users WHERE id = ?");
        $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
        $val = $st->fetchColumn();
        $raw = ($val === false) ? null : $val; // NULL بالقاعدة ⇒ الكل
    } catch (Exception $e) { $raw = null; }
    $cache = viewerAllowedScriptsForUser($raw);
    return $cache;
}

/** هل يستطيع المستخدم الحالي رؤية هذه الصفحة؟ (غير الـviewer يرى كل شيء). */
function viewerCanSeePage($script) {
    if (!isViewer()) return true;
    return in_array($script, currentViewerAllowedScripts(), true);
}

/** تركيب أعمدة صلاحيات المدرسة ذاتياً إن لم تكن موجودة (auto-heal، بلا تدخّل يدوي). */
function ensureUsersPermsColumn() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        if (!$db->query("SHOW COLUMNS FROM users LIKE 'allowed_pages'")->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN allowed_pages TEXT NULL");   // التقارير المسموحة
        }
        if (!$db->query("SHOW COLUMNS FROM users LIKE 'allowed_schools'")->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN allowed_schools TEXT NULL");  // المدارس المسموحة ('all' أو قائمة ids)
        }
    } catch (Exception $e) { /* تجاهل الفشل الصامت */ }
}

/**
 * المدارس التي يُسمح لحساب المدرسة (viewer) الحالي برؤيتها.
 *  - allowed_schools = 'all'  ⇒ كل المدارس (يُحلّ إلى كل الـids الفعّالة).
 *  - '2,5,7'                  ⇒ المدارس المحددة.
 *  - NULL/''                  ⇒ مدرسته المفردة (school_id) — توافق خلفي.
 * يرجع مصفوفة ids (مُحلّاة). تُحسب مرّة وتُخزَّن.
 */
function viewerAllowedSchoolIds() {
    static $cache = null;
    if ($cache !== null) return $cache;
    ensureUsersPermsColumn();
    $raw = null; $sid = 0;
    try {
        $st = getDB()->prepare("SELECT school_id, allowed_schools FROM users WHERE id = ?");
        $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
        $r = $st->fetch();
        if ($r) { $raw = $r['allowed_schools']; $sid = (int)($r['school_id'] ?? 0); }
    } catch (Exception $e) {}

    if ($raw === 'all') {
        $cache = array_map(fn($s) => (int)$s['id'], allSchools(false)); // كل المدارس
    } elseif ($raw !== null && $raw !== '') {
        $ids = array_filter(array_map('intval', explode(',', $raw)), fn($x) => $x > 0);
        $cache = array_values(array_unique($ids));
    } else {
        $cache = $sid > 0 ? [$sid] : []; // مدرسة مفردة (توافق خلفي)
    }
    return $cache;
}

/**
 * يطبّق قيود مستخدم «قراءة فقط»:
 *  (1) إيقاف فوري إن أُوقف الحساب.
 *  (2) قفل الصفحات حسب صلاحيات هذا الحساب تحديداً (checklist المدير).
 *  (3) قفل التعديل: يمنع أي كتابة (POST/حذف) عدا تغيير كلمة المرور.
 * يُستدعى مركزياً من requireLogin() فيسري على كل البرنامج ومنافذ الطباعة/التصدير.
 * لا يؤثّر إطلاقاً على المدير/المشغّل — ينفّذ شيئاً فقط عندما يكون الدور viewer.
 */
function enforceViewerRestrictions() {
    if (!isViewer()) return; // غير الـviewer: لا شيء يتغيّر

    // (1) إيقاف فوري: إذا أوقف المدير هذا الحساب (is_active=0) يُطرَد حالاً حتى لو كان مسجّل دخول.
    static $activeChecked = false;
    if (!$activeChecked) {
        $activeChecked = true;
        try {
            $st = getDB()->prepare("SELECT is_active FROM users WHERE id = ?");
            $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row || (int)$row['is_active'] !== 1) {
                $_SESSION = [];
                if (session_id() !== '') @session_destroy();
                header('Location: ' . BASE_URL . 'login.php');
                exit;
            }
        } catch (Exception $e) { /* تجاهل الفشل الصامت */ }
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));

    // (2) قفل الصفحات حسب صلاحيات هذا الحساب
    if (!in_array($script, currentViewerAllowedScripts(), true)) {
        $_SESSION['flash_error'] = 'قراءة فقط: لا تملك صلاحية الدخول إلى هذه الصفحة. / Lecture seule : accès non autorisé.';
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }

    // (3) قفل التعديل — يُسمح فقط بتغيير كلمة المرور من صفحة الإعدادات
    $isPasswordChange = ($script === 'settings.php' && ($_POST['action'] ?? '') === 'change_password');
    $isWrite = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') || isset($_GET['delete']);
    if ($isWrite && !$isPasswordChange) {
        $_SESSION['flash_error'] = 'قراءة فقط: التعديل غير مسموح. / Lecture seule : modification interdite.';
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
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
    // حساب مدرسة (قراءة فقط): نطاقه = المدارس المسموحة له.
    // إن كان يملك عدة مدارس (أو الكل) يستطيع تصفيتها من المبدّل الأعلى (ضمن المسموح فقط).
    if (isViewer()) {
        $allowed = viewerAllowedSchoolIds();
        if (count($allowed) <= 1) return $allowed;               // مدرسة واحدة (أو لا شيء)
        $sel = $_SESSION['active_schools'] ?? [];
        if (!is_array($sel)) $sel = [];
        $sel = array_values(array_intersect(array_map('intval', $sel), $allowed));
        return $sel ?: $allowed;                                  // فارغ = كل المسموح
    }
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

// تحويل قائمة معرّفات الصفوف ("13,14") إلى أسماء مفصولة بفواصل عربية. يرجع '—' إن لم توجد.
// محصّن: لو جدول class_levels غير موجود (قبل migration 015) يرجع '—'.
function classLevelNames($csv) {
    static $map = null;
    if ($map === null) {
        $map = [];
        // العرض: الفرنسي قبل العربي (fr / ar)، أو العربي وحده إن لم يوجد فرنسي
        try { foreach (getDB()->query("SELECT id, name, name_fr FROM class_levels") as $r) {
            $fr = trim((string)($r['name_fr'] ?? ''));
            $map[(int)$r['id']] = $fr !== '' ? ($fr . ' / ' . $r['name']) : $r['name'];
        } }
        catch (Exception $e) { $map = []; }
    }
    $names = [];
    foreach (array_filter(array_map('intval', explode(',', (string)$csv))) as $cid) { if (isset($map[$cid])) $names[] = $map[$cid]; }
    return $names ? implode('، ', $names) : '—';
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

// 🩹 شفاء ذاتي: احذف أيّ راتب شهري يقع في سنة دراسية **بعد** سنة ترك الأستاذ.
// يُستدعى تلقائياً بعد حفظ/تعديل تاريخ الترك (ملف الأستاذ + رابط تحديث المعلومات).
// السبب: عند فتح سنة جديدة قبل تسجيل تاريخ الترك كانت تُولَّد «رواتب وهمية» للتاركين تبقى في البيانات.
// هذه الدالة تضمن أن رواتب أيّ تارك تتوقّف عند سنة تركه — فلا يظهر في السنة الحالية ولا تتلوّث التقارير.
// تحافظ على أشهر سنة الترك نفسها (مثلاً ترك 30/6 ورواتب الصيف حتى 30/9 من نفس السنة الدراسية).
function pruneSalariesAfterDeparture($db, $empId) {
    $empId = (int)$empId;
    $row = $db->query("SELECT LEAST(COALESCE(left_date_cnss,'9999-12-31'),COALESCE(left_date_finance,'9999-12-31'),COALESCE(left_date_eoc,'9999-12-31')) ld FROM employees WHERE id = $empId")->fetch();
    if (!$row || empty($row['ld']) || $row['ld'] === '9999-12-31') return 0; // ليس تاركاً → لا شيء
    $y = (int)substr($row['ld'], 0, 4); $m = (int)substr($row['ld'], 5, 2);
    $depRank = ($m >= 10) ? $y : $y - 1; // رتبة السنة الدراسية للترك (تبدأ في تشرين الأول)
    // رتبة صفّ (year,month) = (month>=10 ? year : year-1). نحذف كل صفّ رتبته > رتبة الترك.
    $del = $db->prepare("DELETE FROM monthly_salaries WHERE employee_id = ? AND ((month >= 10 AND year > ?) OR (month < 10 AND year - 1 > ?))");
    $del->execute([$empId, $depRank, $depRank]);
    return $del->rowCount();
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
    // مستخدم «قراءة فقط» (حساب مدرسة): يُسمح له بالتصدير كـ PDF و WhatsApp فقط — لا طباعة مباشرة/Excel/Word/Email
    $viewerOnly = isViewer();
    if ($viewerOnly) { $showEmail = false; }
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
        <?php if (!$viewerOnly): ?>
        <button type="button" class="btn btn-sm btn-primary" onclick="ppPrint()"><i class="fas fa-print"></i> Imprimer / طباعة</button>
        <?php endif; ?>
        <?php if ($officialPdf): ?>
        <a class="btn btn-sm btn-danger" href="<?= htmlspecialchars($officialPdf, ENT_QUOTES) ?>" title="PDF رسمي طبق الأصل جاهز للدولة"><i class="fas fa-file-pdf"></i> PDF<?= $viewerOnly ? '' : ' رسمي' ?></a>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-danger" onclick="ppPdf()" title="اختر: حفظ كـ PDF"><i class="fas fa-file-pdf"></i> PDF</button>
        <?php endif; ?>
        <?php if (!$viewerOnly): ?>
            <?php if ($server): ?>
            <a class="btn btn-sm btn-success" href="<?= $sv . $sep ?>format=xlsx"><i class="fas fa-file-excel"></i> Excel</a>
            <a class="btn btn-sm btn-info" href="<?= $sv . $sep ?>format=docx"><i class="fas fa-file-word"></i> Word</a>
            <?php elseif (!$noOffice): ?>
            <button type="button" class="btn btn-sm btn-success" onclick="ppExcel('<?= $t ?>')"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="button" class="btn btn-sm btn-info" onclick="ppWord('<?= $t ?>')"><i class="fas fa-file-word"></i> Word</button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($showWa): ?>
            <?php if ($viewerOnly): ?>
            <button type="button" class="btn btn-sm" style="background:#25D366;color:#fff" onclick="ppWhatsAppPdf('<?= $t ?>','<?= $ph ?>','<?= htmlspecialchars($officialPdf, ENT_QUOTES) ?>')" title="يفتح ملف الـPDF لترفقه + محادثة واتساب"><i class="fab fa-whatsapp"></i> WhatsApp PDF</button>
            <?php else: ?>
            <button type="button" class="btn btn-sm" style="background:#25D366;color:#fff" onclick="ppWhatsApp('<?= $t ?>','<?= $ph ?>')"><i class="fab fa-whatsapp"></i> WhatsApp</button>
            <?php endif; ?>
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
 * وظائف الموظف الإداري (employee_type = 'employe') الذي يخضع لقانون العمل.
 * كود ثابت ⇐ عنوان ثنائي اللغة. الموظف الإداري ليس أستاذاً: لا صفوف ولا مواد ولا مراحل،
 * بل يُحدَّد نوع وظيفته (سكرتير/محاسبة/تنظيفات/سائق/...). القيمة المخزّنة إمّا كود من هنا أو نص حر.
 */
function jobTitleOptions() {
    return [
        'directeur'          => ['fr' => 'Directeur',           'ar' => 'مدير'],
        'directrice'         => ['fr' => 'Directrice',          'ar' => 'مديرة'],
        'responsable'        => ['fr' => 'Responsable',         'ar' => 'مسؤول / مسؤولة'],
        'secretaire'         => ['fr' => 'Secrétaire',          'ar' => 'سكرتير / سكرتيرة'],
        'comptable'          => ['fr' => 'Comptable',           'ar' => 'محاسب / محاسبة'],
        'surveillant'        => ['fr' => 'Surveillant',         'ar' => 'مراقب / ناظر'],
        'assistante_sociale' => ['fr' => 'Assistante sociale',  'ar' => 'مساعِدة اجتماعية'],
        'infirmiere'         => ['fr' => 'Infirmière',          'ar' => 'ممرّضة'],
        'chauffeur'          => ['fr' => 'Chauffeur',           'ar' => 'سائق'],
        'nettoyage'          => ['fr' => "Agent d'entretien",   'ar' => 'عامل / عاملة تنظيفات'],
        'maintenance'        => ['fr' => 'Maintenance',         'ar' => 'صيانة'],
        'concierge'          => ['fr' => 'Concierge / Gardien', 'ar' => 'ناطور'],
        'cuisine'            => ['fr' => 'Cuisine',             'ar' => 'مطبخ'],
        'bibliothecaire'     => ['fr' => 'Bibliothécaire',      'ar' => 'أمين مكتبة'],
    ];
}

/**
 * عنوان الوظيفة للعرض. إن كانت القيمة كوداً معروفاً تُترجَم ثنائياً، وإلا تُعرَض كما هي (نص حر).
 */
function jobTitleLabel($value, $lang = 'fr') {
    $value = trim((string)$value);
    if ($value === '') return '';
    $opts = jobTitleOptions();
    if (isset($opts[$value])) return $opts[$value][$lang] ?? $opts[$value]['fr'];
    return $value; // نص حر أدخله المستخدم
}

/**
 * عرض درجة الموظف بشكل «حسب الأصول»: الأستاذ يظهر رقم درجته (بلا الصفر العشري الزائد،
 * والنص .5 يبقى ظاهراً)، أمّا الموظف الإداري (employe) فيظهر «—» لأنه يخضع لقانون العمل
 * بلا سلسلة رتب ولا درجة. مرّر صفّ الموظف كاملاً، أو النوع مع الدرجة صراحةً.
 */
function gradeDisplay($empOrType, $grade = null) {
    if (is_array($empOrType)) {
        $type = $empOrType['employee_type'] ?? '';
        if ($grade === null) {
            $grade = $empOrType['current_grade'] ?? ($empOrType['grade_at_month'] ?? null);
        }
    } else {
        $type = (string)$empOrType;
    }
    if ($type === 'employe') return '—'; // موظف إداري: لا درجة (قانون العمل)
    return rtrim(rtrim(number_format((float)$grade, 1, '.', ''), '0'), '.');
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

// تحليل تاريخ مُدخَل يدوياً بأيّ صيغة شائعة → 'Y-m-d' أو null.
// يقبل: 15/08/1980 · 15-08-1980 · 15.8.1980 (يوم/شهر/سنة) و 1980-08-15 (ISO من input القديم).
// بديل موثوق عن strtotime (الذي يفسّر «15/08/1980» بالصيغة الأمريكية فيفشل).
function parseFlexibleDate($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m))            { $y = $m[1]; $mo = $m[2]; $d = $m[3]; }
    elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $s, $m)) { $d = $m[1]; $mo = $m[2]; $y = $m[3]; }
    else return null;
    if (!checkdate((int)$mo, (int)$d, (int)$y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

// تنسيق 'Y-m-d' → 'd/m/Y' لعرضه في حقل نصّي (أو '' إن فارغ/وهمي).
function displayDMY($ymd) {
    if (empty($ymd) || $ymd === '0000-00-00' || $ymd === '1900-01-01') return '';
    $ts = strtotime((string)$ymd);
    return $ts ? date('d/m/Y', $ts) : '';
}

// السنة الدراسية التي يقع فيها تاريخ معيّن (تشرين الأول→أيلول). تُرجع مثل "2026-2027" أو null.
function schoolYearOfDate($date) {
    $ts = strtotime((string)$date);
    if (!$ts) return null;
    $m = (int)date('n', $ts); $y = (int)date('Y', $ts);
    return ($m >= 10) ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);
}

/**
 * العمر (سنوات كاملة) في تاريخ معيّن (أو اليوم افتراضياً)، أو null إذا كان تاريخ الولادة
 * ناقصاً/وهمياً (0000-00-00 / 1900-01-01). يُستعمل لتنبيه بلوغ سنّ الـ64.
 */
function ageOnDate($birthDate, $onDate = null) {
    $bs = substr((string)$birthDate, 0, 10);
    if ($bs === '' || in_array($bs, ['0000-00-00', '1900-01-01'], true)) return null;
    $b = date_create($bs);
    $o = $onDate ? date_create(substr((string)$onDate, 0, 10)) : date_create('today');
    if (!$b || !$o) return null;
    return (int)date_diff($b, $o)->y;
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
 * شفاء ذاتي: وصف قانون 2017 في `exceptional_grades_laws` حُفِظ سابقاً برموز معطوبة (؟؟؟) بسبب
 * ترميز قديم عند التنصيب. يُصحَّح النصّ العربي/الفرنسي مرّة واحدة (الشرط LIKE '%?%' يجعله idempotent
 * فلا يُكتب بعد الإصلاح). آمن — بيانات مرجعية فقط (لا يمسّ درجات أو رواتب).
 */
function healLaw2017Description() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $ar = 'سلسلة 2017: 6 درجات لمن دخل الملاك قبل 1/1/2010 (كل الشهادات) أو قسم ثاني (2010→30/9/2017)؛ درجتان لإجازة جامعية/جاردينير ب.ت/ت.س (2010→30/9/2017).';
        $fr = 'Loi 2017: 6 échelons (titularisé avant 2010, ou Qsm2 2010-2017) / 2 échelons (Licence/Jardinière 2010-2017).';
        $st = getDB()->prepare("UPDATE exceptional_grades_laws SET description_ar=?, description_fr=?
                                WHERE law_number='2017' AND description_ar LIKE '%?%'");
        $st->execute([$ar, $fr]);
    } catch (Throwable $e) { /* تجاهل آمن */ }
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
    // شفاء ذاتي مرّة واحدة: قسّم أي درجة استثنائية ملمومة لهذا الأستاذ إلى وحدات مفردة (آمن، بلا تغيير المجموع/الراتب).
    splitExceptionalUnitsForEmployee((int)$emp['id']);
    // شفاء ذاتي: وصف قانون 2017 كان محفوظاً برموز معطوبة (ترميز قديم) → يُصحَّح مرّة (idempotent).
    healLaw2017Description();
    $st = $db->prepare("SELECT * FROM employee_grade_history WHERE employee_id = ? ORDER BY change_date ASC, id ASC");
    $st->execute([(int)$emp['id']]);
    $history = $st->fetchAll(PDO::FETCH_ASSOC);
    $fmtG = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.');

    // نوع كل درجة بتسمية واضحة (الاستثنائية مخزّنة بـreason='' فنستدلّ عليها من law_reference/الملاحظة).
    $labelFor = function ($h) {
        $r = $h['reason'];
        if ($r === 'titularization')     return ['دخول الملاك', 'gold', true];
        if ($r === 'biennial_promotion') return ['درجة عادية (تشرين)', 'success', false];
        if ($r === 'manual')             return ['تعديل يدوي', 'secondary', false];
        // ما تبقّى = درجة استثنائية: إمّا قانون مسمّى (244/102/223/2017) أو نظام الأساتذة الجدد (4+4+2).
        $lbl = !empty($h['law_reference'])
            ? 'درجة استثنائية — قانون ' . $h['law_reference']
            : 'درجة استثنائية (4+4+2)';   // بلا قانون مسمّى = نظام المستجدّ (يشمل دفعات فتح السنة)
        return [$lbl, 'info', false];
    };

    // خريطة كل قانون (رقم → الاسم + تاريخ الصدور) لعرضه في عمود «ملاحظة» بجانب كل درجة استثنائية.
    $lawNames = [];
    foreach ($db->query("SELECT law_number, law_date, description_ar, description_fr FROM exceptional_grades_laws") as $L) {
        $lawNames[(string)$L['law_number']] = [
            'name' => $L['description_ar'] ?: $L['description_fr'],
            'date' => $L['law_date'],
        ];
    }
    // نصّ «سلسلة الرتب والرواتب (قانون 46/2017)» + تاريخ صدوره — لدرجات الدخول والتدرّج العادي.
    $scaleLaw = (isset($lawNames['2017']['date']) && $lawNames['2017']['date'])
        ? 'قانون 46/2017 صادر ' . formatDate($lawNames['2017']['date'])
        : 'قانون 46/2017';

    // قوانين استثنائية فيها **وحدات لم تُعطَ بعد** لهذا الأستاذ — كل وحدة (+1 أو ½) تُمنَح فردياً بتاريخها.
    $laws = $db->query("SELECT * FROM exceptional_grades_laws WHERE is_active = 1 ORDER BY law_date")->fetchAll(PDO::FETCH_ASSOC);
    $grantable = [];
    foreach ($laws as $law) {
        $units = exceptionalGrantUnits($emp, $law);   // المتبقّي = مجموع القانون − المُعطى، وحداتٍ مفردة
        if (empty($units)) continue;                  // مُعطى كاملاً أو لا ينطبق
        $grantable[] = ['law' => $law, 'units' => $units];
    }

    $excludedCount = 0;
    foreach ($history as $hh) { if ($hh['reason'] !== 'titularization' && (int)$hh['counted'] === 0) $excludedCount++; }

    if (empty($history) && empty($grantable)) {
        echo '<div class="empty-state"><i class="fas fa-history"></i><h4>لا يوجد سجلّ درجات بعد</h4>'
           . '<p class="text-muted">اضبط دخول الملاك والشهادة واضغط «إعادة بناء الدرجات حسب القانون» في صفحة الدرجات.</p></div>';
        return;
    }
    ?>
    <p class="text-muted" style="font-size:13px;margin:0 0 8px">
        ✅ = درجة <strong>مُعطاة ومحسوبة</strong> للأستاذ (وضعها البرنامج تلقائياً عند استحقاقها).
        <strong>شِيل الصح</strong> عن أي درجة ما بدّك تحسبها (تبقى ظاهرة لكن لا تُحسب، وترجّعها وقت ما بدّك).
        <strong>التاريخ قدّام كل درجة تقدر تعدّلو.</strong>
        وتحت اللائحة في <strong>«درجات استثنائية بعدها ما أُعطيت»</strong> — كبس الصح واختر التاريخ لتُعطى وتُحسب.
        ثم اضغط «حفظ» فتتحدّث الدرجة والراتب تلقائياً. (درجة دخول الملاك ثابتة.)
        <?php if ($excludedCount): ?><br><span style="color:#c0392b">حالياً <?= $excludedCount ?> درجة مستثناة (غير محسوبة).</span><?php endif; ?>
    </p>
    <form method="POST" action="<?= BASE_URL ?>pages/grades.php?employee_id=<?= (int)$emp['id'] ?>">
        <?= csrfField() ?>
        <input type="hidden" name="grade_save" value="1">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <?php if (!empty($history)): ?>
        <table class="table">
            <thead><tr>
                <th style="text-align:center">محسوبة؟</th>
                <th>التاريخ <small class="text-muted">(قابل للتعديل)</small></th><th>نوع الدرجة</th><th style="text-align:center">مقدارها</th>
                <th style="text-align:center">الدرجة بعدها</th><th>ملاحظة</th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $h):
                [$rlabel, $rcolor, $isTitul] = $labelFor($h);
                $counted = $isTitul || (int)$h['counted'] === 1;
                $amount  = ($h['delta'] !== null) ? (float)$h['delta'] : ((float)$h['grade_after'] - (float)$h['grade_before']);
            ?>
                <tr style="<?= !$counted ? 'opacity:.5;background:#fbfbfb' : '' ?>">
                    <td style="text-align:center">
                        <?php if ($isTitul): ?>
                            <i class="fas fa-lock text-muted" title="درجة دخول الملاك — ثابتة دائماً"></i>
                        <?php else: ?>
                            <input type="checkbox" name="keep[]" value="<?= (int)$h['id'] ?>" <?= $counted ? 'checked' : '' ?> style="width:20px;height:20px;cursor:pointer">
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isTitul): ?>
                            <strong><?= formatDate($h['change_date']) ?></strong>
                        <?php else: ?>
                            <input type="date" name="gdate[<?= (int)$h['id'] ?>]" value="<?= e($h['change_date']) ?>" class="form-control" style="max-width:160px;font-size:12px;padding:4px 6px">
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $rcolor ?>"><?= e($rlabel) ?></span></td>
                    <td style="text-align:center"><span class="badge badge-<?= $amount > 0 ? 'success' : 'warning' ?>"><?= $amount >= 0 ? '+' : '' ?><?= $fmtG($amount) ?></span></td>
                    <td style="text-align:center"><?= $counted ? '<strong>' . $fmtG($h['grade_after']) . '</strong>' : '<span class="text-muted" title="غير محسوبة">—</span>' ?></td>
                    <td><small><?php
                        // عمود الملاحظة: الأساس القانوني لكل درجة (لكل الحالات) —
                        //  • قانون استثنائي مسمّى (244/102/223/2017=قانون 46): «قانون X — الاسم».
                        //  • درجة استثنائية بلا قانون مسمّى = نظام الأساتذة الجدد 4+4+2 (يشمل دفعات فتح السنة).
                        //  • دخول الملاك: درجة الدخول حسب الشهادة.  • التدرّج العادي: التدرّج الدوري.
                        $ref = (string)$h['law_reference'];
                        $rsn = $h['reason'];
                        if ($ref !== '' && isset($lawNames[$ref])) {
                            $li = $lawNames[$ref];
                            $issued = !empty($li['date']) ? ' (صدر بتاريخ ' . formatDate($li['date']) . ')' : '';
                            $noteTxt = 'قانون ' . $ref . $issued . ' — ' . $li['name'];
                        } elseif ($rsn === 'titularization') {
                            $noteTxt = 'درجة الدخول حسب الشهادة — سلسلة الرتب والرواتب (' . $scaleLaw . ') عند دخول الملاك';
                        } elseif ($rsn === 'biennial_promotion') {
                            $noteTxt = 'التدرّج الدوري نصف درجة كل سنة — سلسلة الرتب والرواتب (' . $scaleLaw . ')';
                        } elseif ($rsn === 'manual') {
                            $noteTxt = $h['notes'] !== '' ? $h['notes'] : 'تعديل يدوي';
                        } else {
                            // نظام الأساتذة الجدد 4+4+2 = بديل قوانين الدرجات الاستثنائية للمستجدّين (بعد 2/4/2012).
                            // نذكر القوانين المرجعية وأرقامها وتواريخ صدورها.
                            $subs = [];
                            foreach (['244','102','223'] as $ln) {
                                if (!isset($lawNames[$ln])) continue;
                                $dt = !empty($lawNames[$ln]['date']) ? ' صادر ' . formatDate($lawNames[$ln]['date']) : '';
                                $subs[] = 'قانون ' . $ln . $dt;
                            }
                            $noteTxt = 'درجات الأساتذة الجدد (نظام 4+4+2) — بموجب '
                                     . ($subs ? implode(' · ', $subs) : 'قوانين الدرجات الاستثنائية')
                                     . ' لمن دخل الملاك بعد 2012';
                        }
                        echo e($noteTxt);
                    ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($grantable)): ?>
        <h4 style="color:var(--primary);margin:18px 0 6px"><i class="fas fa-plus-circle"></i> درجات استثنائية بعدها ما أُعطيت (متاحة لهذا الأستاذ)</h4>
        <p class="text-muted" style="font-size:12px;margin:0 0 8px"><strong>كل درجة لحالها.</strong> الشك-مارك فاضي = ما انعطت بعد. كبس الصح واختر تاريخ الإعطاء لكل درجة بدّك ياها فتُضاف وتُحسب.</p>
        <table class="table">
            <thead><tr>
                <th style="text-align:center">إعطاء؟</th><th>القانون / الدرجة</th><th style="text-align:center">مقدارها</th>
                <th>تاريخ الإعطاء</th><th>الوصف</th>
            </tr></thead>
            <tbody>
            <?php foreach ($grantable as $gb): $law = $gb['law']; $units = $gb['units']; $nU = count($units);
                foreach ($units as $i => $u): $key = $law['law_number'] . '__' . $i; ?>
                <tr>
                    <td style="text-align:center"><input type="checkbox" name="gunit[]" value="<?= e($key) ?>" style="width:20px;height:20px;cursor:pointer"></td>
                    <td><strong>قانون <?= e($law['law_number']) ?></strong> <small class="text-muted">— درجة <?= $i + 1 ?>/<?= $nU ?></small></td>
                    <td style="text-align:center"><span class="badge badge-gold">+<?= $fmtG($u['delta']) ?></span></td>
                    <td><input type="date" name="gudate[<?= e($key) ?>]" value="<?= e($u['date']) ?>" class="form-control" style="max-width:160px;font-size:12px;padding:4px 6px"></td>
                    <td><small><?= e($law['description_ar'] ?: $law['description_fr']) ?></small></td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" data-confirm="حفظ الدرجات وإعادة حساب الراتب؟">
            <i class="fas fa-save"></i> حفظ الدرجات
        </button>
    </form>
    <?php
}
