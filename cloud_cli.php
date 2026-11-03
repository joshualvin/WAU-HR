<?php
// Automatic download for Windows Task Scheduler (see the schtasks line in README / the chat):
//   C:\xampp\php\php.exe C:\xampp\htdocs\WAU-HR\cloud_cli.php [days]
// Downloads the scans that reached Fingerspot.io for every machine with a Cloud ID, through the free Fingerspot Personnel
// API (key in config.php). Without [days] it starts at each machine's last covered day: normally today, so a run is one burst
// of requests; after a night off or a failed run the next run catches up the missed days (a minute per day), at most 60 days
// back. With [days] it covers exactly the last [days] days.
// Fingerspot allows one burst of requests a minute for the whole account: all machines are asked together, then the next day
// waits a minute. Scans already stored are not re-added. Schedule: plain "cloud_cli.php" every 5 minutes, all day; outside the
// scan windows (07:00-10:30 and 14:00-19:30, Monday-Saturday, config 'fingerspot_windows'; all day on the 20th, the attendance
// count day) it exits at once.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cloud.php';

// only when employees are likely to scan (cloud_in_window, Jakarta time, so the Windows clock zone does not matter); an explicit
// [days] argument is a manual run and always goes
if (!isset($argv[1]) && !cloud_in_window(time())) exit(0);

// transfers (mutasi) dated up to today take effect here too, so they do not wait for somebody to open the HR pages
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
require __DIR__ . '/inc/transfer.php';
ensure_people_tables();
ensure_transfer_tables();
transfers_apply_due();

if (cloud_token() === '') { fwrite(STDERR, "fingerspot_api_key is not set in config.php\n"); exit(1); }

// one run at a time: the next scheduled start just leaves while the previous run is still waiting between calls
$lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wauhr_cloud.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('Y-m-d H:i') . " previous run still busy, skipped\n"; exit(0); }

$oldest = date('Y-m-d', strtotime('-59 days'));
$to = date('Y-m-d');
q('UPDATE device SET last_download_web = NULL WHERE last_download_web > ?', [$to]);   // written by a clock that ran ahead
if (isset($argv[1])) {
    $from = date('Y-m-d', strtotime('-' . (max(1, min(60, (int)$argv[1])) - 1) . ' days'));
} else {
    $from = $to;
    foreach (cloud_devices() as $d) {
        $last = $d['last_download_web'] ? $d['last_download_web'] : date('Y-m-d', strtotime('-2 days'));
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
