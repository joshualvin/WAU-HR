<?php
// Self-check for the HR assistant's data guard and tool list: php tests/assistant_test.php  (no network or database)
if (!function_exists('valid_date')) { function valid_date($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d); } }
require __DIR__ . '/../inc/assistant.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

$row = ['nama' => 'Budi', 'nik_ktp' => '3578130809800001', 'NPWP' => '123', 'jabatan' => 'Staff', 'detail' => ['gaji_pokok' => 5000000, 'mulai' => '2020-01-01', 'No_Rek' => '99', 'email' => 'a@b.c']];
check('scrub drops salary, IDs, bank, email at any depth', assistant_scrub([$row, ['nama' => 'Ani', 'phone' => '08']]),
    [['nama' => 'Budi', 'jabatan' => 'Staff', 'detail' => ['mulai' => '2020-01-01']], ['nama' => 'Ani']]);
check('scrub keeps plain values and list items', assistant_scrub(['a', 'b', 3]), ['a', 'b', 3]);
check('result is scrubbed JSON', assistant_result([['nama' => 'Budi', 'gaji' => 1]]), '[{"nama":"Budi"}]');
check('result is cut when too long', strlen(assistant_result([str_repeat('x', 500)], 100)) > 100 && strpos(assistant_result([str_repeat('x', 500)], 100), 'dipotong') !== false, true);

$names = array_column(assistant_tools(), 'name');
check('tool names are unique', count($names), count(array_unique($names)));
check('every tool has a description and schema', count(array_filter(assistant_tools(), function ($t) { return $t['description'] !== '' && isset($t['inputSchema']['type']); })), count($names));
$blocked = assistant_blocked_keys();
$bad = [];
foreach (assistant_tools() as $t) foreach (array_keys((array)$t['inputSchema']['properties']) as $p) foreach ($blocked as $b) if (strpos($p, $b) !== false) $bad[] = $t['name'] . '.' . $p;
check('no tool takes a salary or ID field as input', $bad, []);
check('read-only: no tool name suggests writing', count(array_filter($names, function ($n) { return preg_match('/^(save|update|delete|set|add|create|approve|insert)/', $n); })), 0);

check('range ok', assistant_valid_range('2026-10-01', '2026-10-31'), null);
check('range reversed', assistant_valid_range('2026-10-31', '2026-10-01'), 'Rentang tanggal tidak valid.');
check('range too long', assistant_valid_range('2026-01-01', '2026-12-31'), 'Rentang maksimal 93 hari.');
check('system prompt carries the company and the no-salary rule', [strpos(assistant_system_prompt('CV Contoh'), 'CV Contoh') !== false, strpos(assistant_system_prompt('X'), 'gaji') !== false], [true, true]);
echo "assistant_test: $ok checks passed\n";
