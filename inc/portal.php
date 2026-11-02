<?php
// Shared by the Portal Pegawai (employee self-service) and the HR Overview page.
//
// Time off requests use the izin table the program already has. Fingerspot only knows izin_status
// 1 (diizinkan) and 0 (tidak diizinkan), so a request waiting for HR is izin_status 0 with
// ket_status 'Menunggu'; rejected ones get ket_status 'Ditolak'.

const REQ_PENDING = 'Menunggu';
const REQ_REJECTED = 'Ditolak';

function current_emp()
{
    return isset($_SESSION['emp']) ? $_SESSION['emp'] : null;
}

/** 'approved' | 'pending' | 'rejected' for an izin row. */
function request_state($row)
{
    if ((int)$row['izin_status'] === 1) return 'approved';
    return $row['ket_status'] === REQ_PENDING ? 'pending' : 'rejected';
}

function request_badge($row)
{
    $s = request_state($row);
    $map = ['approved' => ['b-ok', 'Disetujui'], 'pending' => ['b-pending', 'Menunggu'], 'rejected' => ['b-absent', 'Ditolak']];
    return '<span class="badge ' . $map[$s][0] . '">' . $map[$s][1] . '</span>';
}

/** Leave types an employee may request (not the forgotten-scan types). */
function request_types()
{
    return array_filter(leave_types(), function ($k) { return !is_scan_type($k); }, ARRAY_FILTER_USE_KEY);
}

/** [quota or null, taken, pending] of Cuti pribadi (type 90) for this calendar year. */
function leave_balance($pegawaiId)
{
    $y = date('Y');
    $quota = q('SELECT jatah_c_jml FROM jatah_cuti WHERE pegawai_id = ? AND jatah_c_mulai <= ? AND jatah_c_akhir >= ?', [$pegawaiId, "$y-12-31", "$y-01-01"])->fetchColumn();
    $taken = (int)q('SELECT COUNT(*) FROM izin WHERE pegawai_id = ? AND izin_jenis_id = 90 AND izin_status = 1 AND izin_tgl BETWEEN ? AND ?', [$pegawaiId, "$y-01-01", "$y-12-31"])->fetchColumn();
    $pending = (int)q('SELECT COUNT(*) FROM izin WHERE pegawai_id = ? AND izin_jenis_id = 90 AND izin_status = 0 AND ket_status = ? AND izin_tgl BETWEEN ? AND ?', [$pegawaiId, REQ_PENDING, "$y-01-01", "$y-12-31"])->fetchColumn();
    return [$quota === false ? null : (int)$quota, $taken, $pending];
}

/** Current payroll period: the 21st of last month to the 20th of this month (moves on after the 20th). */
function current_period()
{
    $from = (int)date('j') > 20 ? date('Y-m-21') : date('Y-m-21', strtotime('first day of last month'));
    return [$from, date('Y-m-20', strtotime($from . ' +1 month'))];
}

/** Approve or reject a pending request (one izin row, keyed like leave_key_from_post()). */
function decide_request($key, $approve)
{
    $row = find_leave($key);
    if (!$row || request_state($row) !== 'pending') return;
    if ($approve) {
        q('UPDATE izin SET izin_status = 1, ket_status = NULL, izin_urutan = ? WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_status = ? AND izin_urutan = ?',
            array_merge([next_izin_seq($key[0], $key[1], $key[2])], $key));
    } else {
        q('UPDATE izin SET ket_status = ? WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_status = ? AND izin_urutan = ?',
            array_merge([REQ_REJECTED], $key));
    }
    audit($approve ? 'request_approve' : 'request_reject', $row['pegawai_nama'] . ' ' . $row['izin_tgl'] . ' type ' . $row['izin_jenis_id']);
}

function initials($name)
{
    $p = preg_split('/\s+/', trim($name));
    return strtoupper(substr($p[0], 0, 1) . (isset($p[1]) ? substr($p[1], 0, 1) : substr($p[0], 1, 1)));
}
