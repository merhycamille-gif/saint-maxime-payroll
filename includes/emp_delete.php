<?php
/**
 * 🗑️📅 حذف الموظف «محلّ ما هو موجود» (2026-11-09 — «حذفت معلمة من سنة 2026-2027، رجعت شفتها بسنة 2025-2026 كمان حذفها وهيدا خطأ:
 *      لازم بس احذف شي ينحذف محلّ ما هو موجود» ثمّ «بدي قبل كل شي أساس البرنامج ما يكون غلط»).
 *
 * القاعدة: الحذف يتبع السنة المعروضة، كقراره 2026-09-23 «بس تغيّر شغلة لازم تتغيّر بس بالسنة اللي بتغيّر فيها»:
 *   • الموظف له راتب فعلي بسنين قبل السنة المعروضة ⇒ يُشال من السنة المعروضة وما بعدها فقط، ويبقى كما هو بما قبلها.
 *     التنفيذ = آلية «الترك» نفسها (المصدر الواحد لقاعدة التارك §10): تاريخ ترك = 30/9 قبل بداية السنة + pruneSalariesAfterDeparture
 *     (تشيل أشهر السنين اللاحقة غير المدفوعة وتطفئ علاواتها) ⇒ yearEmploymentFilter يخرجه من السنة وما بعدها بكل الصفحات والتقارير.
 *   • لا سنين له قبلها (ملف جديد أُدخل بالغلط) أو المعروض «كل السنين» ⇒ حذف الملف كلّه (ناعم، يُسترجع) كما كان — وصفحة التأكيد تقول ذلك صراحةً.
 *   • الأشهر المدفوعة محميّة: له شهر مدفوع بالسنة المعروضة أو بعدها ⇒ لا يُشال منها (يبقى خيار حذف الملف كلّه بتأكيده الصريح).
 */

/** أين هو موجود وماذا يعني حذفه من السنة $sy ⇒ years[سنة => [n, paid]] · before[] · from[] · paid_from · can_year · left_date */
function empDeleteScope(PDO $db, int $id, string $sy): array {
    $st = $db->prepare("SELECT school_year, COUNT(*) n, SUM(CASE WHEN COALESCE(is_paid, 0) = 1 THEN 1 ELSE 0 END) paid
                        FROM monthly_salaries
                        WHERE employee_id = ? AND school_year REGEXP '^[0-9]{4}-[0-9]{4}$'
                          AND (base_plus_echelon_lbp > 0 OR net_salary_lbp > 0 OR total_due_lbp > 0 OR COALESCE(is_paid, 0) = 1)
                        GROUP BY school_year ORDER BY school_year");
    $st->execute([$id]);
    $years = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $years[(string)$r['school_year']] = ['n' => (int)$r['n'], 'paid' => (int)$r['paid']];
    $isYear = preg_match('/^(\d{4})-(\d{4})$/', $sy, $m) === 1;
    $before = []; $from = []; $paidFrom = 0;
    if ($isYear) foreach ($years as $y => $d) {
        if (strcmp($y, $sy) < 0) $before[] = $y; else { $from[] = $y; $paidFrom += $d['paid']; }
    }
    return ['sy' => $sy, 'is_year' => $isYear, 'years' => $years, 'before' => $before, 'from' => $from, 'paid_from' => $paidFrom,
            'can_year' => $isYear && $before && $paidFrom === 0, 'left_date' => $isYear ? ($m[1] . '-09-30') : null];
}

/** شيل الموظف من السنة $sy وما بعدها فقط (يبقى بما قبلها) ⇒ ['ok', 'deleted' عدد الأشهر المشالة, 'left' تاريخ الترك, 'msg'] */
function empRemoveFromYear(PDO $db, int $id, string $sy): array {
    $sc = empDeleteScope($db, $id, $sy);
    if (!$sc['is_year']) return ['ok' => false, 'msg' => 'اختر سنة دراسية محدّدة أوّلاً.'];
    if (!$sc['before']) return ['ok' => false, 'msg' => 'لا سنين له قبل ' . $sy . ' — لا يُشال من سنة، يُحذف الملف.'];
    if ($sc['paid_from'] > 0) return ['ok' => false, 'msg' => 'له ' . $sc['paid_from'] . ' شهراً مدفوعاً بسنة ' . $sy . ' أو بعدها — الأشهر المدفوعة محميّة فلا يُشال منها.'];
    ensureLeftDateAllColumn();
    $q = $db->prepare("SELECT left_date_all FROM employees WHERE id = ?"); $q->execute([$id]);
    $old = (string)$q->fetchColumn(); $oldValid = ($old !== '' && $old >= '1900-01-01') ? substr($old, 0, 10) : null;
    $new = $sc['left_date'];
    // تاريخ ترك أبكر مسجَّل من قبل يبقى (هو أصلاً خارج هذه السنة) — الأحدث أو الفاضي يصير 30/9 قبل بداية السنة
    if ($oldValid === null || $oldValid > $new) $db->prepare("UPDATE employees SET left_date_all = ? WHERE id = ?")->execute([$new, $id]);
    $n = (int)pruneSalariesAfterDeparture($db, $id);
    logAudit('remove_from_year', 'employees', $id, ['left_date_all' => $oldValid], ['left_date_all' => ($oldValid !== null && $oldValid <= $new) ? $oldValid : $new, 'from_year' => $sy, 'months_removed' => $n, 'kept_years' => $sc['before']]);
    return ['ok' => true, 'deleted' => $n, 'left' => $new, 'msg' => ''];
}

/** استرجاع ملف محذوف ناعماً (is_deleted=1 ⇒ 0) — رواتبه وعلاواته لم تُمسّ بالحذف فيرجع بكل سنينه */
function empRestoreFile(PDO $db, int $id, string $why = ''): bool {
    $st = $db->prepare("UPDATE employees SET is_deleted = 0 WHERE id = ? AND is_deleted = 1"); $st->execute([$id]);
    if ($st->rowCount() < 1) return false;
    try { logAudit('restore', 'employees', $id, ['is_deleted' => 1], ['is_deleted' => 0, 'why' => $why]); } catch (Throwable $e) {}
    return true;
}

/**
 * 🩹 مرّة واحدة (2026-11-09 «بدي رجّعها على السنتين»): روزالي جان زيتو (أبلح، ملف 1831) حُذفت 9/11 الساعة 7:35 من سنة 2026-2027
 * فاختفت من 2025-2026 أيضاً (الحذف القديم = الملف كلّه). تُرجَع كما كانت: رواتبها الـ10 بـ2025-2026 والـ12 بـ2026-2027 لم تُمسّ.
 * المطابقة بالرقم والاسم والمدرسة معاً؛ إن لم تكن محذوفة لا يحصل شيء. يعمل محلياً وأونلاين (مفتاح heal_ خاصّ بالجهاز).
 */
function healRestoreRosalieZeito20261109(): void {
    if (getSetting('heal_restore_rosalie_zeito_20261109', '') !== '') return;
    try {
        $db = getDB();
        $q = $db->query("SELECT id FROM employees WHERE id = 1831 AND school_id = 7 AND is_deleted = 1 AND first_name_ar LIKE 'روزالي%' AND last_name_ar LIKE 'زيتو%'");
        $id = (int)$q->fetchColumn(); $done = 'not_needed';
        if ($id === 1831) {
            $st = $db->prepare("UPDATE employees SET is_deleted = 0 WHERE id = ? AND is_deleted = 1"); $st->execute([$id]);
            if ($st->rowCount() > 0) {
                $done = 'restored';
                try { $db->prepare("INSERT INTO audit_log (user_id, username, action, table_name, record_id, old_value, new_value, ip_address) VALUES (?, ?, 'restore', 'employees', ?, ?, ?, ?)")
                         ->execute([(int)($_SESSION['user_id'] ?? 0), 'heal', $id, json_encode(['is_deleted' => 1]), json_encode(['is_deleted' => 0, 'why' => 'حذف 2026-11-09 شال الملف من كل السنين — أُرجعت بطلبه على السنتين'], JSON_UNESCAPED_UNICODE), (string)($_SERVER['REMOTE_ADDR'] ?? 'cli')]); } catch (Throwable $e) {}
            }
        }
        setSetting('heal_restore_rosalie_zeito_20261109', date('Y-m-d H:i:s') . ' ' . $done);
    } catch (Throwable $e) { /* يُعاد بالفتحة التالية */ }
}
