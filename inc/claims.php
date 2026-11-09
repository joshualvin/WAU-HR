<?php
// Overtime requests (pengajuan lembur) and reimbursement claims, submitted in the Portal Pegawai and decided by HR.
// Approved overtime can limit what payroll pays (setting ot_requires_approval); approved reimbursements are added
// to the next saved payroll as a non-taxable earning and then marked paid with that period.

const CLAIM_PENDING = 'pending';
const CLAIM_APPROVED = 'approved';
const CLAIM_REJECTED = 'rejected';
const RECEIPT_DIR = __DIR__ . '/../uploads/receipts';

function ensure_claim_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_overtime (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        tgl DATE NOT NULL,
        jam_mulai TIME NOT NULL,
        jam_selesai TIME NOT NULL,
        minutes INT NOT NULL,
        alasan VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        decided_by VARCHAR(50) NOT NULL DEFAULT '',
        KEY emp_date (pegawai_id, tgl)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_reimburse (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pegawai_id INT NOT NULL,
        tgl DATE NOT NULL,
        kategori VARCHAR(40) NOT NULL,
        jumlah BIGINT NOT NULL,
        keterangan VARCHAR(255) NOT NULL DEFAULT '',
        file VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        decided_by VARCHAR(50) NOT NULL DEFAULT '',
        paid_period_to DATE NULL,
        KEY emp (pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function reimburse_categories()
{
    return ['Transport', 'Bensin', 'Makan', 'Kesehatan', 'Perjalanan dinas', 'ATK / perlengkapan', 'Komunikasi', 'Lainnya'];
}

function claim_badge($status)
{
    $map = [CLAIM_PENDING => ['b-pending', 'Menunggu'], CLAIM_APPROVED => ['b-ok', 'Disetujui'], CLAIM_REJECTED => ['b-absent', 'Ditolak']];
    $m = isset($map[$status]) ? $map[$status] : ['b-off', $status];
    return '<span class="badge ' . $m[0] . '">' . $m[1] . '</span>';
}

/** Minutes between two HH:MM times; an end before the start runs past midnight. */
function span_minutes($start, $end)
{
    $m = hm_to_min($end) - hm_to_min($start);
    return $m <= 0 ? $m + 1440 : $m;
}

/**
 * Save an uploaded receipt (photo or PDF, max 5 MB) under a random name. Returns [file name or '', error or null].
 * The content type is checked from the file itself, not the browser's claim.
 */
function save_receipt($upload)
{
    return save_upload($upload, RECEIPT_DIR, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 'Bukti', 'foto (JPG, PNG) atau PDF');
}

/** Approved overtime minutes per employee per day: [pegawai_id][Y-m-d] => minutes. */
function approved_overtime($from, $to)
{
    $out = [];
    foreach (q('SELECT pegawai_id, tgl, SUM(minutes) m FROM web_overtime WHERE status = ? AND tgl BETWEEN ? AND ? GROUP BY pegawai_id, tgl', [CLAIM_APPROVED, $from, $to]) as $r) {
        $out[$r['pegawai_id']][$r['tgl']] = (int)$r['m'];
    }
    return $out;
}

/** Approved reimbursements to pay with the period ending $to: unpaid ones up to that date, or ones already tied to it. */
function reimbursements_for_period($to)
{
    $out = [];
    foreach (q('SELECT * FROM web_reimburse WHERE status = ? AND ((paid_period_to IS NULL AND tgl <= ?) OR paid_period_to = ?) ORDER BY tgl', [CLAIM_APPROVED, $to, $to]) as $r) {
        $out[$r['pegawai_id']][] = $r;
    }
    return $out;
}
