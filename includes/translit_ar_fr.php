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

/**
 * 🗺️ قاموس أسماء المناطق والأماكن اللبنانية → التهجئة اللاتينية المتعارف عليها
 * («اسم المكان بدك تكتبو مظبوط بالفرنسي: الحدث = Hadath لا Hds» — بطلب المستخدم 2026-08-21).
 * يغطي كل مقاطع عناوين المدارس + المناطق المتكررة بعناوين الموظفين ومحالّ ولادتهم.
 * المفاتيح تُطبَّع (arFrNormalize + ة→ه) فتغطي «مغدوشة/مغدوشه» بمدخل واحد.
 */
function arPlaceDict() {
    static $d = null;
    if ($d !== null) return $d;
    $raw = [
        // محافظات وأقضية
        'الجنوب'=>'Liban-Sud','لبنان الجنوبي'=>'Liban-Sud','الشمال'=>'Liban-Nord','جبل لبنان'=>'Mont-Liban',
        'البقاع'=>'Békaa','البقاع الغربي'=>'Békaa-Ouest','بيروت'=>'Beyrouth','النبطية'=>'Nabatiyeh',
        'عكار'=>'Akkar','بعلبك الهرمل'=>'Baalbeck-Hermel','صيدا'=>'Saida','جزين'=>'Jezzine','الشوف'=>'Chouf',
        'بعبدا'=>'Baabda','المتن'=>'Metn','كسروان'=>'Kesrouan','جبيل'=>'Jbeil','عاليه'=>'Aley','زحلة'=>'Zahlé',
        'بنت جبيل'=>'Bint Jbeil','مرجعيون'=>'Marjeyoun','صور'=>'Sour','البترون'=>'Batroun','بعلبك'=>'Baalbeck',
        'راشيا'=>'Rachaiya','حاصبيا'=>'Hasbaya','طرابلس'=>'Tripoli',
        // مناطق المدارس وضواحيها
        'الحدث'=>'Hadath','تلال الحدث'=>'Tilal El Hadath','المنصورية'=>'Mansourieh','البلاطة'=>'Blata',
        'عبرا'=>'Abra','عبرا الجديدة'=>'Abra El Jdideh','عبرا الجديدة مكسيموس'=>'Abra El Jdideh',
        'مغدوشة'=>'Maghdouché','جون'=>'Joun','جون الدير'=>'Joun','المحتقرة'=>'Mohtakra','الفرزل'=>'Ferzol',
        'الفرزل التحتا'=>'Ferzol El Tahta','الفرزل الفوقا'=>'Ferzol El Faouqa','ابلح'=>'Ablah','كسارة'=>'Ksara',
        'تلال كسارة'=>'Tilal Ksara','يارون'=>'Yaroun','جعيتا'=>'Jeita','الدامور'=>'Damour','مكسيموس'=>'Maximos',
        'الراهبات المخلصيات'=>'Sœurs Salvatoriennes','سامي الصلح'=>'Sami El Solh','مستوصف'=>'Dispensaire',
        'الدير'=>'El Deir','دير'=>'Deir','المدرسة'=>"l'École",'البلدية'=>'La Municipalité','الديشونية'=>'Dichounieh',
        // بلدات ومناطق شائعة بعناوين الموظفين
        'القرية'=>'Qraiyeh','عين الدلب'=>'Ain El Delb','الهلالية'=>'Hlaliyeh','الرميلة'=>'Rmeileh',
        'مجدليون'=>'Majdelyoun','المية ومية'=>'Miyé ou Miyé','حوش الامراء'=>'Housh El Oumara',
        'جنسنايا'=>'Jensnaya','المعلقة'=>'Maallaqa','درب السيم'=>'Darb El Sim','علمان'=>'Aalman',
        'جديتا'=>'Jdita','انان'=>'Anan','عين المير'=>'Ain El Mir','الزهراني'=>'Zahrani','بسابا'=>'Bsaba',
        'كفرشيما'=>'Kfarchima','نيحا'=>'Niha','الحسانية'=>'Hassaniyeh','بيت مري'=>'Beit Mery','روم'=>'Roum',
        'مراح الحباس'=>'Mrah El Habbas','وادي بعنقودين'=>'Wadi Baanqoudine','بيصور'=>'Baysour',
        'سن الفيل'=>'Sin El Fil','شواليق'=>'Chwalik','قيتولي'=>'Qaitouli','وادي شحرور'=>'Wadi Chahrour',
        'الحازمية'=>'Hazmieh','الصالحية'=>'Salhiyeh','بدادون'=>'Bdadoun','رياق'=>'Rayak',
        'رياق الفوقا'=>'Rayak El Faouqa','عين الرمانة'=>'Ain El Remmaneh','كفرفالوس'=>'Kfarfalous',
        'الدكوانة'=>'Dekwaneh','تعلبايا'=>'Taalabaya','حارة صيدا'=>'Haret Saida','لبعا'=>'Lebaa','لبعه'=>'Lebaa',
        'وادي الليمون'=>'Wadi El Laymoun','العدوسية'=>'Aadousiyeh','الغازية'=>'Ghaziyeh','الفياضية'=>'Fayadiyeh',
        'برتي'=>'Berti','حوش حالا'=>'Housh Hala','شحيم'=>'Chhim','البرامية'=>'Bramiyeh','بليبل'=>'Bleibel',
        'تربل'=>'Terbol','عين سعادة'=>'Ain Saadeh','برج حمود'=>'Bourj Hammoud','سبنيه'=>'Sebnay','الزلقا'=>'Zalka',
        'البوشرية'=>'Baouchriyeh','الحجة'=>'Hajjeh','الراسية'=>'Rassiyeh','الراسية الفوقا'=>'Rassiyeh El Faouqa',
        'الزعرورية'=>'Zaarouriyeh','الميدان'=>'Maydan','قب الياس'=>'Qab Elias','كفرجرة'=>'Kfarjarra',
        'كفريا'=>'Kefraya','الكنيسة'=>'El Knisseh','الجديدة'=>'Jdeideh','السيدة'=>'El Saydeh',
        'حي السيدة'=>'Hay El Saydeh','الكحالة'=>'Kahaleh','جل الديب'=>'Jal El Dib','رميش'=>'Rmeich',
        'صربا'=>'Sarba','عين ابل'=>'Ain Ebel','فرن الشباك'=>'Furn El Chebbak','الشارع العام'=>'Rue Principale',
        'الطريق العام'=>'Route Principale','العام'=>'Rue Principale','بطشاي'=>'Btechay','شرحبيل'=>'Charhabil',
        'قاع الريم'=>'Qaa El Rim','كترمايا'=>'Ketermaya','وادي جزين'=>'Wadi Jezzine','الدوير'=>'Dweir',
        'حي الدوير'=>'Hay El Dweir','مار الياس'=>'Mar Elias','مارالياس'=>'Mar Elias','الشياح'=>'Chiyah',
        'الفنار'=>'Fanar','المطلة'=>'Mtolleh','المعمرية'=>'Maamariyeh','انطلياس'=>'Antelias','بجه'=>'Bejjeh',
        'برجا'=>'Barja','بسكنتا'=>'Baskinta','بصاليم'=>'Bsalim','بيت الشعار'=>'Beit Chaar','حلب'=>'Alep',
        'عازور'=>'Aazour','عين عرب'=>'Ain Aarab','كفرحونة'=>'Kfarhouna','نيو روضة'=>'New Rawda',
        'الروضة'=>'Rawda','القاطع'=>'El Qatea','دير الاحمر'=>'Deir El Ahmar','راس بعلبك'=>'Ras Baalbeck',
        'ربله'=>'Ribleh','جديدة مرجعيون'=>'Jdeidet Marjeyoun','الجية'=>'Jiyeh','الكرك'=>'Karak',
        'المغيرية'=>'Mghayriyeh','النجارية'=>'Najjariyeh','بيت شباب'=>'Beit Chabab','تمنين التحتا'=>'Temnine El Tahta',
        'ذوق مصبح'=>'Zouk Mosbeh','زوق مصبح'=>'Zouk Mosbeh','زوق مكايل'=>'Zouk Mikael','صغبين'=>'Saghbine',
        'صليما'=>'Salima','طنبوريت'=>'Tanbourit','عقتانيت'=>'Aaqtanit','قتالي'=>'Qtali','كفرزبد'=>'Kfarzabad',
        'الانطونية'=>'Antoniyeh','البيادر'=>'Bayader','الثكنة'=>'El Thakneh','الخندق'=>'Khandaq',
        'الساحة'=>'El Saha','الشاغور'=>'Chaghour','الفوار'=>'Fawar','بر الياس'=>'Bar Elias',
        'حارة البطم'=>'Haret El Batm','الحوش'=>'El Housh','القصير'=>'Qousseir','رشميا'=>'Rechmaya',
        'مشغرة'=>'Machghara','الاشرفية'=>'Achrafieh','الاشرفية الفوقا'=>'Achrafieh El Faouqa','الحمرا'=>'Hamra',
        'الحرش'=>'El Horch','الصرفند'=>'Sarafand','الشويفات'=>'Choueifat','الضبية'=>'Dbayeh','الدورة'=>'Dora',
        'الدكرمان'=>'Dekerman','بسري'=>'Bisri','الاسكندرية'=>'Alexandrie','السعودية'=>'Arabie Saoudite',
        'قطر'=>'Qatar','الدوحه'=>'Doha','ابيدجان'=>'Abidjan',
        // كلمات عناوين عامة
        'حي'=>'Hay','حارة'=>'Haret','شارع'=>'Rue','طريق'=>'Route','طابق'=>'Étage','بناية'=>'Imm.',
        'عمارة'=>'Imm.','التحتا'=>'El Tahta','الفوقا'=>'El Faouqa','التحتاني'=>'El Tahtani','الفوقاني'=>'El Faouqani',
    ];
    $d = [];
    foreach ($raw as $k => $v) { $d[str_replace('ة', 'ه', arFrNormalize($k))] = $v; }
    return $d;
}

/**
 * 📚 قاموس المواد الدراسية (2026-08-21 «بدنا نكتب لمادة اللغة الإنكليزية»): المادة مخزّنة
 * بملف الأستاذ بأي لغة («Anglais»/«رياضيات»...) — بالإفادة تُكتب بلغة الوثيقة نفسها:
 * عربي = «اللغة الإنكليزية»، فرنسي = «Anglais»، إنكليزي = «English». غير المعروف يبقى كما هو.
 */
function subjectMap() {
    static $m = null;
    if ($m !== null) return $m;
    $rows = [ // [مرادفات مطبَّعة] => [ar, fr, en]
        [['عربي','عربيه','arabe','arabic'], 'اللغة العربية', 'Arabe', 'Arabic'],
        [['فرنسي','فرنسيه','francais','french'], 'اللغة الفرنسية', 'Français', 'French'],
        [['انكليزي','انكليزيه','انجليزي','انجليزيه','anglais','english'], 'اللغة الإنكليزية', 'Anglais', 'English'],
        [['رياضيات','حساب','math','maths','mathematique','mathematiques','mathematics'], 'الرياضيات', 'Mathématiques', 'Mathematics'],
        [['علوم','science','sciences'], 'العلوم', 'Sciences', 'Science'],
        [['فيزياء','physique','physics'], 'الفيزياء', 'Physique', 'Physics'],
        [['كيمياء','chimie','chemistry'], 'الكيمياء', 'Chimie', 'Chemistry'],
        [['احياء','بيولوجيا','علوم الحياه','biologie','biology','svt'], 'علوم الحياة', 'Biologie', 'Biology'],
        [['تاريخ','histoire','history'], 'التاريخ', 'Histoire', 'History'],
        [['جغرافيا','جغرافيه','geographie','geography'], 'الجغرافيا', 'Géographie', 'Geography'],
        [['اجتماع','sociologie','sociology'], 'علم الاجتماع', 'Sociologie', 'Sociology'],
        [['اقتصاد','economie','economics'], 'الاقتصاد', 'Économie', 'Economics'],
        [['اجتماع واقتصاد'], 'الاجتماع والاقتصاد', 'Sociologie et économie', 'Sociology and Economics'],
        [['فلسفه','philosophie','philosophy'], 'الفلسفة', 'Philosophie', 'Philosophy'],
        [['تربيه','تربيه مدنيه','تربيه وطنيه','civique','education civique','civics'], 'التربية المدنية', 'Éducation civique', 'Civics'],
        [['تعليم مسيحي','تربيه مسيحيه','catechese','religion','دين'], 'التعليم المسيحي', 'Catéchèse', 'Religious Education'],
        [['معلوماتيه','informatique','computer','computer science'], 'المعلوماتية', 'Informatique', 'Computer Science'],
        [['رياضه','رياضه بدنيه','sport','eps','education physique'], 'التربية الرياضية', 'Éducation physique', 'Physical Education'],
        [['موسيقى','musique','music'], 'الموسيقى', 'Musique', 'Music'],
        [['رسم','فنون','dessin','art','arts','arts plastiques'], 'الرسم والفنون', 'Arts plastiques', 'Arts'],
        [['مسرح','theatre','drama'], 'المسرح', 'Théâtre', 'Drama'],
        [['رياضيات وعلوم','maths sciences','math sciences'], 'الرياضيات والعلوم', 'Maths et sciences', 'Maths and Science'],
    ];
    $m = [];
    foreach ($rows as $r) { foreach ($r[0] as $al) $m[$al] = [$r[1], $r[2], $r[3]]; }
    return $m;
}

/** تطبيع اسم مادة للمطابقة: أحرف صغيرة، بلا أكسنت فرنسي، بلا «اللغة/لغة/مادة/ال/langue». */
function subjectNormalize($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ç'=>'c','î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ù'=>'u']);
    $s = str_replace('ة', 'ه', arFrNormalize($s));
    foreach (['اللغه ', 'لغه ', 'ماده ', 'langue ', 'the '] as $p) {
        if (mb_strpos($s, $p) === 0) $s = mb_substr($s, mb_strlen($p, 'UTF-8'), null, 'UTF-8');
    }
    $s = preg_replace('/^ال(?=\S)/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** المادة بلغة الوثيقة ('ar'|'fr'|'en') — يدعم موادّ متعددة مفصولة بـ , ، / + */
function subjectToLang($raw, $lang) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $i = ['ar' => 0, 'fr' => 1, 'en' => 2][$lang] ?? 0;
    $map = subjectMap();
    $one = function ($part) use ($map, $i) {
        $part = trim($part);
        if ($part === '') return '';
        $k = subjectNormalize($part);
        return isset($map[$k]) ? $map[$k][$i] : $part; // غير المعروف يبقى كما كُتب
    };
    // المادة كلها دفعة واحدة (تغطي «اجتماع واقتصاد») ثم التقسيم على الفواصل
    $k = subjectNormalize($raw);
    if (isset($map[$k])) return $map[$k][$i];
    $parts = array_filter(array_map($one, preg_split('/\s*[,،;\/+&]\s*/u', $raw)), 'strlen');
    return implode($lang === 'ar' ? ' و' : ', ', $parts);
}

/**
 * ترجمة اسم مكان/عنوان عربي إلى التهجئة اللاتينية الصحيحة (للإفادات الفرنسية والإنكليزية):
 * مطابقة المقطع كاملاً بالقاموس، ثم أطول عبارة (حتى 3 كلمات)، ثم كلمة كلمة (مع إسقاط «ال»)،
 * والاحتياط نقل الحروف. المقاطع تُفصل على - / – / ، وتُعاد موصولة بـ« - ».
 */
function arPlaceToFr($ar) {
    $ar = trim((string)$ar);
    if ($ar === '') return '';
    if (preg_match('/^[\x00-\x7F]+$/', $ar)) return $ar; // لاتيني أصلاً — كما هو
    $dict = arPlaceDict();
    $normP = function ($s) { return str_replace('ة', 'ه', arFrNormalize($s)); };
    $strip = function ($w) { return (mb_substr($w, 0, 2, 'UTF-8') === 'ال' && mb_strlen($w, 'UTF-8') > 3) ? mb_substr($w, 2, null, 'UTF-8') : $w; };
    $lookup = function ($seg) use ($dict, $strip) {
        if ($seg === '') return '';
        if (preg_match('/^[\x00-\x7F]+$/', $seg)) return $seg; // مقطع لاتيني/رقمي
        if (isset($dict[$seg])) return $dict[$seg];
        $core = $strip($seg);
        return isset($dict[$core]) ? $dict[$core] : null;
    };
    $outSegs = [];
    foreach (preg_split('/\s*[-–,،\/]\s*/u', $normP($ar)) as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $hit = $lookup($seg);
        if ($hit !== null) { if ($hit !== '') $outSegs[] = $hit; continue; }
        $words = array_values(array_filter(explode(' ', $seg), 'strlen'));
        $parts = [];
        for ($i = 0; $i < count($words); $i++) {
            for ($len = min(3, count($words) - $i); $len >= 1; $len--) {
                $h = $lookup(implode(' ', array_slice($words, $i, $len)));
                if ($h !== null) { $parts[] = $h; $i += $len - 1; continue 2; }
            }
            $parts[] = arFrTranslitWord($strip($words[$i]));
        }
        $outSegs[] = trim(implode(' ', array_filter($parts, 'strlen')));
    }
    return implode(' - ', array_filter($outSegs, 'strlen'));
}
