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
    // منع المتصفّح من تخبئة الصفحات: حتى تنعكس تبديلات العملة/«الراتب يشمل» فوراً بلا Ctrl+F5،
    // وأماناً لأنّ الصفحات فيها بيانات رواتب حسّاسة يجب ألّا تُخزَّن بذاكرة المتصفّح.
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
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
        // مبدّلات العرض والبحث (كانت ناقصة فكان حساب المدرسة يُطرد عند تغيير العملة/مكوّنات الراتب
        // ويرجع بحث Ctrl+K فارغاً — كلّها عرض فقط ولا تعدّل بيانات):
        'switch_currency.php', 'switch_salarycomp.php', 'ajax_search.php',
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
/**
 * 🔵 معرّفات المدارس **الفاعلة** (is_active=1) — تعريف «كل المدارس».
 * وضع «كل المدارس» كان يعني «بلا أي فلترة» فتُدمَج بياناتُ المدارس المعطّلة في كل
 * المجاميع (641 مليون ل.ل بسنة 2025-2026) رغم أنها لا تظهر بمبدّل المدارس ولا بالتقارير.
 * القاعدة الملزِمة: أي عرض/تقرير يستثني المدارس المعطّلة.
 */
function allActiveSchoolIdsCached() {
    static $ids = null;
    if ($ids === null) {
        try { $ids = array_map('intval', array_column(allSchools(), 'id')); }
        catch (Exception $e) { $ids = []; }
    }
    return $ids;
}

function schoolScopeSql($column = 'school_id') {
    $ids = activeSchoolIds();
    if (empty($ids)) $ids = allActiveSchoolIdsCached(); // «الكل» = كل الفاعلة (لا المعطّلة)
    if (empty($ids)) return ' ';                        // لا مدارس فاعلة: لا تُعطّل الصفحة
    $in = implode(',', array_map('intval', $ids));
    return " AND {$column} IN ({$in}) ";
}

// نفس الفكرة لكن كأول شرط (بدون AND بادئة)
function schoolScopeWhere($column = 'school_id') {
    $ids = activeSchoolIds();
    if (empty($ids)) $ids = allActiveSchoolIdsCached();
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

/**
 * 🔵 من وضع «كل المدارس»: إن كان الإجراء يخصّ موظفاً معيّناً، بدّل الجلسة لمدرسته تلقائياً
 * وأكمل — بدل تحويل المستخدم للائحة المدارس ليختار بنفسه. تُستدعى قبل requireSchoolSelected().
 * تتقيد بالمدارس المسموحة للمستخدم (schoolScopeSql) فلا تفتح مدرسة ليست من صلاحياته.
 */
function autoSwitchToEmployeeSchool(int $employeeId): void {
    if ($employeeId <= 0 || !isAllSchools()) return;
    $st = getDB()->prepare("SELECT school_id FROM employees WHERE id = ? AND is_deleted = 0" . schoolScopeSql());
    $st->execute([$employeeId]);
    $sid = (int)$st->fetchColumn();
    if ($sid > 0) {
        $_SESSION['active_schools'] = [$sid];
        unset($_SESSION['report_schools']);
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

/* 🏛️ صاحب العمل تجاه الضمان الاجتماعي (بطلب المستخدم 2026-08-19):
 * «كل شي تابع للضمان» (الإفادات والنماذج والتقارير) يصدر باسم صاحب العمل المسجَّل
 * لدى الصندوق برقمه — فكل المؤسسات التي رقمها 25-82-043 تصدر أوراق ضمانها باسم
 * «الراهبات المخلصيات لسيدة البشارة» (الجمعية صاحبة الرقم)، وما عداها باسم مؤسسته.
 * المطابقة برقم صاحب العمل مطبَّعاً (تجاهل الفراغات والأصفار البادئة: «25 - 82 - 43» = «25 - 82 - 043»). */
function cnssEmployerNumberKey($num): string {
    $num = strtr((string)$num, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    $parts = preg_split('/[^0-9]+/', $num, -1, PREG_SPLIT_NO_EMPTY);
    return implode('-', array_map('intval', $parts));
}
/* «عمل الأجير» في نماذج الضمان (بطلب المستخدم 2026-08-19): الأستاذ يُكتب «أستاذ» فقط
 * (لا «أستاذ في الملاك» ولا «أستاذ متعاقد») — والموظف الإداري حسب وظيفته الفعلية. */
function cnssOccupationAr(array $emp): string {
    if (($emp['employee_type'] ?? '') === 'employe') {
        $jt = trim((string)($emp['job_title'] ?? ''));
        return $jt !== '' ? jobTitleLabel($jt, 'ar') : 'موظف';
    }
    return 'أستاذ';
}

/* 🏙️ «صحح العنوان» (بطلب المستخدم 2026-08-20): عناوين مدارس مخزّنة بمقاطع مكررة
 * («عبرا - عبرا - الراهبات المخلصيات») — للعرض بالوثائق نشيل المقطع المكرر ونبقي أول ظهور. */
function dedupeAddress($addr): string {
    $parts = preg_split('/\s*[-–ـ]\s*/u', trim((string)$addr), -1, PREG_SPLIT_NO_EMPTY);
    $seen = []; $out = [];
    foreach ($parts as $p) {
        $k = preg_replace('/\s+/u', ' ', trim($p));
        // مقطع بلا أي حرف/رقم («.» وحدها) = زبالة إدخال — لا يُعرض
        if ($k === '' || isset($seen[$k]) || !preg_match('/[\p{L}\p{N}]/u', $k)) continue;
        $seen[$k] = 1; $out[] = $k;
    }
    return implode(' - ', $out);
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-20 «صححها كلها وين ما كان — برنامج ما لازم يكون فيه أخطاء»):
 * عناوين مدارس مخزّنة بمقاطع مكررة («عبرا - عبرا - الراهبات المخلصيات»، وزوج مكرر كامل بمدرسة)
 * وألف مكررة بأول كلمة («االمحتقرة») — ننقّي address (وaddress_fr إن وُجد) بالداتا نفسها،
 * فتصير كل الشاشات والتقارير والنماذج نظيفة من مصدرها. idempotent: المنقّى لا يتبدّل ثانيةً.
 */
function healSchoolAddressDedupe20260820() {
    $flag = 'school_address_dedupe_2026_08_20';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        $cols = ['address'];
        try { $db->query("SELECT address_fr FROM schools LIMIT 1"); $cols[] = 'address_fr'; } catch (Throwable $e) {}
        $n = 0;
        foreach ($db->query("SELECT * FROM schools")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            foreach ($cols as $c) {
                $old = (string)($s[$c] ?? '');
                if (trim($old) === '') continue;
                $new = preg_replace('/(^|\s)اا/u', '$1ا', dedupeAddress($old));
                if ($new !== $old) {
                    $db->prepare("UPDATE schools SET `$c` = ? WHERE id = ?")->execute([$new, (int)$s['id']]);
                    $n++;
                }
            }
        }
        setSetting($flag, date('Y-m-d H:i') . " ($n حقل)");
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-21 «اسم المكان مظبوط بالفرنسي»): حرف تشكيل عربي ضائع
 * بأول الاسم الفرنسي لمدرسة («ُEcole St.Georges» بيارون) — ننقّي name_fr وaddress_fr من
 * حروف التشكيل والتطويل العربية بالداتا نفسها فتصير كل الإفادات اللاتينية نظيفة. idempotent.
 */
function healSchoolNameFrDiacritics20260821() {
    $flag = 'school_name_fr_diacritics_2026_08_21';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        $n = 0;
        foreach ($db->query("SELECT * FROM schools")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            foreach (['name_fr', 'address_fr'] as $c) {
                if (!array_key_exists($c, $s)) continue;
                $old = (string)($s[$c] ?? '');
                if (trim($old) === '') continue;
                $new = trim(preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $old));
                if ($new !== $old) {
                    $db->prepare("UPDATE schools SET `$c` = ? WHERE id = ?")->execute([$new, (int)$s['id']]);
                    $n++;
                }
            }
        }
        setSetting($flag, date('Y-m-d H:i') . " ($n حقل)");
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي (2026-08-20 «الراتب بدك تجمعو مع الإضافي أو المكافأة إذا محطوطين — صحح»):
 * علاوات (أجر إضافي/نقل) موجودة بنسخة الكمبيوتر وناقصة أونلاين (مثال السي موسى: الإضافي
 * 133م مفقود أونلاين فطلع «الراتب الحالي» بنموذج الضمان أساساً فقط). اللائحة الكاملة مولَّدة
 * من الداتا المحلية بassets/data/bonuses_backfill_20260820.json — هنا نضيف **الناقص فقط**
 * (موظف مطابق الهوية بالاسم أو رقم الضمان، وما عنده علاوة فعّالة من نفس النوع لنفس السنة)
 * ثم نعيد حساب أشهر تلك السنة الموجودة له. يعمل دفعاتٍ (25 موظفاً بالفتحة) حتى لا يعلّق
 * الأونلاين، ويتابع تلقائياً بالفتحات التالية إلى أن يختم بـdone. idempotent بالكامل.
 */
function healBonusBackfill20260820() {
    $flagKey = 'bonus_backfill_2026_08_20';
    $st = getSetting($flagKey, '');
    if (strpos($st, 'done') === 0) return;
    $file = __DIR__ . '/../assets/data/bonuses_backfill_20260820.json';
    if (!is_file($file)) return;
    try {
        $db = getDB();
        $list = json_decode((string)file_get_contents($file), true);
        if (!is_array($list) || !$list) { setSetting($flagKey, 'done (ملف فارغ)'); return; }
        $byEmp = [];
        foreach ($list as $r) $byEmp[(int)$r['e']][] = $r;
        $ids = array_keys($byEmp); sort($ids);
        $from = (strpos($st, 'i=') === 0) ? (int)substr($st, 2) : 0;
        $insTot = (strpos($st, 'i=') === 0 && strpos($st, '+') !== false) ? (int)substr($st, strpos($st, '+') + 1) : 0;
        @set_time_limit(600);
        require_once __DIR__ . '/payroll_calculator.php';
        $batch = 0;
        for ($i = $from; $i < count($ids); $i++) {
            if (++$batch > 25) { setSetting($flagKey, 'i=' . $i . ' +' . $insTot); return; } // يتابع بالفتحة الجاية
            $eid = $ids[$i];
            $eq = $db->prepare("SELECT first_name_ar, last_name_ar, nssf_number FROM employees WHERE id=? AND is_deleted=0");
            $eq->execute([$eid]);
            $er = $eq->fetch();
            if (!$er) continue;
            $recalcYears = [];
            foreach ($byEmp[$eid] as $b) {
                // أمان الهوية: نفس الاسم والشهرة، أو نفس رقم الضمان — وإلا لا نلمس
                $nameOk = trim((string)$er['first_name_ar']) === trim((string)$b['fn'])
                       && trim((string)$er['last_name_ar'])  === trim((string)$b['ln']);
                $nssfOk = trim((string)$b['ns']) !== '' && trim((string)$er['nssf_number']) === trim((string)$b['ns']);
                if (!$nameOk && !$nssfOk) continue;
                $has = $db->prepare("SELECT 1 FROM employee_bonuses WHERE employee_id=? AND school_year=? AND bonus_type=? AND is_active=1 LIMIT 1");
                $has->execute([$eid, $b['sy'], $b['t']]);
                if ($has->fetchColumn()) continue; // عنده — لا تكرار ولا تعديل
                $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active)
                              VALUES (?,?,?,?,?,?,?,?,?,1)")
                   ->execute([$eid, $b['t'], $b['p'], $b['sy'], $b['a'], $b['vt'], $b['cur'], $b['sm'], $b['em']]);
                $recalcYears[(string)$b['sy']] = 1; $insTot++;
            }
            foreach (array_keys($recalcYears) as $sy) {
                // 🔴 (2026-08-29) كان يستدعي المحرّك الكامل مباشرةً فصفّر أساس المنقولين بلا إعداد أونلاين
                // (عبرا/الانتقال/النياح/البشارة — 2026-08-20). المسار الآمن الوحيد: recalcEmployeeYear.
                try { recalcEmployeeYear($eid, $sy); } catch (Throwable $e) {}
            }
        }
        setSetting($flagKey, 'done ' . date('Y-m-d H:i') . " (+$insTot علاوة)");
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد عند الفتح التالي */ }
}

function cnssEmployerSchool(?array $school): ?array {
    if (!$school) return $school;
    static $EMPLOYERS = [
        '25-82-43' => ['ar' => 'الراهبات المخلصيات لسيدة البشارة',
                       'fr' => "Sœurs Salvatoriennes de Notre-Dame de l'Annonciation"],
    ];
    $key = cnssEmployerNumberKey($school['nssf_employer_number'] ?? '');
    if ($key !== '' && isset($EMPLOYERS[$key])) {
        $school['name_ar'] = $EMPLOYERS[$key]['ar'];
        $school['name_fr'] = $EMPLOYERS[$key]['fr'];
    }
    return $school;
}

// تحويل قائمة معرّفات الصفوف ("13,14") إلى أسماء مفصولة بفواصل عربية. يرجع '—' إن لم توجد.
// محصّن: لو جدول class_levels غير موجود (قبل migration 015) يرجع '—'.
// $frOnly=true: الأسماء الفرنسية فقط (CP، CE1...) — تُستعمل بالكشف السنوي بطلب المستخدم.
function classLevelNames($csv, bool $frOnly = false) {
    static $map = null;
    if ($map === null) {
        $map = [];
        // 🩹 شفاء ذاتي (طلب المستخدم 2026-08-25: «أسماء الصفوف بالفرنسي EB7,EB8...»): صفوف
        // زُرعت بلا name_fr (البذر 015 لا يملأه) فكان الكشف يسقط للعربي — نملأ الفرنسي
        // المعروف بالذاكرة دائماً، ونثبّته بقاعدة البيانات (محجوب عن حسابات القراءة-فقط)
        $frDefaults = [
            'روضة أولى'=>'PS','روضة ثانية'=>'MS','روضة ثالثة'=>'GS',
            'الأول أساسي'=>'EB1','الثاني أساسي'=>'EB2','الثالث أساسي'=>'EB3','الرابع أساسي'=>'EB4',
            'الخامس أساسي'=>'EB5','السادس أساسي'=>'EB6','السابع أساسي'=>'EB7','الثامن أساسي'=>'EB8','التاسع أساسي'=>'EB9',
            'الأول ثانوي'=>'Secondaire 1','الثاني ثانوي'=>'Secondaire 2','الثالث ثانوي'=>'Secondaire 3',
        ];
        try { foreach (getDB()->query("SELECT id, name, name_fr FROM class_levels") as $r) {
            $fr = trim((string)($r['name_fr'] ?? ''));
            $ar = trim((string)$r['name']);
            if ($fr === '' && isset($frDefaults[$ar])) {
                $fr = $frDefaults[$ar];
                if (!isViewer()) {
                    try { getDB()->prepare("UPDATE class_levels SET name_fr = ? WHERE id = ? AND (name_fr IS NULL OR name_fr = '')")
                                 ->execute([$fr, (int)$r['id']]); } catch (Exception $eH) {}
                }
            }
            $map[(int)$r['id']] = ['fr' => $fr, 'ar' => (string)$r['name']];
        } }
        catch (Exception $e) { $map = []; }
    }
    $names = [];
    foreach (array_filter(array_map('intval', explode(',', (string)$csv))) as $cid) {
        if (!isset($map[$cid])) continue;
        $fr = $map[$cid]['fr']; $ar = $map[$cid]['ar'];
        // العرض: الفرنسي قبل العربي (fr / ar)، أو الفرنسي وحده ($frOnly)، مع سقوط للعربي إن لا فرنسي
        $names[] = $frOnly ? ($fr !== '' ? $fr : $ar) : ($fr !== '' ? ($fr . ' / ' . $ar) : $ar);
    }
    if (!$names) return '—';
    return implode($frOnly ? ', ' : '، ', $names);
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
        // 🔴 حساب مدرسة (قراءة فقط) قد يكون مصرّحاً له بعدّة مدارس — وقتها users.school_id = NULL
        // فكان $sid = 0 ويُفهَم «كل المدارس» فيرى تقارير كل المدارس! الصحّ: مدارسه المصرّح بها فقط.
        if (isViewer()) {
            $allowed = viewerAllowedSchoolIds();
            if (!empty($allowed)) return array_values(array_map('intval', $allowed));
        }
        $sid = (int)($_SESSION['school_id'] ?? 0);
        // بلا مدرسة ولا تصريح: لا شيء (id مستحيل) — أفضل من كشف كل المدارس
        return $sid > 0 ? [$sid] : [-1];
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
    // **ولم يترك قبل بدايتها**. التارك يبقى ظاهراً في كل سنة عمل فيها حتى تاريخ تركه
    // (مثال: ترك 30-9-2026 → يظهر في 2025-2026 كاملة لأنه عمل فيها، ويختفي من 2026-2027
    // التي تبدأ 1-10-2026). ولرؤية كل السابقين يُستعمل «كل السنين» (بلا فلترة).
    // شرط القيمة>0 يستبعد الصفوف الصفرية (الأشباح).
    $yearStart = $m[1] . '-10-01'; // بداية السنة الدراسية (تشرين الأول)
    $leftDate = "LEAST(COALESCE({$prefix}left_date_cnss,'9999-12-31'),"
              . "COALESCE({$prefix}left_date_finance,'9999-12-31'),"
              . "COALESCE({$prefix}left_date_eoc,'9999-12-31'))";
    $sql = " AND {$prefix}id IN (SELECT employee_id FROM monthly_salaries"
         . " WHERE school_year = ? AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0))"
         . " AND {$leftDate} >= ?";
    return [$sql, [$schoolYear, $yearStart]];
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
    // (2026-08-29) الأشهر التي تلي شهر الترك **ضمن نفس السنة** لا تُحذف تلقائياً: قد يكون بقرار المستخدم
    // (حنان تحومي مكمَّلة كل السنة رغم تاريخ تركها) — تُعرض «للمراجعة» بالفحص الرسمي فقط.
    return $del->rowCount();
}

/**
 * 🩹 شفاء ذاتي مستمرّ (2026-08-06 — شكوى «التاركون رجعوا طلعوا بالسنة الجديدة»):
 * تنظيف «الرواتب الوهمية» لكل التاركين دفعة واحدة، بكل المدارس:
 *   (1) أي صفّ راتب **غير مدفوع** (is_paid=0) في سنة دراسية تبدأ بعد تاريخ الترك يُحذف.
 *       الصفوف المدفوعة لا تُمَسّ أبداً (لو وُجدت فهي بحاجة قرار المستخدم — تظهر بصفحة فحص الصحة).
 *   (2) أحداث الدرجات الآلية «(فتح السنة)» المؤرّخة بعد سنة الترك (ذيل السجلّ فقط، لا اليدوية).
 *   (3) العلاوات المنسوخة آلياً لسنين بعد الترك.
 * السبب: قبل حماية المحرّك (calculateAndSave) كانت أي إعادة حساب أو فتح سنة تولّد رواتب
 * لتاركين. يعمل مرّة لكل جلسة (خفيف)، وزرّ «نسخ الملف لسنة» يرجّع الراجع فاعلاً فلا يتأثّر.
 * قاعدة التارك §١٠: يبقى بسنة عمله (حتى 30-9) ويُشال ممّا بعدها فقط.
 */
function healLeaverPhantomRows() {
    if (!empty($_SESSION['heal_leaver_phantoms_done'])) return;
    $_SESSION['heal_leaver_phantoms_done'] = 1;
    try {
        $db = getDB();
        $ldExpr = "LEAST(COALESCE(NULLIF(e.left_date_cnss,'0000-00-00'),'9999-12-31'),"
                . "COALESCE(NULLIF(e.left_date_finance,'0000-00-00'),'9999-12-31'),"
                . "COALESCE(NULLIF(e.left_date_eoc,'0000-00-00'),'9999-12-31'))";
        $depRank = "(CASE WHEN MONTH($ldExpr) >= 10 THEN YEAR($ldExpr) ELSE YEAR($ldExpr) - 1 END)";
        $d1 = (int)$db->exec("DELETE ms FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND $ldExpr < '9999-12-31' AND COALESCE(ms.is_paid, 0) = 0
              AND (CASE WHEN ms.month >= 10 THEN ms.year ELSE ms.year - 1 END) > $depRank");
        $d2 = (int)$db->exec("DELETE gh FROM employee_grade_history gh JOIN employees e ON e.id = gh.employee_id
            WHERE e.is_deleted = 0 AND $ldExpr < '9999-12-31' AND gh.notes LIKE '%(فتح السنة)%'
              AND (CASE WHEN MONTH(gh.change_date) >= 10 THEN YEAR(gh.change_date) ELSE YEAR(gh.change_date) - 1 END) > $depRank");
        $d3 = (int)$db->exec("DELETE b FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
            WHERE e.is_deleted = 0 AND $ldExpr < '9999-12-31'
              AND CAST(SUBSTRING(b.school_year, 1, 4) AS UNSIGNED) > $depRank");
        if ($d1 || $d2 || $d3) {
            logAudit('heal_leaver_phantoms', 'monthly_salaries', 0, null,
                     ['salaries' => $d1, 'grade_events' => $d2, 'bonuses' => $d3]);
        }
    } catch (Exception $e) { /* لا نُعطّل الصفحة */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-06 — حالة مارسيلا داود «الإضافي بالملف صح وبالكشف مش
 * هوي ذاتو»): إعادة الحساب بعد حفظ الملف كانت تصيب السنة التقويمية فقط بينما العلاوات
 * تُحفَظ على السنة المعروضة — فأي تعديل والمستخدم على السنة الجديدة المفتوحة ترك أشهرها
 * المخزّنة على القديم واختلفت الكشوف عن الملف. الكود صُلِّح (recalcEmployeeYear)؛ وهذا
 * الشفاء يصلّح البيانات العالقة الموجودة:
 *   (أ) كل السنين المفتوحة اللاحقة للسنة الجارية: يعاد حسابها بالمحرّك الموحّد
 *       (المعدّون حساب كامل، والمنقولون تركيب علاوات آمن، والتاركون يحميهم حارس المحرّك).
 *   (ب) جراحياً: أي (موظف معدّ، سنة) مخزّنه لا يطابق علاوات ملفه — للحالات القابلة
 *       للمطابقة الدقيقة (مبلغ ل.ل لكل السنة) — يعاد حسابه أيضاً.
 * علامة settings تمنع التكرار؛ والعملية idempotent فلو انقطعت منتصفها تكمل بالفتحة التالية.
 */
function healStaleYearMirror20260806() {
    $flag = 'stale_year_recalc_2026_08_06';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        if (function_exists('set_time_limit')) @set_time_limit(300);
        $cur = currentSchoolYear();
        // (أ) السنين المفتوحة المستقبلية
        $q = $db->prepare("SELECT DISTINCT ms.employee_id, ms.school_year FROM monthly_salaries ms
                           JOIN employees e ON e.id = ms.employee_id
                           WHERE e.is_deleted = 0 AND ms.school_year > ?");
        $q->execute([$cur]);
        $nA = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            try { recalcEmployeeYear((int)$r['employee_id'], $r['school_year']); $nA++; } catch (Exception $e2) {}
        }
        // (ب) جراحي: المعدّون الذين مخزّن سنةٍ ما عندهم لا يطابق علاوات ملفهم (المقارنة الدقيقة فقط)
        $mm = $db->query("SELECT ms.employee_id, ms.school_year FROM monthly_salaries ms
            JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND COALESCE(ms.is_indemnity_month, 0) = 0
              AND (e.employee_type = 'enseignant_titulaire' OR e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
              AND NOT EXISTS (SELECT 1 FROM employee_bonuses b2 WHERE b2.employee_id = e.id AND b2.school_year = ms.school_year AND b2.is_active = 1
                  AND b2.bonus_type IN ('prime_fixe','aide_complementaire')
                  AND (b2.value_type <> 'amount' OR b2.currency <> 'LBP' OR b2.start_month IS NOT NULL OR b2.end_month IS NOT NULL))
            GROUP BY ms.employee_id, ms.school_year
            HAVING SUM((ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp) <>
                (SELECT COALESCE(SUM(b.amount),0) FROM employee_bonuses b WHERE b.employee_id = ms.employee_id AND b.school_year = ms.school_year AND b.is_active = 1
                   AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND b.value_type = 'amount' AND b.currency = 'LBP'
                   AND b.start_month IS NULL AND b.end_month IS NULL)) > 0")->fetchAll(PDO::FETCH_ASSOC);
        $nB = 0;
        foreach ($mm as $r) {
            try { recalcEmployeeYear((int)$r['employee_id'], $r['school_year']); $nB++; } catch (Exception $e2) {}
        }
        setSetting($flag, date('Y-m-d H:i:s'));
        if ($nA || $nB) {
            logAudit('heal_stale_year_mirror', 'monthly_salaries', 0, null,
                     ['future_year_recalcs' => $nA, 'mismatch_recalcs' => $nB]);
        }
    } catch (Exception $e) { /* لا نُعطّل الصفحة — يُعاد بالفتحة التالية لأن العلامة لم تُكتب */ }
}

// 🩹 شفاء ذاتي (مرّة واحدة، 2026-07-29): السنة 2026-2027 فُتحت قبل آلية «نسخ العلاوات مع
// فتح السنة»، فطلعت رواتبها بلا الأجر الإضافي/المكافأة رغم وجودها في 2025-2026 (شكوى p1).
// عند أول فتح صفحة: ينسخ prime_fixe + aide_complementaire من السنة السابقة لكل موظف عنده
// رواتب 2026-2027 وما عنده هذه العلاوات فيها، ثم يعيد حساب أشهر سنته. علامة settings تمنع
// التكرار للأبد (فلا يُعيد إحياء علاوة حذفها المستخدم لاحقاً). لا يلمس النقل (موجود ولا يُدوبل).
/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-07-30): تصحيح «رقم المدرسة لدى صندوق التعويضات».
 * السبب: تعبئة ذاتية سابقة في official_forms.php كانت تطابق بالاسم `LIKE '%مكسيموس%'`
 * فكتبت رقم مدرسة القديس مكسيموس (75210) على «مركز البطريرك مكسيموس الخامس حكيم» أيضاً
 * (٣ مؤسسات) — فكان البيان الفصلي يُطبَع برقم مؤسسة أخرى. نُفرغ الرقم عن كل مؤسسة
 * ليست المدرسة نفسها، فيُدخله المستخدم يدوياً لكل مدرسة من صفحة المدارس.
 * آمن: لا يمسّ إلا القيمة 75210 المكتوبة آلياً، ولا يُلغي أي رقم أدخله المستخدم لمدرسة أخرى.
 */
function healCaisseNumbers() {
    $flag = 'caisse_number_like_fix_2026_07_30';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        // المدرسة المقصودة فعلاً بالرقم: اسمها يبدأ بـ«مدرسة» (لا «مركز»/«دير»/«دار»)
        $db->exec("UPDATE schools SET caisse_number = NULL
                    WHERE caisse_number = '75210'
                      AND name_ar NOT LIKE 'مدرسة%'");
        setSetting($flag, date('Y-m-d H:i:s'));
    } catch (Exception $e) { /* لا نُعطّل الصفحة */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-07-31): حذف نهائي لمدرستَي «ثانوية السيدة - مغدوشة»
 * و«ليسيه سان نيقولا» بكل بياناتهما (موظفون/رواتب/علاوات/تاريخ درجات/طلبات فورم)
 * بطلب صريح من المستخدم — لم تعودا موجودتين في البرنامج.
 * المطابقة بالاسم لا بالرقم (تحسّباً لاختلاف الأرقام بين المحلي والأونلاين).
 * قبل الحذف تُحفظ نسخة استرجاع INSERTs في backups/ (كما تفعل صفحة purge_schools).
 */
function healPurgeClosedSchools20260731() {
    $flag = 'purged_maghdouche_nicolas_2026_07_31';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        $ids = $db->query("SELECT id FROM schools
                            WHERE name_ar LIKE '%مغدوشة%' OR name_fr LIKE '%Maghdouch%'
                               OR name_ar LIKE '%نيقولا%' OR name_fr LIKE '%Nicolas%'")
                  ->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', $ids);
        if (!$ids) { setSetting($flag, date('Y-m-d H:i') . ' (غير موجودتين)'); return; }
        @set_time_limit(600);
        $in = implode(',', $ids);
        $empIds = $db->query("SELECT id FROM employees WHERE school_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $empIn = $empIds ? implode(',', array_map('intval', $empIds)) : '0';

        // (1) نسخة استرجاع لكل الصفوف قبل الحذف
        $dumpRows = function ($table, $rows) use ($db) {
            if (!$rows) return "-- $table: 0\n";
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $sql = "-- $table (" . count($rows) . ")\n";
            foreach ($rows as $r) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($r));
                $sql .= "INSERT INTO `$table` ($cols) VALUES (" . implode(',', $vals) . ");\n";
            }
            return $sql . "\n";
        };
        $dump = "-- نسخة استرجاع (حذف مغدوشة + سان نيقولا) — " . date('Y-m-d H:i:s') . "\n-- المدارس: $in\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $dump .= $dumpRows('schools', $db->query("SELECT * FROM schools WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC));
        $dump .= $dumpRows('employees', $db->query("SELECT * FROM employees WHERE school_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC));
        $dump .= $dumpRows('monthly_salaries', $db->query("SELECT * FROM monthly_salaries WHERE school_id IN ($in) OR employee_id IN ($empIn)")->fetchAll(PDO::FETCH_ASSOC));
        $dump .= $dumpRows('employee_bonuses', $db->query("SELECT * FROM employee_bonuses WHERE employee_id IN ($empIn)")->fetchAll(PDO::FETCH_ASSOC));
        $dump .= $dumpRows('employee_grade_history', $db->query("SELECT * FROM employee_grade_history WHERE employee_id IN ($empIn)")->fetchAll(PDO::FETCH_ASSOC));
        try { $dump .= $dumpRows('info_submissions', $db->query("SELECT * FROM info_submissions WHERE school_id IN ($in) OR employee_id IN ($empIn)")->fetchAll(PDO::FETCH_ASSOC)); } catch (Exception $e) {}
        $dump .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        $dir = __DIR__ . '/../backups';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/purge_auto_' . date('Ymd_His') . '.sql', $dump);

        // (2) الحذف بترتيب آمن ضمن معاملة واحدة (نفس ترتيب purge_schools.php)
        $db->beginTransaction();
        $db->exec("DELETE FROM employee_bonuses WHERE employee_id IN ($empIn)");
        $db->exec("DELETE FROM employee_grade_history WHERE employee_id IN ($empIn)");
        $db->exec("DELETE FROM monthly_salaries WHERE school_id IN ($in) OR employee_id IN ($empIn)");
        try { $db->exec("DELETE FROM info_submissions WHERE school_id IN ($in) OR employee_id IN ($empIn)"); } catch (Exception $e) {}
        $db->exec("DELETE FROM employees WHERE school_id IN ($in)");
        $db->exec("UPDATE users SET school_id = NULL WHERE school_id IN ($in)");
        $db->exec("DELETE FROM schools WHERE id IN ($in)");
        $db->commit();
        if (function_exists('logAudit')) logAudit('purge', 'schools', 0, null, "auto-heal ids=$in");
        setSetting($flag, date('Y-m-d H:i') . " (ids={$in} — " . count($empIds) . " موظف)");
    } catch (Throwable $e) {
        try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $e2) {}
        /* لا نكسر الصفحة — يُعاد عند الفتح التالي */
    }
}

function healYearAdditions2627() {
    $flag = 'yr_additions_backfilled_2026-2027';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        $newSY = '2026-2027'; $prevSY = '2025-2026'; $y1 = 2026; $y2 = 2027;
        $emps = $db->prepare("SELECT DISTINCT e.* FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                              WHERE ms.school_year = ? AND e.is_deleted = 0");
        $emps->execute([$newSY]);
        $emps = $emps->fetchAll(PDO::FETCH_ASSOC);
        if (!$emps) { setSetting($flag, date('Y-m-d H:i') . ' (لا رواتب)'); return; }
        @set_time_limit(600);
        require_once __DIR__ . '/payroll_calculator.php';
        $n = 0;
        foreach ($emps as $emp) {
            $id = (int)$emp['id'];
            $has = $db->prepare("SELECT 1 FROM employee_bonuses WHERE employee_id=? AND school_year=? AND is_active=1
                                 AND bonus_type IN ('prime_fixe','aide_complementaire') LIMIT 1");
            $has->execute([$id, $newSY]);
            if ($has->fetchColumn()) continue; // عنده — خياره محفوظ
            $sel = $db->prepare("SELECT * FROM employee_bonuses WHERE employee_id=? AND school_year=? AND is_active=1
                                 AND bonus_type IN ('prime_fixe','aide_complementaire')");
            $sel->execute([$id, $prevSY]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue; // ما كان عنده السنة الماضية
            foreach ($rows as $b) {
                $db->prepare("INSERT INTO employee_bonuses (employee_id,bonus_type,period_number,school_year,amount,value_type,currency,start_month,end_month,is_active)
                              VALUES (?,?,?,?,?,?,?,?,?,1)")
                   ->execute([$id, $b['bonus_type'], $b['period_number'], $newSY, $b['amount'], $b['value_type'], $b['currency'], $b['start_month'], $b['end_month']]);
            }
            $months = ((int)$emp['payment_months_per_year'] === 10)
                ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
                : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
            foreach ($months as [$m, $y]) {
                try { (new PayrollCalculator($id, $m, $y))->calculateAndSave(); } catch (Exception $e) {}
            }
            $n++;
        }
        setSetting($flag, date('Y-m-d H:i') . " ($n موظف)");
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-07-31، طلب المستخدم p1 — مارغريتا بونصار):
 * درجات كانون الاستثنائية كانت تُدمج دغري بأساس الراتب بالكشوف لمن درجته كسرية (X.5)
 * بسبب early-return قديم بالمحرّك (نصف الدرجة = تدرّج 0) — أُزيل من payroll_calculator.
 * هنا نصلّح الصفوف المخزّنة: أساس الشهر = مجموع الشهر السابق، وقيمة الدرجة = الفرق.
 * المجموع (أساس + درجة) لا يتغيّر إطلاقاً — توزيع عرض فقط، فلا يتأثّر صافٍ/ضمان/ضريبة.
 * idempotent: بعد الإصلاح قيمة الدرجة ≠ 0 فلا يطابق الشرط ثانيةً.
 */
function healEchelonSplit20260731() {
    $flag = 'echelon_split_fix_2026_07_31';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        @set_time_limit(300);
        $n = $db->exec("UPDATE monthly_salaries ms
                        JOIN monthly_salaries p ON p.employee_id = ms.employee_id
                             AND p.school_year = ms.school_year
                             AND (p.year*12 + p.month) = (ms.year*12 + ms.month) - 1
                        JOIN employees e ON e.id = ms.employee_id
                             AND e.employee_type = 'enseignant_titulaire'
                        SET ms.base_salary_lbp   = p.base_plus_echelon_lbp,
                            ms.echelon_value_lbp = ms.base_plus_echelon_lbp - p.base_plus_echelon_lbp
                        WHERE ms.grade_at_month <> FLOOR(ms.grade_at_month)
                          AND ms.echelon_value_lbp = 0
                          AND FLOOR(ms.grade_at_month) > FLOOR(p.grade_at_month)
                          AND p.base_plus_echelon_lbp > 0
                          AND p.base_plus_echelon_lbp < ms.base_plus_echelon_lbp");
        setSetting($flag, date('Y-m-d H:i') . " ($n صفّ)");
    } catch (Throwable $e) { /* لا نكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-04 — سؤال المستخدم «فوت على كل أستاذ متعاقد وحطلو الإضافي؟»):
 * المنقولون من البرنامج القديم صافيهم صحيح لكن «الأجر الإضافي» غير مفروز بأعمدتهم
 * (مثلاً أساس 1م وصافٍ 42.55م). الفجوة = (الصافي+المحسومات) − (الأساس+الإضافات) هي
 * أجره الإضافي الحقيقي شهراً بشهر — نسجّلها تلقائياً علاوةَ «أجر إضافي» بملفه (سطر لكل
 * فترة متساوية القيمة) ثم يركّبها overlayStoredYearBonuses على الأعمدة **بلا تغيير
 * الصافي** (امتصاص الفجوة). من له علاوة إضافي/مكافأة مسجّلة أصلاً لا يُمسّ (كديانا
 * شرو المدخلة يدوياً). idempotent: بعد التسجيل ما عاد في مرشّحون.
 */
function healHiddenImportedExtras20260804() {
    $flag = 'hidden_extrawage_fix_2026_08_04';
    if (getSetting($flag, '') !== '') return;
    if (isViewer()) return; // حسابات «قراءة فقط» لا تكتب شيئاً
    try {
        $db = getDB();
        @set_time_limit(300);
        require_once __DIR__ . '/payroll_calculator.php';
        // كل (موظف منقول بلا أساس بالإعداد، سنة) عنده فجوة موجبة وليس له أي علاوة إضافي/مكافأة
        $cand = $db->query("SELECT ms.employee_id, ms.school_year FROM monthly_salaries ms
            JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
              AND COALESCE(e.base_salary_usd, 0) = 0 AND COALESCE(e.contract_salary_lbp, 0) = 0
              AND COALESCE(ms.is_indemnity_month, 0) = 0
              AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = ms.employee_id
                              AND (b.school_year = ms.school_year OR b.school_year IS NULL)
                              AND b.bonus_type IN ('prime_fixe','aide_complementaire'))
            GROUP BY ms.employee_id, ms.school_year
            HAVING MAX((ms.net_salary_lbp + ms.total_retenues_lbp) - (ms.base_plus_echelon_lbp + ms.extra_lbp + ms.prime_fixe_lbp + ms.aide_complementaire_lbp)) > 0")->fetchAll();
        $ins = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                             VALUES (?, 'prime_fixe', ?, ?, ?, 'amount', 'LBP', ?, ?, 1)");
        $gq = $db->prepare("SELECT month, (net_salary_lbp + total_retenues_lbp) - (base_plus_echelon_lbp + extra_lbp + prime_fixe_lbp + aide_complementaire_lbp) AS gap
                            FROM monthly_salaries WHERE employee_id = ? AND school_year = ? AND COALESCE(is_indemnity_month, 0) = 0");
        $nEmp = 0; $nRows = 0;
        foreach ($cand as $c) {
            $eid = (int)$c['employee_id']; $sy = $c['school_year'];
            $gq->execute([$eid, $sy]);
            $gapByM = [];
            foreach ($gq->fetchAll() as $g) $gapByM[(int)$g['month']] = (int)$g['gap'];
            // فترات متتالية بترتيب السنة الدراسية (10→9) متساوية الفجوة → سطر علاوة لكل فترة
            $runs = []; $cur = null;
            foreach ([10,11,12,1,2,3,4,5,6,7,8,9] as $m) {
                $g = $gapByM[$m] ?? 0;
                if ($g > 0 && $cur !== null && $g === $cur['gap']) { $cur['to'] = $m; continue; }
                if ($cur !== null) $runs[] = $cur;
                $cur = ($g > 0) ? ['from' => $m, 'to' => $m, 'gap' => $g] : null;
            }
            if ($cur !== null) $runs[] = $cur;
            if (!$runs) continue;
            // كل أشهره الموجودة بنفس الفجوة → سطر واحد «لكل السنة» (يغطي أي شهر يُستحدث لاحقاً)
            $allSame = (count($runs) === 1 && count(array_filter($gapByM, fn($x) => $x > 0)) === count($gapByM));
            $pn = 0;
            foreach ($runs as $r) {
                $pn++;
                $ins->execute([$eid, $pn, $sy, $r['gap'], $allSame ? null : $r['from'], $allSame ? null : $r['to']]);
                $nRows++;
            }
            overlayStoredYearBonuses($eid, $sy); // يملأ الأعمدة — الصافي لا يتغيّر (امتصاص الفجوة)
            $nEmp++;
        }
        setSetting($flag, date('Y-m-d H:i') . " ($nEmp موظفاً / $nRows سطراً)");
    } catch (Throwable $e) { /* لا نكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-04ب — p1 ديانا شرو بالتقارير): من كان له علاوة
 * إضافي/مكافأة مسجّلة **قبل نزول التصليح** (أدخلها المستخدم يدوياً والمحرّك حينها كان
 * يتجاهل المنقولين — فتخطّاه الشفاء الشامل احتراماً لإدخاله اليدوي) بقيت أعمدته فارغة
 * بالتقارير إلى أن تُفتح بطاقته السنوية. نمرّر «تركيب العلاوات» مرّة على كل منقول له
 * أي علاوة مسجّلة فتكتمل أعمدته فوراً بكل التقارير. idempotent (المطابق لا يتغيّر).
 */
function healOverlayImportedBonuses20260804b() {
    $flag = 'hidden_extrawage_fix_2026_08_04b';
    if (getSetting($flag, '') !== '') return;
    if (isViewer()) return; // حسابات «قراءة فقط» لا تكتب شيئاً
    try {
        $db = getDB();
        @set_time_limit(300);
        require_once __DIR__ . '/payroll_calculator.php';
        $cand = $db->query("SELECT DISTINCT ms.employee_id, ms.school_year FROM monthly_salaries ms
            JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
              AND COALESCE(e.base_salary_usd, 0) = 0 AND COALESCE(e.contract_salary_lbp, 0) = 0
              AND EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = ms.employee_id
                          AND (b.school_year = ms.school_year OR b.school_year IS NULL))")->fetchAll();
        $n = 0;
        foreach ($cand as $c) { $n += overlayStoredYearBonuses((int)$c['employee_id'], $c['school_year']); }
        setSetting($flag, date('Y-m-d H:i') . " (" . count($cand) . " موظف×سنة / $n شهراً معدَّلاً)");
    } catch (Throwable $e) { /* لا نكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-04ج — الفحص الشامل): 1,580 صفّاً منقولاً «صافي الدولار»
 * فيه صفر لم يُملأ عند النقل (بينما مستحق الدولار مملوء) فتعرض الشاشة المزدوجة $0.00 تحت
 * الصافي ويختلف مجموع البطاقة عن أشهرها. نملأه بقاعدة المحرّك نفسها (الصافي ÷ سعر الشهر).
 * 🔴 لا يُمسّ أي صف فيه دولار مخزّن غير صفري — رواتب «فريش دولار» المنقولة حقيقية وليست مرآة.
 */
function healNetUsdMirror20260804c() {
    $flag = 'net_usd_mirror_fix_2026_08_04c';
    if (getSetting($flag, '') !== '') return;
    if (isViewer()) return; // حسابات «قراءة فقط» لا تكتب شيئاً
    try {
        $db = getDB();
        @set_time_limit(300);
        $n = $db->exec("UPDATE monthly_salaries SET net_salary_usd = ROUND(net_salary_lbp / exchange_rate, 2)
                        WHERE net_salary_usd = 0 AND net_salary_lbp > 0 AND exchange_rate > 0");
        setSetting($flag, date('Y-m-d H:i') . " ($n صفّاً)");
    } catch (Throwable $e) { /* لا نكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 تركيب ذاتي (2026-08-06): عمود «تطبيق التنزيل العائلي» بملف الموظف —
 * خيار بيد المستخدم لكل موظف: يُطبَّق التنزيل العائلي على ضريبته أو لا (الافتراضي: يُطبَّق،
 * كما كان البرنامج دائماً). يُركَّب ذاتياً بلا أي خطوة يدوية (منهج DB self-install).
 */
/**
 * 🏛️ تركيب ذاتي (2026-08-21 «وين معلومات الزوج/الزوجة» بنموذج ر3): أعمدة معلومات
 * الزوج/الزوجة بملف الموظف — تُعبَّأ من شاشة نموذج ر3 وتُحفَظ بالملف فتُستعمل بكل طبعة.
 */
function ensureSpouseColumns20260821() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $cols = [
            'spouse_full_name' => 'VARCHAR(120) NULL', 'spouse_maiden_name' => 'VARCHAR(120) NULL',
            'spouse_father_name' => 'VARCHAR(120) NULL', 'spouse_mother_name' => 'VARCHAR(120) NULL',
            'spouse_nationality' => 'VARCHAR(60) NULL', 'spouse_birth_place' => 'VARCHAR(120) NULL',
            'spouse_birth_date' => 'DATE NULL', 'spouse_id_card' => 'VARCHAR(60) NULL',
            'spouse_mof_number' => 'VARCHAR(30) NULL', 'spouse_employer_name' => 'VARCHAR(160) NULL',
            'spouse_employer_mof' => 'VARCHAR(30) NULL', 'spouse_employer_public' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($cols as $c => $def) {
            if (!$db->query("SHOW COLUMNS FROM employees LIKE '$c'")->fetch()) {
                $db->exec("ALTER TABLE employees ADD COLUMN `$c` $def");
            }
        }
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🧑 خانة الجنس بملف الموظف («المعلومات بدها تتعبى من ملف الموظف تلقائياً» — 2026-08-22):
 * عمود gender (m/f) يتركّب ذاتياً، ويُعبَّأ **مرة واحدة تلقائياً من الاسم الأول** للأسماء
 * اللبنانية المعروفة (لوائح ذكور/إناث عربي وفرنسي + «الاخت/السيدة» أنثى و«الاب/الخوري» ذكر).
 * لا يمسّ إلا الفارغ (NULL) — ما حُدِّد يدوياً بملف الموظف أو من شاشة نموذج يبقى.
 */
function ensureGenderColumn20260822() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'gender'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN gender VARCHAR(1) NULL");
        }
        // ١) ألقاب دينية/اجتماعية ببداية الاسم — الأوثق
        $db->exec("UPDATE employees SET gender='f' WHERE gender IS NULL AND (
            first_name_ar LIKE 'الاخت%' OR first_name_ar LIKE 'الأخت%' OR first_name_ar LIKE 'لاخت%'
            OR first_name_ar LIKE 'السيدة%' OR first_name_ar LIKE 'الانسة%' OR first_name_ar LIKE 'الآنسة%'
            OR first_name_fr LIKE 'Soeur%' OR first_name_fr LIKE 'Sœur%' OR first_name_fr LIKE 'Sr %')");
        $db->exec("UPDATE employees SET gender='m' WHERE gender IS NULL AND (
            first_name_ar LIKE 'الاب %' OR first_name_ar LIKE 'الأب %' OR first_name_ar LIKE 'الخوري%'
            OR first_name_ar LIKE 'الاباتي%' OR first_name_ar LIKE 'الأباتي%'
            OR first_name_fr LIKE 'Père %' OR first_name_fr LIKE 'Pere %' OR first_name_fr LIKE 'Abbé%')");
        // ٢) لوائح الأسماء المعروفة (مطابقة تامة لأول كلمة من الاسم — لا تخمين لواحق)
        $femAr = ['تريز','تراز','تيريز','ماري','مريم','ريتا','ليلى','ماجدة','كارلا','انطوانيت','أنطوانيت','ريما','زاهية','ميراي','ميشلين','كوليت','ندى','عائدة','جانيت','دنيا','اسما','أسماء','اسماء','ايفا','إيفا','جورجات','دوللي','زينه','زينة','غاده','غادة','كارولين','لارا','لوسي','لينا','مادونا','مارينا','ماغي','جسي','اجني','أجني','منى','اوديل','أوديل','اغابي','أغابي','اوغستان','هالة','هاله','رنا','رانيا','ريم','سمر','سهى','سوزان','سيلفا','عبير','فاديا','فيفيان','كلوديا','جيزيل','جاكلين','دارين','ديانا','رلى','روز','روزيت','سابين','سالي','ساندرا','سماح','سميرة','سونيا','غريس','مايا','ميرنا','نانسي','ناتالي','نجوى','نجلا','نجاة','نادين','ناديا','نيكول','هدى','هيام','هيلدا','وفاء','يارا','يولا','ايمان','إيمان','برناديت','بولين','تانيا','جومانا','دلال','رندة','رنده','زينب','سلمى','سلوى','غنى','فاطمة','لور','ليال','ليان','ليندا','مارلين','مي','نهى','هبة','هبه','هناء','هند','وردة','الهام','إلهام','انجيل','أنجيل','ايفلين','إيفلين','امل','أمل','امال','آمال','كريستين','خريستين','كريستال','جويس','ميري','شادية','جوزفين','دوريس','بولا','باميلا','ساره','سارة','سينتيا','ميرا','ميليسا','نايلة','نايله','نورما','باتريسيا','برلا','بيرلا','جنفياف','جوان','جويل','نوره','نورا','رولا','سيلفانا','كاتيا','اميرة','أميرة','ماجي','جيسيكا','إلسي','السي','السى','تيا','اسمهان','أسمهان','إسمهان','ميليا','ميلا','إيلا','ايلا','لوريت','لوريتا','أرليت','ارليت','إيفون','ايفون','منال','ميرفت','جيهان','فيروز','غلاديس','فلورا','مارغو','مارغريت','مرغريت','أنجيلا','انجيلا','أنطوانات','رينه','رينيه','دانيلا','دانييلا','كارين','كارلين','سيلين','سليمة','هلا','رئيسة','بديعة','جميلة','لطيفة','نظيرة','وجيهة','فكتوريا','فيكتوريا','جورجينا','مارلا','ميليسيا','تريزيا','روزا','لورا','نورة','فيرونيك','فلورانس','روزي','تيريزا','مارتا','مرتا','جانين','فيرا','كارمن','كلارا','لوريس','ليليان','ماريان','ميشلين','نعمت','هيفا','رئيفة','رفقا','رندلى','ريتا ماريا','سناء','سوسن','عايدة','غيتا','فاتن','لودي','ليلي','مادلين','مانيا','ميساء','ميلاني','نجود','نهاد','نوال','هنادي','وداد','ياسمين','يمنى'];
        $malAr = ['اميل','إميل','روكز','زياد','كميل','جوزيف','جرجس','انطوان','أنطوان','مارون','سمير','ساسين','غابي','كابي','رجا','علاء','مرسال','ايلي','إيلي','الياس','إلياس','بولس','خليل','جورج','جان','جو','جوني','حبيب','حنا','خضر','داني','دانيال','ربيع','رمزي','روبير','روجيه','روي','ريمون','سامي','سايد','سعيد','سليم','شادي','شربل','صبحي','طانيوس','طوني','عادل','عبدو','فادي','فارس','فيليب','كرم','كريم','مارك','ماهر','مجد','محمد','مروان','ميشال','ناجي','نبيل','نجيب','نديم','نزيه','نقولا','هاني','وليد','يوسف','يعقوب','ابراهيم','إبراهيم','اسعد','أسعد','اكرم','أكرم','امين','أمين','انيس','أنيس','ايمن','أيمن','باسم','بشارة','بيار','جهاد','حسان','حسن','حسين','رامي','رشيد','ريشار','زاهي','عصام','عماد','غسان','فؤاد','فوزي','قيصر','كمال','مالك','منير','موسى','ميلاد','نعيم','وديع','بطرس','سركيس','جبران','راجي','سهيل','طلال','عفيف','فريد','لويس','منصور','نمر','وهيب','يوحنا','جيلبير','ادمون','أدمون','ادوار','أنطونيوس','انطونيوس','جواد','رودريك','شوقي','قزحيا','ضاهر','ديب','نخله','نخلة','واكيم','سمعان','عقل','عساف','فرنسوا','نبيه','منيف','رفيق','توفيق','بديع','حليم','سليمان','مصطفى','علي','عمر','احمد','أحمد','محمود','حسام','هيثم','وائل','جميل','جوزف','دومينيك','رياض','سيمون','عبدالله','غطاس','فايز','فضل','كلوفيس','مخايل','مطانيوس','منذر','نسيب','نصري','نوفل','يوحنا'];
        $femFr = ['Marie','Rita','Nada','Mireille','Micheline','Colette','Janette','Jeanette','Georgette','Dolly','Carla','Antoinette','Rima','Nicole','Josiane','Denise','Therese','Thérèse','Christiane','Christine','Yvonne','Laure','Nathalie','Nancy','Maya','Rana','Rania','Lina','Lara','Sandra','Sonia','Suzanne','Viviane','Jacqueline','Gisele','Gisèle','Claudia','Caroline','Madona','Madonna','Maguy','Mona','Leila','Layla','Magida','Aida','Hoda','Rola','Rima','Mirna','Nawal','Najat','Nadine','Nadia','Hilda','Wafaa','Yara','Yola','Pauline','Bernadette','Tania','Joumana','Dalal','Salma','Salwa','Linda','Marlene','May','Mayssa','Noura','Roula','Katia','Amira','Silvana','Jessica','Veronique','Florence','Carmen','Clara','Liliane','Madeleine','Yasmine','Nawal','Nohad','Sana','Sawsan','Vera','Janine','Marta','Pamela','Paula','Cynthia','Mira','Melissa','Nayla','Norma','Patricia','Perla','Genevieve','Joelle'];
        $malFr = ['Georges','George','Joseph','Antoine','Elie','Elias','Charbel','Tony','Toni','Michel','Marcel','Camille','Pierre','Paul','Boulos','Maroun','Samir','Selim','Salim','Fadi','Fady','Fares','Philippe','Walid','Youssef','Jean','John','Johnny','Habib','Hanna','Dany','Daniel','Rabih','Ramzi','Robert','Roger','Roy','Raymond','Sami','Said','Chadi','Sobhi','Tanios','Adel','Abdo','Karim','Marc','Maher','Marwan','Naji','Nabil','Najib','Nadim','Nazih','Nicolas','Hani','Jacques','Ibrahim','Assaad','Akram','Amine','Anis','Ayman','Bassem','Bechara','Jihad','Hassan','Hussein','Rami','Rachid','Richard','Zahi','Issam','Imad','Ghassan','Fouad','Fawzi','Kamal','Malek','Mounir','Moussa','Milad','Naim','Wadih','Boutros','Sarkis','Gebran','Raji','Souheil','Talal','Afif','Farid','Louis','Mansour','Nemer','Wahib','Emile','Gaby','Gabi','Ziad','Gilbert','Edmond','Edouard','Antonios','Rodrigue'];
        $inList = function (array $names) use ($db) { return implode(',', array_map([$db, 'quote'], $names)); };
        $db->exec("UPDATE employees SET gender='f' WHERE gender IS NULL AND SUBSTRING_INDEX(TRIM(first_name_ar),' ',1) IN (" . $inList($femAr) . ")");
        $db->exec("UPDATE employees SET gender='m' WHERE gender IS NULL AND SUBSTRING_INDEX(TRIM(first_name_ar),' ',1) IN (" . $inList($malAr) . ")");
        $db->exec("UPDATE employees SET gender='f' WHERE gender IS NULL AND SUBSTRING_INDEX(TRIM(first_name_fr),' ',1) IN (" . $inList($femFr) . ")");
        $db->exec("UPDATE employees SET gender='m' WHERE gender IS NULL AND SUBSTRING_INDEX(TRIM(first_name_fr),' ',1) IN (" . $inList($malFr) . ")");
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏛️ ملف تعريف المؤسسة لنماذج وزارة المالية (ر5/ر6/ر10 طبق الأصل — 2026-08-23):
 * عمود schools.mof_profile (JSON) يتركّب ذاتياً ويُزرَع تلقائياً لمدرسة القديس مكسيموس
 * من قيم ملفات المستخدم نفسها (عنوان المركز + الشخص المكلف بتبليغ البريد + الممثل والموقّع).
 * باقي المدارس تعبّئه مرة واحدة من صندوق «معلومات المؤسسة» على شاشة النموذج.
 */
function ensureMofProfile20260823() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        if (!$db->query("SHOW COLUMNS FROM schools LIKE 'mof_profile'")->fetch()) {
            $db->exec("ALTER TABLE schools ADD COLUMN mof_profile TEXT NULL");
        }
        // زرع سان مكسيم (رقمها المالي 2459823) بقيم ملفات المستخدم — مرة واحدة على الفارغ فقط
        $seed = [
            'gov' => 'جبل لبنان', 'caza' => 'المتن', 'town' => 'المنصورية', 'quarter' => '',
            'street' => 'البلاطة', 'cadastral' => 'المنصورية حي البلاطة', 'lot' => '', 'building' => 'المدرسة',
            'floor' => '', 'fax' => '04/531450', 'pob' => '', 'region' => '1825/1', 'email' => 'cm5h@hotmail.com',
            'trade_name' => 'مدرسة', 'rep_name' => 'كميل سعيد مرعي', 'rep_title' => 'مسؤول',
            'contact_name' => 'كميل سعيد مرعي', 'contact_reg' => '271629', 'contact_phone' => '03-888849', 'contact_fax' => '04-531450',
            'preparer_name' => 'كميل سعيد مرعي', 'preparer_reg' => '271629', 'preparer_phone' => '03-888849', 'preparer_fax' => '04-531450',
            'signer_name' => 'كميل مرعي', 'signer_title' => 'مسؤول',
        ];
        $db->prepare("UPDATE schools SET mof_profile=? WHERE (mof_profile IS NULL OR mof_profile='') AND REPLACE(COALESCE(finance_number,''),' ','')='2459823'")
           ->execute([json_encode($seed, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏛️ محرّك تصحيح أسماء المناطق حسب قوائم أكواد الوزارة (2026-08-23 «لازم انت تصححهن
 * متل ما كتبتهن الدولة» — البرنامج لكل المؤسسات فالتصحيح تلقائي عام لا يدوي):
 * مطابقة بتطبيع عربي (ة/ه، أإآ/ا، ى/ي، «ال»، المسافات) على mof_r567_geo.json.
 * 🔴 البلدة هي المرجع: إذا الوزارة حاطّتها بقضاء غير المخزَّن يتصحّح القضاء (والمحافظة) منها.
 * يُصلَح فقط ما له حل وحيد أكيد — الملتبس (أكثر من مرشّح أو ولا واحد) يبقى لقرار المستخدم.
 */
function r567GeoNorm($s) {
    $s = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ـ', ' ', '-'], ['ا', 'ا', 'ا', 'ي', 'ه', '', '', ''], trim((string)$s));
    return preg_replace('/^ال/u', '', $s);
}
/** يعيد [محافظة، قضاء، بلدة] بتهجئة الوزارة إن وُجد حل وحيد، وإلا null */
function r567GeoResolve($gv, $cz, $tw, array $geo) {
    $gv = trim((string)$gv); $cz = trim((string)$cz); $tw = trim((string)$tw);
    if ($tw === '') return null;
    static $idx = null;
    if ($idx === null || ($idx['sig'] ?? '') !== count($geo['towns'] ?? [])) {
        $idx = ['sig' => count($geo['towns'] ?? []), 'gov' => [], 'caza' => [], 'town' => [], 'cazaById' => []];
        foreach (($geo['govs'] ?? []) as $n => $id) $idx['gov'][r567GeoNorm($n)][] = ['name' => $n, 'id' => $id];
        foreach (($geo['cazas'] ?? []) as $c) { $idx['caza'][r567GeoNorm($c['name'])][] = $c; $idx['cazaById'][$c['id']] = $c; }
        foreach (($geo['towns'] ?? []) as $t) $idx['town'][r567GeoNorm($t['name'])][] = $t;
    }
    $govId = null;
    foreach ($idx['gov'][r567GeoNorm($gv)] ?? [] as $g) { $govId = $g['id']; break; }
    $cazaId = null;
    foreach ($idx['caza'][r567GeoNorm($cz)] ?? [] as $c) {
        if ($govId === null || $c['gov'] === $govId) { $cazaId = $c['id']; break; }
    }
    $cands = $idx['town'][r567GeoNorm($tw)] ?? [];
    if (!$cands) return null;
    // ١) ضمن القضاء المخزَّن → تصحيح تهجئة فقط
    $inCaza = $cazaId !== null ? array_values(array_filter($cands, fn($t) => $t['caza'] === $cazaId)) : [];
    // ٢) وإلا ضمن المحافظة (البلدة مرجع القضاء) ٣) وإلا الكل
    $inGov = $govId !== null ? array_values(array_filter($cands, fn($t) => ($idx['cazaById'][$t['caza']]['gov'] ?? -1) === $govId)) : [];
    $pick = count($inCaza) === 1 ? $inCaza[0] : (count($inGov) === 1 ? $inGov[0] : (count($cands) === 1 ? $cands[0] : null));
    if (!$pick) return null;
    $pc = $idx['cazaById'][$pick['caza']] ?? null;
    if (!$pc) return null;
    $pgName = array_search($pc['gov'], $geo['govs'] ?? [], true);
    if ($pgName === false) return null;
    $out = [$pgName, $pc['name'], $pick['name']];
    return ($out[0] === $gv && $out[1] === $cz && $out[2] === $tw) ? null : $out;
}
/** يمسح الموظفين ويصحّح ما له حل وحيد — يعيد لائحة التصحيحات المطبَّقة */
function r567GeoAutoFix($db, $whereSql = '1=1') {
    $geo = json_decode((string)@file_get_contents(__DIR__ . '/../assets/templates/mof_r567_geo.json'), true) ?: [];
    if (!$geo) return [];
    $applied = [];
    try {
        $rows = $db->query("SELECT id, first_name_ar, first_name_fr, last_name_ar, last_name_fr, gouvernorat, district, ville
            FROM employees WHERE is_deleted=0 AND COALESCE(ville,'') <> '' AND ($whereSql)")->fetchAll();
        $upd = $db->prepare("UPDATE employees SET gouvernorat=?, district=?, ville=? WHERE id=?");
        foreach ($rows as $r) {
            $fix = r567GeoResolve($r['gouvernorat'], $r['district'], $r['ville'], $geo);
            if (!$fix) continue;
            $upd->execute([$fix[0], $fix[1], $fix[2], (int)$r['id']]);
            $applied[] = [
                'name' => trim((($r['first_name_ar'] ?? '') ?: ($r['first_name_fr'] ?? '')) . ' ' . (($r['last_name_ar'] ?? '') ?: ($r['last_name_fr'] ?? ''))),
                'from' => trim($r['gouvernorat'] . ' / ' . $r['district'] . ' / ' . $r['ville']),
                'to'   => implode(' / ', $fix),
            ];
        }
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
    return $applied;
}
/** شفاء لمرة واحدة: تصحيح أسماء المناطق لكل المدارس حسب قوائم الوزارة */
function healR567GeoFix20260823() {
    try {
        if (getSetting('heal_r567_geo_20260823', '') !== '') return;
        $n = count(r567GeoAutoFix(getDB()));
        setSetting('heal_r567_geo_20260823', 'done:' . $n);
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}
/** شفاء لمرة واحدة: بلدة الين منصور 191 «Beyrouth» غلط — سجلّها الأصلي (نسخة حزيران) ديك المحدي/المتن،
 *  وخانة حيّها بملفها «ديك المحدي» تؤكّده (تصحيح 2026-08-24) */
/** ✍️ (2026-08-25) قانون أشهر تعويض النقل («من تشرين الأول لحزيران ضمناً يعني تسعة أشهر»):
 *  للأساتذة (ملاك ومتعاقدون) النقل يُدفع فقط ضمن نافذة الأشهر 10→6 (قابلة للتعديل من
 *  الإعدادات: transport_start_month/transport_end_month). الموظف الإداري يداوم الصيف
 *  فنقله كل السنة. القانون مؤرَّخ يسري من 2026-2027 — سنة 2025-2026 المدفوعة والمطابقة
 *  لسجلات المستخدم لا تُمسّ حتى عند إعادة الحساب. مصدر واحد للمنطق (المحرّك + الشفاء). */
function transportMonthActive(int $month, string $employeeType, string $schoolYear): bool {
    if ($employeeType === 'employe') return true;
    if (strcmp($schoolYear, (string)getSetting('transport_window_from_sy', '2026-2027')) < 0) return true;
    $start = max(1, min(12, (int)getSetting('transport_start_month', 10)));
    $end   = max(1, min(12, (int)getSetting('transport_end_month', 6)));
    return $start <= $end ? ($month >= $start && $month <= $end)
                          : ($month >= $start || $month <= $end);
}

/** شفاء ذاتي (2026-08-25): تصفير النقل بالصفوف المولّدة مسبقاً خارج نافذة أشهر النقل
 *  (أساتذة، من 2026-2027 وطالع فقط) مع إنقاص المستحق ومرآة دولاره — 2025-2026 لا تُمسّ. */
function healTransportWindow20260825() {
    try {
        if (getSetting('heal_transport_window_20260825', '') !== '') return;
        $db = getDB();
        $rows = $db->query("SELECT ms.id, ms.month, ms.school_year, ms.transport_lbp, e.employee_type
                            FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id
                            WHERE ms.school_year >= '2026-2027' AND ms.transport_lbp > 0
                              AND e.employee_type IN ('enseignant_titulaire','enseignant_contractuel')")->fetchAll();
        $up = $db->prepare("UPDATE monthly_salaries SET transport_lbp=0, transport_complement_lbp=0,
                              total_due_lbp = total_due_lbp - :tr,
                              total_due_usd = CASE WHEN exchange_rate > 0 THEN ROUND(total_due_lbp / exchange_rate, 2) ELSE total_due_usd END
                            WHERE id = :id");
        $n = 0;
        foreach ($rows as $r) {
            if (transportMonthActive((int)$r['month'], (string)$r['employee_type'], (string)$r['school_year'])) continue;
            $up->execute(['tr' => (int)$r['transport_lbp'], 'id' => (int)$r['id']]);
            $n++;
        }
        setSetting('heal_transport_window_20260825', 'done:' . $n);
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

function healAlineVille20260824() {
    try {
        if (getSetting('heal_aline_ville_20260824', '') !== '') return;
        $db = getDB();
        $n = $db->prepare("UPDATE employees SET gouvernorat='جبل لبنان', district='المتن', ville='ديك المحدي'
            WHERE id=191 AND last_name_ar='منصور' AND ville='Beyrouth'");
        $n->execute();
        setSetting('heal_aline_ville_20260824', 'done:' . $n->rowCount());
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-26): استيراد أرقام صندوق التعويضات لأساتذة مدرسة
 * القديس مكسيموس من بيانه الرسمي «ارقام صندوق التعويضات مدرسة القديس مكسيموس.xlsx»
 * (بيان منظَّم وصُدّق بتاريخ 2025-02-10 — 18 أستاذاً ملاكاً). المطابقة بالاسم الثلاثي
 * داخل المدرسة لا بأرقام السجلات (تحسّباً لاختلافها بين المحلي والأونلاين)، والرقم
 * يُكتب فقط فوق خانة فاضية — أي رقم أدخله المستخدم يدوياً لا يُمسّ. ذو الملفين
 * (ملاك + متعاقد بنفس الاسم) يأخذ الرقم على الملفين لأنه رقم الشخص لدى الصندوق.
 */
function healCaisseImport20260826() {
    try {
        if (getSetting('heal_caisse_import_20260826', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة%مكسيموس%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return; // بلا علامة: يعاود المحاولة حين توجد المدرسة
        $list = [ // [الرقم المالي بالصندوق، الاسم، اسم الأب، الشهرة]
            ['102829', 'الين',     'شربل',   'منصور'],
            ['3938',   'اندره',    'يوسف',   'مراد'],
            ['102831', 'انطوان',   'ديب',    'الحاج'],
            ['127093', 'برلا',     'انطوان', 'نمور'],
            ['84261',  'دنيا',     'جرجس',   'القزي'],
            ['107605', 'دنيا',     'شاهين',  'فلفلي'],
            ['125907', 'رامونا',   'ريمون',  'الاسمر'],
            ['67994',  'ريتا',     'خليل',   'بو خليل'],
            ['73720',  'ريما',     'كرم',    'الكركي'],
            ['64538',  'زاهية',    'اميل',   'الحاج'],
            ['100675', 'شربل',    'انطوان', 'راجحه'],
            ['129320', 'شربل',    'نبيل',   'مرعي'],
            ['104637', 'غيتا',     'جريس',   'العلم'],
            ['107589', 'فادي',     'ريمون',  'المدور'],
            ['116303', 'كريستل',  'ميشال',  'الشختورة'],
            ['133698', 'مارسيلا', 'جورج',   'داود'],
            ['130696', 'مارغريتا', 'مارون',  'بونصار'],
            ['60727',  'نتالي',    'شربل',   'قنصل'],
        ];
        $find = $db->prepare("SELECT id, caisse_number FROM employees
            WHERE school_id=? AND is_deleted=0
              AND TRIM(first_name_ar)=? AND TRIM(father_name_ar)=? AND TRIM(last_name_ar)=?");
        $upd = $db->prepare("UPDATE employees SET caisse_number=? WHERE id=?");
        $set = 0; $kept = 0; $miss = [];
        foreach ($list as [$no, $fn, $fa, $ln]) {
            $find->execute([(int)$sid, $fn, $fa, $ln]);
            $rows = $find->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { $miss[] = "$fn $fa $ln"; continue; }
            foreach ($rows as $r) {
                if (trim((string)$r['caisse_number']) !== '') { $kept++; continue; }
                $upd->execute([$no, (int)$r['id']]);
                $set++;
            }
        }
        setSetting('heal_caisse_import_20260826',
            'done: set=' . $set . ' kept=' . $kept . ($miss ? ' miss=' . implode('؛', $miss) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/** تطبيع اسم للمطابقة على بيانات صندوق التعويضات: همزات/تاء مربوطة/ألف مقصورة موحّدة،
 *  بلا مسافات ولا تشكيل، جوزيف=جوزف، والحرف المكرر يُطوى (عطاالله=عطالله، ميريللا=ميريلا). */
function caisseNameNorm($s) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    $s = strtr($s, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي','ّ'=>'','ً'=>'','ٌ'=>'','ٍ'=>'','َ'=>'','ُ'=>'','ِ'=>'','ْ'=>'']);
    $s = str_replace(' ', '', $s);
    $s = str_replace('جوزيف', 'جوزف', $s);
    return preg_replace('/(.)\1+/u', '$1', $s);
}

/**
 * 🏦 محرّك استيراد أرقام صندوق التعويضات لمدرسة من بيانها الرسمي (عام لأي مدرسة):
 * $list = [[الرقم، «الاسم والشهرة» كما بالبيان، اسم الأب]]. المطابقة بالاسم داخل المدرسة:
 * مفتاح الاسم مطبَّع (مع/بدون «ال» بأول الشهرة + ابو=بو)، والأب مطبَّع؛ ملفات الأب
 * المجهول (./ز/فاضي) تُضم فقط إذا الاسم وحيد بالبيان (لا التباس بين شخصين متشابهين)،
 * وإن اختلف الأب إملائياً يُقبل المرشح الوحيد فقط عند وحدانية الاسم بالبيان والقاعدة.
 * الرقم يُكتب فقط فوق خانة فاضية. يعيد ['set','kept','miss'].
 */
function caisseImportForSchool($db, int $sid, array $list): array {
    $keys = function($first, $last) {
        $l1 = trim((string)$last);
        $l2 = preg_replace('/^ال/u', '', $l1);
        $out = [];
        foreach ([$l1, $l2] as $l) {
            $k = preg_replace('/ابو/u', 'بو', caisseNameNorm($first . ' ' . $l));
            $out[$k] = 1;
        }
        return array_keys($out);
    };
    $isPh = fn($f) => in_array(trim((string)$f), ['', '.', 'ز', '-'], true);
    $emps = $db->query("SELECT id, first_name_ar, father_name_ar, last_name_ar, caisse_number
        FROM employees WHERE school_id=" . (int)$sid . " AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC);
    $pdfNameCount = [];
    foreach ($list as [$no, $name, $father]) {
        $p = preg_split('/\s+/u', trim($name), 2);
        foreach ($keys($p[0], $p[1] ?? '') as $k) $pdfNameCount[$k] = ($pdfNameCount[$k] ?? 0) + 1;
    }
    $fills = []; $miss = [];
    foreach ($list as [$no, $name, $father]) {
        $p = preg_split('/\s+/u', trim($name), 2);
        $nameKeys = $keys($p[0], $p[1] ?? '');
        $fk = caisseNameNorm($father);
        $exact = []; $ph = []; $others = [];
        foreach ($emps as $e) {
            if (!array_intersect($nameKeys, $keys($e['first_name_ar'], $e['last_name_ar']))) continue;
            if (caisseNameNorm($e['father_name_ar']) === $fk) $exact[] = $e;
            elseif ($isPh($e['father_name_ar'])) $ph[] = $e;
            else $others[] = $e;
        }
        $unique = true;
        foreach ($nameKeys as $k) if (($pdfNameCount[$k] ?? 0) > 1) $unique = false;
        $take = $exact;
        if (!$take && $unique && count($others) === 1 && !$ph) $take = $others; // خطأ إملائي بالأب (رز/رزق)
        // احتياط الأب↔الشهرة المعكوسين (تيا «ديب» بنت نخلة مسجّلة تيا نخلة بنت ديب):
        // نجرب الاسم الأول + أب البيان كشهرة، وشهرة البيان أباً — فقط عند وحدانية الاسم
        if (!$take && !$ph && !$others && $unique && isset($p[1])) {
            $swapKeys = $keys($p[0], $father);
            $lk = caisseNameNorm($p[1]);
            foreach ($emps as $e) {
                if (!array_intersect($swapKeys, $keys($e['first_name_ar'], $e['last_name_ar']))) continue;
                if (caisseNameNorm($e['father_name_ar']) === $lk) $take[] = $e;
            }
            if (count($take) > 1) $take = []; // التباس ⇒ لا نكتب
        }
        if ($ph && $unique) { if ($take || !$others) foreach ($ph as $e) $take[] = $e; }
        if (!$take) { $miss[] = "$name ($father)"; continue; }
        foreach ($take as $e) {
            if (isset($fills[$e['id']]) && $fills[$e['id']] !== $no) { unset($fills[$e['id']]); continue; } // تضارب ⇒ لا نكتب
            $fills[$e['id']] = $no;
        }
    }
    $upd = $db->prepare("UPDATE employees SET caisse_number=? WHERE id=?");
    $set = 0; $kept = 0;
    $cur = []; foreach ($emps as $e) $cur[$e['id']] = trim((string)$e['caisse_number']);
    foreach ($fills as $eid => $no) {
        if (($cur[$eid] ?? '') !== '') { $kept++; continue; }
        $upd->execute([$no, (int)$eid]);
        $set++;
    }
    return ['set' => $set, 'kept' => $kept, 'miss' => $miss];
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-26): استيراد أرقام صندوق التعويضات لأساتذة «مدرسة
 * راهبات المخلصيات - ثانوية السيدة» (عبرا) من بيانها الرسمي PDF (بيان عام عن السنة
 * المدرسية 2025-2026 — 129 أستاذاً ملاكاً). «بس خود منه ارقام الصندوق للاساتذة» —
 * لا يُؤخذ من الملف إلا رقم الصندوق. المطابقة بمحرّك caisseImportForSchool أعلاه؛
 * جيسيكا الياس جبور (136125) وحدها بلا ملف بالقاعدة — رقمها ينتظر قرار المستخدم.
 */
function healCaisseImportAbra20260826() {
    try {
        if (getSetting('heal_caisse_import_abra_20260826', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة%ثانوية السيدة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return; // بلا علامة: يعاود المحاولة حين توجد المدرسة
        $list = [ // [رقم الصندوق، «الاسم والشهرة» كما بالبيان، اسم الأب]
            ['123865','السي منصور','متى'],['71827','السي موسى','سعيد'],['65971','الياس عطاالله','كميل'],
            ['113379','اماني متى','سعيد'],['127020','اميره القنواتي','سعدالدين'],['103110','اميلي صهيوني','سليم'],
            ['119604','انجي ابو ضاهر','نمر'],['130582','انجيلا فرنسيس','موسى'],['106246','اندي يونان','ايلي'],
            ['84239','اولمان ابو عزيز','طانيوس'],['130583','ايفا قسطنطين','جوزيف'],['136123','ايلان قزحيا','امين'],
            ['136124','ايلي ابراهيم','الياس'],['121666','ايلي غطاس','هاني'],['106245','ايليز الخوري','لبيب'],
            ['133684','ايليو نوفل','ايلي'],['130584','ايمي طنوس','رزق الله'],['29094','باسمة خليل','خليل'],
            ['111440','برلا ديب','حنا'],['69234','برنادات الاسمر','نمر'],['60155','برناديت ديب','فؤاد'],
            ['121667','برناديت مارتينوس','حبيب'],['111707','بسكال بو حرب','ميلو'],['106242','بولا دندن','ايلي'],
            ['113380','تامارا الطيار','ميشال'],['116137','تريزيا متري','ريمون'],['133685','جان حليحل','مارون'],
            ['29098','جنات رزق الله','مارون'],['136126','جنيفر الناشف','جوزيف'],['72748','جورج العموري','ميشال'],
            ['114565','جورج محفوظ','جميل'],['30689','جورجينا نقولا','حنا'],['80674','جوزف السروع','جرجي'],
            ['102402','جوزف حليحل','ميشال'],['72747','جوسلين عون','كريم'],['70189','جومانة البركة','سميح'],
            ['29097','جومانه طنوس','عبدالله'],['121669','جويل حليحل','الياس'],['62296','جيزيل القطار','يوسف'],
            ['136125','جيسيكا جبور','الياس'],['133688','جيمي الصغبيني','ابراهيم'],['133689','حنان حليحل','شربل'],
            ['130585','دارين الناشف','حنا'],['89008','دنيز انطون','حنا'],['29102','دنيز نقولا','طانيوس'],
            ['72749','ديانا نجم','انطوان'],['127890','راف الشباب','عصام'],['51588','راكيل يوسف','الياس'],
            ['119606','رانيه الحايك','جوزيف'],['116139','ربى الحايك','جوزيف'],['84238','رشا طنوس','كميل'],
            ['133690','روان خطار','الياس'],['19829','روبيكا الحايك','فادي'],['111438','روز مشنتف','ايلي'],
            ['90369','روزي صافي','ابراهيم'],['119444','ريتا ابو نادر','اسعد'],['108553','ريتا الحايك','ميشال'],
            ['130586','ريتا الحداد','شربل'],['39868','ريتا بركات','موريس'],['130587','ريتا حليحل','مارون'],
            ['101141','ريتا حليحل','يوسف'],['40683','ريتا فارس','الياس'],['136127','ريم ابو جريش','جورج'],
            ['119607','ريم عون','جرجي'],['103109','ريما الحاج','جرجس'],['82716','ريما نخله','نقولا'],
            ['65974','زياد ديب','عاطف'],['89025','سابين كساب','بيار'],['136128','سارة شربل','فؤاد'],
            ['133691','ساره السيقلي','بشارة'],['130588','ساره سباهيه','مارون'],['127021','ساره قسطنطين','زياد'],
            ['111020','سامر ابو نادر','مارون'],['121671','سلمى الزين','نزيه'],['107657','سهى جرجس','حبيب'],
            ['103111','سيده متى','ادمون'],['125905','شربل انطون','ابراهيم'],['2906','شنتال منصور','اميل'],
            ['90371','شيرين العاقوري','انطوان'],['106247','شيرين داغر','الياس'],['75592','صولنج الياس','جرجس'],
            ['106248','عايده انطون','جرجس'],['128915','غاييل غريب','فياض'],['116141','غريس بركات','اسعد'],
            ['121672','غريس عربيد المشنتف','جورج'],['113385','غوا الخوري','جورج'],['72610','فاتنة منصور','جورج'],
            ['101245','فادي المعماري','نقولا'],['51169','كريتا اسطفان','كميل'],['136129','كريس يونان','جورج'],
            ['51148','كريستيان طايع','حنا'],['116142','كريستين نحاس','هنري'],['130590','كريستينا حليحل','مارون'],
            ['29127','لبنى الحريري','محمد'],['127022','لبيب مشنتف','سمير'],['105546','لوري الناشف','حنا'],
            ['127023','لويزا المشنتف','عربيد'],['30704','ليلى السيقلي','نخلي'],['133692','ماريا حليحل','الياس'],
            ['133693','ماريا حليحل','سمير'],['130591','ماريان السيقلي','نقولا'],['113981','ماريانا نجم','نجم'],
            ['101142','مالده ايوب','نبيه'],['84748','مايا ابراهيم','حنا'],['47577','مرتا الخوري','فارس'],
            ['119612','مريام قسطنطين','ناصر'],['121673','مريانا المصري','عمر'],['106249','مريانا نصر','يوسف'],
            ['29134','مريانا نقولا','سامي'],['127024','مريم شهدان','حنا'],['89023','منال العاقوري','عماد'],
            ['72847','منى طنوس','ميشال'],['19838','مهى وازن','حسن'],['82717','ميرال فرنسيس','انطون'],
            ['65976','ميراي صوما','انطوان'],['90361','ميرنا ايوب','جورج'],['39341','ميرنا عبود','جورج'],
            ['75591','ميريلا صهيوني','عادل'],['71787','نادين فياض','كميل'],['121674','نبيه ياغي','نجيب'],
            ['84237','نسرين الياس','الياس'],['72751','نسرين طنوس','الياس'],['123868','نور طنوس','الياس'],
            ['136130','نيكولا صليبا','مروان'],['30712','هاني الديك','حبيب'],['58968','هبة الشاميه','مصطفى'],
            ['107658','هبة حنون','طانوس'],['136131','هلا عون','جرجي'],['107962','يولا ابراهيم','حنا'],
        ];
        $r = caisseImportForSchool($db, (int)$sid, $list);
        setSetting('heal_caisse_import_abra_20260826',
            'done: set=' . $r['set'] . ' kept=' . $r['kept'] . ($r['miss'] ? ' miss=' . implode('؛', $r['miss']) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-26 «شوف ابلح فرزل حدث جون»): استيراد أرقام صندوق
 * التعويضات لأربع مدارس من بياناتها الرسمية على الدسك توب — ابلح.xlsx (سيدة النياح،
 * 17)، فرزل.xlsx (سيدة الانتقال، 14)، حدث.xlsx (سيدة النجاة، 24)، جون.pdf (سيدة
 * البشارة، 19). لا يُؤخذ من الملفات إلا رقم الصندوق، والمطابقة بمحرّك
 * caisseImportForSchool (اسم المدرسة من ترويسة كل بيان لا من اسم الملف).
 */
function healCaisseImport4Schools20260826() {
    try {
        if (getSetting('heal_caisse_import_4schools_20260826', '') !== '') return;
        $db = getDB();
        $batches = [
            // سيدة النياح — ابلح (بيان بصيغة اسم/أب/شهرة بخانة واحدة)
            ['%سيدة النياح%', [
                ['36967','جوسلين عازار','يوسف'],['116145','جويل قيقانو','ريمون'],['13244','حنان الخوري','جورج'],
                ['13246','رلى حاتم','موريس'],['133697','رونزا فرج','ميشال'],['90365','سعيدي نصّار','ميشال'],
                ['13248','سمر ملحم','منجد'],['36969','سولا الشكر','يوسف'],['36968','سيدي نصراللّه','عسّاف'],
                ['107718','عبير اسطنبولي','سامي'],['23431','كارين ابوزيدان','صموئيل'],['53198','ليليان ربابي','غرامي'],
                ['30615','لينا الدرزي','عبداللّه'],['90366','مارغريتا بصيبص','ايلي'],['60429','مارلين ابوزغيب','شفيق'],
                ['13279','مارلين فزع','عبود'],['109580','نظلة بوزيدان','روكس'],
            ]],
            // سيدة الانتقال — الفرزل
            ['%سيدة الانتقال%', [
                ['14861','ايلين الياس','الياس'],['14864','تراز العجيل','سمير'],['107606','جلنار سيدي','فوزي'],
                ['133695','جيهان ناصر','احمد'],['106095','دولي شحاده','ناصيف'],['125364','رانيا زياده','الياس'],
                ['89019','رانيا سيدناوي','جورج'],['116160','فاديا مهنا','طانوس'],['83249','كارول جرجس','خليل'],
                ['116161','كريستيا نبهان','طوني'],['14882','منى زعرور','دياب'],['73660','مي خاطر','فهد'],
                ['133696','ميليسا مهنا','ميشال'],['14887','وفاء مهنا','توفيق'],
            ]],
            // سيدة النجاة — الحدث
            ['%سيدة النجاة%', [
                ['128953','اليانا العرجا','ريمون'],['89519','اليانا برباري','جاك'],['132229','انطوني جبور','جوزاف'],
                ['109077','تقلا حداد','سركيس'],['136120','جنى الخوري الفغالي','حنا'],['136121','جوانا الهبر','جهاد'],
                ['130695','جونا زوبا','فادي'],['133787','جويل الفغالي','ادكار'],['119455','خليل حسونه','جميل'],
                ['27947','دوللي كرم','اوجين'],['125906','رانيه سماك','يوسف'],['74561','رجا الحويك','جرجس'],
                ['74562','زينة قرعة','عزيز'],['35621','غاده بو نافع','الياس'],['119718','فاليسا خاطر','جورج'],
                ['64510','كارولين صادر','مخايل'],['136122','كريستينا جرجي','الياس'],['6116','لينا القارح','جاك'],
                ['55274','مادونا عازار','جوزيف'],['6121','مارينا غنيمة','ايوب'],['74563','مرسال منصور','مطانيوس'],
                ['28942','مريم ريشا','رشيد'],['111442','ميراي فاخوري','توفيق'],['125904','ميلي طنوس','انطوان'],
            ]],
            // سيدة البشارة — جون (اسم المدرسة يبدأ بـ«مدرسة» حصراً — لا دَيرا البشارة)
            ['مدرسة%سيدة البشارة%', [
                ['50809','ابتسام ابو ضاهر','نجيب'],['105954','اسمهان عيد','سيمون'],['119619','ايلي بولس','منوال'],
                ['133694','تيا ديب','نخلة'],['127025','جورجيت حبيب','انطوان'],['47802','رجاء عبد اللطيف','يوسف'],
                ['116157','روز اسكندر','جوزيف'],['37486','سمر الكبش','قاسم'],['60617','شيرا العاقوري','انطوان'],
                ['71814','عماد ديب','يوسف'],['90364','غادة باصيلا','الياس'],['136132','كارمن اسماعيل','سليم'],
                ['71812','كارول ناصيف','يوسف'],['89042','لارا نخلة','نخلة'],['834','ليلى سعد','احمد'],
                ['126068','مارون عساف','الياس'],['106181','ماريا اسعد','اديب'],['116158','ناتالي غسطين','طانوس'],
                ['50812','نهاية شمس الدين','سعيد'],
            ]],
        ];
        $log = [];
        foreach ($batches as [$like, $list]) {
            $sq = $db->prepare("SELECT id, name_ar FROM schools WHERE name_ar LIKE ? AND is_deleted=0 LIMIT 1");
            $sq->execute([$like]);
            $s = $sq->fetch(PDO::FETCH_ASSOC);
            if (!$s) { $log[] = "$like: المدرسة غير موجودة"; continue; }
            $r = caisseImportForSchool($db, (int)$s['id'], $list);
            $log[] = trim((string)$s['name_ar']) . ': set=' . $r['set'] . ' kept=' . $r['kept']
                   . ($r['miss'] ? ' miss=' . implode('؛', $r['miss']) : '');
        }
        setSetting('heal_caisse_import_4schools_20260826', 'done | ' . implode(' || ', $log));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * جدول كشفَي سيدة النجاة 2025-2026 (p1/p2) — المصدر الواحد لقيم الأشهر:
 * [الاسم، الشهرة، الفئة بالكشف، أساسي، سلفة، خاضع، ضريبة، ضمان، محسومات، صافي، نقل، مستحق، من، إلى، تنزيل عائلي؟]
 */
function najatSheet20260827Spec() {
    $C = 'enseignant_contractuel'; $E = 'employe';
    return [
        ['باميلا','نضّور',$C, 2000000,42000000,44000000, 130000,1320000,1450000,42550000,9000000,51550000,202510,202609,1],
        ['ديانا','شرو',$C, 1000000,43000000,44000000, 130000,1320000,1450000,42550000,9000000,51550000,202510,202609,1],
        ['رودي','مشعلاني',$C, 1000000,43000000,44000000, 130000,1320000,1450000,42550000,7200000,49750000,202510,202609,1],
        ['ريتا','طنوس',$C, 2000000,69000000,71000000, 740000,2130000,2870000,68130000,7200000,75330000,202510,202609,1],
        ['ريمي','باسط',$C, 2000000,42000000,44000000, 130000,1320000,1450000,42550000,9000000,51550000,202510,202609,1],
        ['شربل','الحاج عساف',$C, 2000000,78000000,80000000,1100000,2400000,3500000,76500000,9000000,85500000,202510,202609,1],
        ['شنتال','سعادة',$C, 2000000,66000000,68000000, 620000,2040000,2660000,65340000,7200000,72540000,202510,202609,1],
        ['علاء','شمعون',$C, 1000000,70000000,71000000, 740000,2130000,2870000,68130000,9000000,77130000,202510,202609,1],
        ['كارين','السكاف',$C, 2000000,51000000,53000000, 310000,1590000,1900000,51100000,9000000,60100000,202510,202609,1],
        ['كريستوف','شلهوب',$C, 1000000,88000000,89000000,1460000,2670000,4130000,84870000,5400000,90270000,202510,202606,1],
        ['كلود','كامل',$C, 2000000,69000000,71000000, 740000,2130000,2870000,68130000,9000000,77130000,202510,202609,1],
        ['كميل','مرعي',$C, 2000000,69000000,71000000,2240000,2130000,4370000,66630000,9000000,75630000,202510,202609,0],
        ['هيلاني','يعقوب',$C, 2000000,74000000,76000000, 940000,2280000,3220000,72780000,9000000,81780000,202510,202609,1],
        ['الياس','ابويونس',$E,30160000,0,30160000, 0, 904800, 904800,29255200,9000000,38255200,202510,202609,1],
        ['حنان','تحومي',$E,28000000,0,28000000, 0, 840000, 840000,27160000,9000000,36160000,202510,202609,1],
        ['رفيقة','حدشيتي',$E,28000000,0,28000000, 0, 840000, 840000,27160000,9000000,36160000,202510,202609,1],
        ['شيرين','بعقليني',$E,30000000,0,30000000, 0, 900000, 900000,29100000,9000000,38100000,202510,202609,1],
        ['كرستيان','عون',$E,28000000,0,28000000, 0, 840000, 840000,27160000,9000000,36160000,202510,202609,1],
        ['ليزا','فرنجية',$E,28000000,0,28000000, 0, 840000, 840000,27160000,9000000,36160000,202510,202609,1],
    ];
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27): رواتب وتعويض نقل «الخاضعين» بمدرسة سيدة النجاة
 * لسنة 2025-2026 طبق كشفَي البرنامج القديم اللذين أرسلهما المستخدم (p1: 13 أستاذاً متعاقداً
 * + p2: 6 موظفين — شهر تشرين الأول معمَّم على كل أشهر السنة):
 *  - كل شهر مخزَّن للأسماء الـ19 يُضبط على أرقام الكشف بالمليم (أساسي/سلفة غلاء = prime/
 *    خاضع/ضريبة/ضمان 3٪/محسومات/صافي/نقل/مستحق + حصص المدرسة 8٪ و6٪ و8.5٪ للموظفين
 *    + مرايا الدولار بسعر صرف الصف نفسه).
 *  - علاء شمعون وكلود كامل وكميل مرعي كانوا مصنَّفين «ملاك» هنا والكشف يصنّفهم متعاقدين
 *    (صندوق تعويضات = 0) ⇒ تُحوَّل فئتهم لمتعاقد، وكميل بلا تنزيل عائلي (ضريبته 2,240,000).
 *  - حنان تحومي: أشهر أيار-أيلول كانت «نقل بلا راتب» — بقرار المستخدم تُكمَّل كل السنة.
 *  - كريستوف شلهوب: 9 أشهر عمل (سلفته م10-6) + صف تموز «نقل فقط» غير مدفوع = يُحذف.
 *  - جيسيكا كنعان دخلت 1/11/2025 (تبقى بقرار المستخدم) وصف تشرين «نقل بلا راتب» يُحذف.
 *  - «طابق نفس الأسماء»: غير الخاضعين (ضمان وضريبة صفر بكل السنة) الذين ليسوا على الكشفين
 *    تُحذف أشهر 2025-2026 عندهم بقرار المستخدم الصريح («الغير خاضعين شيلهن») — الحذف
 *    محميّ بشرط «مجموع الضمان والضريبة = 0» فلا يصيب خاضعاً بالغلط.
 *  - كل صف يُعدَّل أو يُحذف يُنسخ أولاً إلى جدول الاسترجاع _ms_bk_najat20260827.
 * المطابقة بالاسم (الأول + الشهرة، مطبَّعة caisseNameNorm) داخل المدرسة لا بالـid
 * (الأرقام تختلف محلي/أونلاين)، وعند التعدد يُفضَّل صاحب صفوف السنة.
 */
function healNajatSheet20260827() {
    try {
        if (getSetting('heal_najat_sheet_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النجاة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return; // بلا فلاغ — يعاد عند توفر المدرسة
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_najat20260827 LIKE monthly_salaries");

        $emps = $db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid AND e.is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($emps as $e) $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        $resolve = function ($fn, $ln) use ($byName) {
            $c = $byName[caisseNameNorm($fn . ' ' . $ln)] ?? [];
            if (!$c) return null;
            usort($c, fn($a, $b) => (int)$b['yr_rows'] <=> (int)$a['yr_rows']);
            return $c[0];
        };

        $SHEET = najatSheet20260827Spec();
        $upd = $db->prepare("UPDATE monthly_salaries SET
                base_salary_lbp=?, echelon_value_lbp=0, base_plus_echelon_lbp=?,
                extra_lbp=0, prime_fixe_lbp=?, aide_complementaire_lbp=0, transport_complement_lbp=?,
                echelon_to_caisse_lbp=0, caisse_amount_lbp=0, eoc_grade_lbp=0,
                cnss_amount_lbp=?, taxable_base_lbp=?, income_tax_lbp=?, total_retenues_lbp=?,
                net_salary_lbp=?, family_allowance_lbp=0, transport_lbp=?, total_due_lbp=?,
                school_cnss_8_lbp=?, school_eoc_6_lbp=0, school_family_comp_6_lbp=?, school_end_of_service_8_5_lbp=?,
                net_salary_usd = IF(exchange_rate>0, ROUND(?/exchange_rate,2), net_salary_usd),
                total_due_usd  = IF(exchange_rate>0, ROUND(?/exchange_rate,2), total_due_usd)
            WHERE employee_id=? AND (year*100+month) BETWEEN ? AND ?");
        $fixed = 0; $miss = [];
        foreach ($SHEET as [$fn,$ln,$type,$base,$prime,$tb,$tax,$cnss,$ret,$net,$tr,$due,$m1,$m2,$afd]) {
            $e = $resolve($fn, $ln);
            if (!$e || (int)$e['yr_rows'] === 0) { $miss[] = "$fn $ln"; continue; }
            $id = (int)$e['id'];
            $db->exec("INSERT IGNORE INTO _ms_bk_najat20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            $isEmp = ($type === $E);
            $upd->execute([$base,$base,$prime,$tr,$cnss,$tb,$tax,$ret,$net,$tr,$due,
                (int)round($tb*0.08), $isEmp ? (int)round($tb*0.06) : 0, $isEmp ? (int)round($tb*0.085) : 0,
                $net,$due,$id,$m1,$m2]);
            // الفئة حسب الكشف (علاء/كلود/كميل كانوا «ملاك» هنا والكشف متعاقدون) + تنزيل كميل
            $db->exec("UPDATE employees SET employee_type='" . $type . "', apply_family_deduction=" . (int)$afd . " WHERE id=$id");
            // سلفة الغلاء بسجل العلاوات = رقم الكشف (لعرض الملف وأي «تركيب علاوات» لاحق)
            if (!$isEmp) {
                $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                    WHERE employee_id=? AND bonus_type='prime_fixe' AND school_year='2025-2026'")->execute([$prime, $id]);
            }
            $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                WHERE employee_id=? AND bonus_type='transport_complement' AND school_year='2025-2026'")->execute([$tr, $id]);
            $fixed++;
        }
        // صفَّا «نقل بلا راتب» غير مدفوعين: تموز كريستوف + تشرين جيسيكا كنعان (دخلت 1/11/2025)
        foreach ([['كريستوف','شلهوب',202607], ['جيسيكا','كنعان',202510]] as [$fn,$ln,$ym]) {
            $e = $resolve($fn, $ln);
            if (!$e) continue;
            $id = (int)$e['id']; $y = intdiv($ym,100); $m = $ym % 100;
            $db->exec("INSERT IGNORE INTO _ms_bk_najat20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND year=$y AND month=$m AND net_salary_lbp=0 AND is_paid=0");
            $db->exec("DELETE FROM monthly_salaries WHERE employee_id=$id AND year=$y AND month=$m AND net_salary_lbp=0 AND is_paid=0");
        }
        // «طابق نفس الأسماء» — غير الخاضعين الزائدين عن الكشفين (بأمره الصريح 2026-08-27)
        $REMOVE = [['الين','قاصوف'],['تاتيانا','فياض'],['جسي','سركيس'],['جورج','عون'],['سلوى','ابي صابر'],
                   ['مريم','ريشا'],['انطوان','بوسمعان'],['جوزيف','ابي عيد'],['روجيه','نادر'],['زياد','ايوب']];
        $removedRows = 0; $skipRem = [];
        // فهرس ثانٍ يشمل الملفات المحذوفة ناعماً (مريم ريشا/جوزيف ابي عيد حُذفا 2026-08-06
        // وصفوف رواتبهما 2025-2026 بقيت يتيمة — تُشال معهم لتطابق المجاميع الكشفين)
        $byNameAll = [];
        foreach ($db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byNameAll[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        }
        foreach ($REMOVE as [$fn,$ln]) {
            foreach ($byNameAll[caisseNameNorm($fn . ' ' . $ln)] ?? [] as $e) {
                if (!in_array($e['employee_type'], ['enseignant_contractuel','employe'], true)) continue; // الملاك محميّون
                if ((int)$e['yr_rows'] === 0) continue;
                $id = (int)$e['id'];
                $subj = $db->query("SELECT COALESCE(SUM(cnss_amount_lbp),0)+COALESCE(SUM(income_tax_lbp),0)
                    FROM monthly_salaries WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
                if ((float)$subj != 0.0) { $skipRem[] = "$fn $ln#$id"; continue; } // خاضع ⇒ ليس هدف المحي
                $db->exec("INSERT IGNORE INTO _ms_bk_najat20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $removedRows += (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            }
        }
        setSetting('heal_najat_sheet_20260827', 'done: fixed=' . $fixed . ' removedRows=' . $removedRows
            . ($miss ? ' miss=' . implode('؛', $miss) : '')
            . ($skipRem ? ' skipRem=' . implode('؛', $skipRem) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🔘 شفاء ذاتي مرّة واحدة (2026-08-27): «طفي» — بأمر المستخدم بعد مطابقة كشفَي النجاة:
 * مفتاح «تنزيل الأولاد بالضريبة» (grant_children_addition) يُطفأ عند ديانا شرو وكارين
 * السكاف (متعاقدتا سيدة النجاة) — كان مضوّى من قراءة إخراجات القيد بآب، وكشف 2025-2026
 * المدفوع يعطيهما تنزيل العازب فقط، فبقراره يبقى مطفأً للسنين الآتية أيضاً حتى يضويه هو.
 * (أولادهما المؤرَّخون بemployee_children باقون — التضوية لاحقاً تعيد كل شيء.)
 */
function healNajatGcaOff20260827() {
    try {
        if (getSetting('heal_najat_gca_off_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النجاة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $done = [];
        foreach ([['ديانا', 'شرو'], ['كارين', 'السكاف']] as [$fn, $ln]) {
            $st = $db->prepare("SELECT e.id,
                    (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                       AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) yr
                FROM employees e
                WHERE e.school_id = $sid AND e.is_deleted = 0 AND e.employee_type = 'enseignant_contractuel'
                  AND e.first_name_ar LIKE ? AND e.last_name_ar LIKE ?
                ORDER BY yr DESC LIMIT 1");
            $st->execute([$fn . '%', '%' . $ln . '%']);
            $id = (int)$st->fetchColumn();
            if (!$id) continue;
            $db->exec("UPDATE employees SET grant_children_addition = 0 WHERE id = $id");
            // أي سنين لاحقة مخزّنة تُعاد على المفتاح الجديد (2025-2026 المطابقة للكشف لا تتأثر
            // — أساسهما صفر بالإعداد فمسار إعادة الحساب يركّب العلاوات فقط ولا يمسّ الضريبة المخزّنة)
            try { recalcEmployeeYear($id, null); } catch (Throwable $ex) {}
            $done[] = "$fn $ln=$id";
        }
        setSetting('heal_najat_gca_off_20260827', 'done: ' . implode('؛', $done));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء تكميلي (2026-08-27 مساءً — «شوف بل p1 في عنا باميلا نضور وينها عندك»):
 * الشفاء الأول يعدّل الأشهر الموجودة فقط — وطلع أونلاين موظفون من كشفَي النجاة **بلا أي
 * شهر مخزَّن أصلاً** (باميلا نضّور: ملفها موجود لكن استيراد حزيران ما وصلها أونلاين).
 * هذا الشفاء يخلق الأشهر الناقصة لأي اسم من الكشفين بقيم الكشف نفسها (مدفوعة كسنته
 * المدفوعة)، بسعر صرف صف زميل بنفس الشهر، ويضمن فئته وعلاواته (سلفة/نقل) وعلم تنزيله.
 * آمن للإعادة: لا يلمس شهراً موجوداً.
 */
function healNajatSheetFill20260827() {
    try {
        if (getSetting('heal_najat_sheet_fill_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النجاة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $emps = $db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid AND e.is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($emps as $e) $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        $resolve = function ($fn, $ln) use ($byName) {
            $c = $byName[caisseNameNorm($fn . ' ' . $ln)] ?? [];
            if (!$c) return null;
            usort($c, fn($a, $b) => (int)$b['yr_rows'] <=> (int)$a['yr_rows']);
            return $c[0];
        };
        $ins = $db->prepare("INSERT INTO monthly_salaries (
                employee_id, school_id, month, year, school_year, grade_at_month,
                base_salary_lbp, echelon_value_lbp, base_plus_echelon_lbp,
                extra_lbp, prime_fixe_lbp, aide_complementaire_lbp, transport_complement_lbp,
                echelon_to_caisse_lbp, caisse_amount_lbp, eoc_grade_lbp, cnss_amount_lbp,
                taxable_base_lbp, income_tax_lbp, total_retenues_lbp,
                net_salary_lbp, family_allowance_lbp, transport_lbp, total_due_lbp,
                exchange_rate, net_salary_usd, total_due_usd,
                school_cnss_8_lbp, school_eoc_6_lbp, school_family_comp_6_lbp, school_end_of_service_8_5_lbp,
                is_calculated, is_paid, calculated_at
            ) VALUES (?,?,?,?, '2025-2026', 1, ?,0,?, 0,?,0,?, 0,0,0,?, ?,?,?, ?,0,?,?, ?,?,?, ?,0,?,?, 1,1,NOW())");
        $added = 0; $log = [];
        $allMonths = [[10,2025],[11,2025],[12,2025],[1,2026],[2,2026],[3,2026],[4,2026],[5,2026],[6,2026],[7,2026],[8,2026],[9,2026]];
        foreach (najatSheet20260827Spec() as [$fn,$ln,$type,$base,$prime,$tb,$tax,$cnss,$ret,$net,$tr,$due,$m1,$m2,$afd]) {
            $e = $resolve($fn, $ln);
            if (!$e) { $log[] = "غايب: $fn $ln"; continue; }
            $id = (int)$e['id'];
            $have = array_map('intval', $db->query("SELECT year*100+month FROM monthly_salaries
                WHERE employee_id=$id AND (year*100+month) BETWEEN $m1 AND $m2")->fetchAll(PDO::FETCH_COLUMN));
            $isEmp = ($type === 'employe');
            $addedThis = 0;
            foreach ($allMonths as [$m, $y]) {
                $ym = $y * 100 + $m;
                if ($ym < $m1 || $ym > $m2 || in_array($ym, $have, true)) continue;
                $rate = (float)$db->query("SELECT ms.exchange_rate FROM monthly_salaries ms
                    JOIN employees e2 ON e2.id = ms.employee_id
                    WHERE e2.school_id=$sid AND ms.year=$y AND ms.month=$m AND ms.exchange_rate>0 LIMIT 1")->fetchColumn();
                if ($rate <= 0) $rate = (float)getSetting('default_exchange_rate', 89500);
                $ins->execute([$id,$sid,$m,$y, $base,$base, $prime,$tr, $cnss, $tb,$tax,$ret, $net,$tr,$due,
                    $rate, round($net/$rate,2), round($due/$rate,2),
                    (int)round($tb*0.08), $isEmp ? (int)round($tb*0.06) : 0, $isEmp ? (int)round($tb*0.085) : 0]);
                $added++; $addedThis++;
            }
            if ($addedThis) {
                $log[] = "$fn $ln+$addedThis";
                // ملفه وعلاواته (كان قد فاته الشفاء الأول لغياب صفوفه)
                $db->exec("UPDATE employees SET employee_type='" . $type . "', apply_family_deduction=" . (int)$afd . " WHERE id=$id");
                foreach ([['prime_fixe', $prime, !$isEmp], ['transport_complement', $tr, true]] as [$bt, $amt, $want]) {
                    if (!$want || $amt <= 0) continue;
                    $c = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id=$id
                        AND bonus_type='$bt' AND school_year='2025-2026'")->fetchColumn();
                    if ($c) {
                        $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                            WHERE employee_id=? AND bonus_type=? AND school_year='2025-2026'")->execute([$amt, $id, $bt]);
                    } else {
                        $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year,
                            amount, value_type, currency, start_month, end_month, is_active)
                            VALUES (?,?,1,'2025-2026',?,'amount','LBP',NULL,NULL,1)")->execute([$id, $bt, $amt]);
                    }
                }
            }
        }
        setSetting('heal_najat_sheet_fill_20260827', 'done: added=' . $added . ($log ? ' | ' . implode('؛', $log) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 (نسخة ثانية مشخِّصة — 2026-08-27 مساءً): الشفاء التكميلي الأول اشتغل محلياً لكن
 * باميلا نضّور بقيت بلا أشهر أونلاين والخطأ ينبلع بالـcatch الصامت. هذه النسخة:
 * تعيد نفس منطق خلق الأشهر الناقصة + تلتقط أي خطأ (لكل شخص ولكل شهر) وتسجّله
 * نصاً في فلاغ الإعدادات ليُقرأ من بطاقة «حالة الشفاءات» بصفحة فحص الصحة،
 * + مطابقة احتياطية بالاسم الفرنسي إن غاب العربي.
 */
function healNajatSheetFill2_20260827() {
    try {
        if (getSetting('heal_najat_sheet_fill2_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النجاة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $err = []; $added = 0; $log = [];
        $emps = $db->query("SELECT e.id, e.first_name_ar, e.last_name_ar, e.first_name_fr, e.last_name_fr,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid AND e.is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($emps as $e) $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        $FR = ['باميلا نضّور' => ['pam', 'addour']]; // احتياط فرنسي لمن قد يختلف رسم اسمه العربي
        $allMonths = [[10,2025],[11,2025],[12,2025],[1,2026],[2,2026],[3,2026],[4,2026],[5,2026],[6,2026],[7,2026],[8,2026],[9,2026]];
        $ins = $db->prepare("INSERT INTO monthly_salaries (
                employee_id, school_id, month, year, school_year, grade_at_month,
                base_salary_lbp, echelon_value_lbp, base_plus_echelon_lbp,
                extra_lbp, prime_fixe_lbp, aide_complementaire_lbp, transport_complement_lbp,
                echelon_to_caisse_lbp, caisse_amount_lbp, eoc_grade_lbp, cnss_amount_lbp,
                taxable_base_lbp, income_tax_lbp, total_retenues_lbp,
                net_salary_lbp, family_allowance_lbp, transport_lbp, total_due_lbp,
                exchange_rate, net_salary_usd, total_due_usd,
                school_cnss_8_lbp, school_eoc_6_lbp, school_family_comp_6_lbp, school_end_of_service_8_5_lbp,
                is_calculated, is_paid, calculated_at
            ) VALUES (?,?,?,?, '2025-2026', 1, ?,0,?, 0,?,0,?, 0,0,0,?, ?,?,?, ?,0,?,?, ?,?,?, ?,0,?,?, 1,1,NOW())");
        foreach (najatSheet20260827Spec() as [$fn,$ln,$type,$base,$prime,$tb,$tax,$cnss,$ret,$net,$tr,$due,$m1,$m2,$afd]) {
            try {
                $c = $byName[caisseNameNorm($fn . ' ' . $ln)] ?? [];
                usort($c, fn($a, $b) => (int)$b['yr_rows'] <=> (int)$a['yr_rows']);
                $e = $c[0] ?? null;
                if (!$e && isset($FR[$fn . ' ' . $ln])) {
                    [$f1, $l1] = $FR[$fn . ' ' . $ln];
                    foreach ($emps as $cand) {
                        if (stripos((string)$cand['first_name_fr'], $f1) === 0 && stripos((string)$cand['last_name_fr'], $l1) !== false) { $e = $cand; break; }
                    }
                }
                if (!$e) { $err[] = "غايب: $fn $ln"; continue; }
                $id = (int)$e['id'];
                $have = array_map('intval', $db->query("SELECT year*100+month FROM monthly_salaries
                    WHERE employee_id=$id AND (year*100+month) BETWEEN $m1 AND $m2")->fetchAll(PDO::FETCH_COLUMN));
                $isEmp = ($type === 'employe');
                $addedThis = 0;
                foreach ($allMonths as [$m, $y]) {
                    $ym = $y * 100 + $m;
                    if ($ym < $m1 || $ym > $m2 || in_array($ym, $have, true)) continue;
                    try {
                        $rate = (float)$db->query("SELECT ms.exchange_rate FROM monthly_salaries ms
                            JOIN employees e2 ON e2.id = ms.employee_id
                            WHERE e2.school_id=$sid AND ms.year=$y AND ms.month=$m AND ms.exchange_rate>0 LIMIT 1")->fetchColumn();
                        if ($rate <= 0) $rate = (float)getSetting('default_exchange_rate', 89500);
                        $ins->execute([$id,$sid,$m,$y, $base,$base, $prime,$tr, $cnss, $tb,$tax,$ret, $net,$tr,$due,
                            $rate, round($net/$rate,2), round($due/$rate,2),
                            (int)round($tb*0.08), $isEmp ? (int)round($tb*0.06) : 0, $isEmp ? (int)round($tb*0.085) : 0]);
                        $added++; $addedThis++;
                    } catch (Throwable $exM) { $err[] = "$fn $ln $ym: " . mb_substr($exM->getMessage(), 0, 160); }
                }
                if ($addedThis) {
                    $log[] = "$fn $ln#$id+$addedThis";
                    $db->exec("UPDATE employees SET employee_type='" . $type . "', apply_family_deduction=" . (int)$afd . " WHERE id=$id");
                    foreach ([['prime_fixe', $prime, !$isEmp], ['transport_complement', $tr, true]] as [$bt, $amt, $want]) {
                        if (!$want || $amt <= 0) continue;
                        $cB = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id=$id
                            AND bonus_type='$bt' AND school_year='2025-2026'")->fetchColumn();
                        if ($cB) {
                            $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                                WHERE employee_id=? AND bonus_type=? AND school_year='2025-2026'")->execute([$amt, $id, $bt]);
                        } else {
                            $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year,
                                amount, value_type, currency, start_month, end_month, is_active)
                                VALUES (?,?,1,'2025-2026',?,'amount','LBP',NULL,NULL,1)")->execute([$id, $bt, $amt]);
                        }
                    }
                }
            } catch (Throwable $exP) { $err[] = "$fn $ln: " . mb_substr($exP->getMessage(), 0, 200); }
        }
        setSetting('heal_najat_sheet_fill2_20260827', 'done: added=' . $added
            . ($log ? ' | ' . implode('؛', $log) : '')
            . ($err ? ' | أخطاء: ' . implode(' ؛؛ ', $err) : ''));
    } catch (Throwable $e) {
        try { setSetting('heal_najat_sheet_fill2_err', mb_substr($e->getMessage(), 0, 400)); } catch (Throwable $e2) {}
    }
}

/**
 * 🧹 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): باميلا نضّور (النجاة) كانت مختفية من كل
 * الكشوف أونلاين رغم زرع أشهرها — السبب: ملفها الأونلاين فيه تواريخ ترك 2024-01-12
 * بالحقول الثلاثة، وهي **قبل تاريخ دخولها 2024-10-01** (خردة إدخال) وتناقض كشف
 * المستخدم الذي يثبت أنها تعمل كل 2025-2026. يمسح تواريخ الترك الأقدم من الدخول
 * (لأي موظف بهذه الحالة المستحيلة بالنجاة) فيعود الظهور بالكشوف.
 */
function healNajatPamelaLeft20260827() {
    try {
        if (getSetting('heal_najat_pamela_left_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النجاة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        // ترك أقدم من الدخول = حالة مستحيلة (خردة) — تُمسح الثلاثة معاً لمن كلُّ تواريخ تركه المسجّلة قبل دخوله
        $ids = $db->query("SELECT id FROM employees WHERE school_id=$sid AND is_deleted=0 AND hire_date IS NOT NULL
            AND COALESCE(left_date_cnss, left_date_finance, left_date_eoc) IS NOT NULL
            AND COALESCE(left_date_cnss, '9999-12-31') < hire_date
            AND COALESCE(left_date_finance, '9999-12-31') < hire_date
            AND COALESCE(left_date_eoc, '9999-12-31') < hire_date")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $db->exec("UPDATE employees SET left_date_cnss=NULL, left_date_finance=NULL, left_date_eoc=NULL WHERE id=" . (int)$id);
        }
        setSetting('heal_najat_pamela_left_20260827', 'done: cleared=' . count($ids) . ($ids ? ' (ids ' . implode('،', $ids) . ')' : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): تدقيق ملاك ثانوية السيدة-عبرا على كشفَي
 * البرنامج القديم (تشرين الأول 2025-2026، 131 ملاكاً خاضعاً — p1..p9) كشف 102/131 مطابقين
 * بالمليم، وبقرار المستخدم الصريح تُصلَّح حالتان فقط + تحويل فئة لاثنتين (الباقي بلا مسّ):
 *  - ريتا مارون حليحل: سلفة غلائها عندنا 138م = سلفة ريتا «يوسف» حليحل (تشابه أسماء
 *    بالاستيراد) — الصحيح من كشفه: سلفة 80م وصافي 73,710,954 (سطر 59).
 *  - ماريا الياس حليحل: سلفتها عندنا صفر (صافي 1,205,750!) — الصحيح: سلفة 53م وصافي
 *    49,160,000 (سطر 99). وهي وماريا سمير شخصان حقيقيان (كلتاهما على كشفه).
 *  - فيوليت جميل الحمصي وتريز جوزيف حبقوق: قال «هودي اساتذة تعاقد» ⇒ فئتهما تتحول
 *    enseignant_contractuel («حوّلهن متعاقدات هلق») — أرقامهما المخزّنة لا تُمسّ
 *    حتى يرسل كشف متعاقدي عبرا.
 * المطابقة بالاسم الثلاثي المطبَّع (ريتا/ماريا لكل منهما شبيهة اسم بالمدرسة!) والصفوف
 * المعدَّلة تُنسخ أولاً إلى جدول الاسترجاع _ms_bk_abra20260827.
 */
function healAbraFixes20260827() {
    try {
        if (getSetting('heal_abra_fixes_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة ثانوية السيدة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_abra20260827 LIKE monthly_salaries");
        $trio = [];
        foreach ($db->query("SELECT id, employee_type, first_name_ar, COALESCE(father_name_ar,'') fa, last_name_ar
                FROM employees WHERE school_id=$sid AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $trio[caisseNameNorm($e['first_name_ar'] . ' ' . $e['fa'] . ' ' . $e['last_name_ar'])][] = $e;
        }
        $one = function ($name) use ($trio) {
            $c = $trio[caisseNameNorm($name)] ?? [];
            return count($c) === 1 ? $c[0] : null; // الاسم الثلاثي يجب أن يكون وحيداً
        };
        // [الاسم الثلاثي => قيم كشفه: base, ech, prime, e2c/eocg, caisse, cnss, tb, tax, ret, net, tr, due, cn8, eoc6]
        $FIX = [
            'ريتا مارون حليحل'  => [2085000, 0,     80000000, 0,     4925100, 2462550, 77159900, 986396, 8374046, 73710954, 9000000, 82710954, 6566800, 4925100],
            'ماريا الياس حليحل' => [1325000, 50000, 53000000, 50000, 3262500, 1631250, 51062500, 271250, 5215000, 49160000, 9000000, 58160000, 4350000, 3262500],
        ];
        $done = [];
        $upd = $db->prepare("UPDATE monthly_salaries SET
                base_salary_lbp=?, echelon_value_lbp=?, base_plus_echelon_lbp=?,
                prime_fixe_lbp=?, transport_complement_lbp=?, echelon_to_caisse_lbp=?, eoc_grade_lbp=?,
                caisse_amount_lbp=?, cnss_amount_lbp=?, taxable_base_lbp=?, income_tax_lbp=?,
                total_retenues_lbp=?, net_salary_lbp=?, transport_lbp=?, total_due_lbp=?,
                school_cnss_8_lbp=?, school_eoc_6_lbp=?,
                net_salary_usd = IF(exchange_rate>0, ROUND(?/exchange_rate,2), net_salary_usd),
                total_due_usd  = IF(exchange_rate>0, ROUND(?/exchange_rate,2), total_due_usd)
            WHERE employee_id=? AND (year*100+month) BETWEEN 202510 AND 202609");
        foreach ($FIX as $name => [$base,$ech,$prime,$ded,$caisse,$cnss,$tb,$tax,$ret,$net,$tr,$due,$cn8,$eoc6]) {
            $e = $one($name);
            if (!$e) { $done[] = "غايب/ملتبس: $name"; continue; }
            $id = (int)$e['id'];
            $db->exec("INSERT IGNORE INTO _ms_bk_abra20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            $upd->execute([$base,$ech,$base+$ech, $prime,$tr,$ded,$ded, $caisse,$cnss,$tb,$tax, $ret,$net,$tr,$due, $cn8,$eoc6, $net,$due, $id]);
            // سلفة الغلاء بسجل العلاوات = رقم الكشف
            $c = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id=$id
                AND bonus_type='prime_fixe' AND school_year='2025-2026'")->fetchColumn();
            if ($c) {
                $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                    WHERE employee_id=? AND bonus_type='prime_fixe' AND school_year='2025-2026'")->execute([$prime, $id]);
            } else {
                $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year,
                    amount, value_type, currency, start_month, end_month, is_active)
                    VALUES (?,'prime_fixe',1,'2025-2026',?,'amount','LBP',NULL,NULL,1)")->execute([$id, $prime]);
            }
            $done[] = "$name=$id";
        }
        // فيوليت وتريز: «هودي اساتذة تعاقد» — تحويل الفئة فقط، الأرقام كما هي
        foreach (['فيوليت جميل الحمصي', 'تريز جوزيف حبقوق'] as $name) {
            $e = $one($name);
            if (!$e) { $done[] = "غايب/ملتبس: $name"; continue; }
            $db->exec("UPDATE employees SET employee_type='enseignant_contractuel' WHERE id=" . (int)$e['id']);
            $done[] = $name . '⇒متعاقد';
        }
        setSetting('heal_abra_fixes_20260827', 'done: ' . implode('؛', $done));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): متعاقدو وموظفو عبرا الخاضعون على كشفه
 * (a1..a4 — تشرين الأول 2025-2026: 24 متعاقداً + 28 موظفاً): كل الـ52 طلعوا مطابقين
 * بالصافي والنقل بالمليم. بقراره الصريح («صحح» + «شيل غير الخاضعين متل النجاة»):
 *  - فيوليت الحمصي وتريز حبقوق: تصحيح تقسيم الأساسي/السلفة فقط على كشفه (تريز
 *    2,600,000+100م · فيوليت 2,225,000+78م) — المجموع والصافي لا يتغيّران، والتعديل
 *    يصيب فقط الأشهر التي مجموعها = مجموع الكشف (صمّام أمان لاختلاف تقسيم بعض الأشهر).
 *  - حذف أشهر 2025-2026 لغير الخاضعين الـ11 الزائدين عن الكشف (ضمان+ضريبة=0 بكل
 *    السنة — شرط حماية لا يمسّ خاضعاً) مع نسخ للصفوف في _ms_bk_abra20260827.
 *  - تينا شربل ايوب (خاضعة، دخلت 1/11/2025) تبقى — ويُحذف صف تشرين الوهمي
 *    عندها (نقل بلا راتب، غير مدفوع) كنمط جيسيكا كنعان بالنجاة.
 */
function healAbraCw20260827() {
    try {
        if (getSetting('heal_abra_cw_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة ثانوية السيدة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_abra20260827 LIKE monthly_salaries");
        $byName = [];
        foreach ($db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        }
        $done = [];
        // (١) تقسيم فيوليت وتريز على الكشف — الأشهر التي مجموعها مطابق فقط
        $SPLIT = [
            'فيوليت الحمصي' => [2225000, 78000000],
            'تريز حبقوق'    => [2600000, 100000000],
        ];
        foreach ($SPLIT as $name => [$base, $prime]) {
            $cands = array_values(array_filter($byName[caisseNameNorm($name)] ?? [], fn($x) => (int)$x['yr_rows'] > 0));
            if (count($cands) !== 1) { $done[] = "تقسيم $name: غايب/ملتبس"; continue; }
            $id = (int)$cands[0]['id'];
            $db->exec("INSERT IGNORE INTO _ms_bk_abra20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            $st = $db->prepare("UPDATE monthly_salaries SET base_salary_lbp=?, echelon_value_lbp=0,
                    base_plus_echelon_lbp=?, prime_fixe_lbp=?, echelon_to_caisse_lbp=0, eoc_grade_lbp=0
                WHERE employee_id=? AND (year*100+month) BETWEEN 202510 AND 202609
                  AND (base_plus_echelon_lbp + prime_fixe_lbp) = ?");
            $st->execute([$base, $base, $prime, $id, $base + $prime]);
            $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                WHERE employee_id=? AND bonus_type='prime_fixe' AND school_year='2025-2026'")->execute([$prime, $id]);
            $done[] = "تقسيم $name=$id (أشهر " . $st->rowCount() . ")";
        }
        // (٢) غير الخاضعين الزائدون عن كشفه — بأمره «شيل غير الخاضعين متل النجاة»
        $REMOVE = [['بلال','اسعد'],['مرسال','سلوم'],['جورج','خلف'],['وليد','حليحل'],['جان','الياس داود'],
                   ['جوزيف','الاسمر'],['منى','حليحل'],['جوزيف','بولس'],['سهام','حليحل'],['جومانا','متى'],['ويلده','عيد']];
        $removed = 0; $skip = [];
        foreach ($REMOVE as [$fn, $ln]) {
            foreach ($byName[caisseNameNorm($fn . ' ' . $ln)] ?? [] as $e) {
                if (!in_array($e['employee_type'], ['enseignant_contractuel','employe'], true)) continue; // الملاك محميّون
                if ((int)$e['yr_rows'] === 0) continue;
                $id = (int)$e['id'];
                $subj = $db->query("SELECT COALESCE(SUM(cnss_amount_lbp),0)+COALESCE(SUM(income_tax_lbp),0)
                    FROM monthly_salaries WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
                if ((float)$subj != 0.0) { $skip[] = "$fn $ln#$id"; continue; } // خاضع ⇒ لا يُمسّ
                $db->exec("INSERT IGNORE INTO _ms_bk_abra20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $removed += (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            }
        }
        // (٣) صف تشرين الوهمي عند تينا ايوب (نقل بلا راتب، غير مدفوع) — تبقى هي من 1/11
        foreach ($byName[caisseNameNorm('تينا ايوب')] ?? [] as $e) {
            $id = (int)$e['id'];
            $db->exec("INSERT IGNORE INTO _ms_bk_abra20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND year=2025 AND month=10 AND net_salary_lbp=0 AND is_paid=0");
            $db->exec("DELETE FROM monthly_salaries WHERE employee_id=$id AND year=2025 AND month=10 AND net_salary_lbp=0 AND is_paid=0");
        }
        setSetting('heal_abra_cw_20260827', 'done: ' . implode('؛', $done) . ' | removedRows=' . $removed
            . ($skip ? ' skip=' . implode('؛', $skip) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🧹 (نسخة ثانية — 2026-08-27 مساءً): بلال علي اسعد وجوزيف بولس بولس (عبرا) صفوفهما
 * **أونلاين** «خاضعة» بداتا قديمة منحرفة عن المحلي (ضمان وضريبة وأرقام مختلفة) فحماهما
 * صمّام subject=0 في الشفاء الأول — لكنهما ليسا على كشف الخاضعين الرسمي (24 متعاقداً
 * الذي طابقناه بالمليم) والمستخدم أمر صراحة بشيل أسمائهما («شيل غير الخاضعين متل
 * النجاة») ⇒ حذف صفوف 2025-2026 لهذين الاسمين تحديداً بلا صمّام الخضوع، مع النسخ
 * إلى _ms_bk_abra20260827 — النطاق متعاقد/موظف حصراً (الملاك محميّون).
 */
function healAbraCw2_20260827() {
    try {
        if (getSetting('heal_abra_cw2_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة ثانوية السيدة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_abra20260827 LIKE monthly_salaries");
        $removed = 0; $who = [];
        foreach ([['بلال', 'اسعد'], ['جوزيف', 'بولس']] as [$fn, $ln]) {
            $st = $db->prepare("SELECT e.id FROM employees e
                WHERE e.school_id = $sid AND e.employee_type IN ('enseignant_contractuel','employe')
                  AND e.first_name_ar LIKE ? AND e.last_name_ar LIKE ?");
            $st->execute([$fn . '%', $ln . '%']);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (int)$id;
                $db->exec("INSERT IGNORE INTO _ms_bk_abra20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $n = (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                if ($n) { $removed += $n; $who[] = "$fn $ln#$id-$n"; }
            }
        }
        setSetting('heal_abra_cw2_20260827', 'done: removedRows=' . $removed . ($who ? ' (' . implode('؛', $who) . ')' : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): متعاقدو وموظفو سيدة البشارة (الشوف/جون)
 * الخاضعون على كشفه («p1 شوف وصحح» — تشرين الأول 2025-2026: 7 متعاقدين + 4 موظفين):
 * الـ11 كلهم مطابقون بالمليم (بمن فيهم زهير يونس) — التصحيح الوحيد بنمط النجاة وعبرا
 * الذي قرّره: حذف أشهر 2025-2026 لغير الخاضعين الزائدين عن الكشف (ضمان+ضريبة=0 بكل
 * السنة): تغريد غدار + ادي فرنسيس + جوسلين مرعي + عماد ديب (ملف محذوف ناعماً 2026-08-06
 * وصفوفه بقيت يتيمة — الفهرس يشمل المحذوفين، درس مريم ريشا). النسخ إلى
 * _ms_bk_bechara20260827، والملاك محميّون، والخاضع لا يُمسّ.
 */
function healBecharaCw20260827() {
    try {
        if (getSetting('heal_bechara_cw_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_bechara20260827 LIKE monthly_salaries");
        $byName = [];
        foreach ($db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        }
        $REMOVE = [['تغريد','غدار'], ['ادي','فرنسيس'], ['جوسلين','مرعي'], ['عماد','ديب']];
        $removed = 0; $skip = [];
        foreach ($REMOVE as [$fn, $ln]) {
            foreach ($byName[caisseNameNorm($fn . ' ' . $ln)] ?? [] as $e) {
                if (!in_array($e['employee_type'], ['enseignant_contractuel','employe'], true)) continue;
                if ((int)$e['yr_rows'] === 0) continue;
                $id = (int)$e['id'];
                $subj = $db->query("SELECT COALESCE(SUM(cnss_amount_lbp),0)+COALESCE(SUM(income_tax_lbp),0)
                    FROM monthly_salaries WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
                if ((float)$subj != 0.0) { $skip[] = "$fn $ln#$id"; continue; }
                $db->exec("INSERT IGNORE INTO _ms_bk_bechara20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $removed += (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            }
        }
        setSetting('heal_bechara_cw_20260827', 'done: removedRows=' . $removed
            . ($skip ? ' skip=' . implode('؛', $skip) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): متعاقدو وموظفو سيدة الانتقال (زحلة)
 * الخاضعون على كشفه («المتعاقد والموظف صحح» — تشرين الأول 2025-2026: 13 متعاقداً
 * + 4 موظفين، مجموع 762,850,410):
 *  - خمسة من متعاقدي الكشف كانوا عندنا «ملاك» خطأً: الماس جوزف فرح + سمير ملحم
 *    اعزان (بالكشف: اعران) + فاهمة جان سابا + لور جوزف مهنا + نوها فارس سيده ⇒
 *    تُحوَّل فئتهم متعاقداً وتُضبط كل أشهرهم بقيم الكشف المطلقة (سمير: ضريبته على
 *    تنزيله العائلي بالكشف 41,250,000 فصافيه 47,375,000 لا 47,300,000؛ والباقون
 *    كان فرقهم تقسيم الأساسي/السلفة فقط).
 *  - الباقون (8 متعاقدين + 4 موظفين، ومنهم دنيا جوزف فرح) مطابقون بالمليم — لا مسّ.
 *  - كلاريتا مساعد وبول نصرالله غير خاضعين (ضمان+ضريبة=0) وليسا على الكشف ⇒ تُحذف
 *    أشهرهما بنمط النجاة/عبرا/البشارة المقرَّر، مع النسخ إلى _ms_bk_entikal20260827.
 */
function healEntikalCw20260827() {
    try {
        if (getSetting('heal_entikal_cw_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة الانتقال%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_entikal20260827 LIKE monthly_salaries");
        $byName = [];
        foreach ($db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid AND e.is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        }
        $resolve = function (array $namePairs) use ($byName) {
            foreach ($namePairs as [$fn, $ln]) {
                $c = $byName[caisseNameNorm($fn . ' ' . $ln)] ?? [];
                usort($c, fn($a, $b) => (int)$b['yr_rows'] <=> (int)$a['yr_rows']);
                if ($c && (int)$c[0]['yr_rows'] > 0) return $c[0];
            }
            return null;
        };
        // [أسماء المطابقة (مع بدائل الرسم)، أساسي، سلفة، خاضع، ضريبة، ضمان، محسومات، صافي، نقل، مستحق]
        $FIX = [
            [[['الماس','فرح'],['الياس','فرح']],  2600000, 62000000, 64600000, 542000, 1938000, 2480000, 62120000, 0,       62120000],
            [[['سمير','اعزان'],['سمير','اعران']], 2000000, 47000000, 49000000, 155000, 1470000, 1625000, 47375000, 9000000, 56375000],
            [[['فاهمة','سابا']],                 1550000, 36000000, 37550000, 1000,   1126500, 1127500, 36422500, 9000000, 45422500],
            [[['لور','مهنا']],                   1875000, 44000000, 45875000, 167500, 1376250, 1543750, 44331250, 9000000, 53331250],
            [[['نوها','سيده'],['توها','سيده']],  1450000, 34000000, 35450000, 0,      1063500, 1063500, 34386500, 7200000, 41586500],
        ];
        $upd = $db->prepare("UPDATE monthly_salaries SET
                base_salary_lbp=?, echelon_value_lbp=0, base_plus_echelon_lbp=?,
                extra_lbp=0, prime_fixe_lbp=?, aide_complementaire_lbp=0, transport_complement_lbp=?,
                echelon_to_caisse_lbp=0, caisse_amount_lbp=0, eoc_grade_lbp=0,
                cnss_amount_lbp=?, taxable_base_lbp=?, income_tax_lbp=?, total_retenues_lbp=?,
                net_salary_lbp=?, family_allowance_lbp=0, transport_lbp=?, total_due_lbp=?,
                school_cnss_8_lbp=?, school_eoc_6_lbp=0, school_family_comp_6_lbp=0, school_end_of_service_8_5_lbp=0,
                net_salary_usd = IF(exchange_rate>0, ROUND(?/exchange_rate,2), net_salary_usd),
                total_due_usd  = IF(exchange_rate>0, ROUND(?/exchange_rate,2), total_due_usd)
            WHERE employee_id=? AND (year*100+month) BETWEEN 202510 AND 202609");
        $done = [];
        foreach ($FIX as [$pairs, $base, $prime, $tb, $tax, $cnss, $ret, $net, $tr, $due]) {
            $e = $resolve($pairs);
            if (!$e) { $done[] = 'غايب: ' . $pairs[0][0] . ' ' . $pairs[0][1]; continue; }
            $id = (int)$e['id'];
            $db->exec("INSERT IGNORE INTO _ms_bk_entikal20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            $upd->execute([$base, $base, $prime, $tr, $cnss, $tb, $tax, $ret, $net, $tr, $due,
                (int)round($tb * 0.08), $net, $due, $id]);
            $db->exec("UPDATE employees SET employee_type='enseignant_contractuel', apply_family_deduction=1 WHERE id=$id");
            // سلفة الغلاء والنقل بسجل العلاوات = أرقام الكشف
            foreach ([['prime_fixe', $prime], ['transport_complement', $tr]] as [$bt, $amt]) {
                if ($amt <= 0) {
                    $db->prepare("DELETE FROM employee_bonuses WHERE employee_id=? AND bonus_type=? AND school_year='2025-2026'")->execute([$id, $bt]);
                    continue;
                }
                $c = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id=$id
                    AND bonus_type='$bt' AND school_year='2025-2026'")->fetchColumn();
                if ($c) {
                    $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                        WHERE employee_id=? AND bonus_type=? AND school_year='2025-2026'")->execute([$amt, $id, $bt]);
                } else {
                    $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year,
                        amount, value_type, currency, start_month, end_month, is_active)
                        VALUES (?,?,1,'2025-2026',?,'amount','LBP',NULL,NULL,1)")->execute([$id, $bt, $amt]);
                }
            }
            $done[] = $pairs[0][0] . ' ' . $pairs[0][1] . '=' . $id;
        }
        // غير الخاضعين الزائدون عن الكشف — بالنمط المقرَّر
        $removed = 0; $skip = [];
        foreach ([['كلاريتا','مساعد'], ['بول','نصرالله'], ['بول','نصراللّه']] as [$fn, $ln]) {
            foreach ($byName[caisseNameNorm($fn . ' ' . $ln)] ?? [] as $e) {
                if (!in_array($e['employee_type'], ['enseignant_contractuel','employe'], true)) continue;
                if ((int)$e['yr_rows'] === 0) continue;
                $id = (int)$e['id'];
                $subj = $db->query("SELECT COALESCE(SUM(cnss_amount_lbp),0)+COALESCE(SUM(income_tax_lbp),0)
                    FROM monthly_salaries WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
                if ((float)$subj != 0.0) { $skip[] = "$fn $ln#$id"; continue; }
                $db->exec("INSERT IGNORE INTO _ms_bk_entikal20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $removed += (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            }
        }
        setSetting('heal_entikal_cw_20260827', 'done: ' . implode('؛', $done) . ' | removedRows=' . $removed
            . ($skip ? ' skip=' . implode('؛', $skip) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): متعاقدو وموظفو سيدة النياح (زحلة)
 * الخاضعون على كشفه («تصحيح المتعاقد والموظف» — تشرين الأول 2025-2026: 4 متعاقدين
 * + موظفة، مجموع 293,326,600):
 *  - بياره روكس بوزيدان (بالكشف: بيساره بوريدان) كانت «ملاك» خطأً وأرقامها الإجمالية
 *    مطابقة أصلاً ⇒ تحويل الفئة متعاقدةً + ضبط تقسيم الأساسي/السلفة على الكشف
 *    (1,820,000 + 57,000,000) وتصفير أعمدة الدرجة.
 *  - ران رزق + روزالي زيتو + سعدى سيده + فادي حاتم مطابقون بالمليم — لا مسّ.
 *  - الزائدون الخمسة غير الخاضعين (ضمان+ضريبة=0): سعادة الاترم + فيفيان بطرس +
 *    اسبر منصور + ندى باصيل + زويا سمعان ⇒ تُحذف أشهرهم بالنمط المقرَّر، مع النسخ
 *    إلى _ms_bk_niyah20260827. (فيفيان شهرتها «.» فتُطابَق بالاسم والأب.)
 */
function healNiyahCw20260827() {
    try {
        if (getSetting('heal_niyah_cw_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة النياح%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_niyah20260827 LIKE monthly_salaries");
        $byFL = []; $byFF = [];
        foreach ($db->query("SELECT e.id, e.employee_type, e.first_name_ar, COALESCE(e.father_name_ar,'') fa, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid AND e.is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byFL[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
            $byFF[caisseNameNorm($e['first_name_ar'] . ' ' . $e['fa'])][] = $e;
        }
        $pick = function (array $c) { usort($c, fn($a, $b) => (int)$b['yr_rows'] <=> (int)$a['yr_rows']); return $c[0] ?? null; };
        // (١) بياره ⇒ متعاقدة بقيم كشفه المطلقة
        $b = null;
        foreach ([['بياره','بوزيدان'], ['بيساره','بوريدان'], ['بيساره','بوزيدان'], ['بياره','بوريدان']] as [$fn, $ln]) {
            $c = array_filter($byFL[caisseNameNorm($fn . ' ' . $ln)] ?? [], fn($x) => (int)$x['yr_rows'] > 0);
            if ($c) { $b = $pick(array_values($c)); break; }
        }
        $done = [];
        if ($b) {
            $id = (int)$b['id'];
            $db->exec("INSERT IGNORE INTO _ms_bk_niyah20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            $db->prepare("UPDATE monthly_salaries SET
                    base_salary_lbp=1820000, echelon_value_lbp=0, base_plus_echelon_lbp=1820000,
                    extra_lbp=0, prime_fixe_lbp=57000000, aide_complementaire_lbp=0, transport_complement_lbp=7200000,
                    echelon_to_caisse_lbp=0, caisse_amount_lbp=0, eoc_grade_lbp=0,
                    cnss_amount_lbp=1764600, taxable_base_lbp=58820000, income_tax_lbp=426400, total_retenues_lbp=2191000,
                    net_salary_lbp=56629000, family_allowance_lbp=0, transport_lbp=7200000, total_due_lbp=63829000,
                    school_cnss_8_lbp=4705600, school_eoc_6_lbp=0, school_family_comp_6_lbp=0, school_end_of_service_8_5_lbp=0,
                    net_salary_usd = IF(exchange_rate>0, ROUND(56629000/exchange_rate,2), net_salary_usd),
                    total_due_usd  = IF(exchange_rate>0, ROUND(63829000/exchange_rate,2), total_due_usd)
                WHERE employee_id=? AND (year*100+month) BETWEEN 202510 AND 202609")->execute([$id]);
            $db->exec("UPDATE employees SET employee_type='enseignant_contractuel', apply_family_deduction=1 WHERE id=$id");
            foreach ([['prime_fixe', 57000000], ['transport_complement', 7200000]] as [$bt, $amt]) {
                $c = (int)$db->query("SELECT COUNT(*) FROM employee_bonuses WHERE employee_id=$id
                    AND bonus_type='$bt' AND school_year='2025-2026'")->fetchColumn();
                if ($c) {
                    $db->prepare("UPDATE employee_bonuses SET amount=?, value_type='amount', currency='LBP'
                        WHERE employee_id=? AND bonus_type=? AND school_year='2025-2026'")->execute([$amt, $id, $bt]);
                } else {
                    $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year,
                        amount, value_type, currency, start_month, end_month, is_active)
                        VALUES (?,?,1,'2025-2026',?,'amount','LBP',NULL,NULL,1)")->execute([$id, $bt, $amt]);
                }
            }
            $done[] = 'بياره=' . $id;
        } else { $done[] = 'غايبة: بياره بوزيدان'; }
        // (٢) الزائدون غير الخاضعون — بالنمط المقرَّر (فيفيان بالاسم+الأب لأن شهرتها «.»)
        $removed = 0; $skip = [];
        foreach ([['fl','سعادة','الاترم'], ['ff','فيفيان','بطرس'], ['fl','اسبر','منصور'], ['fl','ندى','باصيل'], ['fl','زويا','سمعان']] as [$mode, $fn, $ln]) {
            $pool = $mode === 'ff' ? ($byFF[caisseNameNorm($fn . ' ' . $ln)] ?? []) : ($byFL[caisseNameNorm($fn . ' ' . $ln)] ?? []);
            foreach ($pool as $e) {
                if (!in_array($e['employee_type'], ['enseignant_contractuel','employe'], true)) continue;
                if ((int)$e['yr_rows'] === 0) continue;
                $id = (int)$e['id'];
                $subj = $db->query("SELECT COALESCE(SUM(cnss_amount_lbp),0)+COALESCE(SUM(income_tax_lbp),0)
                    FROM monthly_salaries WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
                if ((float)$subj != 0.0) { $skip[] = "$fn $ln#$id"; continue; }
                $db->exec("INSERT IGNORE INTO _ms_bk_niyah20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $removed += (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            }
        }
        setSetting('heal_niyah_cw_20260827', 'done: ' . implode('؛', $done) . ' | removedRows=' . $removed
            . ($skip ? ' skip=' . implode('؛', $skip) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏫 شفاء ذاتي مرّة واحدة (2026-08-27 مساءً): موظفو دار السعادة-كسارة (زحلة) الخاضعون
 * على كشفه («شوف وصحح» — تشرين الأول 2025-2026: 8 موظفين، مجموع 301,319,000):
 * الثمانية كلهم مطابقون بالمليم (بمن فيهم Mado Tesema بالاسم اللاتيني) — التصحيح
 * الوحيد بالنمط المقرَّر: حذف أشهر 2025-2026 للزائدين غير الخاضعين الـ12 (ضمان+ضريبة=0
 * بكل السنة)، ومنهم ملفا جان عاد (1657/1794) والياس سمعان المحذوف ناعماً بصفوف يتيمة
 * (الفهرس يشمل المحذوفين). صمّام «الخاضع لا يُمسّ» يحمي الياس منير سمعان (على الكشف)
 * من تشابه الاسم. النسخ إلى _ms_bk_ksara20260827.
 */
function healKsaraCw20260827() {
    try {
        if (getSetting('heal_ksara_cw_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'دار السعادة للراهبات%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_ksara20260827 LIKE monthly_salaries");
        $byName = [];
        foreach ($db->query("SELECT e.id, e.employee_type, e.first_name_ar, e.last_name_ar,
                (SELECT COUNT(*) FROM monthly_salaries ms WHERE ms.employee_id = e.id
                   AND (ms.year*100+ms.month) BETWEEN 202510 AND 202609) AS yr_rows
            FROM employees e WHERE e.school_id = $sid")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byName[caisseNameNorm($e['first_name_ar'] . ' ' . $e['last_name_ar'])][] = $e;
        }
        $REMOVE = [['انطوانيت','الاثرم'], ['جان','عاد'], ['نورما','الحاج'], ['دنيز','قبلان'], ['هدية','المضاوي'],
                   ['لينا','برهوم'], ['الياس','سمعان'], ['مارغريتا','مومجيان'], ['جاكلين','حوشان'], ['عماد','الياس']];
        $removed = 0; $skip = [];
        foreach ($REMOVE as [$fn, $ln]) {
            foreach ($byName[caisseNameNorm($fn . ' ' . $ln)] ?? [] as $e) {
                if (!in_array($e['employee_type'], ['enseignant_contractuel','employe'], true)) continue;
                if ((int)$e['yr_rows'] === 0) continue;
                $id = (int)$e['id'];
                $subj = $db->query("SELECT COALESCE(SUM(cnss_amount_lbp),0)+COALESCE(SUM(income_tax_lbp),0)
                    FROM monthly_salaries WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609")->fetchColumn();
                if ((float)$subj != 0.0) { $skip[] = "$fn $ln#$id"; continue; } // خاضع ⇒ لا يُمسّ
                $db->exec("INSERT IGNORE INTO _ms_bk_ksara20260827 SELECT * FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
                $removed += (int)$db->exec("DELETE FROM monthly_salaries
                           WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            }
        }
        setSetting('heal_ksara_cw_20260827', 'done: removedRows=' . $removed
            . ($skip ? ' skip=' . implode('؛', $skip) : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🧹 (نسخة ثانية — 2026-08-27 ليلاً): كلاريتا مساعد (الانتقال-زحلة) صفوفها **أونلاين**
 * «خاضعة» بداتا قديمة منحرفة (ضمان 510,000) فحماها صمّام subject=0 في شفاء الانتقال —
 * لكنها ليست على كشف الخاضعين الرسمي والمستخدم أمر بالنمط ⇒ حذف صفوف 2025-2026
 * باسمها بلا صمّام (نمط بلال/جوزيف بولس بعبرا)، مع النسخ إلى _ms_bk_entikal20260827.
 */
function healEntikalCw2_20260827() {
    try {
        if (getSetting('heal_entikal_cw2_20260827', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة الانتقال%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $sid = (int)$sid;
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_entikal20260827 LIKE monthly_salaries");
        $removed = 0; $who = [];
        $st = $db->prepare("SELECT e.id FROM employees e
            WHERE e.school_id = $sid AND e.employee_type IN ('enseignant_contractuel','employe')
              AND e.first_name_ar LIKE 'كلاريتا%' AND e.last_name_ar LIKE 'مساعد%'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            $db->exec("INSERT IGNORE INTO _ms_bk_entikal20260827 SELECT * FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            $n = (int)$db->exec("DELETE FROM monthly_salaries
                       WHERE employee_id=$id AND (year*100+month) BETWEEN 202510 AND 202609");
            if ($n) { $removed += $n; $who[] = "كلاريتا#$id-$n"; }
        }
        setSetting('heal_entikal_cw2_20260827', 'done: removedRows=' . $removed . ($who ? ' (' . implode('؛', $who) . ')' : ''));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🏛️ تسوية الضمان السنوية طبق الأصل («تسوية الضمان 2025 القديس مكسيموس.xlsx» — 2026-08-26):
 * بيانات الجدول الملحق «الرواتب والاجور» + التصريح الاسمي (أ) الشهري، لسنة ميلادية
 * ولمجموعة مدارس تُختار بحرّية (خاصة ذوات رقم الضمان المشترك — تسوية واحدة للمؤسسة).
 * 🔴 مصدر واحد: الأجور تُشتقّ من الاشتراكات الشهرية المخزّنة (monthly_salaries) بالنسب
 * المؤرّخة — أساس المرض = (حصة المضمون + حصة المدرسة) ÷ النسبة الإجمالية (نمط 190A)،
 * أساس نهاية الخدمة = حصة 8.5٪ ÷ نسبتها (إداريون)، أساس العائلي = حصة 6٪ ÷ نسبتها.
 * ذو الملفين (نفس رقم الضمان/الاسم عبر المدارس المختارة) = شخص واحد بمجموع أجوره.
 */
function cnssTaswiyaData($db, int $fy, array $schoolIds): array {
    $schoolIds = array_values(array_filter(array_map('intval', $schoolIds)));
    if (!$schoolIds) return ['persons' => [], 'monthly' => [], 'totals' => []];
    $in = implode(',', $schoolIds);
    $q = $db->query("SELECT ms.employee_id eid, ms.month m,
            ms.cnss_amount_lbp cn, ms.school_cnss_8_lbp c8,
            ms.school_family_comp_6_lbp f6, ms.school_end_of_service_8_5_lbp e85,
            e.first_name_ar, e.father_name_ar, e.last_name_ar, e.nssf_number, e.birth_date,
            e.hire_date, e.left_date_cnss, e.employee_type
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND ms.year = " . (int)$fy . " AND ms.school_id IN ($in)
          AND (ms.cnss_amount_lbp + ms.school_cnss_8_lbp + ms.school_family_comp_6_lbp + ms.school_end_of_service_8_5_lbp) > 0
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0)");
    $persons = []; $monthly = [];
    for ($m = 1; $m <= 12; $m++) $monthly[$m] = ['fin' => 0, 'fam' => 0, 'mal' => 0];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int)$r['m'];
        $malFrac = cnssTotalFrac($m, $fy);
        $bmal = $malFrac > 0 ? (int)round(((float)$r['cn'] + (float)$r['c8']) / $malFrac) : 0;
        $ff = rateFrac('family_compensation_rate', $m, $fy, 6);
        $bfam = $ff > 0 ? (int)round((float)$r['f6'] / $ff) : 0;
        $ef = rateFrac('end_of_service_rate', $m, $fy, 8.5);
        $bfin = $ef > 0 ? (int)round((float)$r['e85'] / $ef) : 0;
        // 🔴 «مجموع الاجور السنوية لكل فرع ضمن الحد الاقصى المعتمد — وبيتغير خلال السنة»
        // (تنبيه المستخدم 2026-08-26): كل شهر يُسقَف بسقف فرعه الساري بتاريخه (جدول حدود
        // الضمان المؤرّخ) حتى لو كان المخزّن قد حُسب قبل إدخال السقف — فالمجموع السنوي
        // = مجموع الأشهر المسقوفة شهراً بشهر لا سقفاً واحداً ×12.
        $bmal = (int)clampCnssBase($bmal, 'maladie_maternite', $m, $fy);
        $bfam = (int)clampCnssBase($bfam, 'allocations_familiales', $m, $fy);
        $bfin = (int)clampCnssBase($bfin, 'fin_de_service', $m, $fy);
        $nssfDigits = preg_replace('/[^0-9]/', '', arabicDigitsFr($r['nssf_number'] ?? ''));
        if ($nssfDigits !== '' && !preg_match('/[1-9]/', $nssfDigits)) $nssfDigits = ''; // «0» الخردة ليست رقم ضمان
        $key = strlen($nssfDigits) >= 3 ? 'n' . $nssfDigits
             : 'x' . caisseNameNorm($r['first_name_ar'] . ' ' . $r['father_name_ar'] . ' ' . $r['last_name_ar']);
        if (!isset($persons[$key])) {
            $persons[$key] = ['nssf' => $nssfDigits, 'name' => trim($r['first_name_ar'] . ' ' . $r['father_name_ar'] . ' ' . $r['last_name_ar']),
                'birth' => ($r['birth_date'] ? (int)substr($r['birth_date'], 0, 4) : ''),
                'worker' => 0, 'hire' => $r['hire_date'], 'left' => null, 'monthsSet' => [],
                'N' => 0, 'O' => 0, 'Q' => 0];
        }
        $p = &$persons[$key];
        if ($r['employee_type'] === 'employe') $p['worker'] = 1;
        if ($r['hire_date'] && (!$p['hire'] || $r['hire_date'] < $p['hire'])) $p['hire'] = $r['hire_date'];
        if (($l = $r['left_date_cnss'] ?? null) && substr($l, 0, 4) === (string)$fy && (!$p['left'] || $l > $p['left'])) $p['left'] = $l;
        if ($bmal + $bfam + $bfin > 0) $p['monthsSet'][$m] = 1;
        $p['N'] += $bmal; $p['O'] += $bfin; $p['Q'] += $bfam;
        $monthly[$m]['mal'] += $bmal; $monthly[$m]['fam'] += $bfam; $monthly[$m]['fin'] += $bfin;
        unset($p);
    }
    // إتمام حقول كل شخص: تاريخ الترك (المسجَّل، وإلا آخر يوم بآخر شهر معمول إن لم يكمل السنة) + P = O×8.5٪
    foreach ($persons as &$p) {
        $months = array_keys($p['monthsSet']);
        sort($months);
        $p['months'] = count($months);
        $lastM = $months ? max($months) : 0;
        if (!$p['left'] && $lastM > 0 && $lastM < 12) {
            $p['left'] = sprintf('%04d-%02d-%02d', $fy, $lastM, (int)date('t', mktime(0, 0, 0, $lastM, 1, $fy)));
        }
        [$p['hy'], $p['hm'], $p['hd']] = $p['hire'] ? array_map('intval', explode('-', substr($p['hire'], 0, 10))) : ['', '', ''];
        [$p['ly'], $p['lm'], $p['ld']] = $p['left'] ? array_map('intval', explode('-', substr($p['left'], 0, 10))) : ['', '', ''];
        $p['P'] = (int)round($p['O'] * rateFrac('end_of_service_rate', 12, $fy, 8.5)); // النسبة مؤرّخة (نموذجه: P = O×8.5٪)
        $p['R'] = $p['N'];
        unset($p['monthsSet'], $p['hire'], $p['left']);
    }
    unset($p);
    // الترتيب برقم المضمون تصاعدياً (نمط ملفه)، ومن بلا رقم آخر اللائحة بالاسم
    uasort($persons, function ($a, $b) {
        if ($a['nssf'] !== '' && $b['nssf'] !== '') return (float)$a['nssf'] <=> (float)$b['nssf'];
        if ($a['nssf'] !== '') return -1;
        if ($b['nssf'] !== '') return 1;
        return strcmp($a['name'], $b['name']);
    });
    $persons = array_values($persons);
    $tot = ['count' => count($persons), 'workers' => 0, 'N' => 0, 'O' => 0, 'P' => 0, 'Q' => 0, 'R' => 0,
            'aFin' => 0, 'aFam' => 0, 'aMal' => 0];
    foreach ($persons as $p) {
        $tot['workers'] += $p['worker'];
        foreach (['N', 'O', 'P', 'Q', 'R'] as $k) $tot[$k] += $p[$k];
    }
    foreach ($monthly as $mm) { $tot['aFin'] += $mm['fin']; $tot['aFam'] += $mm['fam']; $tot['aMal'] += $mm['mal']; }
    return ['persons' => $persons, 'monthly' => $monthly, 'totals' => $tot];
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-26): السقفان التاريخيان لفرع التعويضات العائلية
 * من تسوية الضمان الرسمية 2025 لمكسيموس نفسها (حنا 6 أشهر × 12م = 72م، ودايخ
 * 6×12م + 6×18م = 180م): 12م حتى 2025-06-30 ثم 18م حتى ما قبل سقف المستخدم
 * الحالي (28م من 2026-05-01). لا يُزرَع إلا إذا ما في أي سقف عائلي يغطي 2025
 * (فلا يدهس إدخالاً يدوياً)، والفرعان بلا سقف (نهاية الخدمة/الصندوق) لا يُمسّان.
 */
function healCnssFamilyCeilings20260826() {
    try {
        if (getSetting('heal_cnss_family_ceilings_20260826', '') !== '') return;
        $db = getDB();
        $n = (int)$db->query("SELECT COUNT(*) FROM cnss_brackets WHERE branch='allocations_familiales'
            AND effective_from <= '2025-12-31' AND (effective_to IS NULL OR effective_to >= '2025-01-01')")->fetchColumn();
        if ($n === 0) {
            $ins = $db->prepare("INSERT INTO cnss_brackets (branch, max_salary_lbp, effective_from, effective_to, notes)
                VALUES ('allocations_familiales', ?, ?, ?, ?)");
            $ins->execute([12000000, '2025-01-01', '2025-06-30', 'السقف التاريخي — من تسوية الضمان الرسمية 2025 (زُرع تلقائياً 2026-08-26)']);
            $ins->execute([18000000, '2025-07-01', '2026-04-30', 'السقف التاريخي — من تسوية الضمان الرسمية 2025 (زُرع تلقائياً 2026-08-26)']);
            setSetting('heal_cnss_family_ceilings_20260826', 'done: seeded 12m+18m');
        } else {
            setSetting('heal_cnss_family_ceilings_20260826', 'skip: يوجد سقف عائلي يغطي 2025 (' . $n . ')');
        }
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/** مجموعات المدارس حسب رقم الضمان الموحّد (لخيارات «مع بعضها») — [key => [ids...]] */
function cnssSchoolGroups($db): array {
    $groups = [];
    foreach ($db->query("SELECT id, name_ar, nssf_employer_number FROM schools WHERE is_deleted=0 AND is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $k = cnssEmployerNumberKey($s['nssf_employer_number'] ?? '');
        $groups[$k !== '' ? $k : ('id' . $s['id'])][] = $s;
    }
    return $groups;
}

/** 🔢 «كل الارقام اكتبو بالفرنسي» (2026-08-26): تحويل الأرقام العربية/الفارسية إلى فرنسية */
function arabicDigitsFr($v) {
    return strtr((string)$v, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                              '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
}

/** 🔢 تطبيع خانة رقم رسمي (صندوق/ضمان/مالية): أرقام فرنسية حتماً، وإن حُشر كلام معها
 *  (متل «الرقم المالي: ١٢٥٩٠٤» بملف ميلي طنوس أونلاين) يُمسح الكلام وتبقى الأرقام وفواصلها. */
function officialNumberFr($v) {
    $v = trim(arabicDigitsFr($v));
    if ($v === '') return $v;
    if (preg_match('/[ء-ي]/u', $v)) $v = trim((string)preg_replace('/[^0-9\/\-]+/u', '', $v), '/- ');
    return trim($v);
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-26 «شوف كل الارقام اكتبو بالفرنسي» — p1 ميلي طنوس):
 * تنقية كل خانات الأرقام الرسمية المخزّنة (موظفون: صندوق/ضمان/مالية + مدارس: صندوق/
 * مالية/ضمان) بofficialNumberFr، والهواتف تُحوَّل أرقامها فقط (تبقى صيغتها 03/888849).
 * يعمل أونلاين بعد النشر حيث الداتا الملوّثة (محلياً نظيفة). الحفظ الجديد يتطبّع
 * تلقائياً من صفحتي الموظف والمدارس فلا تعود الحالة.
 */
function healFrenchDigits20260826() {
    try {
        if (getSetting('heal_french_digits_20260826', '') !== '') return;
        $db = getDB();
        $n = 0;
        $targets = [
            ['employees', 'id', ['caisse_number' => 'num', 'nssf_number' => 'num', 'finance_ministry_number' => 'num', 'phone1' => 'tel', 'phone2' => 'tel']],
            ['schools',   'id', ['caisse_number' => 'num', 'finance_number' => 'num', 'nssf_employer_number' => 'num', 'phone' => 'tel']],
        ];
        foreach ($targets as [$table, $pk, $cols]) {
            $rows = $db->query("SELECT `$pk`, `" . implode('`,`', array_keys($cols)) . "` FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                foreach ($cols as $c => $kind) {
                    $old = (string)($r[$c] ?? '');
                    if ($old === '') continue;
                    $new = ($kind === 'num') ? officialNumberFr($old) : trim(arabicDigitsFr($old));
                    if ($new !== $old) {
                        $db->prepare("UPDATE `$table` SET `$c`=? WHERE `$pk`=?")->execute([$new, $r[$pk]]);
                        $n++;
                    }
                }
            }
        }
        setSetting('heal_french_digits_20260826', 'done: fixed=' . $n);
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/** 🟰 التراكمي الضريبي لموظف من أول السنة الميلادية حتى شهر معيّن («انتبه ر5 كمان بدها
 *  تكون مجموع ر10 على أربع فصول» 2026-08-24): يعيد ['tb','fd','net'] — التنزيل العائلي
 *  التراكمي بسقف الخاضع التراكمي (fda بتاريخ 1/1 لكل السنة). قيمة الفصل = تراكمي آخر
 *  شهره − تراكمي ما قبله، فتجمع الفصول الأربعة إلى الحساب السنوي الإفرادي بالمليم حتماً
 *  وكل فصل يبقى محسوباً بمعلومات وقته فقط (لا يحتاج مستقبل السنة). */
function mofCumTax($db, array $e, int $y, int $mTo): array {
    $z = ['tb' => 0, 'fd' => 0, 'net' => 0];
    if ($mTo < 1) return $z;
    $q = $db->prepare("SELECT SUM(taxable_base_lbp) tb, COUNT(DISTINCT month) mcnt FROM monthly_salaries
        WHERE employee_id=? AND year=? AND month<=?
          AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0)");
    $q->execute([(int)$e['id'], $y, $mTo]);
    $r = $q->fetch() ?: [];
    if (!(int)($r['mcnt'] ?? 0)) return $z;
    $fda = familyDeductionAnnual($e['social_status'] ?? '', $e['spouse_works'] ?? 0,
        $e['apply_family_deduction'] ?? ($e['afd'] ?? 1), $y . '-01-01',
        $e['grant_spouse_addition'] ?? ($e['gsa'] ?? 0), $e['grant_children_addition'] ?? ($e['gca'] ?? 0), (int)$e['id']);
    $tb = (int)$r['tb'];
    $fd = (int)min($fda / 12 * min(12, (int)$r['mcnt']), (float)$tb);
    return ['tb' => $tb, 'fd' => $fd, 'net' => max(0, $tb - $fd)];
}

/* Fonctions de calcul des formulaires MoF (deplacees depuis official_export.php 2026-08-24):
 * ici pour que le verificateur du fichier ministere (r567_check.php) utilise la meme source unique. */
/** فلترا الفئة/الضريبة من الرابط (نفس فلتري official_forms) */
function mofEmpFilterSql($db) {
    $t = in_array($_GET['emp_type'] ?? '', ['enseignant_titulaire', 'enseignant_contractuel', 'employe'], true) ? $_GET['emp_type'] : '';
    $x = in_array($_GET['tax_sub'] ?? '', ['1', '0'], true) ? $_GET['tax_sub'] : '';
    return ($t ? " AND e.employee_type = " . $db->quote($t) : '')
         . ($x !== '' ? " AND e.tax_subject = " . (int)$x : '');
}

/** مجاميع فصل ميلادي واحد — المنطق المعتمد نفسه من نموذج ر10 (2026-08-06) */
function mofQuarterAgg($db, $rq, $rqy, $empFilter) {
    $rqMonthsMap = [1 => [1, 2, 3], 2 => [4, 5, 6], 3 => [7, 8, 9], 4 => [10, 11, 12]];
    $rqM = $rqMonthsMap[$rq];
    $rqIn = implode(',', $rqM);
    $rqSy = ($rq === 4) ? ($rqy . '-' . ($rqy + 1)) : (($rqy - 1) . '-' . $rqy);
    [$yf, $yp] = yearEmploymentFilter($rqSy, 'e.');
    $q = $db->prepare("SELECT
            SUM(ms.base_plus_echelon_lbp+ms.extra_lbp+ms.prime_fixe_lbp+ms.aide_complementaire_lbp) gross,
            SUM(ms.transport_lbp) transport,
            SUM(ms.caisse_amount_lbp) caisse, SUM(ms.eoc_grade_lbp) eoc,
            SUM(ms.taxable_base_lbp) taxable, SUM(ms.income_tax_lbp) tax
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND e.tax_subject=1" . $yf . $empFilter . " AND ms.year=? AND ms.month IN ($rqIn)
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id'));
    $q->execute(array_merge($yp, [$rqy]));
    $g = $q->fetch() ?: [];
    // ١٧٠ التنزيل العائلي للفترة (المصدر الوحيد familyDeductionAnnual — تجزئة بمدة العمل)
    $qDed = $db->prepare("SELECT e.id, e.social_status, e.spouse_works, COALESCE(e.apply_family_deduction,1) afd, COALESCE(e.grant_spouse_addition,0) gsa, COALESCE(e.grant_children_addition,0) gca, COUNT(DISTINCT ms.month) mcnt, SUM(ms.taxable_base_lbp) tb
        FROM employees e JOIN monthly_salaries ms ON ms.employee_id=e.id
        WHERE e.is_deleted=0 AND e.tax_subject=1" . $yf . $empFilter . " AND ms.year=? AND ms.month IN ($rqIn)
          AND (ms.base_plus_echelon_lbp > 0 OR ms.net_salary_lbp > 0 OR ms.total_due_lbp > 0) AND " . schoolScopeWhere('e.school_id') . "
        GROUP BY e.id, e.social_status, e.spouse_works, afd");
    $qDed->execute(array_merge($yp, [$rqy]));
    $dedAsOf = sprintf('%04d-%02d-01', $rqy, $rqM[0]);
    $exempt = 0; $ids = [];
    foreach ($qDed->fetchAll() as $de) {
        $ids[] = (int)$de['id'];
        $fda = familyDeductionAnnual($de['social_status'], $de['spouse_works'], $de['afd'], $dedAsOf, $de['gsa'] ?? 0, $de['gca'] ?? 0, (int)$de['id']);
        $exempt += (int)min($fda / 12 * (int)$de['mcnt'], (float)$de['tb']);
    }
    $gross = (int)($g['gross'] ?? 0); $trans = (int)($g['transport'] ?? 0);
    $other = (int)($g['caisse'] ?? 0) + (int)($g['eoc'] ?? 0);
    $net   = $gross - $other; // = (gross+trans) − trans − other = مجموع الأساس الخاضع المخزَّن
    return [
        'ids' => $ids, 'gross' => $gross, 'trans' => $trans, 'other' => $other,
        'net' => $net, 'exempt' => $exempt, 'taxable' => max(0, $net - $exempt), 'tax' => (int)($g['tax'] ?? 0),
    ];
}

/** 🟰 المصدر السنوي الموحّد («الأرقام بر5 لازم يكونو مطابقين لر6» + «في فرق بين ر5 لحالها
 *  وR567؟» 2026-08-24): صف لكل موظف بمجاميعه المخزّنة للسنة الميلادية + تنزيله العائلي
 *  السنوي + المشتقات الجاهزة، ومجاميعها sum. نموذج ر5 المستقل وورقتا R5/R6 بملف R567
 *  كلهم يقرأون من هنا حصراً — فأي رقم بر5 = مجموع عموده بصفوف ر6 بالمليم أينما فُتح. */
function mofYearEmpData($db, $fy, $empFilter) {
    $ids = [];
    for ($q = 1; $q <= 4; $q++) $ids = array_merge($ids, mofQuarterAgg($db, $q, $fy, $empFilter)['ids']);
    $ids = array_values(array_unique($ids));
    $marLbl = function ($ss2) {
        if (strpos((string)$ss2, 'marie') === 0) return 'متزوج';
        if (strpos((string)$ss2, 'veuf') === 0) return 'أرمل';
        if (strpos((string)$ss2, 'divorce') === 0) return 'مطلق';
        return 'أعزب';
    };
    $rows = [];
    $S = ['paid' => 0, 'trans' => 0, 'fam' => 0, 'other' => 0, 'tb' => 0, 'fd' => 0, 'net' => 0, 'tax' => 0];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        // 🔴 ماكرو الوزارة يقف عند أول صف رقم ماليته فارغ (Exit For) — ناقصو الرقم آخر اللائحة
        $le = $db->query("SELECT * FROM employees WHERE id IN ($in)
            ORDER BY (COALESCE(finance_ministry_number,'') REGEXP '[0-9]') DESC,
                COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)")->fetchAll();
        $agQ = $db->prepare("SELECT SUM(base_plus_echelon_lbp) base,
                SUM(extra_lbp+prime_fixe_lbp) extraw, SUM(aide_complementaire_lbp) aide,
                SUM(family_allowance_lbp) family, SUM(transport_lbp) trans,
                SUM(caisse_amount_lbp+eoc_grade_lbp) other,
                SUM(taxable_base_lbp) tb, SUM(income_tax_lbp) tax,
                COUNT(DISTINCT month) mcnt, MIN(month) m1, MAX(month) m2
            FROM monthly_salaries WHERE employee_id=? AND year=?
              AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0)");
        foreach ($le as $emp2) {
            $agQ->execute([(int)$emp2['id'], $fy]);
            $a2 = $agQ->fetch() ?: [];
            if (!(int)($a2['mcnt'] ?? 0)) continue;
            $isMar2 = strpos((string)($emp2['social_status'] ?? ''), 'marie') === 0;
            $fda2 = familyDeductionAnnual($emp2['social_status'] ?? '', $emp2['spouse_works'] ?? 0,
                $emp2['apply_family_deduction'] ?? 1, $fy . '-01-01',
                $emp2['grant_spouse_addition'] ?? 0, $emp2['grant_children_addition'] ?? 0, (int)$emp2['id']);
            $fd2 = (int)min($fda2 / 12 * min(12, (int)$a2['mcnt']), (float)$a2['tb']);
            // عدد الأولاد دون 18 بأول السنة (المؤرَّخون أولاً وإلا العدد الثابت)
            $kq2 = $db->prepare("SELECT COUNT(*) FROM employee_children WHERE employee_id=? AND DATE_ADD(birth_date, INTERVAL 18 YEAR) > ?");
            $kq2->execute([(int)$emp2['id'], $fy . '-01-01']);
            $hasKids = (int)$db->query("SELECT COUNT(*) FROM employee_children WHERE employee_id=" . (int)$emp2['id'])->fetchColumn();
            $nKids = $hasKids ? (int)$kq2->fetchColumn() : (int)($emp2['number_of_children'] ?? 0);
            $benef2 = $nKids + (($isMar2 && !(int)($emp2['spouse_works'] ?? 0) && (int)($emp2['grant_spouse_addition'] ?? 0) === 1) ? 1 : 0);
            $base2 = (int)$a2['base']; $exw2 = (int)$a2['extraw']; $aide2 = (int)$a2['aide'];
            $fam2 = (int)$a2['family']; $tr2 = (int)$a2['trans'];
            $tb2 = (int)$a2['tb']; $tax2 = (int)$a2['tax'];
            $tot3 = $base2 + $exw2 + $aide2;
            $d2 = [
                'base' => $base2, 'extraw' => $exw2, 'aide' => $aide2, 'fam' => $fam2, 'trans' => $tr2,
                'tb' => $tb2, 'tax' => $tax2,
                'tot1' => $tot3 + $fam2 + $tr2, 'tot2' => $fam2 + $tr2, 'tot3' => $tot3,
                // «تنزيلات أخرى» = الفرق الحقيقي عن الأساس الخاضع المخزَّن (الصندوق+الدرجة
                // + أي جزء غير خاضع بخيارات الموظف) — هكذا كل صف «يركب»: tot3−other = tb
                'other' => max(0, $tot3 - $tb2),
                'net350' => max(0, $tb2 - $fd2),
            ];
            $S['paid'] += $d2['tot1']; $S['trans'] += $tr2; $S['fam'] += $fam2; $S['other'] += $d2['other'];
            $S['tb'] += $tb2; $S['fd'] += $fd2; $S['net'] += $d2['net350']; $S['tax'] += $tax2;
            $rows[] = ['e' => $emp2, 'a' => $a2, 'fd' => $fd2, 'kids' => $nKids, 'benef' => $benef2,
                'mar' => $marLbl($emp2['social_status'] ?? ''), 'd' => $d2];
        }
    }
    return ['rows' => $rows, 'sum' => $S];
}

/** 🟰 الفصل إفرادياً بالطريقة التراكمية («انتبه ر5 كمان بدها تكون مجموع ر10 على أربع
 *  فصول» 2026-08-24): مبالغ الفصل = مجاميع أشهره موظفاً موظفاً، والتنزيل العائلي/الخاضع
 *  = تراكمي السنة حتى آخر الفصل − تراكمي ما قبله (mofCumTax) — فمجموع الفصول الأربعة
 *  يساوي السنوي الإفرادي (mofYearEmpData = ر5 = صفوف ر6) بالمليم حتماً. */
function mofQuarterEmpData($db, $rq, $rqy, $empFilter) {
    $S = ['paid' => 0, 'trans' => 0, 'fam' => 0, 'other' => 0, 'tb' => 0, 'fd' => 0, 'net' => 0, 'tax' => 0, 'cnt' => 0];
    $ids = mofQuarterAgg($db, $rq, $rqy, $empFilter)['ids'];
    if (!$ids) return ['sum' => $S];
    $in = implode(',', array_map('intval', $ids));
    $m1 = ($rq - 1) * 3 + 1; $m3 = $rq * 3;
    $agg = $db->prepare("SELECT SUM(base_plus_echelon_lbp+extra_lbp+prime_fixe_lbp+aide_complementaire_lbp) tot3,
            SUM(family_allowance_lbp) fam, SUM(transport_lbp) trans,
            SUM(taxable_base_lbp) tb, SUM(income_tax_lbp) tax, COUNT(DISTINCT month) mcnt
        FROM monthly_salaries WHERE employee_id=? AND year=? AND month BETWEEN ? AND ?
          AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0)");
    foreach ($db->query("SELECT * FROM employees WHERE id IN ($in)")->fetchAll() as $e9) {
        $agg->execute([(int)$e9['id'], $rqy, $m1, $m3]);
        $q9 = $agg->fetch() ?: [];
        if (!(int)($q9['mcnt'] ?? 0)) continue;
        $C9 = mofCumTax($db, $e9, $rqy, $m3);
        $P9 = mofCumTax($db, $e9, $rqy, $m1 - 1);
        $tot39 = (int)$q9['tot3']; $tb9 = (int)$q9['tb'];
        $S['paid'] += $tot39 + (int)$q9['fam'] + (int)$q9['trans'];
        $S['trans'] += (int)$q9['trans']; $S['fam'] += (int)$q9['fam'];
        $S['other'] += max(0, $tot39 - $tb9);
        $S['tb'] += $tb9;
        $S['fd'] += $C9['fd'] - $P9['fd'];
        $S['net'] += $C9['net'] - $P9['net'];
        $S['tax'] += (int)$q9['tax'];
        $S['cnt']++;
    }
    return ['sum' => $S];
}

/** يعيد ملف تعريف المؤسسة للمالية بكل المفاتيح (الفارغ = '') */
function mofProfile($school): array {
    $defaults = ['gov'=>'','caza'=>'','town'=>'','quarter'=>'','street'=>'','cadastral'=>'','lot'=>'','building'=>'',
        'floor'=>'','fax'=>'','pob'=>'','region'=>'','email'=>'','trade_name'=>'','rep_name'=>'','rep_title'=>'',
        'contact_name'=>'','contact_reg'=>'','contact_phone'=>'','contact_fax'=>'',
        'preparer_name'=>'','preparer_reg'=>'','preparer_phone'=>'','preparer_fax'=>'','signer_name'=>'','signer_title'=>''];
    $p = [];
    if (is_array($school) && !empty($school['mof_profile'])) {
        $p = json_decode((string)$school['mof_profile'], true) ?: [];
    }
    $out = array_merge($defaults, array_intersect_key($p, $defaults));
    // فراغات ذكية من ملف المدرسة نفسه
    if ($out['email'] === '' && !empty($school['email'])) $out['email'] = (string)$school['email'];
    return $out;
}

function ensureEmployeeFlagColumns() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'apply_family_deduction'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN apply_family_deduction TINYINT(1) NOT NULL DEFAULT 1");
        }
        // خيارا التعويض العائلي (2026-08-06): احتساب تعويض الزوج/الزوجة والأولاد أو لا
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'count_spouse_allowance'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN count_spouse_allowance TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'count_children_allowance'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN count_children_allowance TINYINT(1) NOT NULL DEFAULT 1");
        }
        // خيار «زيادة الزوج بالتنزيل العائلي: تُعطى/لا تُعطى» (2026-08-06 بطلب المستخدم —
        // قانوناً الزيادة عن «الزوجة التي لا تعمل»، والمرأة لا تأخذها عن زوج قادر على العمل)
        // 🔴 «طفي زيادة الزوج» (2026-08-23): الافتراضي مطفأ — تُضوّى بقرار المستخدم لكل موظف
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'grant_spouse_addition'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN grant_spouse_addition TINYINT(1) NOT NULL DEFAULT 0");
        }
        // خيار «تنزيل الأولاد بالضريبة: يُعطى/لا» («لو عندها اولاد او الزوج لا يعمل اذا انا
        // مطفي التنزيل عليهن ما لازم يحسب — بس تنزيل الاستاذ لوحدو» — 2026-08-23)
        // 🔴 «هيدا الزر يكون مطفي تلقائيا حتى انا اذا بدي ضوي»: الافتراضي مطفأ — يُضوّى يدوياً لكل موظف
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'grant_children_addition'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN grant_children_addition TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) { /* لا نكسر الصفحة — يُعاد بالفتحة التالية */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-06 — «بدي البرنامج يشتغل كل شي صح وحسب القوانين
 * اللبنانية»): تطبيق قاعدة تجزئة التنزيل والشطور بمدة العمل (دليل وزارة المالية ص55)
 * على المخزّن — يُعاد حساب غير ذوي الـ12 شهراً الخاضعين للضريبة (المعدّين) للسنة
 * الحالية والسنين المفتوحة اللاحقة، فتتصحّح ضريبتهم على المعادلة القانونية (×12/÷12).
 * ذوو الـ12 شهراً لا يتغيّرون (المعادلتان متطابقتان لهم). idempotent.
 */
/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-23 — «ضريبة مايا ابي حبيب 0 وهيدا غلط»): مايا أبي حبيب
 * (1754) قرّر المستخدم أن تنزيلها العائلي شخصي فقط («بس تنزيل الاستاذ لوحدو») — تُطفأ
 * زيادة الزوج وتنزيل الأولاد بملفها ويُعاد احتساب أشهرها المخزّنة من السنة الدراسية
 * 2025-2026 وما بعدها (فتظهر ضريبتها الفعلية بدل الصفر القديم المحسوب بالتنزيل الكامل).
 * يعمل محلياً وأونلاين معاً (النشر يوصله والهيدر يشغّله مرة واحدة). idempotent.
 */
function healMayaTaxFlags20260823() {
    $flag = 'maya_taxflags_2026_08_23';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        ensureEmployeeFlagColumns();
        if (function_exists('set_time_limit')) @set_time_limit(300);
        $ok = $db->query("SELECT id FROM employees WHERE id = 1754 AND last_name_ar LIKE '%حبيب%'")->fetch();
        if ($ok) {
            $db->exec("UPDATE employees SET grant_spouse_addition = 0, grant_children_addition = 0 WHERE id = 1754");
            require_once __DIR__ . '/payroll_calculator.php';
            foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = 1754 AND school_year >= '2025-2026' ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                try { recalcEmployeeYear(1754, $sy); } catch (Throwable $e) {}
            }
        }
        setSetting($flag, date('Y-m-d H:i'));
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد بالفتحة التالية */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-23 — «صحح الحالات وضوي المفاتيح»): تصحيح الوضع
 * العائلي لموظفي سيدة النجاة من إخراجات القيد العائلية المقروءة (كانوا كلهم «عازب»
 * بالبرنامج خطأً) + تضوية مفتاح «تنزيل الأولاد» لمن لديهم أولاد دون 18 حصراً (معيار
 * المستخدم) + زيادة الزوج مطفأة (نمط مايا) + عدد الأولاد = الأولاد دون 18 (المستفاد
 * عنهم ضريبياً) ثم إعادة احتساب سنواتهم من 2025-2026. idempotent — يعمل محلياً وأونلاين.
 */
function healNajatCivilStatus20260823() {
    $flag = 'najat_civil_status_2026_08_23';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        ensureEmployeeFlagColumns();
        if (function_exists('set_time_limit')) @set_time_limit(600);
        // [id, جزء من الشهرة للتثبّت, الوضع, عدد الأولاد (<18), gsa, gca]
        $fix = [
            [1546, 'زوبا',    'marie_3_enfants',   3, 0, 1], // جونا: توأم 2013 + 2019
            [1826, 'شرو',     'marie_2_enfants',   2, 0, 1], // ديانا: 2020 + 2021
            [68,   'منصور',   'marie_2_enfants',   2, 0, 1], // مرسال: 2009 + 2011 (والكبيران فوق 18)
            [51,   'كرم',     'marie_1_enfant',    1, 0, 1], // دوللي: 2014
            [1224, 'خاطر',    'marie_1_enfant',    1, 0, 1], // فاليسا: ~2024
            [1840, 'السكاف',  'marie_1_enfant',    1, 0, 1], // كارين: 2025
            [425,  'فاخوري',  'marie_1_enfant',    1, 0, 1], // ميراي: 11/2024
            [968,  'برباري',  'veuf_2_enfants',    2, 0, 1], // إليانا: أرملة + ~2009 و~2012
            [1066, 'قاصوف',   'marie_sans_enfants', 0, 0, 0], // الين: أولادها فوق 18
            [61,   'القارح',  'marie_sans_enfants', 0, 0, 0], // لينا: فوق 18
            [65,   'غنيمه',   'marie_sans_enfants', 0, 0, 0], // مارينا: الأصغر بلغ 18 بآب 2026
            [1138, 'يعقوب',   'marie_sans_enfants', 0, 0, 0], // هيلاني: فوق 18
            [62,   'عازار',   'marie_sans_enfants', 0, 0, 0], // مادونا: أولاد غير مؤكّدين — نسخة أوضح
            [53,   'قرعه',    'veuf_sans_enfants',  0, 0, 0], // زينة: أرملة، أولادها فوق 18
        ];
        require_once __DIR__ . '/payroll_calculator.php';
        $st = $db->prepare("UPDATE employees SET social_status=?, number_of_children=?, grant_spouse_addition=?, grant_children_addition=? WHERE id=? AND last_name_ar LIKE ?");
        foreach ($fix as [$id, $nm, $ss, $ch, $gsa, $gca]) {
            $st->execute([$ss, $ch, $gsa, $gca, $id, '%' . $nm . '%']);
            if ($st->rowCount() >= 0) {
                foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$id . " AND school_year >= '2025-2026' ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                    try { recalcEmployeeYear((int)$id, $sy); } catch (Throwable $e) {}
                }
            }
        }
        setSetting($flag, date('Y-m-d H:i'));
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد بالفتحة التالية */ }
}

/**
 * 💡 اقتراحات ضريبية من إخراجات القيد («لازم يضوي بالبرنامج وانا بساعتها بطبق او لاء»
 * — 2026-08-23): جدول ذاتي التركيب يحمل ما قُرئ من إخراجات القيد العائلية كاقتراحات؛
 * إشارة حمراء تضوي بالقائمة عند وجود معلَّق، والمستخدم يقرّر «طبّق» أو «تجاهل» من صفحة
 * «اقتراحات من إخراج القيد». القراءات الجديدة تُزرَع هنا (source_key يمنع التكرار).
 */
function ensureTaxSuggestions20260823() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS tax_suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_key VARCHAR(80) UNIQUE,
            employee_id INT NULL,
            school_id INT NULL,
            emp_name VARCHAR(160) NULL,
            title VARCHAR(255) NOT NULL,
            details TEXT NULL,
            proposed TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NULL,
            decided_at DATETIME NULL
        ) DEFAULT CHARSET=utf8mb4");
        // زرع قراءات سيدة النجاة (22 إخراجاً — 2026-08-23): المطبَّق موثَّق والمعلَّق بانتظار قراره
        $ins = $db->prepare("INSERT IGNORE INTO tax_suggestions (source_key, employee_id, school_id, emp_name, title, details, proposed, status, created_at, decided_at) VALUES (?,?,?,?,?,?,?,?,NOW(),?)");
        $J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);
        $rows = [
            ['najat_1546', 1546, 3, 'جونا زوبا', 'متزوجة من روجيه نادر + 3 أولاد دون 18', 'إخراج القيد: توأم ماريا وإلاريا (2013 — 13 سنة) + شريل (2019 — 7 سنوات). كانت «عزباء» بالبرنامج.', $J(['social_status'=>'marie_3_enfants','number_of_children'=>3,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_1826', 1826, 3, 'ديانا شرو', 'متزوجة من ميشال عبود (2017) + ولدان دون 18', 'إخراج القيد: جويا (2020 — 6 سنوات) + شربل (2021 — 4 سنوات).', $J(['social_status'=>'marie_2_enfants','number_of_children'=>2,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_68', 68, 3, 'مرسال منصور', 'متزوجة من ميلاد السروجي + ولدان دون 18 (من أصل 4)', 'إخراج القيد: رومي (2003) وروبين (2004) فوق 18 + ريبيكا (2009 — 17 سنة) وريا (2011 — 15 سنة).', $J(['social_status'=>'marie_2_enfants','number_of_children'=>2,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_968', 968, 3, 'إليانا برباري', 'أرملة (الزوج توفي 2015) + ولدان دون 18', 'قيد سوري: ولدان مواليد ~2009 (17 سنة) و~2012 (14 سنة) — الأعمار تقريبية.', $J(['social_status'=>'veuf_2_enfants','number_of_children'=>2,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_51', 51, 3, 'دوللي كرم', 'متزوجة + بنت دون 18', 'إخراج القيد: ألكسيا مارتينا (2014 — 12 سنة).', $J(['social_status'=>'marie_1_enfant','number_of_children'=>1,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_1224', 1224, 3, 'فاليسا خاطر', 'متزوجة من فريدي أبي أنطون + ولد رضيع', 'إخراج القيد: ولد مواليد ~2024.', $J(['social_status'=>'marie_1_enfant','number_of_children'=>1,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_1840', 1840, 3, 'كارين السكاف', 'متزوجة من ماريو الجد (2024) + طفل رضيع', 'إخراج القيد: مايكل (10/9/2025).', $J(['social_status'=>'marie_1_enfant','number_of_children'=>1,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_425', 425, 3, 'ميراي فاخوري', 'متزوجة من ردي غصين (2025) + بنت رضيعة', 'إخراج القيد: جايمي (28/11/2024).', $J(['social_status'=>'marie_1_enfant','number_of_children'=>1,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_1066', 1066, 3, 'الين قاصوف', 'متزوجة من جان القارح — كل الأولاد فوق 18', 'إخراج القيد: ديزيري (1995) وديانا (1997) تزوّجتا + جاك (2003 — 23 سنة).', $J(['social_status'=>'marie_sans_enfants','number_of_children'=>0,'grant_children_addition'=>0,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_61', 61, 3, 'لينا القارح', 'متزوجة من بيار القارح — كل الأولاد فوق 18', 'إخراج القيد: جيمي (1999) + جان (2002).', $J(['social_status'=>'marie_sans_enfants','number_of_children'=>0,'grant_children_addition'=>0,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_65', 65, 3, 'مارينا غنيمه', 'متزوجة من طنوس العشي — الأصغر بلغ 18 في 1/8/2026', 'إخراج القيد: بندليون (2004) + أيوب (2007) + لبيب (1/8/2008).', $J(['social_status'=>'marie_sans_enfants','number_of_children'=>0,'grant_children_addition'=>0,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_1138', 1138, 3, 'هيلاني يعقوب', 'متزوجة — ولداها فوق 18 (الصورة ضعيفة)', 'إخراج القيد: ولدان ~1992 و~1995. يُستحسن نسخة أوضح للأرشيف.', $J(['social_status'=>'marie_sans_enfants','number_of_children'=>0,'grant_children_addition'=>0,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_53', 53, 3, 'زينة قرعه', 'أرملة (الزوج توفي 2023) — ولداها فوق 18', 'إخراج القيد: نقولا (2003) + شربل (2005).', $J(['social_status'=>'veuf_sans_enfants','number_of_children'=>0,'grant_children_addition'=>0,'grant_spouse_addition'=>0]), 'applied', date('Y-m-d H:i:s')],
            ['najat_62', 62, 3, 'مادونا عازار', '⚠️ متزوجة من ناجي عبدالله + ولدان بأعمار غير مؤكّدة (~16-21)', 'الخط غير واضح بإخراجها — طُبّق مؤقتاً «متزوجة بلا أولاد قاصرين». إن ثبت ولد دون 18 بنسخة أوضح: طبّق الاقتراح ليُضاف ولد واحد.', $J(['social_status'=>'marie_1_enfant','number_of_children'=>1,'grant_children_addition'=>1,'grant_spouse_addition'=>0]), 'pending', null],
            ['najat_1387', 1387, 3, 'كميل مرعي', '⚠️ الملف المرفوع بخانة إخراج القيد ليس إخراج قيد', 'الملف سكرين شوت واتساب — يُطلب رفع إخراج القيد العائلي الصحيح عبر رابط التحديث.', null, 'pending', null],
            ['maxim_38', 38, 2, 'دنيا القزي', '⚠️ الملف المرفوع بخانة إخراج القيد جواز سفر', 'دنيا القزي (القديس مكسيموس) رفعت جواز سفر بدل إخراج القيد — يُطلب رفع الصحيح.', null, 'pending', null],
        ];
        foreach ($rows as $r) $ins->execute($r);
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/** عدد الاقتراحات المعلّقة (لإضاءة الإشارة بالقائمة) —
 *  📅 «بدي اساتذة نفس السنة» (p1 — 2026-08-25): بنفس نطاق صفحة الاقتراحات
 *  (المدرسة المختارة + موظفو السنة المعروضة فقط) حتى يطابق الرقمُ المعروضَ فيها */
function taxSuggestionsPendingCount(): int {
    try {
        ensureTaxSuggestions20260823();
        [$yf, $yp] = yearEmploymentFilter(activeSchoolYear(), 'e.');
        $q = getDB()->prepare("SELECT COUNT(*) FROM tax_suggestions ts
            JOIN employees e ON e.id = ts.employee_id AND e.is_deleted = 0
            WHERE ts.status = 'pending' AND " . schoolScopeWhere('ts.school_id') . $yf);
        $q->execute($yp);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-23 — «طفي زيادة الزوج» + «الا قراري انا وانت اكيد
 * بتكون باعتلي رسالة»): زيادة الزوج/الزوجة تُطفأ تلقائياً لكل الموظفين (كمفتاح الأولاد)
 * — تُضوّى بقرار المستخدم فقط. المتأثرون (متزوج + الزوج لا يعمل + كانت الزيادة مضوّاة):
 * يُعاد احتساب سنواتهم من السنة الجارية + **رسالة لكل واحد بصفحة الاقتراحات** (تضوي
 * بالقائمة) فيها زر «طبّق» يعيد له الزيادة إن قرّر المستخدم. idempotent — محلياً وأونلاين.
 */
function healSpouseAdditionOff20260823() {
    $flag = 'spouse_addition_off_2026_08_23';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        ensureEmployeeFlagColumns();
        ensureTaxSuggestions20260823();
        if (function_exists('set_time_limit')) @set_time_limit(600);
        // المتأثرون قبل الإطفاء (كانوا يأخذون الزيادة فعلاً)
        $aff = $db->query("SELECT id, school_id, COALESCE(NULLIF(TRIM(CONCAT(first_name_ar,' ',last_name_ar)),''), TRIM(CONCAT(first_name_fr,' ',last_name_fr))) nm
            FROM employees WHERE is_deleted=0 AND social_status LIKE 'marie%' AND COALESCE(spouse_works,0)=0
              AND COALESCE(apply_family_deduction,1)=1 AND COALESCE(grant_spouse_addition,1)=1 AND tax_subject=1")->fetchAll(PDO::FETCH_ASSOC);
        // الإطفاء للجميع + الافتراضي مطفأ
        $db->exec("UPDATE employees SET grant_spouse_addition = 0");
        try { $db->exec("ALTER TABLE employees MODIFY COLUMN grant_spouse_addition TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        // إعادة احتساب المتأثرين من السنة الجارية + رسالة قرار لكل واحد
        require_once __DIR__ . '/payroll_calculator.php';
        $cur = currentSchoolYear();
        $ins = $db->prepare("INSERT IGNORE INTO tax_suggestions (source_key, employee_id, school_id, emp_name, title, details, proposed, status, created_at) VALUES (?,?,?,?,?,?,?, 'pending', NOW())");
        foreach ($aff as $a) {
            foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$a['id'] . " AND school_year >= " . $db->quote($cur) . " ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                try { recalcEmployeeYear((int)$a['id'], $sy); } catch (Throwable $e) {}
            }
            $ins->execute(['gsa_off_' . (int)$a['id'], (int)$a['id'], (int)$a['school_id'], $a['nm'],
                'انطفت عنه زيادة الزوج/الزوجة تلقائياً — قرارك إذا بتتضوّى',
                'متزوج والزوج/الزوجة لا يعمل وكان يأخذ زيادة الزوج (225 مليوناً سنوياً) قبل قاعدة «الإطفاء التلقائي». إن أردت إعادتها له اكبس «نعم»، وإذا ما بدك التنزيل «كلا».',
                json_encode(['grant_spouse_addition' => 1], JSON_UNESCAPED_UNICODE)]);
        }
        setSetting($flag, date('Y-m-d H:i') . ' (' . count($aff) . ' متأثراً)');
    } catch (Throwable $e) { /* لا تكسر الصفحة — يُعاد بالفتحة التالية */ }
}

/**
 * 👶📅 أولاد الموظفين بتواريخ ولادتهم («يشيل التنزيل من تاريخ بلوغ 18» — 2026-08-23):
 * جدول ذاتي التركيب + عمود «تاريخ بدء عمل الزوج» + زرع أولاد سيدة النجاة من إخراجات
 * القيد المقروءة (بتواريخهم الفعلية) فيتوقف تنزيل كل ولد تلقائياً من شهر بلوغه الـ18.
 */
function ensureEmployeeChildren20260823() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS employee_children (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            child_name VARCHAR(120) NULL,
            birth_date DATE NOT NULL,
            source VARCHAR(40) NULL,
            UNIQUE KEY uq_child (employee_id, child_name, birth_date)
        ) DEFAULT CHARSET=utf8mb4");
        if (!$db->query("SHOW COLUMNS FROM employees LIKE 'spouse_work_start_date'")->fetch()) {
            $db->exec("ALTER TABLE employees ADD COLUMN spouse_work_start_date DATE NULL");
        }
        if (getSetting('children_seed_najat_2026_08_23', '') !== '') return;
        // زرع أولاد سيدة النجاة من إخراجات القيد المقروءة (التقريبي معلَّم بمصدره)
        $rows = [
            [1546, 'ماريا نادر', '2013-07-08'], [1546, 'إلاريا نادر', '2013-07-08'], [1546, 'شريل نادر', '2019-04-02'],
            [1826, 'جويا عبود', '2020-02-12'], [1826, 'شربل عبود', '2021-12-17'],
            [68, 'رومي السروجي', '2003-04-17'], [68, 'روبين السروجي', '2004-06-25'], [68, 'ريبيكا السروجي', '2009-05-13'], [68, 'ريا السروجي', '2011-08-18'],
            [51, 'ألكسيا مارتينا', '2014-04-04'],
            [1224, 'ولد أبي أنطون (تقريبي)', '2024-08-02'],
            [1840, 'مايكل الجد', '2025-09-10'],
            [425, 'جايمي غصين', '2024-11-28'],
            [968, 'بيتر (تقريبي)', '2009-09-04'], [968, 'بيير (تقريبي)', '2012-08-31'],
            [1066, 'ديزيري القارح', '1995-10-11'], [1066, 'ديانا القارح', '1997-08-14'], [1066, 'جاك القارح', '2003-08-29'],
            [61, 'جيمي القارح', '1999-11-10'], [61, 'جان القارح', '2002-06-18'],
            [65, 'بندليون العشي', '2004-04-20'], [65, 'أيوب العشي', '2007-02-19'], [65, 'لبيب العشي', '2008-08-01'],
            [53, 'نقولا صليبا', '2003-03-11'], [53, 'شربل صليبا', '2005-07-25'],
        ];
        $ins = $db->prepare("INSERT IGNORE INTO employee_children (employee_id, child_name, birth_date, source) VALUES (?,?,?, 'family_doc')");
        foreach ($rows as $r) {
            // لا تزرع إلا إذا الموظف موجود (قاعدة أونلاين/محلي متطابقة الأرقام)
            $ok = $db->query("SELECT id FROM employees WHERE id = " . (int)$r[0])->fetch();
            if ($ok) $ins->execute($r);
        }
        setSetting('children_seed_najat_2026_08_23', date('Y-m-d H:i'));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-23): بعد زرع أولاد سيدة النجاة بتواريخهم، يُعاد
 * احتساب أشهر أصحابهم المخزّنة من السنة الجارية فتسري أتمتة «سقوط تنزيل الولد من شهر
 * بلوغه الـ18» على السنوات المولّدة مسبقاً أيضاً (2026-2027+). idempotent — محلياً وأونلاين.
 */
function healChildrenAutomation20260823() {
    $flag = 'children_automation_2026_08_23';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        ensureEmployeeChildren20260823();
        if (function_exists('set_time_limit')) @set_time_limit(600);
        require_once __DIR__ . '/payroll_calculator.php';
        $cur = currentSchoolYear();
        foreach ($db->query("SELECT DISTINCT employee_id FROM employee_children")->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            foreach ($db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = " . (int)$eid . " AND school_year >= " . $db->quote($cur) . " ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN) as $sy) {
                try { recalcEmployeeYear((int)$eid, $sy); } catch (Throwable $e) {}
            }
        }
        setSetting($flag, date('Y-m-d H:i'));
    } catch (Throwable $e) { /* لا تكسر الصفحة */ }
}

function healLawfulTaxProration20260806() {
    $flag = 'lawful_tax_proration_2026_08_06';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        if (function_exists('set_time_limit')) @set_time_limit(600);
        $q = $db->prepare("SELECT DISTINCT ms.employee_id, ms.school_year FROM monthly_salaries ms
            JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND e.payment_months_per_year <> 12 AND e.tax_subject = 1
              AND (e.employee_type = 'enseignant_titulaire' OR e.base_salary_usd > 0 OR e.contract_salary_lbp > 0)
              AND ms.school_year >= ?");
        $q->execute([currentSchoolYear()]);
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            try { recalcEmployeeYear((int)$r['employee_id'], $r['school_year']); $n++; } catch (Exception $e2) {}
        }
        setSetting($flag, date('Y-m-d H:i:s') . " ($n)");
        if ($n) logAudit('heal_lawful_tax_proration', 'monthly_salaries', 0, null, ['recalcs' => $n]);
    } catch (Exception $e) { /* يُعاد بالفتحة التالية */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-06 بأمر المستخدم الصريح «الملف اللي ما في اسم الأب شيلو»):
 * الموظف ذو الملفين بنفس المؤسسة وكلاهما قابض بالسنة الحالية — يُشال الملف الذي **ليس فيه
 * اسم أب حقيقي** (فارغ أو نقاط أو حرف واحد = placeholder المنقول) ويبقى الملف الكامل.
 * حذف ناعم (is_deleted=1) قابل للاسترجاع + سجلّ تدقيق. المطابقة بالاسم لا بالرقم (يعمل
 * أونلاين مهما اختلفت الأرقام). أمان: إن كان كلا الملفين بلا أب حقيقي (جان عاد) أو كلاهما
 * بأب حقيقي (شخصان مثل ريتا/ماريا حليحل) → لا يُمَسّ شيء، القرار يدوي.
 */
function healRemoveNoFatherDuplicates20260806() {
    $flag = 'remove_nofather_dups_2026_08_06';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        $q = $db->prepare("SELECT e.id, e.school_id, CONCAT(e.first_name_ar,' ',e.last_name_ar) nm, e.father_name_ar
            FROM employees e WHERE e.is_deleted = 0
              AND EXISTS (SELECT 1 FROM monthly_salaries ms WHERE ms.employee_id = e.id AND ms.school_year = ? AND ms.net_salary_lbp > 0)");
        $q->execute([currentSchoolYear()]);
        $groups = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $groups[$r['school_id'] . '|' . $r['nm']][] = $r;
        $realFather = function ($f) { return mb_strlen(str_replace('.', '', trim((string)$f))) >= 2; };
        $removed = [];
        foreach ($groups as $g) {
            if (count($g) < 2) continue;
            $with = array_filter($g, function ($r) use ($realFather) { return $realFather($r['father_name_ar']); });
            $without = array_filter($g, function ($r) use ($realFather) { return !$realFather($r['father_name_ar']); });
            if (!$with || !$without) continue; // كلاهما بلا أب أو كلاهما بأب → قرار يدوي
            foreach ($without as $r) {
                $db->prepare("UPDATE employees SET is_deleted = 1 WHERE id = ?")->execute([(int)$r['id']]);
                $removed[] = $r['nm'] . ' (#' . $r['id'] . ')';
            }
        }
        setSetting($flag, date('Y-m-d H:i:s'));
        if ($removed) logAudit('heal_remove_nofather_dups', 'employees', 0, null, ['removed' => $removed]);
    } catch (Exception $e) { /* لا نعطّل الصفحة — يُعاد بالفتحة التالية */ }
}

/**
 * 🔴 المصدر الوحيد للتنزيل العائلي السنوي الساري لموظف (2026-08-06، حالة زاهية الحاج):
 * يعتمده المحرّك وكل الكشوف وتصاريح ر5/ر10 — فلا يختلف رقم عن رقم.
 *   - زرّ «تطبيق التنزيل العائلي» بملفه مطفأ ⇒ 0.
 *   - «الزوج/الزوجة يعمل» ✓ **أو** زرّ «زيادة الزوج: لا تُعطى» بملفه ⇒ تُحذف **زيادة
 *     الزوج** (فرق «متأهل بلا أولاد» عن «عازب»، حالياً 225 مليون) — فيبقى التنزيل
 *     الشخصي + حصص الأولاد فقط. (قانوناً المادة 31: الزيادة عن «الزوجة التي لا تعمل»؛
 *     المرأة لا تأخذها عن زوج قادر على العمل — القرار النهائي بيد المستخدم عبر الزرّ.)
 * $asOf: تاريخ السريان (أحدث قيم effective_from ≤ التاريخ).
 */
function familyDeductionAnnual($socialStatus, $spouseWorks, $applyFlag, $asOf, $grantSpouseAdd = 0, $grantChildrenAdd = 0, $employeeId = 0) {
    if ((int)($applyFlag ?? 1) !== 1) return 0;
    try {
        $db = getDB();
        $socialStatus = (string)$socialStatus;
        // 📅 التأريخ التلقائي («اذا الاولاد تحت 18 واذا اكتر خلص — يشيل التنزيل من تاريخ بلوغ
        // 18، والزوج اذا اصبح يعمل من تاريخ بدء العمل» — 2026-08-23): عند تمرير رقم الموظف:
        //   ١) أولاده المؤرَّخون (employee_children): يُحتسب فقط من لم يبلغ 18 في أول الشهر
        //      المطلوب ($asOf) — فيسقط تنزيل كل ولد تلقائياً من شهر بلوغه الـ18.
        //   ٢) «تاريخ بدء عمل الزوج» (spouse_work_start_date): من ذاك التاريخ تُسقَط زيادة
        //      الزوج تلقائياً. من لا أولاد مؤرَّخين له يبقى على فئة وضعه العائلي كما هي.
        if ((int)$employeeId > 0) {
            $eid = (int)$employeeId;
            $fdKids = []; $fdSws = null;
            try {
                ensureEmployeeChildren20260823();
                $kq = $db->prepare("SELECT birth_date FROM employee_children WHERE employee_id = ?");
                $kq->execute([$eid]);
                $fdKids = $kq->fetchAll(PDO::FETCH_COLUMN);
                $sq = $db->prepare("SELECT spouse_work_start_date FROM employees WHERE id = ?");
                $sq->execute([$eid]);
                $sw = $sq->fetchColumn();
                $fdSws = ($sw && $sw !== '0000-00-00') ? $sw : null;
            } catch (Throwable $e) {}
            if ($fdKids && preg_match('/^(marie|veuf|divorce)/', $socialStatus, $mPre)) {
                $n = 0;
                foreach ($fdKids as $dob) {
                    if (date('Y-m-d', strtotime($dob . ' +18 years')) > $asOf) $n++;
                }
                $n = min(5, $n);
                $socialStatus = $mPre[1] . ($n === 0 ? '_sans_enfants' : ($n === 1 ? '_1_enfant' : '_' . $n . '_enfants'));
            }
            if ($fdSws !== null && $asOf >= $fdSws) $spouseWorks = 1;
        }
        // 🖤 الأرمل/المطلق (2026-08-23 — أرملتا سيدة النجاة): جدول القانون فيه فئات المتزوج فقط،
        // فالأرمل يُحسب على فئة المتزوج المقابلة **بلا زيادة الزوج دائماً** (لا زوج): الشخصي
        // + حصة الأولاد (تتبع مفتاح «تنزيل الأولاد» كالمعتاد). veuf/divorce بلا أولاد = الشخصي.
        if (strpos($socialStatus, 'veuf') === 0 || strpos($socialStatus, 'divorce') === 0) {
            $mapped = preg_replace('/^(veuf|divorce)/', 'marie', $socialStatus);
            $socialStatus = (strpos($mapped, 'marie_') === 0) ? $mapped : 'marie_sans_enfants';
            $spouseWorks = 1; // يُسقط زيادة الزوج حكماً
        }
        $q = $db->prepare("SELECT annual_deduction FROM family_tax_deductions
                           WHERE social_status = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1");
        $q->execute([(string)$socialStatus, $asOf]);
        $ded = (float)($q->fetchColumn() ?: 0);
        // 🔴 «تنزيل الأولاد: لا يُعطى» (2026-08-23): تُحذف حصة الأولاد كاملة (فرق وضعه عن
        // «متزوج بلا أولاد») حتى لو وضعه العائلي فيه أولاد — يبقى الشخصي (+ زيادة الزوج إن حقّت)
        if ((int)($grantChildrenAdd ?? 1) !== 1 && strpos((string)$socialStatus, 'marie') === 0) {
            $q->execute(['marie_sans_enfants', $asOf]);
            $married0k = (float)($q->fetchColumn() ?: 0);
            $ded = min($ded, $married0k > 0 ? $married0k : $ded);
        }
        if ((!empty($spouseWorks) || (int)($grantSpouseAdd ?? 1) !== 1) && strpos((string)$socialStatus, 'marie') === 0) {
            $q->execute(['marie_sans_enfants', $asOf]);
            $married0 = (float)($q->fetchColumn() ?: 0);
            $q->execute(['celibataire', $asOf]);
            $single = (float)($q->fetchColumn() ?: 0);
            $ded = max($single, $ded - max(0, $married0 - $single));
        }
        return (int)round($ded);
    } catch (Exception $e) { return 0; }
}

/** ضريبة الدخل السنوية بشطور البرنامج المؤرّخة (تُستعمل للتحقّق من المحسومات المنقولة). */
function annualLawTaxAsOf($db, $annualTaxable, $socialStatus, $m, $y) {
    if ($annualTaxable <= 0) return 0;
    $asOf = sprintf('%04d-%02d-01', $y, $m);
    $st = $db->prepare("SELECT annual_deduction FROM family_tax_deductions WHERE social_status = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1");
    $st->execute([$socialStatus, $asOf]);
    $rem = max(0, $annualTaxable - (float)($st->fetchColumn() ?: 0));
    if ($rem <= 0) return 0;
    $st = $db->prepare("SELECT * FROM tax_brackets WHERE effective_from = (SELECT MAX(effective_from) FROM tax_brackets WHERE effective_from <= ?) ORDER BY bracket_number ASC");
    $st->execute([$asOf]);
    $tax = 0;
    foreach ($st->fetchAll() as $b) {
        $size = $b['annual_to'] ? ($b['annual_to'] - $b['annual_from']) : PHP_INT_MAX;
        $in = min($rem, $size);
        if ($in <= 0) break;
        $tax += $in * ((float)$b['rate_percent'] / 100);
        $rem -= $in;
        if ($rem <= 0) break;
    }
    return $tax;
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-04د — الفحص الشامل خطوة خطوة): المحسومات المنقولة
 * المخزّنة كمجموع بلا تفصيل — الفرق الموجب (المجموع − المكوّنات) هو **ضريبة الدخل** التي
 * حسمها البرنامج القديم. الإثبات (لا تخمين):
 *   • 2025-2026: 373/384 صفاً طابقوا شطور القانون ±2% على ×12 شهراً (طريقة القديم).
 *   • 2023-2024: نفس الراتب ⇒ نفس الفرق، والفرق تصاعدي مع الراتب (هامشياً 7% ثم 11%)
 *     ومتغيّر مع الوضع العائلي = سلوك ضريبة بشطور 2023 القديمة (غير مزروعة بالبرنامج).
 *   • لا يوجد نوع حسم آخر ممكن للمتعاقد/الموظف: الضمان بعموده والصندوق للملاك فقط.
 * ننسب الفرق لعمود الضريبة ونملأ «الأساس الخاضع» بالإجمالي، فتكتمل الأعمدة بكل البطاقات
 * والتقارير («الأرقام تركب») ويدخل المحسوم فعلاً بتقارير الضريبة. المجموع والصافي لا يتغيّران.
 */
function healImportedTaxColumn20260804d() {
    $flag = 'imported_tax_column_fix_2026_08_04d3'; // d3: يشمل 2023-2024 وطانيوس (إثبات بنيوي) + الأساس الخاضع
    if (getSetting($flag, '') !== '') return;
    if (isViewer()) return; // حسابات «قراءة فقط» لا تكتب شيئاً
    try {
        $db = getDB();
        @set_time_limit(300);
        // المرشّحون: فرق محسومات موجب غير منسوب، أو ضريبة منسوبة سابقاً بلا أساس خاضع
        $rows = $db->query("SELECT ms.id, ms.total_retenues_lbp, ms.caisse_amount_lbp, ms.cnss_amount_lbp,
                ms.income_tax_lbp, ms.taxable_base_lbp, COALESCE(ms.eoc_grade_lbp, 0) eoc, ms.base_plus_echelon_lbp,
                ms.extra_lbp, ms.prime_fixe_lbp, ms.aide_complementaire_lbp
            FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
              AND COALESCE(e.base_salary_usd, 0) = 0 AND COALESCE(e.contract_salary_lbp, 0) = 0
              AND (ms.total_retenues_lbp - (ms.caisse_amount_lbp + ms.cnss_amount_lbp + ms.income_tax_lbp + COALESCE(ms.eoc_grade_lbp, 0)) > 1
                   OR (ms.income_tax_lbp > 0 AND ms.taxable_base_lbp < ms.income_tax_lbp))")->fetchAll();
        $upd = $db->prepare("UPDATE monthly_salaries SET income_tax_lbp = income_tax_lbp + ?, taxable_base_lbp = ? WHERE id = ?");
        $n = 0;
        foreach ($rows as $r) {
            $resid = (int)$r['total_retenues_lbp'] - ((int)$r['caisse_amount_lbp'] + (int)$r['cnss_amount_lbp'] + (int)$r['income_tax_lbp'] + (int)$r['eoc']);
            if ($resid < 0) continue; // مجموع أصغر من مكوّناته = حالة مختلفة، لا تُمسّ
            $brut = (int)$r['base_plus_echelon_lbp'] + (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp'] + (int)$r['aide_complementaire_lbp'];
            $upd->execute([($resid > 1 ? $resid : 0), max($brut, (int)$r['taxable_base_lbp']), $r['id']]);
            $n++;
        }
        setSetting($flag, date('Y-m-d H:i') . " ($n صفّاً)");
    } catch (Throwable $e) { /* لا نكسر الصفحة — يُعاد عند الفتح التالي */ }
}

/**
 * 🩹 شفاء ذاتي مرّة واحدة (2026-08-04هـ — الفحص الشامل): 6 موظفين منقولين كان عمود
 * «صافي الدولار» عندهم يحمل **راتبهم الفريش دولار الحقيقي** من البرنامج القديم
 * (مثلاً 962$ مقابل صافي ليرة 1م) بينما العمود بالبرنامج مرآةُ عرضٍ فقط (الصافي ÷ السعر)
 * — فكانت البطاقة تعرض مجموعاً بالدولار يخالف أشهرها. المعالجة بلا فقدان أي معلومة:
 * (١) يُحفَظ الرقم الحقيقي في «ملاحظات» ملف الموظف موثَّقاً بسنته،
 * (٢) ثم يُوحَّد العمود على المرآة كسائر البرنامج فتتماسك البطاقات والتقارير.
 */
function healFreshUsdColumn20260804e() {
    $flag = 'fresh_usd_column_fix_2026_08_04e';
    if (getSetting($flag, '') !== '') return;
    if (isViewer()) return; // حسابات «قراءة فقط» لا تكتب شيئاً
    try {
        $db = getDB();
        @set_time_limit(300);
        $rows = $db->query("SELECT ms.employee_id, ms.school_year, ms.net_salary_usd, COUNT(*) n
            FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND ms.exchange_rate > 0 AND ms.net_salary_lbp > 0 AND ms.net_salary_usd <> 0
              AND ABS(ms.net_salary_usd - ms.net_salary_lbp / ms.exchange_rate) > 0.06
            GROUP BY ms.employee_id, ms.school_year, ms.net_salary_usd")->fetchAll();
        $byEmp = [];
        foreach ($rows as $r) $byEmp[(int)$r['employee_id']][] = $r['school_year'] . ': ' . rtrim(rtrim(number_format((float)$r['net_salary_usd'], 2, '.', ''), '0'), '.') . '$';
        $marker = 'نُقل من عمود صافي الدولار';
        foreach ($byEmp as $eid => $vals) {
            $chk = $db->prepare("SELECT COALESCE(notes, '') FROM employees WHERE id = ?");
            $chk->execute([$eid]);
            $notes = (string)$chk->fetchColumn();
            if (strpos($notes, $marker) === false) {
                $add = "📌 راتب فريش دولار من البرنامج القديم (" . implode(' · ', array_unique($vals)) . ") — $marker "
                     . "بعد توحيده على مرآة الليرة (2026-08-04)؛ الرقم الحقيقي محفوظ هنا.";
                $db->prepare("UPDATE employees SET notes = TRIM(CONCAT(COALESCE(notes, ''), '\n', ?)) WHERE id = ?")->execute([$add, $eid]);
            }
        }
        $n = $db->exec("UPDATE monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
            SET ms.net_salary_usd = ROUND(ms.net_salary_lbp / ms.exchange_rate, 2)
            WHERE e.is_deleted = 0 AND ms.exchange_rate > 0 AND ms.net_salary_lbp > 0
              AND ABS(ms.net_salary_usd - ms.net_salary_lbp / ms.exchange_rate) > 0.06");
        setSetting($flag, date('Y-m-d H:i') . ' (' . count($byEmp) . " موظف / $n صفّاً)");
    } catch (Throwable $e) { /* لا نكسر الصفحة — يُعاد عند الفتح التالي */ }
}

// جملة SQL لتقييد التقرير بالمدارس المختارة (آمنة لأنها أرقام)
function reportSchoolSql($column = 'ms.school_id') {
    $ids = selectedReportSchoolIds();
    if (empty($ids)) $ids = allActiveSchoolIdsCached(); // «كل المدارس» = الفاعلة فقط
    if (empty($ids)) return ' ';
    $in = implode(',', array_map('intval', $ids));
    return " AND {$column} IN ({$in}) ";
}

// هل التقرير يشمل أكثر من مدرسة؟ (لإظهار عمود المدرسة)
function reportIsMultiSchool() {
    $ids = selectedReportSchoolIds();
    if (empty($ids)) {
        // "الكل": متعدد إذا في أكثر من مدرسة **فاعلة** فعلاً
        return count(allSchools()) > 1;
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
 * 1) إن كان `schools.logo_path` مضبوطاً والملف موجود → يُستعمل (شعار خاص).
 * 2) وإلا: مدارس/مراكز «مكسيموس» تأخذ شعارها الخاص `assets/logos/maximos.*`
 *    (شعار م.س.أ الموحّد على إفادات مكسيموس غلط — طلب المستخدم 2026-08-03).
 * 3) وإلا الشعار الموحّد `assets/logos/unified.(png|jpg|jpeg|svg)` إن وُجد.
 * 4) وإلا فراغ (تظهر الترويسة بالاسم فقط).
 */
function schoolLogoUrl($school) {
    $base = dirname(__DIR__);
    $lp = is_array($school) ? trim((string)($school['logo_path'] ?? '')) : '';
    if ($lp !== '' && is_file($base . '/' . ltrim($lp, '/'))) return BASE_URL . ltrim($lp, '/');
    $nameAr = is_array($school) ? (string)($school['name_ar'] ?? '') : '';
    $nameFr = is_array($school) ? (string)($school['name_fr'] ?? '') : '';
    if (mb_strpos($nameAr, 'مكسيموس') !== false || stripos($nameFr, 'maxim') !== false) {
        foreach (['png', 'jpg', 'jpeg', 'svg'] as $e) {
            if (is_file($base . "/assets/logos/maximos.$e")) return BASE_URL . "assets/logos/maximos.$e";
        }
    }
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
/**
 * 🔴 سرّ روابط الأساتذة (2026-07-30): كان السرّ نصّاً ثابتاً داخل الكود، ومستودع الكود
 * **عام** على GitHub — فأي شخص يستطيع حساب رابط كل أستاذ (١٦٨١ موظفاً) وفتح بياناته
 * الشخصية (الاسم، الولادة، اسم الأم، العنوان الكامل، الهواتف، رقم الضمان والرقم المالي).
 * الآن السرّ **عشوائي لكل تنصيب**، يُولَّد ذاتياً ويُخزَّن في قاعدة البيانات (خارج الكود)
 * فلا يُحسَب من الكود المنشور.
 * ⚠️ الروابط المُرسَلة سابقاً تتوقّف — يُرسَل رابط جديد من صفحة «تحديث معلومات الأساتذة».
 */
function infoFormSecret() {
    static $sec = null;
    if ($sec !== null) return $sec;
    $sec = (string)getSetting('info_form_secret', '');
    if ($sec === '' || strlen($sec) < 32) {
        try { $sec = bin2hex(random_bytes(32)); }
        catch (Exception $e) { $sec = hash('sha256', uniqid('', true) . microtime(true)); }
        setSetting('info_form_secret', $sec);
    }
    return $sec;
}

function infoFormToken($empId) {
    return substr(hash_hmac('sha256', (string)((int)$empId) . '|info', infoFormSecret()), 0, 24);
}

/**
 * توكن رابط المدرسة (رابط واحد للمجموعة): الأستاذ يفتحه ويختار اسمه.
 */
function schoolFormToken($schoolId) {
    return substr(hash_hmac('sha256', (string)((int)$schoolId) . '|school', infoFormSecret()), 0, 24);
}

/**
 * توكن الرابط الموحّد لكل المدارس: الأستاذ يختار مدرسته ثم اسمه.
 */
function allFormToken() {
    return substr(hash_hmac('sha256', 'ALL|school', infoFormSecret()), 0, 24);
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

/**
 * 🔒 رجوع آمن بعد المبدّلات (العملة/السنة/المدرسة/اللغة): يقبل الـReferer فقط إن كان
 * من نفس الموقع — كان أي رابط خارجي يستدعي مبدّلاً فيُرجَع المستخدم إلى الموقع الخارجي.
 */
function safeBackUrl($fallback = null) {
    $fallback = $fallback ?: (BASE_URL . 'index.php');
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref === '') return $fallback;
    $rHost = parse_url($ref, PHP_URL_HOST);
    $myHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!$rHost || !$myHost || strcasecmp((string)$rHost, $myHost) !== 0) return $fallback;
    return $ref;
}

/**
 * 🔒 حماية موحّدة لأي عملية تعديل تُنفَّذ عبر رابط (GET) — تُستدعى في بداية كل فرع
 * يعدّل/يحذف/يعيد الحساب بلا فورم POST (احتساب، ترقية، حذف بندٍ، حذف سعر صرف…).
 * تمنع أمرين معاً:
 *   1) صلاحية: حساب «قراءة فقط» (viewer) لا يعدّل شيئاً — قفل الـviewer المركزي كان يرى
 *      الـGET قراءةً فيمرّ، فكان بإمكان حساب مدرسة إعادة حساب كل رواتب مدرسته من الكشف السنوي.
 *   2) CSRF: الطلب يجب أن يكون من داخل البرنامج نفسه (same-origin) — فلا ينفّذ رابطٌ
 *      أو صورةٌ في بريد/موقع خارجي عمليةً بحساب المدير وهو فاتح البرنامج.
 * تُطبَّق على كل البرنامج (feedback-apply-fixes-program-wide).
 */
function requireWriteAction($redirect = null) {
    $back = $redirect ?: (BASE_URL . 'index.php');
    if (!canEdit()) {
        $_SESSION['flash_error'] = 'قراءة فقط: التعديل غير مسموح. / Lecture seule : modification interdite.';
        header('Location: ' . $back);
        exit;
    }
    // مصدر الطلب: نقبل same-origin (المتصفحات الحديثة ترسل Sec-Fetch-Site)، أو Referer من نفس الموقع.
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sfs !== '' && !in_array($sfs, ['same-origin', 'same-site', 'none'], true)) {
        $_SESSION['flash_error'] = 'طلب غير صالح (مصدر خارجي) / Requête externe refusée.';
        header('Location: ' . $back);
        exit;
    }
    if ($sfs === '') { // متصفح قديم: تحقّق من الـReferer
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref !== '') {
            $rHost = parse_url($ref, PHP_URL_HOST);
            $myHost = $_SERVER['HTTP_HOST'] ?? '';
            if ($rHost && $myHost && strcasecmp((string)$rHost, preg_replace('/:\d+$/', '', $myHost)) !== 0) {
                $_SESSION['flash_error'] = 'طلب غير صالح (مصدر خارجي) / Requête externe refusée.';
                header('Location: ' . $back);
                exit;
            }
        }
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
    // «بس اضغط زر طبع PDF ما بيطبع — ظبطها تطبع» (2026-08-21): view=1 → الـPDF يفتح بتبويب
    // جديد وشاشة الطباعة تطلع لحالها (كان ينزّل الملف بصمت عالـDownloads فيبدو أن ما صار شي)
    $officialPdf = ($relTarget && strpos($relTarget, 'print_pdf.php') === false)
        ? BASE_URL . 'pages/print_pdf.php?target=' . rawurlencode($relTarget) . '&name=' . rawurlencode($pdfName) . '&view=1'
        : '';
    ob_start(); ?>
    <div class="export-toolbar no-print no-export" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
        <?php if (!$viewerOnly): ?>
        <button type="button" class="btn btn-sm btn-primary" onclick="ppPrint()"><i class="fas fa-print"></i> Imprimer / طباعة</button>
        <?php endif; ?>
        <?php if ($officialPdf): ?>
        <a class="btn btn-sm btn-danger" href="<?= htmlspecialchars($officialPdf, ENT_QUOTES) ?>" target="_blank" title="PDF رسمي طبق الأصل — يفتح ويطبع"><i class="fas fa-file-pdf"></i> PDF<?= $viewerOnly ? '' : ' رسمي' ?></a>
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
/* 💬 تفقيط بالإنكليزي (بطلب المستخدم 2026-08-20 — إفادة السفارة): 1500 → One Thousand Five Hundred */
function numToEnglishWords($num): string {
    $num = (int)round((float)$num);
    if ($num == 0) return 'Zero';
    if ($num < 0) return 'Minus ' . numToEnglishWords(-$num);
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
             'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $below1000 = function (int $x) use ($ones, $tens): string {
        $out = [];
        if ($x >= 100) { $out[] = $ones[intdiv($x, 100)] . ' Hundred'; $x %= 100; }
        if ($x >= 20) {
            $t = $tens[intdiv($x, 10)];
            $out[] = ($x % 10) ? $t . '-' . $ones[$x % 10] : $t;
        } elseif ($x > 0) {
            $out[] = $ones[$x];
        }
        return implode(' ', $out);
    };
    $parts = [];
    foreach ([[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand'], [1, '']] as [$div, $label]) {
        if ($num >= $div) {
            $q = intdiv($num, $div); $num %= $div;
            $parts[] = trim($below1000($q) . ($label !== '' ? ' ' . $label : ''));
        }
    }
    return implode(' ', $parts);
}

/* 💬 تفقيط بالفرنسي (بطلب المستخدم 2026-08-20 — إفادة السفارة الفرنسية):
 * 1500 → mille cinq cents — بقواعد الفرنسية (et un / soixante-dix / quatre-vingts / cents) */
function numToFrenchWords($num): string {
    $num = (int)round((float)$num);
    if ($num == 0) return 'zéro';
    if ($num < 0) return 'moins ' . numToFrenchWords(-$num);
    $ones = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze',
             'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    $below100 = function (int $x) use ($ones): string {
        if ($x < 20) return $ones[$x];
        $names = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];
        $t = intdiv($x, 10); $u = $x % 10;
        if ($t <= 6) return $u == 1 ? $names[$t] . ' et un' : $names[$t] . ($u ? '-' . $ones[$u] : '');
        if ($t == 7) return $x == 71 ? 'soixante et onze' : 'soixante-' . $ones[$x - 60];
        if ($x == 80) return 'quatre-vingts';
        return 'quatre-vingt-' . $ones[$x - 80];
    };
    $below1000 = function (int $x) use ($below100): string {
        if ($x < 100) return $below100($x);
        $h = intdiv($x, 100); $r = $x % 100;
        $cent = ($h == 1) ? 'cent' : $below100($h) . ' cent' . ($r == 0 ? 's' : '');
        return $cent . ($r ? ' ' . $below100($r) : '');
    };
    $parts = [];
    foreach ([[1000000000, 'milliard', true], [1000000, 'million', true], [1000, 'mille', false]] as [$div, $label, $plural]) {
        if ($num >= $div) {
            $q = intdiv($num, $div); $num %= $div;
            if ($label === 'mille') $parts[] = ($q == 1) ? 'mille' : $below1000($q) . ' mille';
            else $parts[] = $below1000($q) . ' ' . $label . (($plural && $q > 1) ? 's' : '');
        }
    }
    if ($num > 0) $parts[] = $below1000($num);
    return implode(' ', $parts);
}

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

/* ✍️ (2026-08-25) قاعدة «بدون الفراطات — داون للرقم»: كل مبلغ دولار يُعرض صحيحاً
   بالتدوير لتحت (20.5 ⇒ 20). التخزين/الضرائب/ملفات الوزارة (بالليرة) لا تُمسّ. */
function formatUSD($amount, $withCurrency = true) {
    $formatted = number_format(floor((float)$amount), 0, '.', ',');
    return $withCurrency ? '$' . $formatted : $formatted;
}

// =====================================================
// عرض العملة (ليرة/دولار/الاثنين) — زرّ خيار موحّد لكل التقارير والملفات والإفادات
// =====================================================
/**
 * وضع عرض العملة المختار من المستخدم عبر مبدّل الترويسة:
 *   'both' (افتراضي) = ليرة والدولار تحتها | 'lbp' = ليرة فقط | 'usd' = دولار فقط.
 */
function displayCurrency(): string {
    $c = $_SESSION['display_currency'] ?? 'both';
    return in_array($c, ['both', 'lbp', 'usd'], true) ? $c : 'both';
}

/** تحويل مبلغ مخزّن بالليرة إلى دولار حسب سعر صرف معطى (سعر شهر الراتب).
 *  ✍️ (2026-08-25) «بدون الفراطات»: التحويل ينزل لدولار صحيح (تدوير لتحت دائماً)
 *  — فتصير المجاميع جمعَ الأرقام المدوّرة نفسها (قاعدة «الأرقام تركب»). */
function lbpToUsd($lbp, $rate): float {
    $rate = (float)$rate;
    return $rate > 0 ? floor((float)$lbp / $rate) : 0.0;
}

/** التحويل المعاكس: مبلغ بالدولار → ليرة لبنانية بسعر صرف معطى.
 *  ✍️ (2026-08-28) بطلب المستخدم «بتحول من الدولار لللبناني ويطلع فراطات تعمل داون»:
 *  الناتج ليرة صحيحة بالتدوير لتحت دائماً — المصدر الواحد لكل تحويل دولار←ليرة
 *  (المحرّك: اتفاق direct_usd، العلاوات بالدولار، النقل اليومي بالدولار، والإفادات والمعاينات). */
function usdToLbp($usd, $rate): float {
    return floor((float)$usd * (float)$rate);
}

/** 🧮 (2026-08-28، شرحها المستخدم بمثال تيا نخلة — «قاعدة النسبة المئوية للأجر الإضافي»):
 *  الإضافي بالنسبة = (أساس الراتب بعد التدرّج ÷ السعر الرسمي القديم 1500) × النسبة٪
 *    ← داون للدولار (526.5 ⇒ 526) ← × سعر السوق (سعر شهر الراتب) ← داون للمليون ليرة
 *    (47,077,000 ⇒ 47,000,000 — «فراطات الليرة» = ما دون المليون هنا).
 *  متحقَّقة على كل ملاك البشارة بنسبة 45٪ (كارمن 40م، جورجيت 61م، روز 68م... نهاية 112م).
 *  بس يتغيّر الأساس (درجة جديدة) يتغيّر الإضافي تلقائياً — «إذا نحنا مطبّقين النسبة المئوية».
 *  السعر الرسمي قابل للتعديل بالإعدادات (official_usd_rate_lbp، الافتراضي 1500). */
/** السعر الرسمي القديم لقاعدة نسبة الإضافي — خيار بالإعدادات (طلبه «بدي 1500 يكون عندي خيار عدلها») */
function officialUsdRate(): float {
    $v = (float)getSetting('official_usd_rate_lbp', 1500);
    return $v > 0 ? $v : 1500.0;
}
/** نص السعر الرسمي للعرض (1500 أو ما عدّله المستخدم) */
function officialUsdRateLbl(): string {
    return rtrim(rtrim(number_format(officialUsdRate(), 2, '.', ''), '0'), '.');
}

function bonusPercentLbp($pct, $basePlusEchelonLbp, $marketRate): float {
    $official = officialUsdRate();
    $usd = floor(((float)$basePlusEchelonLbp / $official) * ((float)$pct / 100)); // داون للدولار
    $lbp = usdToLbp($usd, (float)$marketRate);                                    // داون لليرة
    return floor($lbp / 1000000) * 1000000;                                       // داون للمليون
}

/**
 * سعر صرف صف راتب: يفضّل اللقطة المخزّنة `exchange_rate` (الأدقّ لأنها سعر شهر الراتب)،
 * وإلا يجلب سعر شهر/سنة الصف، وإلا السعر الحالي.
 */
/**
 * 🛡️ حارس خطأ العملة (2026-07-30): مبلغٌ بالدولار كبيرٌ جداً يعني أنّ المستخدم كتب
 * مبلغ **الليرة** والعملة تركها دولاراً. حصل فعلاً: علاوة 54,000,000 «دولار» ولّدت
 * راتباً 3,624 مليار ليرة (كان على موظف تجربة محذوف، لكنّها قنبلة لو تكرّرت على موظف حقيقي).
 * يرجّع العملة المصحّحة، ويضبط $warn إن صحّحها.
 * الحدّ: 200,000 دولار شهرياً — أعلى بكثير من أي راتب واقعي، فلا يعترض الاستعمال الطبيعي.
 */
function sanitizeAmountCurrency(float $amount, string $currency, ?string &$warn = null): string {
    $warn = null;
    if ($currency === 'USD' && $amount > 200000) {
        $warn = 'المبلغ ' . number_format($amount) . ' كبير جداً كدولار — فُهم أنه بالليرة اللبنانية وصُحّحت العملة تلقائياً.';
        return 'LBP';
    }
    return $currency;
}

function rowRate(array $row): float {
    if (!empty($row['exchange_rate']) && (float)$row['exchange_rate'] > 0) return (float)$row['exchange_rate'];
    return (float)getExchangeRate($row['month'] ?? null, $row['year'] ?? null);
}

/**
 * 🔵 قيمة بالدولار لعمود دولار مخزَّن (2026-07-30): 1,251 صفّاً قديماً (2023-2025) مخزَّن
 * فيه سعر الصرف = 0 فصارت أعمدة الدولار المخزَّنة (net_salary_usd/total_due_usd) صفراً،
 * فتُطبَع «$0.00» بلائحة الرواتب والقسيمة مع أنّ مبلغ الليرة صحيح.
 * الحل عرضاً (بلا لمس البيانات): إن كان الدولار المخزَّن صفراً نحسبه من الليرة ÷ سعر الشهر.
 *   $usdKey مثل 'net_salary_usd' و $lbpKey مثل 'net_salary_lbp'
 */
function rowUsd(array $row, string $usdKey, string $lbpKey): float {
    // ✍️ (2026-08-25) «بدون الفراطات»: المرآة المخزّنة بسنتاتها تبقى كما هي بالقاعدة،
    // لكن ما يُعرض/يُدفع دولار صحيح بالتدوير لتحت (580.39 ⇒ 580)
    $u = (float)($row[$usdKey] ?? 0);
    if ($u > 0) return floor($u);
    $rate = rowRate($row);
    return $rate > 0 ? floor(((float)($row[$lbpKey] ?? 0)) / $rate) : 0.0;
}

/**
 * عرض مبلغ (مخزّن بالليرة) حسب وضع العملة المختار — الدالة الموحّدة لكل المستندات.
 *   $lbp  : المبلغ بالليرة (مصدر التخزين).
 *   $rate : سعر صرف شهر الراتب (مرّر $row['exchange_rate'] أو rowRate($row)). null → السعر الحالي.
 *   $opts : mode (تجاوز الوضع)، withCur (وحدة العملة، افتراضي true)،
 *           stacked (بوضع both: الدولار بسطر تحت — للجداول؛ false = بينهما شرطة، للنصوص).
 * يرجّع HTML جاهز. (للنصوص/الإكسل استعمل moneyText.)
 */
function money($lbp, $rate = null, array $opts = []): string {
    $mode    = $opts['mode']    ?? displayCurrency();
    $withCur = $opts['withCur'] ?? true;
    $stacked = $opts['stacked'] ?? true;
    if ($rate === null) $rate = getExchangeRate($opts['month'] ?? null, $opts['year'] ?? null);
    $lbpStr = formatLBP($lbp, $withCur);
    if ($mode === 'lbp') return $lbpStr;
    if ($mode === 'usd') return formatUSD(lbpToUsd($lbp, $rate), $withCur);
    // both: ليرة + دولار (الدولار يحمل علامة $ دائماً للوضوح حتى لو الليرة بلا وحدة)
    $usdStr = formatUSD(lbpToUsd($lbp, $rate), true);
    if ($stacked) {
        return $lbpStr . '<span class="money-usd">' . $usdStr . '</span>';
    }
    return $lbpStr . ' <span class="money-usd-inline">(' . $usdStr . ')</span>';
}

/**
 * عرض بند بالعملتين انطلاقاً من مجموعَي الليرة والدولار المتراكمَين مسبقاً (لصفوف المجاميع/الفوتر
 * حيث كل صف قد يكون بسعر صرف مختلف فيُجمع الدولار صفّاً صفّاً). $withCur: وحدة العملة.
 */
function dualFromUsd($lbp, $usd, bool $withCur = true): string {
    $m = displayCurrency();
    if ($m === 'lbp') return formatLBP($lbp, $withCur);
    if ($m === 'usd') return formatUSD($usd, $withCur);
    return formatLBP($lbp, $withCur) . '<span class="money-usd">' . formatUSD($usd, true) . '</span>';
}

// =====================================================
// تركيب «الراتب المركّب»: أي مكوّنات تُضاف للأساس (أساس+درجة) — زرّ خيار موحّد بالترويسة
// =====================================================
/** المكوّنات المختارة لإضافتها للأساس: مجموعة فرعية من ['extra','aide','transport']. */
function salaryComp(): array {
    $c = $_SESSION['salary_comp'] ?? ['extra', 'aide']; // الافتراضي: الأساسي + الإضافي + المكافأة/المساعدة
    return array_values(array_intersect((array)$c, ['extra', 'aide', 'transport']));
}
function salaryCompHas(string $k): bool { return in_array($k, salaryComp(), true); }

/** عدد أعمدة المكوّنات الظاهرة (إضافي/مكافأة/نقل) — لضبط colspan الجداول ديناميكياً حسب «الراتب يشمل». */
function compColsCount(bool $withTransport = true): int {
    $n = (salaryCompHas('extra') ? 1 : 0) + (salaryCompHas('aide') ? 1 : 0);
    if ($withTransport) $n += salaryCompHas('transport') ? 1 : 0;
    return $n;
}

/** الراتب المركّب بالليرة = أساس+درجة + المكوّنات المختارة (إضافي/مكافأة-مساعدة).
 *  🔴 قاعدة المستخدم (2026-08-06): تعويض النقل **لا يدخل بالمركّب أبداً** — النقل عمود
 *  مستقل يوضع قبل «الإجمالي المتوجب» فيُجمع فيه (خيار transport بالشريط يتحكّم بعموده فقط). */
function composedSalaryLbp(array $row): int {
    $s = (int)($row['base_plus_echelon_lbp'] ?? 0);
    if (salaryCompHas('extra'))     $s += (int)($row['extra_lbp'] ?? 0) + (int)($row['prime_fixe_lbp'] ?? 0);
    if (salaryCompHas('aide'))      $s += (int)($row['aide_complementaire_lbp'] ?? 0);
    return $s;
}

/**
 * شريط خانات اختيار ظاهر (متل شريط الإفادة) — يُعرَض فوق التقارير والملفات ليتحكّم المستخدم
 * بمكوّنات «الراتب المركّب» مباشرةً (يتغيّر فوراً عند الكبس). لا يظهر بالطباعة/التصدير.
 */
function salaryCompToolbar(): string {
    $sel  = salaryComp();
    $lang = $_SESSION['lang'] ?? 'fr';
    ob_start(); ?>
    <form method="get" action="<?= BASE_URL ?>switch_salarycomp.php" class="salcomp-bar no-print no-export">
        <span class="scb-label"><i class="fas fa-layer-group"></i> <?= $lang==='ar' ? 'الراتب المركّب يشمل:' : 'Le salaire composé inclut :' ?></span>
        <label class="scb-opt"><input type="checkbox" name="comp[]" value="extra" <?= in_array('extra',$sel,true)?'checked':'' ?> onchange="this.form.submit()"> + <?= $lang==='ar'?'الأجر الإضافي':'Supplément' ?></label>
        <label class="scb-opt"><input type="checkbox" name="comp[]" value="aide" <?= in_array('aide',$sel,true)?'checked':'' ?> onchange="this.form.submit()"> + <?= $lang==='ar'?'المكافأة والمساعدة':'Prime & aide' ?></label>
        <label class="scb-opt"><input type="checkbox" name="comp[]" value="transport" <?= in_array('transport',$sel,true)?'checked':'' ?> onchange="this.form.submit()"> <?= $lang==='ar'?'عمود تعويض النقل (يُجمع بالمستحق)':'Colonne transport (dans le dû)' ?></label>
        <span class="scb-hint"><?= $lang==='ar'?'(الأساس + الدرجة دائماً — النقل لا يدخل بالمركّب)':'(Base + échelon toujours — transport hors salaire composé)' ?></span>
    </form>
    <?php return ob_get_clean();
}

/** تسمية مختصرة لِما يشمله الراتب المركّب (للعناوين) — النقل ليس منه (عموده مستقل). */
function salaryCompLabel(): string {
    $names = ['extra' => 'الإضافي', 'aide' => 'المكافأة'];
    $sel = array_values(array_intersect(salaryComp(), ['extra', 'aide']));
    if (!$sel) return 'الأساسي فقط';
    return 'الأساسي + ' . implode(' + ', array_map(fn($k) => $names[$k], $sel));
}

/**
 * نسخة نصّية بحتة (بلا HTML) لعرض المبلغ حسب الوضع — للإفادات النصّية وتصدير Excel/Word.
 */
function moneyText($lbp, $rate = null, array $opts = []): string {
    $mode    = $opts['mode']    ?? displayCurrency();
    $withCur = $opts['withCur'] ?? true;
    if ($rate === null) $rate = getExchangeRate($opts['month'] ?? null, $opts['year'] ?? null);
    $lbpStr = formatLBP($lbp, $withCur);
    if ($mode === 'lbp') return $lbpStr;
    $usdStr = formatUSD(lbpToUsd($lbp, $rate), $withCur);
    if ($mode === 'usd') return $usdStr;
    return $lbpStr . ' (' . $usdStr . ')';
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
        'marie_5_enfants' => ['fr' => 'Marié 5 enfants', 'ar' => 'متزوج وله 5 أولاد'],
        'veuf_sans_enfants' => ['fr' => 'Veuf(ve) sans enfants', 'ar' => 'أرمل بلا أولاد'],
        'veuf_1_enfant' => ['fr' => 'Veuf(ve) 1 enfant', 'ar' => 'أرمل وله ولد'],
        'veuf_2_enfants' => ['fr' => 'Veuf(ve) 2 enfants', 'ar' => 'أرمل وله ولدان'],
        'veuf_3_enfants' => ['fr' => 'Veuf(ve) 3 enfants', 'ar' => 'أرمل وله 3 أولاد']
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
    // الحد الأدنى المؤرّخ («والحدود الادنى» 2026-08-26): أجر خاضع فعلي أدنى من الحدّ ⇒ يُرفع
    // إليه (الاشتراك يُحسب على الحد الأدنى). لا يسري على غير الخاضع أصلاً (base=0).
    if (($b['min_salary_lbp'] ?? null) !== null && $base > 0 && $base < (float)$b['min_salary_lbp']) {
        $base = (float)$b['min_salary_lbp'];
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
 * 🔵 النسبة السارية ككسر (0.03 لا 3) — للنماذج الرسمية التي تستنتج الأجر من الاشتراك
 * (الأجر = الاشتراك ÷ النسبة). كانت النِّسَب مكتوبة أرقاماً بالكود (0.03/0.085/0.06/0.11)
 * فلو عدّل المستخدم نسبةً من «النِّسَب حسب التاريخ» يبقى عمود الاشتراك صحيحاً (من العمود
 * المخزَّن) ويصير عمود الأجر خاطئاً — فيناقض النموذج نفسه أمام الضمان.
 */
function rateFrac($key, $month = null, $year = null, $default = 0) {
    $v = getRateAsOf($key, $month, $year, $default);
    return ((float)$v) / 100.0;
}

/** نسبة الضمان الإجمالية (المضمون + صاحب العمل) ككسر — لعمود «مجموع الأجور» بنموذج 190A. */
function cnssTotalFrac($month = null, $year = null) {
    return rateFrac('cnss_employee_rate', $month, $year, 3) + rateFrac('cnss_employer_rate', $month, $year, 8);
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
    // 🔴 (2026-08-28 — «ما عم يحفظ تغيير تاريخ المهلة»): الكتابة بالأرقام العربية (٣٠/٠٨/٢٠٢٦)
    // أو بمسافات حول الفواصل كانت ترجع null فيُتجاهل التاريخ بصمت مع رسالة نجاح كاذبة.
    // التطبيع أولاً: أرقام فرنسية + إزالة المسافات — يعمّ كل مستعملي الدالة.
    if (function_exists('arabicDigitsFr')) $s = arabicDigitsFr($s);
    $s = preg_replace('/\s+/u', '', $s);
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

/**
 * 🔴 السنة الدراسية **للكتابة** (حفظ علاوة/مكافأة/نقل): وضع «كل السنين» ليس سنةً
 * يمكن الحفظ فيها — كان يُخزَّن school_year='all' فلا يراه محرّك الرواتب أبداً
 * (يطابق '2025-2026' أو NULL) ولا يظهر بالمحرّر لاحقاً، فيظنّ المستخدم أنّ المبلغ
 * محفوظ ولا شيء يتغيّر. نُرجع السنة الحالية عندها.
 */
function writeSchoolYear() {
    $sy = activeSchoolYear();
    return ($sy === 'all') ? currentSchoolYear() : $sy;
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

    // 🪄 عرض «درجة كل سنتين» (طلبه 2026-08-29 — «بيكون أفضل بس انتبه ما تخرب الاحتساب»):
    // التخزين والحساب يبقيان بالأنصاف (0.5 كل سنة) **بلا أي تغيير**؛ هنا **عرضاً فقط** نلمّ كل نصفَي
    // تدرّج عادي متتاليين (نفس حالة الاحتساب) بسطر واحد «درجة عادية كاملة» بتاريخ اكتمالها (النصف الثاني).
    // الصح/الحذف على السطر الملموم يطالان النصفين معاً؛ النصف الأخير المفرد يبقى ظاهراً «نص درجة».
    $isPlainHalf = function ($h) {
        return $h['reason'] === 'biennial_promotion'
            && abs((float)($h['delta'] ?? ((float)$h['grade_after'] - (float)$h['grade_before'])) - 0.5) < 0.001
            && strpos((string)$h['notes'], 'تقديم') === false;
    };
    // الأنصاف العادية تُزاوَج بالتسلسل الزمني (الأول مع الثاني، الثالث مع الرابع…) حتى لو فصلت بينها
    // درجات استثنائية؛ السطر الملموم يظهر مكان النصف الثاني (تاريخ اكتمال الدرجة) ويُخفى الأول.
    $pairOf = [];        // فهرس النصف الثاني → صفّ النصف الأول
    $skip = [];          // فهارس الأنصاف الأولى المخفية
    $pending = null;     // فهرس نصف أول بانتظار نصفه الثاني
    foreach ($history as $i => $h) {
        if (!$isPlainHalf($h)) continue;
        if ($pending !== null && (int)$history[$pending]['counted'] === (int)$h['counted']) {
            $pairOf[$i] = $history[$pending]; $skip[$pending] = true; $pending = null;
        } else {
            $pending = $i;
        }
    }
    $display = [];
    foreach ($history as $i => $h) {
        if (isset($skip[$i])) continue;
        $display[] = ['h' => $h, 'pair' => $pairOf[$i] ?? null, 'merged' => isset($pairOf[$i])];
    }

    // نوع كل درجة بتسمية واضحة (الاستثنائية مخزّنة بـreason='' فنستدلّ عليها من law_reference/الملاحظة).
    $labelFor = function ($h) {
        $r = $h['reason'];
        if ($r === 'titularization')     return ['دخول الملاك', 'gold', true];
        if ($r === 'biennial_promotion') return [strpos((string)$h['notes'], 'تقديم') !== false ? 'تقديم التدرّج (نص درجة — قانون 223)' : 'درجة عادية (تشرين)', 'success', false];
        if ($r === 'manual')             return ['درجة يدوية (بقرارك)', 'secondary', false];
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
    <p class="text-muted" style="font-size:13px;margin:0 0 10px;line-height:1.9">
        🔒 <strong>كل اللائحة مقفولة للحماية.</strong> اكبس <strong>«تعديل»</strong> قدّام الدرجة التي تريد تغييرها —
        هي وحدها تنفتح (الصح «محسوبة؟» + التاريخ + المقدار)، وأوّل ما تغيّر شيئاً يظهر زرّ
        <strong>«حفظ» أخضر بنفس السطر</strong> — كبسة وحدة والحفظ فوري. و<strong>«حذف»</strong> يشيل الدرجة نهائياً (بتأكيد).
        ✅ = درجة محسوبة؛ شِيل الصح = تبقى ظاهرة بلا حساب. (درجة دخول الملاك ثابتة دائماً.)
        <?php if ($excludedCount): ?><br><span style="color:#c0392b;font-weight:700">حالياً <?= $excludedCount ?> درجة مستثناة (غير محسوبة).</span><?php endif; ?>
    </p>
    <form method="POST" id="gradeChecklistForm" action="<?= BASE_URL ?>pages/grades.php?employee_id=<?= (int)$emp['id'] ?>">
        <?= csrfField() ?>
        <input type="hidden" name="grade_save" value="1">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <?php if (!empty($history)): ?>
        <table class="table" id="gradeRowsTable">
            <thead><tr>
                <th style="text-align:center;width:70px">محسوبة؟</th>
                <th style="width:150px">التاريخ</th><th>نوع الدرجة</th><th style="text-align:center;width:90px">مقدارها</th>
                <th style="text-align:center;width:90px">الدرجة بعدها</th><th>الأساس القانوني</th>
                <th style="text-align:center;width:190px" class="no-print">إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach ($display as $drow):
                $h = $drow['h']; $pairRow = $drow['pair'];
                [$rlabel, $rcolor, $isTitul] = $labelFor($h);
                $counted = $isTitul || (int)$h['counted'] === 1;
                $amount  = ($h['delta'] !== null) ? (float)$h['delta'] : ((float)$h['grade_after'] - (float)$h['grade_before']);
                if ($pairRow) { $rlabel = 'درجة عادية كاملة (كل سنتين)'; $amount = 1.0; }
                elseif ($isTitul === false && $h['reason'] === 'biennial_promotion' && abs($amount - 0.5) < 0.001 && strpos((string)$h['notes'], 'تقديم') === false) { $rlabel = 'نص درجة عادية (تكتمل تشرين ' . ((int)substr($h['change_date'], 0, 4) + 1) . ')'; }
            ?>
                <tr class="<?= $isTitul ? '' : 'gr-locked' ?>" style="<?= !$counted ? 'opacity:.55;background:#fbfbfb' : '' ?>">
                    <td style="text-align:center">
                        <?php if ($isTitul): ?>
                            <i class="fas fa-lock text-muted" title="درجة دخول الملاك — ثابتة دائماً"></i>
                        <?php else: ?>
                            <input type="checkbox" name="keep[]" value="<?= (int)$h['id'] ?>" <?= $counted ? 'checked' : '' ?> tabindex="-1" style="width:20px;height:20px;cursor:pointer"<?= $pairRow ? ' data-pair="' . (int)$pairRow['id'] . '"' : '' ?>>
                            <?php if ($pairRow): /* النصف الأول (مخفي) يتبع صح السطر الملموم */ ?>
                            <input type="checkbox" name="keep[]" value="<?= (int)$pairRow['id'] ?>" <?= $counted ? 'checked' : '' ?> class="gr-pair-mirror" style="display:none">
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isTitul): ?>
                            <strong><?= formatDate($h['change_date']) ?></strong>
                        <?php else: ?>
                            <input type="date" name="gdate[<?= (int)$h['id'] ?>]" value="<?= e($h['change_date']) ?>" readonly
                                   class="form-control gr-field" style="max-width:145px;padding:4px 6px">
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $rcolor ?>"><?= e($rlabel) ?></span></td>
                    <td style="text-align:center">
                        <?php if ($isTitul || $pairRow): ?>
                            <span class="badge badge-success" <?= $pairRow ? 'title="نصّان: تشرين ' . (int)substr($pairRow['change_date'], 0, 4) . ' + تشرين ' . (int)substr($h['change_date'], 0, 4) . '"' : '' ?>>+<?= $fmtG($amount) ?></span>
                        <?php else: ?>
                            <input type="number" name="gamt[<?= (int)$h['id'] ?>]" value="<?= $fmtG($amount) ?>" step="0.5" min="-52" max="52" readonly
                                   class="form-control gr-field" style="max-width:75px;padding:4px 6px;text-align:center;margin:0 auto">
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center"><?= $counted ? '<strong>' . $fmtG($h['grade_after']) . '</strong>' : '<span class="text-muted" title="غير محسوبة">—</span>' ?></td>
                    <td><small><?php
                        // عمود الملاحظة: الأساس القانوني لكل درجة (لكل الحالات) —
                        //  • قانون استثنائي مسمّى (244/102/223/2017=قانون 46): «قانون X — الاسم».
                        //  • درجة استثنائية بلا قانون مسمّى = نظام الأساتذة الجدد 4+4+2 (يشمل دفعات فتح السنة).
                        //  • دخول الملاك: درجة الدخول حسب الشهادة.  • التدرّج العادي: التدرّج الدوري.
                        // نصّ مختصر يرتّب الجدول + التفصيل الكامل يظهر عند مرور الماوس (title)
                        $ref = (string)$h['law_reference'];
                        $rsn = $h['reason'];
                        if ($ref !== '' && isset($lawNames[$ref])) {
                            $li = $lawNames[$ref];
                            $issued = !empty($li['date']) ? ' (صدر بتاريخ ' . formatDate($li['date']) . ')' : '';
                            $noteShort = 'قانون ' . $ref;
                            $noteTxt = 'قانون ' . $ref . $issued . ' — ' . $li['name'];
                        } elseif ($rsn === 'titularization') {
                            $noteShort = 'درجة الدخول حسب الشهادة';
                            $noteTxt = 'درجة الدخول حسب الشهادة — سلسلة الرتب والرواتب (' . $scaleLaw . ') عند دخول الملاك';
                        } elseif ($rsn === 'biennial_promotion' && $pairRow) {
                            $noteShort = 'التدرّج الدوري (تشرين ' . (int)substr($pairRow['change_date'], 0, 4) . ' + تشرين ' . (int)substr($h['change_date'], 0, 4) . ') — ' . $scaleLaw;
                            $noteTxt = 'درجة عادية كاملة كل سنتين (نصفان: ' . formatDate($pairRow['change_date']) . ' و' . formatDate($h['change_date']) . ') — سلسلة الرتب والرواتب (' . $scaleLaw . ')';
                        } elseif ($rsn === 'biennial_promotion') {
                            $noteShort = 'التدرّج الدوري — ' . $scaleLaw;
                            $noteTxt = 'التدرّج الدوري نصف درجة كل سنة — سلسلة الرتب والرواتب (' . $scaleLaw . ')';
                        } elseif ($rsn === 'manual') {
                            $noteShort = $h['notes'] !== '' ? $h['notes'] : 'تعديل يدوي';
                            $noteTxt = $noteShort;
                        } else {
                            // نظام الأساتذة الجدد 4+4+2 = بديل قوانين الدرجات الاستثنائية للمستجدّين (بعد 2/4/2012).
                            $subs = [];
                            foreach (['244','102','223'] as $ln) {
                                if (!isset($lawNames[$ln])) continue;
                                $dt = !empty($lawNames[$ln]['date']) ? ' صادر ' . formatDate($lawNames[$ln]['date']) : '';
                                $subs[] = 'قانون ' . $ln . $dt;
                            }
                            $noteShort = 'نظام الأساتذة الجدد (4+4+2)';
                            $noteTxt = 'درجات الأساتذة الجدد (نظام 4+4+2) — بموجب '
                                     . ($subs ? implode(' · ', $subs) : 'قوانين الدرجات الاستثنائية')
                                     . ' لمن دخل الملاك بعد 2012';
                        }
                        echo '<span title="' . e($noteTxt) . '" style="cursor:help;border-bottom:1px dotted #cbd5e1">' . e($noteShort) . '</span>';
                    ?></small></td>
                    <td style="text-align:center" class="no-print">
                        <?php if ($isTitul): ?>
                            <small class="text-muted">🔒 ثابتة</small>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-warning gr-edit" title="فتح تاريخ ومقدار هذه الدرجة للتعديل">
                                <i class="fas fa-pen"></i> تعديل
                            </button>
                            <button type="submit" class="btn btn-sm btn-success gr-save" style="display:none"
                                    title="يحفظ تغييراتك فوراً ويعيد حساب الراتب">
                                <i class="fas fa-save"></i> حفظ
                            </button>
                            <button type="submit" name="row_delete" value="<?= $pairRow ? (int)$pairRow['id'] . ',' . (int)$h['id'] : (int)$h['id'] ?>" class="btn btn-sm btn-danger"
                                    data-confirm="⚠️ حذف هذه الدرجة نهائياً؟ ستُعاد سلسلة الدرجات وحساب الراتب بدونها.">
                                <i class="fas fa-trash-alt"></i> حذف
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($grantable)): ?>
        <h4 style="color:var(--primary);margin:18px 0 6px"><i class="fas fa-plus-circle"></i> درجات استثنائية بعدها ما أُعطيت (متاحة لهذا الأستاذ)</h4>
        <p class="text-muted" style="font-size:12px;margin:0 0 8px"><strong>كل درجة لحالها.</strong> الشك-مارك فاضي = ما انعطت بعد. كبس الصح واختر تاريخ الإعطاء لكل درجة بدّك ياها فتُضاف وتُحسب.</p>
        <table class="table" id="gradeUnitsTable">
            <thead><tr>
                <th style="text-align:center">إعطاء؟</th><th>القانون / الدرجة</th><th style="text-align:center">مقدارها</th>
                <th>تاريخ الإعطاء</th><th>الوصف</th>
                <th style="text-align:center;width:160px" class="no-print">إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach ($grantable as $gb): $law = $gb['law']; $units = $gb['units']; $nU = count($units);
                foreach ($units as $i => $u): $key = $law['law_number'] . '__' . $i; ?>
                <tr class="gr-locked">
                    <td style="text-align:center"><input type="checkbox" name="gunit[]" value="<?= e($key) ?>" tabindex="-1" style="width:20px;height:20px;cursor:pointer"></td>
                    <td><strong>قانون <?= e($law['law_number']) ?></strong> <small class="text-muted">— درجة <?= $i + 1 ?>/<?= $nU ?></small></td>
                    <td style="text-align:center"><span class="badge badge-gold">+<?= $fmtG($u['delta']) ?></span></td>
                    <td><input type="date" name="gudate[<?= e($key) ?>]" value="<?= e($u['date']) ?>" readonly class="form-control gr-field" style="max-width:145px;padding:4px 6px"></td>
                    <td><small><?= e($law['description_ar'] ?: $law['description_fr']) ?></small></td>
                    <td style="text-align:center" class="no-print">
                        <button type="button" class="btn btn-sm btn-warning gr-edit" title="فتح هذه الدرجة (الصح والتاريخ) للإعطاء">
                            <i class="fas fa-pen"></i> تعديل
                        </button>
                        <button type="submit" class="btn btn-sm btn-success gr-save" style="display:none"
                                title="يحفظ تغييراتك فوراً ويعيد حساب الراتب">
                            <i class="fas fa-save"></i> حفظ
                        </button>
                    </td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <style>
        /* 🔒 كل الصفوف مقفولة افتراضياً: الصحّات لا تُكبس والحقول لا تُكتب حتى «تعديل» على الصفّ نفسه */
        #gradeChecklistForm tr.gr-locked input[type=checkbox]{pointer-events:none;opacity:.45;cursor:default}
        /* زرّ الحفظ الفوري يلفت النظر لمّا يظهر قدّام الشي المتغيّر */
        .gr-pulse{animation:grPulse .9s ease infinite alternate}
        @keyframes grPulse{from{box-shadow:0 0 0 0 rgba(22,163,74,.55)}to{box-shadow:0 0 0 7px rgba(22,163,74,0)}}
        </style>
        <script>
        // 🔒 كل اللائحة مقفولة افتراضياً — «تعديل» يفتح صفّه فقط (الصح + التاريخ + المقدار)،
        // وأوّل ما يتغيّر شيء ينبض زرّ «حفظ» الأخضر بنفس السطر. الحفظ فوري ويحفظ كامل
        // الحالة المعروضة (فلا يضيع تغيير صفّ آخر فتحتَه قبل الكبسة) ثم يُعاد حساب الراتب.
        (function () {
            var f = document.getElementById('gradeChecklistForm');
            if (!f || f.dataset.grInit) return;
            f.dataset.grInit = '1';
            f.addEventListener('click', function (e) {
                var btn = e.target.closest('.gr-edit');
                if (!btn) return;
                e.preventDefault();
                var tr = btn.closest('tr');
                tr.classList.remove('gr-locked');                     // فكّ قفل هذا الصفّ فقط
                tr.querySelectorAll('.gr-field').forEach(function (x) { x.readOnly = false; x.style.background = '#fef9c3'; });
                tr.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.removeAttribute('tabindex'); });
                var sv = tr.querySelector('.gr-save');
                if (sv) sv.style.display = '';
                btn.style.display = 'none';
            });
            function reveal(el) {
                var tr = el.closest ? el.closest('tr') : null;
                if (!tr || tr.classList.contains('gr-locked')) return; // الصفوف المقفولة لا تتغيّر أصلاً
                var sv = tr.querySelector('.gr-save');
                if (!sv) return;
                sv.style.display = '';
                sv.classList.add('gr-pulse');
            }
            f.addEventListener('change', function (e) {
                // السطر الملموم (درجة كل سنتين): صحّه يتبعه النصف الأول المخفي
                if (e.target.matches && e.target.matches('input[type=checkbox][data-pair]')) {
                    var m = e.target.closest('td').querySelector('.gr-pair-mirror');
                    if (m) m.checked = e.target.checked;
                }
                reveal(e.target);
            });
            f.addEventListener('input',  function (e) { reveal(e.target); });
        })();
        </script>

        <button type="submit" class="btn btn-primary" data-confirm="حفظ كل التغييرات (المحسوبة؟ + التواريخ + المقادير + الدرجات المُعطاة الجديدة) وإعادة حساب الراتب؟">
            <i class="fas fa-save"></i> حفظ الكل دفعة واحدة
        </button>
        <small class="text-muted d-block mt-1">نفس مفعول زرّ «حفظ» الأخضر الذي يظهر قدّام أي تغيير — الاثنان يحفظان كل ما هو معروض فوراً.</small>
    </form>

    <!-- ➕ إضافة درجة يدوية (خارج القانون، بقرار المستخدم) — نموذج مستقلّ -->
    <form method="POST" class="lockedit" action="<?= BASE_URL ?>pages/grades.php?employee_id=<?= (int)$emp['id'] ?>"
          style="margin-top:16px;padding:14px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc">
        <?= csrfField() ?>
        <input type="hidden" name="manual_add" value="1">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <h4 style="margin:0 0 4px;color:var(--primary)"><i class="fas fa-plus-circle"></i> أضف درجة يدوية (بقرارك، خارج القانون)</h4>
        <p class="text-muted" style="font-size:12px;margin:0 0 10px">
            درجة تحطّها انت بمقدار وتاريخ من اختيارك (تشرين 1/10 أو كانون 1/1 أو أي تاريخ). بمجرّد الحفظ
            <strong>تُضاف فوراً لأساس الراتب ويتدرّج الراتب من بعدها تلقائياً</strong>، وتقدر تشيلها لاحقاً بالشك-مارك.
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group mb-0">
                <label class="form-label" style="font-size:12px">مقدار الدرجة</label>
                <input type="number" name="manual_amount" value="1" step="0.5" min="0.5" max="52" class="form-control" style="max-width:110px" required>
            </div>
            <div class="form-group mb-0">
                <label class="form-label" style="font-size:12px">التاريخ</label>
                <input type="date" name="manual_date" value="<?= date('Y') ?>-10-01" class="form-control" style="max-width:170px" required>
            </div>
            <div class="form-group mb-0" style="flex:1;min-width:160px">
                <label class="form-label" style="font-size:12px">ملاحظة (اختياري)</label>
                <input type="text" name="manual_note" class="form-control" placeholder="سبب الدرجة (اختياري)">
            </div>
            <button type="submit" class="btn btn-gold" data-confirm="إضافة درجة يدوية بهذا المقدار والتاريخ، وإعادة حساب الراتب؟">
                <i class="fas fa-plus"></i> أضف الدرجة
            </button>
        </div>
    </form>
    <?php
}

/**
 * رابط «الرجوع لنفس الصفحة اللي كنت فيها» بوضع عرض المستند (doc-view).
 * يلتقط مرجع الدخول أول ما يُفتح التقرير/النموذج ويحفظه بالجلسة، فيبقى زرّ الرجوع
 * يعيد المستخدم لصفحته الأصلية حتى لو غيّر الفلاتر (شهر/سنة/مدارس) داخل التقرير.
 * تغيير الفلاتر لا يغيّر «هوية المستند» (الصفحة + report/form) فلا يُلتقط كمرجع.
 */
function docBackUrl(string $fallback = ''): string {
    $fallback = $fallback !== '' ? $fallback : BASE_URL . 'pages/reports.php';
    $ref  = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $self = basename((string)parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));
    $docId = (string)($_GET['report'] ?? ($_GET['form'] ?? ''));
    if ($ref !== '' && $host !== '' && strpos($ref, '//' . $host . '/') !== false) {
        $sameDoc = $self !== '' && strpos($ref, $self) !== false
                 && ($docId === '' || strpos($ref, $docId) !== false);
        if (!$sameDoc) $_SESSION['doc_back'] = $ref;
    }
    return $_SESSION['doc_back'] ?? $fallback;
}

/**
 * 🧮 شفاء ذاتي مرّة واحدة (2026-08-28): تيا نخلة (البشارة) — أجرها الإضافي كان رقماً جامداً
 * غلطاً (48,060,000 = 540$×سعر قديم 89,000) والصحيح «نسبة مئوية 45٪» بقاعدة المستخدم
 * (÷1500 ← نسبة ← داون دولار ← سعر السوق ← داون للمليون) متل كل رفقاتها بالبشارة،
 * فتتحرّك مع درجتها تلقائياً (تشرين 47م → كانون 54م). يعمل بالاسم لا بالرقم (داتا الأونلاين).
 */
function healTiaPercentPrime20260828() {
    try {
        if (getSetting('heal_tia_percent_20260828', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) return;
        $emp = $db->prepare("SELECT id FROM employees WHERE school_id=? AND is_deleted=0
            AND first_name_ar='تيا' AND last_name_ar='نخلة' AND employee_type='enseignant_titulaire' LIMIT 1");
        $emp->execute([(int)$sid]);
        $eid = (int)$emp->fetchColumn();
        if (!$eid) { setSetting('heal_tia_percent_20260828', 'skip: not found'); return; }
        $b = $db->prepare("SELECT id, value_type, amount FROM employee_bonuses
            WHERE employee_id=? AND bonus_type='prime_fixe' AND school_year='2025-2026' AND is_active=1 LIMIT 1");
        $b->execute([$eid]);
        $row = $b->fetch();
        $log = 'emp=' . $eid;
        if ($row && !($row['value_type'] === 'percent' && (float)$row['amount'] === 45.0)) {
            setSetting('bk_tia_prime_20260828', json_encode(['bonus_id' => $row['id'], 'old_type' => $row['value_type'], 'old_amount' => $row['amount']]));
            $db->prepare("UPDATE employee_bonuses SET value_type='percent', amount=45, currency='LBP' WHERE id=?")->execute([$row['id']]);
            require_once __DIR__ . '/payroll_calculator.php';
            $n = recalcEmployeeYear($eid, '2025-2026');
            $log .= ' updated bonus=' . $row['id'] . ' recalc=' . $n;
        } else {
            $log .= $row ? ' already percent' : ' no prime row';
        }
        setSetting('heal_tia_percent_20260828', 'done: ' . $log);
    } catch (Throwable $e) {
        try { setSetting('heal_tia_percent_20260828', 'err: ' . mb_substr($e->getMessage(), 0, 180)); } catch (Throwable $e2) {}
    }
}

/**
 * 🧮 نسخة مشخِّصة (2026-08-28 ب): أونلاين طلع إضافي تيا 0 بعد الشفاء الأول — هذه النسخة
 * تقرأ حالتها الفعلية (بند العلاوة + سعر الصرف + ناتج القاعدة + شهر تشرين المخزّن)،
 * وإن كان المخزّن لا يطابق القاعدة تعيد الحساب (احتمال opcache قديم لحظة الشفاء الأول)
 * وتسجّل كل شيء نصاً بالفلاغ ليُقرأ من بطاقة «حالة الشفاءات» بصفحة فحص الصحة.
 */
function healTiaPercent2_20260828() {
    try {
        if (getSetting('heal_tia_percent2_20260828', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) { setSetting('heal_tia_percent2_20260828', 'skip: no school'); return; }
        $emp = $db->prepare("SELECT id FROM employees WHERE school_id=? AND is_deleted=0
            AND first_name_ar='تيا' AND last_name_ar='نخلة' AND employee_type='enseignant_titulaire' LIMIT 1");
        $emp->execute([(int)$sid]);
        $eid = (int)$emp->fetchColumn();
        if (!$eid) { setSetting('heal_tia_percent2_20260828', 'skip: no emp'); return; }
        $b = $db->prepare("SELECT id, value_type, amount, currency, school_year, is_active FROM employee_bonuses
            WHERE employee_id=? AND bonus_type='prime_fixe' ORDER BY is_active DESC, id DESC LIMIT 1");
        $b->execute([$eid]);
        $row = $b->fetch(PDO::FETCH_ASSOC) ?: [];
        $rate = getExchangeRate(10, 2025);
        $test = function_exists('bonusPercentLbp') ? bonusPercentLbp(45, 1755000, $rate) : -1;
        $m10 = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$eid AND month=10 AND year=2025 LIMIT 1")->fetchColumn();
        $log = 'emp=' . $eid . ' bonus=' . json_encode($row) . ' rate10/2025=' . $rate . ' ruleTest=' . $test . ' storedOct=' . $m10;
        if (($row['value_type'] ?? '') === 'percent' && (float)($row['amount'] ?? 0) === 45.0 && $test > 0 && $m10 !== (int)$test) {
            require_once __DIR__ . '/payroll_calculator.php';
            $n = recalcEmployeeYear($eid, '2025-2026');
            $m10b = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$eid AND month=10 AND year=2025 LIMIT 1")->fetchColumn();
            $log .= ' | re-recalc=' . $n . ' afterOct=' . $m10b;
        }
        setSetting('heal_tia_percent2_20260828', $log);
    } catch (Throwable $e) {
        try { setSetting('heal_tia_percent2_20260828', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🧮 النسخة الثالثة الحاسمة (2026-08-28 ج): التشخيص الأونلاين بيّن أن تيا نخلة بلا بند
 * prime_fixe إطلاقاً أونلاين (bonus=[] وأشهرها المخزّنة بإضافي 0) — النسختان السابقتان
 * تعدّلان بنداً موجوداً فقط. هذه تخلق البند إن غاب (نسبة 45٪ بقاعدة ÷1500، 2025-2026)
 * أو توحّده إن وُجد، ثم تعيد حساب سنتها، وتسجّل قبل/بعد بالفلاغ.
 */
function healTiaPercent3_20260828() {
    try {
        if (getSetting('heal_tia_percent3_20260828', '') !== '') return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) { setSetting('heal_tia_percent3_20260828', 'skip: no school'); return; }
        $emp = $db->prepare("SELECT id FROM employees WHERE school_id=? AND is_deleted=0
            AND first_name_ar='تيا' AND last_name_ar='نخلة' AND employee_type='enseignant_titulaire' LIMIT 1");
        $emp->execute([(int)$sid]);
        $eid = (int)$emp->fetchColumn();
        if (!$eid) { setSetting('heal_tia_percent3_20260828', 'skip: no emp'); return; }
        $before = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$eid AND month=10 AND year=2025 LIMIT 1")->fetchColumn();
        $b = $db->prepare("SELECT id FROM employee_bonuses WHERE employee_id=? AND bonus_type='prime_fixe' AND school_year='2025-2026' AND is_active=1 LIMIT 1");
        $b->execute([$eid]);
        $bid = (int)$b->fetchColumn();
        if ($bid) {
            $db->prepare("UPDATE employee_bonuses SET value_type='percent', amount=45, currency='LBP' WHERE id=?")->execute([$bid]);
            $act = 'updated ' . $bid;
        } else {
            $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year,
                amount, value_type, currency, start_month, end_month, is_active)
                VALUES (?, 'prime_fixe', 1, '2025-2026', 45, 'percent', 'LBP', NULL, NULL, 1)")->execute([$eid]);
            $act = 'inserted ' . $db->lastInsertId();
        }
        require_once __DIR__ . '/payroll_calculator.php';
        $n = recalcEmployeeYear($eid, '2025-2026');
        $oct = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$eid AND month=10 AND year=2025 LIMIT 1")->fetchColumn();
        $jan = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$eid AND month=1 AND year=2026 LIMIT 1")->fetchColumn();
        setSetting('heal_tia_percent3_20260828', "done: emp=$eid $act recalc=$n beforeOct=$before oct=$oct jan=$jan");
    } catch (Throwable $e) {
        try { setSetting('heal_tia_percent3_20260828', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🧮⚖️ «طبق القانون على كل البرنامج وعلى الجميع» (أمره 2026-08-28):
 * كل بند «أجر إضافي» مبلغاً ثابتاً (سنة كاملة، فعّال) يُحوَّل لنسبة مئوية بقاعدة ÷1500 —
 * **النسبة تُستنتَج بحيث تُطلِّع رقمه المخزّن نفسه بالضبط** (لا تغيير باليوم الحاضر؛
 * ومن الآن يتحرّك مع الأساس/الدرجة كقانون تيا). الغامض بين نسبتين يُرجَّح لنسبة مدرسته
 * الغالبة (عبرا 65٪، النجاة 55٪، البشارة 45٪...). من لا نسبة نظيفة له (اتفاق خاص) يُترَك
 * مبلغاً ويُدرَج بتقرير الاستثناءات بالفلاغ. المؤرّخة بفترات والبلا أساس تُترَك.
 * دفعات (batch) لئلا يطول الطلب أونلاين + نسخ احتياطية (بونص + أشهر المعنيين).
 */
function percentLawMatchSet(float $stored, float $bpe, float $rate): array {
    $set = [];
    if ($stored <= 0 || $bpe <= 0 || $rate <= 0) return $set;
    for ($p = 10; $p <= 1200; $p += 5) { // 1.0٪ .. 120.0٪ خطوة 0.5
        $pct = $p / 10.0; // float دائماً (كان 450/10 يطلع int فتفشل المطابقة الصارمة مع 45.0)
        if (bonusPercentLbp($pct, $bpe, $rate) == $stored) $set[] = (float)$pct;
    }
    return $set;
}
function healPercentLawAll20260828() {
    try {
        if (strpos((string)getSetting('heal_percent_law_20260828', ''), 'done') === 0) return;
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        // نسخ احتياطية (مرّة واحدة)
        $db->exec("CREATE TABLE IF NOT EXISTS _bk_bonuses_pctlaw0828 LIKE employee_bonuses");
        if (!(int)$db->query("SELECT COUNT(*) FROM _bk_bonuses_pctlaw0828")->fetchColumn()) {
            $db->exec("INSERT INTO _bk_bonuses_pctlaw0828 SELECT * FROM employee_bonuses");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_pctlaw0828 LIKE monthly_salaries");
        // ⚠️ تنبيه المستخدم الصريح: «في مدارس مش عاطيين نسبة مئوية — إذا حاطين هني المبلغ بيبقى».
        // لذلك: التحويل **لأساتذة الملاك فقط** وفي المدارس ذات النسبة الموحّدة الواضحة فقط
        // (نسبة غالبة تشمل ≥50٪ من بنود ملاك المدرسة وعددها ≥5)، ولمن رقمه المخزّن يطابق
        // نسبة مدرسته بالضبط. المتعاقدون والمدارس بلا نمط نسبة (مبالغ متفرقة) تبقى مبالغ ثابتة.
        $cand = $db->query("
            SELECT b.id bid, b.employee_id, b.amount, e.school_id,
                   CONCAT(e.first_name_ar,' ',e.last_name_ar) nm,
                   (SELECT ms.base_plus_echelon_lbp FROM monthly_salaries ms WHERE ms.employee_id=b.employee_id AND ms.school_year='2025-2026' ORDER BY ms.year, ms.month LIMIT 1) bpe,
                   (SELECT ms.exchange_rate FROM monthly_salaries ms WHERE ms.employee_id=b.employee_id AND ms.school_year='2025-2026' ORDER BY ms.year, ms.month LIMIT 1) rate
            FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id AND e.is_deleted=0
            WHERE b.bonus_type='prime_fixe' AND b.is_active=1 AND b.value_type='amount'
              AND e.employee_type='enseignant_titulaire'
              AND b.start_month IS NULL AND b.end_month IS NULL
              AND (b.school_year IS NULL OR b.school_year='2025-2026')
              -- 🛡️ محميّان: ريتا مارون وماريا الياس حليحل (عبرا) — مبلغاهما «سلفة» موثّقة
              -- على كشفه بالمليم بقراره الصريح (طابقا نسبة 65٪ صدفةً فتحوّلا غلطاً ورُجِعا)
              AND NOT (e.first_name_ar LIKE 'ريتا%' AND e.father_name_ar LIKE 'مارون%' AND e.last_name_ar LIKE '%حليحل%')
              AND NOT (e.first_name_ar LIKE 'ماريا%' AND e.father_name_ar LIKE 'الياس%' AND e.last_name_ar LIKE '%حليحل%')")->fetchAll(PDO::FETCH_ASSOC);
        // النسبة الغالبة لكل مدرسة (تمريرة أولى — تُحسب مجموعات التطابق كاملة)
        // 🔴 مهم: المحوَّلون نسبةً سابقاً يُحسبون بالغالبة أيضاً — وإلا كل دفعة تُنقص العدّ
        // فيضيع النمط قبل اكتمال المدرسة (كان يترك روز وايلي بلا تحويل بلا سبب).
        $freq = []; $cnt = []; $sets = [];
        foreach ($db->query("SELECT e.school_id, b.amount pct, COUNT(*) n
            FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id AND e.is_deleted=0
            WHERE b.bonus_type='prime_fixe' AND b.is_active=1 AND b.value_type='percent'
              AND e.employee_type='enseignant_titulaire'
              AND (b.school_year IS NULL OR b.school_year='2025-2026')
            GROUP BY e.school_id, b.amount") as $pr) {
            $freq[$pr['school_id']][(string)(float)$pr['pct']] = ($freq[$pr['school_id']][(string)(float)$pr['pct']] ?? 0) + (int)$pr['n'];
            $cnt[$pr['school_id']] = ($cnt[$pr['school_id']] ?? 0) + (int)$pr['n'];
        }
        foreach ($cand as $c) {
            $rate = (float)$c['rate'] > 0 ? (float)$c['rate'] : 89500.0;
            $s = percentLawMatchSet((float)$c['amount'], (float)$c['bpe'], $rate);
            $sets[$c['bid']] = $s;
            $cnt[$c['school_id']] = ($cnt[$c['school_id']] ?? 0) + 1;
            foreach ($s as $p) $freq[$c['school_id']][(string)$p] = ($freq[$c['school_id']][(string)$p] ?? 0) + 1;
        }
        $domin = []; // مدرسة => نسبتها الموحّدة (أو غير موجودة = مدرسة مبالغ ثابتة)
        foreach ($freq as $sidK => $m) {
            arsort($m);
            $top = (float)array_key_first($m); $topN = (int)$m[array_key_first($m)];
            if ($topN >= 5 && $topN * 2 >= (int)$cnt[$sidK]) $domin[$sidK] = $top;
        }
        // التحويل بدفعات
        $batch = 12; $didN = 0; $skips = [];
        foreach ($cand as $c) {
            if (!isset($domin[$c['school_id']])) continue; // مدرسة بلا نسبة — المبلغ يبقى (بأمره)
            $best = $domin[$c['school_id']];
            if (!in_array((float)$best, array_map('floatval', $sets[$c['bid']]), true)) {
                $skips[] = $c['school_id'] . ':' . $c['nm'] . '=' . number_format((float)$c['amount']);
                continue; // رقمه لا يطابق نسبة مدرسته — اتفاق خاص، يبقى مبلغاً وبتقرير الاستثناءات
            }
            if ($didN >= $batch) { setSetting('heal_percent_law_progress', 'working... last batch=' . $didN); return; }
            $eid = (int)$c['employee_id'];
            // نسخة أشهر المعنيّ قبل التعديل (إن لم تُنسخ)
            if (!(int)$db->query("SELECT COUNT(*) FROM _ms_bk_pctlaw0828 WHERE employee_id=$eid")->fetchColumn()) {
                $db->exec("INSERT INTO _ms_bk_pctlaw0828 SELECT * FROM monthly_salaries WHERE employee_id=$eid AND school_year IN ('2025-2026','2026-2027')");
            }
            $db->prepare("UPDATE employee_bonuses SET value_type='percent', amount=?, currency='LBP' WHERE id=?")->execute([$best, $c['bid']]);
            // بند 2026-2027 المنسوخ (مبلغ) → نفس نسبته
            $db->prepare("UPDATE employee_bonuses SET value_type='percent', amount=?, currency='LBP'
                WHERE employee_id=? AND bonus_type='prime_fixe' AND is_active=1 AND value_type='amount'
                  AND start_month IS NULL AND end_month IS NULL AND school_year='2026-2027'")->execute([$best, $eid]);
            recalcEmployeeYear($eid, '2025-2026');
            if ((int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=$eid AND school_year='2026-2027'")->fetchColumn()) {
                recalcEmployeeYear($eid, '2026-2027');
            }
            $didN++;
        }
        if ($didN >= $batch) { setSetting('heal_percent_law_progress', 'working... last batch=' . $didN); return; }
        // خلصت: كل الباقي استثناءات
        setSetting('heal_percent_law_20260828', 'done: exceptions=' . count($skips) . ($skips ? ' | ' . implode('؛ ', array_slice($skips, 0, 60)) : ''));
        setSetting('heal_percent_law_progress', 'done');
    } catch (Throwable $e) {
        try { setSetting('heal_percent_law_progress', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🔄 مزامنة بنود «الأجر الإضافي» من الكمبيوتر للأونلاين (2026-08-28، امتداد «طبق القانون
 * على الجميع»): تبيّن أن ملاك البشارة (وربما غيرهم) أونلاين **بلا بنود إضافي إطلاقاً**
 * وأشهرهم بإضافي 0 (متل قصة تيا) بينما الكمبيوتر هو المصدر الصحيح المطابق لكشوفه.
 * يقرأ لقطة tools/data/prime_snapshot_20260828.json (كل بنود الإضافي الفعّالة محلياً بعد
 * تطبيق القانون) ويطابق بالاسم (مدرسة+اسم+أب+شهرة)، يخلق/يوحّد البند ويعيد حساب سنته.
 * دفعات + يتخطّى من بنده مطابقاً وإضافيه المخزّن ليس صفراً (فلا يلمس السليم) + يتخطّى
 * المحميّين (ريتا مارون/ماريا الياس) والغامض اسمياً (يسجَّل).
 */
function healPrimeSnapshotSync20260828() {
    try {
        $file = dirname(__DIR__) . '/tools/data/prime_snapshot_20260828.json';
        if (!is_file($file)) { setSetting('heal_prime_snapshot_20260828', 'skip: no snapshot file'); return; }
        // نسخة اللقطة جزء من فلاغ الإتمام — لقطة جديدة (مثلاً بعد إضافة اسم الأم للتمييز) تعيد التشغيل
        $ver = substr(md5_file($file), 0, 8);
        if (strpos((string)getSetting('heal_prime_snapshot_20260828', ''), 'done@' . $ver) === 0) return;
        $snap = json_decode((string)file_get_contents($file), true);
        if (!is_array($snap) || !$snap) { setSetting('heal_prime_snapshot_20260828', 'skip: empty snapshot'); return; }
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        // تجميع اللقطة موظفاً-موظفاً (المقارنة كمجموعة بنود كاملة — بند-ببند كان يتأرجح عند تعدّد البنود)
        $byEmp = [];
        foreach ($snap as $r) $byEmp[$r['school'] . '|' . $r['f'] . '|' . $r['fa'] . '|' . ($r['mo'] ?? '') . '|' . $r['l']][] = $r;
        $batch = 10; $didN = 0; $amb = []; $miss = [];
        $key = function ($vt, $a, $c, $sy, $sm, $em) {
            return $vt . '|' . number_format((float)$a, 2, '.', '') . '|' . $c . '|' . ($sy ?? '~') . '|' . ($sm ?? '~') . '|' . ($em ?? '~');
        };
        foreach ($byEmp as $ek => $rows) {
            [$school, $f, $fa, $mo, $l] = explode('|', $ek);
            // المحميّان (سلفتاهما موثّقتان بالمليم)
            if (($f === 'ريتا' && strpos($fa, 'مارون') === 0 && strpos($l, 'حليحل') !== false)
             || ($f === 'ماريا' && strpos($fa, 'الياس') === 0 && strpos($l, 'حليحل') !== false)) continue;
            $sid = (int)$db->query("SELECT id FROM schools WHERE name_ar = " . $db->quote($school) . " AND is_deleted=0 LIMIT 1")->fetchColumn();
            if (!$sid) { $miss[] = 'مدرسة:' . $school; continue; }
            // سلّم التمييز (تنبيه المستخدم: «في اسم وعيلة نفس الشي بس بيختلف اسم الأب أو الأم»):
            // ١) اسم+شهرة+أب ٢) اسم+شهرة+أم ٣) اسم+شهرة+أب+أم ٤) اسم+شهرة فقط — أول درجة تعطي شخصاً واحداً بالضبط
            $ladder = [
                ["AND COALESCE(father_name_ar,'')=?", [$fa]],
                ["AND COALESCE(mother_first_name,'')=?", [$mo]],
                ["AND COALESCE(father_name_ar,'')=? AND COALESCE(mother_first_name,'')=?", [$fa, $mo]],
                ["", []],
            ];
            $ids = [];
            foreach ($ladder as [$cond, $extra]) {
                $st = $db->prepare("SELECT id FROM employees WHERE school_id=? AND is_deleted=0 AND first_name_ar=? AND last_name_ar=? $cond");
                $st->execute(array_merge([$sid, $f, $l], $extra));
                $got = $st->fetchAll(PDO::FETCH_COLUMN);
                if (count($got) === 1) { $ids = $got; break; }
                if (!$ids) $ids = $got; // للتقرير: آخر عدّ غير الصفري
            }
            if (count($ids) !== 1) { $amb[] = $f . ' ' . $l . '×' . count($ids); continue; }
            $eid = (int)$ids[0];
            // مجموعة بنوده الحالية مقابل مجموعة اللقطة
            $curRows = $db->query("SELECT value_type, amount, currency, school_year, start_month, end_month
                FROM employee_bonuses WHERE employee_id=$eid AND bonus_type='prime_fixe' AND is_active=1")->fetchAll(PDO::FETCH_ASSOC);
            $cur = []; $want = [];
            foreach ($curRows as $c1) $cur[] = $key($c1['value_type'], $c1['amount'], $c1['currency'], $c1['school_year'], $c1['start_month'], $c1['end_month']);
            foreach ($rows as $r1) $want[] = $key($r1['vt'], $r1['a'], $r1['c'], $r1['sy'], $r1['sm'], $r1['em']);
            sort($cur); sort($want);
            // إضافي أول أشهر 2025-2026 (يكشف «بند موجود لكن الأشهر بلا إضافي» — قصة تيا)
            $octPrime = (int)$db->query("SELECT prime_fixe_lbp FROM monthly_salaries WHERE employee_id=$eid AND school_year='2025-2026' ORDER BY year, month LIMIT 1")->fetchColumn();
            $n25 = (int)$db->query("SELECT COUNT(*) FROM monthly_salaries WHERE employee_id=$eid AND school_year='2025-2026'")->fetchColumn();
            $hasFullYear2526 = false;
            foreach ($rows as $r1) if ($r1['sm'] === null && ($r1['sy'] === null || $r1['sy'] === '2025-2026')) $hasFullYear2526 = true;
            if ($cur === $want && ($octPrime > 0 || !$hasFullYear2526 || $n25 === 0)) continue; // سليم — لا لمس
            if ($didN >= $batch) { setSetting('heal_prime_snapshot_progress', 'working... batch=' . $didN); return; }
            // إعادة بناء بنوده من اللقطة (المصدر الصحيح) ثم إعادة حساب سنته
            $db->exec("UPDATE employee_bonuses SET is_active=0 WHERE employee_id=$eid AND bonus_type='prime_fixe' AND is_active=1");
            $ins = $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                VALUES (?, 'prime_fixe', ?, ?, ?, ?, ?, ?, ?, 1)");
            $pn = 0;
            foreach ($rows as $r1) $ins->execute([$eid, ++$pn, $r1['sy'], $r1['a'], $r1['vt'], $r1['c'], $r1['sm'], $r1['em']]);
            if ($n25 > 0) recalcEmployeeYear($eid, '2025-2026');
            $didN++;
        }
        if ($didN >= $batch) { setSetting('heal_prime_snapshot_progress', 'working... batch=' . $didN); return; }
        setSetting('heal_prime_snapshot_20260828', 'done@' . $ver . ': غامض=' . count($amb) . ($amb ? ' [' . implode('؛', array_slice($amb, 0, 20)) . ']' : '')
            . ' مفقود=' . count($miss) . ($miss ? ' [' . implode('؛', array_slice(array_unique($miss), 0, 10)) . ']' : ''));
        setSetting('heal_prime_snapshot_progress', 'done');
    } catch (Throwable $e) {
        try { setSetting('heal_prime_snapshot_progress', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🎓 شيرا انطوان العاقوري (البشارة) — «عندها إجازة تعليمية» (المستخدم 2026-08-29).
 * خانة شهادتها كانت فاضية فعاملها البرنامج كدرجة دخول 1 → درجة 18 (أساس 1,695,000) بدل
 * القانون، والإضافي انحطّ رقماً جامداً (68,850,000) يعوّض النقص فيطلع المجموع صحيحاً والتقسيم غلط.
 * الشفاء (بالاسم، مرّة واحدة): الشهادة = إجازة تعليمية (درجة دخول 15) → إعادة بناء الدرجات حسب
 * القانون (15 + 5.5 عادية + 10.5 استثنائية = 31 → 2,625,000) → الإضافي نسبة 45٪ متل رفقاتها
 * بقاعدة ÷1500 (= 70,000,000) → إعادة حساب 2025-2026 وما بعدها. نسخة قبل التعديل بالإعداد bk_chira_20260829.
 */
function healChiraTaalimiya20260829() {
    try {
        if (strpos((string)getSetting('heal_chira_taalimiya_20260829', ''), 'done') === 0) return;
        $db = getDB();
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if (!$sid) { setSetting('heal_chira_taalimiya_20260829', 'done: skip no school'); return; }
        $st = $db->prepare("SELECT * FROM employees WHERE school_id=? AND is_deleted=0 AND employee_type='enseignant_titulaire'
            AND first_name_ar='شيرا' AND last_name_ar LIKE '%عاقوري%' AND father_name_ar LIKE 'انطوان%' LIMIT 1");
        $st->execute([(int)$sid]);
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { setSetting('heal_chira_taalimiya_20260829', 'done: skip not found'); return; }
        $eid = (int)$emp['id'];
        require_once __DIR__ . '/payroll_calculator.php';
        // نسخة قبل التعديل (الشهادة/الدرجات/سجلّ الدرجات/بند الإضافي/أشهر 2025-2026)
        $bk = [
            'emp' => ['diploma' => $emp['diploma'], 'starting_grade' => $emp['starting_grade'], 'current_grade' => $emp['current_grade']],
            'grades' => $db->query("SELECT * FROM employee_grade_history WHERE employee_id=$eid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'bonuses' => $db->query("SELECT * FROM employee_bonuses WHERE employee_id=$eid")->fetchAll(PDO::FETCH_ASSOC),
            'months' => $db->query("SELECT year, month, grade_at_month, base_salary_lbp, echelon_value_lbp, base_plus_echelon_lbp, prime_fixe_lbp, net_salary_lbp, total_due_lbp
                FROM monthly_salaries WHERE employee_id=$eid AND (year*100+month) >= 202510 ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC),
        ];
        setSetting('bk_chira_20260829', json_encode($bk, JSON_UNESCAPED_UNICODE));
        $before = $db->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp p FROM monthly_salaries WHERE employee_id=$eid AND year=2025 AND month=11 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['b' => 0, 'p' => 0];
        // ① الشهادة = إجازة تعليمية (درجة الدخول من جدول الشهادات)
        $sg = $db->query("SELECT starting_grade FROM diploma_starting_grades WHERE diploma_code='ijaza_taalimiya'")->fetchColumn();
        $sg = ($sg === false) ? 15 : (float)$sg;
        $db->prepare("UPDATE employees SET diploma='ijaza_taalimiya', starting_grade=? WHERE id=?")->execute([$sg, $eid]);
        // ② إعادة بناء الدرجات حسب القانون (المصدر الواحد)
        $r = buildLegalGradeHistory($eid);
        // ③ الإضافي = نسبة 45٪ متل رفقاتها (بند 2025-2026 الفعّال، وإلا يُخلَق)
        $b = $db->prepare("SELECT id FROM employee_bonuses WHERE employee_id=? AND bonus_type='prime_fixe' AND is_active=1
            AND (school_year IS NULL OR school_year='2025-2026') ORDER BY id LIMIT 1");
        $b->execute([$eid]);
        $bid = (int)$b->fetchColumn();
        if ($bid > 0) {
            $db->prepare("UPDATE employee_bonuses SET value_type='percent', amount=45, currency='LBP', start_month=NULL, end_month=NULL WHERE id=?")->execute([$bid]);
            $db->prepare("UPDATE employee_bonuses SET is_active=0 WHERE employee_id=? AND bonus_type='prime_fixe' AND is_active=1 AND id<>? AND (school_year IS NULL OR school_year='2025-2026')")->execute([$eid, $bid]);
        } else {
            $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                VALUES (?, 'prime_fixe', 1, '2025-2026', 45, 'percent', 'LBP', NULL, NULL, 1)")->execute([$eid]);
        }
        // ④ إعادة حساب 2025-2026 وأي سنة لاحقة مخزّنة
        $years = $db->query("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id=$eid AND (year*100+month) >= 202510 ORDER BY school_year")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('2025-2026', $years, true)) $years[] = '2025-2026';
        $n = 0;
        foreach ($years as $sy) $n += (int)recalcEmployeeYear($eid, $sy);
        $after = $db->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp p, net_salary_lbp n FROM monthly_salaries WHERE employee_id=$eid AND year=2025 AND month=11 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['b' => 0, 'p' => 0, 'n' => 0];
        setSetting('heal_chira_taalimiya_20260829', 'done: emp=' . $eid . ' grade ' . $emp['current_grade'] . '→' . $r['final_grade']
            . ' | Nov base ' . $before['b'] . '→' . $after['b'] . ' prime ' . $before['p'] . '→' . $after['p'] . ' net=' . $after['n'] . ' recalc=' . $n);
    } catch (Throwable $e) {
        try { setSetting('heal_chira_taalimiya_20260829', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🎓 ماريا اديب اسعد (البشارة) — «حطّيتلها تاريخ دخول الملاك = تاريخ دخول المدرسة» + «طبّق 45٪ على راتبها
 * بعد التدرّج مرّة وحدة لنفس الفترة» (المستخدم 2026-08-29). أونلاين صار ملاكها 1/10/2009 → درجة 39 (3,445,000)
 * بالقانون، لكن إضافيها انحسب مرّتين (بند نسبة 45٪ + البند القديم الجامد 89,610,000 فعّالان معاً = 181,610,000).
 * الشفاء (بالاسم، مرّة واحدة):
 * ① الملاك = دخول المدرسة (يعمّم قراره على النسختين) → إعادة بناء الدرجات حسب القانون
 * ② الإضافي بند واحد = نسبة 45٪ لكل السنة (يُطفأ أي مبلغ ثابت لكل السنة) → إعادة حساب 2025-2026
 * ③ عام: أي موظف عنده «أجر إضافي» نسبةً لكل السنة + مبلغاً ثابتاً لكل السنة معاً (ازدواج) → يُطفأ المبلغ
 *    ويُعاد حسابه، وتُسجَّل الأسماء بالفلاغ. نسخة قبل التعديل: bk_maria_20260829.
 */
function healMariaMalak20260829() {
    try {
        if (strpos((string)getSetting('heal_maria_malak_20260829', ''), 'done') === 0) return;
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        $log = [];
        $fullYearSql = "(start_month IS NULL OR (start_month=10 AND end_month=9))";
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        $emp = null;
        if ($sid) {
            $st = $db->prepare("SELECT * FROM employees WHERE school_id=? AND is_deleted=0 AND employee_type='enseignant_titulaire'
                AND first_name_ar='ماريا' AND last_name_ar LIKE 'اسعد%' AND father_name_ar LIKE 'اديب%' LIMIT 1");
            $st->execute([(int)$sid]);
            $emp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($emp) {
            $eid = (int)$emp['id'];
            setSetting('bk_maria_20260829', json_encode([
                'emp' => ['titularization_date' => $emp['titularization_date'], 'current_grade' => $emp['current_grade'], 'diploma' => $emp['diploma']],
                'grades' => $db->query("SELECT * FROM employee_grade_history WHERE employee_id=$eid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                'bonuses' => $db->query("SELECT * FROM employee_bonuses WHERE employee_id=$eid")->fetchAll(PDO::FETCH_ASSOC),
                'months' => $db->query("SELECT year, month, grade_at_month, base_plus_echelon_lbp, prime_fixe_lbp, net_salary_lbp, total_due_lbp FROM monthly_salaries WHERE employee_id=$eid AND (year*100+month)>=202510 ORDER BY year, month")->fetchAll(PDO::FETCH_ASSOC),
            ], JSON_UNESCAPED_UNICODE));
            $before = $db->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp p FROM monthly_salaries WHERE employee_id=$eid AND year=2025 AND month=11 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['b'=>0,'p'=>0];
            // ① الملاك = دخول المدرسة (قراره)
            if (!empty($emp['hire_date']) && $emp['titularization_date'] !== $emp['hire_date']) {
                $db->prepare("UPDATE employees SET titularization_date=? WHERE id=?")->execute([$emp['hire_date'], $eid]);
            }
            if (empty($emp['diploma'])) $db->prepare("UPDATE employees SET diploma='ijaza_taalimiya', starting_grade=15 WHERE id=?")->execute([$eid]);
            $r = buildLegalGradeHistory($eid);
            // ② بند واحد: نسبة 45٪ لكل السنة
            $pct = $db->query("SELECT id FROM employee_bonuses WHERE employee_id=$eid AND bonus_type='prime_fixe' AND is_active=1 AND value_type='percent'
                AND (school_year IS NULL OR school_year='2025-2026') AND $fullYearSql ORDER BY id LIMIT 1")->fetchColumn();
            if ($pct) {
                $db->exec("UPDATE employee_bonuses SET amount=45, currency='LBP' WHERE id=" . (int)$pct);
            } else {
                $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                    VALUES (?, 'prime_fixe', 1, '2025-2026', 45, 'percent', 'LBP', NULL, NULL, 1)")->execute([$eid]);
                $pct = (int)$db->lastInsertId();
            }
            $off = $db->exec("UPDATE employee_bonuses SET is_active=0 WHERE employee_id=$eid AND bonus_type='prime_fixe' AND is_active=1 AND id<>" . (int)$pct
                . " AND (school_year IS NULL OR school_year='2025-2026') AND $fullYearSql");
            $n = (int)recalcEmployeeYear($eid, '2025-2026');
            $after = $db->query("SELECT base_plus_echelon_lbp b, prime_fixe_lbp p, net_salary_lbp n FROM monthly_salaries WHERE employee_id=$eid AND year=2025 AND month=11 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['b'=>0,'p'=>0,'n'=>0];
            $log[] = 'ماريا emp=' . $eid . ' grade ' . $emp['current_grade'] . '→' . $r['final_grade'] . ' | Nov base ' . $before['b'] . '→' . $after['b']
                . ' prime ' . $before['p'] . '→' . $after['p'] . ' net=' . $after['n'] . ' off=' . (int)$off . ' recalc=' . $n;
        } else {
            $log[] = 'ماريا: not found';
        }
        // ③ عام: ازدواج نسبة + مبلغ لكل السنة عند غيرها
        $dups = $db->query("SELECT b.employee_id, CONCAT(e.first_name_ar,' ',e.last_name_ar) nm FROM employee_bonuses b JOIN employees e ON e.id=b.employee_id
            WHERE b.bonus_type='prime_fixe' AND b.is_active=1 AND (b.school_year IS NULL OR b.school_year='2025-2026') AND $fullYearSql AND e.is_deleted=0
            GROUP BY b.employee_id HAVING SUM(b.value_type='percent')>=1 AND SUM(b.value_type='amount')>=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dups as $d) {
            $eid2 = (int)$d['employee_id'];
            $db->exec("UPDATE employee_bonuses SET is_active=0 WHERE employee_id=$eid2 AND bonus_type='prime_fixe' AND is_active=1 AND value_type='amount'
                AND (school_year IS NULL OR school_year='2025-2026') AND $fullYearSql");
            recalcEmployeeYear($eid2, '2025-2026');
            $log[] = 'ازدواج مُطفأ: ' . $d['nm'] . ' (' . $eid2 . ')';
        }
        setSetting('heal_maria_malak_20260829', 'done: ' . implode(' | ', $log));
    } catch (Throwable $e) {
        try { setSetting('heal_maria_malak_20260829', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 👻 كلاديس الصباغ (البشارة، موظفة) — «وين محطّطلها أجر إضافي مش مبيّن بملفها» (2026-08-29):
 * أونلاين أشهرها المخزّنة فيها إضافي 751,000,000 بلا أي سطر بملفها: سطرٌ أُدخل ثم حُذف محواً،
 * و«تركيب العلاوات» للمنقولين لا يلمس الأشهر إن لم يجد أي سطر (حماية المنقول) فبقي الرقم عالقاً.
 * الحل الجذري بالكود: الحذف صار إطفاءً (is_active=0) فيُصفَّر تلقائياً. وهذا الشفاء (بالاسم، مرّة):
 * يزرع لها سطراً مطفأً لكل سنة فيها إضافي عالق بلا أسطر ثم يعيد الحساب → يصير الإضافي 0 والصافي يتصحّح.
 * ويسجّل بالفلاغ أسماء أي «أشباح» مماثلة عند غيرها (بلا لمس) للمراجعة.
 */
function healGladisGhostPrime20260829() {
    try {
        if (strpos((string)getSetting('heal_gladis_ghost_20260829', ''), 'done') === 0) return;
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        $log = [];
        $ghostSql = "SELECT ms.employee_id, ms.school_year, MAX(ms.prime_fixe_lbp) p, MAX(ms.aide_complementaire_lbp) a
            FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
            WHERE e.is_deleted = 0 AND e.employee_type <> 'enseignant_titulaire'
              AND COALESCE(e.base_salary_usd,0) = 0 AND COALESCE(e.contract_salary_lbp,0) = 0
              AND ms.school_year >= '2025-2026' AND (ms.prime_fixe_lbp > 0 OR ms.aide_complementaire_lbp > 0)
              AND NOT EXISTS (SELECT 1 FROM employee_bonuses b WHERE b.employee_id = ms.employee_id
                    AND b.bonus_type IN ('prime_fixe','aide_complementaire') AND (b.school_year IS NULL OR b.school_year = ms.school_year))
            GROUP BY ms.employee_id, ms.school_year";
        $ghosts = $db->query($ghostSql)->fetchAll(PDO::FETCH_ASSOC);
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        $gid = 0;
        if ($sid) {
            $st = $db->prepare("SELECT id FROM employees WHERE school_id=? AND is_deleted=0 AND first_name_ar LIKE 'كلاديس%' AND last_name_ar LIKE '%الصباغ%' LIMIT 1");
            $st->execute([(int)$sid]); $gid = (int)$st->fetchColumn();
        }
        $fixed = 0; $others = [];
        foreach ($ghosts as $g) {
            $eid = (int)$g['employee_id'];
            if ($eid === $gid && $gid > 0) {
                $before = $db->query("SELECT prime_fixe_lbp, net_salary_lbp FROM monthly_salaries WHERE employee_id=$eid AND school_year=" . $db->quote($g['school_year']) . " ORDER BY year, month LIMIT 1 OFFSET 1")->fetch(PDO::FETCH_ASSOC);
                $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active)
                    VALUES (?, 'prime_fixe', 99, ?, 0, 'amount', 'LBP', NULL, NULL, 0)")->execute([$eid, $g['school_year']]);
                $n = recalcEmployeeYear($eid, $g['school_year']);
                $after = $db->query("SELECT prime_fixe_lbp, net_salary_lbp FROM monthly_salaries WHERE employee_id=$eid AND school_year=" . $db->quote($g['school_year']) . " ORDER BY year, month LIMIT 1 OFFSET 1")->fetch(PDO::FETCH_ASSOC);
                $log[] = 'كلاديس ' . $g['school_year'] . ': prime ' . ($before['prime_fixe_lbp'] ?? '?') . '→' . ($after['prime_fixe_lbp'] ?? '?') . ' net ' . ($before['net_salary_lbp'] ?? '?') . '→' . ($after['net_salary_lbp'] ?? '?') . ' (' . $n . ')';
                $fixed++;
            } else {
                $nm = $db->query("SELECT CONCAT(first_name_ar,' ',last_name_ar) FROM employees WHERE id=$eid")->fetchColumn();
                $others[] = $nm . ' [' . $g['school_year'] . ': ' . (int)$g['p'] . '/' . (int)$g['a'] . ']';
            }
        }
        if (!$fixed) $log[] = 'كلاديس: لا إضافي عالق';
        setSetting('heal_gladis_ghost_20260829', 'done: ' . implode(' | ', $log) . ($others ? ' | أشباح أخرى (بلا لمس): ' . implode('؛ ', array_slice($others, 0, 15)) : ' | لا أشباح أخرى'));
    } catch (Throwable $e) {
        try { setSetting('heal_gladis_ghost_20260829', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🚑 استرجاع الرواتب المصفَّرة أونلاين (2026-08-29 — «شيك على كل البرنامج، ما بدي أغلاط»):
 * بتاريخ 2026-08-20 شفاء تكميل العلاوات استدعى المحرّك الكامل مباشرةً لموظفين ومتعاقدين «منقولين
 * بلا إعداد» (أساسهم بالإعداد صفر) فصفّر أساسهم وصافيهم بأشهر 2025-2026 أونلاين (عبرا 43، الانتقال 11،
 * النياح 7، البشارة 8، النجاة 7…) بينما النسخة المحلية (المدقَّقة على كشوفه بالمليم) سليمة.
 * هذا الشفاء يسترجع من لقطة tools/data/rows_snapshot_20260829.json (الصفوف المحلية السليمة) فقط
 * الصفوف التي أساسها صفر هنا وأساسها > 0 باللقطة (نفس الموظف/السنة/الشهر) — كل الأعمدة المالية —
 * ثم يعيد «تركيب العلاوات» بأسطر الموظف الحالية. ويعيد أسطر الإضافي الناقصة لخمسة متعاقدين بالبشارة
 * ويحوّل إضافي غادة باصيلا وابتسام أبو ضاهر إلى نسبة 45٪ (= كشفه بالمليم). نسخة قبل التعديل:
 * _ms_bk_restore20260829. يعمل بالمعرّف + تحقّق الاسم (الاسم والشهرة نفسهما).
 */
function healRestoreZeroedRows20260829() {
    try {
        if (strpos((string)getSetting('heal_restore_zeroed_20260829', ''), 'done') === 0) return;
        $db = getDB();
        require_once __DIR__ . '/payroll_calculator.php';
        $file = dirname(__DIR__) . '/tools/data/rows_snapshot_20260829.json';
        if (!is_file($file)) { setSetting('heal_restore_zeroed_20260829', 'err: no snapshot'); return; }
        $snap = json_decode((string)file_get_contents($file), true);
        if (!$snap || empty($snap['rows'])) { setSetting('heal_restore_zeroed_20260829', 'err: bad snapshot'); return; }
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_restore20260829 LIKE monthly_salaries");
        $cols = ['base_salary_lbp','echelon_value_lbp','base_plus_echelon_lbp','extra_lbp','prime_fixe_lbp','aide_complementaire_lbp','transport_complement_lbp','echelon_to_caisse_lbp','caisse_amount_lbp','eoc_grade_lbp','cnss_amount_lbp','taxable_base_lbp','income_tax_lbp','total_retenues_lbp','net_salary_lbp','family_allowance_lbp','transport_lbp','total_due_lbp','exchange_rate','net_salary_usd','total_due_usd','school_cnss_8_lbp','school_eoc_6_lbp','school_family_comp_6_lbp','school_end_of_service_8_5_lbp','grade_at_month'];
        $empQ = $db->prepare("SELECT id, first_name_ar, last_name_ar, employee_type, base_salary_usd, contract_salary_lbp, is_deleted FROM employees WHERE id=?");
        $rowQ = $db->prepare("SELECT id, base_plus_echelon_lbp, extra_lbp, prime_fixe_lbp, aide_complementaire_lbp, total_retenues_lbp, net_salary_lbp, transport_lbp, family_allowance_lbp, total_due_lbp FROM monthly_salaries WHERE employee_id=? AND year=? AND month=? LIMIT 1");
        // الصف «غير المتّسق» = صافيه ≠ (الإجمالي − المحسومات) أو مستحقّه ≠ (الصافي + النقل + العائلية) — يُستبدل بالصف المدقَّق
        $inconsistent = function (array $c): bool {
            $gross = (int)$c['base_plus_echelon_lbp'] + (int)$c['extra_lbp'] + (int)$c['prime_fixe_lbp'] + (int)$c['aide_complementaire_lbp'];
            return abs($gross - (int)$c['total_retenues_lbp'] - (int)$c['net_salary_lbp']) > 1
                || abs((int)$c['net_salary_lbp'] + (int)$c['transport_lbp'] + (int)($c['family_allowance_lbp'] ?? 0) - (int)$c['total_due_lbp']) > 1;
        };
        $upd = $db->prepare("UPDATE monthly_salaries SET " . implode(', ', array_map(fn($c) => "$c=?", $cols)) . ", is_calculated=1 WHERE id=?");
        $touched = []; $restored = 0; $skipName = []; $configured = []; $recalcCfg = 0; $prunedCfg = 0;
        $empCache = [];
        foreach ($snap['rows'] as $r) {
            $eid = (int)$r['employee_id'];
            if (!isset($empCache[$eid])) { $empQ->execute([$eid]); $empCache[$eid] = $empQ->fetch(PDO::FETCH_ASSOC) ?: false; }
            $e = $empCache[$eid];
            if (!$e || (int)$e['is_deleted'] === 1) continue;
            if ($e['employee_type'] === 'enseignant_titulaire' || (float)$e['base_salary_usd'] > 0 || (float)$e['contract_salary_lbp'] > 0) { $configured[$eid] = 1; continue; } // له إعداد → المحرّك سيّده (أدناه)
            if (trim((string)$e['first_name_ar']) !== trim((string)$r['f']) || trim((string)$e['last_name_ar']) !== trim((string)$r['l'])) { $skipName[$eid] = $r['f'] . ' ' . $r['l']; continue; }
            $rowQ->execute([$eid, (int)$r['year'], (int)$r['month']]);
            $cur = $rowQ->fetch(PDO::FETCH_ASSOC);
            if (!$cur || (int)$r['base_plus_echelon_lbp'] <= 0) continue;
            if ((int)$cur['base_plus_echelon_lbp'] > 0 && !$inconsistent($cur)) continue; // فقط المصفَّر أو غير المتّسق هنا، والسليم باللقطة
            $db->exec("INSERT IGNORE INTO _ms_bk_restore20260829 SELECT * FROM monthly_salaries WHERE id=" . (int)$cur['id']);
            $vals = []; foreach ($cols as $c) $vals[] = $r[$c];
            $vals[] = (int)$cur['id'];
            $upd->execute($vals);
            $restored++; $touched[$eid] = 1;
        }
        // أعِد تركيب العلاوات الحالية (أسطر الملف أونلاين) على الصفوف المسترجَعة
        foreach (array_keys($touched) as $eid) { try { recalcEmployeeYear($eid, '2025-2026'); } catch (Throwable $e) {} }
        // من له إعداد راتب هنا (عقد/دولار أدخله المستخدم): صفوفه المصفَّرة/غير المتّسقة تُعاد من المحرّك نفسه،
        // وصفوف الأشهر خارج أشهر دفعه (عقد 10 أشهر → لا آب/أيلول) تُزال (بنسخة) — كانت بقايا تصفير 20-08.
        foreach (array_keys($configured) as $eid) {
            $pm = (int)$db->query("SELECT payment_months_per_year FROM employees WHERE id=$eid")->fetchColumn();
            $bad = $db->query("SELECT id, month FROM monthly_salaries WHERE employee_id=$eid AND school_year='2025-2026' AND (base_plus_echelon_lbp=0
                OR ABS((base_plus_echelon_lbp+extra_lbp+prime_fixe_lbp+aide_complementaire_lbp)-total_retenues_lbp-net_salary_lbp)>1
                OR ABS(net_salary_lbp+transport_lbp+COALESCE(family_allowance_lbp,0)-total_due_lbp)>1)")->fetchAll(PDO::FETCH_ASSOC);
            if (!$bad) continue;
            if ($pm === 10) {
                foreach ($bad as $b) if (in_array((int)$b['month'], [8, 9], true)) {
                    $db->exec("INSERT IGNORE INTO _ms_bk_restore20260829 SELECT * FROM monthly_salaries WHERE id=" . (int)$b['id']);
                    $prunedCfg += (int)$db->exec("DELETE FROM monthly_salaries WHERE id=" . (int)$b['id']);
                }
            }
            try { recalcEmployeeYear($eid, '2025-2026'); $recalcCfg++; } catch (Throwable $e) {}
        }
        // تغريد غدار (البشارة، متعاقدة غير خاضعة بكشفه — قرار 2026-08-27): لا صفوف لها بالنسخة المدقَّقة → تُزال صفوفها هنا (بنسخة)
        $tg = 0;
        $sidB = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if ($sidB) {
            $st = $db->prepare("SELECT id FROM employees WHERE school_id=? AND is_deleted=0 AND first_name_ar='تغريد' AND last_name_ar LIKE 'غدار%' AND employee_type='enseignant_contractuel' LIMIT 1");
            $st->execute([(int)$sidB]); $tid = (int)$st->fetchColumn();
            if ($tid) {
                $db->exec("INSERT IGNORE INTO _ms_bk_restore20260829 SELECT * FROM monthly_salaries WHERE employee_id=$tid AND school_year='2025-2026'");
                $tg = $db->exec("DELETE FROM monthly_salaries WHERE employee_id=$tid AND school_year='2025-2026'");
            }
        }
        // أسطر الإضافي الناقصة (البشارة) — إن غاب سطر إضافي فعّال بنفس السنة
        $insB = 0;
        foreach ($snap['bonuses'] ?? [] as $b) {
            $eid = (int)$b['employee_id'];
            $empQ->execute([$eid]); $e = $empQ->fetch(PDO::FETCH_ASSOC);
            if (!$e || (int)$e['is_deleted'] === 1) continue;
            if (trim((string)$e['first_name_ar']) !== trim((string)$b['f']) || trim((string)$e['last_name_ar']) !== trim((string)$b['l'])) continue;
            $has = $db->prepare("SELECT 1 FROM employee_bonuses WHERE employee_id=? AND bonus_type='prime_fixe' AND is_active=1 AND (school_year IS NULL OR school_year='2025-2026') LIMIT 1");
            $has->execute([$eid]);
            if ($has->fetchColumn()) continue;
            $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'prime_fixe', 1, '2025-2026', ?, ?, ?, ?, ?, 1)")
               ->execute([$eid, $b['amount'], $b['value_type'], $b['currency'], $b['start_month'], $b['end_month']]);
            $insB++; try { recalcEmployeeYear($eid, '2025-2026'); } catch (Throwable $e2) {}
        }
        // غادة باصيلا وابتسام أبو ضاهر (ملاك البشارة): إضافيهما = نسبة المدرسة 45٪ (= كشفه بالمليم على درجتيهما الحاليتين)
        $pct = 0;
        $sid = $db->query("SELECT id FROM schools WHERE name_ar LIKE 'مدرسة سيدة البشارة%' AND is_deleted=0 LIMIT 1")->fetchColumn();
        if ($sid) {
            foreach ([['غادة', 'باصيلا']] as [$fn, $ln]) {
                // عند تكرار الاسم: الملف الذي له رواتب هذه السنة هو الفعلي
                $st = $db->prepare("SELECT e.id FROM employees e WHERE e.school_id=? AND e.is_deleted=0 AND e.employee_type='enseignant_titulaire' AND e.first_name_ar=? AND e.last_name_ar LIKE ?
                    ORDER BY (SELECT COUNT(*) FROM monthly_salaries m WHERE m.employee_id=e.id AND m.school_year='2025-2026') DESC, e.id LIMIT 1");
                $st->execute([(int)$sid, $fn, '%' . $ln . '%']);
                $eid = (int)$st->fetchColumn();
                if (!$eid) continue;
                $has = $db->prepare("SELECT id, value_type, amount FROM employee_bonuses WHERE employee_id=? AND bonus_type='prime_fixe' AND is_active=1 AND (school_year IS NULL OR school_year='2025-2026') AND (start_month IS NULL OR (start_month=10 AND end_month=9)) ORDER BY id");
                $has->execute([$eid]);
                $rows = $has->fetchAll(PDO::FETCH_ASSOC);
                $ok = false;
                foreach ($rows as $rw) if ($rw['value_type'] === 'percent' && (float)$rw['amount'] == 45.0) $ok = true;
                if ($ok) continue;
                foreach ($rows as $rw) $db->exec("UPDATE employee_bonuses SET is_active=0 WHERE id=" . (int)$rw['id']);
                $db->prepare("INSERT INTO employee_bonuses (employee_id, bonus_type, period_number, school_year, amount, value_type, currency, start_month, end_month, is_active) VALUES (?, 'prime_fixe', 1, '2025-2026', 45, 'percent', 'LBP', NULL, NULL, 1)")->execute([$eid]);
                try { recalcEmployeeYear($eid, '2025-2026'); } catch (Throwable $e2) {}
                $pct++;
            }
        }
        setSetting('heal_restore_zeroed_20260829', 'done: restoredRows=' . $restored . ' employees=' . count($touched) . ' cfgRecalc=' . $recalcCfg . ' cfgPruned=' . $prunedCfg . ' bonusRows=' . $insB . ' pct45=' . $pct . ' taghridRows=' . $tg
            . ($skipName ? ' | اسم مختلف (لم يُلمس): ' . implode('؛', array_slice(array_values($skipName), 0, 10)) : ''));
    } catch (Throwable $e) {
        try { setSetting('heal_restore_zeroed_20260829', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}

/**
 * 🧹 (2026-08-29 — الفحص الرسمي) رواتب بعد الترك ضمن السنة + صفوف يتيمة لموظفين محذوفين:
 * يحذف رواتب الموظفين المحذوفين (is_deleted=1) بنسخة _ms_bk_orphans20260829. (أشهر ما بعد الترك ضمن
 * السنة تبقى للمراجعة — قد تكون بقرار المستخدم متل حنان تحومي.)
 */
function healPostDepartureOrphans20260829() {
    try {
        if (strpos((string)getSetting('heal_postleave_orphans_20260829', ''), 'done') === 0) return;
        $db = getDB();
        $n1 = 0; $who = [];
        // (أشهر ما بعد الترك ضمن السنة لا تُحذف — قد تكون بقراره؛ تبقى للمراجعة بالفحص الرسمي)
        $db->exec("CREATE TABLE IF NOT EXISTS _ms_bk_orphans20260829 LIKE monthly_salaries");
        $db->exec("INSERT IGNORE INTO _ms_bk_orphans20260829 SELECT ms.* FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=1");
        $n2 = (int)$db->exec("DELETE ms FROM monthly_salaries ms JOIN employees e ON e.id=ms.employee_id WHERE e.is_deleted=1");
        setSetting('heal_postleave_orphans_20260829', 'done: postLeaveRows=' . $n1 . ' [' . implode('؛', array_slice($who, 0, 25)) . '] orphanRows=' . $n2);
    } catch (Throwable $e) {
        try { setSetting('heal_postleave_orphans_20260829', 'err: ' . mb_substr($e->getMessage(), 0, 200)); } catch (Throwable $e2) {}
    }
}
