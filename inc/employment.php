<?php
// Employment status (status kepegawaian): permanent (PKWTT, optional probation), contract (PKWT), daily worker or
// intern. Each contract or change is a row, so the history stays; an employee's current status is their latest row.

function ensure_employment_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_employment (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        status VARCHAR(10) NOT NULL,
        contract_no VARCHAR(60) NOT NULL DEFAULT '',
        start_date DATE NOT NULL,
        end_date DATE NULL,
        probation_end DATE NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        created_by VARCHAR(50) NOT NULL DEFAULT '',
        KEY emp (pegawai_id, start_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function employment_statuses()
{
    return ['tetap' => 'Karyawan tetap (PKWTT)', 'kontrak' => 'Kontrak (PKWT)', 'harian' => 'Harian lepas', 'magang' => 'Magang'];
}

/** Statuses that run until an end date. */
function employment_needs_end($status)
{
    return in_array($status, ['kontrak', 'magang'], true);
}

/** Map a job's employment type text (also from the careers site) to a status key. */
function employment_from_text($text)
{
    $t = strtolower($text);
    if (strpos($t, 'kontrak') !== false || strpos($t, 'contract') !== false || (strpos($t, 'pkwt') !== false && strpos($t, 'pkwtt') === false)) return 'kontrak';
    if (strpos($t, 'harian') !== false || strpos($t, 'daily') !== false || strpos($t, 'freelance') !== false) return 'harian';
    if (strpos($t, 'magang') !== false || strpos($t, 'intern') !== false) return 'magang';
    return 'tetap';
}

/** Current status of every employee who has one: pegawai_id => row. */
function current_employment()
{
    $out = [];
    foreach (q('SELECT * FROM web_employment ORDER BY start_date, id') as $r) $out[$r['pegawai_id']] = $r;
    return $out;
}

/**
 * Check a status row before saving. Returns an error message or null.
 * PP 35/2021: a PKWT lasts at most 5 years including extensions; probation is only for PKWTT, at most 3 months.
 */
function employment_error($status, $start, $end, $probation, $pegawaiId = null)
{
    if (!isset(employment_statuses()[$status])) return 'Pilih status kepegawaian.';
    if (!valid_date($start)) return 'Isi tanggal mulai.';
    if (employment_needs_end($status) && !valid_date($end)) return 'Isi tanggal berakhir kontrak.';
    if ($end && valid_date($end) && $end < $start) return 'Tanggal berakhir sebelum tanggal mulai.';
    if ($probation) {
        if ($status !== 'tetap') return 'Masa percobaan hanya untuk karyawan tetap (PP 35/2021).';
        if ($probation < $start || $probation > date('Y-m-d', strtotime($start . ' +3 months'))) return 'Masa percobaan paling lama 3 bulan dari tanggal mulai.';
    }
    if ($status === 'kontrak' && $pegawaiId) {
        // all contracts in a row up to this one, without a permanent period in between
        $first = $start;
        foreach (array_reverse(q('SELECT status, start_date FROM web_employment WHERE pegawai_id = ? AND start_date < ? ORDER BY start_date', [$pegawaiId, $start])->fetchAll()) as $r) {
            if ($r['status'] !== 'kontrak') break;
            $first = $r['start_date'];
        }
        if ($end > date('Y-m-d', strtotime($first . ' +5 years -1 day'))) return 'PKWT paling lama 5 tahun termasuk perpanjangan (sejak ' . date('d-m-Y', strtotime($first)) . ').';
    }
    return null;
}

function save_employment($pegawaiId, $status, $contractNo, $start, $end, $probation, $note)
{
    q('INSERT INTO web_employment (pegawai_id, status, contract_no, start_date, end_date, probation_end, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$pegawaiId, $status, mb_substr($contractNo, 0, 60), $start, employment_needs_end($status) || $end ? ($end ?: null) : null, $probation ?: null, mb_substr($note, 0, 255), date('Y-m-d H:i:s'), actor()]);
}

/** Short status label with the date that matters, as a pill. */
function employment_pill($r, $today = null)
{
    $today = $today ?: date('Y-m-d');
    if (!$r) return '<span class="pill grey">Belum diisi</span>';
    $names = ['tetap' => 'Tetap', 'kontrak' => 'Kontrak', 'harian' => 'Harian lepas', 'magang' => 'Magang'];
    $label = $names[$r['status']];
    $cls = 'ok';
    if ($r['probation_end'] && $r['probation_end'] >= $today) { $label .= ' · percobaan s/d ' . date('d-m-Y', strtotime($r['probation_end'])); $cls = 'warn'; }
    if ($r['end_date']) {
        $label .= ' s/d ' . date('d-m-Y', strtotime($r['end_date']));
        if ($r['end_date'] < $today) $cls = 'bad';
        elseif ($r['end_date'] <= date('Y-m-d', strtotime($today . ' +30 days'))) $cls = 'warn';
    }
    return '<span class="pill ' . $cls . '">' . h($label) . '</span>';
}

/** Active employees whose contract or probation ends within $days (or a contract that already ended). */
function employment_alerts($days = 30)
{
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime("+$days days"));
    $cur = current_employment();
    $out = [];
    foreach (employees(true) as $e) {
        $r = isset($cur[$e['pegawai_id']]) ? $cur[$e['pegawai_id']] : null;
        if (!$r) continue;
        if ($r['end_date'] && $r['end_date'] <= $limit) $out[] = ['emp' => $e, 'what' => $r['end_date'] < $today ? 'Kontrak sudah berakhir' : 'Kontrak berakhir', 'date' => $r['end_date']];
        if ($r['probation_end'] && $r['probation_end'] >= $today && $r['probation_end'] <= $limit) $out[] = ['emp' => $e, 'what' => 'Masa percobaan selesai', 'date' => $r['probation_end']];
    }
    usort($out, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    return $out;
}
