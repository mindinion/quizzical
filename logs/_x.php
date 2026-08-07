<?php
$d = json_decode(file_get_contents($argv[1] ?? 'php://stdin'), true);
$r = $d['runs'][0] ?? null;
if (!$r || empty($r['questions'])) { echo "FAIL: " . ($r['error'] ?? 'no data') . "\n"; exit; }
echo "OK retries={$r['stats']['categories_retried']} corrections={$r['stats']['answer_corrections']}\n";
foreach ($r['questions'] as $q) {
    $a = '';
    foreach ($q['options'] ?? [] as $o) if (!empty($o['correct'])) $a = $o['text'];
    echo "Q{$q['position']} [{$q['category']}] => $a\n  {$q['question']}\n";
}
