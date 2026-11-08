<?php // إكسل نواقص صندوق التعويضات: ورقة لكل مدرسة (أساتذة 2026-2027 الذين ينقصهم شيء) + ورقة المدارس — يعبّيه المستخدم ويُستورَد
$_SERVER['HTTP_HOST'] = 'localhost'; session_start(); $_SESSION['user_id'] = 1; $_SESSION['role'] = 'superadmin';
if (php_sapi_name() !== 'cli') die('CLI only'); chdir(dirname(__DIR__));
require 'config/database.php'; require 'includes/functions.php'; require 'includes/report_helpers.php'; require 'includes/mehe_budget.php'; require 'includes/eoc_portal.php';
$db = getDB(); $sy = '2026-2027'; $out = $argv[1] ?? 'C:/Users/user/Desktop/نواقص صندوق التعويضات 2026-2027.xlsx';
$X = new MeheXlsx(); $c = [MeheXlsx::class, 'c'];
$niv = ['maternelle' => 'حضانة', 'primaire' => 'ابتدائي', 'intermediaire' => 'متوسط', 'secondaire' => 'ثانوي'];
$nivTxt = fn($v) => implode('، ', array_map(fn($x) => $niv[$x] ?? $x, array_filter(array_map('trim', explode(',', (string)$v)))));
// ورقة التعليمات
$si = $X->sheet('تعليمات', [120]);
foreach ([
    ['نواقص صندوق التعويضات — السنة الدراسية ' . $sy, 1],
    ['ورقة لكل مدرسة: فيها فقط الأساتذة الذين تنقصهم خانة يطلبها الصندوق. الخانات الفاضية هي الناقصة — عبّها.', 12],
    ['لا تغيّر العمود الأول «رقم الموظف» ولا ترتيب الأعمدة؛ منهما يُستورَد الملف إلى البرنامج.', 12],
    ['المرحلة التي يعلّم فيها: اكتب واحدة أو أكثر من: حضانة · ابتدائي · متوسط · ثانوي (افصل بفاصلة). منها يستنتج البرنامج الفئة والمرحلة عند الصندوق.', 12],
    ['الفئة عند الصندوق (اختياري، إذا أردت غير المستنتَج): ' . implode(' · ', eocCatOptions()), 12],
    ['المرحلة عند الصندوق (اختياري): ' . implode(' · ', eocPhaseOptions()), 12],
    ['الشهادة عند الصندوق (اختياري، إذا كانت غير شهادة البرنامج): ' . implode(' · ', eocCertOptions()), 12],
    ['الرقم المالي: رقم الأستاذ عند الصندوق (للملاك فقط). تاريخ الولادة: يوم/شهر/سنة أو سنة-شهر-يوم.', 12],
    ['ورقة «المدارس»: رقم المدرسة عند الصندوق، الهاتف الخلوي (03/70/71/76/78/79/81)، نوع المدرسة (مجاني / غير مجاني / مهني).', 12],
] as [$t, $s]) $X->row($si, [$c($t, $s)]);
// ورقة المدارس
$si = $X->sheet('المدارس', [8, 44, 20, 20, 16]);
$X->row($si, array_map(fn($l) => $c($l, 2), ['رقم', 'المدرسة', 'رقم المدرسة عند الصندوق', 'الهاتف الخلوي للصندوق', 'نوع المدرسة']), 30);
$schools = $db->query("SELECT * FROM schools WHERE is_deleted = 0 AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$withTeachers = [];
foreach ($schools as $s) { $rows = eocGeneralRows($db, [(int)$s['id']], $sy, eocDefaults('general')); if (!$rows) continue; $withTeachers[(int)$s['id']] = $rows;
    $X->row($si, [$c((int)$s['id'], 13), $c($s['name_ar'], 3), $c((string)($s['caisse_number'] ?? ''), (string)($s['caisse_number'] ?? '') === '' ? 10 : 3), $c((string)($s['mobile_phone'] ?? ''), (string)($s['mobile_phone'] ?? '') === '' ? 10 : 3), $c(eocSchoolTypes()[(string)($s['school_type_eoc'] ?? '')] ?? 'غير مجاني', 3)]); }
// ورقة لكل مدرسة
$heads = ['رقم الموظف (لا تغيّره)', 'الاسم والشهرة', 'اسم الأب', 'ملاك/متعاقد', 'الرقم المالي عند الصندوق', 'تاريخ الولادة', 'المرحلة التي يعلّم فيها', 'الفئة عند الصندوق (اختياري)', 'المرحلة عند الصندوق (اختياري)', 'الشهادة بالبرنامج', 'الشهادة عند الصندوق (اختياري)', 'الناقص'];
$tot = 0;
foreach ($withTeachers as $sid => $rows) {
    $s = current(array_filter($schools, fn($x) => (int)$x['id'] === $sid));
    $miss = array_values(array_filter($rows, fn($r) => $r['missing'] && $r['src'] === 'auto'));
    if (!$miss) continue;
    $short = trim(preg_replace(['/^(مدرسة|ثانوية)\s+/u', '/\s*للراهبات المخلصيات\s*/u', '/\s+-\s+/u'], ['', ' ', ' - '], $s['name_ar']));
    $short = mb_substr($short, 0, 24, 'UTF-8'); static $used = []; if (isset($used[$short])) $short .= ' ' . $sid; $used[$short] = true;
    $si = $X->sheet($short, [10, 26, 14, 11, 16, 14, 24, 18, 18, 18, 20, 28]);
    $X->row($si, [$c($s['name_ar'] . ' — نواقص الصندوق ' . $sy . ' (' . count($miss) . ' أستاذاً)', 1)], 26); $X->merge($si, 'A1:L1');
    $X->row($si, array_map(fn($l) => $c($l, 2), $heads), 34);
    $lab = ['teacher_no' => 'الرقم المالي', 'father_name' => 'اسم الأب', 'birth_date' => 'تاريخ الولادة', 'category_id' => 'الفئة', 'phase_id' => 'المرحلة', 'certificate_id' => 'الشهادة', 'start_date' => 'تاريخ الدخول', 'teaching_hours' => 'الساعات', 'salary' => 'الراتب', 'add_salary' => 'الإضافي'];
    foreach ($miss as $r) {
        $e = $db->query("SELECT niveau_scolaire, diploma, eoc_category_id, eoc_phase_id, eoc_certificate_id FROM employees WHERE id = " . (int)$r['emp_id'])->fetch(PDO::FETCH_ASSOC);
        $m = fn(string $f, $val) => $c($val, in_array($f, $r['missing'], true) ? 10 : 3);
        $needNiv = in_array('category_id', $r['missing'], true) || in_array('phase_id', $r['missing'], true);
        $X->row($si, [$c((int)$r['emp_id'], 13), $c($r['teacher_name'], 3), $m('father_name', $r['father_name']), $c($r['employment_type'] === 'staff' ? 'ملاك' : 'متعاقد', 13), $m('teacher_no', (string)$r['teacher_no']), $m('birth_date', $r['birth_date']),
            $c($nivTxt($e['niveau_scolaire']), $needNiv && trim((string)$e['niveau_scolaire']) === '' ? 10 : 3), $c(eocCatOptions()[(int)$e['eoc_category_id']] ?? '', 3), $c(eocPhaseOptions()[(int)$e['eoc_phase_id']] ?? '', 3),
            $c(diplomaLabel($e['diploma'], 'ar'), 3), $c(eocCertOptions()[(int)$e['eoc_certificate_id']] ?? '', 3), $c(implode('، ', array_map(fn($f) => $lab[$f] ?? $f, $r['missing'])), 12)]);
        $tot++;
    }
}
file_put_contents($out, $X->bytes());
echo "written: $out ($tot أستاذاً)\n";
