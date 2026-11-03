<?php
// Mesin → Sinkron FTM: head-office scans from Fingerspot Time Management (database `ftm`). Map each FTM PIN to an
// employee once, then copy the scans of a date range (see inc/ftm.php). Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/ftm.php';
require_login('admin');
ensure_ftm_tables();

$F = ftm_db();
$error = $F ? '' : 'Database FTM "' . ftm_db_name() . '" tidak ada di server MySQL ini (atur ftm_db di config.php).';
list($defFrom, $defTo) = current_period();
$from = post('from', get('from', $defFrom));
$to = post('to', get('to', min($defTo, date('Y-m-d'))));
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $F) {
    check_csrf();
    if (post('action') === 'map') {
        $n = 0;
        $since = isset($_POST['since']) ? (array)$_POST['since'] : [];
        foreach (isset($_POST['map']) ? (array)$_POST['map'] : [] as $pin => $id) {
            $vf = isset($since[$pin]) && valid_date($since[$pin]) ? $since[$pin] : null;
            if ((int)$id) { q('REPLACE INTO web_ftm_map (ftm_pin, pegawai_id, valid_from) VALUES (?, ?, ?)', [(string)$pin, (int)$id, $vf]); $n++; }
            else q('DELETE FROM web_ftm_map WHERE ftm_pin = ?', [(string)$pin]);
        }
        audit('ftm_map', "$n PIN dipasangkan");
        flash("$n PIN FTM dipasangkan. Sinkronkan agar scan mereka masuk.");
        redirect('ftm.php?' . http_build_query(['from' => $from, 'to' => $to]));
    }
    if (!valid_date($from) || !valid_date($to) || $to < $from) $error = 'Isi rentang tanggal yang benar.';
    else {
        list($del, $add) = ftm_sync($from, $to);
        $result = [$del, $add];
        audit('ftm_sync', "$from..$to: $add scan (sebelumnya $del)");
    }
}

$emps = $F ? ftm_employees() : [];
$mapRows = q('SELECT ftm_pin, pegawai_id, valid_from FROM web_ftm_map')->fetchAll(PDO::FETCH_UNIQUE);
$map = array_map(function ($r) { return $r['pegawai_id']; }, $mapRows);
$restarts = $F ? ftm_pin_restarts(ftm_scan_days()) : [];
$people = [];
foreach (employees(true) as $e) $people[$e['pegawai_id']] = $e['pegawai_nama'];
list($counts, $newest) = $F ? ftm_scan_counts(date('Y-m-d', strtotime('-60 days'))) : [[], null];
$copied = q("SELECT MAX(scan_date) FROM att_log WHERE sn LIKE 'FTM%'")->fetchColumn();
$taken = array_count_values(array_map('intval', $map));

settings_header('Sinkron FTM', 'ftm.php');
?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<?php if ($F): ?>
<div class="card">
  <h2>Scan kantor pusat dari Fingerspot Time Management (database <?= h(ftm_db_name()) ?>)</h2>
  <table style="width:auto">
    <tr><th>Scan mesin terbaru di FTM</th><th>Terakhir disalin ke aplikasi</th></tr>
    <tr><td><?= $newest ? h(date('d-m-Y H:i', strtotime($newest))) : '—' ?></td><td><?= $copied ? h(date('d-m-Y H:i', strtotime($copied))) : '—' ?></td></tr>
  </table>
  <?php if ($newest && $newest < date('Y-m-d', strtotime('-3 days'))): ?>
    <p class="flash err" style="margin:8px 0">Scan terbaru di FTM sudah lebih dari 3 hari. Kemungkinan program FTM di komputer masih menyimpan ke database lamanya
      (MySQL bawaan di port 3310), bukan ke server. Arahkan program FTM ke server (file user.fin: host 192.168.20.102, port 3306) atau pindahkan lagi datanya.</p>
  <?php endif; ?>
  <form method="post" class="inline" style="margin-top:6px" onsubmit="document.body.classList.add('busy')"><?= csrf_field() ?><input type="hidden" name="action" value="sync">
    <label>Tanggal <input type="date" name="from" value="<?= h($from) ?>"></label> <label>s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <button class="btn primary"<?= $map ? '' : ' disabled' ?>>Sinkron</button></form>
  <p class="muted">Scan FTM rentang ini yang sudah pernah disalin diganti dengan data FTM terbaru, jadi koreksi dan hapusan di FTM ikut. Hanya PIN yang sudah dipasangkan di bawah yang disalin, dengan PIN pegawai di aplikasi ini.</p>
  <?php if ($result): ?><p><b>Selesai:</b> <?= (int)$result[1] ?> scan disalin untuk <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?><?= $result[0] ? ' (menggantikan ' . (int)$result[0] . ' salinan sebelumnya)' : '' ?>.</p><?php endif; ?>
</div>
<div class="card">
  <h2>Pasangan PIN FTM → pegawai</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="map"><input type="hidden" name="from" value="<?= h($from) ?>"><input type="hidden" name="to" value="<?= h($to) ?>">
  <table>
    <tr><th>PIN FTM</th><th>Nama di FTM</th><th class="num">Scan 60 hari</th><th>Pegawai di aplikasi</th><th title="Scan PIN ini sebelum tanggal ini milik orang lain dan tidak disalin">Berlaku sejak</th><th></th></tr>
    <?php foreach ($emps as $e):
        $saved = isset($map[$e['pin']]) ? (int)$map[$e['pin']] : 0;
        $sug = $saved ? 0 : ftm_suggest($e['name'], $people);
        $sel = $saved ?: $sug; ?>
      <tr<?= $e['active'] ? '' : ' class="muted"' ?>><td><?= h($e['pin']) ?></td><td><?= h($e['name']) ?><?= $e['active'] ? '' : ' (tidak aktif)' ?></td>
        <td class="num"><?= isset($counts[$e['pin']]) ? (int)$counts[$e['pin']] : '' ?></td>
        <?php $vf = isset($mapRows[$e['pin']]) ? $mapRows[$e['pin']]['valid_from'] : null; $re = isset($restarts[$e['pin']]) ? $restarts[$e['pin']] : null; ?>
        <td><select name="map[<?= h($e['pin']) ?>]"><option value="0">— tidak disalin —</option><?php foreach ($people as $id => $n): ?><option value="<?= (int)$id ?>"<?= (int)$id === $sel ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></td>
        <td><input type="date" name="since[<?= h($e['pin']) ?>]" value="<?= h($vf ?: '') ?>">
          <?php if ($re && $re !== $vf): ?><br><small class="muted">PIN kosong lama sebelum <a href="#" onclick="this.closest('td').querySelector('input').value='<?= h($re) ?>';return false"><?= h(date('d-m-Y', strtotime($re))) ?></a> — mungkin dipakai orang lain sebelumnya</small><?php endif; ?></td>
        <td class="muted"><?= $saved ? (isset($taken[$saved]) && $taken[$saved] > 1 ? '<b style="color:#c00">dipakai ' . $taken[$saved] . ' PIN</b>' : 'tersimpan') : ($sug ? '<b>saran</b>, periksa lalu simpan' : '') ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:8px"><button class="btn primary">Simpan pasangan</button> <span class="muted">Saran dari kemiripan nama depan; pastikan benar sebelum menyimpan. Scan FTM hanya berisi PIN, tanpa nama: bila sebuah PIN pernah dipakai orang lain, isi "Berlaku sejak" dengan tanggal pegawai ini mulai memakainya (klik tanggal saran bila PIN pernah lama kosong), lalu sinkron ulang. Pegawai yang absen di mesin Fingerspot dan di FTM mendapat scan dari keduanya.</span></p>
  </form>
</div>
<?php endif; ?>
<?php settings_footer();
