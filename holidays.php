<?php
// Public holidays and collective leave (libur). Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/govcal.php';
require_login('admin');

$kinds = ['1' => 'Hari Libur', '2' => 'Cuti Bersama'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'gov_fill') {
        // add the government's dates that are not in the calendar yet; dates already there are left as they are
        $y = (int)post('year');
        $have = q('SELECT libur_tgl FROM libur WHERE YEAR(libur_tgl) = ?', [$y])->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach (gov_holidays($y) as $g) {
            if (in_array($g[0], $have, true) || ($g[2] === 2 && !post('cuti'))) continue;
            q('INSERT INTO libur (libur_tgl, libur_keterangan, libur_status) VALUES (?, ?, ?)', [$g[0], $g[1], $g[2]]);
            $have[] = $g[0];
            $n++;
        }
        audit('holiday_gov_fill', "$y: $n" . (post('cuti') ? ' (dengan cuti bersama)' : ''));
        flash("$n hari dari kalender pemerintah $y ditambahkan.");
        redirect('holidays.php?year=' . $y);
    }
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
$have = array_column($rows, 'libur_tgl');
$gov = gov_holidays($year);
$missing = array_filter($gov, function ($g) use ($have) { return !in_array($g[0], $have, true); });
$missingCuti = count(array_filter($missing, function ($g) { return $g[2] === 2; }));
settings_header('Hari Libur ' . $year, 'settings.php');
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
    <h2 style="margin-top:16px">Kalender pemerintah <?= $year ?></h2>
    <?php if (!$gov): ?><p class="muted">SKB 3 Menteri tahun <?= $year ?> belum ada di aplikasi.</p>
    <?php elseif (!$missing): ?><p class="muted">Semua <?= count($gov) ?> tanggal libur nasional dan cuti bersama sudah ada.</p>
    <?php else: ?>
      <p class="muted"><?= count($missing) - $missingCuti ?> libur nasional dan <?= $missingCuti ?> cuti bersama belum ada di kalender:</p>
      <ul class="muted" style="margin:0 0 8px;padding-left:18px;font-size:12px"><?php foreach ($missing as $g): ?><li><?= h(fmt_date($g[0])) ?> · <?= h($g[1]) ?></li><?php endforeach; ?></ul>
      <?php if (!gov_official($year)): ?><p class="muted">SKB <?= $year ?> belum ada di aplikasi: hanya hari libur bertanggal tetap (Tahun Baru, Buruh, Pancasila, 17 Agustus, Natal). Libur keagamaan lain tambahkan manual.</p><?php endif; ?>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="gov_fill"><input type="hidden" name="year" value="<?= $year ?>">
        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="cuti" value="1" checked> Termasuk cuti bersama</label>
        <button class="btn primary">Tambahkan dari kalender pemerintah</button></form>
      <p class="muted">Sesuai SKB 3 Menteri. Tanggal yang sudah ada tidak diubah. Bagi perusahaan swasta cuti bersama tidak wajib dan biasanya memotong cuti tahunan; hapus centang bila perusahaan tetap bekerja.</p>
    <?php endif; ?>
  </div>
</div>
<?php settings_footer();
