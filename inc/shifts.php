<?php
// Shift changes and swaps, stored in Fingerspot's own tables so the program reads them too:
// "Ganti Jam Kerja per Pegawai" = ganti_jk_pegawai (one row per change) + ganti_jk_d (one row per date, jns_ganti_jk 2),
// "Tukar Jam" = tukar_jam. Swap requests from the portal wait in web_shift_swap until HR approves them.

function ensure_shift_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_shift_swap (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tgl DATE NOT NULL,
        pegawai_id INT NOT NULL,
        with_id INT NOT NULL,
        alasan VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        decided_by VARCHAR(50) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/**
 * Set the shift of employees for a date range. $shift is a jk_id, 'off' (day off) or 'schedule' (remove changes,
 * follow the work schedule again). Returns the number of employee-days changed.
 */
function set_shift($pegawaiIds, $from, $to, $shift, $note)
{
    $pegawaiIds = array_values(array_unique(array_map('intval', $pegawaiIds)));
    if (!$pegawaiIds) return 0;
    $in = implode(',', $pegawaiIds);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("DELETE FROM ganti_jk_d WHERE jns_ganti_jk = 2 AND pegawai_id IN ($in) AND tgl_ganti_jk BETWEEN ? AND ?", [$from, $to]);
        $days = 0;
        if ($shift !== 'schedule') {
            $jk = $shift === 'off' ? 0 : (int)$shift;
            foreach ($pegawaiIds as $pid) {
                $id = 1 + (int)q('SELECT GREATEST(COALESCE((SELECT MAX(ganti_jk_id) FROM ganti_jk_pegawai), 0), COALESCE((SELECT MAX(ganti_jk_id) FROM ganti_jk_d), 0),
                                                  COALESCE((SELECT MAX(ganti_jk_id) FROM ganti_jk), 0))')->fetchColumn();
                q('INSERT INTO ganti_jk_pegawai (ganti_jk_id, pegawai_id, tgl_awal, tgl_akhir, jk_id, keterangan) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $pid, $from, $to, $jk, mb_substr($note, 0, 200)]);
                for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    q('INSERT INTO ganti_jk_d (ganti_jk_id, tgl_ganti_jk, jns_ganti_jk, jk_id, pegawai_id, libur) VALUES (?, ?, 2, ?, ?, ?)',
                        [$id, $d, $jk, $pid, $shift === 'off' ? 1 : 0]);
                    $days++;
                }
            }
        } else {
            $days = count($pegawaiIds) * (1 + (int)((strtotime($to) - strtotime($from)) / 86400));
        }
        // headers whose dates were all replaced
        q('DELETE h FROM ganti_jk_pegawai h LEFT JOIN ganti_jk_d d ON d.ganti_jk_id = h.ganti_jk_id WHERE d.ganti_jk_id IS NULL');
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $days;
}

/** Record an approved swap (Tukar Jam): both employees work each other's scheduled shift on that date. */
function save_swap($date, $a, $b, $reason)
{
    q('DELETE FROM tukar_jam WHERE tukar_tgl = ? AND (pegawai_id1 IN (?, ?) OR pegawai_id2 IN (?, ?))', [$date, $a, $b, $a, $b]);
    q('INSERT INTO tukar_jam (tukar_tgl, pegawai_id1, pegawai_id2, alasan) VALUES (?, ?, ?, ?)', [$date, $a, $b, mb_substr($reason, 0, 255)]);
}

/** Short label and colour for a day's shift in the calendar. */
function shift_cell($pegawaiId, $date)
{
    list($jk, $off) = work_hours_for($pegawaiId, $date);
    $change = shift_change_for($pegawaiId, $date);
    if ($jk) {
        $label = $jk['jk_kode'] ?: $jk['jk_name'];
        $palette = ['#e9ecfc', '#e2f4ea', '#fdf0dd', '#eee9fb', '#e6f4f7', '#fde8e6'];
        $bg = $palette[((int)$jk['jk_id'] - 1) % count($palette)];
        $title = $jk['jk_name'] . ' ' . min_to_hm($jk['start']) . '–' . min_to_hm($jk['end']);
    } else {
        $label = 'Libur';
        $bg = '#f1f2f4';
        $title = 'Libur';
    }
    if ($change) $title .= isset($change['swap']) ? ' (tukar jam)' : ' (diubah' . ($change['note'] !== '' ? ': ' . $change['note'] : '') . ')';
    return ['label' => $label, 'bg' => $bg, 'title' => $title, 'changed' => (bool)$change, 'swap' => $change && isset($change['swap'])];
}
