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
        responsibilities TEXT NULL,
        requirements TEXT NOT NULL,
        dept_text VARCHAR(60) NOT NULL DEFAULT '',
        loc_text VARCHAR(60) NOT NULL DEFAULT '',
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
        ext_id VARCHAR(64) NULL,
        cv_ext VARCHAR(64) NOT NULL DEFAULT '',
        ai_score TINYINT NULL,
        ai_note TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY job (job_id, stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if (!q("SHOW COLUMNS FROM web_candidate LIKE 'details'")->fetch()) db()->exec('ALTER TABLE web_candidate ADD details TEXT NULL');   // applicant details as JSON, copied to the employee when hired
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
    // columns added for the careers-site link
    $add = ['web_job' => ['responsibilities' => 'TEXT NULL', 'dept_text' => "VARCHAR(60) NOT NULL DEFAULT ''", 'loc_text' => "VARCHAR(60) NOT NULL DEFAULT ''"],
            'web_candidate' => ['ext_id' => 'VARCHAR(64) NULL', 'cv_ext' => "VARCHAR(64) NOT NULL DEFAULT ''", 'ai_score' => 'TINYINT NULL', 'ai_note' => 'TEXT NULL']];
    foreach ($add as $t => $cols) {
        $have = q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c => $def) if (!in_array($c, $have, true)) db()->exec("ALTER TABLE $t ADD $c $def");
    }
}

/** Candidates whose position matches no job here (careers site "Other", or a removed job) have job_id 0. */
function general_job()
{
    return ['id' => 0, 'title' => 'Lamaran umum', 'jab' => '', 'dep' => '', 'kan' => '', 'pembagian1_id' => 0, 'pembagian2_id' => 0, 'pembagian3_id' => 0,
            'employment' => '', 'headcount' => PHP_INT_MAX, 'status' => 'closed', 'close_date' => null];
}

function score_pill($score)
{
    if ($score === null || $score === '') return '';
    $cls = $score >= 75 ? 'ok' : ($score >= 50 ? 'warn' : 'grey');
    return '<span class="pill ' . $cls . '" title="Skor kecocokan AI dari situs karir">AI ' . (int)$score . '</span>';
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
 * schedule from the start date, the employment status from the job's type (a contract ends on $end, default one
 * year) and the onboarding checklist. Returns the new pegawai_id.
 */
function hire_candidate($cand, $job, $start, $end = null)
{
    ensure_talent_tables(); // before the transaction: CREATE TABLE commits it
    ensure_employment_tables();
    $status = employment_from_text($job['employment']);
    if (employment_needs_end($status) && !$end) $end = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
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
        save_employment($id, $status, '', $start, employment_needs_end($status) ? $end : null, null, 'Dari rekrutmen: ' . $job['title']);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

// ---------- applicant details (the fields HR needs for the employee record and payroll) ----------

require_once __DIR__ . '/people.php';

/** Applicant detail fields: key => [label, type, options]. Keys are those of profile_fields(), plus 'gender'. The public form uses the first six. */
function candidate_detail_fields()
{
    $pf = profile_fields();
    return ['gender' => ['Jenis kelamin', 'select', [1 => 'Laki-laki', 2 => 'Perempuan']],
            'p:tgl_lahir' => $pf['p:tgl_lahir'], 'p:tempat_lahir' => $pf['p:tempat_lahir'], 'address' => $pf['address'], 'education' => $pf['education'],
            'marital' => $pf['marital'], 'dependants' => $pf['dependants'], 'nik_ktp' => $pf['nik_ktp'], 'npwp' => $pf['npwp']];
}

/** Valid values from a form (key => value, only the $keys given; empty and invalid values are dropped). */
function candidate_clean_details($in, $keys)
{
    $out = [];
    foreach (candidate_detail_fields() as $k => $f) {
        $fk = str_replace(':', '_', $k);
        if (!in_array($k, $keys, true) || !isset($in[$fk])) continue;
        $v = trim((string)$in[$fk]);
        if ($v === '') continue;
        if ($f[1] === 'select' && !isset($f[2][$v])) continue;
        if ($f[1] === 'date' && !valid_date($v)) continue;
        if ($f[1] === 'number') $v = (string)max(0, min(9, (int)$v));
        if ($k === 'nik_ktp' || $k === 'npwp') { $v = preg_replace('/\D/', '', $v); if (!in_array(strlen($v), $k === 'nik_ktp' ? [16] : [15, 16], true)) continue; }
        $out[$k] = mb_substr($v, 0, 255);
    }
    return $out;
}

function candidate_details($cand)
{
    $d = isset($cand['details']) && $cand['details'] ? json_decode($cand['details'], true) : [];
    return is_array($d) ? $d : [];
}

/** PTKP from marital status and dependants: K/n when married, otherwise TK/n (n up to 3). */
function ptkp_from_status($marital, $dependants)
{
    return ($marital === 'kawin' ? 'K' : 'TK') . '/' . min(3, max(0, (int)$dependants));
}

/** Copy the applicant's details to the new employee: personal profile, gender, and PTKP / NPWP for payroll. */
function apply_candidate_details($pegawaiId, $cand)
{
    $d = candidate_details($cand);
    if (!$d) return;
    if (isset($d['gender'])) { q('UPDATE pegawai SET gender = ? WHERE pegawai_id = ?', [(int)$d['gender'], $pegawaiId]); unset($d['gender']); }
    if ($d) save_profile($pegawaiId, $d);
    if (isset($d['marital']) || isset($d['npwp'])) {
        require_once __DIR__ . '/payroll.php';
        ensure_payroll_tables();
        q('INSERT INTO web_pay_emp (pegawai_id, ptkp, npwp) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ptkp = VALUES(ptkp), npwp = VALUES(npwp)',
            [$pegawaiId, ptkp_from_status(isset($d['marital']) ? $d['marital'] : '', isset($d['dependants']) ? $d['dependants'] : 0), isset($d['npwp']) ? $d['npwp'] : '']);
    }
}
