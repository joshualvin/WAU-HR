<?php
// Self-check for the Fingerspot.io API SDK request and geofences: php tests/cloud_test.php  (no network or database)
$CONFIG = [];
require __DIR__ . '/../inc/cloud.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
// the guide's GPS example: MD5(0-TY7ARXSU7EYDPBOK 2023-12-07 20231210455300 TY7ARXSU7EYDPBOK), no spaces
check('auth', cloud_url('0-TY7ARXSU7EYDPBOK', '2023-12-07', 'TY7ARXSU7EYDPBOK', '20231210455300'),
      'https://api.fingerspot.io/api/download/attendance_log/0-TY7ARXSU7EYDPBOK/2023-12-07/6/date_time/asc/json/' . md5('0-TY7ARXSU7EYDPBOK2023-12-0720231210455300TY7ARXSU7EYDPBOK') . '/20231210455300');
$r = cloud_row(['PIN' => '99901', 'Date Time' => '2024-08-05 10:37', 'Verify' => 5, 'Type ID' => 1, 'Latitude' => -7.277934, 'Longitude' => 112.64297, 'Address' => 'Jl. Alam Hijau']);
check('row', [$r['pin'], $r['scan_date'], $r['verify'], $r['type_id'], $r['lat']], ['99901', '2024-08-05 10:37:00', 5, 1, -7.277934]);
check('row without pin', cloud_row(['Date Time' => '2024-08-05 10:37']), null);
check('error text', cloud_error_text('IO_API_ERR_7'), 'IO_API_ERR_7: sudah mencapai batas 100 request API per hari');
// geofence: Surabaya points about 1.1 km apart
check('distance', distance_m(-7.2779, 112.6429, -7.2779, 112.6529) > 1000 && distance_m(-7.2779, 112.6429, -7.2779, 112.6529) < 1200, true);
$f = [['name' => 'Kantor', 'lat' => -7.2779, 'lng' => 112.6429, 'radius_m' => 150], ['name' => 'Gudang', 'lat' => -7.30, 'lng' => 112.70, 'radius_m' => 200]];
$n = nearest_geofence(-7.2780, 112.6430, $f);
check('inside nearest', [$n[0]['name'], $n[2]], ['Kantor', true]);
$n = nearest_geofence(-7.2779, 112.6529, $f);
check('outside', $n[2], false);
check('no fences', nearest_geofence(-7.2, 112.6, []), null);
echo "cloud_test: $ok checks passed\n";
