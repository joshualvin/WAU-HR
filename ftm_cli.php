<?php
// Automatic FTM sync for Windows Task Scheduler (e.g. every 15 minutes):
//   C:\xampp\php\php.exe C:\xampp\htdocs\WAU-HR\ftm_cli.php [days]
// Copies the scans of mapped FTM PINs for the last [days] days (default 3), replacing earlier copies of those days.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ftm.php';

$days = max(1, min(366, isset($argv[1]) ? (int)$argv[1] : 3));
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
try {
    ensure_ftm_tables();
    list($del, $add) = ftm_sync($from, date('Y-m-d'));
} catch (Exception $e) {
    fwrite(STDERR, date('Y-m-d H:i') . ' FTM sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("%s FTM from %s: %d scans copied (replacing %d)\n", date('Y-m-d H:i'), $from, $add, $del);
