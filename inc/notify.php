<?php
// Notifikasi (HR → Perusahaan): what needs HR's attention today, computed live from the data (no read / unread state).
// notify_build() is pure (arrays in, list out); notify_collect() gathers the arrays from the database.

function notify_levels()
{
    return ['bad' => 'Perlu tindakan', 'warn' => 'Segera', 'info' => 'Info'];
}

/** Days from $a to $b (Y-m-d), negative when $b is earlier. */
function notify_days($a, $b)
{
    return (int)round((strtotime($b) - strtotime($a)) / 86400);
}

/** Next occurrence on or after $today of the month and day of $date (Y-m-d), as Y-m-d; Feb 29 falls on Mar 1 in other years. */
function notify_next_annual($date, $today)
{
    $y = (int)date('Y', strtotime($today));
    for ($i = 0; $i < 2; $i++) {
        $d = date('Y-m-d', strtotime(($y + $i) . '-' . date('m-d', strtotime($date))));
        if ($d >= $today) return $d;
    }
    return $today;
}

/**
 * Build the feed. $d: pending (counts per kind), alerts ([['name', 'what', 'date', 'link']] contracts, probation, documents), birthdays
 * ([['name', 'date' => birth date]]), anniversaries ([['name', 'date' => start date]]), devices ([['name', 'last_scan' => Y-m-d H:i:s|null]]),
 * transfers ([['code', 'name', 'effective', 'summary']]), no_status (number of active employees without employment status).
 * Returns [['level', 'group', 'title', 'detail', 'link'], ...], most urgent first.
 */
function notify_build($d, $today)
{
    $out = [];
    $add = function ($level, $group, $title, $detail, $link) use (&$out) { $out[] = ['level' => $level, 'group' => $group, 'title' => $title, 'detail' => $detail, 'link' => $link]; };

    $pend = ['izin' => ['Cuti / izin', 'hr.php?m=timeoff'], 'lembur' => ['Lembur', 'hr.php?m=overtime'], 'reimburse' => ['Reimbursement', 'payroll.php?p=reimburse'],
             'kasbon' => ['Kasbon', 'payroll.php?p=loans'], 'profil' => ['Perubahan data pribadi', 'hr.php?m=home#tugas']];
    foreach ($pend as $k => $p) {
        $n = isset($d['pending'][$k]) ? (int)$d['pending'][$k] : 0;
        if ($n > 0) $add('bad', 'Persetujuan', "$n pengajuan {$p[0]} menunggu", 'Setujui atau tolak agar karyawan tahu hasilnya.', $p[1]);
    }
    foreach (isset($d['alerts']) ? $d['alerts'] : [] as $a) {
        $left = notify_days($today, $a['date']);
        $when = $left < 0 ? 'lewat ' . (-$left) . ' hari' : ($left === 0 ? 'hari ini' : "$left hari lagi");
        $add($left <= 7 ? 'bad' : 'warn', 'Kontrak & dokumen', $a['name'] . ': ' . $a['what'], date('d-m-Y', strtotime($a['date'])) . " ($when)", $a['link']);
    }
    foreach (isset($d['devices']) ? $d['devices'] : [] as $m) {
        if (!$m['last_scan']) { $add('warn', 'Mesin', $m['name'] . ' belum pernah mengirim scan', 'Periksa koneksi mesin atau Cloud ID-nya.', 'devices.php'); continue; }
        $days = notify_days(substr($m['last_scan'], 0, 10), $today);
        if ($days >= 3) $add('warn', 'Mesin', $m['name'] . " tidak mengirim scan sejak $days hari", 'Scan terakhir ' . date('d-m-Y H:i', strtotime($m['last_scan'])) . '.', 'devices.php');
    }
    foreach (isset($d['transfers']) ? $d['transfers'] : [] as $t) {
        $left = notify_days($today, $t['effective']);
        if ($left >= 0 && $left <= 14) $add('info', 'Mutasi', $t['code'] . ': ' . $t['name'] . ($left === 0 ? ' berlaku hari ini' : " berlaku $left hari lagi"), $t['summary'], 'transfer.php');
    }
    if (!empty($d['no_status'])) $add('info', 'Data karyawan', $d['no_status'] . ' karyawan aktif belum punya status kepegawaian', 'Isi di Daftar karyawan.', 'hr.php?m=employees&ks=none');
    foreach (isset($d['birthdays']) ? $d['birthdays'] : [] as $b) {
        $left = notify_days($today, notify_next_annual($b['date'], $today));
        if ($left > 7) continue;
        $age = (int)date('Y', strtotime(notify_next_annual($b['date'], $today))) - (int)date('Y', strtotime($b['date']));
        $add('info', 'Ulang tahun', $b['name'] . ($left === 0 ? ' berulang tahun hari ini' : " berulang tahun $left hari lagi"), $age . ' tahun · ' . date('d-m', strtotime($b['date'])), 'calendar.php?ym=' . date('Y-m', strtotime(notify_next_annual($b['date'], $today))));
    }
    foreach (isset($d['anniversaries']) ? $d['anniversaries'] : [] as $b) {
        $next = notify_next_annual($b['date'], $today);
        $years = (int)date('Y', strtotime($next)) - (int)date('Y', strtotime($b['date']));
        if ($years < 1 || notify_days($today, $next) > 7) continue;
        $left = notify_days($today, $next);
        $add('info', 'Masa kerja', $b['name'] . ($left === 0 ? " genap $years tahun bekerja hari ini" : " genap $years tahun bekerja $left hari lagi"), date('d-m-Y', strtotime($b['date'])) . ' mulai bekerja', 'calendar.php?ym=' . date('Y-m', strtotime($next)));
    }
    $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
    $keyed = [];
    foreach ($out as $i => $n) $keyed[] = [$rank[$n['level']], $i, $n];
    usort($keyed, function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });   // by level, then in the order they were added (usort is not stable on PHP 7)
    return array_map(function ($k) { return $k[2]; }, $keyed);
}

/** Gather the inputs of notify_build() from the database. */
function notify_collect($today)
{
    $c = function ($sql, $params = []) { try { return (int)q($sql, $params)->fetchColumn(); } catch (Exception $e) { return 0; } };
    $d = ['pending' => [], 'alerts' => [], 'birthdays' => [], 'anniversaries' => [], 'devices' => [], 'transfers' => [], 'no_status' => 0];
    if (defined('REQ_PENDING')) $d['pending']['izin'] = $c('SELECT COUNT(*) FROM izin WHERE izin_status = 0 AND ket_status = ? AND izin_jenis_id NOT BETWEEN 100 AND 105', [REQ_PENDING]);
    if (defined('CLAIM_PENDING')) {
        $d['pending']['lembur'] = $c('SELECT COUNT(*) FROM web_overtime WHERE status = ?', [CLAIM_PENDING]);
        $d['pending']['reimburse'] = $c('SELECT COUNT(*) FROM web_reimburse WHERE status = ?', [CLAIM_PENDING]);
    }
    $d['pending']['kasbon'] = $c("SELECT COUNT(*) FROM web_loan WHERE status = 'pending'");
    $d['pending']['profil'] = $c("SELECT COUNT(*) FROM web_profile_request WHERE status = 'pending'");
    if (function_exists('employment_alerts')) foreach (employment_alerts(30) as $a)
        $d['alerts'][] = ['name' => $a['emp']['pegawai_nama'], 'what' => $a['what'], 'date' => $a['date'], 'link' => 'hr.php?m=employee&id=' . (int)$a['emp']['pegawai_id'] . '&tab=' . (isset($a['tab']) ? $a['tab'] : 'employment')];
    if (function_exists('documents_expiring')) foreach (documents_expiring(30) as $x)
        $d['alerts'][] = ['name' => $x['pegawai_nama'], 'what' => (function_exists('doc_kinds') && isset(doc_kinds()[$x['kind']]) ? doc_kinds()[$x['kind']] : $x['kind']) . ' berakhir', 'date' => $x['expires'], 'link' => 'hr.php?m=employee&id=' . (int)$x['pegawai_id'] . '&tab=docs'];
    foreach (q("SELECT pegawai_nama, tgl_lahir, tgl_mulai_kerja FROM pegawai WHERE pegawai_status = 1")->fetchAll() as $e) {
        if ($e['tgl_lahir'] && $e['tgl_lahir'] !== '0000-00-00') $d['birthdays'][] = ['name' => $e['pegawai_nama'], 'date' => $e['tgl_lahir']];
        if ($e['tgl_mulai_kerja'] && $e['tgl_mulai_kerja'] !== '0000-00-00') $d['anniversaries'][] = ['name' => $e['pegawai_nama'], 'date' => $e['tgl_mulai_kerja']];
    }
    foreach (q("SELECT d.device_name, (SELECT MAX(scan_date) FROM att_log a WHERE a.sn = d.sn) last_scan FROM device d
                WHERE d.sn NOT IN (SELECT sn FROM web_device_archive) AND d.comm_type <> 1")->fetchAll() as $m) $d['devices'][] = ['name' => $m['device_name'], 'last_scan' => $m['last_scan']];
    if (function_exists('transfer_code')) try {
        foreach (q("SELECT t.*, p.pegawai_nama FROM web_transfer t JOIN pegawai p ON p.pegawai_id = t.pegawai_id WHERE t.status = 'scheduled' ORDER BY t.effective_date")->fetchAll() as $t)
            $d['transfers'][] = ['code' => transfer_code($t['id'], $t['created_at']), 'name' => $t['pegawai_nama'], 'effective' => $t['effective_date'], 'summary' => transfer_types()[$t['type']] ?? $t['type']];
    } catch (Exception $e) {}
    if (function_exists('current_employment')) { $cur = current_employment(); foreach (employees(true) as $e) if (!isset($cur[$e['pegawai_id']])) $d['no_status']++; }
    return $d;
}
