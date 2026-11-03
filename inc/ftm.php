<?php
// Head-office scans from Fingerspot Time Management (FTM, database `ftm` on this MySQL server) into this app's
// att_log, so attendance, reports and payroll see them. FTM has its own PINs: each FTM PIN is mapped to an employee
// here (web_ftm_map), and copied scans get that employee's PIN and sn 'FTM' + the FTM machine (FTM0 = FTM's manual
// corrections). A sync replaces the copied scans of its date range, so corrections and deletions in FTM come through.
// config.php: ftm_db (default 'ftm'; '' = off).

const FTM_SN = 'FTM';

function ftm_db_name()
{
    global $CONFIG;
    return isset($CONFIG['ftm_db']) ? (string)$CONFIG['ftm_db'] : 'ftm';
}

/** The FTM database's name quoted for SQL, or null when it is off or not on this server. */
function ftm_db()
{
    static $ok = null;
    $name = ftm_db_name();
    if ($ok === null) $ok = $name !== '' && preg_match('/^\w+$/', $name) && q('SHOW DATABASES LIKE ?', [$name])->fetch();
    return $ok ? "`$name`" : null;
}

function ensure_ftm_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_ftm_map (
        ftm_pin VARCHAR(32) NOT NULL PRIMARY KEY,
        pegawai_id INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** FTM employees: [pin, name, active] (emp_status 0 = Aktif). */
function ftm_employees()
{
    $F = ftm_db();
    $out = [];
    foreach (q("SELECT pin, TRIM(CONCAT_WS(' ', first_name, last_name)) name, emp_status FROM $F.emp ORDER BY pin + 0") as $r) {
        $out[] = ['pin' => (string)$r['pin'], 'name' => (string)$r['name'], 'active' => (int)$r['emp_status'] === 0];
    }
    return $out;
}

/**
 * The employee an FTM name most likely is, by first name: the same, one inside the other (Heni / Nugraheni,
 * Susi / Susilowati, at least 4 letters) or nearly the same (Fredy / Freddy, Yanuar / Januar). $people = [id => name].
 * Returns a pegawai_id or 0. Only a suggestion: HR confirms it.
 */
function ftm_suggest($ftmName, $people)
{
    $first = function ($n) { $w = preg_split('/\s+/', strtolower(trim($n))); return $w[0]; };
    $f = $first($ftmName);
    if (strlen($f) < 3) return 0;
    $best = 0;
    $score = 0;
    foreach ($people as $id => $name) {
        $p = $first($name);
        if (strtolower(trim($name)) === strtolower(trim($ftmName))) $s = 100;
        elseif ($p === $f) $s = 90;
        elseif (strlen($p) >= 4 && strlen($f) >= 4 && (strpos($f, $p) !== false || strpos($p, $f) !== false)) $s = 70;
        else { similar_text($p, $f, $pct); $s = $pct >= 80 ? 60 : 0; }
        if ($s > $score) { $score = $s; $best = (int)$id; }
    }
    return $best;
}

/** Scans per FTM PIN since $from, and the newest scan in FTM. */
function ftm_scan_counts($from)
{
    $F = ftm_db();
    return [q("SELECT pin, COUNT(*) FROM $F.att_log WHERE scan_date >= ? GROUP BY pin", [$from])->fetchAll(PDO::FETCH_KEY_PAIR),
            q("SELECT MAX(scan_date) FROM $F.att_log WHERE sn <> '0'")->fetchColumn()];
}

/** Copy the scans of mapped FTM PINs for $from..$to (dates), replacing what an earlier sync copied. Returns [removed, added]. */
function ftm_sync($from, $to)
{
    $F = ftm_db();
    if (!$F) throw new RuntimeException('Database FTM tidak ditemukan.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $del = q("DELETE FROM att_log WHERE sn LIKE 'FTM%' AND scan_date BETWEEN ? AND ?", $range)->rowCount();
        $add = q("INSERT IGNORE INTO att_log (sn, scan_date, pin, verifymode, inoutmode, reserved, work_code, att_id)
                  SELECT CONCAT('" . FTM_SN . "', a.sn), a.scan_date, p.pegawai_pin, a.verify_mode, a.io_mode, 0, a.work_code, CONCAT('ftm:', a.att_id)
                  FROM $F.att_log a JOIN web_ftm_map m ON m.ftm_pin = a.pin JOIN pegawai p ON p.pegawai_id = m.pegawai_id
                  WHERE a.scan_date BETWEEN ? AND ?", $range)->rowCount();
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$del, $add];
}
