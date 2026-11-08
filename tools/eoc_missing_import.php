<?php // استيراد إكسل نواقص الصندوق بعد تعبئته: يقرأ الأوراق (xlsx بلا مكتبات) ويكتب بملفات الأساتذة والمدارس. وسيط 1 = مسار الملف، وسيط 2 = "apply" للكتابة (وإلا معاينة فقط)
$_SERVER['HTTP_HOST'] = 'localhost'; session_start(); $_SESSION['user_id'] = 1; $_SESSION['role'] = 'superadmin';
if (php_sapi_name() !== 'cli') die('CLI only'); chdir(dirname(__DIR__));
require 'config/database.php'; require 'includes/functions.php'; require 'includes/report_helpers.php'; require 'includes/eoc_portal.php';
$db = getDB(); eocEnsureColumns($db); $file = $argv[1] ?? ''; $apply = ($argv[2] ?? '') === 'apply';
if (!is_file($file)) die("file not found\n");
$z = new ZipArchive(); if ($z->open($file) !== true) die("cannot open xlsx\n");
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
$norm = fn($s) => eocNorm(trim((string)$s));
$nivMap = ['حضانة' => 'maternelle', 'حضانه' => 'maternelle', 'روضة' => 'maternelle', 'ابتدائي' => 'primaire', 'ابتدائى' => 'primaire', 'متوسط' => 'intermediaire', 'تكميلي' => 'intermediaire', 'ثانوي' => 'secondaire', 'ثانوى' => 'secondaire'];
$findId = function (array $opts, string $label): ?int { $w = eocNorm($label); if ($w === '') return null; foreach ($opts as $id => $l) if (eocNorm($l) === $w) return (int)$id; foreach ($opts as $id => $l) if (mb_stripos(eocNorm($l), $w) !== false) return (int)$id; return null; };
$dateNorm = function (string $s): ?string { $s = trim(arabicDigitsFr($s)); if ($s === '') return null; if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); if (is_numeric($s)) { $t = ((int)$s - 25569) * 86400; return $t > 0 ? gmdate('Y-m-d', $t) : null; } return null; };
$log = []; $upd = 0;
// ورقة المدارس
if (isset($sheets['المدارس'])) foreach (array_slice($readSheet($sheets['المدارس']), 1) as $r) {
    $sid = (int)($r[1] ?? 0); if (!$sid) continue;
    $no = preg_replace('/\D+/', '', arabicDigitsFr($r[3] ?? '')); $mob = preg_replace('/\D+/', '', arabicDigitsFr($r[4] ?? '')); $typ = array_search(trim((string)($r[5] ?? '')), eocSchoolTypes(), true) ?: null;
    $set = []; $a = []; if ($no !== '') { $set[] = 'caisse_number = ?'; $a[] = $no; } if ($mob !== '') { $set[] = 'mobile_phone = ?'; $a[] = $mob; } if ($typ) { $set[] = 'school_type_eoc = ?'; $a[] = $typ; }
    if ($set) { $log[] = "مدرسة $sid: " . implode('، ', $set) . ' ⇐ ' . implode(' / ', $a); if ($apply) { $a[] = $sid; $db->prepare("UPDATE schools SET " . implode(', ', $set) . " WHERE id = ?")->execute($a); $upd++; } }
}
// أوراق المدارس (أعمدة: 1 رقم الموظف، 3 اسم الأب، 5 الرقم المالي، 6 تاريخ الولادة، 7 المرحلة التي يعلّم فيها، 8 الفئة، 9 المرحلة عند الصندوق، 11 الشهادة عند الصندوق)
foreach ($sheets as $name => $path) {
    if (in_array($name, ['تعليمات', 'المدارس'], true)) continue;
    foreach (array_slice($readSheet($path), 2) as $r) {
        $eid = (int)($r[1] ?? 0); if (!$eid) continue;
        $cur = $db->query("SELECT id, first_name_ar, last_name_ar, father_name_ar, caisse_number, birth_date, niveau_scolaire, eoc_category_id, eoc_phase_id, eoc_certificate_id FROM employees WHERE id = $eid")->fetch(PDO::FETCH_ASSOC); if (!$cur) { $log[] = "موظف $eid غير موجود"; continue; }
        $set = []; $a = []; $what = [];
        $fa = trim((string)($r[3] ?? '')); if ($fa !== '' && $fa !== trim((string)$cur['father_name_ar'])) { $set[] = 'father_name_ar = ?'; $a[] = $fa; $what[] = "الأب: $fa"; }
        $no = preg_replace('/\D+/', '', arabicDigitsFr($r[5] ?? '')); if ($no !== '' && $no !== trim((string)$cur['caisse_number'])) { $set[] = 'caisse_number = ?'; $a[] = $no; $what[] = "الرقم المالي: $no"; }
        $bd = $dateNorm((string)($r[6] ?? '')); if ($bd && $bd !== substr((string)$cur['birth_date'], 0, 10)) { $set[] = 'birth_date = ?'; $a[] = $bd; $what[] = "الولادة: $bd"; }
        $nivTxt = trim((string)($r[7] ?? '')); if ($nivTxt !== '') { $codes = []; foreach (preg_split('/[،,\/\s]+/u', $nivTxt) as $w) { $w = $norm($w); if ($w === '') continue; $hit = null; foreach ($nivMap as $k => $v) if ($norm($k) === $w) $hit = $v; if ($hit) $codes[$hit] = true; else $log[] = "موظف $eid: مرحلة غير معروفة «$w»"; }
            $codes = implode(',', array_keys($codes)); if ($codes !== '' && $codes !== (string)$cur['niveau_scolaire']) { $set[] = 'niveau_scolaire = ?'; $a[] = $codes; $what[] = "المرحلة التي يعلّم فيها: $codes"; } }
        $cat = $findId(eocCatOptions(), (string)($r[8] ?? '')); if ($cat && $cat !== (int)$cur['eoc_category_id']) { $set[] = 'eoc_category_id = ?'; $a[] = $cat; $what[] = 'الفئة: ' . eocCatOptions()[$cat]; }
        $ph = $findId(eocPhaseOptions(), (string)($r[9] ?? '')); if ($ph && $ph !== (int)$cur['eoc_phase_id']) { $set[] = 'eoc_phase_id = ?'; $a[] = $ph; $what[] = 'المرحلة عند الصندوق: ' . eocPhaseOptions()[$ph]; }
        $ce = $findId(eocCertOptions(), (string)($r[11] ?? '')); if ($ce && $ce !== (int)$cur['eoc_certificate_id']) { $set[] = 'eoc_certificate_id = ?'; $a[] = $ce; $what[] = 'الشهادة عند الصندوق: ' . eocCertOptions()[$ce]; }
        if ($set) { $log[] = "[$name] " . eocNameAr($cur) . " ($eid): " . implode(' · ', $what); if ($apply) { $a[] = $eid; $db->prepare("UPDATE employees SET " . implode(', ', $set) . " WHERE id = ?")->execute($a); $upd++; } }
    }
}
echo implode("\n", $log), "\n", ($apply ? "✅ كُتب $upd سجلاً" : "معاينة فقط — " . count($log) . " تغييراً (أضف apply للكتابة)"), "\n";
