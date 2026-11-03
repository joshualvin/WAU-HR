<?php
// Self-check for the org chart rules: php tests/company_test.php  (no database needed)
require __DIR__ . '/../inc/company.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$map = [2 => 1, 3 => 1, 4 => 2, 5 => 4];   // 1 is the top; 2 and 3 report to 1; 4 to 2; 5 to 4
check('no loop', supervisor_loop(6, 5, $map), false);
check('direct loop', supervisor_loop(1, 2, $map), true);         // 1 reporting to 2, who reports to 1
check('indirect loop', supervisor_loop(2, 5, $map), true);       // 2 reporting to 5, who is under 2
check('self', supervisor_loop(3, 3, $map), true);
$r = all_reports(2, $map); sort($r);
check('reports of 2', $r, [4, 5]);
$r = all_reports(1, $map); sort($r);
check('reports of 1', $r, [2, 3, 4, 5]);
echo "company_test: $ok checks passed\n";
