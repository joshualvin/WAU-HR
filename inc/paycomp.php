<?php
// Salary components and history, pro-rated pay and the final settlement on leaving.
//
// Components: extra earnings or deductions per employee, each with an effective-from date (web_pay_emp_comp), so a
// change applies from that date and older periods keep the old amount. "Tetap" components count as wage (upah) for
// BPJS, overtime, absence deductions, THR and severance. Basic pay, the fixed allowance and the daily pay keep their
// history in web_pay_history the same way.
//
// Final settlement (PP 35/2021): uang pesangon (UP), uang penghargaan masa kerja (UPMK) and uang penggantian hak
// (UPH, e.g. unused leave) by reason, or PKWT compensation when a contract ends. UP + UPMK + uang pisah are taxed with
// the final PPh 21 on severance (PP 68/2009).

function ensure_paycomp_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_pay_component (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'earning',
        fixed TINYINT NOT NULL DEFAULT 0,
        per VARCHAR(5) NOT NULL DEFAULT 'month',
        active TINYINT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_pay_emp_comp (
        pegawai_id INT NOT NULL,
        component_id INT UNSIGNED NOT NULL,
        effective_from DATE NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,
        created_by VARCHAR(50) NOT NULL DEFAULT '',
        PRIMARY KEY (pegawai_id, component_id, effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_pay_history (
        pegawai_id INT NOT NULL,
        effective_from DATE NOT NULL,
        gaji_pokok BIGINT NOT NULL,
        tunjangan BIGINT NOT NULL,
        uang_harian BIGINT NOT NULL,
        upah_harian BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(200) NOT NULL DEFAULT '',
        created_by VARCHAR(50) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (pegawai_id, effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!q("SHOW COLUMNS FROM web_pay_history LIKE 'upah_harian'")->fetch()) db()->exec('ALTER TABLE web_pay_history ADD upah_harian BIGINT NOT NULL DEFAULT 0');
    db()->exec("CREATE TABLE IF NOT EXISTS web_settlement (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        reason VARCHAR(20) NOT NULL,
        end_date DATE NOT NULL,
        detail TEXT NOT NULL,
        total BIGINT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'draft',
        created_by VARCHAR(50) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function component_kinds() { return ['earning' => 'Pendapatan', 'deduction' => 'Potongan']; }
function component_pers() { return ['month' => 'per bulan', 'day' => 'per hari hadir']; }

function pay_components($activeOnly = true)
{
    return q('SELECT * FROM web_pay_component' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id')->fetchAll(PDO::FETCH_UNIQUE);
}

/** Component amounts in force on $date: [pegawai_id => [component_id => amount]] (zero amounts left out). */
function components_as_of($date)
{
    $out = [];
    foreach (q('SELECT c.pegawai_id, c.component_id, c.amount FROM web_pay_emp_comp c
                JOIN (SELECT pegawai_id, component_id, MAX(effective_from) f FROM web_pay_emp_comp WHERE effective_from <= ? GROUP BY pegawai_id, component_id) l
                  ON l.pegawai_id = c.pegawai_id AND l.component_id = c.component_id AND l.f = c.effective_from', [$date]) as $r) {
        if ((int)$r['amount']) $out[$r['pegawai_id']][$r['component_id']] = (int)$r['amount'];
    }
    return $out;
}

/**
 * Pay data in force on $date: web_pay_emp (PTKP, NPWP, BPJS flags and today's amounts) with basic pay, allowance and
 * daily pay replaced by the latest web_pay_history row on or before $date. Employees whose history starts after
 * $date keep today's amounts (no earlier record exists).
 */
function pay_data_as_of($date)
{
    $out = pay_data();
    foreach (q('SELECT h.* FROM web_pay_history h JOIN (SELECT pegawai_id, MAX(effective_from) f FROM web_pay_history WHERE effective_from <= ? GROUP BY pegawai_id) l
                ON l.pegawai_id = h.pegawai_id AND l.f = h.effective_from', [$date]) as $h) {
        if (!isset($out[$h['pegawai_id']])) continue;
        foreach (['gaji_pokok', 'tunjangan', 'uang_harian', 'upah_harian'] as $k) $out[$h['pegawai_id']][$k] = $h[$k];
    }
    return $out;
}

/** Record a change of basic pay / allowance / daily pay from $from (one row per employee per date). */
function save_pay_history($pegawaiId, $from, $gaji, $tunj, $harian, $note, $upahHarian = 0)
{
    q('REPLACE INTO web_pay_history (pegawai_id, effective_from, gaji_pokok, tunjangan, uang_harian, upah_harian, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$pegawaiId, $from, $gaji, $tunj, $harian, $upahHarian, mb_substr($note, 0, 200), actor(), date('Y-m-d H:i:s')]);
}

/**
 * THR wage of a daily worker (Permenaker 6/2016 art. 3(3)): the average monthly wage over the last 12 months worked,
 * or over all months when less. $monthly = wages per saved payslip; none yet = $fallback (daily wage x days a month).
 */
function thr_daily_wage($monthly, $fallback)
{
    $monthly = array_slice(array_values(array_filter($monthly)), -12);
    return $monthly ? (int)round(array_sum($monthly) / count($monthly)) : (int)$fallback;
}

/**
 * Share of a period an employee was employed: [working days employed, working days in the period]. $days are the
 * period's daily_attendance rows; $start / $end the employment dates (null = before / after the period).
 */
function prorate_days($days, $start, $end)
{
    $all = $mine = 0;
    foreach ($days as $r) {
        if ($r['off']) continue;
        $all++;
        if ((!$start || $r['date'] >= $start) && (!$end || $r['date'] <= $end)) $mine++;
    }
    return [$mine, $all];
}

/**
 * Working days of the period before someone joined ($from up to the day before $start). Attendance has no rows for
 * those days, so they are counted on the weekdays the employee works in the period ($days, rows from $start on),
 * leaving out public holidays ($holidays = [Y-m-d, ...]).
 */
function workdays_before($days, $from, $start, $holidays)
{
    $weekdays = [];
    foreach ($days as $r) if (!$r['off']) $weekdays[date('w', strtotime($r['date']))] = true;
    $n = 0;
    for ($d = $from; $d < $start; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (isset($weekdays[date('w', strtotime($d))]) && !in_array($d, $holidays, true)) $n++;
    }
    return $n;
}

// ---------- final settlement (PP 35/2021) ----------

/** Reasons: key => [label, UP multiplier, UPMK multiplier, article]. Multipliers 0 = not due. */
function settlement_reasons()
{
    return [
        'resign'      => ['Mengundurkan diri', 0, 0, 'Pasal 50: uang penggantian hak + uang pisah (PK/PP/PKB)'],
        'pkwt'        => ['Kontrak (PKWT) berakhir', 0, 0, 'Pasal 15-16: uang kompensasi PKWT'],
        'efisiensi_r' => ['Efisiensi, perusahaan rugi', 0.5, 1, 'Pasal 43 ayat 1'],
        'efisiensi'   => ['Efisiensi untuk mencegah kerugian', 1, 1, 'Pasal 43 ayat 2'],
        'tutup_r'     => ['Perusahaan tutup karena rugi', 0.5, 1, 'Pasal 44 ayat 1'],
        'tutup'       => ['Perusahaan tutup bukan karena rugi', 1, 1, 'Pasal 44 ayat 2'],
        'merger'      => ['Penggabungan / peleburan / pengambilalihan', 1, 1, 'Pasal 41'],
        'pelanggaran' => ['Pelanggaran PK/PP/PKB setelah SP', 0.5, 1, 'Pasal 52 ayat 1'],
        'berat'       => ['Pelanggaran bersifat mendesak', 0, 0, 'Pasal 52 ayat 2: uang penggantian hak + uang pisah'],
        'mangkir'     => ['Mangkir 5 hari berturut-turut', 0, 0, 'Pasal 51: uang penggantian hak + uang pisah'],
        'sakit'       => ['Sakit berkepanjangan lebih dari 12 bulan', 2, 1, 'Pasal 55'],
        'pensiun'     => ['Pensiun', 1.75, 1, 'Pasal 56'],
        'meninggal'   => ['Meninggal dunia', 2, 1, 'Pasal 57'],
    ];
}

/** Uang pesangon in months of wage by full years of service (PP 35/2021 art. 40(2)). */
function up_months($years)
{
    return $years >= 8 ? 9 : $years + 1;
}

/** UPMK in months of wage by full years of service (art. 40(3)); none below 3 years. */
function upmk_months($years)
{
    if ($years < 3) return 0;
    if ($years >= 24) return 10;
    return min(8, 2 + intdiv($years - 3, 3));
}

/** Final PPh 21 on severance (PP 68/2009, cumulative): 0% to 50 jt, 5% to 100 jt, 15% to 500 jt, 25% above. */
function pph_pesangon($amount)
{
    $tax = 0;
    $prev = 0;
    foreach ([[50000000, 0], [100000000, 5], [500000000, 15], [PHP_INT_MAX, 25]] as $b) {
        if ($amount <= $prev) break;
        $tax += (min($amount, $b[0]) - $prev) * $b[1] / 100;
        $prev = $b[0];
    }
    return (int)floor($tax);
}

/**
 * Calculate a settlement. $in: reason, upah (monthly wage), start, end (Y-m-d), leave_days (unused annual leave),
 * leave_divisor (working days a month), uang_pisah, other (e.g. travel home), pkwt_months (months of the contract
 * for PKWT compensation). Returns the lines and totals.
 */
function calculate_settlement($in)
{
    $r = settlement_reasons()[$in['reason']];
    $d = (new DateTime($in['start']))->diff(new DateTime($in['end']));
    $years = $d->y;
    $months = $d->y * 12 + $d->m;
    $upah = (int)$in['upah'];
    $up = $r[1] ? (int)round(up_months($years) * $r[1] * $upah) : 0;
    $upmk = $r[2] ? (int)round(upmk_months($years) * $r[2] * $upah) : 0;
    $leave = (int)round(max(0, $in['leave_days']) * $upah / max(1, $in['leave_divisor']));
    $komp = $in['reason'] === 'pkwt' ? (int)round(min(12, max(0, $in['pkwt_months'])) / 12 * $upah) : 0;
    $pisah = in_array($in['reason'], ['resign', 'berat', 'mangkir'], true) ? (int)$in['uang_pisah'] : 0;
    $severance = $up + $upmk + $pisah;
    $tax = pph_pesangon($severance);
    $lines = array_filter([
        'Uang pesangon (' . up_months($years) . ' × ' . str_replace('.', ',', $r[1]) . ' bulan upah)' => $up,
        'Uang penghargaan masa kerja (' . upmk_months($years) . ' × ' . str_replace('.', ',', $r[2]) . ' bulan upah)' => $upmk,
        'Uang kompensasi PKWT (' . (int)$in['pkwt_months'] . '/12 bulan upah)' => $komp,
        'Uang penggantian hak: sisa cuti ' . (float)$in['leave_days'] . ' hari' => $leave,
        'Uang penggantian hak lain (ongkos pulang, dll.)' => (int)$in['other'],
        'Uang pisah' => $pisah,
    ]);
    $gross = array_sum($lines);
    return ['years' => $years, 'months' => $months, 'service' => "{$d->y} tahun {$d->m} bulan", 'upah' => $upah, 'lines' => $lines,
            'severance' => $severance, 'pph_final' => $tax, 'regular' => $gross - $severance, 'gross' => $gross, 'net' => $gross - $tax,
            'reason_label' => $r[0], 'article' => $r[3]];
}
