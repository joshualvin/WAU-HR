<?php
// Tax simulation: take-home pay and PPh 21 for a hypothetical salary, with the same rules as the payroll run
// (inc/payroll.php): gross includes the company-paid JKK, JKM and BPJS Kesehatan; TER January-November, annual Pasal 17 in December.

/**
 * Month-by-month simulation of one salary kept the same all year. $in: gaji_pokok, tunjangan (fixed, BPJS base), lain (other
 * monthly earnings), ptkp ('TK/0' ...), bpjs_kes, bpjs_tk, bpjs_jp (0/1). $s: payroll settings (percentages and caps).
 * Returns ['bruto', 'iuran', 'bpjs' (the bpjs() parts), 'earn', 'rate', 'cat', 'annual' => [bruto, biaya_jabatan, iuran, neto, ptkp, pkp, tax],
 * 'months' => [1..12 => ['tax', 'net']], 'total_tax'].
 */
function tax_simulate($in, $s)
{
    $pay = ['gaji_pokok' => (int)$in['gaji_pokok'], 'tunjangan' => (int)$in['tunjangan'], 'bpjs_kes' => !empty($in['bpjs_kes']), 'bpjs_tk' => !empty($in['bpjs_tk']), 'bpjs_jp' => !empty($in['bpjs_jp'])];
    $b = bpjs($pay, $s);
    $earn = $pay['gaji_pokok'] + $pay['tunjangan'] + (int)$in['lain'];
    $bruto = $earn + $b['jkk'] + $b['jkm'] + $b['kes_co'];
    $iuran = $b['jht_emp'] + $b['jp_emp'];
    $cat = ter_category($in['ptkp']);
    $rate = ter_rate($cat, $bruto);
    $terTax = (int)floor($bruto * $rate / 100);
    $annualBruto = $bruto * 12;
    $biaya = min($annualBruto * 0.05, 6000000);
    $neto = $annualBruto - $biaya - $iuran * 12;
    $pkp = $neto - ptkp_amount($in['ptkp']);
    $annualTax = (int)pasal17($pkp);
    $months = [];
    $total = 0;
    for ($m = 1; $m <= 12; $m++) {
        $tax = $m < 12 ? $terTax : $annualTax - 11 * $terTax;   // December settles the year; negative = overpaid, refunded
        $months[$m] = ['tax' => $tax, 'net' => $earn - $b['kes_emp'] - $b['jht_emp'] - $b['jp_emp'] - $tax];
        $total += $tax;
    }
    return ['bruto' => $bruto, 'iuran' => $iuran, 'bpjs' => $b, 'earn' => $earn, 'rate' => $rate, 'cat' => $cat,
            'annual' => ['bruto' => $annualBruto, 'biaya_jabatan' => (int)$biaya, 'iuran' => $iuran * 12, 'neto' => (int)$neto, 'ptkp' => ptkp_amount($in['ptkp']), 'pkp' => (int)$pkp, 'tax' => $annualTax],
            'months' => $months, 'total_tax' => $total];
}

/** Whole rupiah from what a person types: "10.000.000", "Rp 10,000,000" or "10000000" all give 10000000. */
function tax_money($v)
{
    return (int)preg_replace('/\D+/', '', (string)$v);
}
