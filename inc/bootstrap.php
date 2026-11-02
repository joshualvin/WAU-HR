<?php
// Shared setup for every page: config, database, session, sign-in, CSRF and small helpers.
// Works directly on the Fingerspot (fin_pro) tables; the only tables it adds are web_user and web_log.

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    exit('config.php belum ada - salin config.example.php menjadi config.php lalu isi login database.');
}
$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set(isset($CONFIG['timezone']) ? $CONFIG['timezone'] : 'Asia/Jakarta');

session_name('finpro_web');
session_start();

function db()
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8',
            $CONFIG['db_host'], isset($CONFIG['db_port']) ? (int)$CONFIG['db_port'] : 3306, $CONFIG['db_name']);
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function ensure_web_tables()
{
    // MySQL 5.1: no DEFAULT CURRENT_TIMESTAMP on DATETIME, so times are set from PHP.
    db()->exec("CREATE TABLE IF NOT EXISTS web_user (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        full_name VARCHAR(100) NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
        active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        last_login DATETIME NULL,
        UNIQUE KEY username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        username VARCHAR(50) NOT NULL,
        action VARCHAR(50) NOT NULL,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Portal Pegawai sign-ins: PIN + a login code that HR creates on the HR Overview page
    db()->exec("CREATE TABLE IF NOT EXISTS web_emp (
        pegawai_id INT NOT NULL PRIMARY KEY,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NULL,
        last_login DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

// ---------- output helpers ----------

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function get($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/** A same-site page to go back to after a form, or $default if $url points anywhere else. */
function safe_back($url, $default)
{
    if ($url === '' || !preg_match('#^/?[A-Za-z0-9_./-]*\.php(\?[^\s<>"]*)?(\#[\w-]*)?$#', $url) || strpos($url, '//') !== false) return $default;
    return $url;
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function flash($msg, $type = 'ok')
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes()
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

function valid_date($s)
{
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

function valid_time($s)
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s);
}

function fmt_date($s)
{
    if (!$s) return '';
    $days = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    return $days[(int)date('w', strtotime($s))] . ' ' . date('d-m-Y', strtotime($s));
}

function fmt_minutes($m)
{
    if ($m === null) return '';
    return sprintf('%d:%02d', intdiv((int)$m, 60), (int)$m % 60);
}

// ---------- auth & CSRF ----------

function current_user()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login($role = null)
{
    $u = current_user();
    if (!$u) redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
    if ($role === 'admin' && $u['role'] !== 'admin') {
        http_response_code(403);
        exit('Hanya admin yang dapat membuka halaman ini.');
    }
    return $u;
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!hash_equals(csrf_token(), post('csrf'))) {
        http_response_code(400);
        exit('Form kedaluwarsa. Kembali, muat ulang halaman, lalu coba lagi.');
    }
}

/** Who is acting: the admin user, or 'portal:PIN' for an employee signed in to the Portal Pegawai. */
function actor()
{
    $u = current_user();
    if ($u) return $u['username'];
    return isset($_SESSION['emp']) ? 'portal:' . $_SESSION['emp']['pin'] : '-';
}

function audit($action, $detail)
{
    q('INSERT INTO web_log (created_at, username, action, detail) VALUES (?, ?, ?, ?)',
        [date('Y-m-d H:i:s'), actor(), $action, mb_substr($detail, 0, 500)]);
}

// ---------- Fingerspot lookups ----------

// "Tidak scan" leave types. Fingerspot records a forgotten scan as an approved izin of one of these types,
// plus an att_log row whose sn is the type id (100 = check-in, 101 = check-out, ...).
function scan_types()
{
    return [
        100 => ['Masuk',             12],
        101 => ['Pulang',            13],
        102 => ['Istirahat Mulai',   14],
        103 => ['Istirahat Selesai', 15],
        104 => ['Lembur Mulai',      16],
        105 => ['Lembur Selesai',    17],
    ];
}

function is_scan_type($jenis)
{
    return isset(scan_types()[(int)$jenis]);
}

function employees($activeOnly = true)
{
    $sql = 'SELECT pegawai_id, pegawai_pin, pegawai_nip, pegawai_nama, pegawai_status, tgl_mulai_kerja FROM pegawai';
    if ($activeOnly) $sql .= ' WHERE pegawai_status = 1';
    return q($sql . ' ORDER BY pegawai_nama')->fetchAll();
}

function employee($id)
{
    return q('SELECT * FROM pegawai WHERE pegawai_id = ?', [(int)$id])->fetch();
}

function leave_types()
{
    return q('SELECT izin_jenis_id, izin_jenis_name FROM jns_izin ORDER BY izin_jenis_id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function leave_categories()
{
    return q('SELECT kat_izin_id, kat_izin_nama FROM kategori_izin ORDER BY kat_izin_nama')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function lookup($table)
{
    $map = [
        'pembagian1' => 'SELECT pembagian1_id, pembagian1_nama FROM pembagian1 ORDER BY pembagian1_id',
        'pembagian2' => 'SELECT pembagian2_id, pembagian2_nama FROM pembagian2 ORDER BY pembagian2_id',
        'pembagian3' => 'SELECT pembagian3_id, pembagian3_nama FROM pembagian3 ORDER BY pembagian3_id',
        'jadwal'     => 'SELECT jdw_kerja_m_id, jdw_kerja_m_name FROM jdw_kerja_m ORDER BY jdw_kerja_m_id',
        'cuti'       => 'SELECT cuti_n_id, cuti_n_nama FROM cuti_normatif ORDER BY cuti_n_id',
        'device'     => 'SELECT sn, device_name FROM device ORDER BY device_name',
    ];
    return q($map[$table])->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ---------- manual scans (Fingerspot "Tidak scan" convention) ----------

function att_id($scanDate, $sn, $pin)
{
    return date('dmYHis', strtotime($scanDate)) . $sn . $pin;
}

function next_izin_seq($pegawaiId, $date, $jenis)
{
    $max = q('SELECT MAX(izin_urutan) FROM izin WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ?',
        [$pegawaiId, $date, $jenis])->fetchColumn();
    return (int)$max + 1;
}

function put_scan_row($pin, $date, $time, $jenis)
{
    $scan = $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time);
    q('INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id)
       VALUES (?, ?, ?, 0, 0, 0, 0, ?)', [(string)$jenis, $scan, $pin, att_id($scan, $jenis, $pin)]);
}

function remove_scan_row($pin, $date, $time, $jenis)
{
    q('DELETE FROM att_log WHERE sn = ? AND pin = ? AND scan_date = ?', [(string)$jenis, $pin, $date . ' ' . $time]);
}

/** Add a forgotten scan the way Fingerspot does it. Returns an error message, or null on success. */
function add_manual_scan($pegawaiId, $date, $time, $jenis, $katId, $note, $approved)
{
    $emp = employee($pegawaiId);
    if (!$emp) return 'Pilih pegawai.';
    if (!valid_date($date)) return 'Isi tanggal yang benar.';
    if (!valid_time($time)) return 'Isi jam dengan format JJ:MM.';
    if (!is_scan_type($jenis)) return 'Pilih jenis scan.';
    if ($date > date('Y-m-d')) return 'Tanggal belum terjadi.';
    $time = substr($time, 0, 5) . ':00';

    $dup = q('SELECT COUNT(*) FROM att_log WHERE pin = ? AND scan_date = ?', [$emp['pegawai_pin'], "$date $time"])->fetchColumn();
    if ($dup) return 'Pegawai sudah punya scan di jam yang sama.';
    $same = q('SELECT COUNT(*) FROM izin WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_noscan_time = ?',
        [$pegawaiId, $date, $jenis, $time])->fetchColumn();
    if ($same) return 'Data ini sudah ada.';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO izin (pegawai_id, izin_urutan, izin_tgl_pengajuan, izin_tgl, izin_jenis_id, izin_catatan, izin_status,
                             cuti_n_id, izin_ket_lain, izin_noscan_time, kat_izin_id, ket_status)
           VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NULL)', [
            $pegawaiId, next_izin_seq($pegawaiId, $date, $jenis), date('Y-m-d'), $date, $jenis, $note,
            $approved ? 1 : 0, 'Web: ' . actor(), $time, (int)$katId,
        ]);
        if ($approved) put_scan_row($emp['pegawai_pin'], $date, $time, $jenis);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('manual_scan', sprintf('%s %s %s %s %s', $emp['pegawai_nama'], scan_types()[$jenis][0], $date, $time,
        $approved ? 'approved' : 'pending'));
    return null;
}

/** Leave rows are keyed by (pegawai_id, izin_tgl, izin_jenis_id, izin_status, izin_urutan). */
function leave_key_from_post()
{
    return [(int)post('pegawai_id'), post('izin_tgl'), (int)post('izin_jenis_id'), (int)post('izin_status'), (int)post('izin_urutan')];
}

function find_leave($key)
{
    return q('SELECT i.*, p.pegawai_pin, p.pegawai_nama FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
              WHERE i.pegawai_id = ? AND i.izin_tgl = ? AND i.izin_jenis_id = ? AND i.izin_status = ? AND i.izin_urutan = ?', $key)->fetch();
}

function set_leave_status($key, $newStatus)
{
    $row = find_leave($key);
    if (!$row || (int)$row['izin_status'] === $newStatus) return;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // The status is part of the primary key, so give the row a fresh sequence number to avoid clashes.
        q('UPDATE izin SET izin_status = ?, izin_urutan = ? WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_status = ? AND izin_urutan = ?',
            array_merge([$newStatus, next_izin_seq($key[0], $key[1], $key[2])], $key));
        if (is_scan_type($row['izin_jenis_id']) && $row['izin_noscan_time']) {
            if ($newStatus === 1) put_scan_row($row['pegawai_pin'], $row['izin_tgl'], $row['izin_noscan_time'], $row['izin_jenis_id']);
            else remove_scan_row($row['pegawai_pin'], $row['izin_tgl'], $row['izin_noscan_time'], $row['izin_jenis_id']);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit($newStatus ? 'leave_approve' : 'leave_reject', $row['pegawai_nama'] . ' ' . $row['izin_tgl'] . ' type ' . $row['izin_jenis_id']);
}

function delete_leave($key)
{
    $row = find_leave($key);
    if (!$row) return;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM izin WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_status = ? AND izin_urutan = ?', $key);
        if (is_scan_type($row['izin_jenis_id']) && $row['izin_noscan_time']) {
            remove_scan_row($row['pegawai_pin'], $row['izin_tgl'], $row['izin_noscan_time'], $row['izin_jenis_id']);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('leave_delete', $row['pegawai_nama'] . ' ' . $row['izin_tgl'] . ' type ' . $row['izin_jenis_id']);
}

/**
 * Change a manually added scan ("Tidak scan" izin 100-105): its date, type, time, category or note.
 * The izin row and its att_log row are replaced together. Returns an error message, or null on success.
 */
function revise_manual_scan($key, $date, $jenis, $time, $katId, $note)
{
    $row = find_leave($key);
    if (!$row || !is_scan_type($row['izin_jenis_id']) || !$row['izin_noscan_time']) return 'Data tidak ditemukan.';
    if (!valid_date($date)) return 'Isi tanggal yang benar.';
    if (!valid_time($time)) return 'Isi jam dengan format JJ:MM.';
    if (!is_scan_type($jenis)) return 'Pilih jenis scan.';
    if ($date > date('Y-m-d')) return 'Tanggal belum terjadi.';
    $time = substr($time, 0, 5) . ':00';
    $pin = $row['pegawai_pin'];
    $oldScan = $row['izin_tgl'] . ' ' . $row['izin_noscan_time'];
    $newScan = "$date $time";

    if ($newScan !== $oldScan || (int)$jenis !== (int)$row['izin_jenis_id']) {
        $dup = q('SELECT COUNT(*) FROM att_log WHERE pin = ? AND scan_date = ? AND NOT (sn = ? AND scan_date = ?)',
            [$pin, $newScan, (string)$row['izin_jenis_id'], $oldScan])->fetchColumn();
        if ($dup) return 'Pegawai sudah punya scan di ' . fmt_date($date) . ' ' . substr($time, 0, 5) . '.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM izin WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_status = ? AND izin_urutan = ?', $key);
        remove_scan_row($pin, $row['izin_tgl'], $row['izin_noscan_time'], $row['izin_jenis_id']);
        q('INSERT INTO izin (pegawai_id, izin_urutan, izin_tgl_pengajuan, izin_tgl, izin_jenis_id, izin_catatan, izin_status,
                             cuti_n_id, izin_ket_lain, izin_noscan_time, kat_izin_id, ket_status)
           VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)', [
            $row['pegawai_id'], next_izin_seq($row['pegawai_id'], $date, $jenis), $row['izin_tgl_pengajuan'], $date, $jenis, $note,
            $row['izin_status'], 'Web: ' . actor(), $time, (int)$katId, $row['ket_status'],
        ]);
        if ((int)$row['izin_status'] === 1) put_scan_row($pin, $date, $time, $jenis);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('manual_scan_revise', sprintf('%s %s %s %s -> %s %s %s', $row['pegawai_nama'], scan_types()[(int)$row['izin_jenis_id']][0],
        $row['izin_tgl'], substr($row['izin_noscan_time'], 0, 5), scan_types()[(int)$jenis][0], $date, substr($time, 0, 5)));
    return null;
}

// ---------- work hours & schedules (Fingerspot rules) ----------
//
// jam_kerja stores times relative to each other. Worked out from Fingerspot's own results (shift_result):
//   start          = jk_bcin + jk_cin minutes        check-in window  = jk_bcin .. start + jk_ecin
//   end            = jk_ecout - jk_cout minutes      check-out window = end - jk_bcout .. jk_ecout
// Late = minutes after start (seconds dropped) when more than jk_tol_late; early = minutes before end when more than jk_tol_early.
// Checked against Fingerspot's own results and reports (Datang Terlambat, Pulang Awal, Lembur, Kartu Shift): identical.

function hm_to_min($t)
{
    $p = explode(':', (string)$t);
    return (int)$p[0] * 60 + (isset($p[1]) ? (int)$p[1] : 0);
}

function min_to_hm($m)
{
    $m = (($m % 1440) + 1440) % 1440;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** jam_kerja rows keyed by jk_id, with the derived times in minutes from midnight. */
function work_hours()
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (q('SELECT * FROM jam_kerja ORDER BY jk_id') as $r) {
            $start = hm_to_min($r['jk_bcin']) + (int)$r['jk_cin'];
            $outClose = hm_to_min($r['jk_ecout']);
            $end = $outClose - (int)$r['jk_cout'];
            while ($end <= $start) { $end += 1440; $outClose += 1440; }
            $r['start'] = $start;
            $r['end'] = $end;
            $r['in_open'] = hm_to_min($r['jk_bcin']);
            $r['in_close'] = $start + (int)$r['jk_ecin'];
            $r['out_open'] = $end - (int)$r['jk_bcout'];
            $r['out_close'] = $outClose;
            $all[(int)$r['jk_id']] = $r;
        }
    }
    return $all;
}

/** Fingerspot-style fields for jam_kerja from plain times (HH:MM) and minutes. */
function work_hours_fields($in)
{
    $start = hm_to_min($in['start']);
    $end = hm_to_min($in['end']);
    if ($end <= $start) $end += 1440;
    $inOpen = hm_to_min($in['in_open']);
    if ($inOpen > $start) $inOpen -= 1440;
    $inClose = hm_to_min($in['in_close']);
    while ($inClose < $start) $inClose += 1440;
    $outOpen = hm_to_min($in['out_open']);
    while ($outOpen > $end) $outOpen -= 1440;
    $outClose = hm_to_min($in['out_close']);
    while ($outClose < $end) $outClose += 1440;
    return [
        'jk_bcin' => min_to_hm($inOpen) . ':00',
        'jk_cin' => $start - $inOpen,
        'jk_ecin' => $inClose - $start,
        'jk_bcout' => $end - $outOpen,
        'jk_cout' => $outClose - $end,
        'jk_ecout' => min_to_hm($outClose) . ':00',
    ];
}

/** Schedule assignments: pegawai_id => [[from date, jdw_kerja_m_id], ...] newest first. */
function schedule_assignments()
{
    static $a = null;
    if ($a === null) {
        $a = [];
        foreach (q('SELECT pegawai_id, jdw_kerja_m_id, jdw_kerja_m_mulai FROM jdw_kerja_pegawai ORDER BY jdw_kerja_m_mulai DESC') as $r) {
            $a[$r['pegawai_id']][] = [$r['jdw_kerja_m_mulai'], (int)$r['jdw_kerja_m_id']];
        }
    }
    return $a;
}

/** Schedules keyed by id: master row plus 'days' => idx => [jk_id, off]. */
function schedules()
{
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach (q('SELECT * FROM jdw_kerja_m ORDER BY jdw_kerja_m_id') as $r) {
            $r['days'] = [];
            $s[(int)$r['jdw_kerja_m_id']] = $r;
        }
        foreach (q('SELECT * FROM jdw_kerja_d WHERE jdw_kerja_d_idx < 999') as $r) {
            if (isset($s[(int)$r['jdw_kerja_m_id']])) {
                $s[(int)$r['jdw_kerja_m_id']]['days'][(int)$r['jdw_kerja_d_idx']] = [(int)$r['jk_id'], (int)$r['jdw_kerja_d_libur'] !== 0];
            }
        }
    }
    return $s;
}

/** The work hours for an employee on a date: [jam_kerja row or null, is day off]. */
/**
 * Shift changes for one employee and date, from Fingerspot's own tables: "Ganti Jam Kerja per Pegawai"
 * (ganti_jk_d, jns_ganti_jk = 2) and "Tukar Jam" (tukar_jam). Returns ['jk' => jk_id, 'off' => bool, 'note' => text]
 * for a changed shift, ['swap' => other pegawai_id] for a swap, or null.
 */
function shift_change_for($pegawaiId, $date)
{
    static $changes = null, $swaps = null;
    if ($changes === null) {
        $changes = $swaps = [];
        foreach (q('SELECT d.pegawai_id, d.tgl_ganti_jk, d.jk_id, d.libur, h.keterangan FROM ganti_jk_d d
                    LEFT JOIN ganti_jk_pegawai h ON h.ganti_jk_id = d.ganti_jk_id WHERE d.jns_ganti_jk = 2') as $r) {
            $changes[$r['pegawai_id']][$r['tgl_ganti_jk']] = ['jk' => (int)$r['jk_id'], 'off' => (int)$r['libur'] !== 0, 'note' => (string)$r['keterangan']];
        }
        foreach (q('SELECT tukar_tgl, pegawai_id1, pegawai_id2 FROM tukar_jam') as $r) {
            $swaps[$r['pegawai_id1']][$r['tukar_tgl']] = (int)$r['pegawai_id2'];
            $swaps[$r['pegawai_id2']][$r['tukar_tgl']] = (int)$r['pegawai_id1'];
        }
    }
    if (isset($changes[$pegawaiId][$date])) return $changes[$pegawaiId][$date];
    if (isset($swaps[$pegawaiId][$date])) return ['swap' => $swaps[$pegawaiId][$date]];
    return null;
}

/** Work hours for an employee on a date: a shift change wins, then a swap (the colleague's scheduled shift), then the schedule. */
function work_hours_for($pegawaiId, $date)
{
    $c = shift_change_for($pegawaiId, $date);
    if ($c && isset($c['swap'])) return scheduled_hours_for($c['swap'], $date);
    if ($c) {
        $wh = work_hours();
        return ($c['off'] || !isset($wh[$c['jk']])) ? [null, true] : [$wh[$c['jk']], false];
    }
    return scheduled_hours_for($pegawaiId, $date);
}

/** Work hours from the employee's work schedule alone: [jam_kerja row or null, is day off]. */
function scheduled_hours_for($pegawaiId, $date)
{
    $assign = schedule_assignments();
    $sched = null;
    if (isset($assign[$pegawaiId])) {
        foreach ($assign[$pegawaiId] as $a) {
            if ($a[0] <= $date) { $sched = $a[1]; break; }
        }
        if ($sched === null) $sched = end($assign[$pegawaiId])[1];
    }
    $all = schedules();
    if ($sched === null || !isset($all[$sched])) return [null, (int)date('N', strtotime($date)) === 7];
    $m = $all[$sched];
    $period = max(1, (int)$m['jdw_kerja_m_periode']);
    $days = (int)floor((strtotime($date) - strtotime($m['jdw_kerja_m_mulai'])) / 86400);
    $idx = (($days % $period) + $period) % $period + 1;
    if (!isset($m['days'][$idx])) return [null, false];
    list($jkId, $off) = $m['days'][$idx];
    $wh = work_hours();
    return [($off || !isset($wh[$jkId])) ? null : $wh[$jkId], $off];
}

// ---------- daily attendance ----------

/**
 * One row per employee per day between $from and $to: check-in, check-out, late / early minutes,
 * worked minutes, approved leave and whether the day is a working day, following the employee's schedule.
 */
function daily_attendance($from, $to, $pegawaiId = null)
{
    global $CONFIG;
    $emps = $pegawaiId ? [employee($pegawaiId)] : employees(true);
    $emps = array_values(array_filter($emps));
    if (!$emps) return [];
    $pins = array_map(function ($e) { return $e['pegawai_pin']; }, $emps);
    $ids = array_map(function ($e) { return $e['pegawai_id']; }, $emps);

    // every scan from $from 00:00 up to the day after $to (check-out windows can run past midnight)
    $scans = [];
    $in = implode(',', array_fill(0, count($pins), '?'));
    $st = q("SELECT pin, scan_date, sn FROM att_log WHERE scan_date >= ? AND scan_date < ? + INTERVAL 2 DAY AND pin IN ($in) ORDER BY scan_date",
        array_merge([$from, $to], $pins));
    foreach ($st as $r) $scans[$r['pin']][] = [strtotime($r['scan_date']), is_scan_type($r['sn'])];

    $leave = [];
    $types = leave_types();
    $in2 = implode(',', array_fill(0, count($ids), '?'));
    $st = q("SELECT pegawai_id, izin_tgl, izin_jenis_id FROM izin
             WHERE izin_status = 1 AND izin_jenis_id NOT BETWEEN 100 AND 105 AND izin_tgl BETWEEN ? AND ? AND pegawai_id IN ($in2)",
        array_merge([$from, $to], $ids));
    foreach ($st as $r) {
        $leave[$r['pegawai_id']][$r['izin_tgl']][] = isset($types[$r['izin_jenis_id']]) ? $types[$r['izin_jenis_id']] : ('Type ' . $r['izin_jenis_id']);
    }

    $holidays = q('SELECT libur_tgl, libur_keterangan FROM libur WHERE libur_tgl BETWEEN ? AND ?', [$from, $to])->fetchAll(PDO::FETCH_KEY_PAIR);
    $midday = hm_to_min(isset($CONFIG['midday']) ? $CONFIG['midday'] : '12:00');

    $rows = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $day0 = strtotime($d);
        foreach ($emps as $e) {
            if (!empty($e['tgl_mulai_kerja']) && $d < $e['tgl_mulai_kerja']) continue;
            list($jk, $schedOff) = work_hours_for($e['pegawai_id'], $d);
            $off = isset($holidays[$d]) ? $holidays[$d] : ($schedOff ? 'Libur' : null);
            if ($off) $jk = null;

            // scans as minutes from this day's midnight (seconds dropped, like Fingerspot)
            $mins = [];
            $manual = 0;
            $hi = $jk ? max(1439, $jk['out_close']) : 1439;
            foreach (isset($scans[$e['pegawai_pin']]) ? $scans[$e['pegawai_pin']] : [] as $s) {
                $m = (int)floor(($s[0] - $day0) / 60);
                if ($m < 0 || $m > $hi) continue;
                $mins[] = $m;
                if ($s[1]) $manual++;
            }

            $inM = $outM = null;
            if ($jk) {
                foreach ($mins as $m) if ($m >= $jk['in_open'] && $m <= $jk['in_close']) { $inM = $m; break; }
                foreach (array_reverse($mins) as $m) if ($m >= $jk['out_open'] && $m <= $jk['out_close'] && $m !== $inM) { $outM = $m; break; }
            } elseif ($mins) {
                $inM = $mins[0];
                if (count($mins) > 1) $outM = end($mins);
            }

            $late = $early = 0;
            $minutes = null;
            if ($jk && $inM !== null && $inM - $jk['start'] > (int)$jk['jk_tol_late']) $late = $inM - $jk['start'];
            if ($jk && $outM !== null && $jk['end'] - $outM > (int)$jk['jk_tol_early']) $early = $jk['end'] - $outM;
            if ($inM !== null && $outM !== null) {
                if ($jk && (int)$jk['jk_durasi'] !== 2) $minutes = ($early ? $outM : $jk['end']) - ($late ? $inM : $jk['start']);
                else $minutes = $outM - $inM;
                $minutes = max(0, $minutes);
            }

            // overtime after work: check-out at least min_eot minutes past the end, capped at max_eot (matches Fingerspot's Lembur)
            $ot = 0;
            if ($jk && (int)$jk['use_eot'] && $outM !== null && $outM - $jk['end'] >= (int)$jk['min_eot']) {
                $ot = min($outM - $jk['end'], (int)$jk['max_eot']);
            }

            $lv = isset($leave[$e['pegawai_id']][$d]) ? $leave[$e['pegawai_id']][$d] : [];
            $missing = null;
            if (!$mins) {
                $status = $lv ? 'leave' : ($off ? 'off' : 'absent');
            } elseif ($inM !== null && $outM !== null) {
                $status = 'ok';
            } else {
                $status = $lv ? 'leave' : 'incomplete';
                if ($inM === null && $outM === null) $missing = $mins[0] < ($jk ? $jk['start'] + ($jk['end'] - $jk['start']) / 2 : $midday) ? 101 : 100;
                else $missing = $inM === null ? 100 : 101;
            }
            $rows[] = [
                'date' => $d, 'pegawai_id' => $e['pegawai_id'], 'pin' => $e['pegawai_pin'], 'name' => $e['pegawai_nama'],
                'jk' => $jk ? $jk['jk_name'] : null,
                'first' => $inM !== null ? min_to_hm($inM) : (($outM === null && $mins) ? min_to_hm($mins[0]) : null),
                'last' => $outM !== null ? min_to_hm($outM) : null,
                'late' => $late, 'early' => $early, 'ot' => $ot, 'minutes' => $minutes,
                'scans' => count($mins), 'manual' => $manual,
                'leave' => $lv, 'off' => $off, 'status' => $status, 'missing' => $missing,
            ];
        }
    }
    return $rows;
}

function status_badge($status)
{
    $labels = ['ok' => 'Hadir', 'incomplete' => 'Scan 1x', 'absent' => 'Tidak hadir', 'leave' => 'Izin / cuti', 'off' => 'Libur'];
    return '<span class="badge b-' . h($status) . '">' . h($labels[$status]) . '</span>';
}
