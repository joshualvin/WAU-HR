<?php
// Self-check for the tax simulation: php tests/taxsim_test.php  (no database)
require __DIR__ . '/../inc/payroll.php';
require __DIR__ . '/../inc/taxsim.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$s = payroll_defaults();

// Rp10.000.000 wage, TK/0, all BPJS (worked out by hand): JKK 24.000 + JKM 30.000 + BPJS Kes company 400.000 -> gross 10.454.000,
// TER A bracket up to 10.700.000 = 2,5% -> 261.350; employee BPJS 100.000 + 200.000 + 100.000.
$r = tax_simulate(['gaji_pokok' => 8000000, 'tunjangan' => 2000000, 'lain' => 0, 'ptkp' => 'TK/0', 'bpjs_kes' => 1, 'bpjs_tk' => 1, 'bpjs_jp' => 1], $s);
check('gross', $r['bruto'], 10454000);
check('ter', [$r['cat'], $r['rate']], ['A', 2.5]);
check('january tax', $r['months'][1]['tax'], 261350);
check('january net', $r['months'][1]['net'], 10000000 - 100000 - 200000 - 100000 - 261350);
check('annual pkp', [$r['annual']['bruto'], $r['annual']['biaya_jabatan'], $r['annual']['iuran'], $r['annual']['neto'], $r['annual']['pkp']], [125448000, 6000000, 3600000, 115848000, 61848000]);
check('annual tax pasal 17', $r['annual']['tax'], 3277200);   // 60 jt x 5% + 1.848.000 x 15%
check('december settles the year', $r['months'][12]['tax'], 3277200 - 11 * 261350);
check('total tax equals annual tax', $r['total_tax'], 3277200);

// no BPJS at all: gross = pay, no employee deductions. Rp5.000.000 is under the TER threshold (0% Jan-Nov) but the year is just over
// the PTKP (60.000.000 - 3.000.000 biaya jabatan - 54.000.000 = 3.000.000 x 5%), so December pays the 150.000 all at once.
$r = tax_simulate(['gaji_pokok' => 5000000, 'tunjangan' => 0, 'lain' => 0, 'ptkp' => 'TK/0', 'bpjs_kes' => 0, 'bpjs_tk' => 0, 'bpjs_jp' => 0], $s);
check('under TER threshold pays nothing monthly', [$r['bruto'], $r['rate'], $r['months'][5]['tax'], $r['months'][5]['net']], [5000000, 0, 0, 5000000]);
check('december catches up the year', [$r['annual']['pkp'], $r['annual']['tax'], $r['months'][12]['tax'], $r['total_tax']], [3000000, 150000, 150000, 150000]);
// Rp4.000.000: the year stays under the PTKP, no tax at all
$r = tax_simulate(['gaji_pokok' => 4000000, 'tunjangan' => 0, 'lain' => 0, 'ptkp' => 'TK/0', 'bpjs_kes' => 0, 'bpjs_tk' => 0, 'bpjs_jp' => 0], $s);
check('below PTKP no tax', [$r['annual']['pkp'] < 0, $r['annual']['tax'], $r['total_tax']], [true, 0, 0]);

// overpaid year: a one-off high month rate cannot happen here, but a married K/3 pays under category C
$r = tax_simulate(['gaji_pokok' => 20000000, 'tunjangan' => 0, 'lain' => 2000000, 'ptkp' => 'K/3', 'bpjs_kes' => 1, 'bpjs_tk' => 1, 'bpjs_jp' => 1], $s);
check('k/3 is category C', $r['cat'], 'C');
check('other earnings are part of gross but not the BPJS base', [$r['earn'], $r['bpjs']['jht_emp']], [22000000, 400000]);

check('money parsing', [tax_money('10.000.000'), tax_money('Rp 10,000,000'), tax_money('10000000'), tax_money(''), tax_money('abc')], [10000000, 10000000, 10000000, 0, 0]);
echo "taxsim_test: $ok checks passed\n";
