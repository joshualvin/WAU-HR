<?php
// Self-check for the notification feed: php tests/notify_test.php  (no database)
require __DIR__ . '/../inc/notify.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$today = '2026-11-04';

check('days', [notify_days('2026-11-04', '2026-11-11'), notify_days('2026-11-04', '2026-11-01'), notify_days($today, $today)], [7, -3, 0]);
check('next annual, later this year', notify_next_annual('1996-11-20', $today), '2026-11-20');
check('next annual, already passed goes to next year', notify_next_annual('1996-03-02', $today), '2027-03-02');
check('next annual, today counts', notify_next_annual('1990-11-04', $today), '2026-11-04');

check('quiet day gives nothing', notify_build([], $today), []);
$n = notify_build(['pending' => ['izin' => 2, 'lembur' => 0, 'kasbon' => 1]], $today);
check('pending approvals, one per kind that has some', array_column($n, 'title'), ['2 pengajuan Cuti / izin menunggu', '1 pengajuan Kasbon menunggu']);
check('pending is the most urgent level', array_unique(array_column($n, 'level')), ['bad']);

$n = notify_build(['alerts' => [['name' => 'Budi', 'what' => 'Kontrak berakhir', 'date' => '2026-11-10', 'link' => 'x'], ['name' => 'Sari', 'what' => 'KTP berakhir', 'date' => '2026-11-30', 'link' => 'y'], ['name' => 'Dedi', 'what' => 'SIM berakhir', 'date' => '2026-11-01', 'link' => 'z']]], $today);
check('alerts: within a week or overdue are urgent, later ones are not', array_map(function ($x) { return [$x['title'], $x['level']]; }, $n), [['Budi: Kontrak berakhir', 'bad'], ['Dedi: SIM berakhir', 'bad'], ['Sari: KTP berakhir', 'warn']]);
check('alert detail says how long', [$n[0]['detail'], $n[1]['detail']], ['10-11-2026 (6 hari lagi)', '01-11-2026 (lewat 3 hari)']);

$n = notify_build(['devices' => [['name' => 'AF2', 'last_scan' => '2026-11-03 16:42:00'], ['name' => 'AF4', 'last_scan' => '2026-10-30 09:00:00'], ['name' => 'Baru', 'last_scan' => null]]], $today);
check('machines: only the silent ones (3 days or more, or never)', array_column($n, 'title'), ['AF4 tidak mengirim scan sejak 5 hari', 'Baru belum pernah mengirim scan']);

$n = notify_build(['transfers' => [['code' => 'MUT-2026-0001', 'name' => 'Budi', 'effective' => '2026-11-04', 'summary' => 'Promosi'], ['code' => 'MUT-2026-0002', 'name' => 'Sari', 'effective' => '2026-11-18', 'summary' => 'Mutasi'], ['code' => 'MUT-2026-0003', 'name' => 'Dedi', 'effective' => '2027-02-01', 'summary' => 'Rotasi']]], $today);
check('transfers: today and the next 2 weeks, not months away', array_column($n, 'title'), ['MUT-2026-0001: Budi berlaku hari ini', 'MUT-2026-0002: Sari berlaku 14 hari lagi']);

$n = notify_build(['birthdays' => [['name' => 'Ani', 'date' => '1996-11-04'], ['name' => 'Bimo', 'date' => '1990-11-09'], ['name' => 'Cici', 'date' => '1990-11-20']],
                   'anniversaries' => [['name' => 'Dodo', 'date' => '2016-11-06'], ['name' => 'Eko', 'date' => '2026-11-04'], ['name' => 'Fani', 'date' => '2010-12-25']]], $today);
check('birthdays and anniversaries: within a week, joiners of this year are no anniversary', array_column($n, 'title'),
      ['Ani berulang tahun hari ini', 'Bimo berulang tahun 5 hari lagi', 'Dodo genap 10 tahun bekerja 2 hari lagi']);
check('age in the detail', $n[0]['detail'], '30 tahun · 04-11');

$n = notify_build(['no_status' => 3], $today);
check('missing employment status', [$n[0]['title'], $n[0]['level']], ['3 karyawan aktif belum punya status kepegawaian', 'info']);

$n = notify_build(['pending' => ['izin' => 1], 'devices' => [['name' => 'X', 'last_scan' => null]], 'no_status' => 1], $today);
check('order: act, soon, info', array_column($n, 'level'), ['bad', 'warn', 'info']);
echo "notify_test: $ok checks passed\n";
