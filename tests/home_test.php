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

// ---- age, personal-data fields, turnover ----
check('age before birthday', home_age_of('1996-08-23', '2026-11-04'), 30);
check('age on birthday', home_age_of('1996-11-04', '2026-11-04'), 30);
check('age day before birthday', home_age_of('1996-11-05', '2026-11-04'), 29);
check('age none', [home_age_of(null, '2026-11-04'), home_age_of('0000-00-00', '2026-11-04'), home_age_of('not a date', '2026-11-04')], [null, null, null]);
$ppl = [['tgl_lahir' => '2003-01-01'], ['tgl_lahir' => '1996-08-23'], ['tgl_lahir' => '1990-05-05'], ['tgl_lahir' => '1960-01-01'], ['tgl_lahir' => null]];   // 23, 30, 36, 66, unknown
$ag = home_age($ppl, '2026-11-04');
check('age buckets', $ag['buckets'], ['< 25' => 1, '25-34' => 1, '35-44' => 1, '45-54' => 0, '55+' => 1, 'Belum diisi' => 1]);
check('age average of the known ones', $ag['avg'], 38.8);   // (23 + 30 + 36 + 66) / 4
check('age average without data', home_age([['tgl_lahir' => null]], '2026-11-04')['avg'], null);

$opts = ['islam' => 'Islam', 'kristen' => 'Kristen', 'hindu' => 'Hindu'];
check('field counts, unused options hidden, unknown last', home_field([['pegawai_id' => 1], ['pegawai_id' => 2], ['pegawai_id' => 3], ['pegawai_id' => 4]], [1 => 'kristen', 2 => 'kristen', 3 => 'zzz'], $opts), ['Kristen' => 2, 'Belum diisi' => 2]);

// turnover: today 2026-11-04, three months. A employed all along, B joined 2026-09-10, C left 2026-10-15, D joined 2026-11-02 and left 2026-11-03
$staff = [['start' => '2020-01-01', 'end' => null], ['start' => '2026-09-10', 'end' => null], ['start' => '2021-01-01', 'end' => '2026-10-15'], ['start' => '2026-11-02', 'end' => '2026-11-03']];
$tv = home_turnover($staff, '2026-11-04', 3);
check('turnover months', array_column($tv, 'ym'), ['2026-09', '2026-10', '2026-11']);
check('september: B joined, nobody left, 3 at the end', [$tv[0]['joined'], $tv[0]['left'], $tv[0]['end'], $tv[0]['rate']], [1, 0, 3, 0.0]);
check('october: C left, 2 at the end, 1 / ((3+2)/2) = 40%', [$tv[1]['joined'], $tv[1]['left'], $tv[1]['end'], $tv[1]['rate']], [0, 1, 2, 40.0]);
check('november: D joined and left, 2 at the end, 1 / 2 = 50%', [$tv[2]['joined'], $tv[2]['left'], $tv[2]['end'], $tv[2]['rate']], [1, 1, 2, 50.0]);
check('turnover without staff', home_turnover([], '2026-11-04', 2)[1], ['ym' => '2026-11', 'joined' => 0, 'left' => 0, 'end' => 0, 'rate' => 0.0]);
echo "home_test: $ok checks passed\n";
