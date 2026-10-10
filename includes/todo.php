<?php
/**
 * 🔔 «شو لازم تعمل اليوم» — المصدر الواحد لبطاقات لوحة القيادة وللجرس بالشريط العلوي (2026-10-10 v2026).
 *   msaTodoItems($db, $extra) ⇒ قائمة [icon, color, n, fr, ar, href, key]
 *   $extra (من لوحة القيادة فقط): comp (تقرير المخالفات)، cd (الترسيم)، hr (تناقص الساعات)، a64 (بلوغ الـ64) — تُحفَظ بالجلسة
 *   ليستعملها الجرس على باقي الصفحات بلا إعادة حساب ثقيل. الأعداد الخفيفة (رواتب/ضمان/مكرّرون/ناقصون) تُحسب هنا بكاش جلسة 120 ثانية.
 */
function msaTodoItems(PDO $db, ?array $extra = null): array {
    $scopeKey = md5(schoolScopeSql('e.school_id') . '|' . activeSchoolYear() . '|' . (canEdit() ? 'w' : 'r'));
    if ($extra !== null) {
        $_SESSION['msa_todo_heavy'] = ['k' => $scopeKey, 't' => time(),
            'comp' => ($extra['comp'] && !empty($extra['comp']['pending'])) ? count(array_filter($extra['comp']['pending'], fn($it) => ($it['rule'] ?? '') !== 'carried_zero')) : 0,
            'cd' => count($extra['cd'] ?? []), 'hr' => count($extra['hr'] ?? []), 'a64' => count($extra['a64'] ?? [])];
    }
    $heavy = (isset($_SESSION['msa_todo_heavy']) && $_SESSION['msa_todo_heavy']['k'] === $scopeKey) ? $_SESSION['msa_todo_heavy'] : ['comp' => 0, 'cd' => 0, 'hr' => 0, 'a64' => 0];
    $light = (isset($_SESSION['msa_todo_light']) && $_SESSION['msa_todo_light']['k'] === $scopeKey && time() - $_SESSION['msa_todo_light']['t'] < 120) ? $_SESSION['msa_todo_light']['v'] : null;
    if ($light === null) {
        $light = ['sal' => 0, 'tm' => (int)date('n'), 'ty' => (int)date('Y'), 'dup' => 0, 'ou' => 0, 's1' => 0, 's2' => 0, 'inc' => 0];
        try {
            if (viewerCanSeePage('monthly_payroll.php')) {
                $tm = $light['tm']; $ty = $light['ty']; $tsy = ($tm >= 10) ? ($ty . '-' . ($ty + 1)) : (($ty - 1) . '-' . $ty);
                [$tf, $tp] = yearEmploymentFilter($tsy, 'e.');
                $st = $db->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.month = ? AND ms.year = ?
                    WHERE e.is_deleted = 0 AND e.status = 'actif'" . schoolScopeSql('e.school_id') . $tf . " AND COALESCE(ms.is_calculated, 0) = 0");
                $st->execute(array_merge([$tm, $ty], $tp)); $light['sal'] = (int)$st->fetchColumn();
            }
            if (canEdit()) {
                $light['dup'] = (int)$db->query("SELECT COUNT(*) FROM (SELECT school_id, LOWER(TRIM(CONCAT(first_name_fr,' ',last_name_fr))) k, COUNT(*) c,
                    SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM monthly_salaries m WHERE m.employee_id = e.id AND m.is_calculated = 1) THEN 1 ELSE 0 END) empties
                    FROM employees e WHERE e.is_deleted = 0 AND e.status = 'actif' AND TRIM(CONCAT(first_name_fr,' ',last_name_fr)) <> ''" . schoolScopeSql('e.school_id') . " GROUP BY school_id, k HAVING c > 1 AND empties > 0) d")->fetchColumn();
                $cy = currentSchoolYear(); $prev = ((int)substr($cy, 0, 4) - 1) . '-' . substr($cy, 0, 4);
                $st = $db->prepare("SELECT COUNT(*) FROM (SELECT e.id FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.is_calculated = 1 WHERE e.is_deleted = 0" . schoolScopeSql('e.school_id') . "
                    GROUP BY e.id HAVING SUM(m.cnss_amount_lbp) = 0 AND SUM(m.school_year = ?) = 0 AND SUM(m.school_year <= ?) > 0) q");
                $st->execute([$cy, $prev]); $light['ou'] = (int)$st->fetchColumn();
                $light['s1'] = (int)$db->query("SELECT COUNT(*) FROM (SELECT e.id FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.is_calculated = 1 AND m.school_year >= '2025-2026' WHERE e.is_deleted = 0 AND e.cnss_subject <> 0" . schoolScopeSql('e.school_id') . " GROUP BY e.id HAVING SUM(m.cnss_amount_lbp) = 0) q")->fetchColumn();
                $light['s2'] = (int)$db->query("SELECT COUNT(*) FROM (SELECT e.id FROM employees e JOIN monthly_salaries m ON m.employee_id = e.id AND m.is_calculated = 1 WHERE e.is_deleted = 0 AND (e.nssf_number IS NULL OR TRIM(e.nssf_number) IN ('', '0'))" . schoolScopeSql('e.school_id') . " GROUP BY e.id HAVING SUM(m.cnss_amount_lbp) > 0) q")->fetchColumn();
                $incSy = activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear();
                $light['inc'] = count(incompleteEmployeesRows($db, $incSy, schoolScopeSql('e.school_id')));
            }
        } catch (Throwable $e) {}
        $light['legal'] = (canEdit() && function_exists('legalTodoItems')) ? legalTodoItems() : []; // ⚖️ مواعيد الدولة (15/7/3 أيام) + تقارير مبعوتة تغيّرت
        $_SESSION['msa_todo_light'] = ['k' => $scopeKey, 't' => time(), 'v' => $light];
    }
    $B = BASE_URL; $t = [];
    if ($light['sal'])  $t[] = ['fas fa-money-check-alt', 'var(--ic5)', $light['sal'], 'Salaires à calculer — ' . monthName($light['tm']), 'رواتب ' . monthName($light['tm'], 'ar') . ' بانتظار الاحتساب', $B . 'pages/monthly_payroll.php?month=' . $light['tm'] . '&year=' . $light['ty'], 'sal'];
    foreach (($light['legal'] ?? []) as $li) $t[] = array_slice($li, 0, 7);
    if ($heavy['comp']) $t[] = ['fas fa-balance-scale', 'var(--ic1)', $heavy['comp'], 'Conformité — décisions en attente', 'مخالفات بانتظار قرارك (موافق / لا)', $B . 'index.php#homeCompBox', 'comp'];
    if ($heavy['cd'])   $t[] = ['fas fa-graduation-cap', 'var(--ic4)', $heavy['cd'], 'Titularisations à approuver', 'متعاقدون أكملوا سنتين — بانتظار قرارك', $B . 'pages/cadre_due.php', 'cd'];
    if ($light['dup'])  $t[] = ['fas fa-user-group', 'var(--ic1)', $light['dup'], 'Doublons à nettoyer', 'أسماء مكرّرة بنفس المدرسة — حذف بتأكيد', $B . 'pages/duplicates.php', 'dup'];
    if ($light['ou'])   $t[] = ['fas fa-user-slash', 'var(--ic6)', $light['ou'], 'Anciens non assurés à retirer', 'قدامى تركوا بلا أي ضمان محسوم — حذف بتأكيد', $B . 'pages/old_uninsured.php', 'ou'];
    if ($light['s1'] || $light['s2']) $t[] = ['fas fa-user-shield', 'var(--ic4)', $light['s1'] + $light['s2'], 'Suivi CNSS — à décider / à compléter', ($light['s1'] ? $light['s1'] . ' بلا ضمان بانتظار قرارك' : '') . ($light['s1'] && $light['s2'] ? ' · ' : '') . ($light['s2'] ? $light['s2'] . ' ناقصهم رقم الضمان' : ''), $B . 'pages/cnss_followup.php', 'cnss'];
    if ($light['inc'])  $t[] = ['fas fa-user-edit', 'var(--ic3)', $light['inc'], 'Dossiers incomplets', 'ملفات ناقصة بلا راتب محسوب', $B . 'index.php#homeIncBox', 'inc'];
    if ($heavy['hr'])   $t[] = ['fas fa-clock', 'var(--ic6)', $heavy['hr'], "Réductions d'heures à confirmer", 'تناقص ساعات بانتظار الإذن', $B . 'index.php', 'hr'];
    if ($heavy['a64'])  $t[] = ['fas fa-hourglass-half', 'var(--ic2)', $heavy['a64'], 'Retraite 64 — à traiter', 'بلغوا سنّ الـ64 — قرار', $B . 'pages/retirement_64.php', 'a64'];
    return $t;
}
/** يمسح كاش الجرس (بعد أي حفظ/حذف يغيّر الأعداد) */
function msaTodoInvalidate(): void { unset($_SESSION['msa_todo_light']); }
