<?php
// Employee transfers (mutasi / promosi): a change of Jabatan, Departemen, Kantor or direct supervisor with an effective date and a
// history. Applied to pegawai (pembagian1-3) and web_emp_profile.atasan_id on the effective date.

function ensure_transfer_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_transfer (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, pegawai_id INT NOT NULL, type VARCHAR(12) NOT NULL, effective_date DATE NOT NULL,
        from_jab INT NULL, from_dep INT NULL, from_kan INT NULL, from_atasan INT NULL,
        to_jab INT NULL, to_dep INT NULL, to_kan INT NULL, to_atasan INT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '', doc_id INT NULL, status VARCHAR(10) NOT NULL DEFAULT 'scheduled',
        created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, applied_at DATETIME NULL,
        KEY emp (pegawai_id), KEY due (status, effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function transfer_types()
{
    return ['promosi' => 'Promosi', 'mutasi' => 'Mutasi', 'rotasi' => 'Rotasi', 'demosi' => 'Demosi', 'penyesuaian' => 'Penyesuaian data'];
}

function transfer_statuses()
{
    return ['scheduled' => ['Terjadwal', 'warn'], 'applied' => ['Berlaku', 'ok'], 'cancelled' => ['Dibatalkan', 'grey']];
}

/** What a transfer can change: key => label (the pegawai column is pembagian1/2/3_id, the supervisor lives in web_emp_profile). */
function transfer_fields()
{
    return ['jab' => 'Jabatan', 'dep' => 'Departemen', 'kan' => 'Kantor', 'atasan' => 'Atasan langsung'];
}

/** Transaction id shown to people: MUT-2026-0007. */
function transfer_code($id, $created)
{
    return 'MUT-' . date('Y', strtotime($created)) . '-' . str_pad((string)(int)$id, 4, '0', STR_PAD_LEFT);
}

/** Is a scheduled transfer due on $today? */
function transfer_is_due($effective, $today)
{
    return $effective <= $today;
}

/**
 * The changes of a transfer row: [[label, from name, to name], ...] for every field with a target that differs from the starting value.
 * $names: ['jab' => [id => name], 'dep' => [...], 'kan' => [...], 'atasan' => [...]]. A supervisor target of 0 means "none".
 */
function transfer_changes($row, $names)
{
    $out = [];
    foreach (transfer_fields() as $k => $label) {
        $to = $row['to_' . $k];
        if ($to === null || $to === '') continue;
        $from = $row['from_' . $k];
        if ((int)$to === (int)$from) continue;
        $name = function ($id) use ($names, $k) { return $id === null || $id === '' || (int)$id === 0 ? '—' : (isset($names[$k][(int)$id]) ? $names[$k][(int)$id] : '#' . (int)$id); };
        $out[] = [$label, $name($from), $name($to)];
    }
    return $out;
}

/**
 * Check a new transfer. $in: pegawai_id, type, effective (Y-m-d), jab, dep, kan, atasan (id, '' = unchanged, atasan 0 = remove).
 * $cur: the employee's current ['status' => pegawai_status, 'jab', 'dep', 'kan', 'atasan'] or null when not found. $supers: [pegawai_id => atasan_id]
 * for the loop check. Returns an error message or null.
 */
function transfer_validate($in, $cur, $today, $supers = [])
{
    if (!$cur) return 'Pilih karyawan.';
    if ((int)$cur['status'] !== 1) return 'Hanya karyawan aktif yang bisa dimutasi.';
    if (!isset(transfer_types()[$in['type']])) return 'Pilih jenis mutasi.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['effective']) || !strtotime($in['effective'])) return 'Isi tanggal berlaku yang benar.';
    if ($in['effective'] < date('Y-m-d', strtotime($today . ' -1 year'))) return 'Tanggal berlaku tidak boleh lebih dari 1 tahun yang lalu.';
    if ($in['effective'] > date('Y-m-d', strtotime($today . ' +2 years'))) return 'Tanggal berlaku terlalu jauh ke depan.';
    $changed = false;
    foreach (transfer_fields() as $k => $label) {
        $to = $in[$k];
        if ($to === '' || $to === null) continue;
        $curVal = $cur[$k] === null ? 0 : (int)$cur[$k];
        if ((int)$to === $curVal) continue;
        $changed = true;
    }
    if (!$changed) return 'Tidak ada yang berubah dari data karyawan saat ini.';
    if ($in['atasan'] !== '' && $in['atasan'] !== null && (int)$in['atasan'] > 0) {
        if ((int)$in['atasan'] === (int)$in['pegawai_id']) return 'Karyawan tidak bisa menjadi atasannya sendiri.';
        if (function_exists('supervisor_loop') && supervisor_loop((int)$in['pegawai_id'], (int)$in['atasan'], $supers)) return 'Atasan itu berada di bawah karyawan ini, jadi membentuk lingkaran.';
    }
    return null;
}

// ---------- database ----------

/** The employee's current Jabatan, Departemen, Kantor, supervisor and status, or null. */
function transfer_current($pegawaiId)
{
    $r = q('SELECT p.pegawai_status status, p.pembagian1_id jab, p.pembagian2_id dep, p.pembagian3_id kan, x.atasan_id atasan
            FROM pegawai p LEFT JOIN web_emp_profile x ON x.pegawai_id = p.pegawai_id WHERE p.pegawai_id = ?', [(int)$pegawaiId])->fetch();
    return $r ?: null;
}

/** Create a transfer; applied at once when it is already due. Returns [id, error]. */
function transfer_create($in, $by, $docId = null)
{
    $cur = transfer_current($in['pegawai_id']);
    $err = transfer_validate($in, $cur, date('Y-m-d'), function_exists('supervisors') ? supervisors() : []);
    if ($err) return [0, $err];
    $to = function ($k) use ($in) { return $in[$k] === '' || $in[$k] === null ? null : (int)$in[$k]; };
    q('INSERT INTO web_transfer (pegawai_id, type, effective_date, from_jab, from_dep, from_kan, from_atasan, to_jab, to_dep, to_kan, to_atasan, note, doc_id, status, created_by, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(int)$in['pegawai_id'], $in['type'], $in['effective'], $cur['jab'], $cur['dep'], $cur['kan'], $cur['atasan'], $to('jab'), $to('dep'), $to('kan'), $to('atasan'),
         mb_substr((string)$in['note'], 0, 255), $docId, 'scheduled', $by, date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    if (transfer_is_due($in['effective'], date('Y-m-d'))) transfer_apply($id);
    return [$id, null];
}

/** Apply one scheduled transfer now: pegawai columns and supervisor, status 'applied'. Returns true when applied. */
function transfer_apply($id)
{
    $t = q("SELECT * FROM web_transfer WHERE id = ? AND status = 'scheduled'", [(int)$id])->fetch();
    if (!$t) return false;
    $cur = transfer_current($t['pegawai_id']);
    if (!$cur) return false;
    $note = $t['note'];
    $set = [];
    $params = [];
    foreach (['jab' => 'pembagian1_id', 'dep' => 'pembagian2_id', 'kan' => 'pembagian3_id'] as $k => $col) {
        if ($t['to_' . $k] !== null) { $set[] = "$col = ?"; $params[] = (int)$t['to_' . $k]; }
    }
    if ($set) q('UPDATE pegawai SET ' . implode(', ', $set) . ' WHERE pegawai_id = ?', array_merge($params, [(int)$t['pegawai_id']]));
    if ($t['to_atasan'] !== null) {
        $boss = (int)$t['to_atasan'];
        if ($boss > 0 && function_exists('supervisor_loop') && supervisor_loop((int)$t['pegawai_id'], $boss, supervisors())) {
            $note = trim($note . ' [atasan tidak diterapkan: membentuk lingkaran]');   // the org chart changed since it was planned
        } else {
            q('INSERT INTO web_emp_profile (pegawai_id, atasan_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE atasan_id = VALUES(atasan_id)', [(int)$t['pegawai_id'], $boss > 0 ? $boss : null]);
        }
    }
    // the starting values are those at the moment it took effect
    q("UPDATE web_transfer SET status = 'applied', applied_at = ?, note = ?, from_jab = ?, from_dep = ?, from_kan = ?, from_atasan = ? WHERE id = ?",
        [date('Y-m-d H:i:s'), mb_substr($note, 0, 255), $cur['jab'], $cur['dep'], $cur['kan'], $cur['atasan'], (int)$t['id']]);
    audit('transfer_apply', transfer_code($t['id'], $t['created_at']) . ' pegawai ' . (int)$t['pegawai_id']);
    return true;
}

/** Apply every scheduled transfer whose day has come, oldest first. Returns how many. */
function transfers_apply_due($today = null)
{
    $today = $today ?: date('Y-m-d');
    $n = 0;
    foreach (q("SELECT id FROM web_transfer WHERE status = 'scheduled' AND effective_date <= ? ORDER BY effective_date, id", [$today])->fetchAll(PDO::FETCH_COLUMN) as $id) if (transfer_apply($id)) $n++;
    return $n;
}

/** Cancel a transfer that has not taken effect yet. */
function transfer_cancel($id)
{
    $t = q("SELECT id, created_at FROM web_transfer WHERE id = ? AND status = 'scheduled'", [(int)$id])->fetch();
    if (!$t) return false;
    q("UPDATE web_transfer SET status = 'cancelled' WHERE id = ?", [(int)$id]);
    audit('transfer_cancel', transfer_code($t['id'], $t['created_at']));
    return true;
}

// ---------- display ----------

/** Name lookups for transfer_changes(): ['jab' => [id => name], 'dep' => ..., 'kan' => ..., 'atasan' => [pegawai_id => name]]. */
function transfer_names()
{
    return ['jab' => lookup('pembagian1'), 'dep' => lookup('pembagian2'), 'kan' => lookup('pembagian3'),
            'atasan' => q('SELECT pegawai_id, pegawai_nama FROM pegawai')->fetchAll(PDO::FETCH_KEY_PAIR)];
}

/** The changes of a transfer as one line of text: "Jabatan: Staff → Supervisor; Departemen: Gudang → IT". */
function transfer_summary($row, $names)
{
    return implode('; ', array_map(function ($c) { return $c[0] . ': ' . $c[1] . ' → ' . $c[2]; }, transfer_changes($row, $names)));
}
