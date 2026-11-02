<?php
// Fingerprint machines (mesin) and how recently each one sent scans.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$rows = q("SELECT d.sn, d.device_name, d.dev_id, d.ip_address, d.ethernet_port, d.comm_type, d.last_download,
                  (SELECT MAX(scan_date) FROM att_log a WHERE a.sn = d.sn) last_scan,
                  (SELECT COUNT(*) FROM att_log a WHERE a.sn = d.sn) scans,
                  (SELECT COUNT(DISTINCT pin) FROM att_log a WHERE a.sn = d.sn AND a.scan_date >= CURDATE() - INTERVAL 30 DAY) people
           FROM device d ORDER BY d.device_name")->fetchAll();
$today = q("SELECT sn, COUNT(*) n, COUNT(DISTINCT pin) people FROM att_log WHERE scan_date >= CURDATE() GROUP BY sn")->fetchAll(PDO::FETCH_UNIQUE);
$comm = ['0' => 'Ethernet', '1' => 'USB', '2' => 'Serial'];

page_header('Mesin', 'devices.php');
?>
<div class="page-head"><h1>Daftar Mesin</h1><a class="btn" href="scans.php">Data Scanlog ›</a></div>
<div class="card">
  <div class="table-wrap"><table>
    <tr><th>Nama</th><th>SN</th><th>No.</th><th>Koneksi</th><th>Scan terakhir diterima</th><th class="num">Scan hari ini</th><th class="num">PIN (30 hari)</th><th class="num">Jumlah scan</th></tr>
    <?php foreach ($rows as $r): $age = $r['last_scan'] ? (time() - strtotime($r['last_scan'])) / 86400 : null; ?>
      <tr>
        <td><b><?= h($r['device_name']) ?></b></td>
        <td><?= h($r['sn']) ?></td>
        <td><?= h($r['dev_id']) ?></td>
        <td><?= h(isset($comm[$r['comm_type']]) ? $comm[$r['comm_type']] : '') ?> <?= h($r['ip_address'] ? $r['ip_address'] . ':' . $r['ethernet_port'] : '') ?></td>
        <td><?= $r['last_scan'] ? h(date('d-m-Y H:i', strtotime($r['last_scan']))) : '—' ?>
          <?php if ($age !== null): ?><span class="badge <?= $age < 2 ? 'b-ok' : ($age < 14 ? 'b-incomplete' : 'b-off') ?>"><?= $age < 1 ? 'hari ini' : (int)$age . ' hari lalu' ?></span><?php endif; ?></td>
        <td class="num"><?= isset($today[$r['sn']]) ? (int)$today[$r['sn']]['n'] : '' ?></td>
        <td class="num"><?= (int)$r['people'] ?: '' ?></td>
        <td class="num"><?= number_format($r['scans']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Download data presensi dan upload pegawai ke mesin tetap dilakukan di program absensi (menu Mesin). Halaman ini menampilkan data yang sudah masuk.</p>
</div>
<?php page_footer();
