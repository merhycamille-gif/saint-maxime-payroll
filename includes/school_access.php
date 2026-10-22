<?php
/**
 * 🏫🔑 دخول المدارس وأيقوناتها (2026-10-22 «بدي لكل مدرسة أيقونة أبعتها إلها تفتح عندها على المكتب،
 *  وحطّلي الخيار اللي أنا بسمحلها تشوفو، واليوزر باسم المنطقة والباسورد متل تبعي»).
 *  - حساب كل مدرسة = صفّ users بدور viewer (موجود من 07-06). هنا نضيف فقط:
 *    · اسم دخول = اسم المنطقة (من عنوان المدرسة) + كلمة سرّ نموذجية «Region@السنة» (قابلة للتغيير).
 *    · عمود users.pw_known (يتركّب ذاتياً): كلمة السرّ كما وضعها المدير ليراها لاحقاً؛ يُمحى إذا غيّرتها المدرسة بنفسها.
 *    · رابط تركيب لكل مدرسة: login.php?u=<اسم الدخول> ⇒ صفحة دخول باسم المدرسة + كبسة «تركيب على سطح المكتب»
 *      (PWA: manifest.php?u=… بأيقونة خاصّة icon.php?u=…) + تنزيل اختصار .url.
 *    · رسالة جاهزة (إيميل/واتساب) فيها الرابط واسم الدخول وكلمة السرّ وما تراه.
 *  لا يمسّ محرّك الحساب ولا صلاحيات viewer (enforceViewerRestrictions) — كلّه طبقة فوق users.
 */

/** عمود كلمة السرّ المعروفة (يتركّب ذاتياً — قاعدة «DB Self-Install»). */
function saEnsureCols(): void {
    static $done = false; if ($done) return; $done = true;
    try { $db = getDB(); if (!msaHasCol($db, 'users', 'pw_known')) $db->exec("ALTER TABLE users ADD COLUMN pw_known VARCHAR(120) NULL"); } catch (Throwable $e) {}
}

/** قاعدة الموقع العامّ الذي تدخل منه المدارس (الأونلاين دائماً، حتى لو فُتحت الصفحة من نسخة الكمبيوتر/السيرفر). */
function saPublicBase(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (stripos($host, 'msapayroll.com') !== false) return 'https://' . preg_replace('/^www\./', '', $host) . '/';
    if (defined('ONLINE_SYNC_URL') && ONLINE_SYNC_URL) return rtrim((string)ONLINE_SYNC_URL, '/') . '/';
    return 'https://msapayroll.com/';
}

/** قاموس المناطق (عربي ⇒ اسم دخول لاتيني قصير). يُطابَق أطول مفتاح أوّلاً. */
function saPlaceDict(): array {
    return [
        'عبرا الجديدة' => 'abrajadida', 'تلال الحدث' => 'hadath', 'تلال كسارة' => 'ksara',
        'يارون' => 'yaroun', 'المنصورية' => 'mansourieh', 'منصورية' => 'mansourieh', 'الحدث' => 'hadath', 'الحدت' => 'hadath',
        'عبرا' => 'abra', 'المحتقرة' => 'mohtakra', 'جون' => 'joun', 'الفرزل' => 'ferzol', 'فرزل' => 'ferzol', 'ابلح' => 'ablah', 'أبلح' => 'ablah',
        'كسارة' => 'ksara', 'جعيتا' => 'jeita', 'الدامور' => 'damour', 'مغدوشة' => 'maghdouche', 'الاشرفية' => 'achrafieh', 'الأشرفية' => 'achrafieh',
        'بيروت' => 'beyrouth', 'زحلة' => 'zahle', 'صيدا' => 'saida', 'جونية' => 'jounieh', 'بعبدا' => 'baabda', 'الشياح' => 'chiyah',
    ];
}

/** اسم المنطقة (slug لاتيني) من عنوان المدرسة: address_fr أوّلاً (أوّل مقطع)، ثمّ العربي عبر القاموس. */
function saRegionSlug(array $s): string {
    $clean = function (string $x): string {
        $x = strtolower(trim($x));
        $x = strtr($x, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ï' => 'i', 'î' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe']);
        $x = preg_replace('/^(el|al|le|la|les)\s+/', '', $x);
        return preg_replace('/[^a-z0-9]/', '', $x);
    };
    $fr = trim((string)($s['address_fr'] ?? ''));
    if ($fr !== '') {
        $first = preg_split('/\s*[-,\/]\s*/u', $fr)[0] ?? '';
        $slug = $clean($first);
        if ($slug !== '' && strlen($slug) >= 3) return substr($slug, 0, 18);
    }
    $ar = (string)($s['address'] ?? '') . ' ' . (string)($s['name_ar'] ?? '');
    $dict = saPlaceDict(); uksort($dict, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($dict as $k => $v) if (mb_strpos($ar, $k) !== false) return $v;
    // آخر حلّ: أوّل كلمة لاتينية بالعنوان، وإلّا ecole<id>
    if (preg_match('/[A-Za-z]{3,}/', (string)($s['address'] ?? ''), $m)) return substr(strtolower($m[0]), 0, 18);
    return 'ecole' . (int)($s['id'] ?? 0);
}

/** لاحقة تميّز مؤسّستين بنفس المنطقة (دير/مركز/مستوصف/دار) — من الاسم. */
function saKindSuffix(array $s): string {
    $t = mb_strtolower((string)($s['name_fr'] ?? '') . ' ' . (string)($s['name_ar'] ?? ''));
    foreach (['couvent' => 'couvent', 'دير' => 'couvent', 'clinique' => 'clinique', 'مستوصف' => 'clinique', 'physio' => 'physio', 'فيزيائي' => 'physio',
              'centre' => 'centre', 'مركز' => 'centre', 'dar ' => 'dar', 'دار ' => 'dar', 'lycee' => 'lycee', 'lycé' => 'lycee'] as $k => $v)
        if (mb_strpos($t, $k) !== false) return $v;
    return '';
}

/** ترتيب الأولوية على اسم المنطقة عند التعارض: المدرسة أوّلاً، ثمّ الدار، الدير، المركز، المستوصف، العلاج الفيزيائي. */
function saKindRank(array $s): int {
    $r = ['' => 0, 'lycee' => 0, 'dar' => 1, 'couvent' => 2, 'centre' => 3, 'clinique' => 4, 'physio' => 5];
    return $r[saKindSuffix($s)] ?? 9;
}
/** المدارس الفعّالة مرتّبة بحيث تأخذ المؤسّسة الرئيسية اسم المنطقة الصافي وتأخذ الأخرى اللاحقة. */
function saSchoolsForNaming(): array {
    $list = allSchools(true);
    usort($list, fn($a, $b) => (saKindRank($a) <=> saKindRank($b)) ?: strcmp((string)$a['name_fr'], (string)$b['name_fr']));
    return $list;
}

/** كلمة السرّ النموذجية (على نسق كلمة سرّ المدير: كلمة بحرف كبير + @ + السنة). */
function saStandardPassword(string $slug): string { return ucfirst(preg_replace('/[^a-z0-9]/', '', $slug) ?: 'ecole') . '@' . date('Y'); }

/** حساب «قراءة فقط» الخاصّ بمدرسة (مدرسة مفردة). */
function saViewerForSchool(int $sid): ?array {
    static $all = null;
    if ($all === null) {
        saEnsureCols();
        $all = [];
        foreach (getDB()->query("SELECT * FROM users WHERE role = 'viewer' ORDER BY is_active DESC, id")->fetchAll() as $u) {
            $as = (string)($u['allowed_schools'] ?? '');
            $one = (int)($u['school_id'] ?? 0);
            if ($one <= 0 && $as !== '' && $as !== 'all' && strpos($as, ',') === false) $one = (int)$as;
            if ($one > 0 && $as !== 'all' && strpos($as, ',') === false && !isset($all[$one])) $all[$one] = $u;
        }
    }
    return $all[$sid] ?? null;
}

/** اسم دخول فريد لمدرسة: المنطقة، وعند التعارض المنطقة+نوع المؤسّسة، ثمّ رقم. */
function saUniqueUsername(array $s, array $taken, int $selfId = 0): string {
    $base = saRegionSlug($s);
    $free = function (string $u) use ($taken, $selfId): bool { return !isset($taken[$u]) || (int)$taken[$u] === $selfId; };
    if ($free($base)) return $base;
    $k = saKindSuffix($s);
    if ($k !== '' && $free($base . $k)) return $base . $k;
    for ($i = 2; $i < 50; $i++) if ($free($base . $i)) return $base . $i;
    return $base . bin2hex(random_bytes(2));
}

/** اسم المدرسة القصير للأيقونة/الرسالة (بلا الكلمات الطويلة). */
function saShortName(array $s): string {
    $slug = saRegionSlug($s);
    return strtoupper($slug === '' ? 'MSA' : $slug);
}

/** الرابط الذي يُبعَث للمدرسة. */
function saInstallUrl(string $username): string { return saPublicBase() . 'login.php?u=' . rawurlencode($username); }

/** نصّ الرسالة (إيميل/واتساب): فرنسي ثمّ عربي، الرابط + الدخول + ما تراه + خطوات التركيب. */
function saMessageText(array $s, array $u, string $pw, bool $html = false): string {
    $url = saInstallUrl((string)$u['username']);
    $pages = viewerReportPages();
    $raw = $u['allowed_pages'] ?? null;
    $sel = $raw === null ? array_keys($pages) : array_filter(array_map('trim', explode(',', (string)$raw)));
    $fr = []; $ar = [];
    foreach ($sel as $k) if (isset($pages[$k])) { $fr[] = $pages[$k]['fr']; $ar[] = $pages[$k]['ar']; }
    $pwTxt = $pw !== '' ? $pw : '(inchangé / كما هي)';
    $nameFr = (string)($s['name_fr'] ?: $s['name_ar']); $nameAr = (string)($s['name_ar'] ?: $s['name_fr']);
    $t  = "MSA Payroll — Accès de l'école : {$nameFr}\n";
    $t .= "Lien (ouvrir sur l'ordinateur) : {$url}\n";
    $t .= "Identifiant : {$u['username']}\nMot de passe : {$pwTxt}\n";
    $t .= "Vous pouvez consulter : " . ($fr ? implode(' · ', $fr) : 'Tableau de bord') . "\n";
    $t .= "Pour mettre l'icône sur le bureau : ouvrez le lien avec Chrome ou Edge, puis cliquez « Installer sur le bureau ».\n";
    $t .= "\n";
    $t .= "برنامج الرواتب — دخول مدرسة: {$nameAr}\n";
    $t .= "الرابط (افتحوه على الكمبيوتر): {$url}\n";
    $t .= "اسم الدخول: {$u['username']}\nكلمة السرّ: {$pwTxt}\n";
    $t .= "المسموح لكم مشاهدته: " . ($ar ? implode(' · ', $ar) : 'لوحة القيادة') . "\n";
    $t .= "لوضع الأيقونة على سطح المكتب: افتحوا الرابط بمتصفّح Chrome أو Edge ثمّ اكبسوا «تركيب على سطح المكتب».\n";
    if (!$html) return $t;
    $h = nl2br(e($t));
    $h = str_replace(e($url), '<a href="' . e($url) . '">' . e($url) . '</a>', $h);
    return '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.8">' . $h . '</div>';
}
