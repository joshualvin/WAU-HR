<?php
// Automatic download for Windows Task Scheduler, like the program's download times:
//   C:\xampp\php\php.exe C:\xampp\htdocs\fin-pro\cloud_cli.php [days]
// Downloads the last [days] days (default 2) for every machine with a Cloud ID.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cloud.php';

if (cloud_token() === '') { fwrite(STDERR, "fingerspot_token is not set in config.php\n"); exit(1); }
$days = max(1, min(60, isset($argv[1]) ? (int)$argv[1] : 2));
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$to = date('Y-m-d');
$results = cloud_download($from, $to);
$failed = false;
foreach ($results as $sn => $r) {
    printf("%s %s  %s: %d received, %d new%s\n", date('Y-m-d H:i'), $from, $r['name'], $r['received'], $r['added'],
        $r['errors'] ? '  ERRORS: ' . implode('; ', $r['errors']) : '');
    if ($r['errors'] && !$r['received']) $failed = true;
}
audit('cloud_download_auto', "$from..$to: " . array_sum(array_column($results, 'added')) . ' scan baru');
exit($failed ? 1 : 0);
