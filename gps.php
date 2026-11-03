<?php
// Mesin → Scan GPS (FiO): attendance from the Fingerspot.io FiO phone app with location, checked against geofences
// (offices and sites with a radius). Scans come in with Download Fingerspot.io (Scan GPS App FiO).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/portal.php';
require_once __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/cloud.php';
require_login();
ensure_cloud_tables();
ensure_geofence_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'fence_save') {
        foreach (isset($_POST['f']) ? (array)$_POST['f'] : [] as $id => $f) {
            if (trim($f['name']) === '') q('DELETE FROM web_geofence WHERE id = ?', [(int)$id]);
            elseif (is_numeric($f['lat']) && is_numeric($f['lng'])) q('UPDATE web_geofence SET name = ?, lat = ?, lng = ?, radius_m = ? WHERE id = ?', [mb_substr(trim($f['name']), 0, 80), (float)$f['lat'], (float)$f['lng'], max(20, (int)$f['radius_m']), (int)$id]);
        }
        if (trim(post('new_name')) !== '' && is_numeric(post('new_lat')) && is_numeric(post('new_lng')))
            q('INSERT INTO web_geofence (name, lat, lng, radius_m) VALUES (?, ?, ?, ?)', [mb_substr(trim(post('new_name')), 0, 80), (float)post('new_lat'), (float)post('new_lng'), max(20, (int)post('new_radius', 150))]);
        audit('geofence_save', 'diubah');
        flash('Lokasi disimpan.');
        redirect('gps.php');
    }
}

$from = valid_date(get('from')) ? get('from') : date('Y-m-d', strtotime('-6 days'));
$to = valid_date(get('to')) ? get('to') : date('Y-m-d');
$only = get('only');
$fences = q('SELECT * FROM web_geofence ORDER BY name')->fetchAll();
$rows = q('SELECT g.*, p.pegawai_nama FROM web_gps_scan g LEFT JOIN pegawai p ON p.pegawai_pin = g.pin WHERE g.scan_date >= ? AND g.scan_date < ? + INTERVAL 1 DAY ORDER BY g.scan_date DESC', [$from, $to])->fetchAll();
settings_header('Scan GPS (FiO)', 'gps.php');
?>
<div class="card">
  <h2>Scan GPS dari aplikasi FiO</h2>
  <form method="get" class="inline">Tanggal <input type="date" name="from" value="<?= h($from) ?>"> s/d <input type="date" name="to" value="<?= h($to) ?>">
    <label style="flex-direction:row;gap:4px;align-items:center"><input type="checkbox" name="only" value="out" <?= $only === 'out' ? 'checked' : '' ?>> hanya di luar lokasi</label> <button class="btn">Tampilkan</button>
    <a class="btn" href="cloud.php">Download dari Fingerspot.io</a></form>
  <div class="table-wrap" style="max-height:none;margin-top:8px"><table>
    <tr><th>Waktu</th><th>Pegawai</th><th>Jenis</th><th>Lokasi terdekat</th><th>Alamat</th><th>HP</th><th>Peta</th></tr>
    <?php $shown = 0; foreach ($rows as $r): $n = nearest_geofence($r['lat'], $r['lng'], $fences); if ($only === 'out' && (!$n || $n[2])) continue; $shown++; ?>
      <tr><td><?= h(date('d-m-Y H:i', strtotime($r['scan_date']))) ?></td><td><b><?= h($r['pegawai_nama'] ?: 'PIN ' . $r['pin']) ?></b></td><td><?= h($r['type']) ?></td>
        <td><?= $n ? ($n[2] ? '<span class="ok">' . h($n[0]['name']) . ' (' . $n[1] . ' m)</span>' : '<b class="late">di luar: ' . number_format($n[1] / 1000, 1, ',', '.') . ' km dari ' . h($n[0]['name']) . '</b>') : '<span class="muted">—</span>' ?></td>
        <td style="white-space:normal;max-width:320px"><?= h($r['address']) ?></td><td class="muted"><?= h($r['phone']) ?></td>
        <td><?= $r['lat'] !== null ? '<a target="_blank" rel="noopener" href="https://www.google.com/maps?q=' . h($r['lat'] . ',' . $r['lng']) . '">buka</a>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$shown): ?><tr><td colspan="7" class="muted">Tidak ada scan GPS di rentang ini.</td></tr><?php endif; ?>
  </table></div>
  <p class="muted">Scan GPS juga tersimpan sebagai scan biasa (mesin FIO), jadi langsung masuk kehadiran, laporan dan gaji.</p>
</div>
<div class="card">
  <h2>Lokasi kantor / proyek (geofence)</h2>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="fence_save">
    <table><tr><th>Nama</th><th>Latitude</th><th>Longitude</th><th>Radius (m)</th></tr>
      <?php foreach ($fences as $f): ?><tr><td><input name="f[<?= (int)$f['id'] ?>][name]" value="<?= h($f['name']) ?>"></td><td><input name="f[<?= (int)$f['id'] ?>][lat]" value="<?= h($f['lat']) ?>" style="width:120px"></td>
        <td><input name="f[<?= (int)$f['id'] ?>][lng]" value="<?= h($f['lng']) ?>" style="width:120px"></td><td><input type="number" name="f[<?= (int)$f['id'] ?>][radius_m]" value="<?= (int)$f['radius_m'] ?>" style="width:80px"></td></tr><?php endforeach; ?>
      <tr><td><input name="new_name" placeholder="mis. Kantor Pusat"></td><td><input name="new_lat" placeholder="-7.2779" style="width:120px"></td><td><input name="new_lng" placeholder="112.6429" style="width:120px"></td><td><input type="number" name="new_radius" value="150" style="width:80px"></td></tr>
    </table>
    <button class="btn primary">Simpan lokasi</button>
    <p class="muted">Koordinat bisa disalin dari Google Maps (klik kanan titik → angka pertama latitude, kedua longitude). Kosongkan nama untuk menghapus.</p></form>
</div>
<?php settings_footer();
