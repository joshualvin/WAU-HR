<?php
// Public holidays and collective leave (libur). Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login('admin');

$kinds = ['1' => 'Hari Libur', '2' => 'Cuti Bersama'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete') {
        q('DELETE FROM libur WHERE libur_tgl = ?', [post('tgl')]);
        audit('holiday_delete', post('tgl'));
        flash('Hari libur dihapus.');
        redirect('holidays.php?year=' . substr(post('tgl'), 0, 4));
    }
    $d = post('tgl');
    if (!valid_date($d)) $error = 'Isi tanggal yang benar.';
    elseif (post('ket') === '') $error = 'Isi keterangan.';
    else {
        q('REPLACE INTO libur (libur_tgl, libur_keterangan, libur_status) VALUES (?, ?, ?)', [$d, post('ket'), post('status') === '2' ? 2 : 1]);
        audit('holiday_save', $d . ' ' . post('ket'));
        flash('Hari libur disimpan.');
        redirect('holidays.php?year=' . substr($d, 0, 4));
    }
}

$year = (int)get('year', date('Y'));
$rows = q('SELECT * FROM libur WHERE YEAR(libur_tgl) = ? ORDER BY libur_tgl', [$year])->fetchAll();
page_header('Hari Libur', 'settings.php');
?>
<div class="page-head">
  <h1>Hari Libur <?= $year ?></h1>
  <div><a class="btn small" href="?year=<?= $year - 1 ?>">‹ <?= $year - 1 ?></a> <a class="btn small" href="?year=<?= $year + 1 ?>"><?= $year + 1 ?> ›</a></div>
</div>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<div class="row">
  <div class="card">
    <table>
      <tr><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th></th></tr>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= h(fmt_date($r['libur_tgl'])) ?></td><td><?= h($r['libur_keterangan']) ?></td><td><?= h(isset($kinds[$r['libur_status']]) ? $kinds[$r['libur_status']] : '') ?></td>
          <td><form method="post" class="btn-form" onsubmit="return confirm('Hapus hari libur ini?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="tgl" value="<?= h($r['libur_tgl']) ?>">
            <button class="btn small danger">Hapus</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="muted">Belum ada hari libur tahun <?= $year ?>.</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card" style="flex:0 1 320px">
    <h2>Tambah Hari Libur</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Tanggal <input type="date" name="tgl" required value="<?= h(post('tgl')) ?>"></label>
      <label>Keterangan <input name="ket" required maxlength="255" value="<?= h(post('ket')) ?>"></label>
      <label>Jenis <select name="status"><?= options($kinds, post('status', '1')) ?></select></label>
      <button class="btn primary">Simpan</button>
    </form>
    <p class="muted">Hari libur dilewati saat mencari scan tidak lengkap dan input izin.</p>
  </div>
</div>
<?php page_footer();
