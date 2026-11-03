<?php
// Numbers for the HR home page (Beranda) widgets: employment status, length of service, job level, gender.
// Pure functions over the list people() returns, so they can be tested without a database.

/** Colours for the segments of a bar or donut, in order. */
function home_palette()
{
    return ['#4b61dd', '#27b19b', '#f5a524', '#7a5cd6', '#e5484d', '#8b97a7'];
}

/** [label => count] of $people by $keyFn, in the order of $labels (if given); labels with 0 are kept so the legend is stable. */
function home_count($people, $keyFn, $labels = null)
{
    $out = $labels === null ? [] : array_fill_keys($labels, 0);
    foreach ($people as $p) {
        $k = $keyFn($p);
        $out[$k] = (isset($out[$k]) ? $out[$k] : 0) + 1;
    }
    return $out;
}

/** Whole percent of $n in $total, one decimal ("33.3"); 0 when $total is 0. */
function home_pct($n, $total)
{
    return $total > 0 ? number_format(100 * $n / $total, 1, '.', '') : '0.0';
}

/** Length-of-service bucket of one start date as of $today: '< 1 th' ... '> 10 th', or 'Belum diisi' without a date. */
function home_service_bucket($start, $today)
{
    if (!$start || $start === '0000-00-00') return 'Belum diisi';
    $y = (strtotime($today) - strtotime($start)) / (365.25 * 86400);
    if ($y < 1) return '< 1 th';
    if ($y < 3) return '1-3 th';
    if ($y < 5) return '3-5 th';
    if ($y < 10) return '5-10 th';
    return '> 10 th';
}

/** Length-of-service histogram [bucket => count]; "Belum diisi" is only listed when somebody has no start date. */
function home_service($people, $today)
{
    $labels = ['< 1 th', '1-3 th', '3-5 th', '5-10 th', '> 10 th'];
    $out = home_count($people, function ($p) use ($today) { return home_service_bucket($p['tgl_mulai_kerja'], $today); }, $labels);
    if (empty($out['Belum diisi'])) unset($out['Belum diisi']);
    return $out;
}

/** Employment status per person: [label => count], with the labels of employment_statuses() and "Belum diisi". */
function home_status($people, $current, $statuses)
{
    $labels = array_values($statuses);
    $labels[] = 'Belum diisi';
    $out = home_count($people, function ($p) use ($current, $statuses) {
        $r = isset($current[$p['pegawai_id']]) ? $current[$p['pegawai_id']] : null;
        return $r && isset($statuses[$r['status']]) ? $statuses[$r['status']] : 'Belum diisi';
    }, $labels);
    if (empty($out['Belum diisi'])) unset($out['Belum diisi']);
    return $out;
}

/** Job level (Jabatan) counts in the order of $order (names, in the display order of the Jabatan list); names not in it come last. */
function home_jabatan($people, $order)
{
    $out = home_count($people, function ($p) { return $p['jab'] !== null && $p['jab'] !== '' ? $p['jab'] : 'Belum diisi'; }, $order);
    return array_filter($out, function ($n) { return $n > 0; });
}

/** Gender counts: Laki-laki (1) and Perempuan (2). */
function home_gender($people)
{
    return home_count($people, function ($p) { return (int)$p['gender'] === 2 ? 'Perempuan' : 'Laki-laki'; }, ['Laki-laki', 'Perempuan']);
}

/** CSS conic-gradient for a donut: one arc per count in palette order, or a plain grey ring when everything is 0. */
function home_conic($counts)
{
    $total = array_sum($counts);
    if ($total <= 0) return 'conic-gradient(var(--line) 0 100%)';
    $pal = home_palette();
    $stops = [];
    $acc = 0;
    $i = 0;
    foreach ($counts as $n) {
        if ($n > 0) {
            $from = $acc;
            $acc += 100 * $n / $total;
            $stops[] = $pal[$i % count($pal)] . ' ' . round($from, 2) . '% ' . round($acc, 2) . '%';
        }
        $i++;
    }
    return 'conic-gradient(' . implode(', ', $stops) . ')';
}

/** Who is off on the chosen day: the day range for ?off= today | tomorrow | week. Returns [from, to, label]. */
function home_off_range($which, $today)
{
    if ($which === 'tomorrow') { $d = date('Y-m-d', strtotime($today . ' +1 day')); return [$d, $d, 'Besok']; }
    if ($which === 'week') return [$today, date('Y-m-d', strtotime($today . ' +6 days')), '7 hari'];
    return [$today, $today, 'Hari ini'];
}
