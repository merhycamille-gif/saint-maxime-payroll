<?php
/**
 * 🔤 تعريب الأسماء والعناوين المكتوبة بالأحرف اللاتينية (2026-10-27 «اللي كاتب الأسماء أو العناوين لازم يتحوّلوا للعربي»):
 *   الأساتذة الجدد يكتبون بفورم الرابط «Zalka»، «Siham Attallah»، «Mont liban»… فتطلع النماذج العربية (الضمان) بأحرف لاتينية.
 *   frToAr(): قاموس (محافظات/أقضية/بلدات لبنان + أسماء وعائلات شائعة) ثمّ قواعد نطق (ch⇒ش، kh⇒خ، gh⇒غ، 3⇒ع، 7⇒ح…).
 *   arabizeIfLatin(): يعرّب فقط إن كان النصّ فيه أحرف لاتينية (العربي يمرّ كما هو)؛ «عربي/Latin» ⇒ الجزء العربي.
 *   يُستعمل عند الطباعة (النماذج الرسمية) وبأداة «تعريب الملفات» (pages/arabize.php) باقتراح يُعدَّل قبل الحفظ.
 */

function frArDict(): array {
    static $d = null;
    if ($d !== null) return $d;
    $d = [
        // محافظات وأقضية
        'beirut' => 'بيروت', 'beyrouth' => 'بيروت', 'mont liban' => 'جبل لبنان', 'mont-liban' => 'جبل لبنان', 'mount lebanon' => 'جبل لبنان', 'jabal lubnan' => 'جبل لبنان',
        'north' => 'الشمال', 'nord' => 'الشمال', 'south' => 'الجنوب', 'sud' => 'الجنوب', 'liban sud' => 'الجنوب', 'south lebanon' => 'الجنوب', 'bekaa' => 'البقاع', 'beqaa' => 'البقاع', 'bekaa valley' => 'البقاع',
        'nabatieh' => 'النبطية', 'nabatiyeh' => 'النبطية', 'nabatiye' => 'النبطية', 'akkar' => 'عكار', 'baalbek' => 'بعلبك', 'baalbeck' => 'بعلبك', 'hermel' => 'الهرمل', 'baalbek hermel' => 'بعلبك الهرمل',
        'baabda' => 'بعبدا', 'matn' => 'المتن', 'metn' => 'المتن', 'meten' => 'المتن', 'el metn' => 'المتن', 'kesrouan' => 'كسروان', 'keserwan' => 'كسروان', 'kesrwan' => 'كسروان', 'jbeil' => 'جبيل', 'byblos' => 'جبيل',
        'aley' => 'عاليه', 'chouf' => 'الشوف', 'shouf' => 'الشوف', 'saida' => 'صيدا', 'sidon' => 'صيدا', 'sour' => 'صور', 'tyr' => 'صور', 'tyre' => 'صور', 'jezzine' => 'جزين', 'jezzin' => 'جزين',
        'marjeyoun' => 'مرجعيون', 'marjayoun' => 'مرجعيون', 'marje3yun' => 'مرجعيون', 'marjeyoun' => 'مرجعيون', 'hasbaya' => 'حاصبيا', 'bint jbeil' => 'بنت جبيل', 'zahle' => 'زحلة', 'zahleh' => 'زحلة', 'zahlé' => 'زحلة',
        'west bekaa' => 'البقاع الغربي', 'bekaa ouest' => 'البقاع الغربي', 'rachaya' => 'راشيا', 'tripoli' => 'طرابلس', 'trablous' => 'طرابلس', 'koura' => 'الكورة', 'zgharta' => 'زغرتا', 'bcharre' => 'بشري', 'bsharri' => 'بشري',
        'batroun' => 'البترون', 'minieh' => 'المنية', 'dinniyeh' => 'الضنية', 'minieh danniyeh' => 'المنية الضنية',
        // بلدات وأحياء
        'zalka' => 'الزلقا', 'zalqa' => 'الزلقا', 'mansourieh' => 'المنصورية', 'mansouryeh' => 'المنصورية', 'mansouriyeh' => 'المنصورية', 'mansourye' => 'المنصورية', 'hadath' => 'الحدث', 'hadat' => 'الحدث', 'sin el fil' => 'سن الفيل', 'sin el-fil' => 'سن الفيل',
        'dekwaneh' => 'الدكوانة', 'jdeideh' => 'الجديدة', 'jdeide' => 'الجديدة', 'antelias' => 'أنطلياس', 'jal el dib' => 'جل الديب', 'zouk mosbeh' => 'ذوق مصبح', 'zouk mikael' => 'ذوق مكايل', 'zouk' => 'ذوق', 'jounieh' => 'جونية', 'jounie' => 'جونية',
        'ain saadeh' => 'عين سعادة', 'broummana' => 'برمانا', 'broumana' => 'برمانا', 'beit mery' => 'بيت مري', 'fanar' => 'الفنار', 'ain el remmaneh' => 'عين الرمانة', 'ain el remaneh' => 'عين الرمانة', 'furn el chebbak' => 'فرن الشباك', 'furn el chebak' => 'فرن الشباك',
        'hazmieh' => 'الحازمية', 'hazmiyeh' => 'الحازمية', 'kfarshima' => 'كفرشيما', 'kfarchima' => 'كفرشيما', 'choueifat' => 'الشويفات', 'chouaifat' => 'الشويفات', 'aramoun' => 'عرمون', 'khalde' => 'خلدة', 'khaldeh' => 'خلدة', 'damour' => 'الدامور',
        'abra' => 'عبرا', 'maghdouche' => 'مغدوشة', 'maghdoucheh' => 'مغدوشة', 'darb el sim' => 'درب السيم', 'darb essim' => 'درب السيم', 'barja' => 'برجا', 'deir el qamar' => 'دير القمر', 'beiteddine' => 'بيت الدين', 'beit eddine' => 'بيت الدين',
        'ablah' => 'أبلح', 'ferzol' => 'الفرزل', 'furzol' => 'الفرزل', 'chtaura' => 'شتورا', 'chtoura' => 'شتورا', 'rayak' => 'رياق', 'riyaq' => 'رياق', 'jdita' => 'جديتا', 'jdeita' => 'جديتا', 'taanayel' => 'تعنايل', 'kab elias' => 'قب الياس', 'bar elias' => 'بر الياس', 'niha' => 'نيحا', 'ksara' => 'كسارا',
        'achrafieh' => 'الأشرفية', 'ashrafieh' => 'الأشرفية', 'mazraa' => 'المزرعة', 'hamra' => 'الحمرا', 'badaro' => 'بدارو', 'jnah' => 'الجناح', 'bir hassan' => 'بئر حسن', 'mar elias' => 'مار الياس', 'mousseitbeh' => 'المصيطبة', 'bourj hammoud' => 'برج حمود', 'burj hammoud' => 'برج حمود',
        'ghazir' => 'غزير', 'harissa' => 'حريصا', 'ajaltoun' => 'عجلتون', 'faraya' => 'فاريا', 'mayrouba' => 'ميروبا', 'ballouneh' => 'بلونة', 'ghosta' => 'غوسطا', 'dbayeh' => 'ضبية', 'awkar' => 'عوكر', 'rabieh' => 'الرابية', 'mtayleb' => 'المطيلب', 'bikfaya' => 'بكفيا',
        'dhour choueir' => 'ضهور الشوير', 'dhour el choueir' => 'ضهور الشوير', 'baskinta' => 'بسكنتا', 'bsalim' => 'بصاليم', 'majdalouna' => 'مجدلونا', 'bissour' => 'بيصور', 'baissour' => 'بيصور', 'jiyeh' => 'الجية', 'sibline' => 'سبلين', 'jadra' => 'جدرا', 'naameh' => 'الناعمة', 'mechref' => 'مشرف', 'doha' => 'الدوحة',
        'bchamoun' => 'بشامون', 'souk el gharb' => 'سوق الغرب', 'bhamdoun' => 'بحمدون', 'sofar' => 'صوفر', 'hammana' => 'حمانا', 'falougha' => 'فالوغا', 'ras el metn' => 'رأس المتن', 'qornayel' => 'قرنايل', 'ain dara' => 'عين دارة', 'baakline' => 'بعقلين', 'moukhtara' => 'المختارة', 'kfarhim' => 'كفرحيم', 'ain zhalta' => 'عين زحلتا', 'barouk' => 'الباروك',
        'mina' => 'الميناء', 'qobayat' => 'القبيات', 'halba' => 'حلبا', 'verdun' => 'فردان', 'raouche' => 'الروشة', 'mar mikhael' => 'مار مخايل', 'gemmayzeh' => 'الجميزة', 'sioufi' => 'السيوفي', 'karm el zeitoun' => 'كرم الزيتون', 'horsh tabet' => 'حرش تابت', 'jisr el bacha' => 'جسر الباشا',
        'tahwita' => 'التحويطة', 'mkalles' => 'المكلس', 'mar roukoz' => 'مار روكز', 'dahr el souan' => 'ضهر الصوان', 'bteghrine' => 'بتغرين', 'qornet chehwan' => 'قرنة شهوان', 'biakout' => 'بياقوت', 'naccache' => 'النقاش', 'ain aar' => 'عين عار', 'mazraat yachouh' => 'مزرعة يشوع',
        'haret hreik' => 'حارة حريك', 'ghobeiry' => 'الغبيري', 'chiah' => 'الشياح', 'chiyah' => 'الشياح', 'chyah' => 'الشياح', 'al chyah' => 'الشياح', 'el chyah' => 'الشياح', 'laylaki' => 'الليلكي', 'bourj el barajneh' => 'برج البراجنة', 'ouzai' => 'الأوزاعي', 'louaizeh' => 'اللويزة', 'yarze' => 'اليرزة',
        'ain najm' => 'عين نجم', 'monteverde' => 'مونتيفردي', 'beit chabab' => 'بيت شباب', 'roumieh' => 'رومية', 'mar chaaya' => 'مار شعيا', 'new rawda' => 'نيو روضة', 'rawda' => 'الروضة', 'sed el bouchrieh' => 'سدّ البوشرية', 'sed el bouchryye' => 'سدّ البوشرية', 'sad el bauchrieh' => 'سدّ البوشرية',
        'bouchrieh' => 'البوشرية', 'bouchriyeh' => 'البوشرية', 'bauchrieh' => 'البوشرية', 'rmeileh' => 'الرميلة', 'rmeile' => 'الرميلة', 'chhim' => 'شحيم', 'chehim' => 'شحيم', 'daraya' => 'داريا', 'sami el soleh' => 'سامي الصلح', 'sami el solh' => 'سامي الصلح', 'central' => 'سنترال',
        'addis ababa' => 'أديس أبابا', 'gondar' => 'غوندر', 'ethiopia' => 'إثيوبيا', 'syria' => 'سوريا', 'syrie' => 'سوريا', 'liban' => 'لبنان', 'lebanon' => 'لبنان', 'libanaise' => 'لبنانية', 'libanais' => 'لبناني', 'lebanese' => 'لبنانية',
        'catholique' => 'الكاثوليك', 'catholic' => 'الكاثوليك', 'soeur' => 'الأخت', 'sr' => 'الأخت', 'pere' => 'الأب', 'abouna' => 'أبونا', 'mere' => 'الأم',
        // أسماء أولى شائعة
        'charbel' => 'شربل', 'georges' => 'جورج', 'george' => 'جورج', 'elie' => 'إيلي', 'eli' => 'إيلي', 'elias' => 'الياس', 'elias nehme' => 'الياس نعمة', 'joseph' => 'جوزيف', 'antoine' => 'أنطوان', 'michel' => 'ميشال', 'pierre' => 'بيار', 'tony' => 'طوني', 'toni' => 'طوني',
        'marwan' => 'مروان', 'nabil' => 'نبيل', 'samir' => 'سمير', 'fadi' => 'فادي', 'rami' => 'رامي', 'roy' => 'روي', 'rita' => 'ريتا', 'maya' => 'مايا', 'nour' => 'نور', 'rima' => 'ريما', 'siham' => 'سهام', 'ghada' => 'غادة', 'norma' => 'نورما', 'donia' => 'دنيا', 'dounia' => 'دنيا',
        'mona' => 'منى', 'hoda' => 'هدى', 'layla' => 'ليلى', 'leila' => 'ليلى', 'nada' => 'ندى', 'hala' => 'هالة', 'rana' => 'رنا', 'lara' => 'لارا', 'carla' => 'كارلا', 'joelle' => 'جويل', 'pascale' => 'باسكال', 'nicole' => 'نيكول', 'therese' => 'تريز', 'marie' => 'ماري',
        'josephine' => 'جوزفين', 'antoinette' => 'أنطوانيت', 'georgette' => 'جورجيت', 'yvonne' => 'إيفون', 'jeanne' => 'جان', 'hanna' => 'حنا', 'khalil' => 'خليل', 'youssef' => 'يوسف', 'boutros' => 'بطرس', 'mansour' => 'منصور', 'salim' => 'سليم', 'nassif' => 'ناصيف',
        'sawsan' => 'سوسن', 'soulafa' => 'سلافة', 'nancy' => 'نانسي', 'sarah' => 'سارة', 'sara' => 'سارة', 'rim' => 'ريم', 'reem' => 'ريم', 'ahmad' => 'أحمد', 'ahmed' => 'أحمد', 'georgio' => 'جورجيو', 'perla' => 'بيرلا', 'nathalie' => 'ناتالي', 'natalie' => 'ناتالي', 'elio' => 'إيليو',
        'abed el kader' => 'عبد القادر', 'abdel kader' => 'عبد القادر', 'abdul kader' => 'عبد القادر', 'grace' => 'غريس', 'tracy' => 'تراسي', 'delsin' => 'دلسن', 'lea' => 'ليا', 'camille' => 'كميل', 'jihane' => 'جهان', 'jihan' => 'جهان', 'mireille' => 'ميراي', 'madonna' => 'مادونا',
        'chantal' => 'شنتال', 'karine' => 'كارين', 'marcelle' => 'مرسال', 'marina' => 'مارينا', 'maryam' => 'مريم', 'mariam' => 'مريم', 'mily' => 'ميلي', 'caroline' => 'كارولين', 'dolly' => 'دوللي', 'eliana' => 'إليانا', 'takla' => 'تقلا', 'joanna' => 'جوانا', 'rania' => 'رانية',
        'zeina' => 'زينة', 'christina' => 'كريستينا', 'lina' => 'لينا', 'diana' => 'ديانا', 'roudy' => 'رودي', 'pamela' => 'باميلا', 'jhona' => 'جونا', 'raja' => 'رجا', 'hilani' => 'هيلاني', 'jessica' => 'جيسيكا', 'alaa' => 'علاء', 'anthony' => 'أنطوني', 'remie' => 'ريمي', 'remy' => 'ريمي',
        'hussein' => 'حسين', 'hassan' => 'حسن', 'ali' => 'علي', 'mohamad' => 'محمد', 'mohammad' => 'محمد', 'mohammed' => 'محمد', 'omar' => 'عمر', 'fatima' => 'فاطمة', 'zeinab' => 'زينب', 'zahra' => 'زهرا', 'nabila' => 'نبيلة', 'samira' => 'سميرة', 'souad' => 'سعاد', 'amal' => 'أمل', 'wafa' => 'وفاء', 'hanan' => 'حنان',
        'tereza' => 'تريزا', 'theresa' => 'تريزا', 'roula' => 'رولا', 'randa' => 'رندة', 'najwa' => 'نجوى', 'salwa' => 'سلوى', 'jacqueline' => 'جاكلين', 'violette' => 'فيوليت', 'laure' => 'لور', 'claude' => 'كلود', 'claudette' => 'كلوديت', 'nadine' => 'نادين', 'nadia' => 'نادية', 'elham' => 'إلهام', 'ilham' => 'إلهام',
        // عائلات شائعة
        'attallah' => 'عطالله', 'atallah' => 'عطالله', 'kattar' => 'قطار', 'saab' => 'صعب', 'nakhleh' => 'نخلة', 'nakhle' => 'نخلة', 'el hajjar' => 'الحجار', 'hajjar' => 'حجار', 'al khoury' => 'الخوري', 'el khoury' => 'الخوري', 'khoury' => 'خوري', 'oueidat' => 'عويدات', 'harakeh' => 'حركة', 'boughdana' => 'بغدانة',
        'haddad' => 'حداد', 'sfeir' => 'صفير', 'aoun' => 'عون', 'ayoub' => 'أيوب', 'said' => 'سعيد', 'farah' => 'فرح', 'daher' => 'ضاهر', 'doumit' => 'ضومط', 'ghostine' => 'غسطين', 'zaarour' => 'زعرور', 'karam' => 'كرم', 'arab' => 'عرب', 'basbous' => 'بصبوص', 'jabbour' => 'جبور',
        'nassour' => 'نصورة', 'hage' => 'الحاج', 'el hage' => 'الحاج', 'haj' => 'الحاج', 'alkhodor' => 'الخضر', 'al khodor' => 'الخضر', 'khodor' => 'خضر', 'rizk' => 'رزق', 'nehme' => 'نعمة', 'yazbeck' => 'يزبك', 'yazbek' => 'يزبك', 'merhy' => 'مرعي', 'merhi' => 'مرعي', 'howayek' => 'الحويك', 'hoayek' => 'الحويك',
        'tannous' => 'طنوس', 'tanios' => 'طانيوس', 'berbary' => 'بربري', 'arja' => 'العرجا', 'hassouneh' => 'حسونة', 'fakhoury' => 'فاخوري', 'azar' => 'عازار', 'khater' => 'خاطر', 'ghanimeh' => 'غنيمة', 'sader' => 'صادر', 'naddour' => 'ندور', 'machaalani' => 'مشعلاني', 'chalhoub' => 'شلهوب',
        'kanaan' => 'كنعان', 'abou younes' => 'أبو يونس', 'yaacoub' => 'يعقوب', 'yacoub' => 'يعقوب', 'chamoun' => 'شمعون', 'sakkaf' => 'السكاف', 'charro' => 'شرو', 'gergi' => 'جرجي', 'richa' => 'ريشا', 'el kareh' => 'القارح', 'kareh' => 'قارح', 'zouba' => 'زوبا', 'karaa' => 'قرعة', 'habr' => 'الهبر',
        'feghali' => 'فغالي', 'sammak' => 'سماك', 'basset' => 'باسط', 'hleihel' => 'حليحل', 'bsaibes' => 'بصيبص', 'roukoz' => 'روكز', 'pierre hage' => 'بيار الحاج', 'nasr' => 'نصر', 'nassar' => 'نصار', 'saliba' => 'صليبا', 'moussa' => 'موسى', 'francis' => 'فرنسيس', 'constantin' => 'قسطنطين',
        'asmar' => 'الأسمر', 'el asmar' => 'الأسمر', 'boulos' => 'بولس', 'bader' => 'بدر', 'amin' => 'أمين', 'hajj' => 'الحاج', 'el hajj' => 'الحاج', 'daou' => 'ضو', 'daoud' => 'داود', 'khalife' => 'خليفة', 'khalifeh' => 'خليفة', 'abi nader' => 'أبي ناضر', 'bou assy' => 'بو عاصي', 'bou assi' => 'بو عاصي',
        'kahwaji' => 'قهوجي', 'ghanem' => 'غانم', 'salameh' => 'سلامة', 'salame' => 'سلامة', 'matar' => 'مطر', 'mattar' => 'مطر', 'abdallah' => 'عبدالله', 'abdullah' => 'عبدالله', 'maalouf' => 'معلوف', 'awad' => 'عواد', 'semaan' => 'سمعان', 'sleiman' => 'سليمان', 'slim' => 'سليم', 'tabet' => 'تابت', 'thabet' => 'ثابت',
    ];
    return $d;
}

/** هل فيه أحرف لاتينية؟ */
function hasLatin(string $s): bool { return preg_match('/[A-Za-z]/', $s) === 1; }

/** تعريب كلمة واحدة بقواعد النطق (بعد تعذّر القاموس) — اقتراح قابل للتعديل */
function frArWord(string $w): string {
    $w = strtolower($w);
    if ($w === '') return '';
    if (preg_match('/\d/', $w) && !preg_match('/^[a-z]+\d+$|^\d+[a-z]+$/', $w) && !preg_match('/[23578]/', $w)) return $w;   // رموز مثل B1 تبقى
    if (preg_match('/^[a-z]\d+$|^\d+[a-z]$/', $w)) return strtoupper($w);
    // النهايات: eh/e ⇒ ة أو صامتة، ah ⇒ اح، a ⇒ ا/ة
    $suffix = '';
    if (preg_match('/eh$/', $w)) { $w = substr($w, 0, -2); $suffix = 'ة'; }
    elseif (preg_match('/[^aeiou]e$/', $w) && strlen($w) > 2) { $w = substr($w, 0, -1); $suffix = ''; }
    elseif (preg_match('/ah$/', $w)) { $w = substr($w, 0, -2); $suffix = 'اح'; }
    elseif (preg_match('/[^aeiou]a$/', $w)) { $w = substr($w, 0, -1); $suffix = 'ا'; }
    elseif (preg_match('/[^aeiou]y$/', $w)) { $w = substr($w, 0, -1); $suffix = 'ي'; }
    $map = [
        'sch' => 'ش', 'tch' => 'تش', 'ch' => 'ش', 'sh' => 'ش', 'kh' => 'خ', 'gh' => 'غ', 'th' => 'ث', 'dh' => 'ذ', 'ph' => 'ف', 'ck' => 'ك', 'qu' => 'ك',
        'ou' => 'و', 'oo' => 'و', 'ee' => 'ي', 'ei' => 'ي', 'ai' => 'ي', 'ay' => 'ي', 'ey' => 'ي', 'aa' => 'ا', 'ea' => 'يا', 'ia' => 'يا', 'io' => 'يو', 'oi' => 'وا',
    ];
    $out = ''; $i = 0; $n = strlen($w); $start = true;
    while ($i < $n) {
        $hit = false;
        foreach ([3, 2] as $len) {
            if ($i + $len <= $n) { $seg = substr($w, $i, $len); if (isset($map[$seg])) { $out .= ($start && in_array($seg, ['ou', 'oo', 'ee', 'ei', 'ai', 'ay', 'ey', 'aa', 'ea', 'ia', 'io', 'oi'], true) ? 'ا' : '') . $map[$seg]; $i += $len; $hit = true; break; } }
        }
        if ($hit) { $start = false; continue; }
        $c = $w[$i]; $next = $w[$i + 1] ?? '';
        switch ($c) {
            case 'a': $out .= $start ? 'ا' : ''; break;
            case 'e': $out .= $start ? 'ا' : ''; break;
            case 'i': case 'y': $out .= $start ? 'اي' : 'ي'; break;
            case 'o': case 'u': $out .= $start ? 'او' : 'و'; break;
            case 'w': $out .= 'و'; break;
            case 'b': case 'p': $out .= 'ب'; break;
            case 't': $out .= 'ت'; break; case 'd': $out .= 'د'; break; case 'r': $out .= 'ر'; break; case 'z': $out .= 'ز'; break;
            case 's': $out .= 'س'; break; case 'c': $out .= in_array($next, ['e', 'i', 'y'], true) ? 'س' : 'ك'; break;
            case 'k': $out .= 'ك'; break; case 'q': $out .= 'ق'; break; case 'g': $out .= 'غ'; break; case 'j': $out .= 'ج'; break;
            case 'h': $out .= $start ? 'ح' : 'ه'; break; case 'l': $out .= 'ل'; break; case 'm': $out .= 'م'; break; case 'n': $out .= 'ن'; break;
            case 'f': case 'v': $out .= 'ف'; break; case 'x': $out .= 'كس'; break;
            case '3': $out .= 'ع'; break; case '7': $out .= 'ح'; break; case '2': $out .= 'أ'; break; case '5': $out .= 'خ'; break; case '8': $out .= 'غ'; break; case '9': $out .= 'ق'; break;
            case "'": $out .= 'ع'; break;
            default: $out .= $c;
        }
        $i++; $start = false;
    }
    return $out . $suffix;
}

/** تعريب نصّ كامل (اسم/عنوان): القاموس أوّلاً (كاملاً ثمّ مقاطع) ثمّ القواعد؛ «el/al» ⇒ «ال» ملتصقة */
function frToAr(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '' || !hasLatin($s)) return $s;
    // «عربي/Latin» أو «Latin / عربي» ⇒ الجزء العربي
    if (strpos($s, '/') !== false) { foreach (explode('/', $s) as $part) { $part = trim($part); if ($part !== '' && !hasLatin($part) && preg_match('/[\x{0600}-\x{06FF}]/u', $part)) return $part; } }
    $hadApos = preg_match("/[a-z]['’`][a-z]/i", $s) === 1;   // Sa'id ⇒ ع — أمّا فاصلة iconv للحروف المشكولة (é ⇒ 'e) فتُشال
    $norm = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe',
                         'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C']);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $norm); if ($t !== false && $t !== '') $norm = $t;
    if (!$hadApos) $norm = str_replace(["'", '’', '`', '^', '~', '"'], '', $norm);
    $norm = strtolower(trim(preg_replace('/[^a-z0-9\'\s\-]/i', ' ', str_replace(['’', '`'], "'", $norm))));
    $norm = preg_replace('/\s+/', ' ', str_replace('-', ' ', $norm));
    $dict = frArDict();
    if (isset($dict[$norm])) return $dict[$norm];
    $words = explode(' ', $norm); $out = []; $i = 0; $n = count($words);
    while ($i < $n) {
        // أطول تطابق بالقاموس (حتى 3 كلمات)
        $hit = false;
        for ($len = min(3, $n - $i); $len >= 1; $len--) {
            $seg = implode(' ', array_slice($words, $i, $len));
            if (isset($dict[$seg])) { $out[] = $dict[$seg]; $i += $len; $hit = true; break; }
        }
        if ($hit) continue;
        $w = $words[$i];
        if (in_array($w, ['el', 'al', 'l', 'le', 'la'], true) && $i + 1 < $n) {
            $nx = $words[$i + 1]; $nxAr = $dict[$nx] ?? frArWord($nx);
            $out[] = 'ال' . preg_replace('/^ال/u', '', $nxAr); $i += 2; continue;
        }
        if (in_array($w, ['bou', 'abou', 'abu'], true) && $i + 1 < $n) { $nx = $words[$i + 1]; $out[] = 'أبو ' . ($dict[$nx] ?? frArWord($nx)); $i += 2; continue; }
        if (in_array($w, ['de', 'du', 'des'], true)) { $i++; continue; }
        $out[] = frArWord($w); $i++;
    }
    return trim(implode(' ', array_filter($out, fn($x) => $x !== '')));
}

/** للطباعة: يعرّب فقط إن كان فيه لاتيني (العربي يمرّ كما هو) */
function arabizeIfLatin($s): string { $s = (string)$s; return hasLatin($s) ? frToAr($s) : $s; }
