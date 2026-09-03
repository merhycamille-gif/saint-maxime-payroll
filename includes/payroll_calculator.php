<?php
/**
 * Payroll Calculation Engine
 * محرك حسابات الرواتب
 */

require_once __DIR__ . '/functions.php';

class PayrollCalculator {
    
    private $employee;
    private $month;
    private $year;
    private $exchangeRate;
    
    public function __construct($employeeId, $month, $year) {
        $stmt = getDB()->prepare("SELECT * FROM employees WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$employeeId]);
        $this->employee = $stmt->fetch();
        
        if (!$this->employee) {
            throw new Exception("Employee not found");
        }
        
        $this->month = (int)$month;
        $this->year = (int)$year;
        $this->exchangeRate = getExchangeRate($month, $year);
    }
    
    /**
     * قيمة عمود من السلسلة لدرجة قد تكون كسرية (مثلاً 16.5).
     * نصف الدرجة = القيمة بين الدرجتين المتتاليتين (استيفاء خطّي).
     * يطبّق قانون مثل 223/2012 (+4½ درجة) بأمانة بدل تقريبه.
     */
    private function getScaleColumn($column, $grade) {
        $grade = (float)$grade;
        if ($grade < 1) $grade = 1;
        if ($grade > 52) $grade = 52;

        $low = (int)floor($grade);
        $high = (int)ceil($grade);
        $frac = $grade - $low;

        $allowed = ['new_grade_value', 'new_salary_2017'];
        if (!in_array($column, $allowed, true)) return 0; // حماية من حقن اسم العمود

        // إصدار السلسلة الساري بتاريخ شهر الراتب (دعم سلسلة جديدة «من تاريخ إلى تاريخ»)
        $asOf = sprintf('%04d-%02d-01', $this->year, $this->month);
        $vid = scaleVersionIdAsOf($asOf);
        $stmt = getDB()->prepare("SELECT grade, `$column` AS v FROM salary_scale_2017 WHERE version_id = ? AND grade IN (?, ?)");
        $stmt->execute([$vid, $low, $high]);
        $vals = [];
        foreach ($stmt->fetchAll() as $row) $vals[(int)$row['grade']] = (float)$row['v'];

        // الراتب يُحسب على **الدرجة الكاملة** دائماً (floor) — **لا استيفاء لنصف الدرجة**.
        // نصف الدرجة (.5) لا تعني نصف الراتب بين درجتين؛ تعني أنّ الدرجة الكاملة التالية
        // تنزل السنة القادمة. فهذه السنة يُدفع راتب الدرجة الكاملة الحالية (floor).
        return $vals[$low] ?? 0;
    }

    /**
     * Get grade value (scale value of current grade) in LBP
     * قيمة الدرجة الجديدة من السلسلة (تدعم الكسور)
     */
    private function getGradeValueLBP($grade) {
        return $this->getScaleColumn('new_grade_value', $grade);
    }

    /**
     * Get full salary from scale for a grade (يدعم الكسور)
     */
    private function getScaleSalaryLBP($grade) {
        return $this->getScaleColumn('new_salary_2017', $grade);
    }
    
    /**
     * Get bonus amount for a specific month
     */
    public $primeUsdLaw = 0; // 🧮 دولار القانون لإضافي الشهر (آخر حساب prime_fixe)
    private function getBonusForMonth($bonusType, $baseForPercent = 0) {
        $sy = $this->month >= 10 ? $this->year . '-' . ($this->year + 1) : ($this->year - 1) . '-' . $this->year;
        $stmt = getDB()->prepare("
            SELECT amount, currency, value_type, start_month, end_month
            FROM employee_bonuses
            WHERE employee_id = ? AND bonus_type = ? AND is_active = 1
              AND (school_year IS NULL OR school_year = ?)
        ");
        $stmt->execute([$this->employee['id'], $bonusType, $sy]);
        
        $total = 0;
        $pctSum = 0.0;   // 🧮 مجموع النسب المنطبقة على الشهر — تُدوَّر مرّة واحدة (45٪ + 5٪ = 50٪ بالضبط)
        while ($row = $stmt->fetch()) {
            // Check if month falls in period
            $start = $row['start_month'];
            $end = $row['end_month'];
            
            if ($start === null || $end === null) {
                // applies to all months
                $amount = (float)$row['amount'];
            } else {
                if ($start <= $end) {
                    if ($this->month < $start || $this->month > $end) continue;
                } else {
                    // wrap around year (e.g. start=10 end=3)
                    if ($this->month < $start && $this->month > $end) continue;
                }
                $amount = (float)$row['amount'];
            }
            
            // نسبة مئوية: قاعدة المستخدم (÷1500 رسمي ← نسبة ← داون دولار ← سعر السوق ← داون للمليون)
            // فتتحرّك مع الأساس/الدرجة تلقائياً. أو مبلغ ثابت (يُحوَّل للّيرة إن دولار — داون).
            // (2026-08-29، طلبه «إذا زدت 5٪ وما حطّيت 50»): النسب المتعدّدة لنفس الشهر تُجمَع أولاً ثم
            // تُطبَّق القاعدة مرّة واحدة على مجموعها — لا تدوير لكل سطر لحاله (كان يضيّع لغاية مليون).
            if (($row['value_type'] ?? 'amount') === 'percent') {
                $pctSum += $amount;
                continue;
            } elseif ($row['currency'] === 'USD') {
                $amount = usdToLbp($amount, $this->exchangeRate);
            }
            $total += $amount;
        }
        if ($pctSum > 0) $total += bonusPercentLbp($pctSum, $baseForPercent, $this->exchangeRate);
        // 🧮 دولار القانون للإضافي (يُخزَّن بعمود prime_fixe_usd_law ليقرأه كل البرنامج): 1,536 × 55٪ = 844 $
        if ($bonusType === 'prime_fixe') $this->primeUsdLaw = $pctSum > 0 ? extraPercentLawUsd($pctSum, $baseForPercent) : 0;
        return $total;
    }

    /**
     * مجموع تعويض النقل اليومي **المؤرّخ بالفترات** للشهر الحالي (employee_bonuses نوع transport_daily).
     * لكل فترة سارية للشهر: amount (قيمة يومية) × الأيام × الأسابيع، وتُحوَّل للّيرة إن كانت بالدولار.
     */
    private function getDailyTransportForMonth($days, $weeks) {
        $sy = $this->month >= 10 ? $this->year . '-' . ($this->year + 1) : ($this->year - 1) . '-' . $this->year;
        $stmt = getDB()->prepare("
            SELECT amount, currency, start_month, end_month
            FROM employee_bonuses
            WHERE employee_id = ? AND bonus_type = 'transport_daily' AND is_active = 1
              AND (school_year IS NULL OR school_year = ?)
        ");
        $stmt->execute([$this->employee['id'], $sy]);
        $total = 0;
        while ($row = $stmt->fetch()) {
            $start = $row['start_month']; $end = $row['end_month'];
            if ($start !== null && $end !== null) {
                if ($start <= $end) { if ($this->month < $start || $this->month > $end) continue; }
                else { if ($this->month < $start && $this->month > $end) continue; }
            }
            $monthly = (float)$row['amount'] * $days * $weeks;
            if ($row['currency'] === 'USD') $monthly = usdToLbp($monthly, $this->exchangeRate);
            $total += $monthly;
        }
        return $total;
    }

    /**
     * الدرجة (الإشلون) السارية لتاريخ معيّن من سجلّ الدرجات.
     * يعيد null إذا لا يوجد أي حدث درجة قبل/عند التاريخ.
     */
    private function gradeAsOf($asOfDate) {
        $stmt = getDB()->prepare(
            "SELECT grade_after FROM employee_grade_history
             WHERE employee_id = ? AND grade_after >= 1 AND change_date <= ?
             ORDER BY change_date DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$this->employee['id'], $asOfDate]);
        $g = $stmt->fetchColumn();
        return ($g === false || $g === null) ? null : (float)$g;
    }

    /** الدرجة التي كان عليها الأستاذ **قبل أول حدث مسجّل** (grade_before لأقدم حدث، أو null). */
    private function earliestGradeBefore() {
        $stmt = getDB()->prepare("SELECT grade_before FROM employee_grade_history WHERE employee_id = ? ORDER BY change_date ASC, id ASC LIMIT 1");
        $stmt->execute([$this->employee['id']]);
        $g = $stmt->fetchColumn();
        return ($g === false || $g === null) ? null : (float)$g;
    }

    /** تاريخ آخر حدث درجة في السجلّ (أو null). */
    private function latestGradeEventDate() {
        $stmt = getDB()->prepare("SELECT MAX(change_date) FROM employee_grade_history WHERE employee_id = ? AND grade_after >= 1");
        $stmt->execute([$this->employee['id']]);
        $d = $stmt->fetchColumn();
        return $d ?: null;
    }

    /**
     * احتساب أساس الراتب + الدرجة (التدرّج) **تراكمياً حسب التاريخ**، بالليرة.
     * يعيد [base, echelon, effectiveGrade] حيث base + echelon = راتب الشهر.
     *
     * المبدأ (مطابق لمثال «مثل 2»): الراتب يتراكم سنةً بعد سنة. لكل شهر:
     *   - الدرجة السارية = آخر درجة في السجلّ مفعولها ≤ هذا الشهر (يحترم
     *     مواعيد 1/10 للعادية و1/1 للاستثنائية، فيتغيّر الراتب في وقته).
     *   - أساس الراتب  = راتب السلسلة عند الدرجة التي كان عليها **قبل بداية
     *     هذه السنة الدراسية** (أي ما تراكم سابقاً) — يكبر كل سنة.
     *   - الدرجة/التدرّج = راتب السلسلة عند الدرجة السارية − الأساس
     *     (= ما اكتسبه من درجات **هذه السنة فقط**، وليس مجموع كل الدرجات).
     * إن لم يوجد سجلّ درجات: يُعتمد النموذج الافتراضي (درجة الدخول → الحالية).
     *
     * للمتعاقد والموظف: راتب مباشر، بلا تدرّج. اتفاق USD>0 للملاك يتجاوز السلسلة.
     */
    private function calculateBaseAndEchelon() {
        $emp = $this->employee;

        if ($emp['employee_type'] === 'enseignant_titulaire') {
            // اتفاق خاص بالدولار يتجاوز السلسلة (دولار←ليرة بلا فراطات: تدوير لتحت)
            if ($emp['salary_input_mode'] === 'direct_usd' && (float)$emp['base_salary_usd'] > 0) {
                return [usdToLbp($emp['base_salary_usd'], $this->exchangeRate), 0.0, (float)$emp['current_grade']];
            }
            // اتفاق خاص بالليرة يتجاوز السلسلة
            if ($emp['salary_input_mode'] === 'direct_lbp' && (float)$emp['contract_salary_lbp'] > 0) {
                return [(float)$emp['contract_salary_lbp'], 0.0, (float)$emp['current_grade']];
            }
            $percent = ($emp['salary_input_mode'] === 'percent_of_lbp')
                ? ((float)$emp['base_salary_lbp_percent'] / 100)
                : 1.0;
            // حماية: الأستاذ الملاك يخضع للسلسلة كاملةً. نسبة 0 (أو غير محدّدة) = 100%
            // وإلا يطلع الأساس صفراً (كان السبب أن دنيا/رامونا انخزنوا بنسبة 0%).
            if ($percent <= 0) $percent = 1.0;

            // قاعدة المستخدم: الدرجة تظهر في **شهر مفعولها فقط**، والأشهر التالية تنضمّ
            // لأساس الراتب. لذلك الأساس = راتب السلسلة عند الدرجة في **نهاية الشهر السابق**،
            // والدرجة (التدرّج) = ما اكتُسب **هذا الشهر فقط** (= 0 في الأشهر الساكنة).
            // ملاحظة: المجموع (أساس + درجة) = راتب السلسلة عند الدرجة السارية، لا يتغيّر؛
            // فالصافي والضمان والضريبة تبقى كما هي — يتغيّر التوزيع فقط على الكشف.
            $asOf = sprintf('%04d-%02d-01', $this->year, $this->month);
            $beforeSY = date('Y-m-d', strtotime($asOf . ' -1 day')); // آخر يوم بالشهر السابق

            $effGrade   = $this->gradeAsOf($asOf);
            $startGrade = $this->gradeAsOf($beforeSY);
            $latest     = $this->latestGradeEventDate();
            $cg         = (float)$emp['current_grade'];

            // الدرجة الحالية (التي يحدّدها المستخدم) هي المرجع للفترة بعد آخر حدث مسجّل:
            // إذا كان السجلّ ناقصاً ولم يصل للدرجة الحالية، نعتمد الدرجة الحالية.
            if ($effGrade === null) {
                $effGrade = $cg;
            } elseif ($latest !== null && $asOf >= $latest && $cg > $effGrade) {
                $effGrade = $cg;
            }
            if ($startGrade === null) {
                // لا حدث قبل هذا الشهر. الدرجة الراسخة = grade_before لأقدم حدث مسجّل
                // (الدرجة التي بلغها سابقاً قبل بدء السجلّ) — هكذا لا يُلَمّ فرق درجات
                // السنوات الماضية دفعةً واحدة في تشرين الأول (حالة برنار زغيب: 19 ثابتة).
                // أما إن كان أقدم حدث هو الترسيم نفسه (grade_before < 1) فالأساس = درجة الدخول.
                $earliestBefore = $this->earliestGradeBefore();
                if ($earliestBefore !== null && $earliestBefore >= 1) {
                    $startGrade = min($earliestBefore, $effGrade);
                } else {
                    $startGrade = min((float)$emp['starting_grade'], $effGrade);
                    if ($startGrade < 1) $startGrade = $effGrade;
                }
            } elseif ($latest !== null && $beforeSY >= $latest && $cg > $startGrade) {
                $startGrade = $cg;
            }
            if ($startGrade > $effGrade) $startGrade = $effGrade;

            // 🔴 قاعدة نصف الدرجة (.5): الراتب دائماً على الدرجة الكاملة (floor) — getScaleSalaryLBP
            // يعتمد floor داخلياً، فنصف الدرجة وحده لا يُظهر تدرّجاً (أساس ثابت وقيمة درجة 0).
            // أما إذا ارتفعت الدرجة الكاملة نفسها هذا الشهر (كدرجات كانون الاستثنائية 19.5→23.5)
            // فالفرق يظهر بعمود «قيمة الدرجة» ثم ينضمّ للأساس الأشهر التالية — لا يُدمج بالأساس دغري.
            // (كان هنا early-return يُرجِع تدرّج 0 لكل درجة كسرية فيبلع درجات كانون بالكشوف — أُزيل بطلب المستخدم p1.)
            $base    = $this->getScaleSalaryLBP($startGrade) * $percent;
            $current = $this->getScaleSalaryLBP($effGrade)   * $percent;
            $echelon = max(0, $current - $base);
            return [$base, $echelon, $effGrade];
        }

        // متعاقد / موظف: راتب مباشر فقط، بلا سلسلة ولا تدرّج (الموظف يخضع لقانون
        // العمل بحدّ أدنى يدوي «من تاريخ إلى تاريخ»، وليس لسلسلة الرتب والرواتب).
        if ($emp['salary_input_mode'] === 'direct_usd') {
            return [usdToLbp($emp['base_salary_usd'], $this->exchangeRate), 0.0, (float)$emp['current_grade']];
        }
        // أي وضع آخر (direct_lbp أو ضبط خاطئ على «السلسلة») = الراتب المتفق عليه بالليرة مباشرةً.
        return [(float)$emp['contract_salary_lbp'], 0.0, (float)$emp['current_grade']];
    }
    
    /**
     * Calculate income tax based on tax brackets
     * حساب ضريبة الدخل التصاعدية
     */
    private function calculateIncomeTax($annualTaxableBase) {
        if ($annualTaxableBase <= 0) return 0;
        
        // Get family deduction — المصدر الوحيد familyDeductionAnnual (2026-08-06):
        // يحترم زرّ «تطبيق التنزيل العائلي» بملفه (مطفأ ⇒ 0) + «الزوج/الزوجة يعمل» ⇒
        // تُحذف زيادة الزوج (يبقى الشخصي + الأولاد) — نفس ما تعرضه كل الكشوف والتصاريح.
        $asOfDed = $this->year . '-' . str_pad($this->month, 2, '0', STR_PAD_LEFT) . '-01';
        $familyDeduction = (float)familyDeductionAnnual(
            $this->employee['social_status'] ?? '',
            $this->employee['spouse_works'] ?? 0,
            $this->employee['apply_family_deduction'] ?? 1,
            $asOfDed,
            $this->employee['grant_spouse_addition'] ?? 0,
            $this->employee['grant_children_addition'] ?? 0,
            (int)($this->employee['id'] ?? 0) // 📅 أتمتة الـ18 وتاريخ عمل الزوج (2026-08-23)
        );
        
        // Apply deduction
        $taxableAfterDeduction = max(0, $annualTaxableBase - $familyDeduction);
        if ($taxableAfterDeduction <= 0) return 0;
        
        // Get brackets — أحدث مجموعة شطور سارية فقط (تجنّب خلط مجموعتين)
        $asOf = $this->year . '-' . str_pad($this->month, 2, '0', STR_PAD_LEFT) . '-01';
        $stmt = getDB()->prepare("
            SELECT * FROM tax_brackets
            WHERE effective_from = (SELECT MAX(effective_from) FROM tax_brackets WHERE effective_from <= ?)
            ORDER BY bracket_number ASC
        ");
        $stmt->execute([$asOf]);
        $brackets = $stmt->fetchAll();
        
        $tax = 0;
        $remaining = $taxableAfterDeduction;
        
        foreach ($brackets as $bracket) {
            $bracketSize = $bracket['annual_to'] 
                ? ($bracket['annual_to'] - $bracket['annual_from']) 
                : PHP_INT_MAX;
            
            $taxableInBracket = min($remaining, $bracketSize);
            if ($taxableInBracket <= 0) break;
            
            $tax += $taxableInBracket * ((float)$bracket['rate_percent'] / 100);
            $remaining -= $taxableInBracket;
            
            if ($remaining <= 0) break;
        }
        
        return $tax;
    }
    
    /**
     * مكوّنات العلاوات لشهر الحساب: [الأجر الإضافي prime_fixe، المكافأة aide، النقل الكامل].
     * مصدر واحد للمنطق: يستعمله calculate() للمُعَدّين (ملاك/أساس>0)،
     * وoverlayStoredYearBonuses() للمنقولين بصفوف مخزّنة — كي لا يختلف الحساب بين المسارين.
     */
    public function bonusComponents($basePlusEchelon = 0) {
        $emp = $this->employee;
        $primeFixe = $this->getBonusForMonth('prime_fixe', $basePlusEchelon);
        $aideComp = $this->getBonusForMonth('aide_complementaire', $basePlusEchelon);
        $transportComp = $this->getBonusForMonth('transport_complement', $basePlusEchelon);
        // أيام الحضور والأسابيع (مشتركة للنقل اليومي الثابت والمؤرّخ بالفترات)
        $tDays = (float)($emp['transport_days_per_week'] ?? 0);
        if ($tDays <= 0) $tDays = (float)($emp['days_per_week'] ?? 0);
        $tWeeks = (float)($emp['transport_weeks'] ?? 0);
        if ($tWeeks <= 0) $tWeeks = 4;
        // (أ) تعويض النقل اليومي الثابت (عمود الموظف، قيمة واحدة كل السنة): اليومي × الأيام × الأسابيع.
        $tDaily = (float)($emp['transport_daily_amount'] ?? 0);
        if ($tDaily > 0) {
            $dailyMonthly = $tDaily * $tDays * $tWeeks;
            if (($emp['transport_daily_currency'] ?? 'LBP') === 'USD') $dailyMonthly = usdToLbp($dailyMonthly, $this->exchangeRate);
            $transportComp += $dailyMonthly;
        }
        // (ب) تعويض النقل اليومي **المؤرّخ بالفترات** (employee_bonuses نوع transport_daily): يتغيّر
        // خلال السنة. لكل فترة سارية للشهر: القيمة اليومية × الأيام × الأسابيع (تُحوَّل إن دولار).
        $transportComp += $this->getDailyTransportForMonth($tDays, $tWeeks);
        // ✍️ (2026-08-25) قانون أشهر النقل: للأساتذة النقل من تشرين الأول لحزيران ضمناً (9 أشهر،
        // نافذة قابلة للتعديل بالإعدادات، سارية من 2026-2027) — خارجها لا تعويض نقل إطلاقاً.
        // الموظف الإداري يداوم الصيف فلا تنطبق عليه. (transportMonthActive بfunctions.php)
        $syTr = $this->month >= 10 ? $this->year . '-' . ($this->year + 1) : ($this->year - 1) . '-' . $this->year;
        if (!transportMonthActive((int)$this->month, (string)($emp['employee_type'] ?? ''), $syTr)) $transportComp = 0;
        return [$primeFixe, $aideComp, $transportComp];
    }

    /**
     * Main calculation
     */
    public function calculate() {
        $emp = $this->employee;
        
        // === 1. أساس الراتب + الدرجة (تراكمي حسب تاريخ الشهر، من سلسلة 2017) ===
        [$baseSalary, $echelonValue, $effectiveGrade] = $this->calculateBaseAndEchelon();
        $basePlusEchelon = $baseSalary + $echelonValue;
        
        // === 3. Suppléments ===
        [$primeFixe, $aideComp, $transportComp] = $this->bonusComponents($basePlusEchelon);
        $extra = 0; // can be customized
        // عمودان مستقلان بالخضوع (بطلب المستخدم): «الأجر الإضافي» = extra + prime_fixe (فلاغ *_includes_extra)،
        // «مكافأة ومساعدة» = aide_complementaire (فلاغ *_includes_prime_aide). كلٌّ يدخل القاعدة بزرّه الأخضر المستقل.
        $extraWage = $extra + $primeFixe;          // الأجر الإضافي
        $primeAideTotal = $primeFixe + $aideComp;  // الإجمالي (للراتب الشامل gross فقط)
        
        // === 4. Build deduction bases ===
        // Tax base
        $taxBase = 0;
        if ($emp['tax_subject']) {
            $taxBase = $baseSalary;
            if ($emp['tax_includes_echelon']) $taxBase += $echelonValue;
            if ($emp['tax_includes_extra']) $taxBase += $extraWage;       // الأجر الإضافي
            if ($emp['tax_includes_prime_aide']) $taxBase += $aideComp;   // مكافأة ومساعدة
        }
        
        // CNSS base
        $cnssBase = 0;
        if ($emp['cnss_subject']) {
            $cnssBase = $baseSalary;
            if ($emp['cnss_includes_echelon']) $cnssBase += $echelonValue;
            if ($emp['cnss_includes_extra']) $cnssBase += $extraWage;       // الأجر الإضافي
            if ($emp['cnss_includes_prime_aide']) $cnssBase += $aideComp;   // مكافأة ومساعدة
        }
        
        // EOC base (only for titulaire)
        $eocBase = 0;
        if ($emp['eoc_subject'] && $emp['employee_type'] === 'enseignant_titulaire') {
            $eocBase = $baseSalary;
            if ($emp['eoc_includes_echelon']) $eocBase += $echelonValue;
            if ($emp['eoc_includes_extra']) $eocBase += $extraWage;       // الأجر الإضافي
            if ($emp['eoc_includes_prime_aide']) $eocBase += $aideComp;   // مكافأة ومساعدة
        }
        
        // === بلوغ سنّ الـ64 (تقاعد) مع الإبقاء على العمل ===
        // إذا بلغ الموظف/الأستاذ 64 سنة (اعتباراً من نهاية شهر الراتب) وقرّرت الإدارة إبقاءه
        // (keep_working_past_64=1) تُوقَف محسومات التقاعد اعتباراً من ذلك الشهر فصاعداً:
        //   • الموظف (employe): تُوقَف حصّة نهاية الخدمة ٨.٥٪ (المدرسة).
        //   • الأستاذ الملاك: يُوقَف صندوق التعويضات ٦٪ عنه وعن المدرسة (+ حسم الدرجة/نصف الراتب).
        // الإعفاء مشروط ببلوغ 64 في ذلك الشهر تحديداً، فالأشهر السابقة (قبل 64) تبقى محسوماتها كاملة.
        $past64Titulaire = false; $past64Employe = false;
        if (!empty($emp['keep_working_past_64'])) {
            $endOfMonth = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $this->year, $this->month)));
            $ageEnd = ageOnDate($emp['birth_date'] ?? '', $endOfMonth);
            if ($ageEnd !== null && $ageEnd >= 64) {
                if ($emp['employee_type'] === 'enseignant_titulaire') $past64Titulaire = true;
                elseif ($emp['employee_type'] === 'employe') $past64Employe = true;
            }
        }

        // === 5. Calculate deductions ===
        // تطبيق الحد الأدنى/الأقصى للأجر الخاضع لكل فرع (جدول cnss_brackets، حسب تاريخ الشهر).
        // لكل فرع حدوده الخاصة، لذلك نشتقّ أساساً خاضعاً مستقلاً لكل فرع.
        $maladieBase = clampCnssBase($cnssBase, 'maladie_maternite', $this->month, $this->year);
        $famBase     = clampCnssBase($cnssBase, 'allocations_familiales', $this->month, $this->year);
        $finBase     = clampCnssBase($cnssBase, 'fin_de_service', $this->month, $this->year);
        $eocBase     = clampCnssBase($eocBase, 'eoc', $this->month, $this->year);
        // بلغ 64 وأبقيناه (ملاك): يُصفَّر أساس صندوق التعويضات → يُوقَف الحسم عنه (caisseAmount)
        // وعن المدرسة (schoolEoc) تلقائياً.
        if ($past64Titulaire) $eocBase = 0;

        // النِّسَب مؤرّخة: تُقرأ القيمة السارية بتاريخ شهر الراتب (rate_history)
        $cnssRate = getRateAsOf('cnss_employee_rate', $this->month, $this->year, 3) / 100;
        $eocRate = getRateAsOf('eoc_employee_rate', $this->month, $this->year, 6) / 100;

        $cnssAmount = $maladieBase * $cnssRate;
        $caisseAmount = $eocBase * $eocRate;

        // درجة / نصف راتب → اشتراك صندوق التعويضات لمرّة واحدة عند حدث درجة هذا الشهر:
        // شهر الترسيم = نصف الراتب + الدرجة العادية الفورية؛ شهر الترقية = قيمة الدرجة. للملاك الخاضع للصندوق فقط.
        $eocGradeDeduction = 0;
        if ($emp['eoc_subject'] && $emp['employee_type'] === 'enseignant_titulaire' && !$past64Titulaire) {
            $mStart = sprintf('%04d-%02d-01', $this->year, $this->month);
            $mEnd   = date('Y-m-t', strtotime($mStart));
            $evStmt = getDB()->prepare("SELECT grade_before, grade_after, reason FROM employee_grade_history WHERE employee_id = ? AND change_date BETWEEN ? AND ?");
            $evStmt->execute([$emp['id'], $mStart, $mEnd]);
            $hasTit = false;
            foreach ($evStmt->fetchAll() as $ev) {
                if ($ev['reason'] === 'titularization') { $hasTit = true; continue; } // 0→درجة الدخول ليست «درجة»
                $inc = $this->getScaleSalaryLBP($ev['grade_after']) - $this->getScaleSalaryLBP($ev['grade_before']);
                if ($inc > 0) $eocGradeDeduction += $inc;
            }
            if ($hasTit) $eocGradeDeduction += $basePlusEchelon / 2; // نصف الراتب عند الترسيم
        }

        // === 6. Income Tax ===
        // اشتراك صندوق التعويضات (٦٪ + درجة/نصف راتب) معفى من ضريبة الدخل ⇒ يُنزَّل من الأساس الخاضع.
        $taxBase = max(0, $taxBase - $caisseAmount - $eocGradeDeduction);
        // 🔴 القاعدة الرسمية (دليل وزارة المالية ص55 — طُبّقت 2026-08-06 بطلب المستخدم
        // «كل شي حسب القوانين اللبنانية»): «يجري تجزئة التنزيل العائلي وشطور الضريبة
        // بالنسبة إلى مدة العمل» — كل شهر معمول يستحق 1/12 من التنزيل السنوي و1/12 من
        // الشطور مهما كان عدد أشهر دفعه. التطبيق: أساس الشهر يُسنوَن ×12 وتُحسب الضريبة
        // السنوية (بالتنزيل الكامل والشطور الكاملة) ثم تُقسم ÷12 = ضريبة الشهر المعمول.
        // فمن يعمل 10 أشهر يدفع 10 حصص شهرية = 10/12 من السنوية تلقائياً (لا ×10/÷10
        // كما كان — ذلك أعطاه التنزيل السنوي كاملاً وشطور سنة كاملة عن 10 أشهر خطأً).
        $annualTaxable = $taxBase * 12;
        $annualTax = $this->calculateIncomeTax($annualTaxable);
        $monthlyTax = $annualTax / 12;

        // === 7. Family Allowances (NOT subject to any deduction) ===
        // 🔵 خيارا الملف (2026-08-06): «احتساب تعويض الزوج/الزوجة» و«احتساب تعويض الأولاد»
        // (الافتراضي محسوبان) + قاعدة المستخدم: الزوج/الزوجة يعمل ⇒ لا تعويض زوجة إطلاقاً
        // (يأخذه من جهة عمله) وتعويضُ الأولاد **مناصفةً** بين الوالدَين (النصف هنا).
        $famSpouse   = (float)$emp['family_allowance_spouse_lbp'];
        $famChildren = (float)$emp['family_allowance_children_lbp'];
        if ((int)($emp['count_spouse_allowance'] ?? 1) !== 1) $famSpouse = 0;
        if ((int)($emp['count_children_allowance'] ?? 1) !== 1) $famChildren = 0;
        if (!empty($emp['spouse_works'])) {
            $famSpouse = 0;
            $famChildren = round($famChildren / 2);
        }
        $familyAllowance = $famSpouse + $famChildren;

        // === 8. Totals ===
        $totalRetenues = $cnssAmount + $caisseAmount + $monthlyTax + $eocGradeDeduction;
        $grossEarnings = $basePlusEchelon + $extra + $primeAideTotal;
        $netSalary = $grossEarnings - $totalRetenues;
        $totalDue = $netSalary + $familyAllowance + $transportComp;
        
        // === 9. Employer charges (نِسَب مؤرّخة + أسس محدودة بالحدود لكل فرع) ===
        $schoolCnss = $maladieBase * (getRateAsOf('cnss_employer_rate', $this->month, $this->year, 8) / 100);
        $schoolEoc = ($emp['employee_type'] === 'enseignant_titulaire')
            ? $eocBase * (getRateAsOf('eoc_employer_rate', $this->month, $this->year, 6) / 100)
            : 0;
        $schoolFamilyComp = ($emp['employee_type'] === 'employe')
            ? $famBase * (getRateAsOf('family_compensation_rate', $this->month, $this->year, 6) / 100)
            : 0;
        $schoolEndOfService = ($emp['employee_type'] === 'employe' && !$past64Employe)
            ? $finBase * (getRateAsOf('end_of_service_rate', $this->month, $this->year, 8.5) / 100)
            : 0;
        
        return [
            'employee_id' => $emp['id'],
            'school_id' => $emp['school_id'], // ربط الراتب بمدرسة الموظف
            'month' => $this->month,
            'year' => $this->year,
            // السنة الدراسية: تشرين الأول (10) → أيلول (9). أيلول يتبع السنة التي بدأت تشرين الأول السابق.
            'school_year' => $this->month >= 10 ? $this->year . '-' . ($this->year + 1) : ($this->year - 1) . '-' . $this->year,
            'grade_at_month' => $effectiveGrade,
            
            // Salary
            'base_salary_lbp' => round($baseSalary),
            'echelon_value_lbp' => round($echelonValue),
            'base_plus_echelon_lbp' => round($basePlusEchelon),
            'extra_lbp' => round($extra),
            'prime_fixe_lbp' => round($primeFixe),
            'prime_fixe_usd_law' => (int)$this->primeUsdLaw,
            'aide_complementaire_lbp' => round($aideComp),
            'transport_complement_lbp' => round($transportComp),
            
            // Deductions
            'echelon_to_caisse_lbp' => round($emp['eoc_includes_echelon'] ? $echelonValue : 0),
            'caisse_amount_lbp' => round($caisseAmount),
            'eoc_grade_lbp' => round($eocGradeDeduction),
            'cnss_amount_lbp' => round($cnssAmount),
            'taxable_base_lbp' => round($taxBase),
            'income_tax_lbp' => round($monthlyTax),
            'total_retenues_lbp' => round($totalRetenues),
            
            // Result
            'net_salary_lbp' => round($netSalary),
            'family_allowance_lbp' => round($familyAllowance),
            'transport_lbp' => round($transportComp),
            'total_due_lbp' => round($totalDue),
            
            // Exchange rate
            'exchange_rate' => $this->exchangeRate,
            'net_salary_usd' => round($netSalary / $this->exchangeRate, 2),
            'total_due_usd' => round($totalDue / $this->exchangeRate, 2),
            
            // Employer charges
            'school_cnss_8_lbp' => round($schoolCnss),
            'school_eoc_6_lbp' => round($schoolEoc),
            'school_family_comp_6_lbp' => round($schoolFamilyComp),
            'school_end_of_service_8_5_lbp' => round($schoolEndOfService),
            
            'is_calculated' => 1,
            'calculated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Save calculation to database
     */
    public function calculateAndSave() {
        // 🔴 قاعدة التارك (§١٠): مَن له تاريخ ترك لا يُحفَظ له راتب في سنة دراسية تبدأ
        // **بعد** تاريخ تركه — من أي مسار كان (فتح سنة/إعادة حساب/زرّ احتساب/شفاء ذاتي).
        // يبقى راتبه يُحسب لكل أشهر سنة تركه حتى 30-9 (رتبة السنة نفسها مسموحة).
        // زرّ «نسخ الملف لسنة» للتارك الراجع يمسح تواريخ التّرك قبل الحساب فلا يتأثّر.
        // نفس مبدأ pruneSalariesAfterDeparture و yearEmploymentFilter.
        $lds = array_filter([
            $this->employee['left_date_cnss'] ?? null,
            $this->employee['left_date_finance'] ?? null,
            $this->employee['left_date_eoc'] ?? null,
        ], fn($d) => !empty($d) && $d !== '0000-00-00');
        if ($lds) {
            $ld = min($lds);
            $depRank = ((int)substr($ld, 5, 2) >= 10) ? (int)substr($ld, 0, 4) : (int)substr($ld, 0, 4) - 1;
            $rowRank = ($this->month >= 10) ? $this->year : $this->year - 1;
            if ($rowRank > $depRank) return $this->calculate(); // بلا أي حفظ — سنة بعد الترك
        }
        $data = $this->calculate();
        
        ensurePrimeUsdLawColumn();
        $sql = "INSERT INTO monthly_salaries (
            employee_id, school_id, month, year, school_year, grade_at_month,
            base_salary_lbp, echelon_value_lbp, base_plus_echelon_lbp,
            extra_lbp, prime_fixe_lbp, prime_fixe_usd_law, aide_complementaire_lbp, transport_complement_lbp,
            echelon_to_caisse_lbp, caisse_amount_lbp, eoc_grade_lbp, cnss_amount_lbp,
            taxable_base_lbp, income_tax_lbp, total_retenues_lbp,
            net_salary_lbp, family_allowance_lbp, transport_lbp, total_due_lbp,
            exchange_rate, net_salary_usd, total_due_usd,
            school_cnss_8_lbp, school_eoc_6_lbp, school_family_comp_6_lbp, school_end_of_service_8_5_lbp,
            is_calculated, calculated_at
        ) VALUES (
            :employee_id, :school_id, :month, :year, :school_year, :grade_at_month,
            :base_salary_lbp, :echelon_value_lbp, :base_plus_echelon_lbp,
            :extra_lbp, :prime_fixe_lbp, :prime_fixe_usd_law, :aide_complementaire_lbp, :transport_complement_lbp,
            :echelon_to_caisse_lbp, :caisse_amount_lbp, :eoc_grade_lbp, :cnss_amount_lbp,
            :taxable_base_lbp, :income_tax_lbp, :total_retenues_lbp,
            :net_salary_lbp, :family_allowance_lbp, :transport_lbp, :total_due_lbp,
            :exchange_rate, :net_salary_usd, :total_due_usd,
            :school_cnss_8_lbp, :school_eoc_6_lbp, :school_family_comp_6_lbp, :school_end_of_service_8_5_lbp,
            :is_calculated, :calculated_at
        ) ON DUPLICATE KEY UPDATE
            grade_at_month = VALUES(grade_at_month),
            base_salary_lbp = VALUES(base_salary_lbp),
            echelon_value_lbp = VALUES(echelon_value_lbp),
            base_plus_echelon_lbp = VALUES(base_plus_echelon_lbp),
            extra_lbp = VALUES(extra_lbp),
            prime_fixe_lbp = VALUES(prime_fixe_lbp),
            prime_fixe_usd_law = VALUES(prime_fixe_usd_law),
            aide_complementaire_lbp = VALUES(aide_complementaire_lbp),
            transport_complement_lbp = VALUES(transport_complement_lbp),
            echelon_to_caisse_lbp = VALUES(echelon_to_caisse_lbp),
            caisse_amount_lbp = VALUES(caisse_amount_lbp),
            eoc_grade_lbp = VALUES(eoc_grade_lbp),
            cnss_amount_lbp = VALUES(cnss_amount_lbp),
            taxable_base_lbp = VALUES(taxable_base_lbp),
            income_tax_lbp = VALUES(income_tax_lbp),
            total_retenues_lbp = VALUES(total_retenues_lbp),
            net_salary_lbp = VALUES(net_salary_lbp),
            family_allowance_lbp = VALUES(family_allowance_lbp),
            transport_lbp = VALUES(transport_lbp),
            total_due_lbp = VALUES(total_due_lbp),
            exchange_rate = VALUES(exchange_rate),
            net_salary_usd = VALUES(net_salary_usd),
            total_due_usd = VALUES(total_due_usd),
            school_cnss_8_lbp = VALUES(school_cnss_8_lbp),
            school_eoc_6_lbp = VALUES(school_eoc_6_lbp),
            school_family_comp_6_lbp = VALUES(school_family_comp_6_lbp),
            school_end_of_service_8_5_lbp = VALUES(school_end_of_service_8_5_lbp),
            is_calculated = VALUES(is_calculated),
            calculated_at = VALUES(calculated_at)";
        
        $stmt = getDB()->prepare($sql);
        $stmt->execute($data);
        return $data;
    }
}

/**
 * إعادة حساب رواتب سنة دراسية لأستاذ تلقائياً حسب القانون (بعد تغيير درجة/أجر إضافي/إعداد).
 * يضمن أن الراتب المعروض دائماً مطابق للقانون والمعطيات، فلا تبقى أرقام قديمة عالقة.
 * **أمان:** لا يُعاد الحساب لمن لا أساس له في الإعداد (متعاقد/موظف بمبلغ صفر = راتبه
 * مخزَّن منقول من القديم) لئلا يُصفَّر. الأستاذ الملاك يُعاد دائماً (أساسه = السلسلة).
 * $schoolYear = null ⇒ السنة الدراسية الحالية.
 */
function recalcEmployeeYear($employeeId, $schoolYear = null) {
    $db = getDB();
    $e = $db->prepare("SELECT employee_type, base_salary_usd, contract_salary_lbp, payment_months_per_year, hire_date, is_deleted FROM employees WHERE id = ?");
    $e->execute([$employeeId]);
    $e = $e->fetch();
    if (!$e || (int)$e['is_deleted'] === 1) return 0;

    // أمان: لا حساب كامل لمن أساسه صفر بالإعداد (راتبه مخزَّن منقول) لئلا يُصفَّر.
    // لكنه لا يُهمَل: علاواته المسجّلة (أجر إضافي/مكافأة/نقل) تُركَّب على أشهره المخزّنة (أدناه).
    $hasConfig = ($e['employee_type'] === 'enseignant_titulaire')
              || (float)$e['base_salary_usd'] > 0
              || (float)$e['contract_salary_lbp'] > 0;

    // 🔴 (2026-08-06 — حالة مارسيلا داود «الإضافي بالملف صح وبالكشف مش هوي») النداء بلا سنة
    // صريحة كان يعيد حساب السنة **التقويمية** فقط، بينما العلاوات تُحفَظ على السنة **المعروضة**
    // (writeSchoolYear) — فإذا عدّل المستخدم علاوة وهو على السنة الجديدة المفتوحة بقيت أشهرها
    // المخزّنة على القديم واختلف الكشف عن الملف. الصحيح: بلا سنة صريحة يُعاد حساب السنة
    // المعروضة + السنة التقويمية + كل السنين المفتوحة اللاحقة (المجهّزة مسبقاً من إعدادات
    // الملف نفسها) — فتبقى كل الكشوف مطابقة للملف مهما كانت السنة المعروضة.
    $sy = $schoolYear ?: writeSchoolYear();
    if (!$schoolYear && !empty($e['hire_date'])) {
        // الأستاذ الجديد الذي تاريخ دخوله في سنة دراسية **لاحقة** (عُيِّن لسنة مفتوحة)
        // يُحسب على سنة دخوله — فلا يظهر في السنة الجارية أو ما قبلها.
        $hireSy = schoolYearOfDate($e['hire_date']);
        if ($hireSy && $hireSy > $sy) $sy = $hireSy; // مقارنة نصّية صحيحة لصيغة YYYY-YYYY
    }
    if ($sy === 'all' || !preg_match('/^(\d{4})-(\d{4})$/', (string)$sy, $mm)) return 0;

    if (!$schoolYear) {
        $others = [];
        $cur = currentSchoolYear();
        // السنة التقويمية أيضاً (تعديل الإعداد يمسّها) — إلا الأستاذ المعيَّن لسنة لاحقة
        // (دخوله بعد السنة الجارية): لا تُحسب له السنة الجارية فلا يظهر فيها.
        $hireSyG = !empty($e['hire_date']) ? schoolYearOfDate($e['hire_date']) : null;
        if ($cur !== $sy && (!$hireSyG || $hireSyG <= $cur)) $others[$cur] = 1;
        try {
            $fq = $db->prepare("SELECT DISTINCT school_year FROM monthly_salaries WHERE employee_id = ? AND school_year > ?");
            $fq->execute([$employeeId, $cur]);
            foreach ($fq->fetchAll(PDO::FETCH_COLUMN) as $fSy) {
                if ($fSy !== $sy && preg_match('/^\d{4}-\d{4}$/', (string)$fSy)) $others[$fSy] = 1;
            }
        } catch (Exception $ex) {}
        foreach (array_keys($others) as $oSy) {
            try { recalcEmployeeYear($employeeId, $oSy); } catch (Exception $ex) {}
        }
    }

    // المنقول بصفوف مخزّنة (متعاقد/موظف بأساس صفر): بدل تجاهُله كلياً — ركِّب علاواته
    // المسجّلة على أشهره المخزّنة، فيظهر الأجر الإضافي الذي يدخله المستخدم في ملفه
    // على البطاقة السنوية وكل الكشوف (حالة ديانا شرو 2026-08-04).
    if (!$hasConfig) return overlayStoredYearBonuses($employeeId, $sy);

    $y1 = (int)$mm[1]; $y2 = (int)$mm[2];
    $months = ((int)$e['payment_months_per_year'] === 10)
        ? [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2]]
        : [[10,$y1],[11,$y1],[12,$y1],[1,$y2],[2,$y2],[3,$y2],[4,$y2],[5,$y2],[6,$y2],[7,$y2],[8,$y2],[9,$y2]];
    $n = 0;
    foreach ($months as [$m, $y]) {
        try { (new PayrollCalculator($employeeId, $m, $y))->calculateAndSave(); $n++; } catch (Exception $ex) {}
    }
    return $n;
}

/**
 * «تركيب العلاوات» للموظف المنقول بصفوف مخزّنة (بلا أساس بالإعداد):
 * المحرّك الكامل ممنوع عليه (يُصفِّر أساسه)، لكن علاوات ملفه (أجر إضافي/مكافأة/نقل)
 * يجب أن تنعكس على أشهره المخزّنة — وإلا بقيت البطاقة السنوية وكل الكشوف على القديم
 * مهما عدّل المستخدم (حالة ديانا شرو 2026-08-04).
 *
 * يعدّل أعمدة العلاوات فقط ويصحّح الصافي/المستحق (ومرايا الدولار) بفرق التغيير —
 * لا يلمس الأساس ولا المحسومات المخزّنة (نفس فلسفة مسار open_year للمتعاقد المنقول).
 *
 * أمان: كل عائلة تُركَّب فقط إذا كان له سجلّ من عائلتها لتلك السنة في ملفه
 * (إضافي+مكافأة عائلة، والنقل بأنواعه عائلة) — فلا يُصفَّر نقلٌ منقول قديم لا سجلّ له.
 * العملية idempotent: إعادة تشغيلها بلا تغيير بيانات لا تبدّل شيئاً.
 */
function overlayStoredYearBonuses($employeeId, $schoolYear) {
    $db = getDB();
    if (!preg_match('/^\d{4}-\d{4}$/', (string)$schoolYear)) return 0;

    // أي عائلات علاوات مسجّلة له هذه السنة؟ (تشمل غير الفعّالة كي يُصفَّر المطفأ)
    $fam = $db->prepare("SELECT
            COALESCE(SUM(bonus_type IN ('prime_fixe','aide_complementaire')), 0) AS n_add,
            COALESCE(SUM(bonus_type IN ('transport_complement','transport_daily')), 0) AS n_tr
        FROM employee_bonuses WHERE employee_id = ? AND (school_year IS NULL OR school_year = ?)");
    $fam->execute([$employeeId, $schoolYear]);
    $f = $fam->fetch(PDO::FETCH_ASSOC) ?: ['n_add' => 0, 'n_tr' => 0];
    $doAdd = (int)$f['n_add'] > 0;
    // النقل اليومي الثابت (عمود بطاقة الموظف) يُعدّ إعداد نقل أيضاً
    $tFixed = $db->prepare("SELECT COALESCE(transport_daily_amount, 0) FROM employees WHERE id = ?");
    $tFixed->execute([$employeeId]);
    $doTr = ((int)$f['n_tr'] > 0) || ((float)$tFixed->fetchColumn() > 0);
    if (!$doAdd && !$doTr) return 0; // لا علاوات مسجّلة → لا تلمس الصفوف المنقولة أبداً

    $rows = $db->prepare("SELECT * FROM monthly_salaries
        WHERE employee_id = ? AND school_year = ? AND COALESCE(is_indemnity_month, 0) = 0");
    $rows->execute([$employeeId, $schoolYear]);
    ensurePrimeUsdLawColumn();
    $upd = $db->prepare("UPDATE monthly_salaries SET
            prime_fixe_lbp = ?, aide_complementaire_lbp = ?, transport_complement_lbp = ?, transport_lbp = ?,
            net_salary_lbp = ?, total_due_lbp = ?, net_salary_usd = ?, total_due_usd = ?, prime_fixe_usd_law = ?
        WHERE id = ?");
    $n = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try { $calc = new PayrollCalculator($employeeId, (int)$r['month'], (int)$r['year']); }
        catch (Exception $ex) { continue; }
        [$primeFixe, $aideComp, $transportComp] = $calc->bonusComponents((float)$r['base_plus_echelon_lbp']);
        $newPrime = $doAdd ? (int)round($primeFixe) : (int)$r['prime_fixe_lbp'];
        $newAide  = $doAdd ? (int)round($aideComp)  : (int)$r['aide_complementaire_lbp'];
        $newTr    = $doTr  ? (int)round($transportComp) : (int)$r['transport_lbp'];
        $newTrC   = $doTr  ? (int)round($transportComp) : (int)$r['transport_complement_lbp'];
        $dAdd = ($newPrime + $newAide) - ((int)$r['prime_fixe_lbp'] + (int)$r['aide_complementaire_lbp']);
        // 🔴 النقل داخل المستحق مرّة واحدة (العمودان نفس القيمة) — الفرق من transport_lbp وحده
        $dTr = $newTr - (int)$r['transport_lbp'];
        $newLawUsd = $doAdd ? (int)$calc->primeUsdLaw : (int)($r['prime_fixe_usd_law'] ?? 0);
        if ($dAdd === 0 && $dTr === 0 && $newTrC === (int)$r['transport_complement_lbp'] && $newLawUsd === (int)($r['prime_fixe_usd_law'] ?? 0)) continue;
        // 🔴 امتصاص الفجوة (المنقول من القديم): إذا كان الصافي المخزّن أكبر من (الأساس+الإضافات)
        // فالفرق «أجر إضافي مخفي» موجود داخل الصافي أصلاً — تسجيله بالملف يملأ العمود
        // ولا يُضاف للصافي مرّة ثانية؛ فقط ما يزيد عن الفجوة يُعتبر علاوة جديدة فعلية.
        $gap = max(0, ((int)$r['net_salary_lbp'] + (int)$r['total_retenues_lbp'])
                    - ((int)$r['base_plus_echelon_lbp'] + (int)$r['extra_lbp'] + (int)$r['prime_fixe_lbp'] + (int)$r['aide_complementaire_lbp']));
        $dNet = ($dAdd > 0) ? max(0, $dAdd - $gap) : $dAdd;
        $newNet = max(0, (int)$r['net_salary_lbp'] + $dNet);
        $newDue = max(0, (int)$r['total_due_lbp'] + $dNet + $dTr);
        $rate = (float)$r['exchange_rate'];
        $upd->execute([$newPrime, $newAide, $newTrC, $newTr, $newNet, $newDue,
            $rate > 0 ? round($newNet / $rate, 2) : $r['net_salary_usd'],
            $rate > 0 ? round($newDue / $rate, 2) : $r['total_due_usd'],
            $newLawUsd,
            $r['id']]);
        $n++;
    }
    return $n;
}

/**
 * إعادة حساب الرواتب المخزّنة ضمن مدى تاريخي [$fromDate .. $toDate أو حتى الآن].
 * تُستدعى بعد أي تعديل على قانون (شطور ضريبة، نِسَب، حدود ضمان، سلسلة) فتتحدّث الأرقام تلقائياً.
 *
 * أمان حاسم: تتخطّى الموظفين «المنقولين بلا إعداد» (غير الملاك وأساسهم بالدولار/بالعقد = صفر)
 * تماماً كما يفعل recalcEmployeeYear، لئلا تُصفَّر رواتبهم المنقولة من البرنامج القديم.
 */
function recalcSalariesInRange($db, $fromDate, $toDate = null) {
    @set_time_limit(0);
    @ignore_user_abort(true);
    if (!$fromDate) return 0;
    $fromKey = (int)date('Y', strtotime($fromDate)) * 12 + ((int)date('n', strtotime($fromDate)) - 1);
    $toKey = $toDate ? ((int)date('Y', strtotime($toDate)) * 12 + ((int)date('n', strtotime($toDate)) - 1)) : PHP_INT_MAX;
    // فقط الموظفون الذين لهم إعداد فعلي (ملاك أو أساس>0) — لا تُمسّ رواتب المنقولين بلا إعداد
    $rows = $db->query("SELECT ms.employee_id, ms.year, ms.month
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0
          AND (e.employee_type = 'enseignant_titulaire'
               OR COALESCE(e.base_salary_usd,0) > 0
               OR COALESCE(e.contract_salary_lbp,0) > 0)")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $r) {
        $key = (int)$r['year'] * 12 + ((int)$r['month'] - 1);
        if ($key < $fromKey || $key > $toKey) continue;
        try { (new PayrollCalculator((int)$r['employee_id'], (int)$r['month'], (int)$r['year']))->calculateAndSave(); $n++; }
        catch (Throwable $e) {}
    }
    // يُكمَّل دائماً بإعادة احتساب حصص المؤسسة للموظفين المستوردين (الذين تتخطّاهم
    // الحلقة أعلاه لأن راتبهم مُدخَل مباشرة على جدول الرواتب بلا إعداد على البطاقة).
    backfillEmployerCharges($db, $fromDate, $toDate);
    return $n;
}

/**
 * إعادة احتساب «حصص المؤسسة» (الضمان ٨٪ + التعويضات العائلية ٦٪ + نهاية الخدمة ٨.٥٪)
 * لكل صفوف الرواتب المخزّنة، اشتقاقاً من خصم الأجير ٣٪ المخزّن — **بلا مَسّ الراتب**.
 *
 * لماذا: بيانات الموظفين الحقيقية أُدخلت مباشرة على جدول الرواتب (راتب + خصم ٣٪ فقط)،
 * وحصص المؤسسة بقيت صفراً. ولا يمكن تشغيل المحرّك الكامل عليهم لأن بطاقتهم بلا أساس راتب
 * (فيُصفَّر راتبهم). فنشتقّ الأساس الخاضع من الخصم المخزّن (= خصم٣٪ ÷ نسبة الأجير) ثم نطبّق
 * حدود كل فرع (clampCnssBase) ونِسَبه المؤرّخة — تماماً كما يفعل المحرّك. العملية idempotent:
 * تُعطي القيم نفسها للصفوف المحسوبة سلفاً (الملاك والمتعاقد)، فآمنة للتشغيل على كل البرنامج.
 *
 * يُحدِّث فقط الأعمدة: school_cnss_8_lbp, school_family_comp_6_lbp, school_end_of_service_8_5_lbp.
 * (school_eoc_6_lbp للملاك يبقى كما حسبه المحرّك — لا يُمَسّ هنا.)
 */
function backfillEmployerCharges($db, $fromDate = null, $toDate = null) {
    @set_time_limit(0);
    @ignore_user_abort(true);
    $fromKey = $fromDate ? ((int)date('Y', strtotime($fromDate)) * 12 + ((int)date('n', strtotime($fromDate)) - 1)) : 0;
    $toKey   = $toDate   ? ((int)date('Y', strtotime($toDate))   * 12 + ((int)date('n', strtotime($toDate))   - 1)) : PHP_INT_MAX;
    $rows = $db->query("SELECT ms.id, ms.month, ms.year, ms.cnss_amount_lbp, e.employee_type
        FROM monthly_salaries ms JOIN employees e ON e.id = ms.employee_id
        WHERE e.is_deleted = 0 AND ms.cnss_amount_lbp > 0")->fetchAll(PDO::FETCH_ASSOC);
    $upd = $db->prepare("UPDATE monthly_salaries SET
            school_cnss_8_lbp = :c8,
            school_family_comp_6_lbp = :fam,
            school_end_of_service_8_5_lbp = :eos
        WHERE id = :id");
    $n = 0;
    foreach ($rows as $r) {
        $key = (int)$r['year'] * 12 + ((int)$r['month'] - 1);
        if ($key < $fromKey || $key > $toKey) continue;
        $m = (int)$r['month']; $y = (int)$r['year'];
        // الأساس الخاضع = خصم الأجير ٣٪ ÷ نسبة الأجير المؤرّخة (يعيد بناء الأساس بعد سقف المرض)
        $cnssRate = getRateAsOf('cnss_employee_rate', $m, $y, 3) / 100;
        $subjBase = $cnssRate > 0 ? ((float)$r['cnss_amount_lbp'] / $cnssRate) : 0;
        $isEmploye = ($r['employee_type'] === 'employe');
        $c8  = clampCnssBase($subjBase, 'maladie_maternite', $m, $y) * (getRateAsOf('cnss_employer_rate', $m, $y, 8) / 100);
        $fam = $isEmploye ? clampCnssBase($subjBase, 'allocations_familiales', $m, $y) * (getRateAsOf('family_compensation_rate', $m, $y, 6) / 100) : 0;
        $eos = $isEmploye ? clampCnssBase($subjBase, 'fin_de_service', $m, $y) * (getRateAsOf('end_of_service_rate', $m, $y, 8.5) / 100) : 0;
        $upd->execute([':c8' => round($c8), ':fam' => round($fam), ':eos' => round($eos), ':id' => $r['id']]);
        $n++;
    }
    return $n;
}

/**
 * Apply biennial promotion (every 2 years on 1/10) — تطبيق التدرّج
 *
 * قاعدة القانون: التدرّج درجة عادية يأخذها الأستاذ في 1/10. الدرجات الاستثنائية
 * تُعطى عادةً في 1/1 (كانون) فيجوز اجتماع درجة عادية (تشرين) ودرجة استثنائية
 * (كانون) في نفس السنة بلا مشكلة. لكن إذا صادفت درجة استثنائية في نفس تاريخ
 * 1/10 بالضبط، لا تُجمَع الدرجتان: تؤجَّل الدرجة العادية إلى 1/10 من السنة
 * التالية (وتستمر بالتأجيل سنةً سنة إذا تكرّر التصادف).
 *
 * @param int      $effectiveYear سنة مفعول التدرّج (الافتراضي السنة الحالية)
 * @return array|false ['old_grade','new_grade','change_date','deferred']
 */
function applyBiennialPromotion($employeeId, $manual = false, $effectiveYear = null) {
    $stmt = getDB()->prepare("SELECT * FROM employees WHERE id = ? AND employee_type = 'enseignant_titulaire'");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp || !$emp['titularization_date']) return false;

    $newGrade = (float)$emp['current_grade'] + 1;
    if ($newGrade > 52) return false;

    $year = (int)($effectiveYear ?: date('Y'));

    // تأجيل سنةً سنة فقط إذا صادفت درجة استثنائية في نفس تاريخ 1/10 بالضبط
    // (الاجتماع مع استثنائية بتاريخ آخر من نفس السنة — مثلاً 1/1 — مسموح).
    $deferred = false;
    $guard = 0;
    $chk = getDB()->prepare(
        "SELECT COUNT(*) FROM employee_grade_history
         WHERE employee_id = ? AND law_reference IS NOT NULL AND change_date = ?"
    );
    $changeDate = sprintf('%04d-10-01', $year);
    while ($guard++ < 60) {
        $chk->execute([$employeeId, $changeDate]);
        if ((int)$chk->fetchColumn() === 0) break; // تاريخ 1/10 خالٍ من درجة استثنائية
        $year++;
        $changeDate = sprintf('%04d-10-01', $year);
        $deferred = true;
    }

    getDB()->beginTransaction();
    try {
        getDB()->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")
               ->execute([$newGrade, $employeeId]);

        $note = ($manual ? 'Manual application' : 'Auto biennial promotion')
              . ($deferred ? ' — مؤجَّل: درجة استثنائية في نفس السنة' : '');
        getDB()->prepare("INSERT INTO employee_grade_history (employee_id, grade_before, grade_after, change_date, reason, notes) VALUES (?, ?, ?, ?, 'biennial_promotion', ?)")
               ->execute([$employeeId, (float)$emp['current_grade'], $newGrade, $changeDate, $note]);

        getDB()->commit();
        return ['old_grade' => (float)$emp['current_grade'], 'new_grade' => $newGrade, 'change_date' => $changeDate, 'deferred' => $deferred];
    } catch (Exception $e) {
        getDB()->rollBack();
        throw $e;
    }
}

/**
 * يحسب تاريخ مفعول الدرجة الاستثنائية لأستاذ معيّن. **مصدر واحد** للحقيقة يُستعمل في
 * applyExceptionalLaw (التطبيق الفعلي) وفي عرض «تابلو الدرجات الاستثنائية» بـgrades.php
 * (حتى يتطابق التاريخ المعروض مع المطبَّق). القاعدة (قول المستخدم: «بعد التثبيت دغري
 * ببلّش الأستاذ ياخذ الدرجات الاستثنائية بكانون الثاني»):
 *  - الشهر دائماً 1/1 (كانون الثاني) — لا تشرين الأول (الأخير للتدرّج العادي فقط).
 *  - السنة = max(سنة القانون، سنة **التثبيت** + 1): فالأستاذ يبدأ بأخذ الدرجات الاستثنائية
 *    في **أوّل كانون ثاني بعد تثبيته**. مثال: جما دخلت الملاك 2023 وتثبّتت 1/10/2025 →
 *    أول استثنائية كانون 2026. (التثبيت ≠ دخول الملاك؛ التثبيت يأتي بعد فترة من الدخول.)
 *  - $referenceDate = تاريخ التثبيت (tenure_confirmation_date)، وإن كان فارغاً يُستعمل
 *    تاريخ الملاك (titularization_date) كاحتياط.
 * @param array $law صفّ من exceptional_grades_laws (يحوي effective_date).
 * @param string|null $referenceDate تاريخ التثبيت (أو الملاك احتياطاً).
 */
function exceptionalLawEffectiveDate($law, $referenceDate) {
    $lawYear = (int)date('Y', strtotime($law['effective_date']));
    $excYear = $lawYear;
    if (!empty($referenceDate)) {
        $firstJanAfterRef = (int)date('Y', strtotime($referenceDate)) + 1;
        $excYear = max($lawYear, $firstJanAfterRef);
    }
    return $excYear . '-01-01';
}

/**
 * تقسيم مقدار درجات إلى **وحدات مفردة**: كل درجة كاملة +1 لحالها، والكسر الأخير (½ عادةً) لحاله.
 * مثال: 3 → [1,1,1]؛ 4.5 → [1,1,1,1,0.5]؛ 0.5 → [0.5]؛ 6 → [1,1,1,1,1,1].
 */
function splitGradeUnits($amount) {
    $amount = round((float)$amount, 1);
    $units = [];
    $guard = 0;
    while ($amount > 0.001 && $guard++ < 120) {
        $u = ($amount >= 1) ? 1.0 : $amount;
        $units[] = $u;
        $amount = round($amount - $u, 1);
    }
    return $units;
}

/**
 * تاريخ **دخول الملاك** (الترسيم) = منه تبدأ درجة الدخول والتدرّج كاملاً (قاعدة المستخدم).
 * = `titularization_date` (المصحّح = دخول المدرسة + سنتين)، واحتياطاً `hire_date`+سنتين إن غاب.
 */
function employeeEntryDate($emp) {
    if (!empty($emp['titularization_date'])) return $emp['titularization_date'];
    return !empty($emp['hire_date']) ? date('Y-m-d', strtotime($emp['hire_date'] . ' +2 years')) : null;
}

/**
 * تاريخ **الملاك/التثبيت** = نفس تاريخ دخول الملاك (عنده الدرجة الفورية إلا الإجازة التعليمية،
 * ومنه تبدأ الاستثنائية بكانون). أولوية لتجاوز يدوي عبر `tenure_confirmation_date`.
 */
function tenureReferenceDate($emp) {
    if (!empty($emp['tenure_confirmation_date'])) return $emp['tenure_confirmation_date'];
    return employeeEntryDate($emp);
}

/**
 * يبني سجلّ درجات الأستاذ الملاك **حسب القانون تلقائياً** (لا ملموماً) — المصدر الواحد لإعادة البناء.
 * القاعدة (مطابِقة لورقة المستخدم لجمّا 100%):
 *  - درجة الدخول (`starting_grade` حسب الشهادة) سارية من **دخول المدرسة** (`hire_date`).
 *  - يبقى عليها حتى **التثبيت** (= دخول المدرسة + سنتان، أو tenure_confirmation_date يدوياً).
 *  - **تدرّج عادي +1** عند التثبيت (1/10) ثم +1 كل سنتين (1/10) حتى تاريخ اليوم (لا يتجاوز الدرجة الحالية).
 *  - **الدرجات الاستثنائية** (المتبقّي = current_grade − درجة الدخول − عدد التدرّجات) تُوزّع **درجة كل
 *    سنة بكانون الثاني** بدءاً من السنة التالية للتثبيت حتى الوصول للدرجة الحالية.
 * المجموع النهائي = `current_grade` المخزّنة (حقيقة ضبطها المستخدم) فلا يتغيّر؛ يتغيّر **توزيع** الدرجات
 * على السنين فقط، فيظهر الراتب متدرّجاً بدل أن يكون ثابتاً. يرجّع ملخّصاً للتحقّق.
 * @param string|null $todayOverride للاختبار فقط (تثبيت «اليوم»)، وإلا تاريخ النظام.
 */
function buildLegalGradeHistory($empId, $todayOverride = null, $dryRun = false) {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM employees WHERE id=?");
    $st->execute([$empId]); $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) throw new Exception("الموظف غير موجود");
    if ($emp['employee_type'] !== 'enseignant_titulaire') throw new Exception("ليس أستاذاً ملاكاً — لا تُبنى له درجات");
    $entryDate = employeeEntryDate($emp);                      // دخول المدرسة = hire_date
    if (!$entryDate) throw new Exception("لا يوجد تاريخ دخول المدرسة (hire_date)");

    $start   = (float)$emp['starting_grade'];
    $current = (float)$emp['current_grade'];
    $tenureDate = tenureReferenceDate($emp);                   // التثبيت = دخول + سنتان (أو override)
    $tenureYear = (int)date('Y', strtotime($tenureDate));
    $todayTs = $todayOverride ? strtotime($todayOverride) : time();

    // ===== منطق التدرّج العادي منقول حرفياً من برنامج المستخدم المرجعي «ف7» (calcEchelonFromTable) =====
    // درجة عادية فورية عند دخول الملاك لكل الشهادات إلا الإجازة التعليمية (gets_immediate_grade=0 → noT).
    $gi = $db->prepare("SELECT gets_immediate_grade FROM diploma_starting_grades WHERE diploma_code = ?");
    $gi->execute([$emp['diploma']]);
    $getsImmediate = $gi->fetchColumn();
    $getsImmediate = ($getsImmediate === false) ? 1 : (int)$getsImmediate;
    $noT = ($getsImmediate === 0); // الإجازة التعليمية: لا درجة فورية، أوّل عادية بعد سنتين

    $mAY = (int)date('Y', strtotime($entryDate));   // سنة دخول الملاك
    // السنة الدراسية الحالية (تشرين→أيلول): شهر ≥ 10 → السنة، وإلا السنة − 1
    $tM = (int)date('n', $todayTs); $tY = (int)date('Y', $todayTs);
    $cAY = ($tM >= 10) ? $tY : ($tY - 1);
    $yrs = max(0, $cAY - $mAY);
    $entryTs = strtotime($entryDate);

    // ===== الدرجات الاستثنائية تُطبَّق تلقائياً حسب القوانين (auto_apply=1). قانون 344 يدوي دائماً ولا يُبنى آلياً. =====
    $autoLaws = [];
    foreach ($db->query("SELECT * FROM exceptional_grades_laws WHERE is_active=1 AND auto_apply=1") as $L) {
        $autoLaws[(string)$L['law_number']] = $L;
    }
    // جداول المنح التاريخية الثابتة (سنة كانون 1/1 → عدد الدرجات). نصف 223 = آلية تقديم التدرّج (مطبّقة في $adv).
    $histSchedule = [
        '244' => [2001 => 1, 2002 => 1, 2003 => 1],
        '102' => [2009 => 1, 2010 => 1, 2011 => 1],
        '223' => [2010 => 2, 2011 => 2],
    ];

    $isNew = ($entryTs > strtotime('2012-04-02')); // أستاذ جديد (نظام 4+4+2)
    $lastBatchYear = $mAY + 3;                      // سنة آخر دفعة 4+4+2 (للجديد)
    // 🔴🔴 التدرّج العادي يجري على جدوله الطبيعي **دائماً** (لا يُعطَّل بانتظار إكمال الدرجات
    // الاستثنائية). صحّحه المستخدم بمرجع مارغريتا بونصار (مارغريتا.xlsx، 2026-06-22): إجازة تعليمية
    // ملاك 1/10/2023 أخذت درجتها العادية في تشرين 2025 (ملاك+سنتان) رغم أنّ دفعة 4+4+2 الأخيرة
    // في كانون 2026 — فالشرط القديم `$completed` (الذي كان يؤجّل العادية حتى تشرين mAY+3) كان
    // يُسقِط هذه الدرجة خطأً ويُنقِص الراتب درجةً كاملة طوال السنة. أُزيل الشرط.
    // التحقّق: مارغريتا → 26 (مطابق الإكسل رقماً برقم)؛ المراجع ثابتة (جما 11، شربل مرعي 19،
    // مارسيلا 23 [نص floor]، أندريه/القدامى بلا تغيير لأنّ شرطهم كان مستوفىً أصلاً).
    $events = [];
    // التدرّج العادي = نصف درجة (0.5) لكل سنة (كل سنتين درجة كاملة).
    // - غير الإجازة التعليمية: **درجة عادية كاملة فورية (+1)** عند دخول الملاك (دائماً).
    // - 0.5/سنة من السنة التالية للدخول (إجازة تعليمية: أوّل نصف في mAY+1 فتكتمل درجة في mAY+2).
    if (!$noT) {
        $events[] = ['date' => $mAY . '-10-01', 'type' => 'ordinary', 'delta' => 1.0]; // فورية كاملة
    }
    for ($y = $mAY + 1; $y <= $cAY; $y++) {
        $events[] = ['date' => $y . '-10-01', 'type' => 'ordinary', 'delta' => 0.5];
    }

    if ($entryTs <= strtotime('2012-04-02')) {
        // ----- أستاذ قديم (دخل الملاك ضمن حقبة المنح التاريخية ≤ 2/4/2012): يأخذها **بالكامل** -----
        // القاعدة (مؤكَّدة من المستخدم 2026-06-21): الدرجات الاستثنائية التاريخية (244+102+223) تُعطى
        // كاملةً لكل أستاذ ملاك من تلك الحقبة، **بغضّ النظر عن تاريخ دخوله الملاك** مقابل تواريخ المنح
        // — بشرط أن يكون قد مرّ على دخوله الملاك **3 سنوات على الأقل**. أما من دخل الملاك بعد هذه
        // الحقبة فيأخذ نسخة المستجدّ (4+4+2) في الفرع التالي (نصّ ملف القوانين: «بعد هذه التواريخ»).
        $inMalakYears = ($todayTs - $entryTs) / (365.25 * 86400);
        if ($inMalakYears >= 3) {
            foreach ($histSchedule as $ln => $sched) {
                if (!isset($autoLaws[$ln])) continue;             // معطّل/يدوي → تخطَّ
                foreach ($sched as $y => $cnt) {
                    // المنحة بتاريخها إن وقعت بعد دخول الملاك، وإلا تُقيَّد عند دخول الملاك (لا قبل التثبيت)
                    $gd = (strtotime("$y-01-01") >= $entryTs) ? "$y-01-01" : $entryDate;
                    if (strtotime($gd) <= $todayTs) {
                        $events[] = ['date' => $gd, 'type' => 'exceptional', 'delta' => $cnt, 'law' => $ln];
                    }
                }
            }
            // نصف درجة قانون 223 «تقديم التدرّج سنة» = نصف درجة استثنائية صريحة
            if (isset($autoLaws['223'])) {
                $events[] = ['date' => $entryDate, 'type' => 'exceptional', 'delta' => 0.5, 'law' => '223'];
            }
            if (isset($autoLaws['2017'])) {
                $n2017 = law2017GradesFor($emp);                  // 6 / 2 / 0 حسب الأستاذ
                if ($n2017 > 0) {
                    $d2017 = exceptionalLawEffectiveDate($autoLaws['2017'], $entryDate);
                    if (strtotime($d2017) < $entryTs) $d2017 = $entryDate;
                    if (strtotime($d2017) <= $todayTs) {
                        $events[] = ['date' => $d2017, 'type' => 'exceptional', 'delta' => $n2017, 'law' => '2017'];
                    }
                }
            }
        }
    } else {
        // ----- أستاذ داخل بعد حقبة المنح التاريخية (> 2/4/2012): نسخة المستجدّ 4 + 4 + 2 -----
        // ملف القوانين: «أما الأساتذة الذين يدخلون الملاك بعد هذه التواريخ: يحصلون على أربع درجات
        // في كانون بعد دخولهم، ثم أربع في الذي يليه، ثم درجتين، ثم يُقدَّم تدرّجهم سنة واحدة.»
        $batches = [1 => 4, 2 => 4, 3 => 2];
        foreach ($batches as $k => $amt) {
            $y = $mAY + $k;
            if (strtotime("$y-01-01") <= $todayTs) {
                $events[] = ['date' => "$y-01-01", 'type' => 'exceptional', 'delta' => $amt];
            }
        }
        // «يُقدَّم تدرّجهم سنة واحدة» = نصف درجة إضافية تظهر عند تشرين سنة آخر دفعة 4+4+2 (mAY+3)
        // — تُضاف فقط بعد أن يحلّ ذلك التاريخ فعلاً (الجديد غير المكتمل لا تصله بعد). [مؤكَّد كميل]
        if (strtotime($lastBatchYear . '-10-01') <= $todayTs) {
            $events[] = ['date' => $lastBatchYear . '-10-01', 'type' => 'ordinary', 'delta' => 0.5, 'comp' => true];
        }
        // قانون 2017 يبقى مستحقّاً لمن دخل الملاك حتى 30/9/2017 (law2017GradesFor يرجع 0 لمن دخل بعدها)
        if (isset($autoLaws['2017'])) {
            $n2017 = law2017GradesFor($emp);                      // 6 / 2 / 0 حسب الشهادة وتاريخ الملاك
            if ($n2017 > 0) {
                $d2017 = exceptionalLawEffectiveDate($autoLaws['2017'], $entryDate);
                if (strtotime($d2017) < $entryTs) $d2017 = $entryDate;
                if (strtotime($d2017) <= $todayTs) {
                    $events[] = ['date' => $d2017, 'type' => 'exceptional', 'delta' => $n2017, 'law' => '2017'];
                }
            }
        }
    }

    // ----- قوانين تلقائية أخرى أضافها المستخدم (غير المعروفة و≠344): تُطبَّق إن كان دخول الملاك ضمن نافذتها -----
    foreach ($autoLaws as $ln => $L) {
        if (in_array((string)$ln, ['244','102','223','2017','344'], true)) continue;
        $to = $L['effective_to'];
        if ($to && $entryTs > strtotime($to)) continue;           // دخل بعد انتهاء القانون → غير مستحقّ
        $d = exceptionalLawEffectiveDate($L, $entryDate);
        if (strtotime($d) <= $todayTs && (float)$L['grades_count'] > 0) {
            $events[] = ['date' => $d, 'type' => 'exceptional', 'delta' => (float)$L['grades_count'], 'law' => $ln];
        }
    }

    // ----- الحفاظ على قانون 344 المطبّق يدوياً (لا يُمَسّ من البناء التلقائي) -----
    $man = $db->prepare("SELECT change_date, (grade_after - grade_before) d FROM employee_grade_history WHERE employee_id=? AND law_reference='344'");
    $man->execute([$empId]);
    foreach ($man as $m) {
        $events[] = ['date' => $m['change_date'], 'type' => 'exceptional', 'delta' => (float)$m['d'], 'law' => '344'];
    }

    // ----- الحفاظ على الدرجات اليدوية (reason='manual'، بقرار المستخدم خارج القانون) — تُعاد كما هي بعد البناء -----
    $manualRows = $db->prepare("SELECT change_date, COALESCE(delta, grade_after-grade_before) d, counted, notes
                                FROM employee_grade_history WHERE employee_id=? AND reason='manual'");
    $manualRows->execute([$empId]);
    $manualRows = $manualRows->fetchAll(PDO::FETCH_ASSOC);

    usort($events, fn($a,$b) => strcmp($a['date'], $b['date']));

    // 🔵 كل درجة استثنائية تُخزَّن **مفردة** (+1، والكسر الأخير +½ لحاله) — طلب المستخدم:
    //    3 درجات = 1+1+1، و4.5 = 1+1+1+1+½، كل وحدة صفّ مستقل بتاريخها وشك-ماركها.
    //    التقسيم لا يغيّر المجموع ولا التاريخ ولا الراتب — عرض/تفصيل فقط. (العادية تبقى كما هي: ≤1.)
    $unitEvents = [];
    foreach ($events as $ev) {
        if ($ev['type'] === 'exceptional' && round((float)$ev['delta'], 1) > 1) {
            foreach (splitGradeUnits($ev['delta']) as $u) { $e2 = $ev; $e2['delta'] = $u; $unitEvents[] = $e2; }
        } else {
            $unitEvents[] = $ev;
        }
    }
    $events = $unitEvents;

    // وضع «حساب فقط» (dryRun): يحسب الدرجة النهائية من القانون بلا أي كتابة على القاعدة —
    // للمقارنة بين ما يعطيه القانون وما هو مخزَّن (فحص شامل بلا تعديل بيانات).
    if ($dryRun) {
        $g = $start; $nExc = 0.0; $nOrd = 0.0; $byLaw = [];
        foreach ($events as $ev) {
            $after = min(52, round($g + $ev['delta'], 1));
            if ($after == $g) continue;
            if ($ev['type'] === 'ordinary') $nOrd += ($after - $g);
            else { $nExc += ($after - $g); $byLaw[$ev['law'] ?? '4+4+2'] = ($byLaw[$ev['law'] ?? '4+4+2'] ?? 0) + ($after - $g); }
            $g = $after;
        }
        // الدرجات اليدوية المحسوبة (دخلت أساس الراتب) تُضاف للدرجة المتوقّعة حتى تطابق المخزّنة (لا تُعتبر «انحرافاً»).
        $todayStr = date('Y-m-d', $todayTs);
        foreach ($manualRows as $mr) {
            if ((int)$mr['counted'] && $mr['change_date'] <= $todayStr) $g = min(52, round($g + (float)$mr['d'], 1));
        }
        return ['start'=>$start, 'entry_date'=>$entryDate, 'ordinary'=>$nOrd,
                'exceptional'=>$nExc, 'final_grade'=>$g, 'events'=>count($events)+1, 'by_law'=>$byLaw, 'dry'=>true];
    }

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM employee_grade_history WHERE employee_id=?")->execute([$empId]);
        // إعادة البناء تلقائياً = كل الدرجات محسوبة (counted=1)؛ المستخدم يشيل ما لا يريده لاحقاً بملف الدرجات.
        $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,notes) VALUES (?,0,?,?,1,?,'titularization','دخول الملاك')")
           ->execute([$empId, $start, $start, $entryDate]);
        $g = $start; $nExc = 0; $nOrd = 0;
        foreach ($events as $ev) {
            $before = $g; $after = min(52, round($g + $ev['delta'], 1));
            if ($after == $before) continue;                      // لا تَكتب صفّاً صفريّاً (وصلت السقف)
            $delta = round($after - $before, 1);
            if ($ev['type'] === 'ordinary') {
                $nOrd += ($after - $before);
                $note = !empty($ev['comp']) ? 'تقديم التدرّج (تشرين)' : 'تدرّج عادي (تشرين)';
                $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,notes) VALUES (?,?,?,?,1,?,'biennial_promotion',?)")
                   ->execute([$empId, $before, $after, $delta, $ev['date'], $note]);
            } else {
                $nExc += ($after - $before);
                $lawRef = !empty($ev['law']) ? $ev['law'] : null;
                $note = $lawRef ? ('قانون ' . $lawRef) : 'درجات استثنائية (4+4+2)';
                $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,law_reference,notes) VALUES (?,?,?,?,1,?,'exceptional',?,?)")
                   ->execute([$empId, $before, $after, $delta, $ev['date'], $lawRef, $note]);
            }
            $g = $after;
        }
        $db->prepare("UPDATE employees SET current_grade=? WHERE id=?")->execute([$g, $empId]);
        // أعِد إدراج الدرجات اليدوية المحفوظة (بقيمها وتواريخها وحالة احتسابها)، ثم أعِد الربط لتشمل الدرجة الحالية.
        if (!empty($manualRows)) {
            $insM = $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,law_reference,notes) VALUES (?,0,?,?,?,?,'manual',NULL,?)");
            foreach ($manualRows as $mr) {
                $insM->execute([$empId, (float)$mr['d'], (float)$mr['d'], (int)$mr['counted'], $mr['change_date'], ($mr['notes'] !== null && $mr['notes'] !== '') ? $mr['notes'] : 'درجة يدوية (بقرار المدرسة)']);
            }
            $g = rechainGradeHistory($empId);   // يعيد الحساب شاملاً اليدوية (يحترم التاريخ/الاحتساب)
        }
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); throw $e; }

    return ['start'=>$start, 'entry_date'=>$entryDate, 'ordinary'=>$nOrd,
            'exceptional'=>$nExc, 'final_grade'=>$g, 'events'=>count($events)+1];
}

/**
 * فحص مطابقة القانون: لكل أستاذ ملاك (ضمن المدارس المختارة) يحسب الدرجة من القوانين
 * (buildLegalGradeHistory في وضع dryRun، بلا أي كتابة) ويقارنها بالدرجة المخزّنة.
 * يرجع صفّاً لكل أستاذ: {id, name_ar/fr, diploma, school_id, stored, law, gap, ok, err}.
 * $schoolIds = مصفوفة معرّفات مدارس (أو null/فارغة = كل المدارس).
 * لا يعدّل أي بيانات إطلاقاً — للعرض والمراقبة فقط.
 */
function lawConsistencyCheck($schoolIds = null, $schoolYear = null) {
    $db = getDB();
    // 🔴 «الأرقام تركب»: نفس فلتر السنة الدراسية المعتمد بكل البرنامج (yearEmploymentFilter) —
    // يُحتسب فقط أساتذة الملاك الموجودون فعلاً بالسنة المختارة، والتارك لا يظهر بعد سنة تركه
    // (كان الفحص يعدّ كل الملاك التاريخيين بمن فيهم التاركين فيطلع العدد منفوخاً).
    if ($schoolYear === null) $schoolYear = activeSchoolYear();
    [$yf, $yp] = yearEmploymentFilter($schoolYear);
    $sql = "SELECT id, first_name_ar, last_name_ar, first_name_fr, last_name_fr, diploma,
                   school_id, starting_grade, current_grade, hire_date, titularization_date
            FROM employees WHERE employee_type='enseignant_titulaire' AND is_deleted=0" . $yf;
    if (is_array($schoolIds) && !empty($schoolIds)) {
        $in = implode(',', array_map('intval', $schoolIds));
        $sql .= " AND school_id IN ($in)";
    }
    $sql .= " ORDER BY school_id, COALESCE(NULLIF(first_name_ar,''),first_name_fr), COALESCE(NULLIF(last_name_ar,''),last_name_fr)";
    $st = $db->prepare($sql);
    $st->execute($yp);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $e) {
        $stored = (float)$e['current_grade'];
        try { $r = buildLegalGradeHistory((int)$e['id'], null, true); $law = (float)$r['final_grade']; $err = null; }
        catch (Throwable $ex) { $law = null; $err = $ex->getMessage(); }
        $ok = ($law !== null && abs($law - $stored) < 0.001);
        $out[] = [
            'id' => (int)$e['id'],
            'name_ar' => trim(($e['first_name_ar'] ?? '') . ' ' . ($e['last_name_ar'] ?? '')),
            'name_fr' => trim(($e['first_name_fr'] ?? '') . ' ' . ($e['last_name_fr'] ?? '')),
            'diploma' => $e['diploma'], 'school_id' => (int)$e['school_id'],
            'stored' => $stored, 'law' => $law,
            'gap' => ($law === null ? null : round($law - $stored, 1)),
            'ok' => $ok, 'err' => $err,
        ];
    }
    return $out;
}

/**
 * فحص مطابقة القانون لأستاذ واحد (نفس منطق lawConsistencyCheck لصفّ واحد).
 */
function lawConsistencyCheckOne($empId) {
    $res = null;
    try { $r = buildLegalGradeHistory((int)$empId, null, true); $law = (float)$r['final_grade']; $err = null; }
    catch (Throwable $ex) { $law = null; $err = $ex->getMessage(); }
    $stored = (float)getDB()->query("SELECT current_grade FROM employees WHERE id=" . (int)$empId)->fetchColumn();
    return ['stored' => $stored, 'law' => $law,
            'gap' => ($law === null ? null : round($law - $stored, 1)),
            'ok' => ($law !== null && abs($law - $stored) < 0.001), 'err' => $err];
}

/**
 * 🔵 شفاء ذاتي: تقسيم الدرجات الاستثنائية **الملمومة** (مقدارها > 1) لأستاذ واحد إلى وحدات مفردة
 * (+1، والكسر ½) — كل درجة صفّ مستقل بتاريخها وشك-ماركها. آمن تماماً: لا يغيّر المجموع ولا التواريخ
 * ولا الرواتب (يتحقّق أنّ الدرجة الحالية لم تتغيّر، وإلا يتراجع). يُستدعى عند عرض لوحة الدرجات فيُطبَّق
 * مرّة واحدة لكل أستاذ (بعدها لا يبقى صفّ ملموم فيصير لا-عمل). يرجّع true إن جرى تقسيم.
 */
function splitExceptionalUnitsForEmployee($empId) {
    $db = getDB();
    $empId = (int)$empId;
    $lumped = $db->query("SELECT * FROM employee_grade_history
        WHERE employee_id=$empId AND reason NOT IN ('titularization','biennial_promotion','manual')
          AND ROUND(ABS(COALESCE(delta, grade_after-grade_before)),1) > 1
        ORDER BY change_date, id")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lumped)) return false;
    $before = (float)$db->query("SELECT current_grade FROM employees WHERE id=$empId")->fetchColumn();
    $ownTx = !$db->inTransaction();
    if ($ownTx) $db->beginTransaction();
    try {
        $ins = $db->prepare("INSERT INTO employee_grade_history (employee_id,grade_before,grade_after,delta,counted,change_date,reason,law_reference,notes) VALUES (?,?,?,?,?,?,?,?,?)");
        $del = $db->prepare("DELETE FROM employee_grade_history WHERE id=?");
        foreach ($lumped as $r) {
            $eff = round((float)($r['delta'] !== null ? $r['delta'] : ($r['grade_after'] - $r['grade_before'])), 1);
            $sign = $eff < 0 ? -1 : 1;
            foreach (splitGradeUnits(abs($eff)) as $u) {
                $ins->execute([$empId, 0, $sign * $u, $sign * $u, $r['counted'], $r['change_date'], $r['reason'], $r['law_reference'], $r['notes']]);
            }
            $del->execute([(int)$r['id']]);
        }
        $after = rechainGradeHistory($empId);            // يعيد الربط + الدرجة الحالية (لغاية اليوم)
        if (abs($after - $before) > 0.001) throw new Exception("split mismatch $before/$after");
        if ($ownTx) $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) $db->rollBack();
        return false;                                    // فشل آمن: يبقى ملموماً بلا أي ضرر
    }
}

/**
 * إعادة ربط سلسلة درجات الأستاذ من عمود `delta` و`counted` (بلا حذف أي صف):
 * يمشي على الأحداث بالترتيب الزمني، يبدأ من 0، ويطبّق delta كل حدث **محسوب** فقط
 * (counted=1، ودخول الملاك دائماً محسوب)؛ الحدث غير المحسوب يبقى ظاهراً لكن لا يُقدّم الدرجة
 * (grade_before=grade_after) مع الحفاظ على delta الأصلي ليُعاد تفعيله عند إعادة التأشير.
 * يحدّث grade_before/grade_after لكل صف + current_grade. يرجع الدرجة النهائية.
 * **لا يلمس عمود delta إطلاقاً** (هو القيمة الثابتة لكل درجة).
 */
function rechainGradeHistory($empId) {
    $db = getDB();
    $rows = $db->query("SELECT id, reason, grade_before, grade_after, delta, counted, change_date
                        FROM employee_grade_history WHERE employee_id=" . (int)$empId . "
                        ORDER BY change_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $ownTx = !$db->inTransaction();
    if ($ownTx) $db->beginTransaction();
    try {
        $running = 0.0;
        $today = date('Y-m-d');
        $asOfToday = 0.0;   // الدرجة الحالية = القيمة لغاية اليوم (الأسطر المستقبلية «فتح السنة» تُسلسَل لكن لا تدخل current_grade)
        $upd = $db->prepare("UPDATE employee_grade_history SET grade_before=?, grade_after=? WHERE id=?");
        foreach ($rows as $r) {
            $isTitul = ($r['reason'] === 'titularization');
            $delta = ($r['delta'] === null) ? ((float)$r['grade_after'] - (float)$r['grade_before']) : (float)$r['delta'];
            $counts = $isTitul || ((int)$r['counted'] === 1);
            $before = $running;
            $after  = $counts ? round(min(52, $running + $delta), 1) : $running;
            $upd->execute([$before, $after, (int)$r['id']]);
            $running = $after;
            if ($r['change_date'] <= $today) $asOfToday = $running;   // آخر درجة سارية اليوم
        }
        $db->prepare("UPDATE employees SET current_grade=? WHERE id=?")->execute([$asOfToday, $empId]);
        if ($ownTx) $db->commit();
        return $asOfToday;
    } catch (Throwable $e) { if ($ownTx) $db->rollBack(); throw $e; }
}

/**
 * عدد درجات **قانون 2017** الاستثنائية لأستاذ معيّن (شطور مشروطة حسب ورقة المستخدم):
 *  - دخل الملاك **قبل 1/1/2010** (أي شهادة) → **6 درجات**.
 *  - دخل الملاك **1/1/2010 → 30/9/2017**: قسم ثاني → **6**؛ إجازة جامعية أو جاردينير ب.ت أو جاردينير ت.س → **2**.
 *  - غير ذلك (دخل بعد 30/9/2017، أو إجازة تعليمية/كابس في نافذة 2010-2017) → **0** (لا ينطبق).
 * يُحتسب على تاريخ **دخول الملاك** (titularization_date، واحتياطاً hire_date+سنتين).
 */
function law2017GradesFor($emp) {
    $titul = !empty($emp['titularization_date'])
        ? $emp['titularization_date']
        : (!empty($emp['hire_date']) ? date('Y-m-d', strtotime($emp['hire_date'] . ' +2 years')) : null);
    if (!$titul) return 0.0;
    $t = strtotime($titul);
    if ($t < strtotime('2010-01-01')) return 6.0;                 // ملاك قبل 2010 → 6 لكل الشهادات
    if ($t <= strtotime('2017-09-30')) {                          // ملاك 2010 → 30/9/2017
        if ($emp['diploma'] === 'qsm2_thanawiya') return 6.0;
        if (in_array($emp['diploma'], ['ijaza_jamiya', 'jardinier_bt', 'jardinier_ts'], true)) return 2.0;
        return 0.0;
    }
    return 0.0;                                                   // ملاك بعد 30/9/2017 → قانون 2017 لا ينطبق
}

/**
 * عدد درجات قانون استثنائي لأستاذ معيّن: قانون 2017 مشروط (law2017GradesFor)، وإلا القيمة الثابتة.
 */
function lawGradesForEmployee($law, $emp) {
    if ((string)$law['law_number'] === '2017') return law2017GradesFor($emp);
    return (float)$law['grades_count'];
}

/**
 * وحدات درجة استثنائية **لم تُعطَ بعد** لأستاذ عن قانون معيّن (لعرضها بشك-مارك فاضي + منحها فردياً).
 * = (مجموع درجات القانون لهذا الأستاذ) − (المُعطى فعلاً)، مقسوماً وحداتٍ مفردة (+1 والكسر ½).
 * يرجّع مصفوفة [['delta'=>1.0|0.5, 'date'=>'YYYY-01-01'], ...]. فارغة = القانون مُعطى كاملاً أو لا ينطبق.
 * التاريخ الافتراضي يتدرّج سنةً بسنة من التاريخ القانوني بعد ما أُعطي منه (والمستخدم يعدّله كما يشاء).
 */
function exceptionalGrantUnits($emp, $law) {
    $total = lawGradesForEmployee($law, $emp);
    if ($total <= 0) return [];
    $db = getDB();
    $q = $db->quote((string)$law['law_number']);
    $id = (int)$emp['id'];
    $granted = (float)$db->query("SELECT COALESCE(SUM(COALESCE(delta, grade_after-grade_before)),0)
                                  FROM employee_grade_history WHERE employee_id=$id AND law_reference=$q")->fetchColumn();
    $grantedCount = (int)$db->query("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id=$id AND law_reference=$q")->fetchColumn();
    $remaining = round($total - $granted, 1);
    if ($remaining <= 0.001) return [];
    $base = exceptionalLawEffectiveDate($law, tenureReferenceDate($emp));
    $baseYear = (int)date('Y', strtotime($base));
    $out = [];
    foreach (splitGradeUnits($remaining) as $i => $u) {
        $out[] = ['delta' => $u, 'date' => ($baseYear + $grantedCount + $i) . '-01-01'];
    }
    return $out;
}

/**
 * Apply exceptional grades law
 * @param string|null $dateOverride تاريخ منح مختار من المستخدم (Y-m-d)؛ إن غاب = التاريخ القانوني التلقائي.
 */
function applyExceptionalLaw($employeeId, $lawNumber, $dateOverride = null) {
    $stmt = getDB()->prepare("SELECT * FROM exceptional_grades_laws WHERE law_number = ? AND is_active = 1");
    $stmt->execute([$lawNumber]);
    $law = $stmt->fetch();
    if (!$law) throw new Exception("Law not found");
    
    $stmt = getDB()->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();
    if (!$emp) throw new Exception("Employee not found");
    
    // Check if already applied
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM employee_grade_history WHERE employee_id = ? AND law_reference = ?");
    $stmt->execute([$employeeId, $lawNumber]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Law $lawNumber déjà appliquée à cet employé");
    }
    
    $gradesToAdd = lawGradesForEmployee($law, $emp); // يدعم 4.5 (نصف درجة) + قانون 2017 المشروط (6/2)
    if ($gradesToAdd <= 0) throw new Exception("قانون $lawNumber لا ينطبق على هذا الأستاذ (0 درجة)");
    $newGrade = min(52, (float)$emp['current_grade'] + $gradesToAdd);
    
    getDB()->beginTransaction();
    try {
        $stmt = getDB()->prepare("UPDATE employees SET current_grade = ? WHERE id = ?");
        $stmt->execute([$newGrade, $employeeId]);
        
        $reason = 'exceptional_law_' . $lawNumber;
        // التاريخ: المختار من المستخدم إن وُجد وصحيح، وإلا 1/1 (كانون الثاني) بسنة بعد التثبيت.
        $excDate = ($dateOverride && strtotime($dateOverride))
            ? date('Y-m-d', strtotime($dateOverride))
            : exceptionalLawEffectiveDate($law, tenureReferenceDate($emp));
        // 🔵 نكتب كل درجة **مفردة** (+1، والكسر الأخير ½) صفّاً مستقلاً بـcounted=1 وdelta ثابت —
        //    ليطابق نموذج «كل درجة لحالها». المجموع = gradesToAdd. (المستخدم يعدّل التواريخ فردياً لاحقاً.)
        $ins = getDB()->prepare("INSERT INTO employee_grade_history (employee_id, grade_before, grade_after, delta, counted, change_date, reason, law_reference, notes) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)");
        $run = (float)$emp['current_grade'];
        foreach (splitGradeUnits($gradesToAdd) as $u) {
            $b = $run; $a = min(52, round($run + $u, 1));
            if ($a == $b) continue;                    // بلغ السقف 52
            $ins->execute([$employeeId, $b, $a, $u, $excDate, $reason, $lawNumber, $law['description_ar']]);
            $run = $a;
        }
        getDB()->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([$run, $employeeId]);
        getDB()->commit();
        return ['old_grade' => $emp['current_grade'], 'new_grade' => $run, 'grades_added' => $gradesToAdd];
    } catch (Exception $e) {
        getDB()->rollBack();
        throw $e;
    }
}

/**
 * إلغاء درجة استثنائية عن أستاذ (عكس applyExceptionalLaw): يحذف صفّ السجلّ ذا law_reference
 * ويُنقص الدرجة الحالية بمقدار درجات القانون. يُستعمل من «تابلو الدرجات الاستثنائية» (تشيك مارك)
 * ليتحكّم المستخدم بإعطاء/منع كل درجة استثنائية لكل أستاذ.
 */
function removeExceptionalLaw($employeeId, $lawNumber) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM exceptional_grades_laws WHERE law_number = ?");
    $stmt->execute([$lawNumber]);
    $law = $stmt->fetch();
    if (!$law) throw new Exception("Law not found");

    // الفرق الفعلي من صفّ السجلّ (يضمن إلغاء صحيح حتى للقوانين المشروطة متل 2017 = 6/2)
    $stmt = $db->prepare("SELECT (grade_after - grade_before) FROM employee_grade_history WHERE employee_id = ? AND law_reference = ?");
    $stmt->execute([$employeeId, $lawNumber]);
    $delta = $stmt->fetchColumn();
    if ($delta === false) return false; // غير مطبّقة أصلاً
    $delta = (float)$delta;

    $stmt = $db->prepare("SELECT current_grade FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $cur = (float)$stmt->fetchColumn();
    $newGrade = max(1, $cur - $delta);

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM employee_grade_history WHERE employee_id = ? AND law_reference = ?")->execute([$employeeId, $lawNumber]);
        $db->prepare("UPDATE employees SET current_grade = ? WHERE id = ?")->execute([$newGrade, $employeeId]);
        $db->commit();
        return ['old_grade' => $cur, 'new_grade' => $newGrade, 'grades_removed' => $delta];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * ترقية تلقائية (التدرّج +1) لكل أساتذة الملاك المستحقّين في مدرسة واحدة عن سنة واحدة (1/10/$year).
 * «كل مدرسة بمدرستها وكل سنة بسنتها»: يعالج $schoolId واحدة و$year واحدة فقط.
 *  - مستحقّ هذه السنة = (السنة − سنة الترسيم) موجبة وزوجية (دورة كل سنتين).
 *  - idempotent: يتخطّى من سبق أن أخذ تدرّجاً مسجّلاً بتاريخ 1/10 لهذه السنة.
 *  - حماية من الازدواج: يرفض أي سنة ≤ السنة المرجعية (الدرجات الحالية منقولة من
 *    البرنامج القديم ومحسوبة لغاية 1/10/2025) حتى لا تُضاف درجات محسوبة أصلاً.
 * يعيد ['promoted','not_due','already','max'].
 */
function autoPromoteSchoolYear($schoolId, $year) {
    $schoolId = (int)$schoolId;
    $year = (int)$year;
    $baseline = (int)getSetting('grades_baseline_year', 2025);
    if ($year <= $baseline) {
        throw new Exception("السنة $year محسوبة مسبقاً ضمن الدرجات الحالية (المرجع $baseline). اختر سنة أكبر من $baseline.");
    }
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id, current_grade, titularization_date FROM employees
         WHERE school_id = ? AND employee_type = 'enseignant_titulaire'
           AND is_deleted = 0 AND status = 'actif' AND titularization_date IS NOT NULL"
    );
    $stmt->execute([$schoolId]);
    $rows = $stmt->fetchAll();

    $chk = $db->prepare(
        "SELECT COUNT(*) FROM employee_grade_history
         WHERE employee_id = ? AND reason = 'biennial_promotion' AND change_date = ?"
    );
    $changeDate = sprintf('%04d-10-01', $year);

    $res = ['promoted' => 0, 'not_due' => 0, 'already' => 0, 'max' => 0];
    foreach ($rows as $e) {
        $titYear = (int)substr((string)$e['titularization_date'], 0, 4);
        $diff = $year - $titYear;
        if ($diff <= 0 || ($diff % 2) !== 0) { $res['not_due']++; continue; }
        if ((float)$e['current_grade'] >= 52)  { $res['max']++; continue; }
        $chk->execute([$e['id'], $changeDate]);
        if ((int)$chk->fetchColumn() > 0)      { $res['already']++; continue; }
        $r = applyBiennialPromotion($e['id'], false, $year);
        if ($r !== false) { $res['promoted']++; } else { $res['not_due']++; }
    }
    return $res;
}
