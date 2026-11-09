<?php
// Self-check for reading the applicant's submitted date: php tests/career_test.php  (no database)
require __DIR__ . '/../inc/career.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$d = function ($v) { $t = career_parse_date($v); return $t ? date('Y-m-d H:i', $t) : null; };
check('iso with time', $d('2026-10-17 14:32'), '2026-10-17 14:32');
check('indonesian day first with dots', $d('17/10/2026 14.32'), '2026-10-17 14:32');
check('day first date only', $d('17-10-2026'), '2026-10-17 12:00');
check('day first, day over 12 is not a month', $d('13/10/2026'), '2026-10-13 12:00');
check('spreadsheet serial', substr($d('46312.6'), 0, 10), '2026-10-17');
check('serial from the real sheet (Vaneza)', $d('46312.5375'), '2026-10-17 12:54');
check('serial late in the evening stays on its day', substr($d('46312.9'), 0, 10), '2026-10-17');
check('empty and rubbish', [$d(''), $d('soon')], [null, null]);
echo "career_test: $ok checks passed\n";
