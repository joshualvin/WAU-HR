<?php
// On call (HR → Waktu → On call): who is on standby on which day. One row per person and day; a range is assigned in one go.

function ensure_oncall_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_oncall (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, pegawai_id INT NOT NULL, day DATE NOT NULL, note VARCHAR(120) NOT NULL DEFAULT '',
        created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, UNIQUE KEY person_day (pegawai_id, day), KEY d (day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Every date from $from to $to (Y-m-d), inclusive; with $skipSunday the Sundays are left out. */
function oncall_dates($from, $to, $skipSunday = false)
{
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) if (!$skipSunday || date('N', strtotime($d)) != 7) $out[] = $d;
    return $out;
}

/** Check an assignment: employees (ids), from / to (Y-m-d). Returns an error message or null. At most 92 days at a time. */
function oncall_validate($ids, $from, $to)
{
    if (!$ids) return 'Pilih minimal satu karyawan.';
    foreach ([$from, $to] as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) || !strtotime($d)) return 'Isi tanggal yang benar.';
    if ($to < $from) return 'Tanggal akhir tidak boleh sebelum tanggal mulai.';
    if (count(oncall_dates($from, $to)) > 92) return 'Paling lama 92 hari sekali jalan.';
    return null;
}

/** Assign people to days. Returns how many new person-days were added (days they already had are left alone). */
function oncall_assign($ids, $from, $to, $note, $by, $skipSunday = false)
{
    $ins = db()->prepare('INSERT IGNORE INTO web_oncall (pegawai_id, day, note, created_by, created_at) VALUES (?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($ids as $id) foreach (oncall_dates($from, $to, $skipSunday) as $d) {
        $ins->execute([(int)$id, $d, mb_substr((string)$note, 0, 120), $by, date('Y-m-d H:i:s')]);
        $n += $ins->rowCount();
    }
    return $n;
}

/** The roster between two dates: [day => [[id, pegawai_id, name, note], ...]]. */
function oncall_month($from, $to)
{
    $out = [];
    foreach (q('SELECT o.id, o.pegawai_id, o.day, o.note, p.pegawai_nama FROM web_oncall o JOIN pegawai p ON p.pegawai_id = o.pegawai_id WHERE o.day BETWEEN ? AND ? ORDER BY o.day, p.pegawai_nama', [$from, $to]) as $r)
        $out[$r['day']][] = ['id' => (int)$r['id'], 'pegawai_id' => (int)$r['pegawai_id'], 'name' => $r['pegawai_nama'], 'note' => $r['note']];
    return $out;
}
