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
const CLOUD_DS_LIMIT = 5000;   // rows per day and machine asked from the desktop API

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

// ---------- Fingerspot Personnel desktop API (active path, free) ----------
// The endpoint the Fingerspot Personnel desktop app itself uses (captured from its traffic), so it needs no API SDK
// subscription and no paid Developer API — only the free Personnel API key. Every parameter is a path segment, GET:
//   {base}/{apikey}/{cloud_id}/{date}/{req_ts}/{token}/3/0/{limit}   date = Y-m-d (the day the scans reached the cloud),
//   req_ts = YmdHis, 3 = comm type "web", 0 = offset(?), limit = max rows: 0 returns nothing, 1 one scan (confirmed with
//   cloud.php?diag=att). token = MD5(cloud_id . date . req_ts . apikey) (confirmed with cloud.php?probe).
// Reply {success, total, data:[{PIN, Name, "Date Time", "Log Type", "Verify Type", "Work Code", "IO Mode"}]}.

/** Base of the Personnel desktop API attendance_log endpoint. */
function cloud_ds_base()
{
    global $CONFIG;
    return rtrim(!empty($CONFIG['fingerspot_ds_url']) ? $CONFIG['fingerspot_ds_url'] : 'https://api-ds.fingerspot.io/api/desktop/attendance_log', '/');
}

/** Scheme + host of the desktop API, e.g. https://api-ds.fingerspot.io */
function cloud_ds_origin()
{
    return preg_replace('#^(https?://[^/]+).*$#', '$1', cloud_ds_base());
}

/** The per-request signature for one device and day (recipe confirmed with cloud.php?probe). */
function cloud_ds_token($cloudId, $date, $key, $now)
{
    return md5($cloudId . $date . $now . $key);
}

/** The request URL for one machine and day; $now = YmdHis. */
function cloud_ds_url($cloudId, $date, $key, $now, $tail = null)
{
    if ($tail === null) $tail = '3/0/' . CLOUD_DS_LIMIT;
    return cloud_ds_base() . '/' . rawurlencode($key) . '/' . rawurlencode($cloudId) . "/$date/$now/" . cloud_ds_token($cloudId, $date, $key, $now) . '/' . $tail;
}

/** Diagnostic: the reply of api-ds to each plausible layout x token recipe, [label => message]. A reply that is neither 404 ({"message":""}) nor Access Denied is a hit. */
function cloud_ds_probe($cloudId, $date)
{
    $key = cloud_token();
    $now = date('YmdHis');
    $sn = (string)q('SELECT sn FROM device WHERE UPPER(cloud_id) = ?', [strtoupper($cloudId)])->fetchColumn();
    $tokens = ['c.k' => $cloudId . $key, 'c.d.k' => $cloudId . $date . $key, 'c.k.d' => $cloudId . $key . $date, 'k.c' => $key . $cloudId,
               'c.n.k' => $cloudId . $now . $key, 'c.d.n.k' => $cloudId . $date . $now . $key, 'k.c.d' => $key . $cloudId . $date, 'c.sn.k' => $cloudId . $sn . $key];
    $tails = ['3/0/' . $sn, '3/' . $sn . '/0', $sn . '/3/0', '3/0/0', '3/0/1', '3/0/3'];
    $out = [];
    foreach ($tails as $tail) foreach ($tokens as $tn => $raw) {
        $ch = curl_init(cloud_ds_base() . '/' . rawurlencode($key) . '/' . rawurlencode($cloudId) . "/$date/$now/" . md5($raw) . '/' . $tail);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $reply = (string)curl_exec($ch);
        curl_close($ch);
        $out[str_replace($sn, 'SN', $tail) . '  md5(' . $tn . ')'] = substr(str_replace($key, '***', preg_replace('/\s+/', ' ', $reply)), 0, 110);
    }
    return $out;
}

/** Diagnostic for ?diag=1: what date_list (both date orders) and attendance_log answer for each machine today, no key and no names shown. */
function cloud_ds_diag()
{
    $today = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-3 days'));
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $key = rawurlencode(cloud_token());
    $lines = ["today $today"];
    foreach (cloud_devices() as $d) {
        $cid = strtoupper(trim($d['cloud_id']));
        $lines[] = "== {$d['device_name']} ($cid)";
        foreach (["$from/$today" => 'date_list from/today', "$today/$from" => 'date_list today/from', "$from/$tomorrow" => 'date_list from/tomorrow', "$today/$today" => 'date_list today/today'] as $range => $label) {
            list($j, $e) = cloud_ds_get(cloud_ds_origin() . "/api/desktop/date_list/$key/" . rawurlencode($cid) . "/$range");
            $lines[] = str_pad($label, 26) . ($e ? $e : implode(',', array_map(function ($r) { return isset($r['date']) ? substr($r['date'], 5) : '?'; }, isset($j['data']) ? $j['data'] : [])) . '  (' . (isset($j['data']) ? count($j['data']) : 0) . ' days)');
        }
        foreach ([$today, date('Y-m-d', strtotime('-1 day'))] as $day) {
            list($rows, $e) = cloud_get_attlog_ds($cid, $day);
            $max = $rows ? max(array_column($rows, 'scan_date')) : '';
            $lines[] = str_pad("attendance_log $day", 26) . ($e ? $e : count($rows) . ' scans' . ($max ? ", latest $max" : ''));
        }
    }
    return $lines;
}

/** Diagnostic for ?diag=att: attendance_log scan counts per machine, day and trailing path values (no key, no names). */
function cloud_ds_diag_att()
{
    $lines = [];
    $days = [date('Y-m-d', strtotime('-1 day')), date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))];
    foreach (cloud_devices() as $d) {
        if (!preg_match('/Kantor|AF2/i', $d['device_name'])) continue;
        $cid = strtoupper(trim($d['cloud_id']));
        $lines[] = "== {$d['device_name']} ($cid)";
        foreach (['3/0/0', '3/0/1', '3/0/' . $d['sn'], '3/0/' . CLOUD_DS_LIMIT] as $tail) {
            $row = [];
            foreach ($days as $day) {
                list($rows, $e) = cloud_get_attlog_ds($cid, $day, $tail);
                $row[] = "$day=" . ($e ? 'ERR' : count($rows) . ($rows ? ' (latest ' . substr(max(array_column($rows, 'scan_date')), 5, 11) . ')' : ''));
            }
            $lines[] = str_pad(str_replace($d['sn'], 'SN', $tail), 8) . implode('  ', $row);
        }
    }
    return $lines;
}

/** Normalise one reply row to what cloud_download inserts, or null. Accepts "Verify"/"Type ID" or "Verify Type"/"Log Type". */
function cloud_row_ds($r)
{
    $pin = isset($r['PIN']) ? trim((string)$r['PIN']) : '';
    $dt = isset($r['Date Time']) ? (string)$r['Date Time'] : '';
    if ($pin === '' || !strtotime($dt)) return null;
    $pick = function ($keys, $def) use ($r) { foreach ($keys as $k) if (isset($r[$k]) && is_numeric($r[$k])) return (int)$r[$k]; return $def; };
    return ['pin' => $pin, 'scan_date' => date('Y-m-d H:i:s', strtotime($dt)), 'verify' => $pick(['Verify', 'Verify Type'], 1),
            'type_id' => $pick(['Type ID', 'Log Type'], 0), 'work_code' => $pick(['Work Code'], 0)];
}

/** Seconds between two rounds of requests: Fingerspot allows one burst a minute for the whole account. */
function cloud_min_interval()
{
    global $CONFIG;
    return isset($CONFIG['fingerspot_min_interval']) ? max(0, (int)$CONFIG['fingerspot_min_interval']) : 61;
}

/**
 * Wait until the minimum interval passed since the last round by anyone on this server (web page and scheduled run share
 * the time kept in a temp file), then note the new round. All machines are asked together in one round (cloud_ds_multi), so
 * three machines cost one wait, not three. shortcut: two processes starting in the same second can both pass; the scheduled
 * script's lock keeps that to a web click during a scheduled run.
 */
function cloud_throttle()
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wauhr_cloud_last';
    $last = is_file($file) ? (int)@file_get_contents($file) : 0;
    $wait = $last + cloud_min_interval() - time();
    if ($wait > 0) sleep($wait);
    @file_put_contents($file, (string)time());
}

/** Every day from $from to $to (Y-m-d). */
function cloud_days($from, $to)
{
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $out[] = $d;
    return $out;
}

/** Turn one reply into [json array, error]. */
function cloud_ds_decode($raw, $http, $err)
{
    if ($err !== '' || $raw === null || $raw === false) return [null, 'Tidak bisa terhubung ke api-ds.fingerspot.io: ' . $err];
    $json = json_decode($raw, true);
    if (!is_array($json)) return [null, "HTTP $http: " . substr(strip_tags($raw), 0, 120)];
    if (empty($json['success'])) return [null, 'Fingerspot: ' . (isset($json['msg']) ? (string)$json['msg'] : substr(preg_replace('/\s+/', ' ', $raw), 0, 100)) . ' (kunci API salah atau permintaan tidak dikenal)'];
    return [$json, null];
}

/** GET several desktop-API URLs at the same moment (one round). Returns [key => [json array|null, error|null]]. */
function cloud_ds_multi($urls)
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($handles as $k => $ch) {
        $out[$k] = cloud_ds_decode(curl_multi_getcontent($ch), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** GET one desktop-API URL. Returns [json array, error]. */
function cloud_ds_get($url)
{
    $r = cloud_ds_multi(['x' => $url]);
    return $r['x'];
}

/** The day-list URL of a device: date_list/{key}/{cloud}/{from}/{to+1} (the end day is exclusive on the server). */
function cloud_ds_dates_url($cloudId, $from, $to)
{
    return cloud_ds_origin() . '/api/desktop/date_list/' . rawurlencode(cloud_token()) . '/' . rawurlencode($cloudId) . "/$from/" . date('Y-m-d', strtotime($to . ' +1 day'));
}

/** The days listed in a date_list reply. */
function cloud_ds_dates($json)
{
    $dates = [];
    foreach (isset($json['data']) && is_array($json['data']) ? $json['data'] : [] as $r) if (isset($r['date'])) $dates[] = substr((string)$r['date'], 0, 10);
    return $dates;
}

/** Rows of an attendance_log reply, and a warning when the day filled the row limit: [rows, warning|null]. */
function cloud_ds_rows($json)
{
    $rows = [];
    foreach (isset($json['data']) && is_array($json['data']) ? $json['data'] : [] as $r) if ($row = cloud_row_ds($r)) $rows[] = $row;
    return [$rows, count($rows) >= CLOUD_DS_LIMIT ? 'mungkin ada scan lebih dari ' . CLOUD_DS_LIMIT . ' pada hari ini' : null];
}

/** One desktop-API attendance_log call for a device and upload date (Y-m-d). Returns [rows, error]. */
function cloud_get_attlog_ds($cloudId, $date, $tail = null)
{
    list($json, $err) = cloud_ds_get(cloud_ds_url($cloudId, $date, cloud_token(), date('YmdHis'), $tail));
    if ($err) return [[], $err];
    return cloud_ds_rows($json);
}

/**
 * Scans per [machine sn => result] that reached Fingerspot.io on $from..$to (never later than today). Fingerspot allows one
 * burst of requests a minute for the account: every machine is asked together in one round (one day per machine per round),
 * rounds are 61 s apart (cloud_throttle). Up to 3 days are fetched day by day; a longer range first asks date_list (one round)
 * for the days that have data. With $budget seconds (web page) it stops when the time is up and reports the days left; run it
 * again to continue. last_download_web only moves up to the last day covered (cloud_mark_downloaded), only for a machine that
 * finished without errors, so a partial run never makes the next one skip data.
 */
function cloud_download($from, $to, $sns = [], $gps = false, $budget = 0)
{
    @set_time_limit(0);
    ensure_cloud_tables();
    $started = time();
    $to = min($to, date('Y-m-d'));   // no future dates
    $known = array_flip(q('SELECT pegawai_pin FROM pegawai')->fetchAll(PDO::FETCH_COLUMN));
    $ins = db()->prepare('INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id) VALUES (?, ?, ?, ?, ?, 0, ?, 0)');
    $out = [];
    $cids = [];    // sn => cloud id
    $queue = [];   // sn => days still to fetch
    if ($from > $to) return $out;
    // Cloud IDs are upper-case hex on Fingerspot.io
    foreach (cloud_devices() as $d) {
        if ($sns && !in_array($d['sn'], $sns, true)) continue;
        $cids[$d['sn']] = strtoupper(trim($d['cloud_id']));
        $out[$d['sn']] = ['name' => $d['device_name'], 'received' => 0, 'added' => 0, 'unknown_pins' => [], 'errors' => [], 'complete' => true];
    }
    $days = cloud_days($from, $to);
    if (count($days) <= 3) foreach ($cids as $sn => $cid) $queue[$sn] = $days;
    elseif ($cids) {
        cloud_throttle();
        $urls = [];
        foreach ($cids as $sn => $cid) $urls[$sn] = cloud_ds_dates_url($cid, $from, $to);
        foreach (cloud_ds_multi($urls) as $sn => $r) {
            if ($r[1]) { $out[$sn]['errors'][] = $r[1]; $out[$sn]['complete'] = false; continue; }
            $found = array_values(array_filter(cloud_ds_dates($r[0]), function ($x) use ($to) { return $x <= $to; }));
            if ($found) $queue[$sn] = $found;
        }
    }
    while ($queue) {
        if ($budget && time() - $started >= $budget) {
            foreach ($queue as $sn => $left) { $out[$sn]['errors'][] = 'Belum selesai (batas waktu): ' . implode(', ', $left) . ' — klik Download lagi untuk melanjutkan'; $out[$sn]['complete'] = false; }
            break;
        }
        cloud_throttle();
        $now = date('YmdHis');
        $urls = $round = [];
        foreach ($queue as $sn => $left) { $round[$sn] = array_shift($queue[$sn]); $urls[$sn] = cloud_ds_url($cids[$sn], $round[$sn], cloud_token(), $now); }
        foreach (cloud_ds_multi($urls) as $sn => $r) {
            $day = $round[$sn];
            if ($r[1]) {
                $out[$sn]['errors'][] = "$day: " . $r[1];
                $out[$sn]['complete'] = false;
                if (preg_match('/Access Denied|HTTP 40/i', $r[1])) $queue[$sn] = [];   // will not get better on the next day
                $rows = [];
            } else {
                list($rows, $warn) = cloud_ds_rows($r[0]);
                if ($warn) { $out[$sn]['errors'][] = "$day: $warn"; $out[$sn]['complete'] = false; }
            }
            foreach ($rows as $row) {
                $out[$sn]['received']++;
                $ins->execute([$sn, $row['scan_date'], $row['pin'], $row['verify'], $row['type_id'], $row['work_code']]);
                $out[$sn]['added'] += $ins->rowCount();
                if (!isset($known[$row['pin']])) $out[$sn]['unknown_pins'][$row['pin']] = true;
            }
        }
        $queue = array_filter($queue);
    }
    foreach ($out as $sn => $res) {
        if ($res['complete']) cloud_mark_downloaded($sn, $from, $to);
        $out[$sn]['unknown_pins'] = array_keys($res['unknown_pins']);
    }
    return $out;
}

/**
 * Note that a machine's cloud scans are covered through $to. Only when this run joins the days already covered
 * (last_download_web >= the day before $from); a quick "today" run must not hide an earlier gap from the next full run.
 */
function cloud_mark_downloaded($sn, $from, $to)
{
    q('UPDATE device SET last_download_web = ? WHERE sn = ? AND (last_download_web IS NULL OR last_download_web >= ?) AND (last_download_web IS NULL OR last_download_web < ?)',
        [$to, $sn, date('Y-m-d', strtotime($from . ' -1 day')), $to]);
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
