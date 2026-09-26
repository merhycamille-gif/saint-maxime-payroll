<?php
/**
 * البحث الشامل (Ctrl+K) — يرجع أساتذة/موظفين مطابقين ضمن المدارس المسموحة فقط.
 * يُستدعى من الشريط العلوي في كل الصفحات (includes/header.php).
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 1) { echo '[]'; exit; } // 🔍 من أوّل حرف (2026-09-13)

$like = '%' . $q . '%'; $start = $q . '%';
// 📞 (2026-09-21 «أو تفتيش برقم التلفون») أرقام الهاتف تُطابَق بالأرقام فقط (08-506827 = 08506827 = 506827)
$digits = preg_replace('/\D/', '', $q); $dlike = $digits !== '' ? '%' . $digits . '%' : "\0";
// 🔍 «بس أحطّ أوّل حرف من اسمه لازم دغري يعطيني اللائحة» (2026-09-13): الأسماء التي **تبدأ** بالمكتوب أوّلاً (الاسم الأوّل، ثم الشهرة،
//    بالعربي أو الفرنسي)، ثم أي جزء من الاسم الثلاثي أو الرقم — 30 نتيجة مرتّبة أبجدياً
// 🚪 (2026-09-26 كرستيان عون «ترك من الكل 30/9/2026 ليش اسمه بعده عم يبيّن بـ2026-2027»): البحث كان يفتّش بكل الموظفين بلا سنة ولا ترك.
//    الآن كباقي اللوائح: التارك من الكل يختفي من كل سنة تبدأ بعد تركه (السنة المختارة بالشريط)، ويبقى بسنينه القديمة وبـ«كل السنين»
//    مع شارة «ترك يوم/شهر/سنة» حتى لا يُفتَح ملفه على أنّه موظف حالي.
$sySearch = activeSchoolYear();
$leftFilter = ''; $leftParams = [];
// 🗂️ scope=all (صفحة التاريخ الكامل): كل الموظفين تاركين أو لا بأي سنة — الشارة «ترك» تبقى
if (($_GET['scope'] ?? '') !== 'all' && preg_match('/^(\d{4})-\d{4}$/', (string)$sySearch, $ym)) { $leftFilter = " AND " . leftDateSql() . " >= ?"; $leftParams[] = $ym[1] . '-10-01'; }
$st = getDB()->prepare(
    "SELECT id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, school_id, phone1, phone2, " . leftDateSql() . " AS left_on,
            CASE WHEN COALESCE(first_name_ar,'') LIKE ? OR COALESCE(first_name_fr,'') LIKE ? THEN 0
                 WHEN COALESCE(last_name_ar,'') LIKE ? OR COALESCE(last_name_fr,'') LIKE ? THEN 1 ELSE 2 END AS rk
       FROM employees
      WHERE is_deleted = 0" . schoolScopeSql() . $leftFilter . "
        AND (employee_code LIKE ?
             OR CONCAT(COALESCE(first_name_fr,''),' ',COALESCE(last_name_fr,'')) LIKE ?
             OR CONCAT(COALESCE(first_name_ar,''),' ',COALESCE(father_name_ar,''),' ',COALESCE(last_name_ar,'')) LIKE ?
             OR CONCAT(COALESCE(first_name_ar,''),' ',COALESCE(last_name_ar,'')) LIKE ?
             OR REPLACE(REPLACE(REPLACE(COALESCE(phone1,''),'-',''),' ',''),'/','') LIKE ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone2,''),'-',''),' ',''),'/','') LIKE ?)
      ORDER BY rk, COALESCE(NULLIF(first_name_ar,''), first_name_fr), COALESCE(NULLIF(last_name_ar,''), last_name_fr)
      LIMIT 30"
);
$st->execute(array_merge([$start, $start, $start, $start], $leftParams, [$like, $like, $like, $like, $dlike, $dlike]));

$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = [
        'id'     => (int)$r['id'],
        'code'   => (string)$r['employee_code'],
        'fr'     => trim($r['first_name_fr'] . ' ' . $r['last_name_fr']),
        'ar'     => trim($r['first_name_ar'] . ' ' . $r['last_name_ar']),
        'school' => (string)schoolNameById((int)$r['school_id']),
        'phone'  => trim(implode(' / ', array_filter([trim((string)$r['phone1']), trim((string)$r['phone2'])]))),
        'left'   => (!empty($r['left_on']) && $r['left_on'] !== '9999-12-31') ? date('d/m/Y', strtotime($r['left_on'])) : '', // 🚪 تارك من الكل
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
