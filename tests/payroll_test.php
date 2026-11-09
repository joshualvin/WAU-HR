<?php
// Self-check for the payroll rules: php tests/payroll_test.php  (no database needed)
require __DIR__ . '/../inc/payroll.php';
require __DIR__ . '/../inc/govcal.php';
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
// Sales bonus: highest tier reached, rate % of sales (tiers 80% -> 1%, 100% -> 2%, 120% -> 3%)
$tiers = [['min_pct' => 80, 'rate' => 1, 'fixed' => 0], ['min_pct' => 100, 'rate' => 2, 'fixed' => 0], ['min_pct' => 120, 'rate' => 3, 'fixed' => 500000]];
check('bonus 85%', sales_bonus(100000000, 85000000, $tiers), [850000, 85.0]);
check('bonus 120% + fixed', sales_bonus(100000000, 120000000, $tiers), [4100000, 120.0]);
check('bonus below tiers', sales_bonus(100000000, 70000000, $tiers)[0], 0);
check('bonus no target', sales_bonus(0, 5000000, $tiers), [0, 0]);
// Kasbon: the last installment is what is left
check('loan first', loan_installment(1000000, 300000, 0), 300000);
check('loan last', loan_installment(1000000, 300000, 900000), 100000);
check('loan paid', loan_installment(1000000, 300000, 1000000), 0);
// THR (Permenaker 6/2016): full months of service to the holiday
check('months 11', service_months('2025-03-15', '2026-03-14'), 11);
check('months 12', service_months('2025-03-15', '2026-03-15'), 12);
check('months none', service_months(null, '2026-03-15'), 0);
check('thr pro rata', thr_amount(6000000, 6), 3000000);
check('thr full', thr_amount(6000000, 30), 6000000);
check('thr under 1 month', thr_amount(6000000, 0), 0);
// THR tax: TER A on 8 jt = 1.5% (120,000), on 8 + 8 jt = 7% (1,120,000); the THR slip withholds the difference and
// the month's payroll then withholds the rest, so the month totals TER on salary + THR
$thrTax = ter_tax('TK/0', 16000000) - ter_tax('TK/0', 8000000);
check('thr tax', $thrTax, 1000000);
check('salary tax with thr', pph21(['ptkp' => 'TK/0'], 8000000, 0, '2027-03-20', 1, ['month' => [8000000, $thrTax], 'year' => [8000000, $thrTax]])[0], 120000);
check('salary tax without thr', pph21(['ptkp' => 'TK/0'], 8000000, 0, '2027-03-20', 1, ['month' => [0, 0], 'year' => [0, 0]])[0], 120000);
// THR pay date: H-7, back to a working day when that is a Sunday or a holiday
check('pay H-7', thr_pay_date('2027-03-10'), '2027-03-03');                       // Wednesday
check('pay sunday', thr_pay_date('2026-03-22'), '2026-03-14');                    // H-7 = Sunday 15 March -> Saturday
check('pay holiday', thr_pay_date('2027-03-10', ['2027-03-03', '2027-03-02']), '2027-03-01');
check('skb 2027', lebaran_official()[2027], ['2027-03-10', 1448, 'skb']);
// Government calendar: day counts as published, every date valid, weekday checks, and the Idul Fitri date agrees with THR
foreach ([2026 => [17, 8], 2027 => [18, 8]] as $y => $want) {
    $g = gov_holidays($y);
    check("libur $y", [count(array_filter($g, function ($x) { return $x[2] === 1; })), count(array_filter($g, function ($x) { return $x[2] === 2; }))], $want);
    check("dates $y unique", count(array_unique(array_column($g, 0))), count($g));
    $fitri = array_values(array_filter($g, function ($x) { return $x[2] === 1 && strpos($x[1], 'Idul Fitri') === 0; }));
    check("fitri $y", $fitri[0][0], lebaran_official()[$y][0]);
}
check('2027-03-10 is Wednesday', date('w', strtotime('2027-03-10')), '3');
check('2026-12-25 is Friday', date('w', strtotime('2026-12-25')), '5');
// THR tax with no payslip: base = wage 5,288,796 + JKK 12,693 + JKM 15,866 + Kes 211,552 = 5,528,907 (TER A 0.25% = 13,822);
// with THR 10,817,703 (3% = 324,531): THR tax 310,709
check('thr tax from pay data', ter_tax('TK/0', 5528907 + 5288796) - ter_tax('TK/0', 5528907), 310709);
// Daily workers (PMK 168/2023): TER harian by average daily gross
check('daily 300rb/day', pph21_daily(6000000, 20)[0], 0);
check('daily 450rb exactly', pph21_daily(4500000, 10)[0], 0);
check('daily 600rb/day 0.5%', pph21_daily(6000000, 10)[0], 30000);
check('daily above 2.5jt: Pasal 17 on 50%', pph21_daily(6000000, 2)[0], 150000);
// THR wage of a daily worker: average of the last 12 months, else the fallback
check('thr daily avg', thr_daily_wage([3000000, 4000000, 5000000], 9), 4000000);
check('thr daily last 12', thr_daily_wage(array_merge([100, 100], array_fill(0, 12, 3000000)), 9), 3000000);
check('thr daily fallback', thr_daily_wage([], 3750000), 3750000);
// Journal of a period always balances: earnings, absence deduction, reimbursement, refund, BPJS, PPh, kasbon, other
$slip = ['earnings' => ['Gaji pokok' => 7000000, 'Lembur' => 500000, 'Reimbursement (1 klaim)' => 200000, 'Pengembalian kelebihan PPh 21' => 50000],
         'deductions' => ['Potongan tidak hadir (1 hari)' => 300000, 'BPJS JHT (2%)' => 140000, 'PPh 21' => 0, 'Koperasi' => 100000, 'Cicilan kasbon #1 (sisa Rp0)' => 250000],
         'company' => ['BPJS JHT (3,7%)' => 259000, 'BPJS JKK' => 16800], 'netto' => 7750000 - 790000];
$j = payroll_journal([$slip, $slip]);
check('journal balances', array_sum(array_column($j, 1)), array_sum(array_column($j, 2)));
check('salary expense less absence', $j[0], ['acc_salary', 2 * (7500000 - 300000), 0]);
check('bpjs lines', bpjs_lines($slip)['jht'], [259000, 140000]);
$ai = payroll_defaults();
check('incentive head office', attendance_incentive(0, 20, false, $ai), 200000);
check('incentive gudang', attendance_incentive(0, 20, true, $ai), 100000);
check('no incentive with an absent day', attendance_incentive(1, 19, false, $ai), 0);
check('no incentive without any day present', attendance_incentive(0, 0, false, $ai), 0);
require_once __DIR__ . '/../inc/dl.php';
check('DL: 15 weekdays + 2 Saturdays', dl_pay(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-10'], $ai), [125000 * 2 + 112500 * 2, 2, 2]);
echo "payroll_test: $ok checks passed\n";
