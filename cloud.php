<?php
// Mesin → Download dari Fingerspot.io
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/cloud.php';
$me = require_login();

// Admin diagnostic: ?probe=<cloud id> tries the signing recipes against api-ds and prints which are accepted (key never shown)
if ($me['role'] === 'admin' && get('probe') !== '') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach (cloud_ds_probe(strtoupper(trim(get('probe'))), date('Y-m-d')) as $label => $msg) echo str_pad($label, 34) . $msg . "\n";
    exit;
}

$devices = cloud_devices();
$hasToken = cloud_token() !== '';
$oldest = date('Y-m-d', strtotime('-60 days'));

// default: from the day of the newest cloud scan (so nothing in between is missed) up to today
$newest = null;
foreach ($devices as $d) if ($d['last_scan'] && (!$newest || $d['last_scan'] < $newest)) $newest = $d['last_scan'];
$defFrom = $newest ? max(substr($newest, 0, 10), $oldest) : date('Y-m-d', strtotime('-1 day'));

$results = null;
$error = '';
$from = post('from', get('from', $defFrom));
$to = post('to', get('to', date('Y-m-d')));
$picked = isset($_POST['sn']) ? array_map('strval', (array)$_POST['sn']) : array_column($devices, 'sn');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xlsx']) && $_FILES['xlsx']['name'] !== '') {
    check_csrf();
    try {
        if (($_FILES['xlsx']['error'] ?? 1) !== 0) throw new Exception('Upload file gagal.');
        $results = ['import' => cloud_import_xlsx($_FILES['xlsx']['tmp_name'])];
        audit('cloud_import_xlsx', $_FILES['xlsx']['name'] . ': ' . $results['import']['added'] . ' scan baru');
        $devices = cloud_devices();
    } catch (Exception $e) { $error = 'Impor gagal: ' . $e->getMessage(); }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$hasToken) $error = 'API key Fingerspot.io belum diisi di config.php.';
    elseif (!valid_date($from) || !valid_date($to) || $to < $from) $error = 'Isi rentang tanggal yang benar.';
    elseif ($from < $oldest) $error = 'fingerspot.io hanya menyimpan data 60 hari terakhir (mulai ' . fmt_date($oldest) . ').';
    elseif ($to > date('Y-m-d')) $error = 'Tanggal akhir tidak boleh setelah hari ini.';
    elseif (!$picked) $error = 'Pilih minimal satu mesin.';
    else {
        $results = cloud_download($from, $to, $picked);
        $added = array_sum(array_column($results, 'added'));
        audit('cloud_download', "$from..$to: $added scan baru");
        $devices = cloud_devices();
    }
}

page_header('Download Fingerspot.io', 'cloud.php');
?>
<?php if (!$hasToken): ?>
  <div class="flash err">API key belum diisi. Tambahkan baris <code>'fingerspot_api_key' => 'API_KEY_ANDA',</code> di <code>config.php</code> di server
    (Fingerspot.io: <b>API Key untuk Fingerspot Personnel</b> — bukan API SDK), lalu muat ulang halaman ini.</div>
<?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<div class="card">
  <h2>Download data presensi dari Fingerspot.io</h2>
  <form method="post" class="stack" onsubmit="document.body.classList.add('busy')">
    <?= csrf_field() ?>
    <div class="table-wrap" style="max-height:none"><table>
      <tr><th></th><th>Mesin</th><th>SN</th><th>Cloud ID</th><th>Scan terakhir di database</th><th>Download web terakhir</th></tr>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td><input type="checkbox" name="sn[]" value="<?= h($d['sn']) ?>" <?= in_array($d['sn'], $picked, true) ? 'checked' : '' ?>></td>
          <td><b><?= h($d['device_name']) ?></b></td>
          <td><?= h($d['sn']) ?></td>
          <td><?= h($d['cloud_id']) ?></td>
          <td><?= $d['last_scan'] ? h(date('d-m-Y H:i', strtotime($d['last_scan']))) : '—' ?></td>
          <td><?= $d['last_download_web'] ? h(date('d-m-Y', strtotime($d['last_download_web']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$devices): ?><tr><td colspan="6" class="muted">Tidak ada mesin dengan Cloud ID.</td></tr><?php endif; ?>
    </table></div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <label style="flex-direction:row;align-items:center;gap:4px">Tanggal <input type="date" name="from" value="<?= h($from) ?>" min="<?= $oldest ?>" max="<?= date('Y-m-d') ?>"></label>
      <label style="flex-direction:row;align-items:center;gap:4px">s/d <input type="date" name="to" value="<?= h($to) ?>" min="<?= $oldest ?>" max="<?= date('Y-m-d') ?>"></label>
      <button class="btn primary" <?= $hasToken && $devices ? '' : 'disabled' ?>>Download</button>
      <span class="muted">Lewat API Fingerspot Personnel. Data tersedia 60 hari terakhir; scan yang sudah ada tidak ditambah dua kali.</span>
    </div>
  </form>
</div>

<div class="card">
  <h2>Impor dari file Excel Fingerspot.io</h2>
  <p class="muted">Alternatif tanpa API: di Fingerspot.io buka <b>Laporan → Absensi Perangkat</b>, pilih rentang tanggal, klik ikon Excel, lalu unggah file <code>.xlsx</code> itu di sini. Cloud ID pada file dicocokkan dengan mesin di atas.</p>
  <form method="post" enctype="multipart/form-data" class="inline" onsubmit="document.body.classList.add('busy')">
    <?= csrf_field() ?>
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button class="btn">Impor</button>
  </form>
</div>

<?php if ($results !== null): ?>
<div class="card">
  <h2>Hasil download <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?></h2>
  <div class="table-wrap" style="max-height:none"><table>
    <tr><th>Mesin</th><th class="num">Scan diterima</th><th class="num">Scan baru</th><th class="num">Sudah ada</th><th>PIN tidak dikenal</th><th>Kendala</th></tr>
    <?php foreach ($results as $sn => $r): ?>
      <tr>
        <td><b><?= h($r['name']) ?></b></td>
        <td class="num"><?= (int)$r['received'] ?></td>
        <td class="num"><b><?= (int)$r['added'] ?></b></td>
        <td class="num"><?= (int)$r['received'] - (int)$r['added'] ?></td>
        <td><?= h(implode(', ', $r['unknown_pins'])) ?></td>
        <td class="<?= $r['errors'] ? 'late' : '' ?>" style="white-space:normal"><?= h(implode('; ', array_slice($r['errors'], 0, 3))) ?><?= count($r['errors']) > 3 ? ' (+' . (count($r['errors']) - 3) . ' lagi)' : '' ?><?= !empty($r['unknown_cloud']) ? ' Cloud ID tak dikenal: ' . h(implode(', ', $r['unknown_cloud'])) : '' ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Laporan langsung memakai scan baru; tidak perlu tombol Proses terpisah.</p>
</div>
<?php endif; ?>
<?php page_footer();
