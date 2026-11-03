<?php
// Self-check for the payroll allocation: php tests/payalloc_test.php  (no database)
require __DIR__ . '/../inc/payalloc.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

$slip = function ($earn, $ded, $co, $pph, $net) { return ['earnings' => $earn, 'deductions' => $ded, 'company' => $co, 'pph21' => $pph, 'netto' => $net]; };
// 10jt salary, 500k lembur, 200k absence deduction, company BPJS 400k+24k+30k+370k+200k, a 150k reimbursement and a 75k tax refund
$a = alloc_slip($slip(['Gaji pokok' => 8000000, 'Tunjangan tetap' => 2000000, 'Lembur' => 500000, 'Reimbursement (1 klaim)' => 150000, 'Pengembalian kelebihan PPh 21' => 75000],
                      ['Potongan tidak hadir (1 hari)' => 200000, 'BPJS Kesehatan (1%)' => 100000, 'PPh 21' => 300000], ['kes' => 400000, 'jht' => 370000, 'jkk' => 24000, 'jkm' => 30000, 'jp' => 200000], 300000, 9000000));
check('salary is earnings minus absence, without reimbursement and refund', $a['salary'], 10300000);
check('company bpjs', $a['bpjs'], 1024000);
check('reimbursement is a company cost, the tax refund is not', [$a['reimb'], $a['total']], [150000, 10300000 + 1024000 + 150000]);
check('pph and net pass through', [$a['pph'], $a['net']], [300000, 9000000]);

$plain = function ($salary, $pph, $net) { return ['earnings' => ['Gaji pokok' => $salary], 'deductions' => [], 'company' => ['kes' => 0], 'pph21' => $pph, 'netto' => $net]; };
$g = alloc_group([
    ['group' => 'Gudang', 'detail' => $plain(5000000, 0, 5000000)],
    ['group' => 'IT', 'detail' => $plain(9000000, 100000, 8900000)],
    ['group' => 'Gudang', 'detail' => $plain(4000000, 0, 4000000)],
    ['group' => '', 'detail' => $plain(1000000, 0, 1000000)],
]);
check('ordered by total cost, biggest first', array_keys($g), ['Gudang', 'IT', 'Belum diisi']);   // Gudang 9jt and IT 9jt tie: by name
check('headcount and sums per group', [$g['Gudang']['n'], $g['Gudang']['salary'], $g['Gudang']['total']], [2, 9000000, 9000000]);
check('empty group name becomes Belum diisi', $g['Belum diisi']['n'], 1);
check('grand total', array_sum(array_column($g, 'total')), 19000000);
echo "payalloc_test: $ok checks passed\n";
