<?php
/**
 * نقل حروف الأسماء من العربي إلى الفرنسي (تهجئة لبنانية).
 * arNameToFr($ar, 'first'|'last') → اسم بالفرنسي.
 * يعتمد قاموساً للأسماء/العائلات الشائعة، ثم نقل حروف احتياطي.
 * المستخدم يصحّح بالفرنسي يدوياً عند اللزوم.
 */

/** إزالة التشكيل والتطويل وتوحيد الألف/الهمزة لمطابقة القاموس. */
function arFrNormalize($s) {
    $s = trim((string)$s);
    // إزالة التشكيل (فتحة/ضمة/كسرة/شدّة/سكون/تنوين) والتطويل والمدّة العلوية
    $s = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $s);
    // توحيد الألف والهمزات
    $s = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $s);
    $s = str_replace(['ؤ'], 'و', $s);
    $s = str_replace(['ئ'], 'ي', $s);
    $s = str_replace(['ى'], 'ي', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** قاموس الأسماء الأولى (مفاتيح مُوحّدة). */
function arFrFirstDict() {
    static $d = null;
    if ($d !== null) return $d;
    $d = [
        'ريتا'=>'Rita','جورج'=>'Georges','جورج'=>'Georges','جوزيف'=>'Joseph','جوزف'=>'Joseph',
        'الياس'=>'Élias','ايلي'=>'Élie','ماري'=>'Marie','شربل'=>'Charbel','ماريا'=>'Maria',
        'منى'=>'Mona','مني'=>'Mona','فادي'=>'Fadi','ميراي'=>'Mireille','انطوان'=>'Antoine',
        'جان'=>'Jean','ميرنا'=>'Myrna','ندى'=>'Nada','ندي'=>'Nada','ريما'=>'Rima','رولا'=>'Roula',
        'دنيز'=>'Denise','الين'=>'Aline','لينا'=>'Lina','كارول'=>'Carole','رانيا'=>'Rania',
        'ليلى'=>'Layla','ليلي'=>'Layla','مريم'=>'Maryam','سوزان'=>'Suzanne','طانيوس'=>'Tanios',
        'تريز'=>'Thérèse','كارين'=>'Karine','شيرين'=>'Shirine','مايا'=>'Maya','عماد'=>'Imad',
        'حنان'=>'Hanan','ساره'=>'Sara','سارة'=>'Sara','جويل'=>'Joëlle','طوني'=>'Tony',
        'امال'=>'Amal','امل'=>'Amal','ميشال'=>'Michel','ميشيل'=>'Michel','شادي'=>'Chadi',
        'نور'=>'Nour','مارون'=>'Maroun','سمر'=>'Samar','كميل'=>'Camille','جيسيكا'=>'Jessica',
        'مي'=>'May','وسام'=>'Wissam','جوني'=>'Johnny','غريس'=>'Grâce','جوسلين'=>'Jocelyne',
        'نسرين'=>'Nesrine','فاديا'=>'Fadia','كريستيان'=>'Christian','روز'=>'Rose','غسان'=>'Ghassan',
        'ماغي'=>'Maggy','برناديت'=>'Bernadette','غادة'=>'Ghada','غاده'=>'Ghada','سيده'=>'Sayde',
        'لور'=>'Laure','نادين'=>'Nadine','لارا'=>'Lara','انطوانيت'=>'Antoinette','نوره'=>'Noura',
        'نورا'=>'Noura','اميل'=>'Émile','اجني'=>'Agnès','ريما'=>'Rima','زاهية'=>'Zahia',
        'كابي'=>'Gaby','روكز'=>'Roukoz','زياد'=>'Ziad','كوليت'=>'Colette','عائدة'=>'Aïda',
        'جاد'=>'Jad','رامي'=>'Rami','روميو'=>'Roméo','سامر'=>'Samer','طارق'=>'Tarek',
        'ربيع'=>'Rabih','هاني'=>'Hani','وليد'=>'Walid','نبيل'=>'Nabil','بيار'=>'Pierre',
        'بطرس'=>'Boutros','بولس'=>'Paul','يوسف'=>'Youssef','نقولا'=>'Nicolas','حنا'=>'Hanna',
        'سمعان'=>'Simon','جرجس'=>'Georges','متري'=>'Mitri','اسعد'=>'Assaad','انور'=>'Anwar',
        'سامي'=>'Sami','عصام'=>'Issam','جوزيان'=>'Josiane','جاكلين'=>'Jacqueline','مادونا'=>'Madonna',
        'كلودين'=>'Claudine','كلود'=>'Claude','بسام'=>'Bassam','رودولف'=>'Rodolphe','روني'=>'Ronny',
        'الهام'=>'Ilham','هيام'=>'Hiyam','رندى'=>'Randa','رندا'=>'Randa','داليا'=>'Dalia',
        'ديانا'=>'Diana','جيهان'=>'Jihane','نوال'=>'Nawal','سهى'=>'Soha','سها'=>'Soha',
        'فيفيان'=>'Viviane','هدى'=>'Houda','هدي'=>'Houda','ابتسام'=>'Ibtissam','نجوى'=>'Najwa',
        'سعاد'=>'Souad','وفاء'=>'Wafaa','رلى'=>'Roula','جينا'=>'Gina','كريستيل'=>'Christelle',
        'ايفا'=>'Eva','ايفون'=>'Yvonne','جوزفين'=>'Joséphine','ماغدا'=>'Magda','ماجدة'=>'Majida',
        'ماجده'=>'Majida','جسي'=>'Jessy','كارلا'=>'Carla','افيلين'=>'Aveline','ايلين'=>'Eileen',
        'ميشلين'=>'Micheline','عبدو'=>'Abdo','اسكندر'=>'Alexandre','فرنسيس'=>'François',
        'انطونيوس'=>'Antoine','بشاره'=>'Bechara','بشارة'=>'Bechara','فيليب'=>'Philippe',
        'روبير'=>'Robert','الفير'=>'Alfred','ايلدا'=>'Hilda','هيلدا'=>'Hilda','نتالي'=>'Nathalie',
        'ناتالي'=>'Nathalie','باسكال'=>'Pascale','كاترين'=>'Catherine','صونيا'=>'Sonia',
        'سيمون'=>'Simone','ايليان'=>'Éliane','جنفياف'=>'Geneviève','ريشار'=>'Richard',
        'ايدي'=>'Eddy','طوني'=>'Tony','شانتال'=>'Chantal','نايلة'=>'Nayla','نايله'=>'Nayla',
        'هلا'=>'Hala','ريم'=>'Rim','دارين'=>'Darine','كارمن'=>'Carmen','جوزيت'=>'Josette',
        'مارلين'=>'Marline','جوانا'=>'Joanna','خليل'=>'Khalil','فدوى'=>'Fadwa','اليز'=>'Élise',
        'بولا'=>'Paula','فاتنة'=>'Faten','تقلا'=>'Takla','سهام'=>'Siham','نانسي'=>'Nancy',
        'نزيه'=>'Nazih','مريانا'=>'Mariana','سمير'=>'Samir','حبيب'=>'Habib','جسيكا'=>'Jessica',
        'مايكل'=>'Michael','بول'=>'Paul','سلوى'=>'Salwa','اليانا'=>'Eliana','جنان'=>'Jinane',
        'زينه'=>'Zeina','زينة'=>'Zeina','مارغريتا'=>'Marguerita','ناجي'=>'Naji','منال'=>'Manal',
        'رنا'=>'Rana','رشا'=>'Racha','كلارا'=>'Clara','راكيل'=>'Rachelle','مرسال'=>'Marcel',
        'ياسمين'=>'Yasmine','دينا'=>'Dina','مهى'=>'Maha','رامونا'=>'Ramona','هنادي'=>'Hanadi',
        'ميلاد'=>'Milad','سابين'=>'Sabine','ساندرا'=>'Sandra','سامية'=>'Samia','كارولين'=>'Caroline',
        'ساندي'=>'Sandy','منير'=>'Mounir','ميرا'=>'Mira','تريزيا'=>'Thérésia','روزي'=>'Rosy',
        'محمد'=>'Mohammad','شريهان'=>'Cherihane','تانيا'=>'Tania','رودي'=>'Roudy','كريستينا'=>'Christina',
        'فيوليت'=>'Violette','راف'=>'Ralph','رانيه'=>'Rania','ريا'=>'Ria','هناء'=>'Hanaa','جومانة'=>'Joumana',
        'سيمون'=>'Simon','بيتر'=>'Peter','اندريه'=>'André','اندره'=>'André','رهام'=>'Reham','عبير'=>'Abir',
        'رالف'=>'Ralph','جاكي'=>'Jacky','نتالي'=>'Nathalie','جيلبير'=>'Gilbert','اغناطيوس'=>'Ignace',
    ];
    $nd = []; foreach ($d as $k => $v) { $nd[arFrNormalize($k)] = $v; }
    $d = $nd;
    return $d;
}

/** قاموس العائلات (مفاتيح مُوحّدة، بلا «ال» التعريف غالباً). */
function arFrLastDict() {
    static $d = null;
    if ($d !== null) return $d;
    $d = [
        'حليحل'=>'Hleihel','طنوس'=>'Tannous','الخوري'=>'Khoury','خوري'=>'Khoury','منصور'=>'Mansour',
        'الحاج'=>'El Hage','حاج'=>'Hage','ديب'=>'Deeb','خليل'=>'Khalil','عيد'=>'Eid',
        'عبود'=>'Abboud','الحداد'=>'Haddad','حداد'=>'Haddad','الحايك'=>'Hayek','حايك'=>'Hayek',
        'مرعي'=>'Merhy','داغر'=>'Dagher','الياس'=>'Élias','نجم'=>'Nejm','فرح'=>'Farah',
        'صليبا'=>'Sleiba','عون'=>'Aoun','كرم'=>'Karam','جرجس'=>'Gerges','الاسمر'=>'Asmar',
        'اسمر'=>'Asmar','ايوب'=>'Ayoub','شمعون'=>'Chamoun','نقولا'=>'Nicolas','ناصيف'=>'Nassif',
        'متى'=>'Metta','سابا'=>'Saba','سمعان'=>'Semaan','انطون'=>'Anton','جبور'=>'Jabbour',
        'قسطنطين'=>'Constantin','روكز'=>'Roukoz','ابراهيم'=>'Ibrahim','حنا'=>'Hanna',
        'اسكندر'=>'Iskandar','مخول'=>'Makhoul','فرنسيس'=>'Francis','خلف'=>'Khalaf','يوسف'=>'Youssef',
        'زيدان'=>'Zeidan','مهنا'=>'Mhanna','السيقلي'=>'Sayegh','القزي'=>'Kozhaya','اسعد'=>'Assaad',
        'شلهوب'=>'Chalhoub','الاشقر'=>'Achkar','اشقر'=>'Achkar','العاقوري'=>'Aakoury',
        'الشباب'=>'Chabab','حرب'=>'Harb','موسى'=>'Moussa','زعرور'=>'Zaarour','مراد'=>'Mourad',
        'نصرالله'=>'Nasrallah','نصار'=>'Nassar','نصّار'=>'Nassar','متري'=>'Mitri','الناشف'=>'Nachef',
        'سليم'=>'Slim','الحجار'=>'Hajjar','حجار'=>'Hajjar','كساب'=>'Kassab','بولس'=>'Boulos',
        'حاتم'=>'Hatem','الحلو'=>'Helou','حلو'=>'Helou','فرحات'=>'Farhat','دندن'=>'Dandan',
        'فاخوري'=>'Fakhoury','معلوف'=>'Maalouf','برباري'=>'Barbari','يونس'=>'Younes','الكبي'=>'Kebbe',
        'حنون'=>'Hannoun','فارس'=>'Fares','مشنتف'=>'Mechantaf','الصباغ'=>'Sabbagh','صباغ'=>'Sabbagh',
        'سلوم'=>'Salloum','عازار'=>'Azar','بو خليل'=>'Abi Khalil','ابو خليل'=>'Abou Khalil',
        'بو نهرا'=>'Bou Nehra','بونهرا'=>'Bou Nehra','بشارة'=>'Bechara','بشاره'=>'Bechara',
        'العموري'=>'Ammoury','عموري'=>'Ammoury','عجاقة'=>'Ajaka','عجاقه'=>'Ajaka','ناصر'=>'Nasser',
        'خيرالله'=>'Khairallah','الكركي'=>'Karaki','كركي'=>'Karaki','قمير'=>'Kmeir','بركه'=>'Barakeh',
        'بركة'=>'Barakeh','عاد'=>'Aad','ابي طايع'=>'Abi Tayeh','بو طايع'=>'Bou Tayeh',
        'عبدالله'=>'Abdallah','عبد الله'=>'Abdallah','الحلاني'=>'Hellani','رحمة'=>'Rahmé','رحمه'=>'Rahmé',
        'صعب'=>'Saab','معوض'=>'Maouad','عقل'=>'Akl','شاهين'=>'Chahine','رزق'=>'Rizk',
        'زخيا'=>'Zakhia','ضو'=>'Daou','الدويهي'=>'Douaihy','كرباج'=>'Karbaj','شدياق'=>'Chidiac',
        'الراعي'=>'Raï','مارون'=>'Maroun','نادر'=>'Nader','وهبه'=>'Wehbé','وهبة'=>'Wehbé',
        'جعجع'=>'Geagea','فرنجية'=>'Frangié','فرنجيه'=>'Frangié','طوق'=>'Tok','عبدالنور'=>'Abdelnour',
        'الزغبي'=>'Zoghbi','زغبي'=>'Zoghbi','الترك'=>'Turk','سعد'=>'Saad','سعاده'=>'Saadé',
        'سعادة'=>'Saadé','الشدياق'=>'Chidiac','بو عبدو'=>'Bou Abdo','حبيب'=>'Habib','شعيا'=>'Chaaya',
        'البستاني'=>'Boustany','بستاني'=>'Boustany','الصايغ'=>'Sayegh','صايغ'=>'Sayegh','الصايغ'=>'Sayegh',
        'القس'=>'Kass','سركيس'=>'Sarkis','مبارك'=>'Moubarak','الدبس'=>'Debs','دبس'=>'Debs',
        'كنعان'=>'Kanaan','زخريا'=>'Zakaria','بو سعيد'=>'Bou Saïd','الهاشم'=>'Hachem','هاشم'=>'Hachem',
        'مطر'=>'Matar','جمال'=>'Jamal','ناكوزي'=>'Nakouzy','معلوف'=>'Maalouf','الدكاش'=>'Dakkache',
        'سميا'=>'Samaha','الفغالي'=>'Feghali','فغالي'=>'Feghali','عطالله'=>'Atallah','شحاده'=>'Chehadé',
        'شحادة'=>'Chehadé','نبهان'=>'Nabhan','عزيز'=>'Aziz','نمر'=>'Nemr','طايع'=>'Tayeh',
        'السكاف'=>'Sakkaf','سكاف'=>'Sakkaf','عاصي'=>'Assi','واكيم'=>'Wakim','باسط'=>'Basset',
        'حرفوش'=>'Harfouche','القائد'=>'Kaed','صادر'=>'Sader','داود'=>'Daoud','نخلة'=>'Nakhlé',
        'نخله'=>'Nakhlé','ابوجريش'=>'Abou Jreich','عيسى'=>'Issa','سليمان'=>'Sleiman','مقصود'=>'Maksoud',
        'صوما'=>'Souma','مساعد'=>'Mossaad','الحوراني'=>'Hourani','حوراني'=>'Hourani','بركات'=>'Barakat',
        'نحاس'=>'Nahas','نوفل'=>'Nawfal','ابي عيد'=>'Abi Eid','صالح'=>'Saleh','الشماعي'=>'Chammaï',
        'جرجي'=>'Gerji','بوزيدان'=>'Bou Zeidan','بو زيدان'=>'Bou Zeidan','يونان'=>'Younan','نهرا'=>'Nehra',
        'حيدر'=>'Haidar','غسطين'=>'Ghostine','الحمصي'=>'Homsi','حمصي'=>'Homsi','غانم'=>'Ghanem',
        'سيدناوي'=>'Sednaoui','الحويك'=>'Howayek','حويك'=>'Howayek','الدرزي'=>'Derzi','برهوم'=>'Barhoum',
        'اندراوس'=>'Andraos','نصر'=>'Nasr','ابوزيد'=>'Abou Zeid','ابو زيد'=>'Abou Zeid','خاطر'=>'Khater',
        'السويدي'=>'Soueidi','زكاك'=>'Zakkak','المر'=>'Murr','ملو'=>'Mallo','قنصل'=>'Konsol',
        'مخايل'=>'Mkhayel','السروع'=>'Sarrouh','الطيار'=>'Tayyar','صافي'=>'Safi','ملحم'=>'Melhem',
        'سيدي'=>'Sidi','ضاهر'=>'Daher','المعماري'=>'Maamari','صابر'=>'Saber','ابوسمرا'=>'Abou Samra',
        'يعقوب'=>'Yaacoub','جرجورة'=>'Jerjoura','جرجوره'=>'Jerjoura','ياغي'=>'Yaghi','مهاوج'=>'Mhawej',
        'شبوع'=>'Chabbouh','الديك'=>'Deek','فياض'=>'Fayyad','الصهيوني'=>'Sahyouni','صعيبي'=>'Soueid',
        'طربيه'=>'Tarabay','طربيه'=>'Tarabay','عطيه'=>'Attieh','عطية'=>'Attieh','الزغبي'=>'Zoghbi',
    ];
    $nd = []; foreach ($d as $k => $v) { $nd[arFrNormalize($k)] = $v; }
    $d = $nd;
    return $d;
}

/** خريطة الحروف للنقل الاحتياطي (تهجئة فرنسية تقريبية). */
function arFrCharMap() {
    return [
        'ا'=>'a','ب'=>'b','ت'=>'t','ث'=>'s','ج'=>'j','ح'=>'h','خ'=>'kh','د'=>'d','ذ'=>'z',
        'ر'=>'r','ز'=>'z','س'=>'s','ش'=>'ch','ص'=>'s','ض'=>'d','ط'=>'t','ظ'=>'z','ع'=>'a',
        'غ'=>'gh','ف'=>'f','ق'=>'k','ك'=>'k','ل'=>'l','م'=>'m','ن'=>'n','ه'=>'h',
        'و'=>'ou','ي'=>'i','ء'=>'',
    ];
}

/** نقل حروف كلمة واحدة احتياطياً مع جعل أول حرف كبيراً. */
function arFrTranslitWord($w) {
    $w = arFrNormalize($w);
    if ($w === '') return '';
    // التاء المربوطة بالنهاية → a
    $w = preg_replace('/ة$/u', 'ه', $w);
    $map = arFrCharMap();
    $out = '';
    $chars = preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') { $out .= ' '; continue; }
        // الهاء بالنهاية غالباً تُلفظ a للمؤنّث
        if ($ch === 'ه' && $i === $n - 1) { $out .= 'a'; continue; }
        $out .= $map[$ch] ?? $ch;
    }
    // تنظيف: تكرار حرفين متطابقين متتاليين بصري، وأول حرف كبير لكل كلمة
    $out = preg_replace('/\s+/', ' ', trim($out));
    $out = preg_replace_callback('/(^|[ -])(\p{L})/u', function ($m) { return $m[1] . mb_strtoupper($m[2], 'UTF-8'); }, $out);
    return $out;
}

/**
 * الترجمة الرئيسية: اسم عربي → فرنسي.
 * @param string $ar الاسم بالعربي
 * @param string $type 'first' أو 'last'
 */
function arNameToFr($ar, $type = 'last') {
    $norm = arFrNormalize($ar);
    if ($norm === '' || $norm === '.') return '';
    $dict = ($type === 'first') ? arFrFirstDict() : arFrLastDict();

    // 1) مطابقة الاسم الكامل بالقاموس
    if (isset($dict[$norm])) return $dict[$norm];

    // 2) للعائلات: جرّب بعد إزالة «ال» التعريف
    if ($type === 'last' && mb_substr($norm, 0, 2, 'UTF-8') === 'ال') {
        $core = mb_substr($norm, 2, null, 'UTF-8');
        if (isset($dict[$core])) return $dict[$core];
    }

    // 3) مطابقة كل كلمة على حدة (للأسماء المركّبة)، مع معالجة البادئات
    $words = explode(' ', $norm);
    $parts = [];
    foreach ($words as $w) {
        if ($w === '') continue;
        // بادئة بو / ابو / ابي
        if (in_array($w, ['بو'])) { $parts[] = 'Bou'; continue; }
        if (in_array($w, ['ابو'])) { $parts[] = 'Abou'; continue; }
        if (in_array($w, ['ابي'])) { $parts[] = 'Abi'; continue; }
        if (in_array($w, ['عبد'])) { $parts[] = 'Abdel'; continue; }
        // «ال» التعريف داخل كلمة
        $lookup = $w;
        if ($type === 'last' && mb_substr($w, 0, 2, 'UTF-8') === 'ال' && mb_strlen($w, 'UTF-8') > 3) {
            $lookup = mb_substr($w, 2, null, 'UTF-8');
        }
        if (isset($dict[$lookup])) { $parts[] = $dict[$lookup]; continue; }
        if (isset($dict[$w])) { $parts[] = $dict[$w]; continue; }
        $parts[] = arFrTranslitWord($lookup);
    }
    return trim(implode(' ', array_filter($parts)));
}
