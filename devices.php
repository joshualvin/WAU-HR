<?php
// Fingerprint machines (mesin) and how recently each one sent scans.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/portal.php';
require_once __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/cloud.php';
$me = require_login();
ensure_device_archive();

// Admins archive a machine that is no longer used (it leaves the list and the downloads; its scans stay) or bring it back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me['role'] === 'admin' && (post('archive_sn') !== '' || post('restore_sn') !== '')) {
    check_csrf();
    $sn = post('archive_sn') !== '' ? post('archive_sn') : post('restore_sn');
    if (!q('SELECT COUNT(*) FROM device WHERE sn = ?', [$sn])->fetchColumn()) flash('Mesin tidak ditemukan.', 'err');
    elseif (post('archive_sn') !== '') {
        q('REPLACE INTO web_device_archive (sn, archived_at, archived_by) VALUES (?, NOW(), ?)', [$sn, $me['username']]);
        audit('device_archive', $sn);
        flash('Mesin diarsipkan. Scan lamanya tetap tersimpan; bisa dipulihkan di bagian Mesin diarsipkan.');
    } else {
        q('DELETE FROM web_device_archive WHERE sn = ?', [$sn]);
        audit('device_restore', $sn);
        flash('Mesin dipulihkan.');
    }
    redirect('devices.php');
}

// Admins add a machine registered on Fingerspot.io (name, serial number, Cloud ID), or set the Cloud ID of one
// that is already here, so Download Fingerspot.io can fetch its scans.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me['role'] === 'admin') {
    check_csrf();
    $sn = trim(post('sn'));
    $cloud = strtoupper(trim(post('cloud_id')));
    $no = (int)post('no');   // machine number; empty = keep (existing) or next free (new)
    $taken = $no > 0 ? (int)q('SELECT COUNT(*) FROM device WHERE dev_id = ? AND sn <> ?', [$no, $sn])->fetchColumn() : 0;
    if ($no < 0 || $no > 999 || $taken) flash($taken ? 'Nomor mesin ' . $no . ' sudah dipakai mesin lain.' : 'Nomor mesin 1 sampai 999.', 'err');
    elseif ($sn === '' || !preg_match('/^[A-Za-z0-9]{6,30}$/', $sn) || ($cloud !== '' && !preg_match('/^[A-Z0-9]{8,30}$/', $cloud))) flash('Isi SN mesin (huruf / angka) dan Cloud ID yang benar.', 'err');
    elseif (q('SELECT COUNT(*) FROM device WHERE sn = ?', [$sn])->fetchColumn()) {
        q('UPDATE device SET cloud_id = IF(? = \'\', cloud_id, ?), device_name = IF(? = \'\', device_name, ?), dev_id = IF(? = 0, dev_id, ?) WHERE sn = ?',
            [$cloud, $cloud, post('name'), mb_substr(post('name'), 0, 100), $no, $no, $sn]);
        audit('device_update', "$sn cloud $cloud no $no");
        flash('Mesin diperbarui.');
    } else {
        q("INSERT INTO device (sn, activation_code, device_name, dev_id, comm_type, cloud_id) VALUES (?, '', ?, ?, 0, ?)",
            [$sn, mb_substr(post('name') ?: $sn, 0, 100), $no ?: 1 + (int)q('SELECT COALESCE(MAX(dev_id), 0) FROM device')->fetchColumn(), $cloud]);
        audit('device_add', "$sn " . post('name') . " cloud $cloud");
        flash('Mesin ditambahkan. Ambil datanya di Download Fingerspot.io.');
    }
    redirect('devices.php');
}

$rows = q("SELECT d.sn, d.device_name, d.dev_id, d.cloud_id, d.ip_address, d.ethernet_port, d.comm_type, d.last_download,
                  (SELECT MAX(scan_date) FROM att_log a WHERE a.sn = d.sn) last_scan,
                  (SELECT COUNT(*) FROM att_log a WHERE a.sn = d.sn) scans,
                  (SELECT COUNT(DISTINCT pin) FROM att_log a WHERE a.sn = d.sn AND a.scan_date >= CURDATE() - INTERVAL 30 DAY) people
           FROM device d WHERE d.sn NOT IN (SELECT sn FROM web_device_archive) ORDER BY d.dev_id, d.device_name")->fetchAll();
$archived = q("SELECT d.sn, d.device_name, a.archived_at, (SELECT COUNT(*) FROM att_log x WHERE x.sn = d.sn) scans, (SELECT MAX(scan_date) FROM att_log x WHERE x.sn = d.sn) last_scan
              FROM device d JOIN web_device_archive a ON a.sn = d.sn ORDER BY d.device_name")->fetchAll();
$today = q("SELECT sn, COUNT(*) n, COUNT(DISTINCT pin) people FROM att_log WHERE scan_date >= CURDATE() GROUP BY sn")->fetchAll(PDO::FETCH_UNIQUE);
$comm = ['0' => 'Ethernet', '1' => 'USB', '2' => 'Serial'];

settings_header('Mesin', 'devices.php');
?>
<div class="page-head"><h1>Daftar Mesin</h1><a class="btn" href="scans.php">Data Scanlog ›</a></div>
<div class="card">
  <div class="table-wrap"><table>
    <tr><th>Nama</th><th>SN</th><th>Cloud ID</th><th>No.</th><th>Koneksi</th><th>Scan terakhir diterima</th><th class="num">Scan hari ini</th><th class="num">PIN (30 hari)</th><th class="num">Jumlah scan</th><?php if ($me['role'] === 'admin'): ?><th></th><?php endif; ?></tr>
    <?php foreach ($rows as $r): $age = $r['last_scan'] ? (time() - strtotime($r['last_scan'])) / 86400 : null; ?>
      <tr>
        <td><b><?= h($r['device_name']) ?></b></td>
        <td><?= h($r['sn']) ?></td>
        <td><?= h($r['cloud_id']) ?></td>
        <td><?= h($r['dev_id']) ?></td>
        <td><?= h(isset($comm[$r['comm_type']]) ? $comm[$r['comm_type']] : '') ?> <?= h($r['ip_address'] ? $r['ip_address'] . ':' . $r['ethernet_port'] : '') ?></td>
        <td><?= $r['last_scan'] ? h(date('d-m-Y H:i', strtotime($r['last_scan']))) : '—' ?>
          <?php if ($age !== null): ?><span class="badge <?= $age < 2 ? 'b-ok' : ($age < 14 ? 'b-incomplete' : 'b-off') ?>"><?= $age < 1 ? 'hari ini' : (int)$age . ' hari lalu' ?></span><?php endif; ?></td>
        <td class="num"><?= isset($today[$r['sn']]) ? (int)$today[$r['sn']]['n'] : '' ?></td>
        <td class="num"><?= (int)$r['people'] ?: '' ?></td>
        <td class="num"><?= number_format($r['scans']) ?></td>
        <?php if ($me['role'] === 'admin'): ?><td><form method="post" onsubmit="return confirm('Arsipkan <?= h(addslashes($r['device_name'])) ?>? Scan lamanya tetap tersimpan.')"><?= csrf_field() ?><input type="hidden" name="archive_sn" value="<?= h($r['sn']) ?>"><button class="btn">Arsipkan</button></form></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Download data presensi dan upload pegawai ke mesin tetap dilakukan di program absensi (menu Mesin). Halaman ini menampilkan data yang sudah masuk.</p>
</div>
<?php if ($archived): ?>
<div class="card">
  <h2>Mesin diarsipkan</h2>
  <div class="table-wrap"><table>
    <tr><th>Nama</th><th>SN</th><th>Scan terakhir</th><th class="num">Jumlah scan</th><th>Diarsipkan</th><?php if ($me['role'] === 'admin'): ?><th></th><?php endif; ?></tr>
    <?php foreach ($archived as $a): ?>
      <tr><td><b><?= h($a['device_name']) ?></b></td><td><?= h($a['sn']) ?></td><td><?= $a['last_scan'] ? h(date('d-m-Y H:i', strtotime($a['last_scan']))) : '—' ?></td><td class="num"><?= number_format($a['scans']) ?></td><td><?= h(date('d-m-Y', strtotime($a['archived_at']))) ?></td>
        <?php if ($me['role'] === 'admin'): ?><td><form method="post"><?= csrf_field() ?><input type="hidden" name="restore_sn" value="<?= h($a['sn']) ?>"><button class="btn">Pulihkan</button></form></td><?php endif; ?></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Mesin yang diarsipkan tidak tampil di daftar dan tidak ikut download Fingerspot.io; scan lamanya tetap ada di laporan dan Data Scanlog.</p>
</div>
<?php endif; ?>
<?php if ($me['role'] === 'admin'): ?>
<div class="card">
  <h2>Tambah mesin dari Fingerspot.io</h2>
  <form method="post" class="inline"><?= csrf_field() ?>
    <label>Nama <input name="name" placeholder="mis. Mesin Head Office"></label>
    <label>SN <input name="sn" required placeholder="nomor seri mesin"></label>
    <label>Cloud ID <input name="cloud_id" placeholder="dari Fingerspot.io → Mesin Absensi"></label>
    <label>No. <input name="no" type="number" min="1" max="999" style="width:5em" placeholder="urutan"></label>
    <button class="btn primary">Simpan</button></form>
  <p class="muted">SN dan Cloud ID ada di Fingerspot.io → Mesin Absensi → Aktif. SN yang sudah ada hanya diperbarui (nama, Cloud ID dan No. yang diisi; yang kosong tidak diubah). No. menentukan urutan di daftar. Aktifkan juga API SDK untuk mesin itu (Mesin Absensi → API SDK).</p>
</div>
<?php endif; ?>
<?php settings_footer();
