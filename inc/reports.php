<?php
// Report calculations shared by report.php (Fingerspot-style Laporan) and hr.php (HR → Laporan).
// Included at top level: reads the filters from the query string (tab, from, to, pegawai_id, dept, sort, jenis, min, year)
// and leaves $tabs, $tab, $from, $to, $headers, $data, $groups, $csvHeaders, $groupStart, $side, $plain in the caller's scope.
// get('csv') === '1' streams the report as CSV and exits.

$tabs = [
    'scanlog'   => 'Data Scanlog',
    'kartu'     => 'Kartu Scanlog',
    'harian'    => 'Rincian Harian',
    'rekap'     => 'Rekap Periode',
    'dl'        => 'Dinas Luar',
    'gudang'    => 'Jam Gudang',
    'antar'     => 'Antar Barang & Bensin',
    'awal'      => 'Datang Awal',
    'cutin'     => 'Cuti Normatif',
    'cutip'     => 'Cuti Pribadi',
    'terlambat' => 'Datang Terlambat',
    'pulang'    => 'Pulang Awal',
    'scan1x'    => 'Scan Kerja 1 x',
    'lembur'    => 'Lembur',
    'luar'      => 'Scan Di Luar Jadwal',
    'kartushift' => 'Kartu Shift',
    'tahunan'   => 'Rincian Tahunan',
];
// Datang Terlambat / Pulang Awal / Lembur: per-employee totals (Rekap) or every day (Detail), at least N minutes
$jenis = get('jenis', 'rekap') === 'detail' ? 'detail' : 'rekap';
$minDur = max(1, (int)get('min', 1));
// extra reports shown only in the HR area (hr.php → Laporan)
$hrTabs = ['izin' => 'Cuti & Izin', 'saldo' => 'Saldo Cuti', 'karyawan' => 'Data Karyawan'];
$tab = get('tab', 'rekap');
if (!isset($tabs[$tab]) && !isset($hrTabs[$tab])) $tab = 'rekap';

// default period: the 21st of last month to the 20th of this month (e.g. 21-09 to 20-10 in October)
$periodFrom = date('Y-m-21', strtotime('first day of last month'));
$periodTo = date('Y-m-20');
$from = get('from', $periodFrom);
$to = get('to', $periodTo);
if (!valid_date($from)) $from = $periodFrom;
if (!valid_date($to)) $to = $periodTo;
if ($from > $to) $to = $from;
$year = (int)get('year', substr($to, 0, 4));
if ($tab === 'tahunan') { $from = "$year-01-01"; $to = min("$year-12-31", date('Y-m-d')); }
elseif ((strtotime($to) - strtotime($from)) / 86400 > 92) $to = date('Y-m-d', strtotime($from . ' +92 days'));
$pid = get('pegawai_id');
$dept = get('dept');
$sort = get('sort', 'pin');

// employee details for the Jabatan / Departemen / Kantor columns
$info = [];
foreach (q('SELECT p.pegawai_id, p.pegawai_pin, p.pegawai_nip, p.pegawai_nama, p.pembagian2_id, p1.pembagian1_nama jab, p2.pembagian2_nama dep, p3.pembagian3_nama kan
            FROM pegawai p LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id
            LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = p.pembagian3_id') as $r) {
    $info[$r['pegawai_pin']] = $r;
}
$byId = [];
foreach ($info as $r) $byId[$r['pegawai_id']] = $r;
$wanted = function ($pin) use ($info, $pid, $dept) {
    if (!isset($info[$pin])) return $pid === '' && $dept === '';
    if ($pid !== '' && (string)$info[$pin]['pegawai_id'] !== $pid) return false;
    if ($dept !== '' && (string)$info[$pin]['pembagian2_id'] !== $dept) return false;
    return true;
};
$emp = function ($pin, $key) use ($info) { return isset($info[$pin]) ? $info[$pin][$key] : ''; };

$daily = function () use ($from, $to, $pid, $wanted, $sort) {
    $rows = array_values(array_filter(daily_attendance($from, $to, $pid !== '' ? (int)$pid : null), function ($r) use ($wanted) { return $wanted($r['pin']); }));
    usort($rows, function ($a, $b) use ($sort) {
        return $sort === 'date' ? [$a['date'], (int)$a['pin']] <=> [$b['date'], (int)$b['pin']] : [(int)$a['pin'], $a['date']] <=> [(int)$b['pin'], $b['date']];
    });
    return $rows;
};
$earlyPaid = array_fill_keys(array_map('strval', isset($GLOBALS['CONFIG']['early_ot_pins']) ? (array)$GLOBALS['CONFIG']['early_ot_pins'] : ['9001', '9006']), true);   // early arrival counts as lembur
$note = function ($r) use ($earlyPaid) {
    $early = !empty($r['early_in']) && isset($earlyPaid[(string)$r['pin']]) ? ['Datang awal ' . fmt_minutes($r['early_in']) . ' dihitung sebagai lembur'] : [];
    return implode('; ', array_merge($r['leave'], $r['off'] ? [$r['off']] : [], $r['missing'] == 100 ? ['Tidak scan masuk'] : [], $r['missing'] == 101 ? ['Tidak scan pulang'] : [], $early));
};

$headers = [];
$groups = null;      // optional first header row: [[label, colspan], ...]
$csvHeaders = null;  // flat column names for CSV when $groups is used
$groupStart = [];    // column indexes that start a group (drawn with a heavier left line)
$data = [];
$side = null;
switch ($tab) {
    case 'scanlog':
        $headers = ['Tanggal scan', 'Tanggal', 'Jam', 'PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Kantor', 'Verifikasi', 'I/O', 'Workcode', 'SN', 'Mesin'];
        $devices = lookup('device');
        $st = q('SELECT * FROM att_log WHERE scan_date >= ? AND scan_date < ? + INTERVAL 1 DAY ORDER BY ' . ($sort === 'date' ? 'scan_date, pin + 0' : 'pin + 0, scan_date'), [$from, $to]);
        $perDate = $perSn = [];
        foreach ($st as $r) {
            if (!$wanted($r['pin'])) continue;
            $mesin = isset($devices[$r['sn']]) ? $devices[$r['sn']] : (is_scan_type($r['sn']) ? 'Manual: ' . scan_types()[(int)$r['sn']][0] : '');
            $data[] = [date('d-m-Y H:i:s', strtotime($r['scan_date'])), date('d-m-Y', strtotime($r['scan_date'])), substr($r['scan_date'], 11, 8),
                       $r['pin'], $emp($r['pin'], 'pegawai_nip'), $emp($r['pin'], 'pegawai_nama'), $emp($r['pin'], 'jab'), $emp($r['pin'], 'dep'),
                       $emp($r['pin'], 'kan'), verify_label($r['verifymode'], $r['sn']), inout_label($r['inoutmode']), $r['work_code'], $r['sn'], $mesin];
            $perDate[$r['sn'] . '|' . substr($r['scan_date'], 0, 10)][$r['pin']] = 1;
            $perSn[$r['sn']][$r['pin']] = 1;
        }
        ksort($perDate);
        $side = [['Scanlog per tanggal', ['SN', 'Tanggal', 'Jumlah PIN yang scan'],
                  array_map(function ($k, $v) { list($sn, $d) = explode('|', $k); return [$sn, date('d-m-Y', strtotime($d)), count($v)]; }, array_keys($perDate), $perDate)],
                 ['Scanlog per SN', ['SN', 'Jumlah PIN yang scan'],
                  array_map(function ($k, $v) { return [$k, count($v)]; }, array_keys($perSn), $perSn)]];
        break;

    case 'kartu':
        $max = 0;
        $cards = [];
        $st = q('SELECT pin, scan_date FROM att_log WHERE scan_date >= ? AND scan_date < ? + INTERVAL 1 DAY ORDER BY scan_date', [$from, $to]);
        foreach ($st as $r) {
            if (!$wanted($r['pin'])) continue;
            $k = $r['pin'] . '|' . substr($r['scan_date'], 0, 10);
            $cards[$k][] = substr($r['scan_date'], 11, 5);
            $max = max($max, count($cards[$k]));
        }
        uksort($cards, function ($a, $b) use ($sort) {
            $x = explode('|', $a); $y = explode('|', $b);
            return $sort === 'date' ? [$x[1], (int)$x[0]] <=> [$y[1], (int)$y[0]] : [(int)$x[0], $x[1]] <=> [(int)$y[0], $y[1]];
        });
        $max = min(max($max, 2), 10);
        $headers = array_merge(['PIN', 'Nama', 'Tanggal'], array_map(function ($i) { return "Scan $i"; }, range(1, $max)));
        foreach ($cards as $k => $times) {
            list($pin, $d) = explode('|', $k);
            $data[] = array_merge([$pin, $emp($pin, 'pegawai_nama'), fmt_date($d)], array_pad(array_slice($times, 0, $max), $max, ''));
        }
        break;

    case 'terlambat':
    case 'pulang':
    case 'lembur':
        $field = ['terlambat' => 'late', 'pulang' => 'early', 'lembur' => 'ot'][$tab];
        $hits = array_filter($daily(), function ($r) use ($field, $minDur) { return $r[$field] >= $minDur; });
        if ($jenis === 'rekap') {
            $headers = ['PIN', 'NIP', 'Nama', 'Jumlah', 'Durasi', 'Jabatan', 'Departemen', 'Kantor'];
            $sum = [];
            foreach ($hits as $r) {
                if (!isset($sum[$r['pin']])) $sum[$r['pin']] = [0, 0];
                $sum[$r['pin']][0]++;
                $sum[$r['pin']][1] += $r[$field];
            }
            foreach ($sum as $pin => $v) {
                $data[] = [$pin, $emp($pin, 'pegawai_nip'), $emp($pin, 'pegawai_nama'), $v[0], fmt_minutes($v[1]), $emp($pin, 'jab'), $emp($pin, 'dep'), $emp($pin, 'kan')];
            }
        } else {
            $headers = ['Tanggal', 'PIN', 'NIP', 'Nama', 'Jam kerja', 'Masuk', 'Pulang', 'Durasi', 'Jabatan', 'Departemen', 'Kantor'];
            foreach ($hits as $r) {
                $data[] = [fmt_date($r['date']), $r['pin'], $emp($r['pin'], 'pegawai_nip'), $r['name'], $r['jk'], $r['first'], $r['last'],
                           fmt_minutes($r[$field]), $emp($r['pin'], 'jab'), $emp($r['pin'], 'dep'), $emp($r['pin'], 'kan')];
            }
        }
        break;

    case 'kartushift':
        $dates = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $dates[] = $d;
        // two header rows like the desktop program: Pegawai | Jumlah | one date over each In / Out pair
        $headers = ['PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Kantor', 'Kehadiran'];
        $csvHeaders = ['PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Kantor', 'Jumlah Kehadiran'];
        $groups = [['Pegawai', 6], ['Jumlah', 1]];
        $groupStart = [6];
        foreach ($dates as $d) {
            $label = date('d-m-Y', strtotime($d));
            $groups[] = [$label, 2];
            $groupStart[] = count($headers);
            array_push($headers, 'In', 'Out');
            array_push($csvHeaders, "$label In", "$label Out");
        }
        $grid = [];
        foreach ($daily() as $r) $grid[$r['pin']][$r['date']] = $r;
        // inactive employees are listed too (with empty days), as in the program; resigned ones are not
        if ($pid === '') {
            foreach (q('SELECT pegawai_pin FROM pegawai WHERE pegawai_status = 0')->fetchAll(PDO::FETCH_COLUMN) as $pin) {
                if (!isset($grid[$pin]) && $wanted($pin)) $grid[$pin] = [];
            }
        }
        uksort($grid, function ($a, $b) { return (int)$a <=> (int)$b; });
        foreach ($grid as $pin => $days) {
            $count = 0;
            $cells = [];
            foreach ($dates as $d) {
                $r = isset($days[$d]) ? $days[$d] : null;
                if ($r && ($r['first'] || $r['last'])) $count++;
                // missing times stay blank; when only one of In / Out is there, the empty one is marked
                $half = $r && ($r['first'] xor $r['last']);
                $cells[] = $r && $r['first'] ? $r['first'] : ($half ? ['miss' => true] : '');
                $cells[] = $r && $r['last'] ? $r['last'] : ($half ? ['miss' => true] : '');
            }
            $data[] = array_merge([$pin, $emp($pin, 'pegawai_nip'), $emp($pin, 'pegawai_nama'), $emp($pin, 'jab'), $emp($pin, 'dep'), $emp($pin, 'kan'), $count], $cells);
        }
        break;

    case 'harian':
    case 'scan1x':
    case 'luar':
        $headers = ['Tanggal', 'PIN', 'Nama', 'Jabatan', 'Departemen', 'Jam kerja', 'Masuk', 'Pulang', 'Datang awal', 'Terlambat', 'Pulang awal', 'Lembur', 'Durasi', 'Scan', 'Status', 'Keterangan'];
        foreach ($daily() as $r) {
            if ($tab === 'scan1x' && $r['status'] !== 'incomplete') continue;
            if ($tab === 'luar' && !($r['off'] && $r['scans'])) continue;
            $data[] = [fmt_date($r['date']), $r['pin'], $r['name'], $emp($r['pin'], 'jab'), $emp($r['pin'], 'dep'), $r['jk'] ?: ($r['off'] ? 'Libur' : ''),
                       $r['first'], $r['last'], !empty($r['early_in']) ? fmt_minutes($r['early_in']) : '', $r['late'] ? fmt_minutes($r['late']) : '', $r['early'] ? fmt_minutes($r['early']) : '',
                       $r['ot'] ? (!empty($r['ot_early']) ? fmt_minutes($r['ot'] - $r['ot_early']) . ' + ' . fmt_minutes($r['ot_early']) . ' awal = ' . fmt_minutes($r['ot']) : fmt_minutes($r['ot'])) : '', fmt_minutes($r['minutes']), $r['scans'] ?: '', ['status' => $r['status']], $note($r)];
        }
        break;

    case 'rekap':
        $headers = ['PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Hari kerja', 'Hadir', 'Datang awal (jam)', 'Terlambat (x)', 'Terlambat (jam)', 'Pulang awal (x)', 'Pulang awal (jam)',
                    'Lembur (x)', 'Lembur setelah pulang (jam)', 'Lembur datang awal (jam)', 'Total lembur (jam)', 'Scan 1x', 'Tidak hadir', 'Izin / cuti', 'Izin / cuti (tanggal)', 'Masuk di hari libur', 'Dinas luar (hari)', 'Dinas luar (tanggal)', 'Driver (hari)', 'Kirim sendiri (trip)', 'Insentif antar', 'Bensin (km)', 'Bensin (Rp)', 'Total durasi'];
        require_once __DIR__ . '/payroll.php';
        ensure_payroll_tables();
        $rps = payroll_settings();
        $tripOf = q('SELECT pegawai_id, trip_driver, trip_alone FROM web_bonus WHERE period_to = ?', [$to])->fetchAll(PDO::FETCH_UNIQUE);
        $fuelOf = [];
        foreach (q('SELECT pegawai_id, dest, trips FROM web_fuel WHERE period_to = ?', [$to]) as $fr) $fuelOf[$fr['pegawai_id']][$fr['dest']] = (int)$fr['trips'];   // Rekap gudang of the period ending on "s/d"
        require_once __DIR__ . '/dl.php';
        ensure_dl_tables();
        $dlMarked = dl_days($from, $to);
        $sum = [];
        foreach ($daily() as $r) {
            $k = $r['pin'];
            if (!isset($sum[$k])) $sum[$k] = ['work' => 0, 'ok' => 0, 'late' => 0, 'lateM' => 0, 'early' => 0, 'earlyM' => 0, 'incomplete' => 0, 'absent' => 0, 'leave' => 0, 'offWork' => 0, 'min' => 0, 'ot' => 0, 'otM' => 0, 'earlyIn' => 0];
            $s = &$sum[$k];
            if (!$r['off']) $s['work']++;
            if ($r['off'] && $r['scans']) $s['offWork']++;
            if ($r['status'] === 'ok') $s['ok']++;
            if ($r['status'] === 'incomplete') $s['incomplete']++;
            if ($r['status'] === 'absent') $s['absent']++;
            if ($r['leave']) { $s['leave']++; $s['leaveDays'][] = date('d-m', strtotime($r['date'])) . ' ' . implode('/', $r['leave']); }
            if ($r['late']) { $s['late']++; $s['lateM'] += $r['late']; }
            if ($r['early']) { $s['early']++; $s['earlyM'] += $r['early']; }
            if ($r['ot']) { $s['ot']++; $s['otM'] += $r['ot']; $s['otE'] = (isset($s['otE']) ? $s['otE'] : 0) + (isset($r['ot_early']) ? (int)$r['ot_early'] : 0); }
            $s['min'] += (int)$r['minutes'];
            $s['earlyIn'] += isset($r['early_in']) ? (int)$r['early_in'] : 0;
            unset($s);
        }
        $names = [];
        foreach (array_keys($earlyPaid) as $ep) if (isset($info[$ep])) $names[] = $info[$ep]['pegawai_nama'];
        $side = [['Keterangan', ['Hal', 'Penjelasan'], [
            ['Datang awal', 'Menit sebelum jam masuk jadwal. Untuk ' . implode(' dan ', $names ?: ['(tidak ada)']) . ' datang awal dibayar sebagai lembur: Lembur setelah pulang + Lembur datang awal = Total lembur (yang dibayar), karena harus ke kantor dulu sebelum ke gudang; untuk karyawan lain hanya dicatat.'],
            ['Driver / kirim sendiri', 'Dari Penggajian › Rekap gudang untuk periode gaji yang berakhir pada tanggal "s/d": driver Rp' . number_format($rps['trip_driver'], 0, ',', '.') . '/hari, kirim sendiri (1 kernet) Rp' . number_format($rps['trip_alone'], 0, ',', '.') . '/trip.'],
            ['Bensin', 'Perjalanan kantor–gudang pulang-pergi dari Rekap gudang × km × 2 × Rp' . number_format($rps['fuel_per_km'], 0, ',', '.') . '/km; dibayar sebagai reimbursement (tidak kena pajak).'],
            ['Terlambat gudang', 'Karyawan gudang dihitung terlambat dari jam gudang dibuka (scan pertama pembuka di mesin Margo) bila lebih lambat dari jam masuk, dengan toleransi antre.']]]];
        foreach ($sum as $pin => $s) {
            $tr = isset($info[$pin], $tripOf[$info[$pin]['pegawai_id']]) ? $tripOf[$info[$pin]['pegawai_id']] : null;
            $fu = fuel_amount(isset($info[$pin], $fuelOf[$info[$pin]['pegawai_id']]) ? $fuelOf[$info[$pin]['pegawai_id']] : [], fuel_routes(), $rps['fuel_per_km']);   // bensin kantor-gudang (reimbursement)
            $data[] = [$pin, $emp($pin, 'pegawai_nip'), $emp($pin, 'pegawai_nama'), $emp($pin, 'jab'), $emp($pin, 'dep'), $s['work'], $s['ok'], $s['earlyIn'] ? fmt_minutes($s['earlyIn']) : '',
                       $s['late'] ?: '', $s['lateM'] ? fmt_minutes($s['lateM']) : '', $s['early'] ?: '', $s['earlyM'] ? fmt_minutes($s['earlyM']) : '',
                       $s['ot'] ?: '', $s['otM'] - (isset($s['otE']) ? $s['otE'] : 0) ? fmt_minutes($s['otM'] - (isset($s['otE']) ? $s['otE'] : 0)) : '', !empty($s['otE']) ? fmt_minutes($s['otE']) : '', $s['otM'] ? fmt_minutes($s['otM']) : '',
                       $s['incomplete'] ?: '', $s['absent'] ?: '', $s['leave'] ?: '', isset($s['leaveDays']) ? implode(', ', $s['leaveDays']) : '', $s['offWork'] ?: '',
                       isset($info[$pin], $dlMarked[$info[$pin]['pegawai_id']]) ? count($dlMarked[$info[$pin]['pegawai_id']]) : '',
                       isset($info[$pin], $dlMarked[$info[$pin]['pegawai_id']]) ? dl_ranges($dlMarked[$info[$pin]['pegawai_id']]) : '',
                       $tr ? ((int)$tr['trip_driver'] ?: '') : '', $tr ? ((int)$tr['trip_alone'] ?: '') : '',
                       $tr && ($tr['trip_driver'] || $tr['trip_alone']) ? rupiah($tr['trip_driver'] * $rps['trip_driver'] + $tr['trip_alone'] * $rps['trip_alone']) : '',
                       $fu[0] ?: '', $fu[1] ? rupiah($fu[1]) : '', fmt_minutes($s['min'])];
        }
        break;

    case 'gudang':   // warehouse open / close time: the head of the warehouse's first and last scan of the day on the Margo machines
        require_once __DIR__ . '/gudang.php';
        $pin = gudang_pin();
        $headers = ['Tanggal', 'Hari', 'Dibuka oleh', 'Gudang buka', 'Gudang tutup', 'Lama buka', 'Jumlah scan', 'Mesin'];
        $g = gudang_scans($from, $to);
        $margo = $g['margo'];
        $days = $g['days'];
        $dn = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
        $openMin = $closeMin = [];
        $hm = function ($t) { return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); };
        foreach ($days as $d => $v) {
            $open = min($v['t']);
            $close = count($v['t']) > 1 ? max($v['t']) : null;
            $openMin[] = $hm($open);
            if ($close) $closeMin[] = $hm($close);
            $data[] = [date('d-m-Y', strtotime($d)), $dn[date('N', strtotime($d))], $v['who'], $open, $close ?: '(1 scan saja)', $close ? fmt_minutes($hm($close) - $hm($open)) : '', count($v['t']), implode(', ', array_keys($v['m']))];
        }
        $avg = function ($a) { return $a ? sprintf('%02d:%02d', intdiv((int)round(array_sum($a) / count($a)), 60), (int)round(array_sum($a) / count($a)) % 60) : '-'; };
        $work = 0;   // Monday-Saturday in the period without a scan of this person on the Margo machines
        for ($t = strtotime($from); $t <= strtotime($to) && $t <= time(); $t += 86400) if (date('N', $t) < 7 && !isset($days[date('Y-m-d', $t)])) $work++;
        $side = [['Ringkasan (pembuka PIN ' . implode(' / ', gudang_openers()) . ', mesin ' . ($margo ? implode(' / ', $margo) : 'Margo tidak ditemukan') . ')', ['Keterangan', 'Nilai'],
                  [['Hari dengan scan', count($days)], ['Rata-rata gudang buka', $avg($openMin)], ['Rata-rata gudang tutup', $avg($closeMin)], ['Senin-Sabtu tanpa pembuka scan di Margo', $work]]]];
        break;

    case 'antar':   // driver days, kirim sendiri trips and office-warehouse trips entered on Penggajian > Rekap gudang for the period ending on "s/d"
        require_once __DIR__ . '/payroll.php';
        ensure_payroll_tables();
        $ps = payroll_settings();
        $routes = fuel_routes();
        $headers = array_merge(['PIN', 'Nama', 'Departemen', 'Driver (hari)', 'Insentif driver', 'Kirim sendiri (trip)', 'Insentif kirim sendiri'], array_map(function ($d) { return "Kantor-$d (PP)"; }, array_keys($routes)), ['Km', 'Bensin', 'Total']);
        $trip = q('SELECT pegawai_id, trip_driver, trip_alone FROM web_bonus WHERE period_to = ?', [$to])->fetchAll(PDO::FETCH_UNIQUE);
        $fuel = [];
        foreach (q('SELECT pegawai_id, dest, trips FROM web_fuel WHERE period_to = ?', [$to]) as $r) $fuel[$r['pegawai_id']][$r['dest']] = (int)$r['trips'];
        $sum = array_fill(0, count($headers), 0);
        foreach ($byId as $id => $r) {
            $t = isset($trip[$id]) ? $trip[$id] : ['trip_driver' => 0, 'trip_alone' => 0];
            $f = isset($fuel[$id]) ? $fuel[$id] : [];
            if (!$t['trip_driver'] && !$t['trip_alone'] && !$f) continue;
            if (!$wanted($r['pegawai_pin'])) continue;
            list($km, $rp) = fuel_amount($f, $routes, $ps['fuel_per_km']);
            $dv = $t['trip_driver'] * $ps['trip_driver'];
            $al = $t['trip_alone'] * $ps['trip_alone'];
            $row = [$r['pegawai_pin'], $r['pegawai_nama'], $r['dep'], (int)$t['trip_driver'] ?: '', $dv ? rupiah($dv) : '', (int)$t['trip_alone'] ?: '', $al ? rupiah($al) : ''];
            foreach (array_keys($routes) as $d) $row[] = isset($f[$d]) ? $f[$d] : '';
            $data[] = array_merge($row, [$km ?: '', $rp ? rupiah($rp) : '', rupiah($dv + $al + $rp)]);
        }
        usort($data, function ($x, $y) { return strcmp($x[1], $y[1]); });
        $side = [['Keterangan', ['Hal', 'Nilai'], [['Periode gaji sampai', fmt_date($to)], ['Sumber', 'Penggajian › Rekap gudang (driver & bensin)'],
                  ['Tarif', 'Driver ' . rupiah($ps['trip_driver']) . '/hari, kirim sendiri ' . rupiah($ps['trip_alone']) . '/trip, bensin ' . rupiah($ps['fuel_per_km']) . '/km'],
                  ['Jarak', implode(', ', array_map(function ($d, $k) { return "$d $k km"; }, array_keys($routes), $routes))]]]];
        break;

    case 'awal':   // arrived before the scheduled start; paid as lembur only for early_ot_pins (Heni, Yanuar)
        global $CONFIG;
        $paid = array_fill_keys(array_map('strval', isset($CONFIG['early_ot_pins']) ? (array)$CONFIG['early_ot_pins'] : ['9001', '9006']), true);
        $headers = ['Tanggal', 'PIN', 'Nama', 'Departemen', 'Jam kerja', 'Masuk', 'Datang awal', 'Dibayar lembur'];
        $tot = [];
        foreach ($daily() as $r) {
            if (empty($r['early_in'])) continue;
            $data[] = [fmt_date($r['date']), $r['pin'], $r['name'], $emp($r['pin'], 'dep'), $r['jk'], $r['first'], fmt_minutes($r['early_in']), isset($paid[(string)$r['pin']]) ? 'Ya' : ''];
            if (!isset($tot[$r['pin']])) $tot[$r['pin']] = [$r['pin'], $r['name'], 0, 0];
            $tot[$r['pin']][2]++;
            $tot[$r['pin']][3] += $r['early_in'];
        }
        $side = [['Rekap per karyawan', ['PIN', 'Nama', 'Hari', 'Total datang awal'], array_map(function ($t) { return [$t[0], $t[1], $t[2], fmt_minutes($t[3])]; }, array_values($tot))]];
        break;

    case 'dl':
        require_once __DIR__ . '/dl.php';
        ensure_dl_tables();
        $headers = ['PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Hari DL', 'Sabtu', 'Tanggal'];
        $dlRows = [];
        foreach (dl_days($from, $to) as $id => $dates) {
            if (!isset($byId[$id]) || !$wanted($byId[$id]['pegawai_pin'])) continue;
            $dlRows[] = [$byId[$id]['pegawai_pin'], $byId[$id]['pegawai_nip'], $byId[$id]['pegawai_nama'], $byId[$id]['jab'], $byId[$id]['dep'],
                         count($dates), count(array_filter($dates, function ($d) { return date('N', strtotime($d)) == 6; })) ?: '', dl_ranges($dates)];
        }
        usort($dlRows, function ($a, $b) { return (int)$a[0] <=> (int)$b[0]; });
        $data = $dlRows;
        break;

    case 'cutin':
    case 'cutip':
        $jenis = $tab === 'cutin' ? 80 : 90;
        $headers = ['Tanggal', 'PIN', 'Nama', 'Departemen', 'Jenis', 'Alasan', 'Catatan', 'Status', 'Diajukan'];
        $st = q('SELECT i.*, p.pegawai_pin, k.kat_izin_nama, c.cuti_n_nama FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
                 LEFT JOIN kategori_izin k ON k.kat_izin_id = i.kat_izin_id LEFT JOIN cuti_normatif c ON c.cuti_n_id = i.cuti_n_id AND i.cuti_n_id > 0
                 WHERE i.izin_jenis_id = ? AND i.izin_tgl BETWEEN ? AND ? ORDER BY ' . ($sort === 'date' ? 'i.izin_tgl, p.pegawai_pin + 0' : 'p.pegawai_pin + 0, i.izin_tgl'),
            [$jenis, $from, $to]);
        foreach ($st as $r) {
            if (!$wanted($r['pegawai_pin'])) continue;
            $data[] = [fmt_date($r['izin_tgl']), $r['pegawai_pin'], $emp($r['pegawai_pin'], 'pegawai_nama'), $emp($r['pegawai_pin'], 'dep'),
                       $r['cuti_n_nama'] ?: ($jenis === 80 ? 'Cuti normatif' : 'Cuti pribadi'), $r['kat_izin_nama'], $r['izin_catatan'],
                       $r['izin_status'] ? 'Approved' : 'Not approved', $r['izin_tgl_pengajuan']];
        }
        break;

    case 'izin':
        require_once __DIR__ . '/portal.php';
        $headers = ['Tanggal', 'PIN', 'Nama', 'Departemen', 'Jenis', 'Kategori', 'Catatan', 'Diajukan', 'Status'];
        $st = q('SELECT i.*, p.pegawai_pin, j.izin_jenis_name, k.kat_izin_nama FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
                 LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id LEFT JOIN kategori_izin k ON k.kat_izin_id = i.kat_izin_id
                 WHERE i.izin_jenis_id NOT BETWEEN 100 AND 105 AND i.izin_tgl BETWEEN ? AND ? ORDER BY ' . ($sort === 'date' ? 'i.izin_tgl, p.pegawai_pin + 0' : 'p.pegawai_pin + 0, i.izin_tgl'),
            [$from, $to]);
        $label = ['approved' => 'Disetujui', 'pending' => 'Menunggu', 'rejected' => 'Ditolak'];
        foreach ($st as $r) {
            if (!$wanted($r['pegawai_pin'])) continue;
            $data[] = [fmt_date($r['izin_tgl']), $r['pegawai_pin'], $emp($r['pegawai_pin'], 'pegawai_nama'), $emp($r['pegawai_pin'], 'dep'), $r['izin_jenis_name'],
                       $r['kat_izin_nama'], $r['izin_catatan'], $r['izin_tgl_pengajuan'] ? date('d-m-Y', strtotime($r['izin_tgl_pengajuan'])) : '', $label[request_state($r)]];
        }
        break;

    case 'saldo':
        require_once __DIR__ . '/portal.php';
        $headers = ['PIN', 'NIP', 'Nama', 'Departemen', 'Jatah cuti ' . date('Y'), 'Diambil', 'Menunggu', 'Sisa'];
        foreach (employees(true) as $e) {
            if (!$wanted($e['pegawai_pin'])) continue;
            list($quota, $taken, $pend) = leave_balance($e['pegawai_id']);
            $data[] = [$e['pegawai_pin'], $e['pegawai_nip'], $e['pegawai_nama'], $emp($e['pegawai_pin'], 'dep'), $quota === null ? '' : $quota, $taken, $pend, $quota === null ? '' : $quota - $taken];
        }
        break;

    case 'karyawan':
        $headers = ['PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Kantor', 'Jenis kelamin', 'Tempat lahir', 'Tanggal lahir', 'Mulai bekerja', 'Masa kerja', 'Status', 'No. telp'];
        $statusName = ['0' => 'Non aktif', '1' => 'Aktif', '2' => 'Berhenti'];
        foreach (q('SELECT * FROM pegawai ORDER BY pegawai_pin + 0') as $r) {
            if (!$wanted($r['pegawai_pin']) || ($r['pegawai_status'] == 2 && get('all') !== '1')) continue;
            $tenure = '';
            if ($r['tgl_mulai_kerja']) {
                $d = (new DateTime($r['tgl_mulai_kerja']))->diff(new DateTime($r['tgl_resign'] ?: 'today'));
                $tenure = $d->y . ' th ' . $d->m . ' bln';
            }
            $data[] = [$r['pegawai_pin'], $r['pegawai_nip'], $r['pegawai_nama'], $emp($r['pegawai_pin'], 'jab'), $emp($r['pegawai_pin'], 'dep'), $emp($r['pegawai_pin'], 'kan'),
                       (int)$r['gender'] === 2 ? 'Perempuan' : 'Laki-laki', $r['tempat_lahir'], $r['tgl_lahir'] ? date('d-m-Y', strtotime($r['tgl_lahir'])) : '',
                       $r['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($r['tgl_mulai_kerja'])) : '', $tenure, $statusName[$r['pegawai_status']] ?? '', $r['pegawai_telp']];
        }
        break;

    case 'tahunan':
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $headers = array_merge(['PIN', 'Nama', ''], $months, ['Total']);
        $agg = [];
        foreach ($daily() as $r) {
            $mon = (int)substr($r['date'], 5, 2) - 1;
            foreach (['Hadir' => $r['status'] === 'ok' || $r['status'] === 'incomplete', 'Terlambat' => $r['late'] > 0,
                      'Tidak hadir' => $r['status'] === 'absent', 'Izin / cuti' => (bool)$r['leave']] as $kind => $hit) {
                if (!isset($agg[$r['pin']][$kind])) $agg[$r['pin']][$kind] = array_fill(0, 12, 0);
                if ($hit) $agg[$r['pin']][$kind][$mon]++;
            }
        }
        foreach ($agg as $pin => $kinds) {
            foreach ($kinds as $kind => $vals) {
                $data[] = array_merge([$pin, $emp($pin, 'pegawai_nama'), $kind], array_map(function ($v) { return $v ?: ''; }, $vals), [array_sum($vals)]);
            }
        }
        break;
}


$plain = function ($cell) { return is_array($cell) ? (isset($cell['status']) ? $cell['status'] : '') : $cell; };
if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_' . $tab . '_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel opens it as UTF-8
    fputcsv($out, $csvHeaders ?: $headers);
    foreach ($data as $row) fputcsv($out, array_map($plain, $row));
    exit;
}

