<?php
// Self-check for the employee transfer logic that needs no database: php tests/transfer_test.php
require __DIR__ . '/../inc/transfer.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
// the supervisor loop check lives in inc/company.php; use a local stand-in with the same contract
function supervisor_loop($id, $boss, $map) { $seen = []; for ($b = $boss; $b; $b = isset($map[$b]) ? $map[$b] : null) { if ((int)$b === (int)$id || isset($seen[$b])) return true; $seen[$b] = true; } return false; }

$today = '2026-11-04';
check('code', [transfer_code(7, '2026-11-04 10:00:00'), transfer_code(12345, '2027-01-02 00:00:00')], ['MUT-2026-0007', 'MUT-2027-12345']);
check('due', [transfer_is_due('2026-11-04', $today), transfer_is_due('2026-11-03', $today), transfer_is_due('2026-11-05', $today)], [true, true, false]);

$cur = ['status' => 1, 'jab' => 4, 'dep' => 6, 'kan' => 1, 'atasan' => 2];
$base = ['pegawai_id' => 9, 'type' => 'promosi', 'effective' => '2026-12-01', 'jab' => '', 'dep' => '', 'kan' => '', 'atasan' => ''];
check('ok: new jabatan', transfer_validate(['jab' => '3'] + $base, $cur, $today), null);
check('no employee', transfer_validate($base, null, $today), 'Pilih karyawan.');
check('inactive employee', transfer_validate(['jab' => '3'] + $base, ['status' => 2] + $cur, $today), 'Hanya karyawan aktif yang bisa dimutasi.');
check('bad type', transfer_validate(['jab' => '3', 'type' => 'zzz'] + $base, $cur, $today), 'Pilih jenis mutasi.');
check('bad date', transfer_validate(['jab' => '3', 'effective' => '01-12-2026'] + $base, $cur, $today), 'Isi tanggal berlaku yang benar.');
check('too old', transfer_validate(['jab' => '3', 'effective' => '2025-01-01'] + $base, $cur, $today), 'Tanggal berlaku tidak boleh lebih dari 1 tahun yang lalu.');
check('too far', transfer_validate(['jab' => '3', 'effective' => '2030-01-01'] + $base, $cur, $today), 'Tanggal berlaku terlalu jauh ke depan.');
check('nothing changes', transfer_validate(['jab' => '4', 'dep' => '6'] + $base, $cur, $today), 'Tidak ada yang berubah dari data karyawan saat ini.');
check('all fields empty', transfer_validate($base, $cur, $today), 'Tidak ada yang berubah dari data karyawan saat ini.');
check('remove supervisor counts as a change', transfer_validate(['atasan' => '0'] + $base, $cur, $today), null);
check('removing a supervisor who is already none is not a change', transfer_validate(['atasan' => '0'] + $base, ['atasan' => null] + $cur, $today), 'Tidak ada yang berubah dari data karyawan saat ini.');
check('own supervisor', transfer_validate(['atasan' => '9'] + $base, $cur, $today), 'Karyawan tidak bisa menjadi atasannya sendiri.');
check('supervisor loop (boss 5 reports to 9)', transfer_validate(['atasan' => '5'] + $base, $cur, $today, [5 => 9]), 'Atasan itu berada di bawah karyawan ini, jadi membentuk lingkaran.');
check('supervisor without loop', transfer_validate(['atasan' => '5'] + $base, $cur, $today, [5 => 2]), null);
check('yesterday is fine (recorded late)', transfer_validate(['jab' => '3', 'effective' => '2026-11-03'] + $base, $cur, $today), null);

$names = ['jab' => [3 => 'Supervisor', 4 => 'Staff'], 'dep' => [6 => 'Gudang', 4 => 'IT'], 'kan' => [1 => 'Pusat'], 'atasan' => [2 => 'Budi', 5 => 'Sari']];
$row = ['from_jab' => 4, 'to_jab' => 3, 'from_dep' => 6, 'to_dep' => 4, 'from_kan' => 1, 'to_kan' => null, 'from_atasan' => 2, 'to_atasan' => 0];
check('changes list', transfer_changes($row, $names), [['Jabatan', 'Staff', 'Supervisor'], ['Departemen', 'Gudang', 'IT'], ['Atasan langsung', 'Budi', '—']]);
check('unchanged targets are left out', transfer_changes(['from_jab' => 4, 'to_jab' => 4, 'from_dep' => 6, 'to_dep' => null, 'from_kan' => 1, 'to_kan' => null, 'from_atasan' => null, 'to_atasan' => null], $names), []);
check('unknown ids show their number', transfer_changes(['from_jab' => 4, 'to_jab' => 99, 'from_dep' => null, 'to_dep' => null, 'from_kan' => null, 'to_kan' => null, 'from_atasan' => null, 'to_atasan' => null], $names), [['Jabatan', 'Staff', '#99']]);
check('summary text', transfer_summary($row, $names), 'Jabatan: Staff → Supervisor; Departemen: Gudang → IT; Atasan langsung: Budi → —');
check('summary of nothing', transfer_summary(['from_jab' => 4, 'to_jab' => null, 'from_dep' => 6, 'to_dep' => null, 'from_kan' => 1, 'to_kan' => null, 'from_atasan' => null, 'to_atasan' => null], $names), '');
echo "transfer_test: $ok checks passed\n";
