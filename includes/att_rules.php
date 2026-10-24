<?php
/**
 * ✏️💾 نصوص الإفادات المعدَّلة الدائمة (2026-10-24 «وقت بدي عدّل بالإفادة شي كلمة بدي يكون عندي خيار هالكلمة أو الجملة
 *   اللي بعدّلها تضلّ هي الأساسية بالبرنامج وقت بدي أرجع أطبع مرّة تانية… وهالخيار يكون عندو باسوورد»)
 * المبدأ: بوضع «تعديل / كتابة» يغيّر المستخدم كلمة أو جملة بالإفادة ثمّ يكبس «احفظ كأساس» ⇒ يُحفَظ استبدال
 * (النصّ القديم ⇒ النصّ الجديد) لنوع الإفادة ولغتها، ويُطبَّق تلقائياً على كل إفادة من هذا النوع من بعدها (لأي أستاذ).
 * محميّ بكلمة سرّ خاصّة (att_rules_pw_hash بجدول settings) — بلا كلمة السرّ لا حفظ ولا حذف.
 * الجدول يتركّب ذاتياً. الاستبدال نصّي فقط بين الوسوم (لا يمسّ الوسوم ولا السكربتات) ومن أوّل #ppExportArea.
 */

function attRulesInstall(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS attestation_text_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            att_type VARCHAR(60) NOT NULL,
            lang VARCHAR(5) NOT NULL DEFAULT 'ar',
            from_text TEXT NOT NULL,
            to_text TEXT NOT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (att_type, lang)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
}

/** القواعد المحفوظة لنوع إفادة ولغة (الأقدم أوّلاً — الأحدث يطبَّق آخراً) */
function attRulesLoad(string $type, string $lang): array {
    attRulesInstall();
    try {
        $st = getDB()->prepare("SELECT id, att_type, lang, from_text, to_text, created_by, created_at FROM attestation_text_rules WHERE att_type = ? AND lang = ? ORDER BY id");
        $st->execute([$type, $lang]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** تطبيق القواعد على HTML الصفحة: النصّ بين الوسوم فقط، من أوّل #ppExportArea، بلا script/style */
function attRulesApplyHtml(string $html, array $rules): string {
    if (!$rules) return $html;
    $pos = strpos($html, 'id="ppExportArea"');
    if ($pos === false) return $html;
    $head = substr($html, 0, $pos); $body = substr($html, $pos);
    // افصل السكربتات والأنماط كي لا تُمَسّ
    $segs = preg_split('/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>)/is', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($segs as $i => $seg) {
        if ($i % 2 === 1) continue;   // script/style
        $segs[$i] = preg_replace_callback('/>([^<]+)</u', function ($m) use ($rules) {
            $t = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t2 = preg_replace('/[\s\x{00A0}]+/u', ' ', $t);   // كما يراه المتصفّح (innerText): فراغات مطوية — القاعدة حُسبت منه
            foreach ($rules as $r) { if ($r['from_text'] !== '' && strpos($t2, $r['from_text']) !== false) $t2 = str_replace($r['from_text'], $r['to_text'], $t2); }
            if ($t2 === $t) return $m[0];
            return '>' . htmlspecialchars($t2, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false) . '<';
        }, $seg) ?? $seg;
    }
    return $head . implode('', $segs);
}

function attRulesPwHash(): string {
    try { $st = getDB()->prepare("SELECT value FROM settings WHERE `key` = 'att_rules_pw_hash'"); $st->execute(); return (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) { return ''; }
}
function attRulesPwSet(string $pw): void {
    $h = password_hash($pw, PASSWORD_DEFAULT);
    getDB()->prepare("INSERT INTO settings (`key`, `value`, `description`) VALUES ('att_rules_pw_hash', ?, 'كلمة سرّ حفظ نصوص الإفادات كأساس') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$h]);
}
/** تحقّق كلمة السرّ: 'ok' | 'no_pw' (لم تُحدَّد بعد) | 'bad' */
function attRulesPwCheck(string $pw): string {
    $h = attRulesPwHash();
    if ($h === '') return 'no_pw';
    return ($pw !== '' && password_verify($pw, $h)) ? 'ok' : 'bad';
}
