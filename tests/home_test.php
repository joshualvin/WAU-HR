<?php
// Self-check for the HR home widgets: php tests/home_test.php  (no network or database)
require __DIR__ . '/../inc/homestats.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

$today = '2026-11-04';
check('bucket none', home_service_bucket(null, $today), 'Belum diisi');
check('bucket zero date', home_service_bucket('0000-00-00', $today), 'Belum diisi');
check('bucket under 1y', home_service_bucket('2026-03-01', $today), '< 1 th');
check('bucket 1 year exact-ish', home_service_bucket('2025-11-03', $today), '1-3 th');
check('bucket 4y', home_service_bucket('2022-06-01', $today), '3-5 th');
check('bucket 7y', home_service_bucket('2019-01-01', $today), '5-10 th');
check('bucket over 10y', home_service_bucket('2014-01-28', $today), '> 10 th');

$people = [
    ['pegawai_id' => 1, 'tgl_mulai_kerja' => '2014-01-28', 'jab' => 'Manager', 'gender' => 1],
    ['pegawai_id' => 2, 'tgl_mulai_kerja' => '2026-05-01', 'jab' => 'Staff', 'gender' => 2],
    ['pegawai_id' => 3, 'tgl_mulai_kerja' => null, 'jab' => 'Staff', 'gender' => 1],
    ['pegawai_id' => 4, 'tgl_mulai_kerja' => '2023-09-01', 'jab' => null, 'gender' => 1],
];
check('service histogram', home_service($people, $today), ['< 1 th' => 1, '1-3 th' => 0, '3-5 th' => 1, '5-10 th' => 0, '> 10 th' => 1, 'Belum diisi' => 1]);
check('service without gaps hides unknown', isset(home_service([$people[0]], $today)['Belum diisi']), false);

$statuses = ['tetap' => 'Karyawan tetap (PKWTT)', 'kontrak' => 'Kontrak (PKWT)', 'harian' => 'Harian lepas'];
$cur = [1 => ['status' => 'tetap'], 2 => ['status' => 'kontrak'], 3 => ['status' => 'tetap']];
check('status counts', home_status($people, $cur, $statuses), ['Karyawan tetap (PKWTT)' => 2, 'Kontrak (PKWT)' => 1, 'Harian lepas' => 0, 'Belum diisi' => 1]);
check('status all filled hides unknown', array_keys(home_status([$people[0]], $cur, $statuses)), ['Karyawan tetap (PKWTT)', 'Kontrak (PKWT)', 'Harian lepas']);

check('jabatan in display order, empty dropped', home_jabatan($people, ['Manager', 'Kabag', 'Staff']), ['Manager' => 1, 'Staff' => 2, 'Belum diisi' => 1]);
check('gender', home_gender($people), ['Laki-laki' => 3, 'Perempuan' => 1]);

check('pct', [home_pct(1, 3), home_pct(0, 0), home_pct(17, 17)], ['33.3', '0.0', '100.0']);
check('conic empty', home_conic(['a' => 0]), 'conic-gradient(var(--line) 0 100%)');
check('conic arcs', home_conic(['Laki-laki' => 3, 'Perempuan' => 1]), 'conic-gradient(#4b61dd 0% 75%, #27b19b 75% 100%)');
check('conic skips zero but keeps colour slot', home_conic(['a' => 0, 'b' => 2]), 'conic-gradient(#27b19b 0% 100%)');

check('off today', home_off_range('', $today), [$today, $today, 'Hari ini']);
check('off tomorrow', home_off_range('tomorrow', $today), ['2026-11-05', '2026-11-05', 'Besok']);
check('off week', home_off_range('week', $today), [$today, '2026-11-10', '7 hari']);
echo "home_test: $ok checks passed\n";
