<?php
/**
 * 📚 إفادات جماعية (2026-10-27 «إذا بدّي مجموعة إفادات لمجموعة أساتذة، أبعتها إيميل أو واتساب أو أطبعها دغري بكبسة وحدة»):
 *   نوع إفادة واحد × مجموعة أساتذة ⇒ (١) طباعة: PDF واحد كل أستاذ على ورقته · (٢) إيميل: لكل أستاذ على إيميله، أو كلّها بملف واحد على
 *   عنوان واحد · (٣) واتساب: كبسة لكل أستاذ على رقمه، أو الملف الواحد على رقم واحد.
 *   لا تلمس أيّ صفحة قائمة: كل إفادة تُبنى بالمسار الموجود نفسه (صفحة الإفادة أو النموذج الرسمي) وتُحوَّل PDF بالمحرّك الموحّد (MsaPdf /
 *   MsaChromePdf)، ثمّ تُدمَج عبر خدمة المدرسة (Gotenberg merge). الملفات مؤقّتة بـtmp/bulk (تُنظَّف بعد ساعتين).
 *   API (نفس الملف): act=gen (أستاذ واحد ⇒ PDF [+ إيميل]) · act=merge (دمج) · act=file (تسليم) · act=mailfile (الملف الواحد لعنوان).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attestation_types.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();

$db = getDB();
$ATT_TYPES = attestationTypes() + attestationHireTypes();
$ATT_GROUPS = attestationGroups();
$DOC_LANGS = ['ar' => 'العربية', 'fr' => 'Français', 'en' => 'English'];
$OFFICIAL = ['cnss_hire', 'cnss_hire_new', 'cnss_hire_reg', 'cnss_leave', 'cnss_travail', 'mof_r3'];
$LATIN = ['cnss_hire' => 'Declaration_embauche_CNSS', 'cnss_hire_new' => 'Declaration_embauche_CNSS', 'cnss_hire_reg' => 'Declaration_embauche_CNSS', 'cnss_leave' => 'Declaration_cessation_CNSS',
          'cnss_travail' => 'Attestation_travail_CNSS', 'mof_r3' => 'Formulaire_R3', 'salaire' => 'Attestation_salaire', 'tadris' => 'Attestation_enseignement', 'cnss' => 'Attestation_CNSS',
          'embassy' => 'Attestation_ambassade', 'riaaya' => 'Attestation_organisme', 'anhaa_khedme' => 'Fin_de_service', 'anhaa_mail' => 'Fin_de_service_courrier', 'talab_istiqala' => 'Demission',
          'afade_madrasiya' => 'Attestation_scolaire', 'isqat_haq' => 'Renonciation', 'baraa_zimma' => 'Quittance', 'iqrar' => 'Declaration', 'aqd_taalim' => 'Contrat', 'notice_school' => 'Avertissement', 'notice_mail' => 'Avertissement_courrier'];

$in = array_merge($_GET, $_POST);   // (CLI/الفحص يعبّي $_GET وحده)
$act = (string)($in['act'] ?? '');
$bulkDir = realpath(__DIR__ . '/..') . '/tmp/bulk';
if (!is_dir($bulkDir)) @mkdir($bulkDir, 0777, true);
foreach ((array)glob($bulkDir . '/*.pdf') as $old) if (@filemtime($old) < time() - 7200) @unlink($old);

function bulkJson(array $a): void { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function bulkLatin(string $s): string {   // Thérèse ⇒ Therese (خريطة الحروف المشكولة أوّلاً — iconv على ويندوز يضيف فواصل)
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U', 'Ç' => 'C']);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t === false || $t === '') $t = $s;
    return trim(preg_replace('/[^a-z0-9]+/i', '_', $t), '_');
}
function bulkToken(): string { return bin2hex(random_bytes(10)); }
function bulkOwn(string $tok): bool { return preg_match('/^[a-f0-9]{20}$/', $tok) === 1 && !empty($_SESSION['bulk_files'][$tok]); }
/** 📧 (p1 «ما بدّي يطلع بالإيميل هالجملة والعنوان بالعربي») عنوان الإيميل ونصّه بالفرنسية الرسمية، بلا أقواس «(officielle — automatique)» ولا شرح */
function bulkFrLabel(string $typeKey): string { global $ATT_TYPES; $fr = (string)($ATT_TYPES[$typeKey]['fr'] ?? $typeKey); return trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $fr)); }
function bulkMailText(string $label, string $schoolFr, int $n = 1): array {
    $subject = $label . ($n > 1 ? ' (' . $n . ')' : '') . ($schoolFr !== '' ? ' — ' . $schoolFr : '');
    $body = "Bonjour,\n\nVeuillez trouver ci-joint " . ($n > 1 ? "le fichier PDF contenant $n documents : " : 'le document : ') . $label . ".\n\nCordialement,\n" . $schoolFr;
    return [$subject, $body];
}
/** المدارس المسموح بها لهذا الحساب (المدير: كلّها الفاعلة؛ حساب مدرسة: مدارسه) ⇒ [id => صفّ المدرسة] */
function bulkSchools(): array {
    static $c = null; if ($c !== null) return $c;
    $db = getDB(); $c = [];
    $ids = isSuperAdmin() ? array_map(fn($s) => (int)$s['id'], allSchools(false)) : viewerAllowedSchoolIds();
    if ($ids) foreach ($db->query("SELECT * FROM schools WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY id") as $s) $c[(int)$s['id']] = $s;
    return $c;
}

/** جلب صفحة داخلية بجلسة المستخدم نفسها (كما send_attestation): تُغلق الجلسة أثناء الطلب ثمّ تُفتح */
function bulkFetch(string $rel): string {
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $url = $base . BASE_URL . ltrim($rel, '/');
    $cn = session_name(); $cv = session_id();
    session_write_close();
    $out = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cn . '=' . $cv, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        $out = (string)curl_exec($ch); curl_close($ch);
    }
    @session_start();
    return $out;
}

/** إفادة أستاذ واحد ⇒ [pdf, fileName, labelAr] أو استثناء بسبب واضح */
function bulkBuild(array $emp, string $type, string $lang, string $date): array {
    global $ATT_TYPES, $OFFICIAL, $LATIN;
    $ts = strtotime($date) ?: time();
    $who = bulkLatin(($emp['first_name_fr'] ?? '') . '_' . ($emp['last_name_fr'] ?? ''));
    $used = $type;
    if (in_array($type, $OFFICIAL, true)) {
        if ($type === 'cnss_hire') $used = hasNssfNumber($emp['nssf_number'] ?? '') ? 'cnss_hire_reg' : 'cnss_hire_new';
        $form = $used === 'cnss_travail' ? 'cnss_work_attestation' : $used;
        $q = 'pages/official_export.php?form=' . rawurlencode($form) . '&emp=' . (int)$emp['id'] . '&d=' . (int)date('j', $ts) . '&mo=' . (int)date('n', $ts) . '&yr=' . date('Y', $ts) . '&format=pdf&raw_pdf=1';
        if ($used === 'cnss_leave') $q .= '&reason=1';
        $r = bulkFetch($q);
        if (strpos($r, '%PDF') === 0) $pdf = $r;
        elseif (stripos($r, 'class="pbg"') !== false) {
            require_once __DIR__ . '/../includes/pdf_chromium.php';
            if (!MsaChromePdf::available()) throw new RuntimeException('محرّك PDF غير متاح لهذا النموذج الرسمي');
            $pdf = MsaChromePdf::overlayPageToPdf($r);
        } else throw new RuntimeException('تعذّر توليد النموذج الرسمي (' . ($ATT_TYPES[$used]['ar'] ?? $used) . ')');
    } else {
        $r = bulkFetch('pages/attestations.php?employee_id=' . (int)$emp['id'] . '&type=' . rawurlencode($type) . '&lang_doc=' . rawurlencode($lang) . '&date=' . rawurlencode($date));
        libxml_use_internal_errors(true);
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $r);
        $xp = new DOMXPath($dom);
        $area = $xp->query('//*[@id="ppExportArea"]')->item(0);
        if (!$area) throw new RuntimeException('صفحة الإفادة ما رجّعت مستنداً (' . ($ATT_TYPES[$type]['ar'] ?? $type) . ')');
        $cls = function ($c) { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")'; };
        $rm = []; foreach ($xp->query('.//*[' . $cls('no-print') . ' or ' . $cls('no-export') . ' or ' . $cls('export-toolbar') . ' or ' . $cls('btn') . ' or self::script or self::button or self::select or self::input or self::textarea]', $area) as $n) $rm[] = $n;
        foreach ($rm as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
        $inner = ''; foreach ($area->childNodes as $c) $inner .= $dom->saveHTML($c);
        $style = (string)$area->getAttribute('style'); $lhBg = ''; $lhPad = '';
        if (preg_match('/background\s*:\s*url\((["\']?)([^"\')]+)\1\)/i', $style, $m)) {
            $u = html_entity_decode($m[2]);
            if (!preg_match('#^https?://#i', $u)) { $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); $u = $base . (strpos($u, '/') === 0 ? '' : BASE_URL) . $u; }
            $lhBg = $u;
            if (preg_match('/(^|;)\s*padding\s*:\s*([^;]+)/i', $style, $pm)) {
                $v = array_values(array_filter(array_map(fn($x) => (float)$x, preg_split('/\s+/', trim($pm[2])))));
                if ($v) { $t = $v[0]; $rr = $v[1] ?? $t; $b = $v[2] ?? $t; $l = $v[3] ?? $rr; $lhPad = "$t,$rr,$b,$l"; }
            }
        }
        $dir = strtolower((string)$area->getAttribute('dir')) === 'ltr' ? 'ltr' : ($lang === 'ar' ? 'rtl' : 'ltr');
        if ($area->getAttribute('dir') === '' && $lang !== 'ar' && preg_match('/[\x{0600}-\x{06FF}]/u', strip_tags($inner))) $dir = 'rtl';
        require_once __DIR__ . '/../includes/pdf_engine.php';
        $sch = currentSchool(); $schoolName = $sch ? (string)($sch['name_ar'] ?: ($sch['name_fr'] ?? '')) : '';
        $opts = ['title' => ($ATT_TYPES[$type][$lang] ?? $type) . ' — ' . trim(($emp['first_name_fr'] ?? '') . ' ' . ($emp['last_name_fr'] ?? '')), 'dir' => $dir, 'school' => $schoolName, 'fit' => true, 'lh_bg' => $lhBg, 'lh_pad' => $lhPad];
        // ⚡ («خلّي يكون أسرع») كاش المحرّك ببصمة المحتوى: نفس الإفادة بنفس اليوم ⇒ الملف الجاهز فوراً عند الإعادة
        $ck = MsaPdf::cacheKey($inner, $opts); $pdf = MsaPdf::cacheGet($ck);
        if ($pdf === null) { $pdf = MsaPdf::fromHtml($inner, $opts); MsaPdf::cachePut($ck, $pdf); }
    }
    if (strpos($pdf, '%PDF') !== 0 || strlen($pdf) < 400) throw new RuntimeException('ملف PDF فارغ');
    $name = ($LATIN[$used] ?? ('Document_' . bulkLatin($used))) . ($who !== '' ? '_' . $who : '') . '_' . date('Y-m-d', $ts) . '.pdf';
    return [$pdf, $name, (string)($ATT_TYPES[$used]['ar'] ?? $used)];
}

/** دمج ملفات PDF (بالترتيب) عبر خدمة المدرسة؛ وإلا mPDF باستيراد الصفحات */
function bulkMerge(array $paths): string {
    require_once __DIR__ . '/../includes/pdf_chromium.php';
    $cfg = MsaChromePdf::config();
    if ($cfg) {
        $post = []; $i = 0;
        foreach ($paths as $p) { $post['files' . ($i ? $i : '')] = new CURLFile($p, 'application/pdf', sprintf('%03d', $i + 1) . '_doc.pdf'); $i++; }   // Gotenberg يدمج بترتيب الأسماء
        $ch = curl_init(rtrim($cfg['url'], '/') . '/forms/pdfengines/merge');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => (int)($cfg['timeout'] ?? 120), CURLOPT_CONNECTTIMEOUT => 8]);
        if (!empty($cfg['user'])) curl_setopt($ch, CURLOPT_USERPWD, $cfg['user'] . ':' . ($cfg['pass'] ?? ''));
        $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && is_string($r) && strpos($r, '%PDF') === 0) return $r;
    }
    // احتياط: mPDF يستورد الصفحات (ملفات PDF 1.4 كما ينتجها المحرّكان)
    require_once __DIR__ . '/../includes/pdf_engine.php';
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => realpath(__DIR__ . '/..') . '/tmp/mpdf']);
    $first = true;
    foreach ($paths as $p) {
        $n = $mpdf->setSourceFile($p);
        for ($k = 1; $k <= $n; $k++) {
            $tpl = $mpdf->importPage($k); $sz = $mpdf->getTemplateSize($tpl);
            $mpdf->AddPageByArray(['orientation' => ($sz['width'] > $sz['height']) ? 'L' : 'P', 'sheet-size' => [$sz['width'], $sz['height']], 'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0]);
            $mpdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height'], true);
        }
    }
    return $mpdf->Output('', 'S');
}

/* ======================= API ======================= */
if ($act !== '') {
    $type = (string)($in['type'] ?? ''); $lang = (string)($in['lang_doc'] ?? 'ar'); if (!isset($DOC_LANGS[$lang])) $lang = 'ar';
    $date = (string)($in['date'] ?? date('Y-m-d')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = date('Y-m-d');
    try {
        if ($act === 'gen') {
            if (!isset($ATT_TYPES[$type])) bulkJson(['ok' => false, 'msg' => 'نوع إفادة غير معروف']);
            $eid = (int)($in['emp'] ?? 0);
            $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0"); $st->execute([$eid]); $emp = $st->fetch(PDO::FETCH_ASSOC);
            $schools = bulkSchools();
            if (!$emp || !isset($schools[(int)$emp['school_id']])) bulkJson(['ok' => false, 'msg' => 'الأستاذ غير موجود بمدارسك']);
            // 🏫 (عدّة مدارس) كل إفادة بترويسة مدرسة صاحبها: المدرسة الفعّالة بالجلسة = مدرسته أثناء التوليد ثمّ تُعاد كما كانت
            $prevActive = $_SESSION['active_schools'] ?? null; $prevRep = $_SESSION['report_schools'] ?? null;
            $_SESSION['active_schools'] = [(int)$emp['school_id']]; unset($_SESSION['report_schools']);
            try { [$pdf, $name, $label] = bulkBuild($emp, $type, $lang, $date); }
            finally { if ($prevActive === null) unset($_SESSION['active_schools']); else $_SESSION['active_schools'] = $prevActive; if ($prevRep !== null) $_SESSION['report_schools'] = $prevRep; }
            $sch = $schools[(int)$emp['school_id']];
            $tok = bulkToken(); file_put_contents($bulkDir . '/' . $tok . '.pdf', $pdf);
            $_SESSION['bulk_files'][$tok] = $name;
            $res = ['ok' => true, 'tok' => $tok, 'name' => $name, 'label' => $label, 'size' => strlen($pdf), 'used' => ($type === 'cnss_hire' ? (hasNssfNumber($emp['nssf_number'] ?? '') ? 'cnss_hire_reg' : 'cnss_hire_new') : $type)];
            if (!empty($in['email_each'])) {
                $to = trim((string)($emp['email'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $res['mail'] = 'none'; $res['msg'] = 'ما عنده إيميل بملفه'; }
                else {
                    $schoolFr = (string)(($sch['name_fr'] ?? '') ?: ($sch['name_ar'] ?? ''));
                    [$subject, $body] = bulkMailText(bulkFrLabel($res['used'] ?? $type), $schoolFr, 1);
                    [$ok, $err] = msaSendMail($to, $subject, $body, [['name' => $name, 'data' => $pdf, 'mime' => 'application/pdf']], false, $sch ? (int)$sch['id'] : null, $schoolFr);
                    $res['mail'] = $ok ? 'sent' : 'failed'; $res['to'] = $to; $res['msg'] = $ok ? ('انبعت إلى ' . $to) : ('فشل الإرسال: ' . $err);
                }
            }
            bulkJson($res);
        }
        if ($act === 'mailtok') {   // 📧 (بعد المراجعة) إرسال ملف جاهز لأستاذه على إيميل ملفه
            $tok = (string)($in['tok'] ?? ''); $eid = (int)($in['emp'] ?? 0);
            if (!bulkOwn($tok) || !is_file($bulkDir . '/' . $tok . '.pdf')) bulkJson(['ok' => false, 'msg' => 'انتهت صلاحية الملف — أعد التجهيز']);
            $st = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0"); $st->execute([$eid]); $emp = $st->fetch(PDO::FETCH_ASSOC);
            $schools = bulkSchools();
            if (!$emp || !isset($schools[(int)$emp['school_id']])) bulkJson(['ok' => false, 'msg' => 'الأستاذ غير موجود بمدارسك']);
            $to = trim((string)($emp['email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) bulkJson(['ok' => false, 'mail' => 'none', 'msg' => 'ما عنده إيميل بملفه']);
            $sch = $schools[(int)$emp['school_id']]; $schoolFr = (string)(($sch['name_fr'] ?? '') ?: ($sch['name_ar'] ?? ''));
            $used = ($type === 'cnss_hire' ? (hasNssfNumber($emp['nssf_number'] ?? '') ? 'cnss_hire_reg' : 'cnss_hire_new') : $type);
            [$subject, $body] = bulkMailText(bulkFrLabel($used), $schoolFr, 1);
            [$ok, $err] = msaSendMail($to, $subject, $body, [['name' => (string)$_SESSION['bulk_files'][$tok], 'data' => (string)file_get_contents($bulkDir . '/' . $tok . '.pdf'), 'mime' => 'application/pdf']], false, (int)$sch['id'], $schoolFr);
            bulkJson(['ok' => (bool)$ok, 'mail' => $ok ? 'sent' : 'failed', 'to' => $to, 'msg' => $ok ? ('انبعت إلى ' . $to) : ('فشل الإرسال: ' . $err)]);
        }
        if ($act === 'merge') {
            $toks = (array)($in['toks'] ?? []); $paths = [];
            foreach ($toks as $t) { $t = (string)$t; if (bulkOwn($t) && is_file($bulkDir . '/' . $t . '.pdf')) $paths[] = $bulkDir . '/' . $t . '.pdf'; }
            if (!$paths) bulkJson(['ok' => false, 'msg' => 'لا ملفات للدمج']);
            $merged = count($paths) === 1 ? (string)file_get_contents($paths[0]) : bulkMerge($paths);
            if (strpos($merged, '%PDF') !== 0) bulkJson(['ok' => false, 'msg' => 'تعذّر دمج الملفات']);
            $tok = bulkToken(); file_put_contents($bulkDir . '/' . $tok . '.pdf', $merged);
            $name = ($LATIN[$type] ?? 'Attestations') . '_groupe_' . count($paths) . '_' . $date . '.pdf';
            $_SESSION['bulk_files'][$tok] = $name;
            bulkJson(['ok' => true, 'tok' => $tok, 'name' => $name, 'size' => strlen($merged), 'pages' => preg_match_all('/\/Type\s*\/Page[^s]/', $merged)]);
        }
        if ($act === 'file') {
            $tok = (string)($in['tok'] ?? '');
            if (!bulkOwn($tok) || !is_file($bulkDir . '/' . $tok . '.pdf')) { http_response_code(404); die('انتهت صلاحية الملف — أعد التوليد'); }
            $name = (string)$_SESSION['bulk_files'][$tok]; $data = (string)file_get_contents($bulkDir . '/' . $tok . '.pdf');
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . (empty($in['dl']) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
            header('Content-Length: ' . strlen($data)); header('Cache-Control: no-store');
            echo $data; exit;
        }
        if ($act === 'mailfile') {
            $tok = (string)($in['tok'] ?? ''); $to = trim((string)($in['to'] ?? ''));
            if (!bulkOwn($tok) || !is_file($bulkDir . '/' . $tok . '.pdf')) bulkJson(['ok' => false, 'msg' => 'انتهت صلاحية الملف']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) bulkJson(['ok' => false, 'msg' => 'الإيميل غير صحيح']);
            $schools = bulkSchools(); $sch = $schools[(int)($in['sid'] ?? 0)] ?? (currentSchool() ?: (reset($schools) ?: null));
            $schoolFr = $sch ? (string)(($sch['name_fr'] ?? '') ?: ($sch['name_ar'] ?? '')) : '';
            $n = max(1, (int)($in['n'] ?? 0));
            [$subject, $body] = bulkMailText(bulkFrLabel($type), $schoolFr, $n);
            [$ok, $err] = msaSendMail($to, $subject, $body, [['name' => (string)$_SESSION['bulk_files'][$tok], 'data' => (string)file_get_contents($bulkDir . '/' . $tok . '.pdf'), 'mime' => 'application/pdf']], false, $sch ? (int)$sch['id'] : null, $schoolFr);
            bulkJson(['ok' => (bool)$ok, 'msg' => $ok ? ('انبعت إلى ' . $to) : ('فشل الإرسال: ' . $err)]);
        }
        bulkJson(['ok' => false, 'msg' => 'طلب غير معروف']);
    } catch (Throwable $e) { bulkJson(['ok' => false, 'msg' => $e->getMessage()]); }
}

/* ======================= الصفحة ======================= */
$currentPage = 'attestations';
$pageTitle = 'Attestations groupées / إفادات جماعية';
$hideExportToolbar = true;   // كبسات الطباعة/الإيميل/الواتساب الثلاث داخل الصفحة نفسها
// 🏫 (2026-10-27 «وأكيد كمان بكون حاطط أي مدرسة أو مدارس») اختيار المدارس داخل الصفحة: الافتراضي مدرسة الشريط (أو كلّها بوضع «كل المدارس»)
$schoolsAll = bulkSchools();
$selSch = array_values(array_filter(array_map('intval', (array)($_GET['schools'] ?? [])), fn($i) => isset($schoolsAll[$i])));
if (!$selSch) $selSch = isAllSchools() ? array_keys($schoolsAll) : array_values(array_filter(array_map('intval', activeSchoolIds()), fn($i) => isset($schoolsAll[$i])));
if (!$selSch) $selSch = array_keys($schoolsAll);
$school = count($selSch) === 1 ? $schoolsAll[$selSch[0]] : null;
$employees = [];
if ($selSch) {
    [$ayf, $ayp] = yearEmploymentFilter(activeSchoolYear());
    $st = $db->prepare("SELECT id, school_id, employee_code, first_name_fr, last_name_fr, first_name_ar, last_name_ar, employee_type, email, phone1, phone2, hire_date, nssf_number, status
                          FROM employees WHERE is_deleted = 0 AND school_id IN (" . implode(',', $selSch) . ")" . $ayf . "
                         ORDER BY school_id, FIELD(employee_type,'enseignant_titulaire','enseignant_contractuel','employe'), " . arSortKeySql('COALESCE(NULLIF(first_name_ar,\'\'),first_name_fr)') . ", " . arSortKeySql('COALESCE(NULLIF(last_name_ar,\'\'),last_name_fr)') . ", id");
    $st->execute($ayp);
    $employees = $st->fetchAll(PDO::FETCH_ASSOC);
}
$sy = preg_match('/^\d{4}-\d{4}$/', (string)activeSchoolYear()) ? activeSchoolYear() : currentSchoolYear();
[$sy1, $sy2] = schoolYearToYears($sy);
$preType = (string)($_GET['type'] ?? '');
$multiSch = count($selSch) > 1;
include __DIR__ . '/../includes/header.php';
?>
<style>
.bk-steps{display:grid;gap:14px}
.bk-step{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:12px 14px}
.bk-step .bk-h{display:flex;align-items:center;gap:10px;font-weight:800;color:#0f172a;margin-bottom:10px;font-size:15px}
.bk-step .bk-n{width:28px;height:28px;border-radius:50%;background:#0e7490;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:14px;flex:0 0 28px}
.bk-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px}
.bk-tools input[type=search]{min-width:240px}
.bk-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:4px 10px;max-height:380px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px;background:#f8fafc}
.bk-list label{display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:6px;cursor:pointer;font-size:13.5px;min-height:34px}
.bk-list label:hover{background:#eef2f7}
.bk-list label.hide{display:none}
.bk-list .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bk-list .ct{font-size:11.5px;color:#64748b;white-space:nowrap}
.bk-list .ct.miss{color:#b45309}
.bk-act{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch}
.bk-card{flex:1 1 240px;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}
.bk-card .t{font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.bk-card .opt{display:flex;flex-direction:column;gap:6px;margin-bottom:8px;font-size:13.5px}
.bk-card input[type=text],.bk-card input[type=email],.bk-card input[type=tel]{width:100%}
.bk-prog{display:none;margin-top:14px;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}
.bk-bar{height:10px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin:6px 0 10px}
.bk-bar i{display:block;height:100%;background:#0e7490;width:0;transition:width .2s}
.bk-res{width:100%;border-collapse:collapse;font-size:13px}
.bk-res th,.bk-res td{padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:right}
.bk-res th{background:#f1f5f9;font-weight:700}
.bk-res .ok{color:#166534;font-weight:700}.bk-res .bad{color:#b91c1c;font-weight:700}.bk-res .wait{color:#64748b}
.bk-res .btn{padding:3px 10px;font-size:12.5px}
</style>
<div class="card">
    <div class="card-header"><h3>
        <span dir="ltr"><i class="fas fa-layer-group"></i> Attestations groupées — une attestation pour plusieurs enseignants</span>
        <div style="font-size:0.85em;font-weight:600;opacity:0.9">إفادات جماعية — إفادة واحدة لمجموعة أساتذة: طباعة أو إيميل أو واتساب بكبسة وحدة</div>
    </h3></div>
    <div class="card-body">
        <?php /* 🏫 المدارس: خانات تشيك (وحدة أو أكثر) — تغييرها يعيد تحميل اللائحة */ ?>
        <form method="GET" class="bk-step" style="margin-bottom:14px">
            <?php if ($preType !== ''): ?><input type="hidden" name="type" value="<?= e($preType) ?>"><?php endif; ?>
            <div class="bk-h"><span class="bk-n" style="background:#173f6f"><i class="fas fa-school" style="font-size:12px"></i></span> École(s) / المدرسة أو المدارس <span class="text-muted" style="font-weight:400;font-size:12.5px">— شيك على وحدة أو أكثر ثمّ «عرض»</span></div>
            <div class="school-checks msa-school-pick">
                <?php foreach ($schoolsAll as $sid => $s): ?>
                <label class="chk"><input type="checkbox" name="schools[]" value="<?= (int)$sid ?>" <?= in_array((int)$sid, $selSch, true) ? 'checked' : '' ?>> <span><?= e($s['name_ar'] ?: $s['name_fr']) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                <button class="btn btn-primary"><i class="fas fa-rotate"></i> عرض الأساتذة / Afficher</button>
                <button type="button" class="btn btn-light" onclick="this.form.querySelectorAll('input[name=\'schools[]\']').forEach(function(c){c.checked=true});this.form.submit()"><i class="fas fa-check-double"></i> كل المدارس</button>
            </div>
        </form>
    <?php if (!$employees): ?>
        <div class="alert alert-info">لا أساتذة بالمدارس المختارة لهذه السنة / Aucun employé.</div>
    <?php else: ?>
        <div class="bk-steps">
            <div class="bk-step">
                <div class="bk-h"><span class="bk-n">1</span> Quelle attestation ? / أيّ إفادة؟ <span class="text-muted" style="font-weight:400;font-size:12.5px">— تشاك مارك على وحدة</span></div>
                <div class="att-type-pick att-groups">
                    <?php $gi = 0; foreach ($ATT_GROUPS as $gTitle => $keys): $gi++; $gIcon = ['fa-money-check-dollar', 'fa-door-open', 'fa-file-contract', 'fa-folder-open'][min($gi, 4) - 1]; ?>
                    <div class="att-group att-g<?= min($gi, 4) ?>">
                        <div class="att-gt"><i class="fas <?= $gIcon ?>"></i> <?= e($gTitle) ?></div>
                        <div class="school-checks att-type-checks">
                            <?php foreach ($keys as $k): if (!isset($ATT_TYPES[$k])) continue; ?>
                            <label class="chk"><input type="radio" name="bk_type" value="<?= e($k) ?>" <?= $k === $preType ? 'checked' : '' ?> autocomplete="off"> <span><?= e($ATT_TYPES[$k]['fr']) ?> / <?= e($ATT_TYPES[$k]['ar']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-row cols-3" style="margin-top:8px">
                    <div class="form-group mb-0"><label class="form-label">Langue / اللغة</label>
                        <div class="school-checks att-lang-checks"><?php foreach ($DOC_LANGS as $k => $lbl): ?><label class="chk"><input type="radio" name="bk_lang" value="<?= $k ?>" <?= $k === 'ar' ? 'checked' : '' ?> autocomplete="off"> <span><?= e($lbl) ?></span></label><?php endforeach; ?></div></div>
                    <div class="form-group mb-0"><label class="form-label">Date / التاريخ على الإفادة</label><input type="date" id="bk_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                </div>
                <div class="text-muted" style="font-size:12.5px;margin-top:6px"><i class="fas fa-info-circle"></i> بالجماعي كل إفادة تطلع بمعلومات ملف الأستاذ كما هي (الخيارات المحفوظة له، الساعات × 4، كامل/جزئي، النموذج المناسب لرقم ضمانه). لتعديل شي لأستاذ معيّن اطلعها له لحاله من صفحة الإفادات.</div>
            </div>

            <div class="bk-step">
                <div class="bk-h"><span class="bk-n">2</span> Pour qui ? / لمين؟ <span id="bk_count" class="badge" style="background:#e0f2fe;color:#075985;font-size:13px">0 مختار</span></div>
                <div class="bk-tools">
                    <input type="search" id="bk_q" class="form-control" placeholder="فتّش بالاسم / الرقم / Nom…" autocomplete="off">
                    <button type="button" class="btn btn-sm btn-light" data-pick="all"><i class="fas fa-check-double"></i> الكل الظاهر</button>
                    <button type="button" class="btn btn-sm btn-light" data-pick="none"><i class="fas fa-xmark"></i> ولا واحد</button>
                    <button type="button" class="btn btn-sm btn-info" data-pick="new"><i class="fas fa-user-plus"></i> الجدد هالسنة (<?= e($sy) ?>)</button>
                    <?php /* 📅 (2026-10-27 «إذا بدنا استخدام أجير من أي تاريخ بيطلعوا اللي بهيدا التاريخ») الداخلون بتاريخ — كتقرير الداخلون: بالضبط أو كل السنة الدراسية */ ?>
                    <span style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;border:1px solid #e2e8f0;border-radius:8px;padding:4px 8px;background:#fff">
                        <i class="fas fa-door-open" style="color:#0e7490"></i> <strong style="font-size:13px">الداخلون بتاريخ</strong>
                        <?php /* «بس حطّ أي تاريخ لازم يبيّنوا بس الموظفين الداخلين بهذا التاريخ»: التاريخ فلتر للائحة نفسها (فاضي = الكل) */ ?>
                        <?php $preHire = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['hire'] ?? '')) ? (string)$_GET['hire'] : ''; $preSpan = (($_GET['span'] ?? 'day') === 'year') ? 'year' : 'day';   // من تقرير الداخلون */ ?>
                        <input type="date" id="bk_hire" class="form-control" style="width:160px;padding:4px 8px" value="<?= e($preHire) ?>" title="فاضي = كل الأساتذة">
                        <label style="font-size:12.5px;margin:0"><input type="radio" name="bk_span" value="day" <?= $preSpan === 'day' ? 'checked' : '' ?>> بالضبط</label>
                        <label style="font-size:12.5px;margin:0"><input type="radio" name="bk_span" value="year" <?= $preSpan === 'year' ? 'checked' : '' ?>> كل السنة الدراسية</label>
                        <button type="button" class="btn btn-sm btn-light" data-pick="hire1010" title="1/10 من السنة المعروضة"><i class="fas fa-calendar-check"></i> 1/10/<?= e($sy1) ?></button>
                        <button type="button" class="btn btn-sm btn-light" data-pick="hireclear"><i class="fas fa-xmark"></i> امسح التاريخ</button>
                        <span id="bk_hire_n" class="text-muted" style="font-size:12px"></span>
                    </span>
                    <span class="school-checks emp-type-checks" style="margin:0">
                        <label class="chk"><input type="checkbox" class="bk-cat" value="enseignant_titulaire" checked> <span>ملاك</span></label>
                        <label class="chk"><input type="checkbox" class="bk-cat" value="enseignant_contractuel" checked> <span>متعاقدون</span></label>
                        <label class="chk"><input type="checkbox" class="bk-cat" value="employe" checked> <span>موظفون</span></label>
                    </span>
                </div>
                <div class="bk-list" id="bk_list">
                    <?php $curSid = null; foreach ($employees as $em):
                        if ($multiSch && (int)$em['school_id'] !== $curSid): $curSid = (int)$em['school_id']; ?>
                        <div class="bk-sch" data-sid="<?= $curSid ?>" style="grid-column:1/-1;font-weight:800;color:#173f6f;background:#e0e7ff;border-radius:6px;padding:5px 8px;margin-top:4px;display:flex;justify-content:space-between;align-items:center"><span><i class="fas fa-school"></i> <?= e(($schoolsAll[$curSid]['name_ar'] ?? '') ?: ($schoolsAll[$curSid]['name_fr'] ?? '')) ?></span><button type="button" class="btn btn-sm btn-light" data-pick-sch="<?= $curSid ?>" style="padding:2px 8px;font-size:12px">كل المدرسة</button></div>
                        <?php endif;
                        $isNew = !empty($em['hire_date']) && $em['hire_date'] >= $sy1 . '-10-01' && $em['hire_date'] <= $sy2 . '-09-30';
                        $mail = trim((string)($em['email'] ?? '')); $ph = trim((string)($em['phone1'] ?: ($em['phone2'] ?? '')));
                        $nameAr = trim(($em['first_name_ar'] ?? '') . ' ' . ($em['last_name_ar'] ?? '')); $nameFr = trim(($em['first_name_fr'] ?? '') . ' ' . ($em['last_name_fr'] ?? '')); ?>
                    <label data-cat="<?= e($em['employee_type']) ?>" data-new="<?= $isNew ? 1 : 0 ?>" data-sid="<?= (int)$em['school_id'] ?>" data-hire="<?= e((string)($em['hire_date'] ?? '')) ?>" data-q="<?= e(mb_strtolower($nameAr . ' ' . $nameFr . ' ' . $em['employee_code'] . ' ' . $mail . ' ' . $ph)) ?>">
                        <input type="checkbox" class="bk-emp" value="<?= (int)$em['id'] ?>" data-sid="<?= (int)$em['school_id'] ?>" data-name="<?= e($nameAr ?: $nameFr) ?>" data-mail="<?= e($mail) ?>" data-phone="<?= e($ph) ?>" data-hasnssf="<?= hasNssfNumber($em['nssf_number'] ?? '') ? 1 : 0 ?>">
                        <span class="nm"><strong><?= e($nameAr ?: $nameFr) ?></strong> <small class="text-muted" dir="ltr"><?= e($nameFr) ?></small><?= $isNew ? ' <span class="badge" style="background:#dcfce7;color:#166534;font-size:10.5px">جديد</span>' : '' ?></span>
                        <span class="ct <?= $mail === '' ? 'miss' : '' ?>" title="<?= e($mail ?: 'لا إيميل بالملف') ?>"><i class="fas fa-envelope"></i><?= $mail === '' ? ' —' : '' ?></span>
                        <span class="ct <?= $ph === '' ? 'miss' : '' ?>" title="<?= e($ph ?: 'لا رقم بالملف') ?>"><i class="fab fa-whatsapp"></i><?= $ph === '' ? ' —' : '' ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bk-step">
                <div class="bk-h"><span class="bk-n">3</span> Que faire ? / شو بدّك تعمل فيهن؟</div>
                <div class="bk-act">
                    <div class="bk-card">
                        <div class="t"><i class="fas fa-print" style="color:#173f6f"></i> Imprimer / اطبع</div>
                        <div class="opt text-muted">ملف PDF واحد، كل أستاذ على ورقته، بيفتح وبيطبع دغري.</div>
                        <button type="button" class="btn btn-primary w-100" data-run="print"><i class="fas fa-print"></i> جهّز للطباعة</button>
                    </div>
                    <div class="bk-card">
                        <div class="t"><i class="fas fa-envelope" style="color:#0e7490"></i> E-mail / إيميل</div>
                        <div class="opt">
                            <label><input type="radio" name="bk_mail_mode" value="each" checked> لكل أستاذ على إيميله (إفادته بس)</label>
                            <label><input type="radio" name="bk_mail_mode" value="one"> كلّها بملف واحد على إيميل واحد:</label>
                            <input type="email" id="bk_mail_to" class="form-control" placeholder="name@example.com" dir="ltr" value="<?= e((string)($school['email'] ?? '')) ?>">
                        </div>
                        <button type="button" class="btn btn-success w-100" data-run="email"><i class="fas fa-paper-plane"></i> جهّز للإيميل</button>
                    </div>
                    <div class="bk-card">
                        <div class="t"><i class="fab fa-whatsapp" style="color:#128C7E"></i> WhatsApp / واتساب</div>
                        <div class="opt">
                            <label><input type="radio" name="bk_wa_mode" value="each" checked> لكل أستاذ على رقمه (كبسة لكل واحد)</label>
                            <label><input type="radio" name="bk_wa_mode" value="one"> كلّها بملف واحد على رقم واحد:</label>
                            <input type="tel" id="bk_wa_to" class="form-control" placeholder="03 123 456" dir="ltr">
                        </div>
                        <button type="button" class="btn w-100" style="background:#25D366;color:#fff" data-run="wa"><i class="fab fa-whatsapp"></i> جهّز للواتساب</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bk-prog" id="bk_prog">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div id="bk_title" style="font-weight:800;font-size:15px"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap" id="bk_final"></div>
            </div>
            <div class="bk-bar"><i id="bk_fill"></i></div>
            <div id="bk_sum" class="text-muted" style="font-size:13px;margin-bottom:8px"></div>
            <table class="bk-res"><thead><tr><th>#</th><th>الأستاذ</th><th>الإفادة</th><th>الحالة</th><th></th></tr></thead><tbody id="bk_rows"></tbody></table>
        </div>
    <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var BASE = <?= json_encode(BASE_URL) ?>, API = BASE + 'pages/attestations_bulk.php';
    var list = document.getElementById('bk_list'); if (!list) return;
    var boxes = Array.prototype.slice.call(list.querySelectorAll('.bk-emp'));
    function visibleLabels() { return Array.prototype.slice.call(list.querySelectorAll('label')).filter(function (l) { return !l.classList.contains('hide'); }); }
    function count() { var n = boxes.filter(function (b) { return b.checked; }).length; document.getElementById('bk_count').textContent = n + ' مختار'; }
    function hireRange() {
        var d = document.getElementById('bk_hire').value; if (!d) return null;
        var span = sel('bk_span'); if (span === 'day') return [d, d];
        var y = parseInt(d.slice(0, 4), 10), mo = parseInt(d.slice(5, 7), 10), y1 = mo >= 10 ? y : y - 1;
        return [y1 + '-10-01', (y1 + 1) + '-09-30'];
    }
    function filter(autoPick) {
        var q = (document.getElementById('bk_q').value || '').toLowerCase().trim();
        var cats = Array.prototype.slice.call(document.querySelectorAll('.bk-cat')).filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
        var hr = hireRange(), n = 0;
        list.querySelectorAll('label').forEach(function (l) {
            var h = l.getAttribute('data-hire') || '';
            var ok = cats.indexOf(l.getAttribute('data-cat')) >= 0 && (!q || (l.getAttribute('data-q') || '').indexOf(q) >= 0) && (!hr || (h && h >= hr[0] && h <= hr[1]));
            l.classList.toggle('hide', !ok); if (ok) n++;
            if (hr && autoPick) l.querySelector('.bk-emp').checked = ok;   // بتاريخ محدّد: الظاهرون مختارون تلقائياً
        });
        // رأس المدرسة يختفي إذا ما ضلّ تحتها أحد
        list.querySelectorAll('.bk-sch').forEach(function (s) { var sid = s.getAttribute('data-sid'), any = false; list.querySelectorAll('label[data-sid="' + sid + '"]').forEach(function (l) { if (!l.classList.contains('hide')) any = true; }); s.style.display = any ? '' : 'none'; });
        var hn = document.getElementById('bk_hire_n'); if (hn) hn.textContent = hr ? ('⇐ ' + n + ' داخل' + (sel('bk_span') === 'day' ? ' بهذا التاريخ' : ' بهذه السنة الدراسية')) : '';
        count();
    }
    document.getElementById('bk_q').addEventListener('input', function () { filter(false); });
    document.querySelectorAll('.bk-cat').forEach(function (c) { c.addEventListener('change', function () { filter(false); }); });
    document.getElementById('bk_hire').addEventListener('change', function () { filter(true); });
    document.querySelectorAll('input[name="bk_span"]').forEach(function (r) { r.addEventListener('change', function () { filter(true); }); });
    boxes.forEach(function (b) { b.addEventListener('change', count); });
    document.querySelectorAll('[data-pick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var m = btn.getAttribute('data-pick');
            if (m === 'none') boxes.forEach(function (b) { b.checked = false; });
            else if (m === 'all') visibleLabels().forEach(function (l) { l.querySelector('.bk-emp').checked = true; });
            else if (m === 'new') { boxes.forEach(function (b) { b.checked = false; }); visibleLabels().forEach(function (l) { if (l.getAttribute('data-new') === '1') l.querySelector('.bk-emp').checked = true; }); }
            else if (m === 'hire1010') { document.getElementById('bk_hire').value = <?= json_encode($sy1 . '-10-01') ?>; filter(true); return; }
            else if (m === 'hireclear') { document.getElementById('bk_hire').value = ''; boxes.forEach(function (b) { b.checked = false; }); filter(false); return; }
            count();
        });
    });
    document.querySelectorAll('[data-pick-sch]').forEach(function (btn) { btn.addEventListener('click', function () { var sid = btn.getAttribute('data-pick-sch'); visibleLabels().forEach(function (l) { if (l.getAttribute('data-sid') === sid) l.querySelector('.bk-emp').checked = true; }); count(); }); });
    function sel(name) { var r = document.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; }
    function escH(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function waNum(v) { var n = String(v || '').replace(/[^0-9]/g, ''); if (n.length === 8 && /^(03|70|71|76|78|79|81)/.test(n)) n = '961' + n.replace(/^0/, ''); else if (n.length === 7 && /^(3|70|71|76|78|79|81)/.test(n)) n = '961' + n; else if (n.length === 8 && /^0/.test(n)) n = '961' + n.slice(1); return n; }
    function post(data) {
        var fd = new FormData(); Object.keys(data).forEach(function (k) { if (Array.isArray(data[k])) data[k].forEach(function (v) { fd.append(k + '[]', v); }); else fd.append(k, data[k]); });
        return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function canShare() { try { return !!(navigator.share && navigator.canShare && window.File && navigator.canShare({ files: [new File([new Blob(['%PDF-'], { type: 'application/pdf' })], 'a.pdf', { type: 'application/pdf' })] })); } catch (e) { return false; } }
    function shareTok(tok, name, phone, title) {
        var url = API + '?act=file&tok=' + tok;
        if (canShare()) {
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.blob(); }).then(function (bl) {
                var f = new File([bl], name, { type: 'application/pdf' });
                return navigator.share({ files: [f], title: title });
            }).catch(function () { manual(); });
        } else manual();
        function manual() {
            window.open(url + '&dl=1', '_blank');
            var n = waNum(phone); window.open('https://wa.me/' + (n || '') + '?text=' + encodeURIComponent(title), '_blank');
        }
    }
    var running = false;
    document.querySelectorAll('[data-run]').forEach(function (btn) {
        btn.addEventListener('click', function () { if (!running) run(btn.getAttribute('data-run')); });
    });
    function run(mode) {
        var type = sel('bk_type'), lang = sel('bk_lang'), date = document.getElementById('bk_date').value;
        var picked = boxes.filter(function (b) { return b.checked; });
        if (!type) { alert('اختَر نوع الإفادة أوّلاً (الخطوة 1)'); return; }
        if (!picked.length) { alert('اختَر أستاذاً واحداً على الأقلّ (الخطوة 2)'); return; }
        var mailMode = sel('bk_mail_mode'), waMode = sel('bk_wa_mode');
        var mailTo = (document.getElementById('bk_mail_to').value || '').trim(), waTo = (document.getElementById('bk_wa_to').value || '').trim();
        if (mode === 'email' && mailMode === 'one' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mailTo)) { alert('اكتب الإيميل الواحد'); document.getElementById('bk_mail_to').focus(); return; }
        if (mode === 'wa' && waMode === 'one' && !waNum(waTo)) { alert('اكتب رقم الواتساب الواحد'); document.getElementById('bk_wa_to').focus(); return; }
        running = true;
        var prog = document.getElementById('bk_prog'), rows = document.getElementById('bk_rows'), fill = document.getElementById('bk_fill'), fin = document.getElementById('bk_final'), sum = document.getElementById('bk_sum');
        prog.style.display = 'block'; rows.innerHTML = ''; fin.innerHTML = ''; fill.style.width = '0%';
        document.getElementById('bk_title').textContent = (mode === 'print' ? '🖨️ طباعة' : mode === 'email' ? '📧 إيميل' : '📲 واتساب') + ' — ' + picked.length + ' أستاذ';
        prog.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var toks = [], okN = 0, badN = 0, i = 0;
        picked.forEach(function (b, idx) {
            var tr = document.createElement('tr'); tr.id = 'bkr' + b.value;
            tr.innerHTML = '<td>' + (idx + 1) + '</td><td>' + escH(b.getAttribute('data-name')) + '</td><td class="lbl">—</td><td class="st wait">⏳ بالانتظار</td><td class="ac"></td>';
            rows.appendChild(tr);
        });
        function setRow(b, cls, txt, lbl) { var tr = document.getElementById('bkr' + b.value); tr.querySelector('.st').className = 'st ' + cls; tr.querySelector('.st').textContent = txt; if (lbl) tr.querySelector('.lbl').textContent = lbl; return tr; }
        // ⚡ («خلّي يكون أسرع الإرسال أو الطبع») 3 إفادات تُبنى معاً — مدرسةً مدرسة (الترويسة تتبع المدرسة الفعّالة بالجلسة فلا تختلط بين مدرستين)؛ ترتيب الملف الواحد = ترتيب اللائحة
        var POOL = 3, done = 0, curSid = null, active = 0, toksBy = new Array(picked.length);
        function next() {
            while (active < POOL && i < picked.length) {
                var b = picked[i], sid = b.getAttribute('data-sid') || '';
                if (active > 0 && sid !== curSid) break;   // مدرسة تانية: انطر الحاليين يخلصوا
                curSid = sid; var idx = i; i++; active++;
                one(b, idx);
            }
            if (done >= picked.length) finish();
        }
        // 👀 («بدّي وقت بيصير الملف جاهز قبل ما أبعت») التجهيز وحده أوّلاً: قدّام كل أستاذ «جاهز» + «شوف» + «احذف من الإرسال»،
        //    ولا طباعة ولا إرسال إلّا بالكبسة النهائية بعد المراجعة — على الباقين المشمولين فقط
        var removed = {};
        function one(b, idx) {
            setRow(b, 'wait', '⏳ عم نجهّز…');
            post({ act: 'gen', emp: b.value, type: type, lang_doc: lang, date: date }).then(function (r) {
                if (!r.ok) { badN++; setRow(b, 'bad', '✕ ' + (r.msg || 'فشل')); }
                else {
                    toksBy[idx] = r.tok; b.setAttribute('data-tok', r.tok); b.setAttribute('data-file', r.name);
                    okN++; var tr = setRow(b, 'ok', '✅ جاهز', r.label);
                    tr.querySelector('.ac').innerHTML = '<a class="btn btn-sm btn-light" target="_blank" rel="noopener" href="' + API + '?act=file&tok=' + r.tok + '"><i class="fas fa-eye"></i> شوف</a>'
                        + (mode === 'wa' && waMode === 'each' ? ' <button type="button" class="btn btn-sm" style="background:#25D366;color:#fff" data-wa="' + r.tok + '"><i class="fab fa-whatsapp"></i> ابعت' + (b.getAttribute('data-phone') ? ' ' + escH(b.getAttribute('data-phone')) : '') + '</button>' : '')
                        + ' <button type="button" class="btn btn-sm btn-light" data-rm="' + idx + '" style="color:#b91c1c"><i class="fas fa-user-xmark"></i> احذف من الإرسال</button>';
                    var wb = tr.querySelector('[data-wa]'); if (wb) wb.addEventListener('click', function () { shareTok(r.tok, r.name, b.getAttribute('data-phone'), r.label + ' — ' + b.getAttribute('data-name')); });
                    tr.querySelector('[data-rm]').addEventListener('click', function () {
                        removed[idx] = !removed[idx];
                        tr.style.opacity = removed[idx] ? '.45' : ''; tr.style.textDecoration = removed[idx] ? 'line-through' : '';
                        this.innerHTML = removed[idx] ? '<i class="fas fa-rotate-left"></i> رجّعه' : '<i class="fas fa-user-xmark"></i> احذف من الإرسال';
                        this.style.color = removed[idx] ? '#166534' : '#b91c1c';
                        tr.querySelector('.st').textContent = removed[idx] ? '⛔ محذوف من الإرسال' : '✅ جاهز';
                        updateFinal();
                    });
                }
            }).catch(function (e) { badN++; setRow(b, 'bad', '✕ خطأ اتصال'); })
            .then(function () { active--; done++; fill.style.width = Math.round(done / picked.length * 100) + '%'; sum.textContent = 'خلص ' + done + ' من ' + picked.length + ' — ✅ ' + okN + ' · ✕ ' + badN; next(); });
        }
        function remaining() { var out = []; picked.forEach(function (b, idx) { if (toksBy[idx] && !removed[idx]) out.push({ b: b, idx: idx, tok: toksBy[idx], name: b.getAttribute('data-file') }); }); return out; }
        var finished = false, finalBtn = null;
        function updateFinal() {
            if (!finished) return;
            var n = remaining().length;
            if (!finalBtn) return;
            var lbl = mode === 'print' ? '🖨️ اطبع الملف الواحد (' + n + ')' : mode === 'email' ? (mailMode === 'each' ? '📧 ابعت لكل أستاذ على إيميله (' + n + ')' : '📧 ابعت الملف الواحد إلى ' + mailTo + ' (' + n + ')') : (waMode === 'each' ? '' : '📲 جهّز الملف الواحد للواتساب (' + n + ')');
            finalBtn.textContent = lbl; finalBtn.style.display = lbl && n ? '' : 'none';
            sum.textContent = 'جاهز: ' + n + ' أستاذ' + (Object.keys(removed).filter(function (k) { return removed[k]; }).length ? ' (المحذوفون من الإرسال ما بينبعتوا)' : '') + ' — راجع «شوف» قدّام كل اسم ثمّ اكبس الكبسة النهائية.';
        }
        function finish() {
            if (finished) return; finished = true; running = false;
            if (!remaining().length) { sum.textContent += ' — ما في ولا ملف جاهز.'; return; }
            if (mode === 'wa' && waMode === 'each') { sum.textContent = 'جاهز — راجع «شوف» ثمّ اكبس «ابعت» جنب كل أستاذ.'; return; }
            finalBtn = document.createElement('button'); finalBtn.type = 'button'; finalBtn.className = 'btn btn-primary'; finalBtn.style.fontWeight = '800';
            fin.innerHTML = ''; fin.appendChild(finalBtn); updateFinal();
            finalBtn.addEventListener('click', function () { finalBtn.disabled = true; finalBtn.textContent = '⏳ …'; runFinal(); });
        }
        function runFinal() {
            var rem = remaining(); if (!rem.length) { sum.textContent = 'ما بقي أحد مشمولاً بالإرسال.'; finalBtn.style.display = 'none'; return; }
            if (mode === 'email' && mailMode === 'each') {
                // إرسال متسلسل لكل أستاذ على إيميل ملفه — الحالة قدّام اسمه
                var k = 0, sentN = 0, failN = 0;
                (function nxt() {
                    if (k >= rem.length) { sum.textContent = '✅ انبعت ' + sentN + (failN ? ' · ✕ ' + failN : '') + ' — التفاصيل بسجلّ الإيميلات.'; fin.innerHTML = '<a class="btn btn-sm btn-light" href="' + BASE + 'pages/mail_log.php"><i class="fas fa-envelope-open-text"></i> سجلّ الإيميلات</a>'; return; }
                    var it = rem[k++]; setRow(it.b, 'wait', '⏳ عم نبعت…');
                    post({ act: 'mailtok', tok: it.tok, emp: it.b.value, type: type }).then(function (s) { if (s.ok) { sentN++; setRow(it.b, 'ok', '✅ ' + s.msg); } else { failN++; setRow(it.b, 'bad', '✕ ' + (s.msg || 'ما انبعت')); } })
                        .catch(function () { failN++; setRow(it.b, 'bad', '✕ خطأ اتصال'); }).then(function () { sum.textContent = 'عم نبعت ' + k + ' من ' + rem.length + '…'; nxt(); });
                })();
                return;
            }
            sum.textContent = 'عم ندمج ' + rem.length + ' ملفاً…';
            post({ act: 'merge', toks: rem.map(function (x) { return x.tok; }), type: type, date: date }).then(function (m) {
                if (!m.ok) { sum.textContent = '✕ ' + (m.msg || 'تعذّر الدمج'); finalBtn.disabled = false; updateFinal(); return; }
                var url = API + '?act=file&tok=' + m.tok;
                sum.textContent = 'ملف واحد جاهز: ' + m.name + ' (' + m.pages + ' صفحة)';
                fin.innerHTML = '<a class="btn btn-sm btn-primary" target="_blank" rel="noopener" href="' + url + '"><i class="fas fa-file-pdf"></i> افتح الملف</a> <a class="btn btn-sm btn-light" href="' + url + '&dl=1"><i class="fas fa-download"></i> نزّل</a>';
                if (mode === 'print') { if (window.ppPrintFile) window.ppPrintFile(url, false); else window.open(url, '_blank'); }
                else if (mode === 'email') {
                    sum.textContent += ' — عم نبعت إلى ' + mailTo + '…';
                    post({ act: 'mailfile', tok: m.tok, to: mailTo, type: type, n: rem.length, sid: rem[0].b.getAttribute('data-sid') || '' }).then(function (s) { sum.textContent = (s.ok ? '✅ ' : '✕ ') + s.msg + ' — ' + m.name; });
                } else if (mode === 'wa') { shareTok(m.tok, m.name, waTo, m.name); }
            });
        }
        next();
    }
    count(); filter(!!document.getElementById('bk_hire').value);   // جايين من تقرير الداخلون بتاريخ ⇒ الداخلون ظاهرون ومختارون جاهزين
    if (document.getElementById('bk_hire').value && document.querySelector('input[name="bk_type"]:checked')) { var s3 = document.querySelectorAll('.bk-step')[3] || document.querySelector('.bk-act'); if (s3) setTimeout(function () { s3.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300); }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
