<?php
// Self-check for the activity log helpers: php tests/activity_test.php  (no database)
require __DIR__ . '/../inc/activity.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

foreach (['login' => 'Masuk', 'login_failed' => 'Masuk', 'manual_scan_revise' => 'Absensi', 'cloud_download_auto' => 'Absensi', 'device_archive' => 'Absensi',
          'leave_approve' => 'Cuti & jadwal', 'overtime_request' => 'Cuti & jadwal', 'payroll_unpublish' => 'Penggajian', 'thr_create' => 'Penggajian',
          'loan_pay' => 'Penggajian', 'profile_save' => 'Karyawan', 'master_move' => 'Karyawan', 'warning_create' => 'Karyawan',
          'announcement_save' => 'Perusahaan', 'user_add' => 'Pengguna', 'something_new' => 'Lainnya'] as $a => $t) check("type $a", activity_type($a), $t);

list($sql, $p) = activity_where(['from' => '2026-11-01', 'to' => '2026-11-03']);
check('where dates only', [$sql, $p], ['created_at >= ? AND created_at < ? + INTERVAL 1 DAY', ['2026-11-01', '2026-11-03']]);
list($sql, $p) = activity_where(['from' => '2026-11-01', 'to' => '2026-11-03', 'user' => 'admin', 'q' => 'Kantor', 'type' => 'Pengguna']);
check('where all filters', [$sql, $p], ['created_at >= ? AND created_at < ? + INTERVAL 1 DAY AND username = ? AND (detail LIKE ? OR action LIKE ?) AND (action LIKE ?)', ['2026-11-01', '2026-11-03', 'admin', '%Kantor%', '%Kantor%', 'user_%']]);
list($sql, $p) = activity_where(['from' => 'a', 'to' => 'b', 'type' => 'Masuk']);
check('where type with one prefix', $sql, 'created_at >= ? AND created_at < ? + INTERVAL 1 DAY AND (action LIKE ?)');
list($sql, $p) = activity_where(['from' => 'a', 'to' => 'b', 'type' => 'Lainnya']);
check('where other excludes every group', count($p) - 2, count(array_merge(...array_values(activity_types()))));
list($sql, $p) = activity_where(['from' => 'a', 'to' => 'b', 'type' => 'bogus']);
check('where unknown type ignored', $p, ['a', 'b']);
echo "activity_test: $ok checks passed\n";
