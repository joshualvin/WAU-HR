<?php
// Automatic sync for Windows Task Scheduler (e.g. every 15 minutes):
//   C:\xampp\php\php.exe C:\xampp\htdocs\fin-pro\sync_cli.php [days]
// Copies new rows of the last [days] days (default 3) from the program's database (port 3309).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sync.php';

$days = max(1, min(366, isset($argv[1]) ? (int)$argv[1] : 3));
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
try {
    $r = sync_from_program($from);
} catch (Exception $e) {
    fwrite(STDERR, date('Y-m-d H:i') . ' sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("%s from %s: %d scans read, %d new; %d izin read, %d new\n", date('Y-m-d H:i'), $from, $r['att_log'][0], $r['att_log'][1], $r['izin'][0], $r['izin'][1]);
if ($r['att_log'][1] || $r['izin'][1]) audit('sync_program_auto', "dari $from: {$r['att_log'][1]} scan, {$r['izin'][1]} izin baru");
