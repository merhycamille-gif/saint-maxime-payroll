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
    $nd = []; foreach (array_merge($d, arFrNamesExtra('first')) as $k => $v) { $nd[arFrNormalize($k)] = $v; }
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
    $nd = []; foreach (array_merge($d, arFrNamesExtra('last')) as $k => $v) { $nd[arFrNormalize($k)] = $v; }
    $d = $nd;
    return $d;
}

/**
 * 🔤 (2026-10-01 «الترجمة من العربي إلى الفرنسي تكون مظبوطة ما تضحك الأساتذة علينا — وخاصة أسماء الأساتذة باللغة الأجنبية»):
 * تكملة القاموسين بكل اسم/شهرة موجودة فعلاً بالبرنامج وكان النقل الاحتياطي يطلّعها بلا أحرف علّة (Hsn, Jmil, Brnar, Nmour…).
 * لها الأولوية على القاموس القديم (تصحّح أيضاً مدخلاته الغلط: السيقلي/القزي/صعيبي). التهجئة لبنانية متعارف عليها،
 * وحيث كتب المستخدم بيده تهجئة لاسم (Imane, Mirvate, Valessa…) اعتُمدت تهجئته.
 */
function arFrNamesExtra(string $type): array {
    if ($type === 'first') return [
        'ريمون'=>'Raymond','رمون'=>'Raymond','طانوس'=>'Tannous','سعيد'=>'Said','حسن'=>'Hassan','فواد'=>'Fouad','فؤاد'=>'Fouad',
        'جميل'=>'Jamil','نبيه'=>'Nabih','نجيب'=>'Najib','احمد'=>'Ahmad','عساف'=>'Assaf','توفيق'=>'Toufic','دنيا'=>'Dounia',
        'علاء'=>'Alaa','اديب'=>'Adib','لبيب'=>'Labib','جريس'=>'Jreis','وديع'=>'Wadih','جانيت'=>'Jeannette','رجا'=>'Raja',
        'رجاء'=>'Rajaa','جاك'=>'Jacques','مطانيوس'=>'Mtanios','رشيد'=>'Rachid','جنات'=>'Jannat','مرتا'=>'Martha',
        'روجيه'=>'Roger','شنتال'=>'Chantal','السي'=>'Elsy','وفيق'=>'Wafic','نجمة'=>'Najmeh','نجمه'=>'Najmeh','تراز'=>'Thérèse',
        'جومانا'=>'Joumana','جومانه'=>'Joumana','دياب'=>'Diab','ايلان'=>'Hélène','خالد'=>'Khaled',
        'موريس'=>'Maurice','ادمون'=>'Edmond','ميرال'=>'Miral','قيصر'=>'Kaissar','مصطفى'=>'Moustafa','هبه'=>'Hiba','هبة'=>'Hiba',
        'عايده'=>'Aida','عايدة'=>'Aida','فوزي'=>'Fawzi','ناديا'=>'Nadia','برلا'=>'Perla','بيرلا'=>'Perla','منوال'=>'Manuel',
        'كريستين'=>'Christine','ربى'=>'Rouba','ويلده'=>'Wilda','انجي'=>'Angie','يولا'=>'Yola','تغريد'=>'Taghrid',
        'اميره'=>'Amira','اميرة'=>'Amira','قزحيا'=>'Kozhaya','نورما'=>'Norma','جنى'=>'Jana','مروان'=>'Marwan','كريس'=>'Chris',
        'اسبر'=>'Esper','شبل'=>'Chebel','جورجيو'=>'Georgio','دوللي'=>'Dolly','دولي'=>'Dolly','اوجين'=>'Eugène','مارينا'=>'Marina',
        'باسمة'=>'Bassima','باسمه'=>'Bassima','برنادات'=>'Bernadette','كريم'=>'Karim','جيزيل'=>'Gisèle','جيزال'=>'Gisèle',
        'عاطف'=>'Atef','لبنى'=>'Loubna','فرنسوا'=>'François','قاسم'=>'Kassem','نهاية'=>'Nihaya','حمود'=>'Hammoud',
        'الماس'=>'Almaz','فرج'=>'Faraj','فاهمة'=>'Fahima','فاهمه'=>'Fahima','نوها'=>'Nouha','نهى'=>'Nouha','صبا'=>'Saba',
        'بياره'=>'Pierra','منجد'=>'Mounjed','سولا'=>'Sola','ليليان'=>'Liliane','شفيق'=>'Chafic','سعدى'=>'Saada',
        'صموييل'=>'Samuel','صموئيل'=>'Samuel','سميح'=>'Samih','جورجينا'=>'Georgina','صولنج'=>'Solange','ميريللا'=>'Mirella',
        'ميريلا'=>'Mirella','عادل'=>'Adel','مالده'=>'Malda','مالدة'=>'Malda','نيفين'=>'Nivine','اميلي'=>'Émilie',
        'اليانور'=>'Éléonore','شكرالله'=>'Chukrallah','جهان'=>'Jihane','روبيكا'=>'Rebecca','ربيكا'=>'Rebecca','ايليز'=>'Élise',
        'غيتا'=>'Ghita','نظله'=>'Nazly','كارن'=>'Karen','جلنار'=>'Joulnar','مرفت'=>'Mirvate','ميرفت'=>'Mirvate','عثمان'=>'Osman',
        'رحيله'=>'Rahil','كريستيا'=>'Christia','نجيبة'=>'Najibeh','نجيبه'=>'Najibeh','شوقي'=>'Chawki','حسام'=>'Houssam',
        'تامارا'=>'Tamara','سلافا'=>'Sulafa','تمام'=>'Tamam','كريتا'=>'Greta','اسمهان'=>'Asmahane','شيرا'=>'Chira',
        'سيدة'=>'Sayde','سيمونا'=>'Simona','ريفا'=>'Riva','لوري'=>'Laury','شاديا'=>'Chadia','صفيناز'=>'Safinaz','اماني'=>'Amani',
        'ماريانا'=>'Mariana','رينيه'=>'René','نديم'=>'Nadim','لميس'=>'Lamisse','لويزا'=>'Louisa','هنري'=>'Henri','نهلا'=>'Nahla',
        'نهله'=>'Nahla','جوزاف'=>'Joseph','مريام'=>'Myriam','فهد'=>'Fahd','احلام'=>'Ahlam','حياة'=>'Hayate','حياه'=>'Hayate',
        'بلال'=>'Bilal','علي'=>'Ali','سلمى'=>'Salma','كريستل'=>'Christelle','عناية'=>'Inaya','عنايه'=>'Inaya','عمر'=>'Omar',
        'بسكال'=>'Pascale','ميلو'=>'Milo','فاليسا'=>'Valessa','ديالا'=>'Diala','لوريس'=>'Loris','غانا'=>'Ghana','ليندا'=>'Linda',
        'مادو'=>'Mado','سميره'=>'Samira','سميرة'=>'Samira','خطار'=>'Khattar','جورجيت'=>'Georgette','ادوار'=>'Édouard',
        'كرستيان'=>'Christian','مخيبر'=>'Mkhayber','فتون'=>'Fatoun','فريال'=>'Férial','رونزا'=>'Ronza','ثريا'=>'Souraya',
        'جاندرك'=>"Jeanne d'Arc",'جندارك'=>"Jeanne d'Arc",'صلاح'=>'Salah','رويده'=>'Rouwayda','رويدة'=>'Rouwayda','جونا'=>'Jona',
        'تيا'=>'Tia','ماريان'=>'Marianne','ايمي'=>'Aimy','انجيلا'=>'Angela','روزالة'=>'Rozala','نيكول'=>'Nicole','بهية'=>'Bahia',
        'بهيه'=>'Bahia','ادكار'=>'Edgard','ميليسا'=>'Melissa','روان'=>'Rawan','اناليسا'=>'Annalisa','اتيان'=>'Étienne',
        'ايليو'=>'Elio','اليو'=>'Elio','جيمي'=>'Jimmy','اندي'=>'Andy','مارسيلا'=>'Marcella','رولى'=>'Roula','ايمان'=>'Imane',
        'انطوني'=>'Anthony','جهاد'=>'Jihad','ميلي'=>'Mily','غاييل'=>'Gaëlle','نيكولا'=>'Nicolas','امين'=>'Amine',
        'جنيفر'=>'Jennifer','ادي'=>'Eddy','زهير'=>'Zouher','اوهيلا'=>'Ohayla','هدية'=>'Hadiya','هديه'=>'Hadiya','رفيق'=>'Rafic',
        'ريمي'=>'Rémie','نعمة'=>'Nehmé','نعمه'=>'Nehmé','رفيقة'=>'Rafica','رفيقه'=>'Rafica','فريد'=>'Farid','سالم'=>'Salem',
        'طلعت'=>'Talaat','ميرلا'=>'Mirla','مارتين'=>'Martine','ايلسيا'=>'Elsia','باميلا'=>'Paméla','سيلفا'=>'Silva',
        'شكيب'=>'Chakib','كلاديس'=>'Gladys','فرادي'=>'Freddy','دورلين'=>'Dorline','لويس'=>'Louis','باتريسيا'=>'Patricia',
        'مفيد'=>'Moufid','ليزا'=>'Lisa','زويا'=>'Zoya','روزالي'=>'Rosalie','بيا'=>'Pia','نسيب'=>'Nassib','كريستوف'=>'Christophe',
        'رندلا'=>'Rindala','كلاريتا'=>'Clarita','تينا'=>'Tina','برنار'=>'Bernard','ليا'=>'Léa','غوا'=>'Ghiwa','ران'=>'Reine',
        'اوغستان'=>'Augustin','اوديل'=>'Odile','اغابي'=>'Agapé','اسبرانس'=>'Espérance','الاخت'=>'Sœur','لاخت'=>'Sœur',
        'الام'=>'Mère','الاب'=>'Père','رزق الله'=>'Rizkallah','رز الله'=>'Rizkallah','سعد الدين'=>'Saadeddine',
        'عبد القادر'=>'Abdel Kader','عبد الله'=>'Abdallah','عبدالله'=>'Abdallah','شربل'=>'Charbel',
        'بطرس'=>'Boutros','نخلي'=>'Nakhlé','نخله'=>'Nakhlé','نخلة'=>'Nakhlé','عبدو'=>'Abdo','غرامي'=>'Gharami','جمال'=>'Jamal',
        'فارس'=>'Fares','سليم'=>'Salim','علاء الدين'=>'Alaeddine','خير'=>'Kheir','ماهر'=>'Maher','سيد'=>'Sayed',
    ];
    return [
        'وازن'=>'Wazen','روفايل'=>'Roufael','بصيبص'=>'Bsaibes','اسماعيل'=>'Ismail','قزحيا'=>'Kozhaya','ابوضاهر'=>'Abou Daher',
        'ابو ضاهر'=>'Abou Daher','غدار'=>'Ghaddar','غطاس'=>'Ghattas','شهدان'=>'Chahdan','السيد'=>'El Sayed','الاترم'=>'Atram',
        'الاثرم'=>'Atram','بدر'=>'Bader','ابورجيلي'=>'Abou Rjeily','قرعه'=>'Karaa','بو نافع'=>'Bou Nafeh','كامل'=>'Kamel',
        'القارح'=>'El Kareh','غنيمه'=>'Ghanimeh','غنيمة'=>'Ghanimeh','ريشا'=>'Richa','عطاالله'=>'Atallah','عطا الله'=>'Atallah',
        'ابوعزيز'=>'Abou Aziz','رزق الله'=>'Rizkallah','القطار'=>'Kattar','الحريري'=>'Hariri','مفرج'=>'Mfarrej',
        'حبقوق'=>'Habakouk','عبد اللطيف'=>'Abdel Latif','الكبش'=>'El Kabsh','شمس الدين'=>'Chamseddine','خالد'=>'Khaled',
        'العجيل'=>'Oujeil','سفر'=>'Safar','الشكر'=>'El Chakar','ربابي'=>'Rababy','ابوزغيب'=>'Abou Zgheib','فزع'=>'Fazaa',
        'ابوزيدان'=>'Abou Zeidan','رحال'=>'Rahhal','الجوني'=>'Jouni','عاقوري'=>'Aakoury','بوسابا'=>'Bou Saba',
        'علي'=>'Ali','العلم'=>'El Alam','نبها'=>'Nabha','مرقباوي'=>'Markabawi','سلامه'=>'Salameh','سلامة'=>'Salameh',
        'شيخو'=>'Chikho','معمو'=>'Maamo','فلفلي'=>'Flefly','الشامية'=>'Chamieh','بوسمعان'=>'Bou Semaan','مزهر'=>'Mezher',
        'عبيد'=>'Obeid','بدرا'=>'Badra','زعزع'=>'Zaazaa','اسطفان'=>'Estephan','ابونادر'=>'Abou Nader','ابو نادر'=>'Abou Nader',
        'حلمي'=>'Helmi','غيث'=>'Ghaith','جزاع'=>'Jazaa','برهاني'=>'Berhani','قيقانو'=>'Kikano','المندلق'=>'Mondalek',
        'شليطا'=>'Chlita','قسطة'=>'Kosta','قسطه'=>'Kosta','بعقليني'=>'Baaklini','الدردغاني'=>'Dardaghani','دمج'=>'Damaj',
        'الزين'=>'El Zein','الشختورة'=>'Chakhtoura','الشختوره'=>'Chakhtoura','مارتينوس'=>'Martinos','المغربي'=>'Maghrabi',
        'كنهوش'=>'Kanhouch','حليم'=>'Halim','المصري'=>'Masri','جمعه'=>'Jomaa','جمعة'=>'Jomaa',
        'القنواتي'=>'Kanawati','بوطايع'=>'Bou Tayeh','سماك'=>'Sammak','نمور'=>'Nammour','طانوس'=>'Tannous','عمار'=>'Ammar',
        'دايخ'=>'Dayekh','ويس'=>'Wais','فرج'=>'Faraj','العرجا'=>'Arja','فريجي'=>'Freiji','حماده'=>'Hamadeh','حمادة'=>'Hamadeh',
        'سباهيه'=>'Sbahieh','القصير'=>'Kassir','سعد الدين'=>'Saadeddine','ظهران'=>'Zahran','بونصار'=>'Bou Nassar',
        'ابوخاطر'=>'Abou Khater','عساف'=>'Assaf','زياده'=>'Ziadeh','زيادة'=>'Ziadeh','خطار'=>'Khattar','الصغبيني'=>'Saghbini',
        'قبلان'=>'Kabalan','راجحه'=>'Rajha','بوراشد'=>'Bou Rached','جانبين'=>'Janbein','حسونه'=>'Hassouneh','حسّونه'=>'Hassouneh',
        'الهبر'=>'Habr','غريب'=>'Gharib','غريّب'=>'Gharib','الرشيد'=>'Rachid','ابوشعيا'=>'Abou Chaaya','اسبر'=>'Esper',
        'الحلاق'=>'Hallak','شلحاوي'=>'Chalhawi','المدور'=>'Mdawar','اسطنبولي'=>'Istambouly','بجاني'=>'Bejjani',
        'ابويونس'=>'Abou Younes','حدشيتي'=>'Hadchiti','لبوس'=>'Labbous','محفوظ'=>'Mahfouz','فريحة'=>'Freiha','فريحه'=>'Freiha',
        'باصيلا'=>'Bassila','نضور'=>'Naddour','العبد'=>'El Abd','مومجيان'=>'Momjian','حوشان'=>'Hawchan','الغزال'=>'Ghazal',
        'شرو'=>'Charro','تحومي'=>'Tahoumi','باصيل'=>'Bassil','زيتو'=>'Zito','مشعلاني'=>'Machaalani','زغيب'=>'Zgheib',
        'بصبوص'=>'Basbous','ضومط'=>'Doumit','السيقلي'=>'Saikali','القزي'=>'Azzi','صعيبي'=>'Saaiby','داموري'=>'Damouri',
        'المشنتف'=>'Mechantaf','عربيد'=>'Arbid','عتيق'=>'Atik','انطون'=>'Antoun','سميا'=>'Smaya','السعدي'=>'Saadi','سعدي'=>'Saadi',
    ];
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
    // 🔤 (2026-10-01) القاموس الآخر احتياطاً قبل نقل الحروف: شهرة هي اسم علم (شربل، جان) أو اسم أب هو اسم عائلة (كرم، نجم، ملحم)
    $other = ($type === 'first') ? arFrLastDict() : arFrFirstDict();

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
        if (isset($other[$lookup])) { $parts[] = $other[$lookup]; continue; }
        if (isset($other[$w])) { $parts[] = $other[$w]; continue; }
        $parts[] = arFrTranslitWord($lookup);
    }
    return trim(implode(' ', array_filter($parts)));
}

/** كل الصيغ التي كان النقل الآلي القديم يعطيها لاسم (بأحرف صغيرة) — لتمييز «مولَّد آلياً» عن «مكتوب بيد المستخدم». */
function arFrAutoCandidates($ar, $type): array {
    $norm = arFrNormalize($ar);
    if ($norm === '' || $norm === '.') return [];
    $dict = ($type === 'first') ? arFrFirstDict() : arFrLastDict();
    $oldWrong = ['السيقلي' => 'Sayegh', 'سيقلي' => 'Sayegh', 'القزي' => 'Kozhaya', 'قزي' => 'Kozhaya', 'صعيبي' => 'Soueid'];
    $sets = [];
    foreach (explode(' ', $norm) as $w) {
        if ($w === '') continue;
        $fix = ['بو' => 'Bou', 'ابو' => 'Abou', 'ابي' => 'Abi', 'عبد' => 'Abdel'];
        if (isset($fix[$w])) { $sets[] = [$fix[$w]]; continue; }
        $lookup = ($type === 'last' && mb_substr($w, 0, 2, 'UTF-8') === 'ال' && mb_strlen($w, 'UTF-8') > 3) ? mb_substr($w, 2, null, 'UTF-8') : $w;
        $o = [arFrTranslitWord($lookup), arFrTranslitWord($w)];
        foreach ([$lookup, $w] as $k) { if (isset($dict[$k])) $o[] = $dict[$k]; if (isset($oldWrong[$k])) $o[] = $oldWrong[$k]; }
        $sets[] = array_values(array_unique(array_filter($o)));
    }
    $out = [''];
    foreach ($sets as $o) { $n = []; foreach ($out as $p) foreach ($o as $x) { $n[] = trim($p . ' ' . $x); if (count($n) > 128) break 2; } $out = $n; }
    return array_values(array_unique(array_map(fn($x) => mb_strtolower($x, 'UTF-8'), $out)));
}

/**
 * 🩹🔤 شفاء مرّة واحدة (2026-10-01): الأسماء الفرنسية التي **ولّدها البرنامج آلياً** بالنقل القديم (Hsn, Jmil, Brnar, Nmour…) تُستبدل
 * بتهجئة القاموس الصحيحة. ما كتبه المستخدم بيده (أي صيغة لا تطابق مخرجات النقل الآلي) لا يُمسّ أبداً. الخانة الفارغة تُعبّأ.
 * القيم القديمة محفوظة بجدول _names_fr_bk20261001 للاسترجاع.
 */
function healNamesFr20261001(): void {
    $flag = 'names_fr_healed_20261001';
    if (getSetting($flag, '') !== '') return;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS _names_fr_bk20261001 (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, field VARCHAR(30) NOT NULL, old_value VARCHAR(190) NULL, new_value VARCHAR(190) NULL, changed_at DATETIME NOT NULL) DEFAULT CHARSET=utf8mb4");
        $rows = $db->query("SELECT id, first_name_ar, first_name_fr, father_name_ar, father_name_fr, last_name_ar, last_name_fr FROM employees WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
        $ins = $db->prepare("INSERT INTO _names_fr_bk20261001 (employee_id, field, old_value, new_value, changed_at) VALUES (?,?,?,?,NOW())");
        $n = 0; $emps = 0;
        $db->beginTransaction();
        foreach ($rows as $r) {
            $set = [];
            foreach ([['first_name_ar', 'first_name_fr', 'first'], ['father_name_ar', 'father_name_fr', 'first'], ['last_name_ar', 'last_name_fr', 'last']] as [$a, $f, $t]) {
                $ar = trim((string)$r[$a]); $cur = trim((string)$r[$f]);
                if (preg_match('/^[.\-\s]*$/', $cur)) $cur = ''; // «.» = خانة فارغة
                if ($ar === '' || preg_match('/[A-Za-z]/', $ar) || !preg_match('/\p{Arabic}{2,}/u', $ar)) continue;
                $new = arNameToFr($ar, $t);
                if ($new === '' || mb_strtolower($new, 'UTF-8') === mb_strtolower($cur, 'UTF-8')) continue;
                if ($cur !== '' && !in_array(mb_strtolower(preg_replace('/\s+/', ' ', $cur), 'UTF-8'), arFrAutoCandidates($ar, $t), true)) continue; // مكتوب بيده
                $set[$f] = $new; $ins->execute([(int)$r['id'], $f, $cur, $new]); $n++;
            }
            if ($set) {
                $db->prepare("UPDATE employees SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . " WHERE id = ?")->execute(array_merge(array_values($set), [(int)$r['id']]));
                $emps++;
            }
        }
        $db->commit();
        if ($n) logAudit('heal_names_fr', 'employees', 0, null, ['fields' => $n, 'employees' => $emps, 'backup' => '_names_fr_bk20261001']);
        setSetting($flag, date('Y-m-d H:i') . " ($n خانة — $emps ملفاً)");
    } catch (Throwable $e) {
        try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $e2) {}
    }
}

/**
 * 🗺️🔤 (2026-10-01 «الترجمة من العربي إلى الفرنسي تكون مظبوطة ما تضحك الأساتذة علينا»): تكملة قاموس الأماكن بكل محلّات
 * الولادة/البلدات الموجودة فعلاً بملفات أساتذة السنة وكان النقل الاحتياطي يطلّعها بلا أحرف علّة (Dmchk, Tnourin, Kbiat…).
 */
function arPlaceDictExtra(): array {
    return [
        'مار موسى'=>'Mar Moussa','تنورين'=>'Tannourine','سرعين'=>'Saraïne','الليلكة'=>'Laylaké','الليلكي'=>'Laylaké','الليكي'=>'Laylaké',
        'حارة حريك'=>'Haret Hreik','وادي بنحليه'=>'Wadi Bnahlé','المختارة'=>'Moukhtara','التل'=>'El Tall','تل'=>'Tall','الكويت'=>'Koweït',
        'جوار النخل'=>'Jouar El Nakhl','حوش بردى'=>'Hoch Barada','جنينة ارسلان'=>'Jnaynet Arslan','العيشية'=>'Aïchiyeh',
        'عين الرمانة'=>'Aïn El Remmaneh','عبن الرمانة'=>'Aïn El Remmaneh','شمسطار'=>'Chmestar','ميدان اكيس'=>'Midan Ekbes',
        'دكار سينغال'=>'Dakar - Sénégal','دكار'=>'Dakar','سينغال'=>'Sénégal','البوار'=>'Bouar','القاهرة'=>'Le Caire','فيليبين'=>'Philippines',
        'عين الريحانة'=>'Aïn El Rihaneh','عين الريحاني'=>'Aïn El Rihaneh','دمنيه شرقيه'=>'Doumaniyeh Charkiyeh','دمنية شرقية'=>'Doumaniyeh Charkiyeh',
        'دمشق'=>'Damas','عمان'=>'Amman','غزة'=>'Gaza','بقسطا'=>'Bqosta','بقسطه'=>'Bqosta','عين القبو'=>'Aïn El Qabou',
        'القرداحة'=>'Qardaha','تحويطة الغدير'=>'Tahwitet El Ghadir','تحويطة النهر'=>'Tahwitet El Nahr','رعيت'=>'Raït',
        'عين دارة'=>'Aïn Dara','تل عباس'=>'Tall Abbas','بنغازي ليبيا'=>'Benghazi - Libye','بنغازي'=>'Benghazi','ليبيا'=>'Libye',
        'كفرنيس'=>'Kfarniss','كفرقطرة'=>'Kfarqatra','بسوس'=>'Bsous','وجه الحجر'=>'Wajh El Hajar','معلولا'=>'Maaloula',
        'الرجمة'=>'Rajmeh','تمنين'=>'Temnine','تمنين الفوقا'=>'Temnine El Faouqa','عجلتون'=>'Ajaltoun','دير دوريت'=>'Deir Dourit',
        'خربة قنافار'=>'Kherbet Qanafar','رحبة'=>'Rahbeh','القبيات'=>'Qoubaiyat','دبين'=>'Debbine','حارة صخر'=>'Haret Sakhr',
        'صفد الدوارة'=>'Safad El Dawara','القدام'=>'Qaddam','الهلالية'=>'Hlaliyeh','القبة'=>'Qobbeh','طرابلس'=>'Tripoli',
        'بكيفا'=>'Bkifa','المية و مية'=>'Miyé ou Miyé','المية ومية'=>'Miyé ou Miyé','ميه وميه'=>'Miyé ou Miyé','مية ومية'=>'Miyé ou Miyé',
        'سد البوشرية'=>'Sed El Bauchrieh','شدرا'=>'Chadra','القنطرة'=>'Qantara','شياح'=>'Chiyah','الشياح'=>'Chiyah',
        'البوشرية'=>'Bauchrieh','البوسريه'=>'Bauchrieh','عندقت'=>'Andaqet','ضبية'=>'Dbayeh','جونية'=>'Jounieh','كفر جره'=>'Kfarjarra',
        'كفرجره'=>'Kfarjarra','ديك المحدي'=>'Dik El Mehdi','المحاربية'=>'Mharbiyeh','روضة'=>'Rawda','الروضة'=>'Rawda',
        'عدوسية'=>'Aadousiyeh','خزيز'=>'Khzaiz','خزير'=>'Khzaiz','الحدت'=>'Hadath','الجدث'=>'Hadath','الوسطاني'=>'Wastani',
        'جسر الباشا'=>'Jisr El Bacha','كفر فالوس'=>'Kfarfalous','كفرفالوس'=>'Kfarfalous','بلونة'=>'Ballouneh','الجمهور'=>'Jamhour',
        'شتورة'=>'Chtaura','منصورية'=>'Mansourieh','المنصورية'=>'Mansourieh','عين المرج'=>'Aïn El Marj','المرج'=>'El Marj',
        'سعدنايل'=>'Saadnayel','خربة بسري'=>'Kherbet Bisri','عاريا'=>'Araya','بطشاية'=>'Btechay','بكاسين'=>'Bkassine',
        'سيدة البشارة'=>"Notre-Dame de l'Annonciation",'سيدة النجاة'=>'Notre-Dame de la Délivrance','داريا'=>'Daraya','العبادية'=>'Abadiyeh',
        'جدايل'=>'Jeddayel','جبيل'=>'Jbeil','قبلي'=>'Qebli','القناية'=>'Qennayeh','كرخا'=>'Karkha','حي'=>'Hay','الجورة'=>'Joura',
        'المنارة'=>'Manara','صور'=>'Tyr','الكشك'=>'Kechek','دلبتا'=>'Dlebta','حوش الزراعنة'=>'Hoch El Zaraaneh','حوش الزراعة'=>'Hoch El Zaraaneh',
        'معاصر الشوف'=>'Maasser El Chouf','العاقورة'=>'Aqoura','الغابات'=>'Ghabat','سرجبال'=>'Sirjbal','صفاريه'=>'Sfaray','صفاري'=>'Sfaray',
        'الفرزل'=>'Ferzol','الفزل'=>'Ferzol','مار انطونيوس'=>'Mar Antonios','مار نقولا'=>'Mar Nicolas','يبرود'=>'Yabroud',
        'تل جيلو'=>'Tall Jilo','بشري'=>'Bcharré','المجيدل'=>'Mjeidel','قتالة'=>'Qtaleh','قيمرية'=>'Qaymariyeh','اللبوة'=>'Laboueh',
        'اللبوه'=>'Laboueh','المشارفة'=>'Mcharfeh','جوسيه'=>'Joussieh','الحمصية'=>'Homsiyeh','بمهريه'=>'Bmahray','راشانا'=>'Rachana',
        'برج البراجنة'=>'Bourj El Barajneh','برج الراجنة'=>'Bourj El Barajneh','بحمدون'=>'Bhamdoun','زحلتا'=>'Zahalta','الصويري'=>'Souairi',
        'بيت لهيا'=>'Beit Lahia','نيحا'=>'Niha','المحفارة'=>'Mahfara','حومال'=>'Houmal','عجبل'=>'Ajbel','عشاش'=>'Aachach',
        'وادي شحرور العليا'=>'Wadi Chahrour El Olya','العليا'=>'El Olya','ضهور'=>'Dhour','القرداحه'=>'Qardaha',
        'جون'=>'Joun','عين'=>'Aïn','وادي'=>'Wadi','حوش'=>'Hoch','خربة'=>'Kherbet','برج'=>'Bourj','كفر'=>'Kfar','بيت'=>'Beit','دير'=>'Deir',
    ];
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
    $raw += arPlaceDictExtra(); // 🗺️🔤 (2026-10-01) تكملة: محلّات ولادة/بلدات أساتذة السنة (القديم له الأولوية)
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
        [['اجتماعيات','sciences sociales','social studies','etudes sociales'], 'الاجتماعيات', 'Sciences sociales', 'Social Studies'],
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

/** المادة بلغة الوثيقة ('ar'|'fr'|'en') — يدعم موادّ متعددة مفصولة بـ , ، / + أو بمسافات
 *  («تاريخ تربية جغرافيا» — p1 ‏2026-08-21): تُترجم كلمةً كلمة فقط إن عُرفت كل الكلمات،
 *  وإلا يبقى النص كما كُتب (حتى لا تتخربط عبارة حرّة مثل «مديرة قسم الروضات»). */
function subjectToLang($raw, $lang) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $i = ['ar' => 0, 'fr' => 1, 'en' => 2][$lang] ?? 0;
    $map = subjectMap();
    $sep = ($lang === 'ar') ? ' و' : ', ';
    $one = function ($part) use ($map, $i, $sep) {
        $part = trim($part);
        if ($part === '') return '';
        $k = subjectNormalize($part);
        if (isset($map[$k])) return $map[$k][$i];
        // موادّ متعددة بمسافات: طابق أطول عبارة (حتى 3 كلمات) — كل الكلمات لازم تُعرف
        $words = array_values(array_filter(explode(' ', $k), 'strlen'));
        if (count($words) < 2) return $part;
        // واو العطف الملزوقة («واجتماعيات» — p1 ‏2026-08-21): شيلها إن عُرفت الكلمة بلاها
        $words = array_map(function ($w) use ($map) {
            if (mb_strpos($w, 'و') === 0 && mb_strlen($w, 'UTF-8') > 2 && !isset($map[$w])) {
                $c = mb_substr($w, 1, null, 'UTF-8');
                if (isset($map[$c])) return $c;
            }
            return $w;
        }, $words);
        $out = [];
        for ($w = 0; $w < count($words); $w++) {
            for ($len = min(3, count($words) - $w); $len >= 1; $len--) {
                $ph = implode(' ', array_slice($words, $w, $len));
                if (isset($map[$ph])) { $out[] = $map[$ph][$i]; $w += $len - 1; continue 2; }
            }
            return $part; // كلمة غير معروفة → العبارة كلها كما كُتبت
        }
        return implode($sep, $out);
    };
    $parts = array_filter(array_map($one, preg_split('/\s*[,،;\/+&]\s*/u', $raw)), 'strlen');
    return implode($sep, $parts);
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
