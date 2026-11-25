<?php
// Warehouse (gudang) open / close time: the head of the warehouse (config 'gudang_opener_pin', default 9001) clocks in at the Margo
// machines when the warehouse opens, so his first scan of the day is the opening time and a late scan is the closing time.

function gudang_pin()
{
    global $CONFIG;
    return isset($CONFIG['gudang_opener_pin']) ? (string)$CONFIG['gudang_opener_pin'] : '9001';
}

/** Scans of that person on the Margo machines per day: ['margo' => [sn => name], 'days' => [Y-m-d => ['t' => [HH:MM...], 'm' => [machine name => 1]]]]. */
function gudang_scans($from, $to)
{
    $margo = q("SELECT sn, device_name FROM device WHERE device_name LIKE '%margo%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $days = [];
    if ($margo) {
        $in = implode(',', array_fill(0, count($margo), '?'));
        foreach (q("SELECT scan_date, sn FROM att_log WHERE pin = ? AND sn IN ($in) AND scan_date >= ? AND scan_date < ? + INTERVAL 1 DAY ORDER BY scan_date", array_merge([gudang_pin()], array_keys($margo), [$from, $to])) as $r) {
            $d = substr($r['scan_date'], 0, 10);
            $days[$d]['t'][] = substr($r['scan_date'], 11, 5);
            $days[$d]['m'][$margo[$r['sn']]] = 1;
        }
    }
    return ['margo' => $margo, 'days' => $days];
}

/**
 * Warehouse state for one day from its scan times and the current time: 'state' is open (first scan = opening time),
 * closed (a scan at least 2 hours after the first = closing time), not_yet (no scan; before $expect), late (no scan after $expect) or none.
 */
function gudang_state($times, $now, $expect = '08:00')
{
    if (!$times) return ['state' => $now > $expect ? 'late' : 'not_yet', 'open' => null, 'close' => null];
    sort($times);
    $open = $times[0];
    $last = end($times);
    $mins = function ($t) { return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); };
    $closed = $mins($last) - $mins($open) >= 120;
    return ['state' => $closed ? 'closed' : 'open', 'open' => $open, 'close' => $closed ? $last : null];
}

/**
 * Late minutes of a check-in. Warehouse staff cannot start before the warehouse is opened, so when it opened ($openM) after the
 * scheduled start their lateness is counted from the opening time instead. Minutes from midnight; null $openM = normal rule.
 */
function late_minutes($inM, $startM, $tolerance, $openM = null, $grace = 5)
{
    if ($openM !== null && $openM > $startM) {   // the machine is inside the warehouse: staff queue behind the opener, so a few minutes after opening is on time
        return $inM - $openM > max((int)$tolerance, (int)$grace) ? $inM - $openM : 0;
    }
    return $inM - $startM > (int)$tolerance ? $inM - $startM : 0;
}

/** [Y-m-d => opening minute] for the days in the range, and the ids of warehouse staff (department or office named gudang, not the opener). */
function gudang_open_minutes($from, $to)
{
    $open = [];
    foreach (gudang_scans($from, $to)['days'] as $d => $v) { $t = min($v['t']); $open[$d] = (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); }
    $staff = q("SELECT p.pegawai_id FROM pegawai p LEFT JOIN pembagian2 d ON d.pembagian2_id = p.pembagian2_id LEFT JOIN pembagian3 k ON k.pembagian3_id = p.pembagian3_id
                WHERE (d.pembagian2_nama LIKE '%gudang%' OR k.pembagian3_nama LIKE '%gudang%') AND p.pegawai_pin <> ?", [gudang_pin()])->fetchAll(PDO::FETCH_COLUMN);
    return [$open, array_fill_keys(array_map('intval', $staff), true)];
}
