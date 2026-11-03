<?php
// Self-check for personal data, PTKP hint and letters: php tests/people_test.php  (no database needed)
require __DIR__ . '/../inc/people.php';
function valid_date($s) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s); }
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$c = clean_profile(['marital' => 'kawin', 'religion' => 'bogus', 'dependants' => '12', 'p_tgl_lahir' => '1990-02-30x', 'p_pegawai_telp' => ' 0812 ', 'unknown' => 'x']);
check('select kept', $c['marital'], 'kawin');
check('bad select dropped', isset($c['religion']), false);
check('dependants capped', $c['dependants'], '9');
check('bad date dropped', isset($c['p:tgl_lahir']), false);
check('pegawai field trimmed', $c['p:pegawai_telp'], '0812');
check('unknown dropped', isset($c['unknown']), false);
check('changes only', profile_changes(['marital' => 'belum', 'email' => 'a@b.c'], ['marital' => 'kawin', 'email' => 'a@b.c']), ['marital' => 'kawin']);
check('ptkp married', ptkp_from_profile('kawin', 2), 'K/2');
check('ptkp single max 3', ptkp_from_profile('belum', 5), 'TK/3');
check('letter fill', render_letter('Nama {nama}, {jabatan}. {unknown}', ['nama' => 'Riko', 'jabatan' => 'Staff']), 'Nama Riko, Staff. {unknown}');
echo "people_test: $ok checks passed\n";
