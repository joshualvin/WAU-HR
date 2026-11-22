<?php
// Self-check for the warehouse open / close state: php tests/gudang_test.php  (no database)
require __DIR__ . '/../inc/gudang.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
check('no scan before the expected time', gudang_state([], '07:30'), ['state' => 'not_yet', 'open' => null, 'close' => null]);
check('no scan after the expected time', gudang_state([], '09:15')['state'], 'late');
check('one scan = open', gudang_state(['07:02'], '10:00'), ['state' => 'open', 'open' => '07:02', 'close' => null]);
check('scans out of order, a second scan soon after is still open', gudang_state(['07:10', '07:02'], '08:00'), ['state' => 'open', 'open' => '07:02', 'close' => null]);
check('a scan 2h or more later is the closing time', gudang_state(['07:02', '12:00', '17:05'], '18:00'), ['state' => 'closed', 'open' => '07:02', 'close' => '17:05']);
check('exactly 2 hours counts', gudang_state(['08:00', '10:00'], '10:30')['state'], 'closed');
echo "gudang_test: $ok checks passed\n";
