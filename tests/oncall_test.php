<?php
// Self-check for the on-call helpers that need no database: php tests/oncall_test.php
require __DIR__ . '/../inc/oncall.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

check('dates', oncall_dates('2026-11-06', '2026-11-09'), ['2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09']);   // Fri .. Mon
check('dates without sundays', oncall_dates('2026-11-06', '2026-11-09', true), ['2026-11-06', '2026-11-07', '2026-11-09']);
check('one day', oncall_dates('2026-11-06', '2026-11-06'), ['2026-11-06']);
check('empty when reversed', oncall_dates('2026-11-09', '2026-11-06'), []);
check('month end crossing', count(oncall_dates('2026-10-28', '2026-11-03')), 7);
check('ok', oncall_validate([1, 2], '2026-11-06', '2026-11-12'), null);
check('nobody', oncall_validate([], '2026-11-06', '2026-11-12'), 'Pilih minimal satu karyawan.');
check('bad date', oncall_validate([1], '06-11-2026', '2026-11-12'), 'Isi tanggal yang benar.');
check('reversed', oncall_validate([1], '2026-11-12', '2026-11-06'), 'Tanggal akhir tidak boleh sebelum tanggal mulai.');
check('92 days is the limit', [oncall_validate([1], '2026-01-01', '2026-04-02'), oncall_validate([1], '2026-01-01', '2026-04-03')], [null, 'Paling lama 92 hari sekali jalan.']);   // Jan 1 .. Apr 2 = 92 days
echo "oncall_test: $ok checks passed\n";
