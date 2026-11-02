<?php
// Download scans from fingerspot.io with the official developer API (get_attlog) into att_log.
// Token: 'fingerspot_token' in config.php (from the fingerspot.io developer portal). Never shown in the app.
// Rows are stored like the desktop program stores cloud scans: sn = machine serial, att_id '0';
// the att_log primary key (sn, scan_date, pin) keeps anything already downloaded from being added twice.

function cloud_token()
{
    global $CONFIG;
    return isset($CONFIG['fingerspot_token']) ? trim($CONFIG['fingerspot_token']) : '';
}

function cloud_url()
{
    global $CONFIG;
    return !empty($CONFIG['fingerspot_url']) ? $CONFIG['fingerspot_url'] : 'https://developer.fingerspot.io/api/get_attlog';
}

/** Machines that have a cloud ID. */
function cloud_devices()
{
    return q("SELECT d.sn, d.device_name, d.cloud_id, d.last_download_web,
                     (SELECT MAX(scan_date) FROM att_log a WHERE a.sn = d.sn) last_scan
              FROM device d WHERE d.cloud_id <> '' ORDER BY d.device_name")->fetchAll();
}

/** One get_attlog call (at most 2 days). Returns [records, error]. */
function cloud_get_attlog($cloudId, $start, $end)
{
    $body = json_encode(['trans_id' => (string)mt_rand(1, 999999), 'cloud_id' => $cloudId, 'start_date' => $start, 'end_date' => $end]);
    $ch = curl_init(cloud_url());
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cloud_token(), 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [[], 'Tidak bisa terhubung ke fingerspot.io: ' . $err];
    $json = json_decode($raw, true);
    if ($http !== 200 || !is_array($json)) {
        $msg = is_array($json) ? (isset($json['message']) ? $json['message'] : (isset($json['error']) ? $json['error'] : '')) : substr(strip_tags($raw), 0, 150);
        return [[], "HTTP $http" . ($msg ? ": $msg" : '') . ($http === 401 || $http === 403 ? ' (periksa fingerspot_token di config.php)' : '')];
    }
    if (empty($json['success'])) {
        $msg = isset($json['message']) ? $json['message'] : (isset($json['data']) && is_string($json['data']) ? $json['data'] : 'success = false');
        return [[], 'fingerspot.io: ' . $msg];
    }
    return [isset($json['data']) && is_array($json['data']) ? $json['data'] : [], null];
}

/**
 * Download every machine in $sns (all cloud machines when empty) for $from..$to, two days per call.
 * Returns per-machine results: [sn => ['name', 'received', 'added', 'unknown_pins' => [], 'errors' => []]].
 */
function cloud_download($from, $to, $sns = [])
{
    @set_time_limit(600);
    $known = array_flip(q('SELECT pegawai_pin FROM pegawai')->fetchAll(PDO::FETCH_COLUMN));
    $ins = db()->prepare('INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id) VALUES (?, ?, ?, ?, ?, 0, ?, ?)');
    $out = [];
    foreach (cloud_devices() as $d) {
        if ($sns && !in_array($d['sn'], $sns, true)) continue;
        $res = ['name' => $d['device_name'], 'received' => 0, 'added' => 0, 'unknown_pins' => [], 'errors' => []];
        for ($s = $from; $s <= $to; $s = date('Y-m-d', strtotime($s . ' +2 days'))) {
            $e = min(date('Y-m-d', strtotime($s . ' +1 day')), $to);
            list($rows, $err) = cloud_get_attlog($d['cloud_id'], $s, $e);
            if ($err) { $res['errors'][] = "$s s/d $e: $err"; if (strpos($err, 'HTTP 40') === 0) break; continue; }
            foreach ($rows as $r) {
                if (empty($r['pin']) || empty($r['scan_date']) || !strtotime($r['scan_date'])) continue;
                $res['received']++;
                $scan = date('Y-m-d H:i:s', strtotime($r['scan_date']));
                $pin = (string)$r['pin'];
                $ins->execute([$d['sn'], $scan, $pin, isset($r['verify']) ? (int)$r['verify'] : 1,
                               isset($r['status_scan']) ? (int)$r['status_scan'] : 0, isset($r['work_code']) ? (int)$r['work_code'] : 0, '0']);
                $res['added'] += $ins->rowCount();
                if (!isset($known[$pin])) $res['unknown_pins'][$pin] = true;
            }
        }
        if (!$res['errors'] || $res['received']) q('UPDATE device SET last_download_web = ? WHERE sn = ?', [date('Y-m-d'), $d['sn']]);
        $res['unknown_pins'] = array_keys($res['unknown_pins']);
        $out[$d['sn']] = $res;
    }
    return $out;
}
