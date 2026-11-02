<?php
// Onboarding / offboarding checklists and performance reviews (talent.php, Portal Pegawai).
//
// Checklists: a template per type lists tasks with who does them (HR, Karyawan, Atasan) and a due day relative to
// the start; "Mulai" copies the template to one employee. Reviews: a cycle has weighted criteria; each employee
// rates themselves in the portal and HR gives the scores that count (1-5); the final score is the weighted average.

const TASK_WHO = ['HR', 'Karyawan', 'Atasan'];

function ensure_talent_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_checklist_tpl (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(10) NOT NULL,
        item VARCHAR(200) NOT NULL,
        who VARCHAR(10) NOT NULL DEFAULT 'HR',
        due_days INT NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_checklist (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        type VARCHAR(10) NOT NULL,
        item VARCHAR(200) NOT NULL,
        who VARCHAR(10) NOT NULL,
        due_date DATE NULL,
        done_at DATETIME NULL,
        done_by VARCHAR(50) NOT NULL DEFAULT '',
        sort INT NOT NULL DEFAULT 0,
        KEY emp (pegawai_id, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_review_cycle (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        period_from DATE NOT NULL,
        period_to DATE NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        criteria TEXT NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_review (
        cycle_id INT UNSIGNED NOT NULL,
        pegawai_id INT NOT NULL,
        self_scores TEXT NULL,
        self_note TEXT NULL,
        self_at DATETIME NULL,
        mgr_scores TEXT NULL,
        mgr_note TEXT NULL,
        mgr_by VARCHAR(50) NOT NULL DEFAULT '',
        mgr_at DATETIME NULL,
        final_score DECIMAL(4,2) NULL,
        PRIMARY KEY (cycle_id, pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!q('SELECT COUNT(*) FROM web_checklist_tpl')->fetchColumn()) {
        $defaults = [
            'onboarding' => [['Siapkan kontrak kerja dan tanda tangan', 'HR', -1], ['Daftarkan PIN di mesin absensi dan rekam sidik jari', 'HR', 0],
                             ['Daftarkan BPJS Kesehatan dan Ketenagakerjaan', 'HR', 7], ['Isi data gaji, PTKP dan rekening bank', 'HR', 3],
                             ['Buat kode login Portal Pegawai', 'HR', 0], ['Serahkan KTP, NPWP, KK dan buku tabungan', 'Karyawan', 3],
                             ['Baca peraturan perusahaan', 'Karyawan', 7], ['Perkenalan tim dan orientasi tempat kerja', 'Atasan', 1],
                             ['Evaluasi masa percobaan', 'Atasan', 90]],
            'offboarding' => [['Terima surat pengunduran diri / keputusan PHK', 'HR', 0], ['Serah terima pekerjaan', 'Atasan', 7],
                              ['Kembalikan seragam, kunci, peralatan dan ID card', 'Karyawan', 14], ['Hapus PIN dari mesin absensi', 'HR', 14],
                              ['Hitung gaji terakhir, sisa cuti dan pesangon', 'HR', 14], ['Nonaktifkan BPJS', 'HR', 30],
                              ['Buat surat keterangan kerja (paklaring)', 'HR', 14], ['Exit interview', 'HR', 7]],
        ];
        foreach ($defaults as $type => $items) {
            foreach ($items as $i => $it) q('INSERT INTO web_checklist_tpl (type, item, who, due_days, sort) VALUES (?, ?, ?, ?, ?)', [$type, $it[0], $it[1], $it[2], $i]);
        }
    }
}

function default_criteria()
{
    return [
        ['name' => 'Kualitas kerja', 'weight' => 25],
        ['name' => 'Produktivitas dan target', 'weight' => 25],
        ['name' => 'Kehadiran dan disiplin', 'weight' => 20],
        ['name' => 'Kerja sama tim', 'weight' => 15],
        ['name' => 'Inisiatif dan tanggung jawab', 'weight' => 15],
    ];
}

/** Weighted average (1-5) of the scores given for the criteria; null if any criterion has no score. */
function weighted_score($criteria, $scores)
{
    $sum = $w = 0;
    foreach ($criteria as $i => $c) {
        if (!isset($scores[$i]) || $scores[$i] < 1 || $scores[$i] > 5) return null;
        $sum += $scores[$i] * $c['weight'];
        $w += $c['weight'];
    }
    return $w ? round($sum / $w, 2) : null;
}

function rating_label($score)
{
    if ($score === null) return '';
    if ($score >= 4.5) return 'Sangat baik';
    if ($score >= 3.5) return 'Baik';
    if ($score >= 2.5) return 'Cukup';
    if ($score >= 1.5) return 'Kurang';
    return 'Buruk';
}

function score_scale()
{
    return [1 => 'Buruk', 2 => 'Kurang', 3 => 'Cukup', 4 => 'Baik', 5 => 'Sangat baik'];
}

/** Copy a checklist template to one employee, due dates counted from $start. Returns the number of tasks added. */
function start_checklist($pegawaiId, $type, $start)
{
    $n = 0;
    foreach (q('SELECT * FROM web_checklist_tpl WHERE type = ? ORDER BY sort, id', [$type]) as $t) {
        q('INSERT INTO web_checklist (pegawai_id, type, item, who, due_date, sort) VALUES (?, ?, ?, ?, ?, ?)',
            [$pegawaiId, $type, $t['item'], $t['who'], date('Y-m-d', strtotime($start . ' ' . ($t['due_days'] >= 0 ? '+' : '') . (int)$t['due_days'] . ' days')), $t['sort']]);
        $n++;
    }
    return $n;
}

/** Attendance figures for a review period, shown next to the review as reference. */
function review_attendance($pegawaiId, $from, $to)
{
    $c = ['workdays' => 0, 'present' => 0, 'late' => 0, 'late_min' => 0, 'absent' => 0, 'leave' => 0];
    foreach (daily_attendance($from, min($to, date('Y-m-d')), $pegawaiId) as $r) {
        if (!$r['off']) $c['workdays']++;
        if ($r['first'] || $r['last']) $c['present']++;
        if ($r['late']) { $c['late']++; $c['late_min'] += $r['late']; }
        if ($r['status'] === 'absent') $c['absent']++;
        if ($r['leave']) $c['leave']++;
    }
    return $c;
}
