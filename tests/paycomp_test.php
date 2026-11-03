<?php
// Self-check for pro-rating and the final settlement (PP 35/2021, PP 68/2009): php tests/paycomp_test.php
require __DIR__ . '/../inc/paycomp.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
// UP: <1 year 1 month ... 8+ years 9 months; UPMK: 3-6 years 2 ... 21-24 years 8, 24+ 10
check('up 0y', up_months(0), 1); check('up 4y', up_months(4), 5); check('up 8y', up_months(8), 9); check('up 20y', up_months(20), 9);
check('upmk 2y', upmk_months(2), 0); check('upmk 3y', upmk_months(3), 2); check('upmk 6y', upmk_months(6), 3); check('upmk 9y', upmk_months(9), 4);
check('upmk 21y', upmk_months(21), 8); check('upmk 23y', upmk_months(23), 8); check('upmk 24y', upmk_months(24), 10);
// final tax on severance: 0% to 50 jt, 5% to 100 jt, 15% to 500 jt, 25% above
check('pph 50jt', pph_pesangon(50000000), 0); check('pph 80jt', pph_pesangon(80000000), 1500000); check('pph 200jt', pph_pesangon(200000000), 17500000);
check('pph 600jt', pph_pesangon(600000000), 87500000);   // 2.5 + 60 + 25 jt
// efficiency to prevent losses, 10 years 4 months, wage 5 jt: UP 9 x 1, UPMK 4 x 1 = 65 jt; 5 days leave / 21 x 5 jt
$s = calculate_settlement(['reason' => 'efisiensi', 'upah' => 5000000, 'start' => '2016-01-01', 'end' => '2026-05-01', 'leave_days' => 5, 'leave_divisor' => 21,
                          'uang_pisah' => 0, 'other' => 0, 'pkwt_months' => 0]);
check('years', $s['years'], 10); check('severance', $s['severance'], 65000000); check('leave', $s['lines']['Uang penggantian hak: sisa cuti 5 hari'], 1190476);
check('tax', $s['pph_final'], 750000); check('net', $s['net'], 65000000 + 1190476 - 750000);
// losses: half UP
$s = calculate_settlement(['reason' => 'efisiensi_r', 'upah' => 5000000, 'start' => '2016-01-01', 'end' => '2026-05-01', 'leave_days' => 0, 'leave_divisor' => 21, 'uang_pisah' => 0, 'other' => 0, 'pkwt_months' => 0]);
check('half UP', $s['severance'], 22500000 + 20000000);
// resign: only UPH and uang pisah; PKWT: months / 12 of a wage
$s = calculate_settlement(['reason' => 'resign', 'upah' => 5000000, 'start' => '2020-01-01', 'end' => '2026-01-01', 'leave_days' => 0, 'leave_divisor' => 21, 'uang_pisah' => 2000000, 'other' => 0, 'pkwt_months' => 0]);
check('resign', [$s['severance'], $s['gross']], [2000000, 2000000]);
$s = calculate_settlement(['reason' => 'pkwt', 'upah' => 6000000, 'start' => '2025-07-01', 'end' => '2026-06-30', 'leave_days' => 0, 'leave_divisor' => 21, 'uang_pisah' => 0, 'other' => 0, 'pkwt_months' => 6]);
check('pkwt 6 months', $s['gross'], 3000000);
// pro-rating counts working days employed
$days = [['date' => '2026-10-21', 'off' => false], ['date' => '2026-10-22', 'off' => false], ['date' => '2026-10-24', 'off' => true], ['date' => '2026-10-26', 'off' => false]];
check('joined', prorate_days($days, '2026-10-22', null), [2, 3]);
check('left', prorate_days($days, null, '2026-10-21'), [1, 3]);
check('whole', prorate_days($days, '2020-01-01', null), [3, 3]);
// joiner: Mon-Fri worker starting Wed 2026-10-07; period from Mon 2026-09-28; 1 Oct (Thu) a holiday -> Mon, Tue, Wed 30 Sep, Fri 2, Mon 5, Tue 6 = 6
$joined = [['date' => '2026-10-07', 'off' => false], ['date' => '2026-10-08', 'off' => false], ['date' => '2026-10-09', 'off' => false], ['date' => '2026-10-10', 'off' => true], ['date' => '2026-10-12', 'off' => false], ['date' => '2026-10-13', 'off' => false]];
check('days before start', workdays_before($joined, '2026-09-28', '2026-10-07', ['2026-10-01']), 6);
echo "paycomp_test: $ok checks passed\n";
