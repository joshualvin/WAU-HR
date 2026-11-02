<?php
// Payroll: salary components from attendance, BPJS and PPh 21 (TER, PP 58/2023).
//
// Rates and caps live in payroll_defaults() and the TER tables below so HR can check them against the
// regulations in one place. Company-specific values (JKK risk rate, JP wage cap, late deduction) can be changed
// on the Pengaturan gaji page and are stored in web_pay_setting.
// shortcut: no 20% surcharge for employees without NPWP/NIK and no annualisation for mid-year joiners/leavers;
// add them if the company needs them.

function ensure_payroll_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_pay_emp (
        pegawai_id INT NOT NULL PRIMARY KEY,
        gaji_pokok BIGINT NOT NULL DEFAULT 0,
        tunjangan BIGINT NOT NULL DEFAULT 0,
        uang_harian BIGINT NOT NULL DEFAULT 0,
        ptkp VARCHAR(5) NOT NULL DEFAULT 'TK/0',
        npwp VARCHAR(30) NOT NULL DEFAULT '',
        bpjs_kes TINYINT NOT NULL DEFAULT 1,
        bpjs_tk TINYINT NOT NULL DEFAULT 1,
        bpjs_jp TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_pay_setting (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // one saved payslip per employee per period; detail is the JSON snapshot shown on the payslip
    db()->exec("CREATE TABLE IF NOT EXISTS web_payslip (
        period_to DATE NOT NULL,
        pegawai_id INT NOT NULL,
        period_from DATE NOT NULL,
        bruto BIGINT NOT NULL,
        pph21 BIGINT NOT NULL,
        netto BIGINT NOT NULL,
        detail TEXT NOT NULL,
        published TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        created_by VARCHAR(50) NOT NULL DEFAULT '',
        PRIMARY KEY (period_to, pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Statutory rates (percent) and caps (rupiah). Check these when regulations change. */
function payroll_defaults()
{
    return [
        'kes_co' => 4, 'kes_emp' => 1, 'kes_cap' => 12000000,           // BPJS Kesehatan, Perpres 64/2020
        'jht_co' => 3.7, 'jht_emp' => 2,                                  // JHT
        'jp_co' => 2, 'jp_emp' => 1, 'jp_cap' => 10547400,                // JP; wage cap is updated every March
        'jkk' => 0.24, 'jkm' => 0.3,                                      // JKK (0.24-1.74 by risk group), JKM
        'ot_divisor' => 173,                                              // hourly wage = monthly wage / 173 (PP 35/2021)
        'late_per_minute' => 0,                                           // company rule, rupiah per late minute
        'absent_deduct' => 1,                                             // 1 = deduct pro-rata for absent days without leave
    ];
}

function payroll_settings()
{
    static $s = null;
    if ($s === null) {
        $s = payroll_defaults();
        foreach (q('SELECT k, v FROM web_pay_setting')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) if (isset($s[$k])) $s[$k] = (float)$v;
    }
    return $s;
}

function ptkp_options()
{
    return ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];
}

/** Annual PTKP (UU PPh, PMK 101/2016): 54 jt + 4.5 jt married + 4.5 jt per dependant (max 3). */
function ptkp_amount($ptkp)
{
    list($s, $n) = explode('/', $ptkp);
    return 54000000 + ($s === 'K' ? 4500000 : 0) + 4500000 * min(3, (int)$n);
}

/** TER category for the monthly rate (PP 58/2023): A = TK/0, TK/1, K/0; B = TK/2, TK/3, K/1, K/2; C = K/3. */
function ter_category($ptkp)
{
    if (in_array($ptkp, ['TK/0', 'TK/1', 'K/0'], true)) return 'A';
    if ($ptkp === 'K/3') return 'C';
    return 'B';
}

/** Monthly TER rate (%) for a gross monthly income: [upper limit, rate] per bracket, from PP 58/2023 Lampiran. */
function ter_rate($cat, $bruto)
{
    $t = [
        'A' => [[5400000, 0], [5650000, .25], [5950000, .5], [6300000, .75], [6750000, 1], [7500000, 1.25], [8550000, 1.5], [9650000, 1.75],
                [10050000, 2], [10350000, 2.25], [10700000, 2.5], [11050000, 3], [11600000, 3.5], [12500000, 4], [13750000, 5], [15100000, 6],
                [16950000, 7], [19750000, 8], [24150000, 9], [26450000, 10], [28000000, 11], [30050000, 12], [32400000, 13], [35400000, 14],
                [39100000, 15], [43850000, 16], [47800000, 17], [51400000, 18], [56300000, 19], [62200000, 20], [68600000, 21], [77500000, 22],
                [89000000, 23], [103000000, 24], [125000000, 25], [157000000, 26], [206000000, 27], [337000000, 28], [454000000, 29],
                [550000000, 30], [695000000, 31], [910000000, 32], [1400000000, 33], [PHP_INT_MAX, 34]],
        'B' => [[6200000, 0], [6500000, .25], [6850000, .5], [7300000, .75], [9200000, 1], [10750000, 1.5], [11250000, 2], [11600000, 2.5],
                [12600000, 3], [13600000, 4], [14950000, 5], [16400000, 6], [18450000, 7], [21850000, 8], [26000000, 9], [27700000, 10],
                [29350000, 11], [31450000, 12], [33950000, 13], [37100000, 14], [41100000, 15], [45800000, 16], [49500000, 17], [53800000, 18],
                [58500000, 19], [64000000, 20], [71000000, 21], [80000000, 22], [93000000, 23], [109000000, 24], [129000000, 25], [163000000, 26],
                [211000000, 27], [374000000, 28], [459000000, 29], [555000000, 30], [704000000, 31], [957000000, 32], [1405000000, 33], [PHP_INT_MAX, 34]],
        'C' => [[6600000, 0], [6950000, .25], [7350000, .5], [7800000, .75], [8850000, 1], [9800000, 1.25], [10950000, 1.5], [11200000, 1.75],
                [12050000, 2], [12950000, 3], [14150000, 4], [15550000, 5], [17050000, 6], [19500000, 7], [22700000, 8], [26600000, 9],
                [28100000, 10], [30100000, 11], [32600000, 12], [35400000, 13], [38900000, 14], [43000000, 15], [47400000, 16], [51200000, 17],
                [55800000, 18], [60400000, 19], [66700000, 20], [74500000, 21], [83200000, 22], [95600000, 23], [110000000, 24], [134000000, 25],
                [169000000, 26], [221000000, 27], [390000000, 28], [463000000, 29], [561000000, 30], [709000000, 31], [965000000, 32], [1419000000, 33], [PHP_INT_MAX, 34]],
    ];
    foreach ($t[$cat] as $b) if ($bruto <= $b[0]) return $b[1];
    return 34;
}

/** Progressive Pasal 17 tax (UU HPP) on annual taxable income, rounded down to thousands first. */
function pasal17($pkp)
{
    $pkp = max(0, floor($pkp / 1000) * 1000);
    $tax = 0;
    $prev = 0;
    foreach ([[60000000, 5], [250000000, 15], [500000000, 25], [5000000000, 30], [PHP_INT_MAX, 35]] as $b) {
        if ($pkp <= $prev) break;
        $tax += (min($pkp, $b[0]) - $prev) * $b[1] / 100;
        $prev = $b[0];
    }
    return floor($tax);
}

/** BPJS amounts for one employee: company and employee parts, rounded to rupiah. */
function bpjs($pay, $s)
{
    $wage = $pay['gaji_pokok'] + $pay['tunjangan'];
    $r = function ($base, $pct) { return (int)round($base * $pct / 100); };
    $kesBase = min($wage, $s['kes_cap']);
    $jpBase = min($wage, $s['jp_cap']);
    return [
        'kes_co'  => $pay['bpjs_kes'] ? $r($kesBase, $s['kes_co']) : 0,
        'kes_emp' => $pay['bpjs_kes'] ? $r($kesBase, $s['kes_emp']) : 0,
        'jht_co'  => $pay['bpjs_tk'] ? $r($wage, $s['jht_co']) : 0,
        'jht_emp' => $pay['bpjs_tk'] ? $r($wage, $s['jht_emp']) : 0,
        'jkk'     => $pay['bpjs_tk'] ? $r($wage, $s['jkk']) : 0,
        'jkm'     => $pay['bpjs_tk'] ? $r($wage, $s['jkm']) : 0,
        'jp_co'   => $pay['bpjs_jp'] ? $r($jpBase, $s['jp_co']) : 0,
        'jp_emp'  => $pay['bpjs_jp'] ? $r($jpBase, $s['jp_emp']) : 0,
    ];
}

/**
 * Overtime pay for one day (Kepmenakertrans 102/2004, PP 35/2021), minutes -> rupiah.
 * Work day: first hour 1.5x, then 2x. Rest day / holiday (6-day week): first 7 hours 2x, 8th hour 3x, then 4x.
 */
function overtime_pay($minutes, $hourly, $restDay)
{
    $h = $minutes / 60;
    if (!$restDay) $mult = min($h, 1) * 1.5 + max(0, $h - 1) * 2;
    else $mult = min($h, 7) * 2 + max(0, min($h, 8) - 7) * 3 + max(0, $h - 8) * 4;
    return (int)round($mult * $hourly);
}

/**
 * Monthly PPh 21 withheld. TER on the month's gross for January-November; in the last period of the year
 * (period ending in December) the annual Pasal 17 tax minus what was already withheld in earlier saved periods.
 * Returns [tax, explanation].
 */
function pph21($pay, $bruto, $iuranEmp, $periodTo, $pegawaiId)
{
    if ((int)date('n', strtotime($periodTo)) !== 12) {
        $cat = ter_category($pay['ptkp']);
        $rate = ter_rate($cat, $bruto);
        return [(int)floor($bruto * $rate / 100), "TER $cat {$rate}% × " . rupiah($bruto)];
    }
    $year = substr($periodTo, 0, 4);
    $prev = ['b' => 0, 't' => 0];
    $prevIuran = 0;
    foreach (q('SELECT bruto, pph21, detail FROM web_payslip WHERE pegawai_id = ? AND period_to BETWEEN ? AND ?', [$pegawaiId, "$year-01-01", "$year-11-30"]) as $row) {
        $l = json_decode($row['detail'], true);
        $prev['b'] += $row['bruto'];
        $prev['t'] += $row['pph21'];
        $prevIuran += isset($l['iuran_pensiun']) ? $l['iuran_pensiun'] : 0;
    }
    $annualBruto = $prev['b'] + $bruto;
    $biayaJabatan = min($annualBruto * 0.05, 6000000);
    $neto = $annualBruto - $biayaJabatan - $prevIuran - $iuranEmp;
    $pkp = $neto - ptkp_amount($pay['ptkp']);
    $annual = pasal17($pkp);
    return [(int)max(0, $annual - $prev['t']), 'Desember: PPh setahun ' . rupiah($annual) . ' − dipotong Jan–Nov ' . rupiah($prev['t'])];
}

function rupiah($n)
{
    return 'Rp' . number_format((float)$n, 0, ',', '.');
}

/** Pay data per employee (with zero defaults for employees not set up yet). */
function pay_data()
{
    $out = [];
    foreach (q('SELECT * FROM web_pay_emp') as $r) $out[$r['pegawai_id']] = $r;
    return $out;
}

/**
 * Calculate one payroll period for every active employee who has a salary set up.
 * Returns pegawai_id => payslip array (all amounts in rupiah, integers).
 */
function calculate_payroll($from, $to)
{
    $s = payroll_settings();
    $pays = pay_data();
    $days = [];
    foreach (daily_attendance($from, $to) as $r) $days[$r['pegawai_id']][] = $r;
    $out = [];
    foreach (employees(true) as $e) {
        $id = $e['pegawai_id'];
        if (empty($pays[$id]) || $pays[$id]['gaji_pokok'] + $pays[$id]['tunjangan'] <= 0) continue;
        $p = $pays[$id];
        $wage = $p['gaji_pokok'] + $p['tunjangan'];
        $hourly = $wage / $s['ot_divisor'];
        $c = ['workdays' => 0, 'present' => 0, 'absent' => 0, 'late_min' => 0, 'ot_min' => 0, 'ot_rest_min' => 0, 'ot_pay' => 0];
        foreach (isset($days[$id]) ? $days[$id] : [] as $r) {
            if (!$r['off']) $c['workdays']++;
            if ($r['first'] || $r['last']) $c['present']++;
            if ($r['status'] === 'absent') $c['absent']++;
            $c['late_min'] += $r['late'];
            if ($r['off'] && $r['minutes']) {                  // worked on a day off / holiday
                $c['ot_rest_min'] += $r['minutes'];
                $c['ot_pay'] += overtime_pay($r['minutes'], $hourly, true);
            } elseif ($r['ot']) {
                $c['ot_min'] += $r['ot'];
                $c['ot_pay'] += overtime_pay($r['ot'], $hourly, false);
            }
        }
        $earn = [
            'Gaji pokok' => (int)$p['gaji_pokok'],
            'Tunjangan tetap' => (int)$p['tunjangan'],
            'Uang makan / transport (' . $c['present'] . ' hari)' => (int)($p['uang_harian'] * $c['present']),
            'Lembur' => $c['ot_pay'],
        ];
        $deduct = [];
        if ($s['absent_deduct'] && $c['absent'] && $c['workdays']) $deduct['Potongan tidak hadir (' . $c['absent'] . ' hari)'] = (int)round($wage / $c['workdays'] * $c['absent']);
        if ($s['late_per_minute'] && $c['late_min']) $deduct['Potongan terlambat (' . $c['late_min'] . ' menit)'] = (int)round($s['late_per_minute'] * $c['late_min']);
        $b = bpjs($p, $s);
        $earnTotal = array_sum($earn) - array_sum($deduct);
        // gross for PPh 21 includes the company-paid JKK, JKM and BPJS Kesehatan (PP 58/2023)
        $bruto = $earnTotal + $b['jkk'] + $b['jkm'] + $b['kes_co'];
        $iuranEmp = $b['jht_emp'] + $b['jp_emp'];
        list($tax, $taxNote) = pph21($p, $bruto, $iuranEmp, $to, $id);
        $empDeduct = ['BPJS Kesehatan (1%)' => $b['kes_emp'], 'BPJS JHT (2%)' => $b['jht_emp'], 'BPJS JP (1%)' => $b['jp_emp'], 'PPh 21' => $tax];
        $out[$id] = [
            'pegawai_id' => $id, 'pin' => $e['pegawai_pin'], 'name' => $e['pegawai_nama'], 'ptkp' => $p['ptkp'], 'npwp' => $p['npwp'],
            'period_from' => $from, 'period_to' => $to, 'counts' => $c,
            'earnings' => array_filter($earn), 'deductions' => array_filter($deduct + $empDeduct),
            'company' => ['BPJS Kesehatan (4%)' => $b['kes_co'], 'BPJS JHT (3,7%)' => $b['jht_co'], 'BPJS JP (2%)' => $b['jp_co'], 'BPJS JKK' => $b['jkk'], 'BPJS JKM' => $b['jkm']],
            'bruto' => $bruto, 'iuran_pensiun' => $iuranEmp, 'pph21' => $tax, 'pph21_note' => $taxNote,
            'netto' => $earnTotal - $b['kes_emp'] - $b['jht_emp'] - $b['jp_emp'] - $tax,
        ];
    }
    return $out;
}

function save_payroll($rows, $publish)
{
    foreach ($rows as $r) {
        q('REPLACE INTO web_payslip (period_to, pegawai_id, period_from, bruto, pph21, netto, detail, published, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$r['period_to'], $r['pegawai_id'], $r['period_from'], $r['bruto'], $r['pph21'], $r['netto'], json_encode($r), $publish ? 1 : 0, date('Y-m-d H:i:s'), actor()]);
    }
}
