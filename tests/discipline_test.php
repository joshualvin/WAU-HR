<?php
// Self-check for reprimand levels: php tests/discipline_test.php  (no database needed)
require __DIR__ . '/../inc/discipline.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
check('first letter', next_warning_level([]), 'sp1');
check('verbal does not count', next_warning_level(['lisan']), 'sp1');
check('after SP1', next_warning_level(['sp1']), 'sp2');
check('highest active wins', next_warning_level(['sp2', 'sp1']), 'sp3');
check('SP3 stays SP3', next_warning_level(['sp3']), 'sp3');
echo "discipline_test: $ok checks passed\n";
