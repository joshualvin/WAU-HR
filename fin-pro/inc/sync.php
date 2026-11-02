<?php
// Copy new scans from Fingerspot Personnel's own MySQL (port 3309, where the program downloads machine and
// cloud scans) into this app's database. Insert-only: a row already here is skipped by its primary key,
// nothing is updated or deleted on either side.
// shortcut: deletions/edits made in the program are not copied, only new rows; add a compare step if that matters.

function sync_db()
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $c = function ($k, $d) use ($CONFIG) { return isset($CONFIG[$k]) && $CONFIG[$k] !== '' ? $CONFIG[$k] : $d; };
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', $c('sync_host', '127.0.0.1'), (int)$c('sync_port', 3309), $c('sync_db', 'fin_pro'));
        $pdo = new PDO($dsn, $c('sync_user', 'root'), isset($CONFIG['sync_pass']) ? $CONFIG['sync_pass'] : '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10,
        ]);
    }
    return $pdo;
}

/** Copy rows of one table with $dateCol >= $from. Returns [rows read, rows added]. */
function sync_table($table, $dateCol, $from)
{
    $st = sync_db()->prepare("SELECT * FROM $table WHERE $dateCol >= ?");
    $st->execute([$from]);
    $ins = null;
    $read = $added = 0;
    while ($r = $st->fetch()) {
        if (!$ins) {
            $cols = array_keys($r);
            $ins = db()->prepare("INSERT IGNORE INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        }
        $ins->execute(array_values($r));
        $read++;
        $added += $ins->rowCount();
    }
    return [$read, $added];
}

/** Scans (att_log) and forgotten-scan / leave entries (izin) from $from onwards. */
function sync_from_program($from)
{
    @set_time_limit(600);
    return [
        'att_log' => sync_table('att_log', 'scan_date', $from),
        'izin'    => sync_table('izin', 'izin_tgl', $from),
    ];
}

/** Newest scan on each side, to show how far behind this app is. */
function sync_status()
{
    $here = q('SELECT MAX(scan_date) FROM att_log WHERE sn NOT IN (\'100\',\'101\',\'102\',\'103\',\'104\',\'105\')')->fetchColumn();
    $there = sync_db()->query("SELECT MAX(scan_date) FROM att_log WHERE sn NOT IN ('100','101','102','103','104','105')")->fetchColumn();
    return [$here, $there];
}
