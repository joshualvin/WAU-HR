<?php
// Automatic download for Windows Task Scheduler (see the schtasks line in README / the chat):
//   C:\xampp\php\php.exe C:\xampp\htdocs\WAU-HR\cloud_cli.php [days]
// Downloads the scans that reached Fingerspot.io for every machine with a Cloud ID, through the free Fingerspot Personnel
// API (key in config.php). Without [days] it starts the day before each machine's last clean download (so a missed or failed
// run is caught up by the next one), at most 60 days back; with [days] it covers exactly the last [days] days.
// Fingerspot allows one pull per machine per minute, so a run over several days takes a few minutes. Scans already stored
// are not re-added. Typical schedule: "cloud_cli.php 1" every 5 minutes (today only, one request per machine) and a plain
// "cloud_cli.php" once an hour (catches up anything missed).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cloud.php';

if (cloud_token() === '') { fwrite(STDERR, "fingerspot_api_key is not set in config.php\n"); exit(1); }

// one run at a time: the next scheduled start just leaves while the previous run is still waiting between calls
$lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wauhr_cloud.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('Y-m-d H:i') . " previous run still busy, skipped\n"; exit(0); }

$oldest = date('Y-m-d', strtotime('-59 days'));
$to = date('Y-m-d');
if (isset($argv[1])) {
    $from = date('Y-m-d', strtotime('-' . (max(1, min(60, (int)$argv[1])) - 1) . ' days'));
} else {
    $from = $to;
    foreach (cloud_devices() as $d) {
        $last = $d['last_download_web'] ? date('Y-m-d', strtotime($d['last_download_web'] . ' -1 day')) : date('Y-m-d', strtotime('-2 days'));
        if ($last < $from) $from = $last;
    }
    $from = max($from, $oldest);
}
$results = cloud_download($from, $to, []);
$failed = false;
foreach ($results as $sn => $r) {
    printf("%s %s..%s  %s: %d received, %d new%s\n", date('Y-m-d H:i'), $from, $to, $r['name'], $r['received'], $r['added'],
        $r['errors'] ? '  ERRORS: ' . implode('; ', $r['errors']) : '');
    if (!$r['complete']) $failed = true;
}
audit('cloud_download_auto', "$from..$to: " . array_sum(array_column($results, 'added')) . ' scan baru');
exit($failed ? 1 : 0);
