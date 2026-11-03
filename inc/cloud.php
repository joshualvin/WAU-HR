<?php
// Download scans from Fingerspot.io into att_log. Uses the Fingerspot Developer API (the one Fingerspot Personnel
// uses), which needs no per-machine "API SDK" subscription:
//   POST https://developer.fingerspot.io/api/get_attlog   Authorization: Bearer <token>
//   body {trans_id, cloud_id, start_date, end_date}  — up to 60 days back, at most 2 days per request.
//   reply {success, data:[{pin, scan_date, verify, status_scan, ...}]}.
// Token: 'fingerspot_api_key' in config.php = the "API Key untuk Fingerspot Personnel" on Fingerspot.io
// (the older name 'fingerspot_token' is read too). It is never shown in the app.
// The old per-machine API SDK (api.fingerspot.io, MD5 auth) is kept below in cloud_url()/cloud_get_attlog() for
// reference; the active path is the Developer API. Attendance can also be imported from the .xlsx Fingerspot.io
// exports (Laporan → Absensi Perangkat) via cloud_import_xlsx().

const CLOUD_GPS_SN = 'FIO';

function cloud_token()
{
    global $CONFIG;
    foreach (['fingerspot_api_key', 'fingerspot_token'] as $k) if (!empty($CONFIG[$k])) return trim($CONFIG[$k]);
    return '';
}

function cloud_base()
{
    global $CONFIG;
    return rtrim(!empty($CONFIG['fingerspot_url']) ? $CONFIG['fingerspot_url'] : 'https://api.fingerspot.io/api/download/attendance_log', '/');
}

function ensure_cloud_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_gps_scan (
        pin VARCHAR(32) NOT NULL, scan_date DATETIME NOT NULL, type_id TINYINT NOT NULL DEFAULT 0, type VARCHAR(60) NOT NULL DEFAULT '',
        lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL, address VARCHAR(255) NOT NULL DEFAULT '', phone VARCHAR(60) NOT NULL DEFAULT '',
        location VARCHAR(100) NOT NULL DEFAULT '', PRIMARY KEY (pin, scan_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Machines that have a cloud ID. */
function cloud_devices()
{
    return q("SELECT d.sn, d.device_name, d.cloud_id, d.last_download_web,
                     (SELECT MAX(scan_date) FROM att_log a WHERE a.sn = d.sn) last_scan
              FROM device d WHERE d.cloud_id <> '' ORDER BY d.device_name")->fetchAll();
}

/** The request URL for one machine (or '0-KEY' for FiO GPS scans) and upload day; $now = YmdHis. */
function cloud_url($cloudId, $uploadDate, $key, $now)
{
    $auth = md5($cloudId . $uploadDate . $now . $key);
    return cloud_base() . '/' . rawurlencode($cloudId) . "/$uploadDate/6/date_time/asc/json/$auth/$now";
}

function cloud_error_text($code)
{
    $t = ['IO_API_ERR_1' => 'mesin (Cloud ID) tidak ditemukan', 'IO_API_ERR_2' => 'akun tidak ditemukan', 'IO_API_ERR_3' => 'kode auth salah (periksa API key di config.php)',
          'IO_API_ERR_4' => 'akun melewati masa aktif (due date)', 'IO_API_ERR_5' => 'mesin belum berlangganan API SDK', 'IO_API_ERR_6' => 'belum berlangganan AddOn API SDK scan GPS',
          'IO_API_ERR_7' => 'sudah mencapai batas 100 request API per hari'];
    return isset($t[$code]) ? "$code: {$t[$code]}" : $code;
}

/** Normalise one API row: [pin, scan_date Y-m-d H:i:s, verify, type_id, ...gps fields] or null. */
function cloud_row($r)
{
    $pin = isset($r['PIN']) ? trim((string)$r['PIN']) : '';
    $dt = isset($r['Date Time']) ? (string)$r['Date Time'] : '';
    if ($pin === '' || !strtotime($dt)) return null;
    return ['pin' => $pin, 'scan_date' => date('Y-m-d H:i:s', strtotime($dt)), 'verify' => isset($r['Verify']) ? (int)$r['Verify'] : 1,
            'type_id' => isset($r['Type ID']) ? (int)$r['Type ID'] : 0, 'type' => isset($r['Type']) ? (string)$r['Type'] : '',
            'lat' => isset($r['Latitude']) && is_numeric($r['Latitude']) ? (float)$r['Latitude'] : null, 'lng' => isset($r['Longitude']) && is_numeric($r['Longitude']) ? (float)$r['Longitude'] : null,
            'address' => isset($r['Address']) ? (string)$r['Address'] : '', 'phone' => isset($r['Phone']) ? (string)$r['Phone'] : '', 'location' => isset($r['Location']) ? (string)$r['Location'] : ''];
}

/** One API SDK call. Returns [rows, error]. */
function cloud_get_attlog($cloudId, $uploadDate)
{
    $ch = curl_init(cloud_url($cloudId, $uploadDate, cloud_token(), date('YmdHis')));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [[], 'Tidak bisa terhubung ke api.fingerspot.io: ' . $err];
    $json = json_decode($raw, true);
    if (!is_array($json)) return [[], "HTTP $http: " . substr(strip_tags($raw), 0, 150)];
    if (empty($json['success'])) return [[], cloud_error_text(isset($json['msg']) ? (string)$json['msg'] : 'success = false')];
    $rows = [];
    foreach (isset($json['data']) && is_array($json['data']) ? $json['data'] : [] as $r) if ($row = cloud_row($r)) $rows[] = $row;
    return [$rows, null];
}

// ---------- Developer API (active path) ----------

/** The Developer API get_attlog endpoint, used with the Personnel Bearer token. */
function cloud_dev_url()
{
    global $CONFIG;
    return !empty($CONFIG['fingerspot_dev_url']) ? $CONFIG['fingerspot_dev_url'] : 'https://developer.fingerspot.io/api/get_attlog';
}

/** Normalise one Developer API row ({pin, scan_date, verify, status_scan}) to what cloud_download inserts, or null. */
function cloud_row_dev($r)
{
    $pin = isset($r['pin']) ? trim((string)$r['pin']) : '';
    $dt = isset($r['scan_date']) ? (string)$r['scan_date'] : '';
    if ($pin === '' || !strtotime($dt)) return null;
    return ['pin' => $pin, 'scan_date' => date('Y-m-d H:i:s', strtotime($dt)),
            'verify' => isset($r['verify']) ? (int)$r['verify'] : 1, 'type_id' => isset($r['status_scan']) ? (int)$r['status_scan'] : 0];
}

/** One Developer API get_attlog call for a device, window $start..$end (at most 2 days). Returns [rows, error]. */
function cloud_get_attlog_dev($cloudId, $start, $end)
{
    $ch = curl_init(cloud_dev_url());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cloud_token(), 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['trans_id' => (string)mt_rand(1, 2000000000), 'cloud_id' => $cloudId, 'start_date' => $start, 'end_date' => $end])]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [[], 'Tidak bisa terhubung ke developer.fingerspot.io: ' . $err];
    $json = json_decode($raw, true);
    if (!is_array($json)) return [[], "HTTP $http: " . substr(strip_tags($raw), 0, 150)];
    if (empty($json['success'])) {
        $msg = isset($json['message']) ? (string)$json['message'] : (isset($json['msg']) ? (string)$json['msg'] : "gagal (HTTP $http)");
        return [[], ($http === 401 ? 'token salah/kadaluarsa — ' : '') . 'Developer API: ' . $msg];
    }
    $rows = [];
    foreach (isset($json['data']) && is_array($json['data']) ? $json['data'] : [] as $r) if ($row = cloud_row_dev($r)) $rows[] = $row;
    return [$rows, null];
}

/** Scans per [machine sn => ...] that reached Fingerspot.io on $from..$to, pulled via the Developer API. */
function cloud_download($from, $to, $sns = [], $gps = false)
{
    @set_time_limit(600);
    ensure_cloud_tables();
    $known = array_flip(q('SELECT pegawai_pin FROM pegawai')->fetchAll(PDO::FETCH_COLUMN));
    $ins = db()->prepare('INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id) VALUES (?, ?, ?, ?, ?, 0, 0, 0)');
    $targets = [];
    // Cloud IDs are upper-case hex on Fingerspot.io
    foreach (cloud_devices() as $d) if (!$sns || in_array($d['sn'], $sns, true)) $targets[$d['sn']] = [strtoupper(trim($d['cloud_id'])), $d['device_name']];
    $out = [];
    foreach ($targets as $sn => $t) {
        $res = ['name' => $t[1], 'received' => 0, 'added' => 0, 'unknown_pins' => [], 'errors' => []];
        for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +2 day'))) {   // 2-day windows (API limit)
            $end = min($to, date('Y-m-d', strtotime($day . ' +1 day')));
            list($rows, $err) = cloud_get_attlog_dev($t[0], $day, $end);
            if ($err) {
                $res['errors'][] = "$day: $err";
                if (preg_match('/HTTP 40|token/i', $err)) break;   // auth/quota: later windows will fail too
                continue;
            }
            foreach ($rows as $r) {
                $res['received']++;
                $ins->execute([$sn, $r['scan_date'], $r['pin'], $r['verify'], $r['type_id']]);
                $res['added'] += $ins->rowCount();
                if (!isset($known[$r['pin']])) $res['unknown_pins'][$r['pin']] = true;
            }
        }
        if (!$res['errors'] || $res['received']) q('UPDATE device SET last_download_web = ? WHERE sn = ?', [date('Y-m-d'), $sn]);
        $res['unknown_pins'] = array_keys($res['unknown_pins']);
        $out[$sn] = $res;
    }
    // GPS scans from the FiO app are not part of the Developer API get_attlog; see the Absensi GPS report on Fingerspot.io.
    return $out;
}

// ---------- Import from a Fingerspot.io .xlsx export (Laporan → Absensi Perangkat / Absensi GPS) ----------

/** Read the first worksheet of an .xlsx into rows of [column letter => cell text]. No external library. */
function xlsx_rows($path)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new Exception('File bukan .xlsx yang valid.');
    $shared = [];
    if (($s = $zip->getFromName('xl/sharedStrings.xml')) !== false && ($sx = @simplexml_load_string($s)))
        foreach ($sx->si as $si) { $t = ''; foreach ($si->xpath('.//*[local-name()="t"]') as $n) $t .= (string)$n; $shared[] = $t; }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false || !($xml = @simplexml_load_string($sheet))) throw new Exception('Sheet tidak terbaca.');
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string)$c['r']);
            if ((string)$c['t'] === 's') $cells[$col] = isset($shared[(int)$c->v]) ? $shared[(int)$c->v] : '';
            elseif ((string)$c['t'] === 'inlineStr') $cells[$col] = (string)$c->is->t;
            else $cells[$col] = (string)$c->v;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** Convert an Excel serial date, or pass a string through, to 'Y-m-d H:i:s' or '' when unparseable. */
function xlsx_datetime($v)
{
    $v = trim($v);
    if ($v === '') return '';
    if (is_numeric($v)) return date('Y-m-d H:i:s', (int)round(((float)$v - 25569) * 86400));   // Excel epoch 1899-12-30 → Unix
    return ($t = strtotime($v)) ? date('Y-m-d H:i:s', $t) : '';
}

/** Map a Fingerspot status/type cell to inoutmode (0 in, 1 out, 2 break in, 3 break out); numeric passes through. */
function xlsx_status($v)
{
    $v = strtolower(trim($v));
    if ($v === '' ) return 0;
    if (is_numeric($v)) return (int)$v;
    if (strpos($v, 'mulai istirahat') !== false || strpos($v, 'break in') !== false) return 2;
    if (strpos($v, 'selesai istirahat') !== false || strpos($v, 'break out') !== false) return 3;
    if (strpos($v, 'pulang') !== false || strpos($v, 'out') !== false) return 1;
    return 0;   // masuk / scan in / anything else
}

/**
 * Import attendance from a Fingerspot.io .xlsx export into att_log. Matches columns by header name (English or
 * Indonesian, combined "Time Scan" or split "Tanggal Absensi" + "Jam Absensi"). Returns a cloud_download-style result.
 */
function cloud_import_xlsx($path)
{
    $rows = xlsx_rows($path);
    // find the header row: the first with a cell containing 'cloud' or exactly 'pin'/'id'
    $h = -1;
    foreach ($rows as $i => $r) { $j = implode('|', array_map('strtolower', $r)); if (strpos($j, 'cloud') !== false || preg_match('/\bpin\b/', $j)) { $h = $i; break; } }
    if ($h < 0) throw new Exception('Baris judul tidak ditemukan (cari kolom Cloud ID / PIN).');
    $col = [];
    foreach ($rows[$h] as $l => $text) {
        $t = strtolower(trim($text));
        if (strpos($t, 'cloud') !== false) $col['cloud'] = $l;
        elseif ($t === 'pin' || $t === 'id') $col['pin'] = $l;
        elseif (strpos($t, 'jam') !== false) $col['time'] = $l;                                   // split time column
        elseif (strpos($t, 'time') !== false || strpos($t, 'tanggal absensi') !== false || $t === 'scan') $col['date'] = $l;
        elseif (strpos($t, 'veri') !== false) $col['verify'] = $l;
        elseif ($t === 'status' || strpos($t, 'tipe') !== false) $col['status'] = $l;
    }
    foreach (['cloud', 'pin', 'date'] as $need) if (!isset($col[$need])) throw new Exception('Kolom wajib tidak ada: Cloud ID, PIN, dan tanggal scan.');
    $map = q("SELECT UPPER(cloud_id) c, sn FROM device WHERE cloud_id <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
    $known = array_flip(q('SELECT pegawai_pin FROM pegawai')->fetchAll(PDO::FETCH_COLUMN));
    $ins = db()->prepare('INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id) VALUES (?, ?, ?, ?, ?, 0, 0, 0)');
    $res = ['name' => basename($path), 'received' => 0, 'added' => 0, 'unknown_pins' => [], 'unknown_cloud' => [], 'errors' => []];
    foreach (array_slice($rows, $h + 1) as $r) {
        $cloud = strtoupper(trim(isset($r[$col['cloud']]) ? $r[$col['cloud']] : ''));
        $pin = trim(isset($r[$col['pin']]) ? $r[$col['pin']] : '');
        if ($cloud === '' && $pin === '') continue;   // blank row
        $date = isset($r[$col['date']]) ? $r[$col['date']] : '';
        $time = isset($col['time'], $r[$col['time']]) ? $r[$col['time']] : '';
        $when = xlsx_datetime(trim($date . ' ' . $time));
        if (!isset($map[$cloud])) { $res['unknown_cloud'][$cloud] = true; continue; }
        if ($pin === '' || $when === '') continue;
        $res['received']++;
        $ins->execute([$map[$cloud], $when, $pin, isset($col['verify']) && is_numeric($r[$col['verify']] ?? '') ? (int)$r[$col['verify']] : 1,
                       xlsx_status(isset($col['status']) ? ($r[$col['status']] ?? '') : '')]);
        $res['added'] += $ins->rowCount();
        if (!isset($known[$pin])) $res['unknown_pins'][$pin] = true;
    }
    $res['unknown_pins'] = array_keys($res['unknown_pins']);
    $res['unknown_cloud'] = array_keys(array_filter($res['unknown_cloud'], 'strlen'));
    return $res;
}

// ---------- GPS scans and geofences ----------

function ensure_geofence_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_geofence (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, lat DECIMAL(10,7) NOT NULL, lng DECIMAL(10,7) NOT NULL, radius_m INT NOT NULL DEFAULT 150
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Distance in metres between two points (haversine). */
function distance_m($lat1, $lng1, $lat2, $lng2)
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int)round(2 * $r * asin(min(1, sqrt($a))));
}

/** The nearest geofence of a point: [geofence row, distance m, inside] or null without geofences / location. */
function nearest_geofence($lat, $lng, $fences)
{
    if ($lat === null || $lng === null || !$fences) return null;
    $best = null;
    foreach ($fences as $f) {
        $d = distance_m((float)$lat, (float)$lng, (float)$f['lat'], (float)$f['lng']);
        if (!$best || $d < $best[1]) $best = [$f, $d, $d <= (int)$f['radius_m']];
    }
    return $best;
}
