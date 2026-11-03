<?php
// Link with WAU ERP (on this server): invoiced sales per salesman for the sales bonus, from its api/hr.php.
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
