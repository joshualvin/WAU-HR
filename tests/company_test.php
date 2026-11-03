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

// ---- month calendar ----
check('month from a good value', calendar_month('2026-11', '2026-01-15'), '2026-11-01');
check('month fallback for junk', [calendar_month('', '2026-03-15'), calendar_month('2026-13', '2026-03-15'), calendar_month('abc', '2026-03-15')], ['2026-03-01', '2026-03-01', '2026-03-01']);
$w = calendar_weeks('2026-11-01');   // 1 Nov 2026 is a Sunday: the first row starts on Monday 26 Oct
check('weeks start on Monday and cover the month', [count($w), $w[0][0], $w[0][6], $w[count($w) - 1][6]], [6, '2026-10-26', '2026-11-01', '2026-12-06']);
check('every week has 7 days', array_unique(array_map('count', $w)), [7]);
$w = calendar_weeks('2026-06-01');   // a Monday, 30 days: Mon 1 Jun ... Tue 30 Jun, last week ends Sun 5 Jul
check('a month starting on Monday begins on the 1st', [$w[0][0], count($w), $w[4][6]], ['2026-06-01', 5, '2026-07-05']);
$w = calendar_weeks('2026-02-01');   // 28 days, starts on a Sunday -> 5 rows, 26 Jan .. 1 Mar
check('february 2026', [count($w), $w[0][0], $w[4][6]], [5, '2026-01-26', '2026-03-01']);
echo "company_test: $ok checks passed\n";
