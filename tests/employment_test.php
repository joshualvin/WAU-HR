<?php
// Self-check for employment status rules: php tests/employment_test.php  (no database needed)
require __DIR__ . '/../inc/employment.php';
function valid_date($s) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s); }
function h($s) { return htmlspecialchars((string)$s); }
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
check('type kontrak', employment_from_text('Kontrak'), 'kontrak');
check('type contract', employment_from_text('Contract'), 'kontrak');
check('type pkwtt', employment_from_text('PKWTT'), 'tetap');
check('type full-time', employment_from_text('Full-Time'), 'tetap');
check('type harian', employment_from_text('Harian lepas'), 'harian');
check('type intern', employment_from_text('Internship'), 'magang');
check('ok tetap', employment_error('tetap', '2026-01-01', '', ''), null);
check('kontrak needs end', employment_error('kontrak', '2026-01-01', '', '') !== null, true);
check('end before start', employment_error('kontrak', '2026-01-01', '2025-12-31', '') !== null, true);
check('ok kontrak', employment_error('kontrak', '2026-01-01', '2026-12-31', ''), null);
check('probation 3 months', employment_error('tetap', '2026-01-01', '', '2026-04-01'), null);
check('probation too long', employment_error('tetap', '2026-01-01', '', '2026-04-02') !== null, true);
check('probation only tetap', employment_error('kontrak', '2026-01-01', '2026-12-31', '2026-02-01') !== null, true);
check('pill none', strpos(employment_pill(null), 'Belum diisi') !== false, true);
check('pill expired', strpos(employment_pill(['status' => 'kontrak', 'end_date' => '2026-01-31', 'probation_end' => null], '2026-02-10'), 'pill bad') !== false, true);
check('pill ending soon', strpos(employment_pill(['status' => 'kontrak', 'end_date' => '2026-02-20', 'probation_end' => null], '2026-02-10'), 'pill warn') !== false, true);
echo "employment_test: $ok checks passed\n";
