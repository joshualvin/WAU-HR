<?php
// Link with WAU ERP (on this server): invoiced sales per salesman for the sales bonus, and delivery trips per driver for the
// driver incentive (22 Nov 2026), from its api/hr.php.
// config.php: erp_url (default http://127.0.0.1/wau-erp) and erp_token (the ERP secret's hr_api_token).

function erp_enabled()
{
    global $CONFIG;
    return !empty($CONFIG['erp_token']);
}

/** Invoiced sales per salesman code for $from..$to (surat jalan date). Returns [code => row, error or null]. */
function erp_sales($from, $to)
{
    global $CONFIG;
    $url = rtrim(!empty($CONFIG['erp_url']) ? $CONFIG['erp_url'] : 'http://127.0.0.1/wau-erp', '/') . '/api/hr.php?' . http_build_query(['action' => 'sales', 'from' => $from, 'to' => $to]);
    $ch = curl_init($url);
    $token = trim($CONFIG['erp_token']);
    // X-HR-Token too: XAMPP's Apache often drops Authorization before PHP sees it
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'X-HR-Token: ' . $token, 'Accept: application/json'],
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [[], 'Tidak bisa terhubung ke WAU ERP: ' . $err];
    $json = json_decode($res, true);
    if ($http !== 200 || !is_array($json) || !isset($json['salesmen'])) {
        $msg = is_array($json) && isset($json['error']) ? $json['error'] : substr(strip_tags((string)$res), 0, 150);
        return [[], "WAU ERP HTTP $http: $msg" . ($http === 401 || $http === 404 ? ' (periksa erp_token di config.php dan hr_api_token di secret ERP)' : '')];
    }
    $out = [];
    foreach ($json['salesmen'] as $s) $out[$s['code']] = $s;
    return [$out, null];
}

/**
 * Delivery trips per driver code of the main system (masterkaryawan, e.g. K04 = Ferdy as driver) for $from..$to, from the trips
 * recorded there (Shipment): days driven as driver, trips, and trips with exactly one kernet. Returns [code => row, error or null].
 */
function erp_trips($from, $to)
{
    global $CONFIG;
    $url = rtrim(!empty($CONFIG['erp_url']) ? $CONFIG['erp_url'] : 'http://127.0.0.1/wau-erp', '/') . '/api/hr.php?' . http_build_query(['action' => 'trips', 'from' => $from, 'to' => $to]);
    $ch = curl_init($url);
    $token = trim($CONFIG['erp_token']);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'X-HR-Token: ' . $token, 'Accept: application/json'],
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [[], 'Tidak bisa terhubung ke WAU ERP: ' . $err];
    $json = json_decode($res, true);
    if ($http !== 200 || !is_array($json) || !isset($json['drivers'])) {
        $msg = is_array($json) && isset($json['error']) ? $json['error'] : substr(strip_tags((string)$res), 0, 150);
        return [[], "WAU ERP HTTP $http: $msg"];
    }
    $out = [];
    foreach ($json['drivers'] as $d) $out[strtoupper($d['code'])] = $d;
    return [$out, null];
}

/**
 * The trips themselves (main system Shipment) for $from..$to: [[['date', 'driver' => code, 'helpers' => [codes]], ...], [code => name], error or null].
 * Needs the WAU ERP version whose api/hr.php trips answer includes 'trips'.
 */
function erp_trip_list($from, $to)
{
    global $CONFIG;
    $url = rtrim(!empty($CONFIG['erp_url']) ? $CONFIG['erp_url'] : 'http://127.0.0.1/wau-erp', '/') . '/api/hr.php?' . http_build_query(['action' => 'trips', 'from' => $from, 'to' => $to]);
    $ch = curl_init($url);
    $token = trim($CONFIG['erp_token']);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'X-HR-Token: ' . $token, 'Accept: application/json'],
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$res, true);
    if ($res === false || $http !== 200 || !is_array($json)) return [[], [], 'Tidak bisa membaca trip dari WAU ERP (HTTP ' . $http . ').'];
    if (!isset($json['trips'])) return [[], [], 'WAU ERP di server ini belum punya daftar trip per tanggal; perbarui WAU ERP.'];
    return [$json['trips'], isset($json['names']) ? $json['names'] : [], null];
}

/**
 * Per ERP code: dates driven as driver (one per day) and trips sent alone as the only kernet. $trips = erp_trip_list() rows.
 * Returns [code => ['drive' => [Y-m-d...], 'alone' => [Y-m-d per trip...]]].
 */
function trip_people($trips)
{
    $out = [];
    foreach ($trips as $t) {
        if ($t['driver'] !== '') $out[$t['driver']]['drive'][$t['date']] = $t['date'];
        if (count($t['helpers']) === 1) $out[$t['helpers'][0]]['alone'][] = $t['date'];
    }
    foreach ($out as &$x) { $x['drive'] = isset($x['drive']) ? array_values($x['drive']) : []; $x['alone'] = isset($x['alone']) ? $x['alone'] : []; }
    return $out;
}
