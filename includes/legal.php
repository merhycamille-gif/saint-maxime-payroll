<?php
/**
 * ⚖️📅 الرزنامة القانونية + قفل التقارير المبعوتة للدولة (2026-10-10 v2026 — بكلماته):
 *   «في تنبيهات قانونية: شو لازم قدّم لوائح ومدفوعات للدولة، إلها أوقات محدّدة خلال السنة»
 *   «بس اطبع تقرير بدي ابعتو للدولة لازم يكون في محل حطّ انبعت وما بقى يتغيّر شي بالتقرير… وإذا بدّو يتغيّر لازم يطلع مساج: يا بصحّحو يا بيضلّ متل ما هو»
 *
 *   legal_deadlines : المواعيد (مُسبقة بمواعيد لبنان، قابلة للتعديل من صفحة الرزنامة) — نوع: فصلي/سنوي/شهري، يوم الاستحقاق والأشهر.
 *   legal_filings   : كل تقرير «انبعت»: المفتاح + الفترة + نسخة طبق الأصل (JSON للجداول) + بصمة + النسخة + علم «تغيّر منذ الإرسال» مع السبب.
 *   التنبيه قبل 15 / 7 / 3 أيام يصل للجرس ولـ«شو لازم تعمل» (todo.php) ولشريط الأشهر. المواعيد المتأخّرة تبقى حمراء حتى تُعلَّم «انبعت».
 */
function legalEnsureTables(): void {
    static $done = false; if ($done) return; $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS legal_deadlines (
            dkey VARCHAR(40) PRIMARY KEY, authority VARCHAR(20) NOT NULL, title_fr VARCHAR(160) NOT NULL, title_ar VARCHAR(160) NOT NULL,
            kind ENUM('monthly','quarterly','annual','custom') NOT NULL DEFAULT 'quarterly',
            due_day TINYINT NOT NULL DEFAULT 15, due_months VARCHAR(40) NOT NULL DEFAULT '1,4,7,10', period_type ENUM('month','quarter','year','school_year') NOT NULL DEFAULT 'quarter',
            report_href VARCHAR(255) NULL, alert_days VARCHAR(20) NOT NULL DEFAULT '15,7,3', notes VARCHAR(255) NULL, active TINYINT NOT NULL DEFAULT 1, sort_order SMALLINT NOT NULL DEFAULT 100
        ) DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS legal_filings (
            id INT AUTO_INCREMENT PRIMARY KEY, dkey VARCHAR(80) NOT NULL, period VARCHAR(20) NOT NULL, school_scope VARCHAR(80) NOT NULL DEFAULT '',
            version SMALLINT NOT NULL DEFAULT 1, status VARCHAR(12) NOT NULL DEFAULT 'sent', sent_at DATETIME NOT NULL, sent_by VARCHAR(80) NULL,
            title VARCHAR(255) NULL, href VARCHAR(500) NULL, snapshot_hash CHAR(64) NOT NULL, snapshot_json MEDIUMTEXT NULL,
            seen_hash CHAR(64) NULL, changed_flag TINYINT NOT NULL DEFAULT 0, changed_note TEXT NULL, note VARCHAR(255) NULL,
            INDEX (dkey, period), INDEX (changed_flag)
        ) DEFAULT CHARSET=utf8mb4");
        try { $db->exec("ALTER TABLE legal_deadlines ADD COLUMN report_key VARCHAR(80) NULL"); } catch (Throwable $e) {}
        // 📐 lag_months = كم شهراً بعد نهاية الفترة يقع الموعد (0 = خلال الشهر الأخير من الفترة نفسها). أوّل تركيب للعمود ⇒ إعادة البذر بمواعيده هو
        //    (2026-10-10 مساءً بكلماته: الضمان الشهري خلال شهر من نهاية الشهر · الفصلي خلال 3 أشهر من نهاية الفصل · التسوية السنوية نهاية آذار ·
        //     ر10 خلال 15 يوماً من نهاية الفصل · ر5/ر6/ر7 نهاية شباط · الصندوق: المحسومات الفصلية خلال الشهر الثالث من كل فصل والبيان العام (ملاك/متعاقد) خلال كانون الأول)
        $reseed = false;
        try { $db->exec("ALTER TABLE legal_deadlines ADD COLUMN lag_months TINYINT NOT NULL DEFAULT 1"); $reseed = true; } catch (Throwable $e) {}
        if ($reseed) $db->exec("DELETE FROM legal_deadlines");
        // مفتاح التقرير داخل البرنامج (صفحة:نموذج) ⇒ كبسة «انبعت للدولة» بالتقرير تُقفل الموعد نفسه بالرزنامة
        $rk = ['cnss_monthly' => 'official_forms:cnss_contrib_monthly', 'cnss_quarterly' => 'official_forms:cnss_contrib_annual', 'cnss_annual' => 'official_forms:cnss_taswiya', 'mof_r10' => 'official_forms:tax_r10',
               'mof_annual' => 'official_forms:tax_r6', 'eoc_quarterly' => 'official_forms:eoc_quarterly', 'eoc_annual_tit' => 'official_forms:eoc_staff:titulaire', 'eoc_annual_con' => 'official_forms:eoc_staff:contractuel'];
        if ((int)$db->query("SELECT COUNT(*) FROM legal_deadlines")->fetchColumn() === 0) {
            $B = BASE_URL;
            $seed = [
                // dkey, authority, fr, ar, kind, day, months, period_type, href, notes, order, lag_months
                ['cnss_monthly',   'cnss', 'CNSS — Déclaration mensuelle des cotisations', 'الضمان — التصريح الشهري عن الاشتراكات', 'monthly', 31, '1,2,3,4,5,6,7,8,9,10,11,12', 'month', $B . 'pages/official_forms.php?form=cnss_contrib_monthly', 'خلال شهر من نهاية الشهر السابق', 10, 1],
                ['cnss_quarterly', 'cnss', 'CNSS — Déclaration trimestrielle des cotisations', 'الضمان — التصريح الفصلي عن الاشتراكات', 'quarterly', 31, '3,6,9,12', 'quarter', $B . 'pages/official_forms.php?form=cnss_contrib_annual', 'خلال 3 أشهر من نهاية الفصل', 20, 3],
                ['cnss_annual',    'cnss', 'CNSS — Régularisation annuelle', 'الضمان — التسوية السنوية', 'annual', 31, '3', 'year', $B . 'pages/official_forms.php?form=cnss_taswiya', 'نهاية آذار عن السنة المنصرمة', 30, 3],
                ['mof_r10',        'mof', 'Finances — R10 déclaration trimestrielle de l’impôt retenu', 'المالية — ر10 التصريح الفصلي عن الضريبة المقتطعة', 'quarterly', 15, '1,4,7,10', 'quarter', $B . 'pages/official_forms.php?form=tax_r10', 'خلال 15 يوماً من نهاية الفصل', 40, 1],
                ['mof_annual',     'mof', 'Finances — R5 / R6 / R7 régularisation annuelle', 'المالية — ر5 / ر6 / ر7 التسوية السنوية', 'annual', 28, '2', 'year', $B . 'pages/official_forms.php?form=tax_r6', 'نهاية شباط عن السنة المنصرمة', 50, 2],
                ['eoc_quarterly',  'eoc', 'Caisse des indemnités — retenues trimestrielles', 'صندوق التعويضات — المحسومات الفصلية', 'quarterly', 31, '3,6,9,12', 'quarter', $B . 'pages/official_forms.php?form=eoc_quarterly', 'خلال الشهر الثالث من كل فصل', 60, 0],
                ['eoc_annual_tit', 'eoc', 'Caisse des indemnités — état général annuel (titulaires)', 'صندوق التعويضات — البيان العام السنوي (الملاك)', 'annual', 31, '12', 'school_year', $B . 'pages/official_forms.php?form=eoc_staff&cat=titulaire', 'خلال كانون الأول', 70, 0],
                ['eoc_annual_con', 'eoc', 'Caisse des indemnités — état général annuel (contractuels)', 'صندوق التعويضات — البيان العام السنوي (المتعاقدون)', 'annual', 31, '12', 'school_year', $B . 'pages/official_forms.php?form=eoc_staff&cat=contractuel', 'خلال كانون الأول', 80, 0],
            ];
            $ins = $db->prepare("INSERT INTO legal_deadlines (dkey, authority, title_fr, title_ar, kind, due_day, due_months, period_type, report_href, notes, sort_order, lag_months) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($seed as $s) $ins->execute($s);
        }
        $fix = $db->prepare("UPDATE legal_deadlines SET report_key = ? WHERE dkey = ? AND (report_key IS NULL OR report_key = '')");
        foreach ($rk as $k => $v) $fix->execute([$v, $k]);
        $db->exec("UPDATE legal_deadlines SET report_href = REPLACE(report_href, 'form=cnss_contrib_monthly', 'form=cnss_contrib_annual') WHERE dkey = 'cnss_quarterly'");
    } catch (Throwable $e) {}
}

/** الفترة التي يغطّيها موعد يقع بتاريخ معيّن: فصلي ⇒ الفصل السابق، سنوي ⇒ السنة السابقة، شهري ⇒ الشهر السابق */
function legalPeriodForDue(array $d, int $dueY, int $dueM): string {
    // الفترة = الشهر الذي يسبق الموعد بـlag_months (0 ⇒ الشهر نفسه): فصلي ⇒ الفصل الذي ينتهي بذلك الشهر، سنوي ⇒ سنته، دراسي ⇒ السنة الدراسية التي تحويه
    $lag = max(0, min(12, (int)($d['lag_months'] ?? 1)));
    $m = $dueM - $lag; $y = $dueY; while ($m <= 0) { $m += 12; $y--; }
    if ($d['period_type'] === 'quarter') return $y . '-Q' . (int)ceil($m / 3);
    if ($d['period_type'] === 'month')   return $y . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    if ($d['period_type'] === 'school_year') { $sy = $m >= 10 ? $y : $y - 1; return $sy . '-' . ($sy + 1); }
    return (string)$y;
}

/** كل الاستحقاقات من اليوم - 400 يوم إلى اليوم + $horizon يوم، مع حالة كل واحد (انبعت/معلّق/متأخّر) */
function legalOccurrences(int $horizonDays = 120, int $backDays = 400): array {
    legalEnsureTables();
    $db = getDB(); $out = [];
    try {
        $deadlines = $db->query("SELECT * FROM legal_deadlines WHERE active = 1 ORDER BY sort_order, dkey")->fetchAll();
        $filings = []; foreach ($db->query("SELECT * FROM legal_filings WHERE status = 'sent' ORDER BY version") as $f) $filings[$f['dkey'] . '|' . $f['period']] = $f;
        $today = new DateTimeImmutable('today'); $from = $today->modify("-$backDays days"); $to = $today->modify("+$horizonDays days");
        $trackFrom = new DateTimeImmutable(substr((string)currentSchoolYear(), 0, 4) . '-10-01'); // بداية المتابعة = بداية السنة الدراسية الحالية
        foreach ($deadlines as $d) {
            $months = array_filter(array_map('intval', explode(',', (string)$d['due_months'])));
            for ($y = (int)$from->format('Y'); $y <= (int)$to->format('Y'); $y++) {
                foreach ($months as $m) {
                    $day = min((int)$d['due_day'], (int)(new DateTimeImmutable("$y-$m-01"))->format('t'));
                    $due = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day));
                    if ($due < $from || $due > $to) continue;
                    $period = legalPeriodForDue($d, $y, $m);
                    $f = $filings[$d['dkey'] . '|' . $period] ?? (!empty($d['report_key']) ? ($filings[$d['report_key'] . '|' . $period] ?? null) : null);
                    $daysLeft = (int)$today->diff($due)->format('%r%a');
                    $status = $f ? 'sent' : ($daysLeft < 0 ? 'late' : 'pending');
                    if ($status !== 'sent' && $due < $trackFrom) continue; // ما قبل السنة الدراسية الحالية: مُقدَّم سابقاً خارج البرنامج — لا يُعرض متأخّراً
                    $out[] = ['d' => $d, 'due' => $due->format('Y-m-d'), 'days' => $daysLeft, 'period' => $period, 'status' => $status, 'filing' => $f];
                }
            }
        }
        usort($out, fn($a, $b) => strcmp($a['due'], $b['due']));
    } catch (Throwable $e) {}
    return $out;
}

/** بنود «شو لازم تعمل» القانونية: متأخّر، أو ضمن أيام التنبيه (15/7/3) وغير مبعوت، + تقارير مبعوتة تغيّرت */
function legalTodoItems(): array {
    $t = []; $B = BASE_URL; $due = []; $late = 0;
    foreach (legalOccurrences(45, 120) as $o) {
        if ($o['status'] === 'sent') continue;
        $alert = max(array_map('intval', explode(',', (string)$o['d']['alert_days'])) ?: [15]);
        if ($o['days'] > $alert) continue;
        if ($o['status'] === 'late') $late++;
        $due[] = $o;
    }
    if ($due) { // بند واحد مجمَّع: العدد + الأقرب (بدل بند لكل موعد — الجرس يبقى نظيفاً)
        $n = $due[0]; $per = legalPeriodLabel($n['period']);
        $when = $n['days'] < 0 ? 'متأخّر ' . abs($n['days']) . ' يوم' : ($n['days'] === 0 ? 'اليوم!' : 'باقي ' . $n['days'] . ' يوم');
        $short = preg_replace('/ — .*$/u', '', $n['d']['title_ar']); // «الضمان» / «المالية» / «صندوق التعويضات»
        $t[] = ['fas fa-landmark', $late ? 'var(--ic1)' : 'var(--ic3)', count($due), 'Échéances État — ' . count($due) . ($late ? ' (' . $late . ' en retard)' : ''), 'مواعيد للدولة' . ($late ? ' — منها ' . $late . ' متأخّرة' : '') . ' · الأقرب: ' . $short . ' ' . $per . ' — ' . $when . ' (' . date('d/m', strtotime($n['due'])) . ')', $B . 'pages/legal_calendar.php#cal', 'legal', $n['days']];
    }
    try {
        $n = (int)getDB()->query("SELECT COUNT(*) FROM legal_filings WHERE status = 'sent' AND changed_flag = 1")->fetchColumn();
        if ($n) $t[] = ['fas fa-triangle-exclamation', 'var(--ic1)', $n, 'Rapports envoyés à l’État modifiés depuis', 'تقارير انبعتت للدولة وتغيّرت بعدها — صحّح أو خلّي', $B . 'pages/legal_calendar.php#changed', 'legal_changed', -1];
    } catch (Throwable $e) {}
    return $t;
}

function legalPeriodLabel(string $p): string {
    if (preg_match('/^(\d{4})-Q([1-4])$/', $p, $m)) return 'T' . $m[2] . ' ' . $m[1] . ' / الفصل ' . $m[2];
    if (preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return monthName((int)$m[2]) . ' ' . $m[1];
    return $p;
}

/** سياق التقرير المعروض (أي صفحة مستند): مفتاح + فترة مشتقّان من الصفحة ووسائطها — يُستعمل لزرّ «انبعت» والمقارنة */
function legalFilingContext(): ?array {
    if (empty($GLOBALS['docFocus'])) return null;
    $script = basename($_SERVER['SCRIPT_NAME'] ?? ''); $g = $_GET;
    $name = (string)($g['report'] ?? $g['form'] ?? ''); if ($name === '' && !in_array($script, ['annual_slip.php', 'mehe_budget.php'], true)) return null;
    $key = preg_replace('/\.php$/', '', $script) . ':' . ($name !== '' ? $name : 'doc');
    if (!empty($g['rq']) && !empty($g['rqy'])) $period = (int)$g['rqy'] . '-Q' . (int)$g['rq']; // R10
    elseif (!empty($g['quarter']) && !empty($g['year'])) $period = (int)$g['year'] . '-Q' . (int)$g['quarter'];
    elseif (!empty($g['month']) && !empty($g['year'])) $period = (int)$g['year'] . '-' . str_pad((string)(int)$g['month'], 2, '0', STR_PAD_LEFT);
    elseif (!empty($g['school_year']) && preg_match('/^\d{4}-\d{4}$/', (string)$g['school_year'])) $period = (string)$g['school_year'];
    elseif (!empty($g['fy'])) $period = (string)(int)$g['fy']; // تسوية الضمان السنوية (السنة المالية)
    elseif (!empty($g['year'])) $period = (string)(int)$g['year'];
    else $period = activeSchoolYear() === 'all' ? currentSchoolYear() : activeSchoolYear();
    $scope = implode(',', array_map('intval', activeSchoolIds())) ?: 'all';
    if (!empty($g['schools']) && is_array($g['schools'])) $scope = implode(',', array_map('intval', $g['schools']));
    if (!empty($g['cat'])) $key .= ':' . preg_replace('/[^a-z]/', '', (string)$g['cat']); // البيان العام: ملاك / متعاقدون
    if (!empty($g['employee_id'])) $key .= ':emp' . (int)$g['employee_id'];
    legalEnsureTables();
    $f = null;
    try { $st = getDB()->prepare("SELECT * FROM legal_filings WHERE dkey = ? AND period = ? AND school_scope = ? ORDER BY version DESC LIMIT 1"); $st->execute([$key, $period, $scope]); $f = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    return ['key' => $key, 'period' => $period, 'scope' => $scope, 'filing' => $f];
}

/** تعديل بيانات يمسّ فترة مبعوتة ⇒ علّم التقارير المبعوتة المعنيّة «تغيّرت» مع السبب (يُستدعى من المحرّك عند تغيّر راتب شهر) */
function legalTouch(int $schoolId, int $year, int $month, string $who, string $what = ''): void {
    static $any = null;
    try {
        legalEnsureTables(); $db = getDB();
        if ($any === null) $any = (int)$db->query("SELECT COUNT(*) FROM legal_filings WHERE status = 'sent'")->fetchColumn();
        if (!$any) return; // لا تقارير مبعوتة ⇒ لا شيء يُعلَّم (إعادة الاحتساب الجماعي تبقى خفيفة)
        $sy = $month >= 10 ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
        $periods = [$year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT), $year . '-Q' . (int)ceil($month / 3), (string)$year, $sy];
        $in = implode(',', array_fill(0, count($periods), '?'));
        $rows = $db->prepare("SELECT id, school_scope, changed_note FROM legal_filings WHERE status = 'sent' AND period IN ($in)"); $rows->execute($periods);
        $note = date('d/m/Y H:i') . ' — ' . $who . ' — ' . monthName($month) . ' ' . $year . ($what !== '' ? ' — ' . $what : '');
        $upd = $db->prepare("UPDATE legal_filings SET changed_flag = 1, changed_note = ? WHERE id = ?");
        foreach ($rows->fetchAll() as $r) {
            if ($r['school_scope'] !== '' && $r['school_scope'] !== 'all' && !in_array((string)$schoolId, explode(',', $r['school_scope']), true)) continue;
            $old = (string)$r['changed_note']; if (strpos($old, $note) !== false) continue;
            $lines = array_filter(explode("\n", $old)); array_unshift($lines, $note);
            $upd->execute([implode("\n", array_slice($lines, 0, 30)), $r['id']]);
        }
        unset($_SESSION['msa_todo_light']);
    } catch (Throwable $e) {}
}
