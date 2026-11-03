<?php
// Reprimands: verbal warning (teguran lisan) and warning letters SP1-SP3. PP 35/2021 art. 52: a warning letter is
// valid for at most 6 months unless the work agreement / company regulation says otherwise. A new letter while an
// earlier one is still valid goes one level up; SP3 can be grounds for termination.

function ensure_discipline_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_warning (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        level VARCHAR(6) NOT NULL,
        number VARCHAR(40) NOT NULL DEFAULT '',
        issued DATE NOT NULL,
        valid_until DATE NOT NULL,
        violation VARCHAR(200) NOT NULL,
        description TEXT NULL,
        issued_by VARCHAR(50) NOT NULL DEFAULT '',
        signer VARCHAR(100) NOT NULL DEFAULT '',
        acknowledged_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY emp (pegawai_id, issued)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function warning_levels()
{
    return ['lisan' => 'Teguran lisan', 'sp1' => 'SP1', 'sp2' => 'SP2', 'sp3' => 'SP3'];
}

function warning_titles()
{
    return ['lisan' => 'Catatan Teguran Lisan', 'sp1' => 'Surat Peringatan Pertama (SP-1)', 'sp2' => 'Surat Peringatan Kedua (SP-2)', 'sp3' => 'Surat Peringatan Ketiga (SP-3)'];
}

/** What follows if the employee offends again while the warning is valid. */
function warning_consequence($level)
{
    $c = ['lisan' => 'Apabila pelanggaran terulang, perusahaan akan memberikan Surat Peringatan Pertama (SP-1).',
          'sp1' => 'Apabila dalam masa berlaku surat ini Saudara kembali melakukan pelanggaran, perusahaan akan memberikan Surat Peringatan Kedua (SP-2).',
          'sp2' => 'Apabila dalam masa berlaku surat ini Saudara kembali melakukan pelanggaran, perusahaan akan memberikan Surat Peringatan Ketiga (SP-3).',
          'sp3' => 'Ini adalah peringatan terakhir. Apabila dalam masa berlaku surat ini Saudara kembali melakukan pelanggaran, perusahaan dapat melakukan pemutusan hubungan kerja sesuai ketentuan yang berlaku (PP 35/2021).'];
    return $c[$level];
}

/**
 * The level a new reprimand should get: one above the highest warning letter still valid on $date (verbal warnings
 * do not count), SP1 when none is. $active = [level, ...] of the employee's letters valid on $date.
 */
function next_warning_level($active)
{
    $rank = ['sp1' => 1, 'sp2' => 2, 'sp3' => 3];
    $top = 0;
    foreach ($active as $l) if (isset($rank[$l])) $top = max($top, $rank[$l]);
    return 'sp' . min(3, $top + 1);
}

/** Letters of an employee still valid on $date. */
function active_warnings($pegawaiId, $date)
{
    return q('SELECT * FROM web_warning WHERE pegawai_id = ? AND issued <= ? AND valid_until >= ? ORDER BY issued', [$pegawaiId, $date, $date])->fetchAll();
}

/** Letter number: 001/SP1/HR/XI/2026, counted per year over all letters. */
function warning_number($level, $date)
{
    $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $year = substr($date, 0, 4);
    $n = 1 + (int)q('SELECT COUNT(*) FROM web_warning WHERE YEAR(issued) = ?', [$year])->fetchColumn();
    return sprintf('%03d/%s/HR/%s/%s', $n, strtoupper($level === 'lisan' ? 'TL' : $level), $roman[(int)substr($date, 5, 2) - 1], $year);
}

function warning_pill($w, $today = null)
{
    $today = $today ?: date('Y-m-d');
    $cls = ['lisan' => 'grey', 'sp1' => 'warn', 'sp2' => 'warn', 'sp3' => 'bad'][$w['level']];
    $active = $w['valid_until'] >= $today;
    return '<span class="pill ' . ($active ? $cls : 'grey') . '">' . h(warning_levels()[$w['level']]) . ($active ? '' : ' · berakhir') . '</span>';
}
