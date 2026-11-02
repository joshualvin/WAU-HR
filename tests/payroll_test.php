<?php
// Self-check for the payroll rules: php tests/payroll_test.php  (no database needed)
require __DIR__ . '/../inc/payroll.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

// PTKP and TER categories (PP 58/2023)
check('ptkp TK/0', ptkp_amount('TK/0'), 54000000);
check('ptkp K/3', ptkp_amount('K/3'), 72000000);
check('cat K/0', ter_category('K/0'), 'A');
check('cat K/1', ter_category('K/1'), 'B');
check('cat K/3', ter_category('K/3'), 'C');
// TER brackets: limits are inclusive
check('A 5.4jt', ter_rate('A', 5400000), 0);
check('A 5.4jt+1', ter_rate('A', 5400001), .25);
check('A 10jt', ter_rate('A', 10000000), 2);
check('B 10.454jt', ter_rate('B', 10454000), 1.5);
check('C 6.6jt', ter_rate('C', 6600000), 0);
check('C 20jt', ter_rate('C', 20000000), 8);
// Pasal 17 progressive (UU HPP)
check('p17 60jt', pasal17(60000000), 3000000.0);
check('p17 100jt', pasal17(100000000), 9000000.0);   // 3jt + 40jt*15%
check('p17 rounds down to 1000', pasal17(60000999), 3000000.0);
// BPJS on a 10jt wage, Kesehatan cap 12jt, JP cap 10,547,400
$s = payroll_defaults();
$b = bpjs(['gaji_pokok' => 9000000, 'tunjangan' => 1000000, 'bpjs_kes' => 1, 'bpjs_tk' => 1, 'bpjs_jp' => 1], $s);
check('kes co', $b['kes_co'], 400000); check('kes emp', $b['kes_emp'], 100000);
check('jht co', $b['jht_co'], 370000); check('jht emp', $b['jht_emp'], 200000);
check('jp co', $b['jp_co'], 200000); check('jp emp', $b['jp_emp'], 100000);
check('jkk', $b['jkk'], 24000); check('jkm', $b['jkm'], 30000);
$b = bpjs(['gaji_pokok' => 20000000, 'tunjangan' => 0, 'bpjs_kes' => 1, 'bpjs_tk' => 1, 'bpjs_jp' => 1], $s);
check('kes capped', $b['kes_emp'], 120000); check('jp capped', $b['jp_emp'], 105474);
// Overtime: hourly 100,000; work day 2h = 1.5 + 2 = 3.5x; rest day 9h = 7*2 + 3 + 4 = 21x
check('ot workday 2h', overtime_pay(120, 100000, false), 350000);
check('ot workday 30m', overtime_pay(30, 100000, false), 75000);
check('ot rest 9h', overtime_pay(540, 100000, true), 2100000);
echo "payroll_test: $ok checks passed\n";
