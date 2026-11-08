<?php // إكسل نواقص صندوق التعويضات — ملف مستقل لكل مدرسة (يُرسَل إليها لتعبّيه) ثمّ يُستورَد بـeoc_missing_import.php
// وسيط 1 = مجلّد الإخراج (افتراضياً سطح المكتب/نواقص صندوق التعويضات <السنة>) · وسيط 2 = السنة الدراسية (افتراضياً الحالية)
if (php_sapi_name() !== 'cli') die('CLI only'); chdir(dirname(__DIR__));
$_SERVER['HTTP_HOST'] = 'localhost'; session_start(); $_SESSION['user_id'] = 1; $_SESSION['role'] = 'superadmin';
require 'config/database.php'; require 'includes/functions.php'; require 'includes/report_helpers.php'; require 'includes/mehe_budget.php'; require 'includes/eoc_portal.php';
$db = getDB(); $sy = $argv[2] ?? currentSchoolYear();
$dir = $argv[1] ?? ('C:/Users/user/Desktop/نواقص صندوق التعويضات ' . $sy); if (!is_dir($dir)) mkdir($dir, 0777, true);
$niv = ['maternelle' => 'حضانة', 'primaire' => 'ابتدائي', 'intermediaire' => 'متوسط', 'secondaire' => 'ثانوي'];
$nivTxt = fn($v) => implode('، ', array_map(fn($x) => $niv[$x] ?? $x, array_filter(array_map('trim', explode(',', (string)$v)))));
$lab = ['teacher_no' => 'الرقم المالي', 'father_name' => 'اسم الأب', 'birth_date' => 'تاريخ الولادة', 'category_id' => 'المرحلة التي يعلّم فيها', 'phase_id' => 'المرحلة التي يعلّم فيها', 'certificate_id' => 'الشهادة', 'start_date' => 'تاريخ الدخول', 'teaching_hours' => 'الساعات', 'salary' => 'الراتب', 'add_salary' => 'الإضافي'];
$files = [];
foreach ($db->query("SELECT * FROM schools WHERE is_deleted = 0 AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $rows = eocGeneralRows($db, [(int)$s['id']], $sy, eocDefaults('general')); if (!$rows) continue;
    $miss = array_values(array_filter($rows, fn($r) => $r['missing'] && $r['src'] === 'auto'));
    $schoolMissing = trim((string)($s['caisse_number'] ?? '')) === '' || trim((string)($s['mobile_phone'] ?? '')) === '';
    if (!$miss && !$schoolMissing) continue;
    $X = new MeheXlsx(); $c = [MeheXlsx::class, 'c'];
    // تعليمات
    $si = $X->sheet('تعليمات', [120]);
    foreach ([
        [$s['name_ar'] . ' — نواقص صندوق التعويضات — السنة الدراسية ' . $sy, 1],
        ['ورقة «المدرسة»: عبّوا رقم المدرسة عند الصندوق، والهاتف الخلوي الذي تريدونه عند الصندوق (03/70/71/76/78/79/81)، ونوع المدرسة (مجاني / غير مجاني / مهني).', 12],
        ['ورقة «الأساتذة»: فيها فقط الأساتذة الذين تنقصهم خانة يطلبها الصندوق. الخانات الفاضية الحمراء هي الناقصة — عبّوها. العمود الأخير «الناقص» يقول ما المطلوب.', 12],
        ['المرحلة التي يعلّم فيها: اختاروا من القائمة المنسدلة (رموز الصندوق: ح = حضانة، إ = ابتدائي، م = متوسط، ث = ثانوي، ومجموعاتها ح - إ، إ - م، م - ث، كل المراحل) أو اكتبوا بأنفسكم: حضانة / ابتدائي / متوسط / ثانوي، واحدة أو أكثر بفاصلة.', 12],
        ['الرقم المالي: رقم الأستاذ عند صندوق التعويضات (لأساتذة الملاك فقط). تاريخ الولادة: يوم/شهر/سنة.', 12],
        ['لا تغيّروا العمود الأول «رقم الموظف» ولا ترتيب الأعمدة ولا أسماء الأوراق؛ منها يُستورَد الملف إلى البرنامج.', 12],
    ] as [$t, $st]) $X->row($si, [$c($t, $st)]);
    // المدرسة
    $si = $X->sheet('المدرسة', [8, 44, 24, 26, 18]);
    $X->row($si, array_map(fn($l) => $c($l, 2), ['رقم', 'المدرسة', 'رقم المدرسة عند الصندوق', 'الهاتف الخلوي للصندوق', 'نوع المدرسة']), 30);
    $X->row($si, [$c((int)$s['id'], 13), $c($s['name_ar'], 3), $c((string)($s['caisse_number'] ?? ''), (string)($s['caisse_number'] ?? '') === '' ? 15 : 3), $c((string)($s['mobile_phone'] ?? ''), (string)($s['mobile_phone'] ?? '') === '' ? 15 : 3), $c(eocSchoolTypes()[(string)($s['school_type_eoc'] ?? '')] ?? 'غير مجاني', 3)]);
    // الأساتذة
    $si = $X->sheet('الأساتذة', [10, 28, 16, 12, 18, 16, 30, 30]);
    $X->row($si, [$c($s['name_ar'] . ' — نواقص صندوق التعويضات ' . $sy . ' (' . count($miss) . ' أستاذاً)', 1)], 26); $X->merge($si, 'A1:H1');
    $X->row($si, array_map(fn($l) => $c($l, 2), ['رقم الموظف (لا تغيّروه)', 'الاسم والشهرة', 'اسم الأب', 'ملاك/متعاقد', 'الرقم المالي عند الصندوق', 'تاريخ الولادة', 'المرحلة التي يعلّم فيها', 'الناقص']), 34);
    foreach ($miss as $r) {
        $e = $db->query("SELECT niveau_scolaire FROM employees WHERE id = " . (int)$r['emp_id'])->fetch(PDO::FETCH_ASSOC);
        $m = fn(string $f, $val) => $c($val, in_array($f, $r['missing'], true) ? 15 : 3);
        $needNiv = in_array('category_id', $r['missing'], true) || in_array('phase_id', $r['missing'], true);
        $what = array_values(array_unique(array_map(fn($f) => $lab[$f] ?? $f, $r['missing'])));
        $X->row($si, [$c((int)$r['emp_id'], 13), $c($r['teacher_name'], 3), $m('father_name', $r['father_name']), $c($r['employment_type'] === 'staff' ? 'ملاك' : 'متعاقد', 13), $m('teacher_no', (string)$r['teacher_no']), $m('birth_date', $r['birth_date']),
            $c($nivTxt($e['niveau_scolaire']), $needNiv && trim((string)$e['niveau_scolaire']) === '' ? 15 : 3), $c(implode('، ', $what), 12)]);
    }
    if ($miss) $X->listValidation($si, 'G3:G' . (2 + count($miss)), eocStageChoices()); // 🔽 قائمة منسدلة بمراحل الصندوق + الكتابة الحرّة
    if (!$miss) $X->row($si, [$c('لا نواقص عند الأساتذة — المطلوب فقط ورقة «المدرسة».', 12)]);
    $safe = preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $s['name_ar']);
    $path = rtrim($dir, '/\\') . '/نواقص صندوق التعويضات ' . $sy . ' — ' . $safe . '.xlsx';
    file_put_contents($path, $X->bytes()); $files[] = [$path, count($miss)];
}
foreach ($files as [$p, $n]) echo basename($p), " ($n أستاذاً)\n";
echo "المجلّد: $dir\n";
