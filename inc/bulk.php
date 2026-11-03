<?php
// Bulk update of employee data from a CSV (like Talenta's "Bulk update data"): download the current data, edit it in a spreadsheet,
// upload it, check every change in a preview, then apply. The parsing, checking and planning here need no database.

/**
 * The columns a file may have: key => [label, target, kind, max length]. target: 'key' = the row's key, 'p:xxx' = a column of
 * Fingerspot's pegawai table, 'org:jab|dep|kan' = a Jabatan / Departemen / Kantor (applied as a transfer); $ctx also has 'names' => [key => [id => name]] for the preview, otherwise a column of
 * web_emp_profile. kinds: text, date, gender, org, select, digits16, npwp, account, email.
 */
function bulk_columns()
{
    return [
        'pin'          => ['PIN', 'key'],
        'nip'          => ['NIP', 'p:pegawai_nip', 'text', 30],
        'nama'         => ['Nama', 'p:pegawai_nama', 'text', 255],
        'alias'        => ['Nama panggilan', 'p:pegawai_alias', 'text', 50],
        'gender'       => ['Jenis kelamin', 'p:gender', 'gender'],
        'tempat_lahir' => ['Tempat lahir', 'p:tempat_lahir', 'text', 50],
        'tgl_lahir'    => ['Tanggal lahir', 'p:tgl_lahir', 'date'],
        'hp'           => ['No. HP', 'p:pegawai_telp', 'text', 20],
        'tgl_mulai'    => ['Mulai bekerja', 'p:tgl_mulai_kerja', 'date'],
        'jabatan'      => ['Jabatan', 'org:jab', 'org'],
        'departemen'   => ['Departemen', 'org:dep', 'org'],
        'kantor'       => ['Kantor', 'org:kan', 'org'],
        'nik_ktp'      => ['NIK (KTP)', 'nik_ktp', 'digits16'],
        'no_kk'        => ['No. KK', 'no_kk', 'digits16'],
        'npwp'         => ['NPWP', 'npwp', 'npwp'],
        'marital'      => ['Status perkawinan', 'marital', 'select'],
        'religion'     => ['Agama', 'religion', 'select'],
        'education'    => ['Pendidikan', 'education', 'select'],
        'address'      => ['Alamat KTP', 'address', 'text', 255],
        'domicile'     => ['Alamat domisili', 'domicile', 'text', 255],
        'email'        => ['Email', 'email', 'email', 120],
        'bank'         => ['Bank', 'p:nama_bank', 'text', 50],
        'no_rek'       => ['No. rekening', 'p:no_rek', 'account', 20],
        'nama_rek'     => ['Nama di rekening', 'p:nama_rek', 'text', 100],
    ];
}

/** A header reduced for matching: lower case, letters and digits only ("Tanggal Lahir" and "tanggal_lahir" are the same). */
function bulk_norm_header($h)
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($h));
}

/** [normalised header => column key]: the key, the label and a few common names. */
function bulk_header_names()
{
    $map = [];
    foreach (bulk_columns() as $k => $c) { $map[bulk_norm_header($k)] = $k; $map[bulk_norm_header($c[0])] = $k; }
    foreach (['nik' => 'nik_ktp', 'nohp' => 'hp', 'telepon' => 'hp', 'handphone' => 'hp', 'telp' => 'hp', 'agama' => 'religion', 'pendidikan' => 'education', 'pendidikanterakhir' => 'education',
              'statusperkawinan' => 'marital', 'statuskawin' => 'marital', 'alamat' => 'address', 'alamatktp' => 'address', 'norekening' => 'no_rek', 'rekening' => 'no_rek',
              'namakaryawan' => 'nama', 'namalengkap' => 'nama', 'kelamin' => 'gender', 'tglmulaikerja' => 'tgl_mulai', 'tanggalmulai' => 'tgl_mulai', 'tanggalmasuk' => 'tgl_mulai'] as $n => $k) $map[$n] = $k;
    return $map;
}

/** Read CSV text: UTF-8 (BOM allowed) or Windows-1252, comma, semicolon or tab separated. Returns rows of trimmed strings; empty lines are dropped. */
function bulk_parse_csv($text)
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $first = strtok($text, "\n");
    $delim = ',';
    $best = substr_count($first, ',');
    foreach ([';' => ';', "\t" => "\t"] as $d) { $n = substr_count($first, $d); if ($n > $best) { $best = $n; $delim = $d; } }
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if ($r === [null] || !array_filter($r, function ($c) { return trim((string)$c) !== ''; })) continue;
        $rows[] = array_map(function ($c) { return trim((string)$c); }, $r);
    }
    fclose($fh);
    return $rows;
}

/** Which column key each header of the first row is: [index => key] and the headers nobody knows: [map, unknown]. */
function bulk_header_map($headers)
{
    $names = bulk_header_names();
    $map = $unknown = [];
    foreach ($headers as $i => $h) {
        $n = bulk_norm_header($h);
        if ($n === '') continue;
        if (isset($names[$n]) && !in_array($names[$n], $map, true)) $map[$i] = $names[$n]; else $unknown[] = $h;
    }
    return [$map, $unknown];
}

/** A date in Y-m-d, d-m-Y or d/m/Y as Y-m-d, or null. */
function bulk_date($s)
{
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    else return null;
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/**
 * Check one cell and turn it into the form it is stored in. $ctx: 'org' => [key => [lower-case name => id]] and 'opts' => [key => [value => label]].
 * Returns [value, error]; an empty cell is not handled here (the caller leaves such cells unchanged).
 */
function bulk_value($key, $raw, $ctx, $today = null)
{
    $today = $today ?: date('Y-m-d');
    $c = bulk_columns()[$key];
    $kind = $c[2];
    $raw = trim($raw);
    switch ($kind) {
        case 'text':
            if (mb_strlen($raw) > $c[3]) return [null, $c[0] . ' terlalu panjang (maksimal ' . $c[3] . ' huruf)'];
            return [$raw, null];
        case 'date':
            $d = bulk_date($raw);
            if ($d === null) return [null, $c[0] . ' "' . $raw . '" bukan tanggal yang benar (pakai 31-12-2026 atau 2026-12-31)'];
            if ($key === 'tgl_lahir' && ($d > $today || $d < '1920-01-01')) return [null, 'Tanggal lahir ' . $raw . ' tidak masuk akal'];
            return [$d, null];
        case 'gender':
            $g = strtolower($raw);
            if (in_array($g, ['l', 'laki-laki', 'laki laki', 'pria', 'm', 'male', '1'], true)) return [1, null];
            if (in_array($g, ['p', 'perempuan', 'wanita', 'f', 'female', '2'], true)) return [2, null];
            return [null, 'Jenis kelamin "' . $raw . '" harus L atau P'];
        case 'org':
            $id = isset($ctx['org'][$key][strtolower($raw)]) ? $ctx['org'][$key][strtolower($raw)] : null;
            return $id === null ? [null, $c[0] . ' "' . $raw . '" tidak ada di daftar (tambahkan dulu di Pengaturan)'] : [(int)$id, null];
        case 'select':
            foreach (isset($ctx['opts'][$key]) ? $ctx['opts'][$key] : [] as $v => $label) if (strtolower($raw) === strtolower($v) || strtolower($raw) === strtolower($label)) return [(string)$v, null];
            return [null, $c[0] . ' "' . $raw . '" tidak dikenal (pilihan: ' . implode(', ', array_values(isset($ctx['opts'][$key]) ? $ctx['opts'][$key] : [])) . ')'];
        case 'digits16':
        case 'npwp':
            if (preg_match('/e\+?\d/i', $raw) && preg_match('/^[\d.,]+e/i', $raw)) return [null, $c[0] . ' dirusak Excel (jadi ' . $raw . '): ubah format kolom menjadi Teks lalu isi ulang'];
            $d = preg_replace('/[\s.\-\'"]/', '', $raw);
            if (!ctype_digit($d)) return [null, $c[0] . ' hanya boleh angka'];
            if ($kind === 'digits16' && strlen($d) !== 16) return [null, $c[0] . ' harus 16 angka (ada ' . strlen($d) . ')'];
            if ($kind === 'npwp' && !in_array(strlen($d), [15, 16], true)) return [null, 'NPWP harus 15 atau 16 angka (ada ' . strlen($d) . ')'];
            return [$d, null];
        case 'account':
            $d = preg_replace('/[\s\'"]/', '', $raw);
            if (!preg_match('/^[0-9\-]{1,20}$/', $d)) return [null, 'No. rekening hanya angka (maksimal 20)'];
            return [$d, null];
        case 'email':
            if (mb_strlen($raw) > $c[3] || !filter_var($raw, FILTER_VALIDATE_EMAIL)) return [null, 'Email "' . $raw . '" tidak valid'];
            return [$raw, null];
    }
    return [$raw, null];
}

/** How a stored value reads to a person (the preview). */
function bulk_show($key, $v, $ctx)
{
    if ($v === null || $v === '' || $v === 0 || $v === '0') return '—';
    $kind = bulk_columns()[$key][2];
    if ($kind === 'date') return date('d-m-Y', strtotime($v));
    if ($kind === 'gender') return (int)$v === 2 ? 'Perempuan' : 'Laki-laki';
    if ($kind === 'org') return isset($ctx['names'][$key][(int)$v]) ? $ctx['names'][$key][(int)$v] : '#' . (int)$v;
    if ($kind === 'select') return isset($ctx['opts'][$key][$v]) ? $ctx['opts'][$key][$v] : (string)$v;
    return (string)$v;
}

/**
 * Plan a bulk update. $rows: parsed CSV (first row = headers). $current: [pin => ['id' => pegawai_id, 'cur' => [column key => stored value]]].
 * Returns ['rows' => [...], 'unknown' => [ignored headers], 'known' => [column keys in the file], 'counts' => [...], 'error' => string|null].
 * A row: ['line', 'pin', 'id', 'name', 'changes' => [[key, label, old, new, target, value]], 'errors' => [..]].
 */
function bulk_plan($rows, $current, $ctx, $today = null)
{
    $out = ['rows' => [], 'unknown' => [], 'known' => [], 'counts' => ['rows' => 0, 'changes' => 0, 'errors' => 0, 'unchanged' => 0], 'error' => null];
    if (count($rows) < 2) { $out['error'] = 'File kosong: harus ada baris judul dan minimal satu baris data.'; return $out; }
    list($map, $unknown) = bulk_header_map($rows[0]);
    $out['unknown'] = $unknown;
    $pinCol = array_search('pin', $map, true);
    if ($pinCol === false) { $out['error'] = 'Kolom PIN tidak ada. PIN dipakai untuk mengenali karyawan.'; return $out; }
    $out['known'] = array_values(array_filter($map, function ($k) { return $k !== 'pin'; }));
    if (!$out['known']) { $out['error'] = 'Tidak ada kolom data yang dikenal selain PIN.'; return $out; }
    $seen = [];
    foreach (array_slice($rows, 1, 2000, true) as $i => $r) {
        $pin = isset($r[$pinCol]) ? trim($r[$pinCol]) : '';
        $row = ['line' => $i + 1, 'pin' => $pin, 'id' => null, 'name' => '', 'changes' => [], 'errors' => []];
        if ($pin === '') $row['errors'][] = 'PIN kosong';
        elseif (isset($seen[$pin])) $row['errors'][] = 'PIN ' . $pin . ' muncul dua kali di file';
        elseif (!isset($current[$pin])) $row['errors'][] = 'PIN ' . $pin . ' tidak ada di data karyawan';
        $seen[$pin] = true;
        if (isset($current[$pin])) { $row['id'] = $current[$pin]['id']; $row['name'] = (string)$current[$pin]['cur']['nama']; }
        foreach ($map as $idx => $key) {
            if ($key === 'pin') continue;
            $cell = isset($r[$idx]) ? trim($r[$idx]) : '';
            if ($cell === '') continue;   // empty = leave as it is
            list($val, $err) = bulk_value($key, $cell, $ctx, $today);
            if ($err !== null) { $row['errors'][] = $err; continue; }
            if (!isset($current[$pin])) continue;
            $old = isset($current[$pin]['cur'][$key]) ? $current[$pin]['cur'][$key] : null;
            if ((string)$old === (string)$val) continue;
            $row['changes'][] = [$key, bulk_columns()[$key][0], bulk_show($key, $old, $ctx), bulk_show($key, $val, $ctx), bulk_columns()[$key][1], $val];
        }
        $out['counts']['rows']++;
        if ($row['errors']) $out['counts']['errors']++;
        elseif ($row['changes']) $out['counts']['changes']++;
        else $out['counts']['unchanged']++;
        $out['rows'][] = $row;
    }
    return $out;
}

// ---------- database ----------

/** Name and option lookups for bulk_plan(): org maps (lower-case name => id), names (id => name) and the select options of the personal data. */
function bulk_context()
{
    $ctx = ['org' => [], 'names' => [], 'opts' => []];
    foreach (['jabatan' => 'pembagian1', 'departemen' => 'pembagian2', 'kantor' => 'pembagian3'] as $key => $table) {
        $ctx['names'][$key] = lookup($table);
        foreach ($ctx['names'][$key] as $id => $name) { $l = strtolower(trim($name)); if (!isset($ctx['org'][$key][$l])) $ctx['org'][$key][$l] = (int)$id; }
    }
    $pf = profile_fields();
    foreach (['marital', 'religion', 'education'] as $k) $ctx['opts'][$k] = $pf[$k][2];
    return $ctx;
}

/** Everybody's current data in the shape bulk_plan() compares: [pin => ['id', 'cur' => [column key => value]]]. */
function bulk_current()
{
    $out = [];
    foreach (q('SELECT p.*, x.nik_ktp, x.no_kk, x.npwp, x.marital, x.religion, x.education, x.address, x.domicile, x.email
                FROM pegawai p LEFT JOIN web_emp_profile x ON x.pegawai_id = p.pegawai_id') as $r) {
        $date = function ($d) { return $d && $d !== '0000-00-00' ? substr($d, 0, 10) : ''; };
        $out[(string)$r['pegawai_pin']] = ['id' => (int)$r['pegawai_id'], 'status' => (int)$r['pegawai_status'], 'cur' => [
            'nip' => (string)$r['pegawai_nip'], 'nama' => (string)$r['pegawai_nama'], 'alias' => (string)$r['pegawai_alias'], 'gender' => (int)$r['gender'] === 2 ? 2 : 1,
            'tempat_lahir' => (string)$r['tempat_lahir'], 'tgl_lahir' => $date($r['tgl_lahir']), 'hp' => (string)$r['pegawai_telp'], 'tgl_mulai' => $date($r['tgl_mulai_kerja']),
            'jabatan' => (int)$r['pembagian1_id'], 'departemen' => (int)$r['pembagian2_id'], 'kantor' => (int)$r['pembagian3_id'],
            'nik_ktp' => (string)$r['nik_ktp'], 'no_kk' => (string)$r['no_kk'], 'npwp' => (string)$r['npwp'], 'marital' => (string)$r['marital'], 'religion' => (string)$r['religion'],
            'education' => (string)$r['education'], 'address' => (string)$r['address'], 'domicile' => (string)$r['domicile'], 'email' => (string)$r['email'],
            'bank' => (string)$r['nama_bank'], 'no_rek' => (string)$r['no_rek'], 'nama_rek' => (string)$r['nama_rek']]];
    }
    return $out;
}

/** The CSV the person edits: a header row and one row per active employee, values written the way the import reads them. */
function bulk_export_rows($ctx)
{
    $cols = bulk_columns();
    $rows = [array_map(function ($c) { return $c[0]; }, array_values($cols))];
    $current = bulk_current();
    $pins = array_keys(array_filter($current, function ($c) { return $c['status'] === 1; }));
    usort($pins, function ($a, $b) use ($current) { return strcasecmp($current[$a]['cur']['nama'], $current[$b]['cur']['nama']); });
    foreach ($pins as $pin) {
        $line = [];
        foreach ($cols as $k => $c) {
            if ($k === 'pin') { $line[] = (string)$pin; continue; }
            $v = $current[$pin]['cur'][$k];
            if ($k === 'gender') $line[] = (int)$v === 2 ? 'P' : 'L';
            elseif ($c[2] === 'date') $line[] = $v !== '' ? date('d-m-Y', strtotime($v)) : '';
            elseif ($c[2] === 'org' || $c[2] === 'select') $line[] = $v ? bulk_show($k, $v, $ctx) : '';   // no Kantor (id 0) is an empty cell, not "0"
            else $line[] = (string)$v;
        }
        $rows[] = $line;
    }
    return $rows;
}

/**
 * Apply a plan: rows with errors or no changes are skipped. Personal and Fingerspot data go through save_profile(); a changed Jabatan,
 * Departemen or Kantor is recorded as a transfer ("Penyesuaian data", effective today) so it appears in the history.
 * Returns ['rows' => n, 'fields' => n, 'moves' => n, 'failed' => [messages]].
 */
function bulk_apply($plan)
{
    ensure_transfer_tables();
    $done = ['rows' => 0, 'fields' => 0, 'moves' => 0, 'failed' => []];
    foreach ($plan['rows'] as $r) {
        if ($r['errors'] || !$r['changes'] || !$r['id']) continue;
        $data = [];
        $org = ['jab' => '', 'dep' => '', 'kan' => '', 'atasan' => ''];
        foreach ($r['changes'] as $c) {
            if (strpos($c[4], 'org:') === 0) $org[substr($c[4], 4)] = (string)$c[5];
            else $data[$c[4]] = $c[5];
        }
        if ($data) save_profile($r['id'], $data);
        if (array_filter($org, 'strlen')) {
            list($tid, $err) = transfer_create(['pegawai_id' => $r['id'], 'type' => 'penyesuaian', 'effective' => date('Y-m-d'), 'note' => 'Update data massal'] + $org, actor());
            if ($err) { $done['failed'][] = $r['name'] . ': ' . $err; continue; }
            $done['moves']++;
        }
        $done['rows']++;
        $done['fields'] += count($r['changes']);
        audit('bulk_update', $r['name'] . ': ' . implode(', ', array_map(function ($c) { return $c[1]; }, $r['changes'])));
    }
    return $done;
}
