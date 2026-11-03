<?php
// First-level approval by the employee's direct supervisor (web_emp_profile.atasan_id, set on the org chart).
// The supervisor approves or rejects in Portal Pegawai → Tim saya; a rejection rejects the request outright, an
// approval is shown to HR, who makes the final decision. Employees without a supervisor go straight to HR.

function ensure_approval_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_approval (
        kind VARCHAR(10) NOT NULL, ref VARCHAR(60) NOT NULL, approver_id INT NOT NULL, decision VARCHAR(10) NOT NULL,
        note VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL, PRIMARY KEY (kind, ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function approval_kinds()
{
    return ['izin' => 'Cuti / izin', 'overtime' => 'Lembur', 'reimburse' => 'Reimbursement', 'loan' => 'Kasbon', 'swap' => 'Tukar shift'];
}

/** Reference of an izin row that stays the same when HR approves it (izin_status changes then). */
function izin_ref($r)
{
    return implode('|', [(int)$r['pegawai_id'], $r['izin_tgl'], (int)$r['izin_jenis_id'], (int)$r['izin_urutan']]);
}

function approval_of($kind, $ref)
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (q('SELECT a.*, p.pegawai_nama FROM web_approval a LEFT JOIN pegawai p ON p.pegawai_id = a.approver_id') as $r) $all[$r['kind'] . '#' . $r['ref']] = $r;
    }
    return isset($all["$kind#$ref"]) ? $all["$kind#$ref"] : null;
}

function supervisor_name($pegawaiId)
{
    static $names = null;
    if ($names === null) $names = q('SELECT x.pegawai_id, p.pegawai_nama FROM web_emp_profile x JOIN pegawai p ON p.pegawai_id = x.atasan_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    return isset($names[$pegawaiId]) ? $names[$pegawaiId] : null;
}

/** For HR lists: what the supervisor decided, or that it is still waiting for them ('' without a supervisor). */
function supervisor_badge($kind, $ref, $pegawaiId)
{
    $a = approval_of($kind, (string)$ref);
    if ($a) return '<span class="pill ' . ($a['decision'] === 'approved' ? 'ok' : 'bad') . '" title="' . h($a['note']) . '">' . ($a['decision'] === 'approved' ? '✓ ' : '✗ ') . 'atasan ' . h($a['pegawai_nama']) . '</span>';
    $boss = supervisor_name($pegawaiId);
    return $boss ? '<span class="pill warn">menunggu atasan ' . h($boss) . '</span>' : '';
}

/** Pending requests of $bossId's direct reports that the supervisor has not decided yet. */
function team_pending($bossId)
{
    $team = q('SELECT x.pegawai_id FROM web_emp_profile x JOIN pegawai p ON p.pegawai_id = x.pegawai_id WHERE x.atasan_id = ? AND p.pegawai_status = 1', [$bossId])->fetchAll(PDO::FETCH_COLUMN);
    if (!$team) return [];
    $in = implode(',', array_map('intval', $team));
    $out = [];
    foreach (q("SELECT i.*, p.pegawai_nama, j.izin_jenis_name FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id
                WHERE i.pegawai_id IN ($in) AND i.izin_status = 0 AND i.ket_status = ? AND i.izin_jenis_id NOT BETWEEN 100 AND 105 ORDER BY i.izin_tgl", [REQ_PENDING]) as $r)
        $out[] = ['izin', izin_ref($r), $r['pegawai_nama'], $r['izin_jenis_name'], fmt_date($r['izin_tgl']) . ($r['izin_catatan'] ? ' · ' . $r['izin_catatan'] : '')];
    foreach (q("SELECT o.*, p.pegawai_nama FROM web_overtime o JOIN pegawai p ON p.pegawai_id = o.pegawai_id WHERE o.pegawai_id IN ($in) AND o.status = ? ORDER BY o.tgl", [CLAIM_PENDING]) as $r)
        $out[] = ['overtime', $r['id'], $r['pegawai_nama'], 'Lembur ' . fmt_minutes($r['minutes']) . ' jam', fmt_date($r['tgl']) . ' ' . substr($r['jam_mulai'], 0, 5) . '–' . substr($r['jam_selesai'], 0, 5) . ' · ' . $r['alasan']];
    foreach (q("SELECT r.*, p.pegawai_nama FROM web_reimburse r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.pegawai_id IN ($in) AND r.status = ? ORDER BY r.tgl", [CLAIM_PENDING]) as $r)
        $out[] = ['reimburse', $r['id'], $r['pegawai_nama'], 'Reimbursement ' . $r['kategori'] . ' ' . rupiah($r['jumlah']), fmt_date($r['tgl']) . ' · ' . $r['keterangan']];
    foreach (q("SELECT l.*, p.pegawai_nama FROM web_loan l JOIN pegawai p ON p.pegawai_id = l.pegawai_id WHERE l.pegawai_id IN ($in) AND l.status = 'pending' ORDER BY l.tgl") as $r)
        $out[] = ['loan', $r['id'], $r['pegawai_nama'], 'Kasbon ' . rupiah($r['jumlah']), 'cicilan ' . rupiah($r['cicilan']) . ' · ' . $r['keterangan']];
    foreach (q("SELECT s.*, p.pegawai_nama, w.pegawai_nama with_name FROM web_shift_swap s JOIN pegawai p ON p.pegawai_id = s.pegawai_id JOIN pegawai w ON w.pegawai_id = s.with_id
                WHERE s.pegawai_id IN ($in) AND s.status = ? ORDER BY s.tgl", [CLAIM_PENDING]) as $r)
        $out[] = ['swap', $r['id'], $r['pegawai_nama'], 'Tukar shift dengan ' . $r['with_name'], fmt_date($r['tgl']) . ' · ' . $r['alasan']];
    return array_values(array_filter($out, function ($x) { return !approval_of($x[0], (string)$x[1]); }));
}

/** Employee a request belongs to, or null when it is not pending any more. */
function request_owner($kind, $ref)
{
    if ($kind === 'izin') {
        $k = explode('|', $ref);
        return q('SELECT pegawai_id FROM izin WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_urutan = ? AND izin_status = 0 AND ket_status = ?', [(int)$k[0], $k[1], (int)$k[2], (int)$k[3], REQ_PENDING])->fetchColumn() ?: null;
    }
    $t = ['overtime' => ['web_overtime', CLAIM_PENDING], 'reimburse' => ['web_reimburse', CLAIM_PENDING], 'loan' => ['web_loan', 'pending'], 'swap' => ['web_shift_swap', CLAIM_PENDING]];
    if (!isset($t[$kind])) return null;
    return q("SELECT pegawai_id FROM {$t[$kind][0]} WHERE id = ? AND status = ?", [(int)$ref, $t[$kind][1]])->fetchColumn() ?: null;
}

/** The supervisor decides. Only for a pending request of one of their direct reports. A rejection ends the request. */
function supervisor_decide($bossId, $bossName, $kind, $ref, $approve, $note)
{
    $owner = request_owner($kind, $ref);
    if (!$owner || (int)q('SELECT atasan_id FROM web_emp_profile WHERE pegawai_id = ?', [$owner])->fetchColumn() !== (int)$bossId) return false;
    q('REPLACE INTO web_approval (kind, ref, approver_id, decision, note, at) VALUES (?, ?, ?, ?, ?, ?)', [$kind, (string)$ref, $bossId, $approve ? 'approved' : 'rejected', mb_substr($note, 0, 200), date('Y-m-d H:i:s')]);
    if (!$approve) {
        $by = 'atasan:' . $bossName;
        if ($kind === 'izin') {
            $k = explode('|', $ref);
            decide_request([(int)$k[0], $k[1], (int)$k[2], 0, (int)$k[3]], false);
        } elseif ($kind === 'loan') q("UPDATE web_loan SET status = 'rejected', decided_by = ? WHERE id = ?", [$by, (int)$ref]);
        else q('UPDATE ' . ['overtime' => 'web_overtime', 'reimburse' => 'web_reimburse', 'swap' => 'web_shift_swap'][$kind] . ' SET status = ?, decided_by = ? WHERE id = ?', [CLAIM_REJECTED, $by, (int)$ref]);
    }
    return true;
}
