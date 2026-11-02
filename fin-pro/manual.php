<?php
// Revisi Scan Manual: every manually added time ("Tidak scan" 100-105) in a period, editable in place.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$names = [100 => 'Masuk', 101 => 'Pulang', 102 => 'Istirahat Mulai', 103 => 'Istirahat Selesai', 104 => 'Lembur Mulai', 105 => 'Lembur Selesai'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $key = leave_key_from_post();
    if (post('action') === 'delete') {
        delete_leave($key);
        flash('Scan manual dihapus.');
        redirect(safe_back(post('back'), 'manual.php'));
    }
    $err = revise_manual_scan($key, post('new_tgl'), (int)post('new_jenis'), post('new_time'), (int)post('new_kat'), post('new_note'));
    if ($err) {
        $errors[implode('|', $key)] = $err;
    } else {
        flash('Scan manual diperbarui.');
        redirect(safe_back(post('back'), 'manual.php'));
    }
}

$from = get('from', date('Y-m-21', strtotime('first day of last month')));
$to = get('to', date('Y-m-20'));
if (!valid_date($from)) $from = date('Y-m-21', strtotime('first day of last month'));
if (!valid_date($to)) $to = date('Y-m-20');
$pid = get('pegawai_id');
$jenis = get('jenis');
$src = get('src');

$where = ['i.izin_jenis_id BETWEEN 100 AND 105', 'i.izin_noscan_time IS NOT NULL', 'i.izin_tgl BETWEEN ? AND ?'];
$params = [$from, $to];
if ($pid !== '') { $where[] = 'i.pegawai_id = ?'; $params[] = (int)$pid; }
if ($jenis !== '' && isset($names[(int)$jenis])) { $where[] = 'i.izin_jenis_id = ?'; $params[] = (int)$jenis; }
if ($src === 'web') $where[] = "i.izin_ket_lain LIKE 'Web:%'";
if ($src === 'program') $where[] = "(i.izin_ket_lain IS NULL OR i.izin_ket_lain NOT LIKE 'Web:%')";
$rows = q('SELECT i.*, p.pegawai_pin, p.pegawai_nama FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
           WHERE ' . implode(' AND ', $where) . ' ORDER BY i.izin_tgl DESC, p.pegawai_pin + 0, i.izin_noscan_time LIMIT 1000', $params)->fetchAll();
$cats = leave_categories();
$back = $_SERVER['REQUEST_URI'];

page_header('Revisi Scan Manual', 'manual.php');
?>
<div class="card">
  <form class="inline" method="get">
    <label>Tanggal <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <label>Pegawai <?= employee_select('pegawai_id', $pid, true, false) ?></label>
    <label>Jenis <select name="jenis"><?= options($names, $jenis, 'Semua') ?></select></label>
    <label>Diinput dari <select name="src"><?= options(['web' => 'Aplikasi web', 'program' => 'Program absensi'], $src, 'Semua') ?></select></label>
    <button class="btn primary">Proses</button>
  </form>
</div>
<?php foreach ($errors as $e): ?><div class="flash err"><?= h($e) ?></div><?php endforeach; ?>
<div class="card">
  <h2>Revisi Scan Manual <span class="muted" style="font-weight:400">· <?= count($rows) ?> data<?= count($rows) >= 1000 ? ' (1000 pertama)' : '' ?> · ubah lalu klik Simpan pada baris itu</span></h2>
  <div class="table-wrap"><table>
    <tr><th>PIN</th><th>Nama</th><th>Tanggal</th><th>Jenis</th><th>Jam</th><th>Kategori</th><th>Catatan</th><th>Status</th><th>Diinput</th><th></th></tr>
    <?php foreach ($rows as $n => $r):
        $k = implode('|', [$r['pegawai_id'], $r['izin_tgl'], $r['izin_jenis_id'], $r['izin_status'], $r['izin_urutan']]);
        $fid = 'f' . $n; ?>
      <tr<?= isset($errors[$k]) ? ' class="sel"' : '' ?>>
        <td><?= h($r['pegawai_pin']) ?></td>
        <td><?= h($r['pegawai_nama']) ?></td>
        <td><input form="<?= $fid ?>" type="date" name="new_tgl" value="<?= h($r['izin_tgl']) ?>" max="<?= date('Y-m-d') ?>" required></td>
        <td><select form="<?= $fid ?>" name="new_jenis"><?= options($names, $r['izin_jenis_id']) ?></select></td>
        <td><input form="<?= $fid ?>" type="time" name="new_time" value="<?= h(substr($r['izin_noscan_time'], 0, 5)) ?>" required></td>
        <td><select form="<?= $fid ?>" name="new_kat"><?= options($cats, $r['kat_izin_id'], '—') ?></select></td>
        <td><input form="<?= $fid ?>" name="new_note" maxlength="255" value="<?= h($r['izin_catatan']) ?>" style="width:160px"></td>
        <td><?= $r['izin_status'] ? '<span class="badge b-ok">Diizinkan</span>' : '<span class="badge b-pending">Tidak diizinkan</span>' ?></td>
        <td class="muted"><?= h($r['izin_tgl_pengajuan'] ? date('d-m-Y', strtotime($r['izin_tgl_pengajuan'])) : '') ?> <?= h(strpos((string)$r['izin_ket_lain'], 'Web:') === 0 ? substr($r['izin_ket_lain'], 5) : 'program') ?></td>
        <td style="white-space:nowrap">
          <form id="<?= $fid ?>" method="post" class="btn-form">
            <?= csrf_field() ?>
            <input type="hidden" name="pegawai_id" value="<?= (int)$r['pegawai_id'] ?>">
            <input type="hidden" name="izin_tgl" value="<?= h($r['izin_tgl']) ?>">
            <input type="hidden" name="izin_jenis_id" value="<?= (int)$r['izin_jenis_id'] ?>">
            <input type="hidden" name="izin_status" value="<?= (int)$r['izin_status'] ?>">
            <input type="hidden" name="izin_urutan" value="<?= (int)$r['izin_urutan'] ?>">
            <input type="hidden" name="back" value="<?= h($back) ?>">
            <button class="btn small" name="action" value="save">Simpan</button>
            <button class="btn small danger" name="action" value="delete" formnovalidate onclick="return confirm('Hapus scan manual ini?')">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="muted">Tidak ada scan manual di periode ini.</td></tr><?php endif; ?>
  </table></div>
</div>
<script>
  // mark rows that were changed but not saved yet
  document.querySelectorAll('main table input[form], main table select[form]').forEach(function (el) {
    el.addEventListener('input', function () { el.closest('tr').style.outline = '2px solid #f0b400'; });
  });
</script>
<?php page_footer();
