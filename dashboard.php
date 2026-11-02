<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$today = get('date', date('Y-m-d'));
if (!valid_date($today)) $today = date('Y-m-d');
$rows = daily_attendance($today, $today);
$count = ['ok' => 0, 'incomplete' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0];
foreach ($rows as $r) $count[$r['status']]++;
$pending = q('SELECT COUNT(*) FROM izin WHERE izin_status = 0 AND izin_tgl >= ?', [date('Y-m-d', strtotime('-60 days'))])->fetchColumn();
$recent = q("SELECT a.scan_date, a.sn, p.pegawai_nama, d.device_name
             FROM att_log a LEFT JOIN pegawai p ON p.pegawai_pin = a.pin LEFT JOIN device d ON d.sn = a.sn
             WHERE a.scan_date >= ? ORDER BY a.scan_date DESC LIMIT 12", [date('Y-m-d', strtotime('-7 days'))])->fetchAll();

page_header('Dashboard', 'dashboard.php');
?>
<div class="page-head">
  <h1>Absensi tanggal <?= h(fmt_date($today)) ?></h1>
  <a class="btn primary" href="quick.php">+ Input Cepat (Tidak scan / Dinas)</a>
  <form class="inline" method="get">
    <input type="date" name="date" value="<?= h($today) ?>" max="<?= date('Y-m-d') ?>">
    <button class="btn">Tampilkan</button>
  </form>
</div>

<div class="stats">
  <div class="stat"><div class="n"><?= $count['ok'] ?></div><div class="l">Hadir</div></div>
  <div class="stat"><div class="n"><?= $count['incomplete'] ?></div><div class="l">Scan 1x</div></div>
  <div class="stat"><div class="n"><?= $count['absent'] ?></div><div class="l">Tidak scan</div></div>
  <div class="stat"><div class="n"><?= $count['leave'] ?></div><div class="l">Izin / cuti</div></div>
  <div class="stat"><div class="n"><a href="leave.php?status=0&amp;kind=all&amp;from=<?= date('Y-m-d', strtotime('-60 days')) ?>"><?= (int)$pending ?></a></div><div class="l">Izin menunggu persetujuan</div></div>
</div>

<div class="row">
  <div class="card">
    <h2>Pegawai</h2>
    <div class="table-wrap"><table>
      <tr><th>Nama</th><th>Masuk</th><th>Pulang</th><th>Status</th><th></th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td><?= h($r['first']) ?></td>
          <td><?= h($r['last']) ?></td>
          <td><?= status_badge($r['status']) ?> <?php foreach ($r['leave'] as $l) echo '<span class="tag">' . h($l) . '</span> '; ?><?= $r['off'] && $r['status'] !== 'ok' ? '<span class="tag">' . h($r['off']) . '</span>' : '' ?></td>
          <td>
            <?php if ($r['status'] === 'incomplete' || $r['status'] === 'absent'): ?>
              <a class="btn small" href="scans.php?add=1&pegawai_id=<?= (int)$r['pegawai_id'] ?>&date=<?= h($r['date']) ?>&jenis=<?= (int)($r['missing'] ?: 100) ?>">Tambah <?= $r['missing'] == 101 ? 'pulang' : 'masuk' ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <div class="card">
    <h2>Scan terakhir</h2>
    <table>
      <tr><th>Jam</th><th>Pegawai</th><th>Sumber</th></tr>
      <?php foreach ($recent as $s): ?>
        <tr>
          <td><?= h(date('d-m-Y H:i', strtotime($s['scan_date']))) ?></td>
          <td><?= h($s['pegawai_nama'] ?: '?') ?></td>
          <td><?= is_scan_type($s['sn']) ? '<span class="tag">Manual</span>' : h($s['device_name'] ?: $s['sn']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php page_footer();
