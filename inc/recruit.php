<?php
// Recruitment: job openings, candidates moving through stages, the public careers page and manpower planning.

const CV_DIR = __DIR__ . '/../uploads/cv';

function ensure_recruit_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_job (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        pembagian1_id INT NOT NULL DEFAULT 0,
        pembagian2_id INT NOT NULL DEFAULT 0,
        pembagian3_id INT NOT NULL DEFAULT 0,
        headcount INT NOT NULL DEFAULT 1,
        employment VARCHAR(30) NOT NULL DEFAULT 'Karyawan tetap',
        description TEXT NOT NULL,
        requirements TEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        close_date DATE NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_candidate (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(120) NOT NULL DEFAULT '',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        cv_file VARCHAR(80) NOT NULL DEFAULT '',
        cover TEXT NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'Halaman karir',
        stage VARCHAR(12) NOT NULL DEFAULT 'applied',
        rating TINYINT NOT NULL DEFAULT 0,
        interview_at DATETIME NULL,
        pegawai_id INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY job (job_id, stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_candidate_note (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        candidate_id INT UNSIGNED NOT NULL,
        note TEXT NOT NULL,
        created_by VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY cand (candidate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_manpower (
        tahun INT NOT NULL,
        pembagian2_id INT NOT NULL,
        planned INT NOT NULL DEFAULT 0,
        PRIMARY KEY (tahun, pembagian2_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function stages()
{
    return ['applied' => 'Lamaran masuk', 'screening' => 'Seleksi berkas', 'interview' => 'Wawancara', 'offer' => 'Penawaran', 'hired' => 'Diterima', 'rejected' => 'Ditolak'];
}

function stage_badge($stage)
{
    $cls = ['applied' => 'grey', 'screening' => 'off', 'interview' => 'warn', 'offer' => 'warn', 'hired' => 'ok', 'rejected' => 'bad'];
    return '<span class="pill ' . (isset($cls[$stage]) ? $cls[$stage] : 'grey') . '">' . h(isset(stages()[$stage]) ? stages()[$stage] : $stage) . '</span>';
}

function employment_types()
{
    return ['Karyawan tetap', 'Kontrak', 'Harian lepas', 'Magang'];
}

function save_cv($upload)
{
    return save_upload($upload, CV_DIR, ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'], 'CV', 'PDF atau foto (JPG, PNG)');
}

/**
 * Turn a hired candidate into an employee: next free PIN, the job's position / department / office, the first work
 * schedule from the start date, and the onboarding checklist. Returns the new pegawai_id.
 */
function hire_candidate($cand, $job, $start)
{
    ensure_talent_tables(); // before the transaction: CREATE TABLE commits it
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $id = (int)q('SELECT COALESCE(MAX(pegawai_id), 0) + 1 FROM pegawai FOR UPDATE')->fetchColumn();
        $pin = (string)((int)q('SELECT MAX(CAST(pegawai_pin AS UNSIGNED)) FROM pegawai')->fetchColumn() + 1);
        q('INSERT INTO pegawai (pegawai_id, pegawai_pin, pegawai_nip, pegawai_nama, pegawai_alias, pegawai_telp, pegawai_status, gender,
                                pembagian1_id, pembagian2_id, pembagian3_id, tgl_mulai_kerja, tgl_masuk_pertama)
           VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?, ?)',
            [$id, $pin, $pin, $cand['name'], mb_substr($cand['name'], 0, 50), mb_substr($cand['phone'], 0, 20),
             $job['pembagian1_id'], $job['pembagian2_id'], $job['pembagian3_id'], $start, $start]);
        q('INSERT IGNORE INTO pegawai_d (pegawai_id) VALUES (?)', [$id]);
        $sched = q('SELECT jdw_kerja_m_id FROM jdw_kerja_m ORDER BY jdw_kerja_m_id LIMIT 1')->fetchColumn();
        if ($sched) q('INSERT IGNORE INTO jdw_kerja_pegawai (pegawai_id, jdw_kerja_m_id, jdw_kerja_m_mulai) VALUES (?, ?, ?)', [$id, $sched, $start]);
        q('UPDATE web_candidate SET stage = ?, pegawai_id = ?, updated_at = ? WHERE id = ?', ['hired', $id, date('Y-m-d H:i:s'), $cand['id']]);
        start_checklist($id, 'onboarding', $start);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}
