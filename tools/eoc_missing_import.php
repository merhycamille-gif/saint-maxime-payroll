<?php // استيراد إكسل نواقص الصندوق (ملف مدرسة واحدة أو عدّة ملفات/مجلّد): يقرأ xlsx بلا مكتبات ويكتب بملفات الأساتذة والمدرسة
// الاستعمال: php tools/eoc_missing_import.php <ملف.xlsx | مجلّد> [apply]   — بلا apply = معاينة فقط
if (php_sapi_name() !== 'cli') die('CLI only'); chdir(dirname(__DIR__));
$_SERVER['HTTP_HOST'] = 'localhost'; session_start(); $_SESSION['user_id'] = 1; $_SESSION['role'] = 'superadmin';
require 'config/database.php'; require 'includes/functions.php'; require 'includes/report_helpers.php'; require 'includes/eoc_portal.php';
$db = getDB(); eocEnsureColumns($db); $target = $argv[1] ?? ''; $apply = ($argv[2] ?? '') === 'apply';
$files = is_dir($target) ? glob(rtrim($target, '/\\') . '/*.xlsx') : (is_file($target) ? [$target] : []);
if (!$files) die("لا ملف\n");
$norm = fn($s) => eocNorm(trim((string)$s));
$nivMap = ['حضانة' => 'maternelle', 'حضانه' => 'maternelle', 'روضة' => 'maternelle', 'روضه' => 'maternelle', 'ابتدائي' => 'primaire', 'ابتدائى' => 'primaire', 'متوسط' => 'intermediaire', 'تكميلي' => 'intermediaire', 'تكميلى' => 'intermediaire', 'ثانوي' => 'secondaire', 'ثانوى' => 'secondaire'];
$dateNorm = function (string $s): ?string { $s = trim(arabicDigitsFr($s)); if ($s === '') return null; if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); if (is_numeric($s)) { $t = ((int)$s - 25569) * 86400; return $t > 0 ? gmdate('Y-m-d', $t) : null; } return null; };
$log = []; $upd = 0;
foreach ($files as $file) {
    $z = new ZipArchive(); if ($z->open($file) !== true) { $log[] = "تعذّر فتح " . basename($file); continue; }
    $ss = []; if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) { $d = new DOMDocument(); $d->loadXML($x); foreach ($d->getElementsByTagName('si') as $si) $ss[] = $si->textContent; }
    $wb = new DOMDocument(); $wb->loadXML($z->getFromName('xl/workbook.xml')); $rels = new DOMDocument(); $rels->loadXML($z->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = []; foreach ($rels->getElementsByTagName('Relationship') as $r) $relMap[$r->getAttribute('Id')] = $r->getAttribute('Target');
    $sheets = []; foreach ($wb->getElementsByTagName('sheet') as $s) { $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'); $sheets[$s->getAttribute('name')] = 'xl/' . ltrim($relMap[$rid], '/'); }
    $readSheet = function (string $path) use ($z, $ss): array {
        $d = new DOMDocument(); $d->loadXML($z->getFromName($path)); $rows = [];
        foreach ($d->getElementsByTagName('row') as $r) { $cells = []; foreach ($r->getElementsByTagName('c') as $c) { $ref = preg_replace('/\d+/', '', $c->getAttribute('r')); $col = 0; foreach (str_split($ref) as $ch) $col = $col * 26 + (ord($ch) - 64); $v = $c->getElementsByTagName('v')->item(0); $is = $c->getElementsByTagName('is')->item(0); $val = $v ? $v->textContent : ($is ? $is->textContent : '');
            if ($c->getAttribute('t') === 's') $val = $ss[(int)$val] ?? ''; $cells[$col] = trim((string)$val); } $rows[] = $cells; }
        return $rows;
    };
    // الأعمدة بأسماء رؤوسها (لا بترتيبها) — يتحمّل إعادة ترتيب أو حذف عمود
    $colOf = function (array $hdr, string $needle): ?int { foreach ($hdr as $i => $h) if (mb_stripos(eocNorm($h), eocNorm($needle)) !== false) return $i; return null; };
    $tag = basename($file);
    // ورقة المدرسة/المدارس
    foreach (['المدرسة', 'المدارس'] as $sn) if (isset($sheets[$sn])) {
        $rows = $readSheet($sheets[$sn]); $hdr = $rows[0] ?? []; $iId = $colOf($hdr, 'رقم') ?? 1; $iNo = $colOf($hdr, 'رقم المدرسة عند الصندوق'); $iMob = $colOf($hdr, 'الخلوي'); $iTyp = $colOf($hdr, 'نوع المدرسة');
        foreach (array_slice($rows, 1) as $r) { $sid = (int)($r[$iId] ?? 0); if (!$sid) continue; $cur = $db->query("SELECT caisse_number, mobile_phone, school_type_eoc, name_ar FROM schools WHERE id = $sid")->fetch(PDO::FETCH_ASSOC); if (!$cur) continue;
            $set = []; $a = []; $what = [];
            if ($iNo !== null) { $no = preg_replace('/\D+/', '', arabicDigitsFr($r[$iNo] ?? '')); if ($no !== '' && $no !== trim((string)$cur['caisse_number'])) { $set[] = 'caisse_number = ?'; $a[] = $no; $what[] = "رقم الصندوق: $no"; } }
            if ($iMob !== null) { $mob = preg_replace('/\D+/', '', arabicDigitsFr($r[$iMob] ?? '')); if ($mob !== '' && $mob !== trim((string)$cur['mobile_phone'])) { $set[] = 'mobile_phone = ?'; $a[] = $mob; $what[] = "الخلوي: $mob"; } }
            if ($iTyp !== null) { $typ = array_search($norm($r[$iTyp] ?? ''), array_map($norm, eocSchoolTypes()), true); if ($typ && $typ !== (string)$cur['school_type_eoc']) { $set[] = 'school_type_eoc = ?'; $a[] = $typ; $what[] = 'النوع: ' . eocSchoolTypes()[$typ]; } }
            if ($set) { $log[] = "[$tag] " . $cur['name_ar'] . ": " . implode(' · ', $what); if ($apply) { $a[] = $sid; $db->prepare("UPDATE schools SET " . implode(', ', $set) . " WHERE id = ?")->execute($a); $upd++; } } }
    }
    // أوراق الأساتذة (كل ورقة ليست تعليمات/مدرسة)
    foreach ($sheets as $name => $path) {
        if (in_array($name, ['تعليمات', 'المدرسة', 'المدارس'], true)) continue;
        $rows = $readSheet($path); $hi = null; foreach ($rows as $k => $r) if (array_filter($r, fn($v) => mb_stripos($v, 'رقم الموظف') !== false)) { $hi = $k; break; }
        if ($hi === null) continue; $hdr = $rows[$hi];
        $iId = $colOf($hdr, 'رقم الموظف'); $iFa = $colOf($hdr, 'اسم الأب'); $iNo = $colOf($hdr, 'الرقم المالي'); $iBd = $colOf($hdr, 'تاريخ الولادة'); $iNiv = $colOf($hdr, 'المرحلة التي يعل'); $iCat = $colOf($hdr, 'الفئة عند الصندوق'); $iPh = $colOf($hdr, 'المرحلة عند الصندوق'); $iCe = $colOf($hdr, 'الشهادة عند الصندوق');
        $findId = function (array $opts, string $label): ?int { $w = eocNorm($label); if ($w === '') return null; foreach ($opts as $id => $l) if (eocNorm($l) === $w) return (int)$id; return null; };
        foreach (array_slice($rows, $hi + 1) as $r) {
            $eid = (int)($r[$iId] ?? 0); if (!$eid) continue;
            $cur = $db->query("SELECT id, first_name_ar, last_name_ar, father_name_ar, caisse_number, birth_date, niveau_scolaire, eoc_category_id, eoc_phase_id, eoc_certificate_id FROM employees WHERE id = $eid")->fetch(PDO::FETCH_ASSOC); if (!$cur) { $log[] = "[$tag] موظف $eid غير موجود"; continue; }
            $set = []; $a = []; $what = [];
            if ($iFa !== null) { $fa = trim((string)($r[$iFa] ?? '')); if ($fa !== '' && $fa !== trim((string)$cur['father_name_ar'])) { $set[] = 'father_name_ar = ?'; $a[] = $fa; $what[] = "الأب: $fa"; } }
            if ($iNo !== null) { $no = preg_replace('/\D+/', '', arabicDigitsFr($r[$iNo] ?? '')); if ($no !== '' && $no !== trim((string)$cur['caisse_number'])) { $set[] = 'caisse_number = ?'; $a[] = $no; $what[] = "الرقم المالي: $no"; } }
            if ($iBd !== null) { $bd = $dateNorm((string)($r[$iBd] ?? '')); if ($bd && $bd !== substr((string)$cur['birth_date'], 0, 10)) { $set[] = 'birth_date = ?'; $a[] = $bd; $what[] = "الولادة: $bd"; } }
            if ($iNiv !== null) { $t = trim((string)($r[$iNiv] ?? '')); if ($t !== '') { [$codesArr, $phaseId] = eocParseStage($t);
                if (!$codesArr && !$phaseId) $log[] = "[$tag] " . eocNameAr($cur) . ": مرحلة غير معروفة «$t» (المقبول: حضانة/ابتدائي/متوسط/ثانوي أو رموز الصندوق إ/م/ث/ح)";
                $codes = implode(',', $codesArr); if ($codes !== '' && $codes !== (string)$cur['niveau_scolaire']) { $set[] = 'niveau_scolaire = ?'; $a[] = $codes; $what[] = "المرحلة التي يعلّم فيها: " . $t; }
                if ($phaseId && $phaseId !== (int)$cur['eoc_phase_id']) { $set[] = 'eoc_phase_id = ?'; $a[] = $phaseId; $what[] = 'المرحلة عند الصندوق: ' . eocPhaseOptions()[$phaseId]; } } }
            if ($iCat !== null) { $v = $findId(eocCatOptions(), (string)($r[$iCat] ?? '')); if ($v && $v !== (int)$cur['eoc_category_id']) { $set[] = 'eoc_category_id = ?'; $a[] = $v; $what[] = 'الفئة: ' . eocCatOptions()[$v]; } }
            if ($iPh !== null) { $v = $findId(eocPhaseOptions(), (string)($r[$iPh] ?? '')); if ($v && $v !== (int)$cur['eoc_phase_id']) { $set[] = 'eoc_phase_id = ?'; $a[] = $v; $what[] = 'المرحلة عند الصندوق: ' . eocPhaseOptions()[$v]; } }
            if ($iCe !== null) { $v = $findId(eocCertOptions(), (string)($r[$iCe] ?? '')); if ($v && $v !== (int)$cur['eoc_certificate_id']) { $set[] = 'eoc_certificate_id = ?'; $a[] = $v; $what[] = 'الشهادة عند الصندوق: ' . eocCertOptions()[$v]; } }
            if ($set) { $log[] = "[$tag] " . eocNameAr($cur) . " ($eid): " . implode(' · ', $what); if ($apply) { $a[] = $eid; $db->prepare("UPDATE employees SET " . implode(', ', $set) . " WHERE id = ?")->execute($a); $upd++; } }
        }
    }
    $z->close();
}
echo implode("\n", $log), "\n", ($apply ? "✅ كُتب $upd سجلاً" : "معاينة فقط — " . count($log) . " تغييراً (أضف apply للكتابة)"), "\n";
