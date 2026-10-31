<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/local_sync.php'; // 🔁 مزامنة بيانات الأونلاين → الكمبيوتر تلقائياً (محلياً فقط)
requireLogin();
ensureLeftDateAllColumn(); // 🚪 تركيب ذاتي: عمود «ترك من الكل» + تعبئته مرّة من الثلاثة (2026-09-18)
ensureSalaryLaborLawColumn(); // ⚖️ تركيب ذاتي: عمود «راتب الموظف حسب قانون العمل» (2026-09-18)
ensureUsdBaseFromSyColumn(); // 💵📅 تركيب ذاتي: عمود «أساس الدولار يسري من السنة الدراسية» (2026-09-21 متعاقدو عبرا — 2025-2026 تبقى بالليرة)
healYearAdditions2627(); // شفاء ذاتي مرّة واحدة: علاوات 2026-2027 (لا يفعل شيئاً بعد تمامه)
healCaisseNumbers();     // شفاء ذاتي مرّة واحدة: إفراغ رقم الصندوق الذي كُتب آلياً على مؤسسات غير المدرسة
healPurgeClosedSchools20260731(); // شفاء ذاتي مرّة واحدة: حذف نهائي لمغدوشة + سان نيقولا بكل بياناتهما
healEchelonSplit20260731(); // شفاء ذاتي مرّة واحدة: درجات كانون تظهر بعمود «قيمة الدرجة» لا مدموجة بالأساس (p1 مارغريتا)
healHiddenImportedExtras20260804(); // شفاء ذاتي مرّة واحدة: الأجر الإضافي المخفي للمنقولين يُسجَّل علاوةً بملفاتهم ويظهر بالأعمدة (الصافي لا يتغيّر)
healOverlayImportedBonuses20260804b(); // شفاء ذاتي مرّة واحدة: تركيب العلاوات المسجّلة يدوياً قبل التصليح على أعمدة المنقولين (p1 ديانا بالتقارير)
healNetUsdMirror20260804c(); // شفاء ذاتي مرّة واحدة: تعبئة «صافي الدولار» الصفري المنقول بمرآة المحرّك (بلا مسّ رواتب الفريش دولار الحقيقية)
healImportedTaxColumn20260804d(); // شفاء ذاتي مرّة واحدة: نسب فرق المحسومات المنقولة لعمود الضريبة مع أساسه الخاضع (إثبات قانوني + بنيوي)
healFreshUsdColumn20260804e(); // شفاء ذاتي مرّة واحدة: الفريش دولار المنقول يُحفظ بملاحظات الملف ثم يُوحَّد عمود صافي الدولار على المرآة
healLeaverPhantomRows(); // شفاء ذاتي مستمرّ (كل 3 ساعات): مسح الرواتب الوهمية غير المدفوعة للتاركين بعد سنة تركهم + علاواتهم (قاعدة التارك §١٠)
require_once __DIR__ . '/translit_ar_fr.php'; healNamesFr20261001(); // 🩹🔤 (2026-10-01 بموافقته «صحّح الأسماء») شفاء مرّة واحدة: الأسماء الفرنسية المولَّدة آلياً بلا أحرف علّة ⇒ تهجئة القاموس (المكتوب باليد لا يُمسّ؛ القديم محفوظ بـ_names_fr_bk20261001)
healGhostAdditionsFromPrevYear(); // 🩹 (2026-09-17) إضافي/مكافأة مخزّنان بلا سطر بالملف → نسخ أسطر السنة السابقة حين تطابق المخزّن (الملف = الكشف)
healNullExchangeRates();          // 🩹 (2026-09-17) صفوف رواتب بلا سعر صرف → سعر الشهر + مرايا الدولار
healDerivedExchangeRates();       // 💱 (2026-09-21) سعر مشتقّ بكسور (89,498.94/89,509.14…) ⇒ سعر الشهر الرسمي + مرايا الدولار — كل 3 ساعات
// 🔎🚀 الفحص الشامل الدوري (كل شهر مخزّن ≠ المحرّك الحيّ ⇒ بند month_stale) لم يعد يشتغل داخل الصفحة — نبض خلفي بعد ظهورها (footer.php ← ajax_tick.php)
healEmploye64EndOfService();      // ⚖️ (2026-09-26) موظف قانون العمل بعد 64 لا يخضع لنهاية الخدمة ⇒ إعادة حساب أشهره المخزّنة بـ٨.٥٪ — كل 3 ساعات
healCadreEocIncludesExtra();      // 🩹⚖️ (2026-09-26) ملاك بإضافي ومفتاح «الصندوق يشمل الإضافي» مطفأ خلافاً لرفاقه ⇒ تضوية + إعادة حساب — كل 3 ساعات
healNetMathRows();                // 🩹 (2026-09-17) شهر غير مدفوع لا تركب أرقامه → إعادة حساب بالمسار الآمن recalcEmployeeYear
healStaleYearMirror20260806(); // شفاء ذاتي مرّة واحدة: إعادة حساب السنين المفتوحة والسنين التي مخزّنها لا يطابق علاوات الملف (حالة مارسيلا — الكشف يطابق الملف)
ensureYearLockTable();         // 🔒 تركيب ذاتي: جدول أقفال السنة الدراسية لكل مدرسة (2026-09-12) — خارج أي معاملة
ensureGradeUserEditedColumn();
healHananAfterLeave20261003(); // 🚪🩹 (2026-10-03 «شيل حنان تحومي كمان») مرّة واحدة: أشهرها بعد 30/4/2026
healRowsAfterLeave20261003(); // 🚪🩹 (2026-10-03 «كل شي بعد تاريخ الترك ما لازم يكون في الو راتب») مرّة واحدة: أشهر ما بعد الترك للعشرين (نسخة بـ_ms_bk_afterleft20261003)
healCarriedNoPay20261003();    // 🚪🩹 (2026-10-03 «شيلهم كلهن… من 2026-2027 بس») مرّة واحدة: أشهر 2026-2027 للأربعين المنقولين بلا راتب 2025-2026 (نسخة بـ_ms_bk_carried20261003)
healCurrentGradeAsOfToday();   // 📅🎓 (2026-10-02) يومياً: «الدرجة الحالية» بملف الملاك تلحق آخر درجة سارية اليوم بسجلّه (تدرّج 1/10 واستثنائية 1/1 المؤرّخة مسبقاً)
ensureDisplayHireDateColumn(); // 🗓️👁️ تركيب ذاتي: عمود «تاريخ الدخول المعروض (صوري)» (2026-09-26) // 🏆 تركيب ذاتي: عمود «معدَّلة بيد المستخدم» بسجلّ الدرجات (2026-09-12 «أي درجة بزيدها تثبت ما تتغيّر أبداً»)
require_once __DIR__ . '/cadre_due.php'; cadreDueEnsureColumns(); healJanaRestore20260913(); healCadreNew20260913(); healCadrePctText20260919(); healBogusLeftDates20260919(); healLinkEntryYear20260923(); healJounSchoolMove20260923(); healNewHireZeroBase20260923(); // 🎓 🚑 جنى لبوس: استرجاع سنتَيها كمتعاقدة (حُوِّلت بيده قبل الصمام) — تركيب ذاتي: عمود «ملاك من سنة» + شفاء: من صار ملاكاً هذه السنة يُستكمَل له قانون الملاك (درجات السنة + نقل ونسبة كرفاقه) — مرّة لكل أستاذ (2026-09-13)
ensureEmployeeFlagColumns();   // تركيب ذاتي: أعمدة خيارات ملف الموظف (التنزيل/زيادة الزوج/التعويض العائلي)
ensureSpouseColumns20260821(); // تركيب ذاتي: أعمدة معلومات الزوج/الزوجة (لنموذج المالية ر3)
healRemoveNoFatherDuplicates20260806(); // شفاء ذاتي مرّة واحدة بأمر المستخدم: شيل الملف المكرّر الذي بلا اسم أب حقيقي (حذف ناعم قابل للاسترجاع)
healLawfulTaxProration20260806(); // شفاء ذاتي مرّة واحدة: تطبيق قاعدة تجزئة التنزيل والشطور بمدة العمل (القانون) على ضريبة غير ذوي الـ12 شهراً المخزّنة
healMayaTaxFlags20260823(); // شفاء ذاتي مرّة واحدة: مايا أبي حبيب — تنزيل شخصي فقط + إعادة احتساب أشهرها من 2025-2026 («الضريبة 0 غلط» 2026-08-23)
healNajatCivilStatus20260823(); // شفاء ذاتي مرّة واحدة: تصحيح حالات سيدة النجاة من إخراجات القيد + مفاتيح تنزيل الأولاد («صحح الحالات وضوي المفاتيح» 2026-08-23)
healSpouseAdditionOff20260823(); // شفاء ذاتي مرّة واحدة: زيادة الزوج مطفأة تلقائياً للجميع + رسالة قرار للمتأثرين («طفي زيادة الزوج» 2026-08-23)
healChildrenAutomation20260823(); // شفاء ذاتي مرّة واحدة: سريان أتمتة بلوغ الـ18 على الأشهر المولّدة لأصحاب الأولاد المؤرَّخين (2026-08-23)
healSchoolAddressDedupe20260820(); // شفاء ذاتي مرّة واحدة: تنقية عناوين المدارس بالداتا («عبرا - عبرا» → «عبرا» + الألف المكررة)
healBonusBackfill20260820(); // شفاء ذاتي دفعات: تكميل علاوات الإضافي/النقل الناقصة أونلاين من لائحة الكمبيوتر + إعادة حساب أشهر المعنيين
healSchoolNameFrDiacritics20260821(); // شفاء ذاتي مرّة واحدة: تنقية الاسم/العنوان الفرنسي للمدارس من حروف التشكيل العربية الضائعة («ُEcole»)
healR567GeoFix20260823(); // شفاء ذاتي مرّة واحدة: تصحيح أسماء المناطق بكل المدارس حسب تهجئة قوائم أكواد الوزارة («صححهن متل ما كتبتهن الدولة» 2026-08-23)
healAlineVille20260824(); // شفاء ذاتي مرّة واحدة: بلدة الين منصور «Beyrouth» → ديك المحدي/المتن حسب سجلّها الأصلي (2026-08-24)
healTransportWindow20260825(); // شفاء ذاتي مرّة واحدة: تصفير النقل خارج نافذة أشهره (أساتذة، 2026-2027+) — «النقل من تشرين الأول لحزيران» (2026-08-25)
healCaisseImport20260826(); // شفاء ذاتي مرّة واحدة: استيراد أرقام صندوق التعويضات لأساتذة القديس مكسيموس من بيانه الرسمي (18 ملاكاً، لا يمسّ رقماً مُدخَلاً يدوياً) (2026-08-26)
healCaisseImportAbra20260826(); // شفاء ذاتي مرّة واحدة: استيراد أرقام صندوق التعويضات لأساتذة ثانوية السيدة-عبرا من بيانها الرسمي (129 ملاكاً — «بس ارقام الصندوق») (2026-08-26)
healCaisseImport4Schools20260826(); // شفاء ذاتي مرّة واحدة: أرقام الصندوق لأربع مدارس — النياح/ابلح 17 + الانتقال/الفرزل 14 + النجاة/الحدث 24 + البشارة/جون 19 («شوف ابلح فرزل حدث جون» 2026-08-26)
healFrenchDigits20260826(); // شفاء ذاتي مرّة واحدة: كل خانات الأرقام الرسمية والهواتف بأرقام فرنسية + مسح الكلام المحشور («كل الارقام اكتبو بالفرنسي» — p1 ميلي طنوس 2026-08-26)
healCnssFamilyCeilings20260826(); // شفاء ذاتي مرّة واحدة: السقفان التاريخيان للتعويضات العائلية 12م ثم 18م من تسوية 2025 الرسمية («الحد الاقصى بيتغير خلال السنة» 2026-08-26)
healNajatSheet20260827(); // شفاء ذاتي مرّة واحدة: رواتب وتعويض نقل الخاضعين بسيدة النجاة 2025-2026 طبق كشفَي البرنامج القديم (13 متعاقداً + 6 موظفين) + مطابقة الأسماء بحذف غير الخاضعين الزائدين بقراره («بدك تحطن بسنة 2025-2026… اسماء زيادة بدك تشيلهن» 2026-08-27)
healNajatGcaOff20260827(); // شفاء ذاتي مرّة واحدة: إطفاء مفتاح تنزيل الأولاد عند ديانا شرو وكارين السكاف بالنجاة («طفي» 2026-08-27 — كشف 2025-2026 المدفوع بتنزيل العازب فقط)
healNajatSheetFill20260827(); // شفاء تكميلي مرّة واحدة: خلق الأشهر الناقصة كلياً لأسماء كشفَي النجاة (باميلا نضّور أونلاين بلا أي شهر — «وينها عندك» 2026-08-27)
healAbraFixes20260827(); // شفاء ذاتي مرّة واحدة: تدقيق ملاك عبرا على كشفه — ريتا مارون حليحل (سلفة ريتا يوسف انعجنتلها) + ماريا الياس حليحل (سلفة صفر) على الكشف بالمليم + فيوليت وتريز «اساتذة تعاقد» تحويل فئة بقراره (2026-08-27)
healNajatSheetFill2_20260827(); // نسخة مشخِّصة من التكميلي: تخلق أشهر باميلا الناقصة أونلاين وتسجّل أي خطأ نصاً بالفلاغ (يُقرأ من بطاقة حالة الشفاءات بفحص الصحة) + احتياط الاسم الفرنسي (2026-08-27)
healNajatPamelaLeft20260827(); // شفاء ذاتي مرّة واحدة: مسح تواريخ ترك «مستحيلة» (أقدم من الدخول) بالنجاة — باميلا نضّور أونلاين كانت معلّمة تاركة 2024-01-12 قبل دخولها فتختفي من الكشوف (2026-08-27)
healAbraCw20260827(); // شفاء ذاتي مرّة واحدة: متعاقدو وموظفو عبرا الخاضعون على كشفه (52/52 مطابقون) — تقسيم فيوليت وتريز على الكشف + حذف غير الخاضعين الـ11 بقراره + صف تينا ايوب الوهمي («صحح» + «شيل غير الخاضعين متل النجاة» 2026-08-27)
healAbraCw2_20260827(); // نسخة ثانية: بلال اسعد وجوزيف بولس صفوفهما أونلاين «خاضعة» بداتا قديمة منحرفة فحماها صمّام الشفاء الأول — حذف بالاسم بأمره الصريح مع النسخ الاحتياطي (2026-08-27)
healBecharaCw20260827(); // شفاء ذاتي مرّة واحدة: متعاقدو وموظفو البشارة على كشفه (11/11 مطابقون بالمليم) — حذف غير الخاضعين الأربعة الزائدين بنمط النجاة/عبرا الذي قرّره («شوف وصحح» 2026-08-27)
healEntikalCw20260827(); // شفاء ذاتي مرّة واحدة: متعاقدو وموظفو الانتقال-زحلة على كشفه — 5 متعاقدين كانوا «ملاك» خطأً (الماس فرح/سمير اعزان/فاهمة سابا/لور مهنا/نوها سيده) يُحوَّلون ويُضبطون بقيم الكشف + حذف كلاريتا وبول غير الخاضعين («المتعاقد والموظف صحح» 2026-08-27)
healNiyahCw20260827(); // شفاء ذاتي مرّة واحدة: متعاقدو وموظفو النياح-زحلة على كشفه — بياره بوزيدان كانت «ملاك» خطأً تُحوَّل بقيم الكشف + حذف الزائدين الخمسة غير الخاضعين («تصحيح المتعاقد والموظف» 2026-08-27)
healKsaraCw20260827(); // شفاء ذاتي مرّة واحدة: موظفو دار السعادة-كسارة على كشفه (8/8 مطابقون بالمليم) — حذف الزائدين غير الخاضعين الـ12 بالنمط المقرَّر ومنهم ملفا جان عاد والياس سمعان اليتيم («شوف وصحح» 2026-08-27)
healEntikalCw2_20260827(); // نسخة ثانية: كلاريتا مساعد (الانتقال) صفوفها أونلاين «خاضعة» بداتا منحرفة فحماها الصمّام — حذف بالاسم بالنمط المقرَّر (نمط بلال بعبرا، 2026-08-27)
healTiaPercentPrime20260828(); // شفاء ذاتي مرّة واحدة: تيا نخلة (البشارة) — الإضافي من رقم جامد غلط (48,060,000) إلى نسبة 45٪ بقاعدة ÷1500 متل رفقاتها + إعادة حساب سنتها (شرح المستخدم بمثالها 2026-08-28)
healTiaPercent2_20260828(); // نسخة مشخِّصة: تقرأ حالة تيا الفعلية أونلاين (البند/السعر/ناتج القاعدة/المخزّن) وتعيد الحساب إن كان المخزّن ≠ القاعدة (opcache لحظة الشفاء الأول) وتسجّل كل شيء بالفلاغ (2026-08-28)
healTiaPercent3_20260828(); // النسخة الحاسمة: أونلاين تيا بلا بند علاوة إطلاقاً (bonus=[] وأشهرها بإضافي 0) — تخلق بند النسبة 45٪ إن غاب أو توحّده، وتعيد حساب سنتها وتسجّل قبل/بعد (2026-08-28)
healPercentLawAll20260828(); // ⚖️ «طبق القانون على الجميع» (أمره 2026-08-28): ملاك المدارس ذات النسبة الموحّدة يتحوّلون لنسبتهم المستنتَجة من رقمهم المخزّن نفسه (بلا تغيير اليوم، يتحرّك مع الدرجة لاحقاً) — دفعات + نسخ احتياطية؛ المدارس بلا نسبة والمتعاقدون والمؤرّخة تبقى مبالغ («اذا حاطين هني المبلغ بيبقى»)
healPrimeSnapshotSync20260828();
healPercentLawOwn20260903();
healSamerAbounader20260912(); // 🧑‍🏫 سامر ابونادر (عبرا 840): مسح تواريخ الترك المستحيلة (= ولادته) + إضافي 85٪ + نقل 7,200,000 + حساب 2026-2027 — مرّة واحدة (2026-09-12 «ما عم شوفو»)
healSamerAllYears20260912(); // 🧑‍🏫 سامر — الجزء الثاني («بدو يكون موجود بكل البرنامج»): كل السنين من دخوله 2014 حتى 2025-2026 كرفاقه (65٪ + نقل 7,200,000 بـ2025-2026) + تدرّج تشرين 2026 + حذف ناعم للمكرّرَين الفارغين بقراره — مرّة واحدة
healNssfFromSistersList20261013(); // 🩺 (2026-10-13) أرقام الضمان من لائحة الراهبات 25-82-043: تعبئة 45 + تصحيح 17 بخانة nssf_number فقط، نسخة _bk_nssf_20261013 — مرّة واحدة
healNssfSaadeh20261013(); // 🩺 (2026-10-13) دار السعادة كسارة: رقما ضمان من لائحتها — مرّة واحدة
healNssfMaximos20261013(); // 🩺 (2026-10-13) القديس مكسيموس: 4 أرقام ضمان من لائحتها — مرّة واحدة
healAbraPct65_20260910(); // ⚖️ «أكيد كلهم 65» (2026-09-10): كل ملاك عبرا على نسبة 65٪ (التسعة الباقون) + إعادة حساب 2025-2027 — مرّة واحدة بنسخ احتياطية
healDuplicatePercent20260904(); // 🩹 نسبة مكرّرة فاعلة بنفس السنة (أنطوني جبور 110 %) — تُطفأ ويُعاد الحساب، عند كل فتح
healFutureYearConsistency20260904(); // 🩹 بنود بمبلغ صفر تُطفأ + أشهر السنوات المشتقّة (2026-2027+) التي لا تطابق بنودها الثابتة يُعاد حسابها (مارسيلا داود) — مرّة واحدة
healPrimeUsdLaw20260903();
healScale47_20260903(); healNetFloor1000_20260904(); // 🔴 الصافي داون للألف بكل السنوات (قراره 2026-09-04) // 📜 تصحيح الدرجات 47/49/50 بجدول السلسلة 2017 طبق الجريدة الرسمية (150,000 لا 145,000) + إعادة حساب المعنيين 2025-2027 (2026-09-03) // 🧮 تعبئة عمود دولار القانون للإضافي (prime_fixe_usd_law) للرواتب المخزّنة لأصحاب النسبة — دفعات (2026-09-03 «صحّح النسبة بكل التقارير والإفادات») // 🧮 «طبّق النسبة المئوية اللي بتطلع صح» (2026-09-03): الملاك الباقون بمبلغ ثابت بمدارس النسبة → نسبة المدرسة إن كان الفرق فراطات (<5م) وإلا نسبتهم الخاصة التي تُخرج رقمهم — دفعات + نسخ _bk_bonuses_pctown0903/_ms_bk_pctown0903 // 🔄 مزامنة بنود الإضافي من لقطة الكمبيوتر (المصدر الصحيح): ملاك البشارة وغيرهم أونلاين كانوا بلا بنود وإضافيهم 0 — خلق/توحيد البند وإعادة حساب سنته، بدفعات، ويتخطّى السليم والمحميّين (2026-08-28)
healChiraTaalimiya20260829(); // 🎓 شيرا العاقوري (البشارة): «عندها إجازة تعليمية» — شهادتها كانت فاضية فطلعت درجة 18 والإضافي رقماً يعوّض؛ صارت إجازة تعليمية → درجة 31 حسب القانون + إضافي 45٪ + إعادة حساب سنتها (2026-08-29)
healMariaMalak20260829(); // 🎓 ماريا اسعد (البشارة): «الملاك = دخول المدرسة» → درجة 39 بالقانون + «45٪ مرّة وحدة» = بند واحد (كان مزدوجاً أونلاين: نسبة + مبلغ = 181م) + إطفاء أي ازدواج مماثل عند غيرها (2026-08-29)
healGladisGhostPrime20260829(); // 👻 كلاديس الصباغ (البشارة): إضافي 751م عالق بالأشهر بلا سطر بملفها — تصفير عبر سطر مطفأ + إعادة الحساب، وتسجيل أي أشباح مماثلة (2026-08-29)
healRestoreZeroedRows20260829(); // 🚑 استرجاع رواتب 2025-2026 المصفَّرة أونلاين (20-08: المحرّك الكامل صفّر أساس المنقولين بلا إعداد) من اللقطة المحلية المدقَّقة + أسطر إضافي البشارة الناقصة + غادة/ابتسام 45٪ (2026-08-29)
healPostDepartureOrphans20260829(); // 🧹 رواتب بعد شهر الترك ضمن السنة + صفوف يتيمة للمحذوفين (الفحص الرسمي 2026-08-29، بنسخ)
healDiplomaFromOldProgram20260829(); // 🎓 شهادات 15 أستاذاً ملاكاً كانت فاضية — من برنامجه القديم (امتياز فني = ت.س): 6 يطابق القانون بها كشفَه بالمليم (+ نسبة المدرسة) و9 بلا رواتب (ضبط الملف) (2026-08-29)
healNiyahCw3_20260829(); // 🧹 النياح ٣: اسبر منصور (مكرّر مع الانتقال) + ندى باصيل + زويا سمعان — زائدون غير خاضعين بكشفه، تخطّاهم الشفاء الأول أونلاين (2026-08-29)
healDedup2526_20260902(); // 🧹 المكرّرون (أمره 2026-09-02): نفس الاسم/الأب/الشهرة بنفس المدرسة = ملف واحد يبقى (صاحب رواتب 2025-2026)؛ رواتب الزائد تُدمَج تحته وملفه حذف ناعم + إطفاء بنوده — مستثنيان: جوزيف ابي عيد وجان عاد (قراره)
healChildrenSplit20260910(); // ⚖️ قانون تقاسم تنزيل الأولاد بين الزوجين العاملين (2026-09-10): إعادة حساب من أشهره مخزّنة بالحصة الكاملة (جوزيف حليحل) — مرّة واحدة
ensureFamilyAllowanceDateColumns(); // 👨‍👩‍👧 تركيب ذاتي: عمودا مدّة التعويض العائلي «من شهر ← إلى شهر» (2026-09-20)
healFamilyAllowanceFrom20260920(); // 👨‍👩‍👧 شفاء مرّة واحدة: مبلغ تعويض بلا «من شهر» (طانيوس طنوس/عبرا) ⇒ بداية + إعادة حساب فيظهر بالبطاقة (2026-09-20)
healCarriedMissingMonths20260920(); // 📅 شفاء مرّة واحدة: منقول صار «12 شهراً» وسنته بـ10 ⇒ استكمال آب/أيلول نسخةً عن آخر شهر (طانيوس «شوف آب وأيلول» 2026-09-20)
healPaymentMonths12_20260920(); // 📆 السنة الدراسية ت1 ← أيلول للجميع: الكل 12 شهراً + استكمال الأشهر الناقصة بالسنة الجارية واللاحقة بدفعات (2026-09-20)
healAbraContractUsd20260920(); // 💵 مرّة واحدة: 16 متعاقداً بعبرا — أساس الراتب بالدولار من إكسل «متعاقد عبرا 2026-2027» + إطفاء إضافيهم 2026-2027 (2026-09-20)
healAbraContractUsd2_20260920(); // 💵 مرّة واحدة: تعديله للإكسل نفسه — 7 مبالغ مدوَّرة (500/700/670/815/860/460/700) + إعادة حساب 2026-2027 (2026-09-20 «رجاع عدّل»)
healAbraContractNet20260921(); // 💵 مرّة واحدة: إكسله «متعاقد عبرا» = الصافي بعد المحسومات ⇒ الأساس بالدولار (بالسنت) = أصغر أساس يعطي هذا الصافي بالمحرّك بكل أشهر 2026-2027 (2026-09-21 «بدي ياهن رواتب صافية بعد المحسومات»)
healNewHiresNoRows20260909(); // 🆕 المتعاقد/الموظف الجديد (من 2026-2027) بلا أساس بالإعداد ومعه علاوة/نقل وبلا أي راتب مخزّن → يُحسب من ملفه (ريتا بو عاصي 2026-09-09 «ما عم ببين رواتب بالبطاقة») — عند كل فتح
healDedupFill20260902(); // 🧩 تكملة الدمج: خانات الهوية الفاضية عند الملف الباقي (أرقام الصندوق/الضمان/المالية، الأم، المواليد، الهاتف...) تُستكمَل من نسخة الملف المُزال — بلا لمس حقول الحساب (2026-09-02)

$currentPage = $currentPage ?? '';
$pageTitle = $pageTitle ?? 'MSA Payroll';
$lang = $_SESSION['lang'] ?? 'fr';
require_once __DIR__ . '/todo.php'; require_once __DIR__ . '/legal.php'; // ⚖️ v2026: الرزنامة القانونية + قفل «انبعت للدولة» // 🔔 v2026: الجرس بالشريط العلوي = نفس لائحة «شو لازم تعمل» (كاش جلسة 120 ثانية)
$msaBell = []; try { if (empty($docFocus)) $msaBell = msaTodoItems(getDB()); } catch (Throwable $e) { $msaBell = []; }
$msaBellN = 0; foreach ($msaBell as $mb) $msaBellN += (int)$mb[2];

// 🎨 لون + أيقونة مميّزة لكل قسم من البرنامج (تُطبَّق على العنوان وبادجات رؤوس البطاقات)
$sectionColors = [
    'dashboard' => ['#0891b2', 'rgba(8,145,178,.16)'],   // لوحة القيادة — تركوازي
    'personnel' => ['#0284c7', 'rgba(2,132,199,.16)'],   // الموظفون — أزرق
    'paie'      => ['#16a34a', 'rgba(22,163,74,.16)'],   // الرواتب — أخضر
    'rapports'  => ['#7c3aed', 'rgba(124,58,237,.16)'],  // التقارير — بنفسجي
    'systeme'   => ['#0f766e', 'rgba(15,118,110,.16)'],   // النظام — تركواز غامق (🎨 2026-10-19 «شيل الأحمر والأورانج من الأيقونات» — كان برتقالياً)
];
$sectionIcons = ['dashboard'=>'fa-gauge-high','personnel'=>'fa-users','paie'=>'fa-money-check-dollar','rapports'=>'fa-chart-column','systeme'=>'fa-gear'];
$pageSection = [
    'dashboard'=>'dashboard',
    'employees'=>'personnel','cadre_due'=>'personnel','grades'=>'personnel','classes'=>'personnel','exceptional_laws'=>'personnel','bulk_allowances'=>'personnel','family_allowances'=>'personnel','employee_full_history'=>'personnel','excel_salaries'=>'personnel','law_check'=>'personnel','duplicates'=>'personnel','old_uninsured'=>'personnel','cnss_followup'=>'personnel',
    'monthly'=>'paie','annual'=>'paie','attestations'=>'paie','employee_history'=>'paie','info_collect'=>'paie','info_status'=>'paie','left_teachers'=>'paie','retirement_64'=>'paie','hours_reduction'=>'paie',
    'reports'=>'rapports','tax'=>'rapports','legal_calendar'=>'rapports',
    'schools'=>'systeme','users'=>'systeme','school_access'=>'systeme','open_year'=>'systeme','rates'=>'systeme','social_security'=>'systeme','tax_brackets'=>'systeme','rates_history'=>'systeme','salary_scales'=>'systeme','backup'=>'systeme','settings'=>'systeme','email_settings'=>'systeme','health_check'=>'systeme',
];
$sec = $pageSection[$currentPage] ?? 'dashboard';
[$accentColor, $accentBg] = $sectionColors[$sec];
$secIcon = $sectionIcons[$sec] ?? 'fa-gauge-high';
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'ar' ? 'ar' : 'fr' ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(currentSchoolName()) ?></title>
    
    <!-- Fonts: محلية بالكامل (استقلال البرنامج + توليد PDF بالمتصفح يحتاج خطوطاً بنفس الأصل) -->
    <link href="<?= BASE_URL ?>assets/fonts/fonts.css?v=<?= @filemtime(__DIR__ . '/../assets/fonts/fonts.css') ?: '1' ?>" rel="stylesheet">

    <!-- Icons: Font Awesome محلي -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fontawesome/css/all.min.css">
    
    <!-- Favicon + تطبيق الهاتف (PWA): manifest + أيقونة الشاشة الرئيسية + عامل الخدمة -->
    <link rel="icon" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <link rel="manifest" href="<?= BASE_URL ?>manifest.php<?= (isViewer() && !empty($_SESSION['username'])) ? '?u=' . rawurlencode((string)$_SESSION['username']) : '' ?>"><!-- 🏫 (2026-10-22) حساب مدرسة ⇒ بيان/أيقونة باسم مدرسته -->

    <meta name="theme-color" content="#0a2240">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="MSA Payroll">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('<?= BASE_URL ?>sw.js').catch(function () {});</script>

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: '1' ?>">
</head>
<body class="<?= $lang === 'ar' ? 'rtl' : '' ?><?= !empty($docFocus) ? ' doc-view' : '' ?><?= $currentPage !== '' ? ' page-' . e(preg_replace('/[^a-z0-9_-]/', '', $currentPage)) : '' ?>" data-totals-rowsum="<?= function_exists('totalsRowSum') && totalsRowSum() ? '1' : '0' ?>" data-totals-group="<?= function_exists('totalsGroup') && totalsGroup() ? '1' : '0' ?>" data-totals-cols="<?= !function_exists('totalsCols') || totalsCols() ? '1' : '0' ?>">
<script>/* v2026: القائمة الجانبية مفتوحة بعناوينها دائماً (طلبه: «العامود اللي عالشمال اللي فيه العناوين يضلّ ظاهر») — Ctrl+B/الدبّوس يطويها لشريط أيقونات */try{if(localStorage.getItem('msa_nav_pinned')!=='0')document.body.classList.add('nav-pinned');}catch(e){}
/* 🧭 (2026-10-16 p1 «التبويب والديزاين متل Namecheap»): شريط تبويبات علوي بقوائم منسدلة لكل باب يحلّ محلّ العامود الجانبي؛ msa_side=1 يرجّع العامود (كبسة ☰ بآخر الشريط) */
try{if(localStorage.getItem('msa_side')==='1')document.body.classList.add('msa-side');}catch(e){}</script>

<?php if (!empty($_SESSION['_impersonator'])): // 👁️ (2026-10-31) وضع المعاينة «كما تراه المدرسة»: شريط ثابت + رجوع للمدير (key_preview.php) ?>
<div id="kaPreviewBar" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#b45309;color:#fff;font:600 14px/1.3 'Segoe UI',Tahoma,sans-serif;padding:8px 14px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.25)">
    <span><i class="fas fa-eye"></i> Aperçu : vous voyez le programme exactement comme « <?= e((string)($_SESSION['username'] ?? '')) ?> » (lecture seule — rien ne peut être modifié)</span>
    <span dir="rtl">معاينة: عم تشوف البرنامج تماماً كما يراه حساب «<?= e((string)($_SESSION['username'] ?? '')) ?>» (قراءة فقط — ما في شي بينعدّل)</span>
    <a href="<?= BASE_URL ?>key_preview.php?back=1" style="background:#fff;color:#b45309;border-radius:8px;padding:5px 12px;text-decoration:none;font-weight:700"><i class="fas fa-arrow-right-from-bracket"></i> Retour administrateur / رجوع للمدير</a>
</div>
<style>body{padding-top:44px!important}</style>
<?php endif; ?>
<!-- حماية CSRF: حقن توكن تلقائياً بكل نماذج POST -->
<script>
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.method && f.method.toLowerCase() === 'post' && !f.querySelector('input[name="csrf"]')) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = 'csrf'; i.value = '<?= csrfToken() ?>';
        f.appendChild(i);
    }
}, true);
// 🔁 (2026-09-28 p1 «رحت على ملف بيرلا وحطّيت الإضافي… عملت حفظ صار يفتل وعطاني can't reach this page» + «ما رح أعمل تجارب — البرنامج كلو صح»):
//    الاستضافة تُسقط اتصالات متقطّعة (ERR_CONNECTION_TIMED_OUT حتى للملفات الثابتة). كل نموذج POST يُرسَل بـfetch مع إعادة محاولة تلقائية
//    (حتى 6 مرّات) عند فشل الاتصال، مع req_id فريد لكل إرسال (الخادم يتجاهل التكرار — database.php). لا يمسّ النماذج التي تفتح نافذة/تبويباً
//    (target) ولا data-no-retry. الردّ: إعادة توجيه ⇒ ننتقل إليها، وإلا نعرض الصفحة المرجَعة كما هي.
window.msaPostRetry = { max: 6, delay: 2500 };
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || e.defaultPrevented || !window.fetch || !window.FormData) return;
    if (!f.method || f.method.toLowerCase() !== 'post') return;
    if ((f.getAttribute('target') || '') !== '' || f.hasAttribute('data-no-retry') || f._msaRetrySubmitting) return;
    e.preventDefault();
    var fd = new FormData(f);
    var sub = e.submitter;
    if (sub && sub.name) fd.append(sub.name, sub.value || '');
    if (!fd.has('csrf')) fd.append('csrf', '<?= csrfToken() ?>');
    var rid = 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    fd.append('req_id', rid);
    var url = f.getAttribute('action') || window.location.href;
    var ov = document.getElementById('msaBusyOverlay');
    if (!ov) {
        ov = document.createElement('div'); ov.id = 'msaBusyOverlay';
        ov.setAttribute('style', 'position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;font:700 18px Arial,sans-serif;color:#1F4E5F');
        document.body.appendChild(ov);
    }
    var box = function (t) { ov.innerHTML = '<div style="background:#fff;border:2px solid #1F4E5F;border-radius:12px;padding:16px 28px;box-shadow:0 8px 30px rgba(0,0,0,.15)">' + t + '</div>'; ov.style.display = 'flex'; };
    box('⏳ جارٍ الحفظ… / Enregistrement…');
    f._msaRetrySubmitting = true;
    var attempt = 0;
    var go = function () {
        attempt++;
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow', cache: 'no-store' }).then(function (r) {
            return r.text().then(function (html) {
                f._msaRetrySubmitting = false;
                // 🐛 (2026-10-31 «عملت هيك وما شفت الأيقونة بالدونلود»): بعد إعادة التوجيه كنّا نطلب الصفحة مرّة ثانية (location.href) فتضيع
                //    رسائل الجلسة لمرّة واحدة (flash، صندوق تنزيل الأيقونة مع نقرته التلقائية…) لأنّ fetch استهلكها بالطلب الأوّل ⇒ نعرض الصفحة
                //    التي رجعت فعلاً (كما بالردّ غير المُعاد توجيهه) ونصحّح العنوان. بلا محتوى ⇒ انتقال عادي.
                if (r.redirected && !(html && html.length > 200)) { window.location.href = r.url; return; }
                try { window.history.replaceState(null, '', r.url || url); } catch (x) {}
                document.open(); document.write(html); document.close();
            });
        }).catch(function () {
            if (attempt < window.msaPostRetry.max) {
                box('📡 انقطع الاتصال بالخادم — إعادة المحاولة تلقائياً (' + (attempt + 1) + '/' + window.msaPostRetry.max + ')… لا تغلق الصفحة<br><span style="font-size:13px;font-weight:400">Connexion perdue — nouvelle tentative automatique</span>');
                setTimeout(go, window.msaPostRetry.delay);
            } else {
                f._msaRetrySubmitting = false;
                box('⚠️ تعذّر الوصول للخادم بعد ' + attempt + ' محاولات — ما انحفظ شي. <a href="#" onclick="location.reload();return false" style="color:#1F4E5F">أعد المحاولة</a>');
            }
        });
    };
    go();
}, false);
// 📌 «بدي ما تنطّ الصفحة على مكان آخر وأنا عم اشتغل فيها — إذا صلّحت شي لازم تضلّ بنفس الصفحة، بكل البرنامج» (أمره 2026-09-13):
// عند إرسال أي نموذج POST نحفظ موضع التمرير والأقسام المفتوحة (details) لهذه الصفحة، وبعد الرجوع (إعادة التوجيه) نرجّعها كما كانت.
(function () {
    var key = 'msa_stay:' + location.pathname;
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.method || f.method.toLowerCase() !== 'post') return;
        try {
            var open = []; var ds = document.querySelectorAll('details');
            for (var i = 0; i < ds.length; i++) if (ds[i].open) open.push(i);
            sessionStorage.setItem(key, JSON.stringify({ y: window.scrollY || window.pageYOffset || 0, open: open, t: Date.now() }));
        } catch (err) {}
    }, true);
    function restore() {
        var raw = null;
        try { raw = sessionStorage.getItem(key); if (raw !== null) sessionStorage.removeItem(key); } catch (err) { return; }
        if (!raw) return;
        var s; try { s = JSON.parse(raw); } catch (err) { return; }
        if (!s || (Date.now() - (s.t || 0)) > 120000) return; // حفظ قديم (تنقّل عادي) — تجاهله
        var ds = document.querySelectorAll('details');
        if (s.open) for (var i = 0; i < s.open.length; i++) if (ds[s.open[i]]) ds[s.open[i]].open = true;
        if (location.hash) { try { history.replaceState(null, '', location.pathname + location.search); } catch (err) {} }
        var y = parseInt(s.y, 10) || 0;
        window.scrollTo(0, y);
        setTimeout(function () { window.scrollTo(0, y); }, 60);
        setTimeout(function () { window.scrollTo(0, y); }, 300);
    }
    if (document.readyState === 'complete') restore(); else window.addEventListener('load', restore);
})();
</script>

<div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="brand-logo"><img src="<?= BASE_URL ?>assets/img/logo_mark.png" alt="" width="42" height="42"></span><!-- 🖼️ (2026-10-18 «حطّه») شعار البرنامج: الكتاب وكشف الراتب — logo_mark بلا الكلمة بالأماكن الصغيرة -->
            <div>
                <h2>MSA Payroll</h2>
                <p><?= e(currentSchoolName()) ?></p>
            </div>
        </div>

        <!-- بحث سريع بالقائمة (تصفية فورية) -->
        <div class="sidebar-search no-print">
            <div class="ss-wrap">
                <i class="fas fa-magnifying-glass ss-ic"></i>
                <input type="text" id="navFilter" placeholder="Rechercher / بحث..." autocomplete="off"
                       oninput="var q=this.value.trim().toLowerCase();document.querySelectorAll('.sidebar-nav a').forEach(function(a){a.style.display=(!q||a.textContent.toLowerCase().indexOf(q)>-1)?'':'none'});document.querySelectorAll('.sidebar-nav .nav-section').forEach(function(s){s.style.display=q?'none':''});">
            </div>
        </div>

        <?php ob_start(); // 🧭 (2026-10-14 «العربي مكتوب تحت الفرنسي مش على جانبو») كل اسم بالقائمة الجانبية يُقسَم سطرَين: الفرنسي فوق والعربي تحت ?>
        <nav class="sidebar-nav">
            <a href="<?= BASE_URL ?>index.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i>
                <span>Tableau de bord / لوحة القيادة</span>
            </a>
            
            <?php if (canEdit()): ?>
            <div class="nav-section" style="--ns:#38bdf8">Personnel / الموظفون</div>

            <a href="<?= BASE_URL ?>pages/employees.php" class="<?= $currentPage === 'employees' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Employés & Enseignants / الموظفون والأساتذة</span>
            </a>

            <?php $tsPend = taxSuggestionsPendingCount(); // 💡 «لازم يضوي بالبرنامج» (2026-08-23): الإشارة تضوي ما دام في اقتراحات معلّقة ?>
            <a href="<?= BASE_URL ?>pages/tax_suggestions.php" class="<?= $currentPage === 'tax_suggestions' ? 'active' : '' ?>">
                <i class="fas fa-lightbulb" <?= $tsPend ? 'style="color:#0e7490"' : '' ?>></i>
                <span>Suggestions état civil / اقتراحات من إخراج القيد</span>
                <?php if ($tsPend): ?><span style="background:#173f6f;color:#fff;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:800;margin-inline-start:6px;animation:pulse 1.6s infinite"><?= $tsPend ?></span><?php endif; ?>
            </a>

            <?php require_once __DIR__ . '/compliance.php'; $cpPend = compliancePendingCount(); // ⚖️ (2026-09-04) شارة المخالفات المعلّقة بانتظار قراره ?>
            <a href="<?= BASE_URL ?>pages/compliance.php" class="<?= $currentPage === 'compliance' ? 'active' : '' ?>">
                <i class="fas fa-scale-balanced" <?= $cpPend ? 'style="color:#173f6f"' : '' ?>></i>
                <span>Rapport de conformité / تقرير المخالفات والتصحيحات</span>
                <?php if ($cpPend): ?><span style="background:#173f6f;color:#fff;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:800;margin-inline-start:6px;animation:pulse 1.6s infinite"><?= $cpPend ?></span><?php endif; ?>
            </a>

            <?php $cdNavPend = cadreDuePendingCount(); // 🎓 (2026-09-19) شارة اقتراحات الدخول بالملاك بعد سنتين — بانتظار موافقته ?>
            <a href="<?= BASE_URL ?>pages/cadre_due.php" class="<?= $currentPage === 'cadre_due' ? 'active' : '' ?>">
                <i class="fas fa-graduation-cap" <?= $cdNavPend ? 'style="color:#7c3aed"' : '' ?>></i>
                <span>Propositions de titularisation / اقتراحات الدخول بالملاك</span>
                <?php if ($cdNavPend): ?><span style="background:#6d28d9;color:#fff;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:800;margin-inline-start:6px;animation:pulse 1.6s infinite"><?= $cdNavPend ?></span><?php endif; ?>
            </a>

            <a href="<?= BASE_URL ?>pages/grades.php" class="<?= $currentPage === 'grades' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i>
                <span>Échelons & Promotions / الدرجات والترقيات</span>
            </a>

            <a href="<?= BASE_URL ?>pages/classes.php" class="<?= $currentPage === 'classes' ? 'active' : '' ?>">
                <i class="fas fa-chalkboard"></i>
                <span>Classes / الصفوف</span>
            </a>

            <a href="<?= BASE_URL ?>pages/exceptional_laws.php" class="<?= $currentPage === 'exceptional_laws' ? 'active' : '' ?>">
                <i class="fas fa-scroll"></i>
                <span>Lois exceptionnelles / القوانين الاستثنائية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/bulk_allowances.php" class="<?= $currentPage === 'bulk_allowances' ? 'active' : '' ?>">
                <i class="fas fa-gift"></i>
                <span>Primes & transport / المكافآت والنقل</span>
            </a>

            <a href="<?= BASE_URL ?>pages/family_allowances.php" class="<?= $currentPage === 'family_allowances' ? 'active' : '' ?>">
                <i class="fas fa-people-roof"></i>
                <span>Allocations familiales / التعويض العائلي</span>
            </a>

            <a href="<?= BASE_URL ?>pages/employee_full_history.php" class="<?= $currentPage === 'employee_full_history' ? 'active' : '' ?>">
                <i class="fas fa-clock-rotate-left"></i>
                <span>Historique complet / التاريخ الكامل للأستاذ</span>
            </a>

            <a href="<?= BASE_URL ?>pages/excel_salaries.php" class="<?= $currentPage === 'excel_salaries' ? 'active' : '' ?>">
                <i class="fas fa-file-excel"></i>
                <span>Excel fiches & salaires / إكسل ملفات الموظفين والرواتب</span>
            </a>

            <a href="<?= BASE_URL ?>pages/law_check.php" class="<?= $currentPage === 'law_check' ? 'active' : '' ?>">
                <i class="fas fa-balance-scale"></i>
                <span>Conformité légale / فحص مطابقة القانون</span>
            </a>
            <a href="<?= BASE_URL ?>pages/cnss_followup.php" class="<?= $currentPage === 'cnss_followup' ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i>
                <span>Suivi CNSS / متابعة الضمان</span>
            </a>
            <?php endif; ?>

            <div class="nav-section" style="--ns:#4ade80">Paie / الرواتب</div>

            <?php if (viewerCanSeePage('monthly_payroll.php')): ?>
            <a href="<?= BASE_URL ?>pages/monthly_payroll.php" class="<?= $currentPage === 'monthly' ? 'active' : '' ?>">
                <i class="fas fa-money-check-alt"></i>
                <span>Paie mensuelle / الرواتب الشهرية</span>
            </a>
            <?php endif; ?>

            <?php if (viewerCanSeePage('annual_slip.php')): ?>
            <a href="<?= BASE_URL ?>pages/annual_slip.php" class="<?= $currentPage === 'annual' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Relevé annuel / الكشف السنوي</span>
            </a>
            <?php endif; ?>

            <?php if (viewerCanSeePage('attestations.php')): ?>
            <a href="<?= BASE_URL ?>pages/attestations.php" class="<?= $currentPage === 'attestations' ? 'active' : '' ?>">
                <i class="fas fa-file-signature"></i>
                <span>Attestations / إفادات</span>
            </a>
            <?php endif; ?>

            <?php if (viewerCanSeePage('employee_history.php')): ?>
            <a href="<?= BASE_URL ?>pages/employee_history.php" class="<?= $currentPage === 'employee_history' ? 'active' : '' ?>">
                <i class="fas fa-user-clock"></i>
                <span>Dossier enseignant / سيرة الأستاذ</span>
            </a>
            <?php endif; ?>
            <?php if (canEdit()): ?>
            <a href="<?= BASE_URL ?>pages/info_collect.php" class="<?= $currentPage === 'info_collect' ? 'active' : '' ?>">
                <i class="fab fa-whatsapp"></i>
                <span>Mise à jour / تحديث معلومات الأساتذة</span>
            </a>
            <a href="<?= BASE_URL ?>pages/info_status.php" class="<?= $currentPage === 'info_status' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-check"></i>
                <span>État MàJ / حالة التحديث (مين بعت)</span>
            </a>
            <a href="<?= BASE_URL ?>pages/left_teachers.php" class="<?= $currentPage === 'left_teachers' ? 'active' : '' ?>">
                <i class="fas fa-user-slash"></i>
                <span>Départs / الأساتذة التاركون</span>
            </a>
            <a href="<?= BASE_URL ?>pages/retirement_64.php" class="<?= $currentPage === 'retirement_64' ? 'active' : '' ?>">
                <i class="fas fa-hourglass-half"></i>
                <span>Retraite 64 / بلوغ سنّ الـ64</span>
            </a>
            <a href="<?= BASE_URL ?>pages/hours_reduction.php" class="<?= $currentPage === 'hours_reduction' ? 'active' : '' ?>">
                <i class="fas fa-clock"></i>
                <span>Réduction d'horaire / تناقص ساعات التدريس</span>
            </a>
            <?php endif; ?>

            <div class="nav-section" style="--ns:#c4b5fd">Rapports / التقارير</div>

            <?php if (viewerCanSeePage('reports.php')): ?>
            <a href="<?= BASE_URL ?>pages/reports.php" class="<?= $currentPage === 'reports' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports / التقارير</span>
            </a>
            <?php endif; ?>
            <?php if (canEdit()): /* ⚖️ v2026 الرزنامة القانونية: مواعيد الدولة + التقارير المبعوتة */ ?>
            <a href="<?= BASE_URL ?>pages/legal_calendar.php" class="<?= $currentPage === 'legal_calendar' ? 'active' : '' ?>">
                <i class="fas fa-landmark"></i>
                <span>Échéances État / مواعيد الدولة</span>
            </a>
            <?php endif; ?>
            <?php if (viewerCanSeePage('mehe_budget.php')): ?>
            <a href="<?= BASE_URL ?>pages/mehe_budget.php" class="<?= $currentPage === 'mehe_budget' ? 'active' : '' ?>">
                <i class="fas fa-landmark"></i>
                <span>Budget MEHE / موازنة وزارة التربية</span>
            </a>
            <?php endif; ?>

            <?php if (canEdit()): ?>
            <a href="<?= BASE_URL ?>pages/tax_declarations.php" class="<?= $currentPage === 'tax' ? 'active' : '' ?>">
                <i class="fas fa-file-contract"></i>
                <span>Déclarations / التصاريح</span>
            </a>
            <?php endif; ?>

            <div class="nav-section" style="--ns:#2dd4bf">Système / النظام</div><?php /* 🎨 2026-10-19 «شيل الأحمر والأورانج من الأيقونات»: كان أصفر #fbbf24 ⇒ تركواز */ ?>

            <?php if (isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/schools.php" class="<?= $currentPage === 'schools' ? 'active' : '' ?>">
                <i class="fas fa-school"></i>
                <span>Écoles / المدارس</span>
            </a>
            <?php endif; ?>

            <?php if (isAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/school_access.php" class="<?= $currentPage === 'school_access' ? 'active' : '' ?>">
                <i class="fas fa-school-lock"></i>
                <span>Accès écoles / دخول المدارس وأيقوناتها</span>
            </a>
            <a href="<?= BASE_URL ?>pages/users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i>
                <span>Utilisateurs / حسابات المدارس</span>
            </a>
            <?php endif; ?>

            <?php if (canEdit()): ?>
            <a href="<?= BASE_URL ?>pages/open_year.php" class="<?= $currentPage === 'open_year' ? 'active' : '' ?>">
                <i class="fas fa-folder-plus"></i>
                <span>Ouvrir année / فتح سنة دراسية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/exchange_rates.php" class="<?= $currentPage === 'rates' ? 'active' : '' ?>">
                <i class="fas fa-coins"></i>
                <span>Taux de change / أسعار الصرف</span>
            </a>

            <a href="<?= BASE_URL ?>pages/state_laws.php" class="<?= $currentPage === 'state_laws' ? 'active' : '' ?>">
                <i class="fas fa-scale-balanced"></i>
                <span>Lois de l'État / قوانين الدولة</span>
            </a>

            <a href="<?= BASE_URL ?>pages/social_security.php" class="<?= $currentPage === 'social_security' ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i>
                <span>Plafonds CNSS / حدود الضمان</span>
            </a>

            <a href="<?= BASE_URL ?>pages/tax_brackets.php" class="<?= $currentPage === 'tax_brackets' ? 'active' : '' ?>">
                <i class="fas fa-percent"></i>
                <span>Tranches d'impôt / الشطور الضريبية</span>
            </a>

            <a href="<?= BASE_URL ?>pages/rates_history.php" class="<?= $currentPage === 'rates_history' ? 'active' : '' ?>">
                <i class="fas fa-percent"></i>
                <span>Taux datés / النِّسَب حسب التاريخ</span>
            </a>

            <a href="<?= BASE_URL ?>pages/salary_scales.php" class="<?= $currentPage === 'salary_scales' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i>
                <span>Grille / إصدارات السلسلة</span>
            </a>

            <a href="<?= BASE_URL ?>pages/backup.php" class="<?= $currentPage === 'backup' ? 'active' : '' ?>">
                <i class="fas fa-database"></i>
                <span>Sauvegarde / نسخة احتياطية</span>
            </a>
            <?php endif; ?>

            <?php if (isAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/health_check.php" class="<?= $currentPage === 'health_check' ? 'active' : '' ?>">
                <i class="fas fa-stethoscope"></i>
                <span>Contrôle de santé / فحص صحّة البرنامج</span>
            </a>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>pages/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i>
                <span>Paramètres / الإعدادات</span>
            </a>
            <?php if (isSuperAdmin()): ?>
            <a href="<?= BASE_URL ?>pages/email_settings.php" class="<?= $currentPage === 'email_settings' ? 'active' : '' ?>">
                <i class="fas fa-envelope"></i>
                <span>Paramètres Email / إعدادات البريد</span>
            </a>
            <?php endif; ?>
            <?php if (isAdmin()): // 📬 (2026-10-19) سجلّ الإيميلات: كل رسالة وحالة وصولها الحقيقية ?>
            <a href="<?= BASE_URL ?>pages/mail_log.php" class="<?= $currentPage === 'mail_log' ? 'active' : '' ?>">
                <i class="fas fa-envelope-open-text"></i>
                <span>Suivi des e-mails / سجلّ الإيميلات</span>
            </a>
            <?php endif; ?>
        </nav>
        <?php
        $__nav = ob_get_clean();
        $__nav = preg_replace('~<span>([^<>]+?) / ([^<>]+?)</span>~u', '<span class="nl2"><span class="nl-fr">$1</span><span class="nl-ar">$2</span></span>', $__nav);
        $__nav = preg_replace('~(<div class="nav-section"[^>]*>)([^<>]+?) / ([^<>]+?)</div>~u', '$1<span class="nl-fr">$2</span><span class="nl-ar">$3</span></div>', $__nav);
        echo $__nav;
        ?>
        
        <div class="sidebar-footer">
            <button type="button" class="sb-pin no-print" onclick="window.msaNavPin&&msaNavPin()" title="Fixer / ثبّت القائمة (Ctrl+B)"><i class="fas fa-thumbtack"></i><span>Fixer / ثبّت (Ctrl+B)</span></button>
            <span class="sb-ver">v<?= APP_VERSION ?> • <?= date('Y') ?></span>
        </div>
    </aside>

    <!-- غطاء يغلق القائمة على الموبايل + إغلاق القوائم المنسدلة عند الكبس خارجها -->
    <div class="nav-overlay no-print" onclick="document.body.classList.remove('nav-open')"></div>
    <script>
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.school-menu.open').forEach(function (m) {
            if (!m.closest('.school-multi').contains(e.target)) m.classList.remove('open');
        });
        var bm = document.getElementById('bellMenu'); if (bm && bm.classList.contains('open') && !bm.closest('.bell-wrap').contains(e.target)) bm.classList.remove('open');
    });
    </script>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top bar -->
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
                <button type="button" class="menu-toggle no-print" title="Menu / القائمة"
                        onclick="document.body.classList.toggle('nav-open')">
                    <i class="fas fa-bars"></i>
                </button>
                <?php if ($currentPage !== 'dashboard'): ?>
                <?php
                // بوضع «عرض المستند» (تقرير/نموذج/إفادة): الرجوع يعيد **لنفس الصفحة اللي
                // كان فيها** المستخدم (docBackUrl) لا لحالة الفلاتر السابقة بالتاريخ.
                $docBackHref = !empty($docFocus) ? docBackUrl() : (!empty($backHref) ? (string)$backHref : ''); // $backHref: الصفحة تحدّد وجهة الرجوع (إفادة الأستاذ ⇒ ملف إفاداته، 2026-09-24)
                ?>
                <button type="button" class="btn btn-light no-print" title="رجوع / Retour"
                        onclick="if(window.msaRememberFwd)msaRememberFwd(); if(!(window.msaPrevPage&&msaPrevPage())){<?= $docBackHref !== '' ? "location.href='" . e($docBackHref) . "'" : "if(document.referrer&&history.length>1){history.back()}else{location.href='" . BASE_URL . "index.php'}" ?>}"
                        style="white-space:nowrap">
                    <i class="fas fa-arrow-right" style="color:#16a34a"></i> Retour / رجوع
                </button>
                <?php endif; ?>
                <?php /* ↩️ «إذا كنت بشي صفحة ورجعت للي قبلها يكون في كمان سهم حتى أرجع محل ما كنت» (2026-09-27): بعد كبسة «رجوع» يظهر هنا سهم يعيده
                         للصفحة اللي تركها **وبنفس موضع التمرير** (msaRememberFwd/msaFwdInit بـapp.js — يُخزَّن بالجلسة 30 دقيقة). مخفي ما لم يوجد محلّ للرجوع إليه. */ ?>
                <button type="button" class="btn btn-light no-print" id="msaFwdBtn" hidden title="Revenir où j'étais / ارجع لمحل ما كنت" style="white-space:nowrap" onclick="if(window.msaGoFwd)msaGoFwd()">
                    <i class="fas fa-arrow-left" style="color:#2563eb"></i> Revenir / لمحل ما كنت
                </button>
                <?php
                // عنوان الصفحة ثنائي اللغة: الفرنسي فوق والعربي تحت (يُكتشفان تلقائياً من $pageTitle
                // مهما كان ترتيبه — أي جزء فيه حروف عربية = السطر العربي، والباقي = الفرنسي).
                $ptParts = array_map('trim', explode(' / ', (string)$pageTitle));
                $ptFr = []; $ptAr = [];
                foreach ($ptParts as $ptp) { if ($ptp === '') continue; if (preg_match('/\p{Arabic}/u', $ptp)) $ptAr[] = $ptp; else $ptFr[] = $ptp; }
                ?>
                <div style="display:flex;align-items:center;gap:12px">
                    <span style="width:44px;height:44px;min-width:44px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:<?= $accentColor ?>;color:#fff"><i class="fas <?= $secIcon ?>" style="font-size:20px"></i></span>
                    <h1 style="line-height:1.15;margin:0">
                        <?php if ($ptFr): ?><span dir="ltr"><?= e(implode(' / ', $ptFr)) ?></span><?php endif; ?>
                        <?php if ($ptAr): ?><span style="display:block;font-size:0.62em;font-weight:600;opacity:0.92"><?= e(implode(' / ', $ptAr)) ?></span><?php endif; ?>
                    </h1>
                </div>
            </div>

            <!-- 🔍 البحث الشامل (Ctrl+K): أستاذ أو صفحة — من أي مكان بالبرنامج -->
            <?php $gsCanDossier = viewerCanSeePage('attestations.php'); ?>
            <div class="global-search no-print" id="globalSearch">
                <i class="fas fa-magnifying-glass gs-ic"></i>
                <input type="text" id="gsInput" placeholder="Recherche / بحث: اكتب أوّل حرف من اسم الأستاذ..." autocomplete="off">
                <span class="gs-kbd">Ctrl+K</span>
                <div class="gs-panel" id="gsPanel"></div>
            </div>

            <div class="topbar-actions">
                <?php if (viewerCanSeePage('attestations.php')): ?>
                <a href="<?= BASE_URL ?>pages/attestations.php?dossier=1" class="topbar-dossier no-print" title="ملف الأستاذ الكامل / Dossier complet de l'enseignant">
                    <span class="td-ic"><i class="fas fa-folder-open"></i></span>
                    <span class="td-txt"><span dir="ltr">Dossier</span><span>ملف الأستاذ الكامل</span></span>
                </a>
                <?php endif; ?>
                <?php
                // مبدّل المدارس: للمدير العام (كل المدارس)، ولحساب المدرسة متعدّد المدارس (ضمن مدارسه فقط)
                $isMultiViewer = isViewer() && count(viewerAllowedSchoolIds()) > 1;
                if (isSuperAdmin() || $isMultiViewer):
                    $navSchools = isSuperAdmin()
                        ? allSchools()
                        : array_values(array_filter(allSchools(false), fn($s) => in_array((int)$s['id'], viewerAllowedSchoolIds(), true)));
                    $activeIds = activeSchoolIds();
                    $isAllSel = $isMultiViewer ? (count($activeIds) === count($navSchools)) : empty($activeIds);
                    $selLabel = $isAllSel ? 'Toutes les écoles / كل المدارس'
                              : (count($activeIds)===1 ? schoolNameById($activeIds[0]) : count($activeIds).' écoles / مدارس');
                ?>
                <div class="school-switcher school-multi" id="schoolPicker">
                    <i class="fas fa-school" style="color:#fff;background:#0891b2;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <button type="button" class="sm-btn" onclick="var m=document.getElementById('schoolMenu');m.classList.toggle('open');if(m.classList.contains('open')){var c=m.querySelector('.sm-list input:checked');if(c&&!document.getElementById('smAll').checked){c.closest('label').scrollIntoView({block:'center'});}}" title="اكبس لاختيار المدرسة / المدارس — المشيَّكة ملوّنة بـ✓">
                        <?= e($selLabel) ?> <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="school-menu" id="schoolMenu">
                        <?php /* ☑️ (2026-10-03 «بس اختار كل المدارس يصير في تشاك مارك لكل مدرسة — بختار الكل وبرجع بكبس على المدرسة اللي بديش ياها»):
                                 «كل المدارس» تشيّك كل المدارس واحدة واحدة، فيشيل التشييك عن التي لا يريدها ثم «تطبيق». الكل مشيّك = كل المدارس. */ ?>
                        <form method="get" action="<?= BASE_URL ?>switch_school.php" onsubmit="var bs=this.querySelectorAll('input[name=\'schools[]\']'),on=0;bs.forEach(function(c){if(c.checked)on++;});if(on===bs.length||on===0){bs.forEach(function(c){c.disabled=true;});}">
                            <?php /* 🏫☑️ (2026-10-18 «بكل الصفحات… تشاك مارك… عدّة مدارس») المنتقي صار يظهر بالمستندات أيضاً ⇒ «تطبيق» يرجع لنفس الصفحة/المستند بالمدارس الجديدة (بلا وسائط المدارس والموظف القديمة — الموظف تابع لمدرسته) */
                                  $spQ = $_GET; foreach (['sch', 'sch_all', 'sch_set', 'schools', 'school', 'employee_id', 'emp', 'emp_id'] as $spk) unset($spQ[$spk]);
                                  $spBack = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . ($spQ ? '?' . http_build_query($spQ) : ''); ?>
                            <input type="hidden" name="back" value="<?= e($spBack) ?>">
                            <label class="sm-all"><input type="checkbox" id="smAll" <?= $isAllSel?'checked':'' ?> onclick="var v=this.checked;document.querySelectorAll('#schoolMenu input[name=\'schools[]\']').forEach(function(c){c.checked=v;})"> <strong>🏫 Toutes les écoles / كل المدارس</strong></label>
                            <div class="sm-list">
                            <?php foreach ($navSchools as $navS): ?>
                                <label><input type="checkbox" name="schools[]" value="<?= (int)$navS['id'] ?>" <?= ($isAllSel || in_array((int)$navS['id'],$activeIds,true))?'checked':'' ?> onclick="var bs=document.querySelectorAll('#schoolMenu input[name=\'schools[]\']'),on=0;bs.forEach(function(c){if(c.checked)on++;});document.getElementById('smAll').checked=(on===bs.length)"> <?= e($lang==='ar'?$navS['name_ar']:$navS['name_fr']) ?></label>
                            <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-check"></i> Appliquer / تطبيق</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (function_exists('isSchoolYearLocked') && currentSchoolId() > 0 && isSchoolYearLocked(currentSchoolId(), activeSchoolYear())): ?>
                <a href="<?= BASE_URL ?>pages/open_year.php#yearLocks" class="school-switcher" title="🔒 هذه السنة مقفولة لهذه المدرسة — الحسابات ما بتتغيّر (افتح القفل من صفحة فتح سنة دراسية)" style="background:#eef4fb;border:1px solid #b9cde6;color:#173f6f;font-weight:800;text-decoration:none;padding:4px 10px;border-radius:8px">🔒 مقفولة / Verrouillée</a>
                <?php endif; ?>
                <?php /* 📅 (2026-10-18 «السنين المدرسية مبيّنة فوق… تضلّها مبيّنة كيف ما طلعت ونزلت… واعمِلها غير لون تشوفها»): القائمة نفسها القديمة — بلون مميّز (أحمر) وخطّ أكبر،
                         والشريط العلوي ثابت عند التمرير (sticky بكل الصفحات وبالمستندات — قسم 515 بـapp.css). الاختيار يعيد نفس الصفحة بالسنة المختارة (switch_year يبدّل school_year بالرابط). */ ?>
                <form method="get" action="<?= BASE_URL ?>switch_year.php" class="school-switcher year-switcher" id="yearSwitcher" title="<?= $lang === 'ar' ? 'السنة الدراسية — اختر السنة والصفحة نفسها تنعاد فيها' : 'Année scolaire — la même page se recharge dans l\'année choisie' ?>">
                    <input type="hidden" name="back" value="<?= e(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . ($_GET ? '?' . http_build_query($_GET) : '')) ?>">
                    <i class="fas fa-calendar-alt ys-ic"></i>
                    <select name="school_year" onchange="this.form.submit()" class="form-control form-control-sm">
                        <?php
                        $aSY = activeSchoolYear();
                        $cyH = (int)date('Y'); $cmH = (int)date('n'); $startH = ($cmH >= 10) ? $cyH : $cyH - 1;
                        ?>
                        <option value="all" <?= $aSY === 'all' ? 'selected' : '' ?>>📅 Toutes années / كل السنين</option>
                        <?php for ($yh = $startH + 1; $yh >= 2006; $yh--): $syH = $yh . '-' . ($yh + 1); ?>
                            <option value="<?= $syH ?>" <?= $syH === $aSY ? 'selected' : '' ?>><?= $syH ?></option>
                        <?php endfor; ?>
                    </select>
                </form>

                <form method="get" action="<?= BASE_URL ?>switch_currency.php" class="school-switcher" title="<?= $lang === 'ar' ? 'عرض المبالغ' : 'Affichage des montants' ?>">
                    <i class="fas fa-money-bill-wave" style="color:#fff;background:#059669;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <?php $dispCur = displayCurrency(); ?>
                    <select name="currency" onchange="this.form.submit()" class="form-control form-control-sm">
                        <option value="both" <?= $dispCur === 'both' ? 'selected' : '' ?>>💱 <?= $lang === 'ar' ? 'ليرة + دولار' : 'L.L + $' ?></option>
                        <option value="lbp" <?= $dispCur === 'lbp' ? 'selected' : '' ?>>🇱🇧 <?= $lang === 'ar' ? 'ليرة فقط' : 'L.L seul' ?></option>
                        <option value="usd" <?= $dispCur === 'usd' ? 'selected' : '' ?>>💵 <?= $lang === 'ar' ? 'دولار فقط' : '$ seul' ?></option>
                    </select>
                </form>

                <?php $salSel = salaryComp(); ?>
                <?php /* 🧹 p12 (2026-10-03 «عدم التكرار لنفس الشي بنفس الصفحة»): حيث شريط «الراتب يشمل» الظاهر موجود بالصفحة، القائمة العليا نفسها تختفي */ ?>
                <div class="school-switcher school-multi" id="salCompPicker"<?= in_array($currentPage ?? '', ['reports', 'employee_history', 'monthly'], true) ? ' hidden' : '' ?> title="<?= $lang === 'ar' ? 'مكوّنات الراتب المعروض' : 'Composantes du salaire affiché' ?>">
                    <i class="fas fa-layer-group" style="color:#fff;background:#7c3aed;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <button type="button" class="sm-btn" onclick="document.getElementById('salCompMenu').classList.toggle('open')">
                        <?= $lang === 'ar' ? 'الراتب يشمل' : 'Salaire inclut' ?> <i class="fas fa-caret-down"></i>
                    </button>
                    <?php /* ☑️ (2026-09-28 p1 «ما بدي أسانسور — يبيّنوا حدّ بعض»): القائمة أعرض والخيارات خانات ظاهرة (salaryCompModeTiles) بدل القوائم المنسدلة */ ?>
                    <div class="school-menu scb-menu" id="salCompMenu">
                        <form method="get" action="<?= BASE_URL ?>switch_salarycomp.php">
                            <div class="sm-list">
                                <label><input type="checkbox" name="comp[]" value="extra" <?= in_array('extra',$salSel,true)?'checked':'' ?>> <?= $lang==='ar'?'الأجر الإضافي':'Supplément' ?></label>
                                <label><input type="checkbox" name="comp[]" value="aide" <?= in_array('aide',$salSel,true)?'checked':'' ?>> <?= $lang==='ar'?'المكافأة والمساعدة':'Prime & aide' ?></label>
                            </div>
                            <?php $tmH = transportColMode(); // 🚌 عمود النقل بثلاث حالات (2026-09-17) ?>
                            <div class="scb-block"><div class="scb-title"><i class="fas fa-bus"></i> <?= $lang==='ar'?'عمود تعويض النقل':'Colonne transport' ?></div>
                                <?= salaryCompModeTiles('transport_mode', $tmH, false) ?>
                            </div>
                            <?php $dmH = dueColMode(); // 💰 عمود المستحق بثلاث حالات (2026-09-19) ?>
                            <div class="scb-block"><div class="scb-title"><i class="fas fa-sack-dollar"></i> <?= $lang==='ar'?'عمود المستحق (المجموع)':'Colonne total dû' ?></div>
                                <?= salaryCompModeTiles('due_mode', $dmH, false) ?>
                            </div>
                            <?php $nfmH = netFamColMode(); // 👨‍👩‍👧➕ عمود «الصافي + التعويض العائلي» بثلاث حالات (2026-09-20) ?>
                            <div class="scb-block"><div class="scb-title"><i class="fas fa-people-roof"></i> <?= $lang==='ar'?'عمود الصافي + التعويض العائلي':'Colonne net + alloc. fam.' ?></div>
                                <?= salaryCompModeTiles('netfam_mode', $nfmH, false) ?>
                            </div>
                            <?php $cgH = contractGradeColsMode(); // 🎓 أعمدة الدرجة/التدرّج للمتعاقد (2026-09-27): مخفية افتراضياً + خيار إظهارها ?>
                            <div class="scb-block"><div class="scb-title"><i class="fas fa-graduation-cap"></i> <?= $lang==='ar'?'أعمدة الدرجة والتدرّج للمتعاقد':'Colonnes échelon (contractuel)' ?></div>
                                <?= salaryCompModeTiles('contract_grade_mode', $cgH, false) ?>
                            </div>
                            <div style="font-size:11px;color:#64748b;padding:2px 4px 6px"><?= $lang==='ar'?'الأساس + الدرجة يبقى دائماً':'Base + échelon toujours inclus' ?></div>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-check"></i> Appliquer / تطبيق</button>
                        </form>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>switch_lang.php?lang=<?= $lang === 'ar' ? 'fr' : 'ar' ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-globe" style="color:#fff;background:#0284c7;width:30px;height:30px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center"></i>
                    <?= $lang === 'ar' ? 'Français' : 'العربية' ?>
                </a>
                
                <?php if (empty($docFocus)): ?>
                <div class="bell-wrap no-print" id="bellWrap">
                    <button type="button" class="bell-btn" onclick="document.getElementById('bellMenu').classList.toggle('open')" title="À faire / شو لازم تعمل"><i class="fas fa-bell"></i><?php if ($msaBellN): ?><span class="bell-n"><?= $msaBellN > 999 ? '999+' : $msaBellN ?></span><?php endif; ?></button>
                    <div class="bell-menu" id="bellMenu">
                        <div class="bell-hd"><b>À faire / شو لازم تعمل</b><a href="<?= BASE_URL ?>index.php">Tout voir / الكل →</a></div>
                        <?php if (!$msaBell): ?><div class="bell-empty"><i class="fas fa-circle-check"></i> Rien en attente / ما في شي معلّق</div><?php else: foreach ($msaBell as [$bIc, $bBg, $bN, $bFr, $bAr, $bHref]): ?>
                        <a class="bell-item" href="<?= e($bHref) ?>"><span class="bell-ic" style="background:<?= $bBg ?>"><i class="<?= $bIc ?>"></i></span><span class="bell-t"><span dir="ltr"><?= e($bFr) ?></span><small><?= e($bAr) ?></small></span><b><?= (int)$bN ?></b></a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="user-menu">
                    <div class="user-avatar">
                        <?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <span><?= e($_SESSION['full_name'] ?? '') ?></span>
                </div>
                
                <a href="<?= BASE_URL ?>logout.php" class="btn btn-light btn-sm" title="Déconnexion / تسجيل الخروج">
                    <i class="fas fa-sign-out-alt" style="color:#dc2626"></i>
                </a>
            </div>
        </header>
        <?php
        /* 🧭 (2026-10-16 p1 «بدي التبويب والديزاين لبرنامج الرواتب هيك: كل باب وجوّاتو ملفاتو») الشريط العلوي بالتبويبات:
         * يُبنى من نفس روابط القائمة الجانبية ($__nav: نفس الصلاحيات والشارات) — كل «باب» تبويب، وبالمرور عليه تنزل لائحة بصفحاته.
         * ألوان برنامج الأقساط (كحلي #1e3a5f، ذهبي #c9a961). واجهة فقط: لا يمسّ أي حساب. العامود الجانبي يبقى مخفيّاً ويرجع بكبسة ☰. */
        if (empty($docFocus)) {
            $__tabIcons = ['Personnel' => 'fa-users', 'Paie' => 'fa-money-check-alt', 'Rapports' => 'fa-chart-bar', 'Système' => 'fa-cog'];
            $__navInner = preg_replace('~^\s*<nav class="sidebar-nav">|</nav>\s*$~u', '', trim($__nav));   // بلا غلاف <nav> القائمة الجانبية (كان </nav> يقفل الشريط قبل آخره)
            $__parts = preg_split('~(<div class="nav-section"[^>]*>.*?</div>)~su', $__navInner, -1, PREG_SPLIT_DELIM_CAPTURE);
            $__groups = []; $__gi = -1;
            foreach ($__parts as $__p) {
                if (preg_match('~<div class="nav-section"[^>]*style="--ns:(#[0-9a-fA-F]{3,8})"[^>]*>\s*<span class="nl-fr">([^<]*)</span><span class="nl-ar">([^<]*)</span>~u', $__p, $__m)) {
                    $__gi++; $__groups[$__gi] = ['fr' => trim($__m[2]), 'ar' => trim($__m[3]), 'color' => $__m[1], 'links' => ''];
                } elseif (preg_match('~<div class="nav-section"~u', $__p)) { continue; }
                elseif ($__gi >= 0) { $__groups[$__gi]['links'] .= $__p; }
            }
            // 🏛️ (2026-10-16 «يهمّني يكون صندوق التعويضات والضمان والمالية كل واحد باب وضمنّن تقاريرن») أبواب من أقسام مركز التقارير نفسها
            if (viewerCanSeePage('reports.php')) {
                require_once __DIR__ . '/report_center.php';
                $__reqUri = (string)($_SERVER['REQUEST_URI'] ?? '');
                $__tileLink = function (array $t) use ($__reqUri) {
                    $__u = (string)$t['url']; if ($__u !== '' && $__u[0] === '?') $__u = BASE_URL . 'pages/reports.php' . $__u;
                    $__q = parse_url($__u, PHP_URL_QUERY) ?: ''; parse_str($__q, $__qa);
                    $__act = false;
                    if (!empty($__qa['form']) && preg_match('~[?&]form=' . preg_quote($__qa['form'], '~') . '(&|$)~', $__reqUri)) $__act = empty($__qa['cat']) || strpos($__reqUri, 'cat=' . $__qa['cat']) !== false;
                    elseif (!empty($__qa['report']) && preg_match('~[?&]report=' . preg_quote($__qa['report'], '~') . '(&|$)~', $__reqUri)) $__act = true;
                    return '<a href="' . e($__u) . '" class="' . ($__act ? 'active' : '') . '"><i class="fas ' . e($t['icon']) . '"></i><span class="nl2"><span class="nl-fr">' . e($t['fr']) . '</span><span class="nl-ar">' . e($t['ar']) . '</span></span></a>';
                };
                $__pageLink = function (string $file, string $icon, string $fr, string $ar, string $pageKey) use ($currentPage) {
                    return '<a href="' . BASE_URL . 'pages/' . $file . '" class="' . ($currentPage === $pageKey ? 'active' : '') . '"><i class="fas ' . $icon . '"></i><span class="nl2"><span class="nl-fr">' . e($fr) . '</span><span class="nl-ar">' . e($ar) . '</span></span></a>';
                };
                $__cats = []; foreach (reportCenterCategories() as $__c) $__cats[$__c['title']] = $__c;
                $__pick = function (string $needle) use ($__cats) { foreach ($__cats as $__t => $__c) if (strpos($__t, $needle) !== false) return $__c; return null; };
                $__doors = [
                    // 🏛️🖼️ (2026-10-18 «لوغو الضمان تمون أيقونة الضمان») gov-ic = شعار الجهة الرسمية محلّ الأيقونة (app.css قسم 500)
                    ['fr' => 'CNSS', 'ar' => 'الضمان الاجتماعي', 'icon' => 'gov-ic gov-cnss', 'color' => '#3b82f6', 'cat' => $__pick('CNSS'),
                     'pages' => canEdit() ? [['cnss_followup.php', 'fa-user-shield', 'Suivi CNSS', 'متابعة الضمان', 'cnss_followup'], ['social_security.php', 'fa-shield-alt', 'Plafonds CNSS', 'حدود الضمان', 'social_security']] : []],
                    ['fr' => 'Caisse EOC', 'ar' => 'صندوق التعويضات', 'icon' => 'gov-ic gov-eoc', 'color' => '#c9a961', 'cat' => $__pick('Caisse EOC'), 'pages' => []],
                    ['fr' => 'Finances', 'ar' => 'المالية — الضريبة', 'icon' => 'gov-ic gov-mof', 'color' => '#be185d', 'cat' => $__pick('Impôt'),   /* 🎨 2026-10-19 كان أحمر */
                     'pages' => canEdit() ? [['tax_declarations.php', 'fa-file-contract', 'Déclarations', 'التصاريح', 'tax'], ['tax_brackets.php', 'fa-percent', "Tranches d'impôt", 'الشطور الضريبية', 'tax_brackets']] : []],
                ];
                $__extra = [];
                foreach ($__doors as $__d) {
                    if (!$__d['cat']) continue;
                    $__links = ''; foreach ($__d['pages'] as $__pg) $__links .= $__pageLink(...$__pg);
                    foreach ($__d['cat']['tiles'] as $__t) $__links .= $__tileLink($__t);
                    $__extra[] = ['fr' => $__d['fr'], 'ar' => $__d['ar'], 'color' => $__d['color'], 'links' => $__links, 'icon' => $__d['icon']];
                }
                // 🚪📅 (2026-10-18 «لازم يكون في ملف… اللي دخلوا إلى المدرسة 1-10 من نفس السنة» + «وملف اللي تركوا بنفس السنة» + «بدي يكون في باب لهالتقرير»)
                //    باب «الداخلون والتاركون»: داخلو المدرسة بـ1/10 + الداخلون في الملاك + الأساتذة الجدد + التاركون + بلوغ الـ64 — كلّها بسنة العرض
                $__edL = $__tileLink(['url' => '?report=titularized&tmode=hire', 'icon' => 'fa-door-open', 'fr' => "Entrés à l'école le 1/10 (année affichée)", 'ar' => 'الأساتذة والموظفون الداخلون إلى المدرسة بـ1/10 من السنة المعروضة'])
                       . $__tileLink(['url' => '?report=titularized', 'icon' => 'fa-user-check', 'fr' => 'Entrés au cadre le 1/10 (année affichée)', 'ar' => 'الداخلون في الملاك بـ1/10 من السنة المعروضة'])
                       . $__tileLink(['url' => '?report=new_teachers', 'icon' => 'fa-user-plus', 'fr' => 'Nouveaux enseignants', 'ar' => 'الأساتذة الجدد — كامل الملف']);
                if (canEdit()) $__edL .= $__pageLink('left_teachers.php', 'fa-user-slash', 'Départs (année affichée)', 'الأساتذة والموظفون التاركون خلال السنة المعروضة', 'left_teachers')
                                      . $__pageLink('retirement_64.php', 'fa-hourglass-half', 'Retraite 64', 'بلوغ سنّ الـ64', 'retirement_64')
                                      . $__pageLink('arabize.php', 'fa-language', 'Arabiser noms & adresses', 'تعريب الأسماء والعناوين المكتوبة بالأجنبي', 'arabize');   // 🔤 (2026-10-27)
                $__extra[] = ['fr' => 'Entrées & Départs', 'ar' => 'الداخلون والتاركون', 'color' => '#0d9488', 'links' => $__edL, 'icon' => 'fa-people-arrows'];
                $__tabIcons['Entrées & Départs'] = 'fa-people-arrows';
                // 📄 (2026-10-16 «لازم يكون باب الإفادات وضمنو الإفادات مع التفتيش عن أستاذ: اسمو أو عيلتو أو رقم تلفونو») باب الإفادات
                if (viewerCanSeePage('attestations.php')) {
                    require_once __DIR__ . '/attestation_types.php';
                    $__attT = attestationTypes(); $__attG = attestationGroups(); $__attUsed = [];
                    $__attLinks = '<div class="mt-search" data-base="' . BASE_URL . '" data-target="pages/attestations.php?employee_id={id}"><i class="fas fa-magnifying-glass"></i><input type="text" class="mt-sinp" placeholder="اسم الأستاذ أو شهرته أو رقم تلفونه… / Nom, famille ou téléphone" autocomplete="off"><div class="mt-sres"></div></div>';
                    $__attLinks .= '<div class="mt-att-cols">';
                    foreach ($__attG as $__gt => $__keys) { $__gp = array_map('trim', explode(' / ', $__gt, 2));
                        $__attLinks .= '<div class="mt-att-col"><div class="mt-att-hd"><span dir="ltr">' . e($__gp[0]) . '</span><small>' . e($__gp[1] ?? '') . '</small></div>';
                        foreach ($__keys as $__k) { if (!isset($__attT[$__k])) continue; $__attUsed[$__k] = 1;
                            $__attLinks .= '<a href="' . BASE_URL . 'pages/attestations.php?type=' . e($__k) . '" class="' . ($currentPage === 'attestations' && (($_GET['type'] ?? '') === $__k) ? 'active' : '') . '"><i class="fas fa-file-lines"></i><span class="nl2"><span class="nl-fr">' . e($__attT[$__k]['fr']) . '</span><span class="nl-ar">' . e($__attT[$__k]['ar']) . '</span></span></a>'; }
                        $__attLinks .= '</div>'; }
                    $__others = array_diff(array_keys($__attT), array_keys($__attUsed));
                    if ($__others) { $__attLinks .= '<div class="mt-att-col"><div class="mt-att-hd"><span dir="ltr">Autres</span><small>أخرى</small></div>'; foreach ($__others as $__k) $__attLinks .= '<a href="' . BASE_URL . 'pages/attestations.php?type=' . e($__k) . '"><i class="fas fa-file-lines"></i><span class="nl2"><span class="nl-fr">' . e($__attT[$__k]['fr']) . '</span><span class="nl-ar">' . e($__attT[$__k]['ar']) . '</span></span></a>'; $__attLinks .= '</div>'; }
                    $__attLinks .= '</div>';
                    $__attLinks .= '<div class="mt-att-foot"><a href="' . BASE_URL . 'pages/attestations.php" class="' . ($currentPage === 'attestations' && empty($_GET['type']) ? 'active' : '') . '"><i class="fas fa-file-signature"></i><span class="nl2"><span class="nl-fr">Toutes les attestations</span><span class="nl-ar">صفحة الإفادات كاملة</span></span></a>'
                                 . '<a href="' . BASE_URL . 'pages/attestations.php?dossier=1"><i class="fas fa-folder-open"></i><span class="nl2"><span class="nl-fr">Dossier complet</span><span class="nl-ar">ملف الأستاذ الكامل</span></span></a>'
                                 . (viewerCanSeePage('attestations_bulk.php') ? '<a href="' . BASE_URL . 'pages/attestations_bulk.php" class="' . ($currentPage === 'attestations' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'attestations_bulk.php' ? 'active' : '') . '"><i class="fas fa-layer-group"></i><span class="nl2"><span class="nl-fr">Attestations groupées</span><span class="nl-ar">إفادات جماعية لمجموعة أساتذة</span></span></a>' : '') . '</div>';   // 📚 (2026-10-27) · 🔒 (2026-10-31) حساب المدرسة لا يرى رابطاً يطرده
                    $__attDoor = ['fr' => 'Attestations', 'ar' => 'الإفادات', 'color' => '#0e7490', 'links' => $__attLinks, 'icon' => 'fa-file-signature', 'wide' => true, 'active' => $currentPage === 'attestations'];
                    $__posA = count($__groups); foreach ($__groups as $__i => $__g) if ($__g['fr'] === 'Paie') { $__posA = $__i + 1; break; }
                    array_splice($__groups, $__posA, 0, [$__attDoor]);
                    $__tabIcons['Attestations'] = 'fa-file-signature';
                    if ($currentPage === 'attestations') $__extraActive = true;
                }
                // 👤 (2026-10-16 «لازم يكون باب اسمو ملف الأستاذ وجوّاتو كل شي بيخصّ الأستاذ وأكيد محلّ للتفتيش عنه») أوّل باب بعد الرئيسية
                if (viewerCanSeePage('attestations.php') || viewerCanSeePage('employee_history.php')) {
                    $__dL = '<div class="mt-search" data-base="' . BASE_URL . '" data-target="pages/attestations.php?dossier=1&employee_id={id}"><i class="fas fa-magnifying-glass"></i><input type="text" class="mt-sinp" placeholder="فتّش عن الأستاذ: اسمو أو شهرتو أو رقم تلفونو… / Nom, famille ou téléphone" autocomplete="off"><div class="mt-sres"></div></div>';
                    $__dItems = [];
                    if (canEdit()) $__dItems[] = ['pages/employees.php', 'fa-users', 'Employés & Enseignants', 'لائحة الموظفين والأساتذة (تعديل الملف)', 'employees'];
                    if (viewerCanSeePage('attestations.php')) $__dItems[] = ['pages/attestations.php?dossier=1', 'fa-folder-open', 'Dossier complet', 'الملف الكامل والإفادات', '__dossier'];
                    if (viewerCanSeePage('attestations.php')) $__dItems[] = ['pages/employee_fiche.php', 'fa-id-card', 'Fiche A4', 'بطاقة الأستاذ A4 (كل معلوماته بورقة)', 'employee_fiche'];   // 🪪 (2026-10-19) «تبين أيقونة ضمن ملف الأستاذ دغري»
                    if (viewerCanSeePage('employee_history.php')) $__dItems[] = ['pages/employee_history.php', 'fa-user-clock', 'Dossier enseignant', 'سيرة الأستاذ', 'employee_history'];
                    if (canEdit()) $__dItems[] = ['pages/employee_full_history.php', 'fa-clock-rotate-left', 'Historique complet', 'التاريخ الكامل للأستاذ', 'employee_full_history'];
                    if (viewerCanSeePage('annual_slip.php')) $__dItems[] = ['pages/annual_slip.php', 'fa-file-invoice-dollar', 'Bulletin annuel', 'بطاقة الراتب السنوية', 'annual'];
                    if (viewerCanSeePage('attestations.php')) $__dItems[] = ['pages/attestations.php', 'fa-file-signature', 'Attestations', 'الإفادات', '__att'];
                    if (viewerCanSeePage('reports.php')) $__dItems[] = ['pages/official_forms.php?form=teacher_card', 'fa-address-card', 'Carte enseignant', 'بطاقة الأستاذ', '__card'];
                    if (canEdit()) { $__dItems[] = ['pages/info_collect.php', 'fa-whatsapp', 'Mise à jour', 'تحديث معلومات الأساتذة (واتساب)', 'info_collect']; $__dItems[] = ['pages/info_status.php', 'fa-clipboard-check', 'État MàJ', 'حالة التحديث (مين بعت)', 'info_status'];
                                    $__dItems[] = ['pages/tax_suggestions.php', 'fa-lightbulb', 'Suggestions état civil', 'اقتراحات من إخراج القيد', 'tax_suggestions']; $__dItems[] = ['pages/left_teachers.php', 'fa-user-slash', 'Départs', 'الأساتذة التاركون', 'left_teachers']; $__dItems[] = ['pages/retirement_64.php', 'fa-hourglass-half', 'Retraite 64', 'بلوغ سنّ الـ64', 'retirement_64']; }
                    $__dL .= '<div class="mt-links mt-links-grid">';
                    foreach ($__dItems as $__it) { $__ic = $__it[1] === 'fa-whatsapp' ? 'fab fa-whatsapp' : 'fas ' . $__it[1];
                        $__isA = ($__it[4] === '__dossier' && $currentPage === 'attestations' && !empty($_GET['dossier'])) || ($__it[4] === '__att' && $currentPage === 'attestations' && empty($_GET['dossier'])) || ($__it[4] === 'employees' && $currentPage === 'employees') || ($__it[4] === $currentPage && strpos($__it[4], '__') !== 0 && $__it[4] !== 'employees');
                        $__dL .= '<a href="' . BASE_URL . $__it[0] . '" class="' . ($__isA ? 'active' : '') . '"><i class="' . $__ic . '"></i><span class="nl2"><span class="nl-fr">' . e($__it[2]) . '</span><span class="nl-ar">' . e($__it[3]) . '</span></span></a>'; }
                    $__dL .= '</div>';
                    $__dossierDoor = ['fr' => 'Dossier enseignant', 'ar' => 'ملف الأستاذ', 'color' => '#1d4ed8', 'links' => $__dL, 'icon' => 'fa-id-badge', 'wide' => true, 'active' => strpos($__dL, 'class="active"') !== false];
                    array_splice($__groups, 0, 0, [$__dossierDoor]);
                    $__tabIcons['Dossier enseignant'] = 'fa-id-badge';
                    if ($__dossierDoor['active']) $__extraActive = true;
                }
                // تُدرَج قبل باب «Rapports» (الترتيب: ملف الأستاذ · الموظفون · الرواتب · الإفادات · الضمان · الصندوق · المالية · التقارير · النظام)
                $__pos = count($__groups); foreach ($__groups as $__i => $__g) if ($__g['fr'] === 'Rapports') { $__pos = $__i; break; }
                array_splice($__groups, $__pos, 0, $__extra);
                $__extraActive = false; foreach ($__extra as $__x) if (strpos($__x['links'], 'class="active"') !== false) { $__extraActive = true; break; }
                $__tabIcons += ['CNSS' => 'gov-ic gov-cnss', 'Caisse EOC' => 'gov-ic gov-eoc', 'Finances' => 'gov-ic gov-mof'];
            }
            echo '<nav class="msa-tabs no-print" id="msaTabs" aria-label="Navigation">';
            echo '<a class="mt-brand" href="' . BASE_URL . 'index.php" title="' . e(currentSchoolName()) . '"><span class="mt-brand-ic"><img src="' . BASE_URL . 'assets/img/logo_mark.png" alt="" width="56" height="56"></span><span class="mt-brand-tx"><b>MSA Payroll</b><small>' . e(currentSchoolName()) . '</small></span></a>';
            echo '<a class="mt-tab mt-home' . ($currentPage === 'dashboard' ? ' active' : '') . '" href="' . BASE_URL . 'index.php" style="--tc:#1e3a5f"><i class="fas fa-house"></i><span class="nl2"><span class="nl-fr">Accueil</span><span class="nl-ar">الرئيسية</span></span></a>';
            foreach ($__groups as $__g) {
                if (trim(strip_tags($__g['links'])) === '' && !preg_match('~<a ~', $__g['links'])) continue;
                $__isAct = !empty($__g['active']) || strpos($__g['links'], 'class="active"') !== false || preg_match('~class="[^"]*\bactive\b~', $__g['links']);
                if ($__isAct && !empty($__extraActive) && in_array($__g['fr'], ['Personnel', 'Paie', 'Rapports', 'Système'], true)) $__isAct = false;   // الصفحة تخصّ باباً مخصَّصاً (الضمان/الصندوق/المالية) ⇒ يضيء هو وحده
                $__n = preg_match_all('~<a ~', $__g['links']);
                echo '<div class="mt-tab mt-group' . ($__isAct ? ' active' : '') . '" style="--tc:' . e($__g['color']) . '">'
                   . '<button type="button" class="mt-btn" aria-haspopup="true" aria-expanded="false"><i class="fas ' . ($__tabIcons[$__g['fr']] ?? 'fa-folder') . '"></i><span class="nl2"><span class="nl-fr">' . e($__g['fr']) . '</span><span class="nl-ar">' . e($__g['ar']) . '</span></span><i class="fas fa-chevron-down mt-car"></i></button>'
                   . '<div class="mt-drop' . (!empty($__g['wide']) ? ' mt-wide' : ($__n > 7 ? ' mt-cols' : '')) . '"><div class="mt-drop-hd"><i class="fas ' . ($__tabIcons[$__g['fr']] ?? 'fa-folder') . '"></i> <span dir="ltr">' . e($__g['fr']) . '</span> <span class="mt-hd-ar">' . e($__g['ar']) . '</span></div><div class="mt-links">' . $__g['links'] . '</div></div>'
                   . '</div>';
            }
            echo '<span class="mt-spacer"></span>';
            echo '<button type="button" class="mt-side" onclick="window.msaSideToggle&&msaSideToggle()" title="إظهار / إخفاء العامود الجانبي"><i class="fas fa-table-columns"></i></button>';
            echo '</nav>';
        }
        ?>
        <!-- سكربت البحث الشامل -->
        <script>
        (function () {
            var inp = document.getElementById('gsInput'), panel = document.getElementById('gsPanel'), box = document.getElementById('globalSearch');
            if (!inp) return;
            var canDossier = <?= $gsCanDossier ? 'true' : 'false' ?>, timer = null;
            function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
            function close() { panel.classList.remove('open'); }
            function findPages(q) {
                var out = [];
                document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
                    var txt = a.textContent.trim();
                    if (txt.toLowerCase().indexOf(q) > -1) {
                        var ic = a.querySelector('i');
                        out.push({ t: txt, h: a.href, i: ic ? ic.className : 'fas fa-link' });
                    }
                });
                return out.slice(0, 6);
            }
            function render(pg, emps) {
                var h = '';
                if (pg.length) {
                    h += '<div class="gs-sec"><i class="fas fa-window-maximize"></i> Pages / الصفحات</div>';
                    pg.forEach(function (p) { h += '<a class="gs-item" href="' + esc(p.h) + '"><i class="' + esc(p.i) + '"></i><span>' + esc(p.t) + '</span></a>'; });
                }
                if (emps.length) {
                    h += '<div class="gs-sec"><i class="fas fa-users"></i> Enseignants / الأساتذة</div>';
                    emps.forEach(function (r) {
                        h += '<a class="gs-item" href="<?= BASE_URL ?>pages/attestations.php?dossier=1&employee_id=' + r.id + '">'
                           + '<i class="fas fa-user"></i><span>' + esc(r.fr) + ' / ' + esc(r.ar)
                           + (r.left ? ' <b style="color:#173f6f;font-size:11px;background:#e1ebf7;border-radius:6px;padding:1px 6px">🚪 ترك ' + esc(r.left) + '</b>' : '')
                           + '<small>' + esc(r.code + (r.school ? ' — ' + r.school : '')) + '</small></span></a>';
                    });
                }
                if (!h) h = '<div class="gs-empty">Aucun résultat / لا نتائج</div>';
                panel.innerHTML = h;
                panel.classList.add('open');
            }
            inp.addEventListener('input', function () {
                var q = inp.value.trim();
                clearTimeout(timer);
                if (q.length < 1) { close(); return; } // 🔍 «بس أحطّ أوّل حرف من اسمه لازم دغري يعطيني اللائحة» (2026-09-13)
                timer = setTimeout(function () {
                    var pg = findPages(q.toLowerCase());
                    if (canDossier) {
                        fetch('<?= BASE_URL ?>ajax_search.php?q=' + encodeURIComponent(q))
                            .then(function (r) { return r.json(); })
                            .then(function (emps) { render(pg, emps); })
                            .catch(function () { render(pg, []); });
                    } else { render(pg, []); }
                }, 120);
            });
            // 🧭 v2026 (Ctrl+K متل Linear/Raycast): الفاضي يعرض «الأخيرة» + أوامر الصفحة؛ أسهم ↑↓ + Enter للتنقّل؛ ما تختاره يُحفظ بالأخيرة
            function recents() { try { return JSON.parse(localStorage.getItem('msa_gs_recent') || '[]'); } catch (x) { return []; } }
            function remember(t, h, i) { try { var r = recents().filter(function (x) { return x.h !== h; }); r.unshift({ t: t, h: h, i: i }); localStorage.setItem('msa_gs_recent', JSON.stringify(r.slice(0, 6))); } catch (x) {} }
            function actions(q) { var out = []; (window.MSA_ACTIONS || []).forEach(function (a) { if (!q || a.t.toLowerCase().indexOf(q) > -1) out.push(a); }); return out.slice(0, 6); }
            function renderExtra(q) {
                var h = '';
                if (!q) { var r = recents(); if (r.length) { h += '<div class="gs-sec"><i class="fas fa-clock-rotate-left"></i> Récents / الأخيرة</div>'; r.forEach(function (x) { h += '<a class="gs-item" href="' + esc(x.h) + '"><i class="' + esc(x.i || 'fas fa-link') + '"></i><span>' + esc(x.t) + '</span></a>'; }); } }
                var ac = actions(q); if (ac.length) { h += '<div class="gs-sec"><i class="fas fa-bolt"></i> Actions / أوامر</div>'; ac.forEach(function (a) { h += '<a class="gs-item gs-act" href="' + esc(a.h) + '"' + (a.confirm ? ' data-confirm="' + esc(a.confirm) + '"' : '') + '><i class="fas fa-bolt" style="background:#1d1d1f;color:#fff"></i><span>' + esc(a.t) + '</span><kbd>' + esc(a.k || '') + '</kbd></a>'; }); }
                return h;
            }
            var _render = render;
            render = function (pg, emps, q) { var extra = renderExtra(q || ''); _render(pg, emps); if (extra) panel.insertAdjacentHTML('afterbegin', extra); if (!panel.textContent.trim() && !extra) panel.innerHTML = '<div class="gs-empty">Aucun résultat / لا نتائج — عدّل البحث</div>'; sel = -1; };
            var sel = -1;
            function items() { return panel.querySelectorAll('.gs-item'); }
            function paint() { items().forEach(function (a, i) { a.classList.toggle('on', i === sel); if (i === sel) a.scrollIntoView({ block: 'nearest' }); }); }
            inp.addEventListener('focus', function () { if (inp.value.trim().length >= 1) inp.dispatchEvent(new Event('input')); else { var pg = findPages('').slice(0, 5); render(pg, [], ''); } });
            inp.addEventListener('keydown', function (e) {
                if (!panel.classList.contains('open')) return;
                var it = items();
                if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(it.length - 1, sel + 1); paint(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); paint(); }
                else if (e.key === 'Enter') { var a = sel >= 0 ? it[sel] : it[0]; if (a) { e.preventDefault(); a.click(); } }
            });
            panel.addEventListener('click', function (e) { var a = e.target.closest('.gs-item'); if (!a) return; if (a.dataset.confirm && !confirm(a.dataset.confirm)) { e.preventDefault(); return; } var ic = a.querySelector('i'); remember(a.textContent.trim().replace(/\s+/g, ' ').slice(0, 80), a.getAttribute('href'), ic ? ic.className : ''); });
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key && e.key.toLowerCase() === 'k') { e.preventDefault(); inp.focus(); inp.select(); }
                if (e.key === 'Escape') { close(); inp.blur(); }
            });
            document.addEventListener('click', function (e) { if (!box.contains(e.target)) close(); });
        })();
        </script>

        <?php
        /* 🏫⇄ (2026-10-03 «بكل التقارير والإفادات يكون عندي خيار انقل من مدرسة لمدرسة دغري ينقل بدل ما ارجع للأول واختار»):
           داخل عرض المستند (تقرير/كشف/نموذج/إفادة) مبدّل المدارس الأعلى مخفي — هذا شريط مدارس: كبسة لكل مدرسة تنقل دغري لنفس
           التقرير بتلك المدرسة (وكبسة «الكل»)، بلا رجوع لصفحة الاختيار. لا يُطبَع. */
        if (!empty($docFocus) && (isSuperAdmin() || (isViewer() && count(viewerAllowedSchoolIds()) > 1))):
            $stripSchools = isSuperAdmin() ? allSchools()
                : array_values(array_filter(allSchools(false), fn($s) => in_array((int)$s['id'], viewerAllowedSchoolIds(), true)));
            if (count($stripSchools) > 1):
                $stripActive = array_map('intval', activeSchoolIds());
                if (function_exists('pageSchoolScope') && isSuperAdmin() && (isset($_GET['sch']) || isset($_GET['sch_all']))) { $stripSc = pageSchoolScope(); $stripActive = $stripSc['all'] ? [] : array_map('intval', $stripSc['ids']); }
                elseif (isSuperAdmin() && isset($_GET['schools'])) $stripActive = array_values(array_filter(array_map('intval', (array)$_GET['schools'])));
                $stripAll = isSuperAdmin() ? empty($stripActive) : (count($stripActive) === count($stripSchools));
                // الرجوع لنفس الصفحة بلا وسائط المدارس القديمة ولا الموظف (الموظف تابع لمدرسته)
                $stripQ = $_GET; foreach (['sch', 'sch_all', 'sch_set', 'schools', 'school', 'employee_id', 'emp', 'emp_id'] as $sk) unset($stripQ[$sk]);
                $stripBack = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . ($stripQ ? '?' . http_build_query($stripQ) : '');
        ?>
        <?php /* 🧹 (2026-10-17 «بكل البرنامج أي صفحة بفتحها بتطلع معجّقة») 14 حبّة مدارس على 3 أسطر ⇒ قائمة منسدلة واحدة بنفس الروابط (switch_school) */
              $ssMulti = !$stripAll && count($stripActive) > 1; ?>
        <div class="school-strip no-print" id="schoolStrip">
            <span class="ss-k"><i class="fas fa-school"></i> École / المدرسة:</span>
            <select class="ss-sel" onchange="if (this.value) location.href = this.value" title="انتقل لنفس المستند بمدرسة ثانية دغري">
                <?php if ($ssMulti): ?><option value="" selected><?= count($stripActive) ?> écoles / مدارس مختارة</option><?php endif; ?>
                <option value="<?= BASE_URL ?>switch_school.php?school=0&amp;back=<?= e(rawurlencode($stripBack)) ?>"<?= $stripAll ? ' selected' : '' ?>>Toutes / الكل</option>
                <?php foreach ($stripSchools as $ssS): $ssOn = !$stripAll && !$ssMulti && in_array((int)$ssS['id'], $stripActive, true); ?>
                <option value="<?= BASE_URL ?>switch_school.php?school=<?= (int)$ssS['id'] ?>&amp;back=<?= e(rawurlencode($stripBack)) ?>"<?= $ssOn ? ' selected' : '' ?>><?= e($lang === 'ar' ? ($ssS['name_ar'] ?: $ssS['name_fr']) : ($ssS['name_fr'] ?: $ssS['name_ar'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; endif; ?>
        <div class="page-content" id="pageContent" style="--accent: <?= $accentColor ?>; --accent-bg: <?= $accentBg ?>;">
        <?php
        // رسائل التنبيه (flash) — تنبيهات عائمة عصرية تختفي لحالها (الأخطاء تبقى ليكبس ×)
        // 🔁 نسخة الكمبيوتر: مزامنة بيانات الأونلاين تلقائياً بالخلفية (المدير، كل ONLINE_SYNC_HOURS ساعات) + سطر الحالة بلوحة القيادة
        if (localSyncEnabled() && isAdmin()):
            // 🔁 (2026-10-18) إطلاق خلفي أيضاً حين يحين فحص البصمة (كل 5 دقائق) — sync_local يقرّر إن تغيّر الأونلاين
            if (localSyncDue() || localSyncCheckDue()): ?>
        <script>fetch(<?= json_encode(BASE_URL . 'sync_local.php') ?>, {credentials:'same-origin', keepalive:true}).catch(function(){});</script>
            <?php endif;
            if (($currentPage ?? '') === 'dashboard'):
                $lsSt = localSyncState(); $lsConf = localSyncConflicts();
                if (!empty($_GET['sync_conflicts_seen']) && $lsConf['n']) { try { localSyncPdo()->exec("UPDATE `" . DB_NAME . "`.`_sync_conflicts` SET seen = 1"); } catch (Throwable $e) {} $lsConf = ['n' => 0, 'rows' => []]; } ?>
        <div class="alert alert-info no-print" style="margin:0 0 10px;font-size:12.5px;padding:6px 12px"><i class="fas fa-rotate"></i> نسخة الكمبيوتر ⇄ الأونلاين تلقائياً (كل 5 دقائق): تعديلاتك هون بتنرفع للأونلاين، وتعديلات الأونلاين بتنزل لهون — وعلى الأقل كل <?= rtrim(rtrim(number_format(localSyncHours(), 2, '.', ''), '0'), '.') ?> ساعات نسخة كاملة: <?= e(localSyncStatusText()) ?>
            <?php if (!empty($lsSt['pending_push'])): ?><br><strong style="color:#0e7490"><i class="fas fa-cloud-arrow-up"></i> في تعديلات على الكمبيوتر بانتظار الإنترنت لترفع:</strong> <?= e((string)($lsSt['last_push_msg'] ?? '')) ?><?php elseif (!empty($lsSt['last_push_ok'])): ?> · آخر رفع للأونلاين <?= date('d/m/Y H:i', (int)$lsSt['last_push_ok']) ?><?php endif; ?>
        </div>
                <?php if ($lsConf['n']): ?>
        <div class="alert alert-danger no-print" style="margin:0 0 10px;font-size:12.5px;padding:6px 12px"><i class="fas fa-triangle-exclamation"></i> <strong>تعارض بالمزامنة (<?= (int)$lsConf['n'] ?>):</strong> هالصفوف تعدّلت على الكمبيوتر وعلى الأونلاين معاً — بقي الأونلاين، راجعها وأعِد تعديلها أونلاين إذا لزم:
            <?php foreach ($lsConf['rows'] as $cr): ?><span style="display:inline-block;background:#fff;border:1px solid #fecaca;border-radius:8px;padding:1px 8px;margin:2px"><?= e((string)$cr['label']) ?> <small>(<?= e($cr['tbl']) ?> · <?= $cr['kind'] === 'del' ? 'حذف' : ($cr['kind'] === 'ins' ? 'إضافة' : 'تعديل') ?> · <?= e(substr((string)$cr['created_at'], 0, 16)) ?>)</small></span><?php endforeach; ?>
            <a class="btn btn-sm btn-light" href="?sync_conflicts_seen=1" style="margin-inline-start:8px"><i class="fas fa-check"></i> تمام، شفتها</a>
        </div>
                <?php endif;
            endif;
        endif;
        // 🖥️🔒 (قراءة فقط يدوياً: LOCAL_READ_ONLY) — افتراضياً مطفأ لأنّ المزامنة صارت باتّجاهين
        if (function_exists('pcReadOnly') && pcReadOnly()):
            $pcOnline = rtrim((string)ONLINE_SYNC_URL, '/') . '/' . ltrim(substr((string)($_SERVER['REQUEST_URI'] ?? ''), strlen(rtrim(BASE_URL, '/'))), '/'); ?>
        <div class="alert alert-warning no-print pc-readonly" style="margin:0 0 10px;font-size:12.5px;padding:6px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"><i class="fas fa-lock"></i>
            <span>نسخة الكمبيوتر للقراءة والطباعة فقط — أي تعديل بيصير <strong>أونلاين</strong> وبيرجع لهون تلقائياً خلال دقائق.</span>
            <a class="btn btn-sm btn-primary" href="<?= e($pcOnline) ?>" target="_blank" rel="noopener"><i class="fas fa-globe"></i> افتح هالصفحة أونلاين للتعديل / Modifier en ligne</a>
        </div>
        <?php endif;
        $hasFlash = !empty($_SESSION['flash_success']) || !empty($_SESSION['flash_error']) || !empty($_SESSION['flash_info']);
        if ($hasFlash): ?>
        <div class="toast-stack no-print" id="toastStack">
        <?php foreach (['flash_success' => 'success', 'flash_error' => 'danger', 'flash_info' => 'info'] as $fk => $cls):
            if (!empty($_SESSION[$fk])): ?>
            <div class="alert alert-<?= $cls ?> toast">
                <i class="fas <?= $cls === 'success' ? 'fa-circle-check' : ($cls === 'danger' ? 'fa-circle-exclamation' : 'fa-info-circle') ?>"></i>
                <span><?= e($_SESSION[$fk]) ?></span>
                <button type="button" class="toast-x" onclick="this.closest('.toast').remove()" title="Fermer / إغلاق">&times;</button>
            </div>
        <?php unset($_SESSION[$fk]); endif; endforeach; ?>
        </div>
        <script>
        setTimeout(function () {
            document.querySelectorAll('#toastStack .toast:not(.alert-danger)').forEach(function (t) {
                t.classList.add('toast-hide');
                setTimeout(function () { t.remove(); }, 400);
            });
        }, 6500);
        </script>
        <?php endif; ?>
        <?php
        // 📐 p12 (2026-10-03 «الترتيب»): ترتيب واحد بكل صفحة — الاختيار (مدارس/فئة/فلترة) أوّلاً ← خيارات العرض ← كبسات
        // الطباعة والتصدير فوق المستند مباشرةً. الشريطان يُجمَعان هنا ويُطبعان بعد فورم الاختيار إن بدأت الصفحة به (msaOrderTopBars).
        $msaTopBars = '';
        // شريط «الراتب المركّب يشمل» الظاهر (خانات اختيار متل شريط الإفادة) — على صفحات الرواتب فقط
        if (in_array($currentPage ?? '', ['reports', 'employee_history', 'monthly'], true)) {
            $sct = salaryCompToolbar();
            // 🌍 (2026-10-10 الرواتب الشهرية «أحدث تنظيم»): الصفحة تطلب $compactSalaryComp ⇒ الخيارات الخمسة نفسها مطوية بكبسة واحدة
            // «Colonnes & options» (مثل كبسة «خيارات الطبع») — لا يتغيّر أي خيار ولا سلوكه، فقط يطلع الجدول لفوق.
            // 📐 (2026-10-16 «الخيارات معجّقة وآخدة كل الصفحة — رتّبها بكل صفحات الطبع») الطيّ صار الافتراضي بكل الصفحات؛ الصفحة تضع $compactSalaryComp = false لإظهار الأسطر الخمسة
            if (!isset($compactSalaryComp) || $compactSalaryComp) {
                $sct = '<details class="export-compact no-print"><summary class="btn btn-sm btn-light"><i class="fas fa-sliders-h"></i> Colonnes &amp; options / الأعمدة والخيارات</summary>' . $sct . '</details>';
            }
            $msaTopBars .= $sct;
        }
        ?>
        <?php
        // شريط الطباعة والتصدير — يظهر تلقائياً بكل صفحة (طباعة/PDF/Excel/Word/واتساب/إيميل)
        // (2026-08-29، «ما تخلّي شي ما إلو معنى») صفحات الإعدادات/الإدارة ليست مطبوعات: بلا شريط طباعة/تصدير
        $noToolbarPages = ['settings.php','users.php','email_settings.php','open_year.php','excel_salaries.php']; // 📤 (2026-10-03 «بكل البرنامج لازم يكون في كل شي من كل شي»): الشريط الكامل بكل صفحة فيها لائحة/جدول — تبقى بلاه صفحات الإدارة البحتة فقط
        $noToolbarPages[] = 'school_access.php'; // 🏫 (2026-10-22) دخول المدارس وأيقوناتها — صفحة إدارة بلا شريط تصدير
        $noToolbarPages[] = 'attestations_bulk.php'; // 📚 (2026-10-27) الإفادات الجماعية — كبساتها الثلاث داخل الصفحة
        if (empty($hideExportToolbar) && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $noToolbarPages, true)) {
            $msaTopBars .= exportToolbar($exportTitle ?? $pageTitle, $exportOpts ?? []);
        } elseif (empty($ownExportRow)) {
            // 🖨️ (2026-10-03 «لازم بأي صفحة بكون فاتحها يكون عندي كل خيارات الطبع»): الصفحات التي كانت بلا شريط (إدارة/تعديل/قوائم)
            // تأخذ كبسة وحدة «خيارات الطبع» تفتح الشريط الكامل نفسه — كل الخيارات بكل صفحة بلا عجقة. $ownExportRow = للصفحة سطر تصدير خاصّ كامل.
            $msaTopBars .= '<details class="export-compact no-print"><summary class="btn btn-sm btn-light"><i class="fas fa-print"></i> Impression &amp; export / خيارات الطبع</summary>'
                         . exportToolbar($exportTitle ?? $pageTitle, $exportOpts ?? []) . '</details>';
        }
        if ($msaTopBars !== '' && (!isset($compactSalaryComp) || $compactSalaryComp)) {
            $msaTopBars = '<div class="msa-bars-row no-print">' . $msaTopBars . '</div>'; // الكبستان بسطر واحد
        }
        if ($msaTopBars !== '') {
            ob_start(function ($buf, $phase) use ($msaTopBars) {
                static $done = false;
                if ($done) return $buf;      // دفعات لاحقة (صفحة تبثّ على مراحل) تمرّ كما هي
                $done = true;
                return msaOrderTopBars($msaTopBars, $buf);
            });
        }
        ?>
