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
$note = function ($r) {
    return implode('; ', array_merge($r['leave'], $r['off'] ? [$r['off']] : [], $r['missing'] == 100 ? ['Tidak scan masuk'] : [], $r['missing'] == 101 ? ['Tidak scan pulang'] : []));
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
        $headers = ['Tanggal', 'PIN', 'Nama', 'Jabatan', 'Departemen', 'Jam kerja', 'Masuk', 'Pulang', 'Terlambat', 'Pulang awal', 'Lembur', 'Durasi', 'Scan', 'Status', 'Keterangan'];
        foreach ($daily() as $r) {
            if ($tab === 'scan1x' && $r['status'] !== 'incomplete') continue;
            if ($tab === 'luar' && !($r['off'] && $r['scans'])) continue;
            $data[] = [fmt_date($r['date']), $r['pin'], $r['name'], $emp($r['pin'], 'jab'), $emp($r['pin'], 'dep'), $r['jk'] ?: ($r['off'] ? 'Libur' : ''),
                       $r['first'], $r['last'], $r['late'] ? fmt_minutes($r['late']) : '', $r['early'] ? fmt_minutes($r['early']) : '',
                       $r['ot'] ? fmt_minutes($r['ot']) : '', fmt_minutes($r['minutes']), $r['scans'] ?: '', ['status' => $r['status']], $note($r)];
        }
        break;

    case 'rekap':
        $headers = ['PIN', 'NIP', 'Nama', 'Jabatan', 'Departemen', 'Hari kerja', 'Hadir', 'Terlambat (x)', 'Terlambat (jam)', 'Pulang awal (x)', 'Pulang awal (jam)',
                    'Lembur (x)', 'Lembur (jam)', 'Scan 1x', 'Tidak hadir', 'Izin / cuti', 'Masuk di hari libur', 'Dinas luar (hari)', 'Total durasi'];
        require_once __DIR__ . '/dl.php';
        ensure_dl_tables();
        $dlMarked = dl_days($from, $to);
        $sum = [];
        foreach ($daily() as $r) {
            $k = $r['pin'];
            if (!isset($sum[$k])) $sum[$k] = ['work' => 0, 'ok' => 0, 'late' => 0, 'lateM' => 0, 'early' => 0, 'earlyM' => 0, 'incomplete' => 0, 'absent' => 0, 'leave' => 0, 'offWork' => 0, 'min' => 0, 'ot' => 0, 'otM' => 0];
            $s = &$sum[$k];
            if (!$r['off']) $s['work']++;
            if ($r['off'] && $r['scans']) $s['offWork']++;
            if ($r['status'] === 'ok') $s['ok']++;
            if ($r['status'] === 'incomplete') $s['incomplete']++;
            if ($r['status'] === 'absent') $s['absent']++;
            if ($r['leave']) $s['leave']++;
            if ($r['late']) { $s['late']++; $s['lateM'] += $r['late']; }
            if ($r['early']) { $s['early']++; $s['earlyM'] += $r['early']; }
            if ($r['ot']) { $s['ot']++; $s['otM'] += $r['ot']; }
            $s['min'] += (int)$r['minutes'];
            unset($s);
        }
        foreach ($sum as $pin => $s) {
            $data[] = [$pin, $emp($pin, 'pegawai_nip'), $emp($pin, 'pegawai_nama'), $emp($pin, 'jab'), $emp($pin, 'dep'), $s['work'], $s['ok'],
                       $s['late'] ?: '', $s['lateM'] ? fmt_minutes($s['lateM']) : '', $s['early'] ?: '', $s['earlyM'] ? fmt_minutes($s['earlyM']) : '',
                       $s['ot'] ?: '', $s['otM'] ? fmt_minutes($s['otM']) : '',
                       $s['incomplete'] ?: '', $s['absent'] ?: '', $s['leave'] ?: '', $s['offWork'] ?: '',
                       isset($info[$pin], $dlMarked[$info[$pin]['pegawai_id']]) ? count($dlMarked[$info[$pin]['pegawai_id']]) : '', fmt_minutes($s['min'])];
        }
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

