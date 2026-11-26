<?php
/**
 * 🔒 قفل «المصدر الواحد» (2026-11-26 — المدير: «البرنامج لازم يكون معمول هوّي يصلّح كل شي بمجرّد ما إنت عملت تصليحة معيّنة… ممنوع نروح نشوف وين تصحّح وين ما تصحّح»).
 *
 * كل قاعدة (قانون / حساب / خضوع / إعفاء) تُكتب مرّة واحدة بمصدرها، وكل الشاشات والتقارير والتصاريح تأخذها منه.
 * هذا الملفّ = السجلّ: لكل قاعدة «بصمة» بالكود + الملفّات الوحيدة المسموح أن تظهر فيها. أيّ ظهور خارجها = قاعدة مكرّرة ⇒ الفحص يفشل ⇒ لا نشر.
 * يُشغَّل وحده (php tools/regles_uniques.php) ويُستدعى من tools/regression_check.php. لإضافة قاعدة جديدة: سطر واحد بالسجلّ أدناه.
 */
function reglesUniquesRegistre(): array {
    $F = 'includes/functions.php'; $C = 'includes/payroll_calculator.php';
    return [
        // [الاسم، البصمة (regex)، الملفّات المسموحة، شرح المصدر]
        ['سنّ الـ64 (صندوق التعويضات / نهاية الخدمة)', '/keep_working_past_64|>=\s*64\b|\+\s*64\s*years?|[\'"]\s*\+64 years/u',
            [$F, $C, 'includes/age64.php', 'migrate.php'], 'empPast64() · emp64Date() · titulaireExemptEoc64() · employeExemptEos64() · monthSubjectTo(…, \'eoc\')'],
        ['نسبة صندوق التعويضات (٦٪)', '/getRateAsOf\(\s*[\'"]eoc_(employee|employer)_rate/', [$C, $F, 'includes/report_helpers.php'], 'المحرّك — التقارير تقرأ caisse_amount_lbp / school_eoc_6_lbp المخزَّنين'],
        ['نِسَب الضمان (٣٪ / ٨٪ / ٦٪ / ٨,٥٪)', '/getRateAsOf\(\s*[\'"](cnss_employee_rate|cnss_employer_rate|family_compensation_rate|end_of_service_rate)/', [$C, $F, 'includes/report_helpers.php', 'pages/health_check.php'], 'المحرّك + backfillEmployerCharges'],
        ['سقوف الضمان', '/FROM\s+cnss_brackets\b/i', [$F, 'pages/settings.php', 'pages/state_laws.php', 'pages/social_security.php'], 'clampCnssBase() — صفحة social_security = إدخال السقوف فقط'],
        ['الخضوع بعد تاريخ الترك (ضمان / مالية / صندوق)', '/left_date_(cnss|finance|eoc)\s*(<|>|<=|>=)\s*|(<|>|<=|>=)\s*\$?\w*\[?[\'"]?left_date_(cnss|finance|eoc)/', [$F, 'includes/eoc_portal.php', 'fix_departures_once.php'], 'leftDateOfFor() · monthSubjectTo() · leftDateSql()'],
        ['نِسَب قانونية مكتوبة باليد (0.06 / 0.03 / 0.08 / 0.085)', '/\*\s*0\.(06|03|08|085)\b|\*\s*(6|3|8|8\.5)\s*\/\s*100\b/', [$F, $C], 'rateFrac() ⇐ «النِّسَب حسب التاريخ»'],
        ['إصدار سلسلة الرواتب مثبَّت باليد', '/version_id\s*=\s*1\b/', [$F, $C, 'pages/salary_scales.php'], 'scaleVersionIdAsOf() · scaleSalaryMap() · scaleSalaryLBP()'],
        ['شطور ضريبة الدخل', '/FROM\s+tax_brackets\b/i', [$F, $C, 'pages/settings.php', 'pages/state_laws.php', 'pages/tax_brackets.php'], 'دالّة الضريبة بالمحرّك'],
        ['فروع الضمان حسب الجنسيّة (غير اللبناني)', '/cnss_br_(maladie|family|eos)\b/', [$F, $C, 'pages/employees.php', 'pages/teacher_form.php', 'includes/annual_slip_data.php'], 'cnssBranchOn()'],
    ];
}
/** يُرجع المخالفات: [[قاعدة، ملفّ، سطر، نصّ], …] */
function reglesUniquesVerifier(string $racine): array {
    $viol = []; $reg = reglesUniquesRegistre();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', substr($f->getPathname(), strlen($racine) + 1));
        if (substr($p, -4) !== '.php' || preg_match('#^(vendor|tools|tmp|sql|backups?|node_modules|uploads)/#', $p)) continue;
        $lignes = null;
        foreach ($reg as [$nom, $rx, $ok, $src]) {
            if (in_array($p, $ok, true)) continue;
            if ($lignes === null) $lignes = file($f->getPathname());
            foreach ($lignes as $i => $l) {
                $t = ltrim($l); if ($t === '' || strncmp($t, '//', 2) === 0 || strncmp($t, '*', 1) === 0 || strncmp($t, '/*', 2) === 0 || strncmp($t, '#', 1) === 0) continue;   // التعليقات لا تُحسب
                $code = preg_replace('#\s//.*$#u', '', $l);   // تعليق بآخر السطر
                if (preg_match($rx, $code)) $viol[] = [$nom, $p, $i + 1, trim(mb_substr($code, 0, 150)), $src];
            }
        }
    }
    return $viol;
}
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $v = reglesUniquesVerifier(realpath(__DIR__ . '/..'));
    $par = []; foreach ($v as $x) $par[$x[0]][] = $x;
    foreach (reglesUniquesRegistre() as $r) echo (isset($par[$r[0]]) ? '❌ ' : '✅ ') . $r[0] . ' — المصدر: ' . $r[3] . (isset($par[$r[0]]) ? ' — ' . count($par[$r[0]]) . ' خارج المصدر' : '') . "\n";
    foreach ($v as $x) echo "   {$x[1]}:{$x[2]}  [{$x[0]}]  {$x[3]}\n";
    echo "\n" . (count($v) ? '❌ ' . count($v) . ' قاعدة مكتوبة خارج مصدرها' : '✅ كل قاعدة بمصدرها الوحيد') . "\n";
    exit(count($v) ? 1 : 0);
}
