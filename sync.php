<?php
// Mesin → Sinkron dari Program: copy new scans from Fingerspot Personnel's database (port 3309).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sync.php';
require_login();

$error = '';
$result = null;
$here = $there = null;
try {
    list($here, $there) = sync_status();
} catch (Exception $e) {
    $error = 'Tidak bisa membuka database program (port ' . (isset($CONFIG['sync_port']) ? (int)$CONFIG['sync_port'] : 3309) . '): ' . $e->getMessage()
           . ' — periksa sync_user / sync_pass di config.php.';
}
// default: one day before the newest scan already here, so a partly copied day is completed
$from = post('from', $here ? date('Y-m-d', strtotime(substr($here, 0, 10) . ' -1 day')) : date('Y-m-d', strtotime('-31 days')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    check_csrf();
    if (!valid_date($from) || $from > date('Y-m-d')) $error = 'Isi tanggal yang benar.';
    else {
        $result = sync_from_program($from);
        audit('sync_program', "dari $from: {$result['att_log'][1]} scan, {$result['izin'][1]} izin baru");
        list($here, $there) = sync_status();
    }
}

page_header('Sinkron dari Program', 'sync.php');
?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<div class="card">
  <h2>Sinkron scan dari program absensi (database port <?= isset($CONFIG['sync_port']) ? (int)$CONFIG['sync_port'] : 3309 ?>)</h2>
  <table style="width:auto">
    <tr><th>Scan mesin terbaru</th><th>Di program</th><th>Di aplikasi web</th></tr>
    <tr><td></td><td><?= $there ? h(date('d-m-Y H:i:s', strtotime($there))) : '—' ?></td><td><?= $here ? h(date('d-m-Y H:i:s', strtotime($here))) : '—' ?></td></tr>
  </table>
  <form method="post" class="inline" style="margin-top:6px" onsubmit="document.body.classList.add('busy')">
    <?= csrf_field() ?>
    <label>Ambil scan mulai tanggal <input type="date" name="from" value="<?= h($from) ?>" max="<?= date('Y-m-d') ?>"></label>
    <button class="btn primary" <?= $there === null && $error ? 'disabled' : '' ?>>Sinkron</button>
  </form>
  <p class="muted">Menyalin scan baru (dan Tidak scan / izin dari program) ke aplikasi web. Data yang sudah ada tidak ditambah dua kali; tidak ada yang diubah atau dihapus di program.</p>
</div>
<?php if ($result): ?>
<div class="card">
  <h2>Hasil sinkron dari <?= h(fmt_date($from)) ?></h2>
  <table style="width:auto">
    <tr><th>Data</th><th class="num">Dibaca</th><th class="num">Baru</th></tr>
    <tr><td>Scan (att_log)</td><td class="num"><?= (int)$result['att_log'][0] ?></td><td class="num"><b><?= (int)$result['att_log'][1] ?></b></td></tr>
    <tr><td>Tidak scan / izin</td><td class="num"><?= (int)$result['izin'][0] ?></td><td class="num"><b><?= (int)$result['izin'][1] ?></b></td></tr>
  </table>
</div>
<?php endif; ?>
<?php page_footer();
