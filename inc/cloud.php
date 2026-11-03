<?php
// Download scans from Fingerspot.io with its API SDK (Quick Guide API SDK Fingerspot.io) into att_log.
//   GET https://api.fingerspot.io/api/download/attendance_log/{cloud_id}/{upload_date}/6/date_time/asc/json/{auth}/{now}
//   auth = MD5(cloud_id . upload_date . now . API key), now = YmdHis. upload_date is the day the scans REACHED
//   Fingerspot.io (they may be older), one request per machine per day, at most 100 requests a day per account.
// Machine scans: sn = machine serial. GPS scans from the FiO phone app (cloud_id "0-" . API key, AddOn "API SDK scan
// GPS App FiO"): sn = 'FIO', with location, address and phone kept in web_gps_scan.
// API key: 'fingerspot_api_key' in config.php (Fingerspot.io → Pengaturan → Pengaturan Umum → API SDK → API Key);
// the older name 'fingerspot_token' is read too. It is never shown in the app.

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

/** Scans per [machine sn => ...] that reached Fingerspot.io on $from..$to (one request per machine per day). */
function cloud_download($from, $to, $sns = [], $gps = false)
{
    @set_time_limit(600);
    ensure_cloud_tables();
    $known = array_flip(q('SELECT pegawai_pin FROM pegawai')->fetchAll(PDO::FETCH_COLUMN));
    $ins = db()->prepare('INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id) VALUES (?, ?, ?, ?, ?, 0, 0, ?)');
    $gpsIns = db()->prepare('REPLACE INTO web_gps_scan (pin, scan_date, type_id, type, lat, lng, address, phone, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $targets = [];
    // Cloud IDs are upper-case hex on Fingerspot.io; the auth code is case-sensitive
    foreach (cloud_devices() as $d) if (!$sns || in_array($d['sn'], $sns, true)) $targets[$d['sn']] = [strtoupper(trim($d['cloud_id'])), $d['device_name']];
    if ($gps) $targets[CLOUD_GPS_SN] = ['0-' . cloud_token(), 'Scan GPS App FiO'];
    $out = [];
    foreach ($targets as $sn => $t) {
        $res = ['name' => $t[1], 'received' => 0, 'added' => 0, 'unknown_pins' => [], 'errors' => []];
        for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            list($rows, $err) = cloud_get_attlog($t[0], $day);
            if ($err) {
                $res['errors'][] = "$day: $err";
                if (preg_match('/IO_API_ERR_[1-7]|HTTP 40/', $err)) break;   // will not get better on the next day
                continue;
            }
            foreach ($rows as $r) {
                $res['received']++;
                $ins->execute([$sn, $r['scan_date'], $r['pin'], $r['verify'], $r['type_id'], $sn === CLOUD_GPS_SN ? 'fio' : '0']);
                $res['added'] += $ins->rowCount();
                if ($sn === CLOUD_GPS_SN) $gpsIns->execute([$r['pin'], $r['scan_date'], $r['type_id'], mb_substr($r['type'], 0, 60), $r['lat'], $r['lng'], mb_substr($r['address'], 0, 255), mb_substr($r['phone'], 0, 60), mb_substr($r['location'], 0, 100)]);
                if (!isset($known[$r['pin']])) $res['unknown_pins'][$r['pin']] = true;
            }
        }
        if ($sn !== CLOUD_GPS_SN && (!$res['errors'] || $res['received'])) q('UPDATE device SET last_download_web = ? WHERE sn = ?', [date('Y-m-d'), $sn]);
        $res['unknown_pins'] = array_keys($res['unknown_pins']);
        $out[$sn] = $res;
    }
    return $out;
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
