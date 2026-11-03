<?php
// Self-check for matching FTM names to employees: php tests/ftm_test.php  (no database needed)
require __DIR__ . '/../inc/ftm.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }
$people = [23 => 'Riko', 24 => 'Fredy Lukas', 1 => 'Heni', 15 => 'Susi', 17 => 'Yanuar', 9 => 'Hendry', 10 => 'Henry', 21 => 'Andhy', 19 => 'Joshua'];
check('same first name', ftm_suggest('Riko Dewang', $people), 23);
check('Freddy / Fredy', ftm_suggest('Freddy Lukas', $people), 24);
check('Nugraheni / Heni', ftm_suggest('Nugraheni Setyowati', $people), 1);
check('Susilowati / Susi', ftm_suggest('Susilowati', $people), 15);
check('Januar / Yanuar', ftm_suggest('Januar Budiyanto', $people), 17);
check('exact beats near', ftm_suggest('Hendry', $people), 9);
check('no match', ftm_suggest('Djoko Utomo', $people), 0);
check('too short', ftm_suggest('1', $people), 0);
echo "ftm_test: $ok checks passed\n";
