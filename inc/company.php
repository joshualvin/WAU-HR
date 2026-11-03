<?php
// Company: announcements and the calendar, the org chart (each employee's supervisor, web_emp_profile.atasan_id,
// also used for request approvals) and assets lent to employees.

function ensure_company_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_announcement (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, body TEXT NOT NULL,
        event_date DATE NULL, show_until DATE NULL, pinned TINYINT NOT NULL DEFAULT 0,
        created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_asset (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL DEFAULT '', name VARCHAR(120) NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT '', serial VARCHAR(60) NOT NULL DEFAULT '', bought DATE NULL, value BIGINT NOT NULL DEFAULT 0,
        pegawai_id INT NULL, assigned_at DATE NULL, condition_note VARCHAR(120) NOT NULL DEFAULT 'Baik', note VARCHAR(255) NOT NULL DEFAULT '',
        active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_asset_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, asset_id INT UNSIGNED NOT NULL, pegawai_id INT NULL,
        action VARCHAR(10) NOT NULL, at DATE NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '', created_by VARCHAR(50) NOT NULL DEFAULT '', KEY asset (asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function asset_categories()
{
    return ['Laptop / komputer', 'HP', 'Kendaraan', 'Seragam', 'Kunci / akses', 'ID card', 'Alat kerja', 'Lainnya'];
}

/** Announcements to show on $date: not expired, pinned first, newest first. */
function current_announcements($date, $limit = 10)
{
    return q('SELECT * FROM web_announcement WHERE (show_until IS NULL OR show_until >= ?) ORDER BY pinned DESC, created_at DESC LIMIT ' . (int)$limit, [$date])->fetchAll();
}

/**
 * The calendar from $from for $days days: [[date, kind, title], ...] sorted, kinds holiday / event / birthday /
 * anniversary (full years of service).
 */
function company_calendar($from, $days = 60)
{
    $to = date('Y-m-d', strtotime("$from +$days days"));
    $out = [];
    foreach (q('SELECT libur_tgl, libur_keterangan, libur_status FROM libur WHERE libur_tgl BETWEEN ? AND ?', [$from, $to]) as $r)
        $out[] = [$r['libur_tgl'], (int)$r['libur_status'] === 2 ? 'leave' : 'holiday', $r['libur_keterangan']];
    foreach (q('SELECT event_date, title FROM web_announcement WHERE event_date BETWEEN ? AND ?', [$from, $to]) as $r) $out[] = [$r['event_date'], 'event', $r['title']];
    foreach (q('SELECT pegawai_nama, tgl_lahir, tgl_mulai_kerja FROM pegawai WHERE pegawai_status = 1') as $e) {
        foreach (['tgl_lahir' => 'birthday', 'tgl_mulai_kerja' => 'anniversary'] as $col => $kind) {
            if (!$e[$col] || $e[$col] === '0000-00-00') continue;
            $md = substr($e[$col], 5, 5);
            foreach ([substr($from, 0, 4), substr($to, 0, 4)] as $y) {
                $d = "$y-$md";
                if ($d < $from || $d > $to || isset($seen["$kind|{$e['pegawai_nama']}|$d"])) continue;
                $seen["$kind|{$e['pegawai_nama']}|$d"] = true;
                $years = (int)$y - (int)substr($e[$col], 0, 4);
                if ($kind === 'anniversary' && $years < 1) continue;
                $out[] = [$d, $kind, $kind === 'birthday' ? 'Ulang tahun ' . $e['pegawai_nama'] : $e['pegawai_nama'] . ' ' . $years . ' tahun bekerja'];
            }
        }
    }
    usort($out, function ($a, $b) { return strcmp($a[0] . $a[1], $b[0] . $b[1]); });
    return $out;
}

function calendar_kind_label($k)
{
    $l = ['holiday' => ['bad', 'Libur'], 'leave' => ['warn', 'Cuti bersama'], 'event' => ['ok', 'Acara'], 'birthday' => ['grey', 'Ulang tahun'], 'anniversary' => ['grey', 'Masa kerja']];
    return $l[$k];
}

// ---------- org chart ----------

/** Supervisor of every employee who has one: [pegawai_id => atasan_id]. */
function supervisors()
{
    return q('SELECT pegawai_id, atasan_id FROM web_emp_profile WHERE atasan_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** True when giving $id the supervisor $boss would make a loop (boss reports, directly or not, to $id). */
function supervisor_loop($id, $boss, $map)
{
    $seen = [];
    for ($b = $boss; $b; $b = isset($map[$b]) ? $map[$b] : null) {
        if ((int)$b === (int)$id || isset($seen[$b])) return true;
        $seen[$b] = true;
    }
    return false;
}

/** Direct and indirect reports of $boss. */
function all_reports($boss, $map)
{
    $out = [];
    $queue = [$boss];
    while ($queue) {
        $b = array_shift($queue);
        foreach ($map as $id => $s) if ((int)$s === (int)$b && !isset($out[$id])) { $out[$id] = true; $queue[] = $id; }
    }
    return array_keys($out);
}

// ---------- month calendar ----------

/** First day of the month of $ym ('Y-m'), falling back to the month of $fallback (Y-m-d) when $ym is not a month. */
function calendar_month($ym, $fallback)
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$ym) ? $ym . '-01' : date('Y-m-01', strtotime($fallback));
}

/**
 * The weeks of a month for a grid, Monday first: a list of weeks, each a list of 7 dates (Y-m-d) from the Monday on or before the 1st
 * to the Sunday on or after the last day. $first = first day of the month.
 */
function calendar_weeks($first)
{
    $last = date('Y-m-t', strtotime($first));
    $d = date('Y-m-d', strtotime($first . ' -' . ((int)date('N', strtotime($first)) - 1) . ' days'));
    $weeks = [];
    while ($d <= $last) {
        $w = [];
        for ($i = 0; $i < 7; $i++) { $w[] = $d; $d = date('Y-m-d', strtotime($d . ' +1 day')); }
        $weeks[] = $w;
    }
    return $weeks;
}
