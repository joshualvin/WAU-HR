<?php
// Self-check for review scoring: php tests/talent_test.php  (no database needed)
require __DIR__ . '/../inc/talent.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$c = default_criteria();
check('weights add to 100', array_sum(array_column($c, 'weight')), 100);
check('all 4', weighted_score($c, [4, 4, 4, 4, 4]), 4.0);
check('weighted', weighted_score($c, [5, 3, 4, 2, 5]), 3.85);   // (125 + 75 + 80 + 30 + 75) / 100
check('missing score', weighted_score($c, [5, 3, 4, 2]), null);
check('out of range', weighted_score($c, [5, 3, 4, 2, 6]), null);
check('label 4.5', rating_label(4.5), 'Sangat baik');
check('label 3.85', rating_label(3.85), 'Baik');
check('label 2.5', rating_label(2.5), 'Cukup');
check('label 1.4', rating_label(1.4), 'Buruk');
echo "talent_test: $ok checks passed\n";
