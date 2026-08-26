<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();
foreach (['tax_brackets','tax_bracket_sets','rate_history','family_deductions','tax_family_deductions'] as $t) {
    try { echo "$t: " . implode(',', $db->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN)) . "\n"; }
    catch (Exception $e) { echo "$t: (غير موجود)\n"; }
}
echo "\nrate_history عينة:\n";
try { foreach ($db->query("SELECT param_key, value, effective_from, effective_to FROM rate_history ORDER BY param_key, effective_from LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  {$r['param_key']} = {$r['value']} من {$r['effective_from']} إلى " . ($r['effective_to'] ?: '∞') . "\n"; } catch (Exception $e) { echo $e->getMessage() . "\n"; }
