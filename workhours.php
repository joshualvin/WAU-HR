<?php
// Work hours (jam_kerja): start/end, tolerances and scan windows. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login('admin');

$all = work_hours();
$id = (int)get('id', post('id', 0));
$edit = $id && isset($all[$id]) ? $all[$id] : null;

$times = ['start', 'end', 'in_open', 'in_close', 'out_open', 'out_close'];
$v = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_merge($times, ['jk_name', 'jk_kode', 'jk_tol_late', 'jk_tol_early', 'jk_durasi', 'jk_countas', 'jk_ket']) as $f) $v[$f] = post($f);
} elseif ($edit) {
    foreach ($times as $f) $v[$f] = min_to_hm($edit[$f]);
    foreach (['jk_name', 'jk_kode', 'jk_tol_late', 'jk_tol_early', 'jk_durasi', 'jk_countas', 'jk_ket'] as $f) $v[$f] = $edit[$f];
} else {
    $v = ['start' => '08:00', 'end' => '16:00', 'in_open' => '06:00', 'in_close' => '12:00', 'out_open' => '12:00', 'out_close' => '23:59',
          'jk_name' => '', 'jk_kode' => '', 'jk_tol_late' => 0, 'jk_tol_early' => 0, 'jk_durasi' => 1, 'jk_countas' => 1, 'jk_ket' => ''];
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete') {
        $used = q('SELECT COUNT(*) FROM jdw_kerja_d WHERE jk_id = ?', [$id])->fetchColumn();
        if ($used) $error = 'Jam kerja dipakai di jadwal. Hapus dari jadwal dulu.';
        else {
            q('DELETE FROM jam_kerja WHERE jk_id = ?', [$id]);
            audit('workhours_delete', (string)$id);
            flash('Jam kerja dihapus.');
            redirect('workhours.php');
        }
    } else {
        foreach ($times as $f) if (!valid_time($v[$f])) $error = 'Isi semua jam dengan format JJ:MM.';
        if ($v['jk_name'] === '') $error = 'Isi nama.';
        foreach (['jk_tol_late', 'jk_tol_early'] as $f) if (!ctype_digit((string)$v[$f])) $error = 'Toleransi dalam menit.';
        if (!$error) {
            $data = work_hours_fields($v) + [
                'jk_name' => $v['jk_name'], 'jk_kode' => mb_substr($v['jk_kode'], 0, 10), 'jk_tol_late' => (int)$v['jk_tol_late'],
                'jk_tol_early' => (int)$v['jk_tol_early'], 'jk_durasi' => $v['jk_durasi'] == 2 ? 2 : 1,
                'jk_countas' => (float)$v['jk_countas'], 'jk_ket' => $v['jk_ket'],
            ];
            if ($edit) {
                q('UPDATE jam_kerja SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($data))) . ' WHERE jk_id = ?',
                    array_merge(array_values($data), [$id]));
            } else {
                $id = (int)q('SELECT COALESCE(MAX(jk_id), 0) + 1 FROM jam_kerja')->fetchColumn();
                $data += ['jk_id' => $id, 'use_set' => -1, 'jk_use_ist' => 0, 'jk_ist1' => '00:00:00', 'jk_ist2' => '00:00:00',
                          'use_eot' => 0, 'min_eot' => 0, 'max_eot' => 0, 'reduce_eot' => 0, 'jk_min_countas' => 0, 'jk_min_countas2' => 0];
                q('INSERT INTO jam_kerja (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
            }
            audit('workhours_save', $id . ' ' . $v['jk_name'] . ' ' . $v['start'] . '-' . $v['end']);
            flash('Jam kerja disimpan.');
            redirect('workhours.php');
        }
    }
}

page_header('Jam Kerja', 'settings.php');
?>
<div class="page-head">
  <h1>Jam Kerja</h1>
  <a class="btn primary" href="workhours.php?id=0&new=1#form">+ Tambah</a>
</div>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<div class="card">
  <div class="table-wrap"><table>
    <tr><th>Kode</th><th>Nama</th><th>Masuk</th><th>Pulang</th><th>Terlambat setelah</th><th>Pulang awal sebelum</th><th>Scan masuk</th><th>Scan pulang</th><th>Durasi</th><th></th></tr>
    <?php foreach ($all as $w): ?>
      <tr>
        <td><?= h($w['jk_kode']) ?></td>
        <td><?= h($w['jk_name']) ?></td>
        <td><?= min_to_hm($w['start']) ?></td>
        <td><?= min_to_hm($w['end']) ?></td>
        <td><?= min_to_hm($w['start'] + $w['jk_tol_late']) ?> <span class="muted">(+<?= (int)$w['jk_tol_late'] ?>m)</span></td>
        <td><?= min_to_hm($w['end'] - $w['jk_tol_early']) ?> <span class="muted">(−<?= (int)$w['jk_tol_early'] ?>m)</span></td>
        <td><?= min_to_hm($w['in_open']) ?>–<?= min_to_hm($w['in_close']) ?></td>
        <td><?= min_to_hm($w['out_open']) ?>–<?= min_to_hm($w['out_close']) ?></td>
        <td><?= $w['jk_durasi'] == 2 ? 'Aktual' : 'Efektif' ?></td>
        <td><a class="btn small" href="workhours.php?id=<?= (int)$w['jk_id'] ?>#form">Ubah</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php if ($edit || get('new') || $error): ?>
<div class="card" id="form" style="max-width:820px">
  <h2><?= $edit ? 'Ubah ' . h($edit['jk_name']) : 'Tambah Jam Kerja' ?></h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="grid2">
      <label>Nama <input name="jk_name" required maxlength="100" value="<?= h($v['jk_name']) ?>"></label>
      <label>Kode <input name="jk_kode" maxlength="10" value="<?= h($v['jk_kode']) ?>"></label>
      <label>Jam masuk <input type="time" name="start" required value="<?= h($v['start']) ?>"></label>
      <label>Jam pulang <input type="time" name="end" required value="<?= h($v['end']) ?>"></label>
      <label>Toleransi terlambat (menit) <input type="number" min="0" name="jk_tol_late" value="<?= h($v['jk_tol_late']) ?>"></label>
      <label>Toleransi pulang awal (menit) <input type="number" min="0" name="jk_tol_early" value="<?= h($v['jk_tol_early']) ?>"></label>
      <label>Scan masuk mulai <input type="time" name="in_open" required value="<?= h($v['in_open']) ?>"></label>
      <label>… sampai <input type="time" name="in_close" required value="<?= h($v['in_close']) ?>"></label>
      <label>Scan pulang mulai <input type="time" name="out_open" required value="<?= h($v['out_open']) ?>"></label>
      <label>… sampai (boleh lewat tengah malam) <input type="time" name="out_close" required value="<?= h($v['out_close']) ?>"></label>
      <label>Durasi <select name="jk_durasi"><?= options(['1' => 'Efektif (jam kerja dikurangi terlambat / pulang awal)', '2' => 'Aktual (scan masuk s/d pulang)'], $v['jk_durasi']) ?></select></label>
      <label>Dihitung (hari) <input type="number" step="0.5" min="0" name="jk_countas" value="<?= h($v['jk_countas']) ?>"></label>
      <label>Catatan <input name="jk_ket" maxlength="100" value="<?= h($v['jk_ket']) ?>"></label>
    </div>
    <div>
      <button class="btn primary">Simpan</button> <a class="btn" href="workhours.php">Batal</a>
      <?php if ($edit): ?><button class="btn danger" name="action" value="delete" onclick="return confirm('Hapus jam kerja ini?')" formnovalidate>Hapus</button><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>
<?php page_footer();
