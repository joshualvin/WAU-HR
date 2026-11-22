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
