<?php
// Self-check for the delivery report's counting of the WAU ERP trips: php tests/erp_test.php  (no network or database)
require __DIR__ . '/../inc/erp.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$trips = [
    ['date' => '2026-11-02', 'driver' => 'K04', 'helpers' => ['H01']],          // Ferdy drives, Armand alone as kernet
    ['date' => '2026-11-02', 'driver' => 'K04', 'helpers' => ['H01', 'H02']],   // same day again: still one driving day; two kernets = not alone
    ['date' => '2026-11-03', 'driver' => 'K07', 'helpers' => ['H01']],          // Roy drives, Armand alone again
    ['date' => '2026-11-04', 'driver' => 'K04', 'helpers' => []],              // no kernet: not a kirim sendiri trip
];
$p = trip_people($trips);
check('driver days counted once per day', [$p['K04']['drive'], $p['K07']['drive']], [['2026-11-02', '2026-11-04'], ['2026-11-03']]);
check('kirim sendiri = trips with exactly one kernet, per kernet', $p['H01']['alone'], ['2026-11-02', '2026-11-03']);
check('second kernet of a two-kernet trip gets nothing', isset($p['H02']), false);
check('drivers are not paid kirim sendiri', [$p['K04']['alone'], $p['K07']['alone']], [[], []]);
echo "erp_test: $ok checks passed\n";
