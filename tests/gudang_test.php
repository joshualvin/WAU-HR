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
check('normal: late after the tolerance', late_minutes(8 * 60 + 12, 8 * 60, 5), 12);
check('normal: within the tolerance', late_minutes(8 * 60 + 4, 8 * 60, 5), 0);
check('warehouse opened 08:20, scanned 08:24: within the 5-minute queue grace', late_minutes(8 * 60 + 24, 8 * 60, 0, 8 * 60 + 20), 0);
check('warehouse opened 08:20, scanned 08:32: 12 minutes late', late_minutes(8 * 60 + 32, 8 * 60, 0, 8 * 60 + 20), 12);
check('grace 0 counts every minute after opening', late_minutes(8 * 60 + 22, 8 * 60, 0, 8 * 60 + 20, 0), 2);
check('warehouse opened 08:20, arrived 08:15: not late', late_minutes(8 * 60 + 15, 8 * 60, 0, 8 * 60 + 20), 0);
check('warehouse opened early: normal start counts', late_minutes(8 * 60 + 10, 8 * 60, 0, 7 * 60 + 40), 10);
$op = ['9001', '9008', '9005'];
check('Heni scanned at Margo: she opened, even if a backup scanned earlier', gudang_pick(['9008' => ['07:40'], '9001' => ['08:05']], $op), '9001');
check('Heni absent: the backup who scanned first opened', gudang_pick(['9005' => ['08:10'], '9008' => ['08:02', '16:00']], $op), '9008');
check('nobody of them at Margo', gudang_pick(['1234' => ['08:00']], $op), null);
check('early 07:00 for an 08:00 start: 60 minutes lembur', early_ot_minutes(7 * 60, 8 * 60, 30, 240), 60);
check('a few minutes early is below the minimum', early_ot_minutes(7 * 60 + 50, 8 * 60, 30, 240), 0);
check('on time or late: no early lembur', [early_ot_minutes(8 * 60, 8 * 60, 30, 240), early_ot_minutes(8 * 60 + 5, 8 * 60, 30, 240)], [0, 0]);
check('capped at the maximum', early_ot_minutes(5 * 60, 8 * 60, 30, 120), 120);
echo "gudang_test: $ok checks passed\n";
