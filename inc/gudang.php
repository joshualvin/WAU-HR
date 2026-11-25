<?php
// Warehouse (gudang) open / close time: the head of the warehouse clocks in at the Margo machines (inside the warehouse) when she opens it,
// so her first scan of the day there is the opening time and a late scan the closing time. When she does not come, one of the backups
// opens it: config 'gudang_openers' = PINs, the head first (default Heni 9001, then Andhy 9008 and Susi 9005).

/** PINs that can open the warehouse, the head first. */
function gudang_openers()
{
    global $CONFIG;
    if (!empty($CONFIG['gudang_openers'])) return array_map('strval', (array)$CONFIG['gudang_openers']);
    return isset($CONFIG['gudang_opener_pin']) ? [(string)$CONFIG['gudang_opener_pin']] : ['9001', '9008', '9005'];
}

function gudang_pin()
{
    return gudang_openers()[0];
}

/** Who opened on a day: the head when she scanned at Margo, otherwise the backup who scanned there first. $byPin = [pin => [HH:MM, ...]]. */
function gudang_pick($byPin, $openers)
{
    if (!empty($byPin[$openers[0]])) return $openers[0];
    $best = null;
    foreach (array_slice($openers, 1) as $p) if (!empty($byPin[$p]) && ($best === null || min($byPin[$p]) < min($byPin[$best]))) $best = $p;
    return $best;
}

/** Scans of the opener of each day on the Margo machines: ['margo' => [sn => name], 'days' => [Y-m-d => ['t' => [HH:MM...], 'm' => [machine => 1], 'pin' => opener PIN, 'who' => name]]]. */
function gudang_scans($from, $to)
{
    $margo = q("SELECT sn, device_name FROM device WHERE device_name LIKE '%margo%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $openers = gudang_openers();
    $raw = [];
    if ($margo) {
        $in = implode(',', array_fill(0, count($margo), '?'));
        $inP = implode(',', array_fill(0, count($openers), '?'));
        foreach (q("SELECT pin, scan_date, sn FROM att_log WHERE pin IN ($inP) AND sn IN ($in) AND scan_date >= ? AND scan_date < ? + INTERVAL 1 DAY ORDER BY scan_date", array_merge($openers, array_keys($margo), [$from, $to])) as $r)
            $raw[substr($r['scan_date'], 0, 10)][(string)$r['pin']][] = [substr($r['scan_date'], 11, 5), $margo[$r['sn']]];
    }
    $names = $raw ? q('SELECT pegawai_pin, pegawai_nama FROM pegawai WHERE pegawai_pin IN (' . implode(',', array_fill(0, count($openers), '?')) . ')', $openers)->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $days = [];
    foreach ($raw as $d => $byPin) {
        $times = array_map(function ($l) { return array_column($l, 0); }, $byPin);
        $pin = gudang_pick($times, $openers);
        $days[$d] = ['t' => $times[$pin], 'm' => array_fill_keys(array_column($byPin[$pin], 1), 1), 'pin' => $pin, 'who' => isset($names[$pin]) ? $names[$pin] : $pin];
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

/** [Y-m-d => ['m' => opening minute, 'pin' => who opened]] for the days in the range, and the ids of warehouse staff (department or office named gudang). */
function gudang_open_minutes($from, $to)
{
    $open = [];
    foreach (gudang_scans($from, $to)['days'] as $d => $v) { $t = min($v['t']); $open[$d] = ['m' => (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2), 'pin' => $v['pin']]; }
    $staff = q("SELECT p.pegawai_id FROM pegawai p LEFT JOIN pembagian2 d ON d.pembagian2_id = p.pembagian2_id LEFT JOIN pembagian3 k ON k.pembagian3_id = p.pembagian3_id
                WHERE d.pembagian2_nama LIKE '%gudang%' OR k.pembagian3_nama LIKE '%gudang%'")->fetchAll(PDO::FETCH_COLUMN);
    return [$open, array_fill_keys(array_map('intval', $staff), true)];
}

/**
 * Overtime before the shift for staff who must come in early (config 'early_ot_pins', e.g. Heni and Yanuar collecting documents
 * at the office before going to the warehouse): minutes between the check-in and the scheduled start, counted like overtime after
 * work (at least $minEot minutes, at most $maxEot). 0 for a check-in at or after the start.
 */
function early_ot_minutes($inM, $startM, $minEot, $maxEot)
{
    $m = $startM - $inM;
    return $m >= max(1, (int)$minEot) ? min($m, (int)$maxEot ?: $m) : 0;
}
