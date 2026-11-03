<?php
// Pay items outside the monthly salary: sales bonuses (per period, from target achievement), kasbon (employee loans
// repaid by payroll deduction) and THR (Tunjangan Hari Raya, Permenaker 6/2016) with its own slip.

function ensure_payextra_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_bonus_tier (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        min_pct DECIMAL(6,2) NOT NULL,
        rate DECIMAL(6,3) NOT NULL DEFAULT 0,
        fixed BIGINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!q('SELECT COUNT(*) FROM web_bonus_tier')->fetchColumn()) {
        foreach ([[80, 1, 0], [100, 2, 0], [120, 3, 0]] as $t) q('INSERT INTO web_bonus_tier (min_pct, rate, fixed) VALUES (?, ?, ?)', $t);
    }
    // one row per employee per payroll period (named by its last day)
    db()->exec("CREATE TABLE IF NOT EXISTS web_bonus (
        period_to DATE NOT NULL,
        pegawai_id INT NOT NULL,
        target BIGINT NOT NULL DEFAULT 0,
        sales BIGINT NOT NULL DEFAULT 0,
        sales_bonus BIGINT NOT NULL DEFAULT 0,
        other_bonus BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(200) NOT NULL DEFAULT '',
        sales_source VARCHAR(60) NOT NULL DEFAULT '',
        updated_by VARCHAR(50) NOT NULL DEFAULT '',
        PRIMARY KEY (period_to, pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!q("SHOW COLUMNS FROM web_bonus LIKE 'sales_source'")->fetch()) db()->exec("ALTER TABLE web_bonus ADD sales_source VARCHAR(60) NOT NULL DEFAULT ''");
    // salesman code in WAU ERP (mastersalesman) per employee, for taking actual sales from the ERP
    db()->exec("CREATE TABLE IF NOT EXISTS web_sales_code (
        pegawai_id INT NOT NULL PRIMARY KEY,
        code VARCHAR(10) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_loan (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        tgl DATE NOT NULL,
        jumlah BIGINT NOT NULL,
        cicilan BIGINT NOT NULL,
        keterangan VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        decided_by VARCHAR(50) NOT NULL DEFAULT '',
        KEY emp (pegawai_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // repayments: by payroll (period_to set) or paid in cash (period_to NULL)
    db()->exec("CREATE TABLE IF NOT EXISTS web_loan_pay (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        loan_id INT UNSIGNED NOT NULL,
        period_to DATE NULL,
        tgl DATE NOT NULL,
        jumlah BIGINT NOT NULL,
        note VARCHAR(100) NOT NULL DEFAULT '',
        KEY loan (loan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_thr (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        holiday DATE NOT NULL,
        pay_date DATE NOT NULL,
        published TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        created_by VARCHAR(50) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_thr_slip (
        thr_id INT UNSIGNED NOT NULL,
        pegawai_id INT NOT NULL,
        months INT NOT NULL,
        upah BIGINT NOT NULL,
        rule_amount BIGINT NOT NULL,
        amount BIGINT NOT NULL,
        base_bruto BIGINT NOT NULL,
        pph21 BIGINT NOT NULL,
        netto BIGINT NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (thr_id, pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

// ---------- sales bonus ----------

function bonus_tiers()
{
    return q('SELECT * FROM web_bonus_tier ORDER BY min_pct')->fetchAll();
}

/** Bonus for one period: the highest tier reached gives rate % of sales plus a fixed amount. Returns [bonus, achievement %]. */
function sales_bonus($target, $sales, $tiers)
{
    if ($target <= 0 || $sales <= 0) return [0, 0];
    $pct = $sales / $target * 100;
    $hit = null;
    foreach ($tiers as $t) if ($pct >= (float)$t['min_pct']) $hit = $t;
    return [$hit ? (int)round($sales * (float)$hit['rate'] / 100 + (int)$hit['fixed']) : 0, round($pct, 1)];
}

/** Bonus rows of one period: pegawai_id => row. */
function bonuses_for_period($to)
{
    $out = [];
    foreach (q('SELECT * FROM web_bonus WHERE period_to = ?', [$to]) as $r) $out[$r['pegawai_id']] = $r;
    return $out;
}

// ---------- kasbon ----------

const LOAN_PENDING = 'pending';
const LOAN_ACTIVE = 'active';
const LOAN_PAID = 'paid';
const LOAN_REJECTED = 'rejected';

function loan_badge($status)
{
    $m = [LOAN_PENDING => ['warn', 'Menunggu'], LOAN_ACTIVE => ['ok', 'Berjalan'], LOAN_PAID => ['grey', 'Lunas'], LOAN_REJECTED => ['bad', 'Ditolak']];
    $b = isset($m[$status]) ? $m[$status] : ['grey', $status];
    return '<span class="pill ' . $b[0] . '">' . $b[1] . '</span>';
}

/** Amount repaid on a loan, leaving out the payroll deduction of period $exceptPeriod (so a period can be recalculated). */
function loan_repaid($loanId, $exceptPeriod = null)
{
    return (int)q('SELECT COALESCE(SUM(jumlah), 0) FROM web_loan_pay WHERE loan_id = ? AND (period_to IS NULL OR period_to <> ?)', [$loanId, (string)$exceptPeriod])->fetchColumn();
}

/** Next installment: the agreed installment, or what is left when that is less. */
function loan_installment($jumlah, $cicilan, $repaid)
{
    return (int)max(0, min($cicilan, $jumlah - $repaid));
}

/** Installments to deduct in the period ending $to: pegawai_id => [[loan_id, amount, left after], ...]. Loans start with the first period ending on or after the loan date. */
function loan_deductions($to)
{
    $out = [];
    foreach (q("SELECT * FROM web_loan WHERE status IN ('active', 'paid') AND tgl <= ?", [$to]) as $l) {
        $repaid = loan_repaid($l['id'], $to);
        $amount = loan_installment($l['jumlah'], $l['cicilan'], $repaid);
        // a loan paid off before this period stays paid; one paid by this very period is recalculated
        if ($amount > 0) $out[$l['pegawai_id']][] = [(int)$l['id'], $amount, $l['jumlah'] - $repaid - $amount];
    }
    return $out;
}

/** Store the payroll deductions of a saved period and set each loan's status from what has been repaid. */
function record_loan_deductions($to, $rows)
{
    q('DELETE p FROM web_loan_pay p WHERE p.period_to = ?', [$to]);
    foreach ($rows as $r) {
        foreach ($r['loans'] as $l) q('INSERT INTO web_loan_pay (loan_id, period_to, tgl, jumlah, note) VALUES (?, ?, ?, ?, ?)', [$l[0], $to, $to, $l[1], 'Potong gaji']);
    }
    refresh_loan_status();
}

function refresh_loan_status()
{
    q("UPDATE web_loan l SET status = IF((SELECT COALESCE(SUM(jumlah), 0) FROM web_loan_pay p WHERE p.loan_id = l.id) >= l.jumlah, 'paid', 'active')
       WHERE status IN ('active', 'paid')");
}

// ---------- THR ----------

/** Full months of service from $start to $on (the holiday). */
function service_months($start, $on)
{
    if (!$start || $start > $on) return 0;
    $d = (new DateTime($start))->diff(new DateTime($on));
    return $d->y * 12 + $d->m;
}

/** THR by Permenaker 6/2016: 12+ months of service = one month's wage; 1-11 months = months / 12 of it; below 1 month none. */
function thr_amount($upah, $months)
{
    if ($months < 1) return 0;
    return $months >= 12 ? (int)$upah : (int)round($months / 12 * $upah);
}

/** PPh 21 by TER on a month's gross. */
function ter_tax($ptkp, $bruto)
{
    return (int)floor($bruto * ter_rate(ter_category($ptkp), $bruto) / 100);
}

/** THR already taxed for an employee: [gross, tax] in the tax month of $periodTo, and in its year up to that month. */
function thr_taxed($pegawaiId, $periodTo)
{
    $month = substr($periodTo, 0, 7);
    $r = ['month' => [0, 0], 'year' => [0, 0]];
    foreach (q('SELECT s.amount, s.pph21, t.pay_date FROM web_thr_slip s JOIN web_thr t ON t.id = s.thr_id
                WHERE s.pegawai_id = ? AND t.pay_date BETWEEN ? AND ?', [$pegawaiId, substr($periodTo, 0, 4) . '-01-01', date('Y-m-t', strtotime($periodTo))]) as $s) {
        $r['year'][0] += $s['amount']; $r['year'][1] += $s['pph21'];
        if (substr($s['pay_date'], 0, 7) === $month) { $r['month'][0] += $s['amount']; $r['month'][1] += $s['pph21']; }
    }
    return $r;
}

/**
 * Calculate THR for every active employee with pay data. PPh 21 on THR is the extra TER tax it causes in the month
 * it is paid: TER(salary + THR) − TER(salary), with the salary gross of that month's saved payslip (or the latest
 * one as an estimate). The month's payroll later taxes salary + THR together and subtracts this.
 */
function calculate_thr($holiday, $payDate, $overrides = [])
{
    $pays = pay_data();
    $out = [];
    foreach (q('SELECT pegawai_id, pegawai_pin, pegawai_nama, tgl_mulai_kerja, tgl_masuk_pertama FROM pegawai WHERE pegawai_status = 1 ORDER BY pegawai_nama') as $e) {
        $id = $e['pegawai_id'];
        if (empty($pays[$id]) || $pays[$id]['gaji_pokok'] + $pays[$id]['tunjangan'] <= 0) continue;
        $p = $pays[$id];
        $start = $e['tgl_mulai_kerja'] ?: $e['tgl_masuk_pertama'];
        $months = service_months($start, $holiday);
        $upah = (int)($p['gaji_pokok'] + $p['tunjangan']);
        $rule = thr_amount($upah, $months);
        $amount = isset($overrides[$id]) ? max(0, (int)$overrides[$id]) : $rule;
        $base = q('SELECT bruto, period_to FROM web_payslip WHERE pegawai_id = ? AND period_to <= ? ORDER BY period_to DESC LIMIT 1', [$id, date('Y-m-t', strtotime($payDate))])->fetch();
        $baseBruto = $base ? (int)$base['bruto'] : 0;
        $tax = $amount ? max(0, ter_tax($p['ptkp'], $baseBruto + $amount) - ter_tax($p['ptkp'], $baseBruto)) : 0;
        $note = !$start ? 'Tanggal mulai kerja kosong' : ($months < 1 ? 'Masa kerja < 1 bulan' : '');
        if ($amount) $note = ($note ? "$note; " : '') . 'PPh: TER ' . ter_category($p['ptkp']) . ' atas gaji ' . ($base ? (substr($base['period_to'], 0, 7) === substr($payDate, 0, 7) ? 'bulan ini ' : 'terakhir (perkiraan) ') . rupiah($baseBruto) : 'Rp0 (belum ada slip) ') . '+ THR';
        $out[$id] = ['pegawai_id' => $id, 'pin' => $e['pegawai_pin'], 'name' => $e['pegawai_nama'], 'start' => $start, 'months' => $months, 'upah' => $upah,
                     'rule_amount' => $rule, 'amount' => $amount, 'base_bruto' => $baseBruto, 'pph21' => $tax, 'netto' => $amount - $tax, 'note' => $note];
    }
    return $out;
}

function save_thr($thrId, $rows)
{
    q('DELETE FROM web_thr_slip WHERE thr_id = ?', [$thrId]);
    foreach ($rows as $r) {
        q('INSERT INTO web_thr_slip (thr_id, pegawai_id, months, upah, rule_amount, amount, base_bruto, pph21, netto, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$thrId, $r['pegawai_id'], $r['months'], $r['upah'], $r['rule_amount'], $r['amount'], $r['base_bruto'], $r['pph21'], $r['netto'], mb_substr($r['note'], 0, 255)]);
    }
}

// ---------- bukti potong (BPA1 / formulir 1721-A1) ----------

/**
 * Annual PPh 21 withholding slip of a permanent employee (BPA1, PER-2/PJ/2024), from the saved payslips and THR
 * slips of $year. Returns null when there is nothing for that year. Lines follow the form's numbering.
 */
function bpa1($pegawaiId, $year)
{
    $slips = q('SELECT * FROM web_payslip WHERE pegawai_id = ? AND period_to BETWEEN ? AND ? ORDER BY period_to', [$pegawaiId, "$year-01-01", "$year-12-31"])->fetchAll();
    $thrs = q('SELECT s.*, t.pay_date FROM web_thr_slip s JOIN web_thr t ON t.id = s.thr_id WHERE s.pegawai_id = ? AND t.pay_date BETWEEN ? AND ?', [$pegawaiId, "$year-01-01", "$year-12-31"])->fetchAll();
    if (!$slips && !$thrs) return null;
    $l = array_fill(1, 20, 0);
    $months = [];
    $ptkp = 'TK/0';
    $npwp = '';
    foreach ($slips as $s) {
        $d = json_decode($s['detail'], true);
        $months[] = (int)substr($s['period_to'], 5, 2);
        $ptkp = $d['ptkp'];
        $npwp = $d['npwp'];
        foreach ($d['earnings'] as $k => $v) {
            if (strpos($k, 'Reimbursement') === 0) continue;                 // not income
            if (strpos($k, 'Gaji pokok') === 0) $l[1] += $v;
            elseif (strpos($k, 'Bonus') === 0) $l[7] += $v;
            else $l[3] += $v;                                                // allowances, daily pay, overtime
        }
        foreach ($d['deductions'] as $k => $v) if (strpos($k, 'Potongan') === 0) $l[1] -= $v;   // absence / late deductions lower the salary
        foreach (['BPJS JKK', 'BPJS JKM', 'BPJS Kesehatan (4%)'] as $k) $l[5] += isset($d['company'][$k]) ? $d['company'][$k] : 0;
        $l[10] += isset($d['iuran_pensiun']) ? $d['iuran_pensiun'] : 0;
        $l[20] += $s['pph21'];
    }
    foreach ($thrs as $t) {
        $months[] = (int)substr($t['pay_date'], 5, 2);
        $l[7] += $t['amount'];
        $l[20] += $t['pph21'];
    }
    if (!$slips && ($p = q('SELECT ptkp, npwp FROM web_pay_emp WHERE pegawai_id = ?', [$pegawaiId])->fetch())) { $ptkp = $p['ptkp']; $npwp = $p['npwp']; }
    $l[8] = $l[1] + $l[2] + $l[3] + $l[4] + $l[5] + $l[6] + $l[7];
    $l[9] = (int)min(round($l[8] * 0.05), 6000000);   // biaya jabatan 5%, at most 500,000 a month = 6 jt a year
    $l[11] = $l[9] + $l[10];
    $l[12] = $l[8] - $l[11];
    $l[14] = $l[12] + $l[13];
    $l[15] = ptkp_amount($ptkp);
    $l[16] = (int)max(0, floor(($l[14] - $l[15]) / 1000) * 1000);
    $l[17] = (int)pasal17($l[16]);
    $l[19] = $l[17] - $l[18];
    $final = in_array(12, $months, true);
    return ['lines' => $l, 'from' => min($months), 'to' => max($months), 'ptkp' => $ptkp, 'npwp' => $npwp, 'final' => $final,
            // with December saved, the tax due and the tax withheld should match; a difference is shown on the form
            'diff' => $l[19] - $l[20]];
}
