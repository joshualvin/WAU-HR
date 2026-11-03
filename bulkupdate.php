<?php
// HR → Karyawan → Update data massal: change many employees' data at once from a CSV (like Talenta's bulk update). Download the current
// data, edit it in a spreadsheet, upload it, check every change in the preview, then apply. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
require __DIR__ . '/inc/transfer.php';
require __DIR__ . '/inc/bulk.php';
$me = require_login('admin');
ensure_web_tables();
ensure_people_tables();
ensure_company_tables();
ensure_transfer_tables();
$csrf = csrf_field();
$ctx = bulk_context();

if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="data_karyawan_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach (bulk_export_rows($ctx) as $line) fputcsv($out, $line);
    audit('bulk_export', 'unduh data karyawan');
    exit;
}

$plan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'preview') {
        $f = isset($_FILES['file']) ? $_FILES['file'] : null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0) flash('Pilih file CSV.', 'err');
        elseif ($f['size'] > 2 * 1024 * 1024) flash('File terlalu besar (maksimal 2 MB).', 'err');
        else {
            $plan = bulk_plan(bulk_parse_csv((string)file_get_contents($f['tmp_name'])), bulk_current(), $ctx);
            if ($plan['error']) { flash($plan['error'], 'err'); $plan = null; }
            else $_SESSION['bulk_plan'] = ['plan' => $plan, 'at' => time(), 'name' => mb_substr((string)$f['name'], 0, 80)];
        }
    } elseif (post('action') === 'apply') {
        $saved = isset($_SESSION['bulk_plan']) ? $_SESSION['bulk_plan'] : null;
        if (!$saved || time() - $saved['at'] > 1800) flash('Pratinjau sudah kedaluwarsa. Unggah file lagi.', 'err');
        else {
            $r = bulk_apply($saved['plan']);
            unset($_SESSION['bulk_plan']);
            audit('bulk_apply', $saved['name'] . ': ' . $r['rows'] . ' karyawan, ' . $r['fields'] . ' data');
            flash($r['rows'] . ' karyawan diperbarui (' . $r['fields'] . ' data' . ($r['moves'] ? ', ' . $r['moves'] . ' perubahan jabatan / departemen / kantor tercatat di Mutasi karyawan' : '') . ').');
            foreach ($r['failed'] as $m) flash($m, 'err');
            redirect('bulkupdate.php');
        }
    }
}
$shown = $plan ?: null;

hris_header('Update data massal', 'employee', 'bulkupdate.php', 'Karyawan', '<a class="btn" href="bulkupdate.php?csv=1">Unduh data karyawan (CSV)</a>'); ?>
  <div class="row">
    <div class="card" style="flex:1 1 360px">
      <h2>1. Unduh, ubah, unggah</h2>
      <ol style="margin:0 0 12px;padding-left:20px;display:grid;gap:6px">
        <li>Klik <b>Unduh data karyawan (CSV)</b> di kanan atas, buka di Excel atau Google Sheets.</li>
        <li>Ubah sel yang perlu. <b>Kolom PIN jangan diubah</b> (dipakai untuk mengenali karyawan). Sel yang dikosongkan tidak mengubah apa pun.</li>
        <li>Simpan sebagai CSV, lalu unggah di bawah. Sebelum ada yang tersimpan, semua perubahan ditampilkan dulu untuk diperiksa.</li>
      </ol>
      <form method="post" enctype="multipart/form-data" class="stack"><?= $csrf ?><input type="hidden" name="action" value="preview">
        <input type="file" name="file" accept=".csv,text/csv" required>
        <div><button class="btn primary">Periksa perubahan</button></div></form>
    </div>
    <div class="card" style="flex:1 1 360px">
      <h2>Kolom yang dikenali</h2>
      <p class="muted" style="margin-top:0"><?= h(implode(' · ', array_map(function ($c) { return $c[0]; }, bulk_columns()))) ?></p>
      <p class="muted">Tanggal: 31-12-2026 atau 2026-12-31. Jenis kelamin: L / P. Jabatan, Departemen, Kantor, Agama, Pendidikan dan Status perkawinan harus sama dengan daftar yang ada. NIK dan No. KK 16 angka (format kolom sebagai <b>Teks</b> di Excel agar angkanya tidak berubah).
        Perubahan Jabatan, Departemen atau Kantor dicatat sebagai mutasi (Penyesuaian data) berlaku hari ini.</p>
    </div>
  </div>
  <?php if ($shown): $c = $shown['counts']; ?>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-top:16px">
    <div class="counter"><b><?= (int)$c['rows'] ?></b><span>Baris di file</span></div>
    <div class="counter"><b><?= (int)$c['changes'] ?></b><span>Karyawan akan berubah</span></div>
    <div class="counter"><b><?= (int)$c['unchanged'] ?></b><span>Tanpa perubahan</span></div>
    <div class="counter"><b style="color:<?= $c['errors'] ? 'var(--bad)' : 'inherit' ?>"><?= (int)$c['errors'] ?></b><span>Baris bermasalah (dilewati)</span></div>
  </div>
  <?php if ($shown['unknown']): ?><div class="notice" style="margin-top:12px"><div><b>Kolom ini tidak dikenali dan diabaikan:</b> <?= h(implode(', ', $shown['unknown'])) ?></div></div><?php endif; ?>
  <div class="tablecard" style="margin-top:12px"><div class="tablescroll"><table>
    <thead><tr><th>Baris</th><th>PIN</th><th>Karyawan</th><th>Perubahan</th></tr></thead><tbody>
    <?php foreach ($shown['rows'] as $r): if (!$r['errors'] && !$r['changes']) continue; ?>
      <tr><td class="muted"><?= (int)$r['line'] ?></td><td><?= h($r['pin']) ?></td><td><b><?= h($r['name']) ?></b></td>
        <td><?php foreach ($r['errors'] as $e): ?><div><span class="pill bad">Masalah</span> <?= h($e) ?></div><?php endforeach; ?>
          <?php if (!$r['errors']): foreach ($r['changes'] as $ch): ?><div><span class="muted"><?= h($ch[1]) ?>:</span> <?= h($ch[2]) ?> → <b><?= h($ch[3]) ?></b></div><?php endforeach; endif; ?>
          <?php if ($r['errors'] && $r['changes']): ?><small class="muted">Baris ini dilewati sampai masalahnya diperbaiki.</small><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$c['changes'] && !$c['errors']): ?><div class="empty"><strong>Tidak ada yang berubah</strong>Data di file sama dengan data sekarang.</div><?php endif; ?></div>
  <?php if ($c['changes']): ?>
  <form method="post" class="formfoot" onsubmit="return confirm('Terapkan perubahan untuk <?= (int)$c['changes'] ?> karyawan?')"><?= $csrf ?><input type="hidden" name="action" value="apply">
    <a class="btn" href="bulkupdate.php">Batal</a><button class="btn primary">Terapkan perubahan untuk <?= (int)$c['changes'] ?> karyawan</button></form>
  <?php endif; endif; ?>
<?php hris_footer();
