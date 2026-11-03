<?php
// Automatic download for Windows Task Scheduler, like the program's download times:
//   C:\xampp\php\php.exe C:\xampp\htdocs\WAU-HR\cloud_cli.php [days]
// Downloads what reached Fingerspot.io in the last [days] days (default 2) for every machine with a Cloud ID, via the
// Fingerspot Developer API (the Personnel token). Data is kept 60 days; already-stored scans are not re-added.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cloud.php';

if (cloud_token() === '') { fwrite(STDERR, "fingerspot_api_key is not set in config.php\n"); exit(1); }
$days = max(1, min(60, isset($argv[1]) ? (int)$argv[1] : 2));
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$to = date('Y-m-d');
$results = cloud_download($from, $to, []);
$failed = false;
foreach ($results as $sn => $r) {
    printf("%s %s  %s: %d received, %d new%s\n", date('Y-m-d H:i'), $from, $r['name'], $r['received'], $r['added'],
        $r['errors'] ? '  ERRORS: ' . implode('; ', $r['errors']) : '');
    if ($r['errors'] && !$r['received']) $failed = true;
}
audit('cloud_download_auto', "$from..$to: " . array_sum(array_column($results, 'added')) . ' scan baru');
exit($failed ? 1 : 0);
