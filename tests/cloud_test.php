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

// ---- Personnel desktop API path ----
check('ds url', cloud_ds_url('C2635CA0C3193536', '2026-11-03', 'KEY', '20261103120000'),
      'https://api-ds.fingerspot.io/api/desktop/attendance_log/KEY/C2635CA0C3193536/2026-11-03/20261103120000/' . md5('C2635CA0C3193536' . '2026-11-03' . '20261103120000' . 'KEY') . '/3/0/5000');
$d2 = cloud_row_ds(['PIN' => '2', 'Date Time' => '2020-07-25 11:11:29', 'Verify' => 3, 'Type ID' => 1]);
check('ds origin', cloud_ds_origin(), 'https://api-ds.fingerspot.io');
check('min interval default', cloud_min_interval(), 61);
check('ds row sdk names', [$d2['verify'], $d2['type_id']], [3, 1]);
$d = cloud_row_ds(['PIN' => '1', 'Date Time' => '2020-07-25 11:11:29', 'Verify Type' => 4, 'Log Type' => 1, 'Work Code' => 7]);
check('ds row', [$d['pin'], $d['scan_date'], $d['verify'], $d['type_id'], $d['work_code']], ['1', '2020-07-25 11:11:29', 4, 1, 7]);
check('ds row no scan', cloud_row_ds(['PIN' => '1']), null);
check('ds row defaults', cloud_row_ds(['PIN' => '9', 'Date Time' => '2020-07-25 08:00'])['type_id'], 0);

// ---- .xlsx import helpers ----
check('xlsx string date', xlsx_datetime('2024-08-05 10:37:00'), '2024-08-05 10:37:00');
check('xlsx serial date', xlsx_datetime('44805'), '2022-09-01 00:00:00');   // Excel serial, epoch 1899-12-30
check('xlsx blank date', xlsx_datetime(''), '');
check('status masuk', xlsx_status('Absensi Masuk'), 0);
check('status pulang', xlsx_status('Absensi Pulang'), 1);
check('status break in', xlsx_status('Mulai Istirahat'), 2);
check('status numeric', xlsx_status('3'), 3);

// build a tiny .xlsx (shared-string header + inline-string data) and read it back
$tmp = tempnam(sys_get_temp_dir(), 'xlsxt') . '.xlsx';
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::CREATE);
$zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Cloud ID</t></si><si><t>PIN</t></si><si><t>Time Scan</t></si></sst>');
$zip->addFromString('xl/worksheets/sheet1.xml',
    '<worksheet><sheetData>'
    . '<row><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
    . '<row><c r="A2" t="inlineStr"><is><t>C2635CA0C3193536</t></is></c><c r="B2" t="inlineStr"><is><t>99901</t></is></c><c r="C2" t="inlineStr"><is><t>2024-08-05 10:37:00</t></is></c></row>'
    . '</sheetData></worksheet>');
$zip->close();
$rows = xlsx_rows($tmp);
unlink($tmp);
check('xlsx header', [$rows[0]['A'], $rows[0]['B'], $rows[0]['C']], ['Cloud ID', 'PIN', 'Time Scan']);
check('xlsx data', [$rows[1]['A'], $rows[1]['B'], $rows[1]['C']], ['C2635CA0C3193536', '99901', '2024-08-05 10:37:00']);

echo "cloud_test: $ok checks passed\n";
