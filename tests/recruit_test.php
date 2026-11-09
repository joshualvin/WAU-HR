<?php
// Self-check for the applicant detail helpers: php tests/recruit_test.php  (no database)
if (!function_exists('valid_date')) { function valid_date($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d); } }
require __DIR__ . '/../inc/recruit.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
check('ptkp married with 2', ptkp_from_status('kawin', 2), 'K/2');
check('ptkp single, 5 dependants capped', ptkp_from_status('belum', 5), 'TK/3');
check('ptkp empty status', ptkp_from_status('', 0), 'TK/0');
$in = ['gender' => '2', 'p_tgl_lahir' => '1996-08-23', 'marital' => 'kawin', 'dependants' => '12', 'nik_ktp' => '3578130809800001', 'npwp' => '12.345', 'education' => 'zzz', 'address' => 'Jl. A 1'];
check('clean: valid kept, bad dropped, dependants capped', candidate_clean_details($in, array_keys(candidate_detail_fields())),
    ['gender' => '2', 'p:tgl_lahir' => '1996-08-23', 'address' => 'Jl. A 1', 'marital' => 'kawin', 'dependants' => '9', 'nik_ktp' => '3578130809800001']);
check('clean: only the allowed keys', array_keys(candidate_clean_details($in, ['gender', 'address'])), ['gender', 'address']);
check('details of a candidate row', candidate_details(['details' => '{"gender":"1"}']), ['gender' => '1']);
echo "recruit_test: $ok checks passed\n";
