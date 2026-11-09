<?php
// Dinas luar (DL): days marked as DL on Input Cepat earn Uang DL on the payslip (weekday rate, lower Saturday rate).

function ensure_dl_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_dl (
        pegawai_id INT NOT NULL, tgl DATE NOT NULL, created_by VARCHAR(50) NOT NULL DEFAULT '',
        PRIMARY KEY (pegawai_id, tgl)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function dl_mark($pegawaiId, $date)
{
    q('REPLACE INTO web_dl (pegawai_id, tgl, created_by) VALUES (?, ?, ?)', [(int)$pegawaiId, $date, actor()]);
}

/** [pegawai_id => [dates]] marked DL in the period. */
function dl_days($from, $to)
{
    $out = [];
    foreach (q('SELECT pegawai_id, tgl FROM web_dl WHERE tgl BETWEEN ? AND ? ORDER BY tgl', [$from, $to]) as $r) $out[(int)$r['pegawai_id']][] = $r['tgl'];
    return $out;
}

/** Uang DL for a list of dates: Saturdays at the Saturday rate, every other day at the weekday rate. Returns [amount, weekdays, saturdays]. */
function dl_pay($dates, $s)
{
    $sat = 0;
    foreach ($dates as $d) if (date('N', strtotime($d)) == 6) $sat++;
    $wk = count($dates) - $sat;
    return [(int)round($wk * $s['dl_weekday'] + $sat * $s['dl_saturday']), $wk, $sat];
}

/** Group sorted dates into runs of working days (a Sunday in between does not break a run): "Sen 28-09 s/d Sab 03-10, Sel 06-10". */
function dl_ranges($dates)
{
    $dn = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
    $f = function ($d) use ($dn) { return $dn[date('N', strtotime($d))] . ' ' . date('d-m', strtotime($d)); };
    $runs = [];
    foreach ($dates as $d) {
        $last = $runs ? end($runs)[1] : null;
        $gap = $last ? (strtotime($d) - strtotime($last)) / 86400 : 0;
        if ($last && ($gap == 1 || ($gap == 2 && date('N', strtotime($last)) == 6))) $runs[count($runs) - 1][1] = $d;
        else $runs[] = [$d, $d];
    }
    return implode(', ', array_map(function ($r) use ($f) { return $r[0] === $r[1] ? $f($r[0]) : $f($r[0]) . ' s/d ' . $f($r[1]); }, $runs));
}
